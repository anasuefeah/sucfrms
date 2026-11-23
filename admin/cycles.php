<?php
if (!isAdmin()) { echo '<div class="alert alert-danger">Access denied.</div>'; return; }

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

function validateCycleDates(?string $start, ?string $end, ?string $sub_start, ?string $sub_deadline, ?string $eval_deadline, ?string $appeal_deadline): array {
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
            ensureKraAssignmentTable($pdo);
            try {
            $pdo->beginTransaction();
            $lockedCycles = CriteriaSnapshots::lockScopes($pdo);
            $unfinishedCount = CriteriaSnapshots::closeOpenCycles($pdo, $lockedCycles);

            $pdo->prepare("INSERT INTO cycles (cycle_name, start_date, end_date, submission_start_date, submission_deadline, evaluation_deadline, appeal_deadline, status, created_by) VALUES (?,?,?,?,?,?,?,'open',?)")
                ->execute([$name, $start_date, $end_date, $sub_start, $deadline, $eval_deadline, $appeal_deadline, $_SESSION['user_id']]);
            $new_cid = (int)$pdo->lastInsertId();

            CriteriaSnapshots::initializeCycle($pdo,$new_cid,(int)$_SESSION['user_id']);

            logAudit($pdo, $_SESSION['user_id'], 'Cycle Created', "New cycle: {$name}. Period {$start_date} to {$end_date}; submissions {$sub_start} to {$deadline}; evaluation due {$eval_deadline}.");
            $checker_ids = $pdo->query("SELECT user_id FROM users WHERE role='evaluator' AND status='active'")->fetchAll(PDO::FETCH_COLUMN);
            if ($checker_ids) {
                $assign = $pdo->prepare("INSERT IGNORE INTO checker_kra_assignments (cycle_id, checker_id, kra_category, assigned_by) VALUES (?,?,?,?)");
                foreach ($checker_ids as $checker_id) {
                    foreach (array_keys(kraAssignmentCategories()) as $kra) {
                        $assign->execute([$new_cid, (int)$checker_id, $kra, $_SESSION['user_id']]);
                    }
                }
            }
            $pdo->commit();
            $successMsg = 'Cycle created with its own read-only criteria version. Faculty have been notified.';
            if ($unfinishedCount > 0) {
                $successMsg .= " <strong>Note:</strong> {$unfinishedCount} application(s) from the previous cycle were still pending evaluation when it was closed.";
            }
            flashMessage('success', $successMsg);
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                error_log('SUCFRMS cycle creation error: ' . $e->getMessage());
                flashMessage('danger', 'The cycle could not be created. Please try again.');
            }
        }
    } elseif ($action === 'update_status') {
        $cid    = intval($_POST['cycle_id']);
        $status = $_POST['status'];
        try {
            // Risk 2: Warn admin if closing/archiving with unfinished applications
            if (in_array($status, ['closed','archived'], true) && empty($_POST['confirm_close'])) {
                $uq = $pdo->prepare("SELECT COUNT(*) FROM applications WHERE cycle_id=? AND status IN ('submitted','under_review','needs_revision')");
                $uq->execute([$cid]);
                $unfinished = (int)$uq->fetchColumn();
                if ($unfinished > 0) {
                    flashMessage('warning', "{$unfinished} application(s) are still pending evaluation in this cycle. <form method='POST' style='display:inline;margin-left:8px;'><input type='hidden' name='action' value='update_status'><input type='hidden' name='cycle_id' value='{$cid}'><input type='hidden' name='status' value='{$status}'><input type='hidden' name='confirm_close' value='1'><button type='submit' class='btn btn-sm btn-warning'>Confirm and {$status} anyway</button></form>");
                    echo "<script>window.location.href='index.php?page=cycles&cycle_id={$cid}';</script>"; exit;
                }
            }
            CriteriaSnapshots::changeCycleStatus($pdo,$cid,(string)$status);
            logAudit($pdo,$_SESSION['user_id'],'Cycle Status Updated',"Cycle #{$cid} -> {$status}");
            flashMessage('success','Cycle status updated. Its criteria version has been preserved.');
        } catch (Throwable $e) {
            flashMessage('danger', htmlspecialchars($e->getMessage()));
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
            try {
                $pdo->beginTransaction();
                $old = $pdo->prepare("SELECT * FROM cycles WHERE cycle_id=? FOR UPDATE");
                $old->execute([$cid]);
                $old = $old->fetch();
                if (!$old) throw new DomainException('Selected cycle was not found.');
                $pdo->prepare("UPDATE cycles SET cycle_name=?, start_date=?, end_date=?, submission_start_date=?, submission_deadline=?, evaluation_deadline=?, appeal_deadline=? WHERE cycle_id=?")
                    ->execute([$name, $start, $end, $sub_start, $deadline, $eval_deadline, $appeal_deadline, $cid]);
                $changes = [];
                foreach (['cycle_name'=>$name,'start_date'=>$start,'end_date'=>$end,'submission_start_date'=>$sub_start,'submission_deadline'=>$deadline,'evaluation_deadline'=>$eval_deadline,'appeal_deadline'=>$appeal_deadline] as $field => $new_val) {
                    if (($old[$field] ?? null) != $new_val) $changes[] = "{$field}: " . ($old[$field] ?? 'blank') . " -> " . ($new_val ?? 'blank');
                }
                logAudit($pdo, $_SESSION['user_id'], 'Cycle Updated', "Cycle #{$cid} updated. " . ($changes ? implode('; ', $changes) : 'No date changes.'));
                $pdo->commit();
                flashMessage('success', 'Cycle <strong>' . htmlspecialchars($name) . '</strong> updated.');
            } catch (Throwable $error) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                error_log('SUCFRMS cycle update: ' . $error->getMessage());
                flashMessage('danger', 'The cycle could not be updated. Refresh the page and try again.');
            }
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
        $cid  = (int)$_POST['cycle_id'];
        $typed_name = trim($_POST['confirm_name'] ?? '');
        $typed_pass = $_POST['confirm_password'] ?? '';

        // Load cycle
        $cy_row = $pdo->prepare("SELECT cycle_name FROM cycles WHERE cycle_id=?");
        $cy_row->execute([$cid]);
        $cy = $cy_row->fetch();
        if (!$cy) {
            flashMessage('danger', 'Cycle not found.');
            echo "<script>window.location.href='index.php?page=cycles';</script>"; exit;
        }

        // Verify typed cycle name
        if (strtolower($typed_name) !== strtolower($cy['cycle_name'])) {
            flashMessage('danger', 'Cycle name does not match. Deletion cancelled.');
            echo "<script>window.location.href='index.php?page=cycles&cycle_id={$cid}';</script>"; exit;
        }

        // Verify admin password
        $me = $pdo->prepare("SELECT password FROM users WHERE user_id=?");
        $me->execute([$_SESSION['user_id']]);
        $me_row = $me->fetch();
        if (!$me_row || !password_verify($typed_pass, $me_row['password'])) {
            flashMessage('danger', 'Incorrect password. Deletion cancelled.');
            echo "<script>window.location.href='index.php?page=cycles&cycle_id={$cid}';</script>"; exit;
        }

        // Delete: cascade related data
        $pdo->beginTransaction();
        try {
            deleteCycleRecords($pdo, $cid);
            logAudit($pdo, $_SESSION['user_id'], 'Cycle Deleted', "Permanently deleted cycle #{$cid}: {$cy['cycle_name']}");
            $pdo->commit();
            flashMessage('success', "Cycle \"{$cy['cycle_name']}\" has been permanently deleted.");
        } catch (\Exception $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            flashMessage('danger', 'Deletion failed: ' . htmlspecialchars($e->getMessage()));
            echo "<script>window.location.href='index.php?page=cycles&cycle_id={$cid}';</script>"; exit;
        }
        echo "<script>window.location.href='index.php?page=cycles';</script>"; exit;

    } // end delete_cycle

    echo "<script>window.location.href='index.php?page=cycles';</script>"; exit;
}

// -- Load cycles -----------------------------------------------
$cycles = $pdo->query("
    SELECT c.*, u.full_name,
        (SELECT COUNT(*) FROM applications a WHERE a.cycle_id=c.cycle_id AND a.status!='draft') as applied,
        (SELECT COUNT(*) FROM applications a WHERE a.cycle_id=c.cycle_id AND a.status='reclassified') as reclassified,
        (SELECT COUNT(*) FROM scoring_criteria sc
            LEFT JOIN scoring_criteria parent ON parent.criteria_id=sc.parent_id
            WHERE sc.cycle_id=c.cycle_id AND sc.is_active=1
              AND (sc.parent_id IS NULL OR parent.is_active=1)) as criteria_count
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

?>

<?php showFlash(); ?>

<style>
.cycles-layout{display:grid;grid-template-columns:240px minmax(0,1fr);gap:.75rem;align-items:start;}
.cycles-layout > *{min-width:0;}
.cycle-detail-title{min-width:0;overflow-wrap:anywhere;}

@media(max-width:991.98px){
    .cycles-layout{grid-template-columns:minmax(0,1fr);}
    .cycle-list-scroll{max-height:16rem !important;}
    .cycle-detail-columns,.cycle-form-columns{grid-template-columns:minmax(0,1fr) !important;}
    .cycle-detail-columns > :first-child{border-right:0 !important;border-bottom:1px solid #f1f5f9;}
    .cycle-detail-columns > *,.cycle-form-columns > *{min-width:0;}
    .cycle-actions form{flex-wrap:wrap;}
    .cycle-delete-action{margin-left:0 !important;}
    .cycle-actions button,.cycle-actions select,.cycle-criteria-header a{min-height:44px;}
    .cycle-criteria-header{flex-wrap:wrap;gap:.65rem;padding:.75rem 1rem !important;}
    .cycle-history table{min-width:34rem;}
    #cycleReviewModal > div,#editCycleModal > div,#extendSubmissionModal > div,#deleteCycleModal > div{
        min-width:0;
        max-height:calc(100vh - 2rem);
        max-height:calc(100dvh - 2rem);
        overflow-y:auto !important;
    }
}
</style>

<!-- Page Header -->
<div style="background:#fff;border:1px solid #e2e8f0;padding:0.85rem 1.25rem;margin-bottom:1.25rem;
            display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:1rem;">
    <div style="display:flex;align-items:center;gap:0.75rem;">
        <div style="width:36px;height:36px;background:#1a3a6b;display:flex;align-items:center;justify-content:center;flex-shrink:0;">
            <i class="bi bi-calendar-range" style="color:#fff;font-size:0.9rem;"></i>
        </div>
        <div>
            <div style="font-size:0.92rem;font-weight:700;color:#0f172a;line-height:1.2;">Cycles</div>
            <div style="font-size:0.72rem;color:#94a3b8;margin-top:2px;">Create and manage evaluation cycles</div>
        </div>
    </div>
    <button class="btn btn-primary btn-sm rounded-0" data-bs-toggle="collapse" data-bs-target="#createCyclePanel"
            style="background:#1a3a6b;border:none;padding:0.45rem 1.1rem;font-size:0.8rem;font-weight:600;display:inline-flex;align-items:center;gap:0.3rem;">
        <i class="bi bi-plus-lg"></i> New Cycle
    </button>
</div>

<!-- Create Cycle (collapsible - closed by default) -->
<div class="collapse mb-4" id="createCyclePanel">
    <div style="border:1px solid #e2e8f0;overflow:hidden;">

        <!-- Panel header -->
        <div style="padding:0.85rem 1.25rem;border-bottom:1px solid #f1f5f9;background:#f8fafc;
                    display:flex;align-items:center;justify-content:space-between;">
            <div style="display:flex;align-items:center;gap:0.5rem;">
                <i class="bi bi-plus-circle" style="color:#1e4d8c;font-size:0.9rem;"></i>
                <span style="font-weight:700;color:#1a3a6b;font-size:0.88rem;">Create New Cycle</span>
            </div>
            <button type="button" class="btn-close btn-close-sm"
                    onclick="document.getElementById('createCyclePanel').classList.remove('show')"
                    style="font-size:0.65rem;opacity:0.4;" aria-label="Close"></button>
        </div>

        <div style="padding:1rem 1.1rem;">

            <?php
            $open_cycle = null;
            foreach ($cycles as $cy) { if ($cy['status'] === 'open') { $open_cycle = $cy; break; } }
            if ($open_cycle):
            ?>
            <div style="display:flex;align-items:flex-start;gap:0.5rem;background:#fffbeb;border:1px solid #fde68a;
                        border-left:3px solid #d97706;padding:0.5rem 0.75rem;margin-bottom:0.9rem;font-size:0.76rem;color:#92400e;">
                <i class="bi bi-exclamation-triangle-fill" style="color:#d97706;flex-shrink:0;margin-top:1px;"></i>
                <span>Creating a new cycle will <strong>automatically close</strong> the currently open cycle: <strong><?= sanitize($open_cycle['cycle_name']) ?></strong>.</span>
            </div>
            <?php endif; ?>

            <form method="POST" id="createCycleForm">
                <input type="hidden" name="action" value="create_cycle">

                <?php
                $lbl = 'display:block;font-size:0.65rem;font-weight:700;text-transform:uppercase;letter-spacing:0.06em;color:#6b7280;margin-bottom:4px;';
                $inp = 'width:100%;border:1px solid #e5e7eb;padding:0.35rem 0.5rem;font-size:0.82rem;box-sizing:border-box;outline:none;background:#fff;';
                ?>

                <!-- Cycle Name -->
                <div style="border:1px solid #e5e7eb;overflow:hidden;margin-bottom:0.6rem;">
                    <div style="background:#f9fafb;border-bottom:1px solid #e5e7eb;padding:0.3rem 0.75rem;">
                        <span style="font-size:0.65rem;font-weight:700;text-transform:uppercase;letter-spacing:0.08em;color:#374151;">Cycle Name</span>
                    </div>
                    <div style="padding:0.65rem 0.75rem;">
                        <input type="text" name="cycle_name" id="cc_name" required
                               placeholder="e.g. AY 2026-2029 Reclassification Cycle"
                               style="<?= $inp ?>"
                               onfocus="this.style.borderColor='#374151'" onblur="this.style.borderColor='#e5e7eb'">
                        <div style="font-size:0.65rem;color:#9ca3af;margin-top:4px;">The cycle will be set to <strong>Open</strong> immediately.</div>
                    </div>
                </div>

                <!-- Cycle Period -->
                <div style="border:1px solid #e5e7eb;overflow:hidden;margin-bottom:0.6rem;">
                    <div style="background:#f9fafb;border-bottom:1px solid #e5e7eb;padding:0.3rem 0.75rem;">
                        <span style="font-size:0.65rem;font-weight:700;text-transform:uppercase;letter-spacing:0.08em;color:#374151;">Cycle Period</span>
                    </div>
                    <div class="cycle-form-columns" style="padding:0.65rem 0.75rem;display:grid;grid-template-columns:1fr 1fr;gap:0.6rem;">
                        <div>
                            <label style="<?= $lbl ?>">Start Date <span style="color:#dc2626;">*</span></label>
                            <input type="date" name="start_date" id="cc_start" required style="<?= $inp ?>"
                                   onfocus="this.style.borderColor='#374151'" onblur="this.style.borderColor='#e5e7eb'">
                        </div>
                        <div>
                            <label style="<?= $lbl ?>">End Date <span style="color:#dc2626;">*</span></label>
                            <input type="date" name="end_date" id="cc_end" required style="<?= $inp ?>"
                                   onfocus="this.style.borderColor='#374151'" onblur="this.style.borderColor='#e5e7eb'">
                        </div>
                    </div>
                </div>

                <!-- Faculty Submission Window -->
                <div style="border:1px solid #e5e7eb;overflow:hidden;margin-bottom:0.6rem;">
                    <div style="background:#f9fafb;border-bottom:1px solid #e5e7eb;padding:0.3rem 0.75rem;">
                        <span style="font-size:0.65rem;font-weight:700;text-transform:uppercase;letter-spacing:0.08em;color:#374151;">Faculty Submission Window</span>
                    </div>
                    <div class="cycle-form-columns" style="padding:0.65rem 0.75rem;display:grid;grid-template-columns:1fr 1fr;gap:0.6rem;">
                        <div>
                            <label style="<?= $lbl ?>">Submission Start <span style="color:#dc2626;">*</span></label>
                            <input type="date" name="submission_start_date" id="cc_sub_start" required style="<?= $inp ?>"
                                   onfocus="this.style.borderColor='#374151'" onblur="this.style.borderColor='#e5e7eb'">
                        </div>
                        <div>
                            <label style="<?= $lbl ?>">Submission Deadline <span style="color:#dc2626;">*</span></label>
                            <input type="date" name="submission_deadline" id="cc_deadline" required style="<?= $inp ?>"
                                   onfocus="this.style.borderColor='#374151'" onblur="this.style.borderColor='#e5e7eb'">
                            <div style="font-size:0.62rem;color:#9ca3af;margin-top:3px;">Faculty cannot submit after this date.</div>
                        </div>
                    </div>
                </div>

                <!-- Deadlines -->
                <div style="border:1px solid #e5e7eb;overflow:hidden;margin-bottom:0.75rem;">
                    <div style="background:#f9fafb;border-bottom:1px solid #e5e7eb;padding:0.3rem 0.75rem;">
                        <span style="font-size:0.65rem;font-weight:700;text-transform:uppercase;letter-spacing:0.08em;color:#374151;">Deadlines</span>
                    </div>
                    <div class="cycle-form-columns" style="padding:0.65rem 0.75rem;display:grid;grid-template-columns:1fr 1fr;gap:0.6rem;">
                        <div>
                            <label style="<?= $lbl ?>">Evaluation Deadline <span style="color:#dc2626;">*</span></label>
                            <input type="date" name="evaluation_deadline" id="cc_eval_deadline" required style="<?= $inp ?>"
                                   onfocus="this.style.borderColor='#374151'" onblur="this.style.borderColor='#e5e7eb'">
                        </div>
                        <div>
                            <label style="<?= $lbl ?>">Appeal Deadline <span style="color:#9ca3af;font-weight:400;text-transform:none;font-size:0.62rem;">(optional)</span></label>
                            <input type="date" name="appeal_deadline" id="cc_appeal_deadline" style="<?= $inp ?>"
                                   onfocus="this.style.borderColor='#374151'" onblur="this.style.borderColor='#e5e7eb'">
                            <div style="font-size:0.62rem;color:#9ca3af;margin-top:3px;">Leave blank to allow appeals while the cycle is open.</div>
                        </div>
                    </div>
                </div>

                <!-- Inline error -->
                <div id="cycle_date_error" style="display:none;margin-bottom:0.6rem;">
                    <div style="background:#fef2f2;border:1px solid #fecaca;border-left:3px solid #dc2626;padding:0.45rem 0.75rem;font-size:0.78rem;color:#dc2626;display:flex;align-items:center;gap:0.4rem;">
                        <i class="bi bi-exclamation-circle" style="flex-shrink:0;"></i>
                        <span id="cycle_date_error_msg"></span>
                    </div>
                </div>

                <!-- Actions -->
                <div style="display:flex;align-items:center;gap:0.5rem;">
                    <button type="button" onclick="openCycleReview()"
                            style="padding:0.42rem 1rem;background:#1a3a6b;color:#fff;border:none;font-size:0.8rem;font-weight:700;cursor:pointer;display:inline-flex;align-items:center;gap:5px;"
                            onmouseover="this.style.background='#152d54'" onmouseout="this.style.background='#1a3a6b'">
                        <i class="bi bi-eye" style="font-size:0.75rem;"></i> Review &amp; Create
                    </button>
                    <button type="button"
                            style="padding:0.42rem 0.85rem;background:#fff;color:#374151;border:1px solid #e5e7eb;font-size:0.8rem;font-weight:600;cursor:pointer;"
                            onmouseover="this.style.background='#f9fafb'" onmouseout="this.style.background='#fff'"
                            onclick="document.getElementById('createCyclePanel').classList.remove('show')">
                        Cancel
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<div class="cycles-layout">

    <!-- LEFT: Cycle list -->
    <div>
        <div style="background:#fff;border:1px solid #e2e8f0;overflow:hidden;">
            <div style="padding:0.5rem 0.9rem;background:#1a3a6b;">
                <div style="font-size:0.65rem;font-weight:700;color:#fff;text-transform:uppercase;letter-spacing:0.08em;display:flex;align-items:center;gap:0.4rem;">
                    <i class="bi bi-list-ul"></i>All Cycles
                </div>
            </div>
            <?php if ($cycles): ?>
            <div class="cycle-list-scroll" style="overflow-y:auto;max-height:72vh;">
            <?php foreach ($cycles as $c):
                $is_sel = ($c['cycle_id'] === $active_cycle_id);
            ?>
            <a href="?page=cycles&cycle_id=<?= $c['cycle_id'] ?>"
               style="display:block;text-decoration:none;padding:0.6rem 0.85rem;border-bottom:1px solid #f1f5f9;
                      background:<?= $is_sel ? '#f0f4fb' : '#fff' ?>;
                      border-left:3px solid <?= $is_sel ? '#1a3a6b' : 'transparent' ?>;"
               onmouseover="if(!<?= $is_sel?'true':'false' ?>)this.style.background='#f8fafc'"
               onmouseout="if(!<?= $is_sel?'true':'false' ?>)this.style.background='#fff'">
                <div style="display:flex;justify-content:space-between;align-items:flex-start;gap:0.5rem;">
                    <div style="min-width:0;flex:1;">
                        <div style="font-size:0.78rem;font-weight:600;color:<?= $is_sel ? '#1a3a6b' : '#1e293b' ?>;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">
                            <?= sanitize($c['cycle_name']) ?>
                        </div>
                        <?php if (!empty($c['start_date']) && !empty($c['end_date'])): ?>
                        <div style="font-size:0.65rem;color:#94a3b8;margin-top:2px;">
                            <?= date('Y', strtotime($c['start_date'])) ?> &ndash; <?= date('Y', strtotime($c['end_date'])) ?>
                        </div>
                        <?php endif; ?>
                        <div style="display:flex;gap:0.3rem;flex-wrap:wrap;margin-top:4px;">
                            <?php
                            $sc_colors = ['open'=>['#dcfce7','#16a34a'],'closed'=>['#f1f5f9','#64748b'],'archived'=>['#f8fafc','#94a3b8']];
                            [$scb,$scc] = $sc_colors[$c['status']] ?? ['#f1f5f9','#64748b'];
                            ?>
                            <span style="background:<?= $scb ?>;color:<?= $scc ?>;font-size:0.58rem;font-weight:700;padding:1px 6px;"><?= ucfirst($c['status']) ?></span>
                            <?php if ($c['criteria_count'] > 0): ?>
                            <span style="background:#eff6ff;color:#1e4d8c;font-size:0.58rem;font-weight:700;padding:1px 6px;"><?= $c['criteria_count'] ?> criteria</span>
                            <?php else: ?>
                            <span style="background:#fef3c7;color:#92400e;font-size:0.58rem;font-weight:700;padding:1px 6px;">No criteria</span>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div style="text-align:right;font-size:0.62rem;color:#94a3b8;white-space:nowrap;flex-shrink:0;">
                        <div><?= $c['applied'] ?> applied</div>
                        <div><?= $c['reclassified'] ?> done</div>
                    </div>
                </div>
            </a>
            <?php endforeach; ?>
            </div>
            <?php else: ?>
            <div style="padding:1.5rem;text-align:center;font-size:0.78rem;color:#94a3b8;">No cycles yet. Create one above.</div>
            <?php endif; ?>
        </div>
    </div>

    <!-- RIGHT: Cycle detail + criteria -->
    <div>
    <?php if ($panel_cycle): ?>

        <!-- Cycle header card -->
        <div style="background:#fff;border:1px solid #e2e8f0;margin-bottom:1rem;overflow:hidden;">
            <!-- Cycle title bar -->
            <div style="padding:0.75rem 1.25rem;background:#1a3a6b;display:flex;align-items:center;justify-content:space-between;gap:0.75rem;flex-wrap:wrap;">
                <div class="cycle-detail-title" style="font-weight:700;color:#fff;font-size:0.95rem;"><?= sanitize($panel_cycle['cycle_name']) ?></div>
                <?php
                $sc_colors2 = ['open'=>['#dcfce7','#16a34a'],'closed'=>['#f1f5f9','#64748b'],'archived'=>['#f8fafc','#94a3b8']];
                [$scb2,$scc2] = $sc_colors2[$panel_cycle['status']] ?? ['#f1f5f9','#64748b'];
                ?>
                <span style="background:<?= $scb2 ?>;color:<?= $scc2 ?>;font-size:0.7rem;font-weight:700;padding:2px 10px;"><?= ucfirst($panel_cycle['status']) ?></span>
            </div>

            <!-- Stats + Dates two-column -->
            <div class="cycle-detail-columns" style="display:grid;grid-template-columns:1fr 1fr;border-bottom:1px solid #f1f5f9;">
                <div style="padding:0.85rem 1.25rem;border-right:1px solid #f1f5f9;">
                    <div style="font-size:0.6rem;font-weight:700;text-transform:uppercase;letter-spacing:0.07em;color:#94a3b8;margin-bottom:0.5rem;">Statistics</div>
                    <div style="font-size:0.82rem;color:#1e293b;display:flex;flex-direction:column;gap:3px;">
                        <div><i class="bi bi-file-earmark-text me-1" style="color:#1a3a6b;"></i><?= $panel_cycle['applied'] ?> applications</div>
                        <div><i class="bi bi-check-circle me-1" style="color:#16a34a;"></i><?= $panel_cycle['reclassified'] ?> evaluation complete</div>
                    </div>
                    <div style="font-size:0.7rem;color:#94a3b8;margin-top:5px;">Created by <?= sanitize($panel_cycle['full_name'] ?? 'System') ?></div>
                </div>
                <div style="padding:0.85rem 1.25rem;">
                    <div style="font-size:0.6rem;font-weight:700;text-transform:uppercase;letter-spacing:0.07em;color:#94a3b8;margin-bottom:0.5rem;">Cycle Dates</div>
                    <div style="font-size:0.78rem;color:#1e293b;display:flex;flex-direction:column;gap:3px;">
                        <?php if (!empty($panel_cycle['start_date']) && !empty($panel_cycle['end_date'])): ?>
                        <div><i class="bi bi-calendar-event me-1" style="color:#1a3a6b;"></i><span style="color:#64748b;">Cycle:</span> <strong><?= date('M j, Y', strtotime($panel_cycle['start_date'])) ?> to <?= date('M j, Y', strtotime($panel_cycle['end_date'])) ?></strong></div>
                        <?php endif; ?>
                        <div><i class="bi bi-upload me-1" style="color:#1a3a6b;"></i><span style="color:#64748b;">Submission:</span> <strong><?= !empty($panel_cycle['submission_start_date']) ? date('M j, Y', strtotime($panel_cycle['submission_start_date'])) : 'N/A' ?> to <?= !empty($panel_cycle['submission_deadline']) ? date('M j, Y', strtotime($panel_cycle['submission_deadline'])) : 'N/A' ?></strong></div>
                        <div><i class="bi bi-clock me-1" style="color:#d97706;"></i><span style="color:#64748b;">Evaluation Due:</span> <strong><?= !empty($panel_cycle['evaluation_deadline']) ? date('M j, Y', strtotime($panel_cycle['evaluation_deadline'])) : 'N/A' ?></strong></div>
                        <div><i class="bi bi-chat-square-text me-1" style="color:#1a3a6b;"></i><span style="color:#64748b;">Appeals Due:</span> <strong><?= !empty($panel_cycle['appeal_deadline']) ? date('M j, Y', strtotime($panel_cycle['appeal_deadline'])) : 'While cycle is open' ?></strong></div>
                    </div>
                </div>
            </div>

            <!-- Action buttons -->
            <div class="cycle-actions" style="padding:0.65rem 1.25rem;display:flex;gap:0.5rem;flex-wrap:wrap;align-items:center;background:#f8fafc;border-bottom:1px solid #f1f5f9;">
                <button type="button" onclick="openEditCycleModal()"
                        style="padding:0.35rem 0.85rem;border:1px solid #e2e8f0;background:#fff;font-size:0.75rem;font-weight:600;cursor:pointer;color:#475569;display:inline-flex;align-items:center;gap:4px;"
                        onmouseover="this.style.background='#1a3a6b';this.style.color='#fff';this.style.borderColor='#1a3a6b'"
                        onmouseout="this.style.background='#fff';this.style.color='#475569';this.style.borderColor='#e2e8f0'">
                    <i class="bi bi-pencil" style="font-size:0.7rem;"></i>Edit Cycle
                </button>
                <button type="button" onclick="openExtendSubmissionModal()"
                        style="padding:0.35rem 0.85rem;border:1px solid #e2e8f0;background:#fff;font-size:0.75rem;font-weight:600;cursor:pointer;color:#475569;display:inline-flex;align-items:center;gap:4px;"
                        onmouseover="this.style.background='#1a3a6b';this.style.color='#fff';this.style.borderColor='#1a3a6b'"
                        onmouseout="this.style.background='#fff';this.style.color='#475569';this.style.borderColor='#e2e8f0'">
                    <i class="bi bi-clock-history" style="font-size:0.7rem;"></i>Extend Submission
                </button>
                <form method="POST" class="d-flex gap-1 align-items-center" id="statusForm_<?= $panel_cycle['cycle_id'] ?>">
                    <input type="hidden" name="action" value="update_status">
                    <input type="hidden" name="cycle_id" value="<?= $panel_cycle['cycle_id'] ?>">
                    <select name="status" style="border:1px solid #e2e8f0;padding:0.32rem 0.5rem;font-size:0.75rem;background:#fff;">
                        <option value="open"     <?= $panel_cycle['status']==='open'     ?'selected':'' ?>>Open</option>
                        <option value="closed"   <?= $panel_cycle['status']==='closed'   ?'selected':'' ?>>Closed</option>
                        <option value="archived" <?= $panel_cycle['status']==='archived' ?'selected':'' ?>>Archived</option>
                    </select>
                    <button type="button"
                            style="padding:0.35rem 0.85rem;background:#1a3a6b;color:#fff;border:none;font-size:0.75rem;font-weight:600;cursor:pointer;display:inline-flex;align-items:center;gap:4px;"
                            onclick="confirmDelete('Update status for this cycle?','statusForm_<?= $panel_cycle['cycle_id'] ?>','Update','bi-check-circle')">
                        <i class="bi bi-check-circle" style="font-size:0.7rem;"></i>Update Status
                    </button>
                </form>
                <!-- Delete Cycle button opens the verification modal -->
                <div class="cycle-delete-action" style="margin-left:auto;">
                    <button type="button" id="deleteCycleBtn"
                            data-cycle-id="<?= (int)$panel_cycle['cycle_id'] ?>"
                            data-cycle-name="<?= htmlspecialchars($panel_cycle['cycle_name'], ENT_QUOTES) ?>"
                            data-applied="<?= (int)$panel_cycle['applied'] ?>"
                            style="padding:0.35rem 0.85rem;background:#fff;color:#dc2626;border:1px solid #fecaca;font-size:0.75rem;font-weight:600;cursor:pointer;display:inline-flex;align-items:center;gap:4px;"
                            onmouseover="this.style.background='#fef2f2'" onmouseout="this.style.background='#fff'"
                            onclick="openDeleteCycleModalFromBtn(this)">
                        <i class="bi bi-trash" style="font-size:0.7rem;"></i> Delete Cycle
                    </button>
                </div>
            </div>

            <?php if ($cycle_extensions): ?>
            <div style="padding:0.75rem 1.25rem;">
                <div style="font-size:0.6rem;font-weight:700;text-transform:uppercase;letter-spacing:0.07em;color:#94a3b8;margin-bottom:0.5rem;">Extension History</div>
                <div class="table-responsive cycle-history" tabindex="0" role="region" aria-label="Extension History">
                    <table style="width:100%;border-collapse:collapse;font-size:0.78rem;">
                        <thead>
                        <tr style="background:#f8fafc;border-bottom:1px solid #e2e8f0;">
                            <th style="padding:0.4rem 0.75rem;text-align:left;font-size:0.6rem;font-weight:700;text-transform:uppercase;letter-spacing:0.06em;color:#94a3b8;">Scope</th>
                            <th style="padding:0.4rem 0.75rem;text-align:left;font-size:0.6rem;font-weight:700;text-transform:uppercase;letter-spacing:0.06em;color:#94a3b8;">Previous</th>
                            <th style="padding:0.4rem 0.75rem;text-align:left;font-size:0.6rem;font-weight:700;text-transform:uppercase;letter-spacing:0.06em;color:#94a3b8;">New Deadline</th>
                            <th style="padding:0.4rem 0.75rem;text-align:left;font-size:0.6rem;font-weight:700;text-transform:uppercase;letter-spacing:0.06em;color:#94a3b8;">Extended By</th>
                            <th style="padding:0.4rem 0.75rem;text-align:left;font-size:0.6rem;font-weight:700;text-transform:uppercase;letter-spacing:0.06em;color:#94a3b8;">When</th>
                        </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($cycle_extensions as $ext): ?>
                        <tr style="border-bottom:1px solid #f1f5f9;">
                            <td style="padding:0.45rem 0.75rem;color:#334155;">
                                <?= !empty($ext['applies_to_all']) ? 'All Faculty' : sanitize(formatDisplayName($ext) ?: ($ext['full_name'] ?? 'Selected Faculty')) ?>
                                <?php if (!empty($ext['reason'])): ?><div style="font-size:0.68rem;color:#94a3b8;"><?= sanitize($ext['reason']) ?></div><?php endif; ?>
                            </td>
                            <td style="padding:0.45rem 0.75rem;color:#64748b;"><?= date('M j, Y', strtotime($ext['previous_deadline'])) ?></td>
                            <td style="padding:0.45rem 0.75rem;font-weight:600;color:#1e293b;"><?= date('M j, Y', strtotime($ext['new_deadline'])) ?></td>
                            <td style="padding:0.45rem 0.75rem;color:#64748b;"><?= sanitize($ext['extended_by_name'] ?? 'Admin') ?></td>
                            <td style="padding:0.45rem 0.75rem;color:#94a3b8;font-size:0.72rem;"><?= date('M j, Y g:i A', strtotime($ext['created_at'])) ?></td>
                        </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
            <?php endif; ?>
        </div>

        <!-- Scoring Criteria link tile -->
        <div style="border:1px solid #e2e8f0;background:#fff;overflow:hidden;">
            <div class="cycle-criteria-header" style="padding:0 1.25rem;border-bottom:1px solid #e2e8f0;background:#f8fafc;
                        display:flex;align-items:center;justify-content:space-between;min-height:48px;">
                <div style="display:flex;align-items:center;gap:0.6rem;">
                    <div style="width:28px;height:28px;background:#1a3a6b;display:flex;align-items:center;justify-content:center;flex-shrink:0;">
                        <i class="bi bi-sliders2" style="color:#fff;font-size:0.72rem;"></i>
                    </div>
                    <div>
                        <div style="font-weight:700;color:#1a3a6b;font-size:0.82rem;line-height:1.2;">Scoring Criteria</div>
                        <div style="font-size:0.65rem;color:#94a3b8;margin-top:1px;">Configure scoring criteria per faculty position for this cycle</div>
                    </div>
                </div>
                <a href="index.php?page=scoring_criteria&cycle_id=<?= (int)$panel_cycle['cycle_id'] ?>"
                   style="display:inline-flex;align-items:center;gap:5px;padding:0.38rem 0.9rem;
                          background:#1a3a6b;color:#fff;text-decoration:none;font-size:0.78rem;font-weight:700;flex-shrink:0;"
                   onmouseover="this.style.background='#152d54'" onmouseout="this.style.background='#1a3a6b'">
                    <i class="bi bi-arrow-right-square" style="font-size:0.72rem;"></i> Manage Criteria
                </a>
            </div>
            <div style="padding:0.75rem 1.25rem;display:flex;align-items:center;gap:1.5rem;flex-wrap:wrap;">
                <?php
                $crit_total = (int)($panel_cycle['criteria_count'] ?? 0);
                $crit_positions = 0;
                if ($crit_total > 0) {
                    $cp = $pdo->prepare("SELECT COUNT(DISTINCT sc.position_rank) FROM scoring_criteria sc
                        LEFT JOIN scoring_criteria parent ON parent.criteria_id=sc.parent_id
                        WHERE sc.cycle_id=? AND sc.is_active=1 AND (sc.parent_id IS NULL OR parent.is_active=1)");
                    $cp->execute([(int)$panel_cycle['cycle_id']]);
                    $crit_positions = (int)$cp->fetchColumn();
                }
                ?>
                <div style="display:flex;align-items:center;gap:0.4rem;">
                    <i class="bi bi-check-square" style="color:<?= $crit_total>0?'#1a3a6b':'#94a3b8' ?>;font-size:0.82rem;"></i>
                    <span style="font-size:0.78rem;color:#475569;font-weight:600;"><?= $crit_total ?> criteria</span>
                </div>
                <div style="display:flex;align-items:center;gap:0.4rem;">
                    <i class="bi bi-people" style="color:<?= $crit_positions>0?'#1a3a6b':'#94a3b8' ?>;font-size:0.82rem;"></i>
                    <span style="font-size:0.78rem;color:#475569;font-weight:600;"><?= $crit_positions ?> / 19 positions configured</span>
                </div>
                <?php if ($crit_total === 0): ?>
                <span style="font-size:0.68rem;color:#dc2626;font-weight:700;padding:2px 8px;background:#fef2f2;border:1px solid #fecaca;">
                    <i class="bi bi-exclamation-triangle" style="margin-right:3px;"></i>No criteria set
                </span>
                <?php endif; ?>
            </div>
        </div>

    <?php else: ?>
    <div style="border:1px solid #e2e8f0;background:#fff;min-height:300px;display:flex;flex-direction:column;align-items:center;justify-content:center;padding:3rem;text-align:center;">
        <i class="bi bi-calendar-range" style="font-size:2.5rem;color:#dbeafe;display:block;margin-bottom:0.75rem;"></i>
        <p style="font-weight:600;color:#475569;margin-bottom:0.25rem;">Select a cycle to manage it</p>
        <p style="color:#94a3b8;font-size:0.82rem;">Or create a new cycle using the button above.</p>
    </div>
    <?php endif; ?>
    </div><!-- right panel -->

</div><!-- grid -->

<!-- Cycle Review Modal -->
<div id="cycleReviewModal"
     style="display:none;position:fixed;inset:0;z-index:9999;background:rgba(0,0,0,0.5);
            align-items:center;justify-content:center;padding:1rem;">
    <div style="background:#fff;border:1px solid #e2e8f0;width:100%;max-width:500px;
                box-shadow:0 16px 48px rgba(0,0,0,0.18);overflow:hidden;">

        <!-- Header -->
        <div style="background:#1a3a6b;padding:0.8rem 1.1rem;
                    display:flex;align-items:center;justify-content:space-between;">
            <div>
                <div style="font-weight:700;color:#fff;font-size:0.88rem;">Review New Cycle</div>
                <div style="color:rgba(255,255,255,0.55);font-size:0.68rem;margin-top:2px;">Confirm the details before opening</div>
            </div>
            <button onclick="document.getElementById('cycleReviewModal').style.display='none'"
                    style="background:none;border:none;color:rgba(255,255,255,0.7);font-size:1.1rem;
                           cursor:pointer;line-height:1;padding:0;"
                    onmouseover="this.style.color='#fff'"
                    onmouseout="this.style.color='rgba(255,255,255,0.7)'">
                <i class="bi bi-x-lg"></i>
            </button>
        </div>

        <!-- Warning if open cycle exists -->
        <?php if ($open_cycle): ?>
        <div style="background:#fffbeb;border-bottom:1px solid #fde68a;padding:0.55rem 1.1rem;
                    font-size:0.78rem;color:#92400e;">
            Creating this cycle will <strong>automatically close</strong> the currently open cycle: <strong><?= sanitize($open_cycle['cycle_name']) ?></strong>.
        </div>
        <?php endif; ?>

        <!-- Body -->
        <div style="padding:1.1rem;">

            <!-- Summary table -->
            <div style="border:1px solid #e2e8f0;overflow:hidden;margin-bottom:0.85rem;">

                <!-- Cycle name row -->
                <div style="padding:0.65rem 1rem;border-bottom:1px solid #f1f5f9;">
                    <div style="font-size:0.62rem;color:#94a3b8;font-weight:700;text-transform:uppercase;letter-spacing:0.06em;margin-bottom:2px;">Cycle Name</div>
                    <div style="display:flex;align-items:center;justify-content:space-between;gap:0.75rem;">
                        <div id="rv_name" style="font-size:0.88rem;font-weight:700;color:#0f172a;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;"></div>
                        <span style="font-size:0.65rem;font-weight:600;color:#475569;background:#f1f5f9;
                                     border:1px solid #e2e8f0;padding:2px 9px;white-space:nowrap;flex-shrink:0;">
                            Opens immediately
                        </span>
                    </div>
                </div>

                <!-- 2-col date grid -->
                <div class="cycle-form-columns" style="display:grid;grid-template-columns:1fr 1fr;border-bottom:1px solid #f1f5f9;">
                    <div style="padding:0.65rem 1rem;border-right:1px solid #f1f5f9;">
                        <div style="font-size:0.62rem;color:#94a3b8;font-weight:700;text-transform:uppercase;letter-spacing:0.05em;margin-bottom:3px;">Start Date</div>
                        <div id="rv_start" style="font-size:0.82rem;font-weight:600;color:#1e293b;"></div>
                    </div>
                    <div style="padding:0.65rem 1rem;">
                        <div style="font-size:0.62rem;color:#94a3b8;font-weight:700;text-transform:uppercase;letter-spacing:0.05em;margin-bottom:3px;">End Date</div>
                        <div id="rv_end" style="font-size:0.82rem;font-weight:600;color:#1e293b;"></div>
                    </div>
                </div>

                <div class="cycle-form-columns" style="display:grid;grid-template-columns:1fr 1fr;border-bottom:1px solid #f1f5f9;">
                    <div style="padding:0.65rem 1rem;border-right:1px solid #f1f5f9;">
                        <div style="font-size:0.62rem;color:#94a3b8;font-weight:700;text-transform:uppercase;letter-spacing:0.05em;margin-bottom:3px;">Submission Start</div>
                        <div id="rv_sub_start" style="font-size:0.82rem;font-weight:600;color:#1e293b;"></div>
                    </div>
                    <div style="padding:0.65rem 1rem;">
                        <div style="font-size:0.62rem;color:#94a3b8;font-weight:700;text-transform:uppercase;letter-spacing:0.05em;margin-bottom:3px;">Submission Deadline</div>
                        <div id="rv_deadline" style="font-size:0.82rem;font-weight:600;color:#1e293b;"></div>
                    </div>
                </div>

                <div style="padding:0.65rem 1rem;">
                    <div style="font-size:0.62rem;color:#94a3b8;font-weight:700;text-transform:uppercase;letter-spacing:0.05em;margin-bottom:3px;">Evaluation Deadline</div>
                    <div style="display:flex;align-items:center;gap:0.6rem;flex-wrap:wrap;">
                        <span id="rv_eval_deadline" style="font-size:0.82rem;font-weight:600;color:#1e293b;"></span>
                        <span id="rv_deadline_note" style="font-size:0.72rem;color:#64748b;"></span>
                    </div>
                </div>
            </div>

            <!-- Criteria note -->
            <div style="background:#f8fafc;border:1px solid #e2e8f0;border-left:3px solid #1a3a6b;
                        padding:0.6rem 0.85rem;font-size:0.78rem;color:#475569;margin-bottom:1rem;">
                The cycle receives a <strong>read-only copy</strong> of the default criteria for all 19 position ranks. You can edit the defaults again when no cycle is open.
            </div>

            <!-- Action buttons -->
            <div style="display:flex;gap:0.5rem;">
                <button type="button"
                        onclick="document.getElementById('cycleReviewModal').style.display='none'"
                        style="flex:1;padding:0.45rem 1rem;border:1px solid #e2e8f0;background:#fff;color:#475569;font-size:0.82rem;font-weight:600;cursor:pointer;"
                        onmouseover="this.style.background='#f8fafc'"
                        onmouseout="this.style.background='#fff'">
                    &larr; Go Back
                </button>
                <button type="button" onclick="confirmCreateCycle()"
                        style="flex:2;padding:0.45rem 1rem;border:none;
                               background:#1a3a6b;color:#fff;font-size:0.82rem;font-weight:700;
                               cursor:pointer;"
                        onmouseover="this.style.background='#152d54'"
                        onmouseout="this.style.background='#1a3a6b'">
                    Confirm &amp; Open Cycle
                </button>
            </div>
        </div>

    </div>
</div>

<!-- Edit Cycle Modal -->
<div id="editCycleModal"
     style="display:none;position:fixed;inset:0;z-index:9999;background:rgba(0,0,0,0.5);
            align-items:center;justify-content:center;padding:1rem;">
    <div style="background:#fff;border:1px solid #e2e8f0;width:100%;max-width:540px;max-height:calc(100vh - 2rem);
                box-shadow:0 16px 48px rgba(0,0,0,0.18);overflow-y:auto;">
        <div style="background:#1a3a6b;padding:0.8rem 1.1rem;
                    display:flex;align-items:center;justify-content:space-between;">
            <div style="display:flex;align-items:center;gap:0.5rem;">
                <i class="bi bi-pencil-square" style="color:#fff;font-size:0.85rem;"></i>
                <span style="color:#fff;font-weight:700;font-size:0.88rem;">Edit Cycle</span>
            </div>
            <button onclick="document.getElementById('editCycleModal').style.display='none'"
                    style="background:none;border:none;color:rgba(255,255,255,0.7);font-size:1.1rem;cursor:pointer;line-height:1;padding:0;"
                    onmouseover="this.style.color='#fff'" onmouseout="this.style.color='rgba(255,255,255,0.7)'">&times;</button>
        </div>
        <form method="POST" style="padding:1.5rem;">
            <input type="hidden" name="action" value="update_cycle">
            <input type="hidden" name="cycle_id" value="<?= $panel_cycle ? $panel_cycle['cycle_id'] : 0 ?>">
            <div class="mb-3">
                <label class="form-label fw-semibold small">Cycle Name <span class="text-danger">*</span></label>
                <input type="text" name="cycle_name" id="ec_name" class="form-control" required
                       value="<?= htmlspecialchars($panel_cycle['cycle_name'] ?? '') ?>">
            </div>
            <div class="row g-3 mb-3">
                <div class="col-sm-6">
                    <label class="form-label fw-semibold small">Start Date <span class="text-danger">*</span></label>
                    <input type="date" name="start_date" id="ec_start" class="form-control" required
                           value="<?= htmlspecialchars($panel_cycle['start_date'] ?? '') ?>">
                </div>
                <div class="col-sm-6">
                    <label class="form-label fw-semibold small">End Date <span class="text-danger">*</span></label>
                    <input type="date" name="end_date" id="ec_end" class="form-control" required
                           value="<?= htmlspecialchars($panel_cycle['end_date'] ?? '') ?>">
                </div>
                <div class="col-sm-6">
                    <label class="form-label fw-semibold small">Submission Start <span class="text-danger">*</span></label>
                    <input type="date" name="submission_start_date" id="ec_sub_start" class="form-control" required
                           value="<?= htmlspecialchars($panel_cycle['submission_start_date'] ?? '') ?>">
                </div>
                <div class="col-sm-6">
                    <label class="form-label fw-semibold small">Submission Deadline <span class="text-danger">*</span></label>
                    <input type="date" name="submission_deadline" id="ec_deadline" class="form-control" required
                           value="<?= htmlspecialchars($panel_cycle['submission_deadline'] ?? '') ?>">
                </div>
                <div class="col-sm-6">
                    <label class="form-label fw-semibold small">Evaluation Deadline <span class="text-danger">*</span></label>
                    <input type="date" name="evaluation_deadline" id="ec_eval_deadline" class="form-control" required
                           value="<?= htmlspecialchars($panel_cycle['evaluation_deadline'] ?? '') ?>">
                </div>
                <div class="col-sm-6">
                    <label class="form-label fw-semibold small">Appeal Deadline</label>
                    <input type="date" name="appeal_deadline" id="ec_appeal_deadline" class="form-control"
                           value="<?= htmlspecialchars($panel_cycle['appeal_deadline'] ?? '') ?>">
                </div>
            </div>
            <div id="ec_error" style="display:none;margin-bottom:0.75rem;padding:0.5rem 0.85rem;
                 background:#fef2f2;border:1px solid #fecaca;border-left:3px solid #dc2626;font-size:0.8rem;color:#dc2626;"></div>
            <div style="display:flex;gap:0.5rem;justify-content:flex-end;">
                <button type="button"
                        onclick="document.getElementById('editCycleModal').style.display='none'"
                        style="padding:0.42rem 1rem;border:1px solid #e2e8f0;background:#fff;color:#475569;font-size:0.8rem;font-weight:600;cursor:pointer;"
                        onmouseover="this.style.background='#f8fafc'" onmouseout="this.style.background='#fff'">
                    Cancel
                </button>
                <button type="button" onclick="submitEditCycle()"
                        style="padding:0.42rem 1.15rem;border:none;background:#1a3a6b;color:#fff;font-size:0.8rem;font-weight:700;cursor:pointer;display:inline-flex;align-items:center;gap:5px;"
                        onmouseover="this.style.background='#152d54'" onmouseout="this.style.background='#1a3a6b'">
                    <i class="bi bi-save" style="font-size:0.75rem;"></i> Save Changes
                </button>
            </div>
        </form>
    </div>
</div>

<!-- Extend Submission Modal -->
<?php if ($panel_cycle): ?>
<div id="extendSubmissionModal"
     style="display:none;position:fixed;inset:0;z-index:9999;background:rgba(0,0,0,0.5);
            align-items:center;justify-content:center;padding:1rem;">
    <div style="background:#fff;border:1px solid #e2e8f0;width:100%;max-width:620px;
                box-shadow:0 16px 48px rgba(0,0,0,0.18);overflow:hidden;">
        <div style="background:#1a3a6b;padding:0.8rem 1.1rem;
                    display:flex;align-items:center;justify-content:space-between;">
            <div style="display:flex;align-items:center;gap:0.5rem;">
                <i class="bi bi-clock-history" style="color:#fff;font-size:0.85rem;"></i>
                <span style="color:#fff;font-weight:700;font-size:0.88rem;">Extend Faculty Submission</span>
            </div>
            <button onclick="document.getElementById('extendSubmissionModal').style.display='none'"
                    style="background:none;border:none;color:rgba(255,255,255,0.7);font-size:1.1rem;cursor:pointer;line-height:1;padding:0;"
                    onmouseover="this.style.color='#fff'" onmouseout="this.style.color='rgba(255,255,255,0.7)'">&times;</button>
        </div>
        <form method="POST" id="extendSubmissionForm" style="padding:1.5rem;">
            <input type="hidden" name="action" value="extend_submission">
            <input type="hidden" name="cycle_id" value="<?= (int)$panel_cycle['cycle_id'] ?>">
            <input type="hidden" name="confirm_past_eval" id="confirm_past_eval" value="0">
            <div class="row g-3">
                <div class="col-sm-6">
                    <label class="form-label fw-semibold small">Current Deadline</label>
                    <input type="text" class="form-control" readonly
                           value="<?= !empty($panel_cycle['submission_deadline']) ? date('M j, Y', strtotime($panel_cycle['submission_deadline'])) : 'N/A' ?>">
                </div>
                <div class="col-sm-6">
                    <label class="form-label fw-semibold small">New Deadline <span class="text-danger">*</span></label>
                    <input type="date" name="new_deadline" id="ext_new_deadline" class="form-control" required>
                </div>
                <div class="col-12">
                    <label class="form-label fw-semibold small">Scope <span class="text-danger">*</span></label>
                    <select name="extension_scope" id="extension_scope" class="form-select" onchange="toggleFacultyPicker()">
                        <option value="all">All faculty</option>
                        <option value="selected">Selected faculty only</option>
                    </select>
                </div>
                <div class="col-12" id="faculty_picker_wrap" style="display:none;">
                    <label class="form-label fw-semibold small">Faculty</label>
                    <select name="faculty_ids[]" class="form-select" multiple size="6">
                        <?php foreach ($faculty_for_extension as $fac): ?>
                        <option value="<?= (int)$fac['user_id'] ?>">
                            <?= sanitize(formatDisplayName($fac) ?: ($fac['full_name'] ?? $fac['email'])) ?> - <?= sanitize($fac['email'] ?? '') ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                    <div class="form-text">Hold Ctrl while clicking to select multiple faculty.</div>
                </div>
                <div class="col-12">
                    <label class="form-label fw-semibold small">Reason</label>
                    <textarea name="extension_reason" class="form-control" rows="3" placeholder="Optional note for the audit trail"></textarea>
                </div>
            </div>
            <div id="ext_error" style="display:none;margin-top:0.85rem;padding:0.5rem 0.85rem;
                 background:#fef2f2;border:1px solid #fecaca;border-left:3px solid #dc2626;font-size:0.8rem;color:#dc2626;"></div>
            <div style="display:flex;gap:0.5rem;justify-content:flex-end;margin-top:1.25rem;">
                <button type="button"
                        onclick="document.getElementById('extendSubmissionModal').style.display='none'"
                        style="padding:0.42rem 1rem;border:1px solid #e2e8f0;background:#fff;color:#475569;font-size:0.8rem;font-weight:600;cursor:pointer;"
                        onmouseover="this.style.background='#f8fafc'" onmouseout="this.style.background='#fff'">
                    Cancel
                </button>
                <button type="button" onclick="submitExtension()"
                        style="padding:0.42rem 1.15rem;border:none;background:#1a3a6b;color:#fff;font-size:0.8rem;font-weight:700;cursor:pointer;display:inline-flex;align-items:center;gap:5px;"
                        onmouseover="this.style.background='#152d54'" onmouseout="this.style.background='#1a3a6b'">
                    <i class="bi bi-check-circle" style="font-size:0.75rem;"></i> Save Extension
                </button>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>

<script>
// Cycle name dropdown population and date auto-fill removed.
// Cycles are now created with a name only; timing is controlled by Open/Closed status.

// -- Cycle Review Modal ----------------------------------------------------
function fmtDate(val) {
    if (!val) return ' - ';
    const d = new Date(val + 'T00:00:00');
    return d.toLocaleDateString('en-PH', { year:'numeric', month:'long', day:'numeric' });
}

function openCycleReview() {
    const form = document.getElementById('createCycleForm');
    if (!form.checkValidity()) { form.reportValidity(); return; }

    const name     = document.getElementById('cc_name').value.trim();
    const start    = document.getElementById('cc_start').value;
    const end      = document.getElementById('cc_end').value;
    const subStart = document.getElementById('cc_sub_start').value;
    const deadline = document.getElementById('cc_deadline').value;
    const evalDeadline = document.getElementById('cc_eval_deadline').value;
    const appealDeadline = document.getElementById('cc_appeal_deadline').value;

    // Validate date logic
    const errBox = document.getElementById('cycle_date_error');
    const errMsg = document.getElementById('cycle_date_error_msg');
    const showErr = (msg) => { errMsg.textContent = msg; errBox.style.display = 'block'; errBox.scrollIntoView({behavior:'smooth',block:'nearest'}); };
    errBox.style.display = 'none';
    if (start && end && end < start) {
        showErr('End date must be after the start date.');
        return;
    }
    if (subStart && start && subStart < start) {
        showErr('Submission start must be inside the cycle period.');
        return;
    }
    if (subStart && end && subStart > end) {
        showErr('Submission start must be inside the cycle period.');
        return;
    }
    if (subStart && deadline && subStart > deadline) {
        showErr('Submission start cannot be after the submission deadline.');
        return;
    }
    if (deadline && end && deadline > end) {
        showErr('Submission deadline cannot be after the end date.');
        return;
    }
    if (deadline && start && deadline < start) {
        showErr('Submission deadline cannot be before the start date.');
        return;
    }
    if (evalDeadline && deadline && evalDeadline <= deadline) {
        showErr('Evaluation deadline must be after the faculty submission deadline.');
        return;
    }
    if (evalDeadline && end && evalDeadline > end) {
        showErr('Evaluation deadline cannot be later than the cycle end date.');
        return;
    }
    if (appealDeadline && (appealDeadline < deadline || appealDeadline > end)) {
        showErr('Appeal deadline must be after the submission deadline and inside the cycle period.');
        return;
    }

    // Populate summary
    document.getElementById('rv_name').textContent     = name;
    document.getElementById('rv_start').textContent    = fmtDate(start);
    document.getElementById('rv_end').textContent      = fmtDate(end);
    document.getElementById('rv_sub_start').textContent = fmtDate(subStart);
    document.getElementById('rv_deadline').textContent = fmtDate(deadline);
    document.getElementById('rv_eval_deadline').textContent = fmtDate(evalDeadline);

    // Days until evaluation deadline
    const today = new Date(); today.setHours(0,0,0,0);
    const dlDate = new Date(evalDeadline + 'T00:00:00');
    const diff = Math.round((dlDate - today) / 86400000);
    const dlEl = document.getElementById('rv_deadline_note');
    if (diff < 0) {
        dlEl.textContent = 'This date has already passed.';
        dlEl.style.color = '#dc2626';
    } else if (diff === 0) {
        dlEl.textContent = 'Deadline is today.';
        dlEl.style.color = '#d97706';
    } else {
        dlEl.textContent = diff + ' day' + (diff !== 1 ? 's' : '') + ' from today.';
        dlEl.style.color = '#16a34a';
    }

    document.getElementById('cycleReviewModal').style.display = 'flex';
}

function confirmCreateCycle() {
    document.getElementById('cycleReviewModal').style.display = 'none';
    document.getElementById('createCycleForm').submit();
}

document.addEventListener('DOMContentLoaded', function () {
    const m = document.getElementById('cycleReviewModal');
    if (m) m.addEventListener('click', function(e) {
        if (e.target === this) this.style.display = 'none';
    });
    const em = document.getElementById('editCycleModal');
    if (em) em.addEventListener('click', function(e) {
        if (e.target === this) this.style.display = 'none';
    });
    const xm = document.getElementById('extendSubmissionModal');
    if (xm) xm.addEventListener('click', function(e) {
        if (e.target === this) this.style.display = 'none';
    });

});

function openEditCycleModal() {
    document.getElementById('editCycleModal').style.display = 'flex';
    document.getElementById('ec_error').style.display = 'none';
}

function submitEditCycle() {
    const name     = document.getElementById('ec_name').value.trim();
    const start    = document.getElementById('ec_start').value;
    const end      = document.getElementById('ec_end').value;
    const subStart = document.getElementById('ec_sub_start').value;
    const deadline = document.getElementById('ec_deadline').value;
    const evalDeadline = document.getElementById('ec_eval_deadline').value;
    const appealDeadline = document.getElementById('ec_appeal_deadline').value;
    const errEl    = document.getElementById('ec_error');
    const show = (msg) => { errEl.textContent = msg; errEl.style.display = 'block'; };

    if (!name) { show('Cycle name is required.'); return; }
    if (!start || !end || !subStart || !deadline || !evalDeadline) { show('All cycle date fields are required.'); return; }
    if (start && end && end <= start) { show('End date must be after start date.'); return; }
    if (subStart < start || subStart > end) { show('Submission start must be inside the cycle period.'); return; }
    if (deadline < start || deadline > end) { show('Submission deadline must be inside the cycle period.'); return; }
    if (subStart > deadline) { show('Submission start cannot be after the submission deadline.'); return; }
    if (evalDeadline <= deadline) { show('Evaluation deadline must be after the faculty submission deadline.'); return; }
    if (evalDeadline > end) { show('Evaluation deadline cannot be later than the cycle end date.'); return; }
    if (appealDeadline && (appealDeadline < deadline || appealDeadline > end)) { show('Appeal deadline must be after the submission deadline and inside the cycle period.'); return; }

    errEl.style.display = 'none';
    document.getElementById('ec_name').closest('form').submit();
}

function openExtendSubmissionModal() {
    const m = document.getElementById('extendSubmissionModal');
    if (!m) return;
    document.getElementById('confirm_past_eval').value = '0';
    document.getElementById('ext_error').style.display = 'none';
    m.style.display = 'flex';
}

function toggleFacultyPicker() {
    const wrap = document.getElementById('faculty_picker_wrap');
    const scope = document.getElementById('extension_scope').value;
    if (wrap) wrap.style.display = scope === 'selected' ? 'block' : 'none';
}

function submitExtension() {
    const newDeadline = document.getElementById('ext_new_deadline').value;
    const currentDeadline = '<?= htmlspecialchars($panel_cycle['submission_deadline'] ?? '') ?>';
    const evalDeadline = '<?= htmlspecialchars($panel_cycle['evaluation_deadline'] ?? '') ?>';
    const endDate = '<?= htmlspecialchars($panel_cycle['end_date'] ?? '') ?>';
    const scope = document.getElementById('extension_scope').value;
    const errEl = document.getElementById('ext_error');
    const show = (msg) => { errEl.textContent = msg; errEl.style.display = 'block'; };

    if (!newDeadline) { show('New deadline is required.'); return; }
    if (currentDeadline && newDeadline <= currentDeadline) { show('Extended deadline must be later than the current submission deadline.'); return; }
    if (endDate && newDeadline > endDate) { show('Extended deadline cannot be later than the cycle end date.'); return; }
    if (scope === 'selected') {
        const selected = document.querySelectorAll('#faculty_picker_wrap select option:checked');
        if (!selected.length) { show('Please select at least one faculty member.'); return; }
    }
    if (evalDeadline && newDeadline > evalDeadline && document.getElementById('confirm_past_eval').value !== '1') {
        if (!confirm('The new submission deadline is past the evaluation deadline. Continue with this extension?')) return;
        document.getElementById('confirm_past_eval').value = '1';
    }
    errEl.style.display = 'none';
    document.getElementById('extendSubmissionForm').submit();
}
</script>

<!-- Delete Cycle Verification Modal -->
<div id="deleteCycleModal" style="display:none;position:fixed;inset:0;background:rgba(15,23,42,0.6);
     z-index:99999;align-items:center;justify-content:center;padding:1rem;">
    <div style="background:#fff;width:100%;max-width:500px;border:1px solid #e2e8f0;box-shadow:0 20px 60px rgba(0,0,0,0.25);overflow:hidden;">

        <!-- Header -->
        <div style="background:#dc2626;padding:0.85rem 1.1rem;display:flex;align-items:center;justify-content:space-between;">
            <div style="display:flex;align-items:center;gap:0.5rem;">
                <i class="bi bi-exclamation-triangle-fill" style="color:#fff;font-size:0.9rem;"></i>
                <div>
                    <div style="color:#fff;font-weight:700;font-size:0.88rem;">Delete Cycle</div>
                    <div style="color:rgba(255,255,255,0.6);font-size:0.65rem;margin-top:1px;">This action is permanent and cannot be undone.</div>
                </div>
            </div>
            <button type="button" onclick="closeDeleteCycleModal()"
                    style="background:none;border:none;color:rgba(255,255,255,0.7);font-size:1.1rem;cursor:pointer;line-height:1;padding:0;"
                    onmouseover="this.style.color='#fff'" onmouseout="this.style.color='rgba(255,255,255,0.7)'">
                <i class="bi bi-x-lg"></i>
            </button>
        </div>

        <form method="POST" id="deleteCycleForm" onsubmit="return validateDeleteCycle()">
            <input type="hidden" name="action" value="delete_cycle">
            <input type="hidden" name="cycle_id" id="dcCycleId">

            <div style="padding:1.1rem 1.25rem;">

                <!-- Cycle being deleted -->
                <div style="background:#f8fafc;border:1px solid #e2e8f0;border-left:3px solid #dc2626;padding:0.65rem 0.9rem;margin-bottom:1rem;">
                    <div style="font-size:0.6rem;font-weight:700;text-transform:uppercase;letter-spacing:0.07em;color:#94a3b8;margin-bottom:2px;">Cycle to be deleted</div>
                    <div id="dcCycleName" style="font-weight:700;color:#0f172a;font-size:0.88rem;"></div>
                </div>

                <!-- Warning: applications exist -->
                <div id="dcAppsWarning" style="display:none;background:#fffbeb;border:1px solid #fde68a;border-left:3px solid #d97706;padding:0.65rem 0.9rem;margin-bottom:1rem;font-size:0.78rem;color:#92400e;">
                    <i class="bi bi-exclamation-triangle-fill" style="margin-right:5px;"></i>
                    <span id="dcAppsWarningText"></span>
                </div>

                <!-- Terms -->
                <div style="margin-bottom:1rem;">
                    <div style="font-size:0.65rem;font-weight:700;text-transform:uppercase;letter-spacing:0.07em;color:#64748b;margin-bottom:0.5rem;">What will be permanently deleted:</div>
                    <div style="display:flex;flex-direction:column;gap:4px;">
                        <?php foreach ([
                            'The cycle and all its configuration',
                            'All scoring criteria for every faculty position in this cycle',
                            'All KRA assignments for evaluators in this cycle',
                            'All submitted applications and their KRA entries',
                            'All evidence files linked to this cycle',
                            'All evaluator reviews, decisions, and appeal threads',
                        ] as $term): ?>
                        <div style="display:flex;align-items:flex-start;gap:0.5rem;font-size:0.76rem;color:#475569;">
                            <i class="bi bi-x-circle-fill" style="color:#dc2626;flex-shrink:0;margin-top:1px;font-size:0.72rem;"></i>
                            <?= $term ?>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>

                <!-- Confirm cycle name -->
                <div style="margin-bottom:0.75rem;">
                    <label style="font-size:0.65rem;font-weight:700;text-transform:uppercase;letter-spacing:0.07em;color:#64748b;display:block;margin-bottom:4px;">
                        Type the cycle name to confirm <span style="color:#dc2626;">*</span>
                    </label>
                    <input type="text" name="confirm_name" id="dcConfirmName"
                           placeholder="Type exact cycle name..."
                           autocomplete="off"
                           style="width:100%;border:1px solid #e2e8f0;padding:0.42rem 0.6rem;font-size:0.82rem;outline:none;box-sizing:border-box;"
                           onfocus="this.style.borderColor='#dc2626'" onblur="this.style.borderColor='#e2e8f0'">
                    <div id="dcNameError" style="display:none;font-size:0.68rem;color:#dc2626;margin-top:3px;">Name does not match.</div>
                </div>

                <!-- Password -->
                <div style="margin-bottom:0.85rem;">
                    <label style="font-size:0.65rem;font-weight:700;text-transform:uppercase;letter-spacing:0.07em;color:#64748b;display:block;margin-bottom:4px;">
                        Your account password <span style="color:#dc2626;">*</span>
                    </label>
                    <div style="display:flex;">
                        <input type="password" name="confirm_password" id="dcPassword"
                               placeholder="Enter your password..."
                               style="flex:1;border:1px solid #e2e8f0;border-right:none;padding:0.42rem 0.6rem;font-size:0.82rem;outline:none;"
                               onfocus="this.style.borderColor='#dc2626'" onblur="this.style.borderColor='#e2e8f0'">
                        <button type="button"
                                onclick="var el=document.getElementById('dcPassword');el.type=el.type==='password'?'text':'password';"
                                style="padding:0 0.65rem;border:1px solid #e2e8f0;background:#f8fafc;color:#475569;cursor:pointer;font-size:0.78rem;"
                                onmouseover="this.style.background='#1a3a6b';this.style.color='#fff'" onmouseout="this.style.background='#f8fafc';this.style.color='#475569'">
                            <i class="bi bi-eye"></i>
                        </button>
                    </div>
                    <div id="dcPassError" style="display:none;font-size:0.68rem;color:#dc2626;margin-top:3px;">Password is required.</div>
                </div>

                <!-- I understand checkbox -->
                <label style="display:flex;align-items:flex-start;gap:0.5rem;cursor:pointer;font-size:0.76rem;color:#475569;">
                    <input type="checkbox" id="dcAgree" style="margin-top:2px;cursor:pointer;accent-color:#dc2626;">
                    I understand that this deletion is <strong>permanent</strong> and all associated data will be lost. This action cannot be reversed.
                </label>
                <div id="dcAgreeError" style="display:none;font-size:0.68rem;color:#dc2626;margin-top:3px;">You must acknowledge the terms.</div>
            </div>

            <!-- Footer -->
            <div style="padding:0.75rem 1.25rem;border-top:1px solid #e2e8f0;background:#f8fafc;display:flex;gap:0.5rem;justify-content:flex-end;">
                <button type="button" onclick="closeDeleteCycleModal()"
                        style="padding:0.42rem 1rem;border:1px solid #e2e8f0;background:#fff;color:#475569;font-size:0.78rem;font-weight:600;cursor:pointer;"
                        onmouseover="this.style.background='#f8fafc'" onmouseout="this.style.background='#fff'">Cancel</button>
                <button type="submit" id="dcSubmitBtn"
                        style="padding:0.42rem 1.1rem;background:#dc2626;color:#fff;border:none;font-size:0.78rem;font-weight:700;cursor:pointer;display:inline-flex;align-items:center;gap:5px;"
                        onmouseover="this.style.background='#b91c1c'" onmouseout="this.style.background='#dc2626'">
                    <i class="bi bi-trash" style="font-size:0.72rem;"></i> Permanently Delete
                </button>
            </div>
        </form>
    </div>
</div>

<script>
// Cycle UI helpers

function openDeleteCycleModalFromBtn(btn) {
    var cycleId     = btn.getAttribute('data-cycle-id');
    var cycleName   = btn.getAttribute('data-cycle-name');
    var appliedCount = parseInt(btn.getAttribute('data-applied'), 10);
    openDeleteCycleModal(cycleId, cycleName, appliedCount);
}

function openDeleteCycleModal(cycleId, cycleName, appliedCount) {
    _dcCycleName = cycleName;
    document.getElementById('dcCycleId').value    = cycleId;
    document.getElementById('dcCycleName').textContent = cycleName;
    document.getElementById('dcConfirmName').value = '';
    document.getElementById('dcPassword').value    = '';
    document.getElementById('dcAgree').checked     = false;
    document.getElementById('dcNameError').style.display  = 'none';
    document.getElementById('dcPassError').style.display  = 'none';
    document.getElementById('dcAgreeError').style.display = 'none';

    var warn = document.getElementById('dcAppsWarning');
    if (appliedCount > 0) {
        document.getElementById('dcAppsWarningText').textContent =
            appliedCount + ' submitted application' + (appliedCount !== 1 ? 's' : '') +
            ' will be permanently deleted along with all their entries, files, and reviews.';
        warn.style.display = 'block';
    } else {
        warn.style.display = 'none';
    }

    document.getElementById('deleteCycleModal').style.display = 'flex';
}

function closeDeleteCycleModal() {
    document.getElementById('deleteCycleModal').style.display = 'none';
}

function validateDeleteCycle() {
    var name  = document.getElementById('dcConfirmName').value.trim();
    var pass  = document.getElementById('dcPassword').value;
    var agree = document.getElementById('dcAgree').checked;
    var ok    = true;

    document.getElementById('dcNameError').style.display  = 'none';
    document.getElementById('dcPassError').style.display  = 'none';
    document.getElementById('dcAgreeError').style.display = 'none';

    if (name.toLowerCase() !== _dcCycleName.toLowerCase()) {
        document.getElementById('dcNameError').style.display = 'block';
        document.getElementById('dcConfirmName').style.borderColor = '#dc2626';
        ok = false;
    }
    if (!pass) {
        document.getElementById('dcPassError').style.display = 'block';
        ok = false;
    }
    if (!agree) {
        document.getElementById('dcAgreeError').style.display = 'block';
        ok = false;
    }
    return ok;
}

// Close on backdrop click
document.getElementById('deleteCycleModal').addEventListener('click', function(e) {
    if (e.target === this) closeDeleteCycleModal();
});
</script>

