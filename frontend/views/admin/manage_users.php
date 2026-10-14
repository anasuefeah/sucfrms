<?php
if (!isAdmin()) { echo '<div class="alert alert-danger">Access denied.</div>'; return; }
ensureKraAssignmentTable($pdo);

$mailerPath = __DIR__ . '/../../../backend/vendor/phpmailer/phpmailer/src/';
if (!defined('SMTP_HOST')) {
    define('SMTP_HOST', 'smtp.gmail.com');
    define('SMTP_PORT', 587);
    define('SMTP_USER', 'sucfrms.chmsu@gmail.com');
    define('SMTP_PASS', 'wwbbxdrtpuammsma');
    define('SMTP_FROM', 'sucfrms.chmsu@gmail.com');
    define('SMTP_FROM_NAME', 'SUCFRMS');
}

function adminRandomPassword(): string {
    $chars = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghjkmnpqrstuvwxyz23456789@#!';
    return $chars[random_int(0, 25)] . substr(str_shuffle('abcdefghjkmnpqrstuvwxyz'), 0, 5) . random_int(10, 99) . $chars[random_int(52, strlen($chars) - 1)];
}

function adminSendTempPasswordEmail(string $to, string $name, string $pass): string {
    global $mailerPath;
    if (!file_exists($mailerPath . 'PHPMailer.php')) return 'PHPMailer library not found.';
    try {
        require_once $mailerPath . 'PHPMailer.php';
        require_once $mailerPath . 'SMTP.php';
        require_once $mailerPath . 'Exception.php';
        $mail = new \PHPMailer\PHPMailer\PHPMailer(true);
        $mail->isSMTP();
        $mail->Host = SMTP_HOST;
        $mail->SMTPAuth = true;
        $mail->Username = SMTP_USER;
        $mail->Password = SMTP_PASS;
        $mail->SMTPSecure = \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port = SMTP_PORT;
        $mail->CharSet = 'UTF-8';
        $mail->setFrom(SMTP_FROM, SMTP_FROM_NAME);
        $mail->addAddress($to, $name);
        $mail->isHTML(true);
        $mail->Subject = 'Your SUCFRMS Account';
        $mail->Body = '<p>Hello <strong>' . htmlspecialchars($name) . '</strong>,</p>'
            . '<p>Your SUCFRMS account has been created by the Administrator.</p>'
            . '<p>Temporary password: <strong style="font-family:monospace;font-size:18px;">' . htmlspecialchars($pass) . '</strong></p>'
            . '<p>You may change this password any time from your Profile settings.</p>';
        $mail->AltBody = "Hello $name,\n\nYour SUCFRMS temporary password is: $pass\n\nYou may change this password any time from your Profile settings.";
        $mail->send();
        return '';
    } catch (\Exception $e) {
        error_log('SUCFRMS account email failed: ' . $e->getMessage());
        return $e->getMessage();
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'create_account') {
        $role = $_POST['role'] ?? '';
        $first = trim($_POST['first_name'] ?? '');
        $middle = !empty($_POST['no_middle_name']) ? '' : trim($_POST['middle_name'] ?? '');
        $last = trim($_POST['last_name'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $password = $_POST['password'] ?? '';
        $valid_roles = ['faculty','evaluator'];

        if (!in_array($role, $valid_roles, true) || $first === '' || $last === '' || $email === '' || $password === '') {
            flashMessage('danger', 'Please fill in all required account fields.');
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            flashMessage('danger', 'Please enter a valid email address.');
        } elseif (strlen($password) < 8) {
            flashMessage('danger', 'Temporary password must be at least 8 characters.');
        } else {
            $dup = $pdo->prepare("SELECT user_id FROM users WHERE email=?");
            $dup->execute([$email]);
            if ($dup->fetch()) {
                flashMessage('danger', 'An account with that email already exists.');
            } else {
                $label = $role === 'evaluator' ? nextCheckerLabel($pdo) : null;
                $hash = password_hash($password, PASSWORD_DEFAULT);
                $profile_completed = $role === 'faculty' ? 0 : 1;
                $email_locked = $role === 'faculty' ? 1 : 0;
                $stmt = $pdo->prepare("INSERT INTO users (first_name, middle_name, last_name, email, password, role, status, checker_label, profile_completed, email_locked)
                    VALUES (?,?,?,?,?,?, 'active', ?, ?, ?)");
                $stmt->execute([$first, $middle ?: null, $last, $email, $hash, $role, $label, $profile_completed, $email_locked]);
                $new_id = (int)$pdo->lastInsertId();
                $name = formatDisplayName(['first_name'=>$first,'middle_name'=>$middle,'last_name'=>$last]);
                $mail_error = adminSendTempPasswordEmail($email, $name, $password);
                $role_label = match($role) {
                    'evaluator' => 'Evaluator',
                    default => 'Faculty',
                };
                logAudit($pdo, $_SESSION['user_id'], 'Account Created', "Created {$role_label} account for {$email}.");
                if ($role === 'evaluator') {
                    $cycles_for_assignment = $pdo->query("SELECT cycle_id FROM cycles")->fetchAll(PDO::FETCH_COLUMN);
                    if ($cycles_for_assignment) {
                        $assign = $pdo->prepare("INSERT IGNORE INTO checker_kra_assignments (cycle_id, checker_id, kra_category, assigned_by) VALUES (?,?,?,?)");
                        foreach ($cycles_for_assignment as $cycle_id) {
                            foreach (array_keys(kraAssignmentCategories()) as $kra) {
                                $assign->execute([(int)$cycle_id, $new_id, $kra, $_SESSION['user_id']]);
                            }
                        }
                        logAudit($pdo, $_SESSION['user_id'], 'KRA Assignment Updated', "Granted all KRAs to new evaluator {$email} for existing cycles.");
                    }
                }
                $extra = $mail_error ? " Email failed; temporary password: <strong>" . htmlspecialchars($password) . "</strong>" : ' Temporary password was emailed.';
                flashMessage($mail_error ? 'warning' : 'success', "{$role_label} account created.{$extra}");
            }
        }
    } elseif ($action === 'set_inactive') {
        $uid = (int)$_POST['uid'];
        if ($uid !== (int)$_SESSION['user_id']) {
            $pdo->prepare("UPDATE users SET status='inactive' WHERE user_id=?")->execute([$uid]);
            logAudit($pdo, $_SESSION['user_id'], 'User Deactivated', "Set user ID {$uid} to inactive.");
            flashMessage('warning', 'User has been set to inactive.');
        }
    } elseif ($action === 'set_active') {
        $uid = (int)$_POST['uid'];
        $pdo->prepare("UPDATE users SET status='active' WHERE user_id=?")->execute([$uid]);
        logAudit($pdo, $_SESSION['user_id'], 'User Reactivated', "Reactivated user ID {$uid}.");
        flashMessage('success', 'User has been reactivated.');
    } elseif ($action === 'delete_user') {
        $uid = (int)$_POST['uid'];
        if ($uid !== (int)$_SESSION['user_id']) {
            $row = $pdo->prepare("SELECT full_name FROM users WHERE user_id=?");
            $row->execute([$uid]);
            $name = $row->fetchColumn() ?: "ID {$uid}";
            $pdo->prepare("DELETE FROM users WHERE user_id=?")->execute([$uid]);
            logAudit($pdo, $_SESSION['user_id'], 'User Deleted', "Deleted user {$name}.");
            flashMessage('success', "User <strong>" . htmlspecialchars($name) . "</strong> deleted.");
        }
    } elseif ($action === 'reset_password') {
        $uid = (int)$_POST['uid'];
        $temp = trim($_POST['temp_password'] ?? '') ?: adminRandomPassword();
        if (strlen($temp) < 8) {
            flashMessage('danger', 'Temporary password must be at least 8 characters.');
        } else {
            $row = $pdo->prepare("SELECT email, first_name, middle_name, last_name FROM users WHERE user_id=?");
            $row->execute([$uid]);
            $target = $row->fetch();
            if ($target) {
                $pdo->prepare("UPDATE users SET password=? WHERE user_id=?")->execute([password_hash($temp, PASSWORD_DEFAULT), $uid]);
                $name = formatDisplayName($target);
                $mail_error = adminSendTempPasswordEmail($target['email'], $name, $temp);
                logAudit($pdo, $_SESSION['user_id'], 'Password Reset', "Reset password for user ID {$uid}.");
                $msg = "Temporary password set for <strong>" . htmlspecialchars($name) . "</strong>: <strong>" . htmlspecialchars($temp) . "</strong>";
                if (!$mail_error) $msg .= ' and emailed to the user.';
                flashMessage($mail_error ? 'warning' : 'success', $msg);
            }
        }
    } elseif ($action === 'update_kra_assignments') {
        $cycle_id = (int)($_POST['cycle_id'] ?? 0);
        $posted = $_POST['kra_assignments'] ?? [];
        $categories = kraAssignmentCategories();
        if ($cycle_id <= 0) {
            flashMessage('danger', 'Please select a cycle for KRA assignments.');
        } else {
            $checkers = $pdo->query("SELECT user_id, checker_label, first_name, middle_name, last_name, email FROM users WHERE role='evaluator' AND status='active' ORDER BY user_id ASC")->fetchAll();
            $old_stmt = $pdo->prepare("SELECT checker_id, kra_category FROM checker_kra_assignments WHERE cycle_id=?");
            $old_stmt->execute([$cycle_id]);
            $old_map = [];
            foreach ($old_stmt->fetchAll() as $row) $old_map[(int)$row['checker_id']][] = $row['kra_category'];

            $pdo->beginTransaction();
            try {
                $pdo->prepare("DELETE FROM checker_kra_assignments WHERE cycle_id=?")->execute([$cycle_id]);
                $ins = $pdo->prepare("INSERT IGNORE INTO checker_kra_assignments (cycle_id, checker_id, kra_category, assigned_by) VALUES (?,?,?,?)");
                $changes = [];
                $removal_warnings = [];
                foreach ($checkers as $checker) {
                    $cid = (int)$checker['user_id'];
                    $new_kras = array_values(array_intersect(array_keys($categories), $posted[$cid] ?? []));
                    foreach ($new_kras as $kra) $ins->execute([$cycle_id, $cid, $kra, $_SESSION['user_id']]);
                    $old_kras = $old_map[$cid] ?? [];
                    $added = array_diff($new_kras, $old_kras);
                    $removed = array_diff($old_kras, $new_kras);
                    $label = checkerDisplayLabel($checker);
                    foreach ($added as $kra) $changes[] = "{$label}: added {$categories[$kra]}";
                    foreach ($removed as $kra) {
                        $changes[] = "{$label}: removed {$categories[$kra]}";
                        $open_stmt = $pdo->prepare("
                            SELECT COUNT(*)
                            FROM kra_submissions ks
                            JOIN applications a ON a.application_id = ks.application_id
                            WHERE a.cycle_id = ?
                              AND ks.kra_category = ?
                              AND a.status IN ('submitted','under_review','needs_revision','talisay_review')
                              AND (ks.verified_by = ? OR ks.revision_by = ?)
                        ");
                        $open_stmt->execute([$cycle_id, $kra, $cid, $cid]);
                        $open_count = (int)$open_stmt->fetchColumn();
                        if ($open_count > 0) {
                            $removal_warnings[] = "{$label} has {$open_count} open touched item(s) in {$categories[$kra]}. Please reassign or resolve them.";
                        }
                        try {
                            $replacement = $pdo->prepare("
                                SELECT checker_id
                                FROM checker_kra_assignments
                                WHERE cycle_id=? AND kra_category=? AND checker_id<>?
                                ORDER BY checker_id ASC
                                LIMIT 1
                            ");
                            $replacement->execute([$cycle_id, $kra, $cid]);
                            $new_checker = (int)($replacement->fetchColumn() ?: 0);
                            if ($new_checker) {
                                $move = $pdo->prepare("
                                    UPDATE appeals ap
                                    JOIN applications a ON a.application_id = ap.application_id
                                    JOIN kra_submissions ks ON ks.submission_id = ap.submission_id
                                    SET ap.checker_id=?, ap.is_read_by_checker=0, ap.updated_at=NOW()
                                    WHERE ap.checker_id=? AND ap.status IN ('open','under_review')
                                      AND a.cycle_id=? AND ks.kra_category=?
                                ");
                                $move->execute([$new_checker, $cid, $cycle_id, $kra]);
                                if ($move->rowCount() > 0) {
                                    logAudit($pdo, $_SESSION['user_id'], 'Appeals Reassigned', "Moved {$move->rowCount()} open appeal(s) from {$label} to evaluator ID {$new_checker} for {$categories[$kra]}.");
                                }
                            }
                        } catch (\Exception $e) {}
                    }
                }
                $pdo->commit();
                logAudit($pdo, $_SESSION['user_id'], 'KRA Assignment Updated', "Cycle #{$cycle_id}. " . ($changes ? implode('; ', $changes) : 'No changes.'));
                if ($removal_warnings) {
                    flashMessage('warning', 'KRA assignments updated. ' . htmlspecialchars(implode(' ', $removal_warnings)));
                } else {
                    flashMessage('success', 'KRA assignments updated.');
                }
            } catch (\Exception $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                flashMessage('danger', 'Unable to save KRA assignments: ' . htmlspecialchars($e->getMessage()));
            }
        }
    } elseif ($action === 'copy_kra_assignments') {
        $from_cycle = (int)($_POST['from_cycle_id'] ?? 0);
        $to_cycle = (int)($_POST['to_cycle_id'] ?? 0);
        if ($from_cycle && $to_cycle && $from_cycle !== $to_cycle) {
            $pdo->prepare("DELETE FROM checker_kra_assignments WHERE cycle_id=?")->execute([$to_cycle]);
            $copy = $pdo->prepare("INSERT IGNORE INTO checker_kra_assignments (cycle_id, checker_id, kra_category, assigned_by)
                SELECT ?, checker_id, kra_category, ? FROM checker_kra_assignments WHERE cycle_id=?");
            $copy->execute([$to_cycle, $_SESSION['user_id'], $from_cycle]);
            logAudit($pdo, $_SESSION['user_id'], 'KRA Assignment Copied', "Copied KRA assignments from cycle #{$from_cycle} to cycle #{$to_cycle}.");
            flashMessage('success', 'KRA assignment setup copied.');
        } else {
            flashMessage('danger', 'Please choose a different source cycle to copy from.');
        }
    } elseif ($action === 'update_email') {
        $uid = (int)$_POST['uid'];
        $email = trim($_POST['email'] ?? '');
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            flashMessage('danger', 'Please enter a valid email address.');
        } else {
            $dup = $pdo->prepare("SELECT user_id FROM users WHERE email=? AND user_id<>?");
            $dup->execute([$email, $uid]);
            if ($dup->fetch()) {
                flashMessage('danger', 'That email is already used by another account.');
            } else {
                $pdo->prepare("UPDATE users SET email=? WHERE user_id=?")->execute([$email, $uid]);
                logAudit($pdo, $_SESSION['user_id'], 'User Email Updated', "Updated email for user ID {$uid} to {$email}.");
                flashMessage('success', 'Email updated.');
            }
        }
    }
    echo "<script>window.location.href='index.php?page=manage_users';</script>"; exit;
}

$search = trim($_GET['search'] ?? '');
$filter = $_GET['role_filter'] ?? '';
$filter_campus = (int)($_GET['campus_id'] ?? 0);
$campuses = $pdo->query("SELECT campus_id, campus_name FROM campuses ORDER BY campus_name ASC")->fetchAll();
$kra_categories = kraAssignmentCategories();
$cycles_for_kra = $pdo->query("SELECT cycle_id, cycle_name, status FROM cycles ORDER BY created_at DESC")->fetchAll();
$assignment_cycle_id = (int)($_GET['assignment_cycle_id'] ?? 0);
if (!$assignment_cycle_id && $cycles_for_kra) {
    foreach ($cycles_for_kra as $cy) {
        if ($cy['status'] === 'open') { $assignment_cycle_id = (int)$cy['cycle_id']; break; }
    }
    if (!$assignment_cycle_id) $assignment_cycle_id = (int)$cycles_for_kra[0]['cycle_id'];
}
$active_checkers = $pdo->query("SELECT user_id, checker_label, first_name, middle_name, last_name, email, status FROM users WHERE role='evaluator' ORDER BY status DESC, user_id ASC")->fetchAll();
$assignment_map = [];
$coverage_counts = array_fill_keys(array_keys($kra_categories), 0);
if ($assignment_cycle_id) {
    $am = $pdo->prepare("SELECT checker_id, kra_category FROM checker_kra_assignments WHERE cycle_id=?");
    $am->execute([$assignment_cycle_id]);
    foreach ($am->fetchAll() as $row) {
        $assignment_map[(int)$row['checker_id']][] = $row['kra_category'];
        if (isset($coverage_counts[$row['kra_category']])) $coverage_counts[$row['kra_category']]++;
    }
}

$query = "SELECT u.*, c.campus_name,
    (SELECT COUNT(*) FROM applications a WHERE a.user_id=u.user_id AND a.status!='draft') AS app_count
    FROM users u
    LEFT JOIN campuses c ON u.campus_id=c.campus_id
    WHERE 1=1";
$params = [];
if ($search) { $query .= " AND (u.full_name LIKE ? OR u.email LIKE ? OR u.employee_id LIKE ?)"; $params = array_merge($params, ["%$search%","%$search%","%$search%"]); }
if ($filter) { $query .= " AND u.role=?"; $params[] = $filter; }
if ($filter_campus) { $query .= " AND u.campus_id=?"; $params[] = $filter_campus; }
$query .= " ORDER BY u.created_at DESC";
$stmt = $pdo->prepare($query);
$stmt->execute($params);
$users = $stmt->fetchAll();
?>

<?php showFlash(); ?>

<!-- Page Header Bar -->
<div style="display:flex;align-items:center;justify-content:space-between;
            background:#fff;border:1px solid #e2e8f0;border-radius:10px;
            padding:0.85rem 1.25rem;margin-bottom:1.5rem;
            box-shadow:0 1px 4px rgba(30,58,140,0.05);">
    <div style="display:flex;align-items:center;gap:0.65rem;">
        <div style="width:38px;height:38px;border-radius:9px;background:#1e4d8c;
                    display:flex;align-items:center;justify-content:center;flex-shrink:0;">
            <i class="bi bi-people-fill" style="color:#fff;font-size:0.95rem;"></i>
        </div>
        <div>
            <div style="font-weight:700;color:#1a3a6b;font-size:0.95rem;line-height:1.2;">Manage Users</div>
            <div style="font-size:0.72rem;color:#94a3b8;margin-top:2px;">Create and manage faculty and evaluator accounts</div>
        </div>
    </div>
    <button onclick="document.getElementById('createAccountModal').style.display='flex'"
            style="display:inline-flex;align-items:center;gap:0.4rem;padding:0.45rem 1.05rem;
                   background:#1a3a6b;color:#fff;border:none;border-radius:7px;
                   font-size:0.8rem;font-weight:700;cursor:pointer;white-space:nowrap;flex-shrink:0;"
            onmouseover="this.style.background='#162f56'" onmouseout="this.style.background='#1a3a6b'">
        <i class="bi bi-person-plus-fill"></i> Add Account
    </button>
</div>

<!-- ── Create Account Modal ─────────────────────────────────────── -->
<div id="createAccountModal"
     style="display:none;position:fixed;inset:0;background:rgba(15,23,42,0.55);
            z-index:99999;align-items:center;justify-content:center;padding:1rem;">
    <div style="background:#fff;border-radius:12px;width:100%;max-width:640px;
                box-shadow:0 24px 60px rgba(0,0,0,0.22);overflow:hidden;animation:muSlideIn 0.18s ease;">

        <!-- Modal header -->
        <div style="background:#1a3a6b;padding:1rem 1.35rem;display:flex;align-items:center;justify-content:space-between;">
            <div style="display:flex;align-items:center;gap:0.6rem;">
                <div style="width:30px;height:30px;border-radius:7px;background:rgba(255,255,255,0.15);
                            display:flex;align-items:center;justify-content:center;">
                    <i class="bi bi-person-plus-fill" style="color:#fff;font-size:0.85rem;"></i>
                </div>
                <div>
                    <div style="color:#fff;font-weight:700;font-size:0.92rem;line-height:1.2;">Create New Account</div>
                    <div style="color:#93c5fd;font-size:0.7rem;margin-top:1px;">Faculty or evaluator account</div>
                </div>
            </div>
            <button onclick="closeCreateModal()"
                    style="background:rgba(255,255,255,0.12);border:none;border-radius:6px;
                           color:#fff;width:28px;height:28px;cursor:pointer;font-size:0.9rem;
                           display:flex;align-items:center;justify-content:center;transition:background 0.15s;"
                    onmouseover="this.style.background='rgba(255,255,255,0.22)'"
                    onmouseout="this.style.background='rgba(255,255,255,0.12)'">
                <i class="bi bi-x-lg"></i>
            </button>
        </div>

        <!-- Modal body -->
        <div style="padding:1.35rem 1.5rem;">
            <p style="font-size:0.75rem;color:#64748b;margin-bottom:1.1rem;">
                Administrator-created accounts receive a temporary password the user can change from their
                <span style="color:#1a3a6b;font-weight:600;">Profile</span> settings.
            </p>
            <form method="POST" id="createAccountForm">
                <input type="hidden" name="action" value="create_account">

                <!-- Account Details -->
                <div style="font-size:0.68rem;font-weight:700;text-transform:uppercase;letter-spacing:0.08em;
                            color:#94a3b8;margin-bottom:0.55rem;padding-bottom:0.35rem;border-bottom:1px solid #f1f5f9;">
                    Account Details
                </div>
                <div class="row g-2 mb-3">
                    <div class="col-sm-3">
                        <label class="form-label fw-semibold" style="font-size:0.78rem;color:#334155;">Account Type <span class="text-danger">*</span></label>
                        <select name="role" class="form-select form-select-sm" required>
                            <option value="faculty">Faculty</option>
                            <option value="evaluator">Evaluator</option>
                        </select>
                    </div>
                    <div class="col-sm-3">
                        <label class="form-label fw-semibold" style="font-size:0.78rem;color:#334155;">First Name <span class="text-danger">*</span></label>
                        <input type="text" name="first_name" class="form-control form-control-sm" required>
                    </div>
                    <div class="col-sm-3">
                        <label class="form-label fw-semibold" style="font-size:0.78rem;color:#334155;">Middle Name</label>
                        <input type="text" name="middle_name" id="muMiddle" class="form-control form-control-sm">
                        <label style="font-size:0.7rem;color:#64748b;margin-top:3px;display:flex;gap:0.35rem;align-items:center;cursor:pointer;">
                            <input type="checkbox" name="no_middle_name" onchange="document.getElementById('muMiddle').disabled=this.checked">
                            No middle name
                        </label>
                    </div>
                    <div class="col-sm-3">
                        <label class="form-label fw-semibold" style="font-size:0.78rem;color:#334155;">Last Name <span class="text-danger">*</span></label>
                        <input type="text" name="last_name" class="form-control form-control-sm" required>
                    </div>
                </div>

                <!-- Login Credentials -->
                <div style="font-size:0.68rem;font-weight:700;text-transform:uppercase;letter-spacing:0.08em;
                            color:#94a3b8;margin-bottom:0.55rem;padding-bottom:0.35rem;border-bottom:1px solid #f1f5f9;">
                    Login Credentials
                </div>
                <div class="row g-2 mb-1">
                    <div class="col-sm-6">
                        <label class="form-label fw-semibold" style="font-size:0.78rem;color:#334155;">Email <span class="text-danger">*</span></label>
                        <input type="email" name="email" class="form-control form-control-sm" required>
                    </div>
                    <div class="col-sm-6">
                        <label class="form-label fw-semibold" style="font-size:0.78rem;color:#334155;">Temporary Password <span class="text-danger">*</span></label>
                        <div class="input-group input-group-sm">
                            <input type="password" name="password" id="muPassword" class="form-control" minlength="8" required>
                            <button type="button" class="btn btn-outline-secondary" onclick="toggleMuPw()" title="Show/hide"><i class="bi bi-eye"></i></button>
                            <button type="button" class="btn btn-outline-secondary" onclick="generateMuPw()" title="Generate"><i class="bi bi-shuffle"></i></button>
                        </div>
                    </div>
                </div>

                <!-- Modal footer actions -->
                <div style="display:flex;align-items:center;justify-content:flex-end;gap:0.5rem;
                            padding-top:1rem;margin-top:0.75rem;border-top:1px solid #f1f5f9;">
                    <button type="button" onclick="closeCreateModal()"
                            style="padding:0.45rem 1rem;border:1px solid #cbd5e1;border-radius:7px;
                                   background:#fff;color:#475569;font-size:0.82rem;font-weight:600;cursor:pointer;"
                            onmouseover="this.style.background='#f8fafc'" onmouseout="this.style.background='#fff'">
                        Cancel
                    </button>
                    <button type="submit"
                            style="padding:0.45rem 1.2rem;border:none;border-radius:7px;
                                   background:#1a3a6b;color:#fff;font-size:0.82rem;font-weight:700;cursor:pointer;"
                            onmouseover="this.style.background='#162f56'" onmouseout="this.style.background='#1a3a6b'">
                        <i class="bi bi-person-plus me-1"></i>Create Account
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>
<style>
@keyframes muSlideIn {
    from { opacity:0; transform:translateY(-12px) scale(0.98); }
    to   { opacity:1; transform:translateY(0)      scale(1);    }
}
</style>

<div class="neon-card mb-4" style="padding:0;overflow:hidden;border:1px solid #e2e8f0;">
    <!-- KRA Assignment header — always visible, acts as the toggle -->
    <div style="padding:0.85rem 1.25rem;border-bottom:1px solid #f1f5f9;background:#f8fafc;
                display:flex;align-items:center;justify-content:space-between;cursor:pointer;"
         onclick="toggleKraPanel()">
        <div style="display:flex;align-items:center;gap:0.6rem;">
            <div style="width:36px;height:36px;border-radius:8px;background:#1e4d8c;display:flex;align-items:center;justify-content:center;color:#fff;flex-shrink:0;">
                <i class="bi bi-ui-checks-grid" style="font-size:0.9rem;"></i>
            </div>
            <div>
                <div style="font-weight:700;color:#1a3a6b;font-size:0.95rem;">KRA Assignment</div>
                <div style="font-size:0.72rem;color:#94a3b8;margin-top:1px;">Control which KRAs each evaluator can review for a cycle.</div>
            </div>
        </div>
        <div style="display:flex;align-items:center;gap:0.75rem;">
            <?php if ($cycles_for_kra): ?>
            <form method="GET" action="index.php" class="d-flex gap-2 align-items-end" onclick="event.stopPropagation()">
                <input type="hidden" name="page" value="manage_users">
                <select name="assignment_cycle_id" class="form-select form-select-sm" onchange="this.form.submit()" style="min-width:240px;">
                    <?php foreach ($cycles_for_kra as $cy): ?>
                    <option value="<?= (int)$cy['cycle_id'] ?>" <?= $assignment_cycle_id===(int)$cy['cycle_id']?'selected':'' ?>>
                        <?= sanitize($cy['cycle_name']) ?> (<?= ucfirst($cy['status']) ?>)
                    </option>
                    <?php endforeach; ?>
                </select>
            </form>
            <?php endif; ?>
            <i class="bi bi-chevron-down" id="kraChevron"
               style="color:#94a3b8;font-size:0.75rem;transition:transform 0.2s;flex-shrink:0;"></i>
        </div>
    </div>

    <!-- KRA body — hidden by default -->
    <div id="kraPanel" style="display:none;padding:1.25rem 1.5rem;">
        <?php if (!$cycles_for_kra): ?>
        <div style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:7px;padding:0.65rem 0.9rem;font-size:0.8rem;color:#475569;">
            Create a cycle first before assigning KRAs to evaluators.
        </div>
        <?php else: ?>
            <?php $missing_kras = array_keys(array_filter($coverage_counts, fn($count) => (int)$count === 0)); ?>
            <?php if ($missing_kras): ?>
            <div style="display:flex;align-items:flex-start;gap:0.5rem;background:#fffbeb;border:1px solid #fde68a;
                        border-radius:7px;padding:0.6rem 0.85rem;margin-bottom:1rem;font-size:0.78rem;color:#92400e;">
                <i class="bi bi-exclamation-triangle-fill" style="flex-shrink:0;margin-top:1px;"></i>
                <span>No active evaluator is assigned to: <strong><?= htmlspecialchars(implode(', ', array_map(fn($kra) => $kra_categories[$kra], $missing_kras))) ?></strong>. Submissions under these KRAs cannot be fully evaluated.</span>
            </div>
            <?php endif; ?>

            <?php if (count($cycles_for_kra) > 1): ?>
            <form method="POST" class="d-flex gap-2 align-items-center flex-wrap mb-3"
                  style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:8px;padding:0.65rem 0.9rem;">
                <input type="hidden" name="action" value="copy_kra_assignments">
                <input type="hidden" name="to_cycle_id" value="<?= (int)$assignment_cycle_id ?>">
                <span style="font-size:0.78rem;color:#475569;font-weight:600;">Copy setup from</span>
                <select name="from_cycle_id" class="form-select form-select-sm" style="max-width:300px;">
                    <?php foreach ($cycles_for_kra as $cy): if ((int)$cy['cycle_id'] === $assignment_cycle_id) continue; ?>
                    <option value="<?= (int)$cy['cycle_id'] ?>"><?= sanitize($cy['cycle_name']) ?> (<?= ucfirst($cy['status']) ?>)</option>
                    <?php endforeach; ?>
                </select>
                <button class="btn btn-sm btn-outline-secondary">Copy</button>
            </form>
            <?php endif; ?>

            <form method="POST">
                <input type="hidden" name="action" value="update_kra_assignments">
                <input type="hidden" name="cycle_id" value="<?= (int)$assignment_cycle_id ?>">
                <div class="table-responsive">
                    <table class="table align-middle mb-2" style="font-size:0.82rem;">
                        <thead>
                            <tr style="background:#f8fafc;">
                                <th>Evaluator</th>
                                <?php foreach ($kra_categories as $kra => $label): ?>
                                <th class="text-center">
                                    <?= $label ?>
                                    <div style="font-size:0.66rem;color:#94a3b8;font-weight:500;"><?= sanitize($kra) ?></div>
                                </th>
                                <?php endforeach; ?>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($active_checkers as $checker):
                            $cid = (int)$checker['user_id'];
                            $assigned = $assignment_map[$cid] ?? [];
                            $inactive = $checker['status'] !== 'active';
                        ?>
                        <tr style="<?= $inactive ? 'opacity:0.6;' : '' ?>">
                            <td>
                                <strong style="color:#1e293b;"><?= htmlspecialchars(checkerDisplayLabel($checker)) ?></strong>
                                <div style="font-size:0.72rem;color:#94a3b8;"><?= sanitize($checker['email'] ?? '') ?><?= $inactive ? ' — inactive' : '' ?></div>
                            </td>
                            <?php foreach ($kra_categories as $kra => $label): ?>
                            <td class="text-center">
                                <input type="checkbox"
                                       name="kra_assignments[<?= $cid ?>][]"
                                       value="<?= htmlspecialchars($kra) ?>"
                                       <?= in_array($kra, $assigned, true) ? 'checked' : '' ?>
                                       <?= $inactive ? 'disabled' : '' ?>
                                       style="width:18px;height:18px;">
                            </td>
                            <?php endforeach; ?>
                        </tr>
                        <?php endforeach; ?>
                        <?php if (!$active_checkers): ?>
                        <tr><td colspan="5" class="text-center py-4 text-muted" style="font-size:0.82rem;">No evaluator accounts found.</td></tr>
                        <?php endif; ?>
                        </tbody>
                        <tfoot>
                            <tr style="background:#f8fafc;">
                                <td style="font-weight:700;color:#1a3a6b;font-size:0.8rem;">Coverage</td>
                                <?php foreach ($kra_categories as $kra => $label): ?>
                                <td class="text-center">
                                    <span class="badge" style="background:<?= ($coverage_counts[$kra] ?? 0) > 0 ? '#eff6ff;color:#1e4d8c;border:1px solid #1e4d8c33' : '#fffbeb;color:#92400e;border:1px solid #fcd34d' ?>;">
                                        <?= (int)($coverage_counts[$kra] ?? 0) ?> evaluator<?= (int)($coverage_counts[$kra] ?? 0) === 1 ? '' : 's' ?>
                                    </span>
                                </td>
                                <?php endforeach; ?>
                            </tr>
                        </tfoot>
                    </table>
                </div>
                <div style="display:flex;justify-content:flex-end;padding-top:0.5rem;border-top:1px solid #f1f5f9;">
                    <button class="btn btn-primary btn-sm"
                            style="background:#1a3a6b;border-color:#1a3a6b;font-size:0.82rem;padding:0.42rem 1rem;">
                        Save KRA Assignments
                    </button>
                </div>
            </form>
        <?php endif; ?>
    </div>
</div>

<div class="neon-card" style="padding:1rem 1.25rem;">
    <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:0.75rem;flex-wrap:wrap;gap:0.75rem;">
        <div style="display:flex;align-items:center;gap:0.6rem;">
            <div style="width:36px;height:36px;border-radius:8px;background:#1e4d8c;display:flex;align-items:center;justify-content:center;color:#fff;"><i class="bi bi-people-fill"></i></div>
            <div>
                <div style="font-weight:700;color:#1a3a6b;font-size:0.95rem;">Manage Users</div>
                <div style="font-size:0.72rem;color:#94a3b8;margin-top:1px;"><?= count($users) ?> user<?= count($users)!==1?'s':'' ?> found</div>
            </div>
        </div>
    </div>

    <form method="GET" action="index.php" class="mb-3">
        <input type="hidden" name="page" value="manage_users">
        <div class="row g-2 align-items-end">
            <div class="col-md-4"><label class="form-label">Search</label><input type="text" name="search" value="<?= sanitize($search) ?>" class="form-control" placeholder="Name, email, employee ID..."></div>
            <div class="col-md-2"><label class="form-label">Role</label><select name="role_filter" class="form-select" onchange="this.form.submit()"><option value="">All Roles</option><option value="faculty" <?= $filter==='faculty'?'selected':'' ?>>Faculty</option><option value="evaluator" <?= $filter==='evaluator'?'selected':'' ?>>Evaluator</option><option value="admin" <?= $filter==='admin'?'selected':'' ?>>Admin</option></select></div>
            <div class="col-md-3"><label class="form-label">Campus</label><select name="campus_id" class="form-select" onchange="this.form.submit()"><option value="">All Campuses</option><?php foreach ($campuses as $c): ?><option value="<?= (int)$c['campus_id'] ?>" <?= $filter_campus===(int)$c['campus_id']?'selected':'' ?>><?= sanitize($c['campus_name']) ?></option><?php endforeach; ?></select></div>
            <div class="col-md-3 d-flex gap-2"><button type="submit" class="btn btn-primary"><i class="bi bi-search"></i> Filter</button><?php if ($search || $filter || $filter_campus): ?><a href="index.php?page=manage_users" class="btn btn-outline-secondary">Clear</a><?php endif; ?></div>
        </div>
    </form>

    <div class="table-responsive">
        <table class="table align-middle" style="font-size:0.82rem;">
            <thead><tr style="background:#f8fafc;"><th>Name</th><th>Email</th><th>Employee ID</th><th>Campus</th><th>Rank</th><th>Role</th><th>Status</th><th>Apps</th><th>Joined</th><th>Actions</th></tr></thead>
            <tbody>
            <?php foreach ($users as $u):
                $role_label = match($u['role']) { 'evaluator' => 'Evaluator', 'admin' => 'Admin', default => 'Faculty' };
                $status_label = ucfirst($u['status']);
                $name = formatDisplayName($u);
            ?>
            <tr>
                <td><strong style="color:#1e293b;"><?= htmlspecialchars($name) ?></strong></td>
                <td style="min-width:230px;">
                    <form method="POST" class="d-flex gap-1">
                        <input type="hidden" name="action" value="update_email">
                        <input type="hidden" name="uid" value="<?= (int)$u['user_id'] ?>">
                        <input type="email" name="email" value="<?= sanitize($u['email']) ?>" class="form-control form-control-sm" required>
                        <button class="btn btn-sm btn-outline-secondary" title="Save email"><i class="bi bi-save"></i></button>
                    </form>
                </td>
                <td><?= sanitize($u['employee_id'] ?? 'N/A') ?></td>
                <td><?= sanitize($u['campus_name'] ?? 'N/A') ?></td>
                <td><?= sanitize($u['rank'] ?? 'N/A') ?></td>
                <td><span class="badge" style="background:#eff6ff;color:#1e4d8c;border:1px solid #1e4d8c33;"><?= $role_label ?></span></td>
                <td><span class="badge" style="background:<?= $u['status']==='active' ? '#f0fdf4;color:#16a34a;border:1px solid #16a34a33' : '#fef2f2;color:#dc2626;border:1px solid #dc262633' ?>;"><?= $status_label ?></span></td>
                <td><?= (int)$u['app_count'] ?></td>
                <td style="white-space:nowrap;color:#94a3b8;"><?= date('M d, Y', strtotime($u['created_at'])) ?></td>
                <td>
                    <?php if ((int)$u['user_id'] !== (int)$_SESSION['user_id']): ?>
                    <div class="d-flex gap-1 flex-wrap">
                        <form method="POST" id="statusForm_<?= (int)$u['user_id'] ?>">
                            <input type="hidden" name="action" value="<?= $u['status']==='inactive' ? 'set_active' : 'set_inactive' ?>">
                            <input type="hidden" name="uid" value="<?= (int)$u['user_id'] ?>">
                            <button type="button" class="btn btn-sm <?= $u['status']==='inactive' ? 'btn-outline-primary' : 'btn-outline-secondary' ?>" onclick="confirmDelete('<?= $u['status']==='inactive' ? 'Reactivate' : 'Deactivate' ?> this account?','statusForm_<?= (int)$u['user_id'] ?>','<?= $u['status']==='inactive' ? 'Reactivate' : 'Deactivate' ?>','bi-person-check')">
                                <i class="bi <?= $u['status']==='inactive' ? 'bi-person-check' : 'bi-person-slash' ?>"></i>
                            </button>
                        </form>
                        <button type="button" class="btn btn-sm btn-outline-secondary" onclick="openResetModal(<?= (int)$u['user_id'] ?>,'<?= htmlspecialchars($name, ENT_QUOTES) ?>')" title="Reset password"><i class="bi bi-key"></i></button>
                        <form method="POST" id="deleteUserForm_<?= (int)$u['user_id'] ?>">
                            <input type="hidden" name="action" value="delete_user"><input type="hidden" name="uid" value="<?= (int)$u['user_id'] ?>">
                            <button type="button" class="btn btn-sm btn-outline-danger" onclick="confirmDelete('Delete &ldquo;<?= htmlspecialchars($name, ENT_QUOTES) ?>&rdquo;? This cannot be undone.','deleteUserForm_<?= (int)$u['user_id'] ?>')"><i class="bi bi-trash"></i></button>
                        </form>
                    </div>
                    <?php else: ?><span style="color:#94a3b8;font-style:italic;">You</span><?php endif; ?>
                </td>
            </tr>
            <?php endforeach; ?>
            <?php if (!$users): ?><tr><td colspan="10" class="text-center py-5 text-muted">No users found.</td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<div id="resetPwModal" style="display:none;position:fixed;inset:0;background:rgba(15,23,42,0.55);z-index:9999;align-items:center;justify-content:center;padding:1rem;">
    <div style="background:#fff;border-radius:12px;width:100%;max-width:420px;border:1px solid #e2e8f0;box-shadow:0 20px 60px rgba(0,0,0,0.22);">
        <div style="padding:1rem 1.25rem;border-bottom:1px solid #e2e8f0;display:flex;justify-content:space-between;align-items:center;">
            <strong style="color:#1a3a6b;">Reset Password</strong>
            <button type="button" onclick="document.getElementById('resetPwModal').style.display='none'" style="background:none;border:none;font-size:1.3rem;color:#94a3b8;">&times;</button>
        </div>
        <form method="POST" style="padding:1.25rem;">
            <input type="hidden" name="action" value="reset_password">
            <input type="hidden" name="uid" id="resetUid">
            <div style="font-size:0.84rem;color:#475569;margin-bottom:0.75rem;">Set a new temporary password for <strong id="resetName"></strong>. The user can change it later from their Profile settings.</div>
            <label class="form-label">Temporary Password</label>
            <div class="input-group">
                <input type="text" name="temp_password" id="resetTempPw" class="form-control" minlength="8">
                <button type="button" class="btn btn-outline-secondary" onclick="document.getElementById('resetTempPw').value=makePw()"><i class="bi bi-shuffle"></i></button>
            </div>
            <div class="d-flex justify-content-end gap-2 mt-3">
                <button type="button" class="btn btn-outline-secondary" onclick="document.getElementById('resetPwModal').style.display='none'">Cancel</button>
                <button class="btn btn-primary">Reset Password</button>
            </div>
        </form>
    </div>
</div>

<script>
function makePw() {
    const chars = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghjkmnpqrstuvwxyz23456789@#!';
    let out = chars[Math.floor(Math.random() * 26)];
    for (let i = 0; i < 5; i++) out += chars[26 + Math.floor(Math.random() * 24)];
    out += String(Math.floor(10 + Math.random() * 90));
    out += chars[52 + Math.floor(Math.random() * (chars.length - 52))];
    return out;
}
function generateMuPw() { document.getElementById('muPassword').value = makePw(); }
function toggleMuPw() {
    const el = document.getElementById('muPassword');
    el.type = el.type === 'password' ? 'text' : 'password';
}
function closeCreateModal() {
    document.getElementById('createAccountModal').style.display = 'none';
}
// Close on backdrop click
document.getElementById('createAccountModal').addEventListener('click', function(e) {
    if (e.target === this) closeCreateModal();
});
function toggleKraPanel() {
    const panel   = document.getElementById('kraPanel');
    const chevron = document.getElementById('kraChevron');
    const isOpen  = panel.style.display !== 'none';
    panel.style.display   = isOpen ? 'none' : 'block';
    chevron.style.transform = isOpen ? '' : 'rotate(180deg)';
}
// Auto-open the create panel if there was a form error (flash danger message present)
document.addEventListener('DOMContentLoaded', function() {
    const flash = document.querySelector('.alert-danger');
    if (flash) {
        const panel = document.getElementById('createAccountPanel');
        if (panel) panel.style.display = 'block';
    }
    // Re-open KRA panel after saving assignments (URL has assignment_cycle_id)
    if (new URLSearchParams(window.location.search).has('assignment_cycle_id')) {
        const kp = document.getElementById('kraPanel');
        const kc = document.getElementById('kraChevron');
        if (kp) kp.style.display = 'block';
        if (kc) kc.style.transform = 'rotate(180deg)';
    }
});
function openResetModal(uid, name) {
    document.getElementById('resetUid').value = uid;
    document.getElementById('resetName').textContent = name;
    document.getElementById('resetTempPw').value = makePw();
    document.getElementById('resetPwModal').style.display = 'flex';
}
</script>
