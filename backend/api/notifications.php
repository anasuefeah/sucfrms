<?php
// AJAX: list notifications / mark them read (index.php?notif_action=...). Required by index.php.
defined('APP_ROOT') || exit;

// -- AJAX: mark notifications as read --
if (isset($_GET['notif_action'])) {
    header('Content-Type: application/json');
    $na = $_GET['notif_action'];
    $uid = $_SESSION['user_id'];
    if ($na === 'mark_read') {
        $nid = intval($_POST['notif_id'] ?? 0);
        if ($nid) {
            $pdo->prepare("UPDATE notifications SET is_read=1 WHERE notif_id=? AND user_id=?")->execute([$nid, $uid]);
        } else {
            $pdo->prepare("UPDATE notifications SET is_read=1 WHERE user_id=?")->execute([$uid]);
        }
        echo json_encode(['ok' => true]);
    } elseif ($na === 'get_notifs') {
        // Filter notification types by role so each role only sees relevant notifications
        $role_notif = $_SESSION['role'] ?? 'faculty';
        // Evaluators have no split notification types — show all
        $excluded_types = [];
        if (!empty($excluded_types)) {
            $ph = implode(',', array_fill(0, count($excluded_types), '?'));
            $rows = $pdo->prepare("SELECT n.notif_id, n.type, n.message, n.application_id, n.submission_id, ks.kra_category, n.is_read, UNIX_TIMESTAMP(n.created_at) AS created_at FROM notifications n LEFT JOIN kra_submissions ks ON ks.submission_id = n.submission_id WHERE n.user_id=? AND n.type NOT IN ($ph) ORDER BY n.created_at DESC LIMIT 30");
            $rows->execute(array_merge([$uid], $excluded_types));
        } else {
            $rows = $pdo->prepare("SELECT n.notif_id, n.type, n.message, n.application_id, n.submission_id, ks.kra_category, n.is_read, UNIX_TIMESTAMP(n.created_at) AS created_at FROM notifications n LEFT JOIN kra_submissions ks ON ks.submission_id = n.submission_id WHERE n.user_id=? ORDER BY n.created_at DESC LIMIT 30");
            $rows->execute([$uid]);
        }
        echo json_encode(['ok' => true, 'notifs' => $rows->fetchAll(PDO::FETCH_ASSOC)]);
    }
    exit;
}
