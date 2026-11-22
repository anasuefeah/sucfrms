<?php
/**
 * Self-Assessment Summary PDF (FPDF)  -  monochrome professional style
 */
ob_start();
if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../fpdf/fpdf.php';
require_once __DIR__ . '/../includes/criteria_pdf_rows.php';

$_sd = __DIR__ . '/../includes/';
require_once $_sd . 'self_assessment_scoring.php';
if (!isLoggedIn() || !isFaculty()) { ob_end_clean(); http_response_code(403); die('Access denied.'); }

$uid = (int)$_SESSION['user_id'];

// UTF-8 -> windows-1252 for FPDF
function u(string $s): string {
    return iconv('UTF-8','windows-1252//TRANSLIT//IGNORE', $s);
}
// Strip pipe-delimited remarks into a clean readable string
function parseRemarks(string $remarks): string {
    $p = array_map('trim', explode('|||', $remarks));
    if (($p[0] ?? '') === 'CUSTOM') return $p[5] ?? '';
    $desc = $p[1] ?? '';
    $sub  = $p[2] ?? '';
    if ($desc === '') return $p[0] ?? $remarks;
    if ($sub !== '' && $sub !== '0' && $sub !== '100') return $desc . ' (' . $sub . ')';
    return $desc;
}
// Extract just the type/criterion label from remarks
function parseType(string $remarks): string {
    $p = array_map('trim', explode('|||', $remarks));
    if (($p[0] ?? '') === 'CUSTOM') return $p[3] ?? '';
    return $p[0] ?? '';
}
// Extract sub-value (score, count, etc.)
function parseSub(string $remarks): string {
    $p = array_map('trim', explode('|||', $remarks));
    if (($p[0] ?? '') === 'CUSTOM') return '';
    $sub = $p[2] ?? '';
    return ($sub !== '' && $sub !== '0') ? $sub : '';
}

// Faculty
$fac = $pdo->prepare("SELECT u.*, c.campus_name FROM users u LEFT JOIN campuses c ON u.campus_id=c.campus_id WHERE u.user_id=?");
$fac->execute([$uid]);
$fac = $fac->fetch();
if (!$fac) { ob_end_clean(); die('Faculty not found.'); }

$rank       = $fac['rank'] ?? '';
$full_name  = formatDisplayName($fac);
$campus     = $fac['campus_name'] ?? 'N/A';
$generated  = date('F d, Y  h:i A');

// Entries (for per-row display in the PDF table)
$eq = $pdo->prepare("SELECT pe.entry_id, pe.kra_category, pe.remarks, pe.computed_points, pe.notes, pe.created_at FROM pre_eval_entries pe WHERE pe.user_id=? ORDER BY pe.kra_category, pe.created_at ASC");
$eq->execute([$uid]);
$by_cat = [];
foreach ($eq->fetchAll() as $e) $by_cat[$e['kra_category']][] = $e;
$criteriaByCategory = [];
foreach (CriteriaSnapshots::rows($pdo, (int)(getActiveCycle($pdo)['cycle_id'] ?? 0), $rank ?: null) as $criterion) {
    $criteriaByCategory[$criterion['kra_category']][] = $criterion;
}
foreach ($by_cat as $category => $entries) {
    $by_cat[$category] = kraCurrentCriterionEntries($entries, $criteriaByCategory[$category] ?? []);
}

// Scores — two-pass Orchestrator-aligned engine (same as AJAX preview and sidebar)
$_pe = peAggregateScores($pdo, $uid, $rank);
$ws       = $_pe['weighted_score'];
$sr       = $_pe['sub_rank_increment'];
$pr       = $_pe['pot_rank'];
$pending  = $_pe['pending_documentation'];
$config_i = $_pe['config_incomplete'];

$kras = [
    'Instruction' => ['I', 'kra1'],
    'Research' => ['II', 'kra2'],
    'Extension' => ['III', 'kra3'],
    'Professional Development' => ['IV', 'kra4'],
];

// -- PDF class -------------------------------------------------
class SelfAssessmentPDF extends FPDF
{
    public string $fac_name  = '';
    public string $fac_rank  = '';
    public string $fac_campus= '';
    public string $generated = '';

    function Header(): void
    {
        // Reset draw color so no border box appears around the logo
        $this->SetDrawColor(180,180,180);
        $this->SetLineWidth(0.2);

        // Logo  -  positioned neatly top-left
        // Institution name block (right of logo)
        // Horizontal rule  -  full width, medium weight
        // Document title  -  centred, prominent
        $this->SetFont('Times', 'B', 14);
        $this->SetTextColor(0, 0, 0);
        $this->SetXY(10, 10);
        $this->Cell(0, 8, 'SELF-ASSESSMENT SUMMARY', 0, 1, 'C');

        // Thin rule under title
        $this->SetLineWidth(0.3);
        $this->Line(10, $this->GetY(), 200, $this->GetY());
        $this->Ln(1);

        // Meta info line
        $this->SetFont('Times', '', 7.5);
        $this->SetTextColor(60, 60, 60);
        $this->Cell(0, 5,
            'Faculty: ' . $this->fac_name .
            '   |   Rank: ' . $this->fac_rank .
            '   |   Campus: ' . $this->fac_campus .
            '   |   Generated: ' . $this->generated,
            0, 1, 'L');

        // Final rule below meta
        $this->SetLineWidth(0.3);
        $this->Line(10, $this->GetY(), 200, $this->GetY());
        $this->Ln(3);
        $this->SetTextColor(0, 0, 0);
        $this->SetDrawColor(0, 0, 0);
        $this->SetLineWidth(0.3);
    }

    function Footer(): void
    {
        $this->SetY(-11);
        $this->SetLineWidth(0.3);
        $this->Line(10,$this->GetY(),200,$this->GetY());
        $this->SetFont('Times','I',7);
        $this->SetTextColor(100,100,100);
        $this->Cell(0,5,
            'SUCFRMS Self-Assessment  |  For reference only. Final scores subject to official review.  |  Page '.$this->PageNo().'/{nb}',
            0,0,'C');
    }

    // Bold underlined section heading  -  underline only
    function SectionHead(string $txt): void
    {
        $this->SetFont('Times','B',9);
        $this->SetTextColor(0,0,0);
        $this->Cell(0,6,$txt,'B',1,'L');
        $this->Ln(1);
    }

    // Table header  -  light grey fill, black text
    function THead(array $cols): void
    {
        $this->SetFillColor(220,220,220);
        $this->SetTextColor(0,0,0);
        $this->SetFont('Times','B',7.5);
        foreach ($cols as [$cw,$ct,$ca]) $this->Cell($cw,5.5,$ct,1,0,$ca,true);
        $this->Ln();
        $this->SetFillColor(255,255,255);
    }

    // Plain table row  -  alternating very light grey
    function TRow(array $cols, bool $shade=false): void
    {
        $this->SetFont('Times','',7.5);
        $this->SetFillColor($shade?247:255,$shade?247:255,$shade?247:255);
        foreach ($cols as [$cw,$ct,$ca]) $this->Cell($cw,5,$ct,1,0,$ca,$shade);
        $this->Ln();
    }
}

// -- Build PDF -------------------------------------------------
$pdf = new SelfAssessmentPDF('P','mm','A4');
$pdf->AliasNbPages();
$pdf->fac_name   = u($full_name);
$pdf->fac_rank   = u($rank?:'-');
$pdf->fac_campus = u($campus);
$pdf->generated  = u($generated);
$pdf->SetMargins(10, 55, 10);
$pdf->SetAutoPageBreak(true,14);
$pdf->SetDrawColor(0,0,0);
$pdf->AddPage();

// -- SCORE SUMMARY --------------------------------------------
$pdf->SectionHead('SCORE SUMMARY');

// Score + threshold side by side
$pdf->SetFont('Times','B',26);
$pdf->SetTextColor(0,0,0);
$pdf->Cell(38,14,number_format($ws,2),'1',0,'C');
$pdf->SetFont('Times','',8.5);
$gy = $pdf->GetY();
$pdf->SetXY(52,$gy+1);
$pdf->Cell(0,5,'Weighted Score / 100',0,1,'L');
$pdf->SetXY(52,$pdf->GetY());
$pdf->SetFont('Times','B',8.5);
$pdf->SetTextColor(0,0,0);
$badge = $ws>=41 ? 'MEETS minimum threshold (41)' : 'BELOW minimum threshold (41)';
$pdf->Cell(70,5.5,u($badge),'1',1,'C');
$pdf->Ln(5);

// KRA table
$pdf->THead([[62,'KRA','L'],[24,'Raw (/100)','C'],[20,'Weight','C'],[26,'Weighted Pts','C'],[30,'Sub-rank','C'],[28,'Recommended Rank','C']]);
$shade=false;
foreach ($kras as $category => [$number, $scoreKey]) {
    $k = ['num'=>$number, 'cat'=>$category, 'label'=>$category, 'raw'=>$_pe[$scoreKey]];
    $pdf->Ln(1);

    $pdf->SetFillColor(220,220,220);
    $pdf->SetFont('Times','B',8.5);
    $pdf->SetTextColor(0,0,0);
    $maximum = $_pe[$scoreKey . '_detail']['cap'];
    $pdf->Cell(0,5.5,u('  KRA '.$number.' - '.strtoupper($category).'  ('.number_format($k['raw'],2).' / '.$maximum.' pts)'),1,1,'L',true);
    $pdf->SetFillColor(255,255,255);

    $criteria = $criteriaByCategory[$category] ?? [];
    $buckets = criteriaEntryBuckets($category, $criteria, $by_cat[$category] ?? []);
    foreach (criteriaGroups($criteria) as $code => $parent) {
        $pdf->SetFont('Times','B',7.5);
        $points = $_pe['parent_scores'][$category][$code] ?? 0;
        $label = ($code === '' ? '' : $code . ' - ') . $parent['name'];
        $total = number_format($points, 2) . ($parent['max'] === null ? '' : ' / ' . $parent['max']) . ' pts';
        criteriaPdfRow($pdf, '    ' . $label, 4.5, 14, 0, $total);
        $pdf->SetFont('Times','',7.5);
        foreach ($parent['children'] as $child) {
            $assessed = $buckets[$child['criterion_key']] ?? [];
            $score = $assessed ? number_format(array_sum(array_column($assessed, 'computed_points')), 2) : 'Not assessed';
            $value = $score . ' / ' . number_format((float)$child['max_points'], 2) . ' pts';
            criteriaPdfRow($pdf, '       ' . $child['criterion_label'], 4.5, 14, 0, $value);
        }
    }

    // Thin rule before entries
    $pdf->SetLineWidth(0.2);
    $pdf->Line(10,$pdf->GetY(),200,$pdf->GetY());
    $pdf->SetLineWidth(0.3);

    $entries = $by_cat[$k['cat']] ?? [];
    if (empty($entries)) {
        $pdf->SetFont('Times','I',7);
        $pdf->SetTextColor(120,120,120);
        $pdf->Cell(0,4.5,'    No entries.',0,1,'L');
        $pdf->SetTextColor(0,0,0);
        $pdf->Ln(2);
        continue;
    }

    // Entry table header — no Evidence, more detail columns
    $pdf->THead([[8,'#','C'],[68,'Description','L'],[50,'Type / Criterion','L'],[30,'Notes','L'],[24,'Date','C'],[10,'Pts','C']]);

    $shade=false;
    foreach ($entries as $idx=>$e) {
        $desc   = u(parseRemarks($e['remarks']??''));
        $type   = u(parseType($e['remarks']??''));
        $sub    = u(parseSub($e['remarks']??''));
        $notes  = u(trim($e['notes']??''));
        $date   = !empty($e['created_at']) ? date('M d, Y', strtotime($e['created_at'])) : '';
        $pts    = number_format($e['computed_points'],2);

        // Build type+sub string
        $typeStr = $sub !== '' ? $type.' ('.$sub.')' : $type;

        $pdf->SetFont('Times','',7);
        $descLines  = criteriaPdfLineCount($pdf, 68, $desc);
        $typeLines  = criteriaPdfLineCount($pdf, 50, $typeStr);
        $notesLines = criteriaPdfLineCount($pdf, 30, $notes);
        $lines = max($descLines, $typeLines, $notesLines);
        $rh    = max(5, $lines*4);
        $lh    = max(4, $rh/$lines);
        if ($pdf->GetY() + $lines * $lh > $pdf->GetPageHeight() - 14) $pdf->AddPage();
        $x=$pdf->GetX(); $y=$pdf->GetY();

        $pdf->SetFillColor($shade?247:255,$shade?247:255,$shade?247:255);
        $pdf->SetFont('Times','',7);

        $pdf->SetXY($x,$y);
        $pdf->Cell(8,$rh,(string)($idx+1),1,0,'C',$shade);

        $pdf->SetXY($x+8,$y);
        $pdf->MultiCell(68,$lh,$desc,1,'L',$shade);

        $pdf->SetXY($x+76,$y);
        $pdf->MultiCell(50,$lh,$typeStr,1,'L',$shade);

        $pdf->SetXY($x+126,$y);
        $pdf->MultiCell(30,$lh,$notes ?: '--',1,'L',$shade);

        $pdf->SetXY($x+156,$y);
        $pdf->Cell(24,$rh,u($date),1,0,'C',$shade);

        $pdf->SetXY($x+180,$y);
        $pdf->Cell(10,$rh,$pts,1,0,'C',$shade);

        $pdf->SetXY($x,$y+$rh);
        $shade=!$shade;
    }
    $pdf->Ln(3);
}

// -- SCORING FLAGS ---------------------------------------------
if (!empty($config_i)) {
    $pdf->Ln(2);
    $pdf->SectionHead('SCORING FLAGS ('.count($config_i).')');
    $pdf->SetFont('Times','',7.5);
    foreach ($config_i as $ci) {
        $pdf->SetTextColor(0,0,0);
        $pdf->MultiCell(0,4.5,u('  * '.$ci),0,'L');
    }
    $pdf->Ln(2);
}

// -- DISCLAIMER -----------------------------------------------
$pdf->Ln(2);
$pdf->SetLineWidth(0.3);
$pdf->Line(10,$pdf->GetY(),200,$pdf->GetY());
$pdf->Ln(2);
$pdf->SetFont('Times','I',7);
$pdf->SetTextColor(80,80,80);
$pdf->MultiCell(0,4,
    u('DISCLAIMER: This document is a self-assessment estimate generated by SUCFRMS for reference purposes only. '.
      'Final reclassification scores and rank determinations are subject to official review by '.
      'authorised evaluators and administrators. Evidence authenticity has not been reviewed at this stage.'),
    0,'L');

// -- Output ---------------------------------------------------
ob_end_clean();
$fname = 'SelfAssessment_'.preg_replace('/[^A-Za-z0-9_]/','_',$full_name).'_'.date('Ymd').'.pdf';
$pdf->Output('I',$fname);
