<?php
/**
 * Self-Assessment ISS PDF
 * Individual Summary Sheet — Rank-Weighted Scoring & Reclassification
 *
 * Standalone module. Same data as the main self-assessment PDF but renders
 * only the ISS page: faculty info header, rank-weighted scoring table (all
 * 5 tiers), sub-rank bracket reference, and the 9-row breakdown block.
 *
 * Uses the same two-pass Orchestrator-aligned engine (includes/pre_eval_scoring.php)
 * so the numbers exactly match what the AJAX preview and the main PDF show.
 */
ob_start();
if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../fpdf/fpdf.php';
require_once __DIR__ . '/../includes/pre_eval_scoring.php';

if (!isLoggedIn() || !isFaculty()) {
    ob_end_clean();
    http_response_code(403);
    die('Access denied.');
}

$uid = (int)$_SESSION['user_id'];

// UTF-8 → windows-1252 for FPDF
function iss_u(string $s): string {
    return iconv('UTF-8', 'windows-1252//TRANSLIT//IGNORE', $s);
}

// -- Faculty --------------------------------------------------
$fac = $pdo->prepare("
    SELECT u.*, c.campus_name
    FROM users u
    LEFT JOIN campuses c ON u.campus_id = c.campus_id
    WHERE u.user_id = ?
");
$fac->execute([$uid]);
$fac = $fac->fetch();
if (!$fac) { ob_end_clean(); http_response_code(404); die('Faculty not found.'); }

$rank      = $fac['rank'] ?? '';
$full_name = formatDisplayName($fac);
$campus    = $fac['campus_name'] ?? 'N/A';
$generated = date('F d, Y  h:i A');

// -- Scores (two-pass Orchestrator-aligned engine) -------------
$_pe     = peAggregateScores($pdo, $uid, $rank);
$_auto   = $_pe['auto_subrank'];
$_base   = $_auto['updated_base_rank'] ?? $rank;
$_ws1    = (float)($_auto['pass1_weighted'] ?? $_pe['weighted_score']);
$_inc1   = getSubRankIncrement($_ws1);
$_allr   = facultyRanks();
$_bidx   = array_search($_base, $_allr);
$_irr    = ($_bidx !== false && $_inc1 > 0)
    ? $_allr[min($_bidx + $_inc1, count($_allr) - 1)]
    : $_base;
$_trigger = strtolower($_auto['trigger'] ?? 'none');

$iss = [
    'kra_raw_points' => [
        'kra1' => $_pe['kra1'],
        'kra2' => $_pe['kra2'],
        'kra3' => $_pe['kra3'],
        'kra4' => $_pe['kra4'],
    ],
    'base_rank'                    => $rank,
    'sub_rank_increment_pass1'     => $_inc1,
    'initial_reclassified_rank'    => $_irr,
    'sub_rank_increment_pass2'     => max(0, $_pe['sub_rank_increment'] - (int)($_auto['bonus_increment'] ?? 0)),
    'reclassified_rank'            => (function() use ($_base, $_allr, $_bidx, $_pe, $_auto): string {
        // Score-only rank (before award bump) — for ISS row 7 "Recommended Rank"
        $score_inc = max(0, $_pe['sub_rank_increment'] - (int)($_auto['bonus_increment'] ?? 0));
        $award_bump_only = str_contains(strtolower($_auto['trigger'] ?? ''), 'award') ? 1 : 0;
        $score_only_inc  = $score_inc;
        if ($_bidx !== false && $score_only_inc > 0) {
            return $_allr[min($_bidx + $score_only_inc, count($_allr) - 1)];
        }
        return $_base;
    })(),
    'qualified_auto_subrank_phd'   => str_contains($_trigger, 'doctorate'),
    'qualified_auto_subrank_award' => str_contains($_trigger, 'award'),
    'final_rank'                   => $_pe['pot_rank'],  // includes award bump (now correct)
];

// -- PDF class ------------------------------------------------
class IssOnlyPDF extends FPDF
{
    public string $fac_name   = '';
    public string $fac_rank   = '';
    public string $fac_campus = '';
    public string $generated  = '';

    const GRAY     = [220, 220, 220];
    const GRAY_LBL = [235, 235, 235];
    const BAND     = [200, 200, 200];
    const WHITE    = [255, 255, 255];

    function Header(): void
    {
        $this->SetFont('Times', 'B', 12);
        $this->SetTextColor(0, 0, 0);
        $this->SetY(10);
        $this->Cell(0, 6, 'INDIVIDUAL SUMMARY SHEET', 0, 1, 'C');
        $this->SetFont('Times', 'B', 11);
        $this->Cell(0, 6, 'RANK-WEIGHTED SCORING & RECLASSIFICATION', 0, 1, 'C');
        $this->Ln(2);

        // Faculty info table
        $lw = 55; $vw = 135;
        $rows = [
            ['Name of Faculty:',    $this->fac_name],
            ['Current Faculty Rank:',$this->fac_rank],
            ['College/Department:', ''],
            ['Name of SUC:',        'Carlos Hilado Memorial State University'],
            ['Campus:',             $this->fac_campus],
        ];
        $this->SetDrawColor(0, 0, 0);
        $this->SetLineWidth(0.2);
        foreach ($rows as [$lbl, $val]) {
            $this->SetFont('Times', 'B', 9.5);
            $this->SetFillColor(...self::GRAY_LBL);
            $this->Cell($lw, 6, $lbl, 1, 0, 'L', true);
            $this->SetFont('Times', '', 9.5);
            $this->SetFillColor(...self::WHITE);
            $this->Cell($vw, 6, $val, 1, 1, 'L');
        }
        $this->Ln(4);
    }

    function Footer(): void
    {
        $this->SetY(-11);
        $this->SetFont('Times', 'I', 7);
        $this->SetTextColor(100, 100, 100);
        $this->Cell(0, 5,
            'SUCFRMS Self-Assessment ISS  |  For reference only. Final scores subject to official review.  |  Page ' . $this->PageNo() . '/{nb}',
            0, 0, 'C');
        $this->SetTextColor(0, 0, 0);
    }

    function renderISS(array $iss): void
    {
        $raw = $iss['kra_raw_points'];

        // -- Active tier detection --------------------------------
        $tiers = [
            'Instructor'              => 'Instructor I',
            'Asst. Professor'         => 'Assistant Professor I',
            'Assoc. Professor'        => 'Associate Professor I',
            'Professor'               => 'Professor I',
            'College/Univ. Professor' => 'University Professor',
        ];
        $active_tier = null;
        $w_base = getKraWeights($iss['base_rank']);
        foreach ($tiers as $label => $sample) {
            if (getKraWeights($sample) === $w_base) { $active_tier = $label; break; }
        }

        // -- Table 1: Rank-weighted scoring -----------------------
        $widths  = [40, 15, 13, 15, 13, 15, 13, 15, 13, 24];
        $headers = ['Faculty Rank','KRA 1 Pts','Weight','KRA 2 Pts','Weight',
                    'KRA 3 Pts','Weight','KRA 4 Pts','Weight','Total Points'];

        $this->SetFont('Times', 'B', 8);
        $this->SetFillColor(...self::GRAY);
        $this->SetDrawColor(0, 0, 0);
        $this->SetLineWidth(0.2);
        foreach ($headers as $i => $h) {
            $this->Cell($widths[$i], 7, $h, 1, 0, 'C', true);
        }
        $this->Ln();

        foreach ($tiers as $label => $sample) {
            $w = getKraWeights($sample);
            $total = round(
                $raw['kra1'] * $w['Instruction'] +
                $raw['kra2'] * $w['Research'] +
                $raw['kra3'] * $w['Extension'] +
                $raw['kra4'] * $w['Professional Development'],
                2
            );
            $isActive = ($label === $active_tier);
            $this->SetFillColor(...($isActive ? self::BAND : self::WHITE));
            $this->SetFont('Times', $isActive ? 'B' : '', 8);

            $this->Cell($widths[0], 6, $label,                              1, 0, 'L', $isActive);
            $this->Cell($widths[1], 6, number_format($raw['kra1'], 2),      1, 0, 'C', $isActive);
            $this->Cell($widths[2], 6, round($w['Instruction'] * 100).'%',  1, 0, 'C', $isActive);
            $this->Cell($widths[3], 6, number_format($raw['kra2'], 2),      1, 0, 'C', $isActive);
            $this->Cell($widths[4], 6, round($w['Research'] * 100).'%',     1, 0, 'C', $isActive);
            $this->Cell($widths[5], 6, number_format($raw['kra3'], 2),      1, 0, 'C', $isActive);
            $this->Cell($widths[6], 6, round($w['Extension'] * 100).'%',    1, 0, 'C', $isActive);
            $this->Cell($widths[7], 6, number_format($raw['kra4'], 2),      1, 0, 'C', $isActive);
            $this->Cell($widths[8], 6, round($w['Professional Development'] * 100).'%', 1, 0, 'C', $isActive);
            $this->Cell($widths[9], 6, number_format($total, 2),            1, 1, 'C', $isActive);
        }
        $this->Ln(5);

        // -- Table 2: Sub-rank bracket reference ------------------
        $this->SetFont('Times', 'B', 8);
        $this->SetFillColor(...self::GRAY);
        $this->Cell(60, 7, 'Score Bracket',             1, 0, 'C', true);
        $this->Cell(60, 7, 'No. of Sub-rank Increment', 1, 1, 'C', true);
        $this->SetFont('Times', '', 8);
        $brackets = [
            '41-50' => '1 sub-rank',  '51-60' => '2 sub-ranks',
            '61-70' => '3 sub-ranks', '71-80' => '4 sub-ranks',
            '81-90' => '5 sub-ranks', '91-100'=> '6 sub-ranks',
        ];
        foreach ($brackets as $range => $lbl) {
            $this->Cell(60, 6, $range, 1, 0, 'C');
            $this->Cell(60, 6, $lbl,   1, 1, 'C');
        }
        $this->Ln(5);

        // -- Breakdown block (9 rows) -----------------------------
        $rows = [
            ['Current Faculty Rank',
             $iss['base_rank']],
            ['Qualified for Auto. 1-Sub Rank (for PhD)?',
             $iss['qualified_auto_subrank_phd']   ? 'YES' : 'NO'],
            ['Base Rank',
             $iss['base_rank']],
            ['No. of Sub-Rank Increment based on Score',
             (string)$iss['sub_rank_increment_pass1']],
            ['Initial Recommended Rank',
             $iss['initial_reclassified_rank']],
            ['No. of Sub-Rank Increment based on Recomputed Score',
             (string)$iss['sub_rank_increment_pass2']],
            ['Recommended Rank',
             $iss['reclassified_rank']],
            ['Qualified for Auto. 1-Sub Rank (for Awards)?',
             $iss['qualified_auto_subrank_award'] ? 'YES' : 'NO'],
            ['Final Rank (Score-Based Increment + Auto Sub Rank combined)',
             $iss['final_rank']],
        ];
        $this->SetFillColor(...self::GRAY_LBL);
        foreach ($rows as [$label, $value]) {
            $this->SetFont('Times', '', 8);
            $this->Cell(120, 6, $label, 1, 0, 'R', true);
            $this->SetFont('Times', 'B', 8);
            $this->Cell(64,  6, $value, 1, 1, 'C');
        }

        // -- Disclaimer -------------------------------------------
        $this->Ln(5);
        $this->SetLineWidth(0.3);
        $this->Line(10, $this->GetY(), 200, $this->GetY());
        $this->Ln(2);
        $this->SetFont('Times', 'I', 7);
        $this->SetTextColor(90, 90, 90);
        $this->MultiCell(0, 4,
            'DISCLAIMER: This Individual Summary Sheet is generated from self-assessment data and is for ' .
            'reference purposes only. Auto. 1-Sub Rank for Awards requires official evaluator confirmation. ' .
            'The first-doctorate historical check is indicative only (no verification against prior approved ' .
            'applications is performed at this stage). Final rank determinations are subject to official ' .
            'review by authorised evaluators and the reclassification committee.',
            0, 'L');
        $this->SetTextColor(0, 0, 0);
    }
}

// -- Build & Output -------------------------------------------
$pdf = new IssOnlyPDF('P', 'mm', 'A4');
$pdf->AliasNbPages();
$pdf->fac_name   = iss_u($full_name);
$pdf->fac_rank   = iss_u($rank ?: '-');
$pdf->fac_campus = iss_u($campus);
$pdf->generated  = iss_u($generated);
$pdf->SetMargins(10, 70, 10);
$pdf->SetAutoPageBreak(true, 14);
$pdf->AddPage();
$pdf->renderISS($iss);

ob_end_clean();
$fname = 'ISS_' . preg_replace('/[^A-Za-z0-9_]/', '_', $full_name) . '_' . date('Ymd') . '.pdf';
$pdf->Output('I', $fname);
