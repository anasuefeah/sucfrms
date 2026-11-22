<?php
/**
 * Self-Assessment  -  standalone page, no sidebar.
 */
if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/functions.php';

if (!isLoggedIn()) { header('Location: login.php'); exit; }
if (($_SESSION['role'] ?? '') !== 'faculty') {
    header('Location: ../index.php'); exit;
}
unset($_SESSION['force_pw_change']);

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

// -- Auto Sub Rank is fully automatic  -  no POST handler needed here.
// The tab recalculates on every page view via AutoSubRankCalculator.

// Faculty info
$fac = $pdo->prepare("SELECT full_name, first_name, last_name, middle_name, `rank`, profile_pic FROM users WHERE user_id=?");
$fac->execute([$uid]);
$fac         = $fac->fetch();
$full_name = formatDisplayName($fac);
$rank        = $fac['rank']        ?? '';
$profile_pic = $fac['profile_pic'] ?? '';
$init        = strtoupper(substr($fac['first_name']??'U',0,1).substr($fac['last_name']??'',0,1)) ?: 'FA';

// -- SELF-ASSESSMENT ELIGIBILITY GATE -----------------------------------------
// Constraint: verify rank readiness only. Authenticity of documents is handled
// exclusively by the assigned evaluators.
$eligible          = true;
$eligibility_notes = [];

// Self-Assessment is independent of official cycle and application status.
$all_ranks = [
    'Instructor I','Instructor II','Instructor III',
    'Assistant Professor I','Assistant Professor II','Assistant Professor III','Assistant Professor IV',
    'Associate Professor I','Associate Professor II','Associate Professor III','Associate Professor IV','Associate Professor V',
    'Professor I','Professor II','Professor III','Professor IV','Professor V','Professor VI',
    'University Professor',
];
if (empty($rank)) {
    $eligible = false;
    $eligibility_notes[] = 'Faculty rank is not set. Contact your administrator to update your profile before using Self-Assessment.';
} elseif (!in_array($rank, $all_ranks)) {
    $eligible = false;
    $eligibility_notes[] = "Current rank '{$rank}' is not a recognised reclassification rank. Contact your administrator.";
}

// Current scores use the two-pass Orchestrator-aligned engine.
require_once __DIR__ . '/../includes/self_assessment_scoring.php';

$_pe_result = peAggregateScores($pdo, $uid, $rank);
$weighted   = $_pe_result['weighted_score'];
$sub_rank   = $_pe_result['sub_rank_increment'];
$weights    = $_pe_result['weights'];
$pot_rank   = $_pe_result['pot_rank'];
$raw = [
    'Instruction'             => $_pe_result['kra1'],
    'Research'                => $_pe_result['kra2'],
    'Extension'               => $_pe_result['kra3'],
    'Professional Development'=> $_pe_result['kra4'],
];

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

// Tab entry counts  -  only for KRA tabs
$counts = [];
$kra_slugs = ['instruction','research','extension','profdev'];
foreach ($kra_tabs as $slug => $t) {
    $counts[$slug] = $_pe_result['entry_counts'][$t['cat']] ?? 0;
}

// Evidence guide — load criteria for the active cycle + faculty rank, fall back to globals
$all_criteria = [];
$pe_cycle = getActiveCycle($pdo);
$pe_cycle_id = (int)($pe_cycle['cycle_id'] ?? 0);
$pe_rank = $rank;
foreach (CriteriaSnapshots::rows($pdo,$pe_cycle_id,$pe_rank) as $row) $all_criteria[$row['kra_category']][] = $row;
$additionalCriteria = kraAdditionalCriteria($all_criteria[$cur_cat] ?? []);
$assessmentGroupTitles = [];
foreach (criteriaGroups($all_criteria[$cur_cat] ?? []) as $code => $group) {
    if ($code !== '') $assessmentGroupTitles[$code] = $group['name'];
}
$instructionChildren = [];
$topLevelCriteria = [];
foreach ($additionalCriteria as $criterion) {
    if ($cur_cat === 'Instruction' && isset($criterion['parent_code'])) {
        $instructionChildren[$criterion['parent_code']][] = $criterion;
    } else {
        $topLevelCriteria[] = $criterion;
    }
}
$otherInstructionChildren = array_diff_key($instructionChildren, array_flip(['A', 'B', 'C']));
$showAdditionalCriteria = static function (array $criteria, bool $nested = false): void {
    foreach ($criteria as $criterion) {
        $label = $criterion['criterion_label'];
        $parent = $criterion['parent_code'] ?? '';
        if (preg_match('/^Criterion\s+([A-Z])\b\s*(?:[-.:–—]\s*)?/iu', $label, $match)) {
            if ($parent === '') $parent = strtoupper($match[1]);
            if ($nested) $label = substr($label, strlen($match[0])) ?: $label;
        }
        printf('<option data-parent="%s" value="custom:%s">%s (%s pts)</option>',
            htmlspecialchars($parent, ENT_QUOTES),
            htmlspecialchars($criterion['criterion_key'], ENT_QUOTES), htmlspecialchars($label, ENT_QUOTES),
            number_format((float)$criterion['max_points'], 2));
    }
};
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <link rel="stylesheet" href="../assets/css/square-corners.css">
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1.0">
<title>SUCFRMS  -  Self-Assessment</title>
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
.sidebar{width:230px;flex-shrink:0;background:#1a2744;border-right:none;padding:.6rem .65rem;position:sticky;top:48px;height:calc(100vh - 48px);overflow:hidden;display:flex;flex-direction:column;gap:.4rem;}

/* Sidebar boxes */
.sb-box{background:rgba(255,255,255,.05);border:1px solid rgba(255,255,255,.08);padding:.5rem .6rem;}

/* Nav links */
.sb-nav-link{display:flex;align-items:center;gap:.45rem;padding:.38rem .5rem;text-decoration:none;font-size:.76rem;font-weight:600;color:rgba(255,255,255,.45);transition:background .15s,color .15s;}
.sb-nav-link:hover{background:rgba(255,255,255,.06);color:rgba(255,255,255,.85);}
.sb-nav-link.active{background:rgba(255,255,255,.1);color:#fff;border-left:2px solid rgba(255,255,255,.4);}
.sb-nav-link i{font-size:.8rem;}

/* Score block */
.sb-heading{font-size:.54rem;font-weight:700;color:rgba(255,255,255,.3);text-transform:uppercase;letter-spacing:.14em;margin-bottom:.3rem;}
.score-big{text-align:center;padding:.3rem 0 .35rem;}
.score-num{font-size:1.75rem;font-weight:700;line-height:1;color:#fff;letter-spacing:-.5px;}
.score-sub{font-size:.57rem;color:rgba(255,255,255,.35);margin-top:2px;}
.score-min{font-size:.6rem;font-weight:600;margin-top:3px;color:rgba(255,255,255,.55);}

/* KRA rows */
.sb-kra-list{display:flex;flex-direction:column;gap:.38rem;}
.kra-row{display:flex;flex-direction:column;gap:2px;}
.kra-row-head{display:flex;justify-content:space-between;align-items:center;}
.kra-row-label{font-size:.64rem;color:rgba(255,255,255,.55);font-weight:500;}
.kra-row-pts{font-size:.64rem;font-weight:700;color:rgba(255,255,255,.85);}
.kra-bar{height:2px;background:rgba(255,255,255,.08);overflow:hidden;}
.kra-bar-fill{height:100%;background:rgba(255,255,255,.35);transition:width .4s;}
.kra-row-raw{font-size:.56rem;color:rgba(255,255,255,.3);}

/* Rank box */
.rank-box{background:rgba(255,255,255,.05);border:1px solid rgba(255,255,255,.08);padding:.5rem .6rem;}
.rank-box-label{font-size:.54rem;font-weight:700;color:rgba(255,255,255,.3);text-transform:uppercase;letter-spacing:.1em;margin-bottom:.4rem;}
.rank-row{display:flex;justify-content:space-between;align-items:center;padding:.25rem 0;border-bottom:1px solid rgba(255,255,255,.06);}
.rank-row:last-of-type{border-bottom:none;}
.rank-row-key{font-size:.58rem;color:rgba(255,255,255,.35);font-weight:600;text-transform:uppercase;letter-spacing:.06em;}
.rank-row-val{font-size:.72rem;font-weight:700;color:#fff;text-align:right;}
.rank-row-val.dim{color:rgba(255,255,255,.5);font-weight:500;}
.increment-badge{display:flex;align-items:center;gap:4px;margin-top:.35rem;font-size:.6rem;font-weight:600;color:rgba(255,255,255,.45);}

/* Note & sign out */
.sb-note{font-size:.55rem;color:rgba(255,255,255,.25);line-height:1.5;margin-top:auto;}
.sb-signout{display:flex;align-items:center;justify-content:center;gap:.45rem;width:100%;padding:.45rem .6rem;border:1px solid rgba(255,255,255,.12);background:rgba(255,255,255,.05);color:rgba(255,255,255,.5);font-size:.74rem;font-weight:600;cursor:pointer;transition:background .15s,color .15s,border-color .15s;}
.sb-signout:hover{background:rgba(255,255,255,.1);color:#fff;border-color:rgba(255,255,255,.25);}

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

/* Two panels  -  entry only, full width */
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
.entry-save-feedback{padding:.5rem .75rem;margin-bottom:.65rem;border:1px solid #bbf7d0;background:#f0fdf4;color:#166534;}
.entry-save-feedback.is-error{border-color:#fecaca;background:#fef2f2;color:#991b1b;}

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
.crit-evidence-item::before{content:'*';color:#1a3a6b;font-weight:700;flex-shrink:0;margin-top:1px;}
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
.btn-s{background:#fff;color:#1a3a6b;border:1px solid #1a3a6b;padding:.4rem .9rem;font-size:.75rem;font-weight:600;cursor:pointer;font-family:inherit;}
.btn-s:hover{background:#f0f4fb;}
.btn-xs{padding:2px 8px;border:1px solid;font-size:.65rem;font-weight:600;cursor:pointer;background:#fff;}
.btn-attach{border-color:#1a3a6b;color:#1a3a6b;}
.btn-attach:hover{background:#1a3a6b;color:#fff;}
.btn-rm{border-color:#94a3b8;color:#1e293b;}
.btn-rm:hover{background:#f8fafc;}

@media(max-width:900px){
    .panels{grid-template-columns:1fr;}
    .sidebar{width:100%;height:auto;position:static;border-right:none;border-bottom:1px solid rgba(255,255,255,.1);}
    .pg{flex-direction:column;}
}
@media(max-width:600px){
    /* Navbar  -  show short name only */
    .pnav{padding:0 12px;gap:8px;}
    .pnav-title{font-size:.75rem;}
    .pnav-name{max-width:72px;font-size:.72rem;}

    /* Main area padding */
    .main{padding:.7rem .75rem 2.5rem;}

    /* Page title row  -  stack buttons */
    .main > div:first-of-type{flex-direction:column;align-items:flex-start !important;}

    /* Tabs  -  smaller font, always scrollable */
    .tab-btn{font-size:.68rem;padding:.45rem .65rem;}

    /* 2-column form grid -> 1 column on mobile */
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
<?php $pe_view = $_GET['view'] ?? 'self'; ?>
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

<!-- -- Score sidebar -- -->
<aside class="sidebar">

    <!-- Nav links -->
    <div class="sb-box" style="display:flex;flex-direction:column;gap:2px;">
        <a href="self_assessment.php"
           class="sb-nav-link <?= $pe_view !== 'analytics' ? 'active' : '' ?>">
            <i class="bi bi-pencil-square"></i> Self-Assessment
        </a>
        <a href="self_assessment.php?view=analytics"
           class="sb-nav-link <?= $pe_view === 'analytics' ? 'active' : '' ?>">
            <i class="bi bi-bar-chart-line"></i> Analytics
        </a>
    </div>

    <!-- Score box -->
    <div class="sb-box">
        <div class="sb-heading">Score Estimate</div>

    <div class="score-big">
        <div class="score-num" id="sbScore" style="color:#fff;">
            <?= number_format($weighted,2) ?>
        </div>
        <div class="score-sub">Weighted / 100</div>
        <div class="score-min" id="sbMinNote" style="color:rgba(255,255,255,.55);">
            <?= $weighted>=41?'✓ Meets minimum (41)':'✗ Below minimum (41)' ?>
        </div>
    </div><!-- /.score-big -->
    </div><!-- /.sb-box score -->

    <!-- KRA rows box -->
    <div class="sb-box">
        <div class="sb-heading">KRA Breakdown</div>
        <div class="sb-kra-list">
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
    </div>
    <?php endforeach; ?>
        </div><!-- /.sb-kra-list -->
    </div><!-- /.sb-box kra -->

    <div class="rank-box">
        <div class="rank-box-label">Rank Projection</div>
        <div class="rank-row">
            <span class="rank-row-key">Current</span>
            <span class="rank-row-val dim" id="sbCurRank"><?= sanitize($rank?:'-') ?></span>
        </div>
        <div class="rank-row">
            <span class="rank-row-key">Projected</span>
            <span class="rank-row-val" id="sbPotRank">
                <?php if ($pot_rank && $pot_rank !== $rank && $pot_rank !== ' - '): ?>
                    <?= sanitize($pot_rank) ?>
                <?php else: ?>
                    <?= sanitize($rank?:'-') ?>
                <?php endif; ?>
            </span>
        </div>
        <?php if ($sub_rank > 0): ?>
        <div class="increment-badge"
             title="Total sub-rank increase from your weighted score and any automatic increases."
             style="cursor:help;">
            <i class="bi bi-graph-up-arrow" style="font-size:.6rem;"></i>
            +<?= $sub_rank ?> sub-rank<?= $sub_rank>1?'s':'' ?>
        </div>
        <?php
        $asr = $_pe_result['auto_subrank'] ?? [];
        if (!empty($asr['applied'])): ?>
        <div style="margin-top:.3rem;font-size:.6rem;font-weight:600;color:rgba(255,255,255,.45);letter-spacing:.04em;text-transform:uppercase;">
            Auto Sub-Rank active
        </div>
        <?php endif; ?>
        <?php elseif ($weighted > 0): ?>
        <div class="increment-badge"><i class="bi bi-dash"></i> Score below 41</div>
        <?php endif; ?>
    </div>

    <div class="sb-note">
        <i class="bi bi-info-circle"></i> Estimate only — final scores are reviewed by your evaluator.
    </div>

    <button onclick="document.getElementById('pe-logout-modal').style.display='flex'"
            class="sb-signout">
        <i class="bi bi-box-arrow-right" style="font-size:0.88rem;"></i> Sign Out
    </button>
</aside>

<!-- ── Sign Out Confirmation Modal ────────────────────────────────────── -->
<div id="pe-logout-modal"
     style="display:none;position:fixed;inset:0;background:rgba(15,23,42,0.55);
            z-index:100003;align-items:center;justify-content:center;">
    <div style="background:#fff;border-radius:0;width:100%;max-width:360px;
                margin:1rem;box-shadow:0 8px 40px rgba(0,0,0,0.18);overflow:hidden;border:1px solid #e2e8f0;">

        <!-- Header -->
        <div style="background:#1a3a6b;padding:0.85rem 1.1rem;display:flex;align-items:center;gap:0.6rem;">
            <img src="../assets/images/logo.jpg" alt=""
                 style="width:28px;height:28px;object-fit:cover;border-radius:50%;border:1.5px solid rgba(255,255,255,0.25);flex-shrink:0;">
            <div>
                <div style="color:#fff;font-weight:700;font-size:0.78rem;line-height:1.2;">SUCFRMS</div>
                <div style="color:#93c5fd;font-size:0.62rem;">SUC Faculty Reclassification Management System</div>
            </div>
        </div>

        <!-- Body -->
        <div style="padding:1.35rem 1.25rem 1rem;">
            <div style="font-weight:700;color:#1e293b;font-size:0.88rem;margin-bottom:5px;">Sign Out</div>
            <p style="color:#64748b;font-size:0.78rem;margin:0;line-height:1.55;">
                Are you sure you want to sign out of your account?
            </p>
        </div>

        <!-- Buttons -->
        <div style="padding:0 1.25rem 1.1rem;display:flex;gap:0.5rem;">
            <button onclick="document.getElementById('pe-logout-modal').style.display='none'"
                    style="flex:1;padding:0.5rem;border:1px solid #e2e8f0;border-radius:0;
                           background:#fff;color:#475569;font-weight:600;cursor:pointer;font-size:0.78rem;">
                Cancel
            </button>
            <a href="logout.php"
               style="flex:1;padding:0.5rem;border:none;border-radius:0;background:#dc2626;
                      color:#fff;font-weight:700;font-size:0.78rem;text-align:center;
                      text-decoration:none;display:flex;align-items:center;justify-content:center;">
                Sign Out
            </a>
        </div>

    </div>
</div>
<script>
// Close modal on backdrop click
document.getElementById('pe-logout-modal').addEventListener('click', function(e) {
    if (e.target === this) this.style.display = 'none';
});
</script>

<!-- -- Main -- -->
<div class="main">

    <a href="portal.php" class="back-link"><i class="bi bi-arrow-left"></i> Back to Portal</a>

    <?php if ($pe_view === 'analytics'): ?>
    <?php
    // ── Analytics data prep ──────────────────────────────────────────────
    $ana_meta = [
        'Instruction'              => ['KRA I',   'Instruction',          40, '#1e4d8c', '#dbeafe'],
        'Research'                 => ['KRA II',  'Research',             30, '#0f766e', '#ccfbf1'],
        'Extension'                => ['KRA III', 'Extension',            20, '#7c3aed', '#ede9fe'],
        'Professional Development' => ['KRA IV',  'Prof. Development',    10, '#b45309', '#fef3c7'],
    ];
    $total_entries_all = 0;
    $entries_with_files_all = 0;
    foreach (array_keys($ana_meta) as $kname) {
        $eq = $pdo->prepare("SELECT COUNT(*) FROM pre_eval_entries WHERE user_id=? AND kra_category=?");
        $eq->execute([$uid, $kname]);
        $ec = (int)$eq->fetchColumn();
        $total_entries_all += $ec;
        $fq = $pdo->prepare("SELECT COUNT(DISTINCT e.entry_id) FROM pre_eval_entries e JOIN pre_eval_files f ON f.entry_id=e.entry_id WHERE e.user_id=? AND e.kra_category=?");
        $fq->execute([$uid, $kname]);
        $entries_with_files_all += (int)$fq->fetchColumn();
    }
    $evidence_pct = $total_entries_all > 0 ? min(100, round(($entries_with_files_all / $total_entries_all) * 100)) : 0;

    // Points needed to reach next thresholds
    $thresholds = [
        41  => 'Minimum eligibility',
        51  => 'Next rank tier (51)',
        61  => 'Next rank tier (61)',
        71  => 'Next rank tier (71)',
        81  => 'Next rank tier (81)',
        91  => 'Next rank tier (91)',
    ];
    $next_threshold = null;
    $next_label = '';
    foreach ($thresholds as $t => $tl) {
        if ($weighted < $t) { $next_threshold = $t; $next_label = $tl; break; }
    }
    $pts_to_next = $next_threshold ? max(0, round($next_threshold - $weighted, 2)) : 0;

    // Best KRA to improve (most room vs weight contribution)
    $best_kra = null; $best_room = -1;
    foreach ($ana_meta as $kname => [$kshort, $klabel, $kwt]) {
        $kpts = $raw[$kname] ?? 0;
        $room = max(0, 100 - $kpts) * ($kwt / 100);
        if ($room > $best_room) { $best_room = $room; $best_kra = $kname; }
    }

    // Rank scale for showing steps
    $all_ranks_scale = [
        'Instructor I','Instructor II','Instructor III',
        'Assistant Professor I','Assistant Professor II','Assistant Professor III','Assistant Professor IV',
        'Associate Professor I','Associate Professor II','Associate Professor III','Associate Professor IV','Associate Professor V',
        'Professor I','Professor II','Professor III','Professor IV','Professor V','Professor VI',
        'University Professor',
    ];
    $cur_rank_idx  = array_search($rank, $all_ranks_scale);
    $proj_rank_idx = array_search($pot_rank, $all_ranks_scale);
    $rank_steps    = ($cur_rank_idx !== false && $proj_rank_idx !== false) ? $proj_rank_idx - $cur_rank_idx : 0;
    ?>
    </script>

    <!-- ── Page heading ── -->
    <div style="background:#fff;border:1px solid #e2e8f0;padding:0.85rem 1.25rem;margin-bottom:1.25rem;
                display:flex;align-items:center;justify-content:space-between;gap:1rem;flex-wrap:wrap;">
        <div style="display:flex;align-items:center;gap:0.75rem;">
            <div style="width:36px;height:36px;background:#1a3a6b;display:flex;align-items:center;justify-content:center;flex-shrink:0;">
                <i class="bi bi-bar-chart-fill" style="color:#fff;font-size:0.9rem;"></i>
            </div>
            <div>
                <div style="font-size:0.92rem;font-weight:700;color:#0f172a;line-height:1.2;">Analytics</div>
                <div style="font-size:0.72rem;color:#94a3b8;margin-top:2px;">Self-assessment score summary and reclassification readiness</div>
            </div>
        </div>
        <a href="self_assessment.php"
           style="display:inline-flex;align-items:center;gap:5px;padding:0.35rem 0.85rem;
                  border:1px solid #e2e8f0;background:#f8fafc;color:#475569;
                  font-size:0.75rem;font-weight:600;text-decoration:none;"
           onmouseover="this.style.background='#1a3a6b';this.style.color='#fff';this.style.borderColor='#1a3a6b'"
           onmouseout="this.style.background='#f8fafc';this.style.color='#475569';this.style.borderColor='#e2e8f0'">
            <i class="bi bi-pencil-square"></i> Edit Entries
        </a>
    </div>

    <!-- ── Eligibility status + key numbers ── -->
    <div style="display:grid;grid-template-columns:1fr 1px 1fr 1px 1fr;background:#fff;border:1px solid #e2e8f0;margin-bottom:1.25rem;">
        <?php
        $pts_to_min = max(0, 41 - $weighted);
        $status_color = $weighted >= 41 ? '#1a3a6b' : '#475569';
        $status_label = $weighted >= 41 ? 'Eligible' : 'Below Minimum';
        $cells = [
            ['Weighted Score', number_format($weighted,2), 'out of 100'],
            ['Status',         $status_label,              $weighted>=41 ? 'qualifies for reclassification' : 'need '.number_format($pts_to_min,2).' more pts'],
            ['KRA Entries',    $total_entries_all,         'across all four KRAs'],
        ];
        foreach ($cells as $ci => $c):
        ?>
        <?php if ($ci > 0): ?><div style="background:#e2e8f0;width:1px;"></div><?php endif; ?>
        <div style="padding:1.1rem 1rem;text-align:center;">
            <div style="font-size:0.58rem;font-weight:700;text-transform:uppercase;letter-spacing:0.08em;color:#94a3b8;margin-bottom:4px;"><?= $c[0] ?></div>
            <div style="font-size:1.25rem;font-weight:800;color:<?= $ci===1?$status_color:'#1a3a6b' ?>;line-height:1;letter-spacing:-0.5px;"><?= $c[1] ?></div>
            <div style="font-size:0.65rem;color:#94a3b8;margin-top:4px;"><?= $c[2] ?></div>
        </div>
        <?php endforeach; ?>
    </div>

    <!-- ── Eligibility message ── -->
    <?php if ($weighted >= 41): ?>
    <div style="border:1px solid #e2e8f0;background:#fff;padding:0.75rem 1rem;margin-bottom:1.25rem;">
        <div style="font-size:0.78rem;font-weight:700;color:#1a3a6b;margin-bottom:3px;">You meet the eligibility threshold.</div>
        <div style="font-size:0.75rem;color:#475569;line-height:1.65;">
            Your weighted score of <strong><?= number_format($weighted,2) ?></strong> clears the 41-point minimum required for reclassification.
            <?php if ($next_threshold): ?>
            Your next scoring tier is <strong><?= $next_threshold ?> points</strong> — you need <strong><?= number_format($pts_to_next,2) ?> more weighted points</strong> to reach it, which would further advance your projected rank.
            <?php endif; ?>
        </div>
    </div>
    <?php else: ?>
    <div style="border:1px solid #e2e8f0;background:#fff;padding:0.75rem 1rem;margin-bottom:1.25rem;">
        <div style="font-size:0.78rem;font-weight:700;color:#1e293b;margin-bottom:3px;">You need <?= number_format($pts_to_min,2) ?> more weighted points to qualify.</div>
        <div style="font-size:0.75rem;color:#475569;line-height:1.65;">
            Your current weighted score is <strong><?= number_format($weighted,2) ?></strong>. The minimum for reclassification is <strong>41.00</strong>.
            Weighted points are calculated per KRA based on your rank's weight table — higher-weight KRAs move your score faster.
            See the breakdown below to identify where you have the most room to grow.
        </div>
    </div>
    <?php endif; ?>

    <!-- ── KRA Breakdown ── -->
    <div style="background:#fff;border:1px solid #e2e8f0;margin-bottom:1.25rem;">
        <div style="padding:0.6rem 1rem;border-bottom:1px solid #e2e8f0;display:flex;align-items:center;justify-content:space-between;background:#f8fafc;">
            <div style="font-size:0.62rem;font-weight:700;text-transform:uppercase;letter-spacing:0.09em;color:#64748b;">KRA Score Breakdown</div>
            <div style="font-size:0.7rem;color:#64748b;">Total weighted: <strong style="color:#1a3a6b;"><?= number_format($weighted,2) ?> / 100</strong></div>
        </div>
        <div style="display:grid;grid-template-columns:1fr 55px 85px 75px;padding:0.4rem 1rem;background:#f8fafc;border-bottom:1px solid #e2e8f0;">
            <?php foreach (['KRA','Weight','Weighted','Potential'] as $ch): ?>
            <div style="font-size:0.57rem;font-weight:700;text-transform:uppercase;letter-spacing:0.07em;color:#94a3b8;"><?= $ch ?></div>
            <?php endforeach; ?>
        </div>
        <?php foreach ($ana_meta as $kname => [$kshort, $klabel, $kwt, $col, $bg]):
            $kpts  = $raw[$kname] ?? 0;
            $kwpts = round($kpts * ($kwt/100), 2);
            $bar   = min(100, $kpts);
            $room  = max(0, 100 - $kpts);
            $eq2   = $pdo->prepare("SELECT COUNT(*) FROM pre_eval_entries WHERE user_id=? AND kra_category=?");
            $eq2->execute([$uid, $kname]);
            $kcount = (int)$eq2->fetchColumn();
            $is_best = ($kname === $best_kra && $room > 0);
        ?>
        <div style="display:grid;grid-template-columns:1fr 55px 85px 75px;padding:0.7rem 1rem;border-bottom:1px solid #f1f5f9;align-items:center;<?= $is_best?'background:#fafafa;':'' ?>">
            <div>
                <div style="font-size:0.8rem;font-weight:700;color:#1e293b;"><?= $kshort ?> — <?= $klabel ?>
                    <?php if ($is_best): ?><span style="font-size:0.58rem;font-weight:700;background:#1a3a6b;color:#fff;padding:1px 6px;margin-left:5px;vertical-align:middle;">BEST GAIN</span><?php endif; ?>
                </div>
                <div style="font-size:0.64rem;color:#94a3b8;margin-top:1px;"><?= $kcount ?> entr<?= $kcount===1?'y':'ies' ?></div>
                <div style="height:3px;background:#e2e8f0;margin-top:6px;overflow:hidden;width:85%;">
                    <div style="height:100%;width:<?= $bar ?>%;background:<?= $bar>=100?'#16a34a':'#1a3a6b' ?>;transition:width .3s;"></div>
                </div>
            </div>
            <div style="font-size:0.78rem;color:#475569;"><?= $kwt ?>%</div>
            <div style="font-size:0.82rem;font-weight:700;color:#1a3a6b;"><?= number_format($kwpts,2) ?><span style="font-size:0.62rem;font-weight:400;color:#94a3b8;"> pts</span></div>
            <div style="font-size:0.75rem;color:#64748b;"><?= $room > 0 ? number_format($room,1).' avail.' : '— full' ?></div>
        </div>
        <?php endforeach; ?>
        <div style="display:grid;grid-template-columns:1fr 55px 85px 75px;padding:0.6rem 1rem;background:#f8fafc;border-top:2px solid #e2e8f0;">
            <div style="font-size:0.75rem;font-weight:700;color:#1a3a6b;">Final Weighted Score</div>
            <div></div>
            <div style="font-size:0.95rem;font-weight:800;color:#1a3a6b;"><?= number_format($weighted,2) ?></div>
            <div></div>
        </div>
    </div>

    <!-- ── Rank Projection + What to do next ── -->
    <div style="display:grid;grid-template-columns:1fr 1fr;gap:1.25rem;">

        <!-- Rank Projection -->
        <div style="background:#fff;border:1px solid #e2e8f0;">
            <div style="padding:0.6rem 1rem;border-bottom:1px solid #e2e8f0;font-size:0.62rem;font-weight:700;text-transform:uppercase;letter-spacing:0.09em;color:#64748b;background:#f8fafc;">Rank Projection</div>
            <div style="padding:1rem 1.1rem;">
                <div style="display:flex;align-items:stretch;gap:0.75rem;margin-bottom:0.85rem;">
                    <div style="flex:1;padding:0.65rem 0.75rem;border:1px solid #e2e8f0;background:#f8fafc;">
                        <div style="font-size:0.58rem;font-weight:700;color:#94a3b8;text-transform:uppercase;letter-spacing:0.07em;margin-bottom:3px;">Current Rank</div>
                        <div style="font-size:0.82rem;font-weight:700;color:#334155;"><?= htmlspecialchars($rank ?: '—') ?></div>
                    </div>
                    <div style="display:flex;align-items:center;color:#94a3b8;font-size:1rem;">→</div>
                    <div style="flex:1;padding:0.65rem 0.75rem;border:1px solid <?= $rank_steps>0?'#1a3a6b':'#e2e8f0' ?>;background:<?= $rank_steps>0?'#f0f4fb':'#f8fafc' ?>;">
                        <div style="font-size:0.58rem;font-weight:700;color:#94a3b8;text-transform:uppercase;letter-spacing:0.07em;margin-bottom:3px;">Projected Rank</div>
                        <div style="font-size:0.82rem;font-weight:800;color:#1a3a6b;"><?= htmlspecialchars($pot_rank ?: '—') ?></div>
                    </div>
                </div>
                <div style="font-size:0.76rem;color:#475569;line-height:1.65;border-top:1px solid #f1f5f9;padding-top:0.65rem;">
                <?php if ($weighted < 41): ?>
                    Your score of <strong><?= number_format($weighted,2) ?></strong> is below the 41-point minimum. No rank advancement is projected until you reach the threshold.
                    <?php if ($_pe_result['has_national_award'] ?? false): ?>
                    <br><i class="bi bi-trophy" style="color:#94a3b8;margin-right:3px;font-size:.68rem;"></i><span style="font-size:.72rem;color:#64748b;">Prestigious award detected — this will further advance your rank once your score reaches 41.</span>
                    <?php endif; ?>
                <?php elseif ($rank_steps === 0): ?>
                    Your score meets the minimum but does not yet produce a rank increment. A score above 50 begins advancing your projected rank.
                <?php else: ?>
                    A score of <strong><?= number_format($weighted,2) ?></strong> projects your rank from <em><?= htmlspecialchars($rank) ?></em> to <strong><?= htmlspecialchars($pot_rank) ?></strong>.
                <?php endif; ?>
                </div>

                <?php
                $has_asr_award = $_pe_result['has_national_award'] ?? false;
                $has_asr_phd   = $_pe_result['has_doctorate']      ?? false;
                if ($has_asr_award || $has_asr_phd):
                ?>
                <div style="margin-top:.65rem;padding-top:.6rem;border-top:1px solid #f1f5f9;">
                    <div style="font-size:.6rem;font-weight:700;text-transform:uppercase;letter-spacing:.07em;color:#94a3b8;margin-bottom:.35rem;">Why this rank advanced</div>
                    <div style="font-size:.75rem;color:#334155;line-height:1.65;">
                    <?php if ($has_asr_award && $has_asr_phd): ?>
                    You enabled Auto Sub-Rank through both a <strong>doctorate</strong> and a <strong>prestigious award</strong> in your Prof. Development entries.
                    The doctorate shifted your base rank one step up before scoring, and the award further advanced it —
                    which is why your projected rank jumped to <strong><?= htmlspecialchars($pot_rank) ?></strong> even though your weighted score is low.
                    In the official application, an evaluator must still verify the award evidence.
                    <?php elseif ($has_asr_award): ?>
                    You enabled Auto Sub-Rank by adding a <strong>prestigious award</strong> in Prof. Development.
                    This automatically advances your projected rank beyond what the score alone would produce —
                    which is why the projected rank shows <strong><?= htmlspecialchars($pot_rank) ?></strong>.
                    In the official application, an evaluator must verify the award evidence before this is granted.
                    <?php elseif ($has_asr_phd): ?>
                    You enabled Auto Sub-Rank through a <strong>doctorate degree</strong> in Prof. Development.
                    This shifts your base rank one step up before scoring runs,
                    which can push your projected rank higher than the score alone would suggest.
                    <?php endif; ?>
                    </div>
                </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- What to do next -->
        <div style="background:#fff;border:1px solid #e2e8f0;">
            <div style="padding:0.6rem 1rem;border-bottom:1px solid #e2e8f0;font-size:0.62rem;font-weight:700;text-transform:uppercase;letter-spacing:0.09em;color:#64748b;background:#f8fafc;">What to Do Next</div>
            <div>
            <?php
            $recs = [];
            if ($total_entries_all === 0)
                $recs[] = ['No entries added yet',
                    'Go to any KRA tab and start adding entries. Each entry calculates a score estimate automatically — you don\'t need a cycle to be open to begin.'];
            if ($weighted < 41)
                $recs[] = ['You need '.number_format($pts_to_min,2).' more weighted points',
                    'Weighted points depend on your rank\'s weight table. '.(isset($ana_meta[$best_kra])?$ana_meta[$best_kra][0].' ('.$ana_meta[$best_kra][2].'% weight) offers the most gain — adding entries there will move your score the fastest.':'Focus on KRAs with the highest weight percentage.')];
            if ($best_kra && ($raw[$best_kra] ?? 0) < 100 && $total_entries_all > 0 && $weighted >= 41)
                $recs[] = [$ana_meta[$best_kra][0].' — '.$ana_meta[$best_kra][1].' has the most room to grow',
                    'You still have '.number_format(100-($raw[$best_kra]??0),1).' raw points available in this KRA. Filling it closer to 100 will increase your weighted contribution.'];
            foreach (array_keys($ana_meta) as $kname) {
                if (($raw[$kname] ?? 0) === 0.0 && $total_entries_all > 0) {
                    $recs[] = ['Zero score in '.$ana_meta[$kname][1],
                        'Even one valid entry with a document will improve your total. Zero in any KRA drags your weighted score down — there is no penalty for adding, only upside.'];
                    break;
                }
            }
            if ($evidence_pct < 100 && $total_entries_all > 0)
                $recs[] = ['Prepare your supporting documents',
                    'For each entry you have added, identify the corresponding certificate, report, or official document you will need to attach when the cycle opens. Use the Evidence Checklist in the Evidence Repository to track what you still need to prepare.'];
            if ($weighted >= 41 && $next_threshold)
                $recs[] = ['Next scoring tier: '.$next_threshold.' points',
                    number_format($pts_to_next,2).' more weighted points moves you into the next bracket, which would improve your projected rank further.'];
            if (empty($recs))
                $recs[] = ['Your self-assessment is in good shape',
                    'You are above the minimum and have entries in your KRAs. Review each entry for accuracy and attach evidence files before the cycle opens. The Self-Assessment is advisory only — final scores are determined by evaluators.'];

            foreach ($recs as $ridx => [$rtitle,$rmsg]):
            ?>
            <div style="padding:0.75rem 1rem;border-bottom:<?= $ridx<count($recs)-1?'1px solid #f1f5f9':'none' ?>;">
                <div style="font-size:0.78rem;font-weight:700;color:#1e293b;margin-bottom:3px;"><?= htmlspecialchars($rtitle) ?></div>
                <div style="font-size:0.73rem;color:#475569;line-height:1.65;"><?= htmlspecialchars($rmsg) ?></div>
            </div>
            <?php endforeach; ?>
            </div>

            <!-- Disclaimer -->
            <div style="padding:0.65rem 1rem;border-top:1px solid #e2e8f0;background:#f8fafc;">
                <div style="font-size:0.67rem;color:#94a3b8;line-height:1.5;">
                    <i class="bi bi-info-circle" style="margin-right:4px;"></i>
                    These are <strong>estimates only</strong> — final scores are confirmed by authorised evaluators. The two-pass weight system means your projected rank may shift once a cycle opens and official scoring begins.
                </div>
            </div>
        </div>
    </div>

    <?php else: // self assessment ?>

    <?php if (!$eligible): ?>
    <!-- -- Eligibility gate banner -- -->
    <div style="padding:.75rem 1rem;background:#fef2f2;border:1px solid #fecaca;border-left:4px solid #dc2626;border-radius:6px;margin-bottom:1rem;font-size:.8rem;color:#b91c1c;">
        <div style="font-weight:700;margin-bottom:.3rem;"><i class="bi bi-shield-x me-1"></i>Not eligible for self-assessment</div>
        <?php foreach ($eligibility_notes as $en): ?>
        <div style="margin-top:.2rem;">* <?= htmlspecialchars($en) ?></div>
        <?php endforeach; ?>
    </div>
    <?php else: ?>

    <!-- ── Self-Assessment page heading ── -->
    <div style="background:#fff;border:1px solid #e2e8f0;padding:0.75rem 1.25rem;margin-bottom:1rem;
                display:flex;align-items:center;justify-content:space-between;gap:0.75rem;flex-wrap:wrap;">
        <div style="display:flex;align-items:center;gap:0.75rem;">
            <div style="width:36px;height:36px;background:#1a3a6b;display:flex;align-items:center;justify-content:center;flex-shrink:0;">
                <i class="bi bi-pencil-square" style="color:#fff;font-size:0.9rem;"></i>
            </div>
            <div>
                <div style="font-size:0.92rem;font-weight:700;color:#0f172a;line-height:1.2;">Self-Assessment</div>
                <div style="font-size:0.72rem;color:#94a3b8;margin-top:2px;">Estimate your KRA scores at any time.</div>
            </div>
        </div>
        <div style="display:flex;gap:.4rem;flex-wrap:wrap;">
            <button class="btn-s" onclick="openDrawer()" style="font-size:.75rem;font-weight:600;">Evidence Guide</button>
            <a href="evidence_repository.php" class="btn-s" style="text-decoration:none;font-size:.75rem;font-weight:600;">Evidence Repository</a>
            <button class="btn-s" onclick="openPdfViewer('self_assessment_pdf.php','Summary')" style="font-size:.75rem;font-weight:600;">Summary</button>
            <button class="btn-s" onclick="openPdfViewer('self_assessment_iss_pdf.php','ISS')" style="font-size:.75rem;font-weight:600;"><i class="bi bi-printer me-1" aria-hidden="true"></i>ISS</button>
        </div>
    </div>

    <!-- ── KRA Tabs ── -->
    <div class="tabs">
        <?php foreach ($kra_tabs as $slug => $t): $is_active = ($active_tab === $slug); ?>
        <a href="self_assessment.php?tab=<?= $slug ?>"
           class="tab-btn <?= $is_active ? 'active' : '' ?>">
            <i class="bi <?= $t['icon'] ?>" style="font-size:.72rem;"></i>
            <?= htmlspecialchars($t['label']) ?>
            <?php if (in_array($slug, ['instruction','research','extension','profdev'])): ?>
            <span class="tab-badge"><?= $counts[$slug] ?? 0 ?></span>
            <?php endif; ?>
        </a>
        <?php endforeach; ?>
    </div>

    <?php endif; ?>

    <div id="saIntroModal" style="display:none;position:fixed;inset:0;z-index:99999;background:rgba(15,23,42,0.65);align-items:center;justify-content:center;padding:1rem;">
        <div id="saIntroCard" style="background:#fff;width:100%;max-width:700px;border:1px solid #e5e7eb;overflow:hidden;opacity:0;transform:translateY(20px);transition:opacity 0.3s ease,transform 0.3s ease;">
            <div style="display:grid;grid-template-columns:220px 1fr;">
                <!-- Left navy greeting -->
                <div style="background:#1a3a6b;padding:2rem 1.5rem;display:flex;flex-direction:column;justify-content:center;">
                    <div style="font-size:0.65rem;font-weight:700;letter-spacing:0.1em;text-transform:uppercase;color:rgba(255,255,255,0.4);margin-bottom:0.5rem;">Self-Assessment</div>
                    <div style="font-size:1.3rem;font-weight:700;color:#fff;line-height:1.25;margin-bottom:0.5rem;">Hello,<br><?= htmlspecialchars($_SESSION['first_name'] ?? 'Faculty') ?>.</div>
                    <div style="width:28px;height:2px;background:rgba(255,255,255,0.25);margin-bottom:0.75rem;"></div>
                    <div style="font-size:0.72rem;color:rgba(255,255,255,0.5);line-height:1.6;">Estimate your scores and see your projected rank.</div>
                </div>
                <!-- Right content -->
                <div style="padding:1.4rem 1.4rem 1.2rem;">
                    <p style="font-size:0.8rem;color:#374151;line-height:1.6;margin:0 0 1rem;">Track your KRA entries and prepare evidence independently of your official reclassification application.</p>
                    <div style="display:grid;grid-template-columns:1fr 1fr;gap:0.4rem;margin-bottom:0.9rem;">
                        <div style="border:1px solid #e5e7eb;padding:0.55rem 0.65rem;">
                            <div style="display:flex;align-items:center;gap:0.4rem;margin-bottom:3px;"><i class="bi bi-pencil" style="color:#1a3a6b;font-size:0.78rem;"></i><span style="font-size:0.73rem;font-weight:700;color:#111827;">Enter KRA Scores</span></div>
                            <div style="font-size:0.68rem;color:#9ca3af;line-height:1.4;">Input scores for each of the 4 KRAs.</div>
                        </div>
                        <div style="border:1px solid #e5e7eb;padding:0.55rem 0.65rem;">
                            <div style="display:flex;align-items:center;gap:0.4rem;margin-bottom:3px;"><i class="bi bi-bar-chart-line" style="color:#1a3a6b;font-size:0.78rem;"></i><span style="font-size:0.73rem;font-weight:700;color:#111827;">Projected Rank</span></div>
                            <div style="font-size:0.68rem;color:#9ca3af;line-height:1.4;">See your estimated reclassification rank.</div>
                        </div>
                        <div style="border:1px solid #e5e7eb;padding:0.55rem 0.65rem;">
                            <div style="display:flex;align-items:center;gap:0.4rem;margin-bottom:3px;"><i class="bi bi-file-earmark" style="color:#1a3a6b;font-size:0.78rem;"></i><span style="font-size:0.73rem;font-weight:700;color:#111827;">Generate Summary</span></div>
                            <div style="font-size:0.68rem;color:#9ca3af;line-height:1.4;">Export a PDF report anytime.</div>
                        </div>
                        <div style="border:1px solid #e5e7eb;padding:0.55rem 0.65rem;">
                            <div style="display:flex;align-items:center;gap:0.4rem;margin-bottom:3px;"><i class="bi bi-folder2-open" style="color:#1a3a6b;font-size:0.78rem;"></i><span style="font-size:0.73rem;font-weight:700;color:#111827;">Evidence Repository</span></div>
                            <div style="font-size:0.68rem;color:#9ca3af;line-height:1.4;">Store your supporting documents.</div>
                        </div>
                    </div>
                    <div style="border-top:1px solid #f3f4f6;padding-top:0.75rem;display:flex;align-items:center;justify-content:space-between;gap:1rem;">
                        <span style="font-size:0.68rem;color:#9ca3af;"><i class="bi bi-info-circle" style="margin-right:3px;"></i>Scores are advisory only — evaluator makes the binding decision.</span>
                        <button id="saIntroProceedBtn" style="flex-shrink:0;padding:0.45rem 1.25rem;background:#1a3a6b;color:#fff;border:none;font-size:0.8rem;font-weight:700;cursor:pointer;display:inline-flex;align-items:center;gap:5px;" onmouseover="this.style.background='#152d54'" onmouseout="this.style.background='#1a3a6b'">
                            <i class="bi bi-arrow-right-circle" style="font-size:0.78rem;"></i> Proceed
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <script>
    (function() {
        var key = 'sa_intro_seen_<?= (int)($_SESSION['user_id'] ?? 0) ?>';
        var o = document.getElementById('saIntroModal');
        var c = document.getElementById('saIntroCard');
        var b = document.getElementById('saIntroProceedBtn');
        if (!localStorage.getItem(key)) {
            o.style.display = 'flex';
            requestAnimationFrame(function(){ requestAnimationFrame(function(){
                c.style.opacity = '1'; c.style.transform = 'translateY(0)';
            }); });
        }
        b.addEventListener('click', function(){
            localStorage.setItem(key, '1');
            c.style.opacity = '0'; c.style.transform = 'translateY(12px)';
            o.style.transition = 'opacity 0.25s'; o.style.opacity = '0';
            setTimeout(function(){ o.style.display='none'; o.style.opacity='1'; }, 280);
        });
    })();
    </script>

    <?php
        $pe_has_doctorate = !empty($_pe_result['has_doctorate']);
        $pe_doc_details   = (string)($_pe_result['doctorate_details'] ?? ('Doctorate - Professional Development'));
        $pe_has_award     = !empty($_pe_result['has_national_award']);
        $pe_awd_details   = (string)($_pe_result['award_details']     ?? ('National/International Award'));
        $pe_calc   = new \Scoring\AutoSubRankCalculator($pdo, 0, $uid);
        $pe_result = $pe_calc->calculateForPreEval(
            (float)($_pe_result['weighted_score'] ?? 0),
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
    <?php if ($cur_cat === 'Auto Sub Rank'): ?>

        <?php if (!empty($_GET['saved'])): ?>
        <div style="background:#f0fdf4;border:1px solid #bbf7d0;border-radius:6px;padding:.5rem .85rem;margin-bottom:.75rem;font-size:.75rem;color:#15803d;">
            <i class="bi bi-check-circle me-1"></i>Auto Sub Rank recalculated.
        </div>
        <?php endif; ?>

        <!-- Result banner -->
        <?php if ($pe_ri > 0): ?>
        <div style="background:#f0fdf4;border:1.5px solid #bbf7d0;border-radius:8px;padding:.75rem 1rem;margin-bottom:.85rem;display:flex;align-items:center;gap:.6rem;">
            <i class="bi bi-arrow-up-circle-fill" style="color:#16a34a;font-size:1.1rem;flex-shrink:0;"></i>
            <div>
                <div style="font-weight:700;color:#16a34a;font-size:.88rem;">+<?= $pe_ri ?> automatic sub-rank<?= $pe_ri > 1 ? 's' : '' ?></div>
                <div style="font-size:.72rem;color:#64748b;margin-top:2px;">System-calculated — no manual choice required</div>
            </div>
        </div>
        <?php elseif ($pe_has_award): ?>
        <div style="background:#fffbeb;border:1.5px solid #fde68a;border-radius:8px;padding:.75rem 1rem;margin-bottom:.85rem;display:flex;align-items:center;gap:.6rem;">
            <i class="bi bi-clock-fill" style="color:#d97706;font-size:1.1rem;flex-shrink:0;"></i>
            <div>
                <div style="font-weight:700;color:#92400e;font-size:.88rem;">Prestigious award detected — counted as +1 sub-rank</div>
                <div style="font-size:.72rem;color:#64748b;margin-top:2px;">Self-assessment counts this directly. In the official application, an evaluator must still verify the evidence. Score: <strong><?= number_format((float)($_pe_result['weighted_score'] ?? 0), 2) ?></strong></div>
            </div>
        </div>
        <?php elseif ($pe_has_doctorate): ?>
        <div style="background:#eff6ff;border:1.5px solid #bfdbfe;border-radius:8px;padding:.75rem 1rem;margin-bottom:.85rem;display:flex;align-items:center;gap:.6rem;">
            <i class="bi bi-mortarboard-fill" style="color:#1e4d8c;font-size:1.1rem;flex-shrink:0;"></i>
            <div>
                <div style="font-weight:700;color:#1e4d8c;font-size:.88rem;">Doctorate detected — pending score qualification</div>
                <div style="font-size:.72rem;color:#64748b;margin-top:2px;">You need a weighted score ≥ 41 before the doctorate sub-rank applies. Current score: <strong><?= number_format((float)($_pe_result['weighted_score'] ?? 0), 2) ?></strong></div>
            </div>
        </div>
        <?php else: ?>
        <div style="background:#f8fafc;border:1.5px solid #e2e8f0;border-radius:8px;padding:.75rem 1rem;margin-bottom:.85rem;display:flex;align-items:center;gap:.6rem;">
            <i class="bi bi-dash-circle" style="color:#64748b;font-size:1.1rem;flex-shrink:0;"></i>
            <div>
                <div style="font-weight:600;color:#64748b;font-size:.88rem;">No automatic sub-rank increase</div>
                <div style="font-size:.72rem;color:#94a3b8;margin-top:2px;">Add a doctorate or award entry in Prof. Development to qualify</div>
            </div>
        </div>
        <?php endif; ?>

        <!-- Criterion 1: Doctorate -->
        <div class="add-form" style="margin-bottom:.75rem;">
            <div style="font-size:.72rem;font-weight:700;color:#1a3a6b;text-transform:uppercase;letter-spacing:.06em;margin-bottom:.5rem;display:flex;align-items:center;gap:.5rem;">
                <i class="bi bi-mortarboard"></i>1  -  Doctorate Degree
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
                <a href="?tab=profdev" style="color:#1e4d8c;font-weight:600;">Add a doctorate in Prof. Development (B-degree >= 40)</a>
            </div>
            <?php endif; ?>
        </div>

        <!-- Criterion 2: Award -->
        <div class="add-form" style="margin-bottom:.75rem;">
            <div style="font-size:.72rem;font-weight:700;color:#1a3a6b;text-transform:uppercase;letter-spacing:.06em;margin-bottom:.5rem;display:flex;align-items:center;gap:.5rem;">
                <i class="bi bi-trophy"></i>2  -  National / International Award
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
            <div class="panel-hd-title"><i class="bi bi-file-earmark-check me-1"></i>Position Requirements  -  <?= sanitize($rank ?: 'Unknown Rank') ?></div>
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
                                <div style="font-size:.72rem;color:#64748b;font-weight:400;margin-top:2px;">Scopus, WoS, or ACI  -  published within the last 3 years</div>
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
                <i class="bi bi-shield-check me-1"></i>Upload these documents in the actual Reclassification application when a cycle is open  -  not here in Self-Assessment.
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
                            <?= !$eligible ? 'disabled title="Not eligible  -  see notice above" style="opacity:.45;cursor:not-allowed;"' : '' ?>>
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

                <div id="entrySaveFeedback" class="entry-save-feedback" role="status" hidden></div>
                <!-- Entry form -->
                <div class="add-form" id="addForm" style="display:none;">
                    <input type="hidden" id="editId" value="">

                    <?php if ($cur_cat !== 'Instruction'): ?>
                    <div class="fg">
                        <label class="fl" for="assessmentParent">Criterion Type</label>
                        <select class="fi" id="assessmentParent"></select>
                    </div>
                    <?php endif; ?>

                    <?php if ($cur_cat === 'Instruction'): ?>
                    <div class="fg">
                        <label class="fl">Criterion Type</label>
                        <select class="fi" id="critType" onchange="changeSelfAssessmentCriterion()">
                            <option <?= empty($instructionChildren['A']) ? 'data-criteria="kra1_a_set kra1_a_sef"' : '' ?> value="A-set-sef">A  -  Teaching Evaluation (Student Evaluation of Teaching + Supervisor Evaluation Form)</option>
                            <option <?= empty($instructionChildren['B']) ? 'data-criteria="kra1_b_*"' : '' ?> value="B-material">B  -  Instructional Material</option>
                            <option <?= empty($instructionChildren['C']) ? 'data-criteria="kra1_c_*"' : '' ?> value="C-thesis">C  -  Thesis / Dissertation Advising</option>
                            <?php foreach ($otherInstructionChildren as $letter => $children): ?>
                            <option value="parent:<?= htmlspecialchars($letter, ENT_QUOTES) ?>"><?= htmlspecialchars($letter . ' - ' . ($assessmentGroupTitles[$letter] ?? $letter), ENT_QUOTES) ?></option>
                            <?php endforeach; ?>
                            <?php $showAdditionalCriteria($topLevelCriteria); ?>
                        </select>
                    </div>
                    <div id="standardCriterionFields">
                    <div id="fA">
                        <?php if (!empty($instructionChildren['A'])): ?>
                        <div class="fg">
                            <label class="fl" for="teachingType">Type</label>
                            <select class="fi" id="teachingType" onchange="changeSelfAssessmentCriterion()">
                                <option data-criteria="kra1_a_set kra1_a_sef" value="teaching">Teaching Evaluation</option>
                                <?php $showAdditionalCriteria($instructionChildren['A'], true); ?>
                            </select>
                        </div>
                        <?php endif; ?>
                        <div class="fg2" id="teachingDetails">
                        <div class="fg-row" style="display:grid;grid-template-columns:1fr 1fr;gap:.6rem;">
                            <div><label class="fl">Student Evaluation of Teaching Average (%)</label><input type="number" class="fi" id="setV" min="0" max="100" value="0" oninput="previewScore()"></div>
                            <div><label class="fl">Supervisor Evaluation Form Average (%)</label><input type="number" class="fi" id="sefV" min="0" max="100" value="0" oninput="previewScore()"></div>
                        </div>
                        </div><!-- /fg2 -->
                    </div><!-- /fA -->
                    <div id="fB" style="display:none">
                        <div class="fg">
                            <label class="fl">Material Type</label>
                            <select class="fi" id="matType" onchange="changeSelfAssessmentCriterion()">
                                <option data-criteria="kra1_b_textbook_sole">Textbook  -  Sole Author (30 pts)</option>
                                <option data-criteria="kra1_b_textbook_co">Textbook  -  Co-Author (30 pts)</option>
                                <option data-criteria="kra1_b_chapter_sole">Textbook Chapter  -  Sole Author (10 pts)</option>
                                <option data-criteria="kra1_b_chapter_co">Textbook Chapter  -  Co-Author (10 pts)</option>
                                <option data-criteria="kra1_b_manual_sole">Manual/Module  -  Sole Author (16 pts)</option>
                                <option data-criteria="kra1_b_manual_co">Manual/Module  -  Co-Author (16 pts)</option>
                                <option data-criteria="kra1_b_multimedia">Multimedia Teaching Material (16 pts)</option>
                                <option data-criteria="kra1_b_testing">Validated Testing Material (10 pts)</option>
                                <option data-criteria="kra1_b_program_lead">Academic Program Dev  -  Lead (10 pts)</option>
                                <option data-criteria="kra1_b_program_contrib">Academic Program Dev  -  Contributor (5 pts)</option>
                                <?php $showAdditionalCriteria($instructionChildren['B'] ?? [], true); ?>
                            </select>
                        </div>
                        <div id="materialDetails">
                        <div class="fg"><label class="fl">Contribution % (co-authorship)</label><input type="number" class="fi" id="contribB" value="100" min="1" max="100" oninput="previewScore()"></div>
                        <div class="fg"><label class="fl">Title / Description</label><input type="text" class="fi" id="titleB" placeholder="Title of material"></div>
                        </div>
                    </div>
                    <div id="fC" style="display:none">
                        <div class="fg">
                            <label class="fl">Type</label>
                            <select class="fi" id="thesisType" onchange="changeSelfAssessmentCriterion()">
                                <option data-criteria="kra1_c_adviser_doctoral">Doctoral Dissertation  -  Adviser (10 pts)</option>
                                <option data-criteria="kra1_c_adviser_masters">Master's Thesis  -  Adviser (8 pts)</option>
                                <option data-criteria="kra1_c_adviser_undergrad">Undergraduate Thesis  -  Adviser (5 pts)</option>
                                <option data-criteria="kra1_c_adviser_special">Special Project / Capstone  -  Adviser (3 pts)</option>
                                <option data-criteria="kra1_c_panel_doctoral">Doctoral Dissertation  -  Panel Member (6 pts)</option>
                                <option data-criteria="kra1_c_panel_masters">Master's Thesis  -  Panel Member (4 pts)</option>
                                <option data-criteria="kra1_c_panel_undergrad">Undergraduate Thesis  -  Panel Member (2 pts)</option>
                                <option data-criteria="kra1_c_panel_special">Special Project / Capstone  -  Panel Member (1 pt)</option>
                                <option data-criteria="kra1_c_mentor_competition">Mentor: Student/Team Competition Winner (Warning pts pending confirmation)</option>
                                <?php $showAdditionalCriteria($instructionChildren['C'] ?? [], true); ?>
                            </select>
                        </div>
                        <div class="fg" id="thesisDetails"><label class="fl">Advisee / Student Name (optional)</label><input type="text" class="fi" id="advisee" placeholder="Name of advisee"></div>
                    </div>
                    <?php if ($otherInstructionChildren): ?>
                    <div class="fg" id="fOtherInstruction" hidden>
                        <label class="fl" for="otherInstructionType">Type</label>
                        <select class="fi" id="otherInstructionType" onchange="changeSelfAssessmentCriterion()">
                            <?php foreach ($otherInstructionChildren as $children) $showAdditionalCriteria($children, true); ?>
                        </select>
                    </div>
                    <?php endif; ?>

                    </div>
                    <?php elseif ($cur_cat === 'Research'): ?>
                    <div class="fg">
                        <label class="fl">Output Type</label>
                        <select class="fi" id="resType" onchange="changeSelfAssessmentCriterion()">
                            <option data-criteria="kra2_a_book_sole" value="Book, Sole Author (100pts)">Book  -  Sole Author (100 pts)</option>
                            <option data-criteria="kra2_a_book_co" value="Book, Co-Author (100pts)">Book  -  Co-Author (100 pts)</option>
                            <option data-criteria="kra2_a_monograph_sole" value="Monograph, Sole Author (100pts)">Monograph  -  Sole Author (100 pts)</option>
                            <option data-criteria="kra2_a_monograph_co" value="Monograph, Co-Author (100pts)">Monograph  -  Co-Author (100 pts)</option>
                            <option data-criteria="kra2_a_journal_indexed_sole" value="Indexed Journal Article, Sole Author (50pts)">Indexed Journal Article  -  Sole Author (50 pts)</option>
                            <option data-criteria="kra2_a_journal_indexed_co" value="Indexed Journal Article, Co-Author (50pts)">Indexed Journal Article  -  Co-Author (50 pts)</option>
                            <option data-criteria="kra2_a_book_chapter_sole" value="Book Chapter, Sole Author (35pts)">Book Chapter  -  Sole Author (35 pts)</option>
                            <option data-criteria="kra2_a_book_chapter_co" value="Book Chapter, Co-Author (35pts)">Book Chapter  -  Co-Author (35 pts)</option>
                            <option data-criteria="kra2_a_policy_lead" value="Research Policy/Product, Lead (35pts)">Research -> Policy/Product  -  Lead (35 pts)</option>
                            <option data-criteria="kra2_a_policy_contrib" value="Research Policy/Product, Contributor (35pts)">Research -> Policy/Product  -  Contributor (35 pts)</option>
                            <option data-criteria="kra2_a_scholarly_other" value="Peer-Reviewed Scholarly Output (10pts)">Other Peer-Reviewed Output (10 pts)</option>
                            <option data-criteria="kra2_a_citation_local" value="Local Citation (5pts)">Local Citation (5 pts each)</option>
                            <option data-criteria="kra2_a_citation_intl" value="International Citation (10pts)">International Citation (10 pts each)</option>
                            <?php $showAdditionalCriteria($topLevelCriteria, true); ?>
                        </select>
                    </div>
                    <div id="standardCriterionFields">
                    <div class="fg"><label class="fl">Title / Journal Name</label><input type="text" class="fi" id="resTitle" placeholder="Publication title"></div>
                    <div class="fg"><label class="fl">Contribution % (co-authorship)</label><input type="number" class="fi" id="resContrib" value="100" min="1" max="100" oninput="previewScore()"></div>

                    </div>
                    <?php elseif ($cur_cat === 'Extension'): ?>
                    <div class="fg">
                        <label class="fl">Criterion / Type</label>
                        <select class="fi" id="extType" onchange="changeSelfAssessmentCriterion()">
                            <option data-criteria="kra3_a_moa" value="moa-linkage">MOA / Linkage (5 pts)</option>
                            <option data-criteria="kra3_a_income_*" value="income">Income Generating Project</option>
                            <option data-criteria="kra3_b_accreditation_local" value="accredit-local">Accreditation / QA - Local (8 pts)</option>
                            <option data-criteria="kra3_b_accreditation_intl" value="accredit-intl">Accreditation / QA - International (10 pts)</option>
                            <option data-criteria="kra3_b_resource_local" value="resource-speaker-local">Resource Speaker - Local (2 pts)</option>
                            <option data-criteria="kra3_b_resource_intl" value="resource-speaker-intl">Resource Speaker - International (3 pts)</option>
                            <option data-criteria="kra3_b_outreach_head" value="outreach-isr-lead">Outreach / ISR - Lead (5 pts)</option>
                            <option data-criteria="kra3_b_outreach_participant" value="outreach-isr-member">Outreach / ISR - Member (2 pts)</option>
                            <option data-criteria="kra3_c_satisfaction" value="csr-satisfaction">CSR Satisfaction Rating (max 20 pts)</option>
                            <option data-criteria="kra3_b_consultant_local" value="consultant-local">Consultant / Expert - Local (8 pts)</option>
                            <option data-criteria="kra3_b_consultant_intl" value="consultant-intl">Consultant / Expert - International (10 pts)</option>
                            <option data-criteria="kra3_b_judge_research" value="judge-research">Judge - Research/Competition (2 pts)</option>
                            <option data-criteria="kra3_b_judge_competition" value="judge-other">Judge - Other Event (1 pt)</option>
                            <?php $showAdditionalCriteria($topLevelCriteria, true); ?>
                        </select>
                    </div>
                    <div id="standardCriterionFields">
                    <div class="fg"><label class="fl">Activity / Project / Designation Name</label><input type="text" class="fi" id="extTitle" placeholder="e.g. Community outreach program"></div>
                    <div class="fg" id="extVal1Wrap" style="display:none;"><label class="fl" id="extVal1Label">Value</label><input type="number" class="fi" id="extVal1" value="" min="0" oninput="previewScore()"></div>
                    <div class="fg" id="extVal2Wrap" style="display:none;"><label class="fl">Role</label><select class="fi" id="extVal2" onchange="previewScore()"><option value="lead">Lead</option><option value="co">Co/Member</option></select></div>

                    </div>
                    <?php else: // Professional Development ?>
                    <div class="fg">
                        <label class="fl">Credential / Activity Type</label>
                        <select class="fi" id="pdType" onchange="changeSelfAssessmentCriterion()">
                            <option data-criteria="kra4_a_org_membership" value="A-org">Active Professional Org Membership (5 pts)</option>
                            <option data-criteria="kra4_b_training_*" value="B-training">Training / Conference</option>
                            <option data-criteria="kra4_b_postmaster_cert kra4_b_additional_masters" value="B-degree">Educational Qualification</option>
                            <option data-criteria="kra4_b_paper_*" value="B-paper">Paper Presentation</option>
                            <option data-criteria="kra4_c_*" value="C-award">Award / Recognition</option>
                            <?php $showAdditionalCriteria($topLevelCriteria, true); ?>
                        </select>
                    </div>
                    <div id="standardCriterionFields">
                    <div class="fg"><label class="fl">Description / Title</label><input type="text" class="fi" id="pdDesc" placeholder="e.g. PhD in Education"></div>
                    <div class="fg" id="pdSubWrap" style="display:none">
                        <label class="fl">Value / Sub-type</label>
                        <select class="fi" id="pdSubval" onchange="previewScore()"></select>
                    </div>
                    </div>
                    <?php endif; ?>

                        <div class="fg"><label class="fl">Personal Notes (optional)</label><textarea class="fi" id="entryNotes" rows="2" placeholder="Your own notes about this entry..."></textarea></div>

                    <div class="form-actions">
                        <div class="score-preview"><i class="bi bi-calculator"></i>Est. score: <span id="scorePreview">0.00</span> pts</div>
                        <div style="display:flex;gap:.4rem;">
                            <button class="btn-s" onclick="cancelForm()">Cancel</button>
                            <button class="btn-p" id="saveBtn" onclick="saveEntry()">Save Entry</button>
                        </div>
                    </div>
                </div>

                <!-- Sub-cap progress bars  -  hidden until get_score runs -->
                <?php
                $scoreGroups = criteriaGroups($all_criteria[$cur_cat] ?? []);
                ?>
                <div id="subcapBars" style="display:none;background:#f8fafc;border:1px solid #e2e8f0;border-top:none;padding:.55rem .85rem .45rem;margin-bottom:.6rem;">
                    <div style="font-size:.58rem;font-weight:700;color:#94a3b8;text-transform:uppercase;letter-spacing:.08em;margin-bottom:.4rem;">
                        <i class="bi bi-bar-chart-line me-1"></i>Criterion breakdown
                        <span style="font-weight:400;font-style:italic;margin-left:.3rem;">(updates after each save)</span>
                    </div>
                    <div id="subcapList" style="display:flex;flex-direction:column;gap:.3rem;">
                        <?php
                        foreach ($scoreGroups as $code => $group):
                            $label = $code === '' ? $group['name'] : $code . ' - ' . $group['name'];
                            $keys = array_column($group['children'], 'criterion_key');
                        ?>
                        <div class="subcap-row" data-parent="<?= htmlspecialchars($code, ENT_QUOTES) ?>" data-cap="<?= $group['max'] ?? '' ?>" data-keys="<?= htmlspecialchars(json_encode($keys), ENT_QUOTES) ?>">
                            <div style="display:flex;justify-content:space-between;align-items:baseline;margin-bottom:1px;">
                                <span style="font-size:.65rem;color:#475569;font-weight:600;"><?= htmlspecialchars($label, ENT_QUOTES) ?></span>
                                <span class="subcap-pts" style="font-size:.65rem;font-weight:700;color:#1a3a6b;"> - </span>
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
                    $es = ['Instruction'=>['bi-book-half',100,'Teaching effectiveness (Student Evaluation of Teaching/Supervisor Evaluation Form), instructional materials, and thesis/capstone advisory work.'],
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

            </div>
        </div>

    <?php endif; // end autosubrank / posreq / kra panel ?>

    </div><!-- /panels -->
    <?php endif; // end self assessment view ?>
</div><!-- /main -->
</div><!-- /pg -->

<!-- -- PDF Viewer Modal -- -->
<div id="pdfViewerOverlay"
     style="display:none;position:fixed;inset:0;background:rgba(0,0,0,0.6);z-index:2000;
            align-items:center;justify-content:center;padding:1rem;">
    <div style="background:#fff;width:100%;max-width:900px;height:90vh;display:flex;
                flex-direction:column;border:1px solid #e2e8f0;
                box-shadow:0 20px 60px rgba(0,0,0,0.3);">
        <!-- Header -->
        <div style="display:flex;align-items:center;justify-content:space-between;
                    padding:0.6rem 1rem;background:#1a3a6b;flex-shrink:0;">
            <span id="pdfViewerTitle" style="color:#fff;font-weight:700;font-size:0.85rem;"></span>
            <div style="display:flex;align-items:center;gap:0.5rem;">
                <a id="pdfViewerNewTab" href="#" target="_blank"
                   style="display:inline-flex;align-items:center;gap:4px;padding:3px 10px;
                          border:1px solid rgba(255,255,255,0.4);color:#fff;
                          font-size:0.72rem;font-weight:600;text-decoration:none;background:rgba(255,255,255,0.1);"
                   onmouseover="this.style.background='rgba(255,255,255,0.2)'"
                   onmouseout="this.style.background='rgba(255,255,255,0.1)'">
                    <i class="bi bi-box-arrow-up-right"></i> Open in new tab
                </a>
                <button onclick="closePdfViewer()"
                        style="background:none;border:none;color:rgba(255,255,255,0.7);
                               font-size:1.3rem;cursor:pointer;line-height:1;padding:0 2px;"
                        onmouseover="this.style.color='#fff'"
                        onmouseout="this.style.color='rgba(255,255,255,0.7)'">&times;</button>
            </div>
        </div>
        <!-- iframe -->
        <iframe id="pdfViewerFrame" src="" style="flex:1;border:none;width:100%;"></iframe>
    </div>
</div>

<!-- -- Evidence Guide Drawer -- -->
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
                    'Instruction'=>'KRA I  -  Instruction','Research'=>'KRA II  -  Research',
                    'Extension'=>'KRA III  -  Extension','Professional Development'=>'KRA IV  -  Prof. Development'
                ];
                $cc = $cat_colors[$cat] ?? '#1a3a6b';
            ?>
            <div class="crit-group" data-cat="<?= htmlspecialchars($cat) ?>">
                <div class="crit-group-label" style="color:<?= $cc ?>">
                    <?= htmlspecialchars($cat_labels[$cat] ?? $cat) ?>
                </div>
                <?php foreach (criteriaGroups($crits) as $code => $group):
                    $groupLabel = $code === '' ? $group['name'] : 'Criterion ' . $code . ' - ' . $group['name'];
                ?>
                <section class="crit-parent" data-label="<?= htmlspecialchars(strtolower($groupLabel), ENT_QUOTES) ?>">
                <div class="crit-group-label"><?= htmlspecialchars($groupLabel) ?></div>
                <?php foreach ($group['children'] as $cr):
                    $desc = trim($cr['description'] ?? '');
                    // Support both newline-separated bullets (new format) and legacy sentence-based text
                    if ($desc) {
                        $evidences = array_values(array_filter(array_map('trim', explode("\n", $desc))));
                        if (count($evidences) === 1) {
                            // Legacy single-line: split on ". " followed by capital letter
                            $evidences = array_values(array_filter(array_map('trim', preg_split('/\.\s+(?=[A-Z])/', $desc))));
                        }
                    } else {
                        $evidences = [];
                    }
                ?>
                <div class="crit-item" data-label="<?= htmlspecialchars(strtolower($cr['criterion_label'])) ?>" data-cat="<?= htmlspecialchars($cat) ?>">
                    <div class="crit-item-hd" onclick="toggleCrit(this)">
                        <span class="crit-label"><?= htmlspecialchars($cr['criterion_label']) ?></span>
                        <span class="crit-pts" style="color:<?= $cc ?>;background:<?= $cc ?>18;border-color:<?= $cc ?>44;"><?= rtrim(rtrim(number_format((float)$cr['max_points'], 2), '0'), '.') ?> pts</span>
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
                </section>
                <?php endforeach; ?>
            </div>
            <?php endforeach; ?>
        </div>

    </div>
</div>

<script>
const AJAX = 'self_assessment_ajax.php';
const CAT  = <?= json_encode($cur_cat) ?>;
const builtinCriteria = <?= json_encode(array_values(array_column(array_filter($all_criteria[$cur_cat] ?? [], static fn(array $row): bool => !kraIsAdditionalCriterionKey($row['criterion_key'])), 'criterion_key')), JSON_HEX_TAG) ?>;
const KRA_NUM = <?= $kra_num ?>;
const EMPTY_STATE_INFO = <?= json_encode([
    'Instruction'              => ['icon'=>'bi-book-half',    'max'=>100, 'desc'=>'Teaching effectiveness (Student Evaluation of Teaching/Supervisor Evaluation Form ratings), instructional materials, and thesis/capstone advisory work.'],
    'Research'                 => ['icon'=>'bi-journal-text', 'max'=>100, 'desc'=>'Publications, book chapters, citations, and policy research outputs.'],
    'Extension'                => ['icon'=>'bi-people-fill',  'max'=>100, 'desc'=>'Community outreach programs, MOAs/linkages, and income-generating extension activities.'],
    'Professional Development' => ['icon'=>'bi-award-fill',   'max'=>100, 'desc'=>'Professional org membership, training/conferences, paper presentations, degrees, and awards.'],
]) ?>;

// -- Nav dropdown ----------------------------------------------
function toggleDD(){document.getElementById('ndd').classList.toggle('open');}
document.addEventListener('click',e=>{
    const n=document.getElementById('nu'),d=document.getElementById('ndd');
    if(d&&n&&!n.contains(e.target))d.classList.remove('open');
});

const ELIGIBLE = <?= $eligible ? 'true' : 'false' ?>;

// -- Add/cancel form -------------------------------------------
function toggleForm(){
    if (!ELIGIBLE) return; // server-side gate reflected in UI
    showEntrySaveFeedback('');
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
    const sb = document.getElementById('saveBtn');
    if (sb) sb.textContent = 'Save Entry';
    resetForm();
}

function editEntry(eid, btn){
    if (!ELIGIBLE) return;
    showEntrySaveFeedback('');
    const remarks = btn.dataset.remarks || '';
    const notes   = btn.dataset.notes   || '';
    // Show form
    const f = document.getElementById('addForm');
    f.style.display = 'block';
    document.getElementById('addBtn').innerHTML = '<i class="bi bi-x"></i> Cancel';
    // Set edit ID
    document.getElementById('editId').value = eid;
    // Pre-populate notes
    const notesEl = document.getElementById('entryNotes');
    if (notesEl) notesEl.value = notes || '';
    // Pre-populate form fields from remarks
    const parts = remarks.split('|||').map(s => s.trim());
    const ctype = parts[0] || '';
    const selector = document.querySelector('#critType, #resType, #extType, #pdType');
    const storedChoice = ctype === 'CUSTOM' ? `custom:${parts[2] || ''}` : ctype;
    const assessmentParent = document.getElementById('assessmentParent');
    if (assessmentParent && selector) {
        const storedOption = assessmentOptions.find(option => option.value === storedChoice);
        if (storedOption) {
            assessmentParent.value = assessmentOptionParent(storedOption);
            updateAssessmentTypes(assessmentParent, selector);
        }
        selector.value = storedChoice;
        changeSelfAssessmentCriterion();
        if (CAT === 'Professional Development' && ctype !== 'CUSTOM') {
            document.getElementById('pdDesc').value = parts[1] || '';
            document.getElementById('pdSubval').value = parts[2] || '';
        }
    } else if (selector && ctype === 'CUSTOM') {
        const parent = CAT === 'Instruction' ? Object.keys(instructionFields).find(choice => {
            const subtype = instructionSubtype(choice);
            return subtype && Array.from(subtype.options).some(option => option.value === storedChoice);
        }) : undefined;
        const other = otherInstructionOptions.find(option => option.value === storedChoice);
        selector.value = parent || (other ? `parent:${other.dataset.parent}` : storedChoice);
        if (other) updateOtherInstructionTypes(selector.value);
        const subtype = instructionSubtype(selector.value);
        if (subtype) subtype.value = storedChoice;
        changeSelfAssessmentCriterion();
    } else if (selector && selector.value.startsWith('custom:')) {
        selector.selectedIndex = Array.from(selector.options).findIndex(option => !option.value.startsWith('custom:'));
        changeSelfAssessmentCriterion();
    }
    // Set criterion type selector if present
    const critSel = document.getElementById('critType') || document.querySelector('[id^="critType"]');
    if (critSel && ctype !== 'CUSTOM') {
        critSel.value = ctype;
        const subtype = instructionSubtype(ctype);
        if (subtype) subtype.value = ctype === 'A-set-sef' ? 'teaching' : parts[1];
        changeSelfAssessmentCriterion();
    }
    // After a tick (to let dependent fields render), fill sub-fields
    setTimeout(() => {
        const setVal = (id, val) => { const el = document.getElementById(id); if (el) el.value = val; };
        setVal('f1', parts[1] || '');
        setVal('f2', parts[2] || '');
        setVal('f3', parts[3] || '');
        setVal('f4', parts[4] || '');
        setVal('f5', parts[5] || '');
        // Trigger preview update
        if (typeof previewScore === 'function') previewScore();
    }, 80);
    // Scroll form into view
    f.scrollIntoView({ behavior: 'smooth', block: 'start' });
    // Update save button label
    const sb = document.getElementById('saveBtn');
    if (sb) sb.textContent = 'Update Entry';
}
function resetForm(){
    document.getElementById('editId').value='';
    const selector = document.querySelector('#critType, #resType, #extType, #pdType');
    if (selector && (!selector.value || selector.value.startsWith('custom:'))) {
        selector.selectedIndex = 0;
        changeSelfAssessmentCriterion();
    }
    const subtype = instructionSubtype(selector?.value);
    if (subtype && (!subtype.value || subtype.value.startsWith('custom:'))) {
        subtype.selectedIndex = 0;
        changeSelfAssessmentCriterion();
    }
    const n=document.getElementById('entryNotes'); if(n)n.value='';
    const pdDesc = document.getElementById('pdDesc'); if (pdDesc) pdDesc.value = '';
    const sp=document.getElementById('scorePreview'); if(sp)sp.textContent='0.00';
}

function changeSelfAssessmentCriterion(){
    const choice = document.querySelector('#critType, #resType, #extType, #pdType')?.value;
    document.getElementById('standardCriterionFields').hidden = !choice || choice.startsWith('custom:');
    if (choice && !choice.startsWith('custom:')) {
        if (CAT === 'Instruction') {
            switchInstrForm();
            const field = instructionFields[choice];
            const subtype = instructionSubtype(choice);
            if (field) document.getElementById(field.details).style.display = subtype && (!subtype.value || subtype.value.startsWith('custom:')) ? 'none' : '';
        }
        if (CAT === 'Extension') updateExtOpts();
        if (CAT === 'Professional Development') updatePdOpts();
    }
    previewScore();
}

const instructionFields = {
    'A-set-sef': {select:'teachingType', details:'teachingDetails'},
    'B-material': {select:'matType', details:'materialDetails'},
    'C-thesis': {select:'thesisType', details:'thesisDetails'},
};

function instructionSubtype(choice){
    if (choice?.startsWith('parent:')) return document.getElementById('otherInstructionType');
    const field = instructionFields[choice];
    return field ? document.getElementById(field.select) : null;
}

const assessmentGroupTitles = <?= json_encode($assessmentGroupTitles, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
const assessmentCatalog = <?= json_encode(array_column(array_map(static fn(array $row): array => [
    'key'=>$row['criterion_key'], 'parent'=>$row['parent_code'] ?? '', 'points'=>(float)$row['max_points'],
    'label'=>$row['criterion_label'],
], $all_criteria[$cur_cat] ?? []), null, 'key'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
let assessmentOptions = [];
let otherInstructionOptions = [];

function assessmentOptionParent(option){
    const builtIn = option.dataset.criteria?.match(/^kra\d_([a-z])_/i);
    return option.dataset.parent || builtIn?.[1].toUpperCase() || option.value;
}

function updateAssessmentTypes(parent, types){
    types.replaceChildren(...assessmentOptions.filter(option => assessmentOptionParent(option) === parent.value));
    types.selectedIndex = 0;
}

function initializeAssessmentParents(){
    const other = document.getElementById('otherInstructionType');
    if (other) otherInstructionOptions = Array.from(other.options);
    const parent = document.getElementById('assessmentParent');
    const types = document.querySelector('#resType, #extType, #pdType');
    if (!parent || !types) return;

    assessmentOptions = Array.from(types.options);
    const parents = new Map();
    for (const option of assessmentOptions) {
        const key = assessmentOptionParent(option);
        if (parents.has(key)) continue;
        const label = assessmentGroupTitles[key];
        parents.set(key, label ? `${key} - ${label}` : (/^[A-Z]$/.test(key) ? `Criterion ${key}` : option.text));
    }
    parent.replaceChildren(...Array.from(parents, ([key, label]) => new Option(label, key)));
    parent.addEventListener('change', () => {
        updateAssessmentTypes(parent, types);
        changeSelfAssessmentCriterion();
    });
    updateAssessmentTypes(parent, types);
}

function updateOtherInstructionTypes(choice){
    const select = document.getElementById('otherInstructionType');
    if (!select || select.dataset.selectedParent === choice) return;
    select.dataset.selectedParent = choice;
    select.replaceChildren(...otherInstructionOptions.filter(option => `parent:${option.dataset.parent}` === choice));
    select.selectedIndex = 0;
}

function hasSelfAssessmentCriterion(keys){
    return keys.split(' ').some(key => key.endsWith('*')
        ? builtinCriteria.some(active => active.startsWith(key.slice(0, -1)))
        : builtinCriteria.includes(key));
}

function assessmentPointLabel(fallback, key) {
    const criterion = assessmentCatalog[key];
    if (!criterion) return fallback;
    const label = criterion.label.replace(/^Criterion\s+[A-Z]\b\s*[-.:–—]?\s*/i, '');
    return `${label || criterion.label} (${criterion.points} pts)`;
}

// -- Instruction form switch ------------------------------------
function switchInstrForm(){
    const t=document.getElementById('critType')?.value;
    document.getElementById('fA').style.display=t==='A-set-sef'?'block':'none';
    document.getElementById('fB').style.display=t==='B-material'?'block':'none';
    document.getElementById('fC').style.display=t==='C-thesis'?'block':'none';
    const other = document.getElementById('fOtherInstruction');
    if (other) {
        other.style.display = t?.startsWith('parent:') ? 'block' : 'none';
        other.hidden = !t?.startsWith('parent:');
        if (!other.hidden) updateOtherInstructionTypes(t);
    }
}

// -- ProfDev sub-options ----------------------------------------
const PD_OPTS={
    'B-training':[['1','1 pt  -  Local','kra4_b_training_local'],['2','2 pts  -  International','kra4_b_training_intl']],
    'B-degree':  [['10','10 pts  -  Post-Master\'s/Post-Doctoral Cert.','kra4_b_postmaster_cert'],['20','20 pts  -  Additional Master\'s','kra4_b_additional_masters']],
    'B-paper':   [['3','3 pts  -  Local Presentation','kra4_b_paper_local'],['5','5 pts  -  International Presentation','kra4_b_paper_intl']],
    'C-award':   [['2','2 pts  -  Institutional','kra4_c_award_institutional'],['3','3 pts  -  Local','kra4_c_award_local'],['4','4 pts  -  Regional','kra4_c_award_national'],['0','National/International (+1 sub-rank, 0 pts)','kra4_c_award_prestigious']],
};
function updatePdOpts(){
    const t=document.getElementById('pdType')?.value;
    const w=document.getElementById('pdSubWrap');
    const s=document.getElementById('pdSubval');
    if(!t||!w||!s)return;
    if(t==='A-org'){w.style.display='none';return;}
    const opts=PD_OPTS[t]||[];
    s.replaceChildren(...opts.filter(([, , key])=>hasSelfAssessmentCriterion(key)).map(([v,l,key]) =>
        new Option(assessmentPointLabel(l, key), v)));
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

// -- Build remarks ----------------------------------------------
function buildRemarks(){
    const choice = document.querySelector('#critType, #resType, #extType, #pdType')?.value;
    if (!choice) return '';
    const subtype = CAT === 'Instruction' ? instructionSubtype(choice) : null;
    const selectedItem = subtype ? subtype.value : choice;
    if (!selectedItem) return '';
    if (selectedItem.startsWith('custom:')) return `CUSTOM|||${encodeURIComponent(selectedItem.slice(7))}`;
    if(CAT==='Instruction'){
        const t=document.getElementById('critType')?.value||'A-set-sef';
        if(t==='A-set-sef'){
            const s=hasSelfAssessmentCriterion('kra1_a_set') ? (document.getElementById('setV')?.value||'0') : '0';
            const f=hasSelfAssessmentCriterion('kra1_a_sef') ? (document.getElementById('sefV')?.value||'0') : '0';
            const n=document.getElementById('entryNotes')?.value||'';
            return `A-set-sef|||${s}|||${f}|||${n}`;
        }else if(t==='B-material'){
            const l=selectedItem;
            const c=document.getElementById('contribB')?.value||'100';
            return `B-material|||${l}|||${c}`;
        }else{
            const l=selectedItem;
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
        const v=t==='A-org'?'1':(document.getElementById('pdSubval')?.value||'');
        if (!v) return '';
        return `${t}|||${d}|||${v}`;
    }
}

// -- Score preview ----------------------------------------------
function previewScore(){
    const r=buildRemarks();
    if(!r){document.getElementById('scorePreview').textContent='0.00';return;}
    fetch(AJAX,{method:'POST',body:new URLSearchParams({action:'preview_score',cat:CAT,remarks:r})})
        .then(x=>x.json()).then(d=>{
            if (r !== buildRemarks()) return;
            document.getElementById('scorePreview').textContent=d.ok?d.points.toFixed(2):'?';
        }).catch(()=>{});
}

// -- Save entry -------------------------------------------------
function showEntrySaveFeedback(message, isError = false) {
    const feedback = document.getElementById('entrySaveFeedback');
    feedback.textContent = message;
    feedback.classList.toggle('is-error', isError);
    feedback.hidden = !message;
    if (message && isError) feedback.scrollIntoView({block:'nearest'});
}

async function saveEntry(){
    const saveBtn = document.getElementById('saveBtn');
    if (saveBtn.disabled) return;
    showEntrySaveFeedback('');
    const remarks=buildRemarks();
    if (!remarks) {
        showEntrySaveFeedback('Choose an available criterion and subtype before saving.', true);
        return;
    }
    const notes=document.getElementById('entryNotes')?.value||'';
    const editId=document.getElementById('editId')?.value||'';
    const fd=new FormData();
    fd.append('action','save_entry'); fd.append('cat',CAT);
    fd.append('remarks',remarks); fd.append('notes',notes);
    if(editId) fd.append('entry_id',editId);
    saveBtn.disabled = true;
    saveBtn.textContent = 'Saving…';
    try {
        const response = await fetch(AJAX, {method:'POST', body:fd});
        const result = await response.json();
        if (!response.ok || !result.ok) {
            showEntrySaveFeedback(result.error || 'The entry could not be saved. Please try again.', true);
            return;
        }
        cancelForm();
        loadEntries();
        refreshScore();
        showEntrySaveFeedback(editId ? 'Entry updated.' : 'Entry saved.');
    } catch {
        showEntrySaveFeedback('Could not confirm the save. Check your saved entries before trying again.', true);
    } finally {
        saveBtn.disabled = false;
        saveBtn.textContent = document.getElementById('editId').value ? 'Update Entry' : 'Save Entry';
    }
}

// -- Load entries -----------------------------------------------
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
            <i class="bi bi-paperclip" style="font-size:.6rem;"></i>${esc(f.original_filename.length>20?f.original_filename.slice(0,20)+'...':f.original_filename)}
        </a>
        <button class="chip-del" onclick="delFile(event,${f.file_id})" title="Remove">x</button>
    `).join('');
    return `<div class="entry-card" id="ec-${e.entry_id}">
        <div class="entry-card-top">
            <div><div class="entry-label">${lbl}</div>${notes}</div>
            <div class="entry-pts">${parseFloat(e.computed_points).toFixed(2)}</div>
        </div>
        ${files?`<div class="entry-files">${files}</div>`:''}
        <div class="entry-actions">
            <button class="btn-xs btn-attach" onclick="editEntry(${e.entry_id}, this)" data-remarks="${esc(e.remarks)}" data-notes="${esc(e.notes||'')}"><i class="bi bi-pencil"></i> Edit</button>
            <button class="btn-xs btn-rm" onclick="delEntry(${e.entry_id})"><i class="bi bi-trash"></i> Remove</button>
        </div>
    </div>`;
}

function fmtLabel(r){
    const p=r.split('|||');
    const t=p[0]||'';
    if(t==='CUSTOM') return `<strong>${esc(p[3]||'Criterion')}</strong>`;
    if(t==='A-set-sef') return `<strong>Teaching Evaluation</strong>  -  Student Evaluation of Teaching: ${p[1]||0}%, Supervisor Evaluation Form: ${p[2]||0}%`;
    if(t==='A-set-sem'||t==='A-sef-sem') return `<strong>${t==='A-sef-sem'?'Supervisor Evaluation Form':'Student Evaluation of Teaching'} Teaching Evaluation</strong>  -  ${esc(p[1]||'')}, ${p[2]==='2'?'2nd Semester':'1st Semester'}: ${p[3]||0}%`;
    if(t==='B-material') return `<strong>Material:</strong> ${esc(p[1]||'')}`;
    if(t==='C-thesis')   return `<strong>Thesis/Dissertation:</strong> ${esc(p[1]||'')}`;
    if(['moa-linkage','income','accredit-local','accredit-intl','resource-speaker-local','resource-speaker-intl','outreach-isr-lead','outreach-isr-member','csr-satisfaction','consultant-local','consultant-intl','judge-research','judge-other'].includes(t)){
        const value = p[2] ? ` - ${esc(p[2])}${t==='csr-satisfaction'?'%':''}` : '';
        return `<strong>${esc(t.replaceAll('-',' '))}</strong>${p[1]?' - '+esc(p[1]):''}${value}`;
    }
    if(t==='A-org')      return `<strong>Professional Org Membership</strong>`;
    if(t.startsWith('B-')||t.startsWith('C-')) return `<strong>${esc(p[1]||t)}</strong>${p[2]?'  -  '+esc(p[2])+'pts':''}`;
    // Research / Extension
    return `<strong>${esc(p[0])}</strong>${p[1]?'  -  '+esc(p[1]):''}`;
}

// -- Delete entry / file ----------------------------------------
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

// -- Refresh sidebar score via AJAX (no page reload) ------------
function refreshScore(){
    fetch(`${AJAX}?action=get_score`).then(x=>x.json()).then(d=>{
        if(!d.ok)return;
        const sv=document.getElementById('sbScore');
        if(sv){
            sv.textContent=d.weighted.toFixed(2);
            sv.style.color='#fff';
        }
        const mn=document.getElementById('sbMinNote');
        if(mn){
            mn.textContent=d.weighted>=41?'✓ Meets minimum (41)':'✗ Below minimum (41)';
            mn.style.color='rgba(255,255,255,.55)';
        }
        // Update KRA bars
        const SLUGS=['instruction','research','extension','profdev'];
        const CATS=['Instruction','Research','Extension','Professional Development'];
        const MAXS=<?= json_encode(array_values(kraMaximumPoints())) ?>;
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
            if(pts) pts.textContent=wpts.toFixed(1)+'pt';
            if(bar) bar.style.width=pct+'%';
        });

        // Update rank projection + increment badge
        const potEl  = document.getElementById('sbPotRank');
        const incWrap = document.querySelector('.rank-box');
        if (incWrap) {
            const curRankEl = document.getElementById('sbCurRank');
            const curRank   = curRankEl ? curRankEl.textContent.trim() : '';
            const potRank   = d.pot_rank || '';
            if (potEl) potEl.textContent = (potRank && potRank !== curRank && potRank !== ' - ') ? potRank : curRank;

            // No arrow element in new layout — skip arrow hide/show

            // Rebuild increment badge
            let badge = incWrap.querySelector('.increment-badge');
            const belowNote = incWrap.querySelector('.below-note');
            if (d.sub_rank > 0) {
                const tip = 'Total sub-rank increase from your weighted score and any automatic increases.';
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

        // -- Update sub-cap criterion bars ------------------
        const bars  = document.querySelectorAll('.subcap-row');
        const barsWrap = document.getElementById('subcapBars');
        if (bars.length && d.kra_detail) {
            if (barsWrap) barsWrap.style.display = '';
            bars.forEach(row => {
                const cap = parseFloat(row.dataset.cap) || 0;
                const scores = JSON.parse(row.dataset.keys)
                    .map(key => d.additional_scores?.[CAT]?.[key]).filter(value => value != null);
                const score = d.parent_scores?.[CAT]?.[row.dataset.parent]
                    ?? (scores.length ? scores.reduce((sum, value) => sum + Number(value), 0) : null);
                const val = parseFloat(score ?? 0);

                const ptsEl  = row.querySelector('.subcap-pts');
                const fillEl = row.querySelector('.subcap-fill');

                if (ptsEl) {
                    const label = score == null ? 'Not assessed' : val.toFixed(1);
                    ptsEl.textContent = row.dataset.cap !== '' ? `${label} / ${cap}` : label;
                    ptsEl.style.color = (cap && val >= cap) ? '#dc2626' : '#1a3a6b';
                }
                if (fillEl && cap) {
                    const pct = Math.min(100, Math.round((val / cap) * 100));
                    fillEl.style.width = pct + '%';
                    fillEl.style.background = pct >= 100 ? '#dc2626' : '#1e4d8c';
                } else if (fillEl) {
                    fillEl.style.width = Math.min(100, val) + '%';
                }
            });
        }
        // -- Gap-to-threshold note in sidebar ---------------
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
                gapEl.style.color = 'rgba(255,255,255,.45)';
            } else {
                gapEl.textContent = '';
            }
        }
    });
}

// -- Helpers ----------------------------------------------------
function esc(s){return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');}

// -- Evidence Guide Drawer --------------------------------------
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

// -- PDF Viewer Modal ------------------------------------------
function openPdfViewer(url, title) {
    document.getElementById('pdfViewerTitle').textContent = title;
    document.getElementById('pdfViewerFrame').src = url;
    document.getElementById('pdfViewerNewTab').href = url;
    var overlay = document.getElementById('pdfViewerOverlay');
    overlay.style.display = 'flex';
    document.body.style.overflow = 'hidden';
}
function closePdfViewer() {
    var overlay = document.getElementById('pdfViewerOverlay');
    overlay.style.display = 'none';
    document.getElementById('pdfViewerFrame').src = '';
    document.body.style.overflow = '';
}
document.getElementById('pdfViewerOverlay').addEventListener('click', function(e) {
    if (e.target === this) closePdfViewer();
});
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape' && document.getElementById('pdfViewerOverlay').style.display !== 'none') {
        closePdfViewer();
    }
});
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
        group.querySelectorAll('.crit-parent').forEach(parent => {
            const parentMatch = parent.dataset.label.includes(q);
            let parentVisible = false;
            parent.querySelectorAll('.crit-item').forEach(item => {
                const show = catMatch && (parentMatch || item.dataset.label.includes(q));
                item.style.display = show ? '' : 'none';
                if (show) parentVisible = true;
            });
            parent.hidden = !parentVisible;
            if (parentVisible) anyVisible = true;
        });
        group.style.display = (catMatch && anyVisible) ? '' : 'none';
    });
}

// -- Init -------------------------------------------------------
document.querySelectorAll('#addForm option[data-criteria]').forEach(option => {
    const key = option.dataset.criteria;
    if (!hasSelfAssessmentCriterion(key)) {
        option.remove();
        return;
    }
    const criterion = assessmentCatalog[key];
    if (criterion) {
        const legacyValue = option.value;
        option.textContent = assessmentPointLabel(option.textContent, key);
        option.value = legacyValue;
        option.dataset.parent = criterion.parent;
    }
});
initializeAssessmentParents();
<?php if ($cur_cat === 'Instruction'): ?>
document.getElementById('setV').disabled = !hasSelfAssessmentCriterion('kra1_a_set');
document.getElementById('sefV').disabled = !hasSelfAssessmentCriterion('kra1_a_sef');
<?php endif; ?>
if (document.getElementById('standardCriterionFields')) changeSelfAssessmentCriterion();
function selfAssessmentFormOpen(){
    return document.getElementById('addForm')?.style.display !== 'none';
}
function refreshSelfAssessment(){
    refreshScore();
    if(!selfAssessmentFormOpen())loadEntries();
}
refreshSelfAssessment();
setInterval(refreshSelfAssessment, 5000);

// -- In-page confirm modal --------------------------------------
function peConfirm(icon, color, title, msg, btnLabel, onConfirm) {
    const m = document.getElementById('peConfirmModal');
    document.getElementById('peConfirmIcon').className       = 'bi ' + icon;
    document.getElementById('peConfirmIcon').style.color     = '#fff';
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
    // Wire both cancel buttons
    ['peConfirmCancelBtn','peConfirmCancelBtn2'].forEach(id => {
        const btn = document.getElementById(id);
        if (btn) btn.addEventListener('click', () => { m.style.display = 'none'; document.body.style.overflow = ''; });
    });
    m.addEventListener('click', e => { if (e.target === m) { m.style.display = 'none'; document.body.style.overflow = ''; } });
});
</script>

<!-- -- In-page Confirm Modal ----------------------------------- -->
<div id="peConfirmModal"
     style="display:none;position:fixed;top:0;left:0;right:0;bottom:0;
            background:rgba(15,23,42,0.55);z-index:99999;overflow:hidden;
            align-items:center;justify-content:center;padding:1rem;">
    <div style="background:#fff;width:100%;max-width:360px;border:1px solid #e2e8f0;
                box-shadow:0 8px 32px rgba(0,0,0,0.18);overflow:hidden;">

        <!-- Header -->
        <div style="background:#1a3a6b;padding:.7rem 1rem;display:flex;align-items:center;justify-content:space-between;">
            <div style="display:flex;align-items:center;gap:.5rem;">
                <i id="peConfirmIcon" class="bi bi-trash" style="font-size:.85rem;color:#fff;"></i>
                <span id="peConfirmTitle" style="color:#fff;font-weight:700;font-size:.85rem;"></span>
            </div>
            <button id="peConfirmCancelBtn"
                    style="background:none;border:none;color:rgba(255,255,255,.6);font-size:1.1rem;cursor:pointer;line-height:1;padding:0;"
                    onmouseover="this.style.color='#fff'" onmouseout="this.style.color='rgba(255,255,255,.6)'">&times;</button>
        </div>

        <!-- Body -->
        <div style="padding:.9rem 1rem;">
            <div id="peConfirmMsg" style="font-size:.8rem;color:#475569;line-height:1.6;"></div>
        </div>

        <!-- Buttons -->
        <div style="padding:0 1rem .9rem;display:flex;gap:.5rem;justify-content:flex-end;">
            <button id="peConfirmCancelBtn2"
                    style="padding:.4rem 1rem;border:1px solid #e2e8f0;background:#fff;
                           color:#475569;font-weight:600;font-size:.78rem;cursor:pointer;"
                    onmouseover="this.style.background='#f8fafc'" onmouseout="this.style.background='#fff'">
                Cancel
            </button>
            <button id="peConfirmBtn"
                    style="padding:.4rem 1.1rem;border:none;color:#fff;
                           font-weight:700;font-size:.78rem;cursor:pointer;">
                Confirm
            </button>
        </div>
    </div>
</div>

</body>
</html>
