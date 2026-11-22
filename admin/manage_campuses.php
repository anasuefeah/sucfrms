<?php
if (!isAdmin()) { echo '<div class="alert alert-danger">Access denied.</div>'; return; }

// Runtime migrations - add address and campus_type columns if missing
try { $pdo->query("SELECT address FROM campuses LIMIT 1"); }
catch (\Exception $e) {
    $pdo->exec("ALTER TABLE campuses ADD COLUMN address VARCHAR(255) DEFAULT NULL AFTER campus_name");
}
try { $pdo->query("SELECT campus_type FROM campuses LIMIT 1"); }
catch (\Exception $e) {
    $pdo->exec("ALTER TABLE campuses ADD COLUMN campus_type ENUM('Main','Satellite','Extension') DEFAULT 'Satellite' AFTER address");
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'add_campus') {
        // add_campus is now handled entirely client-side — name validation + modal open
        // This POST should never fire; redirect cleanly if it does
        echo "<script>window.location.href='index.php?page=manage_campuses';</script>"; exit;

    } elseif ($action === 'create_campus') {
        // Final insert — fired when Save Details is submitted from the new-campus modal
        $name    = trim($_POST['campus_name'] ?? '');
        $address = trim($_POST['campus_address'] ?? '');
        $type    = trim($_POST['campus_type'] ?? 'Satellite');
        if ($name === '') {
            flashMessage('danger', 'Campus name is required.');
        } else {
            $check = $pdo->prepare("SELECT campus_id FROM campuses WHERE campus_name = ?");
            $check->execute([$name]);
            if ($check->fetch()) {
                flashMessage('danger', 'A campus with that name already exists.');
            } else {
                $pdo->prepare("INSERT INTO campuses (campus_name, address, campus_type) VALUES (?,?,?)")
                    ->execute([$name, $address ?: null, $type]);
                logAudit($pdo, $_SESSION['user_id'], 'Campus Added', "Added campus: {$name}");
                flashMessage('success', "Campus <strong>{$name}</strong> added.");
            }
        }
    } elseif ($action === 'update_campus') {
        $cid     = intval($_POST['campus_id']);
        $name    = trim($_POST['campus_name'] ?? '');
        $address = trim($_POST['campus_address'] ?? '');
        $type    = trim($_POST['campus_type'] ?? 'Satellite');
        if ($name === '') {
            flashMessage('danger', 'Campus name is required.');
        } else {
            $dupe = $pdo->prepare("SELECT campus_id FROM campuses WHERE campus_name=? AND campus_id<>?");
            $dupe->execute([$name, $cid]);
            if ($dupe->fetch()) {
                flashMessage('danger', 'Another campus with that name already exists.');
            } else {
                $pdo->prepare("UPDATE campuses SET campus_name=?, address=?, campus_type=? WHERE campus_id=?")
                    ->execute([$name, $address ?: null, $type, $cid]);
                logAudit($pdo, $_SESSION['user_id'], 'Campus Updated', "Updated campus ID {$cid}: {$name}");
                flashMessage('success', "Campus <strong>{$name}</strong> updated.");
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

$lbl = 'display:block;font-size:0.65rem;font-weight:700;text-transform:uppercase;letter-spacing:0.06em;color:#6b7280;margin-bottom:4px;';
$inp = 'width:100%;border:1px solid #e5e7eb;padding:0.38rem 0.55rem;font-size:0.82rem;box-sizing:border-box;outline:none;';
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
    <div style="padding:0.5rem 0.9rem;border-bottom:1px solid #f1f5f9;background:#f8fafc;
                display:flex;align-items:center;gap:0.6rem;">
        <div style="width:28px;height:28px;background:#1a3a6b;display:flex;align-items:center;justify-content:center;flex-shrink:0;">
            <i class="bi bi-plus-lg" style="color:#fff;font-size:0.75rem;"></i>
        </div>
        <div>
            <div style="font-weight:700;color:#1a3a6b;font-size:0.82rem;">Add New Campus</div>
            <div style="font-size:0.68rem;color:#94a3b8;">Register a new campus location</div>
        </div>
    </div>
    <div style="padding:0.75rem 1rem;">
        <form method="POST" id="addCampusForm">
            <input type="hidden" name="action" value="add_campus">
            <div style="display:flex;gap:0.5rem;align-items:center;">
                <div style="flex:1;">
                    <div style="font-size:0.65rem;font-weight:700;text-transform:uppercase;letter-spacing:0.06em;color:#6b7280;margin-bottom:4px;">Campus Name <span style="color:#dc2626;">*</span></div>
                    <input type="text" name="campus_name" id="campusNameInput" required minlength="2"
                           placeholder="e.g. CHMSU-Fortune Towne"
                           style="width:100%;border:1px solid #e5e7eb;padding:0.38rem 0.55rem;font-size:0.82rem;box-sizing:border-box;outline:none;"
                           onfocus="this.style.borderColor='#374151'" onblur="this.style.borderColor='#e5e7eb'">
                </div>
                <div style="flex-shrink:0;padding-top:19px;">
                    <button type="button"
                            style="padding:0.42rem 1.1rem;background:#16a34a;color:#fff;border:none;font-size:0.8rem;font-weight:700;cursor:pointer;display:inline-flex;align-items:center;gap:5px;white-space:nowrap;"
                            onmouseover="this.style.background='#15803d'" onmouseout="this.style.background='#16a34a'"
                            onclick="
                                var inp = document.getElementById('campusNameInput');
                                if (!inp || inp.value.trim() === '') { inp.style.borderColor='#dc2626'; inp.focus(); return; }
                                openCampusEdit(0, inp.value.trim(), '', 'Satellite');

                            ">
                        <i class="bi bi-plus-circle" style="font-size:0.75rem;"></i> Add Campus
                    </button>
                </div>
            </div>
            <div style="font-size:0.62rem;color:#9ca3af;margin-top:4px;">Enter the campus name then fill in the full details in the next step.</div>
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
                    <th style="padding:0.5rem 0.75rem;font-size:0.6rem;font-weight:700;text-transform:uppercase;letter-spacing:0.07em;color:#94a3b8;text-align:left;">Address</th>
                    <th style="padding:0.5rem 0.75rem;font-size:0.6rem;font-weight:700;text-transform:uppercase;letter-spacing:0.07em;color:#94a3b8;text-align:center;">Type</th>
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
                <td style="padding:0.65rem 0.75rem;color:#64748b;font-size:0.76rem;">
                    <?= $c['address'] ? sanitize($c['address']) : '<span style="color:#cbd5e1;">&mdash;</span>' ?>
                </td>
                <td style="padding:0.65rem 0.75rem;text-align:center;">
                    <?php
                    $typeCfg = ['Main'=>['#eff6ff','#1e4d8c'],'Satellite'=>['#f8fafc','#64748b'],'Extension'=>['#f0fdf4','#15803d']];
                    [$tbg,$tcol] = $typeCfg[$c['campus_type'] ?? 'Satellite'] ?? ['#f8fafc','#64748b'];
                    ?>
                    <span style="font-size:0.62rem;font-weight:700;padding:1px 7px;background:<?= $tbg ?>;color:<?= $tcol ?>;border:1px solid <?= $tbg ?>;">
                        <?= htmlspecialchars($c['campus_type'] ?? 'Satellite') ?>
                    </span>
                </td>
                <td style="padding:0.65rem 0.75rem;text-align:center;">
                    <?php if ($c['user_count'] > 0): ?>
                    <span style="background:#eff6ff;color:#1e4d8c;font-weight:700;font-size:0.72rem;padding:2px 9px;border:1px solid #bfdbfe;"><?= $c['user_count'] ?></span>
                    <?php else: ?><span style="color:#cbd5e1;font-size:0.78rem;">&mdash;</span><?php endif; ?>
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
                    <div style="display:flex;align-items:center;justify-content:center;gap:0.4rem;">
                    <button type="button"
                            style="padding:3px 9px;font-size:0.68rem;font-weight:700;cursor:pointer;background:#eff6ff;color:#1e4d8c;border:1px solid #bfdbfe;display:inline-flex;align-items:center;gap:4px;"
                            onmouseover="this.style.background='#dbeafe'" onmouseout="this.style.background='#eff6ff'"
                            onclick="openCampusEdit(<?= $c['campus_id'] ?>,<?= htmlspecialchars(json_encode($c['campus_name']),ENT_QUOTES) ?>,<?= htmlspecialchars(json_encode($c['address'] ?? ''),ENT_QUOTES) ?>,<?= htmlspecialchars(json_encode($c['campus_type'] ?? 'Satellite'),ENT_QUOTES) ?>)">
                        <i class="bi bi-pencil" style="font-size:0.62rem;"></i> Edit
                    </button>
                    <form method="POST" id="deleteCampusForm_<?= $c['campus_id'] ?>">
                        <input type="hidden" name="action" value="delete_campus">
                        <input type="hidden" name="campus_id" value="<?= $c['campus_id'] ?>">
                        <?php if (intval($c['user_count']) > 0): ?>
                        <button type="button"
                                style="width:28px;height:28px;background:#f8fafc;color:#cbd5e1;border:1px solid #e2e8f0;font-size:0.72rem;cursor:not-allowed;display:inline-flex;align-items:center;justify-content:center;"
                                disabled title="Cannot delete - reassign the <?= intval($c['user_count']) ?> user(s) first">
                            <i class="bi bi-trash"></i>
                        </button>
                        <?php else: ?>
                        <button type="button"
                                style="width:28px;height:28px;background:#fff;color:#dc2626;border:1px solid #fecaca;font-size:0.72rem;cursor:pointer;display:inline-flex;align-items:center;justify-content:center;"
                                onmouseover="this.style.background='#fef2f2'" onmouseout="this.style.background='#fff'"
                                onclick="confirmDelete('Delete &ldquo;<?= sanitize($c['campus_name']) ?>&rdquo;? Cannot be undone.','deleteCampusForm_<?= $c['campus_id'] ?>')">
                            <i class="bi bi-trash"></i>
                        </button>
                        <?php endif; ?>
                    </form>
                    </div>
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

<!-- - Campus Edit Details Modal - -->
<div id="campusEditModal"
     style="display:none;position:fixed;inset:0;z-index:9999;background:rgba(15,23,42,0.55);
            align-items:center;justify-content:center;padding:1rem;"
     onclick="if(event.target===this)this.style.display='none'">
    <div style="background:#fff;width:100%;max-width:520px;border:1px solid #e5e7eb;overflow:hidden;">

        <!-- Header -->
        <div style="background:#1a3a6b;padding:0.65rem 1rem;display:flex;align-items:center;justify-content:space-between;">
            <div style="display:flex;align-items:center;gap:0.6rem;">
                <i class="bi bi-geo-alt-fill" style="color:rgba(255,255,255,0.75);font-size:0.88rem;"></i>
                <div>
                    <div style="font-size:0.85rem;font-weight:700;color:#fff;" id="campusEditTitle">Campus Details</div>
                    <div style="font-size:0.62rem;color:rgba(255,255,255,0.5);margin-top:1px;">Fill in campus location details</div>
                </div>
            </div>
            <button onclick="document.getElementById('campusEditModal').style.display='none'"
                    style="background:none;border:none;color:rgba(255,255,255,0.65);font-size:1rem;cursor:pointer;line-height:1;padding:0;"
                    onmouseover="this.style.color='#fff'" onmouseout="this.style.color='rgba(255,255,255,0.65)'">
                <i class="bi bi-x-lg"></i>
            </button>
        </div>

        <!-- Form -->
        <form method="POST" id="campusEditForm">
            <input type="hidden" name="action" id="campusEditAction" value="update_campus">
            <input type="hidden" name="campus_id" id="editCampusId">

            <div style="padding:1rem;">

                <!-- Campus Name -->
                <div style="border:1px solid #e5e7eb;overflow:hidden;margin-bottom:0.6rem;">
                    <div style="background:#f9fafb;border-bottom:1px solid #e5e7eb;padding:0.3rem 0.75rem;">
                        <span style="font-size:0.65rem;font-weight:700;text-transform:uppercase;letter-spacing:0.08em;color:#374151;">Identity</span>
                    </div>
                    <div style="padding:0.7rem 0.75rem;display:grid;grid-template-columns:1fr 160px;gap:0.6rem;">
                        <div>
                            <label style="<?= $lbl ?>">Campus Name <span style="color:#dc2626;">*</span></label>
                            <input type="text" name="campus_name" id="editCampusName" required
                                   style="<?= $inp ?>"
                                   onfocus="this.style.borderColor='#374151'" onblur="this.style.borderColor='#e5e7eb'">
                        </div>
                        <div>
                            <label style="<?= $lbl ?>">Campus Type</label>
                            <select name="campus_type" id="editCampusType"
                                    style="<?= $inp ?>cursor:pointer;"
                                    onfocus="this.style.borderColor='#374151'" onblur="this.style.borderColor='#e5e7eb'">
                                <option value="Main">Main</option>
                                <option value="Satellite">Satellite</option>
                                <option value="Extension">Extension</option>
                            </select>
                        </div>
                    </div>
                </div>

                <!-- Location -->
                <div style="border:1px solid #e5e7eb;overflow:hidden;margin-bottom:0.75rem;">
                    <div style="background:#f9fafb;border-bottom:1px solid #e5e7eb;padding:0.3rem 0.75rem;">
                        <span style="font-size:0.65rem;font-weight:700;text-transform:uppercase;letter-spacing:0.08em;color:#374151;">Location</span>
                    </div>
                    <div style="padding:0.7rem 0.75rem;">
                        <label style="<?= $lbl ?>">Full Address</label>
                        <input type="text" name="campus_address" id="editCampusAddress"
                               placeholder="e.g. Sofia Gonzaga Street, Barangay Estefania, Bacolod City"
                               style="<?= $inp ?>"
                               onfocus="this.style.borderColor='#374151'" onblur="this.style.borderColor='#e5e7eb'">
                        <div style="font-size:0.62rem;color:#9ca3af;margin-top:3px;">This address will appear in faculty profile entry as a dropdown option.</div>
                    </div>
                </div>

            </div>

            <!-- Footer -->
            <div style="padding:0.65rem 1rem;border-top:1px solid #e5e7eb;background:#f9fafb;display:flex;justify-content:space-between;align-items:center;">
                <span style="font-size:0.68rem;color:#9ca3af;">Changes apply immediately after saving.</span>
                <div style="display:flex;gap:0.4rem;">
                    <button type="button" onclick="document.getElementById('campusEditModal').style.display='none'"
                            style="padding:0.4rem 0.9rem;border:1px solid #e5e7eb;background:#fff;font-size:0.78rem;font-weight:600;color:#374151;cursor:pointer;"
                            onmouseover="this.style.background='#f9fafb'" onmouseout="this.style.background='#fff'">Cancel</button>
                    <button type="submit"
                            style="padding:0.4rem 1.1rem;background:#1a3a6b;color:#fff;border:none;font-size:0.78rem;font-weight:700;cursor:pointer;display:inline-flex;align-items:center;gap:5px;"
                            onmouseover="this.style.background='#152d54'" onmouseout="this.style.background='#1a3a6b'">
                        <i class="bi bi-check2" style="font-size:0.75rem;"></i> Save Details
                    </button>
                </div>
            </div>
        </form>
    </div>
</div>

<script>
function openCampusEdit(id, name, address, type) {
    var isNew = (id === 0);
    document.getElementById('editCampusId').value      = id;
    document.getElementById('editCampusAction').value  = isNew ? 'create_campus' : 'update_campus';
    document.getElementById('editCampusName').value    = name;
    document.getElementById('editCampusAddress').value = address;
    document.getElementById('campusEditTitle').textContent = isNew ? 'New Campus - Add Details' : name;
    document.getElementById('campusEditTitle').textContent = isNew ? 'New Campus — Add Details' : name;
    document.getElementById('campusEditModal').style.display = 'flex';
}

// Auto-open if redirected after adding
<?php $auto_edit = (int)($_GET['edit_campus'] ?? 0);
if ($auto_edit): ?>
(function() {
    <?php
    $ae = $pdo->prepare("SELECT * FROM campuses WHERE campus_id=?");
    $ae->execute([$auto_edit]);
    $ae = $ae->fetch();
    if ($ae):
    ?>
    openCampusEdit(
        <?= $ae['campus_id'] ?>,
        <?= json_encode($ae['campus_name']) ?>,
        <?= json_encode($ae['address'] ?? '') ?>,
        <?= json_encode($ae['campus_type'] ?? 'Satellite') ?>
    );
    <?php endif; ?>
})();
<?php endif; ?>
</script>


