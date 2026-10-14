<?php if ((require __DIR__ . '/../../../backend/controllers/shared/review_application.php') === false) return; ?>
<?php showFlash(); ?>

<!-- -- Page header --------------------------------------------- -->
<div class="page-header [align-items:center] [margin-bottom:1.25rem] [padding-bottom:1.25rem] [border-bottom:1px_solid_#f1f5f9]">

    <!-- Left: back + title -->
    <div class="[display:flex] [align-items:center] [gap:0.75rem] [min-width:0]">
        <a href="?page=<?= isAdmin() ? 'all_applications' : 'review_queue' ?>"
           class="[display:inline-flex] [align-items:center] [gap:0.3rem] [padding:0.35rem_0.75rem] [border-radius:6px] [background:#fff] [font-size:0.75rem] [font-weight:600] [text-decoration:none] [flex-shrink:0]" style="border:1px solid #e2e8f0;color:#475569;"
           onmouseover="this.style.borderColor='#1a3a6b';this.style.color='#1a3a6b'"
           onmouseout="this.style.borderColor='#e2e8f0';this.style.color='#475569'">
            <i class="bi bi-arrow-left"></i><?= isAdmin() ? 'All Applications' : 'Queue' ?>
        </a>
        <div class="[min-width:0]">
            <div class="[font-size:0.72rem] [color:#94a3b8] [font-weight:500] [text-transform:uppercase] [letter-spacing:0.05em] [margin-bottom:1px]">Reviewing Application</div>
            <div class="[font-size:0.95rem] [font-weight:700] [color:#0f172a] [white-space:nowrap] [overflow:hidden] [text-overflow:ellipsis]"><?= htmlspecialchars(formatDisplayName($app)) ?></div>
        </div>
    </div>

    <!-- Right: status + route + print -->
    <div class="[display:flex] [align-items:center] [gap:0.6rem] [flex-wrap:wrap] [flex-shrink:0]">

        <?= statusBadge($app['status']) ?>



        <a href="backend/services/reports/kra_pdf.php?uid=<?= $app['user_id'] ?>&cycle_id=<?= $app['cycle_id'] ?>"
           target="_blank"
           class="[display:inline-flex] [align-items:center] [gap:0.3rem] [padding:0.35rem_0.85rem] [border-radius:6px] [color:#1a3a6b] [font-size:0.75rem] [font-weight:600] [text-decoration:none]" style="background:#fff;border:1px solid #e2e8f0;"
           onmouseover="this.style.background='#f0f4fb';this.style.borderColor='#1a3a6b'"
           onmouseout="this.style.background='#fff';this.style.borderColor='#e2e8f0'">
            <i class="bi bi-printer"></i>Print ISS
        </a>

    </div>
</div>

<?php
// -- JC01 s.2026 flags panel ----------------------------------------------
$double_flags  = json_decode($app['double_counting_flags'] ?? '[]', true) ?: [];
$pending_docs  = json_decode($app['pending_documentation'] ?? '[]', true) ?: [];
$config_incomp = json_decode($app['config_incomplete'] ?? '[]', true) ?: [];
$orch_flags    = json_decode($app['orchestrator_flags'] ?? '[]', true) ?: [];

$eval_ok       = isset($app['evaluation_period_ok']) ? (bool)$app['evaluation_period_ok'] : true;

$has_flags = !$eval_ok || !empty($double_flags) || !empty($pending_docs) || !empty($config_incomp) || !empty($orch_flags);
if ($has_flags):
?>
<div class="neon-card mb-4 [padding:0] [overflow:hidden] [border-left:4px_solid_#c2410c]">
    <div class="[padding:0.7rem_1rem] [background:#fff7ed] [border-bottom:1px_solid_#fed7aa] [display:flex] [align-items:center] [gap:0.5rem]">
        <i class="bi bi-exclamation-triangle-fill [color:#c2410c]"></i>
        <span class="[font-weight:700] [color:#c2410c] [font-size:0.88rem]">JC01 s.2026 Scoring Flags  -  Require Evaluator Review</span>
        <span class="[margin-left:auto] [font-size:0.72rem] [color:#9a3412]">Human committee makes all binding decisions</span>
    </div>
    <div class="[padding:0.85rem_1rem]">
        <?php if (!$eval_ok): ?>
        <div class="[margin-bottom:0.6rem] [padding:0.5rem_0.75rem] [background:#fef2f2] [border:1px_solid_#fecaca] [border-radius:6px] [font-size:0.8rem] [color:#dc2626]">
            <i class="bi bi-calendar-x me-1"></i><strong>Evaluation Period:</strong> Application is linked to an archived cycle.
        </div>
        <?php endif; ?>
        <?php foreach ($orch_flags as $f): ?>
        <div class="[margin-bottom:0.4rem] [padding:0.4rem_0.75rem] [background:#fef2f2] [border:1px_solid_#fecaca] [border-radius:6px] [font-size:0.78rem] [color:#b91c1c]">
            <i class="bi bi-shield-x me-1"></i><?= htmlspecialchars($f) ?>
        </div>
        <?php endforeach; ?>
        <?php foreach ($double_flags as $f): ?>
        <div class="[margin-bottom:0.4rem] [padding:0.4rem_0.75rem] [background:#fff7ed] [border:1px_solid_#fed7aa] [border-radius:6px] [font-size:0.78rem] [color:#c2410c]">
            <i class="bi bi-copy me-1"></i><strong>Double-Counting:</strong> "<?= htmlspecialchars($f['title'] ?? '') ?>" appears in <?= htmlspecialchars(implode(' + ', $f['categories'] ?? [])) ?>
        </div>
        <?php endforeach; ?>
        <?php if (!empty($pending_docs)): ?>
        <div class="[margin-top:0.5rem]">
            <div class="[font-size:0.75rem] [font-weight:700] [color:#475569] [text-transform:uppercase] [letter-spacing:0.04em] [margin-bottom:0.35rem]"><i class="bi bi-file-earmark-x me-1"></i>Pending Documentation (<?= count($pending_docs) ?>)</div>
            <?php foreach (array_slice($pending_docs, 0, 8) as $pd): ?>
            <div class="[padding:0.3rem_0.6rem] [font-size:0.76rem] [color:#64748b] [border-left:2px_solid_#fcd34d] [margin-bottom:0.2rem] [background:#fffbeb]">
                <?= htmlspecialchars($pd) ?>
            </div>
            <?php endforeach; ?>
            <?php if (count($pending_docs) > 8): ?>
            <div class="[font-size:0.72rem] [color:#94a3b8] [margin-top:0.2rem]">+ <?= count($pending_docs) - 8 ?> more pending items...</div>
            <?php endif; ?>
        </div>
        <?php endif; ?>
        <?php if (!empty($config_incomp)): ?>
        <div class="[margin-top:0.5rem]">
            <div class="[font-size:0.75rem] [font-weight:700] [color:#7c3aed] [text-transform:uppercase] [letter-spacing:0.04em] [margin-bottom:0.35rem]"><i class="bi bi-wrench me-1"></i>Config Incomplete (<?= count($config_incomp) ?>)</div>
            <?php foreach (array_slice($config_incomp, 0, 5) as $ci): ?>
            <div class="[padding:0.3rem_0.6rem] [font-size:0.76rem] [color:#6d28d9] [border-left:2px_solid_#c4b5fd] [margin-bottom:0.2rem] [background:#f5f3ff]">
                <?= htmlspecialchars($ci) ?>
            </div>
            <?php endforeach; ?>
            <?php if (count($config_incomp) > 5): ?>
            <div class="[font-size:0.72rem] [color:#94a3b8] [margin-top:0.2rem]">+ <?= count($config_incomp) - 5 ?> more config items...</div>
            <?php endif; ?>
        </div>
        <?php endif; ?>
    </div>
</div>
<?php endif; ?>

<!-- -- Evaluator completion progress -------------------------- -->
<?php if (!isAdmin()): ?>
<div class="neon-card mb-4 [padding:1.1rem_1.5rem]">
    <div class="d-flex justify-content-between align-items-start mb-2 flex-wrap gap-2">
        <div>
            <span class="fw-bold [color:#1a3a6b] [font-size:0.9rem]">
                <i class="bi bi-people-fill me-2"></i>Evaluator Progress
            </span>
            <span class="text-muted small ms-2"><?= $total_checkers ?> evaluator(s) required to complete the evaluation</span>
        </div>
        <div class="d-flex gap-2 flex-wrap">
            <?php if ($approved_count > 0): ?>
            <span class="badge bg-secondary [font-size:0.78rem] [white-space:nowrap]"><?= $approved_count ?>/<?= $total_checkers ?> complete</span>
            <?php endif; ?>
            <?php if ($rejected_count > 0): ?>
            <span class="badge bg-secondary [font-size:0.78rem] [white-space:nowrap]"><?= $rejected_count ?>/<?= $total_checkers ?> returned</span>
            <?php endif; ?>
            <?php if ($approved_count === 0 && $rejected_count === 0): ?>
            <span class="badge bg-secondary [font-size:0.78rem] [white-space:nowrap]">0/<?= $total_checkers ?> decided</span>
            <?php endif; ?>
        </div>
    </div>
    <!-- Progress bar - neutral completion/return progress -->
    <div class="[height:8px] [background:#e2e8f0] [border-radius:99px] [margin-bottom:1rem] [overflow:hidden] [display:flex]">
        <div style="height:100%;width:<?= $total_checkers > 0 ? min(100, round(($approved_count / $total_checkers) * 100)) : 0 ?>%;
                    background:#1a3a6b;border-radius:99px 0 0 99px;transition:width 0.4s ease;"></div>
        <div style="height:100%;width:<?= $total_checkers > 0 ? min(100, round(($rejected_count / $total_checkers) * 100)) : 0 ?>%;
                    background:#334155;transition:width 0.4s ease;"></div>
    </div>
    <!-- All active checkers and their decisions -->
    <?php
    // Get all active checkers with their decision for this application
    $all_checkers_stmt = $pdo->prepare("
        SELECT DISTINCT u.user_id, u.checker_label,
               r.decision, r.remarks, r.decided_at
        FROM checker_kra_assignments ka
        JOIN users u ON u.user_id = ka.checker_id
        LEFT JOIN application_checker_reviews r
            ON r.application_id = ? AND r.checker_id = u.user_id
        WHERE ka.cycle_id = ? AND u.role = 'evaluator' AND u.status = 'active'
        ORDER BY u.user_id ASC
    ");
    $all_checkers_stmt->execute([$app_id, (int)$app['cycle_id']]);
    $all_checkers_list = $all_checkers_stmt->fetchAll();
    $slot_colors = ['#1a3a6b','#1a3a6b','#1a3a6b','#1a3a6b','#475569','#334155'];
    ?>
    <div class="d-flex flex-wrap gap-2">
        <?php foreach ($all_checkers_list as $idx => $chk):
            $is_me        = (int)$chk['user_id'] === $my_checker_id;
            $dec          = $chk['decision'] ?? null;
            $border_color = $dec === 'approved' ? '#1a3a6b' : ($dec === 'rejected' ? '#334155' : '#e2e8f0');
            $bg_color     = $dec === 'approved' ? '#eff6ff' : ($dec === 'rejected' ? '#f8fafc' : '#f8fafc');
            $dec_color    = $dec === 'approved' ? '#1a3a6b' : ($dec === 'rejected' ? '#334155' : '#94a3b8');
            $dec_icon     = $dec === 'approved' ? 'check-circle-fill' : ($dec === 'rejected' ? 'x-circle-fill' : 'hourglass');
            $dec_label    = $dec === 'approved' ? 'Evaluation Complete' : ($dec === 'rejected' ? 'Returned for Revision' : 'Pending');
        ?>
        <div style="flex:1;min-width:130px;max-width:200px;padding:0.65rem 0.85rem;border-radius:8px;
                    border:2px solid <?= $border_color ?>;background:<?= $bg_color ?>;">
            <div class="d-flex align-items-center gap-2 mb-1">
                <div style="width:26px;height:26px;border-radius:50%;
                            background:<?= $slot_colors[$idx % count($slot_colors)] ?>;
                            display:flex;align-items:center;justify-content:center;flex-shrink:0;">
                    <span class="[color:#fff] [font-size:0.68rem] [font-weight:700]">
                        <i class="bi bi-person-fill"></i>
                    </span>
                </div>
                <div class="[min-width:0]">
                    <div class="[font-size:0.75rem] [font-weight:600] [color:#1e293b] [white-space:nowrap] [overflow:hidden] [text-overflow:ellipsis]">
                        <?= htmlspecialchars(checkerDisplayLabel($chk)) ?><?= $is_me ? ' <span class="[color:#64748b] [font-weight:400] [font-size:0.65rem]">(you)</span>' : '' ?>
                    </div>
                </div>
            </div>
            <span style="font-size:0.67rem;font-weight:600;color:<?= $dec_color ?>;">
                <i class="bi bi-<?= $dec_icon ?> me-1"></i><?= $dec_label ?>
            </span>
            <?php if ($chk['decided_at']): ?>
            <div class="[font-size:0.6rem] [color:#94a3b8] [margin-top:1px]"><?= date('M d, Y', strtotime($chk['decided_at'])) ?></div>
            <?php endif; ?>
            <?php if ($dec === 'rejected' && !empty($chk['remarks'])): ?>
            <div class="[font-size:0.63rem] [color:#1a3a6b] [margin-top:2px] [font-style:italic] [word-break:break-word]">
                "<?= sanitize($chk['remarks']) ?>"
            </div>
            <?php endif; ?>
        </div>
        <?php endforeach; ?>
    </div>
</div>
<?php endif; ?>

<!-- -- Faculty Info ------------------------------------------ -->
<div class="neon-card mb-4">
    <div class="row g-3">
        <div class="col-md-3"><span class="text-muted small d-block">Full Name</span><strong><?= htmlspecialchars(formatDisplayName($app)) ?></strong></div>
        <div class="col-md-3"><span class="text-muted small d-block">Employee ID</span><strong><?= sanitize($app['employee_id'] ?? 'N/A') ?></strong></div>
        <div class="col-md-3"><span class="text-muted small d-block">Campus</span><strong><?= sanitize($app['campus_name'] ?? 'N/A') ?></strong></div>
        <div class="col-md-3"><span class="text-muted small d-block">Faculty Rank</span><strong><?= sanitize($app['rank'] ?? 'N/A') ?></strong></div>
        <div class="col-md-3"><span class="text-muted small d-block">Cycle</span><strong class="text-primary"><?= sanitize($app['cycle_name'] ?? 'N/A') ?></strong></div>
        <div class="col-md-3"><span class="text-muted small d-block">Weighted Score</span><strong class="[color:#1a3a6b] [font-size:1.3rem]"><?= number_format($score_result['weighted_score'], 2) ?></strong></div>
        <div class="col-md-3">
            <span class="text-muted small d-block">Sub-rank Increment</span>
            <?php $inc = $score_result['sub_rank_increment']; ?>
            <span class="badge <?= $inc > 0 ? 'bg-success' : 'bg-secondary' ?> fs-6">
                <?= $inc > 0 ? "+{$inc} sub-rank" . ($inc > 1 ? 's' : '') : 'No sub-rank increment' ?>
            </span>
        </div>
        <div class="col-md-3"><span class="text-muted small d-block">Submitted</span><strong><?= $app['submitted_at'] ? date('M d, Y H:i', strtotime($app['submitted_at'])) : 'N/A' ?></strong></div>
        <?php if ($visible_checker_remarks !== ''): ?>
        <div class="col-12"><span class="text-muted small d-block">Remarks</span><span class="text-warning"><?= sanitize($visible_checker_remarks) ?></span></div>
        <?php endif; ?>
        <div class="col-12">
            <div class="p-4 rounded-lg [background:#eff6ff] [border:1px_solid_#a5d6a7]">
                <div class="d-flex align-items-center gap-4 flex-wrap">
                    <div>
                        <span class="text-muted small d-block">Recommended Rank / Sub-rank</span>
                        <strong class="[font-size:1.1rem] [color:#1a3a6b]"><?= sanitize($potential_data['potential_rank']) ?></strong>
                        <?php if ($potential_data['crossed_category']): ?>
                        <span class="badge bg-warning text-dark ms-2 [font-size:0.7rem]">Category crossed</span>
                        <?php endif; ?>
                    </div>
                    <?php if (!empty($potential_data['flags'])): ?>
                    <div class="d-flex flex-wrap gap-1">
                        <?php foreach ($potential_data['flags'] as $flag):
                            $fc = 'secondary';
                            if (str_contains($flag, 'CC'))      $fc = 'warning text-dark';
                            if (str_contains($flag, 'crossed')) $fc = 'info';
                            if (str_contains($flag, 'EAC'))     continue;
                        ?>
                        <span class="badge bg-<?= $fc ?> [font-size:0.72rem]">
                            <i class="bi bi-info-circle me-1"></i><?= sanitize($flag) ?>
                        </span>
                        <?php endforeach; ?>
                    </div>
                    <?php endif; ?>
                </div>
                <div class="mt-1 [font-size:0.72rem] [color:#64748b]">
                    <i class="bi bi-lock-fill me-1"></i>System-computed per DBM-CHED JC No. 3, s. 2022
                </div>
            </div>
        </div>
    </div>
</div>

<!-- -- Weighted Score Breakdown -------------------------------- -->
<?php if ($score_result['weighted_score'] < 41): ?>
<div class="alert mb-4 d-flex align-items-center gap-4 [background:#f8fafc] [border:2px_solid_#334155] [border-radius:10px] [padding:1rem_1.25rem]"
    >
    <i class="bi bi-exclamation-triangle-fill [color:#334155] [font-size:1.4rem] [flex-shrink:0]"></i>
    <div>
        <div class="[font-weight:700] [color:#1e293b] [font-size:0.95rem]">Below Minimum Weighted Score</div>
        <div class="[color:#1e293b] [font-size:0.85rem] [margin-top:2px]">
            This application's weighted score is <strong><?= number_format($score_result['weighted_score'], 2) ?></strong>,
            which is below the minimum of <strong>41</strong> required for reclassification.
            It cannot be completed as-is. Please return it to the faculty for revision or adjust the scores accordingly.
        </div>
    </div>
</div>
<?php endif; ?>
<div class="neon-card mb-4">
    <h6 class="mb-4 [color:var(--blue-dark)]"><i class="bi bi-calculator me-2"></i>Weighted Score - <?= htmlspecialchars($faculty_rank ?: 'No rank set') ?></h6>
    <div class="table-responsive">
        <table class="table table-modern mb-0 [font-size:0.85rem]">
            <thead><tr class="[background:#f1f5f9]">
                <th class="[color:#1a3a6b]">KRA</th>
                <th class="text-center [color:#1a3a6b]">Raw Score</th>
                <th class="text-center [color:#1a3a6b]">Capped</th>
                <th class="text-center [color:#1a3a6b]">Weight</th>
                <th class="text-center [color:#1a3a6b]">Weighted</th>
            </tr></thead>
            <tbody>
            <?php
            $ck_labels  = ['Instruction'=>'KRA I - Instruction','Research'=>'KRA II - Research','Extension'=>'KRA III - Extension','Professional Development'=>'KRA IV - Prof. Development'];
            $ck_capped  = ['Instruction'=>$score_result['kra1'],'Research'=>$score_result['kra2'],'Extension'=>$score_result['kra3'],'Professional Development'=>$score_result['kra4']];
            $ck_weights = $score_result['weights'];
            foreach ($ck_labels as $key => $label):
                $raw  = $raw_kra[$key] ?? 0;
                $cap  = $ck_capped[$key];
                $w    = $ck_weights[$key];
                $wval = round($cap * $w, 2);
            ?>
            <tr>
                <td><?= $label ?></td>
                <td class="text-center"><?= number_format($raw, 2) ?></td>
                <td class="text-center"><?= number_format($cap, 2) ?></td>
                <td class="text-center"><?= ($w * 100) ?>%</td>
                <td class="text-center fw-bold"><?= number_format($wval, 2) ?></td>
            </tr>
            <?php endforeach; ?>
            </tbody>
            <tfoot>
                <tr class="[background:#eff6ff] [font-weight:700]">
                    <td colspan="4" class="[color:#1a3a6b]">Final Weighted Score</td>
                    <td class="text-center [color:#1a3a6b] [font-size:1rem]"><?= number_format($score_result['weighted_score'], 2) ?></td>
                </tr>
            </tfoot>
        </table>
    </div>
</div>

<!-- -- Auto Sub-Rank Verification -------------------------- -->
<div id="asr-verification" class="[scroll-margin-top:80px]"></div>
<?php
// -- Load AutoSubRankCalculator -------------------------------------------
if (!class_exists('\Scoring\AutoSubRankCalculator')) {
    require_once __DIR__ . '/../../../backend/services/scoring/autosubrank.php';
}
// Recalculate (in case scores changed during this review session)
$asr_checker_calc = new \Scoring\AutoSubRankCalculator($pdo, $app_id);
$asr_checker_res  = $asr_checker_calc->calculate();
$asr_checker_calc->persist($asr_checker_res);
$asr_row_ck = \Scoring\AutoSubRankCalculator::loadForApplication($pdo, $app_id) ?: [];

$ck_d_mode   = $asr_checker_res['doctorate_mode'];
$ck_a_mode   = $asr_checker_res['award_mode'];
$ck_d_color  = \Scoring\AutoSubRankCalculator::modeColor($ck_d_mode);
$ck_a_color  = \Scoring\AutoSubRankCalculator::modeColor($ck_a_mode);
$ck_d_label  = \Scoring\AutoSubRankCalculator::modeLabel($ck_d_mode);
$ck_a_label  = \Scoring\AutoSubRankCalculator::modeLabel($ck_a_mode);
$ck_d_verified = $asr_row_ck['doctorate_verified'] ?? 'pending';
$ck_a_verified = $asr_row_ck['award_verified']     ?? 'pending';
$ck_dv_color   = \Scoring\AutoSubRankCalculator::verifiedColor($ck_d_verified);
$ck_av_color   = \Scoring\AutoSubRankCalculator::verifiedColor($ck_a_verified);
$asr_status_label = static function ($status) {
    return match ($status) {
        'verified' => 'Acceptable',
        'rejected' => 'Not Acceptable',
        default => 'Pending Review',
    };
};
$ck_ri         = $asr_checker_res['total_rank_increase'];

// Can checker act? Not if app is already approved/rejected/archived
$ck_can_verify = !isAdmin()
    && in_array($app['status'], ['submitted','under_review','talisay_review','needs_revision']);
?>

<div class="neon-card mb-4 [padding:0] [overflow:hidden]">
    <!-- Header -->
    <div class="[background:linear-gradient(90deg,#1e3a6b,#1e4d8c)] [padding:0.75rem_1.25rem] [display:flex] [align-items:center] [justify-content:space-between] [flex-wrap:wrap] [gap:0.5rem]">
        <div class="[display:flex] [align-items:center] [gap:0.6rem]">
            <i class="bi bi-arrow-up-circle-fill [color:#fff] [font-size:1rem]"></i>
            <span class="[color:#fff] [font-weight:700] [font-size:0.88rem]">Auto Sub-Rank Assessment</span>
            <span class="[background:rgba(255,255,255,0.15)] [color:#fff] [font-size:0.65rem] [padding:2px_8px] [border-radius:20px] [font-weight:600]">System Calculated</span>
        </div>
        <?php if ($ck_ri > 0): ?>
        <span class="[background:#f0fdf4] [color:#16a34a] [border:1px_solid_#bbf7d0] [padding:3px_12px] [border-radius:20px] [font-size:0.72rem] [font-weight:700]">
            <i class="bi bi-arrow-up me-1"></i>+<?= $ck_ri ?> sub-rank<?= $ck_ri > 1 ? 's' : '' ?>
        </span>
        <?php else: ?>
        <span class="[background:rgba(255,255,255,0.1)] [color:rgba(255,255,255,0.6)] [border:1px_solid_rgba(255,255,255,0.2)] [padding:3px_12px] [border-radius:20px] [font-size:0.72rem] [font-weight:600]">
            No sub-rank increase
        </span>
        <?php endif; ?>
    </div>

    <div class="[padding:1rem_1.25rem]">

        <!-- Explanation callout -->
        <div class="[background:#eff6ff] [border:1px_solid_#bfdbfe] [border-radius:7px] [padding:0.6rem_0.9rem] [margin-bottom:1rem] [font-size:0.78rem] [color:#1e4d8c]">
            <i class="bi bi-info-circle me-1"></i>
            <strong>Your role:</strong> Review whether each piece of evidence is <em>authentic</em>.
            The system has already determined the optimal strategy.
            Marking evidence not acceptable will remove that criterion from the score entirely.
        </div>

        <!-- -- Criterion 1: Doctorate --------------------------------- -->
        <div class="[border:1px_solid_#e2e8f0] [border-radius:8px] [overflow:hidden] [margin-bottom:0.85rem]" id="asr-doc-panel">
            <!-- Sub-header -->
            <div class="[background:#f8fafc] [border-bottom:1px_solid_#e2e8f0] [padding:0.5rem_0.9rem] [display:flex] [align-items:center] [justify-content:space-between] [flex-wrap:wrap] [gap:0.4rem]">
                <span class="[font-size:0.72rem] [font-weight:700] [color:#475569] [text-transform:uppercase] [letter-spacing:0.05em]">
                    <i class="bi bi-mortarboard me-1"></i>Criterion 1  -  Doctorate Degree
                </span>
                <div class="[display:flex] [align-items:center] [gap:0.4rem] [flex-wrap:wrap]">
                    <!-- System decision (read-only) -->
                    <span style="background:<?= $ck_d_color ?>15;color:<?= $ck_d_color ?>;border:1px solid <?= $ck_d_color ?>40;padding:2px 8px;border-radius:20px;font-size:0.67rem;font-weight:700;">
                        <?= htmlspecialchars($ck_d_label) ?>
                    </span>
                    <!-- Current review status -->
                    <span id="asr-doc-status-badge"
                          style="background:<?= $ck_dv_color ?>12;color:<?= $ck_dv_color ?>;border:1px solid <?= $ck_dv_color ?>40;padding:2px 8px;border-radius:20px;font-size:0.67rem;font-weight:700;">
                        <i class="bi bi-shield me-1"></i><?= $asr_status_label($ck_d_verified) ?>
                    </span>
                </div>
            </div>

            <div class="[padding:0.8rem_0.9rem]">
                <?php if ($asr_checker_res['has_doctorate']): ?>

                <!-- Evidence info -->
                <div class="[display:flex] [gap:0.85rem] [flex-wrap:wrap] [margin-bottom:0.75rem]">
                    <div class="[flex:1] [min-width:160px]">
                        <div class="[font-size:0.68rem] [color:#94a3b8] [font-weight:600] [text-transform:uppercase] [margin-bottom:0.2rem]">Doctorate</div>
                        <div class="[font-size:0.82rem] [color:#1e293b]"><?= htmlspecialchars($asr_checker_res['doctorate_details'] ?: 'Doctorate (Professional Development)') ?></div>
                    </div>
                    <div class="[flex:1] [min-width:200px]">
                        <div class="[font-size:0.68rem] [color:#94a3b8] [font-weight:600] [text-transform:uppercase] [margin-bottom:0.2rem]">System Reasoning</div>
                        <div class="[font-size:0.78rem] [color:#475569] [line-height:1.5]"><?= htmlspecialchars($asr_checker_res['doctorate_reason']) ?></div>
                    </div>
                </div>

                <?php if (!empty($asr_row_ck['doctorate_verification_notes']) && $ck_d_verified !== 'pending'): ?>
                <div style="background:<?= $ck_dv_color ?>0d;border:1px solid <?= $ck_dv_color ?>30;border-radius:6px;padding:0.45rem 0.7rem;font-size:0.75rem;color:<?= $ck_dv_color ?>;margin-bottom:0.7rem;">
                    <i class="bi bi-chat-left-text me-1"></i><strong>Review note:</strong> <?= htmlspecialchars($asr_row_ck['doctorate_verification_notes']) ?>
                </div>
                <?php endif; ?>

                <?php if ($ck_can_verify): ?>
                <!-- Accept / Reject form -->
                <form id="asr-doc-form" method="POST"
                      class="[background:#f8fafc] [border:1px_solid_#e2e8f0] [border-radius:7px] [padding:0.7rem_0.85rem]">
                    <input type="hidden" name="action"     value="verify_asr_evidence">
                    <input type="hidden" name="criterion"  value="doctorate">
                    <div class="[font-size:0.72rem] [font-weight:700] [color:#475569] [text-transform:uppercase] [letter-spacing:0.05em] [margin-bottom:0.5rem]">
                        <i class="bi bi-shield-check me-1"></i>Your Review
                    </div>
                    <div class="[display:flex] [gap:0.5rem] [flex-wrap:wrap] [margin-bottom:0.5rem]">
                        <button type="button"
                                onclick="submitAsrVerify('asr-doc-form','verified','asr-doc-status-badge','#16a34a','Acceptable')"
                                class="btn btn-outline-success [flex:1] [min-width:100px] [font-size:0.78rem]"
                                <?= $ck_d_verified === 'verified' ? 'disabled' : '' ?>>
                            <i class="bi bi-check-circle-fill"></i>Acceptable - Evidence is Authentic
                        </button>
                        <button type="button"
                                onclick="submitAsrVerify('asr-doc-form','rejected','asr-doc-status-badge','#dc2626','Not Acceptable')"
                                class="btn btn-outline-danger [flex:1] [min-width:100px] [font-size:0.78rem]"
                                <?= $ck_d_verified === 'rejected' ? 'disabled' : '' ?>>
                            <i class="bi bi-x-circle-fill"></i>Not Acceptable - Evidence Invalid
                        </button>
                    </div>
                    <input type="hidden" name="verification" id="asr-doc-decision" value="">
                    <textarea name="verify_notes" id="asr-doc-notes"
                              placeholder="Optional: note your reason (required when marking not acceptable)"
                              class="form-control form-control-sm [font-size:0.78rem] [min-height:52px]"
                              ><?= htmlspecialchars($asr_row_ck['doctorate_verification_notes'] ?? '') ?></textarea>
                </form>
                <?php else: ?>
                <div class="[font-size:0.75rem] [color:#94a3b8] [font-style:italic]">
                    <i class="bi bi-lock me-1"></i>Review locked - application is not under active review.
                </div>
                <?php endif; ?>

                <?php else: ?>
                <div class="[font-size:0.82rem] [color:#64748b] [display:flex] [align-items:center] [gap:0.5rem]">
                    <i class="bi bi-dash-circle [color:#94a3b8]"></i>
                    No doctorate found in this application's Professional Development entries. Nothing to review.
                </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- -- Criterion 2: Award ------------------------------------- -->
        <div class="[border:1px_solid_#e2e8f0] [border-radius:8px] [overflow:hidden]" id="asr-awd-panel">
            <div class="[background:#f8fafc] [border-bottom:1px_solid_#e2e8f0] [padding:0.5rem_0.9rem] [display:flex] [align-items:center] [justify-content:space-between] [flex-wrap:wrap] [gap:0.4rem]">
                <span class="[font-size:0.72rem] [font-weight:700] [color:#475569] [text-transform:uppercase] [letter-spacing:0.05em]">
                    <i class="bi bi-trophy me-1"></i>Criterion 2  -  National / International Award
                </span>
                <div class="[display:flex] [align-items:center] [gap:0.4rem] [flex-wrap:wrap]">
                    <span style="background:<?= $ck_a_color ?>15;color:<?= $ck_a_color ?>;border:1px solid <?= $ck_a_color ?>40;padding:2px 8px;border-radius:20px;font-size:0.67rem;font-weight:700;">
                        <?= htmlspecialchars($ck_a_label) ?>
                    </span>
                    <?php if ($asr_checker_res['has_award']): ?>
                    <span id="asr-awd-status-badge"
                          style="background:<?= $ck_av_color ?>12;color:<?= $ck_av_color ?>;border:1px solid <?= $ck_av_color ?>40;padding:2px 8px;border-radius:20px;font-size:0.67rem;font-weight:700;">
                        <i class="bi bi-shield me-1"></i><?= $asr_status_label($ck_a_verified) ?>
                    </span>
                    <?php endif; ?>
                </div>
            </div>

            <div class="[padding:0.8rem_0.9rem]">
                <?php if ($asr_checker_res['has_award']): ?>

                <div class="[display:flex] [gap:0.85rem] [flex-wrap:wrap] [margin-bottom:0.75rem]">
                    <div class="[flex:1] [min-width:160px]">
                        <div class="[font-size:0.68rem] [color:#94a3b8] [font-weight:600] [text-transform:uppercase] [margin-bottom:0.2rem]">Award</div>
                        <div class="[font-size:0.82rem] [color:#1e293b]"><?= htmlspecialchars($asr_checker_res['award_details'] ?: 'National/International Award') ?></div>
                    </div>
                    <div class="[flex:1] [min-width:200px]">
                        <div class="[font-size:0.68rem] [color:#94a3b8] [font-weight:600] [text-transform:uppercase] [margin-bottom:0.2rem]">System Reasoning</div>
                        <div class="[font-size:0.78rem] [color:#475569] [line-height:1.5]"><?= htmlspecialchars($asr_checker_res['award_reason']) ?></div>
                    </div>
                    <?php if (!empty($asr_row_ck['award_evidence'])): ?>
                    <div class="[width:100%]">
                        <a href="../<?= htmlspecialchars($asr_row_ck['award_evidence']) ?>" target="_blank"
                           class="[font-size:0.78rem] [color:#1e4d8c] [text-decoration:none] [display:inline-flex] [align-items:center] [gap:0.3rem]">
                           <i class="bi bi-file-earmark-pdf"></i>View submitted evidence
                        </a>
                    </div>
                    <?php endif; ?>
                </div>

                <?php if (!empty($asr_row_ck['award_verification_notes']) && $ck_a_verified !== 'pending'): ?>
                <div style="background:<?= $ck_av_color ?>0d;border:1px solid <?= $ck_av_color ?>30;border-radius:6px;padding:0.45rem 0.7rem;font-size:0.75rem;color:<?= $ck_av_color ?>;margin-bottom:0.7rem;">
                    <i class="bi bi-chat-left-text me-1"></i><strong>Review note:</strong> <?= htmlspecialchars($asr_row_ck['award_verification_notes']) ?>
                </div>
                <?php endif; ?>

                <?php if ($ck_can_verify): ?>
                <form id="asr-awd-form" method="POST"
                      class="[background:#f8fafc] [border:1px_solid_#e2e8f0] [border-radius:7px] [padding:0.7rem_0.85rem]">
                    <input type="hidden" name="action"     value="verify_asr_evidence">
                    <input type="hidden" name="criterion"  value="award">
                    <div class="[font-size:0.72rem] [font-weight:700] [color:#475569] [text-transform:uppercase] [letter-spacing:0.05em] [margin-bottom:0.5rem]">
                        <i class="bi bi-shield-check me-1"></i>Your Review
                    </div>
                    <div class="[display:flex] [gap:0.5rem] [flex-wrap:wrap] [margin-bottom:0.5rem]">
                        <button type="button"
                                onclick="submitAsrVerify('asr-awd-form','verified','asr-awd-status-badge','#16a34a','Acceptable')"
                                class="btn btn-outline-success [flex:1] [min-width:100px] [font-size:0.78rem]"
                                <?= $ck_a_verified === 'verified' ? 'disabled' : '' ?>>
                            <i class="bi bi-check-circle-fill"></i>Acceptable - Evidence is Authentic
                        </button>
                        <button type="button"
                                onclick="submitAsrVerify('asr-awd-form','rejected','asr-awd-status-badge','#dc2626','Not Acceptable')"
                                class="btn btn-outline-danger [flex:1] [min-width:100px] [font-size:0.78rem]"
                                <?= $ck_a_verified === 'rejected' ? 'disabled' : '' ?>>
                            <i class="bi bi-x-circle-fill"></i>Not Acceptable - Evidence Invalid
                        </button>
                    </div>
                    <input type="hidden" name="verification" id="asr-awd-decision" value="">
                    <textarea name="verify_notes" id="asr-awd-notes"
                              placeholder="Optional: note your reason (required when marking not acceptable)"
                              class="form-control form-control-sm [font-size:0.78rem] [min-height:52px]"
                              ><?= htmlspecialchars($asr_row_ck['award_verification_notes'] ?? '') ?></textarea>
                </form>
                <?php else: ?>
                <div class="[font-size:0.75rem] [color:#94a3b8] [font-style:italic]">
                    <i class="bi bi-lock me-1"></i>Review locked - application is not under active review.
                </div>
                <?php endif; ?>

                <?php else: ?>
                <div class="[font-size:0.82rem] [color:#64748b] [display:flex] [align-items:center] [gap:0.5rem]">
                    <i class="bi bi-dash-circle [color:#94a3b8]"></i>
                    No national/international award found in this application. Nothing to review.
                </div>
                <?php endif; ?>
            </div>
        </div>

    </div><!-- /card body -->
</div><!-- /asr neon-card -->

<script>
/**
 * submitAsrVerify  -  submit an accept/reject form via AJAX,
 * then update the status badge inline without full page reload.
 */
function submitAsrVerify(formId, decision, badgeId, color, label) {
    const form  = document.getElementById(formId);
    const badge = document.getElementById(badgeId);
    if (!form) return;

    // Inject the decision into the hidden input
    const decInput = form.querySelector('input[name="verification"]');
    if (decInput) decInput.value = decision;

    // Require a note when rejecting
    const notesEl = form.querySelector('textarea[name="verify_notes"]');
    if (decision === 'rejected' && notesEl && notesEl.value.trim() === '') {
        notesEl.style.borderColor = '#dc2626';
        notesEl.focus();
        notesEl.placeholder = 'Reason is required.';
        return;
    }
    if (notesEl) notesEl.style.borderColor = '#e2e8f0';

    const fd = new FormData(form);
    fetch(window.location.href, { method: 'POST', body: fd,
        headers: { 'X-Requested-With': 'XMLHttpRequest' } })
    .then(r => r.json())
    .then(data => {
        if (data.ok && badge) {
            badge.style.background  = color + '12';
            badge.style.color       = color;
            badge.style.borderColor = color + '40';
            badge.innerHTML = '<i class="bi bi-shield me-1"></i>' + label;
        }
        // Disable both buttons in this form
        form.querySelectorAll('button[type="button"]').forEach(b => { b.disabled = true; b.style.opacity = '0.55'; });
    })
    .catch(() => { form.submit(); });
}
</script>

<!-- -- KRA Submissions --------------------------------------- -->
<div id="kra-verification" class="[scroll-margin-top:80px]"></div>
<?php if (!empty($_GET['score_saved'])): ?>
<?php showFlash(); ?>
<?php endif; ?>
<div class="neon-card mb-4">
    <h6 class="[color:#0f172a] [font-weight:700] [font-size:0.88rem] [margin-bottom:1rem]">
        <i class="bi bi-list-check me-2 [color:#1a3a6b]"></i>KRA Score Review
    </h6>
    <?php if ($subs): ?>
    <?php
    // Per-checker verification state
    $my_verified_sids = [];
    if ($my_checker_id) {
        $my_v = $pdo->prepare("SELECT submission_id FROM kra_checker_verifications WHERE checker_id=?");
        $my_v->execute([$my_checker_id]);
        $my_verified_sids = array_column($my_v->fetchAll(PDO::FETCH_ASSOC), 'submission_id');
    }
    $total_active_checkers = $total_checkers;
    $verify_counts = [];
    if ($subs) {
        $sub_ids_v = array_column($subs, 'submission_id');
        $ph_v = implode(',', array_fill(0, count($sub_ids_v), '?'));
        $vc = $pdo->prepare("SELECT submission_id, COUNT(*) as cnt FROM kra_checker_verifications WHERE submission_id IN ($ph_v) GROUP BY submission_id");
        $vc->execute($sub_ids_v);
        foreach ($vc->fetchAll() as $vrow) $verify_counts[$vrow['submission_id']] = (int)$vrow['cnt'];
    }
    // Pre-compute KRA caps and weights for weighted column
    $kra_caps_rev    = ['Instruction'=>100,'Research'=>100,'Extension'=>100,'Professional Development'=>100];
    $kra_weights_rev = $score_result['weights'];
    // Sum raw per category for proportional distribution
    $kra_raw_totals = [];
    foreach ($subs as $s) {
        $kra_raw_totals[$s['kra_category']] = ($kra_raw_totals[$s['kra_category']] ?? 0) + (float)$s['computed_points'];
    }
    ?>
    <div class="table-responsive">
        <table class="table table-modern table-hover mb-0 [font-size:0.82rem]">
            <thead>
            <tr class="[background:#f8fafc] [border-bottom:2px_solid_#e2e8f0]">
                <th class="[padding:0.6rem_0.85rem] [text-align:left] [font-size:0.65rem] [font-weight:700] [text-transform:uppercase] [letter-spacing:0.06em] [color:#64748b] [white-space:nowrap]">KRA Category</th>
                <th class="[padding:0.6rem_0.75rem] [text-align:right] [font-size:0.65rem] [font-weight:700] [text-transform:uppercase] [letter-spacing:0.06em] [color:#64748b] [white-space:nowrap]">Raw Score</th>
                <th class="[padding:0.6rem_0.75rem] [text-align:right] [font-size:0.65rem] [font-weight:700] [text-transform:uppercase] [letter-spacing:0.06em] [color:#64748b] [white-space:nowrap]">Weighted</th>
                <th class="[padding:0.6rem_0.75rem] [text-align:left] [font-size:0.65rem] [font-weight:700] [text-transform:uppercase] [letter-spacing:0.06em] [color:#64748b]">Remarks</th>
                <th class="[padding:0.6rem_0.75rem] [text-align:left] [font-size:0.65rem] [font-weight:700] [text-transform:uppercase] [letter-spacing:0.06em] [color:#64748b]">Evidence</th>
                <?php if ($can_act_revision): ?>
                <th class="[padding:0.6rem_0.75rem] [text-align:center] [font-size:0.65rem] [font-weight:700] [text-transform:uppercase] [letter-spacing:0.06em] [color:#64748b] [min-width:120px]">Action</th>
                <?php endif; ?>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($subs as $s):
                $cat         = $s['kra_category'];
                $raw_pts     = (float)$s['computed_points'];
                $cap         = $kra_caps_rev[$cat] ?? 100;
                $weight      = $kra_weights_rev[$cat] ?? 0;
                $cat_raw     = $kra_raw_totals[$cat] ?? 0;
                $cat_capped  = min($cap, $cat_raw);
                $entry_share = $cat_raw > 0 ? ($raw_pts / $cat_raw) : 0;
                $weighted_val = round($entry_share * $cat_capped * $weight, 2);
                $needs_rev   = ($s['revision_status'] ?? 'ok') === 'needs_revision';
                $can_review_this_kra = checkerCanReviewKra($pdo, (int)$my_checker_id, (int)$app['cycle_id'], $cat);
                $assigned_for_this_kra = max(1, countAssignedCheckersForKra($pdo, (int)$app['cycle_id'], $cat));
                $i_verified_this = in_array((int)$s['submission_id'], array_map('intval', $my_verified_sids));
                $all_verified    = ($verify_counts[$s['submission_id']] ?? 0) >= $assigned_for_this_kra;
            ?>
            <tr style="border-bottom:1px solid #f1f5f9;<?= $needs_rev ? 'background:#fffbf0;border-left:3px solid #d97706;' : '' ?>"
                onmouseover="this.style.background='<?= $needs_rev ? '#fff8e6' : '#fafafa' ?>'"
                onmouseout="this.style.background='<?= $needs_rev ? '#fffbf0' : '' ?>'">

                <!-- KRA Category -->
                <td class="[padding:0.7rem_0.85rem] [font-weight:600] [color:#1e293b] [white-space:nowrap]">
                    <?= sanitize($s['kra_category']) ?>
                    <?php if (!$can_review_this_kra): ?>
                    <div class="[margin-top:0.25rem]">
                        <span class="[background:#f8fafc] [color:#94a3b8] [border:1px_solid_#e2e8f0] [border-radius:20px] [padding:1px_7px] [font-size:0.65rem] [font-weight:700]">
                            <i class="bi bi-lock-fill me-1"></i>Locked
                        </span>
                    </div>
                    <?php endif; ?>
                </td>

                <!-- Raw Score -->
                <td class="[padding:0.7rem_0.75rem] [text-align:right] [white-space:nowrap]">
                    <span class="[font-size:0.88rem] [font-weight:700] [color:#0f172a]"><?= number_format($raw_pts, 2) ?></span>
                    <?php if (($can_act_revision || isAdmin()) && $can_review_this_kra && $app['status'] !== 'submitted'): ?>
                    <button type="button"
                            onclick="openScoreModal(<?= $s['submission_id'] ?>,'<?= addslashes(sanitize($s['kra_category'])) ?>',<?= $raw_pts ?>)"
                            title="Edit score"
                            class="[background:none] [border:none] [padding:2px_4px] [cursor:pointer] [margin-left:2px]" style="color:#94a3b8;"
                            onmouseover="this.style.color='#1a3a6b'" onmouseout="this.style.color='#94a3b8'">
                        <i class="bi bi-pencil [font-size:0.7rem]"></i>
                    </button>
                    <?php endif; ?>
                </td>

                <!-- Weighted Score -->
                <td class="[padding:0.7rem_0.75rem] [text-align:right] [white-space:nowrap]">
                    <span class="[font-size:0.88rem] [font-weight:700] [color:#1a3a6b]"><?= number_format($weighted_val, 2) ?></span>
                    <div class="[font-size:0.62rem] [color:#94a3b8] [margin-top:1px]"><?= ($weight * 100) ?>% wt</div>
                </td>

                <!-- Remarks -->
                <td class="[padding:0.7rem_0.75rem] [color:#475569] [max-width:260px]">
                    <?= formatKraRemarks($s['kra_category'], $s['remarks'] ?? '') ?>
                    <?php if ($needs_rev): ?>
                    <div class="[margin-top:0.35rem] [padding:0.35rem_0.6rem] [background:#fef3c7] [border:1px_solid_#fde68a] [border-radius:5px] [display:flex] [align-items:flex-start] [gap:0.4rem]">
                        <i class="bi bi-exclamation-triangle-fill [color:#d97706] [font-size:0.75rem] [margin-top:1px] [flex-shrink:0]"></i>
                        <span class="[font-size:0.72rem] [font-weight:600] [color:#92400e]"><?= sanitize($s['revision_note'] ?? '') ?></span>
                    </div>
                    <?php endif; ?>
                </td>

                <!-- Evidence -->
                <td class="[padding:0.7rem_0.75rem]">
                    <!-- Acceptable badge  -  per-checker -->
                    <?php if ($all_verified): ?>
                    <div data-verification-badge="<?= (int)$s['submission_id'] ?>"
                         class="[display:inline-flex] [align-items:center] [gap:3px] [margin-bottom:0.3rem] [font-size:0.65rem] [border-radius:20px] [padding:1px_7px]"
                         style="font-weight:700;color:#16a34a;background:#f0fdf4;border:1px solid #bbf7d0;">
                        <i class="bi bi-patch-check-fill [font-size:0.62rem]"></i>Acceptable
                    </div>
                    <?php elseif ($i_verified_this): ?>
                    <div data-verification-badge="<?= (int)$s['submission_id'] ?>"
                         class="[display:inline-flex] [align-items:center] [gap:3px] [margin-bottom:0.3rem] [font-size:0.65rem] [border-radius:20px] [padding:1px_7px]"
                         style="font-weight:700;color:#1e4d8c;background:#eff6ff;border:1px solid #bfdbfe;"
                         title="You marked this acceptable. Waiting for other evaluators.">
                        <i class="bi bi-patch-check-fill [font-size:0.62rem]"></i>You marked acceptable
                        <span class="[font-size:0.6rem] [color:#64748b]">(<?= $verify_counts[$s['submission_id']] ?? 0 ?>/<?= $assigned_for_this_kra ?>)</span>
                    </div>
                    <?php elseif ($can_act_revision && $can_review_this_kra): ?>
                    <div data-verification-badge="<?= (int)$s['submission_id'] ?>"
                         class="[display:inline-flex] [align-items:center] [gap:3px] [margin-bottom:0.3rem] [font-size:0.65rem] [border-radius:20px] [padding:1px_7px]"
                         style="font-weight:600;color:#94a3b8;background:#f8fafc;border:1px solid #e2e8f0;">
                        <i class="bi bi-circle [font-size:0.55rem]"></i>Not Acceptable
                        <?php if (($verify_counts[$s['submission_id']] ?? 0) > 0): ?>
                        <span class="[font-size:0.6rem] [color:#64748b]">(<?= $verify_counts[$s['submission_id']] ?>/<?= $assigned_for_this_kra ?>)</span>
                        <?php endif; ?>
                    </div>
                    <?php endif; ?>
                    <?php if (!empty($s['evidence_files'])): ?>
                    <div class="[display:flex] [flex-wrap:wrap] [gap:0.3rem]">
                        <?php foreach ($s['evidence_files'] as $fi): ?>
                        <a href="backend/api/view_file.php?file=<?= urlencode($fi['file_path']) ?>" target="_blank" rel="noopener noreferrer"
                           title="<?= htmlspecialchars($fi['original_filename']) ?>"
                           class="[display:inline-flex] [align-items:center] [gap:0.25rem] [padding:2px_8px] [border-radius:4px] [background:#f8fafc] [font-size:0.68rem] [text-decoration:none] [white-space:nowrap] [max-width:110px] [overflow:hidden] [text-overflow:ellipsis]" style="border:1px solid #e2e8f0;color:#475569;"
                           onmouseover="this.style.borderColor='#1a3a6b';this.style.color='#1a3a6b'"
                           onmouseout="this.style.borderColor='#e2e8f0';this.style.color='#475569'">
                            <i class="bi bi-file-earmark [flex-shrink:0]"></i>
                            <?= htmlspecialchars(substr($fi['original_filename'], 0, 12) . (strlen($fi['original_filename']) > 12 ? '...' : '')) ?>
                        </a>
                        <?php endforeach; ?>
                    </div>
                    <?php elseif ($s['document_path']): ?>
                    <a href="backend/api/view_file.php?file=<?= urlencode($s['document_path']) ?>" target="_blank"
                       class="[display:inline-flex] [align-items:center] [gap:0.25rem] [padding:2px_8px] [border-radius:4px] [border:1px_solid_#e2e8f0] [background:#f8fafc] [font-size:0.68rem] [color:#475569] [text-decoration:none]">
                        <i class="bi bi-file-earmark"></i>View
                    </a>
                    <?php else: ?>
                    <span class="[font-size:0.72rem] [color:#cbd5e1]"> - </span>
                    <?php endif; ?>
                </td>

                <!-- Action -->
                <?php
                $show_not_started = !isAdmin() && !$i_decided && !$i_started_review
                                    && in_array($app['status'], ['submitted','under_review']);
                ?>
                <?php if ($can_act_revision): ?>
                <td class="[padding:0.7rem_0.75rem] [text-align:center] [vertical-align:middle]">
                    <?php if (!$can_review_this_kra): ?>
                    <span class="[font-size:0.65rem] [color:#94a3b8] [display:inline-flex] [align-items:center] [gap:3px]" title="This KRA is assigned to another evaluator.">
                        <i class="bi bi-lock [font-size:0.6rem]"></i>Read-only
                    </span>
                    <?php else: ?>
                    <div class="[display:flex] [flex-direction:column] [gap:0.3rem] [align-items:center]">
                    <?php if ($needs_rev): ?>
                        <?php if ($app['status'] === 'under_review'): ?>
                        <form method="POST" action="index.php?page=<?= $return_page ?>&id=<?= $app_id ?>" id="clearRevForm_<?= $s['submission_id'] ?>">
                            <input type="hidden" name="action" value="clear_revision">
                            <input type="hidden" name="submission_id" value="<?= $s['submission_id'] ?>">
                            <button type="button"
                                    onclick="confirmDelete('Mark this entry as OK and clear the revision flag?','clearRevForm_<?= $s['submission_id'] ?>','Clear Flag','bi-check-circle')"
                                    class="[display:inline-flex] [align-items:center] [gap:0.3rem] [padding:0.28rem_0.7rem] [border-radius:5px] [border:1px_solid_#16a34a] [background:#f0fdf4] [color:#16a34a] [font-size:0.71rem] [font-weight:600] [cursor:pointer] [white-space:nowrap]">
                                <i class="bi bi-check-circle"></i>Clear Flag
                            </button>
                        </form>
                        <?php else: ?>
                        <span class="[font-size:0.65rem] [color:#cbd5e1] [display:inline-flex] [align-items:center] [gap:3px]"
                              title="Waiting for faculty to resubmit">
                            <i class="bi bi-hourglass-split [font-size:0.6rem]"></i>Awaiting resubmit
                        </span>
                        <?php endif; ?>
                    <?php else: ?>
                        <?php if (!$i_verified_this): ?>
                        <textarea id="verifyNote_<?= $s['submission_id'] ?>"
                                  placeholder="Optional note..."
                                  rows="1"
                                  class="[width:120px] [resize:vertical] [border:1px_solid_#e2e8f0] [border-radius:5px] [padding:0.25rem_0.4rem] [font-size:0.68rem] [color:#334155] [font-family:inherit]"></textarea>
                        <button type="button"
                                onclick="quickVerify(<?= $s['submission_id'] ?>, this)"
                                id="verifyBtn_<?= $s['submission_id'] ?>"
                                title="Mark as acceptable by you"
                                class="[display:inline-flex] [align-items:center] [gap:0.3rem] [padding:0.28rem_0.7rem] [border-radius:5px] [border:1px_solid_#1e4d8c] [font-size:0.71rem] [font-weight:600] [cursor:pointer] [white-space:nowrap]" style="background:#eff6ff;color:#1e4d8c;"
                                onmouseover="this.style.background='#1e4d8c';this.style.color='#fff'"
                                onmouseout="this.style.background='#eff6ff';this.style.color='#1e4d8c'">
                            <i class="bi bi-patch-check"></i>Mark as Acceptable
                        </button>
                        <?php else: ?>
                        <span class="[font-size:0.68rem] [color:#1e4d8c] [font-weight:600] [display:inline-flex] [align-items:center] [gap:3px]">
                            <i class="bi bi-patch-check-fill"></i>You marked acceptable
                        </span>
                        <?php endif; ?>
                        <button type="button"
                                onclick="openRevisionModal(<?= $s['submission_id'] ?>,'<?= addslashes(sanitize($s['kra_category'])) ?>')"
                                class="[display:inline-flex] [align-items:center] [gap:0.3rem] [padding:0.28rem_0.7rem] [border-radius:5px] [font-size:0.71rem] [font-weight:600] [cursor:pointer] [white-space:nowrap]" style="background:#f8fafc;color:#475569;border:1px solid #e2e8f0;"
                                onmouseover="this.style.borderColor='#d97706';this.style.color='#d97706';this.style.background='#fffbeb'"
                                onmouseout="this.style.borderColor='#e2e8f0';this.style.color='#475569';this.style.background='#f8fafc'">
                            <i class="bi bi-flag"></i>Flag
                        </button>
                    <?php endif; ?>
                    </div>
                    <?php endif; ?>
                </td>
                <?php elseif ($show_not_started): ?>
                <td class="[padding:0.7rem_0.75rem] [text-align:center] [vertical-align:middle]">
                    <div>
                    <span class="[font-size:0.65rem] [color:#cbd5e1] [display:inline-flex] [align-items:center] [gap:3px]" title="Start the review to enable accept and flag actions">
                        <i class="bi bi-lock [font-size:0.6rem]"></i>Start review first
                    </span>
                    </div>
                </td>
                <?php endif; ?>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php else: ?>
    <p class="text-muted small">No KRA submissions found.</p>
    <?php endif; ?>

<!-- -- Checker Decision Panel ------------------------------- -->
<?php if (!isAdmin()): ?>

<?php if ($i_approved): ?>
<!-- Evaluation complete confirmation -->
<div class="neon-card mb-4 [border-left:4px_solid_#1a3a6b]">
    <div class="d-flex align-items-center gap-4">
        <div class="[width:40px] [height:40px] [border-radius:50%] [background:#eff6ff] [display:flex] [align-items:center] [justify-content:center] [flex-shrink:0]">
            <i class="bi bi-check-circle-fill [color:#1a3a6b] [font-size:1.1rem]"></i>
        </div>
        <div>
            <div class="fw-semibold [color:#1a3a6b]">You have completed this evaluation</div>
            <div class="text-muted small">
                <?php if ($approved_count >= $total_checkers): ?>
                All <?= $total_checkers ?> evaluator(s) have completed their work. The evaluation is now <strong>complete</strong>.
                <?php else: ?>
                Waiting for <?= $total_checkers - $slots_taken ?> more evaluator(s) to finish.
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<?php elseif ($i_rejected): ?>
<!-- Returned for revision confirmation -->
<div class="neon-card mb-4 [border-left:4px_solid_#334155]">
    <div class="d-flex align-items-center gap-4">
        <div class="[width:40px] [height:40px] [border-radius:50%] [background:#f8fafc] [display:flex] [align-items:center] [justify-content:center] [flex-shrink:0]">
            <i class="bi bi-arrow-counterclockwise [color:#334155] [font-size:1.1rem]"></i>
        </div>
        <div>
            <div class="fw-semibold [color:#1a3a6b]">You returned this application for revision</div>
            <div class="text-muted small">
                <?php if ($rejected_count >= $total_checkers): ?>
                All <?= $total_checkers ?> evaluator(s) returned it for revision. The application has been <strong>returned to the faculty</strong>.
                <?php else: ?>
                <?= $rejected_count ?> return note(s) recorded. The application stays <strong>Under Evaluation</strong> until all <?= $total_checkers ?> evaluator(s) have finished.
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<?php elseif ($can_act_checker && $app['status'] === 'submitted'): ?>
<!-- Start Review panel -->
<div class="neon-card mb-4 [border-left:4px_solid_#1e4d8c]">
    <div class="[display:flex] [align-items:center] [gap:1rem] [flex-wrap:wrap]">
        <div class="[flex:1] [min-width:0]">
            <div class="fw-semibold mb-1 [color:#1a3a6b] [font-size:0.92rem]">
                <i class="bi bi-play-circle me-2"></i>Ready to Review?
            </div>
            <div class="text-muted small">
                Click <strong>Start Review</strong> to begin evaluating this application.
                Once started, you can edit KRA scores and request revisions.
            </div>
        </div>
        <form method="POST" action="index.php?page=<?= $return_page ?>&id=<?= $app_id ?>" id="startReviewForm">
            <input type="hidden" name="action" value="start_review">
            <button type="button" class="btn btn-primary"
                    onclick="confirmDelete('Start reviewing this application? The faculty will be notified.','startReviewForm','Start Review','bi-play-circle')">
                <i class="bi bi-play-circle me-2"></i>Start Review
            </button>
        </form>
    </div>
</div>

<?php elseif ($can_act_checker && $app['status'] !== 'submitted'): ?>
<!-- Regular checker decision panel -->
<div class="neon-card mb-4">
    <h6 class="mb-4 [color:var(--blue-dark)]"><i class="bi bi-pencil-square me-2"></i>Your Evaluation Action</h6>
    <div class="alert alert-info py-2 small mb-4">
        <i class="bi bi-info-circle me-1"></i>
        <strong>Complete Evaluation</strong> once all KRA entries are checked and acceptable.<br>
        <strong>Return for Revision</strong> when the faculty needs to correct the submission.
        The result is a recommendation for further committee review and is not a final decision.
    </div>
    <form method="POST" action="index.php?page=<?= $return_page ?>&id=<?= $app_id ?>" id="checkerDecisionForm">
        <input type="hidden" name="action" id="checkerDecisionAction" value="">
        <div class="mb-4">
            <label class="form-label fw-semibold">
                Remarks
                <small class="text-muted">(optional for completion, <span class="text-danger">required when returning for revision</span>)</small>
            </label>
            <textarea name="checker_remarks" id="checkerRemarksField" class="form-control" rows="2"
                      placeholder="Enter your remarks..."></textarea>
        </div>
        <div class="d-flex gap-2 flex-wrap">
            <?php if ($score_result['weighted_score'] < 41): ?>
            <button type="button" class="btn btn-success [opacity:0.5] [cursor:not-allowed]" disabled
                    title="Cannot complete evaluation - weighted score (<?= number_format($score_result['weighted_score'], 2) ?>) is below the minimum of 41."
                   >
                <i class="bi bi-check-circle me-2"></i>Complete Evaluation
            </button>
            <?php else: ?>
            <button type="button" class="btn btn-success"
                    onclick="submitCheckerDecision('checker_approve','Mark this evaluation complete?','Complete Evaluation','bi-check-circle')">
                <i class="bi bi-check-circle me-2"></i>Complete Evaluation
            </button>
            <?php endif; ?>
            <button type="button" class="btn btn-danger"
                    onclick="submitCheckerDecision('checker_reject','Return this application for revision?','Return for Revision','bi-arrow-counterclockwise')">
                <i class="bi bi-arrow-counterclockwise me-2"></i>Return for Revision
            </button>
        </div>
        <p class="text-muted small mt-2 mb-0">
            <i class="bi bi-info-circle me-1"></i>
            Use "Request Revision" on individual KRA entries above to flag specific items for the faculty to fix.
        </p>
    </form>
</div>
<script>
function submitCheckerDecision(action, msg, btnLabel, btnIcon) {
    const remarks = document.getElementById('checkerRemarksField').value.trim();
    if (action === 'checker_reject' && !remarks) {
        document.getElementById('checkerRemarksField').style.borderColor = '#334155';
        document.getElementById('checkerRemarksField').focus();
        alert('A reason is required when returning for revision.');
        return;
    }
    document.getElementById('checkerRemarksField').style.borderColor = '';
    document.getElementById('checkerDecisionAction').value = action;
    confirmDelete(msg, 'checkerDecisionForm', btnLabel, btnIcon);
}
</script>

<?php elseif ($app['status'] === 'talisay_review' && isEvaluator()): ?>
<!-- ITC evaluator decision panel -->
<?php
$my_talisay_review = null;
foreach ($checker_reviews as $cr) {
    if ((int)$cr['checker_id'] === $my_checker_id) { $my_talisay_review = $cr; break; }
}
$talisay_decided = $my_talisay_review && in_array($my_talisay_review['decision'], ['approved','rejected']);
?>
<?php if ($talisay_decided): ?>
<div class="neon-card mb-4" style="border-left:4px solid <?= $my_talisay_review['decision']==='approved'?'#1e4d8c':'#1e293b' ?>;">
    <div class="d-flex align-items-center gap-4">
        <i class="bi bi-<?= $my_talisay_review['decision']==='approved'?'check-circle-fill':'x-circle-fill' ?>"
           style="font-size:1.4rem;color:<?= $my_talisay_review['decision']==='approved'?'#1e4d8c':'#1e293b' ?>;"></i>
        <div>
            <div class="fw-semibold [color:#1a3a6b]">
                You have <?= $my_talisay_review['decision']==='approved'?'completed the evaluation for':'returned for revision' ?> this application.
            </div>
            <?php if ($my_talisay_review['remarks']): ?>
            <div class="text-muted small">Remarks: <?= sanitize($my_talisay_review['remarks']) ?></div>
            <?php endif; ?>
        </div>
    </div>
</div>
<?php else: ?>
<div class="neon-card mb-4 [border-left:4px_solid_#475569]">
    <h6 class="mb-4 [color:var(--blue-dark)]">
        <i class="bi bi-building me-2"></i>Evaluation Action
    </h6>
    <div class="alert py-2 small mb-4 [background:#f0f7ff] [border:1px_solid_#bfdbfe] [color:#1e4d8c]">
        <i class="bi bi-info-circle me-1"></i>
        This application is under evaluation. Results recorded here are recommendations for further committee review and are not final decisions.
    </div>
    <form method="POST" action="index.php?page=<?= $return_page ?>&id=<?= $app_id ?>" id="talisayDecisionForm">
        <input type="hidden" name="action" id="talisayDecisionAction" value="">
        <div class="mb-4">
            <label class="form-label fw-semibold">
                Remarks
                <small class="text-muted">(<span class="text-danger">required when returning for revision</span>)</small>
            </label>
            <textarea name="checker_remarks" id="talisayRemarksField" class="form-control" rows="2"
                      placeholder="Enter your remarks..."></textarea>
        </div>
        <div class="d-flex gap-2 flex-wrap">
            <?php if ($score_result['weighted_score'] < 41): ?>
            <button type="button" class="btn btn-success [opacity:0.5] [cursor:not-allowed]" disabled
                    title="Cannot complete evaluation - weighted score (<?= number_format($score_result['weighted_score'], 2) ?>) is below the minimum of 41."
                   >
                <i class="bi bi-check-circle me-2"></i>Complete Evaluation
            </button>
            <?php else: ?>
            <button type="button" class="btn btn-success"
                    onclick="submitTalisayDecision('talisay_approve','Mark this evaluation complete?','Complete Evaluation','bi-check-circle')">
                <i class="bi bi-check-circle me-2"></i>Complete Evaluation
            </button>
            <?php endif; ?>
            <button type="button" class="btn btn-danger"
                    onclick="submitTalisayDecision('talisay_reject','Return this application for revision?','Return for Revision','bi-arrow-counterclockwise')">
                <i class="bi bi-arrow-counterclockwise me-2"></i>Return for Revision
            </button>
        </div>
    </form>
</div>
<script>
function submitTalisayDecision(action, msg, btnLabel, btnIcon) {
    const remarks = document.getElementById('talisayRemarksField').value.trim();
    if (action === 'talisay_reject' && !remarks) {
        document.getElementById('talisayRemarksField').style.borderColor = '#334155';
        document.getElementById('talisayRemarksField').focus();
        showFormError('A reason is required when returning the application.');
        return;
    }
    document.getElementById('talisayRemarksField').style.borderColor = '';
    document.getElementById('talisayDecisionAction').value = action;
    confirmDelete(msg, 'talisayDecisionForm', btnLabel, btnIcon);
}
</script>
<?php endif; ?>

<?php elseif (!$i_have_slot && !$can_join && !isAdmin()): ?>
<!-- Slots full, not assigned -->
<div class="neon-card mb-4 [border-left:4px_solid_#e2e8f0]">
    <div class="d-flex align-items-center gap-4">
        <i class="bi bi-lock [color:#94a3b8] [font-size:1.2rem]"></i>
        <div class="text-muted small">You are not part of this review panel.</div>
    </div>
</div>
<?php endif; ?>

<?php endif; // !isAdmin() ?>

<!-- -- Admin Evaluation Summary -------------------------------- -->
<?php if ($app['status'] === 'approved' && isAdmin()): ?>
<div class="neon-card mb-4 [border-color:#e2e8f0]">
    <h6 class="mb-2 [color:var(--blue-dark)]"><i class="bi bi-clipboard-check me-2"></i>Evaluation Complete</h6>
    <p class="text-muted small mb-0">
        Evaluators have finished checking this submission. The score and recommended rank are for further committee review and are not a final decision.
    </p>
</div>
<?php endif; ?>

<!-- -- Revision Request Modal ------------------------------- -->
<div id="revisionModal" class="modal" role="dialog" aria-modal="true" aria-labelledby="revisionModalTitle" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,0.5);z-index:9999;align-items:center;justify-content:center;padding:1rem;">
    <div class="modal-dialog modal-dialog-centered modal-content [width:100%] [max-width:480px]">
        <div class="modal-header [background:#1a3a6b]">
            <div class="[display:flex] [align-items:center] [gap:0.75rem]">
                <i class="bi bi-pencil-square [color:#475569] [font-size:1.1rem]"></i>
                <span id="revisionModalTitle" class="modal-title [color:#fff]">Request Revision</span>
            </div>
            <button onclick="document.getElementById('revisionModal').style.display='none'"
                    class="btn-close btn-close-white" aria-label="Close"></button>
        </div>
        <div class="modal-body">
            <p class="[font-size:0.85rem] [color:#64748b] [margin-bottom:0.5rem]">
                KRA Entry: <strong id="revModalCategory" class="[color:#1e293b]"></strong>
            </p>
            <p class="[font-size:0.82rem] [color:#64748b] [margin-bottom:1rem]">
                Describe what needs to be corrected. The faculty will see this note and must fix and resubmit.
            </p>
            <form id="revisionForm" method="POST" action="index.php?page=<?= $return_page ?>&id=<?= $app_id ?>">
                <input type="hidden" name="action" value="request_revision">
                <input type="hidden" name="submission_id" id="revModalSubId">
                <div class="mb-4">
                    <label class="form-label fw-semibold [font-size:0.85rem]">Revision Note <span class="text-danger">*</span></label>
                    <textarea name="revision_note" id="revModalNote" class="form-control" rows="3"
                              placeholder="e.g. Evidence file is unclear, please upload a clearer copy." required></textarea>
                </div>
                <div class="modal-footer [padding-right:0] [padding-bottom:0] [padding-left:0]">
                    <button type="button" class="btn btn-secondary flex-fill"
                            onclick="document.getElementById('revisionModal').style.display='none'">Cancel</button>
                    <button type="button" class="btn btn-warning flex-fill fw-bold" onclick="submitRevision()">
                        <i class="bi bi-send me-1"></i>Send Revision Request
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
function openRevisionModal(subId, category) {
    document.getElementById('revModalSubId').value = subId;
    document.getElementById('revModalCategory').textContent = category;
    document.getElementById('revModalNote').value = '';
    document.getElementById('revisionModal').style.display = 'flex';
    setTimeout(() => document.getElementById('revModalNote').focus(), 100);
}
function submitRevision() {
    const note = document.getElementById('revModalNote').value.trim();
    if (!note) {
        document.getElementById('revModalNote').style.borderColor = '#334155';
        document.getElementById('revModalNote').focus();
        return;
    }
    document.getElementById('revisionForm').submit();
}
document.getElementById('revisionModal').addEventListener('click', function(e) {
    if (e.target === this) this.style.display = 'none';
});
</script>

<!-- Score Edit Modal -->
<div id="scoreModal" class="modal" role="dialog" aria-modal="true" aria-labelledby="scoreModalTitle" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,0.5);z-index:9999;align-items:center;justify-content:center;padding:1rem;">
    <div class="modal-dialog modal-dialog-centered modal-content [width:100%] [max-width:420px]">
        <div class="modal-header [background:#1a3a6b]">
            <div>
                <span id="scoreModalTitle" class="modal-title [color:#fff]"><i class="bi bi-pencil-square me-2"></i>Edit KRA Score</span>
                <div id="scoreModalCategory" class="[color:#bfdbfe] [font-size:0.75rem] [margin-top:2px]"></div>
            </div>
            <button onclick="document.getElementById('scoreModal').style.display='none'"
                    class="btn-close btn-close-white" aria-label="Close"></button>
        </div>
        <div class="modal-body">
            <form id="scoreForm" method="POST" action="index.php?page=<?= $return_page ?>&id=<?= $app_id ?>">
                <input type="hidden" name="action" value="alter_score">
                <input type="hidden" name="submission_id" id="scoreModalSubId">
                <div class="mb-4">
                    <label class="form-label fw-semibold small">Current Score</label>
                    <div id="scoreModalCurrent" class="[font-size:1.3rem] [font-weight:800] [color:#1a3a6b]"></div>
                </div>
                <div class="mb-4">
                    <label class="form-label fw-semibold small">New Score <span class="text-danger">*</span></label>
                    <input type="number" name="new_points" id="scoreModalInput" class="form-control"
                           step="0.01" min="0" max="200" placeholder="Enter corrected score" required>
                    <div class="form-text">Enter the reviewed correct score for this KRA entry.</div>
                </div>
                <div class="mb-6">
                    <label class="form-label fw-semibold small">Reason for Change <span class="text-muted">(optional)</span></label>
                    <input type="text" name="alter_note" class="form-control"
                           placeholder="e.g. Corrected based on reviewed documents">
                </div>
                <div class="modal-footer [padding-right:0] [padding-bottom:0] [padding-left:0]">
                    <button type="button" class="btn btn-secondary flex-fill"
                            onclick="document.getElementById('scoreModal').style.display='none'">Cancel</button>
                    <button type="button" class="btn btn-primary flex-fill fw-bold" onclick="submitScoreEdit()">
                        <i class="bi bi-check-circle me-1"></i>Save Score
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>


<script>
function showScoreSaved(oldVal, newVal, category) {
    const overlay = document.createElement('div');
    overlay.className = 'score-success-overlay';
    overlay.innerHTML = `
        <div class="score-success-box" id="scoreSuccessBox">
            <svg class="score-check-svg" width="64" height="64" viewBox="0 0 52 52">
                <circle cx="26" cy="26" r="24"/>
                <path d="M14 26 l8 8 l16-16"/>
            </svg>
            <div class="score-success-label">Score Updated!</div>
            <div class="score-success-sub">${category}: ${oldVal} -> ${newVal}</div>
        </div>`;
    document.body.appendChild(overlay);
    setTimeout(() => {
        const box = overlay.querySelector('#scoreSuccessBox');
        if (box) box.classList.add('fade-out');
        setTimeout(() => overlay.remove(), 380);
    }, 1800);
}

function openScoreModal(subId, category, currentPts) {
    document.getElementById('scoreModalSubId').value  = subId;
    document.getElementById('scoreModalCategory').textContent = category;
    document.getElementById('scoreModalCurrent').textContent  = currentPts;
    document.getElementById('scoreModalInput').value  = currentPts;
    document.getElementById('scoreModal').style.display = 'flex';
    setTimeout(() => document.getElementById('scoreModalInput').select(), 100);
}

function quickVerify(subId, btn) {
    btn.disabled = true;
    btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1" style="width:10px;height:10px;"></span>Marking...';

    const noteEl = document.getElementById('verifyNote_' + subId);
    const note   = noteEl ? noteEl.value : '';

    const fd = new FormData();
    fd.append('action',        'verify_submission');
    fd.append('submission_id', subId);
    fd.append('note',          note);

    fetch('index.php?page=<?= $return_page ?>&id=<?= $app_id ?>', {
        method: 'POST',
        headers: { 'X-Requested-With': 'XMLHttpRequest' },
        body: fd
    })
    .then(async r => {
        const text = await r.text();
        try {
            return JSON.parse(text);
        } catch (err) {
            return { ok: false, error: 'The server saved an unexpected response. Please try again.' };
        }
    })
    .then(data => {
        if (data.ok) {
            // Remove the note textarea alongside the button
            if (noteEl) noteEl.remove();

            // Replace the action button with an "Acceptable" badge in-place
            const row = btn.closest('tr');
            btn.outerHTML = '<span style="font-size:0.68rem;color:#16a34a;font-weight:600;display:inline-flex;align-items:center;gap:3px;">'
                          + '<i class="bi bi-patch-check-fill"></i>Acceptable</span>';

            // Update the not acceptable badge in the evidence cell
            if (row) {
                const verifyBadge = row.querySelector('[data-verification-badge="' + subId + '"]');
                if (verifyBadge) {
                    const done = Number(data.done_count || 1);
                    const total = Number(data.total_count || 1);
                    const allDone = !!data.all_verified || (total > 0 && done >= total);
                    verifyBadge.style.color = allDone ? '#16a34a' : '#1e4d8c';
                    verifyBadge.style.background = allDone ? '#f0fdf4' : '#eff6ff';
                    verifyBadge.style.borderColor = allDone ? '#bbf7d0' : '#bfdbfe';
                    verifyBadge.style.fontWeight = '700';
                    verifyBadge.innerHTML = allDone
                        ? '<i class="bi bi-patch-check-fill" style="font-size:0.62rem;"></i>Acceptable'
                        : '<i class="bi bi-patch-check-fill" style="font-size:0.62rem;"></i>You marked acceptable <span style="font-size:0.6rem;color:#64748b;">(' + done + '/' + total + ')</span>';
                }
            }

            // Re-check if all are now acceptable  -  remove the block message if shown
            const remaining = document.querySelectorAll('[id^="verifyBtn_"]');
            if (remaining.length === 0) {
                const approveBtn = document.querySelector('#checkerDecisionForm .btn-success');
                if (approveBtn && approveBtn.disabled) {
                    approveBtn.disabled = false;
                    approveBtn.title = '';
                }
            }
        } else {
            btn.disabled = false;
            btn.innerHTML = '<i class="bi bi-patch-check"></i>Mark as Acceptable';
            alert(data.error || 'Mark as acceptable failed. Please try again.');
        }
    })
    .catch(() => {
        btn.disabled = false;
        btn.innerHTML = '<i class="bi bi-patch-check"></i>Mark as Acceptable';
    });
}

function submitScoreEdit() {
    const val = parseFloat(document.getElementById('scoreModalInput').value);
    if (isNaN(val) || val < 0) {
        document.getElementById('scoreModalInput').style.borderColor = '#334155';
        document.getElementById('scoreModalInput').focus();
        return;
    }
    document.getElementById('scoreModalInput').style.borderColor = '';

    const oldVal  = document.getElementById('scoreModalCurrent').textContent;
    const category= document.getElementById('scoreModalCategory').textContent;
    const note    = document.getElementById('scoreModalNote')?.value || '';
    const subId   = document.getElementById('scoreModalSubId').value;

    // Close modal
    document.getElementById('scoreModal').style.display = 'none';

    // Submit via AJAX
    const fd = new FormData();
    fd.append('action',        'alter_score');
    fd.append('submission_id', subId);
    fd.append('new_points',    val);
    fd.append('alter_note',    note);

    fetch('index.php?page=<?= $return_page ?>&id=<?= $app_id ?>', {
        method: 'POST',
        headers: { 'X-Requested-With': 'XMLHttpRequest' },
        body: fd
    })
    .then(r => r.json())
    .then(data => {
        if (data.auto_returned) {
            // Show warning overlay  -  score dropped below 41
            const overlay = document.createElement('div');
            overlay.className = 'score-success-overlay';
            overlay.innerHTML = `
                <div class="score-success-box" id="scoreSuccessBox" style="border-top:4px solid #334155;">
                    <svg width="64" height="64" viewBox="0 0 52 52" style="flex-shrink:0;">
                        <circle cx="26" cy="26" r="24" fill="none" stroke="#334155" stroke-width="3"
                            stroke-dasharray="166" stroke-dashoffset="0"/>
                        <text x="26" y="32" text-anchor="middle" font-size="22" font-weight="bold" fill="#334155">!</text>
                    </svg>
                    <div class="score-success-label" style="color:#1e293b;">Application Returned</div>
                    <div class="score-success-sub" style="text-align:center;">Weighted score dropped to <strong>${data.weighted}</strong><br>below the minimum of 41</div>
                </div>`;
            document.body.appendChild(overlay);
            setTimeout(() => {
                const box = overlay.querySelector('#scoreSuccessBox');
                if (box) box.classList.add('fade-out');
                setTimeout(() => {
                    overlay.remove();
                    window.location.href = 'index.php?page=<?= $return_page ?>&id=<?= $app_id ?>';
                }, 380);
            }, 2200);
        } else {
            // Normal success overlay
            showScoreSaved(oldVal, val, category);
            setTimeout(() => {
                window.location.href = 'index.php?page=<?= $return_page ?>&id=<?= $app_id ?>#kra-verification';
            }, 1900);
        }
    })
    .catch(() => {
        window.location.href = 'index.php?page=<?= $return_page ?>&id=<?= $app_id ?>';
    });
}

document.getElementById('scoreModal').addEventListener('click', function(e) {
    if (e.target === this) this.style.display = 'none';
});

// Auto-scroll to KRA section on load if coming back from score save
document.addEventListener('DOMContentLoaded', function () {
    if (window.location.hash === '#kra-verification') {
        const el = document.getElementById('kra-verification');
        if (el) setTimeout(() => el.scrollIntoView({ behavior: 'smooth', block: 'start' }), 100);
    }
});
</script>
</script>
