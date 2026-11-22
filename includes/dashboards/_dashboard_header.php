<?php
/**
 * Shared dashboard header card — used by checker_dashboard.php and admin_dashboard.php.
 *
 * Expects the following variables to already exist in scope:
 *   $cycle  — result of getActiveCycle($pdo), may be null
 *   $pdo    — PDO instance
 * For evaluator role it reads rank/campus from the session/DB directly.
 */

$_dh_role      = $_SESSION['role'] ?? 'faculty';
$_dh_avatar_radius = $_dh_role === 'admin' ? '0' : '50%';
$_dh_uid       = (int)($_SESSION['user_id'] ?? 0);
$_dh_pic       = $_SESSION['profile_pic'] ?? '';
$_dh_first     = $_SESSION['first_name'] ?? '';
$_dh_last      = $_SESSION['last_name']  ?? '';
$_dh_initials  = strtoupper(substr($_dh_first, 0, 1) . substr($_dh_last, 0, 1)) ?: 'U';
$_dh_fullname  = sanitize($_SESSION['full_name'] ?? 'User');

// Rank + campus (evaluator has a profile row; admin uses role label)
$_dh_meta_parts = [];
if ($_dh_role === 'evaluator') {
    $row = $pdo->prepare("SELECT u.`rank`, c.campus_name
        FROM users u
        LEFT JOIN campuses c ON u.campus_id = c.campus_id
        WHERE u.user_id = ?");
    $row->execute([$_dh_uid]);
    $row = $row->fetch();
    if (!empty($row['rank']))       $_dh_meta_parts[] = sanitize($row['rank']);
    if (!empty($row['campus_name'])) $_dh_meta_parts[] = sanitize($row['campus_name']);
} elseif ($_dh_role === 'admin') {
    $_dh_meta_parts[] = 'Administrator';
}
$_dh_meta_parts[] = date('M d, Y');

// Deadline — prefer submission_deadline when cycle is open, else evaluation_deadline
$_dh_deadline_label = '';
$_dh_deadline_date  = '';
$_dh_deadline_days  = null;
$_dh_deadline_color = '#94a3b8';
$_dh_deadline_type  = '';

if ($cycle) {
    // Pick the most relevant upcoming deadline
    $now = time();
    $sub_dl  = !empty($cycle['submission_deadline'])  ? strtotime($cycle['submission_deadline']  . ' 23:59:59') : 0;
    $eval_dl = !empty($cycle['evaluation_deadline'])  ? strtotime($cycle['evaluation_deadline']  . ' 23:59:59') : 0;

    // Evaluator cares about evaluation deadline; admin cares about submission deadline first
    if ($_dh_role === 'evaluator' && $eval_dl) {
        $_dh_deadline_ts    = $eval_dl;
        $_dh_deadline_type  = 'EVALUATION DEADLINE';
        $_dh_deadline_date  = date('M d, Y', $eval_dl);
    } elseif ($sub_dl) {
        $_dh_deadline_ts    = $sub_dl;
        $_dh_deadline_type  = 'SUBMISSION DEADLINE';
        $_dh_deadline_date  = date('M d, Y', $sub_dl);
    } elseif ($eval_dl) {
        $_dh_deadline_ts    = $eval_dl;
        $_dh_deadline_type  = 'EVALUATION DEADLINE';
        $_dh_deadline_date  = date('M d, Y', $eval_dl);
    } else {
        $_dh_deadline_ts = 0;
    }

    if (!empty($_dh_deadline_ts)) {
        $_dh_deadline_days = (int)ceil(($_dh_deadline_ts - $now) / 86400);
        if ($_dh_deadline_days < 0) {
            $_dh_deadline_label = 'Passed';
            $_dh_deadline_color = '#94a3b8';
        } elseif ($_dh_deadline_days === 0) {
            $_dh_deadline_label = 'Today';
            $_dh_deadline_color = '#dc2626';
        } elseif ($_dh_deadline_days <= 3) {
            $_dh_deadline_label = $_dh_deadline_days . ' day' . ($_dh_deadline_days === 1 ? '' : 's') . ' left';
            $_dh_deadline_color = '#dc2626';
        } elseif ($_dh_deadline_days <= 7) {
            $_dh_deadline_label = $_dh_deadline_days . ' days left';
            $_dh_deadline_color = '#d97706';
        } else {
            $_dh_deadline_label = $_dh_deadline_days . ' days left';
            $_dh_deadline_color = '#16a34a';
        }
    }
}
?>

<div style="background:#fff;border:1px solid #e2e8f0;padding:0.85rem 1.25rem;margin-bottom:1.1rem;
            display:flex;align-items:center;justify-content:space-between;gap:1.5rem;flex-wrap:wrap;">

    <!-- Left: avatar + name + meta -->
    <div style="display:flex;align-items:center;gap:0.85rem;min-width:0;">
        <?php if ($_dh_pic): ?>
        <img class="account-avatar" src="<?= sanitize($_dh_pic) ?>" alt="Profile"
             style="width:44px;height:44px;border-radius:<?= $_dh_avatar_radius ?>;object-fit:cover;border:1px solid #e2e8f0;flex-shrink:0;">
        <?php else: ?>
        <div class="account-avatar" style="width:44px;height:44px;border-radius:<?= $_dh_avatar_radius ?>;background:#1a3a6b;
                    display:flex;align-items:center;justify-content:center;
                    font-size:0.9rem;font-weight:700;color:#fff;flex-shrink:0;">
            <?= $_dh_initials ?>
        </div>
        <?php endif; ?>

        <div style="min-width:0;">
            <div style="font-size:0.92rem;font-weight:700;color:#0f172a;line-height:1.25;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">
                <?= $_dh_fullname ?>
            </div>
            <div style="font-size:0.72rem;color:#94a3b8;margin-top:3px;display:flex;align-items:center;gap:0.35rem;flex-wrap:wrap;">
                <?php foreach ($_dh_meta_parts as $_i => $_part): ?>
                <?php if ($_i > 0): ?><span style="color:#e2e8f0;">·</span><?php endif; ?>
                <span><?= $_part ?></span>
                <?php endforeach; ?>
            </div>
        </div>
    </div>

    <!-- Right: deadline -->
    <?php if ($_dh_deadline_date): ?>
    <div style="text-align:right;flex-shrink:0;">
        <div style="font-size:0.6rem;font-weight:700;color:#94a3b8;text-transform:uppercase;letter-spacing:0.1em;margin-bottom:3px;">
            <?= htmlspecialchars($_dh_deadline_type) ?>
        </div>
        <div style="font-size:1.05rem;font-weight:800;color:#0f172a;line-height:1.1;">
            <?= htmlspecialchars($_dh_deadline_date) ?>
        </div>
        <?php if ($_dh_deadline_label !== ''): ?>
        <div style="font-size:0.72rem;font-weight:600;color:<?= $_dh_deadline_color ?>;margin-top:2px;">
            <?= htmlspecialchars($_dh_deadline_label) ?>
        </div>
        <?php endif; ?>
    </div>
    <?php elseif ($cycle): ?>
    <div style="text-align:right;flex-shrink:0;">
        <div style="font-size:0.6rem;font-weight:700;color:#94a3b8;text-transform:uppercase;letter-spacing:0.1em;margin-bottom:3px;">
            Current Cycle
        </div>
        <div style="font-size:0.82rem;font-weight:700;color:#1a3a6b;">
            <?= sanitize($cycle['cycle_name']) ?>
        </div>
        <div style="font-size:0.65rem;color:#94a3b8;margin-top:2px;">No deadline set</div>
    </div>
    <?php endif; ?>

</div>
