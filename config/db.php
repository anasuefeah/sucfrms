<?php
// ── Database credentials ──────────────────────────────────────
// For live deployment: set these to your hosting provider's DB details.
// For local XAMPP: defaults below work out of the box.
$host = getenv('DB_HOST') ?: 'localhost';
$db   = getenv('DB_NAME') ?: 'SUCFRMS';
$user = getenv('DB_USER') ?: 'root';
$pass = getenv('DB_PASS') ?: '';

try {
    $pdo = new PDO("mysql:host=$host;dbname=$db;charset=utf8mb4", $user, $pass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    http_response_code(500);
    $isUnknownDb = stripos($e->getMessage(), 'Unknown database') !== false;
    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>SUCFRMS — Database Connection Error</title>
        <style>
            * { box-sizing: border-box; }
            body { margin: 0; font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
                   background: #f4f7fb; color: #0f172a; padding: 2rem 1rem; }
            .box { max-width: 640px; margin: 3rem auto; background: #fff; border: 1px solid #e2e8f0;
                   border-radius: 16px; box-shadow: 0 10px 30px rgba(15,23,42,.08); padding: 2rem; }
            h1 { font-size: 1.3rem; color: #334155; margin: 0 0 .5rem; }
            p { line-height: 1.6; }
            ol { line-height: 1.9; padding-left: 1.2rem; }
            code { background: #f1f5f9; padding: .15rem .4rem; border-radius: 5px; font-size: .85em; }
            .detail { margin-top: 1.5rem; font-size: .8rem; color: #64748b; background: #f8fafc;
                      border: 1px solid #e2e8f0; border-radius: 8px; padding: .75rem 1rem; word-break: break-word; }
        </style>
    </head>
    <body>
        <div class="box">
            <h1>Couldn't connect to the database</h1>
            <?php if ($isUnknownDb): ?>
            <p>The app can reach MySQL, but the <code><?= htmlspecialchars($db) ?></code> database doesn't exist yet.</p>
            <ol>
                <li>Open <strong>phpMyAdmin</strong> (or the MySQL client you use).</li>
                <li>Create a database named exactly <code><?= htmlspecialchars($db) ?></code>.</li>
                <li>Import <code>database.sql</code> from the project root into it.</li>
                <li>Reload this page.</li>
            </ol>
            <?php else: ?>
            <p>Please check that:</p>
            <ol>
                <li><strong>MySQL is running</strong> in your XAMPP / server control panel.</li>
                <li>The credentials in <code>config/db.php</code> (host, username, password) match your MySQL setup.</li>
                <li>The database <code><?= htmlspecialchars($db) ?></code> exists — import <code>database.sql</code> if you haven't already.</li>
            </ol>
            <?php endif; ?>
            <div class="detail"><?= htmlspecialchars($e->getMessage()) ?></div>
        </div>
    </body>
    </html>
    <?php
    exit;
}
