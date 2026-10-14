<?php require __DIR__ . '/../../../backend/controllers/admin/all_applications.php'; ?>

<?php showFlash(); ?>

<!-- -- Page header -- -->
<div class="flex items-center justify-between flex-wrap [gap:1rem] [margin-bottom:1.5rem]">
    <div>
        <div class="flex items-center [gap:0.6rem] [margin-bottom:0.2rem]">
            <div class="[width:36px] [height:36px] [border-radius:8px] [background:#1e4d8c] flex items-center justify-center shrink-0">
                <i class="bi bi-ui-checks-grid [color:#fff] [font-size:1rem]"></i>
            </div>
            <h5 class="m-0 font-bold [color:#1a3a6b] [font-size:1.1rem]">All Applications</h5>
        </div>
        <div class="[font-size:0.78rem] [color:#64748b] [padding-left:44px]">
            <strong class="[color:#1a3a6b]"><?= $total ?></strong> total across all faculty
        </div>
    </div>
    <!-- Quick stat pills -->
    <div class="flex [gap:0.5rem] flex-wrap">
        <?php
        $highlights = [
            'submitted'    => ['#1e4d8c','#eff6ff','Submitted'],
            'under_review' => ['#475569','#f8fafc','Under Review'],
            'approved'     => ['#1e4d8c','#f0f4fb','Evaluation Complete'],
        ];
        foreach ($highlights as $st => [$tc,$bg,$lbl]):
            $c = $counts[$st] ?? 0; if (!$c) continue;
        ?>
        <div class="pill <?= $st === 'submitted' ? 'pill-brand' : ($st === 'under_review' ? 'pill-progress' : 'pill-success') ?>">
            <?= $c ?> <?= $lbl ?>
        </div>
        <?php endforeach; ?>
    </div>
</div>

<!-- -- Search & Filter bar -- -->
<div class="neon-card mb-4 [padding:1rem_1.25rem]">
    <form method="GET">
        <input type="hidden" name="page" value="all_applications">
        <input type="hidden" name="filter" value="<?= sanitize($filter) ?>">
        <div class="flex flex-wrap [gap:0.75rem] items-end">
            <!-- Search -->
            <div class="flex-1 [min-width:200px]">
                <label class="[font-size:0.72rem] font-semibold [color:#64748b] uppercase [letter-spacing:0.05em] block [margin-bottom:5px]">Search</label>
                <div class="flex items-center overflow-hidden rounded-xl border border-slate-200 bg-slate-50 transition focus-within:border-brand-500 focus-within:ring-4 focus-within:ring-brand-100">
                    <span class="[padding:0_0.65rem] [color:#94a3b8] [font-size:0.85rem]">
                        <i class="bi bi-search"></i>
                    </span>
                    <input type="text" name="search" value="<?= sanitize($search) ?>"
                           placeholder="Faculty name, ID, campus..."
                           class="flex-1 border-0 outline-none bg-transparent [padding:0.5rem_0.5rem_0.5rem_0] [font-size:0.85rem] [color:#1e293b]">
                    <?php if ($search): ?>
                    <a href="?page=all_applications&filter=<?= urlencode($filter) ?><?= $filter_campus ? '&campus_id='.$filter_campus : '' ?>"
                       class="[padding:0_0.65rem] [color:#94a3b8] no-underline [font-size:0.85rem]">
                        <i class="bi bi-x"></i>
                    </a>
                    <?php endif; ?>
                </div>
            </div>
            <!-- Campus filter -->
            <div class="[min-width:180px]">
                <label class="[font-size:0.72rem] font-semibold [color:#64748b] uppercase [letter-spacing:0.05em] block [margin-bottom:5px]">Campus</label>
                <select name="campus_id"
                        class="form-select"
                        onchange="this.form.submit()">
                    <option value="">All Campuses</option>
                    <?php foreach ($campuses as $c): ?>
                    <option value="<?= $c['campus_id'] ?>" <?= $filter_campus === (int)$c['campus_id'] ? 'selected' : '' ?>>
                        <?= sanitize($c['campus_name']) ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <!-- Submit -->
            <button type="submit" class="btn btn-primary">
                <i class="bi bi-search"></i> Search
            </button>
            <?php if ($search || $filter_campus): ?>
            <a href="?page=all_applications&filter=<?= urlencode($filter) ?>"
               class="btn btn-secondary">
                <i class="bi bi-x-circle"></i> Clear
            </a>
            <?php endif; ?>
        </div>
    </form>
</div>

<!-- -- Status filter tabs -- -->
<div class="flex flex-wrap [gap:0.4rem] [margin-bottom:1.25rem]">
    <?php foreach ($tabs as $key => $t):
        $cnt    = $key === '' ? $total : ($counts[$key] ?? 0);
        $active = $filter === $key;
        $url    = '?page=all_applications&filter=' . $key
                . ($search      ? '&search='.urlencode($search) : '')
                . ($filter_campus ? '&campus_id='.$filter_campus  : '');
    ?>
    <a href="<?= $url ?>" class="btn btn-xs <?= $active ? 'btn-primary' : 'btn-secondary' ?>">
        <?= $t['label'] ?>
        <?php if ($cnt): ?>
        <span class="inline-flex size-4 min-w-4 items-center justify-center rounded-full px-1 text-[0.62rem] font-bold <?= $active ? 'bg-white/25 text-white' : 'bg-slate-100 text-slate-500' ?>">
            <?= $cnt ?>
        </span>
        <?php endif; ?>
    </a>
    <?php endforeach; ?>
</div>

<!-- -- Applications table / empty state -- -->
<?php if ($apps): ?>
<div class="neon-card p-0 overflow-hidden">
    <div class="table-responsive">
        <table class="table-modern mb-0 min-w-[900px]">
            <thead>
                <tr class="[background:#f8fafc] [border-bottom:2px_solid_#e2e8f0]">
                    <th class="[padding:0.75rem_1rem] text-left [font-size:0.7rem] font-bold uppercase [letter-spacing:0.05em] [color:#64748b] whitespace-nowrap">
                        Faculty
                    </th>
                    <th class="[padding:0.75rem_0.75rem] text-left [font-size:0.7rem] font-bold uppercase [letter-spacing:0.05em] [color:#64748b] whitespace-nowrap">
                        Campus
                    </th>
                    <th class="[padding:0.75rem_0.75rem] text-left [font-size:0.7rem] font-bold uppercase [letter-spacing:0.05em] [color:#64748b] whitespace-nowrap">
                        Cycle
                    </th>
                    <th class="[padding:0.75rem_0.75rem] text-center [font-size:0.7rem] font-bold uppercase [letter-spacing:0.05em] [color:#64748b] whitespace-nowrap">
                        Score
                    </th>
                    <th class="[padding:0.75rem_0.75rem] text-left [font-size:0.7rem] font-bold uppercase [letter-spacing:0.05em] [color:#64748b] whitespace-nowrap">
                        Status
                    </th>
                    <th class="[padding:0.75rem_0.75rem] text-left [font-size:0.7rem] font-bold uppercase [letter-spacing:0.05em] [color:#64748b] whitespace-nowrap">
                        Updated
                    </th>
                    <th class="[padding:0.75rem_0.75rem] text-center [font-size:0.7rem] font-bold uppercase [letter-spacing:0.05em] [color:#64748b] whitespace-nowrap">
                        Action
                    </th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($apps as $a):
                $score = (float)$a['weighted_score'];
                $sc    = scoreColor($score);
                $score_class = $score >= 71 ? 'bg-emerald-600' : ($score >= 41 ? 'bg-brand-600' : 'bg-rose-600');
            ?>
            <tr>

                <!-- Faculty -->
                <td class="[padding:0.85rem_1rem] [vertical-align:middle]">
                    <div class="flex items-center [gap:0.65rem]">
                        <?php
                        $a_parts  = explode(',', trim($a['full_name'] ?? 'U'));
                        $a_last   = trim($a_parts[0]);
                        $a_rest   = isset($a_parts[1]) ? trim($a_parts[1]) : '';
                        $a_first  = explode(' ', $a_rest)[0] ?? '';
                        $initials = strtoupper(substr($a_first, 0, 1) . substr($a_last, 0, 1));
                        $avatar_classes = ['bg-brand-700','bg-slate-700','bg-brand-600','bg-slate-600'];
                        $avatar_class = $avatar_classes[crc32($a['user_id']) % count($avatar_classes)];
                        ?>
                        <div class="flex size-9 shrink-0 items-center justify-center rounded-full text-xs font-bold text-white <?= $avatar_class ?>">
                            <?= $initials ?>
                        </div>
                        <div>
                            <div class="font-semibold [color:#1e293b] [font-size:0.84rem] leading-tight">
                                <?= sanitize($a['full_name'] ?? ' - ') ?>
                            </div>
                            <div class="[font-size:0.7rem] [color:#94a3b8] [margin-top:1px]">
                                <?= sanitize($a['employee_id'] ?? '') ?>
                                <?php if (!empty($a['rank'])): ?>
                                &nbsp;&middot;&nbsp;<?= sanitize($a['rank']) ?>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </td>

                <!-- Campus -->
                <td class="[padding:0.85rem_0.75rem] [vertical-align:middle]">
                    <span class="[font-size:0.82rem] [color:#475569]">
                        <?= sanitize($a['campus_name'] ?? ' - ') ?>
                    </span>
                </td>

                <!-- Cycle -->
                <td class="[padding:0.85rem_0.75rem] [vertical-align:middle] [max-width:160px]">
                    <span class="[font-size:0.78rem] [color:#64748b] whitespace-nowrap overflow-hidden [text-overflow:ellipsis] block" title="<?= sanitize($a['cycle_name'] ?? '') ?>">
                        <?= sanitize($a['cycle_name'] ?? ' - ') ?>
                    </span>
                </td>

                <!-- Score -->
                <td class="[padding:0.85rem_0.75rem] text-center [vertical-align:middle]">
                    <div class="inline-flex flex-col items-center [gap:2px]">
                        <span class="rounded-md px-2.5 py-1 text-[0.82rem] font-bold text-white <?= $score_class ?>">
                            <?= number_format($score, 2) ?>
                        </span>
                        <progress value="<?= min(100, round($score)) ?>" max="100" class="h-1 w-13 overflow-hidden rounded-full accent-brand-600"></progress>
                    </div>
                </td>

                <!-- Status -->
                <td class="[padding:0.85rem_0.75rem] [vertical-align:middle]">
                    <?= statusBadge($a['status']) ?>
                    <?php if (($a['approvals_count'] ?? 0) > 0 && in_array($a['status'],['under_review','talisay_review'])): ?>
                    <div class="[font-size:0.65rem] [color:#94a3b8] [margin-top:3px]">
                        <i class="bi bi-check-circle me-1"></i><?= $a['approvals_count'] ?> evaluator completion<?= $a['approvals_count']>1?'s':'' ?>
                    </div>
                    <?php endif; ?>
                </td>

                <!-- Updated -->
                <td class="[padding:0.85rem_0.75rem] [vertical-align:middle] whitespace-nowrap">
                    <div class="[font-size:0.78rem] [color:#475569]">
                        <?= $a['updated_at'] ? date('M d, Y', strtotime($a['updated_at'])) : ' - ' ?>
                    </div>
                    <div class="[font-size:0.67rem] [color:#94a3b8]">
                        <?= $a['updated_at'] ? date('H:i', strtotime($a['updated_at'])) : '' ?>
                    </div>
                </td>

                <!-- Action -->
                <td class="[padding:0.85rem_0.75rem] text-center [vertical-align:middle]">
                    <a href="?page=view_application&id=<?= $a['application_id'] ?>"
                       class="btn btn-outline-primary btn-xs">
                        <i class="bi bi-eye [font-size:0.75rem]"></i> View
                    </a>
                </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <!-- Table footer -->
    <div class="[padding:0.65rem_1rem] [background:#f8fafc] [border-top:1px_solid_#f0f4fb] flex items-center justify-between flex-wrap [gap:0.5rem]">
        <span class="[font-size:0.75rem] [color:#94a3b8]">
            Showing <strong class="[color:#1a3a6b]"><?= count($apps) ?></strong>
            <?= $filter ? 'filtered' : '' ?> application<?= count($apps) !== 1 ? 's' : '' ?>
            <?= $search ? ' matching <strong class="[color:#1a3a6b]">'.sanitize($search).'</strong>' : '' ?>
        </span>
        <?php if ($filter || $search || $filter_campus): ?>
        <a href="?page=all_applications"
           class="[font-size:0.72rem] [color:#64748b] no-underline font-semibold">
            <i class="bi bi-x-circle me-1"></i>Clear all filters
        </a>
        <?php endif; ?>
    </div>
</div>

<?php else: ?>
<!-- Empty state -->
<div class="neon-card [padding:3.5rem_1.5rem] text-center">
    <div class="[width:64px] [height:64px] [border-radius:16px] [background:#f0f4fb] flex items-center justify-center [margin:0_auto_1rem]">
        <i class="bi bi-inbox [font-size:1.75rem] [color:#94a3b8]"></i>
    </div>
    <div class="[font-size:1rem] font-semibold [color:#475569] [margin-bottom:0.35rem]">
        No applications found
    </div>
    <div class="[font-size:0.82rem] [color:#94a3b8] [margin-bottom:1.25rem]">
        <?= ($search || $filter || $filter_campus) ? 'Try adjusting your filters or search term.' : 'No faculty applications have been submitted yet.' ?>
    </div>
    <?php if ($search || $filter || $filter_campus): ?>
    <a href="?page=all_applications"
       class="inline-flex items-center [gap:0.4rem] [padding:0.5rem_1.25rem] [border-radius:8px] [background:#1a3a6b] [color:#fff] no-underline [font-size:0.82rem] font-semibold">
        <i class="bi bi-arrow-left"></i> View all applications
    </a>
    <?php endif; ?>
</div>
<?php endif; ?>
