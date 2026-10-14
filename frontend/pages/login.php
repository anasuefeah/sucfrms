<?php require __DIR__ . '/../../backend/auth/login.php'; ?>
<?php $authTitle = 'Login'; include __DIR__ . '/../layouts/auth_start.php'; ?>

            <h2 class="m-0 text-3xl font-semibold tracking-tight text-slate-900">Welcome back</h2>
            <p class="m-0 mt-2 mb-8 text-sm text-slate-500">Sign in to the SUC Faculty Reclassification Management System.</p>

            <?php if ($error): ?>
            <div class="auth-error"><i class="bi bi-exclamation-circle mt-0.5"></i><span><?= sanitize($error) ?></span></div>
            <?php endif; ?>

            <form method="POST" autocomplete="on" class="grid gap-5">
                <div>
                    <label class="auth-label" for="emailInput">Email address</label>
                    <div class="auth-field">
                        <i class="bi bi-envelope"></i>
                        <input type="email" name="email" id="emailInput"
                               placeholder="your.email@university.edu.ph"
                               value="<?= sanitize($_POST['email'] ?? '') ?>"
                               autocomplete="email" required>
                    </div>
                </div>
                <div>
                    <div class="mb-1.5 flex items-center justify-between">
                        <label class="auth-label mb-0" for="passwordInput">Password</label>
                        <a href="forgot_password.php" class="text-xs font-medium text-brand-600 no-underline hover:text-brand-800">Forgot password?</a>
                    </div>
                    <div class="auth-field">
                        <i class="bi bi-lock"></i>
                        <input type="password" name="password" id="passwordInput"
                               placeholder="Enter your password"
                               autocomplete="current-password"
                               data-lpignore="true"
                               data-form-type="password"
                               required>
                        <button type="button" class="auth-eye" onclick="togglePwd()" aria-label="Show password">
                            <i class="bi bi-eye" id="eyeIcon"></i>
                        </button>
                    </div>
                </div>
                <button type="submit" class="auth-submit mt-1">
                    Sign in <i class="bi bi-arrow-right"></i>
                </button>
            </form>

            <p class="m-0 mt-8 text-center text-xs text-slate-400">
                Accounts are issued by your campus administrator.
            </p>

<?php include __DIR__ . '/../layouts/auth_end.php'; ?>
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
