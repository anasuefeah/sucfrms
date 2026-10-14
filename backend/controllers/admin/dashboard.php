<?php
// Request handling and data loading for frontend/views/admin/dashboard.php
$cycle = getActiveCycle($pdo);

// Core stats
$total_faculty  = (int)$pdo->query("SELECT COUNT(*) FROM users WHERE role = 'faculty'")->fetchColumn();
$total_applied  = (int)$pdo->query("SELECT COUNT(DISTINCT user_id) FROM applications WHERE status != 'draft'")->fetchColumn();
$total_approved = (int)$pdo->query("SELECT COUNT(*) FROM applications WHERE status='approved'")->fetchColumn();
$pending_review = (int)$pdo->query("SELECT COUNT(*) FROM applications WHERE status='submitted'")->fetchColumn();
$under_review   = (int)$pdo->query("SELECT COUNT(*) FROM applications WHERE status='under_review'")->fetchColumn();
$needs_revision = (int)$pdo->query("SELECT COUNT(*) FROM applications WHERE status='needs_revision'")->fetchColumn();
$total_checkers = (int)$pdo->query("SELECT COUNT(*) FROM users WHERE role = 'evaluator' AND status='active'")->fetchColumn();

// Deadline info removed  -  cycle timing is controlled by Open/Closed status only.
$days_left = null; $deadline_str = ''; $deadline_urgent = false;

// KRA averages
$kra_avgs = $pdo->query("SELECT kra_category, AVG(computed_points) as avg_pts FROM kra_submissions GROUP BY kra_category")->fetchAll();
$kra_map  = ['Instruction'=>0,'Research'=>0,'Extension'=>0,'Professional Development'=>0];
foreach ($kra_avgs as $k) $kra_map[$k['kra_category']] = round($k['avg_pts'], 2);

// Status counts
$status_counts = $pdo->query("SELECT status, COUNT(*) as cnt FROM applications GROUP BY status")->fetchAll(PDO::FETCH_KEY_PAIR);

// Campus stats
$campus_stats = $pdo->query("
    SELECT c.campus_name,
           COUNT(DISTINCT u.user_id) AS faculty_count,
           COUNT(DISTINCT CASE WHEN a.status != 'draft' THEN a.application_id END) AS applied_count,
           COUNT(DISTINCT CASE WHEN a.status = 'approved' THEN a.application_id END) AS reclass_count,
           COUNT(DISTINCT CASE WHEN a.status IN ('submitted','under_review') THEN a.application_id END) AS pending_count
    FROM campuses c
    LEFT JOIN users u ON u.campus_id = c.campus_id AND u.role = 'faculty'
    LEFT JOIN applications a ON a.user_id = u.user_id
    WHERE c.is_active = 1
    GROUP BY c.campus_id, c.campus_name ORDER BY applied_count DESC
")->fetchAll();

// Recent activity
$recent_logs = $pdo->query("
    SELECT al.action_performed, al.details, al.timestamp, al.role_at_time, u.full_name
    FROM audit_logs al LEFT JOIN users u ON al.user_id = u.user_id
    ORDER BY al.timestamp DESC LIMIT 8
")->fetchAll();

// 7-day trend
$trend_rows = $pdo->query("
    SELECT DATE(submitted_at) AS day, COUNT(*) AS cnt
    FROM applications WHERE submitted_at >= DATE_SUB(CURDATE(), INTERVAL 6 DAY)
    GROUP BY DATE(submitted_at)
")->fetchAll(PDO::FETCH_KEY_PAIR);
$trend_labels = []; $trend_data = [];
for ($i = 6; $i >= 0; $i--) {
    $d = date('Y-m-d', strtotime("-{$i} days"));
    $trend_labels[] = date('M d', strtotime($d));
    $trend_data[]   = (int)($trend_rows[$d] ?? 0);
}

// Donut data
$donut_colors = [
    'submitted'      => '#1a3a6b',
    'under_review'   => '#2f6fb0',
    'approved'       => '#334155',
    'rejected'       => '#94a3b8',
    'needs_revision' => '#475569',
    'admin_rejected' => '#1e293b',
    'draft'          => '#e2e8f0',
    'reclassified'   => '#1e4d8c',
];
$pie_labels = []; $pie_data = []; $pie_colors_arr = [];
foreach ($status_counts as $st => $cnt) {
    if ($st === 'draft') continue;
    $pie_labels[] = ucwords(str_replace('_',' ',$st));
    $pie_data[]   = $cnt;
    $pie_colors_arr[] = $donut_colors[$st] ?? '#94a3b8';
}
