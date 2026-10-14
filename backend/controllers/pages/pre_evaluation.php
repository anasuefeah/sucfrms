<?php
// Request handling and data loading for frontend/pages/pre_evaluation.php
/**
 * Pre-Evaluation  -  standalone page, no sidebar.
 */
if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__ . '/../../database/config/db.php';
require_once __DIR__ . '/../../core/functions.php';

if (!isLoggedIn()) { header('Location: login.php'); exit; }
if (($_SESSION['role'] ?? '') !== 'faculty') {
    header('Location: ../../index.php'); exit;
}
unset($_SESSION['force_pw_change']);

$uid = $_SESSION['user_id'];

// Runtime migration
try { $pdo->query("SELECT entry_id FROM pre_eval_entries LIMIT 1"); }
catch (\Exception $e) {
    $pdo->exec("CREATE TABLE IF NOT EXISTS pre_eval_entries (entry_id INT AUTO_INCREMENT PRIMARY KEY, user_id INT NOT NULL, kra_category ENUM('Instruction','Research','Extension','Professional Development') NOT NULL, remarks TEXT NOT NULL, computed_points DECIMAL(6,2) DEFAULT 0, notes TEXT DEFAULT NULL, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP, FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE CASCADE)");
    $pdo->exec("CREATE TABLE IF NOT EXISTS pre_eval_files (file_id INT AUTO_INCREMENT PRIMARY KEY, user_id INT NOT NULL, entry_id INT DEFAULT NULL, kra_category ENUM('Instruction','Research','Extension','Professional Development') NOT NULL, file_path VARCHAR(255) NOT NULL, original_filename VARCHAR(255) NOT NULL, file_size_bytes INT DEFAULT 0, description VARCHAR(255) DEFAULT NULL, uploaded_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE CASCADE, FOREIGN KEY (entry_id) REFERENCES pre_eval_entries(entry_id) ON DELETE SET NULL)");
}
// Migration: pre_eval_auto_sub_rank table
try { $pdo->query("SELECT pea_id FROM pre_eval_auto_sub_rank LIMIT 1"); }
catch (\Exception $e) {
    $pdo->exec("CREATE TABLE IF NOT EXISTS pre_eval_auto_sub_rank (
        pea_id            INT AUTO_INCREMENT PRIMARY KEY,
        user_id           INT NOT NULL UNIQUE,
        doctorate_status  ENUM('Triggered','Not Triggered','Needs Review') DEFAULT 'Needs Review',
        award_status      ENUM('Triggered','Not Triggered','Needs Review') DEFAULT 'Needs Review',
        doctorate_details TEXT DEFAULT NULL,
        award_details     TEXT DEFAULT NULL,
        award_evidence    VARCHAR(255) DEFAULT NULL,
        updated_at        TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE CASCADE
    )");
}

// -- Auto Sub Rank is fully automatic  -  no POST handler needed here.
// The tab recalculates on every page view via AutoSubRankCalculator.

// Faculty info
$fac = $pdo->prepare("SELECT full_name, first_name, last_name, middle_name, rank, profile_pic FROM users WHERE user_id=?");
$fac->execute([$uid]);
$fac         = $fac->fetch();
$full_name = formatDisplayName($fac);
$rank        = $fac['rank']        ?? '';
$profile_pic = $fac['profile_pic'] ?? '';
$init        = strtoupper(substr($fac['first_name']??'U',0,1).substr($fac['last_name']??'',0,1)) ?: 'FA';

// -- PRE-EVALUATION ELIGIBILITY GATE -----------------------------------------
// Constraint: verify rank readiness only. Authenticity of documents is handled
// exclusively by human reviewers at Stage 1 (Subcommittee) and Stage 2 (ITC).
$eligible          = true;
$eligibility_notes = [];

// (a) Rank check  -  must have a recognised faculty rank to proceed
$all_ranks = [
    'Instructor I','Instructor II','Instructor III',
    'Assistant Professor I','Assistant Professor II','Assistant Professor III','Assistant Professor IV',
    'Associate Professor I','Associate Professor II','Associate Professor III','Associate Professor IV','Associate Professor V',
    'Professor I','Professor II','Professor III','Professor IV','Professor V','Professor VI',
    'University Professor',
];
if (empty($rank)) {
    $eligible = false;
    $eligibility_notes[] = 'Faculty rank is not set. Contact your administrator to update your profile before pre-evaluating.';
} elseif (!in_array($rank, $all_ranks)) {
    $eligible = false;
    $eligibility_notes[] = "Current rank '{$rank}' is not a recognised reclassification rank. Contact your administrator.";
}

// (b) Readiness check  -  cannot pre-evaluate if already in an active cycle with a locked application
$active_cycle = getActiveCycle($pdo);
if ($active_cycle) {
    $existing_app = $pdo->prepare("SELECT status FROM applications WHERE user_id=? AND cycle_id=? LIMIT 1");
    $existing_app->execute([$uid, $active_cycle['cycle_id']]);
    $existing_app = $existing_app->fetch();
    if ($existing_app && in_array($existing_app['status'], ['approved','reclassified','admin_rejected'])) {
        $eligible = false;
        $eligibility_notes[] = 'Your application for the current cycle has already been finalised ('
            . ucwords(str_replace('_',' ', $existing_app['status'])) . '). Pre-evaluation is not applicable.';
    }
}

// Document completeness check  -  presence only, not authenticity
// Count entries that have at least one attached file (evidence presence only)
$entries_with_files_q = $pdo->prepare("
    SELECT COUNT(DISTINCT pe.entry_id)
    FROM pre_eval_entries pe
    WHERE pe.user_id = ?
      AND EXISTS (SELECT 1 FROM pre_eval_files pf WHERE pf.entry_id = pe.entry_id)
");
$entries_with_files_q->execute([$uid]);
$entries_with_files  = (int)$entries_with_files_q->fetchColumn();
$entries_total_q = $pdo->prepare("SELECT COUNT(*) FROM pre_eval_entries WHERE user_id=?");
$entries_total_q->execute([$uid]);
$entry_count_total  = (int)$entries_total_q->fetchColumn();
$docs_completeness_pct = $entry_count_total > 0
    ? min(100, (int)round(($entries_with_files / $entry_count_total) * 100))
    : 0;
$documents_present = ($entry_count_total > 0 && $entries_with_files === $entry_count_total);

// Current scores
$sc = $pdo->prepare("SELECT kra_category, SUM(computed_points) as total FROM pre_eval_entries WHERE user_id=? GROUP BY kra_category");
$sc->execute([$uid]);
$raw = [];
foreach ($sc->fetchAll() as $r) $raw[$r['kra_category']] = (float)$r['total'];

$score_result = computeWeightedScore($raw, $rank);
$weighted     = $score_result['weighted_score'];
$sub_rank     = $score_result['sub_rank_increment'];
$weights      = $score_result['weights'];
$potential    = computePotentialRank($raw, $rank);
$pot_rank     = $potential['potential_rank'];

$kra_cats = ['Instruction','Research','Extension','Professional Development'];
$kra_tabs = [
    'instruction' => ['label'=>'Instruction',         'cat'=>'Instruction',              'icon'=>'bi-book-half',        'max'=>100, 'color'=>'#1e4d8c', 'num'=>1],
    'research'    => ['label'=>'Research',             'cat'=>'Research',                 'icon'=>'bi-journal-text',     'max'=>100, 'color'=>'#1a3a6b', 'num'=>2],
    'extension'   => ['label'=>'Extension',            'cat'=>'Extension',                'icon'=>'bi-people-fill',      'max'=>100, 'color'=>'#1e4d8c', 'num'=>3],
    'profdev'     => ['label'=>'Prof. Development',    'cat'=>'Professional Development', 'icon'=>'bi-award-fill',       'max'=>100, 'color'=>'#475569', 'num'=>4],
    'autosubrank' => ['label'=>'Auto Sub Rank',         'cat'=>'Auto Sub Rank',            'icon'=>'bi-arrow-up-circle',  'max'=>0,   'color'=>'#1a3a6b', 'num'=>5],
    'posreq'      => ['label'=>'Position Requirements','cat'=>'Position Requirements',    'icon'=>'bi-file-earmark-check','max'=>0,   'color'=>'#475569', 'num'=>6],
];

$active_tab = $_GET['tab'] ?? 'instruction';
if (!array_key_exists($active_tab, $kra_tabs)) $active_tab = 'instruction';
$cur     = $kra_tabs[$active_tab];
$cur_cat = $cur['cat'];
$kra_num = $cur['num'];

// Tab entry counts  -  only for KRA tabs
$counts = [];
$kra_slugs = ['instruction','research','extension','profdev'];
foreach ($kra_tabs as $slug => $t) {
    if (in_array($slug, $kra_slugs)) {
        $s = $pdo->prepare("SELECT COUNT(*) FROM pre_eval_entries WHERE user_id=? AND kra_category=?");
        $s->execute([$uid, $t['cat']]);
        $counts[$slug] = (int)$s->fetchColumn();
    } else {
        $counts[$slug] = 0;
    }
}

// Evidence guide  -  load ALL criteria for all KRA categories
$all_criteria = [];
try {
    $cg = $pdo->query("
        SELECT kra_category, criterion_label, max_points, description
        FROM scoring_criteria
        WHERE cycle_id IS NULL AND position_rank IS NULL AND is_active = 1
        ORDER BY kra_category, CASE WHEN criterion_label LIKE 'Criterion A%' THEN 1 WHEN criterion_label LIKE 'Criterion B%' THEN 2 WHEN criterion_label LIKE 'Criterion C%' THEN 3 WHEN criterion_label LIKE 'Criterion D%' THEN 4 ELSE 5 END ASC, max_points DESC, criterion_label ASC
    ");
    foreach ($cg->fetchAll() as $row) {
        $all_criteria[$row['kra_category']][] = $row;
    }
} catch (\Exception $e) { $all_criteria = []; }
