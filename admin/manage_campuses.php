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
            flashMessage('danger', 'Cannot delete - users are assigned to this campus.');
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

<!-- Page Header Bar -->
<div style="background:#fff;border:1px solid #e2e8f0;padding:0 1.25rem;margin-bottom:1.25rem;
            display:flex;align-items:center;justify-content:space-between;min-height:60px;">
    <div style="display:flex;align-items:center;gap:0.75rem;">
        <div style="width:36px;height:36px;background:#1a3a6b;display:flex;align-items:center;justify-content:center;flex-shrink:0;">
            <i class="bi bi-geo-alt-fill" style="color:#fff;font-size:0.9rem;"></i>
        </div>
        <div>
            <div style="font-weight:700;color:#1a3a6b;font-size:0.95rem;line-height:1.2;">Manage Campuses</div>
            <div style="font-size:0.72rem;color:#94a3b8;margin-top:2px;">Register and manage campus locations</div>
        </div>
    </div>
</div>

<!-- Add campus card -->
<div style="background:#fff;border:1px solid #e2e8f0;overflow:hidden;margin-bottom:1rem;">
    <div style="padding:0.65rem 1.25rem;border-bottom:1px solid #f1f5f9;background:#f8fafc;
                display:flex;align-items:center;gap:0.6rem;">
        <div style="width:28px;height:28px;background:#1a3a6b;display:flex;align-items:center;justify-content:center;flex-shrink:0;">
            <i class="bi bi-plus-lg" style="color:#fff;font-size:0.75rem;"></i>
        </div>
        <div>
            <div style="font-weight:700;color:#1a3a6b;font-size:0.82rem;">Add New Campus</div>
            <div style="font-size:0.68rem;color:#94a3b8;">Register a new campus location</div>
        </div>
    </div>
    <div style="padding:0.9rem 1.25rem;">
        <form method="POST" style="display:flex;gap:0.5rem;align-items:flex-end;flex-wrap:wrap;" id="addCampusForm">
            <input type="hidden" name="action" value="add_campus">
            <div style="flex:1;min-width:220px;">
                <div style="font-size:0.6rem;font-weight:700;text-transform:uppercase;letter-spacing:0.08em;color:#94a3b8;margin-bottom:4px;">Campus Name <span style="color:#dc2626;">*</span></div>
                <div id="campusNameWrap" style="display:flex;align-items:center;border:1px solid #e2e8f0;background:#fff;"
                     onfocusin="this.style.borderColor='#1a3a6b'" onfocusout="this.style.borderColor='#e2e8f0'">
                    <span style="padding:0 0.6rem;color:#94a3b8;font-size:0.82rem;"><i class="bi bi-building"></i></span>
                    <input type="text" name="campus_name" id="campusNameInput" placeholder="e.g. CHMSU-Main Campus"
                           required minlength="2" pattern=".*\S.*"
                           style="flex:1;border:none;outline:none;background:transparent;padding:0.42rem 0.5rem 0.42rem 0;font-size:0.82rem;color:#1e293b;"
                           oninput="document.getElementById('campusNameWrap').style.borderColor='#e2e8f0'">
                </div>
            </div>
            <button type="button"
                    style="padding:0.42rem 1.1rem;background:#16a34a;color:#fff;border:none;font-size:0.8rem;font-weight:700;cursor:pointer;white-space:nowrap;display:inline-flex;align-items:center;gap:5px;"
                    onmouseover="this.style.background='#15803d'" onmouseout="this.style.background='#16a34a'"
                    onclick="
                        var inp  = document.getElementById('campusNameInput');
                        var wrap = document.getElementById('campusNameWrap');
                        if (!inp || inp.value.trim() === '') {
                            if (wrap) wrap.style.borderColor = '#dc2626';
                            inp.focus();
                            return;
                        }
                        if (wrap) wrap.style.borderColor = '#e2e8f0';
                        confirmDelete('\u201c' + inp.value.trim() + '\u201d \u2014 add this campus?', 'addCampusForm', 'Add Campus', 'bi-plus-circle');
                    ">
                <i class="bi bi-plus-circle" style="font-size:0.75rem;"></i> Add Campus
            </button>
        </form>
    </div>
</div>

<!-- Campus list card -->
<div style="background:#fff;border:1px solid #e2e8f0;overflow:hidden;">
    <div style="padding:0.65rem 1.25rem;border-bottom:1px solid #e2e8f0;background:#f8fafc;
                display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:0.5rem;">
        <div style="display:flex;align-items:center;gap:0.6rem;">
            <div style="width:28px;height:28px;background:#1a3a6b;display:flex;align-items:center;justify-content:center;flex-shrink:0;">
                <i class="bi bi-list-ul" style="color:#fff;font-size:0.75rem;"></i>
            </div>
            <div>
                <div style="font-weight:700;color:#1a3a6b;font-size:0.82rem;">All Campuses</div>
                <div style="font-size:0.68rem;color:#94a3b8;"><?= count($campuses) ?> campus<?= count($campuses)!==1?'es':'' ?></div>
            </div>
        </div>
        <form method="GET" action="index.php" style="display:flex;gap:0.4rem;align-items:center;">
            <input type="hidden" name="page" value="manage_campuses">
            <div style="display:flex;align-items:center;border:1px solid #e2e8f0;background:#fff;"
                 onfocusin="this.style.borderColor='#1a3a6b'" onfocusout="this.style.borderColor='#e2e8f0'">
                <span style="padding:0 0.55rem;color:#94a3b8;font-size:0.78rem;"><i class="bi bi-search"></i></span>
                <input type="text" name="search" value="<?= sanitize($search) ?>" placeholder="Search campus..."
                       style="border:none;outline:none;background:transparent;padding:0.38rem 0.4rem 0.38rem 0;font-size:0.78rem;color:#1e293b;width:170px;">
            </div>
            <button type="submit"
                    style="padding:0.38rem 0.85rem;background:#1a3a6b;color:#fff;border:none;font-size:0.78rem;font-weight:600;cursor:pointer;"
                    onmouseover="this.style.background='#152d54'" onmouseout="this.style.background='#1a3a6b'">
                Search
            </button>
            <?php if ($search): ?>
            <a href="index.php?page=manage_campuses"
               style="padding:0.38rem 0.75rem;border:1px solid #e2e8f0;background:#fff;color:#475569;font-size:0.78rem;font-weight:600;text-decoration:none;"
               onmouseover="this.style.background='#f8fafc'" onmouseout="this.style.background='#fff'">
                Clear
            </a>
            <?php endif; ?>
        </form>
    </div>
    <div class="table-responsive">
        <table style="width:100%;border-collapse:collapse;font-size:0.82rem;">
            <thead>
                <tr style="background:#f8fafc;border-bottom:1px solid #e2e8f0;">
                    <th style="padding:0.5rem 1rem;font-size:0.6rem;font-weight:700;text-transform:uppercase;letter-spacing:0.07em;color:#94a3b8;text-align:left;">Campus Name</th>
                    <th style="padding:0.5rem 0.75rem;font-size:0.6rem;font-weight:700;text-transform:uppercase;letter-spacing:0.07em;color:#94a3b8;text-align:center;">Users</th>
                    <th style="padding:0.5rem 0.75rem;font-size:0.6rem;font-weight:700;text-transform:uppercase;letter-spacing:0.07em;color:#94a3b8;text-align:center;">Status</th>
                    <th style="padding:0.5rem 0.75rem;font-size:0.6rem;font-weight:700;text-transform:uppercase;letter-spacing:0.07em;color:#94a3b8;text-align:left;">Created</th>
                    <th style="padding:0.5rem 0.75rem;font-size:0.6rem;font-weight:700;text-transform:uppercase;letter-spacing:0.07em;color:#94a3b8;text-align:center;">Actions</th>
                </tr>
            </thead>
            <tbody>
            <?php if ($campuses): ?>
            <?php foreach ($campuses as $c): ?>
            <tr style="border-bottom:1px solid #f1f5f9;" onmouseover="this.style.background='#fafafa'" onmouseout="this.style.background=''">
                <td style="padding:0.65rem 1rem;font-weight:600;color:#1e293b;">
                    <div style="display:flex;align-items:center;gap:0.5rem;">
                        <div style="width:7px;height:7px;border-radius:50%;background:<?= $c['is_active']?'#16a34a':'#cbd5e1' ?>;flex-shrink:0;"></div>
                        <?= sanitize($c['campus_name']) ?>
                    </div>
                </td>
                <td style="padding:0.65rem 0.75rem;text-align:center;">
                    <?php if ($c['user_count'] > 0): ?>
                    <span style="background:#eff6ff;color:#1e4d8c;font-weight:700;font-size:0.72rem;padding:2px 9px;border:1px solid #bfdbfe;"><?= $c['user_count'] ?></span>
                    <?php else: ?><span style="color:#cbd5e1;font-size:0.78rem;">—</span><?php endif; ?>
                </td>
                <td style="padding:0.65rem 0.75rem;text-align:center;">
                    <form method="POST" class="d-inline" id="toggleForm_<?= $c['campus_id'] ?>">
                        <input type="hidden" name="action" value="toggle_active">
                        <input type="hidden" name="campus_id" value="<?= $c['campus_id'] ?>">
                        <input type="hidden" name="is_active" value="<?= $c['is_active'] ? 0 : 1 ?>">
                        <button type="button"
                                style="padding:3px 10px;font-size:0.68rem;font-weight:700;cursor:pointer;
                                       <?= $c['is_active']
                                           ? 'background:#f0fdf4;color:#16a34a;border:1px solid #bbf7d0;'
                                           : 'background:#fef2f2;color:#dc2626;border:1px solid #fecaca;' ?>"
                                onclick="confirmDelete('<?= $c['is_active']?'Deactivate':'Activate' ?> this campus?','toggleForm_<?= $c['campus_id'] ?>','<?= $c['is_active']?'Deactivate':'Activate' ?>','bi-toggle-<?= $c['is_active']?'off':'on' ?>')"
                                onmouseover="this.style.opacity='0.8'" onmouseout="this.style.opacity='1'">
                            <i class="bi bi-toggle-<?= $c['is_active']?'on':'off' ?>"></i>
                            <?= $c['is_active'] ? 'Active' : 'Inactive' ?>
                        </button>
                    </form>
                </td>
                <td style="padding:0.65rem 0.75rem;color:#94a3b8;font-size:0.75rem;"><?= date('M d, Y', strtotime($c['created_at'])) ?></td>
                <td style="padding:0.65rem 0.75rem;text-align:center;">
                    <form method="POST" id="deleteCampusForm_<?= $c['campus_id'] ?>">
                        <input type="hidden" name="action" value="delete_campus">
                        <input type="hidden" name="campus_id" value="<?= $c['campus_id'] ?>">
                        <?php if (intval($c['user_count']) > 0): ?>
                        <button type="button"
                                style="width:30px;height:30px;background:#f8fafc;color:#cbd5e1;border:1px solid #e2e8f0;font-size:0.72rem;cursor:not-allowed;display:inline-flex;align-items:center;justify-content:center;"
                                disabled title="Cannot delete — reassign the <?= intval($c['user_count']) ?> user(s) first">
                            <i class="bi bi-trash"></i>
                        </button>
                        <?php else: ?>
                        <button type="button"
                                style="width:30px;height:30px;background:#fff;color:#dc2626;border:1px solid #fecaca;font-size:0.72rem;cursor:pointer;display:inline-flex;align-items:center;justify-content:center;"
                                onmouseover="this.style.background='#fef2f2'" onmouseout="this.style.background='#fff'"
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
                <i class="bi bi-geo-alt" style="font-size:1.75rem;display:block;margin-bottom:0.5rem;color:#e2e8f0;"></i>
                No campuses found<?= $search ? ' matching "'.sanitize($search).'"' : '' ?>.
            </td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

