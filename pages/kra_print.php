<?php
/**
 * ISS &mdash; Individual Score Sheet
 * Accessible by faculty (own data) or admin/checker (any faculty via ?uid=)
 */
if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__ . '/../includes/functions.php';
requireLogin();
require_once __DIR__ . '/../config/db.php';
header('Content-Type: text/html; charset=UTF-8');
$target_uid = $_SESSION['user_id'];
if ((isAdmin() || isChecker()) && !empty($_GET['uid'])) {
    $target_uid = intval($_GET['uid']);
}

// Load faculty info
$faculty_stmt = $pdo->prepare(
    "SELECT u.*, c.campus_name FROM users u
     LEFT JOIN campuses c ON u.campus_id = c.campus_id
     WHERE u.user_id = ?"
);
$faculty_stmt->execute([$target_uid]);
$faculty = $faculty_stmt->fetch();
if (!$faculty) { echo '<p>Faculty not found.</p>'; exit; }

// Load application &mdash; prefer cycle from ?cycle_id, else active cycle
$cycle_id = intval($_GET['cycle_id'] ?? 0);
if ($cycle_id) {
    $cycle = $pdo->prepare("SELECT * FROM cycles WHERE cycle_id=?");
    $cycle->execute([$cycle_id]);
    $cycle = $cycle->fetch();
} else {
    $cycle = getActiveCycle($pdo);
}
if (!$cycle) { echo '<p>No cycle found.</p>'; exit; }

$app_stmt = $pdo->prepare(
    "SELECT * FROM applications WHERE user_id=? AND cycle_id=? LIMIT 1"
);
$app_stmt->execute([$target_uid, $cycle['cycle_id']]);
$app = $app_stmt->fetch();
if (!$app) { echo '<p>No application found for this cycle.</p>'; exit; }

$app_id = $app['application_id'];

// Load checker info
$checker = null;
if (!empty($app['checker_id'])) {
    $cs = $pdo->prepare("SELECT full_name, first_name, middle_name, last_name FROM users WHERE user_id=?");
    $cs->execute([$app['checker_id']]);
    $checker_row = $cs->fetch();
    if ($checker_row) $checker = formatDisplayName($checker_row);
}

// Load KRA submissions
$subs_raw = $pdo->prepare(
    "SELECT * FROM kra_submissions WHERE application_id=? ORDER BY kra_category, submitted_at ASC"
);
$subs_raw->execute([$app_id]);
$all_subs = $subs_raw->fetchAll();

$subs_by_cat = [];
foreach ($all_subs as $s) $subs_by_cat[$s['kra_category']][] = $s;

$kra_info = [
    'Instruction'              => ['num'=>'I',   'max'=>100, 'label'=>'Teaching Effectiveness'],
    'Research'                 => ['num'=>'II',  'max'=>100, 'label'=>'Research, Innovation & Creative Work'],
    'Extension'                => ['num'=>'III', 'max'=>100, 'label'=>'Extension Services'],
    'Professional Development' => ['num'=>'IV',  'max'=>100, 'label'=>'Professional Development'],
];

$kra_totals = [];
foreach ($kra_info as $cat => $info) {
    $entries = $subs_by_cat[$cat] ?? [];
    $total   = array_sum(array_column($entries, 'computed_points'));
    $cap     = $info['max']; // JC01 s.2026: all KRAs capped at 100
    $kra_totals[$cat] = min($cap, $total);
}
$grand_total = array_sum($kra_totals);

$rank   = $faculty['rank'] ?? '';
$result = computeWeightedScore($kra_totals, $rank);

$weights = $result['weights'];
$printed_at = date('F d, Y h:i A');
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>ISS &mdash; <?= htmlspecialchars(formatDisplayName($faculty)) ?></title>
<style>
* { margin:0; padding:0; box-sizing:border-box; }
body { font-family:'Times New Roman', Times, serif; font-size:11pt; color:#000; background:#fff;
       -webkit-print-color-adjust:exact; print-color-adjust:exact; }

/* Screen toolbar */
.toolbar { background:#1e4d8c; color:#fff; padding:8px 16px; display:flex; align-items:center; justify-content:space-between; gap:8px; flex-wrap:wrap; }
.toolbar h2 { font-size:13px; font-weight:700; }
.toolbar-actions { display:flex; gap:8px; }
.btn { padding:5px 14px; border-radius:5px; border:none; font-size:11px; font-weight:700; cursor:pointer; text-decoration:none; display:inline-flex; align-items:center; gap:4px; }
.btn-white { background:#fff; color:#1a3a6b; }
.btn-outline { background:transparent; color:#fff; border:1px solid rgba(255,255,255,0.5); }
@media print { .toolbar { display:none !important; } }

/* Page */
@page { size: A4 portrait; margin: 15mm 18mm 18mm 18mm; }
.page { max-width: 720px; margin: 0 auto; padding: 20px 24px; }
@media print { .page { max-width:100%; padding:0; } }

/* â”€â”€ Document header â”€â”€ */
.doc-header { display:flex; align-items:flex-start; gap:12px; margin-bottom:10px; }
.doc-header img { width:56px; height:56px; border-radius:50%; object-fit:cover; flex-shrink:0; }
.doc-header-center { flex:1; text-align:center; }
.doc-header-center .institution { font-size:11pt; font-weight:700; text-transform:uppercase; letter-spacing:0.3px; }
.doc-header-center .system-name { font-size:9pt; color:#475569; margin-top:2px; }
.doc-header-center .form-title { font-size:13pt; font-weight:700; margin-top:6px; text-transform:uppercase; letter-spacing:1px; border-top:2px solid #000; border-bottom:2px solid #000; padding:3px 0; }
.doc-header-center .form-subtitle { font-size:9pt; color:#475569; margin-top:2px; }
.doc-header-right { text-align:right; font-size:8.5pt; color:#475569; min-width:120px; }
.doc-header-right .tracking { font-family:monospace; font-size:9pt; font-weight:700; color:#1e4d8c; }

/* â”€â”€ Faculty info table â”€â”€ */
.info-table { width:100%; border-collapse:collapse; margin-bottom:10px; font-size:10pt; }
.info-table td { padding:4px 8px; border:1px solid #000; }
.info-table .lbl { font-weight:700; background:#f1f5f9; width:130px; font-size:9pt; }
.info-table .val { font-weight:400; }

/* â”€â”€ KRA section â”€â”€ */
.kra-section { margin-bottom:10px; page-break-inside:avoid; }
.kra-title-row { background:#1e4d8c; color:#fff; padding:5px 8px; font-size:10pt; font-weight:700; display:flex; justify-content:space-between; }
.kra-title-row .kra-max { font-size:9pt; font-weight:400; opacity:0.85; }
.kra-table { width:100%; border-collapse:collapse; font-size:9.5pt; }
.kra-table th { background:#eff6ff; padding:4px 7px; font-size:8.5pt; font-weight:700; border:1px solid #000; text-align:center; }
.kra-table th.left { text-align:left; }
.kra-table td { padding:4px 7px; border:1px solid #cbd5e1; vertical-align:top; }
.kra-table td.center { text-align:center; }
.kra-table td.score { text-align:center; font-weight:700; }
.kra-table .subtotal-row td { background:#f0f4fb; font-weight:700; border-top:2px solid #1a3a6b; }
.kra-table .subtotal-row td.score { color:#1a3a6b; font-size:11pt; }
.no-entry { border:1px solid #cbd5e1; border-top:none; padding:8px; text-align:center; color:#94a3b8; font-style:italic; font-size:9pt; }
.verified-yes { color:#1a3a6b; font-weight:700; }
.verified-no  { color:#94a3b8; }

/* â”€â”€ Weighted score table â”€â”€ */
.weighted-table { width:100%; border-collapse:collapse; margin-bottom:10px; font-size:9.5pt; }
.weighted-table th { background:#1e4d8c; color:#fff; padding:5px 8px; font-size:9pt; font-weight:700; border:1px solid #000; text-align:center; }
.weighted-table td { padding:5px 8px; border:1px solid #000; text-align:center; }
.weighted-table td.left { text-align:left; }
.weighted-table .total-row td { background:#f0f4fb; font-weight:700; font-size:11pt; }
.weighted-table .result-row td { background:#1e4d8c; color:#fff; font-weight:700; font-size:12pt; }

/* â”€â”€ Grand total box â”€â”€ */
.grand-box { border:2px solid #1e4d8c; border-radius:4px; padding:8px 14px; margin-bottom:10px; display:flex; justify-content:space-between; align-items:center; }
.grand-box .gl { font-size:11pt; font-weight:700; }
.grand-box .gr { font-size:20pt; font-weight:700; color:#1a3a6b; }
.grand-box .gs { font-size:8.5pt; color:#64748b; margin-top:2px; }

/* â”€â”€ Sub-rank result â”€â”€ */
.result-box { border:2px solid #000; border-radius:4px; padding:8px 14px; margin-bottom:14px; display:grid; grid-template-columns:1fr 1fr 1fr; gap:8px; text-align:center; }
.result-box .rb-label { font-size:8pt; color:#64748b; text-transform:uppercase; letter-spacing:0.4px; font-weight:700; }
.result-box .rb-val { font-size:14pt; font-weight:700; color:#1a3a6b; margin-top:2px; }
.result-box .rb-val.green { color:#1e4d8c; }

/* â”€â”€ Signatures â”€â”€ */
.sig-section { margin-top:20px; }
.sig-title { font-size:9pt; font-weight:700; text-transform:uppercase; letter-spacing:0.5px; margin-bottom:10px; border-bottom:1px solid #000; padding-bottom:3px; }
.sig-grid { display:grid; grid-template-columns:1fr 1fr; gap:30px; }
.sig-block { margin-top:30px; }
.sig-line { border-top:1px solid #000; padding-top:4px; }
.sig-name { font-size:10pt; font-weight:700; }
.sig-role { font-size:8.5pt; color:#475569; }
.sig-date { font-size:8.5pt; color:#475569; margin-top:2px; }

/* â”€â”€ Footer â”€â”€ */
.doc-footer { margin-top:16px; border-top:1px solid #000; padding-top:5px; display:flex; justify-content:space-between; font-size:8pt; color:#64748b; }
</style>
</head>
<body>

<!-- Screen toolbar -->
<div class="toolbar">
    <h2>ISS &mdash; Individual Score Sheet &nbsp;&middot;&nbsp; <?= htmlspecialchars(formatDisplayName($faculty)) ?></h2>
    <div class="toolbar-actions">
        <?php
        $back_url = '../index.php?page=dashboard';
        if (isAdmin()) $back_url = '../index.php?page=view_application&id=' . $app_id;
        elseif (isChecker()) $back_url = '../index.php?page=review_application&id=' . $app_id;
        ?>
        <a href="<?= $back_url ?>" class="btn btn-outline">&larr; Back</a>
        <a href="kra_pdf.php?uid=<?= $target_uid ?>&cycle_id=<?= $cycle['cycle_id'] ?>" class="btn btn-white">&#128424; Print ISS</a>
    </div>
</div>

<div class="page">

    <!-- Document Header -->
    <div class="doc-header">
        <img src="../assets/images/logo.jpg" alt="Institution Logo">
        <div class="doc-header-center">
            <div class="institution">State Universities and Colleges</div>
            <div class="system-name">SUC Faculty Reclassification Management System</div>
            <div class="form-title">Individual Score Sheet (ISS)</div>
            <div class="form-subtitle">DBM-CHED Joint Circular No. 3, s. 2022 &amp; JC No. 1, s. 2023</div>
        </div>
        <div class="doc-header-right">
            <div><?= htmlspecialchars($cycle['cycle_name']) ?></div>
            <div><?= date('M d, Y', strtotime($cycle['start_date'])) ?> &ndash; <?= date('M d, Y', strtotime($cycle['end_date'])) ?></div>
            <div style="margin-top:4px;">Printed: <?= $printed_at ?></div>
        </div>
    </div>

    <!-- Faculty Info -->
    <table class="info-table">
        <tr>
            <td class="lbl">Full Name</td>
            <td class="val" colspan="3"><strong><?= htmlspecialchars(formatDisplayName($faculty)) ?></strong></td>
        </tr>
        <tr>
            <td class="lbl">Employee ID</td>
            <td class="val"><?= htmlspecialchars($faculty['employee_id'] ?? '&mdash;') ?></td>
            <td class="lbl">Campus</td>
            <td class="val"><?= htmlspecialchars($faculty['campus_name'] ?? '&mdash;') ?></td>
        </tr>
        <tr>
            <td class="lbl">Current Rank</td>
            <td class="val"><?= htmlspecialchars($rank ?: '&mdash;') ?></td>
            <td class="lbl">Application Status</td>
            <td class="val"><?= ucwords(str_replace('_', ' ', $app['status'] ?? '&mdash;')) ?></td>
        </tr>
        <tr>
            <td class="lbl">Cycle</td>
            <td class="val"><?= htmlspecialchars($cycle['cycle_name']) ?></td>
            <td class="lbl">Reviewed By</td>
            <td class="val"><?= htmlspecialchars($checker ?? '&mdash;') ?></td>
        </tr>
    </table>

    <!-- KRA Sections -->
    <?php foreach ($kra_info as $cat => $info):
        $entries   = $subs_by_cat[$cat] ?? [];
        $cat_total = $kra_totals[$cat];
        $cap_label = ($cat === 'Extension') ? 'max 100 + 20 bonus' : 'max ' . $info['max'] . ' pts';
    ?>
    <div class="kra-section">
        <div class="kra-title-row">
            <span>KRA <?= $info['num'] ?> &mdash; <?= htmlspecialchars($info['label']) ?></span>
            <span class="kra-max"><?= $cap_label ?></span>
        </div>
        <?php if ($entries): ?>
        <table class="kra-table">
            <thead>
                <tr>
                    <th style="width:28px;">#</th>
                    <th class="left">Description / Remarks</th>
                    <th style="width:70px;">Points</th>
                    <th style="width:75px;">Verified</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($entries as $i => $s): ?>
            <tr>
                <td class="center" style="color:#94a3b8;"><?= $i + 1 ?></td>
                <td><?= formatKraRemarks($cat, $s['remarks'] ?? '') ?></td>
                <td class="score"><?= number_format((float)$s['computed_points'], 2) ?></td>
                <td class="center <?= $s['verified'] ? 'verified-yes' : 'verified-no' ?>">
                    <?= $s['verified'] ? '&#10004; Verified' : '' ?>
                </td>
            </tr>
            <?php endforeach; ?>
            <tr class="subtotal-row">
                <td colspan="2" style="text-align:right;">Sub-total (<?= $cap_label ?>)</td>
                <td class="score"><?= number_format($cat_total, 2) ?></td>
                <td></td>
            </tr>
            </tbody>
        </table>
        <?php else: ?>
        <div class="no-entry">No submission for this KRA.</div>
        <?php endif; ?>
    </div>
    <?php endforeach; ?>

    <!-- Grand Total -->
    <div class="grand-box">
        <div>
            <div class="gl">Grand Total Score</div>
            <div class="gs">Sum of all KRA scores (out of 360 pts)</div>
        </div>
        <div class="gr"><?= number_format($grand_total, 2) ?> <span style="font-size:11pt;color:#64748b;">/ 360</span></div>
    </div>

    <!-- Weighted Score Computation -->
    <table class="weighted-table">
        <thead>
            <tr>
                <th class="left">KRA</th>
                <th>Raw Score</th>
                <th>Capped</th>
                <th>Weight</th>
                <th>Weighted Score</th>
            </tr>
        </thead>
        <tbody>
        <?php
        $kra_labels = [
            'Instruction'              => 'KRA I &mdash; Instruction',
            'Research'                 => 'KRA II &mdash; Research',
            'Extension'                => 'KRA III &mdash; Extension',
            'Professional Development' => 'KRA IV &mdash; Prof. Development',
        ];
        $raw_scores = [
            'Instruction'              => $result['kra1'],
            'Research'                 => $result['kra2'],
            'Extension'                => $result['kra3'],
            'Professional Development' => $result['kra4'],
        ];
        foreach ($kra_labels as $cat => $label):
            $raw  = $kra_totals[$cat];
            $cap  = $raw_scores[$cat];
            $w    = $weights[$cat];
            $wval = round($cap * $w, 2);
        ?>
        <tr>
            <td class="left"><?= $label ?></td>
            <td><?= number_format($raw, 2) ?></td>
            <td><?= number_format($cap, 2) ?></td>
            <td><?= ($w * 100) ?>%</td>
            <td><?= number_format($wval, 2) ?></td>
        </tr>
        <?php endforeach; ?>
        <tr class="total-row">
            <td class="left" colspan="4">Final Weighted Score</td>
            <td><?= number_format($result['weighted_score'], 2) ?></td>
        </tr>
        </tbody>
    </table>

    <!-- Result Box -->
    <div class="result-box">
        <div>
            <div class="rb-label">Weighted Score</div>
            <div class="rb-val"><?= number_format($result['weighted_score'], 2) ?></div>
        </div>
        <div>
            <div class="rb-label">Sub-rank Increment</div>
            <div class="rb-val green">
                <?php $inc = $result['sub_rank_increment']; ?>
                <?= $inc > 0 ? "+{$inc} sub-rank" . ($inc > 1 ? 's' : '') : 'No reclassification' ?>
            </div>
        </div>
        <div>
            <div class="rb-label">Current Rank</div>
            <div class="rb-val" style="font-size:10pt;"><?= htmlspecialchars($rank ?: '&mdash;') ?></div>
        </div>
    </div>

    <!-- Signatures -->
    <div class="sig-section">
        <div class="sig-title">Certification &amp; Signatures</div>
        <div class="sig-grid">
            <div>
                <div class="sig-block">
                    <div class="sig-line">
                        <div class="sig-name"><?= htmlspecialchars(formatDisplayName($faculty)) ?></div>
                        <div class="sig-role">Faculty &mdash; Applicant</div>
                        <div class="sig-date">Date: ___________________</div>
                    </div>
                </div>
            </div>
            <div>
                <div class="sig-block">
                    <div class="sig-line">
                        <div class="sig-name"><?= htmlspecialchars($checker ?? '___________________________') ?></div>
                        <div class="sig-role">Checker / Evaluator</div>
                        <div class="sig-date">Date: ___________________</div>
                    </div>
                </div>
            </div>
            <div>
                <div class="sig-block">
                    <div class="sig-line">
                        <div class="sig-name">___________________________</div>
                        <div class="sig-role">Campus Director / Dean</div>
                        <div class="sig-date">Date: ___________________</div>
                    </div>
                </div>
            </div>
            <div>
                <div class="sig-block">
                    <div class="sig-line">
                        <div class="sig-name">___________________________</div>
                        <div class="sig-role">University President</div>
                        <div class="sig-date">Date: ___________________</div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Footer -->
    <div class="doc-footer">
        <span>SUCFRMS &mdash; Individual Score Sheet (ISS)</span>
        <span>Printed: <?= $printed_at ?></span>
    </div>

</div>
</body>
</html>
