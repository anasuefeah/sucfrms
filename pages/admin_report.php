<?php
session_start();
header('Content-Type: text/html; charset=UTF-8');
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../config/db.php';

if (!isLoggedIn() || !isAdmin()) {
    http_response_code(403); die('Access denied.');
}

// â”€â”€ Filters from query string â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
$cycle_id = intval($_GET['cycle_id'] ?? 0);
$status   = trim($_GET['status'] ?? '');
$campus   = intval($_GET['campus_id'] ?? 0);

// â”€â”€ Load cycles for filter dropdown â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
$all_cycles  = $pdo->query("SELECT cycle_id, cycle_name, status FROM cycles ORDER BY created_at DESC")->fetchAll();
$all_campuses = $pdo->query("SELECT campus_id, campus_name FROM campuses WHERE is_active=1 ORDER BY campus_name")->fetchAll();

// â”€â”€ Active cycle fallback â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
if (!$cycle_id) {
    foreach ($all_cycles as $cy) {
        if ($cy['status'] === 'open') { $cycle_id = $cy['cycle_id']; break; }
    }
}
$selected_cycle = null;
foreach ($all_cycles as $cy) {
    if ($cy['cycle_id'] === $cycle_id) { $selected_cycle = $cy; break; }
}

// â”€â”€ Build query â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
$where  = "WHERE u.role = 'faculty'";
$params = [];
if ($cycle_id) { $where .= ' AND a.cycle_id = ?'; $params[] = $cycle_id; }
if ($status && in_array($status, ['submitted','under_review','approved','rejected','reclassified','admin_rejected'])) {
    $where .= ' AND a.status = ?'; $params[] = $status;
}
if ($campus) { $where .= ' AND u.campus_id = ?'; $params[] = $campus; }

$apps = $pdo->prepare("
    SELECT a.*, u.full_name, u.first_name, u.middle_name, u.last_name, u.employee_id, u.rank,
           COALESCE(camp.campus_name, '&mdash;') as campus_name,
           c.cycle_name,
           chk.checker_label as checker_name, chk.user_id as checker_uid
    FROM applications a
    JOIN users u ON a.user_id = u.user_id
    LEFT JOIN campuses camp ON u.campus_id = camp.campus_id
    LEFT JOIN cycles c ON a.cycle_id = c.cycle_id
    LEFT JOIN users chk ON a.checker_id = chk.user_id
    $where
    ORDER BY camp.campus_name, u.full_name
");
$apps->execute($params);
$apps = $apps->fetchAll();

// â”€â”€ Summary stats â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
$total_faculty = $pdo->query("SELECT COUNT(*) FROM users WHERE role = 'faculty' AND status='active'")->fetchColumn();
$status_counts = [];
foreach ($apps as $a) {
    $status_counts[$a['status']] = ($status_counts[$a['status']] ?? 0) + 1;
}

// â”€â”€ KRA averages for selected cycle â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
$kra_q = $cycle_id
    ? $pdo->prepare("SELECT ks.kra_category, AVG(ks.computed_points) as avg_pts FROM kra_submissions ks JOIN applications a ON ks.application_id=a.application_id WHERE a.cycle_id=? GROUP BY ks.kra_category")
    : $pdo->prepare("SELECT kra_category, AVG(computed_points) as avg_pts FROM kra_submissions GROUP BY kra_category");
$cycle_id ? $kra_q->execute([$cycle_id]) : $kra_q->execute();
$kra_avgs = ['Instruction'=>0,'Research'=>0,'Extension'=>0,'Professional Development'=>0];
foreach ($kra_q->fetchAll() as $k) $kra_avgs[$k['kra_category']] = round($k['avg_pts'], 2);

$generated_at = date('F d, Y h:i A');
$admin_name   = $_SESSION['full_name'] ?? 'Administrator';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>SUCFRMS &mdash; Admin Report</title>
</head>
<body>

<!-- â”€â”€ Screen controls â”€â”€ -->
<div class="controls screen-only">
    <h6><i>ðŸ“‹</i> &nbsp;SUCFRMS Admin Report</h6>
    <div class="btn-group">
        <a href="../index.php?page=dashboard" class="btn btn-outline">&larr; Back to Dashboard</a>
        <a href="oss_pdf.php?cycle_id=<?= $cycle_id ?>&status=<?= urlencode($status) ?>&campus_id=<?= $campus ?>" class="btn btn-light">&#128424; Print Report</a>
        <a href="detailed_report_pdf.php?cycle_id=<?= $cycle_id ?>&status=<?= urlencode($status) ?>&campus_id=<?= $campus ?>" class="btn btn-light">ðŸ“„ Detailed Report</a>
    </div>
</div>

<!-- â”€â”€ Filter bar â”€â”€ -->
<form method="GET" class="filter-bar screen-only">
    <label>Cycle:</label>
    <select name="cycle_id" onchange="this.form.submit()">
        <option value="0">All Cycles</option>
        <?php foreach ($all_cycles as $cy): ?>
        <option value="<?= $cy['cycle_id'] ?>" <?= $cy['cycle_id'] === $cycle_id ? 'selected' : '' ?>>
            <?= htmlspecialchars($cy['cycle_name']) ?> (<?= ucfirst($cy['status']) ?>)
        </option>
        <?php endforeach; ?>
    </select>
    <label>Status:</label>
    <select name="status" onchange="this.form.submit()">
        <option value="">All Statuses</option>
        <?php foreach (['submitted','under_review','approved','rejected','reclassified','admin_rejected'] as $s): ?>
        <option value="<?= $s ?>" <?= $status === $s ? 'selected' : '' ?>><?= ucwords(str_replace('_',' ',$s)) ?></option>
        <?php endforeach; ?>
    </select>
    <label>Campus:</label>
    <select name="campus_id" onchange="this.form.submit()">
        <option value="0">All Campuses</option>
        <?php foreach ($all_campuses as $cp): ?>
        <option value="<?= $cp['campus_id'] ?>" <?= $campus === $cp['campus_id'] ? 'selected' : '' ?>><?= htmlspecialchars($cp['campus_name']) ?></option>
        <?php endforeach; ?>
    </select>
</form>

<!-- â”€â”€ Report body â”€â”€ -->
<div class="report-body">

    <!-- Header -->
    <div class="report-card">
        <div class="report-header">
            <img src="../assets/images/logo.jpg" alt="Institution Logo">
            <div class="report-header-text">
                <h1>SUC Faculty Reclassification Management System</h1>
                <p>SUC &mdash; Admin Report &nbsp;&middot;&nbsp;
                   <?= $selected_cycle ? htmlspecialchars($selected_cycle['cycle_name']) : 'All Cycles' ?>
                   <?= $status ? ' &nbsp;&middot;&nbsp; ' . ucwords(str_replace('_',' ',$status)) : '' ?>
                   <?php if ($campus): foreach ($all_campuses as $cp) { if ($cp['campus_id'] === $campus) echo ' &nbsp;&middot;&nbsp; ' . htmlspecialchars($cp['campus_name']); } endif; ?>
                </p>
            </div>
            <div class="report-meta">
                <div>Generated: <?= $generated_at ?></div>
                <div>By: <?= htmlspecialchars($admin_name) ?></div>
            </div>
        </div>
    </div>

    <!-- Summary -->
    <div class="report-card">
        <div class="section-title">Summary Statistics</div>
        <div class="summary-grid">
            <div class="summary-card">
                <div class="val"><?= $total_faculty ?></div>
                <div class="lbl">Total Faculty</div>
            </div>
            <div class="summary-card">
                <div class="val"><?= count($apps) ?></div>
                <div class="lbl">Applications<?= $status ? ' ('.ucwords(str_replace('_',' ',$status)).')' : '' ?></div>
            </div>
            <div class="summary-card">
                <div class="val"><?= $status_counts['reclassified'] ?? 0 ?></div>
                <div class="lbl">Reclassified</div>
            </div>
            <div class="summary-card">
                <div class="val"><?= $status_counts['approved'] ?? 0 ?></div>
                <div class="lbl">Approved</div>
            </div>
            <div class="summary-card">
                <div class="val"><?= ($status_counts['submitted'] ?? 0) + ($status_counts['under_review'] ?? 0) ?></div>
                <div class="lbl">Pending Review</div>
            </div>
            <div class="summary-card">
                <div class="val"><?= $status_counts['rejected'] ?? 0 ?></div>
                <div class="lbl">Returned</div>
            </div>
        </div>

        <!-- Status breakdown pills -->
        <?php if (!$status && !empty($status_counts)): ?>
        <div class="status-row">
            <?php
            $pill_colors = [
                'draft'          => 'background:#f1f5f9;color:#475569',
                'submitted'      => 'background:#dbeafe;color:#1e4d8c',
                'under_review'   => 'background:#f1f5f9;color:#334155',
                'approved'       => 'background:#dbeafe;color:#1a3a6b',
                'rejected'       => 'background:#f8fafc;color:#1a3a6b',
                'reclassified'   => 'background:#e0f2fe;color:#0369a1',
                'edit_requested' => 'background:#f1f5f9;color:#334155',
            ];
            foreach ($status_counts as $st => $cnt):
            ?>
            <span class="status-pill" style="<?= $pill_colors[$st] ?? 'background:#f1f5f9;color:#475569' ?>">
                <?= ucwords(str_replace('_',' ',$st)) ?>: <?= $cnt ?>
            </span>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </div>

    <!-- KRA Averages -->
    <div class="report-card">
        <div class="section-title">Average KRA Scores <?= $selected_cycle ? '&mdash; ' . htmlspecialchars($selected_cycle['cycle_name']) : '' ?></div>
        <div class="kra-grid">
            <?php
            $kra_max    = ['Instruction'=>100,'Research'=>100,'Extension'=>100,'Professional Development'=>100];
            $kra_labels = ['Instruction'=>'KRA I &mdash; Instruction','Research'=>'KRA II &mdash; Research','Extension'=>'KRA III &mdash; Extension','Professional Development'=>'KRA IV &mdash; Prof. Dev.'];
            foreach ($kra_avgs as $cat => $avg):
                $max = $kra_max[$cat];
                $pct = $max > 0 ? min(100, ($avg / $max) * 100) : 0;
            ?>
            <div class="kra-card">
                <div class="kra-name"><?= $kra_labels[$cat] ?></div>
                <div class="kra-val"><?= $avg ?> <span>/ <?= $max ?> pts</span></div>
                <div class="kra-bar"><div class="kra-fill" style="width:<?= $pct ?>%;"></div></div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>

    <!-- Applications table -->
    <div class="report-card">
        <div class="section-title">Applications (<?= count($apps) ?>)</div>
        <?php if ($apps): ?>
        <div class="table-wrapper">
        <table>
            <thead>
                <tr>
                    <th>#</th>
                    <th>Faculty Name</th>
                    <th>Employee ID</th>
                    <th>Campus</th>
                    <th>Rank</th>
                    <th>Cycle</th>
                    <th>Weighted Score</th>
                    <th>Sub-rank</th>
                    <th>Status</th>
                    <th>Reviewed By</th>
                    <th>Submitted</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($apps as $i => $a):
                $ws  = (float)$a['weighted_score'];
                $sc  = $ws >= 71 ? 'score-high' : ($ws >= 41 ? 'score-mid' : 'score-low');
                $inc = (int)$a['sub_rank_increment'];
            ?>
            <tr>
                <td style="color:#94a3b8;"><?= $i + 1 ?></td>
                <td style="font-weight:600;"><?= htmlspecialchars(formatDisplayName($a)) ?></td>
                <td><?= htmlspecialchars($a['employee_id'] ?? '&mdash;') ?></td>
                <td><?= htmlspecialchars($a['campus_name']) ?></td>
                <td><?= htmlspecialchars($a['rank'] ?? '&mdash;') ?></td>
                <td><?= htmlspecialchars($a['cycle_name'] ?? '&mdash;') ?></td>
                <td class="<?= $sc ?>"><?= number_format($ws, 2) ?></td>
                <td style="text-align:center;">
                    <?= $inc > 0 ? "<span style='color:#1e4d8c;font-weight:700;'>+{$inc}</span>" : '<span style="color:#94a3b8;">&mdash;</span>' ?>
                </td>
                <td><span class="badge badge-<?= $a['status'] ?>"><?= ucwords(str_replace('_',' ',$a['status'])) ?></span></td>
                <td><?= htmlspecialchars(!empty($a['checker_uid']) ? checkerDisplayLabel(['checker_label'=>$a['checker_name'],'user_id'=>$a['checker_uid']]) : '&mdash;') ?></td>
                <td><?= $a['submitted_at'] ? date('M d, Y', strtotime($a['submitted_at'])) : '&mdash;' ?></td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
        <?php else: ?>
        <div class="empty-state">No applications found for the selected filters.</div>
        <?php endif; ?>

        <!-- Footer -->
        <div class="report-footer">
            <span>SUCFRMS &mdash; SUC Faculty Reclassification Management System</span>
            <span>Generated <?= $generated_at ?> by <?= htmlspecialchars($admin_name) ?></span>
        </div>
    </div>

</div>

<script>
// Auto-print if ?print=1 is in the URL
if (new URLSearchParams(window.location.search).get('print') === '1') {
    window.addEventListener('load', () => setTimeout(() => window.print(), 400));
}
</script>
</body>
</html>
