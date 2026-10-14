<?php
// Request handling and data loading for frontend/views/faculty/kra_entry.php
$active_tab = $_GET['tab'] ?? 'instruction';
$valid_tabs = ['instruction','research','extension','profdev','autosubrank','posreq'];
if (!in_array($active_tab, $valid_tabs)) $active_tab = 'instruction';

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

// Runtime migration: add faculty_original_score to kra_submissions if missing
try { $pdo->query("SELECT faculty_original_score FROM kra_submissions LIMIT 1"); }
catch (\Exception $e) {
    $pdo->exec("ALTER TABLE kra_submissions ADD COLUMN faculty_original_score DECIMAL(6,2) DEFAULT NULL AFTER computed_points");
    $pdo->exec("UPDATE kra_submissions SET faculty_original_score = computed_points WHERE faculty_original_score IS NULL");
}

// ..."......"... Runtime migration: create auto_sub_rank table if missing ..."......"...
try { $pdo->query("SELECT auto_rank_id FROM auto_sub_rank LIMIT 1"); }
catch (\Exception $e) {
    $pdo->exec("CREATE TABLE IF NOT EXISTS auto_sub_rank (
        auto_rank_id      INT AUTO_INCREMENT PRIMARY KEY,
        application_id    INT NOT NULL,
        doctorate_status  ENUM('Triggered','Not Triggered','Needs Review') DEFAULT 'Needs Review',
        award_status      ENUM('Triggered','Not Triggered','Needs Review') DEFAULT 'Needs Review',
        doctorate_details TEXT DEFAULT NULL,
        award_details     TEXT DEFAULT NULL,
        award_evidence    VARCHAR(255) DEFAULT NULL,
        created_at        TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at        TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        FOREIGN KEY (application_id) REFERENCES applications(application_id) ON DELETE CASCADE
    )");
}

// ..."......"... Runtime migration: create position_requirements table if missing ..."......"...
try { $pdo->query("SELECT pos_req_id FROM position_requirements LIMIT 1"); }
catch (\Exception $e) {
    $pdo->exec("CREATE TABLE IF NOT EXISTS position_requirements (
        pos_req_id        INT AUTO_INCREMENT PRIMARY KEY,
        application_id    INT NOT NULL,
        cav_transcript    ENUM('Uploaded','Missing') DEFAULT 'Missing',
        cav_file          VARCHAR(255) DEFAULT NULL,
        international_article ENUM('Uploaded','Missing') DEFAULT 'Missing',
        article_file      VARCHAR(255) DEFAULT NULL,
        certification_form ENUM('Uploaded','Missing') DEFAULT 'Missing',
        cert_file         VARCHAR(255) DEFAULT NULL,
        created_at        TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at        TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        FOREIGN KEY (application_id) REFERENCES applications(application_id) ON DELETE CASCADE
    )");
}

function kraApplyEffectiveComputedPoints(array $submission): float {
    $points = (float)($submission['computed_points'] ?? 0);
    $parts = array_map('trim', explode('|||', (string)($submission['remarks'] ?? '')));
    if (($parts[0] ?? '') === 'CUSTOM') {
        return max($points, max(0.0, (float)($parts[4] ?? 0)));
    }
    return $points;
}

function step2OfficialKraTotals(PDO $pdo, int $application_id): array {
    $scoring_dir = __DIR__ . '/../../services/scoring/';
    foreach (['kra1_scorer.php','kra2_scorer.php','kra3_scorer.php','kra4_scorer.php'] as $f) {
        if (file_exists($scoring_dir . $f)) require_once $scoring_dir . $f;
    }
    if (class_exists('\Scoring\KRA1Scorer')) {
        $ctx = $pdo->prepare("
            SELECT a.cycle_id, COALESCE(a.position_title, u.rank) AS position_rank
            FROM applications a
            JOIN users u ON u.user_id = a.user_id
            WHERE a.application_id = ?
            LIMIT 1
        ");
        $ctx->execute([$application_id]);
        $ctx_row = $ctx->fetch(PDO::FETCH_ASSOC) ?: [];
        \Scoring\KRA1Scorer::setPdo($pdo);
        \Scoring\KRA1Scorer::setCriteriaContext((int)($ctx_row['cycle_id'] ?? 0), $ctx_row['position_rank'] ?? null);
        if (class_exists('\Scoring\KRA2Scorer')) {
            \Scoring\KRA2Scorer::setPdo($pdo);
            \Scoring\KRA2Scorer::setCriteriaContext((int)($ctx_row['cycle_id'] ?? 0), $ctx_row['position_rank'] ?? null);
        }
        if (class_exists('\Scoring\KRA3Scorer')) {
            \Scoring\KRA3Scorer::setPdo($pdo);
            \Scoring\KRA3Scorer::setCriteriaContext((int)($ctx_row['cycle_id'] ?? 0), $ctx_row['position_rank'] ?? null);
        }
        if (class_exists('\Scoring\KRA4Scorer')) {
            \Scoring\KRA4Scorer::setPdo($pdo);
            \Scoring\KRA4Scorer::setCriteriaContext((int)($ctx_row['cycle_id'] ?? 0), $ctx_row['position_rank'] ?? null);
        }
    }

    $stmt = $pdo->prepare("
        SELECT ks.*,
               GROUP_CONCAT(kef.original_filename ORDER BY kef.evidence_id SEPARATOR '|||') AS evidence_names
        FROM kra_submissions ks
        LEFT JOIN kra_evidence_files kef ON kef.submission_id = ks.submission_id
        WHERE ks.application_id = ?
        GROUP BY ks.submission_id
        ORDER BY ks.submitted_at ASC
    ");
    $stmt->execute([$application_id]);

    $by_cat = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $by_cat[$row['kra_category']][] = $row;
    }

    $fallback = fn($cat) => min(100, array_sum(array_map(
        fn($s) => kraApplyEffectiveComputedPoints($s),
        $by_cat[$cat] ?? []
    )));

    return [
        'Instruction' => class_exists('\Scoring\KRA1Scorer')
            ? (float)(\Scoring\KRA1Scorer::score($by_cat['Instruction'] ?? [])['subtotal'] ?? 0)
            : $fallback('Instruction'),
        'Research' => class_exists('\Scoring\KRA2Scorer')
            ? (float)(\Scoring\KRA2Scorer::score($by_cat['Research'] ?? [])['subtotal'] ?? 0)
            : $fallback('Research'),
        'Extension' => class_exists('\Scoring\KRA3Scorer')
            ? (float)(\Scoring\KRA3Scorer::score($by_cat['Extension'] ?? [])['subtotal'] ?? 0)
            : $fallback('Extension'),
        'Professional Development' => class_exists('\Scoring\KRA4Scorer')
            ? (float)(\Scoring\KRA4Scorer::score($by_cat['Professional Development'] ?? [])['subtotal'] ?? 0)
            : $fallback('Professional Development'),
    ];
}

// -- AJAX: return entries as JSON ------------------------------
if (isset($_GET['ajax_entries'])) {
    $cat = $_GET['cat'] ?? '';
    $valid_cats = ['Instruction','Research','Extension','Professional Development'];
    if (in_array($cat, $valid_cats)) {
        $rows = $pdo->prepare("SELECT submission_id, computed_points, remarks, document_path FROM kra_submissions WHERE application_id=? AND kra_category=? ORDER BY submitted_at ASC");
        $rows->execute([$app_id, $cat]);
        $entries = $rows->fetchAll(PDO::FETCH_ASSOC);
        foreach ($entries as &$entry) {
            $entry['computed_points'] = kraApplyEffectiveComputedPoints($entry);
            $ef = $pdo->prepare("SELECT evidence_id, file_path, original_filename, file_size_bytes FROM kra_evidence_files WHERE submission_id=? ORDER BY uploaded_at ASC");
            $ef->execute([$entry['submission_id']]);
            $entry['evidence_files'] = $ef->fetchAll(PDO::FETCH_ASSOC);
        }
        unset($entry);
        header('Content-Type: application/json');
        echo json_encode(['entries' => $entries]);
        exit;
    }
}

// -- AJAX: return current weighted score for sidebar -----------
if (isset($_GET['ajax_score'])) {
    $totals_ajax = step2OfficialKraTotals($pdo, $app_id);
    $score_ajax = getApplicationScoreSummary($pdo, (int)$app_id);
    $weights_ajax = $score_ajax['weights'];
    $kra_weighted_ajax = [
        'Instruction'              => round($score_ajax['kra1'] * $weights_ajax['Instruction'], 2),
        'Research'                 => round($score_ajax['kra2'] * $weights_ajax['Research'], 2),
        'Extension'                => round($score_ajax['kra3'] * $weights_ajax['Extension'], 2),
        'Professional Development' => round($score_ajax['kra4'] * $weights_ajax['Professional Development'], 2),
    ];
    header('Content-Type: application/json');
    echo json_encode([
        'weighted'    => $score_ajax['weighted_score'],
        'sub_rank'    => $score_ajax['sub_rank_increment'],
        'kra'         => $kra_weighted_ajax,
        'kra_raw'     => $totals_ajax,
        'weights_pct' => array_map(fn($w) => round($w * 100), $weights_ajax),
    ]);
    exit;
}

// -- Handle POST -----------------------------------------------
// Editing rules:
//   - Faculty can add/edit entries freely as long as the submission deadline has NOT passed,
//     regardless of application status (draft, submitted, under_review, etc.)
//   - Once the deadline passes, no more edits or additions are allowed.
//   - Exceptions that are always blocked regardless of deadline:
//       * evaluation complete statuses -> fully locked
//       * needs_revision -> only revision-flagged entries (enforced per-entry below)
$_effective_deadline = getEffectiveSubmissionDeadline($pdo, (int)($cycle['cycle_id'] ?? 0), (int)$uid, $cycle['submission_deadline'] ?? null);
$_submission_start_ts = !empty($cycle['submission_start_date'])
    ? strtotime($cycle['submission_start_date'] . ' 00:00:00')
    : null;
$_deadline_ts = !empty($_effective_deadline)
    ? strtotime($_effective_deadline . ' 23:59:59')
    : null;
$_submission_not_open = $_submission_start_ts !== null && time() < $_submission_start_ts;
$_deadline_passed = $_deadline_ts !== null && time() > $_deadline_ts;

// Statuses that are permanently locked regardless of deadline
$non_editable_statuses = ['approved', 'reclassified', 'admin_rejected'];

// can_edit = not permanently locked AND deadline has not passed
// Special case: needs_revision is still editable after deadline (checker returned it, must fix)
$can_edit = !$locked
    && !in_array($app['status'], $non_editable_statuses)
    && !$_submission_not_open
    && (!$_deadline_passed || in_array($app['status'], ['needs_revision', 'rejected']));

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $can_edit) {
    $kra_action = $_POST['kra_action'] ?? '';

    if ($kra_action === 'save_kra') {
        $category = $_POST['kra_category'] ?? '';
        $remarks  = trim($_POST['remarks'] ?? '');
        $edit_id  = intval($_POST['edit_submission_id'] ?? 0);

        $valid_cats = ['Instruction','Research','Extension','Professional Development'];
        if (in_array($category, $valid_cats)) {
            $remarks_parts = array_map('trim', explode('|||', $remarks));
            $remarks_type = $remarks_parts[0] ?? '';
            $meaningful_remarks = $remarks_type !== '';
            if ($meaningful_remarks && $category === 'Instruction') {
                if ($remarks_type === 'CUSTOM') {
                    $meaningful_remarks = ($remarks_parts[1] ?? '') !== '' && ($remarks_parts[2] ?? '') !== ''
                        && ($remarks_parts[3] ?? '') !== '' && (float)($remarks_parts[4] ?? 0) > 0;
                } elseif ($remarks_type === 'A-set-sef') {
                    $meaningful_remarks = ($remarks_parts[1] ?? '') !== '' && ($remarks_parts[2] ?? '') !== '';
                } elseif ($remarks_type === 'A-set-sef-sem') {
                    $meaningful_remarks = ($remarks_parts[3] ?? '') !== '' && ($remarks_parts[4] ?? '') !== '';
                } elseif ($remarks_type === 'A-set-sem' || $remarks_type === 'A-sef-sem') {
                    $meaningful_remarks = ($remarks_parts[1] ?? '') !== '' && ($remarks_parts[2] ?? '') !== '' && ($remarks_parts[3] ?? '') !== '';
                } else {
                    $meaningful_remarks = str_starts_with($remarks_type, 'B|') || str_starts_with($remarks_type, 'C|')
                        || in_array($remarks_type, ['B-material', 'C-thesis', 'C-mentor'], true);
                }
            } elseif ($meaningful_remarks && $category === 'Extension') {
                if ($remarks_type === 'CUSTOM') {
                    $meaningful_remarks = ($remarks_parts[1] ?? '') !== '' && ($remarks_parts[2] ?? '') !== ''
                        && ($remarks_parts[3] ?? '') !== '' && (float)($remarks_parts[4] ?? 0) > 0;
                } else {
                    $allowed_extension = [
                    'moa-linkage', 'income', 'accredit-local', 'accredit-intl',
                    'judge-research', 'judge-other', 'consultant-local', 'consultant-intl',
                    'media-column-regular', 'media-column-occasional', 'media-tv-radio-host', 'media-guest',
                    'resource-speaker-local', 'resource-speaker-intl', 'outreach-isr-lead', 'outreach-isr-member',
                    'csr-satisfaction', 'president', 'vice-president', 'chancellor', 'vice-chancellor',
                    'campus director', 'office director', 'dean', 'associate dean', 'dept head',
                    'program chair', 'committee chair', 'committee member', 'coordinator',
                    ];
                    $meaningful_remarks = in_array($remarks_type, $allowed_extension, true);
                }
            } elseif ($meaningful_remarks && in_array($category, ['Research','Professional Development'], true) && $remarks_type === 'CUSTOM') {
                $meaningful_remarks = ($remarks_parts[1] ?? '') !== '' && ($remarks_parts[2] ?? '') !== ''
                    && ($remarks_parts[3] ?? '') !== '' && (float)($remarks_parts[4] ?? 0) > 0;
            }
            if (!$meaningful_remarks) {
                if (isset($_POST['ajax']) || (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && $_SERVER['HTTP_X_REQUESTED_WITH'] === 'XMLHttpRequest')) {
                    while (ob_get_level()) ob_end_clean();
                    header('Content-Type: application/json');
                    echo json_encode(['ok' => false, 'error' => 'Please select a valid criterion and fill the required score fields before uploading or saving.']);
                    exit;
                }
                flashMessage('danger', 'Please select a valid criterion and fill the required score fields before saving.');
                echo "<script>window.location.href='index.php?page=apply&tab={$active_tab}';</script>"; exit;
            }

            // Server-side score recomputation via JC01 s.2026 scorer modules
            // Load scorers (kra_ajax.php now routes to them; we use the same entry point)
            if (!function_exists('computeKraScore')) {
                require_once __DIR__ . '/../../api/kra_ajax.php';
            }
            $points        = computeKraScore($category, $remarks);
            $client_points = max(0.0, (float)($_POST['computed_points'] ?? 0));

            // Fallback: if server returns 0 but client sent positive, trust client
            // (edge case: label format unrecognised by server-side pattern matching)
            if ($points <= 0 && $client_points > 0) {
                $points = $client_points;
            }
            if ($points <= 0 && $client_points <= 0) {
                error_log("SUCFRMS KRA SAVE: Both server and client score are 0. Category={$category}, Remarks=" . substr($remarks, 0, 200));
            }

            // -- Normalise uploaded files (single or multi) ------------------
            $uploaded_files = [];
            foreach (['evidence', 'evidence[]'] as $field) {
                if (!empty($_FILES[$field]['name'])) {
                    $names    = (array)$_FILES[$field]['name'];
                    $tmps     = (array)$_FILES[$field]['tmp_name'];
                    $sizes    = (array)$_FILES[$field]['size'];
                    $errors   = (array)$_FILES[$field]['error'];
                    foreach ($names as $i => $fname_raw) {
                        if (!empty($fname_raw) && ($errors[$i] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK) {
                            $uploaded_files[] = [
                                'name'     => $fname_raw,
                                'tmp_name' => $tmps[$i],
                                'size'     => $sizes[$i],
                            ];
                        }
                    }
                }
            }

            $kra_num   = array_search($category, $valid_cats) + 1;
            $folder    = __DIR__ . '/../../../uploads/kra' . $kra_num . '/';
            $rel_path  = 'uploads/kra' . $kra_num . '/';
            $allowed_exts = ['pdf','jpg','jpeg','png'];

            // Collect all successfully moved files to attach after save
            $files_to_insert = [];
            foreach ($uploaded_files as $uf) {
                $ext = strtolower(pathinfo($uf['name'], PATHINFO_EXTENSION));
                if (in_array($ext, $allowed_exts)) {
                    if (!is_dir($folder)) mkdir($folder, 0755, true);
                    $fname = uniqid('kra_') . '.' . $ext;
                    if (move_uploaded_file($uf['tmp_name'], $folder . $fname)) {
                        $files_to_insert[] = [
                            'path' => $rel_path . $fname,
                            'name' => $uf['name'],
                            'size' => $uf['size'],
                        ];
                    }
                }
            }

            // For backward compat: expose first file as $new_file_path etc.
            $new_file_path = $files_to_insert[0]['path'] ?? '';
            $new_file_name = $files_to_insert[0]['name'] ?? '';
            $new_file_size = $files_to_insert[0]['size'] ?? 0;

            if ($edit_id > 0) {
                $existing = $pdo->prepare("SELECT submission_id, revision_status FROM kra_submissions WHERE submission_id=? AND application_id=?");
                $existing->execute([$edit_id, $app_id]);
                $existing_row = $existing->fetch();
                // For needs_revision status: only allow editing entries flagged for revision
                $revision_edit_ok = ($app['status'] !== 'needs_revision')
                    || ($existing_row && $existing_row['revision_status'] === 'needs_revision');
                if ($existing_row && $revision_edit_ok) {
                    $pdo->prepare("UPDATE kra_submissions SET computed_points=?, remarks=?, verified=0, submitted_at=NOW() WHERE submission_id=?")
                        ->execute([$points, $remarks, $edit_id]);
                    $ins_ef = $pdo->prepare("INSERT INTO kra_evidence_files (submission_id, file_path, original_filename, file_size_bytes, uploaded_by) VALUES (?,?,?,?,?)");
                    foreach ($files_to_insert as $fi) {
                        $ins_ef->execute([$edit_id, $fi['path'], $fi['name'], $fi['size'], $uid]);
                    }
                }
            } else {
                $pdo->prepare("INSERT INTO kra_submissions (application_id, user_id, kra_category, computed_points, document_path, remarks) VALUES (?,?,?,?,?,?)")
                    ->execute([$app_id, $uid, $category, $points, '', $remarks]);
                $new_sid = (int)$pdo->lastInsertId();
                $ins_ef = $pdo->prepare("INSERT INTO kra_evidence_files (submission_id, file_path, original_filename, file_size_bytes, uploaded_by) VALUES (?,?,?,?,?)");
                foreach ($files_to_insert as $fi) {
                    $ins_ef->execute([$new_sid, $fi['path'], $fi['name'], $fi['size'], $uid]);
                }
            }

            recalcApplicationScore($pdo, $app_id);
            logAudit($pdo, $uid, 'KRA Saved', "{$category}: {$points} pts (client_sent: {$client_points}, remarks: " . substr($remarks,0,80) . ")");
        }
        // Return JSON for AJAX calls - check POST flag OR custom header
        if (isset($_POST['ajax']) || (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && $_SERVER['HTTP_X_REQUESTED_WITH'] === 'XMLHttpRequest')) {
            // Clear ALL output buffers
            while (ob_get_level()) ob_end_clean();
            header('Content-Type: application/json');
            // Return the actual saved score so client can update badge directly
            $saved_sid = ($edit_id > 0) ? $edit_id : (isset($new_sid) ? $new_sid : 0);
            echo json_encode(['ok' => true, 'message' => 'Entry saved', 'computed_points' => $points, 'submission_id' => $saved_sid]);
            exit;
        }
        flashMessage('success', sanitize($category) . " score saved.");
        echo "<script>window.location.href='index.php?page=apply&tab={$active_tab}';</script>"; exit;
    }

    if ($kra_action === 'delete_kra') {
        $del_id = intval($_POST['submission_id'] ?? 0);
        if ($del_id) {
            // For needs_revision: only allow deleting revision-flagged entries
            $del_check = $pdo->prepare("SELECT revision_status FROM kra_submissions WHERE submission_id=? AND application_id=?");
            $del_check->execute([$del_id, $app_id]);
            $del_row = $del_check->fetch();
            $del_allowed = $del_row && (
                $app['status'] !== 'needs_revision' ||
                $del_row['revision_status'] === 'needs_revision'
            );
            if (!$del_allowed) {
                flashMessage('error', 'This entry cannot be deleted in the current application status.');
                echo "<script>window.location.href='index.php?page=apply&tab={$active_tab}';</script>"; exit;
            }
            // Delete all evidence files for this submission
            $ef_rows = $pdo->prepare("SELECT file_path FROM kra_evidence_files WHERE submission_id=?");
            $ef_rows->execute([$del_id]);
            foreach ($ef_rows->fetchAll(PDO::FETCH_COLUMN) as $fp) {
                $abs = rtrim(str_replace('/', DIRECTORY_SEPARATOR, realpath(__DIR__ . '/../../../')), DIRECTORY_SEPARATOR)
                       . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $fp);
                if (file_exists($abs)) @unlink($abs);
            }
            $row = $pdo->prepare("SELECT document_path FROM kra_submissions WHERE submission_id=? AND application_id=?");
            $row->execute([$del_id, $app_id]);
            $row = $row->fetch();
            if ($row) {
                if ($row['document_path']) {
                    $abs_del = rtrim(str_replace('/', DIRECTORY_SEPARATOR, realpath(__DIR__ . '/../../../')), DIRECTORY_SEPARATOR)
                               . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $row['document_path']);
                    if (file_exists($abs_del)) @unlink($abs_del);
                }
                $pdo->prepare("DELETE FROM kra_submissions WHERE submission_id=?")->execute([$del_id]);
                recalcApplicationScore($pdo, $app_id);
                logAudit($pdo, $uid, 'KRA Entry Deleted', "Deleted submission #{$del_id}");
            }
        }
        if (isset($_POST['ajax']) || (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && $_SERVER['HTTP_X_REQUESTED_WITH'] === 'XMLHttpRequest')) {
            if (ob_get_level()) ob_clean();
            header('Content-Type: application/json');
            echo json_encode(['ok' => true]);
            exit;
        }
        echo "<script>window.location.href='index.php?page=apply&tab={$active_tab}';</script>"; exit;
    }

    if ($kra_action === 'save_auto_sub_rank') {
        // -- Auto Sub-Rank is now fully automatic  -  no manual save needed.
        // Recalculate and persist, then redirect back.
        if (!class_exists('\Scoring\AutoSubRankCalculator')) {
            require_once __DIR__ . '/../../services/scoring/autosubrank.php';
        }
        $calc   = new \Scoring\AutoSubRankCalculator($pdo, $app_id);
        $result = $calc->calculate();
        $calc->persist($result);
        logAudit($pdo, $uid, 'Auto Sub-Rank Recalculated',
            "Application #{$app_id}: doctorate={$result['doctorate_mode']}, award={$result['award_mode']}, score={$result['weighted_score_at_calc']}");
        flashMessage('success', 'Auto Sub-Rank assessed automatically based on your current score and entries.');
        echo "<script>window.location.href='index.php?page=apply&tab=autosubrank';</script>"; exit;
    }

    if ($kra_action === 'save_position_requirements') {
        // Handle file uploads for position requirements
        $files = ['cav_file' => 'cav_transcript', 'article_file' => 'international_article', 'cert_file' => 'certification_form'];
        $uploaded_files = [];
        
        foreach ($files as $file_field => $status_field) {
            if (!empty($_FILES[$file_field]['name']) && $_FILES[$file_field]['error'] === UPLOAD_ERR_OK) {
                $ext = strtolower(pathinfo($_FILES[$file_field]['name'], PATHINFO_EXTENSION));
                $allowed_exts = ['pdf','jpg','jpeg','png'];
                if (in_array($ext, $allowed_exts)) {
                    $folder = __DIR__ . '/../../../uploads/pos_req/';
                    if (!is_dir($folder)) mkdir($folder, 0755, true);
                    $fname = uniqid($file_field . '_') . '.' . $ext;
                    if (move_uploaded_file($_FILES[$file_field]['tmp_name'], $folder . $fname)) {
                        $uploaded_files[$file_field] = 'uploads/pos_req/' . $fname;
                        $uploaded_files[$status_field] = 'Uploaded';
                    }
                }
            }
        }
        
        // Build update query
        $updates = [];
        $params = [];
        foreach ($files as $file_field => $status_field) {
            if (isset($uploaded_files[$file_field])) {
                $updates[] = "$file_field = ?";
                $params[] = $uploaded_files[$file_field];
                $updates[] = "$status_field = ?"; 
                $params[] = 'Uploaded';
            }
        }
        
        if (!empty($updates)) {
            $params[] = $app_id;
            $stmt = $pdo->prepare("INSERT INTO position_requirements (application_id, " . implode(', ', array_keys($uploaded_files)) . ") 
                                  VALUES ($app_id, " . str_repeat('?,', count($uploaded_files)-1) . "?) 
                                  ON DUPLICATE KEY UPDATE " . implode(', ', $updates));
            $stmt->execute($params);
        }
        
        flashMessage('success', 'Position requirements updated.');
        echo "<script>window.location.href='index.php?page=apply&tab=posreq';</script>"; exit;
    }

    if ($kra_action === 'submit_application') {
        if ($_submission_not_open) {
            flashMessage('danger', 'The faculty submission period is not open yet.');
            echo "<script>window.location.href='index.php?page=apply&tab={$active_tab}';</script>"; exit;
        }
        // Block if deadline has passed
        if ($_deadline_passed) {
            flashMessage('danger', 'The submission deadline has passed. You can no longer submit for this cycle.');
            echo "<script>window.location.href='index.php?page=apply&tab={$active_tab}';</script>"; exit;
        }

        try { recalcApplicationScore($pdo, $app_id); } catch (\Exception $e) {}

        // Block if weighted score is below 41
        $faculty_rank_check = $faculty['rank'] ?? '';
        $score_check = getApplicationScoreSummary($pdo, (int)$app_id);
        if ($score_check['weighted_score'] < 41) {
            flashMessage('danger', 'Your weighted score (' . number_format($score_check['weighted_score'], 2) . ') is below the minimum of <strong>41.00</strong> required for reclassification. Improve your KRA scores before submitting.');
            echo "<script>window.location.href='index.php?page=apply&tab={$active_tab}';</script>"; exit;
        }
        // If checker already assigned, go straight back to under_review
        $new_status = !empty($app['checker_id']) ? 'under_review' : 'submitted';
        $pdo->prepare("UPDATE applications SET status=?, submitted_at=NOW() WHERE application_id=?")
            ->execute([$new_status, $app_id]);
        // Stamp faculty_original_score on every submission so checker edits can be diffed
        $pdo->prepare("UPDATE kra_submissions
            SET faculty_original_score = computed_points
            WHERE application_id = ? AND faculty_original_score IS NULL")
            ->execute([$app_id]);
        logAudit($pdo, $uid, 'Application Submitted', "Application {$app_id} submitted. Status: {$new_status}");
        // Notify all active Subcommittee reviewers
        $campus_checkers = $pdo->query("SELECT user_id FROM users WHERE role = 'evaluator' AND status='active'")->fetchAll(PDO::FETCH_COLUMN);
        $faculty_name = $_SESSION['full_name'] ?? "Faculty #{$uid}";
        foreach ($campus_checkers as $cid) {
            createNotif($pdo, (int)$cid, 'new_submission',
                "New application submitted by {$faculty_name} ... awaiting your review.",
                $app_id);
        }
        $msg = $new_status === 'under_review'
            ? 'Application resubmitted. Your evaluator will continue the review.'
            : 'Application submitted successfully for review.';
        flashMessage('success', $msg);
        echo "<script>window.location.href='index.php?page=dashboard';</script>"; exit;
    }
}

// -- Load data -------------------------------------------------
$subs_raw2 = $pdo->prepare("SELECT * FROM kra_submissions WHERE application_id=? ORDER BY submitted_at ASC");
$subs_raw2->execute([$app_id]);
$all_subs2 = $subs_raw2->fetchAll();

$subs_by_cat = [];
foreach ($all_subs2 as $s) $subs_by_cat[$s['kra_category']][] = $s;

$kra_totals2 = step2OfficialKraTotals($pdo, $app_id);
$grand2 = array_sum($kra_totals2);

$tabs = [
    'instruction' => ['label'=>'Instruction',            'cat'=>'Instruction',              'icon'=>'bi-book'],
    'research'    => ['label'=>'Research & Innovation',  'cat'=>'Research',                 'icon'=>'bi-journal-text'],
    'extension'   => ['label'=>'Extension',              'cat'=>'Extension',                'icon'=>'bi-people'],
    'profdev'     => ['label'=>'Prof. Development',      'cat'=>'Professional Development', 'icon'=>'bi-award'],
    'autosubrank' => ['label'=>'Auto Sub Rank',          'cat'=>'Auto Sub Rank',           'icon'=>'bi-arrow-up-circle'],
    'posreq'      => ['label'=>'Position Requirements',  'cat'=>'Position Requirements',   'icon'=>'bi-file-earmark-check'],
];

$cur_tab = $tabs[$active_tab];
$cur_cat = $cur_tab['cat'];

// Load criteria strictly scoped to this application's cycle AND the faculty's target position.
// Each position has its own criteria - no cycle-wide fallback.
$target_position = $app['position_title'] ?? null;
if (!$target_position && !empty($faculty['rank'])) {
    $target_position = $faculty['rank'];
}
if (!$target_position && !empty($app['position_title'])) {
    $target_position = $app['position_title'];
}
$cycle_id_for_criteria = $app['cycle_id'] ?? null;

$criteria_order_sql = "CASE WHEN criterion_label LIKE 'Criterion A%' THEN 1 WHEN criterion_label LIKE 'Criterion B%' THEN 2 WHEN criterion_label LIKE 'Criterion C%' THEN 3 WHEN criterion_label LIKE 'Criterion D%' THEN 4 ELSE 5 END ASC, max_points DESC";
$criteria_by_key = [];
$criteria_order = [];
$mergeCriteriaRows = function (array $rows) use (&$criteria_by_key, &$criteria_order): void {
    foreach ($rows as $row) {
        $key = trim((string)($row['criterion_key'] ?? ''));
        if ($key === '') {
            $key = 'label:' . trim((string)($row['criterion_label'] ?? ''));
        }
        if (!isset($criteria_by_key[$key])) {
            $criteria_order[] = $key;
        }
        $criteria_by_key[$key] = $row;
    }
};

// Load least-specific first, then let cycle/position rows override by key.
// This keeps newly added global criteria visible on existing seeded cycles,
// while preserving cycle/position customisations where they exist.
$cs = $pdo->prepare("SELECT * FROM scoring_criteria WHERE cycle_id IS NULL AND position_rank IS NULL AND kra_category=? AND is_active=1 ORDER BY {$criteria_order_sql}");
$cs->execute([$cur_cat]);
$mergeCriteriaRows($cs->fetchAll());

if ($cycle_id_for_criteria) {
    $cs = $pdo->prepare("SELECT * FROM scoring_criteria WHERE cycle_id=? AND position_rank IS NULL AND kra_category=? AND is_active=1 ORDER BY {$criteria_order_sql}");
    $cs->execute([$cycle_id_for_criteria, $cur_cat]);
    $mergeCriteriaRows($cs->fetchAll());
}

if ($cycle_id_for_criteria && $target_position) {
    $cs = $pdo->prepare("SELECT * FROM scoring_criteria WHERE cycle_id=? AND position_rank=? AND kra_category=? AND is_active=1 ORDER BY {$criteria_order_sql}");
    $cs->execute([$cycle_id_for_criteria, $target_position, $cur_cat]);
    $mergeCriteriaRows($cs->fetchAll());
}

$criteria_list = array_values(array_filter(array_map(
    fn($key) => $criteria_by_key[$key] ?? null,
    $criteria_order
)));

// Defensive cleanup for duplicated criteria configuration rows. Older MySQL
// unique keys allowed repeated NULL cycle/position scopes, so a repeated seed
// could duplicate Criterion A SET/SEF and other criteria.
$criteria_seen = [];
$criteria_list = array_values(array_filter($criteria_list, function ($c) use (&$criteria_seen) {
    $key = implode('|', [
        $c['cycle_id'] ?? '',
        $c['position_rank'] ?? '',
        $c['kra_category'] ?? '',
        trim((string)($c['criterion_label'] ?? '')),
    ]);
    if (isset($criteria_seen[$key])) return false;
    $criteria_seen[$key] = true;
    return true;
}));
usort($criteria_list, function ($a, $b) {
    $normaliseNumbering = function ($row): array {
        $numbering = kraNumberingEntryByKey((string)($row['criterion_key'] ?? ''));
        if (!is_array($numbering)) {
            $numbering = [];
        }
        $label = (string)($row['criterion_label'] ?? '');
        $fallback_criterion = '';
        if (preg_match('/Criterion\s+([A-Z])[\.:\s]/i', $label, $m)) {
            $fallback_criterion = strtoupper($m[1]);
        }
        return [
            'criterion' => (string)($numbering['criterion'] ?? $fallback_criterion),
            'group_no'  => isset($numbering['group_no']) ? (int)$numbering['group_no'] : 99,
            'item_no'   => isset($numbering['item_no']) && $numbering['item_no'] !== null ? (int)$numbering['item_no'] : 0,
            'label'     => $label,
        ];
    };
    $na = $normaliseNumbering(is_array($a) ? $a : []);
    $nb = $normaliseNumbering(is_array($b) ? $b : []);
    $letterOrder = ['A' => 1, 'B' => 2, 'C' => 3, 'D' => 4];
    $oa = [
        $letterOrder[$na['criterion']] ?? 9,
        $na['group_no'],
        $na['item_no'],
        $na['label'],
    ];
    $ob = [
        $letterOrder[$nb['criterion']] ?? 9,
        $nb['group_no'],
        $nb['item_no'],
        $nb['label'],
    ];
    return $oa <=> $ob;
});

$kra1_a_caps = ['set' => 36.0, 'sef' => 24.0];
if ($cur_cat === 'Instruction') {
    foreach ($criteria_list as $criterion_row) {
        if (($criterion_row['criterion_key'] ?? '') === 'kra1_a_set' && (float)$criterion_row['max_points'] > 0) {
            $kra1_a_caps['set'] = (float)$criterion_row['max_points'];
        } elseif (($criterion_row['criterion_key'] ?? '') === 'kra1_a_sef' && (float)$criterion_row['max_points'] > 0) {
            $kra1_a_caps['sef'] = (float)$criterion_row['max_points'];
        }
    }
}

// Pre-define cur_entries here so it's available throughout the template
$cur_entries = $subs_by_cat[$cur_cat] ?? [];
