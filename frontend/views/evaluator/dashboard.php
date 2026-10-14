<?php require __DIR__ . '/../../../backend/controllers/evaluator/dashboard.php'; ?>
<?php include __DIR__ . '/../../components/notif_banner.php'; ?>

<?php
$deadlineClass = static function (string $label): string {
    return match ($label) {
        'Overdue'  => 'bg-rose-50 text-rose-700 ring-rose-200',
        'Due Soon' => 'bg-amber-50 text-amber-700 ring-amber-200',
        'On Time'  => 'bg-emerald-50 text-emerald-700 ring-emerald-200',
        default    => 'bg-slate-50 text-slate-600 ring-slate-200',
    };
};

$waitingClass = static function (int $days): string {
    return $days >= 5 ? 'text-rose-600' : ($days >= 3 ? 'text-amber-600' : 'text-slate-600');
};
?>

<div class="mb-6 flex flex-wrap items-center justify-between gap-4">
    <div class="flex min-w-0 items-center gap-3.5">
        <?= userAvatar('size-12', 'text-sm') ?>
        <div class="min-w-0">
            <h1 class="truncate text-base font-bold leading-tight text-slate-900">
                Welcome back, <?= sanitize($_SESSION['full_name'] ?? 'Evaluator') ?>
            </h1>
            <div class="mt-1 flex flex-wrap items-center gap-2 text-xs text-slate-500">
                <span class="inline-flex items-center gap-1.5">
                    <i class="bi bi-calendar3 text-[0.7rem]"></i>
                    <?= date('l, F j, Y') ?>
                </span>
                <span class="rounded-md bg-brand-50 px-2 py-0.5 text-[0.65rem] font-bold tracking-wider text-brand-700 ring-1 ring-inset ring-brand-200">
                    EVALUATOR
                </span>
            </div>
        </div>
    </div>
    <a href="?page=review_queue" class="btn btn-primary text-white hover:text-white">
        <i class="bi bi-inbox-fill"></i>
        Review Queue
        <?php if ($pending > 0): ?>
            <span class="inline-flex size-5 min-w-5 items-center justify-center rounded-full bg-white/20 px-1 text-[0.65rem] font-bold text-white">
                <?= $pending ?>
            </span>
        <?php endif; ?>
    </a>
</div>

<?php if ($cycle): ?>
    <section class="mb-5 flex flex-wrap items-center justify-between gap-4 rounded-2xl bg-brand-700 px-5 py-4 text-white shadow-brand">
        <div class="flex items-center gap-3">
            <span class="inline-flex size-10 shrink-0 items-center justify-center rounded-xl bg-white/10 text-white/90 ring-1 ring-inset ring-white/10">
                <i class="bi bi-calendar-range"></i>
            </span>
            <div>
                <h2 class="text-sm font-bold leading-tight text-white"><?= sanitize($cycle['cycle_name']) ?></h2>
                <div class="mt-1.5">
                    <?php if ($cycle['status'] === 'open'): ?>
                        <span class="inline-flex items-center gap-1.5 rounded-full bg-emerald-400/15 px-2 py-0.5 text-[0.65rem] font-bold tracking-wider text-emerald-300 ring-1 ring-inset ring-emerald-300/30">
                            <span class="size-1.5 rounded-full bg-emerald-300"></span> OPEN
                        </span>
                    <?php elseif ($cycle['status'] === 'closed'): ?>
                        <span class="rounded-full bg-white/10 px-2 py-0.5 text-[0.65rem] font-bold tracking-wider text-white/70 ring-1 ring-inset ring-white/15">CLOSED</span>
                    <?php else: ?>
                        <span class="rounded-full bg-white/10 px-2 py-0.5 text-[0.65rem] font-bold tracking-wider text-white/50 ring-1 ring-inset ring-white/10">ARCHIVED</span>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <div class="flex items-center gap-4">
            <div class="text-right">
                <div class="text-[0.65rem] font-medium tracking-wider text-white/50 uppercase">Awaiting review</div>
                <div class="text-2xl font-extrabold leading-none text-white"><?= $pending ?></div>
            </div>
            <a href="?page=review_queue" class="inline-flex items-center gap-2 whitespace-nowrap rounded-lg border border-white/20 bg-white/10 px-3.5 py-2 text-xs font-semibold text-white no-underline transition hover:bg-white/20 hover:text-white">
                View Queue <i class="bi bi-arrow-right"></i>
            </a>
        </div>
    </section>
<?php else: ?>
    <div class="mb-5 flex items-center gap-3 rounded-xl border border-brand-200 border-l-4 border-l-brand-700 bg-brand-50 px-4 py-3 text-sm font-semibold text-slate-700">
        <i class="bi bi-calendar-x text-lg text-brand-700"></i>
        No active cycle at the moment.
    </div>
<?php endif; ?>

<div class="mb-5 grid grid-cols-1 gap-3 sm:grid-cols-2 xl:grid-cols-4">
    <?= statCard('Awaiting Decision', $pending, 'bi-inbox-fill', $pending > 0 ? 'brand' : 'slate', '', 'checker_pending', '?page=review_queue') ?>
    <?= statCard('Active Reviews', $my_under_review, 'bi-hourglass-split', $my_under_review > 0 ? 'sky' : 'slate', '', 'checker_active', '?page=review_queue&filter=under_review') ?>
    <?= statCard('Needs Revision', $needs_revision, 'bi-pencil-square', $needs_revision > 0 ? 'amber' : 'slate', '', 'checker_revision', '?page=review_queue&filter=needs_revision') ?>
    <?= statCard('Total Completed', $total_reviewed, 'bi-check2-all', 'emerald', '', '', '?page=review_queue') ?>
</div>

<div class="mb-5 grid grid-cols-1 gap-4 lg:grid-cols-[14rem_minmax(0,1fr)]">
    <section class="ui-card p-5">
        <h2 class="flex items-center gap-2 text-sm font-bold text-slate-900">
            <i class="bi bi-pie-chart-fill text-brand-700"></i>
            My Reviews
        </h2>
        <p class="mt-1 mb-4 text-xs text-slate-500">Evaluation completion</p>
        <?php if ($total_reviewed > 0): ?>
            <div class="h-36"><canvas id="myReviewChart"></canvas></div>
            <div class="mt-4 grid grid-cols-2 gap-2">
                <div class="rounded-xl bg-brand-50 p-2.5 text-center ring-1 ring-inset ring-brand-100">
                    <div class="text-xl font-extrabold leading-none text-brand-700"><?= $total_approved ?></div>
                    <div class="mt-1 text-[0.65rem] font-semibold tracking-wider text-slate-500 uppercase">Complete</div>
                </div>
                <div class="rounded-xl bg-slate-50 p-2.5 text-center ring-1 ring-inset ring-slate-100">
                    <div class="text-xl font-extrabold leading-none text-slate-600"><?= max(0, $total_reviewed - $total_approved) ?></div>
                    <div class="mt-1 text-[0.65rem] font-semibold tracking-wider text-slate-500 uppercase">Other</div>
                </div>
            </div>
        <?php else: ?>
            <?= emptyState('bi-clipboard2-x', 'No completed reviews yet.') ?>
        <?php endif; ?>
    </section>

    <section class="overflow-hidden rounded-2xl border border-slate-200/80 bg-white shadow-card">
        <div class="flex items-center overflow-x-auto border-b border-slate-100 bg-slate-50/60 px-2 sm:px-4" role="tablist" aria-label="Review lists">
            <?php
            $tabs = [
                ['active',   'Active',    'bi-hourglass-split', $my_under_review],
                ['revision', 'Revisions', 'bi-pencil-square',   $needs_revision],
                ['queue',    'Queue',     'bi-inbox',            $pending],
            ];
            foreach ($tabs as [$id, $label, $icon, $cnt]):
                $isActive = $id === 'active';
            ?>
                <button
                    type="button"
                    class="ck-tab -mb-px inline-flex cursor-pointer items-center gap-1.5 whitespace-nowrap border-0 border-b-2 bg-transparent px-3 py-3 text-xs font-semibold transition-colors sm:px-4 <?= $isActive ? 'border-brand-700 text-brand-700' : 'border-transparent text-slate-400 hover:text-slate-700' ?>"
                    data-tab="<?= $id ?>"
                    role="tab"
                    aria-controls="tab-<?= $id ?>"
                    aria-selected="<?= $isActive ? 'true' : 'false' ?>"
                >
                    <i class="bi <?= $icon ?>"></i>
                    <?= $label ?>
                    <?php if ($cnt > 0): ?>
                        <span data-tab-badge class="rounded-full px-2 py-0.5 text-[0.65rem] font-bold <?= $isActive ? 'bg-brand-700 text-white' : 'bg-slate-200 text-slate-600' ?>"><?= $cnt ?></span>
                    <?php endif; ?>
                </button>
            <?php endforeach; ?>
            <a href="?page=review_queue" class="ml-auto inline-flex shrink-0 items-center gap-1 px-2 py-2 text-xs font-semibold text-brand-700 no-underline hover:text-brand-900">
                View All <i class="bi bi-arrow-right"></i>
            </a>
        </div>

        <div id="tab-active" class="ck-panel max-h-[22rem] overflow-y-auto" role="tabpanel">
            <?php if ($inprogress): foreach ($inprogress as $ip):
                $wait = (int)$ip['days_waiting'];
                $approved = ($ip['my_decision'] ?? '') === 'approved';
                [$due_label] = getEvaluationDeadlineLabel($ip['evaluation_deadline'] ?? null);
            ?>
                <div class="flex flex-col gap-3 border-b border-slate-100 px-4 py-3.5 last:border-b-0 sm:flex-row sm:items-center">
                    <div class="min-w-0 flex-1">
                        <div class="truncate text-sm font-semibold text-slate-900"><?= htmlspecialchars(formatDisplayName($ip)) ?></div>
                        <div class="mt-1 flex flex-wrap items-center gap-x-2 gap-y-1 text-xs text-slate-500">
                            <span><?= sanitize($ip['campus_name']) ?></span>
                            <span class="text-slate-300">|</span>
                            <span class="font-semibold <?= $waitingClass($wait) ?>"><?= $wait ?>d waiting</span>
                            <span class="rounded-full px-2 py-0.5 text-[0.65rem] font-bold ring-1 ring-inset <?= $deadlineClass($due_label) ?>"><?= htmlspecialchars($due_label) ?></span>
                            <span class="text-slate-300">|</span>
                            <span class="font-bold text-brand-600"><?= number_format((float)$ip['weighted_score'], 2) ?> pts</span>
                        </div>
                    </div>
                    <div class="flex shrink-0 items-center gap-2">
                        <span class="rounded-full px-2.5 py-1 text-[0.65rem] font-bold <?= $approved ? 'bg-brand-50 text-brand-700 ring-1 ring-inset ring-brand-200' : 'bg-slate-100 text-slate-600' ?>">
                            <?= $approved ? 'Evaluation Complete' : 'Pending' ?>
                        </span>
                        <a href="?page=review_application&id=<?= $ip['application_id'] ?>" class="btn btn-primary btn-xs">
                            <?= $approved ? 'View' : 'Review' ?>
                        </a>
                    </div>
                </div>
            <?php endforeach; else: ?>
                <?= emptyState('bi-check2-circle', 'No active reviews right now.') ?>
            <?php endif; ?>
        </div>

        <div id="tab-revision" class="ck-panel hidden max-h-[22rem] overflow-y-auto" role="tabpanel">
            <?php if ($revision_apps): foreach ($revision_apps as $rv):
                $wait = (int)$rv['days_waiting'];
                [$due_label] = getEvaluationDeadlineLabel($rv['evaluation_deadline'] ?? null);
            ?>
                <div class="flex flex-col gap-3 border-b border-slate-100 px-4 py-3.5 last:border-b-0 sm:flex-row sm:items-center">
                    <div class="min-w-0 flex-1">
                        <div class="truncate text-sm font-semibold text-slate-900"><?= htmlspecialchars(formatDisplayName($rv)) ?></div>
                        <div class="mt-1 flex flex-wrap items-center gap-2 text-xs text-slate-500">
                            <span><?= sanitize($rv['campus_name']) ?></span>
                            <span class="text-slate-300">|</span>
                            <span class="font-semibold <?= $waitingClass($wait) ?>"><?= $wait ?>d waiting</span>
                            <span class="rounded-full px-2 py-0.5 text-[0.65rem] font-bold ring-1 ring-inset <?= $deadlineClass($due_label) ?>"><?= htmlspecialchars($due_label) ?></span>
                        </div>
                    </div>
                    <a href="?page=review_application&id=<?= $rv['application_id'] ?>" class="btn btn-secondary btn-xs self-start sm:self-auto">View</a>
                </div>
            <?php endforeach; else: ?>
                <?= emptyState('bi-check-circle', 'No pending revisions.') ?>
            <?php endif; ?>
        </div>

        <div id="tab-queue" class="ck-panel hidden max-h-[22rem] overflow-y-auto" role="tabpanel">
            <?php if ($queue): foreach ($queue as $q):
                $wait = (int)$q['days_waiting'];
                [$due_label] = getEvaluationDeadlineLabel($q['evaluation_deadline'] ?? null);
            ?>
                <div class="flex flex-col gap-3 border-b border-slate-100 px-4 py-3.5 last:border-b-0 sm:flex-row sm:items-center">
                    <div class="min-w-0 flex-1">
                        <div class="truncate text-sm font-semibold text-slate-900"><?= htmlspecialchars(formatDisplayName($q)) ?></div>
                        <div class="mt-1 flex flex-wrap items-center gap-2 text-xs text-slate-500">
                            <span><?= sanitize($q['campus_name']) ?></span>
                            <span class="text-slate-300">|</span>
                            <span class="font-semibold <?= $waitingClass($wait) ?>"><?= $wait ?>d waiting</span>
                            <span class="rounded-full px-2 py-0.5 text-[0.65rem] font-bold ring-1 ring-inset <?= $deadlineClass($due_label) ?>"><?= htmlspecialchars($due_label) ?></span>
                        </div>
                    </div>
                    <a href="?page=review_application&id=<?= $q['application_id'] ?>" class="btn btn-primary btn-xs self-start sm:self-auto">Review</a>
                </div>
            <?php endforeach; else: ?>
                <?= emptyState('bi-check2-all', 'Queue is clear.') ?>
            <?php endif; ?>
        </div>
    </section>
</div>

<section class="overflow-hidden rounded-2xl border border-slate-200/80 bg-white shadow-card">
    <div class="flex items-center justify-between gap-3 border-b border-slate-100 px-5 py-4">
        <h2 class="flex items-center gap-2 text-sm font-bold text-slate-900">
            <i class="bi bi-clock-history text-brand-700"></i>
            Recently Completed
        </h2>
        <a href="?page=review_queue" class="btn btn-outline-primary btn-xs">View All</a>
    </div>
    <?php if ($recent): ?>
        <div class="table-responsive">
            <table class="table-modern mb-0 min-w-[40rem]">
                <thead>
                    <tr>
                        <th>Faculty</th>
                        <th>Campus</th>
                        <th>Outcome</th>
                        <th>Reviewed</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($recent as $r): ?>
                        <tr>
                            <td class="font-semibold text-slate-900"><?= htmlspecialchars(formatDisplayName($r)) ?></td>
                            <td><?= sanitize($r['campus_name']) ?></td>
                            <td><?= statusBadge($r['status']) ?></td>
                            <td class="text-xs whitespace-nowrap text-slate-500"><?= $r['reviewed_at'] ? date('M d, Y', strtotime($r['reviewed_at'])) : ' - ' ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php else: ?>
        <?= emptyState('bi-clock-history', 'No completed activity yet.', 'Completed evaluations will appear here.') ?>
    <?php endif; ?>
</section>

<script>
document.querySelectorAll('.ck-tab').forEach(function(btn) {
    btn.addEventListener('click', function() {
        document.querySelectorAll('.ck-tab').forEach(function(b) {
            b.classList.remove('border-brand-700', 'text-brand-700');
            b.classList.add('border-transparent', 'text-slate-400');
            b.setAttribute('aria-selected', 'false');
            const badge = b.querySelector('[data-tab-badge]');
            if (badge) {
                badge.classList.remove('bg-brand-700', 'text-white');
                badge.classList.add('bg-slate-200', 'text-slate-600');
            }
        });
        document.querySelectorAll('.ck-panel').forEach(function(p) {
            p.classList.add('hidden');
        });
        btn.classList.remove('border-transparent', 'text-slate-400');
        btn.classList.add('border-brand-700', 'text-brand-700');
        btn.setAttribute('aria-selected', 'true');
        const badge = btn.querySelector('[data-tab-badge]');
        if (badge) {
            badge.classList.remove('bg-slate-200', 'text-slate-600');
            badge.classList.add('bg-brand-700', 'text-white');
        }
        document.getElementById('tab-' + btn.dataset.tab).classList.remove('hidden');
    });
});
</script>

<?php if ($total_reviewed > 0): ?>
<script>
document.addEventListener('DOMContentLoaded', function () {
    new Chart(document.getElementById('myReviewChart'), {
        type: 'doughnut',
        data: {
            labels: ['Complete', 'Other'],
            datasets: [{
                data: [<?= $total_approved ?>, <?= max(0, $total_reviewed - $total_approved) ?>],
                backgroundColor: ['#1a3a6b', '#e2e8f0'],
                borderColor: '#fff',
                borderWidth: 3,
                hoverOffset: 4
            }]
        },
        options: {
            responsive: true, maintainAspectRatio: false, cutout: '74%',
            plugins: {
                legend: { display: false },
                tooltip: { backgroundColor: '#0f172a', cornerRadius: 8, padding: 8 }
            }
        },
        plugins: [{
            id: 'centerPct',
            beforeDraw(chart) {
                const { ctx, chartArea: { width, height, left, top } } = chart;
                const pct = <?= $total_reviewed > 0 ? round(($total_approved / $total_reviewed) * 100) : 0 ?>;
                ctx.save();
                ctx.textAlign = 'center'; ctx.textBaseline = 'middle';
                const cx = left + width / 2, cy = top + height / 2;
                ctx.font = 'bold 1.2rem sans-serif'; ctx.fillStyle = '#0f172a';
                ctx.fillText(pct + '%', cx, cy - 6);
                ctx.font = '0.6rem sans-serif'; ctx.fillStyle = '#94a3b8';
                ctx.fillText('complete', cx, cy + 9);
                ctx.restore();
            }
        }]
    });
});
</script>
<?php endif; ?>
