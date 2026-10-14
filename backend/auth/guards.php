<?php
// Role checks and page guards. Loaded by backend/core/functions.php.

function isLoggedIn(): bool { return isset($_SESSION['user_id']); }
function isAdmin():    bool { return ($_SESSION['role'] ?? '') === 'admin'; }
function isEvaluator(): bool { return ($_SESSION['role'] ?? '') === 'evaluator'; }
/** @deprecated Use isEvaluator() */
function isChecker():  bool { return isEvaluator(); }
function isFaculty():  bool { return ($_SESSION['role'] ?? '') === 'faculty'; }
/** @deprecated Use isEvaluator() */
function isTalisayChecker(): bool { return isEvaluator(); }

function requireLogin(): void {
    if (!isLoggedIn()) { header('Location: ' . BASE_URL . 'frontend/pages/login.php'); exit; }
}
function requireAdmin(): void {
    requireLogin();
    if (!isAdmin()) { header('Location: ' . BASE_URL . 'index.php'); exit; }
}
