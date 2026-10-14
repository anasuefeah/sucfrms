<?php
// Request handling and data loading for frontend/views/admin/all_applications.php
$search          = trim($_GET['search'] ?? '');
$filter          = $_GET['filter'] ?? '';
$filter_campus   = intval($_GET['campus_id'] ?? 0);
$allowed = ['draft','submitted','under_review','talisay_review','approved','rejected','reclassified','admin_rejected','needs_revision'];
if ($filter && !in_array($filter, $allowed)) $filter = '';

// Fetch all campuses for dropdown
$campuses = $pdo->query("SELECT campus_id, campus_name FROM campuses ORDER BY campus_name ASC")->fetchAll();

// Handle admin actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $app_id = intval($_POST['app_id'] ?? 0);

    if ($action === 'admin_approve_edit' && $app_id) {
        $pdo->prepare("UPDATE applications SET status='draft', checker_remarks='Edit request allowed by admin. Please update and resubmit.' WHERE application_id=?")
            ->execute([$app_id]);
        logAudit($pdo, $_SESSION['user_id'], 'Edit Request Allowed', "Admin allowed edit for Application #{$app_id}.");
        flashMessage('success', 'Edit request allowed. Faculty can now edit and resubmit.');
        echo "<script>window.location.href='index.php?page=all_applications&filter=edit_requested';</script>"; exit;
    } elseif ($action === 'admin_deny_edit' && $app_id) {
        $deny_reason = trim($_POST['deny_reason'] ?? 'Edit request denied by admin.');
        $check = $pdo->prepare("SELECT reviewed_at FROM applications WHERE application_id=?");
        $check->execute([$app_id]);
        $prev  = $check->fetch();
        $revert_status = (!empty($prev['reviewed_at'])) ? 'approved' : 'submitted';
        $pdo->prepare("UPDATE applications SET status=?, checker_remarks=? WHERE application_id=?")
            ->execute([$revert_status, $deny_reason, $app_id]);
        logAudit($pdo, $_SESSION['user_id'], 'Edit Request Denied', "Admin denied edit for Application #{$app_id}. Reverted to {$revert_status}.");
        flashMessage('warning', 'Edit request denied.');
        echo "<script>window.location.href='index.php?page=all_applications&filter=edit_requested';</script>"; exit;
    } elseif ($action === 'admin_reject' && $app_id) {
        $admin_remarks = trim($_POST['admin_remarks'] ?? '');
        $pdo->prepare("UPDATE applications SET status='admin_rejected', checker_remarks=?, reviewed_at=NOW() WHERE application_id=? AND status='approved'")
            ->execute([$admin_remarks ?: 'Returned by admin.', $app_id]);
        logAudit($pdo, $_SESSION['user_id'], 'Application Returned by Admin', "Admin returned Application #{$app_id}. Remarks: {$admin_remarks}");
        flashMessage('warning', 'Application has been returned for revision.');
        echo "<script>window.location.href='index.php?page=all_applications&filter=approved';</script>"; exit;
    }
    echo "<script>window.location.href='index.php?page=all_applications&filter=edit_requested';</script>"; exit;
}

$where  = 'WHERE 1=1';
$params = [];

if ($filter) { $where .= ' AND a.status = ?'; $params[] = $filter; }
if ($filter_campus) { $where .= ' AND u.campus_id = ?'; $params[] = $filter_campus; }
if ($search) {
    $where .= ' AND (u.full_name LIKE ? OR u.employee_id LIKE ? OR camp.campus_name LIKE ?)';
    $s = "%$search%";
    $params = array_merge($params, [$s, $s, $s]);
}

$apps = [];
try {
    $pdo->query("SELECT review_id FROM application_checker_reviews LIMIT 1");
    $stmt = $pdo->prepare("
        SELECT a.*, u.full_name, u.employee_id, u.rank, camp.campus_name, c.cycle_name,
               (SELECT COUNT(*) FROM application_checker_reviews r
                WHERE r.application_id = a.application_id AND r.decision = 'approved') AS approvals_count
        FROM applications a
        JOIN users u ON a.user_id = u.user_id AND u.role = 'faculty'
        LEFT JOIN campuses camp ON u.campus_id = camp.campus_id
        LEFT JOIN cycles c ON a.cycle_id = c.cycle_id
        $where ORDER BY a.updated_at DESC
    ");
} catch (\Exception $e) {
    $stmt = $pdo->prepare("
        SELECT a.*, u.full_name, u.employee_id, u.rank, camp.campus_name, c.cycle_name, 0 AS approvals_count
        FROM applications a
        JOIN users u ON a.user_id = u.user_id AND u.role = 'faculty'
        LEFT JOIN campuses camp ON u.campus_id = camp.campus_id
        LEFT JOIN cycles c ON a.cycle_id = c.cycle_id
        $where ORDER BY a.updated_at DESC
    ");
}
$stmt->execute($params);
$apps = $stmt->fetchAll();

$counts = $pdo->query("SELECT a.status, COUNT(*) as cnt FROM applications a JOIN users u ON a.user_id = u.user_id AND u.role = 'faculty' GROUP BY a.status")->fetchAll(PDO::FETCH_KEY_PAIR);
$total  = array_sum($counts);

// Status tab config
$tabs = [
    ''               => ['label'=>'All',           'icon'=>'bi-grid-3x3-gap', 'color'=>'#1a3a6b'],
    'draft'          => ['label'=>'Draft',          'icon'=>'bi-file-earmark', 'color'=>'#94a3b8'],
    'submitted'      => ['label'=>'Submitted',      'icon'=>'bi-send',                  'color'=>'#1a3a6b'],
    'under_review'   => ['label'=>'Under Review',   'icon'=>'bi-hourglass-split',         'color'=>'#1a3a6b'],
    // Display name for stored status value 'talisay_review' is "ITC Review".
    'talisay_review' => ['label'=>'ITC Review', 'icon'=>'bi-building-up',              'color'=>'#1a3a6b'],
    'approved'       => ['label'=>'Evaluation Complete', 'icon'=>'bi-clipboard-check',    'color'=>'#1a3a6b'],
    'rejected'       => ['label'=>'Returned for Revision','icon'=>'bi-arrow-counterclockwise','color'=>'#1a3a6b'],
    'needs_revision' => ['label'=>'Needs Revision', 'icon'=>'bi-pencil-square',            'color'=>'#1a3a6b'],
    'admin_rejected' => ['label'=>'Evaluation Complete','icon'=>'bi-clipboard-check',     'color'=>'#1a3a6b'],
];

// Score color helper
function scoreColor(float $s): string {
    if ($s >= 71) return '#1a3a6b';
    if ($s >= 41) return '#1a3a6b';
    return '#475569';
}
