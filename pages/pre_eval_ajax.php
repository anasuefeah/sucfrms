<?php
/**
 * Pre-Evaluation AJAX endpoint.
 *
 * Scoring uses the SAME KRA scorer modules as the official reclassification
 * pipeline (KRA1Scorer, KRA2Scorer, KRA3Scorer, KRA4Scorer).
 * The only difference from a live application run is that checkers are not
 * involved — no professor gate, no double-counting enforcement, no evaluation-
 * period exclusion. Everything else — point values, sub-caps, income tiers,
 * co-contributor halving, ISR/CSR cross-validation, auto sub-rank triggers —
 * must be identical so faculty get an accurate preview.
 */
if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/functions.php';

// ── Load official KRA scorer modules ──────────────────────────
$_scoring_dir = __DIR__ . '/../includes/scoring/';
foreach (['kra1_scorer.php','kra2_scorer.php','kra3_scorer.php','kra4_scorer.php'] as $_sf) {
    if (file_exists($_scoring_dir . $_sf)) require_once $_scoring_dir . $_sf;
}
// Make PDO available to KRA1Scorer for CONFIG_MENTORSHIP_POINTS lookup
if (class_exists('\Scoring\KRA1Scorer')) {
    \Scoring\KRA1Scorer::setPdo($pdo);
}

if (!isLoggedIn() || !isFaculty()) {
    http_response_code(403);
    echo json_encode(['ok'=>false,'error'=>'Unauthorized']);
    exit;
}

while (ob_get_level()) ob_end_clean();
header('Content-Type: application/json');

$uid    = $_SESSION['user_id'];
$action = $_REQUEST['action'] ?? '';

// ── Runtime migration ──────────────────────────────────────────
try { $pdo->query("SELECT entry_id FROM pre_eval_entries LIMIT 1"); }
catch (\Exception $e) {
    $pdo->exec("CREATE TABLE IF NOT EXISTS pre_eval_entries (
        entry_id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        kra_category ENUM('Instruction','Research','Extension','Professional Development') NOT NULL,
        remarks TEXT NOT NULL,
        computed_points DECIMAL(6,2) DEFAULT 0,
        notes TEXT DEFAULT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE CASCADE
    )");
}
try { $pdo->query("SELECT file_id FROM pre_eval_files LIMIT 1"); }
catch (\Exception $e) {
    $pdo->exec("CREATE TABLE IF NOT EXISTS pre_eval_files (
        file_id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        entry_id INT DEFAULT NULL,
        kra_category ENUM('Instruction','Research','Extension','Professional Development') NOT NULL,
        file_path VARCHAR(255) NOT NULL,
        original_filename VARCHAR(255) NOT NULL,
        file_size_bytes INT DEFAULT 0,
        description VARCHAR(255) DEFAULT NULL,
        uploaded_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE CASCADE,
        FOREIGN KEY (entry_id) REFERENCES pre_eval_entries(entry_id) ON DELETE SET NULL
    )");
}

/**
 * Per-entry score computation — delegates to the official scorer modules,
 * exactly as kra_ajax.php does for live applications.
 * This replaces the old divergent inline peComputeScore() function.
 */
function peComputeScore(string $cat, string $remarks): float {
    switch ($cat) {
        case 'Instruction':
            return class_exists('\Scoring\KRA1Scorer')
                ? \Scoring\KRA1Scorer::computeFromRemarks($remarks)
                : 0.0;
        case 'Research':
            return class_exists('\Scoring\KRA2Scorer')
                ? \Scoring\KRA2Scorer::computeFromRemarks($remarks)
                : 0.0;
        case 'Extension':
            return class_exists('\Scoring\KRA3Scorer')
                ? \Scoring\KRA3Scorer::computeFromRemarks($remarks)
                : 0.0;
        case 'Professional Development':
            return class_exists('\Scoring\KRA4Scorer')
                ? \Scoring\KRA4Scorer::computeFromRemarks($remarks)
                : 0.0;
        default:
            return 0.0;
    }
}

/**
 * Aggregate all pre-eval entries for a user through the official scorer
 * modules so that sub-caps (KRA I: 60/30/10; KRA II: sum-then-cap;
 * KRA III/IV: A+B+C+D capped at 100) are applied identically to the
 * live reclassification pipeline.
 *
 * Also detects auto sub-rank triggers (doctorate, national/intl award)
 * using the same rules as AutoSubrank::compute() but without needing a
 * full orchestrator DB run.
 *
 * Returns the same shape as computeWeightedScore() plus:
 *   kra1_detail, kra2_detail, kra3_detail, kra4_detail  — per-criterion breakdowns
 *   has_doctorate, has_national_award                   — trigger flags
 *   auto_subrank                                        — bump detection result
 *   pending_documentation, config_incomplete            — scorer flags
 */
function peAggregateScores(\PDO $pdo, int $uid, string $rank): array
{
    // Load all entries grouped by category, shaped as scorer expects
    $stmt = $pdo->prepare("
        SELECT pe.entry_id AS submission_id,
               pe.kra_category,
               pe.remarks,
               pe.computed_points,
               GROUP_CONCAT(pf.original_filename ORDER BY pf.file_id SEPARATOR '|||') AS evidence_names
        FROM pre_eval_entries pe
        LEFT JOIN pre_eval_files pf ON pf.entry_id = pe.entry_id
        WHERE pe.user_id = ?
        GROUP BY pe.entry_id
        ORDER BY pe.created_at ASC
    ");
    $stmt->execute([$uid]);
    $all = $stmt->fetchAll(\PDO::FETCH_ASSOC);

    $by_cat = [];
    foreach ($all as $row) {
        $by_cat[$row['kra_category']][] = $row;
    }

    // ── Run official scorers ────────────────────────────────────
    $kra1 = class_exists('\Scoring\KRA1Scorer')
        ? \Scoring\KRA1Scorer::score($by_cat['Instruction'] ?? [])
        : ['subtotal'=>0,'criterion_a'=>0,'criterion_b'=>0,'criterion_c'=>0,'cap'=>100,'pending_documentation'=>[],'config_incomplete'=>[]];

    $kra2 = class_exists('\Scoring\KRA2Scorer')
        ? \Scoring\KRA2Scorer::score($by_cat['Research'] ?? [])
        : ['subtotal'=>0,'criterion_a_raw'=>0,'criterion_b_raw'=>0,'criterion_c_raw'=>0,'sum_before_cap'=>0,'cap'=>100,'pending_documentation'=>[],'config_incomplete'=>[]];

    $kra3 = class_exists('\Scoring\KRA3Scorer')
        ? \Scoring\KRA3Scorer::score($by_cat['Extension'] ?? [])
        : ['subtotal'=>0,'criterion_a'=>0,'criterion_b'=>0,'criterion_c'=>0,'criterion_d_bonus'=>0,'cap'=>100,'pending_documentation'=>[],'config_incomplete'=>[]];

    $kra4 = class_exists('\Scoring\KRA4Scorer')
        ? \Scoring\KRA4Scorer::score($by_cat['Professional Development'] ?? [])
        : ['subtotal'=>0,'criterion_a'=>0,'criterion_b'=>0,'criterion_c'=>0,'criterion_d_bonus'=>0,'cap'=>100,'has_doctorate'=>false,'has_national_award'=>false,'pending_documentation'=>[],'config_incomplete'=>[]];

    // ── Auto sub-rank trigger detection ────────────────────────
    // Mirrors AutoSubrank::compute() logic without the DB-heavy first-doctorate
    // historical check (pre-eval cannot know prior approved applications).
    // We give benefit of the doubt — if a doctorate is present, treat as first.
    $has_doctorate    = $kra4['has_doctorate']     ?? false;
    $has_nat_award    = $kra4['has_national_award'] ?? false;

    $doctorate_eligible_ranks = [
        'Instructor I','Instructor II','Instructor III',
        'Assistant Professor I','Assistant Professor II',
        'Assistant Professor III','Assistant Professor IV',
        'Associate Professor I','Associate Professor II',
        'Associate Professor III','Associate Professor IV',
    ];
    $rank_eligible   = in_array($rank, $doctorate_eligible_ranks);
    $auto_bump       = 0;
    $auto_triggers   = [];

    if ($has_doctorate && $rank_eligible) {
        $auto_bump++;
        $auto_triggers[] = 'doctorate';
    }
    // Award bonus only applies if weighted score already reaches increment > 0
    // We compute a provisional weighted score first to check the threshold.
    $weights_prov    = getKraWeights($rank);
    $ws_prov         = round(
        ($kra1['subtotal'] * $weights_prov['Instruction']) +
        ($kra2['subtotal'] * $weights_prov['Research']) +
        ($kra3['subtotal'] * $weights_prov['Extension']) +
        ($kra4['subtotal'] * $weights_prov['Professional Development']),
        2
    );
    if ($has_nat_award && $ws_prov >= 41) {
        $auto_bump++;
        $auto_triggers[] = 'award';
    }

    // ── Apply auto bump to base rank, then recompute weighted score ─
    $bumped_rank   = $rank;
    if ($auto_bump > 0) {
        $all_ranks   = facultyRanks();
        $cur_idx     = array_search($rank, $all_ranks);
        if ($cur_idx !== false) {
            $bumped_idx  = min($cur_idx + $auto_bump, count($all_ranks) - 1);
            $bumped_rank = $all_ranks[$bumped_idx];
        }
    }

    $weights      = getKraWeights($bumped_rank);
    $weighted     = round(
        ($kra1['subtotal'] * $weights['Instruction']) +
        ($kra2['subtotal'] * $weights['Research']) +
        ($kra3['subtotal'] * $weights['Extension']) +
        ($kra4['subtotal'] * $weights['Professional Development']),
        2
    );
    $sub_rank     = getSubRankIncrement($weighted);

    // ── Potential rank ──────────────────────────────────────────
    $all_ranks    = facultyRanks();
    $bumped_idx   = array_search($bumped_rank, $all_ranks);
    $target_rank  = ($bumped_idx !== false && $sub_rank > 0)
        ? $all_ranks[min($bumped_idx + $sub_rank, count($all_ranks) - 1)]
        : ($sub_rank === 0 ? $rank : $bumped_rank);

    // ── Merge pending/config flags ──────────────────────────────
    $pending  = array_merge(
        $kra1['pending_documentation'],
        $kra2['pending_documentation'],
        $kra3['pending_documentation'],
        $kra4['pending_documentation']
    );
    $config_i = array_merge(
        $kra1['config_incomplete'],
        $kra2['config_incomplete'],
        $kra3['config_incomplete'],
        $kra4['config_incomplete']
    );

    return [
        // Top-level (same shape as computeWeightedScore + computePotentialRank)
        'kra1'               => $kra1['subtotal'],
        'kra2'               => $kra2['subtotal'],
        'kra3'               => $kra3['subtotal'],
        'kra4'               => $kra4['subtotal'],
        'weighted_score'     => $weighted,
        'sub_rank_increment' => $sub_rank,
        'weights'            => $weights,
        'pot_rank'           => $target_rank,
        // Per-criterion breakdowns
        'kra1_detail' => [
            'criterion_a' => $kra1['criterion_a'],
            'criterion_b' => $kra1['criterion_b'],
            'criterion_c' => $kra1['criterion_c'],
            'subtotal'    => $kra1['subtotal'],
            'cap'         => $kra1['cap'],
        ],
        'kra2_detail' => [
            'criterion_a_raw'  => $kra2['criterion_a_raw'],
            'criterion_b_raw'  => $kra2['criterion_b_raw'],
            'criterion_c_raw'  => $kra2['criterion_c_raw'],
            'sum_before_cap'   => $kra2['sum_before_cap'],
            'subtotal'         => $kra2['subtotal'],
            'cap'              => $kra2['cap'],
        ],
        'kra3_detail' => [
            'criterion_a'     => $kra3['criterion_a'],
            'criterion_b'     => $kra3['criterion_b'],
            'criterion_c'     => $kra3['criterion_c'],
            'criterion_d_bonus'=> $kra3['criterion_d_bonus'],
            'subtotal'        => $kra3['subtotal'],
            'cap'             => $kra3['cap'],
        ],
        'kra4_detail' => [
            'criterion_a'     => $kra4['criterion_a'],
            'criterion_b'     => $kra4['criterion_b'],
            'criterion_c'     => $kra4['criterion_c'],
            'criterion_d_bonus'=> $kra4['criterion_d_bonus'],
            'subtotal'        => $kra4['subtotal'],
            'cap'             => $kra4['cap'],
        ],
        // Auto sub-rank
        'has_doctorate'      => $has_doctorate,
        'has_national_award' => $has_nat_award,
        'auto_subrank' => [
            'applied'           => $auto_bump > 0,
            'trigger'           => implode('|', $auto_triggers) ?: 'none',
            'bonus_increment'   => $auto_bump,
            'updated_base_rank' => $bumped_rank,
            'note'              => $auto_bump > 0
                ? 'Pre-eval auto sub-rank is indicative. First-doctorate historical check requires a full orchestrator run.'
                : '',
        ],
        // Flags
        'pending_documentation' => $pending,
        'config_incomplete'     => $config_i,
    ];
}

// ── Helper: upload a file ──────────────────────────────────────
function peUploadFile(array $file, string $cat, int $kra_num): array|false {
    if ($file['error'] !== 0) return false;
    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, ['pdf','jpg','jpeg','png'])) return false;
    if ($file['size'] > 50*1024*1024) return false;
    $folder = __DIR__ . '/../uploads/pre_eval/kra' . $kra_num . '/';
    if (!is_dir($folder)) mkdir($folder, 0755, true);
    $fname = 'pe_' . uniqid() . '.' . $ext;
    if (!move_uploaded_file($file['tmp_name'], $folder . $fname)) return false;
    return [
        'path' => 'uploads/pre_eval/kra' . $kra_num . '/' . $fname,
        'name' => $file['name'],
        'size' => $file['size'],
    ];
}

$valid_cats = ['Instruction','Research','Extension','Professional Development'];

// ── Eligibility gate (server-side enforcement) ────────────────
// Presence check only — authenticity verified by human checkers.
function checkPreEvalEligibility(\PDO $pdo, int $uid): array {
    $fac = $pdo->prepare("SELECT rank FROM users WHERE user_id=?");
    $fac->execute([$uid]);
    $rank = (string)($fac->fetchColumn() ?? '');
    $all_ranks = [
        'Instructor I','Instructor II','Instructor III',
        'Assistant Professor I','Assistant Professor II','Assistant Professor III','Assistant Professor IV',
        'Associate Professor I','Associate Professor II','Associate Professor III','Associate Professor IV','Associate Professor V',
        'Professor I','Professor II','Professor III','Professor IV','Professor V','Professor VI',
        'University Professor',
    ];
    if (empty($rank))                   return ['eligible'=>false,'reason'=>'Faculty rank not set.'];
    if (!in_array($rank, $all_ranks))   return ['eligible'=>false,'reason'=>"Rank '{$rank}' not recognised."];
    return ['eligible'=>true,'reason'=>''];
}

// ── GET: entries ───────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'GET' && $action === 'get_entries') {
    $cat = $_GET['cat'] ?? '';
    if (!in_array($cat, $valid_cats)) { echo json_encode(['ok'=>false,'error'=>'Invalid category']); exit; }

    // Single JOIN + GROUP_CONCAT replaces the N+1 per-entry file query
    $stmt = $pdo->prepare("
        SELECT pe.entry_id, pe.remarks, pe.computed_points, pe.notes, pe.created_at,
               GROUP_CONCAT(pf.file_id       ORDER BY pf.uploaded_at ASC SEPARATOR '||') AS file_ids,
               GROUP_CONCAT(pf.original_filename ORDER BY pf.uploaded_at ASC SEPARATOR '||') AS file_names,
               GROUP_CONCAT(pf.file_path     ORDER BY pf.uploaded_at ASC SEPARATOR '||') AS file_paths,
               GROUP_CONCAT(pf.file_size_bytes ORDER BY pf.uploaded_at ASC SEPARATOR '||') AS file_sizes
        FROM pre_eval_entries pe
        LEFT JOIN pre_eval_files pf ON pf.entry_id = pe.entry_id
        WHERE pe.user_id = ? AND pe.kra_category = ?
        GROUP BY pe.entry_id
        ORDER BY pe.created_at ASC
    ");
    $stmt->execute([$uid, $cat]);
    $entries = $stmt->fetchAll();

    // Expand the GROUP_CONCAT columns back into a files array
    foreach ($entries as &$e) {
        $ids   = $e['file_ids']   ? explode('||', $e['file_ids'])   : [];
        $names = $e['file_names'] ? explode('||', $e['file_names']) : [];
        $paths = $e['file_paths'] ? explode('||', $e['file_paths']) : [];
        $sizes = $e['file_sizes'] ? explode('||', $e['file_sizes']) : [];
        $files = [];
        foreach ($ids as $i => $fid) {
            $files[] = [
                'file_id'          => (int)$fid,
                'original_filename'=> $names[$i] ?? '',
                'file_path'        => $paths[$i] ?? '',
                'file_size_bytes'  => (int)($sizes[$i] ?? 0),
            ];
        }
        $e['files'] = $files;
        unset($e['file_ids'], $e['file_names'], $e['file_paths'], $e['file_sizes']);
    }
    unset($e);

    echo json_encode(['ok'=>true, 'entries'=>$entries]);
    exit;
}

// ── GET: all repository files (for cross-KRA repository) ──────
if ($_SERVER['REQUEST_METHOD'] === 'GET' && $action === 'get_all_files') {
    $stmt = $pdo->prepare("SELECT file_id, kra_category, original_filename, file_path, file_size_bytes, description, uploaded_at FROM pre_eval_files WHERE user_id=? AND entry_id IS NULL ORDER BY uploaded_at DESC");
    $stmt->execute([$uid]);
    echo json_encode(['ok'=>true, 'files'=>$stmt->fetchAll()]);
    exit;
}

// ── GET: repository files ──────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'GET' && $action === 'get_files') {
    $cat = $_GET['cat'] ?? '';
    if (!in_array($cat, $valid_cats)) { echo json_encode(['ok'=>false,'error'=>'Invalid category']); exit; }
    $stmt = $pdo->prepare("SELECT file_id, original_filename, file_path, file_size_bytes, description, uploaded_at FROM pre_eval_files WHERE user_id=? AND kra_category=? AND entry_id IS NULL ORDER BY uploaded_at DESC");
    $stmt->execute([$uid, $cat]);
    echo json_encode(['ok'=>true, 'files'=>$stmt->fetchAll()]);
    exit;
}

// ── GET: score summary ─────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'GET' && $action === 'get_score') {
    $rankRow = $pdo->prepare("SELECT rank FROM users WHERE user_id=?");
    $rankRow->execute([$uid]);
    $rank = (string)($rankRow->fetchColumn() ?? '');

    // ── Use the official scorer modules with proper sub-caps ───
    // This is the same logic the orchestrator uses — the only difference
    // is checker involvement (none here) and the first-doctorate DB check
    // (benefit of the doubt given; noted in auto_subrank.note).
    $agg = peAggregateScores($pdo, $uid, $rank);

    // ── Document completeness — presence only ──────────────────
    $total_entries_q = $pdo->prepare("SELECT COUNT(*) FROM pre_eval_entries WHERE user_id=?");
    $total_entries_q->execute([$uid]);
    $total_entries = (int)$total_entries_q->fetchColumn();
    $with_files_q  = $pdo->prepare("
        SELECT COUNT(DISTINCT pe.entry_id) FROM pre_eval_entries pe
        WHERE pe.user_id = ? AND EXISTS (SELECT 1 FROM pre_eval_files pf WHERE pf.entry_id = pe.entry_id)
    ");
    $with_files_q->execute([$uid]);
    $with_files = (int)$with_files_q->fetchColumn();

    // ── Workflow status — advisory only at pre-eval stage ──────
    $workflow = 'draft';
    $active_cycle = getActiveCycle($pdo);
    if ($active_cycle) {
        $app_status_q = $pdo->prepare("SELECT status FROM applications WHERE user_id=? AND cycle_id=? LIMIT 1");
        $app_status_q->execute([$uid, $active_cycle['cycle_id']]);
        $app_status = $app_status_q->fetchColumn();
        if ($app_status) {
            $workflow = match($app_status) {
                'submitted','under_review','needs_revision','rejected','edit_requested' => 'local_checker_review',
                'talisay_review' => 'main_checker_review',
                'approved','reclassified','admin_rejected' => 'completed',
                default => 'draft',
            };
        }
    }

    $gate = checkPreEvalEligibility($pdo, $uid);

    echo json_encode([
        'ok'       => true,
        'weighted' => $agg['weighted_score'],
        'sub_rank' => $agg['sub_rank_increment'],
        'kra'      => [
            'kra1' => $agg['kra1'],
            'kra2' => $agg['kra2'],
            'kra3' => $agg['kra3'],
            'kra4' => $agg['kra4'],
        ],
        'kra_detail' => [
            'kra1' => $agg['kra1_detail'],
            'kra2' => $agg['kra2_detail'],
            'kra3' => $agg['kra3_detail'],
            'kra4' => $agg['kra4_detail'],
        ],
        'weights'  => array_map(fn($w) => round($w * 100), $agg['weights']),
        'pot_rank' => $agg['pot_rank'],
        // Pre-evaluation output schema
        'pre_evaluation' => [
            'eligible'           => $gate['eligible'],
            'documents_present'  => ($total_entries > 0 && $with_files === $total_entries),
            'entries_total'      => $total_entries,
            'entries_with_files' => $with_files,
            'completeness_pct'   => $total_entries > 0
                ? min(100, (int)round(($with_files / $total_entries) * 100))
                : 0,
        ],
        'workflow_status'       => $workflow,
        'auto_subrank'          => $agg['auto_subrank'],
        'pending_documentation' => $agg['pending_documentation'],
        'config_incomplete'     => $agg['config_incomplete'],
        'scoring' => [
            'points_computed' => $agg['weighted_score'],
            'computed_rank'   => $agg['pot_rank'],
        ],
    ]);
    exit;
}

// ── POST: preview score ────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'preview_score') {
    $cat     = $_POST['cat']     ?? '';
    $remarks = trim($_POST['remarks'] ?? '');
    $points  = ($remarks && in_array($cat, $valid_cats)) ? peComputeScore($cat, $remarks) : 0.0;
    echo json_encode(['ok'=>true, 'points'=>$points]);
    exit;
}

// ── POST: save entry ───────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'save_entry') {
    $gate = checkPreEvalEligibility($pdo, $uid);
    if (!$gate['eligible']) {
        echo json_encode(['ok'=>false,'error'=>'Eligibility check failed: '.$gate['reason']]); exit;
    }
    $cat     = $_POST['cat']     ?? '';
    $remarks = trim($_POST['remarks'] ?? '');
    $notes   = trim($_POST['notes']   ?? '');
    $edit_id = intval($_POST['entry_id'] ?? 0);

    if (!in_array($cat, $valid_cats) || !$remarks) {
        echo json_encode(['ok'=>false,'error'=>'Missing required fields']); exit;
    }

    $points  = peComputeScore($cat, $remarks);
    $kra_num = array_search($cat, $valid_cats) + 1;

    // Handle optional file upload
    $file_data = null;
    if (!empty($_FILES['evidence']['name']) && $_FILES['evidence']['error'] === 0) {
        $file_data = peUploadFile($_FILES['evidence'], $cat, $kra_num);
        if (!$file_data) { echo json_encode(['ok'=>false,'error'=>'Invalid file. Use PDF, JPG, or PNG (max 50 MB).']); exit; }
    }

    if ($edit_id > 0) {
        $chk = $pdo->prepare("SELECT entry_id FROM pre_eval_entries WHERE entry_id=? AND user_id=?");
        $chk->execute([$edit_id, $uid]);
        if (!$chk->fetch()) { echo json_encode(['ok'=>false,'error'=>'Entry not found']); exit; }
        $pdo->prepare("UPDATE pre_eval_entries SET remarks=?, computed_points=?, notes=?, updated_at=NOW() WHERE entry_id=?")
            ->execute([$remarks, $points, $notes, $edit_id]);
        $saved_id = $edit_id;
        logAudit($pdo, $uid, 'Pre-Eval Entry Updated', "{$cat}: entry #{$edit_id} updated ({$points} pts).");
    } else {
        $pdo->prepare("INSERT INTO pre_eval_entries (user_id, kra_category, remarks, computed_points, notes) VALUES (?,?,?,?,?)")
            ->execute([$uid, $cat, $remarks, $points, $notes]);
        $saved_id = (int)$pdo->lastInsertId();
        logAudit($pdo, $uid, 'Pre-Eval Entry Added', "{$cat}: new entry #{$saved_id} ({$points} pts).");
    }

    if ($file_data) {
        $pdo->prepare("INSERT INTO pre_eval_files (user_id, entry_id, kra_category, file_path, original_filename, file_size_bytes) VALUES (?,?,?,?,?,?)")
            ->execute([$uid, $saved_id, $cat, $file_data['path'], $file_data['name'], $file_data['size']]);
    }

    // Return updated files for this entry
    $fs = $pdo->prepare("SELECT file_id, original_filename, file_path, file_size_bytes FROM pre_eval_files WHERE entry_id=? ORDER BY uploaded_at ASC");
    $fs->execute([$saved_id]);
    echo json_encode(['ok'=>true, 'entry_id'=>$saved_id, 'computed_points'=>$points, 'files'=>$fs->fetchAll()]);
    exit;
}

// ── POST: attach file to existing entry ───────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'attach_file') {
    $entry_id = intval($_POST['entry_id'] ?? 0);
    $cat      = $_POST['cat'] ?? '';

    if (!$entry_id || !in_array($cat, $valid_cats)) { echo json_encode(['ok'=>false,'error'=>'Invalid request']); exit; }
    $chk = $pdo->prepare("SELECT entry_id FROM pre_eval_entries WHERE entry_id=? AND user_id=?");
    $chk->execute([$entry_id, $uid]);
    if (!$chk->fetch()) { echo json_encode(['ok'=>false,'error'=>'Entry not found']); exit; }

    if (empty($_FILES['evidence']['name']) || $_FILES['evidence']['error'] !== 0) {
        echo json_encode(['ok'=>false,'error'=>'No file received']); exit;
    }
    $kra_num = array_search($cat, $valid_cats) + 1;
    $fd = peUploadFile($_FILES['evidence'], $cat, $kra_num);
    if (!$fd) { echo json_encode(['ok'=>false,'error'=>'Invalid file type or too large. Use PDF, JPG, or PNG (max 50 MB).']); exit; }

    $pdo->prepare("INSERT INTO pre_eval_files (user_id, entry_id, kra_category, file_path, original_filename, file_size_bytes) VALUES (?,?,?,?,?,?)")
        ->execute([$uid, $entry_id, $cat, $fd['path'], $fd['name'], $fd['size']]);
    $fid = (int)$pdo->lastInsertId();
    echo json_encode(['ok'=>true, 'file_id'=>$fid, 'original_filename'=>$fd['name'], 'file_path'=>$fd['path'], 'file_size_bytes'=>$fd['size']]);
    exit;
}

// ── POST: delete entry ─────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'delete_entry') {
    $eid = intval($_POST['entry_id'] ?? 0);
    $chk = $pdo->prepare("SELECT entry_id FROM pre_eval_entries WHERE entry_id=? AND user_id=?");
    $chk->execute([$eid, $uid]);
    if (!$chk->fetch()) { echo json_encode(['ok'=>false,'error'=>'Not found']); exit; }
    $fs = $pdo->prepare("SELECT file_path FROM pre_eval_files WHERE entry_id=?");
    $fs->execute([$eid]);
    foreach ($fs->fetchAll(PDO::FETCH_COLUMN) as $fp) {
        $abs = realpath(__DIR__.'/../').DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $fp);
        if (file_exists($abs)) @unlink($abs);
    }
    $pdo->prepare("DELETE FROM pre_eval_entries WHERE entry_id=?")->execute([$eid]);
    logAudit($pdo, $uid, 'Pre-Eval Entry Deleted', "Entry #{$eid} deleted.");
    echo json_encode(['ok'=>true]);
    exit;
}

// ── POST: delete ALL entries for one category (Reset tab) ──────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'delete_all_entries') {
    $cat = $_POST['cat'] ?? '';
    $valid_cats_del = ['Instruction','Research','Extension','Professional Development'];
    if (!in_array($cat, $valid_cats_del)) { echo json_encode(['ok'=>false,'error'=>'Invalid category']); exit; }

    // Delete all evidence files for this user + category
    $fs = $pdo->prepare("
        SELECT pf.file_path
        FROM pre_eval_files pf
        JOIN pre_eval_entries pe ON pf.entry_id = pe.entry_id
        WHERE pe.user_id = ? AND pe.kra_category = ?
    ");
    $fs->execute([$uid, $cat]);
    foreach ($fs->fetchAll(PDO::FETCH_COLUMN) as $fp) {
        $abs = realpath(__DIR__.'/../').DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $fp);
        if (file_exists($abs)) @unlink($abs);
    }

    // Delete all entries
    $pdo->prepare("DELETE FROM pre_eval_entries WHERE user_id = ? AND kra_category = ?")
        ->execute([$uid, $cat]);
    logAudit($pdo, $uid, 'Pre-Eval Reset Tab', "All entries in {$cat} deleted (Reset All).");
    echo json_encode(['ok'=>true]);
    exit;
}

// ── POST: delete file ──────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'delete_file') {
    $fid = intval($_POST['file_id'] ?? 0);
    $row = $pdo->prepare("SELECT file_path FROM pre_eval_files WHERE file_id=? AND user_id=?");
    $row->execute([$fid, $uid]);
    $row = $row->fetch();
    if (!$row) { echo json_encode(['ok'=>false,'error'=>'Not found']); exit; }
    $abs = realpath(__DIR__.'/../').DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $row['file_path']);
    if (file_exists($abs)) @unlink($abs);
    $pdo->prepare("DELETE FROM pre_eval_files WHERE file_id=?")->execute([$fid]);
    logAudit($pdo, $uid, 'Pre-Eval File Deleted', "Evidence file #{$fid} deleted.");
    echo json_encode(['ok'=>true]);
    exit;
}

// ── POST: upload to repository ─────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'upload_repo') {
    $cat  = $_POST['cat']  ?? '';
    $desc = trim($_POST['description'] ?? '');
    if (!in_array($cat, $valid_cats)) { echo json_encode(['ok'=>false,'error'=>'Invalid category']); exit; }
    if (empty($_FILES['file']['name']) || $_FILES['file']['error'] !== 0) {
        echo json_encode(['ok'=>false,'error'=>'No file received']); exit;
    }
    $kra_num = array_search($cat, $valid_cats) + 1;
    $fd = peUploadFile($_FILES['file'], $cat, $kra_num);
    if (!$fd) { echo json_encode(['ok'=>false,'error'=>'Invalid file type or too large (max 50MB)']); exit; }
    $pdo->prepare("INSERT INTO pre_eval_files (user_id, entry_id, kra_category, file_path, original_filename, file_size_bytes, description) VALUES (?,NULL,?,?,?,?,?)")
        ->execute([$uid, $cat, $fd['path'], $fd['name'], $fd['size'], $desc]);
    $fid = (int)$pdo->lastInsertId();
    echo json_encode(['ok'=>true,'file_id'=>$fid,'original_filename'=>$fd['name'],'file_path'=>$fd['path'],'file_size_bytes'=>$fd['size'],'description'=>$desc]);
    exit;
}

echo json_encode(['ok'=>false,'error'=>'Unknown action']);
