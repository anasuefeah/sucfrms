<?php
/**
 * Admin Report PDF (FPDF)
 */
ob_start();
session_start();
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../fpdf/fpdf.php';

if (!isLoggedIn() || !isAdmin()) { http_response_code(403); die('Access denied.'); }

$cycle_id = intval($_GET['cycle_id'] ?? 0);
$status   = trim($_GET['status'] ?? '');
$campus   = intval($_GET['campus_id'] ?? 0);

$all_cycles   = $pdo->query("SELECT cycle_id, cycle_name, status FROM cycles ORDER BY created_at DESC")->fetchAll();
$all_campuses = $pdo->query("SELECT campus_id, campus_name FROM campuses WHERE is_active=1 ORDER BY campus_name")->fetchAll();

if (!$cycle_id) {
    foreach ($all_cycles as $cy) {
        if ($cy['status'] === 'open') { $cycle_id = $cy['cycle_id']; break; }
    }
}
$selected_cycle = null;
foreach ($all_cycles as $cy) {
    if ($cy['cycle_id'] === $cycle_id) { $selected_cycle = $cy; break; }
}

$where  = "WHERE u.role IN ('faculty','checker_faculty')";
$params = [];
if ($cycle_id) { $where .= ' AND a.cycle_id=?'; $params[] = $cycle_id; }
if ($status && in_array($status, ['submitted','under_review','approved','rejected','reclassified','admin_rejected'])) {
    $where .= ' AND a.status=?'; $params[] = $status;
}
if ($campus) { $where .= ' AND u.campus_id=?'; $params[] = $campus; }

$apps = $pdo->prepare("
    SELECT a.*, u.full_name, u.employee_id, u.rank,
           COALESCE(camp.campus_name,'N/A') as campus_name,
           c.cycle_name, chk.full_name as checker_name
    FROM applications a
    JOIN users u ON a.user_id=u.user_id
    LEFT JOIN campuses camp ON u.campus_id=camp.campus_id
    LEFT JOIN cycles c ON a.cycle_id=c.cycle_id
    LEFT JOIN users chk ON a.checker_id=chk.user_id
    $where ORDER BY camp.campus_name, u.full_name
");
$apps->execute($params);
$apps = $apps->fetchAll();

$total_faculty = $pdo->query("SELECT COUNT(*) FROM users WHERE role IN ('faculty','checker_faculty') AND status='active'")->fetchColumn();
$status_counts = [];
foreach ($apps as $a) $status_counts[$a['status']] = ($status_counts[$a['status']] ?? 0) + 1;

$kra_q = $cycle_id
    ? $pdo->prepare("SELECT ks.kra_category, AVG(ks.computed_points) as avg_pts FROM kra_submissions ks JOIN applications a ON ks.application_id=a.application_id WHERE a.cycle_id=? GROUP BY ks.kra_category")
    : $pdo->prepare("SELECT kra_category, AVG(computed_points) as avg_pts FROM kra_submissions GROUP BY kra_category");
$cycle_id ? $kra_q->execute([$cycle_id]) : $kra_q->execute();
$kra_avgs = ['Instruction'=>0,'Research'=>0,'Extension'=>0,'Professional Development'=>0];
foreach ($kra_q->fetchAll() as $k) $kra_avgs[$k['kra_category']] = round($k['avg_pts'], 2);

$generated_at = date('M d, Y h:i A');
$admin_name   = $_SESSION['full_name'] ?? 'Administrator';

// â”€â”€ FPDF class â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
class AdminReport_PDF extends FPDF {
    public $cyc_name   = '';
    public $generated  = '';
    public $admin_name = '';

    function Header() {
        $this->SetFont('Times','B',12);
        $this->SetTextColor(30,58,138);
        $this->SetXY(10,8);
        $this->Cell(0,5,'State Universities and Colleges',0,1,'C');
        $this->SetFont('Times','',8.5);
        $this->SetTextColor(80,80,80);
        $this->SetX(10);
        $this->Cell(0,4,'SUC Faculty Reclassification Management System',0,1,'C');
        $this->SetFillColor(30,58,138);
        $this->SetTextColor(255,255,255);
        $this->SetFont('Times','B',12);
        $this->SetX(10);
        $this->Cell(0,7,'ADMIN REPORT',0,1,'C',true);
        $this->SetFont('Times','',7.5);
        $this->SetX(10);
        $this->Cell(0,4.5,'Cycle: '.$this->cyc_name.'   |   Generated: '.$this->generated.'   |   By: '.$this->admin_name,0,1,'C',true);
        $this->SetTextColor(0,0,0);
        $this->Ln(2);
    }

    function Footer() {
        $this->SetY(-10);
        $this->SetFont('Times','I',7);
        $this->SetTextColor(150,150,150);
        $this->Cell(0,4,'SUCFRMS - Admin Report  |  Page '.$this->PageNo().'/{nb}',0,0,'C');
    }

    function SectionBar($txt) {
        $this->SetFillColor(30,58,138);
        $this->SetTextColor(255,255,255);
        $this->SetFont('Times','B',8.5);
        $this->Cell(0,5.5,'  '.$txt,0,1,'L',true);
        $this->SetTextColor(0,0,0);
    }

    function StatBox($label, $value, $x, $y, $w) {
        $this->SetXY($x,$y);
        $this->SetFillColor(241,245,249);
        $this->SetFont('Times','B',14);
        $this->SetTextColor(30,58,138);
        $this->Cell($w,8,$value,1,0,'C',true);
        $this->SetXY($x,$y+8);
        $this->SetFont('Times','',7);
        $this->SetTextColor(100,100,100);
        $this->Cell($w,4,$label,1,1,'C',true);
        $this->SetTextColor(0,0,0);
    }

    function TableHead($cols) {
        $this->SetFillColor(30,58,138);
        $this->SetTextColor(255,255,255);
        $this->SetFont('Times','B',7.5);
        foreach ($cols as [$w,$t,$a]) $this->Cell($w,5.5,$t,1,0,$a,true);
        $this->Ln();
        $this->SetTextColor(0,0,0);
    }
}

// â”€â”€ Build PDF â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
$pdf = new AdminReport_PDF('L','mm','A4');
$pdf->AliasNbPages();
$pdf->cyc_name   = $selected_cycle['cycle_name'] ?? 'All Cycles';
$pdf->generated  = $generated_at;
$pdf->admin_name = $admin_name;
$pdf->SetMargins(10,38,10);
$pdf->SetAutoPageBreak(true,12);
$pdf->AddPage();

// â”€â”€ Summary stats â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
$pdf->SectionBar('SUMMARY STATISTICS');
$pdf->Ln(1);

$stats = [
    ['Total Faculty',   $total_faculty],
    ['Applications',    count($apps)],
    ['Reclassified',    $status_counts['reclassified'] ?? 0],
    ['Approved',        $status_counts['approved'] ?? 0],
    ['Pending Review',  ($status_counts['submitted']??0)+($status_counts['under_review']??0)],
    ['Rejected',        ($status_counts['rejected']??0)+($status_counts['admin_rejected']??0)],
];
$sw = 44; $sx = 10; $sy = $pdf->GetY();
foreach ($stats as $i => [$lbl,$val]) {
    $pdf->StatBox($lbl, (string)$val, $sx + $i*$sw, $sy, $sw);
}
$pdf->SetY($sy + 14);
$pdf->Ln(3);

// â”€â”€ KRA Averages â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
$pdf->SectionBar('AVERAGE KRA SCORES - ' . ($selected_cycle['cycle_name'] ?? 'All Cycles'));
$pdf->Ln(1);

$kra_labels = ['Instruction'=>'KRA I - Instruction','Research'=>'KRA II - Research','Extension'=>'KRA III - Extension','Professional Development'=>'KRA IV - Prof. Dev.'];
$kra_max    = ['Instruction'=>100,'Research'=>100,'Extension'=>100,'Professional Development'=>100];
$kw = 66; $kx = 10; $ky = $pdf->GetY();
foreach ($kra_avgs as $cat => $avg) {
    $max = $kra_max[$cat];
    $pct = $max > 0 ? min(100, ($avg / $max) * 100) : 0;
    $pdf->SetXY($kx, $ky);
    $pdf->SetFillColor(241,245,249);
    $pdf->SetFont('Times','B',7.5);
    $pdf->SetTextColor(30,58,138);
    $pdf->Cell($kw,5,$kra_labels[$cat],1,0,'L',true);
    $pdf->SetXY($kx, $ky+5);
    $pdf->SetFont('Times','B',11);
    $pdf->SetTextColor(30,58,138);
    $pdf->Cell($kw,7,$avg.' / '.$max.' pts',1,0,'C',true);
    // Progress bar
    $pdf->SetXY($kx+1, $ky+13);
    $pdf->SetFillColor(226,232,240);
    $pdf->Cell($kw-2,2,'',0,0,'L',true);
    if ($pct > 0) {
        $pdf->SetXY($kx+1, $ky+13);
        $pdf->SetFillColor(30,58,138);
        $pdf->Cell(($kw-2)*$pct/100,2,'',0,0,'L',true);
    }
    $pdf->SetTextColor(0,0,0);
    $kx += $kw;
}
$pdf->SetY($ky + 17);
$pdf->Ln(3);

// â”€â”€ Applications table â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
$pdf->SectionBar('APPLICATIONS (' . count($apps) . ')');

$cols = [
    [8,  '#',          'C'],
    [56, 'Faculty',    'L'],
    [24, 'Employee ID','C'],
    [36, 'Campus',     'L'],
    [32, 'Rank',       'L'],
    [20, 'Total',      'C'],
    [22, 'Weighted',   'C'],
    [14, 'Sub-rank',   'C'],
    [22, 'Status',     'C'],
    [30, 'Reviewed By','L'],
    [20, 'Submitted',  'C'],
];
$pdf->TableHead($cols);

$row = 1;
foreach ($apps as $a) {
    $ws  = (float)$a['weighted_score'];
    $inc = (int)$a['sub_rank_increment'];
    $fill = ($row % 2 === 0);
    $pdf->SetFillColor(248,250,252);
    $pdf->SetFont('Times','',7.5);

    $pdf->SetTextColor(148,163,184);
    $pdf->Cell(8,5.5,$row,1,0,'C',$fill);
    $pdf->SetTextColor(0,0,0);
    $pdf->SetFont('Times','B',7.5);
    $pdf->Cell(56,5.5,$a['full_name'],1,0,'L',$fill);
    $pdf->SetFont('Times','',7.5);
    $pdf->Cell(24,5.5,$a['employee_id']??'N/A',1,0,'C',$fill);
    $pdf->Cell(36,5.5,$a['campus_name'],1,0,'L',$fill);
    $pdf->Cell(32,5.5,$a['rank']??'N/A',1,0,'L',$fill);

    // Score color
    if ($ws >= 71)     $pdf->SetTextColor(22,163,74);
    elseif ($ws >= 41) $pdf->SetTextColor(217,119,6);
    else               $pdf->SetTextColor(220,38,38);
    $pdf->SetFont('Times','B',7.5);
    $pdf->Cell(20,5.5,number_format((float)$a['total_score'],2),1,0,'C',$fill);
    $pdf->Cell(22,5.5,number_format($ws,2),1,0,'C',$fill);

    $pdf->SetTextColor($inc>0?22:148,$inc>0?163:163,$inc>0?74:184);
    $pdf->Cell(14,5.5,$inc>0?'+'.$inc:'--',1,0,'C',$fill);

    $pdf->SetTextColor(0,0,0);
    $pdf->SetFont('Times','',7);
    $pdf->Cell(22,5.5,ucwords(str_replace('_',' ',$a['status'])),1,0,'C',$fill);
    $pdf->Cell(30,5.5,$a['checker_name']??'N/A',1,0,'L',$fill);
    $pdf->Cell(20,5.5,$a['submitted_at']?date('M d, Y',strtotime($a['submitted_at'])):'N/A',1,1,'C',$fill);

    $row++;
}

if (empty($apps)) {
    $pdf->SetFont('Times','I',9);
    $pdf->SetTextColor(148,163,184);
    $pdf->Cell(0,8,'No applications found for the selected filters.',1,1,'C');
    $pdf->SetTextColor(0,0,0);
}

ob_end_clean();
$pdf->Output('I','AdminReport_'.preg_replace('/[^A-Za-z0-9_]/','_',$selected_cycle['cycle_name']??'all').'_'.date('Ymd').'.pdf');
