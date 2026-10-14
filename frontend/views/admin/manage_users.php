<?php if ((require __DIR__ . '/../../../backend/controllers/admin/manage_users.php') === false) return; ?>

<?php showFlash(); ?>

<!-- Page Header Bar -->
<div class="mb-6 flex flex-wrap items-center justify-between gap-3 rounded-xl border border-slate-200 bg-white px-5 py-4 shadow-card">
    <div class="flex items-center [gap:0.65rem]">
        <div class="[width:38px] [height:38px] [border-radius:9px] [background:#1e4d8c] flex items-center justify-center shrink-0">
            <i class="bi bi-people-fill [color:#fff] [font-size:0.95rem]"></i>
        </div>
        <div>
            <div class="font-bold [color:#1a3a6b] [font-size:0.95rem] leading-tight">Manage Users</div>
            <div class="[font-size:0.72rem] [color:#94a3b8] [margin-top:2px]">Create and manage faculty and evaluator accounts</div>
        </div>
    </div>
    <button onclick="document.getElementById('createAccountModal').hidden=false"
            class="btn btn-primary btn-sm shrink-0">
        <i class="bi bi-person-plus-fill"></i> Add Account
    </button>
</div>

<!-- ── Create Account Modal ─────────────────────────────────────── -->
<div id="createAccountModal" hidden
     class="fixed inset-0 z-[99999] flex items-center justify-center bg-slate-950/55 p-4">
    <div class="w-full max-w-2xl animate-fade-up overflow-hidden rounded-2xl bg-white shadow-pop">

        <!-- Modal header -->
        <div class="[background:#1a3a6b] [padding:1rem_1.35rem] flex items-center justify-between">
            <div class="flex items-center [gap:0.6rem]">
                <div class="[width:30px] [height:30px] [border-radius:7px] [background:rgba(255,255,255,0.15)] flex items-center justify-center">
                    <i class="bi bi-person-plus-fill [color:#fff] [font-size:0.85rem]"></i>
                </div>
                <div>
                    <div class="[color:#fff] font-bold [font-size:0.92rem] leading-tight">Create New Account</div>
                    <div class="[color:#93c5fd] [font-size:0.7rem] [margin-top:1px]">Faculty or evaluator account</div>
                </div>
            </div>
            <button onclick="closeCreateModal()"
                    class="btn-close btn-close-white">
                <i class="bi bi-x-lg"></i>
            </button>
        </div>

        <!-- Modal body -->
        <div class="[padding:1.35rem_1.5rem]">
            <p class="[font-size:0.75rem] [color:#64748b] [margin-bottom:1.1rem]">
                Administrator-created accounts receive a temporary password the user can change from their
                <span class="[color:#1a3a6b] font-semibold">Profile</span> settings.
            </p>
            <form method="POST" id="createAccountForm">
                <input type="hidden" name="action" value="create_account">

                <!-- Account Details -->
                <div class="[font-size:0.68rem] font-bold uppercase [letter-spacing:0.08em] [color:#94a3b8] [margin-bottom:0.55rem] [padding-bottom:0.35rem] [border-bottom:1px_solid_#f1f5f9]">
                    Account Details
                </div>
                <div class="row g-2 mb-4">
                    <div class="col-sm-3">
                        <label class="form-label fw-semibold [font-size:0.78rem] [color:#334155]">Account Type <span class="text-danger">*</span></label>
                        <select name="role" class="form-select form-select-sm" required>
                            <option value="faculty">Faculty</option>
                            <option value="evaluator">Evaluator</option>
                        </select>
                    </div>
                    <div class="col-sm-3">
                        <label class="form-label fw-semibold [font-size:0.78rem] [color:#334155]">First Name <span class="text-danger">*</span></label>
                        <input type="text" name="first_name" class="form-control form-control-sm" required>
                    </div>
                    <div class="col-sm-3">
                        <label class="form-label fw-semibold [font-size:0.78rem] [color:#334155]">Middle Name</label>
                        <input type="text" name="middle_name" id="muMiddle" class="form-control form-control-sm">
                        <label class="[font-size:0.7rem] [color:#64748b] [margin-top:3px] flex [gap:0.35rem] items-center cursor-pointer">
                            <input type="checkbox" name="no_middle_name" onchange="document.getElementById('muMiddle').disabled=this.checked">
                            No middle name
                        </label>
                    </div>
                    <div class="col-sm-3">
                        <label class="form-label fw-semibold [font-size:0.78rem] [color:#334155]">Last Name <span class="text-danger">*</span></label>
                        <input type="text" name="last_name" class="form-control form-control-sm" required>
                    </div>
                </div>

                <!-- Login Credentials -->
                <div class="[font-size:0.68rem] font-bold uppercase [letter-spacing:0.08em] [color:#94a3b8] [margin-bottom:0.55rem] [padding-bottom:0.35rem] [border-bottom:1px_solid_#f1f5f9]">
                    Login Credentials
                </div>
                <div class="row g-2 mb-1">
                    <div class="col-sm-6">
                        <label class="form-label fw-semibold [font-size:0.78rem] [color:#334155]">Email <span class="text-danger">*</span></label>
                        <input type="email" name="email" class="form-control form-control-sm" required>
                    </div>
                    <div class="col-sm-6">
                        <label class="form-label fw-semibold [font-size:0.78rem] [color:#334155]">Temporary Password <span class="text-danger">*</span></label>
                        <div class="input-group input-group-sm">
                            <input type="password" name="password" id="muPassword" class="form-control" minlength="8" required>
                            <button type="button" class="btn btn-outline-secondary" onclick="toggleMuPw()" title="Show/hide"><i class="bi bi-eye"></i></button>
                            <button type="button" class="btn btn-outline-secondary" onclick="generateMuPw()" title="Generate"><i class="bi bi-shuffle"></i></button>
                        </div>
                    </div>
                </div>

                <!-- Modal footer actions -->
                <div class="flex items-center justify-end [gap:0.5rem] [padding-top:1rem] [margin-top:0.75rem] [border-top:1px_solid_#f1f5f9]">
                    <button type="button" onclick="closeCreateModal()" class="btn btn-secondary btn-sm">
                        Cancel
                    </button>
                    <button type="submit" class="btn btn-primary btn-sm">
                        <i class="bi bi-person-plus me-1"></i>Create Account
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>
<div class="neon-card mb-6 p-0 overflow-hidden [border:1px_solid_#e2e8f0]">
    <!-- KRA Assignment header — always visible, acts as the toggle -->
    <div class="flex cursor-pointer flex-wrap items-center justify-between gap-3 border-b border-slate-100 bg-slate-50 px-5 py-3.5"
         onclick="toggleKraPanel()">
        <div class="flex items-center [gap:0.6rem]">
            <div class="[width:36px] [height:36px] [border-radius:8px] [background:#1e4d8c] flex items-center justify-center [color:#fff] shrink-0">
                <i class="bi bi-ui-checks-grid [font-size:0.9rem]"></i>
            </div>
            <div>
                <div class="font-bold [color:#1a3a6b] [font-size:0.95rem]">KRA Assignment</div>
                <div class="[font-size:0.72rem] [color:#94a3b8] [margin-top:1px]">Control which KRAs each evaluator can review for a cycle.</div>
            </div>
        </div>
        <div class="flex w-full flex-wrap items-center gap-3 sm:w-auto sm:flex-nowrap">
            <?php if ($cycles_for_kra): ?>
            <form method="GET" action="index.php" class="d-flex gap-2 align-items-end" onclick="event.stopPropagation()">
                <input type="hidden" name="page" value="manage_users">
                <select name="assignment_cycle_id" class="form-select form-select-sm w-full sm:min-w-60" onchange="this.form.submit()">
                    <?php foreach ($cycles_for_kra as $cy): ?>
                    <option value="<?= (int)$cy['cycle_id'] ?>" <?= $assignment_cycle_id===(int)$cy['cycle_id']?'selected':'' ?>>
                        <?= sanitize($cy['cycle_name']) ?> (<?= ucfirst($cy['status']) ?>)
                    </option>
                    <?php endforeach; ?>
                </select>
            </form>
            <?php endif; ?>
            <i class="bi bi-chevron-down [color:#94a3b8] [font-size:0.75rem] [transition:transform_0.2s] shrink-0" id="kraChevron"
              ></i>
        </div>
    </div>

    <!-- KRA body — hidden by default -->
    <div id="kraPanel" hidden class="px-6 py-5">
        <?php if (!$cycles_for_kra): ?>
        <div class="[background:#f8fafc] [border:1px_solid_#e2e8f0] [border-radius:7px] [padding:0.65rem_0.9rem] [font-size:0.8rem] [color:#475569]">
            Create a cycle first before assigning KRAs to evaluators.
        </div>
        <?php else: ?>
            <?php $missing_kras = array_keys(array_filter($coverage_counts, fn($count) => (int)$count === 0)); ?>
            <?php if ($missing_kras): ?>
            <div class="flex items-start [gap:0.5rem] [background:#fffbeb] [border:1px_solid_#fde68a] [border-radius:7px] [padding:0.6rem_0.85rem] [margin-bottom:1rem] [font-size:0.78rem] [color:#92400e]">
                <i class="bi bi-exclamation-triangle-fill shrink-0 [margin-top:1px]"></i>
                <span>No active evaluator is assigned to: <strong><?= htmlspecialchars(implode(', ', array_map(fn($kra) => $kra_categories[$kra], $missing_kras))) ?></strong>. Submissions under these KRAs cannot be fully evaluated.</span>
            </div>
            <?php endif; ?>

            <?php if (count($cycles_for_kra) > 1): ?>
            <form method="POST" class="d-flex gap-2 align-items-center flex-wrap mb-4 [background:#f8fafc] [border:1px_solid_#e2e8f0] [border-radius:8px] [padding:0.65rem_0.9rem]"
                 >
                <input type="hidden" name="action" value="copy_kra_assignments">
                <input type="hidden" name="to_cycle_id" value="<?= (int)$assignment_cycle_id ?>">
                <span class="[font-size:0.78rem] [color:#475569] font-semibold">Copy setup from</span>
                <select name="from_cycle_id" class="form-select form-select-sm [max-width:300px]">
                    <?php foreach ($cycles_for_kra as $cy): if ((int)$cy['cycle_id'] === $assignment_cycle_id) continue; ?>
                    <option value="<?= (int)$cy['cycle_id'] ?>"><?= sanitize($cy['cycle_name']) ?> (<?= ucfirst($cy['status']) ?>)</option>
                    <?php endforeach; ?>
                </select>
                <button class="btn btn-sm btn-outline-secondary">Copy</button>
            </form>
            <?php endif; ?>

            <form method="POST">
                <input type="hidden" name="action" value="update_kra_assignments">
                <input type="hidden" name="cycle_id" value="<?= (int)$assignment_cycle_id ?>">
                <div class="table-responsive">
                    <table class="table-modern mb-2 min-w-[640px]">
                        <thead>
                            <tr class="[background:#f8fafc]">
                                <th>Evaluator</th>
                                <?php foreach ($kra_categories as $kra => $label): ?>
                                <th class="text-center">
                                    <?= $label ?>
                                    <div class="[font-size:0.66rem] [color:#94a3b8] font-medium"><?= sanitize($kra) ?></div>
                                </th>
                                <?php endforeach; ?>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($active_checkers as $checker):
                            $cid = (int)$checker['user_id'];
                            $assigned = $assignment_map[$cid] ?? [];
                            $inactive = $checker['status'] !== 'active';
                        ?>
                        <tr class="<?= $inactive ? 'opacity-60' : '' ?>">
                            <td>
                                <strong class="[color:#1e293b]"><?= htmlspecialchars(checkerDisplayLabel($checker)) ?></strong>
                                <div class="[font-size:0.72rem] [color:#94a3b8]"><?= sanitize($checker['email'] ?? '') ?><?= $inactive ? ' — inactive' : '' ?></div>
                            </td>
                            <?php foreach ($kra_categories as $kra => $label): ?>
                            <td class="text-center">
                                <input type="checkbox"
                                       name="kra_assignments[<?= $cid ?>][]"
                                       value="<?= htmlspecialchars($kra) ?>"
                                       <?= in_array($kra, $assigned, true) ? 'checked' : '' ?>
                                       <?= $inactive ? 'disabled' : '' ?>
                                       class="[width:18px] [height:18px]">
                            </td>
                            <?php endforeach; ?>
                        </tr>
                        <?php endforeach; ?>
                        <?php if (!$active_checkers): ?>
                        <tr><td colspan="5" class="text-center py-6 text-muted [font-size:0.82rem]">No evaluator accounts found.</td></tr>
                        <?php endif; ?>
                        </tbody>
                        <tfoot>
                            <tr class="[background:#f8fafc]">
                                <td class="font-bold [color:#1a3a6b] [font-size:0.8rem]">Coverage</td>
                                <?php foreach ($kra_categories as $kra => $label): ?>
                                <td class="text-center">
                                    <span class="badge <?= ($coverage_counts[$kra] ?? 0) > 0 ? 'bg-primary' : 'bg-warning' ?>">
                                        <?= (int)($coverage_counts[$kra] ?? 0) ?> evaluator<?= (int)($coverage_counts[$kra] ?? 0) === 1 ? '' : 's' ?>
                                    </span>
                                </td>
                                <?php endforeach; ?>
                            </tr>
                        </tfoot>
                    </table>
                </div>
                <div class="flex justify-end [padding-top:0.5rem] [border-top:1px_solid_#f1f5f9]">
                    <button class="btn btn-primary btn-sm [background:#1a3a6b] [border-color:#1a3a6b] [font-size:0.82rem] [padding:0.42rem_1rem]"
                           >
                        Save KRA Assignments
                    </button>
                </div>
            </form>
        <?php endif; ?>
    </div>
</div>

<div class="neon-card [padding:1rem_1.25rem]">
    <div class="flex items-center justify-between [margin-bottom:0.75rem] flex-wrap [gap:0.75rem]">
        <div class="flex items-center [gap:0.6rem]">
            <div class="[width:36px] [height:36px] [border-radius:8px] [background:#1e4d8c] flex items-center justify-center [color:#fff]"><i class="bi bi-people-fill"></i></div>
            <div>
                <div class="font-bold [color:#1a3a6b] [font-size:0.95rem]">Manage Users</div>
                <div class="[font-size:0.72rem] [color:#94a3b8] [margin-top:1px]"><?= count($users) ?> user<?= count($users)!==1?'s':'' ?> found</div>
            </div>
        </div>
    </div>

    <form method="GET" action="index.php" class="mb-4">
        <input type="hidden" name="page" value="manage_users">
        <div class="row g-2 align-items-end">
            <div class="col-md-4"><label class="form-label">Search</label><input type="text" name="search" value="<?= sanitize($search) ?>" class="form-control" placeholder="Name, email, employee ID..."></div>
            <div class="col-md-2"><label class="form-label">Role</label><select name="role_filter" class="form-select" onchange="this.form.submit()"><option value="">All Roles</option><option value="faculty" <?= $filter==='faculty'?'selected':'' ?>>Faculty</option><option value="evaluator" <?= $filter==='evaluator'?'selected':'' ?>>Evaluator</option><option value="admin" <?= $filter==='admin'?'selected':'' ?>>Admin</option></select></div>
            <div class="col-md-3"><label class="form-label">Campus</label><select name="campus_id" class="form-select" onchange="this.form.submit()"><option value="">All Campuses</option><?php foreach ($campuses as $c): ?><option value="<?= (int)$c['campus_id'] ?>" <?= $filter_campus===(int)$c['campus_id']?'selected':'' ?>><?= sanitize($c['campus_name']) ?></option><?php endforeach; ?></select></div>
            <div class="col-md-3 d-flex gap-2"><button type="submit" class="btn btn-primary"><i class="bi bi-search"></i> Filter</button><?php if ($search || $filter || $filter_campus): ?><a href="index.php?page=manage_users" class="btn btn-outline-secondary">Clear</a><?php endif; ?></div>
        </div>
    </form>

    <div class="table-responsive">
        <table class="table-modern mb-0 min-w-[1100px]">
            <thead><tr class="[background:#f8fafc]"><th>Name</th><th>Email</th><th>Employee ID</th><th>Campus</th><th>Rank</th><th>Role</th><th>Status</th><th>Apps</th><th>Joined</th><th>Actions</th></tr></thead>
            <tbody>
            <?php foreach ($users as $u):
                $role_label = match($u['role']) { 'evaluator' => 'Evaluator', 'admin' => 'Admin', default => 'Faculty' };
                $status_label = ucfirst($u['status']);
                $name = formatDisplayName($u);
            ?>
            <tr>
                <td><strong class="[color:#1e293b]"><?= htmlspecialchars($name) ?></strong></td>
                <td class="[min-width:230px]">
                    <form method="POST" class="d-flex gap-1">
                        <input type="hidden" name="action" value="update_email">
                        <input type="hidden" name="uid" value="<?= (int)$u['user_id'] ?>">
                        <input type="email" name="email" value="<?= sanitize($u['email']) ?>" class="form-control form-control-sm" required>
                        <button class="btn btn-sm btn-outline-secondary" title="Save email"><i class="bi bi-save"></i></button>
                    </form>
                </td>
                <td><?= sanitize($u['employee_id'] ?? 'N/A') ?></td>
                <td><?= sanitize($u['campus_name'] ?? 'N/A') ?></td>
                <td><?= sanitize($u['rank'] ?? 'N/A') ?></td>
                <td><span class="badge [background:#eff6ff] [color:#1e4d8c] [border:1px_solid_#1e4d8c33]"><?= $role_label ?></span></td>
                <td><span class="badge <?= $u['status']==='active' ? 'bg-success' : 'bg-danger' ?>"><?= $status_label ?></span></td>
                <td><?= (int)$u['app_count'] ?></td>
                <td class="whitespace-nowrap [color:#94a3b8]"><?= date('M d, Y', strtotime($u['created_at'])) ?></td>
                <td>
                    <?php if ((int)$u['user_id'] !== (int)$_SESSION['user_id']): ?>
                    <div class="d-flex gap-1 flex-wrap">
                        <form method="POST" id="statusForm_<?= (int)$u['user_id'] ?>">
                            <input type="hidden" name="action" value="<?= $u['status']==='inactive' ? 'set_active' : 'set_inactive' ?>">
                            <input type="hidden" name="uid" value="<?= (int)$u['user_id'] ?>">
                            <button type="button" class="btn btn-sm <?= $u['status']==='inactive' ? 'btn-outline-primary' : 'btn-outline-secondary' ?>" onclick="confirmDelete('<?= $u['status']==='inactive' ? 'Reactivate' : 'Deactivate' ?> this account?','statusForm_<?= (int)$u['user_id'] ?>','<?= $u['status']==='inactive' ? 'Reactivate' : 'Deactivate' ?>','bi-person-check')">
                                <i class="bi <?= $u['status']==='inactive' ? 'bi-person-check' : 'bi-person-slash' ?>"></i>
                            </button>
                        </form>
                        <button type="button" class="btn btn-sm btn-outline-secondary" onclick="openResetModal(<?= (int)$u['user_id'] ?>,'<?= htmlspecialchars($name, ENT_QUOTES) ?>')" title="Reset password"><i class="bi bi-key"></i></button>
                        <form method="POST" id="deleteUserForm_<?= (int)$u['user_id'] ?>">
                            <input type="hidden" name="action" value="delete_user"><input type="hidden" name="uid" value="<?= (int)$u['user_id'] ?>">
                            <button type="button" class="btn btn-sm btn-outline-danger" onclick="confirmDelete('Delete &ldquo;<?= htmlspecialchars($name, ENT_QUOTES) ?>&rdquo;? This cannot be undone.','deleteUserForm_<?= (int)$u['user_id'] ?>')"><i class="bi bi-trash"></i></button>
                        </form>
                    </div>
                    <?php else: ?><span class="[color:#94a3b8] italic">You</span><?php endif; ?>
                </td>
            </tr>
            <?php endforeach; ?>
            <?php if (!$users): ?><tr><td colspan="10" class="text-center py-12 text-muted">No users found.</td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<div id="resetPwModal" hidden class="fixed inset-0 z-[9999] flex items-center justify-center bg-slate-950/55 p-4">
    <div class="bg-white [border-radius:12px] w-full [max-width:420px] [border:1px_solid_#e2e8f0] [box-shadow:0_20px_60px_rgba(0,0,0,0.22)]">
        <div class="[padding:1rem_1.25rem] [border-bottom:1px_solid_#e2e8f0] flex justify-between items-center">
            <strong class="[color:#1a3a6b]">Reset Password</strong>
            <button type="button" onclick="document.getElementById('resetPwModal').hidden=true" class="btn-close" aria-label="Close"></button>
        </div>
        <form method="POST" class="[padding:1.25rem]">
            <input type="hidden" name="action" value="reset_password">
            <input type="hidden" name="uid" id="resetUid">
            <div class="[font-size:0.84rem] [color:#475569] [margin-bottom:0.75rem]">Set a new temporary password for <strong id="resetName"></strong>. The user can change it later from their Profile settings.</div>
            <label class="form-label">Temporary Password</label>
            <div class="input-group">
                <input type="text" name="temp_password" id="resetTempPw" class="form-control" minlength="8">
                <button type="button" class="btn btn-outline-secondary" onclick="document.getElementById('resetTempPw').value=makePw()"><i class="bi bi-shuffle"></i></button>
            </div>
            <div class="d-flex justify-content-end gap-2 mt-4">
                <button type="button" class="btn btn-outline-secondary" onclick="document.getElementById('resetPwModal').hidden=true">Cancel</button>
                <button class="btn btn-primary">Reset Password</button>
            </div>
        </form>
    </div>
</div>

<script>
function makePw() {
    const chars = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghjkmnpqrstuvwxyz23456789@#!';
    let out = chars[Math.floor(Math.random() * 26)];
    for (let i = 0; i < 5; i++) out += chars[26 + Math.floor(Math.random() * 24)];
    out += String(Math.floor(10 + Math.random() * 90));
    out += chars[52 + Math.floor(Math.random() * (chars.length - 52))];
    return out;
}
function generateMuPw() { document.getElementById('muPassword').value = makePw(); }
function toggleMuPw() {
    const el = document.getElementById('muPassword');
    el.type = el.type === 'password' ? 'text' : 'password';
}
function closeCreateModal() {
    document.getElementById('createAccountModal').hidden = true;
}
// Close on backdrop click
document.getElementById('createAccountModal').addEventListener('click', function(e) {
    if (e.target === this) closeCreateModal();
});
function toggleKraPanel() {
    const panel   = document.getElementById('kraPanel');
    const chevron = document.getElementById('kraChevron');
    const isOpen = !panel.hidden;
    panel.hidden = isOpen;
    chevron.classList.toggle('rotate-180', !isOpen);
}
// Auto-open the create panel if there was a form error (flash danger message present)
document.addEventListener('DOMContentLoaded', function() {
    const flash = document.querySelector('.alert-danger');
    if (flash) {
        const panel = document.getElementById('createAccountPanel');
        if (panel) panel.hidden = false;
    }
    // Re-open KRA panel after saving assignments (URL has assignment_cycle_id)
    if (new URLSearchParams(window.location.search).has('assignment_cycle_id')) {
        const kp = document.getElementById('kraPanel');
        const kc = document.getElementById('kraChevron');
        if (kp) kp.hidden = false;
        if (kc) kc.classList.add('rotate-180');
    }
});
function openResetModal(uid, name) {
    document.getElementById('resetUid').value = uid;
    document.getElementById('resetName').textContent = name;
    document.getElementById('resetTempPw').value = makePw();
    document.getElementById('resetPwModal').hidden = false;
}
</script>
