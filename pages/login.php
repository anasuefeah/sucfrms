<?php
if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/functions.php';

if (isLoggedIn()) { header('Location: ../index.php'); exit; }

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email    = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';

    if ($email && $password) {
        $stmt = $pdo->prepare("SELECT * FROM users WHERE email = ? LIMIT 1");
        $stmt->execute([$email]);
        $user = $stmt->fetch();

        if ($user && password_verify($password, $user['password'])) {
            $status = $user['status'] ?? 'active';
            if ($status === 'inactive') {
                $error = 'Your account has been deactivated. Contact the administrator.';
            } elseif ($status === 'rejected') {
                $error = 'Your account has been rejected. Contact the administrator.';
            } else {
                session_regenerate_id(true);
                $_SESSION['user_id']     = $user['user_id'];
                $_SESSION['first_name']  = $user['first_name']  ?? '';
                $_SESSION['middle_name'] = $user['middle_name'] ?? '';
                $_SESSION['last_name']   = $user['last_name']   ?? '';
                $_SESSION['full_name']   = formatDisplayName($user);
                $_SESSION['checker_label'] = $user['checker_label'] ?? null;
                $_SESSION['role']        = $user['role'];
                $_SESSION['email']       = $user['email'];
                $_SESSION['profile_pic'] = $user['profile_pic'] ?? '';
                logAudit($pdo, $user['user_id'], 'Login', 'User logged in successfully.');

                // Check if they still have an unredeemed temp password — force change immediately
                try {
                    $pr = $pdo->prepare("SELECT status FROM password_resets WHERE user_id = ? AND status = 'released' LIMIT 1");
                    $pr->execute([$user['user_id']]);
                    if ($pr->fetch()) {
                        $_SESSION['force_pw_change'] = true;
                        header('Location: ../pages/change_password.php');
                        exit;
                    }
                } catch (\Exception $e) {}

                // Faculty lands on portal overview first; all other roles go to dashboard
                $redirect = $user['role'] === 'faculty'
                    ? '../pages/portal.php'
                    : '../index.php';
                header('Location: ' . $redirect);
                exit;
            }
        } else {
            $error = 'Invalid email or password.';
        }
    } else {
        $error = 'Please fill in all fields.';
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>SUCFRMS | Login</title>
    <link href="../assets/vendor/bootstrap-icons/bootstrap-icons.min.css" rel="stylesheet">
    <link rel="stylesheet" href="../assets/css/app.css">
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }

        body {
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            font-family: 'Segoe UI', sans-serif;
            background: #f0f3f8;
        }

        body::before { display: none; }
        body::after  { display: none; }

        .login-wrap {
            width: 100%;
            max-width: 420px;
            padding: 1rem;
        }

        .login-box {
            background: #ffffff;
            border-radius: 0;
            padding: 2.25rem 2rem 1.75rem;
            box-shadow: 0 2px 16px rgba(0,0,0,0.08);
            border: 1px solid #e2e8f0;
            text-align: center;
            position: relative;
            overflow: hidden;
        }
        .login-box::before { display: none; }

        .login-box img.logo {
            width: 80px;
            height: 80px;
            object-fit: contain;
            border-radius: 50%;
            margin-bottom: 0.75rem;
            display: block;
            margin-left: auto;
            margin-right: auto;
        }

        .login-box h5 {
            color: #1a3a6b;
            font-size: 1.15rem;
            font-weight: 700;
            line-height: 1.3;
            margin-bottom: 0.25rem;
        }

        .login-box .subtitle {
            color: #94a3b8;
            font-size: 0.72rem;
            letter-spacing: 0.1em;
            text-transform: uppercase;
            margin-bottom: 1.25rem;
        }

        .field-label {
            display: block;
            text-align: left;
            font-size: 0.82rem;
            font-weight: 600;
            color: #1a3a6b;
            margin-bottom: 0.35rem;
        }

        .field-wrap {
            display: flex;
            align-items: stretch;
            border: 1.5px solid #dbeafe;
            border-radius: 0;
            overflow: hidden;
            background: #ffffff;
            margin-bottom: 1rem;
        }

        .field-wrap:focus-within {
            border-color: #1e4d8c;
            box-shadow: 0 0 0 3px rgba(30,77,140,0.15);
        }

        .field-icon {
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 0 0.75rem;
            background: #ffffff;
            border-right: 1.5px solid #e2e8f0;
            color: #1e4d8c;
            font-size: 1rem;
            flex-shrink: 0;
        }

        .field-wrap input {
            flex: 1;
            min-width: 0;
            border: none;
            outline: none;
            background: #ffffff;
            color: #1e293b;
            font-size: 0.95rem;
            padding: 0.6rem 0.75rem;
            -webkit-appearance: none;
            appearance: none;
        }

        .field-wrap input::placeholder {
            color: #94a3b8;
        }

        /* Hide browser built-in password reveal / autofill icons */
        .field-wrap input::-ms-reveal,
        .field-wrap input::-ms-clear { display: none; }
        .field-wrap input::-webkit-credentials-auto-fill-button,
        .field-wrap input::-webkit-strong-password-auto-fill-button,
        .field-wrap input::-webkit-contacts-auto-fill-button { display: none !important; visibility: hidden; pointer-events: none; }
        .field-wrap input[type="password"]::-webkit-textfield-decoration-container { display: none; }
        /* Firefox */
        .field-wrap input { -moz-appearance: none; }

        .eye-btn {
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 0 0.75rem;
            background: #ffffff;
            border: none;
            border-left: 1.5px solid #e2e8f0;
            color: #1a3a6b;
            cursor: pointer;
            font-size: 1rem;
            flex-shrink: 0;
        }

        .eye-btn:hover { background: #f8fafc; }

        .btn-signin {
            width: 100%;
            background: #1e4d8c;
            color: #ffffff;
            border: none;
            padding: 0.7rem;
            border-radius: 0;
            font-size: 0.95rem;
            font-weight: 600;
            letter-spacing: 0.01em;
            cursor: pointer;
            margin-top: 0.5rem;
            transition: background 0.2s;
        }

        .btn-signin:hover { background: #1a5276; }

        .login-links {
            margin-top: 1.25rem;
            font-size: 0.82rem;
            color: #64748b;
        }

        .login-links a { color: #1e4d8c; font-weight: 600; text-decoration: none; }
        .login-links a:hover { text-decoration: underline; }

        .track-box {
            background: #ffffff;
            border-radius: 0;
            padding: 0.85rem 1.25rem;
            box-shadow: 0 4px 20px rgba(30,77,140,0.10);
            border: 1px solid #e2e8f0;
            margin-top: 1rem;
            text-align: center;
        }

        .track-box a {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 0.6rem;
            background: #1e4d8c;
            color: #fff;
            text-decoration: none;
            font-size: 0.875rem;
            font-weight: 600;
            padding: 0.6rem 1rem;
            border-radius: 8px;
            transition: background 0.2s;
        }

        .track-box a:hover { background: #1a5276; }

        .track-box a span {
            font-size: 0.72rem;
            font-weight: 400;
            opacity: 0.8;
        }

        .alert-error {
            background: #f8fafc;
            border: 1px solid #94a3b8;
            color: #1a3a6b;
            border-radius: 8px;
            padding: 0.5rem 0.75rem;
            font-size: 0.85rem;
            margin-bottom: 1rem;
            text-align: left;
        }
    </style>
</head>
<body>

<div class="login-wrap">

    <!-- Login Card -->
    <div class="login-box">
        <img class="logo" src="../assets/images/logo.jpg" alt="Institution Logo">
        <h5>SUC Faculty Reclassification Management System</h5>
        <p class="subtitle">SUCFRMS</p>

        <?php if ($error): ?>
        <div class="alert-error"><i class="bi bi-exclamation-circle me-1"></i><?= sanitize($error) ?></div>
        <?php endif; ?>

        <form method="POST" autocomplete="on">
            <div>
                <label class="field-label">Email Address</label>
                <div class="field-wrap">
                    <span class="field-icon"><i class="bi bi-envelope"></i></span>
                    <input type="email" name="email"
                           placeholder="your.email@university.edu.ph"
                           value="<?= sanitize($_POST['email'] ?? '') ?>"
                           autocomplete="email" required>
                </div>
            </div>
            <div>
                <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:0.35rem;">
                    <label class="field-label" style="margin-bottom:0;">Password</label>
                    <a href="forgot_password.php" style="font-size:0.78rem;color:#1e4d8c;text-decoration:none;">Forgot password?</a>
                </div>
                <div class="field-wrap">
                    <span class="field-icon"><i class="bi bi-lock"></i></span>
                    <input type="password" name="password" id="passwordInput"
                           placeholder="Enter your password"
                           autocomplete="current-password"
                           data-lpignore="true"
                           data-form-type="password"
                           required>
                    <button type="button" class="eye-btn" onclick="togglePwd()">
                        <i class="bi bi-eye" id="eyeIcon"></i>
                    </button>
                </div>
            </div>
            <button type="submit" class="btn-signin">
                SIGN IN
            </button>
        </form>

        <div class="login-links">
            <p class="mb-0">Don't have an account? <a href="register.php">Register here</a></p>
        </div>
    </div>

    <!-- Track Card removed -->

</div>

<script>
function togglePwd() {
    const input = document.getElementById('passwordInput');
    const icon  = document.getElementById('eyeIcon');
    input.type  = input.type === 'password' ? 'text' : 'password';
    icon.className = input.type === 'text' ? 'bi bi-eye-slash' : 'bi bi-eye';
}
</script>
</body>
</html>
