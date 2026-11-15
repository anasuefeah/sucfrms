<?php
namespace Scoring;

require_once __DIR__ . '/kra1_scorer.php';
require_once __DIR__ . '/kra2_scorer.php';
require_once __DIR__ . '/kra3_scorer.php';
require_once __DIR__ . '/kra4_scorer.php';

/** The server-side calculation used by entry saves and draft score repairs. */
class EntryScores
{
    private const SCORERS = [
        'Instruction' => KRA1Scorer::class,
        'Research' => KRA2Scorer::class,
        'Extension' => KRA3Scorer::class,
        'Professional Development' => KRA4Scorer::class,
    ];

    public static function compute(\PDO $pdo, string $category, string $remarks, int $cycleId, ?string $position): float
    {
        $scorer = self::SCORERS[$category] ?? null;
        if ($scorer === null) return 0.0;
        $scorer::setPdo($pdo);
        $scorer::setCriteriaContext($cycleId, $position);
        return round(max(0.0, $scorer::computeFromRemarks($remarks)), 2);
    }

    /** Repair stale computed values without changing evaluator decisions or evidence. */
    public static function refreshDraft(\PDO $pdo, int $applicationId): int
    {
        $query = $pdo->prepare("SELECT a.cycle_id, COALESCE(NULLIF(a.position_title, ''), u.`rank`) position_rank
            FROM applications a JOIN users u ON u.user_id=a.user_id
            WHERE a.application_id=? AND a.status IN ('draft','rejected','needs_revision')");
        $query->execute([$applicationId]);
        $app = $query->fetch(\PDO::FETCH_ASSOC);
        if (!$app) return 0;

        $rows = $pdo->prepare("SELECT submission_id, kra_category, remarks, computed_points
            FROM kra_submissions WHERE application_id=? AND verified=0 AND verified_by IS NULL
            AND faculty_original_score IS NULL AND revision_by IS NULL");
        $rows->execute([$applicationId]);
        $update = $pdo->prepare("UPDATE kra_submissions SET computed_points=?
            WHERE submission_id=? AND application_id=? AND computed_points=?
            AND verified=0 AND verified_by IS NULL AND faculty_original_score IS NULL AND revision_by IS NULL");
        $changed = 0;
        foreach ($rows->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            if (trim((string)$row['remarks']) === '') continue;
            $points = self::compute($pdo, $row['kra_category'], $row['remarks'], (int)$app['cycle_id'], $app['position_rank']);
            if (abs($points - (float)$row['computed_points']) < 0.005) continue;
            $update->execute([$points, $row['submission_id'], $applicationId, $row['computed_points']]);
            $changed += $update->rowCount();
        }
        return $changed;
    }
}
