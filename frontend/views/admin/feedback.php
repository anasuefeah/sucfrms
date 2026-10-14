<?php if ((require __DIR__ . '/../../../backend/controllers/admin/feedback.php') === false) return; ?>

<div class="neon-card p-0 overflow-hidden">
    <!-- Header -->
    <div class="flex flex-wrap items-center justify-between gap-3 bg-gradient-to-br from-brand-700 to-brand-600 px-6 py-4">
        <div class="flex items-center [gap:0.75rem]">
            <div class="[width:38px] [height:38px] [border-radius:10px] [background:rgba(255,255,255,0.15)] flex items-center justify-center">
                <i class="bi bi-chat-square-text-fill [color:#fff] [font-size:1rem]"></i>
            </div>
            <div>
                <div class="font-bold [color:#fff] [font-size:0.97rem]">User Feedback</div>
                <div class="[font-size:0.72rem] [color:rgba(255,255,255,0.7)]"><?= $counts['all'] ?> submission<?= $counts['all'] !== 1 ? 's' : '' ?> total</div>
            </div>
        </div>
        <!-- Status filter pills -->
        <div class="flex [gap:0.4rem] flex-wrap">
            <?php
            $pill_filters = [
                '' => ['label'=>'All','count'=>$counts['all'],'color'=>'rgba(255,255,255,0.15)','active'=>'rgba(255,255,255,0.9)'],
                'new' => ['label'=>'New','count'=>$counts['new'],'color'=>'rgba(239,68,68,0.25)','active'=>'#334155'],
                'read' => ['label'=>'Read','count'=>$counts['read'],'color'=>'rgba(234,179,8,0.25)','active'=>'#475569'],
                'resolved' => ['label'=>'Resolved','count'=>$counts['resolved'],'color'=>'rgba(30,77,140,0.15)','active'=>'#1e4d8c'],
            ];
            foreach ($pill_filters as $val => $pill):
                $active = ($filter_status === $val);
            ?>
            <a href="index.php?page=feedback<?= $val ? '&status='.$val : '' ?>"
               class="inline-flex items-center gap-1.5 rounded-full border border-white/20 px-3 py-1.5 text-xs font-semibold no-underline transition <?= $active ? 'bg-white text-brand-700' : 'bg-white/10 text-white/90 hover:bg-white/20 hover:text-white' ?>">
                <?= $pill['label'] ?>
                <span class="rounded-full px-1.5 text-[0.65rem] <?= $active ? 'bg-brand-700 text-white' : 'bg-white/20 text-white/90' ?>"><?= $pill['count'] ?></span>
            </a>
            <?php endforeach; ?>
        </div>
    </div>

    <?php if (empty($feedbacks)): ?>
    <div class="[padding:4rem_2rem] text-center [color:#94a3b8]">
        <i class="bi bi-chat-square [font-size:2.5rem] block [margin-bottom:0.75rem]"></i>
        <div class="[font-size:0.95rem] font-semibold [margin-bottom:0.3rem]">No feedback<?= $filter_status ? ' with status "'.ucfirst($filter_status).'"' : '' ?></div>
        <div class="[font-size:0.82rem]">User submissions will appear here.</div>
    </div>
    <?php else: ?>

    <div class="[padding:1.25rem] flex flex-col [gap:0.85rem]">
    <?php foreach ($feedbacks as $fb):
        $ss = $status_styles[$fb['status']] ?? $status_styles['new'];
        $stars = intval($fb['rating'] ?? 0);
    ?>
    <article class="overflow-hidden rounded-xl border border-slate-200 border-l-4 bg-white shadow-card <?= $fb['status']==='new' ? 'border-l-slate-700' : ($fb['status']==='resolved' ? 'border-l-brand-600' : 'border-l-slate-200') ?>">

        <!-- Feedback header -->
        <div class="[padding:0.75rem_1rem] flex items-center justify-between flex-wrap [gap:0.5rem] [background:#f8fafc] [border-bottom:1px_solid_#f1f5f9]">
            <div class="flex items-center [gap:0.65rem]">
                <!-- Avatar -->
                <div class="[width:32px] [height:32px] [border-radius:50%] [background:#1e4d8c] flex items-center justify-center shrink-0">
                    <span class="[color:#fff] font-bold [font-size:0.75rem]"><?= strtoupper(substr($fb['full_name'] ?? '?', 0, 1)) ?></span>
                </div>
                <div>
                    <div class="font-bold [color:#1e293b] [font-size:0.85rem]"><?= sanitize($fb['full_name'] ?? 'Anonymous') ?></div>
                    <div class="[font-size:0.7rem] [color:#64748b]"><?= sanitize($fb['email'] ?? '') ?><?= $fb['role'] ? ' &nbsp;|&nbsp; <span class="capitalize">'.str_replace('_',' ',$fb['role']).'</span>' : '' ?></div>
                </div>
            </div>
            <div class="flex items-center [gap:0.5rem] flex-wrap">
                <!-- Stars -->
                <?php if ($stars > 0): ?>
                <div class="flex [gap:2px]">
                    <?php for ($s = 1; $s <= 5; $s++): ?>
                    <i class="bi bi-star<?= $s <= $stars ? '-fill text-amber-500' : ' text-slate-300' ?> text-[0.8rem]"></i>
                    <?php endfor; ?>
                </div>
                <?php endif; ?>
                <!-- Status badge -->
                <span class="badge <?= $fb['status'] === 'resolved' ? 'bg-success' : ($fb['status'] === 'read' ? 'bg-warning' : 'bg-danger') ?>">
                    <i class="bi <?= $ss['icon'] ?> me-1"></i><?= $ss['label'] ?>
                </span>
                <!-- Date -->
                <span class="[font-size:0.7rem] [color:#94a3b8]"><?= date('M d, Y | H:i', strtotime($fb['submitted_at'])) ?></span>
            </div>
        </div>

        <!-- Feedback body -->
        <div class="[padding:0.85rem_1rem]">
            <?php if ($fb['subject']): ?>
            <div class="font-bold [color:#1a3a6b] [font-size:0.88rem] [margin-bottom:0.4rem]">
                <i class="bi bi-tag me-1 [color:#64748b]"></i><?= sanitize($fb['subject']) ?>
            </div>
            <?php endif; ?>
            <div class="[font-size:0.84rem] [color:#374151] leading-relaxed whitespace-pre-wrap"><?= sanitize($fb['message']) ?></div>
            <?php if ($fb['status'] === 'resolved' && $fb['resolved_at']): ?>
            <div class="[margin-top:0.6rem] [font-size:0.72rem] [color:#1e4d8c] flex items-center [gap:0.35rem]">
                <i class="bi bi-check2-circle"></i> Resolved <?= date('M d, Y', strtotime($fb['resolved_at'])) ?>
            </div>
            <?php endif; ?>
        </div>

        <!-- Actions -->
        <div class="[padding:0.5rem_1rem] [background:#f8fafc] [border-top:1px_solid_#f1f5f9] flex [gap:0.5rem] flex-wrap">
            <?php if ($fb['status'] === 'new'): ?>
            <form method="POST" class="m-0">
                <input type="hidden" name="feedback_id" value="<?= $fb['feedback_id'] ?>">
                <input type="hidden" name="action" value="mark_read">
                <button type="submit" class="[padding:0.3rem_0.75rem] [border:1px_solid_#e2e8f0] [border-radius:6px] [background:#f8fafc] [color:#334155] [font-size:0.75rem] font-semibold cursor-pointer">
                    <i class="bi bi-eye me-1"></i>Mark Read
                </button>
            </form>
            <?php endif; ?>
            <?php if ($fb['status'] !== 'resolved'): ?>
            <form method="POST" class="m-0">
                <input type="hidden" name="feedback_id" value="<?= $fb['feedback_id'] ?>">
                <input type="hidden" name="action" value="mark_resolved">
                <button type="submit" class="[padding:0.3rem_0.75rem] [border:1px_solid_#bbf7d0] [border-radius:6px] [background:#f0fdf4] [color:#16a34a] [font-size:0.75rem] font-semibold cursor-pointer">
                    <i class="bi bi-check-circle me-1"></i>Mark Resolved
                </button>
            </form>
            <?php else: ?>
            <form method="POST" class="m-0">
                <input type="hidden" name="feedback_id" value="<?= $fb['feedback_id'] ?>">
                <input type="hidden" name="action" value="mark_new">
                <button type="submit" class="[padding:0.3rem_0.75rem] [border:1px_solid_#e2e8f0] [border-radius:6px] [background:#f8fafc] [color:#64748b] [font-size:0.75rem] font-semibold cursor-pointer">
                    <i class="bi bi-arrow-counterclockwise me-1"></i>Reopen
                </button>
            </form>
            <?php endif; ?>
            <form method="POST" id="deleteFeedbackForm_<?= $fb['feedback_id'] ?>" class="m-0 [margin-left:auto]">
                <input type="hidden" name="feedback_id" value="<?= $fb['feedback_id'] ?>">
                <input type="hidden" name="action" value="delete">
                <button type="button"
                        onclick="confirmDelete('Delete this feedback permanently? This cannot be undone.','deleteFeedbackForm_<?= $fb['feedback_id'] ?>','Delete','bi-trash')"
                        class="[padding:0.3rem_0.75rem] [border:1px_solid_#fecaca] [border-radius:6px] [background:#fef2f2] [color:#dc2626] [font-size:0.75rem] font-semibold cursor-pointer">
                    <i class="bi bi-trash me-1"></i>Delete
                </button>
            </form>
        </div>
    </article>
    <?php endforeach; ?>
    </div>
    <?php endif; ?>
</div>
