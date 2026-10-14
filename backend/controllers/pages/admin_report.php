<?php
// Request handling and data loading for frontend/pages/admin_report.php
session_start();
header('Content-Type: text/html; charset=UTF-8');
require_once __DIR__ . '/../../core/functions.php';
require_once __DIR__ . '/../../database/config/db.php';

if (!isLoggedIn() || !isAdmin()) {
    http_response_code(403); die('Access denied.');
}

// -- Filters from query string ---------------------------------
$cycle_id = intval($_GET['cycle_id'] ?? 0);
$status   = trim($_GET['status'] ?? '');
$campus   = intval($_GET['campus_id'] ?? 0);

// -- Load cycles for filter dropdown --------------------------
$all_cycles  = $pdo->query("SELECT cycle_id, cycle_name, status FROM cycles ORDER BY created_at DESC")->fetchAll();
$all_campuses = $pdo->query("SELECT campus_id, campus_name FROM campuses WHERE is_active=1 ORDER BY campus_name")->fetchAll();

// -- Active cycle fallback -------------------------------------
if (!$cycle_id) {
    foreach ($all_cycles as $cy) {
        if ($cy['status'] === 'open') { $cycle_id = $cy['cycle_id']; break; }
    }
}
$selected_cycle = null;
foreach ($all_cycles as $cy) {
    if ($cy['cycle_id'] === $cycle_id) { $selected_cycle = $cy; break; }
}

// -- Build query -----------------------------------------------
$where  = "WHERE u.role = 'faculty'";
$params = [];
if ($cycle_id) { $where .= ' AND a.cycle_id = ?'; $params[] = $cycle_id; }
if ($status && in_array($status, ['submitted','under_review','approved','rejected','reclassified','admin_rejected'])) {
    $where .= ' AND a.status = ?'; $params[] = $status;
}
if ($campus) { $where .= ' AND u.campus_id = ?'; $params[] = $campus; }

$apps = $pdo->prepare("
    SELECT a.*, u.full_name, u.first_name, u.middle_name, u.last_name, u.employee_id, u.rank,
           COALESCE(camp.campus_name, '-') as campus_name,
           c.cycle_name,
           chk.checker_label as checker_name, chk.user_id as checker_uid
    FROM applications a
    JOIN users u ON a.user_id = u.user_id
    LEFT JOIN campuses camp ON u.campus_id = camp.campus_id
    LEFT JOIN cycles c ON a.cycle_id = c.cycle_id
    LEFT JOIN users chk ON a.checker_id = chk.user_id
    $where
    ORDER BY camp.campus_name, u.full_name
");
$apps->execute($params);
$apps = $apps->fetchAll();

// -- Summary stats ---------------------------------------------
$total_faculty = $pdo->query("SELECT COUNT(*) FROM users WHERE role = 'faculty' AND status='active'")->fetchColumn();
$status_counts = [];
foreach ($apps as $a) {
    $status_counts[$a['status']] = ($status_counts[$a['status']] ?? 0) + 1;
}

// -- KRA averages for selected cycle --------------------------
$kra_q = $cycle_id
    ? $pdo->prepare("SELECT ks.kra_category, AVG(ks.computed_points) as avg_pts FROM kra_submissions ks JOIN applications a ON ks.application_id=a.application_id WHERE a.cycle_id=? GROUP BY ks.kra_category")
    : $pdo->prepare("SELECT kra_category, AVG(computed_points) as avg_pts FROM kra_submissions GROUP BY kra_category");
$cycle_id ? $kra_q->execute([$cycle_id]) : $kra_q->execute();
$kra_avgs = ['Instruction'=>0,'Research'=>0,'Extension'=>0,'Professional Development'=>0];
foreach ($kra_q->fetchAll() as $k) $kra_avgs[$k['kra_category']] = round($k['avg_pts'], 2);

$generated_at = date('F d, Y h:i A');
$admin_name   = $_SESSION['full_name'] ?? 'Administrator';
$status_labels = [
    'submitted'      => 'Submitted',
    'under_review'   => 'Under Evaluation',
    'approved'       => 'Evaluation Complete',
    'rejected'       => 'Returned for Revision',
    'reclassified'   => 'Evaluation Complete',
    'admin_rejected' => 'Evaluation Complete',
    'edit_requested' => 'Edit Requested',
    'draft'          => 'Draft',
];
