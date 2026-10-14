<?php require __DIR__ . '/../../backend/auth/forgot_password.php'; ?>
<?php $authTitle = 'Forgot Password'; include __DIR__ . '/../layouts/auth_start.php'; ?>

            <?php if ($error): ?>
            <div class="auth-error"><i class="bi bi-exclamation-circle mt-0.5"></i><span><?= sanitize($error) ?></span></div>
            <?php endif; ?>

            <!-- STEP 1 - Request Form -->
            <?php if ($step === 'request'): ?>
            <div class="auth-icon"><i class="bi bi-envelope-at"></i></div>
            <h2 class="m-0 text-3xl font-semibold tracking-tight text-slate-900">Forgot password?</h2>
            <p class="m-0 mt-2 mb-8 text-sm text-slate-500">
                Enter your registered email address and we'll send you a 6-digit confirmation code.
            </p>

            <form method="POST" novalidate class="grid gap-5">
                <input type="hidden" name="action" value="send_otp">
                <div>
                    <label class="auth-label" for="fpEmail">Email address</label>
                    <div class="auth-field">
                        <i class="bi bi-envelope"></i>
                        <input type="email" name="email" id="fpEmail"
                               placeholder="your.email@university.edu.ph" required
                               value="<?= htmlspecialchars($_SESSION['reset_email'] ?? '') ?>">
                    </div>
                </div>
                <button type="submit" class="auth-submit">
                    <i class="bi bi-send"></i>Send confirmation code
                </button>
            </form>

            <p class="m-0 mt-8 text-center text-sm">
                <a href="login.php" class="inline-flex items-center gap-1.5 font-medium text-slate-500 no-underline hover:text-slate-800"><i class="bi bi-arrow-left"></i>Back to login</a>
            </p>

            <!-- STEP 2 - OTP Verify -->
            <?php elseif ($step === 'verify'): ?>
            <div class="auth-icon"><i class="bi bi-shield-lock"></i></div>
            <h2 class="m-0 text-3xl font-semibold tracking-tight text-slate-900">Check your email</h2>
            <p class="m-0 mt-2 mb-8 text-sm leading-relaxed text-slate-500">
                We sent a 6-digit code to
                <strong class="font-semibold text-slate-900"><?= htmlspecialchars($masked_email) ?></strong>.
                It expires in 15 minutes.
            </p>

            <form method="POST" id="otpForm" novalidate>
                <input type="hidden" name="action" value="verify_otp">
                <input type="hidden" name="otp" id="otpHidden">

                <div class="mb-6 flex justify-between gap-2" id="otpBoxes">
                    <?php for ($i = 0; $i < 6; $i++): ?>
                    <input type="text" maxlength="1" class="otp-digit"
                           inputmode="numeric" pattern="[0-9]"
                           autocomplete="<?= $i === 0 ? 'one-time-code' : 'off' ?>"
                           id="d<?= $i ?>">
                    <?php endfor; ?>
                </div>

                <button type="submit" id="verifyBtn" class="auth-submit">
                    <i class="bi bi-check-circle"></i>Confirm code
                </button>
            </form>

            <div class="mt-6 flex items-center justify-center gap-4 text-sm">
                <form method="POST" class="inline">
                    <input type="hidden" name="action" value="resend_otp">
                    <button type="submit" class="inline-flex cursor-pointer items-center gap-1.5 border-0 bg-transparent p-0 font-medium text-brand-600 hover:text-brand-800">
                        <i class="bi bi-arrow-clockwise"></i>Resend code
                    </button>
                </form>
                <span class="text-slate-300">&middot;</span>
                <a href="forgot_password.php?clear=1" class="inline-flex items-center gap-1.5 font-medium text-slate-500 no-underline hover:text-slate-800">
                    <i class="bi bi-x-circle"></i>Start over
                </a>
            </div>

            <p id="countdown" class="m-0 mt-3 text-center text-xs text-slate-400"></p>

            <!-- STEP 3 - New Password Form -->
            <?php elseif ($step === 'reset'): ?>
            <div class="auth-icon"><i class="bi bi-key"></i></div>
            <h2 class="m-0 text-3xl font-semibold tracking-tight text-slate-900">Set a new password</h2>
            <p class="m-0 mt-2 mb-8 text-sm text-slate-500">Create a strong password for your account.</p>

            <form method="POST" novalidate class="grid gap-5">
                <input type="hidden" name="action" value="reset_password">

                <div>
                    <label class="auth-label" for="pw1">New password</label>
                    <div class="auth-field">
                        <i class="bi bi-lock"></i>
                        <input type="password" name="password" id="pw1" required minlength="8"
                               placeholder="Min. 8 characters" oninput="checkStrength(this.value)">
                        <button type="button" class="auth-eye" onclick="toggleVis('pw1','eyeIcon1')" aria-label="Show password">
                            <i id="eyeIcon1" class="bi bi-eye"></i>
                        </button>
                    </div>
                    <div class="mt-2 h-1 overflow-hidden rounded-full bg-slate-200">
                        <div id="strengthBar" class="strength-bar h-full w-0 rounded-full bg-slate-700"></div>
                    </div>
                    <small id="strengthLabel" class="mt-1 block text-xs text-slate-400"></small>
                </div>

                <div>
                    <label class="auth-label" for="pw2">Confirm password</label>
                    <div class="auth-field">
                        <i class="bi bi-lock-fill"></i>
                        <input type="password" name="password2" id="pw2" required placeholder="Re-enter password">
                        <button type="button" class="auth-eye" onclick="toggleVis('pw2','eyeIcon2')" aria-label="Show password">
                            <i id="eyeIcon2" class="bi bi-eye"></i>
                        </button>
                    </div>
                </div>

                <button type="submit" class="auth-submit">
                    <i class="bi bi-check-lg"></i>Reset password
                </button>
            </form>

            <!-- STEP 4 - Done -->
            <?php elseif ($step === 'done'): ?>
            <div class="auth-icon bg-emerald-50 text-emerald-600 ring-emerald-100"><i class="bi bi-check-circle"></i></div>
            <h2 class="m-0 text-3xl font-semibold tracking-tight text-slate-900">Password updated</h2>
            <p class="m-0 mt-2 mb-8 text-sm leading-relaxed text-slate-500">
                Your password has been successfully reset. You can now log in with your new password.
            </p>
            <a href="login.php" class="auth-submit">
                <i class="bi bi-box-arrow-in-right"></i>Go to login
            </a>
            <?php endif; ?>

<?php include __DIR__ . '/../layouts/auth_end.php'; ?>
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
