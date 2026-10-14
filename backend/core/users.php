<?php
// User display-name helpers.

/**
 * Format a user's display name as "First M. Last".
 * Pass a DB row array with first_name/middle_name/last_name keys.
 * Falls back to full_name if parts are empty.
 */
function formatDisplayName(array $row, string $fallback_key = 'full_name'): string {
    $first  = trim($row['first_name']  ?? '');
    $middle = trim($row['middle_name'] ?? '');
    $last   = trim($row['last_name']   ?? '');
    if ($first === '' && $last === '') {
        return $row[$fallback_key] ?? '';
    }
    $mi = $middle !== '' ? ' ' . strtoupper(substr($middle, 0, 1)) . '.' : '';
    return trim($first . $mi . ' ' . $last);
}

/**
 * The anonymous display name for an evaluator account.
 * Pass a DB row (or partial row) containing checker_label and user_id.
 * Falls back to "Evaluator #<user_id>" if a label wasn't stored (should
 * only happen for rows created before the checker_label migration ran).
 */
function checkerDisplayLabel(array $row): string {
    $label = $row['checker_label'] ?? null;
    if ($label === null) {
        return 'Evaluator #' . ($row['user_id'] ?? '?');
    }
    // Stored labels may still say "Checker #N" if the migration hasn't run yet  -  display as "Evaluator #N"
    return preg_replace('/^Checker\s*#/i', 'Evaluator #', $label);
}

/**
 * Next auto-incremented anonymous evaluator label ("Evaluator #1", "Evaluator #2", ...).
 * Based on the highest existing numeric suffix so deleted accounts don't
 * cause label reuse.
 */
function nextCheckerLabel($pdo): string {
    $stmt = $pdo->query("SELECT checker_label FROM users WHERE checker_label REGEXP '^(Checker|Evaluator) #[0-9]+$'");
    $max = 0;
    foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $lbl) {
        // Accept both legacy "Checker #N" and new "Evaluator #N" patterns
        if (preg_match('/#(\d+)$/', $lbl, $m)) {
            $n = (int)$m[1];
            if ($n > $max) $max = $n;
        }
    }
    return 'Evaluator #' . ($max + 1);
}
