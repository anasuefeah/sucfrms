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
$where  = "WHERE u.role IN ('faculty','checker_faculty') AND a.cycle_id=?";
$params = [$cycle_id];
if ($campus_id) { $where .= ' AND u.campus_id=?'; $params[] = $campus_id; }
if ($status && in_array($status, ['submitted','under_review','approved','rejected','reclassified'])) {
    $where .= ' AND a.status=?'; $params[] = $status;
}

$rows = $pdo->prepare("
    SELECT a.*, u.full_name, u.first_name, u.middle_name, u.last_name, u.employee_id, u.rank,
           COALESCE(camp.campus_name,'&mdash;') as campus_name,
           chk.full_name as checker_name,
           chk.first_name as checker_first_name,
           chk.middle_name as checker_middle_name,
           chk.last_name as checker_last_name
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
<title>OSS &mdash; Overall Score Summary</title>
<style>
* { margin:0; padding:0; box-sizing:border-box; }
body { font-family:'Times New Roman', Times, serif; font-size:10pt; color:#000; background:#fff;
       -webkit-print-color-adjust:exact; print-color-adjust:exact; }

/* Screen toolbar */
.toolbar { background:#1e4d8c; color:#fff; padding:8px 16px; display:flex; align-items:center; justify-content:space-between; gap:8px; flex-wrap:wrap; }
.toolbar h2 { font-size:13px; font-weight:700; }
.toolbar-actions { display:flex; gap:8px; }
.btn { padding:5px 14px; border-radius:5px; border:none; font-size:11px; font-weight:700; cursor:pointer; text-decoration:none; display:inline-flex; align-items:center; gap:4px; }
.btn-white { background:#fff; color:#1a3a6b; }
.btn-outline { background:transparent; color:#fff; border:1px solid rgba(255,255,255,0.5); }

/* Filter bar */
.filter-bar { background:#f8fafc; border-bottom:1px solid #e2e8f0; padding:6px 16px; display:flex; gap:10px; flex-wrap:wrap; align-items:center; font-size:11px; }
.filter-bar label { font-weight:700; color:#475569; }
.filter-bar select { font-size:11px; padding:3px 6px; border:1px solid #cbd5e1; border-radius:4px; }
.filter-bar .btn-apply { background:#1e4d8c; color:#fff; padding:4px 12px; border-radius:4px; border:none; font-size:11px; font-weight:700; cursor:pointer; }
@media print { .toolbar, .filter-bar { display:none !important; } }

/* Page */
@page { size: A4 landscape; margin: 12mm 14mm; }
.page { max-width:1050px; margin:0 auto; padding:16px 20px; }
@media print { .page { max-width:100%; padding:0; } }

/* â”€â”€ Document header â”€â”€ */
.doc-header { display:flex; align-items:flex-start; gap:12px; margin-bottom:10px; }
.doc-header img { width:56px; height:56px; border-radius:50%; object-fit:cover; flex-shrink:0; }
.doc-header-center { flex:1; text-align:center; }
.doc-header-center .institution { font-size:11pt; font-weight:700; text-transform:uppercase; }
.doc-header-center .system-name { font-size:9pt; color:#475569; margin-top:1px; }
.doc-header-center .form-title { font-size:13pt; font-weight:700; margin-top:6px; text-transform:uppercase; letter-spacing:1px; border-top:2px solid #000; border-bottom:2px solid #000; padding:3px 0; }
.doc-header-center .form-subtitle { font-size:9pt; color:#475569; margin-top:2px; }
.doc-header-right { text-align:right; font-size:8.5pt; color:#475569; min-width:130px; }

/* â”€â”€ Filter summary â”€â”€ */
.filter-summary { font-size:9pt; color:#475569; margin-bottom:8px; display:flex; gap:16px; flex-wrap:wrap; }
.filter-summary span { font-weight:700; color:#1e293b; }

/* â”€â”€ Summary stats â”€â”€ */
.stats-row { display:grid; grid-template-columns:repeat(6,1fr); gap:6px; margin-bottom:10px; }
.stat-box { border:1px solid #000; border-radius:3px; padding:5px 8px; text-align:center; }
.stat-box .sv { font-size:14pt; font-weight:700; color:#1a3a6b; line-height:1; }
.stat-box .sl { font-size:7.5pt; color:#64748b; text-transform:uppercase; letter-spacing:0.3px; margin-top:2px; }

/* â”€â”€ KRA averages â”€â”€ */
.kra-avg-row { display:grid; grid-template-columns:repeat(4,1fr); gap:6px; margin-bottom:10px; }
.kra-avg-box { border:1px solid #000; border-radius:3px; padding:5px 8px; }
.kra-avg-box .kn { font-size:8pt; font-weight:700; color:#1a3a6b; }
.kra-avg-box .kv { font-size:12pt; font-weight:700; }
.kra-avg-box .km { font-size:7.5pt; color:#64748b; }

/* â”€â”€ Main table â”€â”€ */
.section-title { font-size:9pt; font-weight:700; text-transform:uppercase; letter-spacing:0.5px; border-bottom:2px solid #000; padding-bottom:3px; margin-bottom:5px; }
table.main { width:100%; border-collapse:collapse; font-size:8.5pt; }
table.main thead tr { background:#1e4d8c; color:#fff; }
table.main thead th { padding:4px 5px; font-size:8pt; font-weight:700; border:1px solid #000; text-align:center; white-space:nowrap; }
table.main thead th.left { text-align:left; }
table.main tbody tr:nth-child(even) { background:#f8fafc; }
table.main tbody td { padding:3.5px 5px; border:1px solid #cbd5e1; vertical-align:middle; }
table.main tbody td.center { text-align:center; }
table.main tbody td.score { text-align:center; font-weight:700; }
table.main tbody td.score-high { color:#1e4d8c; font-weight:700; text-align:center; }
table.main tbody td.score-mid  { color:#475569; font-weight:700; text-align:center; }
table.main tbody td.score-low  { color:#1e293b; font-weight:700; text-align:center; }
.badge { display:inline-block; padding:1px 5px; border-radius:3px; font-size:7.5pt; font-weight:700; }
.badge-reclassified   { background:#e0f2fe; color:#0369a1; }
.badge-approved       { background:#dbeafe; color:#1a3a6b; }
.badge-submitted      { background:#dbeafe; color:#1e4d8c; }
.badge-under_review   { background:#475569; color:#1e293b; }
.badge-rejected       { background:#f8fafc; color:#1a3a6b; }
.badge-admin_rejected { background:#f8fafc; color:#1a3a6b; }
.badge-draft          { background:#f1f5f9; color:#475569; }

/* â”€â”€ Signature â”€â”€ */
.sig-section { margin-top:18px; }
.sig-title { font-size:9pt; font-weight:700; text-transform:uppercase; letter-spacing:0.5px; margin-bottom:10px; border-bottom:1px solid #000; padding-bottom:3px; }
.sig-grid { display:grid; grid-template-columns:1fr 1fr 1fr; gap:30px; }
.sig-block { margin-top:24px; }
.sig-line { border-top:1px solid #000; padding-top:4px; }
.sig-name { font-size:10pt; font-weight:700; }
.sig-role { font-size:8.5pt; color:#475569; }
.sig-date { font-size:8.5pt; color:#475569; margin-top:2px; }

/* â”€â”€ Footer â”€â”€ */
.doc-footer { margin-top:12px; border-top:1px solid #000; padding-top:4px; display:flex; justify-content:space-between; font-size:8pt; color:#64748b; }

/* Page break between campuses */
.campus-group { page-break-inside:avoid; }
.campus-header { background:#1e4d8c; color:#fff; font-weight:700; font-size:9pt; padding:5px 8px; border:1px solid #000; margin-top:8px; }
</style>
</head>
<body>

<!-- Screen toolbar -->
<div class="toolbar">
    <h2>OSS &mdash; Overall Score Summary</h2>
    <div class="toolbar-actions">
        <a href="../index.php?page=dashboard" class="btn btn-outline">&larr; Back</a>
        <button onclick="window.print()" class="btn btn-white">&#128424; Print / Save PDF</button>
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

<div class="page">

    <!-- Document Header -->
    <div class="doc-header">
        <img src="../assets/images/logo.jpg" alt="Institution Logo">
        <div class="doc-header-center">
            <div class="institution">State Universities and Colleges</div>
            <div class="system-name">SUC Faculty Reclassification Management System</div>
            <div class="form-title">Overall Score Summary (OSS)</div>
            <div class="form-subtitle">DBM-CHED Joint Circular No. 01, s. 2026 | CHMSU-FT Pilot Implementation</div>
        </div>
        <div class="doc-header-right">
            <div><?= htmlspecialchars($selected_cycle['cycle_name'] ?? 'All Cycles') ?></div>
            <?php if ($selected_cycle && !empty($selected_cycle['start_date'])): ?>
            <div><?= date('M d, Y', strtotime($selected_cycle['start_date'])) ?> &ndash; <?= date('M d, Y', strtotime($selected_cycle['end_date'])) ?></div>
            <?php endif; ?>
            <div style="margin-top:4px;">Printed: <?= $printed_at ?></div>
            <div>By: <?= htmlspecialchars($admin_name) ?></div>
        </div>
    </div>

    <!-- Filter summary line -->
    <div class="filter-summary">
        Cycle: <span><?= htmlspecialchars($selected_cycle['cycle_name'] ?? 'All') ?></span>
        <?php if ($campus_id): foreach ($all_campuses as $cp) { if ($cp['campus_id'] === $campus_id) { echo 'Campus: <span>' . htmlspecialchars($cp['campus_name']) . '</span>'; } } endif; ?>
        <?php if ($status): ?>Status: <span><?= ucwords(str_replace('_',' ',$status)) ?></span><?php endif; ?>
        Total Records: <span><?= $total ?></span>
    </div>

    <!-- Summary stats -->
    <div class="stats-row">
        <div class="stat-box"><div class="sv"><?= $total ?></div><div class="sl">Total</div></div>
        <div class="stat-box"><div class="sv"><?= $status_counts['reclassified'] ?? 0 ?></div><div class="sl">Reclassified</div></div>
        <div class="stat-box"><div class="sv"><?= $status_counts['approved'] ?? 0 ?></div><div class="sl">Approved</div></div>
        <div class="stat-box"><div class="sv"><?= ($status_counts['submitted'] ?? 0) + ($status_counts['under_review'] ?? 0) ?></div><div class="sl">Pending</div></div>
        <div class="stat-box"><div class="sv"><?= $status_counts['rejected'] ?? 0 ?></div><div class="sl">Returned</div></div>
    </div>

    <!-- KRA averages -->
    <div class="kra-avg-row">
        <?php
        $kra_max = ['Instruction'=>100,'Research'=>100,'Extension'=>100,'Professional Development'=>100];
        $kra_short = ['Instruction'=>'KRA I &mdash; Instruction','Research'=>'KRA II &mdash; Research','Extension'=>'KRA III &mdash; Extension','Professional Development'=>'KRA IV &mdash; Prof. Dev.'];
        foreach ($kra_avg_vals as $cat => $avg):
        ?>
        <div class="kra-avg-box">
            <div class="kn"><?= $kra_short[$cat] ?></div>
            <div class="kv"><?= $avg ?></div>
            <div class="km">avg / <?= $kra_max[$cat] ?> pts</div>
        </div>
        <?php endforeach; ?>
    </div>

    <!-- Applications table grouped by campus -->
    <div class="section-title">Faculty Score List</div>
    <?php
    // Group by campus
    $by_campus = [];
    foreach ($applications as $a) {
        $by_campus[$a['campus_name']][] = $a;
    }
    $row_num = 1;
    foreach ($by_campus as $campus_name => $campus_apps):
    ?>
    <div class="campus-group">
        <div class="campus-header">&#128205; <?= htmlspecialchars($campus_name) ?> &nbsp;(<?= count($campus_apps) ?> faculty)</div>
        <table class="main">
            <thead>
                <tr>
                    <th style="width:24px;">#</th>
                    <th class="left" style="min-width:140px;">Faculty Name</th>
                    <th style="width:80px;">Employee ID</th>
                    <th style="min-width:100px;">Rank</th>
                    <th style="width:55px;">KRA I</th>
                    <th style="width:55px;">KRA II</th>
                    <th style="width:55px;">KRA III</th>
                    <th style="width:55px;">KRA IV</th>
                    <th style="width:60px;">Total</th>
                    <th style="width:65px;">Weighted</th>
                    <th style="width:55px;">Sub-rank</th>
                    <th style="width:90px;">Workflow</th>
                    <th style="width:90px;">Status</th>
                    <th style="min-width:80px;">Reviewed By</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($campus_apps as $a):
                $scores = $kra_scores[$a['application_id']] ?? [];
                $ws = (float)$a['weighted_score'];
                $sc = $ws >= 71 ? 'score-high' : ($ws >= 41 ? 'score-mid' : 'score-low');
                $inc = (int)$a['sub_rank_increment'];
            ?>
            <tr>
                <td class="center" style="color:#94a3b8;"><?= $row_num++ ?></td>
                <td style="font-weight:600;"><?= htmlspecialchars(formatDisplayName($a)) ?></td>
                <td class="center"><?= htmlspecialchars($a['employee_id'] ?? '&mdash;') ?></td>
                <td style="font-size:7.5pt;"><?= htmlspecialchars($a['rank'] ?? '&mdash;') ?></td>
                <td class="center"><?= number_format($scores['Instruction'] ?? 0, 2) ?></td>
                <td class="center"><?= number_format($scores['Research'] ?? 0, 2) ?></td>
                <td class="center"><?= number_format($scores['Extension'] ?? 0, 2) ?></td>
                <td class="center"><?= number_format($scores['Professional Development'] ?? 0, 2) ?></td>
                <td class="<?= $sc ?>"><?= number_format((float)$a['total_score'], 2) ?></td>
                <td class="<?= $sc ?>"><?= number_format($ws, 2) ?></td>
                <td class="center">
                    <?= $inc > 0 ? "<strong style='color:#1e4d8c;'>+{$inc}</strong>" : '<span style="color:#94a3b8;">&mdash;</span>' ?>
                </td>
                <?php
                $wf = match($a['status']) {
                    'submitted','under_review','needs_revision','rejected','edit_requested' => 'Stage 1',
                    'talisay_review' => 'Stage 2',
                    'approved','reclassified','admin_rejected' => 'Completed',
                    default => 'Draft',
                };
                $wf_color = match($wf) {
                    'Stage 1' => '#1e4d8c', 'Stage 2' => '#475569', 'Completed' => '#16a34a', default => '#94a3b8'
                };
                ?>
                <td class="center" style="font-size:7.5pt;font-weight:700;color:<?= $wf_color ?>;"><?= $wf ?></td>
                <td class="center"><span class="badge badge-<?= $a['status'] ?>"><?= ucwords(str_replace('_',' ',$a['status'])) ?></span></td>
                <?php
                $chk_display = '&mdash;';
                if (!empty($a['checker_first_name']) || !empty($a['checker_last_name'])) {
                    $chk_display = htmlspecialchars(formatDisplayName([
                        'first_name'  => $a['checker_first_name']  ?? '',
                        'middle_name' => $a['checker_middle_name'] ?? '',
                        'last_name'   => $a['checker_last_name']   ?? '',
                        'full_name'   => $a['checker_name']        ?? '',
                    ]));
                }
                ?>
                <td style="font-size:7.5pt;"><?= $chk_display ?></td>
            </tr>
            <?php endforeach; ?>
            <!-- Campus subtotal -->
            <?php
            $camp_ws_vals = array_filter(array_map(fn($a) => (float)$a['weighted_score'], $campus_apps));
            $camp_avg_ws  = count($camp_ws_vals) > 0 ? round(array_sum($camp_ws_vals) / count($camp_ws_vals), 2) : 0;
            $camp_reclass = count(array_filter($campus_apps, fn($a) => $a['status'] === 'reclassified'));
            ?>
            <tr style="background:#eff6ff;font-weight:700;border-top:2px solid #1a3a6b;">
                <td colspan="8" style="text-align:right;font-size:8pt;">Campus Average Weighted Score:</td>
                <td colspan="2" style="text-align:center;color:#1a3a6b;"><?= $camp_avg_ws ?></td>
                <td colspan="4" style="font-size:8pt;">Reclassified: <?= $camp_reclass ?> / <?= count($campus_apps) ?></td>
            </tr>
            </tbody>
        </table>
    </div>
    <?php endforeach; ?>

    <?php if (empty($applications)): ?>
    <p style="color:#94a3b8;padding:12px 0;text-align:center;">No applications found for the selected filters.</p>
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
