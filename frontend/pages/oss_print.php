<?php require __DIR__ . '/../../backend/controllers/pages/oss_print.php'; ?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>OSS  -  Overall Score Summary</title>
<style>
@font-face { font-family:'Inter'; src:url('../assets/fonts/InterVariable.woff2') format('woff2'); font-weight:100 900; font-style:normal; font-display:swap; }
* { margin:0; padding:0; box-sizing:border-box; }
body { font-family:'Inter', Arial, sans-serif; font-size:10pt; line-height:1.4; color:#172033; background:#eef2f7; -webkit-print-color-adjust:exact; print-color-adjust:exact; }

/* -- Screen toolbar -- */
.toolbar { background:#17365f; color:#fff; padding:11px 20px; display:flex; align-items:center; justify-content:space-between; gap:10px; flex-wrap:wrap; position:sticky; top:0; z-index:100; box-shadow:0 1px 8px rgba(15,23,42,.16); }
.toolbar h2 { font-size:13px; font-weight:700; letter-spacing:-.01em; }
.toolbar-actions { display:flex; gap:7px; flex-wrap:wrap; }
.btn { padding:7px 13px; border-radius:7px; border:none; font:700 11px/1.2 'Inter',Arial,sans-serif; cursor:pointer; text-decoration:none; display:inline-flex; align-items:center; gap:5px; }
.btn-white { background:#fff; color:#1a3a6b; }
.btn-outline { background:transparent; color:#fff; border:1px solid rgba(255,255,255,0.55); }

/* -- Filter bar -- */
.filter-bar { background:#fff; border-bottom:1px solid #e2e8f0; padding:9px 20px; display:flex; gap:10px; flex-wrap:wrap; align-items:center; font-size:11px; }
.filter-bar label { font-weight:700; color:#475569; }
.filter-bar select { font:500 11px/1.2 'Inter',Arial,sans-serif; padding:6px 8px; border:1px solid #cbd5e1; border-radius:6px; color:#334155; background:#fff; }
.filter-bar .btn-apply { background:#17365f; color:#fff; padding:7px 13px; border-radius:6px; border:none; font:700 11px/1.2 'Inter',Arial,sans-serif; cursor:pointer; }
@media print { .toolbar, .filter-bar { display:none !important; } }

/* -- Document page -- */
@page { size: A4 portrait; margin: 15mm 18mm 18mm 18mm; }
.page-wrap { background:#fff; max-width:760px; margin:0 auto; padding:28px 32px; }
@media print { .page-wrap { max-width:100%; padding:0; margin:0; background:#fff; box-shadow:none; } }
@media screen { .page-wrap { margin:20px auto; box-shadow:0 8px 28px rgba(15,23,42,.10); } }

/* -- Document header -- */
/* -- Filter summary line -- */
.filter-line { font-size:8.5pt; color:#1e293b; margin-bottom:6px; }
.filter-line strong { color:#1a3a6b; }

/* -- Summary stats (inline, no boxes) -- */
.summary-line { font-size:9pt; margin-bottom:10px; border-bottom:1px solid #334155; padding-bottom:7px; }
.summary-line .cycle-lbl { font-weight:700; color:#1e293b; margin-right:8px; }
.summary-line .total-lbl { color:#475569; margin-right:16px; }
.summary-stat { display:inline-block; margin-right:16px; font-size:9pt; }
.summary-stat .sv { font-size:12pt; font-weight:700; color:#1a3a6b; line-height:1; display:block; }
.summary-stat .sl { font-size:7pt; text-transform:uppercase; letter-spacing:0.3px; color:#64748b; }

/* -- KRA averages -- */
.kra-avg-row { display:grid; grid-template-columns:repeat(2,1fr); gap:0; margin-bottom:14px; border:1px solid #334155; }
.kra-avg-box { border-right:1px solid #cbd5e1; border-bottom:1px solid #cbd5e1; }
.kra-avg-box:nth-child(2n) { border-right:none; }
.kra-avg-box:nth-child(3), .kra-avg-box:nth-child(4) { border-bottom:none; }
.kra-avg-box { padding:7px 9px; }
.kra-avg-box .kn { font-size:7.5pt; font-weight:700; color:#1a3a6b; }
.kra-avg-box .kv { font-size:12pt; font-weight:700; line-height:1.2; margin-top:1px; }
.kra-avg-box .km { font-size:7pt; color:#64748b; }

/* -- Section title -- */
.section-title { font-size:9pt; font-weight:700; text-transform:uppercase; letter-spacing:0.06em; color:#17365f; border-bottom:2px solid #334155; padding-bottom:4px; margin-bottom:7px; }

/* -- Main table -- */
table.main { width:100%; border-collapse:collapse; font-size:7pt; }
table.main thead tr { background:#17365f; color:#fff; }
table.main thead th { padding:4px; font-size:6.5pt; font-weight:700; border:1px solid #334155; text-align:center; white-space:nowrap; }
table.main thead th.left { text-align:left; }
table.main tbody tr:nth-child(even) { background:#f8fafc; }
table.main tbody td { padding:4px; border:1px solid #cbd5e1; vertical-align:middle; font-size:7pt; }
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

/* -- Campus header -- */
.campus-header { background:#17365f; color:#fff; font-weight:700; font-size:8.5pt; padding:6px 8px; border:1px solid #334155; margin-top:8px; }
.campus-group { page-break-inside:avoid; }

/* -- Subtotal row -- */
.subtotal-row td { background:#eff6ff !important; font-weight:700; border-top:2px solid #1a3a6b; color:#1a3a6b; font-size:7.5pt; }

/* -- Signature section -- */
.sig-section { margin-top:18px; }
.sig-title { font-size:9pt; font-weight:700; text-transform:uppercase; letter-spacing:0.5px; border-bottom:1px solid #000; padding-bottom:2px; margin-bottom:8px; }
.sig-grid { display:grid; grid-template-columns:1fr 1fr 1fr; gap:28px; }
.sig-block { margin-top:20px; }
.sig-line { border-top:1px solid #000; padding-top:3px; }
.sig-name { font-size:9pt; font-weight:700; }
.sig-role { font-size:8pt; color:#475569; }
.sig-date { font-size:8pt; color:#475569; margin-top:1px; }

/* -- Footer -- */
.doc-footer { margin-top:10px; border-top:1px solid #000; padding-top:3px; display:flex; justify-content:space-between; font-size:7.5pt; color:#64748b; }

/* -- Empty state -- */
.empty-msg { text-align:center; padding:14px; font-style:italic; font-size:9pt; color:#64748b; }
@media screen and (max-width:640px) {
    .page-wrap { margin:0; padding:18px 14px; box-shadow:none; overflow-x:auto; }
    .sig-grid { grid-template-columns:1fr; gap:8px; }
}
</style>
</head>
<body>

<!-- Screen toolbar -->
<div class="toolbar">
    <h2>OSS - Overall Score Summary</h2>
    <div class="toolbar-actions">
        <a href="../../index.php?page=dashboard" class="btn btn-outline">&larr; Back</a>
        <a href="../../backend/services/reports/oss_pdf.php?cycle_id=<?= $cycle_id ?>&campus_id=<?= $campus_id ?>&status=<?= urlencode($status) ?>" class="btn btn-white" target="_blank">&#128462; Download PDF</a>
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
        <option value="<?= $s ?>" <?= $status === $s ? 'selected' : '' ?>><?= $status_labels[$s] ?? ucwords(str_replace('_',' ',$s)) ?></option>
        <?php endforeach; ?>
    </select>
    <button type="submit" class="btn-apply">Apply</button>
</form>

<div class="page-wrap">

    <!-- Summary line (inline, no boxes) -->
    <div class="summary-line">
        <span class="cycle-lbl">Cycle: <?= htmlspecialchars($selected_cycle['cycle_name'] ?? 'All') ?></span>
        <?php if ($campus_id): foreach ($all_campuses as $cp) { if ($cp['campus_id'] === $campus_id) echo '<span style="margin-right:8px;">Campus: <strong>' . htmlspecialchars($cp['campus_name']) . '</strong></span>'; } endif; ?>
        <?php if ($status): ?><span style="margin-right:8px;">Status: <strong><?= $status_labels[$status] ?? ucwords(str_replace('_',' ',$status)) ?></strong></span><?php endif; ?>
        <span class="total-lbl">Total Records: <strong><?= $total ?></strong></span>
        &nbsp;&nbsp;
        <span class="summary-stat"><span class="sv"><?= $total ?></span><span class="sl">Total</span></span>
        <span class="summary-stat"><span class="sv"><?= $status_counts['reclassified'] ?? 0 ?></span><span class="sl">Evaluation Complete</span></span>
        <span class="summary-stat"><span class="sv"><?= $status_counts['approved'] ?? 0 ?></span><span class="sl">Evaluation Complete</span></span>
        <span class="summary-stat"><span class="sv"><?= ($status_counts['submitted'] ?? 0) + ($status_counts['under_review'] ?? 0) ?></span><span class="sl">Pending</span></span>
        <span class="summary-stat"><span class="sv"><?= $status_counts['rejected'] ?? 0 ?></span><span class="sl">Returned for Revision</span></span>
    </div>
    <div style="font-size:8pt;color:#64748b;margin:-4px 0 8px;">
        Scores and recommended ranks are for further committee review and are not final decisions.
    </div>

    <!-- KRA averages -->
    <div class="kra-avg-row">
        <?php
        $kra_short = [
            'Instruction'              => 'KRA I - Instruction',
            'Research'                 => 'KRA II - Research',
            'Extension'                => 'KRA III - Extension',
            'Professional Development' => 'KRA IV - Prof. Dev.',
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
                <td class="center"><?= htmlspecialchars($a['employee_id'] ?? ' - ') ?></td>
                <td style="font-size:7.5pt;"><?= htmlspecialchars($a['rank'] ?? ' - ') ?></td>
                <td class="center"><?= number_format($scores['Instruction'] ?? 0, 2) ?></td>
                <td class="center"><?= number_format($scores['Research'] ?? 0, 2) ?></td>
                <td class="center"><?= number_format($scores['Extension'] ?? 0, 2) ?></td>
                <td class="center"><?= number_format($scores['Professional Development'] ?? 0, 2) ?></td>
                <td class="<?= $sc ?>"><?= number_format((float)$a['total_score'], 2) ?></td>
                <td class="<?= $sc ?>"><?= number_format($ws, 2) ?></td>
                <td class="center" style="font-weight:700;color:<?= $inc>0?'#1e4d8c':'#94a3b8' ?>;"><?= $inc > 0 ? '+'.$inc : ' - ' ?></td>
                <td class="center"><span class="badge badge-<?= $a['status'] ?>"><?= ucwords(str_replace('_',' ',$a['status'])) ?></span></td>
                <td style="font-size:7.5pt;"><?php
                    $chk = ' - ';
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
                <td colspan="3">Evaluation Complete: <?= $reclass_n ?> / <?= count($campus_apps) ?></td>
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
                        <div class="sig-role">Prepared by - <?= htmlspecialchars($admin_name) ?></div>
                        <div class="sig-date">Date: ___________________</div>
                    </div>
                </div>
            </div>
            <div>
                <div class="sig-block">
                    <div class="sig-line">
                        <div class="sig-name">___________________________</div>
                        <div class="sig-role">Reviewed by - Campus Director / Dean</div>
                        <div class="sig-date">Date: ___________________</div>
                    </div>
                </div>
            </div>
            <div>
                <div class="sig-block">
                    <div class="sig-line">
                        <div class="sig-name">___________________________</div>
                        <div class="sig-role">Approved by - University President</div>
                        <div class="sig-date">Date: ___________________</div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Footer -->
    <div class="doc-footer">
        <span>SUCFRMS - Overall Score Summary (OSS)</span>
        <span><?= htmlspecialchars($selected_cycle['cycle_name'] ?? '') ?></span>
        <span>Printed: <?= $printed_at ?></span>
    </div>

</div>
</body>
</html>
