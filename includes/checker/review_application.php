<?php
$app_id = intval($_GET['id'] ?? 0);
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
$app = $pdo->prepare("SELECT a.*, u.full_name, u.first_name, u.middle_name, u.last_name, u.email, u.employee_id, u.rank,
    COALESCE(camp.campus_name, 'N/A') as campus_name, c.cycle_name
    FROM applications a
    JOIN users u ON a.user_id = u.user_id
    LEFT JOIN campuses camp ON u.campus_id = camp.campus_id
    LEFT JOIN cycles c ON a.cycle_id = c.cycle_id
    WHERE a.application_id = ?");
$app->execute([$app_id]);
$app = $app->fetch();
if (!$app) { echo '<div class="alert alert-danger">Application not found.</div>'; return; }

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
function countActiveCheckers($pdo): int {
    $stmt = $pdo->query("SELECT COUNT(*) FROM users
        WHERE role = 'checker' AND status = 'active'");
    return max(1, (int)$stmt->fetchColumn());
}

// -- Helper: total active talisay checkers ---------------------
function countActiveTalisayCheckers($pdo): int {
    $stmt = $pdo->query("SELECT COUNT(*) FROM users
        WHERE role = 'talisay_checker' AND status = 'active'");
    return (int)$stmt->fetchColumn();
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

    // Start review — first checker to act moves app to under_review
    if ($action === 'start_review' && $app['status'] === 'submitted') {
        $pdo->prepare("UPDATE applications SET status='under_review', checker_id=? WHERE application_id=?")
            ->execute([$checker_id, $app_id]);
        logAudit($pdo, $checker_id, 'Review Started', "You started reviewing {$app['full_name']}'s application.");
        // Notify faculty their application is now under review
        createNotif($pdo, $app['user_id'], 'under_review', 'Your application is now under review by a campus checker.', $app_id);
        flashMessage('success', 'Review started.');
        echo "<script>window.location.href='index.php?page={$return_page}&id={$app_id}';</script>"; exit;
    }

    // Join review action is no longer needed &mdash; kept for backward compat redirect only
    if ($action === 'join_review') {
        echo "<script>window.location.href='index.php?page={$return_page}&id={$app_id}';</script>"; exit;
    }

    // -- Checker alters a KRA score ---------------------------
    if ($action === 'alter_score' && in_array($app['status'], ['under_review','talisay_review','needs_revision'])
        && (isChecker() || isTalisayChecker() || isAdmin())) {
        $sub_id    = intval($_POST['submission_id'] ?? 0);
        $new_pts   = floatval($_POST['new_points'] ?? 0);
        $alter_note = trim($_POST['alter_note'] ?? '');
        if ($sub_id && $new_pts >= 0) {
            // Fetch old value for audit
            $old = $pdo->prepare("SELECT computed_points, kra_category FROM kra_submissions WHERE submission_id=? AND application_id=?");
            $old->execute([$sub_id, $app_id]);
            $old = $old->fetch();
            if ($old) {
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
                $checker_name = $_SESSION['checker_label'] ?? "Checker #{$checker_id}";
                logAudit($pdo, $app['user_id'], 'Score Adjusted by Checker', "{$old['kra_category']} score adjusted from {$old['computed_points']} to {$new_pts} by {$checker_name}." . ($alter_note ? " Note: {$alter_note}" : ''));

                // Notify faculty of score adjustment
                createNotif($pdo, $app['user_id'], 'score_adjusted',
                    "{$old['kra_category']} score adjusted from {$old['computed_points']} to {$new_pts} by {$checker_name}." . ($alter_note ? " Note: {$alter_note}" : ''),
                    $app_id);

                // Recalculate weighted score
                recalcApplicationScore($pdo, $app_id);

                // Check if weighted score dropped below 41 — auto-return if so
                $new_app = $pdo->prepare("SELECT weighted_score FROM applications WHERE application_id=?");
                $new_app->execute([$app_id]);
                $new_weighted = (float)$new_app->fetchColumn();

                if ($new_weighted < 41) {
                    // Return application to faculty automatically
                    $auto_remark = "Application automatically returned: weighted score dropped to {$new_weighted} (below minimum 41) after checker adjusted {$old['kra_category']} score from {$old['computed_points']} to {$new_pts}.";
                    $pdo->prepare("UPDATE applications SET status='rejected', reviewed_at=NOW(), checker_remarks=? WHERE application_id=?")
                        ->execute([$auto_remark, $app_id]);
                    logAudit($pdo, $checker_id, 'Auto-Returned', "Application #{$app_id} auto-returned — weighted score {$new_weighted} fell below 41 after score adjustment.");
                    logAudit($pdo, $app['user_id'], 'Application Returned', "Your application was automatically returned because the weighted score ({$new_weighted}) fell below the minimum of 41 after a score adjustment by {$checker_name}.");
                    createNotif($pdo, $app['user_id'], 'rejected',
                        "Your application has been automatically returned. The weighted score ({$new_weighted}) fell below the minimum of 41 after a score adjustment. Please update your KRA entries and resubmit.",
                        $app_id);
                    flashMessage('warning', "Score adjusted. The resulting weighted score (<strong>{$new_weighted}</strong>) is below the minimum of 41 — the application has been <strong>automatically returned</strong> to the faculty.");

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
        // AJAX call — return JSON, no redirect
        if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && $_SERVER['HTTP_X_REQUESTED_WITH'] === 'XMLHttpRequest') {
            header('Content-Type: application/json');
            echo json_encode(['ok' => true]);
            exit;
        }
        echo "<script>window.location.href='index.php?page={$return_page}&id={$app_id}&score_saved=1#kra-verification';</script>"; exit;
    }

    // Request revision on a specific KRA entry &mdash; requires review already started
    if ($action === 'request_revision' && in_array($app['status'], ['under_review','needs_revision'])) {
        $sub_id   = intval($_POST['submission_id'] ?? 0);
        $rev_note = trim($_POST['revision_note'] ?? '');
        if ($sub_id && $rev_note) {
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
            $rev_cat_row = $pdo->prepare("SELECT kra_category FROM kra_submissions WHERE submission_id=?");
            $rev_cat_row->execute([$sub_id]);
            $rev_cat = $rev_cat_row->fetchColumn() ?: 'KRA';
            logAudit($pdo, $checker_id, 'Revision Requested', "You requested a revision on {$app['full_name']}'s {$rev_cat} entry. Note: {$rev_note}");
            // Notify faculty to fix flagged KRA entry — deep-links to that entry's KRA tab
            createNotif($pdo, $app['user_id'], 'needs_revision',
                'A checker has flagged a KRA entry for revision. Please review and resubmit.',
                $app_id, $sub_id);
            flashMessage('warning', 'Revision requested. Faculty can now edit only the flagged entry and resubmit.');
            echo "<script>window.location.href='index.php?page={$return_page}&id={$app_id}';</script>"; exit;
        }
    }

    // Clear a revision flag — only allowed after faculty resubmits (status back to under_review)
    if ($action === 'clear_revision' && in_array($app['status'], ['submitted','under_review'])) {
        $sub_id = intval($_POST['submission_id'] ?? 0);
        if ($sub_id) {
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
        && (isChecker() || isTalisayChecker() || isAdmin())) {
        $sub_id = intval($_POST['submission_id'] ?? 0);
        $verify_note = trim($_POST['note'] ?? '');
        if ($sub_id) {
            $row = $pdo->prepare("SELECT kra_category FROM kra_submissions WHERE submission_id=? AND application_id=?");
            $row->execute([$sub_id, $app_id]);
            $row = $row->fetch();
            if ($row) {
                // Record this checker's individual verification
                $pdo->prepare("INSERT IGNORE INTO kra_checker_verifications (submission_id, checker_id) VALUES (?,?)")
                    ->execute([$sub_id, $checker_id]);
                // Stamp faculty_original_score if not yet set
                $pdo->prepare("UPDATE kra_submissions SET faculty_original_score = COALESCE(faculty_original_score, computed_points) WHERE submission_id=?")
                    ->execute([$sub_id]);
                // Save the checker's note (same field alter_score writes to)
                $pdo->prepare("UPDATE kra_submissions SET checker_note=? WHERE submission_id=?")
                    ->execute([$verify_note ?: null, $sub_id]);
                // If all active checkers have verified, set the global verified=1
                $total_ck = (int)$pdo->query("SELECT COUNT(*) FROM users WHERE role = 'checker' AND status='active'")->fetchColumn();
                // Simpler: just count directly
                $done_stmt = $pdo->prepare("SELECT COUNT(*) FROM kra_checker_verifications WHERE submission_id=?");
                $done_stmt->execute([$sub_id]);
                $done_ck = (int)$done_stmt->fetchColumn();
                if ($total_ck > 0 && $done_ck >= $total_ck) {
                    $pdo->prepare("UPDATE kra_submissions SET verified=1, verified_by=?, verified_at=NOW() WHERE submission_id=?")
                        ->execute([$checker_id, $sub_id]);
                }
                logAudit($pdo, $checker_id, 'Evidence Verified', "You verified {$app['full_name']}'s {$row['kra_category']} entry." . ($verify_note ? " Note: {$verify_note}" : ''));
            }
        }
        if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && $_SERVER['HTTP_X_REQUESTED_WITH'] === 'XMLHttpRequest') {
            header('Content-Type: application/json');
            echo json_encode(['ok' => true]);
            exit;
        }
        echo "<script>window.location.href='index.php?page={$return_page}&id={$app_id}#kra-verification';</script>"; exit;
    }

    // -- Checker approves -------------------------------------
    if ($action === 'checker_approve' && in_array($app['status'], ['submitted','under_review'])) {

        // Block approval if any KRA submission is unverified
        $unverified = $pdo->prepare("SELECT COUNT(*) FROM kra_submissions WHERE application_id = ? AND verified = 0");
        $unverified->execute([$app_id]);
        if ((int)$unverified->fetchColumn() > 0) {
            flashMessage('danger', 'You must verify <strong>all evidence files</strong> before approving this application. Please review and verify each KRA entry first.');
            echo "<script>window.location.href='index.php?page={$return_page}&id={$app_id}';</script>"; exit;
        }

        // Block approval if weighted score is below 41
        $ws_check = $pdo->prepare("SELECT weighted_score FROM applications WHERE application_id=?");
        $ws_check->execute([$app_id]);
        $current_weighted = (float)$ws_check->fetchColumn();
        if ($current_weighted < 41) {
            flashMessage('danger', "This application cannot be approved — the weighted score (<strong>{$current_weighted}</strong>) is below the minimum of <strong>41</strong> required for reclassification. Please return it to the faculty for revision.");
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
        $total_checkers = countActiveCheckers($pdo);
        $approved_count = countApprovedCheckers($pdo, $app_id);
        logAudit($pdo, $checker_id, 'Checker Approved', "You approved {$app['full_name']}'s application. ({$approved_count}/{$total_checkers} approvals)");

        if ($approved_count >= $total_checkers) {
            // Check if there are Talisay checkers — if so, route to talisay_review
            $talisay_count = countActiveTalisayCheckers($pdo);
            if ($talisay_count > 0) {
                $pdo->prepare("UPDATE applications SET status='talisay_review', reviewed_at=NOW(),
                    checker_remarks=? WHERE application_id=?")
                    ->execute(["Approved by all {$total_checkers} checker(s). Awaiting Talisay (main) review.", $app_id]);
                logAudit($pdo, $checker_id, 'Forwarded to Talisay Review', "You approved {$app['full_name']}'s application — forwarded to Talisay after all {$total_checkers} campus checker(s) approved.");
                // Notify faculty
                createNotif($pdo, $app['user_id'], 'talisay_review',
                    'Your application has been approved by all campus checkers and forwarded to the Talisay (Main) campus for final review.',
                    $app_id);
                // Notify all active talisay checkers
                $tc_list = $pdo->query("SELECT user_id FROM users WHERE role='talisay_checker' AND status='active'")->fetchAll(PDO::FETCH_COLUMN);
                foreach ($tc_list as $tc_uid) {
                    createNotif($pdo, (int)$tc_uid, 'new_talisay_submission',
                        "Application from {$app['full_name']} has been forwarded to Talisay for final review.",
                        $app_id);
                }
                flashMessage('success', "All {$total_checkers} checker(s) have approved. Application has been forwarded to <strong>Talisay (Main) Checkers</strong> for final review.");
            } else {
                $pdo->prepare("UPDATE applications SET status='approved', reviewed_at=NOW(),
                    checker_remarks=? WHERE application_id=?")
                    ->execute(["Approved by all {$total_checkers} checker(s).", $app_id]);
                logAudit($pdo, $checker_id, 'Application Approved', "You approved {$app['full_name']}'s application — all {$total_checkers} campus checker(s) have approved.");
                // Notify faculty of final approval
                createNotif($pdo, $app['user_id'], 'approved',
                    'Congratulations! Your application has been approved by all campus checkers.',
                    $app_id);
                flashMessage('success', "Your approval has been recorded. All {$total_checkers} checker(s) have approved &mdash; the application is now <strong>approved</strong>.");
            }
        } else {
            $remaining = $total_checkers - $approved_count;
            flashMessage('success', "Your approval has been recorded ({$approved_count}/{$total_checkers} checkers). Waiting for {$remaining} more checker(s).");
        }
        echo "<script>window.location.href='index.php?page={$return_page}&id={$app_id}';</script>"; exit;
    }

    // -- Talisay checker approves ------------------------------
    if ($action === 'talisay_approve' && $app['status'] === 'talisay_review' && isTalisayChecker()) {

        // Block if weighted score is below 41
        $ws_tal = $pdo->prepare("SELECT weighted_score FROM applications WHERE application_id=?");
        $ws_tal->execute([$app_id]);
        $tal_weighted = (float)$ws_tal->fetchColumn();
        if ($tal_weighted < 41) {
            flashMessage('danger', "This application cannot be approved — the weighted score (<strong>{$tal_weighted}</strong>) is below the minimum of <strong>41</strong>. Please return it to the faculty.");
            echo "<script>window.location.href='index.php?page={$return_page}&id={$app_id}';</script>"; exit;
        }
        $pdo->prepare("INSERT INTO application_checker_reviews
            (application_id, checker_id, decision, remarks, decided_at)
            VALUES (?, ?, 'approved', ?, NOW())
            ON DUPLICATE KEY UPDATE decision='approved', remarks=VALUES(remarks), decided_at=NOW()")
            ->execute([$app_id, $checker_id, $remarks]);

        $total_talisay = countActiveTalisayCheckers($pdo);
        $ta_stmt = $pdo->prepare("SELECT COUNT(*) FROM application_checker_reviews r
            JOIN users u ON r.checker_id=u.user_id
            WHERE r.application_id=? AND r.decision='approved' AND u.role='talisay_checker'");
        $ta_stmt->execute([$app_id]);
        $talisay_approved = (int)$ta_stmt->fetchColumn();

        logAudit($pdo, $checker_id, 'Talisay Checker Approved', "You approved {$app['full_name']}'s application. ({$talisay_approved}/{$total_talisay} Talisay approvals)");

        if ($talisay_approved >= $total_talisay) {
            $pdo->prepare("UPDATE applications SET status='approved', reviewed_at=NOW(),
                checker_remarks=? WHERE application_id=?")
                ->execute(["Approved by all Talisay checker(s).", $app_id]);
            logAudit($pdo, $checker_id, 'Application Approved', "You fully approved {$app['full_name']}'s application after Talisay review.");
            // Notify faculty of final approval
            createNotif($pdo, $app['user_id'], 'approved',
                'Congratulations! Your application has been fully approved by the Talisay (Main) campus checkers.',
                $app_id);
            flashMessage('success', "All Talisay checkers have approved. Application is now <strong>fully approved</strong>.");
        } else {
            flashMessage('success', "Your approval recorded ({$talisay_approved}/{$total_talisay} Talisay checkers). Waiting for remaining approvals.");
        }
        echo "<script>window.location.href='index.php?page={$return_page}&id={$app_id}';</script>"; exit;
    }

    // -- Talisay checker rejects -------------------------------
    if ($action === 'talisay_reject' && $app['status'] === 'talisay_review' && isTalisayChecker()) {
        if (empty($remarks)) {
            flashMessage('danger', 'A reason is required when rejecting.');
            echo "<script>window.location.href='index.php?page={$return_page}&id={$app_id}';</script>"; exit;
        }
        $pdo->prepare("INSERT INTO application_checker_reviews
            (application_id, checker_id, decision, remarks, decided_at)
            VALUES (?, ?, 'rejected', ?, NOW())
            ON DUPLICATE KEY UPDATE decision='rejected', remarks=VALUES(remarks), decided_at=NOW()")
            ->execute([$app_id, $checker_id, $remarks]);
        $pdo->prepare("UPDATE applications SET status='rejected', reviewed_at=NOW(), checker_remarks=? WHERE application_id=?")
            ->execute(["Rejected by Talisay checker: {$remarks}", $app_id]);
        logAudit($pdo, $checker_id, 'Talisay Checker Rejected', "You returned {$app['full_name']}'s application. Reason: {$remarks}");
        // Notify faculty
        createNotif($pdo, $app['user_id'], 'rejected',
            "Your application has been returned by the Talisay checker. Reason: {$remarks}",
            $app_id);
        flashMessage('warning', 'Application has been returned to the faculty.');
        echo "<script>window.location.href='index.php?page={$return_page}&id={$app_id}';</script>"; exit;
    }

    // -- Checker rejects ---------------------------------------
    if ($action === 'checker_reject' && in_array($app['status'], ['submitted','under_review'])) {
        if (empty($remarks)) {
            flashMessage('danger', 'A reason is required when rejecting.');
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

        $total_checkers = countActiveCheckers($pdo);
        $rejected_count = countRejectedCheckers($pdo, $app_id);
        logAudit($pdo, $checker_id, 'Checker Rejected', "You returned {$app['full_name']}'s application. ({$rejected_count}/{$total_checkers} rejections) Reason: {$remarks}");

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
                ->execute(["Rejected by all {$total_checkers} checker(s). {$combined}", $app_id]);
            logAudit($pdo, $checker_id, 'Application Rejected', "You returned {$app['full_name']}'s application — all {$total_checkers} checker(s) have rejected.");
            // Notify faculty of rejection
            createNotif($pdo, $app['user_id'], 'rejected',
                "Your application has been returned by all campus checkers. Please review the remarks and make corrections.",
                $app_id);
            flashMessage('warning', "Your rejection has been recorded. All {$total_checkers} checker(s) have rejected &mdash; the application has been <strong>returned to the faculty</strong>.");
        } else {
            flashMessage('warning', "Your rejection has been recorded ({$rejected_count}/{$total_checkers} rejections so far). The application stays <strong>Under Review</strong> until all checkers have decided.");
        }
        echo "<script>window.location.href='index.php?page={$return_page}&id={$app_id}';</script>"; exit;
    }

    // -- Admin reject only -------------------------------------
    if ($action === 'admin_reject' && isAdmin() && $app['status'] === 'approved') {
        $admin_remarks = trim($_POST['admin_remarks'] ?? '');
        $pdo->prepare("UPDATE applications SET status='admin_rejected', checker_remarks=?, reviewed_at=NOW()
            WHERE application_id=?")
            ->execute([$admin_remarks ?: 'Rejected by admin.', $app_id]);
        logAudit($pdo, $_SESSION['user_id'], 'Application Rejected by Admin', "You rejected {$app['full_name']}'s application (final decision). Remarks: {$admin_remarks}");
        flashMessage('warning', "Application has been <strong>rejected</strong>. This is a final decision.");
        echo "<script>window.location.href='index.php?page=all_applications&filter=approved';</script>"; exit;
    }

    // ── Auto Sub-Rank: checker verifies or rejects evidence ───────────────
    if ($action === 'verify_asr_evidence'
        && in_array($app['status'], ['under_review','talisay_review','needs_revision','submitted'])
        && (isChecker() || isTalisayChecker() || isAdmin())) {

        if (!class_exists('\Scoring\AutoSubRankCalculator')) {
            require_once __DIR__ . '/../../includes/scoring/autosubrank.php';
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
                ? "Your {$crit_label} evidence has been verified by a checker."
                : "Your {$crit_label} evidence was rejected by a checker. Reason: " . ($notes ?: 'No reason given') . ". Please check your Auto Sub-Rank tab.";
            createNotif($pdo, $app['user_id'], $notif_type, $notif_msg, $app_id);

            if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && $_SERVER['HTTP_X_REQUESTED_WITH'] === 'XMLHttpRequest') {
                header('Content-Type: application/json');
                echo json_encode(['ok' => true, 'decision' => $decision, 'criterion' => $criterion]);
                exit;
            }

            $verb = $decision === 'verified' ? 'verified' : 'rejected';
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
$raw_kra        = [];
foreach ($subs as $s) $raw_kra[$s['kra_category']] = ($raw_kra[$s['kra_category']] ?? 0) + (float)$s['computed_points'];
$score_result   = computeWeightedScore($raw_kra, $faculty_rank);
$potential_data = computePotentialRank($raw_kra, $faculty_rank);

// -- Multi-checker state ---------------------------------------
$checker_reviews   = getCheckerReviews($pdo, $app_id);
$total_checkers    = countActiveCheckers($pdo);
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
// No join needed &mdash; every active checker can decide directly
$can_join          = false; // removed
$can_act_checker   = !isAdmin() && !$i_decided && in_array($app['status'], ['submitted','under_review']);
// Review is "started" once app status moves to under_review or needs_revision (set by start_review action)
$i_started_review  = in_array($app['status'], ['under_review','needs_revision','talisay_review']);
// Revision/verify only allowed AFTER review has been started — not while status is still 'submitted'
$can_act_revision  = !isAdmin() && !$i_decided && $i_started_review;
$auto_revision_notice = 'One or more KRA entries need revision. Please check the highlighted entries and resubmit.';
$visible_checker_remarks = trim((string)($app['checker_remarks'] ?? ''));
if ($visible_checker_remarks === $auto_revision_notice) {
    $visible_checker_remarks = '';
}

?>
<?php showFlash(); ?>

<!-- -- Page header --------------------------------------------- -->
<div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:0.75rem;margin-bottom:1.25rem;padding-bottom:1.25rem;border-bottom:1px solid #f1f5f9;">

    <!-- Left: back + title -->
    <div style="display:flex;align-items:center;gap:0.75rem;min-width:0;">
        <a href="?page=<?= isAdmin() ? 'all_applications' : 'review_queue' ?>"
           style="display:inline-flex;align-items:center;gap:0.3rem;padding:0.35rem 0.75rem;border-radius:6px;border:1px solid #e2e8f0;background:#fff;color:#475569;font-size:0.75rem;font-weight:600;text-decoration:none;flex-shrink:0;"
           onmouseover="this.style.borderColor='#1a3a6b';this.style.color='#1a3a6b'"
           onmouseout="this.style.borderColor='#e2e8f0';this.style.color='#475569'">
            <i class="bi bi-arrow-left"></i><?= isAdmin() ? 'All Applications' : 'Queue' ?>
        </a>
        <div style="min-width:0;">
            <div style="font-size:0.72rem;color:#94a3b8;font-weight:500;text-transform:uppercase;letter-spacing:0.05em;margin-bottom:1px;">Reviewing Application</div>
            <div style="font-size:0.95rem;font-weight:700;color:#0f172a;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;"><?= htmlspecialchars(formatDisplayName($app)) ?></div>
        </div>
    </div>

    <!-- Right: status + route + print -->
    <div style="display:flex;align-items:center;gap:0.6rem;flex-wrap:wrap;flex-shrink:0;">

        <?= statusBadge($app['status']) ?>

        <?php
        $committee = $app['committee_route'] ?? null;
        if ($committee):
            $cmtColors = ['IEC'=>'#475569','REC'=>'#1a3a6b','EAC'=>'#c2410c','CC'=>'#7c3aed'];
            $cmtBg     = ['IEC'=>'#f8fafc','REC'=>'#f0f4fb','EAC'=>'#fff7ed','CC'=>'#f5f3ff'];
            $cmtBorder = ['IEC'=>'#e2e8f0','REC'=>'#dbeafe','EAC'=>'#fed7aa','CC'=>'#ddd6fe'];
            $cmtLabel  = ['IEC'=>'Campus Committee','REC'=>'Regional Committee','EAC'=>'Evaluation & Accreditation','CC'=>'Confirming Committee'];
            $cmtColor  = $cmtColors[$committee] ?? '#475569';
            $cmtBgCol  = $cmtBg[$committee]     ?? '#f8fafc';
            $cmtBdCol  = $cmtBorder[$committee] ?? '#e2e8f0';
            $cmtFull   = $cmtLabel[$committee]  ?? $committee;
        ?>
        <span style="display:inline-flex;align-items:center;gap:0.3rem;padding:3px 10px;border-radius:20px;
                     background:<?= $cmtBgCol ?>;color:<?= $cmtColor ?>;border:1px solid <?= $cmtBdCol ?>;
                     font-size:0.68rem;font-weight:700;white-space:nowrap;"
              title="<?= htmlspecialchars($cmtFull) ?> — routing per JC01 s.2026">
            <i class="bi bi-diagram-3" style="font-size:0.65rem;"></i><?= htmlspecialchars($committee) ?>
        </span>
        <?php endif; ?>

        <a href="pages/kra_pdf.php?uid=<?= $app['user_id'] ?>&cycle_id=<?= $app['cycle_id'] ?>"
           target="_blank"
           style="display:inline-flex;align-items:center;gap:0.3rem;padding:0.35rem 0.85rem;border-radius:6px;border:1px solid #e2e8f0;background:#fff;color:#1a3a6b;font-size:0.75rem;font-weight:600;text-decoration:none;"
           onmouseover="this.style.background='#f0f4fb';this.style.borderColor='#1a3a6b'"
           onmouseout="this.style.background='#fff';this.style.borderColor='#e2e8f0'">
            <i class="bi bi-printer"></i>Print ISS
        </a>

    </div>
</div>

<?php
// ── JC01 s.2026 flags panel ──────────────────────────────────────────────
$double_flags  = json_decode($app['double_counting_flags'] ?? '[]', true) ?: [];
$pending_docs  = json_decode($app['pending_documentation'] ?? '[]', true) ?: [];
$config_incomp = json_decode($app['config_incomplete'] ?? '[]', true) ?: [];
$orch_flags    = json_decode($app['orchestrator_flags'] ?? '[]', true) ?: [];

$eval_ok       = isset($app['evaluation_period_ok']) ? (bool)$app['evaluation_period_ok'] : true;

$has_flags = !$eval_ok || !empty($double_flags) || !empty($pending_docs) || !empty($config_incomp) || !empty($orch_flags);
if ($has_flags):
?>
<div class="neon-card mb-3" style="padding:0;overflow:hidden;border-left:4px solid #c2410c;">
    <div style="padding:0.7rem 1rem;background:#fff7ed;border-bottom:1px solid #fed7aa;display:flex;align-items:center;gap:0.5rem;">
        <i class="bi bi-exclamation-triangle-fill" style="color:#c2410c;"></i>
        <span style="font-weight:700;color:#c2410c;font-size:0.88rem;">JC01 s.2026 Scoring Flags — Require Checker Review</span>
        <span style="margin-left:auto;font-size:0.72rem;color:#9a3412;">Human committee makes all binding decisions</span>
    </div>
    <div style="padding:0.85rem 1rem;">
        <?php if (!$eval_ok): ?>
        <div style="margin-bottom:0.6rem;padding:0.5rem 0.75rem;background:#fef2f2;border:1px solid #fecaca;border-radius:6px;font-size:0.8rem;color:#dc2626;">
            <i class="bi bi-calendar-x me-1"></i><strong>Evaluation Period:</strong> Application is linked to an archived cycle.
        </div>
        <?php endif; ?>
        <?php foreach ($orch_flags as $f): ?>
        <div style="margin-bottom:0.4rem;padding:0.4rem 0.75rem;background:#fef2f2;border:1px solid #fecaca;border-radius:6px;font-size:0.78rem;color:#b91c1c;">
            <i class="bi bi-shield-x me-1"></i><?= htmlspecialchars($f) ?>
        </div>
        <?php endforeach; ?>
        <?php foreach ($double_flags as $f): ?>
        <div style="margin-bottom:0.4rem;padding:0.4rem 0.75rem;background:#fff7ed;border:1px solid #fed7aa;border-radius:6px;font-size:0.78rem;color:#c2410c;">
            <i class="bi bi-copy me-1"></i><strong>Double-Counting:</strong> "<?= htmlspecialchars($f['title'] ?? '') ?>" appears in <?= htmlspecialchars(implode(' + ', $f['categories'] ?? [])) ?>
        </div>
        <?php endforeach; ?>
        <?php if (!empty($pending_docs)): ?>
        <div style="margin-top:0.5rem;">
            <div style="font-size:0.75rem;font-weight:700;color:#475569;text-transform:uppercase;letter-spacing:0.04em;margin-bottom:0.35rem;"><i class="bi bi-file-earmark-x me-1"></i>Pending Documentation (<?= count($pending_docs) ?>)</div>
            <?php foreach (array_slice($pending_docs, 0, 8) as $pd): ?>
            <div style="padding:0.3rem 0.6rem;font-size:0.76rem;color:#64748b;border-left:2px solid #fcd34d;margin-bottom:0.2rem;background:#fffbeb;">
                <?= htmlspecialchars($pd) ?>
            </div>
            <?php endforeach; ?>
            <?php if (count($pending_docs) > 8): ?>
            <div style="font-size:0.72rem;color:#94a3b8;margin-top:0.2rem;">+ <?= count($pending_docs) - 8 ?> more pending items…</div>
            <?php endif; ?>
        </div>
        <?php endif; ?>
        <?php if (!empty($config_incomp)): ?>
        <div style="margin-top:0.5rem;">
            <div style="font-size:0.75rem;font-weight:700;color:#7c3aed;text-transform:uppercase;letter-spacing:0.04em;margin-bottom:0.35rem;"><i class="bi bi-wrench me-1"></i>Config Incomplete (<?= count($config_incomp) ?>)</div>
            <?php foreach (array_slice($config_incomp, 0, 5) as $ci): ?>
            <div style="padding:0.3rem 0.6rem;font-size:0.76rem;color:#6d28d9;border-left:2px solid #c4b5fd;margin-bottom:0.2rem;background:#f5f3ff;">
                <?= htmlspecialchars($ci) ?>
            </div>
            <?php endforeach; ?>
            <?php if (count($config_incomp) > 5): ?>
            <div style="font-size:0.72rem;color:#94a3b8;margin-top:0.2rem;">+ <?= count($config_incomp) - 5 ?> more config items…</div>
            <?php endif; ?>
        </div>
        <?php endif; ?>
    </div>
</div>
<?php endif; ?>

<!-- -- Checker approval progress ------------------------------ -->
<?php if (!isAdmin()): ?>
<div class="neon-card mb-3" style="padding:1.1rem 1.5rem;">
    <div class="d-flex justify-content-between align-items-start mb-2 flex-wrap gap-2">
        <div>
            <span class="fw-bold" style="color:#1a3a6b;font-size:0.9rem;">
                <i class="bi bi-people-fill me-2"></i>Checker Decisions
            </span>
            <span class="text-muted small ms-2"><?= $total_checkers ?> checker(s) required to finalise</span>
        </div>
        <div class="d-flex gap-2 flex-wrap">
            <?php if ($approved_count > 0): ?>
            <span class="badge bg-success" style="font-size:0.78rem;white-space:nowrap;"><?= $approved_count ?>/<?= $total_checkers ?> approved</span>
            <?php endif; ?>
            <?php if ($rejected_count > 0): ?>
            <span class="badge bg-danger" style="font-size:0.78rem;white-space:nowrap;"><?= $rejected_count ?>/<?= $total_checkers ?> rejected</span>
            <?php endif; ?>
            <?php if ($approved_count === 0 && $rejected_count === 0): ?>
            <span class="badge bg-secondary" style="font-size:0.78rem;white-space:nowrap;">0/<?= $total_checkers ?> decided</span>
            <?php endif; ?>
        </div>
    </div>
    <!-- Progress bar &mdash; green for approvals, red for rejections -->
    <div style="height:8px;background:#e2e8f0;border-radius:99px;margin-bottom:1rem;overflow:hidden;display:flex;">
        <div style="height:100%;width:<?= $total_checkers > 0 ? min(100, round(($approved_count / $total_checkers) * 100)) : 0 ?>%;
                    background:#1a3a6b;border-radius:99px 0 0 99px;transition:width 0.4s ease;"></div>
        <div style="height:100%;width:<?= $total_checkers > 0 ? min(100, round(($rejected_count / $total_checkers) * 100)) : 0 ?>%;
                    background:#334155;transition:width 0.4s ease;"></div>
    </div>
    <!-- All active checkers and their decisions -->
    <?php
    // Get all active checkers with their decision for this application
    $all_checkers_stmt = $pdo->prepare("
        SELECT u.user_id, u.checker_label,
               r.decision, r.remarks, r.decided_at
        FROM users u
        LEFT JOIN application_checker_reviews r
            ON r.application_id = ? AND r.checker_id = u.user_id
        WHERE u.role = 'checker' AND u.status = 'active'
        ORDER BY u.user_id ASC
    ");
    $all_checkers_stmt->execute([$app_id]);
    $all_checkers_list = $all_checkers_stmt->fetchAll();
    $slot_colors = ['#1a3a6b','#1a3a6b','#1a3a6b','#1a3a6b','#475569','#334155'];
    ?>
    <div class="d-flex flex-wrap gap-2">
        <?php foreach ($all_checkers_list as $idx => $chk):
            $is_me        = (int)$chk['user_id'] === $my_checker_id;
            $dec          = $chk['decision'] ?? null;
            $border_color = $dec === 'approved' ? '#1a3a6b' : ($dec === 'rejected' ? '#334155' : '#e2e8f0');
            $bg_color     = $dec === 'approved' ? '#eff6ff' : ($dec === 'rejected' ? '#f8fafc' : '#f8fafc');
            $dec_color    = $dec === 'approved' ? '#1a3a6b' : ($dec === 'rejected' ? '#334155' : '#94a3b8');
            $dec_icon     = $dec === 'approved' ? 'check-circle-fill' : ($dec === 'rejected' ? 'x-circle-fill' : 'hourglass');
            $dec_label    = $dec === 'approved' ? 'Approved' : ($dec === 'rejected' ? 'Rejected' : 'Pending');
        ?>
        <div style="flex:1;min-width:130px;max-width:200px;padding:0.65rem 0.85rem;border-radius:8px;
                    border:2px solid <?= $border_color ?>;background:<?= $bg_color ?>;">
            <div class="d-flex align-items-center gap-2 mb-1">
                <div style="width:26px;height:26px;border-radius:50%;
                            background:<?= $slot_colors[$idx % count($slot_colors)] ?>;
                            display:flex;align-items:center;justify-content:center;flex-shrink:0;">
                    <span style="color:#fff;font-size:0.68rem;font-weight:700;">
                        <i class="bi bi-person-fill"></i>
                    </span>
                </div>
                <div style="min-width:0;">
                    <div style="font-size:0.75rem;font-weight:600;color:#1e293b;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">
                        <?= htmlspecialchars(checkerDisplayLabel($chk)) ?><?= $is_me ? ' <span style="color:#64748b;font-weight:400;font-size:0.65rem;">(you)</span>' : '' ?>
                    </div>
                </div>
            </div>
            <span style="font-size:0.67rem;font-weight:600;color:<?= $dec_color ?>;">
                <i class="bi bi-<?= $dec_icon ?> me-1"></i><?= $dec_label ?>
            </span>
            <?php if ($chk['decided_at']): ?>
            <div style="font-size:0.6rem;color:#94a3b8;margin-top:1px;"><?= date('M d, Y', strtotime($chk['decided_at'])) ?></div>
            <?php endif; ?>
            <?php if ($dec === 'rejected' && !empty($chk['remarks'])): ?>
            <div style="font-size:0.63rem;color:#1a3a6b;margin-top:2px;font-style:italic;word-break:break-word;">
                "<?= sanitize($chk['remarks']) ?>"
            </div>
            <?php endif; ?>
        </div>
        <?php endforeach; ?>
    </div>
</div>
<?php endif; ?>

<!-- -- Faculty Info ------------------------------------------ -->
<div class="neon-card mb-3">
    <div class="row g-3">
        <div class="col-md-3"><span class="text-muted small d-block">Full Name</span><strong><?= htmlspecialchars(formatDisplayName($app)) ?></strong></div>
        <div class="col-md-3"><span class="text-muted small d-block">Employee ID</span><strong><?= sanitize($app['employee_id'] ?? 'N/A') ?></strong></div>
        <div class="col-md-3"><span class="text-muted small d-block">Campus</span><strong><?= sanitize($app['campus_name'] ?? 'N/A') ?></strong></div>
        <div class="col-md-3"><span class="text-muted small d-block">Faculty Rank</span><strong><?= sanitize($app['rank'] ?? 'N/A') ?></strong></div>
        <div class="col-md-3"><span class="text-muted small d-block">Cycle</span><strong class="text-primary"><?= sanitize($app['cycle_name'] ?? 'N/A') ?></strong></div>
        <div class="col-md-3"><span class="text-muted small d-block">Weighted Score</span><strong style="color:#1a3a6b;font-size:1.3rem;"><?= number_format($score_result['weighted_score'], 2) ?></strong></div>
        <div class="col-md-3">
            <span class="text-muted small d-block">Sub-rank Increment</span>
            <?php $inc = $score_result['sub_rank_increment']; ?>
            <span class="badge <?= $inc > 0 ? 'bg-success' : 'bg-secondary' ?> fs-6">
                <?= $inc > 0 ? "+{$inc} sub-rank" . ($inc > 1 ? 's' : '') : 'No reclassification' ?>
            </span>
        </div>
        <div class="col-md-3"><span class="text-muted small d-block">Submitted</span><strong><?= $app['submitted_at'] ? date('M d, Y H:i', strtotime($app['submitted_at'])) : 'N/A' ?></strong></div>
        <?php if ($visible_checker_remarks !== ''): ?>
        <div class="col-12"><span class="text-muted small d-block">Remarks</span><span class="text-warning"><?= sanitize($visible_checker_remarks) ?></span></div>
        <?php endif; ?>
        <div class="col-12">
            <div class="p-3 rounded" style="background:#eff6ff;border:1px solid #a5d6a7;">
                <div class="d-flex align-items-center gap-3 flex-wrap">
                    <div>
                        <span class="text-muted small d-block">Potential Rank / Sub-rank</span>
                        <strong style="font-size:1.1rem;color:#1a3a6b;"><?= sanitize($potential_data['potential_rank']) ?></strong>
                        <?php if ($potential_data['crossed_category']): ?>
                        <span class="badge bg-warning text-dark ms-2" style="font-size:0.7rem;">Category crossed</span>
                        <?php endif; ?>
                    </div>
                    <?php if (!empty($potential_data['flags'])): ?>
                    <div class="d-flex flex-wrap gap-1">
                        <?php foreach ($potential_data['flags'] as $flag):
                            $fc = 'secondary';
                            if (str_contains($flag, 'CC'))      $fc = 'warning text-dark';
                            if (str_contains($flag, 'crossed')) $fc = 'info';
                            if (str_contains($flag, 'EAC'))     continue;
                        ?>
                        <span class="badge bg-<?= $fc ?>" style="font-size:0.72rem;">
                            <i class="bi bi-info-circle me-1"></i><?= sanitize($flag) ?>
                        </span>
                        <?php endforeach; ?>
                    </div>
                    <?php endif; ?>
                </div>
                <div class="mt-1" style="font-size:0.72rem;color:#64748b;">
                    <i class="bi bi-lock-fill me-1"></i>System-computed per DBM-CHED JC No. 3, s. 2022
                </div>
            </div>
        </div>
    </div>
</div>

<!-- -- Weighted Score Breakdown -------------------------------- -->
<?php if ($score_result['weighted_score'] < 41): ?>
<div class="alert mb-3 d-flex align-items-center gap-3"
     style="background:#f8fafc;border:2px solid #334155;border-radius:10px;padding:1rem 1.25rem;">
    <i class="bi bi-exclamation-triangle-fill" style="color:#334155;font-size:1.4rem;flex-shrink:0;"></i>
    <div>
        <div style="font-weight:700;color:#1e293b;font-size:0.95rem;">Below Minimum Weighted Score</div>
        <div style="color:#1e293b;font-size:0.85rem;margin-top:2px;">
            This application's weighted score is <strong><?= number_format($score_result['weighted_score'], 2) ?></strong>,
            which is below the minimum of <strong>41</strong> required for reclassification.
            It cannot be approved. Please return it to the faculty or adjust the scores accordingly.
        </div>
    </div>
</div>
<?php endif; ?>
<div class="neon-card mb-3">
    <h6 class="mb-3" style="color:var(--blue-dark);"><i class="bi bi-calculator me-2"></i>Weighted Score &mdash; <?= htmlspecialchars($faculty_rank ?: 'No rank set') ?></h6>
    <div class="table-responsive">
        <table style="width:100%;border-collapse:collapse;font-size:0.85rem;">
            <thead><tr style="background:#f1f5f9;">
                <th style="color:#1a3a6b;">KRA</th>
                <th class="text-center" style="color:#1a3a6b;">Raw Score</th>
                <th class="text-center" style="color:#1a3a6b;">Capped</th>
                <th class="text-center" style="color:#1a3a6b;">Weight</th>
                <th class="text-center" style="color:#1a3a6b;">Weighted</th>
            </tr></thead>
            <tbody>
            <?php
            $ck_labels  = ['Instruction'=>'KRA I - Instruction','Research'=>'KRA II - Research','Extension'=>'KRA III - Extension','Professional Development'=>'KRA IV - Prof. Development'];
            $ck_capped  = ['Instruction'=>$score_result['kra1'],'Research'=>$score_result['kra2'],'Extension'=>$score_result['kra3'],'Professional Development'=>$score_result['kra4']];
            $ck_weights = $score_result['weights'];
            foreach ($ck_labels as $key => $label):
                $raw  = $raw_kra[$key] ?? 0;
                $cap  = $ck_capped[$key];
                $w    = $ck_weights[$key];
                $wval = round($cap * $w, 2);
            ?>
            <tr>
                <td><?= $label ?></td>
                <td class="text-center"><?= number_format($raw, 2) ?></td>
                <td class="text-center"><?= number_format($cap, 2) ?></td>
                <td class="text-center"><?= ($w * 100) ?>%</td>
                <td class="text-center fw-bold"><?= number_format($wval, 2) ?></td>
            </tr>
            <?php endforeach; ?>
            </tbody>
            <tfoot>
                <tr style="background:#eff6ff;font-weight:700;">
                    <td colspan="4" style="color:#1a3a6b;">Final Weighted Score</td>
                    <td class="text-center" style="color:#1a3a6b;font-size:1rem;"><?= number_format($score_result['weighted_score'], 2) ?></td>
                </tr>
            </tfoot>
        </table>
    </div>
</div>

<!-- -- Auto Sub-Rank Verification ────────────────────────── -->
<div id="asr-verification" style="scroll-margin-top:80px;"></div>
<?php
// ── Load AutoSubRankCalculator ───────────────────────────────────────────
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
            <strong>Your role:</strong> Verify that each piece of evidence is <em>authentic</em>.
            The system has already determined the optimal strategy.
            Rejecting evidence will remove that criterion from the score entirely.
        </div>

        <!-- ── Criterion 1: Doctorate ───────────────────────────────── -->
        <div style="border:1px solid #e2e8f0;border-radius:8px;overflow:hidden;margin-bottom:0.85rem;" id="asr-doc-panel">
            <!-- Sub-header -->
            <div style="background:#f8fafc;border-bottom:1px solid #e2e8f0;padding:0.5rem 0.9rem;display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:0.4rem;">
                <span style="font-size:0.72rem;font-weight:700;color:#475569;text-transform:uppercase;letter-spacing:0.05em;">
                    <i class="bi bi-mortarboard me-1"></i>Criterion 1 — Doctorate Degree
                </span>
                <div style="display:flex;align-items:center;gap:0.4rem;flex-wrap:wrap;">
                    <!-- System decision (read-only) -->
                    <span style="background:<?= $ck_d_color ?>15;color:<?= $ck_d_color ?>;border:1px solid <?= $ck_d_color ?>40;padding:2px 8px;border-radius:20px;font-size:0.67rem;font-weight:700;">
                        <?= htmlspecialchars($ck_d_label) ?>
                    </span>
                    <!-- Current verification status -->
                    <span id="asr-doc-status-badge"
                          style="background:<?= $ck_dv_color ?>12;color:<?= $ck_dv_color ?>;border:1px solid <?= $ck_dv_color ?>40;padding:2px 8px;border-radius:20px;font-size:0.67rem;font-weight:700;">
                        <i class="bi bi-shield me-1"></i><?= ucfirst($ck_d_verified) ?>
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
                    <i class="bi bi-chat-left-text me-1"></i><strong>Verification note:</strong> <?= htmlspecialchars($asr_row_ck['doctorate_verification_notes']) ?>
                </div>
                <?php endif; ?>

                <?php if ($ck_can_verify): ?>
                <!-- Verify / Reject form -->
                <form id="asr-doc-form" method="POST"
                      style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:7px;padding:0.7rem 0.85rem;">
                    <input type="hidden" name="action"     value="verify_asr_evidence">
                    <input type="hidden" name="criterion"  value="doctorate">
                    <div style="font-size:0.72rem;font-weight:700;color:#475569;text-transform:uppercase;letter-spacing:0.05em;margin-bottom:0.5rem;">
                        <i class="bi bi-shield-check me-1"></i>Your Verification
                    </div>
                    <div style="display:flex;gap:0.5rem;flex-wrap:wrap;margin-bottom:0.5rem;">
                        <button type="button"
                                onclick="submitAsrVerify('asr-doc-form','verified','asr-doc-status-badge','#16a34a','Verified')"
                                style="flex:1;min-width:100px;padding:0.45rem 0.7rem;border-radius:6px;border:1.5px solid #16a34a;background:#f0fdf4;color:#16a34a;font-size:0.78rem;font-weight:700;cursor:pointer;display:flex;align-items:center;justify-content:center;gap:0.3rem;"
                                <?= $ck_d_verified === 'verified' ? 'style="opacity:0.5;" disabled' : '' ?>>
                            <i class="bi bi-check-circle-fill"></i>Verified — Evidence is Authentic
                        </button>
                        <button type="button"
                                onclick="submitAsrVerify('asr-doc-form','rejected','asr-doc-status-badge','#dc2626','Rejected')"
                                style="flex:1;min-width:100px;padding:0.45rem 0.7rem;border-radius:6px;border:1.5px solid #dc2626;background:#fef2f2;color:#dc2626;font-size:0.78rem;font-weight:700;cursor:pointer;display:flex;align-items:center;justify-content:center;gap:0.3rem;"
                                <?= $ck_d_verified === 'rejected' ? 'style="opacity:0.5;" disabled' : '' ?>>
                            <i class="bi bi-x-circle-fill"></i>Rejected — Evidence Invalid
                        </button>
                    </div>
                    <input type="hidden" name="verification" id="asr-doc-decision" value="">
                    <textarea name="verify_notes" id="asr-doc-notes"
                              placeholder="Optional: note your reason (required when rejecting)"
                              style="width:100%;border:1px solid #e2e8f0;border-radius:5px;padding:0.4rem 0.6rem;font-size:0.78rem;resize:vertical;min-height:52px;font-family:inherit;"
                              ><?= htmlspecialchars($asr_row_ck['doctorate_verification_notes'] ?? '') ?></textarea>
                </form>
                <?php else: ?>
                <div style="font-size:0.75rem;color:#94a3b8;font-style:italic;">
                    <i class="bi bi-lock me-1"></i>Verification locked — application is not under active review.
                </div>
                <?php endif; ?>

                <?php else: ?>
                <div style="font-size:0.82rem;color:#64748b;display:flex;align-items:center;gap:0.5rem;">
                    <i class="bi bi-dash-circle" style="color:#94a3b8;"></i>
                    No doctorate found in this application's Professional Development entries. Nothing to verify.
                </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- ── Criterion 2: Award ───────────────────────────────────── -->
        <div style="border:1px solid #e2e8f0;border-radius:8px;overflow:hidden;" id="asr-awd-panel">
            <div style="background:#f8fafc;border-bottom:1px solid #e2e8f0;padding:0.5rem 0.9rem;display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:0.4rem;">
                <span style="font-size:0.72rem;font-weight:700;color:#475569;text-transform:uppercase;letter-spacing:0.05em;">
                    <i class="bi bi-trophy me-1"></i>Criterion 2 — National / International Award
                </span>
                <div style="display:flex;align-items:center;gap:0.4rem;flex-wrap:wrap;">
                    <span style="background:<?= $ck_a_color ?>15;color:<?= $ck_a_color ?>;border:1px solid <?= $ck_a_color ?>40;padding:2px 8px;border-radius:20px;font-size:0.67rem;font-weight:700;">
                        <?= htmlspecialchars($ck_a_label) ?>
                    </span>
                    <?php if ($asr_checker_res['has_award']): ?>
                    <span id="asr-awd-status-badge"
                          style="background:<?= $ck_av_color ?>12;color:<?= $ck_av_color ?>;border:1px solid <?= $ck_av_color ?>40;padding:2px 8px;border-radius:20px;font-size:0.67rem;font-weight:700;">
                        <i class="bi bi-shield me-1"></i><?= ucfirst($ck_a_verified) ?>
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
                    <i class="bi bi-chat-left-text me-1"></i><strong>Verification note:</strong> <?= htmlspecialchars($asr_row_ck['award_verification_notes']) ?>
                </div>
                <?php endif; ?>

                <?php if ($ck_can_verify): ?>
                <form id="asr-awd-form" method="POST"
                      style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:7px;padding:0.7rem 0.85rem;">
                    <input type="hidden" name="action"     value="verify_asr_evidence">
                    <input type="hidden" name="criterion"  value="award">
                    <div style="font-size:0.72rem;font-weight:700;color:#475569;text-transform:uppercase;letter-spacing:0.05em;margin-bottom:0.5rem;">
                        <i class="bi bi-shield-check me-1"></i>Your Verification
                    </div>
                    <div style="display:flex;gap:0.5rem;flex-wrap:wrap;margin-bottom:0.5rem;">
                        <button type="button"
                                onclick="submitAsrVerify('asr-awd-form','verified','asr-awd-status-badge','#16a34a','Verified')"
                                style="flex:1;min-width:100px;padding:0.45rem 0.7rem;border-radius:6px;border:1.5px solid #16a34a;background:#f0fdf4;color:#16a34a;font-size:0.78rem;font-weight:700;cursor:pointer;display:flex;align-items:center;justify-content:center;gap:0.3rem;"
                                <?= $ck_a_verified === 'verified' ? 'disabled' : '' ?>>
                            <i class="bi bi-check-circle-fill"></i>Verified — Evidence is Authentic
                        </button>
                        <button type="button"
                                onclick="submitAsrVerify('asr-awd-form','rejected','asr-awd-status-badge','#dc2626','Rejected')"
                                style="flex:1;min-width:100px;padding:0.45rem 0.7rem;border-radius:6px;border:1.5px solid #dc2626;background:#fef2f2;color:#dc2626;font-size:0.78rem;font-weight:700;cursor:pointer;display:flex;align-items:center;justify-content:center;gap:0.3rem;"
                                <?= $ck_a_verified === 'rejected' ? 'disabled' : '' ?>>
                            <i class="bi bi-x-circle-fill"></i>Rejected — Evidence Invalid
                        </button>
                    </div>
                    <input type="hidden" name="verification" id="asr-awd-decision" value="">
                    <textarea name="verify_notes" id="asr-awd-notes"
                              placeholder="Optional: note your reason (required when rejecting)"
                              style="width:100%;border:1px solid #e2e8f0;border-radius:5px;padding:0.4rem 0.6rem;font-size:0.78rem;resize:vertical;min-height:52px;font-family:inherit;"
                              ><?= htmlspecialchars($asr_row_ck['award_verification_notes'] ?? '') ?></textarea>
                </form>
                <?php else: ?>
                <div style="font-size:0.75rem;color:#94a3b8;font-style:italic;">
                    <i class="bi bi-lock me-1"></i>Verification locked — application is not under active review.
                </div>
                <?php endif; ?>

                <?php else: ?>
                <div style="font-size:0.82rem;color:#64748b;display:flex;align-items:center;gap:0.5rem;">
                    <i class="bi bi-dash-circle" style="color:#94a3b8;"></i>
                    No national/international award found in this application. Nothing to verify.
                </div>
                <?php endif; ?>
            </div>
        </div>

    </div><!-- /card body -->
</div><!-- /asr neon-card -->

<script>
/**
 * submitAsrVerify — submit a verify/reject form via AJAX,
 * then update the status badge inline without full page reload.
 */
function submitAsrVerify(formId, decision, badgeId, color, label) {
    const form  = document.getElementById(formId);
    const badge = document.getElementById(badgeId);
    if (!form) return;

    // Inject the decision into the hidden input
    const decInput = form.querySelector('input[name="verification"]');
    if (decInput) decInput.value = decision;

    // Require a note when rejecting
    const notesEl = form.querySelector('textarea[name="verify_notes"]');
    if (decision === 'rejected' && notesEl && notesEl.value.trim() === '') {
        notesEl.style.borderColor = '#dc2626';
        notesEl.focus();
        notesEl.placeholder = 'Rejection reason is required.';
        return;
    }
    if (notesEl) notesEl.style.borderColor = '#e2e8f0';

    const fd = new FormData(form);
    fetch(window.location.href, { method: 'POST', body: fd,
        headers: { 'X-Requested-With': 'XMLHttpRequest' } })
    .then(r => r.json())
    .then(data => {
        if (data.ok && badge) {
            badge.style.background  = color + '12';
            badge.style.color       = color;
            badge.style.borderColor = color + '40';
            badge.innerHTML = '<i class="bi bi-shield me-1"></i>' + label;
        }
        // Disable both buttons in this form
        form.querySelectorAll('button[type="button"]').forEach(b => { b.disabled = true; b.style.opacity = '0.55'; });
    })
    .catch(() => { form.submit(); });
}
</script>

<!-- -- KRA Submissions --------------------------------------- -->
<div id="kra-verification" style="scroll-margin-top:80px;"></div>
<?php if (!empty($_GET['score_saved'])): ?>
<?php showFlash(); ?>
<?php endif; ?>
<div class="neon-card mb-3">
    <h6 style="color:#0f172a;font-weight:700;font-size:0.88rem;margin-bottom:1rem;">
        <i class="bi bi-list-check me-2" style="color:#1a3a6b;"></i>KRA Score Verification
    </h6>
    <?php if ($subs): ?>
    <?php
    // Per-checker verification state
    $my_verified_sids = [];
    if ($my_checker_id) {
        $my_v = $pdo->prepare("SELECT submission_id FROM kra_checker_verifications WHERE checker_id=?");
        $my_v->execute([$my_checker_id]);
        $my_verified_sids = array_column($my_v->fetchAll(PDO::FETCH_ASSOC), 'submission_id');
    }
    $total_active_checkers = max(1, (int)$pdo->query("SELECT COUNT(*) FROM users WHERE role = 'checker' AND status='active'")->fetchColumn());
    $verify_counts = [];
    if ($subs) {
        $sub_ids_v = array_column($subs, 'submission_id');
        $ph_v = implode(',', array_fill(0, count($sub_ids_v), '?'));
        $vc = $pdo->prepare("SELECT submission_id, COUNT(*) as cnt FROM kra_checker_verifications WHERE submission_id IN ($ph_v) GROUP BY submission_id");
        $vc->execute($sub_ids_v);
        foreach ($vc->fetchAll() as $vrow) $verify_counts[$vrow['submission_id']] = (int)$vrow['cnt'];
    }
    // Pre-compute KRA caps and weights for weighted column
    $kra_caps_rev    = ['Instruction'=>100,'Research'=>100,'Extension'=>100,'Professional Development'=>100];
    $kra_weights_rev = $score_result['weights'];
    // Sum raw per category for proportional distribution
    $kra_raw_totals = [];
    foreach ($subs as $s) {
        $kra_raw_totals[$s['kra_category']] = ($kra_raw_totals[$s['kra_category']] ?? 0) + (float)$s['computed_points'];
    }
    ?>
    <div class="table-responsive">
        <table style="width:100%;border-collapse:collapse;font-size:0.82rem;">
            <thead>
            <tr style="background:#f8fafc;border-bottom:2px solid #e2e8f0;">
                <th style="padding:0.6rem 0.85rem;text-align:left;font-size:0.65rem;font-weight:700;text-transform:uppercase;letter-spacing:0.06em;color:#64748b;white-space:nowrap;">KRA Category</th>
                <th style="padding:0.6rem 0.75rem;text-align:right;font-size:0.65rem;font-weight:700;text-transform:uppercase;letter-spacing:0.06em;color:#64748b;white-space:nowrap;">Raw Score</th>
                <th style="padding:0.6rem 0.75rem;text-align:right;font-size:0.65rem;font-weight:700;text-transform:uppercase;letter-spacing:0.06em;color:#64748b;white-space:nowrap;">Weighted</th>
                <th style="padding:0.6rem 0.75rem;text-align:left;font-size:0.65rem;font-weight:700;text-transform:uppercase;letter-spacing:0.06em;color:#64748b;">Remarks</th>
                <th style="padding:0.6rem 0.75rem;text-align:left;font-size:0.65rem;font-weight:700;text-transform:uppercase;letter-spacing:0.06em;color:#64748b;">Evidence</th>
                <?php if ($can_act_revision): ?>
                <th style="padding:0.6rem 0.75rem;text-align:center;font-size:0.65rem;font-weight:700;text-transform:uppercase;letter-spacing:0.06em;color:#64748b;min-width:120px;">Action</th>
                <?php endif; ?>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($subs as $s):
                $cat         = $s['kra_category'];
                $raw_pts     = (float)$s['computed_points'];
                $cap         = $kra_caps_rev[$cat] ?? 100;
                $weight      = $kra_weights_rev[$cat] ?? 0;
                $cat_raw     = $kra_raw_totals[$cat] ?? 0;
                $cat_capped  = min($cap, $cat_raw);
                $entry_share = $cat_raw > 0 ? ($raw_pts / $cat_raw) : 0;
                $weighted_val = round($entry_share * $cat_capped * $weight, 2);
                $needs_rev   = ($s['revision_status'] ?? 'ok') === 'needs_revision';
                $i_verified_this = in_array((int)$s['submission_id'], array_map('intval', $my_verified_sids));
                $all_verified    = ($verify_counts[$s['submission_id']] ?? 0) >= $total_active_checkers;
            ?>
            <tr style="border-bottom:1px solid #f1f5f9;<?= $needs_rev ? 'background:#fffbf0;border-left:3px solid #d97706;' : '' ?>"
                onmouseover="this.style.background='<?= $needs_rev ? '#fff8e6' : '#fafafa' ?>'"
                onmouseout="this.style.background='<?= $needs_rev ? '#fffbf0' : '' ?>'">

                <!-- KRA Category -->
                <td style="padding:0.7rem 0.85rem;font-weight:600;color:#1e293b;white-space:nowrap;">
                    <?= sanitize($s['kra_category']) ?>
                </td>

                <!-- Raw Score -->
                <td style="padding:0.7rem 0.75rem;text-align:right;white-space:nowrap;">
                    <span style="font-size:0.88rem;font-weight:700;color:#0f172a;"><?= number_format($raw_pts, 2) ?></span>
                    <?php if (($can_act_revision || isAdmin()) && $app['status'] !== 'submitted'): ?>
                    <button type="button"
                            onclick="openScoreModal(<?= $s['submission_id'] ?>,'<?= addslashes(sanitize($s['kra_category'])) ?>',<?= $raw_pts ?>)"
                            title="Edit score"
                            style="background:none;border:none;padding:2px 4px;cursor:pointer;color:#94a3b8;margin-left:2px;"
                            onmouseover="this.style.color='#1a3a6b'" onmouseout="this.style.color='#94a3b8'">
                        <i class="bi bi-pencil" style="font-size:0.7rem;"></i>
                    </button>
                    <?php endif; ?>
                </td>

                <!-- Weighted Score -->
                <td style="padding:0.7rem 0.75rem;text-align:right;white-space:nowrap;">
                    <span style="font-size:0.88rem;font-weight:700;color:#1a3a6b;"><?= number_format($weighted_val, 2) ?></span>
                    <div style="font-size:0.62rem;color:#94a3b8;margin-top:1px;"><?= ($weight * 100) ?>% wt</div>
                </td>

                <!-- Remarks -->
                <td style="padding:0.7rem 0.75rem;color:#475569;max-width:260px;">
                    <?= formatKraRemarks($s['kra_category'], $s['remarks'] ?? '') ?>
                    <?php if ($needs_rev): ?>
                    <div style="margin-top:0.35rem;padding:0.35rem 0.6rem;background:#fef3c7;border:1px solid #fde68a;border-radius:5px;display:flex;align-items:flex-start;gap:0.4rem;">
                        <i class="bi bi-exclamation-triangle-fill" style="color:#d97706;font-size:0.75rem;margin-top:1px;flex-shrink:0;"></i>
                        <span style="font-size:0.72rem;font-weight:600;color:#92400e;"><?= sanitize($s['revision_note'] ?? '') ?></span>
                    </div>
                    <?php endif; ?>
                </td>

                <!-- Evidence -->
                <td style="padding:0.7rem 0.75rem;">
                    <!-- Verified badge — per-checker -->
                    <?php if ($all_verified): ?>
                    <div style="display:inline-flex;align-items:center;gap:3px;margin-bottom:0.3rem;
                                font-size:0.65rem;font-weight:700;color:#16a34a;
                                background:#f0fdf4;border:1px solid #bbf7d0;
                                border-radius:20px;padding:1px 7px;">
                        <i class="bi bi-patch-check-fill" style="font-size:0.62rem;"></i>Verified
                    </div>
                    <?php elseif ($i_verified_this): ?>
                    <div style="display:inline-flex;align-items:center;gap:3px;margin-bottom:0.3rem;
                                font-size:0.65rem;font-weight:700;color:#1e4d8c;
                                background:#eff6ff;border:1px solid #bfdbfe;
                                border-radius:20px;padding:1px 7px;"
                         title="You verified this. Waiting for other checkers.">
                        <i class="bi bi-patch-check-fill" style="font-size:0.62rem;"></i>You verified
                        <span style="font-size:0.6rem;color:#64748b;">(<?= $verify_counts[$s['submission_id']] ?? 0 ?>/<?= $total_active_checkers ?>)</span>
                    </div>
                    <?php elseif ($can_act_revision): ?>
                    <div style="display:inline-flex;align-items:center;gap:3px;margin-bottom:0.3rem;
                                font-size:0.65rem;font-weight:600;color:#94a3b8;
                                background:#f8fafc;border:1px solid #e2e8f0;
                                border-radius:20px;padding:1px 7px;">
                        <i class="bi bi-circle" style="font-size:0.55rem;"></i>Unverified
                        <?php if (($verify_counts[$s['submission_id']] ?? 0) > 0): ?>
                        <span style="font-size:0.6rem;color:#64748b;">(<?= $verify_counts[$s['submission_id']] ?>/<?= $total_active_checkers ?>)</span>
                        <?php endif; ?>
                    </div>
                    <?php endif; ?>
                    <?php if (!empty($s['evidence_files'])): ?>
                    <div style="display:flex;flex-wrap:wrap;gap:0.3rem;">
                        <?php foreach ($s['evidence_files'] as $fi): ?>
                        <a href="pages/view_file.php?file=<?= urlencode($fi['file_path']) ?>" target="_blank" rel="noopener noreferrer"
                           title="<?= htmlspecialchars($fi['original_filename']) ?>"
                           style="display:inline-flex;align-items:center;gap:0.25rem;padding:2px 8px;
                                  border-radius:4px;border:1px solid #e2e8f0;background:#f8fafc;
                                  font-size:0.68rem;color:#475569;text-decoration:none;white-space:nowrap;max-width:110px;overflow:hidden;text-overflow:ellipsis;"
                           onmouseover="this.style.borderColor='#1a3a6b';this.style.color='#1a3a6b'"
                           onmouseout="this.style.borderColor='#e2e8f0';this.style.color='#475569'">
                            <i class="bi bi-file-earmark" style="flex-shrink:0;"></i>
                            <?= htmlspecialchars(substr($fi['original_filename'], 0, 12) . (strlen($fi['original_filename']) > 12 ? '…' : '')) ?>
                        </a>
                        <?php endforeach; ?>
                    </div>
                    <?php elseif ($s['document_path']): ?>
                    <a href="pages/view_file.php?file=<?= urlencode($s['document_path']) ?>" target="_blank"
                       style="display:inline-flex;align-items:center;gap:0.25rem;padding:2px 8px;border-radius:4px;border:1px solid #e2e8f0;background:#f8fafc;font-size:0.68rem;color:#475569;text-decoration:none;">
                        <i class="bi bi-file-earmark"></i>View
                    </a>
                    <?php else: ?>
                    <span style="font-size:0.72rem;color:#cbd5e1;">—</span>
                    <?php endif; ?>
                </td>

                <!-- Action -->
                <?php
                $show_not_started = !isAdmin() && !$i_decided && !$i_started_review
                                    && in_array($app['status'], ['submitted','under_review']);
                ?>
                <?php if ($can_act_revision): ?>
                <td style="padding:0.7rem 0.75rem;text-align:center;vertical-align:middle;">
                    <div style="display:flex;flex-direction:column;gap:0.3rem;align-items:center;">
                    <?php if ($needs_rev): ?>
                        <?php if ($app['status'] === 'under_review'): ?>
                        <form method="POST" action="index.php?page=<?= $return_page ?>&id=<?= $app_id ?>" id="clearRevForm_<?= $s['submission_id'] ?>">
                            <input type="hidden" name="action" value="clear_revision">
                            <input type="hidden" name="submission_id" value="<?= $s['submission_id'] ?>">
                            <button type="button"
                                    onclick="confirmDelete('Mark this entry as OK and clear the revision flag?','clearRevForm_<?= $s['submission_id'] ?>','Clear Flag','bi-check-circle')"
                                    style="display:inline-flex;align-items:center;gap:0.3rem;padding:0.28rem 0.7rem;border-radius:5px;border:1px solid #16a34a;background:#f0fdf4;color:#16a34a;font-size:0.71rem;font-weight:600;cursor:pointer;white-space:nowrap;">
                                <i class="bi bi-check-circle"></i>Clear Flag
                            </button>
                        </form>
                        <?php else: ?>
                        <span style="font-size:0.65rem;color:#cbd5e1;display:inline-flex;align-items:center;gap:3px;"
                              title="Waiting for faculty to resubmit">
                            <i class="bi bi-hourglass-split" style="font-size:0.6rem;"></i>Awaiting resubmit
                        </span>
                        <?php endif; ?>
                    <?php else: ?>
                        <?php if (!$i_verified_this): ?>
                        <textarea id="verifyNote_<?= $s['submission_id'] ?>"
                                  placeholder="Optional note…"
                                  rows="1"
                                  style="width:120px;resize:vertical;border:1px solid #e2e8f0;border-radius:5px;padding:0.25rem 0.4rem;font-size:0.68rem;color:#334155;font-family:inherit;"></textarea>
                        <button type="button"
                                onclick="quickVerify(<?= $s['submission_id'] ?>, this)"
                                id="verifyBtn_<?= $s['submission_id'] ?>"
                                title="Mark as verified by you"
                                style="display:inline-flex;align-items:center;gap:0.3rem;padding:0.28rem 0.7rem;border-radius:5px;border:1px solid #1e4d8c;background:#eff6ff;color:#1e4d8c;font-size:0.71rem;font-weight:600;cursor:pointer;white-space:nowrap;"
                                onmouseover="this.style.background='#1e4d8c';this.style.color='#fff'"
                                onmouseout="this.style.background='#eff6ff';this.style.color='#1e4d8c'">
                            <i class="bi bi-patch-check"></i>Verify
                        </button>
                        <?php else: ?>
                        <span style="font-size:0.68rem;color:#1e4d8c;font-weight:600;display:inline-flex;align-items:center;gap:3px;">
                            <i class="bi bi-patch-check-fill"></i>You verified
                        </span>
                        <?php endif; ?>
                        <button type="button"
                                onclick="openRevisionModal(<?= $s['submission_id'] ?>,'<?= addslashes(sanitize($s['kra_category'])) ?>')"
                                style="display:inline-flex;align-items:center;gap:0.3rem;padding:0.28rem 0.7rem;border-radius:5px;border:1px solid #e2e8f0;background:#f8fafc;color:#475569;font-size:0.71rem;font-weight:600;cursor:pointer;white-space:nowrap;"
                                onmouseover="this.style.borderColor='#d97706';this.style.color='#d97706';this.style.background='#fffbeb'"
                                onmouseout="this.style.borderColor='#e2e8f0';this.style.color='#475569';this.style.background='#f8fafc'">
                            <i class="bi bi-flag"></i>Flag
                        </button>
                    <?php endif; ?>
                    </div>
                </td>
                <?php elseif ($show_not_started): ?>
                <td style="padding:0.7rem 0.75rem;text-align:center;vertical-align:middle;">
                    <div>
                    <span style="font-size:0.65rem;color:#cbd5e1;display:inline-flex;align-items:center;gap:3px;" title="Start the review to enable verify and flag">
                        <i class="bi bi-lock" style="font-size:0.6rem;"></i>Start review first
                    </span>
                    </div>
                </td>
                <?php endif; ?>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php else: ?>
    <p class="text-muted small">No KRA submissions found.</p>
    <?php endif; ?>

<!-- -- Checker Decision Panel ------------------------------- -->
<?php if (!isAdmin()): ?>

<?php if ($i_approved): ?>
<!-- Already approved &mdash; show confirmation -->
<div class="neon-card mb-3" style="border-left:4px solid #1a3a6b;">
    <div class="d-flex align-items-center gap-3">
        <div style="width:40px;height:40px;border-radius:50%;background:#eff6ff;display:flex;align-items:center;justify-content:center;flex-shrink:0;">
            <i class="bi bi-check-circle-fill" style="color:#1a3a6b;font-size:1.1rem;"></i>
        </div>
        <div>
            <div class="fw-semibold" style="color:#1a3a6b;">You have approved this application</div>
            <div class="text-muted small">
                <?php if ($approved_count >= $total_checkers): ?>
                All <?= $total_checkers ?> checker(s) have approved &mdash; the application is now <strong>approved</strong>.
                <?php else: ?>
                Waiting for <?= $total_checkers - $slots_taken ?> more checker(s) to join and decide.
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<?php elseif ($i_rejected): ?>
<!-- Already rejected &mdash; show confirmation -->
<div class="neon-card mb-3" style="border-left:4px solid #334155;">
    <div class="d-flex align-items-center gap-3">
        <div style="width:40px;height:40px;border-radius:50%;background:#f8fafc;display:flex;align-items:center;justify-content:center;flex-shrink:0;">
            <i class="bi bi-x-circle-fill" style="color:#334155;font-size:1.1rem;"></i>
        </div>
        <div>
            <div class="fw-semibold" style="color:#1a3a6b;">You have rejected this application</div>
            <div class="text-muted small">
                <?php if ($rejected_count >= $total_checkers): ?>
                All <?= $total_checkers ?> checker(s) have rejected &mdash; the application has been <strong>returned to the faculty</strong>.
                <?php else: ?>
                <?= $rejected_count ?> rejection(s) recorded. The application stays <strong>Under Review</strong> until all <?= $total_checkers ?> checkers have decided.
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
    <h6 class="mb-3" style="color:var(--blue-dark);"><i class="bi bi-pencil-square me-2"></i>Your Review Decision</h6>
    <div class="alert alert-info py-2 small mb-3">
        <i class="bi bi-info-circle me-1"></i>
        <strong>Approve</strong> &mdash; application moves forward when all <?= $total_checkers ?> checker(s) approve.<br>
        <strong>Reject</strong> &mdash; application is returned to faculty only when all <?= $total_checkers ?> checker(s) reject.
        A single rejection keeps it <strong>Under Review</strong>.
    </div>
    <form method="POST" action="index.php?page=<?= $return_page ?>&id=<?= $app_id ?>" id="checkerDecisionForm">
        <input type="hidden" name="action" id="checkerDecisionAction" value="">
        <div class="mb-3">
            <label class="form-label fw-semibold">
                Remarks
                <small class="text-muted">(optional for approval, <span class="text-danger">required for rejection</span>)</small>
            </label>
            <textarea name="checker_remarks" id="checkerRemarksField" class="form-control" rows="2"
                      placeholder="Enter your remarks..."></textarea>
        </div>
        <div class="d-flex gap-2 flex-wrap">
            <?php if ($score_result['weighted_score'] < 41): ?>
            <button type="button" class="btn btn-success" disabled
                    title="Cannot approve — weighted score (<?= number_format($score_result['weighted_score'], 2) ?>) is below the minimum of 41."
                    style="opacity:0.5;cursor:not-allowed;">
                <i class="bi bi-check-circle me-2"></i>Approve
            </button>
            <?php else: ?>
            <button type="button" class="btn btn-success"
                    onclick="submitCheckerDecision('checker_approve','Approve this application?','Approve','bi-check-circle')">
                <i class="bi bi-check-circle me-2"></i>Approve
            </button>
            <?php endif; ?>
            <button type="button" class="btn btn-danger"
                    onclick="submitCheckerDecision('checker_reject','Reject this application? It will only be returned to the faculty if all checkers reject.','Reject','bi-x-circle')">
                <i class="bi bi-x-circle me-2"></i>Reject
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
        alert('A reason is required when rejecting.');
        return;
    }
    document.getElementById('checkerRemarksField').style.borderColor = '';
    document.getElementById('checkerDecisionAction').value = action;
    confirmDelete(msg, 'checkerDecisionForm', btnLabel, btnIcon);
}
</script>

<?php elseif ($app['status'] === 'talisay_review' && isTalisayChecker()): ?>
<!-- Talisay checker decision panel -->
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
                You have <?= $my_talisay_review['decision']==='approved'?'approved':'rejected' ?> this application.
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
        <i class="bi bi-building me-2"></i>Talisay (Main) — Final Review Decision
    </h6>
    <div class="alert py-2 small mb-3" style="background:#f0f7ff;border:1px solid #bfdbfe;color:#1e4d8c;">
        <i class="bi bi-info-circle me-1"></i>
        This application has been approved by all campus checkers and is now pending <strong>Talisay (Main)</strong> final approval.
    </div>
    <form method="POST" action="index.php?page=<?= $return_page ?>&id=<?= $app_id ?>" id="talisayDecisionForm">
        <input type="hidden" name="action" id="talisayDecisionAction" value="">
        <div class="mb-3">
            <label class="form-label fw-semibold">
                Remarks
                <small class="text-muted">(<span class="text-danger">required for rejection</span>)</small>
            </label>
            <textarea name="checker_remarks" id="talisayRemarksField" class="form-control" rows="2"
                      placeholder="Enter your remarks..."></textarea>
        </div>
        <div class="d-flex gap-2 flex-wrap">
            <?php if ($score_result['weighted_score'] < 41): ?>
            <button type="button" class="btn btn-success" disabled
                    title="Cannot approve — weighted score (<?= number_format($score_result['weighted_score'], 2) ?>) is below the minimum of 41."
                    style="opacity:0.5;cursor:not-allowed;">
                <i class="bi bi-check-circle me-2"></i>Final Approve
            </button>
            <?php else: ?>
            <button type="button" class="btn btn-success"
                    onclick="submitTalisayDecision('talisay_approve','Final approve this application?','Approve','bi-check-circle')">
                <i class="bi bi-check-circle me-2"></i>Final Approve
            </button>
            <?php endif; ?>
            <button type="button" class="btn btn-danger"
                    onclick="submitTalisayDecision('talisay_reject','Return this application to the faculty?','Reject','bi-x-circle')">
                <i class="bi bi-x-circle me-2"></i>Return to Faculty
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

<!-- -- Admin Decision Panel ------------------------------------ -->
<?php if ($app['status'] === 'approved' && isAdmin()): ?>
<div class="neon-card mb-3" style="border-color:#1a3a6b;">
    <h6 class="mb-3" style="color:var(--blue-dark);"><i class="bi bi-shield-check me-2"></i>Admin Review</h6>
    <p class="text-muted small mb-3">
        This application has been <strong>approved by all <?= $total_checkers ?> checker(s)</strong>.
        The application is now <strong>approved</strong>. You may permanently reject it if needed.
    </p>
    <form method="POST" action="index.php?page=<?= $return_page ?>&id=<?= $app_id ?>" id="adminDecisionForm">
        <input type="hidden" name="action" value="admin_reject">
        <div class="mb-3">
            <label class="form-label fw-semibold">Rejection Reason <span class="text-danger">*</span></label>
            <textarea name="admin_remarks" class="form-control" rows="3"
                      placeholder="Required &mdash; state the reason for permanent rejection..."></textarea>
        </div>
        <div class="d-flex gap-2 flex-wrap">
            <button type="button" class="btn btn-danger"
                    onclick="confirmDelete('Permanently reject this application for <?= sanitize($app['full_name'] ?? '') ?>? This cannot be undone.','adminDecisionForm','Reject Application','bi-x-circle')">
                <i class="bi bi-x-circle me-2"></i>Permanently Reject
            </button>
        </div>
        <p class="text-muted small mt-2 mb-0">
            <i class="bi bi-info-circle me-1"></i>
            The application is already <strong>approved</strong> &mdash; no further action is required unless you need to reject it.
        </p>
    </form>
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
                    <div class="form-text">Enter the verified correct score for this KRA entry.</div>
                </div>
                <div class="mb-4">
                    <label class="form-label fw-semibold small">Reason for Change <span class="text-muted">(optional)</span></label>
                    <input type="text" name="alter_note" class="form-control"
                           placeholder="e.g. Corrected based on verified documents">
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
            <div class="score-success-sub">${category}: ${oldVal} → ${newVal}</div>
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
    btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1" style="width:10px;height:10px;"></span>Verifying…';

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
    .then(r => r.json())
    .then(data => {
        if (data.ok) {
            // Remove the note textarea alongside the button
            if (noteEl) noteEl.remove();

            // Replace the Verify button with a "Verified" badge in-place
            const row = btn.closest('tr');
            btn.outerHTML = '<span style="font-size:0.68rem;color:#16a34a;font-weight:600;display:inline-flex;align-items:center;gap:3px;">'
                          + '<i class="bi bi-patch-check-fill"></i>Verified</span>';

            // Update the unverified badge in the evidence cell
            if (row) {
                const evidenceCell = row.querySelector('td:nth-child(5)');
                if (evidenceCell) {
                    const unverBadge = evidenceCell.querySelector('div[style*="Unverified"], div[style*="94a3b8"]');
                    if (unverBadge) {
                        unverBadge.style.color = '#16a34a';
                        unverBadge.style.background = '#f0fdf4';
                        unverBadge.style.borderColor = '#bbf7d0';
                        unverBadge.innerHTML = '<i class="bi bi-patch-check-fill" style="font-size:0.62rem;"></i>Verified';
                    }
                }
            }

            // Re-check if all are now verified — remove the block message if shown
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
            btn.innerHTML = '<i class="bi bi-patch-check"></i>Verify';
            alert(data.error || 'Verify failed. Please try again.');
        }
    })
    .catch(() => {
        btn.disabled = false;
        btn.innerHTML = '<i class="bi bi-patch-check"></i>Verify';
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
            // Show warning overlay — score dropped below 41
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
</script>
