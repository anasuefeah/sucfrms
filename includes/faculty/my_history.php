<?php
if (!isFaculty()) { echo '<div class="alert alert-danger">Access denied.</div>'; return; }

$uid = (int)$_SESSION['user_id'];

// Fetch all past applications with their cycle info, ordered newest first
$stmt = $pdo->prepare("
    SELECT
        a.application_id,
        a.cycle_id,
        a.status,
        a.weighted_score,
        a.total_score,
        a.sub_rank_increment,
        a.potential_rank,
        a.submitted_at,
        a.reviewed_at,
        a.position_title,
        c.cycle_name,
        c.start_date,
        c.end_date,
        c.status AS cycle_status
    FROM applications a
    LEFT JOIN cycles c ON c.cycle_id = a.cycle_id
    WHERE a.user_id = ?
    ORDER BY a.created_at DESC
");
$stmt->execute([$uid]);
$history = $stmt->fetchAll();

// Per-application KRA breakdown
$kraStmt = $pdo->prepare("
    SELECT kra_category, SUM(computed_points) AS pts, COUNT(*) AS entries, SUM(verified) AS verified_count
    FROM kra_submissions
    WHERE application_id = ?
    GROUP BY kra_category
");

$kraLabels = ['Instruction'=>'KRA I','Research'=>'KRA II','Extension'=>'KRA III','Professional Development'=>'KRA IV'];
?>

<!-- Page header -->
<div style="background:#fff;border:1px solid #e5e7eb;padding:0 1.1rem;margin-bottom:1rem;
            display:flex;align-items:center;justify-content:space-between;min-height:58px;">
    <div style="display:flex;align-items:center;gap:0.75rem;">
        <div style="width:36px;height:36px;background:#1a3a6b;display:flex;align-items:center;justify-content:center;flex-shrink:0;">
            <i class="bi bi-clock-history" style="color:#fff;font-size:0.9rem;"></i>
        </div>
        <div>
            <div style="font-weight:700;color:#1a3a6b;font-size:0.95rem;line-height:1.2;">Application History</div>
            <div style="font-size:0.72rem;color:#9ca3af;margin-top:1px;">Your reclassification record across all cycles</div>
        </div>
    </div>
    <div style="font-size:0.68rem;font-weight:700;color:#374151;border:1px solid #e5e7eb;padding:0.2rem 0.65rem;">
        <?= count($history) ?> RECORD<?= count($history) !== 1 ? 'S' : '' ?>
    </div>
</div>

<?php if (empty($history)): ?>
<div style="background:#fff;border:1px solid #e5e7eb;padding:3rem;text-align:center;">
    <i class="bi bi-folder2-open" style="font-size:2rem;color:#e5e7eb;display:block;margin-bottom:0.75rem;"></i>
    <div style="font-weight:600;color:#374151;margin-bottom:0.3rem;">No application history</div>
    <div style="font-size:0.82rem;color:#9ca3af;">Your reclassification applications will appear here once a cycle has been completed.</div>
</div>
<?php else: ?>

<div style="display:flex;flex-direction:column;gap:0.75rem;">
<?php foreach ($history as $app):
    $kraStmt->execute([$app['application_id']]);
    $kras = [];
    foreach ($kraStmt->fetchAll() as $k) $kras[$k['kra_category']] = $k;

    // Status display config
    $statusCfg = match($app['status']) {
        'approved','reclassified'  => ['bg'=>'#f0fdf4','color'=>'#15803d','border'=>'#bbf7d0','label'=>'Approved'],
        'submitted'                => ['bg'=>'#eff6ff','color'=>'#1e4d8c','border'=>'#bfdbfe','label'=>'Submitted'],
        'under_review'             => ['bg'=>'#eff6ff','color'=>'#1e4d8c','border'=>'#bfdbfe','label'=>'Under Evaluation'],
        'needs_revision'           => ['bg'=>'#fff7ed','color'=>'#c2410c','border'=>'#fed7aa','label'=>'Needs Revision'],
        'rejected','admin_rejected'=> ['bg'=>'#fef2f2','color'=>'#dc2626','border'=>'#fecaca','label'=>'Returned'],
        'expired'                  => ['bg'=>'#f9fafb','color'=>'#9ca3af','border'=>'#e5e7eb','label'=>'Expired'],
        default                    => ['bg'=>'#f9fafb','color'=>'#6b7280','border'=>'#e5e7eb','label'=>ucfirst(str_replace('_',' ',$app['status']))],
    };

    $isActive   = $app['cycle_status'] === 'open';
    $submitted  = !in_array($app['status'], ['draft','expired']);
    $meetsMin   = (float)$app['weighted_score'] >= 41;
?>
<div style="border:1px solid #e5e7eb;overflow:hidden;background:#fff;">
    <!-- Cycle header row -->
    <div style="background:#f9fafb;border-bottom:1px solid #e5e7eb;padding:0.5rem 0.9rem;
                display:flex;align-items:center;justify-content:space-between;gap:1rem;flex-wrap:wrap;">
        <div style="display:flex;align-items:center;gap:0.6rem;">
            <i class="bi bi-calendar3" style="color:#6b7280;font-size:0.78rem;"></i>
            <span style="font-size:0.78rem;font-weight:700;color:#374151;">
                <?= htmlspecialchars($app['cycle_name'] ?? 'Unknown Cycle') ?>
            </span>
            <?php if ($isActive): ?>
            <span style="font-size:0.6rem;font-weight:700;padding:1px 6px;background:#1a3a6b;color:#fff;text-transform:uppercase;letter-spacing:0.06em;">Active</span>
            <?php endif; ?>
            <?php if ($app['start_date'] && $app['end_date']): ?>
            <span style="font-size:0.68rem;color:#9ca3af;">
                <?= date('M d, Y', strtotime($app['start_date'])) ?> &mdash; <?= date('M d, Y', strtotime($app['end_date'])) ?>
            </span>
            <?php endif; ?>
        </div>
        <span style="font-size:0.68rem;font-weight:700;padding:2px 10px;
                     background:<?= $statusCfg['bg'] ?>;color:<?= $statusCfg['color'] ?>;
                     border:1px solid <?= $statusCfg['border'] ?>;">
            <?= $statusCfg['label'] ?>
        </span>
    </div>

    <!-- Score + rank row -->
    <div style="display:grid;grid-template-columns:1fr 1fr 1fr 1fr;border-bottom:1px solid #f3f4f6;">
        <?php
        $cells = [
            ['Weighted Score',   $submitted ? number_format((float)$app['weighted_score'],2) : '—',  $submitted && $meetsMin ? '#15803d' : ($submitted ? '#dc2626' : '#9ca3af')],
            ['Position Applied', htmlspecialchars($app['position_title'] ?: ($app['cycle_id'] ? 'Not set' : '—')), '#374151'],
            ['Projected Rank',   htmlspecialchars($app['potential_rank'] ?: '—'), '#1a3a6b'],
            ['Sub-Rank Increment', $app['sub_rank_increment'] > 0 ? '+' . $app['sub_rank_increment'] : ($submitted ? '0' : '—'), $app['sub_rank_increment'] > 0 ? '#15803d' : '#374151'],
        ];
        foreach ($cells as $i => [$lbl, $val, $color]): ?>
        <div style="padding:0.65rem 0.9rem;<?= $i < 3 ? 'border-right:1px solid #f3f4f6;' : '' ?>">
            <div style="font-size:0.6rem;font-weight:700;text-transform:uppercase;letter-spacing:0.07em;color:#9ca3af;margin-bottom:3px;"><?= $lbl ?></div>
            <div style="font-size:0.9rem;font-weight:700;color:<?= $color ?>;"><?= $val ?></div>
        </div>
        <?php endforeach; ?>
    </div>

    <!-- KRA breakdown row -->
    <?php if ($submitted && !empty($kras)): ?>
    <div style="display:grid;grid-template-columns:repeat(4,1fr);border-bottom:1px solid #f3f4f6;background:#fafafa;">
        <?php foreach (['Instruction','Research','Extension','Professional Development'] as $i => $cat):
            $k = $kras[$cat] ?? null;
            $pts = $k ? number_format((float)$k['pts'], 2) : '0.00';
            $entries = $k ? (int)$k['entries'] : 0;
            $verified = $k ? (int)$k['verified_count'] : 0;
        ?>
        <div style="padding:0.5rem 0.9rem;<?= $i < 3 ? 'border-right:1px solid #f3f4f6;' : '' ?>">
            <div style="font-size:0.6rem;font-weight:700;text-transform:uppercase;letter-spacing:0.06em;color:#9ca3af;margin-bottom:2px;">
                <?= $kraLabels[$cat] ?> &mdash; <?= htmlspecialchars($cat) ?>
            </div>
            <div style="display:flex;align-items:center;gap:0.5rem;">
                <span style="font-size:0.82rem;font-weight:700;color:#374151;"><?= $pts ?> pts</span>
                <span style="font-size:0.65rem;color:#9ca3af;"><?= $entries ?> entr<?= $entries !== 1 ? 'ies' : 'y' ?></span>
                <?php if ($verified > 0): ?>
                <span style="font-size:0.6rem;font-weight:700;padding:0 5px;background:#f0fdf4;color:#15803d;border:1px solid #bbf7d0;"><?= $verified ?> verified</span>
                <?php endif; ?>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <!-- Footer row: dates + link -->
    <div style="padding:0.45rem 0.9rem;display:flex;align-items:center;justify-content:space-between;gap:1rem;flex-wrap:wrap;">
        <div style="display:flex;align-items:center;gap:1rem;font-size:0.68rem;color:#9ca3af;">
            <?php if ($app['submitted_at']): ?>
            <span><i class="bi bi-send" style="margin-right:3px;"></i>Submitted <?= date('M d, Y', strtotime($app['submitted_at'])) ?></span>
            <?php endif; ?>
            <?php if ($app['reviewed_at']): ?>
            <span><i class="bi bi-check2-circle" style="margin-right:3px;"></i>Reviewed <?= date('M d, Y', strtotime($app['reviewed_at'])) ?></span>
            <?php endif; ?>
        </div>
        <?php if ($isActive && in_array($app['status'], ['draft','needs_revision','rejected'])): ?>
        <a href="index.php?page=my_application"
           style="font-size:0.72rem;font-weight:700;color:#1a3a6b;text-decoration:none;display:inline-flex;align-items:center;gap:4px;"
           onmouseover="this.style.textDecoration='underline'" onmouseout="this.style.textDecoration='none'">
            <i class="bi bi-arrow-right" style="font-size:0.68rem;"></i> View Current Application
        </a>
        <?php endif; ?>
    </div>
</div>
<?php endforeach; ?>
</div>

<?php endif; ?>
