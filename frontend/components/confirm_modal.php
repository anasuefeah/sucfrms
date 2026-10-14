<?php
// Reusable confirm dialog (markup + JS). Rendered once per page by the footer.

// -- Confirm Delete Modal --------------------------------------
/**
 * Renders a reusable Bootstrap confirm-delete modal + JS helper.
 * Call once per page (e.g. in footer). Trigger via:
 *   confirmDelete('Are you sure?', formId)
 */
function renderConfirmModal(): void { ?>
<!-- Confirm Modal -->
<div id="confirmModal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,0.5);z-index:99999;align-items:center;justify-content:center;padding:1rem;">
  <div style="background:#fff;border-radius:16px;width:100%;max-width:480px;box-shadow:0 20px 60px rgba(0,0,0,0.25);overflow:hidden;">

    <!-- Header with logo -->
    <div style="background:#1a3a6b;padding:1.25rem 1.5rem;display:flex;align-items:center;gap:0.875rem;">
      <img src="<?= BASE_URL ?>frontend/assets/images/logo.jpg" alt="SUCFRMS Logo"
           style="width:40px;height:40px;border-radius:50%;object-fit:cover;border:2px solid #475569;clip-path:circle(50%);flex-shrink:0;">
      <div>
        <div style="color:#fff;font-weight:700;font-size:0.95rem;line-height:1.2;">SUCFRMS</div>
        <div style="color:#bfdbfe;font-size:0.72rem;margin-top:2px;">SUC Faculty Reclassification Management System</div>
      </div>
    </div>

    <!-- Body -->
    <div style="padding:2rem 1.75rem 1.5rem;text-align:center;">
      <div id="confirmModalIconWrap" style="width:68px;height:68px;border-radius:50%;display:flex;align-items:center;justify-content:center;margin:0 auto 1.25rem;">
        <i id="confirmModalIcon" style="font-size:1.75rem;"></i>
      </div>
      <h5 id="confirmModalTitle" style="font-weight:700;color:#1e293b;margin-bottom:0.5rem;font-size:1.15rem;">Confirm Action</h5>
      <p id="confirmModalMsg" style="color:#64748b;font-size:0.9rem;margin:0;line-height:1.6;">Are you sure you want to proceed?</p>
    </div>

    <!-- Divider -->
    <div style="height:1px;background:#e2e8f0;margin:0 1.75rem;"></div>

    <!-- Buttons -->
    <div style="padding:1.25rem 1.75rem 1.75rem;display:flex;gap:0.75rem;">
      <button type="button" id="confirmModalCancelBtn"
              style="flex:1;padding:0.7rem 1rem;border:1px solid #cbd5e1;border-radius:8px;background:#fff;color:#475569;font-weight:600;cursor:pointer;font-size:0.875rem;">
        Cancel
      </button>
      <button type="button" id="confirmModalBtn"
              style="flex:1;padding:0.7rem 1rem;border:none;border-radius:8px;color:#fff;font-weight:600;cursor:pointer;font-size:0.875rem;display:flex;align-items:center;justify-content:center;gap:0.4rem;background:#1e4d8c;">
        <i id="confirmModalBtnIcon" class="bi bi-check-circle"></i>
        <span id="confirmModalBtnLabel">Confirm</span>
      </button>
    </div>
  </div>
</div>

<script>
const CONFIRM_GREEN_LABELS = ['approve','activate','save','update','add','generate','unlock','set','role','submit','join','send','verify','confirm','create','assign','start','reactivate','mark','review','flag','clear flag','proceed','enable','restore','upload','print','export','change'];
const CONFIRM_RED_LABELS   = ['delete','reject','deny','remove','logout','deactivate','revoke','disable','discard','cancel application','permanently'];

function _showConfirmModal(msg, btnLabel, btnIcon, isRed, onConfirm) {
    const modal = document.getElementById('confirmModal');
    const wrap  = document.getElementById('confirmModalIconWrap');
    const icon  = document.getElementById('confirmModalIcon');
    const title = document.getElementById('confirmModalTitle');
    const btn   = document.getElementById('confirmModalBtn');

    if (isRed) {
        wrap.style.background = '#fef2f2';
        icon.className = 'bi ' + btnIcon;
        icon.style.color = '#dc2626';
        btn.style.background = '#dc2626';
    } else {
        wrap.style.background = '#f0fdf4';
        icon.className = 'bi ' + btnIcon;
        icon.style.color = '#16a34a';
        btn.style.background = '#16a34a';
    }

    title.textContent = btnLabel;
    document.getElementById('confirmModalMsg').textContent = msg;
    document.getElementById('confirmModalBtnLabel').textContent = btnLabel;
    document.getElementById('confirmModalBtnIcon').className = 'bi ' + btnIcon;

    const fresh = btn.cloneNode(true);
    btn.parentNode.replaceChild(fresh, btn);
    fresh.addEventListener('click', function() {
        modal.style.display = 'none';
        onConfirm();
    });

    modal.style.display = 'flex';
}

document.addEventListener('DOMContentLoaded', function() {
    const cancelBtn = document.getElementById('confirmModalCancelBtn');
    if (cancelBtn) cancelBtn.addEventListener('click', function() {
        document.getElementById('confirmModal').style.display = 'none';
    });
    const modal = document.getElementById('confirmModal');
    if (modal) modal.addEventListener('click', function(e) {
        if (e.target === this) this.style.display = 'none';
    });
});

function confirmDelete(msg, formId, btnLabel = 'Delete', btnIcon = 'bi-trash') {
    const labelLower = btnLabel.toLowerCase();
    const isRed = CONFIRM_RED_LABELS.some(w => labelLower.includes(w));
    _showConfirmModal(msg, btnLabel, btnIcon, isRed, function() {
        document.getElementById(formId).submit();
    });
}

function confirmAction(msg, callback, btnLabel = 'Confirm', btnIcon = 'bi-check-circle') {
    const labelLower = btnLabel.toLowerCase();
    const isRed = CONFIRM_RED_LABELS.some(w => labelLower.includes(w));
    _showConfirmModal(msg, btnLabel, btnIcon, isRed, callback);
}
</script>
<?php }
