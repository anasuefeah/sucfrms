<?php

/** Match Instruction B/C entries by category and label, independently of point changes. */
function kraInstructionEntryMatchesFlat(array $parts, string $flatCriterion): bool
{
    $expected = explode('|', $flatCriterion, 3);
    if (count($expected) !== 3) return false;

    $type = trim((string)($parts[0] ?? ''));
    if (str_starts_with($type, 'B|') || str_starts_with($type, 'C|')) {
        $stored = explode('|', $type, 3);
        if (($stored[0] ?? '') !== $expected[0]) return false;
        $label = $stored[2] ?? '';
    } else {
        $legacyLetters = ['B-material' => 'B', 'C-thesis' => 'C', 'C-mentor' => 'C'];
        if (($legacyLetters[$type] ?? '') !== $expected[0]) return false;
        if ($type === 'C-mentor') {
            return $expected[2] === 'Mentor: Student/Team Competition Winner';
        }
        $label = (string)($parts[1] ?? '');
    }

    $normalize = static function (string $value): string {
        $value = preg_replace('/\s*\(\d+(?:\.\d+)?\s*pts?\)\s*$/i', '', trim($value));
        $value = str_replace(["\u{2018}", "\u{2019}"], "'", $value);
        return strtolower(preg_replace('/\s+/', ' ', $value));
    };

    return $normalize($label) === $normalize($expected[2]);
}

function kraEntryCriterionKey(array $parts): string
{
    foreach (array_reverse($parts) as $part) {
        if (str_starts_with($part, 'CRITERION_KEY=')) return substr($part, 14);
    }
    return ($parts[0] ?? '') === 'CUSTOM' ? (string)($parts[2] ?? '') : '';
}

function kraResearchLabel(string $label): string
{
    $label = preg_replace('/\(\s*\d+(?:\.\d+)?\s*pts?\.?\s*\)/i', '', $label);
    $label = preg_replace('/^Criterion\s+[A-Z]\s*[.\-:\x{2013}\x{2014}]*\s*/iu', '', trim($label));
    return strtolower(trim(preg_replace('/\s+/', ' ', $label)));
}

/** Legacy matching; new entries carry an explicit criterion key. */
function kraEntryMatchesCriterion(string $category, array $parts, array $criterion, array $flatMap, array $extMap, array $pdMap): bool
{
    $key = (string)($criterion['criterion_key'] ?? '');
    $storedKey = kraEntryCriterionKey($parts);
    if ($storedKey !== '') return $storedKey === $key;
    $type = trim((string)($parts[0] ?? ''));
    if ($category === 'Research') {
        return $type !== '' && kraResearchLabel($type) === kraResearchLabel($criterion['criterion_label']);
    }
    if ($category === 'Instruction') {
        return isset($flatMap[$key]) && kraInstructionEntryMatchesFlat($parts, $flatMap[$key]);
    }
    if ($category === 'Extension') {
        if (!isset($extMap[$key]) || $type !== $extMap[$key]) return false;
        if ($type !== 'income') return true;
        $income = max(0, (float)($parts[2] ?? 0));
        return match ($key) {
            'kra3_a_income_above12m' => $income > 12000000,
            'kra3_a_income_6to12m' => $income > 6000000 && $income <= 12000000,
            'kra3_a_income_below6m' => $income > 0 && $income <= 6000000,
            default => false,
        };
    }
    if ($category === 'Professional Development' && isset($pdMap[$key])) {
        return $type === $pdMap[$key]['type']
            && ($pdMap[$key]['sub'] === '' || (string)($parts[2] ?? '') === $pdMap[$key]['sub']);
    }
    return false;
}
