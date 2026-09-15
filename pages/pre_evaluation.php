<?php
/**
 * Pre-Evaluation — standalone page, no sidebar.
 */
if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/functions.php';

if (!isLoggedIn()) { header('Location: login.php'); exit; }
if (!in_array($_SESSION['role'] ?? '', ['faculty','checker_faculty'])) {
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
$entry_count_total  = (int)$pdo->prepare("SELECT COUNT(*) FROM pre_eval_entries WHERE user_id=?")->execute([$uid]) ? (int)$pdo->query("SELECT COUNT(*) FROM pre_eval_entries WHERE user_id={$uid}")->fetchColumn() : 0;
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
    'instruction' => ['label'=>'Instruction',        'cat'=>'Instruction',              'icon'=>'bi-book-half',    'max'=>100, 'color'=>'#1e4d8c', 'num'=>1],
    'research'    => ['label'=>'Research',            'cat'=>'Research',                 'icon'=>'bi-journal-text', 'max'=>100, 'color'=>'#1a3a6b', 'num'=>2],
    'extension'   => ['label'=>'Extension',           'cat'=>'Extension',                'icon'=>'bi-people-fill',  'max'=>100, 'color'=>'#1e4d8c', 'num'=>3],
    'profdev'     => ['label'=>'Prof. Development',   'cat'=>'Professional Development', 'icon'=>'bi-award-fill',   'max'=>100, 'color'=>'#475569', 'num'=>4],
];

$active_tab = $_GET['tab'] ?? 'instruction';
if (!array_key_exists($active_tab, $kra_tabs)) $active_tab = 'instruction';
$cur     = $kra_tabs[$active_tab];
$cur_cat = $cur['cat'];
$kra_num = $cur['num'];

// Tab entry counts (correct query)
$counts = [];
foreach ($kra_tabs as $slug => $t) {
    $s = $pdo->prepare("SELECT COUNT(*) FROM pre_eval_entries WHERE user_id=? AND kra_category=?");
    $s->execute([$uid, $t['cat']]);
    $counts[$slug] = (int)$s->fetchColumn();
}

// Evidence guide — load ALL criteria for all KRA categories
$all_criteria = [];
try {
    $cg = $pdo->query("
        SELECT kra_category, criterion_label, max_points, description
        FROM scoring_criteria
        WHERE cycle_id IS NULL AND position_rank IS NULL AND is_active = 1
        ORDER BY kra_category, max_points DESC, criterion_label ASC
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
<title>SUCFRMS — Pre-Evaluation</title>
<link href="../assets/vendor/bootstrap-icons/bootstrap-icons.min.css" rel="stylesheet">
<style>
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0;}
body{font-family:'Segoe UI',Arial,sans-serif;background:#f0f3f8;min-height:100vh;color:#1e293b;}

/* Navbar */
.pnav{background:#1a3a6b;height:48px;display:flex;align-items:center;padding:0 24px;gap:10px;position:sticky;top:0;z-index:200;}
.pnav-logo{width:28px;height:28px;border-radius:50%;object-fit:cover;border:1.5px solid rgba(201,168,76,.6);flex-shrink:0;}
.pnav-title{color:#fff;font-size:.85rem;font-weight:600;flex:1;}
.pnav-user{display:flex;align-items:center;gap:8px;cursor:pointer;position:relative;}
.pnav-name{color:rgba(255,255,255,.9);font-size:.8rem;}
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
.tabs{display:flex;gap:0;border-bottom:2px solid #e2e8f0;margin-bottom:1rem;}
.tab-btn{display:flex;align-items:center;gap:5px;padding:.55rem .9rem;font-size:.75rem;font-weight:600;color:#64748b;border:none;background:none;cursor:pointer;border-bottom:2px solid transparent;margin-bottom:-2px;white-space:nowrap;text-decoration:none;}
.tab-btn:hover{color:#1a3a6b;}
.tab-btn.active{color:#1a3a6b;border-bottom-color:#1a3a6b;}
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
        $rpts  = min($t['max'], $raw[$t['cat']] ?? 0);
        $pct   = $t['max'] > 0 ? min(100, round(($rpts/$t['max'])*100)) : 0;
        $wpts  = round($rpts * $weights[$t['cat']], 2);
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
        <div class="increment-badge">+<?= $sub_rank ?> sub-rank<?= $sub_rank>1?'s':'' ?></div>
        <?php elseif ($weighted > 0): ?>
        <div style="font-size:.6rem;color:#475569;margin-top:.3rem;font-weight:600;">Score below 41</div>
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
        <div style="font-weight:700;margin-bottom:.3rem;"><i class="bi bi-shield-x me-1"></i>Not eligible for pre-evaluation</div>
        <?php foreach ($eligibility_notes as $en): ?>
        <div style="margin-top:.2rem;">• <?= htmlspecialchars($en) ?></div>
        <?php endforeach; ?>
    </div>
    <?php else: ?>

    <?php endif; ?>

    <div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:.6rem;margin-bottom:1rem;">
        <div>
            <div style="font-size:.9rem;font-weight:700;color:#1a3a6b;">Pre-Evaluation</div>
            <div style="font-size:.68rem;color:#94a3b8;margin-top:2px;">Self-assess your KRA scores before a cycle opens.</div>
        </div>
        <div style="display:flex;gap:.4rem;flex-wrap:wrap;">
            <button class="btn-s" onclick="openDrawer()" style="font-size:.75rem;">
                Evidence Guide
            </button>
            <a href="evidence_repository.php" class="btn-s" style="text-decoration:none;font-size:.75rem;">
                Evidence Repository
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

        <!-- LEFT: entries -->
        <div class="panel">
            <div class="panel-hd">
                <div class="panel-hd-title"><?= $cur['label'] ?></div>
                <button class="btn-p" onclick="toggleForm()" id="addBtn"
                        <?= !$eligible ? 'disabled title="Not eligible — see notice above" style="opacity:.45;cursor:not-allowed;"' : '' ?>>
                    + Add Entry
                </button>
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
                    <div class="fg"><label class="fl">Activity / Program Name</label><input type="text" class="fi" id="extAct" placeholder="e.g. Community outreach program"></div>
                    <div class="fg2">
                        <div><label class="fl">Income Generated (₱)</label><input type="number" class="fi" id="extInc" value="0" min="0" oninput="previewScore()"></div>
                        <div><label class="fl">MOA / Linkage Count</label><input type="number" class="fi" id="extMoa" value="0" min="0" oninput="previewScore()"></div>
                    </div>
                    <div class="fg"><label class="fl">Outreach Activities Count</label><input type="number" class="fi" id="extOut" value="0" min="0" oninput="previewScore()"></div>

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

                <!-- Entry list -->
                <div class="entry-list" id="entryList">
                    <div class="empty-state"><i class="bi bi-inbox"></i><p>No entries yet. Click <strong>Add Entry</strong> to start.</p></div>
                </div>

                <input type="file" id="entryFileInput" style="display:none" accept=".pdf,.jpg,.jpeg,.png" onchange="attachFile(this)">
            </div>
        </div>

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
        return `${t}|||${ti}|||${c}`;
    }else if(CAT==='Extension'){
        const a=document.getElementById('extAct')?.value||'';
        const i=document.getElementById('extInc')?.value||'0';
        const m=document.getElementById('extMoa')?.value||'0';
        const o=document.getElementById('extOut')?.value||'0';
        return `${a}|||${i}|||${m}|||${o}`;
    }else{
        const t=document.getElementById('pdType')?.value||'A-org';
        const d=document.getElementById('pdDesc')?.value||'';
        const v=t==='A-org'?'5':(document.getElementById('pdSubval')?.value||'0');
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
    fetch(`${AJAX}?action=get_entries&cat=${encodeURIComponent(CAT)}`)
        .then(x=>x.json()).then(d=>{
            const el=document.getElementById('entryList');
            if(!d.ok||!d.entries.length){
                el.innerHTML='<div class="empty-state"><i class="bi bi-inbox"></i><p>No entries yet. Click <strong>Add Entry</strong> to start.</p></div>';
                return;
            }
            el.innerHTML=d.entries.map(e=>entryCard(e)).join('');
        });
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
        if(d.ok)loadEntries();
        else alert(d.error||'Upload failed.');
        input.value=''; attachTarget=null;
    });
}

// ── Delete entry / file ────────────────────────────────────────
function delEntry(eid){
    if(!confirm('Remove this entry and all its files?'))return;
    const fd=new FormData(); fd.append('action','delete_entry'); fd.append('entry_id',eid);
    fetch(AJAX,{method:'POST',body:fd}).then(x=>x.json()).then(d=>{if(d.ok){loadEntries();refreshScore();}});
}
function delFile(e,fid){
    e.preventDefault();e.stopPropagation();
    if(!confirm('Remove this file?'))return;
    const fd=new FormData(); fd.append('action','delete_file'); fd.append('file_id',fid);
    fetch(AJAX,{method:'POST',body:fd}).then(x=>x.json()).then(d=>{if(d.ok)loadEntries();});
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
<?php if($cur_cat==='Professional Development'): ?>updatePdOpts();<?php endif; ?>
loadEntries();
</script>
</body>
</html>
