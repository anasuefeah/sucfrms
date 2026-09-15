<?php
if (!isAdmin()) { echo '<div class="alert alert-danger">Access denied.</div>'; return; }

// â”€â”€ Runtime migration: add cycle_id to scoring_criteria if missing â”€â”€
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
// â”€â”€ Runtime migration: add submission_deadline to cycles if missing â”€â”€
try { $pdo->query("SELECT submission_deadline FROM cycles LIMIT 1"); }
catch (\Exception $e) {
    $pdo->exec("ALTER TABLE cycles ADD COLUMN submission_deadline DATE DEFAULT NULL AFTER end_date");
}
// Make start_date / end_date nullable so legacy rows without dates don't cause errors
try { $pdo->exec("ALTER TABLE cycles MODIFY start_date DATE DEFAULT NULL, MODIFY end_date DATE DEFAULT NULL"); } catch (\Exception $e2) {}

$kra_list        = ['Instruction','Research','Extension','Professional Development'];
$active_cycle_id = intval($_GET['cycle_id'] ?? 0);
$active_position = trim($_GET['position'] ?? ''); // position filter within a cycle

// â”€â”€ POST handlers â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    // â”€â”€ Cycle CRUD â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
    if ($action === 'create_cycle') {
        $name       = trim($_POST['cycle_name'] ?? '');
        $start_date = trim($_POST['start_date'] ?? '');
        $end_date   = trim($_POST['end_date'] ?? '');
        $deadline   = trim($_POST['submission_deadline'] ?? '');
        if ($name && $start_date && $end_date && $deadline) {
            $pdo->exec("UPDATE cycles SET status='closed' WHERE status='open'");
            $pdo->prepare("INSERT INTO cycles (cycle_name, start_date, end_date, submission_deadline, status, created_by) VALUES (?,?,?,?,'open',?)")
                ->execute([$name, $start_date, $end_date, $deadline, $_SESSION['user_id']]);
            $new_cid = (int)$pdo->lastInsertId();

            // â”€â”€ Auto-seed criteria for ALL positions from global defaults â”€â”€
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

            logAudit($pdo, $_SESSION['user_id'], 'Cycle Created', "New cycle: {$name}. Criteria seeded for all 19 positions.");
            flashMessage('success', "Cycle <strong>{$name}</strong> created. Criteria have been seeded for all positions &mdash; customise each position's criteria below.");
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
            logAudit($pdo, $_SESSION['user_id'], 'Cycle Status Updated', "Cycle #{$cid} â†’ {$status}");
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
        $deadline = trim($_POST['submission_deadline'] ?? '') ?: null;
        if ($cid && $name) {
            $pdo->prepare("UPDATE cycles SET cycle_name=?, start_date=?, end_date=?, submission_deadline=? WHERE cycle_id=?")
                ->execute([$name, $start, $end, $deadline, $cid]);
            logAudit($pdo, $_SESSION['user_id'], 'Cycle Updated', "Cycle #{$cid} updated: {$name}");
            flashMessage('success', "Cycle <strong>{$name}</strong> updated.");
        }
        echo "<script>window.location.href='index.php?page=cycles&cycle_id={$cid}';</script>"; exit;
    } elseif ($action === 'delete_cycle') {
        $cid = intval($_POST['cycle_id']);
        $has = $pdo->prepare("SELECT COUNT(*) FROM applications WHERE cycle_id=? AND status!='draft'");
        $has->execute([$cid]);
        if ($has->fetchColumn() > 0) {
            flashMessage('danger', 'Cannot delete &mdash; this cycle has submitted applications.');
            echo "<script>window.location.href='index.php?page=cycles&cycle_id={$cid}';</script>"; exit;
        }
        $pdo->prepare("DELETE FROM cycles WHERE cycle_id=?")->execute([$cid]);
        logAudit($pdo, $_SESSION['user_id'], 'Cycle Deleted', "Deleted cycle #{$cid}");
        flashMessage('success', 'Cycle deleted.');
        echo "<script>window.location.href='index.php?page=cycles';</script>"; exit;

    // â”€â”€ Criteria CRUD â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
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
            logAudit($pdo, $_SESSION['user_id'], 'Criterion Added', "{$label} ({$kra}) = {$max} pts" . ($cid ? " [cycle #{$cid}]" : " [global]") . ($pos_rank ? " [{$pos_rank}]" : ''));
            flashMessage('success', "Criterion <strong>{$label}</strong> added.");
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
            logAudit($pdo, $_SESSION['user_id'], 'Criterion Updated', "ID {$crit_id}: {$label}");
            flashMessage('success', "Criterion <strong>{$label}</strong> updated.");
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
            logAudit($pdo, $_SESSION['user_id'], 'Criterion Deleted', "ID {$crit_id}: {$existing['criterion_label']}");
            flashMessage('success', 'Criterion deleted.');
        }
        $redir = "index.php?page=cycles&cycle_id={$back_cid}" . ($back_pos ? '&position=' . urlencode($back_pos) : '');
        echo "<script>window.location.href='{$redir}';</script>"; exit;
    }

    echo "<script>window.location.href='index.php?page=cycles';</script>"; exit;
}

// â”€â”€ Load cycles â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
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
?>

<?php showFlash(); ?>

<!-- Page Header -->
<div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:1rem;margin-bottom:1.5rem;">
    <div style="display:flex;align-items:center;gap:0.6rem;">
        <div style="width:36px;height:36px;border-radius:8px;background:#1e4d8c;display:flex;align-items:center;justify-content:center;flex-shrink:0;">
            <i class="bi bi-calendar-range" style="color:#fff;font-size:0.9rem;"></i>
        </div>
        <div>
            <div style="font-weight:700;color:#1a3a6b;font-size:0.95rem;">Cycles &amp; Scoring Criteria</div>
            <div style="font-size:0.72rem;color:#94a3b8;margin-top:1px;">Manage evaluation cycles and configure scoring criteria per faculty position</div>
        </div>
    </div>
    <button class="btn btn-primary btn-sm" data-bs-toggle="collapse" data-bs-target="#createCyclePanel"
            style="background:#1a3a6b;border:none;border-radius:8px;padding:0.45rem 1.1rem;font-size:0.82rem;font-weight:600;">
        <i class="bi bi-plus-lg me-1"></i>New Cycle
    </button>
</div>

<!-- Create Cycle (collapsible — closed by default) -->
<div class="collapse mb-4" id="createCyclePanel">
    <div class="neon-card" style="border-left:4px solid #1e4d8c;padding:0;overflow:hidden;">
        <div style="padding:0.9rem 1.25rem;border-bottom:1px solid #f1f5f9;background:#fff;">
            <div style="font-weight:700;color:#1a3a6b;font-size:0.9rem;display:flex;align-items:center;gap:0.5rem;">
                <i class="bi bi-plus-circle" style="color:#1e4d8c;"></i>Create New Cycle
            </div>
        </div>
        <div style="padding:1.25rem;">

            <!-- #7: Warning about closing current open cycle -->
            <?php
            $open_cycle = null;
            foreach ($cycles as $cy) { if ($cy['status'] === 'open') { $open_cycle = $cy; break; } }
            if ($open_cycle):
            ?>
            <div class="alert py-2 mb-3 d-flex align-items-center gap-2" style="background:#f8fafc;border:1px solid #e2e8f0;color:#334155;font-size:0.82rem;">
                <i class="bi bi-exclamation-triangle-fill flex-shrink-0"></i>
                <div>
                    Creating a new cycle will <strong>automatically close</strong> the currently open cycle:
                    <strong><?= sanitize($open_cycle['cycle_name']) ?></strong>.
                </div>
            </div>
            <?php endif; ?>

            <form method="POST" class="row g-3" id="createCycleForm">
                <input type="hidden" name="action" value="create_cycle">

                <div class="col-12">
                    <label class="form-label fw-semibold small">Cycle Name <span class="text-danger">*</span></label>
                    <input type="text" name="cycle_name" id="cc_name" class="form-control" required
                           placeholder="e.g. AY 2026-2029 Reclassification Cycle"
                           style="font-size:0.9rem;">
                    <div class="form-text">The cycle will be set to <strong>Open</strong> immediately. Close it from the status control when submissions end.</div>
                </div>

                <div class="col-sm-4">
                    <label class="form-label fw-semibold small">Start Date <span class="text-danger">*</span></label>
                    <input type="date" name="start_date" id="cc_start" class="form-control" required style="font-size:0.9rem;">
                </div>

                <div class="col-sm-4">
                    <label class="form-label fw-semibold small">End Date <span class="text-danger">*</span></label>
                    <input type="date" name="end_date" id="cc_end" class="form-control" required style="font-size:0.9rem;">
                </div>

                <div class="col-sm-4">
                    <label class="form-label fw-semibold small">Submission Deadline <span class="text-danger">*</span></label>
                    <input type="date" name="submission_deadline" id="cc_deadline" class="form-control" required style="font-size:0.9rem;">
                    <div class="form-text">Faculty cannot submit after this date.</div>
                </div>

                <div id="cycle_date_error" style="display:none;" class="col-12">
                    <div class="alert alert-danger py-2 mb-0" style="font-size:0.82rem;">
                        <i class="bi bi-exclamation-circle me-1"></i>
                        <span id="cycle_date_error_msg"></span>
                    </div>
                </div>

                <div class="col-12">
                    <button type="button" class="btn btn-primary" onclick="openCycleReview()">
                        <i class="bi bi-eye me-1"></i>Review &amp; Create
                    </button>
                    <button type="button" class="btn btn-outline-secondary ms-2"
                            onclick="document.getElementById('createCyclePanel').classList.remove('show')">
                        Cancel
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<div class="row g-3">

    <!-- LEFT: Cycle list -->
    <div class="col-lg-3">        <div class="neon-card h-100" style="padding:0;overflow:hidden;">
            <div style="padding:0.75rem 1rem;background:#1a3a6b;">
                <div style="font-size:0.78rem;font-weight:700;color:#fff;text-transform:uppercase;letter-spacing:0.05em;display:flex;align-items:center;gap:0.5rem;">
                    <i class="bi bi-list-ul"></i>All Cycles
                </div>
            </div>
            <?php if ($cycles): ?>
            <div class="list-group list-group-flush" style="overflow-y:auto;max-height:70vh;">
            <?php foreach ($cycles as $c):
                $is_sel = ($c['cycle_id'] === $active_cycle_id);
                $sc_map = ['open'=>'success','closed'=>'secondary','archived'=>'dark'];
            ?>
            <a href="?page=cycles&cycle_id=<?= $c['cycle_id'] ?>"
               class="list-group-item list-group-item-action px-3 py-3"
               style="border-left:<?= $is_sel ? '4px solid #475569' : '4px solid transparent' ?>;background:<?= $is_sel ? '#f0f4fb' : '#fff' ?>;">
                <div class="d-flex justify-content-between align-items-start gap-2">
                    <div class="flex-grow-1 min-w-0">
                        <div class="fw-semibold text-truncate" style="font-size:0.875rem;color:<?= $is_sel ? '#1a3a6b' : '#1e293b' ?>;">
                            <?= sanitize($c['cycle_name']) ?>
                        </div>
                        <div class="small mt-1" style="color:#64748b;">
                            <?php if (!empty($c['start_date']) && !empty($c['end_date'])): ?>
                            <?= date('Y', strtotime($c['start_date'])) ?> – <?= date('Y', strtotime($c['end_date'])) ?>
                            <?php endif; ?>
                        </div>
                        <div class="d-flex gap-1 mt-2 flex-wrap">
                            <span class="badge bg-<?= $sc_map[$c['status']] ?>" style="font-size:0.65rem;"><?= ucfirst($c['status']) ?></span>
                            <?php if ($c['criteria_count'] > 0): ?>
                            <span class="badge" style="background:#dbeafe;color:#1e4d8c;font-size:0.65rem;"><?= $c['criteria_count'] ?> criteria</span>
                            <?php else: ?>
                            <span class="badge bg-warning text-dark" style="font-size:0.65rem;">No criteria</span>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="text-end" style="font-size:0.72rem;color:#94a3b8;white-space:nowrap;flex-shrink:0;">
                        <div><?= $c['applied'] ?> applied</div>
                        <div><?= $c['reclassified'] ?> reclassified</div>
                    </div>
                </div>
            </a>
            <?php endforeach; ?>
            </div>
            <?php else: ?>
            <div class="p-4 text-center text-muted small">No cycles yet. Create one above.</div>
            <?php endif; ?>
        </div>
    </div>

    <!-- RIGHT: Cycle detail + criteria -->
    <div class="col-lg-9">
    <?php if ($panel_cycle): ?>

        <!-- Cycle header card -->
        <div class="neon-card mb-3" style="padding:0;overflow:hidden;">
            <div style="padding:0.75rem 1.25rem;background:#1a3a6b;">
                <div style="display:flex;align-items:center;justify-content:space-between;gap:0.75rem;flex-wrap:wrap;">
                    <div style="font-weight:700;color:#fff;font-size:0.95rem;">
                        <?= sanitize($panel_cycle['cycle_name']) ?>
                    </div>
                    <?php
                    $sc_map2 = ['open'=>['#f0fdf4','#16a34a'],'closed'=>['#f1f5f9','#64748b'],'archived'=>['#f8fafc','#94a3b8']];
                    [$scb,$scc] = $sc_map2[$panel_cycle['status']] ?? ['#f1f5f9','#64748b'];
                    ?>
                    <span style="background:<?= $scb ?>;color:<?= $scc ?>;font-size:0.72rem;font-weight:700;border-radius:20px;padding:2px 10px;">
                        <?= ucfirst($panel_cycle['status']) ?>
                    </span>
                </div>
            </div>
            <div style="padding:1rem 1.25rem;">
                <div class="row g-3 mb-3">
                    <div class="col-sm-6">
                        <div class="small text-muted mb-1" style="font-size:0.7rem;text-transform:uppercase;letter-spacing:0.05em;font-weight:700;">Statistics</div>
                        <div style="font-size:0.85rem;color:#1e293b;">
                            <span class="me-2"><i class="bi bi-file-earmark-text me-1 text-primary"></i><?= $panel_cycle['applied'] ?> applications</span>
                            <span><i class="bi bi-check-circle me-1 text-success"></i><?= $panel_cycle['reclassified'] ?> reclassified</span>
                        </div>
                        <div class="text-muted small mt-1">Created by <?= sanitize($panel_cycle['full_name'] ?? 'System') ?></div>
                    </div>
                    <div class="col-sm-6">
                        <div class="small text-muted mb-1" style="font-size:0.7rem;text-transform:uppercase;letter-spacing:0.05em;font-weight:700;">Cycle Dates</div>
                        <div style="font-size:0.82rem;color:#1e293b;display:flex;flex-direction:column;gap:2px;">
                            <?php if (!empty($panel_cycle['start_date'])): ?>
                            <div><i class="bi bi-calendar-event me-1 text-primary"></i>
                                <span class="text-muted">Start:</span>
                                <strong><?= date('M j, Y', strtotime($panel_cycle['start_date'])) ?></strong>
                            </div>
                            <?php endif; ?>
                            <?php if (!empty($panel_cycle['end_date'])): ?>
                            <div><i class="bi bi-calendar-x me-1 text-danger"></i>
                                <span class="text-muted">End:</span>
                                <strong><?= date('M j, Y', strtotime($panel_cycle['end_date'])) ?></strong>
                            </div>
                            <?php endif; ?>
                            <?php if (!empty($panel_cycle['submission_deadline'])): ?>
                            <div><i class="bi bi-clock me-1 text-warning"></i>
                                <span class="text-muted">Deadline:</span>
                                <strong><?= date('M j, Y', strtotime($panel_cycle['submission_deadline'])) ?></strong>
                            </div>
                            <?php endif; ?>
                            <?php if (empty($panel_cycle['start_date']) && empty($panel_cycle['end_date'])): ?>
                            <div class="text-muted" style="font-size:0.78rem;"><i class="bi bi-dash me-1"></i>No dates set</div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                <!-- Status controls -->
                <div class="d-flex gap-2 flex-wrap pt-2" style="border-top:1px solid #f0f4fb;">
                    <button type="button" class="btn btn-sm btn-outline-primary"
                            onclick="openEditCycleModal()"
                            style="border-radius:6px;">
                        <i class="bi bi-pencil me-1"></i>Edit Cycle
                    </button>
                    <form method="POST" class="d-flex gap-1" id="statusForm_<?= $panel_cycle['cycle_id'] ?>">
                        <input type="hidden" name="action" value="update_status">
                        <input type="hidden" name="cycle_id" value="<?= $panel_cycle['cycle_id'] ?>">
                        <select name="status" class="form-select form-select-sm" style="width:auto;min-width:130px;">
                            <option value="open"     <?= $panel_cycle['status']==='open'     ?'selected':'' ?>>Open</option>
                            <option value="closed"   <?= $panel_cycle['status']==='closed'   ?'selected':'' ?>>Closed</option>
                            <option value="archived" <?= $panel_cycle['status']==='archived' ?'selected':'' ?>>Archived</option>
                        </select>
                        <button type="button" class="btn btn-sm btn-primary"
                                onclick="confirmDelete('Update status for this cycle?','statusForm_<?= $panel_cycle['cycle_id'] ?>','Update','bi-check-circle')">
                            <i class="bi bi-check-circle me-1"></i>Update Status
                        </button>
                    </form>
                    <form method="POST" id="delCycleForm_<?= $panel_cycle['cycle_id'] ?>">
                        <input type="hidden" name="action" value="delete_cycle">
                        <input type="hidden" name="cycle_id" value="<?= $panel_cycle['cycle_id'] ?>">
                        <button type="button" class="btn btn-sm"
                                style="background:#fef2f2;color:#dc2626;border:1px solid #fecaca;"
                                <?= $panel_cycle['applied'] > 0 ? 'disabled title="Has submitted applications"' : '' ?>
                                onclick="confirmDelete('Delete cycle &ldquo;<?= addslashes(sanitize($panel_cycle['cycle_name'])) ?>&rdquo;? This cannot be undone.','delCycleForm_<?= $panel_cycle['cycle_id'] ?>')">
                            <i class="bi bi-trash me-1"></i>Delete Cycle
                        </button>
                    </form>
                </div>
            </div>
        </div>

        <!-- Criteria card -->
        <div class="neon-card" style="padding:0;overflow:hidden;">
            <div style="padding:0.75rem 1.25rem;border-bottom:1px solid #f1f5f9;display:flex;align-items:center;justify-content:space-between;">
                <div style="font-weight:700;color:#1a3a6b;font-size:0.88rem;display:flex;align-items:center;gap:0.5rem;">
                    <i class="bi bi-sliders" style="color:#1e4d8c;"></i>Scoring Criteria
                    <?php if ($active_position): ?>
                    <span style="background:#dbeafe;color:#1e4d8c;font-size:0.7rem;font-weight:700;border-radius:20px;padding:2px 8px;">
                        <?= htmlspecialchars($active_position) ?>
                    </span>
                    <?php else: ?>
                    <span style="background:#f1f5f9;color:#64748b;font-size:0.7rem;font-weight:700;border-radius:20px;padding:2px 8px;">All Positions Overview</span>
                    <?php endif; ?>
                </div>
            </div>
            <div style="padding:1rem 1.25rem;">

            <!-- Position selector tabs -->
            <div class="mb-3 pb-3" style="border-bottom:1px solid #f0f4fb;">
                <div class="d-flex align-items-center gap-1 flex-wrap">
                    <a href="?page=cycles&cycle_id=<?= $active_cycle_id ?>"
                       class="btn btn-xs <?= !$active_position ? 'btn-primary' : 'btn-outline-secondary' ?>">
                        <i class="bi bi-grid me-1"></i>Overview
                    </a>
                    <?php
                    $rank_groups = [
                        'Instructor'   => ['Instructor I','Instructor II','Instructor III'],
                        'Asst. Prof.'  => ['Assistant Professor I','Assistant Professor II','Assistant Professor III','Assistant Professor IV'],
                        'Assoc. Prof.' => ['Associate Professor I','Associate Professor II','Associate Professor III','Associate Professor IV','Associate Professor V'],
                        'Professor'    => ['Professor I','Professor II','Professor III','Professor IV','Professor V','Professor VI'],
                        'Univ. Prof.'  => ['University Professor'],
                    ];
                    foreach ($rank_groups as $group => $ranks):
                    ?>
                    <div class="dropdown">
                        <button class="btn btn-xs <?= in_array($active_position, $ranks) ? 'btn-primary' : 'btn-outline-secondary' ?> dropdown-toggle" data-bs-toggle="dropdown">
                            <?= $group ?>
                        </button>
                        <ul class="dropdown-menu shadow-sm" style="min-width:230px;border:1px solid #e2e8f0;">
                            <?php foreach ($ranks as $rank):
                                $cnt = $position_counts[$rank] ?? 0;
                                $is_active_pos = ($active_position === $rank);
                            ?>
                            <li>
                                <a class="dropdown-item small d-flex justify-content-between align-items-center py-2 <?= $is_active_pos ? 'active' : '' ?>"
                                   href="?page=cycles&cycle_id=<?= $active_cycle_id ?>&position=<?= urlencode($rank) ?>">
                                    <span><?= htmlspecialchars($rank) ?></span>
                                    <?php if ($cnt > 0): ?>
                                    <span class="badge ms-2" style="background:#dbeafe;color:#1e4d8c;font-size:0.6rem;"><?= $cnt ?></span>
                                    <?php else: ?>
                                    <span class="badge bg-warning text-dark ms-2" style="font-size:0.6rem;">empty</span>
                                    <?php endif; ?>
                                </a>
                            </li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>

            <?php if ($criteria_source === 'overview'): ?>
            <!-- Overview: position status grid -->
            <?php foreach ($rank_groups as $group => $ranks): ?>
            <div class="mb-3">
                <div class="fw-bold mb-2" style="font-size:0.72rem;text-transform:uppercase;letter-spacing:0.06em;color:#94a3b8;"><?= $group ?></div>
                <div class="d-flex flex-wrap gap-2">
                <?php foreach ($ranks as $rank):
                    $cnt = $position_counts[$rank] ?? 0;
                ?>
                <a href="?page=cycles&cycle_id=<?= $active_cycle_id ?>&position=<?= urlencode($rank) ?>"
                   class="btn btn-sm"
                   style="font-size:0.78rem;<?= $cnt > 0
                       ? 'background:#dbeafe;color:#1e4d8c;border:1px solid #bfdbfe;'
                       : 'background:#fff;color:#334155;border:1px solid #475569;' ?>">
                    <?= htmlspecialchars($rank) ?>
                    <span class="ms-1" style="font-size:0.65rem;opacity:0.8;"><?= $cnt > 0 ? $cnt : '!' ?></span>
                </a>
                <?php endforeach; ?>
                </div>
            </div>
            <?php endforeach; ?>

            <?php elseif ($criteria_source === 'empty_position'): ?>
            <div class="alert alert-info py-2 small">
                <i class="bi bi-info-circle me-1"></i>
                Criteria for <strong><?= htmlspecialchars($active_position) ?></strong> are being set up. Please refresh the page.
            </div>

            <?php else: ?>
            <!-- Editable criteria accordion -->
            <div class="alert py-2 small mb-3" style="background:#f0f7ff;border:1px solid #bfdbfe;color:#1e4d8c;">
                <i class="bi bi-pencil-square me-1"></i>
                Editing criteria for <strong><?= htmlspecialchars($active_position) ?></strong>. Changes only affect this position in this cycle.
            </div>

            <div class="accordion mb-4" id="criteriaAccordion">
            <?php foreach ($kra_list as $kra):
                $items = $grouped[$kra] ?? [];
                $slug  = strtolower(str_replace([' ','/'],['-',''], $kra));
                $is_editable = ($criteria_source === 'cycle_position');
            ?>
            <div class="accordion-item border mb-2" style="border-color:#e2e8f0 !important;border-radius:8px !important;overflow:hidden;">
                <h2 class="accordion-header" id="head-<?= $slug ?>">
                    <button class="accordion-button collapsed fw-semibold" type="button"
                            data-bs-toggle="collapse" data-bs-target="#kra-<?= $slug ?>"
                            style="background:#f8fafc;color:#1a3a6b;font-size:0.875rem;">
                        <i class="bi bi-sliders2 me-2 text-primary"></i><?= $kra ?>
                        <span class="badge bg-primary ms-2" style="font-size:0.65rem;"><?= count($items) ?></span>
                    </button>
                </h2>
                <div id="kra-<?= $slug ?>" class="accordion-collapse collapse">
                    <div class="accordion-body p-0" style="background:#fff;">
                        <?php foreach ($items as $c): ?>
                        <form method="POST" class="px-3 py-3 border-bottom" style="border-color:#f0f4fb !important;"
                              id="cForm_<?= $c['criteria_id'] ?>">
                            <input type="hidden" name="action" value="update_criterion">
                            <input type="hidden" name="criteria_id" value="<?= $c['criteria_id'] ?>">
                            <input type="hidden" name="back_cycle_id" value="<?= $active_cycle_id ?>">
                            <input type="hidden" name="back_position" value="<?= htmlspecialchars($active_position) ?>">
                            <div class="row g-2 align-items-end">
                                <div class="col-12 col-md-1">
                                    <label class="form-label small fw-semibold" style="color:#94a3b8;">Key</label>
                                    <input type="text" class="form-control form-control-sm"
                                           value="<?= sanitize($c['criterion_key']) ?>" disabled
                                           style="background:#f8fafc;color:#94a3b8;font-size:0.75rem;">
                                </div>
                                <div class="col-12 col-md-4">
                                    <label class="form-label small fw-semibold" style="color:#475569;">Label</label>
                                    <input type="text" name="criterion_label" class="form-control form-control-sm"
                                           value="<?= sanitize($c['criterion_label']) ?>"
                                           <?= !$is_editable ? 'disabled' : 'required' ?>>
                                </div>
                                <div class="col-6 col-md-2">
                                    <label class="form-label small fw-semibold" style="color:#475569;">Max Pts</label>
                                    <input type="number" name="max_points" class="form-control form-control-sm"
                                           value="<?= $c['max_points'] ?>" step="0.01" min="0"
                                           <?= !$is_editable ? 'disabled' : 'required' ?>>
                                </div>
                                <div class="col-6 col-md-2">
                                    <label class="form-label small fw-semibold" style="color:#475569;">Weight %</label>
                                    <input type="number" name="weight_pct" class="form-control form-control-sm"
                                           value="<?= $c['weight_pct'] ?>" step="0.01" min="0" max="100"
                                           <?= !$is_editable ? 'disabled' : '' ?>>
                                </div>
                                <div class="col-12 col-md-2">
                                    <label class="form-label small fw-semibold" style="color:#475569;">Description</label>
                                    <input type="text" name="description" class="form-control form-control-sm"
                                           value="<?= sanitize($c['description'] ?? '') ?>"
                                           <?= !$is_editable ? 'disabled' : '' ?>>
                                </div>
                                <?php if ($is_editable): ?>
                                <div class="col-6 col-md-1 d-flex flex-column align-items-center">
                                    <label class="form-label small fw-semibold" style="color:#475569;">Active</label>
                                    <div class="form-check form-switch mt-1">
                                        <input class="form-check-input" type="checkbox" name="is_active"
                                               <?= $c['is_active'] ? 'checked' : '' ?>>
                                    </div>
                                </div>
                                <div class="col-6 col-md-auto d-flex gap-1 align-items-end">
                                    <button type="button" class="btn btn-sm btn-primary"
                                            onclick="confirmDelete('Save changes to this criterion?','cForm_<?= $c['criteria_id'] ?>','Save','bi-save')">
                                        <i class="bi bi-save me-1"></i>Save
                                    </button>
                                    <button type="button" class="btn btn-sm"
                                            style="background:#fef2f2;color:#dc2626;border:1px solid #fecaca;"
                                            onclick="showDelCrit(<?= $c['criteria_id'] ?>,<?= $active_cycle_id ?>,'<?= addslashes(htmlspecialchars($c['criterion_label'])) ?>')">
                                        <i class="bi bi-trash"></i>
                                    </button>
                                </div>
                                <?php endif; ?>
                            </div>
                        </form>
                        <form method="POST" id="delCritForm_<?= $c['criteria_id'] ?>" style="display:none;">
                            <input type="hidden" name="action" value="delete_criterion">
                            <input type="hidden" name="criteria_id" value="<?= $c['criteria_id'] ?>">
                            <input type="hidden" name="back_cycle_id" value="<?= $active_cycle_id ?>">
                            <input type="hidden" name="back_position" value="<?= htmlspecialchars($active_position) ?>">
                        </form>
                        <?php endforeach; ?>
                        <?php if (empty($items)): ?>
                        <div class="p-4 text-muted small text-center">
                            <i class="bi bi-inbox me-1"></i>No criteria for this KRA yet.
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
            </div>

            <!-- Add criterion -->
            <?php if ($is_editable): ?>
            <div class="pt-2" style="border-top:1px solid #f0f4fb;">
                <div class="d-flex justify-content-between align-items-center mb-2">
                    <span class="fw-semibold small" style="color:#1a3a6b;">
                        <i class="bi bi-plus-circle me-1"></i>Add Criterion
                        <?php if ($active_position): ?>
                        <span class="badge ms-1" style="background:#dbeafe;color:#1e4d8c;font-size:0.7rem;"><?= htmlspecialchars($active_position) ?></span>
                        <?php endif; ?>
                    </span>
                    <button class="btn btn-sm btn-primary" type="button"
                            data-bs-toggle="collapse" data-bs-target="#addCritCollapse">
                        <i class="bi bi-plus-lg me-1"></i>Add
                    </button>
                </div>
                <div class="collapse" id="addCritCollapse">
                <div class="p-3 rounded" style="background:#f8fafc;border:1px solid #e2e8f0;">
                <form method="POST" id="addCritForm">
                    <input type="hidden" name="action" value="add_criterion">
                    <input type="hidden" name="cycle_id" value="<?= $active_cycle_id ?>">
                    <input type="hidden" name="position_rank" value="<?= htmlspecialchars($active_position) ?>">
                    <div class="row g-2 align-items-end">
                        <div class="col-md-2">
                            <label class="form-label small fw-semibold" style="color:#475569;">KRA <span class="text-danger">*</span></label>
                            <select name="kra_category" class="form-select form-select-sm" required>
                                <?php foreach ($kra_list as $k): ?>
                                <option value="<?= $k ?>"><?= $k ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-2">
                            <label class="form-label small fw-semibold" style="color:#475569;">Key <span class="text-danger">*</span></label>
                            <input type="text" name="criterion_key" class="form-control form-control-sm"
                                   placeholder="e.g. kra1_patent" required>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label small fw-semibold" style="color:#475569;">Label <span class="text-danger">*</span></label>
                            <input type="text" name="criterion_label" class="form-control form-control-sm"
                                   placeholder="e.g. Patent / Utility Model" required>
                        </div>
                        <div class="col-md-1">
                            <label class="form-label small fw-semibold" style="color:#475569;">Max Pts <span class="text-danger">*</span></label>
                            <input type="number" name="max_points" class="form-control form-control-sm"
                                   step="0.01" min="0.01" placeholder="0.00" required>
                        </div>
                        <div class="col-md-1">
                            <label class="form-label small fw-semibold" style="color:#475569;">Weight %</label>
                            <input type="number" name="weight_pct" class="form-control form-control-sm"
                                   step="1" min="0" max="100" value="100">
                        </div>
                        <div class="col-md-2">
                            <label class="form-label small fw-semibold" style="color:#475569;">Description</label>
                            <input type="text" name="description" class="form-control form-control-sm" placeholder="Optional">
                        </div>
                        <div class="col-md-1">
                            <button type="button" class="btn btn-sm btn-primary w-100"
                                    onclick="confirmDelete('Add this criterion?','addCritForm','Add','bi-plus-circle')">
                                <i class="bi bi-plus-lg"></i>
                            </button>
                        </div>
                    </div>
                </form>
                </div>
                </div>
            </div>
            <?php endif; ?>
            <?php endif; // criteria_source ?>

            </div><!-- card-body -->
        </div><!-- criteria card -->

    <?php else: ?>
    <div class="neon-card d-flex flex-column align-items-center justify-content-center py-5 text-center" style="min-height:300px;">
        <i class="bi bi-calendar-range" style="font-size:2.5rem;color:#dbeafe;display:block;margin-bottom:0.75rem;"></i>
        <p class="fw-semibold mb-1" style="color:#475569;">Select a cycle to manage it</p>
        <p class="text-muted small">Or create a new cycle using the button above.</p>
    </div>
    <?php endif; ?>
    </div><!-- col-lg-9 -->
</div><!-- row -->

<!-- Cycle Review Modal -->
<div id="cycleReviewModal"
     style="display:none;position:fixed;inset:0;z-index:9999;background:rgba(0,0,0,0.5);
            align-items:center;justify-content:center;padding:1rem;">
    <div style="background:#fff;border-radius:14px;width:100%;max-width:520px;
                box-shadow:0 20px 60px rgba(0,0,0,0.2);overflow:hidden;">

        <!-- Header -->
        <div style="background:linear-gradient(135deg,#1a3a6b,#1e4d8c);padding:1.25rem 1.5rem;
                    display:flex;align-items:center;justify-content:space-between;">
            <div style="display:flex;align-items:center;gap:0.75rem;">
                <div style="width:36px;height:36px;border-radius:8px;background:rgba(255,255,255,0.12);
                            display:flex;align-items:center;justify-content:center;">
                    <i class="bi bi-calendar-check" style="color:#fff;font-size:1rem;"></i>
                </div>
                <div>
                    <div style="color:#fff;font-weight:700;font-size:0.95rem;">Review New Cycle</div>
                    <div style="color:rgba(255,255,255,0.6);font-size:0.72rem;">Please confirm the details before opening</div>
                </div>
            </div>
            <button onclick="document.getElementById('cycleReviewModal').style.display='none'"
                    style="background:none;border:none;color:rgba(255,255,255,0.7);
                           font-size:1.3rem;cursor:pointer;line-height:1;padding:0;"
                    onmouseover="this.style.color='#fff'" onmouseout="this.style.color='rgba(255,255,255,0.7)'">
                &times;
            </button>
        </div>

        <!-- Warning if open cycle exists -->
        <?php if ($open_cycle): ?>
        <div style="background:#fffbeb;border-bottom:1px solid #fde68a;padding:0.65rem 1.5rem;
                    display:flex;align-items:flex-start;gap:0.6rem;font-size:0.8rem;color:#92400e;">
            <i class="bi bi-exclamation-triangle-fill" style="color:#d97706;flex-shrink:0;margin-top:1px;"></i>
            <div>
                Opening this cycle will <strong>automatically close</strong> the currently open cycle:
                <strong><?= sanitize($open_cycle['cycle_name']) ?></strong>.
            </div>
        </div>
        <?php endif; ?>

        <!-- Summary -->
        <div style="padding:1.5rem;">

            <p style="font-size:0.78rem;color:#64748b;margin-bottom:1.25rem;">
                Review the details below. Once confirmed, the cycle will open immediately and faculty can begin submitting applications.
            </p>

            <div style="border:1px solid #e2e8f0;border-radius:10px;overflow:hidden;margin-bottom:1.25rem;">

                <!-- Cycle name -->
                <div style="display:flex;align-items:center;padding:0.75rem 1rem;border-bottom:1px solid #f1f5f9;background:#fafbff;">
                    <div style="width:28px;height:28px;border-radius:7px;background:#eff6ff;
                                display:flex;align-items:center;justify-content:center;flex-shrink:0;margin-right:0.75rem;">
                        <i class="bi bi-tag" style="color:#1e4d8c;font-size:0.8rem;"></i>
                    </div>
                    <div style="flex:1;">
                        <div style="font-size:0.65rem;color:#94a3b8;font-weight:700;text-transform:uppercase;letter-spacing:0.05em;">Cycle Name</div>
                        <div id="rv_name" style="font-size:0.88rem;font-weight:700;color:#0f172a;margin-top:1px;"></div>
                    </div>
                    <span style="font-size:0.65rem;font-weight:700;color:#16a34a;background:#f0fdf4;
                                 border:1px solid #bbf7d0;border-radius:20px;padding:2px 8px;">
                        Opens immediately
                    </span>
                </div>

                <!-- Dates row -->
                <div style="display:grid;grid-template-columns:1fr 1fr;border-bottom:1px solid #f1f5f9;">
                    <div style="padding:0.75rem 1rem;border-right:1px solid #f1f5f9;">
                        <div style="font-size:0.65rem;color:#94a3b8;font-weight:700;text-transform:uppercase;letter-spacing:0.05em;margin-bottom:2px;">
                            <i class="bi bi-calendar-event me-1 text-primary"></i>Start Date
                        </div>
                        <div id="rv_start" style="font-size:0.85rem;font-weight:600;color:#1e293b;"></div>
                    </div>
                    <div style="padding:0.75rem 1rem;">
                        <div style="font-size:0.65rem;color:#94a3b8;font-weight:700;text-transform:uppercase;letter-spacing:0.05em;margin-bottom:2px;">
                            <i class="bi bi-calendar-x me-1 text-danger"></i>End Date
                        </div>
                        <div id="rv_end" style="font-size:0.85rem;font-weight:600;color:#1e293b;"></div>
                    </div>
                </div>

                <!-- Submission deadline -->
                <div style="padding:0.75rem 1rem;background:#fffbeb;">
                    <div style="display:flex;align-items:center;gap:0.75rem;">
                        <div style="width:28px;height:28px;border-radius:7px;background:#fef3c7;
                                    display:flex;align-items:center;justify-content:center;flex-shrink:0;">
                            <i class="bi bi-alarm" style="color:#d97706;font-size:0.8rem;"></i>
                        </div>
                        <div style="flex:1;">
                            <div style="font-size:0.65rem;color:#94a3b8;font-weight:700;text-transform:uppercase;letter-spacing:0.05em;">Submission Deadline</div>
                            <div style="display:flex;align-items:center;gap:0.75rem;margin-top:2px;flex-wrap:wrap;">
                                <span id="rv_deadline" style="font-size:0.88rem;font-weight:700;color:#0f172a;"></span>
                                <span id="rv_deadline_note" style="font-size:0.72rem;font-weight:600;"></span>
                            </div>
                            <div style="font-size:0.68rem;color:#92400e;margin-top:3px;">
                                Faculty will not be able to submit applications after this date.
                            </div>
                        </div>
                    </div>
                </div>

            </div>

            <!-- Criteria note -->
            <div style="background:#f0f4fb;border:1px solid #dbeafe;border-radius:8px;
                        padding:0.65rem 0.9rem;font-size:0.78rem;color:#1e4d8c;margin-bottom:1.25rem;
                        display:flex;align-items:flex-start;gap:0.5rem;">
                <i class="bi bi-info-circle-fill flex-shrink-0" style="margin-top:1px;"></i>
                <div>
                    Scoring criteria from global defaults will be <strong>automatically seeded</strong> for all 19 position ranks.
                    You can customise them per position after the cycle is created.
                </div>
            </div>

            <!-- Action buttons -->
            <div style="display:flex;gap:0.75rem;">
                <button type="button"
                        onclick="document.getElementById('cycleReviewModal').style.display='none'"
                        style="flex:1;padding:0.6rem 1rem;border:1px solid #e2e8f0;border-radius:8px;
                               background:#fff;color:#475569;font-size:0.875rem;font-weight:600;
                               cursor:pointer;transition:all 0.15s;"
                        onmouseover="this.style.background='#f8fafc'"
                        onmouseout="this.style.background='#fff'">
                    <i class="bi bi-arrow-left me-1"></i>Go Back
                </button>
                <button type="button" onclick="confirmCreateCycle()"
                        style="flex:2;padding:0.6rem 1rem;border:none;border-radius:8px;
                               background:#1a3a6b;color:#fff;font-size:0.875rem;font-weight:700;
                               cursor:pointer;transition:background 0.15s;
                               display:flex;align-items:center;justify-content:center;gap:0.5rem;"
                        onmouseover="this.style.background='#1e4d8c'"
                        onmouseout="this.style.background='#1a3a6b'">
                    <i class="bi bi-check-circle-fill"></i>Confirm &amp; Open Cycle
                </button>
            </div>
        </div>

    </div>
</div>

<!-- Delete criterion confirmation modal -->
<div id="delCritModal" style="display:none;position:fixed;inset:0;z-index:9999;background:rgba(0,0,0,0.45);align-items:center;justify-content:center;">
    <div style="background:#fff;border-radius:10px;padding:1.75rem 2rem;max-width:420px;width:90%;box-shadow:0 20px 60px rgba(0,0,0,0.2);">
        <h6 style="color:#1a3a6b;margin-bottom:0.5rem;font-weight:700;"><i class="bi bi-exclamation-triangle-fill me-2"></i>Delete Criterion</h6>
        <p style="color:#475569;font-size:0.88rem;" class="mb-1">Permanently delete:</p>
        <p id="delCritName" style="color:#1e293b;font-weight:600;background:#f8fafc;border:1px solid #94a3b8;border-radius:6px;padding:0.5rem 0.75rem;font-size:0.88rem;" class="mb-3"></p>
        <p style="color:#64748b;font-size:0.8rem;" class="mb-3">This action cannot be undone.</p>
        <div class="d-flex gap-2 justify-content-end">
            <button type="button" class="btn btn-sm btn-secondary" onclick="document.getElementById('delCritModal').style.display='none'">Cancel</button>
            <button type="button" id="delCritConfirmBtn" class="btn btn-sm" style="background:#dc2626;color:#fff;border:1px solid #dc2626;"><i class="bi bi-trash me-1"></i>Delete</button>
        </div>
    </div>
</div>

<!-- Edit Cycle Modal -->
<div id="editCycleModal"
     style="display:none;position:fixed;inset:0;z-index:9999;background:rgba(0,0,0,0.5);
            align-items:center;justify-content:center;padding:1rem;">
    <div style="background:#fff;border-radius:14px;width:100%;max-width:540px;
                box-shadow:0 20px 60px rgba(0,0,0,0.2);overflow:hidden;">
        <div style="background:linear-gradient(135deg,#1a3a6b,#1e4d8c);padding:1.1rem 1.5rem;
                    display:flex;align-items:center;justify-content:space-between;">
            <div style="color:#fff;font-weight:700;font-size:0.95rem;">
                <i class="bi bi-pencil-square me-2"></i>Edit Cycle
            </div>
            <button onclick="document.getElementById('editCycleModal').style.display='none'"
                    style="background:none;border:none;color:rgba(255,255,255,0.7);font-size:1.3rem;cursor:pointer;line-height:1;"
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
                <div class="col-sm-4">
                    <label class="form-label fw-semibold small">Start Date</label>
                    <input type="date" name="start_date" id="ec_start" class="form-control"
                           value="<?= htmlspecialchars($panel_cycle['start_date'] ?? '') ?>">
                </div>
                <div class="col-sm-4">
                    <label class="form-label fw-semibold small">End Date</label>
                    <input type="date" name="end_date" id="ec_end" class="form-control"
                           value="<?= htmlspecialchars($panel_cycle['end_date'] ?? '') ?>">
                </div>
                <div class="col-sm-4">
                    <label class="form-label fw-semibold small">Submission Deadline</label>
                    <input type="date" name="submission_deadline" id="ec_deadline" class="form-control"
                           value="<?= htmlspecialchars($panel_cycle['submission_deadline'] ?? '') ?>">
                </div>
            </div>
            <div id="ec_error" style="display:none;margin-bottom:0.75rem;padding:0.5rem 0.85rem;
                 background:#fef2f2;border:1px solid #fecaca;border-radius:7px;font-size:0.8rem;color:#dc2626;"></div>
            <div style="display:flex;gap:0.75rem;justify-content:flex-end;">
                <button type="button"
                        onclick="document.getElementById('editCycleModal').style.display='none'"
                        style="padding:0.45rem 1.1rem;border:1px solid #cbd5e1;border-radius:7px;
                               background:#fff;font-size:0.85rem;cursor:pointer;">
                    Cancel
                </button>
                <button type="button" onclick="submitEditCycle()"
                        style="padding:0.45rem 1.3rem;border:none;border-radius:7px;
                               background:#1a3a6b;color:#fff;font-size:0.85rem;font-weight:600;cursor:pointer;">
                    <i class="bi bi-save me-1"></i>Save Changes
                </button>
            </div>
        </form>
    </div>
</div>

<script>
// Cycle name dropdown population and date auto-fill removed.
// Cycles are now created with a name only; timing is controlled by Open/Closed status.

// ── Cycle Review Modal ────────────────────────────────────────────────────
function fmtDate(val) {
    if (!val) return '—';
    const d = new Date(val + 'T00:00:00');
    return d.toLocaleDateString('en-PH', { year:'numeric', month:'long', day:'numeric' });
}

function openCycleReview() {
    const form = document.getElementById('createCycleForm');
    if (!form.checkValidity()) { form.reportValidity(); return; }

    const name     = document.getElementById('cc_name').value.trim();
    const start    = document.getElementById('cc_start').value;
    const end      = document.getElementById('cc_end').value;
    const deadline = document.getElementById('cc_deadline').value;

    // Validate date logic
    const errBox = document.getElementById('cycle_date_error');
    const errMsg = document.getElementById('cycle_date_error_msg');
    const showErr = (msg) => { errMsg.textContent = msg; errBox.style.display = 'block'; errBox.scrollIntoView({behavior:'smooth',block:'nearest'}); };
    errBox.style.display = 'none';

    if (start && end && end < start) {
        showErr('End date must be after the start date.');
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

    // Populate summary
    document.getElementById('rv_name').textContent     = name;
    document.getElementById('rv_start').textContent    = fmtDate(start);
    document.getElementById('rv_end').textContent      = fmtDate(end);
    document.getElementById('rv_deadline').textContent = fmtDate(deadline);

    // Days until deadline
    const today = new Date(); today.setHours(0,0,0,0);
    const dlDate = new Date(deadline + 'T00:00:00');
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


});

function openEditCycleModal() {
    document.getElementById('editCycleModal').style.display = 'flex';
    document.getElementById('ec_error').style.display = 'none';
}

function submitEditCycle() {
    const name     = document.getElementById('ec_name').value.trim();
    const start    = document.getElementById('ec_start').value;
    const end      = document.getElementById('ec_end').value;
    const deadline = document.getElementById('ec_deadline').value;
    const errEl    = document.getElementById('ec_error');

    if (!name) { errEl.textContent = 'Cycle name is required.'; errEl.style.display = 'block'; return; }
    if (start && end && end < start) { errEl.textContent = 'End date must be after start date.'; errEl.style.display = 'block'; return; }
    if (deadline && end && deadline > end) { errEl.textContent = 'Submission deadline cannot be after end date.'; errEl.style.display = 'block'; return; }

    errEl.style.display = 'none';
    document.getElementById('ec_name').closest('form').submit();
}

function showDelCrit(critId, cycleId, label) {
    document.getElementById('delCritName').textContent = label;
    document.getElementById('delCritModal').style.display = 'flex';
    document.getElementById('delCritConfirmBtn').onclick = function() {
        document.getElementById('delCritModal').style.display = 'none';
        document.getElementById('delCritForm_' + critId).submit();
    };
}
</script>
