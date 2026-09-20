<?php
/**
 * Dashboard real-time poll endpoint.
 * Returns role-specific counts as JSON every 15 seconds.
 * Called by the polling loop in header.php.
 */
if (session_status() === PHP_SESSION_NONE) session_start();
header('Content-Type: application/json');
header('Cache-Control: no-cache, no-store, must-revalidate');

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/functions.php';

if (!isLoggedIn()) {
    echo json_encode(['ok' => false]);
    exit;
}

$uid  = $_SESSION['user_id'];
$role = $_SESSION['role'] ?? '';
$data = ['ok' => true, 'role' => $role, 'counts' => []];

try {
    if ($role === 'checker') {
        // Awaiting Decision
        $s = $pdo->prepare("SELECT COUNT(*) FROM applications a WHERE a.status IN ('submitted','under_review') AND a.user_id != ? AND NOT EXISTS (SELECT 1 FROM application_checker_reviews r WHERE r.application_id=a.application_id AND r.checker_id=? AND r.decision IN ('approved','rejected'))");
        $s->execute([$uid, $uid]);
        $data['counts']['checker_pending'] = (int)$s->fetchColumn();

        // Active Reviews
        $s = $pdo->prepare("SELECT COUNT(*) FROM applications a WHERE a.status IN ('under_review','needs_revision') AND a.checker_id=? AND a.user_id != ?");
        $s->execute([$uid, $uid]);
        $data['counts']['checker_active'] = (int)$s->fetchColumn();

        // Needs Revision
        $s = $pdo->prepare("SELECT COUNT(*) FROM applications WHERE checker_id=? AND status='needs_revision'");
        $s->execute([$uid]);
        $data['counts']['checker_revision'] = (int)$s->fetchColumn();

    } elseif ($role === 'talisay_checker') {
        // Awaiting My Review
        $s = $pdo->prepare("SELECT COUNT(*) FROM applications a WHERE a.status = 'talisay_review' AND a.user_id != ? AND NOT EXISTS (SELECT 1 FROM application_checker_reviews r WHERE r.application_id = a.application_id AND r.checker_id = ? AND r.decision IN ('approved','rejected'))");
        $s->execute([$uid, $uid]);
        $data['counts']['talisay_pending'] = (int)$s->fetchColumn();

        // In Talisay Review
        $data['counts']['talisay_total'] = (int)$pdo->query("SELECT COUNT(*) FROM applications WHERE status='talisay_review'")->fetchColumn();

        // I Approved
        $s = $pdo->prepare("SELECT COUNT(*) FROM application_checker_reviews r JOIN applications a ON r.application_id = a.application_id WHERE r.checker_id = ? AND r.decision = 'approved'");
        $s->execute([$uid]);
        $data['counts']['talisay_approved'] = (int)$s->fetchColumn();

        // Total Reviewed
        $s = $pdo->prepare("SELECT COUNT(*) FROM application_checker_reviews WHERE checker_id = ? AND decision IN ('approved','rejected')");
        $s->execute([$uid]);
        $data['counts']['talisay_reviewed'] = (int)$s->fetchColumn();

    } elseif ($role === 'admin') {
        $data['counts']['admin_faculty']   = (int)$pdo->query("SELECT COUNT(*) FROM users WHERE role = 'faculty'")->fetchColumn();
        $data['counts']['admin_applied']   = (int)$pdo->query("SELECT COUNT(DISTINCT user_id) FROM applications WHERE status != 'draft'")->fetchColumn();
        $data['counts']['admin_pending']   = (int)$pdo->query("SELECT COUNT(*) FROM applications WHERE status='submitted'")->fetchColumn();
        $data['counts']['admin_review']    = (int)$pdo->query("SELECT COUNT(*) FROM applications WHERE status='under_review'")->fetchColumn();
        $data['counts']['admin_revision']  = (int)$pdo->query("SELECT COUNT(*) FROM applications WHERE status='needs_revision'")->fetchColumn();
        $data['counts']['admin_approved']  = (int)$pdo->query("SELECT COUNT(*) FROM applications WHERE status='approved'")->fetchColumn();

    } elseif ($role === 'faculty') {
        // Faculty: just return their application status
        $cycle = $pdo->query("SELECT cycle_id FROM cycles WHERE status='open' LIMIT 1")->fetch();
        if ($cycle) {
            $s = $pdo->prepare("SELECT status, weighted_score FROM applications WHERE user_id=? AND cycle_id=? LIMIT 1");
            $s->execute([$uid, $cycle['cycle_id']]);
            $app = $s->fetch();
            if ($app) {
                $data['counts']['faculty_status'] = $app['status'];
                $data['counts']['faculty_score']  = number_format((float)$app['weighted_score'], 2);
            }
        }
    }
} catch (\Exception $e) {
    $data['ok'] = false;
    $data['error'] = 'Query failed';
}

echo json_encode($data);
