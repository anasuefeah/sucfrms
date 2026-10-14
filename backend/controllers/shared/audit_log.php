<?php
// Request handling and data loading for frontend/views/shared/audit_log.php
$uid    = $_SESSION['user_id'];
$role   = $_SESSION['role'] ?? 'faculty';

$per_page      = intval($_GET['per_page'] ?? 25);
$per_page      = in_array($per_page, [10, 25, 50, 100]) ? $per_page : 25;
$page_num      = max(1, intval($_GET['p'] ?? 1));
$offset        = ($page_num - 1) * $per_page;
$search        = trim($_GET['search'] ?? '');
$filter_role   = $_GET['filter_role'] ?? '';
$filter_action = trim($_GET['filter_action'] ?? '');
$filter_campus = intval($_GET['campus_id'] ?? 0);

$campuses = [];
if ($role === 'admin') {
    $campuses = $pdo->query("SELECT campus_id, campus_name FROM campuses ORDER BY campus_name ASC")->fetchAll();
}

$where = []; $params = [];
if ($role !== 'admin') { $where[] = 'al.user_id = ?'; $params[] = $uid; }
if ($search) {
    $where[] = '(al.action_performed LIKE ? OR al.details LIKE ? OR u.full_name LIKE ?)';
    $params  = array_merge($params, ["%$search%", "%$search%", "%$search%"]);
}
if ($filter_role && $role === 'admin') { $where[] = 'al.role_at_time = ?'; $params[] = $filter_role; }
if ($filter_action) { $where[] = 'al.action_performed LIKE ?'; $params[] = "%$filter_action%"; }
if ($filter_campus && $role === 'admin') { $where[] = 'u.campus_id = ?'; $params[] = $filter_campus; }
$where_sql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

$count_stmt = $pdo->prepare("SELECT COUNT(*) FROM audit_logs al LEFT JOIN users u ON al.user_id = u.user_id $where_sql");
$count_stmt->execute($params);
$total_rows  = $count_stmt->fetchColumn();
$total_pages = max(1, ceil($total_rows / $per_page));

$logs_stmt = $pdo->prepare("SELECT al.*, u.full_name, u.checker_label FROM audit_logs al LEFT JOIN users u ON al.user_id = u.user_id $where_sql ORDER BY al.timestamp DESC LIMIT " . (int)$per_page . " OFFSET " . (int)$offset);
$logs_stmt->execute($params);
$logs = $logs_stmt->fetchAll();

$action_types = [];
if ($role === 'admin') {
    $action_types = $pdo->query("SELECT DISTINCT action_performed FROM audit_logs ORDER BY action_performed ASC")->fetchAll(PDO::FETCH_COLUMN);
}

function auditUrl(array $overrides = []): string {
    $base = array_merge([
        'page'          => $_GET['page'] ?? 'audit',
        'search'        => $_GET['search'] ?? '',
        'filter_role'   => $_GET['filter_role'] ?? '',
        'filter_action' => $_GET['filter_action'] ?? '',
        'campus_id'     => $_GET['campus_id'] ?? '',
        'per_page'      => $_GET['per_page'] ?? 25,
        'p'             => $_GET['p'] ?? 1,
    ], $overrides);
    return 'index.php?' . http_build_query(array_filter($base, fn($v) => $v !== ''));
}

// Action badge color map
function actionBadge(string $action): array {
    $action = strtolower($action);
    if (str_contains($action, 'login'))    return ['#dbeafe','#1e4d8c','bi-box-arrow-in-right'];
    if (str_contains($action, 'logout'))   return ['#f1f5f9','#475569','bi-box-arrow-right'];
    if (str_contains($action, 'delete') || str_contains($action, 'deleted')) return ['#fef2f2','#dc2626','bi-trash'];
    if (str_contains($action, 'reject'))   return ['#fef2f2','#dc2626','bi-x-circle'];
    if (str_contains($action, 'approve'))  return ['#f0fdf4','#16a34a','bi-check-circle'];
    if (str_contains($action, 'submit'))   return ['#f0f4fb','#1e4d8c','bi-send'];
    if (str_contains($action, 'kra'))      return ['#eff6ff','#2563b0','bi-list-check'];
    if (str_contains($action, 'register')) return ['#f0f4fb','#1a3a6b','bi-person-plus'];
    if (str_contains($action, 'password') || str_contains($action, 'reset')) return ['#f8fafc','#334155','bi-key'];
    if (str_contains($action, 'role'))     return ['#fff7ed','#c2410c','bi-person-gear'];
    if (str_contains($action, 'cycle'))    return ['#f0fdfa','#1e4d8c','bi-calendar-range'];
    return ['#f8fafc','#475569','bi-activity'];
}

function auditDisplayText(?string $text): string {
    return strtr((string)$text, [
        'Pending Verification' => 'Pending Review',
        'Not Verified' => 'Not Acceptable',
        'Unverified' => 'Not Acceptable',
        'unverified' => 'not acceptable',
        'Verified by' => 'Marked Acceptable by',
        'verified by' => 'marked acceptable by',
        'Verification' => 'Review',
        'verification' => 'review',
        'Verifying' => 'Reviewing',
        'verifying' => 'reviewing',
        'Verified' => 'Acceptable',
        'verified' => 'acceptable',
        'Verify' => 'Review',
        'verify' => 'review',
        'Approval' => 'Evaluation Completion',
        'approval' => 'evaluation completion',
        'Approved' => 'Evaluation Complete',
        'approved' => 'evaluation complete',
        'Approve' => 'Complete Evaluation',
        'approve' => 'complete evaluation',
        'Rejection' => 'Return for Revision',
        'rejection' => 'return for revision',
        'Rejected' => 'Returned for Revision',
        'rejected' => 'returned for revision',
        'Reject' => 'Return for Revision',
        'reject' => 'return for revision',
    ]);
}
