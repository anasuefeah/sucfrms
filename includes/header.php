<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <meta name="mobile-web-app-capable" content="yes">
    <meta name="theme-color" content="#1a3a6b">
    <title>SUCFRMS - SUC Faculty Reclassification Management System</title>
    <link href="assets/vendor/bootstrap-icons/bootstrap-icons.min.css" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/app.css?v=<?= filemtime(__DIR__ . '/../assets/css/app.css') ?>">
</head>
<body>

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
        <!-- Hamburger — mobile only, opens slide-out sidebar -->
        <button class="d-md-none me-2" id="mobileMenuBtn" onclick="toggleMobileSidebar()"
                style="background:none;border:none;color:#fff;padding:0.3rem 0.4rem;cursor:pointer;
                       display:flex;align-items:center;justify-content:center;border-radius:6px;
                       transition:background 0.15s;"
                onmouseover="this.style.background='rgba(255,255,255,0.15)'"
                onmouseout="this.style.background='none'"
                aria-label="Open menu">
            <i class="bi bi-list" style="font-size:1.4rem;line-height:1;"></i>
        </button>
        <a class="navbar-brand d-flex align-items-center gap-2" href="index.php">
            <img src="assets/images/logo.jpg?v=2" alt="Institution Logo"
                 style="width:38px;height:38px;border-radius:50%;object-fit:cover;border:2px solid #475569;clip-path:circle(50%);flex-shrink:0;">
            <span class="fw-bold" style="color:#ffffff;font-size:1rem;">SUC Faculty Reclassification Management System</span>
        </a>
        <?php if (isset($_SESSION['user_id']) && isset($pdo)): ?>
        <?php $_notif_count = getUnreadNotifCount($pdo, (int)$_SESSION['user_id']); ?>
        <!-- Notification bell — always visible on ALL screen sizes, outside the collapsible nav -->
        <div style="position:relative;margin-left:auto;margin-right:0.35rem;flex-shrink:0;">
            <button id="notifBellBtn" onclick="toggleNotifDropdown()"
                    style="background:none;border:none;color:#fff;cursor:pointer;padding:0.4rem 0.55rem;
                           position:relative;font-size:1.3rem;line-height:1;border-radius:8px;
                           transition:background 0.2s;display:flex;align-items:center;"
                    title="Notifications"
                    onmouseover="this.style.background='rgba(255,255,255,0.15)'"
                    onmouseout="this.style.background='none'">
                <i class="bi bi-bell-fill" id="notifBellIcon"
                   style="<?= $_notif_count > 0 ? 'animation:bellRing 1.2s ease infinite;' : '' ?>"></i>
                <?php if ($_notif_count > 0): ?>
                <span id="notifBadge"
                      style="position:absolute;top:-2px;right:-2px;background:#334155;color:#fff;
                             font-size:0.58rem;font-weight:700;border-radius:50%;min-width:18px;height:18px;
                             display:flex;align-items:center;justify-content:center;line-height:1;padding:0 3px;
                             box-shadow:0 0 0 2px #1a3a6b;animation:badgePop 0.3s cubic-bezier(0.34,1.56,0.64,1);">
                    <?= $_notif_count > 9 ? '9+' : $_notif_count ?>
                </span>
                <?php else: ?>
                <span id="notifBadge"
                      style="display:none;position:absolute;top:-2px;right:-2px;background:#334155;color:#fff;
                             font-size:0.58rem;font-weight:700;border-radius:50%;min-width:18px;height:18px;
                             align-items:center;justify-content:center;line-height:1;padding:0 3px;
                             box-shadow:0 0 0 2px #1a3a6b;"></span>
                <?php endif; ?>
            </button>

            <style>
            @keyframes bellRing {
                0%,100%{transform:rotate(0)}
                10%{transform:rotate(14deg)}
                20%{transform:rotate(-12deg)}
                30%{transform:rotate(10deg)}
                40%{transform:rotate(-8deg)}
                50%{transform:rotate(5deg)}
                60%{transform:rotate(0)}
            }
            @keyframes badgePop {
                from{transform:scale(0)}
                to{transform:scale(1)}
            }
            </style>

            <!-- Notification dropdown — fixed position so it escapes any overflow clipping -->
            <div id="notifDropdown"
                 style="display:none;position:fixed;top:62px;right:12px;
                        width:360px;max-width:calc(100vw - 24px);
                        background:#fff;border-radius:12px;
                        box-shadow:0 12px 40px rgba(0,0,0,0.22);
                        z-index:99999;overflow:hidden;border:1px solid #e2e8f0;">
                <div style="padding:0.85rem 1.1rem;background:linear-gradient(135deg,#1e4d8c,#1a3a6b);
                            display:flex;justify-content:space-between;align-items:center;">
                    <div style="display:flex;align-items:center;gap:0.6rem;">
                        <i class="bi bi-bell-fill" style="color:#475569;font-size:0.95rem;"></i>
                        <span style="color:#fff;font-weight:700;font-size:0.9rem;">Notifications</span>
                        <span id="notifHeaderCount"
                              style="background:rgba(255,255,255,0.2);color:#fff;font-size:0.68rem;
                                     font-weight:700;border-radius:20px;padding:1px 7px;">
                            <?= $_notif_count > 0 ? $_notif_count . ' unread' : 'all read' ?>
                        </span>
                    </div>
                    <button onclick="markAllRead()"
                            style="background:rgba(255,255,255,0.15);border:none;color:#fff;font-size:0.72rem;
                                   cursor:pointer;padding:3px 10px;border-radius:6px;"
                            onmouseover="this.style.background='rgba(255,255,255,0.28)'"
                            onmouseout="this.style.background='rgba(255,255,255,0.15)'">
                        Mark all read
                    </button>
                </div>
                <div id="notifList" style="max-height:380px;overflow-y:auto;">
                    <div class="text-center text-muted py-4" style="font-size:0.82rem;">
                        <i class="bi bi-bell-slash d-block mb-2" style="font-size:1.5rem;"></i>Loading...
                    </div>
                </div>
                <div style="padding:0.5rem 1rem;background:#f8fafc;border-top:1px solid #f1f5f9;text-align:center;">
                    <span style="font-size:0.68rem;color:#94a3b8;">
                        <i class="bi bi-arrow-clockwise me-1"></i>Updates every 30 seconds
                    </span>
                </div>
            </div>
        </div>
        <?php endif; ?>

<!-- Help button moved to footer -->

        <!-- Send Feedback modal -->
        <div id="feedbackModal"
             style="display:none;position:fixed;inset:0;background:rgba(0,0,0,0.45);
                    z-index:100003;align-items:center;justify-content:center;">
            <div style="background:#fff;border-radius:14px;width:100%;max-width:460px;
                        margin:1rem;box-shadow:0 20px 60px rgba(0,0,0,0.25);overflow:hidden;">
                <div style="padding:1rem 1.25rem;background:linear-gradient(135deg,#1e4d8c,#1a3a6b);
                            display:flex;justify-content:space-between;align-items:center;">
                    <span style="color:#fff;font-weight:700;font-size:0.95rem;">
                        <i class="bi bi-chat-square-text me-2"></i>Send Feedback
                    </span>
                    <button onclick="document.getElementById('feedbackModal').style.display='none'"
                            style="background:none;border:none;color:#fff;font-size:1.2rem;cursor:pointer;line-height:1;">
                        <i class="bi bi-x-lg"></i>
                    </button>
                </div>
                <form id="feedbackForm" style="padding:1.25rem;">
                    <div style="margin-bottom:1rem;">
                        <label style="font-size:0.8rem;font-weight:600;color:#475569;display:block;margin-bottom:4px;">
                            Subject <span style="color:#334155;">*</span>
                        </label>
                        <input type="text" id="fbSubject" maxlength="255" required
                               placeholder="e.g. Issue with KRA upload"
                               style="width:100%;padding:0.5rem 0.75rem;border:1px solid #cbd5e1;
                                      border-radius:7px;font-size:0.85rem;outline:none;box-sizing:border-box;"
                               onfocus="this.style.borderColor='#1e4d8c'"
                               onblur="this.style.borderColor='#cbd5e1'">
                    </div>
                    <div style="margin-bottom:1rem;">
                        <label style="font-size:0.8rem;font-weight:600;color:#475569;display:block;margin-bottom:4px;">
                            Message <span style="color:#334155;">*</span>
                        </label>
                        <textarea id="fbMessage" rows="4" required
                                  placeholder="Describe your feedback, suggestion, or issue..."
                                  style="width:100%;padding:0.5rem 0.75rem;border:1px solid #cbd5e1;
                                         border-radius:7px;font-size:0.85rem;resize:vertical;
                                         outline:none;box-sizing:border-box;"
                                  onfocus="this.style.borderColor='#1e4d8c'"
                                  onblur="this.style.borderColor='#cbd5e1'"></textarea>
                    </div>
                    <div style="margin-bottom:1.25rem;">
                        <label style="font-size:0.8rem;font-weight:600;color:#475569;display:block;margin-bottom:6px;">
                            Rating (optional)
                        </label>
                        <div id="starRating" style="display:flex;gap:6px;">
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
                    <div id="fbAlert" style="display:none;margin-bottom:0.75rem;padding:0.6rem 0.9rem;
                         border-radius:7px;font-size:0.8rem;"></div>
                    <div style="display:flex;gap:0.75rem;justify-content:flex-end;">
                        <button type="button"
                                onclick="document.getElementById('feedbackModal').style.display='none'"
                                style="padding:0.45rem 1.1rem;border:1px solid #cbd5e1;border-radius:7px;
                                       background:#fff;font-size:0.85rem;cursor:pointer;">
                            Cancel
                        </button>
                        <button type="submit"
                                style="padding:0.45rem 1.3rem;border:none;border-radius:7px;
                                       background:#1e4d8c;color:#fff;font-size:0.85rem;
                                       font-weight:600;cursor:pointer;">
                            <i class="bi bi-send me-1"></i>Submit
                        </button>
                    </div>
                </form>
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
    new_submission:       { icon: 'bi-file-earmark-plus', color: '#1e4d8c' },
    under_review:         { icon: 'bi-hourglass-split',   color: '#475569' },
    talisay_review:       { icon: 'bi-building-up',       color: '#1a3a6b' },
    new_talisay_submission:{ icon: 'bi-building-exclamation', color: '#1a3a6b' },
    score_adjusted:       { icon: 'bi-pencil-square',     color: '#475569' },
    needs_revision:       { icon: 'bi-exclamation-triangle-fill', color: '#334155' },
    revision_resubmitted: { icon: 'bi-arrow-repeat',      color: '#1e4d8c' },
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
    const diff = Math.floor((Date.now() - new Date(ts)) / 1000);
    if (diff < 60)   return 'just now';
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

        // Map notification type → correct page + anchor
        function notifLink(type, appId) {
            if (!appId) return '#';
            const isFaculty  = ['faculty','checker_faculty'].includes(role);
            const isChecker  = ['checker','checker_faculty'].includes(role);
            const isTalisay  = role === 'talisay_checker';

            switch (type) {
                // Faculty: score was adjusted → go to comparison area
                case 'score_adjusted':
                    return `index.php?page=my_application&id=${appId}#score-comparison`;

                // Faculty: application under review / revision / returned / talisay
                case 'under_review':
                case 'needs_revision':
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

                // Talisay: forwarded from campus checker
                case 'new_talisay_submission':
                    return `index.php?page=review_application&id=${appId}`;

                default:
                    return isChecker || isTalisay
                        ? `index.php?page=review_application&id=${appId}`
                        : `index.php?page=my_application&id=${appId}`;
            }
        }

        const link = notifLink(n.type, id);
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

// Auto-refresh badge every 30 seconds — detect new notifications
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

            // New notification arrived — briefly highlight the bell
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
        el.style.color = s <= upto ? '#475569' : '#cbd5e1';
    });
}

document.getElementById('feedbackForm').addEventListener('submit', function(e) {
    e.preventDefault();
    const alert = document.getElementById('fbAlert');
    const btn   = this.querySelector('button[type=submit]');
    btn.disabled = true;
    btn.innerHTML = '<i class="bi bi-hourglass-split me-1"></i>Sending…';

    const fd = new FormData();
    fd.append('subject', document.getElementById('fbSubject').value.trim());
    fd.append('message', document.getElementById('fbMessage').value.trim());
    const rating = document.getElementById('fbRating').value;
    if (rating) fd.append('rating', rating);

    fetch('index.php?action=submit_feedback', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(d => {
            alert.style.display = 'block';
            if (d.ok) {
                alert.style.background = '#f0f4fb';
                alert.style.color      = '#1e4d8c';
                alert.style.border     = '1px solid #bfdbfe';
                alert.innerHTML = '<i class="bi bi-check-circle me-1"></i>Thank you! Your feedback has been submitted.';
                setTimeout(() => document.getElementById('feedbackModal').style.display = 'none', 2000);
            } else {
                alert.style.background = '#f8fafc';
                alert.style.color      = '#334155';
                alert.style.border     = '1px solid #e2e8f0';
                alert.innerHTML = '<i class="bi bi-exclamation-circle me-1"></i>' + (d.error || 'Something went wrong.');
                btn.disabled = false;
                btn.innerHTML = '<i class="bi bi-send me-1"></i>Submit';
            }
        })
        .catch(() => {
            alert.style.display    = 'block';
            alert.style.background = '#f8fafc';
            alert.style.color      = '#334155';
            alert.style.border     = '1px solid #e2e8f0';
            alert.innerHTML = '<i class="bi bi-exclamation-circle me-1"></i>Network error. Please try again.';
            btn.disabled = false;
            btn.innerHTML = '<i class="bi bi-send me-1"></i>Submit';
        });
});
</script>