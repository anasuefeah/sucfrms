<?php
/**
 * KRA IV Scorer  -  Professional Development (100 points + 20 bonus)
 * DBM-CHED Joint Circular No. 01, s. 2026
 *
 * Structure:
 *   Criterion A - Professional Organizations:  max 20 pts (5 per year)
 *   Criterion B - Continuing Development:      max 60 pts
 *     - educational qualifications (no sub-cap within B)
 *     - training/conference attendance:         sub-cap 10
 *     - paper presentations:                    sub-cap 10
 *   Criterion C - Awards and Recognition:       max 20 pts
 *   Criterion D - Bonus (Newly Hired Faculty):  max 20 pts (added on top)
 *   KRA IV total = min(A + B + C + D, 100)
 */

namespace Scoring;

class KRA4Scorer
{
    const CAP   = 100;
    const CAP_A = 20;
    const CAP_B = 60;
    const CAP_B_TRAINING = 10;   // sub-cap for training/conference
    const CAP_B_PAPER    = 10;   // sub-cap for paper presentations
    const CAP_C = 20;
    const CAP_D = 20;

    private static ?\PDO $pdo_ref = null;
    private static ?int $criteria_cycle_id = null;
    private static ?string $criteria_position_rank = null;
    private static array $criteria_max_cache = [];
    private static ?float $doctorate_points_override = null;

    public static function setPdo(\PDO $pdo): void
    {
        self::$pdo_ref = $pdo;
        self::$criteria_max_cache = [];
        self::$intl_award_pts_loaded = false;
    }

    public static function setCriteriaContext(?int $cycleId, ?string $positionRank): void
    {
        self::$criteria_cycle_id = $cycleId ?: null;
        self::$criteria_position_rank = $positionRank !== null && trim($positionRank) !== '' ? trim($positionRank) : null;
        self::$criteria_max_cache = [];
        self::$intl_award_pts_loaded = false;
    }

    public static function setDoctoratePointsOverride(?float $points): void
    {
        self::$doctorate_points_override = $points;
    }

    private static function professionalOrgYears($raw): float
    {
        $years = max(1.0, (float)($raw ?? 1));

        // Earlier entry forms stored the fixed point value (5) instead of the
        // unit count. Treat that legacy value as one qualifying membership.
        return $years === 5.0 ? 1.0 : $years;
    }

    public static function score(array $submissions): array
    {
        $crit_a         = 0.0;
        $crit_b_edu     = 0.0;
        $crit_b_train   = 0.0;
        $crit_b_paper   = 0.0;
        $crit_c         = 0.0;
        $crit_d         = 0.0;
        $pending        = [];
        $config_i       = [];
        $has_nat_award  = false;  // triggers auto sub-rank
        $has_doctorate  = false;  // for auto sub-rank module

        // Track training and paper sub-caps
        $train_total = 0.0;
        $paper_total = 0.0;

        // Check if doctorate is triggered for sub-rank (not used for KRA points)
        // We'll add 40 points automatically if doctorate exists but is not triggered
        $auto_doctorate_points = 0.0;

        foreach ($submissions as $s) {
            $parts    = array_map('trim', explode('|||', $s['remarks'] ?? ''));
            $critType = $parts[0] ?? '';
            $desc     = $parts[1] ?? '';
            $subVal   = (float)($parts[2] ?? 0);
            $pts      = 0.0;

            if ($critType === 'CUSTOM') {
                $letter = strtoupper($parts[1] ?? '');
                $pts = max(0.0, (float)($parts[4] ?? 0));
                if ($letter === 'A') $crit_a += $pts;
                elseif ($letter === 'B') $crit_b_edu += $pts;
                elseif ($letter === 'C') $crit_c += $pts;
                else $crit_d += $pts;
                continue;
            }

            switch ($critType) {
                // -- Criterion A: Professional Organizations ----------
                case 'A-org':
                    // 5 pts per year of current active membership
                    $years = self::professionalOrgYears($parts[2] ?? 1);
                    $pts   = min(self::CAP_A, self::configuredMax('kra4_a_org_membership', 5.0) * $years);
                    if (empty($s['evidence_names'])) {
                        $pending[] = "KRA IV Crit A: '{$desc}'  -  missing membership certificate/ID and engagement certification from org head.";
                    }
                    $crit_a += $pts;
                    break;

                // -- Criterion B: Educational Qualifications ----------
                case 'B-degree':
                    if ($subVal >= 40.0) {
                        $pts = 0.0;
                        $has_doctorate = true;
                    } elseif ($subVal >= 20.0) {
                        $pts = self::configuredMax('kra4_b_additional_masters', 20.0);
                    } elseif ($subVal >= 10.0) {
                        $pts = self::configuredMax('kra4_b_postmaster_cert', 10.0);
                    } else {
                        $pts = 0.0;
                    }
                    
                    if (empty($s['evidence_names'])) {
                        $pending[] = "KRA IV Crit B: Educational qualification '{$desc}'  -  missing transcript of records / diploma / certificate.";
                    }
                    $crit_b_edu += $pts;
                    break;

                // -- Criterion B: Training/Conference/Workshop --------
                case 'B-training':
                    $pts_each = str_contains(strtolower($desc), 'intl') || $subVal >= 2
                        ? self::configuredMax('kra4_b_training_intl', 2.0)
                        : self::configuredMax('kra4_b_training_local', 1.0);
                    // International capacity-building in PH: must have >=3 countries represented
                    if ($pts_each === 2.0 && str_contains(strtolower($desc), 'philippines')) {
                        if (empty($parts[3]) || (int)$parts[3] < 3) {
                            $config_i[] = "KRA IV Crit B: '{$desc}'  -  international activity in PH requires >=3 countries represented among speakers/participants to qualify at international rate.";
                            $pts_each = self::configuredMax('kra4_b_training_local', 1.0); // downgrade to local rate
                        }
                    }
                    // Hours check: min 6 hours (8 if virtual)
                    $hours   = (float)($parts[3] ?? 0);
                    $virtual = str_contains(strtolower($desc), 'virtual') || str_contains(strtolower($desc), 'online');
                    $min_hrs = $virtual ? 8 : 6;
                    if ($hours > 0 && $hours < $min_hrs) {
                        $config_i[] = "KRA IV Crit B: '{$desc}'  -  minimum {$min_hrs} training hours required (" . ($virtual ? '8 virtual' : '6 in-person') . "). Only {$hours}h declared  -  excluded.";
                        break;
                    }
                    if ($train_total + $pts_each <= self::CAP_B_TRAINING) {
                        $pts          = $pts_each;
                        $train_total += $pts;
                    } else {
                        $config_i[] = "KRA IV Crit B: Training/conference sub-cap (10 pts) reached. '{$desc}' excluded.";
                    }
                    if (empty($s['evidence_names'])) {
                        $pending[] = "KRA IV Crit B: '{$desc}'  -  missing certificate of participation.";
                    }
                    $crit_b_train += $pts;
                    break;

                // -- Criterion B: Paper Presentations ----------------
                case 'B-paper':
                    $pts_each = $subVal >= 5 ? self::configuredMax('kra4_b_paper_intl', 5.0) : self::configuredMax('kra4_b_paper_local', 3.0);
                    $is_intl  = ($pts_each === 5.0);
                    // International: requires 3 countries among speakers/participants
                    if ($is_intl) {
                        $countries = (int)($parts[3] ?? 0);
                        if ($countries < 3) {
                            $config_i[] = "KRA IV Crit B: Paper '{$desc}'  -  international rate requires >=3 countries represented among speakers/participants. Downgrading to local rate.";
                            $pts_each = self::configuredMax('kra4_b_paper_local', 3.0);
                        }
                    }
                    if ($paper_total + $pts_each <= self::CAP_B_PAPER) {
                        $pts          = $pts_each;
                        $paper_total += $pts;
                    } else {
                        $config_i[] = "KRA IV Crit B: Paper presentation sub-cap (10 pts) reached. '{$desc}' excluded.";
                    }
                    if (empty($s['evidence_names'])) {
                        $pending[] = "KRA IV Crit B: Paper '{$desc}'  -  missing acceptance letter/certificate + research director certification (not derived from student thesis/dissertation) + governing/President approval to present.";
                    }
                    $crit_b_paper += $pts;
                    break;

                // -- Criterion C: Awards & Recognition ---------------
                case 'C-award':
                    /*
                     * JC01 s.2026 confirmed tiers (KRA IV Crit C evidence table):
                     *   subVal 2  = Institutional                        → 2 pts  (CONFIRMED)
                     *   subVal 3  = Local / Regional (City/Prov/Region)  → 3 pts  (CONFIRMED)
                     *   subVal 4  = National                             → 4 pts  (CONFIRMED)
                     *   subVal 5  = International                        → CONFIG_INCOMPLETE (GAP — blank in circular)
                     *   subVal 0  = Prestigious Award (Ramon Magsaysay-tier)
                     *              → 0 KRA IV pts; triggers Auto Sub-Rank review only (JC01 Art. IV Trigger 2)
                     *              → requires human checker confirmation; never auto-approved
                     *
                     * NOTE: "Local and Regional" in JC01 = 3 pts (same tier).
                     * Only the HIGHEST award per event/competition is counted — do not sum placements.
                     */
                    if ($subVal === 0.0) {
                        // Prestigious award → sub-rank trigger only; no KRA IV points.
                        $has_nat_award = true;
                        $config_i[] = "KRA IV Crit C: '{$desc}'  -  prestigious national/international award flagged for Auto Sub-Rank review (JC01 Art. IV Trigger 2). No KRA IV points awarded here; sub-rank requires human checker confirmation.";
                        break;
                    }

                    if ($subVal >= 5) {
                        /*
                         * International award — CONFIRMED SOURCE GAP (JC01 s.2026, KRA IV Crit C table).
                         * The Points column for the International tier is blank in the official circular.
                         * CONFIG_INTERNATIONAL_AWARD_PTS must be set by an administrator before this
                         * entry can score anything. Until configured, returns PENDING_DOCUMENTATION.
                         * Confirm value with CHED-RO or adviser before finalising.
                         */
                        $intl_pts = self::getInternationalAwardPoints();
                        if ($intl_pts === null) {
                            $pending[] = "KRA IV Crit C - International Award: '{$desc}'  -  PENDING_DOCUMENTATION. "
                                       . "CONFIG_INTERNATIONAL_AWARD_PTS has not been set by a system administrator. "
                                       . "The Points value for International awards is blank in JC01 s.2026 (confirmed source gap). "
                                       . "This entry cannot be scored until the value is confirmed with CHED-RO or your adviser and entered in Scoring Criteria configuration.";
                            // No points added
                        } else {
                            $pts = (float)$intl_pts;
                            $config_i[] = "KRA IV Crit C: International Award '{$desc}' scored at {$pts} pt(s) using administrator-configured CONFIG_INTERNATIONAL_AWARD_PTS. "
                                        . "Note: this value was not sourced from the JC01 circular (Points column is blank)  -  confirm with CHED-RO before finalising.";
                            // Only highest per event; no duplicate check here — checker responsibility
                            if (empty($s['evidence_names'])) {
                                $pending[] = "KRA IV Crit C: '{$desc}'  -  missing award certificate and picture of plaque/trophy/medal.";
                            }
                            $crit_c += $pts;
                        }
                        break;
                    }

                    $pts = match(true) {
                        $subVal >= 4  => self::configuredMax('kra4_c_award_national', 4.0),
                        $subVal >= 3  => self::configuredMax('kra4_c_award_local', 3.0),
                        $subVal >= 2  => self::configuredMax('kra4_c_award_institutional', 2.0),
                        default       => 0.0,
                    };
                    // Only the HIGHEST award per event/competition  -  do not sum multiple placements
                    if (empty($s['evidence_names'])) {
                        $pending[] = "KRA IV Crit C: '{$desc}'  -  missing award certificate and picture of plaque/trophy/medal.";
                    }
                    $crit_c += $pts;
                    break;

                // -- Criterion D: Bonus for Newly Hired Faculty -------
                case 'D-prior-academic':
                    $years = max(1, (float)($parts[2] ?? 1));
                    $role  = strtolower($parts[1] ?? '');
                    if (str_contains($role, 'president'))          $pts = self::configuredMax('kra4_d_academic_president', 5.0) * $years;
                    elseif (str_contains($role, 'vp') || str_contains($role, 'vice') || str_contains($role, 'dean') || str_contains($role, 'director')) $pts = self::configuredMax('kra4_d_academic_vp_dean', 4.0) * $years;
                    elseif (str_contains($role, 'dept') || str_contains($role, 'head') || str_contains($role, 'program')) $pts = self::configuredMax('kra4_d_academic_dept_head', 3.0) * $years;
                    else $pts = self::configuredMax('kra4_d_academic_faculty', 2.0) * $years;
                    if (empty($s['evidence_names'])) $pending[] = "KRA IV Crit D: '{$desc}'  -  missing service record / certificate of employment / appointment order.";
                    $crit_d += $pts;
                    break;

                case 'D-prior-industry':
                    $years = max(1, (float)($parts[2] ?? 1));
                    $role  = strtolower($parts[1] ?? '');
                    if (str_contains($role, 'managerial') || str_contains($role, 'supervisor')) $pts = self::configuredMax('kra4_d_industry_managerial', 4.0) * $years;
                    elseif (str_contains($role, 'technical') || str_contains($role, 'skilled'))  $pts = self::configuredMax('kra4_d_industry_technical', 3.0) * $years;
                    else $pts = self::configuredMax('kra4_d_industry_support', 2.0) * $years;
                    if (empty($s['evidence_names'])) $pending[] = "KRA IV Crit D: '{$desc}'  -  missing service record / certificate of employment / appointment order for industry experience.";
                    $crit_d += $pts;
                    break;

                default:
                    // Legacy PD format: credential|||degree_value
                    // Only set the has_doctorate flag here; actual points are
                    // applied by the doctorate override block below to avoid
                    // double-counting with setDoctoratePointsOverride().
                    if (is_numeric($parts[1] ?? '')) {
                        $dv = (float)$parts[1];
                        if ($dv >= 40)      { $has_doctorate = true; }
                        elseif ($dv >= 20)  { $crit_b_edu += 20.0; }
                        elseif ($dv >= 10)  { $crit_b_edu += 10.0; }
                    }
                    break;
            }
        }

        // Apply sub-caps
        $crit_a = min(self::CAP_A, $crit_a);
        $crit_b = min(self::CAP_B, $crit_b_edu + $crit_b_train + $crit_b_paper);
        $crit_c = min(self::CAP_C, $crit_c);
        $crit_d = min(self::CAP_D, $crit_d);

        // -- Doctorate auto sub-rank points -----------------------------------
        // The orchestrator always calls KRA4Scorer::setDoctoratePointsOverride()
        // before invoking score(). If that was not done, we skip the bonus
        // rather than reaching into the HTTP request or global scope.
        if ($has_doctorate) {
            $doctorate_pts = self::$doctorate_points_override;

            if ((float)$doctorate_pts > 0) {
                $crit_b_edu += $doctorate_pts;
                $crit_b      = min(self::CAP_B, $crit_b_edu + $crit_b_train + $crit_b_paper);
            }
        }

        $sum_before_bonus = $crit_a + $crit_b + $crit_c;
        $capped_base      = min(self::CAP, $sum_before_bonus);
        $sum_with_bonus   = $capped_base + $crit_d;
        $subtotal         = min(self::CAP, $sum_with_bonus);

        return [
            'criterion_a'           => round($crit_a, 2),
            'criterion_b'           => round($crit_b, 2),
            'criterion_b_edu'       => round($crit_b_edu, 2),
            'criterion_b_train'     => round($crit_b_train, 2),
            'criterion_b_paper'     => round($crit_b_paper, 2),
            'criterion_c'           => round($crit_c, 2),
            'criterion_d_bonus'     => round($crit_d, 2),
            'sum_before_bonus'      => round($sum_before_bonus, 2),
            'capped_base'           => round($capped_base, 2),
            'sum_with_bonus'        => round($sum_with_bonus, 2),
            'subtotal'              => round($subtotal, 2),
            'cap'                   => self::CAP,
            'has_national_award'    => $has_nat_award,
            'has_doctorate'         => $has_doctorate,
            'pending_documentation' => $pending,
            'config_incomplete'     => $config_i,
        ];
    }

    /**
     * Per-entry scorer for kra_ajax.php compatibility.
     */
    public static function computeFromRemarks(string $remarks): float
    {
        $parts    = array_map('trim', explode('|||', $remarks));
        $critType = $parts[0] ?? '';
        $subVal   = (float)($parts[2] ?? 0);

        if ($critType === 'CUSTOM') {
            return max(0.0, (float)($parts[4] ?? 0));
        }

        switch ($critType) {
            case 'A-org':
                $years = self::professionalOrgYears($parts[2] ?? 1);
                return min(self::CAP_A, self::configuredMax('kra4_a_org_membership', 5.0) * $years);
            case 'B-degree':
                if ($subVal >= 40)  return 0.0;
                if ($subVal >= 20)  return self::configuredMax('kra4_b_additional_masters', 20.0);
                if ($subVal >= 10)  return self::configuredMax('kra4_b_postmaster_cert', 10.0);
                return 0.0;
            case 'B-training':
                return $subVal >= 2 ? self::configuredMax('kra4_b_training_intl', 2.0) : self::configuredMax('kra4_b_training_local', 1.0);
            case 'B-paper':
                return $subVal >= 5 ? self::configuredMax('kra4_b_paper_intl', 5.0) : self::configuredMax('kra4_b_paper_local', 3.0);
            case 'C-award':
                if ($subVal === 0.0) return 0.0;  // prestigious award = sub-rank trigger only
                if ($subVal >= 5) {
                    // International: GAP — return 0 here; full score() raises PENDING_DOCUMENTATION
                    $intl_pts = self::getInternationalAwardPoints();
                    return $intl_pts !== null ? (float)$intl_pts : 0.0;
                }
                if ($subVal >= 4)  return self::configuredMax('kra4_c_award_national', 4.0);
                if ($subVal >= 3)  return self::configuredMax('kra4_c_award_local', 3.0);
                if ($subVal >= 2)  return self::configuredMax('kra4_c_award_institutional', 2.0);
                return 0.0;
            case 'D-prior-academic':
            case 'D-prior-industry':
                $years = max(1, (float)($parts[2] ?? 1));
                $role  = strtolower($parts[1] ?? '');
                if ($critType === 'D-prior-academic') {
                    if (str_contains($role, 'president')) return self::configuredMax('kra4_d_academic_president', 5.0) * $years;
                    if (str_contains($role, 'vp') || str_contains($role, 'dean') || str_contains($role, 'director')) return self::configuredMax('kra4_d_academic_vp_dean', 4.0) * $years;
                    if (str_contains($role, 'dept') || str_contains($role, 'head')) return self::configuredMax('kra4_d_academic_dept_head', 3.0) * $years;
                    return self::configuredMax('kra4_d_academic_faculty', 2.0) * $years;
                }
                if (str_contains($role, 'managerial') || str_contains($role, 'supervisor')) return self::configuredMax('kra4_d_industry_managerial', 4.0) * $years;
                if (str_contains($role, 'technical') || str_contains($role, 'skilled')) return self::configuredMax('kra4_d_industry_technical', 3.0) * $years;
                return self::configuredMax('kra4_d_industry_support', 2.0) * $years;
            default:
                // Legacy: degree value in $parts[1]
                $dv = (float)($parts[1] ?? 0);
                if ($dv >= 40) return 40.0;
                if ($dv >= 20) return 20.0;
                if ($dv >= 10) return 10.0;
                // Allowlist check (old format)
                $allowed = [5.0, 1.0, 2.0, 3.0, 4.0];
                if (in_array($subVal, $allowed)) return $subVal;
                return 0.0;
        }
    }

    /**
     * Return administrator-configured points for the International award tier.
     *
     * This value is a CONFIRMED SOURCE GAP — the Points column for the International
     * tier is blank in the official JC01 s.2026 circular (KRA IV Crit C table).
     * Returns null → not configured; full score() raises PENDING_DOCUMENTATION.
     * Returns float → admin has explicitly set the value; use it.
     *
     * criterion_key: 'kra4_c_award_international'
     * max_points = 0 means "not yet configured" (same mechanic as kra1_c_mentor_competition).
     * Confirm value with CHED-RO or adviser before finalising.
     */
    private static ?float  $intl_award_pts_cache  = null;
    private static bool    $intl_award_pts_loaded  = false;

    private static function getInternationalAwardPoints(): ?float
    {
        if (self::$intl_award_pts_loaded) return self::$intl_award_pts_cache;
        self::$intl_award_pts_loaded = true;
        self::$intl_award_pts_cache  = null;

        if (self::$pdo_ref === null) return null;

        try {
            $stmt = self::$pdo_ref->prepare(
                "SELECT max_points, cycle_id, position_rank
                 FROM scoring_criteria
                 WHERE criterion_key = 'kra4_c_award_international'
                   AND is_active = 1"
            );
            $stmt->execute();
            $best_score = -1;
            $best_val   = null;
            foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
                $score = self::criteriaScopeScore($row);
                if ($score > $best_score) {
                    $best_score = $score;
                    $best_val   = $row['max_points'];
                }
            }
            // max_points = 0 means "not yet configured"
            self::$intl_award_pts_cache = ($best_val !== null && (float)$best_val > 0)
                ? (float)$best_val
                : null;
        } catch (\Exception $e) {
            self::$intl_award_pts_cache = null;
        }

        return self::$intl_award_pts_cache;
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
