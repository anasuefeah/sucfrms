<?php
if (!isAdmin()) { echo '<div class="alert alert-danger">Access denied.</div>'; return; }
ensureKraAssignmentTable($pdo);

$mailerPath = __DIR__ . '/../vendor/phpmailer/phpmailer/src/';
require_once __DIR__ . '/../config/smtp.php';

function adminRandomPassword(): string {
    $chars = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghjkmnpqrstuvwxyz23456789@#!';
    return $chars[random_int(0, 25)] . substr(str_shuffle('abcdefghjkmnpqrstuvwxyz'), 0, 5) . random_int(10, 99) . $chars[random_int(52, strlen($chars) - 1)];
}

function adminSendTempPasswordEmail(string $to, string $name, string $pass, string $role = 'faculty'): string {
    global $mailerPath;
    if (!file_exists($mailerPath . 'PHPMailer.php')) return 'PHPMailer library not found.';
    try {
        require_once $mailerPath . 'PHPMailer.php';
        require_once $mailerPath . 'SMTP.php';
        require_once $mailerPath . 'Exception.php';
        $mail = new \PHPMailer\PHPMailer\PHPMailer(true);
        $mail->isSMTP();
        $mail->Host       = SMTP_HOST;
        $mail->SMTPAuth   = true;
        $mail->Username   = SMTP_USER;
        $mail->Password   = SMTP_PASS;
        $mail->SMTPSecure = \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port       = SMTP_PORT;
        $mail->CharSet    = 'UTF-8';
        $mail->setFrom(SMTP_FROM, SMTP_FROM_NAME);
        $mail->addAddress($to, $name);
        $mail->isHTML(true);
        $mail->Subject = 'Your SUCFRMS Account Has Been Created';

        $role_label = $role === 'evaluator' ? 'Evaluator' : 'Faculty';
        $role_desc  = $role === 'evaluator'
            ? 'As an Evaluator, you will be responsible for reviewing and verifying faculty reclassification applications assigned to you.'
            : 'As a Faculty member, you can submit your reclassification application, track your scores through the Self-Assessment tool, and manage your evidence files.';
        $login_url = 'https://chmsusucfrms.kesug.com/pages/login.php';
        $circular = 'DBM-CHED Joint Circular No. 01, s. 2026';

        $mail->Body = '
<!DOCTYPE html>
<html lang="en">
<head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1.0"></head>
<body style="margin:0;padding:0;background:#f0f3f8;font-family:\'Segoe UI\',Arial,sans-serif;">
<table width="100%" cellpadding="0" cellspacing="0" style="background:#f0f3f8;padding:32px 0;">
  <tr><td align="center">
    <table width="600" cellpadding="0" cellspacing="0" style="max-width:600px;width:100%;background:#ffffff;border:1px solid #e2e8f0;">

      <!-- Header -->
      <tr>
        <td style="background:#1a3a6b;padding:28px 32px;">
          <table width="100%" cellpadding="0" cellspacing="0">
            <tr>
              <td>
                <div style="color:#ffffff;font-size:18px;font-weight:700;line-height:1.2;">SUCFRMS</div>
                <div style="color:rgba(255,255,255,0.6);font-size:11px;margin-top:3px;letter-spacing:0.04em;">SUC Faculty Reclassification Management System</div>
              </td>
              <td align="right">
                <div style="background:rgba(255,255,255,0.12);border:1px solid rgba(255,255,255,0.2);color:#fff;font-size:11px;font-weight:700;padding:4px 12px;letter-spacing:0.06em;text-transform:uppercase;">' . htmlspecialchars($role_label) . '</div>
              </td>
            </tr>
          </table>
        </td>
      </tr>

      <!-- Greeting -->
      <tr>
        <td style="padding:32px 32px 0;">
          <p style="margin:0 0 8px;font-size:22px;font-weight:700;color:#0f172a;">Welcome, ' . htmlspecialchars($name) . '!</p>
          <p style="margin:0;font-size:14px;color:#64748b;line-height:1.6;">Your account on the <strong>SUC Faculty Reclassification Management System (SUCFRMS)</strong> has been successfully created by the System Administrator.</p>
        </td>
      </tr>

      <!-- What is SUCFRMS -->
      <tr>
        <td style="padding:24px 32px 0;">
          <div style="background:#f8fafc;border-left:3px solid #1a3a6b;padding:14px 16px;">
            <p style="margin:0 0 6px;font-size:12px;font-weight:700;text-transform:uppercase;letter-spacing:0.07em;color:#94a3b8;">About the System</p>
            <p style="margin:0;font-size:13px;color:#334155;line-height:1.65;">SUCFRMS is the official digital platform of <strong>Carlos Hilado Memorial State University</strong> for managing faculty reclassification applications. Faculty can submit KRA evidence and track evaluation progress. The system uses <strong>' . $circular . '</strong> for scoring and rank projection.</p>
          </div>
        </td>
      </tr>

      <!-- Your role -->
      <tr>
        <td style="padding:20px 32px 0;">
          <p style="margin:0;font-size:13px;color:#475569;line-height:1.65;">' . $role_desc . '</p>
        </td>
      </tr>

      <!-- Credentials box -->
      <tr>
        <td style="padding:24px 32px 0;">
          <p style="margin:0 0 12px;font-size:12px;font-weight:700;text-transform:uppercase;letter-spacing:0.07em;color:#94a3b8;">Your Login Credentials</p>
          <table width="100%" cellpadding="0" cellspacing="0" style="border:1px solid #e2e8f0;">
            <tr>
              <td style="padding:14px 16px;border-bottom:1px solid #f1f5f9;width:40%;background:#f8fafc;">
                <div style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:0.07em;color:#94a3b8;">Email Address</div>
                <div style="font-size:14px;font-weight:600;color:#1e293b;margin-top:4px;">' . htmlspecialchars($to) . '</div>
              </td>
              <td style="padding:14px 16px;border-bottom:1px solid #f1f5f9;background:#f8fafc;">
                <div style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:0.07em;color:#94a3b8;">Temporary Password</div>
                <div style="font-size:18px;font-weight:800;color:#1a3a6b;margin-top:4px;font-family:monospace,monospace;letter-spacing:0.08em;">' . htmlspecialchars($pass) . '</div>
              </td>
            </tr>
          </table>
          <p style="margin:10px 0 0;font-size:12px;color:#94a3b8;"><span style="color:#dc2626;font-weight:700;">⚠</span> This is a temporary password. Please change it immediately after your first login from your <strong>Profile</strong> settings.</p>
        </td>
      </tr>

      <!-- Next steps -->
      <tr>
        <td style="padding:24px 32px 0;">
          <p style="margin:0 0 12px;font-size:12px;font-weight:700;text-transform:uppercase;letter-spacing:0.07em;color:#94a3b8;">Getting Started</p>
          <table width="100%" cellpadding="0" cellspacing="0">
            ' . ($role === 'faculty' ? '
            <tr><td style="padding:6px 0;vertical-align:top;width:24px;"><span style="display:inline-block;width:18px;height:18px;background:#1a3a6b;color:#fff;font-size:10px;font-weight:800;text-align:center;line-height:18px;">1</span></td><td style="padding:6px 0;font-size:13px;color:#334155;">Log in and <strong>complete your profile</strong> (rank, campus, employment history).</td></tr>
            <tr><td style="padding:6px 0;vertical-align:top;"><span style="display:inline-block;width:18px;height:18px;background:#1a3a6b;color:#fff;font-size:10px;font-weight:800;text-align:center;line-height:18px;">2</span></td><td style="padding:6px 0;font-size:13px;color:#334155;">Use the <strong>Self-Assessment</strong> tool to estimate your KRA scores before a cycle opens.</td></tr>
            <tr><td style="padding:6px 0;vertical-align:top;"><span style="display:inline-block;width:18px;height:18px;background:#1a3a6b;color:#fff;font-size:10px;font-weight:800;text-align:center;line-height:18px;">3</span></td><td style="padding:6px 0;font-size:13px;color:#334155;">When a cycle opens, submit your <strong>Reclassification Application</strong> with KRA entries and evidence files.</td></tr>
            <tr><td style="padding:6px 0;vertical-align:top;"><span style="display:inline-block;width:18px;height:18px;background:#1a3a6b;color:#fff;font-size:10px;font-weight:800;text-align:center;line-height:18px;">4</span></td><td style="padding:6px 0;font-size:13px;color:#334155;"><strong>Change your password</strong> from Profile &gt; Settings immediately.</td></tr>
            ' : '
            <tr><td style="padding:6px 0;vertical-align:top;width:24px;"><span style="display:inline-block;width:18px;height:18px;background:#1a3a6b;color:#fff;font-size:10px;font-weight:800;text-align:center;line-height:18px;">1</span></td><td style="padding:6px 0;font-size:13px;color:#334155;">Log in and <strong>change your temporary password</strong> from Profile settings.</td></tr>
            <tr><td style="padding:6px 0;vertical-align:top;"><span style="display:inline-block;width:18px;height:18px;background:#1a3a6b;color:#fff;font-size:10px;font-weight:800;text-align:center;line-height:18px;">2</span></td><td style="padding:6px 0;font-size:13px;color:#334155;">Check your <strong>Review Queue</strong> for applications assigned to you.</td></tr>
            <tr><td style="padding:6px 0;vertical-align:top;"><span style="display:inline-block;width:18px;height:18px;background:#1a3a6b;color:#fff;font-size:10px;font-weight:800;text-align:center;line-height:18px;">3</span></td><td style="padding:6px 0;font-size:13px;color:#334155;">Review KRA evidence and mark entries as acceptable or flag for revision.</td></tr>
            ') . '
          </table>
        </td>
      </tr>

      <!-- CTA -->
      <tr>
        <td style="padding:28px 32px 0;text-align:center;">
          <a href="' . $login_url . '"
             style="display:inline-block;background:#1a3a6b;color:#ffffff;font-size:14px;font-weight:700;
                    padding:12px 32px;text-decoration:none;letter-spacing:0.02em;">
            Log In to SUCFRMS
          </a>
        </td>
      </tr>

      <!-- Footer -->
      <tr>
        <td style="padding:28px 32px;border-top:1px solid #f1f5f9;margin-top:28px;">
          <p style="margin:0;font-size:12px;color:#94a3b8;line-height:1.6;">This email was sent by SUCFRMS on behalf of the System Administrator. If you did not expect this account, please contact your campus administrator. Do not share your password with anyone.</p>
          <p style="margin:12px 0 0;font-size:11px;color:#cbd5e1;">&copy; ' . date('Y') . ' Carlos Hilado Memorial State University &nbsp;·&nbsp; SUCFRMS</p>
        </td>
      </tr>

    </table>
  </td></tr>
</table>
</body>
</html>';

        $mail->AltBody = "Welcome to SUCFRMS, {$name}!\n\n"
            . "Your account has been successfully created.\n\n"
            . "SUCFRMS (SUC Faculty Reclassification Management System) is the official platform of Carlos Hilado Memorial State University for managing faculty reclassification applications. The system uses {$circular} for scoring and rank projection.\n\n"
            . "--- YOUR LOGIN CREDENTIALS ---\n"
            . "Email:              {$to}\n"
            . "Temporary Password: {$pass}\n\n"
            . "IMPORTANT: Change your password immediately after your first login from Profile > Settings.\n\n"
            . "Log in at: {$login_url}\n\n"
            . "If you did not expect this account, please contact your campus administrator.\n\n"
            . "-- SUCFRMS Team\n"
            . "Carlos Hilado Memorial State University";

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
                $mail_error = adminSendTempPasswordEmail($email, $name, $password, $role);
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
<div style="background:#fff;border:1px solid #e2e8f0;padding:0 1.25rem;margin-bottom:1.25rem;
            display:flex;align-items:center;justify-content:space-between;min-height:60px;">
    <div style="display:flex;align-items:center;gap:0.75rem;">
        <div style="width:36px;height:36px;background:#1a3a6b;display:flex;align-items:center;justify-content:center;flex-shrink:0;">
            <i class="bi bi-people-fill" style="color:#fff;font-size:0.9rem;"></i>
        </div>
        <div>
            <div style="font-weight:700;color:#1a3a6b;font-size:0.95rem;line-height:1.2;">Manage Users</div>
            <div style="font-size:0.72rem;color:#94a3b8;margin-top:2px;">Create and manage faculty and evaluator accounts</div>
        </div>
    </div>
    <button onclick="document.getElementById('createAccountModal').style.display='flex'"
            style="display:inline-flex;align-items:center;gap:0.4rem;padding:0.45rem 1.1rem;
                   background:#1a3a6b;color:#fff;border:none;
                   font-size:0.8rem;font-weight:700;cursor:pointer;white-space:nowrap;flex-shrink:0;"
            onmouseover="this.style.background='#162f56'" onmouseout="this.style.background='#1a3a6b'">
        <i class="bi bi-person-plus-fill"></i> Add Account
    </button>
</div>

<!-- ── Create Account Modal ─────────────────────────────────────── -->
<div id="createAccountModal"
     style="display:none;position:fixed;inset:0;background:rgba(15,23,42,0.55);
            z-index:99999;align-items:center;justify-content:center;padding:1rem;">
    <div style="background:#fff;width:100%;max-width:580px;border:1px solid #e2e8f0;
                box-shadow:0 16px 48px rgba(0,0,0,0.2);overflow:hidden;">

        <!-- Modal header -->
        <div style="background:#1a3a6b;padding:0.8rem 1.1rem;display:flex;align-items:center;justify-content:space-between;">
            <div style="display:flex;align-items:center;gap:0.5rem;">
                <i class="bi bi-person-plus-fill" style="color:#fff;font-size:0.85rem;"></i>
                <div>
                    <div style="color:#fff;font-weight:700;font-size:0.85rem;line-height:1.2;">Create New Account</div>
                    <div style="color:rgba(255,255,255,0.55);font-size:0.65rem;margin-top:1px;">Faculty or evaluator account</div>
                </div>
            </div>
            <button onclick="closeCreateModal()"
                    style="background:none;border:none;color:rgba(255,255,255,0.7);cursor:pointer;font-size:1.1rem;line-height:1;padding:0;"
                    onmouseover="this.style.color='#fff'" onmouseout="this.style.color='rgba(255,255,255,0.7)'">
                <i class="bi bi-x-lg"></i>
            </button>
        </div>

        <!-- Modal body -->
        <div style="padding:1.1rem 1.25rem;">
            <p style="font-size:0.72rem;color:#64748b;margin-bottom:1rem;padding:.5rem .7rem;background:#f8fafc;border-left:3px solid #1a3a6b;">
                An account creation email with login credentials and system introduction will be sent automatically.
            </p>
            <form method="POST" id="createAccountForm">
                <input type="hidden" name="action" value="create_account">

                <!-- Section: Account Details -->
                <div style="font-size:0.58rem;font-weight:700;text-transform:uppercase;letter-spacing:0.09em;
                            color:#94a3b8;margin-bottom:0.5rem;padding-bottom:0.3rem;border-bottom:1px solid #f1f5f9;">
                    Account Details
                </div>

                <!-- Row 1: Type + First + Middle + Last -->
                <div style="display:grid;grid-template-columns:140px 1fr 1fr 1fr;gap:0.5rem;margin-bottom:0.5rem;">
                    <div>
                        <label style="font-size:0.65rem;font-weight:700;text-transform:uppercase;letter-spacing:0.06em;color:#94a3b8;display:block;margin-bottom:3px;">Type <span style="color:#dc2626;">*</span></label>
                        <select name="role" required style="width:100%;border:1px solid #e2e8f0;padding:0.38rem 0.5rem;font-size:0.8rem;color:#1e293b;background:#fff;outline:none;cursor:pointer;"
                                onfocus="this.style.borderColor='#1a3a6b'" onblur="this.style.borderColor='#e2e8f0'">
                            <option value="faculty">Faculty</option>
                            <option value="evaluator">Evaluator</option>
                        </select>
                    </div>
                    <div>
                        <label style="font-size:0.65rem;font-weight:700;text-transform:uppercase;letter-spacing:0.06em;color:#94a3b8;display:block;margin-bottom:3px;">First Name <span style="color:#dc2626;">*</span></label>
                        <input type="text" name="first_name" required style="width:100%;border:1px solid #e2e8f0;padding:0.38rem 0.5rem;font-size:0.8rem;outline:none;"
                               onfocus="this.style.borderColor='#1a3a6b'" onblur="this.style.borderColor='#e2e8f0'">
                    </div>
                    <div>
                        <label style="font-size:0.65rem;font-weight:700;text-transform:uppercase;letter-spacing:0.06em;color:#94a3b8;display:block;margin-bottom:3px;">Middle Name</label>
                        <input type="text" name="middle_name" id="muMiddle" style="width:100%;border:1px solid #e2e8f0;padding:0.38rem 0.5rem;font-size:0.8rem;outline:none;"
                               onfocus="this.style.borderColor='#1a3a6b'" onblur="this.style.borderColor='#e2e8f0'">
                        <label style="font-size:0.65rem;color:#94a3b8;margin-top:3px;display:flex;gap:4px;align-items:center;cursor:pointer;">
                            <input type="checkbox" name="no_middle_name" onchange="document.getElementById('muMiddle').disabled=this.checked;document.getElementById('muMiddle').value=this.checked?'':document.getElementById('muMiddle').value;">
                            No middle name
                        </label>
                    </div>
                    <div>
                        <label style="font-size:0.65rem;font-weight:700;text-transform:uppercase;letter-spacing:0.06em;color:#94a3b8;display:block;margin-bottom:3px;">Last Name <span style="color:#dc2626;">*</span></label>
                        <input type="text" name="last_name" required style="width:100%;border:1px solid #e2e8f0;padding:0.38rem 0.5rem;font-size:0.8rem;outline:none;"
                               onfocus="this.style.borderColor='#1a3a6b'" onblur="this.style.borderColor='#e2e8f0'">
                    </div>
                </div>

                <!-- Section: Login Credentials -->
                <div style="font-size:0.58rem;font-weight:700;text-transform:uppercase;letter-spacing:0.09em;
                            color:#94a3b8;margin-bottom:0.5rem;padding-bottom:0.3rem;border-bottom:1px solid #f1f5f9;margin-top:.85rem;">
                    Login Credentials
                </div>

                <!-- Row 2: Email + Password -->
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:0.5rem;margin-bottom:0.75rem;">
                    <div>
                        <label style="font-size:0.65rem;font-weight:700;text-transform:uppercase;letter-spacing:0.06em;color:#94a3b8;display:block;margin-bottom:3px;">Email <span style="color:#dc2626;">*</span></label>
                        <input type="email" name="email" required style="width:100%;border:1px solid #e2e8f0;padding:0.38rem 0.5rem;font-size:0.8rem;outline:none;"
                               onfocus="this.style.borderColor='#1a3a6b'" onblur="this.style.borderColor='#e2e8f0'">
                    </div>
                    <div>
                        <label style="font-size:0.65rem;font-weight:700;text-transform:uppercase;letter-spacing:0.06em;color:#94a3b8;display:block;margin-bottom:3px;">Temporary Password <span style="color:#dc2626;">*</span></label>
                        <div style="display:flex;">
                            <input type="password" name="password" id="muPassword" minlength="8" required
                                   style="flex:1;border:1px solid #e2e8f0;border-right:none;padding:0.38rem 0.5rem;font-size:0.8rem;outline:none;min-width:0;"
                                   onfocus="this.style.borderColor='#1a3a6b'" onblur="this.style.borderColor='#e2e8f0'">
                            <button type="button" onclick="toggleMuPw()" title="Show/hide"
                                    style="padding:0.38rem 0.5rem;border:1px solid #e2e8f0;border-right:none;background:#f8fafc;color:#475569;cursor:pointer;font-size:0.78rem;"
                                    onmouseover="this.style.background='#1a3a6b';this.style.color='#fff'" onmouseout="this.style.background='#f8fafc';this.style.color='#475569'">
                                <i class="bi bi-eye"></i>
                            </button>
                            <button type="button" onclick="generateMuPw()" title="Generate password"
                                    style="padding:0.38rem 0.5rem;border:1px solid #e2e8f0;background:#f8fafc;color:#475569;cursor:pointer;font-size:0.78rem;"
                                    onmouseover="this.style.background='#1a3a6b';this.style.color='#fff'" onmouseout="this.style.background='#f8fafc';this.style.color='#475569'">
                                <i class="bi bi-shuffle"></i>
                            </button>
                        </div>
                    </div>
                </div>

                <!-- Footer actions -->
                <div style="display:flex;justify-content:flex-end;gap:0.5rem;padding-top:0.75rem;border-top:1px solid #f1f5f9;">
                    <button type="button" onclick="closeCreateModal()"
                            style="padding:0.42rem 1rem;border:1px solid #e2e8f0;background:#fff;color:#475569;font-size:0.78rem;font-weight:600;cursor:pointer;"
                            onmouseover="this.style.background='#f8fafc'" onmouseout="this.style.background='#fff'">
                        Cancel
                    </button>
                    <button type="submit"
                            style="padding:0.42rem 1.15rem;border:none;background:#1a3a6b;color:#fff;font-size:0.78rem;font-weight:700;cursor:pointer;display:inline-flex;align-items:center;gap:5px;"
                            onmouseover="this.style.background='#152d54'" onmouseout="this.style.background='#1a3a6b'">
                        <i class="bi bi-person-plus" style="font-size:0.75rem;"></i> Create Account
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- KRA Assignment card — removed, now a dedicated page (kra_assignments) -->

<!-- User Accounts section header -->
<div style="background:#fff;border:1px solid #e2e8f0;padding:0 1.25rem;margin-bottom:0;
            display:flex;align-items:center;justify-content:space-between;min-height:52px;border-bottom:none;">
    <div style="display:flex;align-items:center;gap:0.65rem;">
        <div style="width:30px;height:30px;background:#f0f4fb;border:1px solid #dbeafe;display:flex;align-items:center;justify-content:center;flex-shrink:0;">
            <i class="bi bi-person-lines-fill" style="color:#1a3a6b;font-size:0.78rem;"></i>
        </div>
        <div>
            <div style="font-weight:700;color:#1a3a6b;font-size:0.82rem;line-height:1.2;">User Accounts</div>
            <div style="font-size:0.65rem;color:#94a3b8;margin-top:1px;">
                <?= count($users) ?> account<?= count($users)!==1?'s':'' ?> found
                <?php if ($search || $filter || $filter_campus): ?>
                &nbsp;·&nbsp; <a href="index.php?page=manage_users" style="color:#1a3a6b;text-decoration:none;font-weight:600;">Clear filters</a>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <div style="display:flex;align-items:center;gap:0.4rem;">
        <?php
        $role_counts = array_count_values(array_column($users, 'role'));
        foreach (['faculty'=>'Faculty','evaluator'=>'Evaluator','admin'=>'Admin'] as $r => $rl):
            $rc = $role_counts[$r] ?? 0;
            if (!$rc) continue;
        ?>
        <span style="font-size:0.65rem;font-weight:700;padding:2px 8px;
                     background:<?= $r==='admin'?'#fef3c7':($r==='evaluator'?'#f0fdf4':'#eff6ff') ?>;
                     color:<?= $r==='admin'?'#92400e':($r==='evaluator'?'#15803d':'#1e4d8c') ?>;
                     border:1px solid <?= $r==='admin'?'#fde68a':($r==='evaluator'?'#bbf7d0':'#bfdbfe') ?>;">
            <?= $rc ?> <?= $rl ?>
        </span>
        <?php endforeach; ?>
    </div>
</div>

<!-- Users table card -->
<div style="background:#fff;border:1px solid #e2e8f0;overflow:hidden;">
    <!-- Search & filter bar -->
    <div style="padding:0.75rem 1.25rem;border-bottom:1px solid #e2e8f0;background:#f8fafc;">
        <form method="GET" action="index.php">
            <input type="hidden" name="page" value="manage_users">
            <div style="display:flex;flex-wrap:wrap;gap:0.5rem;align-items:flex-end;">
                <div style="flex:2;min-width:180px;">
                    <div style="font-size:0.58rem;font-weight:700;text-transform:uppercase;letter-spacing:0.08em;color:#94a3b8;margin-bottom:3px;">Search</div>
                    <div style="display:flex;align-items:center;border:1px solid #e2e8f0;background:#fff;" onfocusin="this.style.borderColor='#1a3a6b'" onfocusout="this.style.borderColor='#e2e8f0'">
                        <span style="padding:0 0.6rem;color:#94a3b8;font-size:0.78rem;"><i class="bi bi-search"></i></span>
                        <input type="text" name="search" value="<?= sanitize($search) ?>" placeholder="Name, email, employee ID..."
                               style="flex:1;border:none;outline:none;background:transparent;padding:0.38rem 0.5rem 0.38rem 0;font-size:0.78rem;color:#1e293b;">
                    </div>
                </div>
                <div style="flex:1;min-width:130px;">
                    <div style="font-size:0.58rem;font-weight:700;text-transform:uppercase;letter-spacing:0.08em;color:#94a3b8;margin-bottom:3px;">Role</div>
                    <select name="role_filter" onchange="this.form.submit()"
                            style="width:100%;border:1px solid #e2e8f0;padding:0.38rem 0.6rem;font-size:0.78rem;color:#1e293b;background:#fff;outline:none;cursor:pointer;"
                            onfocus="this.style.borderColor='#1a3a6b'" onblur="this.style.borderColor='#e2e8f0'">
                        <option value="">All Roles</option>
                        <option value="faculty"   <?= $filter==='faculty'   ?'selected':'' ?>>Faculty</option>
                        <option value="evaluator" <?= $filter==='evaluator' ?'selected':'' ?>>Evaluator</option>
                        <option value="admin"     <?= $filter==='admin'     ?'selected':'' ?>>Admin</option>
                    </select>
                </div>
                <div style="flex:1;min-width:160px;">
                    <div style="font-size:0.58rem;font-weight:700;text-transform:uppercase;letter-spacing:0.08em;color:#94a3b8;margin-bottom:3px;">Campus</div>
                    <select name="campus_id" onchange="this.form.submit()"
                            style="width:100%;border:1px solid #e2e8f0;padding:0.38rem 0.6rem;font-size:0.78rem;color:#1e293b;background:#fff;outline:none;cursor:pointer;"
                            onfocus="this.style.borderColor='#1a3a6b'" onblur="this.style.borderColor='#e2e8f0'">
                        <option value="">All Campuses</option>
                        <?php foreach ($campuses as $c): ?>
                        <option value="<?= (int)$c['campus_id'] ?>" <?= $filter_campus===(int)$c['campus_id']?'selected':'' ?>><?= sanitize($c['campus_name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div style="display:flex;gap:0.4rem;align-items:flex-end;">
                    <button type="submit" style="padding:0.38rem 0.9rem;background:#1a3a6b;color:#fff;border:none;font-size:0.78rem;font-weight:600;cursor:pointer;display:inline-flex;align-items:center;gap:4px;">
                        <i class="bi bi-search" style="font-size:0.72rem;"></i> Filter
                    </button>
                    <?php if ($search || $filter || $filter_campus): ?>
                    <a href="index.php?page=manage_users" style="padding:0.38rem 0.75rem;border:1px solid #e2e8f0;background:#fff;color:#475569;font-size:0.78rem;font-weight:600;text-decoration:none;display:inline-flex;align-items:center;gap:4px;">
                        <i class="bi bi-x" style="font-size:0.78rem;"></i> Clear
                    </a>
                    <?php endif; ?>
                </div>
            </div>
        </form>
    </div>

    <!-- Table -->
    <div style="font-size:0.65rem;font-weight:700;text-transform:uppercase;letter-spacing:0.07em;color:#94a3b8;padding:0.4rem 1.25rem;background:#f8fafc;border-bottom:1px solid #e2e8f0;">
        <?= count($users) ?> user<?= count($users)!==1?'s':'' ?> found
    </div>
    <div class="table-responsive">
        <table style="width:100%;border-collapse:collapse;font-size:0.8rem;">
            <thead>
            <tr style="background:#f8fafc;border-bottom:1px solid #e2e8f0;">
                <?php foreach (['Name','Email','Employee ID','Campus','Rank','Role','Status','Apps','Joined',''] as $h): ?>
                <th style="padding:0.5rem <?= $h ? '0.75rem' : '1rem' ?>;text-align:left;font-size:0.6rem;font-weight:700;text-transform:uppercase;letter-spacing:0.07em;color:#94a3b8;white-space:nowrap;"><?= $h ?></th>
                <?php endforeach; ?>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($users as $u):
                $role_label   = match($u['role']) { 'evaluator'=>'Evaluator','admin'=>'Admin',default=>'Faculty' };
                $role_color   = match($u['role']) { 'evaluator'=>['#f0fdf4','#16a34a','#bbf7d0'],'admin'=>['#fef3c7','#92400e','#fde68a'],default=>['#eff6ff','#1e4d8c','#bfdbfe'] };
                $status_color = $u['status']==='active' ? ['#f0fdf4','#16a34a','#bbf7d0'] : ['#fef2f2','#dc2626','#fecaca'];
                $name = formatDisplayName($u);
            ?>
            <tr style="border-bottom:1px solid #f1f5f9;" onmouseover="this.style.background='#fafafa'" onmouseout="this.style.background=''">
                <td style="padding:0.65rem 0.75rem;font-weight:600;color:#1e293b;white-space:nowrap;"><?= htmlspecialchars($name) ?></td>
                <td style="padding:0.65rem 0.75rem;min-width:220px;">
                    <form method="POST" style="display:flex;gap:4px;">
                        <input type="hidden" name="action" value="update_email">
                        <input type="hidden" name="uid" value="<?= (int)$u['user_id'] ?>">
                        <input type="email" name="email" value="<?= sanitize($u['email']) ?>"
                               style="flex:1;border:1px solid #e2e8f0;padding:0.28rem 0.5rem;font-size:0.76rem;min-width:0;"
                               onfocus="this.style.borderColor='#1a3a6b'" onblur="this.style.borderColor='#e2e8f0'">
                        <button style="padding:0.28rem 0.5rem;border:1px solid #e2e8f0;background:#f8fafc;color:#475569;cursor:pointer;font-size:0.72rem;" title="Save email"
                                onmouseover="this.style.background='#1a3a6b';this.style.color='#fff';this.style.borderColor='#1a3a6b'"
                                onmouseout="this.style.background='#f8fafc';this.style.color='#475569';this.style.borderColor='#e2e8f0'">
                            <i class="bi bi-check-lg"></i>
                        </button>
                    </form>
                </td>
                <td style="padding:0.65rem 0.75rem;">
                    <?php if (!empty($u['employee_id'])): ?>
                    <span style="color:#64748b;font-size:0.8rem;"><?= sanitize($u['employee_id']) ?></span>
                    <?php else: ?>
                    <span style="font-size:0.65rem;color:#94a3b8;background:#f8fafc;border:1px dashed #cbd5e1;padding:1px 7px;font-style:italic;">Not set</span>
                    <?php endif; ?>
                </td>
                <td style="padding:0.65rem 0.75rem;white-space:nowrap;">
                    <?php if (!empty($u['campus_name'])): ?>
                    <span style="color:#64748b;font-size:0.8rem;"><?= sanitize($u['campus_name']) ?></span>
                    <?php else: ?>
                    <span style="font-size:0.65rem;color:#94a3b8;background:#f8fafc;border:1px dashed #cbd5e1;padding:1px 7px;font-style:italic;">Not set</span>
                    <?php endif; ?>
                </td>
                <td style="padding:0.65rem 0.75rem;max-width:140px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;" title="<?= sanitize($u['rank'] ?? '') ?>">
                    <?php if (!empty($u['rank'])): ?>
                    <span style="color:#64748b;font-size:0.8rem;"><?= sanitize($u['rank']) ?></span>
                    <?php else: ?>
                    <span style="font-size:0.65rem;color:#94a3b8;background:#f8fafc;border:1px dashed #cbd5e1;padding:1px 7px;font-style:italic;">Not set</span>
                    <?php endif; ?>
                </td>
                <td style="padding:0.65rem 0.75rem;">
                    <span style="font-size:0.65rem;font-weight:700;padding:2px 8px;background:<?= $role_color[0] ?>;color:<?= $role_color[1] ?>;border:1px solid <?= $role_color[2] ?>;">
                        <?= $role_label ?>
                    </span>
                </td>
                <td style="padding:0.65rem 0.75rem;">
                    <span style="font-size:0.65rem;font-weight:700;padding:2px 8px;background:<?= $status_color[0] ?>;color:<?= $status_color[1] ?>;border:1px solid <?= $status_color[2] ?>;">
                        <?= ucfirst($u['status']) ?>
                    </span>
                </td>
                <td style="padding:0.65rem 0.75rem;text-align:center;color:#475569;"><?= (int)$u['app_count'] ?></td>
                <td style="padding:0.65rem 0.75rem;white-space:nowrap;color:#94a3b8;font-size:0.72rem;"><?= date('M d, Y', strtotime($u['created_at'])) ?></td>
                <td style="padding:0.65rem 1rem;text-align:right;">
                    <?php if ((int)$u['user_id'] !== (int)$_SESSION['user_id']): ?>
                    <div style="display:flex;gap:4px;justify-content:flex-end;">
                        <form method="POST" id="statusForm_<?= (int)$u['user_id'] ?>">
                            <input type="hidden" name="action" value="<?= $u['status']==='inactive' ? 'set_active' : 'set_inactive' ?>">
                            <input type="hidden" name="uid" value="<?= (int)$u['user_id'] ?>">
                            <button type="button" title="<?= $u['status']==='inactive' ? 'Reactivate' : 'Deactivate' ?>"
                                    onclick="confirmDelete('<?= $u['status']==='inactive' ? 'Reactivate' : 'Deactivate' ?> this account?','statusForm_<?= (int)$u['user_id'] ?>','<?= $u['status']==='inactive' ? 'Reactivate' : 'Deactivate' ?>','bi-person-check')"
                                    style="width:30px;height:30px;border:1px solid #e2e8f0;background:#f8fafc;color:#475569;cursor:pointer;display:flex;align-items:center;justify-content:center;"
                                    onmouseover="this.style.background='#1a3a6b';this.style.color='#fff';this.style.borderColor='#1a3a6b'"
                                    onmouseout="this.style.background='#f8fafc';this.style.color='#475569';this.style.borderColor='#e2e8f0'">
                                <i class="bi <?= $u['status']==='inactive' ? 'bi-person-check' : 'bi-person-slash' ?>" style="font-size:0.72rem;"></i>
                            </button>
                        </form>
                        <button type="button" title="Reset password"
                                onclick="openResetModal(<?= (int)$u['user_id'] ?>,'<?= htmlspecialchars($name, ENT_QUOTES) ?>')"
                                style="width:30px;height:30px;border:1px solid #e2e8f0;background:#f8fafc;color:#475569;cursor:pointer;display:flex;align-items:center;justify-content:center;"
                                onmouseover="this.style.background='#1a3a6b';this.style.color='#fff';this.style.borderColor='#1a3a6b'"
                                onmouseout="this.style.background='#f8fafc';this.style.color='#475569';this.style.borderColor='#e2e8f0'">
                            <i class="bi bi-key" style="font-size:0.72rem;"></i>
                        </button>
                        <form method="POST" id="deleteUserForm_<?= (int)$u['user_id'] ?>">
                            <input type="hidden" name="action" value="delete_user">
                            <input type="hidden" name="uid" value="<?= (int)$u['user_id'] ?>">
                            <button type="button" title="Delete user"
                                    onclick="confirmDelete('Delete &ldquo;<?= htmlspecialchars($name, ENT_QUOTES) ?>&rdquo;? This cannot be undone.','deleteUserForm_<?= (int)$u['user_id'] ?>')"
                                    style="width:30px;height:30px;border:1px solid #fecaca;background:#fff;color:#dc2626;cursor:pointer;display:flex;align-items:center;justify-content:center;"
                                    onmouseover="this.style.background='#fef2f2'" onmouseout="this.style.background='#fff'">
                                <i class="bi bi-trash" style="font-size:0.72rem;"></i>
                            </button>
                        </form>
                    </div>
                    <?php else: ?>
                    <span style="font-size:0.72rem;color:#94a3b8;font-style:italic;">You</span>
                    <?php endif; ?>
                </td>
            </tr>
            <?php endforeach; ?>
            <?php if (!$users): ?>
            <tr><td colspan="10" style="padding:3rem;text-align:center;color:#94a3b8;font-size:0.82rem;">No users found.</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Reset Password Modal -->
<div id="resetPwModal" style="display:none;position:fixed;inset:0;background:rgba(15,23,42,0.55);z-index:9999;align-items:center;justify-content:center;padding:1rem;">
    <div style="background:#fff;width:100%;max-width:400px;border:1px solid #e2e8f0;box-shadow:0 16px 48px rgba(0,0,0,0.2);overflow:hidden;">
        <div style="background:#1a3a6b;padding:0.75rem 1rem;display:flex;align-items:center;justify-content:space-between;">
            <span style="color:#fff;font-weight:700;font-size:0.85rem;">Reset Password</span>
            <button type="button" onclick="document.getElementById('resetPwModal').style.display='none'"
                    style="background:none;border:none;color:rgba(255,255,255,0.7);font-size:1.1rem;cursor:pointer;padding:0;line-height:1;"
                    onmouseover="this.style.color='#fff'" onmouseout="this.style.color='rgba(255,255,255,0.7)'">&times;</button>
        </div>
        <form method="POST" style="padding:1rem 1.1rem;">
            <input type="hidden" name="action" value="reset_password">
            <input type="hidden" name="uid" id="resetUid">
            <div style="font-size:0.78rem;color:#475569;margin-bottom:0.75rem;line-height:1.6;">Set a new temporary password for <strong id="resetName"></strong>. The user can change it from their Profile settings.</div>
            <div style="font-size:0.6rem;font-weight:700;text-transform:uppercase;letter-spacing:0.07em;color:#94a3b8;margin-bottom:4px;">Temporary Password</div>
            <div style="display:flex;gap:4px;margin-bottom:0.85rem;">
                <input type="text" name="temp_password" id="resetTempPw" minlength="8" style="flex:1;font-size:0.8rem;font-family:monospace;border:1px solid #e2e8f0;padding:0.3rem 0.5rem;outline:none;"
                       onfocus="this.style.borderColor='#1a3a6b'" onblur="this.style.borderColor='#e2e8f0'">
                <button type="button" onclick="document.getElementById('resetTempPw').value=makePw()"
                        style="padding:0.3rem 0.6rem;border:1px solid #e2e8f0;background:#f8fafc;color:#475569;cursor:pointer;font-size:0.75rem;"
                        onmouseover="this.style.background='#1a3a6b';this.style.color='#fff'" onmouseout="this.style.background='#f8fafc';this.style.color='#475569'">
                    <i class="bi bi-shuffle"></i>
                </button>
            </div>
            <div style="display:flex;justify-content:flex-end;gap:0.5rem;border-top:1px solid #f1f5f9;padding-top:0.75rem;">
                <button type="button" onclick="document.getElementById('resetPwModal').style.display='none'"
                        style="padding:0.38rem 0.9rem;border:1px solid #e2e8f0;background:#fff;color:#475569;font-size:0.78rem;font-weight:600;cursor:pointer;"
                        onmouseover="this.style.background='#f8fafc'" onmouseout="this.style.background='#fff'">Cancel</button>
                <button style="padding:0.38rem 0.9rem;background:#1a3a6b;color:#fff;border:none;font-size:0.78rem;font-weight:700;cursor:pointer;"
                        onmouseover="this.style.background='#152d54'" onmouseout="this.style.background='#1a3a6b'">Reset Password</button>
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
document.getElementById('createAccountModal').addEventListener('click', function(e) {
    if (e.target === this) closeCreateModal();
});
function openResetModal(uid, name) {
    document.getElementById('resetUid').value = uid;
    document.getElementById('resetName').textContent = name;
    document.getElementById('resetTempPw').value = makePw();
    document.getElementById('resetPwModal').style.display = 'flex';
}
</script>
