<?php

class KraRevisionWorkflow
{
    public static function wasFlagged(array $entry): bool
    {
        return ($entry['revision_status'] ?? '') === 'needs_revision'
            || !empty($entry['revision_at']) || !empty($entry['revision_by'])
            || trim((string)($entry['revision_note'] ?? '')) !== '';
    }

    public static function clearFlag(PDO $pdo, int $applicationId, int $submissionId): void
    {
        self::ensureSchema($pdo);
        // Clearing the active request must retain who flagged it, when, and why.
        $pdo->prepare("UPDATE kra_submissions SET revision_status='ok', revision_saved_at=NULL
            WHERE application_id=? AND submission_id=?")->execute([$applicationId, $submissionId]);
    }

    public static function ensureSchema(PDO $pdo): void
    {
        static $ready = [];
        $id = spl_object_id($pdo);
        if (isset($ready[$id])) return;
        try {
            $pdo->query('SELECT revision_saved_at FROM kra_submissions LIMIT 1');
        } catch (PDOException $e) {
            if ($e->getCode() !== '42S22') throw $e;
            $pdo->exec('ALTER TABLE kra_submissions ADD COLUMN revision_saved_at DATETIME(6) DEFAULT NULL');
        }
        $ready[$id] = true;
    }

    public static function canEdit(string $status, ?array $entry): bool
    {
        if (in_array($status, ['approved', 'reclassified', 'admin_rejected'], true)) return false;
        if ($status === 'rejected') return true;
        if ($status === 'needs_revision') {
            return $entry !== null && ($entry['revision_status'] ?? '') === 'needs_revision';
        }
        return $entry === null || empty($entry['verified']);
    }

    public static function requireEditable(PDO $pdo, int $applicationId, int $submissionId, ?string $category = null): ?array
    {
        $app = $pdo->prepare('SELECT status FROM applications WHERE application_id=?');
        $app->execute([$applicationId]);
        $status = $app->fetchColumn();
        $entry = null;
        if ($submissionId > 0) {
            $query = $pdo->prepare('SELECT * FROM kra_submissions WHERE submission_id=? AND application_id=?');
            $query->execute([$submissionId, $applicationId]);
            $entry = $query->fetch(PDO::FETCH_ASSOC) ?: null;
            if (!$entry || ($category !== null && $entry['kra_category'] !== $category)) {
                throw new RuntimeException('This entry does not belong to this application and KRA.');
            }
        }
        if ($status === false || !self::canEdit((string)$status, $entry)) {
            throw new RuntimeException('Only the specific entries flagged for revision can be edited. Other entries remain locked.');
        }
        return $entry;
    }

    public static function markSaved(PDO $pdo, int $applicationId, int $submissionId): void
    {
        self::ensureSchema($pdo);
        $pdo->prepare("DELETE v FROM kra_checker_verifications v
            JOIN kra_submissions s ON s.submission_id=v.submission_id
            WHERE s.application_id=? AND s.submission_id=?")->execute([$applicationId, $submissionId]);
        $pdo->prepare("UPDATE kra_submissions SET verified=0, verified_by=NULL, verified_at=NULL
            WHERE application_id=? AND submission_id=?")->execute([$applicationId, $submissionId]);
        $pdo->prepare("UPDATE kra_submissions SET revision_saved_at=NOW(6)
            WHERE application_id=? AND submission_id=? AND revision_status='needs_revision'")
            ->execute([$applicationId, $submissionId]);
    }

    private static function unsavedEntryLabel(array $entry): string
    {
        $parts = array_map('trim', explode('|||', $entry['remarks'] ?? ''));
        $category = $entry['kra_category'];
        $type = $parts[0] ?? '';
        $description = $parts[1] ?? '';

        if ($type === 'CUSTOM') {
            $title = ($parts[3] ?? '') ?: 'Custom criterion';
            $detail = $parts[5] ?? '';
        } elseif ($category === 'Instruction' && in_array($type, ['A-set-sem', 'A-sef-sem', 'A-set-sef-sem', 'A-set-sef'], true)) {
            $title = match ($type) {
                'A-set-sem' => 'Student Evaluation of Teaching (SET)',
                'A-sef-sem' => 'Supervisor Evaluation Form (SEF)',
                default => 'Teaching Evaluation (SET and SEF)',
            };
            $semester = match ($parts[2] ?? '') {
                '1' => '1st semester',
                '2' => '2nd semester',
                default => '',
            };
            $detail = $type === 'A-set-sef' ? '' : implode(', ', array_filter([$description, $semester]));
        } else {
            [$title, $detail] = match ($category) {
                'Research' => [kraCleanEntryDisplayTitle($type), ($parts[4] ?? '') ?: $description],
                'Extension' => [kraExtensionSubtypeDisplayTitle($type), $description],
                'Professional Development' => [match ($type) {
                    'D-prior-academic' => 'Academic Service',
                    'D-prior-industry' => 'Industry Experience',
                    default => kraProfessionalDevelopmentDisplayTitle($type, $parts[2] ?? ''),
                }, $description],
                default => [kraInstructionDisplayTitle($type, $description) ?: kraCleanEntryDisplayTitle($type), ''],
            };
        }

        $name = implode(' — ', array_filter([$title, $detail !== $title ? $detail : '']));
        $name = $name !== '' ? mb_strimwidth(preg_replace('/\s+/', ' ', $name), 0, 120, '…', 'UTF-8')
            : 'entry #' . (int)$entry['submission_id'];
        return $category . ' — ' . $name;
    }

    public static function resubmit(PDO $pdo, int $applicationId): int
    {
        self::ensureSchema($pdo);
        $ownsTransaction = !$pdo->inTransaction();
        if ($ownsTransaction) $pdo->beginTransaction();
        try {
            $app = $pdo->prepare('SELECT status, cycle_id, user_id FROM applications WHERE application_id=? FOR UPDATE');
            $app->execute([$applicationId]);
            $application = $app->fetch(PDO::FETCH_ASSOC);
            if (!$application || $application['status'] !== 'needs_revision') throw new RuntimeException('This application is not awaiting revision.');
            $query = $pdo->prepare("SELECT submission_id, kra_category, remarks, revision_saved_at FROM kra_submissions
                WHERE application_id=? AND revision_status='needs_revision' FOR UPDATE");
            $query->execute([$applicationId]);
            $flagged = $query->fetchAll(PDO::FETCH_ASSOC);
            if (!$flagged) throw new RuntimeException('There are no flagged entries to resubmit.');
            $unsaved = [];
            foreach ($flagged as $entry) {
                if (empty($entry['revision_saved_at'])) {
                    $unsaved[] = self::unsavedEntryLabel($entry);
                }
            }
            if ($unsaved) {
                throw new RuntimeException('Still needs saving: ' . implode('; ', $unsaved) . '. Open each flagged entry, save its correction, then resubmit.');
            }
            // Capture the affected reviewers before clearing the revision flags.
            $reviewers = $pdo->prepare("SELECT reviewer.user_id FROM users reviewer
                WHERE reviewer.role='evaluator' AND reviewer.status='active'
                AND EXISTS (SELECT 1 FROM kra_submissions s
                    WHERE s.application_id=? AND s.revision_status='needs_revision'
                    AND (s.revision_by=reviewer.user_id OR EXISTS (
                        SELECT 1 FROM checker_kra_assignments ka
                        WHERE ka.checker_id=reviewer.user_id AND ka.cycle_id=? AND ka.kra_category=s.kra_category)))");
            $reviewers->execute([$applicationId, $application['cycle_id']]);
            $recipients = $reviewers->fetchAll(PDO::FETCH_COLUMN);
            $pdo->prepare("DELETE v FROM kra_checker_verifications v
                JOIN kra_submissions s ON s.submission_id=v.submission_id
                WHERE s.application_id=? AND s.revision_status='needs_revision'")->execute([$applicationId]);
            // Keep the evaluator's remark and request timestamp for review history.
            $pdo->prepare("UPDATE kra_submissions SET revision_status='ok', revision_saved_at=NULL,
                verified=0, verified_by=NULL, verified_at=NULL, faculty_original_score=computed_points
                WHERE application_id=? AND revision_status='needs_revision'")->execute([$applicationId]);
            $pdo->prepare("UPDATE applications SET status='under_review', checker_remarks=NULL, submitted_at=NOW()
                WHERE application_id=?")->execute([$applicationId]);
            $faculty = $pdo->prepare('SELECT full_name FROM users WHERE user_id=?');
            $faculty->execute([$application['user_id']]);
            $message = ($faculty->fetchColumn() ?: 'A faculty member') . ' has resubmitted the corrected KRA entries for your review.';
            $notify = $pdo->prepare("INSERT INTO notifications (user_id,type,message,application_id)
                VALUES (?,'revision_resubmitted',?,?)");
            foreach ($recipients as $reviewerId) {
                $notify->execute([(int)$reviewerId, $message, $applicationId]);
            }
            if ($ownsTransaction) $pdo->commit();
            return count($flagged);
        } catch (Throwable $e) {
            if ($ownsTransaction && $pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
    }
}
