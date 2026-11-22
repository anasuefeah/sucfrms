<?php
if (!isAdmin()) { echo '<div class="alert alert-danger">Access denied.</div>'; return; }
ensureKraAssignmentTable($pdo);

$kra_categories = kraAssignmentCategories();

// KRA descriptions for context
$kra_descriptions = [
    'Instruction'            => 'Covers teaching load, instructional quality, student outcomes, and academic performance. Evaluators reviewing this KRA assess classroom instruction, syllabi compliance, and subject-matter delivery.',
    'Research'               => 'Covers research outputs, publications, presentations, and completed studies. Evaluators reviewing this KRA assess the quality and quantity of research work undertaken by the faculty.',
    'Extension'              => 'Covers community engagement, outreach programs, and extension services rendered by the faculty to partner communities or institutions.',
    'Professional Development' => 'Covers trainings, seminars, certifications, degrees earned, and other professional growth activities completed during the reclassification period.',
];

$cycles = $pdo->query("SELECT cycle_id, cycle_name, status FROM cycles ORDER BY created_at DESC")->fetchAll();
$cycles_by_id = array_column($cycles, null, 'cycle_id');

$cycle_id = (int)($_GET['cycle_id'] ?? 0);
if (!$cycle_id && $cycles) {
    foreach ($cycles as $cy) { if ($cy['status'] === 'open') { $cycle_id = (int)$cy['cycle_id']; break; } }
    if (!$cycle_id) $cycle_id = (int)$cycles[0]['cycle_id'];
}

$current_cycle = $cycles_by_id[$cycle_id] ?? null;
if (!$current_cycle): ?>
<div class="alert alert-danger kra-cycle-alert" role="alert">
    <i class="bi bi-exclamation-circle-fill" aria-hidden="true"></i>
    <div>A reclassification cycle is required before assigning KRAs to evaluators.
        <a href="index.php?page=cycles">Go to Cycles</a>.
    </div>
</div>
<?php return; endif;

$eval_id = (int)($_GET['eval_id'] ?? 0);
if (!$eval_id) {
    echo "<script>window.location.href='index.php?page=kra_assignments&cycle_id={$cycle_id}';</script>"; exit;
}

// Load evaluator
$ev_stmt = $pdo->prepare("SELECT user_id, checker_label, first_name, middle_name, last_name, email, status FROM users WHERE user_id=? AND role='evaluator'");
$ev_stmt->execute([$eval_id]);
$evaluator = $ev_stmt->fetch();
if (!$evaluator) {
    flashMessage('danger', 'Evaluator not found.');
    echo "<script>window.location.href='index.php?page=kra_assignments&cycle_id={$cycle_id}';</script>"; exit;
}

// Current assignments
$am_stmt = $pdo->prepare("SELECT kra_category FROM checker_kra_assignments WHERE cycle_id=? AND checker_id=?");
$am_stmt->execute([$cycle_id, $eval_id]);
$current_assignments = $am_stmt->fetchAll(PDO::FETCH_COLUMN);

// Step: 'select' or 'review'
$step = $_GET['step'] ?? 'select';

// ── POST: confirm & save ───────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action   = $_POST['action'] ?? '';
    $post_cid = (int)($_POST['cycle_id'] ?? 0);
    $post_eid = (int)($_POST['eval_id']  ?? 0);

    if (in_array($action, ['proceed_review', 'confirm_save'], true) && $post_cid !== $cycle_id) {
        flashMessage('danger', 'Select a valid cycle before assigning KRAs to evaluators.');
        echo "<script>window.location.href='index.php?page=kra_assignments&cycle_id={$cycle_id}';</script>"; exit;
    }

    if ($action === 'confirm_save' && $post_cid && $post_eid) {
        $new_kras = array_values(array_intersect(array_keys($kra_categories), $_POST['kra_assignments'] ?? []));
        $old_kras = $current_assignments;

        $pdo->beginTransaction();
        try {
            $pdo->prepare("DELETE FROM checker_kra_assignments WHERE cycle_id=? AND checker_id=?")->execute([$post_cid, $post_eid]);
            $ins = $pdo->prepare("INSERT IGNORE INTO checker_kra_assignments (cycle_id, checker_id, kra_category, assigned_by) VALUES (?,?,?,?)");
            foreach ($new_kras as $kra) $ins->execute([$post_cid, $post_eid, $kra, $_SESSION['user_id']]);

            $added   = array_diff($new_kras, $old_kras);
            $removed = array_diff($old_kras, $new_kras);
            $changes = [];
            foreach ($added   as $k) $changes[] = "added {$kra_categories[$k]}";
            foreach ($removed as $k) {
                $changes[] = "removed {$kra_categories[$k]}";
                $open = $pdo->prepare("SELECT COUNT(*) FROM kra_submissions ks
                    JOIN applications a ON a.application_id=ks.application_id
                    WHERE a.cycle_id=? AND ks.kra_category=?
                      AND a.status IN ('submitted','under_review','needs_revision')
                      AND (ks.verified_by=? OR ks.revision_by=?)");
                $open->execute([$post_cid, $k, $post_eid, $post_eid]);
                if ((int)$open->fetchColumn() > 0)
                    flashMessage('warning', "Warning: evaluator has open reviewed items in {$kra_categories[$k]}. Please follow up on those applications.");
            }
            $pdo->commit();

            $display = formatDisplayName($evaluator);
            logAudit($pdo, $_SESSION['user_id'], 'KRA Assignment Updated',
                "Cycle #{$post_cid} — {$display}: " . ($changes ? implode(', ', $changes) : 'no changes'));
            if (empty($_SESSION['flash'])) flashMessage('success', "KRA assignments saved for {$display}.");
        } catch (\Exception $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            flashMessage('danger', 'Failed to save: ' . htmlspecialchars($e->getMessage()));
        }
        echo "<script>window.location.href='index.php?page=kra_assignments&cycle_id={$post_cid}';</script>"; exit;
    }

    // Proceed to review step — carry selections via GET
    if ($action === 'proceed_review') {
        $selected = $_POST['kra_assignments'] ?? [];
        $selected = array_values(array_intersect(array_keys($kra_categories), $selected));
        $q = http_build_query([
            'page'     => 'kra_assign_evaluator',
            'cycle_id' => $post_cid,
            'eval_id'  => $post_eid,
            'step'     => 'review',
            'kras'     => implode(',', $selected),
        ]);
        echo "<script>window.location.href='index.php?{$q}';</script>"; exit;
    }
}

// Review step: read selected KRAs from GET
$review_kras = [];
if ($step === 'review' && isset($_GET['kras'])) {
    $review_kras = array_values(array_intersect(array_keys($kra_categories), explode(',', $_GET['kras'])));
}

$evlabel = checkerDisplayLabel($evaluator);
?>

<?php showFlash(); ?>

<!-- Page header -->
<div style="background:#fff;border:1px solid #e2e8f0;padding:0 1.25rem;margin-bottom:1.25rem;
            display:flex;align-items:center;justify-content:space-between;min-height:60px;flex-wrap:wrap;gap:0.5rem;">
    <div style="display:flex;align-items:center;gap:0.75rem;">
        <a href="index.php?page=kra_assignments&cycle_id=<?= $cycle_id ?>"
           style="width:32px;height:32px;background:#f8fafc;border:1px solid #e2e8f0;display:flex;align-items:center;justify-content:center;text-decoration:none;flex-shrink:0;"
           onmouseover="this.style.background='#1a3a6b';this.style.borderColor='#1a3a6b';this.querySelector('i').style.color='#fff'"
           onmouseout="this.style.background='#f8fafc';this.style.borderColor='#e2e8f0';this.querySelector('i').style.color='#64748b'">
            <i class="bi bi-arrow-left" style="color:#64748b;font-size:0.78rem;"></i>
        </a>
        <div style="width:36px;height:36px;background:#1a3a6b;display:flex;align-items:center;justify-content:center;flex-shrink:0;">
            <i class="bi bi-person-check" style="color:#fff;font-size:0.9rem;"></i>
        </div>
        <div>
            <div style="font-weight:700;color:#1a3a6b;font-size:0.95rem;line-height:1.2;">
                KRA Assignment &nbsp;—&nbsp; <?= htmlspecialchars($evlabel) ?>
            </div>
            <div style="font-size:0.72rem;color:#94a3b8;margin-top:2px;">
                <?= sanitize($evaluator['email']) ?>
                &nbsp;·&nbsp;
                <?= sanitize($current_cycle['cycle_name'] ?? '—') ?>
                <span style="font-size:0.6rem;font-weight:700;text-transform:uppercase;padding:1px 5px;margin-left:4px;
                             background:<?= ($current_cycle['status']??'')==='open'?'#f0f4fb':'#f8fafc' ?>;
                             color:<?= ($current_cycle['status']??'')==='open'?'#1a3a6b':'#94a3b8' ?>;
                             border:1px solid <?= ($current_cycle['status']??'')==='open'?'#dbeafe':'#e2e8f0' ?>;">
                    <?= ucfirst($current_cycle['status'] ?? '') ?>
                </span>
            </div>
        </div>
    </div>

    <!-- Step indicator -->
    <div style="display:flex;align-items:center;gap:0;">
        <div style="display:flex;align-items:center;gap:0.4rem;padding:0.3rem 0.75rem;
                    background:<?= $step==='select'?'#1a3a6b':'#f0f4fb' ?>;border:1px solid <?= $step==='select'?'#1a3a6b':'#dbeafe' ?>;">
            <span style="font-size:0.65rem;font-weight:700;color:<?= $step==='select'?'#fff':'#1a3a6b' ?>;">1 &nbsp; SELECT KRAs</span>
        </div>
        <div style="width:24px;height:1px;background:#e2e8f0;"></div>
        <div style="display:flex;align-items:center;gap:0.4rem;padding:0.3rem 0.75rem;
                    background:<?= $step==='review'?'#1a3a6b':'#f8fafc' ?>;border:1px solid <?= $step==='review'?'#1a3a6b':'#e2e8f0' ?>;">
            <span style="font-size:0.65rem;font-weight:700;color:<?= $step==='review'?'#fff':'#94a3b8' ?>;">2 &nbsp; REVIEW &amp; CONFIRM</span>
        </div>
    </div>
</div>

<?php if ($step === 'select'): ?>
<!-- ══════════════════════════════════════════════════════
     STEP 1 — SELECT KRAs
     ══════════════════════════════════════════════════════ -->

<form method="POST">
    <input type="hidden" name="action"   value="proceed_review">
    <input type="hidden" name="cycle_id" value="<?= $cycle_id ?>">
    <input type="hidden" name="eval_id"  value="<?= $eval_id ?>">

    <div style="display:grid;grid-template-columns:1fr 1fr;gap:1rem;margin-bottom:1rem;">
        <?php foreach ($kra_categories as $kra => $label):
            $checked = in_array($kra, $current_assignments, true);
            $desc    = $kra_descriptions[$kra] ?? '';
        ?>
        <label id="kra_card_<?= htmlspecialchars($kra) ?>"
               style="display:block;border:2px solid <?= $checked?'#1a3a6b':'#e2e8f0' ?>;
                      background:<?= $checked?'#f0f4fb':'#fff' ?>;
                      padding:1.1rem 1.25rem;cursor:pointer;user-select:none;"
               onclick="toggleCard(this)">
            <div style="display:flex;align-items:flex-start;justify-content:space-between;gap:0.75rem;">
                <div style="flex:1;">
                    <div style="display:flex;align-items:center;gap:0.5rem;margin-bottom:0.5rem;">
                        <div class="kra-indicator"
                             style="width:20px;height:20px;border:2px solid <?= $checked?'#1a3a6b':'#cbd5e1' ?>;
                                    background:<?= $checked?'#1a3a6b':'#fff' ?>;
                                    display:flex;align-items:center;justify-content:center;flex-shrink:0;">
                            <i class="bi bi-check-lg" style="color:#fff;font-size:0.65rem;<?= $checked?'':'display:none;' ?>pointer-events:none;"></i>
                        </div>
                        <span style="font-size:0.65rem;font-weight:700;text-transform:uppercase;letter-spacing:0.08em;
                                     color:<?= $checked?'#1a3a6b':'#94a3b8' ?>;"><?= $label ?></span>
                    </div>
                    <div style="font-size:0.88rem;font-weight:700;color:#0f172a;margin-bottom:0.4rem;"><?= htmlspecialchars($kra) ?></div>
                    <div style="font-size:0.75rem;color:#64748b;line-height:1.6;"><?= htmlspecialchars($desc) ?></div>
                </div>
            </div>
            <input type="checkbox" name="kra_assignments[]" value="<?= htmlspecialchars($kra) ?>"
                   class="kra-chk" style="display:none;" <?= $checked?'checked':'' ?>>
        </label>
        <?php endforeach; ?>
    </div>

    <!-- Footer -->
    <div style="background:#fff;border:1px solid #e2e8f0;padding:0.75rem 1.25rem;
                display:flex;align-items:center;justify-content:space-between;">
        <span style="font-size:0.72rem;color:#94a3b8;" id="selCount">
            <?= count($current_assignments) ?> KRA<?= count($current_assignments)!==1?'s':'' ?> selected
        </span>
        <div style="display:flex;gap:0.5rem;">
            <a href="index.php?page=kra_assignments&cycle_id=<?= $cycle_id ?>"
               style="padding:0.42rem 1rem;border:1px solid #e2e8f0;background:#fff;color:#475569;font-size:0.78rem;font-weight:600;text-decoration:none;display:inline-flex;align-items:center;"
               onmouseover="this.style.background='#f8fafc'" onmouseout="this.style.background='#fff'">Cancel</a>
            <button type="submit"
                    style="padding:0.42rem 1.25rem;background:#1a3a6b;color:#fff;border:none;font-size:0.78rem;font-weight:700;cursor:pointer;display:inline-flex;align-items:center;gap:5px;"
                    onmouseover="this.style.background='#152d54'" onmouseout="this.style.background='#1a3a6b'">
                Next &nbsp;<i class="bi bi-arrow-right" style="font-size:0.72rem;"></i>
            </button>
        </div>
    </div>
</form>

<script>
function toggleCard(lbl) {
    const chk  = lbl.querySelector('.kra-chk');
    const ind  = lbl.querySelector('.kra-indicator');
    const ico  = ind.querySelector('i');
    const klab = lbl.querySelector('span');
    chk.checked = !chk.checked;
    if (chk.checked) {
        lbl.style.borderColor  = '#1a3a6b';
        lbl.style.background   = '#f0f4fb';
        ind.style.borderColor  = '#1a3a6b';
        ind.style.background   = '#1a3a6b';
        ico.style.display      = 'inline';
        klab.style.color       = '#1a3a6b';
    } else {
        lbl.style.borderColor  = '#e2e8f0';
        lbl.style.background   = '#fff';
        ind.style.borderColor  = '#cbd5e1';
        ind.style.background   = '#fff';
        ico.style.display      = 'none';
        klab.style.color       = '#94a3b8';
    }
    const cnt = document.querySelectorAll('.kra-chk:checked').length;
    document.getElementById('selCount').textContent = cnt + ' KRA' + (cnt !== 1 ? 's' : '') + ' selected';
}
</script>

<?php elseif ($step === 'review'): ?>
<!-- ══════════════════════════════════════════════════════
     STEP 2 — REVIEW & CONFIRM
     ══════════════════════════════════════════════════════ -->

<div style="display:grid;grid-template-columns:1fr 320px;gap:1rem;align-items:start;">

<!-- Left: what will change -->
<div>
    <div style="background:#fff;border:1px solid #e2e8f0;overflow:hidden;margin-bottom:1rem;">
        <div style="padding:0.65rem 1.25rem;border-bottom:1px solid #e2e8f0;background:#f8fafc;">
            <div style="font-weight:700;color:#1a3a6b;font-size:0.82rem;">Selected KRA Categories</div>
            <div style="font-size:0.65rem;color:#94a3b8;margin-top:1px;">These KRAs will be assigned to <?= htmlspecialchars($evlabel) ?>.</div>
        </div>
        <?php if (empty($review_kras)): ?>
        <div style="padding:1.5rem 1.25rem;font-size:0.82rem;color:#94a3b8;font-style:italic;">
            No KRAs selected. <a href="index.php?page=kra_assign_evaluator&cycle_id=<?= $cycle_id ?>&eval_id=<?= $eval_id ?>" style="color:#1a3a6b;">Go back to select.</a>
        </div>
        <?php else: ?>
        <?php foreach ($review_kras as $kra):
            $desc = $kra_descriptions[$kra] ?? '';
        ?>
        <div style="padding:1rem 1.25rem;border-bottom:1px solid #f1f5f9;display:flex;align-items:flex-start;gap:0.85rem;">
            <div style="width:32px;height:32px;background:#1a3a6b;display:flex;align-items:center;justify-content:center;flex-shrink:0;margin-top:1px;">
                <i class="bi bi-check-lg" style="color:#fff;font-size:0.78rem;"></i>
            </div>
            <div>
                <div style="display:flex;align-items:center;gap:0.5rem;margin-bottom:3px;">
                    <span style="font-size:0.62rem;font-weight:700;text-transform:uppercase;letter-spacing:0.07em;color:#1a3a6b;background:#f0f4fb;border:1px solid #dbeafe;padding:1px 7px;"><?= htmlspecialchars($kra_categories[$kra]) ?></span>
                    <span style="font-weight:700;color:#0f172a;font-size:0.88rem;"><?= htmlspecialchars($kra) ?></span>
                </div>
                <div style="font-size:0.75rem;color:#64748b;line-height:1.6;"><?= htmlspecialchars($desc) ?></div>
            </div>
        </div>
        <?php endforeach; ?>
        <?php endif; ?>
    </div>

    <!-- What is being removed -->
    <?php $being_removed = array_diff($current_assignments, $review_kras); ?>
    <?php $being_added   = array_diff($review_kras, $current_assignments); ?>
    <?php if ($being_removed): ?>
    <div style="background:#fff;border:1px solid #fecaca;border-left:3px solid #dc2626;overflow:hidden;">
        <div style="padding:0.65rem 1.25rem;border-bottom:1px solid #fecaca;background:#fef2f2;">
            <div style="font-weight:700;color:#dc2626;font-size:0.82rem;">
                <i class="bi bi-exclamation-triangle-fill" style="margin-right:5px;"></i>
                KRAs Being Removed
            </div>
            <div style="font-size:0.65rem;color:#ef4444;margin-top:1px;">These will be unassigned from this evaluator for this cycle.</div>
        </div>
        <?php foreach ($being_removed as $kra): ?>
        <div style="padding:0.65rem 1.25rem;border-bottom:1px solid #fef2f2;display:flex;align-items:center;gap:0.6rem;">
            <i class="bi bi-dash-circle" style="color:#dc2626;font-size:0.82rem;"></i>
            <span style="font-size:0.82rem;font-weight:600;color:#1e293b;"><?= htmlspecialchars($kra) ?></span>
            <span style="font-size:0.65rem;color:#94a3b8;"><?= htmlspecialchars($kra_categories[$kra]) ?></span>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>
</div>

<!-- Right: summary card + confirm -->
<div style="background:#fff;border:1px solid #e2e8f0;overflow:hidden;position:sticky;top:70px;">
    <div style="background:#1a3a6b;padding:0.75rem 1rem;">
        <div style="color:#fff;font-weight:700;font-size:0.85rem;">Assignment Summary</div>
        <div style="color:rgba(255,255,255,0.5);font-size:0.65rem;margin-top:1px;"><?= sanitize($current_cycle['cycle_name'] ?? '—') ?></div>
    </div>

    <div style="padding:1rem;">
        <!-- Evaluator info -->
        <div style="margin-bottom:1rem;padding-bottom:1rem;border-bottom:1px solid #f1f5f9;">
            <div style="font-size:0.6rem;font-weight:700;text-transform:uppercase;letter-spacing:0.07em;color:#94a3b8;margin-bottom:4px;">Evaluator</div>
            <div style="font-weight:700;color:#0f172a;font-size:0.85rem;"><?= htmlspecialchars($evlabel) ?></div>
            <div style="font-size:0.68rem;color:#94a3b8;"><?= sanitize($evaluator['email']) ?></div>
        </div>

        <!-- KRA count -->
        <div style="margin-bottom:1rem;padding-bottom:1rem;border-bottom:1px solid #f1f5f9;">
            <div style="font-size:0.6rem;font-weight:700;text-transform:uppercase;letter-spacing:0.07em;color:#94a3b8;margin-bottom:6px;">Assigned KRAs After Save</div>
            <?php if (empty($review_kras)): ?>
            <div style="font-size:0.78rem;color:#94a3b8;font-style:italic;">None</div>
            <?php else: ?>
            <div style="display:flex;flex-wrap:wrap;gap:4px;">
                <?php foreach ($review_kras as $kra): ?>
                <span style="font-size:0.68rem;font-weight:700;padding:3px 9px;background:#f0f4fb;color:#1a3a6b;border:1px solid #dbeafe;">
                    <?= htmlspecialchars($kra_categories[$kra]) ?>
                </span>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
        </div>

        <!-- Change summary -->
        <div style="margin-bottom:1rem;">
            <div style="font-size:0.6rem;font-weight:700;text-transform:uppercase;letter-spacing:0.07em;color:#94a3b8;margin-bottom:6px;">Changes</div>
            <?php if (empty($being_added) && empty($being_removed)): ?>
            <div style="font-size:0.75rem;color:#94a3b8;">No changes from current assignments.</div>
            <?php else: ?>
            <?php foreach ($being_added as $k): ?>
            <div style="font-size:0.75rem;color:#16a34a;margin-bottom:3px;">
                <i class="bi bi-plus-circle-fill" style="margin-right:4px;"></i>Adding <?= htmlspecialchars($kra_categories[$k]) ?>
            </div>
            <?php endforeach; ?>
            <?php foreach ($being_removed as $k): ?>
            <div style="font-size:0.75rem;color:#dc2626;margin-bottom:3px;">
                <i class="bi bi-dash-circle-fill" style="margin-right:4px;"></i>Removing <?= htmlspecialchars($kra_categories[$k]) ?>
            </div>
            <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>

    <!-- Actions -->
    <form method="POST">
        <input type="hidden" name="action"   value="confirm_save">
        <input type="hidden" name="cycle_id" value="<?= $cycle_id ?>">
        <input type="hidden" name="eval_id"  value="<?= $eval_id ?>">
        <?php foreach ($review_kras as $kra): ?>
        <input type="hidden" name="kra_assignments[]" value="<?= htmlspecialchars($kra) ?>">
        <?php endforeach; ?>
        <div style="padding:0.75rem 1rem;border-top:1px solid #e2e8f0;background:#f8fafc;display:flex;flex-direction:column;gap:0.4rem;">
            <button type="submit"
                    style="width:100%;padding:0.5rem;background:#1a3a6b;color:#fff;border:none;font-size:0.82rem;font-weight:700;cursor:pointer;display:inline-flex;align-items:center;justify-content:center;gap:5px;"
                    onmouseover="this.style.background='#152d54'" onmouseout="this.style.background='#1a3a6b'">
                <i class="bi bi-check-circle" style="font-size:0.78rem;"></i> Confirm &amp; Save
            </button>
            <a href="index.php?page=kra_assign_evaluator&cycle_id=<?= $cycle_id ?>&eval_id=<?= $eval_id ?>"
               style="width:100%;padding:0.45rem;border:1px solid #e2e8f0;background:#fff;color:#475569;font-size:0.78rem;font-weight:600;text-decoration:none;display:flex;align-items:center;justify-content:center;box-sizing:border-box;"
               onmouseover="this.style.background='#f8fafc'" onmouseout="this.style.background='#fff'">
                <i class="bi bi-arrow-left" style="margin-right:4px;font-size:0.72rem;"></i> Back to Edit
            </a>
        </div>
    </form>
</div>

</div><!-- /grid -->

<?php endif; ?>
