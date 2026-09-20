<?php
$cycle = getActiveCycle($pdo);

// Core stats
$total_faculty  = (int)$pdo->query("SELECT COUNT(*) FROM users WHERE role = 'faculty'")->fetchColumn();
$total_applied  = (int)$pdo->query("SELECT COUNT(DISTINCT user_id) FROM applications WHERE status != 'draft'")->fetchColumn();
$total_approved = (int)$pdo->query("SELECT COUNT(*) FROM applications WHERE status='approved'")->fetchColumn();
$pending_review = (int)$pdo->query("SELECT COUNT(*) FROM applications WHERE status='submitted'")->fetchColumn();
$under_review   = (int)$pdo->query("SELECT COUNT(*) FROM applications WHERE status='under_review'")->fetchColumn();
$needs_revision = (int)$pdo->query("SELECT COUNT(*) FROM applications WHERE status='needs_revision'")->fetchColumn();
$total_checkers = (int)$pdo->query("SELECT COUNT(*) FROM users WHERE role IN ('checker','talisay_checker') AND status='active'")->fetchColumn();

// Deadline info removed — cycle timing is controlled by Open/Closed status only.
$days_left = null; $deadline_str = ''; $deadline_urgent = false;

// KRA averages
$kra_avgs = $pdo->query("SELECT kra_category, AVG(computed_points) as avg_pts FROM kra_submissions GROUP BY kra_category")->fetchAll();
$kra_map  = ['Instruction'=>0,'Research'=>0,'Extension'=>0,'Professional Development'=>0];
foreach ($kra_avgs as $k) $kra_map[$k['kra_category']] = round($k['avg_pts'], 2);

// Status counts
$status_counts = $pdo->query("SELECT status, COUNT(*) as cnt FROM applications GROUP BY status")->fetchAll(PDO::FETCH_KEY_PAIR);

// Campus stats
$campus_stats = $pdo->query("
    SELECT c.campus_name,
           COUNT(DISTINCT u.user_id) AS faculty_count,
           COUNT(DISTINCT CASE WHEN a.status != 'draft' THEN a.application_id END) AS applied_count,
           COUNT(DISTINCT CASE WHEN a.status = 'approved' THEN a.application_id END) AS reclass_count,
           COUNT(DISTINCT CASE WHEN a.status IN ('submitted','under_review') THEN a.application_id END) AS pending_count
    FROM campuses c
    LEFT JOIN users u ON u.campus_id = c.campus_id AND u.role = 'faculty'
    LEFT JOIN applications a ON a.user_id = u.user_id
    WHERE c.is_active = 1
    GROUP BY c.campus_id, c.campus_name ORDER BY applied_count DESC
")->fetchAll();

// Recent activity
$recent_logs = $pdo->query("
    SELECT al.action_performed, al.details, al.timestamp, al.role_at_time, u.full_name
    FROM audit_logs al LEFT JOIN users u ON al.user_id = u.user_id
    ORDER BY al.timestamp DESC LIMIT 8
")->fetchAll();

// 7-day trend
$trend_rows = $pdo->query("
    SELECT DATE(submitted_at) AS day, COUNT(*) AS cnt
    FROM applications WHERE submitted_at >= DATE_SUB(CURDATE(), INTERVAL 6 DAY)
    GROUP BY DATE(submitted_at)
")->fetchAll(PDO::FETCH_KEY_PAIR);
$trend_labels = []; $trend_data = [];
for ($i = 6; $i >= 0; $i--) {
    $d = date('Y-m-d', strtotime("-{$i} days"));
    $trend_labels[] = date('M d', strtotime($d));
    $trend_data[]   = (int)($trend_rows[$d] ?? 0);
}

// Donut data
$donut_colors = [
    'submitted'      => '#1a3a6b',
    'under_review'   => '#2f6fb0',
    'talisay_review' => '#64748b',
    'approved'       => '#334155',
    'rejected'       => '#94a3b8',
    'needs_revision' => '#475569',
    'admin_rejected' => '#1e293b',
    'draft'          => '#e2e8f0',
    'reclassified'   => '#1e4d8c',
];
$pie_labels = []; $pie_data = []; $pie_colors_arr = [];
foreach ($status_counts as $st => $cnt) {
    if ($st === 'draft') continue;
    $pie_labels[] = ucwords(str_replace('_',' ',$st));
    $pie_data[]   = $cnt;
    $pie_colors_arr[] = $donut_colors[$st] ?? '#94a3b8';
}
?>

<!-- ══════════════════════════════════════════════
     HEADER — greeting + actions
═══════════════════════════════════════════════ -->
<div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:1rem;margin-bottom:1.5rem;">
    <div style="display:flex;align-items:center;gap:1rem;">
        <?php
        $pic    = $_SESSION['profile_pic'] ?? '';
        $init_h = strtoupper(
            substr($_SESSION['first_name'] ?? '', 0, 1) .
            substr($_SESSION['last_name']  ?? '', 0, 1)
        ) ?: 'A';
        ?>
        <?php if ($pic): ?>
        <img src="<?= sanitize($pic) ?>" alt="Profile" style="width:48px;height:48px;border-radius:12px;object-fit:cover;border:2px solid #e2e8f0;flex-shrink:0;">
        <?php else: ?>
        <div style="width:48px;height:48px;border-radius:12px;background:#1a3a6b;display:flex;align-items:center;justify-content:center;font-size:0.95rem;font-weight:700;color:#fff;flex-shrink:0;"><?= $init_h ?></div>
        <?php endif; ?>
        <div>
            <div style="font-size:1rem;font-weight:700;color:#0f172a;line-height:1.25;">Welcome back, <?= sanitize($_SESSION['full_name'] ?? 'Admin') ?></div>
            <div style="font-size:0.72rem;color:#94a3b8;margin-top:2px;display:flex;align-items:center;gap:0.5rem;">
                <i class="bi bi-calendar3" style="font-size:0.7rem;"></i><?= date('l, F j, Y') ?>
                &ensp;<span style="background:#fef3c7;color:#92400e;font-size:0.62rem;font-weight:700;letter-spacing:0.04em;padding:1px 7px;border-radius:4px;border:1px solid #fde68a;">ADMIN</span>
            </div>
        </div>
    </div>
    <div style="display:flex;gap:0.6rem;flex-wrap:wrap;">
        <a href="pages/oss_print.php<?= $cycle ? '?cycle_id='.$cycle['cycle_id'] : '' ?>" target="_blank"
           style="display:inline-flex;align-items:center;gap:0.4rem;padding:0.45rem 1rem;border-radius:8px;border:1px solid #e2e8f0;background:#fff;color:#475569;font-size:0.78rem;font-weight:600;text-decoration:none;">
            <i class="bi bi-file-earmark-spreadsheet"></i>Print Report
        </a>

    </div>
</div>

<!-- ══════════════════════════════════════════════
     CYCLE BANNER
═══════════════════════════════════════════════ -->
<?php if ($cycle): ?>
<div style="background:#1a3a6b;border-radius:10px;padding:0.9rem 1.25rem;margin-bottom:1.25rem;
            display:flex;align-items:center;justify-content:space-between;gap:0.75rem;flex-wrap:wrap;
            min-width:0;">
    <div style="display:flex;align-items:center;gap:0.75rem;">
        <div style="width:36px;height:36px;border-radius:8px;background:rgba(255,255,255,0.1);display:flex;align-items:center;justify-content:center;flex-shrink:0;">
            <i class="bi bi-calendar-range" style="color:rgba(255,255,255,0.85);font-size:1rem;"></i>
        </div>
        <div>
            <div style="font-weight:700;color:#fff;font-size:0.88rem;line-height:1.2;"><?= sanitize($cycle['cycle_name']) ?></div>
            <div style="font-size:0.7rem;margin-top:3px;">
                <?php if ($cycle['status'] === 'open'): ?>
                <span style="background:rgba(74,222,128,0.15);color:#4ade80;border:1px solid rgba(74,222,128,0.3);padding:1px 8px;border-radius:20px;font-size:0.62rem;font-weight:700;letter-spacing:0.04em;">● OPEN</span>
                <?php elseif ($cycle['status'] === 'closed'): ?>
                <span style="background:rgba(255,255,255,0.08);color:rgba(255,255,255,0.55);border:1px solid rgba(255,255,255,0.15);padding:1px 8px;border-radius:20px;font-size:0.62rem;font-weight:700;">CLOSED</span>
                <?php else: ?>
                <span style="background:rgba(255,255,255,0.06);color:rgba(255,255,255,0.4);padding:1px 8px;border-radius:20px;font-size:0.62rem;font-weight:700;">ARCHIVED</span>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <div style="display:flex;align-items:center;gap:1rem;">
        <div style="text-align:right;">
            <div style="font-size:0.65rem;color:rgba(255,255,255,0.45);text-transform:uppercase;letter-spacing:0.06em;">Pending review</div>
            <div style="font-size:1.4rem;font-weight:800;color:#fff;line-height:1;"><?= $pending_review + $under_review ?></div>
        </div>
        <a href="?page=cycles" style="padding:0.4rem 0.9rem;border-radius:7px;background:rgba(255,255,255,0.12);color:#fff;font-size:0.75rem;font-weight:600;text-decoration:none;border:1px solid rgba(255,255,255,0.2);white-space:nowrap;">
            <i class="bi bi-gear me-1"></i>Manage
        </a>
    </div>
</div>
<?php else: ?>
<div style="background:#f8fafc;border:1px solid #e2e8f0;border-left:3px solid #1a3a6b;border-radius:8px;padding:0.85rem 1.15rem;margin-bottom:1.25rem;display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:0.75rem;">
    <div style="display:flex;align-items:center;gap:0.75rem;">
        <i class="bi bi-calendar-x" style="font-size:1.1rem;color:#1a3a6b;"></i>
        <div>
            <div style="font-weight:700;color:#1a3a6b;font-size:0.84rem;">No Active Reclassification Cycle</div>
            <div style="font-size:0.72rem;color:#64748b;">Faculty cannot submit until a cycle is open.</div>
        </div>
    </div>
    <a href="?page=cycles" style="padding:0.38rem 0.9rem;border-radius:7px;background:#1a3a6b;color:#fff;font-size:0.75rem;font-weight:600;text-decoration:none;">Create Cycle</a>
</div>
<?php endif; ?>

<!-- ══════════════════════════════════════════════
     KPI STAT CARDS
═══════════════════════════════════════════════ -->
<?php
$kpi = [
    ['Total Faculty',    $total_faculty,  '?page=manage_users'],
    ['Submitted',        $total_applied,  '?page=all_applications'],
    ['Awaiting Checker', $pending_review, '?page=all_applications&filter=submitted'],
    ['Under Review',     $under_review,   '?page=all_applications&filter=under_review'],
    ['Needs Revision',   $needs_revision, '?page=all_applications&filter=needs_revision'],
    ['Approved',         $total_approved, '?page=all_applications&filter=approved'],
];
?>
<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(145px,1fr));gap:0.75rem;margin-bottom:1.5rem;">
<?php foreach ($kpi as [$label,$val,$link]): ?>
    <a href="<?= $link ?>" style="text-decoration:none;">
        <div style="background:#fff;border:1px solid #e2e8f0;border-radius:10px;padding:1rem 1.1rem;
                    transition:border-color .15s,box-shadow .15s;"
             onmouseover="this.style.borderColor='#1a3a6b';this.style.boxShadow='0 2px 10px rgba(26,58,107,.08)'"
             onmouseout="this.style.borderColor='#e2e8f0';this.style.boxShadow=''">
            <?php
            $admin_poll_keys = ['admin_faculty','admin_applied','admin_pending','admin_review','admin_revision','admin_approved'];
            static $admin_kpi_idx = 0;
            $apk = $admin_poll_keys[$admin_kpi_idx] ?? '';
            $admin_kpi_idx++;
            ?>
            <div style="font-size:1.9rem;font-weight:800;color:#1a3a6b;line-height:1;letter-spacing:-1px;"
                 data-poll-key="<?= $apk ?>">
                <?= $val ?>
            </div>
            <div style="font-size:0.7rem;font-weight:600;color:#64748b;margin-top:5px;
                        text-transform:uppercase;letter-spacing:0.05em;">
                <?= $label ?>
            </div>
        </div>
    </a>
<?php endforeach; ?>
</div>

<!-- ══════════════════════════════════════════════
     CHARTS ROW — trend + donut + KRA
═══════════════════════════════════════════════ -->
<div style="display:grid;grid-template-columns:1fr 300px 300px;gap:1rem;margin-bottom:1.5rem;">

    <!-- Submission trend -->
    <div class="neon-card" style="padding:1.1rem 1.25rem;">
        <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:1rem;">
            <div>
                <div style="font-weight:700;color:#1a3a6b;font-size:0.88rem;">
                    Submissions — Last 7 Days
                </div>
                <div style="font-size:0.7rem;color:#94a3b8;margin-top:1px;">Daily application submission activity</div>
            </div>
            <?php $total_week = array_sum($trend_data); ?>
            <div style="text-align:right;">
                <div style="font-size:1.3rem;font-weight:800;color:#1e4d8c;line-height:1;"><?= $total_week ?></div>
                <div style="font-size:0.65rem;color:#94a3b8;">this week</div>
            </div>
        </div>
        <div style="height:190px;"><canvas id="trendLineChart"></canvas></div>
    </div>

    <!-- Status donut -->
    <div class="neon-card" style="padding:1.1rem 1.25rem;">
        <div style="font-weight:700;color:#1a3a6b;font-size:0.88rem;margin-bottom:0.25rem;">
            Pipeline
        </div>
        <div style="font-size:0.7rem;color:#94a3b8;margin-bottom:0.75rem;">Application status split</div>
        <?php if (!empty($pie_data) && array_sum($pie_data) > 0): ?>
        <div style="height:140px;"><canvas id="statusPieChart"></canvas></div>
        <div style="margin-top:0.65rem;display:flex;flex-direction:column;gap:3px;">
            <?php foreach ($pie_labels as $i => $pl): ?>
            <div style="display:flex;align-items:center;justify-content:space-between;font-size:0.7rem;">
                <div style="display:flex;align-items:center;gap:4px;color:#475569;">
                    <span style="width:8px;height:8px;border-radius:2px;background:<?= $pie_colors_arr[$i] ?>;flex-shrink:0;"></span>
                    <?= $pl ?>
                </div>
                <strong style="color:#1a3a6b;"><?= $pie_data[$i] ?></strong>
            </div>
            <?php endforeach; ?>
        </div>
        <?php else: ?>
        <div style="height:140px;display:flex;align-items:center;justify-content:center;
                    color:#94a3b8;font-size:0.8rem;text-align:center;">
            <div><i class="bi bi-pie-chart d-block mb-1" style="font-size:1.5rem;"></i>No data yet</div>
        </div>
        <?php endif; ?>
    </div>

    <!-- KRA avg bars -->
    <div class="neon-card" style="padding:1.1rem 1.25rem;">
        <div style="font-weight:700;color:#1a3a6b;font-size:0.88rem;margin-bottom:0.25rem;">
            KRA Averages
        </div>
        <div style="font-size:0.7rem;color:#94a3b8;margin-bottom:0.9rem;">Avg pts per KRA category</div>
        <?php
        $kra_display = [
            'Instruction'              => ['KRA I',   '#1a3a6b', 100],
            'Research'                 => ['KRA II',  '#1a3a6b', 100],
            'Extension'                => ['KRA III', '#1a3a6b', 100],
            'Professional Development' => ['KRA IV',  '#1a3a6b', 100],
        ];
        foreach ($kra_map as $kra => $pts):
            [$short, $col, $max] = $kra_display[$kra] ?? [$kra, '#94a3b8', 100];
            $pct = $max > 0 ? min(100, round(($pts / $max) * 100)) : 0;
        ?>
        <div style="margin-bottom:0.7rem;">
            <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:3px;">
                <div style="font-size:0.72rem;font-weight:700;color:#1a3a6b;"><?= $short ?></div>
                <div style="font-size:0.72rem;font-weight:700;color:#1a3a6b;"><?= $pts ?></div>
            </div>
            <div style="height:8px;background:#f1f5f9;border-radius:99px;overflow:hidden;">
                <div style="height:100%;width:<?= $pct ?>%;background:<?= $col ?>;border-radius:99px;"></div>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
</div>

<!-- ══════════════════════════════════════════════
     CAMPUS TABLE + RECENT ACTIVITY
═══════════════════════════════════════════════ -->
<div style="display:grid;grid-template-columns:1fr 360px;gap:1rem;margin-bottom:1.5rem;">

    <!-- Campus breakdown -->
    <div class="neon-card" style="padding:0;overflow:hidden;">
        <div style="padding:0.9rem 1.25rem;border-bottom:1px solid #f1f5f9;
                    display:flex;align-items:center;justify-content:space-between;">
            <div>
                <div style="font-weight:700;color:#1a3a6b;font-size:0.88rem;">
                    <i class="bi bi-geo-alt-fill me-2" style="color:#1e4d8c;"></i>Campus Breakdown
                </div>
                <div style="font-size:0.7rem;color:#94a3b8;margin-top:1px;">
                    Faculty applications by campus
                </div>
            </div>
            <a href="?page=manage_campuses"
               style="font-size:0.72rem;font-weight:600;color:#1e4d8c;text-decoration:none;
                      padding:0.3rem 0.8rem;border-radius:6px;border:1px solid #dbeafe;background:#eff6ff;">
                Manage
            </a>
        </div>
        <?php if ($campus_stats): ?>
        <table style="width:100%;border-collapse:collapse;font-size:0.81rem;">
            <thead>
                <tr style="background:#f8fafc;border-bottom:2px solid #e2e8f0;">
                    <th style="padding:0.6rem 1rem;text-align:left;font-size:0.68rem;font-weight:700;
                               text-transform:uppercase;letter-spacing:0.05em;color:#64748b;">Campus</th>
                    <th style="padding:0.6rem 0.6rem;text-align:center;font-size:0.68rem;font-weight:700;
                               text-transform:uppercase;letter-spacing:0.05em;color:#64748b;">Faculty</th>
                    <th style="padding:0.6rem 0.6rem;text-align:center;font-size:0.68rem;font-weight:700;
                               text-transform:uppercase;letter-spacing:0.05em;color:#64748b;">Applied</th>
                    <th style="padding:0.6rem 0.6rem;text-align:center;font-size:0.68rem;font-weight:700;
                               text-transform:uppercase;letter-spacing:0.05em;color:#64748b;">Pending</th>
                    <th style="padding:0.6rem 0.6rem;text-align:center;font-size:0.68rem;font-weight:700;
                               text-transform:uppercase;letter-spacing:0.05em;color:#64748b;">Approved</th>
                    <th style="padding:0.6rem 0.75rem;text-align:left;font-size:0.68rem;font-weight:700;
                               text-transform:uppercase;letter-spacing:0.05em;color:#64748b;min-width:90px;">Progress</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($campus_stats as $cs):
                $prog = $cs['faculty_count'] > 0
                    ? min(100, round(($cs['applied_count'] / $cs['faculty_count']) * 100)) : 0;
                $prog_col = '#1e4d8c';
            ?>
            <tr style="border-bottom:1px solid #f0f4fb;"
                onmouseover="this.style.background='#f8fafc'" onmouseout="this.style.background=''">
                <td style="padding:0.75rem 1rem;font-weight:600;color:#1e293b;font-size:0.82rem;">
                    <?= sanitize($cs['campus_name']) ?>
                </td>
                <td style="padding:0.75rem 0.6rem;text-align:center;color:#64748b;font-size:0.8rem;">
                    <?= $cs['faculty_count'] ?>
                </td>
                <td style="padding:0.75rem 0.6rem;text-align:center;">
                    <span style="background:#eff6ff;color:#1e4d8c;padding:0.15rem 0.55rem;
                                 border-radius:20px;font-weight:700;font-size:0.75rem;">
                        <?= $cs['applied_count'] ?>
                    </span>
                </td>
                <td style="padding:0.75rem 0.6rem;text-align:center;">
                    <?php if ($cs['pending_count'] > 0): ?>
                    <span style="background:#f0f4fb;color:#1e4d8c;padding:0.15rem 0.55rem;
                                 border-radius:20px;font-weight:700;font-size:0.75rem;">
                        <?= $cs['pending_count'] ?>
                    </span>
                    <?php else: ?><span style="color:#cbd5e1;font-size:0.75rem;">—</span><?php endif; ?>
                </td>
                <td style="padding:0.75rem 0.6rem;text-align:center;">
                    <?php if ($cs['reclass_count'] > 0): ?>
                    <span style="background:#eff6ff;color:#1e4d8c;padding:0.15rem 0.55rem;
                                 border-radius:20px;font-weight:700;font-size:0.75rem;">
                        <?= $cs['reclass_count'] ?>
                    </span>
                    <?php else: ?><span style="color:#cbd5e1;font-size:0.75rem;">—</span><?php endif; ?>
                </td>
                <td style="padding:0.75rem 0.75rem;">
                    <div style="display:flex;align-items:center;gap:0.5rem;">
                        <div style="flex:1;height:7px;background:#f1f5f9;border-radius:99px;overflow:hidden;">
                            <div style="height:100%;width:<?= $prog ?>%;background:<?= $prog_col ?>;border-radius:99px;"></div>
                        </div>
                        <span style="font-size:0.68rem;font-weight:700;color:<?= $prog_col ?>;min-width:30px;"><?= $prog ?>%</span>
                    </div>
                </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php else: ?>
        <div style="padding:2.5rem;text-align:center;color:#94a3b8;font-size:0.82rem;">No campus data available.</div>
        <?php endif; ?>
    </div>

    <!-- Recent activity -->
    <div class="neon-card" style="padding:0;overflow:hidden;">
        <div style="padding:0.9rem 1.25rem;border-bottom:1px solid #f1f5f9;
                    display:flex;align-items:center;justify-content:space-between;">
            <div>
                <div style="font-weight:700;color:#1a3a6b;font-size:0.88rem;">
                    <i class="bi bi-activity me-2" style="color:#1e4d8c;"></i>Recent Activity
                </div>
                <div style="font-size:0.7rem;color:#94a3b8;margin-top:1px;">Latest system events</div>
            </div>
            <a href="?page=audit"
               style="font-size:0.72rem;font-weight:600;color:#1e4d8c;text-decoration:none;
                      padding:0.3rem 0.8rem;border-radius:6px;border:1px solid #dbeafe;background:#eff6ff;">
                View All
            </a>
        </div>
        <?php if ($recent_logs): ?>
        <div style="padding:0.5rem 0;">
            <?php
            $action_icons = [
                'Login'=>'bi-box-arrow-in-right','Submitted'=>'bi-send',
                'Approved'=>'bi-check-circle-fill','Rejected'=>'bi-x-circle-fill',
                'Verified'=>'bi-patch-check-fill','Password'=>'bi-key-fill',
                'Role'=>'bi-person-gear','Score'=>'bi-pencil-square',
                'Revision'=>'bi-flag-fill','Resent'=>'bi-envelope',
            ];
            $role_badge = [
                'admin'   => ['#1e4d8c','#eff6ff'],
                'checker' => ['#475569','#f1f5f9'],
                'faculty' => ['#475569','#f1f5f9'],
            ];
            foreach ($recent_logs as $log):
                $icon = 'bi-clock-history';
                foreach ($action_icons as $key => $ic) {
                    if (stripos($log['action_performed'], $key) !== false) { $icon = $ic; break; }
                }
                $diff = time() - strtotime($log['timestamp']);
                $ago  = $diff < 60 ? 'just now'
                      : ($diff < 3600 ? floor($diff/60).'m ago'
                      : ($diff < 86400 ? floor($diff/3600).'h ago'
                      : date('M d', strtotime($log['timestamp']))));
                [$rc,$rb] = $role_badge[$log['role_at_time'] ?? ''] ?? ['#94a3b8','#f1f5f9'];
            ?>
            <div style="display:flex;align-items:flex-start;gap:0.7rem;
                        padding:0.6rem 1.1rem;border-bottom:1px solid #f8fafc;
                        transition:background 0.15s;"
                 onmouseover="this.style.background='#f8fafc'" onmouseout="this.style.background=''">
                <div style="width:32px;height:32px;border-radius:50%;background:#f0f4fb;
                            display:flex;align-items:center;justify-content:center;flex-shrink:0;margin-top:1px;">
                    <i class="bi <?= $icon ?>" style="color:#1e4d8c;font-size:0.78rem;"></i>
                </div>
                <div style="flex:1;min-width:0;">
                    <div style="display:flex;align-items:center;justify-content:space-between;gap:0.4rem;margin-bottom:2px;">
                        <span style="font-size:0.78rem;font-weight:600;color:#1e293b;
                                     white-space:nowrap;overflow:hidden;text-overflow:ellipsis;max-width:160px;">
                            <?= sanitize($log['full_name'] ?? 'System') ?>
                        </span>
                        <span style="font-size:0.65rem;color:#94a3b8;flex-shrink:0;"><?= $ago ?></span>
                    </div>
                    <div style="display:flex;align-items:center;gap:0.35rem;flex-wrap:wrap;">
                        <span style="background:<?= $rb ?>;color:<?= $rc ?>;font-size:0.6rem;font-weight:700;
                                     border-radius:4px;padding:1px 5px;border:1px solid <?= $rc ?>33;flex-shrink:0;">
                            <?= sanitize($log['role_at_time'] ?? '—') ?>
                        </span>
                        <span style="font-size:0.72rem;color:#64748b;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">
                            <?= sanitize($log['action_performed']) ?>
                        </span>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        <?php else: ?>
        <div style="padding:2.5rem;text-align:center;color:#94a3b8;font-size:0.82rem;">No activity yet.</div>
        <?php endif; ?>
    </div>
</div>

<!-- ══════════════════════════════════════════════
     QUICK ACTIONS
═══════════════════════════════════════════════ -->
<div class="neon-card" style="padding:1rem 1.25rem;">
    <div style="font-weight:700;color:#1a3a6b;font-size:0.85rem;margin-bottom:0.9rem;">Quick Actions</div>
    <div style="display:flex;flex-wrap:wrap;gap:0.5rem;">
        <?php
        $actions = [
            ['All Applications',  '?page=all_applications'],
            ['Manage Users',      '?page=manage_users'],
            ['Cycles & Criteria', '?page=cycles'],
            ['Analytics',         '?page=analytics'],
            ['Audit Logs',        '?page=audit'],
            ['Manage Campuses',   '?page=manage_campuses'],
        ];
        foreach ($actions as [$lbl,$href]): ?>
        <a href="<?= $href ?>"
           style="display:inline-flex;align-items:center;padding:0.4rem 0.85rem;border-radius:8px;
                  background:#fff;color:#1a3a6b;font-size:0.75rem;font-weight:600;
                  text-decoration:none;border:1px solid #e2e8f0;transition:border-color .15s;"
           onmouseover="this.style.borderColor='#1a3a6b'"
           onmouseout="this.style.borderColor='#e2e8f0'">
            <?= $lbl ?>
        </a>
        <?php endforeach; ?>
    </div>
</div>

<!-- ══════════════════════════════════════════════
     CHARTS JS
═══════════════════════════════════════════════ -->
<script>
document.addEventListener('DOMContentLoaded', function () {
    const tick = '#64748b';
    const grid = '#f1f5f9';
    const tooltipBase = {
        backgroundColor:'#1e293b',titleColor:'#f1f5f9',
        bodyColor:'#cbd5e1',cornerRadius:8,padding:10
    };

    // Trend line
    new Chart(document.getElementById('trendLineChart'), {
        type: 'line',
        data: {
            labels: <?= json_encode($trend_labels) ?>,
            datasets: [{
                data: <?= json_encode($trend_data) ?>,
                borderColor: '#1e4d8c',
                backgroundColor: 'rgba(30,77,140,0.07)',
                borderWidth: 2.5,
                pointBackgroundColor: '#1e4d8c',
                pointBorderColor: '#fff',
                pointBorderWidth: 2,
                pointRadius: 5,
                tension: 0.4,
                fill: true
            }]
        },
        options: {
            responsive: true, maintainAspectRatio: false,
            scales: {
                y: { beginAtZero: true, ticks: { stepSize: 1, color: tick, font:{size:11} }, grid: { color: grid, drawBorder: false } },
                x: { grid: { display: false }, ticks: { color: tick, font:{size:11} } }
            },
            plugins: { legend: { display: false }, tooltip: tooltipBase }
        }
    });

    // Status donut
    <?php if (!empty($pie_data) && array_sum($pie_data) > 0): ?>
    new Chart(document.getElementById('statusPieChart'), {
        type: 'doughnut',
        data: {
            labels: <?= json_encode($pie_labels) ?>,
            datasets: [{
                data: <?= json_encode($pie_data) ?>,
                backgroundColor: <?= json_encode($pie_colors_arr) ?>,
                borderColor: '#fff',
                borderWidth: 3,
                hoverOffset: 5
            }]
        },
        options: {
            responsive: true, maintainAspectRatio: false,
            cutout: '70%',
            plugins: { legend: { display: false }, tooltip: tooltipBase }
        }
    });
    <?php endif; ?>
});
</script>

<style>
/* ── Admin dashboard mobile responsive ── */
@media (max-width: 767.98px) {
    /* Charts row: stack all 3 columns vertically */
    div[style*="grid-template-columns:1fr 300px 300px"] {
        display: flex !important;
        flex-direction: column !important;
    }
    /* Campus + activity row: stack vertically */
    div[style*="grid-template-columns:1fr 360px"] {
        display: flex !important;
        flex-direction: column !important;
    }
    /* KPI cards: 2 columns on mobile */
    div[style*="grid-template-columns:repeat(auto-fit,minmax(145px,1fr))"] {
        grid-template-columns: repeat(2, 1fr) !important;
    }
    /* Header actions: stack on mobile */
    div[style*="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:1rem;margin-bottom:1.5rem"] {
        flex-direction: column;
        align-items: flex-start !important;
    }
    /* Cycle banner: stack content on mobile */
    div[style*="background:#1a3a6b;border-radius:10px"] > div[style*="display:flex;align-items:center;gap:1rem"] {
        flex-wrap: wrap;
    }
    /* Charts: reduce height on mobile */
    #trendLineChart { height: 150px !important; }
    /* Table: horizontal scroll */
    .neon-card table { min-width: 480px; }
    .neon-card .table-responsive,
    .neon-card > div > div[style*="overflow:hidden"] {
        overflow-x: auto;
    }
    /* Campus table wrapper */
    div[style*="padding:0;overflow:hidden"] {
        overflow-x: auto !important;
    }
}
</style>
