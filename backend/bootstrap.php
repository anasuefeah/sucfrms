<?php
// Common start-up for entry points: session, core helpers, database connection ($pdo).
if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__ . '/core/functions.php';
require_once __DIR__ . '/database/config/db.php';
