<?php
if (!isAdmin()) { echo '<div class="alert alert-danger">Access denied.</div>'; return; }

// Mark as read / resolved / delete
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $fid    = intval($_POST['feedback_id'] ?? 0);
    if ($action === 'mark_read' && $fid) {
        $pdo->prepare("UPDATE feedback_submissions SET status='read' WHERE feedback_id=?")->execute([$fid]);
    } elseif ($action === 'mark_resolved' && $fid) {
        $pdo->prepare("UPDATE feedback_submissions SET status='resolved', resolved_by=?, resolved_at=NOW() WHERE feedback_id=?")
            ->execute([$_SESSION['user_id'], $fid]);
        logAudit($pdo, $_SESSION['user_id'], 'Feedback Resolved', "Feedback ID {$fid} marked resolved.");
    } elseif ($action === 'mark_new' && $fid) {
        $pdo->prepare("UPDATE feedback_submissions SET status='new', resolved_by=NULL, resolved_at=NULL WHERE feedback_id=?")->execute([$fid]);
    } elseif ($action === 'delete' && $fid) {
        $pdo->prepare("DELETE FROM feedback_submissions WHERE feedback_id=?")->execute([$fid]);
        logAudit($pdo, $_SESSION['user_id'], 'Feedback Deleted', "Deleted feedback ID {$fid}.");
    }
    echo "<script>window.location.href='index.php?page=feedback';</script>"; exit;
}

// Filters
$filter_status = $_GET['status'] ?? '';
$valid_statuses = ['new','read','resolved'];
$where = $filter_status && in_array($filter_status, $valid_statuses)
    ? "WHERE f.status = " . $pdo->quote($filter_status) : '';

// Counts
$counts = ['all'=>0,'new'=>0,'read'=>0,'resolved'=>0];
foreach ($pdo->query("SELECT status, COUNT(*) c FROM feedback_submissions GROUP BY status")->fetchAll() as $r) {
    $counts[$r['status']] = (int)$r['c'];
    $counts['all'] += (int)$r['c'];
}

// Fetch
$feedbacks = $pdo->query("
    SELECT f.*, u.full_name, u.email, u.role
    FROM feedback_submissions f
    LEFT JOIN users u ON f.user_id = u.user_id
    {$where}
    ORDER BY f.submitted_at DESC
")->fetchAll();
?>

<?php showFlash(); ?>

<!-- Page header -->
<div style="background:#fff;border:1px solid #e2e8f0;padding:0.85rem 1.25rem;margin-bottom:1.25rem;
            display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:1rem;">
    <div style="display:flex;align-items:center;gap:0.75rem;">
        <div style="width:36px;height:36px;background:#1a3a6b;display:flex;align-items:center;justify-content:center;flex-shrink:0;">
            <i class="bi bi-chat-square-text-fill" style="color:#fff;font-size:0.9rem;"></i>
        </div>
        <div>
            <div style="font-size:0.92rem;font-weight:700;color:#0f172a;line-height:1.2;">User Feedback</div>
            <div style="font-size:0.72rem;color:#94a3b8;margin-top:2px;"><?= $counts['all'] ?> submission<?= $counts['all'] !== 1 ? 's' : '' ?> total</div>
        </div>
    </div>
    <!-- Status filter tabs -->
    <div style="display:flex;gap:0;border:1px solid #e2e8f0;overflow:hidden;">
        <?php
        $tab_defs = [
            ''         => ['All',      $counts['all']],
            'new'      => ['New',      $counts['new']],
            'read'     => ['Read',     $counts['read']],
            'resolved' => ['Resolved', $counts['resolved']],
        ];
        foreach ($tab_defs as $val => [$label, $cnt]):
            $active = ($filter_status === $val);
        ?>
        <a href="index.php?page=feedback<?= $val ? '&status='.$val : '' ?>"
           style="display:inline-flex;align-items:center;gap:0.4rem;padding:0.38rem 0.9rem;
                  font-size:0.75rem;font-weight:600;text-decoration:none;white-space:nowrap;
                  background:<?= $active ? '#1a3a6b' : '#fff' ?>;
                  color:<?= $active ? '#fff' : '#475569' ?>;
                  border-right:1px solid #e2e8f0;"
           onmouseover="if(!<?= $active?'true':'false' ?>)this.style.background='#f8fafc'"
           onmouseout="if(!<?= $active?'true':'false' ?>)this.style.background='#fff'">
            <?= $label ?>
            <span style="background:<?= $active ? 'rgba(255,255,255,0.2)' : '#f1f5f9' ?>;
                         color:<?= $active ? '#fff' : '#64748b' ?>;
                         font-size:0.6rem;font-weight:800;padding:0 5px;min-width:16px;text-align:center;">
                <?= $cnt ?>
            </span>
        </a>
        <?php endforeach; ?>
    </div>
</div>

<?php if (empty($feedbacks)): ?>
<div style="background:#fff;border:1px solid #e2e8f0;padding:4rem 2rem;text-align:center;color:#94a3b8;">
    <i class="bi bi-chat-square" style="font-size:2rem;display:block;margin-bottom:0.75rem;color:#e2e8f0;"></i>
    <div style="font-size:0.88rem;font-weight:600;color:#475569;margin-bottom:0.3rem;">
        No feedback<?= $filter_status ? ' with status "'.ucfirst($filter_status).'"' : '' ?>
    </div>
    <div style="font-size:0.78rem;">User submissions will appear here.</div>
</div>
<?php else: ?>

<div style="display:flex;flex-direction:column;gap:0.65rem;">
<?php foreach ($feedbacks as $fb):
    $stars = intval($fb['rating'] ?? 0);
    $is_new      = $fb['status'] === 'new';
    $is_resolved = $fb['status'] === 'resolved';
    $left_color  = $is_new ? '#1a3a6b' : ($is_resolved ? '#16a34a' : '#e2e8f0');
    $status_label = ucfirst($fb['status']);
    $status_bg    = $is_new ? '#eff6ff' : ($is_resolved ? '#f0fdf4' : '#f8fafc');
    $status_color = $is_new ? '#1e4d8c' : ($is_resolved ? '#16a34a' : '#64748b');
    $status_bd    = $is_new ? '#bfdbfe' : ($is_resolved ? '#bbf7d0' : '#e2e8f0');
    $init = strtoupper(substr($fb['full_name'] ?? '?', 0, 1));
?>
<div style="background:#fff;border:1px solid #e2e8f0;border-left:3px solid <?= $left_color ?>;overflow:hidden;">

    <!-- Row 1: Identity + meta -->
    <div style="padding:0.75rem 1rem 0.65rem;display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:0.5rem;border-bottom:1px solid #f1f5f9;">
        <div style="display:flex;align-items:center;gap:0.6rem;">
            <div class="account-avatar" style="width:30px;height:30px;border-radius:50%;background:#1a3a6b;display:flex;align-items:center;justify-content:center;flex-shrink:0;">
                <span style="color:#fff;font-weight:700;font-size:0.68rem;"><?= $init ?></span>
            </div>
            <div>
                <div style="font-weight:700;color:#1e293b;font-size:0.82rem;line-height:1.2;"><?= sanitize($fb['full_name'] ?? 'Anonymous') ?></div>
                <div style="font-size:0.68rem;color:#94a3b8;margin-top:1px;">
                    <?= sanitize($fb['email'] ?? '') ?>
                    <?php if ($fb['role']): ?> &nbsp;·&nbsp; <span style="text-transform:capitalize;"><?= str_replace('_', ' ', $fb['role']) ?></span><?php endif; ?>
                </div>
            </div>
        </div>
        <div style="display:flex;align-items:center;gap:0.5rem;flex-wrap:wrap;">
            <?php if ($stars > 0): ?>
            <div style="display:flex;gap:1px;">
                <?php for ($s = 1; $s <= 5; $s++): ?>
                <i class="bi bi-star<?= $s <= $stars ? '-fill' : '' ?>" style="font-size:0.72rem;color:<?= $s <= $stars ? '#1a3a6b' : '#e2e8f0' ?>;"></i>
                <?php endfor; ?>
            </div>
            <?php endif; ?>
            <span style="background:<?= $status_bg ?>;color:<?= $status_color ?>;border:1px solid <?= $status_bd ?>;font-size:0.65rem;font-weight:700;padding:2px 8px;">
                <?= $status_label ?>
            </span>
            <span style="font-size:0.68rem;color:#94a3b8;"><?= date('M d, Y · H:i', strtotime($fb['submitted_at'])) ?></span>
        </div>
    </div>

    <!-- Row 2: Content -->
    <div style="padding:0.75rem 1rem;">
        <?php if ($fb['subject']): ?>
        <div style="font-weight:700;color:#1e293b;font-size:0.82rem;margin-bottom:0.35rem;">
            <?= sanitize($fb['subject']) ?>
        </div>
        <?php endif; ?>
        <div style="font-size:0.8rem;color:#475569;line-height:1.65;white-space:pre-wrap;"><?= sanitize($fb['message']) ?></div>
        <?php if ($is_resolved && $fb['resolved_at']): ?>
        <div style="margin-top:0.5rem;font-size:0.68rem;color:#16a34a;display:flex;align-items:center;gap:3px;">
            <i class="bi bi-check2-circle"></i> Resolved <?= date('M d, Y', strtotime($fb['resolved_at'])) ?>
        </div>
        <?php endif; ?>
    </div>

    <!-- Row 3: Actions -->
    <div style="padding:0.5rem 1rem;border-top:1px solid #f1f5f9;display:flex;gap:0.4rem;align-items:center;flex-wrap:wrap;">
        <?php if ($is_new): ?>
        <form method="POST" style="margin:0;">
            <input type="hidden" name="feedback_id" value="<?= $fb['feedback_id'] ?>">
            <input type="hidden" name="action" value="mark_read">
            <button type="submit" style="padding:0.3rem 0.75rem;border:1px solid #e2e8f0;background:#fff;color:#475569;font-size:0.72rem;font-weight:600;cursor:pointer;display:inline-flex;align-items:center;gap:3px;"
                    onmouseover="this.style.background='#f8fafc'" onmouseout="this.style.background='#fff'">
                <i class="bi bi-eye" style="font-size:0.68rem;"></i> Mark Read
            </button>
        </form>
        <?php endif; ?>
        <?php if (!$is_resolved): ?>
        <form method="POST" style="margin:0;">
            <input type="hidden" name="feedback_id" value="<?= $fb['feedback_id'] ?>">
            <input type="hidden" name="action" value="mark_resolved">
            <button type="submit" style="padding:0.3rem 0.75rem;border:1px solid #bbf7d0;background:#f0fdf4;color:#16a34a;font-size:0.72rem;font-weight:600;cursor:pointer;display:inline-flex;align-items:center;gap:3px;"
                    onmouseover="this.style.background='#dcfce7'" onmouseout="this.style.background='#f0fdf4'">
                <i class="bi bi-check-circle" style="font-size:0.68rem;"></i> Mark Resolved
            </button>
        </form>
        <?php else: ?>
        <form method="POST" style="margin:0;">
            <input type="hidden" name="feedback_id" value="<?= $fb['feedback_id'] ?>">
            <input type="hidden" name="action" value="mark_new">
            <button type="submit" style="padding:0.3rem 0.75rem;border:1px solid #e2e8f0;background:#fff;color:#64748b;font-size:0.72rem;font-weight:600;cursor:pointer;display:inline-flex;align-items:center;gap:3px;"
                    onmouseover="this.style.background='#f8fafc'" onmouseout="this.style.background='#fff'">
                <i class="bi bi-arrow-counterclockwise" style="font-size:0.68rem;"></i> Reopen
            </button>
        </form>
        <?php endif; ?>
        <form method="POST" id="deleteFeedbackForm_<?= $fb['feedback_id'] ?>" style="margin:0;margin-left:auto;">
            <input type="hidden" name="feedback_id" value="<?= $fb['feedback_id'] ?>">
            <input type="hidden" name="action" value="delete">
            <button type="button"
                    onclick="confirmDelete('Delete this feedback permanently?','deleteFeedbackForm_<?= $fb['feedback_id'] ?>','Delete','bi-trash')"
                    style="padding:0.3rem 0.75rem;border:1px solid #fecaca;background:#fff;color:#dc2626;font-size:0.72rem;font-weight:600;cursor:pointer;display:inline-flex;align-items:center;gap:3px;"
                    onmouseover="this.style.background='#fef2f2'" onmouseout="this.style.background='#fff'">
                <i class="bi bi-trash" style="font-size:0.68rem;"></i> Delete
            </button>
        </form>
    </div>
</div>
<?php endforeach; ?>
</div>
<?php endif; ?>
