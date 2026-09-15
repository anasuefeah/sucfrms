<?php
if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/functions.php';

if (isLoggedIn()) {
    try {
        logAudit($pdo, $_SESSION['user_id'], 'Logout', 'User logged out.');
    } catch (\Exception $e) {
        // Stale session user_id &mdash; ignore audit failure, proceed with logout
    }
}

session_unset();
session_destroy();

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: Thu, 01 Jan 1970 00:00:00 GMT');
header('Location: ../pages/login.php');
exit;

