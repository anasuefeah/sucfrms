<?php require __DIR__ . '/../../../backend/controllers/faculty/kra_entry.php'; ?>


<!-- -- KRA Entries Card -------------------------------------- -->
<div class="neon-card p-0 mb-4 [overflow:hidden]">
  <!-- Card Header -->
  <div class="[background:linear-gradient(135deg,#1e3a6b,#1e4d8c)] [padding:1rem_1.5rem] [display:flex] [justify-content:space-between] [align-items:center] [flex-wrap:wrap] [gap:0.75rem]">
    <div>
      <h5 class="[color:#fff] [font-weight:700] [font-size:0.97rem] [margin:0] [line-height:1.3] [display:flex] [align-items:center] [gap:0.5rem]">
        <i class="bi bi-file-earmark-arrow-up [font-size:1.1rem] [opacity:0.9]"></i>
        KRA Entries &amp; Evidence
        <?= helpBtn('KRA Entry Guide', 'For each KRA tab: 1) Open the entry table for the criterion you need. 2) Select the criterion type and fill in the details ... the score calculates automatically. 3) Click the upload button on each row to attach your evidence file. 4) Click Done. Once all entries are saved, click Submit Application.') ?>
      </h5>
      <?php if (!empty($_effective_deadline)): ?>
      <?php $dl_ts = strtotime($_effective_deadline . ' 23:59:59'); ?>
      <div class="[margin-top:0.3rem] [font-size:0.78rem] [color:rgba(255,255,255,0.75)] [display:flex] [align-items:center] [gap:0.4rem]">
        <i class="bi bi-clock"></i>
        Submission deadline: <strong style="color:<?= time() > $dl_ts ? '#94a3b8' : '#e2e8f0' ?>;"><?= date('M d, Y', strtotime($_effective_deadline)) ?></strong>
        <?php if ($_submission_not_open): ?>
        <span class="[background:#334155] [color:#fff] [border-radius:20px] [padding:0.1rem_0.5rem] [font-size:0.65rem] [font-weight:700]">Not open</span>
        <?php elseif (time() > $dl_ts): ?>
        <span class="[background:#334155] [color:#fff] [border-radius:20px] [padding:0.1rem_0.5rem] [font-size:0.65rem] [font-weight:700]">Passed</span>
        <?php else: ?>
        <span class="[background:#475569] [color:#fff] [border-radius:20px] [padding:0.1rem_0.5rem] [font-size:0.65rem] [font-weight:700]"><?= ceil(($dl_ts - time()) / 86400) ?> day(s) left</span>
        <?php endif; ?>
      </div>
      <?php endif; ?>
    </div>
    <div class="[display:flex] [gap:0.5rem] [align-items:center] [flex-wrap:wrap]" id="kra-entry-actions">
      <a href="index.php?page=dashboard"
         class="[background:rgba(255,255,255,0.12)] [color:#fff] [border:1px_solid_rgba(255,255,255,0.25)] [border-radius:7px] [padding:0.4rem_0.9rem] [font-size:0.82rem] [font-weight:600] [text-decoration:none] [display:inline-flex] [align-items:center] [gap:0.35rem] [white-space:nowrap] [transition:background_0.15s]">
        <i class="bi bi-arrow-left"></i>Back
      </a>
      <?php if (!$locked && $grand2 > 0): ?>
      <?php
        $faculty_rank_btn = $faculty['rank'] ?? '';
        $score_btn = getApplicationScoreSummary($pdo, (int)$app_id);
        $can_submit_step2 = $score_btn['weighted_score'] >= 41;
        $deadline_passed_step2 = $_deadline_passed;
      ?>
      <?php if ($deadline_passed_step2): ?>
      <button type="button" disabled
              class="[background:rgba(255,255,255,0.1)] [color:rgba(255,255,255,0.5)] [border:1px_solid_rgba(255,255,255,0.15)] [border-radius:7px] [padding:0.4rem_0.9rem] [font-size:0.82rem] [font-weight:600] [cursor:not-allowed] [display:inline-flex] [align-items:center] [gap:0.35rem] [white-space:nowrap]">
        <i class="bi bi-calendar-x"></i>Deadline Passed
      </button>
      <?php elseif ($can_submit_step2): ?>
      <form method="POST" class="d-inline m-0 p-0 [line-height:0]" id="submitAppForm">
        <input type="hidden" name="kra_action" value="submit_application">
        <button type="button"
                class="[background:#fff] [color:#1e4d8c] [border:none] [border-radius:7px] [padding:0.4rem_1rem] [font-size:0.82rem] [font-weight:700] [cursor:pointer] [display:inline-flex] [align-items:center] [gap:0.35rem] [white-space:nowrap] [box-shadow:0_2px_8px_rgba(0,0,0,0.15)]"
                onclick="openSubmitModal()">
          <i class="bi bi-send"></i>Submit Application
        </button>
      </form>
      <?php else: ?>
      <button type="button" disabled
              title="Weighted score (<?= number_format($score_btn['weighted_score'], 2) ?>) is below the minimum 41.00 required."
              class="[background:rgba(255,255,255,0.1)] [color:rgba(255,255,255,0.5)] [border:1px_solid_rgba(255,255,255,0.15)] [border-radius:7px] [padding:0.4rem_0.9rem] [font-size:0.82rem] [font-weight:600] [cursor:not-allowed] [display:inline-flex] [align-items:center] [gap:0.35rem] [white-space:nowrap]">
        <i class="bi bi-send"></i>Submit Application
      </button>
      <?php endif; ?>
      <?php endif; ?>
      <?php if (in_array($active_tab, ['instruction','research','extension','profdev'])): ?>
      <?php $knum_map = ['instruction'=>'I','research'=>'II','extension'=>'III','profdev'=>'IV']; ?>
      <a href="backend/services/reports/kra_pdf.php?uid=<?= $_SESSION['user_id'] ?>&cycle_id=<?= $cycle['cycle_id'] ?>&kra=all" target="_blank"
         class="[background:#fff] [color:#1e4d8c] [border:none] [border-radius:7px] [padding:0.4rem_0.8rem] [font-size:0.78rem] [font-weight:700] [text-decoration:none] [display:inline-flex] [align-items:center] [gap:0.3rem] [white-space:nowrap] [box-shadow:0_2px_8px_rgba(0,0,0,0.12)]">
        <i class="bi bi-files"></i>ISS / All KRA
      </a>
      <a href="backend/services/reports/kra_pdf.php?uid=<?= $_SESSION['user_id'] ?>&cycle_id=<?= $cycle['cycle_id'] ?>&kra=<?= urlencode($cur_cat) ?>" target="_blank"
         class="[background:rgba(255,255,255,0.12)] [color:#fff] [border:1px_solid_rgba(255,255,255,0.25)] [border-radius:7px] [padding:0.4rem_0.7rem] [font-size:0.78rem] [font-weight:600] [text-decoration:none] [display:inline-flex] [align-items:center] [gap:0.3rem] [white-space:nowrap]">
        <i class="bi bi-printer"></i>KRA <?= $knum_map[$active_tab] ?>
      </a>
      <?php endif; ?>
    </div>
  </div>

  <!-- Alert banners -->
  <div class="[padding:0_1.25rem]">
  <?php if ($locked): ?>
  <div class="[margin-top:0.85rem] [padding:0.65rem_1rem] [background:#eff6ff] [border:1px_solid_#bfdbfe] [border-left:4px_solid_#1e4d8c] [border-radius:8px] [font-size:0.82rem] [color:#1e4d8c]">
    <i class="bi bi-lock-fill me-2"></i>Evaluation complete - scores are locked.
  </div>
  <?php elseif ($_deadline_passed && !in_array($app['status'], ['needs_revision', 'rejected', 'approved', 'reclassified', 'admin_rejected'])): ?>
  <div class="[margin-top:0.85rem] [padding:0.65rem_1rem] [background:#fef2f2] [border:1px_solid_#fecaca] [border-left:4px_solid_#dc2626] [border-radius:8px] [font-size:0.82rem] [color:#991b1b]">
    <i class="bi bi-calendar-x-fill me-2"></i>
    <strong>Submission deadline has passed.</strong> You can no longer add or edit KRA entries for this cycle.
  </div>
  <?php elseif ($app['status'] === 'needs_revision'): ?>
  <div class="[margin-top:0.85rem] [padding:0.65rem_1rem] [background:#f8fafc] [border:1px_solid_#e2e8f0] [border-left:4px_solid_#475569] [border-radius:8px] [font-size:0.82rem] [color:#334155]">
    <i class="bi bi-exclamation-triangle-fill me-2 [color:#475569]"></i>
    <strong>Revision requested by evaluator.</strong> Only flagged entries can be edited. Fix the highlighted entries and submit your revision from the Dashboard.
  </div>
  <?php elseif ($app['status'] === 'rejected'): ?>
  <div class="[margin-top:0.85rem] [padding:0.65rem_1rem] [background:#f8fafc] [border:1px_solid_#e2e8f0] [border-left:4px_solid_#475569] [border-radius:8px] [font-size:0.82rem] [color:#334155]">
    <i class="bi bi-arrow-counterclockwise me-2 [color:#475569]"></i>
    <strong>Returned for revision by evaluator.</strong> You can edit your entries and resubmit.
  </div>
  <?php endif; ?>

  <?php
  // -- Self-assessment advisory: show only concrete detected issues.
  $app_pending  = json_decode($app['pending_documentation'] ?? '[]', true) ?: [];
  $app_config   = json_decode($app['config_incomplete'] ?? '[]', true) ?: [];
  $app_double_raw = json_decode($app['double_counting_flags'] ?? '[]', true) ?: [];
  $app_previous_reuse = array_values(array_filter($app_double_raw, fn($d) => ($d['type'] ?? '') === 'previous_unsuccessful_reuse'));
  $app_double = array_values(array_filter($app_double_raw, fn($d) => ($d['type'] ?? '') !== 'previous_unsuccessful_reuse'));
  $eval_period_ok = isset($app['evaluation_period_ok']) ? (bool)$app['evaluation_period_ok'] : true;
  $has_advisory = !$eval_period_ok || !empty($app_pending) || !empty($app_config) || !empty($app_double) || !empty($app_previous_reuse);

  if ($has_advisory && !$locked):
  ?>
  <div class="[margin-top:0.85rem] [border:1px_solid_#fde68a] [border-left:4px_solid_#f59e0b] [border-radius:8px] [overflow:hidden] [font-size:0.8rem]">
    <div class="[padding:0.5rem_0.85rem] [background:#fffbeb] [display:flex] [align-items:center] [gap:0.5rem] [font-weight:700] [color:#92400e]">
      <i class="bi bi-clipboard2-pulse"></i> Self-Assessment Advisory (JC01 s.2026)
      <span class="[margin-left:auto] [font-weight:400] [color:#b45309] [font-size:0.72rem]">Advisory only ... your evaluator makes the binding decision</span>
    </div>
    <div class="[padding:0.6rem_0.85rem] [background:#fffdf0]">
      <?php if (!$eval_period_ok): ?>
      <div class="[color:#dc2626] [margin-bottom:0.35rem]"><i class="bi bi-calendar-x me-1"></i>Application is linked to an archived cycle. Contact your administrator.</div>
      <?php endif; ?>
      <?php foreach (array_slice($app_previous_reuse, 0, 5) as $reuse): ?>
      <div class="[color:#dc2626] [margin-bottom:0.25rem]"><i class="bi bi-file-earmark-x me-1"></i><strong>Previous unsuccessful application reuse:</strong> <?= htmlspecialchars($reuse['message'] ?? ($reuse['title'] ?? 'Evidence file')) ?></div>
      <?php endforeach; ?>
      <?php foreach (array_slice($app_double, 0, 3) as $d): ?>
      <div class="[color:#c2410c] [margin-bottom:0.25rem]"><i class="bi bi-copy me-1"></i><strong>Double-counting flagged:</strong> "<?= htmlspecialchars($d['title'] ?? '') ?>"</div>
      <?php endforeach; ?>
      <?php foreach (array_slice($app_pending, 0, 5) as $pd): ?>
      <div class="[color:#92400e] [margin-bottom:0.2rem]"><i class="bi bi-file-earmark-x me-1"></i><?= htmlspecialchars($pd) ?></div>
      <?php endforeach; ?>
      <?php foreach (array_slice($app_config, 0, 3) as $ci): ?>
      <div class="[color:#7c3aed] [margin-bottom:0.2rem]"><i class="bi bi-wrench me-1"></i><?= htmlspecialchars($ci) ?></div>
      <?php endforeach; ?>
      <?php $total_items = count($app_pending) + count($app_config) + count($app_double) + count($app_previous_reuse); ?>
      <?php if ($total_items > 16): ?>
      <div class="[color:#94a3b8] [font-size:0.72rem] [margin-top:0.25rem]">+ more items ... full details visible to your evaluator.</div>
      <?php endif; ?>
    </div>
  </div>
  <?php endif; ?>

  </div>

  <!-- KRA Tabs -->
  <div class="[background:#f8fafc] [padding:0.6rem_1rem] [display:flex] [gap:0.4rem] [overflow-x:auto] [flex-wrap:nowrap] [border-bottom:1px_solid_#e2e8f0]">
    <?php foreach ($tabs as $slug => $t): 
        // Only show points badge for KRA tabs, not for Auto Sub Rank or Position Requirements
        $is_kra_tab = in_array($slug, ['instruction', 'research', 'extension', 'profdev']);
        $pts = $is_kra_tab ? $kra_totals2[$t['cat']] ?? 0 : 0;
        $status_text = '';
        // Compute percentage for KRA tabs
        $pct_text = '';
        if ($is_kra_tab) {
            $pct_text = '(' . $pts . '%)';
        }
        
        if ($slug === 'autosubrank') {
            // Get Auto Sub Rank status from new mode columns
            if (!class_exists('\Scoring\AutoSubRankCalculator')) {
                require_once __DIR__ . '/../../../backend/services/scoring/autosubrank.php';
            }
            $asr_tab = \Scoring\AutoSubRankCalculator::loadForApplication($pdo, $app_id);
            $d_mode  = $asr_tab['doctorate_mode'] ?? 'not_eligible';
            $a_mode  = $asr_tab['award_mode']     ?? 'not_eligible';
            if ($d_mode === 'triggered' || $a_mode === 'triggered') {
                $status_text = '+1 rank';
            } elseif (!$asr_tab) {
                $status_text = 'Pending';
            } else {
                $status_text = 'None';
            }
        } elseif ($slug === 'posreq') {
            // Get Position Requirements status  
            $pos_req = $pdo->prepare("SELECT * FROM position_requirements WHERE application_id = ?");
            $pos_req->execute([$app_id]);
            $pos_data = $pos_req->fetch();
            $faculty_rank = $faculty['rank'] ?? '';
            
            if (strpos($faculty_rank, 'Professor') !== false && strpos($faculty_rank, 'Assistant') === false && strpos($faculty_rank, 'Associate') === false) {
                // Professor rank - needs CAV transcript and international article
                if ($pos_data && $pos_data['cav_transcript'] === 'Uploaded' && $pos_data['international_article'] === 'Uploaded') {
                    $status_text = 'Complete';
                } else {
                    $status_text = 'Incomplete';
                }
            } elseif (strpos($faculty_rank, 'University Professor') !== false) {
                // University Professor - needs certification form
                if ($pos_data && $pos_data['certification_form'] === 'Uploaded') {
                    $status_text = 'Complete';
                } else {
                    $status_text = 'Incomplete';
                }
            } else {
                $status_text = 'N/A';
            }
        }
        $is_active = ($active_tab === $slug);
    ?>
    <a href="?page=apply&tab=<?= $slug ?>"
       style="flex-shrink:0;display:inline-flex;align-items:center;padding:0.35rem 0.85rem;font-size:0.78rem;font-weight:600;text-decoration:none;border-radius:6px;white-space:nowrap;border:1px solid;transition:all 0.15s;
              <?= $is_active
                  ? 'background:#1e4d8c;color:#fff;border-color:#1e4d8c;'
                  : 'background:#fff;color:#64748b;border-color:#e2e8f0;' ?>">
      <?= $t['label'] ?>
    </a>
    <?php endforeach; ?>
  </div>

  <!-- Toolbar strip -->
  <div class="[background:#f8fafc] [padding:0.5rem_1rem] [display:flex] [align-items:center] [border-bottom:1px_solid_#e2e8f0] [font-size:0.8rem]">
    <button onclick="location.reload()" class="[background:none] [border:none] [color:#1e4d8c] [cursor:pointer] [font-size:0.78rem] [display:flex] [align-items:center] [gap:0.3rem] [margin-left:auto]">
      <i class="bi bi-arrow-clockwise"></i> Refresh
    </button>
  </div>

  <!-- Tab Content -->
  <div class="[background:#ffffff] [padding:1rem] [min-height:300px]">
    
    <?php if ($cur_cat === 'Auto Sub Rank'): ?>
    <!-- AUTO SUB RANK TAB CONTENT  -  fully automatic, read-only -->
    <?php
    if (!class_exists('\Scoring\AutoSubRankCalculator')) {
        require_once __DIR__ . '/../../../backend/services/scoring/autosubrank.php';
    }
    // Recalculate automatically on every page load
    $asr_calc   = new \Scoring\AutoSubRankCalculator($pdo, $app_id);
    $asr_result = $asr_calc->calculate();
    $asr_calc->persist($asr_result);
    $asr_row     = \Scoring\AutoSubRankCalculator::loadForApplication($pdo, $app_id) ?: [];
    $d_mode      = $asr_result['doctorate_mode'];
    $a_mode      = $asr_result['award_mode'];
    $d_color     = \Scoring\AutoSubRankCalculator::modeColor($d_mode);
    $a_color     = \Scoring\AutoSubRankCalculator::modeColor($a_mode);
    $d_label     = \Scoring\AutoSubRankCalculator::modeLabel($d_mode);
    $a_label     = \Scoring\AutoSubRankCalculator::modeLabel($a_mode);
    $d_verified  = $asr_row['doctorate_verified'] ?? 'pending';
    $a_verified  = $asr_row['award_verified']     ?? 'pending';
    $dv_color    = \Scoring\AutoSubRankCalculator::verifiedColor($d_verified);
    $av_color    = \Scoring\AutoSubRankCalculator::verifiedColor($a_verified);
    $asr_status_label = static function ($status) {
        return match ($status) {
            'verified' => 'Acceptable',
            'rejected' => 'Not Acceptable',
            default => 'Pending Review',
        };
    };
    $rank_increase = $asr_result['total_rank_increase'];
    ?>

    <!-- Section header -->
    <div class="[display:flex] [align-items:center] [gap:0.65rem] [margin-bottom:1.25rem]">
      <div class="[width:38px] [height:38px] [border-radius:10px] [background:#1e4d8c] [display:flex] [align-items:center] [justify-content:center] [flex-shrink:0] [box-shadow:0_2px_8px_rgba(0,0,0,0.15)]">
        <i class="bi bi-arrow-up-circle [color:#fff] [font-size:1.1rem]"></i>
      </div>
      <div>
        <h6 class="[font-weight:700] [color:#1a3a6b] [margin:0] [font-size:0.95rem] [line-height:1.2]">Automatic Sub-Rank Assessment</h6>
        <div class="[font-size:0.73rem] [color:#64748b] [margin-top:2px]">
          <i class="bi bi-cpu me-1"></i>System-calculated  -  no manual choice required
        </div>
      </div>
      <?php if ($rank_increase > 0): ?>
      <div class="[margin-left:auto] [background:#f0fdf4] [border:1.5px_solid_#bbf7d0] [border-radius:8px] [padding:0.35rem_0.85rem] [display:flex] [align-items:center] [gap:0.4rem]">
        <i class="bi bi-arrow-up-circle-fill [color:#16a34a] [font-size:1rem]"></i>
        <span class="[font-weight:700] [color:#16a34a] [font-size:0.88rem]">+<?= $rank_increase ?> sub-rank<?= $rank_increase > 1 ? 's' : '' ?></span>
      </div>
      <?php else: ?>
      <div class="[margin-left:auto] [background:#f8fafc] [border:1px_solid_#e2e8f0] [border-radius:8px] [padding:0.35rem_0.85rem] [display:flex] [align-items:center] [gap:0.4rem]">
        <i class="bi bi-dash-circle [color:#64748b] [font-size:1rem]"></i>
        <span class="[font-weight:600] [color:#64748b] [font-size:0.88rem]">No sub-rank increase</span>
      </div>
      <?php endif; ?>
    </div>

    <!-- Criterion 1: Doctorate -->
    <div class="[border:1px_solid_#e2e8f0] [border-radius:10px] [overflow:hidden] [margin-bottom:0.85rem]">
      <div class="[background:#f8fafc] [border-bottom:1px_solid_#e2e8f0] [padding:0.55rem_1rem] [display:flex] [align-items:center] [justify-content:space-between] [flex-wrap:wrap] [gap:0.5rem]">
        <span class="[font-size:0.72rem] [font-weight:700] [color:#475569] [text-transform:uppercase] [letter-spacing:0.06em]">
          <i class="bi bi-mortarboard me-1"></i>Criterion 1  -  Doctorate Degree
        </span>
        <div class="[display:flex] [align-items:center] [gap:0.5rem] [flex-wrap:wrap]">
          <span style="background:<?= $d_color ?>18;color:<?= $d_color ?>;border:1px solid <?= $d_color ?>40;padding:0.2rem 0.7rem;border-radius:20px;font-size:0.68rem;font-weight:700;">
            <?= htmlspecialchars($d_label) ?>
          </span>
          <span style="background:<?= $dv_color ?>12;color:<?= $dv_color ?>;border:1px solid <?= $dv_color ?>40;padding:0.2rem 0.7rem;border-radius:20px;font-size:0.67rem;font-weight:600;">
            <i class="bi bi-shield me-1"></i>Evaluator: <?= $asr_status_label($d_verified) ?>
          </span>
        </div>
      </div>
      <div class="[padding:0.85rem_1rem]">
        <?php if ($asr_result['has_doctorate']): ?>
        <div class="[display:flex] [gap:1rem] [flex-wrap:wrap] [align-items:flex-start]">
          <div class="[flex:1] [min-width:180px]">
            <div class="[font-size:0.72rem] [color:#94a3b8] [font-weight:600] [text-transform:uppercase] [letter-spacing:0.05em] [margin-bottom:0.3rem]">Detected From</div>
            <div class="[font-size:0.85rem] [color:#1e293b] [font-weight:500]"><?= htmlspecialchars($asr_result['doctorate_details'] ?: 'Doctorate (Professional Development)') ?></div>
          </div>
          <div class="[flex:1] [min-width:200px]">
            <div class="[font-size:0.72rem] [color:#94a3b8] [font-weight:600] [text-transform:uppercase] [letter-spacing:0.05em] [margin-bottom:0.3rem]">Why This Decision</div>
            <div class="[font-size:0.82rem] [color:#475569] [line-height:1.5]"><?= htmlspecialchars($asr_result['doctorate_reason']) ?></div>
          </div>
          <?php if ($d_mode === 'points_only' || $d_mode === 'blocked_historical'): ?>
          <div class="[background:#eff6ff] [border:1px_solid_#bfdbfe] [border-radius:6px] [padding:0.5rem_0.75rem] [font-size:0.75rem] [color:#1e4d8c] [width:100%]">
            <i class="bi bi-info-circle me-1"></i>Doctorate contributes <strong><?= (int)\Scoring\AutoSubRankCalculator::DOCTORATE_POINTS ?> points</strong> to your KRA IV score.
          </div>
          <?php endif; ?>
          <?php if (!empty($asr_row['doctorate_verification_notes']) && $d_verified !== 'pending'): ?>
          <div style="background:<?= $dv_color ?>0e;border:1px solid <?= $dv_color ?>30;border-radius:6px;padding:0.5rem 0.75rem;font-size:0.75rem;color:<?= $dv_color ?>;width:100%;">
            <i class="bi bi-chat-left-text me-1"></i><strong>Evaluator note:</strong> <?= htmlspecialchars($asr_row['doctorate_verification_notes']) ?>
          </div>
          <?php endif; ?>
        </div>
        <?php else: ?>
        <div class="[display:flex] [align-items:center] [gap:0.75rem] [color:#64748b] [font-size:0.84rem]">
          <i class="bi bi-exclamation-circle [font-size:1.1rem] [color:#94a3b8] [flex-shrink:0]"></i>
          <div>No doctorate entry found in Professional Development.
            <a href="?page=apply&tab=profdev" class="[color:#1e4d8c] [font-weight:600] [margin-left:0.3rem]">
              <i class="bi bi-plus-circle me-1"></i>Add doctorate in Prof. Development
            </a>
          </div>
        </div>
        <?php endif; ?>
      </div>
    </div>

    <!-- Criterion 2: Prestigious Award -->
    <div class="[border:1px_solid_#e2e8f0] [border-radius:10px] [overflow:hidden] [margin-bottom:0.85rem]">
      <div class="[background:#f8fafc] [border-bottom:1px_solid_#e2e8f0] [padding:0.55rem_1rem] [display:flex] [align-items:center] [justify-content:space-between] [flex-wrap:wrap] [gap:0.5rem]">
        <span class="[font-size:0.72rem] [font-weight:700] [color:#475569] [text-transform:uppercase] [letter-spacing:0.06em]">
          <i class="bi bi-trophy me-1"></i>Criterion 2  -  National / International Award
        </span>
        <div class="[display:flex] [align-items:center] [gap:0.5rem] [flex-wrap:wrap]">
          <span style="background:<?= $a_color ?>18;color:<?= $a_color ?>;border:1px solid <?= $a_color ?>40;padding:0.2rem 0.7rem;border-radius:20px;font-size:0.68rem;font-weight:700;">
            <?= htmlspecialchars($a_label) ?>
          </span>
          <?php if ($asr_result['has_award']): ?>
          <span style="background:<?= $av_color ?>12;color:<?= $av_color ?>;border:1px solid <?= $av_color ?>40;padding:0.2rem 0.7rem;border-radius:20px;font-size:0.67rem;font-weight:600;">
            <i class="bi bi-shield me-1"></i>Evaluator: <?= $asr_status_label($a_verified) ?>
          </span>
          <?php endif; ?>
        </div>
      </div>
      <div class="[padding:0.85rem_1rem]">
        <?php if ($asr_result['has_award']): ?>
        <div class="[display:flex] [gap:1rem] [flex-wrap:wrap] [align-items:flex-start]">
          <div class="[flex:1] [min-width:180px]">
            <div class="[font-size:0.72rem] [color:#94a3b8] [font-weight:600] [text-transform:uppercase] [letter-spacing:0.05em] [margin-bottom:0.3rem]">Award</div>
            <div class="[font-size:0.85rem] [color:#1e293b] [font-weight:500]"><?= htmlspecialchars($asr_result['award_details'] ?: 'National/International Award (KRA IV)') ?></div>
          </div>
          <div class="[flex:1] [min-width:200px]">
            <div class="[font-size:0.72rem] [color:#94a3b8] [font-weight:600] [text-transform:uppercase] [letter-spacing:0.05em] [margin-bottom:0.3rem]">Why This Decision</div>
            <div class="[font-size:0.82rem] [color:#475569] [line-height:1.5]"><?= htmlspecialchars($asr_result['award_reason']) ?></div>
          </div>
          <?php if (!empty($asr_row['award_evidence'])): ?>
          <div class="[width:100%]">
            <a href="../<?= htmlspecialchars($asr_row['award_evidence']) ?>" target="_blank"
               class="[font-size:0.78rem] [color:#1e4d8c] [text-decoration:none] [display:inline-flex] [align-items:center] [gap:0.3rem]">
              <i class="bi bi-file-earmark-pdf"></i>View uploaded evidence
            </a>
          </div>
          <?php endif; ?>
          <?php if (!empty($asr_row['award_verification_notes']) && $a_verified !== 'pending'): ?>
          <div style="background:<?= $av_color ?>0e;border:1px solid <?= $av_color ?>30;border-radius:6px;padding:0.5rem 0.75rem;font-size:0.75rem;color:<?= $av_color ?>;width:100%;">
            <i class="bi bi-chat-left-text me-1"></i><strong>Evaluator note:</strong> <?= htmlspecialchars($asr_row['award_verification_notes']) ?>
          </div>
          <?php endif; ?>
        </div>
        <?php else: ?>
        <div class="[display:flex] [align-items:center] [gap:0.75rem] [color:#64748b] [font-size:0.84rem]">
          <i class="bi bi-exclamation-circle [font-size:1.1rem] [color:#94a3b8] [flex-shrink:0]"></i>
          <div>No national/international award found in your entries.
            <a href="?page=apply&tab=profdev" class="[color:#1e4d8c] [font-weight:600] [margin-left:0.3rem]">
              <i class="bi bi-plus-circle me-1"></i>Add award in Prof. Development (Crit. C)
            </a>
          </div>
        </div>
        <?php endif; ?>
      </div>
    </div>

    <!-- Summary card -->
    <div style="border:1.5px solid <?= $rank_increase > 0 ? '#bbf7d0' : '#e2e8f0' ?>;border-radius:10px;padding:1rem;background:<?= $rank_increase > 0 ? '#f0fdf4' : '#f8fafc' ?>;">
      <div class="[font-size:0.73rem] [font-weight:700] [color:#475569] [text-transform:uppercase] [letter-spacing:0.06em] [margin-bottom:0.6rem]">
        <i class="bi bi-calculator me-1"></i>Summary
      </div>
      <div class="[display:grid] [grid-template-columns:repeat(auto-fit,minmax(130px,1fr))] [gap:0.6rem]">
        <div class="[background:#fff] [border:1px_solid_#e2e8f0] [border-radius:7px] [padding:0.6rem_0.75rem]">
          <div class="[font-size:0.68rem] [color:#94a3b8] [font-weight:600] [text-transform:uppercase] [margin-bottom:0.2rem]">Current Score</div>
          <div class="[font-size:1rem] [font-weight:700] [color:#1e293b]"><?= number_format((float)($asr_result['weighted_score_at_calc'] ?? 0), 2) ?></div>
        </div>
        <div class="[background:#fff] [border:1px_solid_#e2e8f0] [border-radius:7px] [padding:0.6rem_0.75rem]">
          <div class="[font-size:0.68rem] [color:#94a3b8] [font-weight:600] [text-transform:uppercase] [margin-bottom:0.2rem]">Doctorate Mode</div>
          <div style="font-size:0.8rem;font-weight:700;color:<?= $d_color ?>;"><?= htmlspecialchars($d_label) ?></div>
        </div>
        <div class="[background:#fff] [border:1px_solid_#e2e8f0] [border-radius:7px] [padding:0.6rem_0.75rem]">
          <div class="[font-size:0.68rem] [color:#94a3b8] [font-weight:600] [text-transform:uppercase] [margin-bottom:0.2rem]">Award Mode</div>
          <div style="font-size:0.8rem;font-weight:700;color:<?= $a_color ?>;"><?= htmlspecialchars($a_label) ?></div>
        </div>
        <div style="background:<?= $rank_increase > 0 ? '#f0fdf4' : '#fff' ?>;border:1px solid <?= $rank_increase > 0 ? '#bbf7d0' : '#e2e8f0' ?>;border-radius:7px;padding:0.6rem 0.75rem;">
          <div class="[font-size:0.68rem] [color:#94a3b8] [font-weight:600] [text-transform:uppercase] [margin-bottom:0.2rem]">Auto Sub-Rank</div>
          <div style="font-size:1rem;font-weight:700;color:<?= $rank_increase > 0 ? '#16a34a' : '#64748b' ?>;">
            <?= $rank_increase > 0 ? "+{$rank_increase}" : '0' ?> rank<?= $rank_increase !== 1 ? 's' : '' ?>
          </div>
        </div>
      </div>
      <div class="[margin-top:0.6rem] [font-size:0.71rem] [color:#94a3b8] [display:flex] [align-items:center] [gap:0.35rem] [flex-wrap:wrap]">
        <i class="bi bi-arrow-repeat"></i>Recalculated automatically  -  updates when you add/edit KRA IV entries.
        <?php if ($d_verified === 'rejected' || $a_verified === 'rejected'): ?>
        <span class="[color:#dc2626] [font-weight:600]"><i class="bi bi-exclamation-triangle me-1"></i>Item marked not acceptable by evaluator - see notes above.</span>
        <?php endif; ?>
      </div>
    </div>

    <?php elseif ($cur_cat === 'Position Requirements'): ?>
    <!-- POSITION REQUIREMENTS TAB CONTENT -->
    <?php
    $pos_req = $pdo->prepare("SELECT * FROM position_requirements WHERE application_id = ?");
    $pos_req->execute([$app_id]);
    $pos_data = $pos_req->fetch();
    
    $faculty_rank = $faculty['rank'] ?? '';
    $is_instructor = strpos($faculty_rank, 'Instructor') !== false;
    $is_asst_assoc = (strpos($faculty_rank, 'Assistant Professor') !== false || strpos($faculty_rank, 'Associate Professor') !== false);
    $is_professor = strpos($faculty_rank, 'Professor') !== false && !$is_asst_assoc;
    $is_univ_prof = strpos($faculty_rank, 'University Professor') !== false;
    ?>
    
    <div class="[display:flex] [align-items:center] [gap:0.65rem] [margin-bottom:1.5rem]">
      <div class="[width:38px] [height:38px] [border-radius:10px] [background:#1e4d8c] [display:flex] [align-items:center] [justify-content:center] [flex-shrink:0] [box-shadow:0_3px_10px_rgba(0,0,0,0.15)]">
        <i class="bi bi-file-earmark-check [color:#fff] [font-size:1.1rem]"></i>
      </div>
      <div>
        <h6 class="[font-weight:700] [color:#1a3a6b] [margin:0] [font-size:0.97rem] [line-height:1.2]">Position Requirements</h6>
        <div class="[font-size:0.75rem] [color:#64748b] [margin-top:2px]">
          <i class="bi bi-info-circle me-1"></i>Document checklist for <?= sanitize($faculty_rank) ?>
        </div>
      </div>
    </div>

    <?php if ($is_instructor || $is_asst_assoc): ?>
    <!-- No requirements for Instructor and Assistant/Associate Professor -->
    <div class="[background:#f0fdf4] [border:1px_solid_#bbf7d0] [border-radius:8px] [padding:2rem] [text-align:center]">
      <i class="bi bi-check-circle-fill [color:#16a34a] [font-size:2rem] [margin-bottom:1rem] [display:block]"></i>
      <h6 class="[color:#16a34a] [font-weight:700] [margin-bottom:0.5rem]">No Additional Documents Required</h6>
      <p class="[color:#15803d] [margin:0] [font-size:0.9rem]">Your current position (<?= sanitize($faculty_rank) ?>) does not require additional documentation.</p>
    </div>

    <?php elseif ($is_professor && !$is_univ_prof): ?>
    <!-- Professor requirements -->
    <form method="POST" enctype="multipart/form-data">
      <input type="hidden" name="kra_action" value="save_position_requirements">
      
      <div class="[border:1px_solid_#e2e8f0] [border-radius:10px] [overflow:hidden] [margin-bottom:1rem] [box-shadow:0_1px_4px_rgba(0,0,0,0.04)]">
        <div class="[background:#1e4d8c] [padding:0.5rem_0.9rem]">
          <span class="[color:#fff] [font-size:0.72rem] [font-weight:700] [text-transform:uppercase] [letter-spacing:0.07em]">Professor Position Requirements</span>
        </div>
        
        <table class="table table-modern mb-0 [background:#fff] [font-size:0.84rem]">
          <thead>
            <tr class="[background:#f8fafc] [border-bottom:1px_solid_#e2e8f0]">
              <th class="[width:40px] [padding:0.7rem_0.75rem] [color:#64748b] [font-size:0.68rem] [font-weight:700] [text-transform:uppercase]">#</th>
              <th class="[padding:0.7rem_0.75rem] [color:#64748b] [font-size:0.68rem] [font-weight:700] [text-transform:uppercase]">Requirement</th>
              <th class="[padding:0.7rem_0.75rem] [color:#64748b] [font-size:0.68rem] [font-weight:700] [text-transform:uppercase]">Upload</th>
              <th class="[padding:0.7rem_0.75rem] [color:#64748b] [font-size:0.68rem] [font-weight:700] [text-transform:uppercase]">Status</th>
            </tr>
          </thead>
          <tbody>
            <tr class="[border-bottom:1px_solid_#f1f5f9]">
              <td class="[padding:0.75rem] [text-align:center] [font-weight:700] [color:#64748b]">1</td>
              <td class="[padding:0.75rem] [font-weight:600] [color:#1e293b]">CAV Transcript of Records</td>
              <td class="[padding:0.75rem]">
                <input type="file" name="cav_file" accept=".pdf,.jpg,.jpeg,.png" class="form-control form-control-sm [font-size:0.78rem]">
                <?php if (!empty($pos_data['cav_file'])): ?>
                <div class="[margin-top:0.3rem]">
                  <a href="<?= $pos_data['cav_file'] ?>" target="_blank" class="[font-size:0.7rem] [color:#1e4d8c]"><i class="bi bi-file-earmark"></i> View Current</a>
                </div>
                <?php endif; ?>
              </td>
              <td class="[padding:0.75rem] [text-align:center]">
                <?php
                $cav_status = $pos_data['cav_transcript'] ?? 'Missing';
                $cav_color = $cav_status === 'Uploaded' ? '#16a34a' : '#dc2626';
                ?>
                <span style="background:<?= $cav_color ?>15;color:<?= $cav_color ?>;padding:0.2rem 0.6rem;border-radius:20px;font-size:0.7rem;font-weight:700;"><?= $cav_status ?></span>
              </td>
            </tr>
            
            <tr>
              <td class="[padding:0.75rem] [text-align:center] [font-weight:700] [color:#64748b]">2</td>
              <td class="[padding:0.75rem] [font-weight:600] [color:#1e293b]">
                Internationally Indexed Article
                <div class="[font-size:0.75rem] [color:#64748b] [font-weight:normal] [margin-top:0.2rem]">Scopus, WoS, or ACI, published within the last 3 years</div>
              </td>
              <td class="[padding:0.75rem]">
                <input type="file" name="article_file" accept=".pdf,.jpg,.jpeg,.png" class="form-control form-control-sm [font-size:0.78rem]">
                <?php if (!empty($pos_data['article_file'])): ?>
                <div class="[margin-top:0.3rem]">
                  <a href="<?= $pos_data['article_file'] ?>" target="_blank" class="[font-size:0.7rem] [color:#1e4d8c]"><i class="bi bi-file-earmark"></i> View Current</a>
                </div>
                <?php endif; ?>
              </td>
              <td class="[padding:0.75rem] [text-align:center]">
                <?php
                $article_status = $pos_data['international_article'] ?? 'Missing';
                $article_color = $article_status === 'Uploaded' ? '#16a34a' : '#dc2626';
                ?>
                <span style="background:<?= $article_color ?>15;color:<?= $article_color ?>;padding:0.2rem 0.6rem;border-radius:20px;font-size:0.7rem;font-weight:700;"><?= $article_status ?></span>
              </td>
            </tr>
          </tbody>
        </table>
      </div>

      <!-- Result Line -->
      <div class="[background:#f8fafc] [border:1px_solid_#e2e8f0] [border-radius:8px] [padding:1rem] [margin-bottom:1rem]">
        <div class="[font-weight:700] [color:#1a3a6b] [margin-bottom:0.5rem]">Result:</div>
        <?php
        $all_uploaded = (($pos_data['cav_transcript'] ?? 'Missing') === 'Uploaded' && ($pos_data['international_article'] ?? 'Missing') === 'Uploaded');
        if ($all_uploaded) {
            echo '<div class="[color:#16a34a] [font-weight:600] [font-size:0.95rem]"><i class="bi bi-check-circle-fill me-1"></i>Requirements Complete</div>';
        } else {
            $missing = [];
            if (($pos_data['cav_transcript'] ?? 'Missing') === 'Missing') $missing[] = 'CAV Transcript';
            if (($pos_data['international_article'] ?? 'Missing') === 'Missing') $missing[] = 'International Article';
            echo '<div class="[color:#dc2626] [font-weight:600] [font-size:0.95rem]"><i class="bi bi-x-circle me-1"></i>Requirements Incomplete ... missing: ' . implode(', ', $missing) . '</div>';
        }
        ?>
      </div>

      <?php if (!$locked): ?>
      <button type="submit" class="btn btn-primary [border-radius:8px] [padding:0.55rem_1.25rem] [font-weight:600]">
        <i class="bi bi-upload me-2"></i>Save Requirements
      </button>
      <?php endif; ?>
    </form>

    <?php elseif ($is_univ_prof): ?>
    <!-- University Professor requirements -->
    <form method="POST" enctype="multipart/form-data">
      <input type="hidden" name="kra_action" value="save_position_requirements">
      
      <div class="[border:1px_solid_#e2e8f0] [border-radius:10px] [overflow:hidden] [margin-bottom:1rem] [box-shadow:0_1px_4px_rgba(0,0,0,0.04)]">
        <div class="[background:#1e4d8c] [padding:0.5rem_0.9rem]">
          <span class="[color:#fff] [font-size:0.72rem] [font-weight:700] [text-transform:uppercase] [letter-spacing:0.07em]">University Professor Requirements</span>
        </div>
        
        <table class="table table-modern mb-0 [background:#fff] [font-size:0.84rem]">
          <thead>
            <tr class="[background:#f8fafc] [border-bottom:1px_solid_#e2e8f0]">
              <th class="[width:40px] [padding:0.7rem_0.75rem] [color:#64748b] [font-size:0.68rem] [font-weight:700] [text-transform:uppercase]">#</th>
              <th class="[padding:0.7rem_0.75rem] [color:#64748b] [font-size:0.68rem] [font-weight:700] [text-transform:uppercase]">Requirement</th>
              <th class="[padding:0.7rem_0.75rem] [color:#64748b] [font-size:0.68rem] [font-weight:700] [text-transform:uppercase]">Upload</th>
              <th class="[padding:0.7rem_0.75rem] [color:#64748b] [font-size:0.68rem] [font-weight:700] [text-transform:uppercase]">Status</th>
            </tr>
          </thead>
          <tbody>
            <tr>
              <td class="[padding:0.75rem] [text-align:center] [font-weight:700] [color:#64748b]">1</td>
              <td class="[padding:0.75rem] [font-weight:600] [color:#1e293b]">College/University Professor Certification Form</td>
              <td class="[padding:0.75rem]">
                <input type="file" name="cert_file" accept=".pdf,.jpg,.jpeg,.png" class="form-control form-control-sm [font-size:0.78rem]">
                <?php if (!empty($pos_data['cert_file'])): ?>
                <div class="[margin-top:0.3rem]">
                  <a href="<?= $pos_data['cert_file'] ?>" target="_blank" class="[font-size:0.7rem] [color:#1e4d8c]"><i class="bi bi-file-earmark"></i> View Current</a>
                </div>
                <?php endif; ?>
              </td>
              <td class="[padding:0.75rem] [text-align:center]">
                <?php
                $cert_status = $pos_data['certification_form'] ?? 'Missing';
                $cert_color = $cert_status === 'Uploaded' ? '#16a34a' : '#dc2626';
                ?>
                <span style="background:<?= $cert_color ?>15;color:<?= $cert_color ?>;padding:0.2rem 0.6rem;border-radius:20px;font-size:0.7rem;font-weight:700;"><?= $cert_status ?></span>
              </td>
            </tr>
          </tbody>
        </table>
      </div>

      <!-- Result Line -->
      <div class="[background:#f8fafc] [border:1px_solid_#e2e8f0] [border-radius:8px] [padding:1rem] [margin-bottom:1rem]">
        <div class="[font-weight:700] [color:#1a3a6b] [margin-bottom:0.5rem]">Result:</div>
        <?php
        $cert_complete = (($pos_data['certification_form'] ?? 'Missing') === 'Uploaded');
        if ($cert_complete) {
            echo '<div class="[color:#16a34a] [font-weight:600] [font-size:0.95rem]"><i class="bi bi-check-circle-fill me-1"></i>Requirements Complete</div>';
        } else {
            echo '<div class="[color:#dc2626] [font-weight:600] [font-size:0.95rem]"><i class="bi bi-x-circle me-1"></i>Requirements Incomplete ... missing: Certification Form</div>';
        }
        ?>
      </div>

      <?php if (!$locked): ?>
      <button type="submit" class="btn btn-primary [border-radius:8px] [padding:0.55rem_1.25rem] [font-weight:600]">
        <i class="bi bi-upload me-2"></i>Save Requirements
      </button>
      <?php endif; ?>
    </form>
    <?php endif; ?>

    <?php else: ?>
    <!-- REGULAR KRA TAB CONTENT (dark-themed criterion-grouped layout) -->
    <?php
    $crit_headers = [
        'Instruction'              => 'Teaching Effectiveness',
        'Research'                 => 'Research, Invention & Creative Work',
        'Extension'                => 'Extension Services',
        'Professional Development' => 'Professional Development',
    ];
    $crit_max = [
        'Instruction'              => 60,
        'Research'                 => 100,
        'Extension'                => 100,
        'Professional Development' => 100,
    ];

    // Map entries to criterion groups using criteria_list labels
    // Build a lookup: criterion_label => [entries]
    $entries_by_crit = [];
    foreach ($cur_entries as $s) {
        $rem = $s['remarks'] ?? '';
        $parts = explode('|||', $rem);
        $matched = false;
        foreach ($criteria_list as $c) {
            // Try to match the stored remarks against criterion label keywords
            $lbl = $c['criterion_label'];
            $critType = $parts[0] ?? '';
            // Match by first segment of remarks against criterion label
            if (stripos($lbl, $critType) !== false || stripos($critType, substr($lbl, 0, 10)) !== false) {
                $entries_by_crit[$lbl][] = $s;
                $matched = true;
                break;
            }
        }
        if (!$matched) {
            $entries_by_crit['Other'][] = $s;
        }
    }

    // Group criteria by Criterion letter (A, B, C, D...)
    $crit_groups = [];
    foreach ($criteria_list as $c) {
        $lbl = $c['criterion_label'];
        // Extract criterion letter from label e.g. "Criterion A..." or just first word
        if (preg_match('/Criterion\s+([A-Z])/i', $lbl, $m)) {
            $letter = strtoupper($m[1]);
        } else {
            $letter = '?';
        }
        if (!isset($crit_groups[$letter])) {
            $crit_groups[$letter] = ['label' => $lbl, 'max' => $c['max_points'], 'items' => []];
        }
        $crit_groups[$letter]['items'][] = $c;
    }

    // Compute criterion totals from submitted entries
    $crit_totals = [];
    foreach ($cur_entries as $s) {
        $rem = $s['remarks'] ?? '';
        $parts = explode('|||', $rem);
        $critType = $parts[0] ?? '';
        foreach ($criteria_list as $c) {
            $lbl = $c['criterion_label'];
            if (preg_match('/Criterion\s+([A-Z])/i', $lbl, $m)) {
                $letter = strtoupper($m[1]);
            } else {
                $letter = '?';
            }
            // Simple heuristic: map critType prefix to letter
            $map = ['A' => ['A-set-sef','A-set-sef-sem','A-set-sem','A-sef-sem','A-org'], 'B' => ['B|','B-material','B-training','B-paper','B-degree'], 'C' => ['C|','C-thesis','C-award'], 'D' => ['D-']];
            foreach ($map as $l => $prefixes) {
                foreach ($prefixes as $p) {
                    if (strpos($critType, $p) === 0 || $critType === $p) {
                        if (!isset($crit_totals[$l])) $crit_totals[$l] = 0;
                        $crit_totals[$l] += (float)$s['computed_points'];
                    }
                }
            }
        }
    }
    ?>

    <?php if (empty($criteria_list)): ?>
    <!-- No criteria configured -->
    <div class="[background:#ffffff] [border:1px_solid_#e2e8f0] [border-radius:8px] [padding:2rem] [text-align:center] [color:#94a3b8] [font-size:0.85rem]">
      <i class="bi bi-inbox [font-size:1.5rem] [display:block] [margin-bottom:0.5rem] [color:#cbd5e1]"></i>
      No criteria configured for this KRA. Contact your administrator.
    </div>
    <?php else: ?>

    <?php
    // Group criteria into dark blocks. Built-in Criterion A/B/C/D rows share a
    // letter group; custom/unmapped rows use the stable DB criterion_key so new
    // criteria don't collapse into the same "?" bucket.
    $dark_groups = [];  // key = display group id, value = ['letter'=>string, 'header'=>string, 'max'=>float, 'items'=>[], '_seen'=>[]]
    $criterion_title_map = [
        'Instruction' => [
            'A' => 'Teaching Effectiveness',
            'B' => 'Curriculum and Instructional Materials Developed',
            'C' => 'Special Projects, Capstone Projects, Thesis, Dissertation and Mentorship Services',
        ],
        'Research' => [
            'A' => 'Research Outputs Published',
            'B' => 'Inventions',
            'C' => 'Creative Works',
        ],
        'Extension' => [
            'A' => 'Service to the Institution',
            'B' => 'Service to the Community',
            'C' => 'Quality of Extension Services',
            'D' => 'Bonus Criterion',
        ],
        'Professional Development' => [
            'A' => 'Involvement in Professional Organization',
            'B' => 'Continuing Development',
            'C' => 'Awards and Recognition',
            'D' => 'Bonus Indicators for Newly Hired Faculty',
        ],
    ];
    foreach ($criteria_list as $c) {
        $lbl = $c['criterion_label'];
        $criterion_key = trim((string)($c['criterion_key'] ?? ''));
        // Extract letter: "Criterion A. Teaching..." or "Criterion B ..."
        if (preg_match('/Criterion\s+([A-Z])[\.:\s]/i', $lbl, $m)) {
            $letter = strtoupper($m[1]);
            $group_key = $letter;
            // Build a clean header: everything up to the first ( or end
            preg_match('/^(Criterion\s+[A-Z][^(]*)/i', $lbl, $hm);
            $header = trim($hm[1] ?? $lbl);
        } else {
            $letter = '?';
            $group_key = 'custom:' . ($criterion_key !== '' ? $criterion_key : md5((string)$lbl));
            $header = $lbl;
        }
        if (isset($criterion_title_map[$cur_cat][$letter])) {
            $header = 'Criterion ' . $letter . '. ' . $criterion_title_map[$cur_cat][$letter];
        }
        if (!isset($dark_groups[$group_key])) {
            $dark_groups[$group_key] = ['letter' => $letter, 'header' => $header, 'max' => 0.0, 'items' => [], '_seen' => []];
        }
        $item_key = $criterion_key !== '' ? $criterion_key : trim((string)$lbl);
        if (isset($dark_groups[$group_key]['_seen'][$item_key])) {
            continue;
        }
        $dark_groups[$group_key]['_seen'][$item_key] = true;
        $dark_groups[$group_key]['items'][] = $c;
        $dark_groups[$group_key]['max'] += (float)$c['max_points'];
    }

    // Build a flat list of all entries with their evidence
    $entries_with_ev = [];
    foreach ($cur_entries as $s) {
        $ef_stmt2 = $pdo->prepare("SELECT evidence_id, file_path, original_filename FROM kra_evidence_files WHERE submission_id=? ORDER BY uploaded_at ASC");
        $ef_stmt2->execute([$s['submission_id']]);
        $ev_files2 = $ef_stmt2->fetchAll();
        if (empty($ev_files2) && $s['document_path']) {
            $ev_files2 = [['evidence_id'=>0,'file_path'=>$s['document_path'],'original_filename'=>basename($s['document_path'])]];
        }
        $s['_ev_files'] = $ev_files2;
        $entries_with_ev[] = $s;
    }

    // Prefix map used for matching entries to criterion letters (KRA I and KRA IV only).
    // KRA II and KRA III use free-form key names that don't follow A-/B-/C- conventions,
    // so for those KRAs we fall back to showing all entries under the first group.
    $letter_to_prefixes = [
        'A' => ['A-set-sef', 'A-set-sef-sem', 'A-set-sem', 'A-sef-sem', 'A-org'],
        'B' => ['B|', 'B-material', 'B-training', 'B-paper', 'B-degree'],
        'C' => ['C|', 'C-thesis', 'C-mentor', 'C-award'],
        'D' => ['D-bonus', 'D-prior-academic', 'D-prior-industry', 'D-industry', 'D-'],
    ];

    // Check if ANY entry matches any known prefix at all (true for KRA I & IV)
    $any_prefix_match = false;
    foreach ($entries_with_ev as $s) {
        $ct = explode('|||', $s['remarks'] ?? '')[0] ?? '';
        foreach ($letter_to_prefixes as $prefixes) {
            foreach ($prefixes as $prefix) {
                if ($prefix === $ct || strpos($ct, rtrim($prefix, '-')) === 0) {
                    $any_prefix_match = true;
                    break 3;
                }
            }
        }
    }

    $first_group_key = array_key_first($dark_groups);

    // -- Key -> pre-select value maps (declared once, used in every sub-item row) --
    // KRA I - Instruction flat crit value
    $key_to_flat = [
        'kra1_b_textbook_sole'    => "B|30|Textbook, Sole Author",
        'kra1_b_textbook_co'      => "B|30co|Textbook, Co-Author",
        'kra1_b_chapter_sole'     => "B|0|Textbook Chapter, Sole Author",
        'kra1_b_chapter_co'       => "B|0|Textbook Chapter, Co-Author",
        'kra1_b_manual_sole'      => "B|16|Manual / Module, Sole Author",
        'kra1_b_manual_co'        => "B|16co|Manual / Module, Co-Author",
        'kra1_b_multimedia'       => 'B|16|Multimedia Teaching Materials',
        'kra1_b_testing'          => 'B|0|Validated Testing Materials',
        'kra1_b_program_lead'     => "B|10|Academic Program Dev/Revision, Lead",
        'kra1_b_program_contrib'  => "B|5|Academic Program Dev/Revision, Contributor",
        'kra1_c_adviser_special'  => "C|3|Adviser: Special Project / Capstone",
        'kra1_c_adviser_undergrad'=> "C|5|Adviser: Undergraduate Thesis",
        'kra1_c_adviser_masters'  => "C|8|Adviser: Master's Thesis",
        'kra1_c_adviser_doctoral' => "C|10|Adviser: Doctoral Dissertation",
        'kra1_c_panel_special'    => "C|1|Panel Member: Special Project / Capstone",
        'kra1_c_panel_undergrad'  => "C|2|Panel Member: Undergraduate Thesis",
        'kra1_c_panel_masters'    => "C|4|Panel Member: Master's Thesis",
        'kra1_c_panel_doctoral'   => "C|6|Panel Member: Doctoral Dissertation",
        'kra1_c_mentor_competition'=> "C|0|Mentor: Student/Team Competition Winner",
    ];
    foreach ($criteria_list as $_crit_for_flat) {
        $_key = (string)($_crit_for_flat['criterion_key'] ?? '');
        if (!isset($key_to_flat[$_key])) {
            continue;
        }
        $_parts = explode('|', $key_to_flat[$_key], 3);
        if (count($_parts) < 3) {
            continue;
        }
        $_is_co = str_ends_with($_parts[1], 'co');
        $_points = rtrim(rtrim(number_format((float)$_crit_for_flat['max_points'], 2, '.', ''), '0'), '.');
        if ($_points === '') $_points = '0';
        $key_to_flat[$_key] = $_parts[0] . '|' . $_points . ($_is_co ? 'co' : '') . '|' . $_parts[2];
    }
    // KRA III - Extension subtype string
    $key_to_ext = [
        'kra3_a_moa'                   => 'moa-linkage',
        'kra3_a_income_below6m'        => 'income',
        'kra3_a_income_6to12m'         => 'income',
        'kra3_a_income_above12m'       => 'income',
        'kra3_b_accreditation_local'   => 'accredit-local',
        'kra3_b_accreditation_intl'    => 'accredit-intl',
        'kra3_b_judge_research'        => 'judge-research',
        'kra3_b_judge_competition'     => 'judge-other',
        'kra3_b_consultant_local'      => 'consultant-local',
        'kra3_b_consultant_intl'       => 'consultant-intl',
        'kra3_b_column_occasional'     => 'media-column-occasional',
        'kra3_b_column_regular'        => 'media-column-regular',
        'kra3_b_tv_radio_host'         => 'media-tv-radio-host',
        'kra3_b_technical_guest'       => 'media-guest',
        'kra3_b_resource_local'        => 'resource-speaker-local',
        'kra3_b_resource_intl'         => 'resource-speaker-intl',
        'kra3_b_outreach_head'         => 'outreach-isr-lead',
        'kra3_b_outreach_participant'  => 'outreach-isr-member',
        'kra3_c_satisfaction'          => 'csr-satisfaction',
        'kra3_d_president'             => 'president',
        'kra3_d_vp'                    => 'vice-president',
        'kra3_d_chancellor'            => 'chancellor',
        'kra3_d_vice_chancellor'       => 'vice-chancellor',
        'kra3_d_campus_director'       => 'campus director',
        'kra3_d_faculty_regent'        => 'campus director',
        'kra3_d_office_director'       => 'office director',
        'kra3_d_univ_secretary'        => 'office director',
        'kra3_d_dean'                  => 'dean',
        'kra3_d_assoc_dean'            => 'associate dean',
        'kra3_d_college_secretary'     => 'associate dean',
        'kra3_d_dept_head'             => 'dept head',
        'kra3_d_program_chair'         => 'program chair',
        'kra3_d_project_head_inst'     => 'program chair',
        'kra3_d_committee_chair_dept'  => 'committee chair',
        'kra3_d_committee_chair_inst'  => 'committee chair',
        'kra3_d_committee_member_dept' => 'committee member',
        'kra3_d_committee_member_inst' => 'committee member',
    ];
    $key_to_ext_default_max = [
        'kra3_a_moa' => 5.00,
        'kra3_a_income_below6m' => 6.00,
        'kra3_a_income_6to12m' => 12.00,
        'kra3_a_income_above12m' => 18.00,
        'kra3_b_accreditation_local' => 8.00,
        'kra3_b_accreditation_intl' => 10.00,
        'kra3_b_judge_research' => 2.00,
        'kra3_b_judge_competition' => 1.00,
        'kra3_b_consultant_local' => 8.00,
        'kra3_b_consultant_intl' => 10.00,
        'kra3_b_column_occasional' => 2.00,
        'kra3_b_column_regular' => 10.00,
        'kra3_b_tv_radio_host' => 10.00,
        'kra3_b_technical_guest' => 1.00,
        'kra3_b_resource_local' => 2.00,
        'kra3_b_resource_intl' => 3.00,
        'kra3_b_outreach_head' => 5.00,
        'kra3_b_outreach_participant' => 2.00,
        'kra3_c_satisfaction' => 20.00,
        'kra3_d_president' => 20.00,
        'kra3_d_vp' => 15.00,
        'kra3_d_chancellor' => 10.00,
        'kra3_d_vice_chancellor' => 8.00,
        'kra3_d_campus_director' => 8.00,
        'kra3_d_faculty_regent' => 8.00,
        'kra3_d_office_director' => 6.00,
        'kra3_d_univ_secretary' => 6.00,
        'kra3_d_project_head_inst' => 4.00,
        'kra3_d_committee_chair_inst' => 3.00,
        'kra3_d_committee_member_inst' => 2.00,
        'kra3_d_dean' => 6.00,
        'kra3_d_assoc_dean' => 5.00,
        'kra3_d_college_secretary' => 3.00,
        'kra3_d_dept_head' => 4.00,
        'kra3_d_program_chair' => 3.00,
        'kra3_d_committee_chair_dept' => 2.00,
        'kra3_d_committee_member_dept' => 1.00,
    ];
    foreach ($criteria_list as $_crit_for_ext) {
        $_key = (string)($_crit_for_ext['criterion_key'] ?? '');
        if (isset($key_to_ext[$_key], $key_to_ext_default_max[$_key])
            && abs((float)$_crit_for_ext['max_points'] - (float)$key_to_ext_default_max[$_key]) > 0.001) {
            unset($key_to_ext[$_key]);
        }
    }
    $ext_subtype_to_letter = [];
    foreach ($key_to_ext as $_key => $_subtype) {
        $_num = kraNumberingEntryByKey((string)$_key);
        if (!empty($_num['criterion'])) {
            $ext_subtype_to_letter[$_subtype] = $_num['criterion'];
        }
    }

    $extensionEntryMatchesKey = static function (string $ci_key, array $parts, array $key_to_ext): bool {
        $subtype = $parts[0] ?? '';
        if (!isset($key_to_ext[$ci_key]) || $subtype !== $key_to_ext[$ci_key]) {
            return false;
        }
        if ($subtype !== 'income') {
            return true;
        }

        $income = max(0, (float)($parts[2] ?? 0));
        return match ($ci_key) {
            'kra3_a_income_above12m' => $income > 12000000,
            'kra3_a_income_6to12m'   => $income >= 6000001 && $income <= 12000000,
            'kra3_a_income_below6m'  => $income > 0 && $income <= 6000000,
            default                  => false,
        };
    };
    // KRA IV - Professional Development {type, sub}
    $key_to_pd = [
        'kra4_a_org_membership'    => ['type'=>'A-org',      'sub'=>''],
        'kra4_b_postmaster_cert'   => ['type'=>'B-degree',   'sub'=>'10'],
        'kra4_b_postdoc_cert'      => ['type'=>'B-degree',   'sub'=>'10'],
        'kra4_b_additional_masters'=> ['type'=>'B-degree',   'sub'=>'20'],
        'kra4_b_doctorate'         => ['type'=>'B-degree',   'sub'=>'40'],
        'kra4_b_training_local'    => ['type'=>'B-training', 'sub'=>'1'],
        'kra4_b_training_intl'     => ['type'=>'B-training', 'sub'=>'2'],
        'kra4_b_paper_local'       => ['type'=>'B-paper',    'sub'=>'3'],
        'kra4_b_paper_intl'        => ['type'=>'B-paper',    'sub'=>'5'],
        'kra4_c_award_institutional'=> ['type'=>'C-award',   'sub'=>'2'],
        'kra4_c_award_local'       => ['type'=>'C-award',    'sub'=>'3'],
        'kra4_c_award_national'    => ['type'=>'C-award',    'sub'=>'4'],
        'kra4_c_award_international'=> ['type'=>'C-award',   'sub'=>'5'],
        'kra4_c_award_prestigious' => ['type'=>'C-award',    'sub'=>'0'],
    ];
    $key_to_pd_default_max = [
        'kra4_a_org_membership' => 5.00,
        'kra4_b_postmaster_cert' => 10.00,
        'kra4_b_postdoc_cert' => 10.00,
        'kra4_b_additional_masters' => 20.00,
        'kra4_b_doctorate' => 40.00,
        'kra4_b_training_local' => 1.00,
        'kra4_b_training_intl' => 2.00,
        'kra4_b_paper_local' => 3.00,
        'kra4_b_paper_intl' => 5.00,
        'kra4_c_award_institutional' => 2.00,
        'kra4_c_award_local' => 3.00,
        'kra4_c_award_national' => 4.00,
        'kra4_c_award_international' => 0.00,
        'kra4_c_award_prestigious' => 0.00,
    ];
    foreach ($criteria_list as $_crit_for_pd) {
        $_key = (string)($_crit_for_pd['criterion_key'] ?? '');
        if (isset($key_to_pd[$_key], $key_to_pd_default_max[$_key])
            && abs((float)$_crit_for_pd['max_points'] - (float)$key_to_pd_default_max[$_key]) > 0.001) {
            unset($key_to_pd[$_key]);
        }
    }
    ?>

    <?php
    // Build step index for the stepper nav
    $group_keys = array_keys($dark_groups);
    $group_count = count($group_keys);
    ?>

    <?php if ($group_count > 1): ?>
    <!-- Criterion stepper nav -->
    <div id="criterionStepperNav"
         class="[display:flex] [align-items:center] [gap:0.4rem] [flex-wrap:wrap] [margin-bottom:1rem] [padding:0.65rem_0.85rem] [background:#f8fafc] [border:1px_solid_#e2e8f0] [border-radius:8px]">
        <span class="[font-size:0.68rem] [font-weight:700] [color:#64748b] [text-transform:uppercase] [letter-spacing:0.06em] [margin-right:0.25rem]">Criterion:</span>
        <?php foreach ($dark_groups as $gk => $g): $gi = array_search($gk, $group_keys); ?>
        <button type="button"
                onclick="goCriterion(<?= $gi ?>)"
                id="criterionPill_<?= $gi ?>"
                style="padding:0.3rem 0.85rem;border-radius:20px;font-size:0.75rem;font-weight:700;cursor:pointer;transition:all 0.15s;
                       <?= $gi === 0 ? 'background:#1e3a5f;color:#fff;border:1px solid #1e3a5f;' : 'background:#fff;color:#64748b;border:1px solid #e2e8f0;' ?>">
            <?= htmlspecialchars($g['letter']) ?>
        </button>
        <?php if ($gi < $group_count - 1): ?>
        <span class="[color:#cbd5e1] [font-size:0.7rem]">›</span>
        <?php endif; ?>
        <?php endforeach; ?>
        <span id="criterionStepLabel"
              class="[margin-left:auto] [font-size:0.75rem] [font-weight:600] [color:#1e3a5f]">
            <?= htmlspecialchars($dark_groups[$group_keys[0]]['header']) ?>
        </span>
    </div>
    <?php endif; ?>

    <?php foreach ($dark_groups as $group_key => $group):
        $gi = array_search($group_key, $group_keys);
    ?>
    <?php
    $letter = (string)($group['letter'] ?? $group_key);
    $group_item_keys = [];
    foreach (($group['items'] ?? []) as $_group_item) {
        $_group_item_key = trim((string)($_group_item['criterion_key'] ?? ''));
        if ($_group_item_key !== '') {
            $group_item_keys[$_group_item_key] = true;
        }
    }

    $customEntryBelongsToGroup = static function (array $parts, string $letter, array $group_item_keys): bool {
        $custom_letter = (string)($parts[1] ?? '');
        $custom_key = trim((string)($parts[2] ?? ''));
        if ($custom_key !== '' && isset($group_item_keys[$custom_key])) {
            return true;
        }
        return $custom_letter !== '' && $custom_letter === $letter;
    };

    // Collect entries for this criterion group
    if ($cur_cat === 'Extension') {
        $group_entries = [];
        foreach ($entries_with_ev as $s) {
            $parts = explode('|||', $s['remarks'] ?? '');
            $critType = $parts[0] ?? '';
            if ($critType === 'CUSTOM') {
                if ($customEntryBelongsToGroup($parts, $letter, $group_item_keys)) {
                    $group_entries[] = $s;
                }
            } elseif (($ext_subtype_to_letter[$critType] ?? null) === $letter) {
                $group_entries[] = $s;
            }
        }
    } elseif ($any_prefix_match) {
        // KRA I / KRA IV: match by critType prefix
        $group_entries = [];
        foreach ($entries_with_ev as $s) {
            $parts = explode('|||', $s['remarks'] ?? '');
            $critType = $parts[0] ?? '';
            if ($critType === 'CUSTOM') {
                if ($customEntryBelongsToGroup($parts, $letter, $group_item_keys)) {
                    $group_entries[] = $s;
                }
                continue;
            }
            foreach (($letter_to_prefixes[$letter] ?? []) as $prefix) {
                if ($prefix === $critType || strpos($critType, rtrim($prefix, '-')) === 0) {
                    $group_entries[] = $s;
                    break;
                }
            }
        }
    } else {
        // KRA II / custom groups: custom entries carry their group letter; unknown
        // legacy entries still fall back to the first group.
        $group_entries = [];
        foreach ($entries_with_ev as $s) {
            $parts = explode('|||', $s['remarks'] ?? '');
            if (($parts[0] ?? '') === 'CUSTOM') {
                if ($customEntryBelongsToGroup($parts, $letter, $group_item_keys)) {
                    $group_entries[] = $s;
                }
            } elseif ($group_key === $first_group_key) {
                $group_entries[] = $s;
            }
        }
    }

    // Compute group score from the already-collected entries
    $group_score = 0;
    foreach ($group_entries as $s) {
        $group_score += kraApplyEffectiveComputedPoints($s);
    }
    if ($cur_cat === 'Instruction' && $letter === 'A') {
        if (!class_exists('\Scoring\KRA1Scorer')) {
            require_once __DIR__ . '/../../../backend/services/scoring/kra1_scorer.php';
        }
        \Scoring\KRA1Scorer::setPdo($pdo);
        \Scoring\KRA1Scorer::setCriteriaContext((int)($cycle_id_for_criteria ?? 0), $target_position ?? null);
        $kra1_group_score = \Scoring\KRA1Scorer::score($group_entries);
        $group_score = (float)($kra1_group_score['criterion_a'] ?? $group_score);
    }
    $group_max = $group['max'];
    ?>
    <?php
    // For KRA I Criterion A, pre-compute per-sub-item scores (SET score vs SEF score)
    $ci_scores = [];
    if ($cur_cat === 'Instruction' && $letter === 'A') {
        $set_ratings = [];
        $sef_ratings = [];
        foreach ($group_entries as $s) {
            $parts    = explode('|||', $s['remarks'] ?? '');
            $critType = $parts[0] ?? '';
            switch ($critType) {
                case 'A-set-sef':
                    $sv = (float)($parts[1] ?? 0); $fv = (float)($parts[2] ?? 0);
                    if ($sv > 0) $set_ratings[] = $sv;
                    if ($fv > 0) $sef_ratings[] = $fv;
                    break;
                case 'A-set-sef-sem':
                    $sv = (float)($parts[3] ?? 0); $fv = (float)($parts[4] ?? 0);
                    if ($sv > 0) $set_ratings[] = $sv;
                    if ($fv > 0) $sef_ratings[] = $fv;
                    break;
                case 'A-set-sem':
                    $sv = (float)($parts[3] ?? 0);
                    if ($sv > 0) $set_ratings[] = $sv;
                    break;
                case 'A-sef-sem':
                    $fv = (float)($parts[3] ?? 0);
                    if ($fv > 0) $sef_ratings[] = $fv;
                    break;
            }
        }
        $avg_set = $set_ratings ? array_sum($set_ratings) / count($set_ratings) : 0;
        $avg_sef = $sef_ratings ? array_sum($sef_ratings) / count($sef_ratings) : 0;
        $ci_scores['set'] = round(($avg_set / 100) * $kra1_a_caps['set'], 2);
        $ci_scores['sef'] = round(($avg_sef / 100) * $kra1_a_caps['sef'], 2);
    }
    ?>
    <div class="<?= $group_count > 1 ? 'criterion-step' : '' ?> [background:#ffffff] [border:1px_solid_#dde3ee] [border-radius:8px] [margin-bottom:1rem] [overflow:hidden]"
         <?= $group_count > 1 ? 'data-step="' . $gi . '" style="display:' . ($gi === 0 ? 'block' : 'none') . ';"' : '' ?>>

      <!-- Criterion group header -->
      <div class="[background:#1e3a5f] [padding:0.55rem_1rem] [display:flex] [justify-content:space-between] [align-items:center]">
        <span class="[color:#fff] [font-weight:700] [font-size:0.85rem] [letter-spacing:0.01em]"><?= htmlspecialchars($group['header']) ?> <span class="[font-weight:400] [color:rgba(255,255,255,0.55)] [font-size:0.75rem]">(maximum of <?= number_format($group_max, 0) ?> points)</span></span>
      </div>

      <?php
      // -- Sub-item rows ------------------------------------------------------
      // For Instruction Criterion A: sub-items map 1-to-1 to SET/SEF with their
      // own scores. For all other criteria: sub-items are listed with the group
      // aggregate score shown once on the last item (or the only item).
      $ci_idx = 0;
      $last_numbering_group = null;
      $last_numbering_slot = null;
      foreach ($group['items'] as $ci):
          $ci_idx++;
          $ci_key        = $ci['criterion_key'] ?? '';
          $ci_numbering  = kraNumberingEntryByKey((string)$ci_key) ?: [];
          $ci_group_no   = $ci_numbering['group_no'] ?? null;
          $ci_item_no    = $ci_numbering['item_no'] ?? null;
          $ci_item_label = kraCleanEntryDisplayTitle((string)$ci['criterion_label']);
          $ci_number     = $ci_numbering ? '' : $ci_idx . '.';
          $ci_entry_type = '';   // 'set', 'sef', or '' (non-A criteria)
          if ($cur_cat === 'Instruction' && $letter === 'A') {
              if (str_contains($ci_key, '_a_set') || str_contains(strtolower($ci['criterion_label']), '(set)')) {
                  $ci_entry_type = 'set';
              } elseif (str_contains($ci_key, '_a_sef') || str_contains(strtolower($ci['criterion_label']), '(sef)')) {
                  $ci_entry_type = 'sef';
              }
          }

          // Build per-button values from the pre-declared maps. These are also
          // used to attach saved entries back to the correct displayed row.
          $flat_crit_value = $key_to_flat[$ci_key] ?? '';
          $ext_subtype     = $key_to_ext[$ci_key]  ?? '';
          $pd_entry        = $key_to_pd[$ci_key]   ?? null;

          // Score to display on this row  -  also collect matched entries for edit buttons
          $ci_entries = []; // entries that belong to this specific sub-item
          if ($ci_entry_type !== '') {
              // Instruction Criterion A  -  SET/SEF have their own subtotals
              $row_score = $ci_scores[$ci_entry_type] ?? 0.0;
          } else {
              $row_score = 0.0;
              foreach ($group_entries as $_se) {
                  $_parts = explode('|||', $_se['remarks'] ?? '');
                  $_p0    = $_parts[0] ?? '';
                  $_match = false;

                  if ($_p0 === 'CUSTOM') {
                      $_match = (($_parts[2] ?? '') === (string)$ci_key);
                  } elseif ($cur_cat === 'Research') {
                      $_stored_label = preg_replace('/\s*\(\d+(?:\.\d+)?\s*pts?\)\s*$/i', '', $_p0);
                      $_match = trim($_stored_label) === trim($ci['criterion_label']);
                  } elseif ($cur_cat === 'Extension') {
                      $_match = $extensionEntryMatchesKey((string)$ci_key, $_parts, $key_to_ext);
                  } elseif ($cur_cat === 'Professional Development') {
                      if (isset($pd_entry)) {
                          $_sub_match = ($pd_entry['sub'] === '' || ($_parts[2] ?? '') === $pd_entry['sub']);
                          $_match = $_p0 === $pd_entry['type'] && $_sub_match;
                      }
                  } else {
                      $_match = ($ci_idx === count($group['items']));
                  }

                  if ($_match) {
                      $ci_entries[] = $_se;
                      $row_score += kraApplyEffectiveComputedPoints($_se);
                  }
              }
              $row_score = ($row_score > 0 || !empty($group_entries)) ? $row_score : null;
          }

          // Description (filter out dev/admin notes)
          $desc_clean = $ci['description'] ?? '';
          $hide_desc  = str_starts_with($desc_clean, 'CONFIRMED SOURCE GAP')
                     || str_starts_with($desc_clean, 'CONFIG_')
                     || str_contains($desc_clean, 'PENDING_DOCUMENTATION')
                     || str_contains($desc_clean, 'JC01 s.2026, Section 15');
          $show_desc  = !empty($desc_clean) && !$hide_desc;

          // For Research pass the full criterion_label so JS can match it in CRIT
          $res_label = ($cur_cat === 'Research') ? ($ci['criterion_label'] ?? '') : '';

          // Button action
          if ($ci_entry_type !== '') {
              // Instruction A -> open focused section (SET or SEF only)
              $btn_onclick = "openCriterionEntryType('A', '{$ci_entry_type}')";
          } elseif ($cur_cat === 'Instruction' && $flat_crit_value !== '') {
              // Instruction B/C sub-item -> pre-select and lock
              $btn_onclick = htmlspecialchars("openCriterionEntryWithCrit(" . json_encode($letter) . ", " . json_encode(['kra'=>'instruction','flat'=>$flat_crit_value]) . ")", ENT_QUOTES);
          } elseif ($cur_cat === 'Research' && $res_label !== '') {
              // Research sub-item -> pre-select by label
              $btn_onclick = htmlspecialchars("openCriterionEntryWithCrit(" . json_encode($letter) . ", " . json_encode(['kra'=>'research','label'=>$res_label,'pts'=>(float)$ci['max_points']]) . ")", ENT_QUOTES);
          } elseif ($cur_cat === 'Extension' && $ext_subtype !== '') {
              // Extension sub-item -> pre-select by subtype
              $btn_onclick = htmlspecialchars("openCriterionEntryWithCrit(" . json_encode($letter) . ", " . json_encode(['kra'=>'extension','subtype'=>$ext_subtype]) . ")", ENT_QUOTES);
          } elseif ($cur_cat === 'Professional Development' && $pd_entry !== null) {
              // PD sub-item -> pre-select type + sub-value
              $btn_onclick = htmlspecialchars("openCriterionEntryWithCrit(" . json_encode($letter) . ", " . json_encode(['kra'=>'pd','type'=>$pd_entry['type'],'sub'=>$pd_entry['sub']]) . ")", ENT_QUOTES);
          } else {
              $btn_onclick = htmlspecialchars("openCriterionEntryWithCrit(" . json_encode($letter) . ", " . json_encode(['kra'=>'custom','letter'=>$letter,'key'=>$ci_key,'label'=>$ci['criterion_label'] ?? '', 'pts'=>(float)$ci['max_points']]) . ")", ENT_QUOTES);
          }
      ?>
      <?php if (!empty($ci_numbering) && $ci_group_no !== $last_numbering_group): ?>
      <?php $last_numbering_group = $ci_group_no; ?>
      <div class="[background:#f1f5f9] [border-top:1px_solid_#dbe3ee] [padding:0.55rem_1rem] [color:#1e3a5f] [font-size:0.8rem] [font-weight:800]">
        <?= htmlspecialchars((int)$ci_numbering['group_no'] . '. ' . $ci_numbering['group_title']) ?>
      </div>
      <?php endif; ?>
      <?php if (!empty($ci_numbering) && $ci_item_no !== null):
          $slot_key = (int)$ci_group_no . '.' . (int)$ci_item_no;
          if ($slot_key !== $last_numbering_slot):
              $last_numbering_slot = $slot_key;
      ?>
      <div class="[background:#f8fafc] [border-top:1px_solid_#e2e8f0] [padding:0.48rem_1rem_0.48rem_2.75rem] [color:#334155] [font-size:0.76rem] [font-weight:800]">
        <?= htmlspecialchars(kraNumberingPrefix($ci_numbering) . ' ' . kraNumberingSubLabel($ci_numbering)) ?>
      </div>
      <?php endif; endif; ?>
      <div <?= $ci_key !== '' ? 'id="criterion-' . htmlspecialchars(rawurlencode((string)$ci_key), ENT_QUOTES) . '"' : '' ?>
           style="display:grid;grid-template-columns:28px 1fr auto auto;align-items:start;gap:0;padding:0;border-top:1px solid #e8edf4;<?= $ci_idx % 2 === 0 ? 'background:#fafbfd;' : 'background:#fff;' ?>">

        <!-- Number badge -->
        <div class="[padding:0.65rem_0_0.65rem_1rem] [color:#94a3b8] [font-size:0.72rem] [font-weight:700] [padding-top:0.78rem]"><?= htmlspecialchars($ci_number) ?></div>

        <!-- Label + description -->
        <div class="[padding:0.6rem_0.75rem_0.6rem_0.5rem] [min-width:0]">
          <div class="[color:#1a2e4a] [font-size:0.82rem] [font-weight:600] [line-height:1.3]"><?= htmlspecialchars($ci_item_label) ?></div>
          <?php if ($show_desc): ?>
          <div class="[color:#64748b] [font-size:0.72rem] [margin-top:0.2rem] [line-height:1.4]"><?= htmlspecialchars($desc_clean) ?></div>
          <?php endif; ?>
          <?php if (!empty($ci_entries)): ?>
          <!-- Existing entries list under the label -->
            <div class="[margin-top:0.45rem] [display:flex] [flex-direction:column] [gap:0.25rem]">
            <?php foreach ($ci_entries as $_ce):
                $_ce_parts = explode('|||', $_ce['remarks'] ?? '');
                $_ce_title = trim($_ce_parts[1] ?? '');
                $_ce_value = trim($_ce_parts[2] ?? '');
                $_ce_meta = '';
                if ($cur_cat === 'Extension' && ($_ce_parts[0] ?? '') === 'income' && $_ce_value !== '') {
                    $_ce_meta = 'PHP ' . number_format((float)$_ce_value, 2);
                } elseif ($cur_cat === 'Extension' && in_array(($_ce_parts[0] ?? ''), ['resource-speaker-local', 'resource-speaker-intl'], true) && $_ce_value !== '') {
                    $_ce_meta = rtrim(rtrim(number_format((float)$_ce_value, 2), '0'), '.') . ' hour(s)';
                }
                $_ce_needs_rev = (($_ce['revision_status'] ?? '') === 'needs_revision');
                $_ce_rev_ok    = ($app['status'] !== 'needs_revision') || $_ce_needs_rev;
                $_ce_verified  = (!empty($_ce['verified']) && !$_ce_needs_rev);
                $_ce_can_edit  = $can_edit && $_ce_rev_ok && !$_ce_verified;
                $_ev_files_btn = array_map(fn($f) => [
                    'evidence_id'       => $f['evidence_id'],
                    'file_path'         => $f['file_path'],
                    'original_filename' => $f['original_filename'],
                ], $_ce['_ev_files'] ?? []);
                $_ce_points = kraApplyEffectiveComputedPoints($_ce);
                $_entry_json = htmlspecialchars(json_encode([
                    'submission_id'   => $_ce['submission_id'],
                    'computed_points' => $_ce_points,
                    'remarks'         => $_ce['remarks'],
                    'document_path'   => $_ce['document_path'] ?? '',
                    'verified'        => $_ce['verified'] ?? 0,
                    'evidence_files'  => $_ev_files_btn,
                ]), ENT_QUOTES);
            ?>
            <div class="[display:flex] [align-items:center] [gap:0.4rem] [flex-wrap:wrap]">
              <?php if ($_ce_title !== ''): ?>
              <span class="[color:#1e293b] [font-size:0.72rem] [font-weight:700]"><?= htmlspecialchars($_ce_title) ?></span>
              <?php endif; ?>
              <?php if ($_ce_meta !== ''): ?>
              <span class="[color:#64748b] [font-size:0.7rem]"><?= htmlspecialchars($_ce_meta) ?></span>
              <?php endif; ?>
              <span class="[color:#475569] [font-size:0.72rem] [font-weight:600]"><?= number_format($_ce_points, 2) ?> pts</span>
              <?php if (!empty($_ce['_ev_files'])): ?>
              <span class="[color:#64748b] [font-size:0.7rem]">
                <i class="bi bi-file-earmark [font-size:0.65rem]"></i>
                <?= count($_ce['_ev_files']) ?> file<?= count($_ce['_ev_files']) > 1 ? 's' : '' ?>
              </span>
              <?php endif; ?>
              <?php if ($_ce_verified): ?>
              <span class="[color:#16a34a] [font-size:0.7rem]"><i class="bi bi-patch-check-fill"></i> Acceptable</span>
              <?php elseif ($_ce_can_edit): ?>
              <button type="button"
                      onclick="openEditModal(<?= (int)$_ce['submission_id'] ?>)"
                      id="editBtn_<?= (int)$_ce['submission_id'] ?>"
                      data-entry="<?= $_entry_json ?>"
                      class="[height:22px] [padding:0_8px] [border:1px_solid_#cbd5e1] [border-radius:4px] [color:#334155] [font-size:0.68rem] [font-weight:600] [cursor:pointer] [display:inline-flex] [align-items:center] [gap:3px]" style="background:#f1f5f9;"
                      onmouseover="this.style.background='#e2e8f0'" onmouseout="this.style.background='#f1f5f9'">
                <i class="bi bi-pencil [font-size:0.6rem]"></i> Edit
              </button>
              <?php if (!$locked): ?>
              <form method="POST" id="delEntry_<?= (int)$_ce['submission_id'] ?>" class="[display:inline]">
                <input type="hidden" name="kra_action" value="delete_kra">
                <input type="hidden" name="submission_id" value="<?= (int)$_ce['submission_id'] ?>">
                <button type="button"
                        onclick="rememberKraEntryScroll(); confirmDelete('Remove this entry?','delEntry_<?= (int)$_ce['submission_id'] ?>','Remove','bi-trash')"
                        class="[height:22px] [padding:0_6px] [background:#fef2f2] [border:1px_solid_#fecaca] [border-radius:4px] [color:#dc2626] [font-size:0.68rem] [cursor:pointer] [display:inline-flex] [align-items:center]">
                  <i class="bi bi-trash [font-size:0.6rem]"></i>
                </button>
              </form>
              <?php endif; ?>
              <?php else: ?>
              <span class="[color:#94a3b8] [font-size:0.7rem] [font-style:italic]">Submitted</span>
              <?php endif; ?>
            </div>
            <?php endforeach; ?>
          </div>
          <?php endif; ?>
        </div>

        <!-- Score -->
        <div class="[padding:0.6rem_0.75rem] [text-align:right] [white-space:nowrap] [min-width:64px]">
          <?php if ($row_score !== null): ?>
          <span class="[color:#1e293b] [font-weight:700] [font-size:0.88rem]"><?= number_format($row_score, 2) ?></span>
          <?php endif; ?>
        </div>

        <!-- Add Entry button -->
        <div class="[padding:0.5rem_0.85rem_0.5rem_0]">
          <?php if ($can_edit): ?>
          <button type="button"
                  onclick="<?= $btn_onclick ?>"
                  class="[height:28px] [padding:0_12px] [border:none] [border-radius:5px] [color:#fff] [font-size:0.72rem] [font-weight:700] [cursor:pointer] [white-space:nowrap] [display:inline-flex] [align-items:center] [gap:5px] [transition:background_0.15s]" style="background:#1e3a5f;"
                  onmouseover="this.style.background='#152e4d'" onmouseout="this.style.background='#1e3a5f'">
            <i class="bi bi-plus-lg"></i> Add Entry
          </button>
          <?php else: ?>
          <span class="[color:#94a3b8] [font-size:0.72rem] [font-style:italic]">
            <?php if ($locked): ?>Locked
            <?php elseif ($_deadline_passed): ?>Closed
            <?php else: ?>Submitted<?php endif; ?>
          </span>
          <?php endif; ?>
        </div>

      </div>
      <?php endforeach; // end group items ?>

      <!-- Group total row -->
      <div class="[background:#1e3a5f] [padding:0.45rem_0.85rem] [display:flex] [justify-content:space-between] [align-items:center] [gap:1rem]">
        <!-- Prev / Next navigation -->
        <?php if ($group_count > 1): ?>
        <div class="[display:flex] [gap:0.4rem]">
            <?php if ($gi > 0): ?>
            <button type="button" onclick="goCriterion(<?= $gi - 1 ?>)"
                    class="[height:26px] [padding:0_10px] [border:1px_solid_rgba(255,255,255,0.25)] [border-radius:5px] [color:#fff] [font-size:0.72rem] [font-weight:600] [cursor:pointer] [display:inline-flex] [align-items:center] [gap:4px]" style="background:rgba(255,255,255,0.12);"
                    onmouseover="this.style.background='rgba(255,255,255,0.2)'"
                    onmouseout="this.style.background='rgba(255,255,255,0.12)'">
                <i class="bi bi-chevron-left"></i> Prev
            </button>
            <?php endif; ?>
            <?php if ($gi < $group_count - 1): ?>
            <button type="button" onclick="goCriterion(<?= $gi + 1 ?>)"
                    class="[height:26px] [padding:0_10px] [border:1px_solid_rgba(255,255,255,0.4)] [border-radius:5px] [color:#1e293b] [font-size:0.72rem] [font-weight:700] [cursor:pointer] [display:inline-flex] [align-items:center] [gap:4px]" style="background:#fff;"
                    onmouseover="this.style.background='#f1f5f9'"
                    onmouseout="this.style.background='#fff'">
                Next <i class="bi bi-chevron-right"></i>
            </button>
            <?php endif; ?>
        </div>
        <?php else: ?>
        <div></div>
        <?php endif; ?>
        <!-- Total -->
        <div class="[display:flex] [align-items:center] [gap:2rem]">
            <span class="[color:rgba(255,255,255,0.55)] [font-size:0.72rem] [font-weight:600] [text-transform:uppercase] [letter-spacing:0.04em]">Total criterion</span>
            <span class="[color:#fff] [font-weight:800] [font-size:0.92rem] [min-width:80px] [text-align:right]">
                <?= number_format($group_score, 2) ?> &nbsp;/&nbsp; <?= number_format($group_max, 2) ?>
            </span>
        </div>
      </div>

      <?php // Note: editBtn_{id} elements are now rendered inline in each sub-item row above ?>

    </div>
    <?php endforeach; // end dark_groups ?>

    <?php endif; // end empty criteria_list check ?>

    <!-- Bottom grand total bar -->
    <?php
    $faculty_rank_step2 = $faculty['rank'] ?? '';
    $score_step2   = getApplicationScoreSummary($pdo, (int)$app_id);
    $weighted2     = $score_step2['weighted_score'];
    $inc2          = $score_step2['sub_rank_increment'];
    $weights2      = $score_step2['weights'];
    $kra_weighted2 = [
        'Instruction'              => round($score_step2['kra1'] * $weights2['Instruction'], 2),
        'Research'                 => round($score_step2['kra2'] * $weights2['Research'], 2),
        'Extension'                => round($score_step2['kra3'] * $weights2['Extension'], 2),
        'Professional Development' => round($score_step2['kra4'] * $weights2['Professional Development'], 2),
    ];
    ?>
    <div class="[background:#1a3a6b] [border-radius:0_0_8px_8px] [padding:0.6rem_1rem] [display:flex] [justify-content:flex-end] [align-items:center] [gap:1.5rem] [margin-top:0.5rem] [flex-wrap:wrap]">
      <div class="[color:#fff] [font-weight:700] [font-size:0.9rem] [white-space:nowrap]">
        Grand Total: <span class="[color:#fff] [font-size:1rem] [font-weight:800]"><?= number_format($weighted2, 2) ?></span>
        <?php if ($inc2 > 0): ?>
        <span class="[background:rgba(255,255,255,0.15)] [color:#fff] [border-radius:20px] [padding:0.1rem_0.5rem] [font-size:0.65rem] [font-weight:700] [margin-left:0.4rem]">+<?= $inc2 ?> sub-rank<?= $inc2 > 1 ? 's' : '' ?></span>
        <?php endif; ?>
      </div>
    </div>

    <?php endif; // End of regular KRA content ?>
  </div><!-- end tab content dark bg -->
</div><!-- end neon-card -->


<!-- KRA ENTRY MODAL -->
<div class="modal fade" id="kraEntryModal" tabindex="-1">
  <div class="modal-dialog modal-fullscreen-lg-down modal-dialog-centered modal-dialog-scrollable [width:96vw] [max-width:96vw]">
    <div class="modal-content [border-radius:14px] [overflow:hidden] [border:none] [box-shadow:0_24px_60px_rgba(0,0,0,0.18)]">

      <!-- Modal Header -->
      <div class="modal-header [background:linear-gradient(135deg,#1a3a6b,#1e4d8c)] [border:none] [padding:1.1rem_1.5rem]">
        <div class="d-flex align-items-center gap-4 flex-grow-1 min-w-0">
          <?php
          $kra_titles = [
              'Instruction'              => ['title' => 'KRA I ... Teaching Effectiveness',             'max' => 100, 'icon' => 'bi-book'],
              'Research'                 => ['title' => 'KRA II ... Research, Invention & Creative Work','max' => 100, 'icon' => 'bi-lightbulb'],
              'Extension'                => ['title' => 'KRA III ... Extension Services',               'max' => 100, 'icon' => 'bi-people'],
              'Professional Development' => ['title' => 'KRA IV ... Professional Development',          'max' => 100, 'icon' => 'bi-mortarboard'],
          ];
          $kra_info_modal = $kra_titles[$cur_cat] ?? ['title' => $cur_cat, 'max' => 100, 'icon' => 'bi-table'];
          ?>
          <div class="[width:36px] [height:36px] [border-radius:8px] [background:rgba(255,255,255,0.12)] [display:flex] [align-items:center] [justify-content:center] [flex-shrink:0]">
            <i class="bi <?= $kra_info_modal['icon'] ?> [color:#e2e8f0] [font-size:1rem]"></i>
          </div>
          <div class="min-w-0">
            <div class="[color:#fff] [font-weight:700] [font-size:0.95rem] [line-height:1.2]">
              <?= htmlspecialchars($kra_info_modal['title']) ?>
            </div>
            <div class="[color:rgba(255,255,255,0.55)] [font-size:0.72rem] [margin-top:2px]">
              Max <?= $kra_info_modal['max'] ?> pts &nbsp;&middot;&nbsp; Entries save automatically
            </div>
          </div>
          <?php if ($cur_cat === 'Instruction'): ?>
          <span id="criteriaAFocusLabel"
                style="display:none;align-items:center;padding:0.2rem 0.55rem;border-radius:999px;
                       background:rgba(255,255,255,0.18);color:#fff;font-size:0.68rem;font-weight:800;
                       letter-spacing:0.06em;text-transform:uppercase;margin-left:0.5rem;flex-shrink:0;"></span>
          <?php endif; ?>
        </div>
        <button type="button" class="btn-close btn-close-white ms-4 [transition:opacity_0.15s]" data-bs-dismiss="modal"
                style="opacity:0.7;" onmouseover="this.style.opacity='1'" onmouseout="this.style.opacity='0.7'"></button>
      </div>

      <!-- Modal Body -->
      <div class="modal-body p-0 [background:#f8fafc]">
        <div class="table-responsive">
          <table class="table table-modern kra-entry-table kra-entry-table-<?= strtolower(str_replace(' ', '-', $cur_cat)) ?> mb-0 [font-size:0.82rem] [&_th]:bg-transparent" id="kraTable">
            <?php if ($cur_cat==='Research'): ?>
            <colgroup>
              <col class="[width:56px]">
              <col class="[width:48px]">
              <col>
              <col class="[width:118px]">
              <col class="[width:106px]">
              <col class="[width:92px]">
              <col class="[width:112px]">
            </colgroup>
            <?php elseif ($cur_cat==='Extension'): ?>
            <colgroup>
              <col class="[width:56px]">
              <col class="[width:48px]">
              <col>
              <col>
              <col class="[width:106px]">
              <col class="[width:92px]">
              <col class="[width:112px]">
            </colgroup>
            <?php else: ?>
            <colgroup>
              <col class="[width:56px]">
              <col class="[width:48px]">
              <col>
              <col>
              <col class="[width:118px]">
              <col class="[width:106px]">
              <col class="[width:92px]">
              <col class="[width:112px]">
            </colgroup>
            <?php endif; ?>
            <thead>
              <tr class="[background:#1e4d8c]">
                <th class="[width:56px] [padding:0.7rem_1rem]">
                  <button type="button" onclick="addKraRow()" title="Add new row"
                          class="[width:36px] [height:36px] [padding:0] [border:2px_solid_rgba(255,255,255,0.3)] [border-radius:8px] [color:#fff] [font-size:1.3rem] [font-weight:900] [cursor:pointer] [display:flex] [align-items:center] [justify-content:center] [box-shadow:0_2px_6px_rgba(0,0,0,0.25)] [transition:background_0.15s,transform_0.1s] [position:relative] [z-index:10]" style="background:#16a34a;"
                          onmouseover="this.style.background='#15803d';this.style.transform='scale(1.08)'"
                          onmouseout="this.style.background='#16a34a';this.style.transform='scale(1)'">
                    <i class="bi bi-plus-lg"></i>
                  </button>
                </th>
                <th class="[padding:0.7rem_0.5rem] [color:rgba(255,255,255,0.65)] [font-size:0.68rem] [font-weight:700] [letter-spacing:0.06em] [text-transform:uppercase]">#</th>
                <?php if ($cur_cat==='Research'): ?>
                <th class="[padding:0.7rem_0.75rem] [color:rgba(255,255,255,0.65)] [font-size:0.68rem] [font-weight:700] [letter-spacing:0.06em] [text-transform:uppercase]">Type / Details</th>
                <th class="[padding:0.7rem_0.75rem] [color:rgba(255,255,255,0.65)] [font-size:0.68rem] [font-weight:700] [letter-spacing:0.06em] [text-transform:uppercase] [width:110px]">Contribution</th>
                <?php elseif ($cur_cat==='Extension'): ?>
                <th class="[padding:0.7rem_0.75rem] [color:rgba(255,255,255,0.65)] [font-size:0.68rem] [font-weight:700] [letter-spacing:0.06em] [text-transform:uppercase] [min-width:160px]">Criterion / Type</th>
                <th class="[padding:0.7rem_0.75rem] [color:rgba(255,255,255,0.65)] [font-size:0.68rem] [font-weight:700] [letter-spacing:0.06em] [text-transform:uppercase] [min-width:200px]">Details</th>
                <?php elseif ($cur_cat==='Professional Development'): ?>
                <th class="[padding:0.7rem_0.75rem] [color:rgba(255,255,255,0.65)] [font-size:0.68rem] [font-weight:700] [letter-spacing:0.06em] [text-transform:uppercase] [min-width:160px]">Criterion</th>
                <th class="[padding:0.7rem_0.75rem] [color:rgba(255,255,255,0.65)] [font-size:0.68rem] [font-weight:700] [letter-spacing:0.06em] [text-transform:uppercase] [min-width:200px]">Description / Details</th>
                <th class="[padding:0.7rem_0.75rem] [color:rgba(255,255,255,0.65)] [font-size:0.68rem] [font-weight:700] [letter-spacing:0.06em] [text-transform:uppercase] [width:110px]">Sub-value</th>
                <?php else: ?>
                <th class="[padding:0.7rem_0.75rem] [color:rgba(255,255,255,0.65)] [font-size:0.68rem] [font-weight:700] [letter-spacing:0.06em] [text-transform:uppercase] [min-width:140px]">Criterion</th>
                <th class="[padding:0.7rem_0.75rem] [color:rgba(255,255,255,0.65)] [font-size:0.68rem] [font-weight:700] [letter-spacing:0.06em] [text-transform:uppercase] [min-width:200px]">Details</th>
                <th class="[padding:0.7rem_0.75rem] [color:rgba(255,255,255,0.65)] [font-size:0.68rem] [font-weight:700] [letter-spacing:0.06em] [text-transform:uppercase] [width:110px]">Sub-value</th>
                <?php endif; ?>
                <th class="[padding:0.7rem_0.75rem] [color:rgba(255,255,255,0.65)] [font-size:0.68rem] [font-weight:700] [letter-spacing:0.06em] [text-transform:uppercase] [width:90px] [text-align:center]"><?= $cur_cat==='Research' ? 'Faculty Score' : 'Score' ?></th>
                <th class="[padding:0.7rem_0.75rem] [color:rgba(255,255,255,0.65)] [font-size:0.68rem] [font-weight:700] [letter-spacing:0.06em] [text-transform:uppercase] [width:90px] [text-align:center]">Evidence</th>
                <th class="[width:112px] [text-align:center]"></th>
              </tr>
            </thead>
            <tbody id="kraTableBody"></tbody>
            <tfoot>
              <tr class="[background:#eff6ff] [border-top:2px_solid_#1a3a6b]">
                <td colspan="<?= $cur_cat==='Research'?4:($cur_cat==='Extension'?4:5) ?>"
                    class="[padding:0.75rem_1rem] [font-size:0.78rem] [font-weight:700] [color:#1a3a6b]">
                  TOTAL
                  <span class="[font-weight:400] [color:#64748b] [margin-left:6px] [font-size:0.72rem]">
                    <?= $cur_cat === 'Extension' ? '(A+B+C max 100 + Criterion D bonus max 20)' : ($cur_cat === 'Instruction' ? '(capped at 60)' : '(capped at 100)') ?>
                  </span>
                </td>
                <td class="[padding:0.75rem] [text-align:center] [font-weight:800] [color:#1e4d8c] [font-size:1.15rem]" id="kraGrandTotalDefault">0.00</td>
                <td colspan="2"></td>
              </tr>
            </tfoot>
          </table>
        </div>
        <div id="criteriaASections" style="display:none;padding:1rem;background:#f8fafc;">
          <div class="criteria-a-section [background:#fff] [border:1px_solid_#e2e8f0] [border-radius:8px] [overflow:hidden] [margin-bottom:1rem]" data-section-type="set">
            <div class="[display:flex] [align-items:center] [justify-content:space-between] [gap:1rem] [padding:0.65rem_1rem] [background:#f8fafc] [border-bottom:1px_solid_#e2e8f0]">
              <div class="[display:flex] [align-items:center] [gap:0.6rem] [min-width:0]">
                <span class="[font-size:0.8rem] [font-weight:700] [color:#1a3a6b]">Student Evaluation of Teaching (SET)</span>
                <span class="[background:#eff6ff] [color:#1e4d8c] [font-size:0.65rem] [font-weight:700] [padding:2px_8px] [border-radius:4px] [border:1px_solid_#bfdbfe]"><?= number_format($kra1_a_caps['set'], 2) ?> pts max</span>
              </div>
              <button type="button" data-add-type="set" onclick="addKraRow('set')" title="Add SET entry"
                      class="[height:28px] [padding:0_10px] [border:none] [border-radius:5px] [color:#fff] [font-size:0.72rem] [font-weight:700] [cursor:pointer] [display:inline-flex] [align-items:center] [gap:4px] [white-space:nowrap]" style="background:#1a3a6b;"
                      onmouseover="this.style.background='#1e4d8c'" onmouseout="this.style.background='#1a3a6b'">
                <i class="bi bi-plus-lg"></i> Add Entry
              </button>
            </div>
            <div class="table-responsive">
              <table class="table table-modern kra-entry-table kra-entry-table-instruction mb-0 [font-size:0.82rem] [table-layout:fixed] [&_th]:bg-transparent">
                <colgroup>
                  <col class="[width:44px]">
                  <col class="[width:40px]">
                  <col class="[min-width:140px]">
                  <col class="[width:150px]">
                  <col class="[width:80px]">
                  <col class="[width:150px]">
                  <col class="[width:80px]">
                  <col class="[width:88px]">
                </colgroup>
                <thead><tr class="[background:#1a3a6b]">
                  <th class="[padding:0.55rem_0.5rem]"></th>
                  <th class="[padding:0.55rem_0.4rem] [color:rgba(255,255,255,0.6)] [font-size:0.62rem] [font-weight:700] [letter-spacing:0.05em] [text-transform:uppercase]">No.</th>
                  <th class="[padding:0.55rem_0.75rem] [color:rgba(255,255,255,0.6)] [font-size:0.62rem] [font-weight:700] [letter-spacing:0.05em] [text-transform:uppercase]">Evaluation Period</th>
                  <th class="[padding:0.55rem_0.5rem] [color:rgba(255,255,255,0.6)] [font-size:0.62rem] [font-weight:700] [letter-spacing:0.05em] [text-transform:uppercase] [text-align:center]">1st Semester</th>
                  <th class="[padding:0.55rem_0.4rem] [color:rgba(255,255,255,0.6)] [font-size:0.62rem] [font-weight:700] [letter-spacing:0.05em] [text-transform:uppercase] [text-align:center]">Evidence</th>
                  <th class="[padding:0.55rem_0.5rem] [color:rgba(255,255,255,0.6)] [font-size:0.62rem] [font-weight:700] [letter-spacing:0.05em] [text-transform:uppercase] [text-align:center]">2nd Semester</th>
                  <th class="[padding:0.55rem_0.4rem] [color:rgba(255,255,255,0.6)] [font-size:0.62rem] [font-weight:700] [letter-spacing:0.05em] [text-transform:uppercase] [text-align:center]">Evidence</th>
                  <th class="[padding:0.55rem_0.5rem] [color:rgba(255,255,255,0.6)] [font-size:0.62rem] [font-weight:700] [letter-spacing:0.05em] [text-transform:uppercase] [text-align:center]">Actions</th>
                </tr></thead>
                <tbody id="criteriaASetBody"></tbody>
                <tfoot><tr class="[background:#f8fafc] [border-top:1px_solid_#e2e8f0]">
                  <td colspan="6" class="[padding:0.6rem_0.75rem] [font-size:0.75rem] [font-weight:700] [color:#475569] [text-align:right]">SET AVG: <span id="kraSetAverage" class="[color:#1a3a6b] [font-size:0.95rem] [font-weight:800]">0.00</span></td>
                  <td colspan="2" class="[padding:0.6rem_0.75rem] [font-size:0.75rem] [font-weight:700] [color:#475569] [text-align:right]">SET SUBTOTAL: <span id="kraSetSubtotal" class="[color:#1a3a6b] [font-size:0.95rem] [font-weight:800]">0.00</span></td>
                </tr></tfoot>
              </table>
            </div>
          </div>
          <div class="criteria-a-section [background:#fff] [border:1px_solid_#e2e8f0] [border-radius:8px] [overflow:hidden] [margin-bottom:1rem]" data-section-type="sef">
            <div class="[display:flex] [align-items:center] [justify-content:space-between] [gap:1rem] [padding:0.65rem_1rem] [background:#f8fafc] [border-bottom:1px_solid_#e2e8f0]">
              <div class="[display:flex] [align-items:center] [gap:0.6rem] [min-width:0]">
                <span class="[font-size:0.8rem] [font-weight:700] [color:#1a3a6b]">Supervisor Evaluation Form (SEF)</span>
                <span class="[background:#f0fdfa] [color:#0f766e] [font-size:0.65rem] [font-weight:700] [padding:2px_8px] [border-radius:4px] [border:1px_solid_#99f6e4]"><?= number_format($kra1_a_caps['sef'], 2) ?> pts max</span>
              </div>
              <button type="button" data-add-type="sef" onclick="addKraRow('sef')" title="Add SEF entry"
                      class="[height:28px] [padding:0_10px] [border:none] [border-radius:5px] [color:#fff] [font-size:0.72rem] [font-weight:700] [cursor:pointer] [display:inline-flex] [align-items:center] [gap:4px] [white-space:nowrap]" style="background:#0f766e;"
                      onmouseover="this.style.background='#115e59'" onmouseout="this.style.background='#0f766e'">
                <i class="bi bi-plus-lg"></i> Add Entry
              </button>
            </div>
            <div class="table-responsive">
              <table class="table table-modern kra-entry-table kra-entry-table-instruction mb-0 [font-size:0.82rem] [table-layout:fixed] [&_th]:bg-transparent">
                <colgroup>
                  <col class="[width:44px]">
                  <col class="[width:40px]">
                  <col class="[min-width:140px]">
                  <col class="[width:150px]">
                  <col class="[width:80px]">
                  <col class="[width:150px]">
                  <col class="[width:80px]">
                  <col class="[width:88px]">
                </colgroup>
                <thead><tr class="[background:#1a3a6b]">
                  <th class="[padding:0.55rem_0.5rem]"></th>
                  <th class="[padding:0.55rem_0.4rem] [color:rgba(255,255,255,0.6)] [font-size:0.62rem] [font-weight:700] [letter-spacing:0.05em] [text-transform:uppercase]">No.</th>
                  <th class="[padding:0.55rem_0.75rem] [color:rgba(255,255,255,0.6)] [font-size:0.62rem] [font-weight:700] [letter-spacing:0.05em] [text-transform:uppercase]">Evaluation Period</th>
                  <th class="[padding:0.55rem_0.5rem] [color:rgba(255,255,255,0.6)] [font-size:0.62rem] [font-weight:700] [letter-spacing:0.05em] [text-transform:uppercase] [text-align:center]">1st Semester</th>
                  <th class="[padding:0.55rem_0.4rem] [color:rgba(255,255,255,0.6)] [font-size:0.62rem] [font-weight:700] [letter-spacing:0.05em] [text-transform:uppercase] [text-align:center]">Evidence</th>
                  <th class="[padding:0.55rem_0.5rem] [color:rgba(255,255,255,0.6)] [font-size:0.62rem] [font-weight:700] [letter-spacing:0.05em] [text-transform:uppercase] [text-align:center]">2nd Semester</th>
                  <th class="[padding:0.55rem_0.4rem] [color:rgba(255,255,255,0.6)] [font-size:0.62rem] [font-weight:700] [letter-spacing:0.05em] [text-transform:uppercase] [text-align:center]">Evidence</th>
                  <th class="[padding:0.55rem_0.5rem] [color:rgba(255,255,255,0.6)] [font-size:0.62rem] [font-weight:700] [letter-spacing:0.05em] [text-transform:uppercase] [text-align:center]">Actions</th>
                </tr></thead>
                <tbody id="criteriaASefBody"></tbody>
                <tfoot><tr class="[background:#f8fafc] [border-top:1px_solid_#e2e8f0]">
                  <td colspan="6" class="[padding:0.6rem_0.75rem] [font-size:0.75rem] [font-weight:700] [color:#475569] [text-align:right]">SEF AVG: <span id="kraSefAverage" class="[color:#0f766e] [font-size:0.95rem] [font-weight:800]">0.00</span></td>
                  <td colspan="2" class="[padding:0.6rem_0.75rem] [font-size:0.75rem] [font-weight:700] [color:#475569] [text-align:right]">SEF SUBTOTAL: <span id="kraSefSubtotal" class="[color:#0f766e] [font-size:0.95rem] [font-weight:800]">0.00</span></td>
                </tr></tfoot>
              </table>
            </div>
          </div>
          <div class="[background:#f8fafc] [border-top:1px_solid_#e2e8f0] [padding:0.65rem_0.75rem] [text-align:right] [font-size:0.75rem] [font-weight:700] [color:#475569]">
            Criterion A Total: <span id="kraGrandTotalCriteriaA" class="[color:#1a3a6b] [font-size:1rem] [font-weight:800]">0.00</span>
          </div>
        </div>
      </div>

      <!-- Modal Footer -->
      <div class="modal-footer [background:#fff] [border-top:1px_solid_#e2e8f0] [padding:0.75rem_1.25rem] [justify-content:space-between] [align-items:center]">
        <span class="[font-size:0.75rem] [color:#94a3b8] [display:flex] [align-items:center] [gap:0.4rem]">
          <i class="bi bi-info-circle [color:#475569]"></i>
          Fill fields &amp; upload evidence ... row saves automatically
        </span>
        <button type="button" id="doneBtn" onclick="handleDone()"
                style="background:#1e4d8c;color:#fff;border:none;border-radius:8px;
                       padding:0.45rem 1.25rem;font-size:0.85rem;font-weight:600;cursor:pointer;
                       display:flex;align-items:center;gap:0.4rem;transition:background 0.15s;"
                onmouseover="this.style.background='#1a3a6b'" onmouseout="this.style.background='#1e4d8c'">
          <i class="bi bi-check2-circle"></i>Done
        </button>
      </div>

      <!-- Inline alert area -->
      <div id="kraAlertBox" style="display:none;background:#f8fafc;border-top:2px solid #475569;
                padding:0.85rem 1.25rem;align-items:flex-start;gap:0.75rem;">
        <i class="bi bi-exclamation-triangle-fill [color:#475569] [font-size:1rem] [flex-shrink:0] [margin-top:2px]"></i>
        <span id="kraAlertMsg" class="[font-size:0.82rem] [color:#334155] [line-height:1.6]"></span>
      </div>
    </div>
  </div>
</div>

<!-- Upload Sub-Modal -->
<div class="modal fade [z-index:10060]" id="uploadModal" tabindex="-1" data-bs-focus="false">
  <div class="modal-dialog modal-dialog-centered [max-width:500px]">
    <div class="modal-content [border-radius:12px] [overflow:hidden] [border:none] [box-shadow:0_20px_60px_rgba(0,0,0,0.18)]">

      <!-- Header -->
      <div class="modal-header [background:#1a3a6b]">
        <div class="[display:flex] [align-items:center] [gap:0.6rem]">
          <div class="[width:30px] [height:30px] [border-radius:7px] [background:rgba(255,255,255,0.12)] [display:flex] [align-items:center] [justify-content:center] [flex-shrink:0]">
            <i class="bi bi-cloud-arrow-up [color:#fff] [font-size:0.9rem]"></i>
          </div>
          <div>
            <div class="[color:#fff] [font-weight:700] [font-size:0.9rem] [line-height:1.2]">Upload Evidence</div>
            <div id="uploadContext" class="[color:#93c5fd] [font-size:0.68rem] [margin-top:1px]"></div>
          </div>
        </div>
        <button type="button" data-bs-dismiss="modal"
                class="[border:none] [border-radius:6px] [width:28px] [height:28px] [color:#fff] [font-size:0.85rem] [cursor:pointer] [display:flex] [align-items:center] [justify-content:center]" style="background:rgba(255,255,255,0.1);"
                onmouseover="this.style.background='rgba(255,255,255,0.2)'"
                onmouseout="this.style.background='rgba(255,255,255,0.1)'">
          <i class="bi bi-x-lg"></i>
        </button>
      </div>

      <div class="modal-body [padding:1.1rem_1.25rem]">

        <!-- Disclaimer -->
        <div class="[display:flex] [align-items:flex-start] [gap:0.5rem] [padding:0.55rem_0.75rem] [background:#fffbeb] [border:1px_solid_#fde68a] [border-radius:7px] [margin-bottom:1rem]">
          <i class="bi bi-exclamation-triangle-fill [color:#d97706] [font-size:0.75rem] [flex-shrink:0] [margin-top:2px]"></i>
          <span class="[font-size:0.72rem] [color:#92400e] [line-height:1.5]">
            <strong>Before uploading —</strong> select a criterion and fill all required score fields first.
            Evidence on an incomplete entry may not count during evaluation.
          </span>
        </div>

        <!-- Cap indicator -->
        <div id="uploadCapInfo" style="display:flex;align-items:center;justify-content:space-between;
                    padding:0.5rem 0.75rem;background:#f0f4fb;border:1px solid #dbeafe;
                    border-radius:7px;margin-bottom:0.9rem;">
          <div class="[display:flex] [align-items:center] [gap:0.4rem]">
            <i class="bi bi-paperclip [color:#1a3a6b] [font-size:0.75rem]"></i>
            <span id="uploadCapText" class="[font-size:0.75rem] [color:#1a3a6b] [font-weight:600]">0 / 10 files</span>
          </div>
          <span class="[font-size:0.68rem] [color:#64748b]">PDF, JPG, PNG · max 50 MB each</span>
        </div>

        <!-- File input (outside drop zone — prevents Bootstrap focus re-trigger) -->
        <input type="file" id="uploadFileInput" class="d-none" accept=".pdf,.jpg,.jpeg,.png" multiple onchange="handleFileSelect(this)">

        <!-- Drop zone -->
        <div id="uploadDropZone"
             style="border:2px dashed #cbd5e1;border-radius:10px;padding:1.2rem;
                    text-align:center;cursor:pointer;transition:all 0.2s;background:#f8fafc;
                    margin-bottom:0.9rem;"
             onclick="document.getElementById('uploadFileInput').click()"
             ondragover="event.preventDefault();this.style.borderColor='#1a3a6b';this.style.background='#f0f4fb';"
             ondragleave="this.style.borderColor='#cbd5e1';this.style.background='#f8fafc';"
             ondrop="handleDrop(event)">
          <i class="bi bi-cloud-arrow-up" id="uploadDropIcon"
             style="font-size:1.75rem;color:#94a3b8;display:block;margin-bottom:6px;"></i>
          <div id="uploadDropText" class="[font-size:0.8rem] [color:#475569] [font-weight:500]">
            Click or drag &amp; drop files here
          </div>
          <div class="[font-size:0.7rem] [color:#94a3b8] [margin-top:3px]">Multiple files allowed</div>
        </div>

        <!-- Upload progress -->
        <div id="uploadProgress" style="display:none;margin-bottom:0.75rem;">
          <div class="[display:flex] [align-items:center] [gap:0.5rem] [margin-bottom:5px]">
            <div class="spinner-border spinner-border-sm [color:#1a3a6b] [width:14px] [height:14px] [border-width:2px]"></div>
            <span id="batchStatusText" class="[font-size:0.78rem] [color:#475569]">Uploading…</span>
          </div>
          <div class="[height:5px] [background:#e2e8f0] [border-radius:99px] [overflow:hidden]">
            <div id="batchProgressBar" class="progress-bar"
                 style="height:100%;width:0%;background:#1a3a6b;border-radius:99px;transition:width 0.3s;"></div>
          </div>
        </div>

        <!-- Uploaded files list -->
        <div>
          <div class="[display:flex] [align-items:center] [justify-content:space-between] [margin-bottom:0.5rem]">
            <span class="[font-size:0.75rem] [font-weight:700] [color:#374151] [text-transform:uppercase] [letter-spacing:0.04em]">
              Uploaded Files
            </span>
            <span id="uploadFileCount"
                  class="[background:#1a3a6b] [color:#fff] [font-size:0.65rem] [font-weight:700] [border-radius:20px] [padding:1px_8px]">0</span>
          </div>
          <div id="uploadFileList"
               class="[border:1px_solid_#e2e8f0] [border-radius:8px] [overflow:hidden] [min-height:48px]">
            <!-- populated by renderUploadFileList() -->
          </div>
        </div>

      </div>

      <!-- Footer -->
      <div class="modal-footer">
        <button type="button" data-bs-dismiss="modal"
                class="[padding:0.4rem_1.1rem] [border:1px_solid_#cbd5e1] [border-radius:7px] [color:#475569] [font-size:0.8rem] [font-weight:600] [cursor:pointer]" style="background:#fff;"
                onmouseover="this.style.background='#f8fafc'" onmouseout="this.style.background='#fff'">
          Close
        </button>
      </div>

    </div>
  </div>
</div>

<!-- Submit Confirm Modal -->
<div id="submitConfirmModal" class="modal" role="dialog" aria-modal="true" aria-labelledby="submitConfirmTitle" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,0.5);z-index:99999;align-items:center;justify-content:center;">
  <div id="submitModalBox" class="modal-dialog modal-dialog-centered modal-content" style="background:#fff;border-radius:0;width:100%;max-width:520px;margin:1rem;box-shadow:0 20px 60px rgba(0,0,0,0.2);overflow:hidden;">

    <!-- Header -->
    <div class="modal-header [background:#1e4d8c]">
      <img src="frontend/assets/images/logo.jpg" alt="SUCFRMS Logo"
           class="[width:36px] [height:36px] [border-radius:50%] [object-fit:cover] [border:2px_solid_rgba(255,255,255,0.3)] [flex-shrink:0]">
      <div>
        <div id="submitConfirmTitle" class="modal-title [color:#fff]">Submit Application</div>
        <div class="[color:#bfdbfe] [font-size:0.7rem] [margin-top:1px]">SUCFRMS — Faculty Reclassification</div>
      </div>
      <button onclick="document.getElementById('submitConfirmModal').style.display='none'"
              class="[margin-left:auto] [border:none] [border-radius:6px] [width:26px] [height:26px] [color:#fff] [font-size:0.8rem] [cursor:pointer] [display:flex] [align-items:center] [justify-content:center]" style="background:rgba(255,255,255,0.1);"
              onmouseover="this.style.background='rgba(255,255,255,0.2)'"
              onmouseout="this.style.background='rgba(255,255,255,0.1)'">
        <i class="bi bi-x-lg"></i>
      </button>
    </div>

    <!-- Step 1: Review -->
    <div id="submitStateReview">
      <div class="[padding:1.1rem_1.5rem_0.5rem]">
        <div class="[display:flex] [align-items:center] [gap:0.5rem] [margin-bottom:0.75rem]">
          <div class="[width:22px] [height:22px] [border-radius:50%] [background:#1e4d8c] [color:#fff] [font-size:0.65rem] [font-weight:700] [display:flex] [align-items:center] [justify-content:center] [flex-shrink:0]">1</div>
          <span class="[font-weight:700] [color:#1e293b] [font-size:0.88rem]">Review Your Application</span>
          <span class="[font-size:0.7rem] [color:#94a3b8] [margin-left:auto]">Check before submitting</span>
        </div>

        <!-- KRA summary table -->
        <div class="[border:1px_solid_#e2e8f0] [border-radius:8px] [overflow:hidden] [margin-bottom:0.85rem]">
          <div class="[background:#f8fafc] [padding:0.45rem_0.85rem] [display:grid] [grid-template-columns:1fr_46px] [gap:0.5rem] [font-size:0.62rem] [font-weight:700] [text-transform:uppercase] [letter-spacing:0.06em] [color:#94a3b8] [border-bottom:1px_solid_#e2e8f0]">
            <span>KRA / Entry</span><span class="[text-align:center]">Evidence</span>
          </div>
          <div id="submitReviewBody" class="[max-height:260px] [overflow-y:auto]">
            <div class="[padding:1rem] [text-align:center] [color:#94a3b8] [font-size:0.8rem]">
              <div class="spinner-border spinner-border-sm [width:14px] [height:14px] [border-width:2px]"></div>
              Loading…
            </div>
          </div>
        </div>

        <!-- Weighted score summary -->
        <div id="submitScoreSummary" class="[display:flex] [align-items:center] [justify-content:space-between] [padding:0.65rem_0.85rem] [background:#f0f4fb] [border:1px_solid_#dbeafe] [border-radius:8px] [margin-bottom:0.85rem]">
          <span class="[font-size:0.78rem] [color:#1a3a6b] [font-weight:600]">Current Weighted Score</span>
          <span id="submitScoreVal" style="font-size:1rem;font-weight:800;color:#1a3a6b;">—</span>
        </div>

        <!-- Warning if issues found -->
        <div id="submitReviewWarning" style="display:none;align-items:flex-start;gap:0.5rem;
                    padding:0.6rem 0.85rem;background:#fffbeb;border:1px solid #fde68a;
                    border-radius:7px;margin-bottom:0.85rem;font-size:0.75rem;color:#92400e;">
          <i class="bi bi-exclamation-triangle-fill [flex-shrink:0] [margin-top:2px]"></i>
          <span id="submitReviewWarningText"></span>
        </div>
      </div>

      <div class="[height:1px] [background:#e2e8f0]"></div>
      <div class="[padding:0.9rem_1.5rem] [display:flex] [gap:0.6rem] [justify-content:flex-end]">
        <button onclick="document.getElementById('submitConfirmModal').style.display='none'"
                class="[padding:0.5rem_1rem] [border:1px_solid_#cbd5e1] [border-radius:7px] [background:#fff] [color:#475569] [font-size:0.82rem] [font-weight:600] [cursor:pointer]">
          Cancel
        </button>
        <button onclick="goToSubmitConfirm()"
                class="[padding:0.5rem_1.2rem] [border:none] [border-radius:7px] [color:#fff] [font-size:0.82rem] [font-weight:700] [cursor:pointer] [display:inline-flex] [align-items:center] [gap:0.4rem]" style="background:#1e4d8c;"
                onmouseover="this.style.background='#1a3a6b'" onmouseout="this.style.background='#1e4d8c'">
          Continue <i class="bi bi-arrow-right"></i>
        </button>
      </div>
    </div>

    <!-- Step 2: Confirm -->
    <div id="submitStateConfirm" style="display:none;">
      <div class="[padding:1.25rem_1.5rem_1rem]">
        <div class="[display:flex] [align-items:center] [gap:0.5rem] [margin-bottom:0.75rem]">
          <div class="[width:22px] [height:22px] [border-radius:50%] [background:#1e4d8c] [color:#fff] [font-size:0.65rem] [font-weight:700] [display:flex] [align-items:center] [justify-content:center] [flex-shrink:0]">2</div>
          <span class="[font-weight:700] [color:#1e293b] [font-size:0.88rem]">Confirm Submission</span>
        </div>
        <p class="[color:#64748b] [font-size:0.82rem] [margin:0_0_0.5rem] [line-height:1.6]">
          You are about to submit your application for evaluator review.
        </p>
        <p class="[font-size:0.75rem] [color:#94a3b8] [margin:0]">
          The evaluation result is a recommendation only, not a final decision.
        </p>
      </div>
      <div class="[height:1px] [background:#e2e8f0]"></div>
      <div class="[padding:0.9rem_1.5rem] [display:flex] [gap:0.6rem] [justify-content:flex-end]">
        <button onclick="goBackToReview()"
                class="[padding:0.5rem_0.9rem] [border:1px_solid_#cbd5e1] [border-radius:7px] [background:#fff] [color:#475569] [font-size:0.82rem] [font-weight:600] [cursor:pointer] [display:inline-flex] [align-items:center] [gap:0.4rem]">
          <i class="bi bi-arrow-left"></i> Back
        </button>
        <button onclick="doSubmitWithAnimation()"
                class="[padding:0.5rem_1.2rem] [border:none] [border-radius:7px] [color:#fff] [font-size:0.82rem] [font-weight:700] [cursor:pointer] [display:inline-flex] [align-items:center] [gap:0.4rem]" style="background:#1e4d8c;"
                onmouseover="this.style.background='#1a3a6b'" onmouseout="this.style.background='#1e4d8c'">
          <i class="bi bi-send"></i> Confirm &amp; Submit
        </button>
      </div>
    </div>

    <!-- Step 3: Submitting -->
    <div id="submitStateProgress" style="display:none;padding:2.5rem 1.75rem;text-align:center;">
      <div class="[width:72px] [height:72px] [background:#f0f4fb] [display:flex] [align-items:center] [justify-content:center] [margin:0_auto_1.5rem] [border-radius:50%] [overflow:hidden]">
        <svg id="submitCheckSvg" viewBox="0 0 52 52" width="52" height="52" class="[display:block]">
          <circle cx="26" cy="26" r="24" fill="#e8edf5"/>
          <circle id="submitCircle" cx="26" cy="26" r="24" fill="none" stroke="#1a3a6b" stroke-width="4"
                  stroke-dasharray="151" stroke-dashoffset="151"
                  stroke-linecap="round"
                  style="transform:rotate(-90deg);transform-origin:center;transition:stroke-dashoffset 0.6s cubic-bezier(0.4,0,0.2,1);"/>
          <polyline id="submitCheck" points="14,27 22,35 38,19" fill="none" stroke="#1a3a6b" stroke-width="3.5"
                    stroke-linecap="round" stroke-linejoin="round"
                    stroke-dasharray="32" stroke-dashoffset="32"
                    style="transition:stroke-dashoffset 0.35s ease 0.55s;"/>
        </svg>
      </div>
      <h5 class="[font-weight:700] [color:#1e293b] [margin-bottom:0.5rem] [font-size:1.1rem]">Submitting Application</h5>
      <p class="[color:#64748b] [font-size:0.85rem] [margin:0_0_1.5rem]">Please wait while your application is being processed...</p>
      <div class="[background:#e2e8f0] [border-radius:0] [height:4px] [overflow:hidden]">
        <div id="submitProgressBar" style="height:100%;width:0%;background:#1e4d8c;transition:width 1.2s cubic-bezier(0.4,0,0.2,1);"></div>
      </div>
    </div>

  </div>
</div>


<script>
function doSubmitWithAnimation() {
    document.getElementById('submitStateConfirm').style.display = 'none';
    document.getElementById('submitStateProgress').style.display = 'block';
    setTimeout(() => {
        document.getElementById('submitCircle').style.strokeDashoffset = '0';
        document.getElementById('submitProgressBar').style.width = '85%';
    }, 50);
    setTimeout(() => { document.getElementById('submitCheck').style.strokeDashoffset = '0'; }, 100);
    setTimeout(() => {
        document.getElementById('submitProgressBar').style.width = '100%';
        document.getElementById('submitProgressBar').style.transition = 'width 0.3s ease';
        setTimeout(() => { document.getElementById('submitAppForm').submit(); }, 350);
    }, 1200);
}

function openSubmitModal() {
    // Reset all states
    document.getElementById('submitCircle').style.strokeDashoffset = '151';
    document.getElementById('submitCheck').style.strokeDashoffset = '32';
    document.getElementById('submitProgressBar').style.width = '0%';
    document.getElementById('submitProgressBar').style.transition = 'width 1.2s cubic-bezier(0.4,0,0.2,1)';
    document.getElementById('submitStateReview').style.display  = 'block';
    document.getElementById('submitStateConfirm').style.display = 'none';
    document.getElementById('submitStateProgress').style.display = 'none';
    document.getElementById('submitModalBox').style.animation = 'none';
    void document.getElementById('submitModalBox').offsetWidth;
    document.getElementById('submitModalBox').style.animation = 'modalFadeIn 0.2s ease forwards';
    document.getElementById('submitConfirmModal').style.display = 'flex';
    buildSubmitReview();
}

function goToSubmitConfirm() {
    document.getElementById('submitStateReview').style.display  = 'none';
    document.getElementById('submitStateConfirm').style.display = 'block';
}

function goBackToReview() {
    document.getElementById('submitStateConfirm').style.display = 'none';
    document.getElementById('submitStateReview').style.display  = 'block';
}

function buildSubmitReview() {
    const body    = document.getElementById('submitReviewBody');
    const warning = document.getElementById('submitReviewWarning');
    const warnTxt = document.getElementById('submitReviewWarningText');
    const scoreEl = document.getElementById('submitScoreVal');

    body.innerHTML = `<div style="padding:1rem;text-align:center;color:#94a3b8;font-size:0.78rem;">
        <div class="spinner-border spinner-border-sm" style="width:14px;height:14px;border-width:2px;"></div> Loading…</div>`;

    fetch(`${AJAX_URL}?kra_action=get_all_kra_summary&app_id=${APP_ID}`)
        .then(r => r.json())
        .then(data => {
            if (!data.ok || !data.summary) {
                body.innerHTML = `<div style="padding:1rem;text-align:center;color:#94a3b8;font-size:0.78rem;">Unable to load entries.</div>`;
                return;
            }

            const ws = parseFloat(data.weighted_score) || 0;
            scoreEl.textContent = ws > 0 ? ws.toFixed(2) : '—';
            scoreEl.style.color = ws >= 41 ? '#15803d' : (ws > 0 ? '#dc2626' : '#94a3b8');

            const kraOrder = ['Instruction','Research','Extension','Professional Development'];
            const kraShort = {'Instruction':'KRA I','Research':'KRA II','Extension':'KRA III','Professional Development':'KRA IV'};

            // Group: kra → criterion label → { total_pts, count, all_have_evidence }
            const grouped = {};
            data.summary.forEach(e => {
                const kra   = e.kra_category;
                const label = cleanRemarkLabel(e.remarks, kra);
                if (!grouped[kra]) grouped[kra] = {};
                if (!grouped[kra][label]) grouped[kra][label] = { pts: 0, count: 0, hasEvidence: true };
                grouped[kra][label].pts         += parseFloat(e.computed_points || 0);
                grouped[kra][label].count       += 1;
                grouped[kra][label].hasEvidence  = grouped[kra][label].hasEvidence && !!e.has_evidence;
            });

            let html = '';
            let missingEvidence = 0;

            kraOrder.forEach(kra => {
                if (!grouped[kra]) return;
                const criterions = grouped[kra];
                const short      = kraShort[kra] || kra;

                html += `<div style="padding:0.38rem 0.85rem;background:#f0f4fb;
                                      border-bottom:1px solid #dbeafe;
                                      display:flex;align-items:center;">
                    <span style="font-size:0.67rem;font-weight:800;color:#1a3a6b;
                                 text-transform:uppercase;letter-spacing:0.06em;">${short} — ${kra}</span>
                </div>`;

                const critKeys = Object.keys(criterions);
                critKeys.forEach((label, i) => {
                    const c      = criterions[label];
                    const isLast = i === critKeys.length - 1;
                    if (!c.hasEvidence) missingEvidence++;

                    html += `<div style="display:flex;align-items:center;gap:0.5rem;
                                          padding:0.45rem 0.85rem 0.45rem 1.1rem;
                                          ${isLast ? '' : 'border-bottom:1px solid #f4f6f9;'}">
                        <div style="flex:1;min-width:0;">
                            <div style="font-size:0.75rem;color:#1e293b;font-weight:500;
                                        overflow:hidden;text-overflow:ellipsis;white-space:nowrap;"
                                 title="${esc(label)}">${esc(label)}</div>
                            ${c.count > 1
                                ? `<div style="font-size:0.65rem;color:#94a3b8;margin-top:1px;">${c.count} entries</div>`
                                : ''}
                        </div>
                        ${c.hasEvidence
                            ? `<i class="bi bi-check-circle-fill" style="color:#15803d;font-size:0.8rem;flex-shrink:0;" title="Evidence present"></i>`
                            : `<i class="bi bi-exclamation-circle" style="color:#d97706;font-size:0.8rem;flex-shrink:0;" title="Missing evidence"></i>`}
                    </div>`;
                });
            });

            body.innerHTML = html || `<div style="padding:1rem;text-align:center;color:#94a3b8;font-size:0.78rem;">No entries yet.</div>`;

            if (missingEvidence > 0) {
                warnTxt.textContent = `${missingEvidence} criterion type${missingEvidence > 1 ? 's' : ''} missing evidence — evaluators may not be able to verify ${missingEvidence > 1 ? 'them' : 'it'}.`;
                warning.style.display = 'flex';
            } else {
                warning.style.display = 'none';
            }
        })
        .catch(() => {
            body.innerHTML = `<div style="padding:1rem;text-align:center;color:#94a3b8;font-size:0.78rem;">Failed to load. Check your connection.</div>`;
        });
}

// Clean up raw remark type keys into human-readable labels
function cleanRemarkLabel(remarks, kra) {
    const parts = String(remarks || '').split('|||');
    const type  = parts[0] || '';

    // Research: stored as full option text like "Book Chapter (Sole Author) (10pts)"
    // strip the pts suffix for display
    if (kra === 'Research') {
        return type.replace(/\s*\(\d+(?:\.\d+)?\s*pts?\)\s*$/i, '').trim() || type || 'Entry';
    }

    // Instruction B/C: "B|16|Manual / Module, Sole Author" → "Manual / Module, Sole Author"
    if (type.startsWith('B|') || type.startsWith('C|')) {
        return type.split('|').slice(2).join('|') || type;
    }

    // Instruction A types
    const instrMap = {
        'A-set-sef':     'Teaching Effectiveness (SET + SEF)',
        'A-set-sef-sem': 'Teaching Effectiveness (SET + SEF)',
        'A-set-sem':     'Teaching Effectiveness (SET)',
        'A-sef-sem':     'Teaching Effectiveness (SEF)',
        'B-material':    'Instructional Material',
        'C-thesis':      'Thesis/Dissertation Adviser',
        'C-mentor':      'Thesis/Dissertation Co-Adviser',
    };
    if (instrMap[type]) return instrMap[type];

    // Extension / PD: use description from parts[1] if available
    if (parts[1] && parts[1].trim()) return parts[1].trim();

    // Custom entry
    if (type === 'CUSTOM') return parts[3] || 'Custom Entry';

    // Extension subtypes — strip slug
    return type.replace(/[-_]/g, ' ').replace(/\b\w/g, c => c.toUpperCase()) || 'Entry';
}
</script>


<script>
const KRA_CAT    = '<?= addslashes($cur_cat) ?>';
const APP_ID     = <?= $app_id ?>;

// -- Criterion stepper ----------------------------------------
const _criterionHeaders = <?= json_encode(array_values(array_map(fn($g) => $g['header'], $dark_groups))) ?>;
function goCriterion(idx) {
    const steps   = document.querySelectorAll('.criterion-step');
    const pills   = document.querySelectorAll('[id^="criterionPill_"]');
    const label   = document.getElementById('criterionStepLabel');
    steps.forEach((s, i) => s.style.display = i === idx ? 'block' : 'none');
    pills.forEach((p, i) => {
        p.style.background   = i === idx ? '#1e3a5f' : '#fff';
        p.style.color        = i === idx ? '#fff'    : '#64748b';
        p.style.borderColor  = i === idx ? '#1e3a5f' : '#e2e8f0';
    });
    if (label && _criterionHeaders[idx]) label.textContent = _criterionHeaders[idx];
    // Scroll to top of the criteria area
    const nav = document.getElementById('criterionStepperNav');
    if (nav) nav.scrollIntoView({ behavior: 'smooth', block: 'start' });
}

// -- Upload success animation ----------------------------------
function showUploadSuccess(filename) {
    const overlay = document.createElement('div');
    overlay.className = 'upload-success-overlay';
    overlay.innerHTML = `
        <div class="upload-success-box" id="successBox">
            <svg class="checkmark-svg" width="64" height="64" viewBox="0 0 52 52">
                <circle cx="26" cy="26" r="24"/>
                <path d="M14 26 l8 8 l16-16"/>
            </svg>
            <div class="upload-success-label">File Uploaded!</div>
            <div class="upload-success-sub">${filename ? filename : 'Evidence saved successfully'}</div>
        </div>`;
    document.body.appendChild(overlay);
    setTimeout(() => {
        const box = overlay.querySelector('#successBox');
        if (box) box.classList.add('fade-out');
        setTimeout(() => overlay.remove(), 380);
    }, 1800);
}

// -- Done button validation ------------------------------------
async function handleDone() {
    const tbody = document.getElementById('kraTableBody');
    if (!tbody) { window.location.href = 'index.php?page=apply&tab=' + ACTIVE_TAB; return; }

    const rows = tbody.querySelectorAll('tr[data-sid]');
    const issues = [];

    if (isCriteriaATableMode()) {
        const aRows = criteriaARows();
        aRows.forEach((tr, idx) => {
            const rowNum = idx + 1;
            const type = tr.dataset.entryType === 'sef' ? 'sef' : 'set';
            const typeLabel = type.toUpperCase();
            const period = tr.querySelector('.kra-a-period')?.value.trim();
            if (!period) issues.push(`Row ${rowNum}: Evaluation period is required.`);
            [1, 2].forEach(sem => {
                const scoreEl = tr.querySelector(type === 'sef' ? `.kra-a-sef.sem-${sem}` : `.kra-a-set.sem-${sem}`);
                const upBtn = tr.querySelector(`[data-sem="${sem}"]`);
                const semName = sem === 1 ? '1st Semester' : '2nd Semester';
                const existingSid = parseInt(upBtn?.dataset.sid || '0') > 0;
                const hasEvidence = upBtn?.classList.contains('has-file');
                const scoreVal = scoreEl?.value.trim() || '';
                // 2nd semester is optional  -  only validate if user typed something or a record already exists
                const semHasData = sem === 1 || existingSid || hasEvidence || scoreVal !== '';
                if (!semHasData) return; // skip empty optional 2nd semester
                if (!scoreVal) issues.push(`Row ${rowNum}: ${semName} ${typeLabel} score is required.`);
                const scoreNum = parseFloat(scoreVal);
                if (scoreVal && (!Number.isFinite(scoreNum) || scoreNum < 0 || scoreNum > 100)) issues.push(`Row ${rowNum}: ${semName} ${typeLabel} score must be 0 to 100.`);
                if (!hasEvidence) issues.push(`Row ${rowNum}: ${semName} evidence file is required.`);
            });
        });
    }

    rows.forEach((tr, idx) => {
        const rowNum = idx + 1;
        const sid = tr.dataset.sid;

        // Check evidence uploaded
        const upBtn = tr.querySelector('[onclick*="openUploadModal"]');
        const hasFile = upBtn && upBtn.classList.contains('has-file');
        if (!hasFile) {
            issues.push(`Row ${rowNum}: Missing evidence file.`);
        }

        // Check required fields are filled
        // Criterion selector (all KRAs have one)
        const critSel = tr.querySelector('.ri-crit, .rtype, .rpd-crit, .re-crit');
        if (critSel && !critSel.value) {
            issues.push(`Row ${rowNum}: Please select a criterion.`);
        }

        // Extension: title/designation name required
        const extTitleEl = tr.querySelector('.re-title');
        if (extTitleEl && !extTitleEl.value.trim()) {
            issues.push(`Row ${rowNum}: Activity / designation name is required.`);
        }

        // For B-material and C-thesis: sub-dropdown must also be selected
        const subSel = tr.querySelector('.ri-d1-sel');
        if (subSel && !subSel.value) {
            issues.push(`Row ${rowNum}: Please select a material/role type from the details dropdown.`);
        }

        // Research: developers required
        const devEl = tr.querySelector('.rdev');
        if (devEl && !devEl.value.trim()) {
            issues.push(`Row ${rowNum}: Developer(s) is required.`);
        }

        // Prof Dev: description required
        const descEl = tr.querySelector('.rpd-desc');
        if (descEl && !descEl.value.trim()) {
            issues.push(`Row ${rowNum}: Description / title is required.`);
        }

        // Instruction A: SET and SEF required
        const setEl = tr.querySelector('.ri-d1[placeholder*="SET"]');
        const sefEl = tr.querySelector('.ri-d2[placeholder*="SEF"]');
        if (setEl && !setEl.value.trim()) issues.push(`Row ${rowNum}: SET average is required.`);
        if (sefEl && !sefEl.value.trim()) issues.push(`Row ${rowNum}: SEF average is required.`);
    });

    if (issues.length > 0) {
        // Shake the Done button and switch to warning style
        const btn = document.getElementById('doneBtn');
        btn.style.background = '#475569';
        btn.innerHTML = '<i class="bi bi-exclamation-triangle"></i>Fix issues first';
        btn.classList.add('btn-shake');
        setTimeout(() => btn.classList.remove('btn-shake'), 400);
        setTimeout(() => {
            btn.style.background = '#1e4d8c';
            btn.innerHTML = '<i class="bi bi-check2-circle"></i>Done';
        }, 2500);

        // Group issues by row number for a cleaner display
        const grouped = {};
        issues.forEach(msg => {
            const m = msg.match(/^Row (\d+): (.+)$/);
            if (m) {
                const row = 'Row ' + m[1];
                if (!grouped[row]) grouped[row] = [];
                grouped[row].push(m[2]);
            } else {
                if (!grouped['General']) grouped['General'] = [];
                grouped['General'].push(msg);
            }
        });
        let html = '<div style="font-weight:700;margin-bottom:0.4rem;">Please fix the following before closing:</div>';
        Object.entries(grouped).forEach(([row, msgs]) => {
            html += `<div style="margin-bottom:0.25rem;">
                <span style="font-weight:600;color:#475569;">${row}</span>
                <ul style="margin:2px 0 0 1rem;padding:0;list-style:disc;">
                    ${msgs.map(m => `<li style="color:#334155;">${m}</li>`).join('')}
                </ul>
            </div>`;
        });
        showKraAlert(html);
        return;
    }

    if (isCriteriaATableMode()) {
        try {
            // "Done" must persist every completed semester before navigating away,
            // otherwise typed ratings would be discarded on reload. persistCriteriaARow
            // writes each semester as its own record, so neither one clears the other.
            for (const tr of tbody.querySelectorAll('tr.kra-a-row')) {
                await persistCriteriaARow(tr);
            }
        } catch (err) {
            showKraAlert(esc(err.message || 'Unable to save Criteria A entries.'));
            return;
        }
    } else {
        try {
            for (const tr of rows) {
                await persistGenericKraRow(tr);
            }
        } catch (err) {
            showKraAlert(esc(err.message || 'Unable to save this row.'));
            return;
        }
    }

    // All good - close modal and reload staying on current tab
    const modal = bootstrap.Modal.getInstance(document.getElementById('kraEntryModal'));
    if (modal) modal.hide();
    window.location.href = 'index.php?page=apply&tab=' + ACTIVE_TAB;
}

const ACTIVE_TAB  = '<?= $active_tab ?>';
const AJAX_URL    = 'backend/api/kra_ajax.php';
const SCORE_URL   = 'index.php?page=apply&ajax_score=1&app_id=<?= $app_id ?>';

// Backdrop click to close submit modal (needs AJAX_URL in scope for buildSubmitReview)
document.getElementById('submitConfirmModal').addEventListener('click', function(e) {
    if (e.target === this) this.style.display = 'none';
});
const EDIT_SID    = <?= intval($_GET['edit_sid'] ?? 0) ?>;
// Cycle-based Evaluation Period generation for KRA I Criterion A
// Derived from the active cycle's start_date; 0 = no cycle / fallback to current year
const CYCLE_START_YEAR = <?= !empty($cycle['start_date']) ? (int)date('Y', strtotime($cycle['start_date'])) : date('Y') ?>;
const CYCLE_ROW_LIMIT  = 3;
const KRA1_A_SET_CAP   = <?= json_encode((float)$kra1_a_caps['set']) ?>;
const KRA1_A_SEF_CAP   = <?= json_encode((float)$kra1_a_caps['sef']) ?>;
<?php
$cjs = [];
foreach ($criteria_list as $c) $cjs[] = ['label'=>$c['criterion_label'],'pts'=>(float)$c['max_points']];
echo 'const CRIT = ' . json_encode($cjs) . ';';
?>

function kraEntryScrollStorageKey() {
    return `sucfrms.kra_entry.scroll.${APP_ID}.${ACTIVE_TAB}`;
}

function rememberKraEntryScroll() {
    try {
        sessionStorage.setItem(kraEntryScrollStorageKey(), JSON.stringify({
            x: window.scrollX || window.pageXOffset || 0,
            y: window.scrollY || window.pageYOffset || 0,
            t: Date.now()
        }));
    } catch (_) {}
}

function restoreKraEntryScroll() {
    try {
        const key = kraEntryScrollStorageKey();
        const saved = JSON.parse(sessionStorage.getItem(key) || 'null');
        sessionStorage.removeItem(key);
        if (!saved || Date.now() - saved.t > 30000) return;
        const restore = () => window.scrollTo(saved.x || 0, saved.y || 0);
        [0, 50, 150, 350, 700, 1200].forEach(delay => setTimeout(restore, delay));
        requestAnimationFrame(() => requestAnimationFrame(restore));
    } catch (_) {}
}

restoreKraEntryScroll();

// -- Live sidebar score refresh --------------------------------
function refreshSidebarScore() {
    fetch(SCORE_URL)
        .then(r => r.json())
        .then(data => {
            // Weighted score
            const ws = data.weighted ?? 0;
            const el = document.getElementById('sidebarWeightedScore');
            if (el) el.textContent = ws.toFixed(2);
            const bar = document.getElementById('sidebarScoreBar');
            if (bar) bar.style.width = Math.min(100, ws) + '%';
            const sr = document.getElementById('sidebarSubRank');
            if (sr) sr.textContent = data.sub_rank > 0 ? `+${data.sub_rank} sub-rank${data.sub_rank > 1 ? 's' : ''}` : '';

            // Per-KRA rows
            const slugMap = {
                'Instruction': 'instruction',
                'Research': 'research',
                'Extension': 'extension',
                'Professional Development': 'professionaldevelopment'
            };
            Object.entries(data.kra ?? {}).forEach(([cat, wval]) => {
                const slug = slugMap[cat] || '';
                const kEl  = document.getElementById('sidebarKra_' + slug);
                const kBar = document.getElementById('sidebarKraBar_' + slug);
                const raw  = data.kra_raw?.[cat] ?? 0;
                if (kEl)  kEl.textContent  = wval.toFixed(1);
                if (kBar) kBar.style.width = Math.min(100, raw) + '%';
            });
        })
        .catch(() => {}); // silent fail ... non-critical
}

let tempId = -1;
let currentUploadSid = 0;
let currentUploadTr  = null;
let currentUploadBtn = null;
let activeKraCriterion = '';
let skipNextModalLoad = false;
let pendingCriteriaAEditEntry = null;
let criteriaARequestToken = 0; // guards against a stale loadCriteriaARows() fetch
                                // resolving after the user has switched criteria
let pendingLegacyCriteriaAEditEntry = null;
// When set to 'set' or 'sef', only that section is visible in the Criteria A modal.
// Cleared on modal close so the next open shows both sections by default.
let pendingCriteriaAFocusType = null;

function setKraModalCriterion(letter) {
    activeKraCriterion = letter || '';
}

function isCriteriaATableMode() {
    return KRA_CAT === 'Instruction' && activeKraCriterion === 'A';
}

// If the modal is still open or mid fade-in/out, opening another criterion
// straight away is ignored by Bootstrap and the old criterion's state/layout
// gets stuck. Wait for it to fully close, then run the open call again.
function deferUntilKraModalClosed(fn) {
    const modal = document.getElementById('kraEntryModal');
    if (!modal) return false;
    const busy = modal.classList.contains('show') || modal.classList.contains('showing')
              || modal.classList.contains('modal-static') || modal.style.display === 'block';
    if (!busy) return false;
    modal.addEventListener('hidden.bs.modal', () => fn(), { once: true });
    bootstrap.Modal.getInstance(modal)?.hide();
    return true;
}

function openCriterionEntry(letter) {
    if (deferUntilKraModalClosed(() => openCriterionEntry(letter))) return;
    if (!pendingKraCustomCrit) activeKraCustomCrit = null;
    setKraModalCriterion(letter);
    const modal = document.getElementById('kraEntryModal');
    if (!modal) return;

    if (isCriteriaATableMode()) {
        // Open and populate the semester grid directly. This prevents the
        // generic Instruction table from replacing it during modal startup.
        skipNextModalLoad = true;
        setKraTableHeaderForCriteriaA();
        const bsModal = bootstrap.Modal.getOrCreateInstance(modal);
        bsModal.show();
        loadCriteriaARows();
        return;
    }

    // Any other criterion uses the generic table - put the original header,
    // footer and rows back before the modal becomes visible.
    skipNextModalLoad = false;
    setKraTableHeaderForDefault();
    const _gb = document.getElementById('kraTableBody');
    if (_gb) _gb.innerHTML = '';   // never show the previous criterion's rows
    bootstrap.Modal.getOrCreateInstance(modal).show();
}

// When set, buildRow() for Instruction pre-selects this flat crit value
// and hides the dropdown (shows a read-only label instead).
let pendingKraFlatCrit = null;

// When set, buildRow() for Research pre-selects this {pts, label} pair.
let pendingKraResCrit = null;   // {pts: number, label: string}

// When set, buildRow() for Extension pre-selects this subtype string.
let pendingKraExtSubtype = null;

// When set, buildRow() for PD pre-selects this {type, sub} pair.
let pendingKraPdCrit = null;    // {type: string, sub: string}

// When set, buildRow() renders a generic custom criterion entry.
let pendingKraCustomCrit = null; // {letter, key, label, pts}
let activeKraCustomCrit = null;

// Like openCriterionEntry but also focuses a specific sub-section ('set' or 'sef')
// so the modal only shows that one section when it opens.
function openCriterionEntryType(letter, type) {
    if (deferUntilKraModalClosed(() => openCriterionEntryType(letter, type))) return;
    pendingCriteriaAFocusType = (type === 'sef') ? 'sef' : 'set';
    openCriterionEntry(letter);
}

// Like openCriterionEntry but pre-selects a specific criterion so the dropdown
// is bypassed. `critObj` is a plain object with a 'kra' discriminator:
//   {kra:'instruction', flat: '...' }
//   {kra:'research',    label: '...', pts: N }
//   {kra:'extension',   subtype: '...' }
//   {kra:'pd',          type: '...', sub: '...' }
//   {kra:'custom',      letter: '...', key: '...', label: '...', pts: N }
function openCriterionEntryWithCrit(letter, critObj) {
    if (deferUntilKraModalClosed(() => openCriterionEntryWithCrit(letter, critObj))) return;
    if (!critObj) { openCriterionEntry(letter); return; }
    if (critObj.kra === 'instruction') {
        activeKraCustomCrit = null;
        pendingKraFlatCrit = critObj.flat || null;
    } else if (critObj.kra === 'research') {
        activeKraCustomCrit = null;
        pendingKraResCrit = { pts: critObj.pts, label: critObj.label || '' };
    } else if (critObj.kra === 'extension') {
        activeKraCustomCrit = null;
        pendingKraExtSubtype = critObj.subtype || null;
    } else if (critObj.kra === 'pd') {
        activeKraCustomCrit = null;
        pendingKraPdCrit = { type: critObj.type || '', sub: String(critObj.sub ?? '') };
    } else if (critObj.kra === 'custom') {
        activeKraCustomCrit = {
            letter: critObj.letter || letter || '',
            key: critObj.key || '',
            label: critObj.label || 'Custom criterion',
            pts: parseFloat(critObj.pts || 0) || 0
        };
        pendingKraCustomCrit = activeKraCustomCrit;
    }
    openCriterionEntry(letter);
}

function normalizeStoredDisplayText(value) {
    return String(value || '')
        .replace(/\\u2014/g, '-')
        .replace(/[–—]/g, '-')
        .replace(/â\s*[€“”]/g, '-')
        .replace(/\\u00d7/gi, 'x');
}

function critTypeFromRemarks(remarks) {
    return normalizeStoredDisplayText(String(remarks || '').split('|||')[0] || '');
}

function criterionLetterFromEntry(entry) {
    const ct = critTypeFromRemarks(entry?.remarks || '');
    if (ct === 'CUSTOM') {
        return String(entry?.remarks || '').split('|||')[1] || '';
    }
    if (['A-set-sef', 'A-set-sef-sem', 'A-set-sem', 'A-sef-sem'].includes(ct)) return 'A';
    if (ct === 'B-material' || ct.startsWith('B|')) return 'B';
    if (ct === 'C-thesis' || ct === 'C-mentor' || ct.startsWith('C|')) return 'C';
    return '';
}

function isBlankKraEntry(entry) {
    const remarks = String(entry?.remarks || '').trim();
    const score = parseFloat(entry?.computed_points || 0) || 0;
    return remarks === '' && score === 0;
}

function entryCriterionLetter(entry) {
    const remarks = String(entry?.remarks || '');
    const parts = remarks.split('|||');
    if ((parts[0] || '') === 'CUSTOM') return (parts[1] || '').toUpperCase();
    if (KRA_CAT === 'Instruction') {
        return criterionLetterFromEntry(entry);
    }
    if (KRA_CAT === 'Research') {
        const m = String(parts[0] || '').match(/^Criterion\s+([A-Z])/i);
        return m ? m[1].toUpperCase() : '';
    }
    if (KRA_CAT === 'Extension') {
        const subtype = parts[0] || '';
        const found = ALL_EXT_CRIT_OPTS.find(([, value]) => value === subtype);
        return found ? found[0] : '';
    }
    if (KRA_CAT === 'Professional Development') {
        return parts[0] ? parts[0].charAt(0).toUpperCase() : '';
    }
    return '';
}

function visibleEntriesForActiveCriterion(entries) {
    const usable = (entries || []).filter(e => !isBlankKraEntry(e));
    if (isCriteriaATableMode()) return usable;
    if (!activeKraCriterion) {
        return KRA_CAT === 'Instruction'
            ? usable.filter(e => entryCriterionLetter(e) !== 'A')
            : usable;
    }
    return usable.filter(e => entryCriterionLetter(e) === activeKraCriterion);
}

function criteriaARows() {
    return Array.from(document.querySelectorAll('#criteriaASections tr.kra-a-row'));
}

function criteriaABodyForType(type) {
    return document.getElementById(type === 'sef' ? 'criteriaASefBody' : 'criteriaASetBody');
}

function criteriaAEmptyRow(type) {
    const label = type === 'sef' ? 'SEF' : 'SET';
    return `<tr class="kra-a-empty-row"><td colspan="8" class="text-center text-muted py-12"><i class="bi bi-inbox fs-2 d-block mb-2 text-secondary"></i>No ${label} entries yet. Click <strong>Add Entry</strong> to add a row.</td></tr>`;
}

function setKraTableHeaderForCriteriaA() {
    captureKraTableDefaults();
    const headRow = document.querySelector('#kraTable thead tr');
    if (!headRow) return;
    document.querySelector('#kraTable')?.closest('.table-responsive')?.style.setProperty('display', 'none');
    const criteriaASections = document.getElementById('criteriaASections');
    if (criteriaASections) criteriaASections.style.display = 'block';
    // Generic Instruction rows must not survive into the semester grid.
    document.querySelectorAll('#kraTableBody tr:not(.kra-a-row)').forEach(tr => tr.remove());
    const footRow = document.querySelector('#kraTable tfoot tr');
    if (footRow) {
        footRow.innerHTML = `<td colspan="8"></td>`;
    }
    // Sync + button state with rows already rendered (e.g. draft restore)
    setTimeout(refreshAddRowButton, 0);
}

function captureKraTableDefaults() {
    const headRow = document.querySelector('#kraTable thead tr');
    const footRow = document.querySelector('#kraTable tfoot tr');
    if (headRow && !headRow.dataset.defaultHtml) headRow.dataset.defaultHtml = headRow.innerHTML;
    if (footRow && !footRow.dataset.defaultHtml) footRow.dataset.defaultHtml = footRow.innerHTML;
}

function setKraTableHeaderForDefault() {
    captureKraTableDefaults();
    document.querySelector('#kraTable')?.closest('.table-responsive')?.style.removeProperty('display');
    const criteriaASections = document.getElementById('criteriaASections');
    if (criteriaASections) criteriaASections.style.display = 'none';
    const headRow = document.querySelector('#kraTable thead tr');
    const footRow = document.querySelector('#kraTable tfoot tr');
    if (headRow && headRow.dataset.defaultHtml) headRow.innerHTML = headRow.dataset.defaultHtml;
    if (footRow && footRow.dataset.defaultHtml) footRow.innerHTML = footRow.dataset.defaultHtml;
    // Criteria A leaves behind its own row markup; clear it so the generic
    // Instruction table never renders on top of semester rows.
    document.querySelectorAll('#kraTableBody tr.kra-a-row').forEach(tr => tr.remove());
    document.querySelectorAll('#criteriaASections tbody').forEach(tbody => { tbody.innerHTML = ''; });
    pendingCriteriaAEditEntry = null;
}

function makeEmptySemester(period, sem, type = 'set') {
    const remarksType = type === 'sef' ? 'A-sef-sem' : 'A-set-sem';
    return { submission_id: tempId--, computed_points: 0, remarks: `${remarksType}|||${period}|||${sem}|||`, evidence_files: [], set: '', sef: '', sem, entry_type: type };
}

function parseCriteriaAEntries(entries) {
    const groups = new Map();
    const sourceEntries = [...(entries || [])];
    if (pendingCriteriaAEditEntry && !sourceEntries.some(e => parseInt(e.submission_id) === parseInt(pendingCriteriaAEditEntry.submission_id))) {
        sourceEntries.push(pendingCriteriaAEditEntry);
    }
    sourceEntries.forEach(e => {
        const parts = String(e.remarks || '').split('|||');
        const ct = parts[0] || '';
        if (!['A-set-sef', 'A-set-sef-sem', 'A-set-sem', 'A-sef-sem'].includes(ct)) return;

        let period = '';
        let sem = 1;
        let set = '';
        let sef = '';
        let types = ['set', 'sef'];
        if (ct === 'A-set-sem' || ct === 'A-sef-sem') {
            period = (parts[1] || '').trim() || 'AY';
            sem = String(parts[2] || '1') === '2' ? 2 : 1;
            if (ct === 'A-set-sem') { set = parts[3] || ''; types = ['set']; }
            if (ct === 'A-sef-sem') { sef = parts[3] || ''; types = ['sef']; }
        } else if (ct === 'A-set-sef-sem') {
            period = (parts[1] || '').trim() || 'AY';
            sem = String(parts[2] || '1') === '2' ? 2 : 1;
            set = parts[3] || '';
            sef = parts[4] || '';
        } else {
            period = (parts[3] || '').trim() || 'Evaluation Period';
            sem = 1;
            set = parts[1] || '';
            sef = parts[2] || '';
        }

        types.forEach(type => {
            const semesterEntry = { ...e, set: type === 'set' ? set : '', sef: type === 'sef' ? sef : '', sem, entry_type: type };
            const slot = sem === 2 ? 'second' : 'first';
            const key = `${type}#${period}`;
            let target = groups.get(key);
            if (!target) {
                target = { period, type, first: null, second: null };
                groups.set(key, target);
            }
            target[slot] = semesterEntry;
        });
    });

    return Array.from(groups.values()).map(g => {
        g.first = g.first || makeEmptySemester(g.period, 1, g.type);
        g.second = g.second || makeEmptySemester(g.period, 2, g.type);
        return g;
    }).sort((a, b) => (a.type === b.type ? a.period.localeCompare(b.period) : (a.type === 'set' ? -1 : 1)));
}

// Values typed into the semester grid that have not reached the database yet.
// A reload (triggered by an evidence upload, for instance) would otherwise wipe
// whichever semester had not been saved.
let criteriaADraftCache = null;

function snapshotCriteriaADraft() {
    const rows = criteriaARows();
    if (!rows.length) return null;
    return Array.from(rows).map(tr => ({
        type: tr.dataset.entryType || 'set',
        period: tr.querySelector('.kra-a-period')?.value.trim() || '',
        s1set: tr.querySelector('.kra-a-set.sem-1')?.value.trim() || '',
        s1sef: tr.querySelector('.kra-a-sef.sem-1')?.value.trim() || '',
        s2set: tr.querySelector('.kra-a-set.sem-2')?.value.trim() || '',
        s2sef: tr.querySelector('.kra-a-sef.sem-2')?.value.trim() || '',
    }));
}

// Restore a typed value only where the freshly loaded row is blank, so saved
// database values always win and nothing the user typed is silently dropped.
function restoreCriteriaADraft(draft) {
    if (!draft || !draft.length) return;
    const rows = criteriaARows();
    const used = new Set();
    rows.forEach(tr => {
        const period = tr.querySelector('.kra-a-period')?.value.trim() || '';
        const type = tr.dataset.entryType || 'set';
        let idx = draft.findIndex((d, i) => !used.has(i) && d.period === period && (d.type || 'set') === type);
        if (idx === -1) return;
        used.add(idx);
        const d = draft[idx];
        [['.kra-a-set.sem-1', d.s1set], ['.kra-a-sef.sem-1', d.s1sef],
         ['.kra-a-set.sem-2', d.s2set], ['.kra-a-sef.sem-2', d.s2sef]].forEach(([sel, val]) => {
            const inp = tr.querySelector(sel);
            if (inp && !inp.value.trim() && val) inp.value = val;
        });
    });
    // Rows that were added but never saved are not returned by the server.
    draft.forEach((d, i) => {
        if (used.has(i)) return;
        if (!d.s1set && !d.s1sef && !d.s2set && !d.s2sef) return;
        const period = d.period || `AY ${new Date().getFullYear()}-${new Date().getFullYear() + 1}`;
        const type = d.type || (d.s1sef || d.s2sef ? 'sef' : 'set');
        const tbody = criteriaABodyForType(type);
        if (!tbody) return;
        tbody.querySelector('.kra-a-empty-row')?.remove();
        const group = { period, type, first: makeEmptySemester(period, 1, type), second: makeEmptySemester(period, 2, type) };
        group.first.set = d.s1set; group.first.sef = d.s1sef;
        group.second.set = d.s2set; group.second.sef = d.s2sef;
        const tr = document.createElement('tr');
        tr.className = 'kra-a-row';
        tr.dataset.entryType = type;
        tr.innerHTML = buildCriteriaARow(group, criteriaARowCount(type) + 1);
        tbody.appendChild(tr);
    });
    updateCriteriaASummary();
}

function criteriaASemesterLocked(entry) {
    return !!(entry && entry.verified && entry.revision_status !== 'needs_revision');
}

function criteriaAUploadButton(entry, sem) {
    const evFiles = entry.evidence_files || [];
    const hasFiles = evFiles.length > 0 || (entry.document_path && entry.document_path !== '');
    const fileCount = evFiles.length || (entry.document_path ? 1 : 0);
    const uploadClass = hasFiles ? 'btn btn-sm btn-outline-success kra-upload-btn has-file' : 'btn btn-sm btn-outline-secondary kra-upload-btn';
    const uploadIcon = hasFiles ? 'bi-file-earmark-check-fill' : 'bi-cloud-upload';
    const uploadTitle = hasFiles ? `${fileCount} file(s) uploaded - click to manage` : `${sem === 1 ? '1st' : '2nd'} Semester evidence`;
    const firstDoc = evFiles.length > 0 ? evFiles[0].file_path : (entry.document_path || '');
    const semLabel = sem === 1 ? '1st Sem' : '2nd Sem';
    const locked = criteriaASemesterLocked(entry);
    return `<div class="kra-a-evidence sem-${sem}">
            <button type="button" class="${uploadClass}" onclick="${locked ? '' : 'openUploadModal(this)'}"
                data-sid="${entry.submission_id || 0}" data-doc="${esc(firstDoc)}" data-sem="${sem}" title="${locked ? 'Acceptable - locked' : uploadTitle}"
                ${locked ? 'disabled' : ''}>
                <i class="bi ${locked ? 'bi-lock-fill' : uploadIcon}"></i>${fileCount > 1 ? ` <span class="badge bg-light text-dark" style="font-size:0.65rem;">${fileCount}</span>` : ''}
            </button>
            <span class="kra-a-evidence-tag">${semLabel}${locked ? ' <i class="bi bi-patch-check-fill" style="color:#22c55e;" title="Marked acceptable by evaluator"></i>' : ''}</span>
        </div>`;
}

function criteriaAScoreFields(entry, sem, type) {
    const locked = criteriaASemesterLocked(entry);
    const dis = locked ? 'disabled' : '';
    const cls = type === 'sef' ? 'kra-a-sef' : 'kra-a-set';
    const value = type === 'sef' ? (entry.sef || '') : (entry.set || '');
    const label = type === 'sef' ? 'SEF:' : 'SET:';
    return `<div class="kra-a-scores sem-${sem}" style="display:flex;align-items:center;justify-content:center;min-height:58px;max-width:120px;margin:0 auto;">
        <label style="display:flex;align-items:center;gap:5px;margin:0;font-size:0.74rem;color:#64748b;font-weight:700;">
            <span style="width:28px;">${label}</span>
            <input type="text" inputmode="decimal" class="${cls} sem-${sem}" value="${esc(value)}" placeholder="0.00" oninput="calcCriteriaARow(this)"
                   style="width:76px;text-align:center;" ${dis}>
        </label>
    </div>`;
}

function buildCriteriaARow(group, num) {
    const first = group.first;
    const second = group.second;
    const type = group.type === 'sef' ? 'sef' : 'set';
    const isSef = type === 'sef';
    const badgeBg = isSef ? '#ccfbf1' : '#dbeafe';
    const badgeColor = isSef ? '#0f766e' : '#1e4d8c';
    const typeLabel = isSef ? 'SEF' : 'SET';
    return `
        <td style="padding:0.5rem 1rem;"></td>
        <td style="padding:0.5rem 0.5rem;color:#94a3b8;font-size:0.75rem;font-weight:600;">${num}<div style="margin-top:4px;"><span style="display:inline-flex;align-items:center;justify-content:center;padding:2px 7px;border-radius:999px;background:${badgeBg};color:${badgeColor};font-size:0.64rem;font-weight:800;">${typeLabel}</span></div></td>
        <td style="padding:0.5rem 0.75rem;">
            <input class="kra-a-period" value="${esc(group.period || '')}" placeholder="AY 2023-2024" oninput="syncCriteriaAPeriod(this)"
                   readonly style="min-width:145px;font-weight:600;color:#1e293b;background:#f1f5f9;cursor:default;border-color:#cbd5e1;" title="Auto-generated from cycle start year">
        </td>
        <td style="padding:0.55rem 0.55rem;text-align:center;">${criteriaAScoreFields(first, 1, type)}</td>
        <td style="padding:0.55rem 0.45rem;text-align:center;">${criteriaAUploadButton(first, 1)}</td>
        <td style="padding:0.55rem 0.55rem;text-align:center;">${criteriaAScoreFields(second, 2, type)}</td>
        <td style="padding:0.55rem 0.45rem;text-align:center;">${criteriaAUploadButton(second, 2)}</td>
        <td style="padding:0.5rem 0.75rem;text-align:center;">
            <div class="d-flex gap-1 justify-content-center">
                <button type="button" class="btn btn-success kra-action-btn" onclick="saveCriteriaARow(this)" title="Save evaluation period" style="width:36px;height:36px;padding:0;border-radius:6px;display:inline-flex;align-items:center;justify-content:center;">
                    <i class="bi bi-floppy"></i>
                </button>
                <button type="button" class="btn btn-danger kra-action-btn" onclick="deleteCriteriaARow(this)" title="Delete evaluation period" style="width:36px;height:36px;padding:0;border-radius:6px;display:inline-flex;align-items:center;justify-content:center;">
                    <i class="bi bi-trash"></i>
                </button>
            </div>
        </td>`;
}

// -- Extension (KRA III) criterion/subtype scheme -------------
// remarks format: subtype|||title|||val1|||val2  -  subtype text must contain
// the exact keywords kra3_scorer.php's isCritX()/scoreCritX() checks for.
const ALL_EXT_CRIT_OPTS = [
    ['A', 'moa-linkage',              'MOA/Linkage Established (5 pts each)'],
    ['A', 'income',                   'Income-Generating Project/Activity'],
    ['B', 'accredit-local',           'Accreditor/QA Evaluator - Local (8 pts)'],
    ['B', 'accredit-intl',            'Accreditor/QA Evaluator - International (10 pts)'],
    ['B', 'judge-research',           'Judge - Research/Competition (2 pts)'],
    ['B', 'judge-other',              'Judge - Other Competition (1 pt)'],
    ['B', 'consultant-local',         'Consultant/Technical Expert - Local (8 pts)'],
    ['B', 'consultant-intl',          'Consultant/Technical Expert - International (10 pts)'],
    ['B', 'media-column-regular',     'Regular Newspaper/Media Column (10 pts)'],
    ['B', 'media-column-occasional',  'Occasional Newspaper/Media Column (2 pts, max 5x)'],
    ['B', 'media-tv-radio-host',      'TV/Radio Program Host (10 pts)'],
    ['B', 'media-guest',              'TV/Radio Guest (1 pt, max 10x)'],
    ['B', 'resource-speaker-local',   'Resource Speaker/Facilitator - Local (2 pts/hour)'],
    ['B', 'resource-speaker-intl',    'Resource Speaker/Facilitator - International (3 pts/hour)'],
    ['B', 'outreach-isr-lead',        'ISR/Outreach Project - Head/Lead (5 pts, ISR cap 30)'],
    ['B', 'outreach-isr-member',      'ISR/Outreach Project - Member (2 pts, ISR cap 30)'],
    ['C', 'csr-satisfaction',         'CSR / Client Satisfaction Rating'],
    ['D', 'president',                'President (20 pts/year)'],
    ['D', 'vice-president',           'Vice-President (15 pts/year)'],
    ['D', 'chancellor',               'Chancellor (10 pts/year)'],
    ['D', 'vice-chancellor',          'Vice-Chancellor (8 pts/year)'],
    ['D', 'campus director',          'Campus Director/Administrator (8 pts/year)'],
    ['D', 'office director',          'Office Director (6 pts/year)'],
    ['D', 'dean',                     'Dean (6 pts/year)'],
    ['D', 'associate dean',           'Associate Dean (5 pts/year)'],
    ['D', 'dept head',                'Department Head (4 pts/year)'],
    ['D', 'program chair',            'Program Chair (3 pts/year)'],
    ['D', 'committee chair',          'Committee Chair (2 pts/year)'],
    ['D', 'committee member',         'Committee Member (1 pt/year)'],
    ['D', 'coordinator',              'Coordinator (2 pts/year)'],
];
const EXT_D_SUBTYPES = ALL_EXT_CRIT_OPTS.filter(([l]) => l === 'D').map(([,v]) => v);

// Extra (val1/val2) fields for the selected Extension subtype
function extExtraFieldsHtml(subtype, val1, val2) {
    if (subtype === 'income') {
        const roleOpts = [['lead','Lead Implementer'],['co-implementer','Co-Implementer']]
            .map(([v,l]) => `<option value="${v}" ${val2===v?'selected':''}>${l}</option>`).join('');
        return `<input type="text" inputmode="decimal" class="re-val1" value="${esc(val1||'')}" placeholder="Income amount (&#8369;)" oninput="calcRow(this)" style="margin-top:3px;width:100%;">
                <select class="re-val2" onchange="calcRow(this)" style="margin-top:3px;width:100%;">${roleOpts}</select>`;
    }
    if (subtype === 'csr-satisfaction') {
        return `<input type="text" inputmode="decimal" class="re-val1" value="${esc(val1||'')}" placeholder="CSR rating (0-100)" oninput="calcRow(this)" style="margin-top:3px;width:100%;">
                <div style="font-size:0.68rem;color:#94a3b8;margin-top:2px;">Title must match a Criterion B outreach/ISR entry for credit.</div>`;
    }
    if (subtype === 'resource-speaker-local' || subtype === 'resource-speaker-intl') {
        return `<input type="text" inputmode="decimal" class="re-val1" value="${esc(val1||'1')}" placeholder="Hours conducted" oninput="calcRow(this)" style="margin-top:3px;width:100%;">`;
    }
    if (EXT_D_SUBTYPES.includes(subtype)) {
        return `<input type="text" inputmode="numeric" class="re-val1" value="${esc(val1||'1')}" placeholder="Years served" oninput="calcRow(this)" style="margin-top:3px;width:100%;">`;
    }
    return '';
}

// Rebuild the val1/val2 fields when the Extension criterion/type changes
function updateExtDetailFields(sel) {
    const tr = sel.closest('tr');
    const wrap = tr.querySelector('.re-extra');
    if (!wrap) return;
    wrap.innerHTML = extExtraFieldsHtml(sel.value, '', '');
}

// -- Build row HTML --------------------------------------------
function buildRow(e, num) {
    const sid   = e.submission_id || 0;
    let score = parseFloat(e.computed_points || 0);
    const rem   = e.remarks || '';
    const doc   = e.document_path || '';
    const parts = rem.split('|||');

    let cells = `<td class="kra-entry-spacer"></td><td class="kra-entry-rownum">${num}</td>`;

    const customPending = (!parts[0] && (pendingKraCustomCrit || activeKraCustomCrit)) ? (pendingKraCustomCrit || activeKraCustomCrit) : null;
    if (customPending) pendingKraCustomCrit = null;
    const isCustomEntry = customPending || parts[0] === 'CUSTOM';

    if (isCustomEntry) {
        const customLetter = customPending?.letter || parts[1] || activeKraCriterion || '';
        const customKey    = customPending?.key || parts[2] || '';
        const customLabel  = customPending?.label || parts[3] || 'Custom criterion';
        const customPts    = parseFloat(customPending?.pts ?? parts[4] ?? score) || 0;
        const customDetail = parts[5] || '';
        if (score <= 0 && customPts > 0) score = customPts;

        cells += `
        <td style="padding:0.4rem;">
            <input type="hidden" class="custom-letter" value="${esc(customLetter)}">
            <input type="hidden" class="custom-key" value="${esc(customKey)}">
            <input type="hidden" class="custom-label" value="${esc(customLabel)}">
            <input type="hidden" class="custom-max" value="${esc(String(customPts))}">
            <div style="font-size:0.78rem;color:#1e293b;font-weight:700;line-height:1.3;">${esc(customLabel)}</div>
            <div style="font-size:0.72rem;color:#64748b;margin-top:0.15rem;">${customPts.toFixed(2)} pts</div>
        </td>
        <td style="padding:0.4rem;" colspan="2">
            <input class="custom-detail" value="${esc(customDetail)}" placeholder="Entry title / details / notes">
        </td>`;
    } else if (KRA_CAT === 'Research') {
        // parts[0] stores the full display text e.g. "Criterion A - Book, Sole Author (100pts)"
        // Strip the pts suffix for matching against c.label
        const storedLabel = (parts[0] || '').replace(/\s*\(\d+(\.\d+)?pts?\)\s*$/i, '').trim();

        // Determine active criterion letter for filtering
        // activeKraCriterion is set when opened via "Data Entry" button
        // For Edit, derive from the stored label
        function researchActiveLetter() {
            if (activeKraCriterion) return activeKraCriterion;
            // Derive from stored label: "Criterion A ...", "Criterion B ...", etc.
            const m = storedLabel.match(/^Criterion\s+([A-Z])/i);
            return m ? m[1].toUpperCase() : '';
        }
        const _resLetter = researchActiveLetter();

        // Filter CRIT to only show items belonging to the active criterion letter
        const filteredCrit = _resLetter
            ? CRIT.filter(c => {
                const m = String(c.label || '').match(/^Criterion\s+([A-Z])/i);
                return m ? m[1].toUpperCase() === _resLetter : true;
              })
            : CRIT;

        // If opened from a specific sub-item Data Entry button, pendingKraResCrit
        // holds the exact {pts, label} to pre-select. Consume it immediately.
        const _resPending = (pendingKraResCrit && !parts[0]) ? pendingKraResCrit : null;
        if (_resPending) pendingKraResCrit = null;

        // Strip "Criterion X  -  " prefix from labels when only one criterion shown
        const opts = filteredCrit.map(c => {
            const cleanLabel = _resLetter
                ? String(c.label || '').replace(/^Criterion\s+[A-Z]\s*[-\-]+\s*/i, '')
                : c.label;
            const isSelected = _resPending
                ? (c.label === _resPending.label)
                : optionSelectedFromStored(storedLabel, c.label, c.pts);
            return `<option value="${c.pts}" data-label="${esc(c.label)}" ${isSelected ? 'selected' : ''}>${cleanLabel} (${c.pts}pts)</option>`;
        }).join('');

        // When locked to a specific sub-item: show a hidden <select> (for
        // _buildRemarksFromRow / calcRow) + a visible read-only label.
        const _resLockedOpt  = _resPending
            ? filteredCrit.find(c => c.label === _resPending.label)
            : null;
        const _resLockedLabel = _resLockedOpt
            ? String(_resLockedOpt.label || '').replace(/^Criterion\s+[A-Z]\s*[-\-]+\s*/i, '')
            : '';
        const resSelectHtml = _resLockedOpt
            ? `<select class="rtype" data-sid="${sid}" style="display:none;" aria-hidden="true">
                 <option value="${esc(String(_resLockedOpt.pts))}" data-label="${esc(_resLockedOpt.label)}" selected>${esc(_resLockedLabel)} (${_resLockedOpt.pts}pts)</option>
               </select>
               <div style="font-size:0.78rem;color:#1e293b;font-weight:600;padding:0.1rem 0 0.3rem;line-height:1.3;">${esc(_resLockedLabel)}</div>`
            : `<select class="rtype" onchange="calcRow(this)" data-sid="${sid}"><option value="" disabled selected>- Select -</option>${opts}</select>`;

        cells += `
        <td class="kra-research-main">
            ${resSelectHtml}
            <div class="kra-research-detail-grid">
                <input class="rdev" value="${esc(parts[1]||'')}" placeholder="Developer(s) / author(s)">
                <input class="raff" value="${esc(parts[2]||'')}" placeholder="Affiliation">
                <input class="rarea" value="${esc(parts[3]||'')}" placeholder="Area">
            </div>
            <textarea class="rspec" rows="2" placeholder="Specific contribution">${esc(parts[4]||'')}</textarea>
        </td>
        <td class="kra-research-contrib"><input type="text" inputmode="decimal" class="rcontrib" value="${esc(parts[5]||'100')}" placeholder="e.g. 100" oninput="calcRow(this)"></td>`;
    } else if (KRA_CAT === 'Extension') {
        // remarks format: subtype|||title|||val1|||val2
        const subtype = parts[0] || '';
        const title   = parts[1] || '';
        const val1    = parts[2] || '';
        const val2    = parts[3] || '';

        // If opened from a specific sub-item Data Entry button, pendingKraExtSubtype
        // holds the exact subtype to pre-select. Consume it immediately.
        const _extPending = (pendingKraExtSubtype && !subtype) ? pendingKraExtSubtype : null;
        if (_extPending) pendingKraExtSubtype = null;

        // Effective subtype: pending (new row) or stored (edit)
        const effectiveSubtype = _extPending || subtype;

        // Which criterion letter is active: opened via a criterion's "Add Entry"
        // button (activeKraCriterion), or  -  when editing  -  derived from the
        // entry's own stored subtype.
        function extActiveLetter() {
            if (activeKraCriterion) return activeKraCriterion;
            const found = ALL_EXT_CRIT_OPTS.find(([, v]) => v === effectiveSubtype);
            return found ? found[0] : '';
        }
        const _extLetter = extActiveLetter();
        const filteredExtOpts = _extLetter
            ? ALL_EXT_CRIT_OPTS.filter(([l]) => l === _extLetter)
            : ALL_EXT_CRIT_OPTS;
        const extOpts = filteredExtOpts.map(([, v, lbl]) =>
            `<option value="${v}" ${effectiveSubtype===v?'selected':''}>${lbl}</option>`
        ).join('');

        // Locked: show hidden <select> + visible read-only label
        const _extLockedEntry = _extPending
            ? ALL_EXT_CRIT_OPTS.find(([, v]) => v === _extPending)
            : null;
        const extSelectHtml = _extLockedEntry
            ? `<select class="re-crit" style="display:none;" aria-hidden="true">
                 <option value="${esc(_extLockedEntry[1])}" selected>${esc(_extLockedEntry[2])}</option>
               </select>
               <div style="font-size:0.78rem;color:#1e293b;font-weight:600;padding:0.1rem 0 0.3rem;line-height:1.3;">${esc(_extLockedEntry[2])}</div>`
            : `<select class="re-crit" onchange="calcRow(this);updateExtDetailFields(this)"><option value="" disabled selected>- Select -</option>${extOpts}</select>`;

        cells += `
        <td style="padding:0.4rem;">${extSelectHtml}</td>
        <td style="padding:0.4rem;">
            <input class="re-title" value="${esc(title)}" placeholder="Activity / project / designation name">
            <div class="re-extra">${extExtraFieldsHtml(effectiveSubtype, val1, val2)}</div>
        </td>`;
    } else if (KRA_CAT === 'Professional Development') {
        // Criterion selector + dynamic sub-fields
        // remarks format: criterion_type|||description|||sub_value
        const critType = parts[0] || '';
        const desc     = parts[1] || '';
        const subVal   = parts[2] || '0';

        // If opened from a specific sub-item Data Entry button, pendingKraPdCrit
        // holds {type, sub} to pre-select. Consume it immediately.
        const _pdPending = (pendingKraPdCrit && !critType) ? pendingKraPdCrit : null;
        if (_pdPending) pendingKraPdCrit = null;

        // Effective values: pending (new row) or stored (edit)
        const effectivePdType = _pdPending ? _pdPending.type : critType;
        const effectivePdSub  = _pdPending ? _pdPending.sub  : subVal;

        const ALL_PD_CRIT_OPTS = [
            ['A-org',       'Criterion A - Professional Org Membership (5 pts each, max 20)'],
            ['B-training',  'Criterion B - Training/Conference Attended'],
            ['B-paper',     'Criterion B - Paper Presentation'],
            ['B-degree',    'Criterion B - Educational Qualification (Degree)'],
            ['C-award',     'Criterion C - Award / Recognition'],
        ];
        const _pdLetter = activeKraCriterion || (effectivePdType ? effectivePdType.charAt(0) : '');
        const filteredPdOpts = _pdLetter
            ? ALL_PD_CRIT_OPTS.filter(([v]) => v.startsWith(_pdLetter))
            : ALL_PD_CRIT_OPTS;
        const critOpts = filteredPdOpts
            .map(([v,l]) => `<option value="${v}" ${effectivePdType===v?'selected':''}>${l}</option>`).join('');

        // Locked: show hidden <select> + visible read-only label
        const _pdLockedEntry = _pdPending
            ? ALL_PD_CRIT_OPTS.find(([v]) => v === effectivePdType)
            : null;
        const pdSelectHtml = _pdLockedEntry
            ? `<select class="rpd-crit" style="display:none;" aria-hidden="true">
                 <option value="${esc(_pdLockedEntry[0])}" selected>${esc(_pdLockedEntry[1])}</option>
               </select>
               <div style="font-size:0.78rem;color:#1e293b;font-weight:600;padding:0.1rem 0 0.3rem;line-height:1.3;">${esc(_pdLockedEntry[1].replace(/^Criterion\s+[A-Z]\s*-\s*/i,''))}</div>`
            : `<select class="rpd-crit" onchange="calcRow(this);updatePdSubField(this)"><option value="" disabled selected>- Select -</option>${critOpts}</select>`;

        // Sub-value options  -  when locked and sub is pre-set, render hidden input + label
        let subField = '';
        if (effectivePdType === 'B-degree') {
            const degOpts = [['0','None (0 pts)'],['10','Post-Master\'s / Post-Doctoral (10 pts)'],['20','Additional Master\'s Degree (20 pts)'],['40','Doctorate / Additional Doctorate (40 pts)']]
                .map(([v,l]) => `<option value="${v}" ${effectivePdSub===v?'selected':''}>${l}</option>`).join('');
            if (_pdPending && effectivePdSub) {
                const degLabel = degOpts.match(new RegExp(`value="${effectivePdSub}"[^>]*>([^<]+)`))?.[1] || `${effectivePdSub} pts`;
                subField = `<input type="hidden" class="rpd-sub" value="${esc(effectivePdSub)}">
                            <span style="font-size:0.75rem;color:#475569;">${esc(degLabel)}</span>`;
            } else {
                subField = `<select class="rpd-sub" onchange="calcRow(this)">${degOpts}</select>`;
            }
        } else if (effectivePdType === 'B-training') {
            const tOpts = [['1','Local (1 pt)'],['2','International (2 pts)']]
                .map(([v,l]) => `<option value="${v}" ${effectivePdSub===v?'selected':''}>${l}</option>`).join('');
            if (_pdPending && effectivePdSub) {
                const tLabel = effectivePdSub === '2' ? 'International (2 pts)' : 'Local (1 pt)';
                subField = `<input type="hidden" class="rpd-sub" value="${esc(effectivePdSub)}">
                            <span style="font-size:0.75rem;color:#475569;">${tLabel}</span>`;
            } else {
                subField = `<select class="rpd-sub" onchange="calcRow(this)">${tOpts}</select>`;
            }
        } else if (effectivePdType === 'B-paper') {
            const pOpts = [['3','Local (3 pts)'],['5','International (5 pts)']]
                .map(([v,l]) => `<option value="${v}" ${effectivePdSub===v?'selected':''}>${l}</option>`).join('');
            if (_pdPending && effectivePdSub) {
                const pLabel = effectivePdSub === '5' ? 'International (5 pts)' : 'Local (3 pts)';
                subField = `<input type="hidden" class="rpd-sub" value="${esc(effectivePdSub)}">
                            <span style="font-size:0.75rem;color:#475569;">${pLabel}</span>`;
            } else {
                subField = `<select class="rpd-sub" onchange="calcRow(this)">${pOpts}</select>`;
            }
        } else if (effectivePdType === 'C-award') {
            const aOpts = [['2','Institutional (2 pts)'],['3','Local/Regional (3 pts)'],['4','National (4 pts)'],['5','International (pts pending — CONFIG GAP)'],['0','Prestigious Award — Sub-rank trigger only (0 pts)']]
                .map(([v,l]) => `<option value="${v}" ${effectivePdSub===v?'selected':''}>${l}</option>`).join('');
            if (_pdPending && effectivePdSub !== '') {
                const aLabels = {'2':'Institutional (2 pts)','3':'Local/Regional (3 pts)','4':'National (4 pts)','5':'International (CONFIG GAP)','0':'Prestigious Award — Sub-rank trigger'};
                subField = `<input type="hidden" class="rpd-sub" value="${esc(effectivePdSub)}">
                            <span style="font-size:0.75rem;color:#475569;">${aLabels[effectivePdSub] || effectivePdSub}</span>`;
            } else {
                subField = `<select class="rpd-sub" onchange="calcRow(this)">${aOpts}</select>`;
            }
        } else {
            // A-org: fixed 5 pts per org
            subField = `<input type="hidden" class="rpd-sub" value="1"><span class="text-muted small">5 pts</span>`;
        }

        cells += `
        <td style="padding:0.4rem;">${pdSelectHtml}</td>
        <td style="padding:0.4rem;"><input class="rpd-desc" value="${esc(desc)}" placeholder="Name / title / organization"></td>
        <td style="padding:0.4rem;">${subField}</td>`;
    } else {
        // KRA I - Instruction: Criterion selector
        // remarks format: criterion_type|||detail1|||detail2
        const critType = normalizeStoredDisplayText(parts[0] || '');
        const d1       = normalizeStoredDisplayText(parts[1] || '');
        const d2       = parts[2] || '';
        const notes    = parts[3] || '';

        // Flat criterion options  -  B and C sub-items shown directly
        const ALL_CRIT_OPTS = [
            ['A-set-sef',   'Criterion A - Teaching Effectiveness (SET + SEF)'],
            ['B|30|Textbook, Sole Author',                         'B - Textbook, Sole Author (30 pts)'],
            ['B|30co|Textbook, Co-Author',                         'B - Textbook, Co-Author (30 x contrib%)'],
            ['B|0|Textbook Chapter, Sole Author',                  'B - Textbook Chapter, Sole Author (Pending confirmation, not counted)'],
            ['B|0|Textbook Chapter, Co-Author',                    'B - Textbook Chapter, Co-Author (Pending confirmation, not counted)'],
            ['B|16|Manual / Module, Sole Author',                  'B - Manual / Module, Sole Author (16 pts)'],
            ['B|16co|Manual / Module, Co-Author',                  'B - Manual / Module, Co-Author (16 x contrib%)'],
            ['B|16|Multimedia Teaching Materials',                 'B - Multimedia Teaching Materials (16 pts)'],
            ['B|0|Validated Testing Materials',                    'B - Validated Testing Materials (Pending confirmation, not counted)'],
            ['B|10|Academic Program Dev/Revision, Lead',           'B - Academic Program Dev/Revision, Lead (10 pts)'],
            ['B|5|Academic Program Dev/Revision, Contributor',     'B - Academic Program Dev/Revision, Contributor (5 pts)'],
            ['C|3|Adviser: Special Project / Capstone',            'C - Adviser: Special Project / Capstone (3 pts)'],
            ['C|5|Adviser: Undergraduate Thesis',                  'C - Adviser: Undergraduate Thesis (5 pts)'],
            ['C|8|Adviser: Master\'s Thesis',                      "C - Adviser: Master's Thesis (8 pts)"],
            ['C|10|Adviser: Doctoral Dissertation',                'C - Adviser: Doctoral Dissertation (10 pts)'],
            ['C|1|Panel Member: Special Project / Capstone',       'C - Panel Member: Special Project / Capstone (1 pt)'],
            ['C|2|Panel Member: Undergraduate Thesis',             'C - Panel Member: Undergraduate Thesis (2 pts)'],
            ['C|4|Panel Member: Master\'s Thesis',                 "C - Panel Member: Master's Thesis (4 pts)"],
            ['C|6|Panel Member: Doctoral Dissertation',            'C - Panel Member: Doctoral Dissertation (6 pts)'],
            ['C|0|Mentor: Student/Team Competition Winner',        'C - Mentor: Student/Team Competition Winner (Pending confirmation, not counted)'],
        ];
        // Determine currently selected option from stored critType/d1 combo
        // Legacy stored as 'B-material' or 'C-thesis' with d1 being the label
        function matchFlatCrit(ct, storedD1) {
            if (ct === 'A-set-sef') return 'A-set-sef';
            if (ct === 'B-material' || ct.startsWith('B|')) {
                // match by label in d1
                const storedNorm = normalizeChoiceLabel(storedD1 || ct.split('|').slice(2).join('|'));
                const found = ALL_CRIT_OPTS.find(([v]) => v.startsWith('B|') && normalizeChoiceLabel(v.split('|').slice(2).join('|')) === storedNorm);
                return found ? found[0] : ct;
            }
            if (ct === 'C-thesis' || ct.startsWith('C|')) {
                const storedNorm = normalizeChoiceLabel(storedD1 || ct.split('|').slice(2).join('|'));
                const found = ALL_CRIT_OPTS.find(([v]) => v.startsWith('C|') && normalizeChoiceLabel(v.split('|').slice(2).join('|')) === storedNorm);
                return found ? found[0] : ct;
            }
            return ct;
        }
        const currentFlatCrit = matchFlatCrit(critType, d1);

        // If this row was opened from a specific sub-item Data Entry button, that button
        // stored pendingKraFlatCrit. Use it to lock the criterion to that sub-item.
        // For edit rows (critType already set), the stored value is used directly.
        const effectiveFlatCrit = (pendingKraFlatCrit && !critType) ? pendingKraFlatCrit : currentFlatCrit;
        // Consume the pending value so subsequent rows (if any) don't inherit it.
        if (pendingKraFlatCrit && !critType) pendingKraFlatCrit = null;

        // Determine which criterion letter is active:
        // 1. If opened via "Data Entry" button, activeKraCriterion is set ('A','B','C')
        // 2. If opened via Edit, derive from the stored critType
        function activeLetter() {
            if (activeKraCriterion && activeKraCriterion !== 'A') return activeKraCriterion;
            if (effectiveFlatCrit === 'A-set-sef') return 'A';
            if (effectiveFlatCrit.startsWith('B|') || critType === 'B-material') return 'B';
            if (effectiveFlatCrit.startsWith('C|') || critType === 'C-thesis') return 'C';
            return activeKraCriterion || '';
        }
        const _letter = activeLetter();

        // Show only options that belong to the active criterion letter
        const filteredOpts = _letter
            ? ALL_CRIT_OPTS.filter(([v]) => {
                if (_letter === 'A') return v === 'A-set-sef';
                if (_letter === 'B') return v.startsWith('B|');
                if (_letter === 'C') return v.startsWith('C|');
                return true; // no filter if letter unknown
              })
            : ALL_CRIT_OPTS;

        // Strip the "B  -  " or "C  -  " prefix from option labels when only one criterion is shown
        const critOpts = filteredOpts.map(([v,l]) => {
            // Remove leading "B  -  " or "C  -  " prefix since the modal header already says which criterion
            const cleanLabel = _letter && _letter !== 'A' ? l.replace(/^[BC] - /, '') : l;
            return `<option value="${v}" ${effectiveFlatCrit===v?'selected':''}>${cleanLabel}</option>`;
        }).join('');

        // Is the criterion locked to a specific option (either from Data Entry or from an existing saved entry)?
        const critLocked = !!effectiveFlatCrit && effectiveFlatCrit !== '';
        const lockedOptEntry = critLocked ? ALL_CRIT_OPTS.find(([v]) => v === effectiveFlatCrit) : null;
        // Fallback label for legacy rows whose stored critType (e.g. 'B-material', 'C-thesis')
        // doesn't match any key in ALL_CRIT_OPTS  -  use d1 (the stored description) or derive a
        // human-readable label from the raw critType so the CRITERION column is never blank.
        function legacyCritLabel(ct, storedD1) {
            if (storedD1) return storedD1;
            // Map legacy internal codes to readable names
            const map = {
                'B-material': 'Instructional Material',
                'C-thesis':   'Thesis / Advisory',
                'C-mentor':   'Mentorship',
                'A-set-sef':  'Teaching Effectiveness (SET + SEF)',
            };
            if (map[ct]) return map[ct];
            // Generic cleanup: strip leading letter prefix and dashes
            return ct.replace(/^[A-Z]-/i, '').replace(/-/g, ' ');
        }
        const lockedLabel = lockedOptEntry
            ? lockedOptEntry[1].replace(/^[ABC] - /, '') // strip "B  -  " / "C  -  " prefix
            : (critLocked ? legacyCritLabel(effectiveFlatCrit, d1) : '');

        // Determine if currently selected item is a co-author B item
        const isBco = effectiveFlatCrit.startsWith('B|') && effectiveFlatCrit.split('|')[1]?.endsWith('co');
        // Parse stored contrib % from d1 or d2 (legacy B stored contrib in d2, new flat stores in d2)
        const contribVal = isBco ? (d2 || d1 || '100') : '';

        let detailFields = '';
        if (critType === 'A-set-sef') {
            detailFields = `
            <input type="text" inputmode="decimal" class="ri-d1" value="${esc(d1)}" placeholder="SET avg % (e.g. 92.5)" oninput="calcRow(this)" style="margin-bottom:3px;">
            <input type="text" inputmode="decimal" class="ri-d2" value="${esc(d2)}" placeholder="SEF avg % (e.g. 88.0)" oninput="calcRow(this)">`;
        } else if (isBco) {
            detailFields = `<input type="text" inputmode="decimal" class="ri-d2" value="${esc(contribVal || '100')}" placeholder="Contrib % (co-author)" oninput="calcRow(this)">`;
        } else {
            detailFields = `<span class="text-muted small">-</span>`;
        }

        // Build the criterion cell: locked = hidden select + visible read-only label;
        // unlocked (shouldn't happen any more from Data Entry, but kept as fallback) = visible dropdown.
        const critCell = critLocked
            ? `<td style="padding:0.4rem;">
                 <select class="ri-crit" style="display:none;" aria-hidden="true">
                   <option value="${esc(effectiveFlatCrit)}" selected>${esc(lockedLabel)}</option>
                 </select>
                 <div style="font-size:0.78rem;color:#1e293b;font-weight:600;padding:0.15rem 0;line-height:1.3;">
                   ${esc(lockedLabel)}
                 </div>
               </td>`
            : `<td style="padding:0.4rem;"><select class="ri-crit" onchange="calcRow(this);updateInstrFlatDetail(this)"><option value="" disabled selected>- Select -</option>${critOpts}</select></td>`;

        cells += `
        ${critCell}
        <td style="padding:0.4rem;">${detailFields}</td>
        <td style="padding:0.4rem;">${critType === 'A-set-sef'
            ? `<input type="text" class="ri-notes" value="${esc(notes)}" placeholder="Notes (optional)">`
            : '<span class="text-muted small">-</span>'}</td>`;
    }

    const scoreClass = score > 0 ? 'kra-score-badge has-score' : 'kra-score-badge';
    const evFiles    = e.evidence_files || [];
    const hasFiles   = evFiles.length > 0 || (e.document_path && e.document_path !== '');
    const fileCount  = evFiles.length || (e.document_path ? 1 : 0);
    const uploadClass = hasFiles ? 'btn btn-sm btn-outline-success kra-upload-btn has-file' : 'btn btn-sm btn-outline-secondary kra-upload-btn';
    const uploadIcon  = hasFiles ? 'bi-file-earmark-check-fill' : 'bi-cloud-upload';
    const uploadTitle = hasFiles ? `${fileCount} file(s) uploaded - click to manage` : 'Upload evidence';
    const firstDoc    = evFiles.length > 0 ? evFiles[0].file_path : (e.document_path || '');

    cells += `
    <td class="kra-score-cell">
        <span class="${scoreClass}" id="rscore_${sid}">${score.toFixed(2)}</span>
    </td>
    <td class="kra-evidence-cell">
        <button type="button" class="${uploadClass}" onclick="openUploadModal(this)"
                data-sid="${sid}" data-doc="${esc(firstDoc)}" title="${uploadTitle}">
            <i class="bi ${uploadIcon}"></i>${fileCount > 1 ? ` <span class="badge bg-light text-dark" style="font-size:0.65rem;">${fileCount}</span>` : ''}
        </button>
    </td>
    <td class="kra-actions-cell">
        <div class="kra-row-actions">
            <button type="button" class="btn btn-success kra-action-btn kra-save-btn d-none" onclick="saveRow(this)" data-sid="${sid}" title="Save row" style="width:36px;height:36px;padding:0;border-radius:6px;display:inline-flex;align-items:center;justify-content:center;">
                <i class="bi bi-floppy"></i>
            </button>
            <button type="button" class="btn btn-danger kra-action-btn" onclick="deleteRow(this)" data-sid="${sid}" title="Delete this row" style="width:36px;height:36px;padding:0;border-radius:6px;display:inline-flex;align-items:center;justify-content:center;">
                <i class="bi bi-trash"></i>
            </button>
        </div>
    </td>`;

    return cells;
}

function esc(s) { return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;'); }

function normalizeChoiceLabel(s) {
    const txt = document.createElement('textarea');
    txt.innerHTML = String(s || '');
    return txt.value
        .replace(/[\u2010-\u2015]/g, '-')
        .replace(/\s*\/\s*/g, '/')
        .replace(/[xx]\s*contrib%/ig, '')
        .replace(/\(\s*\d+(\.\d+)?\s*pts?\.?\s*\)/ig, '')
        .replace(/[^a-z0-9]+/ig, ' ')
        .replace(/\s+/g, ' ')
        .trim()
        .toLowerCase();
}

function storedChoicePoints(s) {
    const m = String(s || '').match(/\(\s*(\d+(?:\.\d+)?)\s*pts?\.?\s*\)/i);
    return m ? parseFloat(m[1]) : null;
}

function optionSelectedFromStored(stored, label, value) {
    const storedNorm = normalizeChoiceLabel(stored);
    const labelNorm = normalizeChoiceLabel(label);
    if (storedNorm && labelNorm && (storedNorm === labelNorm || labelNorm.includes(storedNorm) || storedNorm.includes(labelNorm))) {
        return true;
    }
    const pts = storedChoicePoints(stored);
    if (pts === null) return false;
    const numericValue = parseFloat(String(value || '').replace('co', ''));
    return !Number.isNaN(numericValue) && numericValue === pts && storedNorm && labelNorm && (
        storedNorm.split('-')[0] === labelNorm.split('-')[0] ||
        storedNorm.includes('mentor') === labelNorm.includes('mentor')
    );
}

// Per-entry score preview mirroring kra3_scorer.php's single-entry logic
// (cross-row caps like the ISR 30-pt sub-cap are applied server-side on save).
function extScoreForSubtype(subtype, val1, val2) {
    const s = String(subtype || '');
    if (s === 'moa-linkage') return 5.0;
    if (s === 'income') {
        const income = parseFloat(val1) || 0;
        const lead = !String(val2 || '').toLowerCase().includes('co');
        let tier = 0;
        if (income > 12000000) tier = 18;
        else if (income >= 6000001) tier = 12;
        else if (income >= 500001) tier = 6;
        else if (income >= 100001) tier = 4;
        else if (income > 0) tier = 2;
        return lead ? tier : tier / 2;
    }
    if (s === 'accredit-local') return 8.0;
    if (s === 'accredit-intl') return 10.0;
    if (s === 'judge-research') return 2.0;
    if (s === 'judge-other') return 1.0;
    if (s === 'consultant-local') return 8.0;
    if (s === 'consultant-intl') return 10.0;
    if (s === 'media-column-regular') return 10.0;
    if (s === 'media-column-occasional') return 2.0;
    if (s === 'media-tv-radio-host') return 10.0;
    if (s === 'media-guest') return 1.0;
    if (s === 'resource-speaker-local' || s === 'resource-speaker-intl') {
        const hours = Math.max(1, parseFloat(val1) || 1);
        return (s === 'resource-speaker-intl' ? 3.0 : 2.0) * hours;
    }
    if (s === 'outreach-isr-lead') return 5.0;
    if (s === 'outreach-isr-member') return 2.0;
    if (s === 'csr-satisfaction') {
        const rating = Math.min(100, Math.max(0, parseFloat(val1) || 0));
        return (rating / 100) * 20;
    }
    const D_RATES = {
        'president': 20, 'vice-president': 15, 'chancellor': 10, 'vice-chancellor': 8,
        'campus director': 8, 'office director': 6, 'dean': 6, 'associate dean': 5,
        'dept head': 4, 'program chair': 3, 'committee chair': 2, 'committee member': 1,
        'coordinator': 2,
    };
    if (s in D_RATES) {
        const years = Math.max(1, parseFloat(val1) || 1);
        return D_RATES[s] * years;
    }
    return 0;
}

// -- Calc score ------------------------------------------------
function calcRow(el) {
    const tr = el.closest('tr');
    let score = 0;
    if (tr.querySelector('.custom-key')) {
        score = parseFloat(tr.querySelector('.custom-max')?.value) || 0;
    } else if (KRA_CAT === 'Research') {
        const base = parseFloat(tr.querySelector('.rtype')?.value) || 0;
        const cont = parseFloat(tr.querySelector('.rcontrib')?.value) || 0;
        // Warn if contribution > 100
        const contEl = tr.querySelector('.rcontrib');
        if (contEl) {
            const v = parseFloat(contEl.value);
            contEl.classList.toggle('over-cap', v > 100);
            let w = contEl.nextElementSibling;
            if (!w || !w.classList.contains('cap-warn')) {
                w = document.createElement('div');
                w.className = 'cap-warn';
                w.textContent = 'Max 100%';
                contEl.parentNode.appendChild(w);
            }
            w.style.display = v > 100 ? 'block' : 'none';
        }
        score = base * (cont / 100);
    } else if (KRA_CAT === 'Extension') {
        const subtype = tr.querySelector('.re-crit')?.value || '';
        const val1    = tr.querySelector('.re-val1')?.value || '';
        const val2    = tr.querySelector('.re-val2')?.value || '';
        score = extScoreForSubtype(subtype, val1, val2);
    } else if (KRA_CAT === 'Professional Development') {
        const critType = tr.querySelector('.rpd-crit')?.value || '';
        const subVal   = parseFloat(tr.querySelector('.rpd-sub')?.value) || 0;
        if (critType === 'B-degree') {
            score = subVal; // 10/20/40 pts
        } else if (critType === 'B-training') {
            score = subVal; // 1 or 2 pts per activity
        } else if (critType === 'B-paper') {
            score = subVal; // 3 or 5 pts per presentation
        } else if (critType === 'C-award') {
            // subVal: 2=Institutional, 3=Local/Regional, 4=National, 5=Intl (CONFIG GAP→0 live), 0=Prestigious(0)
            score = (subVal === 5) ? 0 : subVal; // International shows 0 until admin configures the value
        } else if (critType === 'A-org') {
            score = 5; // 5 pts per qualifying org
        }
    } else {
        // KRA I - Instruction
        const critType = tr.querySelector('.ri-crit')?.value || '';
        if (critType === 'A-set-sef') {
            const set = parseFloat(tr.querySelector('.ri-d1')?.value) || 0;
            const sef = parseFloat(tr.querySelector('.ri-d2')?.value) || 0;
            ['.ri-d1','.ri-d2'].forEach(cls => {
                const inp = tr.querySelector(cls);
                if (!inp) return;
                const v = parseFloat(inp.value);
                inp.classList.toggle('over-cap', v > 100);
                let w = inp.nextElementSibling;
                if (!w || !w.classList.contains('cap-warn')) {
                    w = document.createElement('div'); w.className = 'cap-warn'; w.textContent = 'Max 100%';
                    inp.parentNode.appendChild(w);
                }
                w.style.display = v > 100 ? 'block' : 'none';
            });
            score = (set / 100) * KRA1_A_SET_CAP + (sef / 100) * KRA1_A_SEF_CAP;
        } else if (critType === 'B-material' || critType.startsWith('B|')) {
            // Flat B: value like 'B|30co|Label'
            const parts = critType.split('|');
            const pts = parts[1] || '';
            const contrib = parseFloat(tr.querySelector('.ri-d2')?.value) || 100;
            if (pts.endsWith('co')) {
                score = parseFloat(pts.replace('co','')) * (contrib / 100);
            } else {
                score = parseFloat(pts) || 0;
            }
        } else if (critType === 'C-thesis' || critType.startsWith('C|')) {
            // Flat C: value like 'C|5|Adviser  -  Undergraduate Thesis'
            const parts = critType.split('|');
            const pts = parseFloat(parts[1] ?? '0');
            const isPendingSourceGap = parts[1] === '0' && (
                critType.includes('Mentor') ||
                critType.includes('Textbook Chapter') ||
                critType.includes('Validated Testing Materials')
            );
            const scoreEl2 = document.getElementById(`rscore_${tr.dataset.sid}`) || tr.querySelector('.kra-score-badge');
            if (isPendingSourceGap && scoreEl2) {
                scoreEl2.textContent = 'Pending confirmation, not counted';
                scoreEl2.className = 'kra-score-badge';
                updateGrandTotal();
                return;
            }
            score = pts;
        }
    }
    const sid = tr.dataset.sid || tr.querySelector('[data-sid]')?.dataset.sid || '';
    const scoreEl = document.getElementById(`rscore_${sid}`) || tr.querySelector('.kra-score-badge');
    if (scoreEl) {
        scoreEl.textContent = score.toFixed(2);
        scoreEl.className = score > 0 ? 'kra-score-badge has-score' : 'kra-score-badge';
    }
    updateGrandTotal();
}

function getKraGrandTotalEl() {
    return document.getElementById(isCriteriaATableMode() ? 'kraGrandTotalCriteriaA' : 'kraGrandTotalDefault');
}

function setKraGrandTotal(value) {
    const el = getKraGrandTotalEl();
    if (el) el.textContent = value;
}

function updateGrandTotal() {
    if (isCriteriaATableMode()) {
        updateCriteriaASummary();
        return;
    }
    let sum = 0;
    document.querySelectorAll('#kraTableBody .kra-score-badge').forEach(el => sum += parseFloat(el.textContent) || 0);
    // JC01 s.2026: each KRA capped at 100 (Extension was 120 under old JC3 rules)
    const capMap = {'Instruction':100, 'Research':100, 'Extension':100, 'Professional Development':100};
    const cap = capMap[KRA_CAT] ?? 100;
    setKraGrandTotal(Math.min(sum, cap).toFixed(2));
}

function validRating(v) {
    const n = parseFloat(v);
    return String(v).trim() !== '' && Number.isFinite(n) && n >= 0 ? Math.min(100, Math.max(0, n)) : null;
}

function calcCriteriaARow(el) {
    const tr = el.closest('tr');
    tr?.querySelectorAll('.kra-a-set, .kra-a-sef').forEach(inp => {
        const v = parseFloat(inp.value);
        inp.classList.toggle('over-cap', Number.isFinite(v) && v > 100);
    });
    updateCriteriaASummary();
}

function syncCriteriaAPeriod(input) {
    input.closest('tr')?.querySelectorAll('[data-sem]').forEach(btn => {
        btn.dataset.period = input.value || '';
    });
}

function updateCriteriaASummary() {
    const ratings = [];
    const setVals = [];
    const sefVals = [];
    criteriaARows().forEach(tr => {
        tr.querySelectorAll('.kra-a-set, .kra-a-sef').forEach(inp => {
            const v = validRating(inp.value);
            if (v !== null) ratings.push(v);
        });
        tr.querySelectorAll('.kra-a-set').forEach(inp => {
            const v = validRating(inp.value);
            if (v !== null) setVals.push(v);
        });
        tr.querySelectorAll('.kra-a-sef').forEach(inp => {
            const v = validRating(inp.value);
            if (v !== null) sefVals.push(v);
        });
    });
    const avg = ratings.length ? ratings.reduce((a,b) => a + b, 0) / ratings.length : 0;
    const avgSet = setVals.length ? setVals.reduce((a,b) => a + b, 0) / setVals.length : 0;
    const avgSef = sefVals.length ? sefVals.reduce((a,b) => a + b, 0) / sefVals.length : 0;
    const facultyScore = (avgSet / 100) * KRA1_A_SET_CAP + (avgSef / 100) * KRA1_A_SEF_CAP;
    const setSubtotal = (avgSet / 100) * KRA1_A_SET_CAP;
    const sefSubtotal = (avgSef / 100) * KRA1_A_SEF_CAP;
    const setAvgEl = document.getElementById('kraSetAverage');
    const sefAvgEl = document.getElementById('kraSefAverage');
    const setSubtotalEl = document.getElementById('kraSetSubtotal');
    const sefSubtotalEl = document.getElementById('kraSefSubtotal');
    const scoreEl = getKraGrandTotalEl();
    if (setAvgEl) setAvgEl.textContent = avgSet.toFixed(2);
    if (sefAvgEl) sefAvgEl.textContent = avgSef.toFixed(2);
    if (setSubtotalEl) setSubtotalEl.textContent = setSubtotal.toFixed(2);
    if (sefSubtotalEl) sefSubtotalEl.textContent = sefSubtotal.toFixed(2);
    if (scoreEl) scoreEl.textContent = facultyScore.toFixed(2);
}

function criteriaARemarksFromRow(tr, sem) {
    const period = tr.querySelector('.kra-a-period')?.value.trim() || 'AY';
    const type = tr.dataset.entryType === 'sef' ? 'sef' : 'set';
    const value = tr.querySelector(type === 'sef' ? `.kra-a-sef.sem-${sem}` : `.kra-a-set.sem-${sem}`)?.value || '';
    return `${type === 'sef' ? 'A-sef-sem' : 'A-set-sem'}|||${period}|||${sem}|||${value}`;
}

function criteriaAScoreFromRow(tr, sem) {
    const type = tr.dataset.entryType === 'sef' ? 'sef' : 'set';
    const raw = parseFloat(tr.querySelector(type === 'sef' ? `.kra-a-sef.sem-${sem}` : `.kra-a-set.sem-${sem}`)?.value) || 0;
    const rating = Math.min(100, Math.max(0, raw));
    return type === 'sef' ? (rating / 100) * KRA1_A_SEF_CAP : (rating / 100) * KRA1_A_SET_CAP;
}

async function saveCriteriaASemester(tr, sem, sidOverride) {
    const sid = sidOverride ?? tr.querySelector(`[data-sem="${sem}"]`)?.dataset.sid ?? '0';
    const type = tr.dataset.entryType === 'sef' ? 'sef' : 'set';
    const setVal = tr.querySelector(`.kra-a-set.sem-${sem}`)?.value.trim() || '';
    const sefVal = tr.querySelector(`.kra-a-sef.sem-${sem}`)?.value.trim() || '';
    const ownVal = type === 'sef' ? sefVal : setVal;
    const hasFile = tr.querySelector(`[data-sem="${sem}"]`)?.classList.contains('has-file');
    const existingSid = parseInt(sid) > 0;
    // Never create a new record for a semester where nothing has been entered
    if (!existingSid && ownVal === '' && !hasFile) {
        return { ok: true, submission_id: null, skipped: true };
    }
    const fd = new FormData();
    fd.append('kra_action', 'save_kra');
    fd.append('kra_category', KRA_CAT);
    fd.append('app_id', APP_ID);
    fd.append('edit_submission_id', existingSid ? sid : 0);
    fd.append('computed_points', criteriaAScoreFromRow(tr, sem));
    fd.append('remarks', criteriaARemarksFromRow(tr, sem));
    const resp = await fetch(AJAX_URL, { method: 'POST', body: fd });
    const data = await resp.json();
    if (!data.ok) throw new Error(data.error || 'Save failed');
    return data;
}

// Which semesters of this row have something worth writing to the database.
function criteriaASemestersToSave(tr, forceSem) {
    return [1, 2].filter(sem => {
        const type = tr.dataset.entryType === 'sef' ? 'sef' : 'set';
        const selector = type === 'sef' ? `.kra-a-sef.sem-${sem}` : `.kra-a-set.sem-${sem}`;
        const scoreDiv = tr.querySelector(`.kra-a-scores.sem-${sem}`);
        if (scoreDiv && scoreDiv.querySelector(selector)?.disabled) return false; // verified & locked
        if (forceSem && parseInt(forceSem) === sem) return true;
        const btn = tr.querySelector(`[data-sem="${sem}"]`);
        const sid = parseInt(btn?.dataset.sid || '0');
        const val = tr.querySelector(selector)?.value.trim() || '';
        const hasEvidence = btn?.classList.contains('has-file');
        const hasValue = val !== '';
        // Do not create an empty counterpart record when editing a single semester.
        return sid > 0 || hasValue || hasEvidence;
    });
}

// Writes BOTH semesters of an evaluation period. Each semester is its own
// kra_submissions record with its own evidence, so saving or uploading for one
// semester can never blank out the other.
async function persistCriteriaARow(tr, forceSem) {
    const semestersToSave = criteriaASemestersToSave(tr, forceSem);
    for (const sem of semestersToSave) {
        const data = await saveCriteriaASemester(tr, sem);
        if (data && data.submission_id) {
            const semesterButton = tr.querySelector(`[data-sem="${sem}"]`);
            if (semesterButton) semesterButton.dataset.sid = data.submission_id;
            if (sem === 1) tr.dataset.firstSid = data.submission_id;
            else tr.dataset.secondSid = data.submission_id;
        }
    }
    return semestersToSave;
}

async function persistGenericKraRow(tr) {
    if (!tr || tr.classList.contains('kra-a-row')) return { ok: true, skipped: true };
    const sid = parseInt(tr.dataset.sid || '0');
    const upBtn = tr.querySelector('[onclick*="openUploadModal"]');
    const hasEvidence = upBtn?.classList.contains('has-file');
    if (sid <= 0 || !hasEvidence) return { ok: true, skipped: true };

    const score = _computeScoreFromRow(tr);
    const remarks = _buildRemarksFromRow(tr);
    if (!kraRemarksLookMeaningful(remarks)) {
        throw new Error('Please select a valid criterion and fill the required fields.');
    }

    const fd = new FormData();
    fd.append('kra_action', 'save_kra');
    fd.append('kra_category', KRA_CAT);
    fd.append('app_id', APP_ID);
    fd.append('edit_submission_id', sid);
    fd.append('computed_points', score);
    fd.append('remarks', remarks);

    const resp = await fetch(AJAX_URL, { method: 'POST', body: fd });
    const data = await resp.json();
    if (!data.ok) throw new Error(data.error || 'Save failed');
    tr.dataset.dirty = '0';
    const returnedScore = parseFloat(data.computed_points ?? score);
    const badge = document.getElementById(`rscore_${sid}`) || tr.querySelector('.kra-score-badge');
    if (badge && Number.isFinite(returnedScore)) {
        badge.textContent = returnedScore.toFixed(2);
        badge.className = returnedScore > 0 ? 'kra-score-badge has-score' : 'kra-score-badge';
        tr.dataset.computedPoints = returnedScore;
    }
    updateGrandTotal();
    return data;
}

async function saveCriteriaARow(btn) {
    const tr = btn.closest('tr');
    if (!tr) return;
    pendingKraScrollState = captureKraScrollState();
    btn.disabled = true;
    btn.innerHTML = '<span class="spinner-border spinner-border-sm"></span>';
    try {
        // Save in order so a newly-created semester immediately receives its
        // permanent ID before the next interaction with this evaluation row.
        await persistCriteriaARow(tr);
        tr.classList.add('row-saved-flash');
        setTimeout(() => tr.classList.remove('row-saved-flash'), 1200);
        criteriaADraftCache = snapshotCriteriaADraft();
        loadCriteriaARows();
    } catch (err) {
        showKraAlert(esc(err.message || 'Unable to save Criteria A row.'));
    } finally {
        btn.disabled = false;
        btn.innerHTML = '<i class="bi bi-floppy"></i>';
    }
}

function deleteCriteriaARow(btn) {
    const rowType = btn.closest('tr')?.dataset.entryType === 'sef' ? 'SEF' : 'SET';
    confirmAction(`Remove this ${rowType} entry? This removes its semester entries and evidence.`, function() {
        pendingKraScrollState = captureKraScrollState();
        const tr = btn.closest('tr');
        const ids = Array.from(tr.querySelectorAll('[data-sem]'))
            .map(b => parseInt(b.dataset.sid || '0'))
            .filter(id => id > 0);
        if (!ids.length) {
            tr.remove();
            updateCriteriaASummary();
            restoreKraScrollState(pendingKraScrollState);
            refreshAddRowButton();
            const type = tr.dataset.entryType === 'sef' ? 'sef' : 'set';
            const body = criteriaABodyForType(type);
            if (body && !body.querySelector('tr.kra-a-row')) body.innerHTML = criteriaAEmptyRow(type);
            return;
        }
        Promise.all(ids.map(id => {
            const fd = new FormData();
            fd.append('kra_action', 'delete_kra');
            fd.append('app_id', APP_ID);
            fd.append('submission_id', id);
            return fetch(AJAX_URL, { method: 'POST', body: fd }).then(r => r.json());
        })).then(() => loadRows());
    }, 'Remove', 'bi-trash');
}

// Rebuild the sub-field cell when ProfDev criterion changes
function updatePdSubField(sel) {
    const tr      = sel.closest('tr');
    const subCell = tr.querySelector('.rpd-sub')?.closest('td');
    if (!subCell) return;
    const critType = sel.value;
    let html = '';
    if (critType === 'B-degree') {
        html = `<select class="rpd-sub" onchange="calcRow(this)">
            <option value="0">None (0 pts)</option>
            <option value="10">Post-Master's / Post-Doctoral (10 pts)</option>
            <option value="20">Additional Master's Degree (20 pts)</option>
        </select>`;
    } else if (critType === 'B-training') {
        html = `<select class="rpd-sub" onchange="calcRow(this)">
            <option value="1">Local (1 pt)</option>
            <option value="2">International (2 pts)</option>
        </select>`;
    } else if (critType === 'B-paper') {
        html = `<select class="rpd-sub" onchange="calcRow(this)">
            <option value="3">Local (3 pts)</option>
            <option value="5">International (5 pts)</option>
        </select>`;
    } else if (critType === 'C-award') {
        html = `<select class="rpd-sub" onchange="calcRow(this)">
            <option value="2">Institutional (2 pts)</option>
            <option value="3">Local/Regional (3 pts)</option>
            <option value="4">National (4 pts)</option>
            <option value="5">International (pts pending — CONFIG GAP)</option>
            <option value="0">Prestigious Award — Sub-rank trigger only (0 pts)</option>
        </select>`;
    } else {
        html = `<input type="hidden" class="rpd-sub" value="1"><span class="text-muted small">5 pts</span>`;
    }
    subCell.innerHTML = html;
    calcRow(sel);
}

function updateInstrFlatDetail(sel) {
    const tr = sel.closest('tr');
    const detailCell = tr.querySelectorAll('td')[3];
    const notesCell  = tr.querySelectorAll('td')[4];
    if (!detailCell) return;
    const val = sel.value || '';
    if (val === 'A-set-sef') {
        detailCell.innerHTML = `
            <input type="text" inputmode="decimal" class="ri-d1" placeholder="SET avg % (e.g. 92.5)" oninput="calcRow(this)" style="margin-bottom:3px;">
            <input type="text" inputmode="decimal" class="ri-d2" placeholder="SEF avg % (e.g. 88.0)" oninput="calcRow(this)">`;
        if (notesCell) notesCell.innerHTML = `<input type="text" class="ri-notes" placeholder="Notes (optional)">`;
    } else if (val.startsWith('B|') && val.split('|')[1]?.endsWith('co')) {
        detailCell.innerHTML = `<input type="text" inputmode="decimal" class="ri-d2" value="100" placeholder="Contrib % (co-author)" oninput="calcRow(this)">`;
        if (notesCell) notesCell.innerHTML = `<span class="text-muted small">-</span>`;
    } else {
        detailCell.innerHTML = `<span class="text-muted small">-</span>`;
        if (notesCell) notesCell.innerHTML = `<span class="text-muted small">-</span>`;
    }
    calcRow(sel);
}

// Rebuild the detail fields when Instruction criterion changes
function updateInstrSubField(sel) {
    const tr        = sel.closest('tr');
    const detailCell = tr.querySelectorAll('td')[3]; // 4th td = details
    const notesCell  = tr.querySelectorAll('td')[4]; // 5th td = notes/sub
    if (!detailCell) return;
    const critType = sel.value;
    if (critType === 'A-set-sef') {
        detailCell.innerHTML = `
            <input type="text" inputmode="decimal" class="ri-d1" placeholder="SET avg % (e.g. 92.5)" oninput="calcRow(this)" style="margin-bottom:3px;">
            <input type="text" inputmode="decimal" class="ri-d2" placeholder="SEF avg % (e.g. 88.0)" oninput="calcRow(this)">`;
        if (notesCell) notesCell.innerHTML = `<input type="text" class="ri-notes" placeholder="Notes (optional)">`;
    } else if (critType === 'B-material') {
        detailCell.innerHTML = `
            <select class="ri-d1-sel" onchange="calcRow(this)" style="margin-bottom:3px;">
                <option value="" disabled selected>- Select material type -</option>
                <option value="30">Textbook, Sole Author (30 pts)</option>
                <option value="30co">Textbook, Co-Author (30 x contrib%)</option>
                <option value="10">Textbook Chapter, Sole Author (10 pts)</option>
                <option value="10co">Textbook Chapter, Co-Author (10 x contrib%)</option>
                <option value="16">Manual / Module, Sole Author (16 pts)</option>
                <option value="16co">Manual / Module, Co-Author (16 x contrib%)</option>
                <option value="16">Multimedia Teaching Materials (16 pts)</option>
                <option value="10">Validated Testing Materials (10 pts)</option>
                <option value="10">Academic Program Dev/Revision, Lead (10 pts)</option>
                <option value="5">Academic Program Dev/Revision, Contributor (5 pts)</option>
            </select>
            <input type="text" inputmode="decimal" class="ri-d2" placeholder="Contrib % (if co-author)" oninput="calcRow(this)">`;
        if (notesCell) notesCell.innerHTML = `<span class="text-muted small">-</span>`;
    } else if (critType === 'C-thesis') {
        detailCell.innerHTML = `
            <select class="ri-d1-sel" onchange="calcRow(this)">
                <option value="" disabled selected>- Select role -</option>
                <option value="3">Adviser: Special Project / Capstone (3 pts)</option>
                <option value="5">Adviser: Undergraduate Thesis (5 pts)</option>
                <option value="8">Adviser: Master's Thesis (8 pts)</option>
                <option value="10">Adviser: Doctoral Dissertation (10 pts)</option>
                <option value="1">Panel Member: Special Project / Capstone (1 pt)</option>
                <option value="2">Panel Member: Undergraduate Thesis (2 pts)</option>
                <option value="4">Panel Member: Master's Thesis (4 pts)</option>
                <option value="6">Panel Member: Doctoral Dissertation (6 pts)</option>
                <option value="0">Mentor: Student/Team Competition Winner (Pending confirmation, not counted)</option>
            </select>`;
        if (notesCell) notesCell.innerHTML = `<span class="text-muted small">-</span>`;
    }
    calcRow(sel);
}

function captureKraScrollState() {
    const modalBody = document.querySelector('#kraEntryModal .modal-body');
    return {
        modalBody,
        modalTop: modalBody ? modalBody.scrollTop : 0,
        windowX: window.scrollX || window.pageXOffset || 0,
        windowY: window.scrollY || window.pageYOffset || 0
    };
}

function restoreKraScrollState(state) {
    if (!state) return;
    const restore = () => {
        if (state.modalBody) {
            const maxTop = Math.max(0, state.modalBody.scrollHeight - state.modalBody.clientHeight);
            state.modalBody.scrollTop = Math.min(state.modalTop, maxTop);
        }
        window.scrollTo(state.windowX, state.windowY);
    };
    [0, 50, 150, 350, 700].forEach(delay => setTimeout(restore, delay));
    requestAnimationFrame(() => requestAnimationFrame(restore));
}

let pendingKraScrollState = null;

// -- Load rows -------------------------------------------------
let genericRowsRequestToken = 0;
function loadRows() {
    if (isCriteriaATableMode()) {
        loadCriteriaARows();
        return;
    }
    const genericToken = ++genericRowsRequestToken;
    const genericCriterion = activeKraCriterion;
    const scrollState = pendingKraScrollState || captureKraScrollState();
    pendingKraScrollState = null;
    setKraTableHeaderForDefault();
    const tbody = document.getElementById('kraTableBody');
    const modalBody = document.querySelector('#kraEntryModal .modal-body');
    const hadRows = !!tbody?.querySelector('tr[data-sid]');
    if (tbody && !hadRows) {
        tbody.innerHTML = `<tr id="kraEmptyRow"><td colspan="20" class="text-center text-muted py-12">Loading entries...</td></tr>`;
    }
    fetch(`${AJAX_URL}?kra_action=get_entries&app_id=${APP_ID}&cat=${encodeURIComponent(KRA_CAT)}`)
        .then(r => r.json())
        .then(data => {
            // Discard the response if the user has since opened another criterion.
            if (genericToken !== genericRowsRequestToken || genericCriterion !== activeKraCriterion) return;
            const tbody = document.getElementById('kraTableBody');
            // The clicked edit button already contains the record. Use it as a
            // fallback if the list request has not caught up with the page.
            const loadedEntries = data.ok && data.entries?.length
                ? data.entries
                : (pendingLegacyCriteriaAEditEntry ? [pendingLegacyCriteriaAEditEntry] : []);
            const entries = visibleEntriesForActiveCriterion(loadedEntries);
            if (!entries.length) {
                tbody.innerHTML = '';
                setKraGrandTotal('0.00');
                addKraRow();
                refreshSidebarScore();
                restoreKraScrollState(scrollState);
                return;
            }
            tbody.innerHTML = '';
            let sum = 0;
            entries.forEach((e, i) => {
                sum += parseFloat(e.computed_points || 0);
                const tr = document.createElement('tr');
                tr.dataset.sid = e.submission_id;
                tr.dataset.computedPoints = e.computed_points || 0;  // store for fallback
                tr.innerHTML = buildRow(e, i + 1);
                tbody.appendChild(tr);
                // Recalculate score from live dropdown after render
                const subSel = tr.querySelector('select.ri-d1-sel');
                const mainSel = tr.querySelector('select.ri-crit, select.rtype, select.rpd-crit, select.re-crit');
                const trigger = subSel || mainSel;
                if (trigger) {
                    calcRow(trigger);
                    const badge = tr.querySelector('.kra-score-badge');
                    const recalcScore = parseFloat(badge?.textContent) || 0;
                    const dbScore = parseFloat(e.computed_points || 0);
                    if (recalcScore !== dbScore) {
                        sum = sum - dbScore + recalcScore;
                        // Update stored score so upload fallback uses correct value
                        tr.dataset.computedPoints = recalcScore;
                    }
                    // Auto-fix: if DB has 0 but we computed a valid score, save it back
                    if (dbScore === 0 && recalcScore > 0) {
                        const fixFd = new FormData();
                        fixFd.append('kra_action', 'save_kra');
                        fixFd.append('kra_category', KRA_CAT);
                        fixFd.append('app_id', APP_ID);
                        fixFd.append('edit_submission_id', e.submission_id);
                        fixFd.append('computed_points', recalcScore);
                        const remarks = _buildRemarksFromRow(tr);
                        fixFd.append('remarks', remarks);
                        fetch(AJAX_URL, { method: 'POST', body: fixFd }).catch(() => {});
                    }
                }
            });
            setKraGrandTotal(Math.min(sum, 100).toFixed(2));
            refreshSidebarScore();

            // If we have a pending scroll target, scroll to it now
            if (pendingScrollToSid > 0) {
                setTimeout(() => scrollToEntry(pendingScrollToSid), 150);
                pendingScrollToSid = 0; // Clear the flag
            }
            pendingLegacyCriteriaAEditEntry = null;

            // If arriving from "Edit" link, scroll to and highlight the target row
            if (EDIT_SID > 0) {
                const target = tbody.querySelector(`tr[data-sid="${EDIT_SID}"]`);
                if (target) {
                    setTimeout(() => {
                        target.scrollIntoView({ behavior: 'smooth', block: 'center' });
                        target.style.transition = 'background 0.3s';
                        target.style.background = '#f1f5f9';
                        setTimeout(() => { target.style.background = ''; }, 2000);
                    }, 150);
                }
            } else {
                restoreKraScrollState(scrollState);
            }
        });
}

function loadCriteriaARows() {
    const scrollState = pendingKraScrollState || captureKraScrollState();
    pendingKraScrollState = null;
    // Take the draft BEFORE the header rebuild so nothing typed is lost.
    const draft = criteriaADraftCache || snapshotCriteriaADraft();
    criteriaADraftCache = null;
    // Snapshot which criterion this fetch belongs to. If the user closes
    // Criterion A and opens Criterion B (or any other criterion) before this
    // fetch resolves, the response below must be discarded instead of
    // painting Criteria A's semester rows into whatever table is now open  - 
    // this was the exact cause of Criterion B's "Add Entry" UI getting stuck
    // showing Criterion A's layout.
    const requestToken = ++criteriaARequestToken;
    setKraTableHeaderForCriteriaA();
    fetch(`${AJAX_URL}?kra_action=get_entries&app_id=${APP_ID}&cat=${encodeURIComponent(KRA_CAT)}`)
        .then(r => r.json())
        .then(data => {
            if (requestToken !== criteriaARequestToken || !isCriteriaATableMode()) {
                // A newer request has since started, or the user has since
                // switched away from Criterion A entirely  -  this response is
                // stale, do nothing with it.
                return;
            }
            const setBody = criteriaABodyForType('set');
            const sefBody = criteriaABodyForType('sef');
            if (!data.ok) {
                if (setBody) setBody.innerHTML = `<tr class="kra-a-empty-row"><td colspan="8" class="text-center text-muted py-12">Unable to load entries.</td></tr>`;
                if (sefBody) sefBody.innerHTML = `<tr class="kra-a-empty-row"><td colspan="8" class="text-center text-muted py-12">Unable to load entries.</td></tr>`;
                updateCriteriaASummary();
                return;
            }
            const groups = parseCriteriaAEntries(data.entries || []);
            if (setBody) setBody.innerHTML = '';
            if (sefBody) sefBody.innerHTML = '';
            if (!groups.length) {
                if (setBody) setBody.innerHTML = criteriaAEmptyRow('set');
                if (sefBody) sefBody.innerHTML = criteriaAEmptyRow('sef');
                restoreCriteriaADraft(draft);
                updateCriteriaASummary();
                refreshSidebarScore();
                restoreKraScrollState(scrollState);
                refreshAddRowButton();
                applyCriteriaAFocus();
                return;
            }
            const sectionCounts = { set: 0, sef: 0 };
            groups.forEach((g, i) => {
                const type = g.type === 'sef' ? 'sef' : 'set';
                const tbody = criteriaABodyForType(type);
                if (!tbody) return;
                const tr = document.createElement('tr');
                tr.className = 'kra-a-row';
                tr.dataset.entryType = type;
                tr.dataset.firstSid = g.first.submission_id || 0;
                tr.dataset.secondSid = g.second.submission_id || 0;
                sectionCounts[type]++;
                tr.innerHTML = buildCriteriaARow(g, sectionCounts[type]);
                tbody.appendChild(tr);
            });
            if (setBody && !setBody.querySelector('tr.kra-a-row')) setBody.innerHTML = criteriaAEmptyRow('set');
            if (sefBody && !sefBody.querySelector('tr.kra-a-row')) sefBody.innerHTML = criteriaAEmptyRow('sef');
            restoreCriteriaADraft(draft);
            updateCriteriaASummary();
            refreshSidebarScore();
            if (pendingScrollToSid > 0) {
                const targetBtn = document.querySelector(`#criteriaASections [data-sid="${pendingScrollToSid}"]`);
                const target = targetBtn?.closest('tr');
                if (target) {
                    setTimeout(() => {
                        target.scrollIntoView({ behavior: 'smooth', block: 'center' });
                        target.style.transition = 'background 0.3s';
                        target.style.background = '#fffbeb';
                        setTimeout(() => { target.style.background = ''; }, 2000);
                    }, 150);
                }
                pendingScrollToSid = 0;
            } else {
                restoreKraScrollState(scrollState);
            }
            refreshAddRowButton();
            applyCriteriaAFocus();
        });
}

// -- Section focus helper --------------------------------------
/**
 * Shows/hides the SET and SEF sections based on pendingCriteriaAFocusType.
 * When focus is 'set' only the SET section is visible; when 'sef' only SEF;
 * when null both sections are shown (default / add-new behaviour).
 * A small label is injected into the modal title to signal the focused type.
 */
function applyCriteriaAFocus() {
    const setSection = document.querySelector('#criteriaASections .criteria-a-section[data-section-type="set"]');
    const sefSection = document.querySelector('#criteriaASections .criteria-a-section[data-section-type="sef"]');
    const focusLabel = document.getElementById('criteriaAFocusLabel');

    if (!pendingCriteriaAFocusType) {
        // Show both sections
        if (setSection) setSection.style.display = '';
        if (sefSection) sefSection.style.display = '';
        if (focusLabel) focusLabel.style.display = 'none';
        return;
    }

    // Hide the non-focused section, show only the relevant one
    const showSet = pendingCriteriaAFocusType === 'set';
    if (setSection) setSection.style.display = showSet ? '' : 'none';
    if (sefSection) sefSection.style.display = showSet ? 'none' : '';

    // Update the inline badge next to the modal title (if it exists)
    if (focusLabel) {
        focusLabel.textContent = showSet ? 'SET only' : 'SEF only';
        focusLabel.style.display = 'inline-flex';
    }
}

// -- Cycle-year helpers ----------------------------------------
/**
 * Returns the start year of the LAST existing evaluation-period row,
 * reading the "AY YYYY-YYYY" value stored in .kra-a-period inputs.
 * Falls back to CYCLE_START_YEAR when no rows are present yet.
 */
function lastExistingCycleYear(type = '') {
    const rows = criteriaARows()
        .filter(row => !type || (row.dataset.entryType || 'set') === type);
    for (let i = rows.length - 1; i >= 0; i--) {
        const val = rows[i].querySelector('.kra-a-period')?.value.trim() || '';
        const m = val.match(/\b(\d{4})-\d{4}\b/);
        if (m) return parseInt(m[1], 10);
    }
    return CYCLE_START_YEAR;
}

/**
 * Returns the AY string for the next row to be added, based on how many
 * Criterion-A rows already exist in the tbody.
 *   row 1 -> CYCLE_START_YEAR-(CYCLE_START_YEAR+1)
 *   row 2 -> (CYCLE_START_YEAR+1)-(CYCLE_START_YEAR+2)
 *   row 3 -> (CYCLE_START_YEAR+2)-(CYCLE_START_YEAR+3)
 * If rows already present don't follow the cycle sequence (e.g. partial
 * reload), we advance from the last stored year instead.
 */
function nextCycleYear(type = 'set') {
    const existingRows = criteriaARowCount(type);
    // Use cycle start + offset when the count matches a clean sequence
    const sequenceYear = CYCLE_START_YEAR + existingRows;
    // Also check what the last stored row says  -  pick whichever is larger
    // so we never generate a duplicate or go backwards
    const lastYear = lastExistingCycleYear(type);
    const baseYear = existingRows === 0 ? CYCLE_START_YEAR : Math.max(sequenceYear, lastYear + 1);
    return `AY ${baseYear}-${baseYear + 1}`;
}

/**
 * Counts how many Criterion-A rows are currently rendered (saved or unsaved).
 */
function criteriaARowCount(type = '') {
    const rows = criteriaARows();
    return type ? rows.filter(row => (row.dataset.entryType || 'set') === type).length : rows.length;
}

function criteriaAPeriodExists(type, period) {
    return criteriaARows().some(row =>
        (row.dataset.entryType || 'set') === type
        && (row.querySelector('.kra-a-period')?.value.trim() || '') === period
    );
}

/**
 * Enables/disables the + (Add Row) button in the Criterion A table header
 * based on the 3-row cycle limit.  Also updates the button's title tooltip.
 */
function refreshAddRowButton() {
    if (!isCriteriaATableMode()) return;
    const btn = document.querySelector('#kraTable thead tr th:first-child button');
    if (!btn) return;
    const count = criteriaARowCount();
    const atLimit = count >= CYCLE_ROW_LIMIT;
    btn.disabled = atLimit;
    btn.style.opacity = atLimit ? '0.45' : '1';
    btn.style.cursor  = atLimit ? 'not-allowed' : 'pointer';
    btn.title = atLimit
        ? `Cycle limit reached  -  ${CYCLE_ROW_LIMIT} years per cycle`
        : 'Add evaluation period';
    // Restore hover colours only when enabled
    if (!atLimit) {
        btn.onmouseover = () => { btn.style.background = '#1a3a6b'; };
        btn.onmouseout  = () => { btn.style.background = '#1e4d8c'; };
    } else {
        btn.onmouseover = null;
        btn.onmouseout  = null;
        btn.style.background = '#1e4d8c';
    }
}

// -- Add row ---------------------------------------------------
function addKraRow() {
    if (isCriteriaATableMode()) {
        const tbody = document.getElementById('kraTableBody');
        // Guard: never exceed the 3-row cycle limit
        if (criteriaARowCount() >= CYCLE_ROW_LIMIT) {
            showKraAlert(`Cycle limit reached  -  only ${CYCLE_ROW_LIMIT} evaluation years are allowed per reclassification cycle.`);
            return;
        }
        const emptyRow = document.getElementById('kraEmptyRow');
        if (emptyRow) emptyRow.remove();
        const num    = tbody.rows.length + 1;
        const period = nextCycleYear();
        const group  = { period, first: makeEmptySemester(period, 1), second: makeEmptySemester(period, 2) };
        const tr = document.createElement('tr');
        tr.className = 'kra-a-row';
        tr.innerHTML = buildCriteriaARow(group, num);
        tbody.appendChild(tr);
        updateCriteriaASummary();
        refreshAddRowButton();
        return;
    }
    const tbody = document.getElementById('kraTableBody');
    const emptyRow = document.getElementById('kraEmptyRow');
    if (emptyRow) emptyRow.remove();
    const num = tbody.rows.length + 1;
    const tr = document.createElement('tr');
    tr.dataset.sid = tempId;
    tr.innerHTML = buildRow({ submission_id: tempId, computed_points: 0, remarks: '', document_path: '' }, num);
    tbody.appendChild(tr);
    tempId--;
}

// Type-aware Criteria A controls override the older combined-row helpers above.
function refreshAddRowButton() {
    if (!isCriteriaATableMode()) return;
    document.querySelectorAll('#criteriaASections [data-add-type]').forEach(btn => {
        const type = btn.dataset.addType === 'sef' ? 'sef' : 'set';
        const atLimit = criteriaARowCount(type) >= CYCLE_ROW_LIMIT;
        btn.disabled = atLimit;
        btn.style.opacity = atLimit ? '0.45' : '1';
        btn.style.cursor = atLimit ? 'not-allowed' : 'pointer';
        btn.title = atLimit
            ? `Cycle limit reached - ${CYCLE_ROW_LIMIT} ${type.toUpperCase()} years per cycle`
            : `Add ${type.toUpperCase()} entry`;
    });
}

function addKraRow(type = 'set') {
    if (isCriteriaATableMode()) {
        type = type === 'sef' ? 'sef' : 'set';
        const tbody = criteriaABodyForType(type);
        if (!tbody) return;
        if (criteriaARowCount(type) >= CYCLE_ROW_LIMIT) {
            showKraAlert(`Cycle limit reached - only ${CYCLE_ROW_LIMIT} ${type.toUpperCase()} evaluation years are allowed per reclassification cycle.`);
            return;
        }
        tbody.querySelector('.kra-a-empty-row')?.remove();
        const num = criteriaARowCount(type) + 1;
        const period = nextCycleYear(type);
        if (criteriaAPeriodExists(type, period)) {
            showKraAlert(`${type.toUpperCase()} already has an entry for ${esc(period)}.`);
            return;
        }
        const group = { period, type, first: makeEmptySemester(period, 1, type), second: makeEmptySemester(period, 2, type) };
        const tr = document.createElement('tr');
        tr.className = 'kra-a-row';
        tr.dataset.entryType = type;
        tr.innerHTML = buildCriteriaARow(group, num);
        tbody.appendChild(tr);
        updateCriteriaASummary();
        refreshAddRowButton();
        return;
    }
    const tbody = document.getElementById('kraTableBody');
    const emptyRow = document.getElementById('kraEmptyRow');
    if (emptyRow) emptyRow.remove();
    const num = tbody.rows.length + 1;
    const tr = document.createElement('tr');
    tr.dataset.sid = tempId;
    tr.innerHTML = buildRow({ submission_id: tempId, computed_points: 0, remarks: '', document_path: '' }, num);
    tbody.appendChild(tr);
    // Trigger calcRow immediately so score updates when criterion is pre-locked
    const _addTrig = tr.querySelector('select.ri-d1-sel, select.ri-crit, select.rtype, select.rpd-crit, select.re-crit');
    if (_addTrig) calcRow(_addTrig);
    tempId--;
}

// -- Save row --------------------------------------------------
function saveRow(btn) {
    const tr = btn.closest('tr');
    const sid = btn.dataset.sid || '0';
    const scoreEl = document.getElementById(`rscore_${sid}`) || tr.querySelector('.kra-score-badge');
    let score = parseFloat(scoreEl?.textContent) || 0;
    // Only recalculate if 0 ... avoid clobbering a valid score
    if (score === 0) {
        const trigger = tr.querySelector('select[onchange*="calcRow"], select.ri-d1-sel, select.ri-crit, input[oninput*="calcRow"]');
        if (trigger) calcRow(trigger);
        score = parseFloat(scoreEl?.textContent) || 0;
    }

    let remarks = '';
    if (tr.querySelector('.custom-key')) {
        const letter = tr.querySelector('.custom-letter')?.value || activeKraCriterion || '';
        const key    = tr.querySelector('.custom-key')?.value || '';
        const label  = tr.querySelector('.custom-label')?.value || 'Custom criterion';
        const max    = tr.querySelector('.custom-max')?.value || String(score || 0);
        const detail = tr.querySelector('.custom-detail')?.value || '';
        remarks = `CUSTOM|||${letter}|||${key}|||${label}|||${max}|||${detail}`;
    } else if (KRA_CAT === 'Research') {
        const typeEl = tr.querySelector('.rtype');
        // Store the full display text (with pts suffix) so server-side scoring can extract points via regex
        const label  = typeEl?.options[typeEl.selectedIndex]?.text || '';
        const dev    = tr.querySelector('.rdev')?.value || '';
        const aff    = tr.querySelector('.raff')?.value || '';
        const area   = tr.querySelector('.rarea')?.value || '';
        const spec   = tr.querySelector('.rspec')?.value || '';
        const contrib = tr.querySelector('.rcontrib')?.value || '100';
        remarks = `${label}|||${dev}|||${aff}|||${area}|||${spec}|||${contrib}`;
    } else if (KRA_CAT === 'Extension') {
        const subtype = tr.querySelector('.re-crit')?.value || '';
        const title   = tr.querySelector('.re-title')?.value || '';
        const val1    = tr.querySelector('.re-val1')?.value || '';
        const val2    = tr.querySelector('.re-val2')?.value || '';
        remarks = `${subtype}|||${title}|||${val1}|||${val2}`;
    } else if (KRA_CAT === 'Professional Development') {
        const critType = tr.querySelector('.rpd-crit')?.value || '';
        const desc     = tr.querySelector('.rpd-desc')?.value || '';
        const subVal   = tr.querySelector('.rpd-sub')?.value || '0';
        remarks = `${critType}|||${desc}|||${subVal}`;
    } else {
        // KRA I - Instruction
        const critType = tr.querySelector('.ri-crit')?.value || '';
        if (critType === 'A-set-sef') {
            const set   = tr.querySelector('.ri-d1')?.value || '';
            const sef   = tr.querySelector('.ri-d2')?.value || '';
            const notes = tr.querySelector('.ri-notes')?.value || '';
            remarks = `${critType}|||${set}|||${sef}|||${notes}`;
        } else if (critType === 'B-material' || critType.startsWith('B|')) {
            const sel    = tr.querySelector('.ri-d1-sel');
            const parts = critType.split('|');
            const label = critType.startsWith('B|')
                ? (parts.slice(2).join('|') || '')
                : (sel?.options[sel.selectedIndex]?.text || '');
            const contrib = tr.querySelector('.ri-d2')?.value || '100';
            remarks = `${critType}|||${label}|||${contrib}`;
        } else if (critType === 'C-thesis' || critType.startsWith('C|')) {
            const sel   = tr.querySelector('.ri-d1-sel');
            const parts = critType.split('|');
            const label = critType.startsWith('C|')
                ? (parts.slice(2).join('|') || '')
                : (sel?.options[sel.selectedIndex]?.text || '');
            remarks = `${critType}|||${label}|||`;
        } else {
            remarks = `${critType}|||${tr.querySelector('.ri-d1')?.value||''}|||`;
        }
    }

    if (!kraRemarksLookMeaningful(remarks)) {
        showKraAlert('Select a criterion and fill the required score fields before saving or uploading evidence.');
        const firstSelect = tr.querySelector('select');
        if (firstSelect) {
            firstSelect.style.borderColor = '#334155';
            firstSelect.focus();
            setTimeout(() => { firstSelect.style.borderColor = ''; }, 2500);
        }
        return;
    }

    btn.disabled = true;
    btn.innerHTML = '<span class="spinner-border spinner-border-sm"></span>';

    const fd = new FormData();
    fd.append('kra_action', 'save_kra');
    fd.append('kra_category', KRA_CAT);
    fd.append('app_id', APP_ID);
    fd.append('edit_submission_id', parseInt(sid) < 0 ? 0 : sid);
    fd.append('computed_points', score);
    fd.append('remarks', remarks);

    // Check if this row already has a file uploaded (existing entry)
    const upBtn = tr.querySelector('[onclick*="openUploadModal"]');
    const hasExistingFile = upBtn && upBtn.classList.contains('has-file');
    const hasPendingFile  = tr._pendingFile;

    if (!hasExistingFile && !hasPendingFile) {
        btn.disabled = false;
        btn.innerHTML = '<i class="bi bi-floppy"></i>';
        // Highlight upload button
        if (upBtn) {
            upBtn.style.borderColor = '#334155';
            upBtn.style.color = '#334155';
            setTimeout(() => { upBtn.style.borderColor = ''; upBtn.style.color = ''; }, 2500);
        }
        // Show styled inline alert instead of browser alert
        showKraAlert('Evidence file is required before saving. Click the <i class="bi bi-cloud-upload"></i> upload button on this row first.');
        return;
    }

    pendingKraScrollState = captureKraScrollState();
    fetch(AJAX_URL, { method: 'POST', body: fd })
        .then(r => r.json())
        .then(data => {
            btn.disabled = false;
            btn.innerHTML = '<i class="bi bi-floppy"></i>';
            if (data.ok) {
                tr.classList.add('row-saved-flash');
                setTimeout(() => tr.classList.remove('row-saved-flash'), 1200);

                // Update the badge directly from server response ... don't wait for loadRows
                const returnedScore = parseFloat(data.computed_points ?? 0);
                const newSid = data.submission_id || parseInt(sid);
                if (newSid && newSid > 0 && parseInt(sid) < 0) {
                    tr.dataset.sid = newSid;
                }
                const badgeId = newSid > 0 ? `rscore_${newSid}` : `rscore_${sid}`;
                const badge = document.getElementById(badgeId) || tr.querySelector('.kra-score-badge');
                if (badge && returnedScore > 0) {
                    badge.textContent = returnedScore.toFixed(2);
                    badge.className = 'kra-score-badge has-score';
                    tr.dataset.computedPoints = returnedScore;
                }
                updateGrandTotal();
                loadRows();
            } else {
                alert('Error: ' + (data.error || 'Save failed'));
            }
        })
        .catch(() => {
            btn.disabled = false;
            btn.innerHTML = '<i class="bi bi-floppy"></i>';
            alert('Network error. Please try again.');
        });
}

// -- Delete row ------------------------------------------------
function deleteRow(btn) {
    const sid = btn.dataset.sid || '0';
    confirmAction(
        'Remove this entry? This cannot be undone.',
        function() { _executeDeleteRow(btn); },
        'Remove',
        'bi-trash'
    );
}
function _executeDeleteRow(btn) {
    pendingKraScrollState = captureKraScrollState();
    const sid = btn.dataset.sid || '0';
    if (parseInt(sid) < 0) {
        btn.closest('tr').remove();
        updateGrandTotal();
        restoreKraScrollState(pendingKraScrollState);
        if (!document.querySelector('#kraTableBody tr')) loadRows();
        return;
    }
    btn.disabled = true;
    const fd = new FormData();
    fd.append('kra_action', 'delete_kra');
    fd.append('app_id', APP_ID);
    fd.append('submission_id', sid);
    fetch(AJAX_URL, { method: 'POST', body: fd })
        .then(r => r.json())
        .then(() => loadRows())
        .catch(() => { btn.disabled = false; });
}

// -- Upload modal ----------------------------------------------
let uploadModalFileCap = 10;
let uploadModalCurrentFiles = [];

async function openUploadModal(btn) {
    // Block upload if sub-dropdown not selected
    const tr = btn.closest('tr');

    // Criteria A: every semester is its own record. Commit the whole row before
    // the upload so (a) the file attaches to the correct semester record and
    // (b) the post-upload table reload cannot blank the other semester's scores.
    if (tr && tr.classList.contains('kra-a-row')) {
        const original = btn.innerHTML;
        btn.disabled = true;
        btn.innerHTML = '<span class="spinner-border spinner-border-sm"></span>';
        try {
            await persistCriteriaARow(tr, btn.dataset.sem || '1');
        } catch (err) {
            showKraAlert(esc(err.message || 'Unable to prepare this row for upload.'));
            btn.disabled = false;
            btn.innerHTML = original;
            return;
        }
        btn.disabled = false;
        btn.innerHTML = original;
    }

    const subSel = tr?.querySelector('.ri-d1-sel');
    if (subSel && !subSel.value) {
        subSel.style.borderColor = '#334155';
        subSel.focus();
        // Highlight the dropdown red and scroll it into view
        setTimeout(() => { subSel.style.borderColor = ''; }, 2500);
        return;
    }
    if (subSel) subSel.style.borderColor = '';
    currentUploadSid = btn.dataset.sid || '0';
    currentUploadTr  = btn.closest('tr');
    currentUploadBtn = btn;

    const semLabel = isCriteriaATableMode() && btn.dataset.sem
        ? ` - ${btn.dataset.sem === '1' ? '1st' : '2nd'} Semester`
        : '';
    document.getElementById('uploadContext').textContent = `${KRA_CAT} - Row entry${semLabel}`;
    document.getElementById('uploadProgress').style.display = 'none';
    document.getElementById('uploadFileInput').value = '';

    // Reset drop zone
    const dz = document.getElementById('uploadDropZone');
    dz.style.borderColor = '#cbd5e1';
    dz.style.background = '#f8fafc';
    document.getElementById('uploadDropIcon').className = 'bi bi-cloud-arrow-up';
    document.getElementById('uploadDropIcon').style.color = '#94a3b8';
    document.getElementById('uploadDropText').textContent = 'Click or drag file here';

    // Load existing evidence files for this submission from the server
    const sid = parseInt(currentUploadSid);
    if (sid > 0) {
        fetch(`${AJAX_URL}?kra_action=get_entries&app_id=${APP_ID}&cat=${encodeURIComponent(KRA_CAT)}`)
            .then(r => r.json())
            .then(data => {
                if (data.ok) {
                    uploadModalFileCap = data.file_cap || 10;
                    const entry = (data.entries || []).find(e => parseInt(e.submission_id) === sid);
                    uploadModalCurrentFiles = entry ? (entry.evidence_files || []) : [];
                    // Fallback: legacy document_path
                    if (uploadModalCurrentFiles.length === 0 && entry && entry.document_path) {
                        uploadModalCurrentFiles = [{evidence_id: 0, file_path: entry.document_path, original_filename: entry.document_path.split('/').pop(), file_size_bytes: 0}];
                    }
                    renderUploadFileList();
                }
            });
    } else {
        uploadModalFileCap = 10;
        uploadModalCurrentFiles = [];
        renderUploadFileList();
    }

    bootstrap.Modal.getOrCreateInstance(document.getElementById('uploadModal')).show();
}

function renderUploadFileList() {
    const list    = document.getElementById('uploadFileList');
    const count   = document.getElementById('uploadFileCount');
    const capText = document.getElementById('uploadCapText');
    const capInfo = document.getElementById('uploadCapInfo');
    const dz      = document.getElementById('uploadDropZone');

    const n = uploadModalCurrentFiles.length;
    count.textContent = n;
    capText.textContent = `${n} / ${uploadModalFileCap} files uploaded`;

    if (n >= uploadModalFileCap) {
        capInfo.style.background = '#f8fafc';
        capInfo.style.color = '#1a3a6b';
        capInfo.style.borderColor = '#94a3b8';
        dz.style.opacity = '0.5';
        dz.style.pointerEvents = 'none';
        document.getElementById('uploadDropText').textContent = 'File cap reached - remove a file first';
    } else {
        capInfo.style.background = '#f0f4fb';
        capInfo.style.color = '#1a3a6b';
        capInfo.style.borderColor = '#93c5fd';
        dz.style.opacity = '1';
        dz.style.pointerEvents = 'auto';
        document.getElementById('uploadDropText').textContent = 'Click or drag file here';
    }

    if (n === 0) {
        list.innerHTML = `<div style="display:flex;align-items:center;justify-content:center;
                                      padding:1rem;color:#94a3b8;font-size:0.78rem;gap:0.4rem;">
            <i class="bi bi-folder" style="font-size:1rem;"></i> No files uploaded yet
        </div>`;
        return;
    }

    list.innerHTML = uploadModalCurrentFiles.map((f, i) => {
        const fname   = f.original_filename || f.file_path.split('/').pop();
        const ext     = fname.split('.').pop().toLowerCase();
        const icon    = ext === 'pdf' ? 'bi-file-earmark-pdf' : (ext.match(/jpe?g|png/) ? 'bi-file-earmark-image' : 'bi-file-earmark');
        const iconCol = ext === 'pdf' ? '#dc2626' : (ext.match(/jpe?g|png/) ? '#7c3aed' : '#475569');
        const viewBtn = `<a href="backend/api/view_file.php?file=${encodeURIComponent(f.file_path)}" target="_blank"
            rel="noopener noreferrer"
            style="display:inline-flex;align-items:center;justify-content:center;
                   width:28px;height:28px;border-radius:6px;border:1px solid #dbeafe;
                   background:#eff6ff;color:#1a3a6b;font-size:0.75rem;text-decoration:none;"
            title="View file"><i class="bi bi-eye"></i></a>`;
        const delBtn = f.evidence_id > 0
            ? `<button type="button"
                  style="display:inline-flex;align-items:center;justify-content:center;
                         width:28px;height:28px;border-radius:6px;border:1px solid #fecaca;
                         background:#fef2f2;color:#dc2626;font-size:0.75rem;cursor:pointer;"
                  onclick="deleteEvidenceFile(${f.evidence_id}, ${currentUploadSid})" title="Remove file">
                <i class="bi bi-trash"></i>
              </button>`
            : `<span style="width:28px;display:inline-block;"></span>`;
        const isLast = i === uploadModalCurrentFiles.length - 1;
        return `<div style="display:flex;align-items:center;gap:0.6rem;padding:0.55rem 0.75rem;
                             ${isLast ? '' : 'border-bottom:1px solid #f1f5f9;'}">
            <i class="bi ${icon}" style="color:${iconCol};font-size:1rem;flex-shrink:0;"></i>
            <span style="flex:1;min-width:0;font-size:0.77rem;color:#1e293b;
                         overflow:hidden;text-overflow:ellipsis;white-space:nowrap;"
                  title="${esc(fname)}">${esc(fname)}</span>
            <div style="display:flex;gap:4px;flex-shrink:0;">${viewBtn}${delBtn}</div>
        </div>`;
    }).join('');
}

function deleteEvidenceFile(evidenceId, submissionId) {
    confirmAction(
        'Remove this evidence file? This cannot be undone.',
        function() { _executeDeleteEvidenceFile(evidenceId, submissionId); },
        'Remove File',
        'bi-trash'
    );
}

function _executeDeleteEvidenceFile(evidenceId, submissionId) {
    pendingKraScrollState = captureKraScrollState();
    const fd = new FormData();
    fd.append('kra_action', 'delete_evidence');
    fd.append('app_id', APP_ID);
    fd.append('evidence_id', evidenceId);
    fd.append('submission_id', submissionId);
    fetch(AJAX_URL, { method: 'POST', body: fd })
        .then(r => r.json())
        .then(data => {
            if (data.ok) {
                uploadModalCurrentFiles = uploadModalCurrentFiles.filter(f => f.evidence_id !== evidenceId);
                renderUploadFileList();
                const uploadRemarks = _buildRemarksFromRow(currentUploadTr);
                const uploadCriterion = currentUploadTr?.classList.contains('kra-a-row')
                    ? 'A'
                    : criterionLetterFromEntry({ remarks: uploadRemarks });
                if (uploadCriterion) setKraModalCriterion(uploadCriterion);
                pendingScrollToSid = parseInt(currentUploadSid) || parseInt(submissionId) || 0;
                loadRows();
            } else {
                alert('Failed to delete file: ' + (data.error || 'Unknown error'));
            }
        });
}


function handleDrop(event) {
    event.preventDefault();
    const dz = document.getElementById('uploadDropZone');
    dz.style.borderColor = '#cbd5e1';
    dz.style.background = '#f8fafc';
    const files = event.dataTransfer.files;
    if (files && files.length > 0) processUploadFiles(files);
}

function handleFileSelect(input) {
    if (input.files && input.files.length > 0) processUploadFiles(input.files);
    // Reset so same files can be re-selected if needed
    input.value = '';
}

/**
 * Batch upload: accepts a FileList or Array of File objects.
 * Files are validated first, then uploaded sequentially so the
 * submission_id is stable after the first file creates the row.
 */
async function processUploadFiles(fileList) {
    const files   = Array.from(fileList);
    const allowed = ['pdf', 'jpg', 'jpeg', 'png'];

    // -- Validate all files before starting ------------------
    const invalid = [];
    const tooBig  = [];
    files.forEach(f => {
        const ext = f.name.split('.').pop().toLowerCase();
        if (!allowed.includes(ext)) invalid.push(f.name);
        else if (f.size > 50 * 1024 * 1024) tooBig.push(f.name);
    });
    if (invalid.length) {
        showKraAlert(`<strong>Invalid file type</strong> ... only PDF, JPG, PNG allowed:<br>${invalid.map(n=>`<code>${esc(n)}</code>`).join(', ')}`);
        return;
    }
    if (tooBig.length) {
        showKraAlert(`<strong>File too large</strong> (max 50 MB each):<br>${tooBig.map(n=>`<code>${esc(n)}</code>`).join(', ')}`);
        return;
    }

    // -- Cap check --------------------------------------------
    const remaining = uploadModalFileCap - uploadModalCurrentFiles.length;
    if (remaining <= 0) {
        showKraAlert(`File cap reached (${uploadModalFileCap} files max). Remove a file first.`);
        return;
    }
    const batch = files.slice(0, remaining);
    if (batch.length < files.length) {
        showKraAlert(`Only ${remaining} slot(s) left ... uploading first ${batch.length} of ${files.length} file(s).`);
    }

    // -- Show progress bar ------------------------------------
    const progressEl = document.getElementById('uploadProgress');
    const capText    = document.getElementById('uploadCapText');
    progressEl.style.display = 'block';
    progressEl.innerHTML = `
        <div class="d-flex align-items-center gap-2 mb-1">
            <div class="spinner-border spinner-border-sm text-primary"></div>
            <span id="batchStatusText" style="font-size:0.82rem;color:#475569;">Uploading 0 / ${batch.length}...</span>
        </div>
        <div class="progress" style="height:6px;">
            <div id="batchProgressBar" class="progress-bar bg-primary" style="width:0%;transition:width 0.3s;"></div>
        </div>`;

    // -- Build score + remarks once (shared for all files in this batch) --
    const tr      = currentUploadTr;
    const score   = _computeScoreFromRow(tr);
    const remarks = _buildRemarksFromRow(tr);
    // Skip the remarks check when the entry is already saved — sid > 0 means the
    // data is already in the DB and a client-side parse failure should not block
    // the upload. Only enforce it for brand-new (unsaved) rows.
    const isSavedEntry = parseInt(currentUploadSid) > 0;
    if (!isSavedEntry && !kraRemarksLookMeaningful(remarks)) {
        progressEl.style.display = 'none';
        showKraAlert('Select a criterion and fill the required score fields before uploading evidence.');
        const firstSelect = tr?.querySelector('select');
        if (firstSelect) {
            firstSelect.style.borderColor = '#334155';
            firstSelect.focus();
            setTimeout(() => { firstSelect.style.borderColor = ''; }, 2500);
        }
        return;
    }

    // -- Upload sequentially ----------------------------------
    let uploaded = 0;
    let errors   = [];

    for (const file of batch) {
        const statusEl = document.getElementById('batchStatusText');
        const barEl    = document.getElementById('batchProgressBar');
        if (statusEl) statusEl.textContent = `Uploading ${uploaded + 1} / ${batch.length}: ${file.name}`;
        if (barEl) barEl.style.width = Math.round((uploaded / batch.length) * 100) + '%';

        const fd = new FormData();
        fd.append('kra_action',          'save_kra');
        fd.append('kra_category',        KRA_CAT);
        fd.append('app_id',              APP_ID);
        fd.append('edit_submission_id',  parseInt(currentUploadSid) < 0 ? 0 : currentUploadSid);
        fd.append('computed_points',     score);
        fd.append('remarks',             remarks);
        fd.append('evidence',            file);

        try {
            const resp = await fetch(AJAX_URL, { method: 'POST', body: fd });
            const data = await resp.json();

            if (data.ok) {
                // After the first file creates the row, subsequent files attach to same submission_id
                if (data.submission_id && parseInt(currentUploadSid) <= 0) {
                    currentUploadSid = String(data.submission_id);
                    if (tr) {
                        if (tr.classList.contains('kra-a-row') && currentUploadBtn) {
                            currentUploadBtn.dataset.sid = data.submission_id;
                            if (currentUploadBtn.dataset.sem === '1') tr.dataset.firstSid = data.submission_id;
                            if (currentUploadBtn.dataset.sem === '2') tr.dataset.secondSid = data.submission_id;
                        } else {
                            tr.dataset.sid = data.submission_id;
                            tr.querySelectorAll('[data-sid]').forEach(el => el.dataset.sid = data.submission_id);
                            const oldScore = tr.querySelector('.kra-score-badge');
                            if (oldScore) oldScore.id = `rscore_${data.submission_id}`;
                        }
                    }
                }
                // Keep local file list in sync so cap check stays accurate
                uploadModalCurrentFiles = data.evidence_files || [];
                uploadModalFileCap      = data.file_cap || 10;

                // Update row score badge from server response
                const returnedScore = parseFloat(data.computed_points ?? 0);
                if (returnedScore > 0 && tr) {
                    const badge = document.getElementById(`rscore_${currentUploadSid}`) || tr.querySelector('.kra-score-badge');
                    if (badge) { badge.textContent = returnedScore.toFixed(2); badge.className = 'kra-score-badge has-score'; }
                    tr.dataset.computedPoints = returnedScore;
                    updateGrandTotal();
                }
                if (tr) tr._pendingFile = true;

                uploaded++;
            } else {
                errors.push(`${file.name}: ${data.error || 'Unknown error'}`);
            }
        } catch (e) {
            errors.push(`${file.name}: Network error`);
        }
    }

    // -- Complete ---------------------------------------------
    if (document.getElementById('batchProgressBar')) {
        document.getElementById('batchProgressBar').style.width = '100%';
    }
    await new Promise(r => setTimeout(r, 300));
    progressEl.style.display = 'none';
    // Restore original progress HTML for next open
    progressEl.innerHTML = `
        <div class="d-flex align-items-center gap-2 mb-1">
            <div class="spinner-border spinner-border-sm text-primary"></div>
            <span style="font-size:0.82rem;color:#475569;">Uploading...</span>
        </div>
        <div class="progress" style="height:6px;">
            <div class="progress-bar progress-bar-striped progress-bar-animated" style="width:100%;"></div>
        </div>`;

    renderUploadFileList();

    // Update the upload button in the KRA table row
    if (tr && uploaded > 0) {
        const upBtn = tr.classList.contains('kra-a-row') ? currentUploadBtn : tr.querySelector('[onclick*="openUploadModal"]');
        if (upBtn) {
            upBtn.className = 'btn btn-sm btn-outline-success kra-upload-btn has-file';
            upBtn.querySelector('i').className = 'bi bi-file-earmark-check-fill';
            const totalFiles = uploadModalCurrentFiles.length;
            upBtn.title = `${totalFiles} file(s) uploaded ... click to manage`;
            upBtn.dataset.doc = uploadModalCurrentFiles[0]?.file_path || '';
            upBtn.dataset.sid = currentUploadSid;
            // Show count badge if more than 1
            const existingBadge = upBtn.querySelector('.badge');
            if (existingBadge) existingBadge.remove();
            if (totalFiles > 1) {
                const span = document.createElement('span');
                span.className = 'badge bg-light text-dark';
                span.style.fontSize = '0.65rem';
                span.textContent = totalFiles;
                upBtn.appendChild(span);
            }
        }
    }

    if (uploaded > 0) showUploadSuccess(uploaded === 1 ? batch[0].name : `${uploaded} files uploaded`);
    if (errors.length) showKraAlert(`<strong>${errors.length} file(s) failed:</strong><br>${errors.map(e => esc(e)).join('<br>')}`);

    // Do NOT call loadRows() here — it would wipe the locked criterion state
    // from the current row (pendingKraResCrit was already consumed when the row
    // was built, so a reload renders "Select" instead of the locked label).
    // The evidence button and score badge are already updated in-place above.
    // We only reload if this was a Criteria-A semester row (which manages its
    // own state via criteriaADraftCache) or if the entire table was empty
    // (sid went from ≤0 to a real id, meaning a new row was just created).
    const wasNewRow = uploaded > 0 && parseInt(currentUploadSid) > 0 && tr && !tr.classList.contains('kra-a-row');
    if (isCriteriaATableMode()) {
        const uploadRemarks   = _buildRemarksFromRow(tr);
        const uploadCriterion = 'A';
        setKraModalCriterion(uploadCriterion);
        criteriaADraftCache   = snapshotCriteriaADraft();
        pendingKraScrollState = captureKraScrollState();
        pendingScrollToSid    = parseInt(currentUploadSid) || 0;
        loadRows();
    }
    // For non-Criteria-A rows: just keep the modal open with the updated file list.
    // The row in the table behind is already correct (score + button updated above).
}

// -- Helpers: extract score and remarks from the current row --
function _computeScoreFromRow(tr) {
    if (!tr) return 0;
    if (tr.classList.contains('kra-a-row')) {
        const sem = currentUploadBtn?.dataset.sem || '1';
        return criteriaAScoreFromRow(tr, sem);
    }
    let score = 0;
    if (tr.querySelector('.custom-key')) {
        score = parseFloat(tr.querySelector('.custom-max')?.value) || 0;
    } else if (KRA_CAT === 'Research') {
        const typeEl  = tr.querySelector('.rtype');
        const label   = typeEl?.options[typeEl?.selectedIndex]?.text || '';
        const contrib = parseFloat(tr.querySelector('.rcontrib')?.value) || 100;
        const m = label.match(/\((\d+(?:\.\d+)?)\s*pts?\)/i);
        score = m ? parseFloat(m[1]) * (contrib / 100) : 0;
    } else if (KRA_CAT === 'Extension') {
        const subtype = tr.querySelector('.re-crit')?.value || '';
        const val1    = tr.querySelector('.re-val1')?.value || '';
        const val2    = tr.querySelector('.re-val2')?.value || '';
        score = extScoreForSubtype(subtype, val1, val2);
    } else if (KRA_CAT === 'Professional Development') {
        const ct = tr.querySelector('.rpd-crit')?.value || '';
        const sv = parseFloat(tr.querySelector('.rpd-sub')?.value) || 0;
        score = (ct === 'A-org') ? 5.0 : sv;
    } else {
        const critType = tr.querySelector('.ri-crit')?.value || '';
        if (critType === 'A-set-sef') {
            const set = parseFloat(tr.querySelector('.ri-d1')?.value) || 0;
            const sef = parseFloat(tr.querySelector('.ri-d2')?.value) || 0;
            score = (set / 100) * KRA1_A_SET_CAP + (sef / 100) * KRA1_A_SEF_CAP;
        } else if (critType === 'B-material' || critType.startsWith('B|')) {
            const parts = critType.split('|');
            const pts = parts[1] || '';
            const contrib = parseFloat(tr.querySelector('.ri-d2')?.value) || 100;
            score = pts.endsWith('co') ? parseFloat(pts.replace('co','')) * (contrib / 100) : (parseFloat(pts) || 0);
        } else if (critType === 'C-thesis' || critType.startsWith('C|')) {
            score = parseFloat(critType.split('|')[1] ?? '0') || 0;
        }
    }
    // Fallbacks
    if (score === 0) {
        const badge = document.getElementById(`rscore_${currentUploadSid}`) || tr.querySelector('.kra-score-badge');
        score = parseFloat(badge?.textContent) || 0;
    }
    if (score === 0 && tr.dataset.computedPoints) score = parseFloat(tr.dataset.computedPoints) || 0;
    return score;
}

function kraRemarksLookMeaningful(remarks) {
    const parts = String(remarks || '').split('|||').map(p => p.trim());
    const type = parts[0] || '';
    if (!type) return false;
    if (type === 'CUSTOM') return !!parts[1] && !!parts[2] && !!parts[3] && (parseFloat(parts[4] || '0') > 0);
    if (KRA_CAT === 'Instruction') {
        if (type === 'A-set-sef') return !!parts[1] && !!parts[2];
        if (type === 'A-set-sef-sem') return !!parts[3] && !!parts[4];
        if (type === 'A-set-sem' || type === 'A-sef-sem') return !!parts[1] && !!parts[2] && !!parts[3];
        return type.startsWith('B|') || type.startsWith('C|') || ['B-material', 'C-thesis', 'C-mentor'].includes(type);
    }
    if (KRA_CAT === 'Research') return type.startsWith('Criterion') || /\(\d+(?:\.\d+)?\s*pts?\)/i.test(type);
    if (KRA_CAT === 'Extension') {
        return ALL_EXT_CRIT_OPTS.some(([, value]) => value === type);
    }
    if (KRA_CAT === 'Professional Development') return ['A-org', 'B-training', 'B-paper', 'B-degree', 'C-award'].includes(type);
    return false;
}

function _buildRemarksFromRow(tr) {
    if (!tr) return '';
    if (tr.classList.contains('kra-a-row')) {
        const sem = currentUploadBtn?.dataset.sem || '1';
        return criteriaARemarksFromRow(tr, sem);
    }
    if (tr.querySelector('.custom-key')) {
        const letter = tr.querySelector('.custom-letter')?.value || activeKraCriterion || '';
        const key    = tr.querySelector('.custom-key')?.value || '';
        const label  = tr.querySelector('.custom-label')?.value || 'Custom criterion';
        const max    = tr.querySelector('.custom-max')?.value || String(_computeScoreFromRow(tr) || 0);
        const detail = tr.querySelector('.custom-detail')?.value || '';
        return `CUSTOM|||${letter}|||${key}|||${label}|||${max}|||${detail}`;
    }
    if (KRA_CAT === 'Research') {
        const typeEl = tr.querySelector('.rtype');
        const label  = typeEl?.options[typeEl.selectedIndex]?.text || '';
        const dev    = tr.querySelector('.rdev')?.value || '';
        const aff    = tr.querySelector('.raff')?.value || '';
        const area   = tr.querySelector('.rarea')?.value || '';
        const spec   = tr.querySelector('.rspec')?.value || '';
        const contrib = tr.querySelector('.rcontrib')?.value || '100';
        return `${label}|||${dev}|||${aff}|||${area}|||${spec}|||${contrib}`;
    }
    if (KRA_CAT === 'Extension') {
        const subtype = tr.querySelector('.re-crit')?.value || '';
        const title   = tr.querySelector('.re-title')?.value || '';
        const val1    = tr.querySelector('.re-val1')?.value || '';
        const val2    = tr.querySelector('.re-val2')?.value || '';
        return `${subtype}|||${title}|||${val1}|||${val2}`;
    }
    if (KRA_CAT === 'Professional Development') {
        const ct  = tr.querySelector('.rpd-crit')?.value || '';
        const dsc = tr.querySelector('.rpd-desc')?.value || '';
        const sv  = tr.querySelector('.rpd-sub')?.value  || '0';
        return `${ct}|||${dsc}|||${sv}`;
    }
    // KRA I ... Instruction
    const critType = tr.querySelector('.ri-crit')?.value || '';
    if (critType === 'A-set-sef') {
        return `${critType}|||${tr.querySelector('.ri-d1')?.value||''}|||${tr.querySelector('.ri-d2')?.value||''}|||`;
    }
    if (critType === 'B-material' || critType.startsWith('B|')) {
        // Flat: store the full composite value as critType, label from value, contrib from ri-d2
        const parts = critType.split('|');
        const label = parts.slice(2).join('|') || '';
        const contrib = tr.querySelector('.ri-d2')?.value || '100';
        return `${critType}|||${label}|||${contrib}`;
    }
    if (critType === 'C-thesis' || critType.startsWith('C|')) {
        const parts = critType.split('|');
        const label = parts.slice(2).join('|') || '';
        return `${critType}|||${label}|||`;
    }
    return `${critType}|||${tr.querySelector('.ri-d1')?.value||''}|||`;
}

const kraHeadRow = document.querySelector('#kraTable thead tr');
const kraFootRow = document.querySelector('#kraTable tfoot tr');
if (kraHeadRow && !kraHeadRow.dataset.defaultHtml) kraHeadRow.dataset.defaultHtml = kraHeadRow.innerHTML;
if (kraFootRow && !kraFootRow.dataset.defaultHtml) kraFootRow.dataset.defaultHtml = kraFootRow.innerHTML;

const kraAutosaveTimers = new WeakMap();
function queueGenericKraAutosave(target) {
    const tr = target?.closest?.('tr[data-sid]');
    if (!tr || tr.classList.contains('kra-a-row')) return;
    tr.dataset.dirty = '1';
    if (kraAutosaveTimers.has(tr)) clearTimeout(kraAutosaveTimers.get(tr));
    const timer = setTimeout(() => {
        persistGenericKraRow(tr).catch(() => {
            tr.dataset.dirty = '1';
        });
    }, 800);
    kraAutosaveTimers.set(tr, timer);
}

document.getElementById('kraTableBody')?.addEventListener('input', function(e) {
    if (e.target.matches('input, textarea, select')) queueGenericKraAutosave(e.target);
});
document.getElementById('kraTableBody')?.addEventListener('change', function(e) {
    if (e.target.matches('input, textarea, select')) queueGenericKraAutosave(e.target);
});

document.getElementById('kraEntryModal').addEventListener('hidden.bs.modal', function() {
    // Reset criterion state on close so the next criterion opens clean.
    setKraModalCriterion('');
    skipNextModalLoad = false;
    criteriaARequestToken++;
    genericRowsRequestToken++;
    const _tb = document.getElementById('kraTableBody');
    if (_tb) _tb.innerHTML = '';
    pendingCriteriaAEditEntry = null;
    pendingLegacyCriteriaAEditEntry = null;
    criteriaADraftCache = null;
    // Clear focus type and restore both sections so the next open shows both.
    pendingCriteriaAFocusType = null;
    pendingKraFlatCrit = null;
    pendingKraResCrit  = null;
    pendingKraExtSubtype = null;
    pendingKraPdCrit   = null;
    applyCriteriaAFocus();
    setKraTableHeaderForDefault();
});

document.getElementById('kraEntryModal').addEventListener('show.bs.modal', function() {
    if (skipNextModalLoad) {
        skipNextModalLoad = false;
        return;
    }
    loadRows();
});

// Global var to track which entry to scroll to after loadRows completes
let pendingScrollToSid = 0;

function openEditModal(submissionId) {
    if (deferUntilKraModalClosed(() => openEditModal(submissionId))) return;
    // Get entry data from the button's data-entry attribute
    const btn = document.getElementById('editBtn_' + submissionId);
    let entryData = null;
    try {
        entryData = btn ? JSON.parse(btn.getAttribute('data-entry') || '{}') : null;
    } catch (_) {
        entryData = null;
    }
    const critType = critTypeFromRemarks(entryData?.remarks || '');
    const isSemesterCriteriaA = ['A-set-sef-sem', 'A-set-sem', 'A-sef-sem'].includes(critType);
    const isLegacyCriteriaA = critType === 'A-set-sef';

    // Older A records contain SET, SEF, and optional notes in one entry. Keep
    // them in the standard editor so opening the modal cannot discard notes.
    setKraModalCriterion(isSemesterCriteriaA ? 'A' : '');

    const modal = document.getElementById('kraEntryModal');
    if (!modal) return;

    const tbody = document.getElementById('kraTableBody');

    if (entryData && isSemesterCriteriaA) {
        pendingCriteriaAEditEntry = entryData;
        pendingScrollToSid = submissionId;
        // Focus only the section that matches this entry's type (SET or SEF).
        if (critType === 'A-set-sem') pendingCriteriaAFocusType = 'set';
        else if (critType === 'A-sef-sem') pendingCriteriaAFocusType = 'sef';
        else pendingCriteriaAFocusType = null; // A-set-sef-sem has both  -  show both
        // Bootstrap's show event can fire before the criterion-specific state
        // is applied. Load the Criteria A grid directly to avoid its default
        // empty table replacing the saved semester rows.
        skipNextModalLoad = true;
        setKraTableHeaderForCriteriaA();
        const bsModal = bootstrap.Modal.getOrCreateInstance(modal);
        bsModal.show();
        loadCriteriaARows();
        return;
    }

    // From here on we are NOT in the semester grid, so the Criteria A header,
    // footer and leftover rows have to be reverted first. Without this the
    // Criterion B / C editor opens underneath the Criteria A layout.
    setKraTableHeaderForDefault();

    // Let the standard loader render the complete Instruction list for older
    // Criteria A entries, then bring the selected entry into view.
    if (entryData && isLegacyCriteriaA) {
        pendingLegacyCriteriaAEditEntry = entryData;
        pendingScrollToSid = submissionId;
        const bsModal = bootstrap.Modal.getOrCreateInstance(modal);
        bsModal.show();
        return;
    }

    if (entryData && entryData.submission_id) {
        // Clear the table and inject this single entry directly - no AJAX needed
        tbody.innerHTML = '';
        const emptyRow = document.getElementById('kraEmptyRow');
        if (emptyRow) emptyRow.remove();

        const tr = document.createElement('tr');
        tr.dataset.sid = entryData.submission_id;
        tr.dataset.computedPoints = entryData.computed_points || 0;
        tr.innerHTML = buildRow(entryData, 1);
        tbody.appendChild(tr);

        // Recalculate score from dropdowns
        const subSel = tr.querySelector('select.ri-d1-sel');
        const mainSel = tr.querySelector('select.ri-crit, select.rtype, select.rpd-crit, select.re-crit');
        const trigger = subSel || mainSel;
        if (trigger) calcRow(trigger);

        updateGrandTotal();

        // Open modal
        skipNextModalLoad = true;
        const bsModal = bootstrap.Modal.getOrCreateInstance(modal);
        bsModal.show();

        // Highlight the row after modal opens
        setTimeout(() => {
            tr.style.transition = 'background 0.3s';
            tr.style.background = '#fffbeb';
            setTimeout(() => { tr.style.background = ''; }, 2000);
        }, 300);
    } else {
        // Fallback: open modal normally and let loadRows run
        pendingScrollToSid = submissionId;
        const bsModal = bootstrap.Modal.getOrCreateInstance(modal);
        bsModal.show();
    }
}

// Helper function to scroll to and highlight a specific entry row
function scrollToEntry(submissionId) {
    const tbody = document.getElementById('kraTableBody');
    const target = tbody?.querySelector(`tr[data-sid="${submissionId}"]`);
    if (target) {
        target.scrollIntoView({ behavior: 'smooth', block: 'center' });
        target.style.transition = 'background 0.3s';
        target.style.background = '#fffbeb';
        target.style.border = '2px solid #fbbf24';
        setTimeout(() => { 
            target.style.background = ''; 
            target.style.border = '';
        }, 2500);
    }
}

function showKraAlert(msg) {
    const box = document.getElementById('kraAlertBox');
    const txt = document.getElementById('kraAlertMsg');
    if (!box || !txt) return;
    txt.innerHTML = msg;
    box.style.display = 'flex';
    setTimeout(() => { box.style.display = 'none'; }, 6000);
}

function toggleCriteria(btn) {
    const rows = document.querySelectorAll('.criteria-extra');
    const expanded = rows[0]?.style.display !== 'none';
    rows.forEach(r => r.style.display = expanded ? 'none' : '');
    btn.innerHTML = expanded
        ? 'Show all <?= count($criteria_list) ?> criteria'
        : 'Show less';
}
</script>
