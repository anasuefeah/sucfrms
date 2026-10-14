<?php
// Request handling and data loading for frontend/views/faculty/dashboard.php
$uid   = $_SESSION['user_id'];
$cycle = getActiveCycle($pdo);

if (!$cycle) {
    $last_cycle = $pdo->query("SELECT * FROM cycles ORDER BY created_at DESC LIMIT 1")->fetch();
    echo '
    <div class="neon-card text-center py-12">
        <i class="bi bi-calendar-x" style="font-size:2.5rem;color:#cbd5e1;display:block;margin-bottom:0.75rem;"></i>
        <h5 class="fw-bold mb-2" style="color:#1a3a6b;">No Active Reclassification Cycle</h5>
        <p class="text-muted mb-1">There is currently no open cycle. Your account is ready.</p>
        <p class="text-muted small">When the next cycle opens, your application will be available here automatically.</p>'
        . ($last_cycle ? '<p class="text-muted small mt-2">Last cycle: <strong>' . htmlspecialchars($last_cycle['cycle_name']) . '</strong> (' . ucfirst($last_cycle['status']) . ')</p>' : '')
        . '</div>';
    return false;
}

$app    = getOrCreateApplication($pdo, $uid, $cycle['cycle_id']);
$app_id = $app['application_id'];

// -- KRA scores ------------------------------------------------
$score_result = getApplicationScoreSummary($pdo, (int)$app_id);
$kra_map      = $score_result['kra_map'];
$total        = $score_result['grand_total'];

// -- Score computation -----------------------------------------
$faculty_row  = $pdo->prepare("SELECT rank, full_name FROM users WHERE user_id=?");
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

// -- Colleagues from same campus -------------------------------
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
          AND u.role      = 'faculty'
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
        'under_review'   => ['Under Evaluation','#1a3a6b'],
        'needs_revision' => ['Needs Revision',  '#475569'],
        'approved'       => ['Evaluation Complete', '#1e4d8c'],
        'rejected'       => ['Returned for Revision', '#64748b'],
        'admin_rejected' => ['Evaluation Complete', '#64748b'],
        default          => ['No Application',  '#cbd5e1'],
    };
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
    'Instruction'              => ['icon'=>'bi-book',        'color'=>'#1e4d8c','max'=>100, 'short'=>'KRA I',  'tab'=>'instruction'],
    'Research'                 => ['icon'=>'bi-journal-text','color'=>'#1a3a6b','max'=>100, 'short'=>'KRA II', 'tab'=>'research'],
    'Extension'                => ['icon'=>'bi-people',      'color'=>'#1a5276','max'=>120, 'short'=>'KRA III','tab'=>'extension'],
    'Professional Development' => ['icon'=>'bi-award',       'color'=>'#1e4d8c','max'=>120, 'short'=>'KRA IV', 'tab'=>'profdev'],
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
        try { recalcApplicationScore($pdo, $app_id); } catch (\Exception $e) {}
        $new_status = !empty($app['checker_id']) ? 'under_review' : 'submitted';
        $pdo->prepare("UPDATE applications SET status=?, submitted_at=NOW() WHERE application_id=?")->execute([$new_status, $app_id]);
        logAudit($pdo, $uid, 'Application Submitted', "Application #{$app_id} submitted for {$cycle['cycle_name']}.");
        // Notify all active Subcommittee reviewers of new submission
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
        try { recalcApplicationScore($pdo, $app_id); } catch (\Exception $e) {}
        $pdo->prepare("UPDATE kra_submissions SET revision_status='ok', revision_note=NULL, revision_by=NULL, revision_at=NULL WHERE application_id=?")->execute([$app_id]);
        $pdo->prepare("UPDATE applications SET status='under_review', checker_remarks=NULL WHERE application_id=?")->execute([$app_id]);
        logAudit($pdo, $uid, 'Revision Resubmitted', "Faculty resubmitted revised entries for Application #{$app_id}");
        // Notify Subcommittee reviewers of revision compliance
        $campus_checkers = $pdo->query("SELECT user_id FROM users WHERE role = 'evaluator' AND status='active'")->fetchAll(PDO::FETCH_COLUMN);
        foreach ($campus_checkers as $cid) {
            createNotif($pdo, (int)$cid, 'revision_resubmitted',
                ($_SESSION['full_name'] ?? 'A faculty member') . " has resubmitted the revised KRA entries for your review.",
                $app_id);
        }
        flashMessage('success', 'Revised entries submitted. The evaluator will continue reviewing your application.');
        echo "<script>window.location.href='index.php?page=dashboard';</script>"; exit;
    }
}
