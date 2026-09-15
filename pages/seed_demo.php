<?php
/**
 * SUCFRMS Demo Seeder
 * Seeds demo users + applications (using the existing active cycle).
 * Does NOT create a new cycle.
 * DELETE this file after use.
 * Access: http://localhost/SUCFRMS/pages/seed_demo.php
 */
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/functions.php';

$log = [];

function ok(string $m, array &$l): void { $l[] = ['ok', $m]; }
function er(string $m, array &$l): void { $l[] = ['er', $m]; }

try {

// ── 1. Campuses ──────────────────────────────────────────────
foreach (['CHMSU-Fortune Towne','CHMSU-Binalbagan','CHMSU-Talisay'] as $name) {
    $pdo->prepare("INSERT IGNORE INTO campuses (campus_name) VALUES (?)")->execute([$name]);
}
$c1 = (int)$pdo->query("SELECT campus_id FROM campuses WHERE campus_name='CHMSU-Fortune Towne'")->fetchColumn();
$c2 = (int)$pdo->query("SELECT campus_id FROM campuses WHERE campus_name='CHMSU-Binalbagan'")->fetchColumn();
$c3 = (int)$pdo->query("SELECT campus_id FROM campuses WHERE campus_name='CHMSU-Talisay'")->fetchColumn();
ok("Campuses ready.", $log);

// ── 2. Users ─────────────────────────────────────────────────
$pw = password_hash('Demo@1234', PASSWORD_DEFAULT);
$users = [
    ['Maria',  'Santos',    'Cruz',       'faculty1@demo.ph',  'faculty',         'Assistant Professor I',   'EMP-F001', $c1],
    ['Juan',   'Reyes',     'dela Cruz',  'faculty2@demo.ph',  'faculty',         'Associate Professor II',  'EMP-F002', $c1],
    ['Ana',    'Lim',       'Villanueva', 'faculty3@demo.ph',  'faculty',         'Instructor III',          'EMP-F003', $c2],
    ['Pedro',  'Garcia',    'Santos',     'faculty4@demo.ph',  'faculty',         'Assistant Professor III', 'EMP-F004', $c2],
    ['Rosa',   'Torres',    'Mendoza',    'faculty5@demo.ph',  'faculty',         'Associate Professor I',   'EMP-F005', $c1],
    ['Marco',  'Rivera',    'Bautista',   'checker1@demo.ph',  'checker',         null,                      'EMP-C001', $c1],
    ['Luisa',  'Fernandez', 'Aquino',     'checker2@demo.ph',  'checker_faculty', 'Professor I',             'EMP-C002', $c1],
    ['Tomas',  'Blas',      'Guerrero',   'talisay1@demo.ph',  'talisay_checker', null,                      'EMP-T001', $c3],
];
$uid = [];
foreach ($users as [$fn, $mn, $ln, $email, $role, $rank, $empid, $cid]) {
    $ex = $pdo->prepare("SELECT user_id FROM users WHERE email=?");
    $ex->execute([$email]);
    $id = $ex->fetchColumn();
    if (!$id) {
        $pdo->prepare("INSERT INTO users (first_name,middle_name,last_name,email,password,role,status,rank,employee_id,campus_id) VALUES (?,?,?,?,?,?,?,?,?,?)")
            ->execute([$fn,$mn,$ln,$email,$pw,$role,'active',$rank,$empid,$cid]);
        $id = (int)$pdo->lastInsertId();
    }
    $uid[$email] = (int)$id;
}
ok("8 demo users ready (password: Demo@1234).", $log);

// ── 3. Use existing active cycle (do NOT create one) ─────────
$cycle = $pdo->query("SELECT cycle_id, cycle_name FROM cycles WHERE status='open' ORDER BY created_at DESC LIMIT 1")->fetch();
if (!$cycle) {
    er("No open cycle found. Please create a cycle in the admin panel first, then re-run this seeder.", $log);
    goto done;
}
$cid  = (int)$cycle['cycle_id'];
$cname = $cycle['cycle_name'];
ok("Using existing cycle: {$cname} (ID {$cid}).", $log);

// ── 4. Applications + KRA entries ────────────────────────────
function seedApp(PDO $pdo, int $fuid, int $cid, string $status, array $kras, int $checker_uid = 0): int {
    // Remove existing demo application for this user+cycle first
    $ex = $pdo->prepare("SELECT application_id FROM applications WHERE user_id=? AND cycle_id=?");
    $ex->execute([$fuid, $cid]);
    $old = $ex->fetchColumn();
    if ($old) {
        $pdo->prepare("DELETE FROM kra_submissions WHERE application_id=?")->execute([$old]);
        $pdo->prepare("DELETE FROM application_checker_reviews WHERE application_id=?")->execute([$old]);
        $pdo->prepare("DELETE FROM notifications WHERE application_id=?")->execute([$old]);
        $pdo->prepare("DELETE FROM applications WHERE application_id=?")->execute([$old]);
    }
    $pdo->prepare("INSERT INTO applications (user_id,cycle_id,status,submitted_at) VALUES (?,?,?,NOW())")
        ->execute([$fuid,$cid,$status]);
    $aid = (int)$pdo->lastInsertId();

    foreach ($kras as [$cat,$pts,$rem]) {
        $pdo->prepare("INSERT INTO kra_submissions (application_id,user_id,kra_category,computed_points,remarks,faculty_original_score,submitted_at) VALUES (?,?,?,?,?,?,NOW())")
            ->execute([$aid,$fuid,$cat,$pts,$rem,$pts]);
    }

    // Recalc weighted score
    $raw = [];
    foreach (['Instruction','Research','Extension','Professional Development'] as $k) {
        $s = $pdo->prepare("SELECT SUM(computed_points) FROM kra_submissions WHERE application_id=? AND kra_category=?");
        $s->execute([$aid,$k]);
        $raw[$k] = min(100,(float)$s->fetchColumn());
    }
    $rk = $pdo->prepare("SELECT rank FROM users WHERE user_id=?");
    $rk->execute([$fuid]);
    $rank = $rk->fetchColumn() ?: 'Instructor I';
    $res = computeWeightedScore($raw, $rank);
    $pdo->prepare("UPDATE applications SET total_score=?,weighted_score=?,sub_rank_increment=?,checker_id=? WHERE application_id=?")
        ->execute([array_sum($raw),$res['weighted_score'],$res['sub_rank_increment'],$checker_uid ?: null,$aid]);
    return $aid;
}

$chk = $uid['checker1@demo.ph'];

// Faculty 1 — Submitted
$a1 = seedApp($pdo, $uid['faculty1@demo.ph'], $cid, 'submitted', [
    ['Instruction',              52.20, 'A-set-sef|||92|||87|||'],
    ['Research',                 16.67, 'Criterion A – Book, Sole Author (100pts)|||Introduction to Data Analytics|||100'],
    ['Extension',                20.00, 'Community Extension Program|||500000|||1|||2'],
    ['Professional Development',  5.00, 'A-org|||Philippine Association of Professional Educators|||5'],
]);
$pdo->prepare("INSERT IGNORE INTO application_checker_reviews (application_id,checker_id,decision) VALUES (?,?,'pending')")->execute([$a1,$chk]);
ok("App 1 (Maria Santos Cruz) — submitted.", $log);

// Faculty 2 — Under review
$a2 = seedApp($pdo, $uid['faculty2@demo.ph'], $cid, 'under_review', [
    ['Instruction',              56.16, 'A-set-sef|||98|||87|||'],
    ['Research',                100.00, 'Criterion A – Book, Sole Author (100pts)|||Research in Educational Technology|||100'],
    ['Extension',                42.00, 'Community Service Program|||6000000|||1|||0'],
    ['Professional Development',  5.00, 'A-org|||PAFTE Member|||5'],
], $chk);
$pdo->prepare("INSERT IGNORE INTO application_checker_reviews (application_id,checker_id,decision) VALUES (?,?,'pending')")->execute([$a2,$chk]);
ok("App 2 (Juan dela Cruz) — under review.", $log);

// Faculty 3 — Draft
$a3 = seedApp($pdo, $uid['faculty3@demo.ph'], $cid, 'draft', [
    ['Instruction',              36.00, 'A-set-sef|||75|||65|||'],
    ['Research',                  0.00, 'B-paper|||No publication yet|||0'],
    ['Extension',                 0.00, 'Community Activity|||0|||0|||0'],
    ['Professional Development',  5.00, 'A-org|||PAFTE Member|||5'],
]);
ok("App 3 (Ana Lim Villanueva) — draft.", $log);

// Faculty 4 — Needs revision (Research entry flagged)
$a4 = seedApp($pdo, $uid['faculty4@demo.ph'], $cid, 'needs_revision', [
    ['Instruction',              46.80, 'A-set-sef|||81|||73|||'],
    ['Research',                 20.00, 'Criterion C – Juried or Peer-Reviewed Design (20pts)|||Digital Learning Materials|||100'],
    ['Extension',                15.00, 'Community Outreach Program|||200000|||0|||3'],
    ['Professional Development', 10.00, 'B-degree|||Additional Master\'s Degree|||20'],
], $chk);
$pdo->prepare("INSERT IGNORE INTO application_checker_reviews (application_id,checker_id,decision) VALUES (?,?,'pending')")->execute([$a4,$chk]);
$sub = $pdo->prepare("SELECT submission_id FROM kra_submissions WHERE application_id=? AND kra_category='Research' LIMIT 1");
$sub->execute([$a4]);
if ($sid = $sub->fetchColumn()) {
    $pdo->prepare("UPDATE kra_submissions SET revision_status='needs_revision',revision_note='Please attach proof of peer-reviewed publication.',revision_by=? WHERE submission_id=?")
        ->execute([$chk,$sid]);
}
ok("App 4 (Pedro Garcia Santos) — needs revision, Research entry flagged.", $log);

// Faculty 5 — Approved, all entries verified
$a5 = seedApp($pdo, $uid['faculty5@demo.ph'], $cid, 'approved', [
    ['Instruction',              58.80, 'A-set-sef|||95|||91|||'],
    ['Research',                100.00, 'Criterion A – Book, Sole Author (100pts)|||Curriculum Design Principles|||100'],
    ['Extension',                55.00, 'MOA Extension Program|||8000000|||2|||3'],
    ['Professional Development', 20.00, 'B-degree|||Additional Master\'s Degree|||20'],
], $chk);
$pdo->prepare("INSERT IGNORE INTO application_checker_reviews (application_id,checker_id,decision,decided_at) VALUES (?,?,'approved',NOW())")->execute([$a5,$chk]);
$pdo->prepare("UPDATE applications SET reviewed_at=NOW() WHERE application_id=?")->execute([$a5]);
$pdo->prepare("UPDATE kra_submissions SET verified=1,verified_by=?,verified_at=NOW() WHERE application_id=?")->execute([$chk,$a5]);
ok("App 5 (Rosa Torres Mendoza) — approved, all entries verified.", $log);

// ── 5. Notifications ──────────────────────────────────────────
$notifs = [
    [$uid['checker1@demo.ph'], 'new_submission', 'New application submitted by Maria Santos Cruz ... awaiting your review.', $a1],
    [$uid['faculty2@demo.ph'], 'under_review',   'Your application is now under review by a campus checker.', $a2],
    [$uid['faculty4@demo.ph'], 'needs_revision', 'Revision requested on your Research entry. Please attach required documents.', $a4],
    [$uid['faculty5@demo.ph'], 'approved',       'Congratulations! Your application has been approved by all campus checkers.', $a5],
];
foreach ($notifs as [$nuid,$type,$msg,$aid]) {
    $pdo->prepare("INSERT IGNORE INTO notifications (user_id,type,message,application_id) VALUES (?,?,?,?)")
        ->execute([$nuid,$type,$msg,$aid]);
}
ok("Sample notifications seeded.", $log);

// ── 6. Audit log ──────────────────────────────────────────────
$audits = [
    [$uid['faculty1@demo.ph'], 'faculty', 'Application Submitted', 'Submitted for ' . $cname . '.'],
    [$uid['faculty2@demo.ph'], 'faculty', 'Application Submitted', 'Submitted for ' . $cname . '.'],
    [$uid['checker1@demo.ph'], 'checker', 'Review Started',        'Started reviewing faculty2 application.'],
    [$uid['faculty4@demo.ph'], 'faculty', 'Application Submitted', 'Submitted for ' . $cname . '.'],
    [$uid['checker1@demo.ph'], 'checker', 'Revision Requested',    'Flagged Research entry for revision.'],
    [$uid['faculty5@demo.ph'], 'faculty', 'Application Submitted', 'Submitted for ' . $cname . '.'],
    [$uid['checker1@demo.ph'], 'checker', 'Application Approved',  'Approved faculty5 application.'],
];
foreach ($audits as [$auid,$role,$action,$detail]) {
    $pdo->prepare("INSERT INTO audit_logs (user_id,role_at_time,action_performed,details) VALUES (?,?,?,?)")
        ->execute([$auid,$role,$action,$detail]);
}
ok("Audit log entries added.", $log);

done:
} catch (\Exception $e) {
    er("Error: " . $e->getMessage(), $log);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>SUCFRMS Demo Seeder</title>
<style>
*{box-sizing:border-box;margin:0;padding:0;}
body{font-family:'Segoe UI',Arial,sans-serif;background:#f0f4fb;min-height:100vh;display:flex;align-items:center;justify-content:center;padding:2rem;}
.card{background:#fff;border-radius:14px;box-shadow:0 4px 24px rgba(0,0,0,0.12);width:100%;max-width:680px;overflow:hidden;}
.hd{background:linear-gradient(135deg,#1a3a6b,#1e4d8c);padding:1.5rem 2rem;}
.hd h1{color:#fff;font-size:1.15rem;font-weight:700;}
.hd p{color:rgba(255,255,255,.65);font-size:.8rem;margin-top:3px;}
.bd{padding:1.25rem 2rem;}
.item{display:flex;align-items:flex-start;gap:.6rem;padding:.45rem 0;border-bottom:1px solid #f1f5f9;font-size:.85rem;color:#1e293b;}
.item:last-child{border-bottom:none;}
.ok{color:#16a34a;flex-shrink:0;font-size:1.05rem;}
.er{color:#dc2626;flex-shrink:0;font-size:1.05rem;}
.ft{padding:1.25rem 2rem;background:#f8fafc;border-top:1px solid #e2e8f0;}
.ft h3{font-size:.85rem;font-weight:700;color:#1a3a6b;margin-bottom:.75rem;}
table{width:100%;border-collapse:collapse;font-size:.78rem;}
th{background:#1a3a6b;color:#fff;padding:6px 10px;text-align:left;font-weight:600;}
td{padding:5px 10px;border-bottom:1px solid #e2e8f0;color:#334155;}
tr:nth-child(even) td{background:#f8fafc;}
.warn{margin-top:1rem;padding:.6rem .9rem;background:#fef2f2;border:1px solid #fecaca;border-radius:8px;font-size:.75rem;color:#991b1b;}
code{background:#f1f5f9;padding:1px 5px;border-radius:4px;font-size:.8rem;}
</style>
</head>
<body>
<div class="card">
  <div class="hd">
    <h1>🌱 SUCFRMS Demo Seeder</h1>
    <p>Seeds demo users + applications using the existing active cycle. No new cycle is created.</p>
  </div>
  <div class="bd">
    <?php foreach ($log as [$type,$msg]): ?>
    <div class="item">
      <span class="<?= $type ?>"><?= $type==='ok' ? '✓' : '✗' ?></span>
      <span><?= htmlspecialchars($msg) ?></span>
    </div>
    <?php endforeach; ?>
  </div>
  <div class="ft">
    <h3>Demo Credentials — all passwords: <code>Demo@1234</code></h3>
    <table>
      <thead><tr><th>Role</th><th>Email</th><th>Name</th><th>App Status</th></tr></thead>
      <tbody>
        <tr><td>Faculty</td><td>faculty1@demo.ph</td><td>Maria Santos Cruz</td><td>Submitted</td></tr>
        <tr><td>Faculty</td><td>faculty2@demo.ph</td><td>Juan dela Cruz</td><td>Under Review</td></tr>
        <tr><td>Faculty</td><td>faculty3@demo.ph</td><td>Ana Lim Villanueva</td><td>Draft</td></tr>
        <tr><td>Faculty</td><td>faculty4@demo.ph</td><td>Pedro Garcia Santos</td><td>Needs Revision</td></tr>
        <tr><td>Faculty</td><td>faculty5@demo.ph</td><td>Rosa Torres Mendoza</td><td>Approved</td></tr>
        <tr><td>Campus Checker</td><td>checker1@demo.ph</td><td>Marco Rivera Bautista</td><td>—</td></tr>
        <tr><td>Checker+Faculty</td><td>checker2@demo.ph</td><td>Luisa Fernandez Aquino</td><td>—</td></tr>
        <tr><td>Talisay Checker</td><td>talisay1@demo.ph</td><td>Tomas Blas Guerrero</td><td>—</td></tr>
      </tbody>
    </table>
    <div class="warn">⚠ <strong>Delete this file after use:</strong> <code>pages/seed_demo.php</code></div>
  </div>
</div>
</body>
</html>
