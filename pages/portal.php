<?php
/**
 * Faculty Portal — standalone page, no sidebar.
 */
if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/functions.php';

if (!isLoggedIn()) { header('Location: login.php'); exit; }
if (($_SESSION['role'] ?? '') !== 'faculty') {
    header('Location: ../index.php'); exit;
}

// Force password change if still using temp password
if (!empty($_SESSION['force_pw_change'])) {
    header('Location: change_password.php');
    exit;
}

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
$rank        = $fac['rank']        ?? '—';
$campus      = $fac['campus_name'] ?? '—';
$email       = $fac['email']       ?? '—';
$employee_id = $fac['employee_id'] ?? '—';
$profile_pic = $fac['profile_pic'] ?? '';
$date_registered = !empty($fac['created_at']) ? date('F j, Y', strtotime($fac['created_at'])) : '—';
$init        = strtoupper(substr($first_name,0,1).substr($last_name,0,1)) ?: 'FA';

$cycle = getActiveCycle($pdo);
$current_app = null;
if ($cycle) {
    $app_row = getOrCreateApplication($pdo, $uid, $cycle['cycle_id']);
    $re = $pdo->prepare("
        SELECT a.*, c.cycle_name, c.submission_deadline
        FROM applications a LEFT JOIN cycles c ON a.cycle_id=c.cycle_id
        WHERE a.application_id=?
    ");
    $re->execute([$app_row['application_id']]);
    $current_app = $re->fetch();
}

$deadline_str    = '—';
$deadline_passed = false;
if ($current_app && !empty($current_app['submission_deadline'])) {
    $dl_ts           = strtotime($current_app['submission_deadline'].' 23:59:59');
    $deadline_passed = time() > $dl_ts;
    $deadline_str    = date('M d, Y', strtotime($current_app['submission_deadline']));
}

function pStatus(string $s): array {
    return match($s) {
        'submitted'      => ['Submitted',      '#1e4d8c','#eff6ff','#bfdbfe'],
        'under_review'   => ['Under Review',   '#1a3a6b','#f0f4fb','#bfdbfe'],
        'talisay_review' => ['Talisay Review', '#1a3a6b','#f0f4fb','#bfdbfe'],
        'needs_revision' => ['Needs Revision', '#475569','#f8fafc','#e2e8f0'],
        'approved'       => ['Approved',       '#1e4d8c','#f0f4fb','#bfdbfe'],
        'reclassified'   => ['Reclassified',   '#1e4d8c','#f0fdfa','#99f6e4'],
        'rejected','admin_rejected' => ['Returned','#1e293b','#f8fafc','#e2e8f0'],
        default          => ['Pending',        '#475569','#f8fafc','#e2e8f0'],
    };
}

// Status & step link
if ($current_app) {
    [$slabel,$scolor,$sbg,$sbd] = pStatus($current_app['status'] ?? 'draft');
    $step_link = ($current_app['status']==='draft' && (float)($current_app['weighted_score']??0)==0)
        ? '../index.php?page=apply'
        : '../index.php?page=apply';
} else {
    [$slabel,$scolor,$sbg,$sbd] = ['Pending','#475569','#f8fafc','#e2e8f0'];
    $step_link = '../index.php?page=apply';
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
<title>SUCFRMS — Faculty Portal</title>
<link href="../assets/vendor/bootstrap-icons/bootstrap-icons.min.css" rel="stylesheet">
<style>
*, *::before, *::after { box-sizing:border-box; margin:0; padding:0; }
body { font-family:'Segoe UI',Arial,sans-serif; background:#f0f3f8; min-height:100vh; color:#1e293b; }

/* Navbar */
.pnav { background:#1a3a6b; height:52px; display:flex; align-items:center; padding:0 24px; gap:10px; position:sticky; top:0; z-index:200; box-shadow:0 2px 8px rgba(0,0,0,0.2); }
.pnav-logo  { width:30px; height:30px; border-radius:50%; object-fit:cover; border:1.5px solid rgba(255,255,255,.3); flex-shrink:0; }
.pnav-title { color:#fff; font-size:.85rem; font-weight:600; flex:1; letter-spacing:.01em; }
.pnav-user  { display:flex; align-items:center; gap:8px; cursor:pointer; position:relative; }
.pnav-name  { color:rgba(255,255,255,.9); font-size:.8rem; font-weight:500; }
.pnav-avatar    { width:32px; height:32px; border-radius:50%; object-fit:cover; border:2px solid rgba(255,255,255,.35); }
.pnav-avatar-ph { width:32px; height:32px; border-radius:50%; background:rgba(255,255,255,.18); border:2px solid rgba(255,255,255,.3); display:flex; align-items:center; justify-content:center; color:#fff; font-size:.65rem; font-weight:700; letter-spacing:1px; }

/* Dropdown */
.pnav-dd { display:none; position:absolute; top:calc(100% + 10px); right:0; background:#fff;
           border:1px solid #e2e8f0; min-width:220px;
           box-shadow:0 8px 24px rgba(0,0,0,.12); z-index:500;
           border-radius:10px; overflow:hidden; }
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
.pg { max-width:900px; margin:0 auto; padding:20px 16px 60px; }

/* CHED Banner */
.ched { background:linear-gradient(90deg,#1a3a6b,#1e4d8c); padding:14px 20px; display:flex; align-items:center; gap:14px; margin-bottom:18px; }
.ched-logo { width:48px; height:48px; border-radius:50%; object-fit:cover; flex-shrink:0; border:2px solid rgba(201,168,76,.5); }
.ched-sm   { color:rgba(255,255,255,.55); font-size:.6rem; line-height:1.5; margin:0; }
.ched-main { color:#fff; font-size:.8rem; font-weight:700; margin:3px 0 0; letter-spacing:.03em; }

/* Section title */
.sec-title { font-size:.9rem; font-weight:700; color:#475569; margin-bottom:14px; }

/* Card */
.card { background:#fff; border:1px solid #dde2ea; max-width:500px; margin:0 auto 14px; }
.card-status { display:flex; justify-content:center; padding:10px 16px 0; }
.status-pill { display:inline-flex; align-items:center; gap:4px; padding:3px 14px; border-radius:0; font-size:.68rem; font-weight:700; border:1px solid; }
.card-logo-row { display:flex; align-items:center; gap:10px; padding:10px 16px 8px; }
.card-logo { width:34px; height:34px; border-radius:50%; object-fit:cover; border:1px solid #e2e8f0; flex-shrink:0; }
.card-cycle-name { font-size:.85rem; font-weight:700; color:#1a3a6b; }
.card-cycle-sub  { font-size:.65rem; color:#94a3b8; margin-top:1px; }
.card-rank-wrap { padding:0 16px 10px; border-bottom:1px solid #eef0f4; }
.card-rank-pill { display:inline-block; padding:2px 10px; background:#eff6ff; border:1px solid #bfdbfe; font-size:.68rem; font-weight:600; color:#1e4d8c; border-radius:3px; margin-bottom:5px; }
.card-rank-bar { height:3px; background:#e2e8f0; border-radius:2px; overflow:hidden; }
.card-rank-fill { height:100%; background:#1a3a6b; border-radius:2px; }
.info { padding:9px 16px; border-bottom:1px solid #f4f5f8; }
.info:last-of-type { border-bottom:none; }
.info-lbl { font-size:.65rem; color:#94a3b8; margin-bottom:2px; }
.info-val { font-size:.875rem; font-weight:600; color:#111; }
.info-val.email-val { color:#1e4d8c; }
.card-action { padding:10px 16px 14px; display:flex; align-items:center; gap:12px; }
.reclass-btn { display:inline-flex; align-items:center; justify-content:center; gap:7px; background:#1a3a6b; color:#fff; border:none; padding:8px 18px; font-size:.82rem; font-weight:600; cursor:pointer; text-decoration:none; transition:background .15s; }
.reclass-btn:hover { background:#1e4d8c; color:#fff; }
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
        <img src="../<?= sanitize($profile_pic) ?>" class="pnav-avatar" alt="">
        <?php else: ?>
        <div class="pnav-avatar-ph"><?= $init ?></div>
        <?php endif; ?>
        <div class="pnav-dd" id="ndd">
            <!-- Header with user info -->
            <div class="pnav-dd-header">
                <div style="display:flex;align-items:center;gap:10px;">
                    <?php if ($profile_pic): ?>
                    <img src="../<?= sanitize($profile_pic) ?>" class="pnav-dd-avatar" alt="">
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
                <a href="../index.php?page=profile"><i class="bi bi-person-circle"></i>My Profile</a>
                <a href="../index.php?page=my_application"><i class="bi bi-file-earmark-text"></i>My Application</a>
                <a href="../pages/logout.php" class="out"><i class="bi bi-box-arrow-right"></i>Sign Out</a>
            </div>
        </div>
    </div>
</nav>

<!-- Body -->
<div class="pg">

    <!-- Section title -->
    <div class="sec-title">Applications for Evaluation</div>

    <!-- Application card — always visible -->
    <div class="card">

        <!-- Logo + cycle name -->
        <div class="card-logo-row">
            <img src="../assets/images/logo.jpg" class="card-logo" alt="">
            <div>
                <div class="card-cycle-name">
                    <?= $cycle ? sanitize($current_app['cycle_name'] ?? 'Current Application') : 'No Active Cycle' ?>
                </div>
                <div class="card-cycle-sub">Reclassification Application</div>
            </div>
        </div>

        <!-- Rank pill + bar — only when cycle active -->
        <?php if ($cycle): ?>
        <div class="card-rank-wrap">
            <div class="card-rank-pill"><?= sanitize($rank) ?></div>
            <div class="card-rank-bar"><div class="card-rank-fill" style="width:<?= $pct ?>%;"></div></div>
        </div>
        <?php endif; ?>

        <!-- Info rows -->
        <div style="height:1px;background:#f1f5f9;margin:0 16px;"></div>
        <div class="info"><div class="info-lbl">Applicant Name</div><div class="info-val"><?= sanitize($full_name) ?></div></div>
        <div class="info"><div class="info-lbl">Present Rank</div><div class="info-val"><?= sanitize($rank) ?></div></div>
        <div class="info"><div class="info-lbl">Application Status</div><div class="info-val"><?= $slabel ?></div></div>
        <div class="info"><div class="info-lbl">Employee ID</div><div class="info-val"><?= sanitize($employee_id) ?></div></div>
        <div class="info"><div class="info-lbl">Campus</div><div class="info-val"><?= sanitize($campus) ?></div></div>
        <div class="info"><div class="info-lbl">Date Registered</div><div class="info-val"><?= $date_registered ?></div></div>        <?php if ($cycle && $current_app): ?>
        <div class="info"><div class="info-lbl">Cycle</div><div class="info-val"><?= sanitize($current_app['cycle_name'] ?? '—') ?></div></div>
        <div class="info"><div class="info-lbl">Submission Deadline</div>
            <div class="info-val" style="color:<?= $deadline_passed ? '#1e293b' : 'inherit' ?>"><?= $deadline_str ?></div>
        </div>
        <?php endif; ?>
        <div class="info"><div class="info-lbl">Email address</div><div class="info-val email-val"><?= sanitize($email) ?></div></div>

        <!-- Action buttons -->
        <div class="card-action">

            <!-- Pre-Evaluation: always active -->
            <a href="../pages/pre_evaluation.php" class="reclass-btn"
               style="background:#fff;color:#1a3a6b;border:1px solid #1a3a6b;flex:1;">
                Self-Assessment
            </a>

            <!-- Reclassification: active only when cycle exists -->
            <?php if ($cycle): ?>
            <a href="<?= $step_link ?>" class="reclass-btn" style="flex:1;">
                Reclassification
            </a>
            <?php else: ?>
            <button onclick="document.getElementById('noCycleModal').style.display='flex'"
                    class="reclass-btn" style="flex:1;">
                Reclassification
            </button>
            <?php endif; ?>

        </div>
    </div>

</div>

<!-- No cycle warning modal -->
<div id="noCycleModal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,0.4);
     z-index:99999;align-items:center;justify-content:center;">
    <div style="background:#fff;max-width:360px;width:90%;padding:2rem 1.5rem;text-align:center;">
        <i class="bi bi-calendar-x" style="font-size:2rem;color:#94a3b8;display:block;margin-bottom:0.75rem;"></i>
        <div style="font-weight:700;color:#1a3a6b;font-size:0.9rem;margin-bottom:0.5rem;">No Active Cycle</div>
        <p style="font-size:0.8rem;color:#64748b;margin-bottom:1.25rem;line-height:1.6;">
            There is currently no open reclassification cycle.<br>
            Please wait for the administrator to open one.<br>
            You may use <strong>Self-Assessment</strong> in the meantime.
        </p>
        <button onclick="document.getElementById('noCycleModal').style.display='none'"
                style="background:#1a3a6b;color:#fff;border:none;padding:0.45rem 1.5rem;
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
