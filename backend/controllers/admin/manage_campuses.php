<?php
// Request handling and data loading for frontend/views/admin/manage_campuses.php
if (!isAdmin()) { echo '<div class="alert alert-danger">Access denied.</div>'; return false; }

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
