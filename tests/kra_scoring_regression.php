<?php
// Run: C:\xampp\php\php.exe tests/kra_scoring_regression.php
require_once __DIR__ . '/../includes/scoring/entry_scores.php';
require_once __DIR__ . '/../includes/apply/kra_entry_matching.php';

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
        $parts = [$pd['type'], 'Activity', $pd['sub'] ?: '1'];
    } else {
        $parts = ['CUSTOM', $letter, $key, $row['criterion_label'], (string)$expected, 'Activity'];
    }
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
echo count($criteria) . " configured criteria tested; $checks checks passed.\n";
