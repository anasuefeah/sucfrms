<?php
if (!isAdmin()) { echo '<div class="alert alert-danger">Access denied.</div>'; return; }

// Handle updates
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'update_criterion') {
        $cid       = intval($_POST['criteria_id']);
        $label     = trim($_POST['criterion_label'] ?? '');
        $max_pts   = floatval($_POST['max_points'] ?? 0);
        $weight    = floatval($_POST['weight_pct'] ?? 100);
        $desc      = trim($_POST['description'] ?? '');
        $is_active = isset($_POST['is_active']) ? 1 : 0;

        // Fetch the criterion key so we can allow max_points = 0 for config-gap items
        $key_row = $pdo->prepare("SELECT criterion_key FROM scoring_criteria WHERE criteria_id=?");
        $key_row->execute([$cid]);
        $crit_key = $key_row->fetchColumn() ?: '';

        // kra1_c_mentor_competition is a confirmed source gap — max_points = 0 is valid
        // (means "not yet configured"). All other criteria must have max_points > 0.
        $allow_zero = ($crit_key === 'kra1_c_mentor_competition');

        if ($label && ($max_pts > 0 || $allow_zero)) {
            $pdo->prepare("UPDATE scoring_criteria SET criterion_label=?, max_points=?, weight_pct=?, description=?, is_active=?, updated_by=? WHERE criteria_id=?")
                ->execute([$label, $max_pts, $weight, $desc, $is_active, $_SESSION['user_id'], $cid]);

            if ($allow_zero && $max_pts === 0.0) {
                logAudit($pdo, $_SESSION['user_id'], 'Criteria Updated', "Reset CONFIG_MENTORSHIP_POINTS to 0 (unset) for criterion ID {$cid}.");
                flashMessage('warning', "CONFIG_MENTORSHIP_POINTS reset to <strong>0</strong> — mentorship entries will return PENDING_DOCUMENTATION until a non-zero value is set.");
            } elseif ($allow_zero) {
                logAudit($pdo, $_SESSION['user_id'], 'Criteria Updated', "CONFIG_MENTORSHIP_POINTS set to {$max_pts} for criterion ID {$cid}: {$label}");
                flashMessage('success', "CONFIG_MENTORSHIP_POINTS set to <strong>{$max_pts} pt(s)</strong>. Mentorship entries will now be scored. Remember: this value was not sourced from JC01 — confirm with CHED-RO before finalising.");
            } else {
                logAudit($pdo, $_SESSION['user_id'], 'Criteria Updated', "Updated criteria ID {$cid}: {$label} = {$max_pts} pts");
                flashMessage('success', "Criterion <strong>{$label}</strong> updated.");
            }
        } elseif ($label && !$allow_zero && $max_pts <= 0) {
            flashMessage('danger', "Max Points must be greater than 0 for <strong>{$label}</strong>.");
        }
    } elseif ($action === 'add_criterion') {
        $kra    = $_POST['kra_category'] ?? '';
        $key    = trim($_POST['criterion_key'] ?? '');
        $label  = trim($_POST['criterion_label'] ?? '');
        $max    = floatval($_POST['max_points'] ?? 0);
        $weight = floatval($_POST['weight_pct'] ?? 100);
        $desc   = trim($_POST['description'] ?? '');
        $kras   = ['Instruction','Research','Extension','Professional Development'];
        if (in_array($kra, $kras) && $key && $label && $max > 0) {
            $pdo->prepare("INSERT INTO scoring_criteria (kra_category, criterion_key, criterion_label, max_points, weight_pct, description, updated_by) VALUES (?,?,?,?,?,?,?)")
                ->execute([$kra, $key, $label, $max, $weight, $desc, $_SESSION['user_id']]);
            logAudit($pdo, $_SESSION['user_id'], 'Criteria Added', "Added new criterion: {$label} ({$kra}) = {$max} pts");
            flashMessage('success', "New criterion <strong>{$label}</strong> added.");
        }
    } elseif ($action === 'delete_criterion') {
        $cid = intval($_POST['criteria_id']);
        $row = $pdo->prepare("SELECT criterion_label FROM scoring_criteria WHERE criteria_id = ?");
        $row->execute([$cid]);
        $existing = $row->fetch();
        if ($existing) {
            $pdo->prepare("DELETE FROM scoring_criteria WHERE criteria_id = ?")
                ->execute([$cid]);
            logAudit($pdo, $_SESSION['user_id'], 'Criteria Deleted', "Deleted criterion ID {$cid}: {$existing['criterion_label']}");
            flashMessage('success', "Criterion <strong>" . htmlspecialchars($existing['criterion_label']) . "</strong> deleted.");
        }
    }
    echo "<script>window.location.href='index.php?page=config';</script>"; exit;
}

$criteria = $pdo->query("SELECT MIN(criteria_id) as criteria_id, kra_category, criterion_key, criterion_label, max_points, weight_pct, description, is_active, updated_by, updated_at FROM scoring_criteria GROUP BY criterion_key ORDER BY kra_category, MIN(criteria_id)")->fetchAll();
$grouped  = [];
foreach ($criteria as $c) $grouped[$c['kra_category']][] = $c;
$kra_list = ['Instruction','Research','Extension','Professional Development'];
?>

<?php showFlash(); ?>

<?php
// Show a banner if CONFIG_MENTORSHIP_POINTS is still unset (max_points = 0)
$mentor_row = $pdo->query("SELECT max_points FROM scoring_criteria WHERE criterion_key='kra1_c_mentor_competition' AND cycle_id IS NULL AND is_active=1 LIMIT 1")->fetch();
if ($mentor_row !== false && (float)$mentor_row['max_points'] === 0.0):
?>
<div style="display:flex;align-items:flex-start;gap:0.75rem;padding:0.85rem 1rem;background:#fef3c7;border:1px solid #fde68a;border-left:4px solid #f59e0b;border-radius:8px;margin-bottom:1.25rem;font-size:0.82rem;color:#92400e;">
    <i class="bi bi-exclamation-triangle-fill" style="font-size:1.1rem;flex-shrink:0;margin-top:1px;"></i>
    <div>
        <strong>ACTION REQUIRED — CONFIG_MENTORSHIP_POINTS is not set.</strong><br>
        The Points value for <em>Mentorship Services</em> (KRA I Criterion C) is blank in the official JC01 s.2026 circular (Section 15, item 2, p.78). Until an administrator sets it, all mentorship submissions will return <strong>PENDING_DOCUMENTATION</strong> and score zero.
        Open the <strong>Instruction</strong> accordion below, find "Criterion C – Mentor: Student/Team Competition Winner", and enter the confirmed value in Max Pts.
        Suggested starting point for discussion: <strong>1 pt</strong> — but confirm with CHED-RO or your adviser first. This is a design recommendation, not a sourced value.
    </div>
</div>
<?php endif; ?>

<!-- Page header -->
<div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:1rem;margin-bottom:1.5rem;">
    <div style="display:flex;align-items:center;gap:0.6rem;">
        <div style="width:36px;height:36px;border-radius:8px;background:#1e4d8c;display:flex;align-items:center;justify-content:center;flex-shrink:0;">
            <i class="bi bi-sliders" style="color:#fff;font-size:0.95rem;"></i>
        </div>
        <div>
            <div style="font-weight:700;color:#1a3a6b;font-size:0.95rem;">Scoring Criteria Configuration</div>
            <div style="font-size:0.72rem;color:#94a3b8;">Manage point values and weights per DBM-CHED JC01 s.2026. All changes are audit-logged.</div>
        </div>
    </div>
</div>

<!-- Accordion per KRA -->
<div class="accordion mb-4" id="criteriaAccordion">
<?php foreach ($kra_list as $kra):
    $items = $grouped[$kra] ?? [];
    $slug  = strtolower(str_replace([' ','/'],['-',''], $kra));
    $kra_colors = ['Instruction'=>'#1e4d8c','Research'=>'#1a3a6b','Extension'=>'#0369a1','Professional Development'=>'#1e4d8c'];
    $kra_col = $kra_colors[$kra] ?? '#1e4d8c';
?>
<div class="accordion-item border-0 mb-2" style="border-radius:10px;overflow:hidden;box-shadow:0 1px 6px rgba(0,0,0,0.07);">
    <h2 class="accordion-header">
        <button class="accordion-button collapsed"
                style="background:#fff;color:#1a3a6b;font-weight:700;font-size:0.88rem;border-left:4px solid <?= $kra_col ?>;border-radius:10px;"
                type="button" data-bs-toggle="collapse" data-bs-target="#kra-<?= $slug ?>">
            <span style="display:inline-flex;align-items:center;justify-content:center;width:28px;height:28px;border-radius:6px;background:<?= $kra_col ?>18;margin-right:0.6rem;">
                <i class="bi bi-sliders" style="color:<?= $kra_col ?>;font-size:0.75rem;"></i>
            </span>
            <?= $kra ?>
            <span style="background:<?= $kra_col ?>;color:#fff;font-size:0.65rem;font-weight:700;border-radius:20px;padding:1px 8px;margin-left:0.6rem;"><?= count($items) ?></span>
        </button>
    </h2>
    <div id="kra-<?= $slug ?>" class="accordion-collapse collapse" data-bs-parent="#criteriaAccordion">
        <div class="accordion-body" style="padding:0;background:#fff;">
            <?php foreach ($items as $idx => $c):
                $is_mentor = ($c['criterion_key'] === 'kra1_c_mentor_competition');
                $mentor_unset = $is_mentor && (float)$c['max_points'] === 0.0;
            ?>
            <form method="POST" id="criteriaForm_<?= $c['criteria_id'] ?>"
                  style="padding:0.85rem 1.25rem;border-bottom:1px solid #f1f5f9;<?= $mentor_unset ? 'background:#fffbeb;border-left:3px solid #f59e0b;' : '' ?>">
                <input type="hidden" name="action" value="update_criterion">
                <input type="hidden" name="criteria_id" value="<?= $c['criteria_id'] ?>">

                <?php if ($mentor_unset): ?>
                <div style="margin-bottom:0.65rem;padding:0.5rem 0.75rem;background:#fef3c7;border:1px solid #fde68a;border-radius:6px;font-size:0.78rem;color:#92400e;display:flex;align-items:flex-start;gap:0.5rem;">
                    <i class="bi bi-exclamation-triangle-fill flex-shrink-0" style="margin-top:1px;"></i>
                    <div>
                        <strong>CONFIG_MENTORSHIP_POINTS — Action Required</strong><br>
                        The Points column for Mentorship Services is <strong>blank in the official JC01 s.2026 circular</strong> (Section 15, item 2, p.78) — this is a confirmed source gap, not an extraction error.
                        All mentorship submissions are currently returning <strong>PENDING_DOCUMENTATION</strong> and scoring zero.<br>
                        Set <strong>Max Pts</strong> to a non-zero value only after confirming with CHED-RO or your adviser.
                        Suggested starting point for discussion: <strong>1 pt</strong> (matching Panel Member, Special/Capstone — lowest confirmed rate in this criterion).
                        This is a design recommendation, <em>not</em> a sourced value.
                    </div>
                </div>
                <?php elseif ($is_mentor): ?>
                <div style="margin-bottom:0.65rem;padding:0.4rem 0.75rem;background:#f0fdf4;border:1px solid #bbf7d0;border-radius:6px;font-size:0.75rem;color:#166534;">
                    <i class="bi bi-check-circle-fill me-1"></i>
                    <strong>CONFIG_MENTORSHIP_POINTS set to <?= number_format((float)$c['max_points'], 2) ?> pt(s).</strong>
                    Mentorship entries will now be scored using this value. Reminder: this was not sourced from JC01 — confirm with CHED-RO before finalising.
                </div>
                <?php endif; ?>
                <div style="display:grid;grid-template-columns:80px 1fr 100px 90px 1fr 60px auto;gap:0.6rem;align-items:end;">
                    <!-- Key (read-only) -->
                    <div>
                        <div style="font-size:0.65rem;font-weight:600;color:#94a3b8;text-transform:uppercase;letter-spacing:0.04em;margin-bottom:3px;">Key</div>
                        <div style="font-size:0.72rem;color:#94a3b8;background:#f8fafc;border:1px solid #e2e8f0;border-radius:6px;padding:6px 8px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;"
                             title="<?= sanitize($c['criterion_key']) ?>">
                            <?= sanitize(substr($c['criterion_key'],0,12)) ?>…
                        </div>
                    </div>
                    <!-- Label -->
                    <div>
                        <div style="font-size:0.65rem;font-weight:600;color:#64748b;text-transform:uppercase;letter-spacing:0.04em;margin-bottom:3px;">Label</div>
                        <input type="text" name="criterion_label" value="<?= sanitize($c['criterion_label']) ?>" required
                               style="width:100%;border:1.5px solid #e2e8f0;border-radius:6px;padding:6px 10px;font-size:0.82rem;color:#1e293b;background:#f8fafc;outline:none;"
                               onfocus="this.style.borderColor='#1e4d8c'" onblur="this.style.borderColor='#e2e8f0'">
                    </div>
                    <!-- Max points -->
                    <div>
                        <div style="font-size:0.65rem;font-weight:600;color:#64748b;text-transform:uppercase;letter-spacing:0.04em;margin-bottom:3px;">Max Pts</div>
                        <input type="number" name="max_points" value="<?= $c['max_points'] ?>" step="0.01" min="0" required
                               style="width:100%;border:1.5px solid #e2e8f0;border-radius:6px;padding:6px 10px;font-size:0.82rem;color:#1e293b;background:#f8fafc;outline:none;"
                               onfocus="this.style.borderColor='#1e4d8c'" onblur="this.style.borderColor='#e2e8f0'">
                    </div>
                    <!-- Weight -->
                    <div>
                        <div style="font-size:0.65rem;font-weight:600;color:#64748b;text-transform:uppercase;letter-spacing:0.04em;margin-bottom:3px;">Weight %</div>
                        <input type="number" name="weight_pct" value="<?= $c['weight_pct'] ?>" step="0.01" min="0" max="100"
                               style="width:100%;border:1.5px solid #e2e8f0;border-radius:6px;padding:6px 10px;font-size:0.82rem;color:#1e293b;background:#f8fafc;outline:none;"
                               onfocus="this.style.borderColor='#1e4d8c'" onblur="this.style.borderColor='#e2e8f0'">
                    </div>
                    <!-- Description -->
                    <div>
                        <div style="font-size:0.65rem;font-weight:600;color:#64748b;text-transform:uppercase;letter-spacing:0.04em;margin-bottom:3px;">Description</div>
                        <input type="text" name="description" value="<?= sanitize($c['description'] ?? '') ?>"
                               style="width:100%;border:1.5px solid #e2e8f0;border-radius:6px;padding:6px 10px;font-size:0.82rem;color:#1e293b;background:#f8fafc;outline:none;"
                               onfocus="this.style.borderColor='#1e4d8c'" onblur="this.style.borderColor='#e2e8f0'">
                    </div>
                    <!-- Active toggle -->
                    <div style="text-align:center;">
                        <div style="font-size:0.65rem;font-weight:600;color:#64748b;text-transform:uppercase;letter-spacing:0.04em;margin-bottom:3px;">Active</div>
                        <div class="form-check form-switch d-flex justify-content-center">
                            <input class="form-check-input" type="checkbox" name="is_active" <?= $c['is_active'] ? 'checked' : '' ?>>
                        </div>
                    </div>
                    <!-- Actions -->
                    <div style="display:flex;gap:0.35rem;align-items:center;">
                        <button type="button"
                                style="padding:5px 12px;border-radius:6px;background:#1a3a6b;color:#fff;border:none;font-size:0.75rem;font-weight:600;cursor:pointer;white-space:nowrap;"
                                onclick="confirmDelete('Save changes to this criterion?','criteriaForm_<?= $c['criteria_id'] ?>','Save','bi-save')">
                            <i class="bi bi-save"></i>
                        </button>
                        <button type="button"
                                style="padding:5px 10px;border-radius:6px;background:#f8fafc;color:#1e293b;border:1px solid #e2e8f0;font-size:0.75rem;cursor:pointer;"
                                onclick="confirmDeleteCriterion(<?= $c['criteria_id'] ?>,'<?= addslashes(htmlspecialchars($c['criterion_label'])) ?>')">
                            <i class="bi bi-trash"></i>
                        </button>
                    </div>
                </div>
            </form>
            <form method="POST" id="deleteForm_<?= $c['criteria_id'] ?>" style="display:none;">
                <input type="hidden" name="action" value="delete_criterion">
                <input type="hidden" name="criteria_id" value="<?= $c['criteria_id'] ?>">
            </form>
            <?php endforeach; ?>
            <?php if (empty($items)): ?>
            <div style="padding:2rem;text-align:center;color:#94a3b8;font-size:0.82rem;">No criteria in this KRA yet.</div>
            <?php endif; ?>
        </div>
    </div>
</div>
<?php endforeach; ?>
</div>

<!-- Add New Criterion -->
<div class="neon-card" style="padding:1.1rem 1.25rem;">
    <div style="display:flex;align-items:center;gap:0.6rem;margin-bottom:1rem;">
        <div style="width:32px;height:32px;border-radius:8px;background:#1e4d8c;display:flex;align-items:center;justify-content:center;flex-shrink:0;">
            <i class="bi bi-plus-circle" style="color:#fff;font-size:0.85rem;"></i>
        </div>
        <div style="font-weight:700;color:#1a3a6b;font-size:0.9rem;">Add New Criterion</div>
    </div>
    <form method="POST" id="addCriterionForm">
        <input type="hidden" name="action" value="add_criterion">
        <div style="display:grid;grid-template-columns:1fr 1fr 2fr 100px 90px 1fr;gap:0.75rem;align-items:end;margin-bottom:0.85rem;">
            <?php
            $field_style = "width:100%;border:1.5px solid #e2e8f0;border-radius:8px;padding:0.5rem 0.75rem;font-size:0.83rem;color:#1e293b;background:#f8fafc;outline:none;";
            $label_style = "font-size:0.68rem;font-weight:700;color:#64748b;text-transform:uppercase;letter-spacing:0.05em;display:block;margin-bottom:4px;";
            ?>
            <div>
                <label style="<?= $label_style ?>">KRA Category <span style="color:#334155;">*</span></label>
                <select name="kra_category" required
                        style="<?= $field_style ?>cursor:pointer;"
                        onfocus="this.style.borderColor='#1e4d8c'" onblur="this.style.borderColor='#e2e8f0'">
                    <?php foreach ($kra_list as $k): ?><option value="<?= $k ?>"><?= $k ?></option><?php endforeach; ?>
                </select>
            </div>
            <div>
                <label style="<?= $label_style ?>">Key (unique) <span style="color:#334155;">*</span></label>
                <input type="text" name="criterion_key" placeholder="e.g. kra1_patent" required
                       style="<?= $field_style ?>" onfocus="this.style.borderColor='#1e4d8c'" onblur="this.style.borderColor='#e2e8f0'">
            </div>
            <div>
                <label style="<?= $label_style ?>">Label <span style="color:#334155;">*</span></label>
                <input type="text" name="criterion_label" placeholder="e.g. Patent / Utility Model" required
                       style="<?= $field_style ?>" onfocus="this.style.borderColor='#1e4d8c'" onblur="this.style.borderColor='#e2e8f0'">
            </div>
            <div>
                <label style="<?= $label_style ?>">Max Pts <span style="color:#334155;">*</span></label>
                <input type="number" name="max_points" step="0.01" min="0.01" placeholder="0.00" required
                       style="<?= $field_style ?>" onfocus="this.style.borderColor='#1e4d8c'" onblur="this.style.borderColor='#e2e8f0'">
            </div>
            <div>
                <label style="<?= $label_style ?>">Weight %</label>
                <input type="number" name="weight_pct" step="0.01" min="0" max="100" value="100.00"
                       style="<?= $field_style ?>" onfocus="this.style.borderColor='#1e4d8c'" onblur="this.style.borderColor='#e2e8f0'">
            </div>
            <div>
                <label style="<?= $label_style ?>">Description</label>
                <input type="text" name="description" placeholder="Brief description"
                       style="<?= $field_style ?>" onfocus="this.style.borderColor='#1e4d8c'" onblur="this.style.borderColor='#e2e8f0'">
            </div>
        </div>
        <button type="button"
                style="background:#1e4d8c;color:#fff;border:none;border-radius:8px;padding:0.5rem 1.35rem;font-size:0.85rem;font-weight:600;cursor:pointer;"
                onclick="confirmDelete('Add this new criterion to the scoring config?','addCriterionForm','Add Criterion','bi-plus-circle')">
            <i class="bi bi-plus-circle me-1"></i>Add Criterion
        </button>
    </form>
</div>

<!-- Delete Criterion Modal -->
<div id="deleteCriterionModal" style="display:none;position:fixed;inset:0;z-index:9999;background:rgba(0,0,0,0.45);align-items:center;justify-content:center;">
    <div style="background:#fff;border-radius:14px;padding:2rem;max-width:440px;width:90%;box-shadow:0 20px 60px rgba(0,0,0,0.2);">
        <div style="display:flex;align-items:center;gap:0.75rem;margin-bottom:1rem;">
            <div style="width:40px;height:40px;border-radius:50%;background:#f8fafc;display:flex;align-items:center;justify-content:center;flex-shrink:0;">
                <i class="bi bi-exclamation-triangle-fill" style="color:#1e293b;font-size:1rem;"></i>
            </div>
            <div style="font-weight:700;color:#1e293b;font-size:1rem;">Delete Criterion</div>
        </div>
        <p style="color:#64748b;font-size:0.85rem;margin-bottom:0.75rem;">You are about to permanently delete:</p>
        <div id="deleteCriterionName" style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:8px;padding:0.6rem 0.9rem;font-weight:600;color:#1e293b;font-size:0.88rem;margin-bottom:1rem;"></div>
        <p style="color:#94a3b8;font-size:0.78rem;margin-bottom:1.25rem;">This cannot be undone. Existing KRA submissions referencing this criterion may be affected.</p>
        <div style="display:flex;gap:0.6rem;justify-content:flex-end;">
            <button type="button"
                    style="padding:0.45rem 1rem;border-radius:8px;background:#f1f5f9;color:#475569;border:1.5px solid #e2e8f0;font-size:0.82rem;font-weight:600;cursor:pointer;"
                    onclick="document.getElementById('deleteCriterionModal').style.display='none'">Cancel</button>
            <button type="button" id="confirmDeleteCriterionBtn"
                    style="padding:0.45rem 1rem;border-radius:8px;background:#dc2626;color:#fff;border:none;font-size:0.82rem;font-weight:600;cursor:pointer;">
                <i class="bi bi-trash me-1"></i>Delete
            </button>
        </div>
    </div>
</div>

<script>
function confirmDeleteCriterion(id, label) {
    document.getElementById('deleteCriterionName').textContent = label;
    document.getElementById('deleteCriterionModal').style.display = 'flex';
    document.getElementById('confirmDeleteCriterionBtn').onclick = function() {
        document.getElementById('deleteCriterionModal').style.display = 'none';
        document.getElementById('deleteForm_' + id).submit();
    };
}
</script>

