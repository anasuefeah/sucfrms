<?php require __DIR__ . '/../../../backend/controllers/shared/profile.php'; ?>

<?php showFlash(); ?>

<div class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-card">

    <!-- -- Banner -- -->
    <div class="relative h-32 w-full overflow-hidden bg-gradient-to-br from-brand-950 via-brand-800 to-brand-600 sm:h-40">
        <!-- Subtle pattern overlay -->
        <div class="absolute inset-0 bg-[radial-gradient(circle_at_20%_50%,rgba(255,255,255,.18)_1px,transparent_1px)] bg-[length:40px_40px]"></div>
        <div class="absolute inset-0 bg-gradient-to-b from-transparent to-brand-900/50"></div>
    </div>

    <!-- -- Avatar + Identity Row -- -->
    <div class="flex flex-wrap items-end gap-5 border-b border-slate-100 bg-white px-4 pb-5 sm:gap-6 sm:px-8">
        <div class="relative z-[2] -mt-12 shrink-0 sm:-mt-14">
            <?php if (!empty($user['profile_pic'])): ?>
              <img class="block size-24 rounded-full border-4 border-white object-cover object-center shadow-xl sm:size-30" id="profileAvatarImg" src="<?= sanitize($user['profile_pic']) ?>" alt="Profile">
            <?php elseif ($_SESSION['role'] === 'admin'): ?>
              <img src="frontend/assets/images/logo.jpg" alt="SUCFRMS Logo"
                  class="block size-24 rounded-full border-4 border-white object-cover object-center shadow-xl sm:size-30">
            <?php else: ?>
            <div class="flex size-24 items-center justify-center rounded-full border-4 border-white bg-gradient-to-br from-brand-800 to-brand-500 shadow-xl sm:size-30" id="profileAvatarPlaceholder">
                <span class="text-3xl font-bold tracking-wider text-white sm:text-4xl"><?= htmlspecialchars($initials) ?></span>
            </div>
            <?php endif; ?>

            <?php if ($_SESSION['role'] !== 'admin'): ?>
            <!-- Camera overlay -->
            <button type="button" onclick="document.getElementById('avatarFileInput').click()"
                    title="Change profile picture"
                    class="absolute right-1.5 bottom-1.5 z-[3] flex size-8 cursor-pointer items-center justify-center rounded-full border-2 border-white bg-brand-700 text-white shadow-lg transition hover:bg-brand-800">
                <i class="bi bi-camera-fill text-xs leading-none"></i>
            </button>
            <!-- Hidden instant-upload form -->
            <form id="avatarUploadForm" method="POST" action="index.php?page=profile" enctype="multipart/form-data" class="hidden">
                <input type="hidden" name="action"      value="update_info">
                <input type="hidden" name="first_name"  value="<?= htmlspecialchars($user['first_name']  ?? '') ?>">
                <input type="hidden" name="middle_name" value="<?= htmlspecialchars($user['middle_name'] ?? '') ?>">
                <input type="hidden" name="last_name"   value="<?= htmlspecialchars($user['last_name']   ?? '') ?>">
                <input type="hidden" name="suffix"      value="<?= htmlspecialchars($user['suffix']      ?? '') ?>">
                <input type="hidden" name="employee_id" value="<?= htmlspecialchars($user['employee_id'] ?? '') ?>">
                <input type="hidden" name="campus_id"   value="<?= htmlspecialchars($user['campus_id']   ?? '') ?>">
                <input type="hidden" name="rank"        value="<?= htmlspecialchars($user['rank']        ?? '') ?>">
                <input type="hidden" name="faculty_status" value="<?= htmlspecialchars($user['faculty_status'] ?? 'New Faculty') ?>">
                <input type="hidden" name="applied_first_cycle" value="<?= !empty($user['applied_first_cycle']) ? '1' : '0' ?>">
                <input type="file"   id="avatarFileInput" name="profile_pic" accept=".jpg,.jpeg,.png,.gif,.webp"
                       onchange="previewAndUploadAvatar(this)">
            </form>
            <?php endif; ?>
        </div>

        <div class="min-w-0 flex-1 py-2">
            <div class="flex flex-wrap items-end justify-between gap-4">
                <div>
                    <?php
                    $display_first  = trim($user['first_name']  ?? '');
                    $display_middle = trim($user['middle_name'] ?? '');
                    $display_last   = trim($user['last_name']   ?? '');
                    $display_mi     = $display_middle !== '' ? ' ' . strtoupper(substr($display_middle, 0, 1)) . '.' : '';
                    $display_name   = $display_first !== '' || $display_last !== ''
                        ? trim($display_first . $display_mi . ' ' . $display_last)
                        : htmlspecialchars($user['full_name'] ?? '');
                    ?>
                    <h2 class="mb-1 text-xl font-bold tracking-tight text-slate-900 sm:text-2xl"><?= htmlspecialchars($display_name) ?></h2>
                    <p class="mb-2 break-all text-sm text-slate-500"><?= htmlspecialchars($user['email']) ?></p>
                    <div class="flex flex-wrap items-center gap-2">
                        <span class="badge <?= $role === 'admin' ? 'bg-warning text-dark' : ($role === 'evaluator' ? 'bg-success' : 'bg-primary') ?>"><?= ucfirst($_SESSION['role']) ?></span>
                        <?php if ($campus_name): ?>
                        <span class="badge bg-secondary">
                            <i class="bi bi-building me-1"></i><?= htmlspecialchars($campus_name) ?>
                        </span>
                        <?php endif; ?>
                        <?php if (!empty($user['rank'])): ?>
                        <span class="badge bg-secondary">
                            <i class="bi bi-award me-1"></i><?= htmlspecialchars($user['rank']) ?>
                        </span>
                        <?php endif; ?>
                        <span class="badge bg-success">
                            <i class="bi bi-circle-fill text-[0.4rem]"></i>Active
                        </span>
                    </div>
                </div>
                <!-- Settings icon button -->
                <button onclick="pfOpenModal()"
                        title="Account Settings"
                        class="flex size-10 shrink-0 cursor-pointer items-center justify-center rounded-xl border border-slate-200 bg-slate-50 text-slate-600 transition hover:border-brand-700 hover:bg-brand-700 hover:text-white">
                    <i class="bi bi-gear-fill"></i>
                </button>
            </div>
        </div>
    </div>

    <!-- Stats + Latest App -->
    <div class="border-b border-slate-100 bg-white px-4 py-5 sm:px-8 sm:pb-6">

        <div class="mb-3 grid gap-3 sm:grid-cols-2 lg:grid-cols-3">

            <div class="flex min-w-0 items-center gap-3 rounded-xl border border-slate-100 bg-slate-50 px-4 py-3">
                <div class="flex size-9 shrink-0 items-center justify-center rounded-lg bg-brand-50 text-brand-700">
                    <i class="bi bi-person-badge"></i>
                </div>
                <div>
                    <div class="text-[0.6875rem] font-bold tracking-wide text-slate-400 uppercase">Employee ID</div>
                    <div class="mt-px font-bold text-slate-900"><?= htmlspecialchars($user['employee_id'] ?? ' - ') ?></div>
                </div>
            </div>

            <div class="flex min-w-0 items-center gap-3 rounded-xl border border-slate-100 bg-slate-50 px-4 py-3">
                <div class="flex size-9 shrink-0 items-center justify-center rounded-lg bg-brand-50 text-brand-700">
                    <i class="bi bi-calendar-check"></i>
                </div>
                <div>
                    <div class="text-[0.6875rem] font-bold tracking-wide text-slate-400 uppercase">Member Since</div>
                    <div class="mt-px font-bold text-slate-900"><?= $since ?></div>
                </div>
            </div>

            <?php if ($_SESSION['role'] === 'faculty'): ?>
            <div class="flex min-w-0 items-center gap-3 rounded-xl border border-slate-100 bg-slate-50 px-4 py-3">
                <div class="flex size-9 shrink-0 items-center justify-center rounded-lg <?= ($latest && $latest['weighted_score'] >= 41) ? 'bg-emerald-50 text-emerald-600' : 'bg-brand-50 text-brand-700' ?>">
                    <i class="bi bi-bar-chart-fill"></i>
                </div>
                <div>
                    <div class="text-[0.6875rem] font-bold tracking-wide text-slate-400 uppercase">Weighted Score</div>
                    <?php if ($latest && $latest['weighted_score'] > 0): ?>
                    <div class="mt-px text-lg leading-tight font-extrabold text-brand-800">
                        <?= number_format($latest['weighted_score'], 2) ?>
                        <span class="text-[0.6875rem] font-medium text-slate-400"> /100</span>
                    </div>
                    <?php else: ?>
                    <div class="mt-px text-sm font-semibold text-slate-300">No data yet</div>
                    <?php endif; ?>
                </div>
            </div>
            <?php endif; ?>

        </div>

        <?php if ($_SESSION['role'] === 'faculty' && $latest): ?>
        <div class="flex flex-wrap items-center justify-between gap-3 rounded-xl border border-l-[3px] border-slate-100 border-l-brand-700 bg-slate-50 px-4 py-3">
            <div class="flex items-center gap-3">
                <i class="bi bi-file-earmark-text shrink-0 text-brand-700"></i>
                <div>
                    <div class="text-[0.6875rem] font-bold tracking-wide text-slate-400 uppercase">Latest Application</div>
                    <div class="mt-px text-sm font-semibold text-slate-800"><?= htmlspecialchars($latest['cycle_name'] ?? ' - ') ?></div>
                </div>
            </div>
            <div><?= statusBadge($latest['status']) ?></div>
        </div>
        <?php endif; ?>

    </div>

    <?php if ($_SESSION['role'] === 'faculty'): ?>
    <div class="border-b border-slate-100 bg-white px-4 py-5 sm:px-8 sm:pb-6">
        <div class="mb-4 flex flex-wrap items-center justify-between gap-4">
            <div>
                <h2 class="m-0 text-base font-bold text-brand-800">Faculty Details</h2>
                <p class="m-0 mt-0.5 text-xs text-slate-400">Education and employment information from your profile entry</p>
            </div>
        </div>

        <div class="mb-4 flex gap-2 overflow-x-auto pb-1">
            <button type="button" class="pf-detail-tab active whitespace-nowrap rounded-lg border border-brand-800 bg-brand-800 px-3.5 py-2 text-xs font-bold text-white transition" data-tab="edu" onclick="pfDetailTab('edu')">
                Educational Attainment
            </button>
            <button type="button" class="pf-detail-tab whitespace-nowrap rounded-lg border border-slate-200 bg-slate-50 px-3.5 py-2 text-xs font-bold text-slate-600 transition" data-tab="emp" onclick="pfDetailTab('emp')">
                Employment History
            </button>
        </div>

        <div id="pf-detail-edu">
            <div class="rounded-xl border border-slate-100 bg-slate-50 p-4">
                <?php foreach ($education_levels as $level_key => $level_label):
                    $ed = $education_by_level[$level_key] ?? [];
                ?>
                <div class="grid gap-2 border-b border-slate-200 py-3 first:pt-0 md:grid-cols-[9.375rem_1fr] md:gap-4">
                    <div class="text-sm font-extrabold text-slate-800"><?= htmlspecialchars($level_label) ?></div>
                    <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-[1.3fr_1.3fr_.8fr]">
                        <div><span class="mb-1 block text-xs text-slate-400">Name of Degree</span><strong class="block break-words text-sm font-semibold text-slate-800"><?= $pfVal($ed['degree_program'] ?? '') ?></strong></div>
                        <div><span class="mb-1 block text-xs text-slate-400">Name of SUC</span><strong class="block break-words text-sm font-semibold text-slate-800"><?= $pfVal($ed['school_university'] ?? '') ?></strong></div>
                        <div><span class="mb-1 block text-xs text-slate-400">Year Graduated</span><strong class="block break-words text-sm font-semibold text-slate-800"><?= $pfVal($ed['year_graduated'] ?? '') ?></strong></div>
                    </div>
                </div>
                <?php endforeach; ?>
                <div class="mt-2 flex justify-between gap-4 py-2 text-sm">
                    <span class="shrink-0 text-slate-400">Applied during 1st Cycle</span>
                    <strong class="text-right font-semibold text-slate-800"><?= !empty($user['applied_first_cycle']) ? 'YES' : 'NO' ?></strong>
                </div>
            </div>
        </div>

        <div id="pf-detail-emp" class="hidden">
            <div class="grid gap-4 md:grid-cols-2">
                <?php foreach ([['CURRENT EMPLOYMENT',$employment['current'] ?? []],['PREVIOUS EMPLOYMENT',$employment['previous'] ?? []]] as [$heading, $row]): ?>
                <div class="rounded-xl border border-slate-100 bg-slate-50 p-4">
                    <div class="mb-1 border-b border-slate-200 pb-2 text-xs font-extrabold tracking-widest text-slate-400 uppercase"><?= $heading ?></div>
                    <?php
                    $items = $heading === 'CURRENT EMPLOYMENT'
                        ? [['Faculty Rank',$row['rank'] ?? ''],['Mode of Appointment',$row['mode_of_appointment'] ?? ''],['Date of Appointment',$pfDate($row['date_of_appointment'] ?? '')],['SUC',$row['suc'] ?? ''],['Campus',$row['campus'] ?? ''],['Address',$row['address'] ?? '']]
                        : [['Previous Rank',$row['rank'] ?? ''],['Mode of Appointment',$row['mode_of_appointment'] ?? ''],['Date of Appointment',$pfDate($row['date_of_appointment'] ?? '')],['Employment Sector',$row['employment_sector'] ?? ''],['SUC',$row['suc'] ?? ''],['Campus',$row['campus'] ?? ''],['Address',$row['address'] ?? '']];
                    foreach ($items as [$label, $value]):
                    ?>
                    <div class="flex justify-between gap-4 border-b border-slate-200 py-2 text-sm last:border-b-0"><span class="shrink-0 text-slate-400"><?= $label ?></span><strong class="max-w-[58%] break-words text-right font-semibold text-slate-800"><?= $pfVal($value) ?></strong></div>
                    <?php endforeach; ?>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
    <?php endif; ?>

</div><!-- /profile-page -->


<!-- -- Edit Profile Modal -- -->
<div id="pf-edit-modal" class="fixed inset-0 z-[9999] hidden items-center justify-center bg-slate-950/50 p-4 backdrop-blur-[2px]">
    <div class="max-h-[90vh] w-full max-w-2xl overflow-y-auto rounded-2xl bg-white shadow-pop">

        <div class="flex items-center justify-between border-b border-slate-200 px-5 py-4 sm:px-6">
            <h2 class="m-0 text-base font-bold text-slate-800">Edit Profile</h2>
            <button onclick="pfCloseModal()"
                    class="btn-close" aria-label="Close"></button>
        </div>

        <div class="flex border-b border-slate-200 px-5 sm:px-6">
            <button class="pf-modal-tab active -mb-px cursor-pointer border-0 border-b-2 border-brand-700 bg-transparent px-4 py-3 text-sm font-semibold text-brand-800" onclick="pfModalTab(this,'pf-mt-info')">
                Account Settings
            </button>
            <button class="pf-modal-tab -mb-px cursor-pointer border-0 border-b-2 border-transparent bg-transparent px-4 py-3 text-sm font-medium text-slate-500" onclick="pfModalTab(this,'pf-mt-pw')">
                Change Password
            </button>
        </div>

        <div id="pf-mt-info" class="p-5 sm:p-6">
            <form method="POST" action="index.php?page=profile" enctype="multipart/form-data">
                <input type="hidden" name="action" value="update_info">
                <?php if ($_SESSION['role'] !== 'admin'): ?>
                <!-- Profile picture is changed via the camera icon on the avatar -->
                <?php endif; ?>
                <div class="row g-3">
                    <div class="col-md-4">
                        <label class="form-label">First Name <span class="text-danger">*</span></label>
                        <input type="text" name="first_name" class="form-control"
                               value="<?= htmlspecialchars($user['first_name'] ?? '') ?>" required>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Middle Name</label>
                        <input type="text" name="middle_name" class="form-control"
                               value="<?= htmlspecialchars($user['middle_name'] ?? '') ?>"
                               placeholder="Leave blank if none">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Last Name <span class="text-danger">*</span></label>
                        <input type="text" name="last_name" class="form-control"
                               value="<?= htmlspecialchars($user['last_name'] ?? '') ?>" required>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Suffix</label>
                        <input type="text" name="suffix" class="form-control"
                               value="<?= htmlspecialchars($user['suffix'] ?? '') ?>"
                               placeholder="Optional">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Email</label>
                        <div class="input-group">
                            <span class="input-group-text"><i class="bi bi-lock-fill"></i></span>
                            <input type="email" class="form-control" value="<?= htmlspecialchars($user['email']) ?>" readonly>
                        </div>
                        <div class="mt-1 text-xs text-slate-400">Email can only be changed by the Administrator.</div>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Employee ID</label>
                        <?php if ($_SESSION['role'] === 'faculty'): ?>
                        <input type="text" name="employee_id" class="form-control" value="<?= htmlspecialchars($user['employee_id'] ?? '') ?>">
                        <?php else: ?>
                        <input type="text" class="form-control" value="<?= htmlspecialchars($user['employee_id'] ?? '') ?>" disabled>
                        <?php endif; ?>
                    </div>
                    <?php if ($_SESSION['role'] !== 'admin'): ?>
                    <div class="col-md-6">
                        <label class="form-label">Campus</label>
                        <select name="campus_id" class="form-select">
                            <option value="" disabled <?= empty($user['campus_id']) ? 'selected' : '' ?>>- Select Campus -</option>
                            <?php
                            $campuses = $pdo->query("SELECT * FROM campuses WHERE is_active=1 ORDER BY campus_name")->fetchAll();
                            foreach ($campuses as $c):
                            ?>
                            <option value="<?= $c['campus_id'] ?>" <?= ($user['campus_id'] ?? 0) == $c['campus_id'] ? 'selected' : '' ?>>
                                <?= sanitize($c['campus_name']) ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Faculty Rank</label>
                        <select name="rank" class="form-select">
                            <option value="" disabled <?= empty($user['rank']) ? 'selected' : '' ?>>- Select Rank -</option>
                            <?php foreach (facultyRanks() as $r): ?>
                            <option value="<?= $r ?>" <?= ($user['rank'] ?? '') === $r ? 'selected' : '' ?>><?= $r ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <?php if ($_SESSION['role'] === 'faculty'): ?>
                    <div class="col-md-6">
                        <label class="form-label">Faculty Status</label>
                        <select name="faculty_status" class="form-select">
                            <option value="Existing Faculty" <?= ($user['faculty_status'] ?? '') === 'Existing Faculty' ? 'selected' : '' ?>>Existing Faculty</option>
                            <option value="New Faculty" <?= ($user['faculty_status'] ?? 'New Faculty') === 'New Faculty' ? 'selected' : '' ?>>New Faculty</option>
                        </select>
                    </div>
                    <div class="col-12">
                        <div class="mt-2 border-t border-slate-100 pt-4">
                            <div class="mb-3 text-xs font-bold tracking-wider text-slate-600 uppercase">Educational Attainment</div>
                            <div class="flex flex-col gap-3">
                                <?php foreach ($education_levels as $level_key => $level_label):
                                    $ed = $education_by_level[$level_key] ?? [];
                                    $is_required = $level_key === 'Bachelor';
                                ?>
                                <div class="rounded-xl border border-slate-100 bg-slate-50 p-4">
                                    <input type="hidden" name="edu_level[]" value="<?= htmlspecialchars($level_key) ?>">
                                    <div class="mb-3 text-sm font-extrabold text-brand-800"><?= htmlspecialchars($level_label) ?></div>
                                    <div class="grid gap-3 md:grid-cols-[1.3fr_1.3fr_.8fr]">
                                        <div>
                                            <label class="form-label">Name of Degree<?= $is_required ? ' *' : '' ?></label>
                                            <input name="edu_degree[]" class="form-control" value="<?= htmlspecialchars($ed['degree_program'] ?? '') ?>" placeholder="<?= $is_required ? 'Bachelor of Science in...' : 'N/A if none' ?>" <?= $is_required ? 'required' : '' ?>>
                                        </div>
                                        <div>
                                            <label class="form-label">Name of SUC<?= $is_required ? ' *' : '' ?></label>
                                            <input name="edu_school[]" class="form-control" value="<?= htmlspecialchars($ed['school_university'] ?? '') ?>" placeholder="<?= $is_required ? 'School / University' : 'N/A if none' ?>" <?= $is_required ? 'required' : '' ?>>
                                        </div>
                                        <div>
                                            <label class="form-label">Year Graduated<?= $is_required ? ' *' : '' ?></label>
                                            <input name="edu_year[]" class="form-control" value="<?= htmlspecialchars($ed['year_graduated'] ?? '') ?>" placeholder="<?= $is_required ? 'YYYY' : 'N/A' ?>" <?= $is_required ? 'required' : '' ?>>
                                        </div>
                                    </div>
                                </div>
                                <?php endforeach; ?>
                            </div>
                            <div class="mt-4">
                                <label class="form-label">Applied during 1st Cycle</label>
                                <select name="applied_first_cycle" class="form-select max-w-56">
                                    <option value="0" <?= empty($user['applied_first_cycle']) ? 'selected' : '' ?>>NO</option>
                                    <option value="1" <?= !empty($user['applied_first_cycle']) ? 'selected' : '' ?>>YES</option>
                                </select>
                            </div>
                        </div>
                    </div>
                    <?php endif; ?>
                    <?php endif; ?>
                </div>
                <div class="mt-6 d-flex gap-2">
                    <button type="submit" class="btn btn-primary">Save Changes</button>
                    <button type="button" onclick="pfCloseModal()" class="btn btn-outline-secondary">Cancel</button>
                </div>
            </form>
        </div>

        <div id="pf-mt-pw" class="hidden p-5 sm:p-6">
            <form method="POST" action="index.php?page=profile">
                <input type="hidden" name="action" value="change_password">
                <div class="row g-3">
                    <div class="col-md-6">
                        <label class="form-label">New Password</label>
                        <div class="input-group">
                            <input type="password" name="new_password" id="pfNewPassword" class="form-control"
                                   placeholder="e.g. Anasue@123" required
                                   oninput="pfCheckPw(this.value)">
                            <button type="button" class="btn btn-outline-secondary" onclick="pfTogglePw('pfNewPassword', this)"
                                    tabindex="-1">
                                <i class="bi bi-eye"></i>
                            </button>
                        </div>
                        <div class="mt-2 flex gap-1">
                            <div class="pf-pw-bar h-1 flex-1 rounded-full bg-slate-200 transition-colors"></div>
                            <div class="pf-pw-bar h-1 flex-1 rounded-full bg-slate-200 transition-colors"></div>
                            <div class="pf-pw-bar h-1 flex-1 rounded-full bg-slate-200 transition-colors"></div>
                            <div class="pf-pw-bar h-1 flex-1 rounded-full bg-slate-200 transition-colors"></div>
                        </div>
                        <ul class="mt-2 list-disc pl-5 text-xs leading-5 text-slate-400">
                            <li id="pf-req-len"  >At least 8 characters</li>
                            <li id="pf-req-num"  >A number</li>
                            <li id="pf-req-sym"  >A special character (e.g. @, #, !)</li>
                            <li id="pf-req-case" >Both uppercase and lowercase letters</li>
                        </ul>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Confirm New Password</label>
                        <div class="input-group">
                            <input type="password" name="confirm_password" id="pfConfirmPassword" class="form-control" required>
                            <button type="button" class="btn btn-outline-secondary" onclick="pfTogglePw('pfConfirmPassword', this)"
                                    tabindex="-1">
                                <i class="bi bi-eye"></i>
                            </button>
                        </div>
                    </div>
                </div>
                <div class="mt-6 d-flex gap-2">
                    <button type="submit" class="btn btn-primary">Update Password</button>
                    <button type="button" onclick="pfCloseModal()" class="btn btn-outline-secondary">Cancel</button>
                </div>
            </form>
        </div>

    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    if (sessionStorage.getItem('openPwTab') === '1') {
        sessionStorage.removeItem('openPwTab');
        const modal = document.getElementById('pf-edit-modal');
        if (modal) {
            pfOpenModal();
            const pwTab = document.querySelector('.pf-modal-tab:nth-child(2)');
            if (pwTab) pwTab.click();
        }
    }
});

function pfOpenModal() {
    const modal = document.getElementById('pf-edit-modal');
    modal.classList.remove('hidden');
    modal.classList.add('flex');
}

function pfCloseModal() {
    const modal = document.getElementById('pf-edit-modal');
    modal.classList.add('hidden');
    modal.classList.remove('flex');
}

function pfTogglePw(inputId, btn) {
    const input = document.getElementById(inputId);
    const icon  = btn.querySelector('i');
    if (input.type === 'password') {
        input.type = 'text';
        icon.classList.replace('bi-eye', 'bi-eye-slash');
    } else {
        input.type = 'password';
        icon.classList.replace('bi-eye-slash', 'bi-eye');
    }
}

function pfCheckPw(val) {
    const bars    = document.querySelectorAll('.pf-pw-bar');
    const hasLen  = val.length >= 8;
    const hasNum  = /[0-9]/.test(val);
    const hasSym  = /[^a-zA-Z0-9]/.test(val);
    const hasLow  = /[a-z]/.test(val);
    const hasUpp  = /[A-Z]/.test(val);
    const hasCase = hasLow && hasUpp;

    const mark = (id, met) => {
        const el = document.getElementById(id);
        if (!el) return;
        el.classList.toggle('text-brand-700', met);
        el.classList.toggle('font-semibold', met);
        el.classList.toggle('text-slate-400', !met);
    };
    mark('pf-req-len',  hasLen);
    mark('pf-req-num',  hasNum);
    mark('pf-req-sym',  hasSym);
    mark('pf-req-case', hasCase);

    let score = [hasLen, hasNum, hasSym, hasCase].filter(Boolean).length;
    const activeClasses = ['bg-slate-700', 'bg-slate-600', 'bg-brand-600', 'bg-brand-500'];
    bars.forEach((b, i) => {
        b.classList.remove('bg-slate-200', ...activeClasses);
        b.classList.add(i < score ? activeClasses[score - 1] : 'bg-slate-200');
    });
}

function pfModalTab(btn, paneId) {
    document.querySelectorAll('.pf-modal-tab').forEach(t => {
        t.classList.remove('border-brand-700', 'text-brand-800', 'font-semibold');
        t.classList.add('border-transparent', 'text-slate-500', 'font-medium');
        t.classList.remove('active');
    });
    document.querySelectorAll('#pf-edit-modal > div > div[id^="pf-mt"]').forEach(p => p.classList.add('hidden'));
    btn.classList.remove('border-transparent', 'text-slate-500', 'font-medium');
    btn.classList.add('border-brand-700', 'text-brand-800', 'font-semibold');
    btn.classList.add('active');
    document.getElementById(paneId).classList.remove('hidden');
}

document.getElementById('pf-edit-modal').addEventListener('click', function(e) {
    if (e.target === this) pfCloseModal();
});

function pfDetailTab(tab) {
    document.querySelectorAll('.pf-detail-tab').forEach(btn => {
        const active = btn.dataset.tab === tab;
        btn.classList.toggle('active', active);
        btn.classList.toggle('border-brand-800', active);
        btn.classList.toggle('bg-brand-800', active);
        btn.classList.toggle('text-white', active);
        btn.classList.toggle('border-slate-200', !active);
        btn.classList.toggle('bg-slate-50', !active);
        btn.classList.toggle('text-slate-600', !active);
    });
    const edu = document.getElementById('pf-detail-edu');
    const emp = document.getElementById('pf-detail-emp');
    if (edu) edu.classList.toggle('hidden', tab !== 'edu');
    if (emp) emp.classList.toggle('hidden', tab !== 'emp');
}

function previewAndUploadAvatar(input) {
    if (!input.files || !input.files[0]) return;
    const file = input.files[0];
    // Show instant preview before uploading
    const reader = new FileReader();
    reader.onload = function(e) {
        const img = document.getElementById('profileAvatarImg');
        const placeholder = document.getElementById('profileAvatarPlaceholder');
        if (img) {
            img.src = e.target.result;
        } else if (placeholder) {
            // Replace placeholder div with an img
            const newImg = document.createElement('img');
            newImg.id = 'profileAvatarImg';
            newImg.src = e.target.result;
            newImg.alt = 'Profile';
            newImg.className = 'block size-24 rounded-full border-4 border-white object-cover object-center shadow-xl sm:size-30';
            placeholder.replaceWith(newImg);
        }
    };
    reader.readAsDataURL(file);
    // Submit the hidden form
    document.getElementById('avatarUploadForm').submit();
}
</script>

