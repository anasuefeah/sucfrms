<?php if ((require __DIR__ . '/../../../backend/controllers/admin/analytics.php') === false) return; ?>

<!-- ── Page header bar ─────────────────────────────────────────────────────── -->
<div class="mb-6 flex flex-wrap items-center justify-between gap-3 rounded-xl border border-slate-200 bg-white px-5 py-4 shadow-card">
    <div class="flex items-center [gap:0.65rem]">
        <div class="[width:38px] [height:38px] [border-radius:9px] [background:#1e4d8c] flex items-center justify-center shrink-0">
            <i class="bi bi-graph-up-arrow [color:#fff] [font-size:0.95rem]"></i>
        </div>
        <div>
            <div class="font-bold [color:#1a3a6b] [font-size:0.95rem] leading-tight">Analytics</div>
            <div class="[font-size:0.72rem] [color:#94a3b8] [margin-top:2px]">Reclassification performance overview — all cycles and campuses</div>
        </div>
    </div>
    <div class="flex items-center [gap:0.5rem]">
        <span class="[font-size:0.7rem] [color:#94a3b8]"><?= $total_applied ?> total application<?= $total_applied !== 1 ? 's' : '' ?></span>
        <?php if ($active_cycle): ?>
        <span class="[background:#f0f4fb] [color:#1a3a6b] [border:1px_solid_#dbeafe] [border-radius:20px] [font-size:0.68rem] font-bold [padding:2px_10px]">
            Active Cycle
        </span>
        <?php endif; ?>
    </div>
</div>

<!-- ── KPI cards ──────────────────────────────────────────────────────────── -->
<div class="mb-6 grid grid-cols-1 gap-3 sm:grid-cols-2 xl:grid-cols-5">
<?php
$kpi = [
    ['Total Applied',       $total_applied,      'bi-send',                  '#1a3a6b'],
    ['Evaluation Complete', $total_complete,      'bi-check-circle',          '#334155'],
    ['Pending Review',      $total_pending,       'bi-hourglass-split',       '#475569'],
    ['Needs Revision',      $total_revision,      'bi-arrow-counterclockwise','#64748b'],
    ['Completion Rate',     $completion_rate.'%', 'bi-percent',               '#1a3a6b'],
];
foreach ($kpi as $kpi_index => [$label, $val, $icon, $col]):
    $kpi_class = ['border-l-brand-700 text-brand-700','border-l-slate-700 text-slate-700','border-l-slate-600 text-slate-600','border-l-slate-500 text-slate-500','border-l-brand-700 text-brand-700'][$kpi_index];
?>
<div class="rounded-xl border border-l-4 border-slate-200 bg-white px-4 py-3.5 shadow-card <?= $kpi_class ?>">
    <div class="flex items-center justify-between [margin-bottom:0.55rem]">
        <span class="[font-size:0.63rem] font-bold [color:#94a3b8] uppercase [letter-spacing:0.07em] leading-snug"><?= $label ?></span>
        <i class="bi <?= $icon ?> text-sm text-slate-300"></i>
    </div>
    <div class="text-2xl font-extrabold leading-none tracking-tight"><?= $val ?></div>
</div>
<?php endforeach; ?>
</div>

<!-- ── Insights ───────────────────────────────────────────────────────────── -->
<div class="neon-card mb-6 p-0 overflow-hidden [border:1px_solid_#e2e8f0]">

    <!-- Insights header -->
    <div class="[padding:0.8rem_1.25rem] [background:#f8fafc] [border-bottom:1px_solid_#e2e8f0] flex items-center justify-between">
        <div class="flex items-center [gap:0.5rem]">
            <i class="bi bi-lightbulb [color:#1a3a6b] [font-size:0.85rem]"></i>
            <span class="font-bold [color:#1a3a6b] [font-size:0.85rem]">Insights</span>
            <span class="[background:#1a3a6b] [color:#fff] [font-size:0.62rem] font-bold [border-radius:20px] [padding:1px_8px] [margin-left:2px]"><?= count($insights) ?></span>
        </div>
        <span class="[font-size:0.7rem] [color:#94a3b8]">System-generated observations based on current data</span>
    </div>

    <?php if (empty($insights)): ?>
    <div class="[padding:1.5rem_1.25rem] text-center [color:#94a3b8] [font-size:0.82rem]">
        <i class="bi bi-inbox [font-size:1.5rem] block [margin-bottom:0.5rem]"></i>
        No insights available yet. Data will appear once applications are submitted.
    </div>
    <?php else: ?>
    <div class="[padding:0.35rem_0]">
        <?php
        $severity_map = [
            'danger'  => ['Critical',  '#dc2626', '#fef2f2', '#fecaca'],
            'warning' => ['Warning',   '#92400e', '#fffbeb', '#fde68a'],
            'info'    => ['Info',      '#1e4d8c', '#f0f4fb', '#dbeafe'],
            'success' => ['Note',      '#166534', '#f0fdf4', '#bbf7d0'],
        ];
        foreach ($insights as $idx => [$type, $icon, $title, $msg]):
            [$sev_label, $sev_col, , ] = $severity_map[$type] ?? ['Info','#475569','#f8fafc','#e2e8f0'];
            $is_last = $idx === count($insights) - 1;
            $severity_class = match ($type) {
                'danger' => 'text-rose-600',
                'warning' => 'text-amber-700',
                'success' => 'text-emerald-700',
                default => 'text-brand-600',
            };
        ?>
        <div class="grid grid-cols-[5.625rem_1fr] items-start gap-3 px-5 py-2.5 <?= $is_last ? '' : 'border-b border-slate-100' ?>">
            <!-- Severity label -->
            <div class="[padding-top:1px]">
                <span class="text-[0.62rem] font-bold uppercase tracking-wider <?= $severity_class ?>"><?= $sev_label ?></span>
            </div>
            <!-- Content -->
            <div>
                <div class="flex items-center [gap:0.4rem] [margin-bottom:2px]">
                    <i class="bi <?= $icon ?> shrink-0 text-xs text-slate-600"></i>
                    <span class="[font-size:0.8rem] font-bold [color:#1e293b]"><?= htmlspecialchars($title) ?></span>
                </div>
                <div class="[font-size:0.77rem] [color:#475569] [line-height:1.55]"><?= htmlspecialchars($msg) ?></div>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>
</div>

<!-- ── Active cycle pipeline ──────────────────────────────────────────────── -->
<?php if ($active_cycle && $pending_queue): ?>
<div class="neon-card mb-4 p-0 overflow-hidden [border:1px_solid_#e2e8f0]">
    <div class="[padding:0.8rem_1.25rem] [background:#f8fafc] [border-bottom:1px_solid_#e2e8f0] flex items-center justify-between">
        <div class="flex items-center [gap:0.5rem]">
            <i class="bi bi-activity [color:#1a3a6b] [font-size:0.85rem]"></i>
            <span class="font-bold [color:#1a3a6b] [font-size:0.85rem]"><?= sanitize($active_cycle['cycle_name']) ?></span>
        </div>
        <span class="[font-size:0.7rem] [color:#94a3b8]">Current pipeline status</span>
    </div>
    <div class="grid grid-cols-2 p-0 lg:grid-cols-4">
        <?php
        $pipe = [
            ['Awaiting Start',  (int)($pending_queue['awaiting_start'] ?? 0)],
            ['Under Review',    (int)($pending_queue['under_review']   ?? 0)],
            ['Needs Revision',  (int)($pending_queue['needs_revision'] ?? 0)],
            ['Avg Wait (days)', round($pending_queue['avg_wait_days']  ?? 0)],
        ];
        foreach ($pipe as $pi => [$pl, $pv]):
        ?>
        <div class="p-4 text-center <?= $pi < 3 ? 'border-r border-slate-100' : '' ?>">
            <div class="[font-size:1.5rem] [font-weight:800] [color:#1a3a6b] leading-none"><?= $pv ?></div>
            <div class="[font-size:0.63rem] [color:#94a3b8] [margin-top:5px] font-semibold uppercase [letter-spacing:0.05em]"><?= $pl ?></div>
        </div>
        <?php endforeach; ?>
    </div>
</div>
<?php endif; ?>

<!-- ── Charts row 1 ───────────────────────────────────────────────────────── -->
<div class="mb-4 grid grid-cols-1 gap-4 xl:grid-cols-[minmax(0,1fr)_340px]">

    <div class="neon-card [padding:1.15rem_1.25rem] [border:1px_solid_#e2e8f0]">
        <div class="font-bold [color:#1a3a6b] [font-size:0.85rem] [margin-bottom:2px]">Applications per Cycle</div>
        <div class="[font-size:0.71rem] [color:#94a3b8] [margin-bottom:0.9rem]">Applied · Evaluation Complete · Reclassified</div>
        <div class="flex [gap:1.1rem] flex-wrap [margin-bottom:0.7rem]">
            <?php foreach ([[$navy_800,'Applied'],[$navy_600,'Evaluation Complete'],['#64748b','Reclassified']] as $legend_index => [$c,$l]): ?>
            <span class="flex items-center [gap:4px] [font-size:0.7rem] [color:#64748b]">
                <span class="size-2.5 shrink-0 rounded-sm <?= ['bg-brand-700','bg-brand-600','bg-slate-500'][$legend_index] ?>"></span><?= $l ?>
            </span>
            <?php endforeach; ?>
        </div>
        <div class="[height:220px]"><canvas id="cycleChart"></canvas></div>
    </div>

    <div class="neon-card [padding:1.15rem_1.25rem] [border:1px_solid_#e2e8f0]">
        <div class="font-bold [color:#1a3a6b] [font-size:0.85rem] [margin-bottom:2px]">Status Distribution</div>
        <div class="[font-size:0.71rem] [color:#94a3b8] [margin-bottom:0.7rem]">Pipeline across all cycles</div>
        <?php if (array_sum($pie_data) > 0): ?>
        <div class="[height:155px]"><canvas id="statusDonut"></canvas></div>
        <div class="[margin-top:0.6rem] flex flex-col [gap:4px]">
            <?php foreach ($pie_labels as $i => $pl): ?>
            <div class="flex items-center justify-between [font-size:0.71rem]">
                <div class="flex items-center [gap:5px] [color:#64748b]">
                    <span class="size-2 shrink-0 rounded-sm <?= ['bg-brand-700','bg-brand-600','bg-slate-600','bg-slate-500','bg-slate-400'][$i] ?? 'bg-slate-300' ?>"></span><?= $pl ?>
                </div>
                <strong class="[color:#1a3a6b]"><?= $pie_data[$i] ?></strong>
            </div>
            <?php endforeach; ?>
        </div>
        <?php else: ?>
        <div class="[height:155px] flex items-center justify-center [color:#94a3b8] [font-size:0.82rem]">No data yet</div>
        <?php endif; ?>
    </div>
</div>

<!-- ── Charts row 2 ───────────────────────────────────────────────────────── -->
<div class="mb-4 grid grid-cols-1 gap-4 lg:grid-cols-2">

    <div class="neon-card [padding:1.15rem_1.25rem] [border:1px_solid_#e2e8f0]">
        <div class="font-bold [color:#1a3a6b] [font-size:0.85rem] [margin-bottom:2px]">Score Distribution</div>
        <div class="[font-size:0.71rem] [color:#94a3b8] [margin-bottom:0.7rem]">Applications per weighted score band</div>
        <div class="[height:210px]"><canvas id="scoreDistChart"></canvas></div>
    </div>

    <div class="neon-card [padding:1.15rem_1.25rem] [border:1px_solid_#e2e8f0]">
        <div class="font-bold [color:#1a3a6b] [font-size:0.85rem] [margin-bottom:2px]">Campus Average Score</div>
        <div class="[font-size:0.71rem] [color:#94a3b8] [margin-bottom:0.7rem]">Avg weighted score per campus — min 41 to qualify</div>
        <?php if (!empty($dept_stats)): ?>
        <div class="[height:210px]"><canvas id="campusChart"></canvas></div>
        <?php else: ?>
        <div class="[height:210px] flex items-center justify-center [color:#94a3b8] [font-size:0.82rem]">No campus data yet</div>
        <?php endif; ?>
    </div>
</div>

<!-- ── KRA breakdown ──────────────────────────────────────────────────────── -->
<div class="neon-card mb-4 p-0 overflow-hidden [border:1px_solid_#e2e8f0]">
    <div class="[padding:0.8rem_1.25rem] [background:#f8fafc] [border-bottom:1px_solid_#e2e8f0]">
        <div class="font-bold [color:#1a3a6b] [font-size:0.85rem]">KRA Score Breakdown</div>
        <div class="[font-size:0.71rem] [color:#94a3b8]">Average, min, and max points per KRA category</div>
    </div>
    <div class="[padding:1.15rem_1.25rem]">
    <?php if (!empty($kra_avgs)):
        $kra_bar_cols = [$navy_800, $navy_600, '#475569', '#64748b'];
        foreach ($kra_avgs as $i => $kra):
            $pct  = min(100, round(($kra['avg_pts'] / 100) * 100, 1));
            $short = $kra_short[$kra['kra_category']] ?? $kra['kra_category'];
            $col  = $kra_bar_cols[$i % 4];
    ?>
    <div class="[margin-bottom:1rem]">
        <div class="flex justify-between items-baseline [margin-bottom:5px]">
            <div>
                <span class="[font-size:0.78rem] font-bold [color:#1a3a6b]"><?= $short ?></span>
                <span class="[font-size:0.7rem] [color:#64748b] [margin-left:6px]"><?= sanitize($kra['kra_category']) ?></span>
                <span class="[font-size:0.65rem] [color:#94a3b8] [margin-left:6px]"><?= number_format($kra['submissions']) ?> submissions</span>
            </div>
            <div class="flex [gap:1.2rem] [font-size:0.71rem] [color:#94a3b8]">
                <span>Min <strong class="[color:#475569]"><?= $kra['min_pts'] ?></strong></span>
                <span>Max <strong class="[color:#475569]"><?= $kra['max_pts'] ?></strong></span>
                <span class="font-bold text-brand-700">Avg <?= number_format($kra['avg_pts'], 2) ?> pts</span>
            </div>
        </div>
        <progress value="<?= $pct ?>" max="100" class="h-2 w-full overflow-hidden rounded-full accent-brand-600"></progress>
    </div>
    <?php endforeach; else: ?>
    <div class="text-center [padding:2rem] [color:#94a3b8] [font-size:0.82rem]">No KRA data yet</div>
    <?php endif; ?>
    </div>
</div>

<!-- ── Campus breakdown table ─────────────────────────────────────────────── -->
<?php if ($dept_stats): ?>
<div class="neon-card mb-4 p-0 overflow-hidden [border:1px_solid_#e2e8f0]">
    <div class="[padding:0.8rem_1.25rem] [background:#f8fafc] [border-bottom:1px_solid_#e2e8f0]">
        <div class="font-bold [color:#1a3a6b] [font-size:0.85rem]">Campus Breakdown</div>
        <div class="[font-size:0.71rem] [color:#94a3b8]">Faculty count, application progress, and average score per campus</div>
    </div>
    <div class="table-responsive">
        <table class="table-modern mb-0 min-w-[680px]">
            <thead>
                <tr class="[background:#f8fafc] [border-bottom:1px_solid_#e2e8f0]">
                    <?php foreach (['Campus','Faculty','Applied','In Progress','Complete','Avg Score'] as $th): ?>
                    <th class="<?= $th==='Campus'||$th==='Avg Score' ? 'text-left' : 'text-center' ?>"><?= $th ?></th>
                    <?php endforeach; ?>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($dept_stats as $idx => $d):
                $sc = (float)($d['avg_score'] ?? 0);
                $sc_pct = min(100, round($sc));
                $sc_col = $sc >= 71 ? '#15803d' : ($sc >= 41 ? $navy_700 : '#dc2626');
                $participation = $d['total_faculty'] > 0 ? round(($d['applied'] / $d['total_faculty']) * 100) : 0;
            ?>
            <tr>
                <td class="[padding:0.7rem_1rem] font-semibold [color:#1e293b]">
                    <?php if ($idx === 0 && count($dept_stats) > 1): ?>
                    <span class="[background:#f0fdf4] [color:#15803d] [font-size:0.58rem] font-bold [border-radius:3px] [padding:1px_5px] [border:1px_solid_#bbf7d0] [margin-right:5px]">Top</span>
                    <?php endif; ?>
                    <?= sanitize($d['campus_name']) ?>
                </td>
                <td class="[padding:0.7rem_0.75rem] text-center [color:#64748b] font-semibold"><?= $d['total_faculty'] ?></td>
                <td class="[padding:0.7rem_0.75rem] text-center">
                    <span class="font-bold [color:#1a3a6b]"><?= $d['applied'] ?></span>
                    <span class="[font-size:0.65rem] [color:#94a3b8] [margin-left:3px]">(<?= $participation ?>%)</span>
                </td>
                <td class="[padding:0.7rem_0.75rem] text-center font-semibold [color:#64748b]"><?= $d['in_progress'] ?></td>
                <td class="[padding:0.7rem_0.75rem] text-center font-semibold [color:#334155]"><?= $d['evaluation_complete'] ?></td>
                <td class="[padding:0.7rem_0.75rem]">
                    <div class="flex items-center [gap:0.5rem]">
                        <progress value="<?= $sc_pct ?>" max="100" class="h-1.5 flex-1 overflow-hidden rounded-full accent-brand-600"></progress>
                        <span class="min-w-10 text-xs font-bold <?= $sc >= 71 ? 'text-emerald-700' : ($sc >= 41 ? 'text-brand-700' : 'text-rose-600') ?>"><?= $sc > 0 ? number_format($sc,1) : '—' ?></span>
                    </div>
                </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endif; ?>

<!-- ── Evaluator workload ─────────────────────────────────────────────────── -->
<?php if (!empty($evaluator_workload)): ?>
<div class="neon-card mb-4 p-0 overflow-hidden [border:1px_solid_#e2e8f0]">
    <div class="[padding:0.8rem_1.25rem] [background:#f8fafc] [border-bottom:1px_solid_#e2e8f0]">
        <div class="font-bold [color:#1a3a6b] [font-size:0.85rem]">Evaluator Workload</div>
        <div class="[font-size:0.71rem] [color:#94a3b8]">Review activity per evaluator across all cycles</div>
    </div>
    <div class="table-responsive">
        <table class="table-modern mb-0 min-w-[680px]">
            <thead>
                <tr class="[background:#f8fafc] [border-bottom:1px_solid_#e2e8f0]">
                    <?php foreach (['Evaluator','Total Reviewed','Approved','Returned','Pending','Approval Rate'] as $th): ?>
                    <th class="<?= in_array($th,['Evaluator','Approval Rate']) ? 'text-left' : 'text-center' ?>"><?= $th ?></th>
                    <?php endforeach; ?>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($evaluator_workload as $ev):
                $total_ev = (int)$ev['total_reviewed'];
                $apr_rate = $total_ev > 0 ? round(($ev['approved'] / $total_ev) * 100) : 0;
                $label    = checkerDisplayLabel($ev);
            ?>
            <tr>
                <td class="[padding:0.7rem_1rem] font-semibold [color:#1e293b]"><?= sanitize($label) ?></td>
                <td class="[padding:0.7rem_0.75rem] text-center font-bold [color:#1a3a6b]"><?= $total_ev ?></td>
                <td class="[padding:0.7rem_0.75rem] text-center [color:#334155] font-semibold"><?= $ev['approved'] ?></td>
                <td class="[padding:0.7rem_0.75rem] text-center [color:#64748b] font-semibold"><?= $ev['rejected'] ?></td>
                <td class="[padding:0.7rem_0.75rem] text-center [color:#94a3b8] font-semibold"><?= $ev['pending'] ?></td>
                <td class="[padding:0.7rem_1rem]">
                    <div class="flex items-center [gap:0.5rem]">
                        <progress value="<?= $apr_rate ?>" max="100" class="h-1.5 flex-1 overflow-hidden rounded-full accent-brand-700"></progress>
                        <span class="[font-size:0.73rem] font-bold [color:#1a3a6b] [min-width:32px]"><?= $apr_rate ?>%</span>
                    </div>
                </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endif; ?>

<!-- ── Faculty stalled on revision ────────────────────────────────────────── -->
<?php if (!empty($at_risk)): ?>
<div class="neon-card mb-4 p-0 overflow-hidden [border:1px_solid_#e2e8f0] [border-left:3px_solid_#dc2626]">
    <div class="[padding:0.8rem_1.25rem] [background:#f8fafc] [border-bottom:1px_solid_#e2e8f0] flex items-center justify-between">
        <div class="flex items-center [gap:0.5rem]">
            <i class="bi bi-person-exclamation [color:#dc2626] [font-size:0.85rem]"></i>
            <span class="font-bold [color:#1a3a6b] [font-size:0.85rem]">Faculty Stalled on Revision</span>
            <span class="[background:#fef2f2] [color:#dc2626] [border:1px_solid_#fecaca] [font-size:0.62rem] font-bold [border-radius:20px] [padding:1px_8px]"><?= count($at_risk) ?></span>
        </div>
        <span class="[font-size:0.7rem] [color:#94a3b8]">No resubmission for more than 7 days</span>
    </div>
    <div class="table-responsive">
        <table class="table-modern mb-0 min-w-[600px]">
            <thead>
                <tr class="[background:#f8fafc] [border-bottom:1px_solid_#e2e8f0]">
                    <?php foreach (['Faculty','Campus','Score','Days Stalled'] as $th): ?>
                    <th class="<?= in_array($th,['Faculty','Campus']) ? 'text-left' : 'text-center' ?>"><?= $th ?></th>
                    <?php endforeach; ?>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($at_risk as $ar): ?>
            <tr>
                <td class="[padding:0.7rem_1rem]">
                    <div class="font-semibold [color:#1e293b]"><?= sanitize(trim($ar['first_name'].' '.$ar['last_name'])) ?></div>
                    <div class="[font-size:0.68rem] [color:#94a3b8]"><?= sanitize($ar['employee_id'] ?? '') ?></div>
                </td>
                <td class="[padding:0.7rem_0.75rem] [color:#64748b]"><?= sanitize($ar['campus_name'] ?? '—') ?></td>
                <td class="text-center font-bold <?= (float)$ar['weighted_score'] >= 41 ? 'text-slate-700' : 'text-rose-600' ?>">
                    <?= number_format((float)$ar['weighted_score'], 2) ?>
                </td>
                <td class="[padding:0.7rem_0.75rem] text-center">
                    <span class="[background:#f1f5f9] [color:#64748b] [border:1px_solid_#e2e8f0] [border-radius:20px] [padding:2px_10px] [font-size:0.73rem] font-bold">
                        <?= $ar['days_stale'] ?>d
                    </span>
                </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endif; ?>

<!-- ── Per-cycle summary ───────────────────────────────────────────────────── -->
<div class="neon-card mb-4 p-0 overflow-hidden [border:1px_solid_#e2e8f0]">
    <div class="[padding:0.8rem_1.25rem] [background:#f8fafc] [border-bottom:1px_solid_#e2e8f0]">
        <div class="font-bold [color:#1a3a6b] [font-size:0.85rem]">Per-Cycle Summary</div>
        <div class="[font-size:0.71rem] [color:#94a3b8]">Detailed breakdown by reclassification cycle</div>
    </div>
    <div class="table-responsive">
        <table class="table-modern mb-0 min-w-[700px]">
            <thead>
                <tr class="[background:#f8fafc] [border-bottom:1px_solid_#e2e8f0]">
                    <?php foreach (['Cycle','Applied','Pending','Complete','Avg Score','Completion Rate'] as $th): ?>
                    <th class="<?= in_array($th,['Cycle','Completion Rate']) ? 'text-left' : 'text-center' ?>"><?= $th ?></th>
                    <?php endforeach; ?>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($cycle_stats as $cs):
                $rate = $cs['applied'] > 0 ? round(($cs['evaluation_complete'] / $cs['applied']) * 100, 1) : 0;
                $avg  = round((float)($cs['avg_score'] ?? 0), 1);
                $rc   = $rate >= 75 ? '#334155' : ($rate >= 40 ? $navy_600 : ($rate > 0 ? '#64748b' : '#94a3b8'));
                $status_badge = match($cs['cycle_status']) {
                    'open'     => ['Open',     '#15803d','#f0fdf4','#bbf7d0'],
                    'closed'   => ['Closed',   '#475569','#f1f5f9','#e2e8f0'],
                    'archived' => ['Archived', '#94a3b8','#f8fafc','#f1f5f9'],
                    default    => ['—',        '#94a3b8','#f8fafc','#f1f5f9'],
                };
            ?>
            <tr>
                <td class="[padding:0.75rem_1rem] font-semibold [color:#1e293b]">
                    <div class="flex items-center [gap:0.5rem]">
                        <?= sanitize($cs['cycle_name']) ?>
                        <span class="badge <?= $cs['cycle_status'] === 'open' ? 'bg-success' : 'bg-secondary' ?>"><?= $status_badge[0] ?></span>
                    </div>
                </td>
                <td class="[padding:0.75rem_0.75rem] text-center font-bold [color:#1a3a6b]"><?= $cs['applied'] ?></td>
                <td class="[padding:0.75rem_0.75rem] text-center [color:#64748b] font-semibold"><?= $cs['pending'] ?></td>
                <td class="[padding:0.75rem_0.75rem] text-center [color:#334155] font-semibold"><?= $cs['evaluation_complete'] ?></td>
                <td class="text-center font-bold <?= $avg >= 41 ? 'text-slate-700' : 'text-slate-400' ?>"><?= $avg > 0 ? $avg : '—' ?></td>
                <td class="[padding:0.75rem_1rem]">
                    <div class="flex items-center [gap:0.6rem]">
                        <progress value="<?= $rate ?>" max="100" class="h-1.5 flex-1 overflow-hidden rounded-full accent-brand-700"></progress>
                        <span class="min-w-9 text-xs font-bold text-brand-700"><?= $rate ?>%</span>
                    </div>
                </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    const tt = { backgroundColor:'#1e293b', titleColor:'#f1f5f9', bodyColor:'#cbd5e1', cornerRadius:6, padding:9 };
    const gridColor = '#f1f5f9';
    const tickColor = '#94a3b8';
    const tickFont  = { size: 11 };

    new Chart(document.getElementById('cycleChart'), {
        type:'bar',
        data:{
            labels:<?= json_encode($cycle_labels) ?>,
            datasets:[
                { label:'Applied',             data:<?= json_encode($applied_data) ?>,  backgroundColor:'<?= $navy_800 ?>', borderRadius:4, borderSkipped:false },
                { label:'Evaluation Complete', data:<?= json_encode($complete_data) ?>, backgroundColor:'<?= $navy_600 ?>', borderRadius:4, borderSkipped:false },
                { label:'Reclassified',        data:<?= json_encode($reclass_data) ?>,  backgroundColor:'#94a3b8',         borderRadius:4, borderSkipped:false }
            ]
        },
        options:{
            responsive:true, maintainAspectRatio:false,
            plugins:{ legend:{display:false}, tooltip:{...tt} },
            scales:{
                y:{ beginAtZero:true, grid:{color:gridColor}, ticks:{color:tickColor,font:tickFont} },
                x:{ grid:{display:false}, ticks:{color:tickColor,font:tickFont,maxRotation:30,
                    callback(v){const l=this.getLabelForValue(v);return l.length>20?l.substr(0,20)+'…':l;}} }
            }
        }
    });

    <?php if (array_sum($pie_data) > 0): ?>
    new Chart(document.getElementById('statusDonut'), {
        type:'doughnut',
        data:{
            labels:<?= json_encode($pie_labels) ?>,
            datasets:[{ data:<?= json_encode($pie_data) ?>, backgroundColor:<?= json_encode($pie_colors) ?>, borderWidth:2, borderColor:'#fff', hoverOffset:5 }]
        },
        options:{
            responsive:true, maintainAspectRatio:false, cutout:'70%',
            plugins:{
                legend:{display:false},
                tooltip:{...tt, callbacks:{label:ctx=>` ${ctx.label}: ${ctx.parsed} (${Math.round(ctx.parsed/<?= max(1,array_sum($pie_data)) ?>*100)}%)`}}
            }
        }
    });
    <?php endif; ?>

    new Chart(document.getElementById('scoreDistChart'), {
        type:'bar',
        data:{
            labels:<?= json_encode($score_bucket_labels) ?>,
            datasets:[{ data:<?= json_encode($score_bucket_data) ?>,
                backgroundColor:['#94a3b8','#94a3b8','#475569','#475569','#334155','#1e4d8c','#1a3a6b','#0f2a56'],
                borderRadius:4, borderSkipped:false }]
        },
        options:{
            responsive:true, maintainAspectRatio:false,
            plugins:{ legend:{display:false}, tooltip:{...tt, callbacks:{label:ctx=>`${ctx.parsed.y} application(s)`}} },
            scales:{
                y:{ beginAtZero:true, grid:{color:gridColor}, ticks:{color:tickColor,font:tickFont,stepSize:1} },
                x:{ grid:{display:false}, ticks:{color:tickColor,font:tickFont} }
            }
        }
    });

    <?php if (!empty($dept_stats)): ?>
    new Chart(document.getElementById('campusChart'), {
        type:'bar',
        data:{
            labels:<?= json_encode($campus_names) ?>,
            datasets:[{
                data:<?= json_encode($campus_scores) ?>,
                backgroundColor:<?= json_encode(array_map(fn($s) => $s >= 41 ? $navy_800 : '#94a3b8', $campus_scores)) ?>,
                borderRadius:4, borderSkipped:false
            }]
        },
        options:{
            responsive:true, maintainAspectRatio:false, indexAxis:'y',
            plugins:{
                legend:{display:false},
                tooltip:{...tt, callbacks:{
                    label:ctx=>`Avg: ${ctx.parsed.x} pts`,
                    afterLabel:ctx=>ctx.parsed.x>=41?'Above minimum':'Below minimum (41)'
                }}
            },
            scales:{
                x:{ beginAtZero:true, max:100, grid:{color:gridColor}, ticks:{color:tickColor,font:tickFont} },
                y:{ grid:{display:false}, ticks:{color:tickColor,font:{...tickFont,weight:'600'},
                    callback(v){const l=this.getLabelForValue(v);return l.length>16?l.substr(0,16)+'…':l;}} }
            }
        }
    });
    <?php endif; ?>
});
</script>
