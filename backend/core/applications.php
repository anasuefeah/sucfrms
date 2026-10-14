<?php
// Application, cycle and score helpers.

// -- Application helpers ---------------------------------------
function getActiveCycle($pdo): array|false {
    return $pdo->query("SELECT * FROM cycles WHERE status = 'open' ORDER BY created_at DESC LIMIT 1")->fetch();
}


function getOrCreateApplication($pdo, int $user_id, int $cycle_id): array {
    $stmt = $pdo->prepare("SELECT * FROM applications WHERE user_id = ? AND cycle_id = ? LIMIT 1");
    $stmt->execute([$user_id, $cycle_id]);
    $app = $stmt->fetch();
    if (!$app) {
        $pdo->prepare("INSERT INTO applications (user_id, cycle_id, status) VALUES (?, ?, 'draft')")
            ->execute([$user_id, $cycle_id]);
        $stmt->execute([$user_id, $cycle_id]);
        $app = $stmt->fetch();
    }
    return $app;
}

function recalcApplicationScore($pdo, int $application_id): float {
    // -- Route through the JC01 s.2026 Orchestrator when available ---
    // The orchestrator produces the authoritative result; the legacy path
    // below is kept as a fallback for any call before the scoring module loads.
    $scoring_dir = __DIR__ . '/../services/scoring/orchestrator.php';
    if (file_exists($scoring_dir)) {
        try {
            if (!class_exists('\Scoring\Orchestrator')) {
                require_once $scoring_dir;
            }
            $result = \Scoring\Orchestrator::run($pdo, $application_id);
            return (float)($result['weighted_score'] ?? 0);
        } catch (\Throwable $e) {
            // Fall through to legacy computation on any failure
            error_log('SUCFRMS Orchestrator error: ' . $e->getMessage());
        }
    }

    // -- Legacy fallback ----------------------------------------------
    $info = $pdo->prepare("SELECT u.rank FROM applications a JOIN users u ON a.user_id=u.user_id WHERE a.application_id=?");
    $info->execute([$application_id]);
    $rank = $info->fetchColumn() ?? '';

    $stmt = $pdo->prepare("SELECT kra_category, computed_points, remarks FROM kra_submissions WHERE application_id=?");
    $stmt->execute([$application_id]);
    $raw = [];
    $has_national_award = false;
    foreach ($stmt->fetchAll() as $r) {
        $raw[$r['kra_category']] = ($raw[$r['kra_category']] ?? 0) + kraEffectiveComputedPoints($r);
        if ($r['kra_category'] === 'Professional Development') {
            $parts = array_map('trim', explode('|||', $r['remarks'] ?? ''));
            if (($parts[0] ?? '') === 'C-award' && (float)($parts[2] ?? -1) === 0.0) {
                $has_national_award = true;
            }
        }
    }

    $result         = computeWeightedScore($raw, $rank, $has_national_award);
    $potential      = computePotentialRank($raw, $rank, $has_national_award);
    $potential_rank = $potential['potential_rank'];

    try {
        $pdo->prepare("UPDATE applications SET total_score=?, weighted_score=?, sub_rank_increment=?, potential_rank=? WHERE application_id=?")
            ->execute([$result['raw_total'], $result['weighted_score'], $result['sub_rank_increment'], $potential_rank, $application_id]);
    } catch (\Exception $e) {
        $pdo->prepare("UPDATE applications SET total_score=?, weighted_score=?, sub_rank_increment=? WHERE application_id=?")
            ->execute([$result['raw_total'], $result['weighted_score'], $result['sub_rank_increment'], $application_id]);
    }

    return $result['weighted_score'];
}

function kraEffectiveComputedPoints(array $submission): float {
    $points = (float)($submission['computed_points'] ?? 0);
    $parts = array_map('trim', explode('|||', (string)($submission['remarks'] ?? '')));
    if (($parts[0] ?? '') === 'CUSTOM') {
        return max($points, max(0.0, (float)($parts[4] ?? 0)));
    }
    return $points;
}

function recalcApplicationsAfterCriteriaChange(PDO $pdo, ?int $cycle_id = null, ?string $position_rank = null): int {
    try {
        $where = ["a.status <> 'draft'"];
        $params = [];

        if ($cycle_id) {
            $where[] = 'a.cycle_id = ?';
            $params[] = $cycle_id;
        }
        if ($position_rank !== null && $position_rank !== '') {
            $where[] = "COALESCE(NULLIF(a.position_title, ''), u.rank) = ?";
            $params[] = $position_rank;
        }

        $stmt = $pdo->prepare("
            SELECT a.application_id
            FROM applications a
            JOIN users u ON u.user_id = a.user_id
            WHERE " . implode(' AND ', $where)
        );
        $stmt->execute($params);

        $count = 0;
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $app_id) {
            recalcApplicationScore($pdo, (int)$app_id);
            $count++;
        }
        return $count;
    } catch (\Throwable $e) {
        error_log('SUCFRMS criteria recalculation error: ' . $e->getMessage());
        return 0;
    }
}

/**
 * Authoritative score summary for application displays.
 *
 * UI/report layers should use this for KRA subtotals, grand totals,
 * weighted score, and sub-rank values instead of re-summing/capping rows.
 */
function getApplicationScoreSummary($pdo, int $application_id, bool $refresh = true): array {
    static $cache = [];
    $cache_key = $application_id . ':' . ($refresh ? 'fresh' : 'stored');
    if (isset($cache[$cache_key])) return $cache[$cache_key];

    $result = null;
    $scoring_dir = __DIR__ . '/../services/scoring/orchestrator.php';
    if ($refresh && file_exists($scoring_dir)) {
        try {
            if (!class_exists('\Scoring\Orchestrator')) {
                require_once $scoring_dir;
            }
            $result = \Scoring\Orchestrator::run($pdo, $application_id);
            if (!empty($result['error'])) $result = null;
        } catch (\Throwable $e) {
            error_log('SUCFRMS score summary orchestrator error: ' . $e->getMessage());
            $result = null;
        }
    }

    $app_stmt = $pdo->prepare("
        SELECT a.*, u.rank AS faculty_rank
        FROM applications a
        JOIN users u ON a.user_id = u.user_id
        WHERE a.application_id = ?
        LIMIT 1
    ");
    $app_stmt->execute([$application_id]);
    $app = $app_stmt->fetch(PDO::FETCH_ASSOC) ?: [];

    $verified_stmt = $pdo->prepare("
        SELECT kra_category, computed_points, remarks, verified
        FROM kra_submissions
        WHERE application_id = ?
    ");
    $verified_stmt->execute([$application_id]);

    $kra_map = [
        'Instruction'              => ['pts' => 0.0, 'entry_total' => 0.0, 'verified' => 0],
        'Research'                 => ['pts' => 0.0, 'entry_total' => 0.0, 'verified' => 0],
        'Extension'                => ['pts' => 0.0, 'entry_total' => 0.0, 'verified' => 0],
        'Professional Development' => ['pts' => 0.0, 'entry_total' => 0.0, 'verified' => 0],
    ];
    foreach ($verified_stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        if (!isset($kra_map[$row['kra_category']])) continue;
        $kra_map[$row['kra_category']]['entry_total'] += kraEffectiveComputedPoints($row);
        $kra_map[$row['kra_category']]['verified'] = max((int)$kra_map[$row['kra_category']]['verified'], (int)$row['verified']);
    }

    if ($result) {
        $kra_results = $result['kra_results'];
        $kra_map['Instruction']['pts'] = (float)$kra_results['kra1']['subtotal'];
        $kra_map['Research']['pts'] = (float)$kra_results['kra2']['subtotal'];
        $kra_map['Extension']['pts'] = (float)$kra_results['kra3']['subtotal'];
        $kra_map['Professional Development']['pts'] = (float)$kra_results['kra4']['subtotal'];
        $weights = $result['iss']['weight_row_pass2']
            ?? \Scoring\Orchestrator::getKraWeights($result['iss']['initial_reclassified_rank'] ?? ($app['faculty_rank'] ?? ''));
        $summary = [
            'kra1' => $kra_map['Instruction']['pts'],
            'kra2' => $kra_map['Research']['pts'],
            'kra3' => $kra_map['Extension']['pts'],
            'kra4' => $kra_map['Professional Development']['pts'],
            'raw_total' => (float)$result['grand_total'],
            'grand_total' => (float)$result['grand_total'],
            'weights' => $weights,
            'weighted_score' => (float)$result['weighted_score'],
            'sub_rank_increment' => (int)$result['sub_rank_increment'],
            'award_bonus' => (int)($result['auto_subrank']['bonus_increment'] ?? 0),
            'potential_rank' => $result['target_rank'] ?? ($app['potential_rank'] ?? ''),
            'kra_map' => $kra_map,
            'kra_results' => $kra_results,
            'pending_documentation' => $result['pending_documentation'] ?? [],
            'config_incomplete' => $result['config_incomplete'] ?? [],
            'source' => 'orchestrator',
        ];
    } else {
        $weights = getKraWeights($app['faculty_rank'] ?? '');
        foreach ($kra_map as &$k) $k['pts'] = min(100, $k['entry_total']);
        unset($k);
        $weighted = round(
            ($kra_map['Instruction']['pts'] * $weights['Instruction']) +
            ($kra_map['Research']['pts'] * $weights['Research']) +
            ($kra_map['Extension']['pts'] * $weights['Extension']) +
            ($kra_map['Professional Development']['pts'] * $weights['Professional Development']),
            2
        );
        $summary = [
            'kra1' => $kra_map['Instruction']['pts'],
            'kra2' => $kra_map['Research']['pts'],
            'kra3' => $kra_map['Extension']['pts'],
            'kra4' => $kra_map['Professional Development']['pts'],
            'raw_total' => array_sum(array_column($kra_map, 'pts')),
            'grand_total' => array_sum(array_column($kra_map, 'pts')),
            'weights' => $weights,
            'weighted_score' => $weighted,
            'sub_rank_increment' => getSubRankIncrement($weighted),
            'award_bonus' => 0,
            'potential_rank' => $app['potential_rank'] ?? '',
            'kra_map' => $kra_map,
            'kra_results' => [],
            'pending_documentation' => [],
            'config_incomplete' => [],
            'source' => 'legacy_fallback',
        ];
    }

    $cache[$cache_key] = $summary;
    return $summary;
}

/**
 * Returns KRA weights (as decimals) based on faculty rank.
 */
/**
 * Returns KRA weights (as decimals) based on faculty rank.
 * Delegates to Scoring\Orchestrator::getKraWeights() — single source of truth.
 */
function getKraWeights(string $rank): array {
    if (class_exists('\Scoring\Orchestrator')) {
        return \Scoring\Orchestrator::getKraWeights($rank);
    }
    // Fallback (Orchestrator not loaded) — mirrors the same table
    if (preg_match('/^instructor/i', $rank))
        return ['Instruction'=>0.60,'Research'=>0.10,'Extension'=>0.20,'Professional Development'=>0.10];
    if (preg_match('/^assistant professor/i', $rank))
        return ['Instruction'=>0.50,'Research'=>0.20,'Extension'=>0.20,'Professional Development'=>0.10];
    if (preg_match('/^associate professor/i', $rank))
        return ['Instruction'=>0.40,'Research'=>0.30,'Extension'=>0.20,'Professional Development'=>0.10];
    if (preg_match('/^professor (i|ii|iii|iv|v|vi)$/i', $rank))
        return ['Instruction'=>0.30,'Research'=>0.40,'Extension'=>0.20,'Professional Development'=>0.10];
    if (preg_match('/^(college|university) professor/i', $rank))
        return ['Instruction'=>0.20,'Research'=>0.50,'Extension'=>0.20,'Professional Development'=>0.10];
    return ['Instruction'=>0.60,'Research'=>0.10,'Extension'=>0.20,'Professional Development'=>0.10];
}

/**
 * Compute weighted final score and sub-rank increment per JC01 s.2026.
 * KRA caps: Instruction=100, Research=100, Extension=100, PD=100 (each capped at 100).
 * Grand total max = 400 (no additional global cap per JC01).
 */
function computeWeightedScore(array $raw, string $rank, bool $has_national_award = false): array {
    $weights = getKraWeights($rank);

    // JC01 s.2026: each KRA capped at 100 (Extension was incorrectly 120 under old JC3 rules)
    $kra1 = min(100, (float)($raw['Instruction']              ?? 0));
    $kra2 = min(100, (float)($raw['Research']                 ?? 0));
    $kra3 = min(100, (float)($raw['Extension']                ?? 0));
    $kra4 = min(100, (float)($raw['Professional Development'] ?? 0));

    $raw_total = $kra1 + $kra2 + $kra3 + $kra4;  // max 400

    $weighted = round(
        ($kra1 * $weights['Instruction']) +
        ($kra2 * $weights['Research']) +
        ($kra3 * $weights['Extension']) +
        ($kra4 * $weights['Professional Development']),
        2
    );

    $sub_rank = getSubRankIncrement($weighted);

    // Award bonus: applied AFTER score bracket, only if score >= 41
    $award_bonus = 0;
    if ($has_national_award && $sub_rank > 0) {
        $award_bonus = 1;
        $sub_rank   += 1;
    }

    return [
        'kra1'               => $kra1,
        'kra2'               => $kra2,
        'kra3'               => $kra3,
        'kra4'               => $kra4,
        'raw_total'          => $raw_total,
        'weights'            => $weights,
        'weighted_score'     => $weighted,
        'sub_rank_increment' => $sub_rank,
        'award_bonus'        => $award_bonus,
    ];
}

/**
 * Determine sub-rank increment from weighted score per Step 4.
 */
function getSubRankIncrement(float $score): int {
    if ($score >= 91) return 6;
    if ($score >= 81) return 5;
    if ($score >= 71) return 4;
    if ($score >= 61) return 3;
    if ($score >= 51) return 2;
    if ($score >= 41) return 1;
    return 0;
}

function getCriteria($pdo, string $key, ?int $cycle_id = null, ?string $position_rank = null): array|false {
    // 1. Most specific: cycle + position
    if ($cycle_id && $position_rank) {
        $stmt = $pdo->prepare("SELECT * FROM scoring_criteria WHERE criterion_key=? AND cycle_id=? AND position_rank=? AND is_active=1 LIMIT 1");
        $stmt->execute([$key, $cycle_id, $position_rank]);
        $row = $stmt->fetch();
        if ($row) return $row;
    }
    // 2. Cycle-wide (no position)
    if ($cycle_id) {
        $stmt = $pdo->prepare("SELECT * FROM scoring_criteria WHERE criterion_key=? AND cycle_id=? AND position_rank IS NULL AND is_active=1 LIMIT 1");
        $stmt->execute([$key, $cycle_id]);
        $row = $stmt->fetch();
        if ($row) return $row;
    }
    // 3. Global default
    $stmt = $pdo->prepare("SELECT * FROM scoring_criteria WHERE criterion_key=? AND cycle_id IS NULL AND position_rank IS NULL AND is_active=1 LIMIT 1");
    $stmt->execute([$key]);
    return $stmt->fetch();
}
