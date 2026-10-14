<?php
// Request handling and data loading for frontend/views/evaluator/review_queue.php
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
    SELECT a.*, u.full_name, u.first_name, u.middle_name, u.last_name, u.employee_id, u.rank,
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
