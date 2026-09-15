<?php
if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/functions.php';

if (isLoggedIn()) { header('Location: ../index.php'); exit; }

$mailerPath = __DIR__ . '/../vendor/phpmailer/phpmailer/src/';
$mailerOk   = file_exists($mailerPath . 'PHPMailer.php');

define('SMTP_HOST',     'smtp.gmail.com');
define('SMTP_PORT',     587);
define('SMTP_USER',     'sucfrms.chmsu@gmail.com');
define('SMTP_PASS',     'wwbbxdrtpuammsma');
define('SMTP_FROM',     'sucfrms.chmsu@gmail.com');
define('SMTP_FROM_NAME','SUCFRMS');

function sendTempPasswordEmail(string $to, string $name, string $pass): string {
    // Returns '' on success, error message on failure
    global $mailerPath, $mailerOk;
    if (!$mailerOk) {
        return 'PHPMailer library not found. Contact the administrator.';
    }
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
        $mail->Body = '<!DOCTYPE html>
<html>
<head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"></head>
<body style="margin:0;padding:0;background:#f0f4fb;font-family:\'Segoe UI\',Arial,sans-serif;">
<table width="100%" cellpadding="0" cellspacing="0" style="background:#f0f4fb;padding:40px 0;">
  <tr><td align="center">
    <table width="580" cellpadding="0" cellspacing="0" style="max-width:580px;width:100%;background:#ffffff;border-radius:12px;overflow:hidden;box-shadow:0 4px 24px rgba(26,58,107,0.10);">

      <!-- Header -->
      <tr>
        <td style="background:linear-gradient(135deg,#1a3a6b 0%,#1e4d8c 100%);padding:32px 40px;text-align:center;">
          <div style="display:inline-block;width:56px;height:56px;border-radius:50%;border:2px solid #475569;overflow:hidden;margin-bottom:14px;">
            <img src="https://i.imgur.com/placeholder.png" alt="SUCFRMS" width="56" height="56" style="width:56px;height:56px;object-fit:cover;display:block;">
          </div>
          <div style="color:#ffffff;font-size:1.3rem;font-weight:700;letter-spacing:0.04em;margin-bottom:4px;">SUCFRMS</div>
          <div style="color:rgba(255,255,255,0.65);font-size:0.78rem;letter-spacing:0.06em;text-transform:uppercase;">SUC Faculty Reclassification Management System</div>
        </td>
      </tr>

      <!-- Gold accent bar -->
      <tr><td style="background:#475569;height:3px;font-size:0;"></td></tr>

      <!-- Body -->
      <tr>
        <td style="padding:36px 40px 28px;">
          <p style="margin:0 0 8px;font-size:0.82rem;color:#94a3b8;text-transform:uppercase;letter-spacing:0.08em;font-weight:600;">Account Registration</p>
          <h2 style="margin:0 0 20px;color:#1a3a6b;font-size:1.3rem;font-weight:700;">Welcome to SUCFRMS</h2>
          <p style="margin:0 0 8px;color:#475569;font-size:0.9rem;line-height:1.7;">Dear <strong style="color:#1a3a6b;">'.htmlspecialchars($name).'</strong>,</p>
          <p style="margin:0 0 24px;color:#475569;font-size:0.9rem;line-height:1.7;">
            Your account on the SUC Faculty Reclassification Management System has been successfully created.
            Please use the temporary password below to log in for the first time.
          </p>

          <!-- Password box -->
          <table width="100%" cellpadding="0" cellspacing="0" style="margin-bottom:24px;">
            <tr>
              <td style="background:#f8fafc;border:1px solid #e2e8f0;border-left:4px solid #1a3a6b;border-radius:8px;padding:20px 24px;text-align:center;">
                <div style="font-size:0.7rem;color:#94a3b8;letter-spacing:0.12em;text-transform:uppercase;font-weight:600;margin-bottom:10px;">Temporary Password</div>
                <div style="font-size:1.75rem;font-weight:800;color:#1a3a6b;letter-spacing:0.15em;font-family:\'Courier New\',monospace;background:#ffffff;display:inline-block;padding:10px 28px;border-radius:6px;border:1px solid #dbeafe;">'.htmlspecialchars($pass).'</div>
                <div style="margin-top:10px;font-size:0.75rem;color:#1e293b;font-weight:600;">&#9888; For security, change this password immediately after logging in.</div>
              </td>
            </tr>
          </table>

          <!-- Steps -->
          <table width="100%" cellpadding="0" cellspacing="0" style="margin-bottom:28px;background:#f0f4fb;border-radius:8px;">
            <tr><td style="padding:16px 20px;">
              <div style="font-size:0.75rem;font-weight:700;color:#1a3a6b;text-transform:uppercase;letter-spacing:0.06em;margin-bottom:10px;">Getting Started</div>
              <div style="font-size:0.85rem;color:#475569;line-height:1.8;">
                <span style="color:#475569;font-weight:700;">1.</span> Go to the SUCFRMS login page<br>
                <span style="color:#475569;font-weight:700;">2.</span> Enter your registered email address<br>
                <span style="color:#475569;font-weight:700;">3.</span> Enter the temporary password above<br>
                <span style="color:#475569;font-weight:700;">4.</span> Change your password in your Profile settings
              </div>
            </td></tr>
          </table>

          <p style="margin:0;color:#94a3b8;font-size:0.8rem;line-height:1.6;">
            If you did not register for SUCFRMS, please disregard this email. No action is required on your part.
          </p>
        </td>
      </tr>

      <!-- Footer -->
      <tr>
        <td style="background:#f8fafc;border-top:1px solid #e2e8f0;padding:20px 40px;">
          <table width="100%" cellpadding="0" cellspacing="0">
            <tr>
              <td style="font-size:0.75rem;color:#94a3b8;line-height:1.6;">
                <strong style="color:#1a3a6b;">SUCFRMS</strong> &mdash; SUC Faculty Reclassification Management System<br>
                State Universities and Colleges &mdash; Official System
              </td>
              <td align="right" style="font-size:0.7rem;color:#cbd5e1;white-space:nowrap;">
                This is an automated email.<br>Please do not reply.
              </td>
            </tr>
          </table>
        </td>
      </tr>

    </table>
  </td></tr>
</table>
</body></html>';
        $mail->AltBody = "Hello $name,\n\nYour SUCFRMS temporary password is: $pass\n\nLog in and change your password immediately.\n\n- SUCFRMS";
        $mail->send();
        return ''; // success
    } catch (\Exception $e) {
        error_log('SUCFRMS register email FAILED: ' . $e->getMessage());
        return $e->getMessage();
    }
}

$campuses    = $pdo->query("SELECT * FROM campuses ORDER BY is_active DESC, campus_name ASC")->fetchAll();
$error       = '';
$success     = false;
$email_sent  = false;
$smtp_error  = '';
$temp_pass  = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $first_name  = trim($_POST['first_name'] ?? '');
    $middle_name = trim($_POST['middle_name'] ?? '');
    $last_name   = trim($_POST['last_name'] ?? '');
    $suffix      = trim($_POST['suffix'] ?? '');
    $no_middle   = !empty($_POST['no_middle_name']);

    $parts     = [$first_name];
    if (!$no_middle && $middle_name !== '') $parts[] = $middle_name;
    $full_name = $last_name . ', ' . implode(' ', $parts);
    if ($suffix) $full_name .= ' ' . $suffix;
    $full_name = trim($full_name);

    $email       = trim($_POST['email'] ?? '');
    $employee_id = trim($_POST['employee_id'] ?? '');
    $campus_id   = intval($_POST['campus_id'] ?? 0);
    $rank        = trim($_POST['rank'] ?? '');

    if (!$first_name || !$last_name || !$email || !$employee_id || !$rank) {
        $error = 'Please fill in all required fields.';
    } elseif (!in_array($rank, facultyRanks())) {
        $error = 'Please select a valid faculty rank.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Invalid email address.';
    } else {
        // Check email and employee_id separately for precise error messages
        $check_email = $pdo->prepare("SELECT user_id, status FROM users WHERE email = ?");
        $check_email->execute([$email]);
        $existing_email = $check_email->fetch();

        $check_empid = $pdo->prepare("SELECT user_id, status FROM users WHERE employee_id = ?");
        $check_empid->execute([$employee_id]);
        $existing_empid = $check_empid->fetch();

        // Clean up rejected accounts so re-registration is allowed
        $deleted_ids = [];
        if ($existing_email && $existing_email['status'] === 'rejected') {
            $deleted_ids[] = $existing_email['user_id'];
            $pdo->prepare("DELETE FROM users WHERE user_id = ?")->execute([$existing_email['user_id']]);
            $existing_email = null;
        }
        if ($existing_empid && $existing_empid['status'] === 'rejected') {
            // Avoid double-delete if email and empid belong to the same row
            if (!in_array($existing_empid['user_id'], $deleted_ids)) {
                $pdo->prepare("DELETE FROM users WHERE user_id = ?")->execute([$existing_empid['user_id']]);
            }
            $existing_empid = null;
        }

        if ($existing_email && $existing_empid) {
            $error = 'An account with this email and employee ID already exists. Please log in instead.';
        } elseif ($existing_email) {
            $error = 'This email address is already registered. Please log in or use a different email.';
        } elseif ($existing_empid) {
            $error = 'This employee ID is already registered. Please contact the administrator if this is an error.';
        }

        $existing = $existing_email ?? $existing_empid;
        if (!$existing && !$error) {
            // Generate secure temp password
            $chars     = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghjkmnpqrstuvwxyz23456789@#!';
            $temp_pass = strtoupper($chars[random_int(0,25)])
                       . substr(str_shuffle('abcdefghjkmnpqrstuvwxyz'), 0, 5)
                       . random_int(10,99)
                       . $chars[random_int(52, strlen($chars)-1)];

            $hash = password_hash($temp_pass, PASSWORD_DEFAULT);

            // Insert as active — no admin approval needed
            $stmt = $pdo->prepare("INSERT INTO users (first_name, middle_name, last_name, email, password, role, status, campus_id, rank, employee_id) VALUES (?, ?, ?, ?, ?, 'faculty', 'active', ?, ?, ?)");
            $stmt->execute([$first_name, ($no_middle ? null : ($middle_name ?: null)), $last_name, $email, $hash, $campus_id, $rank, $employee_id]);
            $new_id = (int)$pdo->lastInsertId();

            // Mark as having a temp password so system prompts change on first login
            try {
                $pdo->prepare("INSERT INTO password_resets (user_id, temp_password, status, released_at)
                    VALUES (?,?,'released',NOW())
                    ON DUPLICATE KEY UPDATE temp_password=VALUES(temp_password),status='released',released_at=NOW()")
                    ->execute([$new_id, $temp_pass]);
            } catch (\Exception $e) {}

            logAudit($pdo, $new_id, 'Register', "New faculty account created. Email: $email");
            // Send temp password to their registered email
            $smtp_error = sendTempPasswordEmail($email, $full_name, $temp_pass);
            $email_sent = ($smtp_error === '');
            $success    = true;
            // Clear temp_pass from scope if email sent successfully — no on-screen display
            if ($email_sent) $temp_pass = '';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>SUCFRMS | Register</title>
    <link href="../assets/vendor/bootstrap-icons/bootstrap-icons.min.css" rel="stylesheet">
    <link rel="stylesheet" href="../assets/css/app.css">
</head>
<body class="login-page d-flex align-items-center justify-content-center min-vh-100 py-5" style="background:#ffffff;">

<div class="container" style="position:relative;z-index:1;">
<div class="row justify-content-center">
<div class="col-md-6 col-lg-5">
<div style="background:#ffffff;border-radius:0;padding:2rem;box-shadow:0 2px 16px rgba(0,0,0,0.08);border:1px solid #e2e8f0;">

    <div class="text-center mb-4">
        <img class="register-logo" src="../assets/images/logo.jpg" alt="Institution Logo">
        <h4 class="fw-bold mt-2" style="color:#1a3a6b;text-transform:uppercase;letter-spacing:0.02em;">Faculty Registration</h4>
        <hr style="border:none;border-top:1px solid #e2e8f0;margin:1rem 0 0;">
    </div>

    <?php if ($success): ?>
    <!-- ── Success ── -->
    <div class="text-center py-2">
        <div style="width:70px;height:70px;border-radius:50%;background:#f0f4fb;display:flex;align-items:center;justify-content:center;margin:0 auto 1rem;">
            <i class="bi bi-envelope-check-fill" style="font-size:2rem;color:#1e4d8c;"></i>
        </div>
        <strong style="color:#1a3a6b;font-size:1.05rem;">Account Created!</strong>
        <p style="color:#64748b;font-size:0.85rem;margin:0.75rem 0 1.25rem;">
            <?php if ($email_sent): ?>
                Your temporary password has been sent to <strong style="color:#1e4d8c;"><?= htmlspecialchars($_POST['email'] ?? '') ?></strong>.<br>
                Use it to log in, then change your password immediately.
            <?php else: ?>
                Account created but the email could not be sent. Please contact the administrator.
            <?php endif; ?>
        </p>
        <?php if (!$email_sent && $smtp_error): ?>
        <div style="background:#f8fafc;border:1px solid #94a3b8;border-radius:0;padding:0.75rem 1rem;margin-bottom:1rem;font-size:0.78rem;color:#1e293b;text-align:left;">
            <strong>Email error:</strong> <?= htmlspecialchars($smtp_error) ?>
        </div>
        <?php endif; ?>
        <?php if (!$email_sent && $temp_pass): ?>
        <div style="background:#f0f4fb;border:2px dashed #1e4d8c;border-radius:0;padding:1rem;margin-bottom:1.25rem;">
            <div style="font-size:0.68rem;color:#64748b;letter-spacing:.1em;text-transform:uppercase;margin-bottom:4px;">Temporary Password</div>
            <div style="font-size:1.5rem;font-weight:800;color:#1a3a6b;font-family:monospace;letter-spacing:.1em;">
                <?= htmlspecialchars($temp_pass) ?>
            </div>
            <div style="font-size:0.72rem;color:#334155;margin-top:6px;">
                <i class="bi bi-exclamation-triangle me-1"></i>Copy this — it won't be shown again.
            </div>
        </div>
        <?php endif; ?>

        <?php if ($email_sent): ?>
        <!-- Auto-redirect countdown — only when email was sent (password is safely delivered) -->
        <div style="margin-bottom:1rem;font-size:0.78rem;color:#94a3b8;">
            Redirecting to login in <strong id="countdown" style="color:#1e4d8c;">5</strong>s&hellip;
        </div>
        <script>
        (function(){
            let s = 5;
            const el = document.getElementById('countdown');
            const t = setInterval(function(){
                s--;
                if(el) el.textContent = s;
                if(s <= 0){ clearInterval(t); window.location.href = 'login.php'; }
            }, 1000);
        })();
        </script>
        <?php endif; ?>

        <a href="login.php" class="btn fw-bold w-100"
           style="background:#1e4d8c;color:#fff;border:none;border-radius:0;padding:0.65rem;">
            <i class="bi bi-box-arrow-in-right me-2"></i>Go to Login
        </a>
    </div>

    <?php else: ?>
    <!-- ── Form ── -->


    <?php if ($error): ?>
    <div class="alert py-2 mb-3" style="background:rgba(30,77,140,0.08);border:1px solid rgba(30,77,140,0.2);border-radius:0;color:#1e293b;font-size:0.85rem;">
        <i class="bi bi-exclamation-circle me-1"></i><?= sanitize($error) ?>
    </div>
    <?php endif; ?>

    <form method="POST" novalidate>
        <!-- Row 1: First + Last -->
        <div class="row g-2 mb-2">
            <div class="col-6">
                <label class="form-label small fw-semibold" style="color:#1a3a6b;">First Name <span class="text-danger">*</span></label>
                <input type="text" name="first_name" id="first_name" class="form-control"
                       placeholder="e.g. Juan" value="<?= sanitize($_POST['first_name'] ?? '') ?>" required>
            </div>
            <div class="col-6">
                <label class="form-label small fw-semibold" style="color:#1a3a6b;">Last Name <span class="text-danger">*</span></label>
                <input type="text" name="last_name" id="last_name" class="form-control"
                       placeholder="e.g. Dela Cruz" value="<?= sanitize($_POST['last_name'] ?? '') ?>" required>
            </div>
        </div>
        <!-- Row 2: Middle + Suffix -->
        <div class="row g-2 mb-3">
            <div class="col-7">
                <label class="form-label small fw-semibold" style="color:#1a3a6b;">Middle Name</label>
                <input type="text" name="middle_name" id="middle_name" class="form-control"
                       placeholder="e.g. Santos" value="<?= sanitize($_POST['middle_name'] ?? '') ?>">
                <div class="form-check mt-1">
                    <input class="form-check-input" type="checkbox" id="no_middle_name" name="no_middle_name"
                           onchange="toggleMiddleName(this)" <?= !empty($_POST['no_middle_name']) ? 'checked' : '' ?>>
                    <label class="form-check-label" for="no_middle_name" style="font-size:0.72rem;color:#64748b;">No middle name</label>
                </div>
            </div>
            <div class="col-5">
                <label class="form-label small fw-semibold" style="color:#1a3a6b;">Suffix</label>
                <select name="suffix" class="form-select">
                    <option value="">None</option>
                    <?php foreach (['Jr.','Sr.','II','III','IV','V'] as $s): ?>
                    <option value="<?= $s ?>" <?= ($_POST['suffix'] ?? '') === $s ? 'selected' : '' ?>><?= $s ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>

        <!-- Email & Employee ID -->
        <div class="row g-2 mb-3">
            <div class="col-md-6">
                <label class="form-label small fw-semibold" style="color:#1a3a6b;">Email <span class="text-danger">*</span></label>
                <input type="email" name="email" id="inp_email" class="form-control"
                       placeholder="e.g. juandelacruz@chmsu.edu.ph"
                       value="<?= sanitize($_POST['email'] ?? '') ?>" required>
            </div>
            <div class="col-md-6">
                <label class="form-label small fw-semibold" style="color:#1a3a6b;">Employee ID <span class="text-danger">*</span></label>
                <input type="text" name="employee_id" id="inp_empid" class="form-control"
                       value="<?= sanitize($_POST['employee_id'] ?? '') ?>" required>
            </div>
        </div>

        <!-- Campus & Rank -->
        <div class="mb-3">
            <label class="form-label small fw-semibold" style="color:#1a3a6b;">Campus <span class="text-danger">*</span></label>
            <select name="campus_id" id="inp_campus" class="form-select" required>
                <option value="" disabled selected>Select Campus</option>
                <?php foreach ($campuses as $c):
                    $inactive = !$c['is_active'];
                ?>
                <option value="<?= $c['campus_id'] ?>"
                        <?= intval($_POST['campus_id'] ?? 0) === $c['campus_id'] ? 'selected' : '' ?>
                        <?= $inactive ? 'disabled style="color:#94a3b8;"' : '' ?>>
                    <?= sanitize($c['campus_name']) ?><?= $inactive ? ' (Inactive)' : '' ?>
                </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="mb-4">
            <label class="form-label small fw-semibold" style="color:#1a3a6b;">Faculty Rank <span class="text-danger">*</span></label>
            <select name="rank" id="inp_rank" class="form-select" required>
                <option value="" disabled selected>Select Rank</option>
                <?php foreach (facultyRanks() as $r): ?>
                <option value="<?= $r ?>" <?= ($_POST['rank'] ?? '') === $r ? 'selected' : '' ?>><?= $r ?></option>
                <?php endforeach; ?>
            </select>
        </div>

        <!-- Confirmation checkbox -->
        <div class="mb-2" style="background:#f0f4fb;border:1px solid #bfdbfe;border-radius:0;padding:0.5rem 0.75rem;">
            <div class="form-check d-flex align-items-start gap-2">
                <input class="form-check-input mt-1 flex-shrink-0" type="checkbox" id="confirm_accuracy"
                       style="width:14px;height:14px;border:1.5px solid #1e4d8c;cursor:pointer;">
                <label class="form-check-label" for="confirm_accuracy" style="font-size:0.75rem;color:#1a3a6b;cursor:pointer;line-height:1.4;">
                    I confirm that all information provided is <strong>accurate and correct</strong>. I understand that false information may result in account suspension.
                </label>
            </div>
        </div>

        <!-- Terms & Conditions checkbox -->
        <div class="mb-3" style="background:#f0f4fb;border:1px solid #bfdbfe;border-radius:0;padding:0.5rem 0.75rem;">
            <div class="form-check d-flex align-items-start gap-2">
                <input class="form-check-input mt-1 flex-shrink-0" type="checkbox" id="confirm_terms"
                       style="width:14px;height:14px;border:1.5px solid #1e4d8c;cursor:pointer;">
                <label class="form-check-label" for="confirm_terms" style="font-size:0.75rem;color:#1a3a6b;cursor:pointer;line-height:1.4;">
                    I have read and agree to the
                    <a href="#" onclick="document.getElementById('termsModal').style.display='flex';return false;"
                       style="color:#1e4d8c;font-weight:600;text-decoration:underline;">Terms and Conditions</a>.
                </label>
            </div>
        </div>

        <!-- Submit button -->
        <button type="button" id="reviewBtn" onclick="showSummary()" disabled
                style="width:100%;background:#94a3b8;color:#fff;border:none;padding:0.75rem;border-radius:0;font-size:0.95rem;font-weight:700;letter-spacing:0.05em;cursor:not-allowed;transition:all 0.2s;">
            Submit
        </button>
    </form>

    <!-- Terms & Conditions Modal -->
    <div id="termsModal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,0.45);z-index:100000;align-items:center;justify-content:center;padding:1rem;">
        <div style="background:#fff;width:100%;max-width:420px;box-shadow:0 4px 24px rgba(0,0,0,0.15);overflow:hidden;">

            <!-- Header -->
            <div style="padding:0.85rem 1.1rem;border-bottom:1px solid #e2e8f0;display:flex;align-items:center;justify-content:space-between;">
                <span style="font-size:0.88rem;font-weight:700;color:#1a3a6b;">Terms and Conditions</span>
                <button onclick="document.getElementById('termsModal').style.display='none'"
                        style="background:none;border:none;color:#94a3b8;font-size:1.1rem;cursor:pointer;line-height:1;padding:0;">&times;</button>
            </div>

            <!-- Body -->
            <div style="padding:1rem 1.1rem;max-height:55vh;overflow-y:auto;font-size:0.78rem;color:#374151;line-height:1.65;">
                <p style="font-size:0.7rem;color:#94a3b8;margin-bottom:0.85rem;">SUCFRMS &nbsp;&middot;&nbsp; Effective upon registration</p>

                <p style="margin-bottom:0.65rem;"><strong style="color:#1a3a6b;">1. Purpose.</strong> All personal data collected — name, employee ID, email, rank, and campus — is used exclusively for processing your reclassification application under DBM-CHED Joint Circular No. 3, s. 2022.</p>

                <p style="margin-bottom:0.65rem;"><strong style="color:#1a3a6b;">2. Data Privacy.</strong> This system complies with RA No. 10173 (Data Privacy Act of 2012). Your data is securely stored and accessible only to authorized personnel involved in the evaluation process. You retain the right to access, correct, or request deletion of your data.</p>

                <p style="margin-bottom:0.65rem;"><strong style="color:#1a3a6b;">3. Data Sharing.</strong> Your information will not be disclosed to third parties without your consent, except as required by law or by the mandating agencies (DBM and CHED).</p>

                <p style="margin-bottom:0.65rem;"><strong style="color:#1a3a6b;">4. Accuracy.</strong> You are responsible for providing truthful and accurate information. False submissions may result in application rejection and administrative action.</p>

                <p style="margin-bottom:0.65rem;"><strong style="color:#1a3a6b;">5. Account Security.</strong> Keep your credentials confidential. Change your temporary password upon first login and report any unauthorized access immediately.</p>

                <p style="margin-bottom:0.65rem;"><strong style="color:#1a3a6b;">6. Retention.</strong> Data will be retained for the duration of the reclassification process and as required by institutional and government regulations.</p>

                <p><strong style="color:#1a3a6b;">7. Consent.</strong> By registering, you consent to the collection and processing of your personal data as described above.</p>
            </div>

            <!-- Footer -->
            <div style="padding:0.75rem 1.1rem;border-top:1px solid #e2e8f0;display:flex;justify-content:flex-end;gap:0.5rem;">
                <button onclick="document.getElementById('termsModal').style.display='none'"
                        style="padding:0.45rem 1rem;border:1px solid #e2e8f0;background:#fff;color:#64748b;font-size:0.8rem;cursor:pointer;">
                    Close
                </button>
                <button onclick="document.getElementById('confirm_terms').checked=true;updateSubmitBtn();document.getElementById('termsModal').style.display='none';"
                        style="padding:0.45rem 1.25rem;border:none;background:#1a3a6b;color:#fff;font-size:0.8rem;font-weight:600;cursor:pointer;">
                    I Agree
                </button>
            </div>
        </div>
    </div>

    <!-- ── Summary Modal ── -->
    <div id="summaryModal" style="display:none;position:fixed;inset:0;background:rgba(15,23,42,0.6);z-index:99999;align-items:center;justify-content:center;padding:1rem;">
        <div style="background:#fff;border-radius:0;width:100%;max-width:480px;box-shadow:0 20px 60px rgba(0,0,0,0.25);overflow:hidden;">

            <!-- Header -->
            <div style="background:#1a3a6b;padding:1.25rem 1.5rem;border-radius:0;display:flex;align-items:center;gap:0.875rem;">
                <img src="../assets/images/logo.jpg" alt="Logo"
                     style="width:40px;height:40px;border-radius:50%;object-fit:cover;border:2px solid #475569;clip-path:circle(50%);flex-shrink:0;">
                <div>
                    <div style="color:#fff;font-weight:700;font-size:0.95rem;line-height:1.2;">Registration Summary</div>
                    <div style="color:#bfdbfe;font-size:0.72rem;">Please review before submitting</div>
                </div>
            </div>

            <!-- Body -->
            <div style="padding:1.5rem 1.5rem 0.5rem;">
                <p style="font-size:0.78rem;color:#64748b;margin-bottom:1rem;text-align:center;">
                    Confirm that the details below are correct. You <strong>cannot change them</strong> after submitting.
                </p>
                <div id="summaryBody" style="border:1px solid #e2e8f0;border-radius:0;overflow:hidden;"></div>
                <div style="margin-top:1rem;padding:0.75rem 1rem;background:#f8fafc;border:1px solid #e2e8f0;border-radius:0;font-size:0.75rem;color:#334155;display:flex;gap:0.5rem;align-items:flex-start;">
                    <i class="bi bi-envelope-exclamation" style="font-size:1rem;flex-shrink:0;margin-top:1px;"></i>
                    <span>Your temporary password will be sent to the email listed above. Make sure it is correct before confirming.</span>
                </div>
            </div>

            <!-- Footer -->
            <div style="padding:1.25rem 1.5rem 1.5rem;display:flex;gap:0.75rem;">
                <button type="button" onclick="closeSummary()"
                        style="flex:1;padding:0.65rem;border:1px solid #cbd5e1;border-radius:0;background:#fff;color:#475569;font-weight:600;font-size:0.875rem;cursor:pointer;">
                    <i class="bi bi-arrow-left me-1"></i>Go Back
                </button>
                <button type="button" onclick="submitRegistration()"
                        style="flex:2;padding:0.65rem;border:none;border-radius:0;background:#1e4d8c;color:#fff;font-weight:700;font-size:0.875rem;cursor:pointer;">
                    Confirm
                </button>
            </div>
        </div>
    </div>

    <!-- Hidden real submit form -->
    <form id="realSubmitForm" method="POST" style="display:none;">
        <input type="hidden" name="first_name"     id="h_first">
        <input type="hidden" name="middle_name"    id="h_middle">
        <input type="hidden" name="no_middle_name" id="h_nomid">
        <input type="hidden" name="last_name"      id="h_last">
        <input type="hidden" name="suffix"         id="h_suffix">
        <input type="hidden" name="email"          id="h_email">
        <input type="hidden" name="employee_id"    id="h_empid">
        <input type="hidden" name="campus_id"      id="h_campus">
        <input type="hidden" name="rank"           id="h_rank">
        <input type="hidden" name="confirm_accuracy" value="1">
    </form>
    <?php endif; ?>

    <hr style="border-color:#dbeafe;margin-top:1.5rem;">
    <p class="text-center mb-0" style="color:#64748b;font-size:0.82rem;">
        Already have an account? <a href="login.php" style="color:#1e4d8c;font-weight:600;">Sign in</a>
    </p>
</div>

<!-- Inline validation toast -->
<div id="formToast" style="display:none;position:fixed;top:1.25rem;left:50%;transform:translateX(-50%);z-index:999999;
     background:#fff;border:1px solid #94a3b8;border-left:4px solid #1e293b;border-radius:0;
     padding:0.85rem 1.25rem;box-shadow:0 8px 24px rgba(0,0,0,0.12);
     display:none;align-items:center;gap:0.75rem;min-width:280px;max-width:90vw;">
    <i class="bi bi-exclamation-circle-fill" style="color:#1e293b;font-size:1.1rem;flex-shrink:0;"></i>
    <span id="formToastMsg" style="color:#1e293b;font-size:0.85rem;font-weight:500;flex:1;"></span>
    <button onclick="document.getElementById('formToast').style.display='none'"
            style="background:none;border:none;color:#94a3b8;cursor:pointer;padding:0;font-size:1rem;line-height:1;">&times;</button>
</div>
</div>
</div>
</div>

<script src="../assets/js/app.js"></script>
<style>
.form-label{color:#1a3a6b !important;}
.form-control,.form-select{color:#1e293b !important;background-color:#f0f4fb !important;border-color:#dbeafe !important;border-radius:0 !important;}
.form-control::placeholder{color:#94a3b8 !important;}
.form-select{
    appearance:none !important;-webkit-appearance:none !important;
    background-image:url("data:image/svg+xml,%3csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 16 16'%3e%3cpath fill='none' stroke='%231a3a6b' stroke-linecap='round' stroke-linejoin='round' stroke-width='2' d='M2 5l6 6 6-6'/%3e%3c/svg%3e") !important;
    background-repeat:no-repeat !important;background-position:right 0.75rem center !important;
    background-size:14px 10px !important;padding-right:2.5rem !important;cursor:pointer !important;
}
.form-select option{background:#fff;color:#1e293b;}

</style>


<script>
// Functions called by inline onclick handlers must be global
function showFormError(msg){
    const t = document.getElementById('formToast');
    document.getElementById('formToastMsg').textContent = msg;
    t.style.display = 'flex';
    clearTimeout(t._timer);
    t._timer = setTimeout(() => t.style.display = 'none', 4000);
}

function toggleMiddleName(cb){
    const f=document.getElementById('middle_name');
    if(cb.checked){f.value='';f.disabled=true;f.style.opacity='0.4';}
    else{f.disabled=false;f.style.opacity='1';}
}

function updateSubmitBtn() {
    const confirmBox   = document.getElementById('confirm_accuracy');
    const confirmTerms = document.getElementById('confirm_terms');
    const reviewBtn    = document.getElementById('reviewBtn');
    if (!confirmBox || !confirmTerms || !reviewBtn) return;
    const ok = confirmBox.checked && confirmTerms.checked;
    reviewBtn.disabled = !ok;
    reviewBtn.style.background = ok ? '#1e4d8c' : '#94a3b8';
    reviewBtn.style.cursor = ok ? 'pointer' : 'not-allowed';
}

function getCampusLabel(){
    const sel = document.getElementById('inp_campus');
    return sel.options[sel.selectedIndex]?.text || '—';
}
function getRankLabel(){
    const sel = document.getElementById('inp_rank');
    return sel.options[sel.selectedIndex]?.text || '—';
}
function getSuffix(){
    const sel = document.querySelector('[name="suffix"]');
    return sel.options[sel.selectedIndex]?.text || '';
}

function summaryRow(label, value){
    return `<div style="display:flex;padding:0.65rem 1rem;border-bottom:1px solid #f0f4fb;font-size:0.83rem;">
        <div style="width:38%;color:#64748b;font-weight:600;">${label}</div>
        <div style="flex:1;color:#1e293b;font-weight:500;">${value || '<span style="color:#94a3b8;">—</span>'}</div>
    </div>`;
}

function showSummary(){
    const first  = document.getElementById('first_name').value.trim();
    const middle = document.getElementById('middle_name').value.trim();
    const last   = document.getElementById('last_name').value.trim();
    const noMid  = document.getElementById('no_middle_name').checked;
    const suffix = getSuffix() === 'None' ? '' : getSuffix();
    const email  = document.getElementById('inp_email').value.trim();
    const empid  = document.querySelector('[name="employee_id"]').value.trim();

    if(!first || !last || !email || !empid ||
       document.getElementById('inp_campus').value === '' ||
       document.getElementById('inp_rank').value === ''){
        showFormError('Please fill in all required fields before reviewing.');
        return;
    }

    let fullName = last + ', ' + first;
    if(!noMid && middle) fullName += ' ' + middle;
    if(suffix) fullName += ' ' + suffix;

    const midDisplay = noMid ? '<em style="color:#94a3b8;">None</em>' : (middle || '<em style="color:#94a3b8;">—</em>');

    document.getElementById('summaryBody').innerHTML =
        summaryRow('Full Name', fullName) +
        summaryRow('First Name', first) +
        summaryRow('Middle Name', midDisplay) +
        summaryRow('Last Name', last) +
        (suffix ? summaryRow('Suffix', suffix) : '') +
        summaryRow('Email', `<strong style="color:#1e4d8c;">${email}</strong>`) +
        summaryRow('Employee ID', empid) +
        summaryRow('Campus', getCampusLabel()) +
        summaryRow('Faculty Rank', getRankLabel());

    const rows = document.querySelectorAll('#summaryBody > div');
    if(rows.length) rows[rows.length-1].style.borderBottom = 'none';

    document.getElementById('summaryModal').style.display = 'flex';
    document.body.style.overflow = 'hidden';
}

function closeSummary(){
    document.getElementById('summaryModal').style.display = 'none';
    document.body.style.overflow = '';
}

function submitRegistration(){
    const noMid = document.getElementById('no_middle_name').checked;
    document.getElementById('h_first').value   = document.getElementById('first_name').value.trim();
    document.getElementById('h_middle').value  = document.getElementById('middle_name').value.trim();
    document.getElementById('h_nomid').value   = noMid ? '1' : '';
    document.getElementById('h_last').value    = document.querySelector('[name="last_name"]').value.trim();
    document.getElementById('h_suffix').value  = document.querySelector('[name="suffix"]').value;
    document.getElementById('h_email').value   = document.getElementById('inp_email').value.trim();
    document.getElementById('h_empid').value   = document.getElementById('inp_empid').value.trim();
    document.getElementById('h_campus').value  = document.getElementById('inp_campus').value;
    document.getElementById('h_rank').value    = document.getElementById('inp_rank').value;
    document.getElementById('realSubmitForm').submit();
}

// Wire up checkbox listeners + initial state check after DOM is ready
document.addEventListener('DOMContentLoaded', function() {
    const confirmBox   = document.getElementById('confirm_accuracy');
    const confirmTerms = document.getElementById('confirm_terms');
    if (confirmBox)   confirmBox.addEventListener('change', updateSubmitBtn);
    if (confirmTerms) confirmTerms.addEventListener('change', updateSubmitBtn);
    updateSubmitBtn(); // apply correct state on load
});
</script>
</body>
</html>
