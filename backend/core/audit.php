<?php
// Audit log writer.

// -- Audit logging ---------------------------------------------
function logAudit($pdo, $user_id, string $action, string $details = ''): void {
    $pdo->prepare("INSERT INTO audit_logs (user_id, role_at_time, action_performed, details) VALUES (?,?,?,?)")
        ->execute([$user_id, $_SESSION['role'] ?? 'unknown', $action, $details]);
}
