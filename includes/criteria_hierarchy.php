<?php

function criteriaHierarchyIsReady(PDO $pdo): bool
{
    $constraint = $pdo->prepare("SELECT 1 FROM information_schema.REFERENTIAL_CONSTRAINTS
        WHERE CONSTRAINT_SCHEMA=DATABASE() AND TABLE_NAME=? AND CONSTRAINT_NAME=?");
    $constraint->execute(['scoring_criteria', 'fk_scoring_criteria_parent']);
    return (bool)$constraint->fetchColumn();
}

/** Apply after the unchanged criteria seed. Safe to repeat after an interrupted setup. */
function migrateCriteriaHierarchy(PDO $pdo): void
{
    $lock = $pdo->query("SELECT GET_LOCK(CONCAT('criteria_hierarchy:', LEFT(DATABASE(), 40)), 15)");
    if ((int)$lock->fetchColumn() !== 1) throw new RuntimeException('Could not lock the criteria migration.');
    try {
        if (criteriaHierarchyIsReady($pdo)) return;

        $columns = $pdo->query('SHOW COLUMNS FROM scoring_criteria')->fetchAll(PDO::FETCH_COLUMN);
        foreach (['parent_id' => 'INT NULL', 'is_parent' => 'TINYINT(1) NOT NULL DEFAULT 0', 'sort_order' => 'INT NOT NULL DEFAULT 0'] as $name => $definition) {
            if (!in_array($name, $columns, true)) $pdo->exec("ALTER TABLE scoring_criteria ADD COLUMN $name $definition");
        }
        $pdo->beginTransaction();
        try {
            $pdo->query('SELECT scope_id FROM criteria_scope_state WHERE scope_id=0 FOR UPDATE')->fetch();
            $rows = $pdo->query('SELECT * FROM scoring_criteria ORDER BY criteria_id')->fetchAll(PDO::FETCH_ASSOC);
            $parents = array_column(array_filter($rows, static fn($row) => $row['is_parent']), 'criteria_id', 'criteria_id');
            $byScope = [];
            foreach ($rows as $row) {
                if (isset($parents[$row['criteria_id']])) {
                    $byScope[$row['cycle_id'] ?? 0][$row['position_rank'] ?? ''][$row['criterion_key']] = $row['criteria_id'];
                }
            }
            // Preserve the scoring allowances used before the hierarchy was stored.
            $allowances = [
                'Instruction' => ['A'=>60, 'B'=>30, 'C'=>10],
                'Research' => ['A'=>100, 'B'=>100, 'C'=>100],
                'Extension' => ['A'=>30, 'B'=>50, 'C'=>20, 'D'=>20],
                'Professional Development' => ['A'=>20, 'B'=>60, 'C'=>20, 'D'=>20],
            ];
            $insert = $pdo->prepare('INSERT INTO scoring_criteria (cycle_id,position_rank,kra_category,criterion_key,criterion_label,max_points,is_parent,sort_order) VALUES (?,?,?,?,?,?,1,?)');
            $link = $pdo->prepare('UPDATE scoring_criteria SET parent_id=?, updated_at=updated_at WHERE criteria_id=?');
            foreach ($rows as $row) {
                if ($row['is_parent'] || $row['parent_id'] !== null) continue;
                $category = $row['kra_category'];
                if (!preg_match('/^Criterion\s+([A-Z])\b/i', $row['criterion_label'], $match)
                    && !preg_match('/^kra\d_([a-z])_/i', $row['criterion_key'], $match)) continue;
                $code = strtoupper($match[1]);
                $key = criteriaParentKey($category, $code);
                $scope = &$byScope[$row['cycle_id'] ?? 0][$row['position_rank'] ?? ''];
                if (!isset($scope[$key])) {
                    $title = kraCriterionGroupTitles($category)[$code] ?? "Criterion $code";
                    $insert->execute([$row['cycle_id'], $row['position_rank'], $category, $key, $title, $allowances[$category][$code] ?? 100, ord($code) - ord('A')]);
                    $scope[$key] = (int)$pdo->lastInsertId();
                }
                $link->execute([$scope[$key], $row['criteria_id']]);
                unset($scope);
            }
            $pdo->commit();
        } catch (Throwable $error) {
            $pdo->rollBack();
            throw $error;
        }
        $pdo->exec('ALTER TABLE scoring_criteria ADD CONSTRAINT fk_scoring_criteria_parent
            FOREIGN KEY (parent_id) REFERENCES scoring_criteria(criteria_id)');
    } finally {
        $pdo->query("SELECT RELEASE_LOCK(CONCAT('criteria_hierarchy:', LEFT(DATABASE(), 40)))");
    }
}

function criteriaParentKey(string $category, string $code): string
{
    return '@parent:' . $category . ':' . $code;
}

function criteriaParentCode(array $parent): string
{
    return substr($parent['criterion_key'], strrpos($parent['criterion_key'], ':') + 1);
}

/** Category ceilings are scoring policy, independent of the number of criteria. */
function kraMaximumPoints(): array
{
    require_once __DIR__ . '/scoring/entry_scores.php';
    return [
        'Instruction' => \Scoring\KRA1Scorer::CAP,
        'Research' => \Scoring\KRA2Scorer::CAP,
        'Extension' => \Scoring\KRA3Scorer::CAP,
        'Professional Development' => \Scoring\KRA4Scorer::CAP,
    ];
}

function configuredParentLimits(?PDO $pdo, ?int $cycle, ?string $position, string $category): array
{
    if (!$pdo) return [];
    require_once __DIR__ . '/criteria_snapshots.php';
    $limits = [];
    foreach (CriteriaSnapshots::parents($pdo, $cycle ?? 0, $position) as $parent) {
        if ($parent['kra_category'] === $category) $limits[criteriaParentCode($parent)] = (float)$parent['max_points'];
    }
    return $limits;
}

function criteriaGroups(array $rows): array
{
    $groups = [];
    foreach ($rows as $row) {
        $code = $row['parent_code'] ?? '';
        $groups[$code] ??= [
            'code' => $code,
            'name' => $row['parent_name'] ?? 'Additional Criteria',
            'max' => $row['parent_max'] ?? null,
            'sort_order' => $row['parent_sort_order'] ?? PHP_INT_MAX,
            'children' => [],
        ];
        $groups[$code]['children'][] = $row;
    }
    uasort($groups, static fn(array $a, array $b): int => $a['sort_order'] <=> $b['sort_order']);
    foreach ($groups as &$group) {
        usort($group['children'], static fn(array $a, array $b): int => [$a['sort_order'] ?? 0, $a['criteria_id']] <=> [$b['sort_order'] ?? 0, $b['criteria_id']]);
    }
    unset($group);
    return $groups;
}

function configuredCustomCriterionRemarks(PDO $pdo, int $cycleId, ?string $position, string $category, string $key, string $details = ''): ?string
{
    if (!kraIsAdditionalCriterionKey($key)) return null;
    $criteria = array_column(CriteriaSnapshots::rows($pdo, $cycleId, $position, $category), null, 'criterion_key');
    return isset($criteria[$key]) ? kraAdditionalCriterionRemarks($criteria[$key], $details) : null;
}

/** Resolve the saved key first, then use the existing read path for older evidence. */
function criteriaEntryBuckets(string $category, array $rows, array $entries): array
{
    $configured = array_column($rows, null, 'criterion_key');
    $legacyKeys = [];
    foreach ($rows as $row) {
        $numbering = kraNumberingEntryByKey($row['criterion_key']);
        if ($numbering !== null) $legacyKeys[serialize($numbering)] ??= $row['criterion_key'];
    }
    $buckets = [];
    foreach ($entries as $entry) {
        $parts = explode('|||', $entry['remarks'] ?? '');
        $key = \Scoring\EntryMetadata::criterionKey($parts);
        if ($key === '') {
            $numbering = kraNumberingFindByStoredType($category, $parts[0], $parts);
            $key = $legacyKeys[serialize($numbering)] ?? '';
        }
        $buckets[isset($configured[$key]) ? $key : ''][] = $entry;
    }
    return $buckets;
}

/** Report averages retain their per-entry basis and resolve live custom points by scope. */
function criteriaReportStats(PDO $pdo, ?int $cycleId = null): array
{
    $query = $pdo->prepare("SELECT s.*, a.status AS application_status, a.cycle_id,
            COALESCE(NULLIF(a.position_title,''),u.rank) AS position_rank, c.status AS cycle_status
        FROM kra_submissions s
        JOIN applications a ON a.application_id=s.application_id
        JOIN users u ON u.user_id=a.user_id
        LEFT JOIN cycles c ON c.cycle_id=a.cycle_id
        WHERE (? IS NULL OR a.cycle_id=?)");
    $query->execute([$cycleId ?: null, $cycleId ?: null]);
    $criteria = [];
    $stats = [];
    while ($entry = $query->fetch(PDO::FETCH_ASSOC)) {
        $category = $entry['kra_category'];
        if ($entry['cycle_status'] === 'open' && !in_array($entry['application_status'], ['approved','reclassified','admin_rejected'], true)) {
            $parts = explode('|||', $entry['remarks'] ?? '');
            if ($parts[0] === 'CUSTOM') {
                $scope = (int)$entry['cycle_id'];
                $position = $entry['position_rank'] ?? '';
                $criteria[$scope][$position] ??= CriteriaSnapshots::rows($pdo, $scope, $entry['position_rank']);
                $matching = array_filter($criteria[$scope][$position], static fn(array $row): bool => $row['kra_category'] === $category);
                $entry = kraCurrentCriterionEntries([$entry], $matching)[0] ?? null;
                if ($entry === null) continue;
            }
        }
        $stats[$category] ??= ['kra_category'=>$category, 'submissions'=>0, 'scores'=>0, 'min_pts'=>null, 'max_pts'=>null];
        $stats[$category]['submissions']++;
        if ($entry['computed_points'] !== null) {
            $points = (float)$entry['computed_points'];
            $row = &$stats[$category];
            $row['scores']++;
            $row['sum'] = ($row['sum'] ?? 0) + $points;
            $row['min_pts'] = min($points, $row['min_pts'] ?? $points);
            $row['max_pts'] = max($points, $row['max_pts'] ?? $points);
            unset($row);
        }
    }
    foreach ($stats as &$row) {
        $row['avg_pts'] = $row['scores'] ? round($row['sum'] / $row['scores'], 2) : null;
    }
    unset($row);
    return array_values($stats);
}

/** Sum additional parent groups using the allowance resolved from their catalog row. */
function additionalParentTotals(array $groups): array
{
    return array_map(static fn(array $group): float => min($group['max'], $group['points']), $groups);
}

function collectAdditionalParentScore(array &$groups, array $parts, float $points): bool
{
    if (!isset($parts[6]) || !str_starts_with($parts[6], 'PARENT_MAX=')) return false;
    $code = $parts[1];
    $groups[$code] ??= ['max' => (float)substr($parts[6], 11), 'points' => 0.0];
    $groups[$code]['points'] += $points;
    return true;
}
