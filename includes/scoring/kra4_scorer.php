<?php
/**
 * KRA IV Scorer — Professional Development (100 points + 20 bonus)
 * DBM-CHED Joint Circular No. 01, s. 2026
 *
 * Structure:
 *   Criterion A – Professional Organizations:  max 20 pts (5 per year)
 *   Criterion B – Continuing Development:      max 60 pts
 *     - educational qualifications (no sub-cap within B)
 *     - training/conference attendance:         sub-cap 10
 *     - paper presentations:                    sub-cap 10
 *   Criterion C – Awards and Recognition:       max 20 pts
 *   Criterion D – Bonus (Newly Hired Faculty):  max 20 pts (added on top)
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

            switch ($critType) {
                // ── Criterion A: Professional Organizations ──────────
                case 'A-org':
                    // 5 pts per year of current active membership
                    $years = max(1, (float)($parts[2] ?? 1));
                    $pts   = min(self::CAP_A, 5.0 * $years);
                    if (empty($s['evidence_names'])) {
                        $pending[] = "KRA IV Crit A: '{$desc}' — missing membership certificate/ID and engagement certification from org head.";
                    }
                    $crit_a += $pts;
                    break;

                // ── Criterion B: Educational Qualifications ──────────
                case 'B-degree':
                    $pts = match(true) {
                        $subVal >= 20  => 20.0,  // additional master's
                        $subVal >= 10  => 10.0,  // post-master's / post-doc diploma
                        default        => 0.0,
                    };
                    // Doctorate entries (40pts) are now handled by Auto Sub Rank tab
                    // They either give +1 sub-rank OR 40 points, never both
                    if ($subVal >= 40.0) $has_doctorate = true;
                    
                    if (empty($s['evidence_names'])) {
                        $pending[] = "KRA IV Crit B: Educational qualification '{$desc}' — missing transcript of records / diploma / certificate.";
                    }
                    $crit_b_edu += $pts;
                    break;

                // ── Criterion B: Training/Conference/Workshop ────────
                case 'B-training':
                    $pts_each = str_contains(strtolower($desc), 'intl') || $subVal >= 2 ? 2.0 : 1.0;
                    // International capacity-building in PH: must have ≥3 countries represented
                    if ($pts_each === 2.0 && str_contains(strtolower($desc), 'philippines')) {
                        if (empty($parts[3]) || (int)$parts[3] < 3) {
                            $config_i[] = "KRA IV Crit B: '{$desc}' — international activity in PH requires ≥3 countries represented among speakers/participants to qualify at international rate.";
                            $pts_each = 1.0; // downgrade to local rate
                        }
                    }
                    // Hours check: min 6 hours (8 if virtual)
                    $hours   = (float)($parts[3] ?? 0);
                    $virtual = str_contains(strtolower($desc), 'virtual') || str_contains(strtolower($desc), 'online');
                    $min_hrs = $virtual ? 8 : 6;
                    if ($hours > 0 && $hours < $min_hrs) {
                        $config_i[] = "KRA IV Crit B: '{$desc}' — minimum {$min_hrs} training hours required (" . ($virtual ? '8 virtual' : '6 in-person') . "). Only {$hours}h declared — excluded.";
                        break;
                    }
                    if ($train_total + $pts_each <= self::CAP_B_TRAINING) {
                        $pts          = $pts_each;
                        $train_total += $pts;
                    } else {
                        $config_i[] = "KRA IV Crit B: Training/conference sub-cap (10 pts) reached. '{$desc}' excluded.";
                    }
                    if (empty($s['evidence_names'])) {
                        $pending[] = "KRA IV Crit B: '{$desc}' — missing certificate of participation.";
                    }
                    $crit_b_train += $pts;
                    break;

                // ── Criterion B: Paper Presentations ────────────────
                case 'B-paper':
                    $pts_each = $subVal >= 5 ? 5.0 : 3.0;
                    $is_intl  = ($pts_each === 5.0);
                    // International: requires 3 countries among speakers/participants
                    if ($is_intl) {
                        $countries = (int)($parts[3] ?? 0);
                        if ($countries < 3) {
                            $config_i[] = "KRA IV Crit B: Paper '{$desc}' — international rate requires ≥3 countries represented among speakers/participants. Downgrading to local rate.";
                            $pts_each = 3.0;
                        }
                    }
                    if ($paper_total + $pts_each <= self::CAP_B_PAPER) {
                        $pts          = $pts_each;
                        $paper_total += $pts;
                    } else {
                        $config_i[] = "KRA IV Crit B: Paper presentation sub-cap (10 pts) reached. '{$desc}' excluded.";
                    }
                    if (empty($s['evidence_names'])) {
                        $pending[] = "KRA IV Crit B: Paper '{$desc}' — missing acceptance letter/certificate + research director certification (not derived from student thesis/dissertation) + governing/President approval to present.";
                    }
                    $crit_b_paper += $pts;
                    break;

                // ── Criterion C: Awards & Recognition ───────────────
                case 'C-award':
                    // Only 3 confirmed tiers per JC01 evidence table
                    $pts = match(true) {
                        $subVal >= 4  => 4.0,   // national (and international — see below)
                        $subVal >= 3  => 3.0,   // local/regional
                        $subVal >= 2  => 2.0,   // institutional
                        default       => 0.0,
                    };
                    // International award: no distinct tier in source table.
                    // Per JC01 gap note: default to national rate (4) and flag explicitly.
                    if ($subVal === 0.0) {
                        // 0-pts award = national/international → triggers auto sub-rank only
                        $has_nat_award = true;
                        $config_i[] = "KRA IV Crit C: '{$desc}' — national/international award triggers +1 automatic sub-rank (JC01 Art. IV). No KRA IV points awarded here; sub-rank handled by Auto Sub-Rank module.";
                        break;
                    }
                    // International award stored at national rate (4) with explicit flag
                    if (str_contains(strtolower($desc), 'international') && $pts === 4.0) {
                        $config_i[] = "KRA IV Crit C: '{$desc}' — international award defaulted to national rate (4 pts) per JC01 known gap (no distinct international tier in confirmed points table). Flag for committee review.";
                    }
                    // Only the HIGHEST award per event/competition — do not sum multiple placements
                    if (empty($s['evidence_names'])) {
                        $pending[] = "KRA IV Crit C: '{$desc}' — missing award certificate and picture of plaque/trophy/medal.";
                    }
                    $crit_c += $pts;
                    break;

                // ── Criterion D: Bonus for Newly Hired Faculty ───────
                case 'D-prior-academic':
                    $years = max(1, (float)($parts[2] ?? 1));
                    $role  = strtolower($parts[1] ?? '');
                    if (str_contains($role, 'president'))          $pts = 5.0 * $years;
                    elseif (str_contains($role, 'vp') || str_contains($role, 'vice') || str_contains($role, 'dean') || str_contains($role, 'director')) $pts = 4.0 * $years;
                    elseif (str_contains($role, 'dept') || str_contains($role, 'head') || str_contains($role, 'program')) $pts = 3.0 * $years;
                    else $pts = 2.0 * $years;  // faculty member
                    if (empty($s['evidence_names'])) $pending[] = "KRA IV Crit D: '{$desc}' — missing service record / certificate of employment / appointment order.";
                    $crit_d += $pts;
                    break;

                case 'D-prior-industry':
                    $years = max(1, (float)($parts[2] ?? 1));
                    $role  = strtolower($parts[1] ?? '');
                    if (str_contains($role, 'managerial') || str_contains($role, 'supervisor')) $pts = 4.0 * $years;
                    elseif (str_contains($role, 'technical') || str_contains($role, 'skilled'))  $pts = 3.0 * $years;
                    else $pts = 2.0 * $years;  // support/admin staff
                    if (empty($s['evidence_names'])) $pending[] = "KRA IV Crit D: '{$desc}' — missing service record / certificate of employment / appointment order for industry experience.";
                    $crit_d += $pts;
                    break;

                default:
                    // Legacy PD format: credential|||degree_value
                    if (is_numeric($parts[1] ?? '')) {
                        $dv = (float)$parts[1];
                        if ($dv >= 40) { $crit_b_edu += 40.0; $has_doctorate = true; }
                        elseif ($dv >= 20) $crit_b_edu += 20.0;
                        elseif ($dv >= 10) $crit_b_edu += 10.0;
                    }
                    break;
            }
        }

        // Apply sub-caps
        $crit_a = min(self::CAP_A, $crit_a);
        $crit_b = min(self::CAP_B, $crit_b_edu + $crit_b_train + $crit_b_paper);
        $crit_c = min(self::CAP_C, $crit_c);
        $crit_d = min(self::CAP_D, $crit_d);

        // Handle doctorate logic: if doctorate exists but is NOT triggered for sub-rank, award 40 points
        if ($has_doctorate) {
            // Check Auto Sub Rank status - if doctorate not triggered, add 40 points to education
            global $pdo;
            if (isset($pdo) && !empty($_GET['app_id'])) {
                $app_id = (int)$_GET['app_id'];
                $auto_check = $pdo->prepare("SELECT doctorate_status FROM auto_sub_rank WHERE application_id = ?");
                $auto_check->execute([$app_id]);
                $auto_data = $auto_check->fetch();
                
                if (!$auto_data || $auto_data['doctorate_status'] !== 'Triggered') {
                    // Doctorate not triggered for sub-rank, so award 40 points instead
                    $crit_b_edu += 40.0;
                    $crit_b = min(self::CAP_B, $crit_b_edu + $crit_b_train + $crit_b_paper);
                }
            }
        }

        $sum_with_bonus = $crit_a + $crit_b + $crit_c + $crit_d;
        $subtotal       = min(self::CAP, $sum_with_bonus);

        return [
            'criterion_a'           => round($crit_a, 2),
            'criterion_b'           => round($crit_b, 2),
            'criterion_b_edu'       => round($crit_b_edu, 2),
            'criterion_b_train'     => round($crit_b_train, 2),
            'criterion_b_paper'     => round($crit_b_paper, 2),
            'criterion_c'           => round($crit_c, 2),
            'criterion_d_bonus'     => round($crit_d, 2),
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

        switch ($critType) {
            case 'A-org':
                $years = max(1, (float)($parts[2] ?? 1));
                return min(self::CAP_A, 5.0 * $years);
            case 'B-degree':
                if ($subVal >= 40)  return 40.0;
                if ($subVal >= 20)  return 20.0;
                if ($subVal >= 10)  return 10.0;
                return 0.0;
            case 'B-training':
                return $subVal >= 2 ? 2.0 : 1.0;
            case 'B-paper':
                return $subVal >= 5 ? 5.0 : 3.0;
            case 'C-award':
                if ($subVal === 0.0) return 0.0;  // national/intl = sub-rank trigger only
                if ($subVal >= 4)  return 4.0;
                if ($subVal >= 3)  return 3.0;
                if ($subVal >= 2)  return 2.0;
                return 0.0;
            case 'D-prior-academic':
            case 'D-prior-industry':
                $years = max(1, (float)($parts[2] ?? 1));
                $role  = strtolower($parts[1] ?? '');
                if ($critType === 'D-prior-academic') {
                    if (str_contains($role, 'president')) return 5.0 * $years;
                    if (str_contains($role, 'vp') || str_contains($role, 'dean') || str_contains($role, 'director')) return 4.0 * $years;
                    if (str_contains($role, 'dept') || str_contains($role, 'head')) return 3.0 * $years;
                    return 2.0 * $years;
                }
                if (str_contains($role, 'managerial') || str_contains($role, 'supervisor')) return 4.0 * $years;
                if (str_contains($role, 'technical') || str_contains($role, 'skilled')) return 3.0 * $years;
                return 2.0 * $years;
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
}
