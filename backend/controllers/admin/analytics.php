<?php
// Request handling and data loading for frontend/views/admin/analytics.php
if (!isAdmin()) { echo '<div class="alert alert-danger">Access denied.</div>'; return false; }

// ── Per-cycle stats ────────────────────────────────────────────────────────
$cycle_stats = $pdo->query("
    SELECT c.cycle_id, c.cycle_name, c.status AS cycle_status,
           c.submission_deadline, c.evaluation_deadline,
           COUNT(DISTINCT a.application_id)                                              AS applied,
           SUM(CASE WHEN a.status IN ('approved','reclassified') THEN 1 ELSE 0 END)     AS evaluation_complete,
           SUM(CASE WHEN a.status = 'reclassified' THEN 1 ELSE 0 END)                   AS reclassified,
           SUM(CASE WHEN a.status IN ('submitted','under_review','needs_revision') THEN 1 ELSE 0 END) AS pending,
           SUM(CASE WHEN a.status = 'needs_revision' THEN 1 ELSE 0 END)                 AS needs_revision,
           AVG(CASE WHEN a.weighted_score > 0 THEN a.weighted_score END)                AS avg_score
    FROM cycles c
    LEFT JOIN applications a ON a.cycle_id = c.cycle_id AND a.status != 'draft'
    GROUP BY c.cycle_id ORDER BY c.created_at ASC
")->fetchAll();

// ── Active cycle ────────────────────────────────────────────────────────────
$active_cycle = $pdo->query("SELECT * FROM cycles WHERE status='open' LIMIT 1")->fetch();

// ── Overall numbers ─────────────────────────────────────────────────────────
$total_applied    = array_sum(array_column($cycle_stats, 'applied'));
$total_complete   = array_sum(array_column($cycle_stats, 'evaluation_complete'));
$total_reclass    = array_sum(array_column($cycle_stats, 'reclassified'));
$total_pending    = array_sum(array_column($cycle_stats, 'pending'));
$total_revision   = array_sum(array_column($cycle_stats, 'needs_revision'));
$completion_rate  = $total_applied > 0 ? round(($total_complete / $total_applied) * 100, 1) : 0;

// ── Status distribution ──────────────────────────────────────────────────────
$status_dist = $pdo->query("
    SELECT status, COUNT(*) AS cnt
    FROM applications
    WHERE status != 'draft'
    GROUP BY status ORDER BY cnt DESC
")->fetchAll(PDO::FETCH_KEY_PAIR);

// ── Score distribution buckets ───────────────────────────────────────────────
$score_buckets = $pdo->query("
    SELECT
        SUM(CASE WHEN weighted_score = 0                    THEN 1 ELSE 0 END) AS zero,
        SUM(CASE WHEN weighted_score > 0  AND weighted_score < 41  THEN 1 ELSE 0 END) AS below_min,
        SUM(CASE WHEN weighted_score >= 41 AND weighted_score < 51 THEN 1 ELSE 0 END) AS band_41,
        SUM(CASE WHEN weighted_score >= 51 AND weighted_score < 61 THEN 1 ELSE 0 END) AS band_51,
        SUM(CASE WHEN weighted_score >= 61 AND weighted_score < 71 THEN 1 ELSE 0 END) AS band_61,
        SUM(CASE WHEN weighted_score >= 71 AND weighted_score < 81 THEN 1 ELSE 0 END) AS band_71,
        SUM(CASE WHEN weighted_score >= 81 AND weighted_score < 91 THEN 1 ELSE 0 END) AS band_81,
        SUM(CASE WHEN weighted_score >= 91                          THEN 1 ELSE 0 END) AS band_91
    FROM applications WHERE status != 'draft'
")->fetch();

// ── KRA averages ─────────────────────────────────────────────────────────────
$kra_avgs = $pdo->query("
    SELECT kra_category,
           ROUND(AVG(computed_points), 2) AS avg_pts,
           COUNT(*) AS submissions,
           ROUND(MIN(computed_points), 2) AS min_pts,
           ROUND(MAX(computed_points), 2) AS max_pts
    FROM kra_submissions
    GROUP BY kra_category ORDER BY avg_pts ASC
")->fetchAll();

// ── Campus breakdown ─────────────────────────────────────────────────────────
$dept_stats = $pdo->query("
    SELECT COALESCE(c.campus_name, 'Unassigned') AS campus_name,
           COUNT(DISTINCT u.user_id)                                                    AS total_faculty,
           COUNT(DISTINCT a.application_id)                                             AS applied,
           SUM(CASE WHEN a.status IN ('approved','reclassified') THEN 1 ELSE 0 END)    AS evaluation_complete,
           SUM(CASE WHEN a.status IN ('submitted','under_review','needs_revision') THEN 1 ELSE 0 END) AS in_progress,
           ROUND(AVG(CASE WHEN a.weighted_score > 0 THEN a.weighted_score END), 2)     AS avg_score,
           SUM(CASE WHEN a.weighted_score >= 41 THEN 1 ELSE 0 END)                     AS eligible_count
    FROM users u
    LEFT JOIN campuses c ON u.campus_id = c.campus_id
    LEFT JOIN applications a ON a.user_id = u.user_id AND a.status != 'draft'
    WHERE u.role = 'faculty' AND u.status = 'active'
    GROUP BY u.campus_id, c.campus_name
    ORDER BY avg_score DESC
")->fetchAll();

// ── Evaluator workload ────────────────────────────────────────────────────────
$evaluator_workload = $pdo->query("
    SELECT u.checker_label, u.first_name, u.last_name,
           COUNT(DISTINCT r.application_id)                                          AS total_reviewed,
           SUM(CASE WHEN r.decision = 'approved' THEN 1 ELSE 0 END)                 AS approved,
           SUM(CASE WHEN r.decision = 'rejected' THEN 1 ELSE 0 END)                 AS rejected,
           SUM(CASE WHEN r.decision = 'pending'  THEN 1 ELSE 0 END)                 AS pending
    FROM users u
    LEFT JOIN application_checker_reviews r ON r.checker_id = u.user_id
    WHERE u.role = 'evaluator' AND u.status = 'active'
    GROUP BY u.user_id
    ORDER BY total_reviewed DESC
")->fetchAll();

// ── Rank upgrade distribution (potential_rank vs current rank) ────────────────
$rank_upgrades = $pdo->query("
    SELECT u.rank AS current_rank,
           a.potential_rank,
           COUNT(*) AS cnt
    FROM applications a
    JOIN users u ON a.user_id = u.user_id
    WHERE a.status IN ('approved','reclassified')
      AND a.potential_rank IS NOT NULL
      AND a.potential_rank != ''
      AND a.potential_rank != u.rank
    GROUP BY u.rank, a.potential_rank
    ORDER BY cnt DESC
    LIMIT 8
")->fetchAll();

// ── Faculty at risk (submitted but deadline < 3 days away or revision pending > 7 days) ─
$at_risk = [];
if ($active_cycle) {
    $at_risk = $pdo->prepare("
        SELECT u.first_name, u.last_name, u.employee_id, c.campus_name,
               a.status, a.weighted_score, a.submitted_at, a.updated_at,
               DATEDIFF(NOW(), a.updated_at) AS days_stale
        FROM applications a
        JOIN users u ON a.user_id = u.user_id
        LEFT JOIN campuses c ON u.campus_id = c.campus_id
        WHERE a.cycle_id = ?
          AND a.status = 'needs_revision'
          AND DATEDIFF(NOW(), a.updated_at) > 7
        ORDER BY days_stale DESC
        LIMIT 10
    ");
    $at_risk->execute([$active_cycle['cycle_id']]);
    $at_risk = $at_risk->fetchAll();
}

// ── Pending queue summary (active cycle) ─────────────────────────────────────
$pending_queue = null;
if ($active_cycle) {
    $pq = $pdo->prepare("
        SELECT
            SUM(CASE WHEN a.status = 'submitted'    THEN 1 ELSE 0 END) AS awaiting_start,
            SUM(CASE WHEN a.status = 'under_review' THEN 1 ELSE 0 END) AS under_review,
            SUM(CASE WHEN a.status = 'needs_revision' THEN 1 ELSE 0 END) AS needs_revision,
            AVG(CASE WHEN a.status IN ('submitted','under_review')
                     THEN DATEDIFF(NOW(), a.submitted_at) END)          AS avg_wait_days
        FROM applications a
        WHERE a.cycle_id = ?
    ");
    $pq->execute([$active_cycle['cycle_id']]);
    $pending_queue = $pq->fetch();
}

// ── Chart data ────────────────────────────────────────────────────────────────
$cycle_labels   = array_column($cycle_stats, 'cycle_name');
$applied_data   = array_column($cycle_stats, 'applied');
$complete_data  = array_column($cycle_stats, 'evaluation_complete');
$reclass_data   = array_column($cycle_stats, 'reclassified');

$score_bucket_labels = ['0 pts','< 41','41–50','51–60','61–70','71–80','81–90','≥ 91'];
$score_bucket_data   = [
    (int)($score_buckets['zero']     ?? 0),
    (int)($score_buckets['below_min']?? 0),
    (int)($score_buckets['band_41']  ?? 0),
    (int)($score_buckets['band_51']  ?? 0),
    (int)($score_buckets['band_61']  ?? 0),
    (int)($score_buckets['band_71']  ?? 0),
    (int)($score_buckets['band_81']  ?? 0),
    (int)($score_buckets['band_91']  ?? 0),
];
$score_bucket_colors = [
    '#94a3b8','#dc2626','#f59e0b','#eab308',
    '#3b82f6','#1a3a6b','#1e4d8c','#15803d'
];

$campus_names  = array_column($dept_stats, 'campus_name');
$campus_scores = array_map(fn($d) => (float)($d['avg_score'] ?? 0), $dept_stats);

$pie_labels = []; $pie_data = []; $pie_colors = [];
$status_label_map = [
    'submitted'      => 'Submitted',
    'under_review'   => 'Under Review',
    'needs_revision' => 'Needs Revision',
    'approved'       => 'Evaluation Complete',
    'rejected'       => 'Returned',
    'admin_rejected' => 'Admin Rejected',
    'reclassified'   => 'Reclassified',
];
$status_color_map = [
    'submitted'      => '#1a3a6b',
    'under_review'   => '#2f6fb0',
    'needs_revision' => '#f59e0b',
    'approved'       => '#16a34a',
    'rejected'       => '#dc2626',
    'admin_rejected' => '#475569',
    'reclassified'   => '#0f766e',
];
foreach ($status_dist as $st => $cnt) {
    $pie_labels[] = $status_label_map[$st] ?? ucwords(str_replace('_',' ',$st));
    $pie_data[]   = $cnt;
    $pie_colors[] = $status_color_map[$st] ?? '#94a3b8';
}

$kra_short = [
    'Instruction'              => 'KRA I',
    'Research'                 => 'KRA II',
    'Extension'                => 'KRA III',
    'Professional Development' => 'KRA IV',
];

// ── Auto insights ─────────────────────────────────────────────────────────────
$insights = [];
if ($total_applied === 0) {
    $insights[] = ['info', 'bi-info-circle', 'No Data Yet', 'No applications have been submitted yet. Insights will appear once faculty begin applying.'];
} else {

    // ── 1. Overall completion rate ─────────────────────────────────────────
    if ($completion_rate >= 75)
        $insights[] = ['success','bi-trophy-fill','Strong Completion Rate',
            "Overall completion rate is {$completion_rate}% across {$total_applied} application(s) — above the 75% benchmark. The reclassification process is performing well."];
    elseif ($completion_rate >= 40)
        $insights[] = ['warning','bi-exclamation-circle','Moderate Completion Rate',
            "Completion rate is {$completion_rate}% ({$total_complete} of {$total_applied} applications). Consider reviewing evaluator assignments and deadline timelines to improve throughput."];
    elseif ($completion_rate > 0)
        $insights[] = ['danger','bi-exclamation-triangle-fill','Low Completion Rate',
            "Only {$completion_rate}% of submitted applications have completed evaluation ({$total_complete} of {$total_applied}). This is below the 40% threshold — evaluator capacity or bottlenecks may need immediate attention."];

    // ── 2. Pending backlog ────────────────────────────────────────────────
    if ($total_pending > 0) {
        $pct_pending = round(($total_pending / $total_applied) * 100);
        $insights[] = [$pct_pending > 50 ? 'danger' : 'warning', 'bi-hourglass-split', 'Pending Applications',
            "{$total_pending} application(s) ({$pct_pending}% of total) are still in progress — either awaiting evaluation start, under review, or returned for revision. ".
            ($pct_pending > 50 ? "More than half the pipeline is unresolved — immediate action recommended." : "Monitor closely to prevent end-of-cycle bottlenecks.")];
    }

    // ── 3. Revision stall ─────────────────────────────────────────────────
    if ($total_revision > 0) {
        $rev_pct = $total_applied > 0 ? round(($total_revision / $total_applied) * 100) : 0;
        $insights[] = ['warning','bi-arrow-counterclockwise','Revision Backlog',
            "{$total_revision} application(s) ({$rev_pct}% of submitted) are currently returned to faculty for revision and awaiting resubmission. ".
            "Prolonged revision periods delay evaluator availability for other applications."];
    }

    // ── 4. Review wait time (active cycle) ───────────────────────────────
    if ($pending_queue) {
        $avg_wait = round($pending_queue['avg_wait_days'] ?? 0);
        if ($avg_wait > 21)
            $insights[] = ['danger','bi-alarm','Critical Review Delay',
                "Applications in the current cycle have been waiting an average of {$avg_wait} days for evaluation — far above the recommended 14-day turnaround. Evaluator workload rebalancing is strongly recommended."];
        elseif ($avg_wait > 14)
            $insights[] = ['warning','bi-clock','Slow Evaluation Turnaround',
                "Average wait time before evaluation is {$avg_wait} days. The target is 14 days. Consider checking if evaluators have sufficient capacity or if KRA assignments are evenly distributed."];
        elseif ($avg_wait > 0)
            $insights[] = ['success','bi-clock-history','Evaluation On Schedule',
                "Applications are being reviewed within {$avg_wait} days on average — within the recommended 14-day window."];

        // Awaiting start
        $awaiting = (int)($pending_queue['awaiting_start'] ?? 0);
        if ($awaiting > 0)
            $insights[] = ['warning','bi-inbox','Applications Awaiting Evaluation Start',
                "{$awaiting} submitted application(s) have not yet been picked up by any evaluator. These are visible in the review queue and should be assigned or started promptly."];
    }

    // ── 5. Faculty stalled on revision ────────────────────────────────────
    if (!empty($at_risk)) {
        $stall_count = count($at_risk);
        $max_stall   = max(array_column($at_risk, 'days_stale'));
        $insights[] = ['danger','bi-person-exclamation','Faculty Stalled on Revision',
            "{$stall_count} faculty member(s) have had their application returned for revision for more than 7 days without resubmitting. The longest stall is {$max_stall} days. ".
            "Direct follow-up may be needed — unresolved revisions block evaluators from completing their queue."];
    }

    // ── 6. Score distribution — below minimum ────────────────────────────
    $below_min_count = (int)($score_buckets['below_min'] ?? 0) + (int)($score_buckets['zero'] ?? 0);
    if ($below_min_count > 0 && $total_applied > 0) {
        $below_pct = round(($below_min_count / $total_applied) * 100);
        $insights[] = [$below_pct > 30 ? 'danger' : 'warning', 'bi-bar-chart-line','Faculty Below Minimum Score',
            "{$below_min_count} application(s) ({$below_pct}% of total) have a weighted score below 41 — the minimum for reclassification eligibility. ".
            ($below_pct > 30
                ? "This is a significant proportion. Faculty orientation on KRA documentation and scoring criteria may be needed."
                : "Targeted guidance on KRA entry completeness and evidence quality may help these faculty reach eligibility.")];
    }

    // ── 7. Score distribution — high performers ──────────────────────────
    $top_scorers = (int)($score_buckets['band_81'] ?? 0) + (int)($score_buckets['band_91'] ?? 0);
    if ($top_scorers > 0) {
        $top_pct = round(($top_scorers / $total_applied) * 100);
        $insights[] = ['success','bi-stars','High-Performing Faculty',
            "{$top_scorers} application(s) ({$top_pct}%) achieved scores of 81 or above. ".
            "These faculty are strong candidates for significant rank advancement and may serve as peer mentors for KRA documentation."];
    }

    // ── 8. KRA weakness ──────────────────────────────────────────────────
    if (!empty($kra_avgs)) {
        $weakest  = $kra_avgs[0];
        $strongest = end($kra_avgs);
        $spread   = round($strongest['avg_pts'] - $weakest['avg_pts'], 1);

        if (round($weakest['avg_pts'], 1) < 20)
            $insights[] = ['danger','bi-bar-chart','Critical KRA Gap — '.$weakest['kra_category'],
                $weakest['kra_category']." has a critically low average score of ".number_format($weakest['avg_pts'],1)." pts across ".number_format($weakest['submissions'])." submissions. ".
                "Faculty may be under-documenting or misclassifying entries for this KRA. A briefing or training session is recommended before the next cycle."];
        elseif (round($weakest['avg_pts'], 1) < 40)
            $insights[] = ['warning','bi-bar-chart','Weak KRA Area — '.$weakest['kra_category'],
                $weakest['kra_category']." is the lowest-performing area with an average of ".number_format($weakest['avg_pts'],1)." pts. ".
                "Faculty guidance on acceptable evidence types for this KRA may improve outcomes."];

        if ($spread > 30)
            $insights[] = ['info','bi-arrows-expand','Large KRA Score Spread',
                "There is a {$spread}-point gap between the strongest ({$strongest['kra_category']}: ".number_format($strongest['avg_pts'],1)." pts) and weakest ({$weakest['kra_category']}: ".number_format($weakest['avg_pts'],1)." pts) KRA areas. ".
                "This uneven performance suggests faculty effort is concentrated in certain KRAs — balanced documentation across all four areas maximises weighted scores."];

        // Low submission count on a KRA
        foreach ($kra_avgs as $kra) {
            if ((int)$kra['submissions'] < 5 && $total_applied >= 5)
                $insights[] = ['warning','bi-file-earmark-x','Low Submissions — '.$kra['kra_category'],
                    "Only ".number_format($kra['submissions'])." submission(s) recorded for {$kra['kra_category']} — significantly fewer than expected. Faculty may not be aware of qualifying activities in this area."];
        }
    }

    // ── 9. Campus score gap ───────────────────────────────────────────────
    if (!empty($dept_stats) && count($dept_stats) > 1) {
        $top_c    = $dept_stats[0];
        $bottom_c = end($dept_stats);
        $gap      = round(abs((float)$top_c['avg_score'] - (float)$bottom_c['avg_score']), 1);
        if ($gap > 20)
            $insights[] = ['danger','bi-building','Significant Campus Performance Gap',
                "There is a {$gap}-point average score gap between {$top_c['campus_name']} (".number_format($top_c['avg_score'],1)." pts) and {$bottom_c['campus_name']} (".number_format($bottom_c['avg_score'],1)." pts). ".
                "This disparity may reflect differences in faculty development support, KRA documentation culture, or campus-level guidance. Targeted intervention at lower-performing campuses is recommended."];
        elseif ($gap > 10)
            $insights[] = ['warning','bi-building','Campus Score Disparity',
                "{$top_c['campus_name']} leads with ".number_format($top_c['avg_score'],1)." pts vs {$bottom_c['campus_name']} at ".number_format($bottom_c['avg_score'],1)." pts ({$gap}-pt gap). ".
                "Consider sharing best practices from top-performing campuses."];

        // Campuses with zero applications
        foreach ($dept_stats as $d) {
            if ((int)$d['applied'] === 0 && (int)$d['total_faculty'] > 0)
                $insights[] = ['warning','bi-building-x','No Applications — '.$d['campus_name'],
                    "{$d['campus_name']} has {$d['total_faculty']} active faculty but zero applications submitted. ".
                    "Faculty at this campus may not be aware of the open cycle or may need additional encouragement to apply."];
        }

        // Low participation rate
        foreach ($dept_stats as $d) {
            $part = $d['total_faculty'] > 0 ? round(($d['applied'] / $d['total_faculty']) * 100) : 0;
            if ($part < 30 && (int)$d['total_faculty'] >= 5 && (int)$d['applied'] > 0)
                $insights[] = ['warning','bi-people','Low Participation — '.$d['campus_name'],
                    "Only {$part}% of active faculty at {$d['campus_name']} ({$d['applied']} of {$d['total_faculty']}) have submitted applications this cycle. Outreach and reminders may increase participation."];
        }
    }

    // ── 10. Evaluator workload imbalance ──────────────────────────────────
    if (!empty($evaluator_workload) && count($evaluator_workload) > 1) {
        $loads = array_column($evaluator_workload, 'total_reviewed');
        $max_load = max($loads);
        $min_load = min($loads);
        if ($max_load > 0 && ($max_load - $min_load) > 10) {
            $busiest = checkerDisplayLabel($evaluator_workload[0]);
            $lightest = checkerDisplayLabel(end($evaluator_workload));
            $insights[] = ['warning','bi-person-gear','Evaluator Workload Imbalance',
                "{$busiest} has reviewed {$max_load} application(s) while {$lightest} has handled {$min_load}. ".
                "An uneven distribution can lead to burnout or inconsistent evaluation quality. Rebalancing KRA assignments may help."];
        }

        // Evaluator with pending decisions
        foreach ($evaluator_workload as $ev) {
            if ((int)$ev['pending'] > 5) {
                $lbl = checkerDisplayLabel($ev);
                $insights[] = ['warning','bi-person-clock','Evaluator Pending Queue — '.$lbl,
                    "{$lbl} has {$ev['pending']} pending decision(s). A large pending count may indicate the evaluator is overloaded or has not yet started assigned reviews."];
                break; // only flag the worst one
            }
        }
    }

    // ── 11. Active cycle deadline proximity ───────────────────────────────
    if ($active_cycle) {
        if (!empty($active_cycle['submission_deadline'])) {
            $days_to_sub = (int)ceil((strtotime($active_cycle['submission_deadline'].' 23:59:59') - time()) / 86400);
            if ($days_to_sub <= 3 && $days_to_sub > 0)
                $insights[] = ['danger','bi-calendar-x','Submission Deadline Imminent',
                    "The submission deadline for the current cycle is in {$days_to_sub} day(s) (".date('M j, Y', strtotime($active_cycle['submission_deadline']))."). ".
                    "Faculty with draft applications should be notified immediately to submit before the window closes."];
            elseif ($days_to_sub <= 7 && $days_to_sub > 0)
                $insights[] = ['warning','bi-calendar-event','Submission Deadline Approaching',
                    "The submission deadline is in {$days_to_sub} days (".date('M j, Y', strtotime($active_cycle['submission_deadline']))."). ".
                    "Consider sending a reminder to faculty who have not yet submitted."];
        }
        if (!empty($active_cycle['evaluation_deadline'])) {
            $days_to_eval = (int)ceil((strtotime($active_cycle['evaluation_deadline'].' 23:59:59') - time()) / 86400);
            if ($days_to_eval <= 5 && $days_to_eval > 0 && $total_pending > 0)
                $insights[] = ['danger','bi-calendar-check','Evaluation Deadline Approaching with Pending Reviews',
                    "The evaluation deadline is in {$days_to_eval} day(s) but {$total_pending} application(s) are still pending. ".
                    "Evaluators must complete reviews urgently to meet the deadline."];
        }
    }

    // ── 12. Appeals ──────────────────────────────────────────────────────
    $open_appeals = (int)$pdo->query("SELECT COUNT(*) FROM appeals WHERE status IN ('open','under_review')")->fetchColumn();
    if ($open_appeals > 0)
        $insights[] = [$open_appeals >= 5 ? 'danger' : 'warning', 'bi-chat-square-text','Open Appeals',
            "{$open_appeals} appeal(s) are currently open or under review. ".
            ($open_appeals >= 5
                ? "A high number of open appeals may signal systematic scoring issues or poor communication between evaluators and faculty. Review the appeals for patterns."
                : "Ensure evaluators are responding to appeals in a timely manner.")];

    // ── 13. Evidence completeness ─────────────────────────────────────────
    $missing_evidence = (int)$pdo->query("
        SELECT COUNT(DISTINCT ks.application_id)
        FROM kra_submissions ks
        LEFT JOIN kra_evidence_files kef ON kef.submission_id = ks.submission_id
        WHERE kef.evidence_id IS NULL
          AND (ks.document_path IS NULL OR ks.document_path = '')
    ")->fetchColumn();
    if ($missing_evidence > 0)
        $insights[] = ['warning','bi-paperclip','Submissions Missing Evidence',
            "{$missing_evidence} KRA submission(s) across all applications have no attached evidence file. ".
            "Evaluators cannot verify entries without supporting documents — faculty with missing evidence should be contacted to upload files before evaluation."];

    // ── 14. Rank advancement summary ─────────────────────────────────────
    if ($total_complete > 0 && !empty($rank_upgrades)) {
        $total_upgrades = array_sum(array_column($rank_upgrades, 'cnt'));
        $max_upgrade    = $rank_upgrades[0];
        $insights[] = ['success','bi-arrow-up-circle-fill','Rank Advancement Summary',
            "{$total_upgrades} faculty member(s) have achieved a recommended rank change through evaluation. The most common progression is from {$max_upgrade['current_rank']} to {$max_upgrade['potential_rank']} ({$max_upgrade['cnt']} faculty)."];
    }

    // ── 15. No evaluators assigned ───────────────────────────────────────
    $active_eval_count = (int)$pdo->query("SELECT COUNT(*) FROM users WHERE role='evaluator' AND status='active'")->fetchColumn();
    if ($active_eval_count === 0)
        $insights[] = ['danger','bi-person-x','No Active Evaluators',
            "There are no active evaluator accounts in the system. Applications cannot be reviewed until evaluators are created and KRA categories are assigned to them."];
    elseif ($active_cycle) {
        $assigned_stmt = $pdo->prepare("SELECT COUNT(DISTINCT checker_id) FROM checker_kra_assignments WHERE cycle_id=?");
        $assigned_stmt->execute([$active_cycle['cycle_id']]);
        $assigned_evals = (int)$assigned_stmt->fetchColumn();
        $unassigned = $active_eval_count - $assigned_evals;
        if ($unassigned > 0)
            $insights[] = ['warning','bi-person-dash','Evaluators Without KRA Assignment',
                "{$unassigned} active evaluator(s) have no KRA assignments for the current cycle. They will not appear in the review queue and cannot start evaluations. Go to Manage Users → KRA Assignment to fix this."];
    }
}

$navy_800 = '#1a3a6b'; $navy_700 = '#1e4d8c'; $navy_600 = '#2f6fb0';
