<?php
if (!isAdmin()) { echo '<div class="alert alert-danger">Access denied.</div>'; return; }

// -- Runtime migrations: scoring_criteria schema -------------------------
try { $pdo->query("SELECT cycle_id FROM scoring_criteria LIMIT 1"); }
catch (\Exception $e) {
    $pdo->exec("ALTER TABLE scoring_criteria ADD COLUMN cycle_id INT DEFAULT NULL AFTER criteria_id");
    try { $pdo->exec("ALTER TABLE scoring_criteria DROP INDEX criterion_key"); } catch (\Exception $e2) {}
    try { $pdo->exec("ALTER TABLE scoring_criteria ADD UNIQUE KEY uq_cycle_criterion (cycle_id, criterion_key)"); } catch (\Exception $e2) {}
    try { $pdo->exec("ALTER TABLE scoring_criteria ADD CONSTRAINT fk_sc_cycle FOREIGN KEY (cycle_id) REFERENCES cycles(cycle_id) ON DELETE CASCADE"); } catch (\Exception $e2) {}
}
try { $pdo->query("SELECT position_rank FROM scoring_criteria LIMIT 1"); }
catch (\Exception $e) {
    $pdo->exec("ALTER TABLE scoring_criteria ADD COLUMN position_rank VARCHAR(100) DEFAULT NULL AFTER cycle_id");
    try { $pdo->exec("ALTER TABLE scoring_criteria DROP INDEX uq_cycle_criterion"); } catch (\Exception $e2) {}
    try { $pdo->exec("ALTER TABLE scoring_criteria ADD UNIQUE KEY uq_cycle_pos_criterion (cycle_id, position_rank, criterion_key)"); } catch (\Exception $e2) {}
}

$kra_list        = ['Instruction','Research','Extension','Professional Development'];
$open_cycle = getActiveCycle($pdo);
$active_cycle_id = array_key_exists('cycle_id', $_GET) ? (int)$_GET['cycle_id'] : (int)($open_cycle['cycle_id'] ?? 0);
$active_position = trim($_GET['position'] ?? '');
$cycles = $pdo->query('SELECT cycle_id,cycle_name,status FROM cycles ORDER BY created_at DESC')->fetchAll();
$current_cycle = null;
foreach ($cycles as $cy) if ((int)$cy['cycle_id'] === $active_cycle_id) $current_cycle = $cy;
$templates_mode = $active_cycle_id === 0;
if ($templates_mode) $active_position = '';
$closed_scope = !$templates_mode && (!$current_cycle || $current_cycle['status'] !== 'open');
$editing_enabled = $templates_mode && !$open_cycle;
$show_inactive = ($_GET['show_inactive'] ?? '') === '1';
if (empty($_SESSION['criteria_csrf'])) $_SESSION['criteria_csrf'] = bin2hex(random_bytes(32));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (!hash_equals($_SESSION['criteria_csrf'], (string)($_POST['csrf_token'] ?? ''))) throw new RuntimeException('This form has expired. Refresh the page and try again.');
        $action = (string)($_POST['action'] ?? '');
        $result = CriteriaSnapshots::save($pdo,(int)$_SESSION['user_id'],$action,$_POST);
        $message = $result['changed'] ? 'Criteria saved as a new version. All faculty members have been notified.' : 'No criteria changes were detected.';
        if ($action === 'delete_criterion') {
            $message = $result['changed'] ? 'Criterion deactivated. It is now hidden from active lists.' : 'This criterion is already inactive.';
        }
        flashMessage('success', $message);
        $redir = 'index.php?page=scoring_criteria&cycle_id=0';
    } catch (Throwable $e) {
        flashMessage('danger',$e instanceof PDOException ? 'Criteria could not be saved. Check for duplicate keys or labels and try again.' : htmlspecialchars($e->getMessage()));
        $redir = 'index.php?page=scoring_criteria&cycle_id=' . $active_cycle_id . ($active_position ? '&position=' . rawurlencode($active_position) : '');
    }
    echo '<script>window.location.href=' . json_encode($redir) . ';</script>'; exit;
}

$cycle_criteria = [];
$criteria_source = $templates_mode ? 'templates' : 'overview';
if ($templates_mode) {
    $cycle_criteria = $pdo->query('SELECT * FROM scoring_criteria WHERE cycle_id IS NULL AND position_rank IS NULL ORDER BY kra_category,criteria_id')->fetchAll();
} elseif ($active_position) {
    $q = $pdo->prepare('SELECT * FROM scoring_criteria WHERE cycle_id=? AND position_rank=? ORDER BY kra_category,criteria_id');
    $q->execute([$active_cycle_id,$active_position]);
    $cycle_criteria = $q->fetchAll();
    $criteria_source = 'cycle_position';
}
$parentOptions = [];
$referenceCounts = $editing_enabled ? CriteriaSnapshots::defaultReferenceCounts($pdo) : [];
$activeParents = CriteriaSnapshots::parents($pdo, $active_cycle_id, $active_position ?: null);
foreach ($activeParents as $parent) {
    $parentOptions[$parent['kra_category']][] = [
        'id' => (int)$parent['criteria_id'],
        'label' => criteriaParentCode($parent) . ' - ' . $parent['criterion_label'],
    ];
}
$activeIds = array_fill_keys(array_column($activeParents, 'criteria_id'), true);
if ($cycle_criteria) {
    foreach (CriteriaSnapshots::rows($pdo, $active_cycle_id, $active_position ?: null) as $criterion) {
        $activeIds[$criterion['criteria_id']] = true;
    }
}
$grouped = [];
$inactiveCount = 0;
foreach ($cycle_criteria as $c) {
    if (!isset($activeIds[$c['criteria_id']])) {
        $inactiveCount++;
        if (!$show_inactive) continue;
    }
    $grouped[$c['kra_category']][] = $c;
}
$position_counts = [];
if ($active_cycle_id) {
    $q = $pdo->prepare('SELECT criterion.position_rank, COUNT(*) AS cnt
        FROM scoring_criteria criterion
        LEFT JOIN scoring_criteria parent ON parent.criteria_id=criterion.parent_id
        WHERE criterion.cycle_id=? AND criterion.is_active=1
          AND (criterion.parent_id IS NULL OR parent.is_active=1)
        GROUP BY criterion.position_rank');
    $q->execute([$active_cycle_id]);
    $position_counts = $q->fetchAll(PDO::FETCH_KEY_PAIR);
}
$q = $pdo->prepare('SELECT revision,change_summary,created_at FROM criteria_snapshots WHERE scope_id=? ORDER BY revision DESC LIMIT 5');
$q->execute([$active_cycle_id]);
$criteria_history = $q->fetchAll();
$historyQuery = ['page' => 'scoring_criteria', 'cycle_id' => $active_cycle_id];
if ($active_position !== '') $historyQuery['position'] = $active_position;
if ($show_inactive) $historyQuery['show_inactive'] = 1;
$versionUrl = static fn(int $revision): string => 'index.php?' . http_build_query($historyQuery + ['revision' => $revision]) . '#criteria-version-details';
$requestedRevision = filter_var($_GET['revision'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
$version = null;
$versionError = '';
if ($requestedRevision) {
    try {
        $version = CriteriaSnapshots::versionDetails($pdo, $active_cycle_id, $requestedRevision);
        if (!$version) $versionError = 'This version is unavailable for the selected criteria.';
    } catch (Throwable $error) {
        error_log('SUCFRMS criteria version details: ' . $error->getMessage());
        $versionError = 'The saved criteria could not be read.';
    }
}
$rank_groups = [
    'Instructor'   => ['Instructor I','Instructor II','Instructor III'],
    'Asst. Prof.'  => ['Assistant Professor I','Assistant Professor II','Assistant Professor III','Assistant Professor IV'],
    'Assoc. Prof.' => ['Associate Professor I','Associate Professor II','Associate Professor III','Associate Professor IV','Associate Professor V'],
    'Professor'    => ['Professor I','Professor II','Professor III','Professor IV','Professor V','Professor VI'],
    'Univ. Prof.'  => ['University Professor'],
];
?>

<?php showFlash(); ?>

<?php
// Banner config
if ($open_cycle) {
    $banner_icon    = 'bi-lock';
    $banner_color   = '#92400e';
    $banner_bg      = '#fffbeb';
    $banner_border  = '#fde68a';
    $banner_accent  = '#d97706';
    $banner_title   = 'Criteria are read-only';
    $banner_body    = 'Criteria cannot be edited while a cycle is open. Once it closes, you can edit the defaults for future cycles.';
} elseif ($closed_scope) {
    $banner_icon    = 'bi-lock';
    $banner_color   = '#475569';
    $banner_bg      = '#f8fafc';
    $banner_border  = '#cbd5e1';
    $banner_accent  = '#64748b';
    $banner_title   = 'Read-only — historical cycle';
    $banner_body    = 'This cycle keeps its own saved criteria version and cannot be modified. Switch to <strong>Default criteria</strong> to configure future cycles.';
} else {
    $banner_icon    = 'bi-pencil-square';
    $banner_color   = '#1e4d8c';
    $banner_bg      = '#f0f4fb';
    $banner_border  = '#dbeafe';
    $banner_accent  = '#1a3a6b';
    $banner_title   = 'Default criteria — no active cycle';
    $banner_body    = 'These defaults can be edited freely. Existing and closed cycles retain their own versions. All faculty members are notified when changes are saved.';
}
?>
<div style="background:<?= $banner_bg ?>;border:1px solid <?= $banner_border ?>;border-left:3px solid <?= $banner_accent ?>;
            padding:0.65rem 1rem;margin-bottom:1rem;display:flex;align-items:center;gap:0.75rem;flex-wrap:wrap;">
    <div style="width:30px;height:30px;background:<?= $banner_accent ?>;display:flex;align-items:center;justify-content:center;flex-shrink:0;">
        <i class="<?= $banner_icon ?>" style="color:#fff;font-size:0.82rem;"></i>
    </div>
    <div style="flex:1;min-width:0;">
        <div style="font-size:0.75rem;font-weight:700;color:<?= $banner_color ?>;line-height:1.3;"><?= $banner_title ?></div>
        <div style="font-size:0.72rem;color:<?= $banner_color ?>;opacity:0.85;margin-top:1px;line-height:1.4;"><?= $banner_body ?></div>
    </div>
</div>
<?php if ($criteria_history): ?>
<details class="criteria-history" id="criteria-history"<?= $requestedRevision ? ' open' : '' ?>>
    <summary>
        <span class="criteria-history-icon" aria-hidden="true"><i class="bi bi-clock-history"></i></span>
        <span class="criteria-history-title">Saved criteria versions</span>
        <span class="criteria-history-current">Current version <?= (int)$criteria_history[0]['revision'] ?></span>
        <i class="bi bi-chevron-down criteria-history-chevron" aria-hidden="true"></i>
    </summary>
    <ul class="criteria-history-list">
        <?php foreach ($criteria_history as $revision): $revisionNumber = (int)$revision['revision']; ?>
        <li>
            <strong>Version <?= $revisionNumber ?></strong>
            <span><?= htmlspecialchars($revision['change_summary']) ?></span>
            <time datetime="<?= htmlspecialchars(str_replace(' ', 'T', $revision['created_at'])) ?>"><?= htmlspecialchars($revision['created_at']) ?></time>
            <a class="criteria-history-view" href="<?= htmlspecialchars($versionUrl($revisionNumber)) ?>"
               data-version-revision="<?= $revisionNumber ?>" aria-haspopup="dialog"
               title="View version <?= $revisionNumber ?> details" aria-label="View version <?= $revisionNumber ?> details"<?= $revisionNumber === $requestedRevision ? ' aria-current="true"' : '' ?>>
                <i class="bi bi-eye" aria-hidden="true"></i>
            </a>
        </li>
        <?php endforeach; ?>
    </ul>
</details>
<dialog class="criteria-version-popup" id="criteria-version-popup" aria-label="Saved criteria version details" data-cycle-id="<?= $active_cycle_id ?>">
    <button type="button" class="criteria-history-view criteria-version-popup-close" aria-label="Close version details" title="Close version details" autofocus><i class="bi bi-x-lg" aria-hidden="true"></i></button>
    <div class="criteria-version-popup-content"></div>
</dialog>
<?php endif; ?>

<?php if ($version || $versionError): ?>
<section class="criteria-version" id="criteria-version-details" aria-labelledby="criteria-version-title">
    <?php $versionCloseUrl = 'index.php?' . http_build_query($historyQuery) . '#criteria-history'; include __DIR__ . '/../includes/criteria_version_details.php'; unset($versionCloseUrl); ?>
</section>
<?php endif; ?>

<!-- Page header -->
<div style="background:#fff;border:1px solid #e2e8f0;padding:0 1.25rem;margin-bottom:1rem;
            display:flex;align-items:center;justify-content:space-between;min-height:60px;flex-wrap:wrap;gap:0.5rem;">
    <div style="display:flex;align-items:center;gap:0.75rem;">
        <a href="index.php?page=cycles<?= $active_cycle_id ? '&cycle_id='.$active_cycle_id : '' ?>"
           style="width:30px;height:30px;background:#f8fafc;border:1px solid #e2e8f0;display:flex;align-items:center;justify-content:center;text-decoration:none;flex-shrink:0;"
           onmouseover="this.style.background='#1a3a6b';this.style.borderColor='#1a3a6b';this.querySelector('i').style.color='#fff'"
           onmouseout="this.style.background='#f8fafc';this.style.borderColor='#e2e8f0';this.querySelector('i').style.color='#64748b'">
            <i class="bi bi-arrow-left" style="color:#64748b;font-size:0.75rem;"></i>
        </a>
        <div style="width:36px;height:36px;background:#1a3a6b;display:flex;align-items:center;justify-content:center;flex-shrink:0;">
            <i class="bi bi-sliders2" style="color:#fff;font-size:0.9rem;"></i>
        </div>
        <div>
            <div style="font-weight:700;color:#1a3a6b;font-size:0.95rem;line-height:1.2;">Scoring Criteria</div>
            <div style="font-size:0.72rem;color:#94a3b8;margin-top:2px;">
                <?php if ($current_cycle): ?>
                <?= sanitize($current_cycle['cycle_name']) ?>
                &nbsp;<span style="font-size:0.6rem;font-weight:700;text-transform:uppercase;padding:1px 5px;
                             background:<?= $current_cycle['status']==='open'?'#f0f4fb':'#f8fafc' ?>;
                             color:<?= $current_cycle['status']==='open'?'#1a3a6b':'#94a3b8' ?>;
                             border:1px solid <?= $current_cycle['status']==='open'?'#dbeafe':'#e2e8f0' ?>;">
                    <?= ucfirst($current_cycle['status']) ?>
                </span>
                <?php if ($active_position): ?>
                &nbsp;—&nbsp;<strong style="color:#1a3a6b;"><?= htmlspecialchars($active_position ?: 'Default criteria') ?></strong>
                <?php endif; ?>
                <?php else: ?>
                Default criteria for future cycles
                <?php endif; ?>
            </div>
        </div>
    </div>
    <form method="GET" action="index.php" style="display:flex;align-items:center;gap:0.4rem;">
        <input type="hidden" name="page" value="scoring_criteria">
        <span style="font-size:0.65rem;font-weight:700;text-transform:uppercase;letter-spacing:0.07em;color:#94a3b8;">Cycle</span>
        <select name="cycle_id" onchange="this.form.submit()"
                style="border:1px solid #e2e8f0;padding:0.32rem 0.65rem;font-size:0.78rem;color:#1e293b;background:#fff;outline:none;cursor:pointer;min-width:220px;"
                onfocus="this.style.borderColor='#1a3a6b'" onblur="this.style.borderColor='#e2e8f0'">
            <option value="0" <?= $templates_mode ? 'selected' : '' ?>>Default criteria (future cycles)</option>
            <?php foreach ($cycles as $cy): ?>
            <option value="<?= (int)$cy['cycle_id'] ?>" <?= $active_cycle_id===(int)$cy['cycle_id']?'selected':'' ?>>
                <?= sanitize($cy['cycle_name']) ?> (<?= ucfirst($cy['status']) ?>)
            </option>
            <?php endforeach; ?>
        </select>
    </form>
</div>

<?php if (!$templates_mode && !$current_cycle): ?>
<div style="background:#fff;border:1px solid #e2e8f0;padding:3rem;text-align:center;">
    <i class="bi bi-calendar-x" style="font-size:2rem;color:#e2e8f0;display:block;margin-bottom:0.75rem;"></i>
    <div style="font-weight:600;color:#475569;margin-bottom:0.3rem;">No cycle selected</div>
    <div style="font-size:0.82rem;color:#94a3b8;">Select a cycle from the dropdown above, or <a href="index.php?page=cycles" style="color:#1a3a6b;">create one first</a>.</div>
</div>
<?php else: ?>

<!-- Tab bar + content wrapper -->
<div style="background:#fff;border:1px solid #e2e8f0;overflow:hidden;">

    <?php if (!$templates_mode): ?>
    <!-- Position selector tab strip -->
    <div style="border-bottom:1px solid #e2e8f0;padding:0 1rem;display:flex;flex-wrap:wrap;gap:2px;align-items:stretch;background:#f8fafc;">
        <!-- Overview tab -->
        <a href="index.php?page=scoring_criteria&cycle_id=<?= $active_cycle_id ?>"
           style="display:inline-flex;align-items:center;gap:5px;padding:0.6rem 0.9rem;font-size:0.75rem;font-weight:600;text-decoration:none;border-bottom:2px solid transparent;white-space:nowrap;
                  <?= !$active_position
                      ? 'color:#1a3a6b;border-bottom-color:#1a3a6b;background:#fff;'
                      : 'color:#64748b;' ?>"
           onmouseover="<?= $active_position ? "this.style.color='#1a3a6b';this.style.background='#fff'" : '' ?>"
           onmouseout="<?= $active_position ? "this.style.color='#64748b';this.style.background=''" : '' ?>">
            <i class="bi bi-grid-3x3" style="font-size:0.68rem;"></i> Overview
        </a>
        <?php foreach ($rank_groups as $group => $ranks):
            $group_active = in_array($active_position, $ranks);
        ?>
        <div style="position:relative;" class="rank-dropdown-wrap">
            <button type="button"
                    style="display:inline-flex;align-items:center;gap:5px;padding:0.6rem 0.9rem;font-size:0.75rem;font-weight:600;cursor:pointer;background:transparent;border:none;border-bottom:2px solid transparent;white-space:nowrap;
                           <?= $group_active ? 'color:#1a3a6b;border-bottom-color:#1a3a6b;background:#fff;' : 'color:#64748b;' ?>"
                    onclick="toggleRankDropdown(this)"
                    onmouseover="<?= $group_active ? '' : "this.style.color='#1a3a6b';this.style.background='#fff'" ?>"
                    onmouseout="<?= $group_active ? '' : "this.style.color='#64748b';this.style.background=''" ?>">
                <?= $group ?> <i class="bi bi-chevron-down" style="font-size:0.52rem;opacity:0.6;"></i>
            </button>
            <div class="rank-dropdown-menu" style="display:none;position:absolute;top:100%;left:0;z-index:999;background:#fff;border:1px solid #e2e8f0;min-width:230px;box-shadow:0 8px 24px rgba(0,0,0,0.1);">
                <?php foreach ($ranks as $rank):
                    $cnt = $position_counts[$rank] ?? 0;
                    $is_active_pos = ($active_position === $rank);
                ?>
                <a href="index.php?page=scoring_criteria&cycle_id=<?= $active_cycle_id ?>&position=<?= urlencode($rank) ?>"
                   style="display:flex;align-items:center;justify-content:space-between;padding:0.5rem 1rem;font-size:0.78rem;text-decoration:none;border-bottom:1px solid #f8fafc;
                          <?= $is_active_pos ? 'background:#f0f4fb;color:#1a3a6b;font-weight:700;' : 'color:#1e293b;' ?>"
                   onmouseover="<?= $is_active_pos ? '' : "this.style.background='#f8fafc'" ?>"
                   onmouseout="<?= $is_active_pos ? '' : "this.style.background=''" ?>">
                    <span><?= htmlspecialchars($rank) ?></span>
                    <span style="font-size:0.62rem;font-weight:700;padding:1px 7px;margin-left:8px;
                                 background:<?= $cnt>0?'#f0f4fb':'#fef3c7' ?>;
                                 color:<?= $cnt>0?'#1e4d8c':'#92400e' ?>;
                                 border:1px solid <?= $cnt>0?'#dbeafe':'#fde68a' ?>;">
                        <?= $cnt > 0 ? $cnt : 'empty' ?>
                    </span>
                </a>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endforeach; ?>
    </div>

    <?php endif; ?>
    <!-- Content area -->
    <div style="padding:1.25rem;">

    <?php if ($criteria_source === 'overview'): ?>
    <!-- ── Overview: grouped position table ─────────────────────────── -->
    <div style="display:grid;grid-template-columns:1fr 1fr;gap:1rem;">
        <?php foreach ($rank_groups as $group => $ranks):
            $group_total = array_sum(array_map(fn($r) => $position_counts[$r] ?? 0, $ranks));
            $group_configured = count(array_filter($ranks, fn($r) => ($position_counts[$r] ?? 0) > 0));
        ?>
        <div style="border:1px solid #e2e8f0;overflow:hidden;">
            <!-- Group header -->
            <div style="background:#f8fafc;border-bottom:1px solid #e2e8f0;padding:0.5rem 0.9rem;display:flex;align-items:center;justify-content:space-between;">
                <span style="font-size:0.68rem;font-weight:700;text-transform:uppercase;letter-spacing:0.08em;color:#475569;"><?= $group ?></span>
                <span style="font-size:0.65rem;color:#94a3b8;"><?= $group_configured ?>/<?= count($ranks) ?> configured</span>
            </div>
            <!-- Position rows -->
            <?php foreach ($ranks as $rank):
                $cnt = $position_counts[$rank] ?? 0;
                $configured = $cnt > 0;
            ?>
            <a href="index.php?page=scoring_criteria&cycle_id=<?= $active_cycle_id ?>&position=<?= urlencode($rank) ?>"
               style="display:flex;align-items:center;justify-content:space-between;padding:0.55rem 0.9rem;text-decoration:none;border-bottom:1px solid #f1f5f9;"
               onmouseover="this.style.background='#f0f4fb'" onmouseout="this.style.background=''">
                <div style="display:flex;align-items:center;gap:0.5rem;">
                    <div style="width:6px;height:6px;border-radius:50%;background:<?= $configured?'#1a3a6b':'#cbd5e1' ?>;flex-shrink:0;"></div>
                    <span style="font-size:0.8rem;font-weight:600;color:#1e293b;"><?= htmlspecialchars($rank) ?></span>
                </div>
                <div style="display:flex;align-items:center;gap:0.5rem;">
                    <?php if ($configured): ?>
                    <span style="font-size:0.65rem;font-weight:700;padding:1px 7px;background:#f0f4fb;color:#1a3a6b;border:1px solid #dbeafe;">
                        <?= $cnt ?> criteria
                    </span>
                    <?php else: ?>
                    <span style="font-size:0.65rem;font-weight:700;padding:1px 7px;background:#fef3c7;color:#92400e;border:1px solid #fde68a;">
                        empty
                    </span>
                    <?php endif; ?>
                    <i class="bi bi-chevron-right" style="font-size:0.65rem;color:#94a3b8;"></i>
                </div>
            </a>
            <?php endforeach; ?>
        </div>
        <?php endforeach; ?>
    </div>

    <?php elseif ($criteria_source === 'empty_position'): ?>
    <div style="background:#f0f4fb;border:1px solid #dbeafe;border-left:3px solid #1a3a6b;padding:0.75rem 1rem;font-size:0.8rem;color:#1e4d8c;">
        <i class="bi bi-info-circle" style="margin-right:5px;"></i>
        Criteria for <strong><?= htmlspecialchars($active_position ?: 'Default criteria') ?></strong> are being set up. Please refresh the page.
    </div>

    <?php else: ?>
    <!-- ── Editable position criteria ────────────────────────────────── -->
    <?php
    $ec_icon   = $editing_enabled ? 'bi-pencil-square' : 'bi-eye';
    $ec_accent = $editing_enabled ? '#1a3a6b' : '#64748b';
    $ec_bg     = $editing_enabled ? '#f0f4fb' : '#f8fafc';
    $ec_border = $editing_enabled ? '#dbeafe' : '#e2e8f0';
    $ec_note   = $templates_mode ? 'Changes apply to future cycles only.' : 'This cycle maintains its own criteria version.';
    ?>
    <div style="background:<?= $ec_bg ?>;border:1px solid <?= $ec_border ?>;border-left:3px solid <?= $ec_accent ?>;
                padding:0.5rem 0.85rem;margin-bottom:1rem;display:flex;align-items:center;gap:0.6rem;flex-wrap:wrap;">
        <i class="<?= $ec_icon ?>" style="color:<?= $ec_accent ?>;font-size:0.8rem;flex-shrink:0;"></i>
        <div style="display:flex;align-items:center;gap:0.4rem;flex-wrap:wrap;font-size:0.75rem;">
            <span style="font-weight:700;color:<?= $ec_accent ?>;"><?= $editing_enabled ? 'Editing' : 'Viewing' ?></span>
            <span style="font-size:0.65rem;font-weight:700;padding:1px 7px;background:<?= $editing_enabled ? '#dbeafe' : '#e2e8f0' ?>;color:<?= $editing_enabled ? '#1e4d8c' : '#64748b' ?>;border:1px solid <?= $editing_enabled ? '#bfdbfe' : '#cbd5e1' ?>;">
                <?= htmlspecialchars($active_position ?: 'Default criteria') ?>
            </span>
            <span style="color:#94a3b8;font-size:0.7rem;"><?= $ec_note ?></span>
            <?php if ($inactiveCount || $show_inactive):
                $visibilityQuery = ['page'=>'scoring_criteria', 'cycle_id'=>$active_cycle_id];
                if ($active_position !== '') $visibilityQuery['position'] = $active_position;
                if (!$show_inactive) $visibilityQuery['show_inactive'] = 1;
            ?>
            <a href="index.php?<?= htmlspecialchars(http_build_query($visibilityQuery), ENT_QUOTES) ?>">
                <?= $show_inactive ? 'Hide inactive criteria' : 'Show inactive criteria (' . $inactiveCount . ')' ?>
            </a>
            <?php endif; ?>
        </div>
    </div>

    <?php foreach ($kra_list as $kra):
        $items = $grouped[$kra] ?? [];
        $slug  = strtolower(str_replace([' ','/'],['-',''], $kra));
        $is_editable = $editing_enabled;
        $criteriaColumns = 'minmax(0,1fr) 100px' . ($is_editable ? ' 50px 72px' : '');
    ?>
    <div style="border:1px solid #e2e8f0;margin-bottom:0.5rem;overflow:hidden;">
        <div style="background:#1a3a6b;padding:0.5rem 1rem;display:flex;align-items:center;justify-content:space-between;cursor:pointer;user-select:none;"
             onclick="toggleCriteria('kra-body-<?= $slug ?>')">
            <div style="display:flex;align-items:center;gap:0.5rem;">
                <span style="font-size:0.8rem;font-weight:700;color:#fff;"><?= $kra ?></span>
                <span style="font-size:0.6rem;font-weight:700;padding:1px 6px;background:rgba(255,255,255,0.15);color:#fff;border:1px solid rgba(255,255,255,0.2);">
                    <?= count($items) ?>
                </span>
            </div>
            <i class="bi bi-chevron-down" id="chevron-<?= $slug ?>" style="color:rgba(255,255,255,0.65);font-size:0.68rem;transition:transform 0.2s;"></i>
        </div>
        <div id="kra-body-<?= $slug ?>" style="display:none;">
            <?php if (empty($items)): ?>
            <div style="padding:1.5rem;text-align:center;color:#94a3b8;font-size:0.78rem;">
                <i class="bi bi-inbox" style="display:block;font-size:1.25rem;margin-bottom:4px;color:#e2e8f0;"></i>
                No criteria for this KRA yet.
            </div>
            <?php endif; ?>

            <!-- Table header -->
            <?php if (!empty($items)): ?>
            <div style="display:grid;grid-template-columns:<?= $criteriaColumns ?>;gap:0.4rem;padding:0.35rem 0.9rem;background:#f8fafc;border-bottom:2px solid #e2e8f0;font-size:0.58rem;font-weight:700;text-transform:uppercase;letter-spacing:0.07em;color:#64748b;">
                <div>Label</div>
                <div style="text-align:right;">Maximum Points</div>
                <?php if ($is_editable): ?>
                <div style="text-align:center;">On</div>
                <div></div>
                <?php endif; ?>
            </div>
            <?php endif; ?>

            <?php foreach ($items as $c): ?>
            <?php $rowTag = $is_editable ? 'form' : 'div'; ?>
            <<?= $rowTag ?><?= $is_editable ? ' method="POST"' : '' ?>
                  style="border-bottom:1px solid #f1f5f9;padding:0.55rem 0.9rem;"
                  onmouseover="this.style.background='#fafafa'" onmouseout="this.style.background=''">
                <?php if ($is_editable): ?>
                <input type="hidden" name="action"        value="update_criterion">
                <input type="hidden" name="criteria_id"   value="<?= $c['criteria_id'] ?>">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['criteria_csrf']) ?>">
                <?php endif; ?>

                <!-- Key pill + label row -->
                <div style="display:flex;align-items:center;gap:0.5rem;margin-bottom:<?= $is_editable?'0.4rem':'0' ?>;">
                    <span style="font-size:0.6rem;color:#94a3b8;font-family:monospace;background:#f1f5f9;padding:1px 5px;white-space:nowrap;flex-shrink:0;"><?= sanitize($c['criterion_key']) ?></span>
                    <?php if (!isset($activeIds[$c['criteria_id']])): ?>
                    <span style="font-size:0.68rem;color:#64748b;"><?= $c['is_active'] ? 'Hidden from faculty' : 'Inactive' ?></span>
                    <?php endif; ?>
                    <?php if (!$is_editable): ?>
                    <span style="font-size:0.8rem;font-weight:600;color:#1e293b;"><?= sanitize($c['criterion_label']) ?></span>
                    <span style="margin-left:auto;font-size:0.75rem;font-weight:700;color:#1a3a6b;white-space:nowrap;"><?= number_format((float)$c['max_points'],2) ?> pts</span>
                    <?php endif; ?>
                </div>

                <?php if ($is_editable): ?>
                <div style="display:grid;grid-template-columns:<?= $criteriaColumns ?>;gap:0.4rem;align-items:center;">
                    <input type="text" name="criterion_label" value="<?= sanitize($c['criterion_label']) ?>" required
                           style="border:1px solid #e2e8f0;padding:0.3rem 0.5rem;font-size:0.78rem;width:100%;box-sizing:border-box;outline:none;"
                           onfocus="this.style.borderColor='#1a3a6b'" onblur="this.style.borderColor='#e2e8f0'">
                    <input type="number" name="max_points" aria-label="Maximum points" value="<?= $c['max_points'] ?>" step="0.01" min="0" required
                           style="border:1px solid #e2e8f0;padding:0.3rem 0.4rem;font-size:0.78rem;width:100%;box-sizing:border-box;outline:none;text-align:right;"
                           onfocus="this.style.borderColor='#1a3a6b'" onblur="this.style.borderColor='#e2e8f0'">
                    <div style="display:flex;align-items:center;justify-content:center;">
                        <input type="checkbox" name="is_active" <?= $c['is_active'] ? 'checked' : '' ?>
                               style="width:14px;height:14px;cursor:pointer;accent-color:#1a3a6b;">
                    </div>
                    <div style="display:flex;gap:3px;">
                        <button type="button"
                                style="flex:1;padding:0.3rem;background:#1a3a6b;color:#fff;border:none;font-size:0.7rem;cursor:pointer;display:flex;align-items:center;justify-content:center;"
                                onclick="packEvidence(this.closest('form'));openCritReviewModal(this.closest('form'))"
                                onmouseover="this.style.background='#152d54'" onmouseout="this.style.background='#1a3a6b'">
                            <i class="bi bi-save"></i>
                        </button>
                        <?php if ($c['is_active']): ?>
                        <button type="button"
                                style="flex:1;padding:0.3rem;background:#fff;color:#dc2626;border:1px solid #fecaca;font-size:0.7rem;cursor:pointer;display:flex;align-items:center;justify-content:center;"
                                onclick="showDelCrit(<?= (int)$c['criteria_id'] ?>,<?= htmlspecialchars(json_encode($c['criterion_label'], JSON_HEX_TAG), ENT_QUOTES) ?>,<?= $referenceCounts[$c['criteria_id']] ?? 0 ?>)"
                                onmouseover="this.style.background='#fef2f2'" onmouseout="this.style.background='#fff'">
                            <i class="bi bi-trash"></i>
                        </button>
                        <?php endif; ?>
                    </div>
                </div>
                <!-- Evidence Requirements — bullet list -->
                <div style="margin-top:0.4rem;border:1px solid #e2e8f0;overflow:hidden;">
                    <div style="background:#f0f4fb;border-bottom:1px solid #e2e8f0;padding:0.28rem 0.6rem;display:flex;align-items:center;justify-content:space-between;">
                        <div style="display:flex;align-items:center;gap:0.4rem;">
                            <i class="bi bi-file-earmark-text" style="color:#1a3a6b;font-size:0.68rem;"></i>
                            <span style="font-size:0.6rem;font-weight:700;text-transform:uppercase;letter-spacing:0.07em;color:#1a3a6b;">Evidence Requirements</span>
                        </div>
                        <div style="display:flex;align-items:center;gap:0.5rem;">
                            <span style="font-size:0.58rem;color:#94a3b8;">Shown to faculty in Evidence Guide</span>
                            <button type="button"
                                    onclick="addEvidenceBullet(this.closest('form').querySelector('.ev-list'))"
                                    style="padding:1px 7px;background:#1a3a6b;color:#fff;border:none;font-size:0.65rem;font-weight:700;cursor:pointer;display:inline-flex;align-items:center;gap:3px;line-height:1.6;"
                                    onmouseover="this.style.background='#152d54'" onmouseout="this.style.background='#1a3a6b'">
                                <i class="bi bi-plus-lg" style="font-size:0.6rem;"></i> Add
                            </button>
                        </div>
                    </div>
                    <!-- Hidden field that holds the newline-joined value on submit -->
                    <input type="hidden" name="description" class="ev-hidden">
                    <!-- Bullet rows -->
                    <div class="ev-list" style="padding:0.4rem 0.6rem;display:flex;flex-direction:column;gap:3px;">
                        <?php
                        $ev_items = array_values(array_filter(array_map('trim', explode("\n", $c['description'] ?? ''))));
                        if (!$ev_items) $ev_items = [''];
                        foreach ($ev_items as $ev_item):
                        ?>
                        <div class="ev-row" style="display:flex;align-items:center;gap:4px;">
                            <i class="bi bi-dot" style="color:#1a3a6b;font-size:0.9rem;flex-shrink:0;margin-left:-4px;"></i>
                            <input type="text" class="ev-input"
                                   value="<?= htmlspecialchars($ev_item) ?>"
                                   placeholder="e.g. Official rating form signed by department head"
                                   style="flex:1;border:1px solid #e2e8f0;padding:0.25rem 0.4rem;font-size:0.75rem;outline:none;min-width:0;"
                                   onfocus="this.style.borderColor='#1a3a6b'" onblur="this.style.borderColor='#e2e8f0'">
                            <button type="button" onclick="removeEvidenceBullet(this)"
                                    style="width:20px;height:20px;border:none;background:none;color:#cbd5e1;cursor:pointer;font-size:0.7rem;display:flex;align-items:center;justify-content:center;flex-shrink:0;padding:0;"
                                    onmouseover="this.style.color='#dc2626'" onmouseout="this.style.color='#cbd5e1'">
                                <i class="bi bi-x-lg"></i>
                            </button>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php else: ?>
                <?php if (!empty($c['description'])): ?>
                <div style="margin-top:0.3rem;display:flex;align-items:flex-start;gap:0.4rem;">
                    <i class="bi bi-file-earmark-text" style="color:#94a3b8;font-size:0.65rem;margin-top:2px;flex-shrink:0;"></i>
                    <div style="font-size:0.68rem;color:#64748b;line-height:1.5;"><?= htmlspecialchars($c['description']) ?></div>
                </div>
                <?php else: ?>
                <div style="margin-top:0.3rem;display:flex;align-items:center;gap:0.4rem;">
                    <i class="bi bi-file-earmark" style="color:#cbd5e1;font-size:0.65rem;"></i>
                    <span style="font-size:0.68rem;color:#cbd5e1;font-style:italic;">No evidence requirements set</span>
                </div>
                <?php endif; ?>
                <?php endif; ?>
            </<?= $rowTag ?>>
            <?php if ($is_editable): ?>
            <form method="POST" id="delCritForm_<?= $c['criteria_id'] ?>" style="display:none;">
                <input type="hidden" name="action"        value="delete_criterion">
                <input type="hidden" name="criteria_id"   value="<?= $c['criteria_id'] ?>">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['criteria_csrf']) ?>">
            </form>
            <?php endif; ?>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endforeach; ?>

    <!-- Add criterion -->
    <?php if ($editing_enabled): ?>
    <div style="border:1px solid #e2e8f0;overflow:hidden;margin-top:0.75rem;">
        <div style="padding:0.5rem 1rem;border-bottom:1px solid #e2e8f0;background:#f8fafc;
                    display:flex;align-items:center;justify-content:space-between;">
            <div style="display:flex;align-items:center;gap:0.5rem;">
                <span style="font-size:0.72rem;font-weight:700;color:#1a3a6b;">Add Criterion</span>
                <span style="font-size:0.62rem;font-weight:700;padding:1px 6px;background:#f0f4fb;color:#1a3a6b;border:1px solid #dbeafe;">
                    <?= htmlspecialchars($active_position ?: 'Default criteria') ?>
                </span>
            </div>
            <button type="button" onclick="toggleAddCrit()"
                    style="padding:0.28rem 0.85rem;background:#1a3a6b;color:#fff;border:none;font-size:0.72rem;font-weight:700;cursor:pointer;display:inline-flex;align-items:center;gap:4px;"
                    onmouseover="this.style.background='#152d54'" onmouseout="this.style.background='#1a3a6b'">
                <i class="bi bi-plus-lg" style="font-size:0.65rem;"></i> Add
            </button>
        </div>
        <div id="addCritPanel" style="display:none;padding:0.85rem 1rem;background:#f8fafc;border-top:1px solid #e2e8f0;">
        <form method="POST" id="addCritForm">
            <input type="hidden" name="action"        value="add_criterion">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['criteria_csrf']) ?>">
            <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(190px,1fr));gap:0.5rem;margin-bottom:0.5rem;">
                <div>
                    <div style="font-size:0.58rem;font-weight:700;text-transform:uppercase;letter-spacing:0.07em;color:#64748b;margin-bottom:3px;">KRA <span style="color:#dc2626;">*</span></div>
                    <select name="kra_category" required
                            style="width:100%;border:1px solid #e2e8f0;padding:0.32rem 0.4rem;font-size:0.78rem;background:#fff;outline:none;cursor:pointer;"
                            onfocus="this.style.borderColor='#1a3a6b'" onblur="this.style.borderColor='#e2e8f0'">
                        <?php foreach ($kra_list as $k): ?>
                        <option value="<?= $k ?>"><?= $k ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label for="addCritParent" style="display:block;font-size:0.58rem;font-weight:700;text-transform:uppercase;letter-spacing:0.07em;color:#64748b;margin-bottom:3px;">Parent Criterion <span style="color:#dc2626;">*</span></label>
                    <select id="addCritParent" name="parent_criterion" required
                            style="width:100%;border:1px solid #e2e8f0;padding:0.32rem 0.4rem;font-size:0.78rem;background:#fff;">
                        <option value="" disabled selected>Select a criterion</option>
                    </select>
                </div>
            </div>
            <div style="display:grid;grid-template-columns:100px minmax(0,1fr) 100px 36px;gap:0.5rem;align-items:end;margin-bottom:0.4rem;">
                <div>
                    <div style="font-size:0.58rem;font-weight:700;text-transform:uppercase;letter-spacing:0.07em;color:#64748b;margin-bottom:3px;">Key <span style="color:#dc2626;">*</span></div>
                    <input type="text" name="criterion_key" placeholder="e.g. kra1_x" required
                           style="width:100%;border:1px solid #e2e8f0;padding:0.32rem 0.4rem;font-size:0.78rem;box-sizing:border-box;"
                           onfocus="this.style.borderColor='#1a3a6b'" onblur="this.style.borderColor='#e2e8f0'">
                </div>
                <div>
                    <div style="font-size:0.58rem;font-weight:700;text-transform:uppercase;letter-spacing:0.07em;color:#64748b;margin-bottom:3px;">Label <span style="color:#dc2626;">*</span></div>
                    <input type="text" name="criterion_label" placeholder="e.g. Patent / Utility Model" required
                           style="width:100%;border:1px solid #e2e8f0;padding:0.32rem 0.4rem;font-size:0.78rem;box-sizing:border-box;"
                           onfocus="this.style.borderColor='#1a3a6b'" onblur="this.style.borderColor='#e2e8f0'">
                </div>
                <div>
                    <label for="addCritMax" style="display:block;font-size:0.58rem;font-weight:700;text-transform:uppercase;letter-spacing:0.07em;color:#64748b;margin-bottom:3px;">Maximum Points <span style="color:#dc2626;">*</span></label>
                    <input type="number" name="max_points" id="addCritMax" step="0.01" min="0.01" placeholder="0.00" required
                           style="width:100%;border:1px solid #e2e8f0;padding:0.32rem 0.4rem;font-size:0.78rem;box-sizing:border-box;"
                           onfocus="this.style.borderColor='#1a3a6b'" onblur="this.style.borderColor='#e2e8f0'">
                </div>
                <div>
                    <button type="button"
                            style="width:36px;height:34px;background:#16a34a;color:#fff;border:none;font-size:0.82rem;cursor:pointer;display:flex;align-items:center;justify-content:center;"
                            onclick="packEvidence(document.getElementById('addCritForm'));openCritReviewModal(document.getElementById('addCritForm'), true)"
                            onmouseover="this.style.background='#15803d'" onmouseout="this.style.background='#16a34a'">
                        <i class="bi bi-plus-lg"></i>
                    </button>
                </div>
            </div>
            <!-- Evidence Requirements for new criterion -->
            <div style="border:1px solid #e2e8f0;overflow:hidden;">
                <div style="background:#f0f4fb;border-bottom:1px solid #e2e8f0;padding:0.28rem 0.6rem;display:flex;align-items:center;justify-content:space-between;">
                    <div style="display:flex;align-items:center;gap:0.4rem;">
                        <i class="bi bi-file-earmark-text" style="color:#1a3a6b;font-size:0.68rem;"></i>
                        <span style="font-size:0.6rem;font-weight:700;text-transform:uppercase;letter-spacing:0.07em;color:#1a3a6b;">Evidence Requirements</span>
                    </div>
                    <div style="display:flex;align-items:center;gap:0.5rem;">
                        <span style="font-size:0.58rem;color:#94a3b8;">Shown to faculty in Evidence Guide</span>
                        <button type="button"
                                onclick="addEvidenceBullet(this.closest('form').querySelector('.ev-list'))"
                                style="padding:1px 7px;background:#1a3a6b;color:#fff;border:none;font-size:0.65rem;font-weight:700;cursor:pointer;display:inline-flex;align-items:center;gap:3px;line-height:1.6;"
                                onmouseover="this.style.background='#152d54'" onmouseout="this.style.background='#1a3a6b'">
                            <i class="bi bi-plus-lg" style="font-size:0.6rem;"></i> Add
                        </button>
                    </div>
                </div>
                <input type="hidden" name="description" class="ev-hidden">
                <div class="ev-list" style="padding:0.4rem 0.6rem;display:flex;flex-direction:column;gap:3px;">
                    <div class="ev-row" style="display:flex;align-items:center;gap:4px;">
                        <i class="bi bi-dot" style="color:#1a3a6b;font-size:0.9rem;flex-shrink:0;margin-left:-4px;"></i>
                        <input type="text" class="ev-input"
                               value=""
                               placeholder="e.g. Official rating form signed by department head"
                               style="flex:1;border:1px solid #e2e8f0;padding:0.25rem 0.4rem;font-size:0.75rem;outline:none;min-width:0;"
                               onfocus="this.style.borderColor='#1a3a6b'" onblur="this.style.borderColor='#e2e8f0'">
                        <button type="button" onclick="removeEvidenceBullet(this)"
                                style="width:20px;height:20px;border:none;background:none;color:#cbd5e1;cursor:pointer;font-size:0.7rem;display:flex;align-items:center;justify-content:center;flex-shrink:0;padding:0;"
                                onmouseover="this.style.color='#dc2626'" onmouseout="this.style.color='#cbd5e1'">
                            <i class="bi bi-x-lg"></i>
                        </button>
                    </div>
                </div>
            </div>
        </form>
        </div>
    </div>
    <?php endif; ?>

    <?php endif; // criteria_source ?>

    </div><!-- /content area -->
</div><!-- /tab+content wrapper -->

<?php endif; // active_cycle_id ?>

<?php if ($editing_enabled): ?>
<!-- Criterion Review / Confirm Modal -->
<div id="critReviewModal" style="display:none;position:fixed;inset:0;z-index:99999;background:rgba(15,23,42,0.6);
     align-items:center;justify-content:center;padding:1rem;">
    <div style="background:#fff;width:100%;max-width:520px;border:1px solid #e2e8f0;box-shadow:0 20px 60px rgba(0,0,0,0.22);overflow:hidden;">

        <!-- Header -->
        <div style="background:#1a3a6b;padding:0.8rem 1.1rem;display:flex;align-items:center;justify-content:space-between;">
            <div style="display:flex;align-items:center;gap:0.5rem;">
                <i class="bi bi-clipboard-check" style="color:#fff;font-size:0.9rem;"></i>
                <div>
                    <div id="crmTitle" style="color:#fff;font-weight:700;font-size:0.88rem;line-height:1.2;">Review Changes</div>
                    <div id="crmSubtitle" style="color:rgba(255,255,255,0.55);font-size:0.65rem;margin-top:1px;">Confirm before applying to the system</div>
                </div>
            </div>
            <button type="button" onclick="closeCritReviewModal()"
                    style="background:none;border:none;color:rgba(255,255,255,0.7);font-size:1.1rem;cursor:pointer;line-height:1;padding:0;"
                    onmouseover="this.style.color='#fff'" onmouseout="this.style.color='rgba(255,255,255,0.7)'">
                <i class="bi bi-x-lg"></i>
            </button>
        </div>

        <div style="background:#fffbeb;border-bottom:1px solid #fde68a;padding:0.5rem 1rem;
             display:flex;align-items:center;gap:0.5rem;font-size:0.75rem;color:#92400e;">
            <i class="bi bi-exclamation-triangle-fill" style="flex-shrink:0;"></i>
            Saving creates a new default criteria version and notifies faculty members.
        </div>

        <!-- Change summary table -->
        <div style="padding:1rem 1.1rem 0.5rem;">
            <div style="font-size:0.6rem;font-weight:700;text-transform:uppercase;letter-spacing:0.07em;color:#94a3b8;margin-bottom:0.5rem;">
                What will be saved
            </div>
            <div id="crmRows" style="border:1px solid #e2e8f0;overflow:hidden;"></div>
        </div>

        <!-- Evidence preview -->
        <div id="crmEvidenceWrap" style="display:none;padding:0 1.1rem 0.75rem;">
            <div style="font-size:0.6rem;font-weight:700;text-transform:uppercase;letter-spacing:0.07em;color:#94a3b8;margin-bottom:0.4rem;">
                Evidence Requirements
            </div>
            <div id="crmEvidenceList" style="border:1px solid #e2e8f0;padding:0.5rem 0.75rem;"></div>
        </div>

        <!-- Footer -->
        <div style="padding:0.75rem 1.1rem;border-top:1px solid #e2e8f0;background:#f8fafc;
                    display:flex;align-items:center;justify-content:space-between;">
            <span style="font-size:0.68rem;color:#94a3b8;">This action will take effect immediately.</span>
            <div style="display:flex;gap:0.4rem;">
                <button type="button" onclick="closeCritReviewModal()"
                        style="padding:0.42rem 0.9rem;border:1px solid #e2e8f0;background:#fff;color:#475569;font-size:0.78rem;font-weight:600;cursor:pointer;"
                        onmouseover="this.style.background='#f8fafc'" onmouseout="this.style.background='#fff'">Cancel</button>
                <button type="button" id="crmConfirmBtn"
                        style="padding:0.42rem 1.1rem;background:#1a3a6b;color:#fff;border:none;font-size:0.78rem;font-weight:700;cursor:pointer;display:inline-flex;align-items:center;gap:5px;"
                        onmouseover="this.style.background='#152d54'" onmouseout="this.style.background='#1a3a6b'">
                    <i class="bi bi-check-circle" style="font-size:0.72rem;"></i> Confirm &amp; Save
                </button>
            </div>
        </div>
    </div>
</div>

<!-- Delete criterion modal -->
<div id="delCritModal" style="display:none;position:fixed;inset:0;z-index:9999;background:rgba(0,0,0,0.45);align-items:center;justify-content:center;">
    <div style="background:#fff;border:1px solid #e2e8f0;padding:1.5rem 1.75rem;max-width:420px;width:90%;box-shadow:0 16px 48px rgba(0,0,0,0.18);">
        <div style="display:flex;align-items:center;gap:0.5rem;margin-bottom:0.75rem;">
            <div style="width:30px;height:30px;background:#fef2f2;border:1px solid #fecaca;display:flex;align-items:center;justify-content:center;flex-shrink:0;">
                <i class="bi bi-trash" style="color:#dc2626;font-size:0.82rem;"></i>
            </div>
            <span style="font-weight:700;color:#1a3a6b;font-size:0.88rem;">Delete Criterion</span>
        </div>
        <p style="color:#475569;font-size:0.8rem;margin-bottom:0.5rem;">Deactivate this criterion?</p>
        <p id="delCritName" style="color:#1e293b;font-weight:600;background:#f8fafc;border:1px solid #e2e8f0;padding:0.5rem 0.75rem;font-size:0.8rem;margin-bottom:0.75rem;"></p>
        <p id="delCritReferences" style="color:#475569;font-size:0.8rem;margin-bottom:0.5rem;"></p>
        <p style="color:#94a3b8;font-size:0.72rem;margin-bottom:1rem;">Existing submissions are retained. You can reactivate the criterion by editing it.</p>
        <div style="display:flex;gap:0.5rem;justify-content:flex-end;">
            <button type="button" onclick="document.getElementById('delCritModal').style.display='none'"
                    style="padding:0.38rem 0.9rem;border:1px solid #e2e8f0;background:#fff;color:#475569;font-size:0.78rem;font-weight:600;cursor:pointer;"
                    onmouseover="this.style.background='#f8fafc'" onmouseout="this.style.background='#fff'">Cancel</button>
            <button type="button" id="delCritConfirmBtn"
                    style="padding:0.38rem 0.9rem;border:none;background:#dc2626;color:#fff;font-size:0.78rem;font-weight:700;cursor:pointer;display:inline-flex;align-items:center;gap:5px;"
                    onmouseover="this.style.background='#b91c1c'" onmouseout="this.style.background='#dc2626'">
                <i class="bi bi-trash" style="font-size:0.72rem;"></i> Delete
            </button>
        </div>
    </div>
</div>
<?php endif; ?>

<script>
const criteriaVersionPopup = document.getElementById('criteria-version-popup');
if (criteriaVersionPopup && typeof criteriaVersionPopup.showModal === 'function') {
    const history = document.getElementById('criteria-history');
    const content = criteriaVersionPopup.querySelector('.criteria-version-popup-content');
    const close = criteriaVersionPopup.querySelector('.criteria-version-popup-close');
    let currentRequest;
    let opener;

    const showVersion = async (event) => {
        const link = event.target.closest('a[data-version-revision]');
        if (!link || event.button !== 0 || event.ctrlKey || event.metaKey || event.shiftKey || event.altKey) return;
        event.preventDefault();
        currentRequest?.abort();
        const request = new AbortController();
        currentRequest = request;

        const status = document.createElement('p');
        status.className = 'criteria-version-note criteria-version-popup-status';
        status.setAttribute('role', 'status');
        status.textContent = 'Loading version ' + link.dataset.versionRevision + '…';
        content.replaceChildren(status);
        content.scrollTop = 0;
        if (!criteriaVersionPopup.open) {
            opener = link;
            criteriaVersionPopup.showModal();
        }
        close.focus();

        try {
            const query = new URLSearchParams({cycle_id: criteriaVersionPopup.dataset.cycleId, revision: link.dataset.versionRevision});
            const response = await fetch('pages/criteria_version.php?' + query, {signal: request.signal});
            if (!response.ok) {
                status.textContent = response.status === 404 ? 'This version is unavailable for the selected criteria.' : 'The version details could not be loaded.';
                return;
            }
            const fragment = document.createElement('template');
            fragment.innerHTML = await response.text();
            const details = fragment.content.querySelector('[data-criteria-version-content]');
            if (!details) throw new Error('Missing version details');
            if (!request.signal.aborted) content.replaceChildren(...details.childNodes);
        } catch (error) {
            if (!request.signal.aborted) status.textContent = 'The version details could not be loaded.';
        }
    };

    history.addEventListener('click', showVersion);
    content.addEventListener('click', showVersion);
    const dismissVersion = () => {
        currentRequest?.abort();
        criteriaVersionPopup.close();
        opener?.focus({preventScroll: true});
    };
    close.addEventListener('click', dismissVersion);
    criteriaVersionPopup.addEventListener('cancel', (event) => {
        event.preventDefault();
        dismissVersion();
    });
}

const addCriterionForm = document.getElementById('addCritForm');
if (addCriterionForm) {
    const groups = <?= json_encode($parentOptions, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
    const kraSelect = addCriterionForm.elements.namedItem('kra_category');
    const parentSelect = addCriterionForm.elements.namedItem('parent_criterion');
    const parentPlaceholder = parentSelect.options[0];
    const keyInput = addCriterionForm.elements.namedItem('criterion_key');
    const updateKeyInput = () => {
        const newParent = parentSelect.value === 'new_parent';
        keyInput.placeholder = newParent ? 'e.g. E' : 'e.g. kra1_x';
        keyInput.maxLength = newParent ? 1 : 100;
        if (newParent) keyInput.pattern = '[A-Za-z]';
        else keyInput.removeAttribute('pattern');
    };
    const updateParentOptions = () => {
        const options = (groups[kraSelect.value] || []).map(parent => new Option(parent.label, parent.id));
        parentSelect.replaceChildren(parentPlaceholder, ...options, new Option('Add a new parent criterion', 'new_parent'));
        parentSelect.selectedIndex = 0;
        updateKeyInput();
    };
    kraSelect.addEventListener('change', updateParentOptions);
    parentSelect.addEventListener('change', updateKeyInput);
    updateParentOptions();
}
function toggleCriteria(bodyId) {
    var body    = document.getElementById(bodyId);
    var slug    = bodyId.replace('kra-body-', '');
    var chevron = document.getElementById('chevron-' + slug);
    var open    = body.style.display !== 'none';
    body.style.display = open ? 'none' : 'block';
    if (chevron) chevron.style.transform = open ? '' : 'rotate(180deg)';
}

function toggleAddCrit() {
    var p = document.getElementById('addCritPanel');
    p.style.display = p.style.display === 'none' ? 'block' : 'none';
}

function toggleRankDropdown(btn) {
    var menu = btn.nextElementSibling;
    document.querySelectorAll('.rank-dropdown-menu').forEach(function(m) {
        if (m !== menu) m.style.display = 'none';
    });
    menu.style.display = menu.style.display === 'none' ? 'block' : 'none';
}

document.addEventListener('click', function(e) {
    if (!e.target.closest('.rank-dropdown-wrap')) {
        document.querySelectorAll('.rank-dropdown-menu').forEach(function(m) {
            m.style.display = 'none';
        });
    }
});

function showDelCrit(critId, label, count) {
    document.getElementById('delCritName').textContent = label;
    document.getElementById('delCritReferences').textContent =
        `${count} ${count === 1 ? 'entry' : 'entries'} in non-final applications reference this criterion or its children by key.`;
    document.getElementById('delCritModal').style.display = 'flex';
    document.getElementById('delCritConfirmBtn').onclick = function() {
        document.getElementById('delCritModal').style.display = 'none';
        document.getElementById('delCritForm_' + critId).submit();
    };
}

// ── Evidence bullet helpers ───────────────────────────────────────────────
function packEvidence(form) {
    if (!form) return;
    var list   = form.querySelector('.ev-list');
    var hidden = form.querySelector('.ev-hidden');
    if (!list || !hidden) return;
    var lines = [];
    list.querySelectorAll('.ev-input').forEach(function(inp) {
        var v = inp.value.trim();
        if (v) lines.push(v);
    });
    hidden.value = lines.join('\n');
}
function addEvidenceBullet(list) {
    var row = document.createElement('div');
    row.className = 'ev-row';
    row.style.cssText = 'display:flex;align-items:center;gap:4px;';
    row.innerHTML =
        '<i class="bi bi-dot" style="color:#1a3a6b;font-size:0.9rem;flex-shrink:0;margin-left:-4px;"></i>' +
        '<input type="text" class="ev-input" placeholder="e.g. Official rating form signed by department head"' +
        ' style="flex:1;border:1px solid #e2e8f0;padding:0.25rem 0.4rem;font-size:0.75rem;outline:none;min-width:0;"' +
        ' onfocus="this.style.borderColor=\'#1a3a6b\'" onblur="this.style.borderColor=\'#e2e8f0\'">' +
        '<button type="button" onclick="removeEvidenceBullet(this)"' +
        ' style="width:20px;height:20px;border:none;background:none;color:#cbd5e1;cursor:pointer;font-size:0.7rem;display:flex;align-items:center;justify-content:center;flex-shrink:0;padding:0;"' +
        ' onmouseover="this.style.color=\'#dc2626\'" onmouseout="this.style.color=\'#cbd5e1\'">' +
        '<i class="bi bi-x-lg"></i></button>';
    list.appendChild(row);
    row.querySelector('.ev-input').focus();
}

function removeEvidenceBullet(btn) {
    var list = btn.closest('.ev-list');
    var rows = list.querySelectorAll('.ev-row');
    if (rows.length <= 1) {
        // Don't remove the last row — just clear it
        btn.closest('.ev-row').querySelector('.ev-input').value = '';
        return;
    }
    btn.closest('.ev-row').remove();
}

// Before any form with ev-list submits, pack bullet inputs into hidden field
document.addEventListener('submit', function(e) {
    var form = e.target;
    var list = form.querySelector('.ev-list');
    var hidden = form.querySelector('.ev-hidden');
    if (!list || !hidden) return;
    var lines = [];
    list.querySelectorAll('.ev-input').forEach(function(inp) {
        var v = inp.value.trim();
        if (v) lines.push(v);
    });
    hidden.value = lines.join('\n');
}, true);

// packEvidence() is called explicitly on each save/add button click above.

// ── Criterion Review Modal ────────────────────────────────────────────────
var _crmForm = null;

function openCritReviewModal(form, isNew) {
    if (isNew && !form.reportValidity()) return;
    _crmForm = form;
    isNew = isNew || false;

    // Read values from form
    var label    = (form.querySelector('[name=criterion_label]') || {}).value || '';
    var maxPts   = (form.querySelector('[name=max_points]') || {}).value || '';
    var isActive = form.querySelector('[name=is_active]') ? (form.querySelector('[name=is_active]').checked ? 'Yes' : 'No') : '—';
    var key      = (form.querySelector('[name=criteria_id]') ? (form.querySelector('.ev-hidden') ? '' : '') : '');
    // Get key from the displayed monospace span
    var keySpan  = form.querySelector('[style*="monospace"]');
    var keyVal   = isNew ? form.elements.namedItem('criterion_key').value.trim() : (keySpan ? keySpan.textContent.trim() : '—');
    var evidenceRaw = (form.querySelector('.ev-hidden') || {}).value || '';

    if (!label || !maxPts) {
        alert('Please fill in Label and Maximum Points before saving.');
        return;
    }

    // Build the title
    document.getElementById('crmTitle').textContent = isNew ? 'Add New Criterion' : 'Review Changes';
    document.getElementById('crmSubtitle').textContent = isNew
        ? 'Confirm the new criterion before adding it to the system'
        : 'Review the criteria changes for future cycles';

    // Build the summary rows
    var rows = [
        ['Key',     keyVal],
        ['Label',   label],
        ['Maximum Points', parseFloat(maxPts).toFixed(2) + ' pts'],
        ['Active',  isActive],
    ];
    if (isNew) rows.splice(1, 0, ['Parent', form.elements.namedItem('parent_criterion').selectedOptions[0].text]);
    var rowsHtml = '';
    rows.forEach(function(r, i) {
        var bg = i % 2 === 0 ? '#fff' : '#f8fafc';
        rowsHtml +=
            '<div style="display:grid;grid-template-columns:100px 1fr;border-bottom:1px solid #f1f5f9;background:' + bg + ';">' +
            '<div style="padding:0.45rem 0.75rem;font-size:0.65rem;font-weight:700;text-transform:uppercase;letter-spacing:0.07em;color:#94a3b8;border-right:1px solid #f1f5f9;">' + r[0] + '</div>' +
            '<div style="padding:0.45rem 0.75rem;font-size:0.8rem;color:#1e293b;font-weight:600;">' + escHtml(r[1]) + '</div>' +
            '</div>';
    });
    document.getElementById('crmRows').innerHTML = rowsHtml;

    // Evidence bullets
    var evWrap = document.getElementById('crmEvidenceWrap');
    var evList = document.getElementById('crmEvidenceList');
    var bullets = evidenceRaw.split('\n').map(function(s){ return s.trim(); }).filter(Boolean);
    if (bullets.length > 0) {
        evWrap.style.display = 'block';
        evList.innerHTML = bullets.map(function(b) {
            return '<div style="display:flex;align-items:flex-start;gap:6px;padding:3px 0;font-size:0.76rem;color:#475569;">' +
                   '<i class="bi bi-dot" style="color:#1a3a6b;font-size:0.9rem;flex-shrink:0;margin-top:-1px;"></i>' +
                   '<span>' + escHtml(b) + '</span></div>';
        }).join('');
    } else {
        evWrap.style.display = 'none';
    }

    // Wire confirm button
    document.getElementById('crmConfirmBtn').onclick = function() {
        var form = _crmForm;
        closeCritReviewModal();
        form.submit();
    };

    document.getElementById('critReviewModal').style.display = 'flex';
}

function closeCritReviewModal() {
    document.getElementById('critReviewModal').style.display = 'none';
    _crmForm = null;
}

function escHtml(str) {
    return String(str)
        .replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;')
        .replace(/"/g,'&quot;').replace(/'/g,'&#39;');
}

// Close on backdrop click
document.getElementById('critReviewModal')?.addEventListener('click', function(e) {
    if (e.target === this) closeCritReviewModal();
});
</script>
