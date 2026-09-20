<?php
$uid   = $_SESSION['user_id'];
$cycle = getActiveCycle($pdo);

// â”€â”€ Runtime migration: create kra_evidence_files if missing â”€â”€
try { $pdo->query("SELECT evidence_id FROM kra_evidence_files LIMIT 1"); }
catch (\Exception $e) {
    $pdo->exec("CREATE TABLE IF NOT EXISTS kra_evidence_files (
        evidence_id       INT AUTO_INCREMENT PRIMARY KEY,
        submission_id     INT NOT NULL,
        file_path         VARCHAR(255) NOT NULL,
        original_filename VARCHAR(255) NOT NULL,
        file_size_bytes   INT DEFAULT 0,
        uploaded_at       TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        uploaded_by       INT DEFAULT NULL,
        FOREIGN KEY (submission_id) REFERENCES kra_submissions(submission_id) ON DELETE CASCADE,
        FOREIGN KEY (uploaded_by)   REFERENCES users(user_id) ON DELETE SET NULL
    )");
}
// â”€â”€ Runtime migration: add revision columns to kra_submissions â”€â”€
try { $pdo->query("SELECT revision_status FROM kra_submissions LIMIT 1"); }
catch (\Exception $e) {
    $pdo->exec("ALTER TABLE kra_submissions
        ADD COLUMN revision_status ENUM('ok','needs_revision') DEFAULT 'ok' AFTER verified,
        ADD COLUMN revision_note TEXT DEFAULT NULL AFTER revision_status,
        ADD COLUMN revision_by INT DEFAULT NULL AFTER revision_note,
        ADD COLUMN revision_at TIMESTAMP NULL AFTER revision_by
    ");
}

if (!$cycle) {
    // Show the most recently closed cycle for reference
    $last_cycle = $pdo->query("SELECT * FROM cycles ORDER BY created_at DESC LIMIT 1")->fetch();
    echo '<div class="neon-card text-center py-5">
        <i class="bi bi-calendar-x fs-1 text-secondary mb-3 d-block"></i>
        <h5 style="color:#1a3a6b;font-weight:700;margin-bottom:0.5rem;">No Active Reclassification Cycle</h5>
        <p class="text-muted mb-1">There is currently no open cycle. Your account is ready &mdash; you do not need to register again.</p>
        <p class="text-muted small">When the next cycle opens, your application will be available here automatically.</p>'
        . ($last_cycle ? '<p class="text-muted small mt-2">Last cycle: <strong>' . htmlspecialchars($last_cycle['cycle_name']) . '</strong> (' . ucfirst($last_cycle['status']) . ')</p>' : '')
        . '</div>';
    return;
}

$app = getOrCreateApplication($pdo, $uid, $cycle['cycle_id']);
$app_id = $app['application_id'];

// Only lock when checker has approved or admin has finalized
$can_edit = !in_array($app['status'], ['approved', 'reclassified', 'admin_rejected']);

// All KRA submissions for this application
$subs = $pdo->prepare("SELECT * FROM kra_submissions WHERE application_id = ? ORDER BY submitted_at DESC");
$subs->execute([$app_id]);
$subs = $subs->fetchAll();

// Attach evidence files to each submission
foreach ($subs as &$sub) {
    $ef = $pdo->prepare("SELECT evidence_id, file_path, original_filename FROM kra_evidence_files WHERE submission_id=? ORDER BY uploaded_at ASC");
    $ef->execute([$sub['submission_id']]);
    $sub['evidence_files'] = $ef->fetchAll(PDO::FETCH_ASSOC);
    if (empty($sub['evidence_files']) && $sub['document_path']) {
        $sub['evidence_files'] = [['evidence_id'=>0,'file_path'=>$sub['document_path'],'original_filename'=>basename($sub['document_path'])]];
    }
}
unset($sub);

// Compute potential rank for display
$raw_kra_faculty = [];
foreach ($subs as $s) {
    $raw_kra_faculty[$s['kra_category']] = ($raw_kra_faculty[$s['kra_category']] ?? 0) + (float)$s['computed_points'];
}
// Fetch faculty rank from users table (not in $app which is from applications)
$faculty_rank_row = $pdo->prepare("SELECT rank FROM users WHERE user_id=?");
$faculty_rank_row->execute([$uid]);
$faculty_rank_row = $faculty_rank_row->fetch();
$faculty_current_rank = $faculty_rank_row['rank'] ?? '';
$potential_data_faculty = computePotentialRank($raw_kra_faculty, $faculty_current_rank);

// Pre-compute weighted scores per KRA category for the table display
$score_result_fac = computeWeightedScore($raw_kra_faculty, $faculty_current_rank);
$kra_caps_fac     = ['Instruction'=>100,'Research'=>100,'Extension'=>100,'Professional Development'=>100];
$kra_weights_fac  = $score_result_fac['weights'];
// Sum raw per category for proportional distribution per entry
$kra_raw_totals_fac = [];
foreach ($subs as $s) {
    $kra_raw_totals_fac[$s['kra_category']] = ($kra_raw_totals_fac[$s['kra_category']] ?? 0) + (float)$s['computed_points'];
}

// KRA tab mapping
$kra_tabs = [
    'Instruction'              => 'instruction',
    'Research'                 => 'research',
    'Extension'                => 'extension',
    'Professional Development' => 'profdev',
];
?>



<div id="app-status" style="scroll-margin-top:80px;"></div>
<div class="neon-card p-0 mb-3" style="overflow:hidden;">
    <!-- Header bar -->
    <div style="background:#1e4d8c;padding:1.25rem 1.5rem;display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:0.75rem;">
        <div class="d-flex align-items-center gap-3">
            <div style="width:44px;height:44px;border-radius:10px;background:rgba(255,255,255,0.15);display:flex;align-items:center;justify-content:center;flex-shrink:0;">
                <i class="bi bi-file-earmark-text" style="font-size:1.3rem;color:#fff;"></i>
            </div>
            <div>
                <div style="font-size:1rem;font-weight:700;color:#fff;line-height:1.2;">My Application</div>
                <div style="font-size:0.8rem;color:#bfdbfe;margin-top:2px;"><?= sanitize($cycle['cycle_name']) ?></div>
            </div>
        </div>
        <?= statusBadge($app['status']) ?>
    </div>
    <!-- Info row -->
    <div style="padding:1rem 1.5rem;display:flex;flex-wrap:wrap;gap:1.5rem;align-items:center;border-bottom:1px solid #f1f5f9;">
        <div style="display:flex;flex-wrap:wrap;gap:1.5rem;align-items:center;flex:1;min-width:0;">
        <?php if ($app['submitted_at']): ?>
        <div>
            <div style="font-size:0.68rem;text-transform:uppercase;letter-spacing:0.07em;color:#94a3b8;font-weight:600;margin-bottom:3px;">Submitted</div>
            <div style="font-size:0.88rem;font-weight:600;color:#1e293b;"><?= date('M d, Y', strtotime($app['submitted_at'])) ?></div>
        </div>
        <?php endif; ?>
        <?php if (!empty($app['weighted_score'])): ?>
        <div>
            <div style="font-size:0.68rem;text-transform:uppercase;letter-spacing:0.07em;color:#94a3b8;font-weight:600;margin-bottom:3px;">Weighted Score <?= helpBtn('Weighted Score', 'This is your total score computed using KRA weights: Instruction (50%), Research (20%), Extension (20%), Professional Development (10%). You need at least 41.00 to qualify for reclassification.') ?></div>
            <div style="font-size:0.88rem;font-weight:700;color:#1e4d8c;"><?= number_format($app['weighted_score'], 2) ?></div>
        </div>
        <?php endif; ?>
        </div>
        <?php if ($can_edit): ?>
        <div style="flex-shrink:0;">
            <a href="?page=apply" class="btn btn-primary btn-sm" style="font-weight:600;padding:0.45rem 1.1rem;white-space:nowrap;">
                <i class="bi bi-pencil-square me-1"></i>Edit Application
            </a>
        </div>
        <?php endif; ?>
    </div>
</div>

<?php if ($app['checker_remarks']): ?>
<!-- Remarks -->
<div class="neon-card mb-3" style="padding:1rem 1.5rem;">
    <?php $status = $app['status']; ?>
    <?php if ($status === 'admin_rejected'): ?>
    <div class="d-flex gap-2 align-items-start">
        <i class="bi bi-x-circle-fill" style="color:#1e293b;font-size:1rem;margin-top:2px;flex-shrink:0;"></i>
        <div>
            <div style="font-size:0.72rem;font-weight:700;color:#1e293b;text-transform:uppercase;letter-spacing:0.05em;margin-bottom:3px;">Final Rejection</div>
            <div style="font-size:0.88rem;color:#1e293b;"><?= sanitize($app['checker_remarks']) ?></div>
            <div style="font-size:0.78rem;color:#1e293b;margin-top:6px;"><i class="bi bi-exclamation-triangle me-1"></i>You cannot edit or resubmit for this cycle.</div>
        </div>
    </div>
    <?php elseif ($status === 'needs_revision'): ?>
    <div class="d-flex gap-2 align-items-start">
        <i class="bi bi-exclamation-triangle-fill" style="color:#475569;font-size:1rem;margin-top:2px;flex-shrink:0;"></i>
        <div>
            <div style="font-size:0.72rem;font-weight:700;color:#475569;text-transform:uppercase;letter-spacing:0.05em;margin-bottom:3px;">Checker Note</div>
            <div style="font-size:0.88rem;color:#1e293b;"><?= sanitize($app['checker_remarks']) ?></div>
            <div style="font-size:0.78rem;color:#64748b;margin-top:6px;">Edit only the flagged entries below, then resubmit.</div>
        </div>
    </div>
    <?php elseif ($status === 'rejected'): ?>
    <div class="d-flex gap-2 align-items-start">
        <i class="bi bi-arrow-counterclockwise" style="color:#475569;font-size:1rem;margin-top:2px;flex-shrink:0;"></i>
        <div>
            <div style="font-size:0.72rem;font-weight:700;color:#475569;text-transform:uppercase;letter-spacing:0.05em;margin-bottom:3px;">Checker Remarks</div>
            <div style="font-size:0.88rem;color:#1e293b;"><?= sanitize($app['checker_remarks']) ?></div>
            <div class="mt-2"><a href="?page=apply" class="btn btn-sm btn-warning" style="font-weight:600;">Edit &amp; Resubmit</a></div>
        </div>
    </div>
    <?php else: ?>
    <div class="d-flex gap-2 align-items-start">
        <i class="bi bi-chat-left-text" style="color:#1a3a6b;font-size:1rem;margin-top:2px;flex-shrink:0;"></i>
        <div>
            <div style="font-size:0.72rem;font-weight:700;color:#1a3a6b;text-transform:uppercase;letter-spacing:0.05em;margin-bottom:3px;">Remarks</div>
            <div style="font-size:0.88rem;color:#1e293b;"><?= sanitize($app['checker_remarks']) ?></div>
        </div>
    </div>
    <?php endif; ?>
</div>
<?php endif; ?>

<!-- Potential Rank -->
<div class="neon-card p-0 mb-3" style="overflow:hidden;">
    <div style="padding:0.85rem 1.5rem;border-bottom:1px solid #f1f5f9;display:flex;align-items:center;justify-content:space-between;gap:1rem;flex-wrap:wrap;">
        <div class="d-flex align-items-center gap-2">
            <i class="bi bi-award-fill" style="color:#1e4d8c;font-size:1.1rem;"></i>
            <div>
                <div style="font-size:0.9rem;font-weight:700;color:#1a3a6b;">Potential Rank / Sub-rank <?= helpBtn('Potential Rank', 'Based on your weighted score, this shows the rank you could be reclassified to. A score of 41-50 = +1 sub-rank, 51-60 = +2, 61-70 = +3, 71-80 = +4, 81-90 = +5, 91-100 = +6. An automatic +1 extra is given for earning a doctorate during the cycle.') ?></div>
                <div style="font-size:0.72rem;color:#94a3b8;">Based on DBM-CHED Joint Circular No. 3, s. 2022</div>
            </div>
        </div>
        <span class="badge bg-secondary" style="font-size:0.68rem;"><i class="bi bi-lock-fill me-1"></i>System-computed</span>
    </div>
    <div style="padding:1rem 1.5rem;display:flex;flex-wrap:wrap;gap:1rem;align-items:center;">
        <div style="flex:1;min-width:140px;background:#f8fafc;border:1px solid #e2e8f0;border-radius:8px;padding:0.75rem 1rem;">
            <div style="font-size:0.68rem;text-transform:uppercase;letter-spacing:0.07em;color:#94a3b8;font-weight:600;margin-bottom:4px;">Current Rank</div>
            <div style="font-size:0.95rem;font-weight:700;color:#1a3a6b;"><?= sanitize($faculty_current_rank ?: '&mdash;') ?></div>
        </div>
        <div style="color:#cbd5e1;font-size:1.2rem;flex-shrink:0;">&#8594;</div>
        <div style="flex:1;min-width:140px;background:#f0f4fb;border:1px solid #bfdbfe;border-radius:8px;padding:0.75rem 1rem;">
            <div style="font-size:0.68rem;text-transform:uppercase;letter-spacing:0.07em;color:#94a3b8;font-weight:600;margin-bottom:4px;">Potential Rank</div>
            <?php if ($potential_data_faculty['potential_rank'] === '—' || empty($raw_kra_faculty)): ?>
            <div style="font-size:0.85rem;color:#94a3b8;">&mdash; no submissions yet</div>
            <?php else: ?>
            <div style="font-size:0.95rem;font-weight:700;color:#1e4d8c;">
                <?= sanitize($potential_data_faculty['potential_rank']) ?>
                <?php if ($potential_data_faculty['crossed_category']): ?>
                <span class="badge bg-warning text-dark ms-1" style="font-size:0.6rem;vertical-align:middle;">Category crossed</span>
                <?php endif; ?>
            </div>
            <?php endif; ?>
        </div>
        <?php if (!empty($raw_kra_faculty) && $potential_data_faculty['recomputed_score'] !== null): ?>
        <div style="flex:1;min-width:120px;background:#f0f4fb;border:1px solid #dbeafe;border-radius:8px;padding:0.75rem 1rem;">
            <div style="font-size:0.68rem;text-transform:uppercase;letter-spacing:0.07em;color:#94a3b8;font-weight:600;margin-bottom:4px;">Re-computed Score</div>
            <div style="font-size:0.95rem;font-weight:700;color:#1a3a6b;"><?= number_format($potential_data_faculty['recomputed_score'], 2) ?></div>
        </div>
        <?php endif; ?>
    </div>
    <?php if (!empty($potential_data_faculty['flags'])): ?>
    <div style="padding:0 1.5rem 0.85rem;display:flex;flex-wrap:wrap;gap:0.4rem;">
        <?php foreach ($potential_data_faculty['flags'] as $flag):
            $fc = '#64748b'; $fb = '#f1f5f9';
            if (str_contains($flag, 'EAC'))     { $fc = '#1e293b'; $fb = '#f8fafc'; }
            if (str_contains($flag, 'CC'))      { $fc = '#334155'; $fb = '#f1f5f9'; }
            if (str_contains($flag, 'crossed')) { $fc = '#1a3a6b'; $fb = '#f0f4fb'; }
        ?>
        <span style="font-size:0.7rem;padding:0.25rem 0.6rem;border-radius:4px;background:<?= $fb ?>;color:<?= $fc ?>;font-weight:600;">
            <i class="bi bi-info-circle me-1"></i><?= sanitize($flag) ?>
        </span>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>
</div>

<!-- KRA Submissions -->
<div class="neon-card p-0" style="overflow:hidden;">
    <div style="padding:0.85rem 1.5rem;border-bottom:1px solid #f1f5f9;display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:0.75rem;">
        <div>
            <div style="font-size:0.9rem;font-weight:700;color:#1a3a6b;"><i class="bi bi-list-check me-2"></i>KRA Submissions <?= helpBtn('KRA Submissions', 'These are your Key Result Area entries. Each entry needs a score and an evidence file uploaded. You must submit evidence for every entry before submitting your application. Click "Add / Edit Entries" to add or modify your KRA scores.') ?></div>
            <div style="font-size:0.75rem;color:#94a3b8;margin-top:1px;">Your Key Result Areas documentation and evidence</div>
        </div>
        <?php if ($can_edit): ?>
        <a href="?page=apply" class="btn btn-primary btn-sm" style="font-weight:600;padding:0.45rem 1.1rem;">
            <i class="bi bi-plus-circle me-1"></i>Add / Edit Entries
        </a>
        <?php else: ?>
        <span class="badge bg-secondary" style="font-size:0.75rem;padding:0.4rem 0.8rem;"><i class="bi bi-lock me-1"></i>Locked</span>
        <?php endif; ?>
    </div>
    <?php if ($subs): ?>
    <div class="table-responsive">
        <table style="width:100%;border-collapse:collapse;font-size:0.84rem;">
            <thead>
                <tr style="background:#f8fafc;border-bottom:2px solid #e2e8f0;">
                    <th style="padding:0.65rem 1rem;font-size:0.7rem;font-weight:700;text-transform:uppercase;letter-spacing:0.05em;color:#64748b;width:130px;">KRA</th>
                    <th style="padding:0.65rem 0.75rem;font-size:0.7rem;font-weight:700;text-transform:uppercase;letter-spacing:0.05em;color:#64748b;width:110px;text-align:center;">Weighted<br>Score</th>
                    <th style="padding:0.65rem 0.75rem;font-size:0.7rem;font-weight:700;text-transform:uppercase;letter-spacing:0.05em;color:#64748b;">Remarks</th>
                    <th style="padding:0.65rem 0.75rem;font-size:0.7rem;font-weight:700;text-transform:uppercase;letter-spacing:0.05em;color:#64748b;width:160px;">Evidence</th>
                    <th style="padding:0.65rem 0.75rem;font-size:0.7rem;font-weight:700;text-transform:uppercase;letter-spacing:0.05em;color:#64748b;width:80px;text-align:center;">Status</th>
                    <th style="padding:0.65rem 0.75rem;font-size:0.7rem;font-weight:700;text-transform:uppercase;letter-spacing:0.05em;color:#64748b;width:90px;">Date</th>
                    <?php if ($can_edit): ?>
                    <th style="padding:0.65rem 0.75rem;font-size:0.7rem;width:60px;"></th>
                    <?php endif; ?>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($subs as $s): ?>
            <tr style="border-bottom:1px solid #f0f4fb;<?= ($s['revision_status']??'ok')==='needs_revision' ? 'background:#f8fafc;' : '' ?>">
                <td style="padding:0.75rem 1rem;vertical-align:middle;font-weight:600;color:#1a3a6b;font-size:0.82rem;">
                    <?= sanitize($s['kra_category']) ?>
                </td>
                <td style="padding:0.75rem 0.75rem;text-align:center;vertical-align:middle;">
                    <?php
                    $fac_cat    = $s['kra_category'];
                    $fac_raw    = (float)$s['computed_points'];
                    $fac_cap    = $kra_caps_fac[$fac_cat] ?? 100;
                    $fac_weight = $kra_weights_fac[$fac_cat] ?? 0;
                    $fac_cat_raw = $kra_raw_totals_fac[$fac_cat] ?? 0;
                    $fac_capped  = min($fac_cap, $fac_cat_raw);
                    $fac_share   = $fac_cat_raw > 0 ? ($fac_raw / $fac_cat_raw) : 0;
                    $fac_weighted = round($fac_share * $fac_capped * $fac_weight, 2);
                    ?>
                    <span style="background:#1a3a6b;color:#fff;padding:0.22rem 0.6rem;border-radius:5px;font-weight:700;font-size:0.82rem;display:inline-block;">
                        <?= number_format($fac_weighted, 2) ?>
                    </span>
                </td>
                <td style="padding:0.75rem 0.75rem;color:#475569;font-size:0.82rem;vertical-align:middle;">
                    <?= formatKraRemarks($s['kra_category'], $s['remarks'] ?? '') ?>
                </td>
                <td style="padding:0.75rem 0.75rem;vertical-align:middle;">
                    <?php if (!empty($s['evidence_files'])): ?>
                    <div class="d-flex flex-wrap gap-1">
                        <?php foreach ($s['evidence_files'] as $fi): ?>
                        <a href="pages/view_file.php?file=<?= urlencode($fi['file_path']) ?>" target="_blank" rel="noopener noreferrer"
                           title="<?= htmlspecialchars($fi['original_filename']) ?>"
                           style="font-size:0.72rem;padding:0.2rem 0.5rem;border-radius:4px;background:#f0f4fb;color:#1a3a6b;border:1px solid #dbeafe;text-decoration:none;display:inline-flex;align-items:center;gap:3px;white-space:nowrap;">
                            <i class="bi bi-file-earmark"></i><?= htmlspecialchars(substr($fi['original_filename'], 0, 12) . (strlen($fi['original_filename']) > 12 ? '...' : '')) ?>
                        </a>
                        <?php endforeach; ?>
                    </div>
                    <?php else: ?>
                    <span style="color:#cbd5e1;font-size:0.8rem;">&mdash;</span>
                    <?php endif; ?>
                </td>
                <td style="padding:0.75rem 0.75rem;text-align:center;vertical-align:middle;">
                    <?php if (($s['revision_status'] ?? 'ok') === 'needs_revision'): ?>
                    <div style="display:inline-flex;flex-direction:column;align-items:center;gap:3px;">
                        <span style="font-size:0.7rem;padding:0.2rem 0.55rem;border-radius:4px;background:#fff7ed;color:#c2410c;border:1px solid #fed7aa;font-weight:700;white-space:nowrap;">
                            <i class="bi bi-exclamation-triangle-fill me-1"></i>Needs Revision
                        </span>
                        <?php if (!empty($s['revision_note'])): ?>
                        <span style="font-size:0.68rem;color:#92400e;max-width:120px;text-align:center;line-height:1.3;">
                            <?= sanitize($s['revision_note']) ?>
                        </span>
                        <?php endif; ?>
                    </div>
                    <?php elseif ($s['verified']): ?>
                    <span style="font-size:0.7rem;padding:0.2rem 0.5rem;border-radius:4px;background:#dbeafe;color:#1e4d8c;border:1px solid #bfdbfe;font-weight:600;white-space:nowrap;">
                        <i class="bi bi-check-circle me-1"></i>Verified
                    </span>
                    <?php else: ?>
                    <span style="color:#cbd5e1;font-size:0.78rem;">&mdash;</span>
                    <?php endif; ?>
                </td>
                <td style="padding:0.75rem 0.75rem;color:#94a3b8;font-size:0.78rem;vertical-align:middle;white-space:nowrap;">
                    <?= date('M d, Y', strtotime($s['submitted_at'])) ?>
                </td>
                <?php if ($can_edit): ?>
                <td style="padding:0.75rem 0.75rem;text-align:center;vertical-align:middle;">
                    <?php $tab = $kra_tabs[$s['kra_category']] ?? 'instruction';
                          $is_flagged = ($s['revision_status'] ?? 'ok') === 'needs_revision'; ?>
                    <?php if ($app['status'] === 'needs_revision' && !$is_flagged): ?>
                    <span style="color:#cbd5e1;font-size:0.8rem;">&mdash;</span>
                    <?php else: ?>
                    <a href="?page=apply&tab=<?= $tab ?>&edit_sid=<?= $s['submission_id'] ?>"
                       style="font-size:0.8rem;padding:0.28rem 0.65rem;border-radius:5px;background:#f0f4fb;color:#1a3a6b;border:1px solid #dbeafe;text-decoration:none;font-weight:600;display:inline-flex;align-items:center;gap:4px;white-space:nowrap;">
                        <i class="bi bi-pencil" style="font-size:0.75rem;"></i>Edit
                    </a>
                    <?php endif; ?>
                </td>
                <?php endif; ?>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php else: ?>
    <div style="padding:3rem 1.5rem;text-align:center;">
        <i class="bi bi-inbox" style="font-size:2.5rem;color:#cbd5e1;display:block;margin-bottom:0.75rem;"></i>
        <div style="font-size:0.95rem;font-weight:600;color:#94a3b8;margin-bottom:0.35rem;">No KRA Submissions Yet</div>
No KRA Submissions Yet</div>
        <div style="font-size:0.82rem;color:#cbd5e1;margin-bottom:1.25rem;">Start by adding your Key Result Areas documentation</div>
        <?php if ($can_edit): ?>
        <a href="?page=apply" class="btn btn-primary btn-sm" style="font-weight:600;padding:0.5rem 1.25rem;">
            <i class="bi bi-plus-circle me-1"></i>Start Entering KRA Scores
        </a>
        <?php endif; ?>
    </div>
    <?php endif; ?>
</div>

<?php
// Score Comparison Table (live-polled)
// Show as soon as the application has been submitted or is under review
$show_comparison = in_array($app['status'], [
    'submitted','under_review','talisay_review','approved',
    'admin_rejected','needs_revision','rejected'
]) && !empty($subs);

if ($show_comparison):
    // Is it still actively being reviewed? If so we poll for live updates.
    $is_live = in_array($app['status'], ['submitted','under_review','talisay_review','needs_revision']);
?>
<div id="score-comparison" style="scroll-margin-top:80px;"></div>
<div class="neon-card p-0" style="overflow:hidden;margin-top:0.75rem;" id="cmpCard">

    <!-- Header -->
    <div style="padding:0.65rem 1.25rem;display:flex;align-items:center;justify-content:space-between;
                flex-wrap:wrap;gap:0.5rem;border-bottom:2px solid #e2e8f0;background:#fff;">
        <div>
            <div style="font-size:0.9rem;font-weight:700;color:#1a3a6b;display:flex;align-items:center;gap:0.5rem;">
                <i class="bi bi-bar-chart-steps"></i>Score Evaluation Comparison
            </div>
            <div style="font-size:0.72rem;color:#94a3b8;margin-top:1px;">
                Your submitted scores alongside checker evaluations
            </div>
        </div>
        <?php if (!$is_live): ?>
        <span style="font-size:0.7rem;padding:0.3rem 0.7rem;border-radius:20px;background:#f0f4fb;
                     color:#1e4d8c;border:1px solid #dbeafe;font-weight:600;white-space:nowrap;">
            <i class="bi bi-lock me-1"></i>Finalised
        </span>
        <?php endif; ?>
    </div>

    <!-- Table container filled by JS -->
    <div style="overflow-x:auto;">
        <div id="cmpTableWrap">
            <div style="padding:2.5rem;text-align:center;color:#94a3b8;font-size:0.85rem;">
                <div class="spinner-border spinner-border-sm text-primary me-2"></div>
                Loading comparison data&hellip;
            </div>
        </div>
    </div>

</div>

<style>
@keyframes livePulse {
    0%,100% { opacity:1; transform:scale(1); }
    50%      { opacity:.45; transform:scale(1.35); }
}
.cmp-your-badge { background:#dbeafe;color:#1a3a6b;padding:.2rem .6rem;border-radius:6px;font-weight:700;font-size:.82rem; }
.cmp-s1-badge   { background:#1e4d8c;color:#fff;padding:.2rem .55rem;border-radius:6px;font-weight:700;font-size:.82rem; }
.cmp-s2-badge   { background:#1a3a6b;color:#fff;padding:.2rem .55rem;border-radius:6px;font-weight:700;font-size:.82rem; }
.cmp-nochange   { color:#94a3b8;font-size:.75rem; }
.cmp-pending    { color:#cbd5e1;font-size:.75rem; }
.cmp-rev-note   { padding:.3rem .55rem;background:#f8fafc;border-left:3px solid #475569;border-radius:0 4px 4px 0;font-size:.72rem;margin-bottom:4px; }
.cmp-ws-your    { background:#dbeafe;color:#1a3a6b;padding:.3rem .75rem;border-radius:6px;font-weight:700;font-size:.9rem; }
.cmp-ws-s1      { background:#1e4d8c;color:#fff;padding:.3rem .75rem;border-radius:6px;font-weight:700;font-size:.9rem; }
.cmp-ws-s2      { background:#1a3a6b;color:#fff;padding:.3rem .75rem;border-radius:6px;font-weight:700;font-size:.9rem; }
</style>

<script>
(function () {
    const APP_ID   = <?= $app_id ?>;
    const IS_LIVE  = <?= $is_live ? 'true' : 'false' ?>;
    const AJAX_URL = 'includes/faculty/comparison_ajax.php?app_id=' + APP_ID;
    const KRA_ORDER = ['Instruction','Research','Extension','Professional Development'];
    let pollTimer = null;

    function fmt(n) { return parseFloat(n).toFixed(2); }
    function esc(s) {
        return String(s ?? '')
            .replace(/&/g,'&amp;').replace(/</g,'&lt;')
            .replace(/>/g,'&gt;').replace(/"/g,'&quot;');
    }

    function buildTable(data) {
        const subs   = data.submissions || [];
        const hasS1  = data.has_stage1;
        const hasS2  = data.has_stage2;
        const s1Info = data.stage1_info || [];
        const s2Info = data.stage2_info || [];

        const groups = {};
        KRA_ORDER.forEach(k => { groups[k] = []; });
        subs.forEach(s => { if (groups[s.kra_category]) groups[s.kra_category].push(s); });

        const s1Remarks = s1Info.filter(r => r.remarks).map(r => esc(r.name)+': '+esc(r.remarks)).join(' | ');
        const s2Remarks = s2Info.filter(r => r.remarks).map(r => esc(r.name)+': '+esc(r.remarks)).join(' | ');

        let html = '<table style="width:100%;border-collapse:collapse;font-size:.83rem;margin:0;">';

        // ── thead
        html += `<thead><tr style="border-bottom:2px solid #e2e8f0;background:#f8fafc;">
            <th style="padding:.7rem 1rem;color:#1a3a6b;font-size:.7rem;text-transform:uppercase;letter-spacing:.05em;font-weight:700;white-space:nowrap;">KRA</th>
            <th style="padding:.7rem .75rem;font-size:.7rem;text-transform:uppercase;letter-spacing:.05em;font-weight:700;text-align:center;background:#f0f4fb;color:#1a3a6b;">
                <i class="bi bi-person"></i> Your Score
            </th>`;

        if (hasS1) {
            html += `<th style="padding:.7rem .75rem;font-size:.7rem;text-transform:uppercase;letter-spacing:.05em;font-weight:700;text-align:center;background:#eff6ff;color:#1e4d8c;">
                <i class="bi bi-check-circle"></i> Stage 1
                <div style="font-size:.62rem;font-weight:400;color:#64748b;text-transform:none;letter-spacing:0;">Campus Checker</div>
            </th>`;
        } else {
            html += `<th style="padding:.7rem .75rem;font-size:.7rem;text-transform:uppercase;letter-spacing:.05em;font-weight:700;text-align:center;background:#eff6ff;color:#94a3b8;">
                <i class="bi bi-clock"></i> Stage 1
                <div style="font-size:.62rem;font-weight:400;color:#94a3b8;text-transform:none;letter-spacing:0;">Awaiting checker</div>
            </th>`;
        }

        if (hasS2) {
            html += `<th style="padding:.7rem .75rem;font-size:.7rem;text-transform:uppercase;letter-spacing:.05em;font-weight:700;text-align:center;background:#f0f4fb;color:#1a3a6b;">
                <i class="bi bi-check2-circle"></i> Stage 2
                <div style="font-size:.62rem;font-weight:400;color:#64748b;text-transform:none;letter-spacing:0;">Talisay Checker</div>
            </th>`;
        }

        html += `<th style="padding:.7rem .75rem;font-size:.7rem;text-transform:uppercase;letter-spacing:.05em;font-weight:700;color:#1a3a6b;">Remarks</th>
        </tr></thead><tbody>`;

        // ── tbody
        KRA_ORDER.forEach(kra => {
            const rows = groups[kra] || [];
            if (!rows.length) return;
            rows.forEach((s, ri) => {
                const isFirst    = ri === 0;
                const rowCount   = rows.length;
                const s1Adj      = s.checker_adjusted && s.adjusted_stage === 'stage1';
                const s2Adj      = s.checker_adjusted && s.adjusted_stage === 'stage2';
                const hasRev     = s.revision_status === 'needs_revision';

                html += `<tr style="border-bottom:1px solid #f0f4fb;${hasRev ? 'background:#fffbf0;' : ''}">`;

                if (isFirst) {
                    html += `<td rowspan="${rowCount}" style="padding:.7rem 1rem;vertical-align:middle;font-weight:700;color:#1a3a6b;border-right:2px solid #f0f4fb;white-space:nowrap;">${esc(kra)}</td>`;
                }

                // Your score
                html += `<td style="padding:.6rem .75rem;text-align:center;vertical-align:middle;background:#f8fafc;">
                    <span class="cmp-your-badge">${fmt(s.faculty_score)}</span>
                </td>`;

                // Stage 1
                html += `<td style="padding:.6rem .75rem;text-align:center;vertical-align:middle;background:#f0f8ff;">`;
                if (s1Adj) {
                    html += `<span class="cmp-s1-badge">${fmt(s.current_score)}</span>`;
                    if (s.adjusted_by) html += `<div style="font-size:.62rem;color:#64748b;margin-top:2px;">${esc(s.adjusted_by)}</div>`;
                } else if (hasS1) {
                    html += `<span class="cmp-nochange">No change</span>`;
                } else {
                    html += `<span class="cmp-pending">—</span>`;
                }
                html += `</td>`;

                // Stage 2
                if (hasS2) {
                    html += `<td style="padding:.6rem .75rem;text-align:center;vertical-align:middle;background:#fdf8ff;">`;
                    if (s2Adj) {
                        html += `<span class="cmp-s2-badge">${fmt(s.current_score)}</span>`;
                        if (s.adjusted_by) html += `<div style="font-size:.62rem;color:#64748b;margin-top:2px;">${esc(s.adjusted_by)}</div>`;
                    } else if (hasS2) {
                        html += `<span class="cmp-nochange">No change</span>`;
                    } else {
                        html += `<span class="cmp-pending">—</span>`;
                    }
                    html += `</td>`;
                }

                // Remarks
                html += `<td style="padding:.6rem .75rem;vertical-align:middle;font-size:.78rem;color:#475569;">`;
                let hasRem = false;
                if (s.checker_note) {
                    html += `<div style="padding:.3rem .55rem;background:#eff6ff;border-left:3px solid #1e4d8c;border-radius:0 4px 4px 0;font-size:.72rem;margin-bottom:4px;"><strong style="color:#1e4d8c;">Score note:</strong> ${esc(s.checker_note)}</div>`;
                    hasRem = true;
                }
                if (isFirst && s1Remarks) { html += `<div style="font-size:.72rem;color:#1e4d8c;margin-top:2px;">${s1Remarks}</div>`; hasRem = true; }
                if (isFirst && s2Remarks) { html += `<div style="font-size:.72rem;color:#1a3a6b;margin-top:2px;">${s2Remarks}</div>`; hasRem = true; }
                if (!hasRem) html += `<span style="color:#cbd5e1;">—</span>`;
                html += `</td></tr>`;
            });
        });
        html += `</tbody>`;

        // ── tfoot (weighted scores)
        const wsY  = parseFloat(data.ws_faculty ?? 0);
        const wsS1 = parseFloat(data.ws_stage1  ?? 0);
        const wsS2 = data.ws_stage2 !== null ? parseFloat(data.ws_stage2) : null;
        const d1   = Math.round((wsS1 - wsY) * 100) / 100;
        const d2   = wsS2 !== null ? Math.round((wsS2 - wsY) * 100) / 100 : null;

        const diffHtml = (d, cls1, cls2) => {
            if (d === null) return '';
            if (d === 0) return `<div style="font-size:.68rem;margin-top:3px;color:#94a3b8;">No net change</div>`;
            const sign = d > 0 ? '+' : '';
            const col  = d > 0 ? '#1e4d8c' : '#1e293b';
            return `<div style="font-size:.68rem;margin-top:3px;color:${col};">${sign}${fmt(d)} vs your score</div>`;
        };

        html += `<tfoot><tr style="background:#f0f4fb;border-top:2px solid #dbeafe;">
            <td style="padding:.75rem 1rem;font-weight:700;color:#1a3a6b;font-size:.8rem;white-space:nowrap;">
                <i class="bi bi-calculator me-1"></i>Weighted Score
            </td>
            <td style="padding:.75rem;text-align:center;background:#f0f4fb;">
                <span class="cmp-ws-your">${fmt(wsY)}</span>
            </td>
            <td style="padding:.75rem;text-align:center;background:#eff6ff;">`;
        if (hasS1) {
            html += `<span class="cmp-ws-s1">${fmt(wsS1)}</span>${diffHtml(d1)}`;
        } else {
            html += `<span class="cmp-pending">—</span>`;
        }
        html += `</td>`;

        if (hasS2) {
            html += `<td style="padding:.75rem;text-align:center;background:#f0f4fb;">`;
            if (wsS2 !== null) {
                html += `<span class="cmp-ws-s2">${fmt(wsS2)}</span>${diffHtml(d2)}`;
            } else {
                html += `<span class="cmp-pending">—</span>`;
            }
            html += `</td>`;
        }

        html += `<td style="padding:.75rem;font-size:.72rem;color:#64748b;vertical-align:middle;">
                Final weighted score after all adjustments
            </td>
        </tr></tfoot></table>`;
        return html;
    }

    function stopPolling() { if (pollTimer) { clearInterval(pollTimer); pollTimer = null; } }

    function poll() {
        fetch(AJAX_URL)
            .then(r => {
                if (!r.ok) throw new Error('HTTP ' + r.status);
                return r.json();
            })
            .then(data => {
                const wrap = document.getElementById('cmpTableWrap');
                if (!data.ok) {
                    if (wrap) wrap.innerHTML = `<div style="padding:2rem;text-align:center;color:#94a3b8;font-size:.85rem;">
                        <i class="bi bi-exclamation-circle d-block mb-2" style="font-size:1.4rem;"></i>
                        Could not load comparison data. <button onclick="poll()" style="font-size:.78rem;background:none;border:1px solid #cbd5e1;border-radius:6px;padding:2px 10px;cursor:pointer;color:#475569;margin-left:6px;">Retry</button>
                    </div>`;
                    return;
                }
                if (wrap) wrap.innerHTML = buildTable(data);

                const ts = document.getElementById('cmpLastUpdated');
                if (ts) ts.textContent = 'Updated ' + data.updated_at;

                // Stop polling once terminal
                if (['approved','reclassified','admin_rejected','rejected'].includes(data.status)) {
                    stopPolling();
                    const dot = document.getElementById('cmpLiveDot');
                    if (dot) dot.remove();
                    const upd = document.getElementById('cmpLastUpdated');
                    if (upd) upd.remove();
                }
            })
            .catch(err => {
                console.error('Comparison poll failed:', err);
                const wrap = document.getElementById('cmpTableWrap');
                if (wrap && wrap.querySelector('.spinner-border')) {
                    wrap.innerHTML = `<div style="padding:2rem;text-align:center;color:#94a3b8;font-size:.85rem;">
                        <i class="bi bi-wifi-off d-block mb-2" style="font-size:1.4rem;"></i>
                        Could not load comparison data. <button onclick="poll()" style="font-size:.78rem;background:none;border:1px solid #cbd5e1;border-radius:6px;padding:2px 10px;cursor:pointer;color:#475569;margin-left:6px;">Retry</button>
                    </div>`;
                }
            });
    }

    poll();
    if (IS_LIVE) { pollTimer = setInterval(poll, 5000); }

    document.addEventListener('DOMContentLoaded', function () {
        if (window.location.hash === '#score-comparison') {
            const el = document.getElementById('score-comparison');
            if (el) setTimeout(() => el.scrollIntoView({ behavior:'smooth', block:'start' }), 120);
        }
    });
})();
</script>

<?php endif; // show_comparison ?>
