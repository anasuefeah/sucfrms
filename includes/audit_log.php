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

$logs_stmt = $pdo->prepare("SELECT al.*, u.full_name FROM audit_logs al LEFT JOIN users u ON al.user_id = u.user_id $where_sql ORDER BY al.timestamp DESC LIMIT " . (int)$per_page . " OFFSET " . (int)$offset);
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
?>

<!-- Page header -->
<div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:1rem;margin-bottom:1.25rem;">
    <div style="display:flex;align-items:center;gap:0.6rem;">
        <div style="width:36px;height:36px;border-radius:8px;background:#1e4d8c;display:flex;align-items:center;justify-content:center;flex-shrink:0;">
            <i class="bi bi-shield-check" style="color:#fff;font-size:0.95rem;"></i>
        </div>
        <div>
            <div style="font-weight:700;color:#1a3a6b;font-size:0.95rem;">
                <?= $role === 'admin' ? 'System Audit Logs' : 'My Activity Log' ?>
            </div>
            <div style="font-size:0.72rem;color:#94a3b8;margin-top:1px;">
                <?= number_format($total_rows) ?> record<?= $total_rows !== 1 ? 's' : '' ?>
                <?php if ($search || $filter_role || $filter_action || $filter_campus): ?>
                <span style="background:#f0f4fb;color:#1e4d8c;border:1px solid #dbeafe;font-size:0.65rem;font-weight:700;border-radius:4px;padding:1px 6px;margin-left:4px;">filtered</span>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <a href="pages/audit_log_pdf.php?search=<?= urlencode($search) ?>&filter_role=<?= urlencode($filter_role) ?>&filter_action=<?= urlencode($filter_action) ?>&campus_id=<?= $filter_campus ?>"
       target="_blank"
       style="display:inline-flex;align-items:center;gap:0.4rem;padding:0.45rem 1rem;border-radius:8px;
              border:1.5px solid #e2e8f0;background:#fff;color:#475569;font-size:0.78rem;font-weight:600;
              text-decoration:none;transition:all 0.15s;"
       onmouseover="this.style.borderColor='#1a3a6b';this.style.color='#1a3a6b'"
       onmouseout="this.style.borderColor='#e2e8f0';this.style.color='#475569'">
        <i class="bi bi-printer"></i> Print
    </a>
</div>

<!-- Filter bar -->
<div class="neon-card mb-3" style="padding:0.9rem 1.1rem;">
    <form method="GET" action="index.php">
        <input type="hidden" name="page" value="<?= $role === 'admin' ? 'audit' : 'my_audit' ?>">
        <div style="display:flex;flex-wrap:wrap;gap:0.65rem;align-items:flex-end;">
            <!-- Search -->
            <div style="flex:1;min-width:180px;">
                <label style="font-size:0.68rem;font-weight:700;color:#64748b;text-transform:uppercase;letter-spacing:0.05em;display:block;margin-bottom:4px;">Search</label>
                <div style="display:flex;align-items:center;border:1.5px solid #e2e8f0;border-radius:8px;overflow:hidden;background:#f8fafc;"
                     onfocusin="this.style.borderColor='#1e4d8c'" onfocusout="this.style.borderColor='#e2e8f0'">
                    <span style="padding:0 0.6rem;color:#94a3b8;font-size:0.82rem;"><i class="bi bi-search"></i></span>
                    <input type="text" name="search" value="<?= sanitize($search) ?>"
                           placeholder="Action or details..."
                           list="auditSearchSuggestions" autocomplete="off"
                           style="flex:1;border:none;outline:none;background:transparent;padding:0.45rem 0.4rem 0.45rem 0;font-size:0.83rem;color:#1e293b;">
                    <datalist id="auditSearchSuggestions">
                        <option value="Login"><option value="Logout"><option value="KRA Saved">
                        <option value="Application Submitted"><option value="Application Approved">
                        <option value="Application Rejected"><option value="Checker Approved">
                        <option value="Revision Requested"><option value="Score Altered">
                        <option value="Cycle Created"><option value="Register">
                        <option value="Password Reset Released"><option value="Role Changed">
                        <?php foreach ($action_types as $at): ?>
                        <option value="<?= sanitize($at) ?>">
                        <?php endforeach; ?>
                    </datalist>
                </div>
            </div>
            <?php
            $sel_style = "border:1.5px solid #e2e8f0;border-radius:8px;padding:0.45rem 0.7rem;font-size:0.83rem;color:#1e293b;background:#f8fafc;outline:none;cursor:pointer;width:100%;";
            if ($role === 'admin'): ?>
            <!-- Role -->
            <div style="min-width:120px;">
                <label style="font-size:0.68rem;font-weight:700;color:#64748b;text-transform:uppercase;letter-spacing:0.05em;display:block;margin-bottom:4px;">Role</label>
                <select name="filter_role" style="<?= $sel_style ?>" onchange="this.form.submit()">
                    <option value="">All Roles</option>
                    <option value="admin"   <?= $filter_role==='admin'   ?'selected':'' ?>>Admin</option>
                    <option value="checker" <?= $filter_role==='checker' ?'selected':'' ?>>Checker</option>
                    <option value="faculty" <?= $filter_role==='faculty' ?'selected':'' ?>>Faculty</option>
                </select>
            </div>
            <!-- Campus -->
            <div style="min-width:150px;">
                <label style="font-size:0.68rem;font-weight:700;color:#64748b;text-transform:uppercase;letter-spacing:0.05em;display:block;margin-bottom:4px;">Campus</label>
                <select name="campus_id" style="<?= $sel_style ?>" onchange="this.form.submit()">
                    <option value="">All Campuses</option>
                    <?php foreach ($campuses as $c): ?>
                    <option value="<?= $c['campus_id'] ?>" <?= $filter_campus===(int)$c['campus_id']?'selected':'' ?>>
                        <?= sanitize($c['campus_name']) ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <!-- Action type -->
            <div style="min-width:160px;">
                <label style="font-size:0.68rem;font-weight:700;color:#64748b;text-transform:uppercase;letter-spacing:0.05em;display:block;margin-bottom:4px;">Action Type</label>
                <select name="filter_action" style="<?= $sel_style ?>" onchange="this.form.submit()">
                    <option value="">All Actions</option>
                    <?php foreach ($action_types as $at): ?>
                    <option value="<?= sanitize($at) ?>" <?= $filter_action===$at?'selected':'' ?>><?= sanitize($at) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <?php endif; ?>
            <!-- Per page -->
            <div style="min-width:110px;">
                <label style="font-size:0.68rem;font-weight:700;color:#64748b;text-transform:uppercase;letter-spacing:0.05em;display:block;margin-bottom:4px;">Per Page</label>
                <select name="per_page" style="<?= $sel_style ?>" onchange="this.form.submit()">
                    <?php foreach ([10,25,50,100] as $n): ?>
                    <option value="<?= $n ?>" <?= $per_page===$n?'selected':'' ?>><?= $n ?> / page</option>
                    <?php endforeach; ?>
                </select>
            </div>
            <!-- Submit + Clear -->
            <div style="display:flex;gap:0.5rem;align-items:flex-end;">
                <button type="submit"
                        style="background:#1a3a6b;color:#fff;border:none;border-radius:8px;padding:0.47rem 1.1rem;font-size:0.83rem;font-weight:600;cursor:pointer;white-space:nowrap;">
                    <i class="bi bi-search me-1"></i>Search
                </button>
                <?php if ($search || $filter_role || $filter_action || $filter_campus): ?>
                <a href="index.php?page=<?= $role === 'admin' ? 'audit' : 'my_audit' ?>"
                   style="background:#f1f5f9;color:#64748b;border:1.5px solid #e2e8f0;border-radius:8px;padding:0.47rem 0.9rem;font-size:0.82rem;font-weight:600;text-decoration:none;white-space:nowrap;">
                    <i class="bi bi-x-circle me-1"></i>Clear
                </a>
                <?php endif; ?>
            </div>
        </div>
    </form>
</div>

<!-- Table -->
<div class="neon-card" style="padding:0;overflow:hidden;">
    <div class="table-responsive">
        <table style="width:100%;border-collapse:collapse;font-size:0.84rem;">
            <thead>
                <tr style="background:#f8fafc;border-bottom:2px solid #e2e8f0;">
                    <th style="padding:0.65rem 1rem;font-size:0.7rem;font-weight:700;text-transform:uppercase;letter-spacing:0.05em;color:#64748b;width:44px;">#</th>
                    <?php if ($role === 'admin'): ?>
                    <th style="padding:0.65rem 0.75rem;font-size:0.7rem;font-weight:700;text-transform:uppercase;letter-spacing:0.05em;color:#64748b;">User</th>
                    <th style="padding:0.65rem 0.75rem;font-size:0.7rem;font-weight:700;text-transform:uppercase;letter-spacing:0.05em;color:#64748b;width:90px;">Role</th>
                    <?php endif; ?>
                    <th style="padding:0.65rem 0.75rem;font-size:0.7rem;font-weight:700;text-transform:uppercase;letter-spacing:0.05em;color:#64748b;width:180px;">Action</th>
                    <th style="padding:0.65rem 0.75rem;font-size:0.7rem;font-weight:700;text-transform:uppercase;letter-spacing:0.05em;color:#64748b;">Details</th>
                    <th style="padding:0.65rem 0.75rem;font-size:0.7rem;font-weight:700;text-transform:uppercase;letter-spacing:0.05em;color:#64748b;width:160px;white-space:nowrap;">Date &amp; Time</th>
                </tr>
            </thead>
            <tbody>
            <?php if ($logs): foreach ($logs as $i => $log):
                [$bg, $tc, $ic] = actionBadge($log['action_performed']);
            ?>
            <tr style="border-bottom:1px solid #f0f4fb;" onmouseover="this.style.background='#f8fafc'" onmouseout="this.style.background=''">
                <td style="padding:0.65rem 1rem;color:#94a3b8;font-size:0.78rem;vertical-align:middle;"><?= $offset + $i + 1 ?></td>
                <?php if ($role === 'admin'): ?>
                <td style="padding:0.65rem 0.75rem;font-weight:600;color:#1e293b;font-size:0.82rem;vertical-align:middle;">
                    <?= sanitize($log['full_name'] ?? 'System') ?>
                </td>
                <td style="padding:0.65rem 0.75rem;vertical-align:middle;">
                    <?php
                    $rc_map = ['admin'=>['#f1f5f9','#334155'],'checker'=>['#dbeafe','#1e4d8c'],'faculty'=>['#f0f4fb','#475569'],'talisay_checker'=>['#f0f4fb','#1a3a6b']];
                    [$rb,$rt] = $rc_map[$log['role_at_time'] ?? ''] ?? ['#f1f5f9','#64748b'];
                    ?>
                    <span style="background:<?= $rb ?>;color:<?= $rt ?>;padding:0.18rem 0.5rem;border-radius:20px;font-size:0.68rem;font-weight:600;white-space:nowrap;">
                        <?= sanitize($log['role_at_time'] ?? '—') ?>
                    </span>
                </td>
                <?php endif; ?>
                <td style="padding:0.65rem 0.75rem;vertical-align:middle;">
                    <span style="background:<?= $bg ?>;color:<?= $tc ?>;padding:0.22rem 0.6rem;border-radius:20px;font-size:0.75rem;font-weight:600;white-space:nowrap;display:inline-flex;align-items:center;gap:4px;">
                        <i class="bi <?= $ic ?>" style="font-size:0.72rem;"></i>
                        <?= sanitize($log['action_performed']) ?>
                    </span>
                </td>
                <td style="padding:0.65rem 0.75rem;color:#64748b;font-size:0.82rem;vertical-align:middle;max-width:340px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;"
                    title="<?= sanitize($log['details']) ?>">
                    <?= sanitize($log['details']) ?>
                </td>
                <td style="padding:0.65rem 0.75rem;color:#94a3b8;font-size:0.78rem;vertical-align:middle;white-space:nowrap;">
                    <?= date('M d, Y', strtotime($log['timestamp'])) ?>
                    <div style="font-size:0.72rem;color:#cbd5e1;"><?= date('h:i A', strtotime($log['timestamp'])) ?></div>
                </td>
            </tr>
            <?php endforeach; else: ?>
            <tr><td colspan="7" style="padding:3rem;text-align:center;color:#94a3b8;">
                <i class="bi bi-inbox d-block mb-2" style="font-size:2rem;color:#e2e8f0;"></i>
                No logs found.
            </td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>

    <!-- Pagination -->
    <?php if ($total_pages > 1): ?>
    <div class="px-3 py-2 d-flex justify-content-between align-items-center" style="border-top:1px solid #f0f4fb;">
        <small class="text-muted">
            Showing <?= $offset + 1 ?>–<?= min($offset + $per_page, $total_rows) ?> of <?= number_format($total_rows) ?>
        </small>
        <ul class="pagination pagination-sm mb-0">
            <li class="page-item <?= $page_num <= 1 ? 'disabled' : '' ?>">
                <a class="page-link" href="<?= auditUrl(['p' => $page_num - 1]) ?>">‹</a>
            </li>
            <?php
            $start = max(1, $page_num - 2);
            $end   = min($total_pages, $page_num + 2);
            if ($start > 1): ?>
            <li class="page-item"><a class="page-link" href="<?= auditUrl(['p'=>1]) ?>">1</a></li>
            <?php if ($start > 2): ?><li class="page-item disabled"><span class="page-link">&hellip;</span></li><?php endif; ?>
            <?php endif; ?>
            <?php for ($pg = $start; $pg <= $end; $pg++): ?>
            <li class="page-item <?= $pg === $page_num ? 'active' : '' ?>">
                <a class="page-link" href="<?= auditUrl(['p' => $pg]) ?>"><?= $pg ?></a>
            </li>
            <?php endfor; ?>
            <?php if ($end < $total_pages): ?>
            <?php if ($end < $total_pages - 1): ?><li class="page-item disabled"><span class="page-link">&hellip;</span></li><?php endif; ?>
            <li class="page-item"><a class="page-link" href="<?= auditUrl(['p'=>$total_pages]) ?>"><?= $total_pages ?></a></li>
            <?php endif; ?>
            <li class="page-item <?= $page_num >= $total_pages ? 'disabled' : '' ?>">
                <a class="page-link" href="<?= auditUrl(['p' => $page_num + 1]) ?>">›</a>
            </li>
        </ul>
    </div>
    <?php endif; ?>
</div>
