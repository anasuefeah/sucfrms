<?php
/**
 * AutoSubRankCalculator
 * ─────────────────────────────────────────────────────────────────────────────
 * Determines the optimal doctorate / award mode automatically.
 * No manual faculty choice. System calculates based on:
 *   1. Weighted score vs. 41-point threshold
 *   2. Rank eligibility (Professor I+ cannot trigger doctorate for sub-rank)
 *   3. Historical doctorate usage (first doctorate in career only)
 *   4. Award eligibility (score >= 41 required to trigger)
 *
 * Usage (main application):
 *   $calc = new \Scoring\AutoSubRankCalculator($pdo, $application_id);
 *   $result = $calc->calculate();
 *   $calc->persist($result);          // saves to auto_sub_rank table
 *
 * Usage (pre-evaluation, no application_id):
 *   $calc = new \Scoring\AutoSubRankCalculator($pdo, 0, $user_id);
 *   $result = $calc->calculateForPreEval($weighted_score, $rank, $has_doctorate, $doctorate_details, $has_award, $award_details);
 *   $calc->persistPreEval($result);   // saves to pre_eval_auto_sub_rank
 * ─────────────────────────────────────────────────────────────────────────────
 */

namespace Scoring;

class AutoSubRankCalculator
{
    // Score threshold above which doctorate triggering is optimal
    const SCORE_THRESHOLD = 41.0;

    // Points awarded when doctorate is used in points_only mode
    const DOCTORATE_POINTS = 40.0;

    // Ranks that are NOT eligible for doctorate-based sub-rank trigger
    // (Professor I and above — doctorate only contributes 40 pts for them)
    const INELIGIBLE_RANK_PATTERNS = [
        '/^Professor\s+(I|II|III|IV|V|VI)$/i',
        '/^University\s+Professor/i',
    ];

    private \PDO $pdo;
    private int  $application_id;
    private int  $user_id;          // needed for historical check and pre-eval

    public function __construct(\PDO $pdo, int $application_id, int $user_id = 0)
    {
        $this->pdo            = $pdo;
        $this->application_id = $application_id;
        $this->user_id        = $user_id;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // PUBLIC: Main application calculation
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Calculate optimal auto sub-rank mode for a main application.
     *
     * @return array {
     *   doctorate_mode:    string,
     *   doctorate_points:  float,
     *   doctorate_details: string,
     *   doctorate_reason:  string,
     *   award_mode:        string,
     *   award_details:     string,
     *   award_reason:      string,
     *   calculation_reason: string,   // combined for display
     *   weighted_score_at_calc: float,
     *   rank_at_calc:      string,
     *   total_rank_increase: int,
     *   historical_usage:  array|false,
     * }
     */
    public function calculate(): array
    {
        $this->ensureRuntimeMigration();

        // Load application + faculty rank
        $stmt = $this->pdo->prepare(
            "SELECT a.weighted_score, u.user_id, u.rank
             FROM applications a
             JOIN users u ON a.user_id = u.user_id
             WHERE a.application_id = ?"
        );
        $stmt->execute([$this->application_id]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        if (!$row) {
            return $this->emptyResult();
        }

        // Store user_id for historical check
        if (!$this->user_id) {
            $this->user_id = (int)$row['user_id'];
        }

        $weighted_score = (float)$row['weighted_score'];
        $rank           = (string)($row['rank'] ?? '');

        // Detect doctorate from kra_submissions
        [$has_doctorate, $doctorate_details] = $this->detectDoctoratFromSubmissions();

        // Detect national/international award from kra_submissions
        [$has_award, $award_details] = $this->detectAwardFromSubmissions();

        return $this->computeMode(
            $weighted_score,
            $rank,
            $has_doctorate,
            $doctorate_details,
            $has_award,
            $award_details,
            false   // isPreEval = false
        );
    }

    /**
     * Calculate for pre-evaluation (caller supplies score/rank/flags directly
     * since pre-eval uses pre_eval_entries, not kra_submissions).
     */
    public function calculateForPreEval(
        float  $weighted_score,
        string $rank,
        bool   $has_doctorate,
        string $doctorate_details,
        bool   $has_award,
        string $award_details
    ): array {
        $this->ensureRuntimeMigrationPreEval();

        return $this->computeMode(
            $weighted_score,
            $rank,
            $has_doctorate,
            $doctorate_details,
            $has_award,
            $award_details,
            true   // isPreEval = true
        );
    }

    // ─────────────────────────────────────────────────────────────────────────
    // PUBLIC: Persist
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Upsert result into auto_sub_rank table (main application).
     * Preserves existing checker verification columns.
     */
    public function persist(array $result): void
    {
        $this->pdo->prepare("
            INSERT INTO auto_sub_rank
                (application_id, doctorate_mode, doctorate_points, doctorate_details,
                 award_mode, award_details,
                 calculation_reason, weighted_score_at_calc, rank_at_calc,
                 historical_usage_checked)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 1)
            ON DUPLICATE KEY UPDATE
                doctorate_mode          = VALUES(doctorate_mode),
                doctorate_points        = VALUES(doctorate_points),
                doctorate_details       = VALUES(doctorate_details),
                award_mode              = VALUES(award_mode),
                award_details           = VALUES(award_details),
                calculation_reason      = VALUES(calculation_reason),
                weighted_score_at_calc  = VALUES(weighted_score_at_calc),
                rank_at_calc            = VALUES(rank_at_calc),
                historical_usage_checked = 1
        ")->execute([
            $this->application_id,
            $result['doctorate_mode'],
            $result['doctorate_points'],
            $result['doctorate_details'],
            $result['award_mode'],
            $result['award_details'],
            $result['calculation_reason'],
            $result['weighted_score_at_calc'],
            $result['rank_at_calc'],
        ]);
    }

    /**
     * Upsert result into pre_eval_auto_sub_rank table.
     */
    public function persistPreEval(array $result): void
    {
        $this->pdo->prepare("
            INSERT INTO pre_eval_auto_sub_rank
                (user_id, doctorate_mode, doctorate_points, doctorate_details,
                 award_mode, award_details,
                 calculation_reason, weighted_score_at_calc, rank_at_calc)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE
                doctorate_mode         = VALUES(doctorate_mode),
                doctorate_points       = VALUES(doctorate_points),
                doctorate_details      = VALUES(doctorate_details),
                award_mode             = VALUES(award_mode),
                award_details          = VALUES(award_details),
                calculation_reason     = VALUES(calculation_reason),
                weighted_score_at_calc = VALUES(weighted_score_at_calc),
                rank_at_calc           = VALUES(rank_at_calc)
        ")->execute([
            $this->user_id,
            $result['doctorate_mode'],
            $result['doctorate_points'],
            $result['doctorate_details'],
            $result['award_mode'],
            $result['award_details'],
            $result['calculation_reason'],
            $result['weighted_score_at_calc'],
            $result['rank_at_calc'],
        ]);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // PUBLIC: Static helpers for reading stored results
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Load stored auto_sub_rank row for an application.
     * Returns false if no row exists.
     */
    public static function loadForApplication(\PDO $pdo, int $application_id): array|false
    {
        $stmt = $pdo->prepare("SELECT * FROM auto_sub_rank WHERE application_id = ?");
        $stmt->execute([$application_id]);
        return $stmt->fetch(\PDO::FETCH_ASSOC);
    }

    /**
     * Load stored pre_eval row for a user.
     */
    public static function loadForPreEval(\PDO $pdo, int $user_id): array|false
    {
        $stmt = $pdo->prepare("SELECT * FROM pre_eval_auto_sub_rank WHERE user_id = ?");
        $stmt->execute([$user_id]);
        return $stmt->fetch(\PDO::FETCH_ASSOC);
    }

    /**
     * Return doctorate_points to add to KRA IV score.
     * Called by kra4_scorer.php — clean replacement for the old fragile global-$pdo block.
     */
    public static function getDoctoratePoints(\PDO $pdo, int $application_id): float
    {
        $stmt = $pdo->prepare(
            "SELECT doctorate_mode, doctorate_points
             FROM auto_sub_rank
             WHERE application_id = ?"
        );
        $stmt->execute([$application_id]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        if (!$row) {
            // No record yet — default to points_only (safe fallback: add 40 pts)
            return self::DOCTORATE_POINTS;
        }

        // triggered = doctorate used for rank increase, NOT for points
        if ($row['doctorate_mode'] === 'triggered') {
            return 0.0;
        }

        // points_only or blocked_historical = add 40 points
        if (in_array($row['doctorate_mode'], ['points_only', 'blocked_historical'])) {
            return (float)($row['doctorate_points'] ?: self::DOCTORATE_POINTS);
        }

        // not_eligible = faculty has no doctorate or rank blocks it — add 40 pts if doctorate present
        return 0.0;
    }

    /**
     * Same as getDoctoratePoints but for pre-evaluation.
     */
    public static function getDoctoratePointsPreEval(\PDO $pdo, int $user_id): float
    {
        $stmt = $pdo->prepare(
            "SELECT doctorate_mode, doctorate_points
             FROM pre_eval_auto_sub_rank
             WHERE user_id = ?"
        );
        $stmt->execute([$user_id]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        if (!$row) return self::DOCTORATE_POINTS;
        if ($row['doctorate_mode'] === 'triggered') return 0.0;
        if (in_array($row['doctorate_mode'], ['points_only', 'blocked_historical'])) {
            return (float)($row['doctorate_points'] ?: self::DOCTORATE_POINTS);
        }
        return 0.0;
    }

    /**
     * Human-readable label for a mode value.
     */
    public static function modeLabel(string $mode): string
    {
        return match($mode) {
            'triggered'          => 'TRIGGERED (+1 Rank)',
            'points_only'        => 'POINTS ONLY (40 pts)',
            'not_eligible'       => 'NOT ELIGIBLE',
            'blocked_historical' => 'ALREADY USED (40 pts)',
            'insufficient_score' => 'SCORE TOO LOW',
            default              => strtoupper(str_replace('_', ' ', $mode)),
        };
    }

    /**
     * Badge colour for a mode (returns hex).
     */
    public static function modeColor(string $mode): string
    {
        return match($mode) {
            'triggered'          => '#16a34a',
            'points_only'        => '#1e4d8c',
            'blocked_historical' => '#d97706',
            'not_eligible'       => '#64748b',
            'insufficient_score' => '#dc2626',
            default              => '#64748b',
        };
    }

    /**
     * Verification badge colour.
     */
    public static function verifiedColor(string $status): string
    {
        return match($status) {
            'verified' => '#16a34a',
            'rejected' => '#dc2626',
            default    => '#d97706',
        };
    }

    // ─────────────────────────────────────────────────────────────────────────
    // PRIVATE: Core calculation
    // ─────────────────────────────────────────────────────────────────────────

    public function computeMode(
        float  $weighted_score,
        string $rank,
        bool   $has_doctorate,
        string $doctorate_details,
        bool   $has_award,
        string $award_details,
        bool   $isPreEval
    ): array {
        // ── Doctorate decision ───────────────────────────────────────────────
        $doctorate_mode    = 'not_eligible';
        $doctorate_points  = 0.0;
        $doctorate_reason  = '';
        $historical_usage  = false;

        if ($has_doctorate) {
            $rank_eligible = $this->isRankEligibleForTrigger($rank);

            if (!$rank_eligible) {
                $doctorate_mode   = 'not_eligible';
                $doctorate_points = self::DOCTORATE_POINTS;
                $doctorate_reason = "Your rank ({$rank}) is not eligible for doctorate-based sub-rank increase. "
                                  . "Doctorate contributes " . (int)self::DOCTORATE_POINTS . " points to your KRA IV score.";
            } else {
                // Check historical usage (only for real applications, not pre-eval)
                $historical_usage = $isPreEval ? false : $this->checkHistoricalUsage();

                if ($historical_usage !== false) {
                    $cycle  = htmlspecialchars($historical_usage['cycle_name'] ?? 'a previous cycle');
                    $date   = !empty($historical_usage['reviewed_at'])
                            ? date('M d, Y', strtotime($historical_usage['reviewed_at']))
                            : 'date unknown';
                    $doctorate_mode   = 'blocked_historical';
                    $doctorate_points = self::DOCTORATE_POINTS;
                    $doctorate_reason = "Your doctorate was already used for auto sub-rank in {$cycle} (approved {$date}). "
                                      . "It now contributes " . (int)self::DOCTORATE_POINTS . " points to your score instead.";
                } elseif ($weighted_score >= self::SCORE_THRESHOLD) {
                    $doctorate_mode   = 'triggered';
                    $doctorate_points = 0.0;
                    $doctorate_reason = "Your weighted score is {$weighted_score} (at or above the "
                                      . (int)self::SCORE_THRESHOLD . "-point threshold). "
                                      . "Triggering your doctorate grants +1 sub-rank — this is the optimal strategy.";
                } else {
                    $doctorate_mode   = 'points_only';
                    $doctorate_points = self::DOCTORATE_POINTS;
                    $doctorate_reason = "Your weighted score is {$weighted_score} (below the "
                                      . (int)self::SCORE_THRESHOLD . "-point threshold). "
                                      . "Using your doctorate as " . (int)self::DOCTORATE_POINTS
                                      . " points is optimal to maximise your score.";
                }
            }
        } else {
            $doctorate_reason = "No doctorate degree found in your Professional Development entries. "
                              . "Add a doctorate entry (B-degree with value ≥ 40) to enable this criterion.";
        }

        // ── Award decision ───────────────────────────────────────────────────
        $award_mode   = 'not_eligible';
        $award_reason = '';

        if ($has_award) {
            if ($weighted_score >= self::SCORE_THRESHOLD) {
                $award_mode   = 'triggered';
                $award_reason = "Your weighted score is {$weighted_score} (at or above the "
                              . (int)self::SCORE_THRESHOLD . "-point threshold). "
                              . "Your national/international award qualifies for +1 sub-rank increase.";
            } else {
                $award_mode   = 'insufficient_score';
                $award_reason = "Your weighted score is {$weighted_score} (below the "
                              . (int)self::SCORE_THRESHOLD . "-point threshold). "
                              . "A score of at least " . (int)self::SCORE_THRESHOLD
                              . " is required for an award to trigger sub-rank increase.";
            }
        } else {
            $award_reason = "No national/international award found in your KRA IV entries. "
                          . "Add an award entry (C-award with sub-value = 0) to enable this criterion.";
        }

        // ── Combined display reason ──────────────────────────────────────────
        $doc_label = $has_doctorate ? self::modeLabel($doctorate_mode) : 'N/A (no doctorate)';
        $awd_label = $has_award     ? self::modeLabel($award_mode)     : 'N/A (no award)';

        $rank_increase = 0;
        if ($doctorate_mode === 'triggered')  $rank_increase++;
        if ($award_mode     === 'triggered')  $rank_increase++;

        $calculation_reason = "Score: {$weighted_score} | Rank: {$rank} | "
                            . "Doctorate: {$doc_label} | Award: {$awd_label}";

        return [
            'doctorate_mode'        => $doctorate_mode,
            'doctorate_points'      => $doctorate_points,
            'doctorate_details'     => $doctorate_details,
            'doctorate_reason'      => $doctorate_reason,
            'award_mode'            => $award_mode,
            'award_details'         => $award_details,
            'award_reason'          => $award_reason,
            'calculation_reason'    => $calculation_reason,
            'weighted_score_at_calc'=> $weighted_score,
            'rank_at_calc'          => $rank,
            'total_rank_increase'   => $rank_increase,
            'historical_usage'      => $historical_usage,
            'has_doctorate'         => $has_doctorate,
            'has_award'             => $has_award,
        ];
    }

    // ─────────────────────────────────────────────────────────────────────────
    // PRIVATE: Detection helpers
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Detect doctorate from kra_submissions for the current application.
     * Returns [bool $found, string $details].
     */
    public function detectDoctoratFromSubmissions(): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT remarks FROM kra_submissions
             WHERE application_id = ?
               AND kra_category = 'Professional Development'
             ORDER BY submission_id ASC"
        );
        $stmt->execute([$this->application_id]);

        foreach ($stmt->fetchAll(\PDO::FETCH_COLUMN) as $remarks) {
            $parts   = array_map('trim', explode('|||', $remarks));
            $crit    = $parts[0] ?? '';
            $subVal  = (float)($parts[2] ?? 0);
            $desc    = $parts[1] ?? '';

            // B-degree with value >= 40 = doctorate
            if ($crit === 'B-degree' && $subVal >= 40.0) {
                return [true, $desc ?: 'Doctorate degree (from Professional Development)'];
            }
            // Legacy format: degree value in parts[1]
            if ($crit === '' || !in_array($crit, ['A-org','B-degree','B-training','B-paper','C-award','D-prior-academic','D-prior-industry'])) {
                $legacyVal = (float)($parts[1] ?? 0);
                if ($legacyVal >= 40.0) {
                    return [true, $parts[0] ?: 'Doctorate degree (legacy entry)'];
                }
            }
        }

        return [false, ''];
    }

    /**
     * Detect national/international award from kra_submissions.
     * C-award with sub-value = 0 = national/international trigger.
     */
    public function detectAwardFromSubmissions(): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT remarks FROM kra_submissions
             WHERE application_id = ?
               AND kra_category = 'Professional Development'
             ORDER BY submission_id ASC"
        );
        $stmt->execute([$this->application_id]);

        foreach ($stmt->fetchAll(\PDO::FETCH_COLUMN) as $remarks) {
            $parts  = array_map('trim', explode('|||', $remarks));
            $crit   = $parts[0] ?? '';
            $desc   = $parts[1] ?? '';
            $subVal = (float)($parts[2] ?? -1);

            if ($crit === 'C-award' && $subVal === 0.0) {
                return [true, $desc ?: 'National/International Award'];
            }
        }

        return [false, ''];
    }

    // ─────────────────────────────────────────────────────────────────────────
    // PRIVATE: Rank eligibility
    // ─────────────────────────────────────────────────────────────────────────

    private function isRankEligibleForTrigger(string $rank): bool
    {
        foreach (self::INELIGIBLE_RANK_PATTERNS as $pattern) {
            if (preg_match($pattern, trim($rank))) {
                return false;
            }
        }
        return true;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // PRIVATE: Historical doctorate usage check
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Check if this faculty already triggered doctorate in any prior APPROVED application.
     * Returns false if never used, or array with usage details if found.
     */
    private function checkHistoricalUsage(): array|false
    {
        if (!$this->user_id) return false;

        $stmt = $this->pdo->prepare("
            SELECT a.application_id, c.cycle_name, a.reviewed_at
            FROM applications a
            JOIN auto_sub_rank asr ON a.application_id = asr.application_id
            LEFT JOIN cycles c ON a.cycle_id = c.cycle_id
            WHERE a.user_id   = ?
              AND a.status    IN ('approved', 'reclassified')
              AND asr.doctorate_mode = 'triggered'
              AND a.application_id  != ?
            ORDER BY a.reviewed_at DESC
            LIMIT 1
        ");
        $stmt->execute([$this->user_id, $this->application_id]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        return $row ?: false;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // PRIVATE: Runtime migrations (idempotent — safe to run on every page load)
    // ─────────────────────────────────────────────────────────────────────────

    private function ensureRuntimeMigration(): void
    {
        // Add doctorate_mode column if missing (handles fresh installs)
        try {
            $this->pdo->query("SELECT doctorate_mode FROM auto_sub_rank LIMIT 1");
        } catch (\Exception $e) {
            try {
                $this->pdo->exec("
                    ALTER TABLE auto_sub_rank
                        ADD COLUMN doctorate_mode
                            ENUM('triggered','points_only','not_eligible','blocked_historical')
                            NOT NULL DEFAULT 'not_eligible' AFTER application_id,
                        ADD COLUMN doctorate_points DECIMAL(5,2) NOT NULL DEFAULT 0
                            AFTER doctorate_mode,
                        ADD COLUMN doctorate_verified
                            ENUM('pending','verified','rejected') NOT NULL DEFAULT 'pending'
                            AFTER doctorate_details,
                        ADD COLUMN doctorate_verified_by INT DEFAULT NULL
                            AFTER doctorate_verified,
                        ADD COLUMN doctorate_verified_at TIMESTAMP NULL DEFAULT NULL
                            AFTER doctorate_verified_by,
                        ADD COLUMN doctorate_verification_notes TEXT DEFAULT NULL
                            AFTER doctorate_verified_at,
                        ADD COLUMN award_mode
                            ENUM('triggered','not_eligible','insufficient_score')
                            NOT NULL DEFAULT 'not_eligible' AFTER award_details,
                        ADD COLUMN award_verified
                            ENUM('pending','verified','rejected') NOT NULL DEFAULT 'pending'
                            AFTER award_mode,
                        ADD COLUMN award_verified_by INT DEFAULT NULL
                            AFTER award_verified,
                        ADD COLUMN award_verified_at TIMESTAMP NULL DEFAULT NULL
                            AFTER award_verified_by,
                        ADD COLUMN award_verification_notes TEXT DEFAULT NULL
                            AFTER award_verified_at,
                        ADD COLUMN calculation_reason TEXT DEFAULT NULL,
                        ADD COLUMN weighted_score_at_calc DECIMAL(7,2) DEFAULT NULL,
                        ADD COLUMN rank_at_calc VARCHAR(100) DEFAULT NULL,
                        ADD COLUMN historical_usage_checked TINYINT(1) NOT NULL DEFAULT 0
                ");
            } catch (\Exception $e2) {
                // Column-by-column fallback for partial migrations
                $cols = ['doctorate_mode','doctorate_points','doctorate_verified',
                         'doctorate_verified_by','doctorate_verified_at',
                         'doctorate_verification_notes','award_mode',
                         'award_verified','award_verified_by','award_verified_at',
                         'award_verification_notes','calculation_reason',
                         'weighted_score_at_calc','rank_at_calc','historical_usage_checked'];
                foreach ($cols as $col) {
                    try {
                        $this->pdo->query("SELECT {$col} FROM auto_sub_rank LIMIT 1");
                    } catch (\Exception $ex) {
                        $defs = [
                            'doctorate_mode'               => "ENUM('triggered','points_only','not_eligible','blocked_historical') NOT NULL DEFAULT 'not_eligible'",
                            'doctorate_points'             => "DECIMAL(5,2) NOT NULL DEFAULT 0",
                            'doctorate_verified'           => "ENUM('pending','verified','rejected') NOT NULL DEFAULT 'pending'",
                            'doctorate_verified_by'        => "INT DEFAULT NULL",
                            'doctorate_verified_at'        => "TIMESTAMP NULL DEFAULT NULL",
                            'doctorate_verification_notes' => "TEXT DEFAULT NULL",
                            'award_mode'                   => "ENUM('triggered','not_eligible','insufficient_score') NOT NULL DEFAULT 'not_eligible'",
                            'award_verified'               => "ENUM('pending','verified','rejected') NOT NULL DEFAULT 'pending'",
                            'award_verified_by'            => "INT DEFAULT NULL",
                            'award_verified_at'            => "TIMESTAMP NULL DEFAULT NULL",
                            'award_verification_notes'     => "TEXT DEFAULT NULL",
                            'calculation_reason'           => "TEXT DEFAULT NULL",
                            'weighted_score_at_calc'       => "DECIMAL(7,2) DEFAULT NULL",
                            'rank_at_calc'                 => "VARCHAR(100) DEFAULT NULL",
                            'historical_usage_checked'     => "TINYINT(1) NOT NULL DEFAULT 0",
                        ];
                        if (isset($defs[$col])) {
                            try {
                                $this->pdo->exec("ALTER TABLE auto_sub_rank ADD COLUMN {$col} {$defs[$col]}");
                            } catch (\Exception $ex2) {}
                        }
                    }
                }
            }
        }
    }

    private function ensureRuntimeMigrationPreEval(): void
    {
        try {
            $this->pdo->query("SELECT doctorate_mode FROM pre_eval_auto_sub_rank LIMIT 1");
        } catch (\Exception $e) {
            try {
                $this->pdo->exec("
                    ALTER TABLE pre_eval_auto_sub_rank
                        ADD COLUMN doctorate_mode
                            ENUM('triggered','points_only','not_eligible','blocked_historical')
                            NOT NULL DEFAULT 'not_eligible' AFTER user_id,
                        ADD COLUMN doctorate_points DECIMAL(5,2) NOT NULL DEFAULT 0
                            AFTER doctorate_mode,
                        ADD COLUMN award_mode
                            ENUM('triggered','not_eligible','insufficient_score')
                            NOT NULL DEFAULT 'not_eligible' AFTER award_details,
                        ADD COLUMN calculation_reason TEXT DEFAULT NULL,
                        ADD COLUMN weighted_score_at_calc DECIMAL(7,2) DEFAULT NULL,
                        ADD COLUMN rank_at_calc VARCHAR(100) DEFAULT NULL
                ");
            } catch (\Exception $e2) {
                // Per-column fallback
                $defs = [
                    'doctorate_mode'       => "ENUM('triggered','points_only','not_eligible','blocked_historical') NOT NULL DEFAULT 'not_eligible'",
                    'doctorate_points'     => "DECIMAL(5,2) NOT NULL DEFAULT 0",
                    'award_mode'           => "ENUM('triggered','not_eligible','insufficient_score') NOT NULL DEFAULT 'not_eligible'",
                    'calculation_reason'   => "TEXT DEFAULT NULL",
                    'weighted_score_at_calc' => "DECIMAL(7,2) DEFAULT NULL",
                    'rank_at_calc'         => "VARCHAR(100) DEFAULT NULL",
                ];
                foreach ($defs as $col => $def) {
                    try {
                        $this->pdo->query("SELECT {$col} FROM pre_eval_auto_sub_rank LIMIT 1");
                    } catch (\Exception $ex) {
                        try {
                            $this->pdo->exec("ALTER TABLE pre_eval_auto_sub_rank ADD COLUMN {$col} {$def}");
                        } catch (\Exception $ex2) {}
                    }
                }
            }
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // PRIVATE: Empty result template
    // ─────────────────────────────────────────────────────────────────────────

    private function emptyResult(): array
    {
        return [
            'doctorate_mode'         => 'not_eligible',
            'doctorate_points'       => 0.0,
            'doctorate_details'      => '',
            'doctorate_reason'       => 'Application not found.',
            'award_mode'             => 'not_eligible',
            'award_details'          => '',
            'award_reason'           => 'Application not found.',
            'calculation_reason'     => 'Application not found.',
            'weighted_score_at_calc' => 0.0,
            'rank_at_calc'           => '',
            'total_rank_increase'    => 0,
            'historical_usage'       => false,
            'has_doctorate'          => false,
            'has_award'              => false,
        ];
    }
}

/**
 * AutoSubrank — static compatibility facade for Orchestrator::run().
 * ─────────────────────────────────────────────────────────────────────────────
 * Orchestrator::run() calls AutoSubrank::professorGate(), ::compute(), and
 * ::routeCommittee(), none of which previously existed anywhere in this
 * codebase (only the differently-named AutoSubRankCalculator class did) —
 * every call to Orchestrator::run() therefore fatal-errored before this fix.
 *
 * ::compute() deliberately does NOT re-read applications.weighted_score from
 * the database (unlike AutoSubRankCalculator::calculate()), because at the
 * point the orchestrator calls this, the freshly-computed in-memory score
 * has not been persisted yet — reading the DB here would use a stale value
 * from the previous run. Instead it reuses AutoSubRankCalculator's detection
 * and computeMode() logic directly against the score the orchestrator just
 * computed.
 */
class AutoSubrank
{
    /**
     * Professor gate (JC01 s.2026): Professor-tier and above must have a
     * CHED-certified doctorate AND an indexed journal article published
     * within the last 3 years to remain eligible for this cycle's
     * accreditation interview. Ranks below Professor are exempt.
     */
    public static function professorGate(\PDO $pdo, array $app, array $subs_by_cat): array
    {
        $rank = $app['current_rank'] ?? $app['position_title'] ?? '';
        $applies = (bool)preg_match(
            '/^(Professor\s+(I|II|III|IV|V|VI)\b|(College|University)\s+Professor)/i',
            $rank
        );

        if (!$applies) {
            return [
                'applies'                  => false,
                'doctorate_verified'       => true,
                'indexed_article_verified' => true,
                'disqualified'             => false,
            ];
        }

        $application_id = (int)($app['application_id'] ?? 0);
        $calc = new AutoSubRankCalculator($pdo, $application_id, (int)($app['faculty_user_id'] ?? 0));
        [$has_doctorate, ] = $calc->detectDoctoratFromSubmissions();

        // Indexed journal article within the last 3 years (KRA II Criterion A).
        $indexed_verified = false;
        $cutoff = strtotime('-3 years');
        foreach (($subs_by_cat['Research'] ?? []) as $s) {
            $parts = array_map('trim', explode('|||', $s['remarks'] ?? ''));
            $crit  = $parts[0] ?? '';
            if (in_array($crit, ['journal_indexed_sole', 'journal_indexed_co'], true)) {
                $ts = strtotime($s['submitted_at'] ?? '');
                if ($ts !== false && $ts >= $cutoff) { $indexed_verified = true; break; }
            }
        }

        return [
            'applies'                  => true,
            'doctorate_verified'       => $has_doctorate,
            'indexed_article_verified' => $indexed_verified,
            'disqualified'             => !($has_doctorate && $indexed_verified),
        ];
    }

    /**
     * Auto Sub Rank bonus for this run, using the FRESH weighted score the
     * orchestrator just computed (never a possibly-stale DB value). Returns
     * separate PhD/Award qualification flags (needed for the ISS PDF's two
     * "Qualified for Auto 1-Sub Rank?" lines) as well as the combined
     * bonus_increment the orchestrator adds on top of the score-based
     * reclassification.
     */
    public static function compute(\PDO $pdo, array $app, array $kra4, float $weighted_score, int $sub_rank_increment): array
    {
        $rank           = $app['current_rank'] ?? $app['position_title'] ?? '';
        $application_id = (int)($app['application_id'] ?? 0);
        $calc = new AutoSubRankCalculator($pdo, $application_id, (int)($app['faculty_user_id'] ?? 0));

        [$has_doctorate, $doctorate_details] = $calc->detectDoctoratFromSubmissions();
        [$has_award, $award_details]         = $calc->detectAwardFromSubmissions();

        $result = $calc->computeMode(
            $weighted_score, $rank,
            $has_doctorate, $doctorate_details,
            $has_award, $award_details,
            false // isPreEval
        );

        $qualified_phd   = $has_doctorate && $result['doctorate_mode'] === 'triggered';
        $qualified_award = $has_award && $result['award_mode'] === 'triggered';
        $bonus_increment = ($qualified_phd ? 1 : 0) + ($qualified_award ? 1 : 0);

        return [
            'triggered'                    => $bonus_increment > 0,
            'basis'                        => trim(implode(' + ', array_filter([
                                                    $qualified_phd   ? 'Doctorate' : '',
                                                    $qualified_award ? 'Award'     : '',
                                                ]))) ?: 'None',
            'detail'                       => $result['calculation_reason'],
            'bonus_increment'              => $bonus_increment,
            'qualified_auto_subrank_phd'   => $qualified_phd,
            'qualified_auto_subrank_award' => $qualified_award,
            'doctorate_mode'               => $result['doctorate_mode'],
            'award_mode'                   => $result['award_mode'],
        ];
    }

    /**
     * Committee routing based on the applicant's final target rank, using the
     * IEC / REC / EAC / CC labels already displayed in
     * includes/checker/review_application.php.
     * NOTE: the exact rank-to-committee cutoffs are a best-effort mapping —
     * confirm against the institution's actual JC01 s.2026 committee
     * jurisdiction rules and adjust here if it differs.
     */
    public static function routeCommittee(string $target_rank): string
    {
        if (preg_match('/^(College|University)\s+Professor/i', $target_rank)) return 'CC';
        if (preg_match('/^Professor\s+(I|II|III|IV|V|VI)\b/i', $target_rank))   return 'EAC';
        if (preg_match('/^Associate Professor/i', $target_rank))                return 'REC';
        return 'IEC';
    }
}
