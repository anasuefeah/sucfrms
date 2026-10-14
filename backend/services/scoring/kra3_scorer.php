<?php
/**
 * KRA III Scorer  -  Extension Services (100 points + 20 bonus)
 * DBM-CHED Joint Circular No. 01, s. 2026
 *
 * Structure:
 *   Criterion A - Service to the Institution:   max 30 pts
 *   Criterion B - Service to the Community:     max 50 pts
 *   Criterion C - Quality of Extension Services: max 20 pts (formula)
 *   Criterion D - Admin Designation Bonus:       max 20 pts (added on top)
 *   KRA III total = min(A + B + C + D, 100)
 *
 * CRITICAL CROSS-VALIDATION (Criterion C):
 *   An extension project can only earn CSR credit (Crit C) if the same project
 *   was ALSO declared under ISR in Criterion B. Reject Crit C independently.
 */

namespace Scoring;

class KRA3Scorer
{
    const CAP   = 100;
    const CAP_A = 30;
    const CAP_B = 50;
    const CAP_C = 20;
    const CAP_D = 20;    // added on top before final cap
    const ISR_CAP = 30;  // ISR sub-item within Criterion B

    private static ?\PDO $pdo_ref = null;
    private static ?int $criteria_cycle_id = null;
    private static ?string $criteria_position_rank = null;
    private static array $criteria_max_cache = [];

    public static function setPdo(\PDO $pdo): void
    {
        self::$pdo_ref = $pdo;
        self::$criteria_max_cache = [];
    }

    public static function setCriteriaContext(?int $cycleId, ?string $positionRank): void
    {
        self::$criteria_cycle_id = $cycleId ?: null;
        self::$criteria_position_rank = $positionRank !== null && trim($positionRank) !== '' ? trim($positionRank) : null;
        self::$criteria_max_cache = [];
    }

    public static function score(array $submissions): array
    {
        $crit_a   = 0.0;
        $crit_b   = 0.0;
        $crit_c   = 0.0;
        $crit_d   = 0.0;
        $pending  = [];
        $config_i = [];

        // Track ISR sub-total (nested cap within Crit B)
        $isr_subtotal = 0.0;
        // Track column total for media sub-item caps
        $media_occasional_count = 0;  // max 10 pts from occasional columns
        $technical_guest_count  = 0;  // max 10 pts from guesting

        // Collect ISR project titles declared under Crit B for cross-validation
        $isr_projects_b = [];
        // Collect CSR project titles declared under Crit C
        $csr_projects_c = [];

        // First pass: identify ISR projects in Crit B
        foreach ($submissions as $s) {
            $parts    = array_map('trim', explode('|||', $s['remarks'] ?? ''));
            $subtype  = strtolower($parts[0] ?? '');
            $title    = $parts[1] ?? '';
            if (str_contains($subtype, 'outreach') || str_contains($subtype, 'isr') || str_contains($subtype, 'b-outreach')) {
                $isr_projects_b[] = strtolower(trim($title));
            }
        }

        foreach ($submissions as $s) {
            $parts   = array_map('trim', explode('|||', $s['remarks'] ?? ''));
            $subtype = strtolower($parts[0] ?? '');
            $title   = $parts[1] ?? '';
            $val1    = $parts[2] ?? '';
            $val2    = $parts[3] ?? '';
            $pts     = 0.0;

            if ($subtype === 'custom') {
                $letter = strtoupper($parts[1] ?? '');
                $pts = max(0.0, (float)($parts[4] ?? 0));
                if ($letter === 'A') $crit_a += $pts;
                elseif ($letter === 'B') $crit_b += $pts;
                elseif ($letter === 'C') $crit_c += $pts;
                else $crit_d += $pts;
                continue;
            }

            // -- Criterion A: Service to the Institution --------------
            if (self::isCritA($subtype)) {
                $pts = self::scoreCritA($subtype, $val1, $val2, $config_i, $pending, $s);
                $crit_a += $pts;
            }
            // -- Criterion B: Service to the Community ----------------
            elseif (self::isCritB($subtype)) {
                [$pts, $isr_inc] = self::scoreCritB(
                    $subtype, $title, $val1, $val2,
                    $media_occasional_count, $technical_guest_count,
                    $isr_subtotal, $config_i, $pending, $s
                );
                $crit_b      += $pts;
                $isr_subtotal += $isr_inc;
            }
            // -- Criterion C: Quality of Extension Services -----------
            elseif (self::isCritC($subtype)) {
                $project_key = strtolower(trim($title));
                $csr_projects_c[] = $project_key;

                // CROSS-VALIDATION: only credit if project was declared under ISR in Crit B
                if (!in_array($project_key, $isr_projects_b) && !empty($project_key)) {
                    $config_i[] = "KRA III Crit C: Project '{$title}'  -  cross-validation FAILED. This project was not declared under ISR (Criterion B outreach/ISR). CSR credit was not counted per JC01 rule.";
                } else {
                    $rating = min(100, max(0, (float)$val1));
                    $cap = self::configuredMax('kra3_c_satisfaction', 20.0);
                    $pts = round(($rating / 100) * $cap, 2);
                    $crit_c += $pts;
                    if ($rating === 0.0) {
                        $pending[] = "KRA III Crit C: '{$title}'  -  CSR rating is 0. Provide CMO 18 s.2025 instrument summary.";
                    }
                }
            }
            // -- Criterion D: Bonus Admin Designation -----------------
            elseif (self::isCritD($subtype)) {
                $pts    = self::scoreCritD($subtype, (float)$val1, $pending, $s);
                $crit_d += $pts;
            }
            // Legacy extension format (activity|||income|||moa|||outreach)
            else {
                $income   = max(0, (float)($parts[1] ?? 0));
                $moa_cnt  = max(0, (int)($parts[2] ?? 0));
                $out_cnt  = max(0, (int)($parts[3] ?? 0));
                $inc_pts  = 0;
                // JC01 s.2026 tiers: above 12M | 6,000,001–12M | 500,001–6M | 100,001–500K | 100K and below
                if ($income > 12000000)       $inc_pts = 18;
                elseif ($income >= 6000001)   $inc_pts = 12;
                elseif ($income >= 500001)    $inc_pts = 6;
                elseif ($income >= 100001)    $inc_pts = 4;
                elseif ($income > 0)          $inc_pts = 2;
                $pts = $inc_pts + $moa_cnt * 5 + $out_cnt * 2;
                $crit_a += min(10, $inc_pts);   // income -> Crit A
                $crit_b += $moa_cnt * 5;        // MOA/linkage -> Crit A actually, but legacy maps differently
                $crit_b += $out_cnt * 2;
            }
        }

        // Apply sub-caps
        $crit_a = min(self::CAP_A, $crit_a);
        $crit_b = min(self::CAP_B, $crit_b);
        $crit_c = min(self::CAP_C, $crit_c);
        $crit_d = min(self::CAP_D, $crit_d);

        // ISR sub-cap within Crit B (nested cap of 30)
        // Already tracked in scoreCritB; the overall Crit B cap handles it.

        $sum_before_bonus = $crit_a + $crit_b + $crit_c;
        $capped_base      = min(self::CAP, $sum_before_bonus);
        $sum_with_bonus   = $capped_base + $crit_d;
        $subtotal         = min(self::CAP, $sum_with_bonus);

        return [
            'criterion_a'           => round($crit_a, 2),
            'criterion_b'           => round($crit_b, 2),
            'criterion_c'           => round($crit_c, 2),
            'criterion_d_bonus'     => round($crit_d, 2),
            'sum_before_bonus'      => round($sum_before_bonus, 2),
            'capped_base'           => round($capped_base, 2),
            'sum_with_bonus'        => round($sum_with_bonus, 2),
            'subtotal'              => round($subtotal, 2),
            'cap'                   => self::CAP,
            'pending_documentation' => $pending,
            'config_incomplete'     => $config_i,
        ];
    }

    // -- Category detectors -------------------------------------------
    private static function isCritA(string $sub): bool
    {
        return str_contains($sub, 'a-') || str_contains($sub, 'moa') || str_contains($sub, 'linkage')
            || str_contains($sub, 'income') || str_contains($sub, 'kra3_a');
    }

    private static function isCritB(string $sub): bool
    {
        return str_contains($sub, 'b-') || str_contains($sub, 'accredit') || str_contains($sub, 'judge')
            || str_contains($sub, 'consultant') || str_contains($sub, 'media') || str_contains($sub, 'column')
            || str_contains($sub, 'resource') || str_contains($sub, 'outreach') || str_contains($sub, 'isr')
            || str_contains($sub, 'tv') || str_contains($sub, 'radio') || str_contains($sub, 'kra3_b');
    }

    private static function isCritC(string $sub): bool
    {
        return str_contains($sub, 'c-') || str_contains($sub, 'csr') || str_contains($sub, 'satisfaction')
            || str_contains($sub, 'kra3_c');
    }

    private static function isCritD(string $sub): bool
    {
        return str_contains($sub, 'd-') || str_contains($sub, 'bonus') || str_contains($sub, 'designation')
            || str_contains($sub, 'kra3_d') || str_contains($sub, 'president') || str_contains($sub, 'dean')
            || str_contains($sub, 'director') || str_contains($sub, 'chancellor') || str_contains($sub, 'vice');
    }

    // -- Criterion A scorer -------------------------------------------
    private static function scoreCritA(
        string $subtype, string $val1, string $val2,
        array &$config_i, array &$pending, array $s
    ): float {
        // MOA/Linkage: 5 pts per successful instance
        if (str_contains($subtype, 'moa') || str_contains($subtype, 'linkage')) {
            if (empty($s['evidence_names'])) {
                $pending[] = "KRA III Crit A: MOA/Linkage  -  missing notarized MOA/MOU + proof of implementation + Board/President approval.";
            }
            return self::configuredMax('kra3_a_moa', 5.0);
        }
        // Income generation
        if (str_contains($subtype, 'income')) {
            $income = max(0, (float)$val1);
            $role   = strtolower($val2);
            $lead   = !str_contains($role, 'co') && !str_contains($role, 'contrib');

            $tier_pts = 0;
            if ($income > 12000000)       $tier_pts = self::configuredMax('kra3_a_income_above12m', 18.0);
            elseif ($income >= 6000001)   $tier_pts = self::configuredMax('kra3_a_income_6to12m', 12.0);
            elseif ($income >= 500001)    $tier_pts = self::configuredMax('kra3_a_income_below6m', 6.0);
            elseif ($income >= 100001)    $tier_pts = 4;
            elseif ($income > 0)          $tier_pts = 2;

            $pts = $lead ? (float)$tier_pts : round($tier_pts / 2, 2);
            if (empty($s['evidence_names'])) {
                $pending[] = "KRA III Crit A: Income generation  -  missing SUC Accountant-certified financial report.";
            }
            return $pts;
        }
        return 0.0;
    }

    // -- Criterion B scorer -------------------------------------------
    private static function scoreCritB(
        string $subtype, string $title, string $val1, string $val2,
        int &$media_occasional_count, int &$technical_guest_count,
        float $isr_subtotal, array &$config_i, array &$pending, array $s
    ): array {
        $pts     = 0.0;
        $isr_inc = 0.0;

        if (str_contains($subtype, 'accredit') || str_contains($subtype, 'qa') || str_contains($subtype, 'evaluation')) {
            $pts = str_contains($subtype, 'intl') ? self::configuredMax('kra3_b_accreditation_intl', 10.0) : self::configuredMax('kra3_b_accreditation_local', 8.0);
            if (empty($s['evidence_names'])) $pending[] = "KRA III Crit B: Accreditation '{$title}'  -  missing appointment letter + proof of engagement.";
        }
        elseif (str_contains($subtype, 'judge')) {
            $pts = str_contains($subtype, 'research') ? self::configuredMax('kra3_b_judge_research', 2.0) : self::configuredMax('kra3_b_judge_competition', 1.0);
            if (empty($s['evidence_names'])) $pending[] = "KRA III Crit B: Judging '{$title}'  -  missing appointment letter + proof.";
        }
        elseif (str_contains($subtype, 'consultant') || str_contains($subtype, 'expert')) {
            $pts = str_contains($subtype, 'intl') ? self::configuredMax('kra3_b_consultant_intl', 10.0) : self::configuredMax('kra3_b_consultant_local', 8.0);
            if (empty($s['evidence_names'])) $pending[] = "KRA III Crit B: Consultancy '{$title}'  -  missing contract of service.";
        }
        elseif (str_contains($subtype, 'column') || str_contains($subtype, 'media')) {
            if (str_contains($subtype, 'regular')) {
                $pts = self::configuredMax('kra3_b_column_regular', 10.0);
            } elseif (str_contains($subtype, 'occasional')) {
                if ($media_occasional_count * 2 < 10) {
                    $pts = self::configuredMax('kra3_b_column_occasional', 2.0);
                    $media_occasional_count++;
                }
            } elseif (str_contains($subtype, 'tv') || str_contains($subtype, 'radio') || str_contains($subtype, 'host')) {
                $pts = self::configuredMax('kra3_b_tv_radio_host', 10.0);
            } elseif (str_contains($subtype, 'guest')) {
                if ($technical_guest_count * 1 < 10) {
                    $pts = self::configuredMax('kra3_b_technical_guest', 1.0);
                    $technical_guest_count++;
                }
            }
        }
        elseif (str_contains($subtype, 'resource') || str_contains($subtype, 'speaker') || str_contains($subtype, 'facilitator')) {
            $rate  = str_contains($subtype, 'intl') ? self::configuredMax('kra3_b_resource_intl', 3.0) : self::configuredMax('kra3_b_resource_local', 2.0);
            $hours = max(1.0, (float)($val1 ?: 1));
            $pts   = round($rate * $hours, 2);
            if (empty($s['evidence_names'])) $pending[] = "KRA III Crit B: Resource person '{$title}'  -  missing invitation letter, program, certificate of appreciation.";
        }
        elseif (str_contains($subtype, 'outreach') || str_contains($subtype, 'isr')) {
            // ISR sub-cap of 30 within Crit B
            if ($isr_subtotal < self::ISR_CAP) {
                if (str_contains($subtype, 'head') || str_contains($subtype, 'lead')) {
                    $pts = min(self::configuredMax('kra3_b_outreach_head', 5.0), self::ISR_CAP - $isr_subtotal);
                } else {
                    $pts = min(self::configuredMax('kra3_b_outreach_participant', 2.0), self::ISR_CAP - $isr_subtotal);
                }
                $isr_inc = $pts;
            }
            if (empty($s['evidence_names'])) $pending[] = "KRA III Crit B: Outreach '{$title}'  -  missing appointment letter + proof of participation.";
        }

        return [$pts, $isr_inc];
    }

    // -- Criterion D scorer -------------------------------------------
    private static function scoreCritD(
        string $subtype, float $years, array &$pending, array $s
    ): float {
        // All rates are per year served (minimum 1 continuous year)
        $years = max(1, $years ?: 1);

        // Institutional level
        if (str_contains($subtype, 'president') && !str_contains($subtype, 'vice') && !str_contains($subtype, 'program')) return round(self::configuredMax('kra3_d_president', 20.0) * $years, 2);
        if (str_contains($subtype, 'vice-president') || str_contains($subtype, 'vice president') || (str_contains($subtype, 'vp') && !str_contains($subtype, 'dean'))) return round(self::configuredMax('kra3_d_vp', 15.0) * $years, 2);
        if (str_contains($subtype, 'chancellor') && !str_contains($subtype, 'vice')) return round(self::configuredMax('kra3_d_chancellor', 10.0) * $years, 2);
        if (str_contains($subtype, 'vice-chancellor') || str_contains($subtype, 'vice chancellor')) return round(self::configuredMax('kra3_d_vice_chancellor', 8.0) * $years, 2);
        if (str_contains($subtype, 'campus director') || str_contains($subtype, 'administrator')) return round(self::configuredMax('kra3_d_campus_director', 8.0) * $years, 2);
        if (str_contains($subtype, 'regent') || str_contains($subtype, 'trustee')) return round(self::configuredMax('kra3_d_faculty_regent', 8.0) * $years, 2);
        if (str_contains($subtype, 'office director') || (str_contains($subtype, 'director') && !str_contains($subtype, 'campus'))) return round(self::configuredMax('kra3_d_office_director', 6.0) * $years, 2);
        if (str_contains($subtype, 'university secretary') || str_contains($subtype, 'college secretary')) return round(self::configuredMax('kra3_d_univ_secretary', 6.0) * $years, 2);
        if (str_contains($subtype, 'project head') && str_contains($subtype, 'inst')) return round(self::configuredMax('kra3_d_project_head_inst', 4.0) * $years, 2);
        if (str_contains($subtype, 'committee chair') && str_contains($subtype, 'inst')) return round(self::configuredMax('kra3_d_committee_chair_inst', 3.0) * $years, 2);
        if (str_contains($subtype, 'committee member') && str_contains($subtype, 'inst')) return round(self::configuredMax('kra3_d_committee_member_inst', 2.0) * $years, 2);
        // College/Department level
        if (str_contains($subtype, 'dean') && !str_contains($subtype, 'assoc') && !str_contains($subtype, 'associate')) return round(self::configuredMax('kra3_d_dean', 6.0) * $years, 2);
        if (str_contains($subtype, 'associate dean')) return round(self::configuredMax('kra3_d_assoc_dean', 5.0) * $years, 2);
        if (str_contains($subtype, 'dept head') || str_contains($subtype, 'department head')) return round(self::configuredMax('kra3_d_dept_head', 4.0) * $years, 2);
        if (str_contains($subtype, 'program chair') || str_contains($subtype, 'project head')) return round(self::configuredMax('kra3_d_program_chair', 3.0) * $years, 2);
        if (str_contains($subtype, 'committee chair')) return round(self::configuredMax('kra3_d_committee_chair_dept', 2.0) * $years, 2);
        if (str_contains($subtype, 'committee member')) return round(self::configuredMax('kra3_d_committee_member_dept', 1.0) * $years, 2);
        // Coordinator: institutional level = 2 pts/yr; college/dept level = 1 pt/yr (JC01 s.2026 Annex)
        if (str_contains($subtype, 'coordinator') && (str_contains($subtype, 'inst') || str_contains($subtype, 'university') || str_contains($subtype, 'univ')))
            return round(self::configuredMax('kra3_d_coordinator_inst', 2.0) * $years, 2);
        if (str_contains($subtype, 'coordinator'))
            return round(self::configuredMax('kra3_d_coordinator_dept', 1.0) * $years, 2);
        // Fallback: generic designation
        if (empty($s['evidence_names'])) {
            $pending[] = "KRA III Crit D: Designation bonus  -  missing appointment order with effectivity period + accomplishment report.";
        }
        return 0.0;
    }

    /**
     * Per-entry scorer for kra_ajax.php compatibility.
     */
    public static function computeFromRemarks(string $remarks): float
    {
        $parts    = array_map('trim', explode('|||', $remarks));
        $subtype  = strtolower($parts[0] ?? '');
        $val1     = $parts[2] ?? '0';
        $val2     = $parts[3] ?? '';
        $dummy_ci = [];
        $dummy_pd = [];
        $dummy_s  = ['evidence_names' => null];
        $isr_sub  = 0.0;
        $med_occ  = 0;
        $tech_g   = 0;

        if ($subtype === 'custom') {
            return max(0.0, (float)($parts[4] ?? 0));
        }

        if (self::isCritA($subtype)) return self::scoreCritA($subtype, $val1, $val2, $dummy_ci, $dummy_pd, $dummy_s);
        if (self::isCritC($subtype)) {
            $rating = min(100, max(0, (float)$val1));
            return round(($rating / 100) * self::configuredMax('kra3_c_satisfaction', 20.0), 2);
        }
        if (self::isCritD($subtype)) return self::scoreCritD($subtype, (float)($val1 ?: 1), $dummy_pd, $dummy_s);
        if (self::isCritB($subtype)) {
            [$pts,] = self::scoreCritB($subtype, $parts[1] ?? '', $val1, $val2, $med_occ, $tech_g, $isr_sub, $dummy_ci, $dummy_pd, $dummy_s);
            return $pts;
        }
        // Legacy extension format
        $income  = max(0, (float)($parts[1] ?? 0));
        $moa     = max(0, (int)($parts[2] ?? 0));
        $out     = max(0, (int)($parts[3] ?? 0));
        $inc_pts = 0;
        // JC01 s.2026 tiers: above 12M | 6,000,001–12M | 500,001–6M | 100,001–500K | 100K and below
        if ($income > 12000000)       $inc_pts = 18;
        elseif ($income >= 6000001)   $inc_pts = 12;
        elseif ($income >= 500001)    $inc_pts = 6;
        elseif ($income >= 100001)    $inc_pts = 4;
        elseif ($income > 0)          $inc_pts = 2;
        return (float)($inc_pts + $moa * 5 + $out * 2);
    }

    private static function configuredMax(string $key, float $default): float
    {
        if (isset(self::$criteria_max_cache[$key])) return self::$criteria_max_cache[$key];
        $value = $default;
        if (self::$pdo_ref !== null) {
            try {
                $stmt = self::$pdo_ref->prepare("SELECT max_points, cycle_id, position_rank FROM scoring_criteria WHERE criterion_key=? AND is_active=1");
                $stmt->execute([$key]);
                $best_score = -1;
                foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
                    $score = self::criteriaScopeScore($row);
                    if ($score > $best_score) {
                        $best_score = $score;
                        $value = (float)$row['max_points'];
                    }
                }
            } catch (\Exception $e) {
                $value = $default;
            }
        }
        self::$criteria_max_cache[$key] = $value;
        return $value;
    }

    private static function criteriaScopeScore(array $row): int
    {
        $row_cycle = isset($row['cycle_id']) ? (int)$row['cycle_id'] : null;
        $row_position = isset($row['position_rank']) && $row['position_rank'] !== null ? trim((string)$row['position_rank']) : null;
        if (self::$criteria_cycle_id !== null && $row_cycle === self::$criteria_cycle_id) {
            if (self::$criteria_position_rank !== null && $row_position === self::$criteria_position_rank) return 3;
            if ($row_position === null || $row_position === '') return 2;
        }
        if ($row_cycle === null || $row_cycle === 0) return 1;
        return 0;
    }
}
