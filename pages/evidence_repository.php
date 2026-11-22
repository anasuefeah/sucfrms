<?php
/**
 * Evidence Repository  -  standalone page.
 */
if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/functions.php';

if (!isLoggedIn()) { header('Location: login.php'); exit; }
if (($_SESSION['role'] ?? '') !== 'faculty') { header('Location: ../index.php'); exit; }
unset($_SESSION['force_pw_change']);

$uid = $_SESSION['user_id'];

try { $pdo->query("SELECT file_id FROM pre_eval_files LIMIT 1"); }
catch (\Exception $e) {
    $pdo->exec("CREATE TABLE IF NOT EXISTS pre_eval_files (file_id INT AUTO_INCREMENT PRIMARY KEY, user_id INT NOT NULL, entry_id INT DEFAULT NULL, kra_category ENUM('Instruction','Research','Extension','Professional Development','Auto Sub Rank','Position Requirements') NOT NULL, file_path VARCHAR(255) NOT NULL, original_filename VARCHAR(255) NOT NULL, file_size_bytes INT DEFAULT 0, description VARCHAR(255) DEFAULT NULL, uploaded_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE CASCADE)");
}
try {
    $col = $pdo->query("SHOW COLUMNS FROM pre_eval_files LIKE 'kra_category'")->fetch();
    if ($col && strpos($col['Type'], 'Auto Sub Rank') === false) {
        $pdo->exec("ALTER TABLE pre_eval_files MODIFY kra_category ENUM('Instruction','Research','Extension','Professional Development','Auto Sub Rank','Position Requirements') NOT NULL");
    }
} catch (\Exception $e) {}

$fac = $pdo->prepare("SELECT full_name, first_name, last_name, middle_name, profile_pic FROM users WHERE user_id=?");
$fac->execute([$uid]);
$fac         = $fac->fetch();
$full_name   = formatDisplayName($fac);
$profile_pic = $fac['profile_pic'] ?? '';
$init        = strtoupper(substr($fac['first_name']??'U',0,1).substr($fac['last_name']??'',0,1)) ?: 'FA';

$cats = ['Instruction','Research','Extension','Professional Development','Auto Sub Rank','Position Requirements'];
$stats = [];
foreach ($cats as $c) {
    $s = $pdo->prepare("SELECT COUNT(*) as cnt, COALESCE(SUM(file_size_bytes),0) as total_size FROM pre_eval_files WHERE user_id=? AND kra_category=?");
    $s->execute([$uid, $c]);
    $stats[$c] = $s->fetch();
}
$total_files = array_sum(array_column($stats, 'cnt'));
$total_size  = array_sum(array_column($stats, 'total_size'));

// ── Entry checklist ──────────────────────────────────────────
$ck_stmt = $pdo->prepare("
    SELECT pe.entry_id, pe.kra_category, pe.remarks, pe.computed_points,
           COUNT(pf.file_id) AS file_count
    FROM pre_eval_entries pe
    LEFT JOIN pre_eval_files pf ON pf.entry_id = pe.entry_id
    WHERE pe.user_id = ?
    GROUP BY pe.entry_id
    ORDER BY FIELD(pe.kra_category,'Instruction','Research','Extension','Professional Development'), pe.created_at ASC
");
$ck_stmt->execute([$uid]);
$ck_entries = $ck_stmt->fetchAll();
$ck_missing = array_values(array_filter($ck_entries, fn($e) => (int)$e['file_count'] === 0));
$ck_done    = array_values(array_filter($ck_entries, fn($e) => (int)$e['file_count'] > 0));
$ck_total   = count($ck_entries);
$ck_kra_short = ['Instruction'=>'KRA I','Research'=>'KRA II','Extension'=>'KRA III','Professional Development'=>'KRA IV'];

$cat_cfg = [
    'Instruction'              => ['label'=>'KRA I — Instruction',      'color'=>'#1e4d8c','bg'=>'#eff6ff'],
    'Research'                 => ['label'=>'KRA II — Research',         'color'=>'#1a3a6b','bg'=>'#f0f4fb'],
    'Extension'                => ['label'=>'KRA III — Extension',       'color'=>'#1e4d8c','bg'=>'#f0fdfa'],
    'Professional Development' => ['label'=>'KRA IV — Prof. Dev.',       'color'=>'#475569','bg'=>'#f8fafc'],
    'Auto Sub Rank'            => ['label'=>'Auto Sub Rank',             'color'=>'#1a3a6b','bg'=>'#f0f4fb'],
    'Position Requirements'    => ['label'=>'Position Requirements',     'color'=>'#475569','bg'=>'#f8fafc'],
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <link rel="stylesheet" href="../assets/css/square-corners.css">
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1.0">
<title>SUCFRMS — Evidence Repository</title>
<link href="../assets/vendor/bootstrap-icons/bootstrap-icons.min.css" rel="stylesheet">
<style>
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0;}
body{font-family:'Segoe UI',Arial,sans-serif;background:#edf0f5;min-height:100vh;color:#1e293b;}
body::before,body::after{display:none!important;content:none!important;}

/* ── Navbar ── */
.pnav{background:#0f2952;height:50px;display:flex;align-items:center;padding:0 24px;gap:10px;position:sticky;top:0;z-index:200;}
.pnav-logo{width:28px;height:28px;border-radius:50%;object-fit:cover;border:1.5px solid rgba(255,255,255,.3);flex-shrink:0;}
.pnav-title{color:#fff;font-size:.82rem;font-weight:600;flex:1;}
.pnav-user{display:flex;align-items:center;gap:8px;cursor:pointer;position:relative;}
.pnav-name{color:rgba(255,255,255,.85);font-size:.78rem;}
.pnav-avatar{width:28px;height:28px;border-radius:50%;object-fit:cover;border:2px solid rgba(255,255,255,.3);}
.pnav-avatar-ph{width:28px;height:28px;border-radius:50%;background:rgba(255,255,255,.15);border:2px solid rgba(255,255,255,.3);display:flex;align-items:center;justify-content:center;color:#fff;font-size:.62rem;font-weight:700;}
.pnav-dd{display:none;position:absolute;top:calc(100% + 8px);right:0;background:#fff;border:1px solid #e2e8f0;min-width:200px;box-shadow:0 8px 24px rgba(0,0,0,.12);z-index:500;}
.pnav-dd.open{display:block;}
.pnav-dd-header{background:#0f2952;padding:12px 14px;display:flex;align-items:center;gap:10px;}
.pnav-dd-avatar-ph{width:34px;height:34px;border-radius:50%;background:rgba(255,255,255,.2);border:2px solid rgba(255,255,255,.3);display:flex;align-items:center;justify-content:center;color:#fff;font-size:.68rem;font-weight:700;}
.pnav-dd-name{color:#fff;font-size:.8rem;font-weight:700;}
.pnav-dd-body a{display:flex;align-items:center;gap:10px;padding:9px 14px;font-size:.8rem;color:#1e293b;text-decoration:none;border-bottom:1px solid #f1f5f9;transition:background .1s;}
.pnav-dd-body a:last-child{border-bottom:none;}
.pnav-dd-body a:hover{background:#f8fafc;}
.pnav-dd-body a i{color:#1a3a6b;font-size:.82rem;width:16px;text-align:center;}
.pnav-dd-body a.out{color:#dc2626;}.pnav-dd-body a.out i{color:#dc2626;}

/* ── Page ── */
.pg{max-width:1200px;margin:0 auto;padding:1.25rem 1.5rem 3rem;}
.back-link{display:inline-flex;align-items:center;gap:5px;font-size:.78rem;color:#64748b;text-decoration:none;margin-bottom:1rem;}
.back-link:hover{color:#1a3a6b;}

/* ── Header ── */
.repo-header{background:#fff;border:1px solid #e2e8f0;padding:.85rem 1.25rem;display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:1rem;margin-bottom:1.1rem;}
.repo-header-left{display:flex;align-items:center;gap:.75rem;}
.repo-header-icon{width:36px;height:36px;background:#1a3a6b;display:flex;align-items:center;justify-content:center;flex-shrink:0;}
.repo-header h2{font-size:.92rem;font-weight:700;color:#0f172a;line-height:1.2;margin:0;}
.repo-header p{font-size:.72rem;color:#94a3b8;margin-top:2px;}

/* ── Buttons ── */
.btn-p{display:inline-flex;align-items:center;gap:6px;background:#1a3a6b;color:#fff;border:none;padding:.42rem .95rem;font-size:.79rem;font-weight:600;cursor:pointer;font-family:inherit;transition:background .15s;}
.btn-p:hover{background:#162f56;}
.btn-s{display:inline-flex;align-items:center;gap:5px;background:#fff;color:#475569;border:1px solid #e2e8f0;padding:.38rem .8rem;font-size:.78rem;font-weight:600;cursor:pointer;font-family:inherit;transition:background .15s;}
.btn-s:hover{background:#f1f5f9;}

/* ── Layout ── */
.repo-layout{display:grid;grid-template-columns:210px 1fr;gap:1.1rem;align-items:start;}

/* ── Sidebar ── */
.repo-sidebar{background:#fff;border:1px solid #e2e8f0;}
.sidebar-head{padding:.55rem .85rem;border-bottom:1px solid #e2e8f0;font-size:.58rem;font-weight:700;text-transform:uppercase;letter-spacing:.09em;color:#94a3b8;background:#f8fafc;}
.cat-item{display:flex;align-items:center;justify-content:space-between;padding:.58rem .85rem;border-bottom:1px solid #f1f5f9;cursor:pointer;font-size:.79rem;font-weight:600;color:#475569;user-select:none;transition:all .12s;}
.cat-item:last-of-type{border-bottom:none;}
.cat-item:hover{background:#f8fafc;color:#1a3a6b;}
.cat-item.active{background:#eff6ff;color:#1a3a6b;border-left:3px solid #1a3a6b;padding-left:calc(.85rem - 3px);}
.cat-item.active .cat-cnt{background:#1a3a6b;color:#fff;}
.cat-cnt{display:inline-flex;align-items:center;justify-content:center;min-width:20px;height:20px;padding:0 5px;font-size:.6rem;font-weight:700;background:#f1f5f9;color:#64748b;border-radius:50%;}
.sidebar-storage{padding:.65rem .85rem;border-top:1px solid #e2e8f0;background:#f8fafc;}
.storage-bar-wrap{height:4px;background:#e2e8f0;margin:.35rem 0 .3rem;}
.storage-bar-fill{height:100%;background:#1a3a6b;}
.storage-label{font-size:.63rem;color:#94a3b8;}

/* ── Main ── */
.repo-main{display:flex;flex-direction:column;gap:.85rem;}

/* ── Upload panel ── */
.upload-panel{display:none;background:#fff;border:1px solid #e2e8f0;padding:1.1rem 1.25rem;}
.upload-panel.show{display:block;}
.uf-row{display:grid;grid-template-columns:1fr 1fr;gap:.85rem;margin-bottom:.85rem;}
.fl{display:block;font-size:.74rem;font-weight:600;color:#334155;margin-bottom:3px;}
.fi{width:100%;padding:.4rem .65rem;border:1px solid #e2e8f0;font-size:.81rem;outline:none;background:#fff;font-family:inherit;}
.fi:focus{border-color:#1a3a6b;}
.drop-zone{border:2px dashed #e2e8f0;padding:1.75rem;text-align:center;cursor:pointer;background:#f8fafc;transition:all .2s;}
.drop-zone:hover,.drop-zone.over{border-color:#1a3a6b;background:#eff6ff;}
.drop-zone i{font-size:1.5rem;color:#94a3b8;display:block;margin-bottom:5px;}
.drop-zone p{font-size:.81rem;color:#475569;margin:0 0 2px;}
.drop-zone small{font-size:.7rem;color:#94a3b8;}
@keyframes cloudBounce{0%,100%{transform:translateY(0);}50%{transform:translateY(-6px);}}
@keyframes shimmer{0%{background-position:-200% 0;}100%{background-position:200% 0;}}
.upload-anim{display:none;flex-direction:column;align-items:center;padding:1.25rem;gap:7px;}
.upload-anim.show{display:flex;}
.upload-anim-icon{font-size:1.85rem;color:#1a3a6b;animation:cloudBounce 1s ease-in-out infinite;}
.upload-anim-label{font-size:.81rem;font-weight:600;color:#334155;}
.upload-anim-sub{font-size:.7rem;color:#94a3b8;}
.prog-wrap{width:100%;max-width:300px;}
.prog-bar2{height:4px;background:#e2e8f0;}
.prog-fill2{height:100%;width:0%;transition:width .3s;background:linear-gradient(90deg,#1a3a6b,#2f6fb0,#1a3a6b);background-size:200% 100%;animation:shimmer 1.5s infinite linear;}
.prog-count{font-size:.67rem;color:#94a3b8;text-align:right;margin-top:3px;}

/* ── Toolbar ── */
.toolbar{display:flex;align-items:center;gap:.6rem;flex-wrap:wrap;}
.search-box{display:flex;align-items:center;gap:6px;background:#fff;border:1px solid #e2e8f0;padding:.36rem .7rem;flex:1;min-width:180px;}
.search-box:focus-within{border-color:#1a3a6b;}
.search-box i{color:#94a3b8;font-size:.82rem;flex-shrink:0;}
.search-box input{border:none;outline:none;font-size:.81rem;width:100%;background:transparent;}
.sort-sel{padding:.36rem .65rem;border:1px solid #e2e8f0;font-size:.79rem;background:#fff;color:#475569;cursor:pointer;outline:none;font-family:inherit;}
.sort-sel:focus{border-color:#1a3a6b;}
.view-btns{display:flex;border:1px solid #e2e8f0;}
.view-btn{background:#fff;border:none;padding:.36rem .58rem;cursor:pointer;color:#94a3b8;font-size:.88rem;}
.view-btn.active{background:#1a3a6b;color:#fff;}

/* ── List view (default) ── */
.list-view{display:flex;flex-direction:column;gap:1px;background:#e2e8f0;border:1px solid #e2e8f0;}
.file-card-list{display:flex;align-items:center;gap:10px;background:#fff;padding:.65rem 1rem;transition:background .1s;}
.file-card-list:hover{background:#f8fafc;}
.fl-icon{font-size:1.1rem;flex-shrink:0;width:26px;text-align:center;line-height:1;}
.fl-info{flex:1;min-width:0;}
.fl-name{font-size:.82rem;font-weight:600;color:#1a3a6b;text-decoration:none;display:block;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;}
.fl-name:hover{text-decoration:underline;}
.fl-meta{font-size:.68rem;color:#94a3b8;margin-top:1px;}
.fl-cat{display:inline-block;padding:1px 7px;font-size:.62rem;font-weight:700;border:1px solid;white-space:nowrap;flex-shrink:0;}
.fl-actions{display:flex;gap:3px;align-items:center;flex-shrink:0;}
.fl-act-btn{background:none;border:1px solid #e2e8f0;padding:2px 7px;font-size:.67rem;color:#64748b;cursor:pointer;display:inline-flex;align-items:center;gap:3px;transition:all .15s;font-family:inherit;}
.fl-act-btn:hover{background:#f0f4fb;border-color:#1a3a6b;color:#1a3a6b;}
.fl-act-btn.danger:hover{background:#fef2f2;border-color:#fecaca;color:#dc2626;}
.fl-rename-bar{display:none;gap:3px;align-items:center;margin-top:4px;}
.fl-rename-bar.show{display:flex;}
.fl-rename-in{flex:1;padding:3px 6px;border:1px solid #1a3a6b;font-size:.76rem;outline:none;min-width:0;font-family:inherit;}
.fl-rename-ok{background:#1a3a6b;color:#fff;border:none;padding:3px 8px;font-size:.7rem;cursor:pointer;font-family:inherit;}
.fl-move-sel{padding:2px 6px;border:1px solid #e2e8f0;font-size:.7rem;color:#475569;cursor:pointer;outline:none;background:#fff;font-family:inherit;}
.fl-move-sel:focus{border-color:#1a3a6b;}

/* ── Grid view ── */
.grid-view{display:grid;grid-template-columns:repeat(auto-fill,minmax(165px,1fr));gap:.85rem;}
.file-card-grid{background:#fff;border:1px solid #e2e8f0;padding:.9rem .85rem .75rem;display:flex;flex-direction:column;gap:5px;position:relative;transition:box-shadow .15s;}
.file-card-grid:hover{box-shadow:0 3px 12px rgba(0,0,0,.09);}
.fc-open{text-decoration:none;display:contents;}
.fc-icon{font-size:1.75rem;line-height:1;margin-bottom:2px;}
.fc-name{font-size:.77rem;font-weight:600;color:#1e293b;word-break:break-all;line-height:1.35;}
.fc-cat-badge{display:inline-block;font-size:.6rem;font-weight:700;padding:1px 6px;border:1px solid;width:fit-content;}
.fc-meta{font-size:.63rem;color:#94a3b8;}
.fc-actions{display:flex;gap:3px;margin-top:3px;flex-wrap:wrap;}
.fc-act-btn{background:none;border:1px solid #e2e8f0;padding:2px 6px;font-size:.62rem;color:#64748b;cursor:pointer;display:inline-flex;align-items:center;gap:2px;transition:all .15s;font-family:inherit;}
.fc-act-btn:hover{background:#f0f4fb;border-color:#1a3a6b;color:#1a3a6b;}
.fc-act-btn.danger:hover{background:#fef2f2;border-color:#fecaca;color:#dc2626;}
.fc-rename-wrap{display:none;margin-top:3px;}
.fc-rename-wrap.show{display:flex;gap:3px;align-items:center;}
.fc-rename-in{flex:1;padding:3px 6px;border:1px solid #1a3a6b;font-size:.76rem;outline:none;min-width:0;font-family:inherit;}
.fc-rename-ok{background:#1a3a6b;color:#fff;border:none;padding:3px 8px;font-size:.7rem;cursor:pointer;font-family:inherit;}
.fc-move-sel{padding:2px 6px;border:1px solid #e2e8f0;font-size:.68rem;color:#475569;cursor:pointer;outline:none;background:#fff;margin-top:3px;width:100%;font-family:inherit;}
.fc-move-sel:focus{border-color:#1a3a6b;}

/* ── Status bar ── */
.status-bar{display:flex;justify-content:space-between;align-items:center;padding:.45rem .1rem;font-size:.7rem;color:#94a3b8;border-top:1px solid #e2e8f0;}

/* ── Empty state ── */
.empty-state{text-align:center;padding:3rem 1.5rem;color:#94a3b8;background:#fff;border:1px solid #e2e8f0;}
.empty-state i{font-size:2.25rem;display:block;margin-bottom:8px;color:#dbeafe;}
.empty-state h3{font-size:.87rem;font-weight:600;color:#475569;margin-bottom:3px;}
.empty-state p{font-size:.77rem;}

@keyframes fadeInCard{from{opacity:0;transform:translateY(3px);}to{opacity:1;transform:translateY(0);}}
.file-card-grid,.file-card-list{animation:fadeInCard .12s ease both;}
@media(max-width:700px){.repo-layout{grid-template-columns:1fr;}.uf-row{grid-template-columns:1fr;}.pnav-name{display:none;}}
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
            <div class="pnav-dd-header">
                <div class="pnav-dd-avatar-ph"><?= $init ?></div>
                <div><div class="pnav-dd-name"><?= sanitize($full_name) ?></div></div>
            </div>
            <div class="pnav-dd-body">
                <a href="../index.php?page=profile"><i class="bi bi-person-circle"></i>My Profile</a>
                <a href="self_assessment.php"><i class="bi bi-clipboard2-check"></i>Self-Assessment</a>
                <a href="portal.php"><i class="bi bi-grid-1x2"></i>Portal</a>
                <a href="logout.php" class="out"><i class="bi bi-box-arrow-right"></i>Sign Out</a>
            </div>
        </div>
    </div>
</nav>

<div class="pg">
    <a href="self_assessment.php" class="back-link"><i class="bi bi-arrow-left"></i> Back to Self-Assessment</a>

    <!-- Header -->
    <div class="repo-header">
        <div class="repo-header-left">
            <div class="repo-header-icon">
                <i class="bi bi-folder2-open" style="color:#fff;font-size:.9rem;"></i>
            </div>
            <div>
                <h2>Evidence Repository</h2>
                <p><?= $total_files ?> file<?= $total_files!=1?'s':'' ?> &nbsp;·&nbsp; <?= $total_size>1048576?number_format($total_size/1048576,1).'MB':round($total_size/1024).'KB' ?> used</p>
            </div>
        </div>
        <div style="display:flex;align-items:center;gap:.5rem;flex-shrink:0;">
            <button class="btn-s" onclick="document.getElementById('ckModal').style.display='flex'"
                    style="font-size:.75rem;font-weight:600;display:inline-flex;align-items:center;gap:4px;">
                <i class="bi bi-list-check"></i> Evidence Checklist
            </button>
            <button class="btn-p" onclick="toggleUpload()">
                <i class="bi bi-cloud-arrow-up"></i> Upload File
            </button>
        </div>
    </div>

    <!-- Upload panel -->
    <div class="upload-panel" id="uploadPanel">
        <div style="font-size:.79rem;font-weight:700;color:#1a3a6b;margin-bottom:.75rem;padding-bottom:.5rem;border-bottom:1px solid #e2e8f0;">Upload Evidence File</div>
        <div class="uf-row">
            <div>
                <label class="fl">KRA Category <span style="color:#dc2626;">*</span></label>
                <select class="fi" id="upCat">
                    <?php foreach ($cat_cfg as $cat => $cfg): ?>
                    <option value="<?= htmlspecialchars($cat) ?>"><?= $cfg['label'] ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label class="fl">Description (optional)</label>
                <input type="text" class="fi" id="upDesc" placeholder="e.g. Certificate of Participation 2025">
            </div>
        </div>
        <div id="dropZoneWrap">
            <div class="drop-zone" id="dropZone"
                 onclick="document.getElementById('fileInput').click()"
                 ondragover="event.preventDefault();this.classList.add('over')"
                 ondragleave="this.classList.remove('over')"
                 ondrop="handleDrop(event)">
                <i class="bi bi-cloud-arrow-up"></i>
                <p>Click or drag &amp; drop files here</p>
                <small>PDF only — max 50MB each</small>
            </div>
        </div>
        <input type="file" id="fileInput" style="display:none" accept=".pdf,application/pdf" multiple onchange="uploadFiles(this.files)">
        <div class="upload-anim" id="uploadAnim">
            <i class="bi bi-cloud-arrow-up upload-anim-icon"></i>
            <div class="upload-anim-label" id="uploadAnimLabel">Preparing…</div>
            <div class="upload-anim-sub" id="uploadAnimSub"></div>
            <div class="prog-wrap">
                <div class="prog-bar2"><div class="prog-fill2" id="progFill"></div></div>
                <div class="prog-count" id="progCount"></div>
            </div>
        </div>
        <div style="display:flex;gap:.5rem;justify-content:flex-end;margin-top:.65rem;">
            <button class="btn-s" onclick="toggleUpload()">Cancel</button>
        </div>
    </div>

    <!-- Two-column layout -->
    <div class="repo-layout">

        <!-- Sidebar -->
        <div class="repo-sidebar">
            <div class="sidebar-head">Filter by Category</div>
            <div class="cat-item active" data-cat="" onclick="filterByCat(this,'')">
                <span>All Files</span>
                <span class="cat-cnt" id="cnt-all"><?= $total_files ?></span>
            </div>
            <?php foreach ($cat_cfg as $cat => $cfg): ?>
            <div class="cat-item" data-cat="<?= htmlspecialchars($cat) ?>"
                 onclick="filterByCat(this,'<?= addslashes($cat) ?>')">
                <span><?= $cfg['label'] ?></span>
                <span class="cat-cnt" id="cnt-<?= htmlspecialchars(preg_replace('/\W+/','_',$cat)) ?>"><?= $stats[$cat]['cnt'] ?></span>
            </div>
            <?php endforeach; ?>
            <div class="sidebar-storage">
                <div style="display:flex;align-items:baseline;justify-content:space-between;">
                    <div class="storage-label"><?= $total_size>1048576?number_format($total_size/1048576,1).'MB':round($total_size/1024).'KB' ?> used</div>
                    <div class="storage-label" style="color:#cbd5e1;">of 500MB</div>
                </div>
                <?php $pct = min(100, round($total_size / (500*1024*1024) * 100)); ?>
                <div class="storage-bar-wrap"><div class="storage-bar-fill" style="width:<?= $pct ?>%;"></div></div>
                <div class="storage-label"><?= $total_files ?> file<?= $total_files!=1?'s':'' ?> stored</div>
            </div>
        </div>

        <!-- Main content -->
        <div class="repo-main">
            <div class="toolbar">
                <div class="search-box">
                    <i class="bi bi-search"></i>
                    <input type="text" placeholder="Search by filename or description..." oninput="doSearch(this.value)">
                </div>
                <select class="sort-sel" onchange="doSort(this.value)">
                    <option value="date_desc">Newest first</option>
                    <option value="date_asc">Oldest first</option>
                    <option value="name_asc">Name A–Z</option>
                    <option value="name_desc">Name Z–A</option>
                    <option value="size_desc">Largest first</option>
                </select>
                <div class="view-btns">
                    <button class="view-btn" id="gridBtn" onclick="setView('grid')" title="Grid"><i class="bi bi-grid"></i></button>
                    <button class="view-btn active" id="listBtn" onclick="setView('list')" title="List"><i class="bi bi-list-ul"></i></button>
                </div>
            </div>

            <div id="fileGrid" class="list-view"></div>

            <div class="status-bar">
                <span id="statusLeft"></span>
                <span id="statusRight"><?= $total_files ?> total</span>
            </div>
        </div>
    </div>
</div>

<script>
const AJAX = 'self_assessment_ajax.php';
let allFiles  = [];
let filterCat = '';
let searchQ   = '';
let sortMode  = 'date_desc';
let viewMode  = 'list';

const CAT_LABELS = {
    'Instruction':'KRA I','Research':'KRA II','Extension':'KRA III',
    'Professional Development':'KRA IV','Auto Sub Rank':'Auto Sub Rank',
    'Position Requirements':'Pos. Requirements'
};
const CAT_FULL = {
    'Instruction':'KRA I – Instruction','Research':'KRA II – Research',
    'Extension':'KRA III – Extension','Professional Development':'KRA IV – Prof. Development',
    'Auto Sub Rank':'Auto Sub Rank','Position Requirements':'Position Requirements'
};
const CAT_COLORS = {
    'Instruction':'#1e4d8c','Research':'#1a3a6b','Extension':'#1e4d8c',
    'Professional Development':'#475569','Auto Sub Rank':'#1a3a6b','Position Requirements':'#475569'
};
const CAT_BG = {
    'Instruction':'#eff6ff','Research':'#f0f4fb','Extension':'#f0fdfa',
    'Professional Development':'#f8fafc','Auto Sub Rank':'#f0f4fb','Position Requirements':'#f8fafc'
};
const ALL_CATS = Object.keys(CAT_LABELS);

function toggleDD(){document.getElementById('ndd').classList.toggle('open');}
document.addEventListener('click',e=>{
    const n=document.getElementById('nu'),d=document.getElementById('ndd');
    if(d&&n&&!n.contains(e.target))d.classList.remove('open');
});

function toggleUpload(){
    const p=document.getElementById('uploadPanel');
    p.classList.toggle('show');
    if(p.classList.contains('show')) resetUploadUI();
}
function resetUploadUI(){
    document.getElementById('dropZoneWrap').style.display='';
    document.getElementById('uploadAnim').classList.remove('show');
    document.getElementById('progFill').style.width='0%';
    document.getElementById('uploadAnimLabel').textContent='Preparing…';
    document.getElementById('uploadAnimSub').textContent='';
    document.getElementById('progCount').textContent='';
    document.getElementById('fileInput').value='';
}
function handleDrop(e){
    e.preventDefault();
    document.getElementById('dropZone').classList.remove('over');
    if(e.dataTransfer.files.length) uploadFiles(e.dataTransfer.files);
}
async function uploadFiles(files){
    if (Array.from(files).some(file => !/\.pdf$/i.test(file.name))) {
        showToast('Only PDF documents are accepted.', 'error');
        document.getElementById('fileInput').value = '';
        return;
    }
    const cat=document.getElementById('upCat').value;
    const desc=document.getElementById('upDesc').value.trim();
    const anim=document.getElementById('uploadAnim');
    const label=document.getElementById('uploadAnimLabel');
    const sub=document.getElementById('uploadAnimSub');
    const fill=document.getElementById('progFill');
    const count=document.getElementById('progCount');
    document.getElementById('dropZoneWrap').style.display='none';
    anim.classList.add('show');
    const batch=Array.from(files);
    let done=0,errors=[];
    for(const file of batch){
        label.textContent=file.name;
        sub.textContent=`Uploading ${done+1} of ${batch.length}…`;
        count.textContent=`${done}/${batch.length}`;
        const fd=new FormData();
        fd.append('action','upload_repo');fd.append('cat',cat);
        fd.append('file',file);fd.append('description',desc);
        try{const res=await fetch(AJAX,{method:'POST',body:fd});const d=await res.json();if(!d.ok)errors.push(file.name+': '+(d.error||'Upload failed'));}
        catch(e){errors.push(file.name+': network error');}
        done++;fill.style.width=Math.round((done/batch.length)*100)+'%';
    }
    label.textContent=done===batch.length&&!errors.length?`✓ ${done} file${done!==1?'s':''} uploaded`:`${done-errors.length} of ${done} uploaded`;
    sub.textContent=errors.length?errors[0]:'Upload complete';
    count.textContent=`${done}/${batch.length}`;
    await new Promise(r=>setTimeout(r,errors.length?2500:1200));
    document.getElementById('uploadPanel').classList.remove('show');
    document.getElementById('upDesc').value='';
    resetUploadUI();
    if(errors.length) showToast('Some uploads failed:\n'+errors.join('\n'),'error');
    loadFiles();
}

function filterByCat(el,cat){
    document.querySelectorAll('.cat-item').forEach(c=>c.classList.remove('active'));
    filterCat=filterCat===cat?'':cat;
    if(!filterCat) document.querySelector('.cat-item[data-cat=""]').classList.add('active');
    else el.classList.add('active');
    render();
}
function doSearch(q){searchQ=q.toLowerCase();render();}
function doSort(m){sortMode=m;render();}
function setView(v){
    viewMode=v;
    document.getElementById('fileGrid').className=v+'-view';
    document.getElementById('gridBtn').classList.toggle('active',v==='grid');
    document.getElementById('listBtn').classList.toggle('active',v==='list');
    render();
}
function loadFiles(){
    fetch(`${AJAX}?action=get_all_files`).then(x=>x.json()).then(d=>{
        allFiles=d.ok?d.files:[];render();
    });
}
function render(){
    let files=[...allFiles];
    if(filterCat) files=files.filter(f=>f.kra_category===filterCat);
    if(searchQ)   files=files.filter(f=>f.original_filename.toLowerCase().includes(searchQ)||(f.description||'').toLowerCase().includes(searchQ));
    files.sort((a,b)=>{
        if(sortMode==='date_desc') return new Date(b.uploaded_at)-new Date(a.uploaded_at);
        if(sortMode==='date_asc')  return new Date(a.uploaded_at)-new Date(b.uploaded_at);
        if(sortMode==='name_asc')  return a.original_filename.localeCompare(b.original_filename);
        if(sortMode==='name_desc') return b.original_filename.localeCompare(a.original_filename);
        if(sortMode==='size_desc') return b.file_size_bytes-a.file_size_bytes;
        return 0;
    });
    const grid=document.getElementById('fileGrid');
    document.getElementById('statusLeft').textContent=files.length+' file'+(files.length!==1?'s':'')+(filterCat?' — '+CAT_LABELS[filterCat]:'');
    document.getElementById('statusRight').textContent=allFiles.length+' total';
    if(!files.length){
        grid.innerHTML=`<div class="empty-state"><i class="bi bi-folder"></i><h3>${allFiles.length?'No files match your filter':'No files yet'}</h3><p>${allFiles.length?'Try a different filter or search term.':'Click Upload File to add your first evidence.'}</p></div>`;
        return;
    }
    grid.innerHTML=files.map(f=>(viewMode==='grid'?gridCard(f):listRow(f))).join('');
}

function moveOpts(currentCat){
    return ALL_CATS.map(c=>`<option value="${esc(c)}" ${c===currentCat?'selected':''}>${esc(CAT_FULL[c]||c)}</option>`).join('');
}

function gridCard(f){
    const ic=fileIcon(f.original_filename),cc=CAT_COLORS[f.kra_category]||'#94a3b8',cbg=CAT_BG[f.kra_category]||'#f8fafc';
    const sz=fmtSize(f.file_size_bytes),cl=CAT_LABELS[f.kra_category]||f.kra_category;
    const d=f.uploaded_at?new Date(f.uploaded_at).toLocaleDateString('en-PH',{month:'short',day:'numeric',year:'numeric'}):'';
    const fid=f.file_id;
    return `<div class="file-card-grid" id="fc-${fid}">
        <a href="../${esc(f.file_path)}" target="_blank" class="fc-open">
            <div class="fc-icon" style="color:${cc}">${ic}</div>
            <div class="fc-name" id="fcname-${fid}" title="${esc(f.original_filename)}">${esc(truncate(f.original_filename,26))}</div>
        </a>
        <span class="fc-cat-badge" id="fccatbadge-${fid}" style="color:${cc};background:${cbg};border-color:${cc}44;">${cl}</span>
        <div class="fc-meta">${sz} | ${d}</div>
        ${f.description?`<div class="fc-meta" style="font-style:italic;">${esc(f.description)}</div>`:''}
        <div class="fc-actions">
            <button class="fc-act-btn" onclick="startRename('grid',${fid},'${esc(f.original_filename).replace(/'/g,"\\'")}')"><i class="bi bi-pencil"></i> Rename</button>
            <button class="fc-act-btn danger" onclick="delFile(${fid})"><i class="bi bi-trash"></i></button>
        </div>
        <div class="fc-rename-wrap" id="fcren-${fid}">
            <input class="fc-rename-in" id="fcren-in-${fid}" type="text" onkeydown="if(event.key==='Enter')saveRename('grid',${fid});if(event.key==='Escape')cancelRename('grid',${fid})">
            <button class="fc-rename-ok" onclick="saveRename('grid',${fid})"><i class="bi bi-check"></i></button>
            <button class="fc-rename-ok" style="background:#94a3b8;" onclick="cancelRename('grid',${fid})"><i class="bi bi-x"></i></button>
        </div>
        <select class="fc-move-sel" id="fcmove-${fid}" onchange="moveFile(${fid},this.value,'grid')">${moveOpts(f.kra_category)}</select>
    </div>`;
}

function listRow(f){
    const ic=fileIcon(f.original_filename),cc=CAT_COLORS[f.kra_category]||'#94a3b8',cbg=CAT_BG[f.kra_category]||'#f8fafc';
    const sz=fmtSize(f.file_size_bytes),cl=CAT_LABELS[f.kra_category]||f.kra_category;
    const d=f.uploaded_at?new Date(f.uploaded_at).toLocaleDateString('en-PH',{month:'short',day:'numeric',year:'numeric'}):'';
    const fid=f.file_id;
    return `<div class="file-card-list" id="fc-${fid}">
        <div class="fl-icon" style="color:${cc}">${ic}</div>
        <div class="fl-info">
            <a href="../${esc(f.file_path)}" target="_blank" class="fl-name" id="fcname-${fid}" title="${esc(f.original_filename)}">${esc(f.original_filename)}</a>
            <div class="fl-meta">${sz} &nbsp;·&nbsp; ${d}${f.description?' &nbsp;·&nbsp; <em>'+esc(f.description)+'</em>':''}</div>
            <div class="fl-rename-bar" id="fcren-${fid}">
                <input class="fl-rename-in" id="fcren-in-${fid}" type="text" onkeydown="if(event.key==='Enter')saveRename('list',${fid});if(event.key==='Escape')cancelRename('list',${fid})">
                <button class="fl-rename-ok" onclick="saveRename('list',${fid})"><i class="bi bi-check"></i></button>
                <button class="fl-rename-ok" style="background:#94a3b8;" onclick="cancelRename('list',${fid})"><i class="bi bi-x"></i></button>
            </div>
        </div>
        <span class="fl-cat" id="fccatbadge-${fid}" style="color:${cc};background:${cbg};border-color:${cc}44;">${cl}</span>
        <div class="fl-actions">
            <button class="fl-act-btn" onclick="startRename('list',${fid},'${esc(f.original_filename).replace(/'/g,"\\'")}')"><i class="bi bi-pencil"></i> Rename</button>
            <select class="fl-move-sel" id="fcmove-${fid}" onchange="moveFile(${fid},this.value,'list')">${moveOpts(f.kra_category)}</select>
            <button class="fl-act-btn danger" onclick="delFile(${fid})"><i class="bi bi-trash"></i></button>
        </div>
    </div>`;
}

function startRename(mode,fid,currentName){
    const wrap=document.getElementById('fcren-'+fid),inp=document.getElementById('fcren-in-'+fid);
    if(!wrap||!inp)return;
    wrap.classList.add('show');inp.value=currentName;inp.focus();inp.select();
}
function cancelRename(mode,fid){const w=document.getElementById('fcren-'+fid);if(w)w.classList.remove('show');}
function saveRename(mode,fid){
    const inp=document.getElementById('fcren-in-'+fid),name=inp?inp.value.trim():'';
    if(!name){cancelRename(mode,fid);return;}
    const fd=new FormData();fd.append('action','rename_file');fd.append('file_id',fid);fd.append('new_name',name);
    fetch(AJAX,{method:'POST',body:fd}).then(x=>x.json()).then(d=>{
        if(d.ok){
            const el=document.getElementById('fcname-'+fid);
            if(el){el.textContent=el.tagName==='A'?d.original_filename:truncate(d.original_filename,26);el.title=d.original_filename;}
            cancelRename(mode,fid);
            const f=allFiles.find(x=>x.file_id==fid);if(f)f.original_filename=d.original_filename;
        }else showToast(d.error||'Rename failed.','error');
    });
}
function moveFile(fid,newCat,mode){
    const fd=new FormData();fd.append('action','move_file');fd.append('file_id',fid);fd.append('new_cat',newCat);
    fetch(AJAX,{method:'POST',body:fd}).then(x=>x.json()).then(d=>{
        if(d.ok){
            const badge=document.getElementById('fccatbadge-'+fid);
            const cc=CAT_COLORS[newCat]||'#94a3b8',cbg=CAT_BG[newCat]||'#f8fafc';
            if(badge){badge.textContent=CAT_LABELS[newCat]||newCat;badge.style.color=cc;badge.style.background=cbg;badge.style.borderColor=cc+'44';}
            const card=document.getElementById('fc-'+fid);if(card){const ic=card.querySelector('.fc-icon,.fl-icon');if(ic)ic.style.color=cc;}
            const f=allFiles.find(x=>x.file_id==fid);if(f)f.kra_category=newCat;
            if(filterCat&&filterCat!==newCat)render();
        }else{showToast(d.error||'Move failed.','error');loadFiles();}
    });
}
function delFile(fid){confirmAction('Delete this file permanently? This cannot be undone.',()=>{doDeleteFile(fid);},'Delete','bi-trash');}
function doDeleteFile(fid){
    const fd=new FormData();fd.append('action','delete_file');fd.append('file_id',fid);
    fetch(AJAX,{method:'POST',body:fd}).then(x=>x.json()).then(d=>{
        if(d.ok)loadFiles();else showToast(d.error||'Delete failed.','error');
    });
}
function fileIcon(name){
    const ext=name.split('.').pop().toLowerCase();
    if(ext==='pdf') return '<i class="bi bi-file-earmark-pdf"></i>';
    if(ext.match(/jpe?g|png/)) return '<i class="bi bi-file-earmark-image"></i>';
    if(ext.match(/docx?/)) return '<i class="bi bi-file-earmark-word"></i>';
    return '<i class="bi bi-file-earmark"></i>';
}
function fmtSize(b){return b>1048576?(b/1048576).toFixed(1)+'MB':Math.round(b/1024)+'KB';}
function truncate(s,n){return s.length>n?s.slice(0,n)+'…':s;}
function esc(s){return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');}

loadFiles();
</script>
<?php renderConfirmModal(); ?>

<!-- ── Evidence Checklist Modal ── -->
<div id="ckModal" style="display:none;position:fixed;inset:0;background:rgba(15,23,42,.55);
     z-index:9999;align-items:flex-start;justify-content:center;padding:2rem 1rem;overflow-y:auto;">
    <div style="background:#fff;width:100%;max-width:600px;border:1px solid #e2e8f0;
                box-shadow:0 16px 48px rgba(0,0,0,.2);margin:auto;">

        <!-- Header -->
        <div style="background:#1a3a6b;padding:.75rem 1.1rem;display:flex;align-items:center;justify-content:space-between;">
            <div>
                <div style="color:#fff;font-weight:700;font-size:.88rem;"><i class="bi bi-list-check" style="margin-right:.4rem;"></i>Evidence Checklist</div>
                <div style="color:rgba(255,255,255,.55);font-size:.65rem;margin-top:2px;">Based on your self-assessment entries. Check off what you have prepared.</div>
            </div>
            <button onclick="document.getElementById('ckModal').style.display='none'"
                    style="background:none;border:none;color:rgba(255,255,255,.7);font-size:1.2rem;cursor:pointer;line-height:1;"
                    onmouseover="this.style.color='#fff'" onmouseout="this.style.color='rgba(255,255,255,.7)'">&times;</button>
        </div>

        <!-- Body -->
        <div style="padding:.75rem 1.1rem;max-height:70vh;overflow-y:auto;">
            <?php if (empty($ck_entries)): ?>
            <div style="text-align:center;padding:2rem;color:#94a3b8;font-size:.82rem;">
                <i class="bi bi-inbox" style="font-size:1.5rem;display:block;margin-bottom:.4rem;color:#e2e8f0;"></i>
                No self-assessment entries found.<br>
                <a href="self_assessment.php" style="color:#1a3a6b;font-weight:600;">Add entries in Self-Assessment →</a>
            </div>
            <?php else:
                // Group by KRA category
                $ck_by_kra = [];
                foreach ($ck_entries as $e) $ck_by_kra[$e['kra_category']][] = $e;
                $kra_order = ['Instruction','Research','Extension','Professional Development'];
                foreach ($kra_order as $kname):
                    $group = $ck_by_kra[$kname] ?? [];
                    if (!$group) continue;
                    $kshort = $ck_kra_short[$kname] ?? $kname;
            ?>
            <!-- KRA section header -->
            <div style="font-size:.6rem;font-weight:700;text-transform:uppercase;letter-spacing:.09em;
                        color:#fff;background:#1a3a6b;padding:.28rem .75rem;margin:0 -.1rem .0rem;">
                <?= $kshort ?> — <?= htmlspecialchars($kname) ?>
            </div>
            <table style="width:100%;border-collapse:collapse;margin-bottom:.5rem;">
                <thead>
                <tr style="background:#f8fafc;border-bottom:1px solid #e2e8f0;">
                    <th style="width:32px;padding:.4rem .6rem;"></th>
                    <th style="padding:.4rem .6rem;text-align:left;font-size:.6rem;font-weight:700;text-transform:uppercase;letter-spacing:.07em;color:#94a3b8;">Entry</th>
                    <th style="padding:.4rem .6rem;text-align:left;font-size:.6rem;font-weight:700;text-transform:uppercase;letter-spacing:.07em;color:#94a3b8;white-space:nowrap;">What to Prepare</th>
                    <th style="padding:.4rem .6rem;text-align:center;font-size:.6rem;font-weight:700;text-transform:uppercase;letter-spacing:.07em;color:#94a3b8;white-space:nowrap;">Score</th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($group as $idx => $e):
                    $raw   = formatKraRemarks($e['kra_category'], $e['remarks']);
                    // Replace HTML separator used by formatKraRemarks with a plain one, then strip tags
                    $raw   = str_replace(['&nbsp;|&nbsp;', ' &nbsp; '], [' / ', ' '], $raw);
                    $title = html_entity_decode(strip_tags($raw), ENT_QUOTES | ENT_HTML5, 'UTF-8');
                    $parts = array_map('trim', explode('|||', $e['remarks']));
                    $ctype = $parts[0] ?? '';
                    // Determine what evidence to prepare based on criterion type
                    $what = match(true) {
                        str_starts_with($ctype,'A-set') || str_starts_with($ctype,'A-sef') => 'Student Evaluation of Teaching / Supervisor Evaluation Form',
                        $ctype === 'A-thesis'   => 'Approved thesis / dissertation document',
                        $ctype === 'A-advisory' => 'Certificate of completion / title page',
                        $ctype === 'A-module'   => 'Approved instructional material with ISBN/accreditation',
                        str_starts_with($ctype,'B-book')    => 'Published book with ISBN, copyright page',
                        str_starts_with($ctype,'B-article') => 'Journal article with DOI or official publication proof',
                        str_starts_with($ctype,'B-chapter') => 'Book chapter proof with publisher details',
                        str_starts_with($ctype,'B-citation')=> 'Screenshot or PDF of citation count (Google Scholar, Scopus)',
                        str_starts_with($ctype,'B-patent')  => 'Patent certificate or official registration document',
                        str_starts_with($ctype,'B-policy')  => 'Signed policy / resolution document',
                        str_starts_with($ctype,'B-paper')   => 'Conference proceedings or certificate of presentation',
                        str_starts_with($ctype,'B-project') => 'Project completion certificate or contract',
                        str_starts_with($ctype,'C-ext')     => 'MOA / MOU or certificate of participation',
                        str_starts_with($ctype,'C-outreach')=> 'Certificate of service or activity report',
                        str_starts_with($ctype,'C-income')  => 'Income-generating project report or financial document',
                        str_starts_with($ctype,'A-org')     => 'Membership certificate or ID',
                        $ctype === 'B-degree'   => 'Diploma, Transcript of Records (TOR), or CAV',
                        str_starts_with($ctype,'B-training')=> 'Certificate of training / seminar participation',
                        $ctype === 'C-award'    => 'Award certificate, plaque photo, or official recognition document',
                        str_starts_with($ctype,'D-prior')   => 'Service record or certificate of employment',
                        default                 => 'Supporting document (certificate, report, or official record)',
                    };
                    $shade = $idx % 2 === 1;
                ?>
                <tr style="border-bottom:1px solid #f1f5f9;background:<?= $shade?'#fafafa':'#fff' ?>;"
                    onmouseover="this.style.background='#f0f4fb'" onmouseout="this.style.background='<?= $shade?'#fafafa':'#fff' ?>'">
                    <td style="padding:.5rem .6rem;text-align:center;vertical-align:top;">
                        <input type="checkbox" style="width:14px;height:14px;cursor:pointer;accent-color:#1a3a6b;"
                               title="Check when you have this evidence ready">
                    </td>
                    <td style="padding:.5rem .6rem;vertical-align:top;font-size:.78rem;color:#1e293b;line-height:1.45;">
                        <?= htmlspecialchars($title) ?>
                    </td>
                    <td style="padding:.5rem .6rem;vertical-align:top;font-size:.72rem;color:#475569;line-height:1.45;">
                        <?= htmlspecialchars($what) ?>
                    </td>
                    <td style="padding:.5rem .6rem;text-align:center;vertical-align:top;font-size:.78rem;font-weight:700;color:#1a3a6b;white-space:nowrap;">
                        <?= number_format((float)$e['computed_points'], 2) ?>
                    </td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <?php endforeach; ?>

            <div style="font-size:.68rem;color:#94a3b8;padding:.5rem 0;border-top:1px solid #f1f5f9;margin-top:.25rem;line-height:1.55;">
                <i class="bi bi-info-circle" style="margin-right:3px;"></i>
                This is a personal preparation checklist — checkboxes are not saved. Upload your files through
                <a href="evidence_repository.php" style="color:#1a3a6b;font-weight:600;">Evidence Repository</a>.
                Actual evidence verification is done by your evaluator during the official application.
            </div>
            <?php endif; ?>
        </div>

        <!-- Footer -->
        <div style="padding:.6rem 1.1rem;border-top:1px solid #e2e8f0;display:flex;justify-content:flex-end;gap:.5rem;background:#f8fafc;">
            <button onclick="window.print()"
                    style="padding:.38rem .9rem;border:1px solid #e2e8f0;background:#fff;font-size:.75rem;font-weight:600;cursor:pointer;color:#475569;display:inline-flex;align-items:center;gap:4px;"
                    onmouseover="this.style.background='#f1f5f9'" onmouseout="this.style.background='#fff'">
                <i class="bi bi-printer"></i> Print
            </button>
            <button onclick="document.getElementById('ckModal').style.display='none'"
                    style="padding:.38rem 1rem;background:#1a3a6b;color:#fff;border:none;font-size:.75rem;font-weight:700;cursor:pointer;">
                Close
            </button>
        </div>
    </div>
</div>

<script>
// Close on backdrop click
document.getElementById('ckModal').addEventListener('click', function(e){
    if (e.target === this) this.style.display = 'none';
});
document.addEventListener('keydown', function(e){
    if (e.key === 'Escape') document.getElementById('ckModal').style.display = 'none';
});
</script>
</body>
</html>
