<?php if ((require __DIR__ . '/../../../backend/controllers/admin/cycles.php') === false) return; ?>

<?php showFlash(); ?>

<!-- Page Header -->
<div class="flex items-center justify-between flex-wrap [gap:1rem] [margin-bottom:1.5rem]">
    <div class="flex items-center [gap:0.6rem]">
        <div class="[width:36px] [height:36px] [border-radius:8px] [background:#1e4d8c] flex items-center justify-center shrink-0">
            <i class="bi bi-calendar-range [color:#fff] [font-size:0.9rem]"></i>
        </div>
        <div>
            <div class="font-bold [color:#1a3a6b] [font-size:0.95rem]">Cycles &amp; Scoring Criteria</div>
            <div class="[font-size:0.72rem] [color:#94a3b8] [margin-top:1px]">Manage evaluation cycles and configure scoring criteria per faculty position</div>
        </div>
    </div>
    <button class="btn btn-primary btn-sm [background:#1a3a6b] border-0 [border-radius:8px] [padding:0.45rem_1.1rem] [font-size:0.82rem] font-semibold" data-bs-toggle="collapse" data-bs-target="#createCyclePanel"
           >
        <i class="bi bi-plus-lg me-1"></i>New Cycle
    </button>
</div>

<!-- Create Cycle (collapsible - closed by default) -->
<div class="collapse mb-6" id="createCyclePanel">
    <div class="neon-card p-0 overflow-hidden [border:1px_solid_#e2e8f0]">

        <!-- Panel header -->
        <div class="[padding:0.85rem_1.25rem] [border-bottom:1px_solid_#f1f5f9] [background:#f8fafc] flex items-center justify-between">
            <div class="flex items-center [gap:0.5rem]">
                <i class="bi bi-plus-circle [color:#1e4d8c] [font-size:0.9rem]"></i>
                <span class="font-bold [color:#1a3a6b] [font-size:0.88rem]">Create New Cycle</span>
            </div>
            <button type="button" class="btn-close btn-close-sm [font-size:0.65rem] [opacity:0.4]"
                    onclick="document.getElementById('createCyclePanel').classList.remove('show')"
                    aria-label="Close"></button>
        </div>

        <div class="[padding:1.25rem_1.5rem]">

            <?php
            $open_cycle = null;
            foreach ($cycles as $cy) { if ($cy['status'] === 'open') { $open_cycle = $cy; break; } }
            if ($open_cycle):
            ?>
            <div class="flex items-start [gap:0.5rem] [background:#fffbeb] [border:1px_solid_#fde68a] [border-radius:7px] [padding:0.55rem_0.85rem] [margin-bottom:1.1rem] [font-size:0.78rem] [color:#92400e]">
                <i class="bi bi-exclamation-triangle-fill [color:#d97706] shrink-0 [margin-top:1px]"></i>
                <span>Creating a new cycle will <strong>automatically close</strong> the currently open cycle: <strong><?= sanitize($open_cycle['cycle_name']) ?></strong>.</span>
            </div>
            <?php endif; ?>

            <form method="POST" id="createCycleForm">
                <input type="hidden" name="action" value="create_cycle">

                <!-- Cycle name -->
                <div class="[margin-bottom:1.1rem]">
                    <label class="form-label fw-semibold [font-size:0.8rem] [color:#334155]">
                        Cycle Name <span class="text-danger">*</span>
                    </label>
                    <input type="text" name="cycle_name" id="cc_name" class="form-control [font-size:0.88rem]" required
                           placeholder="e.g. AY 2026-2029 Reclassification Cycle"
                          >
                    <div class="form-text [font-size:0.73rem]">
                        The cycle will be set to <strong>Open</strong> immediately. Close it from the status control when submissions end.
                    </div>
                </div>

                <!-- Date section label -->
                <div class="[font-size:0.7rem] font-bold uppercase [letter-spacing:0.07em] [color:#94a3b8] [margin-bottom:0.6rem] [padding-bottom:0.4rem] [border-bottom:1px_solid_#f1f5f9]">
                    Cycle Period
                </div>
                <div class="row g-2 mb-4">
                    <div class="col-sm-6">
                        <label class="form-label fw-semibold [font-size:0.78rem] [color:#334155]">
                            Start Date <span class="text-danger">*</span>
                        </label>
                        <input type="date" name="start_date" id="cc_start" class="form-control form-control-sm" required>
                    </div>
                    <div class="col-sm-6">
                        <label class="form-label fw-semibold [font-size:0.78rem] [color:#334155]">
                            End Date <span class="text-danger">*</span>
                        </label>
                        <input type="date" name="end_date" id="cc_end" class="form-control form-control-sm" required>
                    </div>
                </div>

                <!-- Date section label -->
                <div class="[font-size:0.7rem] font-bold uppercase [letter-spacing:0.07em] [color:#94a3b8] [margin-bottom:0.6rem] [padding-bottom:0.4rem] [border-bottom:1px_solid_#f1f5f9]">
                    Faculty Submission Window
                </div>
                <div class="row g-2 mb-4">
                    <div class="col-sm-6">
                        <label class="form-label fw-semibold [font-size:0.78rem] [color:#334155]">
                            Submission Start <span class="text-danger">*</span>
                        </label>
                        <input type="date" name="submission_start_date" id="cc_sub_start" class="form-control form-control-sm" required>
                    </div>
                    <div class="col-sm-6">
                        <label class="form-label fw-semibold [font-size:0.78rem] [color:#334155]">
                            Submission Deadline <span class="text-danger">*</span>
                        </label>
                        <input type="date" name="submission_deadline" id="cc_deadline" class="form-control form-control-sm" required>
                        <div class="form-text [font-size:0.72rem]">Faculty cannot submit after this date.</div>
                    </div>
                </div>

                <!-- Date section label -->
                <div class="[font-size:0.7rem] font-bold uppercase [letter-spacing:0.07em] [color:#94a3b8] [margin-bottom:0.6rem] [padding-bottom:0.4rem] [border-bottom:1px_solid_#f1f5f9]">
                    Deadlines
                </div>
                <div class="row g-2 mb-4">
                    <div class="col-sm-6">
                        <label class="form-label fw-semibold [font-size:0.78rem] [color:#334155]">
                            Evaluation Deadline <span class="text-danger">*</span>
                        </label>
                        <input type="date" name="evaluation_deadline" id="cc_eval_deadline" class="form-control form-control-sm" required>
                    </div>
                    <div class="col-sm-6">
                        <label class="form-label fw-semibold [font-size:0.78rem] [color:#334155]">
                            Appeal Deadline <span class="[color:#94a3b8] font-normal">(optional)</span>
                        </label>
                        <input type="date" name="appeal_deadline" id="cc_appeal_deadline" class="form-control form-control-sm">
                        <div class="form-text [font-size:0.72rem]">Leave blank to allow appeals while the cycle is open.</div>
                    </div>
                </div>

                <!-- Inline error -->
                <div id="cycle_date_error" hidden class="mb-4">
                    <div class="alert alert-danger py-2 mb-0 [font-size:0.8rem]">
                        <i class="bi bi-exclamation-circle me-1"></i>
                        <span id="cycle_date_error_msg"></span>
                    </div>
                </div>

                <!-- Actions -->
                <div class="flex items-center [gap:0.5rem] [padding-top:0.75rem] [border-top:1px_solid_#f1f5f9]">
                    <button type="button" class="btn btn-primary btn-sm [background:#1a3a6b] [border-color:#1a3a6b] [font-size:0.82rem] [padding:0.42rem_1rem]" onclick="openCycleReview()"
                           >
                        <i class="bi bi-eye me-1"></i>Review &amp; Create
                    </button>
                    <button type="button" class="btn btn-outline-secondary btn-sm [font-size:0.82rem] [padding:0.42rem_0.85rem]"
                            onclick="document.getElementById('createCyclePanel').classList.remove('show')">
                        Cancel
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<div class="row g-3">

    <!-- LEFT: Cycle list -->
    <div class="col-lg-3">        <div class="neon-card h-full p-0 overflow-hidden">
            <div class="[padding:0.75rem_1rem] [background:#1a3a6b]">
                <div class="[font-size:0.78rem] font-bold [color:#fff] uppercase [letter-spacing:0.05em] flex items-center [gap:0.5rem]">
                    <i class="bi bi-list-ul"></i>All Cycles
                </div>
            </div>
            <?php if ($cycles): ?>
            <div class="list-group list-group-flush overflow-y-auto [max-height:70vh]">
            <?php foreach ($cycles as $c):
                $is_sel = ($c['cycle_id'] === $active_cycle_id);
                $sc_map = ['open'=>'success','closed'=>'secondary','archived'=>'dark'];
            ?>
            <a href="?page=cycles&cycle_id=<?= $c['cycle_id'] ?>"
               class="list-group-item list-group-item-action border-l-4 px-4 py-4 <?= $is_sel ? 'border-l-brand-600 bg-brand-50' : 'border-l-transparent bg-white' ?>">
                <div class="d-flex justify-content-between align-items-start gap-2">
                    <div class="flex-grow-1 min-w-0">
                        <div class="truncate text-sm font-semibold <?= $is_sel ? 'text-brand-700' : 'text-slate-800' ?>">
                            <?= sanitize($c['cycle_name']) ?>
                        </div>
                        <div class="small mt-1 [color:#64748b]">
                            <?php if (!empty($c['start_date']) && !empty($c['end_date'])): ?>
                            <?= date('Y', strtotime($c['start_date'])) ?> - <?= date('Y', strtotime($c['end_date'])) ?>
                            <?php endif; ?>
                        </div>
                        <div class="d-flex gap-1 mt-2 flex-wrap">
                            <span class="badge bg-<?= $sc_map[$c['status']] ?> text-[0.65rem]"><?= ucfirst($c['status']) ?></span>
                            <?php if ($c['criteria_count'] > 0): ?>
                            <span class="badge [background:#dbeafe] [color:#1e4d8c] [font-size:0.65rem]"><?= $c['criteria_count'] ?> criteria</span>
                            <?php else: ?>
                            <span class="badge bg-warning text-dark [font-size:0.65rem]">No criteria</span>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="text-end [font-size:0.72rem] [color:#94a3b8] whitespace-nowrap shrink-0">
                        <div><?= $c['applied'] ?> applied</div>
                        <div><?= $c['reclassified'] ?> evaluation complete</div>
                    </div>
                </div>
            </a>
            <?php endforeach; ?>
            </div>
            <?php else: ?>
            <div class="p-6 text-center text-muted small">No cycles yet. Create one above.</div>
            <?php endif; ?>
        </div>
    </div>

    <!-- RIGHT: Cycle detail + criteria -->
    <div class="col-lg-9">
    <?php if ($panel_cycle): ?>

        <!-- Cycle header card -->
        <div class="neon-card mb-4 p-0 overflow-hidden">
            <div class="[padding:0.75rem_1.25rem] [background:#1a3a6b]">
                <div class="flex items-center justify-between [gap:0.75rem] flex-wrap">
                    <div class="font-bold [color:#fff] [font-size:0.95rem]">
                        <?= sanitize($panel_cycle['cycle_name']) ?>
                    </div>
                    <?php
                    $sc_map2 = ['open'=>['#f0fdf4','#16a34a'],'closed'=>['#f1f5f9','#64748b'],'archived'=>['#f8fafc','#94a3b8']];
                    [$scb,$scc] = $sc_map2[$panel_cycle['status']] ?? ['#f1f5f9','#64748b'];
                    ?>
                    <span class="badge <?= $panel_cycle['status'] === 'open' ? 'bg-success' : 'bg-secondary' ?>">
                        <?= ucfirst($panel_cycle['status']) ?>
                    </span>
                </div>
            </div>
            <div class="[padding:1rem_1.25rem]">
                <div class="row g-3 mb-4">
                    <div class="col-sm-6">
                        <div class="small text-muted mb-1 [font-size:0.7rem] uppercase [letter-spacing:0.05em] font-bold">Statistics</div>
                        <div class="[font-size:0.85rem] [color:#1e293b]">
                            <span class="me-2"><i class="bi bi-file-earmark-text me-1 text-primary"></i><?= $panel_cycle['applied'] ?> applications</span>
                            <span><i class="bi bi-check-circle me-1 text-success"></i><?= $panel_cycle['reclassified'] ?> evaluation complete</span>
                        </div>
                        <div class="text-muted small mt-1">Created by <?= sanitize($panel_cycle['full_name'] ?? 'System') ?></div>
                    </div>
                    <div class="col-sm-6">
                        <div class="small text-muted mb-1 [font-size:0.7rem] uppercase [letter-spacing:0.05em] font-bold">Cycle Dates</div>
                        <div class="[font-size:0.82rem] [color:#1e293b] flex flex-col [gap:2px]">
                            <?php if (!empty($panel_cycle['start_date']) && !empty($panel_cycle['end_date'])): ?>
                            <div><i class="bi bi-calendar-event me-1 text-primary"></i>
                                <span class="text-muted">Cycle:</span>
                                <strong><?= date('M j, Y', strtotime($panel_cycle['start_date'])) ?> to <?= date('M j, Y', strtotime($panel_cycle['end_date'])) ?></strong>
                            </div>
                            <?php endif; ?>
                            <div><i class="bi bi-upload me-1 text-primary"></i>
                                <span class="text-muted">Faculty Submission:</span>
                                <strong>
                                    <?= !empty($panel_cycle['submission_start_date']) ? date('M j, Y', strtotime($panel_cycle['submission_start_date'])) : 'N/A' ?>
                                    to
                                    <?= !empty($panel_cycle['submission_deadline']) ? date('M j, Y', strtotime($panel_cycle['submission_deadline'])) : 'N/A' ?>
                                </strong>
                            </div>
                            <?php if (!empty($panel_cycle['submission_deadline'])): ?>
                            <div><i class="bi bi-clock me-1 text-warning"></i>
                                <span class="text-muted">Evaluation Due:</span>
                                <strong><?= !empty($panel_cycle['evaluation_deadline']) ? date('M j, Y', strtotime($panel_cycle['evaluation_deadline'])) : 'N/A' ?></strong>
                            </div>
                            <div><i class="bi bi-chat-square-text me-1 text-primary"></i>
                                <span class="text-muted">Appeals Due:</span>
                                <strong><?= !empty($panel_cycle['appeal_deadline']) ? date('M j, Y', strtotime($panel_cycle['appeal_deadline'])) : 'While cycle is open' ?></strong>
                            </div>
                            <?php endif; ?>
                            <?php if (empty($panel_cycle['start_date']) && empty($panel_cycle['end_date'])): ?>
                            <div class="text-muted [font-size:0.78rem]"><i class="bi bi-dash me-1"></i>No dates set</div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                <!-- Status controls -->
                <div class="d-flex gap-2 flex-wrap pt-2 [border-top:1px_solid_#f0f4fb]">
                    <button type="button" class="btn btn-sm btn-outline-primary [border-radius:6px]"
                            onclick="openEditCycleModal()"
                           >
                        <i class="bi bi-pencil me-1"></i>Edit Cycle
                    </button>
                    <button type="button" class="btn btn-sm btn-outline-primary [border-radius:6px]"
                            onclick="openExtendSubmissionModal()"
                           >
                        <i class="bi bi-clock-history me-1"></i>Extend Submission
                    </button>
                    <form method="POST" class="d-flex gap-1" id="statusForm_<?= $panel_cycle['cycle_id'] ?>">
                        <input type="hidden" name="action" value="update_status">
                        <input type="hidden" name="cycle_id" value="<?= $panel_cycle['cycle_id'] ?>">
                        <select name="status" class="form-select form-select-sm [width:auto] [min-width:130px]">
                            <option value="open"     <?= $panel_cycle['status']==='open'     ?'selected':'' ?>>Open</option>
                            <option value="closed"   <?= $panel_cycle['status']==='closed'   ?'selected':'' ?>>Closed</option>
                            <option value="archived" <?= $panel_cycle['status']==='archived' ?'selected':'' ?>>Archived</option>
                        </select>
                        <button type="button" class="btn btn-sm btn-primary"
                                onclick="confirmDelete('Update status for this cycle?','statusForm_<?= $panel_cycle['cycle_id'] ?>','Update','bi-check-circle')">
                            <i class="bi bi-check-circle me-1"></i>Update Status
                        </button>
                    </form>
                    <form method="POST" id="delCycleForm_<?= $panel_cycle['cycle_id'] ?>">
                        <input type="hidden" name="action" value="delete_cycle">
                        <input type="hidden" name="cycle_id" value="<?= $panel_cycle['cycle_id'] ?>">
                        <button type="button" class="btn btn-sm [background:#fef2f2] [color:#dc2626] [border:1px_solid_#fecaca]"
                                <?= $panel_cycle['applied'] > 0 ? 'disabled title="Has submitted applications"' : '' ?>
                                onclick="confirmDelete('Delete cycle &ldquo;<?= addslashes(sanitize($panel_cycle['cycle_name'])) ?>&rdquo;? This cannot be undone.','delCycleForm_<?= $panel_cycle['cycle_id'] ?>')">
                            <i class="bi bi-trash me-1"></i>Delete Cycle
                        </button>
                    </form>
                </div>
                <?php if ($cycle_extensions): ?>
                <div class="mt-4 pt-4 [border-top:1px_solid_#f0f4fb]">
                    <div class="small text-muted mb-2 [font-size:0.7rem] uppercase [letter-spacing:0.05em] font-bold">Extension History</div>
                    <div class="table-responsive">
                        <table class="table table-sm align-middle mb-0 [font-size:0.8rem]">
                            <thead>
                                <tr class="[color:#64748b]">
                                    <th>Scope</th>
                                    <th>Previous</th>
                                    <th>New Deadline</th>
                                    <th>Extended By</th>
                                    <th>When</th>
                                </tr>
                            </thead>
                            <tbody>
                            <?php foreach ($cycle_extensions as $ext): ?>
                                <tr>
                                    <td>
                                        <?= !empty($ext['applies_to_all'])
                                            ? 'All Faculty'
                                            : sanitize(formatDisplayName($ext) ?: ($ext['full_name'] ?? 'Selected Faculty')) ?>
                                        <?php if (!empty($ext['reason'])): ?>
                                        <div class="text-muted [font-size:0.72rem]"><?= sanitize($ext['reason']) ?></div>
                                        <?php endif; ?>
                                    </td>
                                    <td><?= date('M j, Y', strtotime($ext['previous_deadline'])) ?></td>
                                    <td><strong><?= date('M j, Y', strtotime($ext['new_deadline'])) ?></strong></td>
                                    <td><?= sanitize($ext['extended_by_name'] ?? 'Admin') ?></td>
                                    <td><?= date('M j, Y g:i A', strtotime($ext['created_at'])) ?></td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Criteria card -->
        <div class="neon-card p-0 overflow-hidden">
            <div class="[padding:0.75rem_1.25rem] [border-bottom:1px_solid_#f1f5f9] flex items-center justify-between">
                <div class="font-bold [color:#1a3a6b] [font-size:0.88rem] flex items-center [gap:0.5rem]">
                    <i class="bi bi-sliders [color:#1e4d8c]"></i>Scoring Criteria
                    <?php if ($active_position): ?>
                    <span class="[background:#dbeafe] [color:#1e4d8c] [font-size:0.7rem] font-bold [border-radius:20px] [padding:2px_8px]">
                        <?= htmlspecialchars($active_position) ?>
                    </span>
                    <?php else: ?>
                    <span class="[background:#f1f5f9] [color:#64748b] [font-size:0.7rem] font-bold [border-radius:20px] [padding:2px_8px]">All Positions Overview</span>
                    <?php endif; ?>
                </div>
            </div>
            <div class="[padding:1rem_1.25rem]">

            <!-- Position selector tabs -->
            <div class="mb-4 pb-4 [border-bottom:1px_solid_#f0f4fb]">
                <div class="d-flex align-items-center gap-1 flex-wrap">
                    <a href="?page=cycles&cycle_id=<?= $active_cycle_id ?>"
                       class="btn btn-xs <?= !$active_position ? 'btn-primary' : 'btn-outline-secondary' ?>">
                        <i class="bi bi-grid me-1"></i>Overview
                    </a>
                    <?php
                    $rank_groups = [
                        'Instructor'   => ['Instructor I','Instructor II','Instructor III'],
                        'Asst. Prof.'  => ['Assistant Professor I','Assistant Professor II','Assistant Professor III','Assistant Professor IV'],
                        'Assoc. Prof.' => ['Associate Professor I','Associate Professor II','Associate Professor III','Associate Professor IV','Associate Professor V'],
                        'Professor'    => ['Professor I','Professor II','Professor III','Professor IV','Professor V','Professor VI'],
                        'Univ. Prof.'  => ['University Professor'],
                    ];
                    foreach ($rank_groups as $group => $ranks):
                    ?>
                    <div class="dropdown">
                        <button class="btn btn-xs <?= in_array($active_position, $ranks) ? 'btn-primary' : 'btn-outline-secondary' ?> dropdown-toggle" data-bs-toggle="dropdown">
                            <?= $group ?>
                        </button>
                        <ul class="dropdown-menu shadow-sm [min-width:230px] [border:1px_solid_#e2e8f0]">
                            <?php foreach ($ranks as $rank):
                                $cnt = $position_counts[$rank] ?? 0;
                                $is_active_pos = ($active_position === $rank);
                            ?>
                            <li>
                                <a class="dropdown-item small d-flex justify-content-between align-items-center py-2 <?= $is_active_pos ? 'active' : '' ?>"
                                   href="?page=cycles&cycle_id=<?= $active_cycle_id ?>&position=<?= urlencode($rank) ?>">
                                    <span><?= htmlspecialchars($rank) ?></span>
                                    <?php if ($cnt > 0): ?>
                                    <span class="badge ms-2 [background:#dbeafe] [color:#1e4d8c] [font-size:0.6rem]"><?= $cnt ?></span>
                                    <?php else: ?>
                                    <span class="badge bg-warning text-dark ms-2 [font-size:0.6rem]">empty</span>
                                    <?php endif; ?>
                                </a>
                            </li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>

            <?php if ($criteria_source === 'overview'): ?>
            <!-- Overview: position status grid -->
            <?php foreach ($rank_groups as $group => $ranks): ?>
            <div class="mb-4">
                <div class="fw-bold mb-2 [font-size:0.72rem] uppercase [letter-spacing:0.06em] [color:#94a3b8]"><?= $group ?></div>
                <div class="d-flex flex-wrap gap-2">
                <?php foreach ($ranks as $rank):
                    $cnt = $position_counts[$rank] ?? 0;
                ?>
                <a href="?page=cycles&cycle_id=<?= $active_cycle_id ?>&position=<?= urlencode($rank) ?>"
                   class="btn btn-sm <?= $cnt > 0 ? 'btn-outline-primary' : 'btn-outline-secondary' ?>">
                    <?= htmlspecialchars($rank) ?>
                    <span class="ms-1 [font-size:0.65rem] [opacity:0.8]"><?= $cnt > 0 ? $cnt : '!' ?></span>
                </a>
                <?php endforeach; ?>
                </div>
            </div>
            <?php endforeach; ?>

            <?php elseif ($criteria_source === 'empty_position'): ?>
            <div class="alert alert-info py-2 small">
                <i class="bi bi-info-circle me-1"></i>
                Criteria for <strong><?= htmlspecialchars($active_position) ?></strong> are being set up. Please refresh the page.
            </div>

            <?php else: ?>
            <!-- Editable criteria accordion -->
            <div class="alert py-2 small mb-4 [background:#f0f7ff] [border:1px_solid_#bfdbfe] [color:#1e4d8c]">
                <i class="bi bi-pencil-square me-1"></i>
                Editing criteria for <strong><?= htmlspecialchars($active_position) ?></strong>. Changes only affect this position in this cycle.
            </div>

            <div class="accordion mb-6" id="criteriaAccordion">
            <?php foreach ($kra_list as $kra):
                $items = $grouped[$kra] ?? [];
                $slug  = strtolower(str_replace([' ','/'],['-',''], $kra));
                $is_editable = ($criteria_source === 'cycle_position');
            ?>
            <div class="accordion-item border mb-2 [border-color:#e2e8f0_!important] [border-radius:8px_!important] overflow-hidden">
                <h2 class="accordion-header" id="head-<?= $slug ?>">
                    <button class="accordion-button collapsed fw-semibold" type="button"
                            data-bs-toggle="collapse" data-bs-target="#kra-<?= $slug ?>"
                            class="[background:#f8fafc] [color:#1a3a6b] [font-size:0.875rem]">
                        <i class="bi bi-sliders2 me-2 text-primary"></i><?= $kra ?>
                        <span class="badge bg-primary ms-2 [font-size:0.65rem]"><?= count($items) ?></span>
                    </button>
                </h2>
                <div id="kra-<?= $slug ?>" class="accordion-collapse collapse">
                    <div class="accordion-body p-0 bg-white">
                        <?php foreach ($items as $c): ?>
                        <form method="POST" class="px-4 py-4 border-bottom [border-color:#f0f4fb_!important]"
                              id="cForm_<?= $c['criteria_id'] ?>">
                            <input type="hidden" name="action" value="update_criterion">
                            <input type="hidden" name="criteria_id" value="<?= $c['criteria_id'] ?>">
                            <input type="hidden" name="back_cycle_id" value="<?= $active_cycle_id ?>">
                            <input type="hidden" name="back_position" value="<?= htmlspecialchars($active_position) ?>">
                            <div class="row g-2 align-items-end">
                                <div class="col-12 col-md-1">
                                    <label class="form-label small fw-semibold [color:#94a3b8]">Key</label>
                                    <input type="text" class="form-control form-control-sm"
                                           value="<?= sanitize($c['criterion_key']) ?>" disabled
                                           class="[background:#f8fafc] [color:#94a3b8] [font-size:0.75rem]">
                                </div>
                                <div class="col-12 col-md-4">
                                    <label class="form-label small fw-semibold [color:#475569]">Label</label>
                                    <input type="text" name="criterion_label" class="form-control form-control-sm"
                                           value="<?= sanitize($c['criterion_label']) ?>"
                                           <?= !$is_editable ? 'disabled' : 'required' ?>>
                                </div>
                                <div class="col-6 col-md-2">
                                    <label class="form-label small fw-semibold [color:#475569]">Max Pts</label>
                                    <input type="number" name="max_points" class="form-control form-control-sm criteria-number-input"
                                           value="<?= $c['max_points'] ?>" step="0.01" min="0"
                                           <?= !$is_editable ? 'disabled' : 'required' ?>>
                                </div>
                                <div class="col-6 col-md-2">
                                    <label class="form-label small fw-semibold [color:#475569]">Weight %</label>
                                    <input type="number" name="weight_pct" class="form-control form-control-sm criteria-number-input"
                                           value="<?= $c['weight_pct'] ?>" step="0.01" min="0" max="100"
                                           <?= !$is_editable ? 'disabled' : '' ?>>
                                </div>
                                <div class="col-12 col-md-3">
                                    <label class="form-label small fw-semibold [color:#475569]">Description</label>
                                    <input type="text" name="description" class="form-control form-control-sm"
                                           value="<?= sanitize($c['description'] ?? '') ?>"
                                           <?= !$is_editable ? 'disabled' : '' ?>>
                                </div>
                                <?php if ($is_editable): ?>
                                <div class="col-6 col-md-1 d-flex flex-column align-items-center">
                                    <label class="form-label small fw-semibold [color:#475569]">Active</label>
                                    <div class="form-check form-switch mt-1">
                                        <input class="form-check-input" type="checkbox" name="is_active"
                                               <?= $c['is_active'] ? 'checked' : '' ?>>
                                    </div>
                                </div>
                                <div class="col-6 col-md-auto d-flex gap-1 align-items-end">
                                    <button type="button" class="btn btn-sm btn-primary"
                                            onclick="confirmDelete('Save changes to this criterion?','cForm_<?= $c['criteria_id'] ?>','Save','bi-save')">
                                        <i class="bi bi-save me-1"></i>Save
                                    </button>
                                    <button type="button" class="btn btn-sm [background:#fef2f2] [color:#dc2626] [border:1px_solid_#fecaca]"
                                            onclick="showDelCrit(<?= $c['criteria_id'] ?>,<?= $active_cycle_id ?>,'<?= addslashes(htmlspecialchars($c['criterion_label'])) ?>')">
                                        <i class="bi bi-trash"></i>
                                    </button>
                                </div>
                                <?php endif; ?>
                            </div>
                        </form>
                        <form method="POST" id="delCritForm_<?= $c['criteria_id'] ?>" class="hidden">
                            <input type="hidden" name="action" value="delete_criterion">
                            <input type="hidden" name="criteria_id" value="<?= $c['criteria_id'] ?>">
                            <input type="hidden" name="back_cycle_id" value="<?= $active_cycle_id ?>">
                            <input type="hidden" name="back_position" value="<?= htmlspecialchars($active_position) ?>">
                        </form>
                        <?php endforeach; ?>
                        <?php if (empty($items)): ?>
                        <div class="p-6 text-muted small text-center">
                            <i class="bi bi-inbox me-1"></i>No criteria for this KRA yet.
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
            </div>

            <!-- Add criterion -->
            <?php if ($is_editable): ?>
            <div class="pt-2 [border-top:1px_solid_#f0f4fb]">
                <div class="d-flex justify-content-between align-items-center mb-2">
                    <span class="fw-semibold small [color:#1a3a6b]">
                        <i class="bi bi-plus-circle me-1"></i>Add Criterion
                        <?php if ($active_position): ?>
                        <span class="badge ms-1 [background:#dbeafe] [color:#1e4d8c] [font-size:0.7rem]"><?= htmlspecialchars($active_position) ?></span>
                        <?php endif; ?>
                    </span>
                    <button class="btn btn-sm btn-primary" type="button"
                            data-bs-toggle="collapse" data-bs-target="#addCritCollapse">
                        <i class="bi bi-plus-lg me-1"></i>Add
                    </button>
                </div>
                <div class="collapse" id="addCritCollapse">
                <div class="p-4 rounded-lg [background:#f8fafc] [border:1px_solid_#e2e8f0]">
                <form method="POST" id="addCritForm">
                    <input type="hidden" name="action" value="add_criterion">
                    <input type="hidden" name="cycle_id" value="<?= $active_cycle_id ?>">
                    <input type="hidden" name="position_rank" value="<?= htmlspecialchars($active_position) ?>">
                    <div class="row g-2 align-items-end">
                        <div class="col-12 col-lg-2">
                            <label class="form-label small fw-semibold [color:#475569]">KRA <span class="text-danger">*</span></label>
                            <select name="kra_category" class="form-select form-select-sm" required>
                                <?php foreach ($kra_list as $k): ?>
                                <option value="<?= $k ?>"><?= $k ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-12 col-lg-2">
                            <label class="form-label small fw-semibold [color:#475569]">Key <span class="text-danger">*</span></label>
                            <input type="text" name="criterion_key" class="form-control form-control-sm"
                                   placeholder="e.g. kra1_patent" required>
                        </div>
                        <div class="col-12 col-lg-2">
                            <label class="form-label small fw-semibold [color:#475569]">Label <span class="text-danger">*</span></label>
                            <input type="text" name="criterion_label" class="form-control form-control-sm"
                                   placeholder="e.g. Patent / Utility Model" required>
                        </div>
                        <div class="col-6 col-lg-2">
                            <label class="form-label small fw-semibold [color:#475569]">Max Pts <span class="text-danger">*</span></label>
                            <input type="number" name="max_points" class="form-control form-control-sm criteria-number-input"
                                   step="0.01" min="0.01" placeholder="0.00" required>
                        </div>
                        <div class="col-6 col-lg-2">
                            <label class="form-label small fw-semibold [color:#475569]">Weight %</label>
                            <input type="number" name="weight_pct" class="form-control form-control-sm criteria-number-input"
                                   step="0.01" min="0" max="100" value="100.00">
                        </div>
                        <div class="col-12 col-lg-6">
                            <label class="form-label small fw-semibold [color:#475569]">Description</label>
                            <input type="text" name="description" class="form-control form-control-sm" placeholder="Optional">
                        </div>
                        <div class="col-12 col-lg-1">
                            <button type="button" class="btn btn-sm btn-primary w-full"
                                    onclick="confirmDelete('Add this criterion?','addCritForm','Add','bi-plus-circle')">
                                <i class="bi bi-plus-lg"></i>
                            </button>
                        </div>
                    </div>
                </form>
                </div>
                </div>
            </div>
            <?php endif; ?>
            <?php endif; // criteria_source ?>

            </div><!-- card-body -->
        </div><!-- criteria card -->

    <?php else: ?>
    <div class="neon-card d-flex flex-column align-items-center justify-content-center py-12 text-center [min-height:300px]">
        <i class="bi bi-calendar-range [font-size:2.5rem] [color:#dbeafe] block [margin-bottom:0.75rem]"></i>
        <p class="fw-semibold mb-1 [color:#475569]">Select a cycle to manage it</p>
        <p class="text-muted small">Or create a new cycle using the button above.</p>
    </div>
    <?php endif; ?>
    </div><!-- col-lg-9 -->
</div><!-- row -->

<!-- Cycle Review Modal -->
<div id="cycleReviewModal" hidden
     class="fixed inset-0 z-[9999] flex items-center justify-center bg-slate-950/50 p-4">
    <div class="bg-white [border-radius:12px] w-full [max-width:500px] [box-shadow:0_8px_40px_rgba(0,0,0,0.15)] overflow-hidden [border:1px_solid_#e2e8f0]">

        <!-- Header -->
        <div class="[padding:1.1rem_1.5rem] [border-bottom:1px_solid_#e2e8f0] flex items-center justify-between">
            <div>
                <div class="font-bold [color:#0f172a] [font-size:0.95rem]">Review New Cycle</div>
                <div class="[color:#94a3b8] [font-size:0.72rem] [margin-top:2px]">Confirm the details before opening</div>
            </div>
            <button onclick="document.getElementById('cycleReviewModal').hidden=true" class="btn-close">
                <i class="bi bi-x-lg"></i>
            </button>
        </div>

        <!-- Warning if open cycle exists -->
        <?php if ($open_cycle): ?>
        <div class="[background:#fffbeb] [border-bottom:1px_solid_#fde68a] [padding:0.55rem_1.5rem] [font-size:0.78rem] [color:#92400e]">
            Creating this cycle will <strong>automatically close</strong> the currently open cycle: <strong><?= sanitize($open_cycle['cycle_name']) ?></strong>.
        </div>
        <?php endif; ?>

        <!-- Body -->
        <div class="[padding:1.25rem_1.5rem]">

            <!-- Summary table -->
            <div class="[border:1px_solid_#e2e8f0] [border-radius:8px] overflow-hidden [margin-bottom:1rem]">

                <!-- Cycle name row -->
                <div class="[padding:0.7rem_1rem] [border-bottom:1px_solid_#f1f5f9]">
                    <div class="[font-size:0.62rem] [color:#94a3b8] font-bold uppercase [letter-spacing:0.06em] [margin-bottom:2px]">Cycle Name</div>
                    <div class="flex items-center justify-between [gap:0.75rem]">
                        <div id="rv_name" class="[font-size:0.9rem] font-bold [color:#0f172a] overflow-hidden [text-overflow:ellipsis] whitespace-nowrap"></div>
                        <span class="[font-size:0.65rem] font-semibold [color:#475569] [background:#f1f5f9] [border:1px_solid_#e2e8f0] [border-radius:20px] [padding:2px_9px] whitespace-nowrap shrink-0">
                            Opens immediately
                        </span>
                    </div>
                </div>

                <!-- 2-col date grid -->
                <div class="grid grid-cols-1 border-b border-slate-100 sm:grid-cols-2">
                    <div class="[padding:0.65rem_1rem] [border-right:1px_solid_#f1f5f9]">
                        <div class="[font-size:0.62rem] [color:#94a3b8] font-bold uppercase [letter-spacing:0.05em] [margin-bottom:3px]">Start Date</div>
                        <div id="rv_start" class="[font-size:0.85rem] font-semibold [color:#1e293b]"></div>
                    </div>
                    <div class="[padding:0.65rem_1rem]">
                        <div class="[font-size:0.62rem] [color:#94a3b8] font-bold uppercase [letter-spacing:0.05em] [margin-bottom:3px]">End Date</div>
                        <div id="rv_end" class="[font-size:0.85rem] font-semibold [color:#1e293b]"></div>
                    </div>
                </div>

                <div class="grid grid-cols-1 border-b border-slate-100 sm:grid-cols-2">
                    <div class="[padding:0.65rem_1rem] [border-right:1px_solid_#f1f5f9]">
                        <div class="[font-size:0.62rem] [color:#94a3b8] font-bold uppercase [letter-spacing:0.05em] [margin-bottom:3px]">Submission Start</div>
                        <div id="rv_sub_start" class="[font-size:0.85rem] font-semibold [color:#1e293b]"></div>
                    </div>
                    <div class="[padding:0.65rem_1rem]">
                        <div class="[font-size:0.62rem] [color:#94a3b8] font-bold uppercase [letter-spacing:0.05em] [margin-bottom:3px]">Submission Deadline</div>
                        <div id="rv_deadline" class="[font-size:0.85rem] font-semibold [color:#1e293b]"></div>
                    </div>
                </div>

                <!-- Evaluation deadline row -->
                <div class="[padding:0.65rem_1rem]">
                    <div class="[font-size:0.62rem] [color:#94a3b8] font-bold uppercase [letter-spacing:0.05em] [margin-bottom:3px]">Evaluation Deadline</div>
                    <div class="flex items-center [gap:0.6rem] flex-wrap">
                        <span id="rv_eval_deadline" class="[font-size:0.85rem] font-semibold [color:#1e293b]"></span>
                        <span id="rv_deadline_note" class="[font-size:0.72rem] [color:#64748b]"></span>
                    </div>
                </div>
            </div>

            <!-- Criteria note -->
            <div class="[background:#f8fafc] [border:1px_solid_#e2e8f0] [border-radius:8px] [padding:0.6rem_0.85rem] [font-size:0.78rem] [color:#475569] [margin-bottom:1.25rem]">
                Scoring criteria from global defaults will be <strong>automatically seeded</strong> for all 19 position ranks. You can customise them per position after the cycle is created.
            </div>

            <!-- Action buttons -->
            <div class="flex [gap:0.6rem]">
                <button type="button"
                        onclick="document.getElementById('cycleReviewModal').hidden=true"
                        class="btn btn-secondary flex-1">
                    &larr; Go Back
                </button>
                <button type="button" onclick="confirmCreateCycle()" class="btn btn-primary flex-[2]">
                    Confirm &amp; Open Cycle
                </button>
            </div>
        </div>

    </div>
</div>

<!-- Delete criterion confirmation modal -->
<div id="delCritModal" hidden class="fixed inset-0 z-[9999] flex items-center justify-center bg-slate-950/45 p-4">
    <div class="bg-white [border-radius:10px] [padding:1.75rem_2rem] [max-width:420px] [width:90%] [box-shadow:0_20px_60px_rgba(0,0,0,0.2)]">
        <h6 class="[color:#1a3a6b] [margin-bottom:0.5rem] font-bold"><i class="bi bi-exclamation-triangle-fill me-2"></i>Delete Criterion</h6>
        <p class="mb-1 text-sm text-slate-600">Permanently delete:</p>
        <p id="delCritName" class="mb-4 rounded-md border border-slate-300 bg-slate-50 px-3 py-2 text-sm font-semibold text-slate-800"></p>
        <p class="mb-4 text-xs text-slate-500">This action cannot be undone.</p>
        <div class="d-flex gap-2 justify-content-end">
            <button type="button" class="btn btn-sm btn-secondary" onclick="document.getElementById('delCritModal').hidden=true">Cancel</button>
            <button type="button" id="delCritConfirmBtn" class="btn btn-sm [background:#dc2626] [color:#fff] [border:1px_solid_#dc2626]"><i class="bi bi-trash me-1"></i>Delete</button>
        </div>
    </div>
</div>

<!-- Edit Cycle Modal -->
<div id="editCycleModal" hidden
     class="fixed inset-0 z-[9999] flex items-center justify-center bg-slate-950/50 p-4">
    <div class="bg-white [border-radius:14px] w-full [max-width:540px] [box-shadow:0_20px_60px_rgba(0,0,0,0.2)] overflow-hidden">
        <div class="[background:linear-gradient(135deg,#1a3a6b,#1e4d8c)] [padding:1.1rem_1.5rem] flex items-center justify-between">
            <div class="[color:#fff] font-bold [font-size:0.95rem]">
                <i class="bi bi-pencil-square me-2"></i>Edit Cycle
            </div>
            <button onclick="document.getElementById('editCycleModal').hidden=true" class="btn-close btn-close-white" aria-label="Close"></button>
        </div>
        <form method="POST" class="[padding:1.5rem]">
            <input type="hidden" name="action" value="update_cycle">
            <input type="hidden" name="cycle_id" value="<?= $panel_cycle ? $panel_cycle['cycle_id'] : 0 ?>">
            <div class="mb-4">
                <label class="form-label fw-semibold small">Cycle Name <span class="text-danger">*</span></label>
                <input type="text" name="cycle_name" id="ec_name" class="form-control" required
                       value="<?= htmlspecialchars($panel_cycle['cycle_name'] ?? '') ?>">
            </div>
            <div class="row g-3 mb-4">
                <div class="col-sm-6">
                    <label class="form-label fw-semibold small">Start Date <span class="text-danger">*</span></label>
                    <input type="date" name="start_date" id="ec_start" class="form-control" required
                           value="<?= htmlspecialchars($panel_cycle['start_date'] ?? '') ?>">
                </div>
                <div class="col-sm-6">
                    <label class="form-label fw-semibold small">End Date <span class="text-danger">*</span></label>
                    <input type="date" name="end_date" id="ec_end" class="form-control" required
                           value="<?= htmlspecialchars($panel_cycle['end_date'] ?? '') ?>">
                </div>
                <div class="col-sm-6">
                    <label class="form-label fw-semibold small">Submission Start <span class="text-danger">*</span></label>
                    <input type="date" name="submission_start_date" id="ec_sub_start" class="form-control" required
                           value="<?= htmlspecialchars($panel_cycle['submission_start_date'] ?? '') ?>">
                </div>
                <div class="col-sm-6">
                    <label class="form-label fw-semibold small">Submission Deadline <span class="text-danger">*</span></label>
                    <input type="date" name="submission_deadline" id="ec_deadline" class="form-control" required
                           value="<?= htmlspecialchars($panel_cycle['submission_deadline'] ?? '') ?>">
                </div>
                <div class="col-sm-6">
                    <label class="form-label fw-semibold small">Evaluation Deadline <span class="text-danger">*</span></label>
                    <input type="date" name="evaluation_deadline" id="ec_eval_deadline" class="form-control" required
                           value="<?= htmlspecialchars($panel_cycle['evaluation_deadline'] ?? '') ?>">
                </div>
                <div class="col-sm-6">
                    <label class="form-label fw-semibold small">Appeal Deadline</label>
                    <input type="date" name="appeal_deadline" id="ec_appeal_deadline" class="form-control"
                           value="<?= htmlspecialchars($panel_cycle['appeal_deadline'] ?? '') ?>">
                </div>
            </div>
            <div id="ec_error" hidden class="alert alert-danger mb-3"></div>
            <div class="flex [gap:0.75rem] justify-end">
                <button type="button"
                        onclick="document.getElementById('editCycleModal').hidden=true"
                        class="btn btn-secondary">
                    Cancel
                </button>
                <button type="button" onclick="submitEditCycle()"
                        class="[padding:0.45rem_1.3rem] border-0 [border-radius:7px] [background:#1a3a6b] [color:#fff] [font-size:0.85rem] font-semibold cursor-pointer">
                    <i class="bi bi-save me-1"></i>Save Changes
                </button>
            </div>
        </form>
    </div>
</div>

<!-- Extend Submission Modal -->
<?php if ($panel_cycle): ?>
<div id="extendSubmissionModal" hidden
     class="fixed inset-0 z-[9999] flex items-center justify-center bg-slate-950/50 p-4">
    <div class="bg-white [border-radius:14px] w-full [max-width:620px] [box-shadow:0_20px_60px_rgba(0,0,0,0.2)] overflow-hidden">
        <div class="[background:linear-gradient(135deg,#1a3a6b,#1e4d8c)] [padding:1.1rem_1.5rem] flex items-center justify-between">
            <div class="[color:#fff] font-bold [font-size:0.95rem]">
                <i class="bi bi-clock-history me-2"></i>Extend Faculty Submission
            </div>
            <button onclick="document.getElementById('extendSubmissionModal').hidden=true" class="btn-close btn-close-white" aria-label="Close"></button>
        </div>
        <form method="POST" id="extendSubmissionForm" class="[padding:1.5rem]">
            <input type="hidden" name="action" value="extend_submission">
            <input type="hidden" name="cycle_id" value="<?= (int)$panel_cycle['cycle_id'] ?>">
            <input type="hidden" name="confirm_past_eval" id="confirm_past_eval" value="0">
            <div class="row g-3">
                <div class="col-sm-6">
                    <label class="form-label fw-semibold small">Current Deadline</label>
                    <input type="text" class="form-control" readonly
                           value="<?= !empty($panel_cycle['submission_deadline']) ? date('M j, Y', strtotime($panel_cycle['submission_deadline'])) : 'N/A' ?>">
                </div>
                <div class="col-sm-6">
                    <label class="form-label fw-semibold small">New Deadline <span class="text-danger">*</span></label>
                    <input type="date" name="new_deadline" id="ext_new_deadline" class="form-control" required>
                </div>
                <div class="col-12">
                    <label class="form-label fw-semibold small">Scope <span class="text-danger">*</span></label>
                    <select name="extension_scope" id="extension_scope" class="form-select" onchange="toggleFacultyPicker()">
                        <option value="all">All faculty</option>
                        <option value="selected">Selected faculty only</option>
                    </select>
                </div>
                <div class="col-12" id="faculty_picker_wrap" hidden>
                    <label class="form-label fw-semibold small">Faculty</label>
                    <select name="faculty_ids[]" class="form-select" multiple size="6">
                        <?php foreach ($faculty_for_extension as $fac): ?>
                        <option value="<?= (int)$fac['user_id'] ?>">
                            <?= sanitize(formatDisplayName($fac) ?: ($fac['full_name'] ?? $fac['email'])) ?> - <?= sanitize($fac['email'] ?? '') ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                    <div class="form-text">Hold Ctrl while clicking to select multiple faculty.</div>
                </div>
                <div class="col-12">
                    <label class="form-label fw-semibold small">Reason</label>
                    <textarea name="extension_reason" class="form-control" rows="3" placeholder="Optional note for the audit trail"></textarea>
                </div>
            </div>
            <div id="ext_error" hidden class="alert alert-danger mt-3"></div>
            <div class="flex [gap:0.75rem] justify-end [margin-top:1.25rem]">
                <button type="button"
                        onclick="document.getElementById('extendSubmissionModal').hidden=true"
                        class="btn btn-secondary">
                    Cancel
                </button>
                <button type="button" onclick="submitExtension()"
                        class="[padding:0.45rem_1.3rem] border-0 [border-radius:7px] [background:#1a3a6b] [color:#fff] [font-size:0.85rem] font-semibold cursor-pointer">
                    <i class="bi bi-check-circle me-1"></i>Save Extension
                </button>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>

<script>
// Cycle name dropdown population and date auto-fill removed.
// Cycles are now created with a name only; timing is controlled by Open/Closed status.

// -- Cycle Review Modal ----------------------------------------------------
function fmtDate(val) {
    if (!val) return ' - ';
    const d = new Date(val + 'T00:00:00');
    return d.toLocaleDateString('en-PH', { year:'numeric', month:'long', day:'numeric' });
}

function openCycleReview() {
    const form = document.getElementById('createCycleForm');
    if (!form.checkValidity()) { form.reportValidity(); return; }

    const name     = document.getElementById('cc_name').value.trim();
    const start    = document.getElementById('cc_start').value;
    const end      = document.getElementById('cc_end').value;
    const subStart = document.getElementById('cc_sub_start').value;
    const deadline = document.getElementById('cc_deadline').value;
    const evalDeadline = document.getElementById('cc_eval_deadline').value;
    const appealDeadline = document.getElementById('cc_appeal_deadline').value;

    // Validate date logic
    const errBox = document.getElementById('cycle_date_error');
    const errMsg = document.getElementById('cycle_date_error_msg');
    const showErr = (msg) => { errMsg.textContent = msg; errBox.hidden = false; errBox.scrollIntoView({behavior:'smooth',block:'nearest'}); };
    errBox.hidden = true;

    if (start && end && end < start) {
        showErr('End date must be after the start date.');
        return;
    }
    if (subStart && start && subStart < start) {
        showErr('Submission start must be inside the cycle period.');
        return;
    }
    if (subStart && end && subStart > end) {
        showErr('Submission start must be inside the cycle period.');
        return;
    }
    if (subStart && deadline && subStart > deadline) {
        showErr('Submission start cannot be after the submission deadline.');
        return;
    }
    if (deadline && end && deadline > end) {
        showErr('Submission deadline cannot be after the end date.');
        return;
    }
    if (deadline && start && deadline < start) {
        showErr('Submission deadline cannot be before the start date.');
        return;
    }
    if (evalDeadline && deadline && evalDeadline <= deadline) {
        showErr('Evaluation deadline must be after the faculty submission deadline.');
        return;
    }
    if (evalDeadline && end && evalDeadline > end) {
        showErr('Evaluation deadline cannot be later than the cycle end date.');
        return;
    }
    if (appealDeadline && (appealDeadline < deadline || appealDeadline > end)) {
        showErr('Appeal deadline must be after the submission deadline and inside the cycle period.');
        return;
    }

    // Populate summary
    document.getElementById('rv_name').textContent     = name;
    document.getElementById('rv_start').textContent    = fmtDate(start);
    document.getElementById('rv_end').textContent      = fmtDate(end);
    document.getElementById('rv_sub_start').textContent = fmtDate(subStart);
    document.getElementById('rv_deadline').textContent = fmtDate(deadline);
    document.getElementById('rv_eval_deadline').textContent = fmtDate(evalDeadline);

    // Days until evaluation deadline
    const today = new Date(); today.setHours(0,0,0,0);
    const dlDate = new Date(evalDeadline + 'T00:00:00');
    const diff = Math.round((dlDate - today) / 86400000);
    const dlEl = document.getElementById('rv_deadline_note');
    if (diff < 0) {
        dlEl.textContent = 'This date has already passed.';
        dlEl.className = 'text-xs text-rose-600';
    } else if (diff === 0) {
        dlEl.textContent = 'Deadline is today.';
        dlEl.className = 'text-xs text-amber-600';
    } else {
        dlEl.textContent = diff + ' day' + (diff !== 1 ? 's' : '') + ' from today.';
        dlEl.className = 'text-xs text-emerald-600';
    }

    document.getElementById('cycleReviewModal').hidden = false;
}

function confirmCreateCycle() {
    document.getElementById('cycleReviewModal').hidden = true;
    document.getElementById('createCycleForm').submit();
}

document.addEventListener('DOMContentLoaded', function () {
    const m = document.getElementById('cycleReviewModal');
    if (m) m.addEventListener('click', function(e) {
        if (e.target === this) this.hidden = true;
    });
    const em = document.getElementById('editCycleModal');
    if (em) em.addEventListener('click', function(e) {
        if (e.target === this) this.hidden = true;
    });
    const xm = document.getElementById('extendSubmissionModal');
    if (xm) xm.addEventListener('click', function(e) {
        if (e.target === this) this.hidden = true;
    });

});

function openEditCycleModal() {
    document.getElementById('editCycleModal').hidden = false;
    document.getElementById('ec_error').hidden = true;
}

function submitEditCycle() {
    const name     = document.getElementById('ec_name').value.trim();
    const start    = document.getElementById('ec_start').value;
    const end      = document.getElementById('ec_end').value;
    const subStart = document.getElementById('ec_sub_start').value;
    const deadline = document.getElementById('ec_deadline').value;
    const evalDeadline = document.getElementById('ec_eval_deadline').value;
    const appealDeadline = document.getElementById('ec_appeal_deadline').value;
    const errEl    = document.getElementById('ec_error');
    const show = (msg) => { errEl.textContent = msg; errEl.hidden = false; };

    if (!name) { show('Cycle name is required.'); return; }
    if (!start || !end || !subStart || !deadline || !evalDeadline) { show('All cycle date fields are required.'); return; }
    if (start && end && end <= start) { show('End date must be after start date.'); return; }
    if (subStart < start || subStart > end) { show('Submission start must be inside the cycle period.'); return; }
    if (deadline < start || deadline > end) { show('Submission deadline must be inside the cycle period.'); return; }
    if (subStart > deadline) { show('Submission start cannot be after the submission deadline.'); return; }
    if (evalDeadline <= deadline) { show('Evaluation deadline must be after the faculty submission deadline.'); return; }
    if (evalDeadline > end) { show('Evaluation deadline cannot be later than the cycle end date.'); return; }
    if (appealDeadline && (appealDeadline < deadline || appealDeadline > end)) { show('Appeal deadline must be after the submission deadline and inside the cycle period.'); return; }

    errEl.hidden = true;
    document.getElementById('ec_name').closest('form').submit();
}

function openExtendSubmissionModal() {
    const m = document.getElementById('extendSubmissionModal');
    if (!m) return;
    document.getElementById('confirm_past_eval').value = '0';
    document.getElementById('ext_error').hidden = true;
    m.hidden = false;
}

function toggleFacultyPicker() {
    const wrap = document.getElementById('faculty_picker_wrap');
    const scope = document.getElementById('extension_scope').value;
    if (wrap) wrap.hidden = scope !== 'selected';
}

function submitExtension() {
    const newDeadline = document.getElementById('ext_new_deadline').value;
    const currentDeadline = '<?= htmlspecialchars($panel_cycle['submission_deadline'] ?? '') ?>';
    const evalDeadline = '<?= htmlspecialchars($panel_cycle['evaluation_deadline'] ?? '') ?>';
    const endDate = '<?= htmlspecialchars($panel_cycle['end_date'] ?? '') ?>';
    const scope = document.getElementById('extension_scope').value;
    const errEl = document.getElementById('ext_error');
    const show = (msg) => { errEl.textContent = msg; errEl.hidden = false; };

    if (!newDeadline) { show('New deadline is required.'); return; }
    if (currentDeadline && newDeadline <= currentDeadline) { show('Extended deadline must be later than the current submission deadline.'); return; }
    if (endDate && newDeadline > endDate) { show('Extended deadline cannot be later than the cycle end date.'); return; }
    if (scope === 'selected') {
        const selected = document.querySelectorAll('#faculty_picker_wrap select option:checked');
        if (!selected.length) { show('Please select at least one faculty member.'); return; }
    }
    if (evalDeadline && newDeadline > evalDeadline && document.getElementById('confirm_past_eval').value !== '1') {
        if (!confirm('The new submission deadline is past the evaluation deadline. Continue with this extension?')) return;
        document.getElementById('confirm_past_eval').value = '1';
    }
    errEl.hidden = true;
    document.getElementById('extendSubmissionForm').submit();
}

function showDelCrit(critId, cycleId, label) {
    document.getElementById('delCritName').textContent = label;
    document.getElementById('delCritModal').hidden = false;
    document.getElementById('delCritConfirmBtn').onclick = function() {
        document.getElementById('delCritModal').hidden = true;
        document.getElementById('delCritForm_' + critId).submit();
    };
}
</script>
