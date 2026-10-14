<?php if ((require __DIR__ . '/../../../backend/controllers/admin/manage_campuses.php') === false) return; ?>

<?php showFlash(); ?>

<!-- Add campus card -->
<div class="neon-card mb-6 p-5">
    <div class="flex items-center [gap:0.6rem] [margin-bottom:1rem]">
        <div class="[width:36px] [height:36px] [border-radius:8px] [background:#1e4d8c] flex items-center justify-center shrink-0">
            <i class="bi bi-geo-alt-fill [color:#fff] [font-size:0.9rem]"></i>
        </div>
        <div>
            <div class="font-bold [color:#1a3a6b] [font-size:0.95rem]">Add New Campus</div>
            <div class="[font-size:0.72rem] [color:#94a3b8]">Register a new campus location</div>
        </div>
    </div>
    <form method="POST" class="flex flex-col gap-4 sm:flex-row sm:items-end" id="addCampusForm">
        <input type="hidden" name="action" value="add_campus">
        <div class="flex-1 [min-width:220px]">
            <label class="[font-size:0.72rem] font-semibold [color:#64748b] uppercase [letter-spacing:0.05em] block [margin-bottom:5px]">Campus Name <span class="[color:#334155]">*</span></label>
            <div class="flex items-center overflow-hidden rounded-xl border border-slate-200 bg-slate-50 focus-within:border-brand-500 focus-within:ring-4 focus-within:ring-brand-100">
                <span class="[padding:0_0.65rem] [color:#94a3b8] [font-size:0.85rem]"><i class="bi bi-building"></i></span>
                <input type="text" name="campus_name" placeholder="e.g. CHMSU-Main Campus"
                       required minlength="2" pattern=".*\S.*"
                       class="flex-1 border-0 outline-none bg-transparent [padding:0.5rem_0.5rem_0.5rem_0] [font-size:0.85rem] [color:#1e293b]">
            </div>
        </div>
        <button type="button"
                class="btn btn-success w-full sm:w-auto"
                onclick="confirmDelete('Add this new campus?','addCampusForm','Add Campus','bi-plus-circle')">
            <i class="bi bi-plus-circle me-1"></i>Add Campus
        </button>
    </form>
</div>

<!-- Campus list card -->
<div class="neon-card p-0 overflow-hidden">
    <div class="[padding:0.9rem_1.25rem] [border-bottom:1px_solid_#f1f5f9] flex items-center justify-between flex-wrap [gap:0.75rem]">
        <div class="flex items-center [gap:0.6rem]">
            <div class="[width:36px] [height:36px] [border-radius:8px] [background:#1e4d8c] flex items-center justify-center shrink-0">
                <i class="bi bi-list-ul [color:#fff] [font-size:0.9rem]"></i>
            </div>
            <div>
                <div class="font-bold [color:#1a3a6b] [font-size:0.95rem]">All Campuses</div>
                <div class="[font-size:0.72rem] [color:#94a3b8]"><?= count($campuses) ?> campus<?= count($campuses)!==1?'es':'' ?></div>
            </div>
        </div>
        <form method="GET" action="index.php" class="flex w-full flex-wrap items-center gap-2 sm:w-auto">
            <input type="hidden" name="page" value="manage_campuses">
            <div class="flex min-w-0 flex-1 items-center overflow-hidden rounded-xl border border-slate-200 bg-slate-50 focus-within:border-brand-500 focus-within:ring-4 focus-within:ring-brand-100 sm:flex-none">
                <span class="[padding:0_0.65rem] [color:#94a3b8] [font-size:0.82rem]"><i class="bi bi-search"></i></span>
                <input type="text" name="search" value="<?= sanitize($search) ?>" placeholder="Search campus..."
                       class="min-w-0 flex-1 border-0 bg-transparent py-2 pr-2 text-sm text-slate-800 outline-none sm:w-44">
            </div>
            <button type="submit" class="btn btn-primary btn-sm">
                Search
            </button>
            <?php if ($search): ?>
            <a href="index.php?page=manage_campuses"
               class="btn btn-secondary btn-sm">
                Clear
            </a>
            <?php endif; ?>
        </form>
    </div>
    <div class="table-responsive">
        <table class="table-modern mb-0 min-w-[640px]">
            <thead>
                <tr>
                    <th>Campus Name</th>
                    <th class="text-center">Users</th>
                    <th class="text-center">Status</th>
                    <th>Created</th>
                    <th class="text-center">Action</th>
                </tr>
            </thead>
            <tbody>
            <?php if ($campuses): ?>
            <?php foreach ($campuses as $c): ?>
            <tr>
                <td class="[padding:0.75rem_1rem] font-semibold [color:#1e293b]">
                    <div class="flex items-center [gap:0.5rem]">
                        <div class="size-2 shrink-0 rounded-full <?= $c['is_active'] ? 'bg-brand-600' : 'bg-slate-300' ?>"></div>
                        <?= sanitize($c['campus_name']) ?>
                    </div>
                </td>
                <td class="[padding:0.75rem_0.75rem] text-center">
                    <?php if ($c['user_count'] > 0): ?>
                    <span class="[background:#eff6ff] [color:#1e4d8c] font-bold [font-size:0.78rem] [border-radius:20px] [padding:2px_9px]"><?= $c['user_count'] ?></span>
                    <?php else: ?><span class="[color:#cbd5e1] [font-size:0.78rem]"> - </span><?php endif; ?>
                </td>
                <td class="[padding:0.75rem_0.75rem] text-center">
                    <form method="POST" class="d-inline" id="toggleForm_<?= $c['campus_id'] ?>">
                        <input type="hidden" name="action" value="toggle_active">
                        <input type="hidden" name="campus_id" value="<?= $c['campus_id'] ?>">
                        <input type="hidden" name="is_active" value="<?= $c['is_active'] ? 0 : 1 ?>">
                        <button type="button"
                                class="pill cursor-pointer <?= $c['is_active'] ? 'pill-success' : 'pill-danger' ?>"
                                onclick="confirmDelete('<?= $c['is_active']?'Deactivate':'Activate' ?> this campus?','toggleForm_<?= $c['campus_id'] ?>','<?= $c['is_active']?'Deactivate':'Activate' ?>','bi-toggle-<?= $c['is_active']?'off':'on' ?>')">
                            <i class="bi bi-toggle-<?= $c['is_active']?'on':'off' ?> me-1"></i>
                            <?= $c['is_active'] ? 'Active' : 'Inactive' ?>
                        </button>
                    </form>
                </td>
                <td class="[padding:0.75rem_0.75rem] [color:#94a3b8] [font-size:0.78rem]"><?= date('M d, Y', strtotime($c['created_at'])) ?></td>
                <td class="[padding:0.75rem_0.75rem] text-center">
                    <form method="POST" id="deleteCampusForm_<?= $c['campus_id'] ?>">
                        <input type="hidden" name="action" value="delete_campus">
                        <input type="hidden" name="campus_id" value="<?= $c['campus_id'] ?>">
                        <?php if (intval($c['user_count']) > 0): ?>
                        <button type="button"
                                class="[padding:3px_8px] [border-radius:6px] [background:#f1f5f9] [color:#cbd5e1] [border:1px_solid_#e2e8f0] [font-size:0.75rem] cursor-not-allowed"
                                disabled title="Cannot delete  -  reassign the <?= intval($c['user_count']) ?> user(s) first">
                            <i class="bi bi-trash"></i>
                        </button>
                        <?php else: ?>
                        <button type="button"
                                class="[padding:3px_8px] [border-radius:6px] [background:#fef2f2] [color:#dc2626] [border:1px_solid_#fecaca] [font-size:0.75rem] cursor-pointer"
                                onclick="confirmDelete('Delete &ldquo;<?= sanitize($c['campus_name']) ?>&rdquo;? Cannot be undone.','deleteCampusForm_<?= $c['campus_id'] ?>')">
                            <i class="bi bi-trash"></i>
                        </button>
                        <?php endif; ?>
                    </form>
                </td>
            </tr>
            <?php endforeach; ?>
            <?php else: ?>
            <tr><td colspan="5" class="[padding:3rem] text-center [color:#94a3b8]">
                <i class="bi bi-geo-alt [font-size:2rem] block [margin-bottom:0.5rem] [color:#e2e8f0]"></i>
                No campuses found<?= $search ? ' matching "'.sanitize($search).'"' : '' ?>.
            </td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

