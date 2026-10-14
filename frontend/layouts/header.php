<?php
$_page_titles = [
    'dashboard' => 'Dashboard', 'my_application' => 'Application Status', 'apply' => 'Apply / KRA Entry',
    'score_comparison' => 'Score Comparison', 'appeals' => 'Appeals', 'my_audit' => 'My Activity Log',
    'checker_audit' => 'My Activity Log', 'audit' => 'Audit Logs', 'profile' => 'Profile',
    'profile_entry' => 'Profile Entry', 'review_queue' => 'Review Queue', 'review_application' => 'Review Application',
    'view_application' => 'Application Details', 'all_applications' => 'All Applications', 'analytics' => 'Analytics',
    'cycles' => 'Cycles & Criteria', 'config' => 'Cycles & Criteria', 'manage_users' => 'Manage Users',
    'manage_campuses' => 'Manage Campuses', 'feedback' => 'Feedback', 'help' => 'Help & Guide',
];
$_page_title = $_page_titles[$page ?? 'dashboard'] ?? 'Dashboard';
$_role_label = ['admin' => 'Administrator', 'evaluator' => 'Evaluator'][$_SESSION['role'] ?? ''] ?? 'Faculty';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <meta name="mobile-web-app-capable" content="yes">
    <meta name="theme-color" content="#1a3a6b">
    <title><?= htmlspecialchars($_page_title) ?> · SUCFRMS</title>
    <link rel="preload" href="frontend/assets/fonts/InterVariable.woff2" as="font" type="font/woff2" crossorigin>
    <link href="frontend/assets/vendor/bootstrap-icons/bootstrap-icons.min.css" rel="stylesheet">
    <link rel="stylesheet" href="frontend/assets/css/app.css?v=<?= filemtime(__DIR__ . '/../assets/css/app.css') ?>">
    <link rel="stylesheet" href="frontend/assets/css/legacy.css?v=<?= filemtime(__DIR__ . '/../assets/css/legacy.css') ?>">
    <style>
    @keyframes bellRing { 0%,100%{transform:rotate(0)} 10%{transform:rotate(14deg)} 20%{transform:rotate(-12deg)} 30%{transform:rotate(10deg)} 40%{transform:rotate(-8deg)} 50%{transform:rotate(5deg)} 60%{transform:rotate(0)} }
    @keyframes badgePop { from{transform:scale(0)} to{transform:scale(1)} }
    @keyframes sucfrmsToastIn { from{opacity:0;transform:translateY(1rem)} to{opacity:1;transform:translateY(0)} }
    @keyframes sucfrmsToastOut { from{opacity:1;transform:translateY(0)} to{opacity:0;transform:translateY(1rem)} }
    </style>
</head>
<body class="bg-canvas text-ink antialiased">

<!-- Page loader: shown immediately, hidden once the DOM is ready -->
<div id="page-loader" class="fixed inset-0 z-[99999] flex flex-col items-center justify-center gap-5 bg-canvas transition-opacity duration-300 [&.fade-out]:pointer-events-none [&.fade-out]:opacity-0">
    <div class="relative">
        <img src="frontend/assets/images/logo.jpg" alt="SUCFRMS" class="size-14 rounded-full object-cover shadow-card ring-4 ring-white">
        <span class="absolute -inset-2 animate-spin rounded-full border-2 border-brand-100 border-t-brand-700"></span>
    </div>
    <div class="text-xs font-medium tracking-wide text-slate-400">Loading&hellip;</div>
</div>
<script>
document.addEventListener('DOMContentLoaded', function() {
    const loader = document.getElementById('page-loader');
    if (loader) {
        loader.classList.add('fade-out');
        setTimeout(function() { loader.style.display = 'none'; }, 320);
    }
});
</script>

<script>
// If this page is restored from browser cache after logout, redirect to login
window.addEventListener('pageshow', function(e) {
    if (e.persisted || (window.performance && window.performance.getEntriesByType('navigation')[0]?.type === 'back_forward')) {
        fetch('index.php?ping=1', { method: 'HEAD', cache: 'no-store' })
            .then(r => {
                if (r.status === 401) {
                    window.location.replace('frontend/pages/login.php');
                }
            })
            .catch(() => window.location.replace('frontend/pages/login.php'));
    }
});
</script>

<!-- -- Top bar -- -->
<header id="topbar" class="fixed inset-x-0 top-0 z-[1030] h-16 border-b border-slate-200/80 bg-white/85 backdrop-blur-md print:hidden md:left-64">
    <div class="flex h-full items-center gap-3 px-4 sm:px-6 lg:px-8">
        <button class="topbar-btn md:hidden" id="mobileMenuBtn" onclick="toggleMobileSidebar()" aria-label="Open menu">
            <i class="bi bi-list text-2xl"></i>
        </button>
        <div class="min-w-0 flex-1">
            <div class="truncate text-[0.6875rem] font-semibold tracking-wider text-slate-400 uppercase"><?= $_role_label ?></div>
            <div class="truncate text-base font-semibold leading-tight text-slate-900"><?= htmlspecialchars($_page_title) ?></div>
        </div>

        <?php if (isset($_SESSION['user_id']) && isset($pdo)): ?>
        <?php $_notif_count = getUnreadNotifCount($pdo, (int)$_SESSION['user_id']); ?>
        <!-- Notification bell -->
        <div class="relative shrink-0">
            <button id="notifBellBtn" onclick="toggleNotifDropdown()" class="topbar-btn" title="Notifications" aria-label="Notifications">
                <i class="bi bi-bell" id="notifBellIcon"
                   style="<?= $_notif_count > 0 ? 'animation:bellRing 1.2s ease infinite;' : '' ?>"></i>
                <?php if ($_notif_count > 0): ?>
                <span id="notifBadge"
                      class="absolute top-1 right-1 flex h-[18px] min-w-[18px] items-center justify-center rounded-full bg-rose-500 px-1 text-[0.6rem] leading-none font-bold text-white ring-2 ring-white"
                      style="animation:badgePop 0.3s cubic-bezier(0.34,1.56,0.64,1);">
                    <?= $_notif_count > 9 ? '9+' : $_notif_count ?>
                </span>
                <?php else: ?>
                <span id="notifBadge"
                      class="absolute top-1 right-1 h-[18px] min-w-[18px] items-center justify-center rounded-full bg-rose-500 px-1 text-[0.6rem] leading-none font-bold text-white ring-2 ring-white"
                      style="display:none;"></span>
                <?php endif; ?>
            </button>

            <!-- Notification panel: fixed position so it escapes any overflow clipping -->
            <div id="notifDropdown" style="display:none;"
                 class="fixed top-[4.5rem] right-3 z-[99999] w-[380px] max-w-[calc(100vw-24px)] overflow-hidden rounded-2xl border border-slate-200/80 bg-white shadow-pop animate-fade-up">
                <div class="flex items-center justify-between border-b border-slate-100 px-4 py-3">
                    <div class="flex items-center gap-2">
                        <span class="text-sm font-semibold text-slate-900">Notifications</span>
                        <span id="notifHeaderCount" class="rounded-full bg-brand-50 px-2 py-0.5 text-[0.6875rem] font-semibold text-brand-700">
                            <?= $_notif_count > 0 ? $_notif_count . ' unread' : 'all read' ?>
                        </span>
                    </div>
                    <button onclick="markAllRead()" class="btn btn-ghost btn-xs">
                        <i class="bi bi-check2-all"></i>Mark all read
                    </button>
                </div>
                <div id="notifList" class="max-h-[380px] overflow-y-auto">
                    <div class="py-10 text-center text-sm text-slate-400">
                        <i class="bi bi-bell-slash mb-2 block text-2xl"></i>Loading...
                    </div>
                </div>
                <div class="border-t border-slate-100 bg-slate-50/70 px-4 py-2 text-center text-[0.6875rem] text-slate-400">
                    <i class="bi bi-arrow-clockwise me-1"></i>Updates every 30 seconds
                </div>
            </div>
        </div>
        <?php endif; ?>

        <!-- User menu -->
        <div class="dropdown shrink-0">
            <button type="button" data-bs-toggle="dropdown" aria-label="Account menu"
                    class="flex items-center gap-2.5 rounded-xl border-0 bg-transparent py-1 pr-2 pl-1 transition-colors hover:bg-slate-100">
                <?= userAvatar('size-8', 'text-xs') ?>
                <span class="hidden max-w-40 truncate text-sm font-medium text-slate-700 lg:block"><?= htmlspecialchars($_SESSION['first_name'] ?? ($_SESSION['full_name'] ?? '')) ?></span>
                <i class="bi bi-chevron-down hidden text-[0.65rem] text-slate-400 lg:block"></i>
            </button>
            <div class="dropdown-menu right-0 w-60">
                <div class="px-3 pt-2 pb-2.5">
                    <div class="truncate text-sm font-semibold text-slate-900"><?= htmlspecialchars($_SESSION['full_name'] ?? '') ?></div>
                    <div class="truncate text-xs text-slate-500"><?= htmlspecialchars($_SESSION['email'] ?? $_role_label) ?></div>
                </div>
                <div class="dropdown-divider"></div>
                <a class="dropdown-item" href="index.php?page=profile"><i class="bi bi-person text-slate-400"></i>Profile</a>
                <a class="dropdown-item" href="index.php?page=help"><i class="bi bi-book text-slate-400"></i>Help &amp; Guide</a>
                <a class="dropdown-item" href="#" onclick="openFeedbackModal(); return false;"><i class="bi bi-chat-dots text-slate-400"></i>Send Feedback</a>
                <div class="dropdown-divider"></div>
                <a class="dropdown-item text-rose-600 hover:bg-rose-50 hover:text-rose-700" href="#"
                   onclick="document.getElementById('logout-modal').style.display='flex'; return false;">
                    <i class="bi bi-box-arrow-right"></i>Sign Out
                </a>
            </div>
        </div>
    </div>
</header>

<!-- Send Feedback modal -->
<div id="feedbackModal" style="display:none;"
     class="fixed inset-0 z-[100003] items-center justify-center bg-slate-900/50 p-4 backdrop-blur-[2px]">
    <div class="w-full max-w-md overflow-hidden rounded-2xl bg-white shadow-pop animate-fade-up">
        <div class="flex items-start justify-between gap-4 px-6 pt-6">
            <div class="flex items-center gap-3">
                <span class="icon-chip"><i class="bi bi-chat-square-text"></i></span>
                <div>
                    <h3 class="m-0 text-base font-semibold text-slate-900">Send feedback</h3>
                    <p class="m-0 text-sm text-slate-500">Report an issue or suggest an improvement.</p>
                </div>
            </div>
            <button type="button" class="btn-close" aria-label="Close"
                    onclick="document.getElementById('feedbackModal').style.display='none'"></button>
        </div>
        <form id="feedbackForm" class="space-y-4 px-6 pt-5 pb-6">
            <div>
                <label for="fbSubject" class="form-label">Subject <span class="text-rose-500">*</span></label>
                <input type="text" id="fbSubject" maxlength="255" required class="form-control"
                       placeholder="e.g. Issue with KRA upload">
            </div>
            <div>
                <label for="fbMessage" class="form-label">Message <span class="text-rose-500">*</span></label>
                <textarea id="fbMessage" rows="4" required class="form-control resize-y"
                          placeholder="Describe your feedback, suggestion, or issue..."></textarea>
            </div>
            <div>
                <label class="form-label">Rating <span class="font-normal text-slate-400">(optional)</span></label>
                <div id="starRating" class="flex gap-1.5">
                    <?php for ($s = 1; $s <= 5; $s++): ?>
                    <i class="bi bi-star" data-star="<?= $s ?>"
                       style="font-size:1.4rem;cursor:pointer;color:#cbd5e1;transition:color 0.15s;"
                       onclick="setRating(<?= $s ?>)"
                       onmouseover="hoverRating(<?= $s ?>)"
                       onmouseout="resetRatingHover()"></i>
                    <?php endfor; ?>
                </div>
                <input type="hidden" id="fbRating" value="">
            </div>
            <div id="fbAlert" style="display:none;"></div>
            <div class="flex justify-end gap-2 pt-1">
                <button type="button" class="btn btn-secondary"
                        onclick="document.getElementById('feedbackModal').style.display='none'">Cancel</button>
                <button type="submit" class="btn btn-primary"><i class="bi bi-send"></i>Submit</button>
            </div>
        </form>
    </div>
</div>

<!-- Mobile sidebar backdrop -->
<div id="mobileSidebarBackdrop"
     onclick="toggleMobileSidebar()"
     class="fixed inset-0 z-[1039] bg-slate-900/40 backdrop-blur-[2px]"
     style="display:none;"></div>

<script>
function toggleMobileSidebar() {
    const sidebar = document.getElementById('sidebarCol');
    const backdrop = document.getElementById('mobileSidebarBackdrop');
    const btn = document.getElementById('mobileMenuBtn');
    if (!sidebar) return;
    const isOpen = sidebar.classList.contains('mobile-open');
    if (isOpen) {
        sidebar.classList.remove('mobile-open');
        backdrop.style.display = 'none';
        document.body.style.overflow = '';
    } else {
        sidebar.classList.add('mobile-open');
        backdrop.style.display = 'block';
        document.body.style.overflow = 'hidden';
    }
}
// Close sidebar when a nav link is clicked on mobile
document.addEventListener('click', function(e) {
    const link = e.target.closest('.sidebar-link');
    if (link && window.innerWidth < 768) {
        const sidebar = document.getElementById('sidebarCol');
        const backdrop = document.getElementById('mobileSidebarBackdrop');
        if (sidebar) sidebar.classList.remove('mobile-open');
        if (backdrop) backdrop.style.display = 'none';
        document.body.style.overflow = '';
    }
});
</script>

<script>
// -- Notification bell ----------------------------------------
const NOTIF_ICONS = {
    new_submission:       { icon: 'bi-file-earmark-plus', tone: 'brand' },
    under_review:         { icon: 'bi-hourglass-split',   tone: 'violet' },
    talisay_review:       { icon: 'bi-building-up',       tone: 'violet' },
    new_talisay_submission:{ icon: 'bi-building-exclamation', tone: 'brand' },
    score_adjusted:       { icon: 'bi-pencil-square',     tone: 'sky' },
    needs_revision:       { icon: 'bi-exclamation-triangle-fill', tone: 'amber' },
    revision_resubmitted: { icon: 'bi-arrow-repeat',      tone: 'brand' },
    appeal_opened:        { icon: 'bi-chat-square-text',  tone: 'sky' },
    appeal_message:       { icon: 'bi-chat-dots',         tone: 'sky' },
    appeal_resolved:      { icon: 'bi-check2-circle',     tone: 'emerald' },
    approved:             { icon: 'bi-check-circle-fill', tone: 'emerald' },
    rejected:             { icon: 'bi-x-circle-fill',     tone: 'rose' },
};
const NOTIF_TONES = {
    brand:   'icon-chip',
    violet:  'icon-chip icon-chip-violet',
    sky:     'icon-chip icon-chip-sky',
    amber:   'icon-chip icon-chip-amber',
    emerald: 'icon-chip icon-chip-emerald',
    rose:    'icon-chip icon-chip-rose',
    slate:   'icon-chip icon-chip-slate',
};

function notifIconFor(type) {
    const d = NOTIF_ICONS[type] || { icon: 'bi-bell', tone: 'slate' };
    return `<span class="${NOTIF_TONES[d.tone]} size-9 text-sm"><i class="bi ${d.icon}"></i></span>`;
}

function timeAgo(ts) {
    // ts is now a Unix timestamp in seconds (from UNIX_TIMESTAMP() in MySQL)
    // Multiply by 1000 to get milliseconds. No timezone issues.
    const ms   = Number(ts) * 1000;
    const diff = Math.floor((Date.now() - ms) / 1000);
    if (diff < 5)    return 'just now';
    if (diff < 60)   return diff + 's ago';
    if (diff < 3600) return Math.floor(diff/60) + 'm ago';
    if (diff < 86400)return Math.floor(diff/3600) + 'h ago';
    return Math.floor(diff/86400) + 'd ago';
}

function renderNotifs(notifs) {
    const list = document.getElementById('notifList');
    if (!notifs.length) {
        list.innerHTML = `<div class="empty-state py-10">
            <span class="empty-state-icon size-12 text-xl"><i class="bi bi-bell-slash"></i></span>
            <p class="empty-state-title text-sm">You're all caught up</p>
            <p class="empty-state-text text-xs">New notifications will appear here.</p></div>`;
        return;
    }
    list.innerHTML = notifs.map(n => {
        const unread = n.is_read == 0;
        const role   = '<?= $_SESSION['role'] ?? 'faculty' ?>';
        const id     = n.application_id;

        // Map notification type -> correct page + anchor
        function notifLink(type, appId, submissionId, kraCategory) {
            if (!appId) return '#';
            const isFaculty   = role === 'faculty';
            const isEvaluator = role === 'evaluator';

            switch (type) {
                // Faculty: score was adjusted -> go to comparison area
                case 'score_adjusted':
                    return `index.php?page=my_application&id=${appId}#score-comparison`;

                // Faculty: entry flagged for revision -> deep-link straight to that KRA entry
                case 'needs_revision':
                    if (isFaculty && submissionId) {
                        const slugMap = { 'Research': 'research', 'Extension': 'extension', 'Professional Development': 'profdev' };
                        const slug = slugMap[kraCategory] || 'instruction';
                        return `index.php?page=apply&tab=${slug}&edit_sid=${submissionId}`;
                    }
                    return isFaculty
                        ? `index.php?page=my_application&id=${appId}#app-status`
                        : `index.php?page=review_application&id=${appId}`;

                // Faculty: application under review / returned / talisay
                case 'under_review':
                case 'rejected':
                case 'approved':
                case 'talisay_review':
                    return isFaculty
                        ? `index.php?page=my_application&id=${appId}#app-status`
                        : `index.php?page=review_application&id=${appId}`;

                // Checkers: new submission to review
                case 'new_submission':
                case 'revision_resubmitted':
                    return `index.php?page=review_application&id=${appId}#kra-verification`;

                // ITC: forwarded from the Subcommittee
                case 'new_talisay_submission':
                    return `index.php?page=review_application&id=${appId}`;

                case 'appeal_opened':
                case 'appeal_message':
                case 'appeal_resolved':
                    return `index.php?page=appeals`;

                default:
                    return isEvaluator
                        ? `index.php?page=review_application&id=${appId}`
                        : `index.php?page=my_application&id=${appId}`;
            }
        }

        const link = notifLink(n.type, id, n.submission_id, n.kra_category);
        return `<div onclick="markOneRead(${n.notif_id}, '${link}')"
                     class="flex cursor-pointer items-start gap-3 border-b border-slate-100 px-4 py-3 transition-colors last:border-b-0 hover:bg-slate-50 ${unread ? 'bg-brand-50/50' : 'bg-white'}">
                    ${notifIconFor(n.type)}
                    <div class="min-w-0 flex-1">
                        <div class="text-[0.8125rem] leading-snug break-words text-slate-800 ${unread ? 'font-semibold' : ''}">${escHtml(n.message)}</div>
                        <div class="mt-1 text-[0.6875rem] text-slate-400">${timeAgo(n.created_at)}</div>
                    </div>
                    ${unread ? `<span class="mt-1.5 size-2 shrink-0 rounded-full bg-brand-600"></span>` : ''}
                </div>`;
    }).join('');
}

function escHtml(s) {
    return s.replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

function loadNotifs() {
    const list = document.getElementById('notifList');
    fetch('index.php?notif_action=get_notifs')
        .then(r => {
            if (!r.ok) throw new Error('HTTP ' + r.status);
            return r.json();
        })
        .then(d => {
            if (d.ok) renderNotifs(d.notifs);
            else showNotifError('Could not load notifications.');
        })
        .catch(() => showNotifError('Could not load notifications.'));
}

function showNotifError(msg) {
    const list = document.getElementById('notifList');
    if (!list) return;
    list.innerHTML = `<div class="empty-state py-10">
        <span class="empty-state-icon size-12 text-xl"><i class="bi bi-wifi-off"></i></span>
        <p class="empty-state-text text-xs">${msg}</p>
        <button onclick="loadNotifs()" class="btn btn-secondary btn-xs mt-1">Retry</button>
    </div>`;
}

function toggleNotifDropdown() {
    const dd = document.getElementById('notifDropdown');
    const open = dd.style.display !== 'none';
    dd.style.display = open ? 'none' : 'block';
    if (!open) loadNotifs();
}

function markOneRead(id, link) {
    const fd = new FormData();
    fd.append('notif_id', id);
    fetch('index.php?notif_action=mark_read', { method: 'POST', body: fd })
        .then(() => {
            updateBadge(-1);
            if (link && link !== '#') window.location.href = link;
            else loadNotifs();
        });
}

function markAllRead() {
    fetch('index.php?notif_action=mark_read', { method: 'POST', body: new FormData() })
        .then(() => {
            updateBadge(0, true);
            loadNotifs();
        });
}

function updateBadge(delta, reset = false) {
    const badge  = document.getElementById('notifBadge');
    const bell   = document.getElementById('notifBellIcon');
    const hdr    = document.getElementById('notifHeaderCount');
    if (!badge) return;
    let cur = reset ? 0 : Math.max(0, (parseInt(badge.textContent) || 0) + delta);
    if (cur <= 0) {
        badge.style.display = 'none';
        if (bell) bell.style.animation = 'none';
        if (hdr)  hdr.textContent = 'all read';
    } else {
        badge.style.display = 'flex';
        badge.textContent = cur > 9 ? '9+' : cur;
        if (bell) bell.style.animation = 'bellRing 1.2s ease infinite';
        if (hdr)  hdr.textContent = cur + ' unread';
    }
}

// Close dropdown when clicking outside
document.addEventListener('click', function(e) {
    const dd  = document.getElementById('notifDropdown');
    const btn = document.getElementById('notifBellBtn');
    if (!dd) return;
    if (!dd.contains(e.target) && (!btn || !btn.contains(e.target))) {
        dd.style.display = 'none';
    }
});

// Auto-refresh badge every 30 seconds  -  detect new notifications
let _lastUnreadCount = <?= $_notif_count ?? 0 ?>;
setInterval(() => {
    fetch('index.php?notif_action=get_notifs')
        .then(r => r.ok ? r.json() : Promise.reject('HTTP ' + r.status))
        .then(d => {
            if (!d.ok) return;
            const unread = d.notifs.filter(n => n.is_read == 0).length;
            const badge  = document.getElementById('notifBadge');
            const bell   = document.getElementById('notifBellIcon');
            const hdr    = document.getElementById('notifHeaderCount');
            if (!badge) return;

            // New notification arrived  -  briefly highlight the bell
            if (unread > _lastUnreadCount) {
                if (bell) {
                    bell.style.color = '#1a3a6b';
                    setTimeout(() => { if (bell) bell.style.color = ''; }, 1500);
                }
            }
            _lastUnreadCount = unread;

            if (unread > 0) {
                badge.style.display = 'flex';
                badge.textContent = unread > 9 ? '9+' : unread;
                if (bell) bell.style.animation = 'bellRing 1.2s ease infinite';
                if (hdr)  hdr.textContent = unread + ' unread';
            } else {
                badge.style.display = 'none';
                if (bell) bell.style.animation = 'none';
                if (hdr)  hdr.textContent = 'all read';
            }

            // If dropdown is open, refresh the list too
            const dd = document.getElementById('notifDropdown');
            if (dd && dd.style.display !== 'none') renderNotifs(d.notifs);
        })
        .catch(() => {});
}, 30000);

// -- Real-time dashboard poll  -  updates KPI numbers every 15s --
(function() {
    const POLL_URL = 'backend/api/dashboard_poll.php';
    let _lastCounts = {};
    let _lastFacultyStatus = null;

    function applyPollData(counts) {
        // Update all [data-poll-key] elements on the page
        Object.entries(counts).forEach(([key, val]) => {
            if (key === 'faculty_status' || key === 'faculty_score') return;
            document.querySelectorAll('[data-poll-key="' + key + '"]').forEach(el => {
                if (el.textContent.trim() !== String(val)) {
                    el.textContent = val;
                    // Flash animation to signal update
                    el.style.transition = 'color 0.3s';
                    el.style.color = '#16a34a';
                    setTimeout(() => { el.style.color = ''; }, 1200);
                }
            });
        });

        // Faculty: show toast if application status changed
        if (counts.faculty_status && counts.faculty_status !== _lastFacultyStatus && _lastFacultyStatus !== null) {
            const labels = {
                under_review:   'Your application is now under review.',
                needs_revision: 'An evaluator has flagged an entry for revision.',
                approved:       'Your evaluation is complete.',
                rejected:       'Your application has been returned for revision.',
                talisay_review: 'Your application is now in ITC review.',
            };
            const msg = labels[counts.faculty_status] || 'Your application status has changed.';
            showPollToast(msg, counts.faculty_status === 'approved' ? 'success' : 'info');
        }
        _lastFacultyStatus = counts.faculty_status ?? _lastFacultyStatus;

        // Checker: toast if new items arrived in queue
        if (_lastCounts.checker_pending !== undefined && counts.checker_pending > _lastCounts.checker_pending) {
            showPollToast('New application submitted  -  ' + counts.checker_pending + ' awaiting decision.', 'info');
        }

        _lastCounts = counts;
    }

    function showPollToast(msg, type) {
        const tone = type === 'success' ? 'text-emerald-600' : type === 'warn' ? 'text-amber-600' : 'text-brand-600';
        const icon = type === 'success' ? 'bi-check-circle-fill' : 'bi-bell-fill';
        const id   = 'pollToast_' + Date.now();
        const el   = document.createElement('div');
        el.id        = id;
        el.className = 'toast';
        el.innerHTML = '<i class="bi ' + icon + ' toast-icon ' + tone + '"></i>'
            + '<div class="flex-1 leading-relaxed">' + msg + '</div>'
            + '<button onclick="document.getElementById(\'' + id + '\').remove()" class="btn-close -mt-1 -mr-1" aria-label="Dismiss"></button>';
        document.body.appendChild(el);
        setTimeout(() => {
            if (document.getElementById(id)) {
                el.style.animation = 'sucfrmsToastOut 0.4s ease forwards';
                setTimeout(() => el.remove(), 400);
            }
        }, 4000);
    }

    function doPoll() {
        fetch(POLL_URL, { cache: 'no-store' })
            .then(r => r.ok ? r.json() : null)
            .then(d => { if (d && d.ok) applyPollData(d.counts); })
            .catch(() => {});
    }

    // Initial poll after 3s (let page fully load), then every 15s
    setTimeout(doPoll, 3000);
    setInterval(doPoll, 15000);
})();

// -- Feedback modal ------------------------------------------
function openFeedbackModal() {
    document.getElementById('feedbackForm').reset();
    document.getElementById('fbRating').value = '';
    document.getElementById('fbAlert').style.display = 'none';
    resetStars();
    document.getElementById('feedbackModal').style.display = 'flex';
}

let _selectedRating = 0;

function setRating(val) {
    _selectedRating = val;
    document.getElementById('fbRating').value = val;
    paintStars(val, true);
}

function hoverRating(val) { paintStars(val, false); }

function resetRatingHover() { paintStars(_selectedRating, true); }

function resetStars() {
    _selectedRating = 0;
    paintStars(0, true);
}

function paintStars(upto, solid) {
    document.querySelectorAll('#starRating .bi').forEach(el => {
        const s = parseInt(el.dataset.star);
        el.className = s <= upto ? 'bi bi-star-fill' : 'bi bi-star';
        el.style.color = s <= upto ? '#f59e0b' : '#cbd5e1';
    });
}

document.getElementById('feedbackForm').addEventListener('submit', function(e) {
    e.preventDefault();
    const alert = document.getElementById('fbAlert');
    const btn   = this.querySelector('button[type=submit]');
    btn.disabled = true;
    btn.innerHTML = '<i class="bi bi-hourglass-split"></i>Sending...';

    const fd = new FormData();
    fd.append('subject', document.getElementById('fbSubject').value.trim());
    fd.append('message', document.getElementById('fbMessage').value.trim());
    const rating = document.getElementById('fbRating').value;
    if (rating) fd.append('rating', rating);

    fetch('index.php?action=submit_feedback', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(d => {
            alert.style.display = 'flex';
            if (d.ok) {
                alert.className = 'alert alert-success mb-0';
                alert.innerHTML = '<i class="bi bi-check-circle"></i>Thank you! Your feedback has been submitted.';
                setTimeout(() => document.getElementById('feedbackModal').style.display = 'none', 2000);
            } else {
                alert.className = 'alert alert-danger mb-0';
                alert.innerHTML = '<i class="bi bi-exclamation-circle"></i>' + (d.error || 'Something went wrong.');
                btn.disabled = false;
                btn.innerHTML = '<i class="bi bi-send"></i>Submit';
            }
        })
        .catch(() => {
            alert.style.display = 'flex';
            alert.className = 'alert alert-danger mb-0';
            alert.innerHTML = '<i class="bi bi-exclamation-circle"></i>Network error. Please try again.';
            btn.disabled = false;
            btn.innerHTML = '<i class="bi bi-send"></i>Submit';
        });
});
</script>
