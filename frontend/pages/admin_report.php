<?php require __DIR__ . '/../../backend/controllers/pages/admin_report.php'; ?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>SUCFRMS - Admin Report</title>
<link href="../assets/vendor/bootstrap-icons/bootstrap-icons.min.css" rel="stylesheet">
<style>
@font-face { font-family:'Inter'; src:url('../assets/fonts/InterVariable.woff2') format('woff2'); font-weight:100 900; font-style:normal; font-display:swap; }
*,*::before,*::after { box-sizing:border-box; }
html,body { margin:0; }
body { font-family:'Inter',Arial,sans-serif; color:#172033; background:#eef2f7; font-size:13px; line-height:1.45; -webkit-print-color-adjust:exact; print-color-adjust:exact; }
.controls { position:sticky; top:0; z-index:20; display:flex; align-items:center; justify-content:space-between; gap:16px; padding:13px 22px; color:#fff; background:#17365f; box-shadow:0 1px 8px rgba(15,23,42,.16); }
.controls h6 { margin:0; font-size:14px; font-weight:700; }
.btn-group { display:flex; flex-wrap:wrap; gap:8px; }
.btn { display:inline-flex; align-items:center; gap:6px; padding:7px 13px; border:1px solid transparent; border-radius:7px; font:700 11px/1.2 'Inter',Arial,sans-serif; text-decoration:none; }
.btn-light { color:#17365f; background:#fff; }
.btn-outline { color:#fff; border-color:rgba(255,255,255,.5); background:transparent; }
.filter-bar { display:flex; align-items:center; flex-wrap:wrap; gap:9px; padding:10px 22px; border-bottom:1px solid #dbe3ee; background:#fff; }
.filter-bar label { color:#475569; font-size:11px; font-weight:700; }
.filter-bar select { min-width:150px; padding:6px 9px; border:1px solid #cbd5e1; border-radius:6px; color:#334155; background:#fff; font:500 11px/1.2 'Inter',Arial,sans-serif; }
.report-body { width:min(1180px,calc(100% - 32px)); margin:20px auto 32px; }
.report-card { margin-bottom:16px; padding:20px; border:1px solid #dbe3ee; border-radius:10px; background:#fff; box-shadow:0 4px 18px rgba(15,23,42,.06); }
.section-title { margin-bottom:14px; padding-bottom:7px; border-bottom:2px solid #17365f; color:#17365f; font-size:12px; font-weight:800; letter-spacing:.06em; text-transform:uppercase; }
.summary-grid { display:grid; grid-template-columns:repeat(6,minmax(0,1fr)); gap:10px; margin-bottom:14px; }
.summary-card { padding:13px 12px; border:1px solid #dbe3ee; border-radius:8px; background:#f8fafc; }
.summary-card .val { color:#17365f; font-size:22px; line-height:1.1; font-weight:800; }
.summary-card .lbl { margin-top:5px; color:#64748b; font-size:10px; font-weight:700; letter-spacing:.03em; text-transform:uppercase; }
.status-row { display:flex; flex-wrap:wrap; gap:6px; }
.status-pill,.badge { display:inline-flex; align-items:center; border-radius:999px; padding:3px 8px; font-size:10px; font-weight:700; white-space:nowrap; }
.kra-grid { display:grid; grid-template-columns:repeat(4,minmax(0,1fr)); gap:10px; }
.kra-card { padding:13px; border:1px solid #dbe3ee; border-radius:8px; background:#f8fafc; }
.kra-name { color:#17365f; font-size:11px; font-weight:700; }
.kra-val { margin-top:3px; font-size:20px; font-weight:800; }
.kra-val span { color:#64748b; font-size:10px; font-weight:500; }
.kra-bar { height:5px; margin-top:8px; overflow:hidden; border-radius:999px; background:#e2e8f0; }
.kra-fill { height:100%; border-radius:999px; background:#1e4d8c; }
.table-wrapper { overflow-x:auto; }
table { width:100%; border-collapse:collapse; font-size:10px; }
th { padding:7px 6px; border:1px solid #334155; color:#fff; background:#17365f; font-size:9px; text-align:left; white-space:nowrap; }
td { padding:7px 6px; border:1px solid #dbe3ee; vertical-align:middle; }
tbody tr:nth-child(even) { background:#f8fafc; }
.score-high { color:#17365f; font-weight:800; }
.score-mid { color:#475569; font-weight:800; }
.score-low { color:#7f1d1d; font-weight:800; }
.badge-reclassified,.badge-approved,.badge-submitted { color:#1e4d8c; background:#dbeafe; }
.badge-under_review,.badge-draft,.badge-edit_requested { color:#475569; background:#f1f5f9; }
.badge-rejected,.badge-admin_rejected { color:#9f1239; background:#fff1f2; }
.empty-state { padding:28px; color:#64748b; text-align:center; }
.report-footer { display:flex; justify-content:flex-end; margin-top:14px; padding-top:8px; border-top:1px solid #dbe3ee; color:#64748b; font-size:10px; }
@media (max-width:900px) {
    .summary-grid { grid-template-columns:repeat(3,minmax(0,1fr)); }
    .kra-grid { grid-template-columns:repeat(2,minmax(0,1fr)); }
}
@media (max-width:600px) {
    .controls { align-items:flex-start; padding:12px 14px; }
    .filter-bar { align-items:stretch; padding:10px 14px; }
    .filter-bar label { width:100%; }
    .filter-bar select { width:100%; }
    .report-body { width:100%; margin:0; }
    .report-card { margin:0; padding:15px 12px; border-width:0 0 1px; border-radius:0; box-shadow:none; }
    .summary-grid,.kra-grid { grid-template-columns:repeat(2,minmax(0,1fr)); }
}
@page { size:A4 landscape; margin:12mm; }
@media print {
    body { background:#fff; color:#000; font-size:9pt; }
    .screen-only { display:none !important; }
    .report-body { width:100%; margin:0; }
    .report-card { margin:0 0 10px; padding:0; border:0; border-radius:0; box-shadow:none; break-inside:avoid; }
    .summary-grid { grid-template-columns:repeat(6,1fr); }
    .kra-grid { grid-template-columns:repeat(4,1fr); }
    .summary-card,.kra-card { padding:7px; border-color:#94a3b8; }
    .summary-card .val,.kra-val { font-size:13pt; }
    .table-wrapper { overflow:visible; }
    table { font-size:6.5pt; }
    th,td { padding:3px 4px; }
}
</style>
</head>
<body>

<!-- -- Screen controls -- -->
<div class="controls screen-only">
    <h6><i class="bi bi-clipboard-data"></i> &nbsp;SUCFRMS Admin Report</h6>
    <div class="btn-group">
        <a href="../../index.php?page=dashboard" class="btn btn-outline">&larr; Back to Dashboard</a>
        <a href="../../backend/services/reports/oss_pdf.php?cycle_id=<?= $cycle_id ?>&status=<?= urlencode($status) ?>&campus_id=<?= $campus ?>" class="btn btn-light">&#128424; Print Report</a>
        <a href="../../backend/services/reports/detailed_report_pdf.php?cycle_id=<?= $cycle_id ?>&status=<?= urlencode($status) ?>&campus_id=<?= $campus ?>" class="btn btn-light"><i class="bi bi-file-earmark-text"></i> Detailed Report</a>
    </div>
</div>

<!-- -- Filter bar -- -->
<form method="GET" class="filter-bar screen-only">
    <label>Cycle:</label>
    <select name="cycle_id" onchange="this.form.submit()">
        <option value="0">All Cycles</option>
        <?php foreach ($all_cycles as $cy): ?>
        <option value="<?= $cy['cycle_id'] ?>" <?= $cy['cycle_id'] === $cycle_id ? 'selected' : '' ?>>
            <?= htmlspecialchars($cy['cycle_name']) ?> (<?= ucfirst($cy['status']) ?>)
        </option>
        <?php endforeach; ?>
    </select>
    <label>Status:</label>
    <select name="status" onchange="this.form.submit()">
        <option value="">All Statuses</option>
        <?php foreach (['submitted','under_review','approved','rejected','reclassified','admin_rejected'] as $s): ?>
        <option value="<?= $s ?>" <?= $status === $s ? 'selected' : '' ?>><?= $status_labels[$s] ?? ucwords(str_replace('_',' ',$s)) ?></option>
        <?php endforeach; ?>
    </select>
    <label>Campus:</label>
    <select name="campus_id" onchange="this.form.submit()">
        <option value="0">All Campuses</option>
        <?php foreach ($all_campuses as $cp): ?>
        <option value="<?= $cp['campus_id'] ?>" <?= $campus === $cp['campus_id'] ? 'selected' : '' ?>><?= htmlspecialchars($cp['campus_name']) ?></option>
        <?php endforeach; ?>
    </select>
</form>

<!-- -- Report body -- -->
<div class="report-body">

    <!-- Summary -->
    <div class="report-card">
        <div class="section-title">Summary Statistics</div>
        <div class="summary-grid">
            <div class="summary-card">
                <div class="val"><?= $total_faculty ?></div>
                <div class="lbl">Total Faculty</div>
            </div>
            <div class="summary-card">
                <div class="val"><?= count($apps) ?></div>
                <div class="lbl">Applications<?= $status ? ' ('.($status_labels[$status] ?? ucwords(str_replace('_',' ',$status))).')' : '' ?></div>
            </div>
            <div class="summary-card">
                <div class="val"><?= $status_counts['reclassified'] ?? 0 ?></div>
                <div class="lbl">Evaluation Complete</div>
            </div>
            <div class="summary-card">
                <div class="val"><?= $status_counts['approved'] ?? 0 ?></div>
                <div class="lbl">Evaluation Complete</div>
            </div>
            <div class="summary-card">
                <div class="val"><?= ($status_counts['submitted'] ?? 0) + ($status_counts['under_review'] ?? 0) ?></div>
                <div class="lbl">Pending Review</div>
            </div>
            <div class="summary-card">
                <div class="val"><?= $status_counts['rejected'] ?? 0 ?></div>
                <div class="lbl">Returned</div>
            </div>
        </div>
        <div style="font-size:0.78rem;color:#64748b;margin:-0.4rem 0 1rem;">
            <i class="bi bi-info-circle me-1"></i>Scores and recommended ranks are for further committee review and are not final decisions.
        </div>

        <!-- Status breakdown pills -->
        <?php if (!$status && !empty($status_counts)): ?>
        <div class="status-row">
            <?php
            $pill_colors = [
                'draft'          => 'background:#f1f5f9;color:#475569',
                'submitted'      => 'background:#dbeafe;color:#1e4d8c',
                'under_review'   => 'background:#f1f5f9;color:#334155',
                'approved'       => 'background:#dbeafe;color:#1a3a6b',
                'rejected'       => 'background:#f8fafc;color:#1a3a6b',
                'reclassified'   => 'background:#e0f2fe;color:#0369a1',
                'edit_requested' => 'background:#f1f5f9;color:#334155',
            ];
            foreach ($status_counts as $st => $cnt):
            ?>
            <span class="status-pill" style="<?= $pill_colors[$st] ?? 'background:#f1f5f9;color:#475569' ?>">
                <?= $status_labels[$st] ?? ucwords(str_replace('_',' ',$st)) ?>: <?= $cnt ?>
            </span>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </div>

    <!-- KRA Averages -->
    <div class="report-card">
        <div class="section-title">Average KRA Scores <?= $selected_cycle ? '- ' . htmlspecialchars($selected_cycle['cycle_name']) : '' ?></div>
        <div class="kra-grid">
            <?php
            $kra_max    = ['Instruction'=>100,'Research'=>100,'Extension'=>100,'Professional Development'=>100];
            $kra_labels = ['Instruction'=>'KRA I - Instruction','Research'=>'KRA II - Research','Extension'=>'KRA III - Extension','Professional Development'=>'KRA IV - Prof. Dev.'];
            foreach ($kra_avgs as $cat => $avg):
                $max = $kra_max[$cat];
                $pct = $max > 0 ? min(100, ($avg / $max) * 100) : 0;
            ?>
            <div class="kra-card">
                <div class="kra-name"><?= $kra_labels[$cat] ?></div>
                <div class="kra-val"><?= $avg ?> <span>/ <?= $max ?> pts</span></div>
                <div class="kra-bar"><div class="kra-fill" style="width:<?= $pct ?>%;"></div></div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>

    <!-- Applications table -->
    <div class="report-card">
        <div class="section-title">Applications (<?= count($apps) ?>)</div>
        <?php if ($apps): ?>
        <div class="table-wrapper">
        <table>
            <thead>
                <tr>
                    <th>#</th>
                    <th>Faculty Name</th>
                    <th>Employee ID</th>
                    <th>Campus</th>
                    <th>Rank</th>
                    <th>Cycle</th>
                    <th>Weighted Score</th>
                    <th>Sub-rank</th>
                    <th>Status</th>
                    <th>Reviewed By</th>
                    <th>Submitted</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($apps as $i => $a):
                $ws  = (float)$a['weighted_score'];
                $sc  = $ws >= 71 ? 'score-high' : ($ws >= 41 ? 'score-mid' : 'score-low');
                $inc = (int)$a['sub_rank_increment'];
            ?>
            <tr>
                <td style="color:#94a3b8;"><?= $i + 1 ?></td>
                <td style="font-weight:600;"><?= htmlspecialchars(formatDisplayName($a)) ?></td>
                <td><?= htmlspecialchars($a['employee_id'] ?? '-') ?></td>
                <td><?= htmlspecialchars($a['campus_name']) ?></td>
                <td><?= htmlspecialchars($a['rank'] ?? '-') ?></td>
                <td><?= htmlspecialchars($a['cycle_name'] ?? '-') ?></td>
                <td class="<?= $sc ?>"><?= number_format($ws, 2) ?></td>
                <td style="text-align:center;">
                    <?= $inc > 0 ? "<span style='color:#1e4d8c;font-weight:700;'>+{$inc}</span>" : '<span style="color:#94a3b8;">-</span>' ?>
                </td>
                <td><span class="badge badge-<?= $a['status'] ?>"><?= ucwords(str_replace('_',' ',$a['status'])) ?></span></td>
                <td><?= htmlspecialchars(!empty($a['checker_uid']) ? checkerDisplayLabel(['checker_label'=>$a['checker_name'],'user_id'=>$a['checker_uid']]) : '-') ?></td>
                <td><?= $a['submitted_at'] ? date('M d, Y', strtotime($a['submitted_at'])) : '-' ?></td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
        <?php else: ?>
        <div class="empty-state">No applications found for the selected filters.</div>
        <?php endif; ?>

        <!-- Footer -->
        <div class="report-footer">
            <span>Generated <?= $generated_at ?> by <?= htmlspecialchars($admin_name) ?></span>
        </div>
    </div>

</div>

<script>
// Auto-print if ?print=1 is in the URL
if (new URLSearchParams(window.location.search).get('print') === '1') {
    window.addEventListener('load', () => setTimeout(() => window.print(), 400));
}
</script>
</body>
</html>
