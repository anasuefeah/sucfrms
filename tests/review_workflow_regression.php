<?php
// Uses connection-local temporary tables; real application data is untouched.
require_once __DIR__ . '/../includes/review_workflow_migration.php';
$pdo = new PDO(
    'mysql:host=' . (getenv('DB_HOST') ?: 'localhost') . ';dbname=' . (getenv('DB_NAME') ?: 'SUCFRMS') . ';charset=utf8mb4',
    getenv('DB_USER') ?: 'root', getenv('DB_PASS') ?: '',
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
);
$checks = 0;
function workflowCheck(bool $ok, string $message): void {
    global $checks;
    if (!$ok) throw new RuntimeException($message);
    $checks++;
}

$pdo->exec("CREATE TEMPORARY TABLE applications (
    application_id INT PRIMARY KEY,
    status ENUM('draft','submitted','under_review','talisay_review','approved','rejected','reclassified','admin_rejected','edit_requested','needs_revision','expired') DEFAULT 'draft',
    weighted_score DECIMAL(8,2), checker_remarks TEXT,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
)");
$pdo->exec("CREATE TEMPORARY TABLE notifications (
    notif_id INT PRIMARY KEY, type VARCHAR(50), message TEXT, is_read TINYINT, application_id INT
)");
$statuses = ['draft','submitted','under_review','talisay_review','approved','rejected','reclassified','admin_rejected','edit_requested','needs_revision','expired'];
$insert = $pdo->prepare("INSERT INTO applications VALUES (?,?,75.25,'Preserved reviewer remarks','2020-01-01 00:00:00')");
foreach ($statuses as $i => $status) $insert->execute([$i + 1, $status]);
$pdo->exec("INSERT INTO notifications VALUES
    (1,'talisay_review','Your application is in ITC Review.',0,4),
    (2,'new_talisay_submission','Application forwarded for ITC review.',1,4),
    (3,'approved','Your evaluation is complete.',0,5)");
$before = $pdo->query('SELECT * FROM applications ORDER BY application_id')->fetchAll();
workflowCheck(migrateSingleStageReview($pdo), 'Migration did not complete');
$after = $pdo->query('SELECT * FROM applications ORDER BY application_id')->fetchAll();
workflowCheck(count($before) === count($after), 'Migration removed an application');
foreach ($after as $i => $row) {
    $expected = $before[$i];
    if ($expected['status'] === 'talisay_review') $expected['status'] = 'under_review';
    workflowCheck($row === $expected, 'Migration changed data beyond the retired status');
}
$column = $pdo->query("SHOW COLUMNS FROM applications LIKE 'status'")->fetch();
workflowCheck(!str_contains($column['Type'], 'talisay_review'), 'Retired status remains available');
$notifs = $pdo->query('SELECT * FROM notifications ORDER BY notif_id')->fetchAll();
workflowCheck(count($notifs) === 3, 'Notification history was deleted');
workflowCheck($notifs[0]['type'] === 'under_review' && $notifs[1]['type'] === 'new_submission', 'Legacy notifications were not converted');
workflowCheck(!str_contains(strtolower($notifs[0]['message'] . $notifs[1]['message']), 'itc'), 'Legacy notification text remains');
workflowCheck((int)$notifs[0]['is_read'] === 0 && (int)$notifs[1]['is_read'] === 1 && (int)$notifs[0]['application_id'] === 4, 'Notification state or application link changed');
workflowCheck($notifs[2]['message'] === 'Your evaluation is complete.', 'Unrelated notification changed');
workflowCheck(migrateSingleStageReview($pdo), 'Repeated migration failed');
workflowCheck($pdo->query('SELECT * FROM applications ORDER BY application_id')->fetchAll() === $after, 'Repeated migration changed applications');
workflowCheck($pdo->query('SELECT * FROM notifications ORDER BY notif_id')->fetchAll() === $notifs, 'Repeated migration changed notifications');
echo "Single-stage review migration: {$checks} checks passed.\n";
