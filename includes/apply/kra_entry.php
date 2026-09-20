<?php
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

function step2OfficialKraTotals(PDO $pdo, int $application_id): array {
    $scoring_dir = __DIR__ . '/../scoring/';
    foreach (['kra1_scorer.php','kra2_scorer.php','kra3_scorer.php','kra4_scorer.php'] as $f) {
        if (file_exists($scoring_dir . $f)) require_once $scoring_dir . $f;
    }
    if (class_exists('\Scoring\KRA1Scorer')) {
        \Scoring\KRA1Scorer::setPdo($pdo);
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
        fn($s) => (float)($s['computed_points'] ?? 0),
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
    $faculty_rank_ajax = $faculty['rank'] ?? '';
    $totals_ajax = step2OfficialKraTotals($pdo, $app_id);
    $score_ajax = computeWeightedScore($totals_ajax, $faculty_rank_ajax);
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
//       * approved / reclassified / admin_rejected → fully locked
//       * needs_revision → only revision-flagged entries (enforced per-entry below)
$_deadline_ts = !empty($cycle['submission_deadline'])
    ? strtotime($cycle['submission_deadline'] . ' 23:59:59')
    : null;
$_deadline_passed = $_deadline_ts !== null && time() > $_deadline_ts;

// Statuses that are permanently locked regardless of deadline
$non_editable_statuses = ['approved', 'reclassified', 'admin_rejected'];

// can_edit = not permanently locked AND deadline has not passed
// Special case: needs_revision is still editable after deadline (checker returned it, must fix)
$can_edit = !$locked
    && !in_array($app['status'], $non_editable_statuses)
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
                if ($remarks_type === 'A-set-sef') {
                    $meaningful_remarks = ($remarks_parts[1] ?? '') !== '' && ($remarks_parts[2] ?? '') !== '';
                } elseif ($remarks_type === 'A-set-sef-sem') {
                    $meaningful_remarks = ($remarks_parts[3] ?? '') !== '' && ($remarks_parts[4] ?? '') !== '';
                } else {
                    $meaningful_remarks = str_starts_with($remarks_type, 'B|') || str_starts_with($remarks_type, 'C|')
                        || in_array($remarks_type, ['B-material', 'C-thesis', 'C-mentor'], true);
                }
            } elseif ($meaningful_remarks && $category === 'Extension') {
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
                require_once __DIR__ . '/kra_ajax.php';
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
            $folder    = __DIR__ . '/../../uploads/kra' . $kra_num . '/';
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
                $abs = rtrim(str_replace('/', DIRECTORY_SEPARATOR, realpath(__DIR__ . '/../../')), DIRECTORY_SEPARATOR)
                       . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $fp);
                if (file_exists($abs)) @unlink($abs);
            }
            $row = $pdo->prepare("SELECT document_path FROM kra_submissions WHERE submission_id=? AND application_id=?");
            $row->execute([$del_id, $app_id]);
            $row = $row->fetch();
            if ($row) {
                if ($row['document_path']) {
                    $abs_del = rtrim(str_replace('/', DIRECTORY_SEPARATOR, realpath(__DIR__ . '/../../')), DIRECTORY_SEPARATOR)
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
        // ── Auto Sub-Rank is now fully automatic — no manual save needed.
        // Recalculate and persist, then redirect back.
        if (!class_exists('\Scoring\AutoSubRankCalculator')) {
            require_once __DIR__ . '/../scoring/autosubrank.php';
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
                    $folder = __DIR__ . '/../../uploads/pos_req/';
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
        // Block if deadline has passed
        if (!empty($cycle['submission_deadline']) && time() > strtotime($cycle['submission_deadline'] . ' 23:59:59')) {
            flashMessage('danger', 'The submission deadline has passed. You can no longer submit for this cycle.');
            echo "<script>window.location.href='index.php?page=apply&tab={$active_tab}';</script>"; exit;
        }

        try { recalcApplicationScore($pdo, $app_id); } catch (\Exception $e) {}

        // Block if weighted score is below 41
        $faculty_rank_check = $faculty['rank'] ?? '';
        $score_check = computeWeightedScore(step2OfficialKraTotals($pdo, $app_id), $faculty_rank_check);
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
        // Notify all active campus checkers
        $campus_checkers = $pdo->query("SELECT user_id FROM users WHERE role = 'checker' AND status='active'")->fetchAll(PDO::FETCH_COLUMN);
        $faculty_name = $_SESSION['full_name'] ?? "Faculty #{$uid}";
        foreach ($campus_checkers as $cid) {
            createNotif($pdo, (int)$cid, 'new_submission',
                "New application submitted by {$faculty_name} ... awaiting your review.",
                $app_id);
        }
        $msg = $new_status === 'under_review'
            ? 'Application resubmitted. Your checker will continue the review.'
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
// Each position has its own criteria &mdash; no cycle-wide fallback.
$target_position = $app['position_title'] ?? null;
if (!$target_position && !empty($faculty['rank'])) {
    $target_position = $faculty['rank'];
}
if (!$target_position && !empty($app['position_title'])) {
    $target_position = $app['position_title'];
}
$cycle_id_for_criteria = $app['cycle_id'] ?? null;

$criteria_list = [];
if ($cycle_id_for_criteria && $target_position) {
    // Position-specific criteria for this cycle
    $cs = $pdo->prepare("SELECT * FROM scoring_criteria WHERE cycle_id=? AND position_rank=? AND kra_category=? AND is_active=1 ORDER BY CASE WHEN criterion_label LIKE 'Criterion A%' THEN 1 WHEN criterion_label LIKE 'Criterion B%' THEN 2 WHEN criterion_label LIKE 'Criterion C%' THEN 3 WHEN criterion_label LIKE 'Criterion D%' THEN 4 ELSE 5 END ASC, max_points DESC");
    $cs->execute([$cycle_id_for_criteria, $target_position, $cur_cat]);
    $criteria_list = $cs->fetchAll();
}
if (empty($criteria_list) && $cycle_id_for_criteria) {
    // Fallback: cycle-wide (no position) &mdash; for cycles created before position-specific seeding
    $cs = $pdo->prepare("SELECT * FROM scoring_criteria WHERE cycle_id=? AND position_rank IS NULL AND kra_category=? AND is_active=1 ORDER BY CASE WHEN criterion_label LIKE 'Criterion A%' THEN 1 WHEN criterion_label LIKE 'Criterion B%' THEN 2 WHEN criterion_label LIKE 'Criterion C%' THEN 3 WHEN criterion_label LIKE 'Criterion D%' THEN 4 ELSE 5 END ASC, max_points DESC");
    $cs->execute([$cycle_id_for_criteria, $cur_cat]);
    $criteria_list = $cs->fetchAll();
}
if (empty($criteria_list)) {
    // Final fallback: global defaults
    $cs = $pdo->prepare("SELECT * FROM scoring_criteria WHERE cycle_id IS NULL AND position_rank IS NULL AND kra_category=? AND is_active=1 ORDER BY CASE WHEN criterion_label LIKE 'Criterion A%' THEN 1 WHEN criterion_label LIKE 'Criterion B%' THEN 2 WHEN criterion_label LIKE 'Criterion C%' THEN 3 WHEN criterion_label LIKE 'Criterion D%' THEN 4 ELSE 5 END ASC, max_points DESC");
    $cs->execute([$cur_cat]);
    $criteria_list = $cs->fetchAll();
}

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

// Pre-define cur_entries here so it's available throughout the template
$cur_entries = $subs_by_cat[$cur_cat] ?? [];
?>

<!-- -- KRA Entries Card -------------------------------------- -->
<div class="neon-card p-0 mb-3" style="overflow:hidden;">
  <!-- Card Header -->
  <div style="background:linear-gradient(135deg,#1e3a6b,#1e4d8c);padding:1rem 1.5rem;display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:0.75rem;">
    <div>
      <h5 style="color:#fff;font-weight:700;font-size:0.97rem;margin:0;line-height:1.3;display:flex;align-items:center;gap:0.5rem;">
        <i class="bi bi-file-earmark-arrow-up" style="font-size:1.1rem;opacity:0.9;"></i>
        KRA Entries &amp; Evidence
        <?= helpBtn('KRA Entry Guide', 'For each KRA tab: 1) Open the entry table for the criterion you need. 2) Select the criterion type and fill in the details ... the score calculates automatically. 3) Click the upload button on each row to attach your evidence file. 4) Click Done. Once all entries are saved, click Submit Application.') ?>
      </h5>
      <?php if (!empty($cycle['submission_deadline'])): ?>
      <?php $dl_ts = strtotime($cycle['submission_deadline'] . ' 23:59:59'); ?>
      <div style="margin-top:0.3rem;font-size:0.78rem;color:rgba(255,255,255,0.75);display:flex;align-items:center;gap:0.4rem;">
        <i class="bi bi-clock"></i>
        Submission deadline: <strong style="color:<?= time() > $dl_ts ? '#94a3b8' : '#e2e8f0' ?>;"><?= date('M d, Y', strtotime($cycle['submission_deadline'])) ?></strong>
        <?php if (time() > $dl_ts): ?>
        <span style="background:#334155;color:#fff;border-radius:20px;padding:0.1rem 0.5rem;font-size:0.65rem;font-weight:700;">Passed</span>
        <?php else: ?>
        <span style="background:#475569;color:#fff;border-radius:20px;padding:0.1rem 0.5rem;font-size:0.65rem;font-weight:700;"><?= ceil(($dl_ts - time()) / 86400) ?> day(s) left</span>
        <?php endif; ?>
      </div>
      <?php endif; ?>
    </div>
    <div style="display:flex;gap:0.5rem;align-items:center;" id="kra-entry-actions">
      <a href="index.php?page=dashboard"
         style="background:rgba(255,255,255,0.12);color:#fff;border:1px solid rgba(255,255,255,0.25);border-radius:7px;padding:0.4rem 0.9rem;font-size:0.82rem;font-weight:600;text-decoration:none;display:inline-flex;align-items:center;gap:0.35rem;white-space:nowrap;transition:background 0.15s;">
        <i class="bi bi-arrow-left"></i>Back
      </a>
      <?php if (!$locked && $grand2 > 0): ?>
      <?php
        $faculty_rank_btn = $faculty['rank'] ?? '';
        $score_btn = computeWeightedScore($kra_totals2, $faculty_rank_btn);
        $can_submit_step2 = $score_btn['weighted_score'] >= 41;
        $deadline_passed_step2 = $_deadline_passed;
      ?>
      <?php if ($deadline_passed_step2): ?>
      <button type="button" disabled
              style="background:rgba(255,255,255,0.1);color:rgba(255,255,255,0.5);border:1px solid rgba(255,255,255,0.15);border-radius:7px;padding:0.4rem 0.9rem;font-size:0.82rem;font-weight:600;cursor:not-allowed;display:inline-flex;align-items:center;gap:0.35rem;white-space:nowrap;">
        <i class="bi bi-calendar-x"></i>Deadline Passed
      </button>
      <?php elseif ($can_submit_step2): ?>
      <form method="POST" class="d-inline m-0 p-0" id="submitAppForm" style="line-height:0;">
        <input type="hidden" name="kra_action" value="submit_application">
        <button type="button"
                style="background:#fff;color:#1e4d8c;border:none;border-radius:7px;padding:0.4rem 1rem;font-size:0.82rem;font-weight:700;cursor:pointer;display:inline-flex;align-items:center;gap:0.35rem;white-space:nowrap;box-shadow:0 2px 8px rgba(0,0,0,0.15);"
                onclick="openSubmitModal()">
          <i class="bi bi-send"></i>Submit Application
        </button>
      </form>
      <?php else: ?>
      <button type="button" disabled
              title="Weighted score (<?= number_format($score_btn['weighted_score'], 2) ?>) is below the minimum 41.00 required."
              style="background:rgba(255,255,255,0.1);color:rgba(255,255,255,0.5);border:1px solid rgba(255,255,255,0.15);border-radius:7px;padding:0.4rem 0.9rem;font-size:0.82rem;font-weight:600;cursor:not-allowed;display:inline-flex;align-items:center;gap:0.35rem;white-space:nowrap;">
        <i class="bi bi-send"></i>Submit Application
      </button>
      <?php endif; ?>
      <?php endif; ?>
      <?php if (in_array($active_tab, ['instruction','research','extension','profdev'])): ?>
      <?php $knum_map = ['instruction'=>'I','research'=>'II','extension'=>'III','profdev'=>'IV']; ?>
      <a href="pages/kra_pdf.php?uid=<?= $_SESSION['user_id'] ?>&cycle_id=<?= $cycle['cycle_id'] ?>&kra=all" target="_blank"
         style="background:#fff;color:#1e4d8c;border:none;border-radius:7px;padding:0.4rem 0.8rem;font-size:0.78rem;font-weight:700;text-decoration:none;display:inline-flex;align-items:center;gap:0.3rem;white-space:nowrap;box-shadow:0 2px 8px rgba(0,0,0,0.12);">
        <i class="bi bi-files"></i>ISS / All KRA
      </a>
      <a href="pages/kra_pdf.php?uid=<?= $_SESSION['user_id'] ?>&cycle_id=<?= $cycle['cycle_id'] ?>&kra=<?= urlencode($cur_cat) ?>" target="_blank"
         style="background:rgba(255,255,255,0.12);color:#fff;border:1px solid rgba(255,255,255,0.25);border-radius:7px;padding:0.4rem 0.7rem;font-size:0.78rem;font-weight:600;text-decoration:none;display:inline-flex;align-items:center;gap:0.3rem;white-space:nowrap;">
        <i class="bi bi-printer"></i>KRA <?= $knum_map[$active_tab] ?>
      </a>
      <?php endif; ?>
    </div>
  </div>

  <!-- Alert banners -->
  <div style="padding:0 1.25rem;">
  <?php if ($locked): ?>
  <div style="margin-top:0.85rem;padding:0.65rem 1rem;background:#eff6ff;border:1px solid #bfdbfe;border-left:4px solid #1e4d8c;border-radius:8px;font-size:0.82rem;color:#1e4d8c;">
    <i class="bi bi-lock-fill me-2"></i>Application approved &mdash; scores are locked.
  </div>
  <?php elseif ($_deadline_passed && !in_array($app['status'], ['needs_revision', 'rejected', 'approved', 'reclassified', 'admin_rejected'])): ?>
  <div style="margin-top:0.85rem;padding:0.65rem 1rem;background:#fef2f2;border:1px solid #fecaca;border-left:4px solid #dc2626;border-radius:8px;font-size:0.82rem;color:#991b1b;">
    <i class="bi bi-calendar-x-fill me-2"></i>
    <strong>Submission deadline has passed.</strong> You can no longer add or edit KRA entries for this cycle.
  </div>
  <?php elseif ($app['status'] === 'needs_revision'): ?>
  <div style="margin-top:0.85rem;padding:0.65rem 1rem;background:#f8fafc;border:1px solid #e2e8f0;border-left:4px solid #475569;border-radius:8px;font-size:0.82rem;color:#334155;">
    <i class="bi bi-exclamation-triangle-fill me-2" style="color:#475569;"></i>
    <strong>Revision requested by checker.</strong> Only flagged entries can be edited. Fix the highlighted entries and submit your revision from the Dashboard.
  </div>
  <?php elseif ($app['status'] === 'rejected'): ?>
  <div style="margin-top:0.85rem;padding:0.65rem 1rem;background:#f8fafc;border:1px solid #e2e8f0;border-left:4px solid #475569;border-radius:8px;font-size:0.82rem;color:#334155;">
    <i class="bi bi-arrow-counterclockwise me-2" style="color:#475569;"></i>
    <strong>Returned for revision by checker.</strong> You can edit your entries and resubmit.
  </div>
  <?php endif; ?>

  <?php
  // -- STEP 7 Self-Assessment Advisory (JC01 s.2026) ---------------------------
  $app_pending  = json_decode($app['pending_documentation'] ?? '[]', true) ?: [];
  $app_config   = json_decode($app['config_incomplete'] ?? '[]', true) ?: [];
  $app_double   = json_decode($app['double_counting_flags'] ?? '[]', true) ?: [];
  $eval_period_ok = isset($app['evaluation_period_ok']) ? (bool)$app['evaluation_period_ok'] : true;
  $has_advisory = !$eval_period_ok || !empty($app_pending) || !empty($app_config) || !empty($app_double);

  if ($has_advisory && !$locked):
  ?>
  <div style="margin-top:0.85rem;border:1px solid #fde68a;border-left:4px solid #f59e0b;border-radius:8px;overflow:hidden;font-size:0.8rem;">
    <div style="padding:0.5rem 0.85rem;background:#fffbeb;display:flex;align-items:center;gap:0.5rem;font-weight:700;color:#92400e;">
      <i class="bi bi-clipboard2-pulse"></i> Self-Assessment Advisory (JC01 s.2026)
      <span style="margin-left:auto;font-weight:400;color:#b45309;font-size:0.72rem;">Advisory only ... your checker makes the binding decision</span>
    </div>
    <div style="padding:0.6rem 0.85rem;background:#fffdf0;">
      <?php if (!$eval_period_ok): ?>
      <div style="color:#dc2626;margin-bottom:0.35rem;"><i class="bi bi-calendar-x me-1"></i>Application is linked to an archived cycle. Contact your administrator.</div>
      <?php endif; ?>
      <?php foreach (array_slice($app_double, 0, 3) as $d): ?>
      <div style="color:#c2410c;margin-bottom:0.25rem;"><i class="bi bi-copy me-1"></i><strong>Double-counting flagged:</strong> "<?= htmlspecialchars($d['title'] ?? '') ?>"</div>
      <?php endforeach; ?>
      <?php foreach (array_slice($app_pending, 0, 5) as $pd): ?>
      <div style="color:#92400e;margin-bottom:0.2rem;"><i class="bi bi-file-earmark-x me-1"></i><?= htmlspecialchars($pd) ?></div>
      <?php endforeach; ?>
      <?php foreach (array_slice($app_config, 0, 3) as $ci): ?>
      <div style="color:#7c3aed;margin-bottom:0.2rem;"><i class="bi bi-wrench me-1"></i><?= htmlspecialchars($ci) ?></div>
      <?php endforeach; ?>
      <?php $total_items = count($app_pending) + count($app_config) + count($app_double); ?>
      <?php if ($total_items > 11): ?>
      <div style="color:#94a3b8;font-size:0.72rem;margin-top:0.25rem;">+ more items ... full details visible to your checker.</div>
      <?php endif; ?>
      <div style="margin-top:0.4rem;padding:0.35rem 0.6rem;background:#fef9c3;border-radius:5px;color:#713f12;font-size:0.74rem;">
        <i class="bi bi-info-circle me-1"></i>Documents from an unsuccessful previous application cannot be reused in this cycle (JC01 s.2026 evaluation-period rule).
      </div>
    </div>
  </div>
  <?php endif; ?>
  </div>

  <!-- KRA Tabs -->
  <div style="background:#f8fafc;padding:0.6rem 1rem;display:flex;gap:0.4rem;overflow-x:auto;flex-wrap:nowrap;border-bottom:1px solid #e2e8f0;">
    <?php foreach ($tabs as $slug => $t): 
        // Only show points badge for KRA tabs, not for Auto Sub Rank or Position Requirements
        $is_kra_tab = in_array($slug, ['instruction', 'research', 'extension', 'profdev']);
        $pts = $is_kra_tab ? $kra_totals2[$t['cat']] ?? 0 : 0;
        $status_text = '';
        // Compute percentage for KRA tabs
        $pct_text = '';
        if ($is_kra_tab) {
            $pct_text = '(' . $pts . '%)';
        }
        
        if ($slug === 'autosubrank') {
            // Get Auto Sub Rank status from new mode columns
            if (!class_exists('\Scoring\AutoSubRankCalculator')) {
                require_once __DIR__ . '/../scoring/autosubrank.php';
            }
            $asr_tab = \Scoring\AutoSubRankCalculator::loadForApplication($pdo, $app_id);
            $d_mode  = $asr_tab['doctorate_mode'] ?? 'not_eligible';
            $a_mode  = $asr_tab['award_mode']     ?? 'not_eligible';
            if ($d_mode === 'triggered' || $a_mode === 'triggered') {
                $status_text = '+1 rank';
            } elseif (!$asr_tab) {
                $status_text = 'Pending';
            } else {
                $status_text = 'None';
            }
        } elseif ($slug === 'posreq') {
            // Get Position Requirements status  
            $pos_req = $pdo->prepare("SELECT * FROM position_requirements WHERE application_id = ?");
            $pos_req->execute([$app_id]);
            $pos_data = $pos_req->fetch();
            $faculty_rank = $faculty['rank'] ?? '';
            
            if (strpos($faculty_rank, 'Professor') !== false && strpos($faculty_rank, 'Assistant') === false && strpos($faculty_rank, 'Associate') === false) {
                // Professor rank - needs CAV transcript and international article
                if ($pos_data && $pos_data['cav_transcript'] === 'Uploaded' && $pos_data['international_article'] === 'Uploaded') {
                    $status_text = 'Complete';
                } else {
                    $status_text = 'Incomplete';
                }
            } elseif (strpos($faculty_rank, 'University Professor') !== false) {
                // University Professor - needs certification form
                if ($pos_data && $pos_data['certification_form'] === 'Uploaded') {
                    $status_text = 'Complete';
                } else {
                    $status_text = 'Incomplete';
                }
            } else {
                $status_text = 'N/A';
            }
        }
        $is_active = ($active_tab === $slug);
    ?>
    <a href="?page=apply&tab=<?= $slug ?>"
       style="flex-shrink:0;display:inline-flex;align-items:center;padding:0.35rem 0.85rem;font-size:0.78rem;font-weight:600;text-decoration:none;border-radius:6px;white-space:nowrap;border:1px solid;transition:all 0.15s;
              <?= $is_active
                  ? 'background:#1e4d8c;color:#fff;border-color:#1e4d8c;'
                  : 'background:#fff;color:#64748b;border-color:#e2e8f0;' ?>">
      <?= $t['label'] ?>
    </a>
    <?php endforeach; ?>
  </div>

  <!-- Toolbar strip -->
  <div style="background:#f8fafc;padding:0.5rem 1rem;display:flex;align-items:center;border-bottom:1px solid #e2e8f0;font-size:0.8rem;">
    <button onclick="location.reload()" style="background:none;border:none;color:#1e4d8c;cursor:pointer;font-size:0.78rem;display:flex;align-items:center;gap:0.3rem;margin-left:auto;">
      <i class="bi bi-arrow-clockwise"></i> Refresh
    </button>
  </div>

  <!-- Tab Content -->
  <div style="background:#ffffff;padding:1rem;min-height:300px;">
    
    <?php if ($cur_cat === 'Auto Sub Rank'): ?>
    <!-- AUTO SUB RANK TAB CONTENT — fully automatic, read-only -->
    <?php
    if (!class_exists('\Scoring\AutoSubRankCalculator')) {
        require_once __DIR__ . '/../scoring/autosubrank.php';
    }
    // Recalculate automatically on every page load
    $asr_calc   = new \Scoring\AutoSubRankCalculator($pdo, $app_id);
    $asr_result = $asr_calc->calculate();
    $asr_calc->persist($asr_result);
    $asr_row     = \Scoring\AutoSubRankCalculator::loadForApplication($pdo, $app_id) ?: [];
    $d_mode      = $asr_result['doctorate_mode'];
    $a_mode      = $asr_result['award_mode'];
    $d_color     = \Scoring\AutoSubRankCalculator::modeColor($d_mode);
    $a_color     = \Scoring\AutoSubRankCalculator::modeColor($a_mode);
    $d_label     = \Scoring\AutoSubRankCalculator::modeLabel($d_mode);
    $a_label     = \Scoring\AutoSubRankCalculator::modeLabel($a_mode);
    $d_verified  = $asr_row['doctorate_verified'] ?? 'pending';
    $a_verified  = $asr_row['award_verified']     ?? 'pending';
    $dv_color    = \Scoring\AutoSubRankCalculator::verifiedColor($d_verified);
    $av_color    = \Scoring\AutoSubRankCalculator::verifiedColor($a_verified);
    $rank_increase = $asr_result['total_rank_increase'];
    ?>

    <!-- Section header -->
    <div style="display:flex;align-items:center;gap:0.65rem;margin-bottom:1.25rem;">
      <div style="width:38px;height:38px;border-radius:10px;background:#1e4d8c;display:flex;align-items:center;justify-content:center;flex-shrink:0;box-shadow:0 2px 8px rgba(0,0,0,0.15);">
        <i class="bi bi-arrow-up-circle" style="color:#fff;font-size:1.1rem;"></i>
      </div>
      <div>
        <h6 style="font-weight:700;color:#1a3a6b;margin:0;font-size:0.95rem;line-height:1.2;">Automatic Sub-Rank Assessment</h6>
        <div style="font-size:0.73rem;color:#64748b;margin-top:2px;">
          <i class="bi bi-cpu me-1"></i>System-calculated — no manual choice required
        </div>
      </div>
      <?php if ($rank_increase > 0): ?>
      <div style="margin-left:auto;background:#f0fdf4;border:1.5px solid #bbf7d0;border-radius:8px;padding:0.35rem 0.85rem;display:flex;align-items:center;gap:0.4rem;">
        <i class="bi bi-arrow-up-circle-fill" style="color:#16a34a;font-size:1rem;"></i>
        <span style="font-weight:700;color:#16a34a;font-size:0.88rem;">+<?= $rank_increase ?> sub-rank<?= $rank_increase > 1 ? 's' : '' ?></span>
      </div>
      <?php else: ?>
      <div style="margin-left:auto;background:#f8fafc;border:1px solid #e2e8f0;border-radius:8px;padding:0.35rem 0.85rem;display:flex;align-items:center;gap:0.4rem;">
        <i class="bi bi-dash-circle" style="color:#64748b;font-size:1rem;"></i>
        <span style="font-weight:600;color:#64748b;font-size:0.88rem;">No sub-rank increase</span>
      </div>
      <?php endif; ?>
    </div>

    <!-- Criterion 1: Doctorate -->
    <div style="border:1px solid #e2e8f0;border-radius:10px;overflow:hidden;margin-bottom:0.85rem;">
      <div style="background:#f8fafc;border-bottom:1px solid #e2e8f0;padding:0.55rem 1rem;display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:0.5rem;">
        <span style="font-size:0.72rem;font-weight:700;color:#475569;text-transform:uppercase;letter-spacing:0.06em;">
          <i class="bi bi-mortarboard me-1"></i>Criterion 1 — Doctorate Degree
        </span>
        <div style="display:flex;align-items:center;gap:0.5rem;flex-wrap:wrap;">
          <span style="background:<?= $d_color ?>18;color:<?= $d_color ?>;border:1px solid <?= $d_color ?>40;padding:0.2rem 0.7rem;border-radius:20px;font-size:0.68rem;font-weight:700;">
            <?= htmlspecialchars($d_label) ?>
          </span>
          <span style="background:<?= $dv_color ?>12;color:<?= $dv_color ?>;border:1px solid <?= $dv_color ?>40;padding:0.2rem 0.7rem;border-radius:20px;font-size:0.67rem;font-weight:600;">
            <i class="bi bi-shield me-1"></i>Checker: <?= ucfirst($d_verified) ?>
          </span>
        </div>
      </div>
      <div style="padding:0.85rem 1rem;">
        <?php if ($asr_result['has_doctorate']): ?>
        <div style="display:flex;gap:1rem;flex-wrap:wrap;align-items:flex-start;">
          <div style="flex:1;min-width:180px;">
            <div style="font-size:0.72rem;color:#94a3b8;font-weight:600;text-transform:uppercase;letter-spacing:0.05em;margin-bottom:0.3rem;">Detected From</div>
            <div style="font-size:0.85rem;color:#1e293b;font-weight:500;"><?= htmlspecialchars($asr_result['doctorate_details'] ?: 'Doctorate (Professional Development)') ?></div>
          </div>
          <div style="flex:1;min-width:200px;">
            <div style="font-size:0.72rem;color:#94a3b8;font-weight:600;text-transform:uppercase;letter-spacing:0.05em;margin-bottom:0.3rem;">Why This Decision</div>
            <div style="font-size:0.82rem;color:#475569;line-height:1.5;"><?= htmlspecialchars($asr_result['doctorate_reason']) ?></div>
          </div>
          <?php if ($d_mode === 'points_only' || $d_mode === 'blocked_historical'): ?>
          <div style="background:#eff6ff;border:1px solid #bfdbfe;border-radius:6px;padding:0.5rem 0.75rem;font-size:0.75rem;color:#1e4d8c;width:100%;">
            <i class="bi bi-info-circle me-1"></i>Doctorate contributes <strong><?= (int)\Scoring\AutoSubRankCalculator::DOCTORATE_POINTS ?> points</strong> to your KRA IV score.
          </div>
          <?php endif; ?>
          <?php if (!empty($asr_row['doctorate_verification_notes']) && $d_verified !== 'pending'): ?>
          <div style="background:<?= $dv_color ?>0e;border:1px solid <?= $dv_color ?>30;border-radius:6px;padding:0.5rem 0.75rem;font-size:0.75rem;color:<?= $dv_color ?>;width:100%;">
            <i class="bi bi-chat-left-text me-1"></i><strong>Checker note:</strong> <?= htmlspecialchars($asr_row['doctorate_verification_notes']) ?>
          </div>
          <?php endif; ?>
        </div>
        <?php else: ?>
        <div style="display:flex;align-items:center;gap:0.75rem;color:#64748b;font-size:0.84rem;">
          <i class="bi bi-exclamation-circle" style="font-size:1.1rem;color:#94a3b8;flex-shrink:0;"></i>
          <div>No doctorate entry found in Professional Development.
            <a href="?page=apply&tab=profdev" style="color:#1e4d8c;font-weight:600;margin-left:0.3rem;">
              <i class="bi bi-plus-circle me-1"></i>Add doctorate in Prof. Development
            </a>
          </div>
        </div>
        <?php endif; ?>
      </div>
    </div>

    <!-- Criterion 2: Prestigious Award -->
    <div style="border:1px solid #e2e8f0;border-radius:10px;overflow:hidden;margin-bottom:0.85rem;">
      <div style="background:#f8fafc;border-bottom:1px solid #e2e8f0;padding:0.55rem 1rem;display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:0.5rem;">
        <span style="font-size:0.72rem;font-weight:700;color:#475569;text-transform:uppercase;letter-spacing:0.06em;">
          <i class="bi bi-trophy me-1"></i>Criterion 2 — National / International Award
        </span>
        <div style="display:flex;align-items:center;gap:0.5rem;flex-wrap:wrap;">
          <span style="background:<?= $a_color ?>18;color:<?= $a_color ?>;border:1px solid <?= $a_color ?>40;padding:0.2rem 0.7rem;border-radius:20px;font-size:0.68rem;font-weight:700;">
            <?= htmlspecialchars($a_label) ?>
          </span>
          <?php if ($asr_result['has_award']): ?>
          <span style="background:<?= $av_color ?>12;color:<?= $av_color ?>;border:1px solid <?= $av_color ?>40;padding:0.2rem 0.7rem;border-radius:20px;font-size:0.67rem;font-weight:600;">
            <i class="bi bi-shield me-1"></i>Checker: <?= ucfirst($a_verified) ?>
          </span>
          <?php endif; ?>
        </div>
      </div>
      <div style="padding:0.85rem 1rem;">
        <?php if ($asr_result['has_award']): ?>
        <div style="display:flex;gap:1rem;flex-wrap:wrap;align-items:flex-start;">
          <div style="flex:1;min-width:180px;">
            <div style="font-size:0.72rem;color:#94a3b8;font-weight:600;text-transform:uppercase;letter-spacing:0.05em;margin-bottom:0.3rem;">Award</div>
            <div style="font-size:0.85rem;color:#1e293b;font-weight:500;"><?= htmlspecialchars($asr_result['award_details'] ?: 'National/International Award (KRA IV)') ?></div>
          </div>
          <div style="flex:1;min-width:200px;">
            <div style="font-size:0.72rem;color:#94a3b8;font-weight:600;text-transform:uppercase;letter-spacing:0.05em;margin-bottom:0.3rem;">Why This Decision</div>
            <div style="font-size:0.82rem;color:#475569;line-height:1.5;"><?= htmlspecialchars($asr_result['award_reason']) ?></div>
          </div>
          <?php if (!empty($asr_row['award_evidence'])): ?>
          <div style="width:100%;">
            <a href="../<?= htmlspecialchars($asr_row['award_evidence']) ?>" target="_blank"
               style="font-size:0.78rem;color:#1e4d8c;text-decoration:none;display:inline-flex;align-items:center;gap:0.3rem;">
              <i class="bi bi-file-earmark-pdf"></i>View uploaded evidence
            </a>
          </div>
          <?php endif; ?>
          <?php if (!empty($asr_row['award_verification_notes']) && $a_verified !== 'pending'): ?>
          <div style="background:<?= $av_color ?>0e;border:1px solid <?= $av_color ?>30;border-radius:6px;padding:0.5rem 0.75rem;font-size:0.75rem;color:<?= $av_color ?>;width:100%;">
            <i class="bi bi-chat-left-text me-1"></i><strong>Checker note:</strong> <?= htmlspecialchars($asr_row['award_verification_notes']) ?>
          </div>
          <?php endif; ?>
        </div>
        <?php else: ?>
        <div style="display:flex;align-items:center;gap:0.75rem;color:#64748b;font-size:0.84rem;">
          <i class="bi bi-exclamation-circle" style="font-size:1.1rem;color:#94a3b8;flex-shrink:0;"></i>
          <div>No national/international award found in your entries.
            <a href="?page=apply&tab=profdev" style="color:#1e4d8c;font-weight:600;margin-left:0.3rem;">
              <i class="bi bi-plus-circle me-1"></i>Add award in Prof. Development (Crit. C)
            </a>
          </div>
        </div>
        <?php endif; ?>
      </div>
    </div>

    <!-- Summary card -->
    <div style="border:1.5px solid <?= $rank_increase > 0 ? '#bbf7d0' : '#e2e8f0' ?>;border-radius:10px;padding:1rem;background:<?= $rank_increase > 0 ? '#f0fdf4' : '#f8fafc' ?>;">
      <div style="font-size:0.73rem;font-weight:700;color:#475569;text-transform:uppercase;letter-spacing:0.06em;margin-bottom:0.6rem;">
        <i class="bi bi-calculator me-1"></i>Summary
      </div>
      <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(130px,1fr));gap:0.6rem;">
        <div style="background:#fff;border:1px solid #e2e8f0;border-radius:7px;padding:0.6rem 0.75rem;">
          <div style="font-size:0.68rem;color:#94a3b8;font-weight:600;text-transform:uppercase;margin-bottom:0.2rem;">Current Score</div>
          <div style="font-size:1rem;font-weight:700;color:#1e293b;"><?= number_format((float)($asr_result['weighted_score_at_calc'] ?? 0), 2) ?></div>
        </div>
        <div style="background:#fff;border:1px solid #e2e8f0;border-radius:7px;padding:0.6rem 0.75rem;">
          <div style="font-size:0.68rem;color:#94a3b8;font-weight:600;text-transform:uppercase;margin-bottom:0.2rem;">Doctorate Mode</div>
          <div style="font-size:0.8rem;font-weight:700;color:<?= $d_color ?>;"><?= htmlspecialchars($d_label) ?></div>
        </div>
        <div style="background:#fff;border:1px solid #e2e8f0;border-radius:7px;padding:0.6rem 0.75rem;">
          <div style="font-size:0.68rem;color:#94a3b8;font-weight:600;text-transform:uppercase;margin-bottom:0.2rem;">Award Mode</div>
          <div style="font-size:0.8rem;font-weight:700;color:<?= $a_color ?>;"><?= htmlspecialchars($a_label) ?></div>
        </div>
        <div style="background:<?= $rank_increase > 0 ? '#f0fdf4' : '#fff' ?>;border:1px solid <?= $rank_increase > 0 ? '#bbf7d0' : '#e2e8f0' ?>;border-radius:7px;padding:0.6rem 0.75rem;">
          <div style="font-size:0.68rem;color:#94a3b8;font-weight:600;text-transform:uppercase;margin-bottom:0.2rem;">Auto Sub-Rank</div>
          <div style="font-size:1rem;font-weight:700;color:<?= $rank_increase > 0 ? '#16a34a' : '#64748b' ?>;">
            <?= $rank_increase > 0 ? "+{$rank_increase}" : '0' ?> rank<?= $rank_increase !== 1 ? 's' : '' ?>
          </div>
        </div>
      </div>
      <div style="margin-top:0.6rem;font-size:0.71rem;color:#94a3b8;display:flex;align-items:center;gap:0.35rem;flex-wrap:wrap;">
        <i class="bi bi-arrow-repeat"></i>Recalculated automatically — updates when you add/edit KRA IV entries.
        <?php if ($d_verified === 'rejected' || $a_verified === 'rejected'): ?>
        <span style="color:#dc2626;font-weight:600;"><i class="bi bi-exclamation-triangle me-1"></i>Item rejected by checker — see notes above.</span>
        <?php endif; ?>
      </div>
    </div>

    <?php elseif ($cur_cat === 'Position Requirements'): ?>
    <!-- POSITION REQUIREMENTS TAB CONTENT -->
    <?php
    $pos_req = $pdo->prepare("SELECT * FROM position_requirements WHERE application_id = ?");
    $pos_req->execute([$app_id]);
    $pos_data = $pos_req->fetch();
    
    $faculty_rank = $faculty['rank'] ?? '';
    $is_instructor = strpos($faculty_rank, 'Instructor') !== false;
    $is_asst_assoc = (strpos($faculty_rank, 'Assistant Professor') !== false || strpos($faculty_rank, 'Associate Professor') !== false);
    $is_professor = strpos($faculty_rank, 'Professor') !== false && !$is_asst_assoc;
    $is_univ_prof = strpos($faculty_rank, 'University Professor') !== false;
    ?>
    
    <div style="display:flex;align-items:center;gap:0.65rem;margin-bottom:1.5rem;">
      <div style="width:38px;height:38px;border-radius:10px;background:#1e4d8c;display:flex;align-items:center;justify-content:center;flex-shrink:0;box-shadow:0 3px 10px rgba(0,0,0,0.15);">
        <i class="bi bi-file-earmark-check" style="color:#fff;font-size:1.1rem;"></i>
      </div>
      <div>
        <h6 style="font-weight:700;color:#1a3a6b;margin:0;font-size:0.97rem;line-height:1.2;">Position Requirements</h6>
        <div style="font-size:0.75rem;color:#64748b;margin-top:2px;">
          <i class="bi bi-info-circle me-1"></i>Document checklist for <?= sanitize($faculty_rank) ?>
        </div>
      </div>
    </div>

    <?php if ($is_instructor || $is_asst_assoc): ?>
    <!-- No requirements for Instructor and Assistant/Associate Professor -->
    <div style="background:#f0fdf4;border:1px solid #bbf7d0;border-radius:8px;padding:2rem;text-align:center;">
      <i class="bi bi-check-circle-fill" style="color:#16a34a;font-size:2rem;margin-bottom:1rem;display:block;"></i>
      <h6 style="color:#16a34a;font-weight:700;margin-bottom:0.5rem;">No Additional Documents Required</h6>
      <p style="color:#15803d;margin:0;font-size:0.9rem;">Your current position (<?= sanitize($faculty_rank) ?>) does not require additional documentation.</p>
    </div>

    <?php elseif ($is_professor && !$is_univ_prof): ?>
    <!-- Professor requirements -->
    <form method="POST" enctype="multipart/form-data">
      <input type="hidden" name="kra_action" value="save_position_requirements">
      
      <div style="border:1px solid #e2e8f0;border-radius:10px;overflow:hidden;margin-bottom:1rem;box-shadow:0 1px 4px rgba(0,0,0,0.04);">
        <div style="background:#1e4d8c;padding:0.5rem 0.9rem;">
          <span style="color:#fff;font-size:0.72rem;font-weight:700;text-transform:uppercase;letter-spacing:0.07em;">Professor Position Requirements</span>
        </div>
        
        <table style="width:100%;border-collapse:collapse;background:#fff;font-size:0.84rem;">
          <thead>
            <tr style="background:#f8fafc;border-bottom:1px solid #e2e8f0;">
              <th style="width:40px;padding:0.7rem 0.75rem;color:#64748b;font-size:0.68rem;font-weight:700;text-transform:uppercase;">#</th>
              <th style="padding:0.7rem 0.75rem;color:#64748b;font-size:0.68rem;font-weight:700;text-transform:uppercase;">Requirement</th>
              <th style="padding:0.7rem 0.75rem;color:#64748b;font-size:0.68rem;font-weight:700;text-transform:uppercase;">Upload</th>
              <th style="padding:0.7rem 0.75rem;color:#64748b;font-size:0.68rem;font-weight:700;text-transform:uppercase;">Status</th>
            </tr>
          </thead>
          <tbody>
            <tr style="border-bottom:1px solid #f1f5f9;">
              <td style="padding:0.75rem;text-align:center;font-weight:700;color:#64748b;">1</td>
              <td style="padding:0.75rem;font-weight:600;color:#1e293b;">CAV Transcript of Records</td>
              <td style="padding:0.75rem;">
                <input type="file" name="cav_file" accept=".pdf,.jpg,.jpeg,.png" style="font-size:0.78rem;">
                <?php if (!empty($pos_data['cav_file'])): ?>
                <div style="margin-top:0.3rem;">
                  <a href="<?= $pos_data['cav_file'] ?>" target="_blank" style="font-size:0.7rem;color:#1e4d8c;"><i class="bi bi-file-earmark"></i> View Current</a>
                </div>
                <?php endif; ?>
              </td>
              <td style="padding:0.75rem;text-align:center;">
                <?php
                $cav_status = $pos_data['cav_transcript'] ?? 'Missing';
                $cav_color = $cav_status === 'Uploaded' ? '#16a34a' : '#dc2626';
                ?>
                <span style="background:<?= $cav_color ?>15;color:<?= $cav_color ?>;padding:0.2rem 0.6rem;border-radius:20px;font-size:0.7rem;font-weight:700;"><?= $cav_status ?></span>
              </td>
            </tr>
            
            <tr>
              <td style="padding:0.75rem;text-align:center;font-weight:700;color:#64748b;">2</td>
              <td style="padding:0.75rem;font-weight:600;color:#1e293b;">
                Internationally Indexed Article
                <div style="font-size:0.75rem;color:#64748b;font-weight:normal;margin-top:0.2rem;">Scopus, WoS, or ACI, published within the last 3 years</div>
              </td>
              <td style="padding:0.75rem;">
                <input type="file" name="article_file" accept=".pdf,.jpg,.jpeg,.png" style="font-size:0.78rem;">
                <?php if (!empty($pos_data['article_file'])): ?>
                <div style="margin-top:0.3rem;">
                  <a href="<?= $pos_data['article_file'] ?>" target="_blank" style="font-size:0.7rem;color:#1e4d8c;"><i class="bi bi-file-earmark"></i> View Current</a>
                </div>
                <?php endif; ?>
              </td>
              <td style="padding:0.75rem;text-align:center;">
                <?php
                $article_status = $pos_data['international_article'] ?? 'Missing';
                $article_color = $article_status === 'Uploaded' ? '#16a34a' : '#dc2626';
                ?>
                <span style="background:<?= $article_color ?>15;color:<?= $article_color ?>;padding:0.2rem 0.6rem;border-radius:20px;font-size:0.7rem;font-weight:700;"><?= $article_status ?></span>
              </td>
            </tr>
          </tbody>
        </table>
      </div>

      <!-- Result Line -->
      <div style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:8px;padding:1rem;margin-bottom:1rem;">
        <div style="font-weight:700;color:#1a3a6b;margin-bottom:0.5rem;">Result:</div>
        <?php
        $all_uploaded = (($pos_data['cav_transcript'] ?? 'Missing') === 'Uploaded' && ($pos_data['international_article'] ?? 'Missing') === 'Uploaded');
        if ($all_uploaded) {
            echo '<div style="color:#16a34a;font-weight:600;font-size:0.95rem;"><i class="bi bi-check-circle-fill me-1"></i>Requirements Complete</div>';
        } else {
            $missing = [];
            if (($pos_data['cav_transcript'] ?? 'Missing') === 'Missing') $missing[] = 'CAV Transcript';
            if (($pos_data['international_article'] ?? 'Missing') === 'Missing') $missing[] = 'International Article';
            echo '<div style="color:#dc2626;font-weight:600;font-size:0.95rem;"><i class="bi bi-x-circle me-1"></i>Requirements Incomplete ... missing: ' . implode(', ', $missing) . '</div>';
        }
        ?>
      </div>

      <?php if (!$locked): ?>
      <button type="submit" class="btn btn-primary" style="border-radius:8px;padding:0.55rem 1.25rem;font-weight:600;">
        <i class="bi bi-upload me-2"></i>Save Requirements
      </button>
      <?php endif; ?>
    </form>

    <?php elseif ($is_univ_prof): ?>
    <!-- University Professor requirements -->
    <form method="POST" enctype="multipart/form-data">
      <input type="hidden" name="kra_action" value="save_position_requirements">
      
      <div style="border:1px solid #e2e8f0;border-radius:10px;overflow:hidden;margin-bottom:1rem;box-shadow:0 1px 4px rgba(0,0,0,0.04);">
        <div style="background:#1e4d8c;padding:0.5rem 0.9rem;">
          <span style="color:#fff;font-size:0.72rem;font-weight:700;text-transform:uppercase;letter-spacing:0.07em;">University Professor Requirements</span>
        </div>
        
        <table style="width:100%;border-collapse:collapse;background:#fff;font-size:0.84rem;">
          <thead>
            <tr style="background:#f8fafc;border-bottom:1px solid #e2e8f0;">
              <th style="width:40px;padding:0.7rem 0.75rem;color:#64748b;font-size:0.68rem;font-weight:700;text-transform:uppercase;">#</th>
              <th style="padding:0.7rem 0.75rem;color:#64748b;font-size:0.68rem;font-weight:700;text-transform:uppercase;">Requirement</th>
              <th style="padding:0.7rem 0.75rem;color:#64748b;font-size:0.68rem;font-weight:700;text-transform:uppercase;">Upload</th>
              <th style="padding:0.7rem 0.75rem;color:#64748b;font-size:0.68rem;font-weight:700;text-transform:uppercase;">Status</th>
            </tr>
          </thead>
          <tbody>
            <tr>
              <td style="padding:0.75rem;text-align:center;font-weight:700;color:#64748b;">1</td>
              <td style="padding:0.75rem;font-weight:600;color:#1e293b;">College/University Professor Certification Form</td>
              <td style="padding:0.75rem;">
                <input type="file" name="cert_file" accept=".pdf,.jpg,.jpeg,.png" style="font-size:0.78rem;">
                <?php if (!empty($pos_data['cert_file'])): ?>
                <div style="margin-top:0.3rem;">
                  <a href="<?= $pos_data['cert_file'] ?>" target="_blank" style="font-size:0.7rem;color:#1e4d8c;"><i class="bi bi-file-earmark"></i> View Current</a>
                </div>
                <?php endif; ?>
              </td>
              <td style="padding:0.75rem;text-align:center;">
                <?php
                $cert_status = $pos_data['certification_form'] ?? 'Missing';
                $cert_color = $cert_status === 'Uploaded' ? '#16a34a' : '#dc2626';
                ?>
                <span style="background:<?= $cert_color ?>15;color:<?= $cert_color ?>;padding:0.2rem 0.6rem;border-radius:20px;font-size:0.7rem;font-weight:700;"><?= $cert_status ?></span>
              </td>
            </tr>
          </tbody>
        </table>
      </div>

      <!-- Result Line -->
      <div style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:8px;padding:1rem;margin-bottom:1rem;">
        <div style="font-weight:700;color:#1a3a6b;margin-bottom:0.5rem;">Result:</div>
        <?php
        $cert_complete = (($pos_data['certification_form'] ?? 'Missing') === 'Uploaded');
        if ($cert_complete) {
            echo '<div style="color:#16a34a;font-weight:600;font-size:0.95rem;"><i class="bi bi-check-circle-fill me-1"></i>Requirements Complete</div>';
        } else {
            echo '<div style="color:#dc2626;font-weight:600;font-size:0.95rem;"><i class="bi bi-x-circle me-1"></i>Requirements Incomplete ... missing: Certification Form</div>';
        }
        ?>
      </div>

      <?php if (!$locked): ?>
      <button type="submit" class="btn btn-primary" style="border-radius:8px;padding:0.55rem 1.25rem;font-weight:600;">
        <i class="bi bi-upload me-2"></i>Save Requirements
      </button>
      <?php endif; ?>
    </form>
    <?php endif; ?>

    <?php else: ?>
    <!-- REGULAR KRA TAB CONTENT (dark-themed criterion-grouped layout) -->
    <?php
    $crit_headers = [
        'Instruction'              => 'Teaching Effectiveness',
        'Research'                 => 'Research, Invention & Creative Work',
        'Extension'                => 'Extension Services',
        'Professional Development' => 'Professional Development',
    ];
    $crit_max = [
        'Instruction'              => 60,
        'Research'                 => 100,
        'Extension'                => 100,
        'Professional Development' => 100,
    ];

    // Map entries to criterion groups using criteria_list labels
    // Build a lookup: criterion_label => [entries]
    $entries_by_crit = [];
    foreach ($cur_entries as $s) {
        $rem = $s['remarks'] ?? '';
        $parts = explode('|||', $rem);
        $matched = false;
        foreach ($criteria_list as $c) {
            // Try to match the stored remarks against criterion label keywords
            $lbl = $c['criterion_label'];
            $critType = $parts[0] ?? '';
            // Match by first segment of remarks against criterion label
            if (stripos($lbl, $critType) !== false || stripos($critType, substr($lbl, 0, 10)) !== false) {
                $entries_by_crit[$lbl][] = $s;
                $matched = true;
                break;
            }
        }
        if (!$matched) {
            $entries_by_crit['Other'][] = $s;
        }
    }

    // Group criteria by Criterion letter (A, B, C, D...)
    $crit_groups = [];
    foreach ($criteria_list as $c) {
        $lbl = $c['criterion_label'];
        // Extract criterion letter from label e.g. "Criterion A..." or just first word
        if (preg_match('/Criterion\s+([A-Z])/i', $lbl, $m)) {
            $letter = strtoupper($m[1]);
        } else {
            $letter = '?';
        }
        if (!isset($crit_groups[$letter])) {
            $crit_groups[$letter] = ['label' => $lbl, 'max' => $c['max_points'], 'items' => []];
        }
        $crit_groups[$letter]['items'][] = $c;
    }

    // Compute criterion totals from submitted entries
    $crit_totals = [];
    foreach ($cur_entries as $s) {
        $rem = $s['remarks'] ?? '';
        $parts = explode('|||', $rem);
        $critType = $parts[0] ?? '';
        foreach ($criteria_list as $c) {
            $lbl = $c['criterion_label'];
            if (preg_match('/Criterion\s+([A-Z])/i', $lbl, $m)) {
                $letter = strtoupper($m[1]);
            } else {
                $letter = '?';
            }
            // Simple heuristic: map critType prefix to letter
            $map = ['A' => ['A-set-sef','A-set-sef-sem','A-org'], 'B' => ['B|','B-material','B-training','B-paper','B-degree'], 'C' => ['C|','C-thesis','C-award'], 'D' => ['D-']];
            foreach ($map as $l => $prefixes) {
                foreach ($prefixes as $p) {
                    if (strpos($critType, $p) === 0 || $critType === $p) {
                        if (!isset($crit_totals[$l])) $crit_totals[$l] = 0;
                        $crit_totals[$l] += (float)$s['computed_points'];
                    }
                }
            }
        }
    }
    ?>

    <?php if (empty($criteria_list)): ?>
    <!-- No criteria configured -->
    <div style="background:#ffffff;border:1px solid #e2e8f0;border-radius:8px;padding:2rem;text-align:center;color:#94a3b8;font-size:0.85rem;">
      <i class="bi bi-inbox" style="font-size:1.5rem;display:block;margin-bottom:0.5rem;color:#cbd5e1;"></i>
      No criteria configured for this KRA. Contact your administrator.
    </div>
    <?php else: ?>

    <?php
    // Group criteria by Criterion letter (A, B, C, D) for the dark blocks
    $dark_groups = [];  // key = letter (A/B/C/D/?), value = ['header'=>string, 'max'=>float, 'items'=>[], '_seen'=>[]]
    foreach ($criteria_list as $c) {
        $lbl = $c['criterion_label'];
        // Extract letter: "Criterion A. Teaching..." or "Criterion B ..."
        if (preg_match('/Criterion\s+([A-Z])[\.:\s]/i', $lbl, $m)) {
            $letter = strtoupper($m[1]);
            // Build a clean header: everything up to the first ( or end
            preg_match('/^(Criterion\s+[A-Z][^(]*)/i', $lbl, $hm);
            $header = trim($hm[1] ?? $lbl);
        } else {
            $letter = '?';
            $header = $lbl;
        }
        if (!isset($dark_groups[$letter])) {
            $dark_groups[$letter] = ['header' => $header, 'max' => 0.0, 'items' => [], '_seen' => []];
        }
        $item_key = trim((string)($c['criterion_key'] ?? '')) ?: trim((string)$lbl);
        if (isset($dark_groups[$letter]['_seen'][$item_key])) {
            continue;
        }
        $dark_groups[$letter]['_seen'][$item_key] = true;
        $dark_groups[$letter]['items'][] = $c;
        $dark_groups[$letter]['max'] += (float)$c['max_points'];
    }

    // Build a flat list of all entries with their evidence
    $entries_with_ev = [];
    foreach ($cur_entries as $s) {
        $ef_stmt2 = $pdo->prepare("SELECT evidence_id, file_path, original_filename FROM kra_evidence_files WHERE submission_id=? ORDER BY uploaded_at ASC");
        $ef_stmt2->execute([$s['submission_id']]);
        $ev_files2 = $ef_stmt2->fetchAll();
        if (empty($ev_files2) && $s['document_path']) {
            $ev_files2 = [['evidence_id'=>0,'file_path'=>$s['document_path'],'original_filename'=>basename($s['document_path'])]];
        }
        $s['_ev_files'] = $ev_files2;
        $entries_with_ev[] = $s;
    }

    // Prefix map used for matching entries to criterion letters (KRA I and KRA IV only).
    // KRA II and KRA III use free-form key names that don't follow A-/B-/C- conventions,
    // so for those KRAs we fall back to showing all entries under the first group.
    $letter_to_prefixes = [
        'A' => ['A-set-sef', 'A-set-sef-sem', 'A-org'],
        'B' => ['B|', 'B-material', 'B-training', 'B-paper', 'B-degree'],
        'C' => ['C|', 'C-thesis', 'C-mentor', 'C-award'],
        'D' => ['D-bonus', 'D-prior-academic', 'D-prior-industry', 'D-industry', 'D-'],
    ];

    // Check if ANY entry matches any known prefix at all (true for KRA I & IV)
    $any_prefix_match = false;
    foreach ($entries_with_ev as $s) {
        $ct = explode('|||', $s['remarks'] ?? '')[0] ?? '';
        foreach ($letter_to_prefixes as $prefixes) {
            foreach ($prefixes as $prefix) {
                if ($prefix === $ct || strpos($ct, rtrim($prefix, '-')) === 0) {
                    $any_prefix_match = true;
                    break 3;
                }
            }
        }
    }

    $first_group_key = array_key_first($dark_groups);
    ?>

    <?php foreach ($dark_groups as $letter => $group): ?>
    <?php
    // Collect entries for this criterion group
    if ($any_prefix_match) {
        // KRA I / KRA IV: match by critType prefix
        $group_entries = [];
        foreach ($entries_with_ev as $s) {
            $critType = explode('|||', $s['remarks'] ?? '')[0] ?? '';
            foreach (($letter_to_prefixes[$letter] ?? []) as $prefix) {
                if ($prefix === $critType || strpos($critType, rtrim($prefix, '-')) === 0) {
                    $group_entries[] = $s;
                    break;
                }
            }
        }
    } else {
        // KRA II / KRA III: no prefix conventions ... show all entries under the first group only
        $group_entries = ($letter === $first_group_key) ? $entries_with_ev : [];
    }

    // Compute group score from the already-collected entries
    $group_score = 0;
    foreach ($group_entries as $s) {
        $group_score += (float)$s['computed_points'];
    }
    if ($cur_cat === 'Instruction' && $letter === 'A') {
        if (!class_exists('\Scoring\KRA1Scorer')) {
            require_once __DIR__ . '/../scoring/kra1_scorer.php';
        }
        $kra1_group_score = \Scoring\KRA1Scorer::score($group_entries);
        $group_score = (float)($kra1_group_score['criterion_a'] ?? $group_score);
    }
    $group_max = $group['max'];
    ?>
    <div style="background:#ffffff;border:1px solid #e2e8f0;border-radius:8px;margin-bottom:1rem;overflow:hidden;">

      <!-- Criterion header -->
      <div style="background:#1e4d8c;padding:0.65rem 1rem;border-bottom:1px solid #e2e8f0;display:flex;justify-content:space-between;align-items:center;">
        <span style="color:#fff;font-weight:700;font-size:0.88rem;"><?= htmlspecialchars($group['header']) ?></span>
        <span style="color:rgba(255,255,255,0.7);font-size:0.75rem;">max <?= $group_max ?> pts</span>
      </div>

      <?php foreach ($group['items'] as $ci): ?>
      <!-- Sub-item: description row -->
      <div style="border-top:1px solid #e2e8f0;">
        <!-- Item header -->
        <div style="padding:0.4rem 1rem;background:#f0f4fb;display:flex;justify-content:space-between;align-items:center;">
          <span style="color:#1a3a6b;font-size:0.82rem;font-weight:600;"><?= htmlspecialchars($ci['criterion_label']) ?></span>
          <span style="background:#dbeafe;color:#1e4d8c;border-radius:4px;padding:0.1rem 0.45rem;font-size:0.68rem;font-weight:700;"><?= $ci['max_points'] ?> pts max</span>
        </div>

        <?php
        // Never show internal developer/admin notes to faculty
        $desc_clean = $ci['description'] ?? '';
        $hide_desc = (
            str_starts_with($desc_clean, 'CONFIRMED SOURCE GAP') ||
            str_starts_with($desc_clean, 'CONFIG_') ||
            str_contains($desc_clean, 'PENDING_DOCUMENTATION') ||
            str_contains($desc_clean, 'JC01 s.2026, Section 15')
        );
        if (!empty($desc_clean) && !$hide_desc): ?>
        <div style="padding:0.25rem 1rem 0.35rem 2rem;color:#64748b;font-size:0.75rem;line-height:1.4;"><?= htmlspecialchars($desc_clean) ?></div>
        <?php endif; ?>
      </div>
      <?php endforeach; // end group items (sub-item descriptions) ?>

      <!-- Column headers for data rows ... shown once per group -->
      <div style="background:#f8fafc;display:grid;grid-template-columns:1fr 80px 110px;padding:0.35rem 0.75rem;border-top:1px solid #e2e8f0;">
        <span style="color:#64748b;font-size:0.68rem;font-weight:700;text-transform:uppercase;letter-spacing:0.04em;">Evidence</span>
        <span style="color:#64748b;font-size:0.68rem;font-weight:700;text-transform:uppercase;letter-spacing:0.04em;text-align:right;">SE</span>
        <span style="color:#64748b;font-size:0.68rem;font-weight:700;text-transform:uppercase;letter-spacing:0.04em;text-align:center;">Actions</span>
      </div>

      <?php if (!empty($group_entries)): ?>
        <?php foreach ($group_entries as $s): ?>
        <div style="background:#ffffff;display:grid;grid-template-columns:1fr 80px 110px;padding:0.4rem 0.75rem;border-top:1px solid #f1f5f9;align-items:center;">
          <!-- Evidence cell -->
          <div style="color:#64748b;font-size:0.78rem;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;padding-right:0.5rem;">
            <?php if (!empty($s['_ev_files'])): ?>
              <?php foreach (array_slice($s['_ev_files'], 0, 1) as $fi): ?>
              <a href="pages/view_file.php?file=<?= urlencode($fi['file_path']) ?>" target="_blank" rel="noopener noreferrer"
                 style="color:#1e4d8c;text-decoration:none;display:inline-flex;align-items:center;gap:3px;font-size:0.75rem;">
                <i class="bi bi-file-earmark" style="flex-shrink:0;"></i>
                <span style="overflow:hidden;text-overflow:ellipsis;white-space:nowrap;"><?= htmlspecialchars(substr($fi['original_filename'], 0, 20) . (strlen($fi['original_filename']) > 20 ? '...' : '')) ?></span>
              </a>
              <?php if (count($s['_ev_files']) > 1): ?>
              <span style="color:#475569;font-size:0.68rem;margin-left:4px;">+<?= count($s['_ev_files']) - 1 ?> more</span>
              <?php endif; ?>
              <?php endforeach; ?>
            <?php else: ?>
              <span style="color:#cbd5e1;font-style:italic;">No file</span>
            <?php endif; ?>
          </div>
          <!-- SE (score) cell -->
          <div style="text-align:right;color:#1e293b;font-weight:700;font-size:0.85rem;">
            <?= number_format((float)$s['computed_points'], 2) ?>
            <?php if ($s['verified']): ?>
            <i class="bi bi-check-circle-fill" style="color:#22c55e;font-size:0.65rem;margin-left:2px;" title="Verified"></i>
            <?php endif; ?>
          </div>
          <!-- Actions cell -->
          <div style="text-align:center;display:flex;gap:0.3rem;justify-content:center;">
            <?php
            $ev_files_for_btn = array_map(fn($f) => [
                'evidence_id'     => $f['evidence_id'],
                'file_path'       => $f['file_path'],
                'original_filename' => $f['original_filename'],
            ], $s['_ev_files'] ?? []);
            $entry_json = htmlspecialchars(json_encode([
                'submission_id'   => $s['submission_id'],
                'computed_points' => $s['computed_points'],
                'remarks'         => $s['remarks'],
                'document_path'   => $s['document_path'] ?? '',
                'verified'        => $s['verified'] ?? 0,
                'evidence_files'  => $ev_files_for_btn,
            ]), ENT_QUOTES);
            // Determine if this specific entry can be edited/deleted
            $entry_needs_revision = (($s['revision_status'] ?? '') === 'needs_revision');
            $entry_revision_ok = ($app['status'] !== 'needs_revision') || $entry_needs_revision;
            $entry_verified_lock = (!empty($s['verified']) && !$entry_needs_revision);
            $show_actions = $can_edit && $entry_revision_ok && !$entry_verified_lock;
            ?>
            <?php if ($show_actions): ?>
            <button type="button" onclick="openEditModal(<?= $s['submission_id'] ?>)"
                    data-entry="<?= $entry_json ?>"
                    id="editBtn_<?= $s['submission_id'] ?>"
                    style="border:1px solid #e2e8f0;background:#f8fafc;color:#1e293b;border-radius:4px;padding:0.25rem 0.7rem;font-size:0.75rem;cursor:pointer;white-space:nowrap;display:inline-flex;align-items:center;gap:0.3rem;">
              <i class="bi bi-pencil" style="font-size:0.65rem;"></i> Edit
            </button>
            <?php if (!$locked): ?>
            <form method="POST" id="delEntry_<?= $s['submission_id'] ?>" style="display:inline;">
              <input type="hidden" name="kra_action" value="delete_kra">
              <input type="hidden" name="submission_id" value="<?= $s['submission_id'] ?>">
              <button type="button"
                      onclick="rememberKraEntryScroll(); confirmDelete('Remove this entry?', 'delEntry_<?= $s['submission_id'] ?>', 'Remove', 'bi-trash')"
                      style="border:1px solid #fecaca;background:#fef2f2;color:#dc2626;border-radius:4px;padding:0.25rem 0.45rem;font-size:0.75rem;cursor:pointer;">
                <i class="bi bi-trash"></i>
              </button>
            </form>
            <?php endif; ?>
            <?php else: ?>
            <span style="color:#cbd5e1;font-size:0.72rem;font-style:italic;">
              <?php if ($locked): ?>
                Locked
              <?php elseif ($entry_verified_lock): ?>
                <i class="bi bi-patch-check-fill" style="color:#22c55e;" title="Verified by checker"></i> Verified
              <?php elseif ($app['status'] === 'needs_revision' && ($s['revision_status'] ?? '') !== 'needs_revision'): ?>
                <i class="bi bi-check-circle" style="color:#94a3b8;" title="No revision required for this entry"></i>
              <?php else: ?>
                Submitted
              <?php endif; ?>
            </span>
            <?php endif; ?>
          </div>
        </div>
        <?php endforeach; // end group_entries ?>
      <?php else: ?>
      <!-- Empty row placeholder with Data Entry button -->
      <?php if ($can_edit): ?>
      <div style="background:#f8fafc;display:grid;grid-template-columns:1fr 80px 110px;padding:0.4rem 0.75rem;border-top:1px solid #e2e8f0;align-items:center;">
        <div style="color:#94a3b8;font-size:0.75rem;font-style:italic;">No entries yet</div>
        <div style="text-align:right;color:#94a3b8;font-weight:700;font-size:0.85rem;">&mdash;</div>
        <div style="text-align:center;">
          <button type="button"
                  onclick="openCriterionEntry('<?= htmlspecialchars($letter, ENT_QUOTES) ?>')"
                  style="border:1px solid #1e4d8c;background:#fff;color:#1e4d8c;border-radius:4px;padding:0.25rem 0.7rem;font-size:0.75rem;cursor:pointer;white-space:nowrap;">
            <i class="bi bi-plus" style="font-size:0.65rem;"></i> Add Entry
          </button>
        </div>
      </div>
      <?php endif; ?>
      <?php endif; // end group_entries check ?>

      <!-- Criterion total row -->
      <div style="background:#f0f4fb;padding:0.5rem 1rem;text-align:right;color:#64748b;font-size:0.8rem;border-top:1px solid #e2e8f0;">
        Total criterion: &nbsp;
        <span style="color:#1a3a6b;font-weight:700;"><?= number_format($group_score, 2) ?></span>
        &nbsp;/&nbsp;
        <span><?= number_format($group_max, 2) ?></span>
      </div>

    </div>
    <?php endforeach; // end dark_groups ?>

    <?php endif; // end empty criteria_list check ?>

    <!-- Bottom grand total bar -->
    <?php
    $faculty_rank_step2 = $faculty['rank'] ?? '';
    $score_step2   = computeWeightedScore($kra_totals2, $faculty_rank_step2);
    $weighted2     = $score_step2['weighted_score'];
    $inc2          = $score_step2['sub_rank_increment'];
    $weights2      = $score_step2['weights'];
    $kra_weighted2 = [
        'Instruction'              => round($score_step2['kra1'] * $weights2['Instruction'], 2),
        'Research'                 => round($score_step2['kra2'] * $weights2['Research'], 2),
        'Extension'                => round($score_step2['kra3'] * $weights2['Extension'], 2),
        'Professional Development' => round($score_step2['kra4'] * $weights2['Professional Development'], 2),
    ];
    ?>
    <div style="background:#1a3a6b;border-radius:0 0 8px 8px;padding:0.6rem 1rem;display:flex;justify-content:flex-end;align-items:center;gap:1.5rem;margin-top:0.5rem;flex-wrap:wrap;">
      <div style="color:#fff;font-weight:700;font-size:0.9rem;white-space:nowrap;">
        Grand Total: <span style="color:#fff;font-size:1rem;font-weight:800;"><?= number_format($weighted2, 2) ?></span>
        <?php if ($inc2 > 0): ?>
        <span style="background:rgba(255,255,255,0.15);color:#fff;border-radius:20px;padding:0.1rem 0.5rem;font-size:0.65rem;font-weight:700;margin-left:0.4rem;">+<?= $inc2 ?> sub-rank<?= $inc2 > 1 ? 's' : '' ?></span>
        <?php endif; ?>
      </div>
    </div>

    <?php endif; // End of regular KRA content ?>
  </div><!-- end tab content dark bg -->
</div><!-- end neon-card -->


<!-- KRA ENTRY MODAL -->
<div class="modal fade" id="kraEntryModal" tabindex="-1">
  <div class="modal-dialog modal-fullscreen-lg-down modal-dialog-centered modal-dialog-scrollable" style="width:96vw;max-width:96vw;">
    <div class="modal-content" style="border-radius:14px;overflow:hidden;border:none;box-shadow:0 24px 60px rgba(0,0,0,0.18);">

      <!-- Modal Header -->
      <div class="modal-header" style="background:linear-gradient(135deg,#1a3a6b,#1e4d8c);border:none;padding:1.1rem 1.5rem;">
        <div class="d-flex align-items-center gap-3 flex-grow-1 min-w-0">
          <?php
          $kra_titles = [
              'Instruction'              => ['title' => 'KRA I ... Teaching Effectiveness',             'max' => 100, 'icon' => 'bi-book'],
              'Research'                 => ['title' => 'KRA II ... Research, Invention & Creative Work','max' => 100, 'icon' => 'bi-lightbulb'],
              'Extension'                => ['title' => 'KRA III ... Extension Services',               'max' => 100, 'icon' => 'bi-people'],
              'Professional Development' => ['title' => 'KRA IV ... Professional Development',          'max' => 100, 'icon' => 'bi-mortarboard'],
          ];
          $kra_info_modal = $kra_titles[$cur_cat] ?? ['title' => $cur_cat, 'max' => 100, 'icon' => 'bi-table'];
          ?>
          <div style="width:36px;height:36px;border-radius:8px;background:rgba(255,255,255,0.12);
                      display:flex;align-items:center;justify-content:center;flex-shrink:0;">
            <i class="bi <?= $kra_info_modal['icon'] ?>" style="color:#e2e8f0;font-size:1rem;"></i>
          </div>
          <div class="min-w-0">
            <div style="color:#fff;font-weight:700;font-size:0.95rem;line-height:1.2;">
              <?= htmlspecialchars($kra_info_modal['title']) ?>
            </div>
            <div style="color:rgba(255,255,255,0.55);font-size:0.72rem;margin-top:2px;">
              Max <?= $kra_info_modal['max'] ?> pts &nbsp;&middot;&nbsp; Entries save automatically
            </div>
          </div>
        </div>
        <button type="button" class="btn-close btn-close-white ms-3" data-bs-dismiss="modal"
                style="opacity:0.7;transition:opacity 0.15s;" onmouseover="this.style.opacity='1'" onmouseout="this.style.opacity='0.7'"></button>
      </div>

      <!-- Modal Body -->
      <div class="modal-body p-0" style="background:#f8fafc;">
        <div class="table-responsive">
          <table class="kra-entry-table kra-entry-table-<?= strtolower(str_replace(' ', '-', $cur_cat)) ?>" style="width:100%;border-collapse:collapse;font-size:0.82rem;" id="kraTable">
            <?php if ($cur_cat==='Research'): ?>
            <colgroup>
              <col style="width:56px;">
              <col style="width:48px;">
              <col>
              <col style="width:118px;">
              <col style="width:106px;">
              <col style="width:92px;">
              <col style="width:112px;">
            </colgroup>
            <?php elseif ($cur_cat==='Extension'): ?>
            <colgroup>
              <col style="width:56px;">
              <col style="width:48px;">
              <col>
              <col>
              <col style="width:106px;">
              <col style="width:92px;">
              <col style="width:112px;">
            </colgroup>
            <?php else: ?>
            <colgroup>
              <col style="width:56px;">
              <col style="width:48px;">
              <col>
              <col>
              <col style="width:118px;">
              <col style="width:106px;">
              <col style="width:92px;">
              <col style="width:112px;">
            </colgroup>
            <?php endif; ?>
            <thead>
              <tr style="background:#1e4d8c;">
                <th style="width:56px;padding:0.7rem 1rem;">
                  <button type="button" onclick="addKraRow()" title="Add new row"
                          style="width:30px;height:30px;padding:0;background:#1e4d8c;border:none;border-radius:6px;
                                 color:#fff;font-size:1.1rem;font-weight:700;cursor:pointer;
                                 display:flex;align-items:center;justify-content:center;
                                 transition:background 0.15s;position:relative;z-index:10;"
                          onmouseover="this.style.background='#1a3a6b'" onmouseout="this.style.background='#1e4d8c'">
                    <i class="bi bi-plus-lg"></i>
                  </button>
                </th>
                <th style="padding:0.7rem 0.5rem;color:rgba(255,255,255,0.65);font-size:0.68rem;font-weight:700;letter-spacing:0.06em;text-transform:uppercase;">#</th>
                <?php if ($cur_cat==='Research'): ?>
                <th style="padding:0.7rem 0.75rem;color:rgba(255,255,255,0.65);font-size:0.68rem;font-weight:700;letter-spacing:0.06em;text-transform:uppercase;">Type / Details</th>
                <th style="padding:0.7rem 0.75rem;color:rgba(255,255,255,0.65);font-size:0.68rem;font-weight:700;letter-spacing:0.06em;text-transform:uppercase;width:110px;">Contribution</th>
                <?php elseif ($cur_cat==='Extension'): ?>
                <th style="padding:0.7rem 0.75rem;color:rgba(255,255,255,0.65);font-size:0.68rem;font-weight:700;letter-spacing:0.06em;text-transform:uppercase;min-width:160px;">Criterion / Type</th>
                <th style="padding:0.7rem 0.75rem;color:rgba(255,255,255,0.65);font-size:0.68rem;font-weight:700;letter-spacing:0.06em;text-transform:uppercase;min-width:200px;">Details</th>
                <?php elseif ($cur_cat==='Professional Development'): ?>
                <th style="padding:0.7rem 0.75rem;color:rgba(255,255,255,0.65);font-size:0.68rem;font-weight:700;letter-spacing:0.06em;text-transform:uppercase;min-width:160px;">Criterion</th>
                <th style="padding:0.7rem 0.75rem;color:rgba(255,255,255,0.65);font-size:0.68rem;font-weight:700;letter-spacing:0.06em;text-transform:uppercase;min-width:200px;">Description / Details</th>
                <th style="padding:0.7rem 0.75rem;color:rgba(255,255,255,0.65);font-size:0.68rem;font-weight:700;letter-spacing:0.06em;text-transform:uppercase;width:110px;">Sub-value</th>
                <?php else: ?>
                <th style="padding:0.7rem 0.75rem;color:rgba(255,255,255,0.65);font-size:0.68rem;font-weight:700;letter-spacing:0.06em;text-transform:uppercase;min-width:140px;">Criterion</th>
                <th style="padding:0.7rem 0.75rem;color:rgba(255,255,255,0.65);font-size:0.68rem;font-weight:700;letter-spacing:0.06em;text-transform:uppercase;min-width:200px;">Details</th>
                <th style="padding:0.7rem 0.75rem;color:rgba(255,255,255,0.65);font-size:0.68rem;font-weight:700;letter-spacing:0.06em;text-transform:uppercase;width:110px;">Sub-value</th>
                <?php endif; ?>
                <th style="padding:0.7rem 0.75rem;color:rgba(255,255,255,0.65);font-size:0.68rem;font-weight:700;letter-spacing:0.06em;text-transform:uppercase;width:90px;text-align:center;"><?= $cur_cat==='Research' ? 'Faculty Score' : 'Score' ?></th>
                <th style="padding:0.7rem 0.75rem;color:rgba(255,255,255,0.65);font-size:0.68rem;font-weight:700;letter-spacing:0.06em;text-transform:uppercase;width:90px;text-align:center;">Evidence</th>
                <th style="width:112px;text-align:center;"></th>
              </tr>
            </thead>
            <tbody id="kraTableBody"></tbody>
            <tfoot>
              <tr style="background:#eff6ff;border-top:2px solid #1a3a6b;">
                <td colspan="<?= $cur_cat==='Research'?4:($cur_cat==='Extension'?4:5) ?>"
                    style="padding:0.75rem 1rem;font-size:0.78rem;font-weight:700;color:#1a3a6b;">
                  TOTAL
                  <span style="font-weight:400;color:#64748b;margin-left:6px;font-size:0.72rem;">
                    <?= $cur_cat === 'Extension' ? '(A+B+C max 100 + Criterion D bonus max 20)' : ($cur_cat === 'Instruction' ? '(capped at 60)' : '(capped at 100)') ?>
                  </span>
                </td>
                <td style="padding:0.75rem;text-align:center;font-weight:800;color:#1e4d8c;font-size:1.15rem;" id="kraGrandTotalDefault">0.00</td>
                <td colspan="2"></td>
              </tr>
            </tfoot>
          </table>
        </div>
      </div>

      <!-- Modal Footer -->
      <div class="modal-footer" style="background:#fff;border-top:1px solid #e2e8f0;padding:0.75rem 1.25rem;justify-content:space-between;align-items:center;">
        <span style="font-size:0.75rem;color:#94a3b8;display:flex;align-items:center;gap:0.4rem;">
          <i class="bi bi-info-circle" style="color:#475569;"></i>
          Fill fields &amp; upload evidence ... row saves automatically
        </span>
        <button type="button" id="doneBtn" onclick="handleDone()"
                style="background:#1e4d8c;color:#fff;border:none;border-radius:8px;
                       padding:0.45rem 1.25rem;font-size:0.85rem;font-weight:600;cursor:pointer;
                       display:flex;align-items:center;gap:0.4rem;transition:background 0.15s;"
                onmouseover="this.style.background='#1a3a6b'" onmouseout="this.style.background='#1e4d8c'">
          <i class="bi bi-check2-circle"></i>Done
        </button>
      </div>

      <!-- Inline alert area -->
      <div id="kraAlertBox" style="display:none;background:#f8fafc;border-top:2px solid #475569;
                padding:0.85rem 1.25rem;align-items:flex-start;gap:0.75rem;">
        <i class="bi bi-exclamation-triangle-fill" style="color:#475569;font-size:1rem;flex-shrink:0;margin-top:2px;"></i>
        <span id="kraAlertMsg" style="font-size:0.82rem;color:#334155;line-height:1.6;"></span>
      </div>
    </div>
  </div>
</div>

<!-- Upload Sub-Modal -->
<div class="modal fade" id="uploadModal" tabindex="-1" style="z-index:10060;">
  <div class="modal-dialog modal-dialog-centered" style="max-width:520px;">
    <div class="modal-content" style="border-radius:10px;">
      <div class="modal-header" style="background:#1e4d8c;border:none;">
        <h6 class="modal-title text-white fw-bold"><i class="bi bi-cloud-upload me-2"></i>Upload Evidence Files</h6>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <div id="uploadContext" class="mb-2 p-2 rounded" style="background:#f0f4fb;font-size:0.8rem;color:#1a3a6b;"></div>
        <div id="uploadCapInfo" class="mb-3 p-2 rounded d-flex align-items-center gap-2" style="background:#f0f4fb;font-size:0.8rem;color:#1a3a6b;border:1px solid #93c5fd;">
          <i class="bi bi-info-circle-fill"></i>
          <span id="uploadCapText">0 / 10 files uploaded</span>
        </div>

        <!-- Drop zone -->
        <div id="uploadDropZone" style="border:2px dashed #cbd5e1;border-radius:10px;padding:1.5rem;text-align:center;cursor:pointer;transition:all 0.2s;background:#f8fafc;"
             onclick="document.getElementById('uploadFileInput').click()"
             ondragover="event.preventDefault();this.style.borderColor='#1a3a6b';this.style.background='#f0f4fb';"
             ondragleave="this.style.borderColor='#cbd5e1';this.style.background='#f8fafc';"
             ondrop="handleDrop(event)">
          <i class="bi bi-cloud-arrow-up" style="font-size:2rem;color:#94a3b8;" id="uploadDropIcon"></i>
          <p class="mb-1 mt-2" style="font-size:0.85rem;color:#475569;" id="uploadDropText">Click or drag files here</p>
          <small class="text-muted">PDF, JPG, PNG &mdash; Max 50MB each &mdash; Multiple files allowed</small>
          <input type="file" id="uploadFileInput" class="d-none" accept=".pdf,.jpg,.jpeg,.png" multiple onchange="handleFileSelect(this)">
        </div>

        <!-- Upload progress -->
        <div id="uploadProgress" class="mt-3" style="display:none;">
          <div class="d-flex align-items-center gap-2 mb-1">
            <div class="spinner-border spinner-border-sm text-primary"></div>
            <span style="font-size:0.82rem;color:#475569;">Uploading...</span>
          </div>
          <div class="progress" style="height:6px;">
            <div class="progress-bar progress-bar-striped progress-bar-animated" style="width:100%;"></div>
          </div>
        </div>

        <!-- File list -->
        <div class="mt-3">
          <div class="d-flex justify-content-between align-items-center mb-1">
            <span style="font-size:0.8rem;font-weight:600;color:#374151;">Uploaded Files</span>
            <span id="uploadFileCount" class="badge bg-primary">0</span>
          </div>
          <table style="width:100%;border-collapse:collapse;font-size:0.8rem;">
            <thead style="background:#f1f5f9;">
              <tr>
                <th style="padding:0.4rem 0.6rem;width:30px;">#</th>
                <th style="padding:0.4rem 0.6rem;">File</th>
                <th style="padding:0.4rem 0.6rem;width:50px;text-align:center;">View</th>
                <th style="padding:0.4rem 0.6rem;width:50px;text-align:center;">Del</th>
              </tr>
            </thead>
            <tbody id="uploadFileList">
              <tr><td colspan="4" class="text-center text-muted py-2">No files uploaded</td></tr>
            </tbody>
          </table>
        </div>
      </div>
      <div class="modal-footer py-2">
        <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Close</button>
      </div>
    </div>
  </div>
</div>

<!-- Submit Confirm Modal -->
<div id="submitConfirmModal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,0.5);z-index:99999;align-items:center;justify-content:center;">
  <div id="submitModalBox" style="background:#fff;border-radius:0;width:100%;max-width:420px;margin:1rem;box-shadow:0 20px 60px rgba(0,0,0,0.2);overflow:hidden;">

    <!-- Header -->
    <div style="background:#1e4d8c;padding:1.25rem 1.5rem;display:flex;align-items:center;gap:0.875rem;">
      <img src="assets/images/logo.jpg" alt="SUCFRMS Logo"
           style="width:40px;height:40px;border-radius:50%;object-fit:cover;border:2px solid #475569;clip-path:circle(50%);flex-shrink:0;">
      <div>
        <div style="color:#fff;font-weight:700;font-size:0.95rem;">SUCFRMS</div>
        <div style="color:#bfdbfe;font-size:0.75rem;margin-top:2px;">SUC Faculty Reclassification Management System</div>
      </div>
    </div>

    <!-- Confirm State -->
    <div id="submitStateConfirm">
      <div style="padding:2rem 1.75rem 1.5rem;">
        <h5 style="font-weight:700;color:#1e293b;margin-bottom:0.5rem;font-size:1.1rem;">Submit Application</h5>
        <p style="color:#64748b;font-size:0.875rem;margin:0;line-height:1.6;">
          Are you sure you want to submit your application for checker review?
        </p>
      </div>
      <div style="height:1px;background:#e2e8f0;"></div>
      <div style="padding:1.25rem 1.75rem 1.75rem;display:flex;gap:0.75rem;">
        <button onclick="document.getElementById('submitConfirmModal').style.display='none'"
                style="flex:1;padding:0.65rem 1rem;border:1px solid #cbd5e1;border-radius:0 !important;background:#fff;color:#475569;font-weight:600;cursor:pointer;font-size:0.875rem;">
          Cancel
        </button>
        <button onclick="doSubmitWithAnimation()"
                style="flex:1;padding:0.65rem 1rem;border:none;border-radius:0 !important;background:#1e4d8c;color:#fff;font-weight:600;cursor:pointer;font-size:0.875rem;">
          Confirm &amp; Submit
        </button>
      </div>
    </div>

    <!-- Submitting State -->
    <div id="submitStateProgress" style="display:none;padding:2.5rem 1.75rem;text-align:center;">
      <div style="width:72px;height:72px;background:#f0f4fb;display:flex;align-items:center;justify-content:center;margin:0 auto 1.5rem;border-radius:50%;overflow:hidden;">
        <svg id="submitCheckSvg" viewBox="0 0 52 52" width="52" height="52" style="display:block;">
          <circle cx="26" cy="26" r="24" fill="#e8edf5"/>
          <circle id="submitCircle" cx="26" cy="26" r="24" fill="none" stroke="#1a3a6b" stroke-width="4"
                  stroke-dasharray="151" stroke-dashoffset="151"
                  stroke-linecap="round"
                  style="transform:rotate(-90deg);transform-origin:center;transition:stroke-dashoffset 0.6s cubic-bezier(0.4,0,0.2,1);"/>
          <polyline id="submitCheck" points="14,27 22,35 38,19" fill="none" stroke="#1a3a6b" stroke-width="3.5"
                    stroke-linecap="round" stroke-linejoin="round"
                    stroke-dasharray="32" stroke-dashoffset="32"
                    style="transition:stroke-dashoffset 0.35s ease 0.55s;"/>
        </svg>
      </div>
      <h5 style="font-weight:700;color:#1e293b;margin-bottom:0.5rem;font-size:1.1rem;">Submitting Application</h5>
      <p style="color:#64748b;font-size:0.85rem;margin:0 0 1.5rem;">Please wait while your application is being processed...</p>
      <div style="background:#e2e8f0;border-radius:0;height:4px;overflow:hidden;">
        <div id="submitProgressBar" style="height:100%;width:0%;background:#1e4d8c;transition:width 1.2s cubic-bezier(0.4,0,0.2,1);"></div>
      </div>
    </div>

  </div>
</div>


<script>
function doSubmitWithAnimation() {
    // Switch to progress state
    document.getElementById('submitStateConfirm').style.display = 'none';
    document.getElementById('submitStateProgress').style.display = 'block';

    // Animate SVG circle drawing
    setTimeout(() => {
        document.getElementById('submitCircle').style.strokeDashoffset = '0';
        document.getElementById('submitProgressBar').style.width = '85%';
    }, 50);

    // After circle completes (~0.6s), draw the checkmark
    // (checkmark transition delay is already 0.55s via CSS)
    setTimeout(() => {
        document.getElementById('submitCheck').style.strokeDashoffset = '0';
    }, 100);

    // Complete progress bar and submit
    setTimeout(() => {
        document.getElementById('submitProgressBar').style.width = '100%';
        document.getElementById('submitProgressBar').style.transition = 'width 0.3s ease';
        setTimeout(() => {
            document.getElementById('submitAppForm').submit();
        }, 350);
    }, 1200);
}

function openSubmitModal() {
    // Reset SVG
    document.getElementById('submitCircle').style.strokeDashoffset = '151';
    document.getElementById('submitCheck').style.strokeDashoffset = '32';
    document.getElementById('submitProgressBar').style.width = '0%';
    document.getElementById('submitProgressBar').style.transition = 'width 1.2s cubic-bezier(0.4,0,0.2,1)';
    document.getElementById('submitStateConfirm').style.display = 'block';
    document.getElementById('submitStateProgress').style.display = 'none';
    document.getElementById('submitModalBox').style.animation = 'none';
    void document.getElementById('submitModalBox').offsetWidth;
    document.getElementById('submitModalBox').style.animation = 'modalFadeIn 0.2s ease forwards';
    document.getElementById('submitConfirmModal').style.display = 'flex';
}

document.getElementById('submitConfirmModal').addEventListener('click', function(e) {
    if (e.target === this) this.style.display = 'none';
});
</script>


<script>
const KRA_CAT    = '<?= addslashes($cur_cat) ?>';
const APP_ID     = <?= $app_id ?>;

// -- Upload success animation ----------------------------------
function showUploadSuccess(filename) {
    const overlay = document.createElement('div');
    overlay.className = 'upload-success-overlay';
    overlay.innerHTML = `
        <div class="upload-success-box" id="successBox">
            <svg class="checkmark-svg" width="64" height="64" viewBox="0 0 52 52">
                <circle cx="26" cy="26" r="24"/>
                <path d="M14 26 l8 8 l16-16"/>
            </svg>
            <div class="upload-success-label">File Uploaded!</div>
            <div class="upload-success-sub">${filename ? filename : 'Evidence saved successfully'}</div>
        </div>`;
    document.body.appendChild(overlay);
    setTimeout(() => {
        const box = overlay.querySelector('#successBox');
        if (box) box.classList.add('fade-out');
        setTimeout(() => overlay.remove(), 380);
    }, 1800);
}

// -- Done button validation ------------------------------------
async function handleDone() {
    const tbody = document.getElementById('kraTableBody');
    if (!tbody) { window.location.href = 'index.php?page=apply&tab=' + ACTIVE_TAB; return; }

    const rows = tbody.querySelectorAll('tr[data-sid]');
    const issues = [];

    if (isCriteriaATableMode()) {
        const aRows = tbody.querySelectorAll('tr.kra-a-row');
        aRows.forEach((tr, idx) => {
            const rowNum = idx + 1;
            const period = tr.querySelector('.kra-a-period')?.value.trim();
            if (!period) issues.push(`Row ${rowNum}: Evaluation period is required.`);
            [1, 2].forEach(sem => {
                const setEl = tr.querySelector(`.kra-a-set.sem-${sem}`);
                const sefEl = tr.querySelector(`.kra-a-sef.sem-${sem}`);
                const upBtn = tr.querySelector(`[data-sem="${sem}"]`);
                const semName = sem === 1 ? '1st Semester' : '2nd Semester';
                const existingSid = parseInt(upBtn?.dataset.sid || '0') > 0;
                const hasEvidence = upBtn?.classList.contains('has-file');
                const setVal = setEl?.value.trim() || '';
                const sefVal = sefEl?.value.trim() || '';
                // 2nd semester is optional — only validate if user typed something or a record already exists
                const semHasData = sem === 1 || existingSid || hasEvidence || setVal !== '' || sefVal !== '';
                if (!semHasData) return; // skip empty optional 2nd semester
                if (!setVal) issues.push(`Row ${rowNum}: ${semName} SET score is required.`);
                if (!sefVal) issues.push(`Row ${rowNum}: ${semName} SEF score is required.`);
                if (!hasEvidence) issues.push(`Row ${rowNum}: ${semName} evidence file is required.`);
            });
        });
    }

    rows.forEach((tr, idx) => {
        const rowNum = idx + 1;
        const sid = tr.dataset.sid;

        // Check evidence uploaded
        const upBtn = tr.querySelector('[onclick*="openUploadModal"]');
        const hasFile = upBtn && upBtn.classList.contains('has-file');
        if (!hasFile) {
            issues.push(`Row ${rowNum}: Missing evidence file.`);
        }

        // Check required fields are filled
        // Criterion selector (all KRAs have one)
        const critSel = tr.querySelector('.ri-crit, .rtype, .rpd-crit, .re-crit');
        if (critSel && !critSel.value) {
            issues.push(`Row ${rowNum}: Please select a criterion.`);
        }

        // Extension: title/designation name required
        const extTitleEl = tr.querySelector('.re-title');
        if (extTitleEl && !extTitleEl.value.trim()) {
            issues.push(`Row ${rowNum}: Activity / designation name is required.`);
        }

        // For B-material and C-thesis: sub-dropdown must also be selected
        const subSel = tr.querySelector('.ri-d1-sel');
        if (subSel && !subSel.value) {
            issues.push(`Row ${rowNum}: Please select a material/role type from the details dropdown.`);
        }

        // Research: developers required
        const devEl = tr.querySelector('.rdev');
        if (devEl && !devEl.value.trim()) {
            issues.push(`Row ${rowNum}: Developer(s) is required.`);
        }

        // Prof Dev: description required
        const descEl = tr.querySelector('.rpd-desc');
        if (descEl && !descEl.value.trim()) {
            issues.push(`Row ${rowNum}: Description / title is required.`);
        }

        // Instruction A: SET and SEF required
        const setEl = tr.querySelector('.ri-d1[placeholder*="SET"]');
        const sefEl = tr.querySelector('.ri-d2[placeholder*="SEF"]');
        if (setEl && !setEl.value.trim()) issues.push(`Row ${rowNum}: SET average is required.`);
        if (sefEl && !sefEl.value.trim()) issues.push(`Row ${rowNum}: SEF average is required.`);
    });

    if (issues.length > 0) {
        // Shake the Done button and switch to warning style
        const btn = document.getElementById('doneBtn');
        btn.style.background = '#475569';
        btn.innerHTML = '<i class="bi bi-exclamation-triangle"></i>Fix issues first';
        btn.classList.add('btn-shake');
        setTimeout(() => btn.classList.remove('btn-shake'), 400);
        setTimeout(() => {
            btn.style.background = '#1e4d8c';
            btn.innerHTML = '<i class="bi bi-check2-circle"></i>Done';
        }, 2500);

        // Group issues by row number for a cleaner display
        const grouped = {};
        issues.forEach(msg => {
            const m = msg.match(/^Row (\d+): (.+)$/);
            if (m) {
                const row = 'Row ' + m[1];
                if (!grouped[row]) grouped[row] = [];
                grouped[row].push(m[2]);
            } else {
                if (!grouped['General']) grouped['General'] = [];
                grouped['General'].push(msg);
            }
        });
        let html = '<div style="font-weight:700;margin-bottom:0.4rem;">Please fix the following before closing:</div>';
        Object.entries(grouped).forEach(([row, msgs]) => {
            html += `<div style="margin-bottom:0.25rem;">
                <span style="font-weight:600;color:#475569;">${row}</span>
                <ul style="margin:2px 0 0 1rem;padding:0;list-style:disc;">
                    ${msgs.map(m => `<li style="color:#334155;">${m}</li>`).join('')}
                </ul>
            </div>`;
        });
        showKraAlert(html);
        return;
    }

    if (isCriteriaATableMode()) {
        try {
            // "Done" must persist every completed semester before navigating away,
            // otherwise typed ratings would be discarded on reload. persistCriteriaARow
            // writes each semester as its own record, so neither one clears the other.
            for (const tr of tbody.querySelectorAll('tr.kra-a-row')) {
                await persistCriteriaARow(tr);
            }
        } catch (err) {
            showKraAlert(esc(err.message || 'Unable to save Criteria A entries.'));
            return;
        }
    } else {
        try {
            for (const tr of rows) {
                await persistGenericKraRow(tr);
            }
        } catch (err) {
            showKraAlert(esc(err.message || 'Unable to save this row.'));
            return;
        }
    }

    // All good &mdash; close modal and reload staying on current tab
    const modal = bootstrap.Modal.getInstance(document.getElementById('kraEntryModal'));
    if (modal) modal.hide();
    window.location.href = 'index.php?page=apply&tab=' + ACTIVE_TAB;
}

const ACTIVE_TAB  = '<?= $active_tab ?>';
const AJAX_URL    = 'includes/apply/kra_ajax.php';
const SCORE_URL   = 'index.php?page=apply&ajax_score=1&app_id=<?= $app_id ?>';
const EDIT_SID    = <?= intval($_GET['edit_sid'] ?? 0) ?>;
<?php
$cjs = [];
foreach ($criteria_list as $c) $cjs[] = ['label'=>$c['criterion_label'],'pts'=>(float)$c['max_points']];
echo 'const CRIT = ' . json_encode($cjs) . ';';
?>

function kraEntryScrollStorageKey() {
    return `sucfrms.kra_entry.scroll.${APP_ID}.${ACTIVE_TAB}`;
}

function rememberKraEntryScroll() {
    try {
        sessionStorage.setItem(kraEntryScrollStorageKey(), JSON.stringify({
            x: window.scrollX || window.pageXOffset || 0,
            y: window.scrollY || window.pageYOffset || 0,
            t: Date.now()
        }));
    } catch (_) {}
}

function restoreKraEntryScroll() {
    try {
        const key = kraEntryScrollStorageKey();
        const saved = JSON.parse(sessionStorage.getItem(key) || 'null');
        sessionStorage.removeItem(key);
        if (!saved || Date.now() - saved.t > 30000) return;
        const restore = () => window.scrollTo(saved.x || 0, saved.y || 0);
        [0, 50, 150, 350, 700, 1200].forEach(delay => setTimeout(restore, delay));
        requestAnimationFrame(() => requestAnimationFrame(restore));
    } catch (_) {}
}

restoreKraEntryScroll();

// -- Live sidebar score refresh --------------------------------
function refreshSidebarScore() {
    fetch(SCORE_URL)
        .then(r => r.json())
        .then(data => {
            // Weighted score
            const ws = data.weighted ?? 0;
            const el = document.getElementById('sidebarWeightedScore');
            if (el) el.textContent = ws.toFixed(2);
            const bar = document.getElementById('sidebarScoreBar');
            if (bar) bar.style.width = Math.min(100, ws) + '%';
            const sr = document.getElementById('sidebarSubRank');
            if (sr) sr.textContent = data.sub_rank > 0 ? `+${data.sub_rank} sub-rank${data.sub_rank > 1 ? 's' : ''}` : '';

            // Per-KRA rows
            const slugMap = {
                'Instruction': 'instruction',
                'Research': 'research',
                'Extension': 'extension',
                'Professional Development': 'professionaldevelopment'
            };
            Object.entries(data.kra ?? {}).forEach(([cat, wval]) => {
                const slug = slugMap[cat] || '';
                const kEl  = document.getElementById('sidebarKra_' + slug);
                const kBar = document.getElementById('sidebarKraBar_' + slug);
                const raw  = data.kra_raw?.[cat] ?? 0;
                if (kEl)  kEl.textContent  = wval.toFixed(1);
                if (kBar) kBar.style.width = Math.min(100, raw) + '%';
            });
        })
        .catch(() => {}); // silent fail ... non-critical
}

let tempId = -1;
let currentUploadSid = 0;
let currentUploadTr  = null;
let currentUploadBtn = null;
let activeKraCriterion = '';
let skipNextModalLoad = false;
let pendingCriteriaAEditEntry = null;
let criteriaARequestToken = 0; // guards against a stale loadCriteriaARows() fetch
                                // resolving after the user has switched criteria
let pendingLegacyCriteriaAEditEntry = null;

function setKraModalCriterion(letter) {
    activeKraCriterion = letter || '';
}

function isCriteriaATableMode() {
    return KRA_CAT === 'Instruction' && activeKraCriterion === 'A';
}

function openCriterionEntry(letter) {
    setKraModalCriterion(letter);
    const modal = document.getElementById('kraEntryModal');
    if (!modal) return;

    if (isCriteriaATableMode()) {
        // Open and populate the semester grid directly. This prevents the
        // generic Instruction table from replacing it during modal startup.
        skipNextModalLoad = true;
        setKraTableHeaderForCriteriaA();
        const bsModal = new bootstrap.Modal(modal);
        bsModal.show();
        loadCriteriaARows();
        return;
    }

    // Any other criterion uses the generic table - put the original header,
    // footer and rows back before the modal becomes visible.
    setKraTableHeaderForDefault();
    new bootstrap.Modal(modal).show();
}

function critTypeFromRemarks(remarks) {
    return String(remarks || '').split('|||')[0] || '';
}

function criterionLetterFromEntry(entry) {
    const ct = critTypeFromRemarks(entry?.remarks || '');
    if (ct === 'A-set-sef' || ct === 'A-set-sef-sem') return 'A';
    if (ct === 'B-material' || ct.startsWith('B|')) return 'B';
    if (ct === 'C-thesis' || ct === 'C-mentor' || ct.startsWith('C|')) return 'C';
    return '';
}

function isBlankKraEntry(entry) {
    const remarks = String(entry?.remarks || '').trim();
    const score = parseFloat(entry?.computed_points || 0) || 0;
    return remarks === '' && score === 0;
}

function entryCriterionLetter(entry) {
    const remarks = String(entry?.remarks || '');
    const parts = remarks.split('|||');
    if (KRA_CAT === 'Instruction') {
        return criterionLetterFromEntry(entry);
    }
    if (KRA_CAT === 'Research') {
        const m = String(parts[0] || '').match(/^Criterion\s+([A-Z])/i);
        return m ? m[1].toUpperCase() : '';
    }
    if (KRA_CAT === 'Extension') {
        const subtype = parts[0] || '';
        const found = ALL_EXT_CRIT_OPTS.find(([, value]) => value === subtype);
        return found ? found[0] : '';
    }
    if (KRA_CAT === 'Professional Development') {
        return parts[0] ? parts[0].charAt(0).toUpperCase() : '';
    }
    return '';
}

function visibleEntriesForActiveCriterion(entries) {
    const usable = (entries || []).filter(e => !isBlankKraEntry(e));
    if (isCriteriaATableMode()) return usable;
    if (!activeKraCriterion) {
        return KRA_CAT === 'Instruction'
            ? usable.filter(e => entryCriterionLetter(e) !== 'A')
            : usable;
    }
    return usable.filter(e => entryCriterionLetter(e) === activeKraCriterion);
}

function setKraTableHeaderForCriteriaA() {
    captureKraTableDefaults();
    const headRow = document.querySelector('#kraTable thead tr');
    if (!headRow) return;
    // Generic Instruction rows must not survive into the semester grid.
    document.querySelectorAll('#kraTableBody tr:not(.kra-a-row)').forEach(tr => tr.remove());
    headRow.innerHTML = `
        <th style="width:56px;padding:0.7rem 1rem;">
          <button type="button" onclick="addKraRow()" title="Add evaluation period"
                  style="width:30px;height:30px;padding:0;background:#1e4d8c;border:none;border-radius:6px;color:#fff;font-size:1.1rem;font-weight:700;cursor:pointer;display:flex;align-items:center;justify-content:center;transition:background 0.15s;position:relative;z-index:10;"
                  onmouseover="this.style.background='#1a3a6b'" onmouseout="this.style.background='#1e4d8c'">
            <i class="bi bi-plus-lg"></i>
          </button>
        </th>
        <th style="padding:0.7rem 0.5rem;color:rgba(255,255,255,0.65);font-size:0.68rem;font-weight:700;letter-spacing:0.06em;text-transform:uppercase;width:58px;">No.</th>
        <th style="padding:0.7rem 0.75rem;color:rgba(255,255,255,0.65);font-size:0.68rem;font-weight:700;letter-spacing:0.06em;text-transform:uppercase;min-width:170px;">Evaluation Period</th>
        <th style="padding:0.7rem 0.55rem;color:rgba(255,255,255,0.65);font-size:0.68rem;font-weight:700;letter-spacing:0.06em;text-transform:uppercase;min-width:168px;text-align:center;">1st Semester</th>
        <th style="padding:0.7rem 0.45rem;color:rgba(255,255,255,0.65);font-size:0.68rem;font-weight:700;letter-spacing:0.06em;text-transform:uppercase;width:108px;text-align:center;">Evidence</th>
        <th style="padding:0.7rem 0.55rem;color:rgba(255,255,255,0.65);font-size:0.68rem;font-weight:700;letter-spacing:0.06em;text-transform:uppercase;min-width:168px;text-align:center;">2nd Semester</th>
        <th style="padding:0.7rem 0.45rem;color:rgba(255,255,255,0.65);font-size:0.68rem;font-weight:700;letter-spacing:0.06em;text-transform:uppercase;width:108px;text-align:center;">Evidence</th>
        <th style="width:96px;padding:0.7rem 0.75rem;color:rgba(255,255,255,0.65);font-size:0.68rem;font-weight:700;letter-spacing:0.06em;text-transform:uppercase;text-align:center;">Actions</th>`;
    const footRow = document.querySelector('#kraTable tfoot tr');
    if (footRow) {
        footRow.innerHTML = `
            <td colspan="6" style="padding:0.75rem 1rem;font-size:0.78rem;font-weight:700;color:#1a3a6b;text-align:right;">OVERALL AVERAGE RATING: <span id="kraOverallAverage" style="color:#1e4d8c;font-size:1rem;font-weight:800;">0.00</span></td>
            <td colspan="2" style="padding:0.75rem 1rem;font-size:0.78rem;font-weight:700;color:#1a3a6b;text-align:right;">FACULTY SCORE: <span id="kraGrandTotalCriteriaA" style="color:#1e4d8c;font-size:1.15rem;font-weight:800;">0.00</span></td>`;
    }
}

function captureKraTableDefaults() {
    const headRow = document.querySelector('#kraTable thead tr');
    const footRow = document.querySelector('#kraTable tfoot tr');
    if (headRow && !headRow.dataset.defaultHtml) headRow.dataset.defaultHtml = headRow.innerHTML;
    if (footRow && !footRow.dataset.defaultHtml) footRow.dataset.defaultHtml = footRow.innerHTML;
}

function setKraTableHeaderForDefault() {
    captureKraTableDefaults();
    const headRow = document.querySelector('#kraTable thead tr');
    const footRow = document.querySelector('#kraTable tfoot tr');
    if (headRow && headRow.dataset.defaultHtml) headRow.innerHTML = headRow.dataset.defaultHtml;
    if (footRow && footRow.dataset.defaultHtml) footRow.innerHTML = footRow.dataset.defaultHtml;
    // Criteria A leaves behind its own row markup; clear it so the generic
    // Instruction table never renders on top of semester rows.
    document.querySelectorAll('#kraTableBody tr.kra-a-row').forEach(tr => tr.remove());
    pendingCriteriaAEditEntry = null;
}

function makeEmptySemester(period, sem) {
    return { submission_id: tempId--, computed_points: 0, remarks: `A-set-sef-sem|||${period}|||${sem}||||`, evidence_files: [], set: '', sef: '', sem };
}

function parseCriteriaAEntries(entries) {
    const groups = new Map();
    const sourceEntries = [...(entries || [])];
    if (pendingCriteriaAEditEntry && !sourceEntries.some(e => parseInt(e.submission_id) === parseInt(pendingCriteriaAEditEntry.submission_id))) {
        sourceEntries.push(pendingCriteriaAEditEntry);
    }
    sourceEntries.forEach(e => {
        const parts = String(e.remarks || '').split('|||');
        const ct = parts[0] || '';
        if (ct !== 'A-set-sef' && ct !== 'A-set-sef-sem') return;

        let period = '';
        let sem = 1;
        let set = '';
        let sef = '';
        if (ct === 'A-set-sef-sem') {
            period = (parts[1] || '').trim() || 'AY';
            sem = String(parts[2] || '1') === '2' ? 2 : 1;
            set = parts[3] || '';
            sef = parts[4] || '';
        } else {
            period = (parts[3] || '').trim() || 'Evaluation Period';
            sem = 1;
            set = parts[1] || '';
            sef = parts[2] || '';
        }

        const semesterEntry = { ...e, set, sef, sem };
        const slot = sem === 2 ? 'second' : 'first';
        // Find the first group for this period whose slot is still free, so two
        // evaluation rows that happen to share a label do not overwrite one another.
        let target = null;
        for (const g of groups.values()) {
            if (g.period === period && !g[slot]) { target = g; break; }
        }
        if (!target) {
            target = { period, first: null, second: null };
            groups.set(`${period}#${groups.size}`, target);
        }
        target[slot] = semesterEntry;
    });

    return Array.from(groups.values()).map(g => {
        g.first = g.first || makeEmptySemester(g.period, 1);
        g.second = g.second || makeEmptySemester(g.period, 2);
        return g;
    });
}

// Values typed into the semester grid that have not reached the database yet.
// A reload (triggered by an evidence upload, for instance) would otherwise wipe
// whichever semester had not been saved.
let criteriaADraftCache = null;

function snapshotCriteriaADraft() {
    const rows = document.querySelectorAll('#kraTableBody tr.kra-a-row');
    if (!rows.length) return null;
    return Array.from(rows).map(tr => ({
        period: tr.querySelector('.kra-a-period')?.value.trim() || '',
        s1set: tr.querySelector('.kra-a-set.sem-1')?.value.trim() || '',
        s1sef: tr.querySelector('.kra-a-sef.sem-1')?.value.trim() || '',
        s2set: tr.querySelector('.kra-a-set.sem-2')?.value.trim() || '',
        s2sef: tr.querySelector('.kra-a-sef.sem-2')?.value.trim() || '',
    }));
}

// Restore a typed value only where the freshly loaded row is blank, so saved
// database values always win and nothing the user typed is silently dropped.
function restoreCriteriaADraft(draft) {
    if (!draft || !draft.length) return;
    const rows = Array.from(document.querySelectorAll('#kraTableBody tr.kra-a-row'));
    const used = new Set();
    rows.forEach(tr => {
        const period = tr.querySelector('.kra-a-period')?.value.trim() || '';
        let idx = draft.findIndex((d, i) => !used.has(i) && d.period === period);
        if (idx === -1) return;
        used.add(idx);
        const d = draft[idx];
        [['.kra-a-set.sem-1', d.s1set], ['.kra-a-sef.sem-1', d.s1sef],
         ['.kra-a-set.sem-2', d.s2set], ['.kra-a-sef.sem-2', d.s2sef]].forEach(([sel, val]) => {
            const inp = tr.querySelector(sel);
            if (inp && !inp.value.trim() && val) inp.value = val;
        });
    });
    // Rows that were added but never saved are not returned by the server.
    draft.forEach((d, i) => {
        if (used.has(i)) return;
        if (!d.s1set && !d.s1sef && !d.s2set && !d.s2sef) return;
        const tbody = document.getElementById('kraTableBody');
        if (!tbody) return;
        document.getElementById('kraEmptyRow')?.remove();
        const period = d.period || `AY ${new Date().getFullYear()}-${new Date().getFullYear() + 1}`;
        const group = { period, first: makeEmptySemester(period, 1), second: makeEmptySemester(period, 2) };
        group.first.set = d.s1set; group.first.sef = d.s1sef;
        group.second.set = d.s2set; group.second.sef = d.s2sef;
        const tr = document.createElement('tr');
        tr.className = 'kra-a-row';
        tr.innerHTML = buildCriteriaARow(group, tbody.rows.length + 1);
        tbody.appendChild(tr);
    });
    updateCriteriaASummary();
}

function criteriaASemesterLocked(entry) {
    return !!(entry && entry.verified && entry.revision_status !== 'needs_revision');
}

function criteriaAUploadButton(entry, sem) {
    const evFiles = entry.evidence_files || [];
    const hasFiles = evFiles.length > 0 || (entry.document_path && entry.document_path !== '');
    const fileCount = evFiles.length || (entry.document_path ? 1 : 0);
    const uploadClass = hasFiles ? 'btn btn-sm btn-outline-success kra-upload-btn has-file' : 'btn btn-sm btn-outline-secondary kra-upload-btn';
    const uploadIcon = hasFiles ? 'bi-file-earmark-check-fill' : 'bi-cloud-upload';
    const uploadTitle = hasFiles ? `${fileCount} file(s) uploaded - click to manage` : `${sem === 1 ? '1st' : '2nd'} Semester evidence`;
    const firstDoc = evFiles.length > 0 ? evFiles[0].file_path : (entry.document_path || '');
    const semLabel = sem === 1 ? '1st Sem' : '2nd Sem';
    const locked = criteriaASemesterLocked(entry);
    return `<div class="kra-a-evidence sem-${sem}">
            <button type="button" class="${uploadClass}" onclick="${locked ? '' : 'openUploadModal(this)'}"
                data-sid="${entry.submission_id || 0}" data-doc="${esc(firstDoc)}" data-sem="${sem}" title="${locked ? 'Verified — locked' : uploadTitle}"
                ${locked ? 'disabled' : ''}>
                <i class="bi ${locked ? 'bi-lock-fill' : uploadIcon}"></i>${fileCount > 1 ? ` <span class="badge bg-light text-dark" style="font-size:0.65rem;">${fileCount}</span>` : ''}
            </button>
            <span class="kra-a-evidence-tag">${semLabel}${locked ? ' <i class="bi bi-patch-check-fill" style="color:#22c55e;" title="Verified by checker"></i>' : ''}</span>
        </div>`;
}

function criteriaAScoreFields(entry, sem) {
    const locked = criteriaASemesterLocked(entry);
    const dis = locked ? 'disabled' : '';
    return `<div class="kra-a-scores sem-${sem}" style="display:grid;gap:5px;max-width:120px;margin:0 auto;">
        <label style="display:flex;align-items:center;gap:5px;margin:0;font-size:0.74rem;color:#64748b;font-weight:700;">
            <span style="width:28px;">SET:</span>
            <input type="text" inputmode="decimal" class="kra-a-set sem-${sem}" value="${esc(entry.set || '')}" placeholder="0.00" oninput="calcCriteriaARow(this)"
                   style="width:76px;text-align:center;" ${dis}>
        </label>
        <label style="display:flex;align-items:center;gap:5px;margin:0;font-size:0.74rem;color:#64748b;font-weight:700;">
            <span style="width:28px;">SEF:</span>
            <input type="text" inputmode="decimal" class="kra-a-sef sem-${sem}" value="${esc(entry.sef || '')}" placeholder="0.00" oninput="calcCriteriaARow(this)"
                   style="width:76px;text-align:center;" ${dis}>
        </label>
    </div>`;
}

function buildCriteriaARow(group, num) {
    const first = group.first;
    const second = group.second;
    return `
        <td style="padding:0.5rem 1rem;"></td>
        <td style="padding:0.5rem 0.5rem;color:#94a3b8;font-size:0.75rem;font-weight:600;">${num}</td>
        <td style="padding:0.5rem 0.75rem;">
            <input class="kra-a-period" value="${esc(group.period || '')}" placeholder="AY 2023-2024" oninput="syncCriteriaAPeriod(this)"
                   style="min-width:145px;font-weight:600;color:#1e293b;">
        </td>
        <td style="padding:0.55rem 0.55rem;text-align:center;">${criteriaAScoreFields(first, 1)}</td>
        <td style="padding:0.55rem 0.45rem;text-align:center;">${criteriaAUploadButton(first, 1)}</td>
        <td style="padding:0.55rem 0.55rem;text-align:center;">${criteriaAScoreFields(second, 2)}</td>
        <td style="padding:0.55rem 0.45rem;text-align:center;">${criteriaAUploadButton(second, 2)}</td>
        <td style="padding:0.5rem 0.75rem;text-align:center;">
            <div class="d-flex gap-1 justify-content-center">
                <button type="button" class="btn btn-success kra-action-btn" onclick="saveCriteriaARow(this)" title="Save evaluation period" style="width:36px;height:36px;padding:0;border-radius:6px;display:inline-flex;align-items:center;justify-content:center;">
                    <i class="bi bi-floppy"></i>
                </button>
                <button type="button" class="btn btn-danger kra-action-btn" onclick="deleteCriteriaARow(this)" title="Delete evaluation period" style="width:36px;height:36px;padding:0;border-radius:6px;display:inline-flex;align-items:center;justify-content:center;">
                    <i class="bi bi-trash"></i>
                </button>
            </div>
        </td>`;
}

// -- Extension (KRA III) criterion/subtype scheme -------------
// remarks format: subtype|||title|||val1|||val2 — subtype text must contain
// the exact keywords kra3_scorer.php's isCritX()/scoreCritX() checks for.
const ALL_EXT_CRIT_OPTS = [
    ['A', 'moa-linkage',              'MOA/Linkage Established (5 pts each)'],
    ['A', 'income',                   'Income-Generating Project/Activity'],
    ['B', 'accredit-local',           'Accreditor/QA Evaluator &mdash; Local (8 pts)'],
    ['B', 'accredit-intl',            'Accreditor/QA Evaluator &mdash; International (10 pts)'],
    ['B', 'judge-research',           'Judge &mdash; Research/Competition (2 pts)'],
    ['B', 'judge-other',              'Judge &mdash; Other Competition (1 pt)'],
    ['B', 'consultant-local',         'Consultant/Technical Expert &mdash; Local (8 pts)'],
    ['B', 'consultant-intl',          'Consultant/Technical Expert &mdash; International (10 pts)'],
    ['B', 'media-column-regular',     'Regular Newspaper/Media Column (10 pts)'],
    ['B', 'media-column-occasional',  'Occasional Newspaper/Media Column (2 pts, max 5x)'],
    ['B', 'media-tv-radio-host',      'TV/Radio Program Host (10 pts)'],
    ['B', 'media-guest',              'TV/Radio Guest (1 pt, max 10x)'],
    ['B', 'resource-speaker-local',   'Resource Speaker/Facilitator &mdash; Local (2 pts)'],
    ['B', 'resource-speaker-intl',    'Resource Speaker/Facilitator &mdash; International (3 pts)'],
    ['B', 'outreach-isr-lead',        'ISR/Outreach Project &mdash; Head/Lead (5 pts, ISR cap 30)'],
    ['B', 'outreach-isr-member',      'ISR/Outreach Project &mdash; Member (2 pts, ISR cap 30)'],
    ['C', 'csr-satisfaction',         'CSR / Client Satisfaction Rating'],
    ['D', 'president',                'President (20 pts/year)'],
    ['D', 'vice-president',           'Vice-President (15 pts/year)'],
    ['D', 'chancellor',               'Chancellor (10 pts/year)'],
    ['D', 'vice-chancellor',          'Vice-Chancellor (8 pts/year)'],
    ['D', 'campus director',          'Campus Director/Administrator (8 pts/year)'],
    ['D', 'office director',          'Office Director (6 pts/year)'],
    ['D', 'dean',                     'Dean (6 pts/year)'],
    ['D', 'associate dean',           'Associate Dean (5 pts/year)'],
    ['D', 'dept head',                'Department Head (4 pts/year)'],
    ['D', 'program chair',            'Program Chair (3 pts/year)'],
    ['D', 'committee chair',          'Committee Chair (2 pts/year)'],
    ['D', 'committee member',         'Committee Member (1 pt/year)'],
    ['D', 'coordinator',              'Coordinator (2 pts/year)'],
];
const EXT_D_SUBTYPES = ALL_EXT_CRIT_OPTS.filter(([l]) => l === 'D').map(([,v]) => v);

// Extra (val1/val2) fields for the selected Extension subtype
function extExtraFieldsHtml(subtype, val1, val2) {
    if (subtype === 'income') {
        const roleOpts = [['lead','Lead Implementer'],['co-implementer','Co-Implementer']]
            .map(([v,l]) => `<option value="${v}" ${val2===v?'selected':''}>${l}</option>`).join('');
        return `<input type="text" inputmode="decimal" class="re-val1" value="${esc(val1||'')}" placeholder="Income amount (&#8369;)" oninput="calcRow(this)" style="margin-top:3px;width:100%;">
                <select class="re-val2" onchange="calcRow(this)" style="margin-top:3px;width:100%;">${roleOpts}</select>`;
    }
    if (subtype === 'csr-satisfaction') {
        return `<input type="text" inputmode="decimal" class="re-val1" value="${esc(val1||'')}" placeholder="CSR rating (0-100)" oninput="calcRow(this)" style="margin-top:3px;width:100%;">
                <div style="font-size:0.68rem;color:#94a3b8;margin-top:2px;">Title must match a Criterion B outreach/ISR entry for credit.</div>`;
    }
    if (EXT_D_SUBTYPES.includes(subtype)) {
        return `<input type="text" inputmode="numeric" class="re-val1" value="${esc(val1||'1')}" placeholder="Years served" oninput="calcRow(this)" style="margin-top:3px;width:100%;">`;
    }
    return '';
}

// Rebuild the val1/val2 fields when the Extension criterion/type changes
function updateExtDetailFields(sel) {
    const tr = sel.closest('tr');
    const wrap = tr.querySelector('.re-extra');
    if (!wrap) return;
    wrap.innerHTML = extExtraFieldsHtml(sel.value, '', '');
}

// -- Build row HTML --------------------------------------------
function buildRow(e, num) {
    const sid   = e.submission_id || 0;
    const score = parseFloat(e.computed_points || 0);
    const rem   = e.remarks || '';
    const doc   = e.document_path || '';
    const parts = rem.split('|||');

    let cells = `<td class="kra-entry-spacer"></td><td class="kra-entry-rownum">${num}</td>`;

    if (KRA_CAT === 'Research') {
        // parts[0] stores the full display text e.g. "Criterion A &ndash; Book, Sole Author (100pts)"
        // Strip the pts suffix for matching against c.label
        const storedLabel = (parts[0] || '').replace(/\s*\(\d+(\.\d+)?pts?\)\s*$/i, '').trim();

        // Determine active criterion letter for filtering
        // activeKraCriterion is set when opened via "Data Entry" button
        // For Edit, derive from the stored label
        function researchActiveLetter() {
            if (activeKraCriterion) return activeKraCriterion;
            // Derive from stored label: "Criterion A ...", "Criterion B ...", etc.
            const m = storedLabel.match(/^Criterion\s+([A-Z])/i);
            return m ? m[1].toUpperCase() : '';
        }
        const _resLetter = researchActiveLetter();

        // Filter CRIT to only show items belonging to the active criterion letter
        const filteredCrit = _resLetter
            ? CRIT.filter(c => {
                const m = String(c.label || '').match(/^Criterion\s+([A-Z])/i);
                return m ? m[1].toUpperCase() === _resLetter : true;
              })
            : CRIT;

        // Strip "Criterion X — " prefix from labels when only one criterion shown
        const opts = filteredCrit.map(c => {
            const cleanLabel = _resLetter
                ? String(c.label || '').replace(/^Criterion\s+[A-Z]\s*[\u2014\-]+\s*/i, '')
                : c.label;
            return `<option value="${c.pts}" data-label="${esc(c.label)}" ${optionSelectedFromStored(storedLabel, c.label, c.pts) ? 'selected' : ''}>${cleanLabel} (${c.pts}pts)</option>`;
        }).join('');

        cells += `
        <td class="kra-research-main">
            <select class="rtype" onchange="calcRow(this)" data-sid="${sid}"><option value="" disabled selected>- Select -</option>${opts}</select>
            <div class="kra-research-detail-grid">
                <input class="rdev" value="${esc(parts[1]||'')}" placeholder="Developer(s) / author(s)">
                <input class="raff" value="${esc(parts[2]||'')}" placeholder="Affiliation">
                <input class="rarea" value="${esc(parts[3]||'')}" placeholder="Area">
            </div>
            <textarea class="rspec" rows="2" placeholder="Specific contribution">${esc(parts[4]||'')}</textarea>
        </td>
        <td class="kra-research-contrib"><input type="text" inputmode="decimal" class="rcontrib" value="${esc(parts[5]||'100')}" placeholder="e.g. 100" oninput="calcRow(this)"></td>`;
    } else if (KRA_CAT === 'Extension') {
        // remarks format: subtype|||title|||val1|||val2
        const subtype = parts[0] || '';
        const title   = parts[1] || '';
        const val1    = parts[2] || '';
        const val2    = parts[3] || '';

        // Which criterion letter is active: opened via a criterion's "Add Entry"
        // button (activeKraCriterion), or — when editing — derived from the
        // entry's own stored subtype.
        function extActiveLetter() {
            if (activeKraCriterion) return activeKraCriterion;
            const found = ALL_EXT_CRIT_OPTS.find(([, v]) => v === subtype);
            return found ? found[0] : '';
        }
        const _extLetter = extActiveLetter();
        const filteredExtOpts = _extLetter
            ? ALL_EXT_CRIT_OPTS.filter(([l]) => l === _extLetter)
            : ALL_EXT_CRIT_OPTS;
        const extOpts = filteredExtOpts.map(([, v, lbl]) =>
            `<option value="${v}" ${subtype===v?'selected':''}>${lbl}</option>`
        ).join('');

        cells += `
        <td style="padding:0.4rem;"><select class="re-crit" onchange="calcRow(this);updateExtDetailFields(this)"><option value="" disabled selected>&mdash; Select &mdash;</option>${extOpts}</select></td>
        <td style="padding:0.4rem;">
            <input class="re-title" value="${esc(title)}" placeholder="Activity / project / designation name">
            <div class="re-extra">${extExtraFieldsHtml(subtype, val1, val2)}</div>
        </td>`;
    } else if (KRA_CAT === 'Professional Development') {
        // Criterion selector + dynamic sub-fields
        // remarks format: criterion_type|||description|||sub_value
        const critType = parts[0] || '';
        const desc     = parts[1] || '';
        const subVal   = parts[2] || '0';

        const ALL_PD_CRIT_OPTS = [
            ['A-org',       'Criterion A &mdash; Professional Org Membership (5 pts each, max 20)'],
            ['B-training',  'Criterion B &mdash; Training/Conference Attended'],
            ['B-paper',     'Criterion B &mdash; Paper Presentation'],
            ['B-degree',    'Criterion B &mdash; Educational Qualification (Degree)'],
            ['C-award',     'Criterion C &mdash; Award / Recognition'],
        ];
        // Which criterion letter is active: opened via a criterion's "Add Entry"
        // button (activeKraCriterion), or — when editing — derived from the
        // entry's own stored critType (openEditModal clears activeKraCriterion).
        const _pdLetter = activeKraCriterion || (critType ? critType.charAt(0) : '');
        const filteredPdOpts = _pdLetter
            ? ALL_PD_CRIT_OPTS.filter(([v]) => v.startsWith(_pdLetter))
            : ALL_PD_CRIT_OPTS;
        const critOpts = filteredPdOpts
            .map(([v,l]) => `<option value="${v}" ${critType===v?'selected':''}>${l}</option>`).join('');

        // Sub-value options depend on criterion type
        let subField = '';
        if (critType === 'B-degree') {
            const degOpts = [['0','None (0 pts)'],['10','Post-Master\'s / Post-Doctoral (10 pts)'],['20','Additional Master\'s Degree (20 pts)']]
                .map(([v,l]) => `<option value="${v}" ${subVal===v?'selected':''}>${l}</option>`).join('');
            subField = `<select class="rpd-sub" onchange="calcRow(this)">${degOpts}</select>`;
        } else if (critType === 'B-training') {
            const tOpts = [['1','Local (1 pt)'],['2','International (2 pts)']]
                .map(([v,l]) => `<option value="${v}" ${subVal===v?'selected':''}>${l}</option>`).join('');
            subField = `<select class="rpd-sub" onchange="calcRow(this)">${tOpts}</select>`;
        } else if (critType === 'B-paper') {
            const pOpts = [['3','Local (3 pts)'],['5','International (5 pts)']]
                .map(([v,l]) => `<option value="${v}" ${subVal===v?'selected':''}>${l}</option>`).join('');
            subField = `<select class="rpd-sub" onchange="calcRow(this)">${pOpts}</select>`;
        } else if (critType === 'C-award') {
            const aOpts = [['2','Institutional (2 pts)'],['3','Local/City/Province (3 pts)'],['4','Regional (4 pts)'],['0','National/International (+1 sub-rank, 0 pts)']]
                .map(([v,l]) => `<option value="${v}" ${subVal===v?'selected':''}>${l}</option>`).join('');
            subField = `<select class="rpd-sub" onchange="calcRow(this)">${aOpts}</select>`;
        } else {
            // A-org: fixed 5 pts per org
            subField = `<input type="hidden" class="rpd-sub" value="1"><span class="text-muted small">5 pts</span>`;
        }

        cells += `
        <td style="padding:0.4rem;"><select class="rpd-crit" onchange="calcRow(this);updatePdSubField(this)"><option value="" disabled selected>&mdash; Select &mdash;</option>${critOpts}</select></td>
        <td style="padding:0.4rem;"><input class="rpd-desc" value="${esc(desc)}" placeholder="Name / title / organization"></td>
        <td style="padding:0.4rem;">${subField}</td>`;
    } else {
        // KRA I &mdash; Instruction: Criterion selector
        // remarks format: criterion_type|||detail1|||detail2
        const critType = parts[0] || '';
        const d1       = parts[1] || '';
        const d2       = parts[2] || '';
        const notes    = parts[3] || '';

        // Flat criterion options — B and C sub-items shown directly
        const ALL_CRIT_OPTS = [
            ['A-set-sef',   'Criterion A \u2014 Teaching Effectiveness (SET + SEF)'],
            ['B|30|Textbook \u2014 Sole Author',             'B \u2014 Textbook \u2014 Sole Author (30 pts)'],
            ['B|30co|Textbook \u2014 Co-Author',             'B \u2014 Textbook \u2014 Co-Author (30 \u00d7 contrib%)'],
            ['B|10|Textbook Chapter \u2014 Sole Author',     'B \u2014 Textbook Chapter \u2014 Sole Author (10 pts)'],
            ['B|10co|Textbook Chapter \u2014 Co-Author',     'B \u2014 Textbook Chapter \u2014 Co-Author (10 \u00d7 contrib%)'],
            ['B|16|Manual/Module \u2014 Sole Author',        'B \u2014 Manual/Module \u2014 Sole Author (16 pts)'],
            ['B|16co|Manual/Module \u2014 Co-Author',        'B \u2014 Manual/Module \u2014 Co-Author (16 \u00d7 contrib%)'],
            ['B|16|Multimedia Teaching Materials',           'B \u2014 Multimedia Teaching Materials (16 pts)'],
            ['B|10|Validated Testing Materials',             'B \u2014 Validated Testing Materials (10 pts)'],
            ['B|10|Academic Program \u2014 Lead',            'B \u2014 Academic Program \u2014 Lead (10 pts)'],
            ['B|5|Academic Program \u2014 Contributor',      'B \u2014 Academic Program \u2014 Contributor (5 pts)'],
            ['C|3|Adviser \u2014 Special/Capstone Project',  'C \u2014 Adviser \u2014 Special/Capstone Project (3 pts)'],
            ['C|5|Adviser \u2014 Undergraduate Thesis',      'C \u2014 Adviser \u2014 Undergraduate Thesis (5 pts)'],
            ['C|8|Adviser \u2014 Master\'s Thesis',          "C \u2014 Adviser \u2014 Master's Thesis (8 pts)"],
            ['C|10|Adviser \u2014 Doctoral Dissertation',    'C \u2014 Adviser \u2014 Doctoral Dissertation (10 pts)'],
            ['C|1|Panel \u2014 Special/Capstone Project',    'C \u2014 Panel \u2014 Special/Capstone Project (1 pt)'],
            ['C|2|Panel \u2014 Undergraduate Thesis',        'C \u2014 Panel \u2014 Undergraduate Thesis (2 pts)'],
            ['C|4|Panel \u2014 Master\'s Thesis',            "C \u2014 Panel \u2014 Master's Thesis (4 pts)"],
            ['C|6|Panel \u2014 Doctoral Dissertation',       'C \u2014 Panel \u2014 Doctoral Dissertation (6 pts)'],
            ['C|0|Mentor \u2014 Competition Winner',         'C \u2014 Mentor \u2014 Competition Winner (\u26a0 pending)'],
        ];
        // Determine currently selected option from stored critType/d1 combo
        // Legacy stored as 'B-material' or 'C-thesis' with d1 being the label
        function matchFlatCrit(ct, storedD1) {
            if (ct === 'A-set-sef') return 'A-set-sef';
            if (ct === 'B-material' || ct.startsWith('B|')) {
                // match by label in d1
                const found = ALL_CRIT_OPTS.find(([v]) => v.startsWith('B|') && v.split('|').slice(2).join('|') === storedD1);
                return found ? found[0] : ct;
            }
            if (ct === 'C-thesis' || ct.startsWith('C|')) {
                const found = ALL_CRIT_OPTS.find(([v]) => v.startsWith('C|') && v.split('|').slice(2).join('|') === storedD1);
                return found ? found[0] : ct;
            }
            return ct;
        }
        const currentFlatCrit = matchFlatCrit(critType, d1);

        // Determine which criterion letter is active:
        // 1. If opened via "Data Entry" button, activeKraCriterion is set ('A','B','C')
        // 2. If opened via Edit, derive from the stored critType
        function activeLetter() {
            if (activeKraCriterion && activeKraCriterion !== 'A') return activeKraCriterion;
            if (currentFlatCrit === 'A-set-sef') return 'A';
            if (currentFlatCrit.startsWith('B|') || critType === 'B-material') return 'B';
            if (currentFlatCrit.startsWith('C|') || critType === 'C-thesis') return 'C';
            return activeKraCriterion || '';
        }
        const _letter = activeLetter();

        // Show only options that belong to the active criterion letter
        const filteredOpts = _letter
            ? ALL_CRIT_OPTS.filter(([v]) => {
                if (_letter === 'A') return v === 'A-set-sef';
                if (_letter === 'B') return v.startsWith('B|');
                if (_letter === 'C') return v.startsWith('C|');
                return true; // no filter if letter unknown
              })
            : ALL_CRIT_OPTS;

        // Strip the "B — " or "C — " prefix from option labels when only one criterion is shown
        const critOpts = filteredOpts.map(([v,l]) => {
            // Remove leading "B — " or "C — " prefix since the modal header already says which criterion
            const cleanLabel = _letter && _letter !== 'A' ? l.replace(/^[BC] \u2014 /, '') : l;
            return `<option value="${v}" ${currentFlatCrit===v?'selected':''}>${cleanLabel}</option>`;
        }).join('');

        // Determine if currently selected item is a co-author B item
        const isBco = currentFlatCrit.startsWith('B|') && currentFlatCrit.split('|')[1]?.endsWith('co');
        // Parse stored contrib % from d1 or d2 (legacy B stored contrib in d2, new flat stores in d2)
        const contribVal = isBco ? (d2 || d1 || '100') : '';

        let detailFields = '';
        if (critType === 'A-set-sef') {
            detailFields = `
            <input type="text" inputmode="decimal" class="ri-d1" value="${esc(d1)}" placeholder="SET avg % (e.g. 92.5)" oninput="calcRow(this)" style="margin-bottom:3px;">
            <input type="text" inputmode="decimal" class="ri-d2" value="${esc(d2)}" placeholder="SEF avg % (e.g. 88.0)" oninput="calcRow(this)">`;
        } else if (isBco) {
            detailFields = `<input type="text" inputmode="decimal" class="ri-d2" value="${esc(contribVal || '100')}" placeholder="Contrib % (co-author)" oninput="calcRow(this)">`;
        } else {
            detailFields = `<span class="text-muted small">&mdash;</span>`;
        }

        cells += `
        <td style="padding:0.4rem;"><select class="ri-crit" onchange="calcRow(this);updateInstrFlatDetail(this)"><option value="" disabled selected>&mdash; Select &mdash;</option>${critOpts}</select></td>
        <td style="padding:0.4rem;">${detailFields}</td>
        <td style="padding:0.4rem;">${critType === 'A-set-sef'
            ? `<input type="text" class="ri-notes" value="${esc(notes)}" placeholder="Notes (optional)">`
            : '<span class="text-muted small">&mdash;</span>'}</td>`;
    }

    const scoreClass = score > 0 ? 'kra-score-badge has-score' : 'kra-score-badge';
    const evFiles    = e.evidence_files || [];
    const hasFiles   = evFiles.length > 0 || (e.document_path && e.document_path !== '');
    const fileCount  = evFiles.length || (e.document_path ? 1 : 0);
    const uploadClass = hasFiles ? 'btn btn-sm btn-outline-success kra-upload-btn has-file' : 'btn btn-sm btn-outline-secondary kra-upload-btn';
    const uploadIcon  = hasFiles ? 'bi-file-earmark-check-fill' : 'bi-cloud-upload';
    const uploadTitle = hasFiles ? `${fileCount} file(s) uploaded &mdash; click to manage` : 'Upload evidence';
    const firstDoc    = evFiles.length > 0 ? evFiles[0].file_path : (e.document_path || '');

    cells += `
    <td class="kra-score-cell">
        <span class="${scoreClass}" id="rscore_${sid}">${score.toFixed(2)}</span>
    </td>
    <td class="kra-evidence-cell">
        <button type="button" class="${uploadClass}" onclick="openUploadModal(this)"
                data-sid="${sid}" data-doc="${esc(firstDoc)}" title="${uploadTitle}">
            <i class="bi ${uploadIcon}"></i>${fileCount > 1 ? ` <span class="badge bg-light text-dark" style="font-size:0.65rem;">${fileCount}</span>` : ''}
        </button>
    </td>
    <td class="kra-actions-cell">
        <div class="kra-row-actions">
            <button type="button" class="btn btn-success kra-action-btn kra-save-btn d-none" onclick="saveRow(this)" data-sid="${sid}" title="Save row" style="width:36px;height:36px;padding:0;border-radius:6px;display:inline-flex;align-items:center;justify-content:center;">
                <i class="bi bi-floppy"></i>
            </button>
            <button type="button" class="btn btn-danger kra-action-btn" onclick="deleteRow(this)" data-sid="${sid}" title="Delete this row" style="width:36px;height:36px;padding:0;border-radius:6px;display:inline-flex;align-items:center;justify-content:center;">
                <i class="bi bi-trash"></i>
            </button>
        </div>
    </td>`;

    return cells;
}

function esc(s) { return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;'); }

function normalizeChoiceLabel(s) {
    const txt = document.createElement('textarea');
    txt.innerHTML = String(s || '');
    return txt.value
        .replace(/[–—]/g, '-')
        .replace(/[×x]\s*contrib%/ig, '')
        .replace(/\(\s*\d+(\.\d+)?\s*pts?\.?\s*\)/ig, '')
        .replace(/\s+/g, ' ')
        .trim()
        .toLowerCase();
}

function storedChoicePoints(s) {
    const m = String(s || '').match(/\(\s*(\d+(?:\.\d+)?)\s*pts?\.?\s*\)/i);
    return m ? parseFloat(m[1]) : null;
}

function optionSelectedFromStored(stored, label, value) {
    const storedNorm = normalizeChoiceLabel(stored);
    const labelNorm = normalizeChoiceLabel(label);
    if (storedNorm && labelNorm && (storedNorm === labelNorm || labelNorm.includes(storedNorm) || storedNorm.includes(labelNorm))) {
        return true;
    }
    const pts = storedChoicePoints(stored);
    if (pts === null) return false;
    const numericValue = parseFloat(String(value || '').replace('co', ''));
    return !Number.isNaN(numericValue) && numericValue === pts && storedNorm && labelNorm && (
        storedNorm.split('-')[0] === labelNorm.split('-')[0] ||
        storedNorm.includes('mentor') === labelNorm.includes('mentor')
    );
}

// Per-entry score preview mirroring kra3_scorer.php's single-entry logic
// (cross-row caps like the ISR 30-pt sub-cap are applied server-side on save).
function extScoreForSubtype(subtype, val1, val2) {
    const s = String(subtype || '');
    if (s === 'moa-linkage') return 5.0;
    if (s === 'income') {
        const income = parseFloat(val1) || 0;
        const lead = !String(val2 || '').toLowerCase().includes('co');
        let tier = 0;
        if (income > 12000000) tier = 18;
        else if (income >= 6000001) tier = 12;
        else if (income >= 500001) tier = 6;
        else if (income >= 100001) tier = 4;
        else if (income > 0) tier = 2;
        return lead ? tier : tier / 2;
    }
    if (s === 'accredit-local') return 8.0;
    if (s === 'accredit-intl') return 10.0;
    if (s === 'judge-research') return 2.0;
    if (s === 'judge-other') return 1.0;
    if (s === 'consultant-local') return 8.0;
    if (s === 'consultant-intl') return 10.0;
    if (s === 'media-column-regular') return 10.0;
    if (s === 'media-column-occasional') return 2.0;
    if (s === 'media-tv-radio-host') return 10.0;
    if (s === 'media-guest') return 1.0;
    if (s === 'resource-speaker-local') return 2.0;
    if (s === 'resource-speaker-intl') return 3.0;
    if (s === 'outreach-isr-lead') return 5.0;
    if (s === 'outreach-isr-member') return 2.0;
    if (s === 'csr-satisfaction') {
        const rating = Math.min(100, Math.max(0, parseFloat(val1) || 0));
        return (rating / 100) * 20;
    }
    const D_RATES = {
        'president': 20, 'vice-president': 15, 'chancellor': 10, 'vice-chancellor': 8,
        'campus director': 8, 'office director': 6, 'dean': 6, 'associate dean': 5,
        'dept head': 4, 'program chair': 3, 'committee chair': 2, 'committee member': 1,
        'coordinator': 2,
    };
    if (s in D_RATES) {
        const years = Math.max(1, parseFloat(val1) || 1);
        return D_RATES[s] * years;
    }
    return 0;
}

// -- Calc score ------------------------------------------------
function calcRow(el) {
    const tr = el.closest('tr');
    let score = 0;
    if (KRA_CAT === 'Research') {
        const base = parseFloat(tr.querySelector('.rtype')?.value) || 0;
        const cont = parseFloat(tr.querySelector('.rcontrib')?.value) || 0;
        // Warn if contribution > 100
        const contEl = tr.querySelector('.rcontrib');
        if (contEl) {
            const v = parseFloat(contEl.value);
            contEl.classList.toggle('over-cap', v > 100);
            let w = contEl.nextElementSibling;
            if (!w || !w.classList.contains('cap-warn')) {
                w = document.createElement('div');
                w.className = 'cap-warn';
                w.textContent = 'Max 100%';
                contEl.parentNode.appendChild(w);
            }
            w.style.display = v > 100 ? 'block' : 'none';
        }
        score = base * (cont / 100);
    } else if (KRA_CAT === 'Extension') {
        const subtype = tr.querySelector('.re-crit')?.value || '';
        const val1    = tr.querySelector('.re-val1')?.value || '';
        const val2    = tr.querySelector('.re-val2')?.value || '';
        score = extScoreForSubtype(subtype, val1, val2);
    } else if (KRA_CAT === 'Professional Development') {
        const critType = tr.querySelector('.rpd-crit')?.value || '';
        const subVal   = parseFloat(tr.querySelector('.rpd-sub')?.value) || 0;
        if (critType === 'B-degree') {
            score = subVal; // 10/20/40 pts
        } else if (critType === 'B-training') {
            score = subVal; // 1 or 2 pts per activity
        } else if (critType === 'B-paper') {
            score = subVal; // 3 or 5 pts per presentation
        } else if (critType === 'C-award') {
            score = subVal; // 2/3/4 pts
        } else if (critType === 'A-org') {
            score = 5; // 5 pts per qualifying org
        }
    } else {
        // KRA I &mdash; Instruction
        const critType = tr.querySelector('.ri-crit')?.value || '';
        if (critType === 'A-set-sef') {
            const set = parseFloat(tr.querySelector('.ri-d1')?.value) || 0;
            const sef = parseFloat(tr.querySelector('.ri-d2')?.value) || 0;
            ['.ri-d1','.ri-d2'].forEach(cls => {
                const inp = tr.querySelector(cls);
                if (!inp) return;
                const v = parseFloat(inp.value);
                inp.classList.toggle('over-cap', v > 100);
                let w = inp.nextElementSibling;
                if (!w || !w.classList.contains('cap-warn')) {
                    w = document.createElement('div'); w.className = 'cap-warn'; w.textContent = 'Max 100%';
                    inp.parentNode.appendChild(w);
                }
                w.style.display = v > 100 ? 'block' : 'none';
            });
            score = (set/100)*36 + (sef/100)*24;
        } else if (critType === 'B-material' || critType.startsWith('B|')) {
            // Flat B: value like 'B|30co|Label'
            const parts = critType.split('|');
            const pts = parts[1] || '';
            const contrib = parseFloat(tr.querySelector('.ri-d2')?.value) || 100;
            if (pts.endsWith('co')) {
                score = parseFloat(pts.replace('co','')) * (contrib / 100);
            } else {
                score = parseFloat(pts) || 0;
            }
        } else if (critType === 'C-thesis' || critType.startsWith('C|')) {
            // Flat C: value like 'C|5|Adviser — Undergraduate Thesis'
            const parts = critType.split('|');
            const pts = parseFloat(parts[1] ?? '0');
            const isMentor = parts[1] === '0' && critType.includes('Mentor');
            const scoreEl2 = document.getElementById(`rscore_${tr.dataset.sid}`) || tr.querySelector('.kra-score-badge');
            if (isMentor && scoreEl2) {
                scoreEl2.textContent = '? pending';
                scoreEl2.className = 'kra-score-badge';
                updateGrandTotal();
                return;
            }
            score = pts;
        }
    }
    const sid = tr.dataset.sid || tr.querySelector('[data-sid]')?.dataset.sid || '';
    const scoreEl = document.getElementById(`rscore_${sid}`) || tr.querySelector('.kra-score-badge');
    if (scoreEl) {
        scoreEl.textContent = score.toFixed(2);
        scoreEl.className = score > 0 ? 'kra-score-badge has-score' : 'kra-score-badge';
    }
    updateGrandTotal();
}

function getKraGrandTotalEl() {
    return document.getElementById(isCriteriaATableMode() ? 'kraGrandTotalCriteriaA' : 'kraGrandTotalDefault');
}

function setKraGrandTotal(value) {
    const el = getKraGrandTotalEl();
    if (el) el.textContent = value;
}

function updateGrandTotal() {
    if (isCriteriaATableMode()) {
        updateCriteriaASummary();
        return;
    }
    let sum = 0;
    document.querySelectorAll('#kraTableBody .kra-score-badge').forEach(el => sum += parseFloat(el.textContent) || 0);
    // JC01 s.2026: each KRA capped at 100 (Extension was 120 under old JC3 rules)
    const capMap = {'Instruction':100, 'Research':100, 'Extension':100, 'Professional Development':100};
    const cap = capMap[KRA_CAT] ?? 100;
    setKraGrandTotal(Math.min(sum, cap).toFixed(2));
}

function validRating(v) {
    const n = parseFloat(v);
    return Number.isFinite(n) && n > 0 ? Math.min(100, Math.max(0, n)) : null;
}

function calcCriteriaARow(el) {
    const tr = el.closest('tr');
    tr?.querySelectorAll('.kra-a-set, .kra-a-sef').forEach(inp => {
        const v = parseFloat(inp.value);
        inp.classList.toggle('over-cap', Number.isFinite(v) && v > 100);
    });
    updateCriteriaASummary();
}

function syncCriteriaAPeriod(input) {
    input.closest('tr')?.querySelectorAll('[data-sem]').forEach(btn => {
        btn.dataset.period = input.value || '';
    });
}

function updateCriteriaASummary() {
    const ratings = [];
    const setVals = [];
    const sefVals = [];
    document.querySelectorAll('#kraTableBody tr.kra-a-row').forEach(tr => {
        tr.querySelectorAll('.kra-a-set, .kra-a-sef').forEach(inp => {
            const v = validRating(inp.value);
            if (v !== null) ratings.push(v);
        });
        tr.querySelectorAll('.kra-a-set').forEach(inp => {
            const v = validRating(inp.value);
            if (v !== null) setVals.push(v);
        });
        tr.querySelectorAll('.kra-a-sef').forEach(inp => {
            const v = validRating(inp.value);
            if (v !== null) sefVals.push(v);
        });
    });
    const avg = ratings.length ? ratings.reduce((a,b) => a + b, 0) / ratings.length : 0;
    const avgSet = setVals.length ? setVals.reduce((a,b) => a + b, 0) / setVals.length : 0;
    const avgSef = sefVals.length ? sefVals.reduce((a,b) => a + b, 0) / sefVals.length : 0;
    const facultyScore = (avgSet / 100) * 36 + (avgSef / 100) * 24;
    const avgEl = document.getElementById('kraOverallAverage');
    const scoreEl = getKraGrandTotalEl();
    if (avgEl) avgEl.textContent = avg.toFixed(2);
    if (scoreEl) scoreEl.textContent = facultyScore.toFixed(2);
}

function criteriaARemarksFromRow(tr, sem) {
    const period = tr.querySelector('.kra-a-period')?.value.trim() || 'AY';
    const set = tr.querySelector(`.kra-a-set.sem-${sem}`)?.value || '';
    const sef = tr.querySelector(`.kra-a-sef.sem-${sem}`)?.value || '';
    return `A-set-sef-sem|||${period}|||${sem}|||${set}|||${sef}`;
}

function criteriaAScoreFromRow(tr, sem) {
    const set = parseFloat(tr.querySelector(`.kra-a-set.sem-${sem}`)?.value) || 0;
    const sef = parseFloat(tr.querySelector(`.kra-a-sef.sem-${sem}`)?.value) || 0;
    return (Math.min(100, Math.max(0, set)) / 100) * 36 + (Math.min(100, Math.max(0, sef)) / 100) * 24;
}

async function saveCriteriaASemester(tr, sem, sidOverride) {
    const sid = sidOverride ?? tr.querySelector(`[data-sem="${sem}"]`)?.dataset.sid ?? '0';
    const setVal = tr.querySelector(`.kra-a-set.sem-${sem}`)?.value.trim() || '';
    const sefVal = tr.querySelector(`.kra-a-sef.sem-${sem}`)?.value.trim() || '';
    const hasFile = tr.querySelector(`[data-sem="${sem}"]`)?.classList.contains('has-file');
    const existingSid = parseInt(sid) > 0;
    // Never create a new record for a semester where nothing has been entered
    if (!existingSid && setVal === '' && sefVal === '' && !hasFile) {
        return { ok: true, submission_id: null, skipped: true };
    }
    const fd = new FormData();
    fd.append('kra_action', 'save_kra');
    fd.append('kra_category', KRA_CAT);
    fd.append('app_id', APP_ID);
    fd.append('edit_submission_id', existingSid ? sid : 0);
    fd.append('computed_points', criteriaAScoreFromRow(tr, sem));
    fd.append('remarks', criteriaARemarksFromRow(tr, sem));
    const resp = await fetch(AJAX_URL, { method: 'POST', body: fd });
    const data = await resp.json();
    if (!data.ok) throw new Error(data.error || 'Save failed');
    return data;
}

// Which semesters of this row have something worth writing to the database.
function criteriaASemestersToSave(tr, forceSem) {
    return [1, 2].filter(sem => {
        const scoreDiv = tr.querySelector(`.kra-a-scores.sem-${sem}`);
        if (scoreDiv && scoreDiv.querySelector(`.kra-a-set.sem-${sem}`)?.disabled) return false; // verified & locked
        if (forceSem && parseInt(forceSem) === sem) return true;
        const btn = tr.querySelector(`[data-sem="${sem}"]`);
        const sid = parseInt(btn?.dataset.sid || '0');
        const set = tr.querySelector(`.kra-a-set.sem-${sem}`)?.value.trim() || '';
        const sef = tr.querySelector(`.kra-a-sef.sem-${sem}`)?.value.trim() || '';
        const hasEvidence = btn?.classList.contains('has-file');
        // Treat placeholder '0.00' or '0' as empty - only save if the user typed a real value
        const setHasValue = set !== '' && set !== '0' && set !== '0.00';
        const sefHasValue = sef !== '' && sef !== '0' && sef !== '0.00';
        // Do not create an empty counterpart record when editing a single semester.
        return sid > 0 || setHasValue || sefHasValue || hasEvidence;
    });
}

// Writes BOTH semesters of an evaluation period. Each semester is its own
// kra_submissions record with its own evidence, so saving or uploading for one
// semester can never blank out the other.
async function persistCriteriaARow(tr, forceSem) {
    const semestersToSave = criteriaASemestersToSave(tr, forceSem);
    for (const sem of semestersToSave) {
        const data = await saveCriteriaASemester(tr, sem);
        if (data && data.submission_id) {
            const semesterButton = tr.querySelector(`[data-sem="${sem}"]`);
            if (semesterButton) semesterButton.dataset.sid = data.submission_id;
            if (sem === 1) tr.dataset.firstSid = data.submission_id;
            else tr.dataset.secondSid = data.submission_id;
        }
    }
    return semestersToSave;
}

async function persistGenericKraRow(tr) {
    if (!tr || tr.classList.contains('kra-a-row')) return { ok: true, skipped: true };
    const sid = parseInt(tr.dataset.sid || '0');
    const upBtn = tr.querySelector('[onclick*="openUploadModal"]');
    const hasEvidence = upBtn?.classList.contains('has-file');
    if (sid <= 0 || !hasEvidence) return { ok: true, skipped: true };

    const score = _computeScoreFromRow(tr);
    const remarks = _buildRemarksFromRow(tr);
    if (!kraRemarksLookMeaningful(remarks)) {
        throw new Error('Please select a valid criterion and fill the required fields.');
    }

    const fd = new FormData();
    fd.append('kra_action', 'save_kra');
    fd.append('kra_category', KRA_CAT);
    fd.append('app_id', APP_ID);
    fd.append('edit_submission_id', sid);
    fd.append('computed_points', score);
    fd.append('remarks', remarks);

    const resp = await fetch(AJAX_URL, { method: 'POST', body: fd });
    const data = await resp.json();
    if (!data.ok) throw new Error(data.error || 'Save failed');
    tr.dataset.dirty = '0';
    const returnedScore = parseFloat(data.computed_points ?? score);
    const badge = document.getElementById(`rscore_${sid}`) || tr.querySelector('.kra-score-badge');
    if (badge && Number.isFinite(returnedScore)) {
        badge.textContent = returnedScore.toFixed(2);
        badge.className = returnedScore > 0 ? 'kra-score-badge has-score' : 'kra-score-badge';
        tr.dataset.computedPoints = returnedScore;
    }
    updateGrandTotal();
    return data;
}

async function saveCriteriaARow(btn) {
    const tr = btn.closest('tr');
    if (!tr) return;
    pendingKraScrollState = captureKraScrollState();
    btn.disabled = true;
    btn.innerHTML = '<span class="spinner-border spinner-border-sm"></span>';
    try {
        // Save in order so a newly-created semester immediately receives its
        // permanent ID before the next interaction with this evaluation row.
        await persistCriteriaARow(tr);
        tr.classList.add('row-saved-flash');
        setTimeout(() => tr.classList.remove('row-saved-flash'), 1200);
        criteriaADraftCache = snapshotCriteriaADraft();
        loadCriteriaARows();
    } catch (err) {
        showKraAlert(esc(err.message || 'Unable to save Criteria A row.'));
    } finally {
        btn.disabled = false;
        btn.innerHTML = '<i class="bi bi-floppy"></i>';
    }
}

function deleteCriteriaARow(btn) {
    confirmAction('Remove this evaluation period? This removes both semester entries and their evidence.', function() {
        pendingKraScrollState = captureKraScrollState();
        const tr = btn.closest('tr');
        const ids = Array.from(tr.querySelectorAll('[data-sem]'))
            .map(b => parseInt(b.dataset.sid || '0'))
            .filter(id => id > 0);
        if (!ids.length) {
            tr.remove();
            updateCriteriaASummary();
            restoreKraScrollState(pendingKraScrollState);
            if (!document.querySelector('#kraTableBody tr')) loadRows();
            return;
        }
        Promise.all(ids.map(id => {
            const fd = new FormData();
            fd.append('kra_action', 'delete_kra');
            fd.append('app_id', APP_ID);
            fd.append('submission_id', id);
            return fetch(AJAX_URL, { method: 'POST', body: fd }).then(r => r.json());
        })).then(() => loadRows());
    }, 'Remove', 'bi-trash');
}

// Rebuild the sub-field cell when ProfDev criterion changes
function updatePdSubField(sel) {
    const tr      = sel.closest('tr');
    const subCell = tr.querySelector('.rpd-sub')?.closest('td');
    if (!subCell) return;
    const critType = sel.value;
    let html = '';
    if (critType === 'B-degree') {
        html = `<select class="rpd-sub" onchange="calcRow(this)">
            <option value="0">None (0 pts)</option>
            <option value="10">Post-Master's / Post-Doctoral (10 pts)</option>
            <option value="20">Additional Master's Degree (20 pts)</option>
        </select>`;
    } else if (critType === 'B-training') {
        html = `<select class="rpd-sub" onchange="calcRow(this)">
            <option value="1">Local (1 pt)</option>
            <option value="2">International (2 pts)</option>
        </select>`;
    } else if (critType === 'B-paper') {
        html = `<select class="rpd-sub" onchange="calcRow(this)">
            <option value="3">Local (3 pts)</option>
            <option value="5">International (5 pts)</option>
        </select>`;
    } else if (critType === 'C-award') {
        html = `<select class="rpd-sub" onchange="calcRow(this)">
            <option value="2">Institutional (2 pts)</option>
            <option value="3">Local/City/Province (3 pts)</option>
            <option value="4">Regional (4 pts)</option>
            <option value="0">National/International (+1 sub-rank, 0 pts)</option>
        </select>`;
    } else {
        html = `<input type="hidden" class="rpd-sub" value="1"><span class="text-muted small">5 pts</span>`;
    }
    subCell.innerHTML = html;
    calcRow(sel);
}

function updateInstrFlatDetail(sel) {
    const tr = sel.closest('tr');
    const detailCell = tr.querySelectorAll('td')[3];
    const notesCell  = tr.querySelectorAll('td')[4];
    if (!detailCell) return;
    const val = sel.value || '';
    if (val === 'A-set-sef') {
        detailCell.innerHTML = `
            <input type="text" inputmode="decimal" class="ri-d1" placeholder="SET avg % (e.g. 92.5)" oninput="calcRow(this)" style="margin-bottom:3px;">
            <input type="text" inputmode="decimal" class="ri-d2" placeholder="SEF avg % (e.g. 88.0)" oninput="calcRow(this)">`;
        if (notesCell) notesCell.innerHTML = `<input type="text" class="ri-notes" placeholder="Notes (optional)">`;
    } else if (val.startsWith('B|') && val.split('|')[1]?.endsWith('co')) {
        detailCell.innerHTML = `<input type="text" inputmode="decimal" class="ri-d2" value="100" placeholder="Contrib % (co-author)" oninput="calcRow(this)">`;
        if (notesCell) notesCell.innerHTML = `<span class="text-muted small">&mdash;</span>`;
    } else {
        detailCell.innerHTML = `<span class="text-muted small">&mdash;</span>`;
        if (notesCell) notesCell.innerHTML = `<span class="text-muted small">&mdash;</span>`;
    }
    calcRow(sel);
}

// Rebuild the detail fields when Instruction criterion changes
function updateInstrSubField(sel) {
    const tr        = sel.closest('tr');
    const detailCell = tr.querySelectorAll('td')[3]; // 4th td = details
    const notesCell  = tr.querySelectorAll('td')[4]; // 5th td = notes/sub
    if (!detailCell) return;
    const critType = sel.value;
    if (critType === 'A-set-sef') {
        detailCell.innerHTML = `
            <input type="text" inputmode="decimal" class="ri-d1" placeholder="SET avg % (e.g. 92.5)" oninput="calcRow(this)" style="margin-bottom:3px;">
            <input type="text" inputmode="decimal" class="ri-d2" placeholder="SEF avg % (e.g. 88.0)" oninput="calcRow(this)">`;
        if (notesCell) notesCell.innerHTML = `<input type="text" class="ri-notes" placeholder="Notes (optional)">`;
    } else if (critType === 'B-material') {
        detailCell.innerHTML = `
            <select class="ri-d1-sel" onchange="calcRow(this)" style="margin-bottom:3px;">
                <option value="" disabled selected>\u2014 Select material type \u2014</option>
                <option value="30">Textbook \u2014 Sole Author (30 pts)</option>
                <option value="30co">Textbook \u2014 Co-Author (30 \u00d7 contrib%)</option>
                <option value="10">Textbook Chapter \u2014 Sole Author (10 pts)</option>
                <option value="10co">Textbook Chapter \u2014 Co-Author (10 \u00d7 contrib%)</option>
                <option value="16">Manual/Module \u2014 Sole Author (16 pts)</option>
                <option value="16co">Manual/Module \u2014 Co-Author (16 \u00d7 contrib%)</option>
                <option value="16">Multimedia Teaching Materials (16 pts)</option>
                <option value="10">Validated Testing Materials (10 pts)</option>
                <option value="10">Academic Program \u2014 Lead (10 pts)</option>
                <option value="5">Academic Program \u2014 Contributor (5 pts)</option>
            </select>
            <input type="text" inputmode="decimal" class="ri-d2" placeholder="Contrib % (if co-author)" oninput="calcRow(this)">`;
        if (notesCell) notesCell.innerHTML = `<span class="text-muted small">\u2014</span>`;
    } else if (critType === 'C-thesis') {
        detailCell.innerHTML = `
            <select class="ri-d1-sel" onchange="calcRow(this)">
                <option value="" disabled selected>&mdash; Select role &mdash;</option>
                <option value="3">Adviser &mdash; Special/Capstone Project (3 pts)</option>
                <option value="5">Adviser &mdash; Undergraduate Thesis (5 pts)</option>
                <option value="8">Adviser &mdash; Master's Thesis (8 pts)</option>
                <option value="10">Adviser &mdash; Doctoral Dissertation (10 pts)</option>
                <option value="1">Panel &mdash; Special/Capstone Project (1 pt)</option>
                <option value="2">Panel &mdash; Undergraduate Thesis (2 pts)</option>
                <option value="4">Panel &mdash; Master's Thesis (4 pts)</option>
                <option value="6">Panel &mdash; Doctoral Dissertation (6 pts)</option>
                <option value="0">Mentor &mdash; Competition Winner (? pts pending ... see note)</option>
            </select>`;
        if (notesCell) notesCell.innerHTML = `<span class="text-muted small">&mdash;</span>`;
    }
    calcRow(sel);
}

function captureKraScrollState() {
    const modalBody = document.querySelector('#kraEntryModal .modal-body');
    return {
        modalBody,
        modalTop: modalBody ? modalBody.scrollTop : 0,
        windowX: window.scrollX || window.pageXOffset || 0,
        windowY: window.scrollY || window.pageYOffset || 0
    };
}

function restoreKraScrollState(state) {
    if (!state) return;
    const restore = () => {
        if (state.modalBody) {
            const maxTop = Math.max(0, state.modalBody.scrollHeight - state.modalBody.clientHeight);
            state.modalBody.scrollTop = Math.min(state.modalTop, maxTop);
        }
        window.scrollTo(state.windowX, state.windowY);
    };
    [0, 50, 150, 350, 700].forEach(delay => setTimeout(restore, delay));
    requestAnimationFrame(() => requestAnimationFrame(restore));
}

let pendingKraScrollState = null;

// -- Load rows -------------------------------------------------
function loadRows() {
    if (isCriteriaATableMode()) {
        loadCriteriaARows();
        return;
    }
    const scrollState = pendingKraScrollState || captureKraScrollState();
    pendingKraScrollState = null;
    setKraTableHeaderForDefault();
    const tbody = document.getElementById('kraTableBody');
    const modalBody = document.querySelector('#kraEntryModal .modal-body');
    const hadRows = !!tbody?.querySelector('tr[data-sid]');
    if (tbody && !hadRows) {
        tbody.innerHTML = `<tr id="kraEmptyRow"><td colspan="20" class="text-center text-muted py-5">Loading entries...</td></tr>`;
    }
    fetch(`${AJAX_URL}?kra_action=get_entries&app_id=${APP_ID}&cat=${encodeURIComponent(KRA_CAT)}`)
        .then(r => r.json())
        .then(data => {
            const tbody = document.getElementById('kraTableBody');
            // The clicked edit button already contains the record. Use it as a
            // fallback if the list request has not caught up with the page.
            const loadedEntries = data.ok && data.entries?.length
                ? data.entries
                : (pendingLegacyCriteriaAEditEntry ? [pendingLegacyCriteriaAEditEntry] : []);
            const entries = visibleEntriesForActiveCriterion(loadedEntries);
            if (!entries.length) {
                tbody.innerHTML = '';
                setKraGrandTotal('0.00');
                addKraRow();
                refreshSidebarScore();
                restoreKraScrollState(scrollState);
                return;
            }
            tbody.innerHTML = '';
            let sum = 0;
            entries.forEach((e, i) => {
                sum += parseFloat(e.computed_points || 0);
                const tr = document.createElement('tr');
                tr.dataset.sid = e.submission_id;
                tr.dataset.computedPoints = e.computed_points || 0;  // store for fallback
                tr.innerHTML = buildRow(e, i + 1);
                tbody.appendChild(tr);
                // Recalculate score from live dropdown after render
                const subSel = tr.querySelector('select.ri-d1-sel');
                const mainSel = tr.querySelector('select.ri-crit, select.rtype, select.rpd-crit, select.re-crit');
                const trigger = subSel || mainSel;
                if (trigger) {
                    calcRow(trigger);
                    const badge = tr.querySelector('.kra-score-badge');
                    const recalcScore = parseFloat(badge?.textContent) || 0;
                    const dbScore = parseFloat(e.computed_points || 0);
                    if (recalcScore !== dbScore) {
                        sum = sum - dbScore + recalcScore;
                        // Update stored score so upload fallback uses correct value
                        tr.dataset.computedPoints = recalcScore;
                    }
                    // Auto-fix: if DB has 0 but we computed a valid score, save it back
                    if (dbScore === 0 && recalcScore > 0) {
                        const fixFd = new FormData();
                        fixFd.append('kra_action', 'save_kra');
                        fixFd.append('kra_category', KRA_CAT);
                        fixFd.append('app_id', APP_ID);
                        fixFd.append('edit_submission_id', e.submission_id);
                        fixFd.append('computed_points', recalcScore);
                        const remarks = _buildRemarksFromRow(tr);
                        fixFd.append('remarks', remarks);
                        fetch(AJAX_URL, { method: 'POST', body: fixFd }).catch(() => {});
                    }
                }
            });
            setKraGrandTotal(Math.min(sum, 100).toFixed(2));
            refreshSidebarScore();

            // If we have a pending scroll target, scroll to it now
            if (pendingScrollToSid > 0) {
                setTimeout(() => scrollToEntry(pendingScrollToSid), 150);
                pendingScrollToSid = 0; // Clear the flag
            }
            pendingLegacyCriteriaAEditEntry = null;

            // If arriving from "Edit" link, scroll to and highlight the target row
            if (EDIT_SID > 0) {
                const target = tbody.querySelector(`tr[data-sid="${EDIT_SID}"]`);
                if (target) {
                    setTimeout(() => {
                        target.scrollIntoView({ behavior: 'smooth', block: 'center' });
                        target.style.transition = 'background 0.3s';
                        target.style.background = '#f1f5f9';
                        setTimeout(() => { target.style.background = ''; }, 2000);
                    }, 150);
                }
            } else {
                restoreKraScrollState(scrollState);
            }
        });
}

function loadCriteriaARows() {
    const scrollState = pendingKraScrollState || captureKraScrollState();
    pendingKraScrollState = null;
    // Take the draft BEFORE the header rebuild so nothing typed is lost.
    const draft = criteriaADraftCache || snapshotCriteriaADraft();
    criteriaADraftCache = null;
    // Snapshot which criterion this fetch belongs to. If the user closes
    // Criterion A and opens Criterion B (or any other criterion) before this
    // fetch resolves, the response below must be discarded instead of
    // painting Criteria A's semester rows into whatever table is now open —
    // this was the exact cause of Criterion B's "Add Entry" UI getting stuck
    // showing Criterion A's layout.
    const requestToken = ++criteriaARequestToken;
    setKraTableHeaderForCriteriaA();
    fetch(`${AJAX_URL}?kra_action=get_entries&app_id=${APP_ID}&cat=${encodeURIComponent(KRA_CAT)}`)
        .then(r => r.json())
        .then(data => {
            if (requestToken !== criteriaARequestToken || !isCriteriaATableMode()) {
                // A newer request has since started, or the user has since
                // switched away from Criterion A entirely — this response is
                // stale, do nothing with it.
                return;
            }
            const tbody = document.getElementById('kraTableBody');
            if (!data.ok) {
                tbody.innerHTML = `<tr id="kraEmptyRow"><td colspan="8" class="text-center text-muted py-5">Unable to load entries.</td></tr>`;
                updateCriteriaASummary();
                return;
            }
            const groups = parseCriteriaAEntries(data.entries || []);
            if (!groups.length) {
                tbody.innerHTML = `<tr id="kraEmptyRow"><td colspan="8" class="text-center text-muted py-5"><i class="bi bi-inbox fs-2 d-block mb-2 text-secondary"></i>No evaluation periods yet. Click <strong>+</strong> to add a row.</td></tr>`;
                restoreCriteriaADraft(draft);
                updateCriteriaASummary();
                refreshSidebarScore();
                restoreKraScrollState(scrollState);
                return;
            }
            tbody.innerHTML = '';
            groups.forEach((g, i) => {
                const tr = document.createElement('tr');
                tr.className = 'kra-a-row';
                tr.dataset.firstSid = g.first.submission_id || 0;
                tr.dataset.secondSid = g.second.submission_id || 0;
                tr.innerHTML = buildCriteriaARow(g, i + 1);
                tbody.appendChild(tr);
            });
            restoreCriteriaADraft(draft);
            updateCriteriaASummary();
            refreshSidebarScore();
            if (pendingScrollToSid > 0) {
                const targetBtn = tbody.querySelector(`[data-sid="${pendingScrollToSid}"]`);
                const target = targetBtn?.closest('tr');
                if (target) {
                    setTimeout(() => {
                        target.scrollIntoView({ behavior: 'smooth', block: 'center' });
                        target.style.transition = 'background 0.3s';
                        target.style.background = '#fffbeb';
                        setTimeout(() => { target.style.background = ''; }, 2000);
                    }, 150);
                }
                pendingScrollToSid = 0;
            } else {
                restoreKraScrollState(scrollState);
            }
        });
}

// -- Add row ---------------------------------------------------
function addKraRow() {
    if (isCriteriaATableMode()) {
        const tbody = document.getElementById('kraTableBody');
        const emptyRow = document.getElementById('kraEmptyRow');
        if (emptyRow) emptyRow.remove();
        const num = tbody.rows.length + 1;
        const period = `AY ${new Date().getFullYear()}-${new Date().getFullYear() + 1}`;
        const group = { period, first: makeEmptySemester(period, 1), second: makeEmptySemester(period, 2) };
        const tr = document.createElement('tr');
        tr.className = 'kra-a-row';
        tr.innerHTML = buildCriteriaARow(group, num);
        tbody.appendChild(tr);
        updateCriteriaASummary();
        return;
    }
    const tbody = document.getElementById('kraTableBody');
    const emptyRow = document.getElementById('kraEmptyRow');
    if (emptyRow) emptyRow.remove();
    const num = tbody.rows.length + 1;
    const tr = document.createElement('tr');
    tr.dataset.sid = tempId;
    tr.innerHTML = buildRow({ submission_id: tempId, computed_points: 0, remarks: '', document_path: '' }, num);
    tbody.appendChild(tr);
    tempId--;
}

// -- Save row --------------------------------------------------
function saveRow(btn) {
    const tr = btn.closest('tr');
    const sid = btn.dataset.sid || '0';
    const scoreEl = document.getElementById(`rscore_${sid}`) || tr.querySelector('.kra-score-badge');
    let score = parseFloat(scoreEl?.textContent) || 0;
    // Only recalculate if 0 ... avoid clobbering a valid score
    if (score === 0) {
        const trigger = tr.querySelector('select[onchange*="calcRow"], select.ri-d1-sel, select.ri-crit, input[oninput*="calcRow"]');
        if (trigger) calcRow(trigger);
        score = parseFloat(scoreEl?.textContent) || 0;
    }

    let remarks = '';
    if (KRA_CAT === 'Research') {
        const typeEl = tr.querySelector('.rtype');
        // Store the full display text (with pts suffix) so server-side scoring can extract points via regex
        const label  = typeEl?.options[typeEl.selectedIndex]?.text || '';
        const dev    = tr.querySelector('.rdev')?.value || '';
        const aff    = tr.querySelector('.raff')?.value || '';
        const area   = tr.querySelector('.rarea')?.value || '';
        const spec   = tr.querySelector('.rspec')?.value || '';
        const contrib = tr.querySelector('.rcontrib')?.value || '100';
        remarks = `${label}|||${dev}|||${aff}|||${area}|||${spec}|||${contrib}`;
    } else if (KRA_CAT === 'Extension') {
        const subtype = tr.querySelector('.re-crit')?.value || '';
        const title   = tr.querySelector('.re-title')?.value || '';
        const val1    = tr.querySelector('.re-val1')?.value || '';
        const val2    = tr.querySelector('.re-val2')?.value || '';
        remarks = `${subtype}|||${title}|||${val1}|||${val2}`;
    } else if (KRA_CAT === 'Professional Development') {
        const critType = tr.querySelector('.rpd-crit')?.value || '';
        const desc     = tr.querySelector('.rpd-desc')?.value || '';
        const subVal   = tr.querySelector('.rpd-sub')?.value || '0';
        remarks = `${critType}|||${desc}|||${subVal}`;
    } else {
        // KRA I &mdash; Instruction
        const critType = tr.querySelector('.ri-crit')?.value || '';
        if (critType === 'A-set-sef') {
            const set   = tr.querySelector('.ri-d1')?.value || '';
            const sef   = tr.querySelector('.ri-d2')?.value || '';
            const notes = tr.querySelector('.ri-notes')?.value || '';
            remarks = `${critType}|||${set}|||${sef}|||${notes}`;
        } else if (critType === 'B-material' || critType.startsWith('B|')) {
            const sel    = tr.querySelector('.ri-d1-sel');
            const parts = critType.split('|');
            const label = critType.startsWith('B|')
                ? (parts.slice(2).join('|') || '')
                : (sel?.options[sel.selectedIndex]?.text || '');
            const contrib = tr.querySelector('.ri-d2')?.value || '100';
            remarks = `${critType}|||${label}|||${contrib}`;
        } else if (critType === 'C-thesis' || critType.startsWith('C|')) {
            const sel   = tr.querySelector('.ri-d1-sel');
            const parts = critType.split('|');
            const label = critType.startsWith('C|')
                ? (parts.slice(2).join('|') || '')
                : (sel?.options[sel.selectedIndex]?.text || '');
            remarks = `${critType}|||${label}|||`;
        } else {
            remarks = `${critType}|||${tr.querySelector('.ri-d1')?.value||''}|||`;
        }
    }

    if (!kraRemarksLookMeaningful(remarks)) {
        showKraAlert('Select a criterion and fill the required score fields before saving or uploading evidence.');
        const firstSelect = tr.querySelector('select');
        if (firstSelect) {
            firstSelect.style.borderColor = '#334155';
            firstSelect.focus();
            setTimeout(() => { firstSelect.style.borderColor = ''; }, 2500);
        }
        return;
    }

    btn.disabled = true;
    btn.innerHTML = '<span class="spinner-border spinner-border-sm"></span>';

    const fd = new FormData();
    fd.append('kra_action', 'save_kra');
    fd.append('kra_category', KRA_CAT);
    fd.append('app_id', APP_ID);
    fd.append('edit_submission_id', parseInt(sid) < 0 ? 0 : sid);
    fd.append('computed_points', score);
    fd.append('remarks', remarks);

    // Check if this row already has a file uploaded (existing entry)
    const upBtn = tr.querySelector('[onclick*="openUploadModal"]');
    const hasExistingFile = upBtn && upBtn.classList.contains('has-file');
    const hasPendingFile  = tr._pendingFile;

    if (!hasExistingFile && !hasPendingFile) {
        btn.disabled = false;
        btn.innerHTML = '<i class="bi bi-floppy"></i>';
        // Highlight upload button
        if (upBtn) {
            upBtn.style.borderColor = '#334155';
            upBtn.style.color = '#334155';
            setTimeout(() => { upBtn.style.borderColor = ''; upBtn.style.color = ''; }, 2500);
        }
        // Show styled inline alert instead of browser alert
        showKraAlert('Evidence file is required before saving. Click the <i class="bi bi-cloud-upload"></i> upload button on this row first.');
        return;
    }

    pendingKraScrollState = captureKraScrollState();
    fetch(AJAX_URL, { method: 'POST', body: fd })
        .then(r => r.json())
        .then(data => {
            btn.disabled = false;
            btn.innerHTML = '<i class="bi bi-floppy"></i>';
            if (data.ok) {
                tr.classList.add('row-saved-flash');
                setTimeout(() => tr.classList.remove('row-saved-flash'), 1200);

                // Update the badge directly from server response ... don't wait for loadRows
                const returnedScore = parseFloat(data.computed_points ?? 0);
                const newSid = data.submission_id || parseInt(sid);
                if (newSid && newSid > 0 && parseInt(sid) < 0) {
                    tr.dataset.sid = newSid;
                }
                const badgeId = newSid > 0 ? `rscore_${newSid}` : `rscore_${sid}`;
                const badge = document.getElementById(badgeId) || tr.querySelector('.kra-score-badge');
                if (badge && returnedScore > 0) {
                    badge.textContent = returnedScore.toFixed(2);
                    badge.className = 'kra-score-badge has-score';
                    tr.dataset.computedPoints = returnedScore;
                }
                updateGrandTotal();
                loadRows();
            } else {
                alert('Error: ' + (data.error || 'Save failed'));
            }
        })
        .catch(() => {
            btn.disabled = false;
            btn.innerHTML = '<i class="bi bi-floppy"></i>';
            alert('Network error. Please try again.');
        });
}

// -- Delete row ------------------------------------------------
function deleteRow(btn) {
    const sid = btn.dataset.sid || '0';
    confirmAction(
        'Remove this entry? This cannot be undone.',
        function() { _executeDeleteRow(btn); },
        'Remove',
        'bi-trash'
    );
}
function _executeDeleteRow(btn) {
    pendingKraScrollState = captureKraScrollState();
    const sid = btn.dataset.sid || '0';
    if (parseInt(sid) < 0) {
        btn.closest('tr').remove();
        updateGrandTotal();
        restoreKraScrollState(pendingKraScrollState);
        if (!document.querySelector('#kraTableBody tr')) loadRows();
        return;
    }
    btn.disabled = true;
    const fd = new FormData();
    fd.append('kra_action', 'delete_kra');
    fd.append('app_id', APP_ID);
    fd.append('submission_id', sid);
    fetch(AJAX_URL, { method: 'POST', body: fd })
        .then(r => r.json())
        .then(() => loadRows())
        .catch(() => { btn.disabled = false; });
}

// -- Upload modal ----------------------------------------------
let uploadModalFileCap = 10;
let uploadModalCurrentFiles = [];

async function openUploadModal(btn) {
    // Block upload if sub-dropdown not selected
    const tr = btn.closest('tr');

    // Criteria A: every semester is its own record. Commit the whole row before
    // the upload so (a) the file attaches to the correct semester record and
    // (b) the post-upload table reload cannot blank the other semester's scores.
    if (tr && tr.classList.contains('kra-a-row')) {
        const original = btn.innerHTML;
        btn.disabled = true;
        btn.innerHTML = '<span class="spinner-border spinner-border-sm"></span>';
        try {
            await persistCriteriaARow(tr, btn.dataset.sem || '1');
        } catch (err) {
            showKraAlert(esc(err.message || 'Unable to prepare this row for upload.'));
            btn.disabled = false;
            btn.innerHTML = original;
            return;
        }
        btn.disabled = false;
        btn.innerHTML = original;
    }

    const subSel = tr?.querySelector('.ri-d1-sel');
    if (subSel && !subSel.value) {
        subSel.style.borderColor = '#334155';
        subSel.focus();
        // Highlight the dropdown red and scroll it into view
        setTimeout(() => { subSel.style.borderColor = ''; }, 2500);
        return;
    }
    if (subSel) subSel.style.borderColor = '';
    currentUploadSid = btn.dataset.sid || '0';
    currentUploadTr  = btn.closest('tr');
    currentUploadBtn = btn;

    const semLabel = isCriteriaATableMode() && btn.dataset.sem
        ? ` - ${btn.dataset.sem === '1' ? '1st' : '2nd'} Semester`
        : '';
    document.getElementById('uploadContext').textContent = `${KRA_CAT} - Row entry${semLabel}`;
    document.getElementById('uploadProgress').style.display = 'none';
    document.getElementById('uploadFileInput').value = '';

    // Reset drop zone
    const dz = document.getElementById('uploadDropZone');
    dz.style.borderColor = '#cbd5e1';
    dz.style.background = '#f8fafc';
    document.getElementById('uploadDropIcon').className = 'bi bi-cloud-arrow-up';
    document.getElementById('uploadDropIcon').style.color = '#94a3b8';
    document.getElementById('uploadDropText').textContent = 'Click or drag file here';

    // Load existing evidence files for this submission from the server
    const sid = parseInt(currentUploadSid);
    if (sid > 0) {
        fetch(`${AJAX_URL}?kra_action=get_entries&app_id=${APP_ID}&cat=${encodeURIComponent(KRA_CAT)}`)
            .then(r => r.json())
            .then(data => {
                if (data.ok) {
                    uploadModalFileCap = data.file_cap || 10;
                    const entry = (data.entries || []).find(e => parseInt(e.submission_id) === sid);
                    uploadModalCurrentFiles = entry ? (entry.evidence_files || []) : [];
                    // Fallback: legacy document_path
                    if (uploadModalCurrentFiles.length === 0 && entry && entry.document_path) {
                        uploadModalCurrentFiles = [{evidence_id: 0, file_path: entry.document_path, original_filename: entry.document_path.split('/').pop(), file_size_bytes: 0}];
                    }
                    renderUploadFileList();
                }
            });
    } else {
        uploadModalFileCap = 10;
        uploadModalCurrentFiles = [];
        renderUploadFileList();
    }

    new bootstrap.Modal(document.getElementById('uploadModal')).show();
}

function renderUploadFileList() {
    const list    = document.getElementById('uploadFileList');
    const count   = document.getElementById('uploadFileCount');
    const capText = document.getElementById('uploadCapText');
    const capInfo = document.getElementById('uploadCapInfo');
    const dz      = document.getElementById('uploadDropZone');

    const n = uploadModalCurrentFiles.length;
    count.textContent = n;
    capText.textContent = `${n} / ${uploadModalFileCap} files uploaded`;

    if (n >= uploadModalFileCap) {
        capInfo.style.background = '#f8fafc';
        capInfo.style.color = '#1a3a6b';
        capInfo.style.borderColor = '#94a3b8';
        dz.style.opacity = '0.5';
        dz.style.pointerEvents = 'none';
        document.getElementById('uploadDropText').textContent = 'File cap reached &mdash; remove a file first';
    } else {
        capInfo.style.background = '#f0f4fb';
        capInfo.style.color = '#1a3a6b';
        capInfo.style.borderColor = '#93c5fd';
        dz.style.opacity = '1';
        dz.style.pointerEvents = 'auto';
        document.getElementById('uploadDropText').textContent = 'Click or drag file here';
    }

    if (n === 0) {
        list.innerHTML = '<tr><td colspan="4" class="text-center text-muted py-2">No files uploaded</td></tr>';
        return;
    }

    list.innerHTML = uploadModalCurrentFiles.map((f, i) => {
        const fname  = f.original_filename || f.file_path.split('/').pop();
        const viewBtn = `<a href="pages/view_file.php?file=${encodeURIComponent(f.file_path)}" target="_blank" class="btn btn-xs btn-primary" style="font-size:0.7rem;padding:0.2rem 0.4rem;" rel="noopener noreferrer"><i class="bi bi-eye"></i></a>`;
        const delBtn  = f.evidence_id > 0
            ? `<button type="button" class="btn btn-xs btn-outline-danger" style="font-size:0.7rem;padding:0.2rem 0.4rem;" onclick="deleteEvidenceFile(${f.evidence_id}, ${currentUploadSid})" title="Remove file"><i class="bi bi-trash"></i></button>`
            : `<span class="text-muted small">&mdash;</span>`;
        return `<tr>
            <td style="padding:0.4rem 0.6rem;">${i+1}</td>
            <td style="padding:0.4rem 0.6rem;word-break:break-all;"><i class="bi bi-file-earmark me-1 text-primary"></i>${esc(fname)}</td>
            <td style="padding:0.4rem 0.6rem;text-align:center;">${viewBtn}</td>
            <td style="padding:0.4rem 0.6rem;text-align:center;">${delBtn}</td>
        </tr>`;
    }).join('');
}

function deleteEvidenceFile(evidenceId, submissionId) {
    confirmAction(
        'Remove this evidence file? This cannot be undone.',
        function() { _executeDeleteEvidenceFile(evidenceId, submissionId); },
        'Remove File',
        'bi-trash'
    );
}

function _executeDeleteEvidenceFile(evidenceId, submissionId) {
    pendingKraScrollState = captureKraScrollState();
    const fd = new FormData();
    fd.append('kra_action', 'delete_evidence');
    fd.append('app_id', APP_ID);
    fd.append('evidence_id', evidenceId);
    fd.append('submission_id', submissionId);
    fetch(AJAX_URL, { method: 'POST', body: fd })
        .then(r => r.json())
        .then(data => {
            if (data.ok) {
                uploadModalCurrentFiles = uploadModalCurrentFiles.filter(f => f.evidence_id !== evidenceId);
                renderUploadFileList();
                const uploadRemarks = _buildRemarksFromRow(currentUploadTr);
                const uploadCriterion = currentUploadTr?.classList.contains('kra-a-row')
                    ? 'A'
                    : criterionLetterFromEntry({ remarks: uploadRemarks });
                if (uploadCriterion) setKraModalCriterion(uploadCriterion);
                pendingScrollToSid = parseInt(currentUploadSid) || parseInt(submissionId) || 0;
                loadRows();
            } else {
                alert('Failed to delete file: ' + (data.error || 'Unknown error'));
            }
        });
}


function handleDrop(event) {
    event.preventDefault();
    const dz = document.getElementById('uploadDropZone');
    dz.style.borderColor = '#cbd5e1';
    dz.style.background = '#f8fafc';
    const files = event.dataTransfer.files;
    if (files && files.length > 0) processUploadFiles(files);
}

function handleFileSelect(input) {
    if (input.files && input.files.length > 0) processUploadFiles(input.files);
    // Reset so same files can be re-selected if needed
    input.value = '';
}

/**
 * Batch upload: accepts a FileList or Array of File objects.
 * Files are validated first, then uploaded sequentially so the
 * submission_id is stable after the first file creates the row.
 */
async function processUploadFiles(fileList) {
    const files   = Array.from(fileList);
    const allowed = ['pdf', 'jpg', 'jpeg', 'png'];

    // -- Validate all files before starting ------------------
    const invalid = [];
    const tooBig  = [];
    files.forEach(f => {
        const ext = f.name.split('.').pop().toLowerCase();
        if (!allowed.includes(ext)) invalid.push(f.name);
        else if (f.size > 50 * 1024 * 1024) tooBig.push(f.name);
    });
    if (invalid.length) {
        showKraAlert(`<strong>Invalid file type</strong> ... only PDF, JPG, PNG allowed:<br>${invalid.map(n=>`<code>${esc(n)}</code>`).join(', ')}`);
        return;
    }
    if (tooBig.length) {
        showKraAlert(`<strong>File too large</strong> (max 50 MB each):<br>${tooBig.map(n=>`<code>${esc(n)}</code>`).join(', ')}`);
        return;
    }

    // -- Cap check --------------------------------------------
    const remaining = uploadModalFileCap - uploadModalCurrentFiles.length;
    if (remaining <= 0) {
        showKraAlert(`File cap reached (${uploadModalFileCap} files max). Remove a file first.`);
        return;
    }
    const batch = files.slice(0, remaining);
    if (batch.length < files.length) {
        showKraAlert(`Only ${remaining} slot(s) left ... uploading first ${batch.length} of ${files.length} file(s).`);
    }

    // -- Show progress bar ------------------------------------
    const progressEl = document.getElementById('uploadProgress');
    const capText    = document.getElementById('uploadCapText');
    progressEl.style.display = 'block';
    progressEl.innerHTML = `
        <div class="d-flex align-items-center gap-2 mb-1">
            <div class="spinner-border spinner-border-sm text-primary"></div>
            <span id="batchStatusText" style="font-size:0.82rem;color:#475569;">Uploading 0 / ${batch.length}...</span>
        </div>
        <div class="progress" style="height:6px;">
            <div id="batchProgressBar" class="progress-bar bg-primary" style="width:0%;transition:width 0.3s;"></div>
        </div>`;

    // -- Build score + remarks once (shared for all files in this batch) --
    const tr      = currentUploadTr;
    const score   = _computeScoreFromRow(tr);
    const remarks = _buildRemarksFromRow(tr);
    if (!kraRemarksLookMeaningful(remarks)) {
        progressEl.style.display = 'none';
        showKraAlert('Select a criterion and fill the required score fields before uploading evidence.');
        const firstSelect = tr?.querySelector('select');
        if (firstSelect) {
            firstSelect.style.borderColor = '#334155';
            firstSelect.focus();
            setTimeout(() => { firstSelect.style.borderColor = ''; }, 2500);
        }
        return;
    }

    // -- Upload sequentially ----------------------------------
    let uploaded = 0;
    let errors   = [];

    for (const file of batch) {
        const statusEl = document.getElementById('batchStatusText');
        const barEl    = document.getElementById('batchProgressBar');
        if (statusEl) statusEl.textContent = `Uploading ${uploaded + 1} / ${batch.length}: ${file.name}`;
        if (barEl) barEl.style.width = Math.round((uploaded / batch.length) * 100) + '%';

        const fd = new FormData();
        fd.append('kra_action',          'save_kra');
        fd.append('kra_category',        KRA_CAT);
        fd.append('app_id',              APP_ID);
        fd.append('edit_submission_id',  parseInt(currentUploadSid) < 0 ? 0 : currentUploadSid);
        fd.append('computed_points',     score);
        fd.append('remarks',             remarks);
        fd.append('evidence',            file);

        try {
            const resp = await fetch(AJAX_URL, { method: 'POST', body: fd });
            const data = await resp.json();

            if (data.ok) {
                // After the first file creates the row, subsequent files attach to same submission_id
                if (data.submission_id && parseInt(currentUploadSid) <= 0) {
                    currentUploadSid = String(data.submission_id);
                    if (tr) {
                        if (tr.classList.contains('kra-a-row') && currentUploadBtn) {
                            currentUploadBtn.dataset.sid = data.submission_id;
                            if (currentUploadBtn.dataset.sem === '1') tr.dataset.firstSid = data.submission_id;
                            if (currentUploadBtn.dataset.sem === '2') tr.dataset.secondSid = data.submission_id;
                        } else {
                            tr.dataset.sid = data.submission_id;
                            tr.querySelectorAll('[data-sid]').forEach(el => el.dataset.sid = data.submission_id);
                            const oldScore = tr.querySelector('.kra-score-badge');
                            if (oldScore) oldScore.id = `rscore_${data.submission_id}`;
                        }
                    }
                }
                // Keep local file list in sync so cap check stays accurate
                uploadModalCurrentFiles = data.evidence_files || [];
                uploadModalFileCap      = data.file_cap || 10;

                // Update row score badge from server response
                const returnedScore = parseFloat(data.computed_points ?? 0);
                if (returnedScore > 0 && tr) {
                    const badge = document.getElementById(`rscore_${currentUploadSid}`) || tr.querySelector('.kra-score-badge');
                    if (badge) { badge.textContent = returnedScore.toFixed(2); badge.className = 'kra-score-badge has-score'; }
                    tr.dataset.computedPoints = returnedScore;
                    updateGrandTotal();
                }
                if (tr) tr._pendingFile = true;

                uploaded++;
            } else {
                errors.push(`${file.name}: ${data.error || 'Unknown error'}`);
            }
        } catch (e) {
            errors.push(`${file.name}: Network error`);
        }
    }

    // -- Complete ---------------------------------------------
    if (document.getElementById('batchProgressBar')) {
        document.getElementById('batchProgressBar').style.width = '100%';
    }
    await new Promise(r => setTimeout(r, 300));
    progressEl.style.display = 'none';
    // Restore original progress HTML for next open
    progressEl.innerHTML = `
        <div class="d-flex align-items-center gap-2 mb-1">
            <div class="spinner-border spinner-border-sm text-primary"></div>
            <span style="font-size:0.82rem;color:#475569;">Uploading...</span>
        </div>
        <div class="progress" style="height:6px;">
            <div class="progress-bar progress-bar-striped progress-bar-animated" style="width:100%;"></div>
        </div>`;

    renderUploadFileList();

    // Update the upload button in the KRA table row
    if (tr && uploaded > 0) {
        const upBtn = tr.classList.contains('kra-a-row') ? currentUploadBtn : tr.querySelector('[onclick*="openUploadModal"]');
        if (upBtn) {
            upBtn.className = 'btn btn-sm btn-outline-success kra-upload-btn has-file';
            upBtn.querySelector('i').className = 'bi bi-file-earmark-check-fill';
            const totalFiles = uploadModalCurrentFiles.length;
            upBtn.title = `${totalFiles} file(s) uploaded ... click to manage`;
            upBtn.dataset.doc = uploadModalCurrentFiles[0]?.file_path || '';
            upBtn.dataset.sid = currentUploadSid;
            // Show count badge if more than 1
            const existingBadge = upBtn.querySelector('.badge');
            if (existingBadge) existingBadge.remove();
            if (totalFiles > 1) {
                const span = document.createElement('span');
                span.className = 'badge bg-light text-dark';
                span.style.fontSize = '0.65rem';
                span.textContent = totalFiles;
                upBtn.appendChild(span);
            }
        }
    }

    if (uploaded > 0) showUploadSuccess(uploaded === 1 ? batch[0].name : `${uploaded} files uploaded`);
    if (errors.length) showKraAlert(`<strong>${errors.length} file(s) failed:</strong><br>${errors.map(e => esc(e)).join('<br>')}`);

    // Preserve anything still only in the inputs across the refresh.
    const uploadRemarks = _buildRemarksFromRow(tr);
    const uploadCriterion = tr?.classList.contains('kra-a-row')
        ? 'A'
        : criterionLetterFromEntry({ remarks: uploadRemarks });
    if (uploadCriterion) setKraModalCriterion(uploadCriterion);
    if (isCriteriaATableMode()) criteriaADraftCache = snapshotCriteriaADraft();
    pendingKraScrollState = captureKraScrollState();
    pendingScrollToSid = parseInt(currentUploadSid) || 0;
    loadRows();
}

// -- Helpers: extract score and remarks from the current row --
function _computeScoreFromRow(tr) {
    if (!tr) return 0;
    if (tr.classList.contains('kra-a-row')) {
        const sem = currentUploadBtn?.dataset.sem || '1';
        return criteriaAScoreFromRow(tr, sem);
    }
    let score = 0;
    if (KRA_CAT === 'Research') {
        const typeEl  = tr.querySelector('.rtype');
        const label   = typeEl?.options[typeEl?.selectedIndex]?.text || '';
        const contrib = parseFloat(tr.querySelector('.rcontrib')?.value) || 100;
        const m = label.match(/\((\d+(?:\.\d+)?)\s*pts?\)/i);
        score = m ? parseFloat(m[1]) * (contrib / 100) : 0;
    } else if (KRA_CAT === 'Extension') {
        const subtype = tr.querySelector('.re-crit')?.value || '';
        const val1    = tr.querySelector('.re-val1')?.value || '';
        const val2    = tr.querySelector('.re-val2')?.value || '';
        score = extScoreForSubtype(subtype, val1, val2);
    } else if (KRA_CAT === 'Professional Development') {
        const ct = tr.querySelector('.rpd-crit')?.value || '';
        const sv = parseFloat(tr.querySelector('.rpd-sub')?.value) || 0;
        score = (ct === 'A-org') ? 5.0 : sv;
    } else {
        const critType = tr.querySelector('.ri-crit')?.value || '';
        if (critType === 'A-set-sef') {
            const set = parseFloat(tr.querySelector('.ri-d1')?.value) || 0;
            const sef = parseFloat(tr.querySelector('.ri-d2')?.value) || 0;
            score = (set / 100) * 36 + (sef / 100) * 24;
        } else if (critType === 'B-material' || critType.startsWith('B|')) {
            const parts = critType.split('|');
            const pts = parts[1] || '';
            const contrib = parseFloat(tr.querySelector('.ri-d2')?.value) || 100;
            score = pts.endsWith('co') ? parseFloat(pts.replace('co','')) * (contrib / 100) : (parseFloat(pts) || 0);
        } else if (critType === 'C-thesis' || critType.startsWith('C|')) {
            score = parseFloat(critType.split('|')[1] ?? '0') || 0;
        }
    }
    // Fallbacks
    if (score === 0) {
        const badge = document.getElementById(`rscore_${currentUploadSid}`) || tr.querySelector('.kra-score-badge');
        score = parseFloat(badge?.textContent) || 0;
    }
    if (score === 0 && tr.dataset.computedPoints) score = parseFloat(tr.dataset.computedPoints) || 0;
    return score;
}

function kraRemarksLookMeaningful(remarks) {
    const parts = String(remarks || '').split('|||').map(p => p.trim());
    const type = parts[0] || '';
    if (!type) return false;
    if (KRA_CAT === 'Instruction') {
        if (type === 'A-set-sef') return !!parts[1] && !!parts[2];
        if (type === 'A-set-sef-sem') return !!parts[3] && !!parts[4];
        return type.startsWith('B|') || type.startsWith('C|') || ['B-material', 'C-thesis', 'C-mentor'].includes(type);
    }
    if (KRA_CAT === 'Research') return type.startsWith('Criterion') || /\(\d+(?:\.\d+)?\s*pts?\)/i.test(type);
    if (KRA_CAT === 'Extension') {
        return ALL_EXT_CRIT_OPTS.some(([, value]) => value === type);
    }
    if (KRA_CAT === 'Professional Development') return ['A-org', 'B-training', 'B-paper', 'B-degree', 'C-award'].includes(type);
    return false;
}

function _buildRemarksFromRow(tr) {
    if (!tr) return '';
    if (tr.classList.contains('kra-a-row')) {
        const sem = currentUploadBtn?.dataset.sem || '1';
        return criteriaARemarksFromRow(tr, sem);
    }
    if (KRA_CAT === 'Research') {
        const typeEl = tr.querySelector('.rtype');
        const label  = typeEl?.options[typeEl.selectedIndex]?.text || '';
        const dev    = tr.querySelector('.rdev')?.value || '';
        const aff    = tr.querySelector('.raff')?.value || '';
        const area   = tr.querySelector('.rarea')?.value || '';
        const spec   = tr.querySelector('.rspec')?.value || '';
        const contrib = tr.querySelector('.rcontrib')?.value || '100';
        return `${label}|||${dev}|||${aff}|||${area}|||${spec}|||${contrib}`;
    }
    if (KRA_CAT === 'Extension') {
        const subtype = tr.querySelector('.re-crit')?.value || '';
        const title   = tr.querySelector('.re-title')?.value || '';
        const val1    = tr.querySelector('.re-val1')?.value || '';
        const val2    = tr.querySelector('.re-val2')?.value || '';
        return `${subtype}|||${title}|||${val1}|||${val2}`;
    }
    if (KRA_CAT === 'Professional Development') {
        const ct  = tr.querySelector('.rpd-crit')?.value || '';
        const dsc = tr.querySelector('.rpd-desc')?.value || '';
        const sv  = tr.querySelector('.rpd-sub')?.value  || '0';
        return `${ct}|||${dsc}|||${sv}`;
    }
    // KRA I ... Instruction
    const critType = tr.querySelector('.ri-crit')?.value || '';
    if (critType === 'A-set-sef') {
        return `${critType}|||${tr.querySelector('.ri-d1')?.value||''}|||${tr.querySelector('.ri-d2')?.value||''}|||`;
    }
    if (critType === 'B-material' || critType.startsWith('B|')) {
        // Flat: store the full composite value as critType, label from value, contrib from ri-d2
        const parts = critType.split('|');
        const label = parts.slice(2).join('|') || '';
        const contrib = tr.querySelector('.ri-d2')?.value || '100';
        return `${critType}|||${label}|||${contrib}`;
    }
    if (critType === 'C-thesis' || critType.startsWith('C|')) {
        const parts = critType.split('|');
        const label = parts.slice(2).join('|') || '';
        return `${critType}|||${label}|||`;
    }
    return `${critType}|||${tr.querySelector('.ri-d1')?.value||''}|||`;
}

const kraHeadRow = document.querySelector('#kraTable thead tr');
const kraFootRow = document.querySelector('#kraTable tfoot tr');
if (kraHeadRow && !kraHeadRow.dataset.defaultHtml) kraHeadRow.dataset.defaultHtml = kraHeadRow.innerHTML;
if (kraFootRow && !kraFootRow.dataset.defaultHtml) kraFootRow.dataset.defaultHtml = kraFootRow.innerHTML;

const kraAutosaveTimers = new WeakMap();
function queueGenericKraAutosave(target) {
    const tr = target?.closest?.('tr[data-sid]');
    if (!tr || tr.classList.contains('kra-a-row')) return;
    tr.dataset.dirty = '1';
    if (kraAutosaveTimers.has(tr)) clearTimeout(kraAutosaveTimers.get(tr));
    const timer = setTimeout(() => {
        persistGenericKraRow(tr).catch(() => {
            tr.dataset.dirty = '1';
        });
    }, 800);
    kraAutosaveTimers.set(tr, timer);
}

document.getElementById('kraTableBody')?.addEventListener('input', function(e) {
    if (e.target.matches('input, textarea, select')) queueGenericKraAutosave(e.target);
});
document.getElementById('kraTableBody')?.addEventListener('change', function(e) {
    if (e.target.matches('input, textarea, select')) queueGenericKraAutosave(e.target);
});

document.getElementById('kraEntryModal').addEventListener('hidden.bs.modal', function() {
    // Reset criterion state on close so the next criterion opens clean.
    setKraModalCriterion('');
    pendingCriteriaAEditEntry = null;
    pendingLegacyCriteriaAEditEntry = null;
    criteriaADraftCache = null;
    setKraTableHeaderForDefault();
});

document.getElementById('kraEntryModal').addEventListener('show.bs.modal', function() {
    if (skipNextModalLoad) {
        skipNextModalLoad = false;
        return;
    }
    loadRows();
});

// Global var to track which entry to scroll to after loadRows completes
let pendingScrollToSid = 0;

function openEditModal(submissionId) {
    // Get entry data from the button's data-entry attribute
    const btn = document.getElementById('editBtn_' + submissionId);
    let entryData = null;
    try {
        entryData = btn ? JSON.parse(btn.getAttribute('data-entry') || '{}') : null;
    } catch (_) {
        entryData = null;
    }
    const critType = critTypeFromRemarks(entryData?.remarks || '');
    const isSemesterCriteriaA = critType === 'A-set-sef-sem';
    const isLegacyCriteriaA = critType === 'A-set-sef';

    // Older A records contain SET, SEF, and optional notes in one entry. Keep
    // them in the standard editor so opening the modal cannot discard notes.
    setKraModalCriterion(isSemesterCriteriaA ? 'A' : '');

    const modal = document.getElementById('kraEntryModal');
    if (!modal) return;

    const tbody = document.getElementById('kraTableBody');

    if (entryData && isSemesterCriteriaA) {
        pendingCriteriaAEditEntry = entryData;
        pendingScrollToSid = submissionId;
        // Bootstrap's show event can fire before the criterion-specific state
        // is applied. Load the Criteria A grid directly to avoid its default
        // empty table replacing the saved semester rows.
        skipNextModalLoad = true;
        setKraTableHeaderForCriteriaA();
        const bsModal = new bootstrap.Modal(modal);
        bsModal.show();
        loadCriteriaARows();
        return;
    }

    // From here on we are NOT in the semester grid, so the Criteria A header,
    // footer and leftover rows have to be reverted first. Without this the
    // Criterion B / C editor opens underneath the Criteria A layout.
    setKraTableHeaderForDefault();

    // Let the standard loader render the complete Instruction list for older
    // Criteria A entries, then bring the selected entry into view.
    if (entryData && isLegacyCriteriaA) {
        pendingLegacyCriteriaAEditEntry = entryData;
        pendingScrollToSid = submissionId;
        const bsModal = new bootstrap.Modal(modal);
        bsModal.show();
        return;
    }

    if (entryData && entryData.submission_id) {
        // Clear the table and inject this single entry directly � no AJAX needed
        tbody.innerHTML = '';
        const emptyRow = document.getElementById('kraEmptyRow');
        if (emptyRow) emptyRow.remove();

        const tr = document.createElement('tr');
        tr.dataset.sid = entryData.submission_id;
        tr.dataset.computedPoints = entryData.computed_points || 0;
        tr.innerHTML = buildRow(entryData, 1);
        tbody.appendChild(tr);

        // Recalculate score from dropdowns
        const subSel = tr.querySelector('select.ri-d1-sel');
        const mainSel = tr.querySelector('select.ri-crit, select.rtype, select.rpd-crit, select.re-crit');
        const trigger = subSel || mainSel;
        if (trigger) calcRow(trigger);

        updateGrandTotal();

        // Open modal
        skipNextModalLoad = true;
        const bsModal = new bootstrap.Modal(modal);
        bsModal.show();

        // Highlight the row after modal opens
        setTimeout(() => {
            tr.style.transition = 'background 0.3s';
            tr.style.background = '#fffbeb';
            setTimeout(() => { tr.style.background = ''; }, 2000);
        }, 300);
    } else {
        // Fallback: open modal normally and let loadRows run
        pendingScrollToSid = submissionId;
        const bsModal = new bootstrap.Modal(modal);
        bsModal.show();
    }
}

// Helper function to scroll to and highlight a specific entry row
function scrollToEntry(submissionId) {
    const tbody = document.getElementById('kraTableBody');
    const target = tbody?.querySelector(`tr[data-sid="${submissionId}"]`);
    if (target) {
        target.scrollIntoView({ behavior: 'smooth', block: 'center' });
        target.style.transition = 'background 0.3s';
        target.style.background = '#fffbeb';
        target.style.border = '2px solid #fbbf24';
        setTimeout(() => { 
            target.style.background = ''; 
            target.style.border = '';
        }, 2500);
    }
}

function showKraAlert(msg) {
    const box = document.getElementById('kraAlertBox');
    const txt = document.getElementById('kraAlertMsg');
    if (!box || !txt) return;
    txt.innerHTML = msg;
    box.style.display = 'flex';
    setTimeout(() => { box.style.display = 'none'; }, 6000);
}

function toggleCriteria(btn) {
    const rows = document.querySelectorAll('.criteria-extra');
    const expanded = rows[0]?.style.display !== 'none';
    rows.forEach(r => r.style.display = expanded ? 'none' : '');
    btn.innerHTML = expanded
        ? 'Show all <?= count($criteria_list) ?> criteria'
        : 'Show less';
}
</script>
