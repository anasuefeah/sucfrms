<?php
/**
 * Faculty Portal  -  standalone page, no sidebar.
 */
if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__ . '/../../backend/database/config/db.php';
require_once __DIR__ . '/../../backend/core/functions.php';

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
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1.0">
<title>SUCFRMS  -  Faculty Portal</title>
<link href="../assets/vendor/bootstrap-icons/bootstrap-icons.min.css" rel="stylesheet">
<style>
*, *::before, *::after { box-sizing:border-box; margin:0; padding:0; }
body { font-family:'Segoe UI',Arial,sans-serif; background:#f8fafc; min-height:100vh; color:#1e293b; }

/* Navbar */
.pnav { background:#1a3a6b; height:52px; display:flex; align-items:center; padding:0 24px; gap:10px; position:sticky; top:0; z-index:200; box-shadow:0 2px 8px rgba(15,23,42,.18); }
.pnav-logo  { width:30px; height:30px; border-radius:50%; object-fit:cover; border:1.5px solid rgba(255,255,255,.35); flex-shrink:0; }
.pnav-title { color:#fff; font-size:.85rem; font-weight:600; flex:1; letter-spacing:.01em; }
.pnav-user  { display:flex; align-items:center; gap:8px; cursor:pointer; position:relative; }
.pnav-name  { color:rgba(255,255,255,.9); font-size:.8rem; font-weight:500; }
.pnav-avatar    { width:32px; height:32px; border-radius:50%; object-fit:cover; border:2px solid rgba(255,255,255,.35); }
.pnav-avatar-ph { width:32px; height:32px; border-radius:50%; background:rgba(255,255,255,.18); border:2px solid rgba(255,255,255,.3); display:flex; align-items:center; justify-content:center; color:#fff; font-size:.65rem; font-weight:700; letter-spacing:1px; }

/* Dropdown */
.pnav-dd { display:none; position:absolute; top:calc(100% + 10px); right:0; background:#fff;
           border:1px solid #e2e8f0; min-width:220px;
           box-shadow:0 12px 32px rgba(15,23,42,.14); z-index:500;
           border-radius:8px; overflow:hidden; }
.pnav-dd.open { display:block; }
.pnav-dd-header { background:linear-gradient(135deg,#1a3a6b,#1e4d8c); padding:12px 16px; }
.pnav-dd-avatar { width:38px; height:38px; border-radius:50%; object-fit:cover; border:2px solid rgba(255,255,255,.4); flex-shrink:0; }
.pnav-dd-avatar-ph { width:38px; height:38px; border-radius:50%; background:rgba(255,255,255,.2); border:2px solid rgba(255,255,255,.3); display:flex; align-items:center; justify-content:center; color:#fff; font-size:.75rem; font-weight:700; flex-shrink:0; }
.pnav-dd-name { color:#fff; font-size:.82rem; font-weight:700; line-height:1.2; }
.pnav-dd-email { color:rgba(255,255,255,.6); font-size:.68rem; margin-top:1px; }
.pnav-dd-body a { display:flex; align-items:center; gap:10px; padding:10px 16px; font-size:.82rem; color:#1e293b; text-decoration:none; border-bottom:1px solid #f1f5f9; transition:background .12s; }
.pnav-dd-body a:last-child { border-bottom:none; }
.pnav-dd-body a:hover { background:#f8fafc; }
.pnav-dd-body a i { color:#1a3a6b; font-size:.85rem; width:16px; text-align:center; }
.pnav-dd-body a.out { color:#dc2626; }
.pnav-dd-body a.out i { color:#dc2626; }
.pnav-dd-body a.warn { color:#b45309; background:#fffbeb; }
.pnav-dd-body a.warn i { color:#d97706; }
.pnav-dd-body a.warn:hover { background:#fef3c7; }

/* Page */
.pg { max-width:1000px; margin:0 auto; padding:2rem 1.25rem 3rem; display:grid; grid-template-columns:1fr 300px; gap:1.25rem; align-items:start; }

/* Main card */
.portal-card { background:#fff; border:1px solid #cbd5e1; overflow:hidden; box-shadow:0 2px 8px rgba(15,23,42,.07); }

/* Card header */
.card-head { background:#1a3a6b; padding:1rem 1.5rem; display:flex; align-items:center; gap:1rem; }
.card-head-logo { width:40px; height:40px; border-radius:50%; object-fit:cover; border:2px solid rgba(255,255,255,.3); flex-shrink:0; }
.card-head-title { font-size:0.95rem; font-weight:700; color:#fff; line-height:1.3; }
.card-head-sub   { font-size:0.7rem; color:rgba(255,255,255,.6); margin-top:2px; }

/* Tab row */
.card-tabs { display:flex; border-bottom:1px solid #e2e8f0; padding:0 1.5rem; background:#f8fafc; }
.card-tab  { padding:0.6rem 0.85rem; font-size:0.75rem; font-weight:700; color:#64748b; border-bottom:2px solid transparent; cursor:default; }
.card-tab.active { color:#1a3a6b; border-bottom-color:#1a3a6b; }

/* Info table */
.info-table { width:100%; border-collapse:collapse; }
.info-table tr { border-bottom:1px solid #f1f5f9; }
.info-table tr:last-child { border-bottom:none; }
.info-table td { padding:0.75rem 1.5rem; font-size:0.82rem; vertical-align:middle; }
.info-table td:first-child { width:38%; font-size:0.68rem; font-weight:700; text-transform:uppercase; letter-spacing:0.06em; color:#94a3b8; }
.info-table td:last-child  { font-weight:600; color:#1e293b; }

/* Status pill */
.status-pill { display:inline-flex; align-items:center; gap:5px; padding:3px 10px; border-radius:3px; font-size:0.72rem; font-weight:700; border:1px solid; }

/* Action row */
.card-actions { padding:1.1rem 1.5rem; display:flex; gap:0.75rem; background:#f8fafc; border-top:1px solid #e2e8f0; }
.act-btn { display:inline-flex; align-items:center; justify-content:center; gap:0.4rem; padding:0.65rem 1.25rem; font-size:0.82rem; font-weight:700; border-radius:3px; text-decoration:none; cursor:pointer; border:1px solid; flex:1; transition:opacity .15s; }
.act-btn:hover { opacity:0.88; }

/* Right panel */
.side-panel { display:flex; flex-direction:column; gap:1.25rem; }
.side-box { background:#fff; border:1px solid #cbd5e1; overflow:hidden; box-shadow:0 2px 8px rgba(15,23,42,.07); }
.side-box-head { background:#f8fafc; border-bottom:1px solid #e2e8f0; padding:0.65rem 1rem; font-size:0.65rem; font-weight:700; text-transform:uppercase; letter-spacing:0.08em; color:#64748b; }
.side-box-body { padding:1.1rem 1rem; }

@media(max-width:760px){
    .pg{grid-template-columns:1fr;padding:1rem .75rem 2.5rem;}
    .pnav{padding:0 12px;}
    .pnav-name{display:none;}
    .card-actions{flex-direction:column;}
    .info-table td:first-child{width:45%;}
}
</style>
</head>
<body>

<!-- Navbar -->
<nav class="pnav">
    <img src="../assets/images/logo.jpg" class="pnav-logo" alt="Logo">
    <span class="pnav-title">SUC Faculty Reclassification Management System (SUCFRMS)</span>
    <div class="pnav-user" id="nu" onclick="toggleDD()">
        <span class="pnav-name"><?= sanitize($full_name) ?></span>
        <?php if ($profile_pic): ?>
        <img src="../../<?= sanitize($profile_pic) ?>" class="pnav-avatar" alt="">
        <?php else: ?>
        <div class="pnav-avatar-ph"><?= $init ?></div>
        <?php endif; ?>
        <div class="pnav-dd" id="ndd">
            <!-- Header with user info -->
            <div class="pnav-dd-header">
                <div style="display:flex;align-items:center;gap:10px;">
                    <?php if ($profile_pic): ?>
                    <img src="../../<?= sanitize($profile_pic) ?>" class="pnav-dd-avatar" alt="">
                    <?php else: ?>
                    <div class="pnav-dd-avatar-ph"><?= $init ?></div>
                    <?php endif; ?>
                    <div>
                        <div class="pnav-dd-name"><?= sanitize($full_name) ?></div>
                        <div class="pnav-dd-email"><?= sanitize($email) ?></div>
                    </div>
                </div>
            </div>
            <!-- Links -->
            <div class="pnav-dd-body">
                <a href="../../index.php?page=profile"><i class="bi bi-person-circle"></i>My Profile</a>
                <a href="../../index.php?page=my_application"><i class="bi bi-file-earmark-text"></i>My Application</a>
                <a href="../../backend/auth/logout.php" class="out"><i class="bi bi-box-arrow-right"></i>Sign Out</a>
            </div>
        </div>
    </div>
</nav>

<!-- Body -->
<div class="pg">


    <div class="portal-card">

        <!-- Card header -->
        <div class="card-head">
            <img src="../assets/images/logo.jpg" class="card-head-logo" alt="">
            <div>
                <div class="card-head-title">
                    <?= $cycle ? sanitize($current_app['cycle_name'] ?? 'Active Cycle') : 'No Active Reclassification Cycle' ?>
                </div>
                <div class="card-head-sub">Reclassification Application &nbsp;|&nbsp; SUCFRMS</div>
            </div>
        </div>

        <!-- Tab row -->
        <div class="card-tabs">
            <div class="card-tab active"><?= sanitize($rank) ?></div>
        </div>

        <!-- Info table -->
        <table class="info-table">
            <tr>
                <td>Applicant Name</td>
                <td><?= sanitize($full_name) ?></td>
            </tr>
            <tr>
                <td>Present Rank</td>
                <td><?= sanitize($rank) ?></td>
            </tr>
            <tr>
                <td>Application Status</td>
                <td>
                    <?php [$sl,$sc,$sbg,$sbd] = pStatus($current_app['status'] ?? 'draft'); ?>
                    <span class="status-pill" style="background:<?= $sbg ?>;color:<?= $sc ?>;border-color:<?= $sbd ?>;">
                        <?= $sl ?>
                    </span>
                </td>
            </tr>
            <tr>
                <td>Employee ID</td>
                <td><?= sanitize($employee_id) ?></td>
            </tr>
            <tr>
                <td>Campus</td>
                <td><?= sanitize($campus) ?></td>
            </tr>
            <tr>
                <td>Date Registered</td>
                <td><?= $date_registered ?></td>
            </tr>
            <?php if ($cycle && $current_app): ?>
            <tr>
                <td>Cycle</td>
                <td><?= sanitize($current_app['cycle_name'] ?? '—') ?></td>
            </tr>
            <tr>
                <td>Submission Deadline</td>
                <td>
                    <span style="color:<?= $deadline_passed ? '#dc2626' : '#1e293b' ?>;"><?= $deadline_str ?></span>
                    <?php if (!$deadline_passed && !empty($current_app['submission_deadline'])): ?>
                    <?php $days_left = (int)ceil((strtotime($current_app['submission_deadline'].' 23:59:59') - time()) / 86400); ?>
                    <span style="font-size:0.72rem;color:<?= $days_left<=7?'#dc2626':'#64748b' ?>;font-weight:400;margin-left:8px;">
                        (<?= $days_left ?> day<?= $days_left===1?'':'s' ?> remaining)
                    </span>
                    <?php elseif ($deadline_passed): ?>
                    <span style="font-size:0.72rem;color:#dc2626;margin-left:8px;">(Deadline passed)</span>
                    <?php endif; ?>
                </td>
            </tr>
            <?php endif; ?>
            <tr>
                <td>Email Address</td>
                <td><a href="mailto:<?= sanitize($email) ?>" style="color:#1e4d8c;text-decoration:none;"><?= sanitize($email) ?></a></td>
            </tr>
        </table>

        <!-- Action buttons -->
        <div class="card-actions">
            <a href="pre_evaluation.php"
               class="act-btn"
               style="background:#fff;color:#1a3a6b;border-color:#1a3a6b;">
                <i class="bi bi-calculator"></i> Self-Assessment
            </a>
            <?php if ($cycle): ?>
            <a href="<?= $step_link ?>"
               class="act-btn"
               style="background:#1a3a6b;color:#fff;border-color:#1a3a6b;">
                <i class="bi bi-file-earmark-text"></i> Reclassification
            </a>
            <?php else: ?>
            <button onclick="document.getElementById('noCycleModal').style.display='flex'"
                    class="act-btn"
                    style="background:#f1f5f9;color:#94a3b8;border-color:#e2e8f0;cursor:not-allowed;">
                <i class="bi bi-file-earmark-text"></i> Reclassification
            </button>
            <?php endif; ?>
        </div>

    </div><!-- /portal-card -->

    <!-- Right side panel -->
    <div class="side-panel">

        <!-- Rank progress -->
        <div class="side-box">
            <div class="side-box-head">Rank Standing</div>
            <div class="side-box-body">
                <div style="font-size:0.78rem;font-weight:700;color:#1a3a6b;margin-bottom:0.35rem;"><?= sanitize($rank) ?></div>
                <div style="height:6px;background:#e2e8f0;border-radius:2px;overflow:hidden;margin-bottom:0.4rem;">
                    <div style="height:100%;width:<?= $pct ?>%;background:#1a3a6b;border-radius:2px;"></div>
                </div>
                <div style="font-size:0.68rem;color:#94a3b8;">Step <?= ($ri !== false ? $ri+1 : '—') ?> of <?= count($all_ranks) ?> in the rank scale</div>
            </div>
        </div>

        <!-- Cycle status -->
        <div class="side-box">
            <div class="side-box-head">Cycle Status</div>
            <div class="side-box-body">
                <?php if ($cycle): ?>
                <div style="font-size:0.78rem;font-weight:700;color:#1a3a6b;margin-bottom:0.5rem;">
                    <?= sanitize($current_app['cycle_name'] ?? '—') ?>
                </div>
                <?php if (!$deadline_passed && !empty($current_app['submission_deadline'])): ?>
                <?php $days_left = (int)ceil((strtotime($current_app['submission_deadline'].' 23:59:59') - time()) / 86400); ?>
                <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:0.35rem;">
                    <span style="font-size:0.68rem;color:#94a3b8;text-transform:uppercase;letter-spacing:0.05em;">Days Remaining</span>
                    <span style="font-size:1.1rem;font-weight:800;color:<?= $days_left<=7?'#dc2626':($days_left<=14?'#d97706':'#1a3a6b') ?>;"><?= $days_left ?></span>
                </div>
                <div style="height:4px;background:#e2e8f0;border-radius:2px;overflow:hidden;">
                    <?php
                        $total_days = max(1, (int)ceil((strtotime($current_app['submission_deadline'].' 23:59:59') - strtotime($current_app['submission_start_date'] ?? $current_app['submission_deadline'])) / 86400));
                        $elapsed_pct = min(100, max(0, 100 - round(($days_left / $total_days) * 100)));
                    ?>
                    <div style="height:100%;width:<?= $elapsed_pct ?>%;background:<?= $days_left<=7?'#dc2626':($days_left<=14?'#d97706':'#1a3a6b') ?>;border-radius:2px;"></div>
                </div>
                <div style="font-size:0.68rem;color:#94a3b8;margin-top:0.35rem;">Deadline: <?= $deadline_str ?></div>
                <?php elseif ($deadline_passed): ?>
                <div style="font-size:0.75rem;color:#dc2626;font-weight:600;">Submission deadline has passed.</div>
                <?php endif; ?>
                <?php else: ?>
                <div style="font-size:0.78rem;color:#94a3b8;">No active cycle at this time.</div>
                <div style="font-size:0.72rem;color:#cbd5e1;margin-top:0.35rem;">Use Self-Assessment to prepare while waiting.</div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Quick links -->
        <div class="side-box">
            <div class="side-box-head">Quick Access</div>
            <div style="display:flex;flex-direction:column;">
                <a href="../../index.php?page=my_application"
                   style="display:flex;align-items:center;gap:0.6rem;padding:0.75rem 1rem;border-bottom:1px solid #f1f5f9;text-decoration:none;color:#1e293b;font-size:0.81rem;font-weight:600;transition:background .12s;"
                   onmouseover="this.style.background='#f8fafc'" onmouseout="this.style.background=''">
                    <i class="bi bi-file-earmark-text" style="color:#1a3a6b;width:15px;text-align:center;font-size:0.85rem;"></i>
                    Application Status
                </a>
                <a href="../../index.php?page=apply"
                   style="display:flex;align-items:center;gap:0.6rem;padding:0.75rem 1rem;border-bottom:1px solid #f1f5f9;text-decoration:none;color:#1e293b;font-size:0.81rem;font-weight:600;transition:background .12s;"
                   onmouseover="this.style.background='#f8fafc'" onmouseout="this.style.background=''">
                    <i class="bi bi-ui-checks-grid" style="color:#1a3a6b;width:15px;text-align:center;font-size:0.85rem;"></i>
                    KRA Entry
                </a>
                <a href="../../index.php?page=appeals"
                   style="display:flex;align-items:center;gap:0.6rem;padding:0.75rem 1rem;border-bottom:1px solid #f1f5f9;text-decoration:none;color:#1e293b;font-size:0.81rem;font-weight:600;transition:background .12s;"
                   onmouseover="this.style.background='#f8fafc'" onmouseout="this.style.background=''">
                    <i class="bi bi-chat-square-text" style="color:#1a3a6b;width:15px;text-align:center;font-size:0.85rem;"></i>
                    Appeals
                </a>
                <a href="../../index.php?page=my_audit"
                   style="display:flex;align-items:center;gap:0.6rem;padding:0.75rem 1rem;border-bottom:1px solid #f1f5f9;text-decoration:none;color:#1e293b;font-size:0.81rem;font-weight:600;transition:background .12s;"
                   onmouseover="this.style.background='#f8fafc'" onmouseout="this.style.background=''">
                    <i class="bi bi-clock-history" style="color:#1a3a6b;width:15px;text-align:center;font-size:0.85rem;"></i>
                    Activity Log
                </a>
                <a href="../../index.php?page=help"
                   style="display:flex;align-items:center;gap:0.6rem;padding:0.75rem 1rem;border-bottom:1px solid #f1f5f9;text-decoration:none;color:#1e293b;font-size:0.81rem;font-weight:600;transition:background .12s;"
                   onmouseover="this.style.background='#f8fafc'" onmouseout="this.style.background=''">
                    <i class="bi bi-question-circle" style="color:#1a3a6b;width:15px;text-align:center;font-size:0.85rem;"></i>
                    Tutorial / Help
                </a>
                <a href="../../backend/auth/logout.php"
                   style="display:flex;align-items:center;gap:0.6rem;padding:0.75rem 1rem;text-decoration:none;color:#dc2626;font-size:0.81rem;font-weight:600;transition:background .12s;"
                   onmouseover="this.style.background='#fef2f2'" onmouseout="this.style.background=''">
                    <i class="bi bi-box-arrow-right" style="color:#dc2626;width:15px;text-align:center;font-size:0.85rem;"></i>
                    Sign Out
                </a>
            </div>
        </div>

    </div><!-- /side-panel -->

</div><!-- /pg -->

<!-- No cycle warning modal -->
<div id="noCycleModal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,0.4);
     z-index:99999;align-items:center;justify-content:center;">
    <div style="background:#fff;max-width:360px;width:90%;padding:2rem 1.5rem;text-align:center;border-radius:8px;box-shadow:0 20px 60px rgba(15,23,42,.22);">
        <i class="bi bi-calendar-x" style="font-size:2rem;color:#94a3b8;display:block;margin-bottom:0.75rem;"></i>
        <div style="font-weight:700;color:#1a3a6b;font-size:0.9rem;margin-bottom:0.5rem;">No Active Cycle</div>
        <p style="font-size:0.8rem;color:#64748b;margin-bottom:1.25rem;line-height:1.6;">
            There is currently no open reclassification cycle.<br>
            Please wait for the administrator to open one.<br>
            You may use <strong>Self-Assessment</strong> in the meantime.
        </p>
        <button onclick="document.getElementById('noCycleModal').style.display='none'"
                style="background:#1e4d8c;color:#fff;border:none;border-radius:7px;padding:0.45rem 1.5rem;
                       font-size:0.82rem;font-weight:600;cursor:pointer;">
            OK
        </button>
    </div>
</div>

<script>
function toggleDD() { document.getElementById('ndd').classList.toggle('open'); }
document.addEventListener('click', function(e) {
    const n = document.getElementById('nu'), d = document.getElementById('ndd');
    if (d && n && !n.contains(e.target)) d.classList.remove('open');
});
</script>
</body>
</html>
