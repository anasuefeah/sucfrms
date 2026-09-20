<?php
/**
 * Faculty KRA Entry / Evidence Upload
 */

$uid   = $_SESSION['user_id'];
$cycle = getActiveCycle($pdo);

if (!$cycle) {
    echo '<div class="neon-card text-center py-5">
        <i class="bi bi-calendar-x fs-1 text-secondary mb-3 d-block"></i>
        <h5 style="color:#1a3a6b;font-weight:700;margin-bottom:0.5rem;">No Active Reclassification Cycle</h5>
        <p class="text-muted">There is no open cycle at this time. Your account is ready &mdash; no re-registration needed.</p>
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

// Fetch KRA submissions for this application
$stmt = $pdo->prepare("SELECT kra_category, SUM(computed_points) AS total FROM kra_submissions WHERE application_id = ? GROUP BY kra_category");
$stmt->execute([$app_id]);
$raw_totals = [];
foreach ($stmt->fetchAll() as $r) {
    $raw_totals[$r['kra_category']] = (float)$r['total'];
}

$kra_max = [
    'Instruction'              => 100,
    'Research'                 => 100,
    'Extension'                => 100,
    'Professional Development' => 100,
];

$kra_totals = [];
// Cap each KRA at its actual maximum
foreach ($kra_max as $cat => $max) {
    $kra_totals[$cat] = min($max, $raw_totals[$cat] ?? 0);
}

$grand_total = array_sum($kra_totals);

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
