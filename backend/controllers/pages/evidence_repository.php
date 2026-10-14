<?php
// Request handling and data loading for frontend/pages/evidence_repository.php
/**
 * Evidence Repository  -  standalone page.
 * Faculty store, organise, and manage evidence files across all KRA categories.
 */
if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__ . '/../../database/config/db.php';
require_once __DIR__ . '/../../core/functions.php';

if (!isLoggedIn()) { header('Location: login.php'); exit; }
if (($_SESSION['role'] ?? '') !== 'faculty') {
    header('Location: ../../index.php'); exit;
}

unset($_SESSION['force_pw_change']);

$uid = $_SESSION['user_id'];

// Runtime migration
try { $pdo->query("SELECT file_id FROM pre_eval_files LIMIT 1"); }
catch (\Exception $e) {
    $pdo->exec("CREATE TABLE IF NOT EXISTS pre_eval_files (file_id INT AUTO_INCREMENT PRIMARY KEY, user_id INT NOT NULL, entry_id INT DEFAULT NULL, kra_category ENUM('Instruction','Research','Extension','Professional Development','Auto Sub Rank','Position Requirements') NOT NULL, file_path VARCHAR(255) NOT NULL, original_filename VARCHAR(255) NOT NULL, file_size_bytes INT DEFAULT 0, description VARCHAR(255) DEFAULT NULL, uploaded_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE CASCADE)");
}
// Expand ENUM if table already exists without the new categories
try {
    $col = $pdo->query("SHOW COLUMNS FROM pre_eval_files LIKE 'kra_category'")->fetch();
    if ($col && strpos($col['Type'], 'Auto Sub Rank') === false) {
        $pdo->exec("ALTER TABLE pre_eval_files MODIFY kra_category ENUM('Instruction','Research','Extension','Professional Development','Auto Sub Rank','Position Requirements') NOT NULL");
    }
} catch (\Exception $e) {}

// Faculty info
$fac = $pdo->prepare("SELECT full_name, first_name, last_name, middle_name, profile_pic FROM users WHERE user_id=?");
$fac->execute([$uid]);
$fac         = $fac->fetch();
$full_name = formatDisplayName($fac);
$profile_pic = $fac['profile_pic'] ?? '';
$init        = strtoupper(substr($fac['first_name']??'U',0,1).substr($fac['last_name']??'',0,1)) ?: 'FA';

// File stats per KRA
$cats = ['Instruction','Research','Extension','Professional Development','Auto Sub Rank','Position Requirements'];
$stats = [];
foreach ($cats as $c) {
    $s = $pdo->prepare("SELECT COUNT(*) as cnt, COALESCE(SUM(file_size_bytes),0) as total_size FROM pre_eval_files WHERE user_id=? AND kra_category=?");
    $s->execute([$uid, $c]);
    $stats[$c] = $s->fetch();
}
$total_files = array_sum(array_column($stats, 'cnt'));
$total_size  = array_sum(array_column($stats, 'total_size'));
