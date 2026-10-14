<?php require __DIR__ . '/../../backend/controllers/pages/kra_print.php'; ?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>ISS - <?= htmlspecialchars(formatDisplayName($faculty)) ?></title>
<style>
@font-face { font-family:'Inter'; src:url('../assets/fonts/InterVariable.woff2') format('woff2'); font-weight:100 900; font-style:normal; font-display:swap; }
* { margin:0; padding:0; box-sizing:border-box; }
body { font-family:'Inter', Arial, sans-serif; font-size:10.5pt; line-height:1.45; color:#172033; background:#eef2f7;
       -webkit-print-color-adjust:exact; print-color-adjust:exact; }

/* Screen toolbar */
.toolbar { background:#17365f; color:#fff; padding:12px 20px; display:flex; align-items:center; justify-content:space-between; gap:12px; flex-wrap:wrap; box-shadow:0 1px 8px rgba(15,23,42,.16); }
.toolbar h2 { font-size:13px; font-weight:700; letter-spacing:-.01em; }
.toolbar-actions { display:flex; gap:8px; flex-wrap:wrap; }
.btn { padding:7px 14px; border-radius:7px; border:none; font:700 11px/1.2 'Inter',Arial,sans-serif; cursor:pointer; text-decoration:none; display:inline-flex; align-items:center; gap:5px; }
.btn-white { background:#fff; color:#1a3a6b; }
.btn-outline { background:transparent; color:#fff; border:1px solid rgba(255,255,255,0.5); }
@media print { .toolbar { display:none !important; } }

/* Page */
@page { size: A4 portrait; margin: 15mm 18mm 18mm 18mm; }
.page { max-width: 760px; margin: 20px auto; padding: 28px 32px; background:#fff; box-shadow:0 8px 28px rgba(15,23,42,.10); }
@media print { .page { max-width:100%; margin:0; padding:0; box-shadow:none; } }

/* -- Document header -- */
/* -- Faculty info table -- */
.info-table { width:100%; border-collapse:collapse; margin-bottom:14px; font-size:9.5pt; }
.info-table td { padding:6px 9px; border:1px solid #334155; }
.info-table .lbl { font-weight:700; background:#f1f5f9; width:130px; font-size:9pt; }
.info-table .val { font-weight:400; }

/* -- KRA section -- */
.kra-section { margin-bottom:14px; page-break-inside:avoid; }
.kra-title-row { background:#17365f; color:#fff; padding:7px 9px; font-size:9.5pt; font-weight:700; display:flex; justify-content:space-between; gap:12px; }
.kra-title-row .kra-max { font-size:9pt; font-weight:400; opacity:0.85; }
.kra-table { width:100%; border-collapse:collapse; font-size:9.5pt; }
.kra-table th { background:#eff6ff; padding:5px 7px; font-size:8.5pt; font-weight:700; border:1px solid #334155; text-align:center; }
.kra-table th.left { text-align:left; }
.kra-table td { padding:5px 7px; border:1px solid #cbd5e1; vertical-align:top; }
.kra-table td.center { text-align:center; }
.kra-table td.score { text-align:center; font-weight:700; }
.kra-table .subtotal-row td { background:#f0f4fb; font-weight:700; border-top:2px solid #1a3a6b; }
.kra-table .subtotal-row td.score { color:#1a3a6b; font-size:11pt; }
.no-entry { border:1px solid #cbd5e1; border-top:none; padding:8px; text-align:center; color:#94a3b8; font-style:italic; font-size:9pt; }
.verified-yes { color:#1a3a6b; font-weight:700; }
.verified-no  { color:#94a3b8; }

/* -- Weighted score table -- */
.weighted-table { width:100%; border-collapse:collapse; margin-bottom:14px; font-size:9pt; }
.weighted-table th { background:#17365f; color:#fff; padding:6px 8px; font-size:8.5pt; font-weight:700; border:1px solid #334155; text-align:center; }
.weighted-table td { padding:6px 8px; border:1px solid #334155; text-align:center; }
.weighted-table td.left { text-align:left; }
.weighted-table .total-row td { background:#f0f4fb; font-weight:700; font-size:11pt; }
.weighted-table .result-row td { background:#1e4d8c; color:#fff; font-weight:700; font-size:12pt; }

/* -- Grand total box -- */
.grand-box { border:2px solid #17365f; border-radius:6px; padding:10px 14px; margin-bottom:14px; display:flex; justify-content:space-between; align-items:center; gap:16px; }
.grand-box .gl { font-size:11pt; font-weight:700; }
.grand-box .gr { font-size:20pt; font-weight:700; color:#1a3a6b; }
.grand-box .gs { font-size:8.5pt; color:#64748b; margin-top:2px; }

/* -- Sub-rank result -- */
.result-box { border:2px solid #334155; border-radius:6px; padding:10px 14px; margin-bottom:14px; display:grid; grid-template-columns:1fr 1fr 1fr; gap:12px; text-align:center; }
.result-box .rb-label { font-size:8pt; color:#64748b; text-transform:uppercase; letter-spacing:0.4px; font-weight:700; }
.result-box .rb-val { font-size:14pt; font-weight:700; color:#1a3a6b; margin-top:2px; }
.result-box .rb-val.green { color:#1e4d8c; }

/* -- Signatures -- */
.sig-section { margin-top:20px; }
.sig-title { font-size:9pt; font-weight:700; text-transform:uppercase; letter-spacing:0.5px; margin-bottom:10px; border-bottom:1px solid #000; padding-bottom:3px; }
.sig-grid { display:grid; grid-template-columns:1fr 1fr; gap:30px; }
.sig-block { margin-top:30px; }
.sig-line { border-top:1px solid #000; padding-top:4px; }
.sig-name { font-size:10pt; font-weight:700; }
.sig-role { font-size:8.5pt; color:#475569; }
.sig-date { font-size:8.5pt; color:#475569; margin-top:2px; }

/* -- Footer -- */
.doc-footer { margin-top:16px; border-top:1px solid #000; padding-top:5px; display:flex; justify-content:space-between; font-size:8pt; color:#64748b; }
@media screen and (max-width:640px) {
    .page { margin:0; padding:18px 14px; box-shadow:none; }
    .result-box { grid-template-columns:1fr; }
    .sig-grid { grid-template-columns:1fr; gap:8px; }
}
</style>
</head>
<body>

<!-- Screen toolbar -->
<div class="toolbar">
    <h2>ISS - Individual Score Sheet &nbsp;&middot;&nbsp; <?= htmlspecialchars(formatDisplayName($faculty)) ?></h2>
    <div class="toolbar-actions">
        <?php
        $back_url = '../../index.php?page=dashboard';
        if (isAdmin()) $back_url = '../../index.php?page=view_application&id=' . $app_id;
        elseif (isChecker()) $back_url = '../../index.php?page=review_application&id=' . $app_id;
        ?>
        <a href="<?= $back_url ?>" class="btn btn-outline">&larr; Back</a>
        <a href="../../backend/services/reports/kra_pdf.php?uid=<?= $target_uid ?>&cycle_id=<?= $cycle['cycle_id'] ?>" class="btn btn-white">&#128424; Print ISS</a>
    </div>
</div>

<div class="page">

    <!-- Faculty Info -->
    <table class="info-table">
        <tr>
            <td class="lbl">Full Name</td>
            <td class="val" colspan="3"><strong><?= htmlspecialchars(formatDisplayName($faculty)) ?></strong></td>
        </tr>
        <tr>
            <td class="lbl">Employee ID</td>
            <td class="val"><?= htmlspecialchars($faculty['employee_id'] ?? '-') ?></td>
            <td class="lbl">Campus</td>
            <td class="val"><?= htmlspecialchars($faculty['campus_name'] ?? '-') ?></td>
        </tr>
        <tr>
            <td class="lbl">Current Rank</td>
            <td class="val"><?= htmlspecialchars($rank ?: '-') ?></td>
            <td class="lbl">Application Status</td>
            <td class="val"><?= ucwords(str_replace('_', ' ', $app['status'] ?? '-')) ?></td>
        </tr>
        <tr>
            <td class="lbl">Cycle</td>
            <td class="val"><?= htmlspecialchars($cycle['cycle_name']) ?></td>
            <td class="lbl">Reviewed By</td>
            <td class="val"><?= htmlspecialchars($checker ?? '-') ?></td>
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
            <span>KRA <?= $info['num'] ?> - <?= htmlspecialchars($info['label']) ?></span>
            <span class="kra-max"><?= $cap_label ?></span>
        </div>
        <?php if ($entries): ?>
        <table class="kra-table">
            <thead>
                <tr>
                    <th style="width:28px;">#</th>
                    <th class="left">Description / Remarks</th>
                    <th style="width:70px;">Points</th>
                    <th style="width:75px;">Acceptable</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($entries as $i => $s): ?>
            <tr>
                <td class="center" style="color:#94a3b8;"><?= $i + 1 ?></td>
                <td><?= formatKraRemarks($cat, $s['remarks'] ?? '') ?></td>
                <td class="score"><?= number_format((float)$s['computed_points'], 2) ?></td>
                <td class="center <?= $s['verified'] ? 'verified-yes' : 'verified-no' ?>">
                    <?= $s['verified'] ? '&#10004; Acceptable' : '' ?>
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
            'Instruction'              => 'KRA I - Instruction',
            'Research'                 => 'KRA II - Research',
            'Extension'                => 'KRA III - Extension',
            'Professional Development' => 'KRA IV - Prof. Development',
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
                <?= $inc > 0 ? "+{$inc} sub-rank" . ($inc > 1 ? 's' : '') : 'No sub-rank increment' ?>
            </div>
        </div>
        <div>
            <div class="rb-label">Current Rank</div>
            <div class="rb-val" style="font-size:10pt;"><?= htmlspecialchars($rank ?: '-') ?></div>
        </div>
    </div>
    <div style="font-size:8pt;color:#475569;margin-top:-8px;margin-bottom:12px;text-align:center;">
        This result is for further committee review and is not a final decision.
    </div>

    <!-- Signatures -->
    <div class="sig-section">
        <div class="sig-title">Certification &amp; Signatures</div>
        <div class="sig-grid">
            <div>
                <div class="sig-block">
                    <div class="sig-line">
                        <div class="sig-name"><?= htmlspecialchars(formatDisplayName($faculty)) ?></div>
                        <div class="sig-role">Faculty - Applicant</div>
                        <div class="sig-date">Date: ___________________</div>
                    </div>
                </div>
            </div>
            <div>
                <div class="sig-block">
                    <div class="sig-line">
                        <div class="sig-name"><?= htmlspecialchars($checker ?? '___________________________') ?></div>
                        <div class="sig-role">Evaluator</div>
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
        <span>SUCFRMS - Individual Score Sheet (ISS)</span>
        <span>Printed: <?= $printed_at ?></span>
    </div>

</div>
</body>
</html>
