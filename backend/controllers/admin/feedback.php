<?php
// Request handling and data loading for frontend/views/admin/feedback.php
if (!isAdmin()) { echo '<div class="alert alert-danger">Access denied.</div>'; return false; }

// Mark as read / resolved
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $fid    = intval($_POST['feedback_id'] ?? 0);
    if ($action === 'mark_read' && $fid) {
        $pdo->prepare("UPDATE feedback_submissions SET status='read' WHERE feedback_id=?")->execute([$fid]);
    } elseif ($action === 'mark_resolved' && $fid) {
        $pdo->prepare("UPDATE feedback_submissions SET status='resolved', resolved_by=?, resolved_at=NOW() WHERE feedback_id=?")
            ->execute([$_SESSION['user_id'], $fid]);
        logAudit($pdo, $_SESSION['user_id'], 'Feedback Resolved', "Feedback ID {$fid} marked resolved.");
    } elseif ($action === 'mark_new' && $fid) {
        $pdo->prepare("UPDATE feedback_submissions SET status='new', resolved_by=NULL, resolved_at=NULL WHERE feedback_id=?")->execute([$fid]);
    } elseif ($action === 'delete' && $fid) {
        $pdo->prepare("DELETE FROM feedback_submissions WHERE feedback_id=?")->execute([$fid]);
        logAudit($pdo, $_SESSION['user_id'], 'Feedback Deleted', "Deleted feedback ID {$fid}.");
    }
    echo "<script>window.location.href='index.php?page=feedback';</script>"; exit;
}

// Filters
$filter_status = $_GET['status'] ?? '';
$valid_statuses = ['new','read','resolved'];
$where = $filter_status && in_array($filter_status, $valid_statuses) ? "WHERE f.status = " . $pdo->quote($filter_status) : '';

// Counts
$counts = ['all'=>0,'new'=>0,'read'=>0,'resolved'=>0];
foreach ($pdo->query("SELECT status, COUNT(*) c FROM feedback_submissions GROUP BY status")->fetchAll() as $r) {
    $counts[$r['status']] = (int)$r['c'];
    $counts['all'] += (int)$r['c'];
}

// Fetch
$feedbacks = $pdo->query("
    SELECT f.*, u.full_name, u.email, u.role
    FROM feedback_submissions f
    LEFT JOIN users u ON f.user_id = u.user_id
    {$where}
    ORDER BY f.submitted_at DESC
")->fetchAll();

$status_styles = [
    'new'      => ['bg'=>'#f8fafc','border'=>'#94a3b8','color'=>'#1a3a6b','label'=>'New','icon'=>'bi-dot'],
    'read'     => ['bg'=>'#f8fafc','border'=>'#e2e8f0','color'=>'#334155','label'=>'Read','icon'=>'bi-eye'],
    'resolved' => ['bg'=>'#f0f4fb','border'=>'#bfdbfe','color'=>'#1a3a6b','label'=>'Resolved','icon'=>'bi-check-circle'],
];

$star_colors = ['','#1e293b','#475569','#475569','#3b82f6','#1e4d8c'];
