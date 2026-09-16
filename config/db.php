<?php
// ── Database credentials ──────────────────────────────────────
// For live deployment: set these to your hosting provider's DB details.
// For local XAMPP: defaults below work out of the box.
$host = getenv('DB_HOST') ?: 'localhost';
$db   = getenv('DB_NAME') ?: 'SUCFRMS';
$user = getenv('DB_USER') ?: 'root';
$pass = getenv('DB_PASS') ?: '';


function renderDatabaseSetupError(string $title, string $message, array $steps, ?string $detail = null): void
{
    http_response_code(500);
    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>SUCFRMS - Database Setup Required</title>
        <style>
            * { box-sizing: border-box; }
            body { margin: 0; font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
                   background: #f4f7fb; color: #0f172a; padding: 2rem 1rem; }
            .box { max-width: 680px; margin: 3rem auto; background: #fff; border: 1px solid #e2e8f0;
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
            <h1><?= htmlspecialchars($title) ?></h1>
            <p><?= $message ?></p>
            <ol>
                <?php foreach ($steps as $step): ?>
                    <li><?= $step ?></li>
                <?php endforeach; ?>
            </ol>
            <?php if ($detail): ?>
                <div class="detail"><?= htmlspecialchars($detail) ?></div>
            <?php endif; ?>
        </div>
    </body>
    </html>
    <?php
    exit;
}

try {
    $dsn = "mysql:host=$host;dbname=$db;charset=utf8mb4";
    if ($port !== '') {
        $dsn .= ";port=$port";
    }

    $pdo = new PDO($dsn, $user, $pass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

    $requiredTables = ['campuses', 'users', 'cycles'];
    $placeholders = implode(',', array_fill(0, count($requiredTables), '?'));
    $schemaCheck = $pdo->prepare("
        SELECT table_name
        FROM information_schema.tables
        WHERE table_schema = DATABASE()
          AND table_name IN ($placeholders)
    ");
    $schemaCheck->execute($requiredTables);
    $existingTables = $schemaCheck->fetchAll(PDO::FETCH_COLUMN);
    $missingTables = array_values(array_diff($requiredTables, $existingTables));

    if ($missingTables) {
        renderDatabaseSetupError(
            'Database schema is incomplete',
            'The app connected to MySQL, but the required table(s) <code>' . htmlspecialchars(implode('</code>, <code>', $missingTables)) . '</code> are missing.',
            [
                'Open <strong>phpMyAdmin</strong> and select the <code>' . htmlspecialchars($db) . '</code> database.',
                'Import <code>database.sql</code> from this project folder.',
                'After the import succeeds, reload this page.',
            ],
            'Missing table(s): ' . implode(', ', $missingTables)
        );
    }
} catch (PDOException $e) {
    $isUnknownDb = stripos($e->getMessage(), 'Unknown database') !== false;

    if ($isUnknownDb) {
        renderDatabaseSetupError(
            "Couldn't connect to the database",
            'The app can reach MySQL, but the <code>' . htmlspecialchars($db) . '</code> database does not exist yet.',
            [
                'Open <strong>phpMyAdmin</strong> or your MySQL client.',
                'Create a database named exactly <code>' . htmlspecialchars($db) . '</code>.',
                'Import <code>database.sql</code> from the project root into it.',
                'Reload this page.',
            ],
            $e->getMessage()
        );
    }

    renderDatabaseSetupError(
        "Couldn't connect to the database",
        'Please check your database setup.',
        [
            '<strong>MySQL is running</strong> on your hosting account or server.',            'The credentials in <code>config/db.php</code> match your MySQL setup.',
            'The database <code>' . htmlspecialchars($db) . '</code> exists and <code>database.sql</code> has been imported.',
        ],
        $e->getMessage()
    );
}
