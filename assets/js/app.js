/* =====================================================================
   SUCFRMS — app.js
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
