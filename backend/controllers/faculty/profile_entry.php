<?php
// Request handling and data loading for frontend/views/faculty/profile_entry.php
if (!isFaculty()) { echo '<div class="alert alert-danger">Access denied.</div>'; return false; }

$uid = (int)$_SESSION['user_id'];
$user_stmt = $pdo->prepare("SELECT * FROM users WHERE user_id=?");
$user_stmt->execute([$uid]);
$user = $user_stmt->fetch();

if (!$user) { echo '<div class="alert alert-danger">Account not found.</div>'; return false; }

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
