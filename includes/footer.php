    <footer class="mt-5 py-4 text-center">
        <p style="margin:0;font-size:0.78rem;color:#94a3b8;">
            &copy; <?= date('Y') ?> SUCFRMS &mdash; SUC Faculty Reclassification Management System. All rights reserved.
        </p>
    </footer>

    <!-- Sign Out Confirmation Modal -->
    <div id="logout-modal"
         style="display:none;position:fixed;inset:0;background:rgba(0,0,0,0.45);z-index:100003;align-items:center;justify-content:center;">
        <div style="background:#fff;border-radius:12px;width:100%;max-width:440px;margin:1rem;box-shadow:0 8px 32px rgba(0,0,0,0.15);overflow:hidden;">

            <!-- Header -->
            <div style="background:#1e4d8c;padding:1.25rem 1.5rem;display:flex;align-items:center;gap:0.875rem;">
                <img src="assets/images/logo.jpg" alt="SUCFRMS Logo" style="width:40px;height:40px;object-fit:cover;border-radius:50%;border:2px solid #475569;flex-shrink:0;">
                <div>
                    <div style="color:#fff;font-weight:700;font-size:0.95rem;line-height:1.2;">SUCFRMS</div>
                    <div style="color:#bfdbfe;font-size:0.75rem;margin-top:2px;">SUC Faculty Reclassification Management System</div>
                </div>
            </div>

            <!-- Body -->
            <div style="padding:2rem 1.75rem 1.5rem;text-align:center;">
                <div style="width:68px;height:68px;border-radius:50%;background:#fef2f2;display:flex;align-items:center;justify-content:center;margin:0 auto 1.25rem;">
                    <i class="bi bi-box-arrow-right" style="font-size:1.75rem;color:#dc2626;"></i>
                </div>
                <h5 style="font-weight:700;color:#1e293b;margin-bottom:0.5rem;font-size:1.2rem;">Sign Out</h5>
                <p style="color:#64748b;font-size:0.9rem;margin:0;line-height:1.6;">
                    Are you sure you want to sign out of your account?
                </p>
            </div>

            <!-- Divider -->
            <div style="height:1px;background:#e2e8f0;margin:0 1.75rem;"></div>

            <!-- Buttons -->
            <div style="padding:1.25rem 1.75rem 1.75rem;display:flex;gap:0.75rem;">
                <button onclick="document.getElementById('logout-modal').style.display='none'"
                        style="flex:1;padding:0.7rem 1rem;border:1px solid #cbd5e1;border-radius:8px;background:#fff;color:#475569;font-weight:600;cursor:pointer;font-size:0.875rem;">
                    Cancel
                </button>
                <a href="pages/logout.php"
                   style="flex:1;padding:0.7rem 1rem;border:none;border-radius:8px;background:#dc2626;color:#fff;font-weight:600;cursor:pointer;font-size:0.875rem;text-align:center;text-decoration:none;display:flex;align-items:center;justify-content:center;gap:0.4rem;">
                    <i class="bi bi-box-arrow-right"></i> Sign Out
                </a>
            </div>

        </div>
    </div>
    <script src="assets/js/app.js"></script>
    <script src="assets/vendor/chartjs/chart.umd.min.js"></script>
    <script src="assets/js/analytics.js"></script>
    <?php renderConfirmModal(); ?>

    <!-- ── Help FAB (floating bottom-right) ── -->
    <?php if (isset($_SESSION['user_id'])): ?>
    <div style="position:fixed;bottom:1.5rem;right:1.5rem;z-index:100001;">

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
            $is_faculty  = in_array($role, ['faculty','checker_faculty']);
            $is_checker  = in_array($role, ['checker','checker_faculty','talisay_checker']);
            $is_admin    = $role === 'admin';

            // ── Section label helper ─────────────────────────────
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
            <?php echo $item('index.php?page=whats_new', 'bi-stars', "What's New"); ?>

            <?php if ($is_faculty || $is_checker): ?>
            <!-- Role-specific -->
            <div style="height:1px;background:#f3f4f6;margin:0.25rem 0;"></div>
            <?php if ($is_faculty): ?>
            <?php echo $sectionLabel('Faculty'); ?>
            <?php echo $item('index.php?page=pre_evaluation', 'bi-calculator', 'Pre-Evaluation Tool'); ?>
            <?php echo $item('index.php?page=apply&step=2', 'bi-pencil-square', 'My KRA Entries'); ?>
            <?php endif; ?>
            <?php if ($is_checker): ?>
            <?php echo $sectionLabel('Checker'); ?>
            <?php echo $item('index.php?page=review_queue', 'bi-inbox', 'Review Queue'); ?>
            <?php echo $item('index.php?page=all_applications', 'bi-files', 'All Applications'); ?>
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

    <!-- ── Help Tooltip System ── -->
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
    // Mobile sidebar toggle
    function toggleSidebar() {
        const col  = document.getElementById('sidebarCol');
        const btn  = document.getElementById('sidebarToggleBtn');
        const open = col.classList.toggle('sidebar-open');
        btn.innerHTML = open
            ? '<i class="bi bi-x-lg me-2"></i>Close Menu'
            : '<i class="bi bi-layout-sidebar me-2"></i>Menu';
    }
    // Close sidebar when a nav link is clicked on mobile
    document.addEventListener('click', function(e) {
        const link = e.target.closest('#sidebarCard .sidebar-link');
        if (link && window.innerWidth < 768) {
            const col = document.getElementById('sidebarCol');
            col.classList.remove('sidebar-open');
            const btn = document.getElementById('sidebarToggleBtn');
            if (btn) btn.innerHTML = '<i class="bi bi-layout-sidebar me-2"></i>Menu';
        }
    });
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
    </script>
</body>
</html>

