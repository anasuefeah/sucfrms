<?php
$search = trim($_GET['search'] ?? '');
$filter = $_GET['filter'] ?? 'pending_decision';

$is_talisay = isTalisayChecker();

if ($is_talisay) {
    $allowed_filters = ['talisay_review','approved','rejected','pending_decision'];
    if (!in_array($filter, $allowed_filters)) $filter = 'pending_decision';
} else {
    $allowed_filters = ['submitted','under_review','talisay_review','approved','rejected','needs_revision','pending_decision','my_reviewed'];
    if (!in_array($filter, $allowed_filters)) $filter = 'pending_decision';
}

try { $pdo->query("SELECT review_id FROM application_checker_reviews LIMIT 1"); }
catch (\Exception $e) {
    $pdo->exec("CREATE TABLE IF NOT EXISTS application_checker_reviews (
        review_id      INT AUTO_INCREMENT PRIMARY KEY,
        application_id INT NOT NULL,
        checker_id     INT NOT NULL,
        decision       ENUM('approved','rejected','pending') DEFAULT 'pending',
        remarks        TEXT,
        decided_at     TIMESTAMP NULL,
        created_at     TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uq_app_checker (application_id, checker_id),
        FOREIGN KEY (application_id) REFERENCES applications(application_id) ON DELETE CASCADE,
        FOREIGN KEY (checker_id) REFERENCES users(user_id) ON DELETE CASCADE
    )");
}

$my_uid = $_SESSION['user_id'];

if ($is_talisay) {
    // Talisay checkers see talisay_review apps they haven't decided on
    if ($filter === 'pending_decision') {
        $base_where = "a.status = 'talisay_review' AND a.user_id != ?
            AND NOT EXISTS (
                SELECT 1 FROM application_checker_reviews r
                WHERE r.application_id = a.application_id
                  AND r.checker_id = ?
                  AND r.decision IN ('approved','rejected')
            )";
        $params = [$my_uid, $my_uid];
    } else {
        $base_where = "a.status = ? AND a.user_id != ?";
        $params     = [$filter, $my_uid];
    }
} else {
    if ($filter === 'pending_decision') {
        $base_where = "a.status IN ('submitted','under_review') AND a.user_id != ?
            AND NOT EXISTS (
                SELECT 1 FROM application_checker_reviews r
                WHERE r.application_id = a.application_id
                  AND r.checker_id = ?
                  AND r.decision IN ('approved','rejected')
            )";
        $params = [$my_uid, $my_uid];
    } elseif ($filter === 'my_reviewed') {
        $base_where = "a.user_id != ? AND EXISTS (
            SELECT 1 FROM application_checker_reviews r
            WHERE r.application_id = a.application_id
              AND r.checker_id = ?
              AND r.decision IN ('approved','rejected')
        )";
        $params = [$my_uid, $my_uid];
    } else {
        $base_where = "a.status = ? AND a.user_id != ?";
        $params     = [$filter, $my_uid];
    }
}

if ($search) {
    $base_where .= " AND (u.full_name LIKE ? OR u.employee_id LIKE ?)";
    $params = array_merge($params, ["%$search%", "%$search%"]);
}

$apps = $pdo->prepare("
    SELECT a.*, u.full_name, u.first_name, u.middle_name, u.last_name, u.employee_id, u.rank,
        COALESCE(camp.campus_name, '&mdash;') as campus_name, c.cycle_name,
        (SELECT COUNT(*) FROM application_checker_reviews r
         JOIN users uc ON r.checker_id = uc.user_id
         WHERE r.application_id = a.application_id AND r.decision = 'approved'
           AND uc.role IN ('checker')) AS approvals_count,
        (SELECT COUNT(*) FROM application_checker_reviews r
         JOIN users uc ON r.checker_id = uc.user_id
         WHERE r.application_id = a.application_id
           AND uc.role IN ('checker')) AS slots_taken,
        (SELECT r2.decision FROM application_checker_reviews r2
         WHERE r2.application_id = a.application_id AND r2.checker_id = ?) AS my_decision,
        (SELECT r2.decided_at FROM application_checker_reviews r2
         WHERE r2.application_id = a.application_id AND r2.checker_id = ?) AS my_decided_at
    FROM applications a
    JOIN users u ON a.user_id = u.user_id
    LEFT JOIN campuses camp ON u.campus_id = camp.campus_id
    LEFT JOIN cycles c ON a.cycle_id = c.cycle_id
    WHERE {$base_where}
    ORDER BY a.submitted_at ASC
");
$apps->execute(array_merge([$my_uid, $my_uid], $params));
$apps = $apps->fetchAll();

$counts = $pdo->query("SELECT status, COUNT(*) as cnt FROM applications GROUP BY status")->fetchAll(PDO::FETCH_KEY_PAIR);
$returned_count = ($counts['needs_revision'] ?? 0) + ($counts['rejected'] ?? 0);

// Pending count for MY role
if ($is_talisay) {
    $my_pending_stmt = $pdo->prepare("
        SELECT COUNT(*) FROM applications a
        WHERE a.status = 'talisay_review' AND a.user_id != ?
          AND NOT EXISTS (
              SELECT 1 FROM application_checker_reviews r
              WHERE r.application_id = a.application_id
                AND r.checker_id = ?
                AND r.decision IN ('approved','rejected')
          )
    ");
} else {
    $my_pending_stmt = $pdo->prepare("
        SELECT COUNT(*) FROM applications a
        WHERE a.status IN ('submitted','under_review') AND a.user_id != ?
          AND NOT EXISTS (
              SELECT 1 FROM application_checker_reviews r
              WHERE r.application_id = a.application_id
                AND r.checker_id = ?
                AND r.decision IN ('approved','rejected')
          )
    ");
}
$my_pending_stmt->execute([$my_uid, $my_uid]);
$my_pending_count = (int)$my_pending_stmt->fetchColumn();

// My reviewed count
$my_reviewed_stmt = $pdo->prepare("
    SELECT COUNT(DISTINCT r.application_id) FROM application_checker_reviews r
    WHERE r.checker_id = ? AND r.decision IN ('approved','rejected')
");
$my_reviewed_stmt->execute([$my_uid]);
$my_reviewed_count = (int)$my_reviewed_stmt->fetchColumn();

$total_checkers_count = $is_talisay
    ? max(1, (int)$pdo->query("SELECT COUNT(*) FROM users WHERE role='talisay_checker' AND status='active'")->fetchColumn())
    : max(1, (int)$pdo->query("SELECT COUNT(*) FROM users WHERE role = 'checker' AND status='active'")->fetchColumn());

// Pre-load all slot decisions for displayed apps to avoid N+1
$all_app_ids = array_column($apps, 'application_id');
$slot_map = [];
if ($all_app_ids) {
    $in = implode(',', array_map('intval', $all_app_ids));
    $slot_rows = $pdo->query("SELECT application_id, decision FROM application_checker_reviews WHERE application_id IN ($in) ORDER BY created_at ASC")->fetchAll();
    foreach ($slot_rows as $sr) {
        $slot_map[$sr['application_id']][] = $sr['decision'];
    }
}

// Add days_waiting to each app
foreach ($apps as &$a) {
    $a['days_waiting'] = $a['submitted_at']
        ? max(0, (int)floor((time() - strtotime($a['submitted_at'])) / 86400))
        : 0;
}
unset($a);
?>

<!-- Page header -->
<div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:1rem;margin-bottom:1.25rem;">
    <div style="display:flex;align-items:center;gap:0.6rem;">
        <div style="width:36px;height:36px;border-radius:8px;background:<?= $is_talisay ? '#1a3a6b' : '#1e4d8c' ?>;display:flex;align-items:center;justify-content:center;flex-shrink:0;">
            <i class="bi bi-inbox-fill" style="color:#fff;font-size:0.9rem;"></i>
        </div>
        <div>
            <div style="font-weight:700;color:#1a3a6b;font-size:0.95rem;">Review Queue</div>
            <div style="font-size:0.72rem;color:#94a3b8;margin-top:1px;">Applications assigned for your review</div>
        </div>
    </div>
    <form method="GET" style="display:flex;gap:0.5rem;align-items:center;">
        <input type="hidden" name="page" value="review_queue">
        <input type="hidden" name="filter" value="<?= $filter ?>">
        <div style="display:flex;align-items:center;border:1.5px solid #e2e8f0;border-radius:8px;overflow:hidden;background:#f8fafc;"
             onfocusin="this.style.borderColor='#1e4d8c'" onfocusout="this.style.borderColor='#e2e8f0'">
            <span style="padding:0 0.6rem;color:#94a3b8;font-size:0.82rem;"><i class="bi bi-search"></i></span>
            <input type="text" name="search" value="<?= sanitize($search) ?>"
                   placeholder="Search faculty, ID..."
                   style="border:none;outline:none;background:transparent;padding:0.42rem 0.5rem 0.42rem 0;font-size:0.82rem;color:#1e293b;width:200px;">
        </div>
        <button type="submit"
                style="background:#1a3a6b;color:#fff;border:none;border-radius:8px;padding:0.42rem 1rem;font-size:0.82rem;font-weight:600;cursor:pointer;">
            Search
        </button>
        <?php if ($search): ?>
        <a href="?page=review_queue&filter=<?= $filter ?>"
           style="background:#f1f5f9;color:#64748b;border:1.5px solid #e2e8f0;border-radius:8px;padding:0.42rem 0.75rem;font-size:0.78rem;font-weight:600;text-decoration:none;">
            <i class="bi bi-x"></i>
        </a>
        <?php endif; ?>
    </form>
</div>

<!-- Filter tabs -->
<div class="d-flex gap-2 flex-wrap mb-3">
<?php
if ($is_talisay) {
    $tabs = [
        'pending_decision' => ['label'=>'Awaiting My Decision',  'cnt'=>$my_pending_count,            'color'=>'#1a3a6b'],
        'talisay_review'   => ['label'=>'Talisay Review',        'cnt'=>$counts['talisay_review']??0,  'color'=>'#1e4d8c'],
        'approved'         => ['label'=>'Approved',              'cnt'=>$counts['approved']??0,        'color'=>'#1e4d8c'],
        'rejected'         => ['label'=>'Returned',              'cnt'=>$returned_count,               'color'=>'#334155'],
    ];
} else {
    $tabs = [
        'pending_decision' => ['label'=>'Awaiting My Decision', 'cnt'=>$my_pending_count,              'color'=>'#1a3a6b'],
        'submitted'        => ['label'=>'Submitted',             'cnt'=>$counts['submitted']??0,        'color'=>'#1e4d8c'],
        'under_review'     => ['label'=>'Under Review',          'cnt'=>$counts['under_review']??0,     'color'=>'#1e4d8c'],
        'talisay_review'   => ['label'=>'At Talisay',            'cnt'=>$counts['talisay_review']??0,   'color'=>'#1e4d8c'],
        'approved'         => ['label'=>'Approved',              'cnt'=>$counts['approved']??0,         'color'=>'#1e4d8c'],
        'needs_revision'   => ['label'=>'Returned',              'cnt'=>$returned_count,                'color'=>'#334155'],
        'my_reviewed'      => ['label'=>'My History',             'cnt'=>$my_reviewed_count,             'color'=>'#1e4d8c'],
    ];
}
foreach ($tabs as $key => $t):
    $active = $filter === $key;
?>
<a href="?page=review_queue&filter=<?= $key ?>"
   style="padding:0.4rem 0.9rem;border-radius:20px;font-size:0.82rem;font-weight:600;text-decoration:none;
          <?= $active
            ? 'background:#1a3a6b;color:#fff;'
            : 'background:#f0f4fb;color:#64748b;border:1px solid #e2e8f0;' ?>">
    <?= $t['label'] ?>
    <?php if ($t['cnt']): ?>
    <span style="display:inline-flex;align-items:center;justify-content:center;width:18px;height:18px;
                 border-radius:50%;font-size:0.65rem;font-weight:700;margin-left:4px;
                 <?= $active ? 'background:rgba(255,255,255,0.25);color:#fff;' : 'background:#1a3a6b;color:#fff;' ?>">
        <?= $t['cnt'] ?>
    </span>
    <?php endif; ?>
</a>
<?php endforeach; ?>
</div>

<!-- Table card -->
<div class="neon-card" style="padding:0;overflow:hidden;">
<?php if ($apps): ?>
<div class="table-responsive">
    <table style="width:100%;border-collapse:collapse;font-size:0.84rem;">
        <thead>
            <tr style="background:#f8fafc;border-bottom:2px solid #e2e8f0;">
                <th style="padding:0.75rem 1rem;color:#64748b;font-size:0.68rem;text-transform:uppercase;letter-spacing:0.05em;font-weight:700;white-space:nowrap;">Faculty</th>
                <th style="padding:0.75rem 0.75rem;color:#64748b;font-size:0.68rem;text-transform:uppercase;letter-spacing:0.05em;font-weight:700;">Campus</th>
                <th style="padding:0.75rem 0.75rem;color:#64748b;font-size:0.68rem;text-transform:uppercase;letter-spacing:0.05em;font-weight:700;">Cycle</th>
                <th style="padding:0.75rem 0.75rem;color:#64748b;font-size:0.68rem;text-transform:uppercase;letter-spacing:0.05em;font-weight:700;text-align:center;">Score</th>
                <?php if (in_array($filter, ['submitted','under_review','pending_decision'])): ?>
                <?php if ($filter === 'pending_decision'): ?>
                <th style="padding:0.75rem 0.75rem;color:#64748b;font-size:0.68rem;text-transform:uppercase;letter-spacing:0.05em;font-weight:700;text-align:center;">Status</th>
                <?php endif; ?>
                <th style="padding:0.75rem 0.75rem;color:#64748b;font-size:0.68rem;text-transform:uppercase;letter-spacing:0.05em;font-weight:700;text-align:center;">Approvals</th>
                <th style="padding:0.75rem 0.75rem;color:#64748b;font-size:0.68rem;text-transform:uppercase;letter-spacing:0.05em;font-weight:700;text-align:center;">My Status</th>
                <?php endif; ?>
                <th style="padding:0.75rem 0.75rem;color:#64748b;font-size:0.68rem;text-transform:uppercase;letter-spacing:0.05em;font-weight:700;">Submitted</th>
                <th style="padding:0.75rem 0.75rem;color:#64748b;font-size:0.68rem;text-transform:uppercase;letter-spacing:0.05em;font-weight:700;text-align:center;">Action</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($apps as $a):
            $approvals = (int)$a['approvals_count'];
            $my_dec    = $a['my_decision'] ?? null;
        ?>        <tr style="border-bottom:1px solid #f0f4fb;" onmouseover="this.style.background='#f8fafc'" onmouseout="this.style.background=''">
            <td style="padding:0.75rem 1rem;vertical-align:middle;">
                <div style="font-weight:600;color:#1e293b;font-size:0.84rem;"><?= htmlspecialchars(formatDisplayName($a)) ?></div>
                <div style="font-size:0.7rem;color:#94a3b8;margin-top:1px;"><?= sanitize($a['employee_id'] ?? '') ?>
                <?php if (!empty($a['rank'])): ?>
                &nbsp;&middot;&nbsp;<span style="color:#64748b;"><?= sanitize($a['rank']) ?></span>
                <?php endif; ?>
                <?php $wait = (int)($a['days_waiting'] ?? 0);
                if ($wait >= 5): ?>
                &nbsp;<span style="background:#f8fafc;color:#1e293b;border:1px solid #e2e8f0;border-radius:20px;padding:1px 7px;font-size:0.65rem;font-weight:700;"><i class="bi bi-clock-fill me-1"></i><?= $wait ?>d overdue</span>
                <?php elseif ($wait >= 3): ?>
                &nbsp;<span style="background:#f8fafc;color:#334155;border:1px solid #e2e8f0;border-radius:20px;padding:1px 7px;font-size:0.65rem;font-weight:700;"><?= $wait ?>d waiting</span>
                <?php endif; ?>
                </div>
            </td>
            <td style="padding:0.75rem 0.75rem;color:#64748b;font-size:0.82rem;vertical-align:middle;"><?= sanitize($a['campus_name']) ?></td>
            <td style="padding:0.75rem 0.75rem;color:#64748b;font-size:0.78rem;max-width:160px;vertical-align:middle;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;" title="<?= sanitize($a['cycle_name']) ?>"><?= sanitize($a['cycle_name']) ?></td>
            <td style="padding:0.75rem 0.75rem;text-align:center;vertical-align:middle;">
                <?php $score = (float)$a['weighted_score'];
                      $sc = $score >= 71 ? '#1e4d8c' : ($score >= 41 ? '#1e4d8c' : '#334155'); ?>
                <span style="background:<?= $sc ?>;color:#fff;padding:0.22rem 0.65rem;border-radius:20px;font-size:0.78rem;font-weight:700;">
                    <?= number_format($score, 2) ?>
                </span>
            </td>
            <?php if (in_array($filter, ['submitted','under_review','pending_decision'])): ?>
            <?php if ($filter === 'pending_decision'): ?>
            <td style="padding:0.75rem 0.75rem;text-align:center;vertical-align:middle;">
                <?php $st = $a['status'];
                      $st_bg = $st === 'submitted' ? '#dbeafe' : '#f1f5f9';
                      $st_tc = $st === 'submitted' ? '#1e4d8c' : '#334155'; ?>
                <span style="background:<?= $st_bg ?>;color:<?= $st_tc ?>;padding:0.2rem 0.6rem;border-radius:20px;font-size:0.7rem;font-weight:600;">
                    <?= $st === 'submitted' ? 'Submitted' : 'Under Review' ?>
                </span>
            </td>
            <?php endif; ?>
            <td style="padding:0.75rem 0.75rem;text-align:center;vertical-align:middle;">
                <?php
                $slot_decisions = $slot_map[$a['application_id']] ?? [];
                ?>
                <div style="display:flex;gap:3px;justify-content:center;align-items:center;">
                    <?php for ($i = 0; $i < $total_checkers_count; $i++):
                        $d = $slot_decisions[$i] ?? null;
                        $dot_bg = $d === 'approved' ? '#1a3a6b' : ($d === 'rejected' ? '#334155' : ($d === 'pending' ? '#475569' : '#e2e8f0'));
                    ?>
                    <span style="width:10px;height:10px;border-radius:50%;display:inline-block;background:<?= $dot_bg ?>;flex-shrink:0;"
                          title="Checker <?= $i+1 ?>: <?= $d ?? 'not joined' ?>"></span>
                    <?php endfor; ?>
                    <span style="font-size:0.68rem;color:#94a3b8;margin-left:3px;"><?= $approvals ?>/<?= $total_checkers_count ?></span>
                </div>
            </td>
            <td style="padding:0.75rem 0.75rem;text-align:center;vertical-align:middle;">
                <?php if ($my_dec === 'approved'): ?>
                <span style="background:#dbeafe;color:#1e4d8c;padding:0.18rem 0.55rem;border-radius:20px;font-size:0.7rem;font-weight:600;white-space:nowrap;">
                    <i class="bi bi-check-circle me-1"></i>Approved
                </span>
                <?php elseif ($my_dec === 'rejected'): ?>
                <span style="background:#f8fafc;color:#1e293b;padding:0.18rem 0.55rem;border-radius:20px;font-size:0.7rem;font-weight:600;white-space:nowrap;">
                    <i class="bi bi-x-circle me-1"></i>Rejected
                </span>
                <?php else: ?>
                <span style="background:#f8fafc;color:#334155;padding:0.18rem 0.55rem;border-radius:20px;font-size:0.7rem;font-weight:600;white-space:nowrap;">
                    <i class="bi bi-hourglass-split me-1"></i>Pending
                </span>
                <?php endif; ?>
            </td>
            <?php endif; ?>
            <td style="padding:0.75rem 0.75rem;color:#64748b;font-size:0.78rem;white-space:nowrap;vertical-align:middle;">
                <?php if ($filter === 'my_reviewed' && !empty($a['my_decided_at'])): ?>
                    <div style="font-size:0.72rem;color:#94a3b8;">Decided</div>
                    <?= date('M d, Y', strtotime($a['my_decided_at'])) ?>
                <?php else: ?>
                    <?= $a['submitted_at'] ? date('M d, Y', strtotime($a['submitted_at'])) : '&mdash;' ?>
                <?php endif; ?>
            </td>
            <td style="padding:0.75rem 0.75rem;text-align:center;vertical-align:middle;">
                <a href="?page=review_application&id=<?= $a['application_id'] ?>"
                   style="display:inline-flex;align-items:center;gap:0.3rem;padding:0.32rem 0.85rem;
                          border-radius:6px;font-size:0.75rem;font-weight:600;text-decoration:none;
                          background:#f0f4fb;color:#1a3a6b;border:1px solid #dbeafe;transition:all 0.15s;white-space:nowrap;"
                   onmouseover="this.style.background='#1a3a6b';this.style.color='#fff'"
                   onmouseout="this.style.background='#f0f4fb';this.style.color='#1a3a6b'">
                    <i class="bi bi-eye" style="font-size:0.72rem;"></i>
                    <?= in_array($my_dec, ['approved','rejected']) ? 'View' : 'Review' ?>
                </a>
            </td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>
<?php else: ?>
<div style="text-align:center;padding:3.5rem 1.5rem;color:#94a3b8;">
    <i class="bi bi-inbox" style="font-size:2.5rem;color:#dbeafe;display:block;margin-bottom:0.75rem;"></i>
    <div style="font-size:0.95rem;font-weight:600;color:#475569;margin-bottom:0.3rem;">No applications found</div>
    <div style="font-size:0.82rem;">Try a different filter or search term.</div>
</div>
<?php endif; ?>

<!-- Table footer -->
<div style="padding:0.6rem 1rem;background:#f8fafc;border-top:1px solid #f0f4fb;display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:0.5rem;">
    <span style="font-size:0.72rem;color:#94a3b8;">
        Showing <strong style="color:#1a3a6b;"><?= count($apps) ?></strong> application<?= count($apps) !== 1 ? 's' : '' ?>
    </span>
    <?php if ($filter): ?>
    <a href="?page=review_queue" style="font-size:0.72rem;color:#64748b;text-decoration:none;font-weight:600;">
        <i class="bi bi-x-circle me-1"></i>Clear filter
    </a>
    <?php endif; ?>
</div>
</div>
