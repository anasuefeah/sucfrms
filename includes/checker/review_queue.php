<?php
$search = trim($_GET['search'] ?? '');
$filter = $_GET['filter'] ?? 'pending_decision';

$allowed_filters = ['submitted','under_review','approved','rejected','needs_revision','pending_decision','my_reviewed'];
if (!in_array($filter, $allowed_filters)) $filter = 'pending_decision';

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
ensureKraAssignmentTable($pdo);

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

$base_where .= " AND EXISTS (
    SELECT 1
    FROM kra_submissions ks_scope
    JOIN checker_kra_assignments ka_scope
      ON ka_scope.cycle_id = a.cycle_id
     AND ka_scope.kra_category = ks_scope.kra_category
     AND ka_scope.checker_id = ?
    WHERE ks_scope.application_id = a.application_id
)";
$params[] = $my_uid;

if ($search) {
    $base_where .= " AND (u.full_name LIKE ? OR u.employee_id LIKE ?)";
    $params = array_merge($params, ["%$search%", "%$search%"]);
}

$apps = $pdo->prepare("
    SELECT a.*, u.full_name, u.first_name, u.middle_name, u.last_name, u.employee_id, u.`rank`,
        COALESCE(camp.campus_name, '-') as campus_name, c.cycle_name, c.evaluation_deadline,
        (SELECT COUNT(*) FROM application_checker_reviews r
         JOIN users uc ON r.checker_id = uc.user_id
         WHERE r.application_id = a.application_id AND r.decision = 'approved'
           AND uc.role IN ('evaluator')) AS approvals_count,
        (SELECT COUNT(*) FROM application_checker_reviews r
         JOIN users uc ON r.checker_id = uc.user_id
         WHERE r.application_id = a.application_id
           AND uc.role IN ('evaluator')) AS slots_taken,
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

$counts = [];
$counts_stmt = $pdo->prepare("
    SELECT a.status, COUNT(DISTINCT a.application_id) AS cnt
    FROM applications a
    JOIN kra_submissions ks_scope ON ks_scope.application_id = a.application_id
    JOIN checker_kra_assignments ka_scope
      ON ka_scope.cycle_id = a.cycle_id
     AND ka_scope.kra_category = ks_scope.kra_category
     AND ka_scope.checker_id = ?
    GROUP BY a.status
");
$counts_stmt->execute([$my_uid]);
$counts = $counts_stmt->fetchAll(PDO::FETCH_KEY_PAIR);
$returned_count = ($counts['needs_revision'] ?? 0) + ($counts['rejected'] ?? 0);

// Pending count for MY queue
$my_pending_stmt = $pdo->prepare("
    SELECT COUNT(*) FROM applications a
    WHERE a.status IN ('submitted','under_review') AND a.user_id != ?
      AND EXISTS (
          SELECT 1
          FROM kra_submissions ks_scope
          JOIN checker_kra_assignments ka_scope
            ON ka_scope.cycle_id = a.cycle_id
           AND ka_scope.kra_category = ks_scope.kra_category
           AND ka_scope.checker_id = ?
          WHERE ks_scope.application_id = a.application_id
      )
      AND NOT EXISTS (
          SELECT 1 FROM application_checker_reviews r
          WHERE r.application_id = a.application_id
            AND r.checker_id = ?
            AND r.decision IN ('approved','rejected')
      )
");
$my_pending_stmt->execute([$my_uid, $my_uid, $my_uid]);
$my_pending_count = (int)$my_pending_stmt->fetchColumn();

// My reviewed count
$my_reviewed_stmt = $pdo->prepare("
    SELECT COUNT(DISTINCT r.application_id) FROM application_checker_reviews r
    WHERE r.checker_id = ? AND r.decision IN ('approved','rejected')
");
$my_reviewed_stmt->execute([$my_uid]);
$my_reviewed_count = (int)$my_reviewed_stmt->fetchColumn();

$total_checkers_count = max(1, (int)$pdo->query("SELECT COUNT(*) FROM users WHERE role='evaluator' AND status='active'")->fetchColumn());

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
    [$a['eval_deadline_label'], $a['eval_deadline_color'], $a['eval_deadline_bg']] =
        getEvaluationDeadlineLabel($a['evaluation_deadline'] ?? null);
}
unset($a);
?>

<!-- Page header -->
<div style="background:#fff;border:1px solid #e2e8f0;padding:0.85rem 1.25rem;margin-bottom:1.25rem;
            display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:1rem;">
    <div style="display:flex;align-items:center;gap:0.75rem;">
        <div style="width:36px;height:36px;background:#1a3a6b;display:flex;align-items:center;justify-content:center;flex-shrink:0;">
            <i class="bi bi-inbox-fill" style="color:#fff;font-size:0.9rem;"></i>
        </div>
        <div>
            <div style="font-size:0.92rem;font-weight:700;color:#0f172a;line-height:1.2;">Review Queue</div>
            <div style="font-size:0.72rem;color:#94a3b8;margin-top:2px;">Applications assigned for your review</div>
        </div>
    </div>
</div>

<!-- Search bar -->
<div style="background:#fff;border:1px solid #e2e8f0;padding:0.85rem 1.25rem;margin-bottom:0;">
    <form method="GET">
        <input type="hidden" name="page" value="review_queue">
        <input type="hidden" name="filter" value="<?= $filter ?>">
        <div style="display:flex;gap:0.75rem;align-items:center;">
            <div style="flex:1;min-width:200px;display:flex;align-items:center;border:1px solid #e2e8f0;background:#f8fafc;"
                 onfocusin="this.style.borderColor='#1a3a6b'" onfocusout="this.style.borderColor='#e2e8f0'">
                <span style="padding:0 0.65rem;color:#94a3b8;font-size:0.82rem;"><i class="bi bi-search"></i></span>
                <input type="text" name="search" value="<?= sanitize($search) ?>"
                       placeholder="Search faculty, ID..."
                       style="flex:1;border:none;outline:none;background:transparent;padding:0.5rem 0.5rem 0.5rem 0;font-size:0.82rem;color:#1e293b;">
                <?php if ($search): ?>
                <a href="?page=review_queue&filter=<?= $filter ?>" style="padding:0 0.65rem;color:#94a3b8;text-decoration:none;"><i class="bi bi-x"></i></a>
                <?php endif; ?>
            </div>
            <button type="submit" style="background:#1a3a6b;color:#fff;border:none;padding:0.5rem 1.1rem;font-size:0.8rem;font-weight:600;cursor:pointer;white-space:nowrap;display:flex;align-items:center;gap:0.35rem;">
                <i class="bi bi-search"></i> Search
            </button>
        </div>
    </form>
</div>

<!-- Status filter tabs -->
<div style="background:#fff;border:1px solid #e2e8f0;border-top:none;display:flex;flex-wrap:wrap;margin-bottom:1.25rem;overflow-x:auto;scrollbar-width:none;">
<?php
$tabs = [
    'pending_decision' => ['label'=>'Awaiting My Decision', 'cnt'=>$my_pending_count],
    'submitted'        => ['label'=>'Submitted',            'cnt'=>$counts['submitted']??0],
    'under_review'     => ['label'=>'Under Evaluation',     'cnt'=>$counts['under_review']??0],
    'approved'         => ['label'=>'Evaluation Complete',  'cnt'=>$counts['approved']??0],
    'needs_revision'   => ['label'=>'Returned for Revision','cnt'=>$returned_count],
    'my_reviewed'      => ['label'=>'My History',           'cnt'=>$my_reviewed_count],
];
foreach ($tabs as $key => $t):
    $active = $filter === $key;
?>
<a href="?page=review_queue&filter=<?= $key ?>"
   style="display:inline-flex;align-items:center;gap:0.4rem;padding:0.6rem 1rem;
          font-size:0.75rem;font-weight:600;text-decoration:none;white-space:nowrap;
          border-bottom:2px solid <?= $active ? '#1a3a6b' : 'transparent' ?>;
          color:<?= $active ? '#1a3a6b' : '#64748b' ?>;transition:color .15s;">
    <?= $t['label'] ?>
    <?php if ($t['cnt']): ?>
    <span style="display:inline-flex;align-items:center;justify-content:center;min-width:18px;height:18px;padding:0 4px;font-size:0.6rem;font-weight:700;
                 background:<?= $active ? '#1a3a6b' : '#f1f5f9' ?>;color:<?= $active ? '#fff' : '#64748b' ?>;">
        <?= $t['cnt'] ?>
    </span>
    <?php endif; ?>
</a>
<?php endforeach; ?>
</div>

<!-- Table -->
<?php if ($apps): ?>
<div style="border:1px solid #e2e8f0;background:#fff;overflow:hidden;">
    <div class="table-responsive">
        <table style="width:100%;border-collapse:collapse;font-size:0.84rem;">
            <thead>
                <tr style="background:#f8fafc;border-bottom:2px solid #e2e8f0;">
                    <th style="padding:0.75rem 1rem;text-align:left;font-size:0.7rem;font-weight:700;text-transform:uppercase;letter-spacing:0.05em;color:#64748b;white-space:nowrap;">Faculty</th>
                    <th style="padding:0.75rem 0.75rem;text-align:left;font-size:0.7rem;font-weight:700;text-transform:uppercase;letter-spacing:0.05em;color:#64748b;">Campus</th>
                    <th style="padding:0.75rem 0.75rem;text-align:left;font-size:0.7rem;font-weight:700;text-transform:uppercase;letter-spacing:0.05em;color:#64748b;">Cycle</th>
                    <th style="padding:0.75rem 0.75rem;text-align:center;font-size:0.7rem;font-weight:700;text-transform:uppercase;letter-spacing:0.05em;color:#64748b;">Score</th>
                    <?php if (in_array($filter, ['submitted','under_review','pending_decision'])): ?>
                    <?php if ($filter === 'pending_decision'): ?>
                    <th style="padding:0.75rem 0.75rem;text-align:left;font-size:0.7rem;font-weight:700;text-transform:uppercase;letter-spacing:0.05em;color:#64748b;">Status</th>
                    <?php endif; ?>
                    <th style="padding:0.75rem 0.75rem;text-align:center;font-size:0.7rem;font-weight:700;text-transform:uppercase;letter-spacing:0.05em;color:#64748b;">Progress</th>
                    <th style="padding:0.75rem 0.75rem;text-align:center;font-size:0.7rem;font-weight:700;text-transform:uppercase;letter-spacing:0.05em;color:#64748b;">My Status</th>
                    <?php endif; ?>
                    <th style="padding:0.75rem 0.75rem;text-align:left;font-size:0.7rem;font-weight:700;text-transform:uppercase;letter-spacing:0.05em;color:#64748b;">Submitted</th>
                    <th style="padding:0.75rem 0.75rem;text-align:center;font-size:0.7rem;font-weight:700;text-transform:uppercase;letter-spacing:0.05em;color:#64748b;">Action</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($apps as $a):
                $approvals = (int)$a['approvals_count'];
                $my_dec    = $a['my_decision'] ?? null;
                $score     = (float)$a['weighted_score'];
                $wait      = (int)($a['days_waiting'] ?? 0);
                [$due_label,$due_color,$due_bg] = [$a['eval_deadline_label'],$a['eval_deadline_color'],$a['eval_deadline_bg']];
                // Avatar initials
                $name_parts = explode(',', trim($a['full_name'] ?? 'U'));
                $a_last  = trim($name_parts[0]);
                $a_first = isset($name_parts[1]) ? trim(explode(' ',$name_parts[1])[0]) : '';
                $initials = strtoupper(substr($a_first,0,1).substr($a_last,0,1)) ?: 'FA';
                $colors = ['#1a3a6b','#334155','#1e4d8c','#475569'];
                $av_bg = $colors[crc32($a['user_id']) % count($colors)];
            ?>
            <tr style="border-bottom:1px solid #f0f4fb;" onmouseover="this.style.background='#f8fafc'" onmouseout="this.style.background=''">

                <!-- Faculty -->
                <td style="padding:0.85rem 1rem;vertical-align:middle;">
                    <div style="display:flex;align-items:center;gap:0.65rem;">
                        <div style="width:36px;height:36px;border-radius:50%;background:<?= $av_bg ?>;display:flex;align-items:center;justify-content:center;font-size:0.78rem;font-weight:700;color:#fff;flex-shrink:0;">
                            <?= $initials ?>
                        </div>
                        <div>
                            <div style="font-weight:600;color:#1e293b;font-size:0.84rem;line-height:1.2;"><?= htmlspecialchars(formatDisplayName($a)) ?></div>
                            <div style="font-size:0.7rem;color:#94a3b8;margin-top:1px;">
                                <?= sanitize($a['employee_id'] ?? '') ?>
                                <?php if (!empty($a['rank'])): ?>&nbsp;·&nbsp;<span style="color:#64748b;"><?= sanitize($a['rank']) ?></span><?php endif; ?>
                                <?php if ($wait >= 3): ?>&nbsp;<span style="color:<?= $wait>=5?'#dc2626':'#94a3b8' ?>;font-weight:600;"><?= $wait ?>d waiting</span><?php endif; ?>
                            </div>
                        </div>
                    </div>
                </td>

                <!-- Campus -->
                <td style="padding:0.85rem 0.75rem;vertical-align:middle;"><span style="font-size:0.82rem;color:#475569;"><?= sanitize($a['campus_name']) ?></span></td>

                <!-- Cycle -->
                <td style="padding:0.85rem 0.75rem;vertical-align:middle;max-width:140px;">
                    <span style="font-size:0.78rem;color:#64748b;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;display:block;" title="<?= sanitize($a['cycle_name']) ?>"><?= sanitize($a['cycle_name']) ?></span>
                </td>

                <!-- Score -->
                <td style="padding:0.85rem 0.75rem;text-align:center;vertical-align:middle;">
                    <div style="display:inline-flex;flex-direction:column;align-items:center;gap:2px;">
                        <span style="background:#1a3a6b;color:#fff;padding:0.22rem 0.65rem;border-radius:6px;font-weight:700;font-size:0.82rem;"><?= number_format($score, 2) ?></span>
                        <div style="width:52px;height:4px;background:#f1f5f9;border-radius:99px;overflow:hidden;">
                            <div style="height:100%;width:<?= min(100,round($score)) ?>%;background:#1a3a6b;border-radius:99px;"></div>
                        </div>
                    </div>
                </td>

                <?php if (in_array($filter, ['submitted','under_review','pending_decision'])): ?>
                <?php if ($filter === 'pending_decision'): ?>
                <td style="padding:0.85rem 0.75rem;vertical-align:middle;">
                    <?= statusBadge($a['status']) ?>
                </td>
                <?php endif; ?>

                <!-- Progress -->
                <td style="padding:0.85rem 0.75rem;text-align:center;vertical-align:middle;">
                    <?php $slot_decisions = $slot_map[$a['application_id']] ?? []; ?>
                    <div style="display:flex;gap:3px;justify-content:center;align-items:center;">
                        <?php for ($i = 0; $i < $total_checkers_count; $i++):
                            $d = $slot_decisions[$i] ?? null;
                            $dot = $d === 'approved' ? '#1a3a6b' : ($d === 'rejected' ? '#475569' : '#e2e8f0');
                        ?>
                        <span style="width:9px;height:9px;border-radius:50%;display:inline-block;background:<?= $dot ?>;" title="Evaluator <?= $i+1 ?>: <?= $d ?? 'pending' ?>"></span>
                        <?php endfor; ?>
                        <span style="font-size:0.65rem;color:#94a3b8;margin-left:3px;"><?= $approvals ?>/<?= $total_checkers_count ?></span>
                    </div>
                </td>

                <!-- My Status -->
                <td style="padding:0.85rem 0.75rem;text-align:center;vertical-align:middle;">
                    <?php if ($my_dec === 'approved'): ?>
                    <span style="background:#eff6ff;color:#1e4d8c;padding:2px 8px;font-size:0.7rem;font-weight:600;border:1px solid #bfdbfe;border-radius:4px;">Complete</span>
                    <?php elseif ($my_dec === 'rejected'): ?>
                    <span style="background:#f8fafc;color:#475569;padding:2px 8px;font-size:0.7rem;font-weight:600;border:1px solid #e2e8f0;border-radius:4px;">Returned</span>
                    <?php else: ?>
                    <span style="background:#f8fafc;color:#94a3b8;padding:2px 8px;font-size:0.7rem;font-weight:600;border:1px solid #e2e8f0;border-radius:4px;">Pending</span>
                    <?php endif; ?>
                </td>
                <?php endif; ?>

                <!-- Submitted -->
                <td style="padding:0.85rem 0.75rem;vertical-align:middle;white-space:nowrap;">
                    <div style="font-size:0.78rem;color:#475569;"><?= $a['submitted_at'] ? date('M d, Y', strtotime($a['submitted_at'])) : '-' ?></div>
                    <div style="font-size:0.67rem;color:#94a3b8;"><?= $a['submitted_at'] ? date('H:i', strtotime($a['submitted_at'])) : '' ?></div>
                </td>

                <!-- Action -->
                <td style="padding:0.85rem 0.75rem;text-align:center;vertical-align:middle;">
                    <a href="?page=review_application&id=<?= $a['application_id'] ?>"
                       style="display:inline-flex;align-items:center;gap:0.3rem;padding:0.35rem 0.85rem;border-radius:6px;font-size:0.76rem;font-weight:600;background:#f0f4fb;color:#1a3a6b;text-decoration:none;border:1px solid #dbeafe;transition:all 0.15s;white-space:nowrap;"
                       onmouseover="this.style.background='#1a3a6b';this.style.color='#fff'"
                       onmouseout="this.style.background='#f0f4fb';this.style.color='#1a3a6b'">
                        <i class="bi bi-eye" style="font-size:0.75rem;"></i>
                        <?= in_array($my_dec, ['approved','rejected']) ? 'View' : 'Review' ?>
                    </a>
                </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <div style="padding:0.65rem 1rem;background:#f8fafc;border-top:1px solid #f0f4fb;display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:0.5rem;">
        <span style="font-size:0.75rem;color:#94a3b8;">Showing <strong style="color:#1a3a6b;"><?= count($apps) ?></strong> application<?= count($apps) !== 1 ? 's' : '' ?></span>
        <?php if ($search || $filter !== 'pending_decision'): ?>
        <a href="?page=review_queue" style="font-size:0.72rem;color:#64748b;text-decoration:none;font-weight:600;display:inline-flex;align-items:center;gap:3px;">
            <i class="bi bi-x-circle"></i> Clear filter
        </a>
        <?php endif; ?>
    </div>
</div>

<?php else: ?>
<div style="border:1px solid #e2e8f0;background:#fff;padding:3.5rem 1.5rem;text-align:center;">
    <div style="width:48px;height:48px;background:#f8fafc;display:flex;align-items:center;justify-content:center;margin:0 auto 1rem;border:1px solid #e2e8f0;">
        <i class="bi bi-inbox" style="font-size:1.25rem;color:#94a3b8;"></i>
    </div>
    <div style="font-size:0.88rem;font-weight:600;color:#475569;margin-bottom:0.3rem;">No applications found</div>
    <div style="font-size:0.78rem;color:#94a3b8;">Try a different filter or search term.</div>
</div>
<?php endif; ?>
