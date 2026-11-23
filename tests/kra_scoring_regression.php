<?php
// Run: C:\xampp\php\php.exe tests/kra_scoring_regression.php
require_once __DIR__ . '/../includes/scoring/entry_scores.php';
require_once __DIR__ . '/../includes/scoring/autosubrank.php';
require_once __DIR__ . '/../includes/scoring/orchestrator.php';
require_once __DIR__ . '/../includes/apply/kra_entry_matching.php';
require_once __DIR__ . '/../includes/functions.php';

$pdo = new PDO(
    'mysql:host=' . (getenv('DB_HOST') ?: 'localhost') . ';dbname=' . (getenv('DB_NAME') ?: 'SUCFRMS') . ';charset=utf8mb4',
    getenv('DB_USER') ?: 'root', getenv('DB_PASS') ?: '',
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
);
$checks = 0;
function checkScore(bool $ok, string $message): void {
    global $checks;
    if (!$ok) throw new RuntimeException($message);
    $checks++;
}

// Exercise the same category-to-form mappings used by Add Entry.
$source = file_get_contents(__DIR__ . '/../includes/apply/kra_entry.php');
$maps = [];
foreach (['key_to_flat', 'key_to_ext', 'key_to_pd'] as $name) {
    preg_match('/\$' . $name . ' = (\[.*?\]);/s', $source, $match);
    $maps[$name] = eval('return ' . $match[1] . ';');
}
$criteria = $pdo->query('SELECT * FROM scoring_criteria WHERE is_active=1')->fetchAll();
foreach ($criteria as $row) {
    $key = $row['criterion_key'];
    $category = $row['kra_category'];
    $expected = (float)$row['max_points'];
    $letter = strtoupper(explode('_', $key)[1] ?? 'A');
    if ($category === 'Instruction' && isset($maps['key_to_flat'][$key])) {
        $flat = explode('|', $maps['key_to_flat'][$key], 3);
        $flat[1] = $expected . (str_ends_with($flat[1], 'co') ? 'co' : '');
        $parts = [implode('|', $flat), $flat[2], '100'];
    } elseif ($key === 'kra1_a_set' || $key === 'kra1_a_sef') {
        $parts = [$key === 'kra1_a_set' ? 'A-set-sem' : 'A-sef-sem', 'AY 2025-2026', '1', '100'];
    } elseif ($category === 'Research') {
        $parts = [$row['criterion_label'] . ' (' . $expected . 'pts)', 'Author', 'University', 'Area', 'Details', '100'];
    } elseif ($category === 'Extension' && isset($maps['key_to_ext'][$key])) {
        $type = $maps['key_to_ext'][$key];
        $value = $type === 'csr-satisfaction' ? '100' : ($type === 'income'
            ? ($expected >= 18 ? '13000000' : ($expected >= 12 ? '7000000' : '1000000')) : '1');
        $parts = [$type, 'Activity', $value, 'lead'];
    } elseif ($category === 'Professional Development' && isset($maps['key_to_pd'][$key])) {
        $pd = $maps['key_to_pd'][$key];
        $parts = [$pd['type'], $pd['role'] ?? 'Activity', $pd['sub'] ?: '1'];
    } else {
        $parts = ['CUSTOM', $letter, $key, $row['criterion_label'], (string)$expected, 'Activity'];
    }
    if ($key === 'kra1_c_mentor_competition') {
        $parts[2] = 'regional';
        $parts[3] = '1st';
    }
    if (($parts[0] ?? '') === 'B-training') $parts[3] = '6';
    if (($parts[0] ?? '') === 'B-paper') $parts[3] = '3';
    while (count($parts) < 8) $parts[] = '';
    $parts[] = 'CRITERION_KEY=' . $key;
    $remarks = implode('|||', $parts);
    $points = Scoring\EntryScores::compute($pdo, $category, $remarks, (int)$row['cycle_id'], $row['position_rank']);
    checkScore(abs($points - $expected) < 0.005, "$key: expected $expected, got $points");
    checkScore(kraEntryMatchesCriterion($category, $parts, $row, ...array_values($maps)), "$key: wrong displayed category");
}

// A contribution, rating, or duration must affect the score, not just its label.
foreach ([
    ['Instruction', 'B|30co|Textbook, Co-Author|||Book|||50', 15],
    ['Instruction', 'A-set-sem|||AY 2025-2026|||1|||50', 18],
    ['Extension', 'resource-speaker-intl|||Seminar|||3|||', 9],
    ['Extension', 'income|||Project|||13000000|||co-implementer', 9],
    ['Extension', 'committee chair|||Committee|||2|||', 4],
    ['Extension', 'media-column-regular|||Column|||1|||', 10],
    ['Professional Development', 'B-degree|||Doctorate|||40', 40],
] as [$category, $remarks, $expected]) {
    $actual = Scoring\EntryScores::compute($pdo, $category, $remarks, 1, 'Instructor II');
    checkScore(abs($actual - $expected) < 0.005, "$category formula: expected $expected, got $actual");
}

// Raw doctorate credit and application-level Auto Sub Rank handling are separate.
$doctorate = [['remarks' => 'B-degree|||Doctorate|||40', 'evidence_names' => 'diploma.pdf']];
Scoring\KRA4Scorer::setDoctoratePointsOverride(0);
checkScore(Scoring\KRA4Scorer::score($doctorate)['criterion_b'] === 0.0, 'Doctorate exclusion changed');
Scoring\KRA4Scorer::setDoctoratePointsOverride(40);
checkScore(Scoring\KRA4Scorer::score($doctorate)['criterion_b'] === 40.0, 'Doctorate points-only credit missing');
Scoring\KRA4Scorer::setDoctoratePointsOverride(null);

// Preserve the application scoring handoff from Auto Sub Rank to KRA IV.
foreach ([
    ['Instructor I', $doctorate, 'triggered', 0.0],
    ['Associate Professor V', $doctorate, 'not_eligible', 40.0],
    ['Associate Professor V', [], 'not_eligible', 0.0],
] as [$rank, $entries, $mode, $points]) {
    $assessment = Scoring\AutoSubrank::compute($pdo, ['current_rank'=>$rank], [], 0.0, 0, $entries);
    checkScore($assessment['doctorate_mode'] === $mode && $assessment['doctorate_points'] === $points,
        "$rank: Auto Sub Rank returned the wrong doctorate credit for $mode");
}

// Check both per-entry and aggregate routing for the formerly misclassified types.
foreach (['media-column-regular' => 10, 'media-column-occasional' => 2, 'media-guest' => 1,
    'media-tv-radio-host' => 10, 'dept head' => 4, 'committee chair inst' => 3,
    'committee member inst' => 2, 'college secretary' => 3] as $type => $expected) {
    $result = Scoring\KRA3Scorer::score([['remarks' => "$type|||Activity|||1|||", 'evidence_names' => 'evidence.pdf']]);
    checkScore(abs($result['subtotal'] - $expected) < 0.005, "$type aggregate is incorrect");
}

// Repair a stored zero inside a rollback-only transaction. Evidence and reviewed
// scores must survive, and a second pass must make no further changes.
$app = $pdo->query("SELECT a.application_id, a.user_id FROM applications a WHERE a.status='draft' LIMIT 1")->fetch();
if ($app) {
    $pdo->beginTransaction();
    try {
        $insert = $pdo->prepare("INSERT INTO kra_submissions (application_id,user_id,kra_category,computed_points,remarks,document_path)
            VALUES (?,?,'Professional Development',0,'B-degree|||Doctorate|||40','uploads/kra4/test-evidence.pdf')");
        $insert->execute([$app['application_id'], $app['user_id']]);
        $id = (int)$pdo->lastInsertId();
        Scoring\EntryScores::refreshDraft($pdo, (int)$app['application_id']);
        $saved = $pdo->query("SELECT computed_points,document_path FROM kra_submissions WHERE submission_id=$id")->fetch();
        checkScore((float)$saved['computed_points'] === 40.0, 'Stored zero was not repaired');
        checkScore($saved['document_path'] === 'uploads/kra4/test-evidence.pdf', 'Evidence changed during repair');
        checkScore(Scoring\EntryScores::refreshDraft($pdo, (int)$app['application_id']) === 0, 'Repair is not idempotent');
        $pdo->prepare('UPDATE kra_submissions SET computed_points=7,faculty_original_score=40 WHERE submission_id=?')->execute([$id]);
        Scoring\EntryScores::refreshDraft($pdo, (int)$app['application_id']);
        checkScore((float)$pdo->query("SELECT computed_points FROM kra_submissions WHERE submission_id=$id")->fetchColumn() === 7.0, 'Reviewed score was overwritten');
    } finally {
        $pdo->rollBack();
    }
}
$entry = static fn(string $remarks): array => ['remarks' => $remarks, 'evidence_names' => 'example.pdf'];
$instruction = Scoring\KRA1Scorer::class;
$research = Scoring\KRA2Scorer::class;
$extension = Scoring\KRA3Scorer::class;
$development = Scoring\KRA4Scorer::class;
foreach ([$instruction, $research, $extension, $development] as $scorer) {
    $scorer::setPdo($pdo);
    $scorer::setCriteriaContext(0, null);
}
$set = $entry('A-set-sem|||AY 2025-2026|||1|||100');
checkScore((float)$instruction::score([$set])['criterion_a'] === 6.0, 'Standard SET must use six semesters');
checkScore((float)$instruction::score([$set], 1)['criterion_a'] === 36.0, 'Approved SET divisor was ignored');
checkScore((float)$instruction::score([$entry('A-set-sef|||100|||100')])['criterion_a'] === 60.0, 'Combined rating was treated as a single semester');
checkScore((float)$instruction::score([$entry('C-mentor|||Contest|||regional|||1st')])['criterion_c'] === 1.0, 'Eligible mentorship did not score');
checkScore((float)$instruction::score([$entry('C-mentor|||Contest|||local|||1st')])['criterion_c'] === 0.0, 'Local mentorship earned points');

$researchEntry = static fn(string $label, string $percent, string $reference, string $flag = ''): array =>
    $entry(implode('|||', [$label, 'Author', 'University', 'Area', 'Details', $percent, $reference, $flag]));
$translated = $researchEntry('Criterion A - Research Output -> Project/Policy/Product, Lead (35pts)', '70', 'Policy');
checkScore($research::score([$translated])['criterion_a_raw'] === 24.5, 'Lead research contribution was not applied');
$localPatents = array_fill(0, 5, $researchEntry('Criterion B - Commercialized Patented Product, Local (5pts)', '100', 'P'));
checkScore($research::score($localPatents)['parent_scores'] === ['A' => 0.0, 'B' => 20.0, 'C' => 0.0], 'Commercialization was routed or capped incorrectly');
$software = $researchEntry('Criterion B - New Software Product, Co-Developer (10pts)', '50', 'S');
checkScore($research::score([$software])['criterion_b_raw'] === 5.0, 'Software moved to the wrong criterion or lost its contribution rate');
$design = $researchEntry('Criterion C - Juried or Peer-Reviewed Design (20pts)', '100', 'D');
checkScore($research::score([$design])['criterion_c_raw'] === 20.0, 'Juried design was not credited to Criterion C');
$stages = [
    $researchEntry('Criterion B - Invention Patent: Grant (80pts)', '100', 'P123'),
    $researchEntry('Criterion B - Invention Patent: Acceptance (10pts)', '100', 'p123'),
    $researchEntry('Criterion B - Invention Patent: Publication (20pts)', '100', 'P123'),
];
checkScore($research::score($stages)['criterion_b_raw'] === 80.0, 'Patent stages were added together');
$citation = 'Criterion A - International Citation (per citation, max 60 pts) (10pts)';
checkScore($research::score([
    $researchEntry($citation, '100', 'doi:10.1/abc'),
    $researchEntry($citation, '100', 'https://doi.org/10.1/ABC'),
    $researchEntry($citation, '100', '10.1/def', 'self-citation'),
])['criterion_a_raw'] === 10.0, 'Duplicate or self citations earned points');

checkScore($extension::score([$entry('income|||A|||7000000|||lead'), $entry('income|||B|||7000000|||lead')])['criterion_a'] === 18.0, 'Income was tiered per project');
checkScore($extension::score([$entry('resource-speaker-local|||Seminar|||0')])['criterion_b'] === 0.0, 'Zero delivery hours earned points');
checkScore($extension::score([$entry('dean|||Designation|||0.5')])['criterion_d_bonus'] === 0.0, 'Half-year administrative designation earned points');
checkScore($extension::score([
    $entry('outreach-isr-lead|||Project|||1'),
    $entry('csr-satisfaction|||Project|||90'),
    $entry('csr-satisfaction|||Project|||94'),
    $entry('csr-satisfaction|||Project|||92'),
])['criterion_c'] === 18.4, 'CSR did not average the ratings');

checkScore($development::score([$entry('B-training|||Virtual seminar|||1|||7|||virtual')])['criterion_b'] === 0.0, 'Short virtual training earned points');
checkScore($development::score([$entry('B-paper|||Conference|||5|||3')])['criterion_b'] === 5.0, 'Eligible international presentation lost points');
$presentations = [$entry('B-paper|||Local A|||3'), $entry('B-paper|||Local B|||3'), $entry('B-paper|||International|||5|||3')];
checkScore($development::score($presentations)['criterion_b_paper'] === 10.0
    && $development::score(array_reverse($presentations))['criterion_b_paper'] === 10.0, 'Presentation cap depended on entry order');
$activities = array_fill(0, 6, $entry('B-training|||International Training|||2|||6'));
checkScore($development::score($activities)['criterion_b_train'] === 10.0, 'Training did not honor the sub-cap');
checkScore($development::score([
    $entry('C-award|||International award|||5|||Event 2026'),
    $entry('C-award|||National award|||4|||Event 2026'),
])['criterion_c'] === 5.0, 'Multiple awards for one event were summed');
$prior = $entry('D-prior-industry|||Managerial|||0.5');
checkScore($development::score([$prior], true)['criterion_d_bonus'] === 2.0, 'Prior experience was not prorated');
checkScore($development::score([$prior], false)['criterion_d_bonus'] === 0.0, 'Prior experience scored for existing faculty');

$reviewed = ['computed_points' => 0, 'faculty_original_score' => 5, 'verified' => 1];
checkScore(kraEffectiveComputedPoints($reviewed + $entry('CUSTOM|||B|||custom_example|||Example|||5')) === 0.0,
    'The display ignored an evaluator reduction on a custom criterion');
checkScore((float)$instruction::score([$reviewed + $entry('A-set-sef-sem|||AY 2025-2026|||1|||100|||100')])['criterion_a'] === 0.0,
    'Instruction ignored an evaluator reduction on a combined semester record');
checkScore($instruction::score([$reviewed + $entry('B|10|Academic Program Dev/Revision, Lead')])['subtotal'] == 0, 'Instruction ignored an evaluator reduction to zero');
checkScore($research::score([$reviewed + $software])['subtotal'] === 0.0, 'Research ignored an evaluator reduction to zero');
checkScore($extension::score([$reviewed + $entry('moa-linkage|||Project|||1')])['subtotal'] === 0.0, 'Extension ignored an evaluator reduction to zero');
checkScore($development::score([$reviewed + $entry('A-org|||Organization|||1')])['subtotal'] === 0.0, 'Professional Development ignored an evaluator reduction to zero');

$context = $pdo->query("SELECT user_id, cycle_id, position_title FROM applications WHERE cycle_id IS NOT NULL LIMIT 1")->fetch();
if ($context) {
    $pdo->beginTransaction();
    try {
        $application = $pdo->prepare("INSERT INTO applications (user_id, cycle_id, position_title, status) VALUES (?, ?, ?, ?)");
        $application->execute([$context['user_id'], null, $context['position_title'], 'draft']);
        $testApp = (int)$pdo->lastInsertId();
        $submission = $pdo->prepare("INSERT INTO kra_submissions (application_id, user_id, kra_category, remarks, computed_points)
            VALUES (?, ?, 'Research', ?, 20)");
        $submission->execute([$testApp, $context['user_id'], $design['remarks']]);
        $testClaim = (int)$pdo->lastInsertId();
        $before = Scoring\Orchestrator::run($pdo, $testApp);
        $pdo->prepare("UPDATE kra_submissions SET computed_points = 0, faculty_original_score = 20, verified = 1 WHERE submission_id = ?")
            ->execute([$testClaim]);
        $after = Scoring\Orchestrator::run($pdo, $testApp);
        checkScore($before['kra_results']['kra2']['criterion_c_raw'] === 20.0 && $after['kra_results']['kra2']['criterion_c_raw'] === 0.0
            && $after['weighted_score'] < $before['weighted_score'], 'An evaluator change did not reach the official weighted score');

        $application->execute([$context['user_id'], null, $context['position_title'], 'approved']);
        $priorApp = (int)$pdo->lastInsertId();
        $pdo->prepare("INSERT INTO auto_sub_rank (application_id, doctorate_mode, doctorate_verified) VALUES (?, 'triggered', 'verified')")
            ->execute([$priorApp]);
        $eligibleApplication = ['application_id' => $testApp, 'faculty_user_id' => (int)$context['user_id'], 'current_rank' => 'Associate Professor V'];
        $history = Scoring\AutoSubrank::compute($pdo, $eligibleApplication, [], 0.0, 0, $doctorate);
        checkScore($history['doctorate_mode'] === 'blocked_historical' && $history['doctorate_points'] === 0.0, 'Previously used doctorate was credited again');
        $pdo->prepare("INSERT INTO auto_sub_rank (application_id, doctorate_mode, doctorate_verified) VALUES (?, 'points_only', 'verified')")
            ->execute([$testApp]);
        $additional = Scoring\AutoSubrank::compute($pdo, $eligibleApplication, [], 0.0, 0, $doctorate);
        checkScore($additional['doctorate_mode'] === 'points_only' && $additional['doctorate_points'] === 40.0, 'Evaluator-approved doctorate points did not apply');

        $calculator = new Scoring\AutoSubRankCalculator($pdo, $testApp, (int)$context['user_id']);
        $calculation = $calculator->calculate();
        $calculator->persist($calculation);
        $record = $pdo->query("SELECT doctorate_verified, doctorate_mode FROM auto_sub_rank WHERE application_id = $testApp")->fetch();
        checkScore($record['doctorate_verified'] === 'verified' && $record['doctorate_mode'] === $calculation['doctorate_mode'],
            'Auto Sub-Rank persistence lost the evaluator decision or failed to save the mode');

        $pdo->prepare("UPDATE applications SET status = 'approved', weighted_score = 77 WHERE application_id = ?")->execute([$testApp]);
        Scoring\Orchestrator::run($pdo, $testApp);
        checkScore((float)$pdo->query("SELECT weighted_score FROM applications WHERE application_id = $testApp")->fetchColumn() === 77.0,
            'Recalculation overwrote an approved application');
    } finally {
        $pdo->rollBack();
    }
}

echo count($criteria) . " configured criteria tested; $checks checks passed.\n";
