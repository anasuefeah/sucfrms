<?php
// ── Faculty unread notification banner ────────────────────────────────────
try {
    $fac_notifs_stmt = $pdo->prepare("
        SELECT n.notif_id, n.type, n.message, n.created_at, n.submission_id, ks.kra_category
        FROM notifications n
        LEFT JOIN kra_submissions ks ON ks.submission_id = n.submission_id
        WHERE n.user_id = ? AND n.is_read = 0
          AND n.type IN ('score_adjusted','needs_revision','under_review','approved','rejected','talisay_review')
        ORDER BY n.created_at DESC
        LIMIT 2
    ");
    $fac_notifs_stmt->execute([$uid]);
    $fac_unread_notifs = $fac_notifs_stmt->fetchAll();

    $fac_total_stmt = $pdo->prepare("
        SELECT COUNT(*) FROM notifications
        WHERE user_id = ? AND is_read = 0
          AND type IN ('score_adjusted','needs_revision','under_review','approved','rejected','talisay_review')
    ");
    $fac_total_stmt->execute([$uid]);
    $fac_total_unread = (int)$fac_total_stmt->fetchColumn();
} catch (\Exception $e) {
    $fac_unread_notifs = [];
    $fac_total_unread  = 0;
}

if (!empty($fac_unread_notifs)):
    $notif_styles = [
        'score_adjusted' => ['icon'=>'bi-pencil-square',             'color'=>'#475569','bg'=>'#f8fafc','border'=>'#475569','label'=>'Score Updated'],
        'needs_revision' => ['icon'=>'bi-exclamation-triangle-fill', 'color'=>'#334155','bg'=>'#f8fafc','border'=>'#334155','label'=>'Revision Needed'],
        'under_review'   => ['icon'=>'bi-hourglass-split',           'color'=>'#1e4d8c','bg'=>'#f0f4fb','border'=>'#1e4d8c','label'=>'Under Review'],
        'talisay_review' => ['icon'=>'bi-building-up',               'color'=>'#1a3a6b','bg'=>'#f0f4fb','border'=>'#1a3a6b','label'=>'Talisay Review'],
        'approved'       => ['icon'=>'bi-check-circle-fill',         'color'=>'#1e4d8c','bg'=>'#f0f4fb','border'=>'#1e4d8c','label'=>'Approved'],
        'rejected'       => ['icon'=>'bi-arrow-counterclockwise',    'color'=>'#475569','bg'=>'#f8fafc','border'=>'#475569','label'=>'Returned'],
    ];
    $fac_notif_count  = count($fac_unread_notifs);
    $fac_extra_count  = max(0, $fac_total_unread - $fac_notif_count);
?>
<div id="facultyNotifBanner" class="mb-3" style="border-radius:12px;overflow:hidden;box-shadow:0 2px 16px rgba(0,0,0,0.12);">
    <div style="background:linear-gradient(135deg,#1e4d8c,#1a3a6b);padding:0.75rem 1.1rem;
                display:flex;align-items:center;justify-content:space-between;gap:1rem;flex-wrap:wrap;">
        <div style="display:flex;align-items:center;gap:0.65rem;">
            <span style="display:inline-flex;align-items:center;justify-content:center;
                         width:30px;height:30px;border-radius:50%;background:rgba(255,255,255,0.15);">
                <i class="bi bi-bell-fill" style="color:#475569;font-size:0.9rem;animation:bellRing 1.2s ease 3;"></i>
            </span>
            <span style="color:#fff;font-weight:700;font-size:0.9rem;">
                <?= $fac_total_unread ?> Unread Notification<?= $fac_total_unread > 1 ? 's' : '' ?> — Application Update
            </span>
            <span style="background:#334155;color:#fff;font-size:0.62rem;font-weight:700;
                         border-radius:20px;padding:1px 8px;letter-spacing:0.03em;">NEW</span>
        </div>
        <div style="display:flex;align-items:center;gap:0.6rem;">
            <button onclick="dismissFacultyBanner()"
                    style="background:rgba(255,255,255,0.15);border:none;color:#fff;font-size:0.72rem;
                           font-weight:600;cursor:pointer;padding:4px 12px;border-radius:6px;"
                    onmouseover="this.style.background='rgba(255,255,255,0.28)'"
                    onmouseout="this.style.background='rgba(255,255,255,0.15)'">
                <i class="bi bi-check2-all me-1"></i>Mark all read
            </button>
            <button onclick="document.getElementById('facultyNotifBanner').style.display='none'"
                    style="background:none;border:none;color:rgba(255,255,255,0.7);font-size:1.15rem;
                           cursor:pointer;line-height:1;padding:2px 5px;">&times;</button>
        </div>
    </div>
    <?php foreach ($fac_unread_notifs as $fn):
        $st = $notif_styles[$fn['type']] ?? ['icon'=>'bi-bell','color'=>'#64748b','bg'=>'#f8fafc','border'=>'#e2e8f0','label'=>'Update'];
        $fn_link = match($fn['type']) {
            'score_adjusted' => 'index.php?page=my_application#score-comparison',
            'needs_revision' => !empty($fn['submission_id'])
                ? 'index.php?page=apply&tab=' . kraCategoryToTabSlug($fn['kra_category']) . '&edit_sid=' . (int)$fn['submission_id']
                : 'index.php?page=my_application#app-status',
            default          => 'index.php?page=my_application#app-status',
        };
        $diff_s = time() - strtotime($fn['created_at']);
        if ($diff_s < 60)        $ago = 'just now';
        elseif ($diff_s < 3600)  $ago = floor($diff_s / 60) . 'm ago';
        elseif ($diff_s < 86400) $ago = floor($diff_s / 3600) . 'h ago';
        else                     $ago = floor($diff_s / 86400) . 'd ago';
    ?>
    <div style="display:flex;align-items:flex-start;gap:0.85rem;padding:0.8rem 1.1rem;
                background:<?= $st['bg'] ?>;border-left:4px solid <?= $st['border'] ?>;
                border-bottom:1px solid <?= $st['border'] ?>22;">
        <span style="display:inline-flex;align-items:center;justify-content:center;
                     width:36px;height:36px;border-radius:50%;background:<?= $st['color'] ?>18;
                     flex-shrink:0;margin-top:1px;">
            <i class="bi <?= $st['icon'] ?>" style="color:<?= $st['color'] ?>;font-size:1rem;"></i>
        </span>
        <div style="flex:1;min-width:0;">
            <div style="font-size:0.8rem;font-weight:600;color:#1e293b;line-height:1.45;word-break:break-word;">
                <?= htmlspecialchars($fn['message'], ENT_QUOTES) ?>
            </div>
            <div style="font-size:0.68rem;color:#94a3b8;margin-top:3px;">
                <i class="bi bi-clock me-1"></i><?= $ago ?>
                &nbsp;&middot;&nbsp;
                <span style="font-size:0.65rem;background:<?= $st['color'] ?>18;color:<?= $st['color'] ?>;
                             border-radius:4px;padding:1px 6px;font-weight:600;"><?= $st['label'] ?></span>
            </div>
        </div>
        <a href="<?= $fn_link ?>"
           onclick="markFacultyNotifRead(<?= (int)$fn['notif_id'] ?>)"
           style="flex-shrink:0;font-size:0.72rem;font-weight:600;color:<?= $st['color'] ?>;
                  text-decoration:none;white-space:nowrap;padding:5px 12px;border-radius:6px;
                  border:1px solid <?= $st['border'] ?>66;background:#fff;margin-top:1px;">
            View <i class="bi bi-arrow-right-short"></i>
        </a>
    </div>
    <?php endforeach; ?>
    <?php if ($fac_extra_count > 0): ?>
    <div style="padding:0.55rem 1.1rem;background:#f0f4fb;border-top:1px solid #e2e8f0;
                font-size:0.72rem;color:#64748b;text-align:center;">
        <i class="bi bi-three-dots me-1"></i>+<?= $fac_extra_count ?> more unread &mdash;
        <button onclick="dismissFacultyBanner()"
                style="background:none;border:none;color:#1e4d8c;font-size:0.72rem;font-weight:600;
                       cursor:pointer;padding:0;text-decoration:underline;">Mark all read</button>
    </div>
    <?php endif; ?>
</div>
<script>
function dismissFacultyBanner() {
    fetch('index.php?notif_action=mark_read', { method: 'POST', body: new FormData() })
        .then(() => {
            var b = document.getElementById('facultyNotifBanner');
            if (b) b.style.display = 'none';
            var badge = document.getElementById('notifBadge');
            var bell  = document.getElementById('notifBellIcon');
            var hdr   = document.getElementById('notifHeaderCount');
            if (badge) badge.style.display = 'none';
            if (bell)  bell.style.animation = 'none';
            if (hdr)   hdr.textContent = 'all read';
        });
}
function markFacultyNotifRead(id) {
    var fd = new FormData();
    fd.append('notif_id', id);
    fetch('index.php?notif_action=mark_read', { method: 'POST', body: fd });
}
</script>
<?php endif; ?>
