<?php
if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/functions.php';

if (isLoggedIn()) { header('Location: ../index.php'); exit; }

if (isset($_GET['clear'])) {
    unset($_SESSION['reset_email'], $_SESSION['reset_user_id'], $_SESSION['reset_name'], $_SESSION['reset_verified']);
    header('Location: forgot_password.php'); exit;
}

try { $pdo->query("SELECT otp_code FROM password_resets LIMIT 1"); }
catch (\Exception $e) {
    $pdo->exec("ALTER TABLE password_resets
        ADD COLUMN otp_code       VARCHAR(6)  DEFAULT NULL AFTER temp_password,
        ADD COLUMN otp_expires_at DATETIME    DEFAULT NULL AFTER otp_code
    ");
}

$step  = 'request';
$error = '';

function generateOTP(): string {
    return str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);
}

require_once __DIR__ . '/../config/smtp.php';

function sendOTPEmail(string $to_email, string $to_name, string $otp): bool {
    $base = __DIR__ . '/../vendor/phpmailer/phpmailer/src/';
    if (!file_exists($base . 'PHPMailer.php')) {
        error_log('PHPMailer not found.');
        return false;
    }
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
        $mail->Subject = 'SUCFRMS - Your Password Reset Code';
        $mail->Body    = "<p>Hello <strong>" . htmlspecialchars($to_name) . "</strong>,</p>"
                       . "<p>Your 6-digit reset code is: <strong style='letter-spacing:4px;font-size:1.2em;'>{$otp}</strong></p>"
                       . "<p>This code expires in 15 minutes. Do not share it with anyone.</p>";
        $mail->AltBody = "Hello {$to_name},\n\nYour reset code: {$otp}\n\nExpires in 15 minutes.\n\n- SUCFRMS";
        $mail->send();
        return true;
    } catch (\Exception $e) {
        error_log('PHPMailer: ' . $e->getMessage());
        return false;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {

    if ($_POST['action'] === 'send_otp') {
        $email = trim($_POST['email'] ?? '');
        if (!$email) {
            $error = 'Please enter your email address.';
        } else {
            $stmt = $pdo->prepare("SELECT * FROM users WHERE email = ? AND status = 'active' LIMIT 1");
            $stmt->execute([$email]);
            $user = $stmt->fetch();
            if ($user) {
                $otp     = generateOTP();
                $expires = date('Y-m-d H:i:s', time() + 900);
                $pdo->prepare("INSERT INTO password_resets (user_id, otp_code, otp_expires_at, status, requested_at)
                    VALUES (?, ?, ?, 'pending', NOW())
                    ON DUPLICATE KEY UPDATE otp_code=VALUES(otp_code), otp_expires_at=VALUES(otp_expires_at),
                    temp_password=NULL, status='pending', requested_at=NOW()")
                    ->execute([$user['user_id'], $otp, $expires]);
                $sent = sendOTPEmail($user['email'], $user['full_name'], $otp);
                if ($sent) {
                    $_SESSION['reset_email']   = $user['email'];
                    $_SESSION['reset_user_id'] = $user['user_id'];
                    $_SESSION['reset_name']    = $user['full_name'];
                    $_SESSION['reset_otp']     = $otp;
                    $_SESSION['reset_expires'] = time() + 900;
                    logAudit($pdo, $user['user_id'], 'Password Reset OTP Sent', 'OTP sent to ' . $user['email']);
                    header('Location: forgot_password.php'); exit;
                } else {
                    $error = 'Email could not be sent. Please check SMTP settings.';
                }
            } else {
                $error = 'No active account found with that email address.';
            }
        }

    } elseif ($_POST['action'] === 'verify_otp') {
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
                $pdo->prepare("UPDATE password_resets SET status='verified' WHERE user_id=?")->execute([$uid]);
                $_SESSION['reset_verified'] = true;
                unset($_SESSION['reset_otp']);
                header('Location: forgot_password.php'); exit;
            }
        }

    } elseif ($_POST['action'] === 'reset_password') {
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
            $pdo->prepare("UPDATE users SET password=? WHERE user_id=?")->execute([password_hash($pw1, PASSWORD_DEFAULT), $uid]);
            $pdo->prepare("DELETE FROM password_resets WHERE user_id=?")->execute([$uid]);
            logAudit($pdo, $uid, 'Password Reset Complete', 'User reset password via OTP.');
            unset($_SESSION['reset_email'], $_SESSION['reset_user_id'], $_SESSION['reset_name'], $_SESSION['reset_verified']);
            $step = 'done';
        }

    } elseif ($_POST['action'] === 'resend_otp') {
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
                $_SESSION['reset_otp']     = $otp;
                $_SESSION['reset_expires'] = time() + 900;
                logAudit($pdo, $uid, 'OTP Resent', 'OTP resent to ' . $user['email']);
            }
        }
        header('Location: forgot_password.php'); exit;
    }

} else {
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
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>SUCFRMS | Forgot Password</title>
    <link href="../assets/vendor/bootstrap-icons/bootstrap-icons.min.css" rel="stylesheet">
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }

        body {
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            font-family: 'Segoe UI', sans-serif;
            background: linear-gradient(rgba(0,0,0,0.55), rgba(0,0,0,0.55)),
                        url('../assets/images/login_bg.jpg') center center / cover no-repeat fixed;
            padding: 1rem;
        }
        body::before, body::after { display: none !important; content: none !important; }

        .fp-wrap { width: 100%; max-width: 340px; }

        .fp-box {
            background: #fff;
            border-radius: 0;
            padding: 1.5rem 1.5rem 1.25rem;
            box-shadow: 0 2px 16px rgba(0,0,0,0.08);
            border: 1px solid #e2e8f0;
            text-align: center;
        }

        .fp-box img.logo {
            width: 58px; height: 58px; object-fit: contain;
            border-radius: 50%; margin: 0 auto 0.5rem; display: block;
        }
        .fp-box h5 { color: #1a3a6b; font-size: 0.95rem; font-weight: 700; line-height: 1.3; margin-bottom: 0.2rem; }
        .fp-subtitle { color: #94a3b8; font-size: 0.65rem; letter-spacing: 0.1em; text-transform: uppercase; margin-bottom: 1rem; }
        .fp-divider  { border: none; border-top: 1px solid #e2e8f0; margin-bottom: 1rem; }

        .fp-step-title { font-size: 0.88rem; font-weight: 700; color: #1e293b; text-align: left; margin-bottom: 0.25rem; }
        .fp-step-sub   { font-size: 0.75rem; color: #64748b; line-height: 1.6; text-align: left; margin-bottom: 1rem; }

        .field-label {
            display: block; text-align: left;
            font-size: 0.76rem; font-weight: 600; color: #1a3a6b; margin-bottom: 0.25rem;
        }
        .field-wrap {
            display: flex; align-items: stretch;
            border: 1.5px solid #dbeafe; border-radius: 0;
            overflow: hidden; background: #fff; margin-bottom: 0.75rem;
        }
        .field-wrap:focus-within { border-color: #1e4d8c; box-shadow: 0 0 0 3px rgba(30,77,140,0.15); }
        .field-icon {
            display: flex; align-items: center; justify-content: center;
            padding: 0 0.6rem; background: #fff;
            border-right: 1.5px solid #e2e8f0; color: #1e4d8c; font-size: 0.9rem; flex-shrink: 0;
        }
        .field-wrap input {
            flex: 1; min-width: 0; border: none; outline: none;
            background: #fff; color: #1e293b; font-size: 0.85rem;
            padding: 0.5rem 0.6rem; font-family: inherit;
        }
        .field-wrap input::placeholder { color: #94a3b8; }
        .eye-btn {
            display: flex; align-items: center; padding: 0 0.6rem;
            background: #fff; border: none; border-left: 1.5px solid #e2e8f0;
            color: #1a3a6b; cursor: pointer; font-size: 0.9rem;
        }
        .eye-btn:hover { background: #f8fafc; }

        .btn-main {
            width: 100%; background: #1e4d8c; color: #fff;
            border: none; padding: 0.55rem; border-radius: 0;
            font-size: 0.875rem; font-weight: 600; letter-spacing: 0.01em;
            cursor: pointer; transition: background 0.2s; font-family: inherit;
        }
        .btn-main:hover { background: #1a5276; }

        .fp-link {
            display: block; margin-top: 0.85rem; font-size: 0.75rem;
            color: #64748b; text-decoration: none; text-align: center;
        }
        .fp-link:hover { color: #1e4d8c; }

        .fp-alert {
            background: #f8fafc; border: 1px solid #94a3b8; color: #1a3a6b;
            border-radius: 0; padding: 0.4rem 0.65rem; font-size: 0.78rem;
            margin-bottom: 0.85rem; text-align: left;
        }

        .otp-row { display: flex; gap: 6px; justify-content: center; margin-bottom: 0.75rem; }
        .otp-digit {
            width: 42px; height: 50px; text-align: center;
            font-size: 1.35rem; font-weight: 700;
            border: 1.5px solid #dbeafe; border-radius: 0;
            background: #fff; color: #1a3a6b; outline: none; font-family: inherit;
            transition: border-color .2s, box-shadow .2s;
        }
        .otp-digit:focus { border-color: #1e4d8c; box-shadow: 0 0 0 3px rgba(30,77,140,0.15); }

        .fp-actions {
            display: flex; align-items: center; justify-content: center;
            gap: 0.75rem; margin-top: 0.75rem;
        }
        .fp-actions a, .fp-actions button {
            color: #64748b; background: none; border: none; cursor: pointer;
            font-size: 0.75rem; font-family: inherit; text-decoration: none; padding: 0;
        }
        .fp-actions a:hover, .fp-actions button:hover { color: #1e4d8c; }

        .strength-bar { height: 3px; border-radius: 0; transition: width .3s, background .3s; }

        @keyframes circleIn { from { stroke-dashoffset: 214; } to { stroke-dashoffset: 0; } }
        @keyframes checkIn  { from { stroke-dashoffset: 50;  } to { stroke-dashoffset: 0; } }
        @keyframes fadeUp   { from { opacity:0; transform:translateY(6px); } to { opacity:1; transform:translateY(0); } }
    </style>
</head>
<body>

<div class="fp-wrap">
<div class="fp-box">

    <img class="logo" src="../assets/images/logo.jpg" alt="Logo">
    <h5>SUC Faculty Reclassification Management System</h5>
    <p class="fp-subtitle">SUCFRMS</p>
    <hr class="fp-divider">

    <?php if ($error): ?>
    <div class="fp-alert"><i class="bi bi-exclamation-circle" style="margin-right:5px;"></i><?= sanitize($error) ?></div>
    <?php endif; ?>

    <?php if ($step === 'request'): ?>
        <p class="fp-step-title">Forgot Password</p>
        <p class="fp-step-sub">Enter your registered email address. We will send you a 6-digit verification code.</p>
        <form method="POST" novalidate>
            <input type="hidden" name="action" value="send_otp">
            <label class="field-label">Email Address</label>
            <div class="field-wrap">
                <span class="field-icon"><i class="bi bi-envelope"></i></span>
                <input type="email" name="email" placeholder="your.email@university.edu.ph"
                       value="<?= htmlspecialchars($_SESSION['reset_email'] ?? '') ?>"
                       autocomplete="email" required>
            </div>
            <button type="submit" class="btn-main">SEND CONFIRMATION CODE</button>
        </form>
        <a href="login.php" class="fp-link">&#8592; Back to Login</a>

    <?php elseif ($step === 'verify'): ?>
        <p class="fp-step-title">Check Your Email</p>
        <p class="fp-step-sub">We sent a 6-digit code to <strong style="color:#1a3a6b;"><?= htmlspecialchars($masked_email) ?></strong>. It expires in 15 minutes.</p>
        <form method="POST" id="otpForm" novalidate>
            <input type="hidden" name="action" value="verify_otp">
            <input type="hidden" name="otp" id="otpHidden">
            <div class="otp-row">
                <?php for ($i = 0; $i < 6; $i++): ?>
                <input type="text" maxlength="1" class="otp-digit" inputmode="numeric" pattern="[0-9]"
                       autocomplete="<?= $i === 0 ? 'one-time-code' : 'off' ?>" id="d<?= $i ?>">
                <?php endfor; ?>
            </div>
            <button type="submit" class="btn-main">CONFIRM CODE</button>
        </form>
        <div class="fp-actions">
            <form method="POST" style="display:inline;">
                <input type="hidden" name="action" value="resend_otp">
                <button type="submit"><i class="bi bi-arrow-clockwise" style="margin-right:3px;"></i>Resend</button>
            </form>
            <span style="color:#e2e8f0;">|</span>
            <a href="forgot_password.php?clear=1"><i class="bi bi-x-circle" style="margin-right:3px;"></i>Start Over</a>
        </div>
        <p id="countdown" style="font-size:0.72rem;color:#94a3b8;margin-top:0.5rem;text-align:center;"></p>

    <?php elseif ($step === 'reset'): ?>
        <p class="fp-step-title">Set New Password</p>
        <p class="fp-step-sub">Create a strong password for your account.</p>
        <form method="POST" novalidate>
            <input type="hidden" name="action" value="reset_password">
            <label class="field-label">New Password</label>
            <div class="field-wrap">
                <span class="field-icon"><i class="bi bi-lock"></i></span>
                <input type="password" name="password" id="pw1" required minlength="8"
                       placeholder="Min. 8 characters" oninput="checkStrength(this.value)">
                <button type="button" class="eye-btn" onclick="toggleVis('pw1','ei1')"><i id="ei1" class="bi bi-eye"></i></button>
            </div>
            <div style="background:#e2e8f0;height:3px;margin:-0.5rem 0 0.5rem;">
                <div id="strengthBar" class="strength-bar" style="width:0;background:#1a3a6b;"></div>
            </div>
            <small id="strengthLabel" style="display:block;text-align:left;font-size:0.7rem;color:#94a3b8;margin-bottom:0.6rem;"></small>
            <label class="field-label">Confirm Password</label>
            <div class="field-wrap">
                <span class="field-icon"><i class="bi bi-lock-fill"></i></span>
                <input type="password" name="password2" id="pw2" required placeholder="Re-enter password">
                <button type="button" class="eye-btn" onclick="toggleVis('pw2','ei2')"><i id="ei2" class="bi bi-eye"></i></button>
            </div>
            <button type="submit" class="btn-main">RESET PASSWORD</button>
        </form>

    <?php elseif ($step === 'done'): ?>
        <div style="text-align:center;padding:0.5rem 0 1rem;">
            <svg viewBox="0 0 80 80" width="64" height="64" style="display:block;margin:0 auto 1rem;overflow:visible;">
                <circle cx="40" cy="40" r="34" fill="none" stroke="#e2e8f0" stroke-width="5"/>
                <circle cx="40" cy="40" r="34" fill="none" stroke="#1e4d8c" stroke-width="5"
                        stroke-linecap="round" stroke-dasharray="214" stroke-dashoffset="214"
                        transform="rotate(-90 40 40)"
                        style="animation:circleIn 0.65s ease 0.05s forwards;"/>
                <polyline points="22,41 34,53 58,28" fill="none" stroke="#1e4d8c" stroke-width="5"
                          stroke-linecap="round" stroke-linejoin="round"
                          stroke-dasharray="50" stroke-dashoffset="50"
                          style="animation:checkIn 0.4s ease 0.65s forwards;"/>
            </svg>
            <p class="fp-step-title" style="text-align:center;animation:fadeUp 0.3s ease 0.9s both;opacity:0;">Password Updated</p>
            <p class="fp-step-sub"  style="text-align:center;margin-top:4px;animation:fadeUp 0.3s ease 1.0s both;opacity:0;margin-bottom:0;">
                Your password has been reset.<br>You may now sign in.
            </p>
        </div>
        <a href="login.php" class="btn-main"
           style="display:block;text-decoration:none;text-align:center;animation:fadeUp 0.3s ease 1.1s both;opacity:0;">
            SIGN IN
        </a>
    <?php endif; ?>

</div>
</div>

<script>
(function () {
    const digits = document.querySelectorAll('.otp-digit');
    const hidden = document.getElementById('otpHidden');
    const form   = document.getElementById('otpForm');
    if (!digits.length) return;
    digits.forEach((el, idx) => {
        el.addEventListener('input', () => {
            el.value = el.value.replace(/\D/g,'').slice(-1);
            if (el.value && idx < digits.length - 1) digits[idx+1].focus();
            sync();
        });
        el.addEventListener('keydown', e => {
            if (e.key === 'Backspace' && !el.value && idx > 0) { digits[idx-1].focus(); digits[idx-1].value=''; sync(); }
        });
        el.addEventListener('paste', e => {
            e.preventDefault();
            const t = (e.clipboardData||window.clipboardData).getData('text').replace(/\D/g,'');
            [...t].slice(0,6).forEach((c,i) => { if(digits[i]) digits[i].value=c; });
            digits[Math.min(t.length,5)].focus(); sync();
        });
    });
    function sync() { if(hidden) hidden.value=[...digits].map(d=>d.value).join(''); }
    form && form.addEventListener('submit', e => { sync(); if(hidden.value.length!==6){e.preventDefault();showToast('Please enter all 6 digits.','warning');} });
    digits[0] && digits[0].focus();
})();

(function () {
    const el = document.getElementById('countdown');
    if (!el) return;
    let s = 15*60;
    const tick = () => {
        const m=Math.floor(s/60), sec=s%60;
        el.textContent = `Code expires in ${m}:${sec.toString().padStart(2,'0')}`;
        if (s-- <= 0) { el.textContent='Code expired. Please request a new one.'; return; }
        setTimeout(tick, 1000);
    };
    tick();
})();

function checkStrength(v) {
    const bar=document.getElementById('strengthBar'), lbl=document.getElementById('strengthLabel');
    if (!bar) return;
    let n=0;
    if(v.length>=8)n++; if(/[A-Z]/.test(v))n++; if(/[0-9]/.test(v))n++; if(/[^A-Za-z0-9]/.test(v))n++;
    const m=[{w:'0%',c:'#e2e8f0',t:''},{w:'25%',c:'#94a3b8',t:'Weak'},{w:'50%',c:'#64748b',t:'Fair'},{w:'75%',c:'#1a3a6b',t:'Good'},{w:'100%',c:'#1e4d8c',t:'Strong'}];
    bar.style.width=m[n].w; bar.style.background=m[n].c;
    lbl.textContent=m[n].t; lbl.style.color=m[n].c;
}

function toggleVis(id, iconId) {
    const i=document.getElementById(id), ic=document.getElementById(iconId);
    if(!i) return;
    i.type = i.type==='password'?'text':'password';
    ic.className = i.type==='text'?'bi bi-eye-slash':'bi bi-eye';
}
</script>
</body>
</html>
