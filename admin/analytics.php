<?php
if (!isAdmin()) { echo '<div class="alert alert-danger">Access denied.</div>'; return; }

require_once __DIR__ . '/../includes/cycle_analytics.php';

$cycles = $pdo->query("SELECT cycle_id, cycle_name, status, start_date, end_date,
        submission_start_date, submission_deadline, evaluation_deadline
    FROM cycles ORDER BY start_date DESC, cycle_id DESC")->fetchAll(PDO::FETCH_ASSOC);
$cyclesById = array_column($cycles, null, 'cycle_id');
$defaultCycle = $cycles[0] ?? null;
foreach ($cycles as $candidate) {
    if ($candidate['status'] === 'open') {
        $defaultCycle = $candidate;
        break;
    }
}

$requestedCycleId = filter_var($_GET['cycle_id'] ?? null, FILTER_VALIDATE_INT) ?: 0;
$cycle = $cyclesById[$requestedCycleId] ?? $defaultCycle;
$comparisonChoice = $_GET['compare_cycle_id'] ?? 'previous';
$comparison = null;
if ($cycle && $comparisonChoice === 'previous') {
    $selectedPassed = false;
    foreach ($cycles as $candidate) {
        if ($selectedPassed && in_array($candidate['status'], ['closed', 'archived'], true)) {
            $comparison = $candidate;
            break;
        }
        if ($candidate['cycle_id'] === $cycle['cycle_id']) $selectedPassed = true;
    }
} elseif ($cycle) {
    $comparisonId = filter_var($comparisonChoice, FILTER_VALIDATE_INT) ?: 0;
    $comparison = $cyclesById[$comparisonId] ?? null;
    if ($comparisonId === (int)$cycle['cycle_id']) $comparison = null;
    $comparisonChoice = (string)($comparison['cycle_id'] ?? 0);
}
?>

<div class="cycle-analysis">
    <header class="cycle-analysis-header">
        <span class="cycle-analysis-header-icon" aria-hidden="true"><i class="bi bi-graph-up-arrow"></i></span>
        <div>
            <h1>Cycle Analytics</h1>
            <p>Review bottlenecks and evaluation outcomes by cycle.</p>
        </div>
    </header>

    <?php if ($cycle): ?>
    <section class="cycle-analysis-scope" aria-label="Cycle selection and dates">
        <form class="cycle-analysis-controls" method="get" action="index.php">
            <input type="hidden" name="page" value="analytics">
            <label>Cycle
                <select name="cycle_id">
                    <?php foreach ($cycles as $option): ?>
                    <option value="<?= (int)$option['cycle_id'] ?>" <?= $option['cycle_id'] === $cycle['cycle_id'] ? 'selected' : '' ?>>
                        <?= sanitize($option['cycle_name']) ?> · <?= ucfirst($option['status']) ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label>Compare with
                <select name="compare_cycle_id">
                    <option value="previous" <?= $comparisonChoice === 'previous' ? 'selected' : '' ?>>Previous completed cycle</option>
                    <option value="0" <?= $comparisonChoice === '0' ? 'selected' : '' ?>>No comparison</option>
                    <?php foreach ($cycles as $option): ?>
                    <option value="<?= (int)$option['cycle_id'] ?>" <?= $comparisonChoice === (string)$option['cycle_id'] ? 'selected' : '' ?>>
                        <?= sanitize($option['cycle_name']) ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </label>
            <button class="cycle-analysis-primary" type="submit">View report</button>
        </form>
    <?php else: ?>
    <section class="cycle-analysis-panel cycle-analysis-empty" aria-labelledby="cycle-analysis-empty-title">
        <span class="cycle-analysis-empty-icon" aria-hidden="true"><i class="bi bi-calendar3"></i></span>
        <div>
            <h2 id="cycle-analysis-empty-title">No reclassification cycles yet</h2>
            <p>Create a cycle to check evaluator coverage and track applications as they come in.</p>
        </div>
        <a class="cycle-analysis-primary" href="index.php?page=cycles">Go to Cycles <i class="bi bi-arrow-right" aria-hidden="true"></i></a>
    </section>
    </div>
    <?php return; endif; ?>

<?php
$stages = [
    'submitted' => ['Awaiting review', '#1d4e89'],
    'under_review' => ['Under review', '#4779a8'],
    'revision' => ['Needs revision', '#a65316'],
    'edit_requested' => ['Edit requested', '#6760a2'],
    'returned' => ['Returned', '#a14444'],
    'complete' => ['Evaluation complete', '#24726b'],
    'reclassified' => ['Reclassified', '#1b554f'],
    'other' => ['Other recorded status', '#64748b'],
];
$cycleId = (int)$cycle['cycle_id'];
$comparisonId = (int)($comparison['cycle_id'] ?? 0);
$applicationsByCycle = loadCycleAnalyticsApplications($pdo, $cycleId, $comparisonId);
$applications = $applicationsByCycle[$cycleId] ?? [];
$summary = cycleAnalyticsSummary($applications, $stages);
$previous = $comparison ? cycleAnalyticsSummary($applicationsByCycle[$comparisonId] ?? [], $stages) : null;
$coverage = loadCycleAnalyticsCoverage($pdo, $cycleId);
$isOpen = $cycle['status'] === 'open';
$ageThreshold = 7;
$reviewQueue = array_values(array_filter($applications, static fn(array $row): bool =>
    in_array($row['stage'], ['submitted', 'under_review'], true)));
$aged = count(array_filter($reviewQueue, static fn(array $row): bool =>
    $row['age_days'] !== null && (int)$row['age_days'] >= $ageThreshold));

$campusApplications = [];
foreach ($applications as $row) {
    $campusApplications[$row['campus_id'] ?? 0][] = $row;
}
$campusResults = [];
foreach ($campusApplications as $rows) {
    $campusResults[] = ['name' => $rows[0]['campus_name']] + cycleAnalyticsSummary($rows, $stages);
}
usort($campusResults, static fn(array $a, array $b): int =>
    $b['review_queue'] <=> $a['review_queue'] ?: $b['submitted'] <=> $a['submitted'] ?: strcasecmp($a['name'], $b['name']));
$largestQueueCampus = $campusResults[0] ?? null;

$today = new DateTimeImmutable('today');
$evaluationDeadline = $cycle['evaluation_deadline'] ?: $cycle['end_date'];
$daysUntilDeadline = (int)$today->diff(new DateTimeImmutable($evaluationDeadline))->format('%r%a');
$missingCoverage = array_keys(array_filter($coverage, static fn(array $kra): bool => $kra['evaluators'] === 0));
$blockedCategories = array_filter($coverage, static fn(array $kra): bool => $kra['evaluators'] === 0 && $kra['pending'] > 0);
$findings = [];
if ($isOpen && $summary['review_queue'] > 0) {
    $queueCount = number_format($summary['review_queue']);
    if ($daysUntilDeadline < 0) {
        $findings[] = ['Evaluation deadline passed', "{$queueCount} in the review queue. Check outstanding evaluations against the cycle deadline.", 'priority'];
    } else {
        $remainingDays = $daysUntilDeadline + 1;
        $pace = ceil($summary['review_queue'] / $remainingDays);
        $findings[] = ['Pace needed to clear the current queue',
            "{$queueCount} queued across {$remainingDays} calendar day(s), including today. About {$pace} per day clears the current queue before new submissions or resubmissions.",
            $daysUntilDeadline <= 3 ? 'priority' : ''];
    }
}

if ($isOpen && $blockedCategories) {
    $findings[] = ['Unverified evidence without evaluator coverage',
        implode(', ', array_keys($blockedCategories)) . ' have unverified entries in the review queue and no currently active assigned evaluator.', 'priority'];
} elseif ($isOpen && $missingCoverage) {
    $findings[] = ['Evaluator coverage gap', 'No active evaluator is assigned to ' . implode(', ', $missingCoverage) . '.', 'priority'];
}
if ($isOpen && $aged > 0) {
    $findings[] = ['Aging applications', "{$aged} in the current review queue were submitted at least {$ageThreshold} days ago. Check the oldest cases below.", ''];
}
if ($largestQueueCampus && $largestQueueCampus['review_queue'] > 0 && count($campusResults) > 1) {
    $queueShare = round(100 * $largestQueueCampus['review_queue'] / $summary['review_queue']);
    $findings[] = ['Where pending reviews are concentrated',
        "{$largestQueueCampus['name']} has {$largestQueueCampus['review_queue']} of {$summary['review_queue']} queued applications ({$queueShare}%).", ''];
}
if (!$findings && $summary['submitted']) {
    $findings[] = $summary['review_queue']
        ? ['Review queue at a glance', "{$summary['counts']['submitted']} awaiting review and {$summary['counts']['under_review']} under review.", '']
        : ['Review queue is clear', 'Review applications needing revision, edit requests, and returned cases in the application flow below.', ''];
}

$formatDate = static fn(?string $value): string => $value ? date('M j, Y', strtotime($value)) : '—';
$formatMetric = static function (int|float|null $value, string $unit): string {
    if ($value === null) return '—';
    return match ($unit) {
        'count' => number_format($value),
        'rate' => number_format($value, 1) . '%',
        'days' => number_format($value, 1) . ' days',
        default => number_format($value, 1) . ' pts',
    };
};
$metrics = [
    'submitted' => ['Submitted applications', 'count'],
    'completion_rate' => ['Evaluation completion rate', 'rate'],
    'reclassified' => ['Reclassified applications', 'count'],
    'review_median' => ['Median time to evaluation completion', 'days'],
    'score_median' => ['Median completed weighted score', 'points'],
];
?>
    <div class="cycle-analysis-context">
        <div>
            <span class="cycle-analysis-status"><?= ucfirst($cycle['status']) ?></span>
            <h2><?= sanitize($cycle['cycle_name']) ?></h2>
        </div>
        <dl>
            <div><dt>Standard submission window</dt><dd><?= $formatDate($cycle['submission_start_date'] ?: $cycle['start_date']) ?> – <?= $formatDate($cycle['submission_deadline'] ?: $cycle['end_date']) ?></dd></div>
            <div><dt>Evaluation deadline</dt><dd><?= $formatDate($evaluationDeadline) ?></dd></div>
        </dl>
    </div>
    </section>

    <?php if (!$summary['submitted']): ?>
    <div class="cycle-analysis-note" role="status">
        <strong>No submitted applications in this cycle.</strong>
        <?php if ($isOpen): ?>Use evaluator coverage below to check readiness before submissions arrive.<?php endif; ?>
    </div>
    <?php endif; ?>

    <?php if ($summary['submitted'] || $findings): ?>
    <div class="cycle-analysis-columns">
        <?php if ($summary['submitted']): ?>
        <section class="cycle-analysis-panel">
            <h2>Application flow</h2>
            <p><?= number_format($summary['submitted']) ?> submitted. <?= $formatMetric($summary['completion_rate'], 'rate') ?> have completed evaluation, including reclassified applications.</p>
            <ul class="cycle-analysis-flow">
                <?php foreach ($stages as $key => [$label, $color]):
                    $count = $summary['counts'][$key];
                    if (!$count) continue;
                    $share = 100 * $count / $summary['submitted'];
                ?>
                <li>
                    <span><?= $label ?></span>
                    <span class="cycle-analysis-bar" aria-hidden="true"><span style="width:<?= round($share, 2) ?>%;background:<?= $color ?>"></span></span>
                    <strong><?= number_format($count) ?></strong>
                    <span><?= $formatMetric($share, 'rate') ?></span>
                </li>
                <?php endforeach; ?>
            </ul>
        </section>
        <?php endif; ?>

        <section class="cycle-analysis-panel">
            <h2><?= $isOpen ? 'Review priorities' : 'Cycle findings' ?></h2>
            <?php foreach ($findings as [$title, $message, $tone]): ?>
            <div class="cycle-analysis-finding <?= $tone === 'priority' ? 'cycle-analysis-finding-priority' : '' ?>">
                <h3><?= sanitize($title) ?></h3>
                <p><?= sanitize($message) ?></p>
            </div>
            <?php endforeach; ?>
        </section>
    </div>
    <?php endif; ?>

    <?php if ($summary['submitted'] || ($previous && $previous['submitted'])): ?>
    <section class="cycle-analysis-panel">
        <h2><?= $comparison ? 'Cycle comparison' : 'Evaluation outcomes' ?></h2>
        <?php if ($comparison && ($isOpen || $comparison['status'] === 'open')): ?>
        <p>Open cycle results are in progress. Differences reflect the recorded totals for each cycle to date.</p>
        <?php elseif (!$comparison && $comparisonChoice === 'previous'): ?>
        <p>No earlier completed cycle is available for comparison.</p>
        <?php endif; ?>
        <div class="table-responsive" tabindex="0" role="region" aria-label="Evaluation outcomes">
            <table class="cycle-analysis-table">
                <thead><tr>
                    <th scope="col">Measure</th>
                    <th scope="col"><?= sanitize($cycle['cycle_name']) ?></th>
                    <?php if ($comparison): ?>
                    <th scope="col"><?= sanitize($comparison['cycle_name']) ?></th>
                    <th scope="col">Difference</th>
                    <?php endif; ?>
                </tr></thead>
                <tbody>
                    <?php foreach ($metrics as $key => [$label, $unit]):
                        $current = $summary[$key];
                        $reference = $previous[$key] ?? null;
                        $delta = $current !== null && $reference !== null ? round($current - $reference, $unit === 'count' ? 0 : 1) : null;
                        $sampleKey = match ($key) {
                            'review_median' => 'review_count',
                            'score_median' => 'score_count',
                            default => null,
                        };
                    ?>
                    <tr>
                        <th scope="row"><?= $label ?></th>
                        <td><strong><?= $formatMetric($current, $unit) ?></strong><?php if ($sampleKey && $current !== null): ?> <small>n=<?= $summary[$sampleKey] ?></small><?php endif; ?></td>
                        <?php if ($comparison): ?>
                        <td><?= $formatMetric($reference, $unit) ?><?php if ($sampleKey && $reference !== null): ?> <small>n=<?= $previous[$sampleKey] ?></small><?php endif; ?></td>
                        <td><?= $delta === null ? '—' : ($delta > 0 ? '+' : '') . ($unit === 'rate' ? number_format($delta, 1) . ' pp' : $formatMetric($delta, $unit)) ?></td>
                        <?php endif; ?>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <p class="cycle-analysis-method">Completion rate uses submitted applications as the denominator and excludes drafts and expired drafts. Median review time uses recorded submission and review timestamps for completed evaluations.</p>
    </section>
    <?php endif; ?>

    <?php if ($isOpen && $reviewQueue): ?>
    <section class="cycle-analysis-panel">
        <h2>Oldest applications in the review queue</h2>
        <p>Age is measured from the recorded submission date. Open a case to see its evidence and review history.</p>
        <div class="table-responsive" tabindex="0" role="region" aria-label="Oldest applications in the review queue">
            <table class="cycle-analysis-table">
                <thead><tr><th scope="col">Application</th><th scope="col">Campus</th><th scope="col">Stage</th><th scope="col">Age</th></tr></thead>
                <tbody>
                    <?php foreach (array_slice($reviewQueue, 0, 5) as $row): ?>
                    <tr>
                        <th scope="row"><a href="index.php?page=view_application&amp;id=<?= (int)$row['application_id'] ?>"><?= sanitize($row['full_name']) ?></a></th>
                        <td><?= sanitize($row['campus_name']) ?></td>
                        <td><?= $stages[$row['stage']][0] ?></td>
                        <td><?= $row['age_days'] === null ? '—' : (int)$row['age_days'] . ' days' ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </section>
    <?php endif; ?>

    <section class="cycle-analysis-panel">
        <div class="cycle-analysis-panel-heading">
            <div>
                <h2>Evaluator coverage by KRA</h2>
                <p>Current assignments and evidence awaiting verification in the selected cycle's review queue.</p>
            </div>
            <a href="index.php?page=kra_assignments&amp;cycle_id=<?= $cycleId ?>">View KRA assignments <i class="bi bi-arrow-right" aria-hidden="true"></i></a>
        </div>
        <div class="table-responsive" tabindex="0" role="region" aria-label="Evaluator coverage by KRA">
            <table class="cycle-analysis-table">
                <thead><tr><th scope="col">KRA</th><th scope="col">Active evaluators</th><th scope="col">Unverified / queued entries</th><th scope="col">Applications affected</th></tr></thead>
                <tbody>
                    <?php foreach ($coverage as $category => $kra): ?>
                    <tr>
                        <th scope="row"><small><?= $kra['label'] ?></small> <?= sanitize($category) ?></th>
                        <td class="<?= !$kra['evaluators'] ? 'cycle-analysis-missing' : '' ?>"><?= $kra['evaluators'] ? number_format($kra['evaluators']) : 'None assigned' ?></td>
                        <td><?= number_format($kra['pending']) ?> / <?= number_format($kra['queue_entries']) ?></td>
                        <td><?= number_format($kra['affected']) ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </section>

    <?php if ($campusResults): ?>
    <section class="cycle-analysis-panel">
        <h2>Campus review backlog and outcomes</h2>
        <p>Ordered by review backlog. Campus comes from each faculty member's current profile.</p>
        <div class="table-responsive" tabindex="0" role="region" aria-label="Campus review backlog and outcomes">
            <table class="cycle-analysis-table">
                <thead><tr><th scope="col">Campus</th><th scope="col">Submitted</th><th scope="col">In review queue</th><th scope="col">Needs revision</th><th scope="col">Evaluation complete</th><th scope="col">Median weighted score</th></tr></thead>
                <tbody>
                    <?php foreach ($campusResults as $campus): ?>
                    <tr>
                        <th scope="row"><?= sanitize($campus['name']) ?></th>
                        <td><?= number_format($campus['submitted']) ?></td>
                        <td><?= number_format($campus['review_queue']) ?></td>
                        <td><?= number_format($campus['counts']['revision']) ?></td>
                        <td><?= number_format($campus['complete']) ?> / <?= number_format($campus['submitted']) ?> <small>(<?= $formatMetric($campus['completion_rate'], 'rate') ?>)</small></td>
                        <td><?= $formatMetric($campus['score_median'], 'points') ?><?php if ($campus['score_count']): ?> <small>n=<?= $campus['score_count'] ?></small><?php endif; ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </section>
    <?php endif; ?>
</div>
