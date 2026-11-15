<?php
$uid   = $_SESSION['user_id'];
$cycle = getActiveCycle($pdo);
ensureKraAssignmentTable($pdo);

try { $pdo->query("SELECT review_id FROM application_checker_reviews LIMIT 1"); }
catch (\Exception $e) {
    $pdo->exec("CREATE TABLE IF NOT EXISTS application_checker_reviews (
        review_id INT AUTO_INCREMENT PRIMARY KEY, application_id INT NOT NULL, checker_id INT NOT NULL,
        decision ENUM('approved','rejected','pending') DEFAULT 'pending', remarks TEXT, decided_at TIMESTAMP NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uq_app_checker (application_id, checker_id),
        FOREIGN KEY (application_id) REFERENCES applications(application_id) ON DELETE CASCADE,
        FOREIGN KEY (checker_id) REFERENCES users(user_id) ON DELETE CASCADE)");
}

// ── Core counts ────────────────────────────────────────────────
$pending_stmt = $pdo->prepare("SELECT COUNT(*) FROM applications a
    WHERE a.status IN ('submitted','under_review') AND a.user_id != ?
    AND EXISTS (SELECT 1 FROM kra_submissions ks
        JOIN checker_kra_assignments ka ON ka.cycle_id=a.cycle_id AND ka.kra_category=ks.kra_category AND ka.checker_id=?
        WHERE ks.application_id=a.application_id)
    AND NOT EXISTS (SELECT 1 FROM application_checker_reviews r
        WHERE r.application_id=a.application_id AND r.checker_id=? AND r.decision IN ('approved','rejected'))");
$pending_stmt->execute([$uid,$uid,$uid]);
$pending = (int)$pending_stmt->fetchColumn();

$needs_revision_stmt = $pdo->prepare("SELECT COUNT(*) FROM applications a
    WHERE a.checker_id=? AND a.status='needs_revision'
    AND EXISTS (SELECT 1 FROM kra_submissions ks
        JOIN checker_kra_assignments ka ON ka.cycle_id=a.cycle_id AND ka.kra_category=ks.kra_category AND ka.checker_id=?
        WHERE ks.application_id=a.application_id)");
$needs_revision_stmt->execute([$uid, $uid]);
$needs_revision = (int)$needs_revision_stmt->fetchColumn();

$inprogress_count_stmt = $pdo->prepare("SELECT COUNT(*) FROM applications a
    WHERE a.status = 'under_review' AND a.checker_id=? AND a.user_id != ?
    AND EXISTS (SELECT 1 FROM kra_submissions ks
        JOIN checker_kra_assignments ka ON ka.cycle_id=a.cycle_id AND ka.kra_category=ks.kra_category AND ka.checker_id=?
        WHERE ks.application_id=a.application_id)");
$inprogress_count_stmt->execute([$uid, $uid, $uid]);
$inprogress_count = (int)$inprogress_count_stmt->fetchColumn();

$my_stats_stmt = $pdo->prepare("SELECT COUNT(*) AS total_reviewed,
    SUM(CASE WHEN r.decision='approved' THEN 1 ELSE 0 END) AS approved
    FROM application_checker_reviews r
    WHERE r.checker_id=? AND r.decision IN ('approved','rejected')");
$my_stats_stmt->execute([$uid]);
$my_stats     = $my_stats_stmt->fetch();
$total_decided = (int)$my_stats['total_reviewed'];
$total_approved = (int)$my_stats['approved'];

// KRA assignments for this evaluator
$my_kras = [];
if ($cycle) {
    $kra_stmt = $pdo->prepare("SELECT kra_category FROM checker_kra_assignments WHERE checker_id=? AND cycle_id=?");
    $kra_stmt->execute([$uid, (int)$cycle['cycle_id']]);
    $my_kras = array_column($kra_stmt->fetchAll(), 'kra_category');
}

// ── Urgent queue: awaiting my decision, sorted by deadline proximity ──
$queue_stmt = $pdo->prepare("SELECT a.application_id, a.status, a.weighted_score, a.submitted_at,
    u.first_name, u.middle_name, u.last_name, u.`rank`,
    COALESCE(camp.campus_name,'-') AS campus_name,
    c.evaluation_deadline,
    DATEDIFF(NOW(), a.submitted_at) AS days_waiting
    FROM applications a
    JOIN users u ON a.user_id=u.user_id
    LEFT JOIN campuses camp ON u.campus_id=camp.campus_id
    LEFT JOIN cycles c ON a.cycle_id=c.cycle_id
    WHERE a.status IN ('submitted','under_review') AND a.user_id != ?
    AND EXISTS (SELECT 1 FROM kra_submissions ks
        JOIN checker_kra_assignments ka ON ka.cycle_id=a.cycle_id AND ka.kra_category=ks.kra_category AND ka.checker_id=?
        WHERE ks.application_id=a.application_id)
    AND NOT EXISTS (SELECT 1 FROM application_checker_reviews r
        WHERE r.application_id=a.application_id AND r.checker_id=? AND r.decision IN ('approved','rejected'))
    ORDER BY c.evaluation_deadline ASC, a.submitted_at ASC
    LIMIT 10");
$queue_stmt->execute([$uid,$uid,$uid]);
$queue = $queue_stmt->fetchAll();

// ── Needs revision: awaiting faculty resubmission ──────────────
$revision_stmt = $pdo->prepare("SELECT a.application_id, a.weighted_score, a.updated_at,
    u.first_name, u.middle_name, u.last_name,
    COALESCE(camp.campus_name,'-') AS campus_name,
    c.evaluation_deadline,
    DATEDIFF(NOW(), a.updated_at) AS days_waiting,
    (SELECT COUNT(*) FROM kra_submissions ks2 WHERE ks2.application_id=a.application_id AND ks2.revision_status='needs_revision') AS flagged_count
    FROM applications a
    JOIN users u ON a.user_id=u.user_id
    LEFT JOIN campuses camp ON u.campus_id=camp.campus_id
    LEFT JOIN cycles c ON a.cycle_id=c.cycle_id
    WHERE a.checker_id=? AND a.status='needs_revision'
    AND EXISTS (SELECT 1 FROM kra_submissions ks
        JOIN checker_kra_assignments ka ON ka.cycle_id=a.cycle_id AND ka.kra_category=ks.kra_category AND ka.checker_id=?
        WHERE ks.application_id=a.application_id)
    ORDER BY a.updated_at ASC LIMIT 8");
$revision_stmt->execute([$uid, $uid]);
$revision_apps = $revision_stmt->fetchAll();

// ── In progress ────────────────────────────────────────────────
$inprogress_stmt = $pdo->prepare("SELECT a.application_id, a.status, a.weighted_score, a.submitted_at,
    u.first_name, u.middle_name, u.last_name, u.`rank`,
    COALESCE(camp.campus_name,'-') AS campus_name,
    c.evaluation_deadline,
    DATEDIFF(NOW(), a.submitted_at) AS days_waiting,
    (SELECT COUNT(*) FROM kra_submissions ks2 WHERE ks2.application_id=a.application_id AND ks2.verified=1) AS verified_count,
    (SELECT COUNT(*) FROM kra_submissions ks2 WHERE ks2.application_id=a.application_id) AS total_subs
    FROM applications a
    JOIN users u ON a.user_id=u.user_id
    LEFT JOIN campuses camp ON u.campus_id=camp.campus_id
    LEFT JOIN cycles c ON a.cycle_id=c.cycle_id
    WHERE a.status = 'under_review' AND a.checker_id=? AND a.user_id != ?
    AND EXISTS (SELECT 1 FROM kra_submissions ks
        JOIN checker_kra_assignments ka ON ka.cycle_id=a.cycle_id AND ka.kra_category=ks.kra_category AND ka.checker_id=?
        WHERE ks.application_id=a.application_id)
    ORDER BY a.submitted_at ASC LIMIT 6");
$inprogress_stmt->execute([$uid, $uid, $uid]);
$inprogress = $inprogress_stmt->fetchAll();

// ── Recent completed (by MY decided_at, not app final status) ─
$recent_stmt = $pdo->prepare("SELECT a.application_id, a.status, a.weighted_score, a.potential_rank,
    r.decision, r.decided_at,
    u.first_name, u.middle_name, u.last_name,
    COALESCE(camp.campus_name,'-') AS campus_name
    FROM application_checker_reviews r
    JOIN applications a ON r.application_id=a.application_id
    JOIN users u ON a.user_id=u.user_id
    LEFT JOIN campuses camp ON u.campus_id=camp.campus_id
    WHERE r.checker_id=? AND r.decision IN ('approved','rejected')
    ORDER BY r.decided_at DESC LIMIT 5");
$recent_stmt->execute([$uid]);
$recent = $recent_stmt->fetchAll();

// ── Deadline label helper (inline, avoids function re-def if included elsewhere) ──
$deadlineLabel = function(?string $dl): array {
    if (!$dl) return ['No deadline', '#94a3b8', '#f8fafc'];
    $days = (int)ceil((strtotime($dl . ' 23:59:59') - time()) / 86400);
    if ($days < 0)  return ['Overdue',       '#dc2626', '#fef2f2'];
    if ($days <= 3) return [$days.'d left',   '#c2410c', '#fff7ed'];
    if ($days <= 7) return [$days.'d left',   '#d97706', '#fffbeb'];
    return [date('M d', strtotime($dl)), '#475569', '#f8fafc'];
};
?>
<?php include __DIR__ . '/_notif_banner_snippet.php'; ?>
<?php include __DIR__ . '/_dashboard_header.php'; ?>

<!-- ── KPI Row ───────────────────────────────────────────────── -->
<div style="display:grid;grid-template-columns:repeat(4,1fr);gap:0.65rem;margin-bottom:1.1rem;">
<?php
$kpis = [
    [
        'label'   => 'Awaiting My Decision',
        'value'   => $pending,
        'icon'    => 'bi-inbox-fill',
        'accent'  => $pending > 0 ? '#1a3a6b' : '#94a3b8',
        'link'    => '?page=review_queue&filter=pending_decision',
        'urgent'  => $pending > 0,
        'poll'    => 'checker_pending',
    ],
    [
        'label'   => 'In Progress',
        'value'   => $inprogress_count,
        'icon'    => 'bi-hourglass-split',
        'accent'  => $inprogress_count > 0 ? '#1e4d8c' : '#94a3b8',
        'link'    => '?page=review_queue&filter=under_review',
        'urgent'  => false,
        'poll'    => 'checker_active',
    ],
    [
        'label'   => 'Awaiting Revision',
        'value'   => $needs_revision,
        'icon'    => 'bi-arrow-counterclockwise',
        'accent'  => $needs_revision > 0 ? '#c2410c' : '#94a3b8',
        'link'    => '?page=review_queue&filter=needs_revision',
        'urgent'  => $needs_revision > 0,
        'poll'    => 'checker_revision',
    ],
    [
        'label'   => 'I Have Decided',
        'value'   => $total_decided,
        'icon'    => 'bi-check2-all',
        'accent'  => '#475569',
        'link'    => '?page=review_queue&filter=my_reviewed',
        'urgent'  => false,
        'poll'    => '',
    ],
];
foreach ($kpis as $k):
?>
<a href="<?= $k['link'] ?>" style="text-decoration:none;display:block;">
    <div style="background:#fff;border:1px solid <?= $k['urgent'] ? $k['accent'].'55' : '#e2e8f0' ?>;
                border-top:3px solid <?= $k['accent'] ?>;padding:0.9rem 1rem;
                transition:box-shadow 0.15s;"
         onmouseover="this.style.boxShadow='0 4px 14px rgba(0,0,0,0.08)'"
         onmouseout="this.style.boxShadow=''">
        <div style="display:flex;align-items:flex-start;justify-content:space-between;margin-bottom:0.6rem;">
            <div style="width:32px;height:32px;background:<?= $k['accent'] ?>18;display:flex;align-items:center;justify-content:center;flex-shrink:0;">
                <i class="bi <?= $k['icon'] ?>" style="color:<?= $k['accent'] ?>;font-size:0.88rem;"></i>
            </div>
            <i class="bi bi-arrow-right" style="color:#e2e8f0;font-size:0.75rem;margin-top:4px;"></i>
        </div>
        <div style="font-size:1.85rem;font-weight:800;color:<?= $k['accent'] ?>;line-height:1;letter-spacing:-1px;"
             <?= $k['poll'] ? 'data-poll-key="'.$k['poll'].'"' : '' ?>><?= $k['value'] ?></div>
        <div style="font-size:0.64rem;font-weight:700;color:#94a3b8;margin-top:5px;text-transform:uppercase;letter-spacing:0.05em;"><?= $k['label'] ?></div>
    </div>
</a>
<?php endforeach; ?>
</div>

<!-- ── My KRA Assignments ────────────────────────────────────── -->
<?php if ($cycle && $my_kras): ?>
<div style="background:#fff;border:1px solid #e2e8f0;padding:0.75rem 1rem;margin-bottom:1.1rem;
            display:flex;align-items:center;gap:1rem;flex-wrap:wrap;">
    <div style="font-size:0.65rem;font-weight:700;color:#94a3b8;text-transform:uppercase;letter-spacing:0.07em;flex-shrink:0;">
        My Assigned KRAs
    </div>
    <div style="display:flex;gap:0.4rem;flex-wrap:wrap;">
    <?php
    $kra_labels = ['Instruction'=>'KRA I','Research'=>'KRA II','Extension'=>'KRA III','Professional Development'=>'KRA IV'];
    foreach ($my_kras as $kra):
        $short = $kra_labels[$kra] ?? $kra;
    ?>
        <span style="background:#eff6ff;color:#1a3a6b;border:1px solid #bfdbfe;font-size:0.68rem;font-weight:700;padding:2px 9px;">
            <?= htmlspecialchars($short) ?> <span style="font-weight:400;color:#64748b;">· <?= htmlspecialchars($kra) ?></span>
        </span>
    <?php endforeach; ?>
    </div>
</div>
<?php elseif ($cycle): ?>
<div style="background:#fffbeb;border:1px solid #fde68a;border-left:3px solid #d97706;padding:0.65rem 1rem;margin-bottom:1.1rem;font-size:0.8rem;color:#92400e;display:flex;align-items:center;gap:0.5rem;">
    <i class="bi bi-exclamation-triangle-fill"></i>
    <strong>No KRA assignments found for this cycle.</strong> Contact the administrator to assign KRAs to your account.
</div>
<?php endif; ?>

<!-- ── Main 2-column layout ──────────────────────────────────── -->
<div style="display:grid;grid-template-columns:1fr 340px;gap:0.85rem;align-items:start;">

    <!-- LEFT: Action lists ──────────────────────────────────── -->
    <div style="display:flex;flex-direction:column;gap:0.85rem;">

        <!-- Awaiting My Decision -->
        <div style="background:#fff;border:1px solid #e2e8f0;overflow:hidden;">
            <div style="padding:0.7rem 1rem;border-bottom:1px solid #e2e8f0;background:#f8fafc;
                        display:flex;align-items:center;justify-content:space-between;">
                <div style="display:flex;align-items:center;gap:0.5rem;">
                    <div style="width:6px;height:6px;border-radius:50%;background:<?= $pending>0?'#1a3a6b':'#cbd5e1' ?>;<?= $pending>0?'animation:dotPulse 1.8s ease-in-out infinite;':'' ?>"></div>
                    <span style="font-size:0.7rem;font-weight:700;color:#1e293b;text-transform:uppercase;letter-spacing:0.07em;">
                        Awaiting My Decision
                    </span>
                    <?php if ($pending > 0): ?>
                    <span style="background:#1a3a6b;color:#fff;font-size:0.58rem;font-weight:800;padding:1px 6px;min-width:18px;text-align:center;"><?= $pending ?></span>
                    <?php endif; ?>
                </div>
                <?php if (count($queue) > 0): ?>
                <a href="?page=review_queue&filter=pending_decision" style="font-size:0.68rem;color:#1a3a6b;font-weight:600;text-decoration:none;">All <?= $pending ?> →</a>
                <?php endif; ?>
            </div>

            <?php if ($queue): ?>
            <?php foreach ($queue as $q):
                $wait = (int)$q['days_waiting'];
                [$due_label, $due_color] = $deadlineLabel($q['evaluation_deadline'] ?? null);
                $score = (float)$q['weighted_score'];
                $score_color = $score >= 41 ? '#16a34a' : '#c2410c';
            ?>
            <div style="display:grid;grid-template-columns:1fr auto;align-items:center;gap:0.75rem;
                        padding:0.8rem 1rem;border-bottom:1px solid #f1f5f9;"
                 onmouseover="this.style.background='#fafbff'" onmouseout="this.style.background=''">
                <div>
                    <div style="font-weight:600;font-size:0.83rem;color:#0f172a;line-height:1.3;">
                        <?= htmlspecialchars(formatDisplayName($q)) ?>
                    </div>
                    <div style="display:flex;align-items:center;gap:0.5rem;flex-wrap:wrap;margin-top:3px;">
                        <span style="font-size:0.7rem;color:#64748b;"><?= sanitize($q['campus_name']) ?></span>
                        <span style="color:#e2e8f0;">·</span>
                        <span style="font-size:0.7rem;font-weight:700;color:<?= $score_color ?>;"><?= number_format($score,2) ?> pts</span>
                        <span style="color:#e2e8f0;">·</span>
                        <span style="font-size:0.68rem;color:<?= $wait>=7?'#dc2626':($wait>=3?'#d97706':'#94a3b8') ?>;font-weight:<?= $wait>=3?'700':'400' ?>;">
                            <?= $wait ?>d waiting
                        </span>
                        <span style="font-size:0.65rem;font-weight:700;color:<?= $due_color ?>;background:<?= $due_color ?>14;padding:1px 7px;">
                            <?= htmlspecialchars($due_label) ?>
                        </span>
                    </div>
                </div>
                <a href="?page=review_application&id=<?= $q['application_id'] ?>"
                   style="padding:0.35rem 0.85rem;background:#1a3a6b;color:#fff;font-size:0.72rem;font-weight:700;text-decoration:none;white-space:nowrap;flex-shrink:0;">
                    Review →
                </a>
            </div>
            <?php endforeach; ?>
            <?php else: ?>
            <div style="padding:2.5rem 1rem;text-align:center;color:#94a3b8;">
                <i class="bi bi-check2-circle" style="font-size:1.75rem;color:#dbeafe;display:block;margin-bottom:0.5rem;"></i>
                <div style="font-size:0.8rem;">Queue is clear. All caught up.</div>
            </div>
            <?php endif; ?>
        </div>

        <!-- In Progress -->
        <?php if ($inprogress): ?>
        <div style="background:#fff;border:1px solid #e2e8f0;overflow:hidden;">
            <div style="padding:0.7rem 1rem;border-bottom:1px solid #e2e8f0;background:#f8fafc;
                        display:flex;align-items:center;justify-content:space-between;">
                <div style="display:flex;align-items:center;gap:0.5rem;">
                    <span style="font-size:0.7rem;font-weight:700;color:#1e293b;text-transform:uppercase;letter-spacing:0.07em;">In Progress</span>
                    <span style="background:#e2e8f0;color:#475569;font-size:0.58rem;font-weight:800;padding:1px 6px;"><?= $inprogress_count ?></span>
                </div>
                <a href="?page=review_queue&filter=under_review" style="font-size:0.68rem;color:#1a3a6b;font-weight:600;text-decoration:none;">View all →</a>
            </div>
            <?php foreach ($inprogress as $ip):
                $total_subs = max(1, (int)$ip['total_subs']);
                $verified   = (int)$ip['verified_count'];
                $pct        = (int)round(($verified / $total_subs) * 100);
                [$due_label, $due_color] = $deadlineLabel($ip['evaluation_deadline'] ?? null);
            ?>
            <div style="padding:0.75rem 1rem;border-bottom:1px solid #f1f5f9;display:grid;grid-template-columns:1fr auto;gap:0.75rem;align-items:center;"
                 onmouseover="this.style.background='#fafbff'" onmouseout="this.style.background=''">
                <div>
                    <div style="font-weight:600;font-size:0.83rem;color:#0f172a;"><?= htmlspecialchars(formatDisplayName($ip)) ?></div>
                    <div style="display:flex;align-items:center;gap:0.5rem;margin-top:4px;flex-wrap:wrap;">
                        <span style="font-size:0.7rem;color:#64748b;"><?= sanitize($ip['campus_name']) ?></span>
                        <span style="font-size:0.65rem;font-weight:700;color:<?= $due_color ?>;background:<?= $due_color ?>14;padding:1px 7px;"><?= htmlspecialchars($due_label) ?></span>
                    </div>
                    <!-- Evidence progress bar -->
                    <div style="margin-top:6px;display:flex;align-items:center;gap:0.5rem;">
                        <div style="flex:1;height:4px;background:#f1f5f9;overflow:hidden;">
                            <div style="height:100%;width:<?= $pct ?>%;background:<?= $pct>=100?'#16a34a':'#1a3a6b' ?>;transition:width 0.3s;"></div>
                        </div>
                        <span style="font-size:0.63rem;color:#64748b;white-space:nowrap;flex-shrink:0;"><?= $verified ?>/<?= $total_subs ?> verified</span>
                    </div>
                </div>
                <a href="?page=review_application&id=<?= $ip['application_id'] ?>"
                   style="padding:0.3rem 0.75rem;background:#f0f4fb;color:#1a3a6b;font-size:0.72rem;font-weight:700;text-decoration:none;border:1px solid #dbeafe;white-space:nowrap;flex-shrink:0;">
                    Continue
                </a>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <!-- Awaiting Revision -->
        <?php if ($revision_apps): ?>
        <div style="background:#fff;border:1px solid #fde68a;overflow:hidden;">
            <div style="padding:0.7rem 1rem;border-bottom:1px solid #fde68a;background:#fffbeb;
                        display:flex;align-items:center;justify-content:space-between;">
                <div style="display:flex;align-items:center;gap:0.5rem;">
                    <i class="bi bi-exclamation-circle-fill" style="color:#d97706;font-size:0.75rem;"></i>
                    <span style="font-size:0.7rem;font-weight:700;color:#92400e;text-transform:uppercase;letter-spacing:0.07em;">Awaiting Faculty Revision</span>
                    <span style="background:#d97706;color:#fff;font-size:0.58rem;font-weight:800;padding:1px 6px;"><?= count($revision_apps) ?></span>
                </div>
            </div>
            <?php foreach ($revision_apps as $rv):
                $days = (int)$rv['days_waiting'];
                $flagged = (int)$rv['flagged_count'];
            ?>
            <div style="padding:0.75rem 1rem;border-bottom:1px solid #fef3c7;display:grid;grid-template-columns:1fr auto;gap:0.75rem;align-items:center;"
                 onmouseover="this.style.background='#fffdf5'" onmouseout="this.style.background=''">
                <div>
                    <div style="font-weight:600;font-size:0.83rem;color:#0f172a;"><?= htmlspecialchars(formatDisplayName($rv)) ?></div>
                    <div style="font-size:0.7rem;color:#92400e;margin-top:3px;display:flex;align-items:center;gap:0.4rem;">
                        <span><?= sanitize($rv['campus_name']) ?></span>
                        <span>·</span>
                        <span><?= $flagged ?> entr<?= $flagged===1?'y':'ies' ?> flagged</span>
                        <span>·</span>
                        <span style="color:<?= $days>=5?'#dc2626':'#d97706' ?>;font-weight:700;"><?= $days ?>d since flagged</span>
                    </div>
                </div>
                <a href="?page=review_application&id=<?= $rv['application_id'] ?>"
                   style="padding:0.3rem 0.75rem;background:#fff7ed;color:#c2410c;font-size:0.72rem;font-weight:700;text-decoration:none;border:1px solid #fde68a;white-space:nowrap;flex-shrink:0;">
                    View
                </a>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>

    </div><!-- /left -->

    <!-- RIGHT: Sidebar panels ──────────────────────────────── -->
    <div style="display:flex;flex-direction:column;gap:0.85rem;">

        <!-- My KRA Coverage for this cycle -->
        <?php if ($cycle && $my_kras):
            $kra_stats = [];
            foreach ($my_kras as $kra) {
                $stmt = $pdo->prepare("SELECT
                    COUNT(DISTINCT ks.application_id) AS total,
                    SUM(CASE WHEN ks.verified=1 THEN 1 ELSE 0 END) AS verified
                    FROM kra_submissions ks
                    JOIN applications a ON a.application_id=ks.application_id
                    WHERE ks.kra_category=? AND a.cycle_id=?
                      AND a.status IN ('submitted','under_review','needs_revision','approved','admin_rejected')");
                $stmt->execute([$kra, (int)$cycle['cycle_id']]);
                $kra_stats[$kra] = $stmt->fetch();
            }
        ?>
        <div style="background:#fff;border:1px solid #e2e8f0;overflow:hidden;">
            <div style="padding:0.65rem 1rem;border-bottom:1px solid #e2e8f0;background:#f8fafc;">
                <span style="font-size:0.65rem;font-weight:700;color:#94a3b8;text-transform:uppercase;letter-spacing:0.07em;">KRA Coverage — Current Cycle</span>
            </div>
            <div style="padding:0.75rem 1rem;display:flex;flex-direction:column;gap:0.6rem;">
            <?php foreach ($my_kras as $kra):
                $stat  = $kra_stats[$kra] ?? ['total'=>0,'verified'=>0];
                $tot   = max(1, (int)$stat['total']);
                $ver   = (int)$stat['verified'];
                $pct   = (int)round(($ver / $tot) * 100);
                $short = $kra_labels[$kra] ?? $kra;
            ?>
            <div>
                <div style="display:flex;justify-content:space-between;margin-bottom:3px;">
                    <span style="font-size:0.72rem;font-weight:600;color:#334155;"><?= htmlspecialchars($short) ?></span>
                    <span style="font-size:0.68rem;color:#94a3b8;"><?= $ver ?>/<?= (int)$stat['total'] ?> verified</span>
                </div>
                <div style="height:5px;background:#f1f5f9;overflow:hidden;">
                    <div style="height:100%;width:<?= $pct ?>%;background:<?= $pct>=100?'#16a34a':'#1a3a6b' ?>;"></div>
                </div>
            </div>
            <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>

        <!-- My Decision Summary (this evaluator's own record) -->
        <div style="background:#fff;border:1px solid #e2e8f0;overflow:hidden;">
            <div style="padding:0.65rem 1rem;border-bottom:1px solid #e2e8f0;background:#f8fafc;">
                <span style="font-size:0.65rem;font-weight:700;color:#94a3b8;text-transform:uppercase;letter-spacing:0.07em;">My Decision Summary</span>
            </div>
            <div style="padding:0.9rem 1rem;">
            <?php if ($total_decided > 0):
                $pct_complete = (int)round(($total_approved / $total_decided) * 100);
            ?>
                <div style="display:flex;align-items:flex-end;gap:0.25rem;margin-bottom:0.6rem;">
                    <span style="font-size:2.2rem;font-weight:800;color:#1a3a6b;line-height:1;"><?= $pct_complete ?></span>
                    <span style="font-size:1rem;font-weight:700;color:#94a3b8;line-height:1.8;">%</span>
                    <span style="font-size:0.7rem;color:#64748b;line-height:2;">completed</span>
                </div>
                <div style="height:6px;background:#f1f5f9;margin-bottom:0.75rem;overflow:hidden;">
                    <div style="height:100%;width:<?= $pct_complete ?>%;background:#1a3a6b;"></div>
                </div>
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:0.5rem;">
                    <div style="background:#eff6ff;border:1px solid #dbeafe;padding:0.5rem 0.65rem;text-align:center;">
                        <div style="font-size:1.2rem;font-weight:800;color:#1a3a6b;"><?= $total_approved ?></div>
                        <div style="font-size:0.6rem;color:#64748b;font-weight:600;text-transform:uppercase;letter-spacing:0.04em;">Complete</div>
                    </div>
                    <div style="background:#f8fafc;border:1px solid #e2e8f0;padding:0.5rem 0.65rem;text-align:center;">
                        <div style="font-size:1.2rem;font-weight:800;color:#475569;"><?= max(0,$total_decided-$total_approved) ?></div>
                        <div style="font-size:0.6rem;color:#64748b;font-weight:600;text-transform:uppercase;letter-spacing:0.04em;">Returned</div>
                    </div>
                </div>
            <?php else: ?>
                <div style="text-align:center;padding:0.75rem 0;color:#94a3b8;">
                    <i class="bi bi-clipboard2" style="font-size:1.5rem;display:block;margin-bottom:0.35rem;"></i>
                    <div style="font-size:0.75rem;">No decisions recorded yet.</div>
                </div>
            <?php endif; ?>
            </div>
        </div>

        <!-- Recent Decisions -->
        <?php if ($recent): ?>
        <div style="background:#fff;border:1px solid #e2e8f0;overflow:hidden;">
            <div style="padding:0.65rem 1rem;border-bottom:1px solid #e2e8f0;background:#f8fafc;display:flex;align-items:center;justify-content:space-between;">
                <span style="font-size:0.65rem;font-weight:700;color:#94a3b8;text-transform:uppercase;letter-spacing:0.07em;">Recent Decisions</span>
                <a href="?page=review_queue&filter=my_reviewed" style="font-size:0.65rem;color:#1a3a6b;font-weight:600;text-decoration:none;">All →</a>
            </div>
            <?php foreach ($recent as $r):
                $is_complete = $r['decision'] === 'approved';
                $dot_color   = $is_complete ? '#16a34a' : '#475569';
            ?>
            <div style="padding:0.6rem 1rem;border-bottom:1px solid #f8fafc;display:flex;align-items:flex-start;gap:0.6rem;"
                 onmouseover="this.style.background='#fafbff'" onmouseout="this.style.background=''">
                <div style="width:7px;height:7px;border-radius:50%;background:<?= $dot_color ?>;flex-shrink:0;margin-top:5px;"></div>
                <div style="flex:1;min-width:0;">
                    <div style="font-size:0.78rem;font-weight:600;color:#1e293b;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;"><?= htmlspecialchars(formatDisplayName($r)) ?></div>
                    <div style="font-size:0.67rem;color:#94a3b8;display:flex;align-items:center;gap:0.35rem;flex-wrap:wrap;margin-top:1px;">
                        <span style="color:<?= $dot_color ?>;font-weight:700;"><?= $is_complete ? 'Completed' : 'Returned' ?></span>
                        <span>·</span>
                        <span><?= $r['decided_at'] ? date('M d', strtotime($r['decided_at'])) : '-' ?></span>
                        <?php if (!empty($r['potential_rank'])): ?>
                        <span>·</span>
                        <span style="color:#1a3a6b;"><?= htmlspecialchars($r['potential_rank']) ?></span>
                        <?php endif; ?>
                    </div>
                </div>
                <a href="?page=review_application&id=<?= $r['application_id'] ?>"
                   style="font-size:0.65rem;color:#64748b;text-decoration:none;flex-shrink:0;"
                   title="View">
                    <i class="bi bi-arrow-up-right"></i>
                </a>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>

    </div><!-- /right -->

</div><!-- /grid -->

<style>
@keyframes dotPulse {
    0%,100%{opacity:1;transform:scale(1)}
    50%{opacity:0.4;transform:scale(1.4)}
}
</style>
