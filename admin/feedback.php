<?php
if (!isAdmin()) { echo '<div class="alert alert-danger">Access denied.</div>'; return; }

// Mark as read / resolved
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
$where = $filter_status && in_array($filter_status, $valid_statuses) ? "WHERE f.status = " . $pdo->quote($filter_status) : '';

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

$status_styles = [
    'new'      => ['bg'=>'#f8fafc','border'=>'#94a3b8','color'=>'#1a3a6b','label'=>'New','icon'=>'bi-dot'],
    'read'     => ['bg'=>'#f8fafc','border'=>'#e2e8f0','color'=>'#334155','label'=>'Read','icon'=>'bi-eye'],
    'resolved' => ['bg'=>'#f0f4fb','border'=>'#bfdbfe','color'=>'#1a3a6b','label'=>'Resolved','icon'=>'bi-check-circle'],
];

$star_colors = ['','#1e293b','#475569','#475569','#3b82f6','#1e4d8c'];
?>

<div class="neon-card p-0" style="overflow:hidden;">
    <!-- Header -->
    <div style="background:linear-gradient(135deg,#1e3a6b,#1e4d8c);padding:1rem 1.5rem;display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:0.75rem;">
        <div style="display:flex;align-items:center;gap:0.75rem;">
            <div style="width:38px;height:38px;border-radius:10px;background:rgba(255,255,255,0.15);display:flex;align-items:center;justify-content:center;">
                <i class="bi bi-chat-square-text-fill" style="color:#fff;font-size:1rem;"></i>
            </div>
            <div>
                <div style="font-weight:700;color:#fff;font-size:0.97rem;">User Feedback</div>
                <div style="font-size:0.72rem;color:rgba(255,255,255,0.7);"><?= $counts['all'] ?> submission<?= $counts['all'] !== 1 ? 's' : '' ?> total</div>
            </div>
        </div>
        <!-- Status filter pills -->
        <div style="display:flex;gap:0.4rem;flex-wrap:wrap;">
            <?php
            $pill_filters = [
                '' => ['label'=>'All','count'=>$counts['all'],'color'=>'rgba(255,255,255,0.15)','active'=>'rgba(255,255,255,0.9)'],
                'new' => ['label'=>'New','count'=>$counts['new'],'color'=>'rgba(239,68,68,0.25)','active'=>'#334155'],
                'read' => ['label'=>'Read','count'=>$counts['read'],'color'=>'rgba(234,179,8,0.25)','active'=>'#475569'],
                'resolved' => ['label'=>'Resolved','count'=>$counts['resolved'],'color'=>'rgba(30,77,140,0.15)','active'=>'#1e4d8c'],
            ];
            foreach ($pill_filters as $val => $pill):
                $active = ($filter_status === $val);
            ?>
            <a href="index.php?page=feedback<?= $val ? '&status='.$val : '' ?>"
               style="display:inline-flex;align-items:center;gap:0.3rem;padding:0.3rem 0.75rem;border-radius:20px;font-size:0.75rem;font-weight:600;text-decoration:none;border:1px solid rgba(255,255,255,0.2);
                      background:<?= $active ? 'rgba(255,255,255,0.95)' : 'rgba(255,255,255,0.1)' ?>;
                      color:<?= $active ? '#1e3a6b' : 'rgba(255,255,255,0.85)' ?>;">
                <?= $pill['label'] ?>
                <span style="background:<?= $active ? '#1e3a6b' : 'rgba(255,255,255,0.2)' ?>;color:<?= $active ? '#fff' : 'rgba(255,255,255,0.9)' ?>;border-radius:10px;padding:0 0.4rem;font-size:0.65rem;"><?= $pill['count'] ?></span>
            </a>
            <?php endforeach; ?>
        </div>
    </div>

    <?php if (empty($feedbacks)): ?>
    <div style="padding:4rem 2rem;text-align:center;color:#94a3b8;">
        <i class="bi bi-chat-square" style="font-size:2.5rem;display:block;margin-bottom:0.75rem;"></i>
        <div style="font-size:0.95rem;font-weight:600;margin-bottom:0.3rem;">No feedback<?= $filter_status ? ' with status "'.ucfirst($filter_status).'"' : '' ?></div>
        <div style="font-size:0.82rem;">User submissions will appear here.</div>
    </div>
    <?php else: ?>

    <div style="padding:1.25rem;display:flex;flex-direction:column;gap:0.85rem;">
    <?php foreach ($feedbacks as $fb):
        $ss = $status_styles[$fb['status']] ?? $status_styles['new'];
        $stars = intval($fb['rating'] ?? 0);
    ?>
    <div style="border:1px solid #e2e8f0;border-radius:12px;overflow:hidden;<?= $fb['status']==='new' ? 'border-left:4px solid #334155;' : ($fb['status']==='resolved' ? 'border-left:4px solid #1e4d8c;' : 'border-left:4px solid #e2e8f0;') ?>background:#fff;box-shadow:0 1px 4px rgba(0,0,0,0.04);">

        <!-- Feedback header -->
        <div style="padding:0.75rem 1rem;display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:0.5rem;background:#f8fafc;border-bottom:1px solid #f1f5f9;">
            <div style="display:flex;align-items:center;gap:0.65rem;">
                <!-- Avatar -->
                <div style="width:32px;height:32px;border-radius:50%;background:#1e4d8c;display:flex;align-items:center;justify-content:center;flex-shrink:0;">
                    <span style="color:#fff;font-weight:700;font-size:0.75rem;"><?= strtoupper(substr($fb['full_name'] ?? '?', 0, 1)) ?></span>
                </div>
                <div>
                    <div style="font-weight:700;color:#1e293b;font-size:0.85rem;"><?= sanitize($fb['full_name'] ?? 'Anonymous') ?></div>
                    <div style="font-size:0.7rem;color:#64748b;"><?= sanitize($fb['email'] ?? '') ?><?= $fb['role'] ? ' &nbsp;·&nbsp; <span style="text-transform:capitalize;">'.str_replace('_',' ',$fb['role']).'</span>' : '' ?></div>
                </div>
            </div>
            <div style="display:flex;align-items:center;gap:0.5rem;flex-wrap:wrap;">
                <!-- Stars -->
                <?php if ($stars > 0): ?>
                <div style="display:flex;gap:2px;">
                    <?php for ($s = 1; $s <= 5; $s++): ?>
                    <i class="bi bi-star<?= $s <= $stars ? '-fill' : '' ?>" style="font-size:0.8rem;color:<?= $s <= $stars ? '#475569' : '#cbd5e1' ?>;"></i>
                    <?php endfor; ?>
                </div>
                <?php endif; ?>
                <!-- Status badge -->
                <span style="background:<?= $ss['bg'] ?>;color:<?= $ss['color'] ?>;border:1px solid <?= $ss['border'] ?>;border-radius:20px;padding:0.18rem 0.65rem;font-size:0.68rem;font-weight:700;">
                    <i class="bi <?= $ss['icon'] ?> me-1"></i><?= $ss['label'] ?>
                </span>
                <!-- Date -->
                <span style="font-size:0.7rem;color:#94a3b8;"><?= date('M d, Y · H:i', strtotime($fb['submitted_at'])) ?></span>
            </div>
        </div>

        <!-- Feedback body -->
        <div style="padding:0.85rem 1rem;">
            <?php if ($fb['subject']): ?>
            <div style="font-weight:700;color:#1a3a6b;font-size:0.88rem;margin-bottom:0.4rem;">
                <i class="bi bi-tag me-1" style="color:#64748b;"></i><?= sanitize($fb['subject']) ?>
            </div>
            <?php endif; ?>
            <div style="font-size:0.84rem;color:#374151;line-height:1.6;white-space:pre-wrap;"><?= sanitize($fb['message']) ?></div>
            <?php if ($fb['status'] === 'resolved' && $fb['resolved_at']): ?>
            <div style="margin-top:0.6rem;font-size:0.72rem;color:#1e4d8c;display:flex;align-items:center;gap:0.35rem;">
                <i class="bi bi-check2-circle"></i> Resolved <?= date('M d, Y', strtotime($fb['resolved_at'])) ?>
            </div>
            <?php endif; ?>
        </div>

        <!-- Actions -->
        <div style="padding:0.5rem 1rem;background:#f8fafc;border-top:1px solid #f1f5f9;display:flex;gap:0.5rem;flex-wrap:wrap;">
            <?php if ($fb['status'] === 'new'): ?>
            <form method="POST" style="margin:0;">
                <input type="hidden" name="feedback_id" value="<?= $fb['feedback_id'] ?>">
                <input type="hidden" name="action" value="mark_read">
                <button type="submit" style="padding:0.3rem 0.75rem;border:1px solid #e2e8f0;border-radius:6px;background:#f8fafc;color:#334155;font-size:0.75rem;font-weight:600;cursor:pointer;">
                    <i class="bi bi-eye me-1"></i>Mark Read
                </button>
            </form>
            <?php endif; ?>
            <?php if ($fb['status'] !== 'resolved'): ?>
            <form method="POST" style="margin:0;">
                <input type="hidden" name="feedback_id" value="<?= $fb['feedback_id'] ?>">
                <input type="hidden" name="action" value="mark_resolved">
                <button type="submit" style="padding:0.3rem 0.75rem;border:1px solid #bbf7d0;border-radius:6px;background:#f0fdf4;color:#16a34a;font-size:0.75rem;font-weight:600;cursor:pointer;">
                    <i class="bi bi-check-circle me-1"></i>Mark Resolved
                </button>
            </form>
            <?php else: ?>
            <form method="POST" style="margin:0;">
                <input type="hidden" name="feedback_id" value="<?= $fb['feedback_id'] ?>">
                <input type="hidden" name="action" value="mark_new">
                <button type="submit" style="padding:0.3rem 0.75rem;border:1px solid #e2e8f0;border-radius:6px;background:#f8fafc;color:#64748b;font-size:0.75rem;font-weight:600;cursor:pointer;">
                    <i class="bi bi-arrow-counterclockwise me-1"></i>Reopen
                </button>
            </form>
            <?php endif; ?>
            <form method="POST" style="margin:0;margin-left:auto;"
                  onsubmit="return confirm('Delete this feedback permanently?')">
                <input type="hidden" name="feedback_id" value="<?= $fb['feedback_id'] ?>">
                <input type="hidden" name="action" value="delete">
                <button type="submit" style="padding:0.3rem 0.75rem;border:1px solid #fecaca;border-radius:6px;background:#fef2f2;color:#dc2626;font-size:0.75rem;font-weight:600;cursor:pointer;">
                    <i class="bi bi-trash me-1"></i>Delete
                </button>
            </form>
        </div>
    </div>
    <?php endforeach; ?>
    </div>
    <?php endif; ?>
</div>
