<?php
// Evaluator/admin AJAX: mark a KRA submission acceptable. Required by index.php.
defined('APP_ROOT') || exit;

// -- AJAX: mark KRA submission acceptable  -  intercept BEFORE any header is sent --
if (
    isset($_SERVER['HTTP_X_REQUESTED_WITH']) &&
    $_SERVER['HTTP_X_REQUESTED_WITH'] === 'XMLHttpRequest' &&
    in_array(($_POST['action'] ?? ''), ['verify_kra', 'verify_submission'], true)
) {
    require_once 'backend/database/config/db.php';
    if (!isLoggedIn()) { http_response_code(403); echo json_encode(['ok'=>false,'error'=>'Unauthorized']); exit; }
    header('Content-Type: application/json');

    $app_id = intval($_GET['id'] ?? 0);
    $sub_id = intval($_POST['submission_id'] ?? 0);
    $note   = trim($_POST['note'] ?? '');
    $uid    = $_SESSION['user_id'];

    if (!$app_id || !$sub_id) {
        echo json_encode(['ok' => false, 'error' => 'Invalid request.']);
        exit;
    }

    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS kra_checker_verifications (
            ckv_id        INT AUTO_INCREMENT PRIMARY KEY,
            submission_id INT NOT NULL,
            checker_id    INT NOT NULL,
            verified_at   TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uq_sub_checker (submission_id, checker_id),
            FOREIGN KEY (submission_id) REFERENCES kra_submissions(submission_id) ON DELETE CASCADE,
            FOREIGN KEY (checker_id)    REFERENCES users(user_id) ON DELETE CASCADE
        )");
    } catch (\Exception $e) {}
    try { $pdo->query("SELECT checker_note FROM kra_submissions LIMIT 1"); }
    catch (\Exception $e) {
        try { $pdo->exec("ALTER TABLE kra_submissions ADD COLUMN checker_note TEXT DEFAULT NULL"); }
        catch (\Exception $e2) {}
    }
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS application_checker_reviews (
            review_id      INT AUTO_INCREMENT PRIMARY KEY,
            application_id INT NOT NULL,
            checker_id     INT NOT NULL,
            decision       ENUM('approved','rejected','pending') DEFAULT 'pending',
            remarks        TEXT,
            decided_at     TIMESTAMP NULL,
            created_at     TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uq_app_checker (application_id, checker_id),
            FOREIGN KEY (application_id) REFERENCES applications(application_id) ON DELETE CASCADE,
            FOREIGN KEY (checker_id)     REFERENCES users(user_id) ON DELETE CASCADE
        )");
    } catch (\Exception $e) {}

    $app_row = $pdo->prepare("SELECT a.application_id, a.status, a.cycle_id, a.checker_id, a.user_id, u.full_name
        FROM applications a
        JOIN users u ON u.user_id = a.user_id
        WHERE a.application_id = ?");
    $app_row->execute([$app_id]);
    $app_row = $app_row->fetch();

    if (!$app_row) {
        echo json_encode(['ok' => false, 'error' => 'Application not found.']);
        exit;
    }

    if (!in_array($app_row['status'], ['under_review', 'talisay_review', 'needs_revision'], true)) {
        echo json_encode(['ok' => false, 'error' => 'This application is not under active review.']);
        exit;
    }

    $sub_row = $pdo->prepare("SELECT submission_id, kra_category, computed_points FROM kra_submissions WHERE submission_id = ? AND application_id = ?");
    $sub_row->execute([$sub_id, $app_id]);
    $sub_row = $sub_row->fetch();
    if (!$sub_row) {
        echo json_encode(['ok' => false, 'error' => 'KRA submission not found.']);
        exit;
    }

    if (!isAdmin()) {
        ensureKraAssignmentTable($pdo);
        if (!checkerCanReviewKra($pdo, (int)$uid, (int)$app_row['cycle_id'], $sub_row['kra_category'])) {
            http_response_code(403);
            echo json_encode(['ok' => false, 'error' => 'You are not assigned to review that KRA for this cycle.']);
            exit;
        }
    }

    try {
        $pdo->prepare("INSERT IGNORE INTO application_checker_reviews
            (application_id, checker_id, decision) VALUES (?, ?, 'pending')")
            ->execute([$app_id, $uid]);
    } catch (\Exception $e) {}

    $pdo->prepare("INSERT IGNORE INTO kra_checker_verifications (submission_id, checker_id) VALUES (?, ?)")
        ->execute([$sub_id, $uid]);
    $pdo->prepare("UPDATE kra_submissions
        SET faculty_original_score = COALESCE(faculty_original_score, computed_points),
            checker_note = ?
        WHERE submission_id = ? AND application_id = ?")
        ->execute([$note ?: null, $sub_id, $app_id]);

    $total_ck = isAdmin()
        ? 1
        : countAssignedCheckersForKra($pdo, (int)$app_row['cycle_id'], $sub_row['kra_category']);
    $total_ck = max(1, $total_ck);
    $done_stmt = $pdo->prepare("SELECT COUNT(*) FROM kra_checker_verifications WHERE submission_id = ?");
    $done_stmt->execute([$sub_id]);
    $done_ck = (int)$done_stmt->fetchColumn();
    $all_verified = ($total_ck > 0 && $done_ck >= $total_ck);
    if ($all_verified) {
        $pdo->prepare("UPDATE kra_submissions SET verified = 1, verified_by = ?, verified_at = NOW() WHERE submission_id = ? AND application_id = ?")
            ->execute([$uid, $sub_id, $app_id]);
    }

    logAudit($pdo, $uid, 'Evidence Marked Acceptable', "You marked {$app_row['full_name']}'s {$sub_row['kra_category']} entry acceptable." . ($note ? " Note: {$note}" : ''));

    echo json_encode([
        'ok' => true,
        'submission_id' => $sub_id,
        'done_count' => $done_ck,
        'total_count' => $total_ck,
        'all_verified' => $all_verified,
    ]);
    exit;
}
