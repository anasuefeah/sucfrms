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

    $dir = __DIR__ . '/../../uploads/avatars';
    if (!is_dir($dir)) mkdir($dir, 0755, true);
    $filename = 'avatar_' . $uid . '_' . time() . '.' . $allowed[$mime];
    $target = $dir . DIRECTORY_SEPARATOR . $filename;
    if (!move_uploaded_file($file['tmp_name'], $target)) return [false, $old_path, 'Could not save the profile photo.'];

    if ($old_path) {
        $old_abs = realpath(__DIR__ . '/../../') . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $old_path);
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
?>

<div style="background:#f8fafc;border:1px solid #e2e8f0;border-left:4px solid #1e4d8c;border-radius:8px;padding:0.75rem 1rem;margin-bottom:1rem;color:#475569;font-size:0.86rem;">
    <i class="bi bi-lock-fill me-1" style="color:#1e4d8c;"></i>Please complete your profile to continue.
</div>

<?php if ($errors): ?>
<div style="background:#fef2f2;border:1px solid #fecaca;border-radius:8px;padding:0.8rem 1rem;margin-bottom:1rem;color:#dc2626;font-size:0.84rem;">
    <strong>Please fix the following:</strong>
    <ul style="margin:0.35rem 0 0;padding-left:1.2rem;">
        <?php foreach ($errors as $err): ?><li><?= htmlspecialchars($err) ?></li><?php endforeach; ?>
    </ul>
</div>
<?php endif; ?>

<form method="POST" enctype="multipart/form-data" class="neon-card" style="padding:1.2rem;">
    <input type="hidden" name="csrf" value="<?= htmlspecialchars($_SESSION['profile_entry_csrf']) ?>">

    <div style="display:flex;align-items:center;gap:0.65rem;margin-bottom:1rem;">
        <div style="width:38px;height:38px;border-radius:8px;background:#1e4d8c;display:flex;align-items:center;justify-content:center;color:#fff;"><i class="bi bi-person-lines-fill"></i></div>
        <div>
            <div style="font-weight:700;color:#1a3a6b;font-size:0.98rem;">Profile Entry</div>
            <div style="font-size:0.74rem;color:#94a3b8;">Your email is locked to the account created by the Administrator.</div>
        </div>
    </div>

    <section style="border-top:1px solid #e8edf5;padding-top:1rem;margin-top:0.5rem;">
        <div style="font-size:0.72rem;font-weight:700;color:#475569;text-transform:uppercase;letter-spacing:0.06em;margin-bottom:0.75rem;">A. Personal / Account</div>
        <div class="row g-3">
            <div class="col-md-3">
                <label class="form-label">Profile Photo</label>
                <input type="file" name="profile_pic" class="form-control" accept=".jpg,.jpeg,.png,.webp">
                <div style="font-size:0.72rem;color:#94a3b8;margin-top:4px;">JPG, PNG, WEBP. Max 2 MB.</div>
            </div>
            <div class="col-md-3"><label class="form-label">First Name *</label><input type="text" name="first_name" class="form-control" value="<?= $val('first_name') ?>" required></div>
            <div class="col-md-3">
                <label class="form-label">Middle Name</label>
                <input type="text" name="middle_name" id="peMiddle" class="form-control" value="<?= $val('middle_name') ?>">
                <label style="font-size:0.75rem;color:#475569;margin-top:0.4rem;display:flex;gap:0.35rem;align-items:center;"><input type="checkbox" name="no_middle_name" onchange="document.getElementById('peMiddle').disabled=this.checked"> No middle name</label>
            </div>
            <div class="col-md-3"><label class="form-label">Last Name *</label><input type="text" name="last_name" class="form-control" value="<?= $val('last_name') ?>" required></div>
            <div class="col-md-2"><label class="form-label">Suffix</label><input type="text" name="suffix" class="form-control" value="<?= $val('suffix') ?>"></div>
            <div class="col-md-4">
                <label class="form-label">Email</label>
                <div class="input-group">
                    <span class="input-group-text"><i class="bi bi-lock-fill"></i></span>
                    <input type="email" class="form-control" value="<?= htmlspecialchars($user['email']) ?>" readonly>
                </div>
                <div style="font-size:0.72rem;color:#94a3b8;margin-top:4px;">Email can only be changed by the Administrator.</div>
            </div>
            <div class="col-md-3"><label class="form-label">Employee ID *</label><input type="text" name="employee_id" class="form-control" value="<?= $val('employee_id') ?>" required></div>
            <div class="col-md-3">
                <label class="form-label">Campus *</label>
                <select name="campus_id" class="form-select" required>
                    <option value="">Select campus</option>
                    <?php foreach ($campuses as $c): ?><option value="<?= (int)$c['campus_id'] ?>" <?= $selected('campus_id', $c['campus_id']) ?>><?= htmlspecialchars($c['campus_name']) ?></option><?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-4">
                <label class="form-label">Faculty Rank *</label>
                <select name="rank" class="form-select" required>
                    <option value="">Select rank</option>
                    <?php foreach (facultyRanks() as $r): ?><option value="<?= htmlspecialchars($r) ?>" <?= $selected('rank', $r) ?>><?= htmlspecialchars($r) ?></option><?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-3">
                <label class="form-label">Status *</label>
                <select name="faculty_status" class="form-select" required>
                    <option value="Existing Faculty" <?= $selected('faculty_status', 'Existing Faculty', 'New Faculty') ?>>Existing Faculty</option>
                    <option value="New Faculty" <?= $selected('faculty_status', 'New Faculty', 'New Faculty') ?>>New Faculty</option>
                </select>
            </div>
        </div>
    </section>

    <section style="border-top:1px solid #e8edf5;padding-top:1rem;margin-top:1rem;">
        <div style="font-size:0.72rem;font-weight:700;color:#475569;text-transform:uppercase;letter-spacing:0.06em;margin-bottom:0.75rem;">B. Educational Attainment</div>
        <div class="edu-fixed-list" style="display:flex;flex-direction:column;gap:0.75rem;">
            <?php
            $edu_levels = [
                'Bachelor' => ['Undergrad', true],
                'Master' => ['Masters', false],
                'Doctorate' => ['Doctorate', false],
                'PostDoctorate' => ['Post-doctorate', false],
            ];
            foreach ($edu_levels as $level_value => [$level_label, $is_required]):
                $idx = array_search($level_value, $_POST['edu_level'] ?? [], true);
                $degree_value = $idx !== false ? ($_POST['edu_degree'][$idx] ?? '') : '';
                $school_value = $idx !== false ? ($_POST['edu_school'][$idx] ?? '') : '';
                $year_value = $idx !== false ? ($_POST['edu_year'][$idx] ?? '') : '';
            ?>
            <div class="edu-fixed-row" style="background:#f8fafc;border:1px solid #e8edf5;border-radius:8px;padding:0.85rem;">
                <input type="hidden" name="edu_level[]" value="<?= htmlspecialchars($level_value) ?>">
                <div style="font-size:0.82rem;font-weight:800;color:#1a3a6b;margin-bottom:0.6rem;"><?= htmlspecialchars($level_label) ?></div>
                <div style="display:grid;grid-template-columns:1.4fr 1.4fr 0.8fr;gap:0.65rem;">
                    <div>
                        <label class="form-label">Name of Degree<?= $is_required ? ' *' : '' ?></label>
                        <input name="edu_degree[]" class="form-control" value="<?= htmlspecialchars($degree_value) ?>" placeholder="<?= $is_required ? 'Bachelor of Science in...' : 'N/A if none' ?>" <?= $is_required ? 'required' : '' ?>>
                    </div>
                    <div>
                        <label class="form-label">Name of SUC<?= $is_required ? ' *' : '' ?></label>
                        <input name="edu_school[]" class="form-control" value="<?= htmlspecialchars($school_value) ?>" placeholder="<?= $is_required ? 'School / University' : 'N/A if none' ?>" <?= $is_required ? 'required' : '' ?>>
                    </div>
                    <div>
                        <label class="form-label">Year Graduated<?= $is_required ? ' *' : '' ?></label>
                        <input name="edu_year[]" class="form-control" value="<?= htmlspecialchars($year_value) ?>" placeholder="<?= $is_required ? 'YYYY' : 'N/A' ?>" <?= $is_required ? 'required' : '' ?>>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        <div style="margin-top:0.85rem;background:#f8fafc;border:1px solid #e8edf5;border-radius:8px;padding:0.75rem 0.85rem;">
            <label class="form-label" style="margin-bottom:0.35rem;">Applied during 1st Cycle *</label>
            <select name="applied_first_cycle" class="form-select" style="max-width:220px;" required>
                <option value="0" <?= (($_POST['applied_first_cycle'] ?? '0') === '0') ? 'selected' : '' ?>>NO</option>
                <option value="1" <?= (($_POST['applied_first_cycle'] ?? '0') === '1') ? 'selected' : '' ?>>YES</option>
            </select>
        </div>
    </section>

    <section style="border-top:1px solid #e8edf5;padding-top:1rem;margin-top:1rem;">
        <div style="font-size:0.72rem;font-weight:700;color:#475569;text-transform:uppercase;letter-spacing:0.06em;margin-bottom:0.75rem;">C. Employment History</div>
        <div class="row g-3">
            <div class="col-lg-6">
                <div style="background:#f8fafc;border:1px solid #e8edf5;border-radius:8px;padding:1rem;">
                    <div style="font-size:0.68rem;font-weight:700;color:#94a3b8;text-transform:uppercase;letter-spacing:0.08em;margin-bottom:0.75rem;">Current Employment</div>
                    <div class="row g-2">
                        <div class="col-md-6"><input name="current_rank" class="form-control" placeholder="Faculty Rank" value="<?= htmlspecialchars($old['current_rank'] ?? ($user['rank'] ?? '')) ?>" required></div>
                        <div class="col-md-6"><input name="current_mode" class="form-control" placeholder="Mode of Appointment" required></div>
                        <div class="col-md-6"><input type="date" name="current_date" class="form-control" required></div>
                        <div class="col-md-6"><input name="current_suc" class="form-control" placeholder="SUC" value="Carlos Hilado Memorial State University (CHMSU)" required></div>
                        <div class="col-md-6"><input name="current_campus" class="form-control" placeholder="Campus" value="<?= htmlspecialchars($campus_map[(int)($user['campus_id'] ?? 0)] ?? '') ?>" required></div>
                        <div class="col-md-6"><input name="current_address" class="form-control" placeholder="Address" required></div>
                    </div>
                </div>
            </div>
            <div class="col-lg-6">
                <div style="background:#f8fafc;border:1px solid #e8edf5;border-radius:8px;padding:1rem;">
                    <div style="font-size:0.68rem;font-weight:700;color:#94a3b8;text-transform:uppercase;letter-spacing:0.08em;margin-bottom:0.75rem;">Previous Employment</div>
                    <div class="row g-2">
                        <div class="col-md-6"><input name="previous_rank" class="form-control" placeholder="Previous Rank" required></div>
                        <div class="col-md-6"><input name="previous_mode" class="form-control" placeholder="Mode of Appointment" required></div>
                        <div class="col-md-6"><input type="date" name="previous_date" id="prevDate" class="form-control"><label style="font-size:0.75rem;color:#475569;margin-top:0.35rem;display:flex;gap:0.35rem;"><input type="checkbox" name="previous_date_na" onchange="document.getElementById('prevDate').disabled=this.checked"> N/A</label></div>
                        <div class="col-md-6"><input name="previous_sector" class="form-control" placeholder="Employment Sector" required></div>
                        <div class="col-md-6"><input name="previous_suc" class="form-control" placeholder="SUC" required></div>
                        <div class="col-md-6"><input name="previous_campus" class="form-control" placeholder="Campus" required></div>
                        <div class="col-12"><input name="previous_address" class="form-control" placeholder="Address" required></div>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <div style="border-top:1px solid #e8edf5;margin-top:1rem;padding-top:1rem;">
        <label style="display:flex;gap:0.5rem;align-items:flex-start;color:#475569;font-size:0.84rem;">
            <input type="checkbox" name="privacy_ack" required style="margin-top:0.2rem;">
            I acknowledge that the information I provide will be used for faculty reclassification processing and account administration.
        </label>
    </div>

    <div style="display:flex;justify-content:flex-end;margin-top:1rem;">
        <button type="submit" class="btn btn-primary"><i class="bi bi-check2-circle me-1"></i>Save Profile</button>
    </div>
</form>

<style>
@media (max-width: 768px) {
    .edu-fixed-row > div:last-child { grid-template-columns:1fr !important; }
}
</style>
