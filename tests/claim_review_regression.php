<?php
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/scoring/orchestrator.php';

$pdo = new PDO(
    'mysql:host=' . (getenv('DB_HOST') ?: 'localhost') . ';dbname=' . (getenv('DB_NAME') ?: 'SUCFRMS') . ';charset=utf8mb4',
    getenv('DB_USER') ?: 'root',
    getenv('DB_PASS') ?: '',
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
);
foreach (['users', 'cycles', 'applications', 'kra_submissions', 'kra_evidence_files', 'auto_sub_rank',
    'scoring_criteria', 'criteria_scope_state', 'criteria_snapshots', 'audit_logs',
    'checker_kra_assignments', 'kra_checker_verifications', 'application_checker_reviews'] as $table) {
    $pdo->exec("CREATE TEMPORARY TABLE claim_test_{$table} LIKE {$table}");
    $pdo->exec("ALTER TABLE claim_test_{$table} RENAME TO {$table}");
}
$pdo->exec("ALTER TABLE cycles DROP COLUMN IF EXISTS accomplishment_start, DROP COLUMN IF EXISTS accomplishment_end");
$pdo->exec("ALTER TABLE kra_submissions DROP COLUMN IF EXISTS claim_review");

$checks = 0;
function claimCheck(bool $ok, string $message): void
{
    global $checks;
    if (!$ok) throw new RuntimeException($message);
    $checks++;
}

function claimDenied(callable $action, string $expected): void
{
    try {
        $action();
    } catch (DomainException $error) {
        claimCheck(str_contains($error->getMessage(), $expected), $error->getMessage());
        return;
    }
    throw new RuntimeException("Expected refusal containing {$expected}");
}

function historicalFingerprint(array $entry): string
{
    return hash('sha256', serialize([
        (int)$entry['submission_id'], $entry['kra_category'], $entry['remarks'],
        $entry['document_path'] ?? '', $entry['evidence_ids'] ?? '',
    ]));
}

$pdo->exec("INSERT INTO users (user_id, first_name, last_name, email, password, role, status, `rank`) VALUES
    (1,'Faculty','Test','faculty@example.invalid','unused','faculty','active','Instructor I'),
    (2,'Reviewer','Test','reviewer@example.invalid','unused','evaluator','active',''),
    (3,'Admin','Test','admin@example.invalid','unused','admin','active','')");
$pdo->exec("INSERT INTO cycles (cycle_id,cycle_name,start_date,end_date,status)
    VALUES (1,'Test cycle','2026-01-01','2026-12-31','closed')");
$pdo->exec("INSERT INTO applications (application_id,cycle_id,user_id,status,position_title)
    VALUES (1,1,1,'draft','Instructor I')");
$pdo->exec("INSERT INTO kra_submissions (submission_id,application_id,user_id,kra_category,remarks,computed_points,verified) VALUES
    (1,1,1,'Instruction','A-set-sem|||AY 2025-2026|||1|||100',18,1),
    (2,1,1,'Research','Journal|||Research|||Level|||Area|||Faculty development project|||100',10,1),
    (3,1,1,'Professional Development','B-paper|||Faculty development project|||3',3,0)");
$files = $pdo->prepare('INSERT INTO kra_evidence_files (submission_id,file_path,original_filename,file_size_bytes) VALUES (?,?,?,?)');
$files->execute([1, 'test/first.pdf', 'evidence.pdf', 1500]);
$files->execute([3, 'test/second.pdf', 'evidence.pdf', 1500]);

$draft = $pdo->query("SELECT * FROM applications WHERE application_id=1")->fetch();
claimCheck(submitApplicationForReview($pdo, $draft) === 'submitted', 'Normal submission failed.');
claimCheck((float)$pdo->query("SELECT weighted_score FROM applications WHERE application_id=1")->fetchColumn() > 0.0,
    'Ordinary claims did not earn points after submitting without a separate KRA timeline.');
claimCheck((float)$pdo->query("SELECT faculty_original_score FROM kra_submissions WHERE submission_id=1")->fetchColumn() === 18.0,
    'Normal submission lost the submitted point value.');

$current = static function () use ($pdo): array {
    $app = $pdo->query("SELECT * FROM applications WHERE application_id=1")->fetch();
    $entries = ClaimReview::loadEntries($pdo, 1);
    return [$app, $entries, ClaimReview::assess($app, $entries)];
};
[$app, $entries, $assessment] = $current();
claimCheck(!$assessment['unresolved'] && $assessment['ready'], 'A title or file match blocked review.');
claimCheck(array_column(ClaimReview::scoringEntries($app, $entries, $assessment), 'submission_id') === [1, 2, 3],
    'A title or file match held back a submission.');
claimDenied(fn() => ClaimReview::requireReady($assessment, $entries), 'credited claim acceptable');

$pairKey = hash('sha256', historicalFingerprint($entries[0]) . historicalFingerprint($entries[2]));
$savedDecision = json_encode([$pairKey=>['exclude'=>3, 'reason'=>'Historical decision']], JSON_THROW_ON_ERROR);
$pdo->prepare("UPDATE applications SET duplicate_reviews=? WHERE application_id=1")->execute([$savedDecision]);
$pdo->exec("UPDATE kra_submissions SET verified=1 WHERE submission_id=3");
[$app, $entries, $assessment] = $current();
claimCheck(!$assessment['excluded'], 'A saved duplicate decision still excluded an active submission.');
ClaimReview::requireReady($assessment, $entries);
claimCheck($assessment['ready'], 'Verified claims could not complete evaluation.');
$score = Scoring\Orchestrator::run($pdo, 1);
claimCheck($score['weighted_score'] > 0 && $score['kra_results']['kra4']['subtotal'] === 3.0, 'The score still held back matching evidence.');
claimCheck(!array_filter($score['double_counting_flags'], static fn($flag) => $flag['type'] === 'possible_duplicate'),
    'Scoring still emitted duplicate review flags.');

$completed = ['status' => 'approved'] + $app;
claimCheck(isset(ClaimReview::assess($completed, $entries)['excluded'][3]),
    'An already completed evaluation lost its saved duplicate exclusion.');
$pdo->exec("UPDATE applications SET status='approved' WHERE application_id=1");
$historicalScore = Scoring\Orchestrator::run($pdo, 1);
claimCheck((float)$historicalScore['kra_results']['kra4']['subtotal'] === 0.0,
    'Re-scoring an already completed evaluation restored historically excluded points.');
$newCompletion = ClaimReview::completionMetadata($app);
claimCheck(isset(json_decode($newCompletion, true)[$pairKey]), 'Completion erased a historical decision.');
$pdo->prepare("UPDATE applications SET duplicate_reviews=? WHERE application_id=1")->execute([$newCompletion]);
$newScore = Scoring\Orchestrator::run($pdo, 1);
claimCheck($newScore['weighted_score'] === $score['weighted_score'] && (float)$newScore['kra_results']['kra4']['subtotal'] === 3.0,
    'A newly completed evaluation restored a retired exclusion.');

$historicalEntry = $entries[0];
$historicalEntry['claim_review'] = json_encode([
    'decision' => 'exclude',
    'fingerprint' => historicalFingerprint($historicalEntry),
], JSON_THROW_ON_ERROR);
claimCheck(isset(ClaimReview::assess($completed, [$historicalEntry])['excluded'][1]), 'A completed evaluation lost a historical exclusion.');
claimCheck(!ClaimReview::assess($app, [$historicalEntry])['excluded'], 'A past date decision still excluded an active claim.');
$completed['duplicate_reviews'] = $newCompletion;
claimCheck(!ClaimReview::assess($completed, [$historicalEntry])['excluded'], 'A new completion restored an obsolete exclusion.');

$pdo->exec("INSERT INTO users (user_id, first_name, last_name, email, password, role, status) VALUES
    (4,'Second','Reviewer','second@example.invalid','unused','evaluator','active'),
    (5,'Former','Reviewer','former@example.invalid','unused','evaluator','active')");
$pdo->exec("UPDATE applications SET status='under_review', reviewed_at=NULL WHERE application_id=1");
$pdo->exec("UPDATE kra_submissions SET verified=0, verified_by=NULL WHERE application_id=1");
$pdo->exec("INSERT INTO checker_kra_assignments (cycle_id,checker_id,kra_category) VALUES
    (1,2,'Instruction'), (1,4,'Instruction'), (1,4,'Research'), (1,4,'Professional Development')");
$pdo->exec("INSERT INTO application_checker_reviews (application_id,checker_id,decision) VALUES (1,5,'approved')");
$complete = static function (int $evaluatorId) use ($pdo): array {
    $pdo->beginTransaction();
    try {
        $result = ClaimReview::completeEvaluation($pdo, 1, $evaluatorId, 'Completed assigned review');
        $pdo->commit();
        return $result;
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $error;
    }
};
$status = static fn(): string => $pdo->query('SELECT status FROM applications WHERE application_id=1')->fetchColumn();
claimDenied(fn() => $complete(5), 'do not have any KRA assignments');
claimDenied(fn() => $complete(2), 'your assigned KRAs');
$pdo->exec("INSERT INTO kra_checker_verifications (submission_id,checker_id) VALUES (1,2)");
claimCheck($complete(2) === ['approved' => 1, 'total' => 2, 'complete' => false], 'First evaluator could not finish independently.');
claimCheck($status() === 'under_review', 'A former evaluator decision advanced the application.');
claimCheck($pdo->query('SELECT reviewed_at FROM applications WHERE application_id=1')->fetchColumn() === null,
    'An individual completion set the overall completion date.');
claimCheck(evaluatorDashboardCounts($pdo, 2)['checker_pending'] === 0
    && evaluatorDashboardCounts($pdo, 4)['checker_pending'] === 1, 'The application did not stay pending for the second evaluator.');
claimDenied(fn() => $complete(2), 'already been recorded');
$pdo->exec("INSERT INTO kra_checker_verifications (submission_id,checker_id) VALUES (2,4), (3,4)");
claimDenied(fn() => $complete(4), 'your assigned KRAs');
$pdo->exec("INSERT INTO kra_checker_verifications (submission_id,checker_id) VALUES (1,4)");
claimDenied(fn() => $complete(4), 'each credited claim acceptable');
$pdo->exec("UPDATE kra_submissions SET verified=1, verified_by=4 WHERE application_id=1");
claimDenied(fn() => $complete(4), 'below the minimum of 41');
claimCheck($status() === 'under_review', 'The application was approved below the minimum score.');
$pdo->exec("UPDATE kra_submissions SET remarks='CUSTOM|||A|||instruction_test|||Teaching|||60|||Instruction' WHERE submission_id=1");
$pdo->exec("UPDATE kra_submissions SET remarks='CUSTOM|||A|||research_test|||Research|||100|||Research' WHERE submission_id=2");
claimCheck($complete(4) === ['approved' => 2, 'total' => 2, 'complete' => true], 'The final evaluator could not complete the application.');
claimCheck($status() === 'approved', 'An application with all assigned evaluations complete stayed pending.');

echo "Claim review: {$checks} checks passed; temporary tables only.\n";
