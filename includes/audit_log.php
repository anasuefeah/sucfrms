<?php
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

$filter_date_from = trim($_GET['date_from'] ?? '');
$filter_date_to   = $filter_date_from; // single date — filter exact day

$filter_user = intval($_GET['filter_user'] ?? 0);

$users_list = [];
if ($role === 'admin') {
    $users_list = $pdo->query("SELECT user_id, full_name, role FROM users ORDER BY full_name ASC")->fetchAll();
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
if ($filter_date_from) { $where[] = 'DATE(al.timestamp) = ?'; $params[] = $filter_date_from; }
if ($filter_user && $role === 'admin') { $where[] = 'al.user_id = ?'; $params[] = $filter_user; }
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
        'filter_user'   => $_GET['filter_user'] ?? '',
        'per_page'      => $_GET['per_page'] ?? 25,
        'p'             => $_GET['p'] ?? 1,
        'date_from'     => $_GET['date_from'] ?? '',
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
?>

<!-- Page header -->
<div style="background:#fff;border:1px solid #e2e8f0;padding:0.85rem 1.25rem;margin-bottom:1rem;
            display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:0.75rem;">
    <div>
        <div style="font-size:0.65rem;font-weight:700;text-transform:uppercase;letter-spacing:0.08em;color:#94a3b8;">
            <?= $role === 'admin' ? 'System Audit Logs' : 'My Activity Log' ?>
        </div>
        <div style="font-size:0.75rem;color:#1e293b;font-weight:600;margin-top:1px;">
            <?= number_format($total_rows) ?> record<?= $total_rows !== 1 ? 's' : '' ?>
            <?php if ($search || $filter_role || $filter_action || $filter_campus || $filter_date_from || $filter_user): ?>
            <span style="font-size:0.62rem;font-weight:700;color:#64748b;background:#f1f5f9;border:1px solid #e2e8f0;padding:1px 6px;margin-left:4px;">filtered</span>
            <?php endif; ?>        </div>
    </div>
    <a href="pages/audit_log_pdf.php?search=<?= urlencode($search) ?>&filter_role=<?= urlencode($filter_role) ?>&filter_action=<?= urlencode($filter_action) ?>&campus_id=<?= $filter_campus ?>&date_from=<?= urlencode($filter_date_from) ?>&date_to=<?= urlencode($filter_date_to) ?>"
       target="_blank"
       style="display:inline-flex;align-items:center;gap:0.4rem;padding:0.42rem 0.9rem;
              border:1px solid #e2e8f0;background:#fff;color:#475569;font-size:0.78rem;font-weight:600;
              text-decoration:none;border-radius:0;"
       onmouseover="this.style.borderColor='#1a3a6b';this.style.color='#1a3a6b'"
       onmouseout="this.style.borderColor='#e2e8f0';this.style.color='#475569'">
        <i class="bi bi-printer"></i> Print
    </a>
</div>

<!-- Filter bar -->
<div class="neon-card mb-3" style="padding:0.85rem 1.1rem;border-radius:0;box-shadow:none;">
    <form method="GET" action="index.php">
        <input type="hidden" name="page" value="<?= $role === 'admin' ? 'audit' : 'my_audit' ?>">
        <div style="display:flex;flex-wrap:wrap;gap:0.6rem;align-items:flex-end;">
            <!-- Search -->
            <div style="flex:1;min-width:180px;">
                <label style="font-size:0.6rem;font-weight:700;color:#94a3b8;text-transform:uppercase;letter-spacing:0.07em;display:block;margin-bottom:3px;">Search</label>
                <div style="display:flex;align-items:center;border:1px solid #e2e8f0;background:#f8fafc;">
                    <span style="padding:0 0.6rem;color:#94a3b8;font-size:0.8rem;"><i class="bi bi-search"></i></span>
                    <input type="text" name="search" value="<?= sanitize($search) ?>"
                           placeholder="Action or details..."
                           style="flex:1;border:none;outline:none;background:transparent;padding:0.45rem 0.4rem 0.45rem 0;font-size:0.82rem;color:#1e293b;">
                </div>
            </div>
            <?php
            $sel_style = "border:1px solid #e2e8f0;padding:0.45rem 0.6rem;font-size:0.8rem;color:#1e293b;background:#f8fafc;outline:none;cursor:pointer;width:100%;border-radius:0;";
            $inp_style = "border:1px solid #e2e8f0;padding:0.45rem 0.6rem;font-size:0.8rem;color:#1e293b;background:#f8fafc;outline:none;width:100%;border-radius:0;";
            ?>
            <!-- Date (single) -->
            <div style="min-width:130px;">
                <label style="font-size:0.6rem;font-weight:700;color:#94a3b8;text-transform:uppercase;letter-spacing:0.07em;display:block;margin-bottom:3px;">Date</label>
                <input type="date" name="date_from" value="<?= sanitize($filter_date_from) ?>"
                       style="<?= $inp_style ?>" onchange="this.form.submit()">
            </div>
            <?php if ($role === 'admin'): ?>
            <!-- User -->
            <div style="min-width:180px;">
                <label style="font-size:0.6rem;font-weight:700;color:#94a3b8;text-transform:uppercase;letter-spacing:0.07em;display:block;margin-bottom:3px;">User</label>
                <select name="filter_user" style="<?= $sel_style ?>" onchange="this.form.submit()">
                    <option value="">All Users</option>
                    <?php foreach ($users_list as $u): ?>
                    <option value="<?= $u['user_id'] ?>" <?= $filter_user===(int)$u['user_id']?'selected':'' ?>>
                        <?= sanitize($u['full_name']) ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>            <div style="min-width:110px;">
                <label style="font-size:0.6rem;font-weight:700;color:#94a3b8;text-transform:uppercase;letter-spacing:0.07em;display:block;margin-bottom:3px;">Role</label>
                <select name="filter_role" style="<?= $sel_style ?>" onchange="this.form.submit()">
                    <option value="">All Roles</option>
                    <option value="admin"   <?= $filter_role==='admin'   ?'selected':'' ?>>Admin</option>
                    <option value="evaluator" <?= $filter_role==='evaluator' ?'selected':'' ?>>Evaluator</option>
                    <option value="faculty" <?= $filter_role==='faculty' ?'selected':'' ?>>Faculty</option>
                </select>
            </div>
            <div style="min-width:140px;">
                <label style="font-size:0.6rem;font-weight:700;color:#94a3b8;text-transform:uppercase;letter-spacing:0.07em;display:block;margin-bottom:3px;">Campus</label>
                <select name="campus_id" style="<?= $sel_style ?>" onchange="this.form.submit()">
                    <option value="">All Campuses</option>
                    <?php foreach ($campuses as $c): ?>
                    <option value="<?= $c['campus_id'] ?>" <?= $filter_campus===(int)$c['campus_id']?'selected':'' ?>><?= sanitize($c['campus_name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div style="min-width:150px;">
                <label style="font-size:0.6rem;font-weight:700;color:#94a3b8;text-transform:uppercase;letter-spacing:0.07em;display:block;margin-bottom:3px;">Action Type</label>
                <select name="filter_action" style="<?= $sel_style ?>" onchange="this.form.submit()">
                    <option value="">All Actions</option>
                    <?php foreach ($action_types as $at): ?>
                    <option value="<?= sanitize($at) ?>" <?= $filter_action===$at?'selected':'' ?>><?= sanitize(auditDisplayText($at)) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <?php endif; ?>
            <div style="min-width:100px;">
                <label style="font-size:0.6rem;font-weight:700;color:#94a3b8;text-transform:uppercase;letter-spacing:0.07em;display:block;margin-bottom:3px;">Per Page</label>
                <select name="per_page" style="<?= $sel_style ?>" onchange="this.form.submit()">
                    <?php foreach ([10,25,50,100] as $n): ?>
                    <option value="<?= $n ?>" <?= $per_page===$n?'selected':'' ?>><?= $n ?> / page</option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div style="display:flex;gap:0.4rem;align-items:flex-end;">
                <button type="submit"
                        style="background:#1a3a6b;color:#fff;border:none;border-radius:0;padding:0.45rem 1rem;font-size:0.8rem;font-weight:600;cursor:pointer;">
                    Search
                </button>
                <?php if ($search || $filter_role || $filter_action || $filter_campus || $filter_date_from || $filter_user): ?>
                <a href="index.php?page=<?= $role === 'admin' ? 'audit' : 'my_audit' ?>"
                   style="background:#f8fafc;color:#64748b;border:1px solid #e2e8f0;border-radius:0;padding:0.45rem 0.85rem;font-size:0.8rem;font-weight:600;text-decoration:none;">
                    Clear
                </a>
                <?php endif; ?>
            </div>
        </div>
    </form>
</div>

<!-- Table -->
<div class="neon-card" style="padding:0;overflow:hidden;border-radius:0;box-shadow:none;">
    <div class="table-responsive">
        <table style="width:100%;border-collapse:collapse;font-size:0.82rem;">
            <thead>
                <tr style="background:#f8fafc;border-bottom:2px solid #e2e8f0;">
                    <th style="padding:0.55rem 0.9rem;font-size:0.6rem;font-weight:700;text-transform:uppercase;letter-spacing:0.06em;color:#94a3b8;width:44px;text-align:center;">#</th>
                    <?php if ($role === 'admin'): ?>
                    <th style="padding:0.55rem 0.75rem;font-size:0.6rem;font-weight:700;text-transform:uppercase;letter-spacing:0.06em;color:#94a3b8;">User</th>
                    <th style="padding:0.55rem 0.75rem;font-size:0.6rem;font-weight:700;text-transform:uppercase;letter-spacing:0.06em;color:#94a3b8;width:90px;text-align:center;">Role</th>
                    <?php endif; ?>
                    <th style="padding:0.55rem 0.75rem;font-size:0.6rem;font-weight:700;text-transform:uppercase;letter-spacing:0.06em;color:#94a3b8;width:180px;">Action</th>
                    <th style="padding:0.55rem 0.75rem;font-size:0.6rem;font-weight:700;text-transform:uppercase;letter-spacing:0.06em;color:#94a3b8;">Details</th>
                    <th style="padding:0.55rem 0.75rem;font-size:0.6rem;font-weight:700;text-transform:uppercase;letter-spacing:0.06em;color:#94a3b8;width:130px;white-space:nowrap;text-align:right;">Date &amp; Time</th>
                </tr>
            </thead>
            <tbody>
            <?php if ($logs): foreach ($logs as $i => $log):
                [$bg, $tc, $ic] = actionBadge($log['action_performed']);
                $action_display  = auditDisplayText($log['action_performed'] ?? '');
                $details_display = auditDisplayText($log['details'] ?? '');
            ?>
            <tr style="border-bottom:1px solid #f4f6f9;" onmouseover="this.style.background='#f8fafc'" onmouseout="this.style.background=''">
                <td style="padding:0.6rem 0.9rem;color:#94a3b8;font-size:0.75rem;text-align:center;vertical-align:middle;"><?= $offset + $i + 1 ?></td>
                <?php if ($role === 'admin'): ?>
                <td style="padding:0.6rem 0.75rem;font-weight:600;color:#1e293b;font-size:0.8rem;vertical-align:middle;">
                    <?php
                    $actor_role = $log['role_at_time'] ?? '';
                    $actor_display = $actor_role === 'evaluator'
                        ? checkerDisplayLabel(['checker_label'=>$log['checker_label']??null,'user_id'=>$log['user_id']??null])
                        : ($log['full_name'] ?? 'System');
                    if ($actor_role === 'admin' && str_contains($actor_display, ',')) {
                        $parts_name = array_map('trim', explode(',', $actor_display, 2));
                        $actor_display = trim(($parts_name[1]??'').' '.($parts_name[0]??''));
                    }
                    ?>
                    <?= sanitize($actor_display) ?>
                </td>
                <td style="padding:0.6rem 0.75rem;vertical-align:middle;text-align:center;">
                    <?php
                    $role_display_map = ['admin'=>'Admin','evaluator'=>'Evaluator','faculty'=>'Faculty'];
                    $role_key = $log['role_at_time'] ?? '';
                    ?>
                    <span style="font-size:0.65rem;font-weight:600;color:#64748b;background:#f1f5f9;border:1px solid #e2e8f0;padding:1px 7px;">
                        <?= sanitize($role_display_map[$role_key] ?? ($role_key ?: '—')) ?>
                    </span>
                </td>
                <?php endif; ?>
                <td style="padding:0.6rem 0.75rem;vertical-align:middle;">
                    <span style="font-size:0.72rem;font-weight:600;color:<?= $tc ?>;display:inline-flex;align-items:center;gap:4px;">
                        <i class="bi <?= $ic ?>" style="font-size:0.68rem;flex-shrink:0;"></i>
                        <?= sanitize($action_display) ?>
                    </span>
                </td>
                <td style="padding:0.6rem 0.75rem;color:#64748b;font-size:0.78rem;vertical-align:middle;max-width:340px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;"
                    title="<?= sanitize($details_display) ?>">
                    <?= sanitize($details_display) ?>
                </td>
                <td style="padding:0.6rem 0.75rem;color:#94a3b8;font-size:0.72rem;vertical-align:middle;white-space:nowrap;text-align:right;">
                    <?= date('M d, Y', strtotime($log['timestamp'])) ?>
                    <div style="font-size:0.65rem;color:#cbd5e1;"><?= date('h:i A', strtotime($log['timestamp'])) ?></div>
                </td>
            </tr>
            <?php endforeach; else: ?>
            <tr><td colspan="7" style="padding:2.5rem;text-align:center;color:#94a3b8;font-size:0.8rem;">
                <i class="bi bi-inbox" style="font-size:1.5rem;color:#e2e8f0;display:block;margin-bottom:0.5rem;"></i>
                No logs found.
            </td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>

    <!-- Pagination -->
    <?php if ($total_pages > 1): ?>
    <div style="padding:0.6rem 1rem;border-top:1px solid #f0f4fb;display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:0.5rem;">
        <span style="font-size:0.72rem;color:#94a3b8;">
            Showing <?= $offset + 1 ?>–<?= min($offset + $per_page, $total_rows) ?> of <?= number_format($total_rows) ?>
        </span>
        <ul class="pagination pagination-sm mb-0" style="gap:2px;">
            <li class="page-item <?= $page_num <= 1 ? 'disabled' : '' ?>">
                <a class="page-link" href="<?= auditUrl(['p' => $page_num - 1]) ?>" style="border-radius:0;">‹</a>
            </li>
            <?php
            $start = max(1, $page_num - 2);
            $end   = min($total_pages, $page_num + 2);
            if ($start > 1): ?>
            <li class="page-item"><a class="page-link" href="<?= auditUrl(['p'=>1]) ?>" style="border-radius:0;">1</a></li>
            <?php if ($start > 2): ?><li class="page-item disabled"><span class="page-link" style="border-radius:0;">…</span></li><?php endif; ?>
            <?php endif; ?>
            <?php for ($pg = $start; $pg <= $end; $pg++): ?>
            <li class="page-item <?= $pg === $page_num ? 'active' : '' ?>">
                <a class="page-link" href="<?= auditUrl(['p' => $pg]) ?>" style="border-radius:0;"><?= $pg ?></a>
            </li>
            <?php endfor; ?>
            <?php if ($end < $total_pages): ?>
            <?php if ($end < $total_pages - 1): ?><li class="page-item disabled"><span class="page-link" style="border-radius:0;">…</span></li><?php endif; ?>
            <li class="page-item"><a class="page-link" href="<?= auditUrl(['p'=>$total_pages]) ?>" style="border-radius:0;"><?= $total_pages ?></a></li>
            <?php endif; ?>
            <li class="page-item <?= $page_num >= $total_pages ? 'disabled' : '' ?>">
                <a class="page-link" href="<?= auditUrl(['p' => $page_num + 1]) ?>" style="border-radius:0;">›</a>
            </li>
        </ul>
    </div>
    <?php endif; ?>
</div>
    <div style="display:flex;align-items:center;gap:0.6rem;">
