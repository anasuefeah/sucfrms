<?php
if (!isFaculty()) { echo '<div class="alert alert-danger">Access denied.</div>'; return; }

$uid = (int)$_SESSION['user_id'];
$user_stmt = $pdo->prepare("SELECT * FROM users WHERE user_id=?");
$user_stmt->execute([$uid]);
$user = $user_stmt->fetch();

if (!$user) { echo '<div class="alert alert-danger">Account not found.</div>'; return; }

$campuses = $pdo->query("SELECT campus_id, campus_name FROM campuses WHERE is_active=1 ORDER BY campus_name")->fetchAll();
$campus_map = [];
foreach ($campuses as $c) $campus_map[(int)$c['campus_id']] = $c['campus_name'];

if (empty($_SESSION['profile_entry_csrf'])) {
    $_SESSION['profile_entry_csrf'] = bin2hex(random_bytes(24));
}

$errors = [];
$old = $_POST ?: [];

function pe_trim_array(array $values): array {
    return array_map(fn($v) => trim((string)$v), $values);
}

function pe_save_avatar(array $file, int $uid, string $old_path = ''): array {
    if (empty($file['name'])) return [true, $old_path, ''];
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) return [false, $old_path, 'Unable to upload the profile photo.'];
    if (($file['size'] ?? 0) > 2 * 1024 * 1024) return [false, $old_path, 'Profile photo must be 2 MB or smaller.'];

    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, ['jpg','jpeg','png','webp'], true)) return [false, $old_path, 'Profile photo must be JPG, PNG, or WEBP.'];

    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = $finfo->file($file['tmp_name']);
    $allowed = ['image/jpeg'=>'jpg', 'image/png'=>'png', 'image/webp'=>'webp'];
    if (!isset($allowed[$mime])) return [false, $old_path, 'Profile photo type is not allowed.'];

    $dir = __DIR__ . '/../../../uploads/avatars';
    if (!is_dir($dir)) mkdir($dir, 0755, true);
    $filename = 'avatar_' . $uid . '_' . time() . '.' . $allowed[$mime];
    $target = $dir . DIRECTORY_SEPARATOR . $filename;
    if (!move_uploaded_file($file['tmp_name'], $target)) return [false, $old_path, 'Could not save the profile photo.'];

    if ($old_path) {
        $old_abs = realpath(__DIR__ . '/../../../') . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $old_path);
        if (is_file($old_abs) && str_starts_with(realpath($old_abs), realpath($dir))) @unlink($old_abs);
    }
    return [true, 'uploads/avatars/' . $filename, ''];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals($_SESSION['profile_entry_csrf'] ?? '', $_POST['csrf'] ?? '')) {
        $errors[] = 'Your session token expired. Please try again.';
    }

    $first_name = trim($_POST['first_name'] ?? '');
    $middle_name = !empty($_POST['no_middle_name']) ? '' : trim($_POST['middle_name'] ?? '');
    $last_name = trim($_POST['last_name'] ?? '');
    $suffix = trim($_POST['suffix'] ?? '');
    $employee_id = trim($_POST['employee_id'] ?? '');
    $campus_id = (int)($_POST['campus_id'] ?? 0);
    $rank = trim($_POST['rank'] ?? '');
    $faculty_status = trim($_POST['faculty_status'] ?? '');

    if ($first_name === '') $errors[] = 'First name is required.';
    if ($last_name === '') $errors[] = 'Last name is required.';
    if ($employee_id === '') $errors[] = 'Employee ID is required.';
    if (!$campus_id || !isset($campus_map[$campus_id])) $errors[] = 'Please select a valid campus.';
    if (!in_array($rank, facultyRanks(), true)) $errors[] = 'Please select a valid faculty rank.';
    if (!in_array($faculty_status, ['Existing Faculty','New Faculty'], true)) $errors[] = 'Please select a valid faculty status.';

    if ($employee_id !== '') {
        $dupe = $pdo->prepare("SELECT user_id FROM users WHERE employee_id=? AND user_id<>?");
        $dupe->execute([$employee_id, $uid]);
        if ($dupe->fetch()) $errors[] = 'That Employee ID is already used by another account.';
    }

    $applied_first_cycle = ($_POST['applied_first_cycle'] ?? '') === '1' ? 1 : 0;
    $levels = pe_trim_array($_POST['edu_level'] ?? []);
    $degrees = pe_trim_array($_POST['edu_degree'] ?? []);
    $schools = pe_trim_array($_POST['edu_school'] ?? []);
    $years = pe_trim_array($_POST['edu_year'] ?? []);
    $education_rows = [];
    $has_undergrad = false;
    $allowed_edu_levels = ['Bachelor','Master','Doctorate','PostDoctorate'];
    foreach ($levels as $i => $level) {
        $degree = $degrees[$i] ?? '';
        $school = $schools[$i] ?? '';
        $year = $years[$i] ?? '';
        if ($level === '' && $degree === '' && $school === '' && $year === '') continue;
        if (!in_array($level, $allowed_edu_levels, true)) {
            $errors[] = 'Please use the provided education levels only.';
            break;
        }
        if ($level === 'Bachelor') {
            $has_undergrad = true;
            if ($degree === '' || $school === '' || $year === '') {
                $errors[] = 'Undergrad education must include name of degree, name of SUC, and year graduated.';
                break;
            }
        }
        $education_rows[] = [
            'level' => $level,
            'degree' => $degree !== '' ? $degree : 'N/A',
            'major' => 'N/A',
            'school' => $school !== '' ? $school : 'N/A',
            'year' => $year !== '' ? $year : 'N/A',
            'note' => null,
        ];
    }
    if (!$has_undergrad) $errors[] = 'Undergrad education is required.';

    $current = [
        'rank' => trim($_POST['current_rank'] ?? ''),
        'mode' => trim($_POST['current_mode'] ?? ''),
        'date' => trim($_POST['current_date'] ?? ''),
        'suc' => trim($_POST['current_suc'] ?? ''),
        'campus' => trim($_POST['current_campus'] ?? ''),
        'address' => trim($_POST['current_address'] ?? ''),
    ];
    foreach (['rank'=>'current faculty rank','mode'=>'current mode of appointment','date'=>'current date of appointment','suc'=>'current SUC','campus'=>'current campus','address'=>'current address'] as $key => $label) {
        if ($current[$key] === '') $errors[] = ucfirst($label) . ' is required.';
    }

    $previous = [
        'rank' => trim($_POST['previous_rank'] ?? ''),
        'mode' => trim($_POST['previous_mode'] ?? ''),
        'date' => !empty($_POST['previous_date_na']) ? '' : trim($_POST['previous_date'] ?? ''),
        'sector' => trim($_POST['previous_sector'] ?? ''),
        'suc' => trim($_POST['previous_suc'] ?? ''),
        'campus' => trim($_POST['previous_campus'] ?? ''),
        'address' => trim($_POST['previous_address'] ?? ''),
    ];
    foreach (['rank'=>'previous rank','mode'=>'previous mode of appointment','sector'=>'previous employment sector','suc'=>'previous SUC','campus'=>'previous campus','address'=>'previous address'] as $key => $label) {
        if ($previous[$key] === '') $errors[] = ucfirst($label) . ' is required.';
    }

    if (empty($_POST['privacy_ack'])) $errors[] = 'Please confirm the data privacy acknowledgment.';

    [$avatar_ok, $pic_path, $avatar_error] = pe_save_avatar($_FILES['profile_pic'] ?? [], $uid, $user['profile_pic'] ?? '');
    if (!$avatar_ok) $errors[] = $avatar_error;

    if (!$errors) {
        try {
            $pdo->beginTransaction();
            $pdo->prepare("UPDATE users
                SET first_name=?, middle_name=?, last_name=?, suffix=?, employee_id=?, campus_id=?, rank=?, faculty_status=?, applied_first_cycle=?, profile_pic=?, profile_completed=1, email_locked=1
                WHERE user_id=?")
                ->execute([$first_name, $middle_name ?: null, $last_name, $suffix ?: null, $employee_id, $campus_id, $rank, $faculty_status, $applied_first_cycle, $pic_path, $uid]);

            $pdo->prepare("DELETE FROM faculty_education WHERE user_id=?")->execute([$uid]);
            $edu_ins = $pdo->prepare("INSERT INTO faculty_education (user_id, level, degree_program, major_specialization, school_university, year_graduated, honors_units_notes) VALUES (?,?,?,?,?,?,?)");
            foreach ($education_rows as $row) {
                $edu_ins->execute([$uid, $row['level'], $row['degree'], $row['major'], $row['school'], $row['year'], $row['note']]);
            }

            $pdo->prepare("DELETE FROM faculty_employment WHERE user_id=?")->execute([$uid]);
            $emp_ins = $pdo->prepare("INSERT INTO faculty_employment (user_id, type, rank, mode_of_appointment, date_of_appointment, employment_sector, suc, campus, address) VALUES (?,?,?,?,?,?,?,?,?)");
            $emp_ins->execute([$uid, 'current', $current['rank'], $current['mode'], $current['date'] ?: null, null, $current['suc'], $current['campus'], $current['address']]);
            $emp_ins->execute([$uid, 'previous', $previous['rank'], $previous['mode'], $previous['date'] ?: null, $previous['sector'], $previous['suc'], $previous['campus'], $previous['address']]);

            logAudit($pdo, $uid, 'Profile Completed', 'Faculty completed first-login profile entry.');
            $pdo->commit();

            $_SESSION['first_name'] = $first_name;
            $_SESSION['middle_name'] = $middle_name;
            $_SESSION['last_name'] = $last_name;
            $_SESSION['full_name'] = formatDisplayName(['first_name'=>$first_name,'middle_name'=>$middle_name,'last_name'=>$last_name]);
            $_SESSION['profile_pic'] = $pic_path;
            flashMessage('success', 'Profile completed successfully.');
            echo "<script>window.location.href='index.php?page=dashboard';</script>";
            exit;
        } catch (\Exception $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $errors[] = 'Could not save your profile. Please review the form and try again.';
        }
    }
}

$val = fn($key, $fallback = '') => htmlspecialchars($old[$key] ?? ($user[$key] ?? $fallback), ENT_QUOTES, 'UTF-8');
$selected = fn($key, $value, $fallback = '') => (($old[$key] ?? ($user[$key] ?? $fallback)) == $value) ? 'selected' : '';

// If there were errors, remember which step failed so we can return to it
$error_step = 1;
if ($errors) {
    $last_err = end($errors);
    $step2_keywords = ['Undergrad','education','degree','SUC','Year','graduated','1st Cycle'];
    $step3_keywords = ['rank','appointment','campus','address','previous','current','employment','Privacy','privacy'];
    foreach ($step3_keywords as $kw) {
        if (str_contains($last_err, $kw) || str_contains(implode(' ', $errors), $kw)) { $error_step = 3; break; }
    }
    if ($error_step === 1) foreach ($step2_keywords as $kw) {
        if (str_contains($last_err, $kw) || str_contains(implode(' ', $errors), $kw)) { $error_step = 2; break; }
    }
}
?>

<div style="background:#f8fafc;border:1px solid #e2e8f0;border-left:4px solid #1e4d8c;border-radius:8px;padding:0.65rem 1rem;margin-bottom:1.25rem;color:#475569;font-size:0.84rem;">
    <i class="bi bi-lock-fill me-1" style="color:#1e4d8c;"></i>Complete all three steps to unlock your account.
</div>

<?php if ($errors): ?>
<div style="background:#fef2f2;border:1px solid #fecaca;border-radius:8px;padding:0.8rem 1rem;margin-bottom:1rem;color:#dc2626;font-size:0.84rem;">
    <strong>Please fix the following:</strong>
    <ul style="margin:0.35rem 0 0;padding-left:1.2rem;">
        <?php foreach ($errors as $err): ?><li><?= htmlspecialchars($err) ?></li><?php endforeach; ?>
    </ul>
</div>
<?php endif; ?>

<!-- ── STEP INDICATOR ───────────────────────────────────────── -->
<div style="display:flex;align-items:center;gap:0;margin-bottom:1.5rem;" id="stepIndicator">
    <?php
    $steps = ['Personal & Account', 'Education', 'Employment', 'Review'];
    foreach ($steps as $si => $slabel):
        $sn = $si + 1;
    ?>
    <div style="display:flex;align-items:center;flex:1;min-width:0;" id="stepWrap<?= $sn ?>">
        <div style="display:flex;flex-direction:column;align-items:center;flex:1;min-width:0;">
            <div id="stepCircle<?= $sn ?>"
                 style="width:32px;height:32px;border-radius:50%;display:flex;align-items:center;justify-content:center;
                        font-size:0.78rem;font-weight:700;flex-shrink:0;transition:all 0.2s;
                        background:#e2e8f0;color:#94a3b8;border:2px solid #e2e8f0;">
                <?= $sn ?>
            </div>
            <div id="stepLabel<?= $sn ?>"
                 style="font-size:0.68rem;font-weight:600;margin-top:4px;color:#94a3b8;
                        white-space:nowrap;transition:color 0.2s;">
                <?= $slabel ?>
            </div>
        </div>
        <?php if ($sn < count($steps)): ?>
        <div id="stepLine<?= $sn ?>"
             style="flex:1;height:2px;background:#e2e8f0;margin:0 4px;margin-bottom:22px;transition:background 0.2s;max-width:80px;"></div>
        <?php endif; ?>
    </div>
    <?php endforeach; ?>
</div>

<form method="POST" enctype="multipart/form-data" id="profileWizard">
<input type="hidden" name="csrf" value="<?= htmlspecialchars($_SESSION['profile_entry_csrf']) ?>">

<!-- ══════════════════════════════════════════════
     STEP 1 — Personal & Account
══════════════════════════════════════════════ -->
<div id="step1" class="wizard-step neon-card" style="padding:1.25rem 1.5rem;">
    <div style="font-size:0.7rem;font-weight:700;text-transform:uppercase;letter-spacing:0.07em;
                color:#94a3b8;margin-bottom:1rem;padding-bottom:0.5rem;border-bottom:1px solid #f1f5f9;">
        Personal &amp; Account
    </div>
    <div class="row g-3">
        <div class="col-md-3">
            <label class="form-label">Profile Photo</label>
            <input type="file" name="profile_pic" class="form-control" accept=".jpg,.jpeg,.png,.webp">
            <div style="font-size:0.72rem;color:#94a3b8;margin-top:4px;">JPG, PNG, WEBP. Max 2 MB.</div>
        </div>
        <div class="col-md-3">
            <label class="form-label">First Name <span class="text-danger">*</span></label>
            <input type="text" name="first_name" class="form-control" value="<?= $val('first_name') ?>" required>
        </div>
        <div class="col-md-3">
            <label class="form-label">Middle Name</label>
            <input type="text" name="middle_name" id="peMiddle" class="form-control" value="<?= $val('middle_name') ?>">
            <label style="font-size:0.74rem;color:#475569;margin-top:4px;display:flex;gap:0.35rem;align-items:center;cursor:pointer;">
                <input type="checkbox" name="no_middle_name" onchange="document.getElementById('peMiddle').disabled=this.checked"> No middle name
            </label>
        </div>
        <div class="col-md-3">
            <label class="form-label">Last Name <span class="text-danger">*</span></label>
            <input type="text" name="last_name" class="form-control" value="<?= $val('last_name') ?>" required>
        </div>
        <div class="col-md-2">
            <label class="form-label">Suffix</label>
            <input type="text" name="suffix" class="form-control" value="<?= $val('suffix') ?>">
        </div>
        <div class="col-md-4">
            <label class="form-label">Email</label>
            <div class="input-group">
                <span class="input-group-text"><i class="bi bi-lock-fill"></i></span>
                <input type="email" class="form-control" value="<?= htmlspecialchars($user['email']) ?>" readonly>
            </div>
            <div style="font-size:0.72rem;color:#94a3b8;margin-top:4px;">Email can only be changed by the Administrator.</div>
        </div>
        <div class="col-md-3">
            <label class="form-label">Employee ID <span class="text-danger">*</span></label>
            <input type="text" name="employee_id" class="form-control" value="<?= $val('employee_id') ?>" required>
        </div>
        <div class="col-md-3">
            <label class="form-label">Campus <span class="text-danger">*</span></label>
            <select name="campus_id" class="form-select" required>
                <option value="">Select campus</option>
                <?php foreach ($campuses as $c): ?>
                <option value="<?= (int)$c['campus_id'] ?>" <?= $selected('campus_id', $c['campus_id']) ?>><?= htmlspecialchars($c['campus_name']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-4">
            <label class="form-label">Faculty Rank <span class="text-danger">*</span></label>
            <select name="rank" class="form-select" required>
                <option value="">Select rank</option>
                <?php foreach (facultyRanks() as $r): ?>
                <option value="<?= htmlspecialchars($r) ?>" <?= $selected('rank', $r) ?>><?= htmlspecialchars($r) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-3">
            <label class="form-label">Status <span class="text-danger">*</span></label>
            <select name="faculty_status" class="form-select" required>
                <option value="Existing Faculty" <?= $selected('faculty_status', 'Existing Faculty', 'New Faculty') ?>>Existing Faculty</option>
                <option value="New Faculty" <?= $selected('faculty_status', 'New Faculty', 'New Faculty') ?>>New Faculty</option>
            </select>
        </div>
    </div>

    <div style="display:flex;justify-content:flex-end;padding-top:1rem;margin-top:0.5rem;border-top:1px solid #f1f5f9;">
        <button type="button" class="btn btn-primary"
                onclick="goStep(2)"
                style="background:#1a3a6b;border-color:#1a3a6b;font-size:0.85rem;padding:0.5rem 1.4rem;">
            Continue &rarr;
        </button>
    </div>
</div>

<!-- ══════════════════════════════════════════════
     STEP 2 — Educational Attainment
══════════════════════════════════════════════ -->
<div id="step2" class="wizard-step neon-card" style="padding:1.25rem 1.5rem;display:none;">
    <div style="font-size:0.7rem;font-weight:700;text-transform:uppercase;letter-spacing:0.07em;
                color:#94a3b8;margin-bottom:1rem;padding-bottom:0.5rem;border-bottom:1px solid #f1f5f9;">
        Educational Attainment
    </div>
    <div style="display:flex;flex-direction:column;gap:0.75rem;">
        <?php
        $edu_levels = [
            'Bachelor'      => ['Undergrad',       true],
            'Master'        => ['Masters',          false],
            'Doctorate'     => ['Doctorate',        false],
            'PostDoctorate' => ['Post-doctorate',   false],
        ];
        foreach ($edu_levels as $level_value => [$level_label, $is_required]):
            $idx = array_search($level_value, $_POST['edu_level'] ?? [], true);
            $degree_value = $idx !== false ? ($_POST['edu_degree'][$idx] ?? '') : '';
            $school_value = $idx !== false ? ($_POST['edu_school'][$idx] ?? '') : '';
            $year_value   = $idx !== false ? ($_POST['edu_year'][$idx]   ?? '') : '';
        ?>
        <div style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:8px;padding:0.85rem;">
            <input type="hidden" name="edu_level[]" value="<?= htmlspecialchars($level_value) ?>">
            <div style="font-size:0.8rem;font-weight:700;color:#1a3a6b;margin-bottom:0.6rem;">
                <?= htmlspecialchars($level_label) ?>
                <?= $is_required ? '' : '<span style="font-size:0.68rem;color:#94a3b8;font-weight:400;margin-left:4px;">(optional — enter N/A if none)</span>' ?>
            </div>
            <div class="row g-2">
                <div class="col-md-5">
                    <label class="form-label" style="font-size:0.78rem;">Name of Degree<?= $is_required ? ' <span class="text-danger">*</span>' : '' ?></label>
                    <input name="edu_degree[]" class="form-control form-control-sm"
                           value="<?= htmlspecialchars($degree_value) ?>"
                           placeholder="<?= $is_required ? 'Bachelor of Science in...' : 'N/A if none' ?>"
                           <?= $is_required ? 'required' : '' ?>>
                </div>
                <div class="col-md-5">
                    <label class="form-label" style="font-size:0.78rem;">Name of SUC<?= $is_required ? ' <span class="text-danger">*</span>' : '' ?></label>
                    <input name="edu_school[]" class="form-control form-control-sm"
                           value="<?= htmlspecialchars($school_value) ?>"
                           placeholder="<?= $is_required ? 'School / University' : 'N/A if none' ?>"
                           <?= $is_required ? 'required' : '' ?>>
                </div>
                <div class="col-md-2">
                    <label class="form-label" style="font-size:0.78rem;">Year<?= $is_required ? ' <span class="text-danger">*</span>' : '' ?></label>
                    <input name="edu_year[]" class="form-control form-control-sm"
                           value="<?= htmlspecialchars($year_value) ?>"
                           placeholder="<?= $is_required ? 'YYYY' : 'N/A' ?>"
                           <?= $is_required ? 'required' : '' ?>>
                </div>
            </div>
        </div>
        <?php endforeach; ?>
    </div>

    <div style="margin-top:0.85rem;background:#f8fafc;border:1px solid #e2e8f0;border-radius:8px;padding:0.75rem 0.85rem;">
        <label class="form-label" style="font-size:0.82rem;margin-bottom:0.35rem;">Applied during 1st Cycle <span class="text-danger">*</span></label>
        <select name="applied_first_cycle" class="form-select form-select-sm" style="max-width:220px;" required>
            <option value="0" <?= (($_POST['applied_first_cycle'] ?? '0') === '0') ? 'selected' : '' ?>>NO</option>
            <option value="1" <?= (($_POST['applied_first_cycle'] ?? '0') === '1') ? 'selected' : '' ?>>YES</option>
        </select>
    </div>

    <div style="display:flex;justify-content:space-between;padding-top:1rem;margin-top:0.5rem;border-top:1px solid #f1f5f9;">
        <button type="button" class="btn btn-outline-secondary"
                onclick="goStep(1)"
                style="font-size:0.85rem;padding:0.5rem 1.2rem;">
            &larr; Back
        </button>
        <button type="button" class="btn btn-primary"
                onclick="goStep(3)"
                style="background:#1a3a6b;border-color:#1a3a6b;font-size:0.85rem;padding:0.5rem 1.4rem;">
            Continue &rarr;
        </button>
    </div>
</div>

<!-- ══════════════════════════════════════════════
     STEP 3 — Employment History
══════════════════════════════════════════════ -->
<div id="step3" class="wizard-step neon-card" style="padding:1.25rem 1.5rem;display:none;">
    <div style="font-size:0.7rem;font-weight:700;text-transform:uppercase;letter-spacing:0.07em;
                color:#94a3b8;margin-bottom:1rem;padding-bottom:0.5rem;border-bottom:1px solid #f1f5f9;">
        Employment History
    </div>
    <div class="row g-3">
        <!-- Current Employment -->
        <div class="col-lg-6">
            <div style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:8px;padding:1rem;">
                <div style="font-size:0.68rem;font-weight:700;color:#94a3b8;text-transform:uppercase;letter-spacing:0.08em;margin-bottom:0.75rem;">Current Employment</div>
                <div class="row g-2">
                    <div class="col-md-6">
                        <label class="form-label" style="font-size:0.78rem;">Faculty Rank <span class="text-danger">*</span></label>
                        <input name="current_rank" class="form-control form-control-sm"
                               value="<?= htmlspecialchars($old['current_rank'] ?? ($user['rank'] ?? '')) ?>"
                               placeholder="Faculty Rank" required>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label" style="font-size:0.78rem;">Mode of Appointment <span class="text-danger">*</span></label>
                        <input name="current_mode" class="form-control form-control-sm"
                               value="<?= htmlspecialchars($old['current_mode'] ?? '') ?>"
                               placeholder="Permanent / Temporary" required>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label" style="font-size:0.78rem;">Date of Appointment <span class="text-danger">*</span></label>
                        <input type="date" name="current_date" class="form-control form-control-sm"
                               value="<?= htmlspecialchars($old['current_date'] ?? '') ?>" required>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label" style="font-size:0.78rem;">SUC <span class="text-danger">*</span></label>
                        <input name="current_suc" class="form-control form-control-sm"
                               value="<?= htmlspecialchars($old['current_suc'] ?? 'Carlos Hilado Memorial State University (CHMSU)') ?>" required>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label" style="font-size:0.78rem;">Campus <span class="text-danger">*</span></label>
                        <input name="current_campus" class="form-control form-control-sm"
                               value="<?= htmlspecialchars($old['current_campus'] ?? ($campus_map[(int)($user['campus_id'] ?? 0)] ?? '')) ?>" required>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label" style="font-size:0.78rem;">Address <span class="text-danger">*</span></label>
                        <input name="current_address" class="form-control form-control-sm"
                               value="<?= htmlspecialchars($old['current_address'] ?? '') ?>" required>
                    </div>
                </div>
            </div>
        </div>
        <!-- Previous Employment -->
        <div class="col-lg-6">
            <div style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:8px;padding:1rem;">
                <div style="font-size:0.68rem;font-weight:700;color:#94a3b8;text-transform:uppercase;letter-spacing:0.08em;margin-bottom:0.75rem;">Previous Employment</div>
                <div class="row g-2">
                    <div class="col-md-6">
                        <label class="form-label" style="font-size:0.78rem;">Previous Rank <span class="text-danger">*</span></label>
                        <input name="previous_rank" class="form-control form-control-sm"
                               value="<?= htmlspecialchars($old['previous_rank'] ?? '') ?>" placeholder="Previous Rank" required>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label" style="font-size:0.78rem;">Mode of Appointment <span class="text-danger">*</span></label>
                        <input name="previous_mode" class="form-control form-control-sm"
                               value="<?= htmlspecialchars($old['previous_mode'] ?? '') ?>" placeholder="Permanent / Temporary" required>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label" style="font-size:0.78rem;">Date of Appointment</label>
                        <input type="date" name="previous_date" id="prevDate" class="form-control form-control-sm"
                               value="<?= htmlspecialchars($old['previous_date'] ?? '') ?>">
                        <label style="font-size:0.72rem;color:#475569;margin-top:3px;display:flex;gap:0.35rem;cursor:pointer;">
                            <input type="checkbox" name="previous_date_na" <?= !empty($old['previous_date_na']) ? 'checked' : '' ?>
                                   onchange="document.getElementById('prevDate').disabled=this.checked"> N/A
                        </label>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label" style="font-size:0.78rem;">Employment Sector <span class="text-danger">*</span></label>
                        <input name="previous_sector" class="form-control form-control-sm"
                               value="<?= htmlspecialchars($old['previous_sector'] ?? '') ?>" placeholder="Gov't / Private" required>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label" style="font-size:0.78rem;">SUC <span class="text-danger">*</span></label>
                        <input name="previous_suc" class="form-control form-control-sm"
                               value="<?= htmlspecialchars($old['previous_suc'] ?? '') ?>" required>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label" style="font-size:0.78rem;">Campus <span class="text-danger">*</span></label>
                        <input name="previous_campus" class="form-control form-control-sm"
                               value="<?= htmlspecialchars($old['previous_campus'] ?? '') ?>" required>
                    </div>
                    <div class="col-12">
                        <label class="form-label" style="font-size:0.78rem;">Address <span class="text-danger">*</span></label>
                        <input name="previous_address" class="form-control form-control-sm"
                               value="<?= htmlspecialchars($old['previous_address'] ?? '') ?>" required>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Privacy acknowledgment moved to Step 4 review -->

    <div style="display:flex;justify-content:space-between;padding-top:1rem;margin-top:0.5rem;border-top:1px solid #f1f5f9;">
        <button type="button" class="btn btn-outline-secondary"
                onclick="goStep(2)"
                style="font-size:0.85rem;padding:0.5rem 1.2rem;">
            &larr; Back
        </button>
        <button type="button" class="btn btn-primary"
                onclick="goStep(4)"
                style="background:#1a3a6b;border-color:#1a3a6b;font-size:0.85rem;padding:0.5rem 1.4rem;">
            Review &rarr;
        </button>
    </div>
</div>

<!-- ══════════════════════════════════════════════
     STEP 4 — Review & Confirm
══════════════════════════════════════════════ -->
<div id="step4" class="wizard-step neon-card" style="padding:1.25rem 1.5rem;display:none;">
    <div style="font-size:0.7rem;font-weight:700;text-transform:uppercase;letter-spacing:0.07em;
                color:#94a3b8;margin-bottom:1rem;padding-bottom:0.5rem;border-bottom:1px solid #f1f5f9;">
        Review Your Information
    </div>
    <div style="font-size:0.78rem;color:#64748b;margin-bottom:1.25rem;">
        Check everything below before saving. Click <strong>Edit</strong> on any section to go back and make changes.
    </div>

    <!-- Review sections filled by JS -->
    <div id="rv_personal" class="rv-section" style="margin-bottom:1rem;"></div>
    <div id="rv_education" class="rv-section" style="margin-bottom:1rem;"></div>
    <div id="rv_employment" class="rv-section" style="margin-bottom:1rem;"></div>

    <!-- Privacy acknowledgment -->
    <div style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:8px;padding:0.75rem 0.9rem;margin-top:0.5rem;margin-bottom:1rem;">
        <label style="display:flex;gap:0.5rem;align-items:flex-start;color:#475569;font-size:0.84rem;cursor:pointer;">
            <input type="checkbox" name="privacy_ack" required style="margin-top:2px;flex-shrink:0;">
            I acknowledge that the information I provide will be used for faculty reclassification processing and account administration.
        </label>
    </div>

    <div style="display:flex;justify-content:space-between;padding-top:1rem;border-top:1px solid #f1f5f9;">
        <button type="button" class="btn btn-outline-secondary"
                onclick="goStep(3)"
                style="font-size:0.85rem;padding:0.5rem 1.2rem;">
            &larr; Back
        </button>
        <button type="submit"
                style="background:#1a3a6b;border:none;color:#fff;border-radius:8px;
                       font-size:0.85rem;font-weight:700;padding:0.5rem 1.6rem;cursor:pointer;">
            Confirm &amp; Save Profile
        </button>
    </div>
</div>

</form>

<script>
(function() {
    const TOTAL = 4;
    let current = <?= (int)$error_step ?>;

    function goStep(n) {
        if (n > current) {
            const activeStep = document.getElementById('step' + current);
            const inputs = activeStep.querySelectorAll('[required]');
            for (const inp of inputs) {
                if (!inp.checkValidity()) { inp.reportValidity(); return; }
            }
        }
        current = n;
        if (n === 3) syncStep1ToEmployment();
        if (n === 4) buildReview();
        renderStep();
    }

    function syncStep1ToEmployment() {
        const rankSel = document.querySelector('select[name="rank"]');
        const currentRankInput = document.querySelector('input[name="current_rank"]');
        if (rankSel && currentRankInput && currentRankInput.value === '') {
            const v = rankSel.options[rankSel.selectedIndex]?.value || '';
            if (v) currentRankInput.value = v;
        }
        const campusSel = document.querySelector('select[name="campus_id"]');
        const currentCampusInput = document.querySelector('input[name="current_campus"]');
        if (campusSel && currentCampusInput && currentCampusInput.value === '') {
            const t = campusSel.options[campusSel.selectedIndex]?.text || '';
            if (t && t !== 'Select campus') currentCampusInput.value = t;
        }
    }

    // ── helpers ──────────────────────────────────────────────
    function esc(s) {
        return String(s ?? '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
    }
    function field(label, value) {
        const v = (value ?? '').toString().trim();
        return `<div style="min-width:140px;flex:1;">
            <div style="font-size:0.65rem;font-weight:700;text-transform:uppercase;letter-spacing:0.05em;color:#94a3b8;margin-bottom:2px;">${esc(label)}</div>
            <div style="font-size:0.85rem;color:${v ? '#1e293b' : '#cbd5e1'};font-weight:${v ? '500' : '400'};">${v ? esc(v) : '—'}</div>
        </div>`;
    }
    function section(title, stepNum, rows) {
        return `<div style="border:1px solid #e2e8f0;border-radius:8px;overflow:hidden;margin-bottom:1rem;">
            <div style="display:flex;align-items:center;justify-content:space-between;
                        padding:0.55rem 0.9rem;background:#f8fafc;border-bottom:1px solid #f1f5f9;">
                <span style="font-size:0.7rem;font-weight:700;text-transform:uppercase;letter-spacing:0.07em;color:#475569;">${esc(title)}</span>
                <button type="button" onclick="goStep(${stepNum})"
                        style="background:none;border:1px solid #e2e8f0;border-radius:6px;
                               padding:2px 10px;font-size:0.72rem;font-weight:600;color:#1e4d8c;cursor:pointer;">
                    Edit
                </button>
            </div>
            <div style="padding:0.75rem 0.9rem;display:flex;flex-wrap:wrap;gap:0.75rem 1.25rem;">${rows}</div>
        </div>`;
    }

    function buildReview() {
        // ── Step 1 ─────────────────────────────────────────
        const firstName  = document.querySelector('input[name="first_name"]')?.value   || '';
        const middleName = document.querySelector('input[name="middle_name"]')?.value  || '';
        const lastName   = document.querySelector('input[name="last_name"]')?.value    || '';
        const suffix     = document.querySelector('input[name="suffix"]')?.value       || '';
        const empId      = document.querySelector('input[name="employee_id"]')?.value  || '';
        const rankSel    = document.querySelector('select[name="rank"]');
        const rank       = rankSel?.options[rankSel.selectedIndex]?.value || '';
        const campusSel  = document.querySelector('select[name="campus_id"]');
        const campus     = campusSel?.options[campusSel.selectedIndex]?.text || '';
        const statusSel  = document.querySelector('select[name="faculty_status"]');
        const status     = statusSel?.options[statusSel.selectedIndex]?.text || '';
        const emailEl    = document.querySelector('#step1 input[type="email"]');
        const email      = emailEl?.value || '';

        const fullName = [firstName, middleName, lastName, suffix].filter(Boolean).join(' ');

        let p1 = field('Full Name', fullName) + field('Email', email) +
                 field('Employee ID', empId) + field('Campus', campus) +
                 field('Faculty Rank', rank) + field('Status', status);
        document.getElementById('rv_personal').innerHTML = section('Personal & Account', 1, p1);

        // ── Step 2 ─────────────────────────────────────────
        const levels  = [...document.querySelectorAll('input[name="edu_level[]"]')].map(e => e.value);
        const degrees = [...document.querySelectorAll('input[name="edu_degree[]"]')].map(e => e.value);
        const schools = [...document.querySelectorAll('input[name="edu_school[]"]')].map(e => e.value);
        const years   = [...document.querySelectorAll('input[name="edu_year[]"]')].map(e => e.value);
        const labels  = {Bachelor:'Undergrad', Master:'Masters', Doctorate:'Doctorate', PostDoctorate:'Post-doctorate'};
        const cycleEl = document.querySelector('select[name="applied_first_cycle"]');
        const cycle   = cycleEl?.options[cycleEl.selectedIndex]?.text || '';

        let p2 = '';
        levels.forEach((lv, i) => {
            const lbl = labels[lv] || lv;
            const deg = degrees[i] || '—'; const sch = schools[i] || '—'; const yr = years[i] || '—';
            p2 += `<div style="width:100%;display:flex;flex-wrap:wrap;gap:0.5rem 1.25rem;padding-bottom:0.6rem;border-bottom:1px solid #f8fafc;margin-bottom:0.25rem;">
                <div style="width:100%;font-size:0.68rem;font-weight:700;color:#1a3a6b;">${esc(lbl)}</div>
                ${field('Degree', deg)}${field('School / SUC', sch)}${field('Year', yr)}
            </div>`;
        });
        p2 += field('Applied 1st Cycle', cycle);
        document.getElementById('rv_education').innerHTML = section('Education', 2, p2);

        // ── Step 3 ─────────────────────────────────────────
        const cv = n => document.querySelector(`input[name="${n}"]`)?.value || '';
        let p3  = `<div style="width:100%;font-size:0.68rem;font-weight:700;color:#1a3a6b;margin-bottom:0.25rem;">Current</div>`;
        p3 += field('Rank', cv('current_rank')) + field('Mode', cv('current_mode')) +
              field('Date', cv('current_date')) + field('SUC', cv('current_suc')) +
              field('Campus', cv('current_campus')) + field('Address', cv('current_address'));
        p3 += `<div style="width:100%;border-top:1px solid #f1f5f9;margin:0.4rem 0;"></div>
               <div style="width:100%;font-size:0.68rem;font-weight:700;color:#1a3a6b;margin-bottom:0.25rem;">Previous</div>`;
        const prevDateNA = document.querySelector('input[name="previous_date_na"]')?.checked;
        p3 += field('Rank', cv('previous_rank')) + field('Mode', cv('previous_mode')) +
              field('Date', prevDateNA ? 'N/A' : cv('previous_date')) +
              field('Sector', cv('previous_sector')) + field('SUC', cv('previous_suc')) +
              field('Campus', cv('previous_campus')) + field('Address', cv('previous_address'));
        document.getElementById('rv_employment').innerHTML = section('Employment', 3, p3);
    }

    function renderStep() {
        for (let i = 1; i <= TOTAL; i++) {
            const el = document.getElementById('step' + i);
            if (el) el.style.display = i === current ? 'block' : 'none';

            const circle  = document.getElementById('stepCircle' + i);
            const label   = document.getElementById('stepLabel' + i);
            const line    = document.getElementById('stepLine' + i);
            if (!circle) continue;

            if (i < current) {
                circle.style.background  = '#1a3a6b';
                circle.style.color       = '#fff';
                circle.style.borderColor = '#1a3a6b';
                circle.innerHTML = '<i class="bi bi-check-lg" style="font-size:0.75rem;"></i>';
                label.style.color = '#1a3a6b';
                if (line) line.style.background = '#1a3a6b';
            } else if (i === current) {
                circle.style.background  = '#1e4d8c';
                circle.style.color       = '#fff';
                circle.style.borderColor = '#1e4d8c';
                circle.innerHTML = String(i);
                label.style.color      = '#1a3a6b';
                label.style.fontWeight = '700';
                if (line) line.style.background = '#e2e8f0';
            } else {
                circle.style.background  = '#f1f5f9';
                circle.style.color       = '#94a3b8';
                circle.style.borderColor = '#e2e8f0';
                circle.innerHTML = String(i);
                label.style.color      = '#94a3b8';
                label.style.fontWeight = '600';
                if (line) line.style.background = '#e2e8f0';
            }
        }
        window.scrollTo({ top: 0, behavior: 'smooth' });
    }

    window.goStep = goStep;
    renderStep();
})();
</script>

<style>
.wizard-step { transition: none; }
@media (max-width: 768px) {
    #stepIndicator { gap: 0; }
    #stepIndicator [id^="stepLine"] { max-width: 30px !important; }
}
</style>
