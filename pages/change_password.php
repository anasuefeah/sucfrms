<?php
/**
 * Forced first-login password change.
 * Only reachable when $_SESSION['force_pw_change'] is true.
 * No navigation — user cannot escape until they set a real password.
 */
if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/functions.php';

// Must be logged in
if (!isLoggedIn()) {
    header('Location: login.php');
    exit;
}

// If they already changed it (flag gone), send them home
if (empty($_SESSION['force_pw_change'])) {
    $dest = ($_SESSION['role'] ?? '') === 'faculty'
        ? 'portal.php'
        : '../index.php';
    header('Location: ' . $dest);
    exit;
}

$uid      = $_SESSION['user_id'];
$error    = '';
$success  = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $new_pw  = $_POST['new_password']     ?? '';
    $confirm = $_POST['confirm_password'] ?? '';

    if (strlen($new_pw) < 8) {
        $error = 'Password must be at least 8 characters.';
    } elseif (!preg_match('/[0-9]/', $new_pw)) {
        $error = 'Password must contain at least one number.';
    } elseif (!preg_match('/[^a-zA-Z0-9]/', $new_pw)) {
        $error = 'Password must contain at least one special character (e.g. @, #, !).';
    } elseif (!preg_match('/[a-z]/', $new_pw) || !preg_match('/[A-Z]/', $new_pw)) {
        $error = 'Password must contain both uppercase and lowercase letters.';
    } elseif ($new_pw !== $confirm) {
        $error = 'Passwords do not match.';
    } else {
        $hash = password_hash($new_pw, PASSWORD_DEFAULT);
        $pdo->prepare("UPDATE users SET password = ? WHERE user_id = ?")
            ->execute([$hash, $uid]);
        // Clear the temp password record
        try {
            $pdo->prepare("DELETE FROM password_resets WHERE user_id = ?")
                ->execute([$uid]);
        } catch (\Exception $e) {}

        logAudit($pdo, $uid, 'Password Changed', 'User set a new password after first login.');
        unset($_SESSION['force_pw_change']);
        $success = true;
    }
}

$full_name = formatDisplayName([
    'first_name'  => $_SESSION['first_name']  ?? '',
    'middle_name' => $_SESSION['middle_name'] ?? '',
    'last_name'   => $_SESSION['last_name']   ?? '',
    'full_name'   => $_SESSION['full_name']   ?? 'User',
]);
$role      = $_SESSION['role'] ?? 'faculty';
$home_url  = $role === 'faculty' ? 'portal.php' : '../index.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>SUCFRMS — Set Your Password</title>
    <link href="../assets/vendor/bootstrap-icons/bootstrap-icons.min.css" rel="stylesheet">
    <style>
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

        body {
            min-height: 100vh;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            font-family: 'Segoe UI', Arial, sans-serif;
            background: #f0f3f8;
            padding: 1.5rem;
        }

        .card {
            background: #fff;
            width: 100%;
            max-width: 460px;
            border-radius: 14px;
            box-shadow: 0 8px 32px rgba(26,58,107,.12);
            overflow: hidden;
        }

        /* Header */
        .card-header {
            background: linear-gradient(135deg, #1a3a6b, #1e4d8c);
            padding: 1.5rem 1.75rem 1.25rem;
            display: flex;
            align-items: center;
            gap: 1rem;
        }
        .card-header img {
            width: 44px; height: 44px;
            border-radius: 50%;
            object-fit: cover;
            border: 2px solid rgba(255,255,255,.35);
            flex-shrink: 0;
        }
        .card-header-title  { color: #fff; font-size: .95rem; font-weight: 700; line-height: 1.2; }
        .card-header-sub    { color: rgba(255,255,255,.6); font-size: .72rem; margin-top: 2px; }

        /* Alert banner */
        .alert-banner {
            background: #fffbeb;
            border-bottom: 1px solid #fde68a;
            padding: .65rem 1.75rem;
            display: flex;
            align-items: flex-start;
            gap: .65rem;
            font-size: .82rem;
            color: #92400e;
        }
        .alert-banner i { font-size: 1rem; flex-shrink: 0; margin-top: 1px; color: #d97706; }
        .alert-banner strong { color: #78350f; }

        /* Body */
        .card-body { padding: 1.75rem; }

        /* Success state */
        .success-body {
            padding: 2.5rem 1.75rem;
            text-align: center;
        }
        .success-icon {
            width: 80px; height: 80px;
            border-radius: 50%;
            background: #1a3a6b;
            display: flex; align-items: center; justify-content: center;
            margin: 0 auto 1.25rem;
        }
        .success-title { font-size: 1.1rem; font-weight: 700; color: #0f172a; margin-bottom: .5rem; }
        .success-sub   { font-size: .85rem; color: #64748b; line-height: 1.6; margin-bottom: 1.5rem; }

        /* Form */
        label { display: block; font-size: .8rem; font-weight: 600; color: #475569; margin-bottom: 5px; }
        .input-wrap { position: relative; margin-bottom: 1rem; }
        .input-wrap input {
            width: 100%; padding: .6rem .75rem;
            border: 1.5px solid #e2e8f0; border-radius: 8px;
            font-size: .9rem; outline: none;
            transition: border-color .15s;
            padding-right: 2.5rem;
        }
        .input-wrap input:focus { border-color: #1e4d8c; }
        /* Hide browser native password reveal buttons */
        .input-wrap input::-ms-reveal,
        .input-wrap input::-ms-clear { display: none !important; }
        .input-wrap input::-webkit-credentials-auto-fill-button,
        .input-wrap input::-webkit-strong-password-auto-fill-button {
            display: none !important;
            visibility: hidden !important;
            pointer-events: none !important;
            position: absolute !important;
            right: -9999px !important;
        }
        .input-wrap .eye-btn {
            position: absolute; right: .6rem; top: 50%;
            transform: translateY(-50%);
            background: none; border: none;
            color: #94a3b8; cursor: pointer;
            font-size: .95rem; line-height: 1; padding: 0;
        }
        .input-wrap .eye-btn:hover { color: #475569; }

        /* Error */
        .err-box {
            background: #fef2f2;
            border: 1px solid #fecaca;
            border-radius: 8px;
            padding: .6rem .9rem;
            font-size: .82rem;
            color: #dc2626;
            display: flex; align-items: center; gap: .5rem;
            margin-bottom: 1rem;
        }

        /* Strength bar */
        .strength-bars {
            display: flex; gap: 3px; margin-top: 6px; margin-bottom: 2px;
        }
        .strength-bar {
            flex: 1; height: 3px; border-radius: 2px;
            background: #e2e8f0;
            transition: background .25s;
        }
        .req-list {
            list-style: none;
            padding: 0; margin: 6px 0 1.25rem;
            display: grid; grid-template-columns: 1fr 1fr; gap: 2px 8px;
        }
        .req-list li {
            font-size: .72rem; color: #94a3b8;
            display: flex; align-items: center; gap: 4px;
            transition: color .2s;
        }
        .req-list li.met { color: #16a34a; font-weight: 600; }
        .req-list li i  { font-size: .7rem; width: 12px; }

        /* Submit */
        .btn-submit {
            width: 100%;
            background: #1a3a6b;
            color: #fff;
            border: none;
            border-radius: 8px;
            padding: .75rem;
            font-size: .95rem;
            font-weight: 700;
            cursor: pointer;
            transition: background .15s;
            display: flex; align-items: center; justify-content: center; gap: .5rem;
        }
        .btn-submit:hover { background: #1e4d8c; }
        .btn-submit:disabled { background: #94a3b8; cursor: not-allowed; }

        @keyframes cpCheckDraw {
            from { stroke-dashoffset: 50; }
            to   { stroke-dashoffset: 0;  }
        }

        /* Countdown */
        .countdown { font-size: .78rem; color: #94a3b8; text-align: center; margin-top: .75rem; }
        .countdown strong { color: #1e4d8c; }

        /* Footer note */
        .footer-note {
            text-align: center;
            font-size: .72rem;
            color: #94a3b8;
            margin-top: 1.5rem;
        }
    </style>
</head>
<body>

<div class="card">

    <!-- Header -->
    <div class="card-header">
        <img src="../assets/images/logo.jpg" alt="SUCFRMS">
        <div>
            <div class="card-header-title">SUCFRMS</div>
            <div class="card-header-sub">SUC Faculty Reclassification Management System</div>
        </div>
    </div>

    <?php if ($success): ?>

    <!-- ── Success ── -->
    <div class="success-body">
        <div class="success-icon" style="width:80px;height:80px;border-radius:50%;background:#1a3a6b;display:flex;align-items:center;justify-content:center;margin:0 auto 1.25rem;border:none;">
            <svg width="44" height="44" viewBox="0 0 52 52" style="display:block;">
                <polyline points="12,27 22,37 40,17" fill="none" stroke="#fff" stroke-width="4.5"
                          stroke-linecap="round" stroke-linejoin="round"
                          stroke-dasharray="50" stroke-dashoffset="50"
                          style="animation:cpCheckDraw 0.4s ease 0.15s both;"/>
            </svg>
        </div>
        <div class="success-title">Password Set Successfully</div>
        <div class="success-sub">
            Your account is now secured.<br>
            Redirecting you to the system&hellip;
        </div>
        <div class="countdown">
            You'll be redirected in <strong id="cdCount">3</strong>s
        </div>
    </div>
    <script>
        let s = 3;
        const el = document.getElementById('cdCount');
        const t = setInterval(() => {
            s--;
            if (el) el.textContent = s;
            if (s <= 0) { clearInterval(t); window.location.href = '<?= htmlspecialchars($home_url) ?>'; }
        }, 1000);
    </script>

    <?php else: ?>

    <!-- ── Alert banner ── -->
    <div class="alert-banner">
        <div>
            <strong>Action required before you continue.</strong><br>
            You logged in with a temporary password. Please set a permanent password now to access the system.
        </div>
    </div>

    <!-- ── Form ── -->
    <div class="card-body">

        <?php if ($error): ?>
        <div class="err-box">
            <i class="bi bi-x-circle-fill"></i>
            <?= htmlspecialchars($error) ?>
        </div>
        <?php endif; ?>

        <form method="POST" id="cpForm" novalidate>

            <!-- New password -->
            <div>
                <label for="np">New Password <span style="color:#dc2626;">*</span></label>
                <div class="input-wrap">
                    <input type="password" id="np" name="new_password"
                           placeholder="Min. 8 characters"
                           autocomplete="new-password"
                           oninput="checkStrength(this.value)"
                           data-lpignore="true"
                           data-form-type="other"
                           required>
                    <button type="button" class="eye-btn" onclick="toggleEye('np', this)" aria-label="Toggle password visibility">
                        <i class="bi bi-eye"></i>
                    </button>
                </div>
                <!-- Strength bar -->
                <div class="strength-bars">
                    <div class="strength-bar" id="sb1"></div>
                    <div class="strength-bar" id="sb2"></div>
                    <div class="strength-bar" id="sb3"></div>
                    <div class="strength-bar" id="sb4"></div>
                </div>
                <!-- Requirements checklist -->
                <ul class="req-list">
                    <li id="req-len"><i class="bi bi-circle"></i>At least 8 chars</li>
                    <li id="req-num"><i class="bi bi-circle"></i>A number</li>
                    <li id="req-sym"><i class="bi bi-circle"></i>A special char</li>
                    <li id="req-case"><i class="bi bi-circle"></i>Upper &amp; lowercase</li>
                </ul>
            </div>

            <!-- Confirm password -->
            <div>
                <label for="cp">Confirm New Password <span style="color:#dc2626;">*</span></label>
                <div class="input-wrap">
                    <input type="password" id="cp" name="confirm_password"
                           placeholder="Re-enter password"
                           autocomplete="new-password"
                           oninput="checkMatch()"
                           data-lpignore="true"
                           data-form-type="other"
                           required>
                    <button type="button" class="eye-btn" onclick="toggleEye('cp', this)" aria-label="Toggle password visibility">
                        <i class="bi bi-eye"></i>
                    </button>
                </div>
                <div id="matchMsg" style="font-size:.72rem;min-height:16px;margin-bottom:.75rem;"></div>
            </div>

            <button type="submit" class="btn-submit" id="submitBtn" disabled>
                Set Password
            </button>

        </form>

    </div><!-- /card-body -->

    <?php endif; ?>

</div><!-- /card -->

<div class="footer-note">
    &copy; <?= date('Y') ?> SUCFRMS &mdash; SUC Faculty Reclassification Management System
</div>

<script>
const COLORS = ['#e2e8f0','#475569','#475569','#1a3a6b'];

function toggleEye(inputId, btn) {
    const inp = document.getElementById(inputId);
    const icon = btn.querySelector('i');
    if (inp.type === 'password') {
        inp.type = 'text';
        btn.setAttribute('aria-pressed', 'true');
        if (icon) icon.className = 'bi bi-eye-slash';
    } else {
        inp.type = 'password';
        btn.setAttribute('aria-pressed', 'false');
        if (icon) icon.className = 'bi bi-eye';
    }
}

function checkStrength(val) {
    const hasLen  = val.length >= 8;
    const hasNum  = /[0-9]/.test(val);
    const hasSym  = /[^a-zA-Z0-9]/.test(val);
    const hasLow  = /[a-z]/.test(val);
    const hasUpp  = /[A-Z]/.test(val);
    const hasCase = hasLow && hasUpp;

    // Update requirement items
    setReq('req-len',  hasLen);
    setReq('req-num',  hasNum);
    setReq('req-sym',  hasSym);
    setReq('req-case', hasCase);

    const score = [hasLen, hasNum, hasSym, hasCase].filter(Boolean).length;
    const bars  = document.querySelectorAll('.strength-bar');
    bars.forEach((b, i) => {
        b.style.background = i < score ? COLORS[score - 1] : '#e2e8f0';
    });

    checkMatch();
    updateSubmit(hasLen && hasNum && hasSym && hasCase);
}

function setReq(id, met) {
    const el = document.getElementById(id);
    if (!el) return;
    el.classList.toggle('met', met);
    const icon = el.querySelector('i');
    if (icon) icon.className = met ? 'bi bi-check-circle-fill' : 'bi bi-circle';
}

function checkMatch() {
    const np  = document.getElementById('np').value;
    const cp  = document.getElementById('cp').value;
    const msg = document.getElementById('matchMsg');
    if (!cp) { msg.textContent = ''; return; }
    if (np === cp) {
        msg.style.color = '#16a34a';
        msg.textContent = '✓ Passwords match';
    } else {
        msg.style.color = '#dc2626';
        msg.textContent = '✗ Passwords do not match';
    }
    updateSubmit();
}

function updateSubmit(allReqsMet) {
    const np   = document.getElementById('np').value;
    const cp   = document.getElementById('cp').value;
    const btn  = document.getElementById('submitBtn');
    const reqs = allReqsMet !== undefined ? allReqsMet :
        (np.length >= 8 && /[0-9]/.test(np) && /[^a-zA-Z0-9]/.test(np) &&
         /[a-z]/.test(np) && /[A-Z]/.test(np));
    btn.disabled = !(reqs && np === cp && cp.length > 0);
}

// Focus the password field on load
document.addEventListener('DOMContentLoaded', () => {
    document.getElementById('np')?.focus();
});
</script>

</body>
</html>
