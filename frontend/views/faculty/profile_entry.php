<?php if ((require __DIR__ . '/../../../backend/controllers/faculty/profile_entry.php') === false) return; ?>

<div class="bg-slate-50 border border-slate-200 [border-left:4px_solid_#1e4d8c] rounded-lg [padding:0.65rem_1rem] [margin-bottom:1.25rem] text-slate-600 [font-size:0.84rem]">
 <i class="bi bi-lock-fill me-1 text-blue-800"></i>Complete all three steps to unlock your account.
</div>

<?php if ($errors): ?>
<div class="bg-rose-50 border border-rose-200 rounded-lg [padding:0.8rem_1rem] mb-4 text-rose-600 [font-size:0.84rem]">
 <strong>Please fix the following:</strong>
 <ul class="[margin:0.35rem_0_0] [padding-left:1.2rem]">
 <?php foreach ($errors as $err): ?><li><?= htmlspecialchars($err) ?></li><?php endforeach; ?>
 </ul>
</div>
<?php endif; ?>

<!-- ── STEP INDICATOR ───────────────────────────────────────── -->
<div id="stepIndicator" class="flex items-center gap-0 [margin-bottom:1.5rem]">
 <?php
 $steps = ['Personal & Account', 'Education', 'Employment', 'Review'];
 foreach ($steps as $si => $slabel):
 $sn = $si + 1;
 ?>
 <div class="flex min-w-0 flex-1 items-center" id="stepWrap<?= $sn ?>">
 <div class="flex flex-col items-center flex-1 min-w-0">
 <div id="stepCircle<?= $sn ?>"
 class="flex size-8 shrink-0 items-center justify-center rounded-full border-2 border-slate-200 bg-slate-200 text-xs font-bold text-slate-400 transition-all">
 <?= $sn ?>
 </div>
 <div id="stepLabel<?= $sn ?>"
 class="mt-1 whitespace-nowrap text-[0.68rem] font-semibold text-slate-400 transition-colors">
 <?= $slabel ?>
 </div>
 </div>
 <?php if ($sn < count($steps)): ?>
 <div id="stepLine<?= $sn ?>"
 class="mb-[22px] mx-1 h-0.5 max-w-20 flex-1 bg-slate-200 transition-colors max-md:max-w-[30px]"></div>
 <?php endif; ?>
 </div>
 <?php endforeach; ?>
</div>

<form method="POST" enctype="multipart/form-data" id="profileWizard">
<input type="hidden" name="csrf" value="<?= htmlspecialchars($_SESSION['profile_entry_csrf']) ?>">

<!-- ══════════════════════════════════════════════
 STEP 1 — Personal & Account
══════════════════════════════════════════════ -->
<div id="step1" class="wizard-step neon-card [padding:1.25rem_1.5rem]">
 <div class="[font-size:0.7rem] font-bold uppercase [letter-spacing:0.07em] text-slate-400 mb-4 [padding-bottom:0.5rem] border-b border-slate-100">
 Personal &amp; Account
 </div>
 <div class="row g-3">
 <div class="col-md-3">
 <label class="form-label">Profile Photo</label>
 <input type="file" name="profile_pic" class="form-control" accept=".jpg,.jpeg,.png,.webp">
 <div class="[font-size:0.72rem] text-slate-400 mt-1">JPG, PNG, WEBP. Max 2 MB.</div>
 </div>
 <div class="col-md-3">
 <label class="form-label">First Name <span class="text-danger">*</span></label>
 <input type="text" name="first_name" class="form-control" value="<?= $val('first_name') ?>" required>
 </div>
 <div class="col-md-3">
 <label class="form-label">Middle Name</label>
 <input type="text" name="middle_name" id="peMiddle" class="form-control" value="<?= $val('middle_name') ?>">
 <label class="[font-size:0.74rem] text-slate-600 mt-1 flex [gap:0.35rem] items-center cursor-pointer">
 <input type="checkbox" name="no_middle_name" onchange="document.getElementById('peMiddle').disabled=this.checked"> No middle name
 </label>
 </div>
 <div class="col-md-3">
 <label class="form-label">Last Name <span class="text-danger">*</span></label>
 <input type="text" name="last_name" class="form-control" value="<?= $val('last_name') ?>" required>
 </div>
 <div class="col-md-2">
 <label class="form-label">Suffix</label>
 <input type="text" name="suffix" class="form-control" value="<?= $val('suffix') ?>">
 </div>
 <div class="col-md-4">
 <label class="form-label">Email</label>
 <div class="input-group">
 <span class="input-group-text"><i class="bi bi-lock-fill"></i></span>
 <input type="email" class="form-control" value="<?= htmlspecialchars($user['email']) ?>" readonly>
 </div>
 <div class="[font-size:0.72rem] text-slate-400 mt-1">Email can only be changed by the Administrator.</div>
 </div>
 <div class="col-md-3">
 <label class="form-label">Employee ID <span class="text-danger">*</span></label>
 <input type="text" name="employee_id" class="form-control" value="<?= $val('employee_id') ?>" required>
 </div>
 <div class="col-md-3">
 <label class="form-label">Campus <span class="text-danger">*</span></label>
 <select name="campus_id" class="form-select" required>
 <option value="">Select campus</option>
 <?php foreach ($campuses as $c): ?>
 <option value="<?= (int)$c['campus_id'] ?>" <?= $selected('campus_id', $c['campus_id']) ?>><?= htmlspecialchars($c['campus_name']) ?></option>
 <?php endforeach; ?>
 </select>
 </div>
 <div class="col-md-4">
 <label class="form-label">Faculty Rank <span class="text-danger">*</span></label>
 <select name="rank" class="form-select" required>
 <option value="">Select rank</option>
 <?php foreach (facultyRanks() as $r): ?>
 <option value="<?= htmlspecialchars($r) ?>" <?= $selected('rank', $r) ?>><?= htmlspecialchars($r) ?></option>
 <?php endforeach; ?>
 </select>
 </div>
 <div class="col-md-3">
 <label class="form-label">Status <span class="text-danger">*</span></label>
 <select name="faculty_status" class="form-select" required>
 <option value="Existing Faculty" <?= $selected('faculty_status', 'Existing Faculty', 'New Faculty') ?>>Existing Faculty</option>
 <option value="New Faculty" <?= $selected('faculty_status', 'New Faculty', 'New Faculty') ?>>New Faculty</option>
 </select>
 </div>
 </div>

 <div class="flex justify-end [padding-top:1rem] [margin-top:0.5rem] border-t border-slate-100">
 <button type="button" class="btn btn-primary bg-brand-700 [border-color:#1a3a6b] [font-size:0.85rem] [padding:0.5rem_1.4rem]"
 onclick="goStep(2)"
 >
 Continue &rarr;
 </button>
 </div>
</div>

<!-- ══════════════════════════════════════════════
 STEP 2 — Educational Attainment
══════════════════════════════════════════════ -->
<div id="step2" class="wizard-step neon-card hidden [padding:1.25rem_1.5rem]">
 <div class="[font-size:0.7rem] font-bold uppercase [letter-spacing:0.07em] text-slate-400 mb-4 [padding-bottom:0.5rem] border-b border-slate-100">
 Educational Attainment
 </div>
 <div class="flex flex-col [gap:0.75rem]">
 <?php
 $edu_levels = [
 'Bachelor' => ['Undergrad', true],
 'Master' => ['Masters', false],
 'Doctorate' => ['Doctorate', false],
 'PostDoctorate' => ['Post-doctorate', false],
 ];
 foreach ($edu_levels as $level_value => [$level_label, $is_required]):
 $idx = array_search($level_value, $_POST['edu_level'] ?? [], true);
 $degree_value = $idx !== false ? ($_POST['edu_degree'][$idx] ?? '') : '';
 $school_value = $idx !== false ? ($_POST['edu_school'][$idx] ?? '') : '';
 $year_value = $idx !== false ? ($_POST['edu_year'][$idx] ?? '') : '';
 ?>
 <div class="bg-slate-50 border border-slate-200 rounded-lg [padding:0.85rem]">
 <input type="hidden" name="edu_level[]" value="<?= htmlspecialchars($level_value) ?>">
 <div class="[font-size:0.8rem] font-bold text-brand-700 [margin-bottom:0.6rem]">
 <?= htmlspecialchars($level_label) ?>
 <?= $is_required ? '' : '<span class="[font-size:0.68rem] text-slate-400 font-normal ml-1">(optional — enter N/A if none)</span>' ?>
 </div>
 <div class="row g-2">
 <div class="col-md-5">
 <label class="form-label [font-size:0.78rem]">Name of Degree<?= $is_required ? ' <span class="text-danger">*</span>' : '' ?></label>
 <input name="edu_degree[]" class="form-control form-control-sm"
 value="<?= htmlspecialchars($degree_value) ?>"
 placeholder="<?= $is_required ? 'Bachelor of Science in...' : 'N/A if none' ?>"
 <?= $is_required ? 'required' : '' ?>>
 </div>
 <div class="col-md-5">
 <label class="form-label [font-size:0.78rem]">Name of SUC<?= $is_required ? ' <span class="text-danger">*</span>' : '' ?></label>
 <input name="edu_school[]" class="form-control form-control-sm"
 value="<?= htmlspecialchars($school_value) ?>"
 placeholder="<?= $is_required ? 'School / University' : 'N/A if none' ?>"
 <?= $is_required ? 'required' : '' ?>>
 </div>
 <div class="col-md-2">
 <label class="form-label [font-size:0.78rem]">Year<?= $is_required ? ' <span class="text-danger">*</span>' : '' ?></label>
 <input name="edu_year[]" class="form-control form-control-sm"
 value="<?= htmlspecialchars($year_value) ?>"
 placeholder="<?= $is_required ? 'YYYY' : 'N/A' ?>"
 <?= $is_required ? 'required' : '' ?>>
 </div>
 </div>
 </div>
 <?php endforeach; ?>
 </div>

 <div class="[margin-top:0.85rem] bg-slate-50 border border-slate-200 rounded-lg [padding:0.75rem_0.85rem]">
 <label class="form-label [font-size:0.82rem] [margin-bottom:0.35rem]">Applied during 1st Cycle <span class="text-danger">*</span></label>
 <select name="applied_first_cycle" class="form-select form-select-sm [max-width:220px]" required>
 <option value="0" <?= (($_POST['applied_first_cycle'] ?? '0') === '0') ? 'selected' : '' ?>>NO</option>
 <option value="1" <?= (($_POST['applied_first_cycle'] ?? '0') === '1') ? 'selected' : '' ?>>YES</option>
 </select>
 </div>

 <div class="flex justify-between [padding-top:1rem] [margin-top:0.5rem] border-t border-slate-100">
 <button type="button" class="btn btn-outline-secondary [font-size:0.85rem] [padding:0.5rem_1.2rem]"
 onclick="goStep(1)"
 >
 &larr; Back
 </button>
 <button type="button" class="btn btn-primary bg-brand-700 [border-color:#1a3a6b] [font-size:0.85rem] [padding:0.5rem_1.4rem]"
 onclick="goStep(3)"
 >
 Continue &rarr;
 </button>
 </div>
</div>

<!-- ══════════════════════════════════════════════
 STEP 3 — Employment History
══════════════════════════════════════════════ -->
<div id="step3" class="wizard-step neon-card hidden [padding:1.25rem_1.5rem]">
 <div class="[font-size:0.7rem] font-bold uppercase [letter-spacing:0.07em] text-slate-400 mb-4 [padding-bottom:0.5rem] border-b border-slate-100">
 Employment History
 </div>
 <div class="row g-3">
 <!-- Current Employment -->
 <div class="col-lg-6">
 <div class="bg-slate-50 border border-slate-200 rounded-lg p-4">
 <div class="[font-size:0.68rem] font-bold text-slate-400 uppercase [letter-spacing:0.08em] [margin-bottom:0.75rem]">Current Employment</div>
 <div class="row g-2">
 <div class="col-md-6">
 <label class="form-label [font-size:0.78rem]">Faculty Rank <span class="text-danger">*</span></label>
 <input name="current_rank" class="form-control form-control-sm"
 value="<?= htmlspecialchars($old['current_rank'] ?? ($user['rank'] ?? '')) ?>"
 placeholder="Faculty Rank" required>
 </div>
 <div class="col-md-6">
 <label class="form-label [font-size:0.78rem]">Mode of Appointment <span class="text-danger">*</span></label>
 <input name="current_mode" class="form-control form-control-sm"
 value="<?= htmlspecialchars($old['current_mode'] ?? '') ?>"
 placeholder="Permanent / Temporary" required>
 </div>
 <div class="col-md-6">
 <label class="form-label [font-size:0.78rem]">Date of Appointment <span class="text-danger">*</span></label>
 <input type="date" name="current_date" class="form-control form-control-sm"
 value="<?= htmlspecialchars($old['current_date'] ?? '') ?>" required>
 </div>
 <div class="col-md-6">
 <label class="form-label [font-size:0.78rem]">SUC <span class="text-danger">*</span></label>
 <input name="current_suc" class="form-control form-control-sm"
 value="<?= htmlspecialchars($old['current_suc'] ?? 'Carlos Hilado Memorial State University (CHMSU)') ?>" required>
 </div>
 <div class="col-md-6">
 <label class="form-label [font-size:0.78rem]">Campus <span class="text-danger">*</span></label>
 <input name="current_campus" class="form-control form-control-sm"
 value="<?= htmlspecialchars($old['current_campus'] ?? ($campus_map[(int)($user['campus_id'] ?? 0)] ?? '')) ?>" required>
 </div>
 <div class="col-md-6">
 <label class="form-label [font-size:0.78rem]">Address <span class="text-danger">*</span></label>
 <input name="current_address" class="form-control form-control-sm"
 value="<?= htmlspecialchars($old['current_address'] ?? '') ?>" required>
 </div>
 </div>
 </div>
 </div>
 <!-- Previous Employment -->
 <div class="col-lg-6">
 <div class="bg-slate-50 border border-slate-200 rounded-lg p-4">
 <div class="[font-size:0.68rem] font-bold text-slate-400 uppercase [letter-spacing:0.08em] [margin-bottom:0.75rem]">Previous Employment</div>
 <div class="row g-2">
 <div class="col-md-6">
 <label class="form-label [font-size:0.78rem]">Previous Rank <span class="text-danger">*</span></label>
 <input name="previous_rank" class="form-control form-control-sm"
 value="<?= htmlspecialchars($old['previous_rank'] ?? '') ?>" placeholder="Previous Rank" required>
 </div>
 <div class="col-md-6">
 <label class="form-label [font-size:0.78rem]">Mode of Appointment <span class="text-danger">*</span></label>
 <input name="previous_mode" class="form-control form-control-sm"
 value="<?= htmlspecialchars($old['previous_mode'] ?? '') ?>" placeholder="Permanent / Temporary" required>
 </div>
 <div class="col-md-6">
 <label class="form-label [font-size:0.78rem]">Date of Appointment</label>
 <input type="date" name="previous_date" id="prevDate" class="form-control form-control-sm"
 value="<?= htmlspecialchars($old['previous_date'] ?? '') ?>">
 <label class="[font-size:0.72rem] text-slate-600 mt-1 flex [gap:0.35rem] cursor-pointer">
 <input type="checkbox" name="previous_date_na" <?= !empty($old['previous_date_na']) ? 'checked' : '' ?>
 onchange="document.getElementById('prevDate').disabled=this.checked"> N/A
 </label>
 </div>
 <div class="col-md-6">
 <label class="form-label [font-size:0.78rem]">Employment Sector <span class="text-danger">*</span></label>
 <input name="previous_sector" class="form-control form-control-sm"
 value="<?= htmlspecialchars($old['previous_sector'] ?? '') ?>" placeholder="Gov't / Private" required>
 </div>
 <div class="col-md-6">
 <label class="form-label [font-size:0.78rem]">SUC <span class="text-danger">*</span></label>
 <input name="previous_suc" class="form-control form-control-sm"
 value="<?= htmlspecialchars($old['previous_suc'] ?? '') ?>" required>
 </div>
 <div class="col-md-6">
 <label class="form-label [font-size:0.78rem]">Campus <span class="text-danger">*</span></label>
 <input name="previous_campus" class="form-control form-control-sm"
 value="<?= htmlspecialchars($old['previous_campus'] ?? '') ?>" required>
 </div>
 <div class="col-12">
 <label class="form-label [font-size:0.78rem]">Address <span class="text-danger">*</span></label>
 <input name="previous_address" class="form-control form-control-sm"
 value="<?= htmlspecialchars($old['previous_address'] ?? '') ?>" required>
 </div>
 </div>
 </div>
 </div>
 </div>

 <!-- Privacy acknowledgment moved to Step 4 review -->

 <div class="flex justify-between [padding-top:1rem] [margin-top:0.5rem] border-t border-slate-100">
 <button type="button" class="btn btn-outline-secondary [font-size:0.85rem] [padding:0.5rem_1.2rem]"
 onclick="goStep(2)"
 >
 &larr; Back
 </button>
 <button type="button" class="btn btn-primary bg-brand-700 [border-color:#1a3a6b] [font-size:0.85rem] [padding:0.5rem_1.4rem]"
 onclick="goStep(4)"
 >
 Review &rarr;
 </button>
 </div>
</div>

<!-- ══════════════════════════════════════════════
 STEP 4 — Review & Confirm
══════════════════════════════════════════════ -->
<div id="step4" class="wizard-step neon-card hidden [padding:1.25rem_1.5rem]">
 <div class="[font-size:0.7rem] font-bold uppercase [letter-spacing:0.07em] text-slate-400 mb-4 [padding-bottom:0.5rem] border-b border-slate-100">
 Review Your Information
 </div>
 <div class="[font-size:0.78rem] text-slate-500 [margin-bottom:1.25rem]">
 Check everything below before saving. Click <strong>Edit</strong> on any section to go back and make changes.
 </div>

 <!-- Review sections filled by JS -->
 <div id="rv_personal" class="rv-section mb-4"></div>
 <div id="rv_education" class="rv-section mb-4"></div>
 <div id="rv_employment" class="rv-section mb-4"></div>

 <!-- Privacy acknowledgment -->
 <div class="bg-slate-50 border border-slate-200 rounded-lg [padding:0.75rem_0.9rem] [margin-top:0.5rem] mb-4">
 <label class="flex [gap:0.5rem] items-start text-slate-600 [font-size:0.84rem] cursor-pointer">
 <input type="checkbox" name="privacy_ack" required class="mt-0.5 shrink-0">
 I acknowledge that the information I provide will be used for faculty reclassification processing and account administration.
 </label>
 </div>

 <div class="flex justify-between [padding-top:1rem] border-t border-slate-100">
 <button type="button" class="btn btn-outline-secondary [font-size:0.85rem] [padding:0.5rem_1.2rem]"
 onclick="goStep(3)"
 >
 &larr; Back
 </button>
 <button type="submit"
 class="bg-brand-700 border-0 text-white rounded-lg [font-size:0.85rem] font-bold [padding:0.5rem_1.6rem] cursor-pointer">
 Confirm &amp; Save Profile
 </button>
 </div>
</div>

</form>

<script>
(function() {
 const TOTAL = 4;
 let current = <?= (int)$error_step ?>;

 function goStep(n) {
 if (n > current) {
 const activeStep = document.getElementById('step' + current);
 const inputs = activeStep.querySelectorAll('[required]');
 for (const inp of inputs) {
 if (!inp.checkValidity()) { inp.reportValidity(); return; }
 }
 }
 current = n;
 if (n === 3) syncStep1ToEmployment();
 if (n === 4) buildReview();
 renderStep();
 }

 function syncStep1ToEmployment() {
 const rankSel = document.querySelector('select[name="rank"]');
 const currentRankInput = document.querySelector('input[name="current_rank"]');
 if (rankSel && currentRankInput && currentRankInput.value === '') {
 const v = rankSel.options[rankSel.selectedIndex]?.value || '';
 if (v) currentRankInput.value = v;
 }
 const campusSel = document.querySelector('select[name="campus_id"]');
 const currentCampusInput = document.querySelector('input[name="current_campus"]');
 if (campusSel && currentCampusInput && currentCampusInput.value === '') {
 const t = campusSel.options[campusSel.selectedIndex]?.text || '';
 if (t && t !== 'Select campus') currentCampusInput.value = t;
 }
 }

 // ── helpers ──────────────────────────────────────────────
 function esc(s) {
 return String(s ?? '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
 }
 function field(label, value) {
 const v = (value ?? '').toString().trim();
 return `<div class="[min-width:140px] flex-1">
 <div class="[font-size:0.65rem] font-bold uppercase [letter-spacing:0.05em] text-slate-400 [margin-bottom:2px]">${esc(label)}</div>
 <div class="text-sm ${v ? 'font-medium text-slate-800' : 'font-normal text-slate-300'}">${v ? esc(v) : '—'}</div>
 </div>`;
 }
 function section(title, stepNum, rows) {
 return `<div class="border border-slate-200 rounded-lg overflow-hidden mb-4">
 <div class="flex items-center justify-between [padding:0.55rem_0.9rem] bg-slate-50 border-b border-slate-100">
 <span class="[font-size:0.7rem] font-bold uppercase [letter-spacing:0.07em] text-slate-600">${esc(title)}</span>
 <button type="button" onclick="goStep(${stepNum})"
 class="bg-transparent border border-slate-200 rounded-md [padding:2px_10px] [font-size:0.72rem] font-semibold text-blue-800 cursor-pointer">
 Edit
 </button>
 </div>
 <div class="[padding:0.75rem_0.9rem] flex flex-wrap [gap:0.75rem_1.25rem]">${rows}</div>
 </div>`;
 }

 function buildReview() {
 // ── Step 1 ─────────────────────────────────────────
 const firstName = document.querySelector('input[name="first_name"]')?.value || '';
 const middleName = document.querySelector('input[name="middle_name"]')?.value || '';
 const lastName = document.querySelector('input[name="last_name"]')?.value || '';
 const suffix = document.querySelector('input[name="suffix"]')?.value || '';
 const empId = document.querySelector('input[name="employee_id"]')?.value || '';
 const rankSel = document.querySelector('select[name="rank"]');
 const rank = rankSel?.options[rankSel.selectedIndex]?.value || '';
 const campusSel = document.querySelector('select[name="campus_id"]');
 const campus = campusSel?.options[campusSel.selectedIndex]?.text || '';
 const statusSel = document.querySelector('select[name="faculty_status"]');
 const status = statusSel?.options[statusSel.selectedIndex]?.text || '';
 const emailEl = document.querySelector('#step1 input[type="email"]');
 const email = emailEl?.value || '';

 const fullName = [firstName, middleName, lastName, suffix].filter(Boolean).join(' ');

 let p1 = field('Full Name', fullName) + field('Email', email) +
 field('Employee ID', empId) + field('Campus', campus) +
 field('Faculty Rank', rank) + field('Status', status);
 document.getElementById('rv_personal').innerHTML = section('Personal & Account', 1, p1);

 // ── Step 2 ─────────────────────────────────────────
 const levels = [...document.querySelectorAll('input[name="edu_level[]"]')].map(e => e.value);
 const degrees = [...document.querySelectorAll('input[name="edu_degree[]"]')].map(e => e.value);
 const schools = [...document.querySelectorAll('input[name="edu_school[]"]')].map(e => e.value);
 const years = [...document.querySelectorAll('input[name="edu_year[]"]')].map(e => e.value);
 const labels = {Bachelor:'Undergrad', Master:'Masters', Doctorate:'Doctorate', PostDoctorate:'Post-doctorate'};
 const cycleEl = document.querySelector('select[name="applied_first_cycle"]');
 const cycle = cycleEl?.options[cycleEl.selectedIndex]?.text || '';

 let p2 = '';
 levels.forEach((lv, i) => {
 const lbl = labels[lv] || lv;
 const deg = degrees[i] || '—'; const sch = schools[i] || '—'; const yr = years[i] || '—';
 p2 += `<div class="w-full flex flex-wrap [gap:0.5rem_1.25rem] [padding-bottom:0.6rem] [border-bottom:1px_solid_#f8fafc] [margin-bottom:0.25rem]">
 <div class="w-full [font-size:0.68rem] font-bold text-brand-700">${esc(lbl)}</div>
 ${field('Degree', deg)}${field('School / SUC', sch)}${field('Year', yr)}
 </div>`;
 });
 p2 += field('Applied 1st Cycle', cycle);
 document.getElementById('rv_education').innerHTML = section('Education', 2, p2);

 // ── Step 3 ─────────────────────────────────────────
 const cv = n => document.querySelector(`input[name="${n}"]`)?.value || '';
 let p3 = `<div class="w-full [font-size:0.68rem] font-bold text-brand-700 [margin-bottom:0.25rem]">Current</div>`;
 p3 += field('Rank', cv('current_rank')) + field('Mode', cv('current_mode')) +
 field('Date', cv('current_date')) + field('SUC', cv('current_suc')) +
 field('Campus', cv('current_campus')) + field('Address', cv('current_address'));
 p3 += `<div class="w-full border-t border-slate-100 [margin:0.4rem_0]"></div>
 <div class="w-full [font-size:0.68rem] font-bold text-brand-700 [margin-bottom:0.25rem]">Previous</div>`;
 const prevDateNA = document.querySelector('input[name="previous_date_na"]')?.checked;
 p3 += field('Rank', cv('previous_rank')) + field('Mode', cv('previous_mode')) +
 field('Date', prevDateNA ? 'N/A' : cv('previous_date')) +
 field('Sector', cv('previous_sector')) + field('SUC', cv('previous_suc')) +
 field('Campus', cv('previous_campus')) + field('Address', cv('previous_address'));
 document.getElementById('rv_employment').innerHTML = section('Employment', 3, p3);
 }

 function renderStep() {
 for (let i = 1; i <= TOTAL; i++) {
 const el = document.getElementById('step' + i);
 if (el) el.classList.toggle('hidden', i !== current);

 const circle = document.getElementById('stepCircle' + i);
 const label = document.getElementById('stepLabel' + i);
 const line = document.getElementById('stepLine' + i);
 if (!circle) continue;

 if (i < current) {
 circle.style.background = '#1a3a6b';
 circle.style.color = '#fff';
 circle.style.borderColor = '#1a3a6b';
 circle.innerHTML = '<i class="bi bi-check-lg [font-size:0.75rem]"></i>';
 label.style.color = '#1a3a6b';
 if (line) line.style.background = '#1a3a6b';
 } else if (i === current) {
 circle.style.background = '#1e4d8c';
 circle.style.color = '#fff';
 circle.style.borderColor = '#1e4d8c';
 circle.innerHTML = String(i);
 label.style.color = '#1a3a6b';
 label.style.fontWeight = '700';
 if (line) line.style.background = '#e2e8f0';
 } else {
 circle.style.background = '#f1f5f9';
 circle.style.color = '#94a3b8';
 circle.style.borderColor = '#e2e8f0';
 circle.innerHTML = String(i);
 label.style.color = '#94a3b8';
 label.style.fontWeight = '600';
 if (line) line.style.background = '#e2e8f0';
 }
 }
 window.scrollTo({ top: 0, behavior: 'smooth' });
 }

 window.goStep = goStep;
 renderStep();
})();
</script>
