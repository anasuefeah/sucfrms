<?php
/**
 * Multi-step Faculty Reclassification Application
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

$step = max(1, min(2, intval($_GET['step'] ?? 1)));

// Stay on Step 2 once reached &mdash; only go back to Step 1 if explicitly clicked
if (isset($_GET['step'])) {
    $requested = (int)$_GET['step'];
    $remembered = (int)($_SESSION['apply_step'][$app_id] ?? 1);
    // Only allow going back to step 1 if user explicitly requested it
    // AND they are currently on step 2 (not a redirect from save/upload)
    if ($requested === 1 && $remembered === 2) {
        // Check if this is a deliberate back-click (no referer from step2 actions)
        $referer = $_SERVER['HTTP_REFERER'] ?? '';
        $is_step2_action = strpos($referer, 'step=2') !== false && 
                           (strpos($referer, 'tab=') !== false || strpos($referer, 'kra_action') !== false);
        if (!$is_step2_action) {
            // User deliberately clicked Step 1 &mdash; allow it
            $_SESSION['apply_step'][$app_id] = 1;
            $step = 1;
        } else {
            // Redirect from a step2 action &mdash; stay on step 2
            $step = 2;
            $_SESSION['apply_step'][$app_id] = 2;
        }
    } else {
        $_SESSION['apply_step'][$app_id] = $requested;
        $step = $requested;
    }
} elseif (!empty($_SESSION['apply_step'][$app_id])) {
    // No step in URL &mdash; restore last remembered step
    $step = (int)$_SESSION['apply_step'][$app_id];
}

$step1_data     = $_SESSION['apply_step1'][$app_id] ?? [];
$step1_complete = !empty($step1_data['position_title']);

// Restore Step 1 data from DB if session is empty (e.g. after session expiry or browser close)
if (!$step1_complete) {
    try {
        $db_step1 = $pdo->prepare("SELECT position_title, salary_grade, study_leave FROM applications WHERE application_id=?");
        $db_step1->execute([$app_id]);
        $db_row = $db_step1->fetch();
        if ($db_row && !empty($db_row['position_title'])) {
            // Repopulate session from DB
            $_SESSION['apply_step1'][$app_id] = [
                'position_title' => $db_row['position_title'],
                'salary_grade'   => $db_row['salary_grade'] ?? '',
                'study_leave'    => $db_row['study_leave'] ?? 'no',
            ];
            $step1_data     = $_SESSION['apply_step1'][$app_id];
            $step1_complete = true;
        }
    } catch (\Exception $e) {
        // Columns not yet added &mdash; fall through to KRA submissions check
    }
}

// Also treat step 1 as complete if the application already has KRA submissions
// (e.g. returning after session expiry, or editing a rejected application)
if (!$step1_complete) {
    $has_subs = $pdo->prepare("SELECT COUNT(*) FROM kra_submissions WHERE application_id = ?");
    $has_subs->execute([$app_id]);
    if ((int)$has_subs->fetchColumn() > 0) {
        $step1_complete = true;
    }
}

if ($step === 2 && !$step1_complete && !$locked) {
    flashMessage('warning', 'Please complete <strong>Step 1 &mdash; Application Information</strong> before proceeding to Step 2.');
    $step = 1;
}
?>

<div class="apply-wrapper">
    <div style="overflow:visible;">
        <?php showFlash(); ?>
        <?php if ($step === 1): ?>
            <?php include __DIR__ . '/../includes/apply/step1_info.php'; ?>
        <?php else: ?>
            <?php include __DIR__ . '/../includes/apply/step2_upload.php'; ?>
        <?php endif; ?>
    </div>

</div>



