<?php
/**
 * KRA I Scorer  -  Instruction (100 points)
 * DBM-CHED Joint Circular No. 01, s. 2026
 *
 * Structure:
 *   Criterion A - Teaching Effectiveness:               max 60 pts
 *   Criterion B - Instructional Materials & Programs:   max 30 pts
 *   Criterion C - Research-Related & Mentorship:        max 10 pts
 *   KRA I total = A + B + C, hard cap 100
 *
 * Criterion A formula (CONFIRMED):
 *   SET Points = (Overall SET Rating / 100) × 36     [SET weight = 60% of 60]
 *   SEF Points = (Overall SEF Rating / 100) × 24     [SEF weight = 40% of 60]
 *   Criterion A = SET Points + SEF Points
 *
 *   "Overall SET/SEF Rating" = average of per-semester ratings over the evaluation period.
 *   Divisor is fixed at 6 semesters (or 9 terms for trimestral SUCs).
 *   Adjustments apply for: newly appointed faculty, study leave, maternity leave,
 *   sabbatical, and lab-school faculty (see JC01 s.2026 Section 15, item 1).
 *
 *   Instrument by term:
 *   - NBC 461 QCE (transmuted to 100-point scale): through 1st Sem AY2025–2026
 *   - FEDAF (Annex D, CMO 19 s.2025): mandatory from 2nd Sem AY2025–2026 onward
 */

namespace Scoring;

require_once __DIR__ . '/../criteria_hierarchy.php';
require_once __DIR__ . '/entry_metadata.php';

class KRA1Scorer
{
    const CAP = 100;

    // Criterion sub-caps
    const CAP_A = 60;
    const CAP_B = 30;
    const CAP_C = 10;
    const DEFAULT_A_SET_CAP = 36.0;
    const DEFAULT_A_SEF_CAP = 24.0;

    // IM base point values (sole-author, confirmed by evidence matrix annex)
    const IM_POINTS = [
        'textbook'    => 30,
        'module'      => 16,
        'manual'      => 16,
        'multimedia'  => 16,
        'chapter'     => null,   // not in evidence matrix -> CONFIG_INCOMPLETE
        'testing'     => null,   // not in evidence matrix -> CONFIG_INCOMPLETE
        'program_lead'  => 10,
        'program_contrib' => 5,
    ];

    // Adviser/panel point values per JC01
    const THESIS_POINTS = [
        'adviser_special'   => 3,
        'adviser_undergrad' => 5,
        'adviser_masters'   => 8,
        'adviser_doctoral'  => 10,
        'panel_special'     => 1,
        'panel_undergrad'   => 1,   // DB seed: kra1_c_panel_undergrad = 1 pt
        'panel_masters'     => 4,   // corrected from old seed (was 2)
        'panel_doctoral'    => 6,   // corrected from old seed (was 2)
    ];

    public static function score(array $submissions, int $setDivisor = 6, int $sefDivisor = 6): array
    {
        $additionalGroups = [];
        $crit_a   = 0.0;
        $crit_b   = 0.0;
        $crit_c   = 0.0;
        $crit_a_set_ratings = [];
        $crit_a_sef_ratings = [];
        $overallRatings = [];
        $pending  = [];
        $config_i = [];

        foreach ($submissions as $s) {
            $parts    = array_map('trim', explode('|||', $s['remarks'] ?? ''));
            $critType = $parts[0] ?? '';
            $pts      = 0.0;

            if ($critType === 'CUSTOM') {
                $letter = strtoupper($parts[1] ?? '');
                $pts = EntryMetadata::reviewedPoints($s, max(0.0, (float)($parts[4] ?? 0)));
                if (!in_array($letter, ['A', 'B', 'C'], true) && \collectAdditionalParentScore($additionalGroups, $parts, $pts)) continue;
                if ($letter === 'A') $crit_a += $pts;
                elseif ($letter === 'B') $crit_b += $pts;
                else $crit_c += $pts;
                continue;
            }

            if (str_starts_with($critType, 'B|')) {
                $flat    = explode('|', $critType);
                $rawPts  = $flat[1] ?? '';
                $label   = implode('|', array_slice($flat, 2));
                $contrib = min(100, max(1, (float)($parts[2] ?? 100)));
                $base    = self::configuredPointsForLabel($label) ?? (float)str_replace('co', '', $rawPts);
                $pts     = round(str_ends_with($rawPts, 'co') ? $base * ($contrib / 100) : $base, 2);
                $crit_b += EntryMetadata::reviewedPoints($s, $pts);
                if (empty($s['evidence_names'])) {
                    $pending[] = "KRA I Crit B: '{$label}' - no evidence files attached.";
                }
                continue;
            }

            if (str_starts_with($critType, 'C|')) {
                $flat  = explode('|', $critType);
                $label = implode('|', array_slice($flat, 2));
                if (self::isMentorship($parts)) {
                    $pts = self::mentorshipClaimPoints($parts[2] ?? '', $parts[3] ?? '');
                    if ($pts > 0) $crit_c += EntryMetadata::reviewedPoints($s, $pts);
                    if ($pts <= 0 || empty($s['evidence_names'])) {
                        $pending[] = 'Competition mentorship requires a qualifying competition level, placement, and supporting evidence.';
                    }
                    continue;
                }
                $pts   = self::configuredPointsForLabel($label) ?? (float)($flat[1] ?? 0);
                $crit_c += EntryMetadata::reviewedPoints($s, $pts);
                if (empty($s['evidence_names'])) {
                    $pending[] = "KRA I Crit C: '{$label}' - missing approval sheet / RRPA / COPC evidence.";
                }
                continue;
            }

            switch ($critType) {
                // -- Criterion A: Teaching Effectiveness -------------
                case 'A-set-sef':
                    $set = min(100, max(0, (float)($parts[1] ?? 0)));
                    $sef = min(100, max(0, (float)($parts[2] ?? 0)));
                    // Formula: SET/100 x configured SET cap + SEF/100 x configured SEF cap
                    $overallRatings[] = [$set, $sef, $s];

                    if ($set === 0.0 && $sef === 0.0) {
                        $pending[] = 'KRA I Crit A: Student Evaluation of Teaching and Supervisor Evaluation Form ratings are both 0  -  pending documentation.';
                    }
                    break;

                // -- Combined semester record ------------------------
                case 'A-set-sef-sem':
                    $set = min(100, max(0, (float)($parts[3] ?? 0)));
                    $sef = min(100, max(0, (float)($parts[4] ?? 0)));
                    $calculated = self::computeCriterionAScore($set, $sef);
                    $factor = $calculated > 0 ? EntryMetadata::reviewedPoints($s, $calculated) / $calculated : 1.0;
                    if (is_numeric($parts[3] ?? null)) $crit_a_set_ratings[] = $set * $factor;
                    if (is_numeric($parts[4] ?? null)) $crit_a_sef_ratings[] = $sef * $factor;

                    if ($set === 0.0 && $sef === 0.0) {
                        $period = $parts[1] ?? 'Unknown period';
                        $sem    = $parts[2] ?? '?';
                        $pending[] = "KRA I Crit A: {$period} semester {$sem} Student Evaluation of Teaching and Supervisor Evaluation Form ratings are both 0 - pending documentation.";
                    }
                    break;

                case 'A-set-sem':
                    $setRaw = $parts[3] ?? '';
                    $set = min(100, max(0, (float)$setRaw));
                    $setCap = self::getCriterionACaps()['set'];
                    if ($setRaw !== '' && is_numeric($setRaw) && $setCap > 0) {
                        $crit_a_set_ratings[] = min(100, 100 * EntryMetadata::reviewedPoints($s, $set * $setCap / 100) / $setCap);
                    }
                    if ($set === 0.0) {
                        $period = $parts[1] ?? 'Unknown period';
                        $sem    = $parts[2] ?? '?';
                        $pending[] = "KRA I Crit A: {$period} semester {$sem} Student Evaluation of Teaching rating is 0 - pending documentation.";
                    }
                    break;

                case 'A-sef-sem':
                    $sefRaw = $parts[3] ?? '';
                    $sef = min(100, max(0, (float)$sefRaw));
                    $sefCap = self::getCriterionACaps()['sef'];
                    if ($sefRaw !== '' && is_numeric($sefRaw) && $sefCap > 0) {
                        $crit_a_sef_ratings[] = min(100, 100 * EntryMetadata::reviewedPoints($s, $sef * $sefCap / 100) / $sefCap);
                    }
                    if ($sef === 0.0) {
                        $period = $parts[1] ?? 'Unknown period';
                        $sem    = $parts[2] ?? '?';
                        $pending[] = "KRA I Crit A: {$period} semester {$sem} Supervisor Evaluation Form rating is 0 - pending documentation.";
                    }
                    break;

                case 'B-material':
                    $label   = $parts[1] ?? '';
                    $contrib = min(100, max(1, (float)($parts[2] ?? 100)));
                    $base    = self::resolveIMBase($label, $config_i);
                    if ($base !== null) {
                        $isCo = self::isCoAuthor($label);
                        $pts  = round($isCo ? $base * ($contrib / 100) : (float)$base, 2);
                        if ($isCo && empty($parts[2])) {
                            $pending[] = "KRA I Crit B: Co-author entry '{$label}' missing Annex C (Certificate of Contribution Form_IM).";
                        }
                    $crit_b += EntryMetadata::reviewedPoints($s, $pts);
                    }
                    // Evidence baseline check
                    if (empty($s['evidence_names'])) {
                        $pending[] = "KRA I Crit B: '{$label}'  -  no evidence files attached (required: cover page, ToC, IMDC evaluation, syllabus listing, library certification).";
                    }
                    break;

                // -- Criterion C: Research-Related & Mentorship -------
                case 'C-thesis':
                    $label = $parts[1] ?? '';
                    $pts   = self::resolveThesisPoints($label, $config_i);
                    $crit_c += EntryMetadata::reviewedPoints($s, $pts);
                    if (empty($s['evidence_names'])) {
                        $pending[] = "KRA I Crit C: '{$label}'  -  missing approval sheet / RRPA / COPC evidence.";
                    }
                    break;

                case 'C-mentor':
                    $pts = self::mentorshipClaimPoints($parts[2] ?? '', $parts[3] ?? '');
                    if ($pts > 0) $crit_c += EntryMetadata::reviewedPoints($s, $pts);
                    if ($pts <= 0 || empty($s['evidence_names'])) {
                        $pending[] = 'Competition mentorship requires a qualifying competition level, placement, and supporting evidence.';
                    }
                    break;

                default:
                    // Legacy SET/SEF format without critType prefix
                    if (is_numeric($parts[0]) || is_numeric($parts[1] ?? '')) {
                        $set = min(100, max(0, (float)($parts[0] ?? 0)));
                        $sef = min(100, max(0, (float)($parts[1] ?? 0)));
                        if ($set > 0) $crit_a_set_ratings[] = $set;
                        if ($sef > 0) $crit_a_sef_ratings[] = $sef;
                    }
                    break;
            }
        }

        $avg_set = array_sum($crit_a_set_ratings) / max(1, $setDivisor, count($crit_a_set_ratings));
        $avg_sef = array_sum($crit_a_sef_ratings) / max(1, $sefDivisor, count($crit_a_sef_ratings));
        $setPoints = self::computeCriterionASetScore($avg_set);
        $sefPoints = self::computeCriterionASefScore($avg_sef);
        if ($overallRatings && !$crit_a_set_ratings && !$crit_a_sef_ratings) {
            $setPoints = self::computeCriterionASetScore(array_sum(array_column($overallRatings, 0)) / count($overallRatings));
            $sefPoints = self::computeCriterionASefScore(array_sum(array_column($overallRatings, 1)) / count($overallRatings));
            if (count($overallRatings) === 1 && $setPoints + $sefPoints > 0) {
                $factor = EntryMetadata::reviewedPoints($overallRatings[0][2], $setPoints + $sefPoints) / ($setPoints + $sefPoints);
                $setPoints *= $factor;
                $sefPoints *= $factor;
            }
        }
        $crit_a += $setPoints + $sefPoints;

        // Apply sub-caps
        $limits = \configuredParentLimits(self::$pdo_ref, self::$criteria_cycle_id, self::$criteria_position_rank, 'Instruction');
        $crit_a = min($limits['A'] ?? self::CAP_A, $crit_a);
        $crit_b = min($limits['B'] ?? self::CAP_B, $crit_b);
        $crit_c = min($limits['C'] ?? self::CAP_C, $crit_c);

        $additionalParents = \additionalParentTotals($additionalGroups);
        $subtotal = min(self::CAP, $crit_a + $crit_b + $crit_c + array_sum($additionalParents));

        return [
            'parent_scores' => ['A'=>$crit_a, 'B'=>$crit_b, 'C'=>$crit_c] + $additionalParents,
            'criterion_a'           => $crit_a,
            'criterion_a_set'       => $setPoints,
            'criterion_a_sef'       => $sefPoints,
            'criterion_b'           => $crit_b,
            'criterion_c'           => $crit_c,
            'subtotal'              => $subtotal,
            'cap'                   => self::CAP,
            'pending_documentation' => $pending,
            'config_incomplete'     => $config_i,
        ];
    }

    // -- Resolve IM base points from label ----------------------------
    private static function resolveIMBase(string $label, array &$config_i): ?float
    {
        $l = strtolower($label);
        $configured = self::configuredPointsForLabel($label);
        if ($configured !== null) return $configured;
        if (str_contains($l, 'textbook') && !str_contains($l, 'chapter')) return 30.0;
        if (str_contains($l, 'module') || str_contains($l, 'manual'))     return 16.0;
        if (str_contains($l, 'multimedia'))                                return 16.0;
        if (str_contains($l, 'program') && str_contains($l, 'lead'))      return 10.0;
        if (str_contains($l, 'program') && str_contains($l, 'contributor')) return 5.0;
        if (str_contains($l, 'program') && str_contains($l, 'contrib'))   return 5.0;
        // Textbook chapter and validated testing materials: checklist-only items
        // with no confirmed point figure in the evidence-matrix annex.
        if (str_contains($l, 'chapter') || str_contains($l, 'testing') || str_contains($l, 'validated')) {
            $config_i[] = "KRA I Crit B: '{$label}'  -  item appears in checklist annex only, no confirmed point value in evidence-matrix annex. Flag as CONFIG_INCOMPLETE.";
            return null;
        }
        // Try extracting pts from label pattern "(Xpts)"
        if (preg_match('/\((\d+(?:\.\d+)?)\s*pts?\)/i', $label, $m)) {
            return (float)$m[1];
        }
        $config_i[] = "KRA I Crit B: '{$label}'  -  unrecognised IM type. Flag as CONFIG_INCOMPLETE.";
        return null;
    }

    // -- Detect co-authorship from label -----------------------------
    private static function isCoAuthor(string $label): bool
    {
        $l = strtolower($label);
        return str_contains($l, 'co-author') || str_contains($l, 'co author')
            || str_contains($l, 'co-') || str_contains($l, 'coauthor');
    }

    // -- Resolve thesis/panel points ---------------------------------
    private static function resolveThesisPoints(string $label, array &$config_i): float
    {
        $l = strtolower($label);
        $configured = self::configuredPointsForLabel($label);
        if ($configured !== null) return $configured;
        // Adviser
        if (str_contains($l, 'adviser') || str_contains($l, 'advisor')) {
            if (str_contains($l, 'doctoral') || str_contains($l, 'dissertation')) return 10.0;
            if (str_contains($l, "master"))                                        return 8.0;
            if (str_contains($l, 'undergraduate') || str_contains($l, 'undergrad')) return 5.0;
            if (str_contains($l, 'special') || str_contains($l, 'capstone'))      return 3.0;
        }
        // Panel member
        if (str_contains($l, 'panel')) {
            if (str_contains($l, 'doctoral') || str_contains($l, 'dissertation')) return 6.0;
            if (str_contains($l, "master"))                                        return 4.0;
            if (str_contains($l, 'undergraduate') || str_contains($l, 'undergrad')) return 2.0;
            if (str_contains($l, 'special') || str_contains($l, 'capstone'))      return 1.0;
        }
        $config_i[] = "KRA I Crit C: Unrecognised thesis/panel entry '{$label}'  -  CONFIG_INCOMPLETE.";
        return 0.0;
    }

    /**
     * Compute per-entry score from a remarks string (for kra_ajax.php compatibility).
     * This is the canonical per-entry scorer called by kra_ajax.php and kra_entry.php.
     */
    public static function computeFromRemarks(string $remarks): float
    {
        $dummy_config = [];
        $parts        = array_map('trim', explode('|||', $remarks));
        $critType     = $parts[0] ?? '';

        if ($critType === 'CUSTOM') {
            return max(0.0, (float)($parts[4] ?? 0));
        }

        if (str_starts_with($critType, 'B|')) {
            $flat    = explode('|', $critType);
            $rawPts  = $flat[1] ?? '';
            $label   = implode('|', array_slice($flat, 2));
            $base    = self::configuredPointsForLabel($label) ?? (float)str_replace('co', '', $rawPts);
            $contrib = min(100, max(1, (float)($parts[2] ?? 100)));
            return round(str_ends_with($rawPts, 'co') ? $base * ($contrib / 100) : $base, 2);
        }

        if (str_starts_with($critType, 'C|')) {
            if (self::isMentorship($parts)) return self::mentorshipClaimPoints($parts[2] ?? '', $parts[3] ?? '');
            $flat = explode('|', $critType);
            $label = implode('|', array_slice($flat, 2));
            return self::configuredPointsForLabel($label) ?? (float)($flat[1] ?? 0);
        }

        switch ($critType) {
            case 'A-set-sef':
                $set = min(100, max(0, (float)($parts[1] ?? 0)));
                $sef = min(100, max(0, (float)($parts[2] ?? 0)));
                return self::computeCriterionAScore($set, $sef);

            case 'A-set-sef-sem':
                $set = min(100, max(0, (float)($parts[3] ?? 0)));
                $sef = min(100, max(0, (float)($parts[4] ?? 0)));
                return self::computeCriterionAScore($set, $sef);

            case 'A-set-sem':
                $set = min(100, max(0, (float)($parts[3] ?? 0)));
                return self::computeCriterionASetScore($set);

            case 'A-sef-sem':
                $sef = min(100, max(0, (float)($parts[3] ?? 0)));
                return self::computeCriterionASefScore($sef);

            case 'B-material':
                if (str_starts_with($critType, 'B|')) {
                    $flat    = explode('|', $critType);
                    $rawPts  = $flat[1] ?? '';
                    $base    = (float)str_replace('co', '', $rawPts);
                    $contrib = min(100, max(1, (float)($parts[2] ?? 100)));
                    return round(str_ends_with($rawPts, 'co') ? $base * ($contrib / 100) : $base, 2);
                }
                $label   = $parts[1] ?? '';
                $contrib = min(100, max(1, (float)($parts[2] ?? 100)));
                $base    = self::resolveIMBase($label, $dummy_config);
                if ($base === null) return 0.0;
                return round(self::isCoAuthor($label) ? $base * ($contrib / 100) : (float)$base, 2);

            case 'C-thesis':
                if (str_starts_with($critType, 'C|')) {
                    $flat = explode('|', $critType);
                    return (float)($flat[1] ?? 0);
                }
                return self::resolveThesisPoints($parts[1] ?? '', $dummy_config);

            case 'C-mentor':
                return self::mentorshipClaimPoints($parts[2] ?? '', $parts[3] ?? '');

            default:
                // Legacy SET/SEF
                $set = min(100, max(0, (float)($parts[0] ?? 0)));
                $sef = min(100, max(0, (float)($parts[1] ?? 0)));
                if ($set > 0 || $sef > 0) return self::computeCriterionAScore($set, $sef);
                return 0.0;
        }
    }

    public static function getCriterionACaps(): array
    {
        return self::loadCriterionACaps();
    }

    public static function computeCriterionASetScore(float $rating): float
    {
        $caps = self::loadCriterionACaps();
        return round((min(100, max(0, $rating)) / 100) * $caps['set'], 2);
    }

    public static function computeCriterionASefScore(float $rating): float
    {
        $caps = self::loadCriterionACaps();
        return round((min(100, max(0, $rating)) / 100) * $caps['sef'], 2);
    }

    /**
     * Compute the Criterion A score from overall SET and SEF ratings (both on 100-pt scale).
     *
     * Formula (JC01 s.2026, CONFIRMED):
     *   SET Points = (setRating / 100) × SET_cap    [default SET_cap = 36]
     *   SEF Points = (sefRating / 100) × SEF_cap    [default SEF_cap = 24]
     *   Criterion A = SET Points + SEF Points        [capped at 60]
     *
     * SET_cap and SEF_cap are admin-configurable via scoring_criteria keys
     * 'kra1_a_set' and 'kra1_a_sef' respectively.
     */
    public static function computeCriterionAScore(float $setRating, float $sefRating): float
    {
        $caps = self::loadCriterionACaps();
        $set = (min(100, max(0, $setRating)) / 100) * $caps['set'];
        $sef = (min(100, max(0, $sefRating)) / 100) * $caps['sef'];
        return round($set + $sef, 2);
    }

    /**
     * Read CONFIG_MENTORSHIP_POINTS from the scoring_criteria table.
     * The criterion_key 'kra1_c_mentor_competition' with a non-zero max_points
     * signals that an administrator has explicitly confirmed the point value.
     *
     * Returns null  -> not configured; triggers PENDING_DOCUMENTATION (never score 0).
     * Returns float -> admin has explicitly set the value; use it.
     *
     * Suggested starting point for discussion: 1 pt (matching Panel Member,
     * Special/Capstone  -  the lowest confirmed rate in this criterion). This is a
     * disclosed design recommendation, NOT a sourced value from JC01 p.78.
     * Confirm with CHED-RO or your adviser before finalising.
     */
    private static ?float $mentorship_pts_cache  = null;
    private static bool   $mentorship_pts_loaded = false;
    private static ?array  $criterion_a_caps_cache = null;
    private static ?array  $criteria_label_points_cache = null;
    private static ?\PDO  $pdo_ref               = null;
    private static ?int    $criteria_cycle_id    = null;
    private static ?string $criteria_position_rank = null;

    /** Called by the orchestrator before invoking score() so we can reach the DB. */
    public static function setPdo(\PDO $pdo): void
    {
        self::$pdo_ref               = $pdo;
        self::$mentorship_pts_loaded = false; // flush cache on new connection
        self::$criterion_a_caps_cache = null;
        self::$criteria_label_points_cache = null;
    }

    public static function setCriteriaContext(?int $cycleId, ?string $positionRank): void
    {
        self::$criteria_cycle_id = $cycleId ?: null;
        self::$criteria_position_rank = $positionRank !== null && trim($positionRank) !== '' ? trim($positionRank) : null;
        self::$criterion_a_caps_cache = null;
        self::$criteria_label_points_cache = null;
        self::$mentorship_pts_loaded = false;
    }

    private static function configuredPointsForLabel(string $label): ?float
    {
        $points = self::criteriaLabelPoints();
        if (!$points) return null;
        $key = self::normaliseCriterionLabel($label);
        return $points[$key] ?? null;
    }

    private static function criteriaLabelPoints(): array
    {
        if (self::$criteria_label_points_cache !== null) return self::$criteria_label_points_cache;
        self::$criteria_label_points_cache = [];
        if (self::$pdo_ref === null) return self::$criteria_label_points_cache;

        try {
            $stmt = self::$pdo_ref->prepare("SELECT criterion_label, max_points, cycle_id, position_rank FROM scoring_criteria WHERE kra_category='Instruction' AND is_active=1");
            $stmt->execute();
            $best = [];
            foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
                $key = self::normaliseCriterionLabel((string)$row['criterion_label']);
                $score = self::criteriaScopeScore($row);
                if ($score <= 0) continue;
                if ($key !== '' && (!isset($best[$key]) || $score > $best[$key])) {
                    self::$criteria_label_points_cache[$key] = (float)$row['max_points'];
                    $best[$key] = $score;
                }
            }
        } catch (\Exception $e) {
            self::$criteria_label_points_cache = [];
        }

        return self::$criteria_label_points_cache;
    }

    private static function normaliseCriterionLabel(string $label): string
    {
        $label = preg_replace('/\(\s*\d+(?:\.\d+)?\s*pts?\.?\s*\)/i', '', $label);
        $label = preg_replace('/^Criterion\s+[A-Z]\s*[-:]\s*/i', '', (string)$label);
        return strtolower(trim(preg_replace('/[^a-z0-9]+/i', ' ', $label)));
    }

    private static function loadCriterionACaps(): array
    {
        if (self::$criterion_a_caps_cache !== null) return self::$criterion_a_caps_cache;

        $caps = [
            'set' => self::DEFAULT_A_SET_CAP,
            'sef' => self::DEFAULT_A_SEF_CAP,
        ];

        if (self::$pdo_ref !== null) {
            try {
                $stmt = self::$pdo_ref->prepare(
                    "SELECT criterion_key, max_points, cycle_id, position_rank
                     FROM scoring_criteria
                     WHERE criterion_key IN ('kra1_a_set', 'kra1_a_sef')
                       AND is_active = 1"
                );
                $stmt->execute();
                $best = [];
                foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
                    $key = $row['criterion_key'] === 'kra1_a_set' ? 'set' : 'sef';
                    $score = self::criteriaScopeScore($row);
                if ($score <= 0) continue;
                    if ((float)$row['max_points'] >= 0 && (!isset($best[$key]) || $score > $best[$key])) {
                        $caps[$key] = (float)$row['max_points'];
                        $best[$key] = $score;
                    }
                }
            } catch (\Exception $e) {
                // Keep defaults if the criteria table is unavailable.
            }
        }

        self::$criterion_a_caps_cache = $caps;
        return $caps;
    }

    private static function criteriaScopeScore(array $row): int
    {
        $row_cycle = isset($row['cycle_id']) ? (int)$row['cycle_id'] : null;
        $row_position = isset($row['position_rank']) && $row['position_rank'] !== null ? trim((string)$row['position_rank']) : null;

        if (self::$criteria_cycle_id !== null && $row_cycle === self::$criteria_cycle_id) {
            if (self::$criteria_position_rank !== null && $row_position === self::$criteria_position_rank) {
                return 3;
            }
            if ($row_position === null || $row_position === '') {
                return 2;
            }
        }
        if (self::$criteria_cycle_id !== null) return 0;
        if ($row_cycle === null || $row_cycle === 0) {
            return 1;
        }
        return 0;
    }

    private static function isMentorship(array $parts): bool
    {
        return EntryMetadata::criterionKey($parts) === 'kra1_c_mentor_competition'
            || stripos($parts[0] ?? '', 'Mentor: Student/Team Competition Winner') !== false;
    }

    private static function mentorshipClaimPoints(string $level, string $placement): float
    {
        $eligibleLevel = in_array(strtolower(trim($level)), ['regional', 'national', 'international'], true);
        $eligiblePlacement = preg_match('/^(champion|[123]|1st|2nd|3rd|first|second|third)( place)?$/i', trim($placement));
        return $eligibleLevel && $eligiblePlacement ? (self::getMentorshipPoints() ?? 0.0) : 0.0;
    }

    private static function getMentorshipPoints(): ?float
    {
        if (self::$mentorship_pts_loaded) return self::$mentorship_pts_cache;
        self::$mentorship_pts_loaded = true;

        if (self::$pdo_ref === null) return null; // per-entry path: no DB access

        try {
            $stmt = self::$pdo_ref->prepare(
                "SELECT max_points, cycle_id, position_rank FROM scoring_criteria
                 WHERE criterion_key = 'kra1_c_mentor_competition'
                   AND is_active = 1
                   AND max_points > 0"
            );
            $stmt->execute();
            $best_score = -1;
            $best_val = null;
            foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
                $score = self::criteriaScopeScore($row);
                if ($score <= 0) continue;
                if ($score > $best_score) {
                    $best_score = $score;
                    $best_val = $row['max_points'];
                }
            }
            self::$mentorship_pts_cache = ($best_val !== false && $best_val !== null) ? (float)$best_val : null;
        } catch (\Exception $e) {
            self::$mentorship_pts_cache = null;
        }

        return self::$mentorship_pts_cache;
    }
}
