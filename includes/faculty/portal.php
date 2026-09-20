<?php
/**
 * Faculty Portal — clean SFPRS-style overview shown after login.
 */

$uid = $_SESSION['user_id'];

// ── Faculty profile ──────────────────────────────────────────
$faculty = $pdo->prepare("
    SELECT u.first_name, u.middle_name, u.last_name, u.full_name,
           u.email, u.employee_id, u.rank, u.profile_pic,
           c.campus_name
    FROM   users u
    LEFT JOIN campuses c ON u.campus_id = c.campus_id
    WHERE  u.user_id = ?
");
$faculty->execute([$uid]);
$faculty = $faculty->fetch();

$full_name = formatDisplayName($faculty);
$first_name  = $faculty['first_name']  ?? '';
$last_name   = $faculty['last_name']   ?? '';
$rank        = $faculty['rank']        ?? '—';
$campus      = $faculty['campus_name'] ?? '—';
$email       = $faculty['email']       ?? '—';
$employee_id = $faculty['employee_id'] ?? '—';
$profile_pic = $faculty['profile_pic'] ?? '';

// ── Initials ─────────────────────────────────────────────────
$init = strtoupper(substr($first_name, 0, 1) . substr($last_name, 0, 1)) ?: 'FA';

// ── Active cycle + application ───────────────────────────────
$cycle = getActiveCycle($pdo);

$current_app = null;
if ($cycle) {
    $app_row = getOrCreateApplication($pdo, $uid, $cycle['cycle_id']);
    $re = $pdo->prepare("
        SELECT a.*, c.cycle_name, c.start_date, c.end_date, c.submission_deadline
        FROM applications a
        LEFT JOIN cycles c ON a.cycle_id = c.cycle_id
        WHERE a.application_id = ?
    ");
    $re->execute([$app_row['application_id']]);
    $current_app = $re->fetch();
}

// Past applications (excluding current)
$past_stmt = $pdo->prepare("
    SELECT a.application_id, a.tracking_number, a.status,
           a.weighted_score, a.submitted_at,
           c.cycle_name
    FROM   applications a
    LEFT JOIN cycles c ON a.cycle_id = c.cycle_id
    WHERE  a.user_id = ?
    " . ($current_app ? "AND a.application_id != {$current_app['application_id']}" : "") . "
    ORDER BY a.created_at DESC
");
$past_stmt->execute([$uid]);
$past_apps = $past_stmt->fetchAll();

// ── Deadline helpers ─────────────────────────────────────────
$deadline_str    = '';
$days_left       = null;
$deadline_passed = false;
if ($current_app && !empty($current_app['submission_deadline'])) {
    $dl_ts           = strtotime($current_app['submission_deadline'] . ' 23:59:59');
    $deadline_passed = time() > $dl_ts;
    $deadline_str    = date('M d, Y', strtotime($current_app['submission_deadline']));
    $days_left       = max(0, (int)ceil(($dl_ts - time()) / 86400));
}

// ── Status pill helper ───────────────────────────────────────
function portalStatus(string $s): array {
    return match($s) {
        'submitted'      => ['Submitted',      '#2563b0', '#eff6ff', '#bfdbfe'],
        'under_review'   => ['Under Review',   '#1a3a6b', '#f0f4fb', '#bfdbfe'],
        'talisay_review' => ['Talisay Review', '#1a3a6b', '#f0f4fb', '#bfdbfe'],
        'needs_revision' => ['Needs Revision', '#475569', '#f8fafc', '#e2e8f0'],
        'approved'       => ['Approved',       '#1e4d8c', '#f0f4fb', '#bfdbfe'],
        'reclassified'   => ['Reclassified',   '#1e4d8c', '#f0fdfa', '#99f6e4'],
        'rejected',
        'admin_rejected' => ['Returned',       '#1e293b', '#f8fafc', '#e2e8f0'],
        default          => ['Pending',        '#475569', '#f8fafc', '#e2e8f0'],
    };
}
?>


<div class="portal-wrap">

    <!-- ── Top header banner ── -->
    <div class="portal-header">
        <img src="assets/images/logo.jpg" alt="Logo" class="portal-header-logo">
        <div class="portal-header-body">
            <h2>SUC Faculty Position Reclassification System</h2>
            <p>Republic of the Philippines &nbsp;&middot;&nbsp; Commission on Higher Education</p>
            <p>DBM-CHED Joint Circular No. 3, s. 2022 &nbsp;&middot;&nbsp; <?= sanitize($campus) ?></p>
        </div>
        <div class="portal-header-user">
            <span class="portal-header-user-name"><?= sanitize($full_name) ?></span>
            <?php if ($profile_pic): ?>
            <img src="<?= sanitize($profile_pic) ?>" alt="Avatar" class="portal-header-avatar">
            <?php else: ?>
            <div class="portal-header-avatar-ph"><?= $init ?></div>
            <?php endif; ?>
        </div>
    </div>

    <!-- ── Section title ── -->
    <div class="portal-section-title">
        <i class="bi bi-grid-1x2"></i>
        Applications for Evaluation
    </div>

    <?php if (!$cycle && empty($past_apps)): ?>
    <!-- Empty state -->
    <div class="portal-empty">
        <i class="bi bi-calendar-x" style="font-size:2.5rem;color:#cbd5e1;display:block;margin-bottom:0.75rem;"></i>
        <div style="font-weight:700;color:#1a3a6b;font-size:1rem;margin-bottom:0.35rem;">No Active Reclassification Cycle</div>
        <p style="color:#64748b;font-size:0.85rem;margin:0;">There is currently no open cycle. Check back when the next cycle opens.</p>
    </div>

    <?php else: ?>

    <!-- ── Current application card ── -->
    <?php if ($current_app):
        [$slabel, $scolor, $sbg, $sborder] = portalStatus($current_app['status'] ?? 'draft');
        $score    = (float)($current_app['weighted_score'] ?? 0);
        $potential = $current_app['potential_rank'] ?? '';
        $step_link = ($current_app['status'] === 'draft' && $score == 0)
            ? 'index.php?page=apply'
            : 'index.php?page=apply';
    ?>
    <div class="portal-card">

        <!-- Header row: logo + status + days -->
        <div class="portal-card-header">
            <div class="portal-card-header-left">
                <img src="assets/images/logo.jpg" alt="Logo" class="portal-card-logo">
                <div>
                    <div style="font-size:0.82rem;color:#94a3b8;font-weight:500;">
                        <?= sanitize($current_app['cycle_name'] ?? 'Current Application') ?>
                    </div>
                    <div class="portal-card-id">Application for Reclassification</div>
                </div>
            </div>
            <div class="portal-card-status">
                <span class="portal-status-pill"
                      style="color:<?= $scolor ?>;background:<?= $sbg ?>;border-color:<?= $sborder ?>;">
                    <span style="width:6px;height:6px;border-radius:50%;background:<?= $scolor ?>;display:inline-block;flex-shrink:0;"></span>
                    <?= $slabel ?>
                </span>
                <?php if ($days_left !== null && !$deadline_passed): ?>
                <span class="portal-days-left" style="color:<?= $days_left <= 7 ? '#334155' : '#94a3b8' ?>">
                    <?= $days_left ?>d left
                </span>
                <?php elseif ($deadline_passed): ?>
                <span class="portal-days-left" style="color:#334155;">Deadline passed</span>
                <?php endif; ?>
            </div>
        </div>

        <!-- Current rank line -->
        <div class="portal-rank-line">
            <?= sanitize($rank) ?>
            <?php if ($potential && $potential !== $rank && $potential !== '—'): ?>
            <span style="color:#94a3b8;margin:0 0.4rem;">→</span>
            <span style="color:#1e4d8c;font-weight:600;"><?= sanitize($potential) ?></span>
            <span style="font-size:0.68rem;color:#1e4d8c;background:#f0f4fb;border:1px solid #bfdbfe;
                         border-radius:4px;padding:1px 5px;margin-left:4px;font-weight:600;">Potential</span>
            <?php endif; ?>
        </div>

        <!-- Info grid -->
        <div class="portal-info-grid">
            <div class="portal-info-row">
                <div class="portal-info-label">Applicant Name</div>
                <div class="portal-info-value"><?= sanitize($full_name) ?></div>
            </div>
            <div class="portal-info-row">
                <div class="portal-info-label">Employee ID</div>
                <div class="portal-info-value"><?= sanitize($employee_id) ?></div>
            </div>
            <div class="portal-info-row">
                <div class="portal-info-label">Present Rank</div>
                <div class="portal-info-value"><?= sanitize($rank) ?></div>
            </div>
            <div class="portal-info-row">
                <div class="portal-info-label">Campus / HEI</div>
                <div class="portal-info-value"><?= sanitize($campus) ?></div>
            </div>
            <div class="portal-info-row">
                <div class="portal-info-label">Cycle</div>
                <div class="portal-info-value"><?= sanitize($current_app['cycle_name'] ?? '—') ?></div>
            </div>
            <div class="portal-info-row">
                <div class="portal-info-label">Submission Deadline</div>
                <div class="portal-info-value" style="color:<?= $deadline_passed ? '#334155' : 'inherit' ?>">
                    <?= $deadline_str ?: '—' ?>
                </div>
            </div>
            <div class="portal-info-row full">
                <div class="portal-info-label">Email Address</div>
                <div class="portal-info-value" style="color:#1e4d8c;"><?= sanitize($email) ?></div>
            </div>
        </div>

        <!-- Footer: score + Reclassification button -->
        <div class="portal-card-footer">
            <?php if ($score > 0): ?>
            <div class="portal-score-info has-score">
                <i class="bi bi-bar-chart-fill"></i>
                Weighted Score:
                <span class="portal-score-num" style="color:<?= $score >= 41 ? '#1e4d8c' : '#334155' ?>">
                    <?= number_format($score, 2) ?>
                </span>
                <span style="color:#94a3b8;font-size:0.72rem;">/ 100</span>
            </div>
            <?php else: ?>
            <div class="portal-score-info">
                <i class="bi bi-info-circle"></i>
                No KRA entries yet
            </div>
            <?php endif; ?>

            <a href="<?= $step_link ?>" class="portal-reclass-btn">
                <i class="bi bi-pencil-square"></i>
                Reclassification
            </a>
        </div>
    </div>
    <?php endif; ?>

    <!-- ── Past applications ── -->
    <?php if (!empty($past_apps)): ?>
    <div style="font-size:0.7rem;font-weight:700;text-transform:uppercase;letter-spacing:0.07em;
                color:#94a3b8;margin:1.25rem 0 0.6rem;">Previous Applications</div>
    <?php foreach ($past_apps as $pa):
        [$plabel, $pcolor, $pbg, $pbd] = portalStatus($pa['status'] ?? 'draft');
    ?>
    <div class="portal-past-item">
        <div>
            <div style="font-size:0.82rem;font-weight:600;color:#1e293b;">
                <?= sanitize($pa['cycle_name'] ?? '—') ?>
            </div>
            <div style="font-size:0.72rem;color:#94a3b8;margin-top:2px;">
                <?php if ($pa['submitted_at']): ?>
                Submitted <?= date('M j, Y', strtotime($pa['submitted_at'])) ?>
                <?php else: ?>&mdash;<?php endif; ?>
            </div>
        </div>
        <div style="display:flex;align-items:center;gap:0.65rem;">
            <?php if ((float)($pa['weighted_score'] ?? 0) > 0): ?>
            <span style="font-size:0.8rem;font-weight:700;color:#1e4d8c;">
                <?= number_format($pa['weighted_score'], 2) ?> pts
            </span>
            <?php endif; ?>
            <span class="portal-status-pill"
                  style="color:<?= $pcolor ?>;background:<?= $pbg ?>;border-color:<?= $pbd ?>;">
                <span style="width:6px;height:6px;border-radius:50%;background:<?= $pcolor ?>;display:inline-block;"></span>
                <?= $plabel ?>
            </span>
        </div>
    </div>
    <?php endforeach; ?>
    <?php endif; ?>

    <?php endif; ?>

    <!-- Navigation hint -->
    <div style="text-align:center;margin-top:1.5rem;">
        <a href="index.php?page=dashboard"
           style="font-size:0.75rem;color:#94a3b8;text-decoration:none;
                  display:inline-flex;align-items:center;gap:0.35rem;">
            <i class="bi bi-speedometer2"></i>Go to full dashboard
        </a>
    </div>

</div>
