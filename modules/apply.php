<?php
/**
 * Faculty KRA Entry / Evidence Upload
 */

$uid   = $_SESSION['user_id'];
$cycle = getActiveCycle($pdo);

if (!$cycle) {
    echo '<div class="neon-card rounded-0 shadow-none text-center py-5">
        <i class="bi bi-calendar-x fs-1 text-secondary mb-3 d-block"></i>
        <h5 style="color:#1a3a6b;font-weight:700;margin-bottom:0.5rem;">No Active Reclassification Cycle</h5>
        <p class="text-muted">There is no open cycle at this time. Your account is ready.</p>
        <p class="text-muted small">Check back when the next cycle opens.</p>
    </div>';
    return;
}

$app    = getOrCreateApplication($pdo, $uid, $cycle['cycle_id']);
$app_id = $app['application_id'];
// Only lock when checker has approved or admin has finalized
$locked = in_array($app['status'], ['approved', 'reclassified', 'admin_rejected']);

// Fetch faculty profile with campus name
$faculty = $pdo->prepare("SELECT u.*, c.campus_name FROM users u LEFT JOIN campuses c ON u.campus_id = c.campus_id WHERE u.user_id = ?");
$faculty->execute([$uid]);
$faculty = $faculty->fetch();

$score_summary = getApplicationScoreSummary($pdo, (int)$app_id);
$kra_totals = [
    'Instruction'              => $score_summary['kra1'],
    'Research'                 => $score_summary['kra2'],
    'Extension'                => $score_summary['kra3'],
    'Professional Development' => $score_summary['kra4'],
];
$grand_total = $score_summary['grand_total'];

$kra_short = [
    'Instruction'              => 'KRA 1',
    'Research'                 => 'KRA 2',
    'Extension'                => 'KRA 3',
    'Professional Development' => 'KRA 4',
];

$rank_sg = [
    'Instructor I'            => 'SG-12', 'Instructor II'           => 'SG-13',
    'Instructor III'          => 'SG-14', 'Assistant Professor I'   => 'SG-15',
    'Assistant Professor II'  => 'SG-16', 'Assistant Professor III' => 'SG-17',
    'Assistant Professor IV'  => 'SG-18', 'Associate Professor I'   => 'SG-19',
    'Associate Professor II'  => 'SG-20', 'Associate Professor III' => 'SG-21',
    'Associate Professor IV'  => 'SG-22', 'Associate Professor V'   => 'SG-23',
    'Professor I'             => 'SG-24', 'Professor II'            => 'SG-25',
    'Professor III'           => 'SG-26', 'Professor IV'            => 'SG-27',
    'Professor V'             => 'SG-28', 'Professor VI'            => 'SG-29',
    'University Professor'    => 'SG-30',
];

?>

<div class="apply-wrapper">
    <div style="overflow:visible;">
        <?php showFlash(); ?>
        <?php include __DIR__ . '/../includes/apply/kra_entry.php'; ?>
    </div>
</div>

<!-- ── KRA Self-Assessment Intro Modal ──────────────────── -->
<?php if ($edit_sid === 0): ?>
<div id="kraIntroModal"
     style="display:none;position:fixed;inset:0;z-index:99999;background:rgba(15,23,42,0.6);
            align-items:center;justify-content:center;padding:1rem;">
    <div style="background:#fff;width:100%;max-width:500px;border:1px solid #e5e7eb;overflow:hidden;
                animation:welcomeFadeIn 0.3s ease;">

        <!-- Header -->
        <div style="background:#1a3a6b;padding:1.25rem 1.25rem 1rem;text-align:center;">
            <div style="width:50px;height:50px;border-radius:50%;background:rgba(255,255,255,0.12);
                        border:2px solid rgba(255,255,255,0.25);display:flex;align-items:center;
                        justify-content:center;margin:0 auto 0.65rem;">
                <i class="bi bi-pencil-square" style="color:#fff;font-size:1.3rem;"></i>
            </div>
            <div style="font-size:1rem;font-weight:700;color:#fff;line-height:1.3;">KRA Self-Assessment Entry</div>
            <div style="font-size:0.72rem;color:rgba(255,255,255,0.55);margin-top:0.25rem;">Faculty Reclassification Management System</div>
        </div>

        <!-- Body -->
        <div style="padding:1.1rem 1.25rem 1.25rem;">
            <p style="font-size:0.82rem;color:#374151;line-height:1.65;margin:0 0 1rem;">
                This module is where you fill in your self-assessment scores and upload supporting evidence for each Key Result Area (KRA). Your entries are used to compute your reclassification score.
            </p>

            <div style="display:flex;flex-direction:column;gap:0.45rem;margin-bottom:1.1rem;">
                <?php foreach ([
                    ['bi-1-circle','KRA 1 — Instruction','Teaching performance and classroom-related outputs.'],
                    ['bi-2-circle','KRA 2 — Research','Research outputs, publications, and presentations.'],
                    ['bi-3-circle','KRA 3 — Extension','Community outreach and extension service activities.'],
                    ['bi-4-circle','KRA 4 — Professional Development','Trainings, seminars, and professional growth activities.'],
                ] as [$icon, $title, $desc]): ?>
                <div style="display:flex;align-items:flex-start;gap:0.65rem;padding:0.5rem 0.65rem;
                            border:1px solid #f3f4f6;background:#f9fafb;">
                    <i class="bi <?= $icon ?>" style="color:#1a3a6b;font-size:0.95rem;flex-shrink:0;margin-top:1px;"></i>
                    <div>
                        <div style="font-size:0.75rem;font-weight:700;color:#1a3a6b;line-height:1.2;"><?= $title ?></div>
                        <div style="font-size:0.72rem;color:#6b7280;margin-top:1px;line-height:1.4;"><?= $desc ?></div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>

            <div style="border:1px solid #fde68a;background:#fffbeb;padding:0.5rem 0.65rem;
                        display:flex;align-items:flex-start;gap:0.5rem;margin-bottom:1rem;">
                <i class="bi bi-exclamation-triangle-fill" style="color:#d97706;font-size:0.75rem;flex-shrink:0;margin-top:1px;"></i>
                <span style="font-size:0.72rem;color:#78350f;line-height:1.5;">
                    Make sure to upload valid evidence files for each entry. Unverified entries will not count toward your final score.
                </span>
            </div>

            <button type="button" id="kraIntroProceedBtn"
                    style="width:100%;padding:0.6rem;background:#1a3a6b;color:#fff;border:none;
                           font-size:0.85rem;font-weight:700;cursor:pointer;display:flex;align-items:center;justify-content:center;gap:6px;"
                    onmouseover="this.style.background='#152d54'" onmouseout="this.style.background='#1a3a6b'">
                <i class="bi bi-arrow-right-circle" style="font-size:0.85rem;"></i>
                Got it — Start My Self-Assessment
            </button>
        </div>
    </div>
</div>

<script>
(function() {
    const modal = document.getElementById('kraIntroModal');
    const button = document.getElementById('kraIntroProceedBtn');
    if (!modal || !button) return;

    const storageKey = 'sucfrms.kraIntroAcknowledged.<?= (int)$uid ?>';
    try {
        if (localStorage.getItem(storageKey) === '1') return;
    } catch (_) {}

    modal.style.display = 'flex';
    button.addEventListener('click', function() {
        modal.style.display = 'none';
        try {
            localStorage.setItem(storageKey, '1');
        } catch (_) {}
    });
})();
</script>

<style>
@keyframes welcomeFadeIn {
    from { opacity:0; transform:translateY(16px); }
    to   { opacity:1; transform:translateY(0); }
}
</style>
<?php endif; ?>
