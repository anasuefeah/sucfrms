<?php
namespace Scoring;

class EntryMetadata
{
    public static function reviewedPoints(array $entry, float $calculated): float
    {
        if (!empty($entry['verified']) && isset($entry['faculty_original_score'], $entry['computed_points'])
            && (float)$entry['computed_points'] !== (float)$entry['faculty_original_score']) {
            return max(0.0, (float)$entry['computed_points']);
        }
        return $calculated;
    }

    public static function criterionKey(array $parts): string
    {
        foreach (array_reverse($parts) as $part) {
            if (str_starts_with($part, 'CRITERION_KEY=')) return substr($part, 14);
        }
        return ($parts[0] ?? '') === 'CUSTOM' ? (string)($parts[2] ?? '') : '';
    }

    public static function extensionSubtype(array $parts): string
    {
        // Earlier forms reused another designation's subtype for these rows.
        // The saved category key disambiguates both new and existing entries.
        $designations = [
            'kra3_d_faculty_regent' => 'faculty regent',
            'kra3_d_univ_secretary' => 'university secretary',
            'kra3_d_college_secretary' => 'college secretary',
            'kra3_d_project_head_inst' => 'project head inst',
            'kra3_d_committee_chair_inst' => 'committee chair inst',
            'kra3_d_committee_member_inst' => 'committee member inst',
        ];
        if (($parts[0] ?? '') === 'CUSTOM') return 'custom';
        return $designations[self::criterionKey($parts)] ?? strtolower(trim($parts[0] ?? ''));
    }
}
