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
$comparisonChoice = $_GET['compare_cycle_id'] ?? '0';
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

    <?php if (!$cycle): ?>
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
    'complete' => ['Approved', '#24726b'],
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
$scoreBreakdown = loadCycleAnalyticsScoreBreakdown($pdo, $cycleId);
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
    'review_median' => ['Median time to evaluation completion', 'days'],
    'score_median' => ['Median completed weighted score', 'points'],
];
?>
    <section class="cycle-analysis-scope" aria-label="Cycle details and filters">
        <div class="cycle-analysis-context">
            <div class="cycle-analysis-cycle-name">
                <h2><?= sanitize($cycle['cycle_name']) ?></h2>
                <span class="cycle-analysis-status<?= $isOpen ? ' cycle-analysis-status-open' : '' ?>"><?= ucfirst($cycle['status']) ?></span>
            </div>
            <dl>
                <div><dt>Submission window</dt><dd><?= $formatDate($cycle['submission_start_date'] ?: $cycle['start_date']) ?> &ndash; <?= $formatDate($cycle['submission_deadline'] ?: $cycle['end_date']) ?></dd></div>
                <div><dt>Evaluation deadline</dt><dd><?= $formatDate($evaluationDeadline) ?></dd></div>
            </dl>
        </div>
        <form class="cycle-analysis-controls" method="get" action="index.php">
            <input type="hidden" name="page" value="analytics">
            <label>Cycle
                <select name="cycle_id" onchange="this.form.requestSubmit()">
                    <?php foreach ($cycles as $option): ?>
                    <option value="<?= (int)$option['cycle_id'] ?>" <?= $option['cycle_id'] === $cycle['cycle_id'] ? 'selected' : '' ?>>
                        <?= sanitize($option['cycle_name']) ?> · <?= ucfirst($option['status']) ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </label>
            <noscript>
                <button class="cycle-analysis-primary" type="submit">Load cycle</button>
            </noscript>
        </form>
    </section>

    <?php if (!$summary['submitted']): ?>
    <div class="cycle-analysis-note" role="status">
        <i class="bi bi-info-circle" aria-hidden="true"></i>
        <span>No applications have been submitted for this cycle.</span>
    </div>
    <?php endif; ?>

    <?php if ($summary['submitted'] || $findings): ?>
    <div class="cycle-analysis-columns">
        <?php if ($summary['submitted']): ?>
        <section class="cycle-analysis-panel">
            <h2>Application flow</h2>
            <p><?= number_format($summary['submitted']) ?> submitted. <?= $formatMetric($summary['completion_rate'], 'rate') ?> have completed evaluation.</p>
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
                    ?>
                    <tr>
                        <th scope="row"><?= $label ?></th>
                        <td><strong><?= $formatMetric($current, $unit) ?></strong></td>
                        <?php if ($comparison): ?>
                        <td><?= $formatMetric($reference, $unit) ?></td>
                        <td><?= $delta === null ? '—' : ($delta > 0 ? '+' : '') . ($unit === 'rate' ? number_format($delta, 1) . ' pp' : $formatMetric($delta, $unit)) ?></td>
                        <?php endif; ?>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <p>Approved means the SUCFRMS evaluation is complete. Further review and final reclassification happen outside SUCFRMS.</p>
        <p class="cycle-analysis-method">Completion rate uses submitted applications as the denominator and excludes drafts and expired drafts. Median review time uses recorded submission and review timestamps for completed evaluations.</p>
    </section>
    <?php endif; ?>

    <section class="cycle-analysis-panel" aria-labelledby="cycle-score-breakdown">
        <h2 id="cycle-score-breakdown">Where applicants scored higher or lower</h2>
        <?php if (!$scoreBreakdown['completed']): ?>
        <p>The score breakdown will appear when this cycle has a completed evaluation.</p>
        <?php elseif (!$scoreBreakdown['matched']): ?>
        <p>A score breakdown could not be matched to the saved totals for this cycle.</p>
        <?php else: ?>
        <?php
        $displayScores = [];
        foreach ($scoreBreakdown['categories'] as $category => $scores) {
            if ($scores['median'] !== null) {
                $displayScores[$category] = round($scores['median'], 1);
            }
        }
        ?>
        <?php if (count($displayScores) > 1 && max($displayScores) !== min($displayScores)): ?>
        <dl class="cycle-analysis-score-highlights">
            <?php foreach (['Highest typical score' => max($displayScores), 'Lowest typical score' => min($displayScores)] as $label => $score): ?>
            <div>
                <dt><?= $label ?></dt>
                <dd><strong><?= $formatMetric($score, 'rate') ?></strong> <?= sanitize(implode(', ', array_keys($displayScores, $score, true))) ?></dd>
            </div>
            <?php endforeach; ?>
        </dl>
        <?php endif; ?>
        <p>The typical score is the middle result across completed evaluations, shown as a percentage of available points. Select an area to see where applicants earned points.</p>
        <?php if ($scoreBreakdown['matched'] < $scoreBreakdown['completed']): ?>
        <p>Breakdowns could be matched to saved scores for <?= number_format($scoreBreakdown['matched']) ?> of <?= number_format($scoreBreakdown['completed']) ?> completed evaluations.</p>
        <?php endif; ?>
        <div class="cycle-analysis-score-list">
            <?php foreach ($scoreBreakdown['categories'] as $category => $scores): ?>
            <details class="cycle-analysis-score-detail">
                <summary>
                    <span><?= sanitize($category) ?></span>
                    <strong><?= $scores['median'] === null ? 'No scores' : $formatMetric($scores['median'], 'rate') ?></strong>
                    <small><?= number_format($scores['count']) ?> <?= $scores['count'] === 1 ? 'evaluation' : 'evaluations' ?></small>
                </summary>
                <?php if ($scores['criteria']): ?>
                <p>Criteria where eligible applicants earned points most often appear first.</p>
                <div class="table-responsive" tabindex="0" role="region" aria-label="<?= sanitize($category) ?> criteria">
                    <table class="cycle-analysis-table">
                        <thead><tr>
                            <th scope="col">Criterion</th>
                            <th scope="col">Earned points</th>
                            <th scope="col">Scored zero</th>
                        </tr></thead>
                        <tbody>
                            <?php foreach ($scores['criteria'] as $criterion): ?>
                            <?php $eligible = number_format($criterion['earned'] + $criterion['zero']); ?>
                            <tr>
                                <th scope="row"><?= sanitize($criterion['label']) ?></th>
                                <td><?= number_format($criterion['earned']) ?> of <?= $eligible ?></td>
                                <td><?= number_format($criterion['zero']) ?> of <?= $eligible ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php else: ?>
                <p>No applicable criteria could be matched for this KRA.</p>
                <?php endif; ?>
            </details>
            <?php endforeach; ?>
        </div>
        <p class="cycle-analysis-method">Each criterion counts only applicants it applied to.</p>
        <?php endif; ?>
    </section>

    <?php if ($scoreBreakdown['profiles']):
        $profileCategories = array_keys($scoreBreakdown['categories']);
        $profileCount = count($scoreBreakdown['profiles']);
        $scoreRanges = array_fill_keys($profileCategories, [0, 0, 0]);
        foreach ($scoreBreakdown['profiles'] as $profile) {
            foreach ($profile as $category => $score) {
                $displayScore = round($score, 1);
                $range = $displayScore < 50 ? 0 : ($displayScore < 75 ? 1 : 2);
                $scoreRanges[$category][$range]++;
            }
        }
    ?>
    <section class="cycle-analysis-panel cycle-analysis-score-module" aria-labelledby="cycle-score-module-title">
        <div>
            <h2 id="cycle-score-module-title">Applicant scores</h2>
            <p><?= number_format($profileCount) ?> completed <?= $profileCount === 1 ? 'evaluation' : 'evaluations' ?> with scores to compare</p>
        </div>
        <button class="cycle-analysis-primary" type="button" aria-haspopup="dialog" aria-controls="cycle-score-summary"
                onclick="document.getElementById('cycle-score-summary').showModal()">View score summary</button>
    </section>

    <dialog class="cycle-analysis-score-dialog" id="cycle-score-summary" aria-labelledby="cycle-score-summary-title">
        <div class="cycle-analysis-panel-heading">
            <h2 id="cycle-score-summary-title">Applicant score summary</h2>
            <form method="dialog">
                <button class="cycle-analysis-primary" type="submit" autofocus>Close</button>
            </form>
        </div>
        <p>See how many completed evaluations fell in each score range for each area.</p>
        <div class="table-responsive" tabindex="0" role="region" aria-label="Evaluation counts by score range">
            <table class="cycle-analysis-table cycle-analysis-score-ranges">
                <thead><tr>
                    <th scope="col">Scoring area</th>
                    <th scope="col">Below 50%</th>
                    <th scope="col">50% to under 75%</th>
                    <th scope="col">75% and above</th>
                </tr></thead>
                <tbody>
                    <?php foreach ($scoreRanges as $category => $counts): ?>
                    <tr>
                        <th scope="row"><?= sanitize($category) ?></th>
                        <?php foreach ($counts as $count): ?>
                        <td><?= number_format($count) ?></td>
                        <?php endforeach; ?>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <details class="cycle-analysis-score-detail cycle-analysis-score-drilldown">
            <summary id="cycle-score-patterns">View individual scores</summary>
            <p>Each numbered row is one completed evaluation. Darker cells show higher scores.</p>
            <div class="table-responsive" tabindex="0" role="region" aria-labelledby="cycle-score-patterns">
                <table class="cycle-analysis-table cycle-analysis-score-patterns">
                    <thead><tr>
                        <th scope="col">Evaluation</th>
                        <?php foreach ($profileCategories as $category): ?>
                        <th scope="col"><?= sanitize($category) ?></th>
                        <?php endforeach; ?>
                    </tr></thead>
                    <tbody>
                        <?php foreach ($scoreBreakdown['profiles'] as $index => $profile): ?>
                        <tr>
                            <th scope="row">Evaluation <?= number_format($index + 1) ?></th>
                            <?php foreach ($profileCategories as $category): ?>
                            <?php $score = $profile[$category] ?? null; ?>
                            <td<?php if ($score !== null): ?> class="cycle-analysis-score-cell" style="--kra-share:<?= min(100, max(0, $score)) ?>%"<?php endif; ?>>
                                <?= $formatMetric($score, 'rate') ?>
                            </td>
                            <?php endforeach; ?>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </details>
    </dialog>
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

    <section class="cycle-analysis-panel cycle-analysis-coverage">
        <div class="cycle-analysis-panel-heading">
            <div>
                <h2>Evaluator coverage by KRA</h2>
                <p>Active assignments and evidence awaiting verification.</p>
            </div>
            <a class="cycle-analysis-assignment-link" href="index.php?page=kra_assignments&amp;cycle_id=<?= $cycleId ?>">KRA assignments <i class="bi bi-arrow-right" aria-hidden="true"></i></a>
        </div>
        <div class="table-responsive" tabindex="0" role="region" aria-label="Evaluator coverage by KRA">
            <table class="cycle-analysis-table">
                <thead><tr><th scope="col">KRA</th><th scope="col">Active evaluators</th><th scope="col">Unverified / queued</th><th scope="col">Applications affected</th></tr></thead>
                <tbody>
                    <?php foreach ($coverage as $category => $kra): ?>
                    <tr>
                        <th scope="row"><small class="cycle-analysis-kra-code"><?= $kra['label'] ?></small> <?= sanitize($category) ?></th>
                        <td class="<?= !$kra['evaluators'] ? 'cycle-analysis-missing' : '' ?>"><?= $kra['evaluators'] ? number_format($kra['evaluators']) : 'None assigned' ?></td>
                        <td><strong><?= number_format($kra['pending']) ?></strong><span class="cycle-analysis-coverage-muted"> / <?= number_format($kra['queue_entries']) ?></span></td>
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
                        <td><?= $formatMetric($campus['score_median'], 'points') ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </section>
    <?php endif; ?>
</div>
