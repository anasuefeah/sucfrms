<?php
$app_id = intval($_GET['id'] ?? 0);
KraRevisionWorkflow::ensureSchema($pdo);
if (!$app_id) { echo '<div class="alert alert-danger">Invalid application.</div>'; return; }

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
$app = $pdo->prepare("SELECT a.*, u.full_name, u.first_name, u.middle_name, u.last_name, u.email, u.employee_id, u.`rank`,
    COALESCE(camp.campus_name, 'N/A') as campus_name, c.cycle_name
    FROM applications a
    JOIN users u ON a.user_id = u.user_id
    LEFT JOIN campuses camp ON u.campus_id = camp.campus_id
    LEFT JOIN cycles c ON a.cycle_id = c.cycle_id
    WHERE a.application_id = ?");
$app->execute([$app_id]);
$app = $app->fetch();
if (!$app) { echo '<div class="alert alert-danger">Application not found.</div>'; return; }
ensureKraAssignmentTable($pdo);
$my_checker_id = (int)($_SESSION['user_id'] ?? 0);
$assigned_kras = isEvaluator() ? getCheckerAssignedKras($pdo, $my_checker_id, (int)$app['cycle_id']) : array_keys(kraAssignmentCategories());

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
        if (isEvaluator() && !$assigned_kras) {
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

    // Join review action is no longer needed — no callers remain.

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
                revision_note=?, revision_by=?, revision_at=NOW(), revision_saved_at=NULL,
                verified=0, verified_by=NULL, verified_at=NULL
                WHERE submission_id=? AND application_id=?")
                ->execute([$rev_note, $checker_id, $sub_id, $app_id]);
            $pdo->prepare('DELETE FROM kra_checker_verifications WHERE submission_id=?')->execute([$sub_id]);
            $pdo->prepare("UPDATE application_checker_reviews r
                JOIN checker_kra_assignments ka ON ka.checker_id=r.checker_id
                SET r.decision='pending', r.decided_at=NULL
                WHERE r.application_id=? AND ka.cycle_id=? AND ka.kra_category=?")
                ->execute([$app_id, (int)$app['cycle_id'], $rev_cat]);
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
            KraRevisionWorkflow::clearFlag($pdo, (int)$app_id, $sub_id);
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
        if (isEvaluator() && !$assigned_kras) {
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
        if (isEvaluator() && !$assigned_kras) {
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
            require_once __DIR__ . '/../../includes/scoring/autosubrank.php';
        }

        $criterion  = $_POST['criterion']         ?? '';   // 'doctorate' or 'award'
        $decision   = $_POST['verification']       ?? '';   // 'verified' or 'rejected'
        $notes      = trim($_POST['verify_notes'] ?? '');

        if ($decision === 'rejected' && $notes === '') {
            if (($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'XMLHttpRequest') {
                if (ob_get_level()) ob_clean();
                header('Content-Type: application/json');
                http_response_code(422);
                echo json_encode(['ok' => false, 'error' => 'A reason is required when marking evidence not acceptable.']);
                exit;
            }
            flashMessage('danger', 'A reason is required when marking evidence not acceptable.');
            echo "<script>window.location.href='index.php?page={$return_page}&id={$app_id}#asr-verification';</script>";
            exit;
        }

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

            }
            // Recompute on acceptance too, including a reversal of a rejection.
            recalcApplicationScore($pdo, $app_id);

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
                if (ob_get_level()) ob_clean();
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
$app = $pdo->prepare("SELECT a.*, u.full_name, u.first_name, u.middle_name, u.last_name, u.email, u.employee_id, u.`rank`, u.profile_pic,
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

?>
<?php showFlash(); ?>

<!-- ── Page Header: back nav only, no name duplication ─────── -->
<div style="display:flex;align-items:center;justify-content:space-between;gap:1rem;flex-wrap:wrap;margin-bottom:1rem;">
    <a href="?page=<?= isAdmin() ? 'all_applications' : 'review_queue' ?>"
       style="display:inline-flex;align-items:center;gap:0.3rem;padding:0.3rem 0.7rem;
              border:1px solid #e2e8f0;background:#fff;color:#475569;
              font-size:0.75rem;font-weight:600;text-decoration:none;"
       onmouseover="this.style.borderColor='#1a3a6b';this.style.color='#1a3a6b'"
       onmouseout="this.style.borderColor='#e2e8f0';this.style.color='#475569'">
        <i class="bi bi-arrow-left"></i> <?= isAdmin() ? 'All Applications' : 'Queue' ?>
    </a>
    <span style="font-size:0.65rem;color:#94a3b8;text-transform:uppercase;letter-spacing:0.07em;">Reviewing Application</span>
</div>

<?php
$double_flags  = json_decode($app['double_counting_flags'] ?? '[]', true) ?: [];
$pending_docs  = json_decode($app['pending_documentation'] ?? '[]', true) ?: [];
$config_incomp = json_decode($app['config_incomplete'] ?? '[]', true) ?: [];
$orch_flags    = json_decode($app['orchestrator_flags'] ?? '[]', true) ?: [];
$eval_ok       = isset($app['evaluation_period_ok']) ? (bool)$app['evaluation_period_ok'] : true;
$has_flags     = !$eval_ok || !empty($double_flags) || !empty($pending_docs) || !empty($config_incomp) || !empty($orch_flags);
if ($has_flags):
?>
<div style="border:1px solid #e2e8f0;border-left:3px solid #c2410c;background:#fff;margin-bottom:1rem;">
    <div style="padding:0.6rem 1rem;border-bottom:1px solid #e2e8f0;display:flex;align-items:center;gap:0.5rem;">
        <i class="bi bi-exclamation-triangle-fill" style="color:#c2410c;font-size:0.85rem;"></i>
        <span style="font-weight:700;color:#c2410c;font-size:0.8rem;">JC01 s.2026 Scoring Flags</span>
        <span style="margin-left:auto;font-size:0.7rem;color:#94a3b8;">Human committee makes all binding decisions</span>
    </div>
    <div style="padding:0.75rem 1rem;display:flex;flex-direction:column;gap:0.3rem;">
        <?php foreach ($orch_flags as $f): ?>
        <div style="font-size:0.78rem;color:#b91c1c;padding:0.3rem 0.6rem;background:#fef2f2;border-left:2px solid #dc2626;">
            <i class="bi bi-shield-x me-1"></i><?= htmlspecialchars($f) ?>
        </div>
        <?php endforeach; ?>
        <?php foreach ($double_flags as $f): ?>
        <div style="font-size:0.78rem;color:#92400e;padding:0.3rem 0.6rem;background:#fff7ed;border-left:2px solid #d97706;">
            <i class="bi bi-copy me-1"></i><strong>Double-Counting:</strong> "<?= htmlspecialchars($f['title'] ?? '') ?>" — <?= htmlspecialchars(implode(' + ', $f['categories'] ?? [])) ?>
        </div>
        <?php endforeach; ?>
        <?php foreach (array_slice($pending_docs, 0, 5) as $pd): ?>
        <div style="font-size:0.78rem;color:#475569;padding:0.3rem 0.6rem;background:#f8fafc;border-left:2px solid #94a3b8;">
            <i class="bi bi-file-earmark-x me-1"></i><?= htmlspecialchars($pd) ?>
        </div>
        <?php endforeach; ?>
        <?php if (count($pending_docs) > 5): ?>
        <div style="font-size:0.72rem;color:#94a3b8;">+ <?= count($pending_docs) - 5 ?> more pending items</div>
        <?php endif; ?>
    </div>
</div>
<?php endif; ?>

<!-- ── Faculty Profile + Info + Scores ─────────────────────── -->
<?php
$fac_pic      = $app['profile_pic'] ?? '';
$fac_initials = strtoupper(substr($app['first_name'] ?? '', 0, 1) . substr($app['last_name'] ?? '', 0, 1)) ?: 'FA';
$inc          = $score_result['sub_rank_increment'];
$ws           = $score_result['weighted_score'];
$ws_ok        = $ws >= 41;
?>
<div style="border:1px solid #e2e8f0;background:#fff;margin-bottom:1rem;overflow:hidden;">

    <!-- ── Card header: avatar · name · status · actions ── -->
    <div style="display:flex;align-items:center;gap:1rem;padding:1rem 1.25rem;border-bottom:1px solid #e2e8f0;">

        <!-- Avatar -->
        <?php if ($fac_pic): ?>
        <img src="<?= sanitize($fac_pic) ?>" alt=""
             style="width:54px;height:54px;border-radius:50%;object-fit:cover;border:2px solid #e2e8f0;flex-shrink:0;">
        <?php else: ?>
        <div style="width:54px;height:54px;border-radius:50%;background:#1a3a6b;display:flex;align-items:center;
                    justify-content:center;font-size:1.05rem;font-weight:700;color:#fff;flex-shrink:0;">
            <?= $fac_initials ?>
        </div>
        <?php endif; ?>

        <!-- Name + sub-line -->
        <div style="flex:1;min-width:0;">
            <div style="font-size:1rem;font-weight:700;color:#0f172a;line-height:1.25;"><?= htmlspecialchars(formatDisplayName($app)) ?></div>
            <div style="font-size:0.75rem;color:#64748b;margin-top:2px;">
                <?= sanitize($app['rank'] ?? '') ?>
                <?php if (!empty($app['campus_name']) && $app['campus_name'] !== 'N/A'): ?>
                <span style="color:#cbd5e1;margin:0 3px;">·</span><?= sanitize($app['campus_name']) ?>
                <?php endif; ?>
            </div>
        </div>

        <!-- Status badge + Print ISS -->
        <div style="display:flex;align-items:center;gap:0.5rem;flex-shrink:0;">
            <?= statusBadge($app['status']) ?>
            <a href="pages/kra_pdf.php?uid=<?= $app['user_id'] ?>&cycle_id=<?= $app['cycle_id'] ?>" target="_blank"
               style="display:inline-flex;align-items:center;gap:0.35rem;padding:0.3rem 0.75rem;
                      border:1px solid #e2e8f0;background:#f8fafc;color:#475569;
                      font-size:0.74rem;font-weight:600;text-decoration:none;"
               onmouseover="this.style.background='#1a3a6b';this.style.color='#fff';this.style.borderColor='#1a3a6b'"
               onmouseout="this.style.background='#f8fafc';this.style.color='#475569';this.style.borderColor='#e2e8f0'">
                <i class="bi bi-printer"></i> Print ISS
            </a>
        </div>
    </div>

    <!-- ── Faculty info table ── -->
    <div style="display:grid;grid-template-columns:repeat(4,1fr);border-bottom:1px solid #e2e8f0;">
        <?php
        $info_fields = [
            ['Employee ID',  sanitize($app['employee_id'] ?? '—')],
            ['Campus',       sanitize($app['campus_name'] ?? '—')],
            ['Faculty Rank', sanitize($app['rank'] ?? '—')],
            ['Email',        sanitize($app['email'] ?? '—')],
        ];
        foreach ($info_fields as $fi => [$lbl, $val]):
        ?>
        <div style="padding:0.6rem 1.25rem;<?= $fi < 3 ? 'border-right:1px solid #f1f5f9;' : '' ?>">
            <div style="font-size:0.6rem;color:#94a3b8;text-transform:uppercase;letter-spacing:0.07em;margin-bottom:3px;"><?= $lbl ?></div>
            <div style="font-size:0.8rem;font-weight:600;color:#1e293b;"><?= $val ?></div>
        </div>
        <?php endforeach; ?>
    </div>
    <div style="display:grid;grid-template-columns:repeat(4,1fr);border-bottom:1px solid #e2e8f0;">
        <?php
        $info_fields2 = [
            ['Cycle',        sanitize($app['cycle_name'] ?? '—')],
            ['Submitted',    $app['submitted_at'] ? date('M d, Y H:i', strtotime($app['submitted_at'])) : '—'],
            ['Weighted Score', '<span style="font-size:1rem;font-weight:800;color:'.($ws_ok?'#1a3a6b':'#dc2626').'">'.number_format($ws,2).'</span>'.(!$ws_ok?' <span style="font-size:0.65rem;color:#dc2626;">Below min</span>':'')],
            ['Recommended Rank', '<span style="font-weight:700;color:#1a3a6b;">'.sanitize($potential_data['potential_rank'] ?: '—').'</span>'.($inc>0?' <span style="font-size:0.7rem;color:#16a34a;font-weight:600;">+'.sanitize((string)$inc).' sub-rank'.($inc>1?'s':'').'</span>':'')],
        ];
        foreach ($info_fields2 as $fi => [$lbl, $val]):
        ?>
        <div style="padding:0.6rem 1.25rem;<?= $fi < 3 ? 'border-right:1px solid #f1f5f9;' : '' ?>">
            <div style="font-size:0.6rem;color:#94a3b8;text-transform:uppercase;letter-spacing:0.07em;margin-bottom:3px;"><?= $lbl ?></div>
            <div style="font-size:0.8rem;color:#1e293b;"><?= $val ?></div>
        </div>
        <?php endforeach; ?>
    </div>

    <?php if ($visible_checker_remarks !== ''): ?>
    <div style="padding:0.5rem 1.25rem;background:#f8fafc;border-bottom:1px solid #f1f5f9;font-size:0.78rem;color:#475569;display:flex;align-items:center;gap:0.5rem;">
        <i class="bi bi-chat-left-text" style="color:#1a3a6b;flex-shrink:0;"></i>
        <span><strong>Remarks:</strong> <?= sanitize($visible_checker_remarks) ?></span>
    </div>
    <?php endif; ?>

    <!-- ── KRA score breakdown ── -->
    <div class="table-responsive">
    <table style="width:100%;border-collapse:collapse;font-size:0.82rem;">
        <thead>
        <tr style="background:#f8fafc;border-bottom:1px solid #e2e8f0;">
            <th style="padding:0.5rem 1.25rem;text-align:left;font-size:0.62rem;font-weight:700;text-transform:uppercase;letter-spacing:0.06em;color:#94a3b8;">KRA</th>
            <th style="padding:0.5rem 0.75rem;text-align:right;font-size:0.62rem;font-weight:700;text-transform:uppercase;letter-spacing:0.06em;color:#94a3b8;white-space:nowrap;">Raw</th>
            <th style="padding:0.5rem 0.75rem;text-align:right;font-size:0.62rem;font-weight:700;text-transform:uppercase;letter-spacing:0.06em;color:#94a3b8;white-space:nowrap;">Capped</th>
            <th style="padding:0.5rem 0.75rem;text-align:center;font-size:0.62rem;font-weight:700;text-transform:uppercase;letter-spacing:0.06em;color:#94a3b8;white-space:nowrap;">Weight</th>
            <th style="padding:0.5rem 1.25rem;text-align:right;font-size:0.62rem;font-weight:700;text-transform:uppercase;letter-spacing:0.06em;color:#94a3b8;white-space:nowrap;">Weighted</th>
        </tr>
        </thead>
        <tbody>
        <?php
        $ck_labels  = ['Instruction'=>'KRA I · Instruction','Research'=>'KRA II · Research','Extension'=>'KRA III · Extension','Professional Development'=>'KRA IV · Prof. Development'];
        $ck_capped  = ['Instruction'=>$score_result['kra1'],'Research'=>$score_result['kra2'],'Extension'=>$score_result['kra3'],'Professional Development'=>$score_result['kra4']];
        $ck_weights = $score_result['weights'];
        foreach ($ck_labels as $key => $label):
            $raw  = $raw_kra[$key] ?? 0;
            $cap  = $ck_capped[$key];
            $w    = $ck_weights[$key];
            $wval = round($cap * $w, 2);
        ?>
        <tr style="border-bottom:1px solid #f8fafc;" onmouseover="this.style.background='#fafafa'" onmouseout="this.style.background=''">
            <td style="padding:0.55rem 1.25rem;color:#334155;font-weight:500;"><?= $label ?></td>
            <td style="padding:0.55rem 0.75rem;text-align:right;color:#64748b;"><?= number_format($raw, 2) ?></td>
            <td style="padding:0.55rem 0.75rem;text-align:right;color:#64748b;"><?= number_format($cap, 2) ?></td>
            <td style="padding:0.55rem 0.75rem;text-align:center;color:#94a3b8;"><?= ($w * 100) ?>%</td>
            <td style="padding:0.55rem 1.25rem;text-align:right;font-weight:700;color:#1a3a6b;"><?= number_format($wval, 2) ?></td>
        </tr>
        <?php endforeach; ?>
        </tbody>
        <tfoot>
        <tr style="background:#f0f4fb;border-top:2px solid #1a3a6b;">
            <td colspan="4" style="padding:0.6rem 1.25rem;font-weight:700;color:#1a3a6b;font-size:0.82rem;">Final Weighted Score</td>
            <td style="padding:0.6rem 1.25rem;text-align:right;font-weight:800;color:#1a3a6b;font-size:1rem;"><?= number_format($ws, 2) ?></td>
        </tr>
        </tfoot>
    </table>
    </div>

</div><!-- /faculty profile card -->

<!-- ── Evaluator Progress ──────────────────────────────────── -->
<?php if (!isAdmin()): ?>
<?php
$all_checkers_stmt = $pdo->prepare("
    SELECT DISTINCT u.user_id, u.checker_label, r.decision, r.decided_at
    FROM checker_kra_assignments ka
    JOIN users u ON u.user_id = ka.checker_id
    LEFT JOIN application_checker_reviews r ON r.application_id = ? AND r.checker_id = u.user_id
    WHERE ka.cycle_id = ? AND u.role = 'evaluator' AND u.status = 'active'
    ORDER BY u.user_id ASC
");
$all_checkers_stmt->execute([$app_id, (int)$app['cycle_id']]);
$all_checkers_list = $all_checkers_stmt->fetchAll();
?>
<div style="border:1px solid #e2e8f0;background:#fff;margin-bottom:1rem;">
    <div style="padding:0.6rem 1.25rem;border-bottom:1px solid #e2e8f0;display:flex;align-items:center;justify-content:space-between;">
        <span style="font-size:0.65rem;font-weight:700;color:#94a3b8;text-transform:uppercase;letter-spacing:0.07em;">
            Evaluator Progress
        </span>
        <span style="font-size:0.72rem;color:#94a3b8;"><?= $approved_count + $rejected_count ?>/<?= $total_checkers ?> decided</span>
    </div>
    <div style="padding:0.75rem 1.25rem;display:flex;gap:0.5rem;flex-wrap:wrap;">
        <?php foreach ($all_checkers_list as $chk):
            $is_me  = (int)$chk['user_id'] === $my_checker_id;
            $dec    = $chk['decision'] ?? null;
            $bg     = $dec === 'approved' ? '#f0fdf4' : '#f8fafc';
            $border = $dec === 'approved' ? '#16a34a' : ($dec === 'rejected' ? '#475569' : '#e2e8f0');
            $color  = $dec === 'approved' ? '#16a34a' : ($dec === 'rejected' ? '#475569' : '#94a3b8');
            $icon   = $dec === 'approved' ? 'bi-check-circle-fill' : ($dec === 'rejected' ? 'bi-x-circle' : 'bi-hourglass');
            $dlabel = $dec === 'approved' ? 'Complete' : ($dec === 'rejected' ? 'Returned' : 'Pending');
        ?>
        <div style="display:flex;align-items:center;gap:0.5rem;padding:0.4rem 0.75rem;border:1px solid <?= $border ?>;background:<?= $bg ?>;">
            <i class="bi <?= $icon ?>" style="color:<?= $color ?>;font-size:0.78rem;"></i>
            <div>
                <div style="font-size:0.73rem;font-weight:600;color:#1e293b;line-height:1.2;">
                    <?= htmlspecialchars(checkerDisplayLabel($chk)) ?>
                    <?php if ($is_me): ?><span style="color:#94a3b8;font-weight:400;font-size:0.68rem;"> (you)</span><?php endif; ?>
                </div>
                <div style="font-size:0.65rem;color:<?= $color ?>;">
                    <?= $dlabel ?>
                    <?php if ($chk['decided_at']): ?> · <?= date('M d', strtotime($chk['decided_at'])) ?><?php endif; ?>
                </div>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
</div>
<?php endif; ?>

<!-- -- Auto Sub-Rank Verification -------------------------- -->
<div id="asr-verification" style="scroll-margin-top:80px;"></div>
<?php
// -- Load AutoSubRankCalculator -------------------------------------------
if (!class_exists('\Scoring\AutoSubRankCalculator')) {
    require_once __DIR__ . '/../../includes/scoring/autosubrank.php';
}
// Recalculate (in case scores changed during this review session)
$asr_checker_calc = new \Scoring\AutoSubRankCalculator($pdo, $app_id);
$asr_checker_res  = $asr_checker_calc->calculate();
$asr_checker_calc->persist($asr_checker_res);
$asr_row_ck = \Scoring\AutoSubRankCalculator::loadForApplication($pdo, $app_id) ?: [];

$ck_d_mode   = $asr_checker_res['doctorate_mode'];
$ck_a_mode   = $asr_checker_res['award_mode'];
$ck_d_color  = \Scoring\AutoSubRankCalculator::modeColor($ck_d_mode);
$ck_a_color  = \Scoring\AutoSubRankCalculator::modeColor($ck_a_mode);
$ck_d_label  = \Scoring\AutoSubRankCalculator::modeLabel($ck_d_mode);
$ck_a_label  = \Scoring\AutoSubRankCalculator::modeLabel($ck_a_mode);
$ck_d_verified = $asr_row_ck['doctorate_verified'] ?? 'pending';
$ck_a_verified = $asr_row_ck['award_verified']     ?? 'pending';
$ck_dv_color   = \Scoring\AutoSubRankCalculator::verifiedColor($ck_d_verified);
$ck_av_color   = \Scoring\AutoSubRankCalculator::verifiedColor($ck_a_verified);
$asr_status_label = static function ($status) {
    return match ($status) {
        'verified' => 'Acceptable',
        'rejected' => 'Not Acceptable',
        default => 'Pending Review',
    };
};
$ck_ri         = $asr_checker_res['total_rank_increase'];

// Can checker act? Not if app is already approved/rejected/archived
$ck_can_verify = !isAdmin()
    && in_array($app['status'], ['submitted','under_review','talisay_review','needs_revision']);
?>

<div class="neon-card mb-3" style="padding:0;overflow:hidden;">
    <!-- Header -->
    <div style="background:linear-gradient(90deg,#1e3a6b,#1e4d8c);padding:0.75rem 1.25rem;display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:0.5rem;">
        <div style="display:flex;align-items:center;gap:0.6rem;">
            <i class="bi bi-arrow-up-circle-fill" style="color:#fff;font-size:1rem;"></i>
            <span style="color:#fff;font-weight:700;font-size:0.88rem;">Auto Sub-Rank Assessment</span>
            <span style="background:rgba(255,255,255,0.15);color:#fff;font-size:0.65rem;padding:2px 8px;border-radius:20px;font-weight:600;">System Calculated</span>
        </div>
        <?php if ($ck_ri > 0): ?>
        <span style="background:#f0fdf4;color:#16a34a;border:1px solid #bbf7d0;padding:3px 12px;border-radius:20px;font-size:0.72rem;font-weight:700;">
            <i class="bi bi-arrow-up me-1"></i>+<?= $ck_ri ?> sub-rank<?= $ck_ri > 1 ? 's' : '' ?>
        </span>
        <?php else: ?>
        <span style="background:rgba(255,255,255,0.1);color:rgba(255,255,255,0.6);border:1px solid rgba(255,255,255,0.2);padding:3px 12px;border-radius:20px;font-size:0.72rem;font-weight:600;">
            No sub-rank increase
        </span>
        <?php endif; ?>
    </div>

    <div style="padding:1rem 1.25rem;">

        <!-- Explanation callout -->
        <div style="background:#eff6ff;border:1px solid #bfdbfe;border-radius:7px;padding:0.6rem 0.9rem;margin-bottom:1rem;font-size:0.78rem;color:#1e4d8c;">
            <i class="bi bi-info-circle me-1"></i>
            <strong>Your role:</strong> Review whether each piece of evidence is <em>authentic</em>.
            The system has already determined the optimal strategy.
            Marking evidence not acceptable will remove that criterion from the score entirely.
        </div>

        <!-- -- Criterion 1: Doctorate --------------------------------- -->
        <div style="border:1px solid #e2e8f0;border-radius:8px;overflow:hidden;margin-bottom:0.85rem;" id="asr-doc-panel">
            <!-- Sub-header -->
            <div style="background:#f8fafc;border-bottom:1px solid #e2e8f0;padding:0.5rem 0.9rem;display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:0.4rem;">
                <span style="font-size:0.72rem;font-weight:700;color:#475569;text-transform:uppercase;letter-spacing:0.05em;">
                    <i class="bi bi-mortarboard me-1"></i>Criterion 1  -  Doctorate Degree
                </span>
                <div style="display:flex;align-items:center;gap:0.4rem;flex-wrap:wrap;">
                    <!-- System decision (read-only) -->
                    <span style="background:<?= $ck_d_color ?>15;color:<?= $ck_d_color ?>;border:1px solid <?= $ck_d_color ?>40;padding:2px 8px;border-radius:20px;font-size:0.67rem;font-weight:700;">
                        <?= htmlspecialchars($ck_d_label) ?>
                    </span>
                    <!-- Current review status -->
                    <span id="asr-doc-status-badge"
                          style="background:<?= $ck_dv_color ?>12;color:<?= $ck_dv_color ?>;border:1px solid <?= $ck_dv_color ?>40;padding:2px 8px;border-radius:20px;font-size:0.67rem;font-weight:700;">
                        <i class="bi bi-shield me-1"></i><?= $asr_status_label($ck_d_verified) ?>
                    </span>
                </div>
            </div>

            <div style="padding:0.8rem 0.9rem;">
                <?php if ($asr_checker_res['has_doctorate']): ?>

                <!-- Evidence info -->
                <div style="display:flex;gap:0.85rem;flex-wrap:wrap;margin-bottom:0.75rem;">
                    <div style="flex:1;min-width:160px;">
                        <div style="font-size:0.68rem;color:#94a3b8;font-weight:600;text-transform:uppercase;margin-bottom:0.2rem;">Doctorate</div>
                        <div style="font-size:0.82rem;color:#1e293b;"><?= htmlspecialchars($asr_checker_res['doctorate_details'] ?: 'Doctorate (Professional Development)') ?></div>
                    </div>
                    <div style="flex:1;min-width:200px;">
                        <div style="font-size:0.68rem;color:#94a3b8;font-weight:600;text-transform:uppercase;margin-bottom:0.2rem;">System Reasoning</div>
                        <div style="font-size:0.78rem;color:#475569;line-height:1.5;"><?= htmlspecialchars($asr_checker_res['doctorate_reason']) ?></div>
                    </div>
                </div>

                <?php if (!empty($asr_row_ck['doctorate_verification_notes']) && $ck_d_verified !== 'pending'): ?>
                <div style="background:<?= $ck_dv_color ?>0d;border:1px solid <?= $ck_dv_color ?>30;border-radius:6px;padding:0.45rem 0.7rem;font-size:0.75rem;color:<?= $ck_dv_color ?>;margin-bottom:0.7rem;">
                    <i class="bi bi-chat-left-text me-1"></i><strong>Review note:</strong> <?= htmlspecialchars($asr_row_ck['doctorate_verification_notes']) ?>
                </div>
                <?php endif; ?>

                <?php if ($ck_can_verify): ?>
                <!-- Accept / Reject form -->
                <form id="asr-doc-form" method="POST"
                      style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:7px;padding:0.7rem 0.85rem;">
                    <input type="hidden" name="action"     value="verify_asr_evidence">
                    <input type="hidden" name="criterion"  value="doctorate">
                    <div style="font-size:0.72rem;font-weight:700;color:#475569;text-transform:uppercase;letter-spacing:0.05em;margin-bottom:0.5rem;">
                        <i class="bi bi-shield-check me-1"></i>Your Review
                    </div>
                    <div style="display:flex;gap:0.5rem;flex-wrap:wrap;margin-bottom:0.5rem;">
                        <button type="button"
                                onclick="submitAsrVerify('asr-doc-form','verified','asr-doc-status-badge','#16a34a','Acceptable')"
                                style="flex:1;min-width:100px;padding:0.45rem 0.7rem;border-radius:6px;border:1.5px solid #16a34a;background:#f0fdf4;color:#16a34a;font-size:0.78rem;font-weight:700;cursor:pointer;display:flex;align-items:center;justify-content:center;gap:0.3rem;"
                                >
                            <i class="bi bi-check-circle-fill"></i>Acceptable - Evidence is Authentic
                        </button>
                        <button type="button"
                                onclick="submitAsrVerify('asr-doc-form','rejected','asr-doc-status-badge','#dc2626','Not Acceptable')"
                                style="flex:1;min-width:100px;padding:0.45rem 0.7rem;border-radius:6px;border:1.5px solid #dc2626;background:#fef2f2;color:#dc2626;font-size:0.78rem;font-weight:700;cursor:pointer;display:flex;align-items:center;justify-content:center;gap:0.3rem;"
                                >
                            <i class="bi bi-x-circle-fill"></i>Not Acceptable - Evidence Invalid
                        </button>
                    </div>
                    <input type="hidden" name="verification" id="asr-doc-decision" value="">
                    <textarea name="verify_notes" id="asr-doc-notes"
                              placeholder="Optional: note your reason (required when marking not acceptable)"
                              style="width:100%;border:1px solid #e2e8f0;border-radius:5px;padding:0.4rem 0.6rem;font-size:0.78rem;resize:vertical;min-height:52px;font-family:inherit;"
                              ><?= htmlspecialchars($asr_row_ck['doctorate_verification_notes'] ?? '') ?></textarea>
                </form>
                <?php else: ?>
                <div style="font-size:0.75rem;color:#94a3b8;font-style:italic;">
                    <i class="bi bi-lock me-1"></i>Review locked - application is not under active review.
                </div>
                <?php endif; ?>

                <?php else: ?>
                <div style="font-size:0.82rem;color:#64748b;display:flex;align-items:center;gap:0.5rem;">
                    <i class="bi bi-dash-circle" style="color:#94a3b8;"></i>
                    No doctorate found in this application's Professional Development entries. Nothing to review.
                </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- -- Criterion 2: Award ------------------------------------- -->
        <div style="border:1px solid #e2e8f0;border-radius:8px;overflow:hidden;" id="asr-awd-panel">
            <div style="background:#f8fafc;border-bottom:1px solid #e2e8f0;padding:0.5rem 0.9rem;display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:0.4rem;">
                <span style="font-size:0.72rem;font-weight:700;color:#475569;text-transform:uppercase;letter-spacing:0.05em;">
                    <i class="bi bi-trophy me-1"></i>Criterion 2  -  National / International Award
                </span>
                <div style="display:flex;align-items:center;gap:0.4rem;flex-wrap:wrap;">
                    <span style="background:<?= $ck_a_color ?>15;color:<?= $ck_a_color ?>;border:1px solid <?= $ck_a_color ?>40;padding:2px 8px;border-radius:20px;font-size:0.67rem;font-weight:700;">
                        <?= htmlspecialchars($ck_a_label) ?>
                    </span>
                    <?php if ($asr_checker_res['has_award']): ?>
                    <span id="asr-awd-status-badge"
                          style="background:<?= $ck_av_color ?>12;color:<?= $ck_av_color ?>;border:1px solid <?= $ck_av_color ?>40;padding:2px 8px;border-radius:20px;font-size:0.67rem;font-weight:700;">
                        <i class="bi bi-shield me-1"></i><?= $asr_status_label($ck_a_verified) ?>
                    </span>
                    <?php endif; ?>
                </div>
            </div>

            <div style="padding:0.8rem 0.9rem;">
                <?php if ($asr_checker_res['has_award']): ?>

                <div style="display:flex;gap:0.85rem;flex-wrap:wrap;margin-bottom:0.75rem;">
                    <div style="flex:1;min-width:160px;">
                        <div style="font-size:0.68rem;color:#94a3b8;font-weight:600;text-transform:uppercase;margin-bottom:0.2rem;">Award</div>
                        <div style="font-size:0.82rem;color:#1e293b;"><?= htmlspecialchars($asr_checker_res['award_details'] ?: 'National/International Award') ?></div>
                    </div>
                    <div style="flex:1;min-width:200px;">
                        <div style="font-size:0.68rem;color:#94a3b8;font-weight:600;text-transform:uppercase;margin-bottom:0.2rem;">System Reasoning</div>
                        <div style="font-size:0.78rem;color:#475569;line-height:1.5;"><?= htmlspecialchars($asr_checker_res['award_reason']) ?></div>
                    </div>
                    <?php if (!empty($asr_row_ck['award_evidence'])): ?>
                    <div style="width:100%;">
                        <a href="../<?= htmlspecialchars($asr_row_ck['award_evidence']) ?>" target="_blank"
                           style="font-size:0.78rem;color:#1e4d8c;text-decoration:none;display:inline-flex;align-items:center;gap:0.3rem;">
                           <i class="bi bi-file-earmark-pdf"></i>View submitted evidence
                        </a>
                    </div>
                    <?php endif; ?>
                </div>

                <?php if (!empty($asr_row_ck['award_verification_notes']) && $ck_a_verified !== 'pending'): ?>
                <div style="background:<?= $ck_av_color ?>0d;border:1px solid <?= $ck_av_color ?>30;border-radius:6px;padding:0.45rem 0.7rem;font-size:0.75rem;color:<?= $ck_av_color ?>;margin-bottom:0.7rem;">
                    <i class="bi bi-chat-left-text me-1"></i><strong>Review note:</strong> <?= htmlspecialchars($asr_row_ck['award_verification_notes']) ?>
                </div>
                <?php endif; ?>

                <?php if ($ck_can_verify): ?>
                <form id="asr-awd-form" method="POST"
                      style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:7px;padding:0.7rem 0.85rem;">
                    <input type="hidden" name="action"     value="verify_asr_evidence">
                    <input type="hidden" name="criterion"  value="award">
                    <div style="font-size:0.72rem;font-weight:700;color:#475569;text-transform:uppercase;letter-spacing:0.05em;margin-bottom:0.5rem;">
                        <i class="bi bi-shield-check me-1"></i>Your Review
                    </div>
                    <div style="display:flex;gap:0.5rem;flex-wrap:wrap;margin-bottom:0.5rem;">
                        <button type="button"
                                onclick="submitAsrVerify('asr-awd-form','verified','asr-awd-status-badge','#16a34a','Acceptable')"
                                style="flex:1;min-width:100px;padding:0.45rem 0.7rem;border-radius:6px;border:1.5px solid #16a34a;background:#f0fdf4;color:#16a34a;font-size:0.78rem;font-weight:700;cursor:pointer;display:flex;align-items:center;justify-content:center;gap:0.3rem;"
                                >
                            <i class="bi bi-check-circle-fill"></i>Acceptable - Evidence is Authentic
                        </button>
                        <button type="button"
                                onclick="submitAsrVerify('asr-awd-form','rejected','asr-awd-status-badge','#dc2626','Not Acceptable')"
                                style="flex:1;min-width:100px;padding:0.45rem 0.7rem;border-radius:6px;border:1.5px solid #dc2626;background:#fef2f2;color:#dc2626;font-size:0.78rem;font-weight:700;cursor:pointer;display:flex;align-items:center;justify-content:center;gap:0.3rem;"
                                >
                            <i class="bi bi-x-circle-fill"></i>Not Acceptable - Evidence Invalid
                        </button>
                    </div>
                    <input type="hidden" name="verification" id="asr-awd-decision" value="">
                    <textarea name="verify_notes" id="asr-awd-notes"
                              placeholder="Optional: note your reason (required when marking not acceptable)"
                              style="width:100%;border:1px solid #e2e8f0;border-radius:5px;padding:0.4rem 0.6rem;font-size:0.78rem;resize:vertical;min-height:52px;font-family:inherit;"
                              ><?= htmlspecialchars($asr_row_ck['award_verification_notes'] ?? '') ?></textarea>
                </form>
                <?php else: ?>
                <div style="font-size:0.75rem;color:#94a3b8;font-style:italic;">
                    <i class="bi bi-lock me-1"></i>Review locked - application is not under active review.
                </div>
                <?php endif; ?>

                <?php else: ?>
                <div style="font-size:0.82rem;color:#64748b;display:flex;align-items:center;gap:0.5rem;">
                    <i class="bi bi-dash-circle" style="color:#94a3b8;"></i>
                    No national/international award found in this application. Nothing to review.
                </div>
                <?php endif; ?>
            </div>
        </div>

    </div><!-- /card body -->
</div><!-- /asr neon-card -->

<script>
/**
 * submitAsrVerify  -  submit an accept/reject form via AJAX,
 * then update the status badge inline without full page reload.
 */
async function submitAsrVerify(formId, decision, badgeId, color, label) {
    const form  = document.getElementById(formId);
    const badge = document.getElementById(badgeId);
    if (!form || form.dataset.saving === '1') return;

    // Inject the decision into the hidden input
    const decInput = form.querySelector('input[name="verification"]');
    if (decInput) decInput.value = decision;

    // Require a note when rejecting
    const notesEl = form.querySelector('textarea[name="verify_notes"]');
    if (decision === 'rejected' && notesEl && notesEl.value.trim() === '') {
        notesEl.style.borderColor = '#dc2626';
        notesEl.focus();
        notesEl.placeholder = 'Reason is required.';
        return;
    }
    if (notesEl) {
        notesEl.style.borderColor = '#e2e8f0';
        notesEl.placeholder = 'Optional: note your reason (required when marking not acceptable)';
    }

    const fd = new FormData(form);
    const buttons = Array.from(form.querySelectorAll('button[type="button"]'));
    form.dataset.saving = '1';
    buttons.forEach(b => { b.disabled = true; b.style.opacity = '0.55'; });
    let errorEl = form.querySelector('.asr-save-error');
    if (errorEl) errorEl.remove();
    try {
        const response = await fetch(window.location.href, { method: 'POST', body: fd,
            headers: { 'X-Requested-With': 'XMLHttpRequest' } });
        const data = await response.json();
        if (!response.ok || !data.ok) throw new Error(data.error || 'Could not save your review. Please try again.');
        if (badge) {
            badge.style.background  = color + '12';
            badge.style.color       = color;
            badge.style.borderColor = color + '40';
            badge.innerHTML = '<i class="bi bi-shield me-1"></i>' + label;
        }
        buttons.forEach(b => b.setAttribute('aria-pressed', String(b.getAttribute('onclick')?.includes("'" + decision + "'"))));
    } catch (error) {
        errorEl = document.createElement('div');
        errorEl.className = 'asr-save-error';
        errorEl.setAttribute('role', 'alert');
        errorEl.style.cssText = 'color:#dc2626;font-size:0.78rem;margin-top:0.5rem;';
        errorEl.textContent = error.message || 'Could not save your review. Please try again.';
        form.appendChild(errorEl);
    } finally {
        delete form.dataset.saving;
        buttons.forEach(b => { b.disabled = false; b.style.opacity = ''; });
    }
}
</script>

<!-- -- KRA Submissions --------------------------------------- -->
<div id="kra-verification" style="scroll-margin-top:80px;"></div>
<?php if (!empty($_GET['score_saved'])): ?>
<?php showFlash(); ?>
<?php endif; ?>
<div class="neon-card mb-3" style="padding:0;overflow:hidden;">

    <?php if ($subs): ?>
    <?php
    // Per-checker verification state
    $my_verified_sids = [];
    if ($my_checker_id) {
        $my_v = $pdo->prepare("SELECT submission_id FROM kra_checker_verifications WHERE checker_id=?");
        $my_v->execute([$my_checker_id]);
        $my_verified_sids = array_column($my_v->fetchAll(PDO::FETCH_ASSOC), 'submission_id');
    }
    $total_active_checkers = $total_checkers;
    $verify_counts = [];
    if ($subs) {
        $sub_ids_v = array_column($subs, 'submission_id');
        $ph_v = implode(',', array_fill(0, count($sub_ids_v), '?'));
        $vc = $pdo->prepare("SELECT submission_id, COUNT(*) as cnt FROM kra_checker_verifications WHERE submission_id IN ($ph_v) GROUP BY submission_id");
        $vc->execute($sub_ids_v);
        foreach ($vc->fetchAll() as $vrow) $verify_counts[$vrow['submission_id']] = (int)$vrow['cnt'];
    }
    $kra_caps_rev    = ['Instruction'=>100,'Research'=>100,'Extension'=>100,'Professional Development'=>100];
    $kra_weights_rev = $score_result['weights'];
    $kra_raw_totals  = [];
    foreach ($subs as $s) {
        $kra_raw_totals[$s['kra_category']] = ($kra_raw_totals[$s['kra_category']] ?? 0) + (float)$s['computed_points'];
    }

    // Group subs by category and collect per-tab stats
    $kra_order  = ['Instruction', 'Research', 'Extension', 'Professional Development'];
    $kra_short  = ['Instruction'=>'Instruction','Research'=>'Research','Extension'=>'Extension','Professional Development'=>'Prof. Development'];
    $subs_by_kra = [];
    foreach ($subs as $s) $subs_by_kra[$s['kra_category']][] = $s;

    // Per-tab badge counts (flagged entries highlight the tab)
    $kra_tab_stats = [];
    foreach ($kra_order as $kra) {
        $entries  = $subs_by_kra[$kra] ?? [];
        $flagged  = 0; $accepted = 0;
        foreach ($entries as $e) {
            if (($e['revision_status'] ?? 'ok') === 'needs_revision') $flagged++;
            $sid = (int)$e['submission_id'];
            $assigned = max(1, countAssignedCheckersForKra($pdo, (int)$app['cycle_id'], $kra));
            if (($verify_counts[$sid] ?? 0) >= $assigned) $accepted++;
        }
        $kra_tab_stats[$kra] = [
            'count'    => count($entries),
            'flagged'  => $flagged,
            'accepted' => $accepted,
        ];
    }
    // Default active tab = first KRA that has entries
    $default_kra = 'Instruction';
    foreach ($kra_order as $k) { if (!empty($subs_by_kra[$k])) { $default_kra = $k; break; } }
    $kra_tab_ids = ['Instruction'=>'kra-tab-inst','Research'=>'kra-tab-res','Extension'=>'kra-tab-ext','Professional Development'=>'kra-tab-pd'];
    ?>

    <!-- KRA Tab bar -->
    <div style="display:flex;align-items:stretch;border-bottom:2px solid #e2e8f0;background:#f8fafc;overflow-x:auto;scrollbar-width:none;-ms-overflow-style:none;">
    <style>#kra-verification .neon-card > div:first-child::-webkit-scrollbar{display:none}</style>
        <div style="padding:0.55rem 1rem;display:flex;align-items:center;gap:0.4rem;border-right:1px solid #e2e8f0;flex-shrink:0;">
            <span style="font-size:0.65rem;font-weight:700;color:#94a3b8;text-transform:uppercase;letter-spacing:0.07em;white-space:nowrap;">KRA Score Review</span>
        </div>
        <?php foreach ($kra_order as $kra):
            $stats   = $kra_tab_stats[$kra];
            $tab_id  = $kra_tab_ids[$kra];
            $short   = $kra_short[$kra];
            $is_def  = ($kra === $default_kra);
            $has_flag = $stats['flagged'] > 0;
            $all_done = $stats['count'] > 0 && $stats['accepted'] === $stats['count'];
            $is_empty = $stats['count'] === 0;
            $badge_bg = $has_flag ? '#fef3c7' : ($all_done ? '#f0fdf4' : ($is_empty ? '#f8fafc' : '#f1f5f9'));
            $badge_col= $has_flag ? '#92400e'  : ($all_done ? '#16a34a'  : ($is_empty ? '#cbd5e1' : '#64748b'));
        ?>
        <button type="button"
                id="btn-<?= $tab_id ?>"
                onclick="switchKraTab('<?= $tab_id ?>','<?= addslashes($kra) ?>')"
                style="display:inline-flex;align-items:center;gap:0.45rem;padding:0.65rem 1rem;
                       border:none;background:none;cursor:pointer;white-space:nowrap;
                       font-size:0.78rem;font-weight:600;
                       color:<?= $is_def ? '#1a3a6b' : ($is_empty ? '#94a3b8' : '#64748b') ?>;
                       border-bottom:2px solid <?= $is_def ? '#1a3a6b' : 'transparent' ?>;
                       margin-bottom:-2px;transition:color 0.12s;">
            <?= htmlspecialchars($short) ?>
            <span style="background:<?= $badge_bg ?>;color:<?= $badge_col ?>;font-size:0.6rem;font-weight:800;
                         padding:1px 6px;min-width:18px;text-align:center;
                         <?= $has_flag ? 'animation:badgePulse 1.8s ease-in-out infinite;' : '' ?>">
                <?php if ($is_empty): ?>
                —
                <?php elseif ($has_flag): ?>
                <i class="bi bi-exclamation-triangle-fill" style="font-size:0.55rem;margin-right:2px;"></i><?= $stats['flagged'] ?> flagged
                <?php elseif ($all_done): ?>
                <i class="bi bi-check-lg" style="font-size:0.6rem;margin-right:1px;"></i><?= $stats['count'] ?>
                <?php else: ?>
                <?= $stats['count'] ?>
                <?php endif; ?>
            </span>
        </button>
        <?php endforeach; ?>
    </div>
    <style>
    @keyframes badgePulse {
        0%,100%{opacity:1} 50%{opacity:0.5}
    }
    </style>

    <!-- KRA Tab panels -->
    <?php foreach ($kra_order as $kra):
        $tab_id = $kra_tab_ids[$kra];
        $entries = $subs_by_kra[$kra] ?? [];
        $is_def  = ($kra === $default_kra);
        $can_review_this_kra   = checkerCanReviewKra($pdo, (int)$my_checker_id, (int)$app['cycle_id'], $kra);
        $assigned_for_this_kra = max(1, countAssignedCheckersForKra($pdo, (int)$app['cycle_id'], $kra));
        $kra_weight = $kra_weights_rev[$kra] ?? 0;
        $cat_raw    = $kra_raw_totals[$kra] ?? 0;
        $cat_cap    = $kra_caps_rev[$kra] ?? 100;
        $cat_capped = min($cat_cap, $cat_raw);
    ?>
    <div id="<?= $tab_id ?>" style="display:<?= $is_def ? 'block' : 'none' ?>;">

        <?php if (!$entries): ?>
        <!-- Empty state -->
        <div style="padding:2.5rem 1rem;text-align:center;color:#94a3b8;border-top:1px solid #f1f5f9;">
            <i class="bi bi-folder2-open" style="font-size:1.75rem;display:block;margin-bottom:0.5rem;color:#e2e8f0;"></i>
            <div style="font-size:0.82rem;font-weight:600;color:#64748b;margin-bottom:0.25rem;">No submissions for <?= htmlspecialchars($kra) ?></div>
            <div style="font-size:0.72rem;color:#94a3b8;">The faculty has not submitted any entries under this KRA.</div>
        </div>

        <?php else: ?>
        <div class="table-responsive">
        <table style="width:100%;border-collapse:collapse;font-size:0.82rem;table-layout:fixed;">
            <colgroup>
                <col style="width:auto;">
                <col style="width:90px;">
                <col style="width:90px;">
                <col style="width:130px;">
            </colgroup>
            <thead>
            <tr style="background:#f8fafc;border-bottom:1px solid #e2e8f0;">
                <th style="padding:0.55rem 1rem;text-align:left;font-size:0.63rem;font-weight:700;text-transform:uppercase;letter-spacing:0.06em;color:#94a3b8;">
                    Entry
                </th>
                <th style="padding:0.55rem 0.75rem;text-align:right;font-size:0.63rem;font-weight:700;text-transform:uppercase;letter-spacing:0.06em;color:#94a3b8;">Raw</th>
                <th style="padding:0.55rem 0.75rem;text-align:right;font-size:0.63rem;font-weight:700;text-transform:uppercase;letter-spacing:0.06em;color:#94a3b8;">
                    Weighted
                    <span style="font-weight:400;color:#cbd5e1;">(<?= ($kra_weight*100) ?>%)</span>
                </th>
                <th style="padding:0.55rem 0.75rem;text-align:center;font-size:0.63rem;font-weight:700;text-transform:uppercase;letter-spacing:0.06em;color:#94a3b8;">Action</th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($entries as $idx => $s):
                $raw_pts      = (float)$s['computed_points'];
                $entry_share  = $cat_raw > 0 ? ($raw_pts / $cat_raw) : 0;
                $weighted_val = round($entry_share * $cat_capped * $kra_weight, 2);
                $needs_rev    = ($s['revision_status'] ?? 'ok') === 'needs_revision';
                $was_flagged  = KraRevisionWorkflow::wasFlagged($s);
                $i_flagged_this = $was_flagged && (int)($s['revision_by'] ?? 0) === $my_checker_id;
                $i_verified_this = in_array((int)$s['submission_id'], array_map('intval', $my_verified_sids));
                $all_verified    = ($verify_counts[$s['submission_id']] ?? 0) >= $assigned_for_this_kra;

                // EA modal JSON
                $ev_data = [];
                foreach ($s['evidence_files'] as $fi) {
                    $ev_data[] = ['path' => $fi['file_path'], 'name' => $fi['original_filename']];
                }
                $show_not_started = !isAdmin() && !$i_decided && !$i_started_review
                                    && in_array($app['status'], ['submitted','under_review']);
                $ea_json = htmlspecialchars(json_encode([
                    'sid'       => (int)$s['submission_id'],
                    'category'  => $s['kra_category'],
                    'faculty'   => formatDisplayName($app),
                    'all_ok'    => $all_verified,
                    'i_ok'      => $i_verified_this,
                    'v_count'   => $verify_counts[$s['submission_id']] ?? 0,
                    'v_total'   => $assigned_for_this_kra,
                    'can_rev'   => $can_act_revision && $can_review_this_kra,
                    'can_lock'  => !$can_review_this_kra,
                    'needs_rev' => $needs_rev,
                    'was_flagged' => $was_flagged,
                    'i_flagged' => $i_flagged_this,
                    'rev_at' => !empty($s['revision_at']) ? date('M d, Y h:i A', strtotime($s['revision_at'])) : '',
                    'rev_note'  => $s['revision_note'] ?? '',
                    'files'     => $ev_data,
                    'i_decided' => $i_decided,
                    'not_start' => $show_not_started,
                    'is_under'  => ($app['status'] === 'under_review'),
                ]), ENT_QUOTES);

                if ($all_verified) {
                    $btn_border='#16a34a';$btn_bg='#f0fdf4';$btn_color='#16a34a';$btn_label='Acceptable';
                } elseif ($needs_rev) {
                    $btn_border='#d97706';$btn_bg='#fffbeb';$btn_color='#d97706';$btn_label='Flagged';
                } elseif ($i_verified_this) {
                    $btn_border='#1e4d8c';$btn_bg='#eff6ff';$btn_color='#1e4d8c';$btn_label='Marked';
                } else {
                    $btn_border='#e2e8f0';$btn_bg='#f8fafc';$btn_color='#475569';$btn_label='Review';
                }
            ?>
            <tr style="border-bottom:1px solid #f1f5f9;<?= $needs_rev ? 'border-left:3px solid #d97706;background:#fffbf0;' : '' ?>"
                onmouseover="this.style.background='<?= $needs_rev ? '#fff8e6' : '#f8fafc' ?>'"
                onmouseout="this.style.background='<?= $needs_rev ? '#fffbf0' : '' ?>'">

                <!-- Entry -->
                <td style="padding:0.7rem 1rem;vertical-align:middle;">
                    <div style="display:flex;align-items:baseline;gap:0.5rem;flex-wrap:wrap;">
                        <span style="font-size:0.65rem;font-weight:700;color:#cbd5e1;flex-shrink:0;"><?= $idx+1 ?>.</span>
                        <div style="min-width:0;"><?= formatKraRemarks($s['kra_category'], $s['remarks'] ?? '') ?></div>
                        <?php if ($was_flagged): ?>
                        <span class="revision-history-marker"
                              title="<?= htmlspecialchars(($i_flagged_this ? 'You requested this revision' : 'Revision previously requested') . (!empty($s['revision_at']) ? ' on ' . date('M d, Y h:i A', strtotime($s['revision_at'])) : ''), ENT_QUOTES) ?>"
                              style="display:inline-flex;align-items:center;gap:4px;font-size:0.67rem;font-weight:600;color:<?= $needs_rev ? '#b45309' : '#64748b' ?>;white-space:nowrap;">
                            <i class="bi bi-flag-fill" aria-hidden="true"></i>
                            <?= $needs_rev ? 'Flagged for revision' : ($i_flagged_this ? 'Previously flagged by you' : 'Previously flagged') ?>
                        </span>
                        <?php endif; ?>
                    </div>
                </td>

                <!-- Raw -->
                <td style="padding:0.7rem 0.75rem;text-align:right;vertical-align:middle;white-space:nowrap;">
                    <span style="font-size:0.88rem;font-weight:700;color:#0f172a;"><?= number_format($raw_pts, 2) ?></span>
                    <?php if (($can_act_revision || isAdmin()) && $can_review_this_kra && $app['status'] !== 'submitted'): ?>
                    <button type="button"
                            onclick="openScoreModal(<?= $s['submission_id'] ?>,'<?= addslashes(sanitize($s['kra_category'])) ?>',<?= $raw_pts ?>)"
                            title="Edit score"
                            style="background:none;border:none;padding:1px 3px;cursor:pointer;color:#cbd5e1;margin-left:3px;vertical-align:middle;"
                            onmouseover="this.style.color='#1a3a6b'" onmouseout="this.style.color='#cbd5e1'">
                        <i class="bi bi-pencil-fill" style="font-size:0.65rem;"></i>
                    </button>
                    <?php endif; ?>
                </td>

                <!-- Weighted -->
                <td style="padding:0.7rem 0.75rem;text-align:right;vertical-align:middle;white-space:nowrap;">
                    <span style="font-size:0.88rem;font-weight:700;color:#1a3a6b;"><?= number_format($weighted_val, 2) ?></span>
                </td>

                <!-- Evidence & Action -->
                <td style="padding:0.7rem 0.75rem;text-align:center;vertical-align:middle;">
                    <button type="button"
                            onclick="openEAModal(this)"
                            data-ea='<?= $ea_json ?>'
                            title="View evidence &amp; actions"
                            style="display:inline-flex;align-items:center;justify-content:center;gap:4px;
                                   width:100%;padding:0.38rem 0.5rem;
                                   border:1px solid <?= $btn_border ?>;
                                   background:<?= $btn_bg ?>;color:<?= $btn_color ?>;
                                   font-size:0.72rem;font-weight:600;cursor:pointer;white-space:nowrap;">
                        <i class="bi bi-eye" style="font-size:0.75rem;"></i><?= $btn_label ?>
                    </button>
                </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
        <?php endif; // end else (entries exist) ?>
    </div><!-- /tab panel -->

    <!-- Hidden clear-revision forms for this KRA -->
    <?php foreach ($entries as $s): if (($s['revision_status'] ?? 'ok') === 'needs_revision'): ?>
    <form method="POST" action="index.php?page=<?= $return_page ?>&id=<?= $app_id ?>" id="clearRevForm_<?= (int)$s['submission_id'] ?>" style="display:none;">
        <input type="hidden" name="action" value="clear_revision">
        <input type="hidden" name="submission_id" value="<?= (int)$s['submission_id'] ?>">
    </form>
    <?php endif; endforeach; ?>

    <?php endforeach; // end KRA tab panels ?>

    <?php else: ?>
    <div style="padding:2.5rem 1rem;text-align:center;color:#94a3b8;">
        <i class="bi bi-inbox" style="font-size:1.75rem;display:block;margin-bottom:0.5rem;"></i>
        <div style="font-size:0.82rem;">No KRA submissions found.</div>
    </div>
    <?php endif; ?>

</div><!-- /neon-card kra-verification -->

<script>
function switchKraTab(tabId, kraName) {
    // Hide all panels
    document.querySelectorAll('[id^="kra-tab-"]').forEach(function(p) {
        p.style.display = 'none';
    });
    // Reset all tab buttons
    document.querySelectorAll('[id^="btn-kra-tab-"]').forEach(function(b) {
        b.style.color = '#64748b';
        b.style.borderBottomColor = 'transparent';
    });
    // Show selected panel
    var panel = document.getElementById(tabId);
    if (panel) panel.style.display = 'block';
    // Activate selected button
    var btn = document.getElementById('btn-' + tabId);
    if (btn) {
        btn.style.color = '#1a3a6b';
        btn.style.borderBottomColor = '#1a3a6b';
    }
}
</script>

<!-- Evidence & Action Modal -->
<div id="eaModal" style="display:none;position:fixed;inset:0;background:rgba(15,23,42,0.55);z-index:99990;align-items:center;justify-content:center;padding:1rem;">
    <div style="background:#fff;width:100%;max-width:640px;max-height:88vh;overflow-y:auto;border:1px solid #e2e8f0;box-shadow:0 20px 60px rgba(0,0,0,0.2);display:flex;flex-direction:column;">
        <!-- Header -->
        <div style="display:flex;align-items:center;justify-content:space-between;padding:0.85rem 1.25rem;border-bottom:1px solid #e2e8f0;background:#1a3a6b;flex-shrink:0;">
            <div style="display:flex;align-items:center;gap:0.5rem;">
                <i class="bi bi-file-earmark-check" style="color:#fff;font-size:1rem;"></i>
                <div>
                    <span id="eaModalTitle" style="font-weight:700;font-size:0.88rem;color:#fff;display:block;line-height:1.25;"></span>
                    <span id="eaModalFaculty" style="font-size:0.7rem;color:rgba(255,255,255,0.6);display:block;margin-top:1px;"></span>
                </div>
            </div>
            <button onclick="closeEAModal()" style="background:none;border:none;cursor:pointer;color:rgba(255,255,255,0.7);font-size:1.3rem;line-height:1;padding:0;" onmouseover="this.style.color='#fff'" onmouseout="this.style.color='rgba(255,255,255,0.7)'">&times;</button>
        </div>
        <div id="eaModalBody" style="padding:1.25rem;flex:1;"></div>
    </div>
</div>

<script>
var _eaModalData = null;

function openEAModal(btn) {
    var d = JSON.parse(btn.getAttribute('data-ea'));
    _eaModalData = d;
    document.getElementById('eaModalTitle').textContent = d.category + ' — Evidence & Action';
    document.getElementById('eaModalFaculty').textContent = d.faculty || '';

    var html = '';

    /* ── Status badge ── */
    var badgeHtml = '';
    if (d.all_ok) {
        badgeHtml = '<span style="display:inline-flex;align-items:center;gap:4px;padding:3px 10px;background:#f0fdf4;border:1px solid #bbf7d0;color:#16a34a;font-size:0.72rem;font-weight:700;"><i class="bi bi-patch-check-fill"></i>Acceptable</span>';
    } else if (d.i_ok) {
        badgeHtml = '<span style="display:inline-flex;align-items:center;gap:4px;padding:3px 10px;background:#eff6ff;border:1px solid #bfdbfe;color:#1e4d8c;font-size:0.72rem;font-weight:700;"><i class="bi bi-patch-check-fill"></i>You marked acceptable ('+d.v_count+'/'+d.v_total+')</span>';
    } else if (d.can_rev) {
        badgeHtml = '<span style="display:inline-flex;align-items:center;gap:4px;padding:3px 10px;background:#f8fafc;border:1px solid #e2e8f0;color:#94a3b8;font-size:0.72rem;font-weight:700;"><i class="bi bi-circle"></i>Not yet acceptable'+(d.v_count > 0 ? ' ('+d.v_count+'/'+d.v_total+')' : '')+'</span>';
    }
    if (badgeHtml) html += '<div style="margin-bottom:1rem;">'+badgeHtml+'</div>';

    /* ── Revision notice ── */
    if (d.needs_rev && d.rev_note) {
        html += '<div style="padding:0.6rem 0.9rem;background:#fef3c7;border:1px solid #fde68a;border-left:3px solid #d97706;margin-bottom:1rem;">'
            + '<div style="font-size:0.68rem;font-weight:700;color:#92400e;text-transform:uppercase;letter-spacing:0.05em;margin-bottom:3px;"><i class="bi bi-exclamation-triangle-fill" style="margin-right:4px;"></i>Revision Note</div>'
            + '<div style="font-size:0.82rem;color:#78350f;">' + escEA(d.rev_note) + '</div>'
            + '</div>';
    }
    if (d.was_flagged && !d.needs_rev) {
        html += '<div class="revision-history-notice" style="padding:0.6rem 0.9rem;background:#f8fafc;border:1px solid #e2e8f0;margin-bottom:1rem;">'
            + '<div style="font-size:0.72rem;font-weight:700;color:#475569;margin-bottom:3px;"><i class="bi bi-flag-fill" style="margin-right:4px;"></i>'
            + (d.i_flagged ? 'Previously flagged by you' : 'Previously flagged') + ' — revision cleared</div>'
            + (d.rev_at ? '<div style="font-size:0.7rem;color:#64748b;margin-bottom:3px;">Requested on ' + escEA(d.rev_at) + '</div>' : '')
            + (d.rev_note ? '<div style="font-size:0.8rem;color:#475569;">' + escEA(d.rev_note) + '</div>' : '')
            + '</div>';
    }

    /* ── Evidence files ── */
    html += '<div style="margin-bottom:1.25rem;">'
        + '<div style="font-size:0.68rem;font-weight:700;color:#64748b;text-transform:uppercase;letter-spacing:0.06em;margin-bottom:0.5rem;padding-bottom:0.4rem;border-bottom:1px solid #f1f5f9;">Evidence Files</div>';
    if (d.files && d.files.length > 0) {
        d.files.forEach(function(f, idx) {
            var url = 'pages/view_file.php?file=' + encodeURIComponent(f.path);
            var ext = f.path.split('.').pop().toLowerCase();
            var isImg = ['jpg','jpeg','png','gif','webp'].indexOf(ext) >= 0;
            var isPdf = ext === 'pdf';
            var btnId = 'eaFileBtn_' + idx;
            var previewId = 'eaPreview_' + idx;

            html += '<div style="border:1px solid #e2e8f0;margin-bottom:0.5rem;">';
            // File row button
            html += '<div style="display:flex;align-items:center;background:#f8fafc;border-bottom:0;">'
                + '<button type="button" id="' + btnId + '" onclick="toggleEAPreview(' + idx + ',\'' + url + '\',\'' + (isImg?'img':isPdf?'pdf':'other') + '\')" '
                + 'style="display:flex;align-items:center;gap:0.6rem;padding:0.55rem 0.75rem;flex:1;background:transparent;border:none;cursor:pointer;color:#334155;font-size:0.8rem;text-align:left;min-width:0;"'
                + ' onmouseover="this.style.color=\'#1a3a6b\'" onmouseout="this.style.color=\'#334155\'">'
                + '<i class="bi bi-' + (isImg ? 'file-earmark-image' : isPdf ? 'file-earmark-pdf' : 'file-earmark-text') + '" style="font-size:1rem;color:#1a3a6b;flex-shrink:0;"></i>'
                + '<span style="flex:1;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">' + escEA(f.name) + '</span>'
                + '<i class="bi bi-chevron-down" id="eaChevron_' + idx + '" style="font-size:0.7rem;color:#94a3b8;flex-shrink:0;transition:transform 0.2s;margin-right:0.5rem;"></i>'
                + '</button>'
                // Open in new tab icon
                + '<a href="' + url + '" target="_blank" rel="noopener noreferrer" title="Open in new tab"'
                + ' style="display:flex;align-items:center;justify-content:center;padding:0 0.75rem;border-left:1px solid #e2e8f0;color:#94a3b8;text-decoration:none;height:100%;min-height:36px;flex-shrink:0;"'
                + ' onmouseover="this.style.color=\'#1a3a6b\';this.style.background=\'#eff6ff\'" onmouseout="this.style.color=\'#94a3b8\';this.style.background=\'transparent\'">'
                + '<i class="bi bi-box-arrow-up-right" style="font-size:0.8rem;"></i>'
                + '</a>'
                + '</div>';
            // Inline preview area (hidden by default)
            html += '<div id="' + previewId + '" style="display:none;border-top:1px solid #e2e8f0;background:#f0f0f0;min-height:200px;max-height:520px;overflow:auto;"></div>';
            html += '</div>';
        });
    } else {
        html += '<div style="font-size:0.8rem;color:#94a3b8;font-style:italic;padding:0.5rem 0;">No evidence files uploaded.</div>';
    }
    html += '</div>';

    /* ── Actions ── */
    if (d.can_rev || d.not_start || d.can_lock) {
        html += '<div style="font-size:0.68rem;font-weight:700;color:#64748b;text-transform:uppercase;letter-spacing:0.06em;margin-bottom:0.75rem;padding-bottom:0.4rem;border-bottom:1px solid #f1f5f9;">Actions</div>';

        if (d.can_lock) {
            html += '<div style="font-size:0.78rem;color:#94a3b8;display:flex;align-items:center;gap:0.4rem;"><i class="bi bi-lock"></i>Read-only — assigned to another evaluator.</div>';
        } else if (d.not_start) {
            html += '<div style="font-size:0.78rem;color:#94a3b8;display:flex;align-items:center;gap:0.4rem;"><i class="bi bi-lock"></i>Start the review first to unlock actions.</div>';
        } else if (d.needs_rev) {
            if (d.is_under) {
                html += '<button type="button" onclick="eaClearFlag('+d.sid+')" '
                    + 'style="display:inline-flex;align-items:center;gap:0.4rem;padding:0.45rem 1rem;border:1px solid #16a34a;background:#f0fdf4;color:#16a34a;font-size:0.8rem;font-weight:600;cursor:pointer;width:100%;justify-content:center;">'
                    + '<i class="bi bi-check-circle"></i>Clear Revision Flag</button>';
            } else {
                html += '<div style="font-size:0.78rem;color:#94a3b8;display:flex;align-items:center;gap:0.4rem;"><i class="bi bi-hourglass-split"></i>Awaiting faculty resubmission.</div>';
            }
        } else {
            if (!d.i_ok) {
                html += '<div style="margin-bottom:0.6rem;">'
                    + '<label style="display:block;font-size:0.72rem;font-weight:600;color:#475569;margin-bottom:0.3rem;">Optional note</label>'
                    + '<textarea id="eaNoteField" rows="2" placeholder="Add a note (optional)..." '
                    + 'style="width:100%;border:1px solid #e2e8f0;padding:0.4rem 0.6rem;font-size:0.8rem;font-family:inherit;resize:vertical;box-sizing:border-box;"></textarea>'
                    + '</div>'
                    + '<button type="button" id="eaAcceptBtn" onclick="eaMarkAcceptable('+d.sid+')" '
                    + 'style="display:flex;align-items:center;justify-content:center;gap:0.4rem;padding:0.5rem 1rem;border:1px solid #1e4d8c;background:#1e4d8c;color:#fff;font-size:0.8rem;font-weight:600;cursor:pointer;width:100%;margin-bottom:0.5rem;">'
                    + '<i class="bi bi-patch-check"></i>&nbsp;Mark as Acceptable</button>';
            }
            var catEscaped = d.category.replace(/'/g, "\\'");
            html += '<button type="button" id="eaFlagBtn" onclick="eaFlag('+d.sid+',\''+catEscaped+'\')" '
                + 'style="display:flex;align-items:center;justify-content:center;gap:0.4rem;padding:0.5rem 1rem;border:1px solid #e2e8f0;background:#f8fafc;color:#475569;font-size:0.8rem;font-weight:600;cursor:pointer;width:100%;margin-top:0.4rem;">'
                + '<i class="bi bi-flag"></i>&nbsp;Flag for Revision</button>'
                + '<div id="eaFlagArea" style="display:none;margin-top:0.5rem;border:1px solid #fde68a;background:#fffbeb;padding:0.75rem;">'
                + '<div style="font-size:0.72rem;font-weight:700;color:#92400e;margin-bottom:0.4rem;"><i class="bi bi-exclamation-triangle-fill" style="margin-right:4px;"></i>A reason is required to flag for revision.</div>'
                + '<textarea id="eaFlagNote" rows="3" placeholder="Describe what needs to be corrected..." '
                + 'style="width:100%;border:1px solid #fde68a;padding:0.4rem 0.6rem;font-size:0.8rem;font-family:inherit;resize:vertical;box-sizing:border-box;background:#fff;"></textarea>'
                + '<div id="eaFlagError" style="display:none;font-size:0.72rem;color:#dc2626;margin-top:0.3rem;"><i class="bi bi-x-circle" style="margin-right:3px;"></i>Please enter a reason before submitting.</div>'
                + '<div style="display:flex;gap:0.5rem;margin-top:0.5rem;">'
                + '<button type="button" onclick="eaFlagSubmit('+d.sid+',\''+catEscaped+'\')" '
                + 'style="flex:1;display:flex;align-items:center;justify-content:center;gap:0.4rem;padding:0.45rem 1rem;border:none;background:#d97706;color:#fff;font-size:0.8rem;font-weight:600;cursor:pointer;">'
                + '<i class="bi bi-flag-fill"></i>&nbsp;Submit Flag</button>'
                + '<button type="button" onclick="eaFlagCancel()" '
                + 'style="padding:0.45rem 0.85rem;border:1px solid #e2e8f0;background:#fff;color:#475569;font-size:0.8rem;font-weight:600;cursor:pointer;">'
                + 'Cancel</button>'
                + '</div>'
                + '</div>';
        }
    }

    document.getElementById('eaModalBody').innerHTML = html;
    document.getElementById('eaModal').style.display = 'flex';
}

function closeEAModal() {
    document.getElementById('eaModal').style.display = 'none';
    _eaModalData = null;
}

function escEA(s) {
    return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

function toggleEAPreview(idx, url, type) {
    var preview = document.getElementById('eaPreview_' + idx);
    var chevron = document.getElementById('eaChevron_' + idx);
    if (!preview) return;

    var isOpen = preview.style.display !== 'none';
    if (isOpen) {
        preview.style.display = 'none';
        preview.innerHTML = '';
        if (chevron) chevron.style.transform = '';
        return;
    }

    preview.style.display = 'block';
    if (chevron) chevron.style.transform = 'rotate(180deg)';

    if (type === 'img') {
        preview.style.background = '#f0f0f0';
        preview.style.textAlign = 'center';
        preview.innerHTML = '<img src="' + url + '" '
            + 'style="max-width:100%;max-height:480px;object-fit:contain;display:block;margin:0 auto;padding:8px;" '
            + 'onerror="this.parentNode.innerHTML=\'<p style=\\\"color:#94a3b8;padding:1rem;font-size:0.8rem;\\\">Could not load image.</p>\'">';
    } else if (type === 'pdf') {
        preview.style.background = '#525659';
        preview.style.minHeight = '480px';
        preview.innerHTML = '<iframe src="' + url + '" style="width:100%;height:480px;border:none;display:block;"></iframe>';
    } else {
        preview.style.background = '#f8fafc';
        preview.innerHTML = '<div style="padding:1.5rem;text-align:center;">'
            + '<i class="bi bi-file-earmark" style="font-size:2rem;display:block;margin-bottom:0.5rem;color:#1a3a6b;"></i>'
            + '<div style="font-size:0.8rem;color:#475569;margin-bottom:0.75rem;">Preview not available for this file type.</div>'
            + '<a href="' + url + '" target="_blank" rel="noopener noreferrer" '
            + 'style="display:inline-flex;align-items:center;gap:0.4rem;padding:0.4rem 1rem;background:#1a3a6b;color:#fff;font-size:0.8rem;font-weight:600;text-decoration:none;">'
            + '<i class="bi bi-box-arrow-up-right"></i>Open file</a>'
            + '</div>';
    }
}

function eaMarkAcceptable(sid) {
    var note = (document.getElementById('eaNoteField') || {value:''}).value || '';
    var btn  = document.getElementById('eaAcceptBtn');
    if (btn) { btn.disabled = true; btn.innerHTML = '<i class="bi bi-hourglass-split"></i>&nbsp;Saving...'; }

    var fd = new FormData();
    fd.append('action',        'verify_submission');
    fd.append('submission_id', sid);
    fd.append('note',          note);

    fetch('index.php?page=<?= $return_page ?>&id=<?= $app_id ?>', {
        method: 'POST',
        headers: { 'X-Requested-With': 'XMLHttpRequest' },
        body: fd
    })
    .then(function(r){ return r.json(); })
    .then(function(data) {
        if (data.ok) {
            closeEAModal();
            // Update the eye button in the table immediately
            var allDone = !!data.all_verified;
            var tableBtn = document.querySelector('button[data-ea*=\'"sid":'+sid+'\']');
            if (tableBtn) {
                if (allDone) {
                    tableBtn.style.borderColor = '#16a34a';
                    tableBtn.style.background  = '#f0fdf4';
                    tableBtn.style.color       = '#16a34a';
                    tableBtn.innerHTML         = '<i class="bi bi-eye" style="font-size:0.8rem;"></i> Acceptable';
                } else {
                    tableBtn.style.borderColor = '#1e4d8c';
                    tableBtn.style.background  = '#eff6ff';
                    tableBtn.style.color       = '#1e4d8c';
                    tableBtn.innerHTML         = '<i class="bi bi-eye" style="font-size:0.8rem;"></i> Marked';
                }
                // Update the JSON data on the button so reopening the modal reflects the new state
                try {
                    var d = JSON.parse(tableBtn.getAttribute('data-ea'));
                    d.i_ok   = true;
                    d.all_ok = allDone;
                    d.v_count = data.done_count || 1;
                    tableBtn.setAttribute('data-ea', JSON.stringify(d));
                } catch(e){}
            }
            showToast('Entry marked as acceptable.', 'success');
        } else {
            if (btn) { btn.disabled = false; btn.innerHTML = '<i class="bi bi-patch-check"></i>&nbsp;Mark as Acceptable'; }
            showToast(data.error || 'Could not mark as acceptable. Please try again.', 'error');
        }
    })
    .catch(function() {
        if (btn) { btn.disabled = false; btn.innerHTML = '<i class="bi bi-patch-check"></i>&nbsp;Mark as Acceptable'; }
        showToast('Network error. Please try again.', 'error');
    });
}

function eaFlag(sid, category) {
    var flagBtn  = document.getElementById('eaFlagBtn');
    var flagArea = document.getElementById('eaFlagArea');
    if (!flagArea) { return; }
    if (flagBtn)  flagBtn.style.display  = 'none';
    flagArea.style.display = 'block';
    flagArea.style.visibility = 'visible';
    var ta = document.getElementById('eaFlagNote');
    if (ta) setTimeout(function(){ ta.focus(); }, 50);
}

function eaFlagCancel() {
    var flagBtn  = document.getElementById('eaFlagBtn');
    var flagArea = document.getElementById('eaFlagArea');
    var err      = document.getElementById('eaFlagError');
    if (flagArea) flagArea.style.display = 'none';
    if (flagBtn)  flagBtn.style.display = 'flex';
    if (err)      err.style.display = 'none';
    var ta = document.getElementById('eaFlagNote');
    if (ta) ta.value = '';
}

function eaFlagSubmit(sid, category) {
    var ta  = document.getElementById('eaFlagNote');
    var err = document.getElementById('eaFlagError');
    var note = ta ? ta.value.trim() : '';
    if (!note) {
        if (err) err.style.display = 'block';
        if (ta)  { ta.style.borderColor = '#dc2626'; ta.focus(); }
        showToast('Please enter a reason before flagging for revision.', 'warning');
        return;
    }
    if (err) err.style.display = 'none';
    var subIdField = document.getElementById('revModalSubId');
    var noteField  = document.getElementById('revModalNote');
    if (subIdField) subIdField.value = sid;
    if (noteField)  noteField.value  = note;
    var form = document.getElementById('revisionForm');
    if (form) {
        closeEAModal();
        form.submit();
    } else {
        closeEAModal();
        openRevisionModal(sid, category);
        setTimeout(function() {
            var nf = document.getElementById('revModalNote');
            if (nf) nf.value = note;
        }, 100);
    }
}

function eaClearFlag(sid) {
    if (!confirm('Mark this entry as OK and clear the revision flag?')) return;
    document.getElementById('clearRevForm_' + sid) && document.getElementById('clearRevForm_' + sid).submit();
    closeEAModal();
}

document.getElementById('eaModal').addEventListener('click', function(e) {
    if (e.target === this) closeEAModal();
});
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape' && document.getElementById('eaModal').style.display !== 'none') closeEAModal();
});
</script>

<!-- -- Checker Decision Panel ------------------------------- -->
<?php if (!isAdmin()): ?>

<?php if ($i_approved): ?>
<!-- Evaluation complete confirmation -->
<div class="neon-card mb-3" style="border-left:4px solid #1a3a6b;">
    <div class="d-flex align-items-center gap-3">
        <div style="width:40px;height:40px;border-radius:50%;background:#eff6ff;display:flex;align-items:center;justify-content:center;flex-shrink:0;">
            <i class="bi bi-check-circle-fill" style="color:#1a3a6b;font-size:1.1rem;"></i>
        </div>
        <div>
            <div class="fw-semibold" style="color:#1a3a6b;">You have completed this evaluation</div>
            <div class="text-muted small">
                <?php if ($approved_count >= $total_checkers): ?>
                All <?= $total_checkers ?> evaluator(s) have completed their work. The evaluation is now <strong>complete</strong>.
                <?php else: ?>
                Waiting for <?= $total_checkers - $slots_taken ?> more evaluator(s) to finish.
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<?php elseif ($i_rejected): ?>
<!-- Returned for revision confirmation -->
<div class="neon-card mb-3" style="border-left:4px solid #334155;">
    <div class="d-flex align-items-center gap-3">
        <div style="width:40px;height:40px;border-radius:50%;background:#f8fafc;display:flex;align-items:center;justify-content:center;flex-shrink:0;">
            <i class="bi bi-arrow-counterclockwise" style="color:#334155;font-size:1.1rem;"></i>
        </div>
        <div>
            <div class="fw-semibold" style="color:#1a3a6b;">You returned this application for revision</div>
            <div class="text-muted small">
                <?php if ($rejected_count >= $total_checkers): ?>
                All <?= $total_checkers ?> evaluator(s) returned it for revision. The application has been <strong>returned to the faculty</strong>.
                <?php else: ?>
                <?= $rejected_count ?> return note(s) recorded. The application stays <strong>Under Evaluation</strong> until all <?= $total_checkers ?> evaluator(s) have finished.
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<?php elseif ($can_act_checker && $app['status'] === 'submitted'): ?>
<!-- Start Review panel -->
<div class="neon-card mb-3" style="border-left:4px solid #1e4d8c;">
    <div style="display:flex;align-items:center;gap:1rem;flex-wrap:wrap;">
        <div style="flex:1;min-width:0;">
            <div class="fw-semibold mb-1" style="color:#1a3a6b;font-size:0.92rem;">
                <i class="bi bi-play-circle me-2"></i>Ready to Review?
            </div>
            <div class="text-muted small">
                Click <strong>Start Review</strong> to begin evaluating this application.
                Once started, you can edit KRA scores and request revisions.
            </div>
        </div>
        <form method="POST" action="index.php?page=<?= $return_page ?>&id=<?= $app_id ?>" id="startReviewForm">
            <input type="hidden" name="action" value="start_review">
            <button type="button" class="btn btn-primary"
                    onclick="confirmDelete('Start reviewing this application? The faculty will be notified.','startReviewForm','Start Review','bi-play-circle')">
                <i class="bi bi-play-circle me-2"></i>Start Review
            </button>
        </form>
    </div>
</div>

<?php elseif ($can_act_checker && $app['status'] !== 'submitted'): ?>
<!-- Regular checker decision panel -->
<div class="neon-card mb-3">
    <h6 class="mb-3" style="color:var(--blue-dark);"><i class="bi bi-pencil-square me-2"></i>Your Evaluation Action</h6>
    <div class="alert alert-info py-2 small mb-3">
        <i class="bi bi-info-circle me-1"></i>
        <strong>Complete Evaluation</strong> once all KRA entries are checked and acceptable.<br>
        <strong>Return for Revision</strong> when the faculty needs to correct the submission.
        The result is a recommendation for further committee review and is not a final decision.
    </div>
    <form method="POST" action="index.php?page=<?= $return_page ?>&id=<?= $app_id ?>" id="checkerDecisionForm">
        <input type="hidden" name="action" id="checkerDecisionAction" value="">
        <div class="mb-3">
            <label class="form-label fw-semibold">
                Remarks
                <small class="text-muted">(optional for completion, <span class="text-danger">required when returning for revision</span>)</small>
            </label>
            <textarea name="checker_remarks" id="checkerRemarksField" class="form-control" rows="2"
                      placeholder="Enter your remarks..."></textarea>
        </div>
        <div class="d-flex gap-2 flex-wrap">
            <?php if ($score_result['weighted_score'] < 41): ?>
            <button type="button" class="btn btn-success" disabled
                    title="Cannot complete evaluation - weighted score (<?= number_format($score_result['weighted_score'], 2) ?>) is below the minimum of 41."
                    style="opacity:0.5;cursor:not-allowed;">
                <i class="bi bi-check-circle me-2"></i>Complete Evaluation
            </button>
            <?php else: ?>
            <button type="button" class="btn btn-success"
                    onclick="submitCheckerDecision('checker_approve','Mark this evaluation complete?','Complete Evaluation','bi-check-circle')">
                <i class="bi bi-check-circle me-2"></i>Complete Evaluation
            </button>
            <?php endif; ?>
            <button type="button" class="btn btn-danger"
                    onclick="submitCheckerDecision('checker_reject','Return this application for revision?','Return for Revision','bi-arrow-counterclockwise')">
                <i class="bi bi-arrow-counterclockwise me-2"></i>Return for Revision
            </button>
        </div>
        <p class="text-muted small mt-2 mb-0">
            <i class="bi bi-info-circle me-1"></i>
            Use "Request Revision" on individual KRA entries above to flag specific items for the faculty to fix.
        </p>
    </form>
</div>
<script>
function submitCheckerDecision(action, msg, btnLabel, btnIcon) {
    const remarks = document.getElementById('checkerRemarksField').value.trim();
    if (action === 'checker_reject' && !remarks) {
        document.getElementById('checkerRemarksField').style.borderColor = '#334155';
        document.getElementById('checkerRemarksField').focus();
        showToast('A reason is required when returning for revision.', 'warning');
        return;
    }
    document.getElementById('checkerRemarksField').style.borderColor = '';
    document.getElementById('checkerDecisionAction').value = action;
    confirmDelete(msg, 'checkerDecisionForm', btnLabel, btnIcon);
}
</script>

<?php elseif ($app['status'] === 'talisay_review' && isEvaluator()): ?>
<!-- ITC evaluator decision panel -->
<?php
$my_talisay_review = null;
foreach ($checker_reviews as $cr) {
    if ((int)$cr['checker_id'] === $my_checker_id) { $my_talisay_review = $cr; break; }
}
$talisay_decided = $my_talisay_review && in_array($my_talisay_review['decision'], ['approved','rejected']);
?>
<?php if ($talisay_decided): ?>
<div class="neon-card mb-3" style="border-left:4px solid <?= $my_talisay_review['decision']==='approved'?'#1e4d8c':'#1e293b' ?>;">
    <div class="d-flex align-items-center gap-3">
        <i class="bi bi-<?= $my_talisay_review['decision']==='approved'?'check-circle-fill':'x-circle-fill' ?>"
           style="font-size:1.4rem;color:<?= $my_talisay_review['decision']==='approved'?'#1e4d8c':'#1e293b' ?>;"></i>
        <div>
            <div class="fw-semibold" style="color:#1a3a6b;">
                You have <?= $my_talisay_review['decision']==='approved'?'completed the evaluation for':'returned for revision' ?> this application.
            </div>
            <?php if ($my_talisay_review['remarks']): ?>
            <div class="text-muted small">Remarks: <?= sanitize($my_talisay_review['remarks']) ?></div>
            <?php endif; ?>
        </div>
    </div>
</div>
<?php else: ?>
<div class="neon-card mb-3" style="border-left:4px solid #475569;">
    <h6 class="mb-3" style="color:var(--blue-dark);">
        <i class="bi bi-building me-2"></i>Evaluation Action
    </h6>
    <div class="alert py-2 small mb-3" style="background:#f0f7ff;border:1px solid #bfdbfe;color:#1e4d8c;">
        <i class="bi bi-info-circle me-1"></i>
        This application is under evaluation. Results recorded here are recommendations for further committee review and are not final decisions.
    </div>
    <form method="POST" action="index.php?page=<?= $return_page ?>&id=<?= $app_id ?>" id="talisayDecisionForm">
        <input type="hidden" name="action" id="talisayDecisionAction" value="">
        <div class="mb-3">
            <label class="form-label fw-semibold">
                Remarks
                <small class="text-muted">(<span class="text-danger">required when returning for revision</span>)</small>
            </label>
            <textarea name="checker_remarks" id="talisayRemarksField" class="form-control" rows="2"
                      placeholder="Enter your remarks..."></textarea>
        </div>
        <div class="d-flex gap-2 flex-wrap">
            <?php if ($score_result['weighted_score'] < 41): ?>
            <button type="button" class="btn btn-success" disabled
                    title="Cannot complete evaluation - weighted score (<?= number_format($score_result['weighted_score'], 2) ?>) is below the minimum of 41."
                    style="opacity:0.5;cursor:not-allowed;">
                <i class="bi bi-check-circle me-2"></i>Complete Evaluation
            </button>
            <?php else: ?>
            <button type="button" class="btn btn-success"
                    onclick="submitTalisayDecision('talisay_approve','Mark this evaluation complete?','Complete Evaluation','bi-check-circle')">
                <i class="bi bi-check-circle me-2"></i>Complete Evaluation
            </button>
            <?php endif; ?>
            <button type="button" class="btn btn-danger"
                    onclick="submitTalisayDecision('talisay_reject','Return this application for revision?','Return for Revision','bi-arrow-counterclockwise')">
                <i class="bi bi-arrow-counterclockwise me-2"></i>Return for Revision
            </button>
        </div>
    </form>
</div>
<script>
function submitTalisayDecision(action, msg, btnLabel, btnIcon) {
    const remarks = document.getElementById('talisayRemarksField').value.trim();
    if (action === 'talisay_reject' && !remarks) {
        document.getElementById('talisayRemarksField').style.borderColor = '#334155';
        document.getElementById('talisayRemarksField').focus();
        showFormError('A reason is required when returning the application.');
        return;
    }
    document.getElementById('talisayRemarksField').style.borderColor = '';
    document.getElementById('talisayDecisionAction').value = action;
    confirmDelete(msg, 'talisayDecisionForm', btnLabel, btnIcon);
}
</script>
<?php endif; ?>

<?php elseif (!$i_have_slot && !$can_join && !isAdmin()): ?>
<!-- Slots full, not assigned -->
<div class="neon-card mb-3" style="border-left:4px solid #e2e8f0;">
    <div class="d-flex align-items-center gap-3">
        <i class="bi bi-lock" style="color:#94a3b8;font-size:1.2rem;"></i>
        <div class="text-muted small">You are not part of this review panel.</div>
    </div>
</div>
<?php endif; ?>

<?php endif; // !isAdmin() ?>

<!-- -- Admin Evaluation Summary -------------------------------- -->
<?php if ($app['status'] === 'approved' && isAdmin()): ?>
<div class="neon-card mb-3" style="border-color:#e2e8f0;">
    <h6 class="mb-2" style="color:var(--blue-dark);"><i class="bi bi-clipboard-check me-2"></i>Evaluation Complete</h6>
    <p class="text-muted small mb-0">
        Evaluators have finished checking this submission. The score and recommended rank are for further committee review and are not a final decision.
    </p>
</div>
<?php endif; ?>

<!-- -- Revision Request Modal ------------------------------- -->
<div id="revisionModal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,0.5);z-index:9999;align-items:center;justify-content:center;padding:1rem;">
    <div style="background:#fff;border-radius:12px;width:100%;max-width:480px;box-shadow:0 20px 60px rgba(0,0,0,0.25);overflow:hidden;">
        <div style="background:#1a3a6b;padding:1.25rem 1.5rem;display:flex;justify-content:space-between;align-items:center;">
            <div style="display:flex;align-items:center;gap:0.75rem;">
                <i class="bi bi-pencil-square" style="color:#475569;font-size:1.1rem;"></i>
                <span style="color:#fff;font-weight:700;font-size:1rem;">Request Revision</span>
            </div>
            <button onclick="document.getElementById('revisionModal').style.display='none'"
                    style="background:none;border:none;color:#fff;font-size:1.25rem;cursor:pointer;line-height:1;">&times;</button>
        </div>
        <div style="padding:1.5rem;">
            <p style="font-size:0.85rem;color:#64748b;margin-bottom:0.5rem;">
                KRA Entry: <strong id="revModalCategory" style="color:#1e293b;"></strong>
            </p>
            <p style="font-size:0.82rem;color:#64748b;margin-bottom:1rem;">
                Describe what needs to be corrected. The faculty will see this note and must fix and resubmit.
            </p>
            <form id="revisionForm" method="POST" action="index.php?page=<?= $return_page ?>&id=<?= $app_id ?>">
                <input type="hidden" name="action" value="request_revision">
                <input type="hidden" name="submission_id" id="revModalSubId">
                <div class="mb-3">
                    <label class="form-label fw-semibold" style="font-size:0.85rem;">Revision Note <span class="text-danger">*</span></label>
                    <textarea name="revision_note" id="revModalNote" class="form-control" rows="3"
                              placeholder="e.g. Evidence file is unclear, please upload a clearer copy." required></textarea>
                </div>
                <div class="d-flex gap-2">
                    <button type="button" class="btn btn-secondary flex-fill"
                            onclick="document.getElementById('revisionModal').style.display='none'">Cancel</button>
                    <button type="button" class="btn btn-warning flex-fill fw-bold" onclick="submitRevision()">
                        <i class="bi bi-send me-1"></i>Send Revision Request
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
function openRevisionModal(subId, category) {
    document.getElementById('revModalSubId').value = subId;
    document.getElementById('revModalCategory').textContent = category;
    document.getElementById('revModalNote').value = '';
    document.getElementById('revisionModal').style.display = 'flex';
    setTimeout(() => document.getElementById('revModalNote').focus(), 100);
}
function submitRevision() {
    const note = document.getElementById('revModalNote').value.trim();
    if (!note) {
        document.getElementById('revModalNote').style.borderColor = '#334155';
        document.getElementById('revModalNote').focus();
        return;
    }
    document.getElementById('revisionForm').submit();
}
document.getElementById('revisionModal').addEventListener('click', function(e) {
    if (e.target === this) this.style.display = 'none';
});
</script>

<!-- Score Edit Modal -->
<div id="scoreModal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,0.5);z-index:9999;align-items:center;justify-content:center;padding:1rem;">
    <div style="background:#fff;border-radius:12px;width:100%;max-width:420px;box-shadow:0 20px 60px rgba(0,0,0,0.25);overflow:hidden;">
        <div style="background:#1a3a6b;padding:1.25rem 1.5rem;display:flex;justify-content:space-between;align-items:center;">
            <div>
                <span style="color:#fff;font-weight:700;font-size:1rem;"><i class="bi bi-pencil-square me-2"></i>Edit KRA Score</span>
                <div id="scoreModalCategory" style="color:#bfdbfe;font-size:0.75rem;margin-top:2px;"></div>
            </div>
            <button onclick="document.getElementById('scoreModal').style.display='none'"
                    style="background:none;border:none;color:#fff;font-size:1.25rem;cursor:pointer;line-height:1;">&times;</button>
        </div>
        <div style="padding:1.5rem;">
            <form id="scoreForm" method="POST" action="index.php?page=<?= $return_page ?>&id=<?= $app_id ?>">
                <input type="hidden" name="action" value="alter_score">
                <input type="hidden" name="submission_id" id="scoreModalSubId">
                <div class="mb-3">
                    <label class="form-label fw-semibold small">Current Score</label>
                    <div id="scoreModalCurrent" style="font-size:1.3rem;font-weight:800;color:#1a3a6b;"></div>
                </div>
                <div class="mb-3">
                    <label class="form-label fw-semibold small">New Score <span class="text-danger">*</span></label>
                    <input type="number" name="new_points" id="scoreModalInput" class="form-control"
                           step="0.01" min="0" max="200" placeholder="Enter corrected score" required>
                    <div class="form-text">Enter the reviewed correct score for this KRA entry.</div>
                </div>
                <div class="mb-4">
                    <label class="form-label fw-semibold small">Reason for Change <span class="text-muted">(optional)</span></label>
                    <input type="text" name="alter_note" class="form-control"
                           placeholder="e.g. Corrected based on reviewed documents">
                </div>
                <div class="d-flex gap-2">
                    <button type="button" class="btn btn-secondary flex-fill"
                            onclick="document.getElementById('scoreModal').style.display='none'">Cancel</button>
                    <button type="button" class="btn btn-primary flex-fill fw-bold" onclick="submitScoreEdit()">
                        <i class="bi bi-check-circle me-1"></i>Save Score
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>


<script>
function showScoreSaved(oldVal, newVal, category) {
    const overlay = document.createElement('div');
    overlay.className = 'score-success-overlay';
    overlay.innerHTML = `
        <div class="score-success-box" id="scoreSuccessBox">
            <svg class="score-check-svg" width="64" height="64" viewBox="0 0 52 52">
                <circle cx="26" cy="26" r="24"/>
                <path d="M14 26 l8 8 l16-16"/>
            </svg>
            <div class="score-success-label">Score Updated!</div>
            <div class="score-success-sub">${category}: ${oldVal} -> ${newVal}</div>
        </div>`;
    document.body.appendChild(overlay);
    setTimeout(() => {
        const box = overlay.querySelector('#scoreSuccessBox');
        if (box) box.classList.add('fade-out');
        setTimeout(() => overlay.remove(), 380);
    }, 1800);
}

function openScoreModal(subId, category, currentPts) {
    document.getElementById('scoreModalSubId').value  = subId;
    document.getElementById('scoreModalCategory').textContent = category;
    document.getElementById('scoreModalCurrent').textContent  = currentPts;
    document.getElementById('scoreModalInput').value  = currentPts;
    document.getElementById('scoreModal').style.display = 'flex';
    setTimeout(() => document.getElementById('scoreModalInput').select(), 100);
}

function quickVerify(subId, btn) {
    btn.disabled = true;
    btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1" style="width:10px;height:10px;"></span>Marking...';

    const noteEl = document.getElementById('verifyNote_' + subId);
    const note   = noteEl ? noteEl.value : '';

    const fd = new FormData();
    fd.append('action',        'verify_submission');
    fd.append('submission_id', subId);
    fd.append('note',          note);

    fetch('index.php?page=<?= $return_page ?>&id=<?= $app_id ?>', {
        method: 'POST',
        headers: { 'X-Requested-With': 'XMLHttpRequest' },
        body: fd
    })
    .then(async r => {
        const text = await r.text();
        try {
            return JSON.parse(text);
        } catch (err) {
            return { ok: false, error: 'The server saved an unexpected response. Please try again.' };
        }
    })
    .then(data => {
        if (data.ok) {
            // Remove the note textarea alongside the button
            if (noteEl) noteEl.remove();

            // Replace the action button with an "Acceptable" badge in-place
            const row = btn.closest('tr');
            btn.outerHTML = '<span style="font-size:0.68rem;color:#16a34a;font-weight:600;display:inline-flex;align-items:center;gap:3px;">'
                          + '<i class="bi bi-patch-check-fill"></i>Acceptable</span>';

            // Update the not acceptable badge in the evidence cell
            if (row) {
                const verifyBadge = row.querySelector('[data-verification-badge="' + subId + '"]');
                if (verifyBadge) {
                    const done = Number(data.done_count || 1);
                    const total = Number(data.total_count || 1);
                    const allDone = !!data.all_verified || (total > 0 && done >= total);
                    verifyBadge.style.color = allDone ? '#16a34a' : '#1e4d8c';
                    verifyBadge.style.background = allDone ? '#f0fdf4' : '#eff6ff';
                    verifyBadge.style.borderColor = allDone ? '#bbf7d0' : '#bfdbfe';
                    verifyBadge.style.fontWeight = '700';
                    verifyBadge.innerHTML = allDone
                        ? '<i class="bi bi-patch-check-fill" style="font-size:0.62rem;"></i>Acceptable'
                        : '<i class="bi bi-patch-check-fill" style="font-size:0.62rem;"></i>You marked acceptable <span style="font-size:0.6rem;color:#64748b;">(' + done + '/' + total + ')</span>';
                }
            }

            // Re-check if all are now acceptable  -  remove the block message if shown
            const remaining = document.querySelectorAll('[id^="verifyBtn_"]');
            if (remaining.length === 0) {
                const approveBtn = document.querySelector('#checkerDecisionForm .btn-success');
                if (approveBtn && approveBtn.disabled) {
                    approveBtn.disabled = false;
                    approveBtn.title = '';
                }
            }
        } else {
            btn.disabled = false;
            btn.innerHTML = '<i class="bi bi-patch-check"></i>Mark as Acceptable';
            showToast(data.error || 'Mark as acceptable failed. Please try again.', 'error');
        }
    })
    .catch(() => {
        btn.disabled = false;
        btn.innerHTML = '<i class="bi bi-patch-check"></i>Mark as Acceptable';
    });
}

function submitScoreEdit() {
    const val = parseFloat(document.getElementById('scoreModalInput').value);
    if (isNaN(val) || val < 0) {
        document.getElementById('scoreModalInput').style.borderColor = '#334155';
        document.getElementById('scoreModalInput').focus();
        return;
    }
    document.getElementById('scoreModalInput').style.borderColor = '';

    const oldVal  = document.getElementById('scoreModalCurrent').textContent;
    const category= document.getElementById('scoreModalCategory').textContent;
    const note    = document.getElementById('scoreModalNote')?.value || '';
    const subId   = document.getElementById('scoreModalSubId').value;

    // Close modal
    document.getElementById('scoreModal').style.display = 'none';

    // Submit via AJAX
    const fd = new FormData();
    fd.append('action',        'alter_score');
    fd.append('submission_id', subId);
    fd.append('new_points',    val);
    fd.append('alter_note',    note);

    fetch('index.php?page=<?= $return_page ?>&id=<?= $app_id ?>', {
        method: 'POST',
        headers: { 'X-Requested-With': 'XMLHttpRequest' },
        body: fd
    })
    .then(r => r.json())
    .then(data => {
        if (data.auto_returned) {
            // Show warning overlay  -  score dropped below 41
            const overlay = document.createElement('div');
            overlay.className = 'score-success-overlay';
            overlay.innerHTML = `
                <div class="score-success-box" id="scoreSuccessBox" style="border-top:4px solid #334155;">
                    <svg width="64" height="64" viewBox="0 0 52 52" style="flex-shrink:0;">
                        <circle cx="26" cy="26" r="24" fill="none" stroke="#334155" stroke-width="3"
                            stroke-dasharray="166" stroke-dashoffset="0"/>
                        <text x="26" y="32" text-anchor="middle" font-size="22" font-weight="bold" fill="#334155">!</text>
                    </svg>
                    <div class="score-success-label" style="color:#1e293b;">Application Returned</div>
                    <div class="score-success-sub" style="text-align:center;">Weighted score dropped to <strong>${data.weighted}</strong><br>below the minimum of 41</div>
                </div>`;
            document.body.appendChild(overlay);
            setTimeout(() => {
                const box = overlay.querySelector('#scoreSuccessBox');
                if (box) box.classList.add('fade-out');
                setTimeout(() => {
                    overlay.remove();
                    window.location.href = 'index.php?page=<?= $return_page ?>&id=<?= $app_id ?>';
                }, 380);
            }, 2200);
        } else {
            // Normal success overlay
            showScoreSaved(oldVal, val, category);
            setTimeout(() => {
                window.location.href = 'index.php?page=<?= $return_page ?>&id=<?= $app_id ?>#kra-verification';
            }, 1900);
        }
    })
    .catch(() => {
        window.location.href = 'index.php?page=<?= $return_page ?>&id=<?= $app_id ?>';
    });
}

document.getElementById('scoreModal').addEventListener('click', function(e) {
    if (e.target === this) this.style.display = 'none';
});

// Auto-scroll to KRA section on load if coming back from score save
document.addEventListener('DOMContentLoaded', function () {
    if (window.location.hash === '#kra-verification') {
        const el = document.getElementById('kra-verification');
        if (el) setTimeout(() => el.scrollIntoView({ behavior: 'smooth', block: 'start' }), 100);
    }
});
</script>
