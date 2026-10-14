<?php
// Request handling and data loading for frontend/views/shared/review_application.php
$app_id = intval($_GET['id'] ?? 0);
if (!$app_id) { echo '<div class="alert alert-danger">Invalid application.</div>'; return false; }

// -- Runtime migrations ----------------------------------------
try { $pdo->query("SELECT evidence_id FROM kra_evidence_files LIMIT 1"); }
catch (\Exception $e) {
    $pdo->exec("CREATE TABLE IF NOT EXISTS kra_evidence_files (
        evidence_id INT AUTO_INCREMENT PRIMARY KEY,
        submission_id INT NOT NULL,
        file_path VARCHAR(255) NOT NULL,
        original_filename VARCHAR(255) NOT NULL,
        file_size_bytes INT DEFAULT 0,
        uploaded_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        uploaded_by INT DEFAULT NULL,
        FOREIGN KEY (submission_id) REFERENCES kra_submissions(submission_id) ON DELETE CASCADE,
        FOREIGN KEY (uploaded_by) REFERENCES users(user_id) ON DELETE SET NULL
    )");
}
try { $pdo->query("SELECT revision_status FROM kra_submissions LIMIT 1"); }
catch (\Exception $e) {
    $pdo->exec("ALTER TABLE kra_submissions
        ADD COLUMN revision_status ENUM('ok','needs_revision') DEFAULT 'ok' AFTER verified,
        ADD COLUMN revision_note TEXT DEFAULT NULL AFTER revision_status,
        ADD COLUMN revision_by INT DEFAULT NULL AFTER revision_note,
        ADD COLUMN revision_at TIMESTAMP NULL AFTER revision_by");
}
try { $pdo->query("SELECT checker_note FROM kra_submissions LIMIT 1"); }
catch (\Exception $e) {
    $pdo->exec("ALTER TABLE kra_submissions ADD COLUMN checker_note TEXT DEFAULT NULL AFTER revision_at");
}
// Per-checker verification tracking
try { $pdo->query("SELECT ckv_id FROM kra_checker_verifications LIMIT 1"); }
catch (\Exception $e) {
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
        $pdo->exec("INSERT IGNORE INTO kra_checker_verifications (submission_id, checker_id, verified_at)
            SELECT submission_id, verified_by, COALESCE(verified_at, NOW())
            FROM kra_submissions WHERE verified=1 AND verified_by IS NOT NULL");
    } catch (\Exception $e2) {}
}
// -- faculty_original_score: snapshot of faculty's submitted score --------
try { $pdo->query("SELECT faculty_original_score FROM kra_submissions LIMIT 1"); }
catch (\Exception $e) {
    $pdo->exec("ALTER TABLE kra_submissions
        ADD COLUMN faculty_original_score DECIMAL(6,2) DEFAULT NULL AFTER computed_points");
    $pdo->exec("UPDATE kra_submissions SET faculty_original_score = computed_points
        WHERE faculty_original_score IS NULL");
}
try { $pdo->query("SELECT review_id FROM application_checker_reviews LIMIT 1"); }
catch (\Exception $e) {
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
}
// Runtime migration: add 'rejected' to decision ENUM if table already exists
try {
    $col = $pdo->query("SHOW COLUMNS FROM application_checker_reviews LIKE 'decision'")->fetch();
    if ($col && !str_contains($col['Type'], 'rejected')) {
        $pdo->exec("ALTER TABLE application_checker_reviews
            MODIFY COLUMN decision ENUM('approved','rejected','pending') DEFAULT 'pending'");
    }
} catch (\Exception $e) {}

$return_page = isAdmin() ? 'view_application' : 'review_application';

// -- Load application ------------------------------------------
$app = $pdo->prepare("SELECT a.*, u.full_name, u.first_name, u.middle_name, u.last_name, u.email, u.employee_id, u.rank,
    COALESCE(camp.campus_name, 'N/A') as campus_name, c.cycle_name
    FROM applications a
    JOIN users u ON a.user_id = u.user_id
    LEFT JOIN campuses camp ON u.campus_id = camp.campus_id
    LEFT JOIN cycles c ON a.cycle_id = c.cycle_id
    WHERE a.application_id = ?");
$app->execute([$app_id]);
$app = $app->fetch();
if (!$app) { echo '<div class="alert alert-danger">Application not found.</div>'; return false; }
ensureKraAssignmentTable($pdo);
$my_checker_id = (int)($_SESSION['user_id'] ?? 0);
$assigned_kras = isChecker() ? getCheckerAssignedKras($pdo, $my_checker_id, (int)$app['cycle_id']) : array_keys(kraAssignmentCategories());

function reviewSubmissionCategory(PDO $pdo, int $submission_id, int $application_id): ?string {
    $stmt = $pdo->prepare("SELECT kra_category FROM kra_submissions WHERE submission_id=? AND application_id=?");
    $stmt->execute([$submission_id, $application_id]);
    $cat = $stmt->fetchColumn();
    return $cat ?: null;
}

function blockUnassignedKra(PDO $pdo, int $checker_id, int $cycle_id, ?string $kra_category, int $app_id, string $return_page): void {
    if (!$kra_category || !checkerCanReviewKra($pdo, $checker_id, $cycle_id, $kra_category)) {
        if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && $_SERVER['HTTP_X_REQUESTED_WITH'] === 'XMLHttpRequest') {
            header('Content-Type: application/json');
            echo json_encode(['ok' => false, 'error' => 'You are not assigned to review that KRA for this cycle.']);
            exit;
        }
        flashMessage('danger', 'You are not assigned to review that KRA for this cycle.');
        echo "<script>window.location.href='index.php?page={$return_page}&id={$app_id}#kra-verification';</script>";
        exit;
    }
}

// -- Helper: get all checker reviews for this application ------
function getCheckerReviews($pdo, int $app_id): array {
    $stmt = $pdo->prepare("SELECT r.*, u.full_name
        FROM application_checker_reviews r
        JOIN users u ON r.checker_id = u.user_id
        WHERE r.application_id = ?
        ORDER BY r.created_at ASC");
    $stmt->execute([$app_id]);
    return $stmt->fetchAll();
}

// -- Helper: ensure this checker has a review slot ------------
function ensureCheckerSlot($pdo, int $app_id, int $checker_id): void {
    $pdo->prepare("INSERT IGNORE INTO application_checker_reviews
        (application_id, checker_id, decision) VALUES (?, ?, 'pending')")
        ->execute([$app_id, $checker_id]);
}

// -- Helper: count approved checkers --------------------------
function countApprovedCheckers($pdo, int $app_id): int {
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM application_checker_reviews
        WHERE application_id = ? AND decision = 'approved'");
    $stmt->execute([$app_id]);
    return (int)$stmt->fetchColumn();
}

// -- Helper: count rejected checkers --------------------------
function countRejectedCheckers($pdo, int $app_id): int {
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM application_checker_reviews
        WHERE application_id = ? AND decision = 'rejected'");
    $stmt->execute([$app_id]);
    return (int)$stmt->fetchColumn();
}

// -- Helper: total active checkers in the system ---------------
function countActiveCheckers($pdo, int $cycle_id = 0): int {
    if ($cycle_id) return countAssignedCheckersForCycle($pdo, $cycle_id);
    $stmt = $pdo->query("SELECT COUNT(*) FROM users WHERE role = 'evaluator' AND status = 'active'");
    return max(1, (int)$stmt->fetchColumn());
}

// -- Helper: count checkers who have joined this application --
function countSlotsTaken($pdo, int $app_id): int {
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM application_checker_reviews WHERE application_id=?");
    $stmt->execute([$app_id]);
    return (int)$stmt->fetchColumn();
}

// -- POST handlers ---------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action     = $_POST['action'] ?? '';
    $remarks    = trim($_POST['checker_remarks'] ?? '');
    $checker_id = $_SESSION['user_id'];

    // Start review  -  first checker to act moves app to under_review
    if ($action === 'start_review' && $app['status'] === 'submitted') {
        if (isChecker() && !$assigned_kras) {
            flashMessage('danger', 'You do not have any KRA assignments for this cycle.');
            echo "<script>window.location.href='index.php?page={$return_page}&id={$app_id}';</script>"; exit;
        }
        $pdo->prepare("UPDATE applications SET status='under_review', checker_id=? WHERE application_id=?")
            ->execute([$checker_id, $app_id]);
        logAudit($pdo, $checker_id, 'Review Started', "You started reviewing {$app['full_name']}'s application.");
        // Notify faculty their application is now under review
        createNotif($pdo, $app['user_id'], 'under_review', 'Your application is now under review by the Subcommittee.', $app_id);
        flashMessage('success', 'Review started.');
        echo "<script>window.location.href='index.php?page={$return_page}&id={$app_id}';</script>"; exit;
    }

    // Join review action is no longer needed - kept for backward compat redirect only
    if ($action === 'join_review') {
        echo "<script>window.location.href='index.php?page={$return_page}&id={$app_id}';</script>"; exit;
    }

    // -- Checker alters a KRA score ---------------------------
    if ($action === 'alter_score' && in_array($app['status'], ['under_review','talisay_review','needs_revision'])
        && (isEvaluator() || isAdmin())) {
        $sub_id    = intval($_POST['submission_id'] ?? 0);
        $new_pts   = floatval($_POST['new_points'] ?? 0);
        $alter_note = trim($_POST['alter_note'] ?? '');
        if ($sub_id && $new_pts >= 0) {
            // Fetch old value for audit
            $old = $pdo->prepare("SELECT computed_points, kra_category FROM kra_submissions WHERE submission_id=? AND application_id=?");
            $old->execute([$sub_id, $app_id]);
            $old = $old->fetch();
            if ($old) {
                blockUnassignedKra($pdo, $checker_id, (int)$app['cycle_id'], $old['kra_category'], $app_id, $return_page);
                $pdo->prepare("UPDATE kra_submissions
                    SET computed_points=?,
                        faculty_original_score = COALESCE(faculty_original_score, computed_points),
                        verified=1, verified_by=?, verified_at=NOW(),
                        checker_note=?
                    WHERE submission_id=? AND application_id=?")
                    ->execute([$new_pts, $checker_id, $alter_note ?: null, $sub_id, $app_id]);

                // Log for the checker (their own activity)
                logAudit($pdo, $checker_id, 'Score Adjusted', "You adjusted {$app['full_name']}'s {$old['kra_category']} score from {$old['computed_points']} to {$new_pts}." . ($alter_note ? " Note: {$alter_note}" : ''));

                // Log for the faculty (so it appears in their activity log)
                $checker_name = $_SESSION['checker_label'] ?? "Evaluator #{$checker_id}";
                // Translate any legacy "Checker #N" label to "Evaluator #N"
                $checker_name = preg_replace('/^Checker\s*#/i', 'Evaluator #', $checker_name);
                logAudit($pdo, $app['user_id'], 'Score Adjusted by Evaluator', "{$old['kra_category']} score adjusted from {$old['computed_points']} to {$new_pts} by {$checker_name}." . ($alter_note ? " Note: {$alter_note}" : ''));

                // Notify faculty of score adjustment
                createNotif($pdo, $app['user_id'], 'score_adjusted',
                    "{$old['kra_category']} score adjusted from {$old['computed_points']} to {$new_pts} by {$checker_name}." . ($alter_note ? " Remark: {$alter_note}" : '') . " You may file an appeal from My Application.",
                    $app_id);

                // Recalculate weighted score
                recalcApplicationScore($pdo, $app_id);

                // Check if weighted score dropped below 41  -  auto-return if so
                $new_app = $pdo->prepare("SELECT weighted_score FROM applications WHERE application_id=?");
                $new_app->execute([$app_id]);
                $new_weighted = (float)$new_app->fetchColumn();

                if ($new_weighted < 41) {
                    // Return application to faculty automatically
                    $auto_remark = "Application automatically returned: weighted score dropped to {$new_weighted} (below minimum 41) after evaluator adjusted {$old['kra_category']} score from {$old['computed_points']} to {$new_pts}.";
                    $pdo->prepare("UPDATE applications SET status='rejected', reviewed_at=NOW(), checker_remarks=? WHERE application_id=?")
                        ->execute([$auto_remark, $app_id]);
                    logAudit($pdo, $checker_id, 'Auto-Returned', "Application #{$app_id} auto-returned  -  weighted score {$new_weighted} fell below 41 after score adjustment.");
                    logAudit($pdo, $app['user_id'], 'Application Returned', "Your application was automatically returned because the weighted score ({$new_weighted}) fell below the minimum of 41 after a score adjustment by {$checker_name}.");
                    createNotif($pdo, $app['user_id'], 'rejected',
                        "Your application has been automatically returned. The weighted score ({$new_weighted}) fell below the minimum of 41 after a score adjustment. Please update your KRA entries and resubmit.",
                        $app_id);
                    flashMessage('warning', "Score adjusted. The resulting weighted score (<strong>{$new_weighted}</strong>) is below the minimum of 41  -  the application has been <strong>automatically returned</strong> to the faculty.");

                    if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && $_SERVER['HTTP_X_REQUESTED_WITH'] === 'XMLHttpRequest') {
                        header('Content-Type: application/json');
                        echo json_encode(['ok' => true, 'auto_returned' => true, 'weighted' => $new_weighted]);
                        exit;
                    }
                    echo "<script>window.location.href='index.php?page={$return_page}&id={$app_id}';</script>"; exit;
                }

                flashMessage('success', "Score updated: {$old['kra_category']} changed from <strong>{$old['computed_points']}</strong> to <strong>{$new_pts}</strong>.");
            }
        }
        // AJAX call  -  return JSON, no redirect
        if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && $_SERVER['HTTP_X_REQUESTED_WITH'] === 'XMLHttpRequest') {
            header('Content-Type: application/json');
            echo json_encode(['ok' => true]);
            exit;
        }
        echo "<script>window.location.href='index.php?page={$return_page}&id={$app_id}&score_saved=1#kra-verification';</script>"; exit;
    }

    // Request revision on a specific KRA entry - requires review already started
    if ($action === 'request_revision' && in_array($app['status'], ['under_review','needs_revision'])) {
        $sub_id   = intval($_POST['submission_id'] ?? 0);
        $rev_note = trim($_POST['revision_note'] ?? '');
        if ($sub_id && $rev_note) {
            $rev_cat = reviewSubmissionCategory($pdo, $sub_id, $app_id);
            blockUnassignedKra($pdo, $checker_id, (int)$app['cycle_id'], $rev_cat, $app_id, $return_page);
            // Auto-create a slot for this checker if they don't have one yet
            ensureCheckerSlot($pdo, $app_id, $checker_id);
            // Move to under_review if still submitted
            $pdo->prepare("UPDATE applications SET status='under_review', checker_id=?
                WHERE application_id=? AND status='submitted'")
                ->execute([$checker_id, $app_id]);
            $pdo->prepare("UPDATE kra_submissions SET revision_status='needs_revision',
                revision_note=?, revision_by=?, revision_at=NOW(), verified=0
                WHERE submission_id=? AND application_id=?")
                ->execute([$rev_note, $checker_id, $sub_id, $app_id]);
            $pdo->prepare("UPDATE applications SET status='needs_revision', checker_remarks=?
                WHERE application_id=?")
                ->execute(["One or more KRA entries need revision. Please check the highlighted entries and resubmit.", $app_id]);
            // Get the KRA category for a readable log message
            $rev_cat = $rev_cat ?: 'KRA';
            logAudit($pdo, $checker_id, 'Revision Requested', "You requested a revision on {$app['full_name']}'s {$rev_cat} entry. Note: {$rev_note}");
            // Notify faculty to fix flagged KRA entry  -  deep-links to that entry's KRA tab
            createNotif($pdo, $app['user_id'], 'needs_revision',
                "An evaluator flagged your {$rev_cat} entry for revision. Remark: {$rev_note}. You may revise it or file an appeal from My Application.",
                $app_id, $sub_id);
            flashMessage('warning', 'Revision requested. Faculty can now edit only the flagged entry and resubmit.');
            echo "<script>window.location.href='index.php?page={$return_page}&id={$app_id}';</script>"; exit;
        }
    }

    // Clear a revision flag  -  only allowed after faculty resubmits (status back to under_review)
    if ($action === 'clear_revision' && in_array($app['status'], ['submitted','under_review'])) {
        $sub_id = intval($_POST['submission_id'] ?? 0);
        if ($sub_id) {
            $clear_cat = reviewSubmissionCategory($pdo, $sub_id, $app_id);
            blockUnassignedKra($pdo, $checker_id, (int)$app['cycle_id'], $clear_cat, $app_id, $return_page);
            $pdo->prepare("UPDATE kra_submissions SET revision_status='ok',
                revision_note=NULL, revision_by=NULL, revision_at=NULL
                WHERE submission_id=? AND application_id=?")
                ->execute([$sub_id, $app_id]);
            logAudit($pdo, $checker_id, 'Revision Cleared', "You cleared the revision flag on {$app['full_name']}'s KRA entry.");
            flashMessage('success', 'Revision cleared. Entry marked as OK.');
            echo "<script>window.location.href='index.php?page={$return_page}&id={$app_id}';</script>"; exit;
        }
    }

    // -- Quick-verify a submission (per-checker) -----------
    if ($action === 'verify_submission'
        && in_array($app['status'], ['under_review','talisay_review','needs_revision'])
        && (isEvaluator() || isAdmin())) {
        $sub_id = intval($_POST['submission_id'] ?? 0);
        $verify_note = trim($_POST['note'] ?? '');
        if ($sub_id) {
            $row = $pdo->prepare("SELECT kra_category FROM kra_submissions WHERE submission_id=? AND application_id=?");
            $row->execute([$sub_id, $app_id]);
            $row = $row->fetch();
            if ($row) {
                blockUnassignedKra($pdo, $checker_id, (int)$app['cycle_id'], $row['kra_category'], $app_id, $return_page);
                // Record this checker's individual review acceptance
                $pdo->prepare("INSERT IGNORE INTO kra_checker_verifications (submission_id, checker_id) VALUES (?,?)")
                    ->execute([$sub_id, $checker_id]);
                // Stamp faculty_original_score if not yet set
                $pdo->prepare("UPDATE kra_submissions SET faculty_original_score = COALESCE(faculty_original_score, computed_points) WHERE submission_id=?")
                    ->execute([$sub_id]);
                // Save the checker's note (same field alter_score writes to)
                $pdo->prepare("UPDATE kra_submissions SET checker_note=? WHERE submission_id=?")
                    ->execute([$verify_note ?: null, $sub_id]);
                // If all active checkers have marked it acceptable, set the global verified=1
                $total_ck = countAssignedCheckersForKra($pdo, (int)$app['cycle_id'], $row['kra_category']);
                // Simpler: just count directly
                $done_stmt = $pdo->prepare("SELECT COUNT(*) FROM kra_checker_verifications WHERE submission_id=?");
                $done_stmt->execute([$sub_id]);
                $done_ck = (int)$done_stmt->fetchColumn();
                if ($total_ck > 0 && $done_ck >= $total_ck) {
                    $pdo->prepare("UPDATE kra_submissions SET verified=1, verified_by=?, verified_at=NOW() WHERE submission_id=?")
                        ->execute([$checker_id, $sub_id]);
                }
                logAudit($pdo, $checker_id, 'Evidence Marked Acceptable', "You marked {$app['full_name']}'s {$row['kra_category']} entry acceptable." . ($verify_note ? " Note: {$verify_note}" : ''));
            }
        }
        if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && $_SERVER['HTTP_X_REQUESTED_WITH'] === 'XMLHttpRequest') {
            header('Content-Type: application/json');
            $done_stmt2 = $pdo->prepare("SELECT COUNT(*) FROM kra_checker_verifications WHERE submission_id=?");
            $done_stmt2->execute([$sub_id]);
            $done_final  = (int)$done_stmt2->fetchColumn();
            $total_final = countAssignedCheckersForKra($pdo, (int)$app['cycle_id'], $row['kra_category'] ?? '');
            echo json_encode([
                'ok'           => true,
                'done_count'   => $done_final,
                'total_count'  => $total_final,
                'all_verified' => $total_final > 0 && $done_final >= $total_final,
            ]);
            exit;
        }
        echo "<script>window.location.href='index.php?page={$return_page}&id={$app_id}#kra-verification';</script>"; exit;
    }

    // -- Checker completes evaluation --------------------------
    if ($action === 'checker_approve' && in_array($app['status'], ['submitted','under_review'])) {
        if (isChecker() && !$assigned_kras) {
            flashMessage('danger', 'You do not have any KRA assignments for this cycle.');
            echo "<script>window.location.href='index.php?page={$return_page}&id={$app_id}';</script>"; exit;
        }

        // Block completion if any KRA submission is not acceptable
        $unverified = $pdo->prepare("SELECT COUNT(*) FROM kra_submissions WHERE application_id = ? AND verified = 0");
        $unverified->execute([$app_id]);
        if ((int)$unverified->fetchColumn() > 0) {
            flashMessage('danger', 'You must mark <strong>all evidence files</strong> acceptable before completing this evaluation. Please review each KRA entry first.');
            echo "<script>window.location.href='index.php?page={$return_page}&id={$app_id}';</script>"; exit;
        }

        // Block completion if weighted score is below 41
        $ws_check = $pdo->prepare("SELECT weighted_score FROM applications WHERE application_id=?");
        $ws_check->execute([$app_id]);
        $current_weighted = (float)$ws_check->fetchColumn();
        if ($current_weighted < 41) {
            flashMessage('danger', "This evaluation cannot be completed while the weighted score (<strong>{$current_weighted}</strong>) is below the minimum of <strong>41</strong>. Please return it to the faculty for revision.");
            echo "<script>window.location.href='index.php?page={$return_page}&id={$app_id}';</script>"; exit;
        }
        // Auto-move to under_review if still submitted
        $pdo->prepare("UPDATE applications SET status='under_review', checker_id=?
            WHERE application_id=? AND status='submitted'")
            ->execute([$checker_id, $app_id]);

        // Upsert this checker's decision
        $pdo->prepare("INSERT INTO application_checker_reviews
            (application_id, checker_id, decision, remarks, decided_at)
            VALUES (?, ?, 'approved', ?, NOW())
            ON DUPLICATE KEY UPDATE decision='approved', remarks=VALUES(remarks), decided_at=NOW()")
            ->execute([$app_id, $checker_id, $remarks]);

        recalcApplicationScore($pdo, $app_id);
        $total_checkers = countActiveCheckers($pdo, (int)$app['cycle_id']);
        $approved_count = countApprovedCheckers($pdo, $app_id);
        logAudit($pdo, $checker_id, 'Evaluation Completed by Evaluator', "You completed evaluation for {$app['full_name']}'s application. ({$approved_count}/{$total_checkers} evaluator completions)");

        if ($approved_count >= $total_checkers) {
            $pdo->prepare("UPDATE applications SET status='approved', reviewed_at=NOW(),
                checker_remarks=? WHERE application_id=?")
                ->execute(["Evaluation completed by all {$total_checkers} evaluator(s). Result is for further committee review and is not a final decision.", $app_id]);
            logAudit($pdo, $checker_id, 'Evaluation Complete', "Evaluation completed for {$app['full_name']}'s application by all {$total_checkers} evaluator(s).");
            createNotif($pdo, $app['user_id'], 'approved',
                'Your evaluation is complete. The result is a recommendation for further committee review and is not a final decision.',
                $app_id);
            flashMessage('success', "Evaluation complete. The score and recommended rank are ready for further committee review.");
        } else {
            $remaining = $total_checkers - $approved_count;
            flashMessage('success', "Your evaluation has been recorded ({$approved_count}/{$total_checkers} evaluators). Waiting for {$remaining} more evaluator(s).");
        }
        echo "<script>window.location.href='index.php?page={$return_page}&id={$app_id}';</script>"; exit;
    }

    // -- ITC evaluator approves ------------------------------
    if ($action === 'talisay_approve' && $app['status'] === 'talisay_review' && isEvaluator()) {

        $ws_tal = $pdo->prepare("SELECT weighted_score FROM applications WHERE application_id=?");
        $ws_tal->execute([$app_id]);
        $tal_weighted = (float)$ws_tal->fetchColumn();
        if ($tal_weighted < 41) {
            flashMessage('danger', "This evaluation cannot be completed while the weighted score (<strong>{$tal_weighted}</strong>) is below the minimum of <strong>41</strong>. Please return it to the faculty for revision.");
            echo "<script>window.location.href='index.php?page={$return_page}&id={$app_id}';</script>"; exit;
        }
        $pdo->prepare("INSERT INTO application_checker_reviews
            (application_id, checker_id, decision, remarks, decided_at)
            VALUES (?, ?, 'approved', ?, NOW())
            ON DUPLICATE KEY UPDATE decision='approved', remarks=VALUES(remarks), decided_at=NOW()")
            ->execute([$app_id, $checker_id, $remarks]);

        $total_evaluators = countActiveCheckers($pdo, (int)$app['cycle_id']);
        $ta_stmt = $pdo->prepare("SELECT COUNT(*) FROM application_checker_reviews r
            JOIN users u ON r.checker_id=u.user_id
            WHERE r.application_id=? AND r.decision='approved' AND u.role='evaluator'");
        $ta_stmt->execute([$app_id]);
        $evaluators_approved = (int)$ta_stmt->fetchColumn();

        logAudit($pdo, $checker_id, 'Evaluation Completed by Evaluator', "You completed evaluation for {$app['full_name']}'s application. ({$evaluators_approved}/{$total_evaluators} evaluator completions)");

        if ($evaluators_approved >= $total_evaluators) {
            $pdo->prepare("UPDATE applications SET status='approved', reviewed_at=NOW(),
                checker_remarks=? WHERE application_id=?")
                ->execute(["Evaluation completed by all evaluator(s). Result is for further committee review and is not a final decision.", $app_id]);
            logAudit($pdo, $checker_id, 'Evaluation Complete', "Evaluation completed for {$app['full_name']}'s application.");
            createNotif($pdo, $app['user_id'], 'approved',
                'Your evaluation is complete. The result is a recommendation for further committee review and is not a final decision.',
                $app_id);
            flashMessage('success', "Evaluation complete. The score and recommended rank are ready for further committee review.");
        } else {
            flashMessage('success', "Your evaluation has been recorded ({$evaluators_approved}/{$total_evaluators} evaluators). Waiting for remaining evaluators.");
        }
        echo "<script>window.location.href='index.php?page={$return_page}&id={$app_id}';</script>"; exit;
    }

    // -- ITC evaluator rejects -------------------------------
    if ($action === 'talisay_reject' && $app['status'] === 'talisay_review' && isEvaluator()) {
        if (empty($remarks)) {
            flashMessage('danger', 'A reason is required when returning an application for revision.');
            echo "<script>window.location.href='index.php?page={$return_page}&id={$app_id}';</script>"; exit;
        }
        $pdo->prepare("INSERT INTO application_checker_reviews
            (application_id, checker_id, decision, remarks, decided_at)
            VALUES (?, ?, 'rejected', ?, NOW())
            ON DUPLICATE KEY UPDATE decision='rejected', remarks=VALUES(remarks), decided_at=NOW()")
            ->execute([$app_id, $checker_id, $remarks]);
        $pdo->prepare("UPDATE applications SET status='rejected', reviewed_at=NOW(), checker_remarks=? WHERE application_id=?")
            ->execute(["Returned for revision by evaluator: {$remarks}", $app_id]);
        logAudit($pdo, $checker_id, 'Returned for Revision', "You returned {$app['full_name']}'s application for revision. Reason: {$remarks}");
        createNotif($pdo, $app['user_id'], 'rejected',
            "Your application has been returned for revision. Reason: {$remarks}",
            $app_id);
        flashMessage('warning', 'Application has been returned to the faculty.');
        echo "<script>window.location.href='index.php?page={$return_page}&id={$app_id}';</script>"; exit;
    }

    // -- Checker rejects ---------------------------------------
    if ($action === 'checker_reject' && in_array($app['status'], ['submitted','under_review'])) {
        if (isChecker() && !$assigned_kras) {
            flashMessage('danger', 'You do not have any KRA assignments for this cycle.');
            echo "<script>window.location.href='index.php?page={$return_page}&id={$app_id}';</script>"; exit;
        }
        if (empty($remarks)) {
            flashMessage('danger', 'A reason is required when returning an application for revision.');
            echo "<script>window.location.href='index.php?page={$return_page}&id={$app_id}';</script>"; exit;
        }

        // Auto-move to under_review if still submitted
        $pdo->prepare("UPDATE applications SET status='under_review', checker_id=?
            WHERE application_id=? AND status='submitted'")
            ->execute([$checker_id, $app_id]);

        // Upsert this checker's decision
        $pdo->prepare("INSERT INTO application_checker_reviews
            (application_id, checker_id, decision, remarks, decided_at)
            VALUES (?, ?, 'rejected', ?, NOW())
            ON DUPLICATE KEY UPDATE decision='rejected', remarks=VALUES(remarks), decided_at=NOW()")
            ->execute([$app_id, $checker_id, $remarks]);

        $total_checkers = countActiveCheckers($pdo, (int)$app['cycle_id']);
        $rejected_count = countRejectedCheckers($pdo, $app_id);
        logAudit($pdo, $checker_id, 'Returned for Revision', "You returned {$app['full_name']}'s application for revision. Reason: {$remarks}");

        if ($rejected_count >= $total_checkers) {
            $all_remarks_stmt = $pdo->prepare("SELECT u.user_id, u.checker_label, r.remarks
                FROM application_checker_reviews r
                JOIN users u ON r.checker_id = u.user_id
                WHERE r.application_id = ? AND r.decision = 'rejected'");
            $all_remarks_stmt->execute([$app_id]);
            $combined = implode(' | ', array_map(
                fn($row) => checkerDisplayLabel($row) . ': ' . $row['remarks'],
                $all_remarks_stmt->fetchAll()
            ));
            $pdo->prepare("UPDATE applications SET status='rejected', reviewed_at=NOW(),
                checker_remarks=? WHERE application_id=?")
                ->execute(["Returned for revision by all {$total_checkers} evaluator(s). {$combined}", $app_id]);
            logAudit($pdo, $checker_id, 'Application Returned for Revision', "You returned {$app['full_name']}'s application for revision.");
            createNotif($pdo, $app['user_id'], 'rejected',
                "Your application has been returned for revision. Please review the remarks and make corrections.",
                $app_id);
            flashMessage('warning', "Return for revision recorded. The application has been returned to the faculty.");
        } else {
            flashMessage('warning', "Return for revision recorded ({$rejected_count}/{$total_checkers} evaluator notes so far). The application stays <strong>Under Evaluation</strong> until all evaluators have finished.");
        }
        echo "<script>window.location.href='index.php?page={$return_page}&id={$app_id}';</script>"; exit;
    }

    // -- Admin reject only -------------------------------------
    if ($action === 'admin_reject' && isAdmin() && $app['status'] === 'approved') {
        $admin_remarks = trim($_POST['admin_remarks'] ?? '');
        $pdo->prepare("UPDATE applications SET status='admin_rejected', checker_remarks=?, reviewed_at=NOW()
            WHERE application_id=?")
            ->execute([$admin_remarks ?: 'Returned by admin.', $app_id]);
        logAudit($pdo, $_SESSION['user_id'], 'Application Returned by Admin', "Admin returned {$app['full_name']}'s application. Remarks: {$admin_remarks}");
        flashMessage('warning', "Application has been returned for revision.");
        echo "<script>window.location.href='index.php?page=all_applications&filter=approved';</script>"; exit;
    }

    // -- Auto Sub-Rank: checker verifies or rejects evidence ---------------
    if ($action === 'verify_asr_evidence'
        && in_array($app['status'], ['under_review','talisay_review','needs_revision','submitted'])
        && (isEvaluator() || isAdmin())) {

        if (!class_exists('\Scoring\AutoSubRankCalculator')) {
            require_once __DIR__ . '/../../services/scoring/autosubrank.php';
        }

        $criterion  = $_POST['criterion']         ?? '';   // 'doctorate' or 'award'
        $decision   = $_POST['verification']       ?? '';   // 'verified' or 'rejected'
        $notes      = trim($_POST['verify_notes'] ?? '');

        if (in_array($criterion, ['doctorate','award']) && in_array($decision, ['verified','rejected'])) {

            // Build the right column set
            if ($criterion === 'doctorate') {
                $pdo->prepare("
                    INSERT INTO auto_sub_rank
                        (application_id, doctorate_verified, doctorate_verified_by,
                         doctorate_verified_at, doctorate_verification_notes)
                    VALUES (?, ?, ?, NOW(), ?)
                    ON DUPLICATE KEY UPDATE
                        doctorate_verified       = VALUES(doctorate_verified),
                        doctorate_verified_by    = VALUES(doctorate_verified_by),
                        doctorate_verified_at    = NOW(),
                        doctorate_verification_notes = VALUES(doctorate_verification_notes)
                ")->execute([$app_id, $decision, $checker_id, $notes]);
            } else {
                $pdo->prepare("
                    INSERT INTO auto_sub_rank
                        (application_id, award_verified, award_verified_by,
                         award_verified_at, award_verification_notes)
                    VALUES (?, ?, ?, NOW(), ?)
                    ON DUPLICATE KEY UPDATE
                        award_verified       = VALUES(award_verified),
                        award_verified_by    = VALUES(award_verified_by),
                        award_verified_at    = NOW(),
                        award_verification_notes = VALUES(award_verification_notes)
                ")->execute([$app_id, $decision, $checker_id, $notes]);
            }

            // If checker rejects evidence: recalculate auto sub-rank without that criterion
            if ($decision === 'rejected') {
                // Force the mode to not_eligible for the rejected criterion
                $col = ($criterion === 'doctorate') ? 'doctorate_mode' : 'award_mode';
                $pdo->prepare("UPDATE auto_sub_rank SET {$col}='not_eligible' WHERE application_id=?")
                    ->execute([$app_id]);

                // Recalculate application score (doctorate no longer contributes)
                recalcApplicationScore($pdo, $app_id);
            }

            $crit_label = ucfirst($criterion);
            logAudit($pdo, $checker_id, "ASR Evidence {$decision}",
                "You {$decision} the {$crit_label} evidence for {$app['full_name']}'s application #{$app_id}." .
                ($notes ? " Note: {$notes}" : ''));

            // Notify faculty
            $notif_type = $decision === 'verified' ? 'asr_evidence_verified' : 'asr_evidence_rejected';
            $notif_msg  = $decision === 'verified'
                ? "Your {$crit_label} evidence has been marked acceptable by an evaluator."
                : "Your {$crit_label} evidence was marked not acceptable by an evaluator. Reason: " . ($notes ?: 'No reason given') . ". Please check your Auto Sub-Rank tab.";
            createNotif($pdo, $app['user_id'], $notif_type, $notif_msg, $app_id);

            if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && $_SERVER['HTTP_X_REQUESTED_WITH'] === 'XMLHttpRequest') {
                header('Content-Type: application/json');
                echo json_encode(['ok' => true, 'decision' => $decision, 'criterion' => $criterion]);
                exit;
            }

            $verb = $decision === 'verified' ? 'marked acceptable' : 'marked not acceptable';
            flashMessage($decision === 'verified' ? 'success' : 'warning',
                "{$crit_label} evidence <strong>{$verb}</strong>." .
                ($decision === 'rejected' ? ' Score has been recalculated.' : ''));
        }
        echo "<script>window.location.href='index.php?page={$return_page}&id={$app_id}#asr-verification';</script>"; exit;
    }
}

// -- Reload app + fetch data -----------------------------------
$app = $pdo->prepare("SELECT a.*, u.full_name, u.first_name, u.middle_name, u.last_name, u.email, u.employee_id, u.rank,
    COALESCE(camp.campus_name, 'N/A') as campus_name, c.cycle_name
    FROM applications a JOIN users u ON a.user_id=u.user_id
    LEFT JOIN campuses camp ON u.campus_id=camp.campus_id
    LEFT JOIN cycles c ON a.cycle_id=c.cycle_id
    WHERE a.application_id=?");
$app->execute([$app_id]);
$app = $app->fetch();

$subs = $pdo->prepare("SELECT * FROM kra_submissions WHERE application_id = ? ORDER BY kra_category");
$subs->execute([$app_id]);
$subs = $subs->fetchAll();

foreach ($subs as &$sub) {
    $ef = $pdo->prepare("SELECT evidence_id, file_path, original_filename FROM kra_evidence_files WHERE submission_id=? ORDER BY uploaded_at ASC");
    $ef->execute([$sub['submission_id']]);
    $sub['evidence_files'] = $ef->fetchAll(PDO::FETCH_ASSOC);
    if (empty($sub['evidence_files']) && $sub['document_path']) {
        $sub['evidence_files'] = [['evidence_id'=>0,'file_path'=>$sub['document_path'],'original_filename'=>basename($sub['document_path'])]];
    }
}
unset($sub);

$faculty_rank   = $app['rank'] ?? '';
$score_result   = getApplicationScoreSummary($pdo, (int)$app_id);
$raw_kra        = array_map(fn($d) => $d['pts'], $score_result['kra_map']);
$potential_data = [
    'potential_rank'   => $score_result['potential_rank'] ?: ($app['potential_rank'] ?? ' - '),
    'flags'            => [],
    'crossed_category' => false,
    'recomputed_score' => $score_result['weighted_score'],
];

// -- Multi-checker state ---------------------------------------
$checker_reviews   = getCheckerReviews($pdo, $app_id);
$total_checkers    = countActiveCheckers($pdo, (int)$app['cycle_id']);
$approved_count    = countApprovedCheckers($pdo, $app_id);
$rejected_count    = countRejectedCheckers($pdo, $app_id);
$my_checker_id     = $_SESSION['user_id'];
$my_review         = null;
foreach ($checker_reviews as $cr) {
    if ((int)$cr['checker_id'] === $my_checker_id) { $my_review = $cr; break; }
}
$i_have_slot       = ($my_review !== null);
$i_approved        = ($my_review['decision'] ?? '') === 'approved';
$i_rejected        = ($my_review['decision'] ?? '') === 'rejected';
$i_decided         = $i_approved || $i_rejected;
$slots_taken       = count($checker_reviews);
// No join needed - every active checker can decide directly
$can_join          = false; // removed
$can_act_checker   = !isAdmin() && !$i_decided && in_array($app['status'], ['submitted','under_review']);
// Review is "started" once app status moves to under_review or needs_revision (set by start_review action)
$i_started_review  = in_array($app['status'], ['under_review','needs_revision','talisay_review']);
// Revision/verify only allowed AFTER review has been started  -  not while status is still 'submitted'
$can_act_revision  = !isAdmin() && !$i_decided && $i_started_review;
$auto_revision_notice = 'One or more KRA entries need revision. Please check the highlighted entries and resubmit.';
$visible_checker_remarks = trim((string)($app['checker_remarks'] ?? ''));
if ($visible_checker_remarks === $auto_revision_notice) {
    $visible_checker_remarks = '';
}

