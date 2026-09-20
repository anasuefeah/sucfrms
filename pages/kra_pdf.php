<?php
/**
 * CHED KRA PDF Generator - Official Template Compliance
 * Generates the "SE KRA [N] - Summary of Points" faculty evaluation report,
 * matching the official CHED Faculty Position Reclassification for SUCs template.
 */

ob_start();
if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__ . '/../includes/functions.php';
requireLogin();
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/kra_pdf_render.php';
require_once __DIR__ . '/../includes/scoring/orchestrator.php';

// ═══════════════════════════════════════════════════════════════════════════════
// REQUEST HANDLING & DATA RETRIEVAL
// ═══════════════════════════════════════════════════════════════════════════════

// Target user determination
$target_uid = $_SESSION['user_id'];
if ((isAdmin() || isChecker()) && !empty($_GET['uid'])) {
    $target_uid = intval($_GET['uid']);
}

// Faculty data retrieval
$faculty_stmt = $pdo->prepare("
    SELECT u.*, c.campus_name
    FROM users u
    LEFT JOIN campuses c ON u.campus_id = c.campus_id
    WHERE u.user_id = ?
");
$faculty_stmt->execute([$target_uid]);
$faculty = $faculty_stmt->fetch();
if (!$faculty) die('Faculty not found.');

// Cycle data retrieval
$cycle_id = intval($_GET['cycle_id'] ?? 0);
if ($cycle_id) {
    $cs = $pdo->prepare("SELECT * FROM cycles WHERE cycle_id=?");
    $cs->execute([$cycle_id]);
    $cycle = $cs->fetch();
} else {
    $cycle = getActiveCycle($pdo);
}
if (!$cycle) die('No cycle found.');

// Application data retrieval
$app_stmt = $pdo->prepare("SELECT * FROM applications WHERE user_id=? AND cycle_id=? LIMIT 1");
$app_stmt->execute([$target_uid, $cycle['cycle_id']]);
$app = $app_stmt->fetch();
if (!$app) die('No application found.');
$app_id = $app['application_id'];

// KRA filtering logic — accepts either the roman numeral (I, II, III, IV)
// or the underlying category name (Instruction, Research, Extension, Professional Development),
// since different pages in the app link with either form.
$kra_param = $_GET['kra'] ?? 'all';
$valid_kras = ['I', 'II', 'III', 'IV'];
$category_to_numeral = [
    'Instruction' => 'I',
    'Research' => 'II',
    'Extension' => 'III',
    'Professional Development' => 'IV',
];

if (isset($category_to_numeral[$kra_param])) {
    $kra_param = $category_to_numeral[$kra_param];
}

// Determine which KRAs to generate
if ($kra_param === 'all' || !in_array($kra_param, $valid_kras)) {
    $kras_to_print = $valid_kras;
} else {
    $kras_to_print = [$kra_param];
}

// Retrieve all KRA submissions
$subs_raw = $pdo->prepare("SELECT * FROM kra_submissions WHERE application_id=? ORDER BY kra_category, submitted_at ASC");
$subs_raw->execute([$app_id]);
$all_subs = $subs_raw->fetchAll();

// Group submissions by category
$subs_by_cat = [];
foreach ($all_subs as $s) {
    $subs_by_cat[$s['kra_category']][] = $s;
}

// ═══════════════════════════════════════════════════════════════════════════════
// PDF GENERATION
// ═══════════════════════════════════════════════════════════════════════════════

// Rank-weighted scoring / reclassification summary — only for the full,
// all-KRA report, and always computed fresh (never cached) so it reflects
// the latest scores, Auto Sub Rank status, and rank at the moment of export.
$iss = null;
if (count($kras_to_print) > 1) {
    try {
        $orch_result = \Scoring\Orchestrator::run($pdo, $app_id);
        if (empty($orch_result['error'])) {
            $iss = $orch_result['iss'];
        }
    } catch (\Throwable $e) {
        // Don't let a scoring-pipeline issue block the rest of the PDF from
        // generating — the per-KRA breakdown pages are still useful on their
        // own even if the summary page can't be computed this time.
        $iss = null;
    }
}

try {
    $pdf = renderKraPdf($faculty, $subs_by_cat, $kras_to_print, 'Carlos Hilado Memorial State University', $iss);

    ob_end_clean();

    $kra_part = (count($kras_to_print) === 1) ? 'KRA_' . $kras_to_print[0] : 'KRA_All';
    $faculty_part = preg_replace('/[^A-Za-z0-9_]/', '_', $faculty['full_name'] ?? 'Faculty');
    $date_part = date('Ymd');
    $filename = "{$kra_part}_{$faculty_part}_{$date_part}.pdf";

    $pdf->Output('I', $filename);

} catch (Exception $e) {
    ob_end_clean();
    http_response_code(500);
    echo "Error generating PDF: " . $e->getMessage();
}
