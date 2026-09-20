<?php
/**
 * OSS &mdash; Overall Score Summary
 * Admin/checker only. Shows all faculty scores for a given cycle.
 */
if (session_status() === PHP_SESSION_NONE) session_start();
header('Content-Type: text/html; charset=UTF-8');
require_once __DIR__ . '/../includes/functions.php';
requireLogin();
if (!isAdmin() && !isChecker()) { http_response_code(403); die('Access denied.'); }
require_once __DIR__ . '/../config/db.php';

// Filters
$cycle_id  = intval($_GET['cycle_id'] ?? 0);
$campus_id = intval($_GET['campus_id'] ?? 0);
$status    = trim($_GET['status'] ?? '');

// Load cycles
$all_cycles   = $pdo->query("SELECT cycle_id, cycle_name, status, start_date, end_date FROM cycles ORDER BY created_at DESC")->fetchAll();
$all_campuses = $pdo->query("SELECT campus_id, campus_name FROM campuses WHERE is_active=1 ORDER BY campus_name")->fetchAll();

// Default to open cycle
if (!$cycle_id) {
    foreach ($all_cycles as $cy) {
        if ($cy['status'] === 'open') { $cycle_id = $cy['cycle_id']; break; }
    }
}
$selected_cycle = null;
foreach ($all_cycles as $cy) {
    if ($cy['cycle_id'] === $cycle_id) { $selected_cycle = $cy; break; }
}

// Build query
$where  = "WHERE u.role = 'faculty' AND a.cycle_id=?";
$params = [$cycle_id];
if ($campus_id) { $where .= ' AND u.campus_id=?'; $params[] = $campus_id; }
if ($status && in_array($status, ['submitted','under_review','approved','rejected','reclassified'])) {
    $where .= ' AND a.status=?'; $params[] = $status;
}

$rows = $pdo->prepare("
    SELECT a.*, u.full_name, u.first_name, u.middle_name, u.last_name, u.employee_id, u.rank,
           COALESCE(camp.campus_name,'&mdash;') as campus_name,
           chk.user_id as checker_uid,
           chk.checker_label as checker_label
    FROM applications a
    JOIN users u ON a.user_id=u.user_id
    LEFT JOIN campuses camp ON u.campus_id=camp.campus_id
    LEFT JOIN users chk ON a.checker_id=chk.user_id
    $where
    ORDER BY camp.campus_name, u.full_name
");
$rows->execute($params);
$applications = $rows->fetchAll();

// Load KRA scores per application
$kra_cats = ['Instruction','Research','Extension','Professional Development'];
$kra_scores = [];
if ($applications) {
    $app_ids = array_column($applications, 'application_id');
    $placeholders = implode(',', array_fill(0, count($app_ids), '?'));
    $ks = $pdo->prepare("SELECT application_id, kra_category, computed_points FROM kra_submissions WHERE application_id IN ($placeholders)");
    $ks->execute($app_ids);
    foreach ($ks->fetchAll() as $k) {
        $kra_scores[$k['application_id']][$k['kra_category']] = (float)$k['computed_points'];
    }
}

// Summary stats
$total = count($applications);
$status_counts = [];
foreach ($applications as $a) $status_counts[$a['status']] = ($status_counts[$a['status']] ?? 0) + 1;

// KRA averages
$kra_avgs = ['Instruction'=>[],'Research'=>[],'Extension'=>[],'Professional Development'=>[]];
foreach ($applications as $a) {
    foreach ($kra_cats as $cat) {
        $kra_avgs[$cat][] = $kra_scores[$a['application_id']][$cat] ?? 0;
    }
}
$kra_avg_vals = [];
foreach ($kra_avgs as $cat => $vals) {
    $kra_avg_vals[$cat] = count($vals) > 0 ? round(array_sum($vals) / count($vals), 2) : 0;
}

$printed_at = date('F d, Y h:i A');
$admin_name = $_SESSION['full_name'] ?? 'Administrator';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>OSS — Overall Score Summary</title>
<style>
* { margin:0; padding:0; box-sizing:border-box; }
body { font-family:'Times New Roman', Times, serif; font-size:10.5pt; color:#000; background:#f0f3f8; }

/* ── Screen toolbar ── */
.toolbar { background:#1e4d8c; color:#fff; padding:7px 16px; display:flex; align-items:center; justify-content:space-between; gap:8px; flex-wrap:wrap; position:sticky; top:0; z-index:100; }
.toolbar h2 { font-size:12px; font-weight:700; }
.toolbar-actions { display:flex; gap:6px; }
.btn { padding:4px 13px; border-radius:4px; border:none; font-size:11px; font-weight:700; cursor:pointer; text-decoration:none; display:inline-flex; align-items:center; gap:4px; }
.btn-white { background:#fff; color:#1a3a6b; }
.btn-outline { background:transparent; color:#fff; border:1px solid rgba(255,255,255,0.55); }

/* ── Filter bar ── */
.filter-bar { background:#fff; border-bottom:1px solid #e2e8f0; padding:5px 16px; display:flex; gap:10px; flex-wrap:wrap; align-items:center; font-size:10.5px; }
.filter-bar label { font-weight:700; color:#475569; }
.filter-bar select { font-size:10.5px; padding:3px 6px; border:1px solid #cbd5e1; border-radius:4px; }
.filter-bar .btn-apply { background:#1e4d8c; color:#fff; padding:3px 12px; border-radius:4px; border:none; font-size:10.5px; font-weight:700; cursor:pointer; }
@media print { .toolbar, .filter-bar { display:none !important; } }

/* ── Document page ── */
@page { size: A4 portrait; margin: 15mm 18mm 18mm 18mm; }
.page-wrap { background:#fff; max-width:720px; margin:0 auto; padding:20px 24px; }
@media print { .page-wrap { max-width:100%; padding:0; margin:0; background:#fff; box-shadow:none; } }
@media screen { .page-wrap { margin:14px auto; box-shadow:0 2px 16px rgba(0,0,0,0.1); } }

/* ── Document header ── */
.doc-header { display:flex; align-items:flex-start; gap:14px; margin-bottom:6px; }
.doc-header img { width:60px; height:60px; border-radius:50%; object-fit:cover; flex-shrink:0; border:2px solid #1a3a6b; }
.doc-header-center { flex:1; text-align:center; line-height:1.35; }
.doc-header-center .institution { font-size:12pt; font-weight:700; text-transform:uppercase; letter-spacing:0.3px; }
.doc-header-center .system-name { font-size:8.5pt; color:#1a3a6b; margin-top:1px; text-decoration:underline; }
.doc-title-bar { margin:6px 0 2px; border-top:2.5px solid #000; border-bottom:2.5px solid #000; padding:3px 0; text-align:center; }
.doc-title-bar .form-title { font-size:13.5pt; font-weight:700; text-transform:uppercase; letter-spacing:1.5px; }

/* ── Filter summary line ── */
.filter-line { font-size:8.5pt; color:#1e293b; margin-bottom:6px; }
.filter-line strong { color:#1a3a6b; }

/* ── Summary stats (inline, no boxes) ── */
.summary-line { font-size:9pt; margin-bottom:8px; border-bottom:1px solid #000; padding-bottom:4px; }
.summary-line .cycle-lbl { font-weight:700; color:#1e293b; margin-right:8px; }
.summary-line .total-lbl { color:#475569; margin-right:16px; }
.summary-stat { display:inline-block; margin-right:16px; font-size:9pt; }
.summary-stat .sv { font-size:12pt; font-weight:700; color:#1a3a6b; line-height:1; display:block; }
.summary-stat .sl { font-size:7pt; text-transform:uppercase; letter-spacing:0.3px; color:#64748b; }

/* ── KRA averages ── */
.kra-avg-row { display:grid; grid-template-columns:repeat(2,1fr); gap:0; margin-bottom:10px; border:1px solid #000; }
.kra-avg-box { border-right:1px solid #cbd5e1; border-bottom:1px solid #cbd5e1; }
.kra-avg-box:nth-child(2n) { border-right:none; }
.kra-avg-box:nth-child(3), .kra-avg-box:nth-child(4) { border-bottom:none; }
.kra-avg-box { padding:5px 8px; }
.kra-avg-box .kn { font-size:7.5pt; font-weight:700; color:#1a3a6b; }
.kra-avg-box .kv { font-size:12pt; font-weight:700; line-height:1.2; margin-top:1px; }
.kra-avg-box .km { font-size:7pt; color:#64748b; }

/* ── Section title ── */
.section-title { font-size:9pt; font-weight:700; text-transform:uppercase; letter-spacing:0.5px; border-bottom:2px solid #000; padding-bottom:2px; margin-bottom:5px; }

/* ── Main table ── */
table.main { width:100%; border-collapse:collapse; font-size:7pt; }
table.main thead tr { background:#1e4d8c; color:#fff; }
table.main thead th { padding:3px 4px; font-size:6.5pt; font-weight:700; border:1px solid #000; text-align:center; white-space:nowrap; }
table.main thead th.left { text-align:left; }
table.main tbody tr:nth-child(even) { background:#f8fafc; }
table.main tbody td { padding:3px 4px; border:1px solid #cbd5e1; vertical-align:middle; font-size:7pt; }
table.main tbody td.center { text-align:center; }
table.main tbody td.score-high { color:#1e4d8c; font-weight:700; text-align:center; }
table.main tbody td.score-mid  { color:#475569; font-weight:700; text-align:center; }
table.main tbody td.score-low  { color:#1e293b; font-weight:700; text-align:center; }
.badge { display:inline-block; padding:1px 5px; border-radius:2px; font-size:7pt; font-weight:700; }
.badge-reclassified   { background:#dbeafe; color:#1a3a6b; }
.badge-approved       { background:#dbeafe; color:#1a3a6b; }
.badge-submitted      { background:#dbeafe; color:#1e4d8c; }
.badge-under_review   { background:#f1f5f9; color:#475569; }
.badge-rejected       { background:#f1f5f9; color:#475569; }
.badge-admin_rejected { background:#f1f5f9; color:#475569; }
.badge-draft          { background:#f1f5f9; color:#94a3b8; }

/* ── Campus header ── */
.campus-header { background:#1e4d8c; color:#fff; font-weight:700; font-size:8.5pt; padding:4px 8px; border:1px solid #000; margin-top:6px; }
.campus-group { page-break-inside:avoid; }

/* ── Subtotal row ── */
.subtotal-row td { background:#eff6ff !important; font-weight:700; border-top:2px solid #1a3a6b; color:#1a3a6b; font-size:7.5pt; }

/* ── Signature section ── */
.sig-section { margin-top:18px; }
.sig-title { font-size:9pt; font-weight:700; text-transform:uppercase; letter-spacing:0.5px; border-bottom:1px solid #000; padding-bottom:2px; margin-bottom:8px; }
.sig-grid { display:grid; grid-template-columns:1fr 1fr 1fr; gap:28px; }
.sig-block { margin-top:20px; }
.sig-line { border-top:1px solid #000; padding-top:3px; }
.sig-name { font-size:9pt; font-weight:700; }
.sig-role { font-size:8pt; color:#475569; }
.sig-date { font-size:8pt; color:#475569; margin-top:1px; }

/* ── Footer ── */
.doc-footer { margin-top:10px; border-top:1px solid #000; padding-top:3px; display:flex; justify-content:space-between; font-size:7.5pt; color:#64748b; }

/* ── Empty state ── */
.empty-msg { text-align:center; padding:14px; font-style:italic; font-size:9pt; color:#64748b; }
</style>
</head>
<body>

<!-- Screen toolbar -->
<div class="toolbar">
    <h2>OSS &mdash; Overall Score Summary</h2>
    <div class="toolbar-actions">
        <a href="../index.php?page=dashboard" class="btn btn-outline">&larr; Back</a>
        <a href="oss_pdf.php?cycle_id=<?= $cycle_id ?>&campus_id=<?= $campus_id ?>&status=<?= urlencode($status) ?>" class="btn btn-white" target="_blank">&#128462; Download PDF</a>
        <button onclick="window.print()" class="btn btn-white">&#128424; Print</button>
    </div>
</div>

<!-- Filter bar -->
<form method="GET" class="filter-bar">
    <input type="hidden" name="page" value="oss_print">
    <label>Cycle:</label>
    <select name="cycle_id">
        <?php foreach ($all_cycles as $cy): ?>
        <option value="<?= $cy['cycle_id'] ?>" <?= $cy['cycle_id'] === $cycle_id ? 'selected' : '' ?>>
            <?= htmlspecialchars($cy['cycle_name']) ?> (<?= ucfirst($cy['status']) ?>)
        </option>
        <?php endforeach; ?>
    </select>
    <label>Campus:</label>
    <select name="campus_id">
        <option value="0">All Campuses</option>
        <?php foreach ($all_campuses as $cp): ?>
        <option value="<?= $cp['campus_id'] ?>" <?= $cp['campus_id'] === $campus_id ? 'selected' : '' ?>><?= htmlspecialchars($cp['campus_name']) ?></option>
        <?php endforeach; ?>
    </select>
    <label>Status:</label>
    <select name="status">
        <option value="">All</option>
        <?php foreach (['submitted','under_review','approved','rejected','reclassified'] as $s): ?>
        <option value="<?= $s ?>" <?= $status === $s ? 'selected' : '' ?>><?= ucwords(str_replace('_',' ',$s)) ?></option>
        <?php endforeach; ?>
    </select>
    <button type="submit" class="btn-apply">Apply</button>
</form>

<div class="page-wrap">

    <!-- Document Header -->
    <div class="doc-header">
        <img src="../assets/images/logo.jpg" alt="Institution Logo">
        <div class="doc-header-center">
            <div class="institution">State Universities and Colleges</div>
            <div class="system-name">SUC Faculty Reclassification Management System</div>
            <div class="doc-title-bar">
                <div class="form-title">Overall Score Summary (OSS)</div>
            </div>
        </div>

    </div>

    <!-- Summary line (inline, no boxes) -->
    <div class="summary-line">
        <span class="cycle-lbl">Cycle: <?= htmlspecialchars($selected_cycle['cycle_name'] ?? 'All') ?></span>
        <?php if ($campus_id): foreach ($all_campuses as $cp) { if ($cp['campus_id'] === $campus_id) echo '<span style="margin-right:8px;">Campus: <strong>' . htmlspecialchars($cp['campus_name']) . '</strong></span>'; } endif; ?>
        <?php if ($status): ?><span style="margin-right:8px;">Status: <strong><?= ucwords(str_replace('_',' ',$status)) ?></strong></span><?php endif; ?>
        <span class="total-lbl">Total Records: <strong><?= $total ?></strong></span>
        &nbsp;&nbsp;
        <span class="summary-stat"><span class="sv"><?= $total ?></span><span class="sl">Total</span></span>
        <span class="summary-stat"><span class="sv"><?= $status_counts['reclassified'] ?? 0 ?></span><span class="sl">Reclassified</span></span>
        <span class="summary-stat"><span class="sv"><?= $status_counts['approved'] ?? 0 ?></span><span class="sl">Approved</span></span>
        <span class="summary-stat"><span class="sv"><?= ($status_counts['submitted'] ?? 0) + ($status_counts['under_review'] ?? 0) ?></span><span class="sl">Pending</span></span>
        <span class="summary-stat"><span class="sv"><?= $status_counts['rejected'] ?? 0 ?></span><span class="sl">Returned</span></span>
    </div>

    <!-- KRA averages -->
    <div class="kra-avg-row">
        <?php
        $kra_short = [
            'Instruction'              => 'KRA I &mdash; Instruction',
            'Research'                 => 'KRA II &mdash; Research',
            'Extension'                => 'KRA III &mdash; Extension',
            'Professional Development' => 'KRA IV &mdash; Prof. Dev.',
        ];
        foreach ($kra_avg_vals as $cat => $avg): ?>
        <div class="kra-avg-box">
            <div class="kn"><?= $kra_short[$cat] ?></div>
            <div class="kv"><?= $avg ?></div>
            <div class="km">avg / 100 pts</div>
        </div>
        <?php endforeach; ?>
    </div>

    <!-- Faculty Score List -->
    <div class="section-title">Faculty Score List</div>
    <?php
    $by_campus = [];
    foreach ($applications as $a) {
        $by_campus[$a['campus_name']][] = $a;
    }
    ?>
    <?php if (empty($applications)): ?>
    <div class="empty-msg">No applications found for the selected filters.</div>
    <?php else: ?>
    <?php $row_num = 1; foreach ($by_campus as $campus_name => $campus_apps): ?>
    <div class="campus-group">
        <div class="campus-header"><?= htmlspecialchars($campus_name) ?> &nbsp;(<?= count($campus_apps) ?> faculty)</div>
        <table class="main">
            <thead>
                <tr>
                    <th style="width:16px;">#</th>
                    <th class="left" style="min-width:90px;">Faculty Name</th>
                    <th style="width:55px;">Employee ID</th>
                    <th style="min-width:60px;">Rank</th>
                    <th style="width:32px;">KRA I</th>
                    <th style="width:32px;">KRA II</th>
                    <th style="width:32px;">KRA III</th>
                    <th style="width:32px;">KRA IV</th>
                    <th style="width:36px;">Total</th>
                    <th style="width:40px;">Weighted</th>
                    <th style="width:32px;">+SR</th>
                    <th style="width:55px;">Status</th>
                    <th style="min-width:55px;">Reviewer</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($campus_apps as $a):
                $scores = $kra_scores[$a['application_id']] ?? [];
                $ws  = (float)$a['weighted_score'];
                $sc  = $ws >= 71 ? 'score-high' : ($ws >= 41 ? 'score-mid' : 'score-low');
                $inc = (int)$a['sub_rank_increment'];
            ?>
            <tr>
                <td class="center" style="color:#94a3b8;"><?= $row_num++ ?></td>
                <td style="font-weight:600;"><?= htmlspecialchars(formatDisplayName($a)) ?></td>
                <td class="center"><?= htmlspecialchars($a['employee_id'] ?? '—') ?></td>
                <td style="font-size:7.5pt;"><?= htmlspecialchars($a['rank'] ?? '—') ?></td>
                <td class="center"><?= number_format($scores['Instruction'] ?? 0, 2) ?></td>
                <td class="center"><?= number_format($scores['Research'] ?? 0, 2) ?></td>
                <td class="center"><?= number_format($scores['Extension'] ?? 0, 2) ?></td>
                <td class="center"><?= number_format($scores['Professional Development'] ?? 0, 2) ?></td>
                <td class="<?= $sc ?>"><?= number_format((float)$a['total_score'], 2) ?></td>
                <td class="<?= $sc ?>"><?= number_format($ws, 2) ?></td>
                <td class="center" style="font-weight:700;color:<?= $inc>0?'#1e4d8c':'#94a3b8' ?>;"><?= $inc > 0 ? '+'.$inc : '—' ?></td>
                <td class="center"><span class="badge badge-<?= $a['status'] ?>"><?= ucwords(str_replace('_',' ',$a['status'])) ?></span></td>
                <td style="font-size:7.5pt;"><?php
                    $chk = '—';
                    if (!empty($a['checker_uid'])) {
                        $chk = htmlspecialchars(checkerDisplayLabel([
                            'checker_label' => $a['checker_label'] ?? null,
                            'user_id'       => $a['checker_uid'],
                        ]));
                    }
                    echo $chk;
                ?></td>
            </tr>
            <?php endforeach; ?>
            <!-- Campus subtotal -->
            <?php
            $camp_ws   = array_filter(array_map(fn($a) => (float)$a['weighted_score'], $campus_apps));
            $avg_ws    = count($camp_ws) > 0 ? round(array_sum($camp_ws)/count($camp_ws), 2) : 0;
            $reclass_n = count(array_filter($campus_apps, fn($a) => $a['status'] === 'reclassified'));
            ?>
            <tr class="subtotal-row">
                <td colspan="9" style="text-align:right;padding-right:8px;">Campus Average Weighted Score:</td>
                <td class="center"><?= $avg_ws ?></td>
                <td colspan="3">Reclassified: <?= $reclass_n ?> / <?= count($campus_apps) ?></td>
            </tr>
            </tbody>
        </table>
    </div>
    <?php endforeach; ?>
    <?php endif; ?>

    <!-- Signatures -->
    <div class="sig-section">
        <div class="sig-title">Certification &amp; Signatures</div>
        <div class="sig-grid">
            <div>
                <div class="sig-block">
                    <div class="sig-line">
                        <div class="sig-name">___________________________</div>
                        <div class="sig-role">Prepared by &mdash; <?= htmlspecialchars($admin_name) ?></div>
                        <div class="sig-date">Date: ___________________</div>
                    </div>
                </div>
            </div>
            <div>
                <div class="sig-block">
                    <div class="sig-line">
                        <div class="sig-name">___________________________</div>
                        <div class="sig-role">Reviewed by &mdash; Campus Director / Dean</div>
                        <div class="sig-date">Date: ___________________</div>
                    </div>
                </div>
            </div>
            <div>
                <div class="sig-block">
                    <div class="sig-line">
                        <div class="sig-name">___________________________</div>
                        <div class="sig-role">Approved by &mdash; University President</div>
                        <div class="sig-date">Date: ___________________</div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Footer -->
    <div class="doc-footer">
        <span>SUCFRMS &mdash; Overall Score Summary (OSS)</span>
        <span><?= htmlspecialchars($selected_cycle['cycle_name'] ?? '') ?></span>
        <span>Printed: <?= $printed_at ?></span>
    </div>

</div>
</body>
</html>
