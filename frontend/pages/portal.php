<?php require __DIR__ . '/../../backend/controllers/pages/portal.php'; ?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width,initial-scale=1.0">
    <meta name="theme-color" content="#1a3a6b">
    <title>SUCFRMS — Faculty Portal</title>
    <link rel="preload" href="../assets/fonts/InterVariable.woff2" as="font" type="font/woff2" crossorigin>
    <link href="../assets/vendor/bootstrap-icons/bootstrap-icons.min.css" rel="stylesheet">
    <link rel="stylesheet" href="../assets/css/app.css?v=<?= @filemtime(__DIR__ . '/../assets/css/app.css') ?: 1 ?>">
</head>
<body class="m-0 min-h-screen bg-canvas font-sans text-ink antialiased">

<!-- Navbar -->
<nav class="sticky top-0 z-[200] flex h-16 items-center gap-3 border-b border-white/10 bg-brand-800 px-4 shadow-lg sm:px-6">
    <img src="../assets/images/logo.jpg" class="size-9 shrink-0 rounded-full object-cover ring-2 ring-white/30" alt="Logo">
    <span class="flex-1 text-sm font-semibold text-white">SUC Faculty Reclassification <span class="hidden text-brand-100 sm:inline">Management System</span></span>
    <div class="relative flex cursor-pointer items-center gap-2" id="nu" onclick="toggleDD()">
        <span class="hidden text-sm font-medium text-white/90 sm:inline"><?= sanitize($full_name) ?></span>
        <?php if ($profile_pic): ?>
        <img src="../../<?= sanitize($profile_pic) ?>" class="size-9 rounded-full object-cover ring-2 ring-white/30" alt="">
        <?php else: ?>
        <div class="flex size-9 items-center justify-center rounded-full bg-white/15 text-xs font-bold tracking-wider text-white ring-2 ring-white/30"><?= $init ?></div>
        <?php endif; ?>
        <div class="absolute top-[calc(100%+12px)] right-0 z-[500] hidden min-w-64 overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-pop [&.open]:block" id="ndd">
            <!-- Header with user info -->
            <div class="bg-gradient-to-br from-brand-800 to-brand-600 p-4">
                <div class="flex items-center gap-3">
                    <?php if ($profile_pic): ?>
                    <img src="../../<?= sanitize($profile_pic) ?>" class="size-10 shrink-0 rounded-full object-cover ring-2 ring-white/40" alt="">
                    <?php else: ?>
                    <div class="flex size-10 shrink-0 items-center justify-center rounded-full bg-white/20 text-xs font-bold text-white ring-2 ring-white/30"><?= $init ?></div>
                    <?php endif; ?>
                    <div class="min-w-0">
                        <div class="truncate text-sm font-semibold text-white"><?= sanitize($full_name) ?></div>
                        <div class="truncate text-xs text-white/65"><?= sanitize($email) ?></div>
                    </div>
                </div>
            </div>
            <div class="p-2 text-sm">
                <a href="../../index.php?page=profile" class="flex items-center gap-3 rounded-lg px-3 py-2.5 text-slate-700 no-underline hover:bg-slate-50"><i class="bi bi-person-circle text-brand-600"></i>My Profile</a>
                <a href="../../index.php?page=my_application" class="flex items-center gap-3 rounded-lg px-3 py-2.5 text-slate-700 no-underline hover:bg-slate-50"><i class="bi bi-file-earmark-text text-brand-600"></i>My Application</a>
                <a href="../../backend/auth/logout.php" class="flex items-center gap-3 rounded-lg px-3 py-2.5 text-rose-600 no-underline hover:bg-rose-50"><i class="bi bi-box-arrow-right"></i>Sign Out</a>
            </div>
        </div>
    </div>
</nav>

<!-- Body -->
<div class="mx-auto grid max-w-6xl items-start gap-6 px-4 py-6 md:grid-cols-[minmax(0,1fr)_320px] sm:px-6 lg:py-10">
    <section class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-card">

        <!-- Card header -->
        <div class="flex items-center gap-4 bg-gradient-to-r from-brand-800 to-brand-600 px-5 py-5 sm:px-6">
            <img src="../assets/images/logo.jpg" class="size-12 shrink-0 rounded-full object-cover ring-2 ring-white/30" alt="">
            <div>
                <div class="text-base font-semibold text-white">
                    <?= $cycle ? sanitize($current_app['cycle_name'] ?? 'Active Cycle') : 'No Active Reclassification Cycle' ?>
                </div>
                <div class="mt-1 text-xs text-brand-100/75">Reclassification Application · SUCFRMS</div>
            </div>
        </div>

        <div class="border-b border-slate-200 bg-slate-50 px-6">
            <div class="inline-flex border-b-2 border-brand-600 px-1 py-3 text-xs font-semibold text-brand-700"><?= sanitize($rank) ?></div>
        </div>

        <table class="w-full border-collapse">
            <tr>
                <td class="w-[42%] border-b border-slate-100 px-5 py-3.5 text-[0.68rem] font-semibold tracking-wider text-slate-400 uppercase sm:px-6">Applicant Name</td>
                <td class="border-b border-slate-100 px-5 py-3.5 text-sm font-medium text-slate-800 sm:px-6"><?= sanitize($full_name) ?></td>
            </tr>
            <tr>
                <td class="border-b border-slate-100 px-5 py-3.5 text-[0.68rem] font-semibold tracking-wider text-slate-400 uppercase sm:px-6">Present Rank</td>
                <td class="border-b border-slate-100 px-5 py-3.5 text-sm font-medium text-slate-800 sm:px-6"><?= sanitize($rank) ?></td>
            </tr>
            <tr>
                <td class="border-b border-slate-100 px-5 py-3.5 text-[0.68rem] font-semibold tracking-wider text-slate-400 uppercase sm:px-6">Application Status</td>
                <td class="border-b border-slate-100 px-5 py-3.5 sm:px-6">
                    <?= statusBadge($current_app['status'] ?? 'draft') ?>
                </td>
            </tr>
            <tr>
                <td class="border-b border-slate-100 px-5 py-3.5 text-[0.68rem] font-semibold tracking-wider text-slate-400 uppercase sm:px-6">Employee ID</td>
                <td class="border-b border-slate-100 px-5 py-3.5 text-sm font-medium text-slate-800 sm:px-6"><?= sanitize($employee_id) ?></td>
            </tr>
            <tr>
                <td class="border-b border-slate-100 px-5 py-3.5 text-[0.68rem] font-semibold tracking-wider text-slate-400 uppercase sm:px-6">Campus</td>
                <td class="border-b border-slate-100 px-5 py-3.5 text-sm font-medium text-slate-800 sm:px-6"><?= sanitize($campus) ?></td>
            </tr>
            <tr>
                <td class="border-b border-slate-100 px-5 py-3.5 text-[0.68rem] font-semibold tracking-wider text-slate-400 uppercase sm:px-6">Date Registered</td>
                <td class="border-b border-slate-100 px-5 py-3.5 text-sm font-medium text-slate-800 sm:px-6"><?= $date_registered ?></td>
            </tr>
            <?php if ($cycle && $current_app): ?>
            <tr>
                <td class="border-b border-slate-100 px-5 py-3.5 text-[0.68rem] font-semibold tracking-wider text-slate-400 uppercase sm:px-6">Cycle</td>
                <td class="border-b border-slate-100 px-5 py-3.5 text-sm font-medium text-slate-800 sm:px-6"><?= sanitize($current_app['cycle_name'] ?? '—') ?></td>
            </tr>
            <tr>
                <td class="border-b border-slate-100 px-5 py-3.5 text-[0.68rem] font-semibold tracking-wider text-slate-400 uppercase sm:px-6">Submission Deadline</td>
                <td class="border-b border-slate-100 px-5 py-3.5 text-sm font-medium <?= $deadline_passed ? 'text-rose-600' : 'text-slate-800' ?> sm:px-6">
                    <?= $deadline_str ?>
                    <?php if (!$deadline_passed && !empty($current_app['submission_deadline'])): ?>
                    <?php $days_left = (int)ceil((strtotime($current_app['submission_deadline'].' 23:59:59') - time()) / 86400); ?>
                    <span class="ml-2 text-xs font-normal <?= $days_left <= 7 ? 'text-rose-600' : 'text-slate-500' ?>">
                        (<?= $days_left ?> day<?= $days_left===1?'':'s' ?> remaining)
                    </span>
                    <?php elseif ($deadline_passed): ?>
                    <span class="ml-2 text-xs text-rose-600">(Deadline passed)</span>
                    <?php endif; ?>
                </td>
            </tr>
            <?php endif; ?>
            <tr>
                <td class="px-5 py-3.5 text-[0.68rem] font-semibold tracking-wider text-slate-400 uppercase sm:px-6">Email Address</td>
                <td class="px-5 py-3.5 text-sm font-medium sm:px-6"><a href="mailto:<?= sanitize($email) ?>" class="text-brand-600 no-underline hover:text-brand-800"><?= sanitize($email) ?></a></td>
            </tr>
        </table>

        <div class="flex flex-col gap-3 border-t border-slate-200 bg-slate-50 px-5 py-5 sm:flex-row sm:px-6">
            <a href="pre_evaluation.php" class="btn btn-outline-primary flex-1">
                <i class="bi bi-calculator"></i> Self-Assessment
            </a>
            <?php if ($cycle): ?>
            <a href="<?= $step_link ?>" class="btn btn-primary flex-1">
                <i class="bi bi-file-earmark-text"></i> Reclassification
            </a>
            <?php else: ?>
            <button onclick="document.getElementById('noCycleModal').style.display='flex'"
                    class="btn flex-1 cursor-not-allowed border-slate-200 bg-slate-100 text-slate-400">
                <i class="bi bi-file-earmark-text"></i> Reclassification
            </button>
            <?php endif; ?>
        </div>

    </section>

    <!-- Right side panel -->
    <aside class="grid gap-5">

        <section class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-card">
            <h2 class="m-0 border-b border-slate-200 bg-slate-50 px-4 py-3 text-[0.68rem] font-semibold tracking-wider text-slate-500 uppercase">Rank Standing</h2>
            <div class="p-4">
                <div class="mb-2 text-sm font-semibold text-brand-700"><?= sanitize($rank) ?></div>
                <progress class="portal-progress mb-2 block h-2 w-full text-brand-600" value="<?= $pct ?>" max="100"><?= $pct ?>%</progress>
                <div class="text-xs text-slate-400">Step <?= ($ri !== false ? $ri+1 : '—') ?> of <?= count($all_ranks) ?> in the rank scale</div>
            </div>
        </section>

        <section class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-card">
            <h2 class="m-0 border-b border-slate-200 bg-slate-50 px-4 py-3 text-[0.68rem] font-semibold tracking-wider text-slate-500 uppercase">Cycle Status</h2>
            <div class="p-4">
                <?php if ($cycle): ?>
                <div class="mb-3 text-sm font-semibold text-brand-700">
                    <?= sanitize($current_app['cycle_name'] ?? '—') ?>
                </div>
                <?php if (!$deadline_passed && !empty($current_app['submission_deadline'])): ?>
                <?php $days_left = (int)ceil((strtotime($current_app['submission_deadline'].' 23:59:59') - time()) / 86400); ?>
                <div class="mb-2 flex items-center justify-between">
                    <span class="text-[0.68rem] font-medium tracking-wider text-slate-400 uppercase">Days Remaining</span>
                    <span class="text-xl font-bold <?= $days_left <= 7 ? 'text-rose-600' : ($days_left <= 14 ? 'text-amber-600' : 'text-brand-700') ?>"><?= $days_left ?></span>
                </div>
                <?php
                    $total_days = max(1, (int)ceil((strtotime($current_app['submission_deadline'].' 23:59:59') - strtotime($current_app['submission_start_date'] ?? $current_app['submission_deadline'])) / 86400));
                    $elapsed_pct = min(100, max(0, 100 - round(($days_left / $total_days) * 100)));
                ?>
                <progress class="portal-progress block h-1.5 w-full <?= $days_left <= 7 ? 'text-rose-600' : ($days_left <= 14 ? 'text-amber-500' : 'text-brand-600') ?>" value="<?= $elapsed_pct ?>" max="100"><?= $elapsed_pct ?>%</progress>
                <div class="mt-2 text-xs text-slate-400">Deadline: <?= $deadline_str ?></div>
                <?php elseif ($deadline_passed): ?>
                <div class="text-sm font-medium text-rose-600">Submission deadline has passed.</div>
                <?php endif; ?>
                <?php else: ?>
                <div class="text-sm text-slate-500">No active cycle at this time.</div>
                <div class="mt-1 text-xs text-slate-400">Use Self-Assessment to prepare while waiting.</div>
                <?php endif; ?>
            </div>
        </section>

        <section class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-card">
            <h2 class="m-0 border-b border-slate-200 bg-slate-50 px-4 py-3 text-[0.68rem] font-semibold tracking-wider text-slate-500 uppercase">Quick Access</h2>
            <div class="grid p-2">
                <?php
                $quickLinks = [
                    ['../../index.php?page=my_application', 'bi-file-earmark-text', 'Application Status', ''],
                    ['../../index.php?page=apply', 'bi-ui-checks-grid', 'KRA Entry', ''],
                    ['../../index.php?page=appeals', 'bi-chat-square-text', 'Appeals', ''],
                    ['../../index.php?page=my_audit', 'bi-clock-history', 'Activity Log', ''],
                    ['../../index.php?page=help', 'bi-question-circle', 'Tutorial / Help', ''],
                    ['../../backend/auth/logout.php', 'bi-box-arrow-right', 'Sign Out', 'text-rose-600 hover:bg-rose-50'],
                ];
                foreach ($quickLinks as [$href, $icon, $label, $tone]):
                ?>
                <a href="<?= $href ?>" class="flex items-center gap-3 rounded-lg px-3 py-2.5 text-sm font-medium text-slate-700 no-underline hover:bg-slate-50 <?= $tone ?>">
                    <i class="bi <?= $icon ?> w-4 text-center <?= $tone ? '' : 'text-brand-600' ?>"></i><?= $label ?>
                </a>
                <?php endforeach; ?>
            </div>
        </section>

    </aside>
</div>

<!-- No cycle warning modal -->
<div id="noCycleModal" class="fixed inset-0 z-[99999] hidden items-center justify-center bg-slate-950/50 p-4 backdrop-blur-sm">
    <div class="w-full max-w-sm rounded-2xl bg-white p-6 text-center shadow-pop">
        <span class="mx-auto mb-4 flex size-14 items-center justify-center rounded-2xl bg-slate-100 text-2xl text-slate-500"><i class="bi bi-calendar-x"></i></span>
        <h2 class="m-0 text-base font-semibold text-slate-900">No Active Cycle</h2>
        <p class="m-0 mt-2 mb-6 text-sm leading-relaxed text-slate-500">
            There is currently no open reclassification cycle.<br>
            Please wait for the administrator to open one.<br>
            You may use <strong>Self-Assessment</strong> in the meantime.
        </p>
        <button onclick="document.getElementById('noCycleModal').style.display='none'" class="btn btn-primary">OK</button>
    </div>
</div>

<script>
function toggleDD() { document.getElementById('ndd').classList.toggle('open'); }
document.addEventListener('click', function(e) {
    const n = document.getElementById('nu'), d = document.getElementById('ndd');
    if (d && n && !n.contains(e.target)) d.classList.remove('open');
});
</script>
</body>
</html>
