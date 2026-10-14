<?php require __DIR__ . '/../../../backend/controllers/shared/audit_log.php'; ?>

<!-- Page header -->
<div class="mb-6 flex flex-wrap items-center justify-between gap-4">
    <div class="flex items-center gap-3">
        <div class="flex size-10 shrink-0 items-center justify-center rounded-xl bg-brand-700 text-white shadow-sm">
            <i class="bi bi-shield-check"></i>
        </div>
        <div>
            <h1 class="m-0 text-lg font-bold text-brand-800">
                <?= $role === 'admin' ? 'System Audit Logs' : 'My Activity Log' ?>
            </h1>
            <div class="mt-0.5 text-xs text-slate-400">
                <?= number_format($total_rows) ?> record<?= $total_rows !== 1 ? 's' : '' ?>
                <?php if ($search || $filter_role || $filter_action || $filter_campus): ?>
                <span class="ml-1 rounded bg-brand-50 px-1.5 py-0.5 text-[0.6875rem] font-bold text-brand-700 ring-1 ring-brand-100">filtered</span>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <a href="backend/services/reports/audit_log_pdf.php?search=<?= urlencode($search) ?>&filter_role=<?= urlencode($filter_role) ?>&filter_action=<?= urlencode($filter_action) ?>&campus_id=<?= $filter_campus ?>"
       target="_blank"
       class="btn btn-outline-secondary btn-sm">
        <i class="bi bi-printer"></i> Print
    </a>
</div>

<!-- Filter bar -->
<div class="neon-card mb-6 p-4 sm:p-5">
    <form method="GET" action="index.php">
        <input type="hidden" name="page" value="<?= $role === 'admin' ? 'audit' : 'my_audit' ?>">
        <div class="grid items-end gap-3 sm:grid-cols-2 lg:flex lg:flex-wrap">
            <!-- Search -->
            <div class="min-w-0 sm:col-span-2 lg:min-w-48 lg:flex-1">
                <label class="mb-1 block text-xs font-bold tracking-wide text-slate-500 uppercase">Search</label>
                <div class="flex items-center overflow-hidden rounded-xl border border-slate-200 bg-slate-50 transition focus-within:border-brand-500 focus-within:ring-4 focus-within:ring-brand-100">
                    <span class="px-3 text-sm text-slate-400"><i class="bi bi-search"></i></span>
                    <input type="text" name="search" value="<?= sanitize($search) ?>"
                           placeholder="Action or details..."
                           list="auditSearchSuggestions" autocomplete="off"
                           class="min-w-0 flex-1 border-0 bg-transparent py-2 pr-3 text-sm text-slate-800 outline-none placeholder:text-slate-400">
                    <datalist id="auditSearchSuggestions">
                        <option value="Login"><option value="Logout"><option value="KRA Saved">
                        <option value="Application Submitted"><option value="Evaluation Complete">
                        <option value="Returned for Revision"><option value="Evaluator Completed">
                        <option value="Revision Requested"><option value="Score Altered">
                        <option value="Cycle Created"><option value="Register">
                        <option value="Password Reset Released"><option value="Role Changed">
                        <?php foreach ($action_types as $at): ?>
                        <option value="<?= sanitize(auditDisplayText($at)) ?>">
                        <?php endforeach; ?>
                    </datalist>
                </div>
            </div>
            <?php if ($role === 'admin'): ?>
            <!-- Role -->
            <div class="lg:min-w-32">
                <label class="mb-1 block text-xs font-bold tracking-wide text-slate-500 uppercase">Role</label>
                <select name="filter_role" class="form-select form-select-sm" onchange="this.form.submit()">
                    <option value="">All Roles</option>
                    <option value="admin"   <?= $filter_role==='admin'   ?'selected':'' ?>>Admin</option>
                    <option value="evaluator" <?= $filter_role==='evaluator' ?'selected':'' ?>>Evaluator</option>
                    <option value="faculty" <?= $filter_role==='faculty' ?'selected':'' ?>>Faculty</option>
                </select>
            </div>
            <!-- Campus -->
            <div class="lg:min-w-40">
                <label class="mb-1 block text-xs font-bold tracking-wide text-slate-500 uppercase">Campus</label>
                <select name="campus_id" class="form-select form-select-sm" onchange="this.form.submit()">
                    <option value="">All Campuses</option>
                    <?php foreach ($campuses as $c): ?>
                    <option value="<?= $c['campus_id'] ?>" <?= $filter_campus===(int)$c['campus_id']?'selected':'' ?>>
                        <?= sanitize($c['campus_name']) ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <!-- Action type -->
            <div class="lg:min-w-44">
                <label class="mb-1 block text-xs font-bold tracking-wide text-slate-500 uppercase">Action Type</label>
                <select name="filter_action" class="form-select form-select-sm" onchange="this.form.submit()">
                    <option value="">All Actions</option>
                    <?php foreach ($action_types as $at): ?>
                    <option value="<?= sanitize($at) ?>" <?= $filter_action===$at?'selected':'' ?>><?= sanitize(auditDisplayText($at)) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <?php endif; ?>
            <!-- Per page -->
            <div class="lg:min-w-28">
                <label class="mb-1 block text-xs font-bold tracking-wide text-slate-500 uppercase">Per Page</label>
                <select name="per_page" class="form-select form-select-sm" onchange="this.form.submit()">
                    <?php foreach ([10,25,50,100] as $n): ?>
                    <option value="<?= $n ?>" <?= $per_page===$n?'selected':'' ?>><?= $n ?> / page</option>
                    <?php endforeach; ?>
                </select>
            </div>
            <!-- Submit + Clear -->
            <div class="flex items-end gap-2">
                <button type="submit" class="btn btn-primary btn-sm">
                    <i class="bi bi-search me-1"></i>Search
                </button>
                <?php if ($search || $filter_role || $filter_action || $filter_campus): ?>
                <a href="index.php?page=<?= $role === 'admin' ? 'audit' : 'my_audit' ?>"
                   class="btn btn-outline-secondary btn-sm">
                    <i class="bi bi-x-circle me-1"></i>Clear
                </a>
                <?php endif; ?>
            </div>
        </div>
    </form>
</div>

<!-- Table -->
<div class="neon-card overflow-hidden p-0">
    <div class="table-responsive">
        <table class="table-modern mb-0 min-w-[760px]">
            <thead>
                <tr>
                    <th class="w-12 text-center">#</th>
                    <?php if ($role === 'admin'): ?>
                    <th>User</th>
                    <th class="w-28 text-center">Role</th>
                    <?php endif; ?>
                    <th class="w-52 text-center">Action</th>
                    <th>Details</th>
                    <th class="w-40 text-right">Date &amp; Time</th>
                </tr>
            </thead>
            <tbody>
            <?php if ($logs): foreach ($logs as $i => $log):
                [$bg, $tc, $ic] = actionBadge($log['action_performed']);
                $action_display = auditDisplayText($log['action_performed'] ?? '');
                $details_display = auditDisplayText($log['details'] ?? '');
            ?>
            <tr>
                <td class="text-center text-xs text-slate-400"><?= $offset + $i + 1 ?></td>
                <?php if ($role === 'admin'): ?>
                <td class="text-sm font-semibold text-slate-800">
                    <?php
                    $actor_role = $log['role_at_time'] ?? '';
                    $actor_display = $actor_role === 'evaluator'
                        ? checkerDisplayLabel(['checker_label' => $log['checker_label'] ?? null, 'user_id' => $log['user_id'] ?? null])
                        : ($log['full_name'] ?? 'System');
                    // Admin full_name is stored as "Last, First" — display without the comma for admin accounts
                    if ($actor_role === 'admin' && str_contains($actor_display, ',')) {
                        $parts_name = array_map('trim', explode(',', $actor_display, 2));
                        $actor_display = trim(($parts_name[1] ?? '') . ' ' . ($parts_name[0] ?? ''));
                    }
                    ?>
                    <?= sanitize($actor_display) ?>
                </td>
                <td class="text-center">
                    <?php
                    $role_display_map = ['admin'=>'Admin','evaluator'=>'Evaluator','faculty'=>'Faculty'];
                    $role_key = $log['role_at_time'] ?? '';
                    $role_badge_map = ['admin'=>'bg-secondary','evaluator'=>'bg-primary','faculty'=>'bg-light'];
                    ?>
                    <span class="badge <?= $role_badge_map[$role_key] ?? 'bg-secondary' ?>">
                        <?= sanitize($role_display_map[$role_key] ?? ($role_key ?: 'N/A')) ?>
                    </span>
                </td>
                <?php endif; ?>
                <td class="text-center">
                    <span class="badge bg-primary">
                        <i class="bi <?= $ic ?> text-xs"></i>
                        <?= sanitize($action_display) ?>
                    </span>
                </td>
                <td class="max-w-sm truncate text-sm text-slate-600"
                    title="<?= sanitize($details_display) ?>">
                    <?= sanitize($details_display) ?>
                </td>
                <td class="whitespace-nowrap text-right text-xs text-slate-400">
                    <?= date('M d, Y', strtotime($log['timestamp'])) ?>
                    <div class="text-xs text-slate-300"><?= date('h:i A', strtotime($log['timestamp'])) ?></div>
                </td>
            </tr>
            <?php endforeach; else: ?>
            <tr><td colspan="7" class="p-12 text-center text-slate-400">
                <i class="bi bi-inbox mb-2 block text-3xl text-slate-200"></i>
                No logs found.
            </td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>

    <!-- Pagination -->
    <?php if ($total_pages > 1): ?>
    <div class="flex flex-col gap-3 border-t border-slate-100 px-4 py-3 sm:flex-row sm:items-center sm:justify-between">
        <small class="text-slate-500">
            Showing <?= $offset + 1 ?>-<?= min($offset + $per_page, $total_rows) ?> of <?= number_format($total_rows) ?>
        </small>
        <ul class="pagination pagination-sm mb-0">
            <li class="page-item <?= $page_num <= 1 ? 'disabled' : '' ?>">
                <a class="page-link" href="<?= auditUrl(['p' => $page_num - 1]) ?>">‹</a>
            </li>
            <?php
            $start = max(1, $page_num - 2);
            $end   = min($total_pages, $page_num + 2);
            if ($start > 1): ?>
            <li class="page-item"><a class="page-link" href="<?= auditUrl(['p'=>1]) ?>">1</a></li>
            <?php if ($start > 2): ?><li class="page-item disabled"><span class="page-link">...</span></li><?php endif; ?>
            <?php endif; ?>
            <?php for ($pg = $start; $pg <= $end; $pg++): ?>
            <li class="page-item <?= $pg === $page_num ? 'active' : '' ?>">
                <a class="page-link" href="<?= auditUrl(['p' => $pg]) ?>"><?= $pg ?></a>
            </li>
            <?php endfor; ?>
            <?php if ($end < $total_pages): ?>
            <?php if ($end < $total_pages - 1): ?><li class="page-item disabled"><span class="page-link">...</span></li><?php endif; ?>
            <li class="page-item"><a class="page-link" href="<?= auditUrl(['p'=>$total_pages]) ?>"><?= $total_pages ?></a></li>
            <?php endif; ?>
            <li class="page-item <?= $page_num >= $total_pages ? 'disabled' : '' ?>">
                <a class="page-link" href="<?= auditUrl(['p' => $page_num + 1]) ?>">›</a>
            </li>
        </ul>
    </div>
    <?php endif; ?>
</div>
