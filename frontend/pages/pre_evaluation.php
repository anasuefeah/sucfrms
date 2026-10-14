<?php require __DIR__ . '/../../backend/controllers/pages/pre_evaluation.php'; ?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1.0">
<title>SUCFRMS - Self-Assessment</title>
<link rel="preload" href="../assets/fonts/InterVariable.woff2" as="font" type="font/woff2" crossorigin>
<link href="../assets/vendor/bootstrap-icons/bootstrap-icons.min.css" rel="stylesheet">
<link rel="stylesheet" href="../assets/css/app.css?v=<?= filemtime(__DIR__ . '/../assets/css/app.css') ?>">
</head>
<body class="m-0 min-h-screen overflow-x-hidden bg-canvas font-sans text-slate-800 antialiased">

<!-- Navbar -->
<?php $pe_view = $_GET['view'] ?? 'self'; ?>
<nav class="pnav bg-brand-700 [height:48px] flex items-center [padding:0_24px] gap-2.5 sticky top-0 [z-index:200] max-[600px]:[padding:0_12px] max-[600px]:gap-2">
 <img src="../assets/images/logo.jpg" class="pnav-logo [width:28px] [height:28px] rounded-full object-cover [border:1.5px_solid_rgba(201,168,76,.6)] shrink-0" alt="">
 <span class="pnav-title text-white text-sm font-semibold flex-1 whitespace-nowrap overflow-hidden text-ellipsis min-w-0 max-[600px]:text-xs">SUC Faculty Reclassification Management System (SUCFRMS)</span>
 <div class="pnav-user flex items-center gap-2 cursor-pointer relative" id="nu" onclick="toggleDD()">
 <span class="pnav-name [color:rgba(255,255,255,.9)] text-sm whitespace-nowrap overflow-hidden text-ellipsis [max-width:120px] max-[600px]:[max-width:72px] max-[600px]:text-xs"><?= sanitize($full_name) ?></span>
 <?php if ($profile_pic): ?>
 <img src="../../<?= sanitize($profile_pic) ?>" class="pnav-avatar [width:28px] [height:28px] rounded-full object-cover [border:1.5px_solid_rgba(201,168,76,.5)]" alt="">
 <?php else: ?>
 <div class="pnav-avatar-ph [width:28px] [height:28px] rounded-full [background:rgba(255,255,255,.15)] [border:1.5px_solid_rgba(201,168,76,.4)] flex items-center justify-center text-white text-[0.65rem] font-bold"><?= $init ?></div>
 <?php endif; ?>
 <div class="pnav-dd hidden absolute [top:calc(100%_+_6px)] right-0 bg-white border border-slate-200 [min-width:172px] [box-shadow:0_4px_16px_rgba(0,0,0,.1)] [z-index:500] flex items-center gap-2.5 px-4 py-2.5 text-sm text-slate-800 no-underline border-b border-slate-100 hover:bg-slate-50 text-brand-700 text-sm [width:14px]" id="ndd">
 <a href="../../index.php?page=profile"><i class="bi bi-person-circle"></i>Profile</a>
 <a href="../../index.php?page=my_application"><i class="bi bi-file-earmark-text"></i>Application</a>
 <a href="portal.php"><i class="bi bi-grid-1x2"></i>Portal</a>
 <a href="../../backend/auth/logout.php" class="out text-slate-800"><i class="bi bi-box-arrow-right"></i>Sign out</a>
 </div>
 </div>
</nav>

<div class="pg flex [min-height:calc(100vh_-_48px)] max-[900px]:flex-col">

<!-- -- Score sidebar -- -->
<aside class="sidebar [width:200px] shrink-0 bg-white border-r border-slate-200 [padding:.85rem_.85rem] sticky [top:48px] [height:calc(100vh_-_48px)] overflow-y-auto flex flex-col gap-2.5 max-[900px]:w-full max-[900px]:[height:auto] max-[900px]:[position:static] max-[900px]:[border-right:none] max-[900px]:border-b border-slate-200 max-[600px]:[padding:.65rem_.75rem]">

 <!-- Nav links -->
 <div class="flex flex-col gap-0.5 [margin-bottom:0.85rem] [padding-bottom:0.85rem] border-b border-slate-200">
 <a href="pre_evaluation.php"
 class="flex items-center gap-2 rounded-lg px-2.5 py-2 text-sm font-bold no-underline transition-colors <?= $pe_view !== 'analytics' ? 'bg-blue-800 text-white' : 'text-slate-600 hover:bg-slate-100' ?>">
 <i class="bi bi-pencil-square [font-size:0.85rem]"></i> Self Assessment
 </a>
 <a href="pre_evaluation.php?view=analytics"
 class="flex items-center gap-2 rounded-lg px-2.5 py-2 text-sm font-bold no-underline transition-colors <?= $pe_view === 'analytics' ? 'bg-blue-800 text-white' : 'text-slate-600 hover:bg-slate-100' ?>">
 <i class="bi bi-bar-chart-line [font-size:0.85rem]"></i> Analytics
 </a>
 </div>

 <div class="sb-heading text-[0.58rem] font-bold text-slate-400 uppercase tracking-widest">Score Estimate</div>

 <div class="score-big text-center [padding:.6rem_0] border-b border-slate-100 border-t border-slate-100">
 <div class="score-num text-3xl font-extrabold leading-none max-[600px]:text-2xl <?= $weighted>=41?'text-blue-800':($weighted>0?'text-slate-600':'text-slate-400') ?>" id="sbScore">
 <?= number_format($weighted,2) ?>
 </div>
 <div class="score-sub text-[0.6rem] text-slate-400 mt-0.5">Weighted / 100</div>
 <div class="score-min mt-1 text-[0.6rem] font-semibold <?= $weighted>=41?'text-blue-800':'text-slate-700' ?>" id="sbMinNote">
 <?= $weighted>=41?'OK Meets minimum (41)':'X Below minimum (41)' ?>
 </div>
 </div>

 <div>
 <?php foreach ($kra_tabs as $slug => $t):
 if (!in_array($slug, ['instruction','research','extension','profdev'])) continue;
 $rpts = min($t['max'], $raw[$t['cat']] ?? 0);
 $pct = $t['max'] > 0 ? min(100, round(($rpts/$t['max'])*100)) : 0;
 $wpts = round($rpts * ($weights[$t['cat']] ?? 0), 2);
 ?>
 <div class="kra-row flex flex-col gap-0.5" id="sb-kra-<?= $slug ?>">
 <div class="kra-row-head flex justify-between">
 <span class="kra-row-label text-[0.65rem] text-slate-800 font-semibold"><?= $t['label'] ?></span>
 <span class="kra-row-pts text-[0.65rem] font-bold text-brand-700"><?= number_format($wpts,1) ?>pt</span>
 </div>
 <progress class="kra-bar h-1 w-full overflow-hidden rounded accent-brand-700" value="<?= $pct ?>" max="100" aria-label="<?= htmlspecialchars($t['label']) ?> progress"></progress>
 <div class="kra-row-raw text-[0.58rem] text-slate-400"><?= number_format($rpts,1) ?>/<?= $t['max'] ?> | <?= round($weights[$t['cat']]*100) ?>% wt</div>
 </div>
 <?php endforeach; ?>
 </div>

 <div class="rank-box bg-slate-50 border border-slate-200 [padding:.6rem] max-[600px]:[padding:.5rem]">
 <div class="rank-box-label text-[0.58rem] text-slate-400 uppercase tracking-wide [margin-bottom:3px]">Rank Projection</div>
 <div class="flex items-center gap-1 flex-wrap">
 <span class="rank-cur text-xs font-bold text-brand-700 max-[600px]:text-[0.68rem]" id="sbCurRank"><?= sanitize($rank?:' - ') ?></span>
 <?php if ($pot_rank && $pot_rank !== $rank && $pot_rank !== ' - '): ?>
 <span class="rank-arrow text-slate-400 [margin:0_3px] text-[0.68rem]">-></span>
 <span class="rank-pot text-xs font-bold text-blue-800 max-[600px]:text-[0.68rem]" id="sbPotRank"><?= sanitize($pot_rank) ?></span>
 <?php endif; ?>
 </div>
 <?php if ($sub_rank > 0): ?>
 <div class="increment-badge inline-flex items-center gap-1 bg-brand-50 border border-blue-200 [padding:2px_6px] text-[0.62rem] font-bold text-blue-800 [margin-top:.35rem] cursor-help"
 title="Sub-rank = the number of steps forward in the rank scale your weighted score earns (e.g. +2 means you move up 2 positions from your current rank)."
 >
 +<?= $sub_rank ?> sub-rank<?= $sub_rank>1?'s':'' ?>
 <i class="bi bi-question-circle text-[0.58rem] [opacity:.65] [margin-left:2px]"></i>
 </div>
 <?php elseif ($weighted > 0): ?>
 <div class="below-note text-[0.6rem] text-slate-600 [margin-top:.3rem] font-semibold">Score below 41</div>
 <?php endif; ?>
 </div>

 <div class="sb-note text-[0.58rem] text-slate-400 leading-relaxed [padding-top:.6rem] border-t border-slate-100 mt-auto">
 <i class="bi bi-info-circle me-1"></i>Estimate only - final scores are reviewed by your evaluator.
 </div>

 <button onclick="document.getElementById('pe-logout-modal').classList.remove('hidden');document.getElementById('pe-logout-modal').classList.add('flex')"
 class="mt-3.5 flex w-full items-center justify-center gap-2 rounded-lg border border-rose-200 bg-rose-50 px-3 py-2 text-xs font-bold text-rose-600 transition-colors hover:bg-rose-100 hover:text-rose-700">
 <i class="bi bi-box-arrow-right [font-size:0.88rem]"></i> Sign Out
 </button>
</aside>

<!-- ── Sign Out Confirmation Modal ────────────────────────────────────── -->
<div id="pe-logout-modal"
 class="fixed inset-0 z-[100003] hidden items-center justify-center bg-slate-950/50 p-4">
 <div class="bg-white rounded-xl w-full [max-width:440px] [margin:1rem] [box-shadow:0_8px_32px_rgba(0,0,0,0.15)] overflow-hidden">

 <!-- Header -->
 <div class="bg-blue-800 [padding:1.25rem_1.5rem] flex items-center [gap:0.875rem]">
 <img src="../assets/images/logo.jpg" alt="SUCFRMS Logo"
 class="[width:40px] [height:40px] object-cover rounded-full [border:2px_solid_#475569] shrink-0">
 <div>
 <div class="text-white font-bold [font-size:0.95rem] leading-tight">SUCFRMS</div>
 <div class="[color:#bfdbfe] [font-size:0.75rem] mt-0.5">SUC Faculty Reclassification Management System</div>
 </div>
 </div>

 <!-- Body -->
 <div class="[padding:2rem_1.75rem_1.5rem] text-center">
 <div class="[width:68px] [height:68px] rounded-full bg-rose-50 flex items-center justify-center [margin:0_auto_1.25rem]">
 <i class="bi bi-box-arrow-right text-3xl text-rose-600"></i>
 </div>
 <h5 class="font-bold text-slate-800 [margin-bottom:0.5rem] text-xl">Sign Out</h5>
 <p class="text-slate-500 [font-size:0.9rem] m-0 leading-relaxed">
 Are you sure you want to sign out of your account?
 </p>
 </div>

 <!-- Divider -->
 <div class="[height:1px] bg-slate-200 [margin:0_1.75rem]"></div>

 <!-- Buttons -->
 <div class="[padding:1.25rem_1.75rem_1.75rem] flex [gap:0.75rem]">
 <button onclick="document.getElementById('pe-logout-modal').classList.add('hidden')"
 class="flex-1 [padding:0.7rem_1rem] border border-slate-300 rounded-lg bg-white text-slate-600 font-semibold cursor-pointer [font-size:0.875rem]">
 Cancel
 </button>
 <a href="../../backend/auth/logout.php"
 class="flex-1 [padding:0.7rem_1rem] border-0 rounded-lg [background:#dc2626] text-white font-semibold cursor-pointer [font-size:0.875rem] text-center no-underline flex items-center justify-center [gap:0.4rem]">
 <i class="bi bi-box-arrow-right"></i> Sign Out
 </a>
 </div>

 </div>
</div>
<script>
// Close modal on backdrop click
document.getElementById('pe-logout-modal').addEventListener('click', function(e) {
 if (e.target === this) this.classList.add('hidden');
});
</script>

<!-- -- Main -- -->
<div class="main flex-1 min-w-0 [padding:.9rem_1.25rem_3rem] max-[600px]:[padding:.7rem_.75rem_2.5rem] max-[600px]:flex-col max-[600px]:items-start last:max-[600px]:w-full last:max-[600px]:justify-start">

 <a href="portal.php" class="back-link inline-flex items-center gap-1.5 text-xs text-slate-400 no-underline mb-3 hover:text-brand-700"><i class="bi bi-arrow-left"></i> Back to Portal</a>

 <?php if ($pe_view === 'analytics'): ?>
 <?php
 // ── Analytics data prep ──────────────────────────────────────────────
 $ana_meta = [
 'Instruction' => ['KRA I', 'Instruction', 40, '#1e4d8c', '#dbeafe'],
 'Research' => ['KRA II', 'Research', 30, '#0f766e', '#ccfbf1'],
 'Extension' => ['KRA III', 'Extension', 20, '#7c3aed', '#ede9fe'],
 'Professional Development' => ['KRA IV', 'Prof. Development', 10, '#b45309', '#fef3c7'],
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
 41 => 'Minimum eligibility',
 51 => 'Next rank tier (51)',
 61 => 'Next rank tier (61)',
 71 => 'Next rank tier (71)',
 81 => 'Next rank tier (81)',
 91 => 'Next rank tier (91)',
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
 $cur_rank_idx = array_search($rank, $all_ranks_scale);
 $proj_rank_idx = array_search($pot_rank, $all_ranks_scale);
 $rank_steps = ($cur_rank_idx !== false && $proj_rank_idx !== false) ? $proj_rank_idx - $cur_rank_idx : 0;
 ?>

 <!-- ── Page heading ── -->
 <div class="[margin-bottom:1.5rem] [padding-bottom:0.75rem] border-b border-slate-200">
 <div class="[font-size:0.95rem] font-bold text-brand-700">Analytics</div>
 <div class="[font-size:0.78rem] text-slate-500 mt-1">A summary of your self-assessment performance and readiness for reclassification.</div>
 </div>

 <!-- ── Eligibility status ── -->
 <?php if ($weighted >= 41): ?>
 <div class="bg-slate-50 border border-slate-200 [border-left:3px_solid_#1a3a6b] [border-radius:0] [padding:0.85rem_1rem] [margin-bottom:1.5rem]">
 <div class="font-bold text-brand-700 [font-size:0.85rem] [margin-bottom:2px]">Eligibility Status: Qualified</div>
 <div class="[font-size:0.78rem] text-slate-700 leading-relaxed">
 Your current weighted score of <strong><?= number_format($weighted,2) ?></strong> meets the minimum requirement of 41 points.
 You are eligible to submit a reclassification application once a cycle is opened by the administrator.
 <?php if ($next_threshold): ?>
 To reach the next scoring tier (<?= $next_threshold ?> pts), you need <strong><?= number_format($pts_to_next,2) ?> more weighted points</strong>.
 <?php endif; ?>
 </div>
 </div>
 <?php else: ?>
 <div class="bg-slate-50 border border-slate-200 [border-left:3px_solid_#64748b] [border-radius:0] [padding:0.85rem_1rem] [margin-bottom:1.5rem]">
 <div class="font-bold text-slate-800 [font-size:0.85rem] [margin-bottom:2px]">Eligibility Status: Not Yet Qualified</div>
 <div class="[font-size:0.78rem] text-slate-700 leading-relaxed">
 Your current weighted score is <strong><?= number_format($weighted,2) ?></strong>.
 You need <strong><?= number_format(max(0,41-$weighted),2) ?> more weighted points</strong> to reach the minimum eligibility threshold of 41.
 Review the KRA breakdown below and focus on areas where you have the most room to grow.
 </div>
 </div>
 <?php endif; ?>

 <!-- ── Four stat figures ── -->
 <div class="grid [grid-template-columns:repeat(4,1fr)] gap-0 border border-slate-200 [margin-bottom:1.5rem]">
 <?php
 $stat_items = [
 ['Weighted Score', number_format($weighted,2), 'out of 100'],
 ['Points to Min.', $weighted>=41 ? '—' : number_format(max(0,41-$weighted),2), $weighted>=41?'already met':'needed to qualify'],
 ['KRA Entries', $total_entries_all, 'total submitted'],
 ['Evidence Coverage', $evidence_pct.'%', 'entries with files'],
 ];
 foreach ($stat_items as $i => [$slabel,$sval,$ssub]):
 ?>
 <div class="px-4 py-5 text-center <?= $i < 3 ? 'border-r border-slate-200' : '' ?>">
 <div class="[font-size:0.6rem] font-bold uppercase [letter-spacing:0.08em] text-slate-400 [margin-bottom:0.4rem]"><?= $slabel ?></div>
 <div class="[font-size:1.6rem] font-extrabold text-brand-700 leading-none"><?= $sval ?></div>
 <div class="[font-size:0.68rem] text-slate-400 mt-1"><?= $ssub ?></div>
 </div>
 <?php endforeach; ?>
 </div>

 <!-- ── KRA Breakdown ── -->
 <div class="bg-white border border-slate-200 [margin-bottom:1.5rem]">
 <div class="[padding:0.75rem_1rem] border-b border-slate-200 bg-slate-50">
 <div class="[font-size:0.72rem] font-bold uppercase [letter-spacing:0.07em] text-slate-600">KRA Score Breakdown</div>
 </div>
 <div class="[padding:1rem_1.25rem]">
 <?php foreach ($ana_meta as $kname => [$kshort, $klabel, $kwt, $col, $bg]):
 $kpts = $raw[$kname] ?? 0;
 $kwpts = round($kpts * ($kwt/100), 2);
 $bar = min(100, $kpts);
 $room = max(0, 100 - $kpts);
 $eq2 = $pdo->prepare("SELECT COUNT(*) FROM pre_eval_entries WHERE user_id=? AND kra_category=?");
 $eq2->execute([$uid, $kname]);
 $kcount = (int)$eq2->fetchColumn();
 ?>
 <div class="[padding:0.85rem_0] border-b border-slate-100">
 <div class="flex justify-between items-baseline [margin-bottom:6px]">
 <div>
 <span class="[font-size:0.82rem] font-bold text-slate-800"><?= $kshort ?> — <?= $klabel ?></span>
 <span class="[font-size:0.7rem] text-slate-400 ml-2"><?= $kwt ?>% weight &nbsp;·&nbsp; <?= $kcount ?> entr<?= $kcount===1?'y':'ies' ?></span>
 </div>
 <span class="[font-size:0.82rem] font-bold text-brand-700"><?= number_format($kpts,2) ?><span class="[font-size:0.68rem] font-normal text-slate-400"> /100 pts</span></span>
 </div>
 <progress class="mb-1 h-1.5 w-full overflow-hidden rounded accent-brand-700" value="<?= $bar ?>" max="100" aria-label="<?= htmlspecialchars($klabel) ?> score"></progress>
 <div class="flex justify-between [font-size:0.68rem] text-slate-500">
 <span>Weighted contribution: <strong class="text-brand-700"><?= number_format($kwpts,2) ?> pts</strong></span>
 <span><?= $room > 0 ? number_format($room,1).' pts room to grow' : 'Maxed out' ?></span>
 </div>
 </div>
 <?php endforeach; ?>
 </div>
 </div>

 <!-- ── Rank Projection ── -->
 <div class="bg-white border border-slate-200 [margin-bottom:1.5rem]">
 <div class="[padding:0.75rem_1rem] border-b border-slate-200 bg-slate-50">
 <div class="[font-size:0.72rem] font-bold uppercase [letter-spacing:0.07em] text-slate-600">Rank Projection</div>
 </div>
 <div class="[padding:1rem_1.25rem]">
 <div class="flex items-center gap-6 flex-wrap [margin-bottom:0.85rem]">
 <div>
 <div class="[font-size:0.62rem] text-slate-400 font-bold uppercase [letter-spacing:0.05em] [margin-bottom:3px]">Current Rank</div>
 <div class="[font-size:0.9rem] font-bold text-slate-700"><?= htmlspecialchars($rank ?: '—') ?></div>
 </div>
 <div class="text-slate-400 [font-size:0.8rem]">→</div>
 <div>
 <div class="[font-size:0.62rem] text-slate-400 font-bold uppercase [letter-spacing:0.05em] [margin-bottom:3px]">Projected Rank</div>
 <div class="[font-size:0.9rem] font-extrabold text-brand-700"><?= htmlspecialchars($pot_rank ?: '—') ?></div>
 </div>
 <?php if ($rank_steps > 0): ?>
 <div class="ml-auto bg-slate-100 border border-slate-200 [padding:3px_12px] [font-size:0.72rem] font-bold text-brand-700">
 +<?= $rank_steps ?> step<?= $rank_steps>1?'s':'' ?>
 </div>
 <?php endif; ?>
 </div>
 <div class="[font-size:0.78rem] text-slate-600 leading-relaxed">
 <?php if ($weighted < 41): ?>
 Your current score of <strong><?= number_format($weighted,2) ?></strong> is below the minimum of 41. No rank advancement will be recommended until your score meets the threshold.
 <?php elseif ($rank_steps === 0): ?>
 Your score of <strong><?= number_format($weighted,2) ?></strong> meets the minimum but does not yet qualify for a rank increment. A higher score is needed to advance.
 <?php else: ?>
 Based on your current score of <strong><?= number_format($weighted,2) ?></strong>, the system projects a <strong>+<?= $rank_steps ?> sub-rank</strong> advancement from <em><?= htmlspecialchars($rank) ?></em> to <strong><?= htmlspecialchars($pot_rank) ?></strong>.
 <?php if ($sub_rank > 0): ?>
 An additional <strong>+<?= $sub_rank ?> auto sub-rank</strong> bonus may apply if your doctorate or prestigious award entry is confirmed by an evaluator.
 <?php endif; ?>
 <?php endif; ?>
 </div>
 </div>
 </div>

 <!-- ── Recommendations ── -->
 <div class="bg-white border border-slate-200">
 <div class="[padding:0.75rem_1rem] border-b border-slate-200 bg-slate-50">
 <div class="[font-size:0.72rem] font-bold uppercase [letter-spacing:0.07em] text-slate-600">Recommendations</div>
 </div>
 <div class="[padding:1rem_1.25rem] flex flex-col gap-0">
 <?php
 $recs = [];
 if ($weighted < 41)
 $recs[] = ['You need ' . number_format(41 - $weighted, 2) . ' more weighted points to qualify.',
 'Focus on KRAs with higher weight percentages. Even a few well-documented entries in Instruction or Research can significantly move your weighted score closer to the 41-point minimum.'];
 if ($total_entries_all === 0)
 $recs[] = ['No KRA entries have been added yet.',
 'Start by adding entries under Self Assessment. Each entry you add will calculate an estimated score automatically. You do not need to wait for a cycle to be open to begin.'];
 if ($best_kra && ($raw[$best_kra] ?? 0) < 100) {
 [$bs,$bl,$bw] = $ana_meta[$best_kra];
 $recs[] = [$bl . ' has the highest potential impact on your score.',
 $bs . ' carries a ' . $bw . '% weight in your KRA formula. Adding or completing entries in this area will contribute the most to your weighted total compared to other KRAs.'];
 }
 if ($evidence_pct < 100 && $total_entries_all > 0) {
 // Evidence upload is optional for the self-assessment (predictive mode)
 }
 foreach (array_keys($ana_meta) as $kname) {
 if (($raw[$kname] ?? 0) === 0.0 && $total_entries_all > 0) {
 $recs[] = ['You have zero points in ' . $ana_meta[$kname][1] . '.',
 'Having a zero score in any KRA area reduces your overall weighted total. Even a single valid entry with supporting evidence can improve your standing in this area.'];
 break; // flag only the first zero KRA to avoid noise
 }
 }
 if ($weighted >= 41 && $next_threshold)
 $recs[] = ['Next milestone: ' . $next_threshold . ' weighted points.',
 'You are eligible. Adding ' . number_format($pts_to_next,2) . ' more points will move you into the ' . $next_threshold . '-point bracket, which may result in a higher projected rank and a stronger reclassification recommendation.'];
 if ($weighted >= 91)
 $recs[] = ['Your score is in the top tier.',
 'Ensure all evidence files are attached and that your entries are accurately described before the cycle opens. A complete, well-documented application gives evaluators less reason to flag entries for revision.'];
 if (empty($recs))
 $recs[] = ['Your self-assessment looks complete.',
 'Your score is above the minimum. Review your entries one more time before the cycle opens to confirm accuracy and completeness.'];

 foreach ($recs as $ridx => [$rtitle,$rmsg]):
 ?>
 <div class="py-3.5 <?= $ridx < count($recs)-1 ? 'border-b border-slate-100' : '' ?>">
 <div class="[font-size:0.8rem] font-bold text-slate-800 [margin-bottom:3px]"><?= htmlspecialchars($rtitle) ?></div>
 <div class="[font-size:0.76rem] text-slate-600 leading-relaxed"><?= htmlspecialchars($rmsg) ?></div>
 </div>
 <?php endforeach; ?>
 </div>
 </div>

 <?php else: // self assessment ?>

 <?php if (!$eligible): ?>
 <!-- -- Eligibility gate banner -- -->
 <div class="[padding:.75rem_1rem] bg-rose-50 border border-rose-200 [border-left:4px_solid_#dc2626] rounded-md mb-4 text-sm [color:#b91c1c]">
 <div class="font-bold [margin-bottom:.3rem]"><i class="bi bi-shield-x me-1"></i>Not eligible for self-assessment</div>
 <?php foreach ($eligibility_notes as $en): ?>
 <div class="[margin-top:.2rem]">* <?= htmlspecialchars($en) ?></div>
 <?php endforeach; ?>
 </div>
 <?php else: ?>

 <?php endif; ?>

 <div class="flex items-center justify-between flex-wrap gap-2.5 mb-4">
 <div>
 <div class="text-sm font-bold text-brand-700">Self-Assessment</div>
 <div class="text-[0.68rem] text-slate-400 mt-0.5">Self-assess your KRA scores before a cycle opens.</div>
 </div>
 <div class="flex gap-1.5 flex-wrap">
 <button class="btn-s bg-white text-brand-700 [border:1px_solid_#1a3a6b] [padding:.4rem_.9rem] text-xs font-semibold cursor-pointer hover:bg-brand-50 text-xs" onclick="openDrawer()">
 Evidence Guide
 </button>
 <a href="evidence_repository.php" class="btn-s bg-white text-brand-700 [border:1px_solid_#1a3a6b] [padding:.4rem_.9rem] text-xs font-semibold cursor-pointer hover:bg-brand-50 no-underline text-xs">
 Evidence Repository
 </a>
 <a href="../../backend/services/reports/pre_eval_pdf.php" target="_blank" class="btn-s bg-white text-brand-700 [border:1px_solid_#1a3a6b] [padding:.4rem_.9rem] text-xs font-semibold cursor-pointer hover:bg-brand-50 text-xs no-underline" title="Download self-assessment PDF">
 Print Summary
 </a>
 </div>
 </div>

 <!-- Tabs -->
 <div class="tabs flex gap-0 overflow-x-auto border-b-2 border-slate-200 mb-4">
 <?php foreach ($kra_tabs as $slug => $t): ?>
 <a href="?tab=<?= $slug ?>" class="tab-btn <?= $active_tab===$slug?'active':'' ?> flex items-center gap-1.5 [padding:.55rem_.9rem] text-xs font-semibold text-slate-500 border-0 bg-transparent cursor-pointer [border-bottom:2px_solid_transparent] [margin-bottom:-2px] whitespace-nowrap no-underline [border-radius:6px_6px_0_0] [transition:color_.15s,background_.15s] hover:text-brand-700 hover:bg-brand-50 max-[600px]:text-[0.68rem] max-[600px]:[padding:.45rem_.65rem]">
 <?= $t['label'] ?>
 <?php if ($counts[$slug] > 0): ?>
 <span class="tab-badge bg-slate-100 text-slate-500 text-[0.6rem] font-bold [padding:1px_5px] rounded-full bg-brand-700 text-white"><?= $counts[$slug] ?></span>
 <?php endif; ?>
 </a>
 <?php endforeach; ?>
 </div>

 <div class="panels grid [grid-template-columns:1fr] gap-3.5 max-[900px]:[grid-template-columns:1fr]">

 <?php if ($cur_cat === 'Auto Sub Rank'): ?>
 <!-- AUTO SUB RANK PANEL -->
 <?php
 // Load saved data - kept for backward compat; new UI uses AutoSubRankCalculator directly
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
 <div class="bg-emerald-50 [border:1px_solid_#bbf7d0] rounded-md [padding:.6rem_.9rem] mb-3 text-xs text-emerald-700">
 <i class="bi bi-check-circle me-1"></i>Auto Sub Rank status saved.
 </div>
 <?php endif; ?>
 <div class="panel bg-white border border-slate-200">
 <div class="panel-hd [padding:.75rem_1rem] border-b border-slate-100 flex items-center justify-between gap-2">
 <div class="panel-hd-title text-xs font-bold text-brand-700 flex items-center gap-1.5">Auto Sub Rank</div>
 </div>
 <div class="panel-body p-3.5 max-[600px]:[padding:.65rem_.75rem]">
 <?php
 // -- Load AutoSubRankCalculator -------------------------------------
 if (!class_exists('\Scoring\AutoSubRankCalculator')) {
 require_once __DIR__ . '/../../backend/services/scoring/autosubrank.php';
 }

 // Detect doctorate and award from pre_eval_entries
 $pe_doc_stmt = $pdo->prepare(
 "SELECT remarks FROM pre_eval_entries
 WHERE user_id=? AND kra_category='Professional Development'
 ORDER BY entry_id ASC"
 );
 $pe_doc_stmt->execute([$uid]);
 $pe_has_doctorate = false; $pe_doc_details = '';
 $pe_has_award = false; $pe_awd_details = '';
 foreach ($pe_doc_stmt->fetchAll(\PDO::FETCH_COLUMN) as $pe_rem) {
 $pp = array_map('trim', explode('|||', $pe_rem));
 $pct = $pp[0] ?? '';
 $psv = (float)($pp[2] ?? 0);
 if ($pct === 'B-degree' && $psv >= 40.0 && !$pe_has_doctorate) {
 $pe_has_doctorate = true;
 $pe_doc_details = $pp[1] ?? 'Doctorate degree';
 }
 if ($pct === 'C-award' && $psv === 0.0 && !$pe_has_award) {
 $pe_has_award = true;
 $pe_awd_details = $pp[1] ?? 'National/International Award';
 }
 }

 // Always recalculate on page load
 $pe_calc = new \Scoring\AutoSubRankCalculator($pdo, 0, $uid);
 $pe_result = $pe_calc->calculateForPreEval(
 (float)($score_result['weighted_score'] ?? 0),
 $rank,
 $pe_has_doctorate, $pe_doc_details,
 $pe_has_award, $pe_awd_details
 );
 $pe_calc->persistPreEval($pe_result);

 $pe_d_mode = $pe_result['doctorate_mode'];
 $pe_a_mode = $pe_result['award_mode'];
 $pe_d_color = \Scoring\AutoSubRankCalculator::modeColor($pe_d_mode);
 $pe_a_color = \Scoring\AutoSubRankCalculator::modeColor($pe_a_mode);
 $pe_d_label = \Scoring\AutoSubRankCalculator::modeLabel($pe_d_mode);
 $pe_a_label = \Scoring\AutoSubRankCalculator::modeLabel($pe_a_mode);
 $pe_ri = $pe_result['total_rank_increase'];
 ?>

 <?php if (!empty($_GET['saved'])): ?>
 <div class="bg-emerald-50 [border:1px_solid_#bbf7d0] rounded-md [padding:.5rem_.85rem] mb-3 text-xs text-emerald-700">
 <i class="bi bi-check-circle me-1"></i>Auto Sub Rank recalculated.
 </div>
 <?php endif; ?>

 <!-- Result banner -->
 <div class="mb-3.5 flex items-center gap-2.5 rounded-lg border px-4 py-3 <?= $pe_ri > 0 ? 'border-emerald-200 bg-emerald-50' : 'border-slate-200 bg-slate-50' ?>">
 <?php if ($pe_ri > 0): ?>
 <i class="bi bi-arrow-up-circle-fill text-emerald-600 text-lg shrink-0"></i>
 <div>
 <div class="font-bold text-emerald-600 text-sm">+<?= $pe_ri ?> automatic sub-rank<?= $pe_ri > 1 ? 's' : '' ?></div>
 <div class="text-xs text-slate-500 mt-0.5">System-calculated - no manual choice required</div>
 </div>
 <?php else: ?>
 <i class="bi bi-dash-circle text-slate-500 text-lg shrink-0"></i>
 <div>
 <div class="font-semibold text-slate-500 text-sm">No automatic sub-rank increase</div>
 <div class="text-xs text-slate-400 mt-0.5">Add a doctorate or award entry in Prof. Development to qualify</div>
 </div>
 <?php endif; ?>
 </div>

 <!-- Criterion 1: Doctorate -->
 <div class="add-form bg-slate-50 border border-slate-200 p-3.5 [margin-bottom:.85rem] max-[600px]:[padding:.65rem_.75rem] mb-3">
 <div class="text-xs font-bold text-brand-700 uppercase tracking-wide mb-2 flex items-center gap-2">
 <i class="bi bi-mortarboard"></i>1 - Doctorate Degree
 <span class="ml-auto rounded-full border px-2 py-0.5 text-[0.65rem] font-bold uppercase tracking-wide <?= $pe_d_mode === 'triggered' ? 'border-emerald-200 bg-emerald-50 text-emerald-600' : ($pe_d_mode === 'blocked_historical' ? 'border-amber-200 bg-amber-50 text-amber-600' : 'border-brand-200 bg-brand-50 text-brand-700') ?>">
 <?= htmlspecialchars($pe_d_label) ?>
 </span>
 </div>
 <?php if ($pe_has_doctorate): ?>
 <div class="fg [margin-bottom:.6rem]">
 <label class="fl text-[0.68rem] font-semibold text-slate-600 block [margin-bottom:3px]">Detected From</label>
 <div class="[padding:.4rem_.6rem] bg-slate-50 border border-slate-200 text-xs text-slate-600 [border-radius:0]">
 <?= htmlspecialchars($pe_doc_details) ?>
 </div>
 </div>
 <div class="fg [margin-bottom:.6rem]">
 <label class="fl text-[0.68rem] font-semibold text-slate-600 block [margin-bottom:3px]">Why This Decision</label>
 <div class="[padding:.4rem_.6rem] bg-slate-50 border border-slate-200 text-xs text-slate-600 [border-radius:0] leading-relaxed">
 <?= htmlspecialchars($pe_result['doctorate_reason']) ?>
 </div>
 </div>
 <?php else: ?>
 <div class="text-xs text-slate-500 [padding:.4rem_0]">
 <i class="bi bi-exclamation-circle me-1 text-slate-400"></i>
 No doctorate entry found.
 <a href="?tab=profdev" class="text-blue-800 font-semibold">Add a doctorate in Prof. Development (B-degree >= 40)</a>
 </div>
 <?php endif; ?>
 </div>

 <!-- Criterion 2: Award -->
 <div class="add-form bg-slate-50 border border-slate-200 p-3.5 [margin-bottom:.85rem] max-[600px]:[padding:.65rem_.75rem] mb-3">
 <div class="text-xs font-bold text-brand-700 uppercase tracking-wide mb-2 flex items-center gap-2">
 <i class="bi bi-trophy"></i>2 - National / International Award
 <span class="ml-auto rounded-full border px-2 py-0.5 text-[0.65rem] font-bold uppercase tracking-wide <?= $pe_a_mode === 'triggered' ? 'border-emerald-200 bg-emerald-50 text-emerald-600' : ($pe_a_mode === 'blocked_historical' ? 'border-amber-200 bg-amber-50 text-amber-600' : 'border-brand-200 bg-brand-50 text-brand-700') ?>">
 <?= htmlspecialchars($pe_a_label) ?>
 </span>
 </div>
 <?php if ($pe_has_award): ?>
 <div class="fg [margin-bottom:.6rem]">
 <label class="fl text-[0.68rem] font-semibold text-slate-600 block [margin-bottom:3px]">Award</label>
 <div class="[padding:.4rem_.6rem] bg-slate-50 border border-slate-200 text-xs text-slate-600 [border-radius:0]">
 <?= htmlspecialchars($pe_awd_details) ?>
 </div>
 </div>
 <div class="fg [margin-bottom:.6rem]">
 <label class="fl text-[0.68rem] font-semibold text-slate-600 block [margin-bottom:3px]">Why This Decision</label>
 <div class="[padding:.4rem_.6rem] bg-slate-50 border border-slate-200 text-xs text-slate-600 [border-radius:0] leading-relaxed">
 <?= htmlspecialchars($pe_result['award_reason']) ?>
 </div>
 </div>
 <?php else: ?>
 <div class="text-xs text-slate-500 [padding:.4rem_0]">
 <i class="bi bi-exclamation-circle me-1 text-slate-400"></i>
 No national/international award found.
 <a href="?tab=profdev" class="text-blue-800 font-semibold">Add award in Prof. Development (C-award, national/intl)</a>
 </div>
 <?php endif; ?>
 </div>

 <div class="text-xs text-slate-400 mt-2">
 <i class="bi bi-arrow-repeat me-1"></i>Recalculated automatically each time you view this tab.
 Score used: <strong><?= number_format((float)($pe_result['weighted_score_at_calc'] ?? 0), 2) ?></strong>
 </div>
 </div>
 </div>


 <?php elseif ($cur_cat === 'Position Requirements'): ?>
 <!-- POSITION REQUIREMENTS PANEL -->
 <div class="panel bg-white border border-slate-200">
 <div class="panel-hd [padding:.75rem_1rem] border-b border-slate-100 flex items-center justify-between gap-2">
 <div class="panel-hd-title text-xs font-bold text-brand-700 flex items-center gap-1.5"><i class="bi bi-file-earmark-check me-1"></i>Position Requirements - <?= sanitize($rank ?: 'Unknown Rank') ?></div>
 </div>
 <div class="panel-body p-3.5 max-[600px]:[padding:.65rem_.75rem]">
 <?php
 $is_instructor_pr = strpos($rank, 'Instructor') !== false;
 $is_asst_pr = strpos($rank, 'Assistant Professor') !== false;
 $is_assoc_pr = strpos($rank, 'Associate Professor') !== false;
 $is_univ_pr = $rank === 'University Professor';
 $is_professor_pr = strpos($rank, 'Professor') !== false && !$is_asst_pr && !$is_assoc_pr && !$is_univ_pr;
 ?>
 <div class="bg-blue-50 border border-blue-200 rounded-lg [padding:.75rem_1rem] mb-4 text-xs text-blue-800">
 <i class="bi bi-info-circle me-1"></i>These are the additional documents required for your position when reclassifying. Prepare them ahead of time before a cycle opens.
 </div>

 <?php if ($is_instructor_pr || $is_asst_pr || $is_assoc_pr): ?>
 <div class="bg-emerald-50 [border:1px_solid_#bbf7d0] rounded-lg p-6 text-center">
 <i class="bi bi-check-circle-fill text-emerald-600 text-3xl [margin-bottom:.6rem] block"></i>
 <div class="font-bold text-emerald-600 text-sm [margin-bottom:.3rem]">No Additional Documents Required</div>
 <div class="text-emerald-700 text-xs">Your current rank (<?= sanitize($rank) ?>) does not require additional position documentation beyond the standard KRA evidence.</div>
 </div>

 <?php elseif ($is_professor_pr): ?>
 <div class="border border-slate-200 rounded-lg overflow-hidden mb-3">
 <div class="bg-blue-800 [padding:.45rem_.85rem]">
 <span class="text-white text-xs font-bold uppercase tracking-wide">Professor Position Requirements</span>
 </div>
 <table class="w-full [border-collapse:collapse] text-sm">
 <thead>
 <tr class="bg-slate-50 border-b border-slate-200">
 <th class="[padding:.6rem_.75rem] text-slate-500 text-[0.68rem] font-bold uppercase [width:36px]">#</th>
 <th class="[padding:.6rem_.75rem] text-slate-500 text-[0.68rem] font-bold uppercase">Requirement</th>
 <th class="[padding:.6rem_.75rem] text-slate-500 text-[0.68rem] font-bold uppercase">Evidence Needed</th>
 </tr>
 </thead>
 <tbody>
 <tr class="border-b border-slate-100">
 <td class="[padding:.7rem_.75rem] text-center font-bold text-slate-500">1</td>
 <td class="[padding:.7rem_.75rem] font-semibold text-slate-800">CAV Transcript of Records</td>
 <td class="[padding:.7rem_.75rem] text-slate-600 text-xs">Official CAV-authenticated transcript from CHED or your institution's registrar certifying your academic credentials.</td>
 </tr>
 <tr>
 <td class="[padding:.7rem_.75rem] text-center font-bold text-slate-500">2</td>
 <td class="[padding:.7rem_.75rem] font-semibold text-slate-800">
 Internationally Indexed Article
 <div class="text-xs text-slate-500 font-normal mt-0.5">Scopus, WoS, or ACI - published within the last 3 years</div>
 </td>
 <td class="[padding:.7rem_.75rem] text-slate-600 text-xs">
 Published article with indexing proof (Scopus author profile, WoS record, or ACI listing). Must be within 3 years of application date. Co-authored articles are accepted.
 </td>
 </tr>
 </tbody>
 </table>
 </div>

 <?php elseif ($is_univ_pr): ?>
 <div class="border border-slate-200 rounded-lg overflow-hidden mb-3">
 <div class="bg-blue-800 [padding:.45rem_.85rem]">
 <span class="text-white text-xs font-bold uppercase tracking-wide">University Professor Requirements</span>
 </div>
 <table class="w-full [border-collapse:collapse] text-sm">
 <thead>
 <tr class="bg-slate-50 border-b border-slate-200">
 <th class="[padding:.6rem_.75rem] text-slate-500 text-[0.68rem] font-bold uppercase [width:36px]">#</th>
 <th class="[padding:.6rem_.75rem] text-slate-500 text-[0.68rem] font-bold uppercase">Requirement</th>
 <th class="[padding:.6rem_.75rem] text-slate-500 text-[0.68rem] font-bold uppercase">Evidence Needed</th>
 </tr>
 </thead>
 <tbody>
 <tr>
 <td class="[padding:.7rem_.75rem] text-center font-bold text-slate-500">1</td>
 <td class="[padding:.7rem_.75rem] font-semibold text-slate-800">College/University Professor Certification Form</td>
 <td class="[padding:.7rem_.75rem] text-slate-600 text-xs">
 Signed certification form from the College Dean and University President confirming eligibility for University Professor status. Must include endorsement from the Academic Council.
 </td>
 </tr>
 </tbody>
 </table>
 </div>

 <?php else: ?>
 <div class="bg-slate-50 border border-slate-200 rounded-lg p-6 text-center text-slate-400 text-sm">
 <i class="bi bi-question-circle text-2xl block mb-2"></i>
 Position requirements for your rank (<?= sanitize($rank ?: 'unknown') ?>) are not yet configured. Contact your administrator.
 </div>
 <?php endif; ?>

 <div class="bg-slate-50 border border-slate-200 rounded-lg [padding:.75rem_1rem] mt-3 text-xs text-slate-500">
 <i class="bi bi-shield-check me-1"></i>Upload these documents in the actual Reclassification application when a cycle is open - not here in Self-Assessment.
 </div>
 </div>
 </div>

 <?php else: ?>
 <!-- KRA ENTRIES PANEL -->
 <div class="panel bg-white border border-slate-200">
 <div class="panel-hd [padding:.75rem_1rem] border-b border-slate-100 flex items-center justify-between gap-2">
 <div class="panel-hd-title text-xs font-bold text-brand-700 flex items-center gap-1.5"><?= $cur['label'] ?></div>
 <div class="flex gap-1.5 items-center">
 <button class="btn-p bg-brand-700 text-white border-0 [padding:.4rem_.9rem] text-xs font-semibold cursor-pointer hover:bg-blue-800" onclick="toggleForm()" id="addBtn"
 <?= !$eligible ? 'disabled title="Not eligible - see notice above"' : '' ?>>
 + Add Entry
 </button>
 <button class="btn-p bg-brand-700 text-white border-0 [padding:.4rem_.9rem] text-xs font-semibold cursor-pointer hover:bg-blue-800" id="resetAllBtn"
 onclick="confirmResetAll()"
 class="border border-rose-200 bg-rose-50 text-rose-600"
 <?= !$eligible ? 'disabled' : '' ?>>
 <i class="bi bi-arrow-counterclockwise"></i> Reset All
 </button>
 </div>
 </div>
 <div class="panel-body p-3.5 max-[600px]:[padding:.65rem_.75rem]">

 <!-- Entry form -->
 <div class="add-form hidden bg-slate-50 border border-slate-200 p-3.5 [margin-bottom:.85rem] max-[600px]:[padding:.65rem_.75rem]" id="addForm">
 <input type="hidden" id="editId" value="">

 <?php if ($cur_cat === 'Instruction'): ?>
 <div class="fg [margin-bottom:.6rem]">
 <label class="fl text-[0.68rem] font-semibold text-slate-600 block [margin-bottom:3px]">Criterion Type</label>
 <select class="fi w-full [padding:.4rem_.6rem] border border-slate-200 text-xs text-slate-800 bg-white [font-family:inherit] m-0 focus:outline-none focus:[border-color:#1a3a6b] resize-y [min-height:52px]" id="critType" onchange="switchInstrForm();previewScore()">
 <option value="A-set-sef">A - Teaching Evaluation (SET + SEF)</option>
 <option value="B-material">B - Instructional Material</option>
 <option value="C-thesis">C - Thesis / Dissertation Advising</option>
 </select>
 </div>
 <div id="fA">
 <div class="fg2 grid [grid-template-columns:1fr_1fr] gap-2.5 [margin-bottom:.6rem] max-[600px]:[grid-template-columns:1fr]">
 <div><label class="fl text-[0.68rem] font-semibold text-slate-600 block [margin-bottom:3px]">SET Average (%)</label><input type="number" class="fi w-full [padding:.4rem_.6rem] border border-slate-200 text-xs text-slate-800 bg-white [font-family:inherit] m-0 focus:outline-none focus:[border-color:#1a3a6b] resize-y [min-height:52px]" id="setV" min="0" max="100" value="0" oninput="previewScore()"></div>
 <div><label class="fl text-[0.68rem] font-semibold text-slate-600 block [margin-bottom:3px]">SEF Average (%)</label><input type="number" class="fi w-full [padding:.4rem_.6rem] border border-slate-200 text-xs text-slate-800 bg-white [font-family:inherit] m-0 focus:outline-none focus:[border-color:#1a3a6b] resize-y [min-height:52px]" id="sefV" min="0" max="100" value="0" oninput="previewScore()"></div>
 </div>
 <div class="fg [margin-bottom:.6rem]"><label class="fl text-[0.68rem] font-semibold text-slate-600 block [margin-bottom:3px]">Notes (optional)</label><textarea class="fi w-full [padding:.4rem_.6rem] border border-slate-200 text-xs text-slate-800 bg-white [font-family:inherit] m-0 focus:outline-none focus:[border-color:#1a3a6b] resize-y [min-height:52px]" id="notesA" rows="2"></textarea></div>
 </div>
 <div id="fB" class="hidden">
 <div class="fg [margin-bottom:.6rem]">
 <label class="fl text-[0.68rem] font-semibold text-slate-600 block [margin-bottom:3px]">Material Type</label>
 <select class="fi w-full [padding:.4rem_.6rem] border border-slate-200 text-xs text-slate-800 bg-white [font-family:inherit] m-0 focus:outline-none focus:[border-color:#1a3a6b] resize-y [min-height:52px]" id="matType" onchange="previewScore()">
 <option>Textbook - Sole Author (30 pts)</option>
 <option>Textbook - Co-Author (30 pts)</option>
 <option>Textbook Chapter - Sole Author (10 pts)</option>
 <option>Textbook Chapter - Co-Author (10 pts)</option>
 <option>Manual/Module - Sole Author (16 pts)</option>
 <option>Manual/Module - Co-Author (16 pts)</option>
 <option>Multimedia Teaching Material (16 pts)</option>
 <option>Validated Testing Material (10 pts)</option>
 <option>Academic Program Dev - Lead (10 pts)</option>
 <option>Academic Program Dev - Contributor (5 pts)</option>
 </select>
 </div>
 <div class="fg [margin-bottom:.6rem]"><label class="fl text-[0.68rem] font-semibold text-slate-600 block [margin-bottom:3px]">Contribution % (co-authorship)</label><input type="number" class="fi w-full [padding:.4rem_.6rem] border border-slate-200 text-xs text-slate-800 bg-white [font-family:inherit] m-0 focus:outline-none focus:[border-color:#1a3a6b] resize-y [min-height:52px]" id="contribB" value="100" min="1" max="100" oninput="previewScore()"></div>
 <div class="fg [margin-bottom:.6rem]"><label class="fl text-[0.68rem] font-semibold text-slate-600 block [margin-bottom:3px]">Title / Description</label><input type="text" class="fi w-full [padding:.4rem_.6rem] border border-slate-200 text-xs text-slate-800 bg-white [font-family:inherit] m-0 focus:outline-none focus:[border-color:#1a3a6b] resize-y [min-height:52px]" id="titleB" placeholder="Title of material"></div>
 </div>
 <div id="fC" class="hidden">
 <div class="fg [margin-bottom:.6rem]">
 <label class="fl text-[0.68rem] font-semibold text-slate-600 block [margin-bottom:3px]">Type</label>
 <select class="fi w-full [padding:.4rem_.6rem] border border-slate-200 text-xs text-slate-800 bg-white [font-family:inherit] m-0 focus:outline-none focus:[border-color:#1a3a6b] resize-y [min-height:52px]" id="thesisType" onchange="previewScore()">
 <option>Doctoral Dissertation - Adviser (10 pts)</option>
 <option>Master's Thesis - Adviser (8 pts)</option>
 <option>Undergraduate Thesis - Adviser (5 pts)</option>
 <option>Special Project / Capstone - Adviser (3 pts)</option>
 <option>Doctoral Dissertation - Panel Member (6 pts)</option>
 <option>Master's Thesis - Panel Member (4 pts)</option>
 <option>Undergraduate Thesis - Panel Member (2 pts)</option>
 <option>Special Project / Capstone - Panel Member (1 pt)</option>
 <option>Mentor: Student/Team Competition Winner (Warning pts pending confirmation)</option>
 </select>
 </div>
 <div class="fg [margin-bottom:.6rem]"><label class="fl text-[0.68rem] font-semibold text-slate-600 block [margin-bottom:3px]">Advisee / Student Name (optional)</label><input type="text" class="fi w-full [padding:.4rem_.6rem] border border-slate-200 text-xs text-slate-800 bg-white [font-family:inherit] m-0 focus:outline-none focus:[border-color:#1a3a6b] resize-y [min-height:52px]" id="advisee" placeholder="Name of advisee"></div>
 </div>

 <?php elseif ($cur_cat === 'Research'): ?>
 <div class="fg [margin-bottom:.6rem]">
 <label class="fl text-[0.68rem] font-semibold text-slate-600 block [margin-bottom:3px]">Output Type</label>
 <select class="fi w-full [padding:.4rem_.6rem] border border-slate-200 text-xs text-slate-800 bg-white [font-family:inherit] m-0 focus:outline-none focus:[border-color:#1a3a6b] resize-y [min-height:52px]" id="resType" onchange="previewScore()">
 <option value="Book, Sole Author (100pts)">Book - Sole Author (100 pts)</option>
 <option value="Book, Co-Author (100pts)">Book - Co-Author (100 pts)</option>
 <option value="Monograph, Sole Author (100pts)">Monograph - Sole Author (100 pts)</option>
 <option value="Monograph, Co-Author (100pts)">Monograph - Co-Author (100 pts)</option>
 <option value="Indexed Journal Article, Sole Author (50pts)">Indexed Journal Article - Sole Author (50 pts)</option>
 <option value="Indexed Journal Article, Co-Author (50pts)">Indexed Journal Article - Co-Author (50 pts)</option>
 <option value="Book Chapter, Sole Author (35pts)">Book Chapter - Sole Author (35 pts)</option>
 <option value="Book Chapter, Co-Author (35pts)">Book Chapter - Co-Author (35 pts)</option>
 <option value="Research Policy/Product, Lead (35pts)">Research -> Policy/Product - Lead (35 pts)</option>
 <option value="Research Policy/Product, Contributor (35pts)">Research -> Policy/Product - Contributor (35 pts)</option>
 <option value="Peer-Reviewed Scholarly Output (10pts)">Other Peer-Reviewed Output (10 pts)</option>
 <option value="Local Citation (5pts)">Local Citation (5 pts each)</option>
 <option value="International Citation (10pts)">International Citation (10 pts each)</option>
 </select>
 </div>
 <div class="fg [margin-bottom:.6rem]"><label class="fl text-[0.68rem] font-semibold text-slate-600 block [margin-bottom:3px]">Title / Journal Name</label><input type="text" class="fi w-full [padding:.4rem_.6rem] border border-slate-200 text-xs text-slate-800 bg-white [font-family:inherit] m-0 focus:outline-none focus:[border-color:#1a3a6b] resize-y [min-height:52px]" id="resTitle" placeholder="Publication title"></div>
 <div class="fg [margin-bottom:.6rem]"><label class="fl text-[0.68rem] font-semibold text-slate-600 block [margin-bottom:3px]">Contribution % (co-authorship)</label><input type="number" class="fi w-full [padding:.4rem_.6rem] border border-slate-200 text-xs text-slate-800 bg-white [font-family:inherit] m-0 focus:outline-none focus:[border-color:#1a3a6b] resize-y [min-height:52px]" id="resContrib" value="100" min="1" max="100" oninput="previewScore()"></div>

 <?php elseif ($cur_cat === 'Extension'): ?>
 <div class="fg [margin-bottom:.6rem]">
 <label class="fl text-[0.68rem] font-semibold text-slate-600 block [margin-bottom:3px]">Criterion / Type</label>
 <select class="fi w-full [padding:.4rem_.6rem] border border-slate-200 text-xs text-slate-800 bg-white [font-family:inherit] m-0 focus:outline-none focus:[border-color:#1a3a6b] resize-y [min-height:52px]" id="extType" onchange="updateExtOpts();previewScore()">
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
 <div class="fg [margin-bottom:.6rem]"><label class="fl text-[0.68rem] font-semibold text-slate-600 block [margin-bottom:3px]">Activity / Project / Designation Name</label><input type="text" class="fi w-full [padding:.4rem_.6rem] border border-slate-200 text-xs text-slate-800 bg-white [font-family:inherit] m-0 focus:outline-none focus:[border-color:#1a3a6b] resize-y [min-height:52px]" id="extTitle" placeholder="e.g. Community outreach program"></div>
 <div class="fg [margin-bottom:.6rem] hidden" id="extVal1Wrap"><label class="fl text-[0.68rem] font-semibold text-slate-600 block [margin-bottom:3px]" id="extVal1Label">Value</label><input type="number" class="fi w-full [padding:.4rem_.6rem] border border-slate-200 text-xs text-slate-800 bg-white [font-family:inherit] m-0 focus:outline-none focus:[border-color:#1a3a6b] resize-y [min-height:52px]" id="extVal1" value="" min="0" oninput="previewScore()"></div>
 <div class="fg [margin-bottom:.6rem] hidden" id="extVal2Wrap"><label class="fl text-[0.68rem] font-semibold text-slate-600 block [margin-bottom:3px]">Role</label><select class="fi w-full [padding:.4rem_.6rem] border border-slate-200 text-xs text-slate-800 bg-white [font-family:inherit] m-0 focus:outline-none focus:[border-color:#1a3a6b] resize-y [min-height:52px]" id="extVal2" onchange="previewScore()"><option value="lead">Lead</option><option value="co">Co/Member</option></select></div>

 <?php else: // Professional Development ?>
 <div class="fg [margin-bottom:.6rem]">
 <label class="fl text-[0.68rem] font-semibold text-slate-600 block [margin-bottom:3px]">Credential / Activity Type</label>
 <select class="fi w-full [padding:.4rem_.6rem] border border-slate-200 text-xs text-slate-800 bg-white [font-family:inherit] m-0 focus:outline-none focus:[border-color:#1a3a6b] resize-y [min-height:52px]" id="pdType" onchange="updatePdOpts();previewScore()">
 <option value="A-org">Active Professional Org Membership (5 pts)</option>
 <option value="B-training">Training / Conference</option>
 <option value="B-degree">Educational Qualification</option>
 <option value="B-paper">Paper Presentation</option>
 <option value="C-award">Award / Recognition</option>
 </select>
 </div>
 <div class="fg [margin-bottom:.6rem]"><label class="fl text-[0.68rem] font-semibold text-slate-600 block [margin-bottom:3px]">Description / Title</label><input type="text" class="fi w-full [padding:.4rem_.6rem] border border-slate-200 text-xs text-slate-800 bg-white [font-family:inherit] m-0 focus:outline-none focus:[border-color:#1a3a6b] resize-y [min-height:52px]" id="pdDesc" placeholder="e.g. PhD in Education"></div>
 <div class="fg [margin-bottom:.6rem] hidden" id="pdSubWrap">
 <label class="fl text-[0.68rem] font-semibold text-slate-600 block [margin-bottom:3px]">Value / Sub-type</label>
 <select class="fi w-full [padding:.4rem_.6rem] border border-slate-200 text-xs text-slate-800 bg-white [font-family:inherit] m-0 focus:outline-none focus:[border-color:#1a3a6b] resize-y [min-height:52px]" id="pdSubval" onchange="previewScore()"></select>
 </div>
 <?php endif; ?>

 <div class="fg [margin-bottom:.6rem]"><label class="fl text-[0.68rem] font-semibold text-slate-600 block [margin-bottom:3px]">Personal Notes (optional)</label><textarea class="fi w-full [padding:.4rem_.6rem] border border-slate-200 text-xs text-slate-800 bg-white [font-family:inherit] m-0 focus:outline-none focus:[border-color:#1a3a6b] resize-y [min-height:52px]" id="entryNotes" rows="2" placeholder="Your own notes..."></textarea></div>

 <div class="form-actions flex items-center justify-between mt-3 flex-wrap gap-2">
 <div class="score-preview text-xs font-bold text-brand-700 flex items-center gap-1 text-base"><i class="bi bi-calculator"></i>Est. score: <span id="scorePreview">0.00</span> pts</div>
 <div class="flex gap-1.5">
 <button class="btn-s bg-white text-brand-700 [border:1px_solid_#1a3a6b] [padding:.4rem_.9rem] text-xs font-semibold cursor-pointer hover:bg-brand-50" onclick="cancelForm()">Cancel</button>
 <button class="btn-p bg-brand-700 text-white border-0 [padding:.4rem_.9rem] text-xs font-semibold cursor-pointer hover:bg-blue-800" onclick="saveEntry()">Save Entry</button>
 </div>
 </div>
 </div>

 <!-- Sub-cap progress bars - hidden until get_score runs -->
 <?php
 // Sub-cap definitions per KRA (label, JS key into kra_detail, cap)
 $subcap_defs = [
 'Instruction' => [['A - Teaching Effectiveness','criterion_a',60],['B - Instructional Materials','criterion_b',30],['C - Research/Advisory','criterion_c',10]],
 'Research' => [['A - Books/Monographs','criterion_a_raw',null],['B - Articles/Chapters','criterion_b_raw',null],['C - Citations/Policy','criterion_c_raw',null]],
 'Extension' => [['A - Income','criterion_a',null],['B - MOA/Linkage','criterion_b',null],['C - Outreach','criterion_c',null],['D - Bonus','criterion_d_bonus',null]],
 'Professional Development' => [['A - Prof. Orgs','criterion_a',20],['B - Training/Degrees','criterion_b',60],['C - Awards','criterion_c',20],['D - Bonus','criterion_d_bonus',20]],
 ];
 $bars = $subcap_defs[$cur_cat] ?? [];
 // kra_detail key in get_score JSON
 $detail_key = ['Instruction'=>'kra1','Research'=>'kra2','Extension'=>'kra3','Professional Development'=>'kra4'][$cur_cat] ?? '';
 ?>
 <div id="subcapBars" class="hidden bg-slate-50 border border-slate-200 [border-top:none] [padding:.55rem_.85rem_.45rem] [margin-bottom:.6rem]">
 <div class="text-[0.58rem] font-bold text-slate-400 uppercase tracking-wider [margin-bottom:.4rem]">
 <i class="bi bi-bar-chart-line me-1"></i>Criterion breakdown
 <span class="font-normal [font-style:italic] [margin-left:.3rem]">(updates after each save)</span>
 </div>
 <div id="subcapList" class="flex flex-col gap-1">
 <?php foreach ($bars as [$lbl, $key, $cap]): ?>
 <div class="subcap-row" data-key="<?= $key ?>" data-cap="<?= $cap ?? '' ?>" data-detail="<?= $detail_key ?>">
 <div class="flex justify-between items-baseline [margin-bottom:1px]">
 <span class="text-[0.65rem] text-slate-600 font-semibold"><?= $lbl ?></span>
 <span class="subcap-pts text-[0.65rem] font-bold text-brand-700"> - </span>
 </div>
 <progress class="subcap-fill h-1 w-full overflow-hidden rounded accent-brand-700" value="0" max="100"></progress>
 </div>
 <?php endforeach; ?>
 </div>
 </div>

 <!-- Entry list -->
 <div class="entry-list flex flex-col [gap:.45rem]" id="entryList">
 <?php
 $es = ['Instruction'=>['bi-book-half',100,'Teaching effectiveness (SET/SEF), instructional materials, and thesis/capstone advisory work.'],
 'Research'=>['bi-journal-text',100,'Publications, book chapters, citations, and policy research outputs.'],
 'Extension'=>['bi-people-fill',100,'Community outreach programs, MOAs/linkages, and income-generating extension activities.'],
 'Professional Development'=>['bi-award-fill',100,'Professional org membership, training/conferences, paper presentations, degrees, and awards.']];
 $ei = $es[$cur_cat] ?? ['bi-inbox',0,''];
 ?>
 <div class="empty-state text-center [padding:2rem_1rem] text-slate-400 text-3xl block [margin-bottom:.4rem] text-xs m-0">
 <i class="bi <?= $ei[0] ?>"></i>
 <p>No entries yet. Click <strong>+ Add Entry</strong> to start.</p>
 <?php if ($ei[2]): ?>
 <p class="[margin-top:.35rem] text-[0.68rem] [color:#b0bec5] [max-width:240px] ml-auto mr-auto"><?= $ei[2] ?></p>
 <?php endif; ?>
 <?php if ($ei[1]): ?>
 <p class="[margin-top:.25rem] text-[0.65rem] [color:#bfdbfe] font-bold">Max: <?= $ei[1] ?> pts</p>
 <?php endif; ?>
 </div>
 </div>

 <input type="file" id="entryFileInput" accept=".pdf,.jpg,.jpeg,.png" onchange="attachFile(this)" class="hidden">
 </div>
 </div>

 <?php endif; // end autosubrank / posreq / kra panel ?>

 </div><!-- /panels -->
 <?php endif; // end self assessment view ?>
</div><!-- /main -->
</div><!-- /pg -->

<!-- -- Evidence Guide Drawer -- -->
<div class="drawer-overlay fixed inset-0 z-[999] hidden bg-slate-950/30" id="drawerOverlay" onclick="closeDrawer()"></div>
<div class="drawer fixed inset-y-0 right-0 z-[1000] flex w-full max-w-[400px] translate-x-full flex-col border-l border-slate-200 bg-white shadow-pop transition-transform duration-300" id="evidenceDrawer">

 <!-- Drawer header -->
 <div class="drawer-hd [padding:1rem_1.25rem] border-b border-slate-200 flex items-center justify-between shrink-0 bg-brand-700">
 <div>
 <div class="drawer-hd-title text-sm font-bold text-white">Evidence Guide</div>
 <div class="drawer-hd-sub text-[0.65rem] [color:rgba(255,255,255,.6)] mt-0.5">Required documents per criterion</div>
 </div>
 <button class="drawer-close bg-transparent border-0 [color:rgba(255,255,255,.7)] text-lg cursor-pointer p-0 leading-none hover:text-white" onclick="closeDrawer()" title="Close">&times;</button>
 </div>

 <!-- KRA filter tabs -->
 <div class="flex border-b border-slate-200 bg-slate-50 shrink-0">
 <button class="d-tab active [padding:.45rem_.75rem] border-0 bg-transparent text-xs font-semibold text-slate-500 cursor-pointer [border-bottom:2px_solid_transparent] [margin-bottom:-1px] hover:text-brand-700 [.tab-btn.active_&]:text-brand-700 [.tab-btn.active_&]:[border-bottom-color:#1a3a6b] [.tab-btn.active_&]:bg-white [.d-tab.active_&]:text-brand-700 [.d-tab.active_&]:[border-bottom-color:#1a3a6b]" data-cat="" onclick="switchDrawerTab(this,'')">All</button>
 <button class="d-tab [padding:.45rem_.75rem] border-0 bg-transparent text-xs font-semibold text-slate-500 cursor-pointer [border-bottom:2px_solid_transparent] [margin-bottom:-1px] hover:text-brand-700" data-cat="Instruction" onclick="switchDrawerTab(this,'Instruction')">KRA I</button>
 <button class="d-tab [padding:.45rem_.75rem] border-0 bg-transparent text-xs font-semibold text-slate-500 cursor-pointer [border-bottom:2px_solid_transparent] [margin-bottom:-1px] hover:text-brand-700" data-cat="Research" onclick="switchDrawerTab(this,'Research')">KRA II</button>
 <button class="d-tab [padding:.45rem_.75rem] border-0 bg-transparent text-xs font-semibold text-slate-500 cursor-pointer [border-bottom:2px_solid_transparent] [margin-bottom:-1px] hover:text-brand-700" data-cat="Extension" onclick="switchDrawerTab(this,'Extension')">KRA III</button>
 <button class="d-tab [padding:.45rem_.75rem] border-0 bg-transparent text-xs font-semibold text-slate-500 cursor-pointer [border-bottom:2px_solid_transparent] [margin-bottom:-1px] hover:text-brand-700" data-cat="Professional Development" onclick="switchDrawerTab(this,'Professional Development')">KRA IV</button>
 </div>

 <div class="drawer-body flex-1 overflow-y-auto [padding:.9rem_1rem]">
 <!-- Search -->
 <div class="drawer-search mb-3 relative absolute [left:.6rem] [top:50%] [transform:translateY(-50%)] text-slate-400 text-xs w-full [padding:.38rem_.6rem_.38rem_1.7rem] border border-slate-200 text-slate-800 bg-slate-50 [font-family:inherit] focus:outline-none focus:[border-color:#1a3a6b] focus:bg-white">
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
 'Instruction'=>'KRA I - Instruction','Research'=>'KRA II - Research',
 'Extension'=>'KRA III - Extension','Professional Development'=>'KRA IV - Prof. Development'
 ];
 $cc = $cat_colors[$cat] ?? '#1a3a6b';
 ?>
 <div class="crit-group mb-3" data-cat="<?= htmlspecialchars($cat) ?>">
 <div class="crit-group-label mb-1 px-0.5 py-1.5 text-[0.62rem] font-bold uppercase tracking-wider text-brand-700">
 <?= htmlspecialchars($cat_labels[$cat] ?? $cat) ?>
 </div>
 <?php foreach ($crits as $cr):
 $desc = trim($cr['description'] ?? '');
 $evidences = $desc
 ? array_filter(array_map('trim', preg_split('/\.\s+(?=[A-Z])/', $desc)))
 : [];
 ?>
 <div class="crit-item border border-slate-200 mb-2" data-label="<?= htmlspecialchars(strtolower($cr['criterion_label'])) ?>" data-cat="<?= htmlspecialchars($cat) ?>">
 <div class="crit-item-hd flex items-center justify-between [padding:.55rem_.75rem] cursor-pointer bg-slate-50 gap-2 hover:bg-brand-50" onclick="toggleCrit(this)">
 <span class="crit-label text-xs font-semibold text-slate-800 flex-1 leading-snug"><?= htmlspecialchars($cr['criterion_label']) ?></span>
 <span class="crit-pts shrink-0 whitespace-nowrap rounded-full border border-brand-200 bg-brand-50 px-2 py-0.5 text-xs font-bold text-brand-700"><?= number_format($cr['max_points'], 0) ?> pts</span>
 <i class="bi bi-chevron-right crit-chevron text-[0.65rem] text-slate-400 shrink-0 [transition:transform_.2s] [transform:rotate(90deg)]"></i>
 </div>
 <div class="crit-body hidden [padding:.6rem_.75rem] border-t border-slate-100">
 <?php if ($evidences): ?>
 <div class="crit-evidence-label text-[0.62rem] font-bold text-slate-400 uppercase tracking-wider [margin-bottom:.4rem]">Required Evidence</div>
 <?php foreach ($evidences as $ev): if (!trim($ev)) continue; ?>
 <div class="crit-evidence-item flex items-start gap-1.5 text-xs text-slate-600 leading-relaxed [margin-bottom:.3rem] [content:'*'] text-brand-700 font-bold shrink-0 [margin-top:1px]"><?= htmlspecialchars(rtrim($ev, '.')) ?>.</div>
 <?php endforeach; ?>
 <?php else: ?>
 <div class="text-xs text-slate-400">No evidence details available.</div>
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
const AJAX = '../../backend/api/pre_eval_ajax.php';
const CAT = <?= json_encode($cur_cat) ?>;
const KRA_NUM = <?= $kra_num ?>;
const EMPTY_STATE_INFO = <?= json_encode([
 'Instruction' => ['icon'=>'bi-book-half', 'max'=>100, 'desc'=>'Teaching effectiveness (SET/SEF ratings), instructional materials, and thesis/capstone advisory work.'],
 'Research' => ['icon'=>'bi-journal-text', 'max'=>100, 'desc'=>'Publications, book chapters, citations, and policy research outputs.'],
 'Extension' => ['icon'=>'bi-people-fill', 'max'=>100, 'desc'=>'Community outreach programs, MOAs/linkages, and income-generating extension activities.'],
 'Professional Development' => ['icon'=>'bi-award-fill', 'max'=>100, 'desc'=>'Professional org membership, training/conferences, paper presentations, degrees, and awards.'],
]) ?>;
let attachTarget = null;

// -- Nav dropdown ----------------------------------------------
function toggleDD(){document.getElementById('ndd').classList.toggle('hidden');}
document.addEventListener('click',e=>{
 const n=document.getElementById('nu'),d=document.getElementById('ndd');
 if(d&&n&&!n.contains(e.target))d.classList.add('hidden');
});

const ELIGIBLE = <?= $eligible ? 'true' : 'false' ?>;

// -- Add/cancel form -------------------------------------------
function toggleForm(){
 if (!ELIGIBLE) return; // server-side gate reflected in UI
 const f=document.getElementById('addForm');
 const open=!f.classList.contains('hidden');
 f.classList.toggle('hidden', open);
 document.getElementById('addBtn').innerHTML=open
 ?'<i class="bi bi-plus-lg"></i> Add Entry'
 :'<i class="bi bi-x"></i> Cancel';
 if(!open){resetForm();previewScore();}
}
function cancelForm(){
 document.getElementById('addForm').classList.add('hidden');
 document.getElementById('addBtn').innerHTML='<i class="bi bi-plus-lg"></i> Add Entry';
 resetForm();
}
function resetForm(){
 document.getElementById('editId').value='';
 const n=document.getElementById('entryNotes'); if(n)n.value='';
 const sp=document.getElementById('scorePreview'); if(sp)sp.textContent='0.00';
}

// -- Instruction form switch ------------------------------------
function switchInstrForm(){
 const t=document.getElementById('critType')?.value;
 document.getElementById('fA').classList.toggle('hidden', t!=='A-set-sef');
 document.getElementById('fB').classList.toggle('hidden', t!=='B-material');
 document.getElementById('fC').classList.toggle('hidden', t!=='C-thesis');
}

// -- ProfDev sub-options ----------------------------------------
const PD_OPTS={
 'B-training':[['1','1 pt - Local'],['2','2 pts - International']],
 'B-degree': [['10','10 pts - Post-Master\'s/Post-Doctoral Cert.'],['20','20 pts - Additional Master\'s']],
 'B-paper': [['3','3 pts - Local Presentation'],['5','5 pts - International Presentation']],
 'C-award': [['2','2 pts - Institutional'],['3','3 pts - Local'],['4','4 pts - Regional'],['0','National/International (+1 sub-rank, 0 pts)']],
};
function updatePdOpts(){
 const t=document.getElementById('pdType')?.value;
 const w=document.getElementById('pdSubWrap');
 const s=document.getElementById('pdSubval');
 if(!t||!w||!s)return;
 if(t==='A-org'){w.classList.add('hidden');return;}
 const opts=PD_OPTS[t]||[];
 s.innerHTML=opts.map(([v,l])=>`<option value="${v}">${l}</option>`).join('');
 w.classList.remove('hidden');
}

function updateExtOpts(){
 const t=document.getElementById('extType')?.value||'';
 const v1w=document.getElementById('extVal1Wrap');
 const v2w=document.getElementById('extVal2Wrap');
 const v1=document.getElementById('extVal1');
 const v1l=document.getElementById('extVal1Label');
 if(!v1w||!v2w||!v1||!v1l)return;
 v1w.classList.add('hidden');
 v2w.classList.add('hidden');
 v1.value='';
 if(t==='income'){
 v1l.textContent='Income Generated';
 v1.placeholder='Amount';
 v1w.classList.remove('hidden');
 v2w.classList.remove('hidden');
 }else if(t==='csr-satisfaction'){
 v1l.textContent='CSR Rating (0-100)';
 v1.placeholder='Rating';
 v1w.classList.remove('hidden');
 }
}

// -- Build remarks ----------------------------------------------
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

// -- Score preview ----------------------------------------------
function previewScore(){
 const r=buildRemarks();
 if(!r){document.getElementById('scorePreview').textContent='0.00';return;}
 fetch(AJAX,{method:'POST',body:new URLSearchParams({action:'preview_score',cat:CAT,remarks:r})})
 .then(x=>x.json()).then(d=>{
 document.getElementById('scorePreview').textContent=d.ok?d.points.toFixed(2):'?';
 }).catch(()=>{});
}

// -- Save entry -------------------------------------------------
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

// -- Load entries -----------------------------------------------
function loadEntries(){
 return fetch(`${AJAX}?action=get_entries&cat=${encodeURIComponent(CAT)}`)
 .then(x=>x.json()).then(d=>{
 const el=document.getElementById('entryList');
 if(!el)return d;
 if(!d.ok||!d.entries.length){
 const info = EMPTY_STATE_INFO[CAT] || {};
 const icon = info.icon || 'bi-inbox';
 const max = info.max || 0;
 const desc = info.desc || '';
 const html = `<div class="empty-state text-center [padding:2rem_1rem] text-slate-400 text-3xl block [margin-bottom:.4rem] text-xs m-0">
 <i class="bi ${icon}"></i>
 <p>No entries yet. Click <strong>+ Add Entry</strong> to start.</p>
 ${desc ? `<p class="[margin-top:.35rem] text-[0.68rem] [color:#b0bec5] [max-width:240px] ml-auto mr-auto">${desc}</p>` : ''}
 ${max ? `<p class="[margin-top:.25rem] text-[0.65rem] [color:#bfdbfe] font-bold">Max: ${max} pts</p>` : ''}
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
 const notes=e.notes?`<div class="entry-notes text-[0.65rem] text-slate-400 mt-0.5">${esc(e.notes)}</div>`:'';
 const files=(e.files||[]).map(f=>`
 <a href="../../${f.file_path}" target="_blank" class="file-chip inline-flex items-center gap-1 bg-slate-100 border border-slate-200 [padding:2px_7px] text-[0.65rem] text-slate-600 [max-width:160px] overflow-hidden text-ellipsis whitespace-nowrap no-underline hover:bg-slate-200" title="${esc(f.original_filename)}">
 <i class="bi bi-paperclip text-[0.6rem]"></i>${esc(f.original_filename.length>20?f.original_filename.slice(0,20)+'...':f.original_filename)}
 </a>
 <button class="chip-del bg-transparent border-0 text-slate-400 cursor-pointer p-0 text-[0.65rem] leading-none [margin-left:1px] hover:text-slate-800" onclick="delFile(event,${f.file_id})" title="Remove">x</button>
 `).join('');
 return `<div class="entry-card border border-slate-200" id="ec-${e.entry_id}">
 <div class="entry-card-top flex items-start justify-between [padding:.6rem_.8rem_.4rem] gap-2 max-[600px]:[padding:.5rem_.65rem_.3rem]">
 <div><div class="entry-label text-xs font-semibold text-slate-800 [line-height:1.4] flex-1">${lbl}</div>${notes}</div>
 <div class="entry-pts text-base font-extrabold text-brand-700 whitespace-nowrap shrink-0">${parseFloat(e.computed_points).toFixed(2)}</div>
 </div>
 ${files?`<div class="entry-files [padding:0_.8rem_.4rem] flex flex-wrap gap-1 max-[600px]:[padding:0_.65rem_.35rem]">${files}</div>`:''}
 <div class="entry-actions [padding:0_.8rem_.6rem] flex gap-1 max-[600px]:[padding:0_.65rem_.5rem]">
 <button class="btn-xs btn-attach [padding:2px_8px] [border:1px_solid] text-[0.65rem] font-semibold cursor-pointer bg-white [border-color:#1a3a6b] text-brand-700 hover:bg-brand-700 hover:text-white" onclick="trigAttach(${e.entry_id})"><i class="bi bi-paperclip"></i> Attach</button>
 <button class="btn-xs btn-rm [padding:2px_8px] [border:1px_solid] text-[0.65rem] font-semibold cursor-pointer bg-white [border-color:#94a3b8] text-slate-800 hover:bg-slate-50" onclick="delEntry(${e.entry_id})"><i class="bi bi-trash"></i> Remove</button>
 </div>
 </div>`;
}

function fmtLabel(r){
 const p=r.split('|||');
 const t=p[0]||'';
 if(t==='A-set-sef') return `<strong>Teaching Evaluation</strong> - SET: ${p[1]||0}%, SEF: ${p[2]||0}%`;
 if(t==='A-set-sem'||t==='A-sef-sem') return `<strong>${t==='A-sef-sem'?'SEF':'SET'} Teaching Evaluation</strong> - ${esc(p[1]||'')}, ${p[2]==='2'?'2nd Semester':'1st Semester'}: ${p[3]||0}%`;
 if(t==='B-material') return `<strong>Material:</strong> ${esc(p[1]||'')}`;
 if(t==='C-thesis') return `<strong>Thesis/Dissertation:</strong> ${esc(p[1]||'')}`;
 if(['moa-linkage','income','accredit-local','accredit-intl','resource-speaker-local','resource-speaker-intl','outreach-isr-lead','outreach-isr-member','csr-satisfaction','consultant-local','consultant-intl','judge-research','judge-other'].includes(t)){
 const value = p[2] ? ` - ${esc(p[2])}${t==='csr-satisfaction'?'%':''}` : '';
 return `<strong>${esc(t.replaceAll('-',' '))}</strong>${p[1]?' - '+esc(p[1]):''}${value}`;
 }
 if(t==='A-org') return `<strong>Professional Org Membership</strong>`;
 if(t.startsWith('B-')||t.startsWith('C-')) return `<strong>${esc(p[1]||t)}</strong>${p[2]?' - '+esc(p[2])+'pts':''}`;
 // Research / Extension
 return `<strong>${esc(p[0])}</strong>${p[1]?' - '+esc(p[1]):''}`;
}

// -- Attach file to entry ---------------------------------------
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
 sv.style.color=d.weighted>=41?'#1e4d8c':d.weighted>0?'#475569':'#94a3b8';
 }
 const mn=document.getElementById('sbMinNote');
 if(mn){
 mn.textContent=d.weighted>=41?'OK Meets minimum (41)':'X Below minimum (41)';
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
 const bar=row.querySelector('.kra-bar');
 const rawTxt=row.querySelector('.kra-row-raw');
 if(pts) pts.textContent=wpts.toFixed(1)+'pt';
 if(bar) bar.value=pct;
 if(rawTxt) rawTxt.textContent=raw.toFixed(1)+'/'+mx+' | '+(WEIGHTS[i]||0)+'% wt';
 });

 // Update rank projection + increment badge
 const potEl = document.getElementById('sbPotRank');
 const incWrap = document.querySelector('.rank-box');
 if (incWrap) {
 const curRankEl = document.getElementById('sbCurRank');
 const curRank = curRankEl ? curRankEl.textContent.trim() : '';
 const potRank = d.pot_rank || '';
 if (potEl) potEl.textContent = potRank;

 // Show/hide arrow + pot rank
 const arrow = incWrap.querySelector('.rank-arrow');
 if (arrow) arrow.style.display = (potRank && potRank !== curRank && potRank !== ' - ') ? '' : 'none';
 if (potEl) potEl.style.display = (potRank && potRank !== curRank && potRank !== ' - ') ? '' : 'none';

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
 badge.innerHTML = label+' <i class="bi bi-question-circle text-[0.58rem] [opacity:.65] [margin-left:2px]"></i>';
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
 const bars = document.querySelectorAll('.subcap-row');
 const barsWrap = document.getElementById('subcapBars');
 if (bars.length && d.kra_detail) {
 if (barsWrap) barsWrap.classList.remove('hidden');
 bars.forEach(row => {
 const detailKey = row.dataset.detail;
 const field = row.dataset.key;
 const cap = parseFloat(row.dataset.cap) || 0;
 const detail = d.kra_detail?.[detailKey] || {};
 const val = parseFloat(detail[field] ?? 0);

 const ptsEl = row.querySelector('.subcap-pts');
 const fillEl = row.querySelector('.subcap-fill');

 if (ptsEl) {
 ptsEl.textContent = cap
 ? `${val.toFixed(1)} / ${cap}`
 : val.toFixed(1);
 ptsEl.style.color = (cap && val >= cap) ? '#dc2626' : '#1a3a6b';
 }
 if (fillEl && cap) {
 const pct = Math.min(100, Math.round((val / cap) * 100));
 fillEl.value = pct;
 fillEl.classList.toggle('accent-rose-600', pct >= 100);
 fillEl.classList.toggle('accent-brand-700', pct < 100);
 } else if (fillEl) {
 fillEl.value = Math.min(100, val);
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
 gapEl.style.color = '#c2410c';
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
 document.getElementById('evidenceDrawer').classList.remove('translate-x-full');
 document.getElementById('drawerOverlay').classList.remove('hidden');
 document.body.style.overflow='hidden';
 // Pre-select current KRA tab
 const tabBtn = document.querySelector(`.d-tab[data-cat="${CAT}"]`);
 if(tabBtn) switchDrawerTab(tabBtn, CAT);
}
function closeDrawer(){
 document.getElementById('evidenceDrawer').classList.add('translate-x-full');
 document.getElementById('drawerOverlay').classList.add('hidden');
 document.body.style.overflow='';
}
function switchDrawerTab(btn, cat){
 drawerCat = cat;
 document.querySelectorAll('.d-tab').forEach(b=>b.classList.remove('active'));
 btn.classList.add('active');
 filterCriteria();
}
function toggleCrit(hd){
 const body = hd.closest('.crit-item').querySelector('.crit-body');
 if (body) body.classList.toggle('hidden');
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

// -- Init -------------------------------------------------------
<?php if($cur_cat==='Instruction'): ?>switchInstrForm();<?php endif; ?>
<?php if($cur_cat==='Extension'): ?>updateExtOpts();<?php endif; ?>
<?php if($cur_cat==='Professional Development'): ?>updatePdOpts();<?php endif; ?>
function selfAssessmentFormOpen(){
 return !document.getElementById('addForm')?.classList.contains('hidden');
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
 document.getElementById('peConfirmIcon').className = 'bi ' + icon;
 document.getElementById('peConfirmIcon').style.color = color;
 document.getElementById('peConfirmIconWrap').style.background = color + '18';
 document.getElementById('peConfirmTitle').textContent = title;
 document.getElementById('peConfirmMsg').innerHTML = msg;
 document.getElementById('peConfirmBtn').textContent = btnLabel;
 document.getElementById('peConfirmBtn').style.background = color;
 // Rebind confirm button to avoid stacking listeners
 const fresh = document.getElementById('peConfirmBtn').cloneNode(true);
 document.getElementById('peConfirmBtn').replaceWith(fresh);
 fresh.style.background = color;
 fresh.addEventListener('click', () => { m.classList.add('hidden'); document.body.classList.remove('overflow-hidden'); onConfirm(); });
 m.classList.remove('hidden');
 m.classList.add('flex');
 document.body.style.overflow = 'hidden';
}
document.addEventListener('DOMContentLoaded', () => {
 const m = document.getElementById('peConfirmModal');
 if (!m) return;
 document.getElementById('peConfirmCancelBtn')
 .addEventListener('click', () => { m.classList.add('hidden'); document.body.classList.remove('overflow-hidden'); });
 m.addEventListener('click', e => { if (e.target === m) { m.classList.add('hidden'); document.body.classList.remove('overflow-hidden'); } });
});
</script>

<!-- -- In-page Confirm Modal ----------------------------------- -->
<div id="peConfirmModal"
 class="fixed inset-0 z-[99999] hidden items-center justify-center overflow-hidden bg-slate-950/50 p-4">
 <div class="bg-white rounded-2xl w-full [max-width:400px] [box-shadow:0_24px_64px_rgba(0,0,0,0.28)] overflow-hidden [max-height:90vh]">

 <!-- Header -->
 <div class="bg-brand-700 [padding:.85rem_1.25rem] flex items-center [gap:.65rem]">
 <img src="../assets/images/logo.jpg" alt=""
 class="[width:32px] [height:32px] rounded-full object-cover [border:2px_solid_rgba(255,255,255,.25)] shrink-0">
 <div>
 <div class="text-white font-bold text-sm leading-tight">SUCFRMS</div>
 <div class="[color:rgba(255,255,255,.5)] text-[0.65rem]">Self-Assessment</div>
 </div>
 </div>

 <!-- Body -->
 <div class="[padding:1.5rem_1.5rem_1rem] text-center">
 <div id="peConfirmIconWrap"
 class="[width:56px] [height:56px] rounded-full inline-flex items-center justify-center [margin-bottom:.85rem]">
 <i id="peConfirmIcon" class="bi bi-trash text-2xl"></i>
 </div>
 <div id="peConfirmTitle"
 class="[font-size:.97rem] font-bold text-slate-900 [margin-bottom:.4rem]"></div>
 <div id="peConfirmMsg"
 class="text-sm text-slate-500 [line-height:1.55]"></div>
 </div>

 <!-- Divider -->
 <div class="[height:1px] bg-slate-100 [margin:0_1.25rem]"></div>

 <!-- Buttons -->
 <div class="[padding:.85rem_1.25rem_1.25rem] flex gap-2">
 <button id="peConfirmCancelBtn"
 class="flex-1 [padding:.6rem] [border:1.5px_solid_#e2e8f0] rounded-lg bg-white text-slate-600 font-semibold text-sm cursor-pointer [transition:background_.12s]">
 Cancel
 </button>
 <button id="peConfirmBtn"
 class="flex-1 [padding:.6rem] border-0 rounded-lg text-white font-bold text-sm cursor-pointer [transition:opacity_.12s]">
 Confirm
 </button>
 </div>

 </div>
</div>

</body>
</html>
