<?php if ((require __DIR__ . '/../../../backend/controllers/faculty/dashboard.php') === false) return; ?>

<?php include __DIR__ . '/../../components/notif_banner.php'; ?>

<?php
$steps = [
    ['label' => 'Profile Complete', 'sublabel' => 'Name, rank, campus set', 'icon' => 'bi-person-check', 'done' => !empty($_SESSION['full_name']) && !empty($faculty_rank), 'link' => null],
    ['label' => 'KRA Entries Added', 'sublabel' => ($total > 0 ? number_format($total, 1) . ' pts total' : 'No entries yet'), 'icon' => 'bi-list-check', 'done' => (isset($total) && $total > 0), 'link' => '?page=apply'],
    ['label' => 'Submitted', 'sublabel' => 'Application received', 'icon' => 'bi-send', 'done' => in_array($app['status'], ['submitted', 'under_review', 'talisay_review', 'approved', 'reclassified', 'admin_rejected']), 'link' => null],
    ['label' => 'Under Evaluation', 'sublabel' => 'Evaluator review', 'icon' => 'bi-search', 'done' => in_array($app['status'], ['talisay_review', 'approved', 'reclassified']), 'link' => null],
    ['label' => 'Evaluation Complete', 'sublabel' => 'For committee review', 'icon' => 'bi-clipboard-check', 'done' => in_array($app['status'], ['approved', 'reclassified']), 'link' => null],
];
$current_step = 0;
foreach ($steps as $i => $step) {
    if ($step['done']) $current_step = $i + 1;
}

[$application_status_label, $application_status_class] = match ($app['status']) {
    'submitted' => ['Submitted', 'pill-info'],
    'under_review', 'talisay_review' => ['Under Evaluation', 'pill-progress'],
    'needs_revision', 'rejected' => ['Needs Revision', 'pill-warning'],
    'approved', 'reclassified' => ['Evaluation Complete', 'pill-success'],
    'admin_rejected' => ['Evaluation Complete', 'pill-neutral'],
    default => ['Draft', 'pill-neutral'],
};

$kra_classes = [
    'Instruction' => ['text-brand-600', 'accent-brand-600', 'bg-brand-600'],
    'Research' => ['text-brand-700', 'accent-brand-700', 'bg-brand-700'],
    'Extension' => ['text-sky-700', 'accent-sky-700', 'bg-sky-700'],
    'Professional Development' => ['text-brand-500', 'accent-brand-500', 'bg-brand-500'],
];

$avatar_classes = [
    'bg-brand-600', 'bg-brand-700', 'bg-slate-700', 'bg-slate-600',
    'bg-brand-800', 'bg-slate-500', 'bg-sky-700', 'bg-brand-500',
];
?>

<section class="mb-6 overflow-hidden rounded-2xl border border-slate-200/80 bg-white shadow-card">
    <div class="flex flex-col gap-4 bg-gradient-to-br from-brand-700 to-brand-600 px-5 py-5 text-white sm:flex-row sm:items-center sm:justify-between sm:px-6">
        <div class="flex min-w-0 items-center gap-4">
            <?php
            $pic = $_SESSION['profile_pic'] ?? '';
            $init_h = strtoupper(
                substr($_SESSION['first_name'] ?? '', 0, 1) .
                substr($_SESSION['last_name'] ?? '', 0, 1)
            ) ?: 'U';
            ?>
            <div class="size-12 shrink-0 overflow-hidden rounded-full border-2 border-white/30 bg-white/15">
                <?php if ($pic): ?>
                <img src="<?= sanitize($pic) ?>" alt="Profile" class="size-full object-cover">
                <?php else: ?>
                <div class="flex size-full items-center justify-center text-sm font-bold text-white">
                    <?= sanitize($init_h) ?>
                </div>
                <?php endif; ?>
            </div>
            <div class="min-w-0">
                <h1 class="m-0 truncate text-base font-bold tracking-tight text-white">
                    Welcome back, <?= sanitize($_SESSION['full_name'] ?? '') ?>
                </h1>
                <div class="mt-1 flex flex-wrap items-center gap-x-2 gap-y-1 text-xs text-white/65">
                    <span><i class="bi bi-calendar3 mr-1"></i><?= date('l, F j, Y') ?></span>
                    <?php if ($faculty_rank): ?>
                    <span aria-hidden="true">&middot;</span>
                    <span><i class="bi bi-mortarboard mr-1"></i><?= sanitize($faculty_rank) ?></span>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <div class="flex flex-wrap items-center gap-3 sm:justify-end">
            <span class="pill <?= $application_status_class ?>">
                <?= sanitize($application_status_label) ?>
            </span>
            <?php if ($deadline_str): ?>
            <div class="text-left sm:text-right">
                <div class="text-[0.65rem] font-semibold tracking-wider text-white/55 uppercase">Submission deadline</div>
                <div class="text-sm font-semibold text-white">
                    <?= sanitize($deadline_str) ?>
                    <?php if (!$deadline_passed && $days_left !== null): ?>
                    <span class="<?= $deadline_urgent ? 'text-amber-200' : 'text-white/70' ?>">(<?= $days_left ?> day<?= $days_left === 1 ? '' : 's' ?> left)</span>
                    <?php endif; ?>
                </div>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <div class="px-4 py-5 sm:px-6">
        <div class="mb-4 flex flex-wrap items-center justify-between gap-2">
            <div class="flex items-center gap-2 text-xs font-bold tracking-wider text-slate-500 uppercase">
                <i class="bi bi-signpost-split text-brand-600"></i>
                <span>Application Progress</span>
                <?= helpBtn('Application Progress', 'This shows where your application is in the reclassification process. It is reviewed by the Subcommittee, then by the ITC. You must have a weighted score of at least 41.00 to submit.') ?>
            </div>
            <span class="text-xs text-slate-400"><?= $current_step ?> of <?= count($steps) ?> steps completed</span>
        </div>

        <div class="overflow-x-auto pb-1">
        <div id="progressStepper" class="grid min-w-[640px] grid-cols-5 overflow-hidden" aria-label="Application progress">
            <?php foreach ($steps as $i => $step):
                $is_done = $step['done'];
                $is_current = ($i === $current_step) && !$is_done;
                $is_failed = ($app['status'] === 'admin_rejected') && ($i === 4);
                $circle_class = $is_failed
                    ? 'border-slate-800 bg-slate-800 text-white'
                    : ($is_done
                        ? 'border-brand-700 bg-brand-700 text-white'
                        : ($is_current
                            ? 'border-brand-700 bg-brand-50 text-brand-700 ring-4 ring-brand-100'
                            : 'border-slate-200 bg-slate-50 text-slate-300'));
                $label_class = $is_failed
                    ? 'text-slate-800'
                    : ($is_done ? 'text-brand-700' : ($is_current ? 'text-slate-900' : 'text-slate-400'));
            ?>
            <div class="relative px-1 text-center" data-index="<?= $i ?>">
                <?php if ($i < count($steps) - 1): ?>
                <div class="absolute top-[15px] left-[calc(50%+15px)] right-[calc(-50%+15px)] z-0 h-0.5 <?= $is_done ? 'bg-brand-700' : 'bg-slate-200' ?>" aria-hidden="true"></div>
                <?php endif; ?>

                <div class="relative z-[1] mx-auto mb-2 flex size-[30px] items-center justify-center rounded-full border-2 <?= $circle_class ?>">
                    <?php if ($is_failed): ?>
                    <i class="bi bi-x-lg text-xs"></i>
                    <?php elseif ($is_done): ?>
                    <i class="bi bi-check-lg text-xs"></i>
                    <?php else: ?>
                    <i class="bi <?= $step['icon'] ?> text-xs"></i>
                    <?php endif; ?>
                </div>

                <div class="text-[0.6875rem] leading-tight <?= ($is_done || $is_current || $is_failed) ? 'font-bold' : 'font-medium' ?> <?= $label_class ?>">
                    <?php if ($step['link'] && !$is_done): ?>
                    <a href="<?= $step['link'] ?>" class="text-inherit no-underline hover:underline"><?= $step['label'] ?></a>
                    <?php else: ?>
                    <?= $step['label'] ?>
                    <?php endif; ?>
                </div>
                <div class="mt-1 text-[0.625rem] leading-tight text-slate-400"><?= $step['sublabel'] ?></div>
            </div>
            <?php endforeach; ?>
        </div>
        </div>
    </div>
</section>

<div class="mb-6 grid gap-4 lg:grid-cols-3">
    <section class="neon-card flex h-full flex-col p-5">
        <header class="mb-4 flex items-start justify-between gap-3 border-b border-slate-100 pb-3">
            <div>
                <h2 class="m-0 text-sm font-bold tracking-wide text-brand-700 uppercase">My Campus</h2>
                <p class="mt-1 mb-0 text-xs text-slate-500"><?= sanitize($my_campus_name ?: '-') ?></p>
            </div>
            <?php
            $col_submitted = count(array_filter($colleagues, fn($colleague) => in_array($colleague['app_status'] ?? '', ['submitted', 'under_review', 'needs_revision'])));
            if ($col_submitted > 0):
            ?>
            <span class="pill pill-brand"><?= $col_submitted ?> submitted</span>
            <?php endif; ?>
        </header>

        <?php if ($colleagues): ?>
        <div class="max-h-60 flex-1 overflow-y-auto">
            <?php
            $shown = 0;
            foreach ($colleagues as $col):
                $status = $col['app_status'] ?? '';
                if (in_array($status, ['approved', 'rejected', 'admin_rejected'])) continue;
                $shown++;
                [$status_label, $status_class, $dot_class] = match ($status) {
                    'submitted' => ['Submitted', 'text-brand-600', 'bg-brand-600'],
                    'under_review' => ['Under Evaluation', 'text-violet-700', 'bg-violet-600'],
                    'needs_revision' => ['Needs Revision', 'text-amber-700', 'bg-amber-500'],
                    default => ['Draft', 'text-slate-500', 'bg-slate-300'],
                };
                $initials = strtoupper(
                    substr(trim($col['first_name'] ?? ''), 0, 1) .
                    substr(trim($col['last_name'] ?? ''), 0, 1)
                ) ?: '?';
                $avatar_class = $avatar_classes[((int)$col['user_id']) % count($avatar_classes)];
            ?>
            <div class="flex items-center gap-3 border-b border-slate-100 py-3 last:border-0">
                <div class="relative shrink-0">
                    <div class="flex size-10 items-center justify-center rounded-full text-xs font-semibold text-white <?= $avatar_class ?>">
                        <?= sanitize($initials) ?>
                    </div>
                    <span class="absolute right-0 bottom-0 size-3 rounded-full border-2 border-white <?= $dot_class ?>" aria-hidden="true"></span>
                </div>
                <div class="min-w-0 flex-1">
                    <div class="truncate text-sm font-semibold text-slate-800"><?= htmlspecialchars(formatDisplayName($col)) ?></div>
                    <div class="truncate text-xs text-slate-500"><?= sanitize($col['rank'] ?? '-') ?></div>
                </div>
                <span class="shrink-0 text-[0.6875rem] font-semibold <?= $status_class ?>"><?= $status_label ?></span>
            </div>
            <?php endforeach; ?>
            <?php if ($shown === 0): ?>
            <p class="m-0 py-8 text-center text-sm text-slate-500">All colleagues have completed their applications.</p>
            <?php endif; ?>
        </div>
        <?php elseif (!$my_campus_id): ?>
        <div class="flex flex-1 flex-col items-center justify-center py-8 text-center">
            <p class="mb-3 text-sm text-slate-500">No campus assigned to your profile.</p>
            <a href="?page=profile" class="btn btn-xs btn-outline-primary">Update Profile</a>
        </div>
        <?php else: ?>
        <p class="m-0 flex flex-1 items-center justify-center py-8 text-center text-sm text-slate-500">No other active faculty at your campus yet.</p>
        <?php endif; ?>
    </section>

    <section class="neon-card flex h-full flex-col p-5">
        <header class="mb-4 border-b border-slate-100 pb-3">
            <h2 class="m-0 text-sm font-bold tracking-wide text-brand-700 uppercase">Weighted Score</h2>
            <?php if ($faculty_rank): ?>
            <p class="mt-1 mb-0 text-xs text-slate-500"><?= sanitize($faculty_rank) ?></p>
            <?php endif; ?>
        </header>

        <div class="mb-4 text-5xl leading-none font-bold tracking-tight text-slate-900 tabular-nums">
            <?= number_format($weighted, 2) ?>
            <span class="text-base font-normal tracking-normal text-slate-400">/ 100</span>
        </div>

        <div class="relative mb-1">
            <progress value="<?= min(100, max(0, $weighted)) ?>" max="100" class="h-2 w-full overflow-hidden rounded-full accent-brand-700" aria-label="Weighted score"></progress>
            <span class="absolute top-0 left-[41%] h-3 w-0.5 -translate-y-0.5 rounded-full bg-slate-400" title="Minimum: 41" aria-hidden="true"></span>
        </div>
        <div class="mb-4 flex justify-between text-[0.625rem] text-slate-400">
            <span>0</span><span>minimum 41</span><span>100</span>
        </div>

        <div class="mb-2 flex flex-wrap items-center gap-2 text-xs">
            <span class="font-semibold <?= $inc > 0 ? 'text-brand-600' : 'text-slate-500' ?>">
                <?= $inc > 0 ? '+' . $inc . ' sub-rank' . ($inc > 1 ? 's' : '') : 'Below minimum' ?>
            </span>
            <?php if ($next_bracket_gap !== null && $weighted > 0): ?>
            <span class="text-slate-300">&middot;</span>
            <span class="text-slate-500">
                <?= $next_bracket_gap ?> pts <?= $inc > 0 ? 'more → +' . $next_bracket_inc : 'needed' ?>
            </span>
            <?php endif; ?>
        </div>

        <?php if (($score_result['award_bonus'] ?? 0) > 0): ?>
        <p class="mb-3 text-xs text-slate-500"><i class="bi bi-patch-check mr-1"></i>National/International Award bonus: +1 sub-rank included</p>
        <?php endif; ?>

        <?php if ($app['status'] === 'draft' && !$deadline_passed): ?>
        <div class="mt-auto border-t border-slate-100 pt-4">
            <?php if ($can_submit): ?>
            <form method="POST" id="submitApplicationForm">
                <input type="hidden" name="action" value="submit_application">
                <button type="button" class="btn btn-primary btn-sm w-full" onclick="confirmDelete('Submit your application for evaluator review?','submitApplicationForm','Submit','bi-send')">
                    <i class="bi bi-send"></i>Submit for Review
                </button>
            </form>
            <?php elseif ($total === 0): ?>
            <a href="?page=apply" class="btn btn-sm btn-outline-primary w-full"><i class="bi bi-pencil"></i>Enter KRA Scores First</a>
            <?php else: ?>
            <p class="m-0 text-center text-sm font-medium text-rose-600">Score too low to submit</p>
            <?php endif; ?>
        </div>
        <?php endif; ?>
    </section>

    <section class="neon-card flex h-full flex-col p-5">
        <header class="mb-4 border-b border-slate-100 pb-3">
            <h2 class="m-0 text-sm font-bold tracking-wide text-brand-700 uppercase">KRA Overview</h2>
            <p class="mt-1 mb-0 text-xs text-slate-500">Your weighted contribution per area</p>
        </header>
        <div class="min-h-36 flex-1 max-h-44">
            <canvas id="kraOverviewChart"></canvas>
        </div>
        <div class="mt-4 grid grid-cols-2 gap-2 border-t border-slate-100 pt-3">
            <?php foreach ($kra_info as $kra => $info):
                $legend_class = $kra_classes[$kra][2] ?? 'bg-brand-700';
            ?>
            <div class="flex items-center gap-2 text-[0.6875rem] text-slate-500">
                <span class="size-2 shrink-0 rounded-sm <?= $legend_class ?>" aria-hidden="true"></span>
                <span><?= $info['short'] ?> <strong class="text-slate-800"><?= number_format($kra_map[$kra]['pts'], 1) ?></strong></span>
            </div>
            <?php endforeach; ?>
        </div>
    </section>
</div>

<section class="neon-card mb-6 p-5 sm:p-6">
    <header class="mb-5 flex flex-wrap items-center justify-between gap-3">
        <div>
            <h2 class="m-0 text-base font-bold text-brand-700"><i class="bi bi-bar-chart-steps mr-2"></i>KRA Scores</h2>
            <p class="mt-1 mb-0 text-xs text-slate-500">Your points per area versus maximum</p>
        </div>
        <?php if ($can_edit): ?>
        <a href="?page=apply" class="btn btn-xs btn-outline-primary"><i class="bi bi-pencil"></i>Edit KRA Entries</a>
        <?php endif; ?>
    </header>

    <div class="space-y-5">
        <?php foreach ($kra_map as $kra => $data):
            $info = $kra_info[$kra];
            $pts = $data['pts'];
            $verified = $data['verified'];
            $pct = $info['max'] > 0 ? min(100, ($pts / $info['max']) * 100) : 0;
            $empty = $pts == 0;
            [$kra_text_class, $kra_accent_class] = $kra_classes[$kra] ?? ['text-brand-700', 'accent-brand-700'];
        ?>
        <div>
            <div class="mb-2 flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
                <div class="flex min-w-0 flex-wrap items-center gap-2">
                    <i class="bi <?= $info['icon'] ?> text-sm <?= $empty ? 'text-slate-300' : $kra_text_class ?>"></i>
                    <span class="text-sm font-semibold <?= $empty ? 'text-slate-400' : 'text-slate-800' ?>"><?= $info['short'] ?> — <?= $kra ?></span>
                    <?php if ($verified): ?>
                    <span class="badge bg-success"><i class="bi bi-patch-check"></i>Acceptable</span>
                    <?php elseif (!$empty): ?>
                    <span class="badge bg-secondary">Not Acceptable</span>
                    <?php endif; ?>
                </div>
                <div class="flex shrink-0 items-center gap-3">
                    <span class="text-sm font-bold tabular-nums <?= $empty ? 'text-slate-400' : $kra_text_class ?>">
                        <?= number_format($pts, 1) ?> <span class="text-xs font-normal text-slate-400">/ <?= $info['max'] ?></span>
                    </span>
                    <?php if ($can_edit): ?>
                    <a href="?page=apply&tab=<?= $info['tab'] ?>" class="text-xs font-semibold no-underline hover:underline <?= $kra_text_class ?>"><?= $empty ? '+ Add' : 'Edit' ?></a>
                    <?php endif; ?>
                </div>
            </div>
            <progress value="<?= min($pts, $info['max']) ?>" max="<?= $info['max'] ?>" class="h-2.5 w-full overflow-hidden rounded-full <?= $empty ? 'accent-slate-200' : $kra_accent_class ?>" aria-label="<?= sanitize($kra) ?> score progress"></progress>
            <div class="mt-1 flex justify-between gap-3 text-[0.6875rem] text-slate-400">
                <span><?= $empty ? 'No entries yet' : number_format($pct, 0) . '% of maximum' ?></span>
                <?php if (!$empty && $pct < 100): ?>
                <span><?= number_format($info['max'] - $pts, 1) ?> pts to maximum</span>
                <?php elseif ($pct >= 100): ?>
                <span class="font-semibold text-brand-600">Maximum reached <i class="bi bi-check-lg"></i></span>
                <?php endif; ?>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
</section>

<?php if ($total > 0): ?>
<section class="neon-card mb-6 overflow-hidden p-0">
    <header class="border-b border-slate-100 px-5 py-4 sm:px-6">
        <h2 class="m-0 text-base font-bold text-brand-700">
            <i class="bi bi-calculator mr-2"></i>Score Breakdown
            <span class="ml-1 text-xs font-normal text-slate-500">— weights based on <em><?= sanitize($faculty_rank ?: 'rank not set') ?></em></span>
        </h2>
    </header>
    <div class="table-responsive">
        <table class="table-modern mb-0 min-w-[680px]">
            <thead>
                <tr>
                    <th>KRA</th>
                    <th class="text-center">Raw</th>
                    <th class="text-center">Capped</th>
                    <th class="text-center">Weight</th>
                    <th class="text-center">Weighted</th>
                    <th class="text-center">Acceptable</th>
                </tr>
            </thead>
            <tbody>
            <?php
            $kra_labels = [
                'Instruction' => 'KRA I - Instruction',
                'Research' => 'KRA II - Research',
                'Extension' => 'KRA III - Extension',
                'Professional Development' => 'KRA IV - Prof. Development',
            ];
            $kra_capped = [
                'Instruction' => $score_result['kra1'],
                'Research' => $score_result['kra2'],
                'Extension' => $score_result['kra3'],
                'Professional Development' => $score_result['kra4'],
            ];
            foreach ($kra_labels as $key => $label):
                $raw = $kra_map[$key]['pts'];
                $cap = $kra_capped[$key];
                $weight = $weights[$key];
                $weighted_value = round($cap * $weight, 2);
                $verified = $kra_map[$key]['verified'];
            ?>
                <tr>
                    <td class="font-medium text-slate-800"><?= $label ?></td>
                    <td class="text-center tabular-nums"><?= number_format($raw, 2) ?></td>
                    <td class="text-center tabular-nums"><?= number_format($cap, 2) ?></td>
                    <td class="text-center tabular-nums"><?= ($weight * 100) ?>%</td>
                    <td class="text-center font-bold text-slate-900 tabular-nums"><?= number_format($weighted_value, 2) ?></td>
                    <td class="text-center">
                        <?php if ($verified): ?>
                        <span class="badge bg-success"><i class="bi bi-check-lg"></i></span>
                        <?php else: ?>
                        <span class="badge bg-secondary">—</span>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
            <tfoot>
                <tr class="border-t border-brand-100 bg-brand-50 font-bold">
                    <td colspan="4" class="px-4 py-3 text-brand-700">Final Weighted Score</td>
                    <td class="px-4 py-3 text-center text-base text-brand-700 tabular-nums"><?= number_format($weighted, 2) ?></td>
                    <td></td>
                </tr>
            </tfoot>
        </table>
    </div>
    <footer class="flex flex-wrap items-center gap-3 border-t border-slate-100 bg-slate-50/60 px-5 py-4 text-sm sm:px-6">
        <span class="text-slate-500">Sub-rank increment:</span>
        <span class="badge <?= $inc > 0 ? 'bg-success' : 'bg-secondary' ?>">
            <?= $inc > 0 ? "+{$inc} sub-rank" . ($inc > 1 ? 's' : '') : 'No sub-rank increment (below 41)' ?>
        </span>
        <?php if (($score_result['award_bonus'] ?? 0) > 0): ?>
        <span class="badge bg-primary"><i class="bi bi-award-fill"></i>+1 National/International Award bonus included</span>
        <?php endif; ?>
        <?php if ($inc > 0): ?>
        <span class="text-xs text-slate-500">
            <?php foreach ($brackets as [$lo, $hi, $rank_increment]) {
                if ($rank_increment === ($inc - ($score_result['award_bonus'] ?? 0))) {
                    echo "Score {$lo}-{$hi} → +{$rank_increment} sub-rank" . ($rank_increment > 1 ? 's' : '');
                    break;
                }
            } ?>
        </span>
        <?php endif; ?>
    </footer>
</section>
<?php endif; ?>

<script>
document.addEventListener('DOMContentLoaded', function () {
    <?php
    $kra_capped = [
        'Instruction' => $score_result['kra1'],
        'Research' => $score_result['kra2'],
        'Extension' => $score_result['kra3'],
        'Professional Development' => $score_result['kra4'],
    ];
    $contributions = [];
    foreach ($kra_capped as $category => $cap) {
        $contributions[] = round($cap * $weights[$category], 2);
    }
    $has_any = array_sum($contributions) > 0;
    ?>

    <?php if ($has_any): ?>
    new Chart(document.getElementById('kraOverviewChart'), {
        type: 'doughnut',
        data: {
            labels: ['KRA I - Instruction', 'KRA II - Research', 'KRA III - Extension', 'KRA IV - Prof Dev'],
            datasets: [{
                data: <?= json_encode($contributions) ?>,
                backgroundColor: ['#2b519b', '#1a3a6b', '#0369a1', '#4169b8'],
                borderWidth: 2,
                borderColor: '#fff',
                hoverOffset: 6
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            cutout: '65%',
            plugins: {
                legend: { display: false },
                tooltip: {
                    callbacks: {
                        label: ctx => ` ${ctx.label}: ${ctx.parsed} pts (weighted)`
                    }
                }
            }
        },
        plugins: [{
            id: 'centerText',
            beforeDraw(chart) {
                const { ctx, chartArea: { width, height, left, top } } = chart;
                ctx.save();
                const centerX = left + width / 2;
                const centerY = top + height / 2;
                ctx.textAlign = 'center';
                ctx.textBaseline = 'middle';
                ctx.font = 'bold 1.4rem sans-serif';
                ctx.fillStyle = '#1e293b';
                ctx.fillText('<?= number_format($weighted, 1) ?>', centerX, centerY - 8);
                ctx.font = '0.65rem sans-serif';
                ctx.fillStyle = '#94a3b8';
                ctx.fillText('weighted', centerX, centerY + 10);
                ctx.restore();
            }
        }]
    });
    <?php else: ?>
    const canvas = document.getElementById('kraOverviewChart');
    const context = canvas.getContext('2d');
    canvas.height = 180;
    context.textAlign = 'center';
    context.textBaseline = 'middle';
    context.font = '0.8rem sans-serif';
    context.fillStyle = '#94a3b8';
    context.fillText('No KRA entries yet', canvas.width / 2, canvas.height / 2);
    <?php endif; ?>
});
</script>
