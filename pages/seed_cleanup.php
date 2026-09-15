<?php
/**
 * SUCFRMS Demo Cleanup
 * Removes the demo cycle and all data linked to it (applications, KRA entries,
 * checker reviews, notifications, audit logs from demo cycle).
 * Demo USERS are kept so they can still be used.
 * DELETE this file after use.
 */
require_once __DIR__ . '/../config/db.php';

$log = [];
function c(string $m, array &$l): void { $l[] = $m; }

try {
    $cname = 'AY 2025-2028 Demo Cycle';

    // Get cycle_id
    $ex = $pdo->prepare("SELECT cycle_id FROM cycles WHERE cycle_name=?");
    $ex->execute([$cname]);
    $cid = $ex->fetchColumn();

    if (!$cid) {
        c("Demo cycle not found — nothing to remove.", $log);
    } else {
        // Get all application IDs in this cycle
        $apps = $pdo->prepare("SELECT application_id FROM applications WHERE cycle_id=?");
        $apps->execute([$cid]);
        $app_ids = $apps->fetchAll(PDO::FETCH_COLUMN);

        if ($app_ids) {
            $ph = implode(',', array_fill(0, count($app_ids), '?'));

            // Delete evidence files (file records — not physical files)
            $pdo->prepare("DELETE kef FROM kra_evidence_files kef
                JOIN kra_submissions ks ON kef.submission_id = ks.submission_id
                WHERE ks.application_id IN ($ph)")->execute($app_ids);
            c("KRA evidence file records deleted.", $log);

            // Delete kra_checker_verifications
            try {
                $pdo->prepare("DELETE ckv FROM kra_checker_verifications ckv
                    JOIN kra_submissions ks ON ckv.submission_id = ks.submission_id
                    WHERE ks.application_id IN ($ph)")->execute($app_ids);
                c("Checker verifications deleted.", $log);
            } catch (\Exception $e) {}

            // Delete KRA submissions
            $pdo->prepare("DELETE FROM kra_submissions WHERE application_id IN ($ph)")->execute($app_ids);
            c(count($app_ids) . " applications' KRA entries deleted.", $log);

            // Delete checker reviews
            $pdo->prepare("DELETE FROM application_checker_reviews WHERE application_id IN ($ph)")->execute($app_ids);
            c("Checker reviews deleted.", $log);

            // Delete notifications linked to these applications
            $pdo->prepare("DELETE FROM notifications WHERE application_id IN ($ph)")->execute($app_ids);
            c("Notifications linked to demo applications deleted.", $log);
        }

        // Delete applications
        $pdo->prepare("DELETE FROM applications WHERE cycle_id=?")->execute([$cid]);
        c("Demo applications deleted.", $log);

        // Delete the cycle
        $pdo->prepare("DELETE FROM cycles WHERE cycle_id=?")->execute([$cid]);
        c("Demo cycle '{$cname}' deleted.", $log);
    }

    // Remove demo audit log entries (seeder-inserted ones)
    $demo_emails = [
        'faculty1@demo.ph','faculty2@demo.ph','faculty3@demo.ph',
        'faculty4@demo.ph','faculty5@demo.ph','checker1@demo.ph',
        'checker2@demo.ph','talisay1@demo.ph',
    ];
    $demo_uids = [];
    foreach ($demo_emails as $email) {
        $r = $pdo->prepare("SELECT user_id FROM users WHERE email=?");
        $r->execute([$email]);
        $id = $r->fetchColumn();
        if ($id) $demo_uids[] = (int)$id;
    }
    if ($demo_uids) {
        $ph2 = implode(',', array_fill(0, count($demo_uids), '?'));
        $pdo->prepare("DELETE FROM audit_logs WHERE user_id IN ($ph2)")->execute($demo_uids);
        c("Demo audit log entries removed.", $log);

        // Remove any remaining notifications for demo users
        $pdo->prepare("DELETE FROM notifications WHERE user_id IN ($ph2)")->execute($demo_uids);
        c("Remaining demo notifications removed.", $log);
    }

    // Also remove the seeder-added campuses IF they have no other users
    foreach (['CHMSU-Fortune Towne','CHMSU-Binalbagan','CHMSU-Talisay'] as $cname) {
        $ck = $pdo->prepare("SELECT COUNT(*) FROM users u JOIN campuses c ON u.campus_id=c.campus_id WHERE c.campus_name=? AND u.email NOT LIKE '%@demo.ph'");
        $ck->execute([$cname]);
        if ((int)$ck->fetchColumn() === 0) {
            // Safe to remove — only demo users on this campus
            // First unlink demo users from campus
            $pdo->prepare("UPDATE users SET campus_id=NULL WHERE campus_id=(SELECT campus_id FROM campuses WHERE campus_name=?) AND email LIKE '%@demo.ph'")->execute([$cname]);
        }
    }
    c("Demo user campus links cleared.", $log);

} catch (\Exception $e) {
    $log[] = "ERROR: " . $e->getMessage();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>SUCFRMS Demo Cleanup</title>
<style>
*{box-sizing:border-box;margin:0;padding:0;}
body{font-family:'Segoe UI',Arial,sans-serif;background:#f0f4fb;min-height:100vh;display:flex;align-items:center;justify-content:center;padding:2rem;}
.card{background:#fff;border-radius:14px;box-shadow:0 4px 24px rgba(0,0,0,0.12);width:100%;max-width:560px;overflow:hidden;}
.hd{background:linear-gradient(135deg,#991b1b,#dc2626);padding:1.5rem 2rem;}
.hd h1{color:#fff;font-size:1.15rem;font-weight:700;}
.hd p{color:rgba(255,255,255,.7);font-size:.8rem;margin-top:3px;}
.bd{padding:1.25rem 2rem;}
.item{display:flex;align-items:flex-start;gap:.6rem;padding:.45rem 0;border-bottom:1px solid #f1f5f9;font-size:.85rem;color:#1e293b;}
.item:last-child{border-bottom:none;}
.check{color:#16a34a;flex-shrink:0;}
.err{color:#dc2626;flex-shrink:0;}
.ft{padding:1rem 2rem;background:#f8fafc;border-top:1px solid #e2e8f0;font-size:.78rem;color:#64748b;}
.ft strong{color:#1a3a6b;}
code{background:#f1f5f9;padding:1px 5px;border-radius:4px;}
</style>
</head>
<body>
<div class="card">
  <div class="hd">
    <h1>🗑 SUCFRMS Demo Cleanup</h1>
    <p>Removed demo cycle and linked data. Demo users are kept.</p>
  </div>
  <div class="bd">
    <?php foreach ($log as $msg): ?>
    <div class="item">
      <span class="<?= str_starts_with($msg,'ERROR') ? 'err' : 'check' ?>">
        <?= str_starts_with($msg,'ERROR') ? '✗' : '✓' ?>
      </span>
      <span><?= htmlspecialchars($msg) ?></span>
    </div>
    <?php endforeach; ?>
  </div>
  <div class="ft">
    <strong>Demo users are still in the database.</strong>
    To remove them too, delete them from <code>Manage Users</code> in the admin panel.
    <br><br>
    Delete this file: <code>pages/seed_cleanup.php</code>
  </div>
</div>
</body>
</html>
