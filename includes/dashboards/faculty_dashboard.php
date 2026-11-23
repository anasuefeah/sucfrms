<?php
$uid   = $_SESSION['user_id'];
$cycle = getActiveCycle($pdo);

// ── First-login welcome modal ──────────────────────────────
$show_welcome = !empty($_SESSION['show_welcome']);
if ($show_welcome) unset($_SESSION['show_welcome']);
$welcome_first = htmlspecialchars($_SESSION['first_name'] ?? 'Faculty', ENT_QUOTES);

if (!$cycle) {
    $last_cycle = $pdo->query("SELECT * FROM cycles ORDER BY created_at DESC LIMIT 1")->fetch();
    echo '
    <div class="neon-card rounded-0 shadow-none text-center py-5">
        <i class="bi bi-calendar-x" style="font-size:2.5rem;color:#cbd5e1;display:block;margin-bottom:0.75rem;"></i>
        <h5 class="fw-bold mb-2" style="color:#1a3a6b;">No Active Reclassification Cycle</h5>
        <p class="text-muted mb-1">There is currently no open cycle. Your account is ready.</p>
        <p class="text-muted small">When the next cycle opens, your application will be available here automatically.</p>'
        . ($last_cycle ? '<p class="text-muted small mt-2">Last cycle: <strong>' . htmlspecialchars($last_cycle['cycle_name']) . '</strong> (' . ucfirst($last_cycle['status']) . ')</p>' : '')
        . '</div>';
    return;
}

$app    = getOrCreateApplication($pdo, $uid, $cycle['cycle_id']);
$app_id = $app['application_id'];

// -- KRA scores ------------------------------------------------
$score_result = getApplicationScoreSummary($pdo, (int)$app_id);
$kra_map      = $score_result['kra_map'];
$total        = $score_result['grand_total'];

// -- Score computation -----------------------------------------
$faculty_row  = $pdo->prepare("SELECT `rank`, full_name FROM users WHERE user_id=?");
$faculty_row->execute([$uid]);
$faculty_row  = $faculty_row->fetch();
$faculty_rank = $faculty_row['rank'] ?? '';
$raw_kra      = array_map(fn($d) => $d['pts'], $kra_map);

// -- Checker approval progress (for under_review status display) --
$chk_approved = 0;
$chk_slots    = 0;
$chk_total    = max(1, (int)$pdo->query("SELECT COUNT(*) FROM users WHERE role = 'evaluator' AND status='active'")->fetchColumn());
try {
    $chk_q = $pdo->prepare("
        SELECT r.decision FROM application_checker_reviews r
        JOIN users u ON r.checker_id = u.user_id
        WHERE r.application_id = ? AND u.role = 'evaluator'
    ");
    $chk_q->execute([$app_id]);
    foreach ($chk_q->fetchAll() as $cr) {
        $chk_slots++;
        if ($cr['decision'] === 'approved') $chk_approved++;
    }
} catch (\Exception $e) { /* table not yet created */ }

$weights      = $score_result['weights'];
$weighted     = $score_result['weighted_score'];
$inc          = $score_result['sub_rank_increment'];
$meets_min    = $weighted >= 41;

// -- Faculty campus --------------------------------------------
$my_campus_name = '';
$campus_row = $pdo->prepare("SELECT u.campus_id, c.campus_name FROM users u LEFT JOIN campuses c ON u.campus_id = c.campus_id WHERE u.user_id = ?");
$campus_row->execute([$uid]);
$campus_row = $campus_row->fetch();
if ($campus_row) {
    $my_campus_name = $campus_row['campus_name'] ?? '';
}

// -- Deadline info ---------------------------------------------
$effective_deadline = getEffectiveSubmissionDeadline($pdo, (int)$cycle['cycle_id'], (int)$uid, $cycle['submission_deadline'] ?? null);
$submission_start_ts = !empty($cycle['submission_start_date']) ? strtotime($cycle['submission_start_date'] . ' 00:00:00') : null;
$deadline_ts = $effective_deadline ? strtotime($effective_deadline . ' 23:59:59') : null;
$submission_not_open = $submission_start_ts !== null && time() < $submission_start_ts;
$deadline_passed = ($cycle['status'] ?? 'closed') !== 'open'
    || $submission_not_open
    || ($deadline_ts !== null && time() > $deadline_ts);
$deadline_str    = $effective_deadline ? date('M d, Y', strtotime($effective_deadline)) : '';
$days_left       = $deadline_ts ? max(0, (int)ceil(($deadline_ts - time()) / 86400)) : null;
$deadline_urgent = $days_left !== null && $days_left <= 3;

// -- Score gap to next bracket ---------------------------------------------

// -- Score gap to next bracket ---------------------------------
$brackets   = [[41,50,1],[51,60,2],[61,70,3],[71,80,4],[81,90,5],[91,100,6]];
$next_bracket_gap  = null;
$next_bracket_inc  = null;
foreach ($brackets as [$lo, $hi, $r]) {
    if ($weighted < $lo) {
        $next_bracket_gap = round($lo - $weighted, 2);
        $next_bracket_inc = $r;
        break;
    }
}
$can_submit = !in_array($app['status'], ['submitted','under_review','approved','admin_rejected','needs_revision'])
              && $total > 0 && $meets_min && !$deadline_passed;
$can_edit   = !in_array($app['status'], ['approved','admin_rejected']);

// -- KRA display config ----------------------------------------
$kra_info = [
    'Instruction'              => ['icon'=>'bi-book',        'color'=>'#1e4d8c','max' => kraMaximumPoints()['Instruction'], 'short'=>'KRA I',  'tab'=>'instruction'],
    'Research'                 => ['icon'=>'bi-journal-text','color'=>'#1a3a6b','max' => kraMaximumPoints()['Research'], 'short'=>'KRA II', 'tab'=>'research'],
    'Extension'                => ['icon'=>'bi-people',      'color'=>'#1a5276','max' => kraMaximumPoints()['Extension'], 'short'=>'KRA III','tab'=>'extension'],
    'Professional Development' => ['icon'=>'bi-award',       'color'=>'#1e4d8c','max' => kraMaximumPoints()['Professional Development'], 'short'=>'KRA IV', 'tab'=>'profdev'],
];

// -- POST handlers (unchanged logic) --------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    if ($action === 'submit_application' && $can_submit) {
        if ($weighted < 41) {
            flashMessage('danger', 'Your weighted score (' . number_format($weighted, 2) . ') is below the minimum of 41.');
            echo "<script>window.location.href='index.php?page=dashboard';</script>"; exit;
        }
        if ($deadline_passed) {
            flashMessage('danger', $submission_not_open ? 'The faculty submission period is not open yet.' : 'The cycle is no longer open for submissions.');
            echo "<script>window.location.href='index.php?page=dashboard';</script>"; exit;
        }
        try {
            $new_status = submitApplicationForReview($pdo, $app);
        } catch (Throwable $error) {
            error_log('SUCFRMS application submission: ' . $error->getMessage());
            flashMessage('danger', 'The application could not be submitted. Refresh the page and try again.');
            echo "<script>window.location.href='index.php?page=dashboard';</script>"; exit;
        }
        logAudit($pdo, $uid, 'Application Submitted', "Application #{$app_id} submitted for {$cycle['cycle_name']}.");
        // Notify all active evaluators of the new submission
        $campus_checkers = $pdo->query("SELECT user_id FROM users WHERE role = 'evaluator' AND status='active'")->fetchAll(PDO::FETCH_COLUMN);
        foreach ($campus_checkers as $cid) {
            createNotif($pdo, (int)$cid, 'new_submission',
                "New application submitted by " . ($_SESSION['full_name'] ?? 'A faculty member') . "  -  awaiting your review.",
                $app_id);
        }
        flashMessage('success', $new_status === 'under_review' ? 'Application resubmitted to your evaluator.' : 'Application submitted for review.');
        echo "<script>window.location.href='index.php?page=dashboard';</script>"; exit;
    }
    if ($action === 'resubmit_revision' && $app['status'] === 'needs_revision') {
        try {
            KraRevisionWorkflow::resubmit($pdo, (int)$app_id);
            recalcApplicationScore($pdo, (int)$app_id);
        } catch (RuntimeException $e) {
            flashMessage('danger', htmlspecialchars($e->getMessage(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'));
            echo "<script>window.location.href='index.php?page=my_application';</script>"; exit;
        }
        logAudit($pdo, $uid, 'Revision Resubmitted', "Faculty resubmitted revised entries for Application #{$app_id}");
        flashMessage('success', 'Revised entries submitted. The evaluator will continue reviewing your application.');
        echo "<script>window.location.href='index.php?page=dashboard';</script>"; exit;
    }
}
?>

<!-- -- Page header --------------------------------------------- -->
<?php include __DIR__ . '/_notif_banner_snippet.php'; ?>

<?php
$steps = [
    ['label'=>'Profile Complete',    'sublabel'=>'Name, rank, campus set',                                      'icon'=>'bi-person-check',    'done'=>!empty($_SESSION['full_name']) && !empty($faculty_rank)],
    ['label'=>'KRA Entries Added',   'sublabel'=>$score_result['claim_count'] . ' entries', 'icon'=>'bi-list-check', 'done'=>$score_result['claim_count'] > 0, 'link'=>'?page=apply'],
    ['label'=>'Submitted',           'sublabel'=>'Not yet submitted',                                           'icon'=>'bi-send',            'done'=>in_array($app['status'],['submitted','under_review','approved','reclassified','admin_rejected'])],
    ['label'=>'Under Evaluation',    'sublabel'=>'Evaluator review',                                            'icon'=>'bi-search',          'done'=>in_array($app['status'],['approved','reclassified'])],
    ['label'=>'Evaluation Complete', 'sublabel'=>'For committee review',                                        'icon'=>'bi-clipboard-check', 'done'=>in_array($app['status'],['approved','reclassified'])],
];
$current_step = 0;
foreach ($steps as $i => $s) { if ($s['done']) $current_step = $i + 1; }
?>

<!-- ── Welcome banner ── -->
<div style="background:#fff;border:1px solid #e2e8f0;padding:1rem 1.25rem;margin-bottom:1rem;
            display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:0.75rem;border-radius:0;">
    <div style="display:flex;align-items:center;gap:0.75rem;">
        <?php
        $pic = $_SESSION['profile_pic'] ?? '';
        $init_h = strtoupper(substr($_SESSION['first_name'] ?? '',0,1).substr($_SESSION['last_name'] ?? '',0,1)) ?: 'U';
        ?>
        <?php if ($pic): ?>
        <img class="account-avatar" src="<?= sanitize($pic) ?>" style="width:44px;height:44px;border-radius:50%;object-fit:cover;border:1px solid #e2e8f0;flex-shrink:0;" alt="">
        <?php else: ?>
        <div class="account-avatar" style="width:44px;height:44px;border-radius:50%;background:#1a3a6b;display:flex;align-items:center;justify-content:center;font-size:0.9rem;font-weight:700;color:#fff;flex-shrink:0;"><?= $init_h ?></div>
        <?php endif; ?>
        <div>
            <div style="font-weight:700;color:#1e293b;font-size:0.9rem;line-height:1.2;"><?= sanitize($_SESSION['full_name'] ?? '') ?></div>
            <div style="font-size:0.72rem;color:#94a3b8;margin-top:2px;">
                <?= sanitize($faculty_rank) ?><?= $my_campus_name ? ' &nbsp;·&nbsp; '.sanitize($my_campus_name) : '' ?>
                &nbsp;·&nbsp; <?= date('M d, Y') ?>
            </div>
        </div>
    </div>
    <?php if ($deadline_str && !$deadline_passed): ?>
    <div style="text-align:right;">
        <div style="font-size:0.65rem;color:#94a3b8;text-transform:uppercase;letter-spacing:0.06em;margin-bottom:2px;">Submission Deadline</div>
        <div style="font-size:0.82rem;font-weight:700;color:<?= $deadline_urgent?'#dc2626':'#1a3a6b' ?>;"><?= $deadline_str ?></div>
        <?php if ($days_left !== null): ?>
        <div style="font-size:0.68rem;color:<?= $deadline_urgent?'#dc2626':'#64748b' ?>;"><?= $days_left ?> day<?= $days_left===1?'':'s' ?> left</div>
        <?php endif; ?>
    </div>
    <?php endif; ?>
</div>

<!-- ── Application Progress ── -->
<div style="background:#fff;border:1px solid #e2e8f0;padding:1rem 1.25rem;margin-bottom:1rem;border-radius:0;">
    <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:0.85rem;">
        <span style="font-size:0.65rem;font-weight:700;color:#94a3b8;text-transform:uppercase;letter-spacing:0.08em;">
            Application Progress <?= helpBtn('Application Progress', 'This shows where your application is in the reclassification process.') ?>
        </span>
        <span style="font-size:0.68rem;color:#94a3b8;"><?= $current_step ?> / <?= count($steps) ?> steps</span>
    </div>
    <div style="display:flex;align-items:flex-start;">
        <?php foreach ($steps as $i => $step):
            $done    = $step['done'];
            $current = ($i === $current_step) && !$done;
        ?>
        <div style="flex:1;text-align:center;position:relative;padding:0 2px;">
            <?php if ($i < count($steps)-1): ?>
            <div style="position:absolute;top:13px;left:calc(50% + 13px);right:calc(-50% + 13px);height:1px;background:<?= $done?'#1a3a6b':'#e2e8f0' ?>;z-index:0;"></div>
            <?php endif; ?>
            <div class="application-progress-icon" style="width:26px;height:26px;margin:0 auto 5px;
                        display:flex;align-items:center;justify-content:center;position:relative;z-index:1;
                        background:<?= $done?'#1a3a6b':($current?'#f0f4fb':'#f8fafc') ?>;
                        border:1.5px solid <?= $done?'#1a3a6b':($current?'#1a3a6b':'#e2e8f0') ?>;">
                <?php if ($done): ?>
                <i class="bi bi-check-lg" style="color:#fff;font-size:0.72rem;"></i>
                <?php else: ?>
                <i class="bi <?= $step['icon'] ?>" style="color:<?= $current?'#1a3a6b':'#cbd5e1' ?>;font-size:0.65rem;"></i>
                <?php endif; ?>
            </div>
            <div style="font-size:0.65rem;font-weight:<?= $done||$current?'700':'400' ?>;color:<?= $done?'#1a3a6b':($current?'#1e293b':'#94a3b8') ?>;">
                <?php if (!empty($step['link']) && !$done): ?>
                <a href="<?= $step['link'] ?>" style="color:inherit;text-decoration:none;"><?= $step['label'] ?></a>
                <?php else: ?><?= $step['label'] ?><?php endif; ?>
            </div>
            <div style="font-size:0.58rem;color:#cbd5e1;margin-top:1px;"><?= $step['sublabel'] ?></div>
        </div>
        <?php endforeach; ?>
    </div>
</div>

<!-- ── KRA Scores ── -->
<div class="neon-card mb-3" style="padding:1rem 1.25rem;border-radius:0;box-shadow:none;">
    <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:0.85rem;flex-wrap:wrap;gap:0.5rem;">
        <div>
            <div style="font-size:0.65rem;font-weight:700;color:#94a3b8;text-transform:uppercase;letter-spacing:0.08em;">KRA Scores</div>
            <div style="font-size:0.72rem;color:#64748b;margin-top:2px;">Points per area vs maximum</div>
        </div>
        <?php if ($can_edit): ?>
        <a href="?page=apply" class="btn btn-xs btn-outline-primary">Edit KRA Entries</a>
        <?php endif; ?>
    </div>
    <div style="display:flex;flex-direction:column;gap:0.85rem;">
        <?php foreach ($kra_map as $kra => $data):
            $info = $kra_info[$kra];
            $pts  = $data['pts'];
            $ver  = $data['verified'];
            $pct  = $info['max'] > 0 ? min(100,($pts/$info['max'])*100) : 0;
            $empty = $pts == 0;
        ?>
        <div>
            <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:4px;">
                <div style="display:flex;align-items:center;gap:0.5rem;">
                    <span style="font-size:0.78rem;font-weight:600;color:<?= $empty?'#94a3b8':'#1e293b' ?>;"><?= $info['short'] ?> — <?= $kra ?></span>
                    <?php if ($ver): ?>
                    <span style="font-size:0.6rem;font-weight:600;color:#1a3a6b;background:#eff6ff;border:1px solid #bfdbfe;border-radius:0;padding:1px 6px;">Acceptable</span>
                    <?php endif; ?>
                </div>
                <div style="display:flex;align-items:center;gap:0.75rem;">
                    <span style="font-size:0.78rem;font-weight:700;color:<?= $empty?'#94a3b8':'#1e293b' ?>;"><?= number_format($pts,1) ?><span style="font-weight:400;color:#94a3b8;font-size:0.7rem;"> / <?= $info['max'] ?></span></span>
                    <?php if ($can_edit): ?>
                    <a href="?page=apply&tab=<?= $info['tab'] ?>" style="font-size:0.68rem;color:#1a3a6b;text-decoration:none;"><?= $empty?'+ Add':'Edit' ?></a>
                    <?php endif; ?>
                </div>
            </div>
            <div style="height:6px;background:#f1f5f9;">
                <div style="height:100%;width:<?= $pct ?>%;background:<?= $empty?'#e2e8f0':'#1a3a6b' ?>;"></div>
            </div>            <div style="font-size:0.62rem;color:#94a3b8;margin-top:2px;"><?= $empty ? 'No entries yet' : number_format($pct,0).'% of max' ?><?= (!$empty && $pct<100) ? ' &nbsp;·&nbsp; '.number_format($info['max']-$pts,1).' pts to max' : '' ?></div>
        </div>
        <?php endforeach; ?>
    </div>
</div>

<!-- ── Score Breakdown ── -->
<?php if ($total > 0): ?>
<div class="neon-card mb-4" style="padding:1rem 1.25rem;border-radius:0;box-shadow:none;">
    <div style="font-size:0.65rem;font-weight:700;color:#94a3b8;text-transform:uppercase;letter-spacing:0.08em;margin-bottom:0.75rem;">Score Breakdown</div>
    <div class="table-responsive">
        <table style="width:100%;border-collapse:collapse;font-size:0.82rem;">
            <thead>
                <tr style="border-bottom:2px solid #e2e8f0;">
                    <th style="padding:0.5rem 0.75rem;text-align:left;font-size:0.68rem;color:#94a3b8;font-weight:700;text-transform:uppercase;">KRA</th>
                    <th style="padding:0.5rem 0.75rem;text-align:center;font-size:0.68rem;color:#94a3b8;font-weight:700;text-transform:uppercase;">Raw</th>
                    <th style="padding:0.5rem 0.75rem;text-align:center;font-size:0.68rem;color:#94a3b8;font-weight:700;text-transform:uppercase;">Capped</th>
                    <th style="padding:0.5rem 0.75rem;text-align:center;font-size:0.68rem;color:#94a3b8;font-weight:700;text-transform:uppercase;">Weight</th>
                    <th style="padding:0.5rem 0.75rem;text-align:center;font-size:0.68rem;color:#94a3b8;font-weight:700;text-transform:uppercase;">Weighted</th>
                </tr>
            </thead>
            <tbody>
            <?php
            $kra_labels = ['Instruction'=>'KRA I','Research'=>'KRA II','Extension'=>'KRA III','Professional Development'=>'KRA IV'];
            $kra_capped = ['Instruction'=>$score_result['kra1'],'Research'=>$score_result['kra2'],'Extension'=>$score_result['kra3'],'Professional Development'=>$score_result['kra4']];
            foreach ($kra_labels as $key => $label):
                $raw  = $kra_map[$key]['pts'];
                $cap  = $kra_capped[$key];
                $w    = $weights[$key];
                $wval = round($cap * $w, 2);
            ?>
            <tr style="border-bottom:1px solid #f4f6f9;">
                <td style="padding:0.55rem 0.75rem;color:#1e293b;font-weight:500;"><?= $label ?></td>
                <td style="padding:0.55rem 0.75rem;text-align:center;color:#475569;"><?= number_format($raw,2) ?></td>
                <td style="padding:0.55rem 0.75rem;text-align:center;color:#475569;"><?= number_format($cap,2) ?></td>
                <td style="padding:0.55rem 0.75rem;text-align:center;color:#475569;"><?= ($w*100) ?>%</td>
                <td style="padding:0.55rem 0.75rem;text-align:center;font-weight:700;color:#1a3a6b;"><?= number_format($wval,2) ?></td>
            </tr>
            <?php endforeach; ?>
            </tbody>
            <tfoot>
                <tr style="border-top:2px solid #e2e8f0;background:#f8fafc;">
                    <td colspan="4" style="padding:0.6rem 0.75rem;font-weight:700;color:#1e293b;">Weighted Score</td>
                    <td style="padding:0.6rem 0.75rem;text-align:center;font-weight:800;font-size:1rem;color:#1a3a6b;"><?= number_format($weighted,2) ?></td>
                </tr>
            </tfoot>
        </table>
    </div>
    <div style="margin-top:0.6rem;font-size:0.75rem;color:<?= $inc>0?'#1a3a6b':'#64748b' ?>;font-weight:600;">
        <?= $inc > 0 ? "+{$inc} sub-rank increment" : 'Below minimum — 41.00 required for reclassification' ?>
        <?php if (($score_result['award_bonus']??0)>0): ?>
        &nbsp;·&nbsp; <span style="color:#475569;">+1 National/Intl Award bonus included</span>
        <?php endif; ?>
    </div>
</div>
<?php endif; ?>

<!-- ── Chart JS ── -->
<script>
document.addEventListener('DOMContentLoaded', function () {
});
</script>

<?php if ($show_welcome): ?>
<!-- ── First-Login Welcome Modal ─────────────────────────── -->
<div id="welcomeModal"
     style="display:flex;position:fixed;inset:0;z-index:99999;background:rgba(15,23,42,0.6);
            align-items:center;justify-content:center;padding:1rem;">
    <div style="background:#fff;width:100%;max-width:480px;border:1px solid #e5e7eb;overflow:hidden;
                animation:welcomeFadeIn 0.3s ease;">

        <!-- Header -->
        <div style="background:#1a3a6b;padding:1.5rem 1.5rem 1.25rem;text-align:center;">
            <div style="width:56px;height:56px;border-radius:50%;background:rgba(255,255,255,0.12);
                        border:2px solid rgba(255,255,255,0.25);display:flex;align-items:center;
                        justify-content:center;margin:0 auto 0.75rem;">
                <i class="bi bi-person-check-fill" style="color:#fff;font-size:1.4rem;"></i>
            </div>
            <div style="font-size:1.1rem;font-weight:700;color:#fff;line-height:1.3;">
                Welcome, <?= $welcome_first ?>!
            </div>
            <div style="font-size:0.75rem;color:rgba(255,255,255,0.6);margin-top:0.3rem;">
                SUC Faculty Reclassification Management System
            </div>
        </div>

        <!-- Body -->
        <div style="padding:1.25rem 1.5rem;">
            <p style="font-size:0.85rem;color:#374151;line-height:1.6;margin:0 0 1rem;">
                Your profile has been set up successfully. Your account is now fully active.
            </p>

            <div style="display:flex;flex-direction:column;gap:0.5rem;margin-bottom:1.25rem;">
                <?php foreach ([
                    ['bi-clipboard-check','Submit your reclassification application when a cycle is open.'],
                    ['bi-file-earmark-arrow-up','Upload evidence for each Key Result Area (KRA).'],
                    ['bi-bar-chart-line','Track your scores and application status in real time.'],
                ] as [$icon, $text]): ?>
                <div style="display:flex;align-items:flex-start;gap:0.65rem;padding:0.5rem 0.65rem;
                            border:1px solid #f3f4f6;background:#f9fafb;font-size:0.78rem;color:#374151;line-height:1.4;">
                    <i class="bi <?= $icon ?>" style="color:#1a3a6b;font-size:0.85rem;flex-shrink:0;margin-top:1px;"></i>
                    <?= $text ?>
                </div>
                <?php endforeach; ?>
            </div>

            <button onclick="document.getElementById('welcomeModal').style.display='none'"
                    style="width:100%;padding:0.6rem;background:#1a3a6b;color:#fff;border:none;
                           font-size:0.85rem;font-weight:700;cursor:pointer;display:flex;align-items:center;justify-content:center;gap:6px;"
                    onmouseover="this.style.background='#152d54'" onmouseout="this.style.background='#1a3a6b'">
                <i class="bi bi-arrow-right-circle" style="font-size:0.85rem;"></i>
                Proceed to Dashboard
            </button>
        </div>
    </div>
</div>
<style>
@keyframes welcomeFadeIn {
    from { opacity:0; transform:translateY(16px); }
    to   { opacity:1; transform:translateY(0); }
}
</style>
<?php endif; ?>
