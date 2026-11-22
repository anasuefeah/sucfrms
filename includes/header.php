<!DOCTYPE html>
<html lang="en">
<head>
    <link rel="stylesheet" href="assets/css/square-corners.css?v=<?= filemtime(__DIR__ . '/../assets/css/square-corners.css') ?>">
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <meta name="mobile-web-app-capable" content="yes">
    <meta name="theme-color" content="#1a3a6b">
    <title>SUCFRMS - SUC Faculty Reclassification Management System</title>
    <link href="assets/vendor/bootstrap-icons/bootstrap-icons.min.css" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/app.css?v=<?= filemtime(__DIR__ . '/../assets/css/app.css') ?>">
    <style>
    #page-loader {
        position: fixed;
        inset: 0;
        z-index: 99999;
        background: #f0f3f8;
        display: flex;
        flex-direction: column;
        align-items: center;
        justify-content: center;
        gap: 1.25rem;
        transition: opacity 0.3s ease;
    }
    #page-loader.fade-out {
        opacity: 0;
        pointer-events: none;
    }
    .loader-logo-frame {
        width: 56px;
        height: 56px;
        border-radius: 50%;
        overflow: hidden;
        border: 3px solid #1a3a6b;
        box-shadow: 0 4px 16px rgba(26,58,107,0.2);
    }
    .loader-logo-frame img {
        display: block;
        width: 100%;
        height: 100%;
        object-fit: cover;
    }
    .loader-spinner {
        width: 38px;
        height: 38px;
        border: 3px solid #dbeafe;
        border-top-color: #1a3a6b;
        border-radius: 50%;
        animation: spin 0.7s linear infinite;
    }
    .loader-text {
        font-family: 'Segoe UI', Arial, sans-serif;
        font-size: 0.75rem;
        font-weight: 600;
        color: #94a3b8;
        letter-spacing: 0.04em;
    }
    @keyframes spin { to { transform: rotate(360deg); } }
    </style>
</head>
<body>

<!-- Page loader — shown immediately, hidden once DOM is ready -->
<div id="page-loader">
    <div class="loader-logo-frame">
        <img src="assets/images/logo.jpg" alt="SUCFRMS">
    </div>
    <div class="loader-spinner"></div>
    <div class="loader-text">Loading&hellip;</div>
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
                    window.location.replace('pages/login.php');
                }
            })
            .catch(() => window.location.replace('pages/login.php'));
    }
});
</script>

<nav class="navbar navbar-expand-lg navbar-dark sticky-top" style="z-index:1030;">
    <div class="container-fluid px-4">
        <!-- Hamburger — mobile only -->
        <button class="d-md-none me-2" id="mobileMenuBtn" onclick="toggleMobileSidebar()"
                style="background:none;border:none;color:#fff;padding:0.3rem 0.4rem;cursor:pointer;
                       display:flex;align-items:center;justify-content:center;transition:opacity 0.15s;"
                onmouseover="this.style.opacity='0.75'" onmouseout="this.style.opacity='1'"
                aria-label="Open menu">
            <i class="bi bi-list" style="font-size:1.4rem;line-height:1;"></i>
        </button>

        <!-- Brand -->
        <a class="navbar-brand d-flex align-items-center gap-2" href="index.php" style="gap:0.65rem!important;">
            <img src="assets/images/logo.jpg?v=2" alt="Logo"
                 style="width:32px;height:32px;border-radius:50%;object-fit:cover;
                        border:1.5px solid rgba(255,255,255,0.3);flex-shrink:0;">
            <div style="display:flex;flex-direction:column;line-height:1.1;">
                <span style="color:#fff;font-size:0.82rem;font-weight:700;letter-spacing:0.01em;white-space:nowrap;">SUCFRMS</span>
                <span style="color:rgba(255,255,255,0.45);font-size:0.62rem;font-weight:400;letter-spacing:0.02em;white-space:nowrap;" class="d-none d-lg-block">SUC Faculty Reclassification Management System</span>
            </div>
        </a>

        <?php if (isset($_SESSION['user_id']) && isset($pdo)): ?>
        <?php $_notif_count = getUnreadNotifCount($pdo, (int)$_SESSION['user_id']); ?>

        <!-- Right controls -->
        <div style="display:flex;align-items:center;gap:0.25rem;margin-left:auto;flex-shrink:0;">

            <!-- Notification bell -->
            <div style="position:relative;">
                <button id="notifBellBtn" onclick="toggleNotifDropdown()"
                        style="background:none;border:none;color:rgba(255,255,255,0.75);cursor:pointer;
                               padding:0.45rem 0.55rem;position:relative;font-size:1.1rem;line-height:1;
                               display:flex;align-items:center;transition:color 0.15s;"
                        title="Notifications"
                        onmouseover="this.style.color='#fff'" onmouseout="this.style.color='rgba(255,255,255,0.75)'">
                    <i class="bi bi-bell<?= $_notif_count > 0 ? '-fill' : '' ?>" id="notifBellIcon"
                       style="<?= $_notif_count > 0 ? 'animation:bellRing 1.2s ease infinite;' : '' ?>"></i>
                    <?php if ($_notif_count > 0): ?>
                    <span id="notifBadge"
                          style="position:absolute;top:2px;right:2px;background:#dc2626;color:#fff;
                                 font-size:0.52rem;font-weight:800;border-radius:50%;min-width:15px;height:15px;
                                 display:flex;align-items:center;justify-content:center;padding:0 2px;
                                 box-shadow:0 0 0 1.5px #1a3a6b;animation:badgePop 0.3s cubic-bezier(0.34,1.56,0.64,1);">
                        <?= $_notif_count > 9 ? '9+' : $_notif_count ?>
                    </span>
                    <?php else: ?>
                    <span id="notifBadge"
                          style="display:none;position:absolute;top:2px;right:2px;background:#dc2626;color:#fff;
                                 font-size:0.52rem;font-weight:800;border-radius:50%;min-width:15px;height:15px;
                                 align-items:center;justify-content:center;padding:0 2px;"></span>
                    <?php endif; ?>
                </button>
                <style>
                @keyframes bellRing {
                    0%,100%{transform:rotate(0)}
                    10%{transform:rotate(14deg)} 20%{transform:rotate(-12deg)}
                    30%{transform:rotate(10deg)} 40%{transform:rotate(-8deg)}
                    50%{transform:rotate(5deg)}  60%{transform:rotate(0)}
                }
                @keyframes badgePop { from{transform:scale(0)} to{transform:scale(1)} }
                </style>

                <!-- Notification dropdown -->
                <div id="notifDropdown"
                     style="display:none;position:fixed;top:54px;right:12px;
                            width:360px;max-width:calc(100vw - 24px);
                            background:#fff;border:1px solid #e2e8f0;
                            box-shadow:0 8px 32px rgba(0,0,0,0.16);
                            z-index:99999;overflow:hidden;">
                    <!-- Dropdown header -->
                    <div style="padding:0.7rem 1rem;background:#1a3a6b;
                                display:flex;justify-content:space-between;align-items:center;">
                        <div style="display:flex;align-items:center;gap:0.5rem;">
                            <i class="bi bi-bell-fill" style="color:rgba(255,255,255,0.6);font-size:0.82rem;"></i>
                            <span style="color:#fff;font-weight:700;font-size:0.82rem;">Notifications</span>
                            <span id="notifHeaderCount"
                                  style="background:rgba(255,255,255,0.15);color:#fff;font-size:0.62rem;
                                         font-weight:700;padding:1px 6px;">
                                <?= $_notif_count > 0 ? $_notif_count . ' unread' : 'all read' ?>
                            </span>
                        </div>
                        <button onclick="markAllRead()"
                                style="background:rgba(255,255,255,0.12);border:none;color:#fff;font-size:0.68rem;
                                       cursor:pointer;padding:2px 8px;transition:background 0.15s;"
                                onmouseover="this.style.background='rgba(255,255,255,0.25)'"
                                onmouseout="this.style.background='rgba(255,255,255,0.12)'">
                            Mark all read
                        </button>
                    </div>
                    <div id="notifList" style="max-height:360px;overflow-y:auto;">
                        <div class="text-center text-muted py-4" style="font-size:0.8rem;">
                            <i class="bi bi-bell-slash d-block mb-2" style="font-size:1.4rem;"></i>Loading...
                        </div>
                    </div>
                    <div style="padding:0.4rem 0.85rem;background:#f8fafc;border-top:1px solid #f1f5f9;text-align:center;">
                        <span style="font-size:0.62rem;color:#94a3b8;">
                            <i class="bi bi-arrow-clockwise me-1"></i>Updates every 30 s
                        </span>
                    </div>
                </div>
            </div>

        </div>
        <?php endif; ?>

<!-- Help button moved to footer -->

        <!-- Send Feedback modal -->
        <div id="feedbackModal"
             style="display:none;position:fixed;inset:0;background:rgba(0,0,0,0.45);
                    z-index:100003;align-items:center;justify-content:center;">
            <div style="background:#fff;width:100%;max-width:440px;margin:1rem;
                        border:1px solid #e2e8f0;box-shadow:0 16px 48px rgba(0,0,0,0.18);overflow:hidden;">
                <div style="padding:0.8rem 1.1rem;background:#1a3a6b;
                            display:flex;justify-content:space-between;align-items:center;">
                    <span style="color:#fff;font-weight:600;font-size:0.85rem;letter-spacing:0.01em;">
                        <i class="bi bi-chat-square-text me-2"></i>Send Feedback
                    </span>
                    <button onclick="document.getElementById('feedbackModal').style.display='none'"
                            style="background:none;border:none;color:#fff;cursor:pointer;padding:0;line-height:1;opacity:0.7;transition:opacity 0.15s;"
                            onmouseover="this.style.opacity='1'"
                            onmouseout="this.style.opacity='0.7'">
                        <i class="bi bi-x-lg" style="font-size:0.9rem;"></i>
                    </button>
                </div>
                <form id="feedbackForm" style="padding:1.1rem;">
                    <div style="margin-bottom:0.85rem;">
                        <label style="font-size:0.68rem;font-weight:600;color:#475569;display:block;margin-bottom:5px;letter-spacing:0.04em;text-transform:uppercase;">
                            Subject
                        </label>
                        <input type="text" id="fbSubject" maxlength="255" required
                               placeholder="Brief title for your feedback"
                               style="width:100%;padding:0.5rem 0.65rem;border:1px solid #e2e8f0;
                                      font-size:0.83rem;outline:none;box-sizing:border-box;
                                      font-family:inherit;background:#f8fafc;transition:border-color 0.15s,background 0.15s;"
                               onfocus="this.style.borderColor='#1a3a6b';this.style.background='#fff'"
                               onblur="this.style.borderColor='#e2e8f0';this.style.background='#f8fafc'">
                    </div>
                    <div style="margin-bottom:0.85rem;">
                        <label style="font-size:0.68rem;font-weight:600;color:#475569;display:block;margin-bottom:5px;letter-spacing:0.04em;text-transform:uppercase;">
                            Message
                        </label>
                        <textarea id="fbMessage" rows="4" required
                                  placeholder="Describe your feedback, suggestion, or issue..."
                                  style="width:100%;padding:0.5rem 0.65rem;border:1px solid #e2e8f0;
                                         font-size:0.83rem;resize:vertical;outline:none;box-sizing:border-box;
                                         font-family:inherit;background:#f8fafc;transition:border-color 0.15s,background 0.15s;"
                                  onfocus="this.style.borderColor='#1a3a6b';this.style.background='#fff'"
                                  onblur="this.style.borderColor='#e2e8f0';this.style.background='#f8fafc'"></textarea>
                    </div>
                    <div style="margin-bottom:0.9rem;">
                        <label style="font-size:0.68rem;font-weight:600;color:#475569;display:block;margin-bottom:5px;letter-spacing:0.04em;text-transform:uppercase;">
                            Rating (optional)
                        </label>
                        <div id="starRating" style="display:flex;gap:4px;">
                            <?php for ($s = 1; $s <= 5; $s++): ?>
                            <i class="bi bi-star" data-star="<?= $s ?>"
                               style="font-size:1.2rem;cursor:pointer;color:#cbd5e1;transition:color 0.1s;"
                               onclick="setRating(<?= $s ?>)"
                               onmouseover="hoverRating(<?= $s ?>)"
                               onmouseout="resetRatingHover()"></i>
                            <?php endfor; ?>
                        </div>
                        <input type="hidden" id="fbRating" value="">
                    </div>
                    <div id="fbAlert" role="alert" style="display:none;margin-bottom:0.75rem;padding:0.6rem 0.8rem;
                         font-size:0.78rem;border-left:3px solid;"></div>
                    <div style="display:flex;gap:0.5rem;justify-content:flex-end;">
                        <button type="button"
                                onclick="document.getElementById('feedbackModal').style.display='none'"
                                style="padding:0.5rem 1.1rem;border:1px solid #e2e8f0;
                                       background:#fff;font-size:0.8rem;cursor:pointer;color:#475569;
                                       transition:background 0.15s;"
                                onmouseover="this.style.background='#f8fafc'"
                                onmouseout="this.style.background='#fff'">
                            Cancel
                        </button>
                        <button type="submit"
                                style="padding:0.5rem 1.3rem;border:none;
                                       background:#1a3a6b;color:#fff;font-size:0.8rem;
                                       font-weight:600;cursor:pointer;transition:background 0.15s;"
                                onmouseover="this.style.background='#152d54'"
                                onmouseout="this.style.background='#1a3a6b'">
                            <i class="bi bi-send me-1"></i>Submit
                        </button>
                    </div>
                </form>
                <div id="feedbackSuccess" class="feedback-success" style="display:none;" role="status" aria-live="polite" tabindex="-1">
                    <svg class="feedback-success-check" viewBox="0 0 64 64" aria-hidden="true">
                        <circle cx="32" cy="32" r="28" />
                        <path d="M18 33l9 9 19-21" />
                    </svg>
                    <h2>Feedback sent!</h2>
                    <p>Thank you. Your feedback has been submitted successfully.</p>
                    <button type="button" onclick="document.getElementById('feedbackModal').style.display='none'">Done</button>
                </div>
            </div>
        </div>

        <div class="collapse navbar-collapse" id="mainNav"></div>
    </div>
</nav>

<style>
/* Mobile sidebar slide-out drawer */
@media (max-width: 767.98px) {
    #sidebarCol {
        display: block !important;
        position: fixed !important;
        top: 0;
        left: 0;
        height: 100vh;
        width: 260px !important;
        z-index: 1040;
        transform: translateX(-100%);
        transition: transform 0.25s cubic-bezier(0.4, 0, 0.2, 1);
        overflow-y: auto;
    }
    #sidebarCol.mobile-open {
        transform: translateX(0);
    }
    /* Push main content full-width on mobile */
    .main-content {
        padding-left: 0 !important;
    }
}
</style>

<!-- Mobile sidebar backdrop -->
<div id="mobileSidebarBackdrop"
     onclick="toggleMobileSidebar()"
     style="display:none;position:fixed;inset:0;background:rgba(0,0,0,0.5);z-index:1039;"></div>

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
    criteria_updated:     { icon: 'bi-sliders2',          color: '#1a3a6b' },
    new_submission:       { icon: 'bi-file-earmark-plus', color: '#1e4d8c' },
    under_review:         { icon: 'bi-hourglass-split',   color: '#475569' },
    score_adjusted:       { icon: 'bi-pencil-square',     color: '#475569' },
    needs_revision:       { icon: 'bi-exclamation-triangle-fill', color: '#334155' },
    revision_resubmitted: { icon: 'bi-arrow-repeat',      color: '#1e4d8c' },
    appeal_opened:        { icon: 'bi-chat-square-text',  color: '#1e4d8c' },
    appeal_message:       { icon: 'bi-chat-dots',         color: '#1a3a6b' },
    appeal_resolved:      { icon: 'bi-check2-circle',     color: '#1e4d8c' },
    approved:             { icon: 'bi-check-circle-fill', color: '#1e4d8c' },
    rejected:             { icon: 'bi-x-circle-fill',     color: '#334155' },
};

function notifIconFor(type) {
    const d = NOTIF_ICONS[type] || { icon: 'bi-bell', color: '#64748b' };
    return `<span style="width:34px;height:34px;border-radius:50%;background:${d.color}1a;flex-shrink:0;
                display:flex;align-items:center;justify-content:center;">
                <i class="bi ${d.icon}" style="color:${d.color};font-size:0.95rem;"></i>
            </span>`;
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
        list.innerHTML = `<div class="text-center text-muted py-4" style="font-size:0.82rem;">
            <i class="bi bi-bell-slash d-block mb-2" style="font-size:1.5rem;"></i>No notifications yet</div>`;
        return;
    }
    list.innerHTML = notifs.map(n => {
        const unread = n.is_read == 0;
        const role   = '<?= $_SESSION['role'] ?? 'faculty' ?>';
        const id     = n.application_id;

        // Map notification type -> correct page + anchor
        function notifLink(type, appId, submissionId, kraCategory) {
            if (type === 'criteria_updated') return 'pages/self_assessment.php';
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

                // Faculty: application under review / returned / completed
                case 'under_review':
                case 'rejected':
                case 'approved':
                    return isFaculty
                        ? `index.php?page=my_application&id=${appId}#app-status`
                        : `index.php?page=review_application&id=${appId}`;

                // Checkers: new submission to review
                case 'new_submission':
                case 'revision_resubmitted':
                    return `index.php?page=review_application&id=${appId}#kra-verification`;

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
                     style="display:flex;gap:0.75rem;align-items:flex-start;padding:0.75rem 1rem;cursor:pointer;
                            border-bottom:1px solid #f1f5f9;transition:background 0.15s;
                            background:${unread ? '#f0f7ff' : '#fff'};"
                     onmouseover="this.style.background='#f8fafc'"
                     onmouseout="this.style.background='${unread ? '#f0f7ff' : '#fff'}'">
                    ${notifIconFor(n.type)}
                    <div style="flex:1;min-width:0;">
                        <div style="font-size:0.8rem;color:#1e293b;line-height:1.4;word-break:break-word;
                                    font-weight:${unread ? '600' : '400'};">${escHtml(n.message)}</div>
                        <div style="font-size:0.68rem;color:#94a3b8;margin-top:3px;">${timeAgo(n.created_at)}</div>
                    </div>
                    ${unread ? `<span style="width:8px;height:8px;border-radius:50%;background:#1e4d8c;flex-shrink:0;margin-top:5px;"></span>` : ''}
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
    list.innerHTML = `<div class="text-center text-muted py-4" style="font-size:0.82rem;">
        <i class="bi bi-wifi-off d-block mb-2" style="font-size:1.5rem;"></i>${msg}
        <div style="margin-top:6px;"><button onclick="loadNotifs()" style="font-size:0.75rem;background:none;border:1px solid #cbd5e1;border-radius:6px;padding:2px 10px;cursor:pointer;color:#475569;">Retry</button></div>
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
let _lastUnreadCount = <?= $_notif_count ?>;
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
                    bell.style.color = '#475569';
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
    const POLL_URL = 'ajax/dashboard_poll.php';
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
        const color = type === 'success' ? '#16a34a' : type === 'warn' ? '#d97706' : '#1e4d8c';
        const icon  = type === 'success' ? 'bi-check-circle-fill' : 'bi-bell-fill';
        const id    = 'pollToast_' + Date.now();
        const el    = document.createElement('div');
        el.id       = id;
        el.style.cssText = 'position:fixed;bottom:1.5rem;right:1.5rem;z-index:999998;'
            + 'min-width:260px;max-width:380px;background:#fff;border-radius:12px;'
            + 'box-shadow:0 8px 32px rgba(0,0,0,0.16);display:flex;align-items:flex-start;gap:0.75rem;'
            + 'padding:0.9rem 1rem;border-left:4px solid ' + color + ';'
            + 'animation:sucfrmsToastIn 0.3s ease;';
        el.innerHTML = '<i class="bi ' + icon + '" style="color:' + color + ';font-size:1.1rem;flex-shrink:0;margin-top:2px;"></i>'
            + '<div style="flex:1;font-size:0.83rem;color:#1e293b;line-height:1.5;">' + msg + '</div>'
            + '<button onclick="document.getElementById(\'' + id + '\').remove()" '
            + 'style="background:none;border:none;color:#94a3b8;cursor:pointer;font-size:1.1rem;padding:0;flex-shrink:0;">&times;</button>';
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
let feedbackPending = false;

function openFeedbackModal() {
    document.getElementById('feedbackModal').style.display = 'flex';
    if (feedbackPending) return;
    document.getElementById('feedbackSuccess').style.display = 'none';
    document.getElementById('feedbackForm').style.display = 'block';
    document.getElementById('feedbackForm').reset();
    const btn = document.querySelector('#feedbackForm button[type=submit]');
    btn.disabled = false;
    btn.innerHTML = '<i class="bi bi-send me-1"></i>Submit';
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
        el.style.color = s <= upto ? '#1a3a6b' : '#cbd5e1';
    });
}

document.getElementById('feedbackForm').addEventListener('submit', function(e) {
    e.preventDefault();
    if (feedbackPending) return;
    feedbackPending = true;
    const alert = document.getElementById('fbAlert');
    const btn   = this.querySelector('button[type=submit]');
    alert.style.display = 'none';
    btn.disabled = true;
    btn.innerHTML = '<i class="bi bi-hourglass-split me-1"></i>Sending...';

    const fd = new FormData();
    fd.append('subject', document.getElementById('fbSubject').value.trim());
    fd.append('message', document.getElementById('fbMessage').value.trim());
    const rating = document.getElementById('fbRating').value;
    if (rating) fd.append('rating', rating);

    fetch('index.php?action=submit_feedback', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(d => {
            if (d.ok) {
                document.getElementById('feedbackForm').style.display = 'none';
                const success = document.getElementById('feedbackSuccess');
                success.style.display = 'block';
                if (document.getElementById('feedbackModal').style.display !== 'none') success.focus();
            } else {
                alert.style.display = 'block';
                alert.style.background = '#f8fafc';
                alert.style.color      = '#475569';
                alert.style.borderLeftColor = '#94a3b8';
                alert.textContent = d.error || 'Something went wrong.';
                btn.disabled = false;
                btn.innerHTML = '<i class="bi bi-send me-1"></i>Submit';
            }
        })
        .catch(() => {
            alert.style.display    = 'block';
            alert.style.background = '#f8fafc';
            alert.style.color      = '#475569';
            alert.style.borderLeftColor = '#94a3b8';
            alert.innerHTML = '<i class="bi bi-exclamation-circle me-1"></i>Network error. Please try again.';
            btn.disabled = false;
            btn.innerHTML = '<i class="bi bi-send me-1"></i>Submit';
        })
        .finally(() => { feedbackPending = false; });
});
</script>
