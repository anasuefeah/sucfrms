<?php
/**
 * One-time fix: recomputes computed_points for all kra_submissions where score = 0
 * but remarks contain enough info to compute the correct score.
 * Run once: http://localhost/SUCFRMS/pages/fix_scores.php
 */
require_once __DIR__ . '/../config/db.php';

$rows = $pdo->query("SELECT submission_id, kra_category, remarks, computed_points FROM kra_submissions WHERE computed_points = 0")->fetchAll();

$fixed = 0;
$skipped = 0;

foreach ($rows as $row) {
    $remarks  = $row['remarks'];
    $category = $row['kra_category'];
    $p        = array_map('trim', explode('|||', $remarks));
    $p_norm   = array_map(fn($x) => html_entity_decode(str_replace(['&mdash;','–','—',"\u{2014}"], '-', $x), ENT_HTML5, 'UTF-8'), $p);
    $points   = 0.0;

    switch ($category) {
        case 'Instruction':
            $ct = $p[0] ?? '';
            if ($ct === 'A-set-sef') {
                $set = min(100, max(0, (float)($p[1] ?? 0)));
                $sef = min(100, max(0, (float)($p[2] ?? 0)));
                $points = round(($set/100)*36 + ($sef/100)*24, 2);
            } elseif ($ct === 'B-material') {
                $label   = $p_norm[1] ?? '';
                $contrib = (float)($p[2] ?? 100);
                if ($contrib <= 0) $contrib = 100;
                $contrib = min(100, $contrib);
                $base    = 0;
                if      (stripos($label,'Textbook') !== false && stripos($label,'Chapter') !== false && stripos($label,'Sole') !== false) $base = 10;
                elseif  (stripos($label,'Textbook') !== false && stripos($label,'Chapter') !== false)                                     $base = 10;
                elseif  (stripos($label,'Textbook') !== false && stripos($label,'Sole') !== false)                                        $base = 30;
                elseif  (stripos($label,'Textbook') !== false)                                                                            $base = 30;
                elseif  (stripos($label,'Manual') !== false && stripos($label,'Sole') !== false)                                          $base = 16;
                elseif  (stripos($label,'Manual') !== false || stripos($label,'Module') !== false)                                        $base = 16;
                elseif  (stripos($label,'Multimedia') !== false)                                                                          $base = 16;
                elseif  (stripos($label,'Validated') !== false || stripos($label,'Testing') !== false)                                    $base = 10;
                elseif  (stripos($label,'Lead') !== false)                                                                                $base = 10;
                elseif  (stripos($label,'Contributor') !== false || stripos($label,'Contrib') !== false)                                  $base = 5;
                // Extract pts from label like "(30 pts)"
                if ($base === 0 && preg_match('/\((\d+(?:\.\d+)?)\s*pts?\)/i', $label, $mp)) $base = (float)$mp[1];
                $isCo = stripos($label,'co') !== false && (stripos($label,'author') !== false || stripos($label,'contrib') !== false || stripos($label,'×') !== false);
                $points = round($isCo ? $base * ($contrib/100) : $base, 2);
            } elseif ($ct === 'C-thesis') {
                $label = $p[1] ?? '';
                if      (stripos($label,'Doctoral') !== false && stripos($label,'Adviser') !== false) $points = 10;
                elseif  (stripos($label,"Master") !== false   && stripos($label,'Adviser') !== false) $points = 8;
                elseif  (stripos($label,'Undergraduate') !== false && stripos($label,'Adviser') !== false) $points = 5;
                elseif  (stripos($label,'Special') !== false  && stripos($label,'Adviser') !== false) $points = 3;
                elseif  (stripos($label,'Doctoral') !== false && stripos($label,'Panel') !== false)   $points = 6;  // corrected per JC01
                elseif  (stripos($label,"Master") !== false   && stripos($label,'Panel') !== false)   $points = 4;  // corrected per JC01
                elseif  (stripos($label,'Undergraduate') !== false && stripos($label,'Panel') !== false) $points = 2; // corrected per JC01
                elseif  (stripos($label,'Special') !== false  && stripos($label,'Panel') !== false)   $points = 1;
                // Mentorship: CONFIG_MENTORSHIP_POINTS not confirmed in JC01 p.78 — leave at 0 (pending admin config)
                elseif  (stripos($label,'Mentor') !== false)                                          $points = 0;
            }
            break;

        case 'Research':
            $label   = $p_norm[0] ?? '';
            $contrib = min(100, max(0, (float)($p[2] ?? 100)));
            $base    = 0;
            if (preg_match('/\((\d+(?:\.\d+)?)\s*pts?\)/i', $label, $m)) $base = (float)$m[1];
            if ($base === 0 && isset($p[1]) && is_numeric($p[1]))         $base = (float)$p[1];
            $points = round($base * ($contrib/100), 2);
            break;

        case 'Extension':
            $inc    = max(0, (float)($p[1] ?? 0));
            $moa    = max(0, (int)($p[2] ?? 0));
            $out    = max(0, (int)($p[3] ?? 0));
            $pts    = 0;
            if ($inc >= 12000000) $pts += 18; elseif ($inc >= 6000000) $pts += 12; elseif ($inc >= 500000) $pts += 6;
            $pts   += $moa * 5 + $out * 3;
            $points = (float)$pts;
            break;

        case 'Professional Development':
            $ct  = $p[0] ?? '';
            $sv  = max(0, (float)($p[2] ?? 0));
            $allowed = match($ct) {
                'A-org'      => [5.0],
                'B-training' => [1.0, 2.0],
                'B-paper'    => [3.0, 5.0],
                'B-degree'   => [0.0, 10.0, 20.0],
                'C-award'    => [2.0, 3.0, 4.0],
                default      => []
            };
            $points = ($ct === 'A-org') ? 5.0 : (in_array($sv, $allowed) ? $sv : 0.0);
            break;
    }

    if ($points > 0) {
        $pdo->prepare("UPDATE kra_submissions SET computed_points=? WHERE submission_id=?")
            ->execute([$points, $row['submission_id']]);
        echo "Fixed #{$row['submission_id']} ({$category}): 0 → {$points} | remarks: " . htmlspecialchars(substr($remarks, 0, 80)) . "<br>";
        $fixed++;
    } else {
        echo "Skipped #{$row['submission_id']} ({$category}): still 0 | remarks: " . htmlspecialchars(substr($remarks, 0, 80)) . "<br>";
        $skipped++;
    }
}

// Recalc all application weighted scores
$apps = $pdo->query("SELECT DISTINCT application_id FROM kra_submissions")->fetchAll(PDO::FETCH_COLUMN);
require_once __DIR__ . '/../includes/functions.php';
foreach ($apps as $aid) {
    try { recalcApplicationScore($pdo, (int)$aid); } catch (\Exception $e) {}
}

echo "<hr><strong>Done. Fixed: {$fixed} | Skipped: {$skipped}</strong>";
echo "<br><br><a href='../index.php?page=apply&step=2'>Back to KRA Entry</a>";
?>
