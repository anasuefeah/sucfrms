<?php
// Schema/data migrations that run on every authenticated request (idempotent). Required by index.php.
defined('APP_ROOT') || exit;

// -- Runtime migration: add 'inactive' to users.status ENUM --
try {
    $pdo->exec("ALTER TABLE users MODIFY COLUMN status ENUM('active','inactive','rejected') DEFAULT 'active'");
} catch (\Exception $e) { /* already updated or not needed */ }

// -- Runtime migration: consolidate role ENUM to faculty/admin/evaluator --
try {
    // First rename any existing checker/talisay_checker/checker_faculty rows
    $pdo->exec("UPDATE users SET role='evaluator' WHERE role IN ('checker','talisay_checker','checker_faculty')");
    // Then update the ENUM definition
    $pdo->exec("ALTER TABLE users MODIFY COLUMN role ENUM('faculty','admin','evaluator') DEFAULT 'faculty'");
} catch (\Exception $e) { /* already updated */ }

// -- Runtime migration: split full_name into first_name / middle_name / last_name --
try {
    $cols = $pdo->query("SHOW COLUMNS FROM users LIKE 'first_name'")->fetchAll();
    if (empty($cols)) {
        $pdo->exec("ALTER TABLE users
            ADD COLUMN first_name  VARCHAR(100) NOT NULL DEFAULT '' AFTER user_id,
            ADD COLUMN middle_name VARCHAR(100) DEFAULT NULL        AFTER first_name,
            ADD COLUMN last_name   VARCHAR(100) NOT NULL DEFAULT '' AFTER middle_name");
    }
    // Re-parse any rows where last_name is empty but full_name has a comma
    // (i.e. columns exist but were back-filled with the whole full_name in first_name)
    $bad = $pdo->query("SELECT user_id, full_name FROM users WHERE last_name = '' AND full_name LIKE '%,%'")->fetchAll();
    if ($bad) {
        $upd = $pdo->prepare("UPDATE users SET first_name=?, middle_name=?, last_name=? WHERE user_id=?");
        foreach ($bad as $row) {
            $fn = trim($row['full_name'] ?? '');
            [$last, $rest] = explode(',', $fn, 2);
            $rest   = trim($rest);
            $parts  = explode(' ', $rest, 2);
            $first  = trim($parts[0] ?? '');
            $middle = trim($parts[1] ?? '') ?: null;
            $upd->execute([$first, $middle, trim($last), $row['user_id']]);
        }
    }
} catch (\Exception $e) { /* already migrated */ }

// Runtime migration: faculty profile-completion flow.
try { $pdo->query("SELECT suffix FROM users LIMIT 1"); }
catch (\Exception $e) { try { $pdo->exec("ALTER TABLE users ADD COLUMN suffix VARCHAR(30) DEFAULT NULL AFTER last_name"); } catch (\Exception $e2) {} }
try { $pdo->query("SELECT profile_completed FROM users LIMIT 1"); }
catch (\Exception $e) { try { $pdo->exec("ALTER TABLE users ADD COLUMN profile_completed TINYINT(1) DEFAULT 0 AFTER profile_pic"); } catch (\Exception $e2) {} }
try { $pdo->query("SELECT email_locked FROM users LIMIT 1"); }
catch (\Exception $e) { try { $pdo->exec("ALTER TABLE users ADD COLUMN email_locked TINYINT(1) DEFAULT 1 AFTER profile_completed"); } catch (\Exception $e2) {} }
try { $pdo->query("SELECT faculty_status FROM users LIMIT 1"); }
catch (\Exception $e) { try { $pdo->exec("ALTER TABLE users ADD COLUMN faculty_status ENUM('Existing Faculty','New Faculty') DEFAULT 'New Faculty' AFTER email_locked"); } catch (\Exception $e2) {} }
try { $pdo->query("SELECT applied_first_cycle FROM users LIMIT 1"); }
catch (\Exception $e) { try { $pdo->exec("ALTER TABLE users ADD COLUMN applied_first_cycle TINYINT(1) DEFAULT 0 AFTER faculty_status"); } catch (\Exception $e2) {} }
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS faculty_education (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        level ENUM('Bachelor','Master','Doctorate','PostDoctorate') NOT NULL,
        degree_program VARCHAR(255) NOT NULL,
        major_specialization VARCHAR(255) NOT NULL,
        school_university VARCHAR(255) NOT NULL,
        year_graduated VARCHAR(20) NOT NULL,
        honors_units_notes VARCHAR(255) DEFAULT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_faculty_education_user (user_id),
        FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE CASCADE
    )");
    $pdo->exec("ALTER TABLE faculty_education MODIFY COLUMN level ENUM('Bachelor','Master','Doctorate','PostDoctorate') NOT NULL");
    $pdo->exec("CREATE TABLE IF NOT EXISTS faculty_employment (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        type ENUM('current','previous') NOT NULL,
        rank VARCHAR(100) NOT NULL,
        mode_of_appointment VARCHAR(100) NOT NULL,
        date_of_appointment DATE DEFAULT NULL,
        employment_sector VARCHAR(150) DEFAULT NULL,
        suc VARCHAR(255) NOT NULL,
        campus VARCHAR(255) NOT NULL,
        address TEXT NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_faculty_employment_user_type (user_id, type),
        FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE CASCADE
    )");
    $pdo->exec("UPDATE users
        SET profile_completed = 1
        WHERE role = 'faculty'
          AND COALESCE(employee_id, '') <> ''
          AND campus_id IS NOT NULL
          AND COALESCE(rank, '') <> ''");
    $pdo->exec("UPDATE users SET email_locked = 1 WHERE role = 'faculty'");
} catch (\Exception $e) {}

// Runtime migration: cycle submission/evaluation date controls and extensions.
try { $pdo->query("SELECT submission_start_date FROM cycles LIMIT 1"); }
catch (\Exception $e) {
    try { $pdo->exec("ALTER TABLE cycles ADD COLUMN submission_start_date DATE DEFAULT NULL AFTER end_date"); } catch (\Exception $e2) {}
    try { $pdo->exec("UPDATE cycles SET submission_start_date=start_date WHERE submission_start_date IS NULL AND start_date IS NOT NULL"); } catch (\Exception $e2) {}
}
try { $pdo->query("SELECT evaluation_deadline FROM cycles LIMIT 1"); }
catch (\Exception $e) {
    try { $pdo->exec("ALTER TABLE cycles ADD COLUMN evaluation_deadline DATE DEFAULT NULL AFTER submission_deadline"); } catch (\Exception $e2) {}
    try { $pdo->exec("UPDATE cycles SET evaluation_deadline=end_date WHERE evaluation_deadline IS NULL AND end_date IS NOT NULL"); } catch (\Exception $e2) {}
}
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS cycle_submission_extensions (
        extension_id INT AUTO_INCREMENT PRIMARY KEY,
        cycle_id INT NOT NULL,
        faculty_user_id INT DEFAULT NULL,
        applies_to_all TINYINT(1) DEFAULT 0,
        previous_deadline DATE NOT NULL,
        new_deadline DATE NOT NULL,
        reason TEXT DEFAULT NULL,
        extended_by INT DEFAULT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_cycle_extensions_cycle (cycle_id),
        INDEX idx_cycle_extensions_faculty (faculty_user_id),
        FOREIGN KEY (cycle_id) REFERENCES cycles(cycle_id) ON DELETE CASCADE,
        FOREIGN KEY (faculty_user_id) REFERENCES users(user_id) ON DELETE CASCADE,
        FOREIGN KEY (extended_by) REFERENCES users(user_id) ON DELETE SET NULL
    )");
} catch (\Exception $e) {}

// Runtime migration: normalize legacy evaluator_* database columns to the
// checker_* names used by the application. CHANGE COLUMN preserves existing
// values, indexes, and foreign-key relationships while renaming the field.
$columnExists = static function (PDO $pdo, string $table, string $column): bool {
    $stmt = $pdo->prepare("
        SELECT COUNT(*)
        FROM information_schema.columns
        WHERE table_schema = DATABASE()
          AND table_name = ?
          AND column_name = ?
    ");
    $stmt->execute([$table, $column]);
    return (int)$stmt->fetchColumn() > 0;
};
$legacyEvaluatorColumns = [
    ['applications', 'evaluator_id', 'checker_id', 'INT DEFAULT NULL'],
    ['applications', 'evaluator_remarks', 'checker_remarks', 'TEXT DEFAULT NULL'],
    ['checker_kra_assignments', 'evaluator_id', 'checker_id', 'INT NOT NULL'],
    ['appeals', 'evaluator_id', 'checker_id', 'INT NOT NULL'],
    ['appeals', 'evaluator_remark', 'checker_remark', 'TEXT DEFAULT NULL'],
    ['appeals', 'is_read_by_evaluator', 'is_read_by_checker', 'TINYINT(1) DEFAULT 0'],
    ['application_checker_reviews', 'evaluator_id', 'checker_id', 'INT NOT NULL'],
    ['kra_checker_verifications', 'evaluator_id', 'checker_id', 'INT NOT NULL'],
];
foreach ($legacyEvaluatorColumns as [$table, $oldColumn, $newColumn, $definition]) {
    try {
        if ($columnExists($pdo, $table, $oldColumn)
            && !$columnExists($pdo, $table, $newColumn)) {
            $pdo->exec("ALTER TABLE `{$table}`
                CHANGE COLUMN `{$oldColumn}` `{$newColumn}` {$definition}");
        }
    } catch (\Exception $e) { /* table absent or already normalized */ }
}
unset($columnExists, $legacyEvaluatorColumns);

// Runtime migration: evaluator KRA assignments per cycle.
ensureKraAssignmentTable($pdo);
ensureAppealTables($pdo);

// -- Runtime migration: ensure applications.status ENUM includes all needed values --
try {
    $pdo->exec("ALTER TABLE applications MODIFY COLUMN status ENUM('draft','submitted','under_review','talisay_review','approved','rejected','reclassified','admin_rejected','edit_requested','needs_revision') DEFAULT 'draft'");
} catch (\Exception $e) { /* already updated */ }

// -- Runtime migration: create notifications table --
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS notifications (
        notif_id       INT AUTO_INCREMENT PRIMARY KEY,
        user_id        INT NOT NULL,
        type           VARCHAR(60) NOT NULL,
        message        TEXT NOT NULL,
        application_id INT DEFAULT NULL,
        is_read        TINYINT(1) DEFAULT 0,
        created_at     TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE CASCADE,
        FOREIGN KEY (application_id) REFERENCES applications(application_id) ON DELETE CASCADE
    )");
} catch (\Exception $e) { /* already exists */ }

// -- Runtime migration: notifications.submission_id  -  lets a "needs_revision"
//    notification deep-link straight to the flagged KRA entry --
try {
    $pdo->exec("ALTER TABLE notifications ADD COLUMN IF NOT EXISTS submission_id INT DEFAULT NULL AFTER application_id");
} catch (\Exception $e) { /* already exists / unsupported syntax on older MySQL */ }

// -- Runtime migration: create help_articles table --
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS help_articles (
        article_id   INT AUTO_INCREMENT PRIMARY KEY,
        title        VARCHAR(255) NOT NULL,
        content      TEXT NOT NULL,
        category     ENUM('help') NOT NULL DEFAULT 'help',
        is_published TINYINT(1) DEFAULT 1,
        created_by   INT DEFAULT NULL,
        created_at   TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at   TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        FOREIGN KEY (created_by) REFERENCES users(user_id) ON DELETE SET NULL
    )");
} catch (\Exception $e) { /* already exists */ }

// -- Runtime migration: create feedback_submissions table --
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS feedback_submissions (
        feedback_id   INT AUTO_INCREMENT PRIMARY KEY,
        user_id       INT DEFAULT NULL,
        contact_email VARCHAR(150) DEFAULT NULL,
        subject       VARCHAR(255) DEFAULT NULL,
        message       TEXT NOT NULL,
        rating        TINYINT UNSIGNED DEFAULT NULL,
        status        ENUM('new','read','resolved') DEFAULT 'new',
        submitted_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        resolved_by   INT DEFAULT NULL,
        resolved_at   TIMESTAMP NULL,
        FOREIGN KEY (user_id)     REFERENCES users(user_id) ON DELETE SET NULL,
        FOREIGN KEY (resolved_by) REFERENCES users(user_id) ON DELETE SET NULL
    )");
} catch (\Exception $e) { /* already exists */ }

// -- Runtime migration: JC01 s.2026  -  reset mentorship points to 0 (confirmed source gap) --
// kra1_c_mentor_competition had max_points = 3.00 (old hardcoded guess). Reset to 0.00 so
// the admin is prompted to confirm the value with CHED-RO before it scores anything.
try {
    $pdo->exec("UPDATE scoring_criteria
        SET max_points = 0.00
        WHERE criterion_key = 'kra1_c_mentor_competition'
          AND cycle_id IS NULL
          AND max_points = 3.00");
} catch (\Exception $e) { /* table may not exist yet */ }

// -- Runtime migration: JC01 s.2026  -  correct panel member point values --
// panel_masters: 2 -> 4 pts; panel_doctoral: 2 -> 6 pts per confirmed JC01 annex.
try {
    $pdo->exec("UPDATE scoring_criteria SET max_points = 4.00
        WHERE criterion_key = 'kra1_c_panel_masters' AND max_points = 2.00 AND cycle_id IS NULL");
    $pdo->exec("UPDATE scoring_criteria SET max_points = 6.00
        WHERE criterion_key = 'kra1_c_panel_doctoral' AND max_points = 2.00 AND cycle_id IS NULL");
} catch (\Exception $e) { /* already corrected or table missing */ }

// Runtime migration: de-duplicate scoring criteria and make NULL scopes unique.
// MySQL unique indexes allow multiple NULLs, so repeated seeds could create
// duplicate global/cycle-wide rows such as Criterion A SET/SEF.
try {
    $pdo->exec("DELETE sc FROM scoring_criteria sc
        JOIN (
            SELECT MIN(criteria_id) AS keep_id, cycle_id, position_rank, kra_category, criterion_label
            FROM scoring_criteria
            GROUP BY cycle_id, position_rank, kra_category, criterion_label
            HAVING COUNT(*) > 1
        ) dup
          ON sc.cycle_id <=> dup.cycle_id
         AND sc.position_rank <=> dup.position_rank
         AND sc.kra_category = dup.kra_category
         AND sc.criterion_label = dup.criterion_label
         AND sc.criteria_id <> dup.keep_id");

    $pdo->exec("DELETE sc FROM scoring_criteria sc
        JOIN (
            SELECT MIN(criteria_id) AS keep_id, cycle_id, position_rank, criterion_key
            FROM scoring_criteria
            GROUP BY cycle_id, position_rank, criterion_key
            HAVING COUNT(*) > 1
        ) dup
          ON sc.cycle_id <=> dup.cycle_id
         AND sc.position_rank <=> dup.position_rank
         AND sc.criterion_key = dup.criterion_key
         AND sc.criteria_id <> dup.keep_id");
} catch (\Exception $e) { /* table may not exist yet */ }
try {
    $pdo->exec("ALTER TABLE scoring_criteria
        ADD COLUMN cycle_scope INT GENERATED ALWAYS AS (IFNULL(cycle_id, 0)) STORED AFTER cycle_id");
} catch (\Exception $e) { /* already exists / unsupported */ }
try {
    $pdo->exec("ALTER TABLE scoring_criteria
        ADD COLUMN position_scope VARCHAR(100) GENERATED ALWAYS AS (IFNULL(position_rank, '')) STORED AFTER position_rank");
} catch (\Exception $e) { /* already exists / unsupported */ }
try {
    $pdo->exec("ALTER TABLE scoring_criteria
        ADD UNIQUE KEY uq_scoring_scope_key (cycle_scope, position_scope, criterion_key)");
} catch (\Exception $e) { /* already exists / unsupported */ }
try {
    $pdo->exec("ALTER TABLE scoring_criteria
        ADD UNIQUE KEY uq_scoring_scope_label (cycle_scope, position_scope, kra_category, criterion_label)");
} catch (\Exception $e) { /* already exists / unsupported */ }
