/* =====================================================================
   SUCFRMS  -  app.js
   Lightweight, dependency-free replacement for the parts of Bootstrap's
   JS bundle this app actually uses: collapse (navbar + accordion),
   dropdown, and modal (incl. a `bootstrap.Modal`-compatible shim so
   existing inline scripts that call `new bootstrap.Modal(...)` keep
   working unchanged).
   ===================================================================== */
(function () {
    'use strict';

    /* ---------------- Collapse (navbar toggler + accordion) ---------------- */
    function toggleCollapse(target) {
        if (!target) return;
        const isAccordionChild = target.classList.contains('accordion-collapse');
        const parentSelector = target.getAttribute('data-bs-parent');

        if (isAccordionChild && parentSelector) {
            const parent = document.querySelector(parentSelector);
            if (parent) {
                parent.querySelectorAll('.accordion-collapse.show').forEach(function (openEl) {
                    if (openEl !== target) {
                        openEl.classList.remove('show');
                        const btn = document.querySelector('[data-bs-target="#' + openEl.id + '"]');
                        if (btn) btn.classList.add('collapsed');
                    }
                });
            }
        }

        const nowOpen = target.classList.toggle('show');
        document.querySelectorAll('[data-bs-toggle="collapse"][data-bs-target="#' + target.id + '"]').forEach(function (btn) {
            btn.classList.toggle('collapsed', !nowOpen);
            btn.setAttribute('aria-expanded', nowOpen ? 'true' : 'false');
        });
    }

    document.addEventListener('click', function (e) {
        const trigger = e.target.closest('[data-bs-toggle="collapse"]');
        if (!trigger) return;
        e.preventDefault();
        const sel = trigger.getAttribute('data-bs-target') || trigger.getAttribute('href');
        if (!sel) return;
        const target = document.querySelector(sel);
        toggleCollapse(target);
    });

    /* ---------------- Dropdown ---------------- */
    function closeAllDropdowns(except) {
        document.querySelectorAll('.dropdown-menu.show').forEach(function (menu) {
            if (menu !== except) menu.classList.remove('show');
        });
    }

    document.addEventListener('click', function (e) {
        const trigger = e.target.closest('[data-bs-toggle="dropdown"]');
        if (trigger) {
            e.preventDefault();
            e.stopPropagation();
            const menu = trigger.nextElementSibling && trigger.nextElementSibling.classList.contains('dropdown-menu')
                ? trigger.nextElementSibling
                : trigger.parentElement.querySelector('.dropdown-menu');
            const isOpen = menu && menu.classList.contains('show');
            closeAllDropdowns();
            if (menu && !isOpen) menu.classList.add('show');
            return;
        }
        if (!e.target.closest('.dropdown-menu')) closeAllDropdowns();
    });

    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape') closeAllDropdowns();
    });

    /* ---------------- Modal ---------------- */
    const openModals = [];

    function ensureBackdrop() {
        let backdrop = document.querySelector('.modal-backdrop');
        if (!backdrop) {
            backdrop = document.createElement('div');
            backdrop.className = 'modal-backdrop';
            document.body.appendChild(backdrop);
        }
        return backdrop;
    }

    function removeBackdropIfNoneOpen() {
        if (openModals.length === 0) {
            const backdrop = document.querySelector('.modal-backdrop');
            if (backdrop) backdrop.remove();
            document.body.classList.remove('modal-open');
        }
    }

    class Modal {
        constructor(el) {
            this.el = typeof el === 'string' ? document.querySelector(el) : el;
        }
        show() {
            if (!this.el) return;
            this.el.classList.add('show');
            this.el.style.display = 'block';
            document.body.classList.add('modal-open');
            ensureBackdrop();
            if (openModals.indexOf(this.el) === -1) openModals.push(this.el);
            this.el.dispatchEvent(new CustomEvent('shown.bs.modal'));
        }
        hide() {
            if (!this.el) return;
            this.el.classList.remove('show');
            this.el.style.display = 'none';
            const idx = openModals.indexOf(this.el);
            if (idx !== -1) openModals.splice(idx, 1);
            removeBackdropIfNoneOpen();
            this.el.dispatchEvent(new CustomEvent('hidden.bs.modal'));
        }
        toggle() {
            this.el && this.el.classList.contains('show') ? this.hide() : this.show();
        }
    }
    Modal.getInstance = function (el) {
        el = typeof el === 'string' ? document.querySelector(el) : el;
        if (!el) return null;
        if (!el.__modalInstance) el.__modalInstance = new Modal(el);
        return el.__modalInstance;
    };
    Modal.getOrCreateInstance = Modal.getInstance;

    // Wrap constructor so `new bootstrap.Modal(el)` also registers the instance
    const OriginalModal = Modal;
    function ModalFactory(el) {
        const instance = new OriginalModal(el);
        const target = typeof el === 'string' ? document.querySelector(el) : el;
        if (target) target.__modalInstance = instance;
        return instance;
    }
    ModalFactory.getInstance = Modal.getInstance;
    ModalFactory.getOrCreateInstance = Modal.getOrCreateInstance;

    window.bootstrap = window.bootstrap || {};
    if (!window.bootstrap.Modal) window.bootstrap.Modal = ModalFactory;

    // Trigger buttons: data-bs-toggle="modal" data-bs-target="#id"
    document.addEventListener('click', function (e) {
        const trigger = e.target.closest('[data-bs-toggle="modal"]');
        if (!trigger) return;
        e.preventDefault();
        const sel = trigger.getAttribute('data-bs-target');
        if (!sel) return;
        window.bootstrap.Modal.getOrCreateInstance(sel).show();
    });

    // Dismiss buttons: data-bs-dismiss="modal"
    document.addEventListener('click', function (e) {
        const dismiss = e.target.closest('[data-bs-dismiss="modal"]');
        if (!dismiss) return;
        const modalEl = dismiss.closest('.modal');
        if (modalEl) window.bootstrap.Modal.getOrCreateInstance(modalEl).hide();
    });

    // Click on backdrop (outside modal-content) closes the modal
    document.addEventListener('click', function (e) {
        if (e.target.classList && e.target.classList.contains('modal') && e.target.classList.contains('show')) {
            window.bootstrap.Modal.getOrCreateInstance(e.target).hide();
        }
    });

    // Alert dismiss: data-bs-dismiss="alert"
    document.addEventListener('click', function (e) {
        const dismiss = e.target.closest('[data-bs-dismiss="alert"]');
        if (!dismiss) return;
        const alertEl = dismiss.closest('.alert');
        if (alertEl) alertEl.remove();
    });
})();


/* =====================================================================
   SUCFRMS  -  showToast(message, type)
   Replaces browser alert() with a professional in-page toast.
   type: 'error' | 'warning' | 'success' | 'info'  (default: 'error')
   ===================================================================== */
window.showToast = (function () {
    var container;

    function getContainer() {
        if (!container || !document.body.contains(container)) {
            container = document.createElement('div');
            container.id = 'sucfrms-toast-container';
            container.style.cssText = [
                'position:fixed',
                'top:1.25rem',
                'right:1.25rem',
                'z-index:999999',
                'display:flex',
                'flex-direction:column',
                'gap:0.5rem',
                'pointer-events:none',
            ].join(';');
            document.body.appendChild(container);
        }
        return container;
    }

    var palettes = {
        error:   { bg:'#fef2f2', border:'#fca5a5', icon:'#dc2626', iconCls:'bi-x-circle-fill',        text:'#7f1d1d' },
        warning: { bg:'#fffbeb', border:'#fde68a', icon:'#d97706', iconCls:'bi-exclamation-triangle-fill', text:'#78350f' },
        success: { bg:'#f0fdf4', border:'#86efac', icon:'#16a34a', iconCls:'bi-check-circle-fill',     text:'#14532d' },
        info:    { bg:'#eff6ff', border:'#93c5fd', icon:'#1e4d8c', iconCls:'bi-info-circle-fill',      text:'#1e3a5f' },
    };

    return function showToast(message, type) {
        type = type || 'error';
        var p = palettes[type] || palettes.error;
        var c = getContainer();

        var toast = document.createElement('div');
        toast.style.cssText = [
            'display:flex',
            'align-items:flex-start',
            'gap:0.6rem',
            'background:' + p.bg,
            'border:1px solid ' + p.border,
            'border-left:4px solid ' + p.icon,
            'border-radius:0',
            'padding:0.75rem 1rem',
            'min-width:280px',
            'max-width:400px',
            'box-shadow:0 4px 20px rgba(0,0,0,0.14)',
            'pointer-events:all',
            'opacity:0',
            'transform:translateX(1rem)',
            'transition:opacity 0.25s ease, transform 0.25s ease',
        ].join(';');

        toast.innerHTML =
            '<i class="bi ' + p.iconCls + '" style="color:' + p.icon + ';font-size:1rem;flex-shrink:0;margin-top:2px;"></i>' +
            '<div style="flex:1;font-size:0.82rem;color:' + p.text + ';line-height:1.5;font-family:\'Segoe UI\',sans-serif;">' + message + '</div>' +
            '<button style="background:none;border:none;cursor:pointer;color:' + p.icon + ';font-size:1rem;padding:0;flex-shrink:0;line-height:1;" aria-label="Close">' +
            '<i class="bi bi-x"></i></button>';

        toast.querySelector('button').addEventListener('click', function () { dismiss(toast); });
        c.appendChild(toast);

        // Animate in
        requestAnimationFrame(function () {
            requestAnimationFrame(function () {
                toast.style.opacity = '1';
                toast.style.transform = 'translateX(0)';
            });
        });

        // Auto-dismiss after 4.5 s
        var timer = setTimeout(function () { dismiss(toast); }, 4500);
        toast.addEventListener('mouseenter', function () { clearTimeout(timer); });
        toast.addEventListener('mouseleave', function () { timer = setTimeout(function () { dismiss(toast); }, 2000); });

        function dismiss(el) {
            el.style.opacity = '0';
            el.style.transform = 'translateX(1rem)';
            setTimeout(function () { if (el.parentNode) el.parentNode.removeChild(el); }, 280);
        }
    };
}());
