    <footer class="mt-5 py-4 text-center">
        <p style="margin:0;font-size:0.78rem;color:#94a3b8;">
            &copy; <?= date('Y') ?> SUCFRMS - SUC Faculty Reclassification Management System. All rights reserved.
        </p>
    </footer>

    <!-- Sign Out Confirmation Modal -->
    <div id="logout-modal"
         style="display:none;position:fixed;inset:0;background:rgba(15,23,42,0.55);z-index:100003;align-items:center;justify-content:center;">
        <div style="background:#fff;border-radius:0;width:100%;max-width:360px;margin:1rem;
                    box-shadow:0 8px 40px rgba(0,0,0,0.18);overflow:hidden;border:1px solid #e2e8f0;">

            <!-- Header -->
            <div style="background:#1a3a6b;padding:0.85rem 1.1rem;display:flex;align-items:center;gap:0.6rem;">
                <img src="assets/images/logo.jpg" alt="" style="width:28px;height:28px;object-fit:cover;border-radius:50%;border:1.5px solid rgba(255,255,255,0.25);flex-shrink:0;">
                <div>
                    <div style="color:#fff;font-weight:700;font-size:0.78rem;line-height:1.2;">SUCFRMS</div>
                    <div style="color:#93c5fd;font-size:0.62rem;">SUC Faculty Reclassification Management System</div>
                </div>
            </div>

            <!-- Body -->
            <div style="padding:1.35rem 1.25rem 1rem;">
                <div style="font-weight:700;color:#1e293b;font-size:0.88rem;margin-bottom:5px;">Sign Out</div>
                <p style="color:#64748b;font-size:0.78rem;margin:0;line-height:1.55;">
                    Are you sure you want to sign out of your account?
                </p>
            </div>

            <!-- Buttons -->
            <div style="padding:0 1.25rem 1.1rem;display:flex;gap:0.5rem;">
                <button onclick="document.getElementById('logout-modal').style.display='none'"
                        style="flex:1;padding:0.5rem;border:1px solid #e2e8f0;border-radius:0;
                               background:#fff;color:#475569;font-weight:600;cursor:pointer;font-size:0.78rem;">
                    Cancel
                </button>
                <a href="pages/logout.php"
                   style="flex:1;padding:0.5rem;border:none;border-radius:0;background:#dc2626;
                          color:#fff;font-weight:700;font-size:0.78rem;text-align:center;
                          text-decoration:none;display:flex;align-items:center;justify-content:center;">
                    Sign Out
                </a>
            </div>

        </div>
    </div>
    <script src="assets/js/app.js?v=<?= filemtime(__DIR__ . '/../assets/js/app.js') ?>"></script>
    <?php renderConfirmModal(); ?>

    <!-- -- Help FAB (floating bottom-right) -- -->
    <?php if (isset($_SESSION['user_id'])): ?>
    <div style="position:fixed;bottom:1.5rem;right:calc(1.5rem + var(--scrollbar-w, 0px));z-index:100001;">

        <button id="helpBtn" onclick="toggleHelpDropdown()" title="Help"
                style="width:40px;height:40px;border-radius:50%;
                       background:#1a3a6b;border:none;color:rgba(255,255,255,0.85);
                       font-size:1rem;cursor:pointer;
                       display:flex;align-items:center;justify-content:center;
                       box-shadow:0 2px 10px rgba(0,0,0,0.2);
                       transition:all 0.2s;"
                onmouseover="this.style.background='#1e4d8c';this.style.color='#fff'"
                onmouseout="this.style.background='#1a3a6b';this.style.color='rgba(255,255,255,0.85)'">
            <i class="bi bi-question"></i>
        </button>

        <div id="helpDropdown"
             style="display:none;position:fixed;bottom:4.5rem;right:1.5rem;
                    width:240px;background:#fff;border-radius:10px;
                    box-shadow:0 4px 24px rgba(0,0,0,0.14);
                    z-index:100002;overflow:hidden;border:1px solid #e5e7eb;">

            <?php
            $role      = $_SESSION['role'] ?? '';
            $is_faculty  = $role === 'faculty';
            $is_checker  = ($role === 'evaluator');
            $is_admin    = $role === 'admin';

            // -- Section label helper -----------------------------
            $sectionLabel = function(string $label) {
                return '<div style="padding:0.4rem 1rem 0.25rem;font-size:0.62rem;font-weight:700;
                                    text-transform:uppercase;letter-spacing:0.07em;color:#94a3b8;">'
                       . htmlspecialchars($label) . '</div>';
            };

            $item = function(string $href, string $icon, string $label, bool $isBtn = false, string $onClick = '') {
                $click = $onClick ? ' onclick="' . $onClick . '"' : '';
                $tag   = $isBtn ? 'a href="#"' : 'a href="' . $href . '"';
                return '<' . $tag . $click . '
                           style="display:flex;align-items:center;gap:0.65rem;
                                  padding:0.65rem 1rem;color:#374151;text-decoration:none;
                                  font-size:0.82rem;transition:background 0.12s;"
                           onmouseover="this.style.background=\'#f9fafb\'"
                           onmouseout="this.style.background=\'\'">
                            <i class="bi ' . $icon . '" style="color:#1a3a6b;font-size:0.85rem;width:16px;flex-shrink:0;"></i>
                            ' . htmlspecialchars($label) . '
                        </a>';
            };
            ?>

            <!-- General -->
            <?php echo $sectionLabel('General'); ?>
            <?php echo $item('index.php?page=help', 'bi-book', 'Help & Guide'); ?>

            <?php if ($is_faculty || $is_checker): ?>
            <!-- Role-specific -->
            <div style="height:1px;background:#f3f4f6;margin:0.25rem 0;"></div>
            <?php if ($is_faculty): ?>
            <?php echo $sectionLabel('Faculty'); ?>
            <?php echo $item('pages/self_assessment.php', 'bi-calculator', 'Self-Assessment'); ?>
            <?php echo $item('index.php?page=apply', 'bi-pencil-square', 'My KRA Entries'); ?>
            <?php endif; ?>
            <?php if ($is_checker): ?>
            <?php echo $sectionLabel('Evaluator'); ?>
            <?php echo $item('index.php?page=review_queue', 'bi-inbox', 'Review Queue'); ?>
            <?php echo $item('index.php?page=appeals', 'bi-chat-square-text', 'Appeals'); ?>
            <?php endif; ?>
            <?php endif; ?>

            <?php if ($is_admin): ?>
            <div style="height:1px;background:#f3f4f6;margin:0.25rem 0;"></div>
            <?php echo $sectionLabel('Admin'); ?>
            <?php echo $item('index.php?page=cycles', 'bi-calendar-range', 'Manage Cycles'); ?>
            <?php echo $item('index.php?page=manage_users', 'bi-people', 'Manage Users'); ?>
            <?php echo $item('index.php?page=analytics', 'bi-bar-chart-line', 'Analytics'); ?>
            <?php endif; ?>

            <!-- Feedback -->
            <div style="height:1px;background:#f3f4f6;margin:0.25rem 0;"></div>
            <?php echo $item('#', 'bi-chat-dots', 'Send Feedback', true, "openFeedbackModal();document.getElementById('helpDropdown').style.display='none';return false;"); ?>

        </div>
    </div>

    <script>
    (function() {
        const btn = document.getElementById('helpBtn');
        const dd  = document.getElementById('helpDropdown');
        if (!btn || !dd) return;

        btn.addEventListener('click', function(e) {
            e.stopPropagation();
            dd.style.display = dd.style.display === 'none' ? 'block' : 'none';
        });

        document.addEventListener('click', function(e) {
            if (dd.style.display === 'none') return;
            if (!dd.contains(e.target) && e.target !== btn && !btn.contains(e.target)) {
                dd.style.display = 'none';
            }
        });
    })();
    </script>
    <?php endif; ?>

    <!-- -- Help Tooltip System -- -->
    <style>
    .help-btn {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        width: 16px;
        height: 16px;
        min-width: 16px;
        min-height: 16px;
        border-radius: 50% !important;
        background: #fff;
        color: #1e4d8c;
        font-size: 0.62rem;
        font-weight: 700;
        cursor: pointer;
        border: 1.5px solid #bfdbfe;
        line-height: 1;
        flex-shrink: 0;
        transition: background 0.2s, color 0.2s;
        padding: 0;
        box-sizing: border-box;
        position: static;
    }
    .help-btn:hover { background: #1e4d8c; color: #fff; border-color: #1e4d8c; }

    .help-popover {
        display: none;
        position: fixed;
        z-index: 999999;
        background: #ffffff;
        color: #1e293b;
        border-radius: 8px;
        padding: 0.85rem 1rem;
        width: 300px;
        font-size: 0.78rem;
        font-weight: 400;
        line-height: 1.6;
        box-shadow: 0 8px 32px rgba(30,58,107,0.18);
        border: 1px solid #e2e8f0;
        border-top: 3px solid #1a3a6b;
        text-align: left;
        pointer-events: none;
    }
    .help-popover strong {
        color: #1a3a6b;
        display: block;
        margin-bottom: 5px;
        font-size: 0.8rem;
        font-weight: 700;
        letter-spacing: 0.02em;
        padding-bottom: 5px;
        border-bottom: 1px solid #f0f4fb;
    }
    .help-btn { outline: none; }
    </style>
    <!-- Help popover singleton (fixed position, escapes overflow) -->
    <div id="helpPopoverEl" class="help-popover" style="display:none;">
        <strong id="helpPopoverTitle"></strong>
        <span id="helpPopoverBody"></span>
    </div>
    <script>
    (function() {
        const pop = document.getElementById('helpPopoverEl');
        let hideTimer = null;

        document.querySelectorAll('.help-btn').forEach(btn => {
            btn.addEventListener('mouseenter', function() {
                clearTimeout(hideTimer);
                const title = btn.getAttribute('data-title');
                const body  = btn.getAttribute('data-body');
                document.getElementById('helpPopoverTitle').textContent = title;
                document.getElementById('helpPopoverBody').textContent  = body;

                pop.style.display = 'block';
                const r = btn.getBoundingClientRect();
                const pw = 300;
                let left = r.left + r.width/2 - pw/2;
                if (left < 8) left = 8;
                if (left + pw > window.innerWidth - 8) left = window.innerWidth - pw - 8;
                pop.style.left = left + 'px';
                pop.style.top  = (r.bottom + 8) + 'px';
                pop.style.width = pw + 'px';
            });
            btn.addEventListener('mouseleave', function() {
                hideTimer = setTimeout(() => { pop.style.display = 'none'; }, 100);
            });
        });
    })();
    </script>



    <script>
    // Sidebar live clock
    (function () {
        const dateEl = document.getElementById('sidebarDate');
        const timeEl = document.getElementById('sidebarTime');
        if (!dateEl || !timeEl) return;
        function tick() {
            const now = new Date();
            dateEl.textContent = now.toLocaleDateString('en-US', { weekday: 'short', month: 'short', day: 'numeric', year: 'numeric' });
            timeEl.textContent = now.toLocaleTimeString('en-US', { hour: '2-digit', minute: '2-digit', second: '2-digit', hour12: false });
        }
        tick();
        setInterval(tick, 1000);
    })();

    // Set scrollbar width CSS variable so fixed elements account for it
    (function() {
        const sw = window.innerWidth - document.documentElement.clientWidth;
        document.documentElement.style.setProperty('--scrollbar-w', sw + 'px');
        window.addEventListener('resize', function() {
            const sw2 = window.innerWidth - document.documentElement.clientWidth;
            document.documentElement.style.setProperty('--scrollbar-w', sw2 + 'px');
        });
    })();
    </script>
</body>
</html>

