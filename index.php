<?php
require_once 'backend/bootstrap.php';

require 'backend/api/verify_kra.php';

header('Content-Type: text/html; charset=UTF-8');

// Ping endpoint  -  used by back-button detection to check session validity
if (isset($_GET['ping'])) {
    if (!isLoggedIn()) {
        http_response_code(401);
    }
    exit;
}

requireLogin();

require 'backend/database/data_fixes.php';

require 'backend/api/notifications.php';

// -- Force password change if temp password is still active --
unset($_SESSION['force_pw_change']);

require 'backend/database/runtime_migrations.php';

require 'backend/api/feedback.php';


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
        header('Location: frontend/pages/login.php');
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


// Role-based allowed pages
$faculty_pages   = ['dashboard','my_application','my_audit','profile','profile_entry','apply','score_comparison','appeals','help'];
$evaluator_pages = ['dashboard','review_queue','review_application','appeals','checker_audit','profile','help'];
$admin_pages     = ['dashboard','manage_users','audit','cycles','config','analytics','profile','all_applications','manage_campuses','view_application','appeals','help','feedback'];

$allowed = match($role) {
    'admin'     => $admin_pages,
    'evaluator' => $evaluator_pages,
    default     => $faculty_pages,
};

if (!in_array($page, $allowed)) $page = 'dashboard';

$faculty_profile_incomplete = ($role === 'faculty' && empty($fresh['profile_completed']));
if ($faculty_profile_incomplete && $page !== 'profile_entry') {
    header('Location: index.php?page=profile_entry');
    exit;
}

include 'frontend/layouts/header.php';
?>

<?php include 'frontend/layouts/sidebar.php'; ?>

<!-- -- Main Content -- -->
<main id="mainContent" class="main-content min-w-0 pt-16 md:pl-64">
    <div class="mx-auto w-full max-w-[1440px] px-4 py-6 sm:px-6 lg:px-8 lg:py-8">
            <?php showFlash(); ?>
            <?php switch ($page) {
                case 'dashboard':
                    include 'frontend/views/' . $role . '/dashboard.php';
                    break;
                case 'my_application':
                    include 'frontend/views/faculty/my_application.php';
                    break;
                case 'score_comparison':
                    include 'frontend/views/faculty/score_comparison.php';
                    break;
                case 'apply':
                    if ($role === 'faculty') include 'frontend/views/faculty/apply.php';
                    break;
                case 'my_audit':
                case 'checker_audit':
                    include 'frontend/views/shared/audit_log.php';
                    break;
                case 'review_queue':
                    include 'frontend/views/evaluator/review_queue.php';
                    break;
                case 'review_application':
                    include 'frontend/views/shared/review_application.php';
                    break;
                case 'appeals':
                    include 'frontend/views/shared/appeals.php';
                    break;
                case 'all_applications':
                    include 'frontend/views/admin/all_applications.php';
                    break;
                case 'view_application':
                    include 'frontend/views/shared/review_application.php';
                    break;
                case 'manage_users':
                    include 'frontend/views/admin/manage_users.php';
                    break;
                case 'manage_campuses':
                    include 'frontend/views/admin/manage_campuses.php';
                    break;
                case 'audit':
                    include 'frontend/views/shared/audit_log.php';
                    break;
                case 'cycles':
                    include 'frontend/views/admin/cycles.php';
                    break;
                case 'config':
                    // Redirect legacy config links to the merged cycles module
                    $redir_cid = intval($_GET['cycle_id'] ?? 0);
                    echo "<script>window.location.replace('index.php?page=cycles" . ($redir_cid ? "&cycle_id={$redir_cid}" : '') . "');</script>";
                    break;
                case 'analytics':
                    include 'frontend/views/admin/analytics.php';
                    break;
                case 'feedback':
                    include 'frontend/views/admin/feedback.php';
                    break;
                case 'profile':
                    include 'frontend/views/shared/profile.php';
                    break;
                case 'profile_entry':
                    include 'frontend/views/faculty/profile_entry.php';
                    break;
                case 'help':
                    include 'frontend/views/shared/help.php';
                    break;
                default:
                    break;
            }
            ?>
    </div>
</main>

<?php include 'frontend/layouts/footer.php'; ?>
