<?php require __DIR__ . '/../../../backend/controllers/faculty/kra_analytics.php'; ?>

<div class="space-y-4 rounded-2xl border border-slate-200 bg-slate-50/70 p-3 sm:p-5">
  <?php if (empty($analytics_entries)): ?>
    <div class="empty-state rounded-2xl border border-slate-200 bg-white shadow-sm">
      <span class="empty-state-icon"><i class="bi bi-clipboard-data"></i></span>
      <div class="empty-state-title text-brand-900">Start filling in your Self Assessment and your dashboard will appear here.</div>
    </div>
  <?php else: ?>
    <div class="grid grid-cols-1 gap-3 sm:grid-cols-2 xl:grid-cols-4">
      <div class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm">
        <div class="text-xs font-semibold tracking-wide text-slate-500 uppercase">Self assessment progress</div>
        <div class="mt-1 text-3xl font-bold text-brand-700"><?= $analytics_progress_pct ?>%</div>
        <div class="mt-1 text-xs leading-5 text-slate-500"><?= $completed_saved_entries ?> saved entr<?= $completed_saved_entries === 1 ? 'y' : 'ies' ?> out of <?= $analytics_progress_denom ?> configured item<?= $analytics_progress_denom === 1 ? '' : 's' ?>.</div>
      </div>
      <div class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm">
        <div class="text-xs font-semibold tracking-wide text-slate-500 uppercase">Total projected points</div>
        <div class="mt-1 text-3xl font-bold text-slate-900"><?= number_format($analytics_total, 2) ?></div>
        <div class="mt-1 text-xs leading-5 text-slate-500">Weighted score is <?= number_format($analytics_weighted, 2) ?>.</div>
      </div>
      <div class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm">
        <div class="text-xs font-semibold tracking-wide text-slate-500 uppercase">Current position and sub-rank</div>
        <div class="mt-2 text-base font-bold text-slate-900"><?= htmlspecialchars($target_position ?: ($faculty['rank'] ?? 'Not set')) ?></div>
        <div class="mt-1 text-xs leading-5 text-slate-500"><?= $analytics_sub_rank > 0 ? '+' . $analytics_sub_rank . ' sub-rank(s)' : 'No sub-rank increase yet' ?>.</div>
      </div>
      <div class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm">
        <div class="text-xs font-semibold tracking-wide text-slate-500 uppercase">Days left before the cycle deadline</div>
        <div class="mt-1 text-2xl font-bold <?= $analytics_days_left !== null && $analytics_days_left < 0 ? 'text-rose-600' : 'text-slate-900' ?>"><?= htmlspecialchars($analytics_deadline_label) ?></div>
        <div class="mt-1 text-xs leading-5 text-slate-500">Submission deadline: <?= !empty($_effective_deadline) ? date('M d, Y', strtotime($_effective_deadline)) : 'Not set' ?>.</div>
      </div>
    </div>

    <section class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm sm:p-5">
      <h3 class="m-0 text-base font-semibold text-brand-900">Score per KRA</h3>
      <p class="mt-1 mb-4 text-xs leading-5 text-slate-500">JC 2026 means Joint Circular No. 01, series of 2026. The dashboard uses the saved entries and the existing scoring logic for this cycle.</p>
      <?php foreach ($analytics_kras as $kra_name => $meta):
          $earned = $analytics_points[$kra_name] ?? 0;
          $max = (float)$meta['max'];
          $pct = $max > 0 ? min(100, ($earned / $max) * 100) : 0;
          $room = max(0, $max - $earned);
      ?>
      <div class="mb-4 last:mb-0">
        <div class="mb-1.5 flex flex-wrap items-center justify-between gap-2">
          <strong class="text-sm text-slate-800"><?= $meta['label'] ?>, <?= htmlspecialchars($kra_name) ?></strong>
          <span class="text-xs text-slate-600"><?= number_format($earned, 2) ?> of <?= number_format($max, 2) ?> points</span>
        </div>
        <progress class="portal-progress block h-2.5 w-full text-brand-700" value="<?= $pct ?>" max="100"><?= number_format($pct) ?>%</progress>
        <div class="mt-1.5 text-xs leading-5 text-slate-500">You have <?= number_format($earned, 2) ?> of <?= number_format($max, 2) ?> points. <?= $room > 0 ? 'This KRA still has ' . number_format($room, 2) . ' unused point(s).' : 'This KRA has reached its current maximum.' ?></div>
      </div>
      <?php endforeach; ?>
      <?php if (!empty($analytics_pending_confirmation)): ?>
      <div class="mt-3 rounded-xl border border-amber-200 bg-amber-50 p-3 text-xs leading-5 text-amber-800">
        <strong>Pending confirmation:</strong>
        <?php foreach (array_slice($analytics_pending_confirmation, 0, 5) as $pending): ?>
          <span><?= htmlspecialchars($pending['kra']) ?>, <?= htmlspecialchars($pending['label']) ?>. </span>
        <?php endforeach; ?>
        These items are configured at 0 maximum points and are not counted.
      </div>
      <?php endif; ?>
    </section>

    <section class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm sm:p-5">
      <h3 class="m-0 text-base font-semibold text-brand-900">Gap to next rank</h3>
      <p class="mt-2 mb-0 text-sm leading-6 text-slate-600">
        <?= $points_needed > 0 ? 'You need ' . number_format($points_needed, 2) . ' more weighted point(s) to reach the next sub-rank bracket.' : 'You are already in the highest configured score bracket.' ?>
        <?= htmlspecialchars($most_room_kra) ?> has the most unused points.
      </p>
    </section>

    <section class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm sm:p-5">
      <h3 class="m-0 mb-3 text-base font-semibold text-brand-900">What to do next</h3>
      <?php if (empty($analytics_actions)): ?>
        <div class="text-sm text-slate-500">No recommended actions right now.</div>
      <?php else: ?>
        <div class="space-y-2">
          <?php foreach ($analytics_actions as $action):
              $tab = $analytics_kras[$action['kra']]['tab'] ?? 'instruction';
              $hash = !empty($action['criterion_key']) ? '#criterion-' . rawurlencode((string)$action['criterion_key']) : '';
          ?>
          <a href="?page=apply&tab=<?= urlencode($tab) ?><?= $action['sid'] ? '&edit_sid=' . (int)$action['sid'] : '' ?><?= htmlspecialchars($hash) ?>"
             data-kra-tab="<?= htmlspecialchars($tab) ?>"
             data-submission-id="<?= (int)$action['sid'] ?>"
             class="analytics-entry-link flex items-start justify-between gap-3 rounded-xl border border-slate-200 bg-white p-3 text-sm text-slate-800 no-underline transition hover:border-brand-200 hover:bg-brand-50/40">
            <span><strong><?= htmlspecialchars($action['kra']) ?>.</strong> <?= htmlspecialchars($action['text']) ?></span>
            <span class="whitespace-nowrap text-xs font-bold text-brand-700"><?= number_format((float)$action['score'], 2) ?> pts</span>
          </a>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </section>

    <section class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm sm:p-5">
      <h3 class="m-0 mb-3 text-base font-semibold text-brand-900">Problems to fix before submitting</h3>
      <?php if (empty($analytics_problem_rows)): ?>
        <span class="inline-flex items-center rounded-full border border-emerald-200 bg-emerald-50 px-2.5 py-1 text-xs font-bold text-emerald-700">Good: No problems found.</span>
      <?php else: ?>
        <div class="space-y-2">
          <?php foreach ($analytics_problem_rows as $problem):
              $is_block = $problem['status'] === 'Blocking issue';
          ?>
          <div class="rounded-xl border p-3 <?= $is_block ? 'border-rose-200 border-l-4 border-l-rose-600 bg-rose-50' : 'border-amber-200 border-l-4 border-l-amber-600 bg-amber-50' ?>">
            <div class="text-sm font-bold <?= $is_block ? 'text-rose-800' : 'text-amber-800' ?>"><?= htmlspecialchars($problem['status']) ?>: <?= htmlspecialchars($problem['title']) ?></div>
            <div class="mt-1 text-xs leading-5 text-slate-600">Why it matters: <?= htmlspecialchars($problem['why']) ?></div>
            <div class="text-xs leading-5 text-slate-600">How to fix it: <?= htmlspecialchars($problem['fix']) ?></div>
          </div>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </section>

    <section class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm sm:p-5">
      <h3 class="m-0 mb-3 text-base font-semibold text-brand-900">Evidence completeness</h3>
      <div class="grid grid-cols-1 gap-3 sm:grid-cols-2 xl:grid-cols-4">
        <?php foreach ($analytics_kras as $kra_name => $meta):
            $entries = $analytics_by_kra[$kra_name] ?? [];
            $total = count($entries);
            $with = 0;
            foreach ($entries as $entry) {
                if ((int)($entry['evidence_count'] ?? 0) > 0 || !empty($entry['document_path'])) $with++;
            }
            $pct = $total > 0 ? (int)round(($with / $total) * 100) : 0;
            $evidence_status = $pct === 100 ? 'Good' : ($pct >= 50 ? 'Needs attention' : 'Blocking issue');
        ?>
        <div class="rounded-xl border border-slate-200 bg-slate-50/60 p-3">
          <div class="text-sm font-bold text-slate-800"><?= $meta['label'] ?></div>
          <div class="mt-1 text-xl font-bold <?= $pct === 100 ? 'text-emerald-600' : ($pct >= 50 ? 'text-amber-600' : 'text-rose-600') ?>"><?= $pct ?>% <span class="text-xs"><?= htmlspecialchars($evidence_status) ?></span></div>
          <div class="mt-1 text-xs text-slate-500"><?= $with ?> of <?= $total ?> entr<?= $total === 1 ? 'y has' : 'ies have' ?> evidence.</div>
        </div>
        <?php endforeach; ?>
      </div>
    </section>

    <section class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm sm:p-5">
      <h3 class="m-0 mb-3 text-base font-semibold text-brand-900">Incomplete entries</h3>
      <?php
      $has_incomplete = false;
      foreach ($analytics_kras as $kra_name => $meta):
          $items = [];
          foreach (($analytics_by_kra[$kra_name] ?? []) as $entry) {
              $missing = $analytics_missing_fields($entry);
              if ($missing) $items[] = [$entry, $missing];
          }
          if (!$items) continue;
          $has_incomplete = true;
      ?>
      <div class="mb-4">
        <div class="mb-2 text-sm font-bold text-slate-800"><?= $meta['label'] ?>, <?= htmlspecialchars($kra_name) ?></div>
        <div class="space-y-1.5">
          <?php foreach ($items as [$entry, $missing]): ?>
          <a data-kra-tab="<?= htmlspecialchars($meta['tab']) ?>" data-submission-id="<?= (int)$entry['submission_id'] ?>"
             href="?page=apply&tab=<?= urlencode($meta['tab']) ?>&edit_sid=<?= (int)$entry['submission_id'] ?>"
             class="analytics-entry-link block rounded-xl border border-slate-200 bg-white p-3 text-sm text-slate-700 no-underline transition hover:border-brand-200 hover:bg-brand-50/40">
            <?= htmlspecialchars($analytics_entry_title($entry)) ?> is missing <?= htmlspecialchars(implode(', ', $missing)) ?>.
          </a>
          <?php endforeach; ?>
        </div>
      </div>
      <?php endforeach; ?>
      <?php if (!$has_incomplete): ?>
        <span class="inline-flex items-center rounded-full border border-emerald-200 bg-emerald-50 px-2.5 py-1 text-xs font-bold text-emerald-700">Good: No incomplete saved entries found.</span>
      <?php endif; ?>
    </section>

    <section class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm sm:p-5">
      <h3 class="m-0 mb-3 text-base font-semibold text-brand-900">Progress across cycles</h3>
      <?php if (!$has_prior_cycles): ?>
        <div class="text-sm text-slate-500">Earlier cycles are not available yet.</div>
      <?php else: ?>
        <?php
        $cycle_max = max(1, ...array_map(fn($row) => (float)($row['saved_score'] ?? 0), $analytics_cycles));
        foreach ($analytics_cycles as $cycle_row):
            $bar = min(100, ((float)$cycle_row['saved_score'] / $cycle_max) * 100);
        ?>
        <div class="mb-4 last:mb-0">
          <div class="mb-1.5 flex flex-wrap justify-between gap-2 text-xs text-slate-700">
            <strong><?= htmlspecialchars($cycle_row['cycle_name'] ?: 'Cycle #' . $cycle_row['application_id']) ?></strong>
            <span><?= number_format((float)$cycle_row['saved_score'], 2) ?> total points</span>
          </div>
          <progress class="portal-progress block h-2.5 w-full text-brand-700" value="<?= $bar ?>" max="100"><?= number_format($bar) ?>%</progress>
          <div class="mt-2 grid grid-cols-2 gap-1 text-xs text-slate-500 sm:grid-cols-4">
            <span>KRA I: <?= number_format((float)$cycle_row['instruction_points'], 2) ?></span>
            <span>KRA II: <?= number_format((float)$cycle_row['research_points'], 2) ?></span>
            <span>KRA III: <?= number_format((float)$cycle_row['extension_points'], 2) ?></span>
            <span>KRA IV: <?= number_format((float)$cycle_row['profdev_points'], 2) ?></span>
          </div>
        </div>
        <?php endforeach; ?>
      <?php endif; ?>
    </section>
  <?php endif; ?>
</div>

