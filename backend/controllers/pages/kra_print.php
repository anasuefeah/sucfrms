<?php
// Request handling and data loading for frontend/pages/kra_print.php
/**
 * ISS - Individual Score Sheet
 * Accessible by faculty (own data) or admin/checker (any faculty via ?uid=)
 */
if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__ . '/../../core/functions.php';
requireLogin();
require_once __DIR__ . '/../../database/config/db.php';
header('Content-Type: text/html; charset=UTF-8');
$target_uid = $_SESSION['user_id'];
if ((isAdmin() || isChecker()) && !empty($_GET['uid'])) {
    $target_uid = intval($_GET['uid']);
}

// Load faculty info
$faculty_stmt = $pdo->prepare(
    "SELECT u.*, c.campus_name FROM users u
     LEFT JOIN campuses c ON u.campus_id = c.campus_id
     WHERE u.user_id = ?"
);
$faculty_stmt->execute([$target_uid]);
$faculty = $faculty_stmt->fetch();
if (!$faculty) { echo '<p>Faculty not found.</p>'; exit; }

// Load application - prefer cycle from ?cycle_id, else active cycle
$cycle_id = intval($_GET['cycle_id'] ?? 0);
if ($cycle_id) {
    $cycle = $pdo->prepare("SELECT * FROM cycles WHERE cycle_id=?");
    $cycle->execute([$cycle_id]);
    $cycle = $cycle->fetch();
} else {
    $cycle = getActiveCycle($pdo);
}
if (!$cycle) { echo '<p>No cycle found.</p>'; exit; }

$app_stmt = $pdo->prepare(
    "SELECT * FROM applications WHERE user_id=? AND cycle_id=? LIMIT 1"
);
$app_stmt->execute([$target_uid, $cycle['cycle_id']]);
$app = $app_stmt->fetch();
if (!$app) { echo '<p>No application found for this cycle.</p>'; exit; }

$app_id = $app['application_id'];

// Load checker info
$checker = null;
if (!empty($app['checker_id'])) {
    $cs = $pdo->prepare("SELECT user_id, checker_label FROM users WHERE user_id=?");
    $cs->execute([$app['checker_id']]);
    $checker_row = $cs->fetch();
    if ($checker_row) $checker = checkerDisplayLabel($checker_row);
}

// Load KRA submissions
$subs_raw = $pdo->prepare(
    "SELECT * FROM kra_submissions WHERE application_id=? ORDER BY kra_category, submitted_at ASC"
);
$subs_raw->execute([$app_id]);
$all_subs = $subs_raw->fetchAll();

$subs_by_cat = [];
foreach ($all_subs as $s) $subs_by_cat[$s['kra_category']][] = $s;

$kra_info = [
    'Instruction'              => ['num'=>'I',   'max'=>100, 'label'=>'Teaching Effectiveness'],
    'Research'                 => ['num'=>'II',  'max'=>100, 'label'=>'Research, Innovation & Creative Work'],
    'Extension'                => ['num'=>'III', 'max'=>120, 'label'=>'Extension Services'],
    'Professional Development' => ['num'=>'IV',  'max'=>120, 'label'=>'Professional Development'],
];

$rank   = $faculty['rank'] ?? '';
$result = getApplicationScoreSummary($pdo, (int)$app_id);
$kra_totals = [
    'Instruction'              => $result['kra1'],
    'Research'                 => $result['kra2'],
    'Extension'                => $result['kra3'],
    'Professional Development' => $result['kra4'],
];
$grand_total = $result['grand_total'];

$weights = $result['weights'];
$printed_at = date('F d, Y h:i A');
