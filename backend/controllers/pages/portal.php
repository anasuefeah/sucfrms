<?php
// Request handling and data loading for frontend/pages/portal.php
/**
 * Faculty Portal  -  standalone page, no sidebar.
 */
if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__ . '/../../database/config/db.php';
require_once __DIR__ . '/../../core/functions.php';

if (!isLoggedIn()) { header('Location: login.php'); exit; }
if (($_SESSION['role'] ?? '') !== 'faculty') {
    header('Location: ../../index.php'); exit;
}

// If profile not yet completed, send to profile entry first
$_pcheck = $pdo->prepare("SELECT profile_completed FROM users WHERE user_id=?");
$_pcheck->execute([$_SESSION['user_id']]);
if (!(int)$_pcheck->fetchColumn()) {
    header('Location: ../../index.php?page=profile_entry'); exit;
}

unset($_SESSION['force_pw_change']);

$uid = $_SESSION['user_id'];

$fac = $pdo->prepare("
    SELECT u.first_name, u.last_name, u.full_name, u.middle_name, u.email,
           u.employee_id, u.rank, u.profile_pic, u.created_at, c.campus_name
    FROM users u LEFT JOIN campuses c ON u.campus_id = c.campus_id
    WHERE u.user_id = ?
");
$fac->execute([$uid]);
$fac         = $fac->fetch();
$full_name = formatDisplayName($fac);
$first_name  = $fac['first_name']  ?? '';
$last_name   = $fac['last_name']   ?? '';
$rank        = $fac['rank']        ?? ' - ';
$campus      = $fac['campus_name'] ?? ' - ';
$email       = $fac['email']       ?? ' - ';
$employee_id = $fac['employee_id'] ?? ' - ';
$profile_pic = $fac['profile_pic'] ?? '';
$date_registered = !empty($fac['created_at']) ? date('F j, Y', strtotime($fac['created_at'])) : ' - ';
$init        = strtoupper(substr($first_name,0,1).substr($last_name,0,1)) ?: 'FA';

$cycle = getActiveCycle($pdo);
$current_app = null;
if ($cycle) {
    $app_row = getOrCreateApplication($pdo, $uid, $cycle['cycle_id']);
    $re = $pdo->prepare("
        SELECT a.*, c.cycle_name, c.submission_start_date, c.submission_deadline
        FROM applications a LEFT JOIN cycles c ON a.cycle_id=c.cycle_id
        WHERE a.application_id=?
    ");
    $re->execute([$app_row['application_id']]);
    $current_app = $re->fetch();
}

$deadline_str    = ' - ';
$deadline_passed = false;
if ($current_app && !empty($current_app['submission_deadline'])) {
    $effective_deadline = getEffectiveSubmissionDeadline($pdo, (int)$current_app['cycle_id'], (int)$uid, $current_app['submission_deadline']);
    $dl_ts           = strtotime($effective_deadline.' 23:59:59');
    $deadline_passed = time() > $dl_ts;
    $deadline_str    = date('M d, Y', strtotime($effective_deadline));
}

function pStatus(string $s): array {
    return match($s) {
        'submitted'      => ['Submitted',      '#1e4d8c','#eff6ff','#bfdbfe'],
        'under_review'   => ['Under Review',   '#1a3a6b','#f0f4fb','#bfdbfe'],
        // Display name for stored status value 'talisay_review' is "ITC Review".
        'talisay_review' => ['ITC Review', '#1a3a6b','#f0f4fb','#bfdbfe'],
        'needs_revision' => ['Needs Revision', '#475569','#f8fafc','#e2e8f0'],
        'approved'       => ['Evaluation Complete', '#1e4d8c','#f0f4fb','#bfdbfe'],
        'reclassified'   => ['Evaluation Complete', '#1e4d8c','#f0f4fb','#bfdbfe'],
        'rejected','admin_rejected' => ['Returned for Revision','#1e293b','#f8fafc','#e2e8f0'],
        default          => ['Pending',        '#475569','#f8fafc','#e2e8f0'],
    };
}

// Status & step link
if ($current_app) {
    [$slabel,$scolor,$sbg,$sbd] = pStatus($current_app['status'] ?? 'draft');
    $step_link = ($current_app['status']==='draft' && (float)($current_app['weighted_score']??0)==0)
        ? '../../index.php?page=apply'
        : '../../index.php?page=apply';
} else {
    [$slabel,$scolor,$sbg,$sbd] = ['Pending','#475569','#f8fafc','#e2e8f0'];
    $step_link = '../../index.php?page=apply';
}

// Rank progress bar
$all_ranks = ['Instructor I','Instructor II','Instructor III',
    'Assistant Professor I','Assistant Professor II','Assistant Professor III','Assistant Professor IV',
    'Associate Professor I','Associate Professor II','Associate Professor III','Associate Professor IV','Associate Professor V',
    'Professor I','Professor II','Professor III','Professor IV','Professor V','Professor VI','University Professor'];
$ri  = array_search($rank, $all_ranks);
$pct = $ri !== false ? round(($ri/(count($all_ranks)-1))*100) : 0;
