<?php
if (!isAdmin()) { echo '<div class="alert alert-danger">Access denied.</div>'; return; }
$search          = is_string($_GET['search'] ?? null) ? trim($_GET['search']) : '';
$filter          = $_GET['filter'] ?? '';
$filter_campus   = intval($_GET['campus_id'] ?? 0);
$tabs = [
    '' => 'All', 'draft' => 'Draft', 'submitted' => 'Submitted', 'under_review' => 'Under Review',
    'approved' => 'Evaluation Complete', 'rejected' => 'Returned for Revision', 'needs_revision' => 'Needs Revision',
    'admin_rejected' => 'Returned by Admin', 'edit_requested' => 'Edit Requested', 'expired' => 'Expired',
    'reclassified' => 'Reclassified',
];
if (!is_string($filter) || !isset($tabs[$filter])) $filter = '';

// Fetch all campuses for dropdown
$campuses = $pdo->query("SELECT campus_id, campus_name FROM campuses ORDER BY campus_name ASC")->fetchAll();

$where  = 'WHERE 1=1';
$params = [];

if ($filter === 'approved') {
    $where .= " AND a.status IN ('approved','reclassified')";
} elseif ($filter !== '') {
    $where .= ' AND a.status = ?';
    $params[] = $filter;
}
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
        SELECT a.*, u.full_name, u.employee_id, u.`rank`, camp.campus_name, c.cycle_name,
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
        SELECT a.*, u.full_name, u.employee_id, u.`rank`, camp.campus_name, c.cycle_name, 0 AS approvals_count
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

$counts['approved'] = ($counts['approved'] ?? 0) + ($counts['reclassified'] ?? 0);
foreach (['edit_requested','expired'] as $optionalStatus) {
    if ($filter !== $optionalStatus && empty($counts[$optionalStatus])) unset($tabs[$optionalStatus]);
}
if ($filter !== 'reclassified') unset($tabs['reclassified']);

// Score color helper
function scoreColor(float $s): string {
    if ($s >= 71) return '#1a3a6b';
    if ($s >= 41) return '#1a3a6b';
    return '#475569';
}
?>

<?php showFlash(); ?>

<!-- -- Page header -- -->
<div style="background:#fff;border:1px solid #e2e8f0;padding:0.85rem 1.25rem;margin-bottom:1.25rem;
            display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:1rem;">
    <div style="display:flex;align-items:center;gap:0.75rem;">
        <div style="width:36px;height:36px;background:#1a3a6b;display:flex;align-items:center;justify-content:center;flex-shrink:0;">
            <i class="bi bi-ui-checks-grid" style="color:#fff;font-size:0.9rem;"></i>
        </div>
        <div>
            <div style="font-size:0.92rem;font-weight:700;color:#0f172a;line-height:1.2;">All Applications</div>
            <div style="font-size:0.72rem;color:#94a3b8;margin-top:2px;">
                <strong style="color:#1a3a6b;"><?= $total ?></strong> total across all faculty
            </div>
        </div>
    </div>
    <div style="display:flex;gap:0.4rem;flex-wrap:wrap;">
        <?php
        $highlights = [
            'submitted'    => ['#1e4d8c','#eff6ff','Submitted'],
            'under_review' => ['#475569','#f8fafc','Under Review'],
            'approved'     => ['#1e4d8c','#f0f4fb','Evaluation Complete'],
        ];
        foreach ($highlights as $st => [$tc,$bg,$lbl]):
            $c = $counts[$st] ?? 0; if (!$c) continue;
        ?>
        <div style="background:<?= $bg ?>;border:1px solid <?= $tc ?>33;padding:2px 10px;font-size:0.72rem;font-weight:700;color:<?= $tc ?>;">
            <?= $c ?> <?= $lbl ?>
        </div>
        <?php endforeach; ?>
    </div>
</div>

<!-- -- Search & Filter bar -- -->
<div class="applications-toolbar">
    <form class="applications-filters" method="GET">
        <input type="hidden" name="page" value="all_applications">
        <input type="hidden" name="filter" value="<?= sanitize($filter) ?>">
        <!-- Search -->
        <div class="applications-search-field">
            <label for="applications-search">Search</label>
            <div class="applications-search-box">
                <i class="bi bi-search" aria-hidden="true"></i>
                <input id="applications-search" type="text" name="search" value="<?= sanitize($search) ?>"
                       placeholder="Faculty name, ID, campus...">
                <?php if ($search): ?>
                <a href="?page=all_applications&amp;filter=<?= urlencode($filter) ?><?= $filter_campus ? '&amp;campus_id='.$filter_campus : '' ?>"
                   aria-label="Clear search">
                    <i class="bi bi-x-lg" aria-hidden="true"></i>
                </a>
                <?php endif; ?>
            </div>
        </div>
        <!-- Campus filter -->
        <div>
            <label for="applications-campus">Campus</label>
            <select id="applications-campus" name="campus_id"
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
        <div class="applications-filter-actions">
            <button type="submit"><i class="bi bi-search" aria-hidden="true"></i> Search</button>
            <?php if ($search || $filter_campus): ?>
            <a href="?page=all_applications&amp;filter=<?= urlencode($filter) ?>">Clear</a>
            <?php endif; ?>
        </div>
    </form>

    <!-- -- Status filter tabs -- -->
    <nav class="applications-status-filters" aria-label="Filter by application status">
        <?php foreach ($tabs as $key => $label):
            $cnt    = $key === '' ? $total : ($counts[$key] ?? 0);
            $active = $filter === $key;
            $url    = '?page=all_applications&filter=' . $key
                    . ($search      ? '&search='.urlencode($search) : '')
                    . ($filter_campus ? '&campus_id='.$filter_campus  : '');
        ?>
        <a href="<?= htmlspecialchars($url, ENT_QUOTES) ?>"<?= $active ? ' aria-current="page"' : '' ?>>
            <?= $label ?>
            <?php if ($cnt): ?>
            <span>
                <?= $cnt ?>
            </span>
            <?php endif; ?>
        </a>
        <?php endforeach; ?>
    </nav>
</div>

<!-- -- Applications table / empty state -- -->
<?php if ($apps): ?>
<div style="background:#fff;border:1px solid #e2e8f0;padding:0;overflow:hidden;">
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
                        <div class="account-avatar" style="width:36px;height:36px;border-radius:50%;background:<?= $bg ?>;
                                    display:flex;align-items:center;justify-content:center;
                                    font-size:0.78rem;font-weight:700;color:#fff;flex-shrink:0;">
                            <?= $initials ?>
                        </div>
                        <div>
                            <div style="font-weight:600;color:#1e293b;font-size:0.84rem;line-height:1.2;">
                                <?= sanitize($a['full_name'] ?? ' - ') ?>
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
                        <?= sanitize($a['campus_name'] ?? ' - ') ?>
                    </span>
                </td>

                <!-- Cycle -->
                <td style="padding:0.85rem 0.75rem;vertical-align:middle;max-width:160px;">
                    <span style="font-size:0.78rem;color:#64748b;white-space:nowrap;overflow:hidden;
                                 text-overflow:ellipsis;display:block;" title="<?= sanitize($a['cycle_name'] ?? '') ?>">
                        <?= sanitize($a['cycle_name'] ?? ' - ') ?>
                    </span>
                </td>

                <!-- Score -->
                <td style="padding:0.85rem 0.75rem;text-align:center;vertical-align:middle;">
                    <div style="display:inline-flex;flex-direction:column;align-items:center;gap:2px;">
                        <span style="background:<?= $sc ?>;color:#fff;padding:0.22rem 0.65rem;
                                     font-weight:700;font-size:0.82rem;">
                            <?= number_format($score, 2) ?>
                        </span>
                        <div style="width:52px;height:4px;background:#f1f5f9;overflow:hidden;">
                            <div style="height:100%;width:<?= min(100,round($score))?>%;background:<?= $sc ?>;"></div>
                        </div>
                    </div>
                </td>

                <!-- Status -->
                <td style="padding:0.85rem 0.75rem;vertical-align:middle;">
                    <?= statusBadge($a['status']) ?>
                    <?php if (($a['approvals_count'] ?? 0) > 0 && in_array($a['status'],['under_review'])): ?>
                    <div style="font-size:0.65rem;color:#94a3b8;margin-top:3px;">
                        <i class="bi bi-check-circle me-1"></i><?= $a['approvals_count'] ?> evaluator completion<?= $a['approvals_count']>1?'s':'' ?>
                    </div>
                    <?php endif; ?>
                </td>

                <!-- Updated -->
                <td style="padding:0.85rem 0.75rem;vertical-align:middle;white-space:nowrap;">
                    <div style="font-size:0.78rem;color:#475569;">
                        <?= $a['updated_at'] ? date('M d, Y', strtotime($a['updated_at'])) : ' - ' ?>
                    </div>
                    <div style="font-size:0.67rem;color:#94a3b8;">
                        <?= $a['updated_at'] ? date('H:i', strtotime($a['updated_at'])) : '' ?>
                    </div>
                </td>

                <!-- Action -->
                <td style="padding:0.85rem 0.75rem;text-align:center;vertical-align:middle;">
                    <a href="?page=view_application&id=<?= $a['application_id'] ?>"
                       style="display:inline-flex;align-items:center;gap:0.3rem;
                              padding:0.35rem 0.85rem;font-size:0.76rem;font-weight:600;
                              background:#f0f4fb;color:#1a3a6b;text-decoration:none;border:1px solid #dbeafe;
                              white-space:nowrap;"
                       onmouseover="this.style.background='#1a3a6b';this.style.color='#fff';this.style.borderColor='#1a3a6b'"
                       onmouseout="this.style.background='#f0f4fb';this.style.color='#1a3a6b';this.style.borderColor='#dbeafe'">
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
<div style="background:#fff;border:1px solid #e2e8f0;padding:3.5rem 1.5rem;text-align:center;">
    <div style="width:52px;height:52px;background:#f0f4fb;border:1px solid #dbeafe;
                display:flex;align-items:center;justify-content:center;margin:0 auto 1rem;">
        <i class="bi bi-inbox" style="font-size:1.6rem;color:#94a3b8;"></i>
    </div>
    <div style="font-size:0.95rem;font-weight:600;color:#475569;margin-bottom:0.35rem;">
        No applications found
    </div>
    <div style="font-size:0.8rem;color:#94a3b8;margin-bottom:1.25rem;">
        <?= ($search || $filter || $filter_campus) ? 'Try adjusting your filters or search term.' : 'No faculty applications have been submitted yet.' ?>
    </div>
    <?php if ($search || $filter || $filter_campus): ?>
    <a href="?page=all_applications"
       style="display:inline-flex;align-items:center;gap:0.4rem;padding:0.45rem 1.1rem;
              background:#1a3a6b;color:#fff;text-decoration:none;
              font-size:0.8rem;font-weight:600;"
       onmouseover="this.style.background='#152d54'" onmouseout="this.style.background='#1a3a6b'">
        <i class="bi bi-arrow-left"></i> View all applications
    </a>
    <?php endif; ?>
</div>
<?php endif; ?>
