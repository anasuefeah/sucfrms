<?php
/**
 * Core helper functions for SUCFRMS
 */

// -- Authentication --------------------------------------------
function isLoggedIn(): bool { return isset($_SESSION['user_id']); }
function isAdmin():    bool { return ($_SESSION['role'] ?? '') === 'admin'; }
function isChecker():  bool { return in_array($_SESSION['role'] ?? '', ['checker', 'checker_faculty']); }
function isFaculty():  bool { return in_array($_SESSION['role'] ?? '', ['faculty', 'checker_faculty']); }
function isTalisayChecker(): bool { return ($_SESSION['role'] ?? '') === 'talisay_checker'; }

function requireLogin(): void {
    if (!isLoggedIn()) { header('Location: pages/login.php'); exit; }
}
function requireAdmin(): void {
    requireLogin();
    if (!isAdmin()) { header('Location: index.php'); exit; }
}

/**
 * Format a user's display name as "First M. Last".
 * Pass a DB row array with first_name/middle_name/last_name keys.
 * Falls back to full_name if parts are empty.
 */
function formatDisplayName(array $row, string $fallback_key = 'full_name'): string {
    $first  = trim($row['first_name']  ?? '');
    $middle = trim($row['middle_name'] ?? '');
    $last   = trim($row['last_name']   ?? '');
    if ($first === '' && $last === '') {
        return $row[$fallback_key] ?? '';
    }
    $mi = $middle !== '' ? ' ' . strtoupper(substr($middle, 0, 1)) . '.' : '';
    return trim($first . $mi . ' ' . $last);
}

// -- Audit logging ---------------------------------------------
function logAudit($pdo, $user_id, string $action, string $details = ''): void {
    $pdo->prepare("INSERT INTO audit_logs (user_id, role_at_time, action_performed, details) VALUES (?,?,?,?)")
        ->execute([$user_id, $_SESSION['role'] ?? 'unknown', $action, $details]);
}

// -- Notifications ---------------------------------------------
function createNotif($pdo, int $user_id, string $type, string $message, int $application_id = 0): void {
    try {
        $pdo->prepare("INSERT INTO notifications (user_id, type, message, application_id) VALUES (?,?,?,?)")
            ->execute([$user_id, $type, $message, $application_id ?: null]);
    } catch (\Exception $e) { /* table may not exist yet — silent fail */ }
}

function getUnreadNotifCount($pdo, int $user_id): int {
    try {
        $s = $pdo->prepare("SELECT COUNT(*) FROM notifications WHERE user_id=? AND is_read=0");
        $s->execute([$user_id]);
        return (int)$s->fetchColumn();
    } catch (\Exception $e) { return 0; }
}

// -- Flash messages --------------------------------------------
function flashMessage(string $type, string $msg): void {
    $_SESSION['flash'] = ['type' => $type, 'msg' => $msg];
}
function showFlash(): void {
    if (empty($_SESSION['flash'])) return;
    $f    = $_SESSION['flash'];
    unset($_SESSION['flash']);
    $icon = match($f['type']) {
        'success' => 'bi-check-circle-fill',
        'warning' => 'bi-exclamation-triangle-fill',
        'danger'  => 'bi-x-circle-fill',
        default   => 'bi-info-circle-fill',
    };
    $color = match($f['type']) {
        'success' => '#16a34a',
        'warning' => '#d97706',
        'danger'  => '#dc2626',
        default   => '#1e4d8c',
    };
    $msg_html = $f['msg'];
    // Toast: fixed bottom-right, slides in, auto-dismisses after 3.5 s, never pushes content down
    echo "<div id='sucfrms-toast' style='position:fixed;bottom:1.5rem;right:1.5rem;z-index:999999;"
       . "min-width:280px;max-width:400px;background:#fff;border-radius:12px;"
       . "box-shadow:0 8px 32px rgba(0,0,0,0.18);display:flex;align-items:flex-start;gap:0.75rem;"
       . "padding:1rem 1.1rem;border-left:4px solid {$color};animation:sucfrmsToastIn 0.3s ease;'>"
       . "<i class='bi {$icon}' style='color:{$color};font-size:1.15rem;flex-shrink:0;margin-top:2px;'></i>"
       . "<div style='flex:1;font-size:0.85rem;color:#1e293b;line-height:1.5;'>{$msg_html}</div>"
       . "<button onclick=\"document.getElementById('sucfrms-toast').remove()\" "
       .         "style='background:none;border:none;color:#94a3b8;cursor:pointer;"
       .                "font-size:1.2rem;line-height:1;padding:0 0 2px;flex-shrink:0;'>&times;</button>"
       . "</div>"
       . "<style>"
       . "@keyframes sucfrmsToastIn{from{opacity:0;transform:translateY(1rem)}to{opacity:1;transform:translateY(0)}}"
       . "@keyframes sucfrmsToastOut{from{opacity:1;transform:translateY(0)}to{opacity:0;transform:translateY(1rem)}}"
       . "</style>"
       . "<script>(function(){"
       .   "var t=document.getElementById('sucfrms-toast');"
       .   "if(!t)return;"
       .   "setTimeout(function(){"
       .     "t.style.animation='sucfrmsToastOut 0.4s ease forwards';"
       .     "setTimeout(function(){if(t&&t.parentNode)t.parentNode.removeChild(t);},400);"
       .   "},3500);"
       . "})();</script>";
}

// -- Output sanitization ---------------------------------------
function sanitize(string $str): string {
    return htmlspecialchars(strip_tags(trim($str)), ENT_QUOTES, 'UTF-8');
}

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
    // ── Route through the JC01 s.2026 Orchestrator when available ───
    // The orchestrator produces the authoritative result; the legacy path
    // below is kept as a fallback for any call before the scoring module loads.
    $scoring_dir = __DIR__ . '/scoring/orchestrator.php';
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

    // ── Legacy fallback ──────────────────────────────────────────────
    $info = $pdo->prepare("SELECT u.rank FROM applications a JOIN users u ON a.user_id=u.user_id WHERE a.application_id=?");
    $info->execute([$application_id]);
    $rank = $info->fetchColumn() ?? '';

    $stmt = $pdo->prepare("SELECT kra_category, computed_points, remarks FROM kra_submissions WHERE application_id=?");
    $stmt->execute([$application_id]);
    $raw = [];
    $has_national_award = false;
    foreach ($stmt->fetchAll() as $r) {
        $raw[$r['kra_category']] = ($raw[$r['kra_category']] ?? 0) + (float)$r['computed_points'];
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

/**
 * Returns KRA weights (as decimals) based on faculty rank.
 */
function getKraWeights(string $rank): array {
    $rank = strtolower(trim($rank));
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
    // Default: Instructor weights
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

// -- UI helpers ------------------------------------------------

/**
 * Parse the |||â€‘delimited remarks string into a human-readable HTML snippet.
 * Format per KRA category:
 *   Instruction:              SET%  ||| SEF%  ||| notes
 *   Research:                 Type  ||| Title ||| Contribution%
 *   Extension:                Activity ||| Income ||| MOA count ||| Outreach count
 *   Professional Development: Credential ||| Degree value
 */
function formatKraRemarks(string $category, string $remarks): string {
    $p = array_map('trim', explode('|||', $remarks));

    switch ($category) {
        case 'Instruction':
            $critType = $p[0] ?? '';
            $d1       = $p[1] ?? '';
            $d2       = $p[2] ?? '';
            $notes    = $p[3] ?? '';
            if ($critType === 'A-set-sef') {
                $parts = [];
                if ($d1 !== '') $parts[] = '<span class="text-muted" style="font-size:0.75rem;">SET</span> <strong>' . htmlspecialchars($d1, ENT_QUOTES) . '%</strong>';
                if ($d2 !== '') $parts[] = '<span class="text-muted" style="font-size:0.75rem;">SEF</span> <strong>' . htmlspecialchars($d2, ENT_QUOTES) . '%</strong>';
                if ($notes !== '') $parts[] = '<span class="text-muted" style="font-size:0.75rem;">Notes:</span> ' . htmlspecialchars($notes, ENT_QUOTES);
                return implode(' &nbsp;|&nbsp; ', $parts) ?: '&mdash;';
            } elseif ($critType === 'B-material') {
                $out = '<div><span class="badge bg-info" style="font-size:0.7rem;">Crit. B</span> ' . htmlspecialchars($d1, ENT_QUOTES) . '</div>';
                if ($d2 !== '' && $d2 !== '100') $out .= '<div class="text-muted" style="font-size:0.78rem;">Contribution: ' . htmlspecialchars($d2, ENT_QUOTES) . '%</div>';
                return $out;
            } elseif ($critType === 'C-thesis') {
                return '<div><span class="badge bg-warning text-dark" style="font-size:0.7rem;">Crit. C</span> ' . htmlspecialchars($d1, ENT_QUOTES) . '</div>';
            } elseif ($critType === 'C-mentor') {
                $competition = $d1 ?: 'Mentorship';
                $level       = $d2 ? htmlspecialchars($d2, ENT_QUOTES) : '';
                $placement   = $notes ? htmlspecialchars($notes, ENT_QUOTES) : '';
                $out = '<div><span class="badge bg-secondary" style="font-size:0.7rem;">Crit. C – Mentor</span> '
                     . htmlspecialchars($competition, ENT_QUOTES) . '</div>';
                $meta = array_filter([$level, $placement]);
                if ($meta) $out .= '<div class="text-muted" style="font-size:0.76rem;">' . implode(' &middot; ', $meta) . '</div>';
                $out .= '<div style="font-size:0.72rem;color:#f59e0b;font-weight:600;"><i class="bi bi-exclamation-triangle me-1"></i>PENDING — CONFIG_MENTORSHIP_POINTS not confirmed (JC01 p.78 source gap)</div>';
                return $out;
            } else {
                // Legacy format: SET%|||SEF%|||notes
                $set   = $p[0] ?? '';
                $sef   = $p[1] ?? '';
                $notes = $p[2] ?? '';
                $parts = [];
                if ($set  !== '') $parts[] = '<span class="text-muted" style="font-size:0.75rem;">SET</span> <strong>' . htmlspecialchars($set, ENT_QUOTES) . '%</strong>';
                if ($sef  !== '') $parts[] = '<span class="text-muted" style="font-size:0.75rem;">SEF</span> <strong>' . htmlspecialchars($sef, ENT_QUOTES) . '%</strong>';
                if ($notes !== '') $parts[] = '<span class="text-muted" style="font-size:0.75rem;">Notes:</span> ' . htmlspecialchars($notes, ENT_QUOTES);
                return implode(' &nbsp;|&nbsp; ', $parts) ?: '&mdash;';
            }

        case 'Research':
            $type    = $p[0] ?? '';
            $title   = $p[1] ?? '';
            $contrib = $p[2] ?? '';
            $out = '';
            if ($type  !== '') $out .= '<div><span class="badge bg-primary" style="font-size:0.72rem;">' . htmlspecialchars($type, ENT_QUOTES) . '</span></div>';
            if ($title !== '') $out .= '<div class="text-muted" style="font-size:0.82rem;">' . htmlspecialchars($title, ENT_QUOTES) . '</div>';
            if ($contrib !== '') $out .= '<div style="font-size:0.78rem;"><span class="text-muted">Contribution:</span> <strong>' . htmlspecialchars($contrib, ENT_QUOTES) . '%</strong></div>';
            return $out ?: '&mdash;';

        case 'Extension':
            $activity = $p[0] ?? '';
            $income   = $p[1] ?? '';
            $moa      = $p[2] ?? '';
            $outreach = $p[3] ?? '';
            $out = '';
            if ($activity !== '') $out .= '<div><strong>' . htmlspecialchars($activity, ENT_QUOTES) . '</strong></div>';
            $meta = [];
            if ($income   !== '' && $income   !== '0') $meta[] = '<span class="text-muted" style="font-size:0.78rem;">Income: &#8369;' . number_format((float)$income) . '</span>';
            if ($moa      !== '' && $moa      !== '0') $meta[] = '<span class="text-muted" style="font-size:0.78rem;">MOA: ' . htmlspecialchars($moa, ENT_QUOTES) . '</span>';
            if ($outreach !== '' && $outreach !== '0') $meta[] = '<span class="text-muted" style="font-size:0.78rem;">Outreach: ' . htmlspecialchars($outreach, ENT_QUOTES) . '</span>';
            if ($meta) $out .= '<div>' . implode(' &nbsp;&middot;&nbsp; ', $meta) . '</div>';
            return $out ?: '&mdash;';

        case 'Professional Development':
            $critType = $p[0] ?? '';
            $desc     = $p[1] ?? '';
            $subVal   = $p[2] ?? '';

            // New format: criterion_type|||description|||sub_value
            $critLabels = [
                'A-org'      => ['Crit. A', 'secondary', 'Professional Org'],
                'B-training' => ['Crit. B', 'primary',   'Training/Conference'],
                'B-paper'    => ['Crit. B', 'primary',   'Paper Presentation'],
                'B-degree'   => ['Crit. B', 'primary',   'Educational Qualification'],
                'C-award'    => ['Crit. C', 'success',   'Award/Recognition'],
            ];
            if (isset($critLabels[$critType])) {
                [$badge, $color, $label] = $critLabels[$critType];
                $out = '<div><span class="badge bg-' . $color . '" style="font-size:0.7rem;">' . $badge . '</span> ';
                $out .= '<span style="font-size:0.78rem;">' . htmlspecialchars($label, ENT_QUOTES) . '</span></div>';
                if ($desc !== '') $out .= '<div class="text-muted" style="font-size:0.82rem;">' . htmlspecialchars($desc, ENT_QUOTES) . '</div>';
                return $out;
            }
            // Legacy format: credential|||degree_value
            $cred   = $p[0] ?? '';
            $degree = $p[1] ?? '';
            $out = '';
            if ($cred !== '') $out .= '<div><strong>' . htmlspecialchars($cred, ENT_QUOTES) . '</strong></div>';
            if ($degree !== '' && $degree !== '0') {
                $degLabel = match($degree) {
                    '40' => 'Doctorate',
                    '20' => "Master's Degree",
                    '10' => 'Post-Doctoral',
                    default => ''
                };
                if ($degLabel) $out .= '<div><span class="badge bg-info" style="font-size:0.72rem;">' . $degLabel . '</span></div>';
            }
            return $out ?: '&mdash;';

        default:
            // Fallback: just show first segment
            return htmlspecialchars($p[0] ?? $remarks, ENT_QUOTES) ?: '&mdash;';
    }
}

/**
 * Renders a small ? help button with a hover popover.
 * Usage: <?= helpBtn('Title', 'Explanation text here') ?>
 */
function helpBtn(string $title, string $body): string {
    $t = htmlspecialchars($title, ENT_QUOTES);
    $b = htmlspecialchars($body, ENT_QUOTES);
    return '<span style="display:inline-block;vertical-align:middle;margin-left:5px;">'
         . '<button type="button" class="help-btn" data-title="' . $t . '" data-body="' . $b . '" tabindex="0" aria-label="Help: ' . $t . '">?</button>'
         . '</span>';
}

function statusBadge(string $status): string {
    // Color scheme: green for positive outcomes, red for negative, navy/gray for neutral/in-progress
    $map = [
        'draft'          => ['#64748b', '#f1f5f9', 'Draft'],
        'submitted'      => ['#1a3a6b', '#e8eef7', 'Submitted'],
        'under_review'   => ['#1a3a6b', '#e8eef7', 'Under Review'],
        'talisay_review' => ['#1a3a6b', '#e8eef7', 'Talisay Review'],
        'approved'       => ['#16a34a', '#f0fdf4', 'Approved'],
        'reclassified'   => ['#16a34a', '#f0fdf4', 'Reclassified'],
        'rejected'       => ['#dc2626', '#fef2f2', 'Returned'],
        'needs_revision' => ['#334155', '#f8fafc', 'Needs Revision'],
        'admin_rejected' => ['#dc2626', '#fef2f2', 'Rejected'],
        'edit_requested' => ['#334155', '#f8fafc', 'Edit Requested'],
    ];
    [$tc, $bg, $label] = $map[$status] ?? ['#64748b', '#f1f5f9', ucfirst(str_replace('_', ' ', $status))];
    return "<span style=\"display:inline-block;padding:2px 8px;font-size:0.68rem;font-weight:700;"
         . "color:{$tc};background:{$bg};border:1px solid {$tc}33;letter-spacing:0.03em;\">{$label}</span>";
}

function facultyRanks(): array {
    return [
        'Instructor I', 'Instructor II', 'Instructor III',
        'Assistant Professor I', 'Assistant Professor II',
        'Assistant Professor III', 'Assistant Professor IV',
        'Associate Professor I', 'Associate Professor II',
        'Associate Professor III', 'Associate Professor IV', 'Associate Professor V',
        'Professor I', 'Professor II', 'Professor III',
        'Professor IV', 'Professor V', 'Professor VI',
        'University Professor',
    ];
}

/**
 * Compute the potential rank/sub-rank per DBM-CHED JC No. 3, s. 2022.
 *
 * Steps:
 *  1. Determine sub-rank increments from weighted score.
 *  2. Count sub-ranks forward from current rank.
 *  3. If increment crosses into a new rank category, re-compute using
 *     that category's weights. If faculty qualifies (score &ge; 41 with
 *     new weights), award the new rank; otherwise award the highest
 *     sub-rank of the current category.
 *  4. Flag Professor ranks for EAC; College/University Professor for CC.
 *
 * Returns array:
 *   potential_rank  string   &mdash; e.g. "Associate Professor III"
 *   flags           string[] &mdash; e.g. ['EAC accreditation required']
 *   crossed_category bool    &mdash; true if a rank-category boundary was crossed
 *   recomputed_score float|null &mdash; weighted score under new-category weights
 */
function computePotentialRank(array $raw_kra, string $current_rank, bool $has_national_award = false): array {
    $all_ranks   = facultyRanks();
    $current_idx = array_search($current_rank, $all_ranks);

    // Unknown rank &mdash; cannot compute
    if ($current_idx === false) {
        return [
            'potential_rank'    => '—',
            'flags'             => ['Current rank not recognised'],
            'crossed_category'  => false,
            'recomputed_score'  => null,
        ];
    }

    // Step 1 &mdash; weighted score under current rank weights
    $result    = computeWeightedScore($raw_kra, $current_rank, $has_national_award);
    $increment = $result['sub_rank_increment'];  // already includes award bonus if applicable

    // No reclassification
    if ($increment === 0) {
        return [
            'potential_rank'    => $current_rank,
            'flags'             => ['Score below 41 — no reclassification'],
            'crossed_category'  => false,
            'recomputed_score'  => null,
        ];
    }

    // Step 2 &mdash; count sub-ranks forward (capped at end of rank list)
    $target_idx  = min($current_idx + $increment, count($all_ranks) - 1);
    $target_rank = $all_ranks[$target_idx];

    // Step 3 &mdash; detect category crossing
    $current_cat = getRankCategory($current_rank);
    $target_cat  = getRankCategory($target_rank);
    $crossed     = ($current_cat !== $target_cat);

    $recomputed_score = null;
    $flags = [];

    if ($crossed) {
        // Re-compute using the target category's weights (award bonus carries over)
        $recomputed       = computeWeightedScore($raw_kra, $target_rank, $has_national_award);
        $recomputed_score = $recomputed['weighted_score'];
        $new_increment    = $recomputed['sub_rank_increment'];

        if ($new_increment === 0) {
            // Does not qualify under new-category weights &mdash; cap at highest sub-rank of current category
            $highest_in_cat = getHighestRankInCategory($current_cat, $all_ranks);
            $target_rank    = $highest_in_cat;
            $flags[]        = "Score insufficient under {$target_cat} weights — awarded highest sub-rank of {$current_cat}";
        } else {
            $new_target_idx  = min($current_idx + $new_increment, count($all_ranks) - 1);
            $target_rank     = $all_ranks[$new_target_idx];
            $flags[]         = "Category boundary crossed — re-computed under {$target_cat} weights";
        }
    }

    // National/International Award bonus note
    if ($has_national_award && ($result['award_bonus'] ?? 0) > 0) {
        $flags[] = 'National/International Award applied: +1 sub-rank bonus (JC3 s.2022 Step 4)';
    }

    // Step 4 &mdash; accreditation flags
    $final_cat = getRankCategory($target_rank);
    if (in_array($final_cat, ['College Professor', 'University Professor'])) {
        $flags[] = 'CC (Continuing Competency) certification required';
    }

    return [
        'potential_rank'   => $target_rank,
        'flags'            => $flags,
        'crossed_category' => $crossed,
        'recomputed_score' => $recomputed_score,
    ];
}

/**
 * Return the broad rank category for a given rank string.
 */
function getRankCategory(string $rank): string {
    if (preg_match('/^Instructor/i', $rank))           return 'Instructor';
    if (preg_match('/^Assistant Professor/i', $rank))  return 'Assistant Professor';
    if (preg_match('/^Associate Professor/i', $rank))  return 'Associate Professor';
    if (preg_match('/^Professor (I|II|III|IV|V|VI)$/i', $rank)) return 'Professor';
    if (preg_match('/^University Professor/i', $rank)) return 'University Professor';
    return 'Unknown';
}

/**
 * Return the highest sub-rank within a given category.
 */
function getHighestRankInCategory(string $category, array $all_ranks): string {
    $matches = array_filter($all_ranks, fn($r) => getRankCategory($r) === $category);
    return $matches ? end($matches) : '';
}

// -- Confirm Delete Modal --------------------------------------
/**
 * Renders a reusable Bootstrap confirm-delete modal + JS helper.
 * Call once per page (e.g. in footer). Trigger via:
 *   confirmDelete('Are you sure?', formId)
 */
function renderConfirmModal(): void { ?>
<!-- Confirm Modal -->
<div id="confirmModal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,0.5);z-index:99999;align-items:center;justify-content:center;padding:1rem;">
  <div style="background:#fff;border-radius:16px;width:100%;max-width:480px;box-shadow:0 20px 60px rgba(0,0,0,0.25);overflow:hidden;">

    <!-- Header with logo -->
    <div style="background:#1a3a6b;padding:1.25rem 1.5rem;display:flex;align-items:center;gap:0.875rem;">
      <img src="assets/images/logo.jpg" alt="SUCFRMS Logo"
           style="width:40px;height:40px;border-radius:50%;object-fit:cover;border:2px solid #475569;clip-path:circle(50%);flex-shrink:0;">
      <div>
        <div style="color:#fff;font-weight:700;font-size:0.95rem;line-height:1.2;">SUCFRMS</div>
        <div style="color:#bfdbfe;font-size:0.72rem;margin-top:2px;">SUC Faculty Reclassification Management System</div>
      </div>
    </div>

    <!-- Body -->
    <div style="padding:2rem 1.75rem 1.5rem;text-align:center;">
      <div id="confirmModalIconWrap" style="width:68px;height:68px;border-radius:50%;display:flex;align-items:center;justify-content:center;margin:0 auto 1.25rem;">
        <i id="confirmModalIcon" style="font-size:1.75rem;"></i>
      </div>
      <h5 id="confirmModalTitle" style="font-weight:700;color:#1e293b;margin-bottom:0.5rem;font-size:1.15rem;">Confirm Action</h5>
      <p id="confirmModalMsg" style="color:#64748b;font-size:0.9rem;margin:0;line-height:1.6;">Are you sure you want to proceed?</p>
    </div>

    <!-- Divider -->
    <div style="height:1px;background:#e2e8f0;margin:0 1.75rem;"></div>

    <!-- Buttons -->
    <div style="padding:1.25rem 1.75rem 1.75rem;display:flex;gap:0.75rem;">
      <button type="button" id="confirmModalCancelBtn"
              style="flex:1;padding:0.7rem 1rem;border:1px solid #cbd5e1;border-radius:8px;background:#fff;color:#475569;font-weight:600;cursor:pointer;font-size:0.875rem;">
        Cancel
      </button>
      <button type="button" id="confirmModalBtn"
              style="flex:1;padding:0.7rem 1rem;border:none;border-radius:8px;color:#fff;font-weight:600;cursor:pointer;font-size:0.875rem;display:flex;align-items:center;justify-content:center;gap:0.4rem;background:#1e4d8c;">
        <i id="confirmModalBtnIcon" class="bi bi-check-circle"></i>
        <span id="confirmModalBtnLabel">Confirm</span>
      </button>
    </div>
  </div>
</div>

<script>
const CONFIRM_GREEN_LABELS = ['approve','activate','save','update','add','generate','unlock','set','role','submit','join','send','verify','confirm','create','assign'];
const CONFIRM_RED_LABELS   = ['delete','reject','deny','remove','logout','deactivate','revoke','clear'];

function _showConfirmModal(msg, btnLabel, btnIcon, isRed, onConfirm) {
    const modal = document.getElementById('confirmModal');
    const wrap  = document.getElementById('confirmModalIconWrap');
    const icon  = document.getElementById('confirmModalIcon');
    const title = document.getElementById('confirmModalTitle');
    const btn   = document.getElementById('confirmModalBtn');

    if (isRed) {
        wrap.style.background = '#fef2f2';
        icon.className = 'bi ' + btnIcon;
        icon.style.color = '#dc2626';
        btn.style.background = '#dc2626';
    } else {
        wrap.style.background = '#f0fdf4';
        icon.className = 'bi ' + btnIcon;
        icon.style.color = '#16a34a';
        btn.style.background = '#16a34a';
    }

    title.textContent = btnLabel;
    document.getElementById('confirmModalMsg').textContent = msg;
    document.getElementById('confirmModalBtnLabel').textContent = btnLabel;
    document.getElementById('confirmModalBtnIcon').className = 'bi ' + btnIcon;

    const fresh = btn.cloneNode(true);
    btn.parentNode.replaceChild(fresh, btn);
    fresh.addEventListener('click', function() {
        modal.style.display = 'none';
        onConfirm();
    });

    modal.style.display = 'flex';
}

document.addEventListener('DOMContentLoaded', function() {
    const cancelBtn = document.getElementById('confirmModalCancelBtn');
    if (cancelBtn) cancelBtn.addEventListener('click', function() {
        document.getElementById('confirmModal').style.display = 'none';
    });
    const modal = document.getElementById('confirmModal');
    if (modal) modal.addEventListener('click', function(e) {
        if (e.target === this) this.style.display = 'none';
    });
});

function confirmDelete(msg, formId, btnLabel = 'Delete', btnIcon = 'bi-trash') {
    const labelLower = btnLabel.toLowerCase();
    const isRed = CONFIRM_RED_LABELS.some(w => labelLower.includes(w)) || !CONFIRM_GREEN_LABELS.some(w => labelLower.includes(w));
    _showConfirmModal(msg, btnLabel, btnIcon, isRed, function() {
        document.getElementById(formId).submit();
    });
}

function confirmAction(msg, callback, btnLabel = 'Confirm', btnIcon = 'bi-check-circle') {
    const labelLower = btnLabel.toLowerCase();
    const isRed = CONFIRM_RED_LABELS.some(w => labelLower.includes(w)) || !CONFIRM_GREEN_LABELS.some(w => labelLower.includes(w));
    _showConfirmModal(msg, btnLabel, btnIcon, isRed, callback);
}
</script>
<?php }


