<?php
function ensureTeachingDivisors(PDO $pdo): void
{
    if ($pdo->query("SHOW COLUMNS FROM applications LIKE 'teaching_divisors'")->fetchColumn()) return;
    try {
        $pdo->exec("ALTER TABLE applications ADD COLUMN teaching_divisors VARCHAR(20) DEFAULT NULL");
    } catch (PDOException $e) {
        if ((int)($e->errorInfo[1] ?? 0) !== 1060) throw $e;
    }
}

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

function migrateEvaluatorTerminology(PDO $pdo): bool {
    try {
        $pdo->exec("UPDATE notifications
            SET message = 'Your application is now under review by the evaluators.'
            WHERE type = 'under_review' AND message = 'Your application is now under review by the Subcommittee.'");

        $pdo->exec("UPDATE help_articles
            SET content = REPLACE(content, 'For further assistance contact the Subcommittee or the system administrator.',
                'For further assistance, contact the evaluators or the system administrator.'),
                updated_at = updated_at
            WHERE category = 'help' AND title = 'Getting Started with SUCFRMS'
              AND content LIKE '%For further assistance contact the Subcommittee or the system administrator.%'");
    } catch (PDOException $e) {
        if ((int)($e->errorInfo[1] ?? 0) === 1146) return false;
        throw $e;
    }
    return true;
}
