<?php
/**
 * SFRMIS Master Orchestrator — DBM-CHED Joint Circular No. 01, s. 2026
 *
 * Validates eligibility, routes to KRA scorer modules, aggregates results,
 * runs auto sub-rank / quota gate / committee routing, and persists flags.
 *
 * Usage:
 *   require_once __DIR__ . '/orchestrator.php';
 *   $result = \Scoring\Orchestrator::run($pdo, $application_id);
 */

namespace Scoring;

require_once __DIR__ . '/kra1_scorer.php';
require_once __DIR__ . '/kra2_scorer.php';
require_once __DIR__ . '/kra3_scorer.php';
require_once __DIR__ . '/kra4_scorer.php';
require_once __DIR__ . '/autosubrank.php';

class Orchestrator
{
    /**
     * Run the full scoring pipeline for one application.
     *
     * Returns the full result schema:
     * {
     *   applicant_id, evaluation_period_ok,
     *   kra_results: {kra1,kra2,kra3,kra4},
     *   grand_total, weighted_score, sub_rank_increment,
     *   auto_subrank: {triggered, basis, detail},
     *   quota_gate:   {applies, rank_requested, cohort_status},
     *   committee_route,
     *   professor_gate: {applies, doctorate_verified, indexed_article_verified, disqualified},
     *   double_counting_flags, pending_documentation, config_incomplete, notes
     * }
     */
    public static function run(\PDO $pdo, int $application_id): array
    {
        // ── Load application + faculty ───────────────────────────────
        $app = $pdo->prepare("
            SELECT a.*, u.rank AS current_rank, u.user_id AS faculty_user_id,
                   u.first_name, u.last_name, u.employee_id,
                   c.status AS cycle_status
            FROM applications a
            JOIN users u ON a.user_id = u.user_id
            LEFT JOIN cycles c ON a.cycle_id = c.cycle_id
            WHERE a.application_id = ?
        ");
        $app->execute([$application_id]);
        $app = $app->fetch(\PDO::FETCH_ASSOC);

        if (!$app) {
            return ['error' => 'Application not found', 'applicant_id' => $application_id];
        }

        $rank           = $app['position_title'] ?? $app['current_rank'] ?? '';
        $current_rank   = $app['current_rank'] ?? '';
        $faculty_id     = (int)$app['faculty_user_id'];

        // ── Load all KRA submissions ─────────────────────────────────
        $subs_stmt = $pdo->prepare("
            SELECT s.*, GROUP_CONCAT(ef.original_filename ORDER BY ef.evidence_id SEPARATOR '|||') AS evidence_names
            FROM kra_submissions s
            LEFT JOIN kra_evidence_files ef ON ef.submission_id = s.submission_id
            WHERE s.application_id = ?
            GROUP BY s.submission_id
            ORDER BY s.submitted_at ASC
        ");
        $subs_stmt->execute([$application_id]);
        $all_subs = $subs_stmt->fetchAll(\PDO::FETCH_ASSOC);

        $subs_by_cat = [];
        foreach ($all_subs as $s) {
            $subs_by_cat[$s['kra_category']][] = $s;
        }

    // ── STEP 1: Evaluation period check ──────────────────────────
    $eval_ok = self::checkEvaluationPeriod($app);

        // ── STEP 2: Double-counting check ────────────────────────────
        $double_flags = self::checkDoubleCounting($all_subs);

        // ── STEP 3: Admin duty exclusion ─────────────────────────────
        $admin_flags = self::checkAdminDutyExclusion($subs_by_cat);

        // ── STEP 4: Professor gate (hard gate — runs BEFORE scoring) ─
        $prof_gate = AutoSubrank::professorGate($pdo, $app, $subs_by_cat);

        $notes = [];
        if ($prof_gate['applies'] && $prof_gate['disqualified']) {
            $notes[] = 'PROFESSOR GATE FAILED: Faculty is disqualified from this cycle\'s accreditation interview. '
                     . 'Missing: ' . implode('; ', array_filter([
                         !$prof_gate['doctorate_verified']      ? 'CHED-certified doctorate transcript' : '',
                         !$prof_gate['indexed_article_verified'] ? 'Indexed journal article (last 3 years)' : '',
                     ]));
        }

        // ── STEP 4: Route to KRA scorers ────────────────────────────
        KRA1Scorer::setPdo($pdo);   // provides DB access for CONFIG_MENTORSHIP_POINTS lookup
        $kra1 = KRA1Scorer::score($subs_by_cat['Instruction'] ?? []);
        $kra2 = KRA2Scorer::score($subs_by_cat['Research'] ?? []);
        $kra3 = KRA3Scorer::score($subs_by_cat['Extension'] ?? []);
        $kra4 = KRA4Scorer::score($subs_by_cat['Professional Development'] ?? []);

        // ── STEP 5: Aggregate ────────────────────────────────────────
        $grand_total = $kra1['subtotal'] + $kra2['subtotal'] + $kra3['subtotal'] + $kra4['subtotal'];
        // No global cap per JC01 s.2026 (GRAND_TOTAL_CAP = null/unset)

        // ── STEP 5a: PASS 1 — weighted score using the CURRENT rank's weights ──
        // Look up the bracket, get the initial increment, apply it to get the
        // Initial Reclassified Rank. This pass never sees Auto Sub Rank.
        $weights_pass1          = self::getKraWeights($current_rank);
        $weighted_score_pass1   = round(
            ($kra1['subtotal'] * $weights_pass1['Instruction']) +
            ($kra2['subtotal'] * $weights_pass1['Research']) +
            ($kra3['subtotal'] * $weights_pass1['Extension']) +
            ($kra4['subtotal'] * $weights_pass1['Professional Development']),
            2
        );
        $sub_rank_increment_pass1 = self::getSubRankIncrement($weighted_score_pass1);
        $initial_reclassified_rank = self::computeTargetRank($current_rank, $sub_rank_increment_pass1);

        // ── STEP 5b: PASS 2 — recompute using the Initial Reclassified Rank's
        // weight row (NOT the original rank), since the weight table itself
        // changes once the rank changes. A different result vs. Pass 1 is
        // expected, not an error.
        $weights_pass2        = self::getKraWeights($initial_reclassified_rank);
        $weighted_score_pass2 = round(
            ($kra1['subtotal'] * $weights_pass2['Instruction']) +
            ($kra2['subtotal'] * $weights_pass2['Research']) +
            ($kra3['subtotal'] * $weights_pass2['Extension']) +
            ($kra4['subtotal'] * $weights_pass2['Professional Development']),
            2
        );
        $sub_rank_increment_pass2 = self::getSubRankIncrement($weighted_score_pass2);
        // FINAL Reclassified Rank uses Pass 2's increment, applied on top of
        // the Initial Reclassified Rank (not the original current rank).
        $reclassified_rank = self::computeTargetRank($initial_reclassified_rank, $sub_rank_increment_pass2);

        // ── STEP 6: Auto Sub Rank ─────────────────────────────────────
        // Score-based increment (Steps 5a/5b above) and Auto Sub Rank
        // (doctorate or prestigious award) are two SEPARATE mechanisms that
        // both apply — never alternatives, and never blended into the Pass 1
        // / Pass 2 weight lookups above. They are added together only here,
        // on top of the already-final Reclassified Rank, to get Final Rank.
        $auto_subrank = AutoSubrank::compute($pdo, $app, $kra4, $weighted_score_pass2, $sub_rank_increment_pass2);
        $auto_bump    = $auto_subrank['bonus_increment']; // +0, +1, or +2
        $final_rank   = self::computeTargetRank($reclassified_rank, $auto_bump);

        // ── Back-compat aliases for the rest of this method / DB persistence.
        // "weighted_score" / "sub_rank_increment" / "target_rank" keep meaning
        // Pass 2's fully-recomputed values and the combined final rank, so
        // existing callers/columns that read these keys are unaffected.
        $weighted_score      = $weighted_score_pass2;
        $sub_rank_increment  = $sub_rank_increment_pass2 + $auto_bump;
        $bumped_base_rank    = $reclassified_rank; // kept for the code below that still references this name

        // ── STEP 7: Self-assessment summary ─────────────────────────
        $pending_docs   = array_merge(
            $kra1['pending_documentation'],
            $kra2['pending_documentation'],
            $kra3['pending_documentation'],
            $kra4['pending_documentation']
        );
        $config_incomplete = array_merge(
            $kra1['config_incomplete'],
            $kra2['config_incomplete'],
            $kra3['config_incomplete'],
            $kra4['config_incomplete']
        );

        // ── Quota gate — EXCLUDED from CHMSU-FT pilot scope ──────────
        // Real-time quota tracking requires external HR/plantilla data not available
        // in this system. The quota gate is flagged but not enforced.
        $quota_gate = ['applies' => false, 'rank_requested' => '', 'cohort_status' => 'excluded',
                       'note' => 'Real-time quota tracking excluded from CHMSU-FT pilot scope. '
                               . 'Phase II Professorial Accreditation and IEC/REC/EAC/NCC governance '
                               . 'approvals are outside this system\'s boundary.'];

        // ── Committee routing ────────────────────────────────────────
        // target_rank = Final Rank = Reclassified Rank + Auto Sub Rank, already
        // computed above as $final_rank (Step 4 of the spec: the two mechanisms
        // are added together, not blended into the two-pass weight lookups).
        $target_rank     = $final_rank;
        $committee_route = AutoSubrank::routeCommittee($target_rank);

        // ── Persist results to DB ─────────────────────────────────────
        $workflow = self::mapWorkflowStatus($app['status'] ?? 'draft');

        self::persist($pdo, $application_id, [
            'grand_total'           => $grand_total,
            'weighted_score'        => $weighted_score,
            'sub_rank_increment'    => $sub_rank_increment,
            'potential_rank'        => $target_rank,
            'committee_route'       => $committee_route,
            'evaluation_period_ok'  => $eval_ok,
            'double_counting_flags' => json_encode($double_flags),
            'pending_documentation' => json_encode($pending_docs),
            'config_incomplete'     => json_encode($config_incomplete),
            'orchestrator_flags'    => json_encode(array_merge($admin_flags, $notes)),
        ]);

        // ── Document completeness check ──────────────────────────────
        // Presence/upload check only — no authenticity or accuracy verification.
        // Those are performed exclusively by human checkers.
        $doc_check = self::checkDocumentCompleteness($pdo, $application_id, $all_subs);

        // ── Pre-evaluation summary ────────────────────────────────────
        $pre_eval = [
            'eligible'           => $eval_ok && !($prof_gate['applies'] && $prof_gate['disqualified']),
            'documents_present'  => $doc_check['all_present'],
            'entries_with_files' => $doc_check['entries_with_files'],
            'entries_missing_files' => $doc_check['entries_missing_files'],
            'completeness_pct'   => $doc_check['completeness_pct'],
        ];

        // ── ISS structured data ───────────────────────────────────────
        $faculty_for_iss = $app;
        $faculty_for_iss['full_name']    = trim(($app['first_name'] ?? '') . ' ' . ($app['last_name'] ?? ''));
        $faculty_for_iss['current_rank'] = $current_rank;
        $faculty_for_iss['rank']         = $current_rank;
        $iss = self::buildISS($app, $faculty_for_iss, $kra1, $kra2, $kra3, $kra4, [
            'current_rank'                 => $current_rank,
            'weights_pass1'                => $weights_pass1,
            'weighted_score_pass1'         => $weighted_score_pass1,
            'sub_rank_increment_pass1'     => $sub_rank_increment_pass1,
            'initial_reclassified_rank'    => $initial_reclassified_rank,
            'weights_pass2'                => $weights_pass2,
            'weighted_score_pass2'         => $weighted_score_pass2,
            'sub_rank_increment_pass2'     => $sub_rank_increment_pass2,
            'reclassified_rank'            => $reclassified_rank,
            'qualified_auto_subrank_phd'   => $auto_subrank['qualified_auto_subrank_phd'],
            'qualified_auto_subrank_award' => $auto_subrank['qualified_auto_subrank_award'],
            'auto_subrank_bonus'           => $auto_bump,
            'final_rank'                   => $final_rank,
            // Back-compat top-level fields other callers of buildISS's output may expect.
            'weighted_score'               => $weighted_score,
            'sub_rank_increment'           => $sub_rank_increment,
        ]);

        // ── OSS row (this applicant's contribution to the OSS) ────────
        $oss_row = [
            'applicant_id'       => $application_id,
            'full_name'          => $app['first_name'] . ' ' . $app['last_name'],
            'employee_id'        => $app['employee_id'] ?? '',
            'current_rank'       => $current_rank,
            'bumped_base_rank'   => $bumped_base_rank,
            'computed_rank'      => $target_rank,
            'points_computed'    => $weighted_score,
            'sub_rank_increment' => $sub_rank_increment,
            'workflow_status'    => $workflow,
            'committee_route'    => $committee_route,
        ];

        // ── Return full schema ───────────────────────────────────────
        return [
            'applicant_id'          => $application_id,
            'evaluation_period_ok'  => $eval_ok,
            'pre_evaluation'        => $pre_eval,
            'kra_results'           => [
                'kra1' => $kra1,
                'kra2' => $kra2,
                'kra3' => $kra3,
                'kra4' => $kra4,
            ],
            'grand_total'           => $grand_total,
            'weighted_score'        => $weighted_score,
            'sub_rank_increment'    => $sub_rank_increment,
            'target_rank'           => $target_rank,
            'auto_subrank'          => array_merge($auto_subrank, [
                'updated_base_rank' => $bumped_base_rank,
            ]),
            'quota_gate'            => $quota_gate,  // excluded from pilot scope
            'committee_route'       => $committee_route,
            'professor_gate'        => $prof_gate,
            'double_counting_flags' => $double_flags,
            'pending_documentation' => $pending_docs,
            'config_incomplete'     => $config_incomplete,
            'workflow_status'       => $workflow,
            'iss'                   => $iss,
            'oss'                   => $oss_row,
            'notes'                 => array_merge($admin_flags, $notes),
            'scoring'               => [
                'points_computed'   => $weighted_score,
                'computed_rank'     => $target_rank,
            ],
        ];
    }

    // ── STEP 1: Evaluation period ────────────────────────────────────
    private static function checkEvaluationPeriod(array $app): bool
    {
        // Cycle timing is controlled entirely by the admin opening/closing the cycle.
        // An application is in-period if its cycle exists and is not archived.
        // 'open' and 'closed' are both valid (closed means submissions ended,
        // not that the evaluation is void). Only 'archived' cycles are out-of-scope.
        $cycle_status = $app['cycle_status'] ?? null;
        if ($cycle_status === null) return true;        // no cycle attached — assume ok
        return $cycle_status !== 'archived';
    }

    // ── STEP 2: Double-counting check ───────────────────────────────
    private static function checkDoubleCounting(array $all_subs): array
    {
        $flags = [];
        // Build map of (criterion_key or title) → [categories seen in]
        $title_map = [];
        foreach ($all_subs as $s) {
            $parts = explode('|||', $s['remarks'] ?? '');
            // title/description-like field varies by category:
            // Research now stores developers|||affiliation|||area|||specific_contribution|||contrib,
            // so the closest match for a paper's identity is Specific Contribution ($parts[4]).
            // Instruction B/C and Professional Development still keep their descriptive text at $parts[1].
            $title = trim(($s['kra_category'] === 'Research' ? ($parts[4] ?? '') : ($parts[1] ?? '')));
            if ($title === '' || strlen($title) < 5) continue;
            $cat = $s['kra_category'];
            $title_map[$title][] = $cat;
        }
        foreach ($title_map as $title => $cats) {
            $unique_cats = array_unique($cats);
            if (count($unique_cats) < 2) continue;

            // The ONE confirmed exception: paper both presented (KRA IV Crit B)
            // AND later published in indexed journal (KRA II Crit A).
            $has_kra2 = in_array('Research', $unique_cats);
            $has_kra4 = in_array('Professional Development', $unique_cats);
            if (count($unique_cats) === 2 && $has_kra2 && $has_kra4) {
                // This is the allowed exception — do not flag, just note it
                continue;
            }

            $flags[] = [
                'title'      => $title,
                'categories' => $unique_cats,
                'message'    => "Document appears in multiple KRA categories — flag for manual review before scoring proceeds.",
            ];
        }
        return $flags;
    }

    // ── STEP 3: Admin duty exclusion ────────────────────────────────
    private static function checkAdminDutyExclusion(array $subs_by_cat): array
    {
        $flags = [];
        $extension_subs = $subs_by_cat['Extension'] ?? [];
        $kra3d_keys = ['D-bonus', 'D-designation', 'kra3_d_'];

        $has_designation = false;
        foreach ($extension_subs as $s) {
            $parts   = explode('|||', $s['remarks'] ?? '');
            $subtype = strtolower(trim($parts[0] ?? ''));
            if (str_contains($subtype, 'd-') || str_contains($subtype, 'bonus') || str_contains($subtype, 'designation')) {
                $has_designation = true;
                break;
            }
        }

        // If faculty claims a designation bonus AND also claims accomplishments
        // that may be part of their admin role, flag ambiguous cases.
        if ($has_designation) {
            foreach ($extension_subs as $s) {
                $parts   = explode('|||', $s['remarks'] ?? '');
                $subtype = strtolower(trim($parts[0] ?? ''));
                // Skip the designation entries themselves
                if (str_contains($subtype, 'd-') || str_contains($subtype, 'bonus') || str_contains($subtype, 'designation')) continue;
                // MOA/linkage entries linked to admin role need checker review
                if (str_contains($subtype, 'moa') || str_contains($subtype, 'linkage')) {
                    $flags[] = 'Admin duty exclusion: MOA/linkage entry may be part of administrative designation — flag for checker review. Submission ID ' . $s['submission_id'];
                }
            }
        }
        return $flags;
    }

    // ── KRA weights by rank ──────────────────────────────────────────
    public static function getKraWeights(string $rank): array
    {
        if (preg_match('/^Instructor/i', $rank))
            return ['Instruction'=>0.60,'Research'=>0.10,'Extension'=>0.20,'Professional Development'=>0.10];
        if (preg_match('/^Assistant Professor/i', $rank))
            return ['Instruction'=>0.50,'Research'=>0.20,'Extension'=>0.20,'Professional Development'=>0.10];
        if (preg_match('/^Associate Professor/i', $rank))
            return ['Instruction'=>0.40,'Research'=>0.30,'Extension'=>0.20,'Professional Development'=>0.10];
        if (preg_match('/^Professor (I|II|III|IV|V|VI)$/i', $rank))
            return ['Instruction'=>0.30,'Research'=>0.40,'Extension'=>0.20,'Professional Development'=>0.10];
        if (preg_match('/^(College|University) Professor/i', $rank))
            return ['Instruction'=>0.20,'Research'=>0.50,'Extension'=>0.20,'Professional Development'=>0.10];
        return ['Instruction'=>0.60,'Research'=>0.10,'Extension'=>0.20,'Professional Development'=>0.10];
    }

    // ── Sub-rank brackets per JC01 ───────────────────────────────────
    public static function getSubRankIncrement(float $score): int
    {
        if ($score >= 91) return 6;
        if ($score >= 81) return 5;
        if ($score >= 71) return 4;
        if ($score >= 61) return 3;
        if ($score >= 51) return 2;
        if ($score >= 41) return 1;
        return 0;
    }

    // ── Target rank after increment ──────────────────────────────────
    public static function computeTargetRank(string $current_rank, int $increment): string
    {
        if ($increment === 0) return $current_rank;
        $all_ranks   = \facultyRanks();
        $current_idx = array_search($current_rank, $all_ranks);
        if ($current_idx === false) return $current_rank;
        $target_idx  = min($current_idx + $increment, count($all_ranks) - 1);
        return $all_ranks[$target_idx];
    }

    // ── workflow_status mapping ───────────────────────────────────────
    /**
     * Maps the DB application status to the 4-state workflow_status enum
     * defined in the CHMSU-FT pilot system spec.
     *
     * draft                 → 'draft'
     * submitted             → 'local_checker_review'
     * under_review          → 'local_checker_review'
     * needs_revision        → 'local_checker_review'
     * rejected (returned)   → 'local_checker_review'  (awaiting resubmission)
     * talisay_review        → 'main_checker_review'
     * approved              → 'completed'
     * reclassified          → 'completed'
     * admin_rejected        → 'completed'             (final decision)
     * edit_requested        → 'local_checker_review'
     */
    public static function mapWorkflowStatus(string $db_status): string
    {
        return match($db_status) {
            'draft'          => 'draft',
            'submitted',
            'under_review',
            'needs_revision',
            'rejected',
            'edit_requested' => 'local_checker_review',
            'talisay_review' => 'main_checker_review',
            'approved',
            'reclassified',
            'admin_rejected' => 'completed',
            default          => 'draft',
        };
    }

    // ── Document completeness check ────────────────────────────────────
    /**
     * Checks whether all KRA submissions have at least one evidence file attached.
     * Constraint: presence check only. Authenticity, validity, and accuracy
     * are strictly evaluated through manual review by human checkers.
     * No automated OCR, document parsing, or AI verification is performed.
     */
    private static function checkDocumentCompleteness(\PDO $pdo, int $app_id, array $all_subs): array
    {
        $total             = count($all_subs);
        $with_files        = 0;
        $missing           = [];

        foreach ($all_subs as $s) {
            $sid = (int)$s['submission_id'];
            // Count evidence files for this submission
            try {
                $stmt = $pdo->prepare("SELECT COUNT(*) FROM kra_evidence_files WHERE submission_id = ?");
                $stmt->execute([$sid]);
                $file_count = (int)$stmt->fetchColumn();
            } catch (\Exception $e) {
                $file_count = 0;
            }
            // Fall back to legacy document_path
            $has_legacy = !empty($s['document_path'] ?? '');
            $has_files  = $file_count > 0 || $has_legacy;

            if ($has_files) {
                $with_files++;
            } else {
                $missing[] = [
                    'submission_id' => $sid,
                    'kra_category'  => $s['kra_category'],
                    'remarks_label' => substr($s['remarks'] ?? '', 0, 80),
                ];
            }
        }

        $pct = $total > 0 ? round(($with_files / $total) * 100) : 100;

        return [
            'all_present'           => empty($missing),
            'entries_with_files'    => $with_files,
            'entries_missing_files' => count($missing),
            'missing_entries'       => $missing,
            'completeness_pct'      => $pct,
            'note'                  => 'Presence check only — authenticity and accuracy are verified exclusively by human checkers. No automated document parsing or AI verification is performed.',
        ];
    }

    // ── ISS structured data builder ────────────────────────────────────
    /**
     * Builds the Individual Score Sheet data structure.
     * This mirrors the PDF ISS but as a machine-readable array so it can
     * be consumed by any output format (PDF, JSON API, print view).
     */
    private static function buildISS(
        array $app, array $faculty,
        array $kra1, array $kra2, array $kra3, array $kra4,
        array $rank_calc
    ): array {
        return [
            'faculty_name'       => ($faculty['full_name'] ?? '')
                                    ?: trim(($faculty['first_name'] ?? '') . ' ' . ($faculty['last_name'] ?? '')),
            'employee_id'        => $faculty['employee_id'] ?? '',
            'current_rank'       => $faculty['current_rank'] ?? $faculty['rank'] ?? '',
            'bumped_base_rank'   => $rank_calc['reclassified_rank'], // back-compat alias
            'cycle_id'           => $app['cycle_id'] ?? null,
            'application_status' => $app['status'] ?? '',
            'kra_scores' => [
                'kra1' => ['subtotal' => $kra1['subtotal'], 'cap' => $kra1['cap'],
                           'crit_a' => $kra1['criterion_a'], 'crit_b' => $kra1['criterion_b'], 'crit_c' => $kra1['criterion_c']],
                'kra2' => ['subtotal' => $kra2['subtotal'], 'cap' => $kra2['cap'],
                           'crit_a' => $kra2['criterion_a_raw'], 'crit_b' => $kra2['criterion_b_raw'], 'crit_c' => $kra2['criterion_c_raw']],
                'kra3' => ['subtotal' => $kra3['subtotal'], 'cap' => $kra3['cap'],
                           'crit_a' => $kra3['criterion_a'], 'crit_b' => $kra3['criterion_b'],
                           'crit_c' => $kra3['criterion_c'], 'crit_d_bonus' => $kra3['criterion_d_bonus']],
                'kra4' => ['subtotal' => $kra4['subtotal'], 'cap' => $kra4['cap'],
                           'crit_a' => $kra4['criterion_a'], 'crit_b' => $kra4['criterion_b'],
                           'crit_c' => $kra4['criterion_c'], 'crit_d_bonus' => $kra4['criterion_d_bonus']],
            ],
            // Capped raw points per KRA — this is what Table 1 of the ISS PDF
            // multiplies against each rank tier's weight row.
            'kra_raw_points' => [
                'kra1' => $kra1['subtotal'],
                'kra2' => $kra2['subtotal'],
                'kra3' => $kra3['subtotal'],
                'kra4' => $kra4['subtotal'],
            ],
            'grand_total'        => $kra1['subtotal'] + $kra2['subtotal'] + $kra3['subtotal'] + $kra4['subtotal'],

            // ── Rank-weighted scoring / reclassification breakdown ──────────
            // Table 1 uses getKraWeights() for every tier (see kra_pdf_render.php);
            // the fields below are the specific values for THIS applicant's
            // two-pass recompute and Auto Sub Rank combination (Steps 1-4 of spec).
            'base_rank'                    => $rank_calc['current_rank'],
            'weight_row_pass1'             => $rank_calc['weights_pass1'],
            'weighted_score_pass1'         => $rank_calc['weighted_score_pass1'],
            'sub_rank_increment_pass1'     => $rank_calc['sub_rank_increment_pass1'],
            'initial_reclassified_rank'    => $rank_calc['initial_reclassified_rank'],
            'weight_row_pass2'             => $rank_calc['weights_pass2'],
            'weighted_score_pass2'         => $rank_calc['weighted_score_pass2'],
            'sub_rank_increment_pass2'     => $rank_calc['sub_rank_increment_pass2'],
            'reclassified_rank'            => $rank_calc['reclassified_rank'],
            'qualified_auto_subrank_phd'   => $rank_calc['qualified_auto_subrank_phd'],
            'qualified_auto_subrank_award' => $rank_calc['qualified_auto_subrank_award'],
            'auto_subrank_bonus'           => $rank_calc['auto_subrank_bonus'],
            'final_rank'                   => $rank_calc['final_rank'],

            // Back-compat top-level fields (Pass 2 values / final rank).
            'weighted_score'     => $rank_calc['weighted_score'],
            'sub_rank_increment' => $rank_calc['sub_rank_increment'],
            'computed_rank'      => $rank_calc['final_rank'],

            'pending_documentation' => array_merge(
                $kra1['pending_documentation'],
                $kra2['pending_documentation'],
                $kra3['pending_documentation'],
                $kra4['pending_documentation']
            ),
            'config_incomplete'  => array_merge(
                $kra1['config_incomplete'],
                $kra2['config_incomplete'],
                $kra3['config_incomplete'],
                $kra4['config_incomplete']
            ),
            'generated_at'       => date('Y-m-d H:i:s'),
        ];
    }

    // ── Persist orchestrator results ─────────────────────────────────
    private static function persist(\PDO $pdo, int $app_id, array $data): void
    {
        // Ensure columns exist (runtime migration)
        $cols = [
            'committee_route'       => "VARCHAR(10) DEFAULT NULL",
            'evaluation_period_ok'  => "TINYINT(1) DEFAULT 1",
            'double_counting_flags' => "TEXT DEFAULT NULL",
            'pending_documentation' => "TEXT DEFAULT NULL",
            'config_incomplete'     => "TEXT DEFAULT NULL",
            'orchestrator_flags'    => "TEXT DEFAULT NULL",
        ];
        foreach ($cols as $col => $def) {
            try {
                $pdo->exec("ALTER TABLE applications ADD COLUMN {$col} {$def}");
            } catch (\Exception $e) { /* already exists */ }
        }

        try {
            $pdo->prepare("UPDATE applications SET
                total_score           = :grand_total,
                weighted_score        = :weighted_score,
                sub_rank_increment    = :sub_rank_increment,
                potential_rank        = :potential_rank,
                committee_route       = :committee_route,
                evaluation_period_ok  = :evaluation_period_ok,
                double_counting_flags = :double_counting_flags,
                pending_documentation = :pending_documentation,
                config_incomplete     = :config_incomplete,
                orchestrator_flags    = :orchestrator_flags
            WHERE application_id = :application_id")
            ->execute(array_merge($data, ['application_id' => $app_id]));
        } catch (\Exception $e) {
            // Fallback to minimal update if new columns not yet available
            $pdo->prepare("UPDATE applications SET
                total_score=:grand_total, weighted_score=:weighted_score,
                sub_rank_increment=:sub_rank_increment, potential_rank=:potential_rank
            WHERE application_id=:application_id")
            ->execute([
                'grand_total'        => $data['grand_total'],
                'weighted_score'     => $data['weighted_score'],
                'sub_rank_increment' => $data['sub_rank_increment'],
                'potential_rank'     => $data['potential_rank'],
                'application_id'     => $app_id,
            ]);
        }
    }
}
