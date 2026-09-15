<?php
if (!isAdmin()) { echo '<div class="alert alert-danger">Access denied.</div>'; return; }

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'add_campus') {
        $name = trim($_POST['campus_name'] ?? '');
        if ($name === '') {
            flashMessage('danger', 'Campus name is required.');
        } else {
            $check = $pdo->prepare("SELECT campus_id FROM campuses WHERE campus_name = ?");
            $check->execute([$name]);
            if ($check->fetch()) {
                flashMessage('danger', 'Campus already exists.');
            } else {
                $pdo->prepare("INSERT INTO campuses (campus_name) VALUES (?)")->execute([$name]);
                logAudit($pdo, $_SESSION['user_id'], 'Campus Added', "Added campus: {$name}");
                flashMessage('success', "Campus <strong>{$name}</strong> added.");
            }
        }
    } elseif ($action === 'toggle_active') {
        $cid = intval($_POST['campus_id']);
        $active = intval($_POST['is_active']);
        $pdo->prepare("UPDATE campuses SET is_active=? WHERE campus_id=?")->execute([$active, $cid]);
        logAudit($pdo, $_SESSION['user_id'], 'Campus Updated', "Campus ID {$cid} active status set to {$active}");
        flashMessage('success', 'Campus status updated.');
    } elseif ($action === 'delete_campus') {
        $cid = intval($_POST['campus_id']);
        // Check if any users are assigned to this campus
        $has_users = $pdo->prepare("SELECT COUNT(*) FROM users WHERE campus_id = ?");
        $has_users->execute([$cid]);
        if ($has_users->fetchColumn() > 0) {
            flashMessage('danger', 'Cannot delete &mdash; users are assigned to this campus.');
        } else {
            $name_row = $pdo->prepare("SELECT campus_name FROM campuses WHERE campus_id=?");
            $name_row->execute([$cid]);
            $deleted_name = $name_row->fetchColumn() ?: "ID {$cid}";
            $pdo->prepare("DELETE FROM campuses WHERE campus_id=?")->execute([$cid]);
            logAudit($pdo, $_SESSION['user_id'], 'Campus Deleted', "Deleted campus {$deleted_name}");
            flashMessage('success', "Campus <strong>{$deleted_name}</strong> deleted.");
        }
    }
    echo "<script>window.location.href='index.php?page=manage_campuses';</script>"; exit;
}

$search   = trim($_GET['search'] ?? '');
$sq       = $search ? "%{$search}%" : null;
$campuses = $sq
    ? $pdo->prepare("SELECT c.*, (SELECT COUNT(*) FROM users u WHERE u.campus_id = c.campus_id) as user_count FROM campuses c WHERE c.campus_name LIKE ? ORDER BY c.campus_name")
    : $pdo->prepare("SELECT c.*, (SELECT COUNT(*) FROM users u WHERE u.campus_id = c.campus_id) as user_count FROM campuses c ORDER BY c.campus_name");
$sq ? $campuses->execute([$sq]) : $campuses->execute();
$campuses = $campuses->fetchAll();
?>

<?php showFlash(); ?>

<!-- Add campus card -->
<div class="neon-card mb-4" style="padding:1.1rem 1.25rem;">
    <div style="display:flex;align-items:center;gap:0.6rem;margin-bottom:1rem;">
        <div style="width:36px;height:36px;border-radius:8px;background:#1e4d8c;display:flex;align-items:center;justify-content:center;flex-shrink:0;">
            <i class="bi bi-geo-alt-fill" style="color:#fff;font-size:0.9rem;"></i>
        </div>
        <div>
            <div style="font-weight:700;color:#1a3a6b;font-size:0.95rem;">Add New Campus</div>
            <div style="font-size:0.72rem;color:#94a3b8;">Register a new campus location</div>
        </div>
    </div>
    <form method="POST" class="d-flex gap-3 align-items-end flex-wrap" id="addCampusForm">
        <input type="hidden" name="action" value="add_campus">
        <div style="flex:1;min-width:220px;">
            <label style="font-size:0.72rem;font-weight:600;color:#64748b;text-transform:uppercase;letter-spacing:0.05em;display:block;margin-bottom:5px;">Campus Name <span style="color:#334155;">*</span></label>
            <div style="display:flex;align-items:center;border:1.5px solid #e2e8f0;border-radius:8px;overflow:hidden;background:#f8fafc;"
                 onfocusin="this.style.borderColor='#1e4d8c'" onfocusout="this.style.borderColor='#e2e8f0'">
                <span style="padding:0 0.65rem;color:#94a3b8;font-size:0.85rem;"><i class="bi bi-building"></i></span>
                <input type="text" name="campus_name" placeholder="e.g. CHMSU-Main Campus"
                       required minlength="2" pattern=".*\S.*"
                       style="flex:1;border:none;outline:none;background:transparent;padding:0.5rem 0.5rem 0.5rem 0;font-size:0.85rem;color:#1e293b;">
            </div>
        </div>
        <button type="button"
                style="background:#16a34a;color:#fff;border:1px solid #16a34a;border-radius:8px;padding:0.5rem 1.35rem;font-size:0.85rem;font-weight:600;cursor:pointer;white-space:nowrap;"
                onclick="confirmDelete('Add this new campus?','addCampusForm','Add Campus','bi-plus-circle')">
            <i class="bi bi-plus-circle me-1"></i>Add Campus
        </button>
    </form>
</div>

<!-- Campus list card -->
<div class="neon-card" style="padding:0;overflow:hidden;">
    <div style="padding:0.9rem 1.25rem;border-bottom:1px solid #f1f5f9;display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:0.75rem;">
        <div style="display:flex;align-items:center;gap:0.6rem;">
            <div style="width:36px;height:36px;border-radius:8px;background:#1e4d8c;display:flex;align-items:center;justify-content:center;flex-shrink:0;">
                <i class="bi bi-list-ul" style="color:#fff;font-size:0.9rem;"></i>
            </div>
            <div>
                <div style="font-weight:700;color:#1a3a6b;font-size:0.95rem;">All Campuses</div>
                <div style="font-size:0.72rem;color:#94a3b8;"><?= count($campuses) ?> campus<?= count($campuses)!==1?'es':'' ?></div>
            </div>
        </div>
        <form method="GET" action="index.php" style="display:flex;gap:0.5rem;align-items:center;">
            <input type="hidden" name="page" value="manage_campuses">
            <div style="display:flex;align-items:center;border:1.5px solid #e2e8f0;border-radius:8px;overflow:hidden;background:#f8fafc;"
                 onfocusin="this.style.borderColor='#1e4d8c'" onfocusout="this.style.borderColor='#e2e8f0'">
                <span style="padding:0 0.65rem;color:#94a3b8;font-size:0.82rem;"><i class="bi bi-search"></i></span>
                <input type="text" name="search" value="<?= sanitize($search) ?>" placeholder="Search campus..."
                       style="border:none;outline:none;background:transparent;padding:0.42rem 0.5rem 0.42rem 0;font-size:0.82rem;color:#1e293b;width:180px;">
            </div>
            <button type="submit"
                    style="background:#1a3a6b;color:#fff;border:none;border-radius:8px;padding:0.42rem 1rem;font-size:0.82rem;font-weight:600;cursor:pointer;">
                Search
            </button>
            <?php if ($search): ?>
            <a href="index.php?page=manage_campuses"
               style="background:#f1f5f9;color:#64748b;border:1.5px solid #e2e8f0;border-radius:8px;padding:0.42rem 0.85rem;font-size:0.82rem;font-weight:600;text-decoration:none;">
                Clear
            </a>
            <?php endif; ?>
        </form>
    </div>
    <div class="table-responsive">
        <table style="width:100%;border-collapse:collapse;font-size:0.83rem;">
            <thead>
                <tr style="background:#f8fafc;border-bottom:2px solid #e2e8f0;">
                    <th style="padding:0.65rem 1rem;font-size:0.68rem;font-weight:700;text-transform:uppercase;letter-spacing:0.05em;color:#64748b;">Campus Name</th>
                    <th style="padding:0.65rem 0.75rem;font-size:0.68rem;font-weight:700;text-transform:uppercase;letter-spacing:0.05em;color:#64748b;text-align:center;">Users</th>
                    <th style="padding:0.65rem 0.75rem;font-size:0.68rem;font-weight:700;text-transform:uppercase;letter-spacing:0.05em;color:#64748b;text-align:center;">Status</th>
                    <th style="padding:0.65rem 0.75rem;font-size:0.68rem;font-weight:700;text-transform:uppercase;letter-spacing:0.05em;color:#64748b;">Created</th>
                    <th style="padding:0.65rem 0.75rem;font-size:0.68rem;font-weight:700;text-transform:uppercase;letter-spacing:0.05em;color:#64748b;text-align:center;">Action</th>
                </tr>
            </thead>
            <tbody>
            <?php if ($campuses): ?>
            <?php foreach ($campuses as $c): ?>
            <tr style="border-bottom:1px solid #f0f4fb;" onmouseover="this.style.background='#f8fafc'" onmouseout="this.style.background=''">
                <td style="padding:0.75rem 1rem;font-weight:600;color:#1e293b;">
                    <div style="display:flex;align-items:center;gap:0.5rem;">
                        <div style="width:8px;height:8px;border-radius:50%;background:<?= $c['is_active']?'#1e4d8c':'#cbd5e1' ?>;flex-shrink:0;"></div>
                        <?= sanitize($c['campus_name']) ?>
                    </div>
                </td>
                <td style="padding:0.75rem 0.75rem;text-align:center;">
                    <?php if ($c['user_count'] > 0): ?>
                    <span style="background:#eff6ff;color:#1e4d8c;font-weight:700;font-size:0.78rem;border-radius:20px;padding:2px 9px;"><?= $c['user_count'] ?></span>
                    <?php else: ?><span style="color:#cbd5e1;font-size:0.78rem;">—</span><?php endif; ?>
                </td>
                <td style="padding:0.75rem 0.75rem;text-align:center;">
                    <form method="POST" class="d-inline" id="toggleForm_<?= $c['campus_id'] ?>">
                        <input type="hidden" name="action" value="toggle_active">
                        <input type="hidden" name="campus_id" value="<?= $c['campus_id'] ?>">
                        <input type="hidden" name="is_active" value="<?= $c['is_active'] ? 0 : 1 ?>">
                        <button type="button"
                                style="padding:3px 12px;border-radius:20px;font-size:0.72rem;font-weight:700;cursor:pointer;
                                       <?= $c['is_active']
                                           ? 'background:#f0fdf4;color:#16a34a;border:1px solid #bbf7d0;'
                                           : 'background:#fef2f2;color:#dc2626;border:1px solid #fecaca;' ?>"
                                onclick="confirmDelete('<?= $c['is_active']?'Deactivate':'Activate' ?> this campus?','toggleForm_<?= $c['campus_id'] ?>','<?= $c['is_active']?'Deactivate':'Activate' ?>','bi-toggle-<?= $c['is_active']?'off':'on' ?>')">
                            <i class="bi bi-toggle-<?= $c['is_active']?'on':'off' ?> me-1"></i>
                            <?= $c['is_active'] ? 'Active' : 'Inactive' ?>
                        </button>
                    </form>
                </td>
                <td style="padding:0.75rem 0.75rem;color:#94a3b8;font-size:0.78rem;"><?= date('M d, Y', strtotime($c['created_at'])) ?></td>
                <td style="padding:0.75rem 0.75rem;text-align:center;">
                    <form method="POST" id="deleteCampusForm_<?= $c['campus_id'] ?>">
                        <input type="hidden" name="action" value="delete_campus">
                        <input type="hidden" name="campus_id" value="<?= $c['campus_id'] ?>">
                        <?php if (intval($c['user_count']) > 0): ?>
                        <button type="button"
                                style="padding:3px 8px;border-radius:6px;background:#f1f5f9;color:#cbd5e1;border:1px solid #e2e8f0;font-size:0.75rem;cursor:not-allowed;"
                                disabled title="Cannot delete — reassign the <?= intval($c['user_count']) ?> user(s) first">
                            <i class="bi bi-trash"></i>
                        </button>
                        <?php else: ?>
                        <button type="button"
                                style="padding:3px 8px;border-radius:6px;background:#fef2f2;color:#dc2626;border:1px solid #fecaca;font-size:0.75rem;cursor:pointer;"
                                onclick="confirmDelete('Delete &ldquo;<?= sanitize($c['campus_name']) ?>&rdquo;? Cannot be undone.','deleteCampusForm_<?= $c['campus_id'] ?>')">
                            <i class="bi bi-trash"></i>
                        </button>
                        <?php endif; ?>
                    </form>
                </td>
            </tr>
            <?php endforeach; ?>
            <?php else: ?>
            <tr><td colspan="5" style="padding:3rem;text-align:center;color:#94a3b8;">
                <i class="bi bi-geo-alt" style="font-size:2rem;display:block;margin-bottom:0.5rem;color:#e2e8f0;"></i>
                No campuses found<?= $search ? ' matching "'.sanitize($search).'"' : '' ?>.
            </td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

