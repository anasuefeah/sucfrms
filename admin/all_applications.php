<?php
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
        $pdo->prepare("UPDATE applications SET status='draft', checker_remarks='Edit approved by admin. Please update and resubmit.' WHERE application_id=?")
            ->execute([$app_id]);
        logAudit($pdo, $_SESSION['user_id'], 'Edit Request Approved', "Admin approved edit for Application #{$app_id}.");
        flashMessage('success', 'Edit request approved. Faculty can now edit and resubmit.');
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
            ->execute([$admin_remarks ?: 'Rejected by admin.', $app_id]);
        logAudit($pdo, $_SESSION['user_id'], 'Application Rejected by Admin', "Admin rejected Application #{$app_id}. Remarks: {$admin_remarks}");
        flashMessage('warning', 'Application has been <strong>rejected</strong>. This is a final decision &mdash; faculty cannot edit or resubmit for this cycle.');
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
    'talisay_review' => ['label'=>'Talisay Review', 'icon'=>'bi-building-up',              'color'=>'#1a3a6b'],
    'approved'       => ['label'=>'Approved',       'icon'=>'bi-check-circle',             'color'=>'#1a3a6b'],
    'rejected'       => ['label'=>'Returned',       'icon'=>'bi-arrow-counterclockwise',   'color'=>'#1a3a6b'],
    'needs_revision' => ['label'=>'Needs Revision', 'icon'=>'bi-pencil-square',            'color'=>'#1a3a6b'],
    'admin_rejected' => ['label'=>'Rejected',       'icon'=>'bi-x-circle',                'color'=>'#1a3a6b'],
];

// Score color helper
function scoreColor(float $s): string {
    if ($s >= 71) return '#1a3a6b';
    if ($s >= 41) return '#1a3a6b';
    return '#475569';
}
?>

<?php showFlash(); ?>

<!-- ── Page header ── -->
<div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:1rem;margin-bottom:1.5rem;">
    <div>
        <div style="display:flex;align-items:center;gap:0.6rem;margin-bottom:0.2rem;">
            <div style="width:36px;height:36px;border-radius:8px;background:#1e4d8c;
                        display:flex;align-items:center;justify-content:center;flex-shrink:0;">
                <i class="bi bi-ui-checks-grid" style="color:#fff;font-size:1rem;"></i>
            </div>
            <h5 style="margin:0;font-weight:700;color:#1a3a6b;font-size:1.1rem;">All Applications</h5>
        </div>
        <div style="font-size:0.78rem;color:#64748b;padding-left:44px;">
            <strong style="color:#1a3a6b;"><?= $total ?></strong> total across all faculty
        </div>
    </div>
    <!-- Quick stat pills -->
    <div style="display:flex;gap:0.5rem;flex-wrap:wrap;">
        <?php
        $highlights = [
            'submitted'    => ['#1e4d8c','#eff6ff','Submitted'],
            'under_review' => ['#475569','#f8fafc','Under Review'],
            'approved'     => ['#1e4d8c','#f0f4fb','Approved'],
        ];
        foreach ($highlights as $st => [$tc,$bg,$lbl]):
            $c = $counts[$st] ?? 0; if (!$c) continue;
        ?>
        <div style="background:<?= $bg ?>;border:1px solid <?= $tc ?>33;border-radius:20px;
                    padding:0.3rem 0.9rem;font-size:0.75rem;font-weight:700;color:<?= $tc ?>;">
            <?= $c ?> <?= $lbl ?>
        </div>
        <?php endforeach; ?>
    </div>
</div>

<!-- ── Search & Filter bar ── -->
<div class="neon-card mb-3" style="padding:1rem 1.25rem;">
    <form method="GET">
        <input type="hidden" name="page" value="all_applications">
        <input type="hidden" name="filter" value="<?= sanitize($filter) ?>">
        <div style="display:flex;flex-wrap:wrap;gap:0.75rem;align-items:flex-end;">
            <!-- Search -->
            <div style="flex:1;min-width:200px;">
                <label style="font-size:0.72rem;font-weight:600;color:#64748b;text-transform:uppercase;
                               letter-spacing:0.05em;display:block;margin-bottom:5px;">Search</label>
                <div style="display:flex;align-items:center;border:1.5px solid #e2e8f0;border-radius:8px;
                            overflow:hidden;background:#f8fafc;transition:border-color 0.2s;"
                     onfocusin="this.style.borderColor='#1e4d8c'" onfocusout="this.style.borderColor='#e2e8f0'">
                    <span style="padding:0 0.65rem;color:#94a3b8;font-size:0.85rem;">
                        <i class="bi bi-search"></i>
                    </span>
                    <input type="text" name="search" value="<?= sanitize($search) ?>"
                           placeholder="Faculty name, ID, campus..."
                           style="flex:1;border:none;outline:none;background:transparent;
                                  padding:0.5rem 0.5rem 0.5rem 0;font-size:0.85rem;color:#1e293b;">
                    <?php if ($search): ?>
                    <a href="?page=all_applications&filter=<?= urlencode($filter) ?><?= $filter_campus ? '&campus_id='.$filter_campus : '' ?>"
                       style="padding:0 0.65rem;color:#94a3b8;text-decoration:none;font-size:0.85rem;">
                        <i class="bi bi-x"></i>
                    </a>
                    <?php endif; ?>
                </div>
            </div>
            <!-- Campus filter -->
            <div style="min-width:180px;">
                <label style="font-size:0.72rem;font-weight:600;color:#64748b;text-transform:uppercase;
                               letter-spacing:0.05em;display:block;margin-bottom:5px;">Campus</label>
                <select name="campus_id"
                        style="width:100%;border:1.5px solid #e2e8f0;border-radius:8px;padding:0.5rem 0.75rem;
                               font-size:0.85rem;color:#1e293b;background:#f8fafc;outline:none;cursor:pointer;"
                        onchange="this.form.submit()">
                    <option value="">All Campuses</option>
                    <?php foreach ($campuses as $c): ?>
                    <option value="<?= $c['campus_id'] ?>" <?= $filter_campus === (int)$c['campus_id'] ? 'selected' : '' ?>>
                        <?= sanitize($c['campus_name']) ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <!-- Submit -->
            <button type="submit"
                    style="background:#1a3a6b;color:#fff;border:none;border-radius:8px;
                           padding:0.5rem 1.25rem;font-size:0.85rem;font-weight:600;cursor:pointer;
                           display:flex;align-items:center;gap:0.4rem;white-space:nowrap;">
                <i class="bi bi-search"></i> Search
            </button>
            <?php if ($search || $filter_campus): ?>
            <a href="?page=all_applications&filter=<?= urlencode($filter) ?>"
               style="background:#f1f5f9;color:#64748b;border:1.5px solid #e2e8f0;border-radius:8px;
                      padding:0.5rem 1rem;font-size:0.82rem;font-weight:600;text-decoration:none;
                      display:flex;align-items:center;gap:0.4rem;white-space:nowrap;">
                <i class="bi bi-x-circle"></i> Clear
            </a>
            <?php endif; ?>
        </div>
    </form>
</div>

<!-- ── Status filter tabs ── -->
<div style="display:flex;flex-wrap:wrap;gap:0.4rem;margin-bottom:1.25rem;">
    <?php foreach ($tabs as $key => $t):
        $cnt    = $key === '' ? $total : ($counts[$key] ?? 0);
        $active = $filter === $key;
        $url    = '?page=all_applications&filter=' . $key
                . ($search      ? '&search='.urlencode($search) : '')
                . ($filter_campus ? '&campus_id='.$filter_campus  : '');
    ?>
    <a href="<?= $url ?>"
       style="display:inline-flex;align-items:center;gap:0.4rem;padding:0.35rem 0.85rem;
              font-size:0.75rem;font-weight:600;text-decoration:none;transition:all 0.15s;
              <?= $active
                ? 'background:#1a3a6b;color:#fff;'
                : 'background:#fff;color:#475569;border:1px solid #e2e8f0;' ?>">
        <?= $t['label'] ?>
        <?php if ($cnt): ?>
        <span style="display:inline-flex;align-items:center;justify-content:center;
                     min-width:16px;height:16px;padding:0 3px;font-size:0.62rem;font-weight:700;
                     <?= $active ? 'background:rgba(255,255,255,0.25);color:#fff;' : 'background:#f1f5f9;color:#64748b;' ?>">
            <?= $cnt ?>
        </span>
        <?php endif; ?>
    </a>
    <?php endforeach; ?>
</div>

<!-- ── Applications table / empty state ── -->
<?php if ($apps): ?>
<div class="neon-card" style="padding:0;overflow:hidden;">
    <div class="table-responsive">
        <table style="width:100%;border-collapse:collapse;font-size:0.84rem;">
            <thead>
                <tr style="background:#f8fafc;border-bottom:2px solid #e2e8f0;">
                    <th style="padding:0.75rem 1rem;text-align:left;font-size:0.7rem;font-weight:700;
                               text-transform:uppercase;letter-spacing:0.05em;color:#64748b;white-space:nowrap;">
                        Faculty
                    </th>
                    <th style="padding:0.75rem 0.75rem;text-align:left;font-size:0.7rem;font-weight:700;
                               text-transform:uppercase;letter-spacing:0.05em;color:#64748b;white-space:nowrap;">
                        Campus
                    </th>
                    <th style="padding:0.75rem 0.75rem;text-align:left;font-size:0.7rem;font-weight:700;
                               text-transform:uppercase;letter-spacing:0.05em;color:#64748b;white-space:nowrap;">
                        Cycle
                    </th>
                    <th style="padding:0.75rem 0.75rem;text-align:center;font-size:0.7rem;font-weight:700;
                               text-transform:uppercase;letter-spacing:0.05em;color:#64748b;white-space:nowrap;">
                        Score
                    </th>
                    <th style="padding:0.75rem 0.75rem;text-align:left;font-size:0.7rem;font-weight:700;
                               text-transform:uppercase;letter-spacing:0.05em;color:#64748b;white-space:nowrap;">
                        Status
                    </th>
                    <th style="padding:0.75rem 0.75rem;text-align:left;font-size:0.7rem;font-weight:700;
                               text-transform:uppercase;letter-spacing:0.05em;color:#64748b;white-space:nowrap;">
                        Updated
                    </th>
                    <th style="padding:0.75rem 0.75rem;text-align:center;font-size:0.7rem;font-weight:700;
                               text-transform:uppercase;letter-spacing:0.05em;color:#64748b;white-space:nowrap;">
                        Action
                    </th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($apps as $a):
                $score = (float)$a['weighted_score'];
                $sc    = scoreColor($score);
            ?>
            <tr style="border-bottom:1px solid #f0f4fb;transition:background 0.15s;"
                onmouseover="this.style.background='#f8fafc'"
                onmouseout="this.style.background='transparent'">

                <!-- Faculty -->
                <td style="padding:0.85rem 1rem;vertical-align:middle;">
                    <div style="display:flex;align-items:center;gap:0.65rem;">
                        <?php
                        $a_parts  = explode(',', trim($a['full_name'] ?? 'U'));
                        $a_last   = trim($a_parts[0]);
                        $a_rest   = isset($a_parts[1]) ? trim($a_parts[1]) : '';
                        $a_first  = explode(' ', $a_rest)[0] ?? '';
                        $initials = strtoupper(substr($a_first, 0, 1) . substr($a_last, 0, 1));
                        $colors = ['#1a3a6b','#334155','#1e4d8c','#475569','#1a3a6b','#334155'];
                        $bg = $colors[crc32($a['user_id']) % count($colors)];
                        ?>
                        <div style="width:36px;height:36px;border-radius:50%;background:<?= $bg ?>;
                                    display:flex;align-items:center;justify-content:center;
                                    font-size:0.78rem;font-weight:700;color:#fff;flex-shrink:0;">
                            <?= $initials ?>
                        </div>
                        <div>
                            <div style="font-weight:600;color:#1e293b;font-size:0.84rem;line-height:1.2;">
                                <?= sanitize($a['full_name'] ?? '—') ?>
                            </div>
                            <div style="font-size:0.7rem;color:#94a3b8;margin-top:1px;">
                                <?= sanitize($a['employee_id'] ?? '') ?>
                                <?php if (!empty($a['rank'])): ?>
                                &nbsp;&middot;&nbsp;<?= sanitize($a['rank']) ?>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </td>

                <!-- Campus -->
                <td style="padding:0.85rem 0.75rem;vertical-align:middle;">
                    <span style="font-size:0.82rem;color:#475569;">
                        <?= sanitize($a['campus_name'] ?? '—') ?>
                    </span>
                </td>

                <!-- Cycle -->
                <td style="padding:0.85rem 0.75rem;vertical-align:middle;max-width:160px;">
                    <span style="font-size:0.78rem;color:#64748b;white-space:nowrap;overflow:hidden;
                                 text-overflow:ellipsis;display:block;" title="<?= sanitize($a['cycle_name'] ?? '') ?>">
                        <?= sanitize($a['cycle_name'] ?? '—') ?>
                    </span>
                </td>

                <!-- Score -->
                <td style="padding:0.85rem 0.75rem;text-align:center;vertical-align:middle;">
                    <div style="display:inline-flex;flex-direction:column;align-items:center;gap:2px;">
                        <span style="background:<?= $sc ?>;color:#fff;padding:0.22rem 0.65rem;
                                     border-radius:6px;font-weight:700;font-size:0.82rem;">
                            <?= number_format($score, 2) ?>
                        </span>
                        <div style="width:52px;height:4px;background:#f1f5f9;border-radius:99px;overflow:hidden;">
                            <div style="height:100%;width:<?= min(100,round($score))?>%;
                                        background:<?= $sc ?>;border-radius:99px;"></div>
                        </div>
                    </div>
                </td>

                <!-- Status -->
                <td style="padding:0.85rem 0.75rem;vertical-align:middle;">
                    <?= statusBadge($a['status']) ?>
                    <?php if (($a['approvals_count'] ?? 0) > 0 && in_array($a['status'],['under_review','talisay_review'])): ?>
                    <div style="font-size:0.65rem;color:#94a3b8;margin-top:3px;">
                        <i class="bi bi-check-circle me-1"></i><?= $a['approvals_count'] ?> approval<?= $a['approvals_count']>1?'s':'' ?>
                    </div>
                    <?php endif; ?>
                </td>

                <!-- Updated -->
                <td style="padding:0.85rem 0.75rem;vertical-align:middle;white-space:nowrap;">
                    <div style="font-size:0.78rem;color:#475569;">
                        <?= $a['updated_at'] ? date('M d, Y', strtotime($a['updated_at'])) : '—' ?>
                    </div>
                    <div style="font-size:0.67rem;color:#94a3b8;">
                        <?= $a['updated_at'] ? date('H:i', strtotime($a['updated_at'])) : '' ?>
                    </div>
                </td>

                <!-- Action -->
                <td style="padding:0.85rem 0.75rem;text-align:center;vertical-align:middle;">
                    <a href="?page=view_application&id=<?= $a['application_id'] ?>"
                       style="display:inline-flex;align-items:center;gap:0.3rem;
                              padding:0.35rem 0.85rem;border-radius:6px;font-size:0.76rem;font-weight:600;
                              background:#f0f4fb;color:#1a3a6b;text-decoration:none;border:1px solid #dbeafe;
                              transition:all 0.15s;white-space:nowrap;"
                       onmouseover="this.style.background='#1a3a6b';this.style.color='#fff'"
                       onmouseout="this.style.background='#f0f4fb';this.style.color='#1a3a6b'">
                        <i class="bi bi-eye" style="font-size:0.75rem;"></i> View
                    </a>
                </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <!-- Table footer -->
    <div style="padding:0.65rem 1rem;background:#f8fafc;border-top:1px solid #f0f4fb;
                display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:0.5rem;">
        <span style="font-size:0.75rem;color:#94a3b8;">
            Showing <strong style="color:#1a3a6b;"><?= count($apps) ?></strong>
            <?= $filter ? 'filtered' : '' ?> application<?= count($apps) !== 1 ? 's' : '' ?>
            <?= $search ? ' matching <strong style="color:#1a3a6b;">'.sanitize($search).'</strong>' : '' ?>
        </span>
        <?php if ($filter || $search || $filter_campus): ?>
        <a href="?page=all_applications"
           style="font-size:0.72rem;color:#64748b;text-decoration:none;font-weight:600;">
            <i class="bi bi-x-circle me-1"></i>Clear all filters
        </a>
        <?php endif; ?>
    </div>
</div>

<?php else: ?>
<!-- Empty state -->
<div class="neon-card" style="padding:3.5rem 1.5rem;text-align:center;">
    <div style="width:64px;height:64px;border-radius:16px;background:#f0f4fb;
                display:flex;align-items:center;justify-content:center;margin:0 auto 1rem;">
        <i class="bi bi-inbox" style="font-size:1.75rem;color:#94a3b8;"></i>
    </div>
    <div style="font-size:1rem;font-weight:600;color:#475569;margin-bottom:0.35rem;">
        No applications found
    </div>
    <div style="font-size:0.82rem;color:#94a3b8;margin-bottom:1.25rem;">
        <?= ($search || $filter || $filter_campus) ? 'Try adjusting your filters or search term.' : 'No faculty applications have been submitted yet.' ?>
    </div>
    <?php if ($search || $filter || $filter_campus): ?>
    <a href="?page=all_applications"
       style="display:inline-flex;align-items:center;gap:0.4rem;padding:0.5rem 1.25rem;
              border-radius:8px;background:#1a3a6b;color:#fff;text-decoration:none;
              font-size:0.82rem;font-weight:600;">
        <i class="bi bi-arrow-left"></i> View all applications
    </a>
    <?php endif; ?>
</div>
<?php endif; ?>
