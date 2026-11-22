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
    if ($role === 'evaluator') {
        $data['counts'] = evaluatorDashboardCounts($pdo, (int)$uid);

    } elseif ($role === 'admin') {
        $data['counts'] = adminDashboardCounts($pdo);

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
