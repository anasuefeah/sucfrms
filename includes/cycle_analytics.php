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
