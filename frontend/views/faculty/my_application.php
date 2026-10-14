<?php if ((require __DIR__ . '/../../../backend/controllers/faculty/my_application.php') === false) return; ?>



<div id="app-status" class="[scroll-margin-top:80px]"></div>
<div class="neon-card p-0 mb-4 overflow-hidden">
 <!-- Header bar -->
 <div class="bg-blue-800 [padding:1.25rem_1.5rem] flex justify-between items-center flex-wrap [gap:0.75rem]">
 <div class="d-flex align-items-center gap-4">
 <div class="[width:44px] [height:44px] rounded-xl [background:rgba(255,255,255,0.15)] flex items-center justify-center shrink-0">
 <i class="bi bi-file-earmark-text text-xl text-white"></i>
 </div>
 <div>
 <div class="text-base font-bold text-white leading-tight">My Application</div>
 <div class="[font-size:0.8rem] [color:#bfdbfe] mt-0.5"><?= sanitize($cycle['cycle_name']) ?></div>
 </div>
 </div>
 <?= statusBadge($app['status']) ?>
 </div>
 <!-- Info row -->
 <div class="[padding:1rem_1.5rem] flex flex-wrap gap-6 items-center border-b border-slate-100">
 <div class="flex flex-wrap gap-6 items-center flex-1 min-w-0">
 <?php if ($app['submitted_at']): ?>
 <div>
 <div class="[font-size:0.68rem] uppercase [letter-spacing:0.07em] text-slate-400 font-semibold [margin-bottom:3px]">Submitted</div>
 <div class="[font-size:0.88rem] font-semibold text-slate-800"><?= date('M d, Y', strtotime($app['submitted_at'])) ?></div>
 </div>
 <?php endif; ?>
 <?php if (!empty($app['weighted_score'])): ?>
 <div>
 <div class="[font-size:0.68rem] uppercase [letter-spacing:0.07em] text-slate-400 font-semibold [margin-bottom:3px]">Weighted Score <?= helpBtn('Weighted Score', 'This is your total score computed using KRA weights: Instruction (50%), Research (20%), Extension (20%), Professional Development (10%). You need at least 41.00 to qualify for reclassification.') ?></div>
 <div class="[font-size:0.88rem] font-bold text-blue-800"><?= number_format($app['weighted_score'], 2) ?></div>
 </div>
 <?php endif; ?>
 </div>
 <?php if ($can_edit): ?>
 <div class="shrink-0">
 <a href="?page=apply" class="btn btn-primary btn-sm font-semibold [padding:0.45rem_1.1rem] whitespace-nowrap">
 <i class="bi bi-pencil-square me-1"></i>Edit Application
 </a>
 </div>
 <?php endif; ?>
 </div>
</div>

<?php if ($app['checker_remarks']): ?>
<!-- Remarks -->
<div class="neon-card mb-4 [padding:1rem_1.5rem]">
 <?php $status = $app['status']; ?>
 <?php if ($status === 'admin_rejected'): ?>
 <div class="d-flex gap-2 align-items-start">
 <i class="bi bi-x-circle-fill text-slate-800 text-base mt-0.5 shrink-0"></i>
 <div>
 <div class="[font-size:0.72rem] font-bold text-slate-800 uppercase [letter-spacing:0.05em] [margin-bottom:3px]">Evaluation Complete</div>
 <div class="[font-size:0.88rem] text-slate-800"><?= sanitize($app['checker_remarks']) ?></div>
 <div class="[font-size:0.78rem] text-slate-800 [margin-top:6px]"><i class="bi bi-info-circle me-1"></i>This result is for further committee review and is not a final decision.</div>
 </div>
 </div>
 <?php elseif ($status === 'needs_revision'): ?>
 <div class="d-flex gap-2 align-items-start">
 <i class="bi bi-exclamation-triangle-fill text-slate-600 text-base mt-0.5 shrink-0"></i>
 <div>
 <div class="[font-size:0.72rem] font-bold text-slate-600 uppercase [letter-spacing:0.05em] [margin-bottom:3px]">Evaluator Note</div>
 <div class="[font-size:0.88rem] text-slate-800"><?= sanitize($app['checker_remarks']) ?></div>
 <div class="[font-size:0.78rem] text-slate-500 [margin-top:6px]">Edit only the flagged entries below, then resubmit.</div>
 </div>
 </div>
 <?php elseif ($status === 'rejected'): ?>
 <div class="d-flex gap-2 align-items-start">
 <i class="bi bi-arrow-counterclockwise text-slate-600 text-base mt-0.5 shrink-0"></i>
 <div>
 <div class="[font-size:0.72rem] font-bold text-slate-600 uppercase [letter-spacing:0.05em] [margin-bottom:3px]">Returned for Revision</div>
 <div class="[font-size:0.88rem] text-slate-800"><?= sanitize($app['checker_remarks']) ?></div>
 <div class="mt-2"><a href="?page=apply" class="btn btn-sm btn-warning font-semibold">Edit &amp; Resubmit</a></div>
 </div>
 </div>
 <?php else: ?>
 <div class="d-flex gap-2 align-items-start">
 <i class="bi bi-chat-left-text text-brand-700 text-base mt-0.5 shrink-0"></i>
 <div>
 <div class="[font-size:0.72rem] font-bold text-brand-700 uppercase [letter-spacing:0.05em] [margin-bottom:3px]">Remarks</div>
 <div class="[font-size:0.88rem] text-slate-800"><?= sanitize($app['checker_remarks']) ?></div>
 </div>
 </div>
 <?php endif; ?>
</div>
<?php endif; ?>

<!-- Recommended Rank -->
<div class="neon-card p-0 mb-4 overflow-hidden">
 <div class="[padding:0.85rem_1.5rem] border-b border-slate-100 flex items-center justify-between gap-4 flex-wrap">
 <div class="d-flex align-items-center gap-2">
 <i class="bi bi-award-fill text-blue-800 text-lg"></i>
 <div>
 <div class="[font-size:0.9rem] font-bold text-brand-700">Recommended Rank / Sub-rank <?= helpBtn('Recommended Rank', 'Based on your weighted score, this shows the recommended rank for committee review. A score of 41-50 = +1 sub-rank, 51-60 = +2, 61-70 = +3, 71-80 = +4, 81-90 = +5, 91-100 = +6. An automatic +1 extra is given for earning a doctorate during the cycle.') ?></div>
 <div class="[font-size:0.72rem] text-slate-400">Recommendation only, based on DBM-CHED Joint Circular No. 3, s. 2022</div>
 </div>
 </div>
 <span class="badge bg-secondary [font-size:0.68rem]"><i class="bi bi-lock-fill me-1"></i>System-computed</span>
 </div>
 <div class="[padding:1rem_1.5rem] flex flex-wrap gap-4 items-center">
 <div class="flex-1 [min-width:140px] bg-slate-50 border border-slate-200 rounded-lg [padding:0.75rem_1rem]">
 <div class="[font-size:0.68rem] uppercase [letter-spacing:0.07em] text-slate-400 font-semibold [margin-bottom:4px]">Current Rank</div>
 <div class="[font-size:0.95rem] font-bold text-brand-700"><?= sanitize($faculty_current_rank ?: '-') ?></div>
 </div>
 <div class="text-slate-300 text-xl shrink-0">&#8594;</div>
 <div class="flex-1 [min-width:140px] bg-brand-50 border border-blue-200 rounded-lg [padding:0.75rem_1rem]">
 <div class="[font-size:0.68rem] uppercase [letter-spacing:0.07em] text-slate-400 font-semibold [margin-bottom:4px]">Recommended Rank</div>
 <?php if ($potential_data_faculty['potential_rank'] === ' - ' || empty($raw_kra_faculty)): ?>
 <div class="[font-size:0.85rem] text-slate-400">- no submissions yet</div>
 <?php else: ?>
 <div class="[font-size:0.95rem] font-bold text-blue-800">
 <?= sanitize($potential_data_faculty['potential_rank']) ?>
 <?php if ($potential_data_faculty['crossed_category']): ?>
 <span class="badge bg-warning text-dark ms-1 [font-size:0.6rem] align-middle">Category crossed</span>
 <?php endif; ?>
 </div>
 <?php endif; ?>
 </div>
 <?php if (!empty($raw_kra_faculty) && $potential_data_faculty['recomputed_score'] !== null): ?>
 <div class="flex-1 [min-width:120px] bg-brand-50 border border-blue-100 rounded-lg [padding:0.75rem_1rem]">
 <div class="[font-size:0.68rem] uppercase [letter-spacing:0.07em] text-slate-400 font-semibold [margin-bottom:4px]">Re-computed Score</div>
 <div class="[font-size:0.95rem] font-bold text-brand-700"><?= number_format($potential_data_faculty['recomputed_score'], 2) ?></div>
 </div>
 <?php endif; ?>
 </div>
 <?php if (!empty($potential_data_faculty['flags'])): ?>
 <div class="[padding:0_1.5rem_0.85rem] flex flex-wrap [gap:0.4rem]">
 <?php foreach ($potential_data_faculty['flags'] as $flag):
 $fc = '#64748b'; $fb = '#f1f5f9';
 if (str_contains($flag, 'EAC')) { $fc = '#1e293b'; $fb = '#f8fafc'; }
 if (str_contains($flag, 'CC')) { $fc = '#334155'; $fb = '#f1f5f9'; }
 if (str_contains($flag, 'crossed')) { $fc = '#1a3a6b'; $fb = '#f0f4fb'; }
 ?>
 <span class="inline-flex rounded bg-slate-100 px-2.5 py-1 text-xs font-semibold text-slate-600">
 <i class="bi bi-info-circle me-1"></i><?= sanitize($flag) ?>
 </span>
 <?php endforeach; ?>
 </div>
 <?php endif; ?>
 <div class="[padding:0_1.5rem_1rem] [font-size:0.75rem] text-slate-500">
 <i class="bi bi-info-circle me-1"></i>This result is for further committee review and is not a final decision.
 </div>
</div>

<!-- KRA Submissions -->
<div class="neon-card p-0 overflow-hidden">
 <div class="[padding:0.85rem_1.5rem] border-b border-slate-100 flex items-center justify-between flex-wrap [gap:0.75rem]">
 <div>
 <div class="[font-size:0.9rem] font-bold text-brand-700"><i class="bi bi-list-check me-2"></i>KRA Submissions <?= helpBtn('KRA Submissions', 'These are your Key Result Area entries. Each entry needs a score and an evidence file uploaded. You must submit evidence for every entry before submitting your application. Click "Add / Edit Entries" to add or modify your KRA scores.') ?></div>
 <div class="[font-size:0.75rem] text-slate-400 [margin-top:1px]">Your Key Result Areas documentation and evidence</div>
 </div>
 <?php if ($can_edit): ?>
 <a href="?page=apply" class="btn btn-primary btn-sm font-semibold [padding:0.45rem_1.1rem]">
 <i class="bi bi-plus-circle me-1"></i>Add / Edit Entries
 </a>
 <?php else: ?>
 <span class="badge bg-secondary [font-size:0.75rem] [padding:0.4rem_0.8rem]"><i class="bi bi-lock me-1"></i>Locked</span>
 <?php endif; ?>
 </div>
 <?php if ($subs):
 $kra_order = ['Instruction','Research','Extension','Professional Development'];
 $subs_by_kra = [];
 foreach ($subs as $s) $subs_by_kra[$s['kra_category']][] = $s;
 foreach ($subs as $s) {
 if (!in_array($s['kra_category'], $kra_order)) $kra_order[] = $s['kra_category'];
 }
 $kra_order = array_unique($kra_order);
 $kra_short_map = ['Instruction'=>'KRA I','Research'=>'KRA II','Extension'=>'KRA III','Professional Development'=>'KRA IV'];
 ?>

 <?php foreach ($kra_order as $ki => $kra_cat):
 if (empty($subs_by_kra[$kra_cat])) continue;
 $short = $kra_short_map[$kra_cat] ?? $kra_cat;
 $count = count($subs_by_kra[$kra_cat]);
 $has_revision = false;
 foreach ($subs_by_kra[$kra_cat] as $s) {
 if (($s['revision_status'] ?? 'ok') === 'needs_revision') { $has_revision = true; break; }
 }
 $sec_id = 'krasec-' . $ki;
 ?>
 <!-- KRA <?= $ki+1 ?> collapsible section -->
 <div class="border-b border-slate-200">
 <!-- Clickable header -->
 <div onclick="toggleKraSection('<?= $sec_id ?>')"
 id="<?= $sec_id ?>-hdr"
 class="flex cursor-pointer select-none items-center justify-between bg-slate-50 px-5 py-2.5 transition-colors hover:bg-brand-50">
 <div class="flex items-center [gap:0.55rem]">
 <?php if ($has_revision): ?>
 <i class="bi bi-exclamation-circle-fill text-amber-600 [font-size:0.78rem]"></i>
 <?php else: ?>
 <i class="bi bi-check-circle-fill text-emerald-700 [font-size:0.78rem]"></i>
 <?php endif; ?>
 <span class="[font-size:0.8rem] font-bold text-brand-700"><?= $short ?> — <?= htmlspecialchars($kra_cat) ?></span>
 <span class="[background:#e0e7ff] [color:#3730a3] rounded-full [padding:1px_8px] [font-size:0.65rem] font-bold"><?= $count ?></span>
 </div>
 <i class="bi bi-chevron-down" id="<?= $sec_id ?>-chev"
 class="rotate-180 text-xs text-slate-400 transition-transform"></i>
 </div>

 <!-- Collapsible content -->
 <div id="<?= $sec_id ?>" class="block">
 <div class="table-responsive">
 <table class="w-full [border-collapse:collapse] [font-size:0.83rem]">
 <thead>
 <tr class="bg-white border-b border-slate-200">
 <th class="[padding:0.5rem_1rem] [font-size:0.65rem] font-bold uppercase [letter-spacing:0.05em] text-slate-400 [width:105px] text-center">Score</th>
 <th class="[padding:0.5rem_0.75rem] [font-size:0.65rem] font-bold uppercase [letter-spacing:0.05em] text-slate-400">Remarks</th>
 <th class="[padding:0.5rem_0.75rem] [font-size:0.65rem] font-bold uppercase [letter-spacing:0.05em] text-slate-400 [width:150px]">Evidence</th>
 <th class="[padding:0.5rem_0.75rem] [font-size:0.65rem] font-bold uppercase [letter-spacing:0.05em] text-slate-400 [width:90px] text-center">Status</th>
 <th class="[padding:0.5rem_0.75rem] [font-size:0.65rem] font-bold uppercase [letter-spacing:0.05em] text-slate-400 [width:85px]">Date</th>
 <th class="[padding:0.5rem_0.75rem] [font-size:0.65rem] font-bold uppercase [letter-spacing:0.05em] text-slate-400 [width:80px]">Appeal</th>
 <?php if ($can_edit): ?>
 <th class="[padding:0.5rem_0.75rem] [width:55px]"></th>
 <?php endif; ?>
 </tr>
 </thead>
 <tbody>
 <?php foreach ($subs_by_kra[$kra_cat] as $s): ?>
 <tr class="border-b border-slate-100 <?= ($s['revision_status']??'ok')==='needs_revision' ? 'bg-amber-50' : '' ?>">
 <td class="[padding:0.65rem_1rem] text-center align-middle">
 <?php
 $fac_cat = $s['kra_category'];
 $fac_raw = (float)$s['computed_points'];
 $fac_cap = $kra_caps_fac[$fac_cat] ?? 100;
 $fac_weight = $kra_weights_fac[$fac_cat] ?? 0;
 $fac_cat_raw = $kra_raw_totals_fac[$fac_cat] ?? 0;
 $fac_capped = min($fac_cap, $fac_cat_raw);
 $fac_share = $fac_cat_raw > 0 ? ($fac_raw / $fac_cat_raw) : 0;
 $fac_weighted = round($fac_share * $fac_capped * $fac_weight, 2);
 ?>
 <span class="bg-brand-700 text-white [padding:0.2rem_0.55rem] rounded-md font-bold [font-size:0.8rem] inline-block">
 <?= number_format($fac_weighted, 2) ?>
 </span>
 </td>
 <td class="[padding:0.65rem_0.75rem] text-slate-600 [font-size:0.82rem] align-middle">
 <?= formatKraRemarks($s['kra_category'], $s['remarks'] ?? '') ?>
 </td>
 <td class="[padding:0.65rem_0.75rem] align-middle">
 <?php if (!empty($s['evidence_files'])): ?>
 <div class="d-flex flex-wrap gap-1">
 <?php foreach ($s['evidence_files'] as $fi): ?>
 <a href="backend/api/view_file.php?file=<?= urlencode($fi['file_path']) ?>" target="_blank" rel="noopener noreferrer"
 title="<?= htmlspecialchars($fi['original_filename']) ?>"
 class="inline-flex items-center gap-1 whitespace-nowrap rounded border border-blue-100 bg-brand-50 px-2 py-1 text-xs text-brand-700 no-underline">
 <i class="bi bi-file-earmark"></i><?= htmlspecialchars(substr($fi['original_filename'], 0, 12) . (strlen($fi['original_filename']) > 12 ? '...' : '')) ?>
 </a>
 <?php endforeach; ?>
 </div>
 <?php else: ?>
 <span class="text-slate-300 [font-size:0.8rem]">—</span>
 <?php endif; ?>
 </td>
 <td class="[padding:0.65rem_0.75rem] text-center align-middle">
 <?php if (($s['revision_status'] ?? 'ok') === 'needs_revision'): ?>
 <span class="[font-size:0.68rem] [padding:0.2rem_0.5rem] rounded bg-orange-50 text-orange-700 [border:1px_solid_#fed7aa] font-bold whitespace-nowrap">
 <i class="bi bi-exclamation-triangle-fill"></i> Revision
 </span>
 <?php if (!empty($s['revision_note'])): ?>
 <div class="[font-size:0.65rem] [color:#92400e] mt-0.5"><?= sanitize($s['revision_note']) ?></div>
 <?php endif; ?>
 <?php elseif ($s['verified']): ?>
 <span class="[font-size:0.68rem] [padding:0.2rem_0.5rem] rounded bg-blue-100 text-blue-800 border border-blue-200 font-semibold whitespace-nowrap">
 <i class="bi bi-check-circle"></i> OK
 </span>
 <?php else: ?>
 <span class="text-slate-300 [font-size:0.78rem]">—</span>
 <?php endif; ?>
 </td>
 <td class="[padding:0.65rem_0.75rem] text-slate-400 [font-size:0.76rem] align-middle whitespace-nowrap">
 <?= date('M d, Y', strtotime($s['submitted_at'])) ?>
 </td>
 <td class="[padding:0.65rem_0.75rem] align-middle">
 <?php
 $sid = (int)$s['submission_id'];
 $score_changed = isset($s['faculty_original_score']) && $s['faculty_original_score'] !== null && (float)$s['faculty_original_score'] !== (float)$s['computed_points'];
 $flagged = ($s['revision_status'] ?? 'ok') === 'needs_revision';
 $open_appeal = null;
 foreach ($appeals_by_submission[$sid] ?? [] as $apx) {
 if (in_array($apx['status'], ['open','under_review'], true)) { $open_appeal = $apx; break; }
 }
 $latest_appeal = $appeals_by_submission[$sid][0] ?? null;
 $appealable = ($flagged || $score_changed) && $appeals_allowed && !$open_appeal && (int)($s['revision_by'] ?: $s['verified_by']) > 0;
 ?>
 <?php if ($open_appeal): ?>
 <a href="index.php?page=appeals&id=<?= (int)$open_appeal['appeal_id'] ?>" class="btn btn-sm btn-outline-primary text-xs">Open</a>
 <?php elseif ($appealable): ?>
 <button type="button" class="btn btn-sm btn-outline-primary text-xs"
 onclick="openAppealModal(<?= $sid ?>,'<?= htmlspecialchars(addslashes($s['kra_category'])) ?>')">Appeal</button>
 <?php elseif ($latest_appeal): ?>
 <a href="index.php?page=appeals&id=<?= (int)$latest_appeal['appeal_id'] ?>" class="btn btn-sm btn-outline-secondary text-xs">View</a>
 <?php else: ?>
 <span class="text-slate-300 [font-size:0.78rem]">—</span>
 <?php endif; ?>
 </td>
 <?php if ($can_edit): ?>
 <td class="[padding:0.65rem_0.75rem] text-center align-middle">
 <?php $tab = $kra_tabs[$s['kra_category']] ?? 'instruction';
 $is_flagged = ($s['revision_status'] ?? 'ok') === 'needs_revision'; ?>
 <?php if ($app['status'] === 'needs_revision' && !$is_flagged): ?>
 <span class="text-slate-300 [font-size:0.8rem]">—</span>
 <?php else: ?>
 <a href="?page=apply&tab=<?= $tab ?>&edit_sid=<?= $s['submission_id'] ?>"
 class="inline-flex items-center gap-1 rounded-md border border-blue-100 bg-brand-50 px-2.5 py-1 text-xs font-semibold text-brand-700 no-underline hover:bg-brand-100">
 <i class="bi bi-pencil [font-size:0.72rem]"></i>Edit
 </a>
 <?php endif; ?>
 </td>
 <?php endif; ?>
 </tr>
 <?php endforeach; ?>
 </tbody>
 </table>
 </div>
 </div>
 </div>
 <?php endforeach; ?>

 <script>
 function toggleKraSection(id) {
 const sec = document.getElementById(id);
 const chev = document.getElementById(id + '-chev');
 if (!sec) return;
 const open = !sec.classList.contains('hidden');
 sec.classList.toggle('hidden', open);
 if (chev) chev.classList.toggle('rotate-180', !open);
 }
 // Open all sections by default
 document.addEventListener('DOMContentLoaded', function() {
 document.querySelectorAll('[id^="krasec-"]').forEach(function(sec) {
 if (!sec.id.includes('-hdr') && !sec.id.includes('-chev')) {
 sec.classList.remove('hidden');
 const chev = document.getElementById(sec.id + '-chev');
 if (chev) chev.classList.add('rotate-180');
 }
 });
 });
 </script>
 <?php else: ?>
 <div class="[padding:3rem_1.5rem] text-center">
 <i class="bi bi-inbox text-4xl text-slate-300 block [margin-bottom:0.75rem]"></i>
 <div class="[font-size:0.95rem] font-semibold text-slate-400 [margin-bottom:0.35rem]">No KRA Submissions Yet</div>
No KRA Submissions Yet</div>
 <div class="[font-size:0.82rem] text-slate-300 [margin-bottom:1.25rem]">Start by adding your Key Result Areas documentation</div>
 <?php if ($can_edit): ?>
 <a href="?page=apply" class="btn btn-primary btn-sm font-semibold [padding:0.5rem_1.25rem]">
 <i class="bi bi-plus-circle me-1"></i>Start Entering KRA Scores
 </a>
 <?php endif; ?>
 </div>
 <?php endif; ?>
</div>

<div id="appealModal" class="fixed inset-0 z-[9999] hidden items-center justify-center bg-slate-950/55 p-4">
 <div class="bg-white rounded-xl w-full [max-width:520px] border border-slate-200 [box-shadow:0_20px_60px_rgba(0,0,0,0.22)]">
 <div class="[padding:1rem_1.25rem] border-b border-slate-200 flex justify-between items-center">
 <strong class="text-brand-700">File Appeal</strong>
 <button type="button" onclick="document.getElementById('appealModal').classList.add('hidden')" class="bg-transparent border-0 text-xl text-slate-400">&times;</button>
 </div>
 <form method="POST" action="index.php?page=appeals" enctype="multipart/form-data" class="p-5">
 <input type="hidden" name="action" value="file_appeal">
 <input type="hidden" name="submission_id" id="appealSubmissionId">
 <div class="[font-size:0.84rem] text-slate-600 [margin-bottom:0.75rem]">
 Appealing item: <strong id="appealKraLabel"></strong>
 </div>
 <label class="form-label">Reason for Appeal</label>
 <textarea name="reason" class="form-control" rows="4" required placeholder="Explain why this flag or score change should be reviewed."></textarea>
 <label class="form-label mt-4">Supporting Files</label>
 <input type="file" name="attachments[]" class="form-control" multiple>
 <div class="form-text">PDF, image, Word files. Max 5 MB each.</div>
 <div class="d-flex justify-content-end gap-2 mt-4">
 <button type="button" class="btn btn-outline-secondary" onclick="document.getElementById('appealModal').classList.add('hidden')">Cancel</button>
 <button class="btn btn-primary"><i class="bi bi-send me-1"></i>Submit Appeal</button>
 </div>
 </form>
 </div>
</div>

<script>
function openAppealModal(submissionId, kraLabel) {
 document.getElementById('appealSubmissionId').value = submissionId;
 document.getElementById('appealKraLabel').textContent = kraLabel;
 document.getElementById('appealModal').classList.remove('hidden');
 document.getElementById('appealModal').classList.add('flex');
}
</script>

<?php
// Score Comparison Table (live-polled)
// Show as soon as the application has been submitted or is under review
$show_comparison = in_array($app['status'], [
 'submitted','under_review','talisay_review','approved',
 'admin_rejected','needs_revision','rejected'
]) && !empty($subs);

if ($show_comparison):
 // Is it still actively being reviewed? If so we poll for live updates.
 $is_live = in_array($app['status'], ['submitted','under_review','talisay_review','needs_revision']);
?>
<div id="score-comparison" class="[scroll-margin-top:80px]"></div>
<div class="neon-card p-0 overflow-hidden [margin-top:0.75rem]" id="cmpCard">

 <!-- Header -->
 <div class="[padding:0.65rem_1.25rem] flex items-center justify-between flex-wrap [gap:0.5rem] [border-bottom:2px_solid_#e2e8f0] bg-white">
 <div>
 <div class="[font-size:0.9rem] font-bold text-brand-700 flex items-center [gap:0.5rem]">
 <i class="bi bi-bar-chart-steps"></i>Score Evaluation Comparison
 </div>
 <div class="[font-size:0.72rem] text-slate-400 [margin-top:1px]">
 Your submitted scores alongside evaluator evaluations
 </div>
 </div>
 <?php if (!$is_live): ?>
 <span class="[font-size:0.7rem] [padding:0.3rem_0.7rem] rounded-full bg-brand-50 text-blue-800 border border-blue-100 font-semibold whitespace-nowrap">
 <i class="bi bi-lock me-1"></i>Evaluation Complete
 </span>
 <?php endif; ?>
 </div>

 <!-- Table container filled by JS -->
 <div class="overflow-x-auto">
 <div id="cmpTableWrap">
 <div class="[padding:2.5rem] text-center text-slate-400 [font-size:0.85rem]">
 <div class="spinner-border spinner-border-sm text-primary me-2"></div>
 Loading comparison data...
 </div>
 </div>
 </div>

</div>

<script>
(function () {
 const APP_ID = <?= $app_id ?>;
 const IS_LIVE = <?= $is_live ? 'true' : 'false' ?>;
 const AJAX_URL = 'backend/api/comparison_ajax.php?app_id=' + APP_ID;
 const KRA_ORDER = ['Instruction','Research','Extension','Professional Development'];
 let pollTimer = null;

 function fmt(n) { return parseFloat(n).toFixed(2); }
 function esc(s) {
 return String(s ?? '')
 .replace(/&/g,'&amp;').replace(/</g,'&lt;')
 .replace(/>/g,'&gt;').replace(/"/g,'&quot;');
 }

 function buildTable(data) {
 const subs = data.submissions || [];
 const hasS1 = data.has_stage1;
 const hasS2 = data.has_stage2;
 const s1Info = data.stage1_info || [];
 const s2Info = data.stage2_info || [];

 const groups = {};
 KRA_ORDER.forEach(k => { groups[k] = []; });
 subs.forEach(s => { if (groups[s.kra_category]) groups[s.kra_category].push(s); });

 const s1Remarks = s1Info.filter(r => r.remarks).map(r => esc(r.name)+': '+esc(r.remarks)).join(' | ');
 const s2Remarks = s2Info.filter(r => r.remarks).map(r => esc(r.name)+': '+esc(r.remarks)).join(' | ');

 let html = '<table class="w-full [border-collapse:collapse] text-sm m-0">';

 // -- thead
 html += `<thead><tr class="[border-bottom:2px_solid_#e2e8f0] bg-slate-50">
 <th class="[padding:.7rem_1rem] text-brand-700 text-xs uppercase tracking-wide font-bold whitespace-nowrap">KRA</th>
 <th class="[padding:.7rem_.75rem] text-xs uppercase tracking-wide font-bold text-center bg-brand-50 text-brand-700">
 <i class="bi bi-person"></i> Your Score
 </th>`;

 if (hasS1) {
 html += `<th class="[padding:.7rem_.75rem] text-xs uppercase tracking-wide font-bold text-center bg-blue-50 text-blue-800">
 <i class="bi bi-check-circle"></i> Stage 1
 <div class="text-[0.62rem] font-normal text-slate-500 [text-transform:none] [letter-spacing:0]">Subcommittee</div>
 </th>`;
 } else {
 html += `<th class="[padding:.7rem_.75rem] text-xs uppercase tracking-wide font-bold text-center bg-blue-50 text-slate-400">
 <i class="bi bi-clock"></i> Stage 1
 <div class="text-[0.62rem] font-normal text-slate-400 [text-transform:none] [letter-spacing:0]">Awaiting evaluator</div>
 </th>`;
 }

 if (hasS2) {
 html += `<th class="[padding:.7rem_.75rem] text-xs uppercase tracking-wide font-bold text-center bg-brand-50 text-brand-700">
 <i class="bi bi-check2-circle"></i> Stage 2
 <div class="text-[0.62rem] font-normal text-slate-500 [text-transform:none] [letter-spacing:0]">ITC Evaluator</div>
 </th>`;
 }

 html += `<th class="[padding:.7rem_.75rem] text-xs uppercase tracking-wide font-bold text-brand-700">Remarks</th>
 </tr></thead><tbody>`;

 // -- tbody
 KRA_ORDER.forEach(kra => {
 const rows = groups[kra] || [];
 if (!rows.length) return;
 rows.forEach((s, ri) => {
 const isFirst = ri === 0;
 const rowCount = rows.length;
 const s1Adj = s.checker_adjusted && s.adjusted_stage === 'stage1';
 const s2Adj = s.checker_adjusted && s.adjusted_stage === 'stage2';
 const hasRev = s.revision_status === 'needs_revision';

 html += `<tr class="border-b border-slate-100 ${hasRev ? 'bg-amber-50' : ''}">`;

 if (isFirst) {
 html += `<td rowspan="${rowCount}" class="[padding:.7rem_1rem] align-middle font-bold text-brand-700 [border-right:2px_solid_#f0f4fb] whitespace-nowrap">${esc(kra)}</td>`;
 }

 // Your score
 html += `<td class="[padding:.6rem_.75rem] text-center align-middle bg-slate-50">
 <span class="cmp-your-badge bg-blue-100 text-brand-700 px-2.5 py-1 rounded-md font-bold text-[0.82rem]">${fmt(s.faculty_score)}</span>
 </td>`;

 // Stage 1
 html += `<td class="[padding:.6rem_.75rem] text-center align-middle [background:#f0f8ff]">`;
 if (s1Adj) {
 html += `<span class="cmp-s1-badge bg-blue-800 text-white px-2 py-1 rounded-md font-bold text-[0.82rem]">${fmt(s.current_score)}</span>`;
 if (s.adjusted_by) html += `<div class="text-[0.62rem] text-slate-500 mt-0.5">${esc(s.adjusted_by)}</div>`;
 } else if (hasS1) {
 html += `<span class="cmp-nochange text-slate-400 text-xs">No change</span>`;
 } else {
 html += `<span class="cmp-pending text-slate-300 text-xs"> - </span>`;
 }
 html += `</td>`;

 // Stage 2
 if (hasS2) {
 html += `<td class="[padding:.6rem_.75rem] text-center align-middle [background:#fdf8ff]">`;
 if (s2Adj) {
 html += `<span class="cmp-s2-badge bg-brand-700 text-white px-2 py-1 rounded-md font-bold text-[0.82rem]">${fmt(s.current_score)}</span>`;
 if (s.adjusted_by) html += `<div class="text-[0.62rem] text-slate-500 mt-0.5">${esc(s.adjusted_by)}</div>`;
 } else if (hasS2) {
 html += `<span class="cmp-nochange text-slate-400 text-xs">No change</span>`;
 } else {
 html += `<span class="cmp-pending text-slate-300 text-xs"> - </span>`;
 }
 html += `</td>`;
 }

 // Remarks
 html += `<td class="[padding:.6rem_.75rem] align-middle text-xs text-slate-600">`;
 let hasRem = false;
 if (s.checker_note) {
 html += `<div class="[padding:.3rem_.55rem] bg-blue-50 [border-left:3px_solid_#1e4d8c] [border-radius:0_4px_4px_0] text-xs [margin-bottom:4px]"><strong class="text-blue-800">Score note:</strong> ${esc(s.checker_note)}</div>`;
 hasRem = true;
 }
 if (isFirst && s1Remarks) { html += `<div class="text-xs text-blue-800 mt-0.5">${s1Remarks}</div>`; hasRem = true; }
 if (isFirst && s2Remarks) { html += `<div class="text-xs text-brand-700 mt-0.5">${s2Remarks}</div>`; hasRem = true; }
 if (!hasRem) html += `<span class="text-slate-300"> - </span>`;
 html += `</td></tr>`;
 });
 });
 html += `</tbody>`;

 // -- tfoot (weighted scores)
 const wsY = parseFloat(data.ws_faculty ?? 0);
 const wsS1 = parseFloat(data.ws_stage1 ?? 0);
 const wsS2 = data.ws_stage2 !== null ? parseFloat(data.ws_stage2) : null;
 const d1 = Math.round((wsS1 - wsY) * 100) / 100;
 const d2 = wsS2 !== null ? Math.round((wsS2 - wsY) * 100) / 100 : null;

 const diffHtml = (d, cls1, cls2) => {
 if (d === null) return '';
 if (d === 0) return `<div class="text-[0.68rem] mt-1 text-slate-400">No net change</div>`;
 const sign = d > 0 ? '+' : '';
 const col = d > 0 ? '#1e4d8c' : '#1e293b';
 return `<div class="mt-1 text-[0.68rem] ${d > 0 ? 'text-brand-700' : 'text-slate-800'}">${sign}${fmt(d)} vs your score</div>`;
 };

 html += `<tfoot><tr class="bg-brand-50 [border-top:2px_solid_#dbeafe]">
 <td class="[padding:.75rem_1rem] font-bold text-brand-700 text-sm whitespace-nowrap">
 <i class="bi bi-calculator me-1"></i>Weighted Score
 </td>
 <td class="p-3 text-center bg-brand-50">
 <span class="cmp-ws-your bg-blue-100 text-brand-700 px-3 py-1.5 rounded-md font-bold text-sm">${fmt(wsY)}</span>
 </td>
 <td class="p-3 text-center bg-blue-50">`;
 if (hasS1) {
 html += `<span class="cmp-ws-s1 bg-blue-800 text-white px-3 py-1.5 rounded-md font-bold text-sm">${fmt(wsS1)}</span>${diffHtml(d1)}`;
 } else {
 html += `<span class="cmp-pending text-slate-300 text-xs"> - </span>`;
 }
 html += `</td>`;

 if (hasS2) {
 html += `<td class="p-3 text-center bg-brand-50">`;
 if (wsS2 !== null) {
 html += `<span class="cmp-ws-s2 bg-brand-700 text-white px-3 py-1.5 rounded-md font-bold text-sm">${fmt(wsS2)}</span>${diffHtml(d2)}`;
 } else {
 html += `<span class="cmp-pending text-slate-300 text-xs"> - </span>`;
 }
 html += `</td>`;
 }

 html += `<td class="p-3 text-xs text-slate-500 align-middle">
 Evaluator score after all adjustments. This result is for further committee review and is not a final decision.
 </td>
 </tr></tfoot></table>`;
 return html;
 }

 function stopPolling() { if (pollTimer) { clearInterval(pollTimer); pollTimer = null; } }

 function poll() {
 fetch(AJAX_URL)
 .then(r => {
 if (!r.ok) throw new Error('HTTP ' + r.status);
 return r.json();
 })
 .then(data => {
 const wrap = document.getElementById('cmpTableWrap');
 if (!data.ok) {
 if (wrap) wrap.innerHTML = `<div class="[padding:2rem] text-center text-slate-400 text-sm">
 <i class="bi bi-exclamation-circle d-block mb-2 text-2xl"></i>
 Could not load comparison data. <button onclick="poll()" class="text-xs bg-transparent border border-slate-300 rounded-md [padding:2px_10px] cursor-pointer text-slate-600 [margin-left:6px]">Retry</button>
 </div>`;
 return;
 }
 if (wrap) wrap.innerHTML = buildTable(data);

 const ts = document.getElementById('cmpLastUpdated');
 if (ts) ts.textContent = 'Updated ' + data.updated_at;

 // Stop polling once terminal
 if (['approved','reclassified','admin_rejected','rejected'].includes(data.status)) {
 stopPolling();
 const dot = document.getElementById('cmpLiveDot');
 if (dot) dot.remove();
 const upd = document.getElementById('cmpLastUpdated');
 if (upd) upd.remove();
 }
 })
 .catch(err => {
 console.error('Comparison poll failed:', err);
 const wrap = document.getElementById('cmpTableWrap');
 if (wrap && wrap.querySelector('.spinner-border')) {
 wrap.innerHTML = `<div class="[padding:2rem] text-center text-slate-400 text-sm">
 <i class="bi bi-wifi-off d-block mb-2 text-2xl"></i>
 Could not load comparison data. <button onclick="poll()" class="text-xs bg-transparent border border-slate-300 rounded-md [padding:2px_10px] cursor-pointer text-slate-600 [margin-left:6px]">Retry</button>
 </div>`;
 }
 });
 }

 poll();
 if (IS_LIVE) { pollTimer = setInterval(poll, 5000); }

 document.addEventListener('DOMContentLoaded', function () {
 if (window.location.hash === '#score-comparison') {
 const el = document.getElementById('score-comparison');
 if (el) setTimeout(() => el.scrollIntoView({ behavior:'smooth', block:'start' }), 120);
 }
 });
})();
</script>

<?php endif; // show_comparison ?>
