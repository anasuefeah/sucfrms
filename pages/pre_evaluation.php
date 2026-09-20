<?php
/**
 * Pre-Evaluation — standalone page, no sidebar.
 */
if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/functions.php';

if (!isLoggedIn()) { header('Location: login.php'); exit; }
if (($_SESSION['role'] ?? '') !== 'faculty') {
    header('Location: ../index.php'); exit;
}
if (!empty($_SESSION['force_pw_change'])) {
    header('Location: change_password.php'); exit;
}

$uid = $_SESSION['user_id'];

// Runtime migration
try { $pdo->query("SELECT entry_id FROM pre_eval_entries LIMIT 1"); }
catch (\Exception $e) {
    $pdo->exec("CREATE TABLE IF NOT EXISTS pre_eval_entries (entry_id INT AUTO_INCREMENT PRIMARY KEY, user_id INT NOT NULL, kra_category ENUM('Instruction','Research','Extension','Professional Development') NOT NULL, remarks TEXT NOT NULL, computed_points DECIMAL(6,2) DEFAULT 0, notes TEXT DEFAULT NULL, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP, FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE CASCADE)");
    $pdo->exec("CREATE TABLE IF NOT EXISTS pre_eval_files (file_id INT AUTO_INCREMENT PRIMARY KEY, user_id INT NOT NULL, entry_id INT DEFAULT NULL, kra_category ENUM('Instruction','Research','Extension','Professional Development') NOT NULL, file_path VARCHAR(255) NOT NULL, original_filename VARCHAR(255) NOT NULL, file_size_bytes INT DEFAULT 0, description VARCHAR(255) DEFAULT NULL, uploaded_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE CASCADE, FOREIGN KEY (entry_id) REFERENCES pre_eval_entries(entry_id) ON DELETE SET NULL)");
}
// Migration: pre_eval_auto_sub_rank table
try { $pdo->query("SELECT pea_id FROM pre_eval_auto_sub_rank LIMIT 1"); }
catch (\Exception $e) {
    $pdo->exec("CREATE TABLE IF NOT EXISTS pre_eval_auto_sub_rank (
        pea_id            INT AUTO_INCREMENT PRIMARY KEY,
        user_id           INT NOT NULL UNIQUE,
        doctorate_status  ENUM('Triggered','Not Triggered','Needs Review') DEFAULT 'Needs Review',
        award_status      ENUM('Triggered','Not Triggered','Needs Review') DEFAULT 'Needs Review',
        doctorate_details TEXT DEFAULT NULL,
        award_details     TEXT DEFAULT NULL,
        award_evidence    VARCHAR(255) DEFAULT NULL,
        updated_at        TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE CASCADE
    )");
}

// -- Auto Sub Rank is fully automatic — no POST handler needed here.
// The tab recalculates on every page view via AutoSubRankCalculator.

// Faculty info
$fac = $pdo->prepare("SELECT full_name, first_name, last_name, middle_name, rank, profile_pic FROM users WHERE user_id=?");
$fac->execute([$uid]);
$fac         = $fac->fetch();
$full_name = formatDisplayName($fac);
$rank        = $fac['rank']        ?? '';
$profile_pic = $fac['profile_pic'] ?? '';
$init        = strtoupper(substr($fac['first_name']??'U',0,1).substr($fac['last_name']??'',0,1)) ?: 'FA';

// ── PRE-EVALUATION ELIGIBILITY GATE ─────────────────────────────────────────
// Constraint: verify rank readiness only. Authenticity of documents is handled
// exclusively by human checkers at Stage 1 (local) and Stage 2 (Talisay/main).
$eligible          = true;
$eligibility_notes = [];

// (a) Rank check — must have a recognised faculty rank to proceed
$all_ranks = [
    'Instructor I','Instructor II','Instructor III',
    'Assistant Professor I','Assistant Professor II','Assistant Professor III','Assistant Professor IV',
    'Associate Professor I','Associate Professor II','Associate Professor III','Associate Professor IV','Associate Professor V',
    'Professor I','Professor II','Professor III','Professor IV','Professor V','Professor VI',
    'University Professor',
];
if (empty($rank)) {
    $eligible = false;
    $eligibility_notes[] = 'Faculty rank is not set. Contact your administrator to update your profile before pre-evaluating.';
} elseif (!in_array($rank, $all_ranks)) {
    $eligible = false;
    $eligibility_notes[] = "Current rank '{$rank}' is not a recognised reclassification rank. Contact your administrator.";
}

// (b) Readiness check — cannot pre-evaluate if already in an active cycle with a locked application
$active_cycle = getActiveCycle($pdo);
if ($active_cycle) {
    $existing_app = $pdo->prepare("SELECT status FROM applications WHERE user_id=? AND cycle_id=? LIMIT 1");
    $existing_app->execute([$uid, $active_cycle['cycle_id']]);
    $existing_app = $existing_app->fetch();
    if ($existing_app && in_array($existing_app['status'], ['approved','reclassified','admin_rejected'])) {
        $eligible = false;
        $eligibility_notes[] = 'Your application for the current cycle has already been finalised ('
            . ucwords(str_replace('_',' ', $existing_app['status'])) . '). Pre-evaluation is not applicable.';
    }
}

// Document completeness check — presence only, not authenticity
// Count entries that have at least one attached file (evidence presence only)
$entries_with_files_q = $pdo->prepare("
    SELECT COUNT(DISTINCT pe.entry_id)
    FROM pre_eval_entries pe
    WHERE pe.user_id = ?
      AND EXISTS (SELECT 1 FROM pre_eval_files pf WHERE pf.entry_id = pe.entry_id)
");
$entries_with_files_q->execute([$uid]);
$entries_with_files  = (int)$entries_with_files_q->fetchColumn();
$entries_total_q = $pdo->prepare("SELECT COUNT(*) FROM pre_eval_entries WHERE user_id=?");
$entries_total_q->execute([$uid]);
$entry_count_total  = (int)$entries_total_q->fetchColumn();
$docs_completeness_pct = $entry_count_total > 0
    ? min(100, (int)round(($entries_with_files / $entry_count_total) * 100))
    : 0;
$documents_present = ($entry_count_total > 0 && $entries_with_files === $entry_count_total);

// Current scores
$sc = $pdo->prepare("SELECT kra_category, SUM(computed_points) as total FROM pre_eval_entries WHERE user_id=? GROUP BY kra_category");
$sc->execute([$uid]);
$raw = [];
foreach ($sc->fetchAll() as $r) $raw[$r['kra_category']] = (float)$r['total'];

$score_result = computeWeightedScore($raw, $rank);
$weighted     = $score_result['weighted_score'];
$sub_rank     = $score_result['sub_rank_increment'];
$weights      = $score_result['weights'];
$potential    = computePotentialRank($raw, $rank);
$pot_rank     = $potential['potential_rank'];

$kra_cats = ['Instruction','Research','Extension','Professional Development'];
$kra_tabs = [
    'instruction' => ['label'=>'Instruction',         'cat'=>'Instruction',              'icon'=>'bi-book-half',        'max'=>100, 'color'=>'#1e4d8c', 'num'=>1],
    'research'    => ['label'=>'Research',             'cat'=>'Research',                 'icon'=>'bi-journal-text',     'max'=>100, 'color'=>'#1a3a6b', 'num'=>2],
    'extension'   => ['label'=>'Extension',            'cat'=>'Extension',                'icon'=>'bi-people-fill',      'max'=>100, 'color'=>'#1e4d8c', 'num'=>3],
    'profdev'     => ['label'=>'Prof. Development',    'cat'=>'Professional Development', 'icon'=>'bi-award-fill',       'max'=>100, 'color'=>'#475569', 'num'=>4],
    'autosubrank' => ['label'=>'Auto Sub Rank',         'cat'=>'Auto Sub Rank',            'icon'=>'bi-arrow-up-circle',  'max'=>0,   'color'=>'#1a3a6b', 'num'=>5],
    'posreq'      => ['label'=>'Position Requirements','cat'=>'Position Requirements',    'icon'=>'bi-file-earmark-check','max'=>0,   'color'=>'#475569', 'num'=>6],
];

$active_tab = $_GET['tab'] ?? 'instruction';
if (!array_key_exists($active_tab, $kra_tabs)) $active_tab = 'instruction';
$cur     = $kra_tabs[$active_tab];
$cur_cat = $cur['cat'];
$kra_num = $cur['num'];

// Tab entry counts — only for KRA tabs
$counts = [];
$kra_slugs = ['instruction','research','extension','profdev'];
foreach ($kra_tabs as $slug => $t) {
    if (in_array($slug, $kra_slugs)) {
        $s = $pdo->prepare("SELECT COUNT(*) FROM pre_eval_entries WHERE user_id=? AND kra_category=?");
        $s->execute([$uid, $t['cat']]);
        $counts[$slug] = (int)$s->fetchColumn();
    } else {
        $counts[$slug] = 0;
    }
}

// Evidence guide — load ALL criteria for all KRA categories
$all_criteria = [];
try {
    $cg = $pdo->query("
        SELECT kra_category, criterion_label, max_points, description
        FROM scoring_criteria
        WHERE cycle_id IS NULL AND position_rank IS NULL AND is_active = 1
        ORDER BY kra_category, CASE WHEN criterion_label LIKE 'Criterion A%' THEN 1 WHEN criterion_label LIKE 'Criterion B%' THEN 2 WHEN criterion_label LIKE 'Criterion C%' THEN 3 WHEN criterion_label LIKE 'Criterion D%' THEN 4 ELSE 5 END ASC, max_points DESC, criterion_label ASC
    ");
    foreach ($cg->fetchAll() as $row) {
        $all_criteria[$row['kra_category']][] = $row;
    }
} catch (\Exception $e) { $all_criteria = []; }
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1.0">
<title>SUCFRMS — Self-Assessment</title>
<link href="../assets/vendor/bootstrap-icons/bootstrap-icons.min.css" rel="stylesheet">
<style>
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0;}
body{font-family:'Segoe UI',Arial,sans-serif;background:#f0f3f8;min-height:100vh;color:#1e293b;}

/* Navbar */
.pnav{background:#1a3a6b;height:48px;display:flex;align-items:center;padding:0 24px;gap:10px;position:sticky;top:0;z-index:200;}
.pnav-logo{width:28px;height:28px;border-radius:50%;object-fit:cover;border:1.5px solid rgba(201,168,76,.6);flex-shrink:0;}
.pnav-title{color:#fff;font-size:.85rem;font-weight:600;flex:1;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;min-width:0;}
.pnav-user{display:flex;align-items:center;gap:8px;cursor:pointer;position:relative;}
.pnav-name{color:rgba(255,255,255,.9);font-size:.8rem;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;max-width:120px;}
.pnav-avatar{width:28px;height:28px;border-radius:50%;object-fit:cover;border:1.5px solid rgba(201,168,76,.5);}
.pnav-avatar-ph{width:28px;height:28px;border-radius:50%;background:rgba(255,255,255,.15);border:1.5px solid rgba(201,168,76,.4);display:flex;align-items:center;justify-content:center;color:#fff;font-size:.65rem;font-weight:700;}
.pnav-dd{display:none;position:absolute;top:calc(100% + 6px);right:0;background:#fff;border:1px solid #e2e8f0;min-width:172px;box-shadow:0 4px 16px rgba(0,0,0,.1);z-index:500;}
.pnav-dd.open{display:block;}
.pnav-dd a{display:flex;align-items:center;gap:10px;padding:10px 16px;font-size:.82rem;color:#1e293b;text-decoration:none;border-bottom:1px solid #f1f5f9;}
.pnav-dd a:hover{background:#f8fafc;}
.pnav-dd a i{color:#1a3a6b;font-size:.85rem;width:14px;}
.pnav-dd a.out{color:#1e293b;}
.pnav-dd a.out i{color:#1e293b;}

/* Layout */
.pg{display:flex;min-height:calc(100vh - 48px);}

/* Score sidebar */
.sidebar{width:200px;flex-shrink:0;background:#fff;border-right:1px solid #e2e8f0;padding:.85rem .85rem;position:sticky;top:48px;height:calc(100vh - 48px);overflow-y:auto;display:flex;flex-direction:column;gap:.6rem;}
.sb-heading{font-size:.58rem;font-weight:700;color:#94a3b8;text-transform:uppercase;letter-spacing:.1em;}
.score-big{text-align:center;padding:.6rem 0;border-bottom:1px solid #f1f5f9;border-top:1px solid #f1f5f9;}
.score-num{font-size:1.9rem;font-weight:800;line-height:1;}
.score-sub{font-size:.6rem;color:#94a3b8;margin-top:2px;}
.score-min{font-size:.6rem;font-weight:600;margin-top:3px;}
.kra-row{display:flex;flex-direction:column;gap:2px;}
.kra-row-head{display:flex;justify-content:space-between;}
.kra-row-label{font-size:.65rem;color:#1e293b;font-weight:600;}
.kra-row-pts{font-size:.65rem;font-weight:700;color:#1a3a6b;}
.kra-bar{height:3px;background:#f1f5f9;overflow:hidden;}
.kra-bar-fill{height:100%;transition:width .4s;}
.kra-row-raw{font-size:.58rem;color:#94a3b8;}
.rank-box{background:#f8fafc;border:1px solid #e2e8f0;padding:.6rem;}
.rank-box-label{font-size:.58rem;color:#94a3b8;text-transform:uppercase;letter-spacing:.06em;margin-bottom:3px;}
.rank-cur{font-size:.75rem;font-weight:700;color:#1a3a6b;}
.rank-arrow{color:#94a3b8;margin:0 3px;font-size:.68rem;}
.rank-pot{font-size:.75rem;font-weight:700;color:#1e4d8c;}
.increment-badge{display:inline-flex;align-items:center;gap:3px;background:#f0f4fb;border:1px solid #bfdbfe;padding:2px 6px;font-size:.62rem;font-weight:700;color:#1e4d8c;margin-top:.35rem;}
.sb-note{font-size:.58rem;color:#94a3b8;line-height:1.5;padding-top:.6rem;border-top:1px solid #f1f5f9;margin-top:auto;}

/* Main */
.main{flex:1;min-width:0;padding:.9rem 1.25rem 3rem;}
.back-link{display:inline-flex;align-items:center;gap:5px;font-size:.72rem;color:#94a3b8;text-decoration:none;margin-bottom:.75rem;}
.back-link:hover{color:#1a3a6b;}

/* Tabs */
.tabs{display:flex;gap:0;border-bottom:2px solid #e2e8f0;margin-bottom:1rem;overflow-x:auto;-webkit-overflow-scrolling:touch;scrollbar-width:none;}
.tabs::-webkit-scrollbar{display:none;}
.tab-btn{display:flex;align-items:center;gap:5px;padding:.55rem .9rem;font-size:.75rem;font-weight:600;color:#64748b;border:none;background:none;cursor:pointer;border-bottom:2px solid transparent;margin-bottom:-2px;white-space:nowrap;text-decoration:none;border-radius:6px 6px 0 0;transition:color .15s,background .15s;}
.tab-btn:hover{color:#1a3a6b;background:#f0f4fb;}
.tab-btn.active{color:#1a3a6b;border-bottom-color:#1a3a6b;background:#fff;}
.tab-badge{background:#f1f5f9;color:#64748b;font-size:.6rem;font-weight:700;padding:1px 5px;border-radius:20px;}
.tab-btn.active .tab-badge{background:#1a3a6b;color:#fff;}

/* Two panels — entry only, full width */
.panels{display:grid;grid-template-columns:1fr;gap:.85rem;}
.panel{background:#fff;border:1px solid #e2e8f0;}
.panel-hd{padding:.75rem 1rem;border-bottom:1px solid #f1f5f9;display:flex;align-items:center;justify-content:space-between;gap:.5rem;}
.panel-hd-title{font-size:.78rem;font-weight:700;color:#1a3a6b;display:flex;align-items:center;gap:.4rem;}
.panel-body{padding:.9rem;}

/* Add form */
.add-form{background:#f8fafc;border:1px solid #e2e8f0;padding:.85rem;margin-bottom:.85rem;}
.fl{font-size:.68rem;font-weight:600;color:#475569;display:block;margin-bottom:3px;}
.fi{width:100%;padding:.4rem .6rem;border:1px solid #e2e8f0;font-size:.78rem;color:#1e293b;background:#fff;font-family:inherit;}
/* Hide number input spinners */
input[type=number].fi::-webkit-inner-spin-button,
input[type=number].fi::-webkit-outer-spin-button{-webkit-appearance:none;margin:0;}
input[type=number].fi{-moz-appearance:textfield;}
.fi:focus{outline:none;border-color:#1a3a6b;}
textarea.fi{resize:vertical;min-height:52px;}
.fg{margin-bottom:.6rem;}
.fg2{display:grid;grid-template-columns:1fr 1fr;gap:.6rem;margin-bottom:.6rem;}
.score-preview{font-size:.75rem;font-weight:700;color:#1a3a6b;display:flex;align-items:center;gap:.3rem;}
.score-preview span{font-size:1rem;}
.form-actions{display:flex;align-items:center;justify-content:space-between;margin-top:.75rem;flex-wrap:wrap;gap:.5rem;}

/* Entry list */
.entry-list{display:flex;flex-direction:column;gap:.45rem;}
.entry-card{border:1px solid #e2e8f0;}
.entry-card-top{display:flex;align-items:flex-start;justify-content:space-between;padding:.6rem .8rem .4rem;gap:.5rem;}
.entry-label{font-size:.78rem;font-weight:600;color:#1e293b;line-height:1.4;flex:1;}
.entry-notes{font-size:.65rem;color:#94a3b8;margin-top:2px;}
.entry-pts{font-size:.95rem;font-weight:800;color:#1a3a6b;white-space:nowrap;flex-shrink:0;}
.entry-files{padding:0 .8rem .4rem;display:flex;flex-wrap:wrap;gap:.3rem;}
.file-chip{display:inline-flex;align-items:center;gap:3px;background:#f1f5f9;border:1px solid #e2e8f0;padding:2px 7px;font-size:.65rem;color:#475569;max-width:160px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;text-decoration:none;}
.file-chip:hover{background:#e2e8f0;}
.chip-del{background:none;border:none;color:#94a3b8;cursor:pointer;padding:0;font-size:.65rem;line-height:1;margin-left:1px;}
.chip-del:hover{color:#1e293b;}
.entry-actions{padding:0 .8rem .6rem;display:flex;gap:.3rem;}
.empty-state{text-align:center;padding:2rem 1rem;color:#94a3b8;}
.empty-state i{font-size:1.75rem;display:block;margin-bottom:.4rem;}
.empty-state p{font-size:.75rem;margin:0;}

/* Evidence Guide Drawer */
.drawer-overlay{display:none;position:fixed;inset:0;background:rgba(0,0,0,0.3);z-index:999;}
.drawer-overlay.open{display:block;}
.drawer{position:fixed;top:0;right:-420px;width:400px;height:100vh;background:#fff;
        border-left:1px solid #e2e8f0;z-index:1000;display:flex;flex-direction:column;
        transition:right .25s ease;box-shadow:-4px 0 20px rgba(0,0,0,0.1);}
.drawer.open{right:0;}
.drawer-hd{padding:1rem 1.25rem;border-bottom:1px solid #e2e8f0;display:flex;align-items:center;justify-content:space-between;flex-shrink:0;background:#1a3a6b;}
.drawer-hd-title{font-size:.85rem;font-weight:700;color:#fff;}
.drawer-hd-sub{font-size:.65rem;color:rgba(255,255,255,.6);margin-top:2px;}
.drawer-close{background:none;border:none;color:rgba(255,255,255,.7);font-size:1.1rem;cursor:pointer;padding:0;line-height:1;}
.drawer-close:hover{color:#fff;}
.drawer-body{flex:1;overflow-y:auto;padding:.9rem 1rem;}
.drawer-search{margin-bottom:.75rem;position:relative;}
.drawer-search i{position:absolute;left:.6rem;top:50%;transform:translateY(-50%);color:#94a3b8;font-size:.75rem;}
.drawer-search input{width:100%;padding:.38rem .6rem .38rem 1.7rem;border:1px solid #e2e8f0;font-size:.75rem;color:#1e293b;background:#f8fafc;font-family:inherit;}
.drawer-search input:focus{outline:none;border-color:#1a3a6b;background:#fff;}
.crit-item{border:1px solid #e2e8f0;margin-bottom:.5rem;}
.crit-item-hd{display:flex;align-items:center;justify-content:space-between;padding:.55rem .75rem;cursor:pointer;background:#f8fafc;gap:.5rem;}
.crit-item-hd:hover{background:#f0f4fb;}
.crit-label{font-size:.75rem;font-weight:600;color:#1e293b;flex:1;line-height:1.35;}
.crit-pts{font-size:.7rem;font-weight:700;color:#1a3a6b;white-space:nowrap;background:#eff6ff;border:1px solid #bfdbfe;padding:1px 7px;flex-shrink:0;}
.crit-chevron{font-size:.65rem;color:#94a3b8;flex-shrink:0;transition:transform .2s;}
.crit-item.open .crit-chevron{transform:rotate(90deg);}
.crit-body{display:none;padding:.6rem .75rem;border-top:1px solid #f1f5f9;}
.crit-item.open .crit-body{display:block;}
.crit-evidence-label{font-size:.62rem;font-weight:700;color:#94a3b8;text-transform:uppercase;letter-spacing:.07em;margin-bottom:.4rem;}
.crit-evidence-item{display:flex;align-items:flex-start;gap:.4rem;font-size:.72rem;color:#475569;line-height:1.5;margin-bottom:.3rem;}
.crit-evidence-item::before{content:'•';color:#1a3a6b;font-weight:700;flex-shrink:0;margin-top:1px;}
.drawer-empty{text-align:center;padding:2rem;color:#94a3b8;font-size:.78rem;}
.d-tab{padding:.45rem .75rem;border:none;background:none;font-size:.72rem;font-weight:600;color:#64748b;cursor:pointer;border-bottom:2px solid transparent;margin-bottom:-1px;}
.d-tab:hover{color:#1a3a6b;}
.d-tab.active{color:#1a3a6b;border-bottom-color:#1a3a6b;}
.crit-group{margin-bottom:.75rem;}
.crit-group-label{font-size:.62rem;font-weight:700;text-transform:uppercase;letter-spacing:.08em;padding:.4rem .1rem .4rem 0;margin-bottom:.3rem;}

@media(max-width:500px){.drawer{width:100%;right:-100%;}}

/* Buttons */
.btn-p{background:#1a3a6b;color:#fff;border:none;padding:.4rem .9rem;font-size:.75rem;font-weight:600;cursor:pointer;}
.btn-p:hover{background:#1e4d8c;}
.btn-s{background:#fff;color:#1a3a6b;border:1px solid #1a3a6b;padding:.4rem .9rem;font-size:.75rem;font-weight:600;cursor:pointer;}
.btn-s:hover{background:#f0f4fb;}
.btn-xs{padding:2px 8px;border:1px solid;font-size:.65rem;font-weight:600;cursor:pointer;background:#fff;}
.btn-attach{border-color:#1a3a6b;color:#1a3a6b;}
.btn-attach:hover{background:#1a3a6b;color:#fff;}
.btn-rm{border-color:#94a3b8;color:#1e293b;}
.btn-rm:hover{background:#f8fafc;}

@media(max-width:900px){
    .panels{grid-template-columns:1fr;}
    .sidebar{width:100%;height:auto;position:static;border-right:none;border-bottom:1px solid #e2e8f0;}
    .pg{flex-direction:column;}
}
@media(max-width:600px){
    /* Navbar — show short name only */
    .pnav{padding:0 12px;gap:8px;}
    .pnav-title{font-size:.75rem;}
    .pnav-name{max-width:72px;font-size:.72rem;}

    /* Main area padding */
    .main{padding:.7rem .75rem 2.5rem;}

    /* Page title row — stack buttons */
    .main > div:first-of-type{flex-direction:column;align-items:flex-start !important;}

    /* Tabs — smaller font, always scrollable */
    .tab-btn{font-size:.68rem;padding:.45rem .65rem;}

    /* 2-column form grid → 1 column on mobile */
    .fg2{grid-template-columns:1fr !important;}

    /* Sidebar score compact */
    .sidebar{padding:.65rem .75rem;}
    .score-num{font-size:1.5rem;}
    .rank-box{padding:.5rem;}
    .rank-cur,.rank-pot{font-size:.68rem;}

    /* Panel body padding */
    .panel-body{padding:.65rem .75rem;}
    .add-form{padding:.65rem .75rem;}

    /* Entry cards compact */
    .entry-card-top{padding:.5rem .65rem .3rem;}
    .entry-files{padding:0 .65rem .35rem;}
    .entry-actions{padding:0 .65rem .5rem;}

    /* Buttons row in page header */
    .main > div[style*="space-between"] > div:last-child{
        width:100%;
        justify-content:flex-start;
    }
}
</style>
</head>
<body>

<!-- Navbar -->
<nav class="pnav">
    <img src="../assets/images/logo.jpg" class="pnav-logo" alt="">
    <span class="pnav-title">SUC Faculty Reclassification Management System (SUCFRMS)</span>
    <div class="pnav-user" id="nu" onclick="toggleDD()">
        <span class="pnav-name"><?= sanitize($full_name) ?></span>
        <?php if ($profile_pic): ?>
        <img src="../<?= sanitize($profile_pic) ?>" class="pnav-avatar" alt="">
        <?php else: ?>
        <div class="pnav-avatar-ph"><?= $init ?></div>
        <?php endif; ?>
        <div class="pnav-dd" id="ndd">
            <a href="../index.php?page=profile"><i class="bi bi-person-circle"></i>Profile</a>
            <a href="../index.php?page=my_application"><i class="bi bi-file-earmark-text"></i>Application</a>
            <a href="portal.php"><i class="bi bi-grid-1x2"></i>Portal</a>
            <a href="logout.php" class="out"><i class="bi bi-box-arrow-right"></i>Sign out</a>
        </div>
    </div>
</nav>

<div class="pg">

<!-- ── Score sidebar ── -->
<aside class="sidebar">
    <div class="sb-heading">Score Estimate</div>

    <div class="score-big">
        <div class="score-num" id="sbScore"
             style="color:<?= $weighted>=41?'#1e4d8c':($weighted>0?'#475569':'#94a3b8') ?>">
            <?= number_format($weighted,2) ?>
        </div>
        <div class="score-sub">Weighted / 100</div>
        <div class="score-min" id="sbMinNote"
             style="color:<?= $weighted>=41?'#1e4d8c':'#334155' ?>">
            <?= $weighted>=41?'✓ Meets minimum (41)':'✗ Below minimum (41)' ?>
        </div>
    </div>

    <div>
    <?php foreach ($kra_tabs as $slug => $t):
        if (!in_array($slug, ['instruction','research','extension','profdev'])) continue;
        $rpts  = min($t['max'], $raw[$t['cat']] ?? 0);
        $pct   = $t['max'] > 0 ? min(100, round(($rpts/$t['max'])*100)) : 0;
        $wpts  = round($rpts * ($weights[$t['cat']] ?? 0), 2);
    ?>
    <div class="kra-row" id="sb-kra-<?= $slug ?>">
        <div class="kra-row-head">
            <span class="kra-row-label"><?= $t['label'] ?></span>
            <span class="kra-row-pts"><?= number_format($wpts,1) ?>pt</span>
        </div>
        <div class="kra-bar">
            <div class="kra-bar-fill" style="width:<?= $pct ?>%;background:<?= $t['color'] ?>;"></div>
        </div>
        <div class="kra-row-raw"><?= number_format($rpts,1) ?>/<?= $t['max'] ?> · <?= round($weights[$t['cat']]*100) ?>% wt</div>
    </div>
    <?php endforeach; ?>
    </div>

    <div class="rank-box">
        <div class="rank-box-label">Rank Projection</div>
        <div style="display:flex;align-items:center;gap:3px;flex-wrap:wrap;">
            <span class="rank-cur" id="sbCurRank"><?= sanitize($rank?:'—') ?></span>
            <?php if ($pot_rank && $pot_rank !== $rank && $pot_rank !== '—'): ?>
            <span class="rank-arrow">→</span>
            <span class="rank-pot" id="sbPotRank"><?= sanitize($pot_rank) ?></span>
            <?php endif; ?>
        </div>
        <?php if ($sub_rank > 0): ?>
        <div class="increment-badge"
             title="Sub-rank = the number of steps forward in the rank scale your weighted score earns (e.g. +2 means you move up 2 positions from your current rank)."
             style="cursor:help;">
            +<?= $sub_rank ?> sub-rank<?= $sub_rank>1?'s':'' ?>
            <i class="bi bi-question-circle" style="font-size:.58rem;opacity:.65;margin-left:2px;"></i>
        </div>
        <?php elseif ($weighted > 0): ?>
        <div class="below-note" style="font-size:.6rem;color:#475569;margin-top:.3rem;font-weight:600;">Score below 41</div>
        <?php endif; ?>
    </div>

    <div class="sb-note">
        <i class="bi bi-info-circle me-1"></i>Estimate only — final scores are verified by your checker.
    </div>
</aside>

<!-- ── Main ── -->
<div class="main">

    <a href="portal.php" class="back-link"><i class="bi bi-arrow-left"></i> Back to Portal</a>

    <?php if (!$eligible): ?>
    <!-- ── Eligibility gate banner ── -->
    <div style="padding:.75rem 1rem;background:#fef2f2;border:1px solid #fecaca;border-left:4px solid #dc2626;border-radius:6px;margin-bottom:1rem;font-size:.8rem;color:#b91c1c;">
        <div style="font-weight:700;margin-bottom:.3rem;"><i class="bi bi-shield-x me-1"></i>Not eligible for self-assessment</div>
        <?php foreach ($eligibility_notes as $en): ?>
        <div style="margin-top:.2rem;">• <?= htmlspecialchars($en) ?></div>
        <?php endforeach; ?>
    </div>
    <?php else: ?>

    <?php endif; ?>

    <div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:.6rem;margin-bottom:1rem;">
        <div>
            <div style="font-size:.9rem;font-weight:700;color:#1a3a6b;">Self-Assessment</div>
            <div style="font-size:.68rem;color:#94a3b8;margin-top:2px;">Self-assess your KRA scores before a cycle opens.</div>
        </div>
        <div style="display:flex;gap:.4rem;flex-wrap:wrap;">
            <button class="btn-s" onclick="openDrawer()" style="font-size:.75rem;">
                Evidence Guide
            </button>
            <a href="evidence_repository.php" class="btn-s" style="text-decoration:none;font-size:.75rem;">
                Evidence Repository
            </a>
            <a href="pre_eval_pdf.php" target="_blank" class="btn-s" style="font-size:.75rem;text-decoration:none;" title="Download self-assessment PDF">
                Print Summary
            </a>
        </div>
    </div>

    <!-- Tabs -->
    <div class="tabs">
        <?php foreach ($kra_tabs as $slug => $t): ?>
        <a href="?tab=<?= $slug ?>" class="tab-btn <?= $active_tab===$slug?'active':'' ?>">
            <?= $t['label'] ?>
            <?php if ($counts[$slug] > 0): ?>
            <span class="tab-badge"><?= $counts[$slug] ?></span>
            <?php endif; ?>
        </a>
        <?php endforeach; ?>
    </div>

    <div class="panels">

    <?php if ($cur_cat === 'Auto Sub Rank'): ?>
    <!-- AUTO SUB RANK PANEL -->
    <?php
    // Load saved data — kept for backward compat; new UI uses AutoSubRankCalculator directly
    $pea_stmt = $pdo->prepare("SELECT * FROM pre_eval_auto_sub_rank WHERE user_id=?");
    $pea_stmt->execute([$uid]);
    $pea_data = $pea_stmt->fetch() ?: [];

    // Check doctorate from prof dev entries
    $doc_stmt2 = $pdo->prepare("SELECT remarks FROM pre_eval_entries WHERE user_id=? AND kra_category='Professional Development' AND (remarks LIKE '%doctorate%' OR remarks LIKE '%B-degree%') LIMIT 1");
    $doc_stmt2->execute([$uid]);
    $doc_rec2 = $doc_stmt2->fetch();
    $asr_doctorate_info = $doc_rec2 ? sanitize($doc_rec2['remarks']) : 'No doctorate entry found in Prof. Development tab.';

    $rank_eligible_asr = !preg_match('/Professor VI$|University Professor/', $rank);
    ?>
    <?php if (!empty($_GET['saved'])): ?>
    <div style="background:#f0fdf4;border:1px solid #bbf7d0;border-radius:6px;padding:.6rem .9rem;margin-bottom:.75rem;font-size:.75rem;color:#15803d;">
        <i class="bi bi-check-circle me-1"></i>Auto Sub Rank status saved.
    </div>
    <?php endif; ?>
    <div class="panel">
        <div class="panel-hd">
            <div class="panel-hd-title">Auto Sub Rank</div>
        </div>
        <div class="panel-body">
        <?php
        // ── Load AutoSubRankCalculator ─────────────────────────────────────
        if (!class_exists('\Scoring\AutoSubRankCalculator')) {
            require_once __DIR__ . '/../includes/scoring/autosubrank.php';
        }

        // Detect doctorate and award from pre_eval_entries
        $pe_doc_stmt = $pdo->prepare(
            "SELECT remarks FROM pre_eval_entries
             WHERE user_id=? AND kra_category='Professional Development'
             ORDER BY entry_id ASC"
        );
        $pe_doc_stmt->execute([$uid]);
        $pe_has_doctorate = false; $pe_doc_details = '';
        $pe_has_award     = false; $pe_awd_details = '';
        foreach ($pe_doc_stmt->fetchAll(\PDO::FETCH_COLUMN) as $pe_rem) {
            $pp  = array_map('trim', explode('|||', $pe_rem));
            $pct = $pp[0] ?? '';
            $psv = (float)($pp[2] ?? 0);
            if ($pct === 'B-degree' && $psv >= 40.0 && !$pe_has_doctorate) {
                $pe_has_doctorate = true;
                $pe_doc_details   = $pp[1] ?? 'Doctorate degree';
            }
            if ($pct === 'C-award' && $psv === 0.0 && !$pe_has_award) {
                $pe_has_award   = true;
                $pe_awd_details = $pp[1] ?? 'National/International Award';
            }
        }

        // Always recalculate on page load
        $pe_calc   = new \Scoring\AutoSubRankCalculator($pdo, 0, $uid);
        $pe_result = $pe_calc->calculateForPreEval(
            (float)($score_result['weighted_score'] ?? 0),
            $rank,
            $pe_has_doctorate, $pe_doc_details,
            $pe_has_award,     $pe_awd_details
        );
        $pe_calc->persistPreEval($pe_result);

        $pe_d_mode  = $pe_result['doctorate_mode'];
        $pe_a_mode  = $pe_result['award_mode'];
        $pe_d_color = \Scoring\AutoSubRankCalculator::modeColor($pe_d_mode);
        $pe_a_color = \Scoring\AutoSubRankCalculator::modeColor($pe_a_mode);
        $pe_d_label = \Scoring\AutoSubRankCalculator::modeLabel($pe_d_mode);
        $pe_a_label = \Scoring\AutoSubRankCalculator::modeLabel($pe_a_mode);
        $pe_ri      = $pe_result['total_rank_increase'];
        ?>

        <?php if (!empty($_GET['saved'])): ?>
        <div style="background:#f0fdf4;border:1px solid #bbf7d0;border-radius:6px;padding:.5rem .85rem;margin-bottom:.75rem;font-size:.75rem;color:#15803d;">
            <i class="bi bi-check-circle me-1"></i>Auto Sub Rank recalculated.
        </div>
        <?php endif; ?>

        <!-- Result banner -->
        <div style="background:<?= $pe_ri > 0 ? '#f0fdf4' : '#f8fafc' ?>;border:1.5px solid <?= $pe_ri > 0 ? '#bbf7d0' : '#e2e8f0' ?>;border-radius:8px;padding:.75rem 1rem;margin-bottom:.85rem;display:flex;align-items:center;gap:.6rem;">
            <?php if ($pe_ri > 0): ?>
            <i class="bi bi-arrow-up-circle-fill" style="color:#16a34a;font-size:1.1rem;flex-shrink:0;"></i>
            <div>
                <div style="font-weight:700;color:#16a34a;font-size:.88rem;">+<?= $pe_ri ?> automatic sub-rank<?= $pe_ri > 1 ? 's' : '' ?></div>
                <div style="font-size:.72rem;color:#64748b;margin-top:2px;">System-calculated — no manual choice required</div>
            </div>
            <?php else: ?>
            <i class="bi bi-dash-circle" style="color:#64748b;font-size:1.1rem;flex-shrink:0;"></i>
            <div>
                <div style="font-weight:600;color:#64748b;font-size:.88rem;">No automatic sub-rank increase</div>
                <div style="font-size:.72rem;color:#94a3b8;margin-top:2px;">Add a doctorate or award entry in Prof. Development to qualify</div>
            </div>
            <?php endif; ?>
        </div>

        <!-- Criterion 1: Doctorate -->
        <div class="add-form" style="margin-bottom:.75rem;">
            <div style="font-size:.72rem;font-weight:700;color:#1a3a6b;text-transform:uppercase;letter-spacing:.06em;margin-bottom:.5rem;display:flex;align-items:center;gap:.5rem;">
                <i class="bi bi-mortarboard"></i>1 — Doctorate Degree
                <span style="background:<?= $pe_d_color ?>18;color:<?= $pe_d_color ?>;border:1px solid <?= $pe_d_color ?>40;padding:1px 8px;border-radius:20px;font-size:.65rem;font-weight:700;text-transform:uppercase;letter-spacing:.04em;margin-left:auto;">
                    <?= htmlspecialchars($pe_d_label) ?>
                </span>
            </div>
            <?php if ($pe_has_doctorate): ?>
            <div class="fg">
                <label class="fl">Detected From</label>
                <div style="padding:.4rem .6rem;background:#f8fafc;border:1px solid #e2e8f0;font-size:.75rem;color:#475569;border-radius:0;">
                    <?= htmlspecialchars($pe_doc_details) ?>
                </div>
            </div>
            <div class="fg">
                <label class="fl">Why This Decision</label>
                <div style="padding:.4rem .6rem;background:#f8fafc;border:1px solid #e2e8f0;font-size:.75rem;color:#475569;border-radius:0;line-height:1.5;">
                    <?= htmlspecialchars($pe_result['doctorate_reason']) ?>
                </div>
            </div>
            <?php else: ?>
            <div style="font-size:.78rem;color:#64748b;padding:.4rem 0;">
                <i class="bi bi-exclamation-circle me-1" style="color:#94a3b8;"></i>
                No doctorate entry found.
                <a href="?tab=profdev" style="color:#1e4d8c;font-weight:600;">Add a doctorate in Prof. Development (B-degree ≥ 40)</a>
            </div>
            <?php endif; ?>
        </div>

        <!-- Criterion 2: Award -->
        <div class="add-form" style="margin-bottom:.75rem;">
            <div style="font-size:.72rem;font-weight:700;color:#1a3a6b;text-transform:uppercase;letter-spacing:.06em;margin-bottom:.5rem;display:flex;align-items:center;gap:.5rem;">
                <i class="bi bi-trophy"></i>2 — National / International Award
                <span style="background:<?= $pe_a_color ?>18;color:<?= $pe_a_color ?>;border:1px solid <?= $pe_a_color ?>40;padding:1px 8px;border-radius:20px;font-size:.65rem;font-weight:700;text-transform:uppercase;letter-spacing:.04em;margin-left:auto;">
                    <?= htmlspecialchars($pe_a_label) ?>
                </span>
            </div>
            <?php if ($pe_has_award): ?>
            <div class="fg">
                <label class="fl">Award</label>
                <div style="padding:.4rem .6rem;background:#f8fafc;border:1px solid #e2e8f0;font-size:.75rem;color:#475569;border-radius:0;">
                    <?= htmlspecialchars($pe_awd_details) ?>
                </div>
            </div>
            <div class="fg">
                <label class="fl">Why This Decision</label>
                <div style="padding:.4rem .6rem;background:#f8fafc;border:1px solid #e2e8f0;font-size:.75rem;color:#475569;border-radius:0;line-height:1.5;">
                    <?= htmlspecialchars($pe_result['award_reason']) ?>
                </div>
            </div>
            <?php else: ?>
            <div style="font-size:.78rem;color:#64748b;padding:.4rem 0;">
                <i class="bi bi-exclamation-circle me-1" style="color:#94a3b8;"></i>
                No national/international award found.
                <a href="?tab=profdev" style="color:#1e4d8c;font-weight:600;">Add award in Prof. Development (C-award, national/intl)</a>
            </div>
            <?php endif; ?>
        </div>

        <div style="font-size:.7rem;color:#94a3b8;margin-top:.5rem;">
            <i class="bi bi-arrow-repeat me-1"></i>Recalculated automatically each time you view this tab.
            Score used: <strong><?= number_format((float)($pe_result['weighted_score_at_calc'] ?? 0), 2) ?></strong>
        </div>
        </div>
    </div>


    <?php elseif ($cur_cat === 'Position Requirements'): ?>
    <!-- POSITION REQUIREMENTS PANEL -->
    <div class="panel">
        <div class="panel-hd">
            <div class="panel-hd-title"><i class="bi bi-file-earmark-check me-1"></i>Position Requirements — <?= sanitize($rank ?: 'Unknown Rank') ?></div>
        </div>
        <div class="panel-body">
            <?php
            $is_instructor_pr = strpos($rank, 'Instructor') !== false;
            $is_asst_pr       = strpos($rank, 'Assistant Professor') !== false;
            $is_assoc_pr      = strpos($rank, 'Associate Professor') !== false;
            $is_univ_pr       = $rank === 'University Professor';
            $is_professor_pr  = strpos($rank, 'Professor') !== false && !$is_asst_pr && !$is_assoc_pr && !$is_univ_pr;
            ?>
            <div style="background:#eff6ff;border:1px solid #bfdbfe;border-radius:8px;padding:.75rem 1rem;margin-bottom:1rem;font-size:.78rem;color:#1e4d8c;">
                <i class="bi bi-info-circle me-1"></i>These are the additional documents required for your position when reclassifying. Prepare them ahead of time before a cycle opens.
            </div>

            <?php if ($is_instructor_pr || $is_asst_pr || $is_assoc_pr): ?>
            <div style="background:#f0fdf4;border:1px solid #bbf7d0;border-radius:8px;padding:1.5rem;text-align:center;">
                <i class="bi bi-check-circle-fill" style="color:#16a34a;font-size:1.75rem;margin-bottom:.6rem;display:block;"></i>
                <div style="font-weight:700;color:#16a34a;font-size:.88rem;margin-bottom:.3rem;">No Additional Documents Required</div>
                <div style="color:#15803d;font-size:.78rem;">Your current rank (<?= sanitize($rank) ?>) does not require additional position documentation beyond the standard KRA evidence.</div>
            </div>

            <?php elseif ($is_professor_pr): ?>
            <div style="border:1px solid #e2e8f0;border-radius:8px;overflow:hidden;margin-bottom:.75rem;">
                <div style="background:#1e4d8c;padding:.45rem .85rem;">
                    <span style="color:#fff;font-size:.72rem;font-weight:700;text-transform:uppercase;letter-spacing:.06em;">Professor Position Requirements</span>
                </div>
                <table style="width:100%;border-collapse:collapse;font-size:.82rem;">
                    <thead>
                        <tr style="background:#f8fafc;border-bottom:1px solid #e2e8f0;">
                            <th style="padding:.6rem .75rem;color:#64748b;font-size:.68rem;font-weight:700;text-transform:uppercase;width:36px;">#</th>
                            <th style="padding:.6rem .75rem;color:#64748b;font-size:.68rem;font-weight:700;text-transform:uppercase;">Requirement</th>
                            <th style="padding:.6rem .75rem;color:#64748b;font-size:.68rem;font-weight:700;text-transform:uppercase;">Evidence Needed</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr style="border-bottom:1px solid #f1f5f9;">
                            <td style="padding:.7rem .75rem;text-align:center;font-weight:700;color:#64748b;">1</td>
                            <td style="padding:.7rem .75rem;font-weight:600;color:#1e293b;">CAV Transcript of Records</td>
                            <td style="padding:.7rem .75rem;color:#475569;font-size:.78rem;">Official CAV-authenticated transcript from CHED or your institution's registrar certifying your academic credentials.</td>
                        </tr>
                        <tr>
                            <td style="padding:.7rem .75rem;text-align:center;font-weight:700;color:#64748b;">2</td>
                            <td style="padding:.7rem .75rem;font-weight:600;color:#1e293b;">
                                Internationally Indexed Article
                                <div style="font-size:.72rem;color:#64748b;font-weight:400;margin-top:2px;">Scopus, WoS, or ACI — published within the last 3 years</div>
                            </td>
                            <td style="padding:.7rem .75rem;color:#475569;font-size:.78rem;">
                                Published article with indexing proof (Scopus author profile, WoS record, or ACI listing). Must be within 3 years of application date. Co-authored articles are accepted.
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <?php elseif ($is_univ_pr): ?>
            <div style="border:1px solid #e2e8f0;border-radius:8px;overflow:hidden;margin-bottom:.75rem;">
                <div style="background:#1e4d8c;padding:.45rem .85rem;">
                    <span style="color:#fff;font-size:.72rem;font-weight:700;text-transform:uppercase;letter-spacing:.06em;">University Professor Requirements</span>
                </div>
                <table style="width:100%;border-collapse:collapse;font-size:.82rem;">
                    <thead>
                        <tr style="background:#f8fafc;border-bottom:1px solid #e2e8f0;">
                            <th style="padding:.6rem .75rem;color:#64748b;font-size:.68rem;font-weight:700;text-transform:uppercase;width:36px;">#</th>
                            <th style="padding:.6rem .75rem;color:#64748b;font-size:.68rem;font-weight:700;text-transform:uppercase;">Requirement</th>
                            <th style="padding:.6rem .75rem;color:#64748b;font-size:.68rem;font-weight:700;text-transform:uppercase;">Evidence Needed</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr>
                            <td style="padding:.7rem .75rem;text-align:center;font-weight:700;color:#64748b;">1</td>
                            <td style="padding:.7rem .75rem;font-weight:600;color:#1e293b;">College/University Professor Certification Form</td>
                            <td style="padding:.7rem .75rem;color:#475569;font-size:.78rem;">
                                Signed certification form from the College Dean and University President confirming eligibility for University Professor status. Must include endorsement from the Academic Council.
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <?php else: ?>
            <div style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:8px;padding:1.5rem;text-align:center;color:#94a3b8;font-size:.82rem;">
                <i class="bi bi-question-circle" style="font-size:1.5rem;display:block;margin-bottom:.5rem;"></i>
                Position requirements for your rank (<?= sanitize($rank ?: 'unknown') ?>) are not yet configured. Contact your administrator.
            </div>
            <?php endif; ?>

            <div style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:8px;padding:.75rem 1rem;margin-top:.75rem;font-size:.78rem;color:#64748b;">
                <i class="bi bi-shield-check me-1"></i>Upload these documents in the actual Reclassification application when a cycle is open — not here in Self-Assessment.
            </div>
        </div>
    </div>

    <?php else: ?>
        <!-- KRA ENTRIES PANEL -->
        <div class="panel">
            <div class="panel-hd">
                <div class="panel-hd-title"><?= $cur['label'] ?></div>
                <div style="display:flex;gap:.4rem;align-items:center;">
                    <button class="btn-p" onclick="toggleForm()" id="addBtn"
                            <?= !$eligible ? 'disabled title="Not eligible — see notice above" style="opacity:.45;cursor:not-allowed;"' : '' ?>>
                        + Add Entry
                    </button>
                    <button class="btn-p" id="resetAllBtn"
                            onclick="confirmResetAll()"
                            style="background:#fef2f2;color:#dc2626;border:1px solid #fecaca;"
                            <?= !$eligible ? 'disabled style="opacity:.45;cursor:not-allowed;"' : '' ?>>
                        <i class="bi bi-arrow-counterclockwise"></i> Reset All
                    </button>
                </div>
            </div>
            <div class="panel-body">

                <!-- Entry form -->
                <div class="add-form" id="addForm" style="display:none;">
                    <input type="hidden" id="editId" value="">

                    <?php if ($cur_cat === 'Instruction'): ?>
                    <div class="fg">
                        <label class="fl">Criterion Type</label>
                        <select class="fi" id="critType" onchange="switchInstrForm();previewScore()">
                            <option value="A-set-sef">A — Teaching Evaluation (SET + SEF)</option>
                            <option value="B-material">B — Instructional Material</option>
                            <option value="C-thesis">C — Thesis / Dissertation Advising</option>
                        </select>
                    </div>
                    <div id="fA">
                        <div class="fg2">
                            <div><label class="fl">SET Average (%)</label><input type="number" class="fi" id="setV" min="0" max="100" value="0" oninput="previewScore()"></div>
                            <div><label class="fl">SEF Average (%)</label><input type="number" class="fi" id="sefV" min="0" max="100" value="0" oninput="previewScore()"></div>
                        </div>
                        <div class="fg"><label class="fl">Notes (optional)</label><textarea class="fi" id="notesA" rows="2"></textarea></div>
                    </div>
                    <div id="fB" style="display:none">
                        <div class="fg">
                            <label class="fl">Material Type</label>
                            <select class="fi" id="matType" onchange="previewScore()">
                                <option>Textbook — Sole Author (30 pts)</option>
                                <option>Textbook — Co-Author (30 pts)</option>
                                <option>Textbook Chapter — Sole Author (10 pts)</option>
                                <option>Textbook Chapter — Co-Author (10 pts)</option>
                                <option>Manual/Module — Sole Author (16 pts)</option>
                                <option>Manual/Module — Co-Author (16 pts)</option>
                                <option>Multimedia Teaching Material (16 pts)</option>
                                <option>Validated Testing Material (10 pts)</option>
                                <option>Academic Program Dev — Lead (10 pts)</option>
                                <option>Academic Program Dev — Contributor (5 pts)</option>
                            </select>
                        </div>
                        <div class="fg"><label class="fl">Contribution % (co-authorship)</label><input type="number" class="fi" id="contribB" value="100" min="1" max="100" oninput="previewScore()"></div>
                        <div class="fg"><label class="fl">Title / Description</label><input type="text" class="fi" id="titleB" placeholder="Title of material"></div>
                    </div>
                    <div id="fC" style="display:none">
                        <div class="fg">
                            <label class="fl">Type</label>
                            <select class="fi" id="thesisType" onchange="previewScore()">
                                <option>Doctoral Dissertation — Adviser (10 pts)</option>
                                <option>Master's Thesis — Adviser (8 pts)</option>
                                <option>Undergraduate Thesis — Adviser (5 pts)</option>
                                <option>Special Project / Capstone — Adviser (3 pts)</option>
                                <option>Doctoral Dissertation — Panel Member (6 pts)</option>
                                <option>Master's Thesis — Panel Member (4 pts)</option>
                                <option>Undergraduate Thesis — Panel Member (2 pts)</option>
                                <option>Special Project / Capstone — Panel Member (1 pt)</option>
                                <option>Mentor: Student/Team Competition Winner (⚠ pts pending confirmation)</option>
                            </select>
                        </div>
                        <div class="fg"><label class="fl">Advisee / Student Name (optional)</label><input type="text" class="fi" id="advisee" placeholder="Name of advisee"></div>
                    </div>

                    <?php elseif ($cur_cat === 'Research'): ?>
                    <div class="fg">
                        <label class="fl">Output Type</label>
                        <select class="fi" id="resType" onchange="previewScore()">
                            <option value="Book, Sole Author (100pts)">Book — Sole Author (100 pts)</option>
                            <option value="Book, Co-Author (100pts)">Book — Co-Author (100 pts)</option>
                            <option value="Monograph, Sole Author (100pts)">Monograph — Sole Author (100 pts)</option>
                            <option value="Monograph, Co-Author (100pts)">Monograph — Co-Author (100 pts)</option>
                            <option value="Indexed Journal Article, Sole Author (50pts)">Indexed Journal Article — Sole Author (50 pts)</option>
                            <option value="Indexed Journal Article, Co-Author (50pts)">Indexed Journal Article — Co-Author (50 pts)</option>
                            <option value="Book Chapter, Sole Author (35pts)">Book Chapter — Sole Author (35 pts)</option>
                            <option value="Book Chapter, Co-Author (35pts)">Book Chapter — Co-Author (35 pts)</option>
                            <option value="Research Policy/Product, Lead (35pts)">Research → Policy/Product — Lead (35 pts)</option>
                            <option value="Research Policy/Product, Contributor (35pts)">Research → Policy/Product — Contributor (35 pts)</option>
                            <option value="Peer-Reviewed Scholarly Output (10pts)">Other Peer-Reviewed Output (10 pts)</option>
                            <option value="Local Citation (5pts)">Local Citation (5 pts each)</option>
                            <option value="International Citation (10pts)">International Citation (10 pts each)</option>
                        </select>
                    </div>
                    <div class="fg"><label class="fl">Title / Journal Name</label><input type="text" class="fi" id="resTitle" placeholder="Publication title"></div>
                    <div class="fg"><label class="fl">Contribution % (co-authorship)</label><input type="number" class="fi" id="resContrib" value="100" min="1" max="100" oninput="previewScore()"></div>

                    <?php elseif ($cur_cat === 'Extension'): ?>
                    <div class="fg">
                        <label class="fl">Criterion / Type</label>
                        <select class="fi" id="extType" onchange="updateExtOpts();previewScore()">
                            <option value="moa-linkage">MOA / Linkage (5 pts)</option>
                            <option value="income">Income Generating Project</option>
                            <option value="accredit-local">Accreditation / QA - Local (8 pts)</option>
                            <option value="accredit-intl">Accreditation / QA - International (10 pts)</option>
                            <option value="resource-speaker-local">Resource Speaker - Local (2 pts)</option>
                            <option value="resource-speaker-intl">Resource Speaker - International (3 pts)</option>
                            <option value="outreach-isr-lead">Outreach / ISR - Lead (5 pts)</option>
                            <option value="outreach-isr-member">Outreach / ISR - Member (2 pts)</option>
                            <option value="csr-satisfaction">CSR Satisfaction Rating (max 20 pts)</option>
                            <option value="consultant-local">Consultant / Expert - Local (8 pts)</option>
                            <option value="consultant-intl">Consultant / Expert - International (10 pts)</option>
                            <option value="judge-research">Judge - Research/Competition (2 pts)</option>
                            <option value="judge-other">Judge - Other Event (1 pt)</option>
                        </select>
                    </div>
                    <div class="fg"><label class="fl">Activity / Project / Designation Name</label><input type="text" class="fi" id="extTitle" placeholder="e.g. Community outreach program"></div>
                    <div class="fg" id="extVal1Wrap" style="display:none;"><label class="fl" id="extVal1Label">Value</label><input type="number" class="fi" id="extVal1" value="" min="0" oninput="previewScore()"></div>
                    <div class="fg" id="extVal2Wrap" style="display:none;"><label class="fl">Role</label><select class="fi" id="extVal2" onchange="previewScore()"><option value="lead">Lead</option><option value="co">Co/Member</option></select></div>

                    <?php else: // Professional Development ?>
                    <div class="fg">
                        <label class="fl">Credential / Activity Type</label>
                        <select class="fi" id="pdType" onchange="updatePdOpts();previewScore()">
                            <option value="A-org">Active Professional Org Membership (5 pts)</option>
                            <option value="B-training">Training / Conference</option>
                            <option value="B-degree">Educational Qualification</option>
                            <option value="B-paper">Paper Presentation</option>
                            <option value="C-award">Award / Recognition</option>
                        </select>
                    </div>
                    <div class="fg"><label class="fl">Description / Title</label><input type="text" class="fi" id="pdDesc" placeholder="e.g. PhD in Education"></div>
                    <div class="fg" id="pdSubWrap" style="display:none">
                        <label class="fl">Value / Sub-type</label>
                        <select class="fi" id="pdSubval" onchange="previewScore()"></select>
                    </div>
                    <?php endif; ?>

                    <div class="fg"><label class="fl">Personal Notes (optional)</label><textarea class="fi" id="entryNotes" rows="2" placeholder="Your own notes..."></textarea></div>

                    <div class="form-actions">
                        <div class="score-preview"><i class="bi bi-calculator"></i>Est. score: <span id="scorePreview">0.00</span> pts</div>
                        <div style="display:flex;gap:.4rem;">
                            <button class="btn-s" onclick="cancelForm()">Cancel</button>
                            <button class="btn-p" onclick="saveEntry()">Save Entry</button>
                        </div>
                    </div>
                </div>

                <!-- Sub-cap progress bars — hidden until get_score runs -->
                <?php
                // Sub-cap definitions per KRA (label, JS key into kra_detail, cap)
                $subcap_defs = [
                    'Instruction'              => [['A — Teaching Effectiveness','criterion_a',60],['B — Instructional Materials','criterion_b',30],['C — Research/Advisory','criterion_c',10]],
                    'Research'                 => [['A — Books/Monographs','criterion_a_raw',null],['B — Articles/Chapters','criterion_b_raw',null],['C — Citations/Policy','criterion_c_raw',null]],
                    'Extension'                => [['A — Income','criterion_a',null],['B — MOA/Linkage','criterion_b',null],['C — Outreach','criterion_c',null],['D — Bonus','criterion_d_bonus',null]],
                    'Professional Development' => [['A — Prof. Orgs','criterion_a',20],['B — Training/Degrees','criterion_b',60],['C — Awards','criterion_c',20],['D — Bonus','criterion_d_bonus',20]],
                ];
                $bars = $subcap_defs[$cur_cat] ?? [];
                // kra_detail key in get_score JSON
                $detail_key = ['Instruction'=>'kra1','Research'=>'kra2','Extension'=>'kra3','Professional Development'=>'kra4'][$cur_cat] ?? '';
                ?>
                <div id="subcapBars" style="display:none;background:#f8fafc;border:1px solid #e2e8f0;border-top:none;padding:.55rem .85rem .45rem;margin-bottom:.6rem;">
                    <div style="font-size:.58rem;font-weight:700;color:#94a3b8;text-transform:uppercase;letter-spacing:.08em;margin-bottom:.4rem;">
                        <i class="bi bi-bar-chart-line me-1"></i>Criterion breakdown
                        <span style="font-weight:400;font-style:italic;margin-left:.3rem;">(updates after each save)</span>
                    </div>
                    <div id="subcapList" style="display:flex;flex-direction:column;gap:.3rem;">
                        <?php foreach ($bars as [$lbl, $key, $cap]): ?>
                        <div class="subcap-row" data-key="<?= $key ?>" data-cap="<?= $cap ?? '' ?>" data-detail="<?= $detail_key ?>">
                            <div style="display:flex;justify-content:space-between;align-items:baseline;margin-bottom:1px;">
                                <span style="font-size:.65rem;color:#475569;font-weight:600;"><?= $lbl ?></span>
                                <span class="subcap-pts" style="font-size:.65rem;font-weight:700;color:#1a3a6b;">—</span>
                            </div>
                            <div style="height:4px;background:#e2e8f0;border-radius:3px;overflow:hidden;">
                                <div class="subcap-fill" style="height:100%;width:0%;background:#1e4d8c;border-radius:3px;transition:width .35s ease;"></div>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>

                <!-- Entry list -->
                <div class="entry-list" id="entryList">
                    <?php
                    $es = ['Instruction'=>['bi-book-half',100,'Teaching effectiveness (SET/SEF), instructional materials, and thesis/capstone advisory work.'],
                           'Research'=>['bi-journal-text',100,'Publications, book chapters, citations, and policy research outputs.'],
                           'Extension'=>['bi-people-fill',100,'Community outreach programs, MOAs/linkages, and income-generating extension activities.'],
                           'Professional Development'=>['bi-award-fill',100,'Professional org membership, training/conferences, paper presentations, degrees, and awards.']];
                    $ei = $es[$cur_cat] ?? ['bi-inbox',0,''];
                    ?>
                    <div class="empty-state">
                        <i class="bi <?= $ei[0] ?>"></i>
                        <p>No entries yet. Click <strong>+ Add Entry</strong> to start.</p>
                        <?php if ($ei[2]): ?>
                        <p style="margin-top:.35rem;font-size:.68rem;color:#b0bec5;max-width:240px;margin-left:auto;margin-right:auto;"><?= $ei[2] ?></p>
                        <?php endif; ?>
                        <?php if ($ei[1]): ?>
                        <p style="margin-top:.25rem;font-size:.65rem;color:#bfdbfe;font-weight:700;">Max: <?= $ei[1] ?> pts</p>
                        <?php endif; ?>
                    </div>
                </div>

                <input type="file" id="entryFileInput" style="display:none" accept=".pdf,.jpg,.jpeg,.png" onchange="attachFile(this)">
            </div>
        </div>

    <?php endif; // end autosubrank / posreq / kra panel ?>

    </div><!-- /panels -->
</div><!-- /main -->
</div><!-- /pg -->

<!-- ── Evidence Guide Drawer ── -->
<div class="drawer-overlay" id="drawerOverlay" onclick="closeDrawer()"></div>
<div class="drawer" id="evidenceDrawer">

    <!-- Drawer header -->
    <div class="drawer-hd">
        <div>
            <div class="drawer-hd-title">Evidence Guide</div>
            <div class="drawer-hd-sub">Required documents per criterion</div>
        </div>
        <button class="drawer-close" onclick="closeDrawer()" title="Close">&times;</button>
    </div>

    <!-- KRA filter tabs -->
    <div style="display:flex;border-bottom:1px solid #e2e8f0;background:#f8fafc;flex-shrink:0;">
        <button class="d-tab active" data-cat="" onclick="switchDrawerTab(this,'')">All</button>
        <button class="d-tab" data-cat="Instruction" onclick="switchDrawerTab(this,'Instruction')">KRA I</button>
        <button class="d-tab" data-cat="Research" onclick="switchDrawerTab(this,'Research')">KRA II</button>
        <button class="d-tab" data-cat="Extension" onclick="switchDrawerTab(this,'Extension')">KRA III</button>
        <button class="d-tab" data-cat="Professional Development" onclick="switchDrawerTab(this,'Professional Development')">KRA IV</button>
    </div>

    <div class="drawer-body">
        <!-- Search -->
        <div class="drawer-search">
            <i class="bi bi-search"></i>
            <input type="text" id="drawerSearch" placeholder="Search criteria..." oninput="filterCriteria()">
        </div>

        <!-- Criteria list -->
        <div id="criteriaList">
            <?php foreach ($all_criteria as $cat => $crits):
                $cat_colors = [
                    'Instruction'=>'#1e4d8c','Research'=>'#1a3a6b',
                    'Extension'=>'#1e4d8c','Professional Development'=>'#475569'
                ];
                $cat_labels = [
                    'Instruction'=>'KRA I — Instruction','Research'=>'KRA II — Research',
                    'Extension'=>'KRA III — Extension','Professional Development'=>'KRA IV — Prof. Development'
                ];
                $cc = $cat_colors[$cat] ?? '#1a3a6b';
            ?>
            <div class="crit-group" data-cat="<?= htmlspecialchars($cat) ?>">
                <div class="crit-group-label" style="color:<?= $cc ?>">
                    <?= htmlspecialchars($cat_labels[$cat] ?? $cat) ?>
                </div>
                <?php foreach ($crits as $cr):
                    $desc = trim($cr['description'] ?? '');
                    $evidences = $desc
                        ? array_filter(array_map('trim', preg_split('/\.\s+(?=[A-Z])/', $desc)))
                        : [];
                ?>
                <div class="crit-item" data-label="<?= htmlspecialchars(strtolower($cr['criterion_label'])) ?>" data-cat="<?= htmlspecialchars($cat) ?>">
                    <div class="crit-item-hd" onclick="toggleCrit(this)">
                        <span class="crit-label"><?= htmlspecialchars($cr['criterion_label']) ?></span>
                        <span class="crit-pts" style="color:<?= $cc ?>;background:<?= $cc ?>18;border-color:<?= $cc ?>44;"><?= number_format($cr['max_points'], 0) ?> pts</span>
                        <i class="bi bi-chevron-right crit-chevron"></i>
                    </div>
                    <div class="crit-body">
                        <?php if ($evidences): ?>
                        <div class="crit-evidence-label">Required Evidence</div>
                        <?php foreach ($evidences as $ev): if (!trim($ev)) continue; ?>
                        <div class="crit-evidence-item"><?= htmlspecialchars(rtrim($ev, '.')) ?>.</div>
                        <?php endforeach; ?>
                        <?php else: ?>
                        <div style="font-size:.72rem;color:#94a3b8;">No evidence details available.</div>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
            <?php endforeach; ?>
        </div>

    </div>
</div>

<script>
const AJAX = 'pre_eval_ajax.php';
const CAT  = <?= json_encode($cur_cat) ?>;
const KRA_NUM = <?= $kra_num ?>;
const EMPTY_STATE_INFO = <?= json_encode([
    'Instruction'              => ['icon'=>'bi-book-half',    'max'=>100, 'desc'=>'Teaching effectiveness (SET/SEF ratings), instructional materials, and thesis/capstone advisory work.'],
    'Research'                 => ['icon'=>'bi-journal-text', 'max'=>100, 'desc'=>'Publications, book chapters, citations, and policy research outputs.'],
    'Extension'                => ['icon'=>'bi-people-fill',  'max'=>100, 'desc'=>'Community outreach programs, MOAs/linkages, and income-generating extension activities.'],
    'Professional Development' => ['icon'=>'bi-award-fill',   'max'=>100, 'desc'=>'Professional org membership, training/conferences, paper presentations, degrees, and awards.'],
]) ?>;
let attachTarget = null;

// ── Nav dropdown ──────────────────────────────────────────────
function toggleDD(){document.getElementById('ndd').classList.toggle('open');}
document.addEventListener('click',e=>{
    const n=document.getElementById('nu'),d=document.getElementById('ndd');
    if(d&&n&&!n.contains(e.target))d.classList.remove('open');
});

const ELIGIBLE = <?= $eligible ? 'true' : 'false' ?>;

// ── Add/cancel form ───────────────────────────────────────────
function toggleForm(){
    if (!ELIGIBLE) return; // server-side gate reflected in UI
    const f=document.getElementById('addForm');
    const open=f.style.display!=='none';
    f.style.display=open?'none':'block';
    document.getElementById('addBtn').innerHTML=open
        ?'<i class="bi bi-plus-lg"></i> Add Entry'
        :'<i class="bi bi-x"></i> Cancel';
    if(!open){resetForm();previewScore();}
}
function cancelForm(){
    document.getElementById('addForm').style.display='none';
    document.getElementById('addBtn').innerHTML='<i class="bi bi-plus-lg"></i> Add Entry';
    resetForm();
}
function resetForm(){
    document.getElementById('editId').value='';
    const n=document.getElementById('entryNotes'); if(n)n.value='';
    const sp=document.getElementById('scorePreview'); if(sp)sp.textContent='0.00';
}

// ── Instruction form switch ────────────────────────────────────
function switchInstrForm(){
    const t=document.getElementById('critType')?.value;
    document.getElementById('fA').style.display=t==='A-set-sef'?'block':'none';
    document.getElementById('fB').style.display=t==='B-material'?'block':'none';
    document.getElementById('fC').style.display=t==='C-thesis'?'block':'none';
}

// ── ProfDev sub-options ────────────────────────────────────────
const PD_OPTS={
    'B-training':[['1','1 pt — Local'],['2','2 pts — International']],
    'B-degree':  [['10','10 pts — Post-Master\'s/Post-Doctoral Cert.'],['20','20 pts — Additional Master\'s']],
    'B-paper':   [['3','3 pts — Local Presentation'],['5','5 pts — International Presentation']],
    'C-award':   [['2','2 pts — Institutional'],['3','3 pts — Local'],['4','4 pts — Regional'],['0','National/International (+1 sub-rank, 0 pts)']],
};
function updatePdOpts(){
    const t=document.getElementById('pdType')?.value;
    const w=document.getElementById('pdSubWrap');
    const s=document.getElementById('pdSubval');
    if(!t||!w||!s)return;
    if(t==='A-org'){w.style.display='none';return;}
    const opts=PD_OPTS[t]||[];
    s.innerHTML=opts.map(([v,l])=>`<option value="${v}">${l}</option>`).join('');
    w.style.display='block';
}

function updateExtOpts(){
    const t=document.getElementById('extType')?.value||'';
    const v1w=document.getElementById('extVal1Wrap');
    const v2w=document.getElementById('extVal2Wrap');
    const v1=document.getElementById('extVal1');
    const v1l=document.getElementById('extVal1Label');
    if(!v1w||!v2w||!v1||!v1l)return;
    v1w.style.display='none';
    v2w.style.display='none';
    v1.value='';
    if(t==='income'){
        v1l.textContent='Income Generated';
        v1.placeholder='Amount';
        v1w.style.display='block';
        v2w.style.display='block';
    }else if(t==='csr-satisfaction'){
        v1l.textContent='CSR Rating (0-100)';
        v1.placeholder='Rating';
        v1w.style.display='block';
    }
}

// ── Build remarks ──────────────────────────────────────────────
function buildRemarks(){
    if(CAT==='Instruction'){
        const t=document.getElementById('critType')?.value||'A-set-sef';
        if(t==='A-set-sef'){
            const s=document.getElementById('setV')?.value||'0';
            const f=document.getElementById('sefV')?.value||'0';
            const n=document.getElementById('notesA')?.value||'';
            return `A-set-sef|||${s}|||${f}|||${n}`;
        }else if(t==='B-material'){
            const l=document.getElementById('matType')?.value||'';
            const c=document.getElementById('contribB')?.value||'100';
            return `B-material|||${l}|||${c}`;
        }else{
            const l=document.getElementById('thesisType')?.value||'';
            return `C-thesis|||${l}`;
        }
    }else if(CAT==='Research'){
        const t=document.getElementById('resType')?.value||'';
        const ti=document.getElementById('resTitle')?.value||'';
        const c=document.getElementById('resContrib')?.value||'100';
        return `${t}|||${ti}||||||||||||${c}`;
    }else if(CAT==='Extension'){
        const t=document.getElementById('extType')?.value||'';
        const title=document.getElementById('extTitle')?.value||'';
        const v1=document.getElementById('extVal1')?.value||'';
        const v2=document.getElementById('extVal2')?.value||'';
        return `${t}|||${title}|||${v1}|||${v2}`;
    }else{
        const t=document.getElementById('pdType')?.value||'A-org';
        const d=document.getElementById('pdDesc')?.value||'';
        const v=t==='A-org'?'1':(document.getElementById('pdSubval')?.value||'0');
        return `${t}|||${d}|||${v}`;
    }
}

// ── Score preview ──────────────────────────────────────────────
function previewScore(){
    const r=buildRemarks();
    if(!r){document.getElementById('scorePreview').textContent='0.00';return;}
    fetch(AJAX,{method:'POST',body:new URLSearchParams({action:'preview_score',cat:CAT,remarks:r})})
        .then(x=>x.json()).then(d=>{
            document.getElementById('scorePreview').textContent=d.ok?d.points.toFixed(2):'?';
        }).catch(()=>{});
}

// ── Save entry ─────────────────────────────────────────────────
function saveEntry(){
    const remarks=buildRemarks();
    if(!remarks){alert('Please fill in the required fields.');return;}
    const notes=document.getElementById('entryNotes')?.value||'';
    const editId=document.getElementById('editId')?.value||'';
    const fd=new FormData();
    fd.append('action','save_entry'); fd.append('cat',CAT);
    fd.append('remarks',remarks); fd.append('notes',notes);
    if(editId) fd.append('entry_id',editId);
    fetch(AJAX,{method:'POST',body:fd}).then(x=>x.json()).then(d=>{
        if(d.ok){cancelForm();loadEntries();refreshScore();}
        else alert(d.error||'Save failed.');
    });
}

// ── Load entries ───────────────────────────────────────────────
function loadEntries(){
    return fetch(`${AJAX}?action=get_entries&cat=${encodeURIComponent(CAT)}`)
        .then(x=>x.json()).then(d=>{
            const el=document.getElementById('entryList');
            if(!el)return d;
            if(!d.ok||!d.entries.length){
                const info = EMPTY_STATE_INFO[CAT] || {};
                const icon = info.icon || 'bi-inbox';
                const max  = info.max  || 0;
                const desc = info.desc || '';
                const html = `<div class="empty-state">
                    <i class="bi ${icon}"></i>
                    <p>No entries yet. Click <strong>+ Add Entry</strong> to start.</p>
                    ${desc ? `<p style="margin-top:.35rem;font-size:.68rem;color:#b0bec5;max-width:240px;margin-left:auto;margin-right:auto;">${desc}</p>` : ''}
                    ${max  ? `<p style="margin-top:.25rem;font-size:.65rem;color:#bfdbfe;font-weight:700;">Max: ${max} pts</p>` : ''}
                </div>`;
                if(el.innerHTML!==html)el.innerHTML=html;
                return d;
            }
            const html=d.entries.map(e=>entryCard(e)).join('');
            if(el.innerHTML!==html)el.innerHTML=html;
            return d;
        }).catch(()=>null);
}

function entryCard(e){
    const lbl=fmtLabel(e.remarks);
    const notes=e.notes?`<div class="entry-notes">${esc(e.notes)}</div>`:'';
    const files=(e.files||[]).map(f=>`
        <a href="../${f.file_path}" target="_blank" class="file-chip" title="${esc(f.original_filename)}">
            <i class="bi bi-paperclip" style="font-size:.6rem;"></i>${esc(f.original_filename.length>20?f.original_filename.slice(0,20)+'…':f.original_filename)}
        </a>
        <button class="chip-del" onclick="delFile(event,${f.file_id})" title="Remove">×</button>
    `).join('');
    return `<div class="entry-card" id="ec-${e.entry_id}">
        <div class="entry-card-top">
            <div><div class="entry-label">${lbl}</div>${notes}</div>
            <div class="entry-pts">${parseFloat(e.computed_points).toFixed(2)}</div>
        </div>
        ${files?`<div class="entry-files">${files}</div>`:''}
        <div class="entry-actions">
            <button class="btn-xs btn-attach" onclick="trigAttach(${e.entry_id})"><i class="bi bi-paperclip"></i> Attach</button>
            <button class="btn-xs btn-rm" onclick="delEntry(${e.entry_id})"><i class="bi bi-trash"></i> Remove</button>
        </div>
    </div>`;
}

function fmtLabel(r){
    const p=r.split('|||');
    const t=p[0]||'';
    if(t==='A-set-sef') return `<strong>Teaching Evaluation</strong> — SET: ${p[1]||0}%, SEF: ${p[2]||0}%`;
    if(t==='B-material') return `<strong>Material:</strong> ${esc(p[1]||'')}`;
    if(t==='C-thesis')   return `<strong>Thesis/Dissertation:</strong> ${esc(p[1]||'')}`;
    if(['moa-linkage','income','accredit-local','accredit-intl','resource-speaker-local','resource-speaker-intl','outreach-isr-lead','outreach-isr-member','csr-satisfaction','consultant-local','consultant-intl','judge-research','judge-other'].includes(t)){
        const value = p[2] ? ` - ${esc(p[2])}${t==='csr-satisfaction'?'%':''}` : '';
        return `<strong>${esc(t.replaceAll('-',' '))}</strong>${p[1]?' - '+esc(p[1]):''}${value}`;
    }
    if(t==='A-org')      return `<strong>Professional Org Membership</strong>`;
    if(t.startsWith('B-')||t.startsWith('C-')) return `<strong>${esc(p[1]||t)}</strong>${p[2]?' — '+esc(p[2])+'pts':''}`;
    // Research / Extension
    return `<strong>${esc(p[0])}</strong>${p[1]?' — '+esc(p[1]):''}`;
}

// ── Attach file to entry ───────────────────────────────────────
function trigAttach(eid){attachTarget=eid;document.getElementById('entryFileInput').click();}
function attachFile(input){
    if(!input.files.length||!attachTarget)return;
    const fd=new FormData();
    fd.append('action','attach_file');
    fd.append('cat',CAT);
    fd.append('entry_id',attachTarget);
    fd.append('evidence',input.files[0]);
    fetch(AJAX,{method:'POST',body:fd}).then(x=>x.json()).then(d=>{
        if(d.ok){loadEntries();refreshScore();}
        else alert(d.error||'Upload failed.');
        input.value=''; attachTarget=null;
    });
}

// ── Delete entry / file ────────────────────────────────────────
function delEntry(eid){
    peConfirm(
        'bi-trash',
        '#dc2626',
        'Remove Entry',
        'This will permanently delete this entry and all its attached files.',
        'Remove',
        () => {
            const fd=new FormData(); fd.append('action','delete_entry'); fd.append('entry_id',eid);
            fetch(AJAX,{method:'POST',body:fd}).then(x=>x.json()).then(d=>{if(d.ok){loadEntries();refreshScore();}});
        }
    );
}
function delFile(e,fid){
    e.preventDefault();e.stopPropagation();
    peConfirm(
        'bi-paperclip',
        '#dc2626',
        'Remove File',
        'This will permanently delete this evidence file.',
        'Remove File',
        () => {
            const fd=new FormData(); fd.append('action','delete_file'); fd.append('file_id',fid);
            fetch(AJAX,{method:'POST',body:fd}).then(x=>x.json()).then(d=>{if(d.ok){loadEntries();refreshScore();}});
        }
    );
}

function confirmResetAll(){
    peConfirm(
        'bi-arrow-counterclockwise',
        '#dc2626',
        'Reset All Entries',
        'This will permanently delete <strong>all entries and files</strong> in the <strong>' + CAT + '</strong> tab. This cannot be undone.',
        'Reset All',
        () => {
            const fd=new FormData(); fd.append('action','delete_all_entries'); fd.append('cat',CAT);
            fetch(AJAX,{method:'POST',body:fd}).then(x=>x.json()).then(d=>{
                if(d.ok){ loadEntries(); refreshScore(); }
            });
        }
    );
}

// ── Refresh sidebar score via AJAX (no page reload) ────────────
function refreshScore(){
    fetch(`${AJAX}?action=get_score`).then(x=>x.json()).then(d=>{
        if(!d.ok)return;
        const sv=document.getElementById('sbScore');
        if(sv){
            sv.textContent=d.weighted.toFixed(2);
            sv.style.color=d.weighted>=41?'#1e4d8c':d.weighted>0?'#475569':'#94a3b8';
        }
        const mn=document.getElementById('sbMinNote');
        if(mn){
            mn.textContent=d.weighted>=41?'✓ Meets minimum (41)':'✗ Below minimum (41)';
            mn.style.color=d.weighted>=41?'#1e4d8c':'#334155';
        }
        // Update KRA bars
        const SLUGS=['instruction','research','extension','profdev'];
        const CATS=['Instruction','Research','Extension','Professional Development'];
        const MAXS=[100,100,100,100]; // JC01 s.2026: each KRA capped at 100
        const WEIGHTS=[d.weights?.Instruction,d.weights?.Research,d.weights?.Extension,d.weights?.['Professional Development']];
        const RAWS=[d.kra?.kra1,d.kra?.kra2,d.kra?.kra3,d.kra?.kra4];
        SLUGS.forEach((slug,i)=>{
            const row=document.getElementById('sb-kra-'+slug);
            if(!row)return;
            const raw=RAWS[i]||0;
            const mx=MAXS[i];
            const pct=Math.min(100,Math.round((raw/mx)*100));
            const wpts=Math.round(raw*((WEIGHTS[i]||0)/100)*10)/10;
            const pts=row.querySelector('.kra-row-pts');
            const bar=row.querySelector('.kra-bar-fill');
            const rawTxt=row.querySelector('.kra-row-raw');
            if(pts) pts.textContent=wpts.toFixed(1)+'pt';
            if(bar) bar.style.width=pct+'%';
            if(rawTxt) rawTxt.textContent=raw.toFixed(1)+'/'+mx+' · '+(WEIGHTS[i]||0)+'% wt';
        });

        // Update rank projection + increment badge
        const potEl  = document.getElementById('sbPotRank');
        const incWrap = document.querySelector('.rank-box');
        if (incWrap) {
            const curRankEl = document.getElementById('sbCurRank');
            const curRank   = curRankEl ? curRankEl.textContent.trim() : '';
            const potRank   = d.pot_rank || '';
            if (potEl) potEl.textContent = potRank;

            // Show/hide arrow + pot rank
            const arrow = incWrap.querySelector('.rank-arrow');
            if (arrow) arrow.style.display = (potRank && potRank !== curRank && potRank !== '—') ? '' : 'none';
            if (potEl) potEl.style.display  = (potRank && potRank !== curRank && potRank !== '—') ? '' : 'none';

            // Rebuild increment badge
            let badge = incWrap.querySelector('.increment-badge');
            const belowNote = incWrap.querySelector('.below-note');
            if (d.sub_rank > 0) {
                const tip = 'Sub-rank = the number of steps forward in the rank scale your weighted score earns (e.g. +2 means you move up 2 positions from your current rank).';
                const label = '+'+d.sub_rank+' sub-rank'+(d.sub_rank>1?'s':'');
                if (!badge) {
                    badge = document.createElement('div');
                    badge.className = 'increment-badge';
                    incWrap.appendChild(badge);
                }
                badge.title = tip;
                badge.style.cursor = 'help';
                badge.innerHTML = label+' <i class="bi bi-question-circle" style="font-size:.58rem;opacity:.65;margin-left:2px;"></i>';
                badge.style.display = '';
                if (belowNote) belowNote.style.display = 'none';
            } else {
                if (badge) badge.style.display = 'none';
                if (belowNote) {
                    belowNote.style.display = d.weighted > 0 ? '' : 'none';
                }
            }
        }

        // ── Update sub-cap criterion bars ──────────────────
        const bars  = document.querySelectorAll('.subcap-row');
        const barsWrap = document.getElementById('subcapBars');
        if (bars.length && d.kra_detail) {
            if (barsWrap) barsWrap.style.display = '';
            bars.forEach(row => {
                const detailKey = row.dataset.detail;   // kra1 / kra2 / kra3 / kra4
                const field     = row.dataset.key;      // criterion_a, criterion_b etc.
                const cap       = parseFloat(row.dataset.cap) || 0;
                const detail    = d.kra_detail?.[detailKey] || {};
                const val       = parseFloat(detail[field] ?? 0);

                const ptsEl  = row.querySelector('.subcap-pts');
                const fillEl = row.querySelector('.subcap-fill');

                if (ptsEl) {
                    ptsEl.textContent = cap
                        ? `${val.toFixed(1)} / ${cap}`
                        : val.toFixed(1);
                    // Turn red when at or over cap
                    ptsEl.style.color = (cap && val >= cap) ? '#dc2626' : '#1a3a6b';
                }
                if (fillEl && cap) {
                    const pct = Math.min(100, Math.round((val / cap) * 100));
                    fillEl.style.width = pct + '%';
                    fillEl.style.background = pct >= 100 ? '#dc2626' : '#1e4d8c';
                } else if (fillEl) {
                    // No hard cap (Research) — show filled proportionally to 100 pts
                    fillEl.style.width = Math.min(100, val) + '%';
                }
            });
        }

        // ── Gap-to-threshold note in sidebar ───────────────
        let gapEl = document.getElementById('sbGapNote');
        if (!gapEl) {
            const mn = document.getElementById('sbMinNote');
            if (mn) {
                gapEl = document.createElement('div');
                gapEl.id = 'sbGapNote';
                gapEl.style.cssText = 'font-size:.58rem;font-weight:600;margin-top:3px;';
                mn.after(gapEl);
            }
        }
        if (gapEl) {
            const gap = Math.max(0, 41 - d.weighted);
            if (gap > 0 && d.weighted > 0) {
                gapEl.textContent = `Need ${gap.toFixed(1)} more pts to qualify`;
                gapEl.style.color = '#c2410c';
            } else {
                gapEl.textContent = '';
            }
        }
    });
}

// ── Helpers ────────────────────────────────────────────────────
function esc(s){return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');}

// ── Evidence Guide Drawer ──────────────────────────────────────
let drawerCat = '';

function openDrawer(){
    document.getElementById('evidenceDrawer').classList.add('open');
    document.getElementById('drawerOverlay').classList.add('open');
    document.body.style.overflow='hidden';
    // Pre-select current KRA tab
    const tabBtn = document.querySelector(`.d-tab[data-cat="${CAT}"]`);
    if(tabBtn) switchDrawerTab(tabBtn, CAT);
}
function closeDrawer(){
    document.getElementById('evidenceDrawer').classList.remove('open');
    document.getElementById('drawerOverlay').classList.remove('open');
    document.body.style.overflow='';
}
function switchDrawerTab(btn, cat){
    drawerCat = cat;
    document.querySelectorAll('.d-tab').forEach(b=>b.classList.remove('active'));
    btn.classList.add('active');
    filterCriteria();
}
function toggleCrit(hd){
    hd.closest('.crit-item').classList.toggle('open');
}
function filterCriteria(){
    const q = (document.getElementById('drawerSearch')?.value||'').toLowerCase();
    document.querySelectorAll('.crit-group').forEach(group=>{
        const gc = group.dataset.cat||'';
        const catMatch = !drawerCat || gc===drawerCat;
        let anyVisible = false;
        group.querySelectorAll('.crit-item').forEach(item=>{
            const labelMatch = !q || (item.dataset.label||'').includes(q);
            const show = catMatch && labelMatch;
            item.style.display = show ? '' : 'none';
            if(show) anyVisible = true;
        });
        group.style.display = (catMatch && anyVisible) ? '' : 'none';
    });
}

// ── Init ───────────────────────────────────────────────────────
<?php if($cur_cat==='Instruction'): ?>switchInstrForm();<?php endif; ?>
<?php if($cur_cat==='Extension'): ?>updateExtOpts();<?php endif; ?>
<?php if($cur_cat==='Professional Development'): ?>updatePdOpts();<?php endif; ?>
function selfAssessmentFormOpen(){
    return document.getElementById('addForm')?.style.display !== 'none';
}
function refreshSelfAssessment(){
    refreshScore();
    if(!selfAssessmentFormOpen())loadEntries();
}
refreshSelfAssessment();
setInterval(refreshSelfAssessment, 5000);

// ── In-page confirm modal ──────────────────────────────────────
function peConfirm(icon, color, title, msg, btnLabel, onConfirm) {
    const m = document.getElementById('peConfirmModal');
    document.getElementById('peConfirmIcon').className       = 'bi ' + icon;
    document.getElementById('peConfirmIcon').style.color     = color;
    document.getElementById('peConfirmIconWrap').style.background = color + '18';
    document.getElementById('peConfirmTitle').textContent    = title;
    document.getElementById('peConfirmMsg').innerHTML        = msg;
    document.getElementById('peConfirmBtn').textContent      = btnLabel;
    document.getElementById('peConfirmBtn').style.background = color;
    // Rebind confirm button to avoid stacking listeners
    const fresh = document.getElementById('peConfirmBtn').cloneNode(true);
    document.getElementById('peConfirmBtn').replaceWith(fresh);
    fresh.style.background = color;
    fresh.addEventListener('click', () => { m.style.display = 'none'; document.body.style.overflow = ''; onConfirm(); });
    m.style.display = 'flex';
    document.body.style.overflow = 'hidden';
}
document.addEventListener('DOMContentLoaded', () => {
    const m = document.getElementById('peConfirmModal');
    if (!m) return;
    document.getElementById('peConfirmCancelBtn')
        .addEventListener('click', () => { m.style.display = 'none'; document.body.style.overflow = ''; });
    m.addEventListener('click', e => { if (e.target === m) { m.style.display = 'none'; document.body.style.overflow = ''; } });
});
</script>

<!-- ── In-page Confirm Modal ─────────────────────────────────── -->
<div id="peConfirmModal"
     style="display:none;position:fixed;top:0;left:0;right:0;bottom:0;
            background:rgba(0,0,0,0.5);z-index:99999;overflow:hidden;
            align-items:center;justify-content:center;padding:1rem;">
    <div style="background:#fff;border-radius:14px;width:100%;max-width:400px;
                box-shadow:0 24px 64px rgba(0,0,0,0.28);overflow:hidden;
                animation:peModalIn .18s ease;max-height:90vh;">

        <!-- Header -->
        <div style="background:#1a3a6b;padding:.85rem 1.25rem;display:flex;align-items:center;gap:.65rem;">
            <img src="../assets/images/logo.jpg" alt=""
                 style="width:32px;height:32px;border-radius:50%;object-fit:cover;
                        border:2px solid rgba(255,255,255,.25);flex-shrink:0;">
            <div>
                <div style="color:#fff;font-weight:700;font-size:.85rem;line-height:1.2;">SUCFRMS</div>
                <div style="color:rgba(255,255,255,.5);font-size:.65rem;">Self-Assessment</div>
            </div>
        </div>

        <!-- Body -->
        <div style="padding:1.5rem 1.5rem 1rem;text-align:center;">
            <div id="peConfirmIconWrap"
                 style="width:56px;height:56px;border-radius:50%;display:inline-flex;align-items:center;
                        justify-content:center;margin-bottom:.85rem;">
                <i id="peConfirmIcon" class="bi bi-trash" style="font-size:1.5rem;"></i>
            </div>
            <div id="peConfirmTitle"
                 style="font-size:.97rem;font-weight:700;color:#0f172a;margin-bottom:.4rem;"></div>
            <div id="peConfirmMsg"
                 style="font-size:.82rem;color:#64748b;line-height:1.55;"></div>
        </div>

        <!-- Divider -->
        <div style="height:1px;background:#f1f5f9;margin:0 1.25rem;"></div>

        <!-- Buttons -->
        <div style="padding:.85rem 1.25rem 1.25rem;display:flex;gap:.5rem;">
            <button id="peConfirmCancelBtn"
                    style="flex:1;padding:.6rem;border:1.5px solid #e2e8f0;border-radius:8px;
                           background:#fff;color:#475569;font-weight:600;font-size:.83rem;cursor:pointer;
                           transition:background .12s;">
                Cancel
            </button>
            <button id="peConfirmBtn"
                    style="flex:1;padding:.6rem;border:none;border-radius:8px;
                           color:#fff;font-weight:700;font-size:.83rem;cursor:pointer;
                           transition:opacity .12s;">
                Confirm
            </button>
        </div>

    </div>
</div>

<style>
@keyframes peModalIn {
    from { opacity:0; transform:scale(.95) translateY(8px); }
    to   { opacity:1; transform:scale(1)   translateY(0);   }
}
</style>

</body>
</html>
