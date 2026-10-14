<?php
// Request handling and data loading for frontend/views/shared/profile.php
$uid  = $_SESSION['user_id'];
$user = $pdo->prepare("SELECT * FROM users WHERE user_id = ?");
$user->execute([$uid]);
$user = $user->fetch();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'update_info') {
        $first_name  = trim($_POST['first_name']  ?? '');
        $middle_name = trim($_POST['middle_name'] ?? '') ?: null;
        $last_name   = trim($_POST['last_name']   ?? '');
        $campus_id   = intval($_POST['campus_id'] ?? 0);
        $rank        = trim($_POST['rank'] ?? '');
        $suffix      = trim($_POST['suffix'] ?? '') ?: null;
        $employee_id = trim($_POST['employee_id'] ?? ($user['employee_id'] ?? ''));
        $faculty_status = trim($_POST['faculty_status'] ?? ($user['faculty_status'] ?? 'New Faculty'));
        $applied_first_cycle = ($_POST['applied_first_cycle'] ?? ($user['applied_first_cycle'] ?? '0')) === '1' ? 1 : 0;

        $pic_path = $user['profile_pic'] ?? '';
        if ($_SESSION['role'] !== 'admin' && !empty($_FILES['profile_pic']['name'])) {
            $ext = strtolower(pathinfo($_FILES['profile_pic']['name'], PATHINFO_EXTENSION));
            if (in_array($ext, ['jpg','jpeg','png','gif','webp'])) {
                $filename    = 'avatar_' . $uid . '_' . time() . '.' . $ext;
                $abs_avatars = realpath(__DIR__ . '/../../../uploads/avatars/');
                if (!$abs_avatars) {
                    mkdir(__DIR__ . '/../../../uploads/avatars/', 0755, true);
                    $abs_avatars = realpath(__DIR__ . '/../../../uploads/avatars/');
                }
                move_uploaded_file($_FILES['profile_pic']['tmp_name'], $abs_avatars . DIRECTORY_SEPARATOR . $filename);
                // Delete old avatar
                if ($pic_path) {
                    $old_abs = realpath(__DIR__ . '/../../../') . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $pic_path);
                    if (file_exists($old_abs)) @unlink($old_abs);
                }
                $pic_path = 'uploads/avatars/' . $filename;
            }
        }

        if ($first_name && $last_name) {
            // full_name is a GENERATED column  -  update only the parts
            if ($_SESSION['role'] === 'faculty') {
                $dupe = $pdo->prepare("SELECT user_id FROM users WHERE employee_id=? AND user_id<>?");
                $dupe->execute([$employee_id, $uid]);
                if ($employee_id !== '' && $dupe->fetch()) {
                    flashMessage('danger', 'That Employee ID is already used by another account.');
                    echo "<script>window.location.href='index.php?page=profile';</script>";
                    exit;
                }
                $pdo->prepare("UPDATE users SET first_name=?, middle_name=?, last_name=?, suffix=?, employee_id=?, campus_id=?, rank=?, faculty_status=?, applied_first_cycle=?, profile_pic=? WHERE user_id=?")
                    ->execute([$first_name, $middle_name, $last_name, $suffix, $employee_id ?: null, $campus_id ?: null, $rank, $faculty_status, $applied_first_cycle, $pic_path, $uid]);

                if (isset($_POST['edu_level']) && is_array($_POST['edu_level'])) {
                    $allowed_edu_levels = ['Bachelor','Master','Doctorate','PostDoctorate'];
                    $edu_rows = [];
                    foreach ($_POST['edu_level'] as $i => $level) {
                        $level = trim((string)$level);
                        if (!in_array($level, $allowed_edu_levels, true)) continue;
                        $degree = trim((string)($_POST['edu_degree'][$i] ?? ''));
                        $school = trim((string)($_POST['edu_school'][$i] ?? ''));
                        $year = trim((string)($_POST['edu_year'][$i] ?? ''));
                        if ($level === 'Bachelor' && ($degree === '' || $school === '' || $year === '')) {
                            flashMessage('danger', 'Undergrad education must include name of degree, name of SUC, and year graduated.');
                            echo "<script>window.location.href='index.php?page=profile';</script>";
                            exit;
                        }
                        $edu_rows[] = [
                            'level' => $level,
                            'degree' => $degree !== '' ? $degree : 'N/A',
                            'school' => $school !== '' ? $school : 'N/A',
                            'year' => $year !== '' ? $year : 'N/A',
                        ];
                    }
                    $pdo->prepare("DELETE FROM faculty_education WHERE user_id=?")->execute([$uid]);
                    $edu_ins = $pdo->prepare("INSERT INTO faculty_education (user_id, level, degree_program, major_specialization, school_university, year_graduated, honors_units_notes) VALUES (?,?,?,?,?,?,?)");
                    foreach ($edu_rows as $row) {
                        $edu_ins->execute([$uid, $row['level'], $row['degree'], 'N/A', $row['school'], $row['year'], null]);
                    }
                }
            } else {
                $pdo->prepare("UPDATE users SET first_name=?, middle_name=?, last_name=?, suffix=?, profile_pic=? WHERE user_id=?")
                    ->execute([$first_name, $middle_name, $last_name, $suffix, $pic_path, $uid]);
            }
            // Update session with new name parts and formatted display name
            $_SESSION['first_name']  = $first_name;
            $_SESSION['middle_name'] = $middle_name ?? '';
            $_SESSION['last_name']   = $last_name;
            $_SESSION['full_name']   = formatDisplayName([
                'first_name'  => $first_name,
                'middle_name' => $middle_name ?? '',
                'last_name'   => $last_name,
            ]);
            $_SESSION['profile_pic'] = $pic_path;
            logAudit($pdo, $uid, 'Profile Update', 'Updated profile info.');
            flashMessage('success', 'Profile updated successfully.');
        }
    } elseif ($action === 'change_password') {
        $new_pw  = $_POST['new_password'] ?? '';
        $confirm = $_POST['confirm_password'] ?? '';

        if (strlen($new_pw) < 8) {
            flashMessage('danger', 'New password must be at least 8 characters.');
        } elseif (!preg_match('/[0-9]/', $new_pw)) {
            flashMessage('danger', 'New password must contain at least one number.');
        } elseif (!preg_match('/[^a-zA-Z0-9]/', $new_pw)) {
            flashMessage('danger', 'New password must contain at least one special character (e.g. @, #, !).');
        } elseif (!preg_match('/[a-z]/', $new_pw) || !preg_match('/[A-Z]/', $new_pw)) {
            flashMessage('danger', 'New password must contain both uppercase and lowercase letters.');
        } elseif ($new_pw !== $confirm) {
            flashMessage('danger', 'Passwords do not match.');
        } else {
            $hash = password_hash($new_pw, PASSWORD_DEFAULT);
            $pdo->prepare("UPDATE users SET password = ? WHERE user_id = ?")->execute([$hash, $uid]);
            try {
                $pdo->prepare("DELETE FROM password_resets WHERE user_id = ?")->execute([$uid]);
            } catch (\Exception $e) {}
            logAudit($pdo, $uid, 'Password Change', 'User changed their password.');
            flashMessage('success', 'Password changed successfully.');
        }
    }
    echo "<script>window.location.href='index.php?page=profile';</script>";
    exit;
}

// Build initials from first_name + last_name parts
$initials = strtoupper(substr($user['first_name'] ?? 'U', 0, 1) . substr($user['last_name'] ?? '', 0, 1));
try { $since = (new DateTime($user['created_at'] ?? 'now'))->format('M Y'); }
catch(Exception $e) { $since = '-'; }

// Fetch campus name
$campus_name = '';
if (!empty($user['campus_id'])) {
    $cs = $pdo->prepare("SELECT campus_name FROM campuses WHERE campus_id=?");
    $cs->execute([$user['campus_id']]);
    $campus_name = $cs->fetchColumn() ?: '';
}

// Only faculty sees application stats
$latest = null;
$total_apps = $reclassified = $pending_review = 0;
if ($_SESSION['role'] === 'faculty') {
    $app_stats = $pdo->prepare("SELECT status, COUNT(*) as cnt FROM applications WHERE user_id=? GROUP BY status");
    $app_stats->execute([$uid]);
    $stats_raw      = $app_stats->fetchAll(PDO::FETCH_KEY_PAIR);
    $total_apps     = array_sum($stats_raw);
    $reclassified   = $stats_raw['reclassified'] ?? 0;
    $pending_review = ($stats_raw['submitted'] ?? 0) + ($stats_raw['under_review'] ?? 0);

    $latest_app = $pdo->prepare("SELECT a.status, a.total_score, a.weighted_score, a.application_id, c.cycle_name FROM applications a LEFT JOIN cycles c ON a.cycle_id=c.cycle_id WHERE a.user_id=? ORDER BY a.created_at DESC LIMIT 1");
    $latest_app->execute([$uid]);
    $latest = $latest_app->fetch();
}

$education = [];
$employment = ['current' => null, 'previous' => null];
if ($_SESSION['role'] === 'faculty') {
    try {
        $ed = $pdo->prepare("SELECT * FROM faculty_education WHERE user_id=? ORDER BY FIELD(level,'Bachelor','Master','Doctorate','PostDoctorate'), id");
        $ed->execute([$uid]);
        $education = $ed->fetchAll();

        $emp = $pdo->prepare("SELECT * FROM faculty_employment WHERE user_id=? ORDER BY FIELD(type,'current','previous'), id");
        $emp->execute([$uid]);
        foreach ($emp->fetchAll() as $row) {
            $employment[$row['type']] = $row;
        }
    } catch (\Exception $e) {}
}
$pfDate = fn($d) => !empty($d) ? date('M d, Y', strtotime($d)) : 'N/A';
$pfVal = fn($v) => htmlspecialchars(trim((string)$v) !== '' ? (string)$v : 'N/A');
$education_levels = [
    'Bachelor' => 'Undergrad',
    'Master' => 'Masters',
    'Doctorate' => 'Doctorate',
    'PostDoctorate' => 'Post-doctorate',
];
$education_by_level = [];
foreach ($education as $ed_row) {
    $education_by_level[$ed_row['level']] = $ed_row;
}
