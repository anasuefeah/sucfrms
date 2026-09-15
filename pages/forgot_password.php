<?php
if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/functions.php';

if (isLoggedIn()) { header('Location: ../index.php'); exit; }

// Clear session if user clicked "Start Over"
if (isset($_GET['clear'])) {
    unset($_SESSION['reset_email'], $_SESSION['reset_user_id'], $_SESSION['reset_name'], $_SESSION['reset_verified']);
    header('Location: forgot_password.php'); exit;
}

// -- Runtime migration: add OTP columns to password_resets --
try { $pdo->query("SELECT otp_code FROM password_resets LIMIT 1"); }
catch (\Exception $e) {
    $pdo->exec("ALTER TABLE password_resets
        ADD COLUMN otp_code       VARCHAR(6)   DEFAULT NULL AFTER temp_password,
        ADD COLUMN otp_expires_at DATETIME     DEFAULT NULL AFTER otp_code
    ");
}

$step  = 'request';   // request | verify | reset | done
$error = '';

// --------------------------------------------------------------
// Helper: generate a 6-digit numeric OTP
// --------------------------------------------------------------
function generateOTP(): string {
    return str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);
}

// --------------------------------------------------------------
// SMTP Configuration
// --------------------------------------------------------------
define('SMTP_HOST',     'smtp.gmail.com');
define('SMTP_PORT',     587);
define('SMTP_USER',     'sucfrms.chmsu@gmail.com');
define('SMTP_PASS',     'wwbbxdrtpuammsma');
define('SMTP_FROM',     'sucfrms.chmsu@gmail.com');
define('SMTP_FROM_NAME','SUCFRMS &ndash; SUC');

// --------------------------------------------------------------
// Helper: send OTP email via PHPMailer
// --------------------------------------------------------------
function sendOTPEmail(string $to_email, string $to_name, string $otp): bool {
    $subject = 'SUCFRMS &ndash; Your Password Reset Code';

    // Load PHPMailer directly
    $base = __DIR__ . '/../vendor/phpmailer/phpmailer/src/';
    if (file_exists($base . 'PHPMailer.php')) {
        require_once $base . 'Exception.php';
        require_once $base . 'SMTP.php';
        require_once $base . 'PHPMailer.php';

        try {
            $mail = new \PHPMailer\PHPMailer\PHPMailer(true);
            $mail->isSMTP();
            $mail->Host       = SMTP_HOST;
            $mail->SMTPAuth   = true;
            $mail->Username   = SMTP_USER;
            $mail->Password   = SMTP_PASS;
            $mail->SMTPSecure = \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
            $mail->Port       = SMTP_PORT;
            $mail->setFrom(SMTP_FROM, SMTP_FROM_NAME);
            $mail->addAddress($to_email, $to_name);
            $mail->isHTML(true);
            $mail->Subject = $subject;
            $mail->Body    = emailTemplate($to_name, $otp);
            $mail->AltBody = "Hello $to_name,\n\nYour password reset code is: $otp\n\nThis code expires in 15 minutes.\n\n&ndash; SUCFRMS";
            $mail->send();
            return true;
        } catch (\Exception $e) {
            error_log('PHPMailer error: ' . $e->getMessage());
            return false;
        }
    }

    // PHPMailer not available &mdash; cannot send
    error_log('PHPMailer not found. Please install it in vendor/phpmailer/phpmailer/src/');
    return false;
}

// --------------------------------------------------------------
// HTML email template
// --------------------------------------------------------------
function emailTemplate(string $name, string $otp): string {
    return "
<!DOCTYPE html>
<html>
<head><meta charset='UTF-8'></head>
<body style='margin:0;padding:0;background:#f1f5f9;font-family:Arial,sans-serif;'>
  <table width='100%' cellpadding='0' cellspacing='0' style='background:#f1f5f9;padding:40px 0;'>
    <tr><td align='center'>
      <table width='480' cellpadding='0' cellspacing='0' style='background:#fff;border-radius:12px;overflow:hidden;box-shadow:0 4px 20px rgba(0,0,0,0.08);'>

        <!-- Header -->
        <tr><td style='background:#1a3a6b;padding:28px 32px;text-align:center;'>
          <h2 style='margin:0;color:#fff;font-size:20px;letter-spacing:1px;'>SUCFRMS</h2>
          <p style='margin:4px 0 0;color:#81c784;font-size:13px;'>Password Reset Request</p>
        </td></tr>

        <!-- Body -->
        <tr><td style='padding:32px;'>
          <p style='margin:0 0 12px;color:#334155;font-size:15px;'>Hello, <strong>" . htmlspecialchars($name) . "</strong>!</p>
          <p style='margin:0 0 24px;color:#64748b;font-size:14px;line-height:1.6;'>
            We received a request to reset your password. Use the verification code below to continue.
            This code expires in <strong>15 minutes</strong>.
          </p>

          <!-- OTP Box -->
          <div style='background:#eff6ff;border:2px dashed #1a3a6b;border-radius:10px;padding:24px;text-align:center;margin-bottom:24px;'>
            <p style='margin:0 0 6px;font-size:12px;color:#64748b;text-transform:uppercase;letter-spacing:1px;'>Your Verification Code</p>
            <span style='font-size:42px;font-weight:700;letter-spacing:10px;color:#1a3a6b;font-family:monospace;'>" . htmlspecialchars($otp) . "</span>
          </div>

          <div style='background:#f1f5f9;border-left:4px solid #475569;border-radius:4px;padding:12px 16px;margin-bottom:24px;font-size:13px;color:#334155;'>
            <strong>? Security tip:</strong> Never share this code with anyone. SUCFRMS staff will never ask for it.
          </div>

          <p style='margin:0;color:#94a3b8;font-size:12px;line-height:1.6;'>
            If you did not request a password reset, please ignore this email or contact your administrator immediately.
          </p>
        </td></tr>

        <!-- Footer -->
        <tr><td style='background:#f8fafc;padding:16px 32px;text-align:center;border-top:1px solid #e2e8f0;'>
          <p style='margin:0;color:#94a3b8;font-size:12px;'>© " . date('Y') . " SUCFRMS. All rights reserved.</p>
        </td></tr>

      </table>
    </td></tr>
  </table>
</body>
</html>";
}

// --------------------------------------------------------------
// STEP 1 &mdash; Submit email ? send OTP
// --------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {

    if ($_POST['action'] === 'send_otp') {
        $email = trim($_POST['email'] ?? '');
        if (!$email) {
            $error = 'Please enter your email address.';
            $step  = 'request';
        } else {
            $stmt = $pdo->prepare("SELECT * FROM users WHERE email = ? AND status = 'active' LIMIT 1");
            $stmt->execute([$email]);
            $user = $stmt->fetch();

            if ($user) {
                $otp     = generateOTP();
                $expires = date('Y-m-d H:i:s', time() + 900); // 15 min, local time

                // Persist to DB
                $pdo->prepare("
                    INSERT INTO password_resets (user_id, otp_code, otp_expires_at, status, requested_at)
                    VALUES (?, ?, ?, 'pending', NOW())
                    ON DUPLICATE KEY UPDATE
                        otp_code       = VALUES(otp_code),
                        otp_expires_at = VALUES(otp_expires_at),
                        temp_password  = NULL,
                        status         = 'pending',
                        requested_at   = NOW()
                ")->execute([$user['user_id'], $otp, $expires]);

                $sent = sendOTPEmail($user['email'], $user['full_name'], $otp);

                if ($sent) {
                    // Store everything in session — OTP included — so double-submits don't
                    // overwrite the DB with a different code after the email has gone out
                    $_SESSION['reset_email']   = $user['email'];
                    $_SESSION['reset_user_id'] = $user['user_id'];
                    $_SESSION['reset_name']    = $user['full_name'];
                    $_SESSION['reset_otp']     = $otp;          // source of truth for verification
                    $_SESSION['reset_expires'] = time() + 900;  // Unix timestamp, immune to TZ issues
                    logAudit($pdo, $user['user_id'], 'Password Reset OTP Sent', 'OTP sent to ' . $user['email']);
                    // PRG: redirect to prevent browser re-submit on refresh
                    header('Location: forgot_password.php');
                    exit;
                } else {
                    $error = 'Email could not be sent. Please check SMTP settings (SMTP_USER, SMTP_PASS) and that PHPMailer is installed.';
                    $step  = 'request';
                }
            } else {
                $error = 'No active account found with that email address.';
                $step  = 'request';
            }
        }
    }

    // ----------------------------------------------------------
    // STEP 2 — Verify OTP
    // ----------------------------------------------------------
    elseif ($_POST['action'] === 'verify_otp') {
        $uid  = (int)($_SESSION['reset_user_id'] ?? -1);
        $code = str_pad(preg_replace('/\D/', '', trim($_POST['otp'] ?? '')), 6, '0', STR_PAD_LEFT);

        if ($uid <= 0 || !$code) {
            $error = 'Session expired. Please start over.';
            $step  = 'request';
            unset($_SESSION['reset_email'], $_SESSION['reset_user_id'], $_SESSION['reset_name'],
                  $_SESSION['reset_otp'], $_SESSION['reset_expires'], $_SESSION['reset_verified']);
        } else {
            $session_otp     = $_SESSION['reset_otp']     ?? '';
            $session_expires = (int)($_SESSION['reset_expires'] ?? 0);

            if (time() > $session_expires) {
                $error = 'Your code has expired. Please request a new one.';
                $step  = 'request';
                unset($_SESSION['reset_email'], $_SESSION['reset_user_id'], $_SESSION['reset_name'],
                      $_SESSION['reset_otp'], $_SESSION['reset_expires'], $_SESSION['reset_verified']);
            } elseif ($code !== $session_otp) {
                $error = 'Invalid code. Please check your email and try again.';
                $step  = 'verify';
            } else {
                // Valid — mark verified in both session and DB
                $pdo->prepare("UPDATE password_resets SET status='verified' WHERE user_id=?")
                    ->execute([$uid]);
                $_SESSION['reset_verified'] = true;
                unset($_SESSION['reset_otp']); // no longer needed
                // PRG redirect
                header('Location: forgot_password.php');
                exit;
            }
        }
    }

    // ----------------------------------------------------------
    // STEP 3 &mdash; Set new password
    // ----------------------------------------------------------
    elseif ($_POST['action'] === 'reset_password') {
        $uid      = (int)($_SESSION['reset_user_id'] ?? 0);
        $verified = $_SESSION['reset_verified'] ?? false;
        $pw1      = $_POST['password']  ?? '';
        $pw2      = $_POST['password2'] ?? '';

        if (!$uid || !$verified) {
            $error = 'Session expired. Please start over.';
            $step  = 'request';
            unset($_SESSION['reset_email'], $_SESSION['reset_user_id'], $_SESSION['reset_name'], $_SESSION['reset_verified']);
        } elseif (strlen($pw1) < 8) {
            $error = 'Password must be at least 8 characters.';
            $step  = 'reset';
        } elseif ($pw1 !== $pw2) {
            $error = 'Passwords do not match.';
            $step  = 'reset';
        } else {
            $hash = password_hash($pw1, PASSWORD_DEFAULT);
            $pdo->prepare("UPDATE users SET password=? WHERE user_id=?")->execute([$hash, $uid]);
            $pdo->prepare("DELETE FROM password_resets WHERE user_id=?")->execute([$uid]);
            logAudit($pdo, $uid, 'Password Reset Complete', 'User reset password via email OTP.');

            unset($_SESSION['reset_email'], $_SESSION['reset_user_id'], $_SESSION['reset_name'], $_SESSION['reset_verified']);
            $step = 'done';
        }
    }

    // -- Resend OTP ------------------------------------------
    elseif ($_POST['action'] === 'resend_otp') {
        $uid = (int)($_SESSION['reset_user_id'] ?? 0);
        if ($uid) {
            $u = $pdo->prepare("SELECT full_name, email FROM users WHERE user_id=?");
            $u->execute([$uid]);
            $user = $u->fetch();
            if ($user) {
                $otp     = generateOTP();
                $expires = date('Y-m-d H:i:s', time() + 900);
                $pdo->prepare("UPDATE password_resets SET otp_code=?, otp_expires_at=?, status='pending' WHERE user_id=?")
                    ->execute([$otp, $expires, $uid]);
                sendOTPEmail($user['email'], $user['full_name'], $otp);
                // Keep session in sync with new OTP
                $_SESSION['reset_otp']     = $otp;
                $_SESSION['reset_expires'] = time() + 900;
                logAudit($pdo, $uid, 'Password Reset OTP Resent', 'OTP resent to ' . $user['email']);
            }
        }
        // PRG redirect
        header('Location: forgot_password.php');
        exit;
    }

} else {
    // Restore step from session
    if (!empty($_SESSION['reset_verified'])) {
        $step = 'reset';
    } elseif (array_key_exists('reset_user_id', $_SESSION)) {
        $step = 'verify';
    }
}

$masked_email = '';
if (!empty($_SESSION['reset_email'])) {
    [$local, $domain] = explode('@', $_SESSION['reset_email']) + ['', ''];
    $masked_email = substr($local, 0, 2) . str_repeat('*', max(0, strlen($local) - 2)) . '@' . $domain;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <meta name="mobile-web-app-capable" content="yes">
    <meta name="theme-color" content="#1a3a6b">
    <title>SUCFRMS | Forgot Password</title>
    <link href="../assets/vendor/bootstrap-icons/bootstrap-icons.min.css" rel="stylesheet">
    <link rel="stylesheet" href="../assets/css/app.css">
    <style>
        .otp-input-group { display:flex; gap:8px; justify-content:center; }
        .otp-digit {
            width:44px; height:54px; text-align:center; font-size:1.4rem; font-weight:700;
            border:2px solid #cbd5e1; border-radius:10px; background:#f8fafc;
            color:#1a3a6b; transition:border-color .2s, box-shadow .2s;
            flex-shrink:0;
        }
        .otp-digit:focus { border-color:#1a3a6b; box-shadow:0 0 0 3px rgba(30,77,140,.15); outline:none; }
        .strength-bar { height:4px; border-radius:2px; transition:width .3s, background .3s; }
    </style>
</head>
<body style="background:#ffffff; min-height:100vh; position:relative; overflow:hidden;"
      class="d-flex align-items-center justify-content-center min-vh-100">

<div style="position:fixed;width:600px;height:600px;border-radius:50%;background:radial-gradient(circle,rgba(30,77,140,0.08) 0%,transparent 70%);top:-150px;right:-150px;pointer-events:none;z-index:0;"></div>
<div style="position:fixed;width:500px;height:500px;border-radius:50%;background:radial-gradient(circle,rgba(34,197,94,0.07) 0%,transparent 70%);bottom:-100px;left:-100px;pointer-events:none;z-index:0;"></div>

<div class="container" style="position:relative;z-index:1;">
    <div class="row justify-content-center">
        <div class="col-md-5 col-lg-4" style="min-width:340px;max-width:400px;">
            <div style="background:rgba(255,255,255,0.97);border-radius:16px;padding:2rem 1.5rem;box-shadow:0 8px 40px rgba(30,77,140,0.13);border:1px solid #e2e8f0;text-align:center;position:relative;overflow:visible;">
                <div style="position:absolute;top:0;left:0;right:0;height:4px;background:linear-gradient(90deg,#1a3a6b,#1a3a6b,#1a3a6b);border-radius:16px 16px 0 0;overflow:hidden;"></div>

                <?php if ($error): ?>
                <div class="alert alert-danger py-2 small text-start mb-3">
                    <i class="bi bi-exclamation-circle me-1"></i><?= sanitize($error) ?>
                </div>
                <?php endif; ?>

                <!-- ------------------------------------------ -->
                <!-- STEP 1 &mdash; Request Form                      -->
                <!-- ------------------------------------------ -->
                <?php if ($step === 'request'): ?>
                <div style="width:64px;height:64px;border-radius:50%;background:#eff6ff;display:flex;align-items:center;justify-content:center;margin:0 auto 1rem;">
                    <i class="bi bi-envelope-at-fill" style="font-size:1.75rem;color:#1a3a6b;"></i>
                </div>
                <h5 class="fw-bold mb-1" style="color:#1e293b;">Forgot Password</h5>
                <p style="color:#64748b;font-size:0.82rem;margin-bottom:1.25rem;">
                    Enter your registered email address and we'll send you a 6-digit verification code.
                </p>

                <form method="POST" novalidate>
                    <input type="hidden" name="action" value="send_otp">
                    <div class="mb-3 text-start">
                        <label class="form-label small fw-semibold" style="color:#1a3a6b;">Email Address</label>
                        <div style="display:flex;border:1px solid #cbd5e1;border-radius:8px;overflow:hidden;background:#f8fafc;">
                            <span style="display:flex;align-items:center;padding:0 0.75rem;background:#f1f5f9;border-right:1px solid #cbd5e1;color:#1a3a6b;">
                                <i class="bi bi-envelope"></i>
                            </span>
                            <input type="email" name="email"
                                   style="flex:1;min-width:0;border:none;outline:none;background:#f8fafc;color:#1e293b;padding:0.55rem 0.75rem;font-size:0.95rem;"
                                   placeholder="your.email@university.edu.ph" required
                                   value="<?= htmlspecialchars($_SESSION['reset_email'] ?? '') ?>">
                        </div>
                    </div>
                    <button type="submit"
                            style="width:100%;background:#1a3a6b;color:#fff;border:none;padding:0.65rem;border-radius:8px;font-size:0.9rem;font-weight:700;cursor:pointer;">
                        <i class="bi bi-send me-1"></i>Send Verification Code
                    </button>
                </form>

                <p class="mt-3 mb-0" style="font-size:0.82rem;">
                    <a href="login.php" style="color:#64748b;"><i class="bi bi-arrow-left me-1"></i>Back to Login</a>
                </p>


                <!-- ------------------------------------------ -->
                <!-- STEP 2 &mdash; OTP Verify                        -->
                <!-- ------------------------------------------ -->
                <?php elseif ($step === 'verify'): ?>
                <div style="width:64px;height:64px;border-radius:50%;background:#eff6ff;display:flex;align-items:center;justify-content:center;margin:0 auto 1rem;">
                    <i class="bi bi-shield-lock-fill" style="font-size:1.75rem;color:#1a3a6b;"></i>
                </div>
                <h5 class="fw-bold mb-1" style="color:#1e293b;">Check Your Email</h5>
                <p style="color:#64748b;font-size:0.82rem;margin-bottom:1.5rem;">
                    We sent a 6-digit code to<br>
                    <strong style="color:#1a3a6b;"><?= htmlspecialchars($masked_email) ?></strong><br>
                    <span style="font-size:0.75rem;">It expires in 15 minutes.</span>
                </p>

                <form method="POST" id="otpForm" novalidate>
                    <input type="hidden" name="action" value="verify_otp">
                    <input type="hidden" name="otp" id="otpHidden">

                    <div class="otp-input-group mb-3" id="otpBoxes">
                        <?php for ($i = 0; $i < 6; $i++): ?>
                        <input type="text" maxlength="1" class="otp-digit"
                               inputmode="numeric" pattern="[0-9]"
                               autocomplete="<?= $i === 0 ? 'one-time-code' : 'off' ?>"
                               id="d<?= $i ?>">
                        <?php endfor; ?>
                    </div>

                    <button type="submit" id="verifyBtn"
                            style="width:100%;background:#1a3a6b;color:#fff;border:none;padding:0.65rem;border-radius:8px;font-size:0.9rem;font-weight:700;cursor:pointer;">
                        <i class="bi bi-check-circle me-1"></i>Verify Code
                    </button>
                </form>

                <div class="mt-3">
                    <form method="POST" style="display:inline;">
                        <input type="hidden" name="action" value="resend_otp">
                        <button type="submit" class="btn btn-link btn-sm p-0" style="color:#64748b;font-size:0.8rem;">
                            <i class="bi bi-arrow-clockwise me-1"></i>Resend Code
                        </button>
                    </form>
                    &nbsp;&middot;&nbsp;
                    <a href="forgot_password.php?clear=1" style="color:#64748b;font-size:0.8rem;">
                        <i class="bi bi-x-circle me-1"></i>Start Over
                    </a>
                </div>

                <!-- Countdown timer -->
                <p id="countdown" style="font-size:0.75rem;color:#94a3b8;margin-top:0.5rem;"></p>


                <!-- ------------------------------------------ -->
                <!-- STEP 3 &mdash; New Password Form                 -->
                <!-- ------------------------------------------ -->
                <?php elseif ($step === 'reset'): ?>
                <div style="width:64px;height:64px;border-radius:50%;background:#eff6ff;display:flex;align-items:center;justify-content:center;margin:0 auto 1rem;">
                    <i class="bi bi-key-fill" style="font-size:1.75rem;color:#1a3a6b;"></i>
                </div>
                <h5 class="fw-bold mb-1" style="color:#1e293b;">Set New Password</h5>
                <p style="color:#64748b;font-size:0.82rem;margin-bottom:1.25rem;">
                    Create a strong password for your account.
                </p>

                <form method="POST" novalidate>
                    <input type="hidden" name="action" value="reset_password">

                    <div class="mb-3 text-start">
                        <label class="form-label small fw-semibold" style="color:#1a3a6b;">New Password</label>
                        <div style="display:flex;border:1px solid #cbd5e1;border-radius:8px;overflow:hidden;background:#f8fafc;">
                            <span style="display:flex;align-items:center;padding:0 0.75rem;background:#f1f5f9;border-right:1px solid #cbd5e1;color:#1a3a6b;">
                                <i class="bi bi-lock"></i>
                            </span>
                            <input type="password" name="password" id="pw1" required minlength="8"
                                   style="flex:1;min-width:0;border:none;outline:none;background:#f8fafc;color:#1e293b;padding:0.55rem 0.75rem;font-size:0.95rem;"
                                   placeholder="Min. 8 characters" oninput="checkStrength(this.value)">
                            <button type="button" onclick="toggleVis('pw1','eyeIcon1')"
                                    style="border:none;background:transparent;padding:0 0.75rem;color:#94a3b8;cursor:pointer;">
                                <i id="eyeIcon1" class="bi bi-eye"></i>
                            </button>
                        </div>
                        <div style="background:#e2e8f0;border-radius:2px;height:4px;margin-top:6px;">
                            <div id="strengthBar" class="strength-bar" style="width:0;background:#334155;"></div>
                        </div>
                        <small id="strengthLabel" style="font-size:0.72rem;color:#94a3b8;"></small>
                    </div>

                    <div class="mb-3 text-start">
                        <label class="form-label small fw-semibold" style="color:#1a3a6b;">Confirm Password</label>
                        <div style="display:flex;border:1px solid #cbd5e1;border-radius:8px;overflow:hidden;background:#f8fafc;">
                            <span style="display:flex;align-items:center;padding:0 0.75rem;background:#f1f5f9;border-right:1px solid #cbd5e1;color:#1a3a6b;">
                                <i class="bi bi-lock-fill"></i>
                            </span>
                            <input type="password" name="password2" id="pw2" required
                                   style="flex:1;min-width:0;border:none;outline:none;background:#f8fafc;color:#1e293b;padding:0.55rem 0.75rem;font-size:0.95rem;"
                                   placeholder="Re-enter password">
                            <button type="button" onclick="toggleVis('pw2','eyeIcon2')"
                                    style="border:none;background:transparent;padding:0 0.75rem;color:#94a3b8;cursor:pointer;">
                                <i id="eyeIcon2" class="bi bi-eye"></i>
                            </button>
                        </div>
                    </div>

                    <button type="submit"
                            style="width:100%;background:#1a3a6b;color:#fff;border:none;padding:0.65rem;border-radius:8px;font-size:0.9rem;font-weight:700;cursor:pointer;">
                        <i class="bi bi-check-lg me-1"></i>Reset Password
                    </button>
                </form>


                <!-- ------------------------------------------ -->
                <!-- STEP 4 &mdash; Done                              -->
                <!-- ------------------------------------------ -->
                <?php elseif ($step === 'done'): ?>
                <div style="width:72px;height:72px;border-radius:50%;background:#eff6ff;display:flex;align-items:center;justify-content:center;margin:0 auto 1rem;">
                    <i class="bi bi-check-circle-fill" style="font-size:2rem;color:#1a3a6b;"></i>
                </div>
                <h5 class="fw-bold mb-1" style="color:#1e293b;">Password Updated!</h5>
                <p style="color:#64748b;font-size:0.85rem;margin-bottom:1.5rem;">
                    Your password has been successfully reset.<br>You can now log in with your new password.
                </p>
                <a href="login.php" class="btn w-100 fw-bold"
                   style="background:#1a3a6b;color:#fff;border:none;border-radius:8px;padding:0.6rem;font-size:0.9rem;">
                    <i class="bi bi-box-arrow-in-right me-2"></i>Go to Login
                </a>
                <?php endif; ?>

            </div>
        </div>
    </div>
</div>

<script src="../assets/js/app.js"></script>
<script>
// -- OTP digit boxes ------------------------------------------
(function () {
    const digits  = document.querySelectorAll('.otp-digit');
    const hidden  = document.getElementById('otpHidden');
    const form    = document.getElementById('otpForm');
    if (!digits.length) return;

    digits.forEach((el, idx) => {
        el.addEventListener('input', e => {
            el.value = el.value.replace(/\D/g, '').slice(-1);
            if (el.value && idx < digits.length - 1) digits[idx + 1].focus();
            syncHidden();
        });
        el.addEventListener('keydown', e => {
            if (e.key === 'Backspace' && !el.value && idx > 0) {
                digits[idx - 1].focus();
                digits[idx - 1].value = '';
                syncHidden();
            }
        });
        el.addEventListener('paste', e => {
            e.preventDefault();
            const text = (e.clipboardData || window.clipboardData).getData('text').replace(/\D/g,'');
            [...text].slice(0, 6).forEach((ch, i) => { if (digits[i]) digits[i].value = ch; });
            const next = Math.min(text.length, 5);
            digits[next].focus();
            syncHidden();
        });
    });

    function syncHidden() {
        hidden.value = [...digits].map(d => d.value).join('');
    }

    form && form.addEventListener('submit', e => {
        syncHidden();
        if (hidden.value.length !== 6) { e.preventDefault(); alert('Please enter all 6 digits.'); }
    });

    digits[0] && digits[0].focus();
})();

// -- 15-min countdown -----------------------------------------
(function () {
    const el = document.getElementById('countdown');
    if (!el) return;
    let secs = 15 * 60;
    const tick = () => {
        const m = Math.floor(secs / 60), s = secs % 60;
        el.textContent = `Code expires in ${m}:${s.toString().padStart(2,'0')}`;
        if (secs-- <= 0) { el.textContent = 'Code expired. Please request a new one.'; el.style.color='#334155'; }
        else setTimeout(tick, 1000);
    };
    tick();
})();

// -- Password strength ----------------------------------------
function checkStrength(v) {
    const bar = document.getElementById('strengthBar');
    const lbl = document.getElementById('strengthLabel');
    if (!bar) return;
    let score = 0;
    if (v.length >= 8)  score++;
    if (/[A-Z]/.test(v)) score++;
    if (/[0-9]/.test(v)) score++;
    if (/[^A-Za-z0-9]/.test(v)) score++;
    const map = [
        {w:'0%',  c:'#334155', t:''},
        {w:'25%', c:'#334155', t:'Weak'},
        {w:'50%', c:'#475569', t:'Fair'},
        {w:'75%', c:'#1a3a6b', t:'Good'},
        {w:'100%',c:'#1a3a6b', t:'Strong'},
    ];
    bar.style.width      = map[score].w;
    bar.style.background = map[score].c;
    lbl.textContent      = map[score].t;
    lbl.style.color      = map[score].c;
}

// -- Show/hide password ---------------------------------------
function toggleVis(inputId, iconId) {
    const inp  = document.getElementById(inputId);
    const icon = document.getElementById(iconId);
    if (!inp) return;
    const isPass = inp.type === 'password';
    inp.type = isPass ? 'text' : 'password';
    icon.className = isPass ? 'bi bi-eye-slash' : 'bi bi-eye';
}
</script>
</body>
</html>
