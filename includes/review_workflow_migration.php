<?php
/** Preserve legacy applications and notifications when retiring the extra review stage. */
function migrateSingleStageReview(PDO $pdo): bool {
    try {
        $status = $pdo->query("SHOW COLUMNS FROM applications LIKE 'status'")->fetch(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        if ((int)($e->errorInfo[1] ?? 0) === 1146) return false;
        throw $e;
    }

    if ($status && str_contains($status['Type'], "'talisay_review'")) {
        $pdo->exec("UPDATE applications SET status='under_review', updated_at=updated_at WHERE status='talisay_review'");
        $pdo->exec("ALTER TABLE applications MODIFY COLUMN status ENUM('draft','submitted','under_review','approved','rejected','reclassified','admin_rejected','edit_requested','needs_revision','expired') DEFAULT 'draft'");
    }

    try {
        $pdo->exec("UPDATE notifications SET type='under_review', message='Your application is under review.' WHERE type='talisay_review'");
        $pdo->exec("UPDATE notifications SET type='new_submission', message='An application is ready for evaluation.' WHERE type='new_talisay_submission'");
    } catch (PDOException $e) {
        if ((int)($e->errorInfo[1] ?? 0) === 1146) return false;
        throw $e;
    }
    return true;
}
