<?php
if (!isAdmin()) { echo '<div class="alert alert-danger">Access denied.</div>'; return; }

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'approve_user') {
        $uid = intval($_POST['uid']);
        $pdo->prepare("UPDATE users SET status='active' WHERE user_id=?")->execute([$uid]);
        logAudit($pdo, $_SESSION['user_id'], 'User Approved', "Approved registration for user ID {$uid}.");
        flashMessage('success', 'User approved and activated.');
    } elseif ($action === 'reject_user') {
        $uid = intval($_POST['uid']);
        $pdo->prepare("UPDATE users SET status='rejected' WHERE user_id=?")->execute([$uid]);
        logAudit($pdo, $_SESSION['user_id'], 'User Rejected', "Rejected registration for user ID {$uid}.");
        flashMessage('warning', 'User registration rejected.');
    } elseif ($action === 'set_inactive') {
        $uid = intval($_POST['uid']);
        if ($uid !== $_SESSION['user_id']) {
            $pdo->prepare("UPDATE users SET status='inactive' WHERE user_id=?")->execute([$uid]);
            logAudit($pdo, $_SESSION['user_id'], 'User Deactivated', "Set user ID {$uid} to inactive.");
            flashMessage('warning', 'User has been set to <strong>inactive</strong>. They can no longer log in.');
        }
    } elseif ($action === 'set_active') {
        $uid = intval($_POST['uid']);
        $pdo->prepare("UPDATE users SET status='active' WHERE user_id=?")->execute([$uid]);
        logAudit($pdo, $_SESSION['user_id'], 'User Reactivated', "Reactivated user ID {$uid}.");
        flashMessage('success', 'User has been <strong>reactivated</strong>.');
    } elseif ($action === 'add_checker') {
        $first_name  = trim($_POST['first_name'] ?? '');
        $middle_name = trim($_POST['middle_name'] ?? '');
        $last_name   = trim($_POST['last_name'] ?? '');
        $email       = trim($_POST['email'] ?? '');
        $password    = $_POST['password'] ?? '';
        $checker_role = $_POST['checker_role'] ?? '';

        if (!$first_name || !$last_name || !$email || !$password || !in_array($checker_role, ['checker','talisay_checker'])) {
            flashMessage('danger', 'Please fill in all required fields to add a checker.');
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            flashMessage('danger', 'Invalid email address.');
        } elseif (strlen($password) < 8) {
            flashMessage('danger', 'Password must be at least 8 characters.');
        } else {
            $dup = $pdo->prepare("SELECT user_id FROM users WHERE email = ?");
            $dup->execute([$email]);
            if ($dup->fetch()) {
                flashMessage('danger', 'An account with this email already exists.');
            } else {
                $label = nextCheckerLabel($pdo);
                $hash  = password_hash($password, PASSWORD_DEFAULT);
                $pdo->prepare("INSERT INTO users (first_name, middle_name, last_name, email, password, role, status, checker_label) VALUES (?, ?, ?, ?, ?, ?, 'active', ?)")
                    ->execute([$first_name, ($middle_name ?: null), $last_name, $email, $hash, $checker_role, $label]);
                logAudit($pdo, $_SESSION['user_id'], 'Checker Account Created', "Created {$label} (" . ($checker_role === 'talisay_checker' ? 'Talisay Checker' : 'Checker') . ") account. Email: {$email}.");
                flashMessage('success', "Checker account created as <strong>{$label}</strong>.");
            }
        }
    } elseif ($action === 'delete_user') {
        $uid = intval($_POST['uid']);
        if ($uid !== $_SESSION['user_id']) {
            $name_row = $pdo->prepare("SELECT full_name FROM users WHERE user_id=?");
            $name_row->execute([$uid]);
            $deleted_name = $name_row->fetchColumn() ?: "ID {$uid}";
            $pdo->prepare("DELETE FROM users WHERE user_id=?")->execute([$uid]);
            logAudit($pdo, $_SESSION['user_id'], 'User Deleted', "Deleted user {$deleted_name}.");
            flashMessage('success', "User <strong>{$deleted_name}</strong> deleted.");
        }
    }
    echo "<script>window.location.href='index.php?page=manage_users';</script>"; exit;
}

$search        = trim($_GET['search'] ?? '');
$filter        = $_GET['role_filter'] ?? '';
$filter_campus = intval($_GET['campus_id'] ?? 0);

// Fetch all campuses for dropdown
$campuses = $pdo->query("SELECT campus_id, campus_name FROM campuses ORDER BY campus_name ASC")->fetchAll();

// Accounts are activated immediately on registration — no pending approval queue
$query = "SELECT u.*, u.first_name, u.middle_name, u.last_name, c.campus_name,
    (SELECT COUNT(*) FROM applications a WHERE a.user_id = u.user_id AND a.status != 'draft') as app_count
    FROM users u
    LEFT JOIN campuses c ON u.campus_id = c.campus_id
    WHERE 1=1";
$params = [];
if ($search)        { $query .= " AND (u.full_name LIKE ? OR u.email LIKE ? OR u.employee_id LIKE ?)"; $params = array_merge($params, ["%$search%","%$search%","%$search%"]); }
if ($filter)        { $query .= " AND u.role = ?"; $params[] = $filter; }
if ($filter_campus) { $query .= " AND u.campus_id = ?"; $params[] = $filter_campus; }
$query .= " ORDER BY u.created_at DESC";
$users = $pdo->prepare($query);
$users->execute($params);
$users = $users->fetchAll();
?>

<?php showFlash(); ?>

<!-- Add checker card -->
<div class="neon-card mb-4" style="padding:1.1rem 1.25rem;">
    <div style="display:flex;align-items:center;gap:0.6rem;margin-bottom:1rem;">
        <div style="width:36px;height:36px;border-radius:8px;background:#1e4d8c;display:flex;align-items:center;justify-content:center;flex-shrink:0;">
            <i class="bi bi-person-plus-fill" style="color:#fff;font-size:0.9rem;"></i>
        </div>
        <div>
            <div style="font-weight:700;color:#1a3a6b;font-size:0.95rem;">Add Checker</div>
            <div style="font-size:0.72rem;color:#94a3b8;">Create a checker account directly &mdash; they will appear anonymously to faculty as an auto-numbered "Checker"</div>
        </div>
    </div>
    <form method="POST" class="d-flex gap-3 align-items-end flex-wrap" id="addCheckerForm">
        <input type="hidden" name="action" value="add_checker">
        <div style="min-width:160px;">
            <label style="font-size:0.72rem;font-weight:600;color:#64748b;text-transform:uppercase;letter-spacing:0.05em;display:block;margin-bottom:5px;">First Name <span style="color:#334155;">*</span></label>
            <input type="text" name="first_name" required pattern=".*\S.*"
                   style="width:100%;border:1.5px solid #e2e8f0;border-radius:8px;padding:0.5rem 0.65rem;font-size:0.85rem;color:#1e293b;background:#f8fafc;outline:none;">
        </div>
        <div style="min-width:140px;">
            <label style="font-size:0.72rem;font-weight:600;color:#64748b;text-transform:uppercase;letter-spacing:0.05em;display:block;margin-bottom:5px;">Middle Name</label>
            <input type="text" name="middle_name"
                   style="width:100%;border:1.5px solid #e2e8f0;border-radius:8px;padding:0.5rem 0.65rem;font-size:0.85rem;color:#1e293b;background:#f8fafc;outline:none;">
        </div>
        <div style="min-width:160px;">
            <label style="font-size:0.72rem;font-weight:600;color:#64748b;text-transform:uppercase;letter-spacing:0.05em;display:block;margin-bottom:5px;">Last Name <span style="color:#334155;">*</span></label>
            <input type="text" name="last_name" required pattern=".*\S.*"
                   style="width:100%;border:1.5px solid #e2e8f0;border-radius:8px;padding:0.5rem 0.65rem;font-size:0.85rem;color:#1e293b;background:#f8fafc;outline:none;">
        </div>
        <div style="flex:1;min-width:200px;">
            <label style="font-size:0.72rem;font-weight:600;color:#64748b;text-transform:uppercase;letter-spacing:0.05em;display:block;margin-bottom:5px;">Email <span style="color:#334155;">*</span></label>
            <input type="email" name="email" required
                   style="width:100%;border:1.5px solid #e2e8f0;border-radius:8px;padding:0.5rem 0.65rem;font-size:0.85rem;color:#1e293b;background:#f8fafc;outline:none;">
        </div>
        <div style="min-width:170px;">
            <label style="font-size:0.72rem;font-weight:600;color:#64748b;text-transform:uppercase;letter-spacing:0.05em;display:block;margin-bottom:5px;">Password <span style="color:#334155;">*</span></label>
            <input type="password" name="password" required minlength="8" placeholder="Min. 8 characters"
                   style="width:100%;border:1.5px solid #e2e8f0;border-radius:8px;padding:0.5rem 0.65rem;font-size:0.85rem;color:#1e293b;background:#f8fafc;outline:none;">
        </div>
        <div style="min-width:150px;">
            <label style="font-size:0.72rem;font-weight:600;color:#64748b;text-transform:uppercase;letter-spacing:0.05em;display:block;margin-bottom:5px;">Role <span style="color:#334155;">*</span></label>
            <select name="checker_role" required
                    style="width:100%;border:1.5px solid #e2e8f0;border-radius:8px;padding:0.5rem 0.65rem;font-size:0.85rem;color:#1e293b;background:#f8fafc;outline:none;cursor:pointer;">
                <option value="checker">Checker</option>
                <option value="talisay_checker">Talisay Checker</option>
            </select>
        </div>
        <button type="button"
                style="background:#16a34a;color:#fff;border:1px solid #16a34a;border-radius:8px;padding:0.5rem 1.35rem;font-size:0.85rem;font-weight:600;cursor:pointer;white-space:nowrap;"
                onclick="if(document.getElementById('addCheckerForm').reportValidity()) confirmDelete('Create this checker account?','addCheckerForm','Add Checker','bi-person-plus')">
            <i class="bi bi-person-plus me-1"></i>Add Checker
        </button>
    </form>
</div>

<div class="neon-card" style="padding:1rem 1.25rem;">
    <!-- Header -->
    <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:0.75rem;flex-wrap:wrap;gap:0.75rem;">
        <div style="display:flex;align-items:center;gap:0.6rem;">
            <div style="width:36px;height:36px;border-radius:8px;background:#1e4d8c;display:flex;align-items:center;justify-content:center;flex-shrink:0;">
                <i class="bi bi-people-fill" style="color:#fff;font-size:0.95rem;"></i>
            </div>
            <div>
                <div style="font-weight:700;color:#1a3a6b;font-size:0.95rem;">Manage Users</div>
                <div style="font-size:0.72rem;color:#94a3b8;margin-top:1px;"><?= count($users) ?> user<?= count($users)!==1?'s':'' ?> found</div>
            </div>
        </div>
    </div>

    <!-- Filter Bar -->
    <form method="GET" action="index.php" class="mb-2">
        <input type="hidden" name="page" value="manage_users">
        <div style="display:flex;flex-wrap:wrap;gap:0.75rem;align-items:flex-end;">
            <!-- Search -->
            <div style="flex:1;min-width:200px;">
                <label style="font-size:0.72rem;font-weight:600;color:#64748b;text-transform:uppercase;letter-spacing:0.05em;display:block;margin-bottom:5px;">Search</label>
                <div style="display:flex;align-items:center;border:1.5px solid #e2e8f0;border-radius:8px;overflow:hidden;background:#f8fafc;"
                     onfocusin="this.style.borderColor='#1e4d8c'" onfocusout="this.style.borderColor='#e2e8f0'">
                    <span style="padding:0 0.65rem;color:#94a3b8;font-size:0.85rem;"><i class="bi bi-search"></i></span>
                    <input type="text" name="search" value="<?= sanitize($search) ?>" placeholder="Name, email, employee ID..."
                           style="flex:1;border:none;outline:none;background:transparent;padding:0.5rem 0.5rem 0.5rem 0;font-size:0.85rem;color:#1e293b;">
                </div>
            </div>
            <!-- Role filter -->
            <div style="min-width:140px;">
                <label style="font-size:0.72rem;font-weight:600;color:#64748b;text-transform:uppercase;letter-spacing:0.05em;display:block;margin-bottom:5px;">Role</label>
                <select name="role_filter" onchange="this.form.submit()"
                        style="width:100%;border:1.5px solid #e2e8f0;border-radius:8px;padding:0.5rem 0.75rem;font-size:0.85rem;color:#1e293b;background:#f8fafc;outline:none;cursor:pointer;">
                    <option value="">All Roles</option>
                    <option value="faculty"         <?= $filter==='faculty'         ?'selected':'' ?>>Faculty</option>
                    <option value="checker"         <?= $filter==='checker'         ?'selected':'' ?>>Checker</option>
                    <option value="talisay_checker" <?= $filter==='talisay_checker' ?'selected':'' ?>>Talisay Checker</option>
                    <option value="admin"           <?= $filter==='admin'           ?'selected':'' ?>>Admin</option>
                </select>
            </div>
            <!-- Campus filter -->
            <div style="min-width:160px;">
                <label style="font-size:0.72rem;font-weight:600;color:#64748b;text-transform:uppercase;letter-spacing:0.05em;display:block;margin-bottom:5px;">Campus</label>
                <select name="campus_id" onchange="this.form.submit()"
                        style="width:100%;border:1.5px solid #e2e8f0;border-radius:8px;padding:0.5rem 0.75rem;font-size:0.85rem;color:#1e293b;background:#f8fafc;outline:none;cursor:pointer;">
                    <option value="">All Campuses</option>
                    <?php foreach ($campuses as $c): ?>
                    <option value="<?= $c['campus_id'] ?>" <?= $filter_campus===(int)$c['campus_id']?'selected':'' ?>><?= sanitize($c['campus_name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <button type="submit"
                    style="background:#1a3a6b;color:#fff;border:none;border-radius:8px;padding:0.5rem 1.25rem;font-size:0.85rem;font-weight:600;cursor:pointer;display:flex;align-items:center;gap:0.4rem;">
                <i class="bi bi-search"></i> Filter
            </button>
            <?php if ($search || $filter || $filter_campus): ?>
            <a href="index.php?page=manage_users"
               style="background:#f1f5f9;color:#64748b;border:1.5px solid #e2e8f0;border-radius:8px;padding:0.5rem 1rem;font-size:0.82rem;font-weight:600;text-decoration:none;display:flex;align-items:center;gap:0.4rem;">
                <i class="bi bi-x-circle"></i> Clear
            </a>
            <?php endif; ?>
        </div>
    </form>

    <div class="table-responsive">
        <table style="width:100%;border-collapse:collapse;font-size:0.82rem;">
            <thead>
                <tr style="background:#f8fafc;border-bottom:2px solid #e2e8f0;">
                    <?php foreach (['Name','Email','Employee ID','Campus','Rank','Role','Status','Apps','Joined','Actions'] as $h): ?>
                    <th style="padding:0.65rem <?= $h==='Name'?'1rem':'0.75rem' ?>;font-size:0.68rem;font-weight:700;text-transform:uppercase;letter-spacing:0.05em;color:#64748b;white-space:nowrap;<?= in_array($h,['Apps','Status'])?' text-align:center;':'' ?>"><?= $h ?></th>
                    <?php endforeach; ?>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($users as $u):
                $role_cfg = [
                    'admin'           => ['#f1f5f9','#334155','Admin'],
                    'checker'         => ['#eff6ff','#1e4d8c','Checker'],
                    'talisay_checker' => ['#f0f4fb','#1a3a6b','Talisay Checker'],
                    'faculty'         => ['#eff6ff','#1e4d8c','Faculty'],
                ];
                $status_cfg = [
                    'active'   => ['#f0fdf4','#16a34a','Active'],
                    'inactive' => ['#fef2f2','#dc2626','Inactive'],
                    'rejected' => ['#fef2f2','#dc2626','Rejected'],
                ];
                [$rb,$rc,$rl] = $role_cfg[$u['role']] ?? ['#f1f5f9','#64748b',ucfirst($u['role'])];
                [$sb,$sc,$sl] = $status_cfg[$u['status']] ?? ['#f1f5f9','#64748b',ucfirst($u['status'])];
                $init = strtoupper(
                    substr(trim($u['first_name'] ?? ''), 0, 1) .
                    substr(trim($u['last_name']  ?? ''), 0, 1)
                ) ?: '?';
                $avc   = ['#1e4d8c','#1a3a6b','#1e4d8c','#475569','#0369a1','#1e293b'];
                $avbg  = $avc[crc32($u['user_id']) % count($avc)];
            ?>
            <tr style="border-bottom:1px solid #f0f4fb;" onmouseover="this.style.background='#f8fafc'" onmouseout="this.style.background=''">
                <!-- Name + avatar -->
                <td style="padding:0.75rem 1rem;white-space:nowrap;">
                    <div style="display:flex;align-items:center;gap:0.6rem;">
                        <div style="width:32px;height:32px;border-radius:50%;background:<?= $avbg ?>;display:flex;align-items:center;justify-content:center;font-size:0.68rem;font-weight:700;color:#fff;flex-shrink:0;"><?= $init ?></div>
                        <div style="font-weight:600;color:#1e293b;"><?= htmlspecialchars(formatDisplayName($u)) ?></div>
                    </div>
                </td>
                <td style="padding:0.75rem 0.75rem;color:#64748b;font-size:0.78rem;"><?= sanitize($u['email']) ?></td>
                <td style="padding:0.75rem 0.75rem;color:#64748b;font-size:0.78rem;"><?= sanitize($u['employee_id'] ?? '—') ?></td>
                <td style="padding:0.75rem 0.75rem;color:#64748b;font-size:0.78rem;"><?= sanitize($u['campus_name'] ?? '—') ?></td>
                <td style="padding:0.75rem 0.75rem;color:#64748b;font-size:0.78rem;max-width:120px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;" title="<?= sanitize($u['rank']??'') ?>"><?= sanitize($u['rank'] ?? '—') ?></td>
                <!-- Role badge -->
                <td style="padding:0.75rem 0.75rem;">
                    <span style="background:<?= $rb ?>;color:<?= $rc ?>;font-size:0.68rem;font-weight:700;border-radius:20px;padding:2px 9px;border:1px solid <?= $rc ?>33;white-space:nowrap;"><?= $rl ?></span>
                </td>
                <!-- Status badge -->
                <td style="padding:0.75rem 0.75rem;text-align:center;">
                    <span style="background:<?= $sb ?>;color:<?= $sc ?>;font-size:0.68rem;font-weight:700;border-radius:20px;padding:2px 9px;border:1px solid <?= $sc ?>33;"><?= $sl ?></span>
                </td>
                <!-- App count -->
                <td style="padding:0.75rem 0.75rem;text-align:center;">
                    <?php if ($u['app_count'] > 0): ?>
                    <span style="background:#eff6ff;color:#1e4d8c;font-weight:700;font-size:0.78rem;border-radius:20px;padding:2px 9px;"><?= $u['app_count'] ?></span>
                    <?php else: ?><span style="color:#cbd5e1;font-size:0.78rem;">—</span><?php endif; ?>
                </td>
                <!-- Joined -->
                <td style="padding:0.75rem 0.75rem;color:#94a3b8;font-size:0.75rem;white-space:nowrap;"><?= date('M d, Y', strtotime($u['created_at'])) ?></td>
                <!-- Actions -->
                <td style="padding:0.75rem 0.75rem;">
                    <?php if ($u['user_id'] !== $_SESSION['user_id']): ?>
                    <div style="display:flex;gap:0.35rem;align-items:center;flex-wrap:wrap;">
                        <!-- Activate/Deactivate -->
                        <?php if ($u['status'] === 'inactive'): ?>
                        <form method="POST" id="activateForm_<?= $u['user_id'] ?>">
                            <input type="hidden" name="action" value="set_active">
                            <input type="hidden" name="uid" value="<?= $u['user_id'] ?>">
                            <button type="button"
                                    style="padding:3px 10px;border-radius:6px;background:#f0fdf4;color:#16a34a;border:1px solid #bbf7d0;font-size:0.72rem;font-weight:600;cursor:pointer;"
                                    onclick="confirmDelete('Reactivate &ldquo;<?= htmlspecialchars(formatDisplayName($u), ENT_QUOTES) ?>&rdquo;?','activateForm_<?= $u['user_id'] ?>','Reactivate','bi-person-check')">
                                <i class="bi bi-person-check me-1"></i>Activate
                            </button>
                        </form>
                        <?php else: ?>
                        <form method="POST" id="deactivateForm_<?= $u['user_id'] ?>">
                            <input type="hidden" name="action" value="set_inactive">
                            <input type="hidden" name="uid" value="<?= $u['user_id'] ?>">
                            <button type="button"
                                    style="padding:3px 10px;border-radius:6px;background:#fef2f2;color:#dc2626;border:1px solid #fecaca;font-size:0.72rem;font-weight:600;cursor:pointer;"
                                    onclick="confirmDelete('Deactivate &ldquo;<?= htmlspecialchars(formatDisplayName($u), ENT_QUOTES) ?>&rdquo;?','deactivateForm_<?= $u['user_id'] ?>','Deactivate','bi-person-slash')">
                                <i class="bi bi-person-slash me-1"></i>Deactivate
                            </button>
                        </form>
                        <?php endif; ?>
                        <!-- Delete -->
                        <form method="POST" id="deleteUserForm_<?= $u['user_id'] ?>">
                            <input type="hidden" name="action" value="delete_user">
                            <input type="hidden" name="uid" value="<?= $u['user_id'] ?>">
                            <button type="button"
                                    style="padding:3px 8px;border-radius:6px;background:#fef2f2;color:#dc2626;border:1px solid #fecaca;font-size:0.75rem;cursor:pointer;"
                                    onclick="confirmDelete('Delete &ldquo;<?= htmlspecialchars(formatDisplayName($u), ENT_QUOTES) ?>&rdquo;? This cannot be undone.','deleteUserForm_<?= $u['user_id'] ?>')">
                                <i class="bi bi-trash"></i>
                            </button>
                        </form>
                    </div>
                    <?php else: ?>
                    <span style="font-size:0.75rem;color:#94a3b8;font-style:italic;">You</span>
                    <?php endif; ?>
                </td>
            </tr>
            <?php endforeach; ?>
            <?php if (empty($users)): ?>
            <tr><td colspan="10" style="padding:3rem;text-align:center;color:#94a3b8;">
                <i class="bi bi-people" style="font-size:2rem;display:block;margin-bottom:0.5rem;color:#e2e8f0;"></i>
                No users found<?= $search ? ' matching "'.sanitize($search).'"' : '' ?>.
            </td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

