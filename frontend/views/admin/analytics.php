<?php
if (!isAdmin()) { echo '<div class="alert alert-danger">Access denied.</div>'; return; }

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
?>

<!-- ── Page header bar ─────────────────────────────────────────────────────── -->
<div style="display:flex;align-items:center;justify-content:space-between;
            background:#fff;border:1px solid #e2e8f0;border-radius:10px;
            padding:0.85rem 1.25rem;margin-bottom:1.5rem;
            box-shadow:0 1px 4px rgba(30,58,140,0.05);">
    <div style="display:flex;align-items:center;gap:0.65rem;">
        <div style="width:38px;height:38px;border-radius:9px;background:#1e4d8c;
                    display:flex;align-items:center;justify-content:center;flex-shrink:0;">
            <i class="bi bi-graph-up-arrow" style="color:#fff;font-size:0.95rem;"></i>
        </div>
        <div>
            <div style="font-weight:700;color:#1a3a6b;font-size:0.95rem;line-height:1.2;">Analytics</div>
            <div style="font-size:0.72rem;color:#94a3b8;margin-top:2px;">Reclassification performance overview — all cycles and campuses</div>
        </div>
    </div>
    <div style="display:flex;align-items:center;gap:0.5rem;">
        <span style="font-size:0.7rem;color:#94a3b8;"><?= $total_applied ?> total application<?= $total_applied !== 1 ? 's' : '' ?></span>
        <?php if ($active_cycle): ?>
        <span style="background:#f0f4fb;color:#1a3a6b;border:1px solid #dbeafe;border-radius:20px;
                     font-size:0.68rem;font-weight:700;padding:2px 10px;">
            Active Cycle
        </span>
        <?php endif; ?>
    </div>
</div>

<!-- ── KPI cards ──────────────────────────────────────────────────────────── -->
<div style="display:grid;grid-template-columns:repeat(5,1fr);gap:0.75rem;margin-bottom:1.5rem;">
<?php
$kpi = [
    ['Total Applied',       $total_applied,      'bi-send',                  '#1a3a6b'],
    ['Evaluation Complete', $total_complete,      'bi-check-circle',          '#334155'],
    ['Pending Review',      $total_pending,       'bi-hourglass-split',       '#475569'],
    ['Needs Revision',      $total_revision,      'bi-arrow-counterclockwise','#64748b'],
    ['Completion Rate',     $completion_rate.'%', 'bi-percent',               '#1a3a6b'],
];
foreach ($kpi as [$label, $val, $icon, $col]):
?>
<div style="background:#fff;border:1px solid #e2e8f0;border-radius:9px;
            padding:0.9rem 1rem;border-left:3px solid <?= $col ?>;">
    <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:0.55rem;">
        <span style="font-size:0.63rem;font-weight:700;color:#94a3b8;text-transform:uppercase;
                     letter-spacing:0.07em;line-height:1.3;"><?= $label ?></span>
        <i class="bi <?= $icon ?>" style="color:#cbd5e1;font-size:0.85rem;"></i>
    </div>
    <div style="font-size:1.6rem;font-weight:800;color:<?= $col ?>;letter-spacing:-0.5px;line-height:1;"><?= $val ?></div>
</div>
<?php endforeach; ?>
</div>

<!-- ── Insights ───────────────────────────────────────────────────────────── -->
<div class="neon-card mb-4" style="padding:0;overflow:hidden;border:1px solid #e2e8f0;">

    <!-- Insights header -->
    <div style="padding:0.8rem 1.25rem;background:#f8fafc;border-bottom:1px solid #e2e8f0;
                display:flex;align-items:center;justify-content:space-between;">
        <div style="display:flex;align-items:center;gap:0.5rem;">
            <i class="bi bi-lightbulb" style="color:#1a3a6b;font-size:0.85rem;"></i>
            <span style="font-weight:700;color:#1a3a6b;font-size:0.85rem;">Insights</span>
            <span style="background:#1a3a6b;color:#fff;font-size:0.62rem;font-weight:700;
                         border-radius:20px;padding:1px 8px;margin-left:2px;"><?= count($insights) ?></span>
        </div>
        <span style="font-size:0.7rem;color:#94a3b8;">System-generated observations based on current data</span>
    </div>

    <?php if (empty($insights)): ?>
    <div style="padding:1.5rem 1.25rem;text-align:center;color:#94a3b8;font-size:0.82rem;">
        <i class="bi bi-inbox" style="font-size:1.5rem;display:block;margin-bottom:0.5rem;"></i>
        No insights available yet. Data will appear once applications are submitted.
    </div>
    <?php else: ?>
    <div style="padding:0.35rem 0;">
        <?php
        $severity_map = [
            'danger'  => ['Critical',  '#dc2626', '#fef2f2', '#fecaca'],
            'warning' => ['Warning',   '#92400e', '#fffbeb', '#fde68a'],
            'info'    => ['Info',      '#1e4d8c', '#f0f4fb', '#dbeafe'],
            'success' => ['Note',      '#166534', '#f0fdf4', '#bbf7d0'],
        ];
        foreach ($insights as $idx => [$type, $icon, $title, $msg]):
            [$sev_label, $sev_col, , ] = $severity_map[$type] ?? ['Info','#475569','#f8fafc','#e2e8f0'];
            $is_last = $idx === count($insights) - 1;
        ?>
        <div style="display:grid;grid-template-columns:90px 1fr;
                    <?= $is_last ? '' : 'border-bottom:1px solid #f1f5f9;' ?>
                    padding:0.65rem 1.25rem;gap:0.75rem;align-items:start;">
            <!-- Severity label -->
            <div style="padding-top:1px;">
                <span style="font-size:0.62rem;font-weight:700;text-transform:uppercase;
                             letter-spacing:0.06em;color:<?= $sev_col ?>;"><?= $sev_label ?></span>
            </div>
            <!-- Content -->
            <div>
                <div style="display:flex;align-items:center;gap:0.4rem;margin-bottom:2px;">
                    <i class="bi <?= $icon ?>" style="color:#475569;font-size:0.78rem;flex-shrink:0;"></i>
                    <span style="font-size:0.8rem;font-weight:700;color:#1e293b;"><?= htmlspecialchars($title) ?></span>
                </div>
                <div style="font-size:0.77rem;color:#475569;line-height:1.55;"><?= htmlspecialchars($msg) ?></div>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>
</div>

<!-- ── Active cycle pipeline ──────────────────────────────────────────────── -->
<?php if ($active_cycle && $pending_queue): ?>
<div class="neon-card mb-3" style="padding:0;overflow:hidden;border:1px solid #e2e8f0;">
    <div style="padding:0.8rem 1.25rem;background:#f8fafc;border-bottom:1px solid #e2e8f0;
                display:flex;align-items:center;justify-content:space-between;">
        <div style="display:flex;align-items:center;gap:0.5rem;">
            <i class="bi bi-activity" style="color:#1a3a6b;font-size:0.85rem;"></i>
            <span style="font-weight:700;color:#1a3a6b;font-size:0.85rem;"><?= sanitize($active_cycle['cycle_name']) ?></span>
        </div>
        <span style="font-size:0.7rem;color:#94a3b8;">Current pipeline status</span>
    </div>
    <div style="display:grid;grid-template-columns:repeat(4,1fr);padding:0;">
        <?php
        $pipe = [
            ['Awaiting Start',  (int)($pending_queue['awaiting_start'] ?? 0)],
            ['Under Review',    (int)($pending_queue['under_review']   ?? 0)],
            ['Needs Revision',  (int)($pending_queue['needs_revision'] ?? 0)],
            ['Avg Wait (days)', round($pending_queue['avg_wait_days']  ?? 0)],
        ];
        foreach ($pipe as $pi => [$pl, $pv]):
        ?>
        <div style="padding:1rem;text-align:center;<?= $pi < 3 ? 'border-right:1px solid #f1f5f9;' : '' ?>">
            <div style="font-size:1.5rem;font-weight:800;color:#1a3a6b;line-height:1;"><?= $pv ?></div>
            <div style="font-size:0.63rem;color:#94a3b8;margin-top:5px;font-weight:600;
                        text-transform:uppercase;letter-spacing:0.05em;"><?= $pl ?></div>
        </div>
        <?php endforeach; ?>
    </div>
</div>
<?php endif; ?>

<!-- ── Charts row 1 ───────────────────────────────────────────────────────── -->
<div style="display:grid;grid-template-columns:1fr 340px;gap:1rem;margin-bottom:1rem;">

    <div class="neon-card" style="padding:1.15rem 1.25rem;border:1px solid #e2e8f0;">
        <div style="font-weight:700;color:#1a3a6b;font-size:0.85rem;margin-bottom:2px;">Applications per Cycle</div>
        <div style="font-size:0.71rem;color:#94a3b8;margin-bottom:0.9rem;">Applied · Evaluation Complete · Reclassified</div>
        <div style="display:flex;gap:1.1rem;flex-wrap:wrap;margin-bottom:0.7rem;">
            <?php foreach ([[$navy_800,'Applied'],[$navy_600,'Evaluation Complete'],['#64748b','Reclassified']] as [$c,$l]): ?>
            <span style="display:flex;align-items:center;gap:4px;font-size:0.7rem;color:#64748b;">
                <span style="width:10px;height:10px;border-radius:2px;background:<?= $c ?>;flex-shrink:0;"></span><?= $l ?>
            </span>
            <?php endforeach; ?>
        </div>
        <div style="height:220px;"><canvas id="cycleChart"></canvas></div>
    </div>

    <div class="neon-card" style="padding:1.15rem 1.25rem;border:1px solid #e2e8f0;">
        <div style="font-weight:700;color:#1a3a6b;font-size:0.85rem;margin-bottom:2px;">Status Distribution</div>
        <div style="font-size:0.71rem;color:#94a3b8;margin-bottom:0.7rem;">Pipeline across all cycles</div>
        <?php if (array_sum($pie_data) > 0): ?>
        <div style="height:155px;"><canvas id="statusDonut"></canvas></div>
        <div style="margin-top:0.6rem;display:flex;flex-direction:column;gap:4px;">
            <?php foreach ($pie_labels as $i => $pl): ?>
            <div style="display:flex;align-items:center;justify-content:space-between;font-size:0.71rem;">
                <div style="display:flex;align-items:center;gap:5px;color:#64748b;">
                    <span style="width:7px;height:7px;border-radius:2px;background:<?= $pie_colors[$i] ?>;flex-shrink:0;"></span><?= $pl ?>
                </div>
                <strong style="color:#1a3a6b;"><?= $pie_data[$i] ?></strong>
            </div>
            <?php endforeach; ?>
        </div>
        <?php else: ?>
        <div style="height:155px;display:flex;align-items:center;justify-content:center;
                    color:#94a3b8;font-size:0.82rem;">No data yet</div>
        <?php endif; ?>
    </div>
</div>

<!-- ── Charts row 2 ───────────────────────────────────────────────────────── -->
<div style="display:grid;grid-template-columns:1fr 1fr;gap:1rem;margin-bottom:1rem;">

    <div class="neon-card" style="padding:1.15rem 1.25rem;border:1px solid #e2e8f0;">
        <div style="font-weight:700;color:#1a3a6b;font-size:0.85rem;margin-bottom:2px;">Score Distribution</div>
        <div style="font-size:0.71rem;color:#94a3b8;margin-bottom:0.7rem;">Applications per weighted score band</div>
        <div style="height:210px;"><canvas id="scoreDistChart"></canvas></div>
    </div>

    <div class="neon-card" style="padding:1.15rem 1.25rem;border:1px solid #e2e8f0;">
        <div style="font-weight:700;color:#1a3a6b;font-size:0.85rem;margin-bottom:2px;">Campus Average Score</div>
        <div style="font-size:0.71rem;color:#94a3b8;margin-bottom:0.7rem;">Avg weighted score per campus — min 41 to qualify</div>
        <?php if (!empty($dept_stats)): ?>
        <div style="height:210px;"><canvas id="campusChart"></canvas></div>
        <?php else: ?>
        <div style="height:210px;display:flex;align-items:center;justify-content:center;
                    color:#94a3b8;font-size:0.82rem;">No campus data yet</div>
        <?php endif; ?>
    </div>
</div>

<!-- ── KRA breakdown ──────────────────────────────────────────────────────── -->
<div class="neon-card mb-3" style="padding:0;overflow:hidden;border:1px solid #e2e8f0;">
    <div style="padding:0.8rem 1.25rem;background:#f8fafc;border-bottom:1px solid #e2e8f0;">
        <div style="font-weight:700;color:#1a3a6b;font-size:0.85rem;">KRA Score Breakdown</div>
        <div style="font-size:0.71rem;color:#94a3b8;">Average, min, and max points per KRA category</div>
    </div>
    <div style="padding:1.15rem 1.25rem;">
    <?php if (!empty($kra_avgs)):
        $kra_bar_cols = [$navy_800, $navy_600, '#475569', '#64748b'];
        foreach ($kra_avgs as $i => $kra):
            $pct  = min(100, round(($kra['avg_pts'] / 100) * 100, 1));
            $short = $kra_short[$kra['kra_category']] ?? $kra['kra_category'];
            $col  = $kra_bar_cols[$i % 4];
    ?>
    <div style="margin-bottom:1rem;">
        <div style="display:flex;justify-content:space-between;align-items:baseline;margin-bottom:5px;">
            <div>
                <span style="font-size:0.78rem;font-weight:700;color:#1a3a6b;"><?= $short ?></span>
                <span style="font-size:0.7rem;color:#64748b;margin-left:6px;"><?= sanitize($kra['kra_category']) ?></span>
                <span style="font-size:0.65rem;color:#94a3b8;margin-left:6px;"><?= number_format($kra['submissions']) ?> submissions</span>
            </div>
            <div style="display:flex;gap:1.2rem;font-size:0.71rem;color:#94a3b8;">
                <span>Min <strong style="color:#475569;"><?= $kra['min_pts'] ?></strong></span>
                <span>Max <strong style="color:#475569;"><?= $kra['max_pts'] ?></strong></span>
                <span style="color:<?= $col ?>;font-weight:700;">Avg <?= number_format($kra['avg_pts'], 2) ?> pts</span>
            </div>
        </div>
        <div style="height:7px;background:#f1f5f9;border-radius:99px;overflow:hidden;">
            <div style="height:100%;width:<?= $pct ?>%;background:<?= $col ?>;border-radius:99px;"></div>
        </div>
    </div>
    <?php endforeach; else: ?>
    <div style="text-align:center;padding:2rem;color:#94a3b8;font-size:0.82rem;">No KRA data yet</div>
    <?php endif; ?>
    </div>
</div>

<!-- ── Campus breakdown table ─────────────────────────────────────────────── -->
<?php if ($dept_stats): ?>
<div class="neon-card mb-3" style="padding:0;overflow:hidden;border:1px solid #e2e8f0;">
    <div style="padding:0.8rem 1.25rem;background:#f8fafc;border-bottom:1px solid #e2e8f0;">
        <div style="font-weight:700;color:#1a3a6b;font-size:0.85rem;">Campus Breakdown</div>
        <div style="font-size:0.71rem;color:#94a3b8;">Faculty count, application progress, and average score per campus</div>
    </div>
    <div class="table-responsive">
        <table style="width:100%;border-collapse:collapse;font-size:0.81rem;">
            <thead>
                <tr style="background:#f8fafc;border-bottom:1px solid #e2e8f0;">
                    <?php foreach (['Campus','Faculty','Applied','In Progress','Complete','Avg Score'] as $th): ?>
                    <th style="padding:0.6rem <?= $th==='Campus'?'1rem':'0.75rem' ?>;text-align:<?= $th==='Campus'||$th==='Avg Score'?'left':'center' ?>;
                               font-size:0.65rem;font-weight:700;text-transform:uppercase;letter-spacing:0.05em;color:#94a3b8;"><?= $th ?></th>
                    <?php endforeach; ?>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($dept_stats as $idx => $d):
                $sc = (float)($d['avg_score'] ?? 0);
                $sc_pct = min(100, round($sc));
                $sc_col = $sc >= 71 ? '#15803d' : ($sc >= 41 ? $navy_700 : '#dc2626');
                $participation = $d['total_faculty'] > 0 ? round(($d['applied'] / $d['total_faculty']) * 100) : 0;
            ?>
            <tr style="border-bottom:1px solid #f4f6f9;" onmouseover="this.style.background='#f8fafc'" onmouseout="this.style.background=''">
                <td style="padding:0.7rem 1rem;font-weight:600;color:#1e293b;">
                    <?php if ($idx === 0 && count($dept_stats) > 1): ?>
                    <span style="background:#f0fdf4;color:#15803d;font-size:0.58rem;font-weight:700;
                                 border-radius:3px;padding:1px 5px;border:1px solid #bbf7d0;margin-right:5px;">Top</span>
                    <?php endif; ?>
                    <?= sanitize($d['campus_name']) ?>
                </td>
                <td style="padding:0.7rem 0.75rem;text-align:center;color:#64748b;font-weight:600;"><?= $d['total_faculty'] ?></td>
                <td style="padding:0.7rem 0.75rem;text-align:center;">
                    <span style="font-weight:700;color:#1a3a6b;"><?= $d['applied'] ?></span>
                    <span style="font-size:0.65rem;color:#94a3b8;margin-left:3px;">(<?= $participation ?>%)</span>
                </td>
                <td style="padding:0.7rem 0.75rem;text-align:center;font-weight:600;color:#64748b;"><?= $d['in_progress'] ?></td>
                <td style="padding:0.7rem 0.75rem;text-align:center;font-weight:600;color:#334155;"><?= $d['evaluation_complete'] ?></td>
                <td style="padding:0.7rem 0.75rem;">
                    <div style="display:flex;align-items:center;gap:0.5rem;">
                        <div style="flex:1;height:6px;background:#f1f5f9;border-radius:99px;overflow:hidden;">
                            <div style="height:100%;width:<?= $sc_pct ?>%;background:<?= $sc_col ?>;border-radius:99px;"></div>
                        </div>
                        <span style="font-size:0.76rem;font-weight:700;color:<?= $sc_col ?>;min-width:38px;"><?= $sc > 0 ? number_format($sc,1) : '—' ?></span>
                    </div>
                </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endif; ?>

<!-- ── Evaluator workload ─────────────────────────────────────────────────── -->
<?php if (!empty($evaluator_workload)): ?>
<div class="neon-card mb-3" style="padding:0;overflow:hidden;border:1px solid #e2e8f0;">
    <div style="padding:0.8rem 1.25rem;background:#f8fafc;border-bottom:1px solid #e2e8f0;">
        <div style="font-weight:700;color:#1a3a6b;font-size:0.85rem;">Evaluator Workload</div>
        <div style="font-size:0.71rem;color:#94a3b8;">Review activity per evaluator across all cycles</div>
    </div>
    <div class="table-responsive">
        <table style="width:100%;border-collapse:collapse;font-size:0.81rem;">
            <thead>
                <tr style="background:#f8fafc;border-bottom:1px solid #e2e8f0;">
                    <?php foreach (['Evaluator','Total Reviewed','Approved','Returned','Pending','Approval Rate'] as $th): ?>
                    <th style="padding:0.6rem <?= $th==='Evaluator'||$th==='Approval Rate'?'1rem':'0.75rem' ?>;
                               text-align:<?= in_array($th,['Evaluator','Approval Rate'])?'left':'center' ?>;
                               font-size:0.65rem;font-weight:700;text-transform:uppercase;letter-spacing:0.05em;color:#94a3b8;"><?= $th ?></th>
                    <?php endforeach; ?>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($evaluator_workload as $ev):
                $total_ev = (int)$ev['total_reviewed'];
                $apr_rate = $total_ev > 0 ? round(($ev['approved'] / $total_ev) * 100) : 0;
                $label    = checkerDisplayLabel($ev);
            ?>
            <tr style="border-bottom:1px solid #f4f6f9;" onmouseover="this.style.background='#f8fafc'" onmouseout="this.style.background=''">
                <td style="padding:0.7rem 1rem;font-weight:600;color:#1e293b;"><?= sanitize($label) ?></td>
                <td style="padding:0.7rem 0.75rem;text-align:center;font-weight:700;color:#1a3a6b;"><?= $total_ev ?></td>
                <td style="padding:0.7rem 0.75rem;text-align:center;color:#334155;font-weight:600;"><?= $ev['approved'] ?></td>
                <td style="padding:0.7rem 0.75rem;text-align:center;color:#64748b;font-weight:600;"><?= $ev['rejected'] ?></td>
                <td style="padding:0.7rem 0.75rem;text-align:center;color:#94a3b8;font-weight:600;"><?= $ev['pending'] ?></td>
                <td style="padding:0.7rem 1rem;">
                    <div style="display:flex;align-items:center;gap:0.5rem;">
                        <div style="flex:1;height:5px;background:#f1f5f9;border-radius:99px;overflow:hidden;">
                            <div style="height:100%;width:<?= $apr_rate ?>%;background:#1a3a6b;border-radius:99px;"></div>
                        </div>
                        <span style="font-size:0.73rem;font-weight:700;color:#1a3a6b;min-width:32px;"><?= $apr_rate ?>%</span>
                    </div>
                </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endif; ?>

<!-- ── Faculty stalled on revision ────────────────────────────────────────── -->
<?php if (!empty($at_risk)): ?>
<div class="neon-card mb-3" style="padding:0;overflow:hidden;border:1px solid #e2e8f0;border-left:3px solid #dc2626;">
    <div style="padding:0.8rem 1.25rem;background:#f8fafc;border-bottom:1px solid #e2e8f0;
                display:flex;align-items:center;justify-content:space-between;">
        <div style="display:flex;align-items:center;gap:0.5rem;">
            <i class="bi bi-person-exclamation" style="color:#dc2626;font-size:0.85rem;"></i>
            <span style="font-weight:700;color:#1a3a6b;font-size:0.85rem;">Faculty Stalled on Revision</span>
            <span style="background:#fef2f2;color:#dc2626;border:1px solid #fecaca;font-size:0.62rem;
                         font-weight:700;border-radius:20px;padding:1px 8px;"><?= count($at_risk) ?></span>
        </div>
        <span style="font-size:0.7rem;color:#94a3b8;">No resubmission for more than 7 days</span>
    </div>
    <div class="table-responsive">
        <table style="width:100%;border-collapse:collapse;font-size:0.81rem;">
            <thead>
                <tr style="background:#f8fafc;border-bottom:1px solid #e2e8f0;">
                    <?php foreach (['Faculty','Campus','Score','Days Stalled'] as $th): ?>
                    <th style="padding:0.6rem <?= $th==='Faculty'?'1rem':'0.75rem' ?>;
                               text-align:<?= in_array($th,['Faculty','Campus'])?'left':'center' ?>;
                               font-size:0.65rem;font-weight:700;text-transform:uppercase;letter-spacing:0.05em;color:#94a3b8;"><?= $th ?></th>
                    <?php endforeach; ?>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($at_risk as $ar): ?>
            <tr style="border-bottom:1px solid #f4f6f9;" onmouseover="this.style.background='#f8fafc'" onmouseout="this.style.background=''">
                <td style="padding:0.7rem 1rem;">
                    <div style="font-weight:600;color:#1e293b;"><?= sanitize(trim($ar['first_name'].' '.$ar['last_name'])) ?></div>
                    <div style="font-size:0.68rem;color:#94a3b8;"><?= sanitize($ar['employee_id'] ?? '') ?></div>
                </td>
                <td style="padding:0.7rem 0.75rem;color:#64748b;"><?= sanitize($ar['campus_name'] ?? '—') ?></td>
                <td style="padding:0.7rem 0.75rem;text-align:center;font-weight:700;
                           color:<?= (float)$ar['weighted_score'] >= 41 ? '#334155' : '#dc2626' ?>;">
                    <?= number_format((float)$ar['weighted_score'], 2) ?>
                </td>
                <td style="padding:0.7rem 0.75rem;text-align:center;">
                    <span style="background:#f1f5f9;color:#64748b;border:1px solid #e2e8f0;
                                 border-radius:20px;padding:2px 10px;font-size:0.73rem;font-weight:700;">
                        <?= $ar['days_stale'] ?>d
                    </span>
                </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endif; ?>

<!-- ── Per-cycle summary ───────────────────────────────────────────────────── -->
<div class="neon-card mb-3" style="padding:0;overflow:hidden;border:1px solid #e2e8f0;">
    <div style="padding:0.8rem 1.25rem;background:#f8fafc;border-bottom:1px solid #e2e8f0;">
        <div style="font-weight:700;color:#1a3a6b;font-size:0.85rem;">Per-Cycle Summary</div>
        <div style="font-size:0.71rem;color:#94a3b8;">Detailed breakdown by reclassification cycle</div>
    </div>
    <div class="table-responsive">
        <table style="width:100%;border-collapse:collapse;font-size:0.81rem;">
            <thead>
                <tr style="background:#f8fafc;border-bottom:1px solid #e2e8f0;">
                    <?php foreach (['Cycle','Applied','Pending','Complete','Avg Score','Completion Rate'] as $th): ?>
                    <th style="padding:0.6rem <?= in_array($th,['Cycle','Completion Rate'])?'1rem':'0.75rem' ?>;
                               text-align:<?= in_array($th,['Cycle','Completion Rate'])?'left':'center' ?>;
                               font-size:0.65rem;font-weight:700;text-transform:uppercase;letter-spacing:0.05em;color:#94a3b8;"><?= $th ?></th>
                    <?php endforeach; ?>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($cycle_stats as $cs):
                $rate = $cs['applied'] > 0 ? round(($cs['evaluation_complete'] / $cs['applied']) * 100, 1) : 0;
                $avg  = round((float)($cs['avg_score'] ?? 0), 1);
                $rc   = $rate >= 75 ? '#334155' : ($rate >= 40 ? $navy_600 : ($rate > 0 ? '#64748b' : '#94a3b8'));
                $status_badge = match($cs['cycle_status']) {
                    'open'     => ['Open',     '#15803d','#f0fdf4','#bbf7d0'],
                    'closed'   => ['Closed',   '#475569','#f1f5f9','#e2e8f0'],
                    'archived' => ['Archived', '#94a3b8','#f8fafc','#f1f5f9'],
                    default    => ['—',        '#94a3b8','#f8fafc','#f1f5f9'],
                };
            ?>
            <tr style="border-bottom:1px solid #f4f6f9;" onmouseover="this.style.background='#f8fafc'" onmouseout="this.style.background=''">
                <td style="padding:0.75rem 1rem;font-weight:600;color:#1e293b;">
                    <div style="display:flex;align-items:center;gap:0.5rem;">
                        <?= sanitize($cs['cycle_name']) ?>
                        <span style="background:<?= $status_badge[2] ?>;color:<?= $status_badge[1] ?>;
                                     border:1px solid <?= $status_badge[3] ?>;font-size:0.58rem;
                                     font-weight:700;padding:1px 6px;border-radius:3px;"><?= $status_badge[0] ?></span>
                    </div>
                </td>
                <td style="padding:0.75rem 0.75rem;text-align:center;font-weight:700;color:#1a3a6b;"><?= $cs['applied'] ?></td>
                <td style="padding:0.75rem 0.75rem;text-align:center;color:#64748b;font-weight:600;"><?= $cs['pending'] ?></td>
                <td style="padding:0.75rem 0.75rem;text-align:center;color:#334155;font-weight:600;"><?= $cs['evaluation_complete'] ?></td>
                <td style="padding:0.75rem 0.75rem;text-align:center;font-weight:700;
                           color:<?= $avg >= 41 ? '#334155' : '#94a3b8' ?>;"><?= $avg > 0 ? $avg : '—' ?></td>
                <td style="padding:0.75rem 1rem;">
                    <div style="display:flex;align-items:center;gap:0.6rem;">
                        <div style="flex:1;height:6px;background:#f1f5f9;border-radius:99px;overflow:hidden;">
                            <div style="height:100%;width:<?= $rate ?>%;background:<?= $rc ?>;border-radius:99px;"></div>
                        </div>
                        <span style="font-size:0.73rem;font-weight:700;color:<?= $rc ?>;min-width:34px;"><?= $rate ?>%</span>
                    </div>
                </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    const tt = { backgroundColor:'#1e293b', titleColor:'#f1f5f9', bodyColor:'#cbd5e1', cornerRadius:6, padding:9 };
    const gridColor = '#f1f5f9';
    const tickColor = '#94a3b8';
    const tickFont  = { size: 11 };

    new Chart(document.getElementById('cycleChart'), {
        type:'bar',
        data:{
            labels:<?= json_encode($cycle_labels) ?>,
            datasets:[
                { label:'Applied',             data:<?= json_encode($applied_data) ?>,  backgroundColor:'<?= $navy_800 ?>', borderRadius:4, borderSkipped:false },
                { label:'Evaluation Complete', data:<?= json_encode($complete_data) ?>, backgroundColor:'<?= $navy_600 ?>', borderRadius:4, borderSkipped:false },
                { label:'Reclassified',        data:<?= json_encode($reclass_data) ?>,  backgroundColor:'#94a3b8',         borderRadius:4, borderSkipped:false }
            ]
        },
        options:{
            responsive:true, maintainAspectRatio:false,
            plugins:{ legend:{display:false}, tooltip:{...tt} },
            scales:{
                y:{ beginAtZero:true, grid:{color:gridColor}, ticks:{color:tickColor,font:tickFont} },
                x:{ grid:{display:false}, ticks:{color:tickColor,font:tickFont,maxRotation:30,
                    callback(v){const l=this.getLabelForValue(v);return l.length>20?l.substr(0,20)+'…':l;}} }
            }
        }
    });

    <?php if (array_sum($pie_data) > 0): ?>
    new Chart(document.getElementById('statusDonut'), {
        type:'doughnut',
        data:{
            labels:<?= json_encode($pie_labels) ?>,
            datasets:[{ data:<?= json_encode($pie_data) ?>, backgroundColor:<?= json_encode($pie_colors) ?>, borderWidth:2, borderColor:'#fff', hoverOffset:5 }]
        },
        options:{
            responsive:true, maintainAspectRatio:false, cutout:'70%',
            plugins:{
                legend:{display:false},
                tooltip:{...tt, callbacks:{label:ctx=>` ${ctx.label}: ${ctx.parsed} (${Math.round(ctx.parsed/<?= max(1,array_sum($pie_data)) ?>*100)}%)`}}
            }
        }
    });
    <?php endif; ?>

    new Chart(document.getElementById('scoreDistChart'), {
        type:'bar',
        data:{
            labels:<?= json_encode($score_bucket_labels) ?>,
            datasets:[{ data:<?= json_encode($score_bucket_data) ?>,
                backgroundColor:['#94a3b8','#94a3b8','#475569','#475569','#334155','#1e4d8c','#1a3a6b','#0f2a56'],
                borderRadius:4, borderSkipped:false }]
        },
        options:{
            responsive:true, maintainAspectRatio:false,
            plugins:{ legend:{display:false}, tooltip:{...tt, callbacks:{label:ctx=>`${ctx.parsed.y} application(s)`}} },
            scales:{
                y:{ beginAtZero:true, grid:{color:gridColor}, ticks:{color:tickColor,font:tickFont,stepSize:1} },
                x:{ grid:{display:false}, ticks:{color:tickColor,font:tickFont} }
            }
        }
    });

    <?php if (!empty($dept_stats)): ?>
    new Chart(document.getElementById('campusChart'), {
        type:'bar',
        data:{
            labels:<?= json_encode($campus_names) ?>,
            datasets:[{
                data:<?= json_encode($campus_scores) ?>,
                backgroundColor:<?= json_encode(array_map(fn($s) => $s >= 41 ? $navy_800 : '#94a3b8', $campus_scores)) ?>,
                borderRadius:4, borderSkipped:false
            }]
        },
        options:{
            responsive:true, maintainAspectRatio:false, indexAxis:'y',
            plugins:{
                legend:{display:false},
                tooltip:{...tt, callbacks:{
                    label:ctx=>`Avg: ${ctx.parsed.x} pts`,
                    afterLabel:ctx=>ctx.parsed.x>=41?'Above minimum':'Below minimum (41)'
                }}
            },
            scales:{
                x:{ beginAtZero:true, max:100, grid:{color:gridColor}, ticks:{color:tickColor,font:tickFont} },
                y:{ grid:{display:false}, ticks:{color:tickColor,font:{...tickFont,weight:'600'},
                    callback(v){const l=this.getLabelForValue(v);return l.length>16?l.substr(0,16)+'…':l;}} }
            }
        }
    });
    <?php endif; ?>
});
</script>
