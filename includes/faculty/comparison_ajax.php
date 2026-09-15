<?php
/**
 * comparison_ajax.php
 * Returns live comparison data for the score comparison table.
 * Called by faculty's my_application page via polling.
 *
 * GET  ?app_id=N   → JSON comparison payload
 */
if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/functions.php';

// Send JSON content type immediately — any error below will still be caught
header('Content-Type: application/json');

// Catch all errors and return JSON so the poller doesn't hang
set_error_handler(function($errno, $errstr) {
    echo json_encode(['ok' => false, 'error' => "PHP error: $errstr"]);
    exit;
});
set_exception_handler(function($e) {
    echo json_encode(['ok' => false, 'error' => 'Exception: ' . $e->getMessage()]);
    exit;
});

// Runtime migrations
try { $pdo->query("SELECT faculty_original_score FROM kra_submissions LIMIT 1"); }
catch (\Exception $e) {
    try {
        $pdo->exec("ALTER TABLE kra_submissions ADD COLUMN faculty_original_score DECIMAL(6,2) DEFAULT NULL AFTER computed_points");
        $pdo->exec("UPDATE kra_submissions SET faculty_original_score = computed_points WHERE faculty_original_score IS NULL");
    } catch (\Exception $e2) {}
}
try { $pdo->query("SELECT revision_status FROM kra_submissions LIMIT 1"); }
catch (\Exception $e) {
    try {
        $pdo->exec("ALTER TABLE kra_submissions
            ADD COLUMN revision_status ENUM('ok','needs_revision') DEFAULT 'ok',
            ADD COLUMN revision_note TEXT DEFAULT NULL,
            ADD COLUMN revision_by INT DEFAULT NULL,
            ADD COLUMN revision_at TIMESTAMP NULL");
    } catch (\Exception $e2) {}
}

if (!isLoggedIn() || (!isFaculty() && !isAdmin())) {
    echo json_encode(['ok' => false, 'error' => 'Unauthorized']);
    exit;
}

$app_id = intval($_GET['app_id'] ?? 0);
if (!$app_id) {
    echo json_encode(['ok' => false, 'error' => 'Missing app_id']);
    exit;
}

$uid = $_SESSION['user_id'];

// Load application — faculty can only see their own; admin can see any
$app_stmt = $pdo->prepare("
    SELECT a.application_id, a.status, a.weighted_score, a.total_score,
           a.sub_rank_increment, a.potential_rank, u.rank AS faculty_rank,
           u.full_name
    FROM applications a
    JOIN users u ON a.user_id = u.user_id
    WHERE a.application_id = ?
    " . (!isAdmin() ? "AND a.user_id = {$uid}" : "") . "
    LIMIT 1
");
$app_stmt->execute([$app_id]);
$app = $app_stmt->fetch(PDO::FETCH_ASSOC);

if (!$app) {
    echo json_encode(['ok' => false, 'error' => 'Application not found']);
    exit;
}

// Load all KRA submissions for this application
$subs_stmt = $pdo->prepare("
    SELECT submission_id, kra_category,
           computed_points,
           COALESCE(faculty_original_score, computed_points) AS faculty_original_score,
           verified, verified_by, revision_status, revision_note,
           remarks
    FROM kra_submissions
    WHERE application_id = ?
    ORDER BY FIELD(kra_category,'Instruction','Research','Extension','Professional Development'),
             submission_id ASC
");
try {
    $subs_stmt->execute([$app_id]);
    $subs = $subs_stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (\Exception $e) {
    // faculty_original_score column might not exist yet — fall back without it
    $subs_stmt2 = $pdo->prepare("
        SELECT submission_id, kra_category,
               computed_points,
               computed_points AS faculty_original_score,
               verified, verified_by, revision_status, revision_note,
               remarks
        FROM kra_submissions
        WHERE application_id = ?
        ORDER BY FIELD(kra_category,'Instruction','Research','Extension','Professional Development'),
                 submission_id ASC
    ");
    $subs_stmt2->execute([$app_id]);
    $subs = $subs_stmt2->fetchAll(PDO::FETCH_ASSOC);
}

// Build per-submission checker info — who verified and what the adjusted value is
// verified_by → checker name
$checker_names = [];
$vby_ids = array_filter(array_unique(array_column($subs, 'verified_by')));
if (!empty($vby_ids)) {
    $ph = implode(',', array_fill(0, count($vby_ids), '?'));
    $cn = $pdo->prepare("SELECT user_id, full_name, first_name, middle_name, last_name, role FROM users WHERE user_id IN ({$ph})");
    $cn->execute(array_values($vby_ids));
    foreach ($cn->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $checker_names[$r['user_id']] = ['name' => formatDisplayName($r), 'role' => $r['role']];
    }
}

// Load all checker decisions for stage labels
$reviews = [];
try {
    $reviews_stmt = $pdo->prepare("
        SELECT r.checker_id, r.decision, r.remarks, r.decided_at, u.full_name, u.first_name, u.middle_name, u.last_name, u.role
        FROM application_checker_reviews r
        JOIN users u ON r.checker_id = u.user_id
        WHERE r.application_id = ?
        ORDER BY r.created_at ASC
    ");
    $reviews_stmt->execute([$app_id]);
    $reviews = $reviews_stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (\Exception $e) {
    // application_checker_reviews table may not exist yet — no reviews yet
    $reviews = [];
}

$stage1_reviews = array_values(array_filter($reviews, fn($r) => in_array($r['role'], ['checker','checker_faculty'])));
$stage2_reviews = array_values(array_filter($reviews, fn($r) => $r['role'] === 'talisay_checker'));

// For each submission, determine stage (stage1 = campus checker, stage2 = talisay)
// Use verified_by role to classify the adjustment stage
$submissions = [];
foreach ($subs as $s) {
    $vby_id   = $s['verified_by'] ? (int)$s['verified_by'] : null;
    $vby_info = $vby_id ? ($checker_names[$vby_id] ?? null) : null;

    $checker_adjusted  = ($vby_id !== null) && ((float)$s['computed_points'] !== (float)$s['faculty_original_score']);
    $is_stage2_checker = $vby_info && $vby_info['role'] === 'talisay_checker';

    $submissions[] = [
        'submission_id'          => (int)$s['submission_id'],
        'kra_category'           => $s['kra_category'],
        'faculty_score'          => (float)$s['faculty_original_score'],
        'current_score'          => (float)$s['computed_points'],
        'checker_adjusted'       => $checker_adjusted,
        'adjusted_by'            => $vby_info ? $vby_info['name'] : null,
        'adjusted_stage'         => $checker_adjusted ? ($is_stage2_checker ? 'stage2' : 'stage1') : null,
        'verified'               => (bool)$s['verified'],
        'revision_status'        => $s['revision_status'],
        'revision_note'          => $s['revision_note'],
        'remarks'                => $s['remarks'],
    ];
}

// Compute weighted scores
$kra_caps = ['Instruction' => 100, 'Research' => 100, 'Extension' => 100, 'Professional Development' => 100];
$faculty_raw = [];
$checker_raw = [];
$stage2_raw  = [];

foreach ($submissions as $s) {
    $cat = $s['kra_category'];
    $faculty_raw[$cat] = ($faculty_raw[$cat] ?? 0) + $s['faculty_score'];
    // Use current_score for stage1 if adjusted by stage1, else faculty_score
    if ($s['adjusted_stage'] === 'stage2') {
        $checker_raw[$cat] = ($checker_raw[$cat] ?? 0) + $s['faculty_score']; // stage1 unchanged
        $stage2_raw[$cat]  = ($stage2_raw[$cat]  ?? 0) + $s['current_score'];
    } else {
        $checker_raw[$cat] = ($checker_raw[$cat] ?? 0) + $s['current_score'];
        $stage2_raw[$cat]  = ($stage2_raw[$cat]  ?? 0) + $s['current_score'];
    }
}
// Cap each KRA
foreach ($kra_caps as $cat => $cap) {
    $faculty_raw[$cat] = min($cap, $faculty_raw[$cat] ?? 0);
    $checker_raw[$cat] = min($cap, $checker_raw[$cat] ?? 0);
    $stage2_raw[$cat]  = min($cap, $stage2_raw[$cat]  ?? 0);
}

$faculty_rank    = $app['faculty_rank'] ?? '';
$ws_faculty      = computeWeightedScore($faculty_raw, $faculty_rank)['weighted_score'];
$ws_stage1       = computeWeightedScore($checker_raw, $faculty_rank)['weighted_score'];
$ws_stage2       = !empty($stage2_reviews) ? computeWeightedScore($stage2_raw, $faculty_rank)['weighted_score'] : null;

$has_stage2      = !empty($stage2_reviews);
$has_stage1      = !empty($stage1_reviews);

// Build checker summary for display
$stage1_info = array_map(fn($r) => [
    'name'     => formatDisplayName($r),
    'decision' => $r['decision'],
    'remarks'  => $r['remarks'],
], $stage1_reviews);

$stage2_info = array_map(fn($r) => [
    'name'     => formatDisplayName($r),
    'decision' => $r['decision'],
    'remarks'  => $r['remarks'],
], $stage2_reviews);

echo json_encode([
    'ok'           => true,
    'app_id'       => (int)$app_id,
    'status'       => $app['status'],
    'submissions'  => $submissions,
    'has_stage1'   => $has_stage1,
    'has_stage2'   => $has_stage2,
    'stage1_info'  => $stage1_info,
    'stage2_info'  => $stage2_info,
    'ws_faculty'   => $ws_faculty,
    'ws_stage1'    => $ws_stage1,
    'ws_stage2'    => $ws_stage2,
    'potential_rank' => $app['potential_rank'],
    'sub_rank_increment' => (int)$app['sub_rank_increment'],
    'updated_at'   => date('H:i:s'),
]);
