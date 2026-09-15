<?php
/**
 * Step 1 &mdash; Application Information
 * Collects faculty details, position applied for, study leave, and AY ratings.
 * Data is stored in session keyed by application_id (persists until submission).
 */

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$locked) {
    $step_action = $_POST['step_action'] ?? '';

    if ($step_action === 'save_step1') {
        // Mark step 1 as complete in session so step 2 is accessible
        if (!isset($_SESSION['apply_step1'][$app_id])) {
            $_SESSION['apply_step1'][$app_id] = [];
        }
        // Store a placeholder so step1_complete check passes
        $_SESSION['apply_step1'][$app_id]['position_title'] = $faculty['rank'] ?? 'set';

        // Persist to DB
        try {
            $pdo->prepare("UPDATE applications SET position_title=? WHERE application_id=?")
                ->execute([$faculty['rank'] ?? 'set', $app_id]);
        } catch (\Exception $e) {}

        logAudit($pdo, $uid, 'Step 1 Saved', "Application #{$app_id} info saved.");
        // Remember step 2 in session so sidebar link restores it
        $_SESSION['apply_step'][$app_id] = 2;
        echo "<script>window.location.href='index.php?page=apply&step=2';</script>";
        exit;
    }
}

// Load saved data (from session or DB)
$s1 = $_SESSION['apply_step1'][$app_id] ?? [];

// If session is empty, check DB or KRA submissions to mark step1 complete
if (empty($s1['position_title'])) {
    try {
        $db_s1 = $pdo->prepare("SELECT position_title FROM applications WHERE application_id=?");
        $db_s1->execute([$app_id]);
        $db_row = $db_s1->fetch();
        if ($db_row && !empty($db_row['position_title'])) {
            $_SESSION['apply_step1'][$app_id]['position_title'] = $db_row['position_title'];
            $s1 = $_SESSION['apply_step1'][$app_id];
        }
    } catch (\Exception $e) {}
}

$ay_years = ['2019-2020','2020-2021','2021-2022','2022-2023','2023-2024','2024-2025'];
?>

<div class="neon-card mb-3">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h5 class="mb-0" style="color:var(--blue-dark);">
            <i class="bi bi-person-lines-fill me-2"></i>Step 1 &mdash; Application Information <?= helpBtn('Step 1', 'Confirm your profile details. Your rank is auto-filled from your account. Click "Save & Continue to Step 2" to proceed to KRA document uploading. You can always come back to Step 1 later.') ?>
        </h5>
        <span class="badge bg-secondary">Cycle: <?= sanitize($cycle['cycle_name']) ?></span>
    </div>

    <form method="POST" action="index.php?page=apply&step=1">
        <input type="hidden" name="step_action" value="save_step1">

        <!-- Faculty Info (read-only) -->
        <div class="mb-3 pb-2 border-bottom" style="border-color:#e2e8f0!important;">
            <span class="text-muted" style="font-size:0.75rem;font-weight:600;letter-spacing:.06em;text-transform:uppercase;">
                <i class="bi bi-person-badge me-1"></i>Personal Information
            </span>
            <p class="mb-0 mt-1" style="font-size:0.78rem;color:#64748b;">
                These details are pulled from your account and used only for the reclassification process.
                <a href="index.php?page=profile"
                   style="color:#1e4d8c;font-weight:600;text-decoration:underline;
                          display:inline-flex;align-items:center;gap:0.25rem;"
                   onmouseover="this.style.color='#1a3a6b'"
                   onmouseout="this.style.color='#1e4d8c'">
                    <i class="bi bi-pencil-square" style="font-size:0.75rem;"></i>Update profile
                </a> to make changes.
            </p>
        </div>

        <div class="row g-3 mb-4">
            <div class="col-md-6">
                <label class="form-label fw-semibold">Full Name</label>
                <input type="text" class="form-control bg-light" value="<?= sanitize($faculty['full_name'] ?? '') ?>" disabled>
            </div>
            <div class="col-md-6">
                <label class="form-label fw-semibold">Email</label>
                <input type="text" class="form-control bg-light" value="<?= sanitize($faculty['email'] ?? '') ?>" disabled>
            </div>
            <div class="col-md-4">
                <label class="form-label fw-semibold">First Name</label>
                <input type="text" class="form-control bg-light" value="<?= sanitize($faculty['first_name'] ?? '') ?>" disabled>
            </div>
            <div class="col-md-4">
                <label class="form-label fw-semibold">Middle Name</label>
                <input type="text" class="form-control bg-light" value="<?= sanitize($faculty['middle_name'] ?? '—') ?>" disabled>
            </div>
            <div class="col-md-4">
                <label class="form-label fw-semibold">Last Name</label>
                <input type="text" class="form-control bg-light" value="<?= sanitize($faculty['last_name'] ?? '') ?>" disabled>
            </div>            <div class="col-md-4">
                <label class="form-label fw-semibold">Employee ID</label>
                <input type="text" class="form-control bg-light" value="<?= sanitize($faculty['employee_id'] ?? '') ?>" disabled>
            </div>
            <div class="col-md-4">
                <label class="form-label fw-semibold">Campus</label>
                <input type="text" class="form-control bg-light" value="<?= sanitize($faculty['campus_name'] ?? '') ?>" disabled>
            </div>
            <div class="col-md-4">
                <label class="form-label fw-semibold">Current Faculty Rank</label>
                <input type="text" class="form-control bg-light" value="<?= sanitize($faculty['rank'] ?? '') ?>" disabled>
            </div>
        </div>

        <!-- Application Details -->
        <div class="row g-3 mb-4">
        </div>

        <div class="alert alert-info py-2 small mb-3">
            <i class="bi bi-info-circle me-1"></i>Please verify all information is correct before saving and continuing to Step 2.
        </div>

        <?php if (!$locked): ?>
        <button type="submit" class="btn btn-primary px-4">
            <i class="bi bi-arrow-right me-2"></i>Save & Continue to Step 2
        </button>
        <?php else: ?>
        <div class="alert alert-warning py-2 small mb-3">
            <i class="bi bi-lock me-1"></i>Application submitted &mdash; info is locked.
        </div>
        <a href="index.php?page=apply&step=2" class="btn btn-primary px-4">
            <i class="bi bi-arrow-right me-2"></i>Go to Step 2
        </a>
        <?php endif; ?>
    </form>
</div>

<script>
</script>

