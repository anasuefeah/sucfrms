<!-- -- Sidebar -- -->
<aside id="sidebarCol"
       class="fixed inset-y-0 left-0 z-[1040] flex w-64 -translate-x-full flex-col border-r border-slate-200/80 bg-white transition-transform duration-300 ease-out print:hidden md:translate-x-0 [&.mobile-open]:translate-x-0 [&.mobile-open]:shadow-pop">
    <!-- Brand -->
    <a href="index.php" class="flex h-16 shrink-0 items-center gap-3 border-b border-slate-100 px-5 no-underline">
        <img src="frontend/assets/images/logo.jpg?v=2" alt="SUCFRMS" class="size-9 rounded-full object-cover ring-1 ring-slate-200">
        <span class="min-w-0 leading-tight">
            <span class="block text-[0.9375rem] font-bold tracking-tight text-slate-900">SUCFRMS</span>
            <span class="block truncate text-[0.6875rem] font-medium text-slate-400">Faculty Reclassification</span>
        </span>
    </a>

    <nav class="sidebar-nav-scroll flex-1 overflow-y-auto px-3 pt-2 pb-4">
        <?php if (!($role === 'faculty' && $faculty_profile_incomplete)): ?>
        <a href="?page=dashboard" class="sidebar-link <?= $page==='dashboard'?'active':'' ?>">
            <i class="bi bi-grid-1x2"></i>Dashboard
        </a>
        <?php endif; ?>

        <?php if ($role === 'faculty'): ?>
        <?php if ($faculty_profile_incomplete): ?>
        <div class="sidebar-section-label">Setup</div>
        <a href="?page=profile_entry" class="sidebar-link <?= $page==='profile_entry'?'active':'' ?>">
            <i class="bi bi-person-lines-fill"></i>Profile Entry
        </a>
        <div class="mx-1 mt-3 flex gap-2 rounded-xl border border-amber-200 bg-amber-50 px-3 py-2.5 text-xs leading-relaxed text-amber-800">
            <i class="bi bi-lock-fill mt-px"></i><span>Complete your profile to unlock all features.</span>
        </div>
        <?php else: ?>
        <div class="sidebar-section-label">My Application</div>
        <a href="frontend/pages/portal.php" class="sidebar-link">
            <i class="bi bi-compass"></i>Portal
        </a>
        <a href="?page=my_application" class="sidebar-link <?= $page==='my_application'?'active':'' ?>">
            <i class="bi bi-file-earmark-person"></i>Application Status
        </a>
        <a href="?page=apply" class="sidebar-link <?= $page==='apply'?'active':'' ?>">
            <i class="bi bi-ui-checks-grid"></i>Apply / KRA Entry
        </a>
        <a href="?page=appeals" class="sidebar-link <?= $page==='appeals'?'active':'' ?>">
            <i class="bi bi-chat-square-text"></i>Appeals
        </a>
        <div class="sidebar-section-label">Account</div>
        <a href="?page=my_audit" class="sidebar-link <?= $page==='my_audit'?'active':'' ?>">
            <i class="bi bi-clock-history"></i>My Activity Log
        </a>
        <a href="?page=profile" class="sidebar-link <?= $page==='profile'?'active':'' ?>">
            <i class="bi bi-person"></i>Profile
        </a>
        <?php endif; ?>

        <?php elseif ($role === 'evaluator'): ?>
        <div class="sidebar-section-label">Review</div>
        <a href="?page=review_queue" class="sidebar-link <?= $page==='review_queue'?'active':'' ?>">
            <i class="bi bi-inbox"></i>Review Queue
        </a>
        <a href="?page=appeals" class="sidebar-link <?= $page==='appeals'?'active':'' ?>">
            <i class="bi bi-chat-square-text"></i>Appeals
        </a>
        <div class="sidebar-section-label">Account</div>
        <a href="?page=checker_audit" class="sidebar-link <?= $page==='checker_audit'?'active':'' ?>">
            <i class="bi bi-clock-history"></i>My Activity Log
        </a>
        <a href="?page=profile" class="sidebar-link <?= $page==='profile'?'active':'' ?>">
            <i class="bi bi-person"></i>Profile
        </a>

        <?php elseif ($role === 'admin'): ?>
        <div class="sidebar-section-label">Applications</div>
        <a href="?page=all_applications" class="sidebar-link <?= $page==='all_applications'?'active':'' ?>">
            <i class="bi bi-ui-checks-grid"></i>All Applications
        </a>
        <a href="?page=appeals" class="sidebar-link <?= $page==='appeals'?'active':'' ?>">
            <i class="bi bi-chat-square-text"></i>Appeals
        </a>
        <div class="sidebar-section-label">Administration</div>
        <a href="?page=analytics" class="sidebar-link <?= $page==='analytics'?'active':'' ?>">
            <i class="bi bi-graph-up"></i>Analytics
        </a>
        <?php
        $setup_pages = ['manage_users','manage_campuses','cycles','config'];
        $setup_active = in_array($page, $setup_pages);
        ?>
        <div class="sidebar-group <?= $setup_active ? 'open' : '' ?>">
            <button type="button" class="sidebar-link sidebar-group-toggle"
                    onclick="this.closest('.sidebar-group').classList.toggle('open')">
                <i class="bi bi-sliders"></i>System Setup
                <i class="bi bi-chevron-down sidebar-chevron"></i>
            </button>
            <div class="sidebar-submenu">
                <a href="?page=cycles" class="sidebar-link sidebar-sublink <?= in_array($page,['cycles','config'])?'active':'' ?>">
                    <i class="bi bi-calendar-range"></i>Cycles &amp; Criteria
                </a>
                <a href="?page=manage_users" class="sidebar-link sidebar-sublink <?= $page==='manage_users'?'active':'' ?>">
                    <i class="bi bi-people"></i>Manage Users
                </a>
                <a href="?page=manage_campuses" class="sidebar-link sidebar-sublink <?= $page==='manage_campuses'?'active':'' ?>">
                    <i class="bi bi-geo-alt"></i>Manage Campuses
                </a>
            </div>
        </div>
        <a href="?page=audit" class="sidebar-link <?= $page==='audit'?'active':'' ?>">
            <i class="bi bi-shield-check"></i>Audit Logs
        </a>
        <a href="?page=feedback" class="sidebar-link <?= $page==='feedback'?'active':'' ?>">
            <i class="bi bi-chat-square-heart"></i>Feedback
            <?php
            try {
                $fb_new = $pdo->query("SELECT COUNT(*) FROM feedback_submissions WHERE status='new'")->fetchColumn();
                if ($fb_new > 0) echo '<span class="badge bg-danger">'.$fb_new.'</span>';
            } catch(\Exception $e) {}
            ?>
        </a>
        <div class="sidebar-section-label">Account</div>
        <a href="?page=profile" class="sidebar-link <?= $page==='profile'?'active':'' ?>">
            <i class="bi bi-person"></i>Profile
        </a>
        <?php endif; ?>
    </nav>

    <!-- User card -->
    <div class="shrink-0 border-t border-slate-100 p-3">
        <div class="flex items-center gap-3 rounded-xl bg-slate-50 p-2.5">
            <?= userAvatar('size-9', 'text-xs') ?>
            <div class="min-w-0 flex-1 leading-tight">
                <div class="truncate text-sm font-semibold text-slate-900"><?= htmlspecialchars($_SESSION['full_name'] ?? '') ?></div>
                <div class="text-xs text-slate-500"><?= $role === 'admin' ? 'Administrator' : ($role === 'evaluator' ? 'Evaluator' : 'Faculty') ?></div>
            </div>
            <button type="button" title="Sign out" aria-label="Sign out"
                    class="inline-flex size-8 cursor-pointer items-center justify-center rounded-lg border-0 bg-transparent text-slate-400 transition-colors hover:bg-rose-50 hover:text-rose-600"
                    onclick="document.getElementById('logout-modal').style.display='flex'">
                <i class="bi bi-box-arrow-right"></i>
            </button>
        </div>
        <div class="mt-2.5 flex items-center justify-between px-1 text-[0.6875rem] text-slate-400">
            <span class="inline-flex items-center gap-1.5">
                <span class="size-1.5 animate-pulse rounded-full bg-emerald-500"></span>
                <span id="sidebarDate">&nbsp;</span>
            </span>
            <span id="sidebarTime" class="tabular-nums">&nbsp;</span>
        </div>
    </div>
</aside>
