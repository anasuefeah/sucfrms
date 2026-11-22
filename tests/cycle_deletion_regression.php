<?php
// All fixtures and deletions are rolled back, including on assertion failures.
require_once __DIR__ . '/../includes/cycle_deletion.php';
require_once __DIR__ . '/../includes/criteria_snapshots.php';
$pdo = new PDO(
    'mysql:host=' . (getenv('DB_HOST') ?: 'localhost') . ';dbname=' . (getenv('DB_NAME') ?: 'SUCFRMS') . ';charset=utf8mb4',
    getenv('DB_USER') ?: 'root', getenv('DB_PASS') ?: '',
    [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]
);
$checks = 0;
CriteriaSnapshots::ensureSchema($pdo);
function deletionCheck(bool $ok, string $message): void {
    global $checks;
    if (!$ok) throw new RuntimeException($message);
    $checks++;
}
function deletionFixture(PDO $pdo, int $userId): int {
    $pdo->exec("INSERT INTO cycles (cycle_name,start_date,end_date,status) VALUES ('Deletion regression fixture','2020-01-01','2020-12-31','closed')");
    $cycleId = (int)$pdo->lastInsertId();
    $pdo->prepare("INSERT INTO scoring_criteria (cycle_id,position_rank,kra_category,criterion_key,criterion_label,max_points) VALUES (?,'Instructor I','Instruction','regression_delete','Deletion regression',10)")->execute([$cycleId]);
    $pdo->prepare("INSERT INTO checker_kra_assignments (cycle_id,checker_id,kra_category) VALUES (?,?,'Instruction')")->execute([$cycleId,$userId]);
    $pdo->prepare('INSERT INTO applications (user_id,cycle_id) VALUES (?,?)')->execute([$userId,$cycleId]);
    $appId = (int)$pdo->lastInsertId();
    $pdo->prepare("INSERT INTO kra_submissions (application_id,user_id,kra_category) VALUES (?,?,'Instruction')")->execute([$appId,$userId]);
    $subId = (int)$pdo->lastInsertId();
    $pdo->prepare("INSERT INTO kra_evidence_files (submission_id,file_path,original_filename) VALUES (?,'regression-not-a-real-file.pdf','regression.pdf')")->execute([$subId]);
    $pdo->prepare('INSERT INTO kra_checker_verifications (submission_id,checker_id) VALUES (?,?)')->execute([$subId,$userId]);
    $pdo->prepare('INSERT INTO application_checker_reviews (application_id,checker_id) VALUES (?,?)')->execute([$appId,$userId]);
    $pdo->prepare("INSERT INTO notifications (user_id,type,message,application_id,submission_id) VALUES (?,'under_review','Regression fixture',?,?)")->execute([$userId,$appId,$subId]);
    $pdo->prepare("INSERT INTO appeals (application_id,submission_id,faculty_id,checker_id,cycle_id,appeal_type,reason) VALUES (?,?,?,?,?,'flag','Regression fixture')")->execute([$appId,$subId,$userId,$userId,$cycleId]);
    $appealId = (int)$pdo->lastInsertId();
    $pdo->prepare("INSERT INTO appeal_messages (appeal_id,sender_id,message) VALUES (?,?,'Regression fixture')")->execute([$appealId,$userId]);
    $messageId = (int)$pdo->lastInsertId();
    $pdo->prepare("INSERT INTO appeal_attachments (message_id,file_path,original_filename) VALUES (?,'regression-not-a-real-attachment.pdf','regression.pdf')")->execute([$messageId]);
    $pdo->prepare("INSERT INTO cycle_submission_extensions (cycle_id,applies_to_all,previous_deadline,new_deadline) VALUES (?,1,'2020-06-01','2020-07-01')")->execute([$cycleId]);
    return $cycleId;
}
function deletionCounts(PDO $pdo, array $tables): array {
    $counts = [];
    foreach ($tables as $table) $counts[$table] = (int)$pdo->query("SELECT COUNT(*) FROM {$table}")->fetchColumn();
    return $counts;
}
$tables = ['cycles','scoring_criteria','checker_kra_assignments','applications','kra_submissions','kra_evidence_files','kra_checker_verifications','application_checker_reviews','notifications','appeals','appeal_messages','appeal_attachments','cycle_submission_extensions'];
$before = deletionCounts($pdo,$tables);
$userId = (int)$pdo->query('SELECT MIN(user_id) FROM users')->fetchColumn();
if (!$userId) throw new RuntimeException('A local account is required for this rollback-only test.');
try {
    deleteCycleRecords($pdo,0);
    throw new RuntimeException('Deletion was allowed without a transaction');
} catch (LogicException $e) { deletionCheck(true, 'Transaction guard'); }
$pdo->beginTransaction();
try {
    $target = deletionFixture($pdo,$userId);
    $control = deletionFixture($pdo,$userId);
    deleteCycleRecords($pdo,$target);
    $after = deletionCounts($pdo,$tables);
    foreach ($tables as $table) {
        deletionCheck($after[$table] === $before[$table] + 1, "Wrong deletion scope or orphaned records in {$table}");
    }
    $remaining = $pdo->prepare('SELECT COUNT(*) FROM cycles WHERE cycle_id=?');
    $remaining->execute([$control]);
    deletionCheck((int)$remaining->fetchColumn() === 1, 'Unrelated cycle was deleted');
    try {
        deleteCycleRecords($pdo,$target);
        throw new LogicException('Missing cycle deletion was accepted');
    } catch (RuntimeException $e) { deletionCheck(true, 'Missing cycle rejected'); }
    $pdo->exec("INSERT INTO cycles (cycle_name,start_date,end_date,status) VALUES ('Empty deletion fixture','2020-01-01','2020-12-31','closed')");
    $empty = (int)$pdo->lastInsertId();
    deleteCycleRecords($pdo,$empty);
    $remaining->execute([$empty]);
    deletionCheck((int)$remaining->fetchColumn() === 0, 'Empty cycle deletion failed');
} finally {
    if ($pdo->inTransaction()) $pdo->rollBack();
}
deletionCheck(deletionCounts($pdo,$tables) === $before, 'Rollback did not restore all records');
echo "Cycle deletion: {$checks} checks passed; fixtures and deletions rolled back.\n";
