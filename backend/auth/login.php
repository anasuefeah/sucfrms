<?php
// Request handling and data loading for frontend/pages/login.php
if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__ . '/../database/config/db.php';
require_once __DIR__ . '/../core/functions.php';

if (isLoggedIn()) { header('Location: ../../index.php'); exit; }

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email    = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';

    if ($email && $password) {
        $stmt = $pdo->prepare("SELECT * FROM users WHERE email = ? LIMIT 1");
        $stmt->execute([$email]);
        $user = $stmt->fetch();

        if ($user && password_verify($password, $user['password'])) {
            $status = $user['status'] ?? 'active';
            if ($status === 'inactive') {
                $error = 'Your account has been deactivated. Contact the administrator.';
            } elseif ($status === 'rejected') {
                $error = 'Your account is unavailable. Contact the administrator.';
            } else {
                session_regenerate_id(true);
                $_SESSION['user_id']     = $user['user_id'];
                $_SESSION['first_name']  = $user['first_name']  ?? '';
                $_SESSION['middle_name'] = $user['middle_name'] ?? '';
                $_SESSION['last_name']   = $user['last_name']   ?? '';
                $_SESSION['full_name']   = formatDisplayName($user);
                $_SESSION['checker_label'] = $user['checker_label'] ?? null;
                $_SESSION['role']        = $user['role'];
                $_SESSION['email']       = $user['email'];
                $_SESSION['profile_pic'] = $user['profile_pic'] ?? '';
                logAudit($pdo, $user['user_id'], 'Login', 'User logged in successfully.');

                // Redirect faculty to the portal, everyone else to the dashboard
                $redirect = ($user['role'] === 'faculty') ? 'portal.php' : '../../index.php';
                header('Location: ' . $redirect);
                exit;
            }
        } else {
            $error = 'Invalid email or password.';
        }
    } else {
        $error = 'Please fill in all fields.';
    }
}
