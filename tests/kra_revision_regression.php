<?php
require_once __DIR__ . '/../includes/functions.php';
$pdo = new PDO(
    'mysql:host=' . (getenv('DB_HOST') ?: 'localhost') . ';dbname=' . (getenv('DB_NAME') ?: 'SUCFRMS') . ';charset=utf8mb4',
    getenv('DB_USER') ?: 'root', getenv('DB_PASS') ?: '',
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
);
KraRevisionWorkflow::ensureSchema($pdo);
$checks = 0;
function revisionCheck(bool $ok, string $message): void {
    global $checks;
    if (!$ok) throw new RuntimeException($message);
    $checks++;
}
function revisionDenied(callable $action, string $message): void {
    try { $action(); } catch (RuntimeException $e) { revisionCheck(true, $message); return; }
    throw new RuntimeException($message);
}
revisionCheck(!KraRevisionWorkflow::wasFlagged(['revision_status'=>'ok']), 'An entry with no revision history has a flag');
revisionCheck(KraRevisionWorkflow::wasFlagged(['revision_status'=>'needs_revision']), 'Active flag missing');
revisionCheck(KraRevisionWorkflow::wasFlagged(['revision_status'=>'ok','revision_at'=>'2026-11-16 10:00:00']), 'Cleared flag history missing');

$app = $pdo->query('SELECT application_id,user_id FROM applications ORDER BY application_id LIMIT 1')->fetch();
if (!$app) throw new RuntimeException('A local application is required for this rollback-only test.');
$appId = (int)$app['application_id'];
$pdo->beginTransaction();
try {
    $pdo->prepare("UPDATE applications SET status='needs_revision' WHERE application_id=?")->execute([$appId]);
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
    $requester = $pdo->query("SELECT user_id FROM users WHERE role='evaluator' AND status='active' LIMIT 1")->fetchColumn();
    if ($requester) {
        $pdo->prepare('UPDATE kra_submissions SET revision_by=? WHERE submission_id=?')->execute([$requester,$flagged[0]]);
        $expectedRecipients[] = $requester;
    }
    $expectedRecipients = array_values(array_unique(array_map('intval', $expectedRecipients)));
    sort($expectedRecipients);
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
echo "$checks revision workflow checks passed; test data rolled back.\n";
