<?php
// -- Faculty unread notification banner ------------------------------------
try {
    $fac_notifs_stmt = $pdo->prepare("
        SELECT n.notif_id, n.type, n.message, n.created_at, n.submission_id, ks.kra_category
        FROM notifications n
        LEFT JOIN kra_submissions ks ON ks.submission_id = n.submission_id
        WHERE n.user_id = ? AND n.is_read = 0
          AND n.type IN ('criteria_updated','score_adjusted','needs_revision','under_review','approved','rejected','appeal_message','appeal_resolved')
        ORDER BY n.created_at DESC
        LIMIT 3
    ");
    $fac_notifs_stmt->execute([$uid]);
    $fac_unread_notifs = $fac_notifs_stmt->fetchAll();

    $fac_total_stmt = $pdo->prepare("
        SELECT COUNT(*) FROM notifications
        WHERE user_id = ? AND is_read = 0
          AND type IN ('criteria_updated','score_adjusted','needs_revision','under_review','approved','rejected','appeal_message','appeal_resolved')
    ");
    $fac_total_stmt->execute([$uid]);
    $fac_total_unread = (int)$fac_total_stmt->fetchColumn();
} catch (\Exception $e) {
    $fac_unread_notifs = [];
    $fac_total_unread  = 0;
}

if (!empty($fac_unread_notifs)):
    $notif_styles = [
        'criteria_updated' => ['icon'=>'bi-sliders2', 'label'=>'Criteria Updated', 'accent'=>'#1a3a6b'],
        'score_adjusted' => ['icon'=>'bi-pencil',                    'label'=>'Score Updated',         'accent'=>'#475569'],
        'needs_revision' => ['icon'=>'bi-exclamation-triangle-fill', 'label'=>'Revision Needed',       'accent'=>'#c2410c'],
        'under_review'   => ['icon'=>'bi-hourglass-split',           'label'=>'Under Review',          'accent'=>'#1a3a6b'],
        'approved'       => ['icon'=>'bi-clipboard-check',           'label'=>'Evaluation Complete',   'accent'=>'#16a34a'],
        'rejected'       => ['icon'=>'bi-arrow-counterclockwise',    'label'=>'Returned for Revision', 'accent'=>'#475569'],
        'appeal_message' => ['icon'=>'bi-chat-dots',                 'label'=>'Appeal Reply',          'accent'=>'#1a3a6b'],
        'appeal_resolved'=> ['icon'=>'bi-check2-circle',             'label'=>'Appeal Resolved',       'accent'=>'#16a34a'],
    ];
    $fac_notif_count = count($fac_unread_notifs);
    $fac_extra_count = max(0, $fac_total_unread - $fac_notif_count);
?>
<div id="facultyNotifBanner" style="border:1px solid #e2e8f0;background:#fff;margin-bottom:1rem;">

    <!-- Header -->
    <div style="display:flex;align-items:center;justify-content:space-between;
                padding:0.55rem 1rem;border-bottom:1px solid #e2e8f0;background:#1a3a6b;">
        <div style="display:flex;align-items:center;gap:0.5rem;">
            <i class="bi bi-bell-fill" style="color:#fff;font-size:0.82rem;"></i>
            <span style="color:#fff;font-weight:700;font-size:0.8rem;">
                <?= $fac_total_unread ?> Unread Notification<?= $fac_total_unread > 1 ? 's' : '' ?>
            </span>
        </div>
        <div style="display:flex;align-items:center;gap:0.5rem;">
            <button onclick="dismissFacultyBanner()"
                    style="background:none;border:1px solid rgba(255,255,255,0.3);color:#fff;
                           font-size:0.72rem;font-weight:600;cursor:pointer;padding:3px 10px;
                           display:inline-flex;align-items:center;gap:4px;"
                    onmouseover="this.style.background='rgba(255,255,255,0.15)'"
                    onmouseout="this.style.background='none'">
                <i class="bi bi-check2-all"></i> Mark all read
            </button>
            <button onclick="document.getElementById('facultyNotifBanner').style.display='none'"
                    style="background:none;border:none;color:rgba(255,255,255,0.6);
                           font-size:1.1rem;cursor:pointer;line-height:1;padding:0 2px;">
                &times;
            </button>
        </div>
    </div>

    <!-- Notification rows -->
    <?php foreach ($fac_unread_notifs as $i => $fn):
        $st = $notif_styles[$fn['type']] ?? ['icon'=>'bi-bell','label'=>'Update','accent'=>'#64748b'];
        $fn_link = match($fn['type']) {
            'criteria_updated' => 'pages/self_assessment.php',
            'score_adjusted'  => 'index.php?page=my_application#score-comparison',
            'needs_revision'  => !empty($fn['submission_id'])
                ? 'index.php?page=apply&tab=' . kraCategoryToTabSlug($fn['kra_category']) . '&edit_sid=' . (int)$fn['submission_id']
                : 'index.php?page=my_application#app-status',
            'appeal_message',
            'appeal_resolved' => 'index.php?page=appeals',
            default           => 'index.php?page=my_application#app-status',
        };
        $diff_s = time() - strtotime($fn['created_at']);
        if ($diff_s < 60)        $ago = 'just now';
        elseif ($diff_s < 3600)  $ago = floor($diff_s / 60) . 'm ago';
        elseif ($diff_s < 86400) $ago = floor($diff_s / 3600) . 'h ago';
        else                     $ago = floor($diff_s / 86400) . 'd ago';
    ?>
    <div style="display:flex;align-items:center;gap:0.85rem;padding:0.7rem 1rem;
                border-left:3px solid <?= $st['accent'] ?>;
                <?= $i < $fac_notif_count - 1 || $fac_extra_count > 0 ? 'border-bottom:1px solid #f1f5f9;' : '' ?>
                background:#fff;">
        <!-- Icon -->
        <div style="flex-shrink:0;width:30px;height:30px;background:#f8fafc;border:1px solid #e2e8f0;
                    display:flex;align-items:center;justify-content:center;">
            <i class="bi <?= $st['icon'] ?>" style="color:<?= $st['accent'] ?>;font-size:0.82rem;"></i>
        </div>
        <!-- Content -->
        <div style="flex:1;min-width:0;">
            <div style="font-size:0.8rem;color:#1e293b;line-height:1.5;word-break:break-word;">
                <?= htmlspecialchars($fn['message'], ENT_QUOTES) ?>
            </div>
            <div style="display:flex;align-items:center;gap:0.5rem;margin-top:3px;">
                <span style="font-size:0.68rem;color:#94a3b8;"><?= $ago ?></span>
                <span style="font-size:0.62rem;font-weight:700;color:<?= $st['accent'] ?>;
                             background:<?= $st['accent'] ?>14;padding:1px 6px;"><?= $st['label'] ?></span>
            </div>
        </div>
        <!-- Action -->
        <a href="<?= $fn_link ?>"
           onclick="markFacultyNotifRead(<?= (int)$fn['notif_id'] ?>)"
           style="flex-shrink:0;font-size:0.72rem;font-weight:600;color:#1a3a6b;
                  text-decoration:none;padding:4px 10px;border:1px solid #e2e8f0;
                  background:#f8fafc;white-space:nowrap;"
           onmouseover="this.style.borderColor='#1a3a6b';this.style.background='#eff6ff'"
           onmouseout="this.style.borderColor='#e2e8f0';this.style.background='#f8fafc'">
            View →
        </a>
    </div>
    <?php endforeach; ?>

    <!-- Extra count footer -->
    <?php if ($fac_extra_count > 0): ?>
    <div style="padding:0.45rem 1rem;background:#f8fafc;border-top:1px solid #e2e8f0;
                display:flex;align-items:center;justify-content:space-between;">
        <span style="font-size:0.72rem;color:#94a3b8;">+<?= $fac_extra_count ?> more unread notification<?= $fac_extra_count > 1 ? 's' : '' ?></span>
        <button onclick="dismissFacultyBanner()"
                style="background:none;border:none;color:#1a3a6b;font-size:0.72rem;
                       font-weight:600;cursor:pointer;padding:0;">
            Mark all read
        </button>
    </div>
    <?php endif; ?>

</div>

<script>
function dismissFacultyBanner() {
    fetch('index.php?notif_action=mark_read', { method:'POST', body:new FormData() })
        .then(function() {
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
    fetch('index.php?notif_action=mark_read', { method:'POST', body:fd });
}
</script>
<?php endif; ?>
