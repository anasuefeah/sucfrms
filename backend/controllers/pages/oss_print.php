<?php
// Request handling and data loading for frontend/pages/oss_print.php
/**
 * OSS - Overall Score Summary
 * Admin/checker only. Shows all faculty scores for a given cycle.
 */
if (session_status() === PHP_SESSION_NONE) session_start();
header('Content-Type: text/html; charset=UTF-8');
require_once __DIR__ . '/../../core/functions.php';
requireLogin();
if (!isAdmin() && !isChecker()) { http_response_code(403); die('Access denied.'); }
require_once __DIR__ . '/../../database/config/db.php';

// Filters
$cycle_id  = intval($_GET['cycle_id'] ?? 0);
$campus_id = intval($_GET['campus_id'] ?? 0);
$status    = trim($_GET['status'] ?? '');

// Load cycles
$all_cycles   = $pdo->query("SELECT cycle_id, cycle_name, status, start_date, end_date FROM cycles ORDER BY created_at DESC")->fetchAll();
$all_campuses = $pdo->query("SELECT campus_id, campus_name FROM campuses WHERE is_active=1 ORDER BY campus_name")->fetchAll();

// Default to open cycle
if (!$cycle_id) {
    foreach ($all_cycles as $cy) {
        if ($cy['status'] === 'open') { $cycle_id = $cy['cycle_id']; break; }
    }
}
$selected_cycle = null;
foreach ($all_cycles as $cy) {
    if ($cy['cycle_id'] === $cycle_id) { $selected_cycle = $cy; break; }
}

// Build query
$where  = "WHERE u.role = 'faculty' AND a.cycle_id=?";
$params = [$cycle_id];
if ($campus_id) { $where .= ' AND u.campus_id=?'; $params[] = $campus_id; }
if ($status && in_array($status, ['submitted','under_review','approved','rejected','reclassified'])) {
    $where .= ' AND a.status=?'; $params[] = $status;
}

$rows = $pdo->prepare("
    SELECT a.*, u.full_name, u.first_name, u.middle_name, u.last_name, u.employee_id, u.rank,
           COALESCE(camp.campus_name,'-') as campus_name,
           chk.user_id as checker_uid,
           chk.checker_label as checker_label
    FROM applications a
    JOIN users u ON a.user_id=u.user_id
    LEFT JOIN campuses camp ON u.campus_id=camp.campus_id
    LEFT JOIN users chk ON a.checker_id=chk.user_id
    $where
    ORDER BY camp.campus_name, u.full_name
");
$rows->execute($params);
$applications = $rows->fetchAll();

// Load KRA scores per application
$kra_cats = ['Instruction','Research','Extension','Professional Development'];
$kra_scores = [];
if ($applications) {
    $app_ids = array_column($applications, 'application_id');
    $placeholders = implode(',', array_fill(0, count($app_ids), '?'));
    $ks = $pdo->prepare("SELECT application_id, kra_category, computed_points FROM kra_submissions WHERE application_id IN ($placeholders)");
    $ks->execute($app_ids);
    foreach ($ks->fetchAll() as $k) {
        $kra_scores[$k['application_id']][$k['kra_category']] = (float)$k['computed_points'];
    }
}

// Summary stats
$total = count($applications);
$status_counts = [];
foreach ($applications as $a) $status_counts[$a['status']] = ($status_counts[$a['status']] ?? 0) + 1;

$status_labels = [
    'submitted'    => 'Submitted',
    'under_review' => 'Under Evaluation',
    'approved'     => 'Evaluation Complete',
    'rejected'     => 'Returned for Revision',
    'reclassified' => 'Evaluation Complete',
];

// KRA averages
$kra_avgs = ['Instruction'=>[],'Research'=>[],'Extension'=>[],'Professional Development'=>[]];
foreach ($applications as $a) {
    foreach ($kra_cats as $cat) {
        $kra_avgs[$cat][] = $kra_scores[$a['application_id']][$cat] ?? 0;
    }
}
$kra_avg_vals = [];
foreach ($kra_avgs as $cat => $vals) {
    $kra_avg_vals[$cat] = count($vals) > 0 ? round(array_sum($vals) / count($vals), 2) : 0;
}

$printed_at = date('F d, Y h:i A');
$admin_name = $_SESSION['full_name'] ?? 'Administrator';
