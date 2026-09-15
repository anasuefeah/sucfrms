<?php
if (!isAdmin()) { echo '<div class="alert alert-danger">Access denied.</div>'; return; }

// Per-cycle stats
$cycle_stats = $pdo->query("
    SELECT c.cycle_name, c.cycle_id,
        COUNT(DISTINCT a.application_id) as applied,
        SUM(CASE WHEN a.status IN ('approved','reclassified') THEN 1 ELSE 0 END) as qualified,
        SUM(CASE WHEN a.status = 'reclassified' THEN 1 ELSE 0 END) as reclassified
    FROM cycles c
    LEFT JOIN applications a ON a.cycle_id = c.cycle_id AND a.status != 'draft'
    GROUP BY c.cycle_id ORDER BY c.created_at ASC
")->fetchAll();

// KRA averages (overall)
$kra_avgs = $pdo->query("
    SELECT kra_category, AVG(computed_points) as avg_pts, COUNT(*) as submissions
    FROM kra_submissions GROUP BY kra_category ORDER BY avg_pts ASC
")->fetchAll();

// Campus breakdown
$dept_stats = $pdo->query("
    SELECT COALESCE(c.campus_name, 'Unassigned') as campus_name,
        COUNT(DISTINCT a.application_id) as applied,
        SUM(CASE WHEN a.status IN ('approved','reclassified') THEN 1 ELSE 0 END) as approved,
        SUM(CASE WHEN a.status='reclassified' THEN 1 ELSE 0 END) as reclassified,
        AVG(a.weighted_score) as avg_score
    FROM applications a
    JOIN users u ON a.user_id = u.user_id
    LEFT JOIN campuses c ON u.campus_id = c.campus_id
    WHERE a.status != 'draft'
    GROUP BY u.campus_id, c.campus_name
    ORDER BY avg_score DESC
")->fetchAll();

// Status distribution
$status_dist = $pdo->query("
    SELECT a.status, COUNT(*) as cnt
    FROM applications a JOIN users u ON a.user_id=u.user_id
    WHERE u.role IN ('faculty','checker_faculty') AND a.status != 'draft'
    GROUP BY a.status
")->fetchAll(PDO::FETCH_KEY_PAIR);

// Overall summary numbers
$total_applied = array_sum(array_column($cycle_stats, 'applied'));
$total_qualified = array_sum(array_column($cycle_stats, 'qualified'));
$total_reclass = array_sum(array_column($cycle_stats, 'reclassified'));
$overall_rate  = $total_applied > 0 ? round(($total_qualified / $total_applied) * 100, 1) : 0;

// Chart data
$cycle_labels   = array_column($cycle_stats, 'cycle_name');
$applied_data   = array_column($cycle_stats, 'applied');
$qualified_data = array_column($cycle_stats, 'qualified');
$reclass_data   = array_column($cycle_stats, 'reclassified');
$kra_labels_avg = array_column($kra_avgs, 'kra_category');
$kra_avg_data   = array_map(fn($k) => round($k['avg_pts'], 2), $kra_avgs);

// Campus chart data
$campus_names   = array_column($dept_stats, 'campus_name');
$campus_scores  = array_map(fn($d) => round($d['avg_score'] ?? 0, 2), $dept_stats);

/* ── Muted, professional palette ──────────────────────────────
   Built on the existing navy/gold design tokens instead of a wide
   rainbow of unrelated hues. Semantic colors (success/warning/danger)
   are reserved for status meaning only. */
$navy_900 = '#142a4d';
$navy_800 = '#1a3a6b';
$navy_700 = '#1e4d8c';
$navy_600 = '#2f6fb0';
$navy_300 = '#a9c2e0';
$gold_700 = '#334155';
$gold_500 = '#475569';
$slate_500= '#64748b';
$success  = '#1e4d8c';
$warning  = '#475569';
$danger   = '#334155';

$status_colors = [
    'submitted'      => $navy_800,
    'under_review'   => $navy_600,
    'talisay_review' => $slate_500,
    'approved'       => $success,
    'rejected'       => $danger,
    'needs_revision' => $warning,
    'admin_rejected' => '#1e293b',
    'reclassified'   => $gold_700,
];
$pie_labels = []; $pie_data = []; $pie_colors = [];
foreach ($status_dist as $st => $cnt) {
    $pie_labels[] = ucwords(str_replace('_',' ',$st));
    $pie_data[]   = $cnt;
    $pie_colors[] = $status_colors[$st] ?? '#94a3b8';
}

// AI summary
$lowest_kra  = !empty($kra_avgs) ? $kra_avgs[0] : null;
$highest_kra = !empty($kra_avgs) ? end($kra_avgs) : null;
$best_campus = !empty($dept_stats) ? $dept_stats[0] : null;
$worst_campus= count($dept_stats) > 1 ? end($dept_stats) : null;

if ($total_applied === 0) {
    $summary = 'No applications have been submitted yet. Analytics will populate once faculty begin applying.';
} else {
    $summary  = "A total of <strong>{$total_applied}</strong> application(s) submitted across all cycles — ";
    $summary .= "<strong>{$total_qualified}</strong> qualified/approved (<strong>{$overall_rate}%</strong> rate). ";
    if ($lowest_kra && $highest_kra && $lowest_kra['kra_category'] !== $highest_kra['kra_category']) {
        $summary .= "<strong>{$lowest_kra['kra_category']}</strong> is the weakest KRA area (avg <strong>".round($lowest_kra['avg_pts'],2)."</strong> pts); ";
        $summary .= "<strong>{$highest_kra['kra_category']}</strong> performs best (avg <strong>".round($highest_kra['avg_pts'],2)."</strong> pts). ";
    }
    if ($best_campus) {
        $summary .= "<strong>{$best_campus['campus_name']}</strong> leads with avg score <strong>".number_format($best_campus['avg_score'],2)."</strong>. ";
    }
    $summary .= $overall_rate >= 75
        ? "Overall performance is <strong style='color:{$success};'>strong</strong>."
        : ($overall_rate > 0 && $overall_rate < 40
            ? "Reclassification rate is <strong style='color:{$danger};'>below 40%</strong> — further faculty support may help."
            : "These figures provide a baseline for monitoring reclassification progress.");
}

// KRA short labels
$kra_short = [
    'Instruction'              => 'KRA I',
    'Research'                 => 'KRA II',
    'Extension'                => 'KRA III',
    'Professional Development' => 'KRA IV',
];
// Single-hue graduated scale (navy → gold) instead of a red/amber/blue/green mix
$kra_colors_chart = [$navy_800, $navy_600, $navy_300, $gold_700];
?>

<!-- ── Page header ── -->
<div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:1rem;margin-bottom:1.5rem;">
    <div style="display:flex;align-items:center;gap:0.75rem;">
        <div style="width:40px;height:40px;border-radius:10px;background:<?= $navy_800 ?>;
                    display:flex;align-items:center;justify-content:center;flex-shrink:0;">
            <i class="bi bi-graph-up-arrow" style="color:#fff;font-size:1.1rem;"></i>
        </div>
        <div>
            <h5 style="margin:0;font-weight:700;color:<?= $navy_800 ?>;font-size:1.1rem;">Analytics</h5>
            <div style="font-size:0.75rem;color:#94a3b8;margin-top:1px;">
                Reclassification performance overview across all cycles and campuses
            </div>
        </div>
    </div>

</div>

<!-- ── KPI stat cards ── -->
<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:1rem;margin-bottom:1.5rem;">

    <?php
    $stats_cards = [
        ['Applied',       $total_applied,    'bi-send-fill'],
        ['Qualified',     $total_qualified,  'bi-check-circle-fill'],
        ['Reclassified',  $total_reclass,    'bi-patch-check-fill'],
    ];
    foreach ($stats_cards as [$label,$val,$icon]):
    ?>
    <div style="background:#fff;border:1px solid #e2e8f0;border-radius:12px;
                padding:1.1rem 1.25rem;">
        <div style="display:flex;align-items:center;gap:0.65rem;margin-bottom:0.65rem;">
            <div style="width:34px;height:34px;border-radius:8px;background:<?= $navy_800 ?>0d;
                        display:flex;align-items:center;justify-content:center;flex-shrink:0;">
                <i class="bi <?= $icon ?>" style="color:<?= $navy_800 ?>;font-size:0.9rem;"></i>
            </div>
            <span style="font-size:0.72rem;font-weight:600;color:#64748b;text-transform:uppercase;
                         letter-spacing:0.05em;"><?= $label ?></span>
        </div>
        <div style="font-size:1.85rem;font-weight:800;color:<?= $navy_900 ?>;line-height:1;letter-spacing:-1px;">
            <?= $val ?>
        </div>
    </div>
    <?php endforeach; ?>

    <!-- Success rate — the single highlighted metric, gold accent -->
    <div style="background:linear-gradient(135deg,<?= $navy_800 ?>,<?= $navy_700 ?>);border-radius:12px;
                padding:1.1rem 1.25rem;position:relative;overflow:hidden;">
        <div style="position:absolute;top:-16px;right:-16px;width:80px;height:80px;border-radius:50%;
                    background:rgba(255,255,255,0.06);"></div>
        <div style="display:flex;align-items:center;gap:0.65rem;margin-bottom:0.65rem;">
            <div style="width:34px;height:34px;border-radius:8px;background:rgba(255,255,255,0.14);
                        display:flex;align-items:center;justify-content:center;flex-shrink:0;">
                <i class="bi bi-percent" style="color:<?= $gold_500 ?>;font-size:0.9rem;"></i>
            </div>
            <span style="font-size:0.72rem;font-weight:600;color:#cbd8ec;text-transform:uppercase;
                         letter-spacing:0.05em;">Success Rate</span>
        </div>
        <div style="font-size:1.85rem;font-weight:800;color:#fff;line-height:1;letter-spacing:-1px;">
            <?= $overall_rate ?>%
        </div>
    </div>
</div>

<!-- ── Charts row 1: Cycle bar + Status donut ── -->
<div style="display:grid;grid-template-columns:1fr 380px;gap:1rem;margin-bottom:1rem;">

    <!-- Cycle grouped bar -->
    <div class="neon-card" style="padding:1.25rem;">
        <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:1rem;flex-wrap:wrap;gap:0.5rem;">
            <div>
                <div style="font-weight:700;color:<?= $navy_800 ?>;font-size:0.88rem;">
                    Applications per Cycle
                </div>
                <div style="font-size:0.72rem;color:#94a3b8;margin-top:1px;">Applied vs Qualified vs Reclassified</div>
            </div>
            <div style="display:flex;gap:0.75rem;flex-wrap:wrap;">
                <?php foreach ([[$navy_800,'Applied'],[$navy_600,'Qualified'],[$gold_700,'Reclassified']] as [$c,$l]): ?>
                <span style="display:flex;align-items:center;gap:4px;font-size:0.7rem;color:#64748b;">
                    <span style="width:10px;height:10px;border-radius:3px;background:<?= $c ?>;flex-shrink:0;"></span><?= $l ?>
                </span>
                <?php endforeach; ?>
            </div>
        </div>
        <div style="height:240px;"><canvas id="cycleChart"></canvas></div>
    </div>

    <!-- Status donut -->
    <div class="neon-card" style="padding:1.25rem;">
        <div style="font-weight:700;color:<?= $navy_800 ?>;font-size:0.88rem;margin-bottom:0.3rem;">
            Status Distribution
        </div>
        <div style="font-size:0.72rem;color:#94a3b8;margin-bottom:0.85rem;">Current application pipeline</div>
        <?php if (array_sum($pie_data) > 0): ?>
        <div style="height:180px;"><canvas id="statusDonut"></canvas></div>
        <div style="margin-top:0.75rem;display:flex;flex-direction:column;gap:3px;">
            <?php foreach ($pie_labels as $i => $pl): ?>
            <div style="display:flex;align-items:center;justify-content:space-between;font-size:0.72rem;">
                <div style="display:flex;align-items:center;gap:5px;color:#475569;">
                    <span style="width:8px;height:8px;border-radius:2px;background:<?= $pie_colors[$i] ?>;flex-shrink:0;"></span>
                    <?= $pl ?>
                </div>
                <strong style="color:<?= $navy_800 ?>;"><?= $pie_data[$i] ?></strong>
            </div>
            <?php endforeach; ?>
        </div>
        <?php else: ?>
        <div style="height:180px;display:flex;align-items:center;justify-content:center;color:#94a3b8;font-size:0.82rem;">
            No data yet
        </div>
        <?php endif; ?>
    </div>
</div>

<!-- ── Charts row 2: KRA bars + Campus avg ── -->
<div style="display:grid;grid-template-columns:1fr 1fr;gap:1rem;margin-bottom:1rem;">

    <!-- KRA avg horizontal bars -->
    <div class="neon-card" style="padding:1.25rem;">
        <div style="font-weight:700;color:<?= $navy_800 ?>;font-size:0.88rem;margin-bottom:0.3rem;">
            KRA Average Scores
        </div>
        <div style="font-size:0.72rem;color:#94a3b8;margin-bottom:1rem;">Overall avg points per KRA category</div>
        <?php if (!empty($kra_avgs)):
            $maxKra = max(array_column($kra_avgs, 'avg_pts')) ?: 100;
            foreach ($kra_avgs as $i => $kra):
                $pct = $maxKra > 0 ? round(($kra['avg_pts'] / $maxKra) * 100, 1) : 0;
                $short = $kra_short[$kra['kra_category']] ?? $kra['kra_category'];
                $col = $kra_colors_chart[$i % 4];
        ?>
        <div style="margin-bottom:0.85rem;">
            <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:4px;">
                <div>
                    <span style="font-size:0.72rem;font-weight:700;color:<?= $navy_800 ?>;"><?= $short ?></span>
                    <span style="font-size:0.68rem;color:#94a3b8;margin-left:5px;"><?= sanitize($kra['kra_category']) ?></span>
                </div>
                <span style="font-size:0.78rem;font-weight:700;color:<?= $col ?>;">
                    <?= number_format($kra['avg_pts'], 2) ?> pts
                </span>
            </div>
            <div style="height:9px;background:#f1f5f9;border-radius:99px;overflow:hidden;">
                <div style="height:100%;width:<?= $pct ?>%;background:<?= $col ?>;border-radius:99px;
                            transition:width 0.6s ease;"></div>
            </div>
            <div style="font-size:0.65rem;color:#94a3b8;margin-top:2px;">
                <?= $kra['submissions'] ?> submission<?= $kra['submissions'] != 1 ? 's' : '' ?>
            </div>
        </div>
        <?php endforeach; else: ?>
        <div style="text-align:center;padding:2rem;color:#94a3b8;font-size:0.82rem;">No KRA data yet</div>
        <?php endif; ?>
    </div>

    <!-- Campus avg score chart -->
    <div class="neon-card" style="padding:1.25rem;">
        <div style="font-weight:700;color:<?= $navy_800 ?>;font-size:0.88rem;margin-bottom:0.3rem;">
            Campus Average Score
        </div>
        <div style="font-size:0.72rem;color:#94a3b8;margin-bottom:0.85rem;">Average weighted score per campus</div>
        <?php if (!empty($dept_stats)): ?>
        <div style="height:220px;"><canvas id="campusChart"></canvas></div>
        <?php else: ?>
        <div style="height:220px;display:flex;align-items:center;justify-content:center;color:#94a3b8;font-size:0.82rem;">
            No campus data yet
        </div>
        <?php endif; ?>
    </div>
</div>

<!-- ── Per-Cycle summary table ── -->
<div class="neon-card mb-3" style="padding:0;overflow:hidden;">
    <div style="padding:1rem 1.25rem;border-bottom:1px solid #f1f5f9;">
        <div style="font-weight:700;color:<?= $navy_800 ?>;font-size:0.88rem;">Per-Cycle Summary</div>
        <div style="font-size:0.72rem;color:#94a3b8;">Detailed breakdown by reclassification cycle</div>
    </div>
    <div class="table-responsive">
        <table style="width:100%;border-collapse:collapse;font-size:0.83rem;">
            <thead>
                <tr style="background:#f8fafc;border-bottom:2px solid #e2e8f0;">
                    <th style="padding:0.7rem 1rem;text-align:left;font-size:0.7rem;font-weight:700;
                               text-transform:uppercase;letter-spacing:0.05em;color:#64748b;">Cycle</th>
                    <th style="padding:0.7rem 0.75rem;text-align:center;font-size:0.7rem;font-weight:700;
                               text-transform:uppercase;letter-spacing:0.05em;color:#64748b;">Applied</th>
                    <th style="padding:0.7rem 0.75rem;text-align:center;font-size:0.7rem;font-weight:700;
                               text-transform:uppercase;letter-spacing:0.05em;color:#64748b;">Qualified / Approved</th>
                    <th style="padding:0.7rem 0.75rem;text-align:center;font-size:0.7rem;font-weight:700;
                               text-transform:uppercase;letter-spacing:0.05em;color:#64748b;">Reclassified</th>
                    <th style="padding:0.7rem 0.75rem;text-align:left;font-size:0.7rem;font-weight:700;
                               text-transform:uppercase;letter-spacing:0.05em;color:#64748b;min-width:180px;">Rate</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($cycle_stats as $cs):
                $rate = $cs['applied'] > 0 ? round(($cs['qualified'] / $cs['applied']) * 100, 1) : 0;
                $rc   = $rate >= 75 ? $success : ($rate >= 40 ? $navy_700 : ($rate > 0 ? $warning : '#94a3b8'));
            ?>
            <tr style="border-bottom:1px solid #f0f4fb;" onmouseover="this.style.background='#f8fafc'" onmouseout="this.style.background=''">
                <td style="padding:0.85rem 1rem;font-weight:600;color:#1e293b;">
                    <div style="display:flex;align-items:center;gap:0.5rem;">
                        <div style="width:8px;height:8px;border-radius:50%;background:<?= $navy_800 ?>;flex-shrink:0;"></div>
                        <?= sanitize($cs['cycle_name']) ?>
                    </div>
                </td>
                <td style="padding:0.85rem 0.75rem;text-align:center;">
                    <span style="background:#f1f5f9;color:#475569;padding:0.2rem 0.7rem;
                                 border-radius:20px;font-weight:700;font-size:0.82rem;">
                        <?= $cs['applied'] ?>
                    </span>
                </td>
                <td style="padding:0.85rem 0.75rem;text-align:center;">
                    <span style="background:#f1f5f9;color:#475569;padding:0.2rem 0.7rem;
                                 border-radius:20px;font-weight:700;font-size:0.82rem;">
                        <?= $cs['qualified'] ?>
                    </span>
                </td>
                <td style="padding:0.85rem 0.75rem;text-align:center;">
                    <span style="background:#f1f5f9;color:#475569;padding:0.2rem 0.7rem;
                                 border-radius:20px;font-weight:700;font-size:0.82rem;">
                        <?= $cs['reclassified'] ?>
                    </span>
                </td>
                <td style="padding:0.85rem 0.75rem;">
                    <div style="display:flex;align-items:center;gap:0.6rem;">
                        <div style="flex:1;height:8px;background:#f1f5f9;border-radius:99px;overflow:hidden;">
                            <div style="height:100%;width:<?= $rate ?>%;background:<?= $rc ?>;border-radius:99px;"></div>
                        </div>
                        <span style="font-size:0.75rem;font-weight:700;color:<?= $rc ?>;min-width:36px;">
                            <?= $rate ?>%
                        </span>
                    </div>
                </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- ── Campus breakdown table ── -->
<?php if ($dept_stats): ?>
<div class="neon-card mb-3" style="padding:0;overflow:hidden;">
    <div style="padding:1rem 1.25rem;border-bottom:1px solid #f1f5f9;">
        <div style="font-weight:700;color:<?= $navy_800 ?>;font-size:0.88rem;">Campus Breakdown</div>
        <div style="font-size:0.72rem;color:#94a3b8;">Performance by campus across all cycles</div>
    </div>
    <div class="table-responsive">
        <table style="width:100%;border-collapse:collapse;font-size:0.83rem;">
            <thead>
                <tr style="background:#f8fafc;border-bottom:2px solid #e2e8f0;">
                    <th style="padding:0.7rem 1rem;text-align:left;font-size:0.7rem;font-weight:700;text-transform:uppercase;letter-spacing:0.05em;color:#64748b;">Campus</th>
                    <th style="padding:0.7rem 0.75rem;text-align:center;font-size:0.7rem;font-weight:700;text-transform:uppercase;letter-spacing:0.05em;color:#64748b;">Applied</th>
                    <th style="padding:0.7rem 0.75rem;text-align:center;font-size:0.7rem;font-weight:700;text-transform:uppercase;letter-spacing:0.05em;color:#64748b;">Approved</th>
                    <th style="padding:0.7rem 0.75rem;text-align:center;font-size:0.7rem;font-weight:700;text-transform:uppercase;letter-spacing:0.05em;color:#64748b;">Reclassified</th>
                    <th style="padding:0.7rem 0.75rem;text-align:left;font-size:0.7rem;font-weight:700;text-transform:uppercase;letter-spacing:0.05em;color:#64748b;min-width:180px;">Avg Score</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($dept_stats as $idx => $d):
                $sc = (float)($d['avg_score'] ?? 0);
                $sc_pct = min(100, round($sc));
                $sc_col = $sc >= 71 ? $success : ($sc >= 41 ? $navy_700 : $danger);
                $is_best = $idx === 0 && count($dept_stats) > 1;
            ?>
            <tr style="border-bottom:1px solid #f0f4fb;" onmouseover="this.style.background='#f8fafc'" onmouseout="this.style.background=''">
                <td style="padding:0.85rem 1rem;font-weight:600;color:#1e293b;">
                    <div style="display:flex;align-items:center;gap:0.5rem;">
                        <div style="width:8px;height:8px;border-radius:50%;
                                    background:<?= $is_best ? $success : $navy_800 ?>;flex-shrink:0;"></div>
                        <?= sanitize($d['campus_name']) ?>
                        <?php if ($is_best): ?>
                        <span style="background:#f0f4fb;color:<?= $success ?>;font-size:0.6rem;font-weight:700;
                                     border-radius:4px;padding:1px 5px;border:1px solid #bfdbfe;">Top</span>
                        <?php endif; ?>
                    </div>
                </td>
                <td style="padding:0.85rem 0.75rem;text-align:center;">
                    <span style="background:#f1f5f9;color:#475569;padding:0.18rem 0.6rem;border-radius:20px;font-weight:700;font-size:0.8rem;"><?= $d['applied'] ?></span>
                </td>
                <td style="padding:0.85rem 0.75rem;text-align:center;">
                    <span style="background:#f1f5f9;color:#475569;padding:0.18rem 0.6rem;border-radius:20px;font-weight:700;font-size:0.8rem;"><?= $d['approved'] ?></span>
                </td>
                <td style="padding:0.85rem 0.75rem;text-align:center;">
                    <span style="background:#f1f5f9;color:#475569;padding:0.18rem 0.6rem;border-radius:20px;font-weight:700;font-size:0.8rem;"><?= $d['reclassified'] ?></span>
                </td>
                <td style="padding:0.85rem 0.75rem;">
                    <div style="display:flex;align-items:center;gap:0.6rem;">
                        <div style="flex:1;height:8px;background:#f1f5f9;border-radius:99px;overflow:hidden;">
                            <div style="height:100%;width:<?= $sc_pct ?>%;background:<?= $sc_col ?>;border-radius:99px;"></div>
                        </div>
                        <span style="font-size:0.78rem;font-weight:700;color:<?= $sc_col ?>;min-width:42px;">
                            <?= number_format($sc, 2) ?>
                        </span>
                    </div>
                </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endif; ?>

<!-- ── AI Summary insight card ── -->
<div class="neon-card" style="padding:1.1rem 1.25rem;background:#f8fafc;border:1px solid #e2e8f0;">
    <div style="display:flex;align-items:flex-start;gap:0.85rem;">
        <div style="width:36px;height:36px;border-radius:8px;background:<?= $navy_800 ?>;
                    display:flex;align-items:center;justify-content:center;flex-shrink:0;margin-top:1px;">
            <i class="bi bi-lightbulb-fill" style="color:<?= $gold_500 ?>;font-size:0.9rem;"></i>
        </div>
        <div>
            <div style="font-size:0.8rem;font-weight:700;color:<?= $navy_800 ?>;margin-bottom:0.35rem;
                        text-transform:uppercase;letter-spacing:0.04em;">
                System Insight
            </div>
            <p style="margin:0;font-size:0.83rem;line-height:1.7;color:#334155;"><?= $summary ?></p>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {

    const chartDefaults = {
        responsive: true,
        maintainAspectRatio: false,
        plugins: {
            legend: { display: false },
            tooltip: {
                backgroundColor: '#1e293b',
                titleColor: '#f1f5f9',
                bodyColor: '#cbd5e1',
                cornerRadius: 8,
                padding: 10
            }
        }
    };

    // ── Cycle grouped bar ──────────────────────────────────────────
    new Chart(document.getElementById('cycleChart'), {
        type: 'bar',
        data: {
            labels: <?= json_encode($cycle_labels) ?>,
            datasets: [
                {
                    label: 'Applied',
                    data: <?= json_encode($applied_data) ?>,
                    backgroundColor: '<?= $navy_800 ?>',
                    borderRadius: 6,
                    borderSkipped: false
                },
                {
                    label: 'Qualified',
                    data: <?= json_encode($qualified_data) ?>,
                    backgroundColor: '<?= $navy_600 ?>',
                    borderRadius: 6,
                    borderSkipped: false
                },
                {
                    label: 'Reclassified',
                    data: <?= json_encode($reclass_data) ?>,
                    backgroundColor: '<?= $gold_700 ?>',
                    borderRadius: 6,
                    borderSkipped: false
                }
            ]
        },
        options: {
            ...chartDefaults,
            plugins: {
                ...chartDefaults.plugins,
                legend: { display: false }
            },
            scales: {
                y: {
                    beginAtZero: true,
                    grid: { color: '#f1f5f9', drawBorder: false },
                    ticks: { color: '#64748b', font: { size: 11 } }
                },
                x: {
                    grid: { display: false },
                    ticks: {
                        color: '#64748b',
                        font: { size: 11 },
                        maxRotation: 30,
                        callback: function(val, idx) {
                            const lbl = this.getLabelForValue(val);
                            return lbl.length > 22 ? lbl.substring(0,22)+'…' : lbl;
                        }
                    }
                }
            }
        }
    });

    // ── Status donut ───────────────────────────────────────────────
    <?php if (array_sum($pie_data) > 0): ?>
    new Chart(document.getElementById('statusDonut'), {
        type: 'doughnut',
        data: {
            labels: <?= json_encode($pie_labels) ?>,
            datasets: [{
                data: <?= json_encode($pie_data) ?>,
                backgroundColor: <?= json_encode($pie_colors) ?>,
                borderWidth: 2,
                borderColor: '#fff',
                hoverOffset: 6
            }]
        },
        options: {
            ...chartDefaults,
            cutout: '68%',
            plugins: {
                ...chartDefaults.plugins,
                tooltip: {
                    ...chartDefaults.plugins.tooltip,
                    callbacks: {
                        label: ctx => ` ${ctx.label}: ${ctx.parsed} (${Math.round(ctx.parsed/<?= max(1,array_sum($pie_data)) ?>*100)}%)`
                    }
                }
            }
        }
    });
    <?php endif; ?>

    // ── Campus avg bar ────────────────────────────────────────────
    <?php if (!empty($dept_stats)): ?>
    new Chart(document.getElementById('campusChart'), {
        type: 'bar',
        data: {
            labels: <?= json_encode($campus_names) ?>,
            datasets: [{
                data: <?= json_encode($campus_scores) ?>,
                backgroundColor: <?= json_encode(array_map(fn($s) => (float)$s >= 71 ? $success : ((float)$s >= 41 ? $navy_700 : $danger), $campus_scores)) ?>,
                borderRadius: 6,
                borderSkipped: false
            }]
        },
        options: {
            ...chartDefaults,
            indexAxis: 'y',
            scales: {
                x: {
                    beginAtZero: true, max: 100,
                    grid: { color: '#f1f5f9', drawBorder: false },
                    ticks: { color: '#64748b', font: { size: 11 } }
                },
                y: {
                    grid: { display: false },
                    ticks: {
                        color: '#64748b',
                        font: { size: 11, weight: '600' },
                        callback: function(val) {
                            const lbl = this.getLabelForValue(val);
                            return lbl.length > 18 ? lbl.substring(0,18)+'…' : lbl;
                        }
                    }
                }
            },
            plugins: {
                ...chartDefaults.plugins,
                tooltip: {
                    ...chartDefaults.plugins.tooltip,
                    callbacks: { label: ctx => ` Avg Score: ${ctx.parsed.x}` }
                }
            }
        }
    });
    <?php endif; ?>

}); // end DOMContentLoaded
</script>
