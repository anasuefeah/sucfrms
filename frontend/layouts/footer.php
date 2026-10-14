    <footer class="px-4 pt-4 pb-8 text-center text-xs text-slate-400 print:hidden sm:px-6 md:pl-64 lg:px-8 lg:pl-72">
        &copy; <?= date('Y') ?> SUCFRMS · SUC Faculty Reclassification Management System. All rights reserved.
    </footer>

    <!-- Sign Out Confirmation Modal -->
    <div id="logout-modal" style="display:none;"
         class="fixed inset-0 z-[100003] items-center justify-center bg-slate-900/50 p-4 backdrop-blur-[2px]">
        <div class="w-full max-w-sm overflow-hidden rounded-2xl bg-white text-center shadow-pop animate-fade-up">
            <div class="px-6 pt-7 pb-6">
                <span class="mx-auto mb-4 flex size-14 items-center justify-center rounded-2xl bg-rose-50 text-2xl text-rose-600 ring-1 ring-inset ring-rose-100">
                    <i class="bi bi-box-arrow-right"></i>
                </span>
                <h3 class="m-0 text-lg font-semibold text-slate-900">Sign out?</h3>
                <p class="mt-1.5 mb-0 text-sm text-slate-500">You'll need to sign in again to access your account.</p>
            </div>
            <div class="flex gap-2 border-t border-slate-100 bg-slate-50/60 px-6 py-4">
                <button onclick="document.getElementById('logout-modal').style.display='none'" class="btn btn-secondary flex-1">
                    Cancel
                </button>
                <a href="backend/auth/logout.php" class="btn btn-danger flex-1">
                    <i class="bi bi-box-arrow-right"></i>Sign out
                </a>
            </div>
        </div>
    </div>
    <script src="frontend/assets/js/app.js"></script>
    <script src="frontend/assets/vendor/chartjs/chart.umd.min.js"></script>
    <script src="frontend/assets/js/analytics.js"></script>
    <?php renderConfirmModal(); ?>

    <!-- -- Help FAB (floating bottom-right) -- -->
    <?php if (isset($_SESSION['user_id'])): ?>
    <div class="fixed right-6 bottom-6 z-[100001] print:hidden">

        <button id="helpBtn" title="Help" aria-label="Help"
                class="flex size-11 items-center justify-center rounded-full border-0 bg-brand-700 text-lg text-white shadow-brand transition hover:-translate-y-0.5 hover:bg-brand-800">
            <i class="bi bi-question-lg"></i>
        </button>

        <div id="helpDropdown" style="display:none;"
             class="fixed right-6 bottom-20 z-[100002] w-64 overflow-hidden rounded-2xl border border-slate-200/80 bg-white p-1.5 shadow-pop animate-fade-up">

            <?php
            $role      = $_SESSION['role'] ?? '';
            $is_faculty  = $role === 'faculty';
            $is_checker  = ($role === 'evaluator');
            $is_admin    = $role === 'admin';

            // -- Section label helper -----------------------------
            $sectionLabel = function(string $label) {
                return '<div class="dropdown-header">' . htmlspecialchars($label) . '</div>';
            };

            $item = function(string $href, string $icon, string $label, bool $isBtn = false, string $onClick = '') {
                $click = $onClick ? ' onclick="' . $onClick . '"' : '';
                $tag   = $isBtn ? 'a href="#"' : 'a href="' . $href . '"';
                return '<' . $tag . $click . ' class="dropdown-item">'
                     . '<i class="bi ' . $icon . ' w-4 text-brand-600"></i>' . htmlspecialchars($label) . '</a>';
            };
            ?>

            <!-- General -->
            <?php echo $sectionLabel('General'); ?>
            <?php echo $item('index.php?page=help', 'bi-book', 'Help & Guide'); ?>

            <?php if ($is_faculty || $is_checker): ?>
            <!-- Role-specific -->
            <div class="dropdown-divider"></div>
            <?php if ($is_faculty): ?>
            <?php echo $sectionLabel('Faculty'); ?>
            <?php echo $item('frontend/pages/pre_evaluation.php', 'bi-calculator', 'Self-Assessment'); ?>
            <?php echo $item('index.php?page=apply', 'bi-pencil-square', 'My KRA Entries'); ?>
            <?php endif; ?>
            <?php if ($is_checker): ?>
            <?php echo $sectionLabel('Evaluator'); ?>
            <?php echo $item('index.php?page=review_queue', 'bi-inbox', 'Review Queue'); ?>
            <?php echo $item('index.php?page=appeals', 'bi-chat-square-text', 'Appeals'); ?>
            <?php endif; ?>
            <?php endif; ?>

            <?php if ($is_admin): ?>
            <div class="dropdown-divider"></div>
            <?php echo $sectionLabel('Admin'); ?>
            <?php echo $item('index.php?page=cycles', 'bi-calendar-range', 'Manage Cycles'); ?>
            <?php echo $item('index.php?page=manage_users', 'bi-people', 'Manage Users'); ?>
            <?php echo $item('index.php?page=analytics', 'bi-bar-chart-line', 'Analytics'); ?>
            <?php endif; ?>

            <!-- Feedback -->
            <div class="dropdown-divider"></div>
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
            dateEl.textContent = now.toLocaleDateString('en-US', { weekday: 'short', month: 'short', day: 'numeric' });
            timeEl.textContent = now.toLocaleTimeString('en-US', { hour: '2-digit', minute: '2-digit', second: '2-digit', hour12: false });
        }
        tick();
        setInterval(tick, 1000);
    })();
    </script>
</body>
</html>
