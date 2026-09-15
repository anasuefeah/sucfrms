<?php
ini_set('display_errors', 0); // Prevent PHP errors from corrupting JSON output
error_reporting(0);
ob_start(); // Buffer output before includes to prevent HTML leaking into JSON
/**
 * KRA AJAX endpoint
 * Handles JSON requests from the KRA entry table modal in step2_upload.php
 */
if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/functions.php';

// â”€â”€ Runtime migration: create kra_evidence_files if missing â”€â”€
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

// â”€â”€ File caps per KRA category â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
const FILE_CAPS = [
    'Instruction'              => 10,
    'Research'                 => 10,
    'Extension'                => 10,
    'Professional Development' => 10,
];

/**
 * Server-side KRA score computation from remarks string.
 * Routes through JC01 s.2026 KRA scorer modules.
 * Faculty cannot self-assign scores — all points are derived from structured data.
 */
function computeKraScore(string $category, string $remarks): float {
    // Load scorer modules if not already loaded
    $scoring_dir = __DIR__ . '/../../includes/scoring/';
    static $loaded = false;
    if (!$loaded) {
        foreach (['kra1_scorer.php','kra2_scorer.php','kra3_scorer.php','kra4_scorer.php'] as $f) {
            if (file_exists($scoring_dir . $f)) require_once $scoring_dir . $f;
        }
        $loaded = true;
    }

    switch ($category) {
        case 'Instruction':
            return class_exists('\Scoring\KRA1Scorer')
                ? \Scoring\KRA1Scorer::computeFromRemarks($remarks)
                : _legacyKRA1Score($remarks);

        case 'Research':
            return class_exists('\Scoring\KRA2Scorer')
                ? \Scoring\KRA2Scorer::computeFromRemarks($remarks)
                : _legacyKRA2Score($remarks);

        case 'Extension':
            return class_exists('\Scoring\KRA3Scorer')
                ? \Scoring\KRA3Scorer::computeFromRemarks($remarks)
                : _legacyKRA3Score($remarks);

        case 'Professional Development':
            return class_exists('\Scoring\KRA4Scorer')
                ? \Scoring\KRA4Scorer::computeFromRemarks($remarks)
                : _legacyKRA4Score($remarks);

        default:
            return 0.0;
    }
}

// ── Legacy fallback scorers (retained for safety) ────────────────────────────
function _legacyKRA1Score(string $remarks): float {
    $p = array_map('trim', explode('|||', $remarks));
    $ct = $p[0] ?? '';
    if ($ct === 'A-set-sef') {
        $set = min(100, max(0, (float)($p[1] ?? 0)));
        $sef = min(100, max(0, (float)($p[2] ?? 0)));
        return round(($set / 100) * 36 + ($sef / 100) * 24, 2);
    }
    if ($ct === 'B-material') {
        $label = $p[1] ?? ''; $contrib = min(100, max(1, (float)($p[2] ?? 100)));
        $base  = 0;
        if (preg_match('/\((\d+(?:\.\d+)?)\s*pts?\)/i', $label, $m)) $base = (float)$m[1];
        if ($base === 0) {
            $l = strtolower($label);
            if (str_contains($l,'textbook') && !str_contains($l,'chapter')) $base = 30;
            elseif (str_contains($l,'module') || str_contains($l,'manual')) $base = 16;
            elseif (str_contains($l,'multimedia')) $base = 16;
            elseif (str_contains($l,'chapter')) $base = 10;
            elseif (str_contains($l,'testing') || str_contains($l,'validated')) $base = 10;
            elseif (str_contains($l,'lead')) $base = 10;
            elseif (str_contains($l,'contributor') || str_contains($l,'contrib')) $base = 5;
        }
        $isCo = str_contains(strtolower($label),'co');
        return round($isCo ? $base * ($contrib / 100) : (float)$base, 2);
    }
    if ($ct === 'C-thesis') {
        $label = $p[1] ?? '';
        if (str_contains($label,'Doctoral') && str_contains($label,'Adviser'))    return 10;
        if (str_contains($label,"Master's") && str_contains($label,'Adviser'))    return 8;
        if (str_contains($label,'Undergrad') && str_contains($label,'Adviser'))   return 5;
        if (str_contains($label,'Special') && str_contains($label,'Adviser'))     return 3;
        if (str_contains($label,'Doctoral') && str_contains($label,'Panel'))      return 6;
        if (str_contains($label,"Master's") && str_contains($label,'Panel'))      return 4;
        if (str_contains($label,'Undergrad') && str_contains($label,'Panel'))     return 2;
        if (str_contains($label,'Special') && str_contains($label,'Panel'))       return 1;
        return 0;
    }
    $set = min(100, max(0, (float)($p[0] ?? 0)));
    $sef = min(100, max(0, (float)($p[1] ?? 0)));
    return round(($set / 100) * 36 + ($sef / 100) * 24, 2);
}

function _legacyKRA2Score(string $remarks): float {
    $p = array_map('trim', explode('|||', $remarks));
    $base = 0; $contrib = min(100, max(1, (float)($p[2] ?? 100)));
    if (preg_match('/\((\d+(?:\.\d+)?)\s*pts?\)/i', $p[0] ?? '', $m)) $base = (float)$m[1];
    return round($base * ($contrib / 100), 2);
}

function _legacyKRA3Score(string $remarks): float {
    $p = array_map('trim', explode('|||', $remarks));
    $inc = max(0,(float)($p[1]??0)); $moa = max(0,(int)($p[2]??0)); $out = max(0,(int)($p[3]??0));
    $pts = 0;
    if ($inc>=12000000) $pts+=18; elseif ($inc>=6000000) $pts+=12; elseif ($inc>=500000) $pts+=6;
    return (float)($pts + $moa * 5 + $out * 2);
}

function _legacyKRA4Score(string $remarks): float {
    $p = array_map('trim', explode('|||', $remarks));
    $ct = $p[0] ?? ''; $sv = max(0,(float)($p[2]??0));
    $allowed = match($ct) {
        'A-org'=>[5.0],'B-training'=>[1.0,2.0],'B-paper'=>[3.0,5.0],
        'B-degree'=>[0.0,10.0,20.0],'C-award'=>[2.0,3.0,4.0],default=>[]
    };
    return ($ct==='A-org') ? 5.0 : (in_array($sv,$allowed) ? $sv : 0.0);
}

// Must be logged in as faculty
if (!isLoggedIn() || !isFaculty()) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Unauthorized']);
    exit;
}

// Clear any accidental output
while (ob_get_level()) ob_end_clean();
header('Content-Type: application/json');

$uid    = $_SESSION['user_id'];
$app_id = intval($_POST['app_id'] ?? $_GET['app_id'] ?? 0);

// Verify this application belongs to the logged-in user
if ($app_id) {
    $check = $pdo->prepare("SELECT application_id, status FROM applications WHERE application_id = ? AND user_id = ?");
    $check->execute([$app_id, $uid]);
    $app_row = $check->fetch();
    if (!$app_row) {
        echo json_encode(['ok' => false, 'error' => 'Application not found']);
        exit;
    }
    $app_status          = $app_row['status'];
    $locked              = in_array($app_status, ['approved', 'reclassified', 'admin_rejected']);
    $needs_revision_mode = ($app_status === 'needs_revision');
} else {
    echo json_encode(['ok' => false, 'error' => 'Missing app_id']);
    exit;
}

// â”€â”€ GET: fetch entries â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $kra_action = $_GET['kra_action'] ?? '';
    if ($kra_action === 'get_entries') {
        $cat = $_GET['cat'] ?? '';
        $valid_cats = ['Instruction', 'Research', 'Extension', 'Professional Development'];
        if (!in_array($cat, $valid_cats)) {
            echo json_encode(['ok' => false, 'error' => 'Invalid category']);
            exit;
        }
        $rows = $pdo->prepare("SELECT submission_id, computed_points, remarks, document_path, verified FROM kra_submissions WHERE application_id = ? AND kra_category = ? ORDER BY submitted_at ASC");
        $rows->execute([$app_id, $cat]);
        $entries = $rows->fetchAll(PDO::FETCH_ASSOC);

        // Attach evidence files to each entry
        foreach ($entries as &$entry) {
            $ef = $pdo->prepare("SELECT evidence_id, file_path, original_filename, file_size_bytes FROM kra_evidence_files WHERE submission_id = ? ORDER BY uploaded_at ASC");
            $ef->execute([$entry['submission_id']]);
            $entry['evidence_files'] = $ef->fetchAll(PDO::FETCH_ASSOC);
        }
        unset($entry);

        // Include cap info
        $cap = FILE_CAPS[$cat] ?? 10;
        echo json_encode(['ok' => true, 'entries' => $entries, 'file_cap' => $cap]);
        exit;
    }
    echo json_encode(['ok' => false, 'error' => 'Unknown action']);
    exit;
}

// â”€â”€ POST: save or delete â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $kra_action = $_POST['kra_action'] ?? '';

    // â”€â”€ Save KRA entry â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
    if ($kra_action === 'save_kra') {
        if ($locked) {
            echo json_encode(['ok' => false, 'error' => 'Application is locked']);
            exit;
        }

        $category = $_POST['kra_category'] ?? '';
        $remarks  = trim($_POST['remarks'] ?? '');
        $edit_id  = intval($_POST['edit_submission_id'] ?? 0);
        $doc_path = '';

        // In needs_revision mode, only allow saving entries that are flagged for revision
        if ($needs_revision_mode && $edit_id > 0) {
            $rev_check = $pdo->prepare("SELECT revision_status FROM kra_submissions WHERE submission_id=? AND application_id=?");
            $rev_check->execute([$edit_id, $app_id]);
            $rev_row = $rev_check->fetch();
            if (!$rev_row || $rev_row['revision_status'] !== 'needs_revision') {
                echo json_encode(['ok' => false, 'error' => 'Only flagged entries can be edited during revision.']);
                exit;
            }
        }

        $valid_cats = ['Instruction', 'Research', 'Extension', 'Professional Development'];
        if (!in_array($category, $valid_cats)) {
            echo json_encode(['ok' => false, 'error' => 'Invalid KRA category']);
            exit;
        }

        // â”€â”€ Server-side score computation (never trust client-sent score) â”€â”€
        $points = computeKraScore($category, $remarks);

        // Handle file upload — insert into kra_evidence_files (multiple files per submission)
        $new_file_path = '';
        $new_file_name = '';
        $new_file_size = 0;

        // ── Normalise uploaded files: accept single 'evidence' or array 'evidence[]' ──
        $uploaded_files = [];
        foreach (['evidence', 'evidence[]'] as $field) {
            if (!empty($_FILES[$field]['name'])) {
                $names  = (array)$_FILES[$field]['name'];
                $tmps   = (array)$_FILES[$field]['tmp_name'];
                $sizes  = (array)$_FILES[$field]['size'];
                $errors = (array)$_FILES[$field]['error'];
                foreach ($names as $i => $raw_name) {
                    if (!empty($raw_name) && ($errors[$i] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK) {
                        $uploaded_files[] = [
                            'name'     => $raw_name,
                            'tmp_name' => $tmps[$i],
                            'size'     => $sizes[$i],
                        ];
                    }
                }
            }
        }

        $kra_num      = array_search($category, $valid_cats) + 1;
        $project_root = rtrim(str_replace('/', DIRECTORY_SEPARATOR, realpath(__DIR__ . '/../../')), DIRECTORY_SEPARATOR);
        $rel_folder   = 'uploads/kra' . $kra_num . '/';
        $abs_folder   = $project_root . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'kra' . $kra_num . DIRECTORY_SEPARATOR;
        $allowed_exts = ['pdf', 'jpg', 'jpeg', 'png'];

        $files_to_insert = [];
        foreach ($uploaded_files as $uf) {
            $ext = strtolower(pathinfo($uf['name'], PATHINFO_EXTENSION));
            if (!in_array($ext, $allowed_exts)) {
                echo json_encode(['ok' => false, 'error' => 'Invalid file type. Use PDF, JPG, or PNG.']);
                exit;
            }
            if ($uf['size'] > 50 * 1024 * 1024) {
                echo json_encode(['ok' => false, 'error' => 'File too large. Max 50MB.']);
                exit;
            }
            if (!is_dir($abs_folder)) mkdir($abs_folder, 0755, true);
            $fname = uniqid('kra_') . '.' . $ext;
            $moved = move_uploaded_file($uf['tmp_name'], $abs_folder . $fname);
            if (!$moved) {
                echo json_encode(['ok' => false, 'error' => 'Failed to save file. Check folder permissions. Path: ' . $abs_folder]);
                exit;
            }
            $files_to_insert[] = [
                'path' => $rel_folder . $fname,
                'name' => $uf['name'],
                'size' => $uf['size'],
            ];
        }

        // Back-compat: keep single-file vars for any code below that references them
        $new_file_path = $files_to_insert[0]['path'] ?? '';
        $new_file_name = $files_to_insert[0]['name'] ?? '';
        $new_file_size = $files_to_insert[0]['size'] ?? 0;

        // No single-entry categories &mdash; all KRAs now support multiple entries
        // (Instruction has Criterion A, B, C as separate rows)
        $single_entry_cats = [];

        if ($edit_id > 0) {
            // Update existing entry
            $existing = $pdo->prepare("SELECT document_path FROM kra_submissions WHERE submission_id = ? AND application_id = ?");
            $existing->execute([$edit_id, $app_id]);
            $existing = $existing->fetch();
            if ($existing) {
                $pdo->prepare("UPDATE kra_submissions SET computed_points=?, remarks=?, verified=0, submitted_at=NOW() WHERE submission_id=?")
                    ->execute([$points, $remarks, $edit_id]);
                if (!empty($files_to_insert)) {
                    // Check remaining cap before bulk insert
                    $file_count = $pdo->prepare("SELECT COUNT(*) FROM kra_evidence_files WHERE submission_id=?");
                    $file_count->execute([$edit_id]);
                    $cap     = FILE_CAPS[$category] ?? 10;
                    $current = (int)$file_count->fetchColumn();
                    $allowed = $cap - $current;
                    if ($allowed <= 0) {
                        echo json_encode(['ok' => false, 'error' => "File cap reached ({$cap} files max per entry). Remove a file first."]);
                        exit;
                    }
                    $ins_ef = $pdo->prepare("INSERT INTO kra_evidence_files (submission_id, file_path, original_filename, file_size_bytes, uploaded_by) VALUES (?,?,?,?,?)");
                    foreach (array_slice($files_to_insert, 0, $allowed) as $fi) {
                        $ins_ef->execute([$edit_id, $fi['path'], $fi['name'], $fi['size'], $uid]);
                    }
                }
                $saved_sid = $edit_id;
            }
        } elseif (in_array($category, $single_entry_cats)) {
            // Upsert for single-entry categories
            $existing = $pdo->prepare("SELECT submission_id, document_path FROM kra_submissions WHERE application_id=? AND kra_category=? LIMIT 1");
            $existing->execute([$app_id, $category]);
            $existing = $existing->fetch();
            if ($existing) {
                $pdo->prepare("UPDATE kra_submissions SET computed_points=?, remarks=?, verified=0, submitted_at=NOW() WHERE submission_id=?")
                    ->execute([$points, $remarks, $existing['submission_id']]);
                if (!empty($files_to_insert)) {
                    $file_count = $pdo->prepare("SELECT COUNT(*) FROM kra_evidence_files WHERE submission_id=?");
                    $file_count->execute([$existing['submission_id']]);
                    $cap     = FILE_CAPS[$category] ?? 10;
                    $current = (int)$file_count->fetchColumn();
                    $allowed = $cap - $current;
                    if ($allowed <= 0) {
                        echo json_encode(['ok' => false, 'error' => "File cap reached ({$cap} files max per entry). Remove a file first."]);
                        exit;
                    }
                    $ins_ef = $pdo->prepare("INSERT INTO kra_evidence_files (submission_id, file_path, original_filename, file_size_bytes, uploaded_by) VALUES (?,?,?,?,?)");
                    foreach (array_slice($files_to_insert, 0, $allowed) as $fi) {
                        $ins_ef->execute([$existing['submission_id'], $fi['path'], $fi['name'], $fi['size'], $uid]);
                    }
                }
                $saved_sid = $existing['submission_id'];
            } else {
                $pdo->prepare("INSERT INTO kra_submissions (application_id, user_id, kra_category, computed_points, document_path, remarks) VALUES (?,?,?,?,?,?)")
                    ->execute([$app_id, $uid, $category, $points, '', $remarks]);
                $saved_sid = (int)$pdo->lastInsertId();
                if (!empty($files_to_insert)) {
                    $ins_ef = $pdo->prepare("INSERT INTO kra_evidence_files (submission_id, file_path, original_filename, file_size_bytes, uploaded_by) VALUES (?,?,?,?,?)");
                    foreach ($files_to_insert as $fi) {
                        $ins_ef->execute([$saved_sid, $fi['path'], $fi['name'], $fi['size'], $uid]);
                    }
                }
            }
        } else {
            // Insert new entry for multi-entry categories
            $pdo->prepare("INSERT INTO kra_submissions (application_id, user_id, kra_category, computed_points, document_path, remarks) VALUES (?,?,?,?,?,?)")
                ->execute([$app_id, $uid, $category, $points, '', $remarks]);
            $saved_sid = (int)$pdo->lastInsertId();
            if (!empty($files_to_insert)) {
                $ins_ef = $pdo->prepare("INSERT INTO kra_evidence_files (submission_id, file_path, original_filename, file_size_bytes, uploaded_by) VALUES (?,?,?,?,?)");
                foreach ($files_to_insert as $fi) {
                    $ins_ef->execute([$saved_sid, $fi['path'], $fi['name'], $fi['size'], $uid]);
                }
            }
        }

        recalcApplicationScore($pdo, $app_id);
        logAudit($pdo, $uid, 'KRA Saved', "{$category}: {$points} pts");

        // Return evidence files for this submission
        $ef = $pdo->prepare("SELECT evidence_id, file_path, original_filename, file_size_bytes FROM kra_evidence_files WHERE submission_id=? ORDER BY uploaded_at ASC");
        $ef->execute([$saved_sid ?? 0]);
        $evidence_files = $ef->fetchAll(PDO::FETCH_ASSOC);
        $cap = FILE_CAPS[$category] ?? 10;

        echo json_encode([
            'ok'             => true,
            'message'        => 'Entry saved',
            'submission_id'  => $saved_sid ?? 0,
            'evidence_files' => $evidence_files,
            'file_cap'       => $cap,
        ]);
        exit;
    }

    // â”€â”€ Delete KRA entry â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
    if ($kra_action === 'delete_kra') {
        if ($locked) {
            echo json_encode(['ok' => false, 'error' => 'Application is locked']);
            exit;
        }
        $del_id = intval($_POST['submission_id'] ?? 0);
        if ($del_id) {
            // Delete all evidence files for this submission
            $ef_rows = $pdo->prepare("SELECT file_path FROM kra_evidence_files WHERE submission_id=?");
            $ef_rows->execute([$del_id]);
            foreach ($ef_rows->fetchAll(PDO::FETCH_COLUMN) as $fp) {
                $abs = rtrim(str_replace('/', DIRECTORY_SEPARATOR, realpath(__DIR__ . '/../../')), DIRECTORY_SEPARATOR)
                       . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $fp);
                if (file_exists($abs)) @unlink($abs);
            }
            // Also delete legacy document_path if set
            $row = $pdo->prepare("SELECT document_path FROM kra_submissions WHERE submission_id=? AND application_id=?");
            $row->execute([$del_id, $app_id]);
            $row = $row->fetch();
            if ($row && $row['document_path']) {
                $abs_del = rtrim(str_replace('/', DIRECTORY_SEPARATOR, realpath(__DIR__ . '/../../')), DIRECTORY_SEPARATOR)
                           . DIRECTORY_SEPARATOR
                           . str_replace('/', DIRECTORY_SEPARATOR, $row['document_path']);
                if (file_exists($abs_del)) @unlink($abs_del);
            }
            $pdo->prepare("DELETE FROM kra_submissions WHERE submission_id=?")->execute([$del_id]);
            recalcApplicationScore($pdo, $app_id);
            logAudit($pdo, $uid, 'KRA Entry Deleted', "Deleted submission #{$del_id}");
        }
        echo json_encode(['ok' => true]);
        exit;
    }

    // â”€â”€ Delete single evidence file â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
    if ($kra_action === 'delete_evidence') {
        if ($locked) {
            echo json_encode(['ok' => false, 'error' => 'Application is locked']);
            exit;
        }
        $ev_id  = intval($_POST['evidence_id'] ?? 0);
        $sub_id = intval($_POST['submission_id'] ?? 0);
        if ($ev_id && $sub_id) {
            // Verify the submission belongs to this application
            $check = $pdo->prepare("SELECT submission_id FROM kra_submissions WHERE submission_id=? AND application_id=?");
            $check->execute([$sub_id, $app_id]);
            if ($check->fetch()) {
                $ef = $pdo->prepare("SELECT file_path FROM kra_evidence_files WHERE evidence_id=? AND submission_id=?");
                $ef->execute([$ev_id, $sub_id]);
                $ef = $ef->fetch();
                if ($ef) {
                    $abs = rtrim(str_replace('/', DIRECTORY_SEPARATOR, realpath(__DIR__ . '/../../')), DIRECTORY_SEPARATOR)
                           . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $ef['file_path']);
                    if (file_exists($abs)) @unlink($abs);
                    $pdo->prepare("DELETE FROM kra_evidence_files WHERE evidence_id=?")->execute([$ev_id]);
                    logAudit($pdo, $uid, 'Evidence File Deleted', "Deleted evidence #{$ev_id} from submission #{$sub_id}");
                }
            }
        }
        echo json_encode(['ok' => true]);
        exit;
    }
}

echo json_encode(['ok' => false, 'error' => 'Unknown request']);

