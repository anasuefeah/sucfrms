<?php
/**
 * Evidence Repository — standalone page.
 * Faculty store, organise, and manage evidence files across all KRA categories.
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
try { $pdo->query("SELECT file_id FROM pre_eval_files LIMIT 1"); }
catch (\Exception $e) {
    $pdo->exec("CREATE TABLE IF NOT EXISTS pre_eval_files (file_id INT AUTO_INCREMENT PRIMARY KEY, user_id INT NOT NULL, entry_id INT DEFAULT NULL, kra_category ENUM('Instruction','Research','Extension','Professional Development','Auto Sub Rank','Position Requirements') NOT NULL, file_path VARCHAR(255) NOT NULL, original_filename VARCHAR(255) NOT NULL, file_size_bytes INT DEFAULT 0, description VARCHAR(255) DEFAULT NULL, uploaded_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE CASCADE)");
}
// Expand ENUM if table already exists without the new categories
try {
    $col = $pdo->query("SHOW COLUMNS FROM pre_eval_files LIKE 'kra_category'")->fetch();
    if ($col && strpos($col['Type'], 'Auto Sub Rank') === false) {
        $pdo->exec("ALTER TABLE pre_eval_files MODIFY kra_category ENUM('Instruction','Research','Extension','Professional Development','Auto Sub Rank','Position Requirements') NOT NULL");
    }
} catch (\Exception $e) {}

// Faculty info
$fac = $pdo->prepare("SELECT full_name, first_name, last_name, middle_name, profile_pic FROM users WHERE user_id=?");
$fac->execute([$uid]);
$fac         = $fac->fetch();
$full_name = formatDisplayName($fac);
$profile_pic = $fac['profile_pic'] ?? '';
$init        = strtoupper(substr($fac['first_name']??'U',0,1).substr($fac['last_name']??'',0,1)) ?: 'FA';

// File stats per KRA
$cats = ['Instruction','Research','Extension','Professional Development','Auto Sub Rank','Position Requirements'];
$stats = [];
foreach ($cats as $c) {
    $s = $pdo->prepare("SELECT COUNT(*) as cnt, COALESCE(SUM(file_size_bytes),0) as total_size FROM pre_eval_files WHERE user_id=? AND kra_category=?");
    $s->execute([$uid, $c]);
    $stats[$c] = $s->fetch();
}
$total_files = array_sum(array_column($stats, 'cnt'));
$total_size  = array_sum(array_column($stats, 'total_size'));
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1.0">
<title>SUCFRMS — Evidence Repository</title>
<link href="../assets/vendor/bootstrap-icons/bootstrap-icons.min.css" rel="stylesheet">
<style>
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0;}
body{font-family:'Segoe UI',Arial,sans-serif;background:#f0f3f8;min-height:100vh;color:#1e293b;}

/* ── Navbar ── */
.pnav{background:#1a3a6b;height:52px;display:flex;align-items:center;padding:0 24px;gap:10px;position:sticky;top:0;z-index:200;box-shadow:0 2px 8px rgba(0,0,0,.2);}
.pnav-logo{width:30px;height:30px;border-radius:50%;object-fit:cover;border:1.5px solid rgba(255,255,255,.3);flex-shrink:0;}
.pnav-title{color:#fff;font-size:.85rem;font-weight:600;flex:1;letter-spacing:.01em;}
.pnav-user{display:flex;align-items:center;gap:8px;cursor:pointer;position:relative;}
.pnav-name{color:rgba(255,255,255,.9);font-size:.8rem;font-weight:500;}
.pnav-avatar{width:32px;height:32px;border-radius:50%;object-fit:cover;border:2px solid rgba(255,255,255,.35);}
.pnav-avatar-ph{width:32px;height:32px;border-radius:50%;background:rgba(255,255,255,.18);border:2px solid rgba(255,255,255,.3);display:flex;align-items:center;justify-content:center;color:#fff;font-size:.65rem;font-weight:700;letter-spacing:1px;}
.pnav-dd{display:none;position:absolute;top:calc(100% + 10px);right:0;background:#fff;border:1px solid #e2e8f0;min-width:200px;box-shadow:0 8px 24px rgba(0,0,0,.12);z-index:500;border-radius:10px;overflow:hidden;}
.pnav-dd.open{display:block;}
.pnav-dd-header{background:linear-gradient(135deg,#1a3a6b,#1e4d8c);padding:12px 16px;display:flex;align-items:center;gap:10px;}
.pnav-dd-avatar-ph{width:36px;height:36px;border-radius:50%;background:rgba(255,255,255,.2);border:2px solid rgba(255,255,255,.3);display:flex;align-items:center;justify-content:center;color:#fff;font-size:.72rem;font-weight:700;flex-shrink:0;}
.pnav-dd-name{color:#fff;font-size:.82rem;font-weight:700;line-height:1.2;}
.pnav-dd-body a{display:flex;align-items:center;gap:10px;padding:10px 16px;font-size:.82rem;color:#1e293b;text-decoration:none;border-bottom:1px solid #f1f5f9;transition:background .12s;}
.pnav-dd-body a:last-child{border-bottom:none;}
.pnav-dd-body a:hover{background:#f8fafc;}
.pnav-dd-body a i{color:#1a3a6b;font-size:.85rem;width:16px;text-align:center;}
.pnav-dd-body a.out{color:#dc2626;}
.pnav-dd-body a.out i{color:#dc2626;}

/* ── Page wrapper ── */
.pg{max-width:1100px;margin:0 auto;padding:24px 20px 60px;}

/* ── Back link ── */
.back-link{display:inline-flex;align-items:center;gap:6px;font-size:.8rem;color:#475569;text-decoration:none;margin-bottom:16px;transition:color .15s;}
.back-link:hover{color:#1a3a6b;}

/* ── Page header ── */
.page-hd{display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:1rem;margin-bottom:20px;}
.page-hd-left{display:flex;align-items:center;gap:12px;}
.page-hd-icon{width:44px;height:44px;border-radius:10px;background:#1a3a6b;display:flex;align-items:center;justify-content:center;flex-shrink:0;}
.page-hd-icon i{color:#fff;font-size:1.2rem;}
.page-hd h2{font-size:1.15rem;font-weight:700;color:#0f172a;margin:0;}
.page-hd p{font-size:.78rem;color:#64748b;margin:2px 0 0;}

/* ── Primary button ── */
.btn-p{display:inline-flex;align-items:center;gap:6px;background:#1a3a6b;color:#fff;border:none;border-radius:8px;padding:.5rem 1.1rem;font-size:.82rem;font-weight:600;cursor:pointer;transition:background .15s;}
.btn-p:hover{background:#1e4d8c;}
.btn-s{display:inline-flex;align-items:center;gap:5px;background:#f1f5f9;color:#475569;border:1px solid #e2e8f0;border-radius:7px;padding:.4rem .9rem;font-size:.8rem;font-weight:500;cursor:pointer;transition:background .15s;}
.btn-s:hover{background:#e2e8f0;}

/* ── KRA stat cards ── */
.stat-row{display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:12px;margin-bottom:20px;}
.stat-card{display:flex;align-items:center;gap:12px;background:#fff;border:2px solid transparent;border-radius:10px;padding:12px 14px;cursor:pointer;transition:all .15s;box-shadow:0 1px 4px rgba(0,0,0,.06);}
.stat-card:hover{border-color:var(--cc);box-shadow:0 2px 10px rgba(0,0,0,.1);}
.stat-card.active{border-color:var(--cc);background:var(--cbg);}
.stat-dot{width:10px;height:10px;border-radius:50%;background:var(--cc);flex-shrink:0;}
.stat-label{font-size:.72rem;color:#64748b;font-weight:600;margin-bottom:2px;}
.stat-count{font-size:1.2rem;font-weight:800;color:var(--cc);line-height:1;}
.stat-size{font-size:.68rem;color:#94a3b8;margin-top:1px;}

/* ── Upload panel ── */
.upload-panel{display:none;background:#fff;border:1px solid #e2e8f0;border-radius:10px;padding:20px;margin-bottom:20px;box-shadow:0 2px 10px rgba(0,0,0,.06);}
.upload-panel.show{display:block;}
.uf-row{display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:14px;}
@media(max-width:600px){.uf-row{grid-template-columns:1fr;}}
.fl{display:block;font-size:.78rem;font-weight:600;color:#475569;margin-bottom:4px;}
.fi{width:100%;padding:.45rem .75rem;border:1px solid #cbd5e1;border-radius:7px;font-size:.85rem;outline:none;transition:border-color .15s;background:#fff;}
.fi:focus{border-color:#1a3a6b;}
.drop-zone{border:2px dashed #cbd5e1;border-radius:10px;padding:28px 20px;text-align:center;cursor:pointer;transition:all .2s;background:#f8fafc;margin-bottom:12px;}
.drop-zone:hover,.drop-zone.over{border-color:#1a3a6b;background:#eff6ff;}
.drop-zone i{font-size:2rem;color:#94a3b8;display:block;margin-bottom:8px;}
.drop-zone p{font-size:.85rem;color:#475569;margin:0 0 4px;}
.drop-zone small{font-size:.72rem;color:#94a3b8;}
.prog-bar{display:none;height:5px;background:#e2e8f0;border-radius:3px;overflow:hidden;margin-bottom:8px;}
.prog-fill{height:100%;background:#1a3a6b;width:0%;transition:width .3s;}

/* ── Toolbar ── */
.toolbar{display:flex;align-items:center;gap:10px;margin-bottom:16px;flex-wrap:wrap;}
.search-box{display:flex;align-items:center;gap:8px;background:#fff;border:1px solid #e2e8f0;border-radius:8px;padding:.4rem .85rem;flex:1;min-width:200px;}
.search-box i{color:#94a3b8;font-size:.9rem;flex-shrink:0;}
.search-box input{border:none;outline:none;font-size:.85rem;width:100%;background:transparent;color:#1e293b;}
.sort-sel{padding:.4rem .75rem;border:1px solid #e2e8f0;border-radius:8px;font-size:.82rem;background:#fff;color:#475569;cursor:pointer;outline:none;}
.view-btns{display:flex;border:1px solid #e2e8f0;border-radius:8px;overflow:hidden;}
.view-btn{background:#fff;border:none;padding:.4rem .65rem;cursor:pointer;color:#94a3b8;transition:all .15s;font-size:.9rem;}
.view-btn:hover{background:#f1f5f9;}
.view-btn.active{background:#1a3a6b;color:#fff;}

/* ── Grid view ── */
.grid-view{display:grid;grid-template-columns:repeat(auto-fill,minmax(190px,1fr));gap:14px;margin-bottom:16px;}
.file-card-grid{background:#fff;border:1px solid #e2e8f0;border-radius:10px;padding:16px 14px 12px;position:relative;transition:box-shadow .15s;display:flex;flex-direction:column;gap:6px;}
.file-card-grid:hover{box-shadow:0 4px 16px rgba(0,0,0,.1);}
.fc-del{position:absolute;top:8px;right:8px;width:24px;height:24px;border-radius:50%;background:#f8fafc;border:1px solid #e2e8f0;color:#94a3b8;cursor:pointer;font-size:.65rem;display:flex;align-items:center;justify-content:center;transition:all .15s;}
.fc-del:hover{background:#fef2f2;color:#dc2626;border-color:#fecaca;}
.fc-open{text-decoration:none;display:contents;}
.fc-icon{font-size:2rem;margin-bottom:4px;line-height:1;}
.fc-name{font-size:.8rem;font-weight:600;color:#1e293b;word-break:break-all;line-height:1.35;}
.fc-cat-badge{display:inline-block;font-size:.65rem;font-weight:700;padding:2px 8px;border-radius:20px;border:1px solid;width:fit-content;}
.fc-meta{font-size:.68rem;color:#94a3b8;}

/* ── List view ── */
.list-view{display:flex;flex-direction:column;gap:6px;margin-bottom:16px;}
.file-card-list{display:flex;align-items:center;gap:12px;background:#fff;border:1px solid #e2e8f0;border-radius:8px;padding:10px 14px;transition:box-shadow .15s;}
.file-card-list:hover{box-shadow:0 2px 8px rgba(0,0,0,.08);}
.fl-icon{font-size:1.4rem;flex-shrink:0;line-height:1;}
.fl-info{flex:1;min-width:0;}
.fl-name{font-size:.85rem;font-weight:600;color:#1a3a6b;text-decoration:none;word-break:break-all;}
.fl-name:hover{text-decoration:underline;}
.fl-meta{font-size:.72rem;color:#94a3b8;margin-top:1px;}
.fl-cat{font-size:.65rem;font-weight:700;padding:2px 10px;border-radius:20px;border:1px solid;white-space:nowrap;flex-shrink:0;}
.fl-del{background:none;border:none;color:#94a3b8;cursor:pointer;font-size:.9rem;padding:.3rem;border-radius:6px;transition:all .15s;flex-shrink:0;}
.fl-del:hover{color:#dc2626;background:#fef2f2;}

/* ── Empty state ── */
.empty-state{text-align:center;padding:60px 20px;color:#94a3b8;}
.empty-state i{font-size:3rem;display:block;margin-bottom:12px;}
.empty-state h3{font-size:.95rem;font-weight:600;color:#475569;margin-bottom:6px;}
.empty-state p{font-size:.82rem;}

/* ── Status bar ── */
.status-bar{display:flex;justify-content:space-between;font-size:.72rem;color:#94a3b8;padding-top:8px;border-top:1px solid #e2e8f0;}
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
                <div>
                    <div class="pnav-dd-name"><?= sanitize($full_name) ?></div>
                </div>
            </div>
            <div class="pnav-dd-body">
                <a href="../index.php?page=profile"><i class="bi bi-person-circle"></i>My Profile</a>
                <a href="pre_evaluation.php"><i class="bi bi-clipboard2-check"></i>Self-Assessment</a>
                <a href="portal.php"><i class="bi bi-grid-1x2"></i>Portal</a>
                <a href="logout.php" class="out"><i class="bi bi-box-arrow-right"></i>Sign Out</a>
            </div>
        </div>
    </div>
</nav>

<div class="pg">

    <a href="pre_evaluation.php" class="back-link"><i class="bi bi-arrow-left"></i> Back to Self-Assessment</a>

    <!-- Header -->
    <div class="page-hd">
        <div class="page-hd-left">
            <div class="page-hd-icon"><i class="bi bi-folder2-open"></i></div>
            <div>
                <h2>Evidence Repository</h2>
                <p>Store and organise your KRA evidence files. <?= $total_files ?> file<?= $total_files!=1?'s':'' ?> · <?= $total_size>1048576?number_format($total_size/1048576,1).'MB':round($total_size/1024).'KB' ?> used</p>
            </div>
        </div>
        <button class="btn-p" onclick="toggleUpload()">
            <i class="bi bi-cloud-arrow-up"></i> Upload File
        </button>
    </div>

    <!-- KRA stat cards (act as filters) -->
    <?php
    $cat_cfg = [
        'Instruction'              => ['label'=>'KRA I — Instruction',        'color'=>'#1e4d8c', 'bg'=>'#eff6ff'],
        'Research'                 => ['label'=>'KRA II — Research',           'color'=>'#1a3a6b', 'bg'=>'#f0f4fb'],
        'Extension'                => ['label'=>'KRA III — Extension',         'color'=>'#1e4d8c', 'bg'=>'#f0fdfa'],
        'Professional Development' => ['label'=>'KRA IV — Prof. Development',  'color'=>'#475569', 'bg'=>'#f8fafc'],
        'Auto Sub Rank'            => ['label'=>'Auto Sub Rank',               'color'=>'#1a3a6b', 'bg'=>'#f0f4fb'],
        'Position Requirements'    => ['label'=>'Position Requirements',       'color'=>'#475569', 'bg'=>'#f8fafc'],
    ];
    ?>
    <div class="stat-row">
        <?php foreach ($cat_cfg as $cat => $cfg): ?>
        <div class="stat-card" style="--cc:<?= $cfg['color'] ?>;--cbg:<?= $cfg['bg'] ?>;"
             onclick="filterByCat(this,'<?= addslashes($cat) ?>')" data-cat="<?= htmlspecialchars($cat) ?>">
            <span class="stat-dot"></span>
            <div>
                <div class="stat-label"><?= $cfg['label'] ?></div>
                <div class="stat-count"><?= $stats[$cat]['cnt'] ?></div>
                <div class="stat-size">
                    <?php $sz = (int)$stats[$cat]['total_size'];
                    echo $sz > 1048576 ? number_format($sz/1048576,1).'MB' : round($sz/1024).'KB'; ?>
                </div>
            </div>
        </div>
        <?php endforeach; ?>
    </div>

    <!-- Upload panel -->
    <div class="upload-panel" id="uploadPanel">
        <div class="uf-row">
            <div>
                <label class="fl">KRA Category <span style="color:#334155">*</span></label>
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
        <!-- Drop zone -->
        <div class="drop-zone" id="dropZone"
             onclick="document.getElementById('fileInput').click()"
             ondragover="event.preventDefault();this.classList.add('over')"
             ondragleave="this.classList.remove('over')"
             ondrop="handleDrop(event)">
            <i class="bi bi-cloud-arrow-up"></i>
            <p>Click or drag & drop files here</p>
            <small>PDF, JPG, PNG, DOC, DOCX — max 50MB each</small>
        </div>
        <input type="file" id="fileInput" style="display:none" accept=".pdf,.jpg,.jpeg,.png,.doc,.docx" multiple onchange="uploadFiles(this.files)">
        <div class="prog-bar" id="progBar"><div class="prog-fill" id="progFill"></div></div>
        <div style="display:flex;gap:.5rem;justify-content:flex-end;margin-top:.5rem;">
            <button class="btn-s" onclick="toggleUpload()">Cancel</button>
        </div>
    </div>

    <!-- Toolbar -->
    <div class="toolbar">
        <div class="search-box">
            <i class="bi bi-search"></i>
            <input type="text" placeholder="Search files..." oninput="doSearch(this.value)">
        </div>
        <select class="sort-sel" onchange="doSort(this.value)">
            <option value="date_desc">Newest first</option>
            <option value="date_asc">Oldest first</option>
            <option value="name_asc">Name A–Z</option>
            <option value="name_desc">Name Z–A</option>
            <option value="size_desc">Largest first</option>
        </select>
        <div class="view-btns">
            <button class="view-btn active" id="gridBtn" onclick="setView('grid')" title="Grid view"><i class="bi bi-grid"></i></button>
            <button class="view-btn" id="listBtn" onclick="setView('list')" title="List view"><i class="bi bi-list-ul"></i></button>
        </div>
    </div>

    <!-- File grid/list -->
    <div id="fileGrid" class="grid-view"></div>

    <!-- Status bar -->
    <div class="status-bar">
        <span id="statusLeft"></span>
        <span id="statusRight"><?= $total_files ?> total file<?= $total_files!=1?'s':'' ?></span>
    </div>

</div>

<script>
const AJAX = 'pre_eval_ajax.php';
let allFiles  = [];
let filterCat = '';
let searchQ   = '';
let sortMode  = 'date_desc';
let viewMode  = 'grid';

const CAT_LABELS = {
    'Instruction':'KRA I','Research':'KRA II',
    'Extension':'KRA III','Professional Development':'KRA IV',
    'Auto Sub Rank':'Auto Sub Rank','Position Requirements':'Pos. Requirements'
};
const CAT_COLORS = {
    'Instruction':'#1e4d8c','Research':'#1a3a6b',
    'Extension':'#1e4d8c','Professional Development':'#475569',
    'Auto Sub Rank':'#1a3a6b','Position Requirements':'#475569'
};
const CAT_BG = {
    'Instruction':'#eff6ff','Research':'#f0f4fb',
    'Extension':'#f0fdfa','Professional Development':'#f8fafc',
    'Auto Sub Rank':'#f0f4fb','Position Requirements':'#f8fafc'
};

// Nav dropdown
function toggleDD(){document.getElementById('ndd').classList.toggle('open');}
document.addEventListener('click',e=>{
    const n=document.getElementById('nu'),d=document.getElementById('ndd');
    if(d&&n&&!n.contains(e.target))d.classList.remove('open');
});

// Upload panel
function toggleUpload(){
    const p=document.getElementById('uploadPanel');
    p.classList.toggle('show');
}

// Drag & drop
function handleDrop(e){
    e.preventDefault();
    document.getElementById('dropZone').classList.remove('over');
    if(e.dataTransfer.files.length) uploadFiles(e.dataTransfer.files);
}

// Upload
function uploadFiles(files){
    const cat  = document.getElementById('upCat').value;
    const desc = document.getElementById('upDesc').value.trim();
    const prog = document.getElementById('progBar');
    const fill = document.getElementById('progFill');
    prog.style.display = 'block';

    let done = 0;
    Array.from(files).forEach(file => {
        const fd = new FormData();
        fd.append('action','upload_repo');
        fd.append('cat', cat);
        fd.append('file', file);
        fd.append('description', desc);
        fetch(AJAX,{method:'POST',body:fd}).then(x=>x.json()).then(d=>{
            done++;
            fill.style.width = Math.round((done/files.length)*100)+'%';
            if(done === files.length){
                setTimeout(()=>{ prog.style.display='none'; fill.style.width='0%'; },500);
                document.getElementById('uploadPanel').classList.remove('show');
                document.getElementById('upDesc').value = '';
                document.getElementById('fileInput').value = '';
                loadFiles();
            }
            if(!d.ok) alert(d.error||'Upload failed: '+file.name);
        });
    });
}

// Filter by KRA card click
function filterByCat(el, cat){
    document.querySelectorAll('.stat-card').forEach(c=>c.classList.remove('active'));
    if(filterCat === cat){
        filterCat = ''; // toggle off
    } else {
        filterCat = cat;
        el.classList.add('active');
    }
    render();
}

// Search & sort
function doSearch(q){ searchQ = q.toLowerCase(); render(); }
function doSort(m){ sortMode = m; render(); }

// View toggle
function setView(v){
    viewMode = v;
    document.getElementById('fileGrid').className = v+'-view';
    document.getElementById('gridBtn').classList.toggle('active', v==='grid');
    document.getElementById('listBtn').classList.toggle('active', v==='list');
    render();
}

// Load all files
function loadFiles(){
    fetch(`${AJAX}?action=get_all_files`).then(x=>x.json()).then(d=>{
        allFiles = d.ok ? d.files : [];
        render();
    });
}

// Render
function render(){
    let files = [...allFiles];
    if(filterCat) files = files.filter(f=>f.kra_category===filterCat);
    if(searchQ)   files = files.filter(f=>
        f.original_filename.toLowerCase().includes(searchQ) ||
        (f.description||'').toLowerCase().includes(searchQ)
    );

    // Sort
    files.sort((a,b)=>{
        if(sortMode==='date_desc') return new Date(b.uploaded_at)-new Date(a.uploaded_at);
        if(sortMode==='date_asc')  return new Date(a.uploaded_at)-new Date(b.uploaded_at);
        if(sortMode==='name_asc')  return a.original_filename.localeCompare(b.original_filename);
        if(sortMode==='name_desc') return b.original_filename.localeCompare(a.original_filename);
        if(sortMode==='size_desc') return b.file_size_bytes - a.file_size_bytes;
        return 0;
    });

    const grid = document.getElementById('fileGrid');
    const sl   = document.getElementById('statusLeft');
    const sr   = document.getElementById('statusRight');

    if(sl) sl.textContent = files.length + ' file' + (files.length!==1?'s':'') + (filterCat?' in '+CAT_LABELS[filterCat]:'');
    if(sr) sr.textContent = allFiles.length + ' total';

    if(!files.length){
        grid.innerHTML = `<div class="empty-state" style="grid-column:1/-1;">
            <i class="bi bi-folder"></i>
            <h3>${allFiles.length?'No files match your filter':'No files yet'}</h3>
            <p>${allFiles.length?'Try a different filter or search term.':'Click Upload File to add your first evidence.'}</p>
        </div>`;
        return;
    }

    grid.innerHTML = files.map(f => viewMode==='grid' ? gridCard(f) : listRow(f)).join('');
}

function gridCard(f){
    const ic  = fileIcon(f.original_filename);
    const cc  = CAT_COLORS[f.kra_category]||'#94a3b8';
    const cbg = CAT_BG[f.kra_category]||'#f8fafc';
    const sz  = fmtSize(f.file_size_bytes);
    const cl  = CAT_LABELS[f.kra_category]||f.kra_category;
    const d   = f.uploaded_at ? new Date(f.uploaded_at).toLocaleDateString('en-PH',{month:'short',day:'numeric',year:'numeric'}) : '';
    return `<div class="file-card-grid">
        <button class="fc-del" onclick="delFile(${f.file_id})" title="Delete"><i class="bi bi-x-lg"></i></button>
        <a href="../${esc(f.file_path)}" target="_blank" class="fc-open">
            <div class="fc-icon" style="color:${cc}">${ic}</div>
            <div class="fc-name" title="${esc(f.original_filename)}">${esc(truncate(f.original_filename,28))}</div>
        </a>
        <span class="fc-cat-badge" style="color:${cc};background:${cbg};border-color:${cc}44;">${cl}</span>
        <div class="fc-meta">${sz} · ${d}</div>
        ${f.description?`<div class="fc-meta" style="font-style:italic;">${esc(f.description)}</div>`:''}
    </div>`;
}

function listRow(f){
    const ic  = fileIcon(f.original_filename);
    const cc  = CAT_COLORS[f.kra_category]||'#94a3b8';
    const cbg = CAT_BG[f.kra_category]||'#f8fafc';
    const sz  = fmtSize(f.file_size_bytes);
    const cl  = CAT_LABELS[f.kra_category]||f.kra_category;
    const d   = f.uploaded_at ? new Date(f.uploaded_at).toLocaleDateString('en-PH',{month:'short',day:'numeric',year:'numeric'}) : '';
    return `<div class="file-card-list">
        <div class="fl-icon" style="color:${cc}">${ic}</div>
        <div class="fl-info">
            <a href="../${esc(f.file_path)}" target="_blank" class="fl-name" title="${esc(f.original_filename)}">${esc(f.original_filename)}</a>
            <div class="fl-meta">${sz} · ${d}${f.description?' · <em>'+esc(f.description)+'</em>':''}</div>
        </div>
        <span class="fl-cat" style="color:${cc};background:${cbg};border-color:${cc}44;">${cl}</span>
        <button class="fl-del" onclick="delFile(${f.file_id})" title="Delete"><i class="bi bi-trash"></i></button>
    </div>`;
}

function delFile(fid){
    confirmAction(
        'Delete this file permanently? This cannot be undone.',
        function(){ doDeleteFile(fid); },
        'Delete',
        'bi-trash'
    );
}

function doDeleteFile(fid){
    const fd = new FormData();
    fd.append('action','delete_file');
    fd.append('file_id', fid);
    fetch(AJAX,{method:'POST',body:fd}).then(x=>x.json()).then(d=>{
        if(d.ok) loadFiles();
        else alert(d.error||'Delete failed.');
    });
}

// Helpers
function fileIcon(name){
    const ext = name.split('.').pop().toLowerCase();
    if(ext==='pdf')  return '<i class="bi bi-file-earmark-pdf"></i>';
    if(ext.match(/jpe?g|png/)) return '<i class="bi bi-file-earmark-image"></i>';
    if(ext.match(/docx?/))     return '<i class="bi bi-file-earmark-word"></i>';
    return '<i class="bi bi-file-earmark"></i>';
}
function fmtSize(b){ return b>1048576?(b/1048576).toFixed(1)+'MB':Math.round(b/1024)+'KB'; }
function truncate(s,n){ return s.length>n?s.slice(0,n)+'…':s; }
function esc(s){ return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;'); }

// Init
loadFiles();
</script>
<?php renderConfirmModal(); ?>
</body>
</html>
