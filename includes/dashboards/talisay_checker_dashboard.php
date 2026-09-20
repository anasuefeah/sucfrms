<?php
$uid   = $_SESSION['user_id'];
$cycle = getActiveCycle($pdo);

$pending_stmt = $pdo->prepare("SELECT COUNT(*) FROM applications a WHERE a.status = 'talisay_review' AND a.user_id != ? AND NOT EXISTS (SELECT 1 FROM application_checker_reviews r WHERE r.application_id = a.application_id AND r.checker_id = ? AND r.decision IN ('approved','rejected'))");
$pending_stmt->execute([$uid, $uid]); $pending = (int)$pending_stmt->fetchColumn();

$total_talisay_review = (int)$pdo->query("SELECT COUNT(*) FROM applications WHERE status='talisay_review'")->fetchColumn();

$my_approved_stmt = $pdo->prepare("SELECT COUNT(*) FROM application_checker_reviews r JOIN applications a ON r.application_id = a.application_id WHERE r.checker_id = ? AND r.decision = 'approved'");
$my_approved_stmt->execute([$uid]); $my_approved = (int)$my_approved_stmt->fetchColumn();

$total_reviewed_stmt = $pdo->prepare("SELECT COUNT(*) FROM application_checker_reviews WHERE checker_id = ? AND decision IN ('approved','rejected')");
$total_reviewed_stmt->execute([$uid]); $total_reviewed = (int)$total_reviewed_stmt->fetchColumn();

$queue_stmt = $pdo->prepare("SELECT a.*, u.full_name, u.first_name, u.middle_name, u.last_name, u.rank, COALESCE(camp.campus_name,'—') AS campus_name, c.cycle_name, DATEDIFF(NOW(), a.submitted_at) AS days_waiting FROM applications a JOIN users u ON a.user_id = u.user_id LEFT JOIN campuses camp ON u.campus_id = camp.campus_id JOIN cycles c ON a.cycle_id = c.cycle_id WHERE a.status = 'talisay_review' AND a.user_id != ? AND NOT EXISTS (SELECT 1 FROM application_checker_reviews r WHERE r.application_id = a.application_id AND r.checker_id = ? AND r.decision IN ('approved','rejected')) ORDER BY a.submitted_at ASC LIMIT 10");
$queue_stmt->execute([$uid, $uid]); $queue = $queue_stmt->fetchAll();

$recent_stmt = $pdo->prepare("SELECT a.*, u.full_name, u.first_name, u.middle_name, u.last_name, COALESCE(camp.campus_name,'—') AS campus_name FROM applications a JOIN users u ON a.user_id = u.user_id LEFT JOIN campuses camp ON u.campus_id = camp.campus_id JOIN application_checker_reviews r ON r.application_id = a.application_id AND r.checker_id = ? WHERE r.decision IN ('approved','rejected') ORDER BY r.decided_at DESC LIMIT 5");
$recent_stmt->execute([$uid]); $recent = $recent_stmt->fetchAll();
?>

<!-- ── Header ── -->
<div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:1rem;margin-bottom:1.5rem;">
    <div style="display:flex;align-items:center;gap:1rem;">
        <?php
        $pic = $_SESSION['profile_pic'] ?? '';
        $init_h = strtoupper(
            substr($_SESSION['first_name'] ?? '', 0, 1) .
            substr($_SESSION['last_name']  ?? '', 0, 1)
        ) ?: 'T';
        ?>
        <?php if ($pic): ?>
        <img src="<?= sanitize($pic) ?>" style="width:52px;height:52px;border-radius:50%;object-fit:cover;
                    border:3px solid #1a3a6b;flex-shrink:0;box-shadow:0 2px 12px rgba(124,58,237,0.25);">
        <?php else: ?>
        <div style="width:52px;height:52px;border-radius:50%;
                    background:linear-gradient(135deg,#1a3a6b,#1a3a6b);
                    display:flex;align-items:center;justify-content:center;
                    font-size:1rem;font-weight:800;color:#fff;flex-shrink:0;
                    box-shadow:0 2px 12px rgba(124,58,237,0.3);">
            <?= $init_h ?>
        </div>
        <?php endif; ?>
        <div>
            <div style="font-size:1.05rem;font-weight:700;color:#1a3a6b;">
                Welcome back, <?= sanitize($_SESSION['full_name'] ?? 'Talisay Checker') ?>
            </div>
            <div style="font-size:0.75rem;color:#94a3b8;margin-top:3px;display:flex;align-items:center;gap:0.5rem;">
                <i class="bi bi-calendar3"></i><?= date('l, F j, Y') ?>
                &nbsp;&middot;&nbsp;
                <span style="background:#f0f4fb;color:#1a3a6b;font-size:0.65rem;font-weight:700;border-radius:4px;padding:1px 7px;border:1px solid #dbeafe;">Talisay Checker</span>
            </div>
        </div>
    </div>
    <a href="?page=review_queue"
       style="display:inline-flex;align-items:center;gap:0.5rem;padding:0.5rem 1.15rem;
              border-radius:8px;background:#1a3a6b;color:#fff;font-size:0.82rem;font-weight:700;
              text-decoration:none;transition:background 0.15s;"
       onmouseover="this.style.background='#1e4d8c'" onmouseout="this.style.background='#1a3a6b'">
        <i class="bi bi-inbox-fill"></i> Review Queue
        <?php if ($pending > 0): ?>
        <span style="background:#334155;color:#fff;font-size:0.65rem;font-weight:700;border-radius:50%;
                     min-width:18px;height:18px;display:inline-flex;align-items:center;justify-content:center;padding:0 3px;">
            <?= $pending ?>
        </span>
        <?php endif; ?>
    </a>
</div>

<!-- ── Cycle banner ── -->
<?php if ($cycle): ?>
<div style="background:linear-gradient(135deg,#4c1d95,#1a3a6b);border-radius:14px;
            padding:1.1rem 1.4rem;margin-bottom:1.5rem;
            display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:1rem;
            box-shadow:0 4px 20px rgba(124,58,237,0.25);">
    <div style="display:flex;align-items:center;gap:1rem;">
        <div style="width:44px;height:44px;border-radius:10px;background:rgba(255,255,255,0.15);
                    display:flex;align-items:center;justify-content:center;flex-shrink:0;">
            <i class="bi bi-building-up" style="color:#475569;font-size:1.2rem;"></i>
        </div>
        <div>
            <div style="font-weight:700;color:#fff;font-size:0.95rem;"><?= sanitize($cycle['cycle_name']) ?></div>
            <div style="font-size:0.75rem;color:#bfdbfe;margin-top:2px;">
                <span style="opacity:0.8;">Status:</span>
                <strong style="color:#fff;"><?= ucfirst($cycle['status']) ?></strong>
            </div>
        </div>
    </div>
</div>
<?php else: ?>
<div style="background:#f8fafc;border:1px solid #e2e8f0;border-left:4px solid #475569;border-radius:12px;
            padding:1rem 1.25rem;margin-bottom:1.5rem;display:flex;align-items:center;gap:0.75rem;">
    <i class="bi bi-exclamation-triangle-fill" style="color:#475569;font-size:1.2rem;"></i>
    <div style="font-weight:600;color:#334155;font-size:0.88rem;">No active cycle at the moment.</div>
</div>
<?php endif; ?>

<!-- ── KPI Cards ── -->
<?php
$kpi = [
    ['Awaiting My Review',  $pending,             'bi-inbox-fill',          $pending>0?'#334155':'#94a3b8',   $pending>0?'#f8fafc':'#f8fafc', $pending>0?'#e2e8f0':'#e2e8f0', '?page=review_queue'],
    ['In Talisay Review',   $total_talisay_review,'bi-building-up',         '#1a3a6b','#f0f4fb','#dbeafe', '?page=review_queue'],
    ['I Approved',          $my_approved,         'bi-check-circle-fill',   '#1e4d8c','#eff6ff','#dbeafe', '?page=review_queue'],
    ['Total Reviewed',      $total_reviewed,      'bi-check2-all',          '#1e4d8c','#eff6ff','#dbeafe', '?page=review_queue'],
];
?>
<div style="display:grid;grid-template-columns:repeat(4,1fr);gap:0.85rem;margin-bottom:1.5rem;">
<?php foreach ($kpi as [$label,$val,$icon,$tc,$bg,$border,$link]): ?>
    <a href="<?= $link ?>" style="text-decoration:none;">
        <div style="background:<?= $bg ?>;border:1px solid <?= $border ?>;border-radius:12px;
                    padding:1rem 1.1rem;position:relative;overflow:hidden;transition:transform 0.15s,box-shadow 0.15s;"
             onmouseover="this.style.transform='translateY(-2px)';this.style.boxShadow='0 6px 20px <?= $tc ?>33'"
             onmouseout="this.style.transform='';this.style.boxShadow=''">
            <div style="position:absolute;top:-10px;right:-10px;width:50px;height:50px;border-radius:50%;background:<?= $tc ?>15;"></div>
            <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:0.6rem;">
                <div style="width:32px;height:32px;border-radius:8px;background:<?= $tc ?>20;display:flex;align-items:center;justify-content:center;">
                    <i class="bi <?= $icon ?>" style="color:<?= $tc ?>;font-size:0.9rem;"></i>
                </div>
                <i class="bi bi-arrow-right-short" style="color:<?= $tc ?>;opacity:0.4;font-size:1rem;"></i>
            </div>
            <?php
            $talisay_poll_keys = ['talisay_pending','talisay_total','talisay_approved','talisay_reviewed'];
            static $talisay_kpi_idx = 0;
            $tpk = $talisay_poll_keys[$talisay_kpi_idx] ?? '';
            $talisay_kpi_idx++;
            ?>
            <div style="font-size:1.9rem;font-weight:800;color:<?= $tc ?>;line-height:1;letter-spacing:-1px;"
                 data-poll-key="<?= $tpk ?>"><?= $val ?></div>
            <div style="font-size:0.7rem;font-weight:600;color:#64748b;margin-top:4px;text-transform:uppercase;letter-spacing:0.04em;"><?= $label ?></div>
        </div>
    </a>
<?php endforeach; ?>
</div>

<!-- ── Pending queue ── -->
<div class="neon-card" style="padding:0;overflow:hidden;margin-bottom:1.25rem;">
    <div style="padding:0.9rem 1.25rem;border-bottom:1px solid #f1f5f9;display:flex;align-items:center;justify-content:space-between;">
        <div>
            <div style="font-weight:700;color:#1a3a6b;font-size:0.88rem;">
                <i class="bi bi-inbox-fill me-2" style="color:#1a3a6b;"></i>Pending Talisay Review
            </div>
            <div style="font-size:0.7rem;color:#94a3b8;margin-top:1px;">Applications awaiting your final decision</div>
        </div>
        <a href="?page=review_queue" style="font-size:0.72rem;font-weight:600;color:#1a3a6b;text-decoration:none;padding:0.3rem 0.8rem;border-radius:6px;border:1px solid #dbeafe;background:#f0f4fb;">View All</a>
    </div>
    <?php if ($queue): ?>
    <table style="width:100%;border-collapse:collapse;font-size:0.83rem;">
        <thead>
            <tr style="background:#f8fafc;border-bottom:2px solid #e2e8f0;">
                <th style="padding:0.6rem 1rem;text-align:left;font-size:0.68rem;font-weight:700;text-transform:uppercase;letter-spacing:0.05em;color:#64748b;">Faculty</th>
                <th style="padding:0.6rem 0.75rem;text-align:left;font-size:0.68rem;font-weight:700;text-transform:uppercase;letter-spacing:0.05em;color:#64748b;">Campus</th>
                <th style="padding:0.6rem 0.75rem;text-align:center;font-size:0.68rem;font-weight:700;text-transform:uppercase;letter-spacing:0.05em;color:#64748b;">Score</th>
                <th style="padding:0.6rem 0.75rem;text-align:left;font-size:0.68rem;font-weight:700;text-transform:uppercase;letter-spacing:0.05em;color:#64748b;">Waiting</th>
                <th style="padding:0.6rem 0.75rem;text-align:center;font-size:0.68rem;font-weight:700;text-transform:uppercase;letter-spacing:0.05em;color:#64748b;">Action</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($queue as $q):
            $wait=(int)$q['days_waiting']; $wc=$wait>=5?'#334155':($wait>=3?'#475569':'#64748b');
            $score=(float)$q['weighted_score'];
            $sc=$score>=71?'#1e4d8c':($score>=41?'#1e4d8c':'#334155');
        ?>
        <tr style="border-bottom:1px solid #f0f4fb;" onmouseover="this.style.background='#f8fafc'" onmouseout="this.style.background=''">
            <td style="padding:0.75rem 1rem;">
                <div style="font-weight:600;color:#1e293b;"><?= htmlspecialchars(formatDisplayName($q)) ?></div>
                <div style="font-size:0.72rem;color:#94a3b8;margin-top:1px;"><?= sanitize($q['rank'] ?? '') ?></div>
            </td>
            <td style="padding:0.75rem 0.75rem;color:#64748b;font-size:0.82rem;"><?= sanitize($q['campus_name']) ?></td>
            <td style="padding:0.75rem 0.75rem;text-align:center;">
                <span style="background:<?= $sc ?>;color:#fff;padding:0.2rem 0.65rem;border-radius:20px;font-weight:700;font-size:0.78rem;">
                    <?= number_format($score,2) ?>
                </span>
            </td>
            <td style="padding:0.75rem 0.75rem;">
                <span style="color:<?= $wc ?>;font-weight:600;font-size:0.82rem;"><?= $wait ?>d</span>
            </td>
            <td style="padding:0.75rem 0.75rem;text-align:center;">
                <a href="?page=review_application&id=<?= $q['application_id'] ?>"
                   style="padding:0.3rem 0.85rem;border-radius:6px;background:#1a3a6b;color:#fff;
                          font-size:0.73rem;font-weight:600;text-decoration:none;">
                    Review
                </a>
            </td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <?php else: ?>
    <div style="text-align:center;padding:3rem;color:#94a3b8;">
        <i class="bi bi-check2-circle" style="font-size:2.5rem;color:#dbeafe;display:block;margin-bottom:0.75rem;"></i>
        <div style="font-size:0.88rem;font-weight:600;">No applications pending Talisay review.</div>
        <div style="font-size:0.78rem;margin-top:0.3rem;">Check back when campus checkers forward applications.</div>
    </div>
    <?php endif; ?>
</div>

<!-- ── Recently reviewed ── -->
<?php if ($recent): ?>
<div class="neon-card" style="padding:0;overflow:hidden;">
    <div style="padding:0.9rem 1.25rem;border-bottom:1px solid #f1f5f9;display:flex;align-items:center;justify-content:space-between;">
        <div style="font-weight:700;color:#1a3a6b;font-size:0.88rem;">
            <i class="bi bi-clock-history me-2" style="color:#1a3a6b;"></i>Recently Reviewed
        </div>
        <a href="?page=review_queue" style="font-size:0.72rem;font-weight:600;color:#1a3a6b;text-decoration:none;padding:0.3rem 0.8rem;border-radius:6px;border:1px solid #dbeafe;background:#f0f4fb;">View All</a>
    </div>
    <table style="width:100%;border-collapse:collapse;font-size:0.82rem;">
        <thead>
            <tr style="background:#f8fafc;border-bottom:2px solid #e2e8f0;">
                <th style="padding:0.6rem 1rem;text-align:left;font-size:0.68rem;font-weight:700;text-transform:uppercase;letter-spacing:0.05em;color:#64748b;">Faculty</th>
                <th style="padding:0.6rem 0.75rem;text-align:left;font-size:0.68rem;font-weight:700;text-transform:uppercase;letter-spacing:0.05em;color:#64748b;">Campus</th>
                <th style="padding:0.6rem 0.75rem;text-align:left;font-size:0.68rem;font-weight:700;text-transform:uppercase;letter-spacing:0.05em;color:#64748b;">Outcome</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($recent as $r): ?>
        <tr style="border-bottom:1px solid #f0f4fb;" onmouseover="this.style.background='#f8fafc'" onmouseout="this.style.background=''">
            <td style="padding:0.7rem 1rem;font-weight:600;color:#1e293b;"><?= htmlspecialchars(formatDisplayName($r)) ?></td>
            <td style="padding:0.7rem 0.75rem;color:#64748b;"><?= sanitize($r['campus_name']) ?></td>
            <td style="padding:0.7rem 0.75rem;"><?= statusBadge($r['status']) ?></td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>
<?php endif; ?>
