<?php
// Request handling and data loading for frontend/views/evaluator/dashboard.php
$uid   = $_SESSION['user_id'];
$cycle = getActiveCycle($pdo);
ensureKraAssignmentTable($pdo);

try { $pdo->query("SELECT review_id FROM application_checker_reviews LIMIT 1"); }
catch (\Exception $e) {
    $pdo->exec("CREATE TABLE IF NOT EXISTS application_checker_reviews (
        review_id INT AUTO_INCREMENT PRIMARY KEY, application_id INT NOT NULL, checker_id INT NOT NULL,
        decision ENUM('approved','pending') DEFAULT 'pending', remarks TEXT, decided_at TIMESTAMP NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uq_app_checker (application_id, checker_id),
        FOREIGN KEY (application_id) REFERENCES applications(application_id) ON DELETE CASCADE,
        FOREIGN KEY (checker_id) REFERENCES users(user_id) ON DELETE CASCADE)");
}

$pending_stmt = $pdo->prepare("SELECT COUNT(*) FROM applications a WHERE a.status IN ('submitted','under_review') AND a.user_id != ? AND EXISTS (SELECT 1 FROM kra_submissions ks JOIN checker_kra_assignments ka ON ka.cycle_id=a.cycle_id AND ka.kra_category=ks.kra_category AND ka.checker_id=? WHERE ks.application_id=a.application_id) AND NOT EXISTS (SELECT 1 FROM application_checker_reviews r WHERE r.application_id=a.application_id AND r.checker_id=? AND r.decision IN ('approved','rejected'))");
$pending_stmt->execute([$uid,$uid,$uid]); $pending = (int)$pending_stmt->fetchColumn();

$my_under_review_stmt = $pdo->prepare("SELECT COUNT(*) FROM applications a WHERE a.status IN ('under_review','needs_revision') AND a.checker_id=? AND a.user_id != ? AND EXISTS (SELECT 1 FROM kra_submissions ks JOIN checker_kra_assignments ka ON ka.cycle_id=a.cycle_id AND ka.kra_category=ks.kra_category AND ka.checker_id=? WHERE ks.application_id=a.application_id)");
$my_under_review_stmt->execute([$uid, $uid, $uid]); $my_under_review = (int)$my_under_review_stmt->fetchColumn();

$needs_revision_stmt = $pdo->prepare("SELECT COUNT(*) FROM applications a WHERE a.checker_id=? AND a.status='needs_revision' AND EXISTS (SELECT 1 FROM kra_submissions ks JOIN checker_kra_assignments ka ON ka.cycle_id=a.cycle_id AND ka.kra_category=ks.kra_category AND ka.checker_id=? WHERE ks.application_id=a.application_id)");
$needs_revision_stmt->execute([$uid, $uid]); $needs_revision = (int)$needs_revision_stmt->fetchColumn();

$my_stats_stmt = $pdo->prepare("SELECT COUNT(*) AS total_reviewed, SUM(CASE WHEN r.decision='approved' THEN 1 ELSE 0 END) AS approved FROM application_checker_reviews r JOIN applications a ON r.application_id=a.application_id WHERE r.checker_id=? AND a.status IN ('approved','admin_rejected')");
$my_stats_stmt->execute([$uid]); $my_stats = $my_stats_stmt->fetch();
$total_reviewed = (int)$my_stats['total_reviewed']; $total_approved = (int)$my_stats['approved'];

$total_checkers_dash = $cycle ? countAssignedCheckersForCycle($pdo, (int)$cycle['cycle_id']) : max(1,(int)$pdo->query("SELECT COUNT(*) FROM users WHERE role = 'evaluator' AND status='active'")->fetchColumn());

$queue_stmt = $pdo->prepare("SELECT a.*, u.full_name, u.first_name, u.middle_name, u.last_name, u.rank, COALESCE(camp.campus_name,' - ') AS campus_name, c.cycle_name, c.evaluation_deadline, DATEDIFF(NOW(),a.submitted_at) AS days_waiting FROM applications a JOIN users u ON a.user_id=u.user_id LEFT JOIN campuses camp ON u.campus_id=camp.campus_id JOIN cycles c ON a.cycle_id=c.cycle_id WHERE a.status IN ('submitted','under_review') AND a.user_id!=? AND EXISTS (SELECT 1 FROM kra_submissions ks JOIN checker_kra_assignments ka ON ka.cycle_id=a.cycle_id AND ka.kra_category=ks.kra_category AND ka.checker_id=? WHERE ks.application_id=a.application_id) AND NOT EXISTS (SELECT 1 FROM application_checker_reviews r WHERE r.application_id=a.application_id AND r.checker_id=? AND r.decision IN ('approved','rejected')) ORDER BY a.submitted_at ASC LIMIT 8");
$queue_stmt->execute([$uid,$uid,$uid]); $queue = $queue_stmt->fetchAll();

$revision_stmt = $pdo->prepare("SELECT a.*, u.full_name, u.first_name, u.middle_name, u.last_name, COALESCE(camp.campus_name,' - ') AS campus_name, c.evaluation_deadline, DATEDIFF(NOW(),a.updated_at) AS days_waiting FROM applications a JOIN users u ON a.user_id=u.user_id LEFT JOIN campuses camp ON u.campus_id=camp.campus_id LEFT JOIN cycles c ON a.cycle_id=c.cycle_id WHERE a.checker_id=? AND a.status='needs_revision' AND EXISTS (SELECT 1 FROM kra_submissions ks JOIN checker_kra_assignments ka ON ka.cycle_id=a.cycle_id AND ka.kra_category=ks.kra_category AND ka.checker_id=? WHERE ks.application_id=a.application_id) ORDER BY a.updated_at ASC");
$revision_stmt->execute([$uid, $uid]); $revision_apps = $revision_stmt->fetchAll();

$inprogress_stmt = $pdo->prepare("SELECT a.*, u.full_name, u.first_name, u.middle_name, u.last_name, u.rank, COALESCE(camp.campus_name,' - ') AS campus_name, c.evaluation_deadline, DATEDIFF(NOW(),a.submitted_at) AS days_waiting, NULL AS my_decision, (SELECT COUNT(*) FROM application_checker_reviews r2 JOIN users uc2 ON r2.checker_id=uc2.user_id WHERE r2.application_id=a.application_id AND r2.decision='approved' AND uc2.role = 'evaluator') AS approvals_count FROM applications a JOIN users u ON a.user_id=u.user_id LEFT JOIN campuses camp ON u.campus_id=camp.campus_id LEFT JOIN cycles c ON a.cycle_id=c.cycle_id WHERE a.status IN ('under_review','needs_revision') AND a.checker_id=? AND a.user_id != ? AND EXISTS (SELECT 1 FROM kra_submissions ks JOIN checker_kra_assignments ka ON ka.cycle_id=a.cycle_id AND ka.kra_category=ks.kra_category AND ka.checker_id=? WHERE ks.application_id=a.application_id) ORDER BY a.submitted_at ASC LIMIT 5");
$inprogress_stmt->execute([$uid, $uid, $uid]); $inprogress = $inprogress_stmt->fetchAll();

$recent_stmt = $pdo->prepare("SELECT a.*, u.full_name, u.first_name, u.middle_name, u.last_name, COALESCE(camp.campus_name,' - ') AS campus_name FROM applications a JOIN users u ON a.user_id=u.user_id LEFT JOIN campuses camp ON u.campus_id=camp.campus_id JOIN application_checker_reviews r ON r.application_id=a.application_id AND r.checker_id=? WHERE a.status IN ('approved','admin_rejected') ORDER BY a.reviewed_at DESC LIMIT 5");
$recent_stmt->execute([$uid]); $recent = $recent_stmt->fetchAll();
