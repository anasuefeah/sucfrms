<?php
// Request handling and data loading for frontend/views/faculty/my_application.php
$uid   = $_SESSION['user_id'];
$cycle = getActiveCycle($pdo);

// -- Runtime migration: create kra_evidence_files if missing --
try { $pdo->query("SELECT evidence_id FROM kra_evidence_files LIMIT 1"); }
catch (\Exception $e) {
    $pdo->exec("CREATE TABLE IF NOT EXISTS kra_evidence_files (
        evidence_id       INT AUTO_INCREMENT PRIMARY KEY,
        submission_id     INT NOT NULL,
        file_path         VARCHAR(255) NOT NULL,
        original_filename VARCHAR(255) NOT NULL,
        file_size_bytes   INT DEFAULT 0,
        uploaded_at       TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        uploaded_by       INT DEFAULT NULL,
        FOREIGN KEY (submission_id) REFERENCES kra_submissions(submission_id) ON DELETE CASCADE,
        FOREIGN KEY (uploaded_by)   REFERENCES users(user_id) ON DELETE SET NULL
    )");
}
// -- Runtime migration: add revision columns to kra_submissions --
try { $pdo->query("SELECT revision_status FROM kra_submissions LIMIT 1"); }
catch (\Exception $e) {
    $pdo->exec("ALTER TABLE kra_submissions
        ADD COLUMN revision_status ENUM('ok','needs_revision') DEFAULT 'ok' AFTER verified,
        ADD COLUMN revision_note TEXT DEFAULT NULL AFTER revision_status,
        ADD COLUMN revision_by INT DEFAULT NULL AFTER revision_note,
        ADD COLUMN revision_at TIMESTAMP NULL AFTER revision_by
    ");
}

if (!$cycle) {
    // Show the most recently closed cycle for reference
    $last_cycle = $pdo->query("SELECT * FROM cycles ORDER BY created_at DESC LIMIT 1")->fetch();
    echo '<div class="neon-card text-center py-12">
        <i class="bi bi-calendar-x fs-1 text-secondary mb-4 d-block"></i>
        <h5 style="color:#1a3a6b;font-weight:700;margin-bottom:0.5rem;">No Active Reclassification Cycle</h5>
        <p class="text-muted mb-1">There is currently no open cycle. Your account is ready.</p>
        <p class="text-muted small">When the next cycle opens, your application will be available here automatically.</p>'
        . ($last_cycle ? '<p class="text-muted small mt-2">Last cycle: <strong>' . htmlspecialchars($last_cycle['cycle_name']) . '</strong> (' . ucfirst($last_cycle['status']) . ')</p>' : '')
        . '</div>';
    return false;
}
ensureAppealTables($pdo);

$app = getOrCreateApplication($pdo, $uid, $cycle['cycle_id']);
$app_id = $app['application_id'];
// Support both the current code name and the legacy database column name.
$app['checker_remarks'] = $app['checker_remarks'] ?? $app['evaluator_remarks'] ?? null;

// Lock once evaluator work is complete.
$can_edit = !in_array($app['status'], ['approved', 'reclassified', 'admin_rejected']);

// All KRA submissions for this application
$subs = $pdo->prepare("SELECT * FROM kra_submissions WHERE application_id = ? ORDER BY submitted_at DESC");
$subs->execute([$app_id]);
$subs = $subs->fetchAll();

// Attach evidence files to each submission
foreach ($subs as &$sub) {
    $ef = $pdo->prepare("SELECT evidence_id, file_path, original_filename FROM kra_evidence_files WHERE submission_id=? ORDER BY uploaded_at ASC");
    $ef->execute([$sub['submission_id']]);
    $sub['evidence_files'] = $ef->fetchAll(PDO::FETCH_ASSOC);
    if (empty($sub['evidence_files']) && $sub['document_path']) {
        $sub['evidence_files'] = [['evidence_id'=>0,'file_path'=>$sub['document_path'],'original_filename'=>basename($sub['document_path'])]];
    }
}
unset($sub);

$appeals_by_submission = [];
if ($subs) {
    $sub_ids = array_map('intval', array_column($subs, 'submission_id'));
    $ph = implode(',', array_fill(0, count($sub_ids), '?'));
    $ap_stmt = $pdo->prepare("SELECT * FROM appeals WHERE submission_id IN ($ph) ORDER BY created_at DESC");
    $ap_stmt->execute($sub_ids);
    foreach ($ap_stmt->fetchAll() as $ap) {
        $sid = (int)$ap['submission_id'];
        if (!isset($appeals_by_submission[$sid])) $appeals_by_submission[$sid] = [];
        $appeals_by_submission[$sid][] = $ap;
    }
}
$appeals_allowed = ($cycle['status'] ?? '') === 'open'
    && (empty($cycle['appeal_deadline']) || time() <= strtotime($cycle['appeal_deadline'] . ' 23:59:59'));

// Fetch faculty rank from users table (not in $app which is from applications)
$faculty_rank_row = $pdo->prepare("SELECT rank FROM users WHERE user_id=?");
$faculty_rank_row->execute([$uid]);
$faculty_rank_row = $faculty_rank_row->fetch();
$faculty_current_rank = $faculty_rank_row['rank'] ?? '';
$score_summary_faculty = getApplicationScoreSummary($pdo, (int)$app_id);
$raw_kra_faculty       = array_map(fn($d) => $d['pts'], $score_summary_faculty['kra_map']);
$potential_data_faculty = [
    'potential_rank'   => $score_summary_faculty['potential_rank'] ?: ' - ',
    'flags'            => [],
    'crossed_category' => false,
    'recomputed_score' => $score_summary_faculty['weighted_score'],
];

// Pre-compute weighted scores per KRA category for the table display
$score_result_fac = $score_summary_faculty;
$kra_caps_fac     = ['Instruction'=>100,'Research'=>100,'Extension'=>120,'Professional Development'=>120];
$kra_weights_fac  = $score_result_fac['weights'];
// Sum raw per category for proportional distribution per entry
$kra_raw_totals_fac = [];
foreach ($subs as $s) {
    $kra_raw_totals_fac[$s['kra_category']] = ($kra_raw_totals_fac[$s['kra_category']] ?? 0) + (float)$s['computed_points'];
}

// KRA tab mapping
$kra_tabs = [
    'Instruction'              => 'instruction',
    'Research'                 => 'research',
    'Extension'                => 'extension',
    'Professional Development' => 'profdev',
];
