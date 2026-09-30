<?php

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
                $abs_avatars = realpath(__DIR__ . '/../uploads/avatars/');
                if (!$abs_avatars) {
                    mkdir(__DIR__ . '/../uploads/avatars/', 0755, true);
                    $abs_avatars = realpath(__DIR__ . '/../uploads/avatars/');
                }
                move_uploaded_file($_FILES['profile_pic']['tmp_name'], $abs_avatars . DIRECTORY_SEPARATOR . $filename);
                // Delete old avatar
                if ($pic_path) {
                    $old_abs = realpath(__DIR__ . '/../') . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $pic_path);
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
?>

<?php showFlash(); ?>

<div class="profile-page">

    <!-- -- Banner -- -->
    <div style="width:100%;height:160px;border-radius:14px 14px 0 0;overflow:hidden;position:relative;background:linear-gradient(135deg,#0f2952 0%,#1a3a6b 35%,#1e4d8c 65%,#1a5276 100%);">
        <!-- Subtle pattern overlay -->
        <div style="position:absolute;inset:0;opacity:0.12;background-image:
            radial-gradient(circle at 20% 50%,#fff 1px,transparent 1px),
            radial-gradient(circle at 80% 20%,#fff 1px,transparent 1px),
            radial-gradient(circle at 60% 80%,#fff 1px,transparent 1px);
            background-size:40px 40px,60px 60px,50px 50px;"></div>
        <div style="position:absolute;inset:0;background:linear-gradient(to bottom,rgba(0,0,0,0.0) 0%,rgba(30,77,140,0.45) 100%);"></div>
    </div>

    <!-- -- Avatar + Identity Row -- -->
    <div style="background:#fff;padding:0 2rem 1.25rem;display:flex;align-items:flex-end;gap:1.5rem;border-bottom:1px solid #e8edf5;flex-wrap:wrap;">
        <div style="flex-shrink:0;margin-top:-55px;position:relative;z-index:2;">
            <?php if (!empty($user['profile_pic'])): ?>
              <img class="profile-avatar" id="profileAvatarImg" src="<?= sanitize($user['profile_pic']) ?>" alt="Profile"
                  style="width:120px;height:120px;border-radius:50%;object-fit:cover;object-position:center;border:4px solid #fff;box-shadow:0 4px 20px rgba(0,0,0,0.18);display:block;">
            <?php elseif ($_SESSION['role'] === 'admin'): ?>
              <img src="assets/images/logo.jpg" alt="SUCFRMS Logo"
                  style="width:120px;height:120px;border-radius:50%;object-fit:cover;object-position:center;border:4px solid #fff;box-shadow:0 4px 20px rgba(0,0,0,0.18);display:block;clip-path:circle(50%);">
            <?php else: ?>
            <div class="profile-avatar-placeholder" id="profileAvatarPlaceholder" style="width:120px;height:120px;border-radius:50%;border:4px solid #fff;background:linear-gradient(135deg,#1a3a6b,#2563b0);display:flex;align-items:center;justify-content:center;box-shadow:0 4px 20px rgba(0,0,0,0.18);">
                <span style="color:#fff;font-size:2.5rem;font-weight:700;letter-spacing:2px;"><?= htmlspecialchars($initials) ?></span>
            </div>
            <?php endif; ?>

            <?php if ($_SESSION['role'] !== 'admin'): ?>
            <!-- Camera overlay -->
            <button type="button" onclick="document.getElementById('avatarFileInput').click()"
                    title="Change profile picture"
                    style="position:absolute;bottom:6px;right:6px;
                           width:32px;height:32px;border-radius:50%;
                           background:#1e4d8c;border:2px solid #fff;
                           color:#fff;cursor:pointer;
                           display:flex;align-items:center;justify-content:center;
                           box-shadow:0 2px 8px rgba(0,0,0,0.25);
                           transition:background 0.15s;z-index:3;"
                    onmouseover="this.style.background='#1a3a6b'"
                    onmouseout="this.style.background='#1e4d8c'">
                <i class="bi bi-camera-fill" style="font-size:0.78rem;line-height:1;"></i>
            </button>
            <!-- Hidden instant-upload form -->
            <form id="avatarUploadForm" method="POST" action="index.php?page=profile" enctype="multipart/form-data" style="display:none;">
                <input type="hidden" name="action"      value="update_info">
                <input type="hidden" name="first_name"  value="<?= htmlspecialchars($user['first_name']  ?? '') ?>">
                <input type="hidden" name="middle_name" value="<?= htmlspecialchars($user['middle_name'] ?? '') ?>">
                <input type="hidden" name="last_name"   value="<?= htmlspecialchars($user['last_name']   ?? '') ?>">
                <input type="hidden" name="suffix"      value="<?= htmlspecialchars($user['suffix']      ?? '') ?>">
                <input type="hidden" name="employee_id" value="<?= htmlspecialchars($user['employee_id'] ?? '') ?>">
                <input type="hidden" name="campus_id"   value="<?= htmlspecialchars($user['campus_id']   ?? '') ?>">
                <input type="hidden" name="rank"        value="<?= htmlspecialchars($user['rank']        ?? '') ?>">
                <input type="hidden" name="faculty_status" value="<?= htmlspecialchars($user['faculty_status'] ?? 'New Faculty') ?>">
                <input type="hidden" name="applied_first_cycle" value="<?= !empty($user['applied_first_cycle']) ? '1' : '0' ?>">
                <input type="file"   id="avatarFileInput" name="profile_pic" accept=".jpg,.jpeg,.png,.gif,.webp"
                       onchange="previewAndUploadAvatar(this)">
            </form>
            <?php endif; ?>
        </div>

        <div style="flex:1;padding-bottom:0.5rem;padding-top:0.5rem;">
            <div style="display:flex;align-items:flex-end;justify-content:space-between;flex-wrap:wrap;gap:1rem;">
                <div>
                    <?php
                    $display_first  = trim($user['first_name']  ?? '');
                    $display_middle = trim($user['middle_name'] ?? '');
                    $display_last   = trim($user['last_name']   ?? '');
                    $display_mi     = $display_middle !== '' ? ' ' . strtoupper(substr($display_middle, 0, 1)) . '.' : '';
                    $display_name   = $display_first !== '' || $display_last !== ''
                        ? trim($display_first . $display_mi . ' ' . $display_last)
                        : htmlspecialchars($user['full_name'] ?? '');
                    ?>
                    <h2 style="font-size:1.5rem;font-weight:700;color:#0f172a;margin:0 0 0.2rem;letter-spacing:-0.01em;"><?= htmlspecialchars($display_name) ?></h2>
                    <p style="font-size:0.85rem;color:#64748b;margin:0 0 0.5rem;"><?= htmlspecialchars($user['email']) ?></p>
                    <div style="display:flex;flex-wrap:wrap;gap:0.5rem;align-items:center;">
                        <span class="badge <?= $role === 'admin' ? 'bg-warning text-dark' : ($role === 'checker' ? 'bg-success' : 'bg-primary') ?>"><?= ucfirst($_SESSION['role']) ?></span>
                        <?php if ($campus_name): ?>
                        <span style="font-size:0.78rem;color:#475569;background:#f1f5f9;padding:0.2rem 0.6rem;border-radius:20px;border:1px solid #e2e8f0;">
                            <i class="bi bi-building me-1"></i><?= htmlspecialchars($campus_name) ?>
                        </span>
                        <?php endif; ?>
                        <?php if (!empty($user['rank'])): ?>
                        <span style="font-size:0.78rem;color:#475569;background:#f1f5f9;padding:0.2rem 0.6rem;border-radius:20px;border:1px solid #e2e8f0;">
                            <i class="bi bi-award me-1"></i><?= htmlspecialchars($user['rank']) ?>
                        </span>
                        <?php endif; ?>
                        <span style="font-size:0.74rem;color:#16a34a;background:#f0fdf4;padding:0.18rem 0.55rem;border-radius:20px;border:1px solid #bbf7d0;font-weight:600;">
                            <i class="bi bi-circle-fill" style="font-size:0.4rem;vertical-align:middle;margin-right:3px;"></i>Active
                        </span>
                    </div>
                </div>
                <!-- Settings icon button -->
                <button onclick="document.getElementById('pf-edit-modal').style.display='flex'"
                        title="Account Settings"
                        style="width:38px;height:38px;border-radius:8px;
                               background:#f1f5f9;color:#475569;border:1px solid #e2e8f0;
                               display:flex;align-items:center;justify-content:center;
                               cursor:pointer;flex-shrink:0;transition:all 0.15s;"
                        onmouseover="this.style.background='#1e4d8c';this.style.color='#fff';this.style.borderColor='#1e4d8c'"
                        onmouseout="this.style.background='#f1f5f9';this.style.color='#475569';this.style.borderColor='#e2e8f0'">
                    <i class="bi bi-gear-fill" style="font-size:1rem;"></i>
                </button>
            </div>
        </div>
    </div>

    <!-- Stats + Latest App -->
    <div style="background:#fff;padding:1.25rem 2rem 1.5rem;border-bottom:1px solid #e8edf5;">

        <div style="display:flex;flex-wrap:wrap;gap:0.75rem;margin-bottom:0.85rem;">

            <div style="display:flex;align-items:center;gap:0.6rem;background:#f8fafc;border:1px solid #e8edf5;border-radius:10px;padding:0.65rem 1rem;flex:1;min-width:140px;">
                <div style="width:34px;height:34px;border-radius:8px;background:#eff6ff;display:flex;align-items:center;justify-content:center;flex-shrink:0;">
                    <i class="bi bi-person-badge" style="color:#1e4d8c;font-size:0.95rem;"></i>
                </div>
                <div>
                    <div style="font-size:0.62rem;color:#94a3b8;font-weight:700;text-transform:uppercase;letter-spacing:0.05em;">Employee ID</div>
                    <div style="font-size:0.92rem;font-weight:700;color:#0f172a;margin-top:1px;"><?= htmlspecialchars($user['employee_id'] ?? ' - ') ?></div>
                </div>
            </div>

            <div style="display:flex;align-items:center;gap:0.6rem;background:#f8fafc;border:1px solid #e8edf5;border-radius:10px;padding:0.65rem 1rem;flex:1;min-width:140px;">
                <div style="width:34px;height:34px;border-radius:8px;background:#eff6ff;display:flex;align-items:center;justify-content:center;flex-shrink:0;">
                    <i class="bi bi-calendar-check" style="color:#1e4d8c;font-size:0.95rem;"></i>
                </div>
                <div>
                    <div style="font-size:0.62rem;color:#94a3b8;font-weight:700;text-transform:uppercase;letter-spacing:0.05em;">Member Since</div>
                    <div style="font-size:0.92rem;font-weight:700;color:#0f172a;margin-top:1px;"><?= $since ?></div>
                </div>
            </div>

            <?php if ($_SESSION['role'] === 'faculty'): ?>
            <div style="display:flex;align-items:center;gap:0.6rem;background:#f8fafc;border:1px solid #e8edf5;border-radius:10px;padding:0.65rem 1rem;flex:1;min-width:140px;">
                <div style="width:34px;height:34px;border-radius:8px;background:<?= ($latest && $latest['weighted_score'] >= 41) ? '#f0fdf4' : '#eff6ff' ?>;display:flex;align-items:center;justify-content:center;flex-shrink:0;">
                    <i class="bi bi-bar-chart-fill" style="color:<?= ($latest && $latest['weighted_score'] >= 41) ? '#16a34a' : '#1e4d8c' ?>;font-size:0.95rem;"></i>
                </div>
                <div>
                    <div style="font-size:0.62rem;color:#94a3b8;font-weight:700;text-transform:uppercase;letter-spacing:0.05em;">Weighted Score</div>
                    <?php if ($latest && $latest['weighted_score'] > 0): ?>
                    <div style="font-size:1.1rem;font-weight:800;color:#1a3a6b;margin-top:1px;line-height:1.1;">
                        <?= number_format($latest['weighted_score'], 2) ?>
                        <span style="font-size:0.62rem;color:#94a3b8;font-weight:500;"> /100</span>
                    </div>
                    <?php else: ?>
                    <div style="font-size:0.82rem;color:#cbd5e1;font-weight:600;margin-top:1px;">No data yet</div>
                    <?php endif; ?>
                </div>
            </div>
            <?php endif; ?>

        </div>

        <?php if ($_SESSION['role'] === 'faculty' && $latest): ?>
        <div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:0.75rem;
                    background:#f8fafc;border:1px solid #e8edf5;border-left:3px solid #1e4d8c;
                    border-radius:10px;padding:0.7rem 1rem;">
            <div style="display:flex;align-items:center;gap:0.65rem;">
                <i class="bi bi-file-earmark-text" style="color:#1e4d8c;font-size:1rem;flex-shrink:0;"></i>
                <div>
                    <div style="font-size:0.62rem;color:#94a3b8;font-weight:700;text-transform:uppercase;letter-spacing:0.05em;">Latest Application</div>
                    <div style="font-size:0.85rem;font-weight:600;color:#1e293b;margin-top:1px;"><?= htmlspecialchars($latest['cycle_name'] ?? ' - ') ?></div>
                </div>
            </div>
            <div><?= statusBadge($latest['status']) ?></div>
        </div>
        <?php endif; ?>

    </div>

    <?php if ($_SESSION['role'] === 'faculty'): ?>
    <div style="background:#fff;padding:1.25rem 2rem 1.5rem;border-bottom:1px solid #e8edf5;">
        <div style="display:flex;align-items:center;justify-content:space-between;gap:1rem;flex-wrap:wrap;margin-bottom:0.9rem;">
            <div>
                <div style="font-weight:700;color:#1a3a6b;font-size:0.95rem;">Faculty Details</div>
                <div style="font-size:0.72rem;color:#94a3b8;">Education and employment information from your profile entry</div>
            </div>
        </div>

        <div style="display:flex;gap:0.5rem;margin-bottom:1rem;overflow-x:auto;">
            <button type="button" class="pf-detail-tab active" data-tab="edu" onclick="pfDetailTab('edu')"
                    style="border:1px solid #1a3a6b;background:#1a3a6b;color:#fff;border-radius:8px;padding:0.45rem 0.85rem;font-size:0.78rem;font-weight:700;white-space:nowrap;">
                Educational Attainment
            </button>
            <button type="button" class="pf-detail-tab" data-tab="emp" onclick="pfDetailTab('emp')"
                    style="border:1px solid #e2e8f0;background:#f8fafc;color:#475569;border-radius:8px;padding:0.45rem 0.85rem;font-size:0.78rem;font-weight:700;white-space:nowrap;">
                Employment History
            </button>
        </div>

        <div id="pf-detail-edu">
            <div style="background:#f8fafc;border:1px solid #e8edf5;border-radius:10px;padding:1rem;">
                <?php foreach ($education_levels as $level_key => $level_label):
                    $ed = $education_by_level[$level_key] ?? [];
                ?>
                <div class="pf-education-line">
                    <div class="pf-education-level"><?= htmlspecialchars($level_label) ?></div>
                    <div class="pf-education-fields">
                        <div><span>Name of Degree</span><strong><?= $pfVal($ed['degree_program'] ?? '') ?></strong></div>
                        <div><span>Name of SUC</span><strong><?= $pfVal($ed['school_university'] ?? '') ?></strong></div>
                        <div><span>Year Graduated</span><strong><?= $pfVal($ed['year_graduated'] ?? '') ?></strong></div>
                    </div>
                </div>
                <?php endforeach; ?>
                <div class="pf-detail-row" style="border-bottom:0;margin-top:0.35rem;">
                    <span>Applied during 1st Cycle</span>
                    <strong><?= !empty($user['applied_first_cycle']) ? 'YES' : 'NO' ?></strong>
                </div>
            </div>
        </div>

        <div id="pf-detail-emp" style="display:none;">
            <div class="pf-detail-grid">
                <?php foreach ([['CURRENT EMPLOYMENT',$employment['current'] ?? []],['PREVIOUS EMPLOYMENT',$employment['previous'] ?? []]] as [$heading, $row]): ?>
                <div style="background:#f8fafc;border:1px solid #e8edf5;border-radius:10px;padding:1rem;">
                    <div style="font-size:0.68rem;font-weight:800;color:#94a3b8;text-transform:uppercase;letter-spacing:0.08em;border-bottom:1px solid #e8edf5;padding-bottom:0.5rem;margin-bottom:0.2rem;"><?= $heading ?></div>
                    <?php
                    $items = $heading === 'CURRENT EMPLOYMENT'
                        ? [['Faculty Rank',$row['rank'] ?? ''],['Mode of Appointment',$row['mode_of_appointment'] ?? ''],['Date of Appointment',$pfDate($row['date_of_appointment'] ?? '')],['SUC',$row['suc'] ?? ''],['Campus',$row['campus'] ?? ''],['Address',$row['address'] ?? '']]
                        : [['Previous Rank',$row['rank'] ?? ''],['Mode of Appointment',$row['mode_of_appointment'] ?? ''],['Date of Appointment',$pfDate($row['date_of_appointment'] ?? '')],['Employment Sector',$row['employment_sector'] ?? ''],['SUC',$row['suc'] ?? ''],['Campus',$row['campus'] ?? ''],['Address',$row['address'] ?? '']];
                    foreach ($items as [$label, $value]):
                    ?>
                    <div class="pf-detail-row"><span><?= $label ?></span><strong><?= $pfVal($value) ?></strong></div>
                    <?php endforeach; ?>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
    <?php endif; ?>

</div><!-- /profile-page -->


<!-- -- Edit Profile Modal -- -->
<div id="pf-edit-modal"
     style="display:none;position:fixed;inset:0;background:rgba(0,0,0,0.45);z-index:9999;align-items:center;justify-content:center;padding:1rem;">
    <div style="background:#fff;border-radius:12px;width:100%;max-width:640px;max-height:90vh;overflow-y:auto;box-shadow:0 20px 60px rgba(0,0,0,0.25);">

        <div style="display:flex;justify-content:space-between;align-items:center;padding:1.25rem 1.5rem;border-bottom:1px solid #e2e8f0;">
            <h5 style="margin:0;font-weight:700;color:#1e293b;">Edit Profile</h5>
            <button onclick="document.getElementById('pf-edit-modal').style.display='none'"
                    style="background:none;border:none;font-size:1.25rem;cursor:pointer;color:#64748b;">&times;</button>
        </div>

        <div style="display:flex;border-bottom:2px solid #e2e8f0;padding:0 1.5rem;">
            <button class="pf-modal-tab active" onclick="pfModalTab(this,'pf-mt-info')"
                    style="background:none;border:none;border-bottom:2px solid #1e4d8c;margin-bottom:-2px;padding:0.65rem 1rem;font-size:0.875rem;font-weight:600;color:#1a3a6b;cursor:pointer;">
                Account Settings
            </button>
            <button class="pf-modal-tab" onclick="pfModalTab(this,'pf-mt-pw')"
                    style="background:none;border:none;border-bottom:2px solid transparent;margin-bottom:-2px;padding:0.65rem 1rem;font-size:0.875rem;font-weight:500;color:#64748b;cursor:pointer;">
                Change Password
            </button>
        </div>

        <div id="pf-mt-info" style="padding:1.5rem;">
            <form method="POST" action="index.php?page=profile" enctype="multipart/form-data">
                <input type="hidden" name="action" value="update_info">
                <?php if ($_SESSION['role'] !== 'admin'): ?>
                <!-- Profile picture is changed via the camera icon on the avatar -->
                <?php endif; ?>
                <div class="row g-3">
                    <div class="col-md-4">
                        <label class="form-label">First Name <span class="text-danger">*</span></label>
                        <input type="text" name="first_name" class="form-control"
                               value="<?= htmlspecialchars($user['first_name'] ?? '') ?>" required>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Middle Name</label>
                        <input type="text" name="middle_name" class="form-control"
                               value="<?= htmlspecialchars($user['middle_name'] ?? '') ?>"
                               placeholder="Leave blank if none">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Last Name <span class="text-danger">*</span></label>
                        <input type="text" name="last_name" class="form-control"
                               value="<?= htmlspecialchars($user['last_name'] ?? '') ?>" required>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Suffix</label>
                        <input type="text" name="suffix" class="form-control"
                               value="<?= htmlspecialchars($user['suffix'] ?? '') ?>"
                               placeholder="Optional">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Email</label>
                        <div class="input-group">
                            <span class="input-group-text"><i class="bi bi-lock-fill"></i></span>
                            <input type="email" class="form-control" value="<?= htmlspecialchars($user['email']) ?>" readonly>
                        </div>
                        <div style="font-size:0.72rem;color:#94a3b8;margin-top:4px;">Email can only be changed by the Administrator.</div>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Employee ID</label>
                        <?php if ($_SESSION['role'] === 'faculty'): ?>
                        <input type="text" name="employee_id" class="form-control" value="<?= htmlspecialchars($user['employee_id'] ?? '') ?>">
                        <?php else: ?>
                        <input type="text" class="form-control" value="<?= htmlspecialchars($user['employee_id'] ?? '') ?>" disabled>
                        <?php endif; ?>
                    </div>
                    <?php if ($_SESSION['role'] !== 'admin'): ?>
                    <div class="col-md-6">
                        <label class="form-label">Campus</label>
                        <select name="campus_id" class="form-select">
                            <option value="" disabled <?= empty($user['campus_id']) ? 'selected' : '' ?>>- Select Campus -</option>
                            <?php
                            $campuses = $pdo->query("SELECT * FROM campuses WHERE is_active=1 ORDER BY campus_name")->fetchAll();
                            foreach ($campuses as $c):
                            ?>
                            <option value="<?= $c['campus_id'] ?>" <?= ($user['campus_id'] ?? 0) == $c['campus_id'] ? 'selected' : '' ?>>
                                <?= sanitize($c['campus_name']) ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Faculty Rank</label>
                        <select name="rank" class="form-select">
                            <option value="" disabled <?= empty($user['rank']) ? 'selected' : '' ?>>- Select Rank -</option>
                            <?php foreach (facultyRanks() as $r): ?>
                            <option value="<?= $r ?>" <?= ($user['rank'] ?? '') === $r ? 'selected' : '' ?>><?= $r ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <?php if ($_SESSION['role'] === 'faculty'): ?>
                    <div class="col-md-6">
                        <label class="form-label">Faculty Status</label>
                        <select name="faculty_status" class="form-select">
                            <option value="Existing Faculty" <?= ($user['faculty_status'] ?? '') === 'Existing Faculty' ? 'selected' : '' ?>>Existing Faculty</option>
                            <option value="New Faculty" <?= ($user['faculty_status'] ?? 'New Faculty') === 'New Faculty' ? 'selected' : '' ?>>New Faculty</option>
                        </select>
                    </div>
                    <div class="col-12">
                        <div style="border-top:1px solid #e8edf5;margin-top:0.4rem;padding-top:1rem;">
                            <div style="font-size:0.72rem;font-weight:700;color:#475569;text-transform:uppercase;letter-spacing:0.06em;margin-bottom:0.75rem;">Educational Attainment</div>
                            <div style="display:flex;flex-direction:column;gap:0.75rem;">
                                <?php foreach ($education_levels as $level_key => $level_label):
                                    $ed = $education_by_level[$level_key] ?? [];
                                    $is_required = $level_key === 'Bachelor';
                                ?>
                                <div style="background:#f8fafc;border:1px solid #e8edf5;border-radius:8px;padding:0.85rem;">
                                    <input type="hidden" name="edu_level[]" value="<?= htmlspecialchars($level_key) ?>">
                                    <div style="font-size:0.82rem;font-weight:800;color:#1a3a6b;margin-bottom:0.6rem;"><?= htmlspecialchars($level_label) ?></div>
                                    <div class="pf-edu-edit-grid">
                                        <div>
                                            <label class="form-label">Name of Degree<?= $is_required ? ' *' : '' ?></label>
                                            <input name="edu_degree[]" class="form-control" value="<?= htmlspecialchars($ed['degree_program'] ?? '') ?>" placeholder="<?= $is_required ? 'Bachelor of Science in...' : 'N/A if none' ?>" <?= $is_required ? 'required' : '' ?>>
                                        </div>
                                        <div>
                                            <label class="form-label">Name of SUC<?= $is_required ? ' *' : '' ?></label>
                                            <input name="edu_school[]" class="form-control" value="<?= htmlspecialchars($ed['school_university'] ?? '') ?>" placeholder="<?= $is_required ? 'School / University' : 'N/A if none' ?>" <?= $is_required ? 'required' : '' ?>>
                                        </div>
                                        <div>
                                            <label class="form-label">Year Graduated<?= $is_required ? ' *' : '' ?></label>
                                            <input name="edu_year[]" class="form-control" value="<?= htmlspecialchars($ed['year_graduated'] ?? '') ?>" placeholder="<?= $is_required ? 'YYYY' : 'N/A' ?>" <?= $is_required ? 'required' : '' ?>>
                                        </div>
                                    </div>
                                </div>
                                <?php endforeach; ?>
                            </div>
                            <div style="margin-top:0.85rem;">
                                <label class="form-label">Applied during 1st Cycle</label>
                                <select name="applied_first_cycle" class="form-select" style="max-width:220px;">
                                    <option value="0" <?= empty($user['applied_first_cycle']) ? 'selected' : '' ?>>NO</option>
                                    <option value="1" <?= !empty($user['applied_first_cycle']) ? 'selected' : '' ?>>YES</option>
                                </select>
                            </div>
                        </div>
                    </div>
                    <?php endif; ?>
                    <?php endif; ?>
                </div>
                <div class="mt-4 d-flex gap-2">
                    <button type="submit" class="btn btn-primary">Save Changes</button>
                    <button type="button" onclick="document.getElementById('pf-edit-modal').style.display='none'" class="btn btn-outline-secondary">Cancel</button>
                </div>
            </form>
        </div>

        <div id="pf-mt-pw" style="display:none;padding:1.5rem;">
            <form method="POST" action="index.php?page=profile">
                <input type="hidden" name="action" value="change_password">
                <div class="row g-3">
                    <div class="col-md-6">
                        <label class="form-label">New Password</label>
                        <div class="input-group">
                            <input type="password" name="new_password" id="pfNewPassword" class="form-control"
                                   placeholder="e.g. Anasue@123" required
                                   oninput="pfCheckPw(this.value)">
                            <button type="button" class="btn btn-outline-secondary" onclick="pfTogglePw('pfNewPassword', this)"
                                    tabindex="-1" style="border-color:#dee2e6;">
                                <i class="bi bi-eye"></i>
                            </button>
                        </div>
                        <div style="margin-top:5px;display:flex;gap:3px;">
                            <div class="pf-pw-bar" style="flex:1;height:3px;border-radius:2px;background:#e2e8f0;transition:background 0.3s;"></div>
                            <div class="pf-pw-bar" style="flex:1;height:3px;border-radius:2px;background:#e2e8f0;transition:background 0.3s;"></div>
                            <div class="pf-pw-bar" style="flex:1;height:3px;border-radius:2px;background:#e2e8f0;transition:background 0.3s;"></div>
                            <div class="pf-pw-bar" style="flex:1;height:3px;border-radius:2px;background:#e2e8f0;transition:background 0.3s;"></div>
                        </div>
                        <ul style="margin:5px 0 0;padding-left:1.1rem;font-size:0.72rem;color:#94a3b8;line-height:1.7;">
                            <li id="pf-req-len"  >At least 8 characters</li>
                            <li id="pf-req-num"  >A number</li>
                            <li id="pf-req-sym"  >A special character (e.g. @, #, !)</li>
                            <li id="pf-req-case" >Both uppercase and lowercase letters</li>
                        </ul>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Confirm New Password</label>
                        <div class="input-group">
                            <input type="password" name="confirm_password" id="pfConfirmPassword" class="form-control" required>
                            <button type="button" class="btn btn-outline-secondary" onclick="pfTogglePw('pfConfirmPassword', this)"
                                    tabindex="-1" style="border-color:#dee2e6;">
                                <i class="bi bi-eye"></i>
                            </button>
                        </div>
                    </div>
                </div>
                <div class="mt-4 d-flex gap-2">
                    <button type="submit" class="btn btn-primary">Update Password</button>
                    <button type="button" onclick="document.getElementById('pf-edit-modal').style.display='none'" class="btn btn-outline-secondary">Cancel</button>
                </div>
            </form>
        </div>

    </div>
</div>

<style>
.pf-detail-grid {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 1rem;
}
.pf-detail-row {
    display: flex;
    justify-content: space-between;
    gap: 1rem;
    border-bottom: 1px solid #e8edf5;
    padding: 0.52rem 0;
    font-size: 0.82rem;
}
.pf-detail-row span {
    color: #94a3b8;
    flex-shrink: 0;
}
.pf-detail-row strong {
    color: #1e293b;
    font-weight: 600;
    text-align: right;
    overflow-wrap: anywhere;
}
.pf-education-line {
    display: grid;
    grid-template-columns: 150px 1fr;
    gap: 1rem;
    padding: 0.75rem 0;
    border-bottom: 1px solid #e8edf5;
}
.pf-education-level {
    color: #1e293b;
    font-weight: 800;
    font-size: 0.86rem;
}
.pf-education-fields,
.pf-edu-edit-grid {
    display: grid;
    grid-template-columns: 1.3fr 1.3fr 0.8fr;
    gap: 0.75rem;
}
.pf-education-fields span {
    display: block;
    color: #94a3b8;
    font-size: 0.76rem;
    margin-bottom: 0.2rem;
}
.pf-education-fields strong {
    display: block;
    color: #1e293b;
    font-size: 0.84rem;
    font-weight: 600;
    overflow-wrap: anywhere;
}
@media (max-width: 768px) {
    .pf-detail-grid { grid-template-columns: 1fr; }
    .pf-detail-row strong { max-width: 58%; }
    .pf-education-line { grid-template-columns: 1fr; gap: 0.45rem; }
    .pf-education-fields,
    .pf-edu-edit-grid { grid-template-columns: 1fr; }
}
</style>

<script>
document.addEventListener('DOMContentLoaded', function () {
    if (sessionStorage.getItem('openPwTab') === '1') {
        sessionStorage.removeItem('openPwTab');
        const modal = document.getElementById('pf-edit-modal');
        if (modal) {
            modal.style.display = 'flex';
            const pwTab = document.querySelector('.pf-modal-tab:nth-child(2)');
            if (pwTab) pwTab.click();
        }
    }
});

function pfTogglePw(inputId, btn) {
    const input = document.getElementById(inputId);
    const icon  = btn.querySelector('i');
    if (input.type === 'password') {
        input.type = 'text';
        icon.classList.replace('bi-eye', 'bi-eye-slash');
    } else {
        input.type = 'password';
        icon.classList.replace('bi-eye-slash', 'bi-eye');
    }
}

function pfCheckPw(val) {
    const bars    = document.querySelectorAll('.pf-pw-bar');
    const hasLen  = val.length >= 8;
    const hasNum  = /[0-9]/.test(val);
    const hasSym  = /[^a-zA-Z0-9]/.test(val);
    const hasLow  = /[a-z]/.test(val);
    const hasUpp  = /[A-Z]/.test(val);
    const hasCase = hasLow && hasUpp;

    const mark = (id, met) => {
        const el = document.getElementById(id);
        if (!el) return;
        el.style.color = met ? '#1e4d8c' : '#94a3b8';
        el.style.fontWeight = met ? '600' : 'normal';
    };
    mark('pf-req-len',  hasLen);
    mark('pf-req-num',  hasNum);
    mark('pf-req-sym',  hasSym);
    mark('pf-req-case', hasCase);

    let score = [hasLen, hasNum, hasSym, hasCase].filter(Boolean).length;
    const colors = ['#334155','#475569','#475569','#2563b0'];
    bars.forEach((b, i) => {
        b.style.background = i < score ? colors[score - 1] : '#e2e8f0';
    });
}

function pfModalTab(btn, paneId) {
    document.querySelectorAll('.pf-modal-tab').forEach(t => {
        t.style.borderBottomColor = 'transparent';
        t.style.color = '#64748b';
        t.style.fontWeight = '500';
        t.classList.remove('active');
    });
    document.querySelectorAll('#pf-edit-modal > div > div[id^="pf-mt"]').forEach(p => p.style.display = 'none');
    btn.style.borderBottomColor = '#1e4d8c';
    btn.style.color = '#1a3a6b';
    btn.style.fontWeight = '600';
    btn.classList.add('active');
    document.getElementById(paneId).style.display = 'block';
}

document.getElementById('pf-edit-modal').addEventListener('click', function(e) {
    if (e.target === this) this.style.display = 'none';
});

function pfDetailTab(tab) {
    document.querySelectorAll('.pf-detail-tab').forEach(btn => {
        const active = btn.dataset.tab === tab;
        btn.classList.toggle('active', active);
        btn.style.background = active ? '#1a3a6b' : '#f8fafc';
        btn.style.borderColor = active ? '#1a3a6b' : '#e2e8f0';
        btn.style.color = active ? '#fff' : '#475569';
    });
    const edu = document.getElementById('pf-detail-edu');
    const emp = document.getElementById('pf-detail-emp');
    if (edu) edu.style.display = tab === 'edu' ? 'block' : 'none';
    if (emp) emp.style.display = tab === 'emp' ? 'block' : 'none';
}

function previewAndUploadAvatar(input) {
    if (!input.files || !input.files[0]) return;
    const file = input.files[0];
    // Show instant preview before uploading
    const reader = new FileReader();
    reader.onload = function(e) {
        const img = document.getElementById('profileAvatarImg');
        const placeholder = document.getElementById('profileAvatarPlaceholder');
        if (img) {
            img.src = e.target.result;
        } else if (placeholder) {
            // Replace placeholder div with an img
            const newImg = document.createElement('img');
            newImg.id = 'profileAvatarImg';
            newImg.src = e.target.result;
            newImg.alt = 'Profile';
            newImg.style.cssText = 'width:130px;height:130px;border-radius:50%;object-fit:cover;object-position:center;border:5px solid #fff;box-shadow:0 6px 24px rgba(0,0,0,0.2);display:block;';
            placeholder.replaceWith(newImg);
        }
    };
    reader.readAsDataURL(file);
    // Submit the hidden form
    document.getElementById('avatarUploadForm').submit();
}
</script>

