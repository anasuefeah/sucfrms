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
                $pdo->prepare("UPDATE users SET first_name=?, middle_name=?, last_name=?, suffix=?, employee_id=?, campus_id=?, `rank`=?, faculty_status=?, applied_first_cycle=?, profile_pic=? WHERE user_id=?")
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

    <!-- ── Banner — same hero image as portal ── -->
    <div class="profile-banner" style="width:100%;position:relative;overflow:hidden;background:#0f2952;">
        <img src="assets/images/portal_hero.jpg" alt=""
             style="position:absolute;inset:0;width:100%;height:100%;object-fit:cover;object-position:center 30%;opacity:0.35;">
        <div style="position:absolute;inset:0;background:linear-gradient(to bottom,rgba(15,41,82,0.3) 0%,rgba(15,41,82,0.75) 100%);"></div>
    </div>

    <!-- ── Avatar + Identity Row ── -->
    <div class="profile-identity">
        <div class="profile-identity-avatar">
            <?php if (!empty($user['profile_pic'])): ?>
            <img class="profile-identity-image" id="profileAvatarImg" src="<?= sanitize($user['profile_pic']) ?>" alt="Profile">
            <?php elseif ($_SESSION['role'] === 'admin'): ?>
            <img class="profile-identity-image" src="assets/images/logo.jpg" alt="SUCFRMS Logo">
            <?php else: ?>
            <div class="profile-avatar-placeholder profile-identity-image" id="profileAvatarPlaceholder" style="background:#1a3a6b;">
                <span style="color:#fff;font-size:2.25rem;font-weight:700;"><?= htmlspecialchars($initials) ?></span>
            </div>
            <?php endif; ?>

            <?php if ($_SESSION['role'] !== 'admin'): ?>
            <button type="button" class="profile-avatar-control" onclick="document.getElementById('avatarFileInput').click()"
                    title="Change profile picture"
                    style="position:absolute;bottom:5px;right:5px;width:30px;height:30px;border-radius:50%;
                           background:#1a3a6b;border:2px solid #fff;color:#fff;cursor:pointer;
                           display:flex;align-items:center;justify-content:center;z-index:3;">
                <i class="bi bi-camera-fill" style="font-size:0.72rem;"></i>
            </button>
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
                <input type="file" id="avatarFileInput" name="profile_pic" accept=".jpg,.jpeg,.png,.gif,.webp"
                       onchange="previewAndUploadAvatar(this)">
            </form>
            <?php endif; ?>
        </div>

        <div class="profile-identity-content">
            <div style="display:flex;align-items:flex-end;justify-content:space-between;flex-wrap:wrap;gap:1rem;">
                <div class="profile-identity-text">
                    <?php
                    $display_name = trim(formatDisplayName($user) . ' ' . trim($user['suffix'] ?? ''));
                    ?>
                    <h2 style="font-size:1.4rem;font-weight:700;color:#0f172a;margin:0 0 0.2rem;letter-spacing:-0.01em;"><?= htmlspecialchars($display_name) ?></h2>
                    <p style="font-size:0.8rem;color:#94a3b8;margin:0 0 0.5rem;"><?= htmlspecialchars($user['email']) ?></p>
                    <div class="profile-identity-meta">
                        <!-- Role badge — boxy -->
                        <span style="font-size:0.65rem;font-weight:700;letter-spacing:0.05em;padding:2px 8px;
                                     background:<?= $role==='admin'?'#1a3a6b':($role==='evaluator'?'#16a34a':'#1e4d8c') ?>;color:#fff;">
                            <?= strtoupper($_SESSION['role']) ?>
                        </span>
                        <?php if (!isEvaluator() && $campus_name): ?>
                        <span style="font-size:0.72rem;color:#475569;background:#f8fafc;padding:2px 8px;border:1px solid #e2e8f0;display:inline-flex;align-items:center;gap:3px;">
                            <i class="bi bi-building" style="font-size:0.65rem;"></i><?= htmlspecialchars($campus_name) ?>
                        </span>
                        <?php endif; ?>
                        <?php if (!isEvaluator() && !empty($user['rank'])): ?>
                        <span style="font-size:0.72rem;color:#475569;background:#f8fafc;padding:2px 8px;border:1px solid #e2e8f0;display:inline-flex;align-items:center;gap:3px;">
                            <i class="bi bi-award" style="font-size:0.65rem;"></i><?= htmlspecialchars($user['rank']) ?>
                        </span>
                        <?php endif; ?>
                        <span style="font-size:0.65rem;font-weight:700;color:#16a34a;background:#f0fdf4;padding:2px 8px;border:1px solid #bbf7d0;display:inline-flex;align-items:center;gap:3px;">
                            <i class="bi bi-circle-fill" style="font-size:0.4rem;"></i>Active
                        </span>
                    </div>
                </div>
                <!-- Settings button -->
                <button type="button" class="profile-account-settings" onclick="document.getElementById('pf-edit-modal').style.display='flex'"
                        title="Account Settings"
                        style="width:36px;height:36px;background:#f8fafc;color:#475569;border:1px solid #e2e8f0;
                               display:flex;align-items:center;justify-content:center;cursor:pointer;flex-shrink:0;"
                        onmouseover="this.style.background='#1a3a6b';this.style.color='#fff';this.style.borderColor='#1a3a6b'"
                        onmouseout="this.style.background='#f8fafc';this.style.color='#475569';this.style.borderColor='#e2e8f0'">
                    <i class="bi bi-gear-fill" style="font-size:0.9rem;"></i>
                </button>
            </div>
        </div>
    </div>

    <!-- ── Stats + Latest App ── -->
    <div class="profile-overview" style="background:#fff;border-bottom:1px solid #e2e8f0;">
        <div style="display:flex;flex-wrap:wrap;gap:0.65rem;margin-bottom:<?= ($_SESSION['role']==='faculty'&&$latest)?'0.65rem':'0' ?>;">

            <?php if (!isEvaluator()): ?>
            <div style="display:flex;align-items:center;gap:0.6rem;background:#f8fafc;border:1px solid #e2e8f0;padding:0.6rem 1rem;flex:1;min-width:140px;">
                <i class="bi bi-person-badge" style="color:#1a3a6b;font-size:1rem;flex-shrink:0;"></i>
                <div>
                    <div style="font-size:0.58rem;color:#94a3b8;font-weight:700;text-transform:uppercase;letter-spacing:0.06em;">Employee ID</div>
                    <div style="font-size:0.88rem;font-weight:700;color:#0f172a;margin-top:1px;"><?= htmlspecialchars($user['employee_id'] ?? '—') ?></div>
                </div>
            </div>
            <?php endif; ?>

            <div style="display:flex;align-items:center;gap:0.6rem;background:#f8fafc;border:1px solid #e2e8f0;padding:0.6rem 1rem;flex:1;min-width:140px;">
                <i class="bi bi-calendar3" style="color:#1a3a6b;font-size:1rem;flex-shrink:0;"></i>
                <div>
                    <div style="font-size:0.58rem;color:#94a3b8;font-weight:700;text-transform:uppercase;letter-spacing:0.06em;">Member Since</div>
                    <div style="font-size:0.88rem;font-weight:700;color:#0f172a;margin-top:1px;"><?= $since ?></div>
                </div>
            </div>

            <?php if ($_SESSION['role'] === 'faculty'): ?>
            <div style="display:flex;align-items:center;gap:0.6rem;background:#f8fafc;border:1px solid #e2e8f0;padding:0.6rem 1rem;flex:1;min-width:140px;">
                <i class="bi bi-bar-chart-fill" style="color:<?= ($latest&&(float)$latest['weighted_score']>=41)?'#16a34a':'#1a3a6b' ?>;font-size:1rem;flex-shrink:0;"></i>
                <div>
                    <div style="font-size:0.58rem;color:#94a3b8;font-weight:700;text-transform:uppercase;letter-spacing:0.06em;">Weighted Score</div>
                    <?php if ($latest && (float)$latest['weighted_score'] > 0): ?>
                    <div style="font-size:1rem;font-weight:800;color:#1a3a6b;margin-top:1px;line-height:1.1;">
                        <?= number_format($latest['weighted_score'], 2) ?>
                        <span style="font-size:0.58rem;color:#94a3b8;font-weight:500;">/100</span>
                    </div>
                    <?php else: ?>
                    <div style="font-size:0.8rem;color:#cbd5e1;font-weight:600;margin-top:1px;">No data yet</div>
                    <?php endif; ?>
                </div>
            </div>
            <?php endif; ?>

        </div>

        <?php if ($_SESSION['role'] === 'faculty' && $latest): ?>
        <div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:0.75rem;
                    background:#f8fafc;border:1px solid #e2e8f0;border-left:3px solid #1a3a6b;padding:0.65rem 1rem;">
            <div style="display:flex;align-items:center;gap:0.6rem;">
                <i class="bi bi-file-earmark-text" style="color:#1a3a6b;font-size:0.95rem;flex-shrink:0;"></i>
                <div>
                    <div style="font-size:0.58rem;color:#94a3b8;font-weight:700;text-transform:uppercase;letter-spacing:0.06em;">Latest Application</div>
                    <div style="font-size:0.82rem;font-weight:600;color:#1e293b;margin-top:1px;"><?= htmlspecialchars($latest['cycle_name'] ?? '—') ?></div>
                </div>
            </div>
            <?= statusBadge($latest['status']) ?>
        </div>
        <?php endif; ?>
    </div>

    <?php if ($_SESSION['role'] === 'faculty'): ?>
    <div style="background:#fff;padding:1.25rem 2rem 1.5rem;border-bottom:1px solid #e2e8f0;">
        <div style="margin-bottom:0.85rem;">
            <div style="font-weight:700;color:#0f172a;font-size:0.9rem;">Faculty Details</div>
            <div style="font-size:0.7rem;color:#94a3b8;margin-top:1px;">Education and employment information from your profile entry</div>
        </div>

        <div style="display:flex;gap:0.4rem;margin-bottom:1rem;">
            <button type="button" class="pf-detail-tab active" data-tab="edu" onclick="pfDetailTab('edu')"
                    style="border:1px solid #1a3a6b;background:#1a3a6b;color:#fff;padding:0.38rem 0.85rem;font-size:0.75rem;font-weight:700;cursor:pointer;white-space:nowrap;">
                Educational Attainment
            </button>
            <button type="button" class="pf-detail-tab" data-tab="emp" onclick="pfDetailTab('emp')"
                    style="border:1px solid #e2e8f0;background:#f8fafc;color:#475569;padding:0.38rem 0.85rem;font-size:0.75rem;font-weight:700;cursor:pointer;white-space:nowrap;">
                Employment History
            </button>
        </div>

        <div id="pf-detail-edu">
            <div style="background:#f8fafc;border:1px solid #e2e8f0;padding:0.85rem 1rem;">
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
                <div style="background:#f8fafc;border:1px solid #e2e8f0;padding:0.85rem 1rem;">
                    <div style="font-size:0.62rem;font-weight:800;color:#94a3b8;text-transform:uppercase;letter-spacing:0.08em;border-bottom:1px solid #e2e8f0;padding-bottom:0.45rem;margin-bottom:0.2rem;"><?= $heading ?></div>
                    <?php
                    $items = $heading === 'CURRENT EMPLOYMENT'
                        ? [['Faculty Rank',$row['rank']??''],['Mode of Appointment',$row['mode_of_appointment']??''],['Date of Appointment',$pfDate($row['date_of_appointment']??'')],['SUC',$row['suc']??''],['Campus',$row['campus']??''],['Address',$row['address']??'']]
                        : [['Previous Rank',$row['rank']??''],['Mode of Appointment',$row['mode_of_appointment']??''],['Date of Appointment',$pfDate($row['date_of_appointment']??'')],['Employment Sector',$row['employment_sector']??''],['SUC',$row['suc']??''],['Campus',$row['campus']??''],['Address',$row['address']??'']];
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
     style="display:none;position:fixed;inset:0;background:rgba(15,23,42,0.55);z-index:9999;align-items:center;justify-content:center;padding:1rem;">
    <div style="background:#fff;border:1px solid #e5e7eb;width:100%;max-width:620px;max-height:90vh;overflow-y:auto;display:flex;flex-direction:column;">

        <!-- Header -->
        <div style="background:#1a3a6b;padding:0.65rem 1rem;display:flex;align-items:center;justify-content:space-between;flex-shrink:0;">
            <div style="display:flex;align-items:center;gap:0.6rem;">
                <i class="bi bi-person-gear" style="color:rgba(255,255,255,0.75);font-size:0.88rem;"></i>
                <span style="font-size:0.85rem;font-weight:700;color:#fff;">Edit Profile</span>
            </div>
            <button onclick="document.getElementById('pf-edit-modal').style.display='none'"
                    style="background:none;border:none;color:rgba(255,255,255,0.65);font-size:1rem;cursor:pointer;line-height:1;padding:0;"
                    onmouseover="this.style.color='#fff'" onmouseout="this.style.color='rgba(255,255,255,0.65)'">
                <i class="bi bi-x-lg"></i>
            </button>
        </div>

        <!-- Tabs -->
        <div style="display:flex;border-bottom:1px solid #e5e7eb;flex-shrink:0;">
            <button class="pf-modal-tab" id="pf-tab-info" onclick="pfModalTab(this,'pf-mt-info')"
                    style="background:none;border:none;border-bottom:2px solid #1a3a6b;padding:0.55rem 1rem;font-size:0.78rem;font-weight:700;color:#1a3a6b;cursor:pointer;margin-bottom:-1px;">
                Account Settings
            </button>
            <button class="pf-modal-tab" id="pf-tab-pw" onclick="pfModalTab(this,'pf-mt-pw')"
                    style="background:none;border:none;border-bottom:2px solid transparent;padding:0.55rem 1rem;font-size:0.78rem;font-weight:600;color:#9ca3af;cursor:pointer;margin-bottom:-1px;">
                Change Password
            </button>
        </div>

        <!-- Account Settings tab -->
        <div id="pf-mt-info" style="padding:1.1rem 1rem;">
            <form method="POST" action="index.php?page=profile" enctype="multipart/form-data">
                <input type="hidden" name="action" value="update_info">

                <!-- Identity box -->
                <div style="border:1px solid #e5e7eb;overflow:hidden;margin-bottom:0.75rem;">
                    <div style="background:#f9fafb;border-bottom:1px solid #e5e7eb;padding:0.35rem 0.75rem;">
                        <span style="font-size:0.65rem;font-weight:700;text-transform:uppercase;letter-spacing:0.08em;color:#374151;">Identity</span>
                    </div>
                    <div style="padding:0.75rem;display:grid;grid-template-columns:1fr 1fr 1fr;gap:0.6rem;">
                        <div>
                            <label style="display:block;font-size:0.65rem;font-weight:700;text-transform:uppercase;letter-spacing:0.06em;color:#6b7280;margin-bottom:4px;">First Name <span style="color:#dc2626;">*</span></label>
                            <input type="text" name="first_name" required value="<?= htmlspecialchars($user['first_name'] ?? '') ?>"
                                   style="width:100%;border:1px solid #e5e7eb;padding:0.38rem 0.55rem;font-size:0.82rem;box-sizing:border-box;outline:none;"
                                   onfocus="this.style.borderColor='#374151'" onblur="this.style.borderColor='#e5e7eb'">
                        </div>
                        <div>
                            <label style="display:block;font-size:0.65rem;font-weight:700;text-transform:uppercase;letter-spacing:0.06em;color:#6b7280;margin-bottom:4px;">Middle Name</label>
                            <input type="text" name="middle_name" value="<?= htmlspecialchars($user['middle_name'] ?? '') ?>"
                                   placeholder="Leave blank if none"
                                   style="width:100%;border:1px solid #e5e7eb;padding:0.38rem 0.55rem;font-size:0.82rem;box-sizing:border-box;outline:none;"
                                   onfocus="this.style.borderColor='#374151'" onblur="this.style.borderColor='#e5e7eb'">
                        </div>
                        <div>
                            <label style="display:block;font-size:0.65rem;font-weight:700;text-transform:uppercase;letter-spacing:0.06em;color:#6b7280;margin-bottom:4px;">Last Name <span style="color:#dc2626;">*</span></label>
                            <input type="text" name="last_name" required value="<?= htmlspecialchars($user['last_name'] ?? '') ?>"
                                   style="width:100%;border:1px solid #e5e7eb;padding:0.38rem 0.55rem;font-size:0.82rem;box-sizing:border-box;outline:none;"
                                   onfocus="this.style.borderColor='#374151'" onblur="this.style.borderColor='#e5e7eb'">
                        </div>
                        <div>
                            <label style="display:block;font-size:0.65rem;font-weight:700;text-transform:uppercase;letter-spacing:0.06em;color:#6b7280;margin-bottom:4px;">Suffix</label>
                            <input type="text" name="suffix" value="<?= htmlspecialchars($user['suffix'] ?? '') ?>"
                                   placeholder="Optional"
                                   style="width:100%;border:1px solid #e5e7eb;padding:0.38rem 0.55rem;font-size:0.82rem;box-sizing:border-box;outline:none;"
                                   onfocus="this.style.borderColor='#374151'" onblur="this.style.borderColor='#e5e7eb'">
                        </div>
                        <div style="grid-column:span 2;">
                            <label style="display:block;font-size:0.65rem;font-weight:700;text-transform:uppercase;letter-spacing:0.06em;color:#6b7280;margin-bottom:4px;">Email</label>
                            <div style="display:flex;border:1px solid #e5e7eb;background:#f9fafb;">
                                <div style="padding:0.38rem 0.55rem;border-right:1px solid #e5e7eb;display:flex;align-items:center;">
                                    <i class="bi bi-lock" style="font-size:0.68rem;color:#9ca3af;"></i>
                                </div>
                                <input type="email" value="<?= htmlspecialchars($user['email']) ?>" readonly
                                       style="flex:1;border:none;background:transparent;padding:0.38rem 0.55rem;font-size:0.82rem;color:#9ca3af;outline:none;">
                            </div>
                            <div style="font-size:0.62rem;color:#9ca3af;margin-top:3px;">Changed by Administrator only.</div>
                        </div>
                    </div>
                </div>

                <?php if (!isEvaluator()): ?>
                <!-- Account box -->
                <div style="border:1px solid #e5e7eb;overflow:hidden;margin-bottom:0.75rem;">
                    <div style="background:#f9fafb;border-bottom:1px solid #e5e7eb;padding:0.35rem 0.75rem;">
                        <span style="font-size:0.65rem;font-weight:700;text-transform:uppercase;letter-spacing:0.08em;color:#374151;">Account</span>
                    </div>
                    <div style="padding:0.75rem;display:grid;grid-template-columns:1fr 1fr;gap:0.6rem;">
                        <div>
                            <label style="display:block;font-size:0.65rem;font-weight:700;text-transform:uppercase;letter-spacing:0.06em;color:#6b7280;margin-bottom:4px;">Employee ID</label>
                            <?php if ($_SESSION['role'] === 'faculty'): ?>
                            <input type="text" name="employee_id" value="<?= htmlspecialchars($user['employee_id'] ?? '') ?>"
                                   style="width:100%;border:1px solid #e5e7eb;padding:0.38rem 0.55rem;font-size:0.82rem;box-sizing:border-box;outline:none;"
                                   onfocus="this.style.borderColor='#374151'" onblur="this.style.borderColor='#e5e7eb'">
                            <?php else: ?>
                            <input type="text" value="<?= htmlspecialchars($user['employee_id'] ?? '') ?>" disabled
                                   style="width:100%;border:1px solid #e5e7eb;padding:0.38rem 0.55rem;font-size:0.82rem;box-sizing:border-box;background:#f9fafb;color:#9ca3af;">
                            <?php endif; ?>
                        </div>
                        <?php if ($_SESSION['role'] !== 'admin'): ?>
                        <div>
                            <label style="display:block;font-size:0.65rem;font-weight:700;text-transform:uppercase;letter-spacing:0.06em;color:#6b7280;margin-bottom:4px;">Campus</label>
                            <select name="campus_id"
                                    style="width:100%;border:1px solid #e5e7eb;padding:0.38rem 0.55rem;font-size:0.82rem;background:#fff;cursor:pointer;outline:none;"
                                    onfocus="this.style.borderColor='#374151'" onblur="this.style.borderColor='#e5e7eb'">
                                <option value="" disabled <?= empty($user['campus_id']) ? 'selected' : '' ?>>Select campus</option>
                                <?php
                                $campuses = $pdo->query("SELECT * FROM campuses WHERE is_active=1 ORDER BY campus_name")->fetchAll();
                                foreach ($campuses as $c): ?>
                                <option value="<?= $c['campus_id'] ?>" <?= ($user['campus_id'] ?? 0) == $c['campus_id'] ? 'selected' : '' ?>>
                                    <?= sanitize($c['campus_name']) ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div>
                            <label style="display:block;font-size:0.65rem;font-weight:700;text-transform:uppercase;letter-spacing:0.06em;color:#6b7280;margin-bottom:4px;">Faculty Rank</label>
                            <select name="rank"
                                    style="width:100%;border:1px solid #e5e7eb;padding:0.38rem 0.55rem;font-size:0.82rem;background:#fff;cursor:pointer;outline:none;"
                                    onfocus="this.style.borderColor='#374151'" onblur="this.style.borderColor='#e5e7eb'">
                                <option value="" disabled <?= empty($user['rank']) ? 'selected' : '' ?>>Select rank</option>
                                <?php foreach (facultyRanks() as $r): ?>
                                <option value="<?= $r ?>" <?= ($user['rank'] ?? '') === $r ? 'selected' : '' ?>><?= $r ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <?php if ($_SESSION['role'] === 'faculty'): ?>
                        <div>
                            <label style="display:block;font-size:0.65rem;font-weight:700;text-transform:uppercase;letter-spacing:0.06em;color:#6b7280;margin-bottom:4px;">Faculty Status</label>
                            <select name="faculty_status"
                                    style="width:100%;border:1px solid #e5e7eb;padding:0.38rem 0.55rem;font-size:0.82rem;background:#fff;cursor:pointer;outline:none;"
                                    onfocus="this.style.borderColor='#374151'" onblur="this.style.borderColor='#e5e7eb'">
                                <option value="Existing Faculty" <?= ($user['faculty_status'] ?? '') === 'Existing Faculty' ? 'selected' : '' ?>>Existing Faculty</option>
                                <option value="New Faculty" <?= ($user['faculty_status'] ?? 'New Faculty') === 'New Faculty' ? 'selected' : '' ?>>New Faculty</option>
                            </select>
                        </div>
                        <?php endif; ?>
                        <?php endif; ?>
                    </div>
                </div>

                <?php endif; ?>

                <?php if ($_SESSION['role'] === 'faculty'): ?>
                <!-- Education box -->
                <div style="border:1px solid #e5e7eb;overflow:hidden;margin-bottom:0.75rem;">
                    <div style="background:#f9fafb;border-bottom:1px solid #e5e7eb;padding:0.35rem 0.75rem;">
                        <span style="font-size:0.65rem;font-weight:700;text-transform:uppercase;letter-spacing:0.08em;color:#374151;">Educational Attainment</span>
                    </div>
                    <div style="padding:0.75rem;display:flex;flex-direction:column;gap:0.5rem;">
                        <?php foreach ($education_levels as $level_key => $level_label):
                            $ed = $education_by_level[$level_key] ?? [];
                            $is_required = $level_key === 'Bachelor';
                        ?>
                        <div style="border:1px solid #e5e7eb;overflow:hidden;">
                            <div style="background:#f9fafb;border-bottom:1px solid #e5e7eb;padding:0.28rem 0.65rem;display:flex;align-items:center;justify-content:space-between;">
                                <span style="font-size:0.68rem;font-weight:700;color:#374151;"><?= htmlspecialchars($level_label) ?></span>
                                <?php if (!$is_required): ?>
                                <span style="font-size:0.6rem;color:#9ca3af;font-style:italic;">optional</span>
                                <?php else: ?>
                                <span style="font-size:0.6rem;font-weight:700;color:#374151;border:1px solid #d1d5db;padding:0 4px;">Required</span>
                                <?php endif; ?>
                            </div>
                            <div style="padding:0.6rem 0.65rem;display:grid;grid-template-columns:1fr 1fr 90px;gap:0.5rem;">
                                <input type="hidden" name="edu_level[]" value="<?= htmlspecialchars($level_key) ?>">
                                <div>
                                    <label style="display:block;font-size:0.6rem;font-weight:700;text-transform:uppercase;letter-spacing:0.06em;color:#6b7280;margin-bottom:3px;">Degree<?= $is_required ? ' *' : '' ?></label>
                                    <input name="edu_degree[]" value="<?= htmlspecialchars($ed['degree_program'] ?? '') ?>"
                                           placeholder="<?= $is_required ? 'Bachelor of Science in...' : 'N/A if none' ?>"
                                           <?= $is_required ? 'required' : '' ?>
                                           style="width:100%;border:1px solid #e5e7eb;padding:0.32rem 0.45rem;font-size:0.78rem;box-sizing:border-box;outline:none;"
                                           onfocus="this.style.borderColor='#374151'" onblur="this.style.borderColor='#e5e7eb'">
                                </div>
                                <div>
                                    <label style="display:block;font-size:0.6rem;font-weight:700;text-transform:uppercase;letter-spacing:0.06em;color:#6b7280;margin-bottom:3px;">School<?= $is_required ? ' *' : '' ?></label>
                                    <input name="edu_school[]" value="<?= htmlspecialchars($ed['school_university'] ?? '') ?>"
                                           placeholder="<?= $is_required ? 'School / University' : 'N/A if none' ?>"
                                           <?= $is_required ? 'required' : '' ?>
                                           style="width:100%;border:1px solid #e5e7eb;padding:0.32rem 0.45rem;font-size:0.78rem;box-sizing:border-box;outline:none;"
                                           onfocus="this.style.borderColor='#374151'" onblur="this.style.borderColor='#e5e7eb'">
                                </div>
                                <div>
                                    <label style="display:block;font-size:0.6rem;font-weight:700;text-transform:uppercase;letter-spacing:0.06em;color:#6b7280;margin-bottom:3px;">Year<?= $is_required ? ' *' : '' ?></label>
                                    <input name="edu_year[]" value="<?= htmlspecialchars($ed['year_graduated'] ?? '') ?>"
                                           placeholder="YYYY" <?= $is_required ? 'required' : '' ?>
                                           style="width:100%;border:1px solid #e5e7eb;padding:0.32rem 0.45rem;font-size:0.78rem;box-sizing:border-box;outline:none;"
                                           onfocus="this.style.borderColor='#374151'" onblur="this.style.borderColor='#e5e7eb'">
                                </div>
                            </div>
                        </div>
                        <?php endforeach; ?>
                        <div style="display:flex;align-items:center;gap:0.75rem;padding:0.35rem 0;">
                            <label style="font-size:0.65rem;font-weight:700;text-transform:uppercase;letter-spacing:0.06em;color:#6b7280;white-space:nowrap;">Applied 1st Cycle</label>
                            <select name="applied_first_cycle"
                                    style="border:1px solid #e5e7eb;padding:0.32rem 0.45rem;font-size:0.78rem;background:#fff;cursor:pointer;outline:none;width:100px;"
                                    onfocus="this.style.borderColor='#374151'" onblur="this.style.borderColor='#e5e7eb'">
                                <option value="0" <?= empty($user['applied_first_cycle']) ? 'selected' : '' ?>>NO</option>
                                <option value="1" <?= !empty($user['applied_first_cycle']) ? 'selected' : '' ?>>YES</option>
                            </select>
                        </div>
                    </div>
                </div>
                <?php endif; ?>

                <!-- Footer -->
                <div style="display:flex;gap:0.5rem;justify-content:flex-end;padding-top:0.25rem;">
                    <button type="button" onclick="document.getElementById('pf-edit-modal').style.display='none'"
                            style="padding:0.42rem 1rem;border:1px solid #e5e7eb;background:#fff;font-size:0.8rem;font-weight:600;color:#374151;cursor:pointer;"
                            onmouseover="this.style.background='#f9fafb'" onmouseout="this.style.background='#fff'">Cancel</button>
                    <button type="submit"
                            style="padding:0.42rem 1.2rem;background:#1a3a6b;color:#fff;border:none;font-size:0.8rem;font-weight:700;cursor:pointer;display:inline-flex;align-items:center;gap:5px;"
                            onmouseover="this.style.background='#152d54'" onmouseout="this.style.background='#1a3a6b'">
                        <i class="bi bi-check2" style="font-size:0.75rem;"></i> Save Changes
                    </button>
                </div>
            </form>
        </div>

        <!-- Change Password tab -->
        <div id="pf-mt-pw" style="display:none;padding:1.1rem 1rem;">
            <form method="POST" action="index.php?page=profile">
                <input type="hidden" name="action" value="change_password">

                <div style="border:1px solid #e5e7eb;overflow:hidden;margin-bottom:0.75rem;">
                    <div style="background:#f9fafb;border-bottom:1px solid #e5e7eb;padding:0.35rem 0.75rem;">
                        <span style="font-size:0.65rem;font-weight:700;text-transform:uppercase;letter-spacing:0.08em;color:#374151;">New Password</span>
                    </div>
                    <div style="padding:0.75rem;display:grid;grid-template-columns:1fr 1fr;gap:0.6rem;">
                        <div>
                            <label style="display:block;font-size:0.65rem;font-weight:700;text-transform:uppercase;letter-spacing:0.06em;color:#6b7280;margin-bottom:4px;">New Password</label>
                            <div style="display:flex;border:1px solid #e5e7eb;" id="pfNewPwWrap">
                                <input type="password" name="new_password" id="pfNewPassword" required
                                       placeholder="e.g. Anasue@123"
                                       oninput="pfCheckPw(this.value)"
                                       onfocus="document.getElementById('pfNewPwWrap').style.borderColor='#374151'"
                                       onblur="document.getElementById('pfNewPwWrap').style.borderColor='#e5e7eb'"
                                       style="flex:1;border:none;padding:0.38rem 0.55rem;font-size:0.82rem;outline:none;">
                                <button type="button" onclick="pfTogglePw('pfNewPassword',this)"
                                        style="border:none;border-left:1px solid #e5e7eb;background:#f9fafb;padding:0 0.55rem;cursor:pointer;color:#9ca3af;">
                                    <i class="bi bi-eye" style="font-size:0.75rem;"></i>
                                </button>
                            </div>
                            <div style="margin-top:5px;display:flex;gap:3px;">
                                <div class="pf-pw-bar" style="flex:1;height:3px;background:#e5e7eb;transition:background 0.3s;"></div>
                                <div class="pf-pw-bar" style="flex:1;height:3px;background:#e5e7eb;transition:background 0.3s;"></div>
                                <div class="pf-pw-bar" style="flex:1;height:3px;background:#e5e7eb;transition:background 0.3s;"></div>
                                <div class="pf-pw-bar" style="flex:1;height:3px;background:#e5e7eb;transition:background 0.3s;"></div>
                            </div>
                            <ul style="margin:5px 0 0;padding-left:1.1rem;font-size:0.68rem;color:#9ca3af;line-height:1.7;">
                                <li id="pf-req-len">At least 8 characters</li>
                                <li id="pf-req-num">A number</li>
                                <li id="pf-req-sym">A special character (@, #, !)</li>
                                <li id="pf-req-case">Uppercase and lowercase letters</li>
                            </ul>
                        </div>
                        <div>
                            <label style="display:block;font-size:0.65rem;font-weight:700;text-transform:uppercase;letter-spacing:0.06em;color:#6b7280;margin-bottom:4px;">Confirm Password</label>
                            <div style="display:flex;border:1px solid #e5e7eb;" id="pfConfPwWrap">
                                <input type="password" name="confirm_password" id="pfConfirmPassword" required
                                       onfocus="document.getElementById('pfConfPwWrap').style.borderColor='#374151'"
                                       onblur="document.getElementById('pfConfPwWrap').style.borderColor='#e5e7eb'"
                                       style="flex:1;border:none;padding:0.38rem 0.55rem;font-size:0.82rem;outline:none;">
                                <button type="button" onclick="pfTogglePw('pfConfirmPassword',this)"
                                        style="border:none;border-left:1px solid #e5e7eb;background:#f9fafb;padding:0 0.55rem;cursor:pointer;color:#9ca3af;">
                                    <i class="bi bi-eye" style="font-size:0.75rem;"></i>
                                </button>
                            </div>
                        </div>
                    </div>
                </div>

                <div style="display:flex;gap:0.5rem;justify-content:flex-end;padding-top:0.25rem;">
                    <button type="button" onclick="document.getElementById('pf-edit-modal').style.display='none'"
                            style="padding:0.42rem 1rem;border:1px solid #e5e7eb;background:#fff;font-size:0.8rem;font-weight:600;color:#374151;cursor:pointer;"
                            onmouseover="this.style.background='#f9fafb'" onmouseout="this.style.background='#fff'">Cancel</button>
                    <button type="submit"
                            style="padding:0.42rem 1.2rem;background:#1a3a6b;color:#fff;border:none;font-size:0.8rem;font-weight:700;cursor:pointer;display:inline-flex;align-items:center;gap:5px;"
                            onmouseover="this.style.background='#152d54'" onmouseout="this.style.background='#1a3a6b'">
                        <i class="bi bi-check2" style="font-size:0.75rem;"></i> Update Password
                    </button>
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
            newImg.className = 'profile-identity-image';
            placeholder.replaceWith(newImg);
        }
    };
    reader.readAsDataURL(file);
    // Submit the hidden form
    document.getElementById('avatarUploadForm').submit();
}
</script>

