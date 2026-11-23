<?php

final class ClaimReview
{
    public static function ensureSchema(PDO $pdo): void
    {
        if ($pdo->query("SHOW COLUMNS FROM applications LIKE 'duplicate_reviews'")->fetchColumn()) return;
        try {
            $pdo->exec("ALTER TABLE applications ADD COLUMN duplicate_reviews LONGTEXT DEFAULT NULL");
        } catch (PDOException $error) {
            if ((int)($error->errorInfo[1] ?? 0) !== 1060) throw $error;
        }
    }

    public static function loadEntries(PDO $pdo, int $applicationId): array
    {
        $query = $pdo->prepare("SELECT s.*,
            GROUP_CONCAT(ef.original_filename ORDER BY ef.evidence_id SEPARATOR '|||') AS evidence_names,
            GROUP_CONCAT(ef.file_size_bytes ORDER BY ef.evidence_id SEPARATOR '|||') AS evidence_sizes,
            GROUP_CONCAT(ef.evidence_id ORDER BY ef.evidence_id) AS evidence_ids
            FROM kra_submissions s LEFT JOIN kra_evidence_files ef ON ef.submission_id=s.submission_id
            WHERE s.application_id=? GROUP BY s.submission_id ORDER BY s.submitted_at, s.submission_id");
        $query->execute([$applicationId]);
        return $query->fetchAll(PDO::FETCH_ASSOC);
    }

    public static function lockApplication(PDO $pdo, int $applicationId): array
    {
        $query = $pdo->prepare("SELECT a.*, c.status AS cycle_status
            FROM applications a JOIN cycles c ON c.cycle_id=a.cycle_id WHERE a.application_id=? FOR UPDATE");
        $query->execute([$applicationId]);
        $application = $query->fetch(PDO::FETCH_ASSOC);
        if (!$application || $application['cycle_status'] === 'archived'
            || !in_array($application['status'], ['submitted', 'under_review', 'needs_revision'], true)) {
            throw new DomainException('This application is no longer available for evaluation.');
        }
        $entries = $pdo->prepare('SELECT submission_id FROM kra_submissions WHERE application_id=? FOR UPDATE');
        $entries->execute([$applicationId]);
        $entries->fetchAll(PDO::FETCH_COLUMN);
        return $application;
    }

    private static function fingerprint(array $entry): string
    {
        return hash('sha256', serialize([
            (int)$entry['submission_id'], $entry['kra_category'], $entry['remarks'] ?? '',
            $entry['document_path'] ?? '', $entry['evidence_ids'] ?? '',
        ]));
    }

    private static function title(array $entry): string
    {
        $parts = explode('|||', (string)($entry['remarks'] ?? ''));
        $type = $parts[0];
        if ($type === 'CUSTOM') return trim($parts[5] ?? '');
        if ($entry['kra_category'] === 'Research') return trim($parts[4] ?? '');
        if ($entry['kra_category'] === 'Instruction'
            && (str_starts_with($type, 'A-set') || str_starts_with($type, 'A-sef') || preg_match('/^[BC]\|/', $type))) return '';
        return trim($parts[1] ?? '');
    }

    private static function candidates(array $entries): array
    {
        $groups = [];
        foreach ($entries as $entry) {
            $id = (int)$entry['submission_id'];
            $title = mb_strtolower(self::title($entry), 'UTF-8');
            $title = trim(preg_replace('/[^\p{L}\p{N}]+/u', ' ', $title) ?? '');
            if (mb_strlen($title, 'UTF-8') >= 5) $groups['title:' . $title][$id] = $entry;
            $names = explode('|||', (string)($entry['evidence_names'] ?? ''));
            $sizes = explode('|||', (string)($entry['evidence_sizes'] ?? ''));
            foreach ($names as $i => $name) {
                if (trim($name) !== '' && (int)($sizes[$i] ?? 0) > 0) {
                    $groups['file:' . mb_strtolower(trim($name), 'UTF-8') . ':' . $sizes[$i]][$id] = $entry;
                }
            }
        }
        $pairs = [];
        foreach ($groups as $group) {
            ksort($group);
            $members = array_values($group);
            foreach ($members as $i => $left) {
                for ($j = $i + 1; $j < count($members); $j++) {
                    $right = $members[$j];
                    $key = hash('sha256', self::fingerprint($left) . self::fingerprint($right));
                    $pairs[$key] = true;
                }
            }
        }
        return $pairs;
    }

    public static function assess(array $application, array $entries): array
    {
        $completed = in_array($application['status'] ?? '', ['approved', 'reclassified', 'admin_rejected'], true);
        $saved = $completed ? (json_decode($application['duplicate_reviews'] ?? '', true) ?: []) : [];
        $excluded = [];
        // Older installations may have exclusions recorded before the timeline was removed.
        // Preserve those decisions for completed evaluations when regenerating scores and reports.
        if ($completed && empty($saved['ignore_legacy_exclusions'])) {
            foreach ($entries as $entry) {
                $legacyReview = json_decode($entry['claim_review'] ?? '', true);
                if (($legacyReview['decision'] ?? '') === 'exclude' && ($legacyReview['fingerprint'] ?? '') === self::fingerprint($entry)) {
                    $excluded[(int)$entry['submission_id']] = true;
                }
            }
        }

        if ($completed && $saved && empty($saved['ignore_duplicate_exclusions'])) {
            // Match only the saved decisions that still refer to the original evidence.
            foreach (array_intersect_key($saved, self::candidates($entries)) as $decision) {
                $excludedId = (int)($decision['exclude'] ?? 0);
                if ($excludedId) $excluded[$excludedId] = true;
            }
        }
        return ['excluded' => $excluded, 'unresolved' => [], 'ready' => !empty($application['cycle_id'])];
    }

    public static function completionMetadata(array $application): string
    {
        $saved = json_decode($application['duplicate_reviews'] ?? '', true) ?: [];
        $saved['ignore_legacy_exclusions'] = true;
        $saved['ignore_duplicate_exclusions'] = true;
        return json_encode($saved, JSON_THROW_ON_ERROR);
    }

    public static function scoringEntries(array $application, array $entries, array $assessment): array
    {
        $held = $assessment['excluded'];
        return array_values(array_filter($entries, static fn(array $entry): bool => !isset($held[$entry['submission_id']])));
    }

    public static function requireReady(array $assessment, array $entries): void
    {
        if (!$assessment['ready']) throw new DomainException('The application must belong to a reclassification cycle before completing the evaluation.');
        foreach ($entries as $entry) {
            if (!isset($assessment['excluded'][$entry['submission_id']]) && empty($entry['verified'])) {
                throw new DomainException('Mark the evidence for each credited claim acceptable before completing the evaluation.');
            }
        }
    }

    public static function assignedEvaluatorReviews(PDO $pdo, int $applicationId, int $cycleId): array
    {
        $query = $pdo->prepare("SELECT DISTINCT u.user_id, u.checker_label, r.decision, r.decided_at
            FROM checker_kra_assignments ka
            JOIN users u ON u.user_id=ka.checker_id
            LEFT JOIN application_checker_reviews r ON r.application_id=? AND r.checker_id=u.user_id
            WHERE ka.cycle_id=? AND u.role='evaluator' AND u.status='active'
            ORDER BY u.user_id");
        $query->execute([$applicationId, $cycleId]);
        return array_column($query->fetchAll(PDO::FETCH_ASSOC), null, 'user_id');
    }

    // Runs inside the caller's transaction, including the completion audit and notification.
    public static function completeEvaluation(PDO $pdo, int $applicationId, int $evaluatorId, string $remarks): array
    {
        $application = self::lockApplication($pdo, $applicationId);
        if ($application['status'] === 'needs_revision') {
            throw new DomainException('Wait for the faculty member to resubmit the revised entries.');
        }

        $cycleId = (int)$application['cycle_id'];
        $reviews = self::assignedEvaluatorReviews($pdo, $applicationId, $cycleId);
        if (!isset($reviews[$evaluatorId])) {
            throw new DomainException('You do not have any KRA assignments for this cycle.');
        }
        if (in_array($reviews[$evaluatorId]['decision'], ['approved', 'rejected'], true)) {
            throw new DomainException('Your evaluation decision has already been recorded.');
        }

        $entries = self::loadEntries($pdo, $applicationId);
        $assessment = self::assess($application, $entries);
        $assignedKras = getCheckerAssignedKras($pdo, $evaluatorId, $cycleId);
        if (!$assignedKras) {
            throw new DomainException('You do not have any KRA assignments for this cycle.');
        }
        $query = $pdo->prepare("SELECT v.submission_id FROM kra_checker_verifications v
            JOIN kra_submissions s ON s.submission_id=v.submission_id
            WHERE s.application_id=? AND v.checker_id=?");
        $query->execute([$applicationId, $evaluatorId]);
        $verified = array_fill_keys($query->fetchAll(PDO::FETCH_COLUMN), true);
        foreach (self::scoringEntries($application, $entries, $assessment) as $entry) {
            if (!in_array($entry['kra_category'], $assignedKras, true)) continue;
            $accepted = isset($verified[$entry['submission_id']])
                || (!empty($entry['verified']) && (int)$entry['verified_by'] === $evaluatorId);
            if (!$accepted || $entry['revision_status'] === 'needs_revision') {
                throw new DomainException('Mark the evidence for each credited claim in your assigned KRAs acceptable before completing your evaluation.');
            }
        }

        $total = count($reviews);
        $approved = 1 + count(array_keys(array_column($reviews, 'decision'), 'approved', true));
        $complete = $approved === $total;
        if ($complete) {
            self::requireReady($assessment, $entries);
            require_once __DIR__ . '/scoring/orchestrator.php';
            $score = \Scoring\Orchestrator::run($pdo, $applicationId);
            if (!isset($score['weighted_score'])) throw new RuntimeException('The scoring engine did not return a score.');
            $weighted = (float)$score['weighted_score'];
            if ($weighted < 41) {
                throw new DomainException("The weighted score ({$weighted}) is below the minimum of 41. Return the application for revision.");
            }
        }

        $pdo->prepare("INSERT INTO application_checker_reviews
            (application_id, checker_id, decision, remarks, decided_at)
            VALUES (?, ?, 'approved', ?, NOW())
            ON DUPLICATE KEY UPDATE decision='approved', remarks=VALUES(remarks), decided_at=NOW()")
            ->execute([$applicationId, $evaluatorId, $remarks]);

        if ($application['status'] === 'submitted') {
            $pdo->prepare("UPDATE applications SET status='under_review', checker_id=? WHERE application_id=?")
                ->execute([$evaluatorId, $applicationId]);
        }
        if ($complete) {
            $autoSubrank = $score['auto_subrank'] ?? [];
            if (!empty($autoSubrank['qualified_auto_subrank_phd'])) {
                $pdo->prepare("UPDATE auto_sub_rank SET doctorate_mode = 'triggered', doctorate_points = 0
                    WHERE application_id = ? AND doctorate_verified = 'verified'")
                    ->execute([$applicationId]);
            }
            $pdo->prepare("UPDATE applications SET status='approved', reviewed_at=NOW(),
                checker_remarks=?, duplicate_reviews=? WHERE application_id=?")
                ->execute([
                    "Evaluation completed by all {$total} evaluator(s). Result is for further committee review and is not a final decision.",
                    self::completionMetadata($application),
                    $applicationId,
                ]);
        }

        return ['approved' => $approved, 'total' => $total, 'complete' => $complete];
    }
}
