<?php
/**
 * Shared self-assessment scoring engine.
 *
 * peAggregateScores() implements the EXACT same logic as the Orchestrator:
 *   - All 4 KRA scorers get setPdo + setCriteriaContext (global scope, no cycle/position)
 *   - KRA4 doctorate points override for ineligible ranks (+40 pts, same as Orchestrator step 4)
 *   - TWO-PASS weighted score (Pass1: auto-bumped base rank; Pass2: Initial Reclassified Rank)
 *   - Prestigious awards count toward the estimate and require official evaluator confirmation
 *   - Doctorate: benefit-of-doubt (Self-Assessment has no approved applications to check)
 *
 * Used by the Self-Assessment page, AJAX endpoint, and PDF exports.
 *
 * Requires: $pdo already available; scorer modules already loaded (or will be loaded here).
 */

// Ensure scorer modules are loaded
$_pe_scoring_dir = __DIR__ . '/scoring/';
foreach (['kra1_scorer.php','kra2_scorer.php','kra3_scorer.php','kra4_scorer.php','autosubrank.php','orchestrator.php'] as $_pe_sf) {
    if (file_exists($_pe_scoring_dir . $_pe_sf) && !class_exists('\Scoring\\' . match($_pe_sf) {
        'kra1_scorer.php'  => 'KRA1Scorer',
        'kra2_scorer.php'  => 'KRA2Scorer',
        'kra3_scorer.php'  => 'KRA3Scorer',
        'kra4_scorer.php'  => 'KRA4Scorer',
        'autosubrank.php'  => 'AutoSubRankCalculator',
        'orchestrator.php' => 'Orchestrator',
        default            => '__none__',
    }, false)) {
        require_once $_pe_scoring_dir . $_pe_sf;
    }
}

if (!function_exists('peAggregateScores')):
/**
 * Aggregate all Self-Assessment entries for a user through the official scorer
 * modules using the exact same two-pass Orchestrator logic.
 *
 * @param PDO    $pdo   Active DB connection
 * @param int    $uid   Faculty user_id
 * @param string $rank  Faculty current rank (e.g. "Associate Professor II")
 * @return array {
 *   kra1, kra2, kra3, kra4         float   subtotals per KRA
 *   weighted_score                  float   Pass-2 weighted score
 *   sub_rank_increment              int     increment including auto bump
 *   weights                         array   Pass-2 KRA weights used
 *   pot_rank                        string  recommended target rank
 *   kra1_detail..kra4_detail        array   per-criterion breakdowns
 *   has_doctorate, has_national_award bool  trigger flags
 *   auto_subrank                    array   bump details + notes
 *   pending_documentation           array   scorer flags
 *   config_incomplete               array   scorer flags
 * }
 */
function peAggregateScores(\PDO $pdo, int $uid, string $rank): array
{
    // -- Load all entries shaped as scorer expects --------------------
    $stmt = $pdo->prepare("
        SELECT pe.entry_id AS submission_id,
               pe.kra_category,
               pe.remarks,
               pe.computed_points,
               GROUP_CONCAT(pf.original_filename ORDER BY pf.file_id SEPARATOR '|||') AS evidence_names
        FROM pre_eval_entries pe
        LEFT JOIN pre_eval_files pf ON pf.entry_id = pe.entry_id
        WHERE pe.user_id = ?
        GROUP BY pe.entry_id
        ORDER BY pe.created_at ASC
    ");
    $stmt->execute([$uid]);
    $all = $stmt->fetchAll(\PDO::FETCH_ASSOC);

    $by_cat = [];
    foreach ($all as $row) {
        $by_cat[$row['kra_category']][] = $row;
    }

    // -- Provide PDO + criteria context to all scorers ---------------
    // Use the active open cycle + faculty rank for cycle-specific criteria,
    // falling back to globals (cycle_id=0) if no open cycle exists.
    $openCycle = $pdo->query("SELECT cycle_id, start_date, end_date FROM cycles WHERE status='open' ORDER BY created_at DESC LIMIT 1")
        ->fetch(\PDO::FETCH_ASSOC) ?: [];
    $pe_scorer_cycle = (int)($openCycle['cycle_id'] ?? 0);
    $priorServiceEligible = \Scoring\KRA4Scorer::isNewlyAppointed($pdo, $uid, $openCycle['start_date'] ?? null, $openCycle['end_date'] ?? null);
    $pe_scorer_rank  = (trim($rank) !== '') ? trim($rank) : null;
    $criteria = CriteriaSnapshots::rows($pdo, $pe_scorer_cycle, $pe_scorer_rank);
    $additionalScores = [];
    $entryCounts = [];
    $entriesWithFiles = 0;
    foreach ($by_cat as $category => $entries) {
        $by_cat[$category] = kraCurrentCriterionEntries($entries, array_filter($criteria, static fn(array $row): bool => $row['kra_category'] === $category));
        $additionalScores[$category] = kraAdditionalCriterionScores($by_cat[$category]);
        $entryCounts[$category] = count($by_cat[$category]);
        $entriesWithFiles += count(array_filter($by_cat[$category], static fn(array $row): bool => ($row['evidence_names'] ?? '') !== ''));
    }

    if (class_exists('\Scoring\KRA1Scorer')) { \Scoring\KRA1Scorer::setPdo($pdo); \Scoring\KRA1Scorer::setCriteriaContext($pe_scorer_cycle, $pe_scorer_rank); }
    if (class_exists('\Scoring\KRA2Scorer')) { \Scoring\KRA2Scorer::setPdo($pdo); \Scoring\KRA2Scorer::setCriteriaContext($pe_scorer_cycle, $pe_scorer_rank); }
    if (class_exists('\Scoring\KRA3Scorer')) { \Scoring\KRA3Scorer::setPdo($pdo); \Scoring\KRA3Scorer::setCriteriaContext($pe_scorer_cycle, $pe_scorer_rank); }
    if (class_exists('\Scoring\KRA4Scorer')) { \Scoring\KRA4Scorer::setPdo($pdo); \Scoring\KRA4Scorer::setCriteriaContext($pe_scorer_cycle, $pe_scorer_rank); }

    // -- STEP 1: Score KRA I, II, III; first-pass KRA IV (no doctorate override yet) --
    $kra1 = class_exists('\Scoring\KRA1Scorer')
        ? \Scoring\KRA1Scorer::score($by_cat['Instruction'] ?? [])
        : ['subtotal'=>0,'criterion_a'=>0,'criterion_b'=>0,'criterion_c'=>0,'cap'=>100,'pending_documentation'=>[],'config_incomplete'=>[]];

    $kra2 = class_exists('\Scoring\KRA2Scorer')
        ? \Scoring\KRA2Scorer::score($by_cat['Research'] ?? [])
        : ['subtotal'=>0,'criterion_a_raw'=>0,'criterion_b_raw'=>0,'criterion_c_raw'=>0,'sum_before_cap'=>0,'cap'=>100,'pending_documentation'=>[],'config_incomplete'=>[]];

    $kra3 = class_exists('\Scoring\KRA3Scorer')
        ? \Scoring\KRA3Scorer::score($by_cat['Extension'] ?? [])
        : ['subtotal'=>0,'criterion_a'=>0,'criterion_b'=>0,'criterion_c'=>0,'criterion_d_bonus'=>0,'cap'=>100,'pending_documentation'=>[],'config_incomplete'=>[]];

    // First KRA IV pass — detect doctorate/award flags before override
    $kra4_pre = class_exists('\Scoring\KRA4Scorer')
        ? \Scoring\KRA4Scorer::score($by_cat['Professional Development'] ?? [], $priorServiceEligible)
        : ['subtotal'=>0,'criterion_a'=>0,'criterion_b'=>0,'criterion_c'=>0,'criterion_d_bonus'=>0,'cap'=>100,'has_doctorate'=>false,'has_national_award'=>false,'pending_documentation'=>[],'config_incomplete'=>[]];

    $has_doctorate = $kra4_pre['has_doctorate']     ?? false;
    $has_nat_award = $kra4_pre['has_national_award'] ?? false;

    // -- STEP 2: Doctorate rank eligibility (same as AutoSubRankCalculator::INELIGIBLE_RANK_PATTERNS) --
    $rank_ineligible_for_trigger = (bool)preg_match(
        '/^(Associate\s+Professor\s+V|Professor\s+(I|II|III|IV|V|VI)|University\s+Professor)/i',
        trim($rank)
    );
    $rank_eligible_for_trigger = !$rank_ineligible_for_trigger;

    // Doctorate auto sub-rank preliminary bump (benefit of the doubt — no historical check)
    $auto_bump_pre = ($has_doctorate && $rank_eligible_for_trigger) ? 1 : 0;
    $auto_triggers = $auto_bump_pre > 0 ? ['doctorate'] : [];

    // -- STEP 3: KRA IV doctorate points override (same as Orchestrator steps 4-4a) --
    // Eligible ranks  → rank-trigger mode  → 0 KRA IV pts, +1 base-rank bump
    // Ineligible ranks → points-only mode  → +40 KRA IV pts, 0 rank bump
    $doctorate_pts_for_kra4 = 0.0;
    if ($has_doctorate && !$rank_eligible_for_trigger) {
        $doctorate_pts_for_kra4 = class_exists('\Scoring\AutoSubRankCalculator')
            ? \Scoring\AutoSubRankCalculator::DOCTORATE_POINTS
            : 40.0;
    }

    // Re-score KRA IV with doctorate override
    if (class_exists('\Scoring\KRA4Scorer')) {
        \Scoring\KRA4Scorer::setDoctoratePointsOverride($doctorate_pts_for_kra4 > 0.0 ? $doctorate_pts_for_kra4 : null);
        $kra4 = \Scoring\KRA4Scorer::score($by_cat['Professional Development'] ?? [], $priorServiceEligible);
        \Scoring\KRA4Scorer::setDoctoratePointsOverride(null);
    } else {
        $kra4 = $kra4_pre;
    }

    // -- STEP 4: TWO-PASS weighted score (exact Orchestrator algorithm) --------
    $all_ranks = facultyRanks();
    $cur_idx   = array_search($rank, $all_ranks);

    // Compute auto-bumped base rank (doctorate bump applied to current rank)
    $auto_base_rank = $rank;
    if ($auto_bump_pre > 0 && $cur_idx !== false) {
        $auto_base_rank = $all_ranks[min($cur_idx + $auto_bump_pre, count($all_ranks) - 1)];
    }

    // Pass 1 — use auto-bumped base rank weights
    $weights_pass1  = getKraWeights($auto_base_rank);
    $weighted_pass1 = round(
        ($kra1['subtotal'] * $weights_pass1['Instruction']) +
        ($kra2['subtotal'] * $weights_pass1['Research']) +
        ($kra3['subtotal'] * $weights_pass1['Extension']) +
        ($kra4['subtotal'] * $weights_pass1['Professional Development']),
        2
    );
    $increment_pass1 = getSubRankIncrement($weighted_pass1);

    // Compute Initial Reclassified Rank from Pass 1
    $initial_reclassified_rank = $auto_base_rank;
    if ($increment_pass1 > 0) {
        $base_idx_p1 = array_search($auto_base_rank, $all_ranks);
        if ($base_idx_p1 !== false) {
            $initial_reclassified_rank = $all_ranks[min($base_idx_p1 + $increment_pass1, count($all_ranks) - 1)];
        }
    }

    // Pass 2 — re-weight using Initial Reclassified Rank's weights
    $weights_pass2  = getKraWeights($initial_reclassified_rank);
    $weighted_pass2 = round(
        ($kra1['subtotal'] * $weights_pass2['Instruction']) +
        ($kra2['subtotal'] * $weights_pass2['Research']) +
        ($kra3['subtotal'] * $weights_pass2['Extension']) +
        ($kra4['subtotal'] * $weights_pass2['Professional Development']),
        2
    );
    $increment_pass2 = getSubRankIncrement($weighted_pass2);

    // -- STEP 5: Award bonus (in self-assessment, award counts directly as +1) ----
    // No evaluator confirmation needed — self-assessment is a personal estimation tool.
    $award_bump = 0;
    if ($has_nat_award) {
        $award_bump      = 1;
        $auto_triggers[] = 'award';
    }
    $total_auto_bump = $auto_bump_pre + $award_bump;
    $sub_rank        = $increment_pass2 + $total_auto_bump;

    // -- STEP 6: Final recommended rank (includes all bumps) -----------------
    $base_idx       = array_search($auto_base_rank, $all_ranks);
    $target_rank    = $auto_base_rank;
    $total_increment = $increment_pass2 + $award_bump;  // score-based + award
    if ($base_idx !== false && $total_increment > 0) {
        $target_rank = $all_ranks[min($base_idx + $total_increment, count($all_ranks) - 1)];
    }

    // -- Merge pending/config flags from all KRAs ----------------------------
    $pending  = array_merge(
        $kra1['pending_documentation'] ?? [],
        $kra2['pending_documentation'] ?? [],
        $kra3['pending_documentation'] ?? [],
        $kra4['pending_documentation'] ?? []
    );
    $config_i = array_merge(
        $kra1['config_incomplete'] ?? [],
        $kra2['config_incomplete'] ?? [],
        $kra3['config_incomplete'] ?? [],
        $kra4['config_incomplete'] ?? []
    );

    return [
        'additional_scores'  => $additionalScores,
        'parent_scores'      => [
            'Instruction' => $kra1['parent_scores'] ?? [],
            'Research' => $kra2['parent_scores'] ?? [],
            'Extension' => $kra3['parent_scores'] ?? [],
            'Professional Development' => $kra4['parent_scores'] ?? [],
        ],
        'entry_counts'       => $entryCounts,
        'entries_with_files' => $entriesWithFiles,
        // KRA subtotals
        'kra1'               => $kra1['subtotal'],
        'kra2'               => $kra2['subtotal'],
        'kra3'               => $kra3['subtotal'],
        'kra4'               => $kra4['subtotal'],
        // Scoring
        'weighted_score'     => $weighted_pass2,
        'sub_rank_increment' => $sub_rank,
        'weights'            => $weights_pass2,
        'pot_rank'           => $target_rank,
        // Per-criterion breakdowns (same keys the PDF and UI panels expect)
        'kra1_detail' => [
            'criterion_a' => $kra1['criterion_a'] ?? 0,
            'criterion_b' => $kra1['criterion_b'] ?? 0,
            'criterion_c' => $kra1['criterion_c'] ?? 0,
            'subtotal'    => $kra1['subtotal'],
            'cap'         => $kra1['cap'] ?? 100,
        ],
        'kra2_detail' => [
            'criterion_a_raw' => $kra2['criterion_a_raw'] ?? 0,
            'criterion_b_raw' => $kra2['criterion_b_raw'] ?? 0,
            'criterion_c_raw' => $kra2['criterion_c_raw'] ?? 0,
            'sum_before_cap'  => $kra2['sum_before_cap']  ?? 0,
            'subtotal'        => $kra2['subtotal'],
            'cap'             => $kra2['cap'] ?? 100,
        ],
        'kra3_detail' => [
            'criterion_a'      => $kra3['criterion_a']      ?? 0,
            'criterion_b'      => $kra3['criterion_b']      ?? 0,
            'criterion_c'      => $kra3['criterion_c']      ?? 0,
            'criterion_d_bonus'=> $kra3['criterion_d_bonus'] ?? 0,
            'subtotal'         => $kra3['subtotal'],
            'cap'              => $kra3['cap'] ?? 100,
        ],
        'kra4_detail' => [
            'criterion_a'      => $kra4['criterion_a']      ?? 0,
            'criterion_b'      => $kra4['criterion_b']      ?? 0,
            'criterion_c'      => $kra4['criterion_c']      ?? 0,
            'criterion_d_bonus'=> $kra4['criterion_d_bonus'] ?? 0,
            'subtotal'         => $kra4['subtotal'],
            'cap'              => $kra4['cap'] ?? 100,
        ],
        // Auto sub-rank info
        'has_doctorate'      => $has_doctorate,
        'has_national_award' => $has_nat_award,
        'auto_subrank' => [
            'applied'           => $total_auto_bump > 0,
            'trigger'           => implode(' + ', $auto_triggers) ?: 'none',
            'bonus_increment'   => $total_auto_bump,
            'doctorate_pts'     => $doctorate_pts_for_kra4,
            'updated_base_rank' => $auto_base_rank,
            'pass1_weighted'    => $weighted_pass1,
            'pass2_weighted'    => $weighted_pass2,
            'note'              => 'Two-pass scoring (mirrors Orchestrator). '
                . ($auto_bump_pre > 0 ? 'Doctorate assumed first-use (no historical check in self-assessment). ' : '')
                . ($has_nat_award     ? 'Award: counted as +1 in self-assessment (evaluator verification required in official application).' : ''),
        ],
        // Scorer flags
        'pending_documentation' => $pending,
        'config_incomplete'     => $config_i,
    ];
}
endif;
