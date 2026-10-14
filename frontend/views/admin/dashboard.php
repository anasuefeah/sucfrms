<?php require __DIR__ . '/../../../backend/controllers/admin/dashboard.php'; ?>

<?php
$pic = $_SESSION['profile_pic'] ?? '';
$init_h = strtoupper(
    substr($_SESSION['first_name'] ?? '', 0, 1)
    . substr($_SESSION['last_name'] ?? '', 0, 1)
) ?: 'A';
?>

<header class="mb-6 flex flex-col items-start justify-between gap-4 sm:flex-row sm:items-center">
    <div class="flex min-w-0 items-center gap-4">
        <?php if ($pic): ?>
        <img src="<?= sanitize($pic) ?>" alt="Profile" class="size-12 shrink-0 rounded-xl object-cover ring-2 ring-slate-200">
        <?php else: ?>
        <div class="inline-flex size-12 shrink-0 items-center justify-center rounded-xl bg-brand-700 text-sm font-bold text-white">
            <?= $init_h ?>
        </div>
        <?php endif; ?>
        <div class="min-w-0">
            <h1 class="truncate text-base font-bold leading-tight text-slate-900">
                Welcome back, <?= sanitize($_SESSION['full_name'] ?? 'Admin') ?>
            </h1>
            <div class="mt-1 flex flex-wrap items-center gap-2 text-xs text-slate-400">
                <span class="inline-flex items-center gap-1.5">
                    <i class="bi bi-calendar3"></i>
                    <?= date('l, F j, Y') ?>
                </span>
                <span class="badge bg-warning">Admin</span>
            </div>
        </div>
    </div>
    <a href="frontend/pages/oss_print.php<?= $cycle ? '?cycle_id='.$cycle['cycle_id'] : '' ?>"
       target="_blank"
       class="btn btn-secondary btn-sm">
        <i class="bi bi-file-earmark-spreadsheet"></i>
        Print Report
    </a>
</header>

<?php if ($cycle): ?>
<section class="mb-5 flex min-w-0 flex-wrap items-center justify-between gap-4 rounded-2xl bg-brand-700 px-5 py-4 text-white shadow-brand">
    <div class="flex min-w-0 items-center gap-3">
        <span class="inline-flex size-10 shrink-0 items-center justify-center rounded-xl bg-white/10 text-white/90">
            <i class="bi bi-calendar-range"></i>
        </span>
        <div class="min-w-0">
            <h2 class="truncate text-sm font-bold text-white"><?= sanitize($cycle['cycle_name']) ?></h2>
            <div class="mt-1">
                <?php if ($cycle['status'] === 'open'): ?>
                <span class="inline-flex items-center gap-1.5 rounded-full bg-emerald-400/15 px-2.5 py-0.5 text-[0.6875rem] font-bold tracking-wide text-emerald-300 ring-1 ring-inset ring-emerald-300/30">
                    <span class="size-1.5 rounded-full bg-emerald-300"></span>Open
                </span>
                <?php elseif ($cycle['status'] === 'closed'): ?>
                <span class="inline-flex rounded-full bg-white/10 px-2.5 py-0.5 text-[0.6875rem] font-bold text-white/60 ring-1 ring-inset ring-white/15">Closed</span>
                <?php else: ?>
                <span class="inline-flex rounded-full bg-white/5 px-2.5 py-0.5 text-[0.6875rem] font-bold text-white/45">Archived</span>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <div class="flex flex-wrap items-center gap-4">
        <div class="text-right">
            <div class="text-[0.6875rem] font-medium tracking-wider text-white/50 uppercase">Pending review</div>
            <div class="text-2xl font-extrabold leading-none tabular-nums text-white"><?= $pending_review + $under_review ?></div>
        </div>
        <a href="?page=cycles" class="btn btn-sm border-white/20 bg-white/10 text-white hover:bg-white/20 hover:text-white">
            <i class="bi bi-gear"></i>
            Manage
        </a>
    </div>
</section>
<?php else: ?>
<section class="mb-5 flex flex-wrap items-center justify-between gap-3 rounded-xl border border-l-4 border-slate-200 border-l-brand-700 bg-slate-50 px-5 py-4">
    <div class="flex items-center gap-3">
        <span class="text-lg text-brand-700"><i class="bi bi-calendar-x"></i></span>
        <div>
            <h2 class="text-sm font-bold text-brand-700">No Active Reclassification Cycle</h2>
            <p class="mt-0.5 text-xs text-slate-500">Faculty cannot submit until a cycle is open.</p>
        </div>
    </div>
    <a href="?page=cycles" class="btn btn-primary btn-sm">Create Cycle</a>
</section>
<?php endif; ?>

<?php
$kpi = [
    ['Total Faculty',       $total_faculty,  'bi-people',               'brand',   'admin_faculty',  '?page=manage_users'],
    ['Submitted',           $total_applied,  'bi-send',                 'sky',     'admin_applied',  '?page=all_applications'],
    ['Awaiting Evaluator',  $pending_review, 'bi-hourglass-split',      'amber',   'admin_pending',  '?page=all_applications&filter=submitted'],
    ['Under Review',        $under_review,   'bi-clipboard-data',       'violet',  'admin_review',   '?page=all_applications&filter=under_review'],
    ['Needs Revision',      $needs_revision, 'bi-arrow-counterclockwise','rose',   'admin_revision', '?page=all_applications&filter=needs_revision'],
    ['Evaluation Complete', $total_approved, 'bi-check-circle',         'emerald', 'admin_approved', '?page=all_applications&filter=approved'],
];
?>
<section aria-label="Dashboard statistics" class="mb-6 grid grid-cols-1 gap-3 min-[420px]:grid-cols-2 lg:grid-cols-3 2xl:grid-cols-6">
    <?php foreach ($kpi as [$label, $val, $icon, $tone, $poll_key, $link]): ?>
        <?= statCard($label, $val, $icon, $tone, '', $poll_key, $link) ?>
    <?php endforeach; ?>
</section>

<section aria-label="Application analytics" class="mb-6 grid grid-cols-1 gap-4 xl:grid-cols-[minmax(0,1fr)_300px_300px]">
    <article class="neon-card p-5">
        <div class="mb-4 flex items-start justify-between gap-4">
            <div>
                <h2 class="text-sm font-bold text-brand-700">Submissions – Last 7 Days</h2>
                <p class="mt-0.5 text-xs text-slate-400">Daily application submission activity</p>
            </div>
            <?php $total_week = array_sum($trend_data); ?>
            <div class="shrink-0 text-right">
                <div class="text-xl font-extrabold leading-none tabular-nums text-brand-600"><?= $total_week ?></div>
                <div class="mt-1 text-[0.6875rem] text-slate-400">this week</div>
            </div>
        </div>
        <div class="h-[150px] sm:h-[190px]"><canvas id="trendLineChart"></canvas></div>
    </article>

    <article class="neon-card p-5">
        <h2 class="text-sm font-bold text-brand-700">Pipeline</h2>
        <p class="mt-1 mb-3 text-xs text-slate-400">Application status split</p>
        <?php if (!empty($pie_data) && array_sum($pie_data) > 0): ?>
        <div class="h-[140px]"><canvas id="statusPieChart"></canvas></div>
        <div class="mt-3 flex flex-col gap-1">
            <?php
            $pie_legend_classes = [
                'bg-brand-700', 'bg-sky-500', 'bg-violet-500',
                'bg-amber-500', 'bg-emerald-500', 'bg-slate-400',
            ];
            foreach ($pie_labels as $i => $pl):
                $legend_class = $pie_legend_classes[$i % count($pie_legend_classes)];
            ?>
            <div class="flex items-center justify-between gap-3 text-xs">
                <div class="flex min-w-0 items-center gap-1.5 text-slate-600">
                    <span class="<?= $legend_class ?> size-2 shrink-0 rounded-sm"></span>
                    <span class="truncate"><?= $pl ?></span>
                </div>
                <strong class="text-brand-700"><?= $pie_data[$i] ?></strong>
            </div>
            <?php endforeach; ?>
        </div>
        <?php else: ?>
            <?= emptyState('bi-pie-chart', 'No data yet', 'Application status data will appear here.') ?>
        <?php endif; ?>
    </article>

    <article class="neon-card p-5">
        <h2 class="text-sm font-bold text-brand-700">KRA Averages</h2>
        <p class="mt-1 mb-4 text-xs text-slate-400">Average points per KRA category</p>
        <?php
        $kra_display = [
            'Instruction'              => ['KRA I', 100],
            'Research'                 => ['KRA II', 100],
            'Extension'                => ['KRA III', 100],
            'Professional Development' => ['KRA IV', 100],
        ];
        foreach ($kra_map as $kra => $pts):
            [$short, $max] = $kra_display[$kra] ?? [$kra, 100];
            $pct = $max > 0 ? min(100, round(($pts / $max) * 100)) : 0;
        ?>
        <div class="mb-3 last:mb-0">
            <div class="mb-1 flex items-center justify-between text-xs font-bold text-brand-700">
                <span><?= $short ?></span>
                <span class="tabular-nums"><?= $pts ?></span>
            </div>
            <progress value="<?= $pct ?>" max="100" aria-label="<?= sanitize($short) ?> average: <?= $pct ?>%"
                      class="block h-2 w-full overflow-hidden rounded-full accent-brand-700"></progress>
        </div>
        <?php endforeach; ?>
    </article>
</section>

<section class="mb-6 grid grid-cols-1 gap-4 xl:grid-cols-[minmax(0,1fr)_360px]">
    <article class="neon-card overflow-hidden p-0">
        <div class="flex items-center justify-between gap-4 border-b border-slate-100 px-5 py-4">
            <div>
                <h2 class="text-sm font-bold text-brand-700">
                    <i class="bi bi-geo-alt-fill mr-2 text-brand-600"></i>Campus Breakdown
                </h2>
                <p class="mt-0.5 text-xs text-slate-400">Faculty applications by campus</p>
            </div>
            <a href="?page=manage_campuses" class="btn btn-outline-primary btn-xs">Manage</a>
        </div>
        <?php if ($campus_stats): ?>
        <div class="table-responsive">
            <table class="table-modern mb-0 min-w-[680px]">
                <thead>
                    <tr>
                        <th>Campus</th>
                        <th class="text-center">Faculty</th>
                        <th class="text-center">Applied</th>
                        <th class="text-center">Pending</th>
                        <th class="text-center">Evaluation Complete</th>
                        <th class="min-w-32">Progress</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($campus_stats as $cs):
                    $prog = $cs['faculty_count'] > 0
                        ? min(100, round(($cs['applied_count'] / $cs['faculty_count']) * 100)) : 0;
                ?>
                    <tr>
                        <td class="font-semibold text-slate-800"><?= sanitize($cs['campus_name']) ?></td>
                        <td class="text-center tabular-nums text-slate-500"><?= $cs['faculty_count'] ?></td>
                        <td class="text-center"><span class="badge bg-primary"><?= $cs['applied_count'] ?></span></td>
                        <td class="text-center">
                            <?php if ($cs['pending_count'] > 0): ?>
                            <span class="badge bg-secondary"><?= $cs['pending_count'] ?></span>
                            <?php else: ?>
                            <span class="text-slate-300">–</span>
                            <?php endif; ?>
                        </td>
                        <td class="text-center">
                            <?php if ($cs['reclass_count'] > 0): ?>
                            <span class="badge bg-success"><?= $cs['reclass_count'] ?></span>
                            <?php else: ?>
                            <span class="text-slate-300">–</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <div class="flex items-center gap-2">
                                <progress value="<?= $prog ?>" max="100" aria-label="<?= $prog ?>% of faculty applied"
                                          class="block h-2 min-w-16 flex-1 overflow-hidden rounded-full accent-brand-700"></progress>
                                <span class="min-w-8 text-right text-[0.6875rem] font-bold tabular-nums text-brand-700"><?= $prog ?>%</span>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php else: ?>
            <?= emptyState('bi-buildings', 'No campus data available', 'Campus statistics will appear once data is available.') ?>
        <?php endif; ?>
    </article>

    <article class="neon-card overflow-hidden p-0">
        <div class="flex items-center justify-between gap-4 border-b border-slate-100 px-5 py-4">
            <div>
                <h2 class="text-sm font-bold text-brand-700">
                    <i class="bi bi-activity mr-2 text-brand-600"></i>Recent Activity
                </h2>
                <p class="mt-0.5 text-xs text-slate-400">Latest system events</p>
            </div>
            <a href="?page=audit" class="btn btn-outline-primary btn-xs">View All</a>
        </div>
        <?php if ($recent_logs): ?>
        <div class="py-2">
            <?php
            $action_icons = [
                'Login' => 'bi-box-arrow-in-right',
                'Submitted' => 'bi-send',
                'Evaluation Complete' => 'bi-clipboard-check',
                'Returned' => 'bi-arrow-counterclockwise',
                'Acceptable' => 'bi-patch-check-fill',
                'Password' => 'bi-key-fill',
                'Role' => 'bi-person-gear',
                'Score' => 'bi-pencil-square',
                'Revision' => 'bi-flag-fill',
                'Resent' => 'bi-envelope',
            ];
            $role_badge_classes = [
                'admin' => 'bg-brand-50 text-brand-700 ring-brand-200',
                'evaluator' => 'bg-violet-50 text-violet-700 ring-violet-200',
                'faculty' => 'bg-slate-100 text-slate-600 ring-slate-200',
            ];
            foreach ($recent_logs as $log):
                $action_display = strtr((string)($log['action_performed'] ?? ''), [
                    'Pending Verification' => 'Pending Review',
                    'Not Verified' => 'Not Acceptable',
                    'Unverified' => 'Not Acceptable',
                    'unverified' => 'not acceptable',
                    'Verified by' => 'Marked Acceptable by',
                    'verified by' => 'marked acceptable by',
                    'Verification' => 'Review',
                    'verification' => 'review',
                    'Verifying' => 'Reviewing',
                    'verifying' => 'reviewing',
                    'Verified' => 'Acceptable',
                    'verified' => 'acceptable',
                    'Verify' => 'Review',
                    'verify' => 'review',
                    'Approval' => 'Evaluation Completion',
                    'approval' => 'evaluation completion',
                    'Approved' => 'Evaluation Complete',
                    'approved' => 'evaluation complete',
                    'Approve' => 'Complete Evaluation',
                    'approve' => 'complete evaluation',
                    'Rejection' => 'Return for Revision',
                    'rejection' => 'return for revision',
                    'Rejected' => 'Returned for Revision',
                    'rejected' => 'returned for revision',
                    'Reject' => 'Return for Revision',
                    'reject' => 'return for revision',
                ]);
                $icon = 'bi-clock-history';
                foreach ($action_icons as $key => $activity_icon) {
                    if (stripos($action_display, $key) !== false) {
                        $icon = $activity_icon;
                        break;
                    }
                }
                $diff = time() - strtotime($log['timestamp']);
                $ago = $diff < 60 ? 'just now'
                    : ($diff < 3600 ? floor($diff / 60).'m ago'
                    : ($diff < 86400 ? floor($diff / 3600).'h ago'
                    : date('M d', strtotime($log['timestamp']))));
                $role_class = $role_badge_classes[$log['role_at_time'] ?? '']
                    ?? 'bg-slate-50 text-slate-500 ring-slate-200';
            ?>
            <div class="flex items-start gap-3 border-b border-slate-50 px-4 py-3 transition-colors last:border-b-0 hover:bg-slate-50">
                <span class="mt-0.5 inline-flex size-8 shrink-0 items-center justify-center rounded-full bg-brand-50 text-xs text-brand-600">
                    <i class="bi <?= $icon ?>"></i>
                </span>
                <div class="min-w-0 flex-1">
                    <div class="mb-1 flex items-center justify-between gap-2">
                        <span class="max-w-40 truncate text-xs font-semibold text-slate-800">
                            <?= sanitize($log['full_name'] ?? 'System') ?>
                        </span>
                        <time class="shrink-0 text-[0.6875rem] text-slate-400"><?= $ago ?></time>
                    </div>
                    <div class="flex min-w-0 flex-wrap items-center gap-1.5">
                        <span class="<?= $role_class ?> inline-flex shrink-0 rounded-md px-1.5 py-0.5 text-[0.625rem] font-bold ring-1 ring-inset">
                            <?= sanitize($log['role_at_time'] ?? '–') ?>
                        </span>
                        <span class="min-w-0 truncate text-xs text-slate-500"><?= sanitize($action_display) ?></span>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        <?php else: ?>
            <?= emptyState('bi-activity', 'No activity yet', 'Recent system events will appear here.') ?>
        <?php endif; ?>
    </article>
</section>

<section class="neon-card p-5">
    <h2 class="mb-3 text-sm font-bold text-brand-700">Quick Actions</h2>
    <div class="flex flex-wrap gap-2">
        <?php
        $actions = [
            ['All Applications',  '?page=all_applications'],
            ['Manage Users',      '?page=manage_users'],
            ['Cycles & Criteria', '?page=cycles'],
            ['Analytics',         '?page=analytics'],
            ['Audit Logs',        '?page=audit'],
            ['Manage Campuses',   '?page=manage_campuses'],
        ];
        foreach ($actions as [$label, $href]):
        ?>
        <a href="<?= $href ?>" class="btn btn-outline-primary btn-sm"><?= $label ?></a>
        <?php endforeach; ?>
    </div>
</section>

<script>
document.addEventListener('DOMContentLoaded', function () {
    const tick = '#64748b';
    const grid = '#f1f5f9';
    const tooltipBase = {
        backgroundColor: '#1e293b',
        titleColor: '#f1f5f9',
        bodyColor: '#cbd5e1',
        cornerRadius: 8,
        padding: 10
    };

    new Chart(document.getElementById('trendLineChart'), {
        type: 'line',
        data: {
            labels: <?= json_encode($trend_labels) ?>,
            datasets: [{
                data: <?= json_encode($trend_data) ?>,
                borderColor: '#1e4d8c',
                backgroundColor: 'rgba(30,77,140,0.07)',
                borderWidth: 2.5,
                pointBackgroundColor: '#1e4d8c',
                pointBorderColor: '#fff',
                pointBorderWidth: 2,
                pointRadius: 5,
                tension: 0.4,
                fill: true
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            scales: {
                y: {
                    beginAtZero: true,
                    ticks: { stepSize: 1, color: tick, font: { size: 11 } },
                    grid: { color: grid, drawBorder: false }
                },
                x: {
                    grid: { display: false },
                    ticks: { color: tick, font: { size: 11 } }
                }
            },
            plugins: { legend: { display: false }, tooltip: tooltipBase }
        }
    });

    <?php if (!empty($pie_data) && array_sum($pie_data) > 0): ?>
    new Chart(document.getElementById('statusPieChart'), {
        type: 'doughnut',
        data: {
            labels: <?= json_encode($pie_labels) ?>,
            datasets: [{
                data: <?= json_encode($pie_data) ?>,
                backgroundColor: <?= json_encode($pie_colors_arr) ?>,
                borderColor: '#fff',
                borderWidth: 3,
                hoverOffset: 5
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            cutout: '70%',
            plugins: { legend: { display: false }, tooltip: tooltipBase }
        }
    });
    <?php endif; ?>
});
</script>
