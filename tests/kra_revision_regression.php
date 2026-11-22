<?php
require_once __DIR__ . '/../includes/functions.php';
$pdo = new PDO(
    'mysql:host=' . (getenv('DB_HOST') ?: 'localhost') . ';dbname=' . (getenv('DB_NAME') ?: 'SUCFRMS') . ';charset=utf8mb4',
    getenv('DB_USER') ?: 'root', getenv('DB_PASS') ?: '',
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
);
foreach (['users','cycles','applications','kra_submissions','kra_evidence_files','kra_checker_verifications','checker_kra_assignments','notifications'] as $table) {
    $pdo->exec("CREATE TEMPORARY TABLE revision_test_{$table} LIKE {$table}");
    $pdo->exec("ALTER TABLE revision_test_{$table} RENAME TO {$table}");
}
KraRevisionWorkflow::ensureSchema($pdo);
$checks = 0;
function revisionCheck(bool $ok, string $message): void {
    global $checks;
    if (!$ok) throw new RuntimeException($message);
    $checks++;
}
function revisionDenied(callable $action, string $message, ?string $expectedText = null): void {
    try { $action(); } catch (RuntimeException $e) {
        revisionCheck($expectedText === null || str_contains($e->getMessage(), $expectedText), $message);
        return;
    }
    throw new RuntimeException($message);
}
revisionCheck(!KraRevisionWorkflow::wasFlagged(['revision_status'=>'ok']), 'An entry with no revision history has a flag');
revisionCheck(KraRevisionWorkflow::wasFlagged(['revision_status'=>'needs_revision']), 'Active flag missing');
revisionCheck(KraRevisionWorkflow::wasFlagged(['revision_status'=>'ok','revision_at'=>'2026-11-16 10:00:00']), 'Cleared flag history missing');

$pdo->exec("INSERT INTO users (user_id,first_name,last_name,email,password,role,status) VALUES
    (1,'Test','Faculty','revision-faculty@example.invalid','fixture','faculty','active'),
    (2,'Test','Requester','revision-requester@example.invalid','fixture','evaluator','active'),
    (3,'Test','Assigned','revision-assigned@example.invalid','fixture','evaluator','active'),
    (4,'Test','Inactive','revision-inactive@example.invalid','fixture','evaluator','inactive')");
$pdo->exec("INSERT INTO cycles (cycle_id,cycle_name,start_date,end_date,status) VALUES (1,'Revision fixture','2025-01-01','2025-12-31','open')");
$pdo->exec("INSERT INTO applications (application_id,user_id,cycle_id,status) VALUES (1,1,1,'submitted')");
$pdo->exec("INSERT INTO checker_kra_assignments (cycle_id,checker_id,kra_category) VALUES (1,3,'Research'),(1,4,'Research')");
$pdo->exec("INSERT INTO kra_submissions (application_id,user_id,kra_category,remarks) VALUES (1,1,'Instruction','Existing entry')");
$app = ['application_id'=>1,'user_id'=>1];
$appId = (int)$app['application_id'];
$pdo->beginTransaction();
try {
    $pdo->prepare("UPDATE applications SET status='needs_revision' WHERE application_id=?")->execute([$appId]);
    revisionDenied(fn() => KraRevisionWorkflow::resubmit($pdo,$appId), 'An empty revision was resubmitted');
    $pdo->prepare("UPDATE kra_submissions SET revision_status='ok',revision_saved_at=NULL WHERE application_id=?")->execute([$appId]);
    $original = $pdo->query("SELECT * FROM kra_submissions WHERE application_id=$appId ORDER BY submission_id")->fetchAll();
    $evidenceCount = (int)$pdo->query('SELECT COUNT(*) FROM kra_evidence_files')->fetchColumn();
    $flagged = [];
    $unflagged = [];
    $insert = $pdo->prepare("INSERT INTO kra_submissions
        (application_id,user_id,kra_category,computed_points,remarks,verified,revision_status,revision_note,revision_at)
        VALUES (?,?,?,5,'Regression test',?,?,?,NOW())");
    foreach (['Instruction', 'Research', 'Extension', 'Professional Development'] as $category) {
        $insert->execute([$appId,$app['user_id'],$category,0,'needs_revision','Correct this specific entry']);
        $id = (int)$pdo->lastInsertId();
        $flagged[] = $id;
        revisionCheck(KraRevisionWorkflow::requireEditable($pdo,$appId,$id,$category)['submission_id'] == $id, "$category flagged entry is locked");
        $insert->execute([$appId,$app['user_id'],$category,1,'ok',null]);
        $other = (int)$pdo->lastInsertId();
        $unflagged[] = $other;
        revisionDenied(fn() => KraRevisionWorkflow::requireEditable($pdo,$appId,$other,$category), "$category unflagged entry is editable");
        revisionDenied(fn() => KraRevisionWorkflow::requireEditable($pdo,$appId,0,$category), "$category allows a new entry during revision");
        KraRevisionWorkflow::markSaved($pdo,$appId,$id);
        revisionCheck(KraRevisionWorkflow::requireEditable($pdo,$appId,$id,$category) !== null, 'Saving locked the flagged entry');
        $saved = $pdo->query("SELECT revision_status,revision_note,revision_saved_at FROM kra_submissions WHERE submission_id=$id")->fetch();
        revisionCheck($saved['revision_status'] === 'needs_revision' && $saved['revision_saved_at'] !== null, 'Saving cleared the revision request');
        revisionCheck($saved['revision_note'] === 'Correct this specific entry', 'Evaluator remark was lost');
    }
    revisionDenied(fn() => KraRevisionWorkflow::requireEditable($pdo,$appId,$flagged[0],'Research'), 'Entry could be moved to another KRA');
    $last = end($flagged);
    $notificationStart = (int)$pdo->query('SELECT COALESCE(MAX(notif_id),0) FROM notifications')->fetchColumn();
    $cycleId = (int)$pdo->query("SELECT cycle_id FROM applications WHERE application_id=$appId")->fetchColumn();
    $expectedRecipients = $pdo->query("SELECT DISTINCT u.user_id FROM users u
        JOIN checker_kra_assignments ka ON ka.checker_id=u.user_id
        WHERE u.role='evaluator' AND u.status='active' AND ka.cycle_id=$cycleId")->fetchAll(PDO::FETCH_COLUMN);
    $requester = 2;
    if ($requester) {
        $pdo->prepare('UPDATE kra_submissions SET revision_by=? WHERE submission_id=?')->execute([$requester,$flagged[0]]);
        $expectedRecipients[] = $requester;
    }
    $expectedRecipients = array_values(array_unique(array_map('intval', $expectedRecipients)));
    sort($expectedRecipients);
    $pdo->prepare('UPDATE kra_submissions SET remarks=?,revision_saved_at=NULL WHERE submission_id=?')
        ->execute(['Criterion A - Journal Article (50pts)|||Test Author|||University|||Science|||Coastal Flood Mapping|||100', $flagged[1]]);
    revisionDenied(fn() => KraRevisionWorkflow::resubmit($pdo,$appId), 'The unsaved warning did not identify the research entry',
        'Research — Journal Article — Coastal Flood Mapping');
    KraRevisionWorkflow::markSaved($pdo, $appId, $flagged[1]);
    $pdo->prepare('UPDATE kra_submissions SET revision_saved_at=NULL WHERE submission_id=?')->execute([$last]);
    revisionDenied(fn() => KraRevisionWorkflow::resubmit($pdo,$appId), 'Unsaved corrections were resubmitted');
    revisionCheck((int)$pdo->query("SELECT COUNT(*) FROM notifications WHERE notif_id>$notificationStart AND type='revision_resubmitted' AND application_id=$appId")->fetchColumn() === 0, 'Failed resubmission notified reviewers');
    revisionCheck($pdo->query("SELECT status FROM applications WHERE application_id=$appId")->fetchColumn() === 'needs_revision', 'Failed resubmission changed application status');
    KraRevisionWorkflow::markSaved($pdo,$appId,$last);

    $beforeUnflagged = $pdo->query('SELECT * FROM kra_submissions WHERE submission_id IN (' . implode(',', $unflagged) . ') ORDER BY submission_id')->fetchAll();
    revisionCheck(KraRevisionWorkflow::resubmit($pdo,$appId) === 4, 'Wrong entries resubmitted');
    $notified = array_map('intval', $pdo->query("SELECT user_id FROM notifications
        WHERE notif_id>$notificationStart AND type='revision_resubmitted' AND application_id=$appId ORDER BY user_id")->fetchAll(PDO::FETCH_COLUMN));
    revisionCheck($notified === $expectedRecipients, 'Affected evaluators were not notified exactly once');
    revisionCheck($pdo->query("SELECT status FROM applications WHERE application_id=$appId")->fetchColumn() === 'under_review', 'Evaluator review did not resume');
    $afterUnflagged = $pdo->query('SELECT * FROM kra_submissions WHERE submission_id IN (' . implode(',', $unflagged) . ') ORDER BY submission_id')->fetchAll();
    revisionCheck($beforeUnflagged === $afterUnflagged, 'Unflagged entries changed during resubmission');
    foreach ($original as $entry) {
        $after = $pdo->query('SELECT * FROM kra_submissions WHERE submission_id=' . (int)$entry['submission_id'])->fetch();
        revisionCheck($entry === $after, 'An existing unrelated entry changed');
    }
    foreach ($flagged as $id) {
        $entry = $pdo->query("SELECT * FROM kra_submissions WHERE submission_id=$id")->fetch();
        revisionCheck($entry['revision_status'] === 'ok' && (int)$entry['verified'] === 0, 'Revised entry is not ready for evaluator review');
        revisionCheck($entry['revision_note'] === 'Correct this specific entry', 'Revision history was lost');
        revisionCheck(KraRevisionWorkflow::wasFlagged($entry), 'Flag symbol lost after resubmission');
        KraRevisionWorkflow::clearFlag($pdo,$appId,$id);
        $cleared = $pdo->query("SELECT * FROM kra_submissions WHERE submission_id=$id")->fetch();
        foreach (['revision_note','revision_by','revision_at'] as $field) {
            revisionCheck($cleared[$field] === $entry[$field], 'Clearing the flag erased '.$field);
        }
        $pdo->prepare('UPDATE kra_submissions SET verified=1 WHERE submission_id=?')->execute([$id]);
        $accepted = $pdo->query("SELECT * FROM kra_submissions WHERE submission_id=$id")->fetch();
        revisionCheck(KraRevisionWorkflow::wasFlagged($accepted), 'Accepting an entry erased its history marker');
    }
    revisionCheck((int)$pdo->query('SELECT COUNT(*) FROM kra_evidence_files')->fetchColumn() === $evidenceCount, 'Unrelated evidence changed');
    revisionDenied(fn() => KraRevisionWorkflow::resubmit($pdo,$appId), 'Duplicate resubmission allowed');
    revisionCheck((int)$pdo->query("SELECT COUNT(*) FROM notifications WHERE notif_id>$notificationStart AND type='revision_resubmitted' AND application_id=$appId")->fetchColumn() === count($expectedRecipients), 'Duplicate resubmission duplicated notifications');
} finally {
    $pdo->rollBack();
}
echo "$checks revision workflow checks passed; temporary tables only.\n";
