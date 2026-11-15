<?php
/**
 * Faculty Portal  -  standalone page, no sidebar.
 */
if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/functions.php';

if (!isLoggedIn()) { header('Location: login.php'); exit; }
if (($_SESSION['role'] ?? '') !== 'faculty') { header('Location: ../index.php'); exit; }

$_pcheck = $pdo->prepare("SELECT profile_completed FROM users WHERE user_id=?");
$_pcheck->execute([$_SESSION['user_id']]);
if (!(int)$_pcheck->fetchColumn()) { header('Location: ../index.php?page=profile_entry'); exit; }

unset($_SESSION['force_pw_change']);

$uid = $_SESSION['user_id'];

$fac = $pdo->prepare("
    SELECT u.first_name, u.last_name, u.full_name, u.middle_name, u.email,
           u.employee_id, u.`rank`, u.profile_pic, u.created_at, c.campus_name
    FROM users u LEFT JOIN campuses c ON u.campus_id = c.campus_id
    WHERE u.user_id = ?
");
$fac->execute([$uid]);
$fac         = $fac->fetch();
$full_name   = formatDisplayName($fac);
$first_name  = $fac['first_name']  ?? '';
$last_name   = $fac['last_name']   ?? '';
$rank        = $fac['rank']        ?? '-';
$campus      = $fac['campus_name'] ?? '-';
$email       = $fac['email']       ?? '-';
$employee_id = $fac['employee_id'] ?? '-';
$profile_pic = $fac['profile_pic'] ?? '';
$date_registered = !empty($fac['created_at']) ? date('F j, Y', strtotime($fac['created_at'])) : '-';
$init        = strtoupper(substr($first_name,0,1).substr($last_name,0,1)) ?: 'FA';

$cycle = getActiveCycle($pdo);
$current_app = null;
if ($cycle) {
    $app_row = getOrCreateApplication($pdo, $uid, $cycle['cycle_id']);
    $re = $pdo->prepare("SELECT a.*, c.cycle_name, c.submission_start_date, c.submission_deadline
        FROM applications a LEFT JOIN cycles c ON a.cycle_id=c.cycle_id WHERE a.application_id=?");
    $re->execute([$app_row['application_id']]);
    $current_app = $re->fetch();
}

$deadline_str = '-';
$deadline_passed = false;
if ($current_app && !empty($current_app['submission_deadline'])) {
    $effective_deadline = getEffectiveSubmissionDeadline($pdo, (int)$current_app['cycle_id'], (int)$uid, $current_app['submission_deadline']);
    $dl_ts = strtotime($effective_deadline.' 23:59:59');
    $deadline_passed = time() > $dl_ts;
    $deadline_str = date('M d, Y', strtotime($effective_deadline));
}

// ── Education & Employment ───────────────────────────────────
$portal_education   = [];
$portal_employment  = ['current'=>null,'previous'=>null];
try {
    $ed = $pdo->prepare("SELECT * FROM faculty_education WHERE user_id=? ORDER BY FIELD(level,'Bachelor','Master','Doctorate','PostDoctorate'), id");
    $ed->execute([$uid]);
    $portal_education = $ed->fetchAll();
} catch(\Exception $e) {}
try {
    $emp = $pdo->prepare("SELECT * FROM faculty_employment WHERE user_id=? ORDER BY FIELD(type,'current','previous'), id");
    $emp->execute([$uid]);
    foreach ($emp->fetchAll() as $row) $portal_employment[$row['type']] = $row;
} catch(\Exception $e) {}

$edu_labels = ['Bachelor'=>'Undergraduate','Master'=>"Master's",'Doctorate'=>'Doctorate','PostDoctorate'=>'Post-Doctorate'];
$edu_by_level = [];
foreach ($portal_education as $r) $edu_by_level[$r['level']] = $r;

// ── Inline edit POST handler ─────────────────────────────────
if (isset($_POST['portal_edit_action'])) {
    $action = $_POST['portal_edit_action'];
    if ($action === 'update_basic') {
        $fn = trim($_POST['first_name'] ?? '');
        $mn = trim($_POST['middle_name'] ?? '') ?: null;
        $ln = trim($_POST['last_name']   ?? '');
        // Handle profile photo upload
        $pic_path = null;
        if (!empty($_FILES['profile_pic']['name']) && $_FILES['profile_pic']['error'] === UPLOAD_ERR_OK) {
            $ext = strtolower(pathinfo($_FILES['profile_pic']['name'], PATHINFO_EXTENSION));
            if (in_array($ext, ['jpg','jpeg','png','webp'], true) && $_FILES['profile_pic']['size'] <= 2*1024*1024) {
                $dir = __DIR__ . '/../uploads/avatars/';
                if (!is_dir($dir)) mkdir($dir, 0755, true);
                $filename = 'avatar_' . $uid . '_' . time() . '.' . $ext;
                if (move_uploaded_file($_FILES['profile_pic']['tmp_name'], $dir . $filename)) {
                    // Remove old avatar
                    $old = $pdo->prepare("SELECT profile_pic FROM users WHERE user_id=?");
                    $old->execute([$uid]);
                    $old_pic = $old->fetchColumn();
                    if ($old_pic) {
                        $old_abs = realpath(__DIR__ . '/../') . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $old_pic);
                        if (file_exists($old_abs)) @unlink($old_abs);
                    }
                    $pic_path = 'uploads/avatars/' . $filename;
                    $_SESSION['profile_pic'] = $pic_path;
                }
            }
        }
        if ($fn && $ln) {
            if ($pic_path) {
                $pdo->prepare("UPDATE users SET first_name=?,middle_name=?,last_name=?,profile_pic=? WHERE user_id=?")
                    ->execute([$fn,$mn,$ln,$pic_path,$uid]);
            } else {
                $pdo->prepare("UPDATE users SET first_name=?,middle_name=?,last_name=? WHERE user_id=?")
                    ->execute([$fn,$mn,$ln,$uid]);
            }
            $_SESSION['first_name'] = $fn;
            $_SESSION['middle_name'] = $mn ?? '';
            $_SESSION['last_name']  = $ln;
            $_SESSION['full_name']  = formatDisplayName(['first_name'=>$fn,'middle_name'=>$mn??'','last_name'=>$ln]);
        }
    } elseif ($action === 'change_password') {
        $np = $_POST['new_password'] ?? '';
        $cp = $_POST['confirm_password'] ?? '';
        if (strlen($np)>=8 && $np===$cp &&
            preg_match('/[A-Z]/',$np) && preg_match('/[a-z]/',$np) &&
            preg_match('/[0-9]/',$np) && preg_match('/[^a-zA-Z0-9]/',$np)) {
            $pdo->prepare("UPDATE users SET password=? WHERE user_id=?")
                ->execute([password_hash($np,PASSWORD_DEFAULT),$uid]);
            logAudit($pdo,$uid,'Password Change','Password changed from portal.');
        }
    } elseif ($action === 'update_education') {
        $levels  = $_POST['edu_level']  ?? [];
        $degrees = $_POST['edu_degree'] ?? [];
        $schools = $_POST['edu_school'] ?? [];
        $years   = $_POST['edu_year']   ?? [];
        $allowed = ['Bachelor','Master','Doctorate','PostDoctorate'];
        $rows = [];
        foreach ($levels as $i => $lv) {
            $lv = trim((string)$lv);
            if (!in_array($lv, $allowed, true)) continue;
            $deg = trim((string)($degrees[$i] ?? ''));
            $sch = trim((string)($schools[$i] ?? ''));
            $yr  = trim((string)($years[$i]   ?? ''));
            if ($deg === '' && $sch === '' && $yr === '') continue;
            $rows[] = [$uid, $lv, $deg ?: 'N/A', 'N/A', $sch ?: 'N/A', $yr ?: 'N/A', null];
        }
        if ($rows) {
            $pdo->beginTransaction();
            $pdo->prepare("DELETE FROM faculty_education WHERE user_id=?")->execute([$uid]);
            $ins = $pdo->prepare("INSERT INTO faculty_education (user_id,level,degree_program,major_specialization,school_university,year_graduated,honors_units_notes) VALUES (?,?,?,?,?,?,?)");
            foreach ($rows as $r) $ins->execute($r);
            $pdo->commit();
            logAudit($pdo,$uid,'Education Updated','Updated education records from portal.');
        }
    } elseif ($action === 'update_employment') {
        foreach (['current','previous'] as $type) {
            $r = [
                'rank'   => trim($_POST[$type.'_rank']   ?? ''),
                'mode'   => trim($_POST[$type.'_mode']   ?? ''),
                'date'   => trim($_POST[$type.'_date']   ?? '') ?: null,
                'sector' => trim($_POST[$type.'_sector'] ?? '') ?: null,
                'suc'    => trim($_POST[$type.'_suc']    ?? ''),
                'campus' => trim($_POST[$type.'_campus'] ?? ''),
                'addr'   => trim($_POST[$type.'_address']?? ''),
            ];
            // Skip previous entirely if all fields are empty
            if ($type === 'previous' && $r['rank'] === '' && $r['suc'] === '' && $r['addr'] === '') continue;

            $existing = $pdo->prepare("SELECT id FROM faculty_employment WHERE user_id=? AND type=?");
            $existing->execute([$uid,$type]);
            if ($existing->fetch()) {
                $pdo->prepare("UPDATE faculty_employment SET `rank`=?,mode_of_appointment=?,date_of_appointment=?,employment_sector=?,suc=?,campus=?,address=? WHERE user_id=? AND type=?")
                    ->execute([$r['rank'],$r['mode'],$r['date'],$r['sector'],$r['suc'],$r['campus'],$r['addr'],$uid,$type]);
            } else {
                $pdo->prepare("INSERT INTO faculty_employment (user_id,type,`rank`,mode_of_appointment,date_of_appointment,employment_sector,suc,campus,address) VALUES (?,?,?,?,?,?,?,?,?)")
                    ->execute([$uid,$type,$r['rank'],$r['mode'],$r['date'],$r['sector'],$r['suc'],$r['campus'],$r['addr']]);
            }
        }
        logAudit($pdo,$uid,'Employment Updated','Updated employment records from portal.');
    }
    header('Location: '.$_SERVER['REQUEST_URI']); exit;
}

function pStatus(string $s): array {
    return match($s) {
        'submitted'      => ['Submitted',            '#1e4d8c','#eff6ff','#bfdbfe'],
        'under_review'   => ['Under Review',          '#1a3a6b','#f0f4fb','#bfdbfe'],
        'talisay_review' => ['ITC Review',            '#1a3a6b','#f0f4fb','#bfdbfe'],
        'needs_revision' => ['Needs Revision',        '#92400e','#fffbeb','#fde68a'],
        'approved'       => ['Evaluation Complete',   '#166534','#f0fdf4','#bbf7d0'],
        'reclassified'   => ['Evaluation Complete',   '#166534','#f0fdf4','#bbf7d0'],
        'rejected','admin_rejected' => ['Returned for Revision','#475569','#f8fafc','#e2e8f0'],
        default          => ['Pending',               '#475569','#f8fafc','#e2e8f0'],
    };
}

if ($current_app) {
    [$slabel,$scolor,$sbg,$sbd] = pStatus($current_app['status'] ?? 'draft');
    $step_link = '../index.php?page=apply';
} else {
    [$slabel,$scolor,$sbg,$sbd] = ['Pending','#475569','#f8fafc','#e2e8f0'];
    $step_link = '../index.php?page=apply';
}

$all_ranks = ['Instructor I','Instructor II','Instructor III',
    'Assistant Professor I','Assistant Professor II','Assistant Professor III','Assistant Professor IV',
    'Associate Professor I','Associate Professor II','Associate Professor III','Associate Professor IV','Associate Professor V',
    'Professor I','Professor II','Professor III','Professor IV','Professor V','Professor VI','University Professor'];
$ri  = array_search($rank, $all_ranks);
$pct = $ri !== false ? round(($ri / (count($all_ranks) - 1)) * 100) : 0;
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1.0">
<title>SUCFRMS — Faculty Portal</title>
<link href="../assets/vendor/bootstrap-icons/bootstrap-icons.min.css" rel="stylesheet">
<style>
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0;}
body{font-family:'Segoe UI',system-ui,sans-serif;background:#edf0f5;min-height:100vh;color:#1e293b;}
body::before,body::after{display:none!important;content:none!important;}

/* ── Topbar ── */
.topbar{height:52px;background:#0f2952;display:flex;align-items:center;padding:0 2rem;gap:12px;position:sticky;top:0;z-index:300;}
.topbar-logo{width:28px;height:28px;border-radius:50%;object-fit:cover;border:1.5px solid rgba(255,255,255,.3);flex-shrink:0;}
.topbar-title{color:#fff;font-size:.82rem;font-weight:600;flex:1;letter-spacing:.01em;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;}
.topbar-user{display:flex;align-items:center;gap:8px;cursor:pointer;position:relative;}
.topbar-name{color:rgba(255,255,255,.85);font-size:.78rem;font-weight:500;}
.topbar-av{width:30px;height:30px;border-radius:50%;background:rgba(255,255,255,.15);border:2px solid rgba(255,255,255,.3);display:flex;align-items:center;justify-content:center;color:#fff;font-size:.62rem;font-weight:700;}
.dd{display:none;position:absolute;top:calc(100%+8px);right:0;background:#fff;border:1px solid #e2e8f0;min-width:220px;box-shadow:0 12px 32px rgba(0,0,0,.15);z-index:500;}
.dd.open{display:block;}
.dd-hd{background:#0f2952;padding:12px 14px;display:flex;align-items:center;gap:10px;}
.dd-av{width:36px;height:36px;border-radius:50%;background:rgba(255,255,255,.2);border:2px solid rgba(255,255,255,.3);display:flex;align-items:center;justify-content:center;color:#fff;font-size:.7rem;font-weight:700;}
.dd-nm{color:#fff;font-size:.82rem;font-weight:700;}
.dd-em{color:rgba(255,255,255,.5);font-size:.65rem;margin-top:2px;}
.dd a{display:flex;align-items:center;gap:10px;padding:10px 14px;font-size:.8rem;color:#1e293b;text-decoration:none;border-bottom:1px solid #f1f5f9;transition:background .1s;}
.dd a:last-child{border-bottom:none;}
.dd a:hover{background:#f8fafc;}
.dd a i{color:#1a3a6b;font-size:.82rem;width:16px;text-align:center;}
.dd a.out{color:#dc2626;}.dd a.out i{color:#dc2626;}

/* ── Hero ── */
.hero{background:linear-gradient(rgba(255,255,255,0.18),rgba(255,255,255,0.18)),url('../assets/images/portal_hero.jpg') center center/cover no-repeat;padding:2.5rem 2rem 0;position:relative;overflow:hidden;}
.hero::before{display:none;}
.hero-inner{max-width:1100px;margin:0 auto;display:flex;align-items:flex-end;justify-content:space-between;gap:2rem;position:relative;z-index:1;}
.hero-left{display:flex;align-items:flex-end;gap:1.25rem;padding-bottom:2rem;}
.hero-avatar-wrap{width:80px;height:80px;border-radius:50%;border:3px solid rgba(255,255,255,.6);overflow:hidden;flex-shrink:0;background:#1a3a6b;display:flex;align-items:center;justify-content:center;color:#fff;font-size:1.6rem;font-weight:700;}
.hero-name{color:#0f172a;font-size:1.6rem;font-weight:800;line-height:1.15;letter-spacing:-.02em;text-shadow:0 1px 4px rgba(255,255,255,.9);}
.hero-sub{color:#1e293b;font-size:.82rem;margin-top:5px;text-shadow:0 1px 2px rgba(255,255,255,.7);}
.hero-school{color:#1a3a6b;font-size:.75rem;font-weight:600;margin-top:3px;text-shadow:0 1px 2px rgba(255,255,255,.8);}
.hero-badge{display:none;}
.hero-right{padding-bottom:2rem;text-align:right;flex-shrink:0;}
.hero-score-label{color:rgba(255,255,255,.45);font-size:.62rem;text-transform:uppercase;letter-spacing:.09em;}
.hero-score{color:#fff;font-size:2.75rem;font-weight:800;line-height:1;letter-spacing:-2px;margin-top:3px;}
.hero-score sup{font-size:1rem;font-weight:400;color:rgba(255,255,255,.4);letter-spacing:0;vertical-align:super;}
.rank-bar-wrap{display:none;}

/* ── Page wrap ── */
.pg{max-width:1100px;margin:0 auto;padding:.85rem 2rem 1rem;}

/* ── Grid ── */
.grid{display:grid;grid-template-columns:1fr 290px;gap:1rem;align-items:stretch;}
.grid > div:first-child{display:flex;flex-direction:column;}
.grid > div:first-child > .card{flex:1;display:flex;flex-direction:column;}

/* ── Cards ── */
.card{background:#fff;border:1px solid #e2e8f0;}
.card-head{padding:.5rem 1rem;border-bottom:1px solid #e2e8f0;display:flex;align-items:center;justify-content:space-between;background:#fff;}
.card-title{font-size:.58rem;font-weight:700;text-transform:uppercase;letter-spacing:.09em;color:#94a3b8;}

/* ── Faculty info ── */
.fi-table{width:100%;border-collapse:collapse;}
.fi-table tr{border-bottom:1px solid #f1f5f9;}
.fi-table tr:last-child{border-bottom:none;}
.fi-table td{padding:.7rem 1rem;vertical-align:middle;}
.fi-table td.lbl{font-size:.54rem;font-weight:700;text-transform:uppercase;letter-spacing:.07em;color:#94a3b8;width:130px;white-space:nowrap;}
.fi-table td.val{font-size:.82rem;font-weight:600;color:#1e293b;}
.fi-table td.sep{width:1px;background:#f1f5f9;padding:0;}

/* ── Pill ── */
.pill{display:inline-flex;align-items:center;gap:4px;padding:2px 8px;font-size:.67rem;font-weight:700;border:1px solid;}

/* ── Actions ── */
.act-row{padding:.5rem 1rem;display:flex;gap:.5rem;border-top:1px solid #e2e8f0;}
.btn-outline{display:inline-flex;align-items:center;justify-content:center;gap:.4rem;padding:.45rem .9rem;font-size:0.72rem;font-weight:600;border:1px solid #e2e8f0;color:#64748b;background:transparent;cursor:pointer;text-decoration:none;flex:1;transition:all 0.15s;font-family:inherit;}
.btn-outline:hover{background:#f1f5f9;color:#1a3a6b;border-color:#1a3a6b;}
.btn-fill{display:inline-flex;align-items:center;justify-content:center;gap:.4rem;padding:.45rem .9rem;font-size:0.72rem;font-weight:600;border:1px solid #1a3a6b;color:#fff;background:#1a3a6b;cursor:pointer;text-decoration:none;flex:1;transition:background .15s;font-family:inherit;}
.btn-fill:hover{background:#162f56;}
.btn-dis{display:inline-flex;align-items:center;justify-content:center;gap:.4rem;padding:.45rem .9rem;font-size:0.72rem;font-weight:600;border:1px solid #e2e8f0;color:#94a3b8;background:#f8fafc;cursor:not-allowed;flex:1;font-family:inherit;}

/* ── Sidebar ── */
.side{display:flex;flex-direction:column;gap:1rem;height:100%;}
.cycle-hd{background:#f8fafc;padding:.5rem .9rem;display:flex;align-items:center;justify-content:space-between;border-bottom:1px solid #e2e8f0;}
.cycle-nm{color:#1a3a6b;font-size:.75rem;font-weight:700;}
.cycle-open{display:inline-flex;align-items:center;gap:3px;padding:1px 7px;background:#f0fdf4;border:1px solid #bbf7d0;color:#16a34a;font-size:.58rem;font-weight:700;letter-spacing:.04em;}

/* ── Countdown ── */
.cd-grid{display:grid;grid-template-columns:repeat(4,1fr);gap:3px;margin:.35rem 0 .3rem;}
.cd-block{display:flex;flex-direction:column;align-items:center;gap:2px;}
.cd-num{width:100%;text-align:center;padding:.35rem .2rem;font-size:1.1rem;font-weight:800;color:#1a3a6b;background:#eff6ff;border:1px solid #dbeafe;font-variant-numeric:tabular-nums;letter-spacing:-1px;line-height:1;}
.cd-lbl{font-size:.43rem;color:#94a3b8;text-transform:uppercase;letter-spacing:.07em;font-weight:700;}

/* ── Quick links ── */
.ql{display:flex;align-items:center;gap:.5rem;padding:.52rem .9rem;border-bottom:1px solid #f1f5f9;text-decoration:none;color:#334155;font-size:.76rem;font-weight:600;transition:all .12s;}
.ql:last-child{border-bottom:none;}
.ql:hover{background:#f0f4fa;color:#1a3a6b;padding-left:1.2rem;}
.ql i{width:15px;text-align:center;font-size:.8rem;color:#1a3a6b;flex-shrink:0;}

@media(max-width:820px){
    .grid{grid-template-columns:1fr;}
    .hero-right{display:none;}
    .fi-table td.sep{display:none;}
    .fi-table tr{display:grid;grid-template-columns:auto 1fr;}
    .fi-table td.lbl,.fi-table td.val{display:block;}
    .act-row{flex-direction:column;}
    .topbar-name{display:none;}
    .pg{padding:1.25rem 1rem 3rem;}
}
</style>
</head>
<body>

<!-- Topbar -->
<nav class="topbar">
    <img src="../assets/images/logo.jpg" class="topbar-logo" alt="">
    <span class="topbar-title">SUC Faculty Reclassification Management System (SUCFRMS)</span>
    <div class="topbar-user" id="nu" onclick="toggleDD()">
        <span class="topbar-name"><?= sanitize($full_name) ?></span>
        <?php if ($profile_pic): ?>
        <img src="../<?= sanitize($profile_pic) ?>" style="width:30px;height:30px;border-radius:50%;object-fit:cover;border:2px solid rgba(255,255,255,.3);" alt="">
        <?php else: ?>
        <div class="topbar-av"><?= $init ?></div>
        <?php endif; ?>
    </div>
    <div class="dd" id="ndd">
        <div class="dd-hd">
            <div class="dd-av"><?= $init ?></div>
            <div>
                <div class="dd-nm"><?= sanitize($full_name) ?></div>
                <div class="dd-em"><?= sanitize($email) ?></div>
            </div>
        </div>
        <div>
            <a href="../index.php?page=profile"><i class="bi bi-person-circle"></i>My Profile</a>
            <a href="../index.php?page=my_application"><i class="bi bi-file-earmark-text"></i>My Application</a>
            <a href="../pages/logout.php" class="out"><i class="bi bi-box-arrow-right"></i>Sign Out</a>
        </div>
    </div>
</nav>

<!-- Hero -->
<div class="hero">
    <div class="hero-inner">
        <div class="hero-left">
            <?php if ($profile_pic): ?>
            <div class="hero-avatar-wrap"><img src="../<?= sanitize($profile_pic) ?>" style="width:100%;height:100%;object-fit:cover;" alt=""></div>
            <?php else: ?>
            <div class="hero-avatar-wrap"><?= $init ?></div>
            <?php endif; ?>
            <div>
                <div class="hero-name"><?= sanitize($full_name) ?></div>
                <div class="hero-sub"><?= sanitize($rank) ?> &nbsp;·&nbsp; <?= sanitize($campus) ?></div>
                <div class="hero-school">Carlos Hilado Memorial State University</div>
            </div>
        </div>
        <div class="hero-right" style="display:none;"></div>
    </div>
    <div class="rank-bar-wrap"><div class="rank-bar-fill" style="width:<?= $pct ?>%;"></div></div>
</div>

<!-- Content -->
<div class="pg">
    <div class="grid">

        <!-- Main -->
        <div style="display:flex;flex-direction:column;gap:1.5rem;">
            <div class="card" style="display:flex;flex-direction:column;">

                <!-- Card header with tab strip -->
                <div style="border-bottom:1px solid #e2e8f0;">
                    <div style="padding:.5rem 1rem .0rem;display:flex;align-items:center;justify-content:space-between;">
                        <span class="card-title">Faculty Details</span>
                        <button onclick="document.getElementById('portalEditModal').style.display='flex'"
                                style="display:inline-flex;align-items:center;gap:3px;padding:2px 8px;border:1px solid #e2e8f0;background:#f8fafc;color:#475569;font-size:.65rem;font-weight:600;cursor:pointer;"
                                onmouseover="this.style.background='#1a3a6b';this.style.color='#fff';this.style.borderColor='#1a3a6b'"
                                onmouseout="this.style.background='#f8fafc';this.style.color='#475569';this.style.borderColor='#e2e8f0'">
                            <i class="bi bi-pencil-fill" style="font-size:.6rem;"></i> Edit
                        </button>
                    </div>
                    <!-- Tab strip -->
                    <div style="display:flex;padding:0 1rem;gap:0;margin-top:.35rem;">
                        <?php foreach ([['info','Details'],['edu','Education'],['emp','Employment']] as [$tid,$tlab]): ?>
                        <button id="ptab-<?= $tid ?>" onclick="portalTab('<?= $tid ?>')"
                                style="display:inline-flex;align-items:center;gap:.3rem;padding:.4rem .75rem;border:none;background:none;
                                       font-size:.72rem;font-weight:600;cursor:pointer;white-space:nowrap;
                                       border-bottom:2px solid <?= $tid==='info'?'#1a3a6b':'transparent' ?>;
                                       color:<?= $tid==='info'?'#1a3a6b':'#94a3b8' ?>;margin-bottom:-1px;">
                            <?= $tlab ?>
                        </button>
                        <?php endforeach; ?>
                    </div>
                </div>

                <!-- Tab: Details -->
                <div id="ptab-info-panel" style="flex:1;display:grid;grid-template-columns:1fr 1fr;gap:0;">
                    <?php
                    $days_left_dl = null;
                    if ($cycle && $current_app && !$deadline_passed && !empty($current_app['submission_deadline'])) {
                        $days_left_dl = (int)ceil((strtotime($current_app['submission_deadline'].' 23:59:59') - time()) / 86400);
                    }
                    $info_cells = [
                        ['Full Name',          sanitize($full_name)],
                        ['Present Rank',       sanitize($rank)],
                        ['Employee ID',        sanitize($employee_id)],
                        ['Campus',             sanitize($campus)],
                        ['Date Registered',    $date_registered],
                        ['Email Address',      '<a href="mailto:'.sanitize($email).'" style="color:#1a3a6b;text-decoration:none;">'.sanitize($email).'</a>'],
                    ];
                    if ($cycle && $current_app) {
                        $info_cells[] = ['App. Status',
                            '<span class="pill" style="background:'.$sbg.';color:'.$scolor.';border-color:'.$sbd.';">'.$slabel.'</span>'];
                        $dl_val = '<span style="font-weight:600;color:'.($deadline_passed?'#dc2626':'#1e293b').'">'.$deadline_str.'</span>';
                        if ($days_left_dl!==null) $dl_val .= ' <span style="font-size:.67rem;color:'.($days_left_dl<=7?'#dc2626':'#64748b').'">('.$days_left_dl.'d left)</span>';
                        $info_cells[] = ['Submission Deadline', $dl_val];
                    }
                    $total = count($info_cells);
                    foreach ($info_cells as $i => [$lbl,$val]):
                        $br = $i>=$total-2 ? 'none' : '1px solid #f1f5f9';
                        $brr= $i%2===1    ? 'none' : '1px solid #f1f5f9';
                    ?>
                    <div style="padding:.75rem 1rem;border-bottom:<?= $br ?>;border-right:<?= $brr ?>;">
                        <div style="margin-bottom:.25rem;">
                            <span style="font-size:.52rem;font-weight:700;text-transform:uppercase;letter-spacing:.08em;color:#94a3b8;"><?= $lbl ?></span>
                        </div>
                        <div style="font-size:.82rem;font-weight:600;color:#1e293b;line-height:1.3;"><?= $val ?></div>
                    </div>
                    <?php endforeach; ?>
                </div>

                <!-- Tab: Education -->
                <div id="ptab-edu-panel" style="display:none;flex:1;padding:.75rem 1rem;">
                    <?php if ($portal_education): ?>
                    <table style="width:100%;border-collapse:collapse;font-size:.8rem;">
                        <thead>
                        <tr style="background:#f8fafc;border-bottom:1px solid #e2e8f0;">
                            <th style="padding:.45rem .75rem;text-align:left;font-size:.6rem;font-weight:700;text-transform:uppercase;letter-spacing:.07em;color:#94a3b8;">Level</th>
                            <th style="padding:.45rem .75rem;text-align:left;font-size:.6rem;font-weight:700;text-transform:uppercase;letter-spacing:.07em;color:#94a3b8;">Degree</th>
                            <th style="padding:.45rem .75rem;text-align:left;font-size:.6rem;font-weight:700;text-transform:uppercase;letter-spacing:.07em;color:#94a3b8;">School / University</th>
                            <th style="padding:.45rem .75rem;text-align:left;font-size:.6rem;font-weight:700;text-transform:uppercase;letter-spacing:.07em;color:#94a3b8;">Year</th>
                        </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($portal_education as $ed): ?>
                        <tr style="border-bottom:1px solid #f1f5f9;" onmouseover="this.style.background='#fafafa'" onmouseout="this.style.background=''">
                            <td style="padding:.5rem .75rem;font-weight:700;color:#1a3a6b;white-space:nowrap;"><?= htmlspecialchars($edu_labels[$ed['level']] ?? $ed['level']) ?></td>
                            <td style="padding:.5rem .75rem;color:#1e293b;"><?= htmlspecialchars($ed['degree_program'] ?: '—') ?></td>
                            <td style="padding:.5rem .75rem;color:#475569;"><?= htmlspecialchars($ed['school_university'] ?: '—') ?></td>
                            <td style="padding:.5rem .75rem;color:#64748b;white-space:nowrap;"><?= htmlspecialchars($ed['year_graduated'] ?: '—') ?></td>
                        </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                    <?php else: ?>
                    <div style="text-align:center;padding:2rem;color:#94a3b8;font-size:.8rem;">
                        <i class="bi bi-mortarboard" style="font-size:1.5rem;display:block;margin-bottom:.4rem;color:#e2e8f0;"></i>
                        No education records. <a href="../index.php?page=profile_entry" style="color:#1a3a6b;">Update your profile.</a>
                    </div>
                    <?php endif; ?>
                </div>

                <!-- Tab: Employment -->
                <div id="ptab-emp-panel" style="display:none;flex:1;padding:.75rem 1rem;">
                    <?php
                    $has_emp = $portal_employment['current'] || $portal_employment['previous'];
                    if ($has_emp):
                    ?>
                    <table style="width:100%;border-collapse:collapse;font-size:.8rem;">
                        <thead>
                        <tr style="background:#f8fafc;border-bottom:1px solid #e2e8f0;">
                            <th style="padding:.45rem .75rem;text-align:left;font-size:.6rem;font-weight:700;text-transform:uppercase;letter-spacing:.07em;color:#94a3b8;white-space:nowrap;">Type</th>
                            <th style="padding:.45rem .75rem;text-align:left;font-size:.6rem;font-weight:700;text-transform:uppercase;letter-spacing:.07em;color:#94a3b8;white-space:nowrap;">Rank</th>
                            <th style="padding:.45rem .75rem;text-align:left;font-size:.6rem;font-weight:700;text-transform:uppercase;letter-spacing:.07em;color:#94a3b8;white-space:nowrap;">Mode</th>
                            <th style="padding:.45rem .75rem;text-align:left;font-size:.6rem;font-weight:700;text-transform:uppercase;letter-spacing:.07em;color:#94a3b8;white-space:nowrap;">SUC</th>
                            <th style="padding:.45rem .75rem;text-align:left;font-size:.6rem;font-weight:700;text-transform:uppercase;letter-spacing:.07em;color:#94a3b8;white-space:nowrap;">Campus</th>
                            <th style="padding:.45rem .75rem;text-align:left;font-size:.6rem;font-weight:700;text-transform:uppercase;letter-spacing:.07em;color:#94a3b8;white-space:nowrap;">Date</th>
                        </tr>
                        </thead>
                        <tbody>
                        <?php foreach ([['Current', $portal_employment['current']], ['Previous', $portal_employment['previous']]] as [$type_label, $emp_row]):
                            if (!$emp_row) continue;
                            $gv = fn($k) => htmlspecialchars(trim((string)($emp_row[$k] ?? '')) ?: '—');
                        ?>
                        <tr style="border-bottom:1px solid #f1f5f9;" onmouseover="this.style.background='#fafafa'" onmouseout="this.style.background=''">
                            <td style="padding:.5rem .75rem;font-weight:700;color:#1a3a6b;white-space:nowrap;"><?= $type_label ?></td>
                            <td style="padding:.5rem .75rem;color:#1e293b;"><?= $gv('rank') ?></td>
                            <td style="padding:.5rem .75rem;color:#475569;white-space:nowrap;"><?= $gv('mode_of_appointment') ?></td>
                            <td style="padding:.5rem .75rem;color:#475569;"><?= $gv('suc') ?></td>
                            <td style="padding:.5rem .75rem;color:#475569;"><?= $gv('campus') ?></td>
                            <td style="padding:.5rem .75rem;color:#64748b;white-space:nowrap;"><?= !empty($emp_row['date_of_appointment']) ? date('M d, Y', strtotime($emp_row['date_of_appointment'])) : '—' ?></td>
                        </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                    <?php else: ?>
                    <div style="text-align:center;padding:2rem;color:#94a3b8;font-size:.8rem;">
                        <i class="bi bi-briefcase" style="font-size:1.5rem;display:block;margin-bottom:.4rem;color:#e2e8f0;"></i>
                        No employment records. <a href="../index.php?page=profile_entry" style="color:#1a3a6b;">Update your profile.</a>
                    </div>
                    <?php endif; ?>
                </div>

                <div class="act-row" style="margin-top:auto;">
                    <a href="../pages/pre_evaluation.php" class="btn-outline"><i class="bi bi-calculator"></i> Self-Assessment</a>
                    <?php if ($cycle): ?>
                    <a href="<?= $step_link ?>" class="btn-fill"><i class="bi bi-file-earmark-text"></i> Reclassification</a>
                    <?php else: ?>
                    <button class="btn-dis" onclick="document.getElementById('noCycleModal').style.display='flex'"><i class="bi bi-file-earmark-text"></i> Reclassification</button>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Sidebar -->
        <div class="side">

            <!-- Cycle card -->
            <div class="card">
                <?php if ($cycle): ?>
                <div class="cycle-hd">
                    <span class="cycle-nm"><?= sanitize($current_app['cycle_name'] ?? '—') ?></span>
                    <?php if ($cycle['status'] === 'open'): ?><span class="cycle-open">● OPEN</span><?php endif; ?>
                </div>
                <div style="padding:.55rem .9rem;">
                    <?php if (!$deadline_passed && !empty($current_app['submission_deadline'])): ?>
                    <div style="font-size:.58rem;font-weight:700;color:#94a3b8;text-transform:uppercase;letter-spacing:.08em;margin-bottom:.45rem;">Time Remaining</div>
                    <?php
                    $dl_ts_countdown = strtotime($current_app['submission_deadline'].' 23:59:59');
                    $secs_left = max(0, $dl_ts_countdown - time());
                    $cd_d = floor($secs_left / 86400);
                    $cd_h = floor(($secs_left % 86400) / 3600);
                    $cd_m = floor(($secs_left % 3600) / 60);
                    $cd_s = $secs_left % 60;
                    $cd_color = $cd_d <= 3 ? '#dc2626' : ($cd_d <= 14 ? '#d97706' : '#1a3a6b');
                    ?>
                    <div class="cd-grid" id="portalCountdown" data-deadline="<?= $dl_ts_countdown ?>">
                        <?php foreach ([['d',$cd_d,'Days'],['h',$cd_h,'Hours'],['m',$cd_m,'Min'],['s',$cd_s,'Sec']] as [$k,$v,$l]): ?>
                        <div class="cd-block">
                            <div class="cd-num" id="pc-<?= $k ?>" style="color:<?= $cd_color ?>;background:<?= $cd_d<=3?'#fef2f2':($cd_d<=14?'#fffbeb':'#eff6ff') ?>;border-color:<?= $cd_d<=3?'#fecaca':($cd_d<=14?'#fde68a':'#dbeafe') ?>;"><?= str_pad($v,2,'0',STR_PAD_LEFT) ?></div>
                            <span class="cd-lbl"><?= $l ?></span>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <div style="font-size:.63rem;color:#94a3b8;border-top:1px solid #f1f5f9;padding-top:.45rem;margin-top:.2rem;">
                        <i class="bi bi-calendar3" style="margin-right:3px;"></i>Deadline: <?= $deadline_str ?>
                    </div>
                    <script>
                    (function(){
                        const el=document.getElementById('portalCountdown');
                        if(!el)return;
                        const end=parseInt(el.dataset.deadline)*1000;
                        function tick(){
                            const diff=Math.max(0,Math.floor((end-Date.now())/1000));
                            const d=Math.floor(diff/86400),h=Math.floor((diff%86400)/3600),m=Math.floor((diff%3600)/60),s=diff%60;
                            const pad=n=>String(n).padStart(2,'0');
                            const col=d<=3?'#dc2626':(d<=14?'#d97706':'#1a3a6b');
                            [['d',d],['h',h],['m',m],['s',s]].forEach(([k,v])=>{
                                const c=document.getElementById('pc-'+k);
                                if(c){c.textContent=pad(v);c.style.color=col;c.style.borderColor=d<=3?'#fecaca':d<=14?'#fde68a':'#dbeafe';c.style.background=d<=3?'#fef2f2':d<=14?'#fffbeb':'#eff6ff';}
                            });
                            if(diff>0)setTimeout(tick,1000);
                        }
                        tick();
                    })();
                    </script>
                    <?php elseif ($deadline_passed): ?>
                    <div style="font-size:.8rem;color:#dc2626;font-weight:600;padding:.25rem 0;">Submission deadline has passed.</div>
                    <?php endif; ?>
                </div>
                <?php else: ?>
                <div style="padding:1.5rem 1rem;text-align:center;">
                    <i class="bi bi-calendar-x" style="font-size:1.75rem;color:#cbd5e1;display:block;margin-bottom:.5rem;"></i>
                    <div style="font-size:.8rem;font-weight:600;color:#94a3b8;">No active cycle</div>
                    <div style="font-size:.71rem;color:#cbd5e1;margin-top:3px;">Use Self-Assessment to prepare</div>
                </div>
                <?php endif; ?>
            </div>

            <!-- Quick Access -->
            <div class="card" style="flex:1;">
                <div class="card-head"><span class="card-title">Quick Access</span></div>
                <div>
                    <a href="../index.php?page=my_application" class="ql"><i class="bi bi-file-earmark-text"></i>Application Status</a>
                    <a href="../index.php?page=apply" class="ql"><i class="bi bi-ui-checks-grid"></i>KRA Entry</a>
                    <a href="../index.php?page=appeals" class="ql"><i class="bi bi-chat-square-text"></i>Appeals</a>
                    <a href="../index.php?page=my_audit" class="ql"><i class="bi bi-clock-history"></i>Activity Log</a>
                    <a href="../index.php?page=help" class="ql"><i class="bi bi-question-circle"></i>Tutorial / Help</a>
                    <a href="../pages/logout.php" class="ql" style="color:#dc2626;"><i class="bi bi-box-arrow-right" style="color:#dc2626;"></i>Sign Out</a>
                </div>
            </div>

        </div>
    </div>
</div>

<!-- No cycle modal -->
<div id="noCycleModal" style="display:none;position:fixed;inset:0;background:rgba(15,23,42,.55);z-index:9999;align-items:center;justify-content:center;">
    <div style="background:#fff;max-width:340px;width:90%;border:1px solid #e2e8f0;box-shadow:0 12px 40px rgba(0,0,0,.2);overflow:hidden;">
        <div style="background:#0f2952;padding:.85rem 1.1rem;display:flex;align-items:center;gap:.6rem;">
            <img src="../assets/images/logo.jpg" style="width:26px;height:26px;border-radius:50%;object-fit:cover;border:1.5px solid rgba(255,255,255,.25);" alt="">
            <span style="color:#fff;font-weight:700;font-size:.78rem;">SUCFRMS</span>
        </div>
        <div style="padding:1.25rem 1.1rem;">
            <div style="font-weight:700;color:#1e293b;font-size:.85rem;margin-bottom:5px;">No Active Cycle</div>
            <p style="font-size:.78rem;color:#64748b;line-height:1.6;margin-bottom:1rem;">
                There is no open reclassification cycle at this time. Please wait for the administrator to open one.
                You may use <strong>Self-Assessment</strong> to prepare.
            </p>
            <button onclick="document.getElementById('noCycleModal').style.display='none'"
                style="background:#1a3a6b;color:#fff;border:none;padding:.45rem 1.25rem;font-size:.78rem;font-weight:700;cursor:pointer;">
                Close
            </button>
        </div>
    </div>
</div>


<!-- ── Edit Profile Modal ─────────────────────────────────── -->
<div id="portalEditModal"
     style="display:none;position:fixed;inset:0;background:rgba(15,23,42,.55);z-index:9999;
            align-items:flex-start;justify-content:center;padding:1.5rem 1rem;overflow-y:auto;">
    <div style="background:#fff;width:100%;max-width:620px;border:1px solid #e2e8f0;
                box-shadow:0 16px 48px rgba(0,0,0,.2);overflow:hidden;margin:auto;">

        <!-- Header -->
        <div style="background:#1a3a6b;padding:.75rem 1.1rem;display:flex;align-items:center;justify-content:space-between;flex-shrink:0;">
            <span style="color:#fff;font-weight:700;font-size:.85rem;">Edit Profile</span>
            <button onclick="document.getElementById('portalEditModal').style.display='none'"
                    style="background:none;border:none;color:rgba(255,255,255,.7);font-size:1.2rem;cursor:pointer;padding:0;line-height:1;"
                    onmouseover="this.style.color='#fff'" onmouseout="this.style.color='rgba(255,255,255,.7)'">&times;</button>
        </div>

        <!-- Tab strip -->
        <div style="display:flex;border-bottom:2px solid #e2e8f0;background:#f8fafc;overflow-x:auto;scrollbar-width:none;">
            <?php foreach ([
                ['info', 'Basic Info'],
                ['edu',  'Education'],
                ['emp',  'Employment'],
                ['pw',   'Password'],
            ] as [$tid, $tlab]):
                $active = $tid === 'info';
            ?>
            <button id="em-tab-<?= $tid ?>" onclick="editModalTab('<?= $tid ?>')"
                    style="padding:.55rem 1rem;border:none;background:none;font-size:.73rem;font-weight:600;
                           white-space:nowrap;cursor:pointer;margin-bottom:-2px;
                           color:<?= $active?'#1a3a6b':'#94a3b8' ?>;
                           border-bottom:2px solid <?= $active?'#1a3a6b':'transparent' ?>;">
                <?= $tlab ?>
            </button>
            <?php endforeach; ?>
        </div>

        <!-- ── Tab: Basic Info ── -->
        <div id="em-panel-info" style="padding:1.1rem;">
            <p style="font-size:.72rem;color:#64748b;margin-bottom:.85rem;">Only your name is editable here. For rank, campus, or ID changes, contact your administrator.</p>

            <!-- Profile photo update -->
            <form method="POST" action="" enctype="multipart/form-data" id="portalPhotoForm" style="margin-bottom:.85rem;padding:.65rem .8rem;border:1px solid #f1f5f9;background:#f8fafc;display:flex;align-items:center;gap:.85rem;">
                <input type="hidden" name="portal_edit_action" value="update_basic">
                <input type="hidden" name="first_name"  value="<?= htmlspecialchars($fac['first_name']??'') ?>">
                <input type="hidden" name="middle_name" value="<?= htmlspecialchars($fac['middle_name']??'') ?>">
                <input type="hidden" name="last_name"   value="<?= htmlspecialchars($fac['last_name']??'') ?>">
                <!-- Current avatar -->
                <?php if ($profile_pic): ?>
                <img src="../<?= sanitize($profile_pic) ?>" id="portalPhotoPreview"
                     style="width:48px;height:48px;border-radius:50%;object-fit:cover;border:2px solid #e2e8f0;flex-shrink:0;">
                <?php else: ?>
                <div id="portalPhotoPreview"
                     style="width:48px;height:48px;border-radius:50%;background:#1a3a6b;display:flex;align-items:center;justify-content:center;font-size:.9rem;font-weight:700;color:#fff;flex-shrink:0;">
                    <?= strtoupper(substr($fac['first_name']??'U',0,1).substr($fac['last_name']??'',0,1)) ?>
                </div>
                <?php endif; ?>
                <div style="flex:1;min-width:0;">
                    <div style="font-size:.62rem;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:#94a3b8;margin-bottom:3px;">Profile Photo</div>
                    <label style="display:inline-flex;align-items:center;gap:4px;padding:.28rem .7rem;border:1px solid #e2e8f0;background:#fff;font-size:.72rem;font-weight:600;color:#475569;cursor:pointer;"
                           onmouseover="this.style.background='#1a3a6b';this.style.color='#fff';this.style.borderColor='#1a3a6b'"
                           onmouseout="this.style.background='#fff';this.style.color='#475569';this.style.borderColor='#e2e8f0'">
                        <i class="bi bi-camera-fill" style="font-size:.65rem;"></i> Change Photo
                        <input type="file" name="profile_pic" accept=".jpg,.jpeg,.png,.webp"
                               style="display:none;"
                               onchange="
                                   if(this.files[0]){
                                       var r=new FileReader();
                                       r.onload=function(e){
                                           var p=document.getElementById('portalPhotoPreview');
                                           if(p.tagName==='IMG'){p.src=e.target.result;}
                                           else{p.style.backgroundImage='url('+e.target.result+')';p.style.backgroundSize='cover';p.style.backgroundPosition='center';}
                                       };
                                       r.readAsDataURL(this.files[0]);
                                       document.getElementById('portalPhotoForm').submit();
                                   }">
                    </label>
                    <div style="font-size:.62rem;color:#cbd5e1;margin-top:3px;">JPG, PNG or WEBP · max 2MB</div>
                </div>
            </form>

            <form method="POST" action="">
                <input type="hidden" name="portal_edit_action" value="update_basic">
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:.6rem;margin-bottom:.6rem;">
                    <div>
                        <label style="font-size:.6rem;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:#94a3b8;display:block;margin-bottom:3px;">First Name</label>
                        <input type="text" name="first_name" value="<?= htmlspecialchars($fac['first_name']??'') ?>" required
                               style="width:100%;border:1px solid #e2e8f0;padding:.4rem .6rem;font-size:.8rem;box-sizing:border-box;font-family:inherit;"
                               onfocus="this.style.borderColor='#1a3a6b'" onblur="this.style.borderColor='#e2e8f0'">
                    </div>
                    <div>
                        <label style="font-size:.6rem;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:#94a3b8;display:block;margin-bottom:3px;">Last Name</label>
                        <input type="text" name="last_name" value="<?= htmlspecialchars($fac['last_name']??'') ?>" required
                               style="width:100%;border:1px solid #e2e8f0;padding:.4rem .6rem;font-size:.8rem;box-sizing:border-box;font-family:inherit;"
                               onfocus="this.style.borderColor='#1a3a6b'" onblur="this.style.borderColor='#e2e8f0'">
                    </div>
                </div>
                <div style="margin-bottom:.85rem;">
                    <label style="font-size:.6rem;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:#94a3b8;display:block;margin-bottom:3px;">Middle Name <span style="font-weight:400;color:#cbd5e1;">(optional)</span></label>
                    <input type="text" name="middle_name" value="<?= htmlspecialchars($fac['middle_name']??'') ?>"
                           style="width:100%;border:1px solid #e2e8f0;padding:.4rem .6rem;font-size:.8rem;box-sizing:border-box;font-family:inherit;"
                           onfocus="this.style.borderColor='#1a3a6b'" onblur="this.style.borderColor='#e2e8f0'">
                </div>
                <div style="display:flex;justify-content:flex-end;gap:.5rem;">
                    <button type="button" onclick="document.getElementById('portalEditModal').style.display='none'"
                            style="padding:.4rem 1rem;border:1px solid #e2e8f0;background:#fff;font-size:.78rem;font-weight:600;cursor:pointer;color:#475569;">Cancel</button>
                    <button type="submit" style="padding:.4rem 1.1rem;background:#1a3a6b;color:#fff;border:none;font-size:.78rem;font-weight:700;cursor:pointer;">Save Changes</button>
                </div>
            </form>
        </div>

        <!-- ── Tab: Education ── -->
        <div id="em-panel-edu" style="display:none;padding:1.1rem;">
            <p style="font-size:.72rem;color:#64748b;margin-bottom:.85rem;">Add or update your educational attainment. Undergraduate is required.</p>
            <form method="POST" action="">
                <input type="hidden" name="portal_edit_action" value="update_education">
                <?php
                $edu_levels_edit = ['Bachelor'=>'Undergraduate','Master'=>"Master's",'Doctorate'=>'Doctorate','PostDoctorate'=>'Post-Doctorate'];
                foreach ($edu_levels_edit as $lv_key => $lv_label):
                    $ed = $edu_by_level[$lv_key] ?? [];
                    $req = $lv_key === 'Bachelor' ? 'required' : '';
                ?>
                <div style="margin-bottom:.9rem;border:1px solid #f1f5f9;padding:.65rem .8rem;">
                    <div style="font-size:.6rem;font-weight:700;text-transform:uppercase;letter-spacing:.08em;color:#1a3a6b;margin-bottom:.5rem;"><?= $lv_label ?></div>
                    <input type="hidden" name="edu_level[]" value="<?= $lv_key ?>">
                    <div style="display:grid;grid-template-columns:1fr 1fr 80px;gap:.5rem;">
                        <div>
                            <label style="font-size:.58rem;font-weight:700;text-transform:uppercase;color:#94a3b8;display:block;margin-bottom:2px;">Degree <?= $lv_key==='Bachelor'?'<span style="color:#dc2626;">*</span>':'' ?></label>
                            <input type="text" name="edu_degree[]" <?= $req ?> value="<?= htmlspecialchars($ed['degree_program'] ?? '') ?>"
                                   placeholder="e.g. BS Computer Science"
                                   style="width:100%;border:1px solid #e2e8f0;padding:.35rem .5rem;font-size:.77rem;box-sizing:border-box;font-family:inherit;"
                                   onfocus="this.style.borderColor='#1a3a6b'" onblur="this.style.borderColor='#e2e8f0'">
                        </div>
                        <div>
                            <label style="font-size:.58rem;font-weight:700;text-transform:uppercase;color:#94a3b8;display:block;margin-bottom:2px;">School / University <?= $lv_key==='Bachelor'?'<span style="color:#dc2626;">*</span>':'' ?></label>
                            <input type="text" name="edu_school[]" <?= $req ?> value="<?= htmlspecialchars($ed['school_university'] ?? '') ?>"
                                   placeholder="e.g. CHMSU"
                                   style="width:100%;border:1px solid #e2e8f0;padding:.35rem .5rem;font-size:.77rem;box-sizing:border-box;font-family:inherit;"
                                   onfocus="this.style.borderColor='#1a3a6b'" onblur="this.style.borderColor='#e2e8f0'">
                        </div>
                        <div>
                            <label style="font-size:.58rem;font-weight:700;text-transform:uppercase;color:#94a3b8;display:block;margin-bottom:2px;">Year <?= $lv_key==='Bachelor'?'<span style="color:#dc2626;">*</span>':'' ?></label>
                            <input type="text" name="edu_year[]" <?= $req ?> value="<?= htmlspecialchars($ed['year_graduated'] ?? '') ?>"
                                   placeholder="2020" maxlength="4"
                                   style="width:100%;border:1px solid #e2e8f0;padding:.35rem .5rem;font-size:.77rem;box-sizing:border-box;font-family:inherit;"
                                   onfocus="this.style.borderColor='#1a3a6b'" onblur="this.style.borderColor='#e2e8f0'">
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
                <div style="display:flex;justify-content:flex-end;gap:.5rem;">
                    <button type="button" onclick="document.getElementById('portalEditModal').style.display='none'"
                            style="padding:.4rem 1rem;border:1px solid #e2e8f0;background:#fff;font-size:.78rem;font-weight:600;cursor:pointer;color:#475569;">Cancel</button>
                    <button type="submit" style="padding:.4rem 1.1rem;background:#1a3a6b;color:#fff;border:none;font-size:.78rem;font-weight:700;cursor:pointer;">Save Education</button>
                </div>
            </form>
        </div>

        <!-- ── Tab: Employment ── -->
        <div id="em-panel-emp" style="display:none;padding:.85rem 1.1rem;">
            <p style="font-size:.72rem;color:#64748b;margin-bottom:.75rem;">Update your current and previous employment history.</p>
            <form method="POST" action="">
                <input type="hidden" name="portal_edit_action" value="update_employment">
                <?php
                $emp_sections_edit = [
                    ['current',  'Current',  $portal_employment['current']  ?? []],
                    ['previous', 'Previous', $portal_employment['previous'] ?? []],
                ];
                foreach ($emp_sections_edit as [$etype, $elabel, $erow]):
                    $gv = fn($k) => htmlspecialchars(trim((string)($erow[$k] ?? '')));
                ?>
                <!-- Section label -->
                <div style="font-size:.6rem;font-weight:700;text-transform:uppercase;letter-spacing:.08em;
                            color:#94a3b8;margin-bottom:.35rem;margin-top:<?= $etype==='current'?'0':'.65rem' ?>;">
                    <?= $elabel ?> Employment
                </div>
                <!-- 3-column row 1: Rank / Mode / Date -->
                <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:.45rem;margin-bottom:.45rem;">
                    <div>
                        <label style="font-size:.56rem;font-weight:700;text-transform:uppercase;color:#94a3b8;display:block;margin-bottom:2px;">Rank <?= $etype==='current'?'<span style="color:#dc2626;">*</span>':'' ?></label>
                        <input type="text" name="<?= $etype ?>_rank" value="<?= $gv('rank') ?>" <?= $etype==='current'?'required':'' ?>
                               style="width:100%;border:1px solid #e2e8f0;padding:.3rem .45rem;font-size:.76rem;box-sizing:border-box;font-family:inherit;"
                               onfocus="this.style.borderColor='#1a3a6b'" onblur="this.style.borderColor='#e2e8f0'">
                    </div>
                    <div>
                        <label style="font-size:.56rem;font-weight:700;text-transform:uppercase;color:#94a3b8;display:block;margin-bottom:2px;">Mode</label>
                        <select name="<?= $etype ?>_mode"
                                style="width:100%;border:1px solid #e2e8f0;padding:.3rem .45rem;font-size:.76rem;box-sizing:border-box;font-family:inherit;background:#fff;">
                            <?php foreach (['Permanent','Temporary','Contractual','Casual','Co-Terminus'] as $m): ?>
                            <option value="<?= $m ?>" <?= ($gv('mode_of_appointment')===$m)?'selected':'' ?>><?= $m ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div>
                        <label style="font-size:.56rem;font-weight:700;text-transform:uppercase;color:#94a3b8;display:block;margin-bottom:2px;">Date of Appointment</label>
                        <input type="date" name="<?= $etype ?>_date" value="<?= $gv('date_of_appointment') ?>"
                               style="width:100%;border:1px solid #e2e8f0;padding:.3rem .45rem;font-size:.76rem;box-sizing:border-box;font-family:inherit;"
                               onfocus="this.style.borderColor='#1a3a6b'" onblur="this.style.borderColor='#e2e8f0'">
                    </div>
                </div>
                <!-- 3-column row 2: SUC / Campus / Sector(prev only) -->
                <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:.45rem;margin-bottom:.45rem;">
                    <div>
                        <label style="font-size:.56rem;font-weight:700;text-transform:uppercase;color:#94a3b8;display:block;margin-bottom:2px;">SUC <?= $etype==='current'?'<span style="color:#dc2626;">*</span>':'' ?></label>
                        <input type="text" name="<?= $etype ?>_suc" value="<?= $gv('suc') ?>" <?= $etype==='current'?'required':'' ?>
                               style="width:100%;border:1px solid #e2e8f0;padding:.3rem .45rem;font-size:.76rem;box-sizing:border-box;font-family:inherit;"
                               onfocus="this.style.borderColor='#1a3a6b'" onblur="this.style.borderColor='#e2e8f0'">
                    </div>
                    <div>
                        <label style="font-size:.56rem;font-weight:700;text-transform:uppercase;color:#94a3b8;display:block;margin-bottom:2px;">Campus <?= $etype==='current'?'<span style="color:#dc2626;">*</span>':'' ?></label>
                        <input type="text" name="<?= $etype ?>_campus" value="<?= $gv('campus') ?>" <?= $etype==='current'?'required':'' ?>
                               style="width:100%;border:1px solid #e2e8f0;padding:.3rem .45rem;font-size:.76rem;box-sizing:border-box;font-family:inherit;"
                               onfocus="this.style.borderColor='#1a3a6b'" onblur="this.style.borderColor='#e2e8f0'">
                    </div>
                    <?php if ($etype==='previous'): ?>
                    <div>
                        <label style="font-size:.56rem;font-weight:700;text-transform:uppercase;color:#94a3b8;display:block;margin-bottom:2px;">Sector</label>
                        <input type="text" name="previous_sector" value="<?= $gv('employment_sector') ?>"
                               style="width:100%;border:1px solid #e2e8f0;padding:.3rem .45rem;font-size:.76rem;box-sizing:border-box;font-family:inherit;"
                               onfocus="this.style.borderColor='#1a3a6b'" onblur="this.style.borderColor='#e2e8f0'">
                    </div>
                    <?php else: ?>
                    <div></div>
                    <?php endif; ?>
                </div>
                <!-- Address full width -->
                <div style="margin-bottom:<?= $etype==='current'?'.1rem':'.0rem' ?>;">
                    <label style="font-size:.56rem;font-weight:700;text-transform:uppercase;color:#94a3b8;display:block;margin-bottom:2px;">Address <?= $etype==='current'?'<span style="color:#dc2626;">*</span>':'' ?></label>
                    <input type="text" name="<?= $etype ?>_address" value="<?= $gv('address') ?>" <?= $etype==='current'?'required':'' ?>
                           style="width:100%;border:1px solid #e2e8f0;padding:.3rem .45rem;font-size:.76rem;box-sizing:border-box;font-family:inherit;"
                           onfocus="this.style.borderColor='#1a3a6b'" onblur="this.style.borderColor='#e2e8f0'">
                </div>
                <?php if ($etype==='current'): ?>
                <div style="border-bottom:1px solid #f1f5f9;margin:.6rem 0;"></div>
                <?php endif; ?>
                <?php endforeach; ?>

                <div style="display:flex;justify-content:flex-end;gap:.5rem;margin-top:.6rem;">
                    <button type="button" onclick="document.getElementById('portalEditModal').style.display='none'"
                            style="padding:.4rem 1rem;border:1px solid #e2e8f0;background:#fff;font-size:.78rem;font-weight:600;cursor:pointer;color:#475569;">Cancel</button>
                    <button type="submit" style="padding:.4rem 1.1rem;background:#1a3a6b;color:#fff;border:none;font-size:.78rem;font-weight:700;cursor:pointer;">Save Employment</button>
                </div>
            </form>
        </div>

        <!-- ── Tab: Password ── -->
        <div id="em-panel-pw" style="display:none;padding:1.1rem;">
            <p style="font-size:.72rem;color:#64748b;margin-bottom:.85rem;">Must be 8+ characters with uppercase, lowercase, a number, and a special character.</p>
            <form method="POST" action="" onsubmit="return emPwValidate(this)">
                <input type="hidden" name="portal_edit_action" value="change_password">
                <div style="margin-bottom:.6rem;">
                    <label style="font-size:.6rem;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:#94a3b8;display:block;margin-bottom:3px;">New Password</label>
                    <input type="password" name="new_password" required id="em-np"
                           style="width:100%;border:1px solid #e2e8f0;padding:.4rem .6rem;font-size:.8rem;box-sizing:border-box;font-family:inherit;"
                           onfocus="this.style.borderColor='#1a3a6b'" onblur="this.style.borderColor='#e2e8f0'">
                </div>
                <div style="margin-bottom:.85rem;">
                    <label style="font-size:.6rem;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:#94a3b8;display:block;margin-bottom:3px;">Confirm Password</label>
                    <input type="password" name="confirm_password" required id="em-cp"
                           style="width:100%;border:1px solid #e2e8f0;padding:.4rem .6rem;font-size:.8rem;box-sizing:border-box;font-family:inherit;"
                           onfocus="this.style.borderColor='#1a3a6b'" onblur="this.style.borderColor='#e2e8f0'">
                </div>
                <div id="em-pw-err" style="display:none;font-size:.73rem;color:#dc2626;margin-bottom:.6rem;padding:.35rem .6rem;background:#fef2f2;border-left:3px solid #dc2626;"></div>
                <div style="display:flex;justify-content:flex-end;gap:.5rem;">
                    <button type="button" onclick="document.getElementById('portalEditModal').style.display='none'"
                            style="padding:.4rem 1rem;border:1px solid #e2e8f0;background:#fff;font-size:.78rem;font-weight:600;cursor:pointer;color:#475569;">Cancel</button>
                    <button type="submit" style="padding:.4rem 1.1rem;background:#1a3a6b;color:#fff;border:none;font-size:.78rem;font-weight:700;cursor:pointer;">Update Password</button>
                </div>
            </form>
        </div>

    </div>
</div>

<script>
function toggleDD(){document.getElementById('ndd').classList.toggle('open');}
document.addEventListener('click',function(e){
    const n=document.getElementById('nu'),d=document.getElementById('ndd');
    if(d&&n&&!n.contains(e.target))d.classList.remove('open');
});

// Portal tab switching
function portalTab(id) {
    ['info','edu','emp'].forEach(function(t) {
        var panel = document.getElementById('ptab-'+t+'-panel');
        var btn   = document.getElementById('ptab-'+t);
        if (!panel || !btn) return;
        var active = t === id;
        panel.style.display = active ? (t==='info'?'grid':'block') : 'none';
        btn.style.borderBottomColor = active ? '#1a3a6b' : 'transparent';
        btn.style.color = active ? '#1a3a6b' : '#94a3b8';
    });
}

// Edit modal tab
function editModalTab(id) {
    ['info','edu','emp','pw'].forEach(function(t) {
        var panel = document.getElementById('em-panel-'+t);
        var btn   = document.getElementById('em-tab-'+t);
        if (!panel||!btn) return;
        var active = t===id;
        panel.style.display = active ? 'block' : 'none';
        btn.style.borderBottomColor = active ? '#1a3a6b' : 'transparent';
        btn.style.color = active ? '#1a3a6b' : '#94a3b8';
    });
}

// Password validation
function emPwValidate(form) {
    var np = form.querySelector('#em-np').value;
    var cp = form.querySelector('#em-cp').value;
    var err = document.getElementById('em-pw-err');
    var msg = '';
    if (np.length < 8) msg = 'Password must be at least 8 characters.';
    else if (!/[A-Z]/.test(np)) msg = 'Must include at least one uppercase letter.';
    else if (!/[a-z]/.test(np)) msg = 'Must include at least one lowercase letter.';
    else if (!/[0-9]/.test(np)) msg = 'Must include at least one number.';
    else if (!/[^a-zA-Z0-9]/.test(np)) msg = 'Must include at least one special character.';
    else if (np !== cp) msg = 'Passwords do not match.';
    if (msg) { err.textContent = msg; err.style.display = 'block'; return false; }
    err.style.display = 'none';
    return true;
}

// Close modals on backdrop click
['portalEditModal','noCycleModal'].forEach(function(id){
    var el = document.getElementById(id);
    if (el) el.addEventListener('click', function(e){ if(e.target===this) this.style.display='none'; });
});
document.addEventListener('keydown', function(e){
    if (e.key==='Escape') {
        ['portalEditModal','noCycleModal'].forEach(function(id){
            var el=document.getElementById(id); if(el) el.style.display='none';
        });
    }
});
</script>
</body>
</html>
