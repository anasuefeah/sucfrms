<?php
if (session_status() === PHP_SESSION_NONE) session_start();
require_once 'includes/functions.php';

// -- AJAX: mark KRA submission acceptable  -  intercept BEFORE any header is sent --
if (
    isset($_SERVER['HTTP_X_REQUESTED_WITH']) &&
    $_SERVER['HTTP_X_REQUESTED_WITH'] === 'XMLHttpRequest' &&
    in_array(($_POST['action'] ?? ''), ['verify_kra', 'verify_submission'], true)
) {
    require_once 'config/db.php';
    if (!isLoggedIn()) { http_response_code(403); echo json_encode(['ok'=>false,'error'=>'Unauthorized']); exit; }
    header('Content-Type: application/json');

    $app_id = intval($_GET['id'] ?? 0);
    $sub_id = intval($_POST['submission_id'] ?? 0);
    $note   = trim($_POST['note'] ?? '');
    $uid    = $_SESSION['user_id'];

    if (!$app_id || !$sub_id) {
        echo json_encode(['ok' => false, 'error' => 'Invalid request.']);
        exit;
    }

    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS kra_checker_verifications (
            ckv_id        INT AUTO_INCREMENT PRIMARY KEY,
            submission_id INT NOT NULL,
            checker_id    INT NOT NULL,
            verified_at   TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uq_sub_checker (submission_id, checker_id),
            FOREIGN KEY (submission_id) REFERENCES kra_submissions(submission_id) ON DELETE CASCADE,
            FOREIGN KEY (checker_id)    REFERENCES users(user_id) ON DELETE CASCADE
        )");
    } catch (\Exception $e) {}
    try { $pdo->query("SELECT checker_note FROM kra_submissions LIMIT 1"); }
    catch (\Exception $e) {
        try { $pdo->exec("ALTER TABLE kra_submissions ADD COLUMN checker_note TEXT DEFAULT NULL"); }
        catch (\Exception $e2) {}
    }
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS application_checker_reviews (
            review_id      INT AUTO_INCREMENT PRIMARY KEY,
            application_id INT NOT NULL,
            checker_id     INT NOT NULL,
            decision       ENUM('approved','rejected','pending') DEFAULT 'pending',
            remarks        TEXT,
            decided_at     TIMESTAMP NULL,
            created_at     TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uq_app_checker (application_id, checker_id),
            FOREIGN KEY (application_id) REFERENCES applications(application_id) ON DELETE CASCADE,
            FOREIGN KEY (checker_id)     REFERENCES users(user_id) ON DELETE CASCADE
        )");
    } catch (\Exception $e) {}

    $app_row = $pdo->prepare("SELECT a.application_id, a.status, a.cycle_id, a.checker_id, a.user_id, u.full_name
        FROM applications a
        JOIN users u ON u.user_id = a.user_id
        WHERE a.application_id = ?");
    $app_row->execute([$app_id]);
    $app_row = $app_row->fetch();

    if (!$app_row) {
        echo json_encode(['ok' => false, 'error' => 'Application not found.']);
        exit;
    }

    if (!in_array($app_row['status'], ['under_review', 'talisay_review', 'needs_revision'], true)) {
        echo json_encode(['ok' => false, 'error' => 'This application is not under active review.']);
        exit;
    }

    $sub_row = $pdo->prepare("SELECT submission_id, kra_category, computed_points FROM kra_submissions WHERE submission_id = ? AND application_id = ?");
    $sub_row->execute([$sub_id, $app_id]);
    $sub_row = $sub_row->fetch();
    if (!$sub_row) {
        echo json_encode(['ok' => false, 'error' => 'KRA submission not found.']);
        exit;
    }

    if (!isAdmin()) {
        ensureKraAssignmentTable($pdo);
        if (!checkerCanReviewKra($pdo, (int)$uid, (int)$app_row['cycle_id'], $sub_row['kra_category'])) {
            http_response_code(403);
            echo json_encode(['ok' => false, 'error' => 'You are not assigned to review that KRA for this cycle.']);
            exit;
        }
    }

    try {
        $pdo->prepare("INSERT IGNORE INTO application_checker_reviews
            (application_id, checker_id, decision) VALUES (?, ?, 'pending')")
            ->execute([$app_id, $uid]);
    } catch (\Exception $e) {}

    $pdo->prepare("INSERT IGNORE INTO kra_checker_verifications (submission_id, checker_id) VALUES (?, ?)")
        ->execute([$sub_id, $uid]);
    $pdo->prepare("UPDATE kra_submissions
        SET faculty_original_score = COALESCE(faculty_original_score, computed_points),
            checker_note = ?
        WHERE submission_id = ? AND application_id = ?")
        ->execute([$note ?: null, $sub_id, $app_id]);

    $total_ck = (isAdmin() || isTalisayChecker())
        ? 1
        : countAssignedCheckersForKra($pdo, (int)$app_row['cycle_id'], $sub_row['kra_category']);
    $total_ck = max(1, $total_ck);
    $done_stmt = $pdo->prepare("SELECT COUNT(*) FROM kra_checker_verifications WHERE submission_id = ?");
    $done_stmt->execute([$sub_id]);
    $done_ck = (int)$done_stmt->fetchColumn();
    $all_verified = ($total_ck > 0 && $done_ck >= $total_ck);
    if ($all_verified) {
        $pdo->prepare("UPDATE kra_submissions SET verified = 1, verified_by = ?, verified_at = NOW() WHERE submission_id = ? AND application_id = ?")
            ->execute([$uid, $sub_id, $app_id]);
    }

    logAudit($pdo, $uid, 'Evidence Marked Acceptable', "You marked {$app_row['full_name']}'s {$sub_row['kra_category']} entry acceptable." . ($note ? " Note: {$note}" : ''));

    echo json_encode([
        'ok' => true,
        'submission_id' => $sub_id,
        'done_count' => $done_ck,
        'total_count' => $total_ck,
        'all_verified' => $all_verified,
    ]);
    exit;
}

header('Content-Type: text/html; charset=UTF-8');

// Ping endpoint  -  used by back-button detection to check session validity
if (isset($_GET['ping'])) {
    if (!isLoggedIn()) {
        http_response_code(401);
    }
    exit;
}

requireLogin();
require_once 'config/db.php';

// One-time cleanup: remove new_submission notifs from talisay_checker accounts
// (these were incorrectly sent before role filtering was added)
if (empty($_SESSION['_notif_cleanup_done'])) {
    try {
        $pdo->exec("DELETE n FROM notifications n
            JOIN users u ON n.user_id = u.user_id
            WHERE u.role = 'talisay_checker'
              AND n.type IN ('new_submission', 'revision_resubmitted')");
        $pdo->exec("DELETE n FROM notifications n
            JOIN users u ON n.user_id = u.user_id
            WHERE u.role = 'checker'
              AND n.type = 'new_talisay_submission'");
    } catch (\Exception $e) {}
    $_SESSION['_notif_cleanup_done'] = true;
}

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
        // new_submission = first-stage reviewer notifications only
        // new_talisay_submission = talisay checker notifications only
        // Build an exclusion list based on role
        $excluded_types = [];
        if ($role_notif === 'talisay_checker') {
            // ITC reviewers should NOT see new_submission (that's for first-stage reviewers)
            $excluded_types[] = 'new_submission';
            $excluded_types[] = 'revision_resubmitted';
        } elseif ($role_notif === 'checker') {
            // Campus checkers should NOT see new_talisay_submission (that's for talisay only)
            $excluded_types[] = 'new_talisay_submission';
        }
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

// -- Force password change if temp password is still active --
unset($_SESSION['force_pw_change']);

// -- Runtime migration: add 'inactive' to users.status ENUM --
try {
    $pdo->exec("ALTER TABLE users MODIFY COLUMN status ENUM('active','inactive','rejected') DEFAULT 'active'");
} catch (\Exception $e) { /* already updated or not needed */ }

// -- Runtime migration: add 'talisay_checker' to users.role ENUM --
try {
    $pdo->exec("ALTER TABLE users MODIFY COLUMN role ENUM('faculty','checker','admin','talisay_checker') DEFAULT 'faculty'");
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

// -- AJAX: submit feedback --
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

if (isset($_GET['action']) && $_GET['action'] === 'submit_feedback') {
    header('Content-Type: application/json');
    $subject = trim($_POST['subject'] ?? '');
    $message = trim($_POST['message'] ?? '');
    $rating  = isset($_POST['rating']) && is_numeric($_POST['rating'])
                ? min(5, max(1, (int)$_POST['rating'])) : null;

    if ($message === '') {
        echo json_encode(['ok' => false, 'error' => 'Message is required.']);
        exit;
    }
    try {
        $stmt = $pdo->prepare("INSERT INTO feedback_submissions (user_id, subject, message, rating) VALUES (?,?,?,?)");
        $stmt->execute([$_SESSION['user_id'], $subject ?: null, $message, $rating]);
        logAudit($pdo, $_SESSION['user_id'], 'Feedback Submitted', "Subject: {$subject}");
        echo json_encode(['ok' => true]);
    } catch (\Exception $e) {
        echo json_encode(['ok' => false, 'error' => 'Could not save feedback.']);
    }
    exit;
}



// Prevent browser from caching authenticated pages
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: Thu, 01 Jan 1970 00:00:00 GMT');

$role = $_SESSION['role'] ?? 'faculty';
$page = $_GET['page'] ?? 'dashboard';

// Always sync role, status and name from DB on every page load
$fresh = $pdo->prepare("SELECT role, status, first_name, middle_name, last_name, profile_pic, profile_completed FROM users WHERE user_id = ?");
$fresh->execute([$_SESSION['user_id']]);
$fresh = $fresh->fetch();
if ($fresh) {
    if ($fresh['status'] !== 'active') {
        session_destroy();
        header('Location: pages/login.php');
        exit;
    }
    if ($fresh['role'] !== $_SESSION['role']) {
        $_SESSION['role'] = $fresh['role'];
    }
    $role = $_SESSION['role'];
    // Keep name parts in session fresh so sidebar/welcome always show correct format
    $_SESSION['first_name']  = $fresh['first_name']  ?? '';
    $_SESSION['middle_name'] = $fresh['middle_name'] ?? '';
    $_SESSION['last_name']   = $fresh['last_name']   ?? '';
    $_SESSION['full_name']   = formatDisplayName($fresh);
    if (!empty($fresh['profile_pic'])) {
        $_SESSION['profile_pic'] = $fresh['profile_pic'];
    }
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

// Role-based allowed pages
$faculty_pages         = ['dashboard','my_application','my_audit','profile','profile_entry','apply','score_comparison','appeals','help'];
$checker_pages         = ['dashboard','review_queue','review_application','appeals','checker_audit','profile','help'];
$talisay_pages         = ['dashboard','review_queue','review_application','checker_audit','profile','help'];
$admin_pages           = ['dashboard','manage_users','audit','cycles','config','analytics','profile','all_applications','manage_campuses','view_application','appeals','help','feedback'];

$allowed = match($role) {
    'admin'            => $admin_pages,
    'checker'          => $checker_pages,
    'talisay_checker'  => $talisay_pages,
    default            => $faculty_pages,
};

if (!in_array($page, $allowed)) $page = 'dashboard';

$faculty_profile_incomplete = ($role === 'faculty' && empty($fresh['profile_completed']));
if ($faculty_profile_incomplete && $page !== 'profile_entry') {
    header('Location: index.php?page=profile_entry');
    exit;
}

include 'includes/header.php';
?>

<div class="container-fluid main-content" style="padding-left:264px;padding-right:0;padding-top:0;padding-bottom:1.5rem;margin-top:0;">
    <div class="row g-0">

        <!-- -- Sidebar -- -->
        <div id="sidebarCol" class="col-auto d-none d-md-block">
            <div id="sidebarCard">
                <!-- Profile -->
                <div class="sidebar-profile">
                    <?php if ($role === 'admin'): ?>
                    <img src="assets/images/logo.jpg" alt="SUCFRMS Logo"
                         style="width:44px;height:44px;border-radius:50%;object-fit:cover;border:2px solid var(--gold-500);clip-path:circle(50%);flex-shrink:0;">
                    <?php else:
                        $pic = $_SESSION['profile_pic'] ?? '';
                        if ($pic): ?>
                    <img src="<?= sanitize($pic) ?>" alt="Profile"
                         style="width:44px;height:44px;border-radius:50%;object-fit:cover;border:2px solid rgba(255,255,255,.2);flex-shrink:0;">
                    <?php else:
                        $init_s = strtoupper(
                            substr($_SESSION['first_name'] ?? '', 0, 1) .
                            substr($_SESSION['last_name']  ?? '', 0, 1)
                        ) ?: 'U';
                    ?>
                    <div style="width:44px;height:44px;border-radius:50%;background:linear-gradient(135deg,var(--navy-600),var(--navy-700));display:flex;align-items:center;justify-content:center;font-size:.95rem;font-weight:700;color:#fff;overflow:hidden;clip-path:circle(50%);flex-shrink:0;">
                        <?= $init_s ?>
                    </div>
                    <?php endif; endif; ?>
                    <div class="min-w-0">
                        <p class="sidebar-profile-name text-truncate"><?= htmlspecialchars($_SESSION['full_name'] ?? '') ?></p>
                        <span class="badge <?= $role === 'admin' ? 'bg-warning text-dark' : ($role === 'checker' ? 'bg-success' : 'bg-info') ?>">
                            <?= ucfirst(str_replace(['talisay_checker','checker'], ['ITC Evaluator','Evaluator'], $role)) ?>
                        </span>
                    </div>
                </div>
                <div class="sidebar-divider"></div>
                <div class="sidebar-nav-scroll">
                <nav class="nav flex-column">
                    <?php if (!($role === 'faculty' && $faculty_profile_incomplete)): ?>
                    <a href="?page=dashboard" class="nav-link sidebar-link <?= $page==='dashboard'?'active':'' ?>">
                        <i class="bi bi-speedometer2 me-2"></i>Dashboard
                    </a>
                    <?php endif; ?>

                    <?php if ($role === 'faculty'): ?>
                    <?php if ($faculty_profile_incomplete): ?>
                    <div style="padding:0.7rem 0.75rem;margin-bottom:0.55rem;background:rgba(255,255,255,0.08);border:1px solid rgba(255,255,255,0.12);border-radius:8px;color:rgba(255,255,255,0.78);font-size:0.72rem;line-height:1.45;">
                        <i class="bi bi-lock-fill me-1"></i>Please complete your profile to continue.
                    </div>
                    <a href="?page=profile_entry" class="nav-link sidebar-link <?= $page==='profile_entry'?'active':'' ?>">
                        <i class="bi bi-person-lines-fill me-2"></i>Profile Entry
                    </a>
                    <?php else: ?>
                    <div class="sidebar-section-label">My Application</div>
                    <a href="?page=my_application" class="nav-link sidebar-link <?= $page==='my_application'?'active':'' ?>">
                        <i class="bi bi-file-earmark-person me-2"></i>Application Status
                    </a>
                    <a href="?page=apply" class="nav-link sidebar-link <?= $page==='apply'?'active':'' ?>">
                        <i class="bi bi-ui-checks-grid me-2"></i>Apply / KRA Entry
                    </a>
                    <a href="?page=appeals" class="nav-link sidebar-link <?= $page==='appeals'?'active':'' ?>">
                        <i class="bi bi-chat-square-text me-2"></i>Appeals
                    </a>
                    <div class="sidebar-section-label">Account</div>
                    <a href="?page=my_audit" class="nav-link sidebar-link <?= $page==='my_audit'?'active':'' ?>">
                        <i class="bi bi-clock-history me-2"></i>My Activity Log
                    </a>
                    <a href="?page=profile" class="nav-link sidebar-link <?= $page==='profile'?'active':'' ?>">
                        <i class="bi bi-person me-2"></i>Profile
                    </a>
                    <?php endif; ?>

                    <?php elseif ($role === 'checker'): ?>
                    <div class="sidebar-section-label">Review</div>
                    <a href="?page=review_queue" class="nav-link sidebar-link <?= $page==='review_queue'?'active':'' ?>">
                        <i class="bi bi-inbox me-2"></i>Review Queue
                    </a>
                    <a href="?page=appeals" class="nav-link sidebar-link <?= $page==='appeals'?'active':'' ?>">
                        <i class="bi bi-chat-square-text me-2"></i>Appeals
                    </a>
                    <div class="sidebar-section-label">Account</div>
                    <a href="?page=checker_audit" class="nav-link sidebar-link <?= $page==='checker_audit'?'active':'' ?>">
                        <i class="bi bi-clock-history me-2"></i>My Activity Log
                    </a>
                    <a href="?page=profile" class="nav-link sidebar-link <?= $page==='profile'?'active':'' ?>">
                        <i class="bi bi-person me-2"></i>Profile
                    </a>

                    <?php elseif ($role === 'talisay_checker'): ?>
                    <div class="sidebar-section-label">ITC Review</div>
                    <a href="?page=review_queue" class="nav-link sidebar-link <?= $page==='review_queue'?'active':'' ?>">
                        <i class="bi bi-inbox-fill me-2"></i>Review Queue
                    </a>
                    <div class="sidebar-section-label">Account</div>
                    <a href="?page=checker_audit" class="nav-link sidebar-link <?= $page==='checker_audit'?'active':'' ?>">
                        <i class="bi bi-clock-history me-2"></i>My Activity Log
                    </a>
                    <a href="?page=profile" class="nav-link sidebar-link <?= $page==='profile'?'active':'' ?>">
                        <i class="bi bi-person me-2"></i>Profile
                    </a>

                    <?php elseif ($role === 'admin'): ?>
                    <div class="sidebar-section-label">Applications</div>
                    <a href="?page=all_applications" class="nav-link sidebar-link <?= $page==='all_applications'?'active':'' ?>">
                        <i class="bi bi-ui-checks-grid me-2"></i>All Applications
                    </a>
                    <a href="?page=appeals" class="nav-link sidebar-link <?= $page==='appeals'?'active':'' ?>">
                        <i class="bi bi-chat-square-text me-2"></i>Appeals
                    </a>
                    <div class="sidebar-section-label">Administration</div>
                    <a href="?page=analytics" class="nav-link sidebar-link <?= $page==='analytics'?'active':'' ?>">
                        <i class="bi bi-graph-up me-2"></i>Analytics
                    </a>
                    <?php
                    $setup_pages = ['manage_users','manage_campuses','cycles','config'];
                    $setup_active = in_array($page, $setup_pages);
                    ?>
                    <div class="sidebar-group <?= $setup_active ? 'open' : '' ?>">
                        <button type="button" class="nav-link sidebar-link sidebar-group-toggle w-100 text-start"
                                onclick="this.closest('.sidebar-group').classList.toggle('open')"
                                style="background:none;border:none;cursor:pointer;">
                            <i class="bi bi-sliders me-2"></i>System Setup
                            <i class="bi bi-chevron-down ms-auto sidebar-chevron" style="font-size:0.65rem;transition:transform 0.2s;"></i>
                        </button>
                        <div class="sidebar-submenu">
                            <a href="?page=cycles" class="nav-link sidebar-link sidebar-sublink <?= in_array($page,['cycles','config'])?'active':'' ?>">
                                <i class="bi bi-calendar-range me-2"></i>Cycles &amp; Criteria
                            </a>
                            <a href="?page=manage_users" class="nav-link sidebar-link sidebar-sublink <?= $page==='manage_users'?'active':'' ?>">
                                <i class="bi bi-people-fill me-2"></i>Manage Users
                            </a>
                            <a href="?page=manage_campuses" class="nav-link sidebar-link sidebar-sublink <?= $page==='manage_campuses'?'active':'' ?>">
                                <i class="bi bi-geo-alt me-2"></i>Manage Campuses
                            </a>
                        </div>
                    </div>
                    <a href="?page=audit" class="nav-link sidebar-link <?= $page==='audit'?'active':'' ?>">
                        <i class="bi bi-shield-check me-2"></i>Audit Logs
                    </a>
                    <a href="?page=feedback" class="nav-link sidebar-link <?= $page==='feedback'?'active':'' ?>">
                        <i class="bi bi-chat-square-text me-2"></i>Feedback
                        <?php
                        try {
                            $fb_new = $pdo->query("SELECT COUNT(*) FROM feedback_submissions WHERE status='new'")->fetchColumn();
                            if ($fb_new > 0) echo '<span class="badge rounded-pill ms-auto" style="background:#334155;font-size:0.65rem;padding:0.25em 0.5em;">'.$fb_new.'</span>';
                        } catch(\Exception $e) {}
                        ?>
                    </a>
                    <div class="sidebar-section-label">Account</div>
                    <a href="?page=profile" class="nav-link sidebar-link <?= $page==='profile'?'active':'' ?>">
                        <i class="bi bi-person me-2"></i>Profile
                    </a>
                    <?php endif; ?>
                </nav>
                </div>

                <div class="sidebar-bottom">
                    <div class="sidebar-divider" style="margin:0 0 .75rem;"></div>
                    <?php if ($role === 'faculty'): ?>
                    <a href="pages/portal.php"
                       style="display:flex;align-items:center;gap:0.4rem;padding:0.3rem 0.75rem;
                              margin-bottom:0.4rem;border-radius:6px;text-decoration:none;
                              font-size:0.7rem;font-weight:600;color:#64748b;
                              transition:background 0.15s,color 0.15s;"
                       onmouseover="this.style.background='rgba(0,0,0,0.04)';this.style.color='#1a3a6b'"
                       onmouseout="this.style.background='';this.style.color='#64748b'">
                        <i class="bi bi-grid-1x2" style="font-size:0.7rem;"></i>
                        My Portal
                    </a>
                    <?php endif; ?>
                    <div class="sidebar-clock">
                        <span class="sidebar-live"><span class="sidebar-live-dot"></span>Live</span>
                        <div class="sidebar-datetime">
                            <div class="sidebar-date" id="sidebarDate">&nbsp;</div>
                            <div class="sidebar-time" id="sidebarTime">&nbsp;</div>
                        </div>
                    </div>
                    <div class="sidebar-version">SUCFRMS v1.0</div>
                    <a href="#" class="sidebar-signout"
                       onclick="document.getElementById('logout-modal').style.display='flex'; return false;">
                        <i class="bi bi-box-arrow-right"></i>Sign Out
                    </a>
                </div>
            </div>
        </div>

        <!-- -- Main Content -- -->
        <div class="col-12 min-w-0">
            <?php showFlash(); ?>
            <?php switch ($page) {
                case 'dashboard':
                    $dash_role = $role === 'talisay_checker' ? 'talisay_checker' : $role;
                    include 'includes/dashboards/' . $dash_role . '_dashboard.php';
                    break;
                case 'my_application':
                    include 'includes/faculty/my_application.php';
                    break;
                case 'score_comparison':
                    include 'includes/faculty/score_comparison.php';
                    break;
                case 'apply':
                    if ($role === 'faculty') include 'modules/apply.php';
                    break;
                case 'my_audit':
                case 'checker_audit':
                    include 'includes/audit_log.php';
                    break;
                case 'review_queue':
                    include 'includes/checker/review_queue.php';
                    break;
                case 'review_application':
                    include 'includes/checker/review_application.php';
                    break;
                case 'appeals':
                    include 'includes/appeals.php';
                    break;
                case 'all_applications':
                    include 'admin/all_applications.php';
                    break;
                case 'view_application':
                    include 'includes/checker/review_application.php';
                    break;
                case 'manage_users':
                    include 'admin/manage_users.php';
                    break;
                case 'manage_campuses':
                    include 'admin/manage_campuses.php';
                    break;
                case 'audit':
                    include 'includes/audit_log.php';
                    break;
                case 'cycles':
                    include 'admin/cycles.php';
                    break;
                case 'config':
                    // Redirect legacy config links to the merged cycles module
                    $redir_cid = intval($_GET['cycle_id'] ?? 0);
                    echo "<script>window.location.replace('index.php?page=cycles" . ($redir_cid ? "&cycle_id={$redir_cid}" : '') . "');</script>";
                    break;
                case 'analytics':
                    include 'admin/analytics.php';
                    break;
                case 'feedback':
                    include 'admin/feedback.php';
                    break;
                case 'profile':
                    include 'includes/profile.php';
                    break;
                case 'profile_entry':
                    include 'includes/faculty/profile_entry.php';
                    break;
                case 'help':
                    include 'includes/help.php';
                    break;
                default:
                    break;
            }
            ?>
        </div>
    </div>
</div>

<?php include 'includes/footer.php'; ?>
