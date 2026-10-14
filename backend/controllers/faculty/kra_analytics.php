<?php
// Request handling and data loading for frontend/views/faculty/kra_analytics.php
$analytics_kras = [
    'Instruction' => ['label' => 'KRA I', 'tab' => 'instruction', 'max' => 100.0],
    'Research' => ['label' => 'KRA II', 'tab' => 'research', 'max' => 100.0],
    'Extension' => ['label' => 'KRA III', 'tab' => 'extension', 'max' => 100.0],
    'Professional Development' => ['label' => 'KRA IV', 'tab' => 'profdev', 'max' => 100.0],
];

$analytics_score = getApplicationScoreSummary($pdo, (int)$app_id);
$analytics_points = [
    'Instruction' => (float)$analytics_score['kra1'],
    'Research' => (float)$analytics_score['kra2'],
    'Extension' => (float)$analytics_score['kra3'],
    'Professional Development' => (float)$analytics_score['kra4'],
];
$analytics_total = (float)$analytics_score['grand_total'];
$analytics_weighted = (float)$analytics_score['weighted_score'];
$analytics_sub_rank = (int)$analytics_score['sub_rank_increment'];

$analytics_result_keys = [
    'Instruction' => 'kra1',
    'Research' => 'kra2',
    'Extension' => 'kra3',
    'Professional Development' => 'kra4',
];
foreach ($analytics_result_keys as $kra_name => $result_key) {
    $cap = (float)($analytics_score['kra_results'][$result_key]['cap'] ?? $analytics_kras[$kra_name]['max']);
    $earned = (float)($analytics_points[$kra_name] ?? 0);
    $analytics_kras[$kra_name]['max'] = max($cap, $earned, 1.0);
}

$analytics_rows_stmt = $pdo->prepare("
    SELECT ks.submission_id,
           ks.application_id,
           ks.user_id,
           ks.kra_category,
           ks.computed_points,
           ks.document_path,
           ks.remarks,
           ks.submitted_at,
           COUNT(kef.evidence_id) AS evidence_count,
           GROUP_CONCAT(kef.original_filename ORDER BY kef.evidence_id SEPARATOR ', ') AS evidence_names
    FROM kra_submissions ks
    LEFT JOIN kra_evidence_files kef ON kef.submission_id = ks.submission_id
    WHERE ks.application_id = ?
    GROUP BY ks.submission_id
    ORDER BY ks.kra_category, ks.submitted_at ASC
");
$analytics_rows_stmt->execute([$app_id]);
$analytics_entries = $analytics_rows_stmt->fetchAll(PDO::FETCH_ASSOC);

$analytics_by_kra = [];
foreach (array_keys($analytics_kras) as $kra_name) {
    $analytics_by_kra[$kra_name] = [];
}
foreach ($analytics_entries as $entry) {
    if (isset($analytics_by_kra[$entry['kra_category']])) {
        $analytics_by_kra[$entry['kra_category']][] = $entry;
    }
}

$analytics_load_criteria = function (string $kra_name) use ($pdo, $cycle_id_for_criteria, $target_position): array {
    $rows_by_key = [];
    $order = [];
    $merge = function (array $rows) use (&$rows_by_key, &$order): void {
        foreach ($rows as $row) {
            $key = trim((string)($row['criterion_key'] ?? ''));
            if ($key === '') $key = 'label:' . trim((string)($row['criterion_label'] ?? ''));
            if (!isset($rows_by_key[$key])) $order[] = $key;
            $rows_by_key[$key] = $row;
        }
    };

    $stmt = $pdo->prepare("SELECT * FROM scoring_criteria WHERE cycle_id IS NULL AND position_rank IS NULL AND kra_category=? AND is_active=1 ORDER BY criteria_id");
    $stmt->execute([$kra_name]);
    $merge($stmt->fetchAll(PDO::FETCH_ASSOC));

    if ($cycle_id_for_criteria) {
        $stmt = $pdo->prepare("SELECT * FROM scoring_criteria WHERE cycle_id=? AND position_rank IS NULL AND kra_category=? AND is_active=1 ORDER BY criteria_id");
        $stmt->execute([$cycle_id_for_criteria, $kra_name]);
        $merge($stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    if ($cycle_id_for_criteria && $target_position) {
        $stmt = $pdo->prepare("SELECT * FROM scoring_criteria WHERE cycle_id=? AND position_rank=? AND kra_category=? AND is_active=1 ORDER BY criteria_id");
        $stmt->execute([$cycle_id_for_criteria, $target_position, $kra_name]);
        $merge($stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    return array_values(array_filter(array_map(fn($key) => $rows_by_key[$key] ?? null, $order)));
};

$analytics_criteria = [];
$analytics_configured_count = 0;
$analytics_pending_confirmation = [];
foreach (array_keys($analytics_kras) as $kra_name) {
    $analytics_criteria[$kra_name] = $analytics_load_criteria($kra_name);
    foreach ($analytics_criteria[$kra_name] as $criterion) {
        if ((float)($criterion['max_points'] ?? 0) > 0) {
            $analytics_configured_count++;
        } else {
            $analytics_pending_confirmation[] = [
                'kra' => $kra_name,
                'label' => kraCleanEntryDisplayTitle((string)($criterion['criterion_label'] ?? 'Configured criterion')),
            ];
        }
    }
}

$analytics_entry_title = static function (array $entry): string {
    $parts = array_map('trim', explode('|||', (string)($entry['remarks'] ?? '')));
    $cat = $entry['kra_category'] ?? '';
    $title = '';
    if (($parts[0] ?? '') === 'CUSTOM') {
        $title = $parts[5] ?? ($parts[3] ?? '');
    } elseif ($cat === 'Research') {
        $title = $parts[4] ?? ($parts[1] ?? '');
    } elseif ($cat === 'Extension') {
        $title = $parts[1] ?? '';
    } elseif ($cat === 'Professional Development') {
        $title = $parts[1] ?? '';
    } else {
        $title = $parts[1] ?? ($parts[2] ?? '');
    }
    $title = trim((string)$title);
    return $title !== '' ? $title : 'Saved entry #' . (int)($entry['submission_id'] ?? 0);
};

$analytics_missing_fields = static function (array $entry): array {
    $parts = array_map('trim', explode('|||', (string)($entry['remarks'] ?? '')));
    $cat = $entry['kra_category'] ?? '';
    $missing = [];
    if (($parts[0] ?? '') === '') $missing[] = 'level or criterion';
    if ($cat === 'Research') {
        if (($parts[1] ?? '') === '') $missing[] = 'title or author';
        if (($parts[4] ?? '') === '') $missing[] = 'role or contribution';
        if (($parts[5] ?? '') === '') $missing[] = 'role percentage';
    } elseif ($cat === 'Extension') {
        if (($parts[1] ?? '') === '') $missing[] = 'title';
    } elseif ($cat === 'Professional Development') {
        if (($parts[1] ?? '') === '') $missing[] = 'title';
        if (in_array(($parts[0] ?? ''), ['B-training', 'B-paper', 'B-degree', 'C-award'], true) && ($parts[2] ?? '') === '') {
            $missing[] = 'level';
        }
    } elseif (($parts[0] ?? '') === 'CUSTOM') {
        if (($parts[5] ?? '') === '') $missing[] = 'title';
    } else {
        if (($parts[1] ?? '') === '' && ($parts[2] ?? '') === '') $missing[] = 'title or role';
    }
    if ((int)($entry['evidence_count'] ?? 0) === 0 && empty($entry['document_path'])) $missing[] = 'file';
    return array_values(array_unique($missing));
};

$completed_saved_entries = 0;
$entries_with_files_total = 0;
foreach ($analytics_entries as $entry) {
    if (trim((string)($entry['remarks'] ?? '')) !== '' && empty($analytics_missing_fields($entry))) $completed_saved_entries++;
    if ((int)($entry['evidence_count'] ?? 0) > 0 || !empty($entry['document_path'])) $entries_with_files_total++;
}
$analytics_progress_denom = max(count($analytics_entries), 1);
$analytics_progress_pct = min(100, (int)round(($completed_saved_entries / $analytics_progress_denom) * 100));

$analytics_deadline_label = 'No deadline set';
$analytics_days_left = null;
if (!empty($_effective_deadline)) {
    $analytics_deadline_ts = strtotime($_effective_deadline . ' 23:59:59');
    $analytics_days_left = (int)ceil(($analytics_deadline_ts - time()) / 86400);
    $analytics_deadline_label = $analytics_days_left < 0 ? 'Deadline passed' : $analytics_days_left . ' day(s) left';
}

$next_threshold = null;
foreach ([41, 51, 61, 71, 81, 91] as $threshold) {
    if ($analytics_weighted < $threshold) {
        $next_threshold = $threshold;
        break;
    }
}
$points_needed = $next_threshold === null ? 0.0 : max(0.0, $next_threshold - $analytics_weighted);

$most_room_kra = 'Instruction';
$most_room_points = -1.0;
foreach ($analytics_kras as $kra_name => $meta) {
    $room = max(0.0, (float)$meta['max'] - ($analytics_points[$kra_name] ?? 0));
    if ($room > $most_room_points) {
        $most_room_points = $room;
        $most_room_kra = $kra_name;
    }
}

$analytics_actions = [];
foreach ($analytics_entries as $entry) {
    $missing = $analytics_missing_fields($entry);
    $has_file_problem = in_array('file', $missing, true);
    if ($has_file_problem) {
        $analytics_actions[] = [
            'score' => kraApplyEffectiveComputedPoints($entry),
            'kra' => $entry['kra_category'],
            'sid' => (int)$entry['submission_id'],
            'criterion_key' => '',
            'text' => 'Upload evidence for ' . $analytics_entry_title($entry) . '. It is listed but has no file, so it may not be accepted.',
        ];
    } elseif (!empty($missing)) {
        $analytics_actions[] = [
            'score' => max(0.1, kraApplyEffectiveComputedPoints($entry)),
            'kra' => $entry['kra_category'],
            'sid' => (int)$entry['submission_id'],
            'criterion_key' => '',
            'text' => 'Complete ' . implode(', ', $missing) . ' for ' . $analytics_entry_title($entry) . '.',
        ];
    }
}
foreach ($analytics_criteria as $kra_name => $criteria_rows) {
    $earned = (float)($analytics_points[$kra_name] ?? 0);
    $room = max(0.0, (float)$analytics_kras[$kra_name]['max'] - $earned);
    if ($room <= 0) continue;
    $best_configured_points = 0.0;
    $best_configured_label = '';
    foreach ($criteria_rows as $criterion) {
        $max = (float)($criterion['max_points'] ?? 0);
        if ($max <= 0) continue;
        if ($max > $best_configured_points) {
            $best_configured_points = $max;
            $best_configured_label = kraCleanEntryDisplayTitle((string)($criterion['criterion_label'] ?? 'this criterion'));
        }
    }
    if ($best_configured_points > 0) {
        $action_points = min($room, $best_configured_points);
        $analytics_actions[] = [
            'score' => $action_points,
            'kra' => $kra_name,
            'sid' => 0,
            'criterion_key' => '',
            'text' => 'Add a complete entry for ' . $best_configured_label . '. This KRA has ' . number_format($room, 2) . ' unused point(s), and this configured item can count up to ' . number_format($best_configured_points, 2) . ' point(s).',
        ];
    }
}
usort($analytics_actions, fn($a, $b) => ($b['score'] <=> $a['score']));
$analytics_actions = array_slice($analytics_actions, 0, 5);

$analytics_problem_rows = [];
$add_problem = static function (array &$rows, string $status, string $title, string $why, string $fix): void {
    $rows[] = ['status' => $status, 'title' => $title, 'why' => $why, 'fix' => $fix];
};
if (isset($app['evaluation_period_ok']) && !(bool)$app['evaluation_period_ok']) {
    $add_problem($analytics_problem_rows, 'Blocking issue', 'Evaluation period issue found.', 'The application is linked to a cycle that is no longer valid for evaluation.', 'Ask the administrator to check the active cycle.');
}
$double_flags = json_decode($app['double_counting_flags'] ?? '[]', true) ?: [];
foreach ($double_flags as $flag) {
    $add_problem($analytics_problem_rows, 'Needs attention', 'Possible double counting: ' . ($flag['title'] ?? 'saved evidence'), 'The same work may have been listed in more than one place.', 'Open the linked entries and keep the work only where it belongs.');
}
foreach (($analytics_score['pending_documentation'] ?? []) as $item) {
    $add_problem($analytics_problem_rows, 'Needs attention', 'Pending documentation.', (string)$item, 'Upload the missing file or complete the entry details.');
}
foreach (($analytics_score['config_incomplete'] ?? []) as $item) {
    $add_problem($analytics_problem_rows, 'Blocking issue', 'Scoring configuration is incomplete.', (string)$item, 'Contact the administrator. This item cannot be counted until the configuration is confirmed.');
}

$analytics_previous_stmt = $pdo->prepare("
    SELECT a.application_id, c.cycle_name,
           COALESCE(a.weighted_score, a.total_score, 0) AS saved_score,
           SUM(CASE WHEN ks.kra_category='Instruction' THEN ks.computed_points ELSE 0 END) AS instruction_points,
           SUM(CASE WHEN ks.kra_category='Research' THEN ks.computed_points ELSE 0 END) AS research_points,
           SUM(CASE WHEN ks.kra_category='Extension' THEN ks.computed_points ELSE 0 END) AS extension_points,
           SUM(CASE WHEN ks.kra_category='Professional Development' THEN ks.computed_points ELSE 0 END) AS profdev_points
    FROM applications a
    LEFT JOIN cycles c ON c.cycle_id = a.cycle_id
    LEFT JOIN kra_submissions ks ON ks.application_id = a.application_id
    WHERE a.user_id = ?
    GROUP BY a.application_id, c.cycle_name, a.weighted_score, a.total_score, a.created_at
    ORDER BY a.created_at ASC, a.application_id ASC
");
$analytics_previous_stmt->execute([$uid]);
$analytics_cycles = $analytics_previous_stmt->fetchAll(PDO::FETCH_ASSOC);
$has_prior_cycles = count($analytics_cycles) > 1;
