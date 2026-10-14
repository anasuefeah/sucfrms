<?php
// Request handling and data loading for frontend/views/admin/cycles.php
if (!isAdmin()) { echo '<div class="alert alert-danger">Access denied.</div>'; return false; }

// -- Runtime migration: add cycle_id to scoring_criteria if missing --
try { $pdo->query("SELECT cycle_id FROM scoring_criteria LIMIT 1"); }
catch (\Exception $e) {
    $pdo->exec("ALTER TABLE scoring_criteria ADD COLUMN cycle_id INT DEFAULT NULL AFTER criteria_id");
    try { $pdo->exec("ALTER TABLE scoring_criteria DROP INDEX criterion_key"); } catch (\Exception $e2) {}
    try { $pdo->exec("ALTER TABLE scoring_criteria ADD UNIQUE KEY uq_cycle_criterion (cycle_id, criterion_key)"); } catch (\Exception $e2) {}
    try { $pdo->exec("ALTER TABLE scoring_criteria ADD CONSTRAINT fk_sc_cycle FOREIGN KEY (cycle_id) REFERENCES cycles(cycle_id) ON DELETE CASCADE"); } catch (\Exception $e2) {}
}
// Add position_rank column if missing
try { $pdo->query("SELECT position_rank FROM scoring_criteria LIMIT 1"); }
catch (\Exception $e) {
    $pdo->exec("ALTER TABLE scoring_criteria ADD COLUMN position_rank VARCHAR(100) DEFAULT NULL AFTER cycle_id");
    try { $pdo->exec("ALTER TABLE scoring_criteria DROP INDEX uq_cycle_criterion"); } catch (\Exception $e2) {}
    try { $pdo->exec("ALTER TABLE scoring_criteria ADD UNIQUE KEY uq_cycle_pos_criterion (cycle_id, position_rank, criterion_key)"); } catch (\Exception $e2) {}
}
// -- Runtime migration: add submission_deadline to cycles if missing --
try { $pdo->query("SELECT submission_deadline FROM cycles LIMIT 1"); }
catch (\Exception $e) {
    $pdo->exec("ALTER TABLE cycles ADD COLUMN submission_deadline DATE DEFAULT NULL AFTER end_date");
}
try { $pdo->query("SELECT submission_start_date FROM cycles LIMIT 1"); }
catch (\Exception $e) {
    try { $pdo->exec("ALTER TABLE cycles ADD COLUMN submission_start_date DATE DEFAULT NULL AFTER end_date"); } catch (\Exception $e2) {}
    try { $pdo->exec("UPDATE cycles SET submission_start_date=start_date WHERE submission_start_date IS NULL AND start_date IS NOT NULL"); } catch (\Exception $e2) {}
}
try { $pdo->query("SELECT evaluation_deadline FROM cycles LIMIT 1"); }
catch (\Exception $e) {
    try { $pdo->exec("ALTER TABLE cycles ADD COLUMN evaluation_deadline DATE DEFAULT NULL AFTER submission_deadline"); } catch (\Exception $e2) {}
    try { $pdo->exec("UPDATE cycles SET evaluation_deadline=end_date WHERE evaluation_deadline IS NULL AND end_date IS NOT NULL"); } catch (\Exception $e2) {}
}
try { $pdo->query("SELECT appeal_deadline FROM cycles LIMIT 1"); }
catch (\Exception $e) {
    try { $pdo->exec("ALTER TABLE cycles ADD COLUMN appeal_deadline DATE DEFAULT NULL AFTER evaluation_deadline"); } catch (\Exception $e2) {}
}
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS cycle_submission_extensions (
        extension_id INT AUTO_INCREMENT PRIMARY KEY,
        cycle_id INT NOT NULL,
        faculty_user_id INT DEFAULT NULL,
        applies_to_all TINYINT(1) DEFAULT 0,
        previous_deadline DATE NOT NULL,
        new_deadline DATE NOT NULL,
        reason TEXT DEFAULT NULL,
        extended_by INT DEFAULT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_cycle_extensions_cycle (cycle_id),
        INDEX idx_cycle_extensions_faculty (faculty_user_id),
        FOREIGN KEY (cycle_id) REFERENCES cycles(cycle_id) ON DELETE CASCADE,
        FOREIGN KEY (faculty_user_id) REFERENCES users(user_id) ON DELETE CASCADE,
        FOREIGN KEY (extended_by) REFERENCES users(user_id) ON DELETE SET NULL
    )");
} catch (\Exception $e) {}
// Make start_date / end_date nullable so legacy rows without dates don't cause errors
try { $pdo->exec("ALTER TABLE cycles MODIFY start_date DATE DEFAULT NULL, MODIFY end_date DATE DEFAULT NULL"); } catch (\Exception $e2) {}

$kra_list        = ['Instruction','Research','Extension','Professional Development'];
$active_cycle_id = intval($_GET['cycle_id'] ?? 0);
$active_position = trim($_GET['position'] ?? ''); // position filter within a cycle

function cycleDateValid(?string $date): bool {
    if (!$date) return false;
    $dt = DateTime::createFromFormat('Y-m-d', $date);
    return $dt && $dt->format('Y-m-d') === $date;
}

function validateCycleDates(?string $start, ?string $end, ?string $sub_start, ?string $sub_deadline, ?string $eval_deadline, ?string $appeal_deadline = null): array {
    $errors = [];
    foreach (['cycle start date'=>$start,'cycle end date'=>$end,'faculty submission start date'=>$sub_start,'faculty submission deadline'=>$sub_deadline,'evaluation deadline'=>$eval_deadline] as $label => $value) {
        if (!cycleDateValid($value)) $errors[] = ucfirst($label) . ' is required and must be a valid date.';
    }
    if ($errors) return $errors;
    if ($start >= $end) $errors[] = 'Cycle start date must be before the cycle end date.';
    if ($sub_start < $start || $sub_start > $end) $errors[] = 'Faculty submission start date must fall inside the cycle period.';
    if ($sub_deadline < $start || $sub_deadline > $end) $errors[] = 'Faculty submission deadline must fall inside the cycle period.';
    if ($sub_start > $sub_deadline) $errors[] = 'Faculty submission start date cannot be after the submission deadline.';
    if ($eval_deadline <= $sub_deadline) $errors[] = 'Evaluation deadline must be after the faculty submission deadline.';
    if ($eval_deadline > $end) $errors[] = 'Evaluation deadline cannot be later than the cycle end date.';
    if ($appeal_deadline !== null && $appeal_deadline !== '') {
        if (!cycleDateValid($appeal_deadline)) $errors[] = 'Appeal deadline must be a valid date.';
        elseif ($appeal_deadline < $sub_deadline || $appeal_deadline > $end) $errors[] = 'Appeal deadline must be after the submission deadline and inside the cycle period.';
    }
    return $errors;
}

// -- POST handlers ---------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    // -- Cycle CRUD --------------------------------------------
    if ($action === 'create_cycle') {
        $name       = trim($_POST['cycle_name'] ?? '');
        $start_date = trim($_POST['start_date'] ?? '');
        $end_date   = trim($_POST['end_date'] ?? '');
        $sub_start  = trim($_POST['submission_start_date'] ?? '');
        $deadline   = trim($_POST['submission_deadline'] ?? '');
        $eval_deadline = trim($_POST['evaluation_deadline'] ?? '');
        $appeal_deadline = trim($_POST['appeal_deadline'] ?? '') ?: null;
        $date_errors = validateCycleDates($start_date, $end_date, $sub_start, $deadline, $eval_deadline, $appeal_deadline);
        if (!$name) $date_errors[] = 'Cycle name is required.';
        if ($date_errors) {
            flashMessage('danger', implode('<br>', array_map('htmlspecialchars', $date_errors)));
        } else {
            $pdo->exec("UPDATE cycles SET status='closed' WHERE status='open'");
            $pdo->prepare("INSERT INTO cycles (cycle_name, start_date, end_date, submission_start_date, submission_deadline, evaluation_deadline, appeal_deadline, status, created_by) VALUES (?,?,?,?,?,?,?,'open',?)")
                ->execute([$name, $start_date, $end_date, $sub_start, $deadline, $eval_deadline, $appeal_deadline, $_SESSION['user_id']]);
            $new_cid = (int)$pdo->lastInsertId();

            // -- Auto-seed criteria for ALL positions from global defaults --
            $globals = $pdo->query("SELECT * FROM scoring_criteria WHERE cycle_id IS NULL AND position_rank IS NULL ORDER BY criteria_id")->fetchAll();
            if ($globals) {
                $ins = $pdo->prepare("INSERT IGNORE INTO scoring_criteria (cycle_id, position_rank, kra_category, criterion_key, criterion_label, max_points, weight_pct, description, is_active, updated_by) VALUES (?,?,?,?,?,?,?,?,?,?)");
                $all_ranks = [
                    'Instructor I','Instructor II','Instructor III',
                    'Assistant Professor I','Assistant Professor II','Assistant Professor III','Assistant Professor IV',
                    'Associate Professor I','Associate Professor II','Associate Professor III','Associate Professor IV','Associate Professor V',
                    'Professor I','Professor II','Professor III','Professor IV','Professor V','Professor VI',
                    'University Professor',
                ];
                foreach ($all_ranks as $rank) {
                    foreach ($globals as $g) {
                        $ins->execute([$new_cid, $rank, $g['kra_category'], $g['criterion_key'], $g['criterion_label'], $g['max_points'], $g['weight_pct'], $g['description'], $g['is_active'], $_SESSION['user_id']]);
                    }
                }
            }

            logAudit($pdo, $_SESSION['user_id'], 'Cycle Created', "New cycle: {$name}. Period {$start_date} to {$end_date}; submissions {$sub_start} to {$deadline}; evaluation due {$eval_deadline}.");
            ensureKraAssignmentTable($pdo);
            $checker_ids = $pdo->query("SELECT user_id FROM users WHERE role='evaluator' AND status='active'")->fetchAll(PDO::FETCH_COLUMN);
            if ($checker_ids) {
                $assign = $pdo->prepare("INSERT IGNORE INTO checker_kra_assignments (cycle_id, checker_id, kra_category, assigned_by) VALUES (?,?,?,?)");
                foreach ($checker_ids as $checker_id) {
                    foreach (array_keys(kraAssignmentCategories()) as $kra) {
                        $assign->execute([$new_cid, (int)$checker_id, $kra, $_SESSION['user_id']]);
                    }
                }
            }
            flashMessage('success', "Cycle <strong>{$name}</strong> created. Criteria have been seeded for all positions - customise each position's criteria below.");
        }
    } elseif ($action === 'update_status') {
        $cid    = intval($_POST['cycle_id']);
        $status = $_POST['status'];
        if (in_array($status, ['open','closed','archived'])) {
            // If opening this cycle, close any other currently open cycles first
            if ($status === 'open') {
                $pdo->prepare("UPDATE cycles SET status='closed' WHERE status='open' AND cycle_id != ?")
                    ->execute([$cid]);
            }
            $pdo->prepare("UPDATE cycles SET status=? WHERE cycle_id=?")->execute([$status, $cid]);
            logAudit($pdo, $_SESSION['user_id'], 'Cycle Status Updated', "Cycle #{$cid} -> {$status}");
            flashMessage('success', $status === 'open'
                ? 'Cycle opened. Any previously open cycle has been closed. Faculty will automatically see this new cycle.'
                : 'Cycle status updated.');
        }
        echo "<script>window.location.href='index.php?page=cycles&cycle_id={$cid}';</script>"; exit;
    } elseif ($action === 'update_cycle') {
        $cid      = intval($_POST['cycle_id']);
        $name     = trim($_POST['cycle_name'] ?? '');
        $start    = trim($_POST['start_date'] ?? '') ?: null;
        $end      = trim($_POST['end_date'] ?? '') ?: null;
        $sub_start = trim($_POST['submission_start_date'] ?? '') ?: null;
        $deadline = trim($_POST['submission_deadline'] ?? '') ?: null;
        $eval_deadline = trim($_POST['evaluation_deadline'] ?? '') ?: null;
        $appeal_deadline = trim($_POST['appeal_deadline'] ?? '') ?: null;
        $date_errors = validateCycleDates($start, $end, $sub_start, $deadline, $eval_deadline, $appeal_deadline);
        if (!$name) $date_errors[] = 'Cycle name is required.';
        if ($date_errors) {
            flashMessage('danger', implode('<br>', array_map('htmlspecialchars', $date_errors)));
        } elseif ($cid) {
            $old = $pdo->prepare("SELECT * FROM cycles WHERE cycle_id=?");
            $old->execute([$cid]);
            $old = $old->fetch();
            $pdo->prepare("UPDATE cycles SET cycle_name=?, start_date=?, end_date=?, submission_start_date=?, submission_deadline=?, evaluation_deadline=?, appeal_deadline=? WHERE cycle_id=?")
                ->execute([$name, $start, $end, $sub_start, $deadline, $eval_deadline, $appeal_deadline, $cid]);
            $changes = [];
            foreach (['cycle_name'=>$name,'start_date'=>$start,'end_date'=>$end,'submission_start_date'=>$sub_start,'submission_deadline'=>$deadline,'evaluation_deadline'=>$eval_deadline,'appeal_deadline'=>$appeal_deadline] as $field => $new_val) {
                if (($old[$field] ?? null) != $new_val) $changes[] = "{$field}: " . ($old[$field] ?? 'blank') . " -> " . ($new_val ?? 'blank');
            }
            logAudit($pdo, $_SESSION['user_id'], 'Cycle Updated', "Cycle #{$cid} updated. " . ($changes ? implode('; ', $changes) : 'No date changes.'));
            flashMessage('success', "Cycle <strong>{$name}</strong> updated.");
        }
        echo "<script>window.location.href='index.php?page=cycles&cycle_id={$cid}';</script>"; exit;
    } elseif ($action === 'extend_submission') {
        $cid = intval($_POST['cycle_id']);
        $new_deadline = trim($_POST['new_deadline'] ?? '');
        $scope = $_POST['extension_scope'] ?? 'all';
        $faculty_ids = array_map('intval', $_POST['faculty_ids'] ?? []);
        $reason = trim($_POST['extension_reason'] ?? '');
        $confirm_past_eval = !empty($_POST['confirm_past_eval']);
        $cycle_row = $pdo->prepare("SELECT * FROM cycles WHERE cycle_id=?");
        $cycle_row->execute([$cid]);
        $cycle_row = $cycle_row->fetch();
        $errors = [];
        if (!$cycle_row) $errors[] = 'Selected cycle was not found.';
        if (!cycleDateValid($new_deadline)) $errors[] = 'Extended deadline must be a valid date.';
        if ($cycle_row && empty($cycle_row['submission_deadline'])) $errors[] = 'Set the current faculty submission deadline before adding an extension.';
        if ($cycle_row && !empty($cycle_row['end_date']) && $new_deadline > $cycle_row['end_date']) $errors[] = 'Extended deadline cannot be later than the cycle end date.';
        if ($cycle_row && !empty($cycle_row['evaluation_deadline']) && $new_deadline > $cycle_row['evaluation_deadline'] && !$confirm_past_eval) {
            $errors[] = 'Extended deadline goes past the evaluation deadline. Confirm this extension or adjust the evaluation deadline first.';
        }
        if ($scope === 'selected' && !$faculty_ids) $errors[] = 'Please select at least one faculty member for a selected-faculty extension.';
        if ($cycle_row && $scope === 'all') {
            $current_effective = getEffectiveSubmissionDeadline($pdo, $cid, 0, $cycle_row['submission_deadline'] ?? null);
            if ($current_effective && $new_deadline <= $current_effective) $errors[] = 'Extended deadline must be later than the current effective submission deadline.';
        } elseif ($cycle_row && $scope === 'selected' && $faculty_ids) {
            $faculty_ids = array_values(array_unique(array_filter($faculty_ids)));
            $placeholders = implode(',', array_fill(0, count($faculty_ids), '?'));
            $valid_fac = $pdo->prepare("SELECT user_id FROM users WHERE role='faculty' AND status='active' AND user_id IN ($placeholders)");
            $valid_fac->execute($faculty_ids);
            $faculty_ids = array_map('intval', $valid_fac->fetchAll(PDO::FETCH_COLUMN));
            if (!$faculty_ids) {
                $errors[] = 'Please select at least one active faculty account.';
            } else {
                foreach ($faculty_ids as $fid) {
                    $current_effective = getEffectiveSubmissionDeadline($pdo, $cid, $fid, $cycle_row['submission_deadline'] ?? null);
                    if ($current_effective && $new_deadline <= $current_effective) {
                        $errors[] = 'Extended deadline must be later than the current effective deadline for every selected faculty member.';
                        break;
                    }
                }
            }
        }
        if ($errors) {
            flashMessage('danger', implode('<br>', array_map('htmlspecialchars', $errors)));
        } else {
            $insert = $pdo->prepare("INSERT INTO cycle_submission_extensions (cycle_id, faculty_user_id, applies_to_all, previous_deadline, new_deadline, reason, extended_by) VALUES (?,?,?,?,?,?,?)");
            if ($scope === 'all') {
                $previous_deadline = getEffectiveSubmissionDeadline($pdo, $cid, 0, $cycle_row['submission_deadline'] ?? null);
                $insert->execute([$cid, null, 1, $previous_deadline, $new_deadline, $reason ?: null, $_SESSION['user_id']]);
                $who = 'all faculty';
            } else {
                foreach ($faculty_ids as $fid) {
                    $previous_deadline = getEffectiveSubmissionDeadline($pdo, $cid, $fid, $cycle_row['submission_deadline'] ?? null);
                    $insert->execute([$cid, $fid, 0, $previous_deadline, $new_deadline, $reason ?: null, $_SESSION['user_id']]);
                }
                $who = count($faculty_ids) . ' selected faculty';
            }
            logAudit($pdo, $_SESSION['user_id'], 'Submission Extended', "Cycle #{$cid}: submission deadline extended from {$cycle_row['submission_deadline']} to {$new_deadline} for {$who}. Reason: " . ($reason ?: 'N/A'));
            flashMessage('success', "Submission deadline extended to <strong>" . htmlspecialchars(date('M j, Y', strtotime($new_deadline))) . "</strong> for {$who}.");
        }
        echo "<script>window.location.href='index.php?page=cycles&cycle_id={$cid}';</script>"; exit;
    } elseif ($action === 'delete_cycle') {
        $cid = intval($_POST['cycle_id']);
        $has = $pdo->prepare("SELECT COUNT(*) FROM applications WHERE cycle_id=? AND status!='draft'");
        $has->execute([$cid]);
        if ($has->fetchColumn() > 0) {
            flashMessage('danger', 'Cannot delete - this cycle has submitted applications.');
            echo "<script>window.location.href='index.php?page=cycles&cycle_id={$cid}';</script>"; exit;
        }
        $pdo->prepare("DELETE FROM cycles WHERE cycle_id=?")->execute([$cid]);
        logAudit($pdo, $_SESSION['user_id'], 'Cycle Deleted', "Deleted cycle #{$cid}");
        flashMessage('success', 'Cycle deleted.');
        echo "<script>window.location.href='index.php?page=cycles';</script>"; exit;

    // -- Criteria CRUD -----------------------------------------
    } elseif ($action === 'seed_all_positions') {
        $cid = intval($_POST['target_cycle_id']);
        if ($cid) {
            $globals = $pdo->query("SELECT * FROM scoring_criteria WHERE cycle_id IS NULL AND position_rank IS NULL ORDER BY criteria_id")->fetchAll();
            $all_ranks = facultyRanks();
            $ins = $pdo->prepare("INSERT IGNORE INTO scoring_criteria (cycle_id, position_rank, kra_category, criterion_key, criterion_label, max_points, weight_pct, description, is_active, updated_by) VALUES (?,?,?,?,?,?,?,?,?,?)");
            $seeded = 0;
            foreach ($all_ranks as $rank) {
                $cnt = $pdo->prepare("SELECT COUNT(*) FROM scoring_criteria WHERE cycle_id=? AND position_rank=?");
                $cnt->execute([$cid, $rank]);
                if ((int)$cnt->fetchColumn() === 0) {
                    foreach ($globals as $g) {
                        $ins->execute([$cid, $rank, $g['kra_category'], $g['criterion_key'], $g['criterion_label'], $g['max_points'], $g['weight_pct'], $g['description'], $g['is_active'], $_SESSION['user_id']]);
                    }
                    $seeded++;
                }
            }
            logAudit($pdo, $_SESSION['user_id'], 'Criteria Seeded', "Seeded criteria for {$seeded} positions in cycle #{$cid}");
            flashMessage('success', "Criteria seeded for <strong>{$seeded}</strong> position(s). Each position now has its own editable criteria.");
        }
        echo "<script>window.location.href='index.php?page=cycles&cycle_id={$cid}';</script>"; exit;
    } elseif ($action === 'copy_defaults_to_cycle') {
        $cid      = intval($_POST['target_cycle_id']);
        $pos_rank = trim($_POST['position_rank'] ?? '') ?: null;
        if ($cid) {
            // Copy global criteria (matching position or position-agnostic) to this cycle+position
            if ($pos_rank) {
                $globals = $pdo->prepare("SELECT * FROM scoring_criteria WHERE cycle_id IS NULL AND (position_rank = ? OR position_rank IS NULL) ORDER BY position_rank DESC, criteria_id");
                $globals->execute([$pos_rank]);
                $globals = $globals->fetchAll();
            } else {
                $globals = $pdo->query("SELECT * FROM scoring_criteria WHERE cycle_id IS NULL AND position_rank IS NULL ORDER BY criteria_id")->fetchAll();
            }
            $ins = $pdo->prepare("INSERT IGNORE INTO scoring_criteria (cycle_id, position_rank, kra_category, criterion_key, criterion_label, max_points, weight_pct, description, is_active, updated_by) VALUES (?,?,?,?,?,?,?,?,?,?)");
            $copied = 0;
            foreach ($globals as $g) {
                $ins->execute([$cid, $pos_rank, $g['kra_category'], $g['criterion_key'], $g['criterion_label'], $g['max_points'], $g['weight_pct'], $g['description'], $g['is_active'], $_SESSION['user_id']]);
                $copied++;
            }
            $pos_label = $pos_rank ? " for <strong>{$pos_rank}</strong>" : '';
            logAudit($pdo, $_SESSION['user_id'], 'Criteria Copied', "Copied {$copied} defaults to cycle #{$cid}" . ($pos_rank ? " / {$pos_rank}" : ''));
            flashMessage('success', "Criteria copied{$pos_label}. You can now customise them per position.");
        }
        $redir = "index.php?page=cycles&cycle_id={$cid}" . ($pos_rank ? '&position=' . urlencode($pos_rank) : '');
        echo "<script>window.location.href='{$redir}';</script>"; exit;
    } elseif ($action === 'add_criterion') {
        $cid      = intval($_POST['cycle_id']) ?: null;
        $pos_rank = trim($_POST['position_rank'] ?? '') ?: null;
        $kra      = $_POST['kra_category'] ?? '';
        $key      = trim($_POST['criterion_key'] ?? '');
        $label    = trim($_POST['criterion_label'] ?? '');
        $max      = floatval($_POST['max_points'] ?? 0);
        $weight   = floatval($_POST['weight_pct'] ?? 100);
        $desc     = trim($_POST['description'] ?? '');
        if (in_array($kra, $kra_list) && $key && $label && $max > 0) {
            $pdo->prepare("INSERT INTO scoring_criteria (cycle_id, position_rank, kra_category, criterion_key, criterion_label, max_points, weight_pct, description, updated_by) VALUES (?,?,?,?,?,?,?,?,?)")
                ->execute([$cid, $pos_rank, $kra, $key, $label, $max, $weight, $desc, $_SESSION['user_id']]);
            $recalc_count = recalcApplicationsAfterCriteriaChange($pdo, $cid, $pos_rank);
            logAudit($pdo, $_SESSION['user_id'], 'Criterion Added', "{$label} ({$kra}) = {$max} pts" . ($cid ? " [cycle #{$cid}]" : " [global]") . ($pos_rank ? " [{$pos_rank}]" : ''));
            flashMessage('success', "Criterion <strong>{$label}</strong> added." . ($recalc_count ? " Recalculated {$recalc_count} affected application(s)." : ''));
        }
        $back = $cid ?: 0;
        $redir = "index.php?page=cycles&cycle_id={$back}" . ($pos_rank ? '&position=' . urlencode($pos_rank) : '');
        echo "<script>window.location.href='{$redir}';</script>"; exit;
    } elseif ($action === 'update_criterion') {
        $crit_id   = intval($_POST['criteria_id']);
        $label     = trim($_POST['criterion_label'] ?? '');
        $max_pts   = floatval($_POST['max_points'] ?? 0);
        $weight    = floatval($_POST['weight_pct'] ?? 100);
        $desc      = trim($_POST['description'] ?? '');
        $is_active = isset($_POST['is_active']) ? 1 : 0;
        $back_cid  = intval($_POST['back_cycle_id'] ?? 0);
        $back_pos  = trim($_POST['back_position'] ?? '');
        if ($label && $max_pts > 0) {
            $pdo->prepare("UPDATE scoring_criteria SET criterion_label=?, max_points=?, weight_pct=?, description=?, is_active=?, updated_by=? WHERE criteria_id=?")
                ->execute([$label, $max_pts, $weight, $desc, $is_active, $_SESSION['user_id'], $crit_id]);
            $recalc_count = recalcApplicationsAfterCriteriaChange($pdo, $back_cid ?: null, $back_pos ?: null);
            logAudit($pdo, $_SESSION['user_id'], 'Criterion Updated', "ID {$crit_id}: {$label}");
            flashMessage('success', "Criterion <strong>{$label}</strong> updated." . ($recalc_count ? " Recalculated {$recalc_count} affected application(s)." : ''));
        } elseif ($label && $max_pts <= 0) {
            flashMessage('danger', "Max Points must be greater than 0 for <strong>{$label}</strong>.");
        }
        $redir = "index.php?page=cycles&cycle_id={$back_cid}" . ($back_pos ? '&position=' . urlencode($back_pos) : '');
        echo "<script>window.location.href='{$redir}';</script>"; exit;
    } elseif ($action === 'delete_criterion') {
        $crit_id  = intval($_POST['criteria_id']);
        $back_cid = intval($_POST['back_cycle_id'] ?? 0);
        $back_pos = trim($_POST['back_position'] ?? '');
        $row = $pdo->prepare("SELECT criterion_label FROM scoring_criteria WHERE criteria_id=?");
        $row->execute([$crit_id]);
        $existing = $row->fetch();
        if ($existing) {
            $pdo->prepare("DELETE FROM scoring_criteria WHERE criteria_id=?")->execute([$crit_id]);
            $recalc_count = recalcApplicationsAfterCriteriaChange($pdo, $back_cid ?: null, $back_pos ?: null);
            logAudit($pdo, $_SESSION['user_id'], 'Criterion Deleted', "ID {$crit_id}: {$existing['criterion_label']}");
            flashMessage('success', 'Criterion deleted.' . ($recalc_count ? " Recalculated {$recalc_count} affected application(s)." : ''));
        }
        $redir = "index.php?page=cycles&cycle_id={$back_cid}" . ($back_pos ? '&position=' . urlencode($back_pos) : '');
        echo "<script>window.location.href='{$redir}';</script>"; exit;
    }

    echo "<script>window.location.href='index.php?page=cycles';</script>"; exit;
}

// -- Load cycles -----------------------------------------------
$cycles = $pdo->query("
    SELECT c.*, u.full_name,
        (SELECT COUNT(*) FROM applications a WHERE a.cycle_id=c.cycle_id AND a.status!='draft') as applied,
        (SELECT COUNT(*) FROM applications a WHERE a.cycle_id=c.cycle_id AND a.status='reclassified') as reclassified,
        (SELECT COUNT(*) FROM scoring_criteria sc WHERE sc.cycle_id=c.cycle_id) as criteria_count
    FROM cycles c LEFT JOIN users u ON c.created_by=u.user_id
    ORDER BY c.created_at DESC
")->fetchAll();

// Auto-select open cycle if none chosen
if (!$active_cycle_id) {
    foreach ($cycles as $cy) {
        if ($cy['status'] === 'open') { $active_cycle_id = $cy['cycle_id']; break; }
    }
}

// Find selected cycle
$panel_cycle = null;
foreach ($cycles as $cy) {
    if ($cy['cycle_id'] === $active_cycle_id) { $panel_cycle = $cy; break; }
}

$faculty_for_extension = [];
$cycle_extensions = [];
if ($panel_cycle) {
    try {
        $faculty_for_extension = $pdo->query("
            SELECT user_id, first_name, middle_name, last_name, full_name, email
            FROM users
            WHERE role='faculty' AND status='active'
            ORDER BY last_name, first_name, full_name
        ")->fetchAll();
    } catch (\Exception $e) {}
    try {
        $ext_stmt = $pdo->prepare("
            SELECT e.*, u.full_name, u.first_name, u.middle_name, u.last_name,
                   admin.full_name AS extended_by_name
            FROM cycle_submission_extensions e
            LEFT JOIN users u ON e.faculty_user_id = u.user_id
            LEFT JOIN users admin ON e.extended_by = admin.user_id
            WHERE e.cycle_id = ?
            ORDER BY e.created_at DESC
            LIMIT 12
        ");
        $ext_stmt->execute([$panel_cycle['cycle_id']]);
        $cycle_extensions = $ext_stmt->fetchAll();
    } catch (\Exception $e) {}
}

// Load criteria for selected cycle (always position-scoped)
$cycle_criteria = [];
$criteria_source = 'none';
if ($active_cycle_id) {
    if ($active_position) {
        // Load criteria specific to this cycle + position
        $stmt = $pdo->prepare("SELECT * FROM scoring_criteria WHERE cycle_id=? AND position_rank=? ORDER BY kra_category, criteria_id");
        $stmt->execute([$active_cycle_id, $active_position]);
        $cycle_criteria = $stmt->fetchAll();
        $criteria_source = count($cycle_criteria) > 0 ? 'cycle_position' : 'empty_position';
    } else {
        // Overview: show summary of all positions
        $criteria_source = 'overview';
    }
}
$grouped = [];
foreach ($cycle_criteria as $c) $grouped[$c['kra_category']][] = $c;

// Get all positions and their criteria counts for this cycle
// Auto-seed any missing positions on load
$position_counts = [];
if ($active_cycle_id) {
    $all_ranks = facultyRanks();
    foreach ($all_ranks as $rank) {
        $cnt = $pdo->prepare("SELECT COUNT(*) FROM scoring_criteria WHERE cycle_id=? AND position_rank=?");
        $cnt->execute([$active_cycle_id, $rank]);
        $position_counts[$rank] = (int)$cnt->fetchColumn();
    }

    // Auto-seed any position that has no criteria yet
    $missing = array_keys(array_filter($position_counts, fn($c) => $c === 0));
    if (!empty($missing)) {
        $globals = $pdo->query("SELECT * FROM scoring_criteria WHERE cycle_id IS NULL AND position_rank IS NULL ORDER BY criteria_id")->fetchAll();
        if ($globals) {
            $ins = $pdo->prepare("INSERT IGNORE INTO scoring_criteria (cycle_id, position_rank, kra_category, criterion_key, criterion_label, max_points, weight_pct, description, is_active, updated_by) VALUES (?,?,?,?,?,?,?,?,?,?)");
            foreach ($missing as $rank) {
                foreach ($globals as $g) {
                    $ins->execute([$active_cycle_id, $rank, $g['kra_category'], $g['criterion_key'], $g['criterion_label'], $g['max_points'], $g['weight_pct'], $g['description'], $g['is_active'], $_SESSION['user_id']]);
                }
                $position_counts[$rank] = count($globals);
            }
        }
    }
}
$positions_with_criteria = array_keys(array_filter($position_counts, fn($c) => $c > 0));
$positions_missing = array_keys(array_filter($position_counts, fn($c) => $c === 0));
