<?php
// Faculty ranks and potential-rank calculation.

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
 *   potential_rank  string   - e.g. "Associate Professor III"
 *   flags           string[] - e.g. ['EAC accreditation required']
 *   crossed_category bool    - true if a rank-category boundary was crossed
 *   recomputed_score float|null - weighted score under new-category weights
 */
function computePotentialRank(array $raw_kra, string $current_rank, bool $has_national_award = false): array {
    $all_ranks   = facultyRanks();
    $current_idx = array_search($current_rank, $all_ranks);

    // Unknown rank - cannot compute
    if ($current_idx === false) {
        return [
            'potential_rank'    => ' - ',
            'flags'             => ['Current rank not recognised'],
            'crossed_category'  => false,
            'recomputed_score'  => null,
        ];
    }

    // Step 1 - weighted score under current rank weights
    $result    = computeWeightedScore($raw_kra, $current_rank, $has_national_award);
    $increment = $result['sub_rank_increment'];  // already includes award bonus if applicable

    // No reclassification
    if ($increment === 0) {
        return [
            'potential_rank'    => $current_rank,
            'flags'             => ['Score below 41  -  no reclassification'],
            'crossed_category'  => false,
            'recomputed_score'  => null,
        ];
    }

    // Step 2 - count sub-ranks forward (capped at end of rank list)
    $target_idx  = min($current_idx + $increment, count($all_ranks) - 1);
    $target_rank = $all_ranks[$target_idx];

    // Step 3 - detect category crossing
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
            // Does not qualify under new-category weights - cap at highest sub-rank of current category
            $highest_in_cat = getHighestRankInCategory($current_cat, $all_ranks);
            $target_rank    = $highest_in_cat;
            $flags[]        = "Score insufficient under {$target_cat} weights  -  awarded highest sub-rank of {$current_cat}";
        } else {
            $new_target_idx  = min($current_idx + $new_increment, count($all_ranks) - 1);
            $target_rank     = $all_ranks[$new_target_idx];
            $flags[]         = "Category boundary crossed  -  re-computed under {$target_cat} weights";
        }
    }

    // Prestigious Award bonus note
    if ($has_national_award && ($result['award_bonus'] ?? 0) > 0) {
        $flags[] = 'Prestigious Award (Auto Sub-Rank Trigger 2) applied: +1 sub-rank bonus (JC01 s.2026 Art. IV)';
    }

    // Step 4 - accreditation flags
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
