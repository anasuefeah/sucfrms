<?php
// Request handling and data loading for frontend/pages/forgot_password.php
if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__ . '/../database/config/db.php';
require_once __DIR__ . '/../core/functions.php';
require_once __DIR__ . '/../services/mail/mailer.php';

if (isLoggedIn()) { header('Location: ../../index.php'); exit; }

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
// Helper: send OTP email
// --------------------------------------------------------------
function sendOTPEmail(string $to_email, string $to_name, string $otp): bool {
    $err = sendMail(
        $to_email,
        $to_name,
        'SUCFRMS - Your Password Reset Code',
        emailTemplate($to_name, $otp),
        "Hello $to_name,\n\nYour password reset code is: $otp\n\nThis code expires in 15 minutes.\n\n- SUCFRMS",
        'SUCFRMS - SUC'
    );
    if ($err !== '') {
        error_log('PHPMailer error: ' . $err);
        return false;
    }
    return true;
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
            We received a request to reset your password. Use the confirmation code below to continue.
            This code expires in <strong>15 minutes</strong>.
          </p>

          <!-- OTP Box -->
          <div style='background:#eff6ff;border:2px dashed #1a3a6b;border-radius:10px;padding:24px;text-align:center;margin-bottom:24px;'>
            <p style='margin:0 0 6px;font-size:12px;color:#64748b;text-transform:uppercase;letter-spacing:1px;'>Your Confirmation Code</p>
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
// STEP 1 - Submit email ? send OTP
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
                    // Store everything in session  -  OTP included  -  so double-submits don't
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
    // STEP 2  -  Verify OTP
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
                // Valid  -  mark verified in both session and DB
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
    // STEP 3 - Set new password
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
