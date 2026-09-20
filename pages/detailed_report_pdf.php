<?php
/**
 * Detailed Report PDF &mdash; Per-faculty KRA breakdown for all faculty in a cycle
 * Plain, professional formatting (no color fills, no colored text)
 */
ob_start();
if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__ . '/../includes/functions.php';
requireLogin();
if (!isAdmin() && !isChecker()) { http_response_code(403); die('Access denied.'); }
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../fpdf/fpdf.php';

$cycle_id  = intval($_GET['cycle_id']  ?? 0);
$campus_id = intval($_GET['campus_id'] ?? 0);
$status    = trim($_GET['status']      ?? '');

// â”€â”€ Load cycles / campuses â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
$all_cycles = $pdo->query("SELECT cycle_id, cycle_name, status FROM cycles ORDER BY created_at DESC")->fetchAll();
if (!$cycle_id) {
    foreach ($all_cycles as $cy) {
        if ($cy['status'] === 'open') { $cycle_id = $cy['cycle_id']; break; }
    }
}
$selected_cycle = null;
foreach ($all_cycles as $cy) {
    if ($cy['cycle_id'] === $cycle_id) { $selected_cycle = $cy; break; }
}

// â”€â”€ Load applications â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
$where  = "WHERE u.role = 'faculty' AND a.cycle_id=?";
$params = [$cycle_id];
if ($campus_id) { $where .= ' AND u.campus_id=?'; $params[] = $campus_id; }
$allowed = ['submitted','under_review','approved','rejected','admin_rejected','reclassified'];
if ($status && in_array($status, $allowed)) { $where .= ' AND a.status=?'; $params[] = $status; }

$rows = $pdo->prepare("
    SELECT a.*, u.full_name, u.employee_id, u.rank, u.email,
           COALESCE(camp.campus_name,'N/A') AS campus_name,
           chk.checker_label AS checker_name, chk.user_id AS checker_uid
    FROM applications a
    JOIN users u ON a.user_id = u.user_id
    LEFT JOIN campuses camp ON u.campus_id = camp.campus_id
    LEFT JOIN users chk ON a.checker_id = chk.user_id
    $where
    ORDER BY camp.campus_name, u.full_name
");
$rows->execute($params);
$applications = $rows->fetchAll();

if (empty($applications)) {
    // Return a minimal PDF with a message
    $pdf2 = new FPDF('P','mm','A4');
    $pdf2->AddPage();
    $pdf2->SetFont('Times','I',11);
    $pdf2->Cell(0,10,'No applications found for the selected filters.',0,1,'C');
    ob_end_clean();
    $pdf2->Output('I','Detailed_Report_'.date('Ymd').'.pdf');
    exit;
}

// â”€â”€ Load all KRA submissions in one query â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
$app_ids      = array_column($applications, 'application_id');
$placeholders = implode(',', array_fill(0, count($app_ids), '?'));
$ks = $pdo->prepare("SELECT * FROM kra_submissions WHERE application_id IN ($placeholders) ORDER BY application_id, kra_category, submitted_at ASC");
$ks->execute($app_ids);
$all_subs = $ks->fetchAll();

$subs_by_app = [];
foreach ($all_subs as $s) $subs_by_app[$s['application_id']][$s['kra_category']][] = $s;

$kra_info = [
    'Instruction'              => ['num'=>'I',   'max'=>100, 'label'=>'Teaching Effectiveness'],
    'Research'                 => ['num'=>'II',  'max'=>100, 'label'=>'Research, Innovation & Creative Work'],
    'Extension'                => ['num'=>'III', 'max'=>100, 'label'=>'Extension Services'],
    'Professional Development' => ['num'=>'IV',  'max'=>100, 'label'=>'Professional Development'],
];

$printed_at = date('M d, Y h:i A');
$admin_name = $_SESSION['full_name'] ?? 'Administrator';

// â”€â”€ Helper: strip HTML for PDF â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
function pdfClean2(string $html): string {
    $t = html_entity_decode(strip_tags($html), ENT_QUOTES, 'UTF-8');
    return preg_replace('/\s+/', ' ', trim($t));
}

// â”€â”€ FPDF class â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
class DetailedPDF extends FPDF {
    public $cyc_name   = '';
    public $printed_at = '';
    public $admin_name = '';
    public $fac_name   = '';

    function Header() {
        $this->SetFont('Times','B',12);
        $this->SetTextColor(0,0,0);
        $this->SetXY(12, 6);
        $this->Cell(0, 6, 'State Universities and Colleges', 0, 1, 'C');

        $this->SetFont('Times','',8.5);
        $this->SetTextColor(60,60,60);
        $this->SetX(12);
        $this->Cell(0, 4.5, 'SUC Faculty Reclassification Management System', 0, 1, 'C');

        $this->SetDrawColor(0,0,0);
        $this->SetLineWidth(0.4);
        $this->Line(12, $this->GetY(), 198, $this->GetY());
        $this->Ln(1.5);

        $this->SetFont('Times','B',11);
        $this->SetTextColor(0,0,0);
        $this->SetX(12);
        $this->Cell(0, 6, 'DETAILED FACULTY RECLASSIFICATION REPORT', 0, 1, 'C');

        $this->SetFont('Times','I',7.5);
        $this->SetTextColor(60,60,60);
        $this->SetX(12);
        $this->Cell(0, 4, 'DBM-CHED Joint Circular No. 3, s. 2022 & JC No. 1, s. 2023', 0, 1, 'C');

        $this->SetFont('Times','',7.5);
        $this->SetX(12);
        $this->Cell(0, 4, 'Cycle: '.$this->cyc_name.'   |   Printed: '.$this->printed_at.'   |   By: '.$this->admin_name, 0, 1, 'C');

        $this->SetLineWidth(0.4);
        $this->Line(12, $this->GetY(), 198, $this->GetY());
        $this->SetTextColor(0,0,0);
        $this->Ln(2);
    }

    function Footer() {
        $this->SetY(-10);
        $this->SetFont('Times','I',7);
        $this->SetTextColor(100,100,100);
        $this->Cell(0, 4, 'SUCFRMS - Detailed Report   |   '.$this->fac_name.'   |   Page '.$this->PageNo().'/{nb}', 0, 0, 'C');
    }

    // Plain bold section label with underline rule
    function SectionLabel(string $txt): void {
        $this->SetFont('Times','B',8.5);
        $this->SetTextColor(0,0,0);
        $this->Cell(0, 5, strtoupper($txt), 0, 1, 'L');
        $this->SetLineWidth(0.3);
        $this->Line(12, $this->GetY(), 198, $this->GetY());
        $this->Ln(1.5);
    }

    // KRA category header &mdash; light grey fill
    function KraHeader(string $num, string $label, string $cap): void {
        $this->SetFillColor(220,220,220);
        $this->SetTextColor(0,0,0);
        $this->SetFont('Times','B',9);
        $this->Cell(130, 6, 'KRA '.$num.' - '.$label, 1, 0, 'L', true);
        $this->SetFont('Times','',8);
        $this->Cell(0, 6, $cap, 1, 1, 'R', true);
    }

    // Table column header &mdash; light grey
    function ColHead(array $cols): void {
        $this->SetFillColor(210,210,210);
        $this->SetTextColor(0,0,0);
        $this->SetFont('Times','B',7.5);
        foreach ($cols as [$w,$t,$a]) $this->Cell($w, 5.5, $t, 1, 0, $a, true);
        $this->Ln();
    }

    // Info row pair
    function InfoRow(string $l1, string $v1, string $l2 = '', string $v2 = ''): void {
        $lw = 28; $vw = 66;
        $this->SetFillColor(235,235,235);
        $this->SetFont('Times','B',8);
        $this->Cell($lw, 5.5, $l1, 1, 0, 'L', true);
        $this->SetFont('Times','',8);
        if ($l2 !== '') {
            $this->Cell($vw, 5.5, $v1, 1, 0, 'L');
            $this->SetFont('Times','B',8);
            $this->Cell($lw, 5.5, $l2, 1, 0, 'L', true);
            $this->SetFont('Times','',8);
            $this->Cell(0, 5.5, $v2, 1, 1, 'L');
        } else {
            $this->Cell(0, 5.5, $v1, 1, 1, 'L');
        }
    }
}

// â”€â”€ Build PDF â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
$pdf = new DetailedPDF('P','mm','A4');
$pdf->AliasNbPages();
$pdf->cyc_name   = $selected_cycle['cycle_name'] ?? 'N/A';
$pdf->printed_at = $printed_at;
$pdf->admin_name = $admin_name;
$pdf->SetMargins(12, 46, 12);
$pdf->SetAutoPageBreak(true, 14);
$pdf->SetDrawColor(0,0,0);
$pdf->SetLineWidth(0.2);

// Page width = 210 - 12 - 12 = 186mm
$pw = 186;

// â”€â”€ One section per faculty â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
foreach ($applications as $idx => $a) {
    $pdf->fac_name = $a['full_name'];
    $pdf->AddPage();

    $subs_by_cat = $subs_by_app[$a['application_id']] ?? [];
    $rank        = $a['rank'] ?? '';

    // Compute KRA totals
    $kra_totals = [];
    foreach ($kra_info as $cat => $info) {
        $entries = $subs_by_cat[$cat] ?? [];
        $raw     = array_sum(array_column($entries, 'computed_points'));
        $cap     = $info['max']; // JC01 s.2026: all KRAs capped at 100
        $kra_totals[$cat] = min($cap, $raw);
    }
    $grand_total = array_sum($kra_totals);
    $result      = computeWeightedScore($kra_totals, $rank);
    $weights     = $result['weights'];

    // â”€â”€ Faculty info â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
    $pdf->SectionLabel('Faculty Information');
    $pdf->InfoRow('Full Name',    $a['full_name'],
                  'Employee ID',  $a['employee_id'] ?? 'N/A');
    $pdf->InfoRow('Campus',       $a['campus_name'],
                  'Current Rank', $rank ?: 'N/A');
    $pdf->InfoRow('Cycle',        $selected_cycle['cycle_name'] ?? 'N/A',
                  'Status',       ucwords(str_replace('_',' ',$a['status'])));
    $pdf->InfoRow('Reviewed By',  !empty($a['checker_uid']) ? checkerDisplayLabel(['checker_label'=>$a['checker_name'],'user_id'=>$a['checker_uid']]) : 'N/A', '', '');
    if (!empty($a['checker_remarks'])) {
        $pdf->InfoRow('Remarks', pdfClean2($a['checker_remarks']));
    }
    $pdf->Ln(3);

    // â”€â”€ KRA sections â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
    foreach ($kra_info as $cat => $info) {
        $entries   = $subs_by_cat[$cat] ?? [];
        $cat_total = $kra_totals[$cat];
        $cap_label = 'Max ' . $info['max'] . ' pts';

        $pdf->KraHeader($info['num'], $info['label'], $cap_label);

        if ($entries) {
            $pdf->ColHead([
                [7,   '#',           'C'],
                [117, 'Description', 'L'],
                [22,  'Points',      'C'],
                [40,  'Verified',    'C'],
            ]);

            foreach ($entries as $i => $s) {
                $desc = pdfClean2(formatKraRemarks($cat, $s['remarks'] ?? ''));
                if ($desc === '' || $desc === '--') $desc = '(no description)';

                // Measure height needed for MultiCell
                $pdf->SetFont('Times','',7.5);
                $line_h  = 4.5;
                $str_w   = $pdf->GetStringWidth($desc);
                $lines   = max(1, ceil($str_w / 115));
                $cell_h  = max(5.5, $lines * $line_h);

                $x = $pdf->GetX();
                $y = $pdf->GetY();

                // # cell
                $pdf->SetFont('Times','',7.5);
                $pdf->SetTextColor(0,0,0);
                $pdf->Cell(7, $cell_h, $i + 1, 1, 0, 'C');

                // Description &mdash; MultiCell, then reposition
                $pdf->SetFont('Times','',7.5);
                $pdf->MultiCell(117, $line_h, $desc, 1, 'L');

                // Points
                $pdf->SetXY($x + 124, $y);
                $pdf->SetFont('Times','B',8.5);
                $pdf->Cell(22, $cell_h, number_format((float)$s['computed_points'], 2), 1, 0, 'C');

                // Verified
                $verified_label = $s['verified'] ? 'Verified' : 'Pending';
                $pdf->SetFont('Times','', 7.5);
                $pdf->SetTextColor(0,0,0);
                $pdf->Cell(40, $cell_h, $verified_label, 1, 1, 'C');
            }

            // Subtotal
            $pdf->SetFillColor(230,230,230);
            $pdf->SetFont('Times','B',8.5);
            $pdf->SetTextColor(0,0,0);
            $pdf->Cell(146, 6, 'Sub-total ('.$cap_label.')', 1, 0, 'R', true);
            $pdf->Cell(40,  6, number_format($cat_total, 2), 1, 1, 'C', true);
        } else {
            $pdf->SetFont('Times','I',8.5);
            $pdf->SetTextColor(80,80,80);
            $pdf->Cell(0, 6, 'No submission for this KRA.', 1, 1, 'C');
            $pdf->SetTextColor(0,0,0);
        }
        $pdf->Ln(2);
    }

    // â”€â”€ Grand total â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
    $pdf->SetFillColor(220,220,220);
    $pdf->SetFont('Times','B',10);
    $pdf->SetTextColor(0,0,0);
    $pdf->Cell(146, 7, 'GRAND TOTAL SCORE (out of 360 pts)', 1, 0, 'R', true);
    $pdf->Cell(40,  7, number_format($grand_total, 2),        1, 1, 'C', true);
    $pdf->Ln(3);

    // â”€â”€ Weighted score computation â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
    $pdf->SectionLabel('Weighted Score Computation');
    $pdf->ColHead([
        [70, 'KRA',        'L'],
        [24, 'Raw Score',  'C'],
        [24, 'Capped',     'C'],
        [24, 'Weight',     'C'],
        [44, 'Weighted',   'C'],
    ]);

    $kra_labels = [
        'Instruction'              => 'KRA I - Instruction',
        'Research'                 => 'KRA II - Research',
        'Extension'                => 'KRA III - Extension',
        'Professional Development' => 'KRA IV - Prof. Development',
    ];
    $raw_scores = [
        'Instruction'              => $result['kra1'],
        'Research'                 => $result['kra2'],
        'Extension'                => $result['kra3'],
        'Professional Development' => $result['kra4'],
    ];

    $pdf->SetFont('Times','',8.5);
    $pdf->SetTextColor(0,0,0);
    foreach ($kra_labels as $cat => $label) {
        $raw  = $kra_totals[$cat];
        $cap  = $raw_scores[$cat];
        $w    = $weights[$cat];
        $wval = round($cap * $w, 2);
        $fill = false;
        $pdf->Cell(70, 5.5, $label,                  1, 0, 'L', $fill);
        $pdf->Cell(24, 5.5, number_format($raw, 2),  1, 0, 'C', $fill);
        $pdf->Cell(24, 5.5, number_format($cap, 2),  1, 0, 'C', $fill);
        $pdf->Cell(24, 5.5, ($w * 100).'%',          1, 0, 'C', $fill);
        $pdf->Cell(44, 5.5, number_format($wval, 2), 1, 1, 'C', $fill);
    }

    // Final weighted score row
    $pdf->SetFillColor(220,220,220);
    $pdf->SetFont('Times','B',9.5);
    $pdf->Cell(142, 7, 'Final Weighted Score', 1, 0, 'R', true);
    $pdf->Cell(44,  7, number_format($result['weighted_score'], 2), 1, 1, 'C', true);
    $pdf->Ln(3);

    // â”€â”€ Result summary â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
    $pdf->SectionLabel('Result Summary');
    $inc      = $result['sub_rank_increment'];
    $inc_text = $inc > 0 ? '+' . $inc . ' sub-rank' . ($inc > 1 ? 's' : '') : 'No reclassification (score below 41)';

    $pdf->SetFillColor(235,235,235);
    $pdf->SetFont('Times','B',8);
    $pdf->Cell(62, 5.5, 'Weighted Score',    1, 0, 'C', true);
    $pdf->Cell(62, 5.5, 'Sub-rank Increment',1, 0, 'C', true);
    $pdf->Cell(62, 5.5, 'Current Rank',      1, 1, 'C', true);

    $pdf->SetFont('Times','B',11);
    $pdf->SetTextColor(0,0,0);
    $pdf->Cell(62, 8, number_format($result['weighted_score'], 2), 1, 0, 'C');
    $pdf->Cell(62, 8, $inc_text,                                   1, 0, 'C');
    $pdf->SetFont('Times','',9);
    $pdf->Cell(62, 8, $rank ?: 'N/A',                              1, 1, 'C');
    $pdf->Ln(4);

    // â”€â”€ Signatures â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
    $pdf->SectionLabel('Certification & Signatures');
    $pdf->Ln(12);

    $sig_names = [$a['full_name'], (!empty($a['checker_uid']) ? checkerDisplayLabel(['checker_label'=>$a['checker_name'],'user_id'=>$a['checker_uid']]) : 'N/A'), 'Campus Director / Dean', 'University President'];
    $sig_roles = ['Faculty - Applicant', 'Checker / Evaluator', 'Campus Director / Dean', 'University President'];
    $sw = 80;

    // Row 1: Faculty + Checker
    foreach ([0, 1] as $i) {
        $x = 12 + $i * ($sw + 14);
        $pdf->SetLineWidth(0.4);
        $pdf->Line($x, $pdf->GetY(), $x + $sw, $pdf->GetY());
        $pdf->SetXY($x, $pdf->GetY());
        $pdf->SetFont('Times','B',8.5);
        $pdf->SetTextColor(0,0,0);
        $pdf->Cell($sw, 5, $sig_names[$i], 0, 0, 'L');
    }
    $pdf->Ln(5);
    foreach ([0, 1] as $i) {
        $x = 12 + $i * ($sw + 14);
        $pdf->SetXY($x, $pdf->GetY());
        $pdf->SetFont('Times','',8);
        $pdf->SetTextColor(80,80,80);
        $pdf->Cell($sw, 4.5, $sig_roles[$i], 0, 0, 'L');
    }
    $pdf->Ln(14);

    // Row 2: Campus Director + University President
    foreach ([2, 3] as $i) {
        $x = 12 + ($i - 2) * ($sw + 14);
        $pdf->SetLineWidth(0.4);
        $pdf->Line($x, $pdf->GetY(), $x + $sw, $pdf->GetY());
        $pdf->SetXY($x, $pdf->GetY());
        $pdf->SetFont('Times','B',8.5);
        $pdf->SetTextColor(0,0,0);
        $pdf->Cell($sw, 5, $sig_names[$i], 0, 0, 'L');
    }
    $pdf->Ln(5);
    foreach ([2, 3] as $i) {
        $x = 12 + ($i - 2) * ($sw + 14);
        $pdf->SetXY($x, $pdf->GetY());
        $pdf->SetFont('Times','',8);
        $pdf->SetTextColor(80,80,80);
        $pdf->Cell($sw, 4.5, $sig_roles[$i], 0, 0, 'L');
    }
    $pdf->SetTextColor(0,0,0);
}

ob_end_clean();
$pdf->Output('I', 'Detailed_Report_'.preg_replace('/[^A-Za-z0-9_]/','_',$selected_cycle['cycle_name']??'report').'_'.date('Ymd').'.pdf');
