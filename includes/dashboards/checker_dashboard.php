<?php
$uid   = $_SESSION['user_id'];
$cycle = getActiveCycle($pdo);

try { $pdo->query("SELECT review_id FROM application_checker_reviews LIMIT 1"); }
catch (\Exception $e) {
    $pdo->exec("CREATE TABLE IF NOT EXISTS application_checker_reviews (
        review_id INT AUTO_INCREMENT PRIMARY KEY, application_id INT NOT NULL, checker_id INT NOT NULL,
        decision ENUM('approved','pending') DEFAULT 'pending', remarks TEXT, decided_at TIMESTAMP NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uq_app_checker (application_id, checker_id),
        FOREIGN KEY (application_id) REFERENCES applications(application_id) ON DELETE CASCADE,
        FOREIGN KEY (checker_id) REFERENCES users(user_id) ON DELETE CASCADE)");
}

$pending_stmt = $pdo->prepare("SELECT COUNT(*) FROM applications a WHERE a.status IN ('submitted','under_review') AND a.user_id != ? AND NOT EXISTS (SELECT 1 FROM application_checker_reviews r WHERE r.application_id=a.application_id AND r.checker_id=? AND r.decision IN ('approved','rejected'))");
$pending_stmt->execute([$uid,$uid]); $pending = (int)$pending_stmt->fetchColumn();

$my_under_review_stmt = $pdo->prepare("SELECT COUNT(*) FROM applications a WHERE a.status IN ('under_review','needs_revision') AND a.checker_id=? AND a.user_id != ?");
$my_under_review_stmt->execute([$uid, $uid]); $my_under_review = (int)$my_under_review_stmt->fetchColumn();

$needs_revision_stmt = $pdo->prepare("SELECT COUNT(*) FROM applications WHERE checker_id=? AND status='needs_revision'");
$needs_revision_stmt->execute([$uid]); $needs_revision = (int)$needs_revision_stmt->fetchColumn();

$my_stats_stmt = $pdo->prepare("SELECT COUNT(*) AS total_reviewed, SUM(CASE WHEN r.decision='approved' THEN 1 ELSE 0 END) AS approved FROM application_checker_reviews r JOIN applications a ON r.application_id=a.application_id WHERE r.checker_id=? AND a.status IN ('approved','admin_rejected')");
$my_stats_stmt->execute([$uid]); $my_stats = $my_stats_stmt->fetch();
$total_reviewed = (int)$my_stats['total_reviewed']; $total_approved = (int)$my_stats['approved'];

$total_checkers_dash = max(1,(int)$pdo->query("SELECT COUNT(*) FROM users WHERE role = 'checker' AND status='active'")->fetchColumn());

$queue_stmt = $pdo->prepare("SELECT a.*, u.full_name, u.first_name, u.middle_name, u.last_name, u.rank, COALESCE(camp.campus_name,'—') AS campus_name, c.cycle_name, DATEDIFF(NOW(),a.submitted_at) AS days_waiting FROM applications a JOIN users u ON a.user_id=u.user_id LEFT JOIN campuses camp ON u.campus_id=camp.campus_id JOIN cycles c ON a.cycle_id=c.cycle_id WHERE a.status IN ('submitted','under_review') AND a.user_id!=? AND NOT EXISTS (SELECT 1 FROM application_checker_reviews r WHERE r.application_id=a.application_id AND r.checker_id=? AND r.decision IN ('approved','rejected')) ORDER BY a.submitted_at ASC LIMIT 8");
$queue_stmt->execute([$uid,$uid]); $queue = $queue_stmt->fetchAll();

$revision_stmt = $pdo->prepare("SELECT a.*, u.full_name, u.first_name, u.middle_name, u.last_name, COALESCE(camp.campus_name,'—') AS campus_name, DATEDIFF(NOW(),a.updated_at) AS days_waiting FROM applications a JOIN users u ON a.user_id=u.user_id LEFT JOIN campuses camp ON u.campus_id=camp.campus_id WHERE a.checker_id=? AND a.status='needs_revision' ORDER BY a.updated_at ASC");
$revision_stmt->execute([$uid]); $revision_apps = $revision_stmt->fetchAll();

$inprogress_stmt = $pdo->prepare("SELECT a.*, u.full_name, u.first_name, u.middle_name, u.last_name, u.rank, COALESCE(camp.campus_name,'—') AS campus_name, DATEDIFF(NOW(),a.submitted_at) AS days_waiting, NULL AS my_decision, (SELECT COUNT(*) FROM application_checker_reviews r2 JOIN users uc2 ON r2.checker_id=uc2.user_id WHERE r2.application_id=a.application_id AND r2.decision='approved' AND uc2.role = 'checker') AS approvals_count FROM applications a JOIN users u ON a.user_id=u.user_id LEFT JOIN campuses camp ON u.campus_id=camp.campus_id WHERE a.status IN ('under_review','needs_revision') AND a.checker_id=? AND a.user_id != ? ORDER BY a.submitted_at ASC LIMIT 5");
$inprogress_stmt->execute([$uid, $uid]); $inprogress = $inprogress_stmt->fetchAll();

$recent_stmt = $pdo->prepare("SELECT a.*, u.full_name, u.first_name, u.middle_name, u.last_name, COALESCE(camp.campus_name,'—') AS campus_name FROM applications a JOIN users u ON a.user_id=u.user_id LEFT JOIN campuses camp ON u.campus_id=camp.campus_id JOIN application_checker_reviews r ON r.application_id=a.application_id AND r.checker_id=? WHERE a.status IN ('approved','admin_rejected') ORDER BY a.reviewed_at DESC LIMIT 5");
$recent_stmt->execute([$uid]); $recent = $recent_stmt->fetchAll();
?>
<?php include __DIR__ . '/_notif_banner_snippet.php'; ?>

<!-- ══════════════════════════════════════════════
     PAGE HEADER
═══════════════════════════════════════════════ -->
<div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:1rem;margin-bottom:1.5rem;">
    <div style="display:flex;align-items:center;gap:0.85rem;">
        <?php
        $pic     = $_SESSION['profile_pic'] ?? '';
        $init_h = strtoupper(
            substr($_SESSION['first_name'] ?? '', 0, 1) .
            substr($_SESSION['last_name']  ?? '', 0, 1)
        ) ?: 'C';
        ?>
        <?php if ($pic): ?>
        <img src="<?= sanitize($pic) ?>" style="width:48px;height:48px;border-radius:12px;object-fit:cover;border:2px solid #e2e8f0;flex-shrink:0;">
        <?php else: ?>
        <div style="width:48px;height:48px;border-radius:12px;background:#1a3a6b;display:flex;align-items:center;justify-content:center;font-size:0.95rem;font-weight:700;color:#fff;flex-shrink:0;"><?= $init_h ?></div>
        <?php endif; ?>
        <div>
            <div style="font-size:1rem;font-weight:700;color:#0f172a;line-height:1.25;">Welcome back, <?= sanitize($_SESSION['full_name'] ?? 'Checker') ?></div>
            <div style="font-size:0.72rem;color:#94a3b8;margin-top:2px;display:flex;align-items:center;gap:0.5rem;">
                <i class="bi bi-calendar3" style="font-size:0.7rem;"></i><?= date('l, F j, Y') ?>
                &ensp;<span style="background:#dbeafe;color:#1e4d8c;font-size:0.62rem;font-weight:700;letter-spacing:0.04em;padding:1px 7px;border-radius:4px;border:1px solid #bfdbfe;">CHECKER</span>
            </div>
        </div>
    </div>
    <a href="?page=review_queue" style="display:inline-flex;align-items:center;gap:0.5rem;padding:0.5rem 1.15rem;border-radius:8px;background:#1a3a6b;color:#fff;font-size:0.82rem;font-weight:600;text-decoration:none;box-shadow:0 2px 8px rgba(26,58,107,0.25);">
        <i class="bi bi-inbox-fill"></i>Review Queue
        <?php if ($pending > 0): ?>
        <span style="background:rgba(255,255,255,0.2);color:#fff;font-size:0.65rem;font-weight:700;border-radius:20px;min-width:20px;height:20px;display:inline-flex;align-items:center;justify-content:center;padding:0 5px;"><?= $pending ?></span>
        <?php endif; ?>
    </a>
</div>

<!-- ══════════════════════════════════════════════
     CYCLE BANNER
═══════════════════════════════════════════════ -->
<?php if ($cycle): ?>
<div style="background:#1a3a6b;border-radius:10px;padding:0.9rem 1.25rem;margin-bottom:1.25rem;
            display:flex;align-items:center;justify-content:space-between;gap:1rem;flex-wrap:wrap;">
    <div style="display:flex;align-items:center;gap:0.75rem;">
        <div style="width:36px;height:36px;border-radius:8px;background:rgba(255,255,255,0.1);display:flex;align-items:center;justify-content:center;flex-shrink:0;">
            <i class="bi bi-calendar-range" style="color:rgba(255,255,255,0.85);font-size:1rem;"></i>
        </div>
        <div>
            <div style="font-weight:700;color:#fff;font-size:0.88rem;line-height:1.2;"><?= sanitize($cycle['cycle_name']) ?></div>
            <div style="font-size:0.7rem;margin-top:2px;">
                <?php if ($cycle['status'] === 'open'): ?>
                <span style="background:rgba(74,222,128,0.15);color:#4ade80;border:1px solid rgba(74,222,128,0.3);padding:1px 8px;border-radius:20px;font-size:0.62rem;font-weight:700;letter-spacing:0.04em;">● OPEN</span>
                <?php elseif ($cycle['status'] === 'closed'): ?>
                <span style="background:rgba(255,255,255,0.08);color:rgba(255,255,255,0.55);border:1px solid rgba(255,255,255,0.15);padding:1px 8px;border-radius:20px;font-size:0.62rem;font-weight:700;letter-spacing:0.04em;">CLOSED</span>
                <?php else: ?>
                <span style="background:rgba(255,255,255,0.08);color:rgba(255,255,255,0.4);padding:1px 8px;border-radius:20px;font-size:0.62rem;font-weight:700;">ARCHIVED</span>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <div style="display:flex;align-items:center;gap:1rem;">
        <div style="text-align:right;">
            <div style="font-size:0.65rem;color:rgba(255,255,255,0.45);text-transform:uppercase;letter-spacing:0.06em;">Awaiting review</div>
            <div style="font-size:1.4rem;font-weight:800;color:#fff;line-height:1;"><?= $pending ?></div>
        </div>
        <a href="?page=review_queue" style="padding:0.4rem 0.9rem;border-radius:7px;background:rgba(255,255,255,0.12);color:#fff;font-size:0.75rem;font-weight:600;text-decoration:none;border:1px solid rgba(255,255,255,0.2);white-space:nowrap;">View Queue <i class="bi bi-arrow-right ms-1"></i></a>
    </div>
</div>
<?php else: ?>
<div style="background:#f8fafc;border:1px solid #e2e8f0;border-left:3px solid #1a3a6b;border-radius:8px;padding:0.85rem 1.15rem;margin-bottom:1.25rem;display:flex;align-items:center;gap:0.75rem;">
    <i class="bi bi-calendar-x" style="color:#1a3a6b;font-size:1.1rem;"></i>
    <span style="font-size:0.84rem;font-weight:600;color:#334155;">No active cycle at the moment.</span>
</div>
<?php endif; ?>

<!-- ══════════════════════════════════════════════
     KPI STRIP
═══════════════════════════════════════════════ -->
<div style="display:grid;grid-template-columns:repeat(4,1fr);gap:0.75rem;margin-bottom:1.25rem;">
<?php
$kpi = [
    ['Awaiting Decision', $pending,        'bi-inbox-fill',      $pending > 0 ? '#1a3a6b' : '#94a3b8', '?page=review_queue'],
    ['Active Reviews',    $my_under_review,'bi-hourglass-split', $my_under_review > 0 ? '#1e4d8c' : '#94a3b8', '?page=review_queue&filter=under_review'],
    ['Needs Revision',    $needs_revision, 'bi-pencil-square',   $needs_revision > 0 ? '#475569' : '#94a3b8', '?page=review_queue&filter=needs_revision'],
    ['Total Completed',   $total_reviewed, 'bi-check2-all',      '#1e4d8c', '?page=review_queue'],
];
foreach ($kpi as [$label, $val, $icon, $accent, $link]):
?>
<a href="<?= $link ?>" style="text-decoration:none;">
    <div style="background:#fff;border:1px solid #e2e8f0;border-radius:10px;padding:1rem 1.1rem;
                transition:box-shadow 0.15s,transform 0.15s;"
         onmouseover="this.style.boxShadow='0 4px 16px rgba(0,0,0,0.08)';this.style.transform='translateY(-1px)'"
         onmouseout="this.style.boxShadow='';this.style.transform=''">
        <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:0.65rem;">
            <div style="width:34px;height:34px;border-radius:8px;background:<?= $accent ?>18;display:flex;align-items:center;justify-content:center;">
                <i class="bi <?= $icon ?>" style="color:<?= $accent ?>;font-size:0.9rem;"></i>
            </div>
            <i class="bi bi-arrow-up-right" style="color:#e2e8f0;font-size:0.85rem;"></i>
        </div>
        <?php
        $poll_keys = ['checker_pending','checker_active','checker_revision',''];
        static $checker_kpi_idx = 0;
        $pk = $poll_keys[$checker_kpi_idx] ?? '';
        $checker_kpi_idx++;
        ?>
        <div style="font-size:1.75rem;font-weight:800;color:<?= $accent ?>;line-height:1;letter-spacing:-1px;"
             <?= $pk ? 'data-poll-key="'.$pk.'"' : '' ?>><?= $val ?></div>
        <div style="font-size:0.68rem;font-weight:600;color:#94a3b8;margin-top:4px;text-transform:uppercase;letter-spacing:0.05em;"><?= $label ?></div>
    </div>
</a>
<?php endforeach; ?>
</div>

<!-- ══════════════════════════════════════════════
     MAIN CONTENT — sidebar + tabbed list
═══════════════════════════════════════════════ -->
<div style="display:grid;grid-template-columns:220px 1fr;gap:1rem;margin-bottom:1.25rem;">

    <!-- Sidebar: approval donut -->
    <div style="background:#fff;border:1px solid #e2e8f0;border-radius:10px;padding:1.1rem;">
        <div style="font-weight:700;color:#0f172a;font-size:0.82rem;margin-bottom:0.2rem;"><i class="bi bi-pie-chart-fill me-1" style="color:#1a3a6b;"></i>My Reviews</div>
        <div style="font-size:0.68rem;color:#94a3b8;margin-bottom:0.85rem;">Approval breakdown</div>
        <?php if ($total_reviewed > 0): ?>
        <div style="height:130px;"><canvas id="myReviewChart"></canvas></div>
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:0.5rem;margin-top:0.85rem;">
            <div style="background:#eff6ff;border-radius:8px;padding:0.55rem;text-align:center;">
                <div style="font-size:1.25rem;font-weight:800;color:#1a3a6b;line-height:1;"><?= $total_approved ?></div>
                <div style="font-size:0.63rem;color:#64748b;margin-top:2px;font-weight:600;text-transform:uppercase;letter-spacing:0.04em;">Approved</div>
            </div>
            <div style="background:#f8fafc;border-radius:8px;padding:0.55rem;text-align:center;">
                <div style="font-size:1.25rem;font-weight:800;color:#475569;line-height:1;"><?= max(0,$total_reviewed-$total_approved) ?></div>
                <div style="font-size:0.63rem;color:#64748b;margin-top:2px;font-weight:600;text-transform:uppercase;letter-spacing:0.04em;">Other</div>
            </div>
        </div>
        <?php else: ?>
        <div style="height:130px;display:flex;flex-direction:column;align-items:center;justify-content:center;color:#cbd5e1;text-align:center;">
            <i class="bi bi-clipboard2-x" style="font-size:1.75rem;margin-bottom:0.4rem;"></i>
            <div style="font-size:0.72rem;color:#94a3b8;">No completed reviews yet.</div>
        </div>
        <?php endif; ?>
    </div>

    <!-- Main panel: tabbed reviews -->
    <div style="background:#fff;border:1px solid #e2e8f0;border-radius:10px;overflow:hidden;">

        <!-- Tab bar -->
        <div style="display:flex;align-items:center;padding:0 1rem;border-bottom:1px solid #f1f5f9;background:#fafafa;">
            <?php
            $tabs = [
                ['active',   'Active',    'bi-hourglass-split', $my_under_review],
                ['revision', 'Revisions', 'bi-pencil-square',   $needs_revision],
                ['queue',    'Queue',     'bi-inbox',            $pending],
            ];
            foreach ($tabs as [$id, $label, $icon, $cnt]):
            ?>
            <button class="ck-tab <?= $id === 'active' ? 'ck-tab-active' : '' ?>" data-tab="<?= $id ?>"
                    style="display:flex;align-items:center;gap:0.4rem;padding:0.7rem 0.9rem;border:none;background:none;
                           font-size:0.78rem;font-weight:600;color:<?= $id === 'active' ? '#1a3a6b' : '#94a3b8' ?>;
                           border-bottom:2px solid <?= $id === 'active' ? '#1a3a6b' : 'transparent' ?>;
                           margin-bottom:-1px;cursor:pointer;white-space:nowrap;">
                <i class="bi <?= $icon ?>"></i><?= $label ?>
                <?php if ($cnt > 0): ?>
                <span style="background:<?= $id === 'active' ? '#1a3a6b' : '#e2e8f0' ?>;color:<?= $id === 'active' ? '#fff' : '#64748b' ?>;
                             font-size:0.6rem;font-weight:700;border-radius:20px;padding:1px 6px;"><?= $cnt ?></span>
                <?php endif; ?>
            </button>
            <?php endforeach; ?>
            <a href="?page=review_queue" style="margin-left:auto;font-size:0.72rem;font-weight:600;color:#1a3a6b;text-decoration:none;padding:0.3rem 0.5rem;display:flex;align-items:center;gap:0.25rem;">
                View All <i class="bi bi-arrow-right"></i>
            </a>
        </div>

        <!-- Tab: Active -->
        <div id="tab-active" class="ck-panel" style="padding:0;overflow-y:auto;max-height:290px;">
        <?php if ($inprogress): foreach ($inprogress as $ip):
            $wait = (int)$ip['days_waiting'];
            $approved = ($ip['my_decision'] ?? '') === 'approved';
        ?>
        <div style="display:flex;align-items:center;gap:0.85rem;padding:0.75rem 1rem;border-bottom:1px solid #f8fafc;">
            <div style="flex:1;min-width:0;">
                <div style="font-weight:600;font-size:0.83rem;color:#0f172a;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;"><?= htmlspecialchars(formatDisplayName($ip)) ?></div>
                <div style="font-size:0.7rem;color:#94a3b8;margin-top:1px;display:flex;align-items:center;gap:0.4rem;flex-wrap:wrap;">
                    <span><?= sanitize($ip['campus_name']) ?></span>
                    <span style="color:#e2e8f0;">·</span>
                    <span style="color:<?= $wait >= 5 ? '#dc2626' : ($wait >= 3 ? '#d97706' : '#64748b') ?>;font-weight:600;"><?= $wait ?>d waiting</span>
                    <span style="color:#e2e8f0;">·</span>
                    <span style="color:#1e4d8c;font-weight:700;"><?= number_format((float)$ip['weighted_score'], 2) ?> pts</span>
                </div>
            </div>
            <div style="display:flex;align-items:center;gap:0.5rem;flex-shrink:0;">
                <span style="font-size:0.62rem;font-weight:700;padding:2px 8px;border-radius:20px;
                             background:<?= $approved ? '#dbeafe' : '#f1f5f9' ?>;
                             color:<?= $approved ? '#1e4d8c' : '#475569' ?>;">
                    <?= $approved ? 'Approved' : 'Pending' ?>
                </span>
                <a href="?page=review_application&id=<?= $ip['application_id'] ?>"
                   style="padding:0.28rem 0.75rem;border-radius:6px;background:#1a3a6b;color:#fff;font-size:0.71rem;font-weight:600;text-decoration:none;">
                    <?= $approved ? 'View' : 'Review' ?>
                </a>
            </div>
        </div>
        <?php endforeach; else: ?>
        <div style="display:flex;flex-direction:column;align-items:center;justify-content:center;padding:3rem 1rem;color:#94a3b8;text-align:center;">
            <i class="bi bi-check2-circle" style="font-size:2rem;color:#dbeafe;margin-bottom:0.5rem;"></i>
            <div style="font-size:0.8rem;">No active reviews right now.</div>
        </div>
        <?php endif; ?>
        </div>

        <!-- Tab: Revisions -->
        <div id="tab-revision" class="ck-panel" style="display:none;padding:0;overflow-y:auto;max-height:290px;">
        <?php if ($revision_apps): foreach ($revision_apps as $rv):
            $wait = (int)$rv['days_waiting'];
        ?>
        <div style="display:flex;align-items:center;gap:0.85rem;padding:0.75rem 1rem;border-bottom:1px solid #f8fafc;">
            <div style="flex:1;min-width:0;">
                <div style="font-weight:600;font-size:0.83rem;color:#0f172a;"><?= htmlspecialchars(formatDisplayName($rv)) ?></div>
                <div style="font-size:0.7rem;color:#94a3b8;margin-top:1px;">
                    <?= sanitize($rv['campus_name']) ?> · <span style="color:<?= $wait >= 5 ? '#dc2626' : '#64748b' ?>;font-weight:600;"><?= $wait ?>d waiting</span>
                </div>
            </div>
            <a href="?page=review_application&id=<?= $rv['application_id'] ?>"
               style="padding:0.28rem 0.75rem;border-radius:6px;background:#f1f5f9;color:#334155;font-size:0.71rem;font-weight:600;text-decoration:none;border:1px solid #e2e8f0;">
                View
            </a>
        </div>
        <?php endforeach; else: ?>
        <div style="display:flex;flex-direction:column;align-items:center;justify-content:center;padding:3rem 1rem;color:#94a3b8;text-align:center;">
            <i class="bi bi-check-circle" style="font-size:2rem;color:#dbeafe;margin-bottom:0.5rem;"></i>
            <div style="font-size:0.8rem;">No pending revisions.</div>
        </div>
        <?php endif; ?>
        </div>

        <!-- Tab: Queue -->
        <div id="tab-queue" class="ck-panel" style="display:none;padding:0;overflow-y:auto;max-height:290px;">
        <?php if ($queue): foreach ($queue as $q):
            $wait = (int)$q['days_waiting'];
        ?>
        <div style="display:flex;align-items:center;gap:0.85rem;padding:0.75rem 1rem;border-bottom:1px solid #f8fafc;">
            <div style="flex:1;min-width:0;">
                <div style="font-weight:600;font-size:0.83rem;color:#0f172a;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;"><?= htmlspecialchars(formatDisplayName($q)) ?></div>
                <div style="font-size:0.7rem;color:#94a3b8;margin-top:1px;">
                    <?= sanitize($q['campus_name']) ?> · <span style="color:<?= $wait >= 5 ? '#dc2626' : '#64748b' ?>;font-weight:600;"><?= $wait ?>d waiting</span>
                </div>
            </div>
            <a href="?page=review_application&id=<?= $q['application_id'] ?>"
               style="padding:0.28rem 0.75rem;border-radius:6px;background:#1a3a6b;color:#fff;font-size:0.71rem;font-weight:600;text-decoration:none;flex-shrink:0;">
                Review
            </a>
        </div>
        <?php endforeach; else: ?>
        <div style="display:flex;flex-direction:column;align-items:center;justify-content:center;padding:3rem 1rem;color:#94a3b8;text-align:center;">
            <i class="bi bi-check2-all" style="font-size:2rem;color:#dbeafe;margin-bottom:0.5rem;"></i>
            <div style="font-size:0.8rem;">Queue is clear.</div>
        </div>
        <?php endif; ?>
        </div>
    </div>
</div>

<!-- ══════════════════════════════════════════════
     RECENTLY COMPLETED
═══════════════════════════════════════════════ -->
<?php if ($recent): ?>
<div style="background:#fff;border:1px solid #e2e8f0;border-radius:10px;overflow:hidden;">
    <div style="padding:0.85rem 1rem;border-bottom:1px solid #f1f5f9;display:flex;align-items:center;justify-content:space-between;">
        <span style="font-weight:700;color:#0f172a;font-size:0.82rem;display:flex;align-items:center;gap:0.4rem;">
            <i class="bi bi-clock-history" style="color:#1a3a6b;"></i>Recently Completed
        </span>
        <a href="?page=review_queue" style="font-size:0.71rem;font-weight:600;color:#1e4d8c;text-decoration:none;padding:0.28rem 0.75rem;border-radius:6px;border:1px solid #dbeafe;background:#eff6ff;">View All</a>
    </div>
    <div style="overflow-x:auto;">
    <table style="width:100%;border-collapse:collapse;font-size:0.8rem;">
        <thead>
            <tr style="background:#f8fafc;border-bottom:1px solid #e2e8f0;">
                <th style="padding:0.55rem 1rem;text-align:left;font-size:0.65rem;font-weight:700;text-transform:uppercase;letter-spacing:0.06em;color:#94a3b8;">Faculty</th>
                <th style="padding:0.55rem 0.75rem;text-align:left;font-size:0.65rem;font-weight:700;text-transform:uppercase;letter-spacing:0.06em;color:#94a3b8;">Campus</th>
                <th style="padding:0.55rem 0.75rem;text-align:left;font-size:0.65rem;font-weight:700;text-transform:uppercase;letter-spacing:0.06em;color:#94a3b8;">Outcome</th>
                <th style="padding:0.55rem 0.75rem;text-align:left;font-size:0.65rem;font-weight:700;text-transform:uppercase;letter-spacing:0.06em;color:#94a3b8;">Reviewed</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($recent as $r): ?>
        <tr style="border-bottom:1px solid #f8fafc;" onmouseover="this.style.background='#fafafa'" onmouseout="this.style.background=''">
            <td style="padding:0.65rem 1rem;font-weight:600;color:#0f172a;"><?= htmlspecialchars(formatDisplayName($r)) ?></td>
            <td style="padding:0.65rem 0.75rem;color:#64748b;"><?= sanitize($r['campus_name']) ?></td>
            <td style="padding:0.65rem 0.75rem;"><?= statusBadge($r['status']) ?></td>
            <td style="padding:0.65rem 0.75rem;color:#94a3b8;font-size:0.75rem;"><?= $r['reviewed_at'] ? date('M d, Y', strtotime($r['reviewed_at'])) : '—' ?></td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
</div>
<?php endif; ?>

<script>
// Tab switching
document.querySelectorAll('.ck-tab').forEach(function(btn) {
    btn.addEventListener('click', function() {
        document.querySelectorAll('.ck-tab').forEach(function(b) {
            b.style.color = '#94a3b8';
            b.style.borderBottomColor = 'transparent';
            b.classList.remove('ck-tab-active');
            // reset badge color
            const badge = b.querySelector('span');
            if (badge) { badge.style.background = '#e2e8f0'; badge.style.color = '#64748b'; }
        });
        document.querySelectorAll('.ck-panel').forEach(function(p) { p.style.display = 'none'; });
        btn.style.color = '#1a3a6b';
        btn.style.borderBottomColor = '#1a3a6b';
        btn.classList.add('ck-tab-active');
        const badge = btn.querySelector('span');
        if (badge) { badge.style.background = '#1a3a6b'; badge.style.color = '#fff'; }
        document.getElementById('tab-' + btn.dataset.tab).style.display = 'block';
    });
});
</script>

<?php if ($total_reviewed > 0): ?>
<script>
document.addEventListener('DOMContentLoaded', function () {
    new Chart(document.getElementById('myReviewChart'), {
        type: 'doughnut',
        data: {
            labels: ['Approved', 'Other'],
            datasets: [{
                data: [<?= $total_approved ?>, <?= max(0, $total_reviewed - $total_approved) ?>],
                backgroundColor: ['#1a3a6b', '#e2e8f0'],
                borderColor: '#fff',
                borderWidth: 3,
                hoverOffset: 4
            }]
        },
        options: {
            responsive: true, maintainAspectRatio: false, cutout: '74%',
            plugins: {
                legend: { display: false },
                tooltip: { backgroundColor: '#0f172a', cornerRadius: 8, padding: 8 }
            }
        },
        plugins: [{
            id: 'centerPct',
            beforeDraw(chart) {
                const { ctx, chartArea: { width, height, left, top } } = chart;
                const pct = <?= $total_reviewed > 0 ? round(($total_approved / $total_reviewed) * 100) : 0 ?>;
                ctx.save();
                ctx.textAlign = 'center'; ctx.textBaseline = 'middle';
                const cx = left + width / 2, cy = top + height / 2;
                ctx.font = 'bold 1.2rem sans-serif'; ctx.fillStyle = '#0f172a';
                ctx.fillText(pct + '%', cx, cy - 6);
                ctx.font = '0.6rem sans-serif'; ctx.fillStyle = '#94a3b8';
                ctx.fillText('approved', cx, cy + 9);
                ctx.restore();
            }
        }]
    });
});
</script>
<?php endif; ?>
