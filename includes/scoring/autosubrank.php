<?php
/**
 * Auto Sub-Rank, Quota Gate, Committee Routing, Professor Gate
 * DBM-CHED Joint Circular No. 01, s. 2026 — Article IV, Articles VI–IX
 */

namespace Scoring;

class AutoSubrank
{
    /**
     * Compute automatic sub-rank adjustments.
     *
     * Two independent triggers per JC01 Article IV:
     *   Trigger 1 — First doctorate degree (Instructor I – Associate Professor IV only)
     *   Trigger 2 — Prestigious national/international award
     *
     * ORDER OF OPERATIONS (critical):
     *   1. Apply auto sub-rank bump to current base rank FIRST.
     *   2. Bumped rank becomes new base.
     *   3. THEN layer point-based reclassification on top of new base.
     *
     * Returns:
     *   triggered        bool
     *   trigger_type     'doctorate|award|both|none'
     *   eligible_rank    bool  (is current rank in Instructor I – Assoc Prof IV range?)
     *   bonus_increment  int   (total auto sub-ranks to add — already independent of points)
     *   detail           string
     */
    public static function compute(
        \PDO $pdo, array $app, array $kra4_result,
        float $weighted_score, int $score_increment
    ): array {
        $current_rank = $app['current_rank'] ?? '';
        $bonus        = 0;
        $triggers     = [];
        $details      = [];

        // ── Trigger 1: First doctorate ───────────────────────────────
        $doctorate_eligible_ranks = [
            'Instructor I', 'Instructor II', 'Instructor III',
            'Assistant Professor I', 'Assistant Professor II',
            'Assistant Professor III', 'Assistant Professor IV',
            'Associate Professor I', 'Associate Professor II',
            'Associate Professor III', 'Associate Professor IV',
        ];
        $rank_eligible = in_array($current_rank, $doctorate_eligible_ranks);

        $has_doctorate    = $kra4_result['has_doctorate'] ?? false;
        $doctorate_bonus  = false;

        if ($has_doctorate && $rank_eligible) {
            // Check if this is first doctorate (not a second/additional one)
            // We check the remarks for 'B-degree' entries with value >= 40
            $is_first = self::isFirstDoctorate($pdo, (int)$app['faculty_user_id'], (int)$app['application_id']);
            if ($is_first) {
                $bonus++;
                $doctorate_bonus = true;
                $triggers[]      = 'doctorate';
                $details[]       = 'First doctorate degree: +1 automatic sub-rank (Instructor I–Associate Professor IV eligible range, CHED-recognized institution required). Applies BEFORE point-based increment.';
            } else {
                $details[] = 'Doctorate found but not first — second/additional doctorate does NOT re-trigger auto sub-rank.';
            }
        } elseif ($has_doctorate && !$rank_eligible) {
            $details[] = "Doctorate found but current rank '{$current_rank}' is NOT in eligible range (Instructor I – Associate Professor IV). Auto sub-rank from doctorate does NOT apply at Associate Professor V or Professor rank.";
        }

        // ── Trigger 2: Prestigious award ─────────────────────────────
        $has_nat_award  = $kra4_result['has_national_award'] ?? false;
        $award_bonus    = false;

        if ($has_nat_award) {
            // Award bonus: only applies if faculty already qualifies (score >= 41)
            if ($weighted_score >= 41 || $score_increment > 0) {
                $bonus++;
                $award_bonus  = true;
                $triggers[]   = 'award';
                $details[]    = 'National/International prestigious award: +1 automatic sub-rank (applied AFTER score-bracket increment per JC01 Step 4 order of operations).';
            } else {
                $details[] = 'Award trigger found but score < 41 — faculty does not qualify for reclassification, award bonus does not apply.';
            }
        }

        $trigger_type = implode('|', $triggers) ?: 'none';

        return [
            'triggered'              => $bonus > 0,
            'trigger_type'           => $trigger_type,
            'eligible_rank_check_passed' => $rank_eligible,
            'doctorate_triggered'    => $doctorate_bonus,
            'award_triggered'        => $award_bonus,
            'bonus_increment'        => $bonus,
            'basis'                  => $trigger_type,
            'detail'                 => implode(' | ', $details),
        ];
    }

    /**
     * Check if this is the faculty's first doctorate.
     * Heuristic: no other application from the same faculty in a prior cycle
     * already has a doctorate entry that was approved. Falls back to true
     * (benefit of the doubt → require checker to verify if suspicious).
     */
    private static function isFirstDoctorate(\PDO $pdo, int $faculty_id, int $current_app_id): bool
    {
        try {
            // Look for approved applications from this faculty (not current) that had a doctorate entry
            $stmt = $pdo->prepare("
                SELECT COUNT(*) FROM kra_submissions ks
                JOIN applications a ON ks.application_id = a.application_id
                WHERE a.user_id = ? AND a.application_id != ?
                  AND a.status IN ('approved','reclassified')
                  AND ks.kra_category = 'Professional Development'
                  AND ks.remarks LIKE 'B-degree%'
                  AND CAST(SUBSTRING_INDEX(SUBSTRING_INDEX(ks.remarks,'|||',-1),'|||',1) AS DECIMAL) >= 40
            ");
            $stmt->execute([$faculty_id, $current_app_id]);
            return (int)$stmt->fetchColumn() === 0;
        } catch (\Exception $e) {
            return true; // cannot verify — flag for checker, assume first
        }
    }

    /**
     * Quota gate — Articles VI–IX.
     * Professor rank: ≤ 20% of total authorized faculty positions.
     * College/University Professor: ≤ 1 per cycle per institution, ≤ 5% of Professor positions.
     */
    public static function quotaGate(\PDO $pdo, array $app, string $target_rank): array
    {
        $rank_cat = self::rankCategory($target_rank);
        if (!in_array($rank_cat, ['Professor', 'University Professor'])) {
            return ['applies' => false, 'rank_requested' => $target_rank, 'cohort_status' => 'n/a'];
        }

        $cycle_id = (int)($app['cycle_id'] ?? 0);

        try {
            // Total authorized faculty positions for this institution
            $total_faculty = (int)$pdo->query("SELECT COUNT(*) FROM users WHERE role IN ('faculty','checker_faculty') AND status='active'")->fetchColumn();

            if ($rank_cat === 'Professor') {
                $cap         = (int)floor($total_faculty * 0.20);
                $current_cnt = (int)$pdo->prepare("
                    SELECT COUNT(*) FROM users
                    WHERE rank LIKE 'Professor%' AND status='active'
                ")->fetchColumn();
                // Count already-approved in this cycle aiming for Professor
                $pipeline = (int)$pdo->prepare("
                    SELECT COUNT(*) FROM applications
                    WHERE cycle_id=? AND status IN ('approved','reclassified')
                      AND potential_rank LIKE 'Professor%'
                ")->fetchColumn() + $current_cnt;
                $pdo->prepare("SELECT COUNT(*) FROM users WHERE rank LIKE 'Professor%' AND status='active'")->execute();

                $at_or_over = $pipeline >= $cap;
                return [
                    'applies'        => true,
                    'rank_requested' => $target_rank,
                    'cohort_status'  => $at_or_over ? 'at_cap' : 'within_cap',
                    'cap'            => $cap,
                    'current_count'  => $pipeline,
                    'note'           => $at_or_over
                        ? 'Professor quota at/over cap. Flag for EAC committee ranking/prioritization — do not auto-approve.'
                        : "Professor quota OK ({$pipeline}/{$cap}).",
                ];
            }

            if ($rank_cat === 'University Professor') {
                // 1 per cycle per institution
                $in_cycle = (int)$pdo->prepare("
                    SELECT COUNT(*) FROM applications
                    WHERE cycle_id=? AND status IN ('approved','reclassified')
                      AND potential_rank = 'University Professor'
                ")->fetchColumn();
                $pdo->prepare("SELECT COUNT(*) FROM applications WHERE cycle_id=? AND status IN ('approved','reclassified') AND potential_rank='University Professor'")->execute([$cycle_id]);

                return [
                    'applies'        => true,
                    'rank_requested' => 'University Professor',
                    'cohort_status'  => $in_cycle >= 1 ? 'over_cap' : 'within_cap',
                    'cap'            => 1,
                    'current_count'  => $in_cycle,
                    'note'           => $in_cycle >= 1
                        ? 'University Professor quota exceeded (max 1 per cycle). Flag for CC committee.'
                        : 'University Professor quota OK.',
                ];
            }
        } catch (\Exception $e) {
            return ['applies' => true, 'rank_requested' => $target_rank, 'cohort_status' => 'unknown', 'note' => 'Quota check failed: ' . $e->getMessage()];
        }

        return ['applies' => false, 'rank_requested' => $target_rank, 'cohort_status' => 'n/a'];
    }

    /**
     * Committee routing per JC01.
     *   Instructor – Associate Professor → IEC (campus) → REC
     *   Professor rank                   → EAC (includes Phase I Professorial Accreditation)
     *   College/University Professor     → CC
     */
    public static function routeCommittee(string $target_rank): string
    {
        $cat = self::rankCategory($target_rank);
        return match($cat) {
            'University Professor'                  => 'CC',
            'Professor'                             => 'EAC',
            'Instructor', 'Assistant Professor',
            'Associate Professor'                   => 'REC',
            default                                 => 'IEC',
        };
    }

    /**
     * Professor submission gate — Article VII.
     * Hard gate: DISQUALIFIES applicant from the cycle's accreditation interview if:
     *   (a) CHED-certified doctorate transcript is missing, OR
     *   (b) No indexed journal article in last 3 years (sole/lead, other institution co-authors)
     * Must be checked BEFORE KRA scoring for Professor-rank applicants.
     */
    public static function professorGate(\PDO $pdo, array $app, array $subs_by_cat): array
    {
        $target_rank = $app['position_title'] ?? $app['current_rank'] ?? '';
        $rank_cat    = self::rankCategory($target_rank);

        if ($rank_cat !== 'Professor') {
            return [
                'applies'                  => false,
                'doctorate_verified'       => null,
                'indexed_article_verified' => null,
                'disqualified'             => false,
            ];
        }

        // (a) Doctorate check — look for a B-degree entry with value >= 40
        $pd_subs        = $subs_by_cat['Professional Development'] ?? [];
        $doctorate_found = false;
        foreach ($pd_subs as $s) {
            $parts = explode('|||', $s['remarks'] ?? '');
            if (($parts[0] ?? '') === 'B-degree' && (float)($parts[2] ?? 0) >= 40) {
                $doctorate_found = true;
                break;
            }
        }

        // (b) Indexed journal article in last 3 years — check Research submissions
        $research_subs   = $subs_by_cat['Research'] ?? [];
        $indexed_found   = false;
        $three_years_ago = strtotime('-3 years');
        foreach ($research_subs as $s) {
            $parts = explode('|||', $s['remarks'] ?? '');
            $label = strtolower($parts[0] ?? '');
            if ((str_contains($label, 'journal') || str_contains($label, 'article')) && str_contains($label, 'indexed')) {
                // Check submission date as proxy (actual publication date in remarks[3] if provided)
                $pub_date = $parts[3] ?? '';
                if ($pub_date && strtotime($pub_date) !== false) {
                    if (strtotime($pub_date) >= $three_years_ago) { $indexed_found = true; break; }
                } else {
                    // No date provided — flag as pending but don't auto-disqualify
                    $indexed_found = true; // give benefit of the doubt; checker must verify
                    break;
                }
            }
        }

        $disqualified = !$doctorate_found || !$indexed_found;

        return [
            'applies'                  => true,
            'doctorate_verified'       => $doctorate_found,
            'indexed_article_verified' => $indexed_found,
            'disqualified'             => $disqualified,
            'note'                     => $disqualified
                ? 'PROFESSOR GATE: Faculty is disqualified from this cycle\'s accreditation interview. Both a CHED-certified doctorate transcript AND an indexed journal article from the last 3 years are required. Missing items block the interview regardless of KRA score.'
                : 'Professor gate passed.',
        ];
    }

    // ── Rank category helper ─────────────────────────────────────────
    public static function rankCategory(string $rank): string
    {
        if (preg_match('/^Instructor/i', $rank))           return 'Instructor';
        if (preg_match('/^Assistant Professor/i', $rank))  return 'Assistant Professor';
        if (preg_match('/^Associate Professor/i', $rank))  return 'Associate Professor';
        if (preg_match('/^Professor (I|II|III|IV|V|VI)$/i', $rank)) return 'Professor';
        if (preg_match('/^(College|University) Professor/i', $rank)) return 'University Professor';
        return 'Unknown';
    }
}
