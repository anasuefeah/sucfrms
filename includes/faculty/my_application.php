<?php
$uid   = $_SESSION['user_id'];
$cycle = getActiveCycle($pdo);

// -- Runtime migration: create kra_evidence_files if missing --
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
// -- Runtime migration: add revision columns to kra_submissions --
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
        <p class="text-muted mb-1">There is currently no open cycle. Your account is ready.</p>
        <p class="text-muted small">When the next cycle opens, your application will be available here automatically.</p>'
        . ($last_cycle ? '<p class="text-muted small mt-2">Last cycle: <strong>' . htmlspecialchars($last_cycle['cycle_name']) . '</strong> (' . ucfirst($last_cycle['status']) . ')</p>' : '')
        . '</div>';
    return;
}
ensureAppealTables($pdo);

$app = getOrCreateApplication($pdo, $uid, $cycle['cycle_id']);
$app_id = $app['application_id'];

// Lock once evaluator work is complete.
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

$appeals_by_submission = [];
if ($subs) {
    $sub_ids = array_map('intval', array_column($subs, 'submission_id'));
    $ph = implode(',', array_fill(0, count($sub_ids), '?'));
    $ap_stmt = $pdo->prepare("SELECT * FROM appeals WHERE submission_id IN ($ph) ORDER BY created_at DESC");
    $ap_stmt->execute($sub_ids);
    foreach ($ap_stmt->fetchAll() as $ap) {
        $sid = (int)$ap['submission_id'];
        if (!isset($appeals_by_submission[$sid])) $appeals_by_submission[$sid] = [];
        $appeals_by_submission[$sid][] = $ap;
    }
}
$appeals_allowed = ($cycle['status'] ?? '') === 'open'
    && (empty($cycle['appeal_deadline']) || time() <= strtotime($cycle['appeal_deadline'] . ' 23:59:59'));

// Fetch faculty rank from users table (not in $app which is from applications)
$faculty_rank_row = $pdo->prepare("SELECT `rank` FROM users WHERE user_id=?");
$faculty_rank_row->execute([$uid]);
$faculty_rank_row = $faculty_rank_row->fetch();
$faculty_current_rank = $faculty_rank_row['rank'] ?? '';
$score_summary_faculty = getApplicationScoreSummary($pdo, (int)$app_id);
$raw_kra_faculty       = array_map(fn($d) => $d['pts'], $score_summary_faculty['kra_map']);
$potential_data_faculty = [
    'potential_rank'   => $score_summary_faculty['potential_rank'] ?: ' - ',
    'flags'            => [],
    'crossed_category' => false,
    'recomputed_score' => $score_summary_faculty['weighted_score'],
];

// Pre-compute weighted scores per KRA category for the table display
$score_result_fac = $score_summary_faculty;
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
<div class="neon-card p-0 mb-3" style="overflow:hidden;border-radius:0;box-shadow:none;">
    <!-- Header bar -->
    <div style="background:#1a3a6b;padding:1rem 1.25rem;display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:0.75rem;">
        <div style="display:flex;align-items:center;gap:0.75rem;">
            <div>
                <div style="font-size:0.65rem;text-transform:uppercase;letter-spacing:0.08em;color:rgba(255,255,255,0.5);margin-bottom:2px;">My Application</div>
                <div style="font-size:0.88rem;font-weight:700;color:#fff;line-height:1.2;"><?= sanitize($cycle['cycle_name']) ?></div>
            </div>
        </div>
        <?= statusBadge($app['status']) ?>
    </div>
</div>

<!-- Recommended Rank -->
<div class="neon-card p-0 mb-3" style="overflow:hidden;border-radius:0;box-shadow:none;">
    <div style="padding:0.7rem 1.25rem;border-bottom:1px solid #e2e8f0;background:#f8fafc;display:flex;align-items:center;justify-content:space-between;gap:1rem;flex-wrap:wrap;">
        <div>
            <div style="font-size:0.65rem;font-weight:700;text-transform:uppercase;letter-spacing:0.08em;color:#94a3b8;">Recommended Rank / Sub-rank <?= helpBtn('Recommended Rank', 'Based on your weighted score, this shows the recommended rank for committee review. A score of 41-50 = +1 sub-rank, 51-60 = +2, 61-70 = +3, 71-80 = +4, 81-90 = +5, 91-100 = +6. An automatic +1 extra is given for earning a doctorate during the cycle.') ?></div>
        </div>
        <span style="font-size:0.62rem;font-weight:600;color:#64748b;background:#f1f5f9;border:1px solid #e2e8f0;padding:2px 8px;">System-computed</span>
    </div>
    <div style="padding:0.85rem 1.25rem;display:flex;flex-wrap:wrap;gap:0.75rem;align-items:stretch;">
        <div style="flex:1;min-width:130px;background:#fff;border:1px solid #e2e8f0;padding:0.65rem 0.9rem;">
            <div style="font-size:0.62rem;text-transform:uppercase;letter-spacing:0.07em;color:#94a3b8;font-weight:700;margin-bottom:4px;">Current Rank</div>
            <div style="font-size:0.88rem;font-weight:700;color:#1e293b;"><?= sanitize($faculty_current_rank ?: '—') ?></div>
        </div>
        <div style="display:flex;align-items:center;color:#cbd5e1;font-size:1rem;flex-shrink:0;">&#8594;</div>
        <div style="flex:1;min-width:130px;background:#fff;border:1px solid #1a3a6b;padding:0.65rem 0.9rem;">
            <div style="font-size:0.62rem;text-transform:uppercase;letter-spacing:0.07em;color:#94a3b8;font-weight:700;margin-bottom:4px;">Recommended Rank</div>
            <?php if ($potential_data_faculty['potential_rank'] === ' - ' || empty($raw_kra_faculty)): ?>
            <div style="font-size:0.82rem;color:#94a3b8;">No submissions yet</div>
            <?php else: ?>
            <div style="font-size:0.88rem;font-weight:700;color:#1a3a6b;">
                <?= sanitize($potential_data_faculty['potential_rank']) ?>
                <?php if ($potential_data_faculty['crossed_category']): ?>
                <span style="font-size:0.58rem;font-weight:700;color:#92400e;background:#fffbeb;border:1px solid #fde68a;padding:1px 5px;margin-left:4px;">Category crossed</span>
                <?php endif; ?>
            </div>
            <?php endif; ?>
        </div>
        <?php if (!empty($raw_kra_faculty) && $potential_data_faculty['recomputed_score'] !== null): ?>
        <div style="flex:1;min-width:110px;background:#fff;border:1px solid #e2e8f0;padding:0.65rem 0.9rem;">
            <div style="font-size:0.62rem;text-transform:uppercase;letter-spacing:0.07em;color:#94a3b8;font-weight:700;margin-bottom:4px;">Re-computed Score</div>
            <div style="font-size:0.88rem;font-weight:700;color:#1e293b;"><?= number_format($potential_data_faculty['recomputed_score'], 2) ?></div>
        </div>
        <?php endif; ?>
    </div>
    <?php if (!empty($potential_data_faculty['flags'])): ?>
    <div style="padding:0 1.25rem 0.75rem;display:flex;flex-wrap:wrap;gap:0.35rem;">
        <?php foreach ($potential_data_faculty['flags'] as $flag): ?>
        <span style="font-size:0.68rem;padding:2px 8px;background:#f1f5f9;color:#475569;font-weight:600;">
            <?= sanitize($flag) ?>
        </span>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>
    <div style="padding:0 1.25rem 0.75rem;font-size:0.7rem;color:#94a3b8;">
        This result is for further committee review and is not a final decision.
    </div>
</div>

<?php if ($app['checker_remarks']): ?>
<div class="neon-card mb-3" style="padding:0.85rem 1.25rem;border-radius:0;box-shadow:none;border-left:3px solid #475569;">
    <?php $status = $app['status']; ?>
    <?php if ($status === 'admin_rejected'): ?>
    <div style="font-size:0.62rem;font-weight:700;color:#dc2626;text-transform:uppercase;letter-spacing:0.07em;margin-bottom:4px;">Returned by Admin</div>
    <div style="font-size:0.82rem;color:#1e293b;"><?= sanitize($app['checker_remarks']) ?></div>
    <div style="font-size:0.72rem;color:#64748b;margin-top:5px;">This result is for further committee review and is not a final decision.</div>
    <?php elseif ($status === 'needs_revision'): ?>
    <div style="font-size:0.62rem;font-weight:700;color:#475569;text-transform:uppercase;letter-spacing:0.07em;margin-bottom:4px;">Evaluator Note</div>
    <div style="font-size:0.82rem;color:#1e293b;"><?= sanitize($app['checker_remarks']) ?></div>
    <div style="font-size:0.72rem;color:#64748b;margin-top:5px;">Edit and save each flagged entry below, then resubmit the corrections. Other entries remain locked.</div>
    <form method="POST" action="index.php?page=dashboard" style="margin-top:0.75rem;">
        <input type="hidden" name="action" value="resubmit_revision">
        <button type="submit" class="btn btn-primary btn-sm">Resubmit corrected entries</button>
    </form>
    <?php elseif ($status === 'rejected'): ?>
    <div style="font-size:0.62rem;font-weight:700;color:#475569;text-transform:uppercase;letter-spacing:0.07em;margin-bottom:4px;">Returned for Revision</div>
    <div style="font-size:0.82rem;color:#1e293b;"><?= sanitize($app['checker_remarks']) ?></div>
    <div style="margin-top:0.6rem;"><a href="?page=apply" class="btn btn-sm btn-warning" style="font-weight:600;border-radius:0;">Edit &amp; Resubmit</a></div>
    <?php else: ?>
    <div style="font-size:0.62rem;font-weight:700;color:#475569;text-transform:uppercase;letter-spacing:0.07em;margin-bottom:4px;">Remarks</div>
    <div style="font-size:0.82rem;color:#1e293b;"><?= sanitize($app['checker_remarks']) ?></div>
    <?php endif; ?>
</div>
<?php endif; ?>

<!-- KRA Submissions -->
<div class="neon-card p-0" style="overflow:hidden;border-radius:0;box-shadow:none;">
    <div style="padding:0.7rem 1.25rem;border-bottom:1px solid #e2e8f0;background:#f8fafc;display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:0.75rem;">
        <div>
            <div style="font-size:0.65rem;font-weight:700;text-transform:uppercase;letter-spacing:0.08em;color:#94a3b8;">KRA Submissions <?= helpBtn('KRA Submissions', 'These are your Key Result Area entries. Each entry needs a score and an evidence file uploaded. You must submit evidence for every entry before submitting your application. Click "Add / Edit Entries" to add or modify your KRA scores.') ?></div>
            <div style="font-size:0.7rem;color:#94a3b8;margin-top:1px;">Your Key Result Areas documentation and evidence</div>
        </div>
        <?php if ($can_edit): ?>
        <a href="?page=apply" class="btn btn-primary btn-sm" style="font-weight:600;padding:0.42rem 1rem;border-radius:0;">
            Add / Edit Entries
        </a>
        <?php else: ?>
        <span style="font-size:0.7rem;font-weight:600;color:#64748b;background:#f1f5f9;border:1px solid #e2e8f0;padding:3px 10px;">Locked</span>
        <?php endif; ?>
    </div>
    <?php if ($subs):
    $kra_order = ['Instruction','Research','Extension','Professional Development'];
    $subs_by_kra = [];
    foreach ($subs as $s) $subs_by_kra[$s['kra_category']][] = $s;
    foreach ($subs as $s) {
        if (!in_array($s['kra_category'], $kra_order)) $kra_order[] = $s['kra_category'];
    }
    $kra_order = array_unique($kra_order);
    $kra_short_map = ['Instruction'=>'KRA I','Research'=>'KRA II','Extension'=>'KRA III','Professional Development'=>'KRA IV'];
    ?>

    <?php foreach ($kra_order as $ki => $kra_cat):
        if (empty($subs_by_kra[$kra_cat])) continue;
        $short = $kra_short_map[$kra_cat] ?? $kra_cat;
        $count = count($subs_by_kra[$kra_cat]);
        $has_revision = false;
        foreach ($subs_by_kra[$kra_cat] as $s) {
            if (($s['revision_status'] ?? 'ok') === 'needs_revision') { $has_revision = true; break; }
        }
        $sec_id = 'krasec-' . $ki;
    ?>
    <!-- KRA <?= $ki+1 ?> collapsible section -->
    <div style="border-bottom:1px solid #e2e8f0;">
        <!-- Clickable header -->
        <div onclick="toggleKraSection('<?= $sec_id ?>')"
             id="<?= $sec_id ?>-hdr"
             style="display:flex;align-items:center;justify-content:space-between;
                    padding:0.55rem 1.25rem;cursor:pointer;user-select:none;
                    background:#f8fafc;border-bottom:1px solid #e2e8f0;"
             onmouseover="this.style.background='#f0f4fb'" onmouseout="this.style.background='#f8fafc'">
            <div style="display:flex;align-items:center;gap:0.5rem;">
                <span style="font-size:0.75rem;font-weight:700;color:<?= $has_revision?'#92400e':'#1a3a6b' ?>;"><?= $short ?> — <?= htmlspecialchars($kra_cat) ?></span>
                <span style="background:#f1f5f9;color:#475569;border:1px solid #e2e8f0;padding:0 6px;font-size:0.62rem;font-weight:700;"><?= $count ?></span>
                <?php if ($has_revision): ?><span style="font-size:0.62rem;font-weight:700;color:#92400e;background:#fffbeb;border:1px solid #fde68a;padding:0 6px;">Needs Revision</span><?php endif; ?>
            </div>
            <i class="bi bi-chevron-down" id="<?= $sec_id ?>-chev"
               style="color:#94a3b8;font-size:0.65rem;transition:transform 0.2s;transform:rotate(180deg);"></i>
        </div>

        <!-- Collapsible content -->
        <div id="<?= $sec_id ?>" style="display:block;">
        <div class="table-responsive">
        <table style="width:100%;border-collapse:collapse;font-size:0.82rem;table-layout:fixed;">
            <colgroup>
                <col style="width:80px;">
                <col style="width:auto;">
                <col style="width:85px;">
                <col style="width:55px;">
                <col style="width:45px;">
                <?php if ($can_edit): ?><col style="width:45px;"><?php endif; ?>
            </colgroup>
            <thead>
                <tr style="background:#f8fafc;border-bottom:1px solid #e2e8f0;">
                    <th style="padding:0.45rem 0.75rem;font-size:0.6rem;font-weight:700;text-transform:uppercase;letter-spacing:0.06em;color:#94a3b8;text-align:center;">Score</th>
                    <th style="padding:0.45rem 0.75rem;font-size:0.6rem;font-weight:700;text-transform:uppercase;letter-spacing:0.06em;color:#94a3b8;">Remarks</th>
                    <th style="padding:0.45rem 0.75rem;font-size:0.6rem;font-weight:700;text-transform:uppercase;letter-spacing:0.06em;color:#94a3b8;text-align:center;">Date</th>
                    <th style="padding:0.45rem 0.75rem;font-size:0.6rem;font-weight:700;text-transform:uppercase;letter-spacing:0.06em;color:#94a3b8;text-align:center;">Status</th>
                    <th style="padding:0.45rem 0.75rem;font-size:0.6rem;font-weight:700;text-transform:uppercase;letter-spacing:0.06em;color:#94a3b8;text-align:center;">View</th>
                    <?php if ($can_edit): ?>
                    <th style="padding:0.45rem 0.75rem;width:45px;"></th>
                    <?php endif; ?>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($subs_by_kra[$kra_cat] as $s):
                $sid         = (int)$s['submission_id'];
                $is_revision = ($s['revision_status'] ?? 'ok') === 'needs_revision';
                $is_verified = (bool)$s['verified'];
                $score_changed = isset($s['faculty_original_score']) && $s['faculty_original_score'] !== null
                              && (float)$s['faculty_original_score'] !== (float)$s['computed_points'];
                $open_appeal = null;
                foreach ($appeals_by_submission[$sid] ?? [] as $apx) {
                    if (in_array($apx['status'], ['open','under_review'], true)) { $open_appeal = $apx; break; }
                }
                $latest_appeal = $appeals_by_submission[$sid][0] ?? null;
                $appealable    = ($is_revision || $score_changed) && $appeals_allowed && !$open_appeal
                              && (int)($s['revision_by'] ?: $s['verified_by']) > 0;
                $fac_cat     = $s['kra_category'];
                $fac_raw     = (float)$s['computed_points'];
                $fac_cap     = $kra_caps_fac[$fac_cat] ?? 100;
                $fac_weight  = $kra_weights_fac[$fac_cat] ?? 0;
                $fac_cat_raw = $kra_raw_totals_fac[$fac_cat] ?? 0;
                $fac_capped  = min($fac_cap, $fac_cat_raw);
                $fac_share   = $fac_cat_raw > 0 ? ($fac_raw / $fac_cat_raw) : 0;
                $fac_weighted = round($fac_share * $fac_capped * $fac_weight, 2);

                // Build detail data for the modal (JSON-encoded)
                $detail = [
                    'sid'            => $sid,
                    'remarks'        => formatKraRemarks($s['kra_category'], $s['remarks'] ?? ''),
                    'score'          => number_format($fac_weighted, 2),
                    'raw'            => number_format($fac_raw, 2),
                    'original_score' => $score_changed ? number_format((float)$s['faculty_original_score'], 2) : null,
                    'status'         => $is_revision ? 'revision' : ($is_verified ? 'ok' : 'pending'),
                    'revision_note'  => $s['revision_note'] ?? '',
                    'date'           => date('M d, Y', strtotime($s['submitted_at'])),
                    'verified_at'    => $s['verified_at'] ? date('M d, Y', strtotime($s['verified_at'])) : null,
                    'evidence'       => array_map(fn($f) => [
                        'name' => $f['original_filename'],
                        'url'  => 'pages/view_file.php?file=' . urlencode($f['file_path']),
                    ], $s['evidence_files'] ?? []),
                    'appeal_open'    => $open_appeal ? (int)$open_appeal['appeal_id'] : null,
                    'appeal_latest'  => $latest_appeal ? (int)$latest_appeal['appeal_id'] : null,
                    'appealable'     => $appealable,
                    'kra_cat'        => $s['kra_category'],
                ];
            ?>
            <tr style="border-bottom:1px solid #f4f6f9;<?= $is_revision ? 'background:#fffbeb;' : '' ?>">
                <!-- Score -->
                <td style="padding:0.55rem 0.75rem;text-align:center;vertical-align:middle;">
                    <span style="background:#1a3a6b;color:#fff;padding:0.18rem 0.5rem;font-weight:700;font-size:0.78rem;display:inline-block;">
                        <?= number_format($fac_weighted, 2) ?>
                    </span>
                    <?php if ($score_changed): ?>
                    <div style="font-size:0.58rem;color:#64748b;margin-top:1px;">adjusted</div>
                    <?php endif; ?>
                </td>

                <!-- Remarks -->
                <td style="padding:0.55rem 0.75rem;color:#475569;font-size:0.8rem;vertical-align:middle;overflow:hidden;text-overflow:ellipsis;">
                    <?= formatKraRemarks($s['kra_category'], $s['remarks'] ?? '') ?>
                    <?php if (!empty($s['evidence_files'])): ?>
                    <div style="font-size:0.62rem;color:#94a3b8;margin-top:2px;">
                        <i class="bi bi-paperclip" style="font-size:0.6rem;"></i> <?= count($s['evidence_files']) ?> file<?= count($s['evidence_files'])>1?'s':'' ?>
                    </div>
                    <?php endif; ?>
                </td>

                <!-- Date -->
                <td style="padding:0.55rem 0.75rem;text-align:center;vertical-align:middle;font-size:0.72rem;color:#64748b;white-space:nowrap;">
                    <?= date('M d, Y', strtotime($s['submitted_at'])) ?>
                </td>

                <!-- Status pill only -->
                <td style="padding:0.55rem 0.75rem;text-align:center;vertical-align:middle;">
                    <?php if ($is_revision): ?>
                    <span style="font-size:0.68rem;padding:2px 6px;background:#fff7ed;color:#c2410c;border:1px solid #fed7aa;font-weight:700;">
                        <i class="bi bi-exclamation-triangle-fill"></i>
                    </span>
                    <?php elseif ($is_verified): ?>
                    <span style="font-size:0.68rem;padding:2px 6px;background:#eff6ff;color:#1a3a6b;border:1px solid #bfdbfe;font-weight:700;">
                        <i class="bi bi-check-circle-fill"></i>
                    </span>
                    <?php else: ?>
                    <span style="color:#cbd5e1;font-size:0.72rem;">—</span>
                    <?php endif; ?>
                </td>

                <!-- Eye button -->
                <td style="padding:0.55rem 0.75rem;text-align:center;vertical-align:middle;">
                    <button type="button"
                            onclick="openEntryDetail(<?= htmlspecialchars(json_encode($detail), ENT_QUOTES) ?>)"
                            style="width:26px;height:26px;border-radius:0;border:1px solid #e2e8f0;
                                   background:#f8fafc;color:#64748b;cursor:pointer;font-size:0.72rem;
                                   display:inline-flex;align-items:center;justify-content:center;"
                            title="View detail"
                            onmouseover="this.style.background='#f0f4fb';this.style.color='#1a3a6b'"
                            onmouseout="this.style.background='#f8fafc';this.style.color='#64748b'">
                        <i class="bi bi-eye"></i>
                    </button>
                </td>

                <!-- Edit (if allowed) -->
                <?php if ($can_edit): ?>
                <td style="padding:0.55rem 0.75rem;text-align:center;vertical-align:middle;">
                    <?php $tab = $kra_tabs[$s['kra_category']] ?? 'instruction'; ?>
                    <?php if ($app['status'] === 'needs_revision' && !$is_revision): ?>
                    <span style="color:#cbd5e1;font-size:0.78rem;">—</span>
                    <?php else: ?>
                    <a href="?page=apply&tab=<?= $tab ?>&edit_sid=<?= $sid ?>"
                       style="font-size:0.72rem;padding:0.2rem 0.5rem;border-radius:0;background:#f8fafc;
                              color:#1a3a6b;border:1px solid #e2e8f0;text-decoration:none;font-weight:600;
                              display:inline-flex;align-items:center;gap:2px;">
                        <i class="bi bi-pencil" style="font-size:0.68rem;"></i>
                    </a>
                    <?php endif; ?>
                </td>
                <?php endif; ?>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
        </div>
    </div>
    <?php endforeach; ?>

    <!-- Entry Detail Modal -->
    <div id="entryDetailModal"
         style="display:none;position:fixed;inset:0;background:rgba(15,23,42,0.5);
                z-index:9998;align-items:center;justify-content:center;padding:1rem;">
        <div style="background:#fff;border-radius:0;width:100%;max-width:440px;
                    box-shadow:0 8px 40px rgba(0,0,0,0.15);overflow:hidden;border:1px solid #e2e8f0;">

            <!-- Header -->
            <div style="background:#1a3a6b;padding:0.85rem 1.1rem;display:flex;align-items:center;justify-content:space-between;">
                <div>
                    <div style="color:#fff;font-weight:700;font-size:0.82rem;line-height:1.2;">Entry Detail</div>
                    <div id="edKraCat" style="color:#93c5fd;font-size:0.65rem;margin-top:1px;"></div>
                </div>
                <button onclick="document.getElementById('entryDetailModal').style.display='none'"
                        style="background:rgba(255,255,255,0.15);border:none;border-radius:50%;
                               width:26px;height:26px;min-width:26px;min-height:26px;
                               color:#fff;font-size:0.7rem;cursor:pointer;
                               display:flex;align-items:center;justify-content:center;flex-shrink:0;line-height:1;">
                    <i class="bi bi-x-lg" style="line-height:1;"></i>
                </button>
            </div>

            <!-- Body -->
            <div style="padding:0;">

                <!-- Entry -->
                <div style="padding:0.75rem 1.1rem;border-bottom:1px solid #f1f5f9;">
                    <div style="font-size:0.6rem;font-weight:700;text-transform:uppercase;letter-spacing:0.08em;color:#94a3b8;margin-bottom:3px;">Entry</div>
                    <div id="edRemarks" style="font-size:0.8rem;color:#1e293b;font-weight:500;"></div>
                </div>

                <!-- Score -->
                <div style="padding:0.75rem 1.1rem;border-bottom:1px solid #f1f5f9;display:flex;gap:1.5rem;align-items:flex-start;">
                    <div>
                        <div style="font-size:0.6rem;font-weight:700;text-transform:uppercase;letter-spacing:0.08em;color:#94a3b8;margin-bottom:3px;">Score</div>
                        <div id="edScore" style="font-size:0.95rem;font-weight:800;color:#1a3a6b;"></div>
                    </div>
                    <div id="edScoreChangedWrap" style="display:none;">
                        <div style="font-size:0.6rem;font-weight:700;text-transform:uppercase;letter-spacing:0.08em;color:#94a3b8;margin-bottom:3px;">Original</div>
                        <div id="edOrigScore" style="font-size:0.82rem;font-weight:600;color:#94a3b8;text-decoration:line-through;"></div>
                    </div>
                    <div>
                        <div style="font-size:0.6rem;font-weight:700;text-transform:uppercase;letter-spacing:0.08em;color:#94a3b8;margin-bottom:3px;">Evaluator Review</div>
                        <div id="edStatusBadge"></div>
                        <div id="edVerifiedAt" style="font-size:0.65rem;color:#94a3b8;margin-top:2px;"></div>
                    </div>
                </div>

                <!-- Revision note -->
                <div id="edRevisionNote" style="display:none;padding:0.75rem 1.1rem;border-bottom:1px solid #f1f5f9;background:#f8fafc;">
                    <div style="display:flex;align-items:flex-start;gap:0.6rem;">
                        <!-- Evaluator avatar -->
                        <div style="width:28px;height:28px;border-radius:50%;background:#1a3a6b;
                                    display:flex;align-items:center;justify-content:center;
                                    font-size:0.6rem;font-weight:700;color:#fff;flex-shrink:0;margin-top:1px;">EV</div>
                        <div style="flex:1;min-width:0;">
                            <div style="font-size:0.65rem;font-weight:700;color:#475569;margin-bottom:4px;">
                                Evaluator &nbsp;<span style="font-weight:400;color:#94a3b8;">· note</span>
                            </div>
                            <!-- Comment bubble -->
                            <div id="edRevisionText"
                                 style="background:#fff;border:1px solid #e2e8f0;padding:0.55rem 0.75rem;
                                        font-size:0.78rem;color:#1e293b;line-height:1.55;position:relative;">
                                <div style="position:absolute;top:8px;left:-5px;width:8px;height:8px;
                                            background:#fff;border-left:1px solid #e2e8f0;border-bottom:1px solid #e2e8f0;
                                            transform:rotate(45deg);"></div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Evidence -->
                <div style="padding:0.75rem 1.1rem;border-bottom:1px solid #f1f5f9;">
                    <div style="font-size:0.6rem;font-weight:700;text-transform:uppercase;letter-spacing:0.08em;color:#94a3b8;margin-bottom:5px;">Evidence Files</div>
                    <div id="edEvidence"></div>
                </div>

                <!-- Date + footer -->
                <div style="padding:0.65rem 1.1rem;display:flex;align-items:center;justify-content:space-between;gap:0.5rem;flex-wrap:wrap;">
                    <div style="font-size:0.7rem;color:#94a3b8;">
                        Submitted: <span id="edDate" style="font-weight:600;color:#475569;"></span>
                    </div>
                    <div id="edFooter" style="display:flex;gap:0.4rem;"></div>
                </div>
            </div>

        </div>
    </div>

    <script>
    function openEntryDetail(d) {
        document.getElementById('edKraCat').textContent   = d.kra_cat || '';
        document.getElementById('edRemarks').innerHTML    = d.remarks || '—';
        document.getElementById('edScore').textContent    = d.score + ' pts';
        document.getElementById('edDate').textContent     = d.date || '—';

        // Score change
        const scWrap = document.getElementById('edScoreChangedWrap');
        if (d.original_score) {
            scWrap.style.display = '';
            document.getElementById('edOrigScore').textContent = d.original_score + ' pts';
        } else {
            scWrap.style.display = 'none';
        }

        // Status
        const badgeEl = document.getElementById('edStatusBadge');
        const noteEl  = document.getElementById('edRevisionNote');
        const vatEl   = document.getElementById('edVerifiedAt');
        if (d.status === 'revision') {
            badgeEl.innerHTML = '<span style="font-size:0.68rem;font-weight:700;color:#92400e;background:#fffbeb;border:1px solid #fde68a;padding:1px 7px;">Needs Revision</span>';
            if (d.revision_note) {
                noteEl.style.display = '';
                const textEl = document.getElementById('edRevisionText');
                if (textEl) textEl.textContent = d.revision_note;
            } else { noteEl.style.display = 'none'; }
            vatEl.textContent = '';
        } else if (d.status === 'ok') {
            badgeEl.innerHTML = '<span style="font-size:0.68rem;font-weight:700;color:#1a3a6b;background:#eff6ff;border:1px solid #bfdbfe;padding:1px 7px;">Acceptable</span>';
            noteEl.style.display = 'none';
            vatEl.textContent = d.verified_at ? 'Verified ' + d.verified_at : '';
        } else {
            badgeEl.innerHTML = '<span style="font-size:0.68rem;color:#94a3b8;">Pending</span>';
            noteEl.style.display = 'none';
            vatEl.textContent = '';
        }

        // Evidence
        const evEl = document.getElementById('edEvidence');
        if (d.evidence && d.evidence.length) {
            evEl.innerHTML = d.evidence.map(f =>
                `<a href="${f.url}" target="_blank" rel="noopener noreferrer"
                    style="display:inline-flex;align-items:center;gap:3px;padding:2px 8px;
                           background:#f8fafc;color:#1a3a6b;border:1px solid #e2e8f0;text-decoration:none;
                           font-size:0.72rem;margin-right:4px;margin-bottom:3px;">
                    <i class="bi bi-file-earmark" style="font-size:0.65rem;"></i>${f.name.length > 20 ? f.name.slice(0,20)+'…' : f.name}
                 </a>`
            ).join('');
        } else {
            evEl.innerHTML = '<span style="font-size:0.72rem;color:#94a3b8;">No files attached</span>';
        }

        // Footer actions
        const footerEl = document.getElementById('edFooter');
        let footerHTML = '';
        if (d.appeal_open) {
            footerHTML += `<a href="index.php?page=appeals&id=${d.appeal_open}"
                style="padding:0.38rem 0.85rem;border:1px solid #e2e8f0;background:#f8fafc;
                       color:#1a3a6b;font-size:0.75rem;font-weight:600;text-decoration:none;">
                View Appeal</a>`;
        } else if (d.appealable) {
            footerHTML += `<button type="button" onclick="document.getElementById('entryDetailModal').style.display='none';openAppealModal(${d.sid},'${d.kra_cat.replace(/'/g,"\\'")}')"
                style="padding:0.38rem 0.85rem;border:1px solid #1a3a6b;background:#1a3a6b;
                       color:#fff;font-size:0.75rem;font-weight:600;cursor:pointer;">
                File Appeal</button>`;
        } else if (d.appeal_latest) {
            footerHTML += `<a href="index.php?page=appeals&id=${d.appeal_latest}"
                style="padding:0.38rem 0.85rem;border:1px solid #e2e8f0;background:#f8fafc;
                       color:#475569;font-size:0.75rem;font-weight:600;text-decoration:none;">
                View Appeal</a>`;
        }
        footerHTML += `<button onclick="document.getElementById('entryDetailModal').style.display='none'"
            style="padding:0.38rem 0.85rem;border:1px solid #e2e8f0;background:#fff;
                   color:#475569;font-size:0.75rem;font-weight:600;cursor:pointer;">Close</button>`;
        footerEl.innerHTML = footerHTML;

        document.getElementById('entryDetailModal').style.display = 'flex';
    }
    document.getElementById('entryDetailModal').addEventListener('click', function(e) {
        if (e.target === this) this.style.display = 'none';
    });
    </script>

    <script>
    function toggleKraSection(id) {
        const sec  = document.getElementById(id);
        const chev = document.getElementById(id + '-chev');
        if (!sec) return;
        const open = sec.style.display !== 'none';
        sec.style.display     = open ? 'none' : 'block';
        if (chev) chev.style.transform = open ? '' : 'rotate(180deg)';
    }
    // Open all sections by default
    document.addEventListener('DOMContentLoaded', function() {
        document.querySelectorAll('[id^="krasec-"]').forEach(function(sec) {
            if (!sec.id.includes('-hdr') && !sec.id.includes('-chev')) {
                sec.style.display = 'block';
                const chev = document.getElementById(sec.id + '-chev');
                if (chev) chev.style.transform = 'rotate(180deg)';
            }
        });
    });
    </script>
    <?php else: ?>
    <div style="padding:2rem 1.25rem;text-align:center;">
        <i class="bi bi-inbox" style="font-size:2rem;color:#cbd5e1;display:block;margin-bottom:0.5rem;"></i>
        <div style="font-size:0.85rem;font-weight:600;color:#94a3b8;margin-bottom:0.25rem;">No KRA Submissions Yet</div>
        <div style="font-size:0.75rem;color:#cbd5e1;margin-bottom:1rem;">Start by adding your Key Result Areas documentation</div>
        <?php if ($can_edit): ?>
        <a href="?page=apply" class="btn btn-primary btn-sm" style="font-weight:600;padding:0.45rem 1.1rem;border-radius:0;">
            Start Entering KRA Scores
        </a>
        <?php endif; ?>
    </div>
    <?php endif; ?>
</div>

<div id="appealModal" style="display:none;position:fixed;inset:0;background:rgba(15,23,42,0.55);z-index:9999;align-items:center;justify-content:center;padding:1rem;">
    <div style="background:#fff;border-radius:12px;width:100%;max-width:520px;border:1px solid #e2e8f0;box-shadow:0 20px 60px rgba(0,0,0,0.22);">
        <div style="padding:1rem 1.25rem;border-bottom:1px solid #e2e8f0;display:flex;justify-content:space-between;align-items:center;">
            <strong style="color:#1a3a6b;">File Appeal</strong>
            <button type="button" onclick="document.getElementById('appealModal').style.display='none'" style="background:none;border:none;font-size:1.3rem;color:#94a3b8;">&times;</button>
        </div>
        <form method="POST" action="index.php?page=appeals" enctype="multipart/form-data" style="padding:1.25rem;">
            <input type="hidden" name="action" value="file_appeal">
            <input type="hidden" name="submission_id" id="appealSubmissionId">
            <div style="font-size:0.84rem;color:#475569;margin-bottom:0.75rem;">
                Appealing item: <strong id="appealKraLabel"></strong>
            </div>
            <label class="form-label">Reason for Appeal</label>
            <textarea name="reason" class="form-control" rows="4" required placeholder="Explain why this flag or score change should be reviewed."></textarea>
            <label class="form-label mt-3">Supporting Files</label>
            <input type="file" name="attachments[]" class="form-control" multiple>
            <div class="form-text">PDF, image, Word files. Max 5 MB each.</div>
            <div class="d-flex justify-content-end gap-2 mt-3">
                <button type="button" class="btn btn-outline-secondary" onclick="document.getElementById('appealModal').style.display='none'">Cancel</button>
                <button class="btn btn-primary"><i class="bi bi-send me-1"></i>Submit Appeal</button>
            </div>
        </form>
    </div>
</div>

<script>
function openAppealModal(submissionId, kraLabel) {
    document.getElementById('appealSubmissionId').value = submissionId;
    document.getElementById('appealKraLabel').textContent = kraLabel;
    document.getElementById('appealModal').style.display = 'flex';
}
</script>

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
<div class="neon-card p-0" style="overflow:hidden;margin-top:0.75rem;border-radius:0;box-shadow:none;" id="cmpCard">

    <!-- Header -->
    <div style="padding:0.7rem 1.25rem;background:#f8fafc;border-bottom:1px solid #e2e8f0;
                display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:0.5rem;">
        <div>
            <div style="font-size:0.65rem;font-weight:700;text-transform:uppercase;letter-spacing:0.08em;color:#94a3b8;">Score Evaluation Comparison</div>
            <div style="font-size:0.7rem;color:#64748b;margin-top:1px;">Your submitted scores alongside evaluator evaluations</div>
        </div>
        <?php if (!$is_live): ?>
        <span style="font-size:0.62rem;font-weight:600;color:#64748b;background:#f1f5f9;border:1px solid #e2e8f0;padding:2px 8px;">Evaluation Complete</span>
        <?php endif; ?>
    </div>

    <!-- Table container filled by JS -->
    <div style="overflow-x:auto;">
        <div id="cmpTableWrap">
            <div style="padding:2rem;text-align:center;color:#94a3b8;font-size:0.82rem;">
                <div class="spinner-border spinner-border-sm me-2"></div>Loading...
            </div>
        </div>
    </div>

</div>

<style>
@keyframes livePulse {
    0%,100% { opacity:1; transform:scale(1); }
    50%      { opacity:.45; transform:scale(1.35); }
}
.cmp-your-badge { background:#f1f5f9;color:#1a3a6b;padding:.18rem .55rem;font-weight:700;font-size:.8rem;border:1px solid #e2e8f0; }
.cmp-s1-badge   { background:#1a3a6b;color:#fff;padding:.18rem .55rem;font-weight:700;font-size:.8rem; }
.cmp-s2-badge   { background:#334155;color:#fff;padding:.18rem .55rem;font-weight:700;font-size:.8rem; }
.cmp-nochange   { color:#94a3b8;font-size:.75rem; }
.cmp-pending    { color:#cbd5e1;font-size:.75rem; }
.cmp-rev-note   { padding:.3rem .55rem;background:#f8fafc;border-left:2px solid #475569;font-size:.7rem;margin-bottom:3px; }
.cmp-ws-your    { background:#f1f5f9;color:#1a3a6b;padding:.28rem .7rem;font-weight:700;font-size:.88rem;border:1px solid #e2e8f0; }
.cmp-ws-s1      { background:#1a3a6b;color:#fff;padding:.28rem .7rem;font-weight:700;font-size:.88rem; }
.cmp-ws-s2      { background:#334155;color:#fff;padding:.28rem .7rem;font-weight:700;font-size:.88rem; }
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

        let html = '<table style="width:100%;border-collapse:collapse;font-size:.81rem;margin:0;">';

        // -- thead
        html += `<thead><tr style="border-bottom:2px solid #e2e8f0;background:#f8fafc;">
            <th style="padding:.55rem 1rem;color:#94a3b8;font-size:.62rem;text-transform:uppercase;letter-spacing:.07em;font-weight:700;width:130px;">KRA</th>
            <th style="padding:.55rem .75rem;font-size:.62rem;text-transform:uppercase;letter-spacing:.07em;font-weight:700;text-align:center;color:#94a3b8;width:100px;">Your Score</th>`;

        if (hasS1) {
            html += `<th style="padding:.55rem .75rem;font-size:.62rem;text-transform:uppercase;letter-spacing:.07em;font-weight:700;text-align:center;color:#94a3b8;width:100px;">Stage 1<div style="font-size:.6rem;font-weight:400;color:#cbd5e1;text-transform:none;letter-spacing:0;">Subcommittee</div></th>`;
        } else {
            html += `<th style="padding:.55rem .75rem;font-size:.62rem;text-transform:uppercase;letter-spacing:.07em;font-weight:700;text-align:center;color:#cbd5e1;width:100px;">Stage 1<div style="font-size:.6rem;font-weight:400;text-transform:none;letter-spacing:0;">Pending</div></th>`;
        }

        if (hasS2) {
            html += `<th style="padding:.55rem .75rem;font-size:.62rem;text-transform:uppercase;letter-spacing:.07em;font-weight:700;text-align:center;color:#94a3b8;width:100px;">Stage 2<div style="font-size:.6rem;font-weight:400;color:#cbd5e1;text-transform:none;letter-spacing:0;">ITC</div></th>`;
        }

        html += `<th style="padding:.55rem .75rem;font-size:.62rem;text-transform:uppercase;letter-spacing:.07em;font-weight:700;color:#94a3b8;">Remarks</th>
        </tr></thead><tbody>`;

        // -- tbody
        KRA_ORDER.forEach(kra => {
            const rows = groups[kra] || [];
            if (!rows.length) return;

            // KRA group header row
            html += `<tr style="background:#f8fafc;border-top:1px solid #e2e8f0;border-bottom:1px solid #e2e8f0;">
                <td colspan="${2 + (hasS1?1:0) + (hasS2?1:0) + 1}" style="padding:.4rem 1rem;font-size:.62rem;font-weight:700;color:#94a3b8;text-transform:uppercase;letter-spacing:.07em;">${esc(kra)}</td>
            </tr>`;

            rows.forEach((s) => {
                const s1Adj = s.checker_adjusted && s.adjusted_stage === 'stage1';
                const s2Adj = s.checker_adjusted && s.adjusted_stage === 'stage2';
                const hasRev = s.revision_status === 'needs_revision';

                html += `<tr style="border-bottom:1px solid #f4f6f9;${hasRev ? 'background:#fffbf0;' : ''}">`;

                // Your score
                html += `<td style="padding:.55rem 1rem;text-align:center;vertical-align:middle;">
                    <span class="cmp-your-badge">${fmt(s.faculty_score)}</span>
                </td>`;

                // Stage 1
                html += `<td style="padding:.55rem .75rem;text-align:center;vertical-align:middle;">`;
                if (s1Adj) {
                    html += `<span class="cmp-s1-badge">${fmt(s.current_score)}</span>`;
                    if (s.adjusted_by) html += `<div style="font-size:.6rem;color:#64748b;margin-top:2px;">${esc(s.adjusted_by)}</div>`;
                } else if (hasS1) {
                    html += `<span class="cmp-nochange">No change</span>`;
                } else {
                    html += `<span class="cmp-pending">—</span>`;
                }
                html += `</td>`;

                // Stage 2
                if (hasS2) {
                    html += `<td style="padding:.55rem .75rem;text-align:center;vertical-align:middle;">`;
                    if (s2Adj) {
                        html += `<span class="cmp-s2-badge">${fmt(s.current_score)}</span>`;
                        if (s.adjusted_by) html += `<div style="font-size:.6rem;color:#64748b;margin-top:2px;">${esc(s.adjusted_by)}</div>`;
                    } else if (hasS2) {
                        html += `<span class="cmp-nochange">No change</span>`;
                    } else {
                        html += `<span class="cmp-pending">—</span>`;
                    }
                    html += `</td>`;
                }

                // Remarks
                html += `<td style="padding:.55rem .75rem;vertical-align:middle;font-size:.75rem;color:#475569;">`;
                let hasRem = false;
                if (s.checker_note) {
                    html += `<div class="cmp-rev-note"><strong style="color:#334155;">Note:</strong> ${esc(s.checker_note)}</div>`;
                    hasRem = true;
                }
                if (s.revision_note) {
                    html += `<div class="cmp-rev-note" style="margin-top:2px;">${esc(s.revision_note)}</div>`;
                    hasRem = true;
                }
                if (!hasRem) html += `<span style="color:#cbd5e1;font-size:.72rem;">No evaluator notes</span>`;
                html += `</td></tr>`;
            });
        });
        html += `</tbody>`;

        // -- tfoot
        const wsY  = parseFloat(data.ws_faculty ?? 0);
        const wsS1 = parseFloat(data.ws_stage1  ?? 0);
        const wsS2 = data.ws_stage2 !== null ? parseFloat(data.ws_stage2) : null;
        const d1   = Math.round((wsS1 - wsY) * 100) / 100;
        const d2   = wsS2 !== null ? Math.round((wsS2 - wsY) * 100) / 100 : null;

        const diffHtml = (d) => {
            if (d === null) return '';
            if (d === 0) return `<div style="font-size:.62rem;margin-top:2px;color:#94a3b8;">No net change</div>`;
            const sign = d > 0 ? '+' : '';
            const col  = d > 0 ? '#1a3a6b' : '#475569';
            return `<div style="font-size:.62rem;margin-top:2px;color:${col};">${sign}${fmt(d)} vs your score</div>`;
        };

        html += `<tfoot><tr style="background:#f8fafc;border-top:2px solid #e2e8f0;">
            <td style="padding:.65rem 1rem;font-size:.7rem;font-weight:700;color:#1e293b;">Weighted Score</td>
            <td style="padding:.65rem .75rem;text-align:center;"><span class="cmp-ws-your">${fmt(wsY)}</span></td>
            <td style="padding:.65rem .75rem;text-align:center;">`;
        if (hasS1) {
            html += `<span class="cmp-ws-s1">${fmt(wsS1)}</span>${diffHtml(d1)}`;
        } else {
            html += `<span class="cmp-pending">—</span>`;
        }
        html += `</td>`;

        if (hasS2) {
            html += `<td style="padding:.65rem .75rem;text-align:center;">`;
            if (wsS2 !== null) {
                html += `<span class="cmp-ws-s2">${fmt(wsS2)}</span>${diffHtml(d2)}`;
            } else {
                html += `<span class="cmp-pending">—</span>`;
            }
            html += `</td>`;
        }

        html += `<td style="padding:.65rem .75rem;font-size:.7rem;color:#94a3b8;vertical-align:middle;">
                Evaluator score after all adjustments. Not a final decision.
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
