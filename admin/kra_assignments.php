<?php
if (!isAdmin()) { echo '<div class="alert alert-danger">Access denied.</div>'; return; }
ensureKraAssignmentTable($pdo);

$kra_categories = kraAssignmentCategories();
$cycles         = $pdo->query("SELECT cycle_id, cycle_name, status FROM cycles ORDER BY created_at DESC")->fetchAll();

$cycle_id = (int)($_GET['cycle_id'] ?? 0);
if (!$cycle_id && $cycles) {
    foreach ($cycles as $cy) { if ($cy['status'] === 'open') { $cycle_id = (int)$cy['cycle_id']; break; } }
    if (!$cycle_id) $cycle_id = (int)$cycles[0]['cycle_id'];
}

// ── POST handlers ─────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action   = $_POST['action'] ?? '';
    $post_cid = (int)($_POST['cycle_id'] ?? 0);
    $post_eid = (int)($_POST['eval_id']  ?? 0);

    if ($action === 'save_evaluator_kras' && $post_cid && $post_eid) {
        $new_kras = array_values(array_intersect(array_keys($kra_categories), $_POST['kra_assignments'] ?? []));
        $old_stmt = $pdo->prepare("SELECT kra_category FROM checker_kra_assignments WHERE cycle_id=? AND checker_id=?");
        $old_stmt->execute([$post_cid, $post_eid]);
        $old_kras = $old_stmt->fetchAll(PDO::FETCH_COLUMN);
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
                      AND a.status IN ('submitted','under_review','needs_revision','talisay_review')
                      AND (ks.verified_by=? OR ks.revision_by=?)");
                $open->execute([$post_cid, $k, $post_eid, $post_eid]);
                if ((int)$open->fetchColumn() > 0)
                    flashMessage('warning', "Warning: evaluator has open reviewed items in {$kra_categories[$k]}.");
            }
            $pdo->commit();
            $en = $pdo->prepare("SELECT first_name,middle_name,last_name FROM users WHERE user_id=?");
            $en->execute([$post_eid]);
            $enr = $en->fetch();
            $display = $enr ? formatDisplayName($enr) : "Evaluator #{$post_eid}";
            logAudit($pdo, $_SESSION['user_id'], 'KRA Assignment Updated',
                "Cycle #{$post_cid} — {$display}: " . ($changes ? implode(', ', $changes) : 'no changes'));
            if (empty($_SESSION['flash'])) flashMessage('success', "Assignments saved for {$display}.");
        } catch (\Exception $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            flashMessage('danger', 'Failed to save: ' . htmlspecialchars($e->getMessage()));
        }
        echo "<script>window.location.href='index.php?page=kra_assignments&cycle_id={$post_cid}';</script>"; exit;
    }

    if ($action === 'remove_evaluator_kras' && $post_cid && $post_eid) {
        $pdo->prepare("DELETE FROM checker_kra_assignments WHERE cycle_id=? AND checker_id=?")->execute([$post_cid, $post_eid]);
        $en = $pdo->prepare("SELECT first_name,middle_name,last_name FROM users WHERE user_id=?");
        $en->execute([$post_eid]);
        $enr = $en->fetch();
        $display = $enr ? formatDisplayName($enr) : "Evaluator #{$post_eid}";
        logAudit($pdo, $_SESSION['user_id'], 'KRA Assignment Removed', "Removed all KRAs for {$display} from cycle #{$post_cid}.");
        flashMessage('success', "All KRA assignments removed for {$display}.");
        echo "<script>window.location.href='index.php?page=kra_assignments&cycle_id={$post_cid}';</script>"; exit;
    }

    if ($action === 'copy_kra_assignments') {
        $from = (int)($_POST['from_cycle_id'] ?? 0);
        $to   = (int)($_POST['to_cycle_id']   ?? 0);
        if ($from && $to && $from !== $to) {
            $pdo->prepare("DELETE FROM checker_kra_assignments WHERE cycle_id=?")->execute([$to]);
            $pdo->prepare("INSERT IGNORE INTO checker_kra_assignments (cycle_id,checker_id,kra_category,assigned_by)
                SELECT ?,checker_id,kra_category,? FROM checker_kra_assignments WHERE cycle_id=?")
                ->execute([$to, $_SESSION['user_id'], $from]);
            logAudit($pdo, $_SESSION['user_id'], 'KRA Assignment Copied', "Copied from cycle #{$from} to #{$to}.");
            flashMessage('success', 'Assignment setup copied.');
        } else {
            flashMessage('danger', 'Please select a different source cycle.');
        }
        echo "<script>window.location.href='index.php?page=kra_assignments&cycle_id={$to}';</script>"; exit;
    }

    echo "<script>window.location.href='index.php?page=kra_assignments&cycle_id={$post_cid}';</script>"; exit;
}

// ── Fetch ─────────────────────────────────────────────────────────────────
$evaluators = $pdo->query("SELECT user_id,checker_label,first_name,middle_name,last_name,email,status
    FROM users WHERE role='evaluator' ORDER BY status DESC, user_id ASC")->fetchAll();

$assignment_map  = [];
$coverage_counts = array_fill_keys(array_keys($kra_categories), 0);
if ($cycle_id) {
    $am = $pdo->prepare("SELECT checker_id,kra_category FROM checker_kra_assignments WHERE cycle_id=?");
    $am->execute([$cycle_id]);
    foreach ($am->fetchAll() as $row) {
        $assignment_map[(int)$row['checker_id']][] = $row['kra_category'];
        if (isset($coverage_counts[$row['kra_category']])) $coverage_counts[$row['kra_category']]++;
    }
}

$missing_kras  = array_keys(array_filter($coverage_counts, fn($c) => (int)$c === 0));
$current_cycle = null;
foreach ($cycles as $cy) { if ((int)$cy['cycle_id'] === $cycle_id) { $current_cycle = $cy; break; } }

$js_assignments = [];
foreach ($evaluators as $ev) $js_assignments[(int)$ev['user_id']] = $assignment_map[(int)$ev['user_id']] ?? [];
?>

<?php showFlash(); ?>

<!-- Page header -->
<div style="background:#fff;border:1px solid #e2e8f0;padding:0 1.25rem;margin-bottom:1.25rem;
            display:flex;align-items:center;justify-content:space-between;min-height:60px;flex-wrap:wrap;gap:0.5rem;">
    <div style="display:flex;align-items:center;gap:0.75rem;">
        <a href="index.php?page=manage_users"
           style="width:32px;height:32px;background:#f8fafc;border:1px solid #e2e8f0;display:flex;align-items:center;justify-content:center;text-decoration:none;flex-shrink:0;"
           onmouseover="this.style.background='#1a3a6b';this.style.borderColor='#1a3a6b';this.querySelector('i').style.color='#fff'"
           onmouseout="this.style.background='#f8fafc';this.style.borderColor='#e2e8f0';this.querySelector('i').style.color='#64748b'">
            <i class="bi bi-arrow-left" style="color:#64748b;font-size:0.78rem;"></i>
        </a>
        <div style="width:36px;height:36px;background:#1a3a6b;display:flex;align-items:center;justify-content:center;flex-shrink:0;">
            <i class="bi bi-ui-checks-grid" style="color:#fff;font-size:0.9rem;"></i>
        </div>
        <div>
            <div style="font-weight:700;color:#1a3a6b;font-size:0.95rem;line-height:1.2;">KRA Assignments</div>
            <div style="font-size:0.72rem;color:#94a3b8;margin-top:2px;">Click a row to edit &nbsp;·&nbsp; An evaluator can hold multiple KRAs</div>
        </div>
    </div>
    <?php if ($cycles): ?>
    <form method="GET" action="index.php" style="display:flex;align-items:center;gap:0.4rem;">
        <input type="hidden" name="page" value="kra_assignments">
        <span style="font-size:0.7rem;font-weight:700;text-transform:uppercase;letter-spacing:0.07em;color:#94a3b8;">Cycle</span>
        <select name="cycle_id" onchange="this.form.submit()"
                style="border:1px solid #e2e8f0;padding:0.35rem 0.65rem;font-size:0.78rem;color:#1e293b;background:#fff;outline:none;cursor:pointer;min-width:220px;"
                onfocus="this.style.borderColor='#1a3a6b'" onblur="this.style.borderColor='#e2e8f0'">
            <?php foreach ($cycles as $cy): ?>
            <option value="<?= (int)$cy['cycle_id'] ?>" <?= $cycle_id===(int)$cy['cycle_id']?'selected':'' ?>>
                <?= sanitize($cy['cycle_name']) ?> (<?= ucfirst($cy['status']) ?>)
            </option>
            <?php endforeach; ?>
        </select>
    </form>
    <?php endif; ?>
</div>

<?php if ($missing_kras): ?>
<div style="display:flex;align-items:flex-start;gap:0.65rem;background:#fffbeb;border:1px solid #fde68a;border-left:3px solid #d97706;
            padding:0.65rem 1rem;margin-bottom:1rem;font-size:0.78rem;color:#92400e;">
    <i class="bi bi-exclamation-triangle-fill" style="flex-shrink:0;margin-top:1px;"></i>
    <div><strong>Incomplete Coverage —</strong>
    No evaluator assigned to: <strong><?= htmlspecialchars(implode(', ', array_map(fn($k) => $kra_categories[$k], $missing_kras))) ?></strong>.
    Applications under these categories cannot proceed to evaluation.</div>
</div>
<?php endif; ?>

<!-- Coverage summary bar -->
<?php $kkeys = array_keys($kra_categories); $klast = end($kkeys); ?>
<div style="background:#fff;border:1px solid #e2e8f0;display:grid;grid-template-columns:repeat(<?= count($kra_categories) ?>,1fr);margin-bottom:1rem;">
    <?php foreach ($kra_categories as $kra => $label):
        $cnt = (int)($coverage_counts[$kra] ?? 0); $ok = $cnt > 0; ?>
    <div style="padding:0.75rem 1rem;text-align:center;<?= $kra !== $klast ? 'border-right:1px solid #f1f5f9;' : '' ?>">
        <div style="font-size:0.6rem;font-weight:700;text-transform:uppercase;letter-spacing:0.07em;color:#94a3b8;margin-bottom:4px;"><?= $label ?></div>
        <div style="font-size:1.3rem;font-weight:800;color:<?= $ok?'#1a3a6b':'#dc2626' ?>;line-height:1;"><?= $cnt ?></div>
        <div style="font-size:0.65rem;color:<?= $ok?'#94a3b8':'#dc2626' ?>;margin-top:2px;"><?= $ok?'evaluator'.($cnt!==1?'s':''):'Unassigned' ?></div>
    </div>
    <?php endforeach; ?>
</div>

<!-- Evaluator list (full width now) -->
<div style="background:#fff;border:1px solid #e2e8f0;overflow:hidden;">
    <div style="padding:0.5rem 1rem;border-bottom:1px solid #e2e8f0;background:#f8fafc;
                display:flex;align-items:center;justify-content:space-between;">
        <span style="font-size:0.65rem;font-weight:700;text-transform:uppercase;letter-spacing:0.07em;color:#64748b;">
            <?= count($evaluators) ?> Evaluator<?= count($evaluators)!==1?'s':'' ?>
        </span>
        <?php if (count($cycles) > 1 && $cycle_id): ?>
        <form method="POST" style="display:flex;align-items:center;gap:0.4rem;">
            <input type="hidden" name="action" value="copy_kra_assignments">
            <input type="hidden" name="to_cycle_id" value="<?= $cycle_id ?>">
            <span style="font-size:0.68rem;color:#94a3b8;white-space:nowrap;">Copy setup from</span>
            <select name="from_cycle_id"
                    style="border:1px solid #e2e8f0;padding:0.28rem 0.5rem;font-size:0.7rem;color:#1e293b;background:#fff;outline:none;cursor:pointer;max-width:180px;"
                    onfocus="this.style.borderColor='#1a3a6b'" onblur="this.style.borderColor='#e2e8f0'">
                <?php foreach ($cycles as $cy): if ((int)$cy['cycle_id'] === $cycle_id) continue; ?>
                <option value="<?= (int)$cy['cycle_id'] ?>"><?= sanitize($cy['cycle_name']) ?></option>
                <?php endforeach; ?>
            </select>
            <button style="padding:0.28rem 0.65rem;border:1px solid #e2e8f0;background:#fff;color:#475569;font-size:0.7rem;font-weight:600;cursor:pointer;"
                    onmouseover="this.style.background='#1a3a6b';this.style.color='#fff';this.style.borderColor='#1a3a6b'"
                    onmouseout="this.style.background='#fff';this.style.color='#475569';this.style.borderColor='#e2e8f0'">Copy</button>
        </form>
        <?php endif; ?>
    </div>

    <?php if (!$evaluators): ?>
    <div style="padding:3rem;text-align:center;color:#94a3b8;font-size:0.82rem;">
        <i class="bi bi-person-x" style="font-size:1.5rem;display:block;margin-bottom:0.5rem;color:#e2e8f0;"></i>
        No evaluator accounts found. <a href="index.php?page=manage_users" style="color:#1a3a6b;">Create one in Manage Users.</a>
    </div>
    <?php else: ?>
    <table style="width:100%;border-collapse:collapse;font-size:0.8rem;">
        <thead>
        <tr style="background:#f8fafc;border-bottom:1px solid #e2e8f0;">
            <th style="padding:0.5rem 1rem;text-align:left;font-size:0.6rem;font-weight:700;text-transform:uppercase;letter-spacing:0.07em;color:#64748b;">Evaluator</th>
            <th style="padding:0.5rem 0.75rem;text-align:left;font-size:0.6rem;font-weight:700;text-transform:uppercase;letter-spacing:0.07em;color:#64748b;">Assigned KRAs</th>
            <th style="padding:0.5rem 1rem;text-align:right;font-size:0.6rem;font-weight:700;text-transform:uppercase;letter-spacing:0.07em;color:#64748b;"></th>
        </tr>
        </thead>
        <tbody>
        <?php foreach ($evaluators as $ev):
            $eid      = (int)$ev['user_id'];
            $kras     = $assignment_map[$eid] ?? [];
            $inactive = $ev['status'] !== 'active';
            $evlabel  = checkerDisplayLabel($ev);
        ?>
        <tr id="evrow_<?= $eid ?>"
            data-name="<?= htmlspecialchars($evlabel, ENT_QUOTES) ?>"
            data-email="<?= htmlspecialchars(sanitize($ev['email']), ENT_QUOTES) ?>"
            style="border-bottom:1px solid #f1f5f9;<?= $inactive?'opacity:0.55;':'' ?>;cursor:pointer;"
            onclick="window.location.href='index.php?page=kra_assign_evaluator&cycle_id=<?= $cycle_id ?>&eval_id=<?= $eid ?>'"
            onmouseover="this.style.background='#f0f4fb'"
            onmouseout="this.style.background=''">
            <td style="padding:0.65rem 1rem;" onclick="window.location.href='index.php?page=kra_assign_evaluator&cycle_id=<?= $cycle_id ?>&eval_id=<?= $eid ?>'">
                <div style="display:flex;align-items:center;gap:0.6rem;">
                    <div id="evsel_<?= $eid ?>" style="width:3px;height:34px;background:transparent;flex-shrink:0;"></div>
                    <div>
                        <div style="font-weight:600;color:#0f172a;font-size:0.82rem;line-height:1.3;"><?= htmlspecialchars($evlabel) ?></div>
                        <div style="font-size:0.65rem;color:#94a3b8;"><?= sanitize($ev['email']) ?></div>
                        <?php if ($inactive): ?>
                        <div style="font-size:0.6rem;color:#dc2626;font-weight:700;">INACTIVE</div>
                        <?php endif; ?>
                    </div>
                </div>
            </td>
            <td style="padding:0.65rem 0.75rem;vertical-align:middle;">
                <?php if (empty($kras)): ?>
                <span style="font-size:0.68rem;color:#94a3b8;font-style:italic;">None assigned</span>
                <?php else: ?>
                <div style="display:flex;flex-wrap:wrap;gap:3px;">
                    <?php foreach ($kras as $k): ?>
                    <span style="font-size:0.65rem;font-weight:700;padding:2px 7px;background:#f0f4fb;color:#1a3a6b;border:1px solid #dbeafe;">
                        <?= htmlspecialchars($kra_categories[$k] ?? $k) ?>
                    </span>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
            </td>
            <td style="padding:0.65rem 1rem;text-align:right;white-space:nowrap;vertical-align:middle;">
                <div style="display:inline-flex;gap:4px;">
                    <a href="index.php?page=kra_assign_evaluator&cycle_id=<?= $cycle_id ?>&eval_id=<?= $eid ?>"
                       title="Edit KRA assignments"
                       style="width:30px;height:30px;border:1px solid #e2e8f0;background:#f8fafc;color:#475569;cursor:pointer;display:inline-flex;align-items:center;justify-content:center;text-decoration:none;"
                       onmouseover="this.style.background='#1a3a6b';this.style.color='#fff';this.style.borderColor='#1a3a6b'"
                       onmouseout="this.style.background='#f8fafc';this.style.color='#475569';this.style.borderColor='#e2e8f0'">
                        <i class="bi bi-pencil" style="font-size:0.72rem;pointer-events:none;"></i>
                    </a>
                    <?php if (!empty($kras)): ?>
                    <form method="POST" id="removeForm_<?= $eid ?>" style="margin:0;display:inline;">
                        <input type="hidden" name="action"   value="remove_evaluator_kras">
                        <input type="hidden" name="cycle_id" value="<?= $cycle_id ?>">
                        <input type="hidden" name="eval_id"  value="<?= $eid ?>">
                        <button type="button" title="Remove all assignments"
                                onclick="confirmDelete('Remove all KRA assignments for this evaluator?','removeForm_<?= $eid ?>','Remove','bi-trash')"
                                style="width:30px;height:30px;border:1px solid #fecaca;background:#fff;color:#dc2626;cursor:pointer;display:flex;align-items:center;justify-content:center;"
                                onmouseover="this.style.background='#fef2f2'" onmouseout="this.style.background='#fff'">
                            <i class="bi bi-trash" style="font-size:0.72rem;pointer-events:none;"></i>
                        </button>
                    </form>
                    <?php else: ?>
                    <div style="width:30px;height:30px;"></div>
                    <?php endif; ?>
                </div>
            </td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <?php endif; ?>
</div>
