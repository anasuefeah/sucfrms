<?php require __DIR__ . '/../../../backend/controllers/evaluator/review_queue.php'; ?>

<!-- Page header -->
<div class="flex items-center justify-between flex-wrap gap-4 [margin-bottom:1.25rem]">
 <div class="flex items-center [gap:0.6rem]">
 <div class="[width:36px] [height:36px] rounded-lg bg-blue-800 flex items-center justify-center shrink-0">
 <i class="bi bi-inbox-fill text-white [font-size:0.9rem]"></i>
 </div>
 <div>
 <div class="font-bold text-brand-700 [font-size:0.95rem]">Review Queue</div>
 <div class="[font-size:0.72rem] text-slate-400 [margin-top:1px]">Applications assigned for your review</div>
 </div>
 </div>
 <form method="GET" class="flex [gap:0.5rem] items-center">
 <input type="hidden" name="page" value="review_queue">
 <input type="hidden" name="filter" value="<?= $filter ?>">
 <div class="flex items-center overflow-hidden rounded-lg border border-slate-200 bg-slate-50 focus-within:border-brand-700">
 <span class="[padding:0_0.6rem] text-slate-400 [font-size:0.82rem]"><i class="bi bi-search"></i></span>
 <input type="text" name="search" value="<?= sanitize($search) ?>"
 placeholder="Search faculty, ID..."
 class="w-48 border-0 bg-transparent py-2 pr-2 text-sm text-slate-800 outline-none">
 </div>
 <button type="submit"
 class="bg-brand-700 text-white border-0 rounded-lg [padding:0.42rem_1rem] [font-size:0.82rem] font-semibold cursor-pointer">
 Search
 </button>
 <?php if ($search): ?>
 <a href="?page=review_queue&filter=<?= $filter ?>"
 class="rounded-lg border border-slate-200 bg-slate-100 px-3 py-2 text-xs font-semibold text-slate-500 no-underline hover:bg-slate-200">
 <i class="bi bi-x"></i>
 </a>
 <?php endif; ?>
 </form>
</div>

<!-- Filter tabs -->
<div class="d-flex gap-2 flex-wrap mb-4">
<?php
$tabs = [
 'pending_decision' => ['label'=>'Awaiting My Decision', 'cnt'=>$my_pending_count, 'color'=>'#1a3a6b'],
 'submitted' => ['label'=>'Submitted', 'cnt'=>$counts['submitted']??0, 'color'=>'#1e4d8c'],
 'under_review' => ['label'=>'Under Evaluation', 'cnt'=>$counts['under_review']??0, 'color'=>'#1e4d8c'],
 'approved' => ['label'=>'Evaluation Complete', 'cnt'=>$counts['approved']??0, 'color'=>'#1e4d8c'],
 'needs_revision' => ['label'=>'Returned for Revision', 'cnt'=>$returned_count, 'color'=>'#334155'],
 'my_reviewed' => ['label'=>'My History', 'cnt'=>$my_reviewed_count, 'color'=>'#1e4d8c'],
];
foreach ($tabs as $key => $t):
 $active = $filter === $key;
?>
<a href="?page=review_queue&filter=<?= $key ?>"
 class="rounded-full px-3.5 py-1.5 text-sm font-semibold no-underline <?= $active ? 'bg-brand-700 text-white' : 'border border-slate-200 bg-brand-50 text-slate-500 hover:border-brand-200 hover:text-brand-700' ?>">
 <?= $t['label'] ?>
 <?php if ($t['cnt']): ?>
 <span class="ml-1 inline-flex size-[18px] items-center justify-center rounded-full text-[0.65rem] font-bold text-white <?= $active ? 'bg-white/25' : 'bg-brand-700' ?>">
 <?= $t['cnt'] ?>
 </span>
 <?php endif; ?>
</a>
<?php endforeach; ?>
</div>

<!-- Table card -->
<div class="neon-card p-0 overflow-hidden">
<?php if ($apps): ?>
<div class="table-responsive">
 <table class="w-full [border-collapse:collapse] [font-size:0.84rem]">
 <thead>
 <tr class="bg-slate-50 [border-bottom:2px_solid_#e2e8f0]">
 <th class="[padding:0.75rem_1rem] text-slate-500 [font-size:0.68rem] uppercase [letter-spacing:0.05em] font-bold whitespace-nowrap">Faculty</th>
 <th class="[padding:0.75rem_0.75rem] text-slate-500 [font-size:0.68rem] uppercase [letter-spacing:0.05em] font-bold">Campus</th>
 <th class="[padding:0.75rem_0.75rem] text-slate-500 [font-size:0.68rem] uppercase [letter-spacing:0.05em] font-bold">Cycle</th>
 <th class="[padding:0.75rem_0.75rem] text-slate-500 [font-size:0.68rem] uppercase [letter-spacing:0.05em] font-bold text-center">Score</th>
 <?php if (in_array($filter, ['submitted','under_review','pending_decision'])): ?>
 <?php if ($filter === 'pending_decision'): ?>
 <th class="[padding:0.75rem_0.75rem] text-slate-500 [font-size:0.68rem] uppercase [letter-spacing:0.05em] font-bold text-center">Status</th>
 <?php endif; ?>
 <th class="[padding:0.75rem_0.75rem] text-slate-500 [font-size:0.68rem] uppercase [letter-spacing:0.05em] font-bold text-center">Evaluator Progress</th>
 <th class="[padding:0.75rem_0.75rem] text-slate-500 [font-size:0.68rem] uppercase [letter-spacing:0.05em] font-bold text-center">My Status</th>
 <?php endif; ?>
 <th class="[padding:0.75rem_0.75rem] text-slate-500 [font-size:0.68rem] uppercase [letter-spacing:0.05em] font-bold">Submitted</th>
 <th class="[padding:0.75rem_0.75rem] text-slate-500 [font-size:0.68rem] uppercase [letter-spacing:0.05em] font-bold text-center">Action</th>
 </tr>
 </thead>
 <tbody>
 <?php foreach ($apps as $a):
 $approvals = (int)$a['approvals_count'];
 $my_dec = $a['my_decision'] ?? null;
 ?> <tr class="border-b border-slate-100 hover:bg-slate-50">
 <td class="[padding:0.75rem_1rem] align-middle">
 <div class="font-semibold text-slate-800 [font-size:0.84rem]"><?= htmlspecialchars(formatDisplayName($a)) ?></div>
 <div class="[font-size:0.7rem] text-slate-400 [margin-top:1px]"><?= sanitize($a['employee_id'] ?? '') ?>
 <?php if (!empty($a['rank'])): ?>
 &nbsp;&middot;&nbsp;<span class="text-slate-500"><?= sanitize($a['rank']) ?></span>
 <?php endif; ?>
 <?php $wait = (int)($a['days_waiting'] ?? 0);
 [$due_label, $due_color, $due_bg] = [$a['eval_deadline_label'], $a['eval_deadline_color'], $a['eval_deadline_bg']]; ?>
 &nbsp;<span class="rounded-full border border-slate-200 bg-slate-50 px-2 py-0.5 text-[0.65rem] font-bold text-slate-700"><?= htmlspecialchars($due_label) ?></span>
 <?php if ($wait >= 5): ?>
 &nbsp;<span class="bg-slate-50 text-slate-800 border border-slate-200 rounded-full [padding:1px_7px] [font-size:0.65rem] font-bold"><i class="bi bi-clock-fill me-1"></i><?= $wait ?>d overdue</span>
 <?php elseif ($wait >= 3): ?>
 &nbsp;<span class="bg-slate-50 text-slate-700 border border-slate-200 rounded-full [padding:1px_7px] [font-size:0.65rem] font-bold"><?= $wait ?>d waiting</span>
 <?php endif; ?>
 </div>
 </td>
 <td class="[padding:0.75rem_0.75rem] text-slate-500 [font-size:0.82rem] align-middle"><?= sanitize($a['campus_name']) ?></td>
 <td class="max-w-40 truncate px-3 py-3 align-middle text-xs text-slate-500" title="<?= sanitize($a['cycle_name']) ?>"><?= sanitize($a['cycle_name']) ?></td>
 <td class="[padding:0.75rem_0.75rem] text-center align-middle">
 <?php $score = (float)$a['weighted_score'];
 $sc = $score >= 71 ? '#1e4d8c' : ($score >= 41 ? '#1e4d8c' : '#334155'); ?>
 <span class="rounded-full px-2.5 py-1 text-xs font-bold text-white <?= $score >= 41 ? 'bg-brand-700' : 'bg-slate-700' ?>">
 <?= number_format($score, 2) ?>
 </span>
 </td>
 <?php if (in_array($filter, ['submitted','under_review','pending_decision'])): ?>
 <?php if ($filter === 'pending_decision'): ?>
 <td class="[padding:0.75rem_0.75rem] text-center align-middle">
 <?php $st = $a['status'];
 $st_bg = $st === 'submitted' ? '#dbeafe' : '#f1f5f9';
 $st_tc = $st === 'submitted' ? '#1e4d8c' : '#334155'; ?>
 <span class="rounded-full px-2.5 py-1 text-xs font-semibold <?= $st === 'submitted' ? 'bg-blue-100 text-brand-700' : 'bg-slate-100 text-slate-700' ?>">
 <?= $st === 'submitted' ? 'Submitted' : 'Under Review' ?>
 </span>
 </td>
 <?php endif; ?>
 <td class="[padding:0.75rem_0.75rem] text-center align-middle">
 <?php
 $slot_decisions = $slot_map[$a['application_id']] ?? [];
 ?>
 <div class="flex gap-1 justify-center items-center">
 <?php for ($i = 0; $i < $total_checkers_count; $i++):
 $d = $slot_decisions[$i] ?? null;
 $dot_bg = $d === 'approved' ? '#1a3a6b' : ($d === 'rejected' ? '#334155' : ($d === 'pending' ? '#475569' : '#e2e8f0'));
 ?>
 <span class="inline-block size-2.5 shrink-0 rounded-full <?= $d === 'approved' ? 'bg-brand-700' : ($d === 'rejected' ? 'bg-slate-700' : ($d === 'pending' ? 'bg-slate-600' : 'bg-slate-200')) ?>"
 title="Evaluator <?= $i+1 ?>: <?= $d ?? 'not joined' ?>"></span>
 <?php endfor; ?>
 <span class="[font-size:0.68rem] text-slate-400 [margin-left:3px]"><?= $approvals ?>/<?= $total_checkers_count ?></span>
 </div>
 </td>
 <td class="[padding:0.75rem_0.75rem] text-center align-middle">
 <?php if ($my_dec === 'approved'): ?>
 <span class="bg-blue-100 text-blue-800 [padding:0.18rem_0.55rem] rounded-full [font-size:0.7rem] font-semibold whitespace-nowrap">
 <i class="bi bi-check-circle me-1"></i>Evaluation Complete
 </span>
 <?php elseif ($my_dec === 'rejected'): ?>
 <span class="bg-slate-50 text-slate-800 [padding:0.18rem_0.55rem] rounded-full [font-size:0.7rem] font-semibold whitespace-nowrap">
 <i class="bi bi-arrow-counterclockwise me-1"></i>Returned for Revision
 </span>
 <?php else: ?>
 <span class="bg-slate-50 text-slate-700 [padding:0.18rem_0.55rem] rounded-full [font-size:0.7rem] font-semibold whitespace-nowrap">
 <i class="bi bi-hourglass-split me-1"></i>Pending
 </span>
 <?php endif; ?>
 </td>
 <?php endif; ?>
 <td class="[padding:0.75rem_0.75rem] text-slate-500 [font-size:0.78rem] whitespace-nowrap align-middle">
 <?php if ($filter === 'my_reviewed' && !empty($a['my_decided_at'])): ?>
 <div class="[font-size:0.72rem] text-slate-400">Decided</div>
 <?= date('M d, Y', strtotime($a['my_decided_at'])) ?>
 <?php else: ?>
 <?= $a['submitted_at'] ? date('M d, Y', strtotime($a['submitted_at'])) : '-' ?>
 <?php endif; ?>
 </td>
 <td class="[padding:0.75rem_0.75rem] text-center align-middle">
 <a href="?page=review_application&id=<?= $a['application_id'] ?>"
 class="inline-flex items-center gap-1 whitespace-nowrap rounded-md border border-blue-100 bg-brand-50 px-3.5 py-1.5 text-xs font-semibold text-brand-700 no-underline transition-colors hover:bg-brand-700 hover:text-white"
 class="hover:bg-brand-700 hover:text-white">
 <i class="bi bi-eye [font-size:0.72rem]"></i>
 <?= in_array($my_dec, ['approved','rejected']) ? 'View' : 'Review' ?>
 </a>
 </td>
 </tr>
 <?php endforeach; ?>
 </tbody>
 </table>
</div>
<?php else: ?>
<div class="text-center [padding:3.5rem_1.5rem] text-slate-400">
 <i class="bi bi-inbox text-4xl [color:#dbeafe] block [margin-bottom:0.75rem]"></i>
 <div class="[font-size:0.95rem] font-semibold text-slate-600 [margin-bottom:0.3rem]">No applications found</div>
 <div class="[font-size:0.82rem]">Try a different filter or search term.</div>
</div>
<?php endif; ?>

<!-- Table footer -->
<div class="[padding:0.6rem_1rem] bg-slate-50 [border-top:1px_solid_#f0f4fb] flex items-center justify-between flex-wrap [gap:0.5rem]">
 <span class="[font-size:0.72rem] text-slate-400">
 Showing <strong class="text-brand-700"><?= count($apps) ?></strong> application<?= count($apps) !== 1 ? 's' : '' ?>
 </span>
 <?php if ($filter): ?>
 <a href="?page=review_queue" class="[font-size:0.72rem] text-slate-500 no-underline font-semibold">
 <i class="bi bi-x-circle me-1"></i>Clear filter
 </a>
 <?php endif; ?>
</div>
</div>
