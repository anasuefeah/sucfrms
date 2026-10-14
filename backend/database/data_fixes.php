<?php
// Once-per-session data clean-ups on the notifications table. Required by index.php.
defined('APP_ROOT') || exit;

// One-time cleanup: remove stale talisay_checker/checker notification mismatches
if (empty($_SESSION['_notif_cleanup_done'])) {
    try {
        // Remove any leftover notification type mismatches from old role setup
        $pdo->exec("DELETE n FROM notifications n
            JOIN users u ON n.user_id = u.user_id
            WHERE u.role = 'evaluator'
              AND n.type = 'new_talisay_submission'");
    } catch (\Exception $e) {}
    $_SESSION['_notif_cleanup_done'] = true;
}

// One-time per-session: fix stored notification messages containing "Last, First" name format
if (empty($_SESSION['_notif_names_fixed'])) {
    try {
        $bad = $pdo->query("SELECT notif_id, message FROM notifications WHERE message LIKE '% by %,%'");
        if ($bad) {
            $upd = $pdo->prepare("UPDATE notifications SET message=? WHERE notif_id=?");
            foreach ($bad->fetchAll() as $n) {
                $fixed = preg_replace_callback(
                    '/\bby\s+([^,\.\n]+),\s*([^,\.\n]+)\./u',
                    function($m) {
                        $last  = ucfirst(strtolower(trim($m[1])));
                        $rest  = trim($m[2]);
                        $parts = preg_split('/\s+/', $rest, 2);
                        $first = ucfirst(strtolower($parts[0] ?? ''));
                        $mi    = !empty($parts[1]) ? ' ' . strtoupper(substr(trim($parts[1]), 0, 1)) . '.' : '';
                        return 'by ' . $first . $mi . ' ' . $last . '.';
                    },
                    $n['message']
                );
                if ($fixed !== $n['message']) {
                    $upd->execute([$fixed, $n['notif_id']]);
                }
            }
        }
    } catch (\Exception $e) {}
    $_SESSION['_notif_names_fixed'] = true;
}
