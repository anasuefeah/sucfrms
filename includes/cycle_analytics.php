<?php

function cycleAnalyticsMedian(array $values): ?float
{
    if (!$values) return null;
    sort($values, SORT_NUMERIC);
    $middle = intdiv(count($values), 2);
    return count($values) % 2 ? $values[$middle] : ($values[$middle - 1] + $values[$middle]) / 2;
}

function cycleAnalyticsSummary(array $applications, array $stages): array
{
    $counts = array_fill_keys(array_keys($stages), 0);
    $scores = [];
    $reviewDays = [];
    foreach ($applications as $application) {
        $stage = $application['stage'];
        $counts[$stage]++;
        if ($stage !== 'complete' && $stage !== 'reclassified') continue;
        if ($application['weighted_score'] !== null) $scores[] = (float)$application['weighted_score'];
        if ($application['review_days'] !== null && $application['review_days'] >= 0) {
            $reviewDays[] = (float)$application['review_days'];
        }
    }
    $submitted = count($applications);
    $complete = $counts['complete'] + $counts['reclassified'];

    return [
        'counts' => $counts,
        'submitted' => $submitted,
        'complete' => $complete,
        'reclassified' => $counts['reclassified'],
        'completion_rate' => $submitted ? 100 * $complete / $submitted : null,
        'review_queue' => $counts['submitted'] + $counts['under_review'],
        'score_median' => cycleAnalyticsMedian($scores),
        'score_count' => count($scores),
        'review_median' => cycleAnalyticsMedian($reviewDays),
        'review_count' => count($reviewDays),
    ];
}

function loadCycleAnalyticsApplications(PDO $pdo, int $cycleId, int $comparisonId): array
{
    // Use persisted scores and timestamps. Analytics must never recalculate an application.
    $query = $pdo->prepare("SELECT a.cycle_id, a.application_id, a.status, a.weighted_score,
            u.full_name, u.campus_id, COALESCE(c.campus_name, 'Unassigned') AS campus_name,
            GREATEST(0, DATEDIFF(CURRENT_DATE, a.submitted_at)) AS age_days,
            TIMESTAMPDIFF(MINUTE, a.submitted_at, a.reviewed_at) / 1440.0 AS review_days
        FROM applications a
        JOIN users u ON u.user_id = a.user_id
        LEFT JOIN campuses c ON c.campus_id = u.campus_id
        WHERE a.cycle_id IN (?, ?) AND a.status NOT IN ('draft', 'expired')
        ORDER BY a.submitted_at IS NULL, a.submitted_at, a.application_id");
    $query->execute([$cycleId, $comparisonId ?: $cycleId]);
    $byCycle = [];
    while ($row = $query->fetch(PDO::FETCH_ASSOC)) {
        $row['stage'] = match ($row['status']) {
            'submitted', 'under_review', 'reclassified' => $row['status'],
            'approved' => 'complete',
            'needs_revision' => 'revision',
            'rejected', 'admin_rejected' => 'returned',
            'edit_requested' => 'edit_requested',
            default => 'other',
        };
        $byCycle[(int)$row['cycle_id']][] = $row;
    }
    return $byCycle;
}

function loadCycleAnalyticsCoverage(PDO $pdo, int $cycleId): array
{
    $assignments = $pdo->prepare("SELECT k.kra_category, COUNT(DISTINCT k.checker_id)
        FROM checker_kra_assignments k
        JOIN users u ON u.user_id = k.checker_id AND u.role = 'evaluator' AND u.status = 'active'
        WHERE k.cycle_id = ? GROUP BY k.kra_category");
    $assignments->execute([$cycleId]);
    $assigned = $assignments->fetchAll(PDO::FETCH_KEY_PAIR);

    $work = $pdo->prepare("SELECT s.kra_category, COUNT(*) AS queue_entries,
            SUM(CASE WHEN COALESCE(s.verified, 0) = 0 THEN 1 ELSE 0 END) AS awaiting_verification,
            COUNT(DISTINCT CASE WHEN COALESCE(s.verified, 0) = 0 THEN s.application_id END) AS affected_applications
        FROM kra_submissions s JOIN applications a ON a.application_id = s.application_id
        WHERE a.cycle_id = ? AND a.status IN ('submitted', 'under_review')
        GROUP BY s.kra_category");
    $work->execute([$cycleId]);
    $entries = array_column($work->fetchAll(PDO::FETCH_ASSOC), null, 'kra_category');

    $coverage = [];
    foreach (kraAssignmentCategories() as $category => $label) {
        $coverage[$category] = [
            'label' => $label,
            'evaluators' => (int)($assigned[$category] ?? 0),
            'queue_entries' => (int)($entries[$category]['queue_entries'] ?? 0),
            'pending' => (int)($entries[$category]['awaiting_verification'] ?? 0),
            'affected' => (int)($entries[$category]['affected_applications'] ?? 0),
        ];
    }
    return $coverage;
}

function loadCycleAnalyticsScoreBreakdown(PDO $pdo, int $cycleId): array
{
    require_once __DIR__ . '/scoring/orchestrator.php';

    $kraKeys = [
        'Instruction' => 'kra1',
        'Research' => 'kra2',
        'Extension' => 'kra3',
        'Professional Development' => 'kra4',
    ];
    $profiles = [];
    $criteria = [];
    $criteriaByPosition = [];
    $completed = 0;
    $matched = 0;

    // Completed applications do not store their KRA or criterion subtotals.
    $pdo->exec('SET TRANSACTION READ ONLY');
    $pdo->beginTransaction();
    try {
        $query = $pdo->prepare("SELECT a.application_id, a.user_id, a.total_score,
                COALESCE(NULLIF(a.position_title, ''), u.`rank`) AS position, c.start_date, c.end_date
            FROM applications a
            JOIN users u ON u.user_id = a.user_id
            JOIN cycles c ON c.cycle_id = a.cycle_id
            WHERE a.cycle_id = ? AND a.status IN ('approved', 'reclassified')");
        $query->execute([$cycleId]);
        $applications = $query->fetchAll(PDO::FETCH_ASSOC);
        $completed = count($applications);

        foreach ($applications as $application) {
            try {
                $result = \Scoring\Orchestrator::run($pdo, (int)$application['application_id']);
                $kras = $result['kra_results'] ?? [];
                if (array_diff_key(array_flip($kraKeys), $kras)
                    || $application['total_score'] === null
                    || round(array_sum(array_column($kras, 'subtotal')), 2) !== (float)$application['total_score']) {
                    continue;
                }

                $position = $application['position'] ?: null;
                if (!isset($criteriaByPosition[$position ?? ''])) {
                    $activeParents = array_column(CriteriaSnapshots::rows($pdo, $cycleId, $position), 'parent_id', 'parent_id');
                    $criteriaByPosition[$position ?? ''] = array_filter(
                        CriteriaSnapshots::parents($pdo, $cycleId, $position),
                        static fn(array $parent): bool => isset($activeParents[$parent['criteria_id']]) && (float)$parent['max_points'] > 0
                    );
                }
                $newlyAppointed = \Scoring\KRA4Scorer::isNewlyAppointed(
                    $pdo, (int)$application['user_id'], $application['start_date'], $application['end_date']
                );
            } catch (Throwable $error) {
                error_log('SUCFRMS Analytics score breakdown for application ' . $application['application_id'] . ': ' . $error->getMessage());
                continue;
            }

            $matched++;
            $profile = [];
            foreach ($kraKeys as $category => $key) {
                $kra = $kras[$key];
                if ($kra['cap'] > 0) {
                    $profile[$category] = 100 * $kra['subtotal'] / $kra['cap'];
                }
            }
            if ($profile) $profiles[] = $profile;

            foreach ($criteriaByPosition[$position ?? ''] as $parent) {
                $category = $parent['kra_category'];
                $code = criteriaParentCode($parent);
                if ($category === 'Professional Development' && $code === 'D' && !$newlyAppointed) {
                    continue;
                }
                $scores = $kras[$kraKeys[$category]]['parent_scores'];
                $points = $scores[$code] ?? 0;
                $criterion = $parent['criterion_key'];
                $criteria[$category][$criterion] ??= ['label' => $parent['criterion_label'], 'earned' => 0, 'zero' => 0];
                $bucket = round($points, 2) > 0 ? 'earned' : 'zero';
                $criteria[$category][$criterion][$bucket]++;
            }
        }
    } finally {
        $pdo->rollBack();
    }

    $categories = [];
    foreach ($kraKeys as $category => $key) {
        $values = array_column($profiles, $category);
        $rows = $criteria[$category] ?? [];
        foreach ($rows as &$row) {
            $row['rate'] = 100 * $row['earned'] / ($row['earned'] + $row['zero']);
        }
        unset($row);
        usort($rows, static fn(array $a, array $b): int =>
            $b['rate'] <=> $a['rate'] ?: strcasecmp($a['label'], $b['label']));
        $categories[$category] = ['median' => cycleAnalyticsMedian($values), 'count' => count($values), 'criteria' => $rows];
    }
    return ['completed' => $completed, 'matched' => $matched, 'categories' => $categories, 'profiles' => $profiles];
}
