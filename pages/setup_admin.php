<?php
/**
 * FIRST-TIME SETUP � Run once to create the admin account, then DELETE this file.
 * Access: http://localhost/SUCFRMS/pages/setup_admin.php
 *;
 */
require_once __DIR__ . '/../config/db.php';

// Also allow promoting an existing user by email
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && !empty($_POST['promote_email'])) {
    $email = trim($_POST['promote_email']);
    $stmt  = $pdo->prepare("UPDATE users SET role = 'admin' WHERE email = ?");
    $stmt->execute([$email]);
    $affected = $stmt->rowCount();
    echo "<p style='color:" . ($affected ? 'lime' : 'orange') . "; font-family:monospace;'>"
       . ($affected ? "? User '{$email}' promoted to admin." : "? No user found with that email.")
       . "</p>";
}

// Create default admin
$email    = 'admin@chmsuft.edu.ph';
$password = 'Admin@1234';
$hash     = password_hash($password, PASSWORD_DEFAULT);

$stmt = $pdo->prepare("INSERT INTO users (first_name, middle_name, last_name, email, password, role, status, employee_id)
    VALUES ('System', NULL, 'Administrator', ?, ?, 'admin', 'active', 'ADMIN-001')
    ON DUPLICATE KEY UPDATE email = VALUES(email), password = VALUES(password), role = 'admin', status = 'active'");
$stmt->execute([$email, $hash]);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>SUCFRMS Setup</title>
    <style>
        body { font-family: monospace; background: #0d0d0d; color: #7FFFD4; padding: 2rem; }
        input { background:#1a1a2e; border:1px solid #008080; color:#fff; padding:0.4rem 0.8rem; border-radius:4px; }
        button { background:transparent; border:2px solid #7FFFD4; color:#7FFFD4; padding:0.4rem 1rem; border-radius:4px; cursor:pointer; }
        .box { border:1px solid #008080; border-radius:8px; padding:1.5rem; max-width:500px; margin-bottom:1.5rem; }
        .warn { color: #f87171; }
    </style>
</head>
<body>
<div class="box">
    <h3>? Default Admin Account Ready</h3>
    <p>Email: <strong><?= $email ?></strong></p>
    <p>Password: <strong><?= $password ?></strong></p>
    <p class="warn">? DELETE this file immediately after use!</p>
</div>

<div class="box">
    <h3>Promote Existing User to Admin</h3>
    <p style="color:#9ca3af; font-size:0.85rem;">If you already registered via the register page, enter your email below to promote your account to admin.</p>
    <form method="POST">
        <input type="email" name="promote_email" placeholder="your@email.com" required style="width:100%; margin-bottom:0.75rem;">
        <button type="submit">Promote to Admin</button>
    </form>
</div>

<p class="warn">Remember: DELETE pages/setup_admin.php after you're done.</p>
</body>
</html>

