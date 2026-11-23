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
                SET first_name=?, middle_name=?, last_name=?, suffix=?, employee_id=?, campus_id=?, `rank`=?, faculty_status=?, applied_first_cycle=?, profile_pic=?, profile_completed=1, email_locked=1
                WHERE user_id=?")
                ->execute([$first_name, $middle_name ?: null, $last_name, $suffix ?: null, $employee_id, $campus_id, $rank, $faculty_status, $applied_first_cycle, $pic_path, $uid]);

            $pdo->prepare("DELETE FROM faculty_education WHERE user_id=?")->execute([$uid]);
            $edu_ins = $pdo->prepare("INSERT INTO faculty_education (user_id, level, degree_program, major_specialization, school_university, year_graduated, honors_units_notes) VALUES (?,?,?,?,?,?,?)");
            foreach ($education_rows as $row) {
                $edu_ins->execute([$uid, $row['level'], $row['degree'], $row['major'], $row['school'], $row['year'], $row['note']]);
            }

            $pdo->prepare("DELETE FROM faculty_employment WHERE user_id=?")->execute([$uid]);
            $emp_ins = $pdo->prepare("INSERT INTO faculty_employment (user_id, type, `rank`, mode_of_appointment, date_of_appointment, employment_sector, suc, campus, address) VALUES (?,?,?,?,?,?,?,?,?)");
            $emp_ins->execute([$uid, 'current', $current['rank'], $current['mode'], $current['date'] ?: null, null, $current['suc'], $current['campus'], $current['address']]);
            $emp_ins->execute([$uid, 'previous', $previous['rank'], $previous['mode'], $previous['date'] ?: null, $previous['sector'], $previous['suc'], $previous['campus'], $previous['address']]);

            logAudit($pdo, $uid, 'Profile Completed', 'Faculty completed first-login profile entry.');
            $pdo->commit();

            $_SESSION['first_name'] = $first_name;
            $_SESSION['middle_name'] = $middle_name;
            $_SESSION['last_name'] = $last_name;
            $_SESSION['full_name'] = formatDisplayName(['first_name'=>$first_name,'middle_name'=>$middle_name,'last_name'=>$last_name]);
            $_SESSION['profile_pic'] = $pic_path;
            $_SESSION['show_welcome'] = true;
            flashMessage('success', 'Profile completed successfully.');
            echo "<script>window.location.href='pages/portal.php';</script>";
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

<div class="profile-setup-intro" style="background:#fff;border:1px solid #e5e7eb;padding:0 1.1rem;margin-bottom:1.25rem;
            display:flex;align-items:center;justify-content:space-between;min-height:58px;gap:1rem;">
    <div class="profile-setup-intro-main" style="display:flex;align-items:center;gap:0.75rem;">
        <div style="width:36px;height:36px;background:#1a3a6b;display:flex;align-items:center;justify-content:center;flex-shrink:0;">
            <i class="bi bi-person-lines-fill" style="color:#fff;font-size:0.9rem;"></i>
        </div>
        <div>
            <div style="font-weight:700;color:#1a3a6b;font-size:0.95rem;line-height:1.2;">Profile Setup</div>
            <div class="profile-setup-intro-copy" style="font-size:0.72rem;color:#9ca3af;margin-top:1px;">
                Complete all steps to unlock your account and begin your reclassification application.
                <a href="#" onclick="document.getElementById('peInfoModal').style.display='flex';return false;"
                   style="color:#1a3a6b;font-weight:600;text-decoration:underline;margin-left:3px;">Why do we need this?</a>
            </div>
        </div>
    </div>
    <div style="display:flex;align-items:center;gap:0.4rem;border:1px solid #e5e7eb;background:#fff;padding:0.2rem 0.65rem;flex-shrink:0;">
        <i class="bi bi-lock" style="color:#6b7280;font-size:0.65rem;"></i>
        <span style="font-size:0.68rem;font-weight:700;color:#374151;white-space:nowrap;letter-spacing:0.04em;">3 STEPS</span>
    </div>
</div>

<?php if ($errors): ?>
<div style="border:1px solid #e2e8f0;border-left:3px solid #dc2626;padding:0.7rem 1rem;margin-bottom:1rem;background:#fff;">
    <div style="font-size:0.78rem;font-weight:700;color:#dc2626;margin-bottom:0.3rem;">Please fix the following:</div>
    <ul style="margin:0;padding-left:1.1rem;font-size:0.78rem;color:#475569;">
        <?php foreach ($errors as $err): ?><li><?= htmlspecialchars($err) ?></li><?php endforeach; ?>
    </ul>
</div>
<?php endif; ?>

<!-- STEP INDICATOR -->
<div style="display:grid;grid-template-columns:repeat(4,1fr);margin-bottom:1.75rem;position:relative;" id="stepIndicator">
    <div style="position:absolute;top:14px;left:calc(12.5%);right:calc(12.5%);height:1px;background:#e5e7eb;z-index:0;" id="stepTrack"></div>
    <?php $steps = ['Personal & Account','Education','Employment','Review'];
    foreach ($steps as $si => $slabel): $sn = $si+1; ?>
    <div style="display:flex;flex-direction:column;align-items:center;position:relative;z-index:1;" id="stepWrap<?= $sn ?>">
        <div class="profile-setup-step-icon" id="stepCircle<?= $sn ?>"
             style="width:28px;height:28px;border-radius:50%;border:2px solid #d1d5db;display:flex;align-items:center;justify-content:center;
                    font-size:0.75rem;font-weight:700;color:#9ca3af;background:#fff;flex-shrink:0;">
            <?= $sn ?>
        </div>
        <div id="stepLabel<?= $sn ?>"
             style="font-size:0.65rem;font-weight:600;margin-top:5px;color:#9ca3af;text-align:center;white-space:nowrap;">
            <?= $slabel ?>
        </div>
    </div>
    <?php endforeach; ?>
</div><form method="POST" id="profileWizard">
<input type="hidden" name="csrf" value="<?= htmlspecialchars($_SESSION['profile_entry_csrf']) ?>">

<?php
/* shared label/input styles as PHP vars for DRY HTML */
$lbl = 'display:block;font-size:0.68rem;font-weight:700;text-transform:uppercase;letter-spacing:0.06em;color:#6b7280;margin-bottom:4px;';
$inp = 'width:100%;border:1px solid #e5e7eb;padding:0.38rem 0.55rem;font-size:0.82rem;color:#111827;background:#fff;outline:none;box-sizing:border-box;';
$req = '<span style="color:#374151;">*</span>';

function peBox(string $title): string {
    return '<div style="border:1px solid #e5e7eb;overflow:hidden;margin-bottom:0.65rem;">
        <div style="background:#f9fafb;border-bottom:1px solid #e5e7eb;padding:0.4rem 0.9rem;">
            <span style="font-size:0.65rem;font-weight:700;text-transform:uppercase;letter-spacing:0.08em;color:#374151;">'.$title.'</span>
        </div>
        <div style="padding:0.85rem 0.9rem;">';
}
function peBoxEnd(): string { return '</div></div>'; }
?>

<!-- STEP 1 -->
<div id="step1" class="wizard-step">

    <?= peBox('Identity') ?>
    <div class="profile-setup-grid" style="display:grid;grid-template-columns:1fr 1fr 1fr 90px;gap:0.65rem;align-items:start;">


        <div>
            <label style="<?= $lbl ?>">First Name <?= $req ?></label>
            <input type="text" name="first_name" value="<?= $val('first_name') ?>" required
                   style="<?= $inp ?>" onfocus="this.style.borderColor='#374151'" onblur="this.style.borderColor='#e5e7eb'">
        </div>
        <div>
            <label style="<?= $lbl ?>">Middle Name</label>
            <input type="text" name="middle_name" id="peMiddle" value="<?= $val('middle_name') ?>"
                   style="<?= $inp ?>" onfocus="this.style.borderColor='#374151'" onblur="this.style.borderColor='#e5e7eb'">
            <label style="font-size:0.7rem;color:#6b7280;margin-top:4px;display:flex;gap:4px;align-items:center;cursor:pointer;">
                <input type="checkbox" name="no_middle_name"
                       onchange="document.getElementById('peMiddle').disabled=this.checked;document.getElementById('peMiddle').style.background=this.checked?'#f9fafb':'#fff'">
                No middle name
            </label>
        </div>
        <div>
            <label style="<?= $lbl ?>">Last Name <?= $req ?></label>
            <input type="text" name="last_name" value="<?= $val('last_name') ?>" required
                   style="<?= $inp ?>" onfocus="this.style.borderColor='#374151'" onblur="this.style.borderColor='#e5e7eb'">
        </div>
        <div style="position:relative;">
            <label style="<?= $lbl ?>">Suffix</label>
            <select id="suffixSelect"
                    style="<?= $inp ?>cursor:pointer;"
                    onfocus="this.style.borderColor='#374151'" onblur="this.style.borderColor='#e5e7eb'"
                    onchange="handleSuffixChange(this)">
                <option value="">None</option>
                <?php
                $suffixes = ['Jr.','Sr.','II','III','IV','V','VI','Ph.D.','M.D.','Esq.','DDS','DVM','RN','CPA','Other'];
                $curSuffix = $val('suffix');
                $isOther = $curSuffix !== '' && !in_array($curSuffix, array_slice($suffixes, 0, -1));
                foreach ($suffixes as $sfx):
                    $sel = (!$isOther && $curSuffix === $sfx) ? 'selected' : ($isOther && $sfx === 'Other' ? 'selected' : '');
                ?>
                <option value="<?= htmlspecialchars($sfx) ?>" <?= $sel ?>><?= htmlspecialchars($sfx) ?></option>
                <?php endforeach; ?>
            </select>
            <input type="text" name="suffix" id="suffixInput"
                   value="<?= $val('suffix') ?>"
                   placeholder="Type suffix&hellip;"
                   style="<?= $inp ?>margin-top:0.35rem;<?= (!$isOther && $curSuffix === '' || !$isOther) ? 'display:none;' : '' ?>"
                   onfocus="this.style.borderColor='#374151'" onblur="this.style.borderColor='#e5e7eb'">
        </div>
    </div>
    <?= peBoxEnd() ?>

    <?= peBox('Account &amp; Placement') ?>
    <div class="profile-setup-grid" style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:0.65rem;">
        <div>
            <label style="<?= $lbl ?>">Email</label>
            <div style="display:flex;border:1px solid #e5e7eb;background:#f9fafb;">
                <div style="padding:0.38rem 0.55rem;border-right:1px solid #e5e7eb;display:flex;align-items:center;">
                    <i class="bi bi-lock" style="font-size:0.68rem;color:#9ca3af;"></i>
                </div>
                <input type="email" value="<?= htmlspecialchars($user['email']) ?>" readonly
                       style="flex:1;border:none;background:transparent;padding:0.38rem 0.55rem;font-size:0.82rem;color:#9ca3af;outline:none;">
            </div>
            <div style="font-size:0.62rem;color:#9ca3af;margin-top:3px;">Changed by Administrator only.</div>
        </div>
        <div>
            <label style="<?= $lbl ?>">Employee ID <?= $req ?></label>
            <input type="text" name="employee_id" value="<?= $val('employee_id') ?>" required
                   style="<?= $inp ?>" onfocus="this.style.borderColor='#374151'" onblur="this.style.borderColor='#e5e7eb'">
        </div>
        <div>
            <label style="<?= $lbl ?>">Campus <?= $req ?></label>
            <select name="campus_id" required style="<?= $inp ?>cursor:pointer;"
                    onfocus="this.style.borderColor='#374151'" onblur="this.style.borderColor='#e5e7eb'">
                <option value="">Select campus</option>
                <?php foreach ($campuses as $c): ?>
                <option value="<?= (int)$c['campus_id'] ?>" <?= $selected('campus_id',$c['campus_id']) ?>><?= htmlspecialchars($c['campus_name']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
    </div>
    <?= peBoxEnd() ?>

    <?= peBox('Rank &amp; Status') ?>
    <div class="profile-setup-grid" style="display:grid;grid-template-columns:1fr 180px;gap:0.65rem;">
        <div>
            <label style="<?= $lbl ?>">Faculty Rank <?= $req ?></label>
            <select name="rank" required style="<?= $inp ?>cursor:pointer;"
                    onfocus="this.style.borderColor='#374151'" onblur="this.style.borderColor='#e5e7eb'">
                <option value="">Select rank</option>
                <?php foreach (facultyRanks() as $r): ?>
                <option value="<?= htmlspecialchars($r) ?>" <?= $selected('rank',$r) ?>><?= htmlspecialchars($r) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div>
            <label style="<?= $lbl ?>">Status <?= $req ?></label>
            <select name="faculty_status" required style="<?= $inp ?>cursor:pointer;"
                    onfocus="this.style.borderColor='#374151'" onblur="this.style.borderColor='#e5e7eb'">
                <option value="Existing Faculty" <?= $selected('faculty_status','Existing Faculty','New Faculty') ?>>Existing Faculty</option>
                <option value="New Faculty"      <?= $selected('faculty_status','New Faculty','New Faculty') ?>>New Faculty</option>
            </select>
        </div>
    </div>
    <?= peBoxEnd() ?>

    <div class="profile-setup-actions" style="display:flex;justify-content:flex-end;margin-top:0.25rem;">
        <button type="button" onclick="goStep(2)"
                style="padding:0.45rem 1.4rem;background:#1a3a6b;color:#fff;border:none;font-size:0.82rem;font-weight:600;cursor:pointer;display:inline-flex;align-items:center;gap:5px;"
                onmouseover="this.style.background='#152d54'" onmouseout="this.style.background='#1a3a6b'">
            Continue <i class="bi bi-arrow-right" style="font-size:0.72rem;"></i>
        </button>
    </div>
</div>

<!-- STEP 2 -->
<div id="step2" class="wizard-step" style="display:none;">

    <?= peBox('Educational Attainment') ?>
    <?php
    $edu_levels = [
        'Bachelor'      => ['Undergrad',      true],
        'Master'        => ['Masters',        false],
        'Doctorate'     => ['Doctorate',      false],
        'PostDoctorate' => ['Post-doctorate', false],
    ];
    foreach ($edu_levels as $level_value => [$level_label, $is_required]):
        $idx          = array_search($level_value, $_POST['edu_level'] ?? [], true);
        $degree_value = $idx !== false ? ($_POST['edu_degree'][$idx] ?? '') : '';
        $school_value = $idx !== false ? ($_POST['edu_school'][$idx] ?? '') : '';
        $year_value   = $idx !== false ? ($_POST['edu_year'][$idx]   ?? '') : '';
    ?>
    <div style="border:1px solid #e5e7eb;overflow:hidden;margin-bottom:0.5rem;">
        <div style="background:#f9fafb;border-bottom:1px solid #e5e7eb;padding:0.32rem 0.75rem;display:flex;align-items:center;justify-content:space-between;">
            <span style="font-size:0.7rem;font-weight:700;color:#374151;"><?= htmlspecialchars($level_label) ?></span>
            <?php if (!$is_required): ?>
            <span style="font-size:0.62rem;color:#9ca3af;font-style:italic;">optional &mdash; N/A if none</span>
            <?php else: ?>
            <span style="font-size:0.62rem;font-weight:700;color:#374151;border:1px solid #d1d5db;padding:0 5px;">Required</span>
            <?php endif; ?>
        </div>
        <div style="padding:0.65rem 0.75rem;">
            <input type="hidden" name="edu_level[]" value="<?= htmlspecialchars($level_value) ?>">
            <div class="profile-setup-grid" style="display:grid;grid-template-columns:1fr 1fr 100px;gap:0.55rem;">
                <div>
                    <label style="<?= $lbl ?>">Name of Degree<?= $is_required ? ' '.$req : '' ?></label>
                    <input name="edu_degree[]" value="<?= htmlspecialchars($degree_value) ?>"
                           placeholder="<?= $is_required ? 'Bachelor of Science in...' : 'N/A if none' ?>"
                           <?= $is_required ? 'required' : '' ?>
                           style="<?= $inp ?>" onfocus="this.style.borderColor='#374151'" onblur="this.style.borderColor='#e5e7eb'">
                </div>
                <div>
                    <label style="<?= $lbl ?>">School / University<?= $is_required ? ' '.$req : '' ?></label>
                    <input name="edu_school[]" value="<?= htmlspecialchars($school_value) ?>"
                           placeholder="<?= $is_required ? 'School / University' : 'N/A if none' ?>"
                           <?= $is_required ? 'required' : '' ?>
                           style="<?= $inp ?>" onfocus="this.style.borderColor='#374151'" onblur="this.style.borderColor='#e5e7eb'">
                </div>
                <div>
                    <label style="<?= $lbl ?>">Year<?= $is_required ? ' '.$req : '' ?></label>
                    <input name="edu_year[]" value="<?= htmlspecialchars($year_value) ?>"
                           placeholder="YYYY" <?= $is_required ? 'required' : '' ?>
                           style="<?= $inp ?>" onfocus="this.style.borderColor='#374151'" onblur="this.style.borderColor='#e5e7eb'">
                </div>
            </div>
        </div>
    </div>
    <?php endforeach; ?>
    <?= peBoxEnd() ?>

    <?= peBox('Application History') ?>
    <div class="profile-setup-history" style="display:flex;align-items:center;gap:1rem;">
        <label style="<?= $lbl ?>margin-bottom:0;">Applied during 1st Cycle <?= $req ?></label>
        <select name="applied_first_cycle" required style="border:1px solid #e5e7eb;padding:0.38rem 0.55rem;font-size:0.82rem;background:#fff;outline:none;cursor:pointer;width:100px;"
                onfocus="this.style.borderColor='#374151'" onblur="this.style.borderColor='#e5e7eb'">
            <option value="0" <?= (($_POST['applied_first_cycle'] ?? '0') === '0') ? 'selected' : '' ?>>NO</option>
            <option value="1" <?= (($_POST['applied_first_cycle'] ?? '0') === '1') ? 'selected' : '' ?>>YES</option>
        </select>
    </div>
    <?= peBoxEnd() ?>

    <div class="profile-setup-actions" style="display:flex;justify-content:space-between;margin-top:0.25rem;">
        <button type="button" onclick="goStep(1)"
                style="padding:0.45rem 1.2rem;background:#fff;color:#374151;border:1px solid #e5e7eb;font-size:0.82rem;font-weight:600;cursor:pointer;display:inline-flex;align-items:center;gap:5px;"
                onmouseover="this.style.background='#f9fafb'" onmouseout="this.style.background='#fff'">
            <i class="bi bi-arrow-left" style="font-size:0.72rem;"></i> Back
        </button>
        <button type="button" onclick="goStep(3)"
                style="padding:0.45rem 1.4rem;background:#1a3a6b;color:#fff;border:none;font-size:0.82rem;font-weight:600;cursor:pointer;display:inline-flex;align-items:center;gap:5px;"
                onmouseover="this.style.background='#152d54'" onmouseout="this.style.background='#1a3a6b'">
            Continue <i class="bi bi-arrow-right" style="font-size:0.72rem;"></i>
        </button>
    </div>
</div>

<!-- STEP 3 -->
<div id="step3" class="wizard-step" style="display:none;">

    <div class="profile-setup-grid" style="display:grid;grid-template-columns:1fr 1fr;gap:0.65rem;margin-bottom:0.65rem;">

        <!-- Current -->
        <div style="border:1px solid #e5e7eb;overflow:hidden;">
            <div style="background:#f9fafb;border-bottom:1px solid #e5e7eb;padding:0.4rem 0.9rem;">
                <span style="font-size:0.65rem;font-weight:700;text-transform:uppercase;letter-spacing:0.08em;color:#374151;">Current Employment</span>
            </div>
            <div class="profile-setup-grid" style="padding:0.85rem 0.9rem;display:grid;grid-template-columns:1fr 1fr;gap:0.55rem;">
                <div>
                    <label style="<?= $lbl ?>">Faculty Rank <?= $req ?></label>
                    <div style="border:1px solid #e5e7eb;background:#f9fafb;padding:0.38rem 0.55rem;font-size:0.82rem;color:#374151;display:flex;align-items:center;justify-content:space-between;gap:0.5rem;">
                        <span id="currentRankDisplay" style="flex:1;"><?= htmlspecialchars($old['current_rank'] ?? ($user['rank'] ?? '—')) ?></span>
                        <i class="bi bi-lock" style="color:#9ca3af;font-size:0.65rem;flex-shrink:0;"></i>
                    </div>
                    <input type="hidden" name="current_rank" id="currentRankHidden"
                           value="<?= htmlspecialchars($old['current_rank'] ?? ($user['rank'] ?? '')) ?>">
                    <div style="font-size:0.62rem;color:#9ca3af;margin-top:3px;">Reflects your rank from Step 1.</div>
                </div>
                <div>
                    <label style="<?= $lbl ?>">Mode of Appointment <?= $req ?></label>
                    <select name="current_mode" required style="<?= $inp ?>cursor:pointer;"
                            onfocus="this.style.borderColor='#374151'" onblur="this.style.borderColor='#e5e7eb'">
                        <option value="">Select mode</option>
                        <?php foreach (['Permanent','Temporary','Casual','Contractual','Co-terminus'] as $m):
                            $sel = ($old['current_mode'] ?? '') === $m ? 'selected' : ''; ?>
                        <option value="<?= $m ?>" <?= $sel ?>><?= $m ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label style="<?= $lbl ?>">Date of Appointment <?= $req ?></label>
                    <input type="date" name="current_date" value="<?= htmlspecialchars($old['current_date'] ?? '') ?>" required
                           style="<?= $inp ?>" onfocus="this.style.borderColor='#374151'" onblur="this.style.borderColor='#e5e7eb'">
                </div>
                <div>
                    <label style="<?= $lbl ?>">SUC <?= $req ?></label>
                    <select name="current_suc" required style="<?= $inp ?>cursor:pointer;"
                            onfocus="this.style.borderColor='#374151'" onblur="this.style.borderColor='#e5e7eb'">
                        <option value="Carlos Hilado Memorial State University (CHMSU)" selected>Carlos Hilado Memorial State University (CHMSU)</option>
                    </select>
                </div>
                <div>
                    <label style="<?= $lbl ?>">Campus <?= $req ?></label>
                    <select name="current_campus" required style="<?= $inp ?>cursor:pointer;"
                            onfocus="this.style.borderColor='#374151'" onblur="this.style.borderColor='#e5e7eb'">
                        <option value="">Select campus</option>
                        <?php
                        $cur_campus_val = $old['current_campus'] ?? ($campus_map[(int)($user['campus_id'] ?? 0)] ?? '');
                        foreach ($campuses as $c): $sel = $cur_campus_val === $c['campus_name'] ? 'selected' : ''; ?>
                        <option value="<?= htmlspecialchars($c['campus_name']) ?>" <?= $sel ?>><?= htmlspecialchars($c['campus_name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label style="<?= $lbl ?>">Address <?= $req ?></label>
                    <select name="current_address" id="currentAddressSel" required style="<?= $inp ?>cursor:pointer;"
                            onfocus="this.style.borderColor='#374151'" onblur="this.style.borderColor='#e5e7eb'">
                        <option value="">Select campus first</option>
                        <?php
                        // Build address options from campuses table, falling back to hardcoded values
                        $addr_opts = [];
                        foreach ($campuses as $c) {
                            if (!empty($c['address'])) {
                                $addr_opts[$c['campus_name']] = $c['address'];
                            }
                        }
                        // Fallback hardcoded addresses for campuses without DB address
                        $fallback_addrs = [
                            'Fortune' => 'Sofia Gonzaga Street, Barangay Estefania, Bacolod City, Negros Occidental',
                            'Alijis'  => 'Barangay Alijis, Bacolod City, Negros Occidental',
                            'Binalbagan' => 'Enclaro, Binalbagan, Negros Occidental',
                            'Talisay' => 'Talisay City, Negros Occidental',
                        ];
                        // Merge: DB addresses take priority; fill gaps with fallback
                        $all_addrs = $addr_opts;
                        foreach ($campuses as $c) {
                            $found = false;
                            foreach ($addr_opts as $cname => $addr) {
                                if ($cname === $c['campus_name']) { $found = true; break; }
                            }
                            if (!$found) {
                                foreach ($fallback_addrs as $keyword => $addr) {
                                    if (stripos($c['campus_name'], $keyword) !== false) {
                                        $all_addrs[$c['campus_name']] = $addr;
                                        break;
                                    }
                                }
                            }
                        }
                        $cur_addr_val = $old['current_address'] ?? '';
                        foreach ($all_addrs as $cname => $address):
                            $sel = $cur_addr_val === $address ? 'selected' : '';
                        ?>
                        <option value="<?= htmlspecialchars($address) ?>" data-campus="<?= htmlspecialchars($cname) ?>" <?= $sel ?>>
                            <?= htmlspecialchars($address) ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
        </div>

        <!-- Previous -->
        <div style="border:1px solid #e5e7eb;overflow:hidden;">
            <div style="background:#f9fafb;border-bottom:1px solid #e5e7eb;padding:0.4rem 0.9rem;">
                <span style="font-size:0.65rem;font-weight:700;text-transform:uppercase;letter-spacing:0.08em;color:#374151;">Previous Employment</span>
            </div>
            <div class="profile-setup-grid" style="padding:0.85rem 0.9rem;display:grid;grid-template-columns:1fr 1fr;gap:0.55rem;">
                <div>
                    <label style="<?= $lbl ?>">Previous Rank <?= $req ?></label>
                    <select name="previous_rank" required style="<?= $inp ?>cursor:pointer;"
                            onfocus="this.style.borderColor='#374151'" onblur="this.style.borderColor='#e5e7eb'">
                        <option value="">Select rank</option>
                        <?php foreach (facultyRanks() as $r): $sel = ($old['previous_rank'] ?? '') === $r ? 'selected' : ''; ?>
                        <option value="<?= htmlspecialchars($r) ?>" <?= $sel ?>><?= htmlspecialchars($r) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label style="<?= $lbl ?>">Mode of Appointment <?= $req ?></label>
                    <select name="previous_mode" required style="<?= $inp ?>cursor:pointer;"
                            onfocus="this.style.borderColor='#374151'" onblur="this.style.borderColor='#e5e7eb'">
                        <option value="">Select mode</option>
                        <?php foreach (['Permanent','Temporary','Casual','Contractual','Co-terminus'] as $m):
                            $sel = ($old['previous_mode'] ?? '') === $m ? 'selected' : ''; ?>
                        <option value="<?= $m ?>" <?= $sel ?>><?= $m ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label style="<?= $lbl ?>">Date of Appointment</label>
                    <input type="date" name="previous_date" id="prevDate" value="<?= htmlspecialchars($old['previous_date'] ?? '') ?>"
                           style="<?= $inp ?>" onfocus="this.style.borderColor='#374151'" onblur="this.style.borderColor='#e5e7eb'">
                    <label style="font-size:0.7rem;color:#6b7280;margin-top:3px;display:flex;gap:4px;cursor:pointer;">
                        <input type="checkbox" name="previous_date_na" <?= !empty($old['previous_date_na']) ? 'checked' : '' ?>
                               onchange="document.getElementById('prevDate').disabled=this.checked"> N/A
                    </label>
                </div>
                <div>
                    <label style="<?= $lbl ?>">Employment Sector <?= $req ?></label>
                    <select name="previous_sector" required style="<?= $inp ?>cursor:pointer;"
                            onfocus="this.style.borderColor='#374151'" onblur="this.style.borderColor='#e5e7eb'">
                        <option value="">Select sector</option>
                        <?php foreach (['Government','Private','NGO / Non-profit','Self-employed'] as $s):
                            $sel = ($old['previous_sector'] ?? '') === $s ? 'selected' : ''; ?>
                        <option value="<?= $s ?>" <?= $sel ?>><?= $s ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label style="<?= $lbl ?>">SUC <?= $req ?></label>
                    <input name="previous_suc" value="<?= htmlspecialchars($old['previous_suc'] ?? '') ?>" required
                           style="<?= $inp ?>" onfocus="this.style.borderColor='#374151'" onblur="this.style.borderColor='#e5e7eb'">
                </div>
                <div>
                    <label style="<?= $lbl ?>">Campus <?= $req ?></label>
                    <select name="previous_campus" required style="<?= $inp ?>cursor:pointer;"
                            onfocus="this.style.borderColor='#374151'" onblur="this.style.borderColor='#e5e7eb'">
                        <option value="">Select campus</option>
                        <?php foreach ($campuses as $c): $sel = ($old['previous_campus'] ?? '') === $c['campus_name'] ? 'selected' : ''; ?>
                        <option value="<?= htmlspecialchars($c['campus_name']) ?>" <?= $sel ?>><?= htmlspecialchars($c['campus_name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-span-2" style="grid-column:span 2;">
                    <label style="<?= $lbl ?>">Address <?= $req ?></label>
                    <input name="previous_address" value="<?= htmlspecialchars($old['previous_address'] ?? '') ?>" required
                           style="<?= $inp ?>" onfocus="this.style.borderColor='#374151'" onblur="this.style.borderColor='#e5e7eb'">
                </div>
            </div>
        </div>
    </div>

    <div class="profile-setup-actions" style="display:flex;justify-content:space-between;margin-top:0.25rem;">
        <button type="button" onclick="goStep(2)"
                style="padding:0.45rem 1.2rem;background:#fff;color:#374151;border:1px solid #e5e7eb;font-size:0.82rem;font-weight:600;cursor:pointer;display:inline-flex;align-items:center;gap:5px;"
                onmouseover="this.style.background='#f9fafb'" onmouseout="this.style.background='#fff'">
            <i class="bi bi-arrow-left" style="font-size:0.72rem;"></i> Back
        </button>
        <button type="button" onclick="goStep(4)"
                style="padding:0.45rem 1.4rem;background:#1a3a6b;color:#fff;border:none;font-size:0.82rem;font-weight:600;cursor:pointer;display:inline-flex;align-items:center;gap:5px;"
                onmouseover="this.style.background='#152d54'" onmouseout="this.style.background='#1a3a6b'">
            Review <i class="bi bi-arrow-right" style="font-size:0.72rem;"></i>
        </button>
    </div>
</div>

<!-- STEP 4 -->
<div id="step4" class="wizard-step" style="display:none;">

    <div style="border:1px solid #e5e7eb;overflow:hidden;margin-bottom:0.65rem;">
        <div style="background:#f9fafb;border-bottom:1px solid #e5e7eb;padding:0.4rem 0.9rem;">
            <span style="font-size:0.65rem;font-weight:700;text-transform:uppercase;letter-spacing:0.08em;color:#374151;">Review Your Information</span>
        </div>
        <div style="padding:0.85rem 0.9rem;">
            <p style="font-size:0.78rem;color:#6b7280;margin:0 0 0.9rem;">Check everything below before saving. Click <strong>Edit</strong> to go back.</p>
            <div id="rv_personal" style="margin-bottom:0.65rem;"></div>
            <div id="rv_education" style="margin-bottom:0.65rem;"></div>
            <div id="rv_employment"></div>
        </div>
    </div>

    <div style="border:1px solid #e5e7eb;overflow:hidden;margin-bottom:0.65rem;">
        <div style="background:#f9fafb;border-bottom:1px solid #e5e7eb;padding:0.4rem 0.9rem;">
            <span style="font-size:0.65rem;font-weight:700;text-transform:uppercase;letter-spacing:0.08em;color:#374151;">Data Privacy</span>
        </div>
        <div style="padding:0.75rem 0.9rem;">
            <div style="font-size:0.78rem;color:#6b7280;margin-bottom:0.6rem;line-height:1.5;">
                Before proceeding, please read our
                <a href="#" id="privacyClauseLink"
                   onclick="openPrivacyClause();return false;"
                   style="color:#1a3a6b;font-weight:600;text-decoration:underline;">
                    Data Privacy Statement
                </a>
                to understand how your information will be used.
            </div>
            <label id="privacyAckLabel"
                   style="display:flex;gap:0.55rem;align-items:flex-start;font-size:0.8rem;line-height:1.5;
                          opacity:0.4;user-select:none;color:#374151;cursor:not-allowed;">
                <input type="checkbox" name="privacy_ack" id="privacyAckCheck"
                       style="margin-top:3px;flex-shrink:0;pointer-events:none;" disabled>
                I have read and acknowledge that the information I provide will be used for faculty reclassification processing and account administration.
            </label>
            <div id="privacyUnlockHint" style="display:none;"></div>
            <!-- Nudge toast -->
            <div id="privacyNudge"
                 style="position:fixed;bottom:1.5rem;left:50%;transform:translateX(-50%) translateY(6px);
                        background:#1a3a6b;color:#fff;padding:0.5rem 1rem;font-size:0.78rem;font-weight:600;
                        display:flex;align-items:center;gap:0.5rem;z-index:99998;
                        opacity:0;transition:opacity 0.25s,transform 0.25s;pointer-events:none;
                        border:1px solid rgba(255,255,255,0.15);box-shadow:0 4px 16px rgba(0,0,0,0.25);">
                <i class="bi bi-lock-fill" style="font-size:0.72rem;opacity:0.8;"></i>
                You must open and read the Data Privacy Statement before saving.
            </div>
        </div>
    </div>

    <div class="profile-setup-actions" style="display:flex;justify-content:space-between;">
        <button type="button" onclick="goStep(3)"
                style="padding:0.45rem 1.2rem;background:#fff;color:#374151;border:1px solid #e5e7eb;font-size:0.82rem;font-weight:600;cursor:pointer;display:inline-flex;align-items:center;gap:5px;"
                onmouseover="this.style.background='#f9fafb'" onmouseout="this.style.background='#fff'">
            <i class="bi bi-arrow-left" style="font-size:0.72rem;"></i> Back
        </button>
        <button type="submit"
                style="padding:0.45rem 1.6rem;background:#1a3a6b;color:#fff;border:none;font-size:0.82rem;font-weight:600;cursor:pointer;display:inline-flex;align-items:center;gap:5px;"
                onmouseover="this.style.background='#152d54'" onmouseout="this.style.background='#1a3a6b'">
            <i class="bi bi-check2" style="font-size:0.72rem;"></i> Confirm &amp; Save Profile
        </button>
    </div>
</div>

</form>

<!-- ── Why do we need this? Modal ──────────────────────── -->
<div id="peInfoModal"
     style="display:none;position:fixed;inset:0;z-index:99999;background:rgba(15,23,42,0.55);
            align-items:center;justify-content:center;padding:1rem;"
     onclick="if(event.target===this)this.style.display='none'">
    <div style="background:#fff;width:100%;max-width:560px;border:1px solid #e5e7eb;overflow:hidden;max-height:90vh;display:flex;flex-direction:column;">

        <!-- Header -->
        <div style="background:#1a3a6b;padding:0.75rem 1rem;display:flex;align-items:center;justify-content:space-between;flex-shrink:0;">
            <div style="display:flex;align-items:center;gap:0.6rem;">
                <i class="bi bi-info-circle-fill" style="color:rgba(255,255,255,0.75);font-size:0.9rem;"></i>
                <div>
                    <div style="font-size:0.85rem;font-weight:700;color:#fff;">How Your Information Is Used</div>
                    <div style="font-size:0.62rem;color:rgba(255,255,255,0.5);margin-top:1px;">Faculty Reclassification Management System</div>
                </div>
            </div>
            <button onclick="document.getElementById('peInfoModal').style.display='none'"
                    style="background:none;border:none;color:rgba(255,255,255,0.6);font-size:1.1rem;cursor:pointer;line-height:1;padding:0;"
                    onmouseover="this.style.color='#fff'" onmouseout="this.style.color='rgba(255,255,255,0.6)'">
                <i class="bi bi-x-lg"></i>
            </button>
        </div>

        <!-- Body -->
        <div style="overflow-y:auto;padding:1.1rem 1.1rem 0.5rem;">

            <?php
            $infoSections = [
                ['bi-person','Personal &amp; Account Details', [
                    'Your name, employee ID, and campus are used to create your official faculty record in the system.',
                    'Your email is used for system notifications such as cycle announcements, application status updates, and criteria change alerts.',
                    'Your faculty rank and status determine which scoring criteria apply to your reclassification application.',
                ]],
                ['bi-mortarboard','Educational Attainment', [
                    'Your educational background is a core requirement for faculty reclassification under CHED and CSC guidelines.',
                    'Degree information is recorded in your application and evaluated by the reclassification committee.',
                    'Higher educational attainment may qualify you for a higher faculty rank during the reclassification process.',
                ]],
                ['bi-briefcase','Employment History', [
                    'Your current appointment details (rank, mode, date) form the baseline record for the reclassification evaluation.',
                    'Previous employment history provides context for your career progression and is reviewed by evaluators.',
                    'This information is submitted as part of your official reclassification dossier to the institution.',
                ]],
                ['bi-shield-lock','Data Privacy', [
                    'All information you provide is stored securely and used exclusively for faculty reclassification processing.',
                    'Your data will not be shared with third parties outside the institution.',
                    'You may request a correction or update to your profile by contacting the system administrator.',
                ]],
            ];
            foreach ($infoSections as [$icon, $title, $points]):
            ?>
            <div style="margin-bottom:1rem;">
                <div style="display:flex;align-items:center;gap:0.5rem;margin-bottom:0.4rem;">
                    <div style="width:24px;height:24px;background:#1a3a6b;display:flex;align-items:center;justify-content:center;flex-shrink:0;">
                        <i class="bi <?= $icon ?>" style="color:#fff;font-size:0.65rem;"></i>
                    </div>
                    <span style="font-size:0.78rem;font-weight:700;color:#1a3a6b;"><?= $title ?></span>
                </div>
                <div style="border:1px solid #e5e7eb;overflow:hidden;">
                    <?php foreach ($points as $i => $pt): ?>
                    <div style="display:flex;align-items:flex-start;gap:0.6rem;padding:0.45rem 0.75rem;
                                <?= $i < count($points)-1 ? 'border-bottom:1px solid #f3f4f6;' : '' ?>
                                font-size:0.78rem;color:#374151;line-height:1.5;">
                        <i class="bi bi-dot" style="color:#1a3a6b;font-size:1rem;flex-shrink:0;margin-top:-1px;"></i>
                        <?= $pt ?>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endforeach; ?>

        </div>

        <!-- Footer -->
        <div style="padding:0.65rem 1rem;border-top:1px solid #e5e7eb;background:#f9fafb;display:flex;justify-content:flex-end;flex-shrink:0;">
            <button onclick="document.getElementById('peInfoModal').style.display='none'"
                    style="padding:0.4rem 1.2rem;border:1px solid #e5e7eb;background:#fff;font-size:0.8rem;font-weight:600;color:#374151;cursor:pointer;"
                    onmouseover="this.style.background='#f3f4f6'" onmouseout="this.style.background='#fff'">
                Close
            </button>
        </div>
    </div>
</div>

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
        // Always sync rank from Step 1 into the display + hidden input
        const rankSel = document.querySelector('select[name="rank"]');
        const rankVal = rankSel?.options[rankSel.selectedIndex]?.value || '';
        const hidden  = document.getElementById('currentRankHidden');
        const display = document.getElementById('currentRankDisplay');
        if (hidden)  hidden.value       = rankVal;
        if (display) display.textContent = rankVal || '—';

        // Sync campus from Step 1 into current_campus select (only if empty)
        const campSel = document.querySelector('select[name="campus_id"]');
        const ccSel   = document.querySelector('select[name="current_campus"]');
        if (campSel && ccSel && !ccSel.value) {
            const t = campSel.options[campSel.selectedIndex]?.text || '';
            if (t && t !== 'Select campus') ccSel.value = t;
        }
        // Auto-fill address when campus is already set
        syncCampusAddress();
    }

    // Map campus name keywords → address option values
    const campusAddressMap = <?php
        $jsMap = [];
        foreach ($all_addrs as $cname => $address) {
            // Use first word of campus name as keyword
            $parts = preg_split('/[-\s]+/', $cname);
            foreach ($parts as $part) {
                if (strlen($part) > 3 && strtolower($part) !== 'chmsu') {
                    $jsMap[$part] = $address;
                    break;
                }
            }
        }
        echo json_encode($jsMap, JSON_UNESCAPED_UNICODE);
    ?>;

    function syncCampusAddress() {
        const ccSel   = document.querySelector('select[name="current_campus"]');
        const addrSel = document.getElementById('currentAddressSel');
        if (!ccSel || !addrSel) return;
        const campus = ccSel.value || '';
        for (const [keyword, address] of Object.entries(campusAddressMap)) {
            if (campus.includes(keyword)) {
                addrSel.value = address;
                return;
            }
        }
    }

    // Wire privacy label click when locked → open modal + nudge
    var privacyLbl = document.getElementById('privacyAckLabel');
    if (privacyLbl) {
        privacyLbl.addEventListener('click', function(e) {
            var chk = document.getElementById('privacyAckCheck');
            if (chk && chk.disabled) {
                e.preventDefault();
                openPrivacyClause();
                showPrivacyNudge();
            }
        });
    }

    // Wire campus change → auto-fill address
    var ccSel = document.querySelector('select[name="current_campus"]');
    if (ccSel) ccSel.addEventListener('change', syncCampusAddress);

    // Also wire Step 1 rank select to live-update the display
    document.addEventListener('change', function(e) {
        if (e.target && e.target.name === 'rank') {
            const v = e.target.value;
            const hidden  = document.getElementById('currentRankHidden');
            const display = document.getElementById('currentRankDisplay');
            if (hidden)  hidden.value       = v;
            if (display) display.textContent = v || '—';
        }
    });

    function esc(s) {
        return String(s ?? '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
    }
    function field(label, value) {
        const v = (value ?? '').trim();
        return `<div class="profile-setup-review-field" style="min-width:0;flex:1;">
            <div style="font-size:0.6rem;font-weight:700;text-transform:uppercase;letter-spacing:0.06em;color:#9ca3af;margin-bottom:2px;">${esc(label)}</div>
            <div style="font-size:0.82rem;color:${v?'#111827':'#d1d5db'};font-weight:500;word-break:break-all;overflow-wrap:anywhere;">${v ? esc(v) : '&mdash;'}</div>
        </div>`;
    }
    function rvSection(title, stepNum, rows) {
        return `<div style="border:1px solid #e5e7eb;overflow:hidden;margin-bottom:0.5rem;">
            <div style="background:#f9fafb;border-bottom:1px solid #e5e7eb;padding:0.35rem 0.75rem;
                        display:flex;align-items:center;justify-content:space-between;">
                <span style="font-size:0.62rem;font-weight:700;text-transform:uppercase;letter-spacing:0.07em;color:#6b7280;">${esc(title)}</span>
                <button type="button" onclick="goStep(${stepNum})"
                        style="padding:1px 10px;border:1px solid #e5e7eb;background:#fff;font-size:0.7rem;font-weight:600;color:#374151;cursor:pointer;">
                    Edit
                </button>
            </div>
            <div style="padding:0.65rem 0.75rem;display:flex;flex-wrap:wrap;gap:0.6rem 1.1rem;">${rows}</div>
        </div>`;
    }

    function buildReview() {
        const firstName  = document.querySelector('input[name="first_name"]')?.value  || '';
        const middleName = document.querySelector('input[name="middle_name"]')?.value || '';
        const lastName   = document.querySelector('input[name="last_name"]')?.value   || '';
        const suffix     = document.querySelector('input[name="suffix"]')?.value      || '';
        const empId      = document.querySelector('input[name="employee_id"]')?.value || '';
        const rankSel    = document.querySelector('select[name="rank"]');
        const rank       = rankSel?.options[rankSel.selectedIndex]?.value || '';
        const campSel    = document.querySelector('select[name="campus_id"]');
        const campus     = campSel?.options[campSel.selectedIndex]?.text || '';
        const statSel    = document.querySelector('select[name="faculty_status"]');
        const status     = statSel?.options[statSel.selectedIndex]?.text || '';
        const email      = document.querySelector('#step1 input[type="email"]')?.value || '';
        const fullName   = [firstName, middleName, lastName, suffix].filter(Boolean).join(' ');
        document.getElementById('rv_personal').innerHTML = rvSection('Personal & Account', 1,
            field('Full Name',fullName)+field('Email',email)+field('Employee ID',empId)+field('Campus',campus)+field('Rank',rank)+field('Status',status));

        const levels  = [...document.querySelectorAll('input[name="edu_level[]"]')].map(e=>e.value);
        const degrees = [...document.querySelectorAll('input[name="edu_degree[]"]')].map(e=>e.value);
        const schools = [...document.querySelectorAll('input[name="edu_school[]"]')].map(e=>e.value);
        const years   = [...document.querySelectorAll('input[name="edu_year[]"]')].map(e=>e.value);
        const labels  = {Bachelor:'Undergrad',Master:'Masters',Doctorate:'Doctorate',PostDoctorate:'Post-doctorate'};
        const cycleEl = document.querySelector('select[name="applied_first_cycle"]');
        const cycle   = cycleEl?.options[cycleEl.selectedIndex]?.text || '';
        let p2 = '';
        levels.forEach((lv,i) => {
            p2 += `<div style="width:100%;display:flex;flex-wrap:wrap;gap:0.4rem 1rem;padding-bottom:0.4rem;border-bottom:1px solid #f3f4f6;margin-bottom:0.3rem;">
                <div style="width:100%;font-size:0.65rem;font-weight:700;color:#374151;">${esc(labels[lv]||lv)}</div>
                ${field('Degree',degrees[i])}${field('School',schools[i])}${field('Year',years[i])}
            </div>`;
        });
        p2 += field('Applied 1st Cycle',cycle);
        document.getElementById('rv_education').innerHTML = rvSection('Education', 2, p2);

        const cv = n => document.querySelector(`[name="${n}"]`)?.value || '';
        const prevDateNA = document.querySelector('input[name="previous_date_na"]')?.checked;
        let p3 = `<div style="width:100%;font-size:0.65rem;font-weight:700;color:#374151;margin-bottom:0.2rem;">Current</div>`;
        p3 += field('Rank',cv('current_rank'))+field('Mode',cv('current_mode'))+field('Date',cv('current_date'))+field('SUC',cv('current_suc'))+field('Campus',cv('current_campus'))+field('Address',cv('current_address'));
        p3 += `<div style="width:100%;height:1px;background:#f3f4f6;margin:0.3rem 0;"></div>
               <div style="width:100%;font-size:0.65rem;font-weight:700;color:#374151;margin-bottom:0.2rem;">Previous</div>`;
        p3 += field('Rank',cv('previous_rank'))+field('Mode',cv('previous_mode'))+field('Date',prevDateNA?'N/A':cv('previous_date'))+field('Sector',cv('previous_sector'))+field('SUC',cv('previous_suc'))+field('Campus',cv('previous_campus'))+field('Address',cv('previous_address'));
        document.getElementById('rv_employment').innerHTML = rvSection('Employment', 3, p3);
    }

    function renderStep() {
        for (let i = 1; i <= TOTAL; i++) {
            const el     = document.getElementById('step'+i);
            if (el) el.style.display = i === current ? 'block' : 'none';
            const circle = document.getElementById('stepCircle'+i);
            const label  = document.getElementById('stepLabel'+i);
            if (!circle) continue;
            if (i < current) {
                circle.style.cssText = 'width:28px;height:28px;border-radius:50%;border:2px solid #1a3a6b;display:flex;align-items:center;justify-content:center;font-size:0.75rem;font-weight:700;flex-shrink:0;color:#1a3a6b;background:#fff;';
                circle.innerHTML = '<i class="bi bi-check2" style="font-size:0.72rem;"></i>';
                label.style.color = '#1a3a6b'; label.style.fontWeight = '600';
            } else if (i === current) {
                circle.style.cssText = 'width:28px;height:28px;border-radius:50%;border:2px solid #1a3a6b;display:flex;align-items:center;justify-content:center;font-size:0.75rem;font-weight:700;flex-shrink:0;color:#fff;background:#1a3a6b;';
                circle.innerHTML = String(i);
                label.style.color = '#1a3a6b'; label.style.fontWeight = '700';
            } else {
                circle.style.cssText = 'width:28px;height:28px;border-radius:50%;border:2px solid #d1d5db;display:flex;align-items:center;justify-content:center;font-size:0.75rem;font-weight:700;flex-shrink:0;color:#9ca3af;background:#fff;';
                circle.innerHTML = String(i);
                label.style.color = '#9ca3af'; label.style.fontWeight = '600';
            }
        }
        // Update track line: fill left portion in navy up to current step
        const track = document.getElementById('stepTrack');
        if (track) {
            const pct = Math.round(((current - 1) / (TOTAL - 1)) * 100);
            track.style.background = 'linear-gradient(to right, #1a3a6b ' + pct + '%, #e5e7eb ' + pct + '%)';
        }
        window.scrollTo({ top: 0, behavior: 'smooth' });
    }

    window.goStep = goStep;
    renderStep();

    // ── Persist current step across refreshes ────────────────────────────
    const STEP_KEY = 'pe_step_<?= (int)$uid ?>';

    const _origGoStep = goStep;
    window.goStep = function(n) {
        _origGoStep(n);
        try { localStorage.setItem(STEP_KEY, current); } catch(e) {}
    };

    // ── Autosave to localStorage ──────────────────────────────────────────
    const SAVE_KEY = 'pe_draft_<?= (int)$uid ?>';

    const FIELDS = [
        'first_name','middle_name','last_name','suffix',
        'employee_id','campus_id','rank','faculty_status',
        'current_rank','current_mode','current_date','current_suc','current_campus','current_address',
        'previous_rank','previous_mode','previous_date','previous_sector','previous_suc','previous_campus','previous_address',
        'applied_first_cycle'
    ];
    const EDU_FIELDS = ['edu_degree[]','edu_school[]','edu_year[]'];
    const CHECKBOXES = ['no_middle_name','previous_date_na'];

    function saveDraft() {
        var data = {};
        FIELDS.forEach(function(n) {
            var el = document.querySelector('[name="' + n + '"]');
            if (el) data[n] = el.value;
        });
        EDU_FIELDS.forEach(function(n) {
            data[n] = [...document.querySelectorAll('[name="' + n + '"]')].map(function(e){ return e.value; });
        });
        CHECKBOXES.forEach(function(n) {
            var el = document.querySelector('[name="' + n + '"]');
            if (el) data[n] = el.checked;
        });
        try { localStorage.setItem(SAVE_KEY, JSON.stringify(data)); } catch(e) {}
    }

    function loadDraft() {
        var raw;
        try { raw = localStorage.getItem(SAVE_KEY); } catch(e) { return; }
        if (!raw) return;
        var data;
        try { data = JSON.parse(raw); } catch(e) { return; }

        FIELDS.forEach(function(n) {
            if (data[n] === undefined) return;
            var el = document.querySelector('[name="' + n + '"]');
            if (el) el.value = data[n];
        });
        EDU_FIELDS.forEach(function(n) {
            if (!Array.isArray(data[n])) return;
            var els = [...document.querySelectorAll('[name="' + n + '"]')];
            els.forEach(function(el, i) { if (data[n][i] !== undefined) el.value = data[n][i]; });
        });
        CHECKBOXES.forEach(function(n) {
            if (data[n] === undefined) return;
            var el = document.querySelector('[name="' + n + '"]');
            if (el) {
                el.checked = data[n];
                el.dispatchEvent(new Event('change'));
            }
        });

        // Sync suffix select to match restored text value
        var suffixInp = document.getElementById('suffixInput');
        var suffixSel = document.getElementById('suffixSelect');
        if (suffixInp && suffixSel && data['suffix']) {
            var found = false;
            for (var i = 0; i < suffixSel.options.length; i++) {
                if (suffixSel.options[i].value === data['suffix']) {
                    suffixSel.selectedIndex = i; found = true; break;
                }
            }
            if (!found) {
                suffixSel.value = 'Other';
                suffixInp.value = data['suffix'];
                suffixInp.style.display = '';
            }
        }
    }

    // Clear draft and step on successful submit
    document.getElementById('profileWizard').addEventListener('submit', function(e) {
        // Block submit if privacy checkbox is still disabled (not yet read)
        var chk = document.getElementById('privacyAckCheck');
        if (chk && chk.disabled) {
            e.preventDefault();
            // Open the privacy modal
            openPrivacyClause();
            // Show nudge toast
            showPrivacyNudge();
            return false;
        }
        if (chk && !chk.checked) {
            e.preventDefault();
            showPrivacyNudge();
            chk.focus();
            return false;
        }
        try { localStorage.removeItem(SAVE_KEY); localStorage.removeItem(STEP_KEY); } catch(e) {}
    });

    // Attach save listeners
    document.getElementById('profileWizard').addEventListener('input', saveDraft);
    document.getElementById('profileWizard').addEventListener('change', saveDraft);

    // Restore step and field values on load
    setTimeout(function() {
        loadDraft();
        // Always sync employment display fields after draft is restored
        syncStep1ToEmployment();
        try {
            var savedStep = parseInt(localStorage.getItem(STEP_KEY), 10);
            if (savedStep >= 2 && savedStep <= 4) {
                current = savedStep;
                if (current === 4) buildReview();
                renderStep();
            }
        } catch(e) {}
    }, 0);
})();

function handleSuffixChange(sel) {
    var inp = document.getElementById('suffixInput');
    if (sel.value === 'Other') {
        inp.style.display = '';
        inp.value = '';
        inp.focus();
        inp.addEventListener('input', function() {
            if (typeof saveDraft === 'function') saveDraft();
        });
    } else {
        inp.style.display = 'none';
        inp.value = sel.value;
        if (typeof saveDraft === 'function') saveDraft();
    }
}



</script>

<style>
.wizard-step { transition: none; }
@media (max-width: 768px) {
    .profile-setup-intro {
        margin-top: .75rem;
        padding: .85rem !important;
        flex-direction: column;
        align-items: flex-start !important;
        gap: .65rem !important;
    }
    .profile-setup-intro-main { align-items: flex-start !important; }
    .profile-setup-intro-copy { font-size: .85rem !important; line-height: 1.5; color: #475569 !important; }
    .profile-setup-intro-copy a { display: inline-block; margin: .25rem 0 0 !important; }
    #stepIndicator { grid-template-columns: repeat(4, minmax(0, 1fr)) !important; }
    #stepIndicator [id^="stepLabel"] { white-space: normal !important; line-height: 1.3; padding-inline: .2rem; }

    #profileWizard .profile-setup-grid { grid-template-columns: minmax(0, 1fr) !important; gap: .9rem !important; }
    #profileWizard .profile-setup-grid > * { min-width: 0; }
    #profileWizard .profile-setup-grid > .col-span-2 { grid-column: 1 / -1 !important; }
    #profileWizard .profile-setup-grid label,
    #profileWizard .profile-setup-history label { font-size: .8rem !important; line-height: 1.4; letter-spacing: normal !important; text-transform: none !important; }
    #profileWizard .profile-setup-history { flex-wrap: wrap; gap: .45rem !important; }
    #profileWizard .profile-setup-history select { width: 100% !important; }
    #profileWizard input:not([type="checkbox"]):not([type="hidden"]),
    #profileWizard select { min-width: 0; max-width: 100%; min-height: 44px; font-size: 1rem !important; }
    #profileWizard .profile-setup-actions { flex-wrap: wrap; gap: .5rem; }
    #profileWizard .profile-setup-actions button { min-height: 44px; }

    #profileWizard .profile-setup-review-field { flex: 0 0 100% !important; }
    #profileWizard .profile-setup-review-field > :first-child { font-size: .72rem !important; }
    #profileWizard .profile-setup-review-field > :last-child { word-break: normal !important; }
    #profileWizard #privacyNudge { width: min(24rem, calc(100% - 2rem)); }
}
</style>

<script>
function openPrivacyClause() {
    document.getElementById('privacyModal').style.display = 'flex';
}
function closePrivacyModal() {
    document.getElementById('privacyModal').style.display = 'none';
}
function acceptPrivacyClause() {
    closePrivacyModal();
    var lbl  = document.getElementById('privacyAckLabel');
    var chk  = document.getElementById('privacyAckCheck');
    var hint = document.getElementById('privacyUnlockHint');
    var trap = document.getElementById('privacyClickTrap');
    var link = document.getElementById('privacyClauseLink');
    if (lbl)  { lbl.style.opacity='1'; lbl.style.pointerEvents=''; lbl.style.cursor='pointer'; lbl.title=''; }
    if (chk)  { chk.disabled = false; chk.style.pointerEvents = ''; }
    if (hint) { hint.style.display = 'none'; }
    if (link) { link.style.color='#16a34a'; }
}
function showPrivacyNudge() {
    var nudge = document.getElementById('privacyNudge');
    if (!nudge) return;
    nudge.style.opacity = '1';
    nudge.style.transform = 'translateY(0)';
    clearTimeout(window._nudgeTimer);
    window._nudgeTimer = setTimeout(function() {
        nudge.style.opacity = '0';
        nudge.style.transform = 'translateY(6px)';
    }, 3000);
}
</script>

<!-- ── Privacy Clause Modal ───────────────────────────────── -->
<div id="privacyModal"
     style="display:none;position:fixed;inset:0;z-index:99999;background:rgba(15,23,42,0.55);
            align-items:center;justify-content:center;padding:1rem;"
     onclick="if(event.target===this)return false;">
    <div style="background:#fff;width:100%;max-width:560px;border:1px solid #e5e7eb;overflow:hidden;max-height:88vh;display:flex;flex-direction:column;">

        <div style="background:#1a3a6b;padding:0.75rem 1rem;display:flex;align-items:center;justify-content:space-between;gap:0.6rem;flex-shrink:0;">
            <div style="display:flex;align-items:center;gap:0.6rem;">
                <i class="bi bi-shield-lock-fill" style="color:rgba(255,255,255,0.75);font-size:0.9rem;"></i>
                <div>
                    <div style="font-size:0.85rem;font-weight:700;color:#fff;">Data Privacy Statement</div>
                    <div style="font-size:0.62rem;color:rgba(255,255,255,0.5);margin-top:1px;">Republic Act No. 10173 &mdash; Data Privacy Act of 2012</div>
                </div>
            </div>
            <button onclick="closePrivacyModal()"
                    style="background:none;border:none;color:rgba(255,255,255,0.6);font-size:1.1rem;cursor:pointer;line-height:1;padding:0;flex-shrink:0;"
                    onmouseover="this.style.color='#fff'" onmouseout="this.style.color='rgba(255,255,255,0.6)'">
                <i class="bi bi-x-lg"></i>
            </button>
        </div>

        <div style="overflow-y:auto;padding:1rem 1.1rem;font-size:0.8rem;color:#374151;line-height:1.7;flex:1;">

            <p style="margin:0 0 0.75rem;">
                <strong>Carlos Hilado Memorial State University (CHMSU)</strong>, as the Personal Information Controller,
                collects and processes your personal data in accordance with <strong>Republic Act No. 10173</strong>,
                otherwise known as the <em>Data Privacy Act of 2012</em>, and its Implementing Rules and Regulations.
            </p>

            <div style="border:1px solid #e5e7eb;overflow:hidden;margin-bottom:0.75rem;">
                <div style="background:#f9fafb;border-bottom:1px solid #e5e7eb;padding:0.35rem 0.75rem;">
                    <span style="font-size:0.65rem;font-weight:700;text-transform:uppercase;letter-spacing:0.07em;color:#374151;">Purpose of Collection</span>
                </div>
                <div style="padding:0.6rem 0.75rem;">
                    The information you provide is collected <strong>solely</strong> for the following purposes:
                    <div style="display:flex;align-items:flex-start;gap:0.5rem;padding:0.3rem 0;">
                        <i class="bi bi-dot" style="color:#1a3a6b;font-size:1rem;flex-shrink:0;margin-top:-1px;"></i>
                        <span>Processing your faculty reclassification application under CHED and CSC guidelines.</span>
                    </div>
                    <div style="display:flex;align-items:flex-start;gap:0.5rem;padding:0.3rem 0;border-top:1px solid #f3f4f6;">
                        <i class="bi bi-dot" style="color:#1a3a6b;font-size:1rem;flex-shrink:0;margin-top:-1px;"></i>
                        <span>Maintaining an accurate record of faculty credentials and employment history.</span>
                    </div>
                    <div style="display:flex;align-items:flex-start;gap:0.5rem;padding:0.3rem 0;border-top:1px solid #f3f4f6;">
                        <i class="bi bi-dot" style="color:#1a3a6b;font-size:1rem;flex-shrink:0;margin-top:-1px;"></i>
                        <span>Sending system notifications related to reclassification cycles, application status, and criteria updates.</span>
                    </div>
                    <div style="display:flex;align-items:flex-start;gap:0.5rem;padding:0.3rem 0;border-top:1px solid #f3f4f6;">
                        <i class="bi bi-dot" style="color:#1a3a6b;font-size:1rem;flex-shrink:0;margin-top:-1px;"></i>
                        <span>Generating official reports and documents required by the institution and oversight bodies.</span>
                    </div>
                </div>
            </div>

            <div style="border:1px solid #e5e7eb;overflow:hidden;margin-bottom:0.75rem;">
                <div style="background:#f9fafb;border-bottom:1px solid #e5e7eb;padding:0.35rem 0.75rem;">
                    <span style="font-size:0.65rem;font-weight:700;text-transform:uppercase;letter-spacing:0.07em;color:#374151;">Your Rights as a Data Subject</span>
                </div>
                <div style="padding:0.6rem 0.75rem;">
                    <div style="display:flex;align-items:flex-start;gap:0.5rem;padding:0.3rem 0;font-size:0.78rem;">
                        <i class="bi bi-dot" style="color:#1a3a6b;font-size:1rem;flex-shrink:0;margin-top:-1px;"></i>
                        <span><strong>Right to be informed</strong> &mdash; You have the right to know how your data is collected and used.</span>
                    </div>
                    <div style="display:flex;align-items:flex-start;gap:0.5rem;padding:0.3rem 0;border-top:1px solid #f3f4f6;font-size:0.78rem;">
                        <i class="bi bi-dot" style="color:#1a3a6b;font-size:1rem;flex-shrink:0;margin-top:-1px;"></i>
                        <span><strong>Right to access</strong> &mdash; You may request a copy of the personal data we hold about you.</span>
                    </div>
                    <div style="display:flex;align-items:flex-start;gap:0.5rem;padding:0.3rem 0;border-top:1px solid #f3f4f6;font-size:0.78rem;">
                        <i class="bi bi-dot" style="color:#1a3a6b;font-size:1rem;flex-shrink:0;margin-top:-1px;"></i>
                        <span><strong>Right to rectification</strong> &mdash; You may request corrections to inaccurate or incomplete data.</span>
                    </div>
                    <div style="display:flex;align-items:flex-start;gap:0.5rem;padding:0.3rem 0;border-top:1px solid #f3f4f6;font-size:0.78rem;">
                        <i class="bi bi-dot" style="color:#1a3a6b;font-size:1rem;flex-shrink:0;margin-top:-1px;"></i>
                        <span><strong>Right to erasure</strong> &mdash; You may request deletion subject to legal and institutional retention policies.</span>
                    </div>
                    <div style="display:flex;align-items:flex-start;gap:0.5rem;padding:0.3rem 0;border-top:1px solid #f3f4f6;font-size:0.78rem;">
                        <i class="bi bi-dot" style="color:#1a3a6b;font-size:1rem;flex-shrink:0;margin-top:-1px;"></i>
                        <span><strong>Right to data portability</strong> &mdash; You may request your data in a structured, commonly used format.</span>
                    </div>
                </div>
            </div>

            <div style="border:1px solid #e5e7eb;overflow:hidden;margin-bottom:0.75rem;">
                <div style="background:#f9fafb;border-bottom:1px solid #e5e7eb;padding:0.35rem 0.75rem;">
                    <span style="font-size:0.65rem;font-weight:700;text-transform:uppercase;letter-spacing:0.07em;color:#374151;">Data Security &amp; Retention</span>
                </div>
                <div style="padding:0.6rem 0.75rem;">
                    Your data is stored in a secured database accessible only to authorized personnel. It will be retained for the duration required by institutional and government regulations and disposed of in accordance with CHMSU's data retention policy.
                </div>
            </div>

            <p style="margin:0;font-size:0.75rem;color:#6b7280;">
                For concerns regarding your personal data, contact the CHMSU Data Privacy Officer through the Office of the University Registrar.
            </p>
        </div>

        <div style="padding:0.65rem 1rem;border-top:1px solid #e5e7eb;background:#f9fafb;
                    display:flex;align-items:center;justify-content:space-between;flex-shrink:0;">
            <span style="font-size:0.68rem;color:#9ca3af;">Read the full statement before accepting.</span>
            <button onclick="acceptPrivacyClause()"
                    style="padding:0.42rem 1.2rem;background:#1a3a6b;color:#fff;border:none;
                           font-size:0.8rem;font-weight:700;cursor:pointer;display:inline-flex;align-items:center;gap:5px;"
                    onmouseover="this.style.background='#152d54'" onmouseout="this.style.background='#1a3a6b'">
                <i class="bi bi-check2" style="font-size:0.75rem;"></i> I Have Read &amp; Understood This
            </button>
        </div>
    </div>
</div>
