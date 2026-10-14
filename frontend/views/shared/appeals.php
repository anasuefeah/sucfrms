<?php require __DIR__ . '/../../../backend/controllers/shared/appeals.php'; ?>

<?php showFlash(); ?>
<div class="grid gap-6 lg:grid-cols-[minmax(0,22rem)_minmax(0,1fr)]">
    <aside>
        <div class="neon-card overflow-hidden p-0">
            <div class="bg-brand-800 px-4 py-3 text-sm font-bold text-white">
                <i class="bi bi-chat-square-text me-2"></i>Appeals
            </div>
            <?php if ($appeals): foreach ($appeals as $ap): ?>
            <a href="index.php?page=appeals&id=<?= (int)$ap['appeal_id'] ?>"
               class="block border-b border-slate-100 px-4 py-3.5 no-underline transition hover:bg-slate-50 <?= $selected && (int)$selected['appeal_id']===(int)$ap['appeal_id'] ? 'bg-brand-50' : 'bg-white' ?>">
                <div class="flex justify-between gap-2">
                    <strong class="text-[0.8125rem] text-brand-800"><?= sanitize($categories[$ap['kra_category']] ?? $ap['kra_category']) ?> Appeal</strong>
                    <?php $unread = (isFaculty() && empty($ap['is_read_by_faculty'])) || (isEvaluator() && empty($ap['is_read_by_checker'])); ?>
                    <?php if ($unread): ?><span class="badge bg-primary">New</span><?php endif; ?>
                </div>
                <div class="mt-1 text-xs text-slate-600"><?= sanitize($ap['cycle_name']) ?></div>
                <div class="mt-1 text-xs text-slate-400"><?= sanitize(appealStatusLabel($ap)) ?></div>
            </a>
            <?php endforeach; else: ?>
            <div class="p-8 text-center text-sm text-slate-500">No appeals yet.</div>
            <?php endif; ?>
        </div>
    </aside>
    <section class="min-w-0">
        <?php if ($selected): ?>
        <div class="neon-card mb-6 p-5 sm:p-6">
            <div class="flex flex-wrap items-start justify-between gap-4">
                <div>
                    <h2 class="mb-1 text-base font-bold text-brand-800"><?= sanitize($categories[$selected['kra_category']] ?? $selected['kra_category']) ?> Appeal</h2>
                    <div class="text-sm text-slate-500"><?= sanitize($selected['cycle_name']) ?> | <?= sanitize(appealStatusLabel($selected)) ?></div>
                </div>
                <a href="index.php?page=<?= isFaculty() ? 'my_application' : 'review_application&id=' . (int)$selected['application_id'] ?>#kra-verification" class="btn btn-sm btn-outline-primary">
                    <i class="bi bi-box-arrow-up-right me-1"></i>Open Item
                </a>
            </div>
            <dl class="mt-5 grid gap-x-6 gap-y-3 text-sm sm:grid-cols-2">
                <div><dt class="inline text-slate-500">Faculty:</dt> <dd class="inline font-semibold text-slate-800"><?= sanitize(formatDisplayName([
                    'first_name' => $selected['faculty_first'] ?? '',
                    'middle_name' => $selected['faculty_middle'] ?? '',
                    'last_name' => $selected['faculty_last'] ?? '',
                    'faculty_name' => $selected['faculty_name'] ?? ''
                ], 'faculty_name')) ?></dd></div>
                <div><dt class="inline text-slate-500">Evaluator:</dt> <dd class="inline font-semibold text-slate-800"><?= sanitize(checkerDisplayLabel($selected)) ?></dd></div>
                <div><dt class="inline text-slate-500">Original Score:</dt> <dd class="inline text-slate-800"><?= $selected['original_score'] !== null ? number_format((float)$selected['original_score'], 2) : 'N/A' ?></dd></div>
                <div><dt class="inline text-slate-500">Changed Score:</dt> <dd class="inline text-slate-800"><?= $selected['changed_score'] !== null ? number_format((float)$selected['changed_score'], 2) : 'N/A' ?></dd></div>
                <?php if (!empty($selected['checker_remark'])): ?><div class="sm:col-span-2"><dt class="inline text-slate-500">Evaluator Remark:</dt> <dd class="inline text-slate-800"><?= sanitize($selected['checker_remark']) ?></dd></div><?php endif; ?>
            </dl>
        </div>

        <div class="neon-card mb-6 p-5 sm:p-6">
            <h2 class="mb-2 text-sm font-bold text-brand-800">Thread</h2>
            <?php foreach ($messages as $m): ?>
            <article class="border-b border-slate-100 py-4 last:border-b-0">
                <div class="text-xs font-bold text-slate-800"><?= sanitize($m['role']==='evaluator' ? checkerDisplayLabel($m) : formatDisplayName($m)) ?> <time class="font-normal text-slate-400"><?= date('M d, Y g:i A', strtotime($m['created_at'])) ?></time></div>
                <div class="mt-1 whitespace-pre-wrap text-sm leading-6 text-slate-600"><?= sanitize($m['message']) ?></div>
                <?php
                $att = $pdo->prepare("SELECT * FROM appeal_attachments WHERE message_id=?");
                $att->execute([$m['message_id']]);
                $atts = $att->fetchAll();
                ?>
                <?php if ($atts): ?><div class="mt-3 flex flex-wrap gap-2"><?php foreach ($atts as $a): ?>
                    <a href="backend/api/view_file.php?file=<?= urlencode($a['file_path']) ?>" target="_blank" class="btn btn-sm btn-outline-secondary"><i class="bi bi-paperclip"></i><?= sanitize($a['original_filename']) ?></a>
                <?php endforeach; ?></div><?php endif; ?>
            </article>
            <?php endforeach; ?>
        </div>

        <?php if ($selected['status'] !== 'resolved' && !isAdmin()): ?>
        <div class="neon-card mb-6 p-5 sm:p-6">
            <form method="POST" enctype="multipart/form-data">
                <input type="hidden" name="action" value="reply_appeal">
                <input type="hidden" name="appeal_id" value="<?= (int)$selected['appeal_id'] ?>">
                <label class="form-label">Reply</label>
                <textarea name="message" class="form-control" rows="3" required></textarea>
                <input type="file" name="attachments[]" class="form-control mt-3" multiple>
                <button class="btn btn-primary mt-3"><i class="bi bi-send me-1"></i>Send Reply</button>
            </form>
        </div>
        <?php endif; ?>

        <?php if ($selected['status'] !== 'resolved' && isEvaluator() && (int)$selected['checker_id'] === $uid): ?>
        <div class="neon-card p-5 sm:p-6">
            <form method="POST">
                <input type="hidden" name="action" value="resolve_appeal">
                <input type="hidden" name="appeal_id" value="<?= (int)$selected['appeal_id'] ?>">
                <div class="grid gap-4 md:grid-cols-3">
                    <div><label class="form-label">Outcome</label><select name="outcome" class="form-select" required><option value="upheld">Upheld</option><option value="revised">Revised</option><option value="dismissed">Dismissed</option></select></div>
                    <div><label class="form-label">New Score</label><input type="number" step="0.01" min="0" name="new_score" class="form-control"></div>
                    <div class="flex items-end"><label class="form-check"><input type="checkbox" name="clear_flag" value="1" class="form-check-input"> Clear flag</label></div>
                </div>
                <button class="btn btn-primary mt-5"><i class="bi bi-check-circle me-1"></i>Resolve Appeal</button>
            </form>
        </div>
        <?php endif; ?>
        <?php else: ?>
        <?= emptyState('bi-chat-square-text', 'Select an appeal', 'Choose an appeal to view its discussion thread.') ?>
        <?php endif; ?>
    </section>
</div>
