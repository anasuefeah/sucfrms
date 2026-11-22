<?php
require_once __DIR__ . '/kra_numbering.php';
require_once __DIR__ . '/criteria_hierarchy.php';

/** Working criteria belong to one scope; saved revisions are immutable snapshots. */
class CriteriaSnapshots {
    public static function ensureSchema(PDO $pdo): void {
        $pdo->exec("CREATE TABLE IF NOT EXISTS criteria_scope_state (
            scope_id INT PRIMARY KEY, revision INT NOT NULL DEFAULT 0
        ) ENGINE=InnoDB");
        $pdo->exec("CREATE TABLE IF NOT EXISTS criteria_snapshots (
            snapshot_id BIGINT AUTO_INCREMENT PRIMARY KEY,
            scope_id INT NOT NULL, cycle_id INT DEFAULT NULL,
            revision INT NOT NULL, criteria_json LONGTEXT NOT NULL,
            change_summary VARCHAR(255) NOT NULL, created_by INT DEFAULT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uq_criteria_revision (scope_id,revision),
            FOREIGN KEY (scope_id) REFERENCES criteria_scope_state(scope_id) ON DELETE CASCADE,
            FOREIGN KEY (cycle_id) REFERENCES cycles(cycle_id) ON DELETE CASCADE,
            FOREIGN KEY (created_by) REFERENCES users(user_id) ON DELETE SET NULL
        ) ENGINE=InnoDB");
        $pdo->exec('INSERT IGNORE INTO criteria_scope_state (scope_id) VALUES (0)');
        migrateCriteriaHierarchy($pdo);
    }

    public static function ranks(): array {
        return ['Instructor I','Instructor II','Instructor III','Assistant Professor I','Assistant Professor II','Assistant Professor III','Assistant Professor IV','Associate Professor I','Associate Professor II','Associate Professor III','Associate Professor IV','Associate Professor V','Professor I','Professor II','Professor III','Professor IV','Professor V','Professor VI','University Professor'];
    }

    public static function lockScopes(PDO $pdo): array {
        if (!$pdo->inTransaction()) throw new LogicException('Criteria changes require a transaction.');
        $pdo->query('SELECT scope_id FROM criteria_scope_state WHERE scope_id=0 FOR UPDATE')->fetch();
        return $pdo->query('SELECT * FROM cycles ORDER BY cycle_id FOR UPDATE')->fetchAll(PDO::FETCH_ASSOC);
    }

    private static function scopeRows(PDO $pdo, int $scope): array {
        $q = $pdo->prepare('SELECT * FROM scoring_criteria WHERE cycle_id <=> ? ORDER BY criteria_id');
        $q->execute([$scope ?: null]);
        return $q->fetchAll(PDO::FETCH_ASSOC);
    }

    private static function encodeRows(array $rows): string {
        $json = json_encode($rows,JSON_THROW_ON_ERROR);
        // Complete rank snapshots repeat long evidence descriptions. Keep each
        // insert below common shared-host MySQL packet limits without losing data.
        if (strlen($json) > 262144) return json_encode(['encoding'=>'gzip-base64','data'=>base64_encode(gzencode($json,6))],JSON_THROW_ON_ERROR);
        return $json;
    }

    public static function decodeRows(string $json): array {
        $data = json_decode($json,true,512,JSON_THROW_ON_ERROR);
        if (($data['encoding'] ?? '') === 'gzip-base64') {
            $decoded = gzdecode(base64_decode($data['data'],true));
            if ($decoded === false) throw new RuntimeException('Invalid criteria snapshot.');
            return json_decode($decoded,true,512,JSON_THROW_ON_ERROR);
        }
        return $data;
    }

    public static function versionDetails(PDO $pdo, int $scope, int $revision): ?array {
        $query = $pdo->prepare('SELECT snapshot.revision, snapshot.change_summary, snapshot.created_at,
                snapshot.criteria_json, u.full_name AS author
            FROM criteria_snapshots snapshot LEFT JOIN users u ON u.user_id=snapshot.created_by
            WHERE snapshot.scope_id=? AND snapshot.revision<=?
            ORDER BY snapshot.revision DESC LIMIT 2');
        $query->execute([$scope, $revision]);
        [$version, $previous] = array_pad($query->fetchAll(PDO::FETCH_ASSOC), 2, null);
        if (!$version || (int)$version['revision'] !== $revision) return null;

        $version['changes'] = $previous
            ? self::compareVersions(self::decodeRows($previous['criteria_json']), self::decodeRows($version['criteria_json']))
            : [];
        unset($version['criteria_json']);
        $version['previous_revision'] = $previous ? (int)$previous['revision'] : null;
        return $version;
    }

    private static function compareVersions(array $previous, array $current): array {
        $before = array_column($previous, null, 'criteria_id');
        $after = array_column($current, null, 'criteria_id');
        $previousHasHierarchy = $previous && array_key_exists('parent_id', $previous[0]);
        $fields = [
            'criterion_key' => 'Criterion key', 'criterion_label' => 'Label', 'kra_category' => 'KRA',
            'position_rank' => 'Faculty rank', 'max_points' => 'Maximum points', 'weight_pct' => 'Weight',
            'is_active' => 'Status', 'parent_id' => 'Parent criterion', 'is_parent' => 'Criterion type',
            'sort_order' => 'Display order', 'description' => 'Evidence requirements',
        ];
        $changes = [];
        foreach ($before + $after as $id => $row) {
            $old = $before[$id] ?? null;
            $new = $after[$id] ?? null;
            if (!$old || !$new) {
                // The hierarchy migration inserted parent rows without an administrator edit.
                if (!$old && $previous && !$previousHasHierarchy && !empty($new['is_parent']) && empty($new['updated_by'])) continue;
                $changes[] = ['criterion' => $new ?? $row, 'type' => $old ? 'Removed' : 'Added', 'fields' => []];
                continue;
            }
            $differences = [];
            foreach ($fields as $field => $label) {
                if (!$previousHasHierarchy && in_array($field, ['parent_id', 'is_parent', 'sort_order'], true)) continue;
                $oldValue = self::versionField($field, $old, $before);
                $newValue = self::versionField($field, $new, $after);
                // A renamed parent is recorded on the parent row itself.
                $same = $field === 'parent_id'
                    ? (int)($old['parent_id'] ?? 0) === (int)($new['parent_id'] ?? 0)
                    : $oldValue === $newValue;
                if ($same) continue;
                if ($field === 'description') {
                    [$oldValue, $newValue] = self::changedEvidenceLines($oldValue, $newValue);
                    if ($oldValue === $newValue) continue;
                }
                $differences[] = ['label' => $label, 'before' => $oldValue, 'after' => $newValue];
            }
            if (!$differences) continue;

            $type = match (true) {
                !empty($old['is_active']) && empty($new['is_active']) => 'Deactivated',
                empty($old['is_active']) && !empty($new['is_active']) => 'Reactivated',
                default => 'Updated',
            };
            $changes[] = ['criterion' => $new, 'type' => $type, 'fields' => $differences];
        }
        return $changes;
    }

    private static function changedEvidenceLines(?string $before, ?string $after): array {
        $oldLines = $before === null ? [] : preg_split('/\R/u', $before);
        $newLines = $after === null ? [] : preg_split('/\R/u', $after);
        if ($oldLines === $newLines) return [null, null];
        $unmatched = static function (array $lines, array $other): ?string {
            $remaining = array_count_values($other);
            $changed = [];
            foreach ($lines as $line) {
                if (!empty($remaining[$line])) {
                    $remaining[$line]--;
                } else {
                    $changed[] = $line;
                }
            }
            return $changed ? implode("\n", $changed) : null;
        };
        $oldChanges = $unmatched($oldLines, $newLines);
        $newChanges = $unmatched($newLines, $oldLines);
        return $oldChanges !== null || $newChanges !== null
            ? [$oldChanges, $newChanges]
            : [null, 'Evidence requirements reordered'];
    }

    private static function versionField(string $field, array $row, array $rowsById): ?string {
        $value = $row[$field] ?? null;
        return match ($field) {
            'max_points' => number_format((float)$value, 2),
            'weight_pct' => number_format((float)($value ?? 100), 2) . '%',
            'is_active' => !empty($value) ? 'Active' : 'Inactive',
            'is_parent' => !empty($value) ? 'Parent criterion' : 'Scoring criterion',
            'parent_id' => $value === null ? null : ($rowsById[$value]['criterion_label'] ?? "Criterion #{$value}"),
            'position_rank' => $value ?: 'All ranks',
            'sort_order' => (string)($value ?? 0),
            default => $value === null || trim((string)$value) === '' ? null : (string)$value,
        };
    }

    public static function rows(PDO $pdo, int $cycleId = 0, ?string $position = null, ?string $category = null): array {
        $rows = self::scopeRows($pdo, $cycleId);
        $selected = [];
        foreach ($rows as $row) {
            if ($category !== null && $row['kra_category'] !== $category) continue;
            if ($row['position_rank'] !== null && $row['position_rank'] !== $position) continue;
            $key = $row['criterion_key'];
            if (!isset($selected[$key]) || $row['position_rank'] === $position) $selected[$key] = $row;
        }
        $parents = [];
        foreach ($rows as $row) {
            if (empty($row['is_parent'])) continue;
            $parent = $selected[$row['criterion_key']] ?? null;
            if ($parent) $parents[$row['criteria_id']] = $parent;
        }
        $active = [];
        foreach ($selected as $row) {
            if (!$row['is_active'] || !empty($row['is_parent'])) continue;
            if (isset($row['parent_id'])) {
                $parent = $parents[$row['parent_id']] ?? null;
                if (!$parent || !$parent['is_active']) continue;
                $row['parent_id'] = $parent['criteria_id'];
                $row['parent_code'] = criteriaParentCode($parent);
                $row['parent_name'] = $parent['criterion_label'];
                $row['parent_max'] = (float)$parent['max_points'];
                $row['parent_sort_order'] = (int)$parent['sort_order'];
            }
            $active[] = $row;
        }
        return $active;
    }

    public static function parents(PDO $pdo, int $cycleId, ?string $position): array {
        $parents = [];
        foreach (self::scopeRows($pdo, $cycleId) as $row) {
            if (empty($row['is_parent']) || ($row['position_rank'] !== null && $row['position_rank'] !== $position)) continue;
            $key = $row['criterion_key'];
            if (!isset($parents[$key]) || $row['position_rank'] === $position) $parents[$key] = $row;
        }
        $parents = array_values(array_filter($parents, static fn(array $row): bool => (bool)$row['is_active']));
        usort($parents, static fn(array $a, array $b): int => [$a['sort_order'], $a['criteria_id']] <=> [$b['sort_order'], $b['criteria_id']]);
        return $parents;
    }

    private static function copyIntoCycle(PDO $pdo, int $cycleId): void {
        $defaults = self::scopeRows($pdo,0);
        $existing = self::scopeRows($pdo,$cycleId);
        $insert = $pdo->prepare('INSERT IGNORE INTO scoring_criteria (cycle_id,position_rank,kra_category,criterion_key,criterion_label,max_points,weight_pct,description,is_active,updated_by,parent_id,is_parent,sort_order) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)');
        $lookup = $pdo->prepare('SELECT criteria_id FROM scoring_criteria WHERE cycle_id=? AND position_rank=? AND criterion_key=?');
        $keysById = array_column(array_merge($defaults, $existing), 'criterion_key', 'criteria_id');
        foreach (self::ranks() as $rank) {
            $source = [];
            foreach (array_merge($defaults,$existing) as $row) {
                if ($row['position_rank'] === null || $row['position_rank'] === $rank) {
                    // Existing position rows are preserved by INSERT IGNORE.
                    $source[$row['criterion_key']] = $row;
                }
            }
            uasort($source, static fn(array $a, array $b): int => (int)$b['is_parent'] <=> (int)$a['is_parent']);
            $parentIds = [];
            foreach ($source as $row) {
                $parentKey = $keysById[$row['parent_id'] ?? 0] ?? null;
                $insert->execute([$cycleId,$rank,$row['kra_category'],$row['criterion_key'],$row['criterion_label'],$row['max_points'],$row['weight_pct'],$row['description'],$row['is_active'],$row['updated_by'],$parentIds[$parentKey] ?? null,$row['is_parent'],$row['sort_order']]);
                if ($row['is_parent']) {
                    $lookup->execute([$cycleId,$rank,$row['criterion_key']]);
                    $parentIds[$row['criterion_key']] = (int)$lookup->fetchColumn();
                }
            }
        }
    }

    public static function record(PDO $pdo, int $scope, ?int $adminId, string $summary, bool $notify = true): int {
        if (!$pdo->inTransaction()) throw new LogicException('Snapshots require a transaction.');
        $pdo->prepare('INSERT IGNORE INTO criteria_scope_state (scope_id) VALUES (?)')->execute([$scope]);
        $q = $pdo->prepare('SELECT revision FROM criteria_scope_state WHERE scope_id=? FOR UPDATE');
        $q->execute([$scope]);
        $revision = (int)$q->fetchColumn() + 1;
        $pdo->prepare('INSERT INTO criteria_snapshots (scope_id,cycle_id,revision,criteria_json,change_summary,created_by) VALUES (?,?,?,?,?,?)')
            ->execute([$scope,$scope ?: null,$revision,self::encodeRows(self::scopeRows($pdo,$scope)),$summary,$adminId]);
        $pdo->prepare('UPDATE criteria_scope_state SET revision=? WHERE scope_id=?')->execute([$revision,$scope]);
        if ($notify) {
            $label = 'defaults for future cycles';
            if ($scope) {
                $name = $pdo->prepare('SELECT cycle_name FROM cycles WHERE cycle_id=?');
                $name->execute([$scope]);
                $label = (string)$name->fetchColumn();
            }
            // Fail atomically if notifications cannot be saved; never silently lose alerts.
            $pdo->prepare("INSERT INTO notifications (user_id,type,message) SELECT user_id,'criteria_updated',? FROM users WHERE role='faculty'")
                ->execute(["Scoring criteria updated: {$label} (version {$revision}). {$summary}. Past and closed cycles retain their own criteria."]);
        }
        return $revision;
    }

    public static function baseline(PDO $pdo): void {
        $pdo->beginTransaction();
        try {
            $cycles = self::lockScopes($pdo);
            if (!(int)$pdo->query('SELECT revision FROM criteria_scope_state WHERE scope_id=0')->fetchColumn()) self::record($pdo,0,null,'Initial default criteria',false);
            foreach ($cycles as $cycle) self::initializeCycle($pdo,(int)$cycle['cycle_id'],null,false);
            $pdo->commit();
        } catch (Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $e; }
    }

    public static function initializeCycle(PDO $pdo, int $cycleId, ?int $adminId, bool $notify = true): void {
        if (!$pdo->inTransaction()) throw new LogicException('Cycle setup requires a transaction.');
        $q = $pdo->prepare('SELECT revision FROM criteria_scope_state WHERE scope_id=?');
        $q->execute([$cycleId]);
        if ((int)$q->fetchColumn() > 0) return;
        self::copyIntoCycle($pdo,$cycleId);
        self::record($pdo,$cycleId,$adminId,'Cycle criteria version created',$notify);
    }

    public static function changeCycleStatus(PDO $pdo, int $cycleId, string $status): void {
        if (!in_array($status,['open','closed','archived'],true)) throw new RuntimeException('Invalid cycle status.');
        $pdo->beginTransaction();
        try {
            $cycles = self::lockScopes($pdo);
            $cycle = array_column($cycles, null, 'cycle_id')[$cycleId] ?? null;
            if (!$cycle) throw new RuntimeException('Cycle not found.');
            self::initializeCycle($pdo,$cycleId,null,false);
            if ($status === 'open') self::closeOpenCycles($pdo, $cycles, $cycleId);
            if ($cycle['status'] === 'open' && $status !== 'open') self::closeCycle($pdo, $cycleId);
            if ($status !== $cycle['status']) $pdo->prepare('UPDATE cycles SET status=? WHERE cycle_id=?')->execute([$status,$cycleId]);

            if ($status === 'archived' && $cycle['status'] !== 'archived') {
                // Risk 5: Auto-resolve open appeals when cycle is archived
                $pdo->prepare("
                    UPDATE appeals
                    SET status='resolved', outcome='dismissed', resolved_at=NOW()
                    WHERE cycle_id=? AND status IN ('open','under_review')
                ")->execute([$cycleId]);

                // Notify faculty with dismissed appeals
                $dismissedFaculty = $pdo->prepare("
                    SELECT DISTINCT faculty_id FROM appeals
                    WHERE cycle_id=? AND status='resolved' AND outcome='dismissed'
                      AND resolved_at >= NOW() - INTERVAL 10 SECOND
                ");
                $dismissedFaculty->execute([$cycleId]);
                $archiveNotif = $pdo->prepare("INSERT INTO notifications (user_id, type, message) VALUES (?, 'cycle_archived', ?)");
                foreach ($dismissedFaculty->fetchAll(PDO::FETCH_COLUMN) as $fid) {
                    $archiveNotif->execute([$fid, 'The reclassification cycle has been archived. Any pending appeals have been closed.']);
                }
            }

            $pdo->commit();
        } catch (Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $e; }
    }

    /** Close the open cycles read by lockScopes() within the current transaction. */
    public static function closeOpenCycles(PDO $pdo, array $lockedCycles, ?int $exceptCycleId = null): int {
        if (!$pdo->inTransaction()) throw new LogicException('Cycle closeout requires a transaction.');
        $unfinished = 0;
        foreach ($lockedCycles as $cycle) {
            $cycleId = (int)$cycle['cycle_id'];
            if ($cycle['status'] === 'open' && $cycleId !== $exceptCycleId) {
                $unfinished += self::closeCycle($pdo, $cycleId);
            }
        }
        return $unfinished;
    }

    private static function closeCycle(PDO $pdo, int $cycleId): int {
        $pdo->prepare("UPDATE cycles SET status='closed' WHERE cycle_id=?")->execute([$cycleId]);

        $drafts = $pdo->prepare("SELECT user_id FROM applications WHERE cycle_id=? AND status='draft' FOR UPDATE");
        $drafts->execute([$cycleId]);
        $faculty = $drafts->fetchAll(PDO::FETCH_COLUMN);
        $pdo->prepare("UPDATE applications SET status='expired' WHERE cycle_id=? AND status='draft'")->execute([$cycleId]);
        $notify = $pdo->prepare('INSERT INTO notifications (user_id, type, message) VALUES (?, ?, ?)');
        foreach ($faculty as $facultyId) {
            $notify->execute([$facultyId, 'cycle_expired', 'The reclassification cycle has closed. Your draft application was not submitted and has been marked expired.']);
        }

        $pdo->prepare("DELETE ck FROM checker_kra_assignments ck JOIN users u ON u.user_id=ck.checker_id
            WHERE ck.cycle_id=? AND (u.status!='active' OR u.role!='evaluator')")->execute([$cycleId]);
        $pending = $pdo->prepare("SELECT COUNT(*) FROM applications
            WHERE cycle_id=? AND status IN ('submitted','under_review','needs_revision')");
        $pending->execute([$cycleId]);
        $unfinished = (int)$pending->fetchColumn();
        if ($unfinished > 0) {
            $evaluators = $pdo->prepare('SELECT DISTINCT checker_id FROM checker_kra_assignments WHERE cycle_id=?');
            $evaluators->execute([$cycleId]);
            foreach ($evaluators->fetchAll(PDO::FETCH_COLUMN) as $evaluatorId) {
                $notify->execute([$evaluatorId, 'cycle_closed_pending', 'A reclassification cycle has been closed. Some applications assigned to you were not fully evaluated.']);
            }
        }

        self::takeCycleSummarySnapshot($pdo, $cycleId);
        return $unfinished;
    }

    public static function takeCycleSummarySnapshot(PDO $pdo, int $cycleId): void {
        try {
            $cycleName = $pdo->prepare("SELECT cycle_name FROM cycles WHERE cycle_id=?");
            $cycleName->execute([$cycleId]);
            $cycleName = (string)($cycleName->fetchColumn() ?: "Cycle #{$cycleId}");

            $stats = $pdo->prepare("
                SELECT
                    COUNT(*)                                                        AS total_applicants,
                    SUM(status NOT IN ('draft','expired'))                          AS submitted,
                    SUM(status IN ('approved','reclassified'))                      AS approved,
                    SUM(status IN ('rejected','admin_rejected'))                    AS returned,
                    SUM(status = 'expired')                                         AS expired_drafts,
                    ROUND(AVG(CASE WHEN status NOT IN ('draft','expired') THEN weighted_score END), 2) AS avg_weighted_score
                FROM applications WHERE cycle_id=?
            ");
            $stats->execute([$cycleId]);
            $s = $stats->fetch(PDO::FETCH_ASSOC);

            // Per-KRA averages from kra_submissions joined through applications
            $kraAvg = $pdo->prepare("
                SELECT ks.kra_category, ROUND(AVG(ks.computed_points),2) AS avg_pts
                FROM kra_submissions ks
                JOIN applications a ON a.application_id = ks.application_id
                WHERE a.cycle_id=? AND a.status NOT IN ('draft','expired')
                GROUP BY ks.kra_category
            ");
            $kraAvg->execute([$cycleId]);
            $kraMap = ['Instruction'=>0,'Research'=>0,'Extension'=>0,'Professional Development'=>0];
            foreach ($kraAvg->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $kraMap[$r['kra_category']] = (float)$r['avg_pts'];
            }

            $pdo->prepare("
                INSERT INTO cycle_summary
                    (cycle_id, cycle_name, total_applicants, submitted, approved, returned,
                     expired_drafts, avg_weighted_score, avg_kra1, avg_kra2, avg_kra3, avg_kra4)
                VALUES (?,?,?,?,?,?,?,?,?,?,?,?)
                ON DUPLICATE KEY UPDATE
                    cycle_name=VALUES(cycle_name),
                    total_applicants=VALUES(total_applicants),
                    submitted=VALUES(submitted),
                    approved=VALUES(approved),
                    returned=VALUES(returned),
                    expired_drafts=VALUES(expired_drafts),
                    avg_weighted_score=VALUES(avg_weighted_score),
                    avg_kra1=VALUES(avg_kra1),
                    avg_kra2=VALUES(avg_kra2),
                    avg_kra3=VALUES(avg_kra3),
                    avg_kra4=VALUES(avg_kra4),
                    snapshot_at=NOW()
            ")->execute([
                $cycleId, $cycleName,
                (int)($s['total_applicants']??0),
                (int)($s['submitted']??0),
                (int)($s['approved']??0),
                (int)($s['returned']??0),
                (int)($s['expired_drafts']??0),
                (float)($s['avg_weighted_score']??0),
                $kraMap['Instruction'],
                $kraMap['Research'],
                $kraMap['Extension'],
                $kraMap['Professional Development'],
            ]);
        } catch (\Throwable $e) {
            error_log('SUCFRMS cycle summary error: ' . $e->getMessage());
            throw new RuntimeException('Could not save the cycle summary. The cycle status was not changed.', 0, $e);
        }
    }

    /** Count explicit criterion references before a default criterion is deactivated. */
    public static function defaultReferenceCounts(PDO $pdo): array {
        $query = $pdo->query("SELECT refs.kra_category, refs.criterion_key, COUNT(*) AS entry_count
            FROM (
                SELECT s.kra_category, CASE
                    WHEN LOCATE('|||CRITERION_KEY=', CONCAT('|||', s.remarks)) > 0
                    THEN SUBSTRING_INDEX(SUBSTRING_INDEX(CONCAT('|||', s.remarks), '|||CRITERION_KEY=', -1), '|||', 1)
                    WHEN LEFT(s.remarks, 9) = 'CUSTOM|||'
                    THEN SUBSTRING_INDEX(SUBSTRING_INDEX(s.remarks, '|||', 3), '|||', -1)
                END AS criterion_key
                FROM kra_submissions s
                JOIN applications a ON a.application_id=s.application_id
                WHERE a.status NOT IN ('approved','reclassified','admin_rejected','expired')
            ) refs
            WHERE refs.criterion_key <> ''
            GROUP BY refs.kra_category, refs.criterion_key");
        $byKey = [];
        foreach ($query->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $byKey[$row['kra_category']][$row['criterion_key']] = (int)$row['entry_count'];
        }
        $counts = [];
        foreach (self::scopeRows($pdo, 0) as $row) {
            $count = $byKey[$row['kra_category']][$row['criterion_key']] ?? 0;
            $id = (int)$row['criteria_id'];
            $counts[$id] = ($counts[$id] ?? 0) + $count;
            if ($row['parent_id'] !== null) {
                $parentId = (int)$row['parent_id'];
                $counts[$parentId] = ($counts[$parentId] ?? 0) + $count;
            }
        }
        return $counts;
    }

    public static function save(PDO $pdo, int $adminId, string $action, array $post): array {
        $pdo->beginTransaction();
        try {
            $cycles = self::lockScopes($pdo);
            $admin = $pdo->prepare('SELECT role,status FROM users WHERE user_id=? FOR UPDATE');
            $admin->execute([$adminId]);
            $admin = $admin->fetch(PDO::FETCH_ASSOC);
            if (!$admin || $admin['role'] !== 'admin' || $admin['status'] !== 'active') throw new RuntimeException('Administrator access is required.');
            foreach ($cycles as $cycle) {
                if ($cycle['status'] === 'open') throw new RuntimeException('Criteria cannot be edited while a cycle is open.');
            }
            if (in_array($action,['update_criterion','delete_criterion'],true)) {
                $q = $pdo->prepare('SELECT * FROM scoring_criteria WHERE criteria_id=? FOR UPDATE');
                $q->execute([(int)($post['criteria_id'] ?? 0)]);
                $row = $q->fetch(PDO::FETCH_ASSOC);
                if (!$row) throw new RuntimeException('Criterion not found.');
                $scope = (int)($row['cycle_id'] ?? 0);
            } elseif ($action === 'add_criterion') {
                $scope = (int)($post['cycle_id'] ?? 0);
            } else throw new RuntimeException('Unsupported criteria action.');
            if ($scope || isset($row['position_rank'])) throw new RuntimeException('Saved cycle criteria are read-only. Edit defaults to configure a future cycle.');
            $changed = true;
            if ($action === 'delete_criterion') {
                $changed = (bool)$row['is_active'];
                if ($changed) $pdo->prepare('UPDATE scoring_criteria SET is_active=0,updated_by=? WHERE criteria_id=?')->execute([$adminId,$row['criteria_id']]);
                $summary = 'Criterion removed';
            } else {
                $label = trim((string)($post['criterion_label'] ?? ''));
                $max = $post['max_points'] ?? '';
                $description = trim((string)($post['description'] ?? ''));
                $enabled = $action === 'add_criterion' || isset($post['is_active']) ? 1 : 0;
                if ($label === '' || !is_numeric($max) || !is_finite((float)$max) || $max < 0) throw new RuntimeException('Enter a label and non-negative points.');
                if ($action === 'add_criterion') {
                    $key = trim((string)($post['criterion_key'] ?? ''));
                    $category = $post['kra_category'] ?? '';
                    if ($key === '' || stripos($key, '@parent:') === 0 || str_contains($key, '|||') || !in_array($category,['Instruction','Research','Extension','Professional Development'],true)) throw new RuntimeException('A criterion key and valid KRA are required.');
                    $parentChoice = (string)($post['parent_criterion'] ?? '');
                    $isParent = $parentChoice === 'new_parent';
                    $parentId = null;
                    $sort = 0;
                    if ($isParent) {
                        $code = strtoupper($key);
                        if (!preg_match('/^[A-Z]$/D', $code)) throw new RuntimeException('Enter a single letter as the parent criterion key.');
                        $key = criteriaParentKey($category, $code);
                        $existing = $pdo->prepare('SELECT is_active FROM scoring_criteria
                            WHERE cycle_id IS NULL AND position_rank IS NULL AND criterion_key=? ORDER BY is_active DESC LIMIT 1');
                        $existing->execute([$key]);
                        $status = $existing->fetchColumn();
                        if ($status !== false) {
                            throw new RuntimeException($status ? 'That parent letter is already in use for this KRA.'
                                : 'This parent already exists. Show inactive criteria to reactivate it.');
                        }
                        $sort = ord($code) - ord('A');
                    } elseif ($parentChoice !== '') {
                        foreach (self::parents($pdo, 0, null) as $parent) {
                            if ((string)$parent['criteria_id'] === $parentChoice && $parent['kra_category'] === $category) {
                                $parentId = $parent['criteria_id'];
                                break;
                            }
                        }
                        if ($parentId === null) throw new RuntimeException('Select an active parent criterion for the chosen KRA.');
                    }
                    $pdo->prepare('INSERT INTO scoring_criteria (kra_category,criterion_key,criterion_label,max_points,description,is_active,updated_by,parent_id,is_parent,sort_order) VALUES (?,?,?,?,?,?,?,?,?,?)')
                        ->execute([$category,$key,$label,$max,$description,$enabled,$adminId,$parentId,(int)$isParent,$sort]);
                    $summary = 'Criterion added';
                } else {
                    $changed = $label !== $row['criterion_label'] || (float)$max !== (float)$row['max_points'] || $description !== trim((string)$row['description']) || $enabled !== (int)$row['is_active'];
                    if ($changed) $pdo->prepare('UPDATE scoring_criteria SET criterion_label=?,max_points=?,description=?,is_active=?,updated_by=? WHERE criteria_id=?')->execute([$label,$max,$description,$enabled,$adminId,$row['criteria_id']]);
                    $summary = 'Criterion updated';
                }
            }
            $revision = null;
            if ($changed) {
                $revision = self::record($pdo,$scope,$adminId,$summary);
                $pdo->prepare('INSERT INTO audit_logs (user_id,role_at_time,action_performed,details) VALUES (?,\'admin\',\'Criteria Updated\',?)')
                    ->execute([$adminId,"{$summary}; scope {$scope}; revision {$revision}"]);
            }
            $pdo->commit();
            return ['scope'=>$scope,'changed'=>$changed,'revision'=>$revision];
        } catch (Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $e; }
    }
}
