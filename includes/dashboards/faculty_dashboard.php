<?php
$uid   = $_SESSION['user_id'];
$cycle = getActiveCycle($pdo);

if (!$cycle) {
    $last_cycle = $pdo->query("SELECT * FROM cycles ORDER BY created_at DESC LIMIT 1")->fetch();
    echo '
    <div class="neon-card text-center py-5">
        <i class="bi bi-calendar-x" style="font-size:2.5rem;color:#cbd5e1;display:block;margin-bottom:0.75rem;"></i>
        <h5 class="fw-bold mb-2" style="color:#1a3a6b;">No Active Reclassification Cycle</h5>
        <p class="text-muted mb-1">There is currently no open cycle. Your account is ready &mdash; you do not need to register again.</p>
        <p class="text-muted small">When the next cycle opens, your application will be available here automatically.</p>'
        . ($last_cycle ? '<p class="text-muted small mt-2">Last cycle: <strong>' . htmlspecialchars($last_cycle['cycle_name']) . '</strong> (' . ucfirst($last_cycle['status']) . ')</p>' : '')
        . '</div>';
    return;
}

$app    = getOrCreateApplication($pdo, $uid, $cycle['cycle_id']);
$app_id = $app['application_id'];

// â”€â”€ KRA scores â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
$kra_scores = $pdo->prepare("SELECT kra_category, SUM(computed_points) as pts, MAX(verified) as verified FROM kra_submissions WHERE application_id = ? GROUP BY kra_category");
$kra_scores->execute([$app_id]);
$kra_map = [
    'Instruction'              => ['pts'=>0,'verified'=>0],
    'Research'                 => ['pts'=>0,'verified'=>0],
    'Extension'                => ['pts'=>0,'verified'=>0],
    'Professional Development' => ['pts'=>0,'verified'=>0],
];
foreach ($kra_scores->fetchAll() as $k) {
    $kra_map[$k['kra_category']] = ['pts' => (float)$k['pts'], 'verified' => (int)$k['verified']];
}
$total = array_sum(array_column($kra_map, 'pts'));

// â”€â”€ Score computation â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
$faculty_row  = $pdo->prepare("SELECT rank, full_name FROM users WHERE user_id=?");
$faculty_row->execute([$uid]);
$faculty_row  = $faculty_row->fetch();
$faculty_rank = $faculty_row['rank'] ?? '';
$raw_kra      = array_map(fn($d) => $d['pts'], $kra_map);

// Detect national/international award bonus from live submissions
$award_check = $pdo->prepare("
    SELECT COUNT(*) FROM kra_submissions
    WHERE application_id = ?
      AND kra_category = 'Professional Development'
      AND remarks LIKE 'C-award|||%|||0'
");
$award_check->execute([$app_id]);
$has_national_award = (int)$award_check->fetchColumn() > 0;

// â”€â”€ Checker approval progress (for under_review status display) â”€â”€
$chk_approved = 0;
$chk_slots    = 0;
$chk_total    = max(1, (int)$pdo->query("SELECT COUNT(*) FROM users WHERE role IN ('checker','checker_faculty') AND status='active'")->fetchColumn());
try {
    $chk_q = $pdo->prepare("
        SELECT r.decision FROM application_checker_reviews r
        JOIN users u ON r.checker_id = u.user_id
        WHERE r.application_id = ? AND u.role IN ('checker','checker_faculty')
    ");
    $chk_q->execute([$app_id]);
    foreach ($chk_q->fetchAll() as $cr) {
        $chk_slots++;
        if ($cr['decision'] === 'approved') $chk_approved++;
    }
} catch (\Exception $e) { /* table not yet created */ }

$score_result = computeWeightedScore($raw_kra, $faculty_rank, $has_national_award);
$weights      = $score_result['weights'];
$weighted     = $score_result['weighted_score'];
$inc          = $score_result['sub_rank_increment'];
$meets_min    = $weighted >= 41;

// â”€â”€ Colleagues from same campus â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
$my_campus_id = null;
$my_campus_name = '';
$campus_row = $pdo->prepare("SELECT u.campus_id, c.campus_name FROM users u LEFT JOIN campuses c ON u.campus_id = c.campus_id WHERE u.user_id = ?");
$campus_row->execute([$uid]);
$campus_row = $campus_row->fetch();
if ($campus_row) {
    $my_campus_id   = $campus_row['campus_id'];
    $my_campus_name = $campus_row['campus_name'] ?? '';
}

$colleagues = [];
if ($my_campus_id) {
    $col_stmt = $pdo->prepare("
        SELECT u.user_id, u.full_name, u.first_name, u.middle_name, u.last_name, u.rank,
               a.status AS app_status,
               a.updated_at
        FROM users u
        LEFT JOIN applications a
            ON a.user_id = u.user_id AND a.cycle_id = ?
        WHERE u.campus_id = ?
          AND u.status    = 'active'
          AND u.role      IN ('faculty','checker_faculty')
          AND u.user_id  != ?
        ORDER BY u.full_name ASC
        LIMIT 20
    ");
    $col_stmt->execute([$cycle['cycle_id'], $my_campus_id, $uid]);
    $colleagues = $col_stmt->fetchAll();
}

// Status label + color for colleague display (no scores shown)
function colleagueStatusLabel(string $s): array {
    return match($s) {
        'draft'          => ['Draft',          '#94a3b8'],
        'submitted'      => ['Submitted',       '#1e4d8c'],
        'under_review'   => ['Under Review',    '#1a3a6b'],
        'needs_revision' => ['Needs Revision',  '#475569'],
        'approved'       => ['Approved',        '#1e4d8c'],
        'rejected'       => ['Returned',        '#64748b'],
        'admin_rejected' => ['Rejected',        '#64748b'],
        default          => ['No Application',  '#cbd5e1'],
    };
}

// â”€â”€ Deadline info â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
// Cycle open/closed controls submission availability — no deadline date needed.
$deadline_passed = ($cycle['status'] ?? 'closed') !== 'open';
$deadline_str    = '';
$days_left       = null;
$deadline_urgent = false;

// ── Score gap to next bracket ─────────────────────────────────────────────

// â”€â”€ Score gap to next bracket â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
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

// â”€â”€ KRA display config â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
$kra_info = [
    'Instruction'              => ['icon'=>'bi-book',        'color'=>'#1e4d8c','max'=>100, 'short'=>'KRA I',  'tab'=>'instruction'],
    'Research'                 => ['icon'=>'bi-journal-text','color'=>'#1a3a6b','max'=>100, 'short'=>'KRA II', 'tab'=>'research'],
    'Extension'                => ['icon'=>'bi-people',      'color'=>'#1a5276','max'=>100, 'short'=>'KRA III','tab'=>'extension'],
    'Professional Development' => ['icon'=>'bi-award',       'color'=>'#1e4d8c','max'=>100, 'short'=>'KRA IV', 'tab'=>'profdev'],
];

// â”€â”€ POST handlers (unchanged logic) â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    if ($action === 'submit_application' && $can_submit) {
        if ($weighted < 41) {
            flashMessage('danger', 'Your weighted score (' . number_format($weighted, 2) . ') is below the minimum of 41.');
            echo "<script>window.location.href='index.php?page=dashboard';</script>"; exit;
        }
        if ($deadline_passed) {
            flashMessage('danger', 'The cycle is no longer open for submissions.');
            echo "<script>window.location.href='index.php?page=dashboard';</script>"; exit;
        }
        try { recalcApplicationScore($pdo, $app_id); } catch (\Exception $e) {}
        $new_status = !empty($app['checker_id']) ? 'under_review' : 'submitted';
        $pdo->prepare("UPDATE applications SET status=?, submitted_at=NOW() WHERE application_id=?")->execute([$new_status, $app_id]);
        logAudit($pdo, $uid, 'Application Submitted', "Application #{$app_id} submitted for {$cycle['cycle_name']}.");
        // Notify all active campus checkers of new submission
        $campus_checkers = $pdo->query("SELECT user_id FROM users WHERE role IN ('checker','checker_faculty') AND status='active'")->fetchAll(PDO::FETCH_COLUMN);
        foreach ($campus_checkers as $cid) {
            createNotif($pdo, (int)$cid, 'new_submission',
                "New application submitted by " . ($_SESSION['full_name'] ?? 'A faculty member') . " — awaiting your review.",
                $app_id);
        }
        flashMessage('success', $new_status === 'under_review' ? 'Application resubmitted to your checker.' : 'Application submitted for review.');
        echo "<script>window.location.href='index.php?page=dashboard';</script>"; exit;
    }
    if ($action === 'resubmit_revision' && $app['status'] === 'needs_revision') {
        try { recalcApplicationScore($pdo, $app_id); } catch (\Exception $e) {}
        $pdo->prepare("UPDATE kra_submissions SET revision_status='ok', revision_note=NULL, revision_by=NULL, revision_at=NULL WHERE application_id=?")->execute([$app_id]);
        $pdo->prepare("UPDATE applications SET status='under_review', checker_remarks=NULL WHERE application_id=?")->execute([$app_id]);
        logAudit($pdo, $uid, 'Revision Resubmitted', "Faculty resubmitted revised entries for Application #{$app_id}");
        // Notify campus checkers of revision compliance
        $campus_checkers = $pdo->query("SELECT user_id FROM users WHERE role IN ('checker','checker_faculty') AND status='active'")->fetchAll(PDO::FETCH_COLUMN);
        foreach ($campus_checkers as $cid) {
            createNotif($pdo, (int)$cid, 'revision_resubmitted',
                ($_SESSION['full_name'] ?? 'A faculty member') . " has resubmitted the revised KRA entries for your review.",
                $app_id);
        }
        flashMessage('success', 'Revised entries submitted. The checker will continue reviewing your application.');
        echo "<script>window.location.href='index.php?page=dashboard';</script>"; exit;
    }
}
?>

<!-- â”€â”€ Page header â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€ -->
<?php include __DIR__ . '/_notif_banner_snippet.php'; ?>

<?php
// ── Application progress steps ──
$steps = [
    ['label'=>'Profile Complete',       'sublabel'=>'Name, rank, campus set',     'icon'=>'bi-person-check', 'done'=>!empty($_SESSION['full_name']) && !empty($faculty_rank),                                                            'link'=>null],
    ['label'=>'KRA Entries Added',      'sublabel'=>($total > 0 ? number_format($total,1).' pts total' : 'No entries yet'), 'icon'=>'bi-list-check', 'done'=>(isset($total) && $total > 0), 'link'=>'?page=apply&step=2'],
    ['label'=>'Submitted',              'sublabel'=>'Not yet submitted',          'icon'=>'bi-send',         'done'=>in_array($app['status'],['submitted','under_review','talisay_review','approved','reclassified','admin_rejected']),  'link'=>null],
    ['label'=>'Campus Review',          'sublabel'=>'Awaiting campus checkers',   'icon'=>'bi-search',       'done'=>in_array($app['status'],['talisay_review','approved','reclassified']),                                            'link'=>null],
    ['label'=>'Under Review by Talisay','sublabel'=>'Awaiting Talisay checkers',  'icon'=>'bi-building',     'done'=>in_array($app['status'],['approved','reclassified']),                                                              'link'=>null],
    ['label'=>'Approved',               'sublabel'=>'Pending',                    'icon'=>'bi-check-circle', 'done'=>$app['status']==='approved'||$app['status']==='reclassified',                                                      'link'=>null],
];
$current_step = 0;
foreach ($steps as $i => $s) { if ($s['done']) $current_step = $i + 1; }
?>
<div class="neon-card mb-3" style="padding:0;overflow:hidden;">

    <!-- Top: gradient header with greeting + deadline -->
    <div style="background:linear-gradient(135deg,#1a3a6b 0%,#1e4d8c 100%);
                padding:1.1rem 1.5rem;
                display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:1rem;">

        <!-- Left: avatar + name -->
        <div class="d-flex align-items-center gap-3">
            <div style="width:46px;height:46px;flex-shrink:0;">
                <?php
                $pic = $_SESSION['profile_pic'] ?? '';
                if ($pic): ?>
                <img src="<?= sanitize($pic) ?>" alt="Profile"
                     style="width:46px;height:46px;border-radius:50%;object-fit:cover;border:2px solid rgba(255,255,255,0.3);">
                <?php else:
                    $init_h = strtoupper(
                        substr($_SESSION['first_name'] ?? '', 0, 1) .
                        substr($_SESSION['last_name']  ?? '', 0, 1)
                    ) ?: 'U';
                ?>
                <div style="width:46px;height:46px;border-radius:50%;background:rgba(255,255,255,0.15);
                            border:2px solid rgba(255,255,255,0.3);
                            display:flex;align-items:center;justify-content:center;
                            font-size:0.95rem;font-weight:700;color:#fff;">
                    <?= $init_h ?>
                </div>
                <?php endif; ?>
            </div>
            <div>
                <div style="color:#fff;font-weight:700;font-size:0.95rem;line-height:1.2;">
                    Welcome back, <?= sanitize($_SESSION['full_name'] ?? '') ?>
                </div>
                <div style="color:rgba(255,255,255,0.6);font-size:0.73rem;margin-top:3px;">
                    <i class="bi bi-calendar3 me-1"></i><?= date('l, F j, Y') ?>
                    <?php if ($faculty_rank): ?>
                    &nbsp;&middot;&nbsp;<i class="bi bi-mortarboard me-1"></i><?= sanitize($faculty_rank) ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Right: cycle status pill -->
        <?php if (!$deadline_passed): ?>
        <div style="display:flex;align-items:center;gap:0.5rem;
                    background:rgba(255,255,255,0.1);border:1px solid rgba(255,255,255,0.15);
                    border-radius:20px;padding:0.35rem 0.85rem;">
            <i class="bi bi-circle-fill" style="color:#4ade80;font-size:0.55rem;"></i>
            <span style="font-size:0.78rem;color:rgba(255,255,255,0.9);font-weight:600;">Accepting submissions</span>
        </div>
        <?php else: ?>
        <div style="display:flex;align-items:center;gap:0.4rem;
                    background:rgba(30,77,140,0.25);border:1px solid rgba(255,255,255,0.2);
                    border-radius:20px;padding:0.35rem 0.85rem;">
            <i class="bi bi-pause-circle" style="color:rgba(255,255,255,0.7);font-size:0.85rem;"></i>
            <span style="font-size:0.78rem;color:rgba(255,255,255,0.7);font-weight:600;">Cycle closed</span>
        </div>
        <?php endif; ?>
    </div>

    <!-- Bottom: progress stepper -->
    <div style="padding:0.9rem 1.5rem 1.1rem;background:#fff;">
        <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:0.75rem;">
            <span style="font-size:0.7rem;font-weight:700;color:#64748b;text-transform:uppercase;letter-spacing:0.07em;">
                <i class="bi bi-signpost-split me-1"></i>Application Progress
                <?= helpBtn('Application Progress', 'This shows where your application is in the reclassification process. Complete all steps to reach "Approved". You must have a weighted score of at least 41.00 to submit.') ?>
            </span>
            <span style="font-size:0.7rem;color:#94a3b8;"><?= $current_step ?> of <?= count($steps) ?> steps completed</span>
        </div>
        <div class="d-flex align-items-start gap-0" id="progressStepper" style="position:relative;">
            <?php foreach ($steps as $i => $step):
                $is_done    = $step['done'];
                $is_current = ($i === $current_step) && !$is_done;
                $is_failed  = ($app['status'] === 'admin_rejected') && ($i === 4);
                $delay      = $i * 120; // ms stagger per step
            ?>
            <div class="progress-step" data-index="<?= $i ?>"
                 style="flex:1;min-width:70px;text-align:center;position:relative;padding:0 4px;
                        animation:stepFadeIn 0.4s ease both;
                        animation-delay:<?= $delay ?>ms;">

                <?php if ($i < count($steps) - 1): ?>
                <!-- connector line -->
                <div style="position:absolute;top:15px;left:calc(50% + 15px);right:calc(-50% + 15px);
                            height:2px;background:#e2e8f0;z-index:0;">
                    <?php if ($is_done): ?>
                    <div class="connector-fill" style="height:100%;background:#1a3a6b;width:100%;
                                animation:lineFill 0.5s ease forwards;
                                animation-delay:<?= $delay + 200 ?>ms;"></div>
                    <?php endif; ?>
                </div>
                <?php endif; ?>

                <!-- circle -->
                <div class="<?= $is_current ? 'step-pulse' : '' ?>"
                     style="width:30px;height:30px;border-radius:50%;margin:0 auto 5px;
                            display:flex;align-items:center;justify-content:center;position:relative;z-index:1;
                            background:<?= $is_failed ? '#1e293b' : ($is_done ? '#1a3a6b' : ($is_current ? '#eff6ff' : '#f8fafc')) ?>;
                            border:2px solid <?= $is_failed ? '#1e293b' : ($is_done ? '#1a3a6b' : ($is_current ? '#1a3a6b' : '#e2e8f0')) ?>;">
                    <?php if ($is_failed): ?>
                        <i class="bi bi-x-lg" style="color:#fff;font-size:0.8rem;"></i>
                    <?php elseif ($is_done): ?>
                        <i class="bi bi-check-lg" style="color:#fff;font-size:0.8rem;
                                animation:checkPop 0.3s cubic-bezier(0.34,1.56,0.64,1) forwards;
                                animation-delay:<?= $delay + 150 ?>ms;
                                display:inline-block;"></i>
                    <?php else: ?>
                        <i class="bi <?= $step['icon'] ?>" style="color:<?= $is_current ? '#1a3a6b' : '#cbd5e1' ?>;font-size:0.72rem;"></i>
                    <?php endif; ?>
                </div>

                <div style="font-size:0.68rem;font-weight:<?= $is_done||$is_current||$is_failed ? '700' : '400' ?>;
                            color:<?= $is_failed ? '#fff' : ($is_done ? '#1a3a6b' : ($is_current ? '#1e293b' : '#94a3b8')) ?>;">
                    <?php if ($step['link'] && !$is_done): ?>
                    <a href="<?= $step['link'] ?>" style="color:inherit;text-decoration:none;"><?= $step['label'] ?></a>
                    <?php else: ?><?= $step['label'] ?><?php endif; ?>
                </div>
                <div style="font-size:0.6rem;color:#cbd5e1;margin-top:1px;"><?= $step['sublabel'] ?></div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
</div>


<!-- â”€â”€ My Campus + Weighted Score + KRA Overview row â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€ -->
<div class="row g-3 mb-4">

    <!-- My Campus -->
    <div class="col-lg-4">
        <div class="neon-card h-100 d-flex flex-column" style="padding:1.25rem;">

            <!-- Title -->
            <div class="mb-3 pb-2" style="border-bottom:1px solid #f1f5f9;">
                <div class="d-flex align-items-center justify-content-between">
                    <div>
                        <div class="fw-semibold" style="color:#1a3a6b;font-size:0.85rem;letter-spacing:0.02em;">
                            MY CAMPUS
                        </div>
                        <div class="text-muted" style="font-size:0.7rem;margin-top:2px;">
                            <?= sanitize($my_campus_name ?: '&mdash;') ?>
                        </div>
                    </div>
                    <?php
                    $col_submitted = count(array_filter($colleagues, fn($c) => in_array($c['app_status'] ?? '', ['submitted','under_review','needs_revision'])));
                    if ($col_submitted > 0): ?>
                    <span style="font-size:0.65rem;font-weight:600;color:#1e4d8c;
                                 background:#f0f4fb;border:1px solid #bfdbfe;
                                 padding:2px 8px;border-radius:20px;">
                        <?= $col_submitted ?> submitted
                    </span>
                    <?php endif; ?>
                </div>
            </div>

            <?php if ($colleagues): ?>
            <div class="flex-grow-1" style="overflow-y:auto;max-height:240px;">
                <?php
                $avatar_colors = ['#1e4d8c','#1a3a6b','#2d4a6b','#334155','#1e4d8c','#475569','#1a3a6b','#334155'];
                $shown = 0;
                foreach ($colleagues as $col):
                    $s = $col['app_status'] ?? '';
                    if (in_array($s, ['approved','rejected','admin_rejected'])) continue;
                    $shown++;
                    [$slabel, $scolor] = match($s) {
                        'submitted','under_review','needs_revision' => ['Submitted', '#1e4d8c'],
                        default => ['Draft', '#94a3b8'],
                    };
                    // Status dot: green = submitted/active, grey = draft/inactive
                    $dot_color = in_array($s, ['submitted','under_review','needs_revision']) ? '#1e4d8c' : '#cbd5e1';
                    $initials = strtoupper(
                        substr(trim($col['first_name'] ?? ''), 0, 1) .
                        substr(trim($col['last_name']  ?? ''), 0, 1)
                    ) ?: '?';
                    $bg       = $avatar_colors[$col['user_id'] % count($avatar_colors)];
                ?>
                <div class="d-flex align-items-center gap-3 py-2" style="border-bottom:1px solid #f8fafc;">
                    <div style="position:relative;flex-shrink:0;">
                        <div class="colleague-avatar"
                             style="width:40px;height:40px;background:<?= $bg ?>;
                                    display:flex;align-items:center;justify-content:center;
                                    font-size:0.82rem;font-weight:600;color:#fff;">
                            <?= $initials ?>
                        </div>
                        <span class="colleague-status-dot" style="background:<?= $dot_color ?>;"></span>
                    </div>
                    <div class="flex-grow-1 min-w-0">
                        <div class="fw-semibold text-truncate" style="color:#1e293b;font-size:0.83rem;">
                            <?= htmlspecialchars(formatDisplayName($col)) ?>
                        </div>
                        <div class="text-muted text-truncate" style="font-size:0.68rem;">
                            <?= sanitize($col['rank'] ?? '&mdash;') ?>
                        </div>
                    </div>
                    <span style="font-size:0.62rem;font-weight:500;color:<?= $scolor ?>;
                                 white-space:nowrap;flex-shrink:0;">
                        <?= $slabel ?>
                    </span>
                </div>
                <?php endforeach; ?>
                <?php if ($shown === 0): ?>
                <p class="text-muted small text-center py-3 mb-0">All colleagues have completed their applications.</p>
                <?php endif; ?>
            </div>

            <?php elseif (!$my_campus_id): ?>
            <div class="text-center py-4 flex-grow-1 d-flex flex-column align-items-center justify-content-center">
                <p class="text-muted small mb-2">No campus assigned to your profile.</p>
                <a href="?page=profile" class="btn btn-xs btn-outline-primary">Update Profile</a>
            </div>
            <?php else: ?>
            <p class="text-muted small text-center py-4 mb-0">No other active faculty at your campus yet.</p>
            <?php endif; ?>

        </div>
    </div>

    <!-- Weighted Score -->
    <div class="col-lg-4">
        <div class="neon-card h-100 d-flex flex-column" style="padding:1.25rem;">

            <?php
            $bar_color = '#1a3a6b';
            $bar_pct   = min(100, max(0, $weighted));
            ?>

            <!-- Title -->
            <div class="mb-3 pb-2" style="border-bottom:1px solid #f1f5f9;">
                <div class="fw-semibold" style="color:#1a3a6b;font-size:0.85rem;letter-spacing:0.02em;">
                    WEIGHTED SCORE
                </div>
                <?php if ($faculty_rank): ?>
                <div class="text-muted" style="font-size:0.7rem;margin-top:2px;">
                    <?= sanitize($faculty_rank) ?>
                </div>
                <?php endif; ?>
            </div>

            <!-- Score number -->
            <div class="mb-3">
                <div style="font-size:2.8rem;font-weight:700;line-height:1;color:#1e293b;letter-spacing:-1px;">
                    <?= number_format($weighted, 2) ?>
                    <span style="font-size:1rem;font-weight:400;color:#94a3b8;letter-spacing:0;">/ 100</span>
                </div>
            </div>

            <!-- Progress bar -->
            <div style="position:relative;height:6px;background:#f1f5f9;border-radius:99px;margin-bottom:0.35rem;">
                <div style="height:100%;width:<?= $bar_pct ?>%;background:<?= $bar_color ?>;border-radius:99px;"></div>
                <div style="position:absolute;top:-4px;left:41%;width:1.5px;height:14px;
                            background:#94a3b8;border-radius:1px;" title="Minimum: 41"></div>
            </div>
            <div class="d-flex justify-content-between mb-3" style="font-size:0.6rem;color:#94a3b8;">
                <span>0</span><span>min 41</span><span>100</span>
            </div>

            <!-- Sub-rank row -->
            <div class="d-flex align-items-center gap-2 mb-2 flex-wrap">
                <?php if ($inc > 0): ?>
                <span style="font-size:0.75rem;font-weight:600;color:#1e4d8c;">
                    +<?= $inc ?> sub-rank<?= $inc > 1 ? 's' : '' ?>
                </span>
                <?php else: ?>
                <span style="font-size:0.75rem;font-weight:600;color:#64748b;">
                    Below minimum
                </span>
                <?php endif; ?>
                <span style="color:#e2e8f0;">&middot;</span>
                <?php if ($next_bracket_gap !== null && $weighted > 0): ?>
                <span style="font-size:0.72rem;color:#64748b;">
                    <?php if ($inc > 0): ?>
                    <?= $next_bracket_gap ?> pts more â†’ +<?= $next_bracket_inc ?>
                    <?php else: ?>
                    <?= $next_bracket_gap ?> pts needed
                    <?php endif; ?>
                </span>
                <?php endif; ?>
            </div>

            <!-- Award bonus -->
            <?php if (($score_result['award_bonus'] ?? 0) > 0): ?>
            <div class="mb-2" style="font-size:0.7rem;color:#64748b;">
                <i class="bi bi-patch-check me-1"></i>National/Intl Award bonus: +1 sub-rank included
            </div>
            <?php endif; ?>

            <!-- Submit button -->
            <?php if ($app['status'] === 'draft' && !$deadline_passed): ?>
            <div class="mt-auto pt-2" style="border-top:1px solid #f1f5f9;">
                <?php if ($can_submit): ?>
                <form method="POST" id="submitApplicationForm">
                    <input type="hidden" name="action" value="submit_application">
                    <button type="button" class="btn btn-primary btn-sm w-100"
                            onclick="confirmDelete('Submit your application for checker review?','submitApplicationForm','Submit','bi-send')">
                        <i class="bi bi-send me-1"></i>Submit for Review
                    </button>
                </form>
                <?php elseif ($total === 0): ?>
                <a href="?page=apply&step=2" class="btn btn-sm btn-outline-primary w-100">
                    <i class="bi bi-pencil me-1"></i>Enter KRA Scores First
                </a>
                <?php else: ?>
                <p class="text-danger small mb-0 text-center">Score too low to submit</p>
                <?php endif; ?>
            </div>
            <?php endif; ?>

        </div>
    </div>

    <!-- KRA overview chart -->
    <div class="col-lg-4">
        <div class="neon-card h-100 d-flex flex-column" style="padding:1.25rem;">

            <!-- Title -->
            <div class="mb-3 pb-2" style="border-bottom:1px solid #f1f5f9;">
                <div class="fw-semibold" style="color:#1a3a6b;font-size:0.85rem;letter-spacing:0.02em;">
                    KRA OVERVIEW
                </div>
                <div class="text-muted" style="font-size:0.7rem;margin-top:2px;">
                    Your scores vs maximum per area
                </div>
            </div>

            <div class="flex-grow-1" style="min-height:140px;max-height:160px;">
                <canvas id="kraOverviewChart"></canvas>
            </div>

            <!-- Legend -->
            <div class="d-flex flex-wrap gap-3 mt-3 pt-2" style="border-top:1px solid #f1f5f9;">
                <?php foreach ($kra_info as $kra => $info): ?>
                <div style="font-size:0.65rem;color:#64748b;">
                    <span style="display:inline-block;width:8px;height:8px;border-radius:2px;
                                 background:<?= $info['color'] ?>;margin-right:4px;vertical-align:middle;"></span>
                    <?= $info['short'] ?>
                    <strong style="color:#1e293b;"><?= number_format($kra_map[$kra]['pts'],1) ?></strong>
                </div>
                <?php endforeach; ?>
            </div>

        </div>
    </div>
</div>

<!-- â”€â”€ KRA scores &mdash; compact horizontal bar chart â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€ -->
<div class="neon-card mb-4">
    <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
        <div>
            <h6 class="mb-0" style="color:var(--blue-dark);">
                <i class="bi bi-bar-chart-steps me-2"></i>KRA Scores
            </h6>
            <small class="text-muted">Your points per area vs maximum</small>
        </div>
        <?php if ($can_edit): ?>
        <a href="?page=apply&step=2" class="btn btn-xs btn-outline-primary">
            <i class="bi bi-pencil me-1"></i>Edit KRA Entries
        </a>
        <?php endif; ?>
    </div>

    <div class="d-flex flex-column gap-3">
        <?php foreach ($kra_map as $kra => $data):
            $info     = $kra_info[$kra];
            $pts      = $data['pts'];
            $verified = $data['verified'];
            $pct      = $info['max'] > 0 ? min(100, ($pts / $info['max']) * 100) : 0;
            $empty    = $pts == 0;
        ?>
        <div>
            <!-- Label row -->
            <div class="d-flex justify-content-between align-items-center mb-1">
                <div class="d-flex align-items-center gap-2">
                    <i class="bi <?= $info['icon'] ?>" style="font-size:0.85rem;color:<?= $empty ? '#cbd5e1' : $info['color'] ?>;"></i>
                    <span style="font-size:0.8rem;font-weight:600;color:<?= $empty ? '#94a3b8' : '#1e293b' ?>;">
                        <?= $info['short'] ?> &mdash; <?= $kra ?>
                    </span>
                    <?php if ($verified): ?>
                    <span class="badge bg-success" style="font-size:0.58rem;padding:2px 6px;">
                        <i class="bi bi-patch-check"></i> Verified
                    </span>
                    <?php elseif (!$empty): ?>
                    <span class="badge bg-secondary" style="font-size:0.58rem;padding:2px 6px;">Unverified</span>
                    <?php endif; ?>
                </div>
                <div class="d-flex align-items-center gap-2">
                    <span style="font-size:0.8rem;font-weight:700;color:<?= $empty ? '#94a3b8' : $info['color'] ?>;">
                        <?= number_format($pts, 1) ?>
                        <span style="font-weight:400;color:#94a3b8;font-size:0.72rem;">/ <?= $info['max'] ?></span>
                    </span>
                    <?php if ($can_edit): ?>
                    <a href="?page=apply&step=2&tab=<?= $info['tab'] ?>"
                       style="font-size:0.68rem;color:<?= $info['color'] ?>;text-decoration:none;white-space:nowrap;">
                        <?= $empty ? '+ Add' : 'Edit' ?>
                    </a>
                    <?php endif; ?>
                </div>
            </div>
            <!-- Bar -->
            <div style="height:10px;background:#f1f5f9;border-radius:99px;overflow:hidden;position:relative;">
                <div style="height:100%;width:<?= $pct ?>%;
                            background:<?= $empty ? '#e2e8f0' : $info['color'] ?>;
                            border-radius:99px;
                            transition:width 0.6s ease;
                            <?= !$empty ? 'box-shadow:0 0 6px '.$info['color'].'55;' : '' ?>">
                </div>
                <!-- Minimum threshold marker at 41% -->
                <?php if ($kra === 'Instruction'): // weighted threshold only meaningful on final score ?>
                <?php endif; ?>
            </div>
            <div class="d-flex justify-content-between mt-1">
                <span style="font-size:0.62rem;color:#94a3b8;"><?= $empty ? 'No entries yet' : number_format($pct, 0).'% of max' ?></span>
                <?php if (!$empty && $pct < 100): ?>
                <span style="font-size:0.62rem;color:#94a3b8;"><?= number_format($info['max'] - $pts, 1) ?> pts to max</span>
                <?php elseif ($pct >= 100): ?>
                <span style="font-size:0.62rem;color:#1e4d8c;font-weight:600;">Maxed out &#10004;</span>
                <?php endif; ?>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
</div>

<!-- â”€â”€ Score computation table â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€ -->
<?php if ($total > 0): ?>
<div class="neon-card mb-4">
    <h6 class="mb-3" style="color:var(--blue-dark);">
        <i class="bi bi-calculator me-2"></i>Score Breakdown
        <span class="text-muted fw-normal" style="font-size:0.78rem;">
            &mdash; weights based on <em><?= sanitize($faculty_rank ?: 'rank not set') ?></em>
        </span>
    </h6>
    <div class="table-responsive">
        <table style="width:100%;border-collapse:collapse;font-size:0.85rem;margin-bottom:0.5rem;">
            <thead>
                <tr style="background:#f1f5f9;">
                    <th style="color:#1a3a6b;">KRA</th>
                    <th class="text-center" style="color:#1a3a6b;">Raw</th>
                    <th class="text-center" style="color:#1a3a6b;">Capped</th>
                    <th class="text-center" style="color:#1a3a6b;">Weight</th>
                    <th class="text-center" style="color:#1a3a6b;">Weighted</th>
                    <th class="text-center" style="color:#1a3a6b;">Verified</th>
                </tr>
            </thead>
            <tbody>
            <?php
            $kra_labels = ['Instruction'=>'KRA I &mdash; Instruction','Research'=>'KRA II &mdash; Research','Extension'=>'KRA III &mdash; Extension','Professional Development'=>'KRA IV &mdash; Prof. Development'];
            $kra_capped = ['Instruction'=>$score_result['kra1'],'Research'=>$score_result['kra2'],'Extension'=>$score_result['kra3'],'Professional Development'=>$score_result['kra4']];
            foreach ($kra_labels as $key => $label):
                $raw  = $kra_map[$key]['pts'];
                $cap  = $kra_capped[$key];
                $w    = $weights[$key];
                $wval = round($cap * $w, 2);
                $ver  = $kra_map[$key]['verified'];
            ?>
            <tr>
                <td><?= $label ?></td>
                <td class="text-center"><?= number_format($raw, 2) ?></td>
                <td class="text-center"><?= number_format($cap, 2) ?></td>
                <td class="text-center"><?= ($w * 100) ?>%</td>
                <td class="text-center fw-bold"><?= number_format($wval, 2) ?></td>
                <td class="text-center">
                    <?php if ($ver): ?>
                    <span class="badge bg-success" style="font-size:0.65rem;"><i class="bi bi-check"></i></span>
                    <?php else: ?>
                    <span class="badge bg-secondary" style="font-size:0.65rem;">&mdash;</span>
                    <?php endif; ?>
                </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
            <tfoot>
                <tr style="background:#f0f4fb;font-weight:700;">
                    <td colspan="4" style="color:#1a3a6b;">Final Weighted Score</td>
                    <td class="text-center" style="color:#1a3a6b;font-size:1rem;"><?= number_format($weighted, 2) ?></td>
                    <td></td>
                </tr>
            </tfoot>
        </table>
    </div>
    <!-- Sub-rank summary -->
    <div class="d-flex align-items-center gap-3 flex-wrap mt-1">
        <div>
            <span class="text-muted small">Sub-rank increment: </span>
            <span class="badge <?= $inc > 0 ? 'bg-success' : 'bg-secondary' ?> ms-1">
                <?= $inc > 0 ? "+{$inc} sub-rank" . ($inc > 1 ? 's' : '') : 'No reclassification (below 41)' ?>
            </span>
            <?php if (($score_result['award_bonus'] ?? 0) > 0): ?>
            <span class="badge ms-1" style="background:#1a3a6b;font-size:0.7rem;">
                <i class="bi bi-award-fill me-1"></i>+1 National/Intl Award bonus included
            </span>
            <?php endif; ?>
        </div>
        <?php if ($inc > 0): ?>
        <div class="text-muted small">
            <?php foreach ($brackets as [$lo,$hi,$r]) { if ($r === ($inc - ($score_result['award_bonus'] ?? 0))) { echo "Score {$lo}&ndash;{$hi} â†’ +{$r} sub-rank" . ($r>1?'s':''); break; } } ?>
        </div>
        <?php endif; ?>
    </div>
</div>
<?php endif; ?>

<!-- â”€â”€ Chart JS â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€ -->
<script>
document.addEventListener('DOMContentLoaded', function () {
    <?php
    // Weighted contribution per KRA = capped_score * weight
    $kra_capped = [
        'Instruction'              => $score_result['kra1'],
        'Research'                 => $score_result['kra2'],
        'Extension'                => $score_result['kra3'],
        'Professional Development' => $score_result['kra4'],
    ];
    $contributions = [];
    foreach ($kra_capped as $cat => $cap) {
        $contributions[] = round($cap * $weights[$cat], 2);
    }
    $has_any = array_sum($contributions) > 0;
    ?>

    <?php if ($has_any): ?>
    new Chart(document.getElementById('kraOverviewChart'), {
        type: 'doughnut',
        data: {
            labels: ['KRA I &mdash; Instruction', 'KRA II &mdash; Research', 'KRA III &mdash; Extension', 'KRA IV &mdash; Prof Dev'],
            datasets: [{
                data: <?= json_encode($contributions) ?>,
                backgroundColor: ['#1a3a6b','#334155','#475569','#1e4d8c'],
                borderWidth: 2,
                borderColor: '#fff',
                hoverOffset: 6
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            cutout: '65%',
            plugins: {
                legend: { display: false },
                tooltip: {
                    callbacks: {
                        label: ctx => ` ${ctx.label}: ${ctx.parsed} pts (weighted)`
                    }
                }
            }
        },
        plugins: [{
            id: 'centerText',
            beforeDraw(chart) {
                const { ctx, chartArea: { width, height, left, top } } = chart;
                ctx.save();
                const cx = left + width / 2;
                const cy = top + height / 2;
                ctx.textAlign = 'center';
                ctx.textBaseline = 'middle';
                ctx.font = 'bold 1.4rem sans-serif';
                ctx.fillStyle = '#1e293b';
                ctx.fillText('<?= number_format($weighted, 1) ?>', cx, cy - 8);
                ctx.font = '0.65rem sans-serif';
                ctx.fillStyle = '#94a3b8';
                ctx.fillText('weighted', cx, cy + 10);
                ctx.restore();
            }
        }]
    });
    <?php else: ?>
    // No data yet &mdash; show empty state text on canvas
    const canvas = document.getElementById('kraOverviewChart');
    const ctx2 = canvas.getContext('2d');
    canvas.height = 180;
    ctx2.textAlign = 'center';
    ctx2.textBaseline = 'middle';
    ctx2.font = '0.8rem sans-serif';
    ctx2.fillStyle = '#94a3b8';
    ctx2.fillText('No KRA entries yet', canvas.width / 2, canvas.height / 2);
    <?php endif; ?>
});
</script>
