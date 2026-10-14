<?php
// Notifications, deadlines, evaluator KRA assignments and appeals.

// -- Notifications ---------------------------------------------
function kraCategoryToTabSlug(?string $category): string {
    return match ($category) {
        'Research'                 => 'research',
        'Extension'                => 'extension',
        'Professional Development' => 'profdev',
        default                    => 'instruction',
    };
}

function createNotif($pdo, int $user_id, string $type, string $message, int $application_id = 0, int $submission_id = 0): void {
    try {
        $pdo->prepare("INSERT INTO notifications (user_id, type, message, application_id, submission_id) VALUES (?,?,?,?,?)")
            ->execute([$user_id, $type, $message, $application_id ?: null, $submission_id ?: null]);
    } catch (\Exception $e) { /* table may not exist yet  -  silent fail */ }
}

function getEffectiveSubmissionDeadline(PDO $pdo, int $cycle_id, int $faculty_user_id = 0, ?string $base_deadline = null): ?string {
    if (!$base_deadline && $cycle_id) {
        try {
            $s = $pdo->prepare("SELECT submission_deadline FROM cycles WHERE cycle_id=?");
            $s->execute([$cycle_id]);
            $base_deadline = $s->fetchColumn() ?: null;
        } catch (\Exception $e) {}
    }
    if (!$cycle_id) return $base_deadline ?: null;
    try {
        $s = $pdo->prepare("
            SELECT MAX(new_deadline)
            FROM cycle_submission_extensions
            WHERE cycle_id = ?
              AND (applies_to_all = 1 OR faculty_user_id = ?)
        ");
        $s->execute([$cycle_id, $faculty_user_id ?: 0]);
        $ext = $s->fetchColumn();
        if ($ext && (!$base_deadline || strtotime($ext) > strtotime($base_deadline))) {
            return $ext;
        }
    } catch (\Exception $e) {}
    return $base_deadline ?: null;
}

function getEvaluationDeadlineLabel(?string $deadline): array {
    if (!$deadline) return ['No Deadline', '#64748b', '#f1f5f9'];
    $today = strtotime(date('Y-m-d'));
    $due = strtotime($deadline);
    if ($due < $today) return ['Overdue', '#dc2626', '#fef2f2'];
    $days = (int)floor(($due - $today) / 86400);
    if ($days <= 3) return ['Due Soon', '#d97706', '#fffbeb'];
    return ['On Time', '#16a34a', '#f0fdf4'];
}

function kraAssignmentCategories(): array {
    return [
        'Instruction' => 'KRA I',
        'Research' => 'KRA II',
        'Extension' => 'KRA III',
        'Professional Development' => 'KRA IV',
    ];
}

function ensureKraAssignmentTable(PDO $pdo): void {
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS checker_kra_assignments (
            assignment_id INT AUTO_INCREMENT PRIMARY KEY,
            cycle_id INT NOT NULL,
            checker_id INT NOT NULL,
            kra_category ENUM('Instruction','Research','Extension','Professional Development') NOT NULL,
            assigned_by INT DEFAULT NULL,
            assigned_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uq_checker_cycle_kra (cycle_id, checker_id, kra_category),
            INDEX idx_cka_cycle_kra (cycle_id, kra_category),
            INDEX idx_cka_checker_cycle (checker_id, cycle_id),
            FOREIGN KEY (cycle_id) REFERENCES cycles(cycle_id) ON DELETE CASCADE,
            FOREIGN KEY (checker_id) REFERENCES users(user_id) ON DELETE CASCADE,
            FOREIGN KEY (assigned_by) REFERENCES users(user_id) ON DELETE SET NULL
        )");
        $count = (int)$pdo->query("SELECT COUNT(*) FROM checker_kra_assignments")->fetchColumn();
        if ($count === 0) {
            $ins = $pdo->prepare("INSERT IGNORE INTO checker_kra_assignments (cycle_id, checker_id, kra_category, assigned_by) VALUES (?,?,?,NULL)");
            $cycles = $pdo->query("SELECT cycle_id FROM cycles")->fetchAll(PDO::FETCH_COLUMN);
            $checkers = $pdo->query("SELECT user_id FROM users WHERE role='evaluator' AND status='active'")->fetchAll(PDO::FETCH_COLUMN);
            foreach ($cycles as $cycle_id) {
                foreach ($checkers as $checker_id) {
                    foreach (array_keys(kraAssignmentCategories()) as $kra) {
                        $ins->execute([(int)$cycle_id, (int)$checker_id, $kra]);
                    }
                }
            }
        }
    } catch (\Exception $e) {}
}

function getCheckerAssignedKras(PDO $pdo, int $checker_id, int $cycle_id): array {
    if (!$checker_id || !$cycle_id) return [];
    ensureKraAssignmentTable($pdo);
    try {
        $stmt = $pdo->prepare("SELECT kra_category FROM checker_kra_assignments WHERE checker_id=? AND cycle_id=?");
        $stmt->execute([$checker_id, $cycle_id]);
        return $stmt->fetchAll(PDO::FETCH_COLUMN) ?: [];
    } catch (\Exception $e) {
        return [];
    }
}

function checkerCanReviewKra(PDO $pdo, int $checker_id, int $cycle_id, string $kra_category): bool {
    if (isAdmin()) return true;
    if (!isEvaluator()) return false;
    return in_array($kra_category, getCheckerAssignedKras($pdo, $checker_id, $cycle_id), true);
}

function countAssignedCheckersForKra(PDO $pdo, int $cycle_id, string $kra_category): int {
    ensureKraAssignmentTable($pdo);
    try {
        $stmt = $pdo->prepare("
            SELECT COUNT(DISTINCT a.checker_id)
            FROM checker_kra_assignments a
            JOIN users u ON u.user_id = a.checker_id
            WHERE a.cycle_id=? AND a.kra_category=? AND u.role='evaluator' AND u.status='active'
        ");
        $stmt->execute([$cycle_id, $kra_category]);
        return (int)$stmt->fetchColumn();
    } catch (\Exception $e) {
        return 0;
    }
}

function countAssignedCheckersForCycle(PDO $pdo, int $cycle_id): int {
    ensureKraAssignmentTable($pdo);
    try {
        $stmt = $pdo->prepare("
            SELECT COUNT(DISTINCT a.checker_id)
            FROM checker_kra_assignments a
            JOIN users u ON u.user_id = a.checker_id
            WHERE a.cycle_id=? AND u.role='evaluator' AND u.status='active'
        ");
        $stmt->execute([$cycle_id]);
        return max(1, (int)$stmt->fetchColumn());
    } catch (\Exception $e) {
        return max(1, (int)$pdo->query("SELECT COUNT(*) FROM users WHERE role='evaluator' AND status='active'")->fetchColumn());
    }
}

function ensureAppealTables(PDO $pdo): void {
    try { $pdo->query("SELECT appeal_deadline FROM cycles LIMIT 1"); }
    catch (\Exception $e) { try { $pdo->exec("ALTER TABLE cycles ADD COLUMN appeal_deadline DATE DEFAULT NULL AFTER evaluation_deadline"); } catch (\Exception $e2) {} }
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS appeals (
            appeal_id INT AUTO_INCREMENT PRIMARY KEY,
            application_id INT NOT NULL,
            submission_id INT NOT NULL,
            faculty_id INT NOT NULL,
            checker_id INT NOT NULL,
            cycle_id INT NOT NULL,
            appeal_type ENUM('flag','score_change') NOT NULL,
            original_score DECIMAL(6,2) DEFAULT NULL,
            changed_score DECIMAL(6,2) DEFAULT NULL,
            checker_remark TEXT DEFAULT NULL,
            reason TEXT NOT NULL,
            status ENUM('open','under_review','resolved') DEFAULT 'open',
            outcome ENUM('upheld','revised','dismissed') DEFAULT NULL,
            is_read_by_faculty TINYINT(1) DEFAULT 1,
            is_read_by_checker TINYINT(1) DEFAULT 0,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            resolved_at TIMESTAMP NULL,
            resolved_by INT DEFAULT NULL,
            INDEX idx_appeals_submission_status (submission_id, status),
            INDEX idx_appeals_faculty (faculty_id, status),
            INDEX idx_appeals_checker (checker_id, status),
            FOREIGN KEY (application_id) REFERENCES applications(application_id) ON DELETE CASCADE,
            FOREIGN KEY (submission_id) REFERENCES kra_submissions(submission_id) ON DELETE CASCADE,
            FOREIGN KEY (faculty_id) REFERENCES users(user_id) ON DELETE CASCADE,
            FOREIGN KEY (checker_id) REFERENCES users(user_id) ON DELETE CASCADE,
            FOREIGN KEY (cycle_id) REFERENCES cycles(cycle_id) ON DELETE CASCADE,
            FOREIGN KEY (resolved_by) REFERENCES users(user_id) ON DELETE SET NULL
        )");
        $pdo->exec("CREATE TABLE IF NOT EXISTS appeal_messages (
            message_id INT AUTO_INCREMENT PRIMARY KEY,
            appeal_id INT NOT NULL,
            sender_id INT NOT NULL,
            message TEXT NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (appeal_id) REFERENCES appeals(appeal_id) ON DELETE CASCADE,
            FOREIGN KEY (sender_id) REFERENCES users(user_id) ON DELETE CASCADE
        )");
        $pdo->exec("CREATE TABLE IF NOT EXISTS appeal_attachments (
            attachment_id INT AUTO_INCREMENT PRIMARY KEY,
            message_id INT NOT NULL,
            file_path VARCHAR(255) NOT NULL,
            original_filename VARCHAR(255) NOT NULL,
            file_size_bytes INT DEFAULT 0,
            uploaded_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (message_id) REFERENCES appeal_messages(message_id) ON DELETE CASCADE
        )");
    } catch (\Exception $e) {}
}

function appealStatusLabel(array $appeal): string {
    if (($appeal['status'] ?? '') === 'resolved') {
        return 'Resolved' . (!empty($appeal['outcome']) ? ' - ' . ucfirst(str_replace('_', ' ', $appeal['outcome'])) : '');
    }
    return ($appeal['status'] ?? 'open') === 'under_review' ? 'Under Review' : 'Open';
}

function getUnreadNotifCount($pdo, int $user_id): int {
    try {
        $s = $pdo->prepare("SELECT COUNT(*) FROM notifications WHERE user_id=? AND is_read=0");
        $s->execute([$user_id]);
        return (int)$s->fetchColumn();
    } catch (\Exception $e) { return 0; }
}
