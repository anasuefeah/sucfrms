<?php require __DIR__ . '/../../backend/controllers/pages/evidence_repository.php'; ?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1.0">
<title>SUCFRMS - Evidence Repository</title>
<link rel="preload" href="../assets/fonts/InterVariable.woff2" as="font" type="font/woff2" crossorigin>
<link href="../assets/vendor/bootstrap-icons/bootstrap-icons.min.css" rel="stylesheet">
<link rel="stylesheet" href="../assets/css/app.css?v=<?= filemtime(__DIR__ . '/../assets/css/app.css') ?>">
</head>
<body class="[font-family:'Segoe_UI',Arial,sans-serif] [background:#f0f3f8] min-h-screen text-slate-800 font-sans antialiased">

<!-- Navbar -->
<nav class="pnav bg-brand-700 [height:52px] flex items-center [padding:0_24px] gap-2.5 sticky top-0 [z-index:200] [box-shadow:0_2px_8px_rgba(0,0,0,.2)]">
 <img src="../assets/images/logo.jpg" class="pnav-logo [width:30px] [height:30px] rounded-full object-cover [border:1.5px_solid_rgba(255,255,255,.3)] shrink-0" alt="">
 <span class="pnav-title text-white text-sm font-semibold flex-1 tracking-normal">SUC Faculty Reclassification Management System (SUCFRMS)</span>
 <div class="pnav-user flex items-center gap-2 cursor-pointer relative" id="nu" onclick="toggleDD()">
 <span class="pnav-name [color:rgba(255,255,255,.9)] text-sm font-medium"><?= sanitize($full_name) ?></span>
 <?php if ($profile_pic): ?>
 <img src="../../<?= sanitize($profile_pic) ?>" class="pnav-avatar [width:32px] [height:32px] rounded-full object-cover [border:2px_solid_rgba(255,255,255,.35)]" alt="">
 <?php else: ?>
 <div class="pnav-avatar-ph [width:32px] [height:32px] rounded-full [background:rgba(255,255,255,.18)] [border:2px_solid_rgba(255,255,255,.3)] flex items-center justify-center text-white text-[0.65rem] font-bold [letter-spacing:1px]"><?= $init ?></div>
 <?php endif; ?>
 <div class="pnav-dd hidden absolute [top:calc(100%_+_10px)] right-0 bg-white border border-slate-200 [min-width:200px] [box-shadow:0_8px_24px_rgba(0,0,0,.12)] [z-index:500] rounded-xl overflow-hidden" id="ndd">
 <div class="pnav-dd-header [background:linear-gradient(135deg,#1a3a6b,#1e4d8c)] px-4 py-3 flex items-center gap-2.5">
 <div class="pnav-dd-avatar-ph [width:36px] [height:36px] rounded-full [background:rgba(255,255,255,.2)] [border:2px_solid_rgba(255,255,255,.3)] flex items-center justify-center text-white text-xs font-bold shrink-0"><?= $init ?></div>
 <div>
 <div class="pnav-dd-name text-white text-sm font-bold leading-tight"><?= sanitize($full_name) ?></div>
 </div>
 </div>
 <div class="pnav-dd-body flex items-center gap-2.5 px-4 py-2.5 text-sm text-slate-800 no-underline border-b border-slate-100 [transition:background_.12s] last:[border-bottom:none] hover:bg-slate-50 text-brand-700 text-sm [width:16px] text-center">
 <a href="../../index.php?page=profile"><i class="bi bi-person-circle"></i>My Profile</a>
 <a href="pre_evaluation.php"><i class="bi bi-clipboard2-check"></i>Self-Assessment</a>
 <a href="portal.php"><i class="bi bi-grid-1x2"></i>Portal</a>
 <a href="../../backend/auth/logout.php" class="out text-rose-600"><i class="bi bi-box-arrow-right"></i>Sign Out</a>
 </div>
 </div>
 </div>
</nav>

<div class="pg [max-width:1100px] [margin:0_auto] [padding:24px_20px_60px]">

 <a href="pre_evaluation.php" class="back-link inline-flex items-center gap-1.5 text-sm text-slate-600 no-underline [margin-bottom:16px] [transition:color_.15s] hover:text-brand-700"><i class="bi bi-arrow-left"></i> Back to Self-Assessment</a>

 <!-- Header -->
 <div class="page-hd flex items-center justify-between flex-wrap gap-4 [margin-bottom:20px] [font-size:1.15rem] font-bold text-slate-900 m-0 text-xs text-slate-500 [margin:2px_0_0]">
 <div class="page-hd-left flex items-center gap-3">
 <div class="page-hd-icon [width:44px] [height:44px] rounded-xl bg-brand-700 flex items-center justify-center shrink-0 text-white text-xl"><i class="bi bi-folder2-open"></i></div>
 <div>
 <h2>Evidence Repository</h2>
 <p>Store and organise your KRA evidence files. <?= $total_files ?> file<?= $total_files!=1?'s':'' ?> | <?= $total_size>1048576?number_format($total_size/1048576,1).'MB':round($total_size/1024).'KB' ?> used</p>
 </div>
 </div>
 <button class="btn-p inline-flex items-center gap-1.5 bg-brand-700 text-white border-0 rounded-lg [padding:.5rem_1.1rem] text-sm font-semibold cursor-pointer [transition:background_.15s] hover:bg-blue-800" onclick="toggleUpload()">
 <i class="bi bi-cloud-arrow-up"></i> Upload File
 </button>
 </div>

 <!-- KRA stat cards (act as filters) -->
 <?php
 $cat_cfg = [
 'Instruction' => ['label'=>'KRA I - Instruction', 'color'=>'#1e4d8c', 'bg'=>'#eff6ff'],
 'Research' => ['label'=>'KRA II - Research', 'color'=>'#1a3a6b', 'bg'=>'#f0f4fb'],
 'Extension' => ['label'=>'KRA III - Extension', 'color'=>'#1e4d8c', 'bg'=>'#f0fdfa'],
 'Professional Development' => ['label'=>'KRA IV - Prof. Development', 'color'=>'#475569', 'bg'=>'#f8fafc'],
 'Auto Sub Rank' => ['label'=>'Auto Sub Rank', 'color'=>'#1a3a6b', 'bg'=>'#f0f4fb'],
 'Position Requirements' => ['label'=>'Position Requirements', 'color'=>'#475569', 'bg'=>'#f8fafc'],
 ];
 ?>
 <div class="stat-row grid [grid-template-columns:repeat(auto-fit,minmax(200px,1fr))] gap-3 [margin-bottom:20px]">
 <?php foreach ($cat_cfg as $cat => $cfg): ?>
 <div class="stat-card flex cursor-pointer items-center gap-3 rounded-xl border-2 border-transparent bg-white px-3.5 py-3 shadow-card transition hover:border-brand-300 hover:shadow-card-hover [&.active]:border-brand-600 [&.active]:bg-brand-50"
 onclick="filterByCat(this,'<?= addslashes($cat) ?>')" data-cat="<?= htmlspecialchars($cat) ?>">
 <span class="stat-dot [width:10px] [height:10px] rounded-full shrink-0"></span>
 <div>
 <div class="stat-label text-xs text-slate-500 font-semibold [margin-bottom:2px]"><?= $cfg['label'] ?></div>
 <div class="stat-count text-xl font-extrabold leading-none"><?= $stats[$cat]['cnt'] ?></div>
 <div class="stat-size text-[0.68rem] text-slate-400 [margin-top:1px]">
 <?php $sz = (int)$stats[$cat]['total_size'];
 echo $sz > 1048576 ? number_format($sz/1048576,1).'MB' : round($sz/1024).'KB'; ?>
 </div>
 </div>
 </div>
 <?php endforeach; ?>
 </div>

 <!-- Upload panel -->
 <div class="upload-panel hidden bg-white border border-slate-200 rounded-xl p-5 [margin-bottom:20px] [box-shadow:0_2px_10px_rgba(0,0,0,.06)]" id="uploadPanel">
 <div class="uf-row grid [grid-template-columns:1fr_1fr] gap-3 [margin-bottom:14px] max-[600px]:[grid-template-columns:1fr]">
 <div>
 <label class="fl block text-xs font-semibold text-slate-600 [margin-bottom:4px]">KRA Category <span class="text-slate-700">*</span></label>
 <select class="fi w-full [padding:.45rem_.75rem] border border-slate-300 rounded-lg text-sm outline-none [transition:border-color_.15s] bg-white focus:[border-color:#1a3a6b]" id="upCat">
 <?php foreach ($cat_cfg as $cat => $cfg): ?>
 <option value="<?= htmlspecialchars($cat) ?>"><?= $cfg['label'] ?></option>
 <?php endforeach; ?>
 </select>
 </div>
 <div>
 <label class="fl block text-xs font-semibold text-slate-600 [margin-bottom:4px]">Description (optional)</label>
 <input type="text" class="fi w-full [padding:.45rem_.75rem] border border-slate-300 rounded-lg text-sm outline-none [transition:border-color_.15s] bg-white focus:[border-color:#1a3a6b]" id="upDesc" placeholder="e.g. Certificate of Participation 2025">
 </div>
 </div>

 <!-- Drop zone — hidden during upload -->
 <div id="dropZoneWrap">
 <div class="drop-zone [border:2px_dashed_#cbd5e1] rounded-xl [padding:28px_20px] text-center cursor-pointer [transition:all_.2s] bg-slate-50 [margin-bottom:12px] hover:[border-color:#1a3a6b] hover:bg-blue-50 text-3xl text-slate-400 block [margin-bottom:8px] text-sm text-slate-600 [margin:0_0_4px] text-xs" id="dropZone"
 onclick="document.getElementById('fileInput').click()"
 ondragover="event.preventDefault();this.classList.add('over')"
 ondragleave="this.classList.remove('over')"
 ondrop="handleDrop(event)">
 <i class="bi bi-cloud-arrow-up"></i>
 <p>Click or drag & drop files here</p>
 <small>PDF, JPG, PNG, DOC, DOCX - max 50MB each</small>
 </div>
 </div>
 <input type="file" id="fileInput" accept=".pdf,.jpg,.jpeg,.png,.doc,.docx" multiple onchange="uploadFiles(this.files)" class="hidden">

 <!-- Upload animation — shown during upload -->
 <div class="upload-anim hidden flex-col items-center justify-center px-5 py-6 gap-2.5" id="uploadAnim">
 <i class="bi bi-cloud-arrow-up upload-anim-icon text-4xl text-brand-700"></i>
 <div class="upload-anim-label text-sm font-semibold text-slate-600 [max-width:320px] text-center overflow-hidden text-ellipsis whitespace-nowrap" id="uploadAnimLabel">Preparing…</div>
 <div class="upload-anim-sub text-xs text-slate-400" id="uploadAnimSub"></div>
 <div class="prog-wrap w-full [max-width:360px]">
 <progress class="prog-fill h-1.5 w-full overflow-hidden rounded accent-brand-700" id="progFill" value="0" max="100" aria-label="Upload progress"></progress>
 <div class="prog-count text-xs text-slate-400 [margin-top:5px] text-right" id="progCount"></div>
 </div>
 </div>

 <div class="flex gap-2 justify-end mt-2">
 <button class="btn-s inline-flex items-center gap-1.5 bg-slate-100 text-slate-600 border border-slate-200 rounded-lg [padding:.4rem_.9rem] text-sm font-medium cursor-pointer [transition:background_.15s] hover:bg-slate-200" onclick="toggleUpload()">Cancel</button>
 </div>
 </div>

 <!-- Toolbar -->
 <div class="toolbar flex items-center gap-2.5 [margin-bottom:16px] flex-wrap">
 <div class="search-box flex items-center gap-2 bg-white border border-slate-200 rounded-lg [padding:.4rem_.85rem] flex-1 [min-width:200px] text-slate-400 text-sm shrink-0 border-0 outline-none text-sm w-full bg-transparent text-slate-800">
 <i class="bi bi-search"></i>
 <input type="text" placeholder="Search files..." oninput="doSearch(this.value)">
 </div>
 <select class="sort-sel [padding:.4rem_.75rem] border border-slate-200 rounded-lg text-sm bg-white text-slate-600 cursor-pointer outline-none" onchange="doSort(this.value)">
 <option value="date_desc">Newest first</option>
 <option value="date_asc">Oldest first</option>
 <option value="name_asc">Name A-Z</option>
 <option value="name_desc">Name Z-A</option>
 <option value="size_desc">Largest first</option>
 </select>
 <div class="view-btns flex border border-slate-200 rounded-lg overflow-hidden">
 <button class="view-btn active bg-white border-0 [padding:.4rem_.65rem] cursor-pointer text-slate-400 [transition:all_.15s] text-sm hover:bg-slate-100 [.view-btn.active_&]:bg-brand-700 [.view-btn.active_&]:text-white" id="gridBtn" onclick="setView('grid')" title="Grid view"><i class="bi bi-grid"></i></button>
 <button class="view-btn bg-white border-0 [padding:.4rem_.65rem] cursor-pointer text-slate-400 [transition:all_.15s] text-sm hover:bg-slate-100" id="listBtn" onclick="setView('list')" title="List view"><i class="bi bi-list-ul"></i></button>
 </div>
 </div>

 <!-- File grid/list -->
 <div id="fileGrid" class="grid-view grid [grid-template-columns:repeat(auto-fill,minmax(190px,1fr))] [gap:14px] [margin-bottom:16px]"></div>

 <!-- Status bar -->
 <div class="status-bar">
 <span id="statusLeft"></span>
 <span id="statusRight"><?= $total_files ?> total file<?= $total_files!=1?'s':'' ?></span>
 </div>

</div>

<script>
const AJAX = '../../backend/api/pre_eval_ajax.php';
let allFiles = [];
let filterCat = '';
let searchQ = '';
let sortMode = 'date_desc';
let viewMode = 'grid';

const CAT_LABELS = {
 'Instruction':'KRA I','Research':'KRA II',
 'Extension':'KRA III','Professional Development':'KRA IV',
 'Auto Sub Rank':'Auto Sub Rank','Position Requirements':'Pos. Requirements'
};
const CAT_FULL = {
 'Instruction':'KRA I – Instruction','Research':'KRA II – Research',
 'Extension':'KRA III – Extension','Professional Development':'KRA IV – Prof. Development',
 'Auto Sub Rank':'Auto Sub Rank','Position Requirements':'Position Requirements'
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
const ALL_CATS = Object.keys(CAT_LABELS);

// Nav dropdown
function toggleDD(){document.getElementById('ndd').classList.toggle('hidden');}
document.addEventListener('click',e=>{
 const n=document.getElementById('nu'),d=document.getElementById('ndd');
 if(d&&n&&!n.contains(e.target))d.classList.add('hidden');
});

// Upload panel toggle
function toggleUpload(){
 const p=document.getElementById('uploadPanel');
 p.classList.toggle('hidden');
 // Reset to drop-zone state whenever opening
 if(!p.classList.contains('hidden')) resetUploadUI();
}

function resetUploadUI(){
 document.getElementById('dropZoneWrap').classList.remove('hidden');
 document.getElementById('uploadAnim').classList.add('hidden');
 document.getElementById('progFill').value = 0;
 document.getElementById('uploadAnimLabel').textContent = 'Preparing…';
 document.getElementById('uploadAnimSub').textContent = '';
 document.getElementById('progCount').textContent = '';
 document.getElementById('fileInput').value = '';
}

// Drag & drop
function handleDrop(e){
 e.preventDefault();
 document.getElementById('dropZone').classList.remove('over');
 if(e.dataTransfer.files.length) uploadFiles(e.dataTransfer.files);
}

// Upload with animation
async function uploadFiles(files){
 const cat = document.getElementById('upCat').value;
 const desc = document.getElementById('upDesc').value.trim();
 const anim = document.getElementById('uploadAnim');
 const label = document.getElementById('uploadAnimLabel');
 const sub = document.getElementById('uploadAnimSub');
 const fill = document.getElementById('progFill');
 const count = document.getElementById('progCount');

 // Switch to animation view
 document.getElementById('dropZoneWrap').classList.add('hidden');
 anim.classList.remove('hidden');
 anim.classList.add('flex');

 const batch = Array.from(files);
 let done = 0, errors = [];

 for (const file of batch) {
 label.textContent = file.name;
 sub.textContent = `Uploading ${done + 1} of ${batch.length}…`;
 count.textContent = `${done}/${batch.length}`;

 const fd = new FormData();
 fd.append('action','upload_repo');
 fd.append('cat', cat);
 fd.append('file', file);
 fd.append('description', desc);

 try {
 const res = await fetch(AJAX,{method:'POST',body:fd});
 const d = await res.json();
 if(!d.ok) errors.push(file.name + ': ' + (d.error||'Upload failed'));
 } catch(e) {
 errors.push(file.name + ': network error');
 }

 done++;
 fill.value = Math.round((done / batch.length) * 100);
 }

 // Done
 label.textContent = done === batch.length && !errors.length
 ? `✓ ${done} file${done!==1?'s':''} uploaded`
 : `${done - errors.length} of ${done} uploaded`;
 sub.textContent = errors.length ? errors[0] : 'Upload complete';
 count.textContent = `${done}/${batch.length}`;

 await new Promise(r => setTimeout(r, errors.length ? 2500 : 1200));

 document.getElementById('uploadPanel').classList.add('hidden');
 document.getElementById('upDesc').value = '';
 resetUploadUI();
 if(errors.length) alert('Some uploads failed:\n' + errors.join('\n'));
 loadFiles();
}

// Filter
function filterByCat(el, cat){
 document.querySelectorAll('.stat-card').forEach(c=>c.classList.remove('active'));
 filterCat = filterCat === cat ? '' : cat;
 if(filterCat) el.classList.add('active');
 render();
}

function doSearch(q){ searchQ = q.toLowerCase(); render(); }
function doSort(m){ sortMode = m; render(); }

function setView(v){
 viewMode = v;
 document.getElementById('fileGrid').className = v+'-view';
 document.getElementById('gridBtn').classList.toggle('active', v==='grid');
 document.getElementById('listBtn').classList.toggle('active', v==='list');
 render();
}

function loadFiles(){
 fetch(`${AJAX}?action=get_all_files`).then(x=>x.json()).then(d=>{
 allFiles = d.ok ? d.files : [];
 render();
 });
}

function render(){
 let files = [...allFiles];
 if(filterCat) files = files.filter(f=>f.kra_category===filterCat);
 if(searchQ) files = files.filter(f=>
 f.original_filename.toLowerCase().includes(searchQ) ||
 (f.description||'').toLowerCase().includes(searchQ)
 );
 files.sort((a,b)=>{
 if(sortMode==='date_desc') return new Date(b.uploaded_at)-new Date(a.uploaded_at);
 if(sortMode==='date_asc') return new Date(a.uploaded_at)-new Date(b.uploaded_at);
 if(sortMode==='name_asc') return a.original_filename.localeCompare(b.original_filename);
 if(sortMode==='name_desc') return b.original_filename.localeCompare(a.original_filename);
 if(sortMode==='size_desc') return b.file_size_bytes-a.file_size_bytes;
 return 0;
 });

 const grid = document.getElementById('fileGrid');
 document.getElementById('statusLeft').textContent = files.length+' file'+(files.length!==1?'s':'')+(filterCat?' in '+CAT_LABELS[filterCat]:'');
 document.getElementById('statusRight').textContent = allFiles.length+' total';

 if(!files.length){
 grid.innerHTML = `<div class="empty-state text-center [padding:60px_20px] text-slate-400 text-5xl block [margin-bottom:12px] text-base font-semibold text-slate-600 [margin-bottom:6px] text-sm [grid-column:1/-1]">
 <i class="bi bi-folder"></i>
 <h3>${allFiles.length?'No files match your filter':'No files yet'}</h3>
 <p>${allFiles.length?'Try a different filter or search term.':'Click Upload File to add your first evidence.'}</p>
 </div>`;
 return;
 }
 grid.innerHTML = files.map((f,i) =>
 (viewMode==='grid' ? gridCard(f) : listRow(f))
 ).join('');
}

// ── Move options HTML ────────────────────────────────────────────
function moveOpts(currentCat, fid, selClass){
 return ALL_CATS.map(c =>
 `<option value="${esc(c)}" ${c===currentCat?'selected':''}>${esc(CAT_FULL[c]||c)}</option>`
 ).join('');
}

// ── Grid card ────────────────────────────────────────────────────
function gridCard(f){
 const ic = fileIcon(f.original_filename);
 const cc = CAT_COLORS[f.kra_category]||'#94a3b8';
 const cbg = CAT_BG[f.kra_category]||'#f8fafc';
 const sz = fmtSize(f.file_size_bytes);
 const cl = CAT_LABELS[f.kra_category]||f.kra_category;
 const d = f.uploaded_at ? new Date(f.uploaded_at).toLocaleDateString('en-PH',{month:'short',day:'numeric',year:'numeric'}) : '';
 const fid = f.file_id;
 return `<div class="file-card-grid bg-white border border-slate-200 rounded-xl [padding:16px_14px_12px] relative [transition:box-shadow_.15s] flex flex-col gap-1.5 hover:[box-shadow:0_4px_16px_rgba(0,0,0,.1)]" id="fc-${fid}">
 <a href="../../${esc(f.file_path)}" target="_blank" class="fc-open no-underline contents">
 <div class="fc-icon mb-1 text-3xl leading-none text-brand-700">${ic}</div>
 <div class="fc-name text-sm font-semibold text-slate-800 break-all leading-snug" id="fcname-${fid}" title="${esc(f.original_filename)}">${esc(truncate(f.original_filename,28))}</div>
 </a>
 <span class="fc-cat-badge inline-block w-fit rounded-full border border-brand-200 bg-brand-50 px-2 py-0.5 text-[0.65rem] font-bold text-brand-700" id="fccatbadge-${fid}">${cl}</span>
 <div class="fc-meta text-[0.68rem] text-slate-400">${sz} | ${d}</div>
 ${f.description?`<div class="fc-meta text-[0.68rem] text-slate-400 [font-style:italic]">${esc(f.description)}</div>`:''}

 <!-- Actions row -->
 <div class="fc-actions flex gap-1 mt-1 flex-wrap">
 <button class="fc-act-btn bg-transparent border border-slate-200 rounded-md [padding:2px_7px] text-[0.65rem] text-slate-500 cursor-pointer inline-flex items-center gap-1 [transition:all_.15s] whitespace-nowrap hover:bg-brand-50 hover:[border-color:#1a3a6b] hover:text-brand-700" onclick="startRename('grid',${fid},'${esc(f.original_filename).replace(/'/g,"\\'")}')">
 <i class="bi bi-pencil"></i> Rename
 </button>
 <button class="fc-act-btn danger bg-transparent border border-slate-200 rounded-md [padding:2px_7px] text-[0.65rem] text-slate-500 cursor-pointer inline-flex items-center gap-1 [transition:all_.15s] whitespace-nowrap hover:bg-brand-50 hover:[border-color:#1a3a6b] hover:text-brand-700 [.fc-act-btn.danger_&]:bg-rose-50 [.fc-act-btn.danger_&]:[border-color:#fecaca] [.fc-act-btn.danger_&]:text-rose-600 [.fl-act-btn.danger_&]:bg-rose-50 [.fl-act-btn.danger_&]:[border-color:#fecaca] [.fl-act-btn.danger_&]:text-rose-600" onclick="delFile(${fid})">
 <i class="bi bi-trash"></i>
 </button>
 </div>

 <!-- Rename input -->
 <div class="fc-rename-wrap hidden mt-1" id="fcren-${fid}">
 <input class="fc-rename-in flex-1 [padding:3px_7px] [border:1px_solid_#1a3a6b] rounded-md text-xs outline-none text-slate-800 bg-white min-w-0" id="fcren-in-${fid}" type="text"
 onkeydown="if(event.key==='Enter')saveRename('grid',${fid});if(event.key==='Escape')cancelRename('grid',${fid})">
 <button class="fc-rename-ok bg-brand-700 text-white border-0 rounded-md [padding:3px_9px] text-xs cursor-pointer shrink-0 hover:bg-blue-800" onclick="saveRename('grid',${fid})"><i class="bi bi-check"></i></button>
 <button class="fc-rename-ok bg-brand-700 text-white border-0 rounded-md [padding:3px_9px] text-xs cursor-pointer shrink-0 hover:bg-blue-800 [background:#94a3b8]" onclick="cancelRename('grid',${fid})"><i class="bi bi-x"></i></button>
 </div>

 <!-- Move to KRA -->
 <select class="fc-move-sel [padding:3px_7px] border border-slate-200 rounded-md text-xs text-slate-600 cursor-pointer outline-none bg-white mt-1 w-full focus:[border-color:#1a3a6b]" id="fcmove-${fid}" onchange="moveFile(${fid},this.value,'grid')"
 title="Move to different KRA">
 ${moveOpts(f.kra_category, fid, 'fc-move-sel')}
 </select>
 </div>`;
}

// ── List row ─────────────────────────────────────────────────────
function listRow(f){
 const ic = fileIcon(f.original_filename);
 const cc = CAT_COLORS[f.kra_category]||'#94a3b8';
 const cbg = CAT_BG[f.kra_category]||'#f8fafc';
 const sz = fmtSize(f.file_size_bytes);
 const cl = CAT_LABELS[f.kra_category]||f.kra_category;
 const d = f.uploaded_at ? new Date(f.uploaded_at).toLocaleDateString('en-PH',{month:'short',day:'numeric',year:'numeric'}) : '';
 const fid = f.file_id;
 return `<div class="file-card-list flex items-center gap-3 bg-white border border-slate-200 rounded-lg px-3.5 py-2.5 [transition:box-shadow_.15s] hover:[box-shadow:0_2px_8px_rgba(0,0,0,.08)]" id="fc-${fid}">
 <div class="fl-icon shrink-0 text-2xl leading-none text-brand-700">${ic}</div>
 <div class="fl-info flex-1 min-w-0 min-w-0 flex-1">
 <a href="../../${esc(f.file_path)}" target="_blank" class="fl-name text-sm font-semibold text-brand-700 no-underline break-all hover:[text-decoration:underline]" id="fcname-${fid}" title="${esc(f.original_filename)}">${esc(f.original_filename)}</a>
 <div class="fl-meta text-xs text-slate-400 [margin-top:1px]">${sz} | ${d}${f.description?' | <em>'+esc(f.description)+'</em>':''}</div>
 <div class="fl-rename-bar hidden gap-1 items-center mt-1" id="fcren-${fid}">
 <input class="fl-rename-in flex-1 [padding:3px_7px] [border:1px_solid_#1a3a6b] rounded-md text-xs outline-none text-slate-800 min-w-0" id="fcren-in-${fid}" type="text"
 onkeydown="if(event.key==='Enter')saveRename('list',${fid});if(event.key==='Escape')cancelRename('list',${fid})">
 <button class="fl-rename-ok bg-brand-700 text-white border-0 rounded-md [padding:3px_9px] text-xs cursor-pointer shrink-0" onclick="saveRename('list',${fid})"><i class="bi bi-check"></i></button>
 <button class="fl-rename-ok bg-brand-700 text-white border-0 rounded-md [padding:3px_9px] text-xs cursor-pointer shrink-0 [background:#94a3b8]" onclick="cancelRename('list',${fid})"><i class="bi bi-x"></i></button>
 </div>
 </div>
 <span class="fl-cat shrink-0 whitespace-nowrap rounded-full border border-brand-200 bg-brand-50 px-2.5 py-0.5 text-[0.65rem] font-bold text-brand-700" id="fccatbadge-${fid}">${cl}</span>
 <div class="fl-actions flex gap-1 items-center shrink-0">
 <button class="fl-act-btn bg-transparent border border-slate-200 rounded-md [padding:3px_8px] text-[0.68rem] text-slate-500 cursor-pointer inline-flex items-center gap-1 [transition:all_.15s] whitespace-nowrap hover:bg-brand-50 hover:[border-color:#1a3a6b] hover:text-brand-700" onclick="startRename('list',${fid},'${esc(f.original_filename).replace(/'/g,"\\'")}')">
 <i class="bi bi-pencil"></i> Rename
 </button>
 <select class="fl-move-sel [padding:3px_7px] border border-slate-200 rounded-md text-xs text-slate-600 cursor-pointer outline-none bg-white shrink-0 focus:[border-color:#1a3a6b]" id="fcmove-${fid}" onchange="moveFile(${fid},this.value,'list')" title="Move to KRA">
 ${moveOpts(f.kra_category, fid)}
 </select>
 <button class="fl-act-btn danger bg-transparent border border-slate-200 rounded-md [padding:3px_8px] text-[0.68rem] text-slate-500 cursor-pointer inline-flex items-center gap-1 [transition:all_.15s] whitespace-nowrap hover:bg-brand-50 hover:[border-color:#1a3a6b] hover:text-brand-700 [.fc-act-btn.danger_&]:bg-rose-50 [.fc-act-btn.danger_&]:[border-color:#fecaca] [.fc-act-btn.danger_&]:text-rose-600 [.fl-act-btn.danger_&]:bg-rose-50 [.fl-act-btn.danger_&]:[border-color:#fecaca] [.fl-act-btn.danger_&]:text-rose-600" onclick="delFile(${fid})"><i class="bi bi-trash"></i></button>
 </div>
 </div>`;
}

// ── Rename ───────────────────────────────────────────────────────
function startRename(mode, fid, currentName){
 const wrap = document.getElementById('fcren-'+fid);
 const inp = document.getElementById('fcren-in-'+fid);
 if(!wrap || !inp) return;
 wrap.classList.remove('hidden');
 wrap.classList.add('flex');
 inp.value = currentName;
 inp.focus();
 inp.select();
}
function cancelRename(mode, fid){
 const wrap = document.getElementById('fcren-'+fid);
 if(wrap) wrap.classList.add('hidden');
}
function saveRename(mode, fid){
 const inp = document.getElementById('fcren-in-'+fid);
 const name = inp ? inp.value.trim() : '';
 if(!name){ cancelRename(mode, fid); return; }
 const fd = new FormData();
 fd.append('action','rename_file');
 fd.append('file_id', fid);
 fd.append('new_name', name);
 fetch(AJAX,{method:'POST',body:fd}).then(x=>x.json()).then(d=>{
 if(d.ok){
 // Update displayed name without full reload
 const nameEl = document.getElementById('fcname-'+fid);
 if(nameEl){
 const isList = nameEl.tagName === 'A';
 nameEl.textContent = isList ? d.original_filename : truncate(d.original_filename, 28);
 nameEl.title = d.original_filename;
 }
 cancelRename(mode, fid);
 // Update allFiles cache
 const f = allFiles.find(x=>x.file_id==fid);
 if(f) f.original_filename = d.original_filename;
 } else {
 alert(d.error||'Rename failed.');
 }
 });
}

// ── Move ─────────────────────────────────────────────────────────
function moveFile(fid, newCat, mode){
 const fd = new FormData();
 fd.append('action','move_file');
 fd.append('file_id', fid);
 fd.append('new_cat', newCat);
 fetch(AJAX,{method:'POST',body:fd}).then(x=>x.json()).then(d=>{
 if(d.ok){
 // Update badge label + colours in place
 const badge = document.getElementById('fccatbadge-'+fid);
 const cc = CAT_COLORS[newCat]||'#94a3b8';
 const cbg = CAT_BG[newCat]||'#f8fafc';
 if(badge){
 badge.textContent = CAT_LABELS[newCat]||newCat;
 badge.style.color = cc;
 badge.style.background = cbg;
 badge.style.borderColor = cc+'44';
 }
 // Update icon colour
 const card = document.getElementById('fc-'+fid);
 if(card){ const ic=card.querySelector('.fc-icon,.fl-icon'); if(ic) ic.style.color=cc; }
 // Update cache
 const f = allFiles.find(x=>x.file_id==fid);
 if(f) f.kra_category = newCat;
 // Refilter if active filter excludes this file
 if(filterCat && filterCat !== newCat) render();
 } else {
 alert(d.error||'Move failed.');
 loadFiles(); // resync
 }
 });
}

// ── Delete ───────────────────────────────────────────────────────
function delFile(fid){
 confirmAction('Delete this file permanently? This cannot be undone.',
 ()=>{ doDeleteFile(fid); },'Delete','bi-trash');
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

// ── Helpers ──────────────────────────────────────────────────────
function fileIcon(name){
 const ext = name.split('.').pop().toLowerCase();
 if(ext==='pdf') return '<i class="bi bi-file-earmark-pdf"></i>';
 if(ext.match(/jpe?g|png/)) return '<i class="bi bi-file-earmark-image"></i>';
 if(ext.match(/docx?/)) return '<i class="bi bi-file-earmark-word"></i>';
 return '<i class="bi bi-file-earmark"></i>';
}
function fmtSize(b){ return b>1048576?(b/1048576).toFixed(1)+'MB':Math.round(b/1024)+'KB'; }
function truncate(s,n){ return s.length>n?s.slice(0,n)+'…':s; }
function esc(s){ return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;'); }

loadFiles();
</script>
<?php renderConfirmModal(); ?>
</body>
</html>
