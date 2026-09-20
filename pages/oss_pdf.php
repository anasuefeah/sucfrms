<?php
/**
 * OSS — Overall Score Summary PDF (FPDF)
 */
ob_start();
if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../fpdf/fpdf.php';

requireLogin();
if (!isAdmin() && !isChecker()) { http_response_code(403); die('Access denied.'); }

// ── Filters ──────────────────────────────────────────────────
$cycle_id  = intval($_GET['cycle_id']  ?? 0);
$campus_id = intval($_GET['campus_id'] ?? 0);
$status    = trim($_GET['status']      ?? '');

$all_cycles   = $pdo->query("SELECT cycle_id, cycle_name, status, start_date, end_date FROM cycles ORDER BY created_at DESC")->fetchAll();
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

// ── Query ─────────────────────────────────────────────────────
$where  = "WHERE u.role = 'faculty' AND a.cycle_id=?";
$params = [$cycle_id];
if ($campus_id) { $where .= ' AND u.campus_id=?'; $params[] = $campus_id; }
if ($status && in_array($status, ['submitted','under_review','approved','rejected','reclassified'])) {
    $where .= ' AND a.status=?'; $params[] = $status;
}

$rows = $pdo->prepare("
    SELECT a.*, u.full_name, u.first_name, u.middle_name, u.last_name, u.employee_id, u.rank,
           COALESCE(camp.campus_name,'N/A') as campus_name,
           chk.user_id as checker_uid,
           chk.checker_label as checker_label
    FROM applications a
    JOIN users u ON a.user_id=u.user_id
    LEFT JOIN campuses camp ON u.campus_id=camp.campus_id
    LEFT JOIN users chk ON a.checker_id=chk.user_id
    $where
    ORDER BY camp.campus_name, u.full_name
");
$rows->execute($params);
$applications = $rows->fetchAll();

// ── KRA scores ────────────────────────────────────────────────
$kra_cats   = ['Instruction','Research','Extension','Professional Development'];
$kra_scores = [];
if ($applications) {
    $app_ids      = array_column($applications, 'application_id');
    $placeholders = implode(',', array_fill(0, count($app_ids), '?'));
    $ks = $pdo->prepare("SELECT application_id, kra_category, computed_points FROM kra_submissions WHERE application_id IN ($placeholders)");
    $ks->execute($app_ids);
    foreach ($ks->fetchAll() as $k) {
        $kra_scores[$k['application_id']][$k['kra_category']] = (float)$k['computed_points'];
    }
}

// ── Summary stats ─────────────────────────────────────────────
$total         = count($applications);
$status_counts = [];
foreach ($applications as $a) $status_counts[$a['status']] = ($status_counts[$a['status']] ?? 0) + 1;

// ── KRA averages ──────────────────────────────────────────────
$kra_avgs = ['Instruction'=>[],'Research'=>[],'Extension'=>[],'Professional Development'=>[]];
foreach ($applications as $a) {
    foreach ($kra_cats as $cat) {
        $kra_avgs[$cat][] = $kra_scores[$a['application_id']][$cat] ?? 0;
    }
}
$kra_avg_vals = [];
foreach ($kra_avgs as $cat => $vals) {
    $kra_avg_vals[$cat] = count($vals) > 0 ? round(array_sum($vals) / count($vals), 2) : 0;
}

$printed_at = date('F d, Y h:i A');
$admin_name = $_SESSION['full_name'] ?? 'Administrator';

// ── Group by campus ───────────────────────────────────────────
$by_campus = [];
foreach ($applications as $a) {
    $by_campus[$a['campus_name']][] = $a;
}

// ── FPDF Class ────────────────────────────────────────────────
class OSS_PDF extends FPDF {
    public $cyc_name    = '';
    public $printed_at  = '';
    public $admin_name  = '';

    function Header() {
        $this->SetFont('Times', 'B', 12);
        $this->SetTextColor(30, 58, 138);
        $this->SetXY(10, 8);
        $this->Cell(0, 5, 'State Universities and Colleges', 0, 1, 'C');
        $this->SetFont('Times', '', 8.5);
        $this->SetTextColor(80, 80, 80);
        $this->SetX(10);
        $this->Cell(0, 4, 'SUC Faculty Reclassification Management System', 0, 1, 'C');
        $this->SetFillColor(30, 58, 138);
        $this->SetTextColor(255, 255, 255);
        $this->SetFont('Times', 'B', 12);
        $this->SetX(10);
        $this->Cell(0, 7, 'OVERALL SCORE SUMMARY (OSS)', 0, 1, 'C', true);
        $this->SetFont('Times', '', 7.5);
        $this->SetX(10);
        $this->Cell(0, 4.5, 'Cycle: ' . $this->cyc_name . '   |   Printed: ' . $this->printed_at . '   |   By: ' . $this->admin_name, 0, 1, 'C', true);
        $this->SetTextColor(0, 0, 0);
        $this->Ln(2);
    }

    function Footer() {
        $this->SetY(-10);
        $this->SetFont('Times', 'I', 7);
        $this->SetTextColor(150, 150, 150);
        $this->Cell(0, 4, 'SUCFRMS - Overall Score Summary (OSS)  |  Page ' . $this->PageNo() . '/{nb}', 0, 0, 'C');
    }

    function SectionBar($txt) {
        $this->SetFillColor(30, 58, 138);
        $this->SetTextColor(255, 255, 255);
        $this->SetFont('Times', 'B', 8.5);
        $this->Cell(0, 5.5, '  ' . $txt, 0, 1, 'L', true);
        $this->SetTextColor(0, 0, 0);
    }

    function CampusBar($txt) {
        $this->SetFillColor(71, 85, 105);
        $this->SetTextColor(255, 255, 255);
        $this->SetFont('Times', 'B', 8);
        $this->Cell(0, 5, '  ' . $txt, 0, 1, 'L', true);
        $this->SetTextColor(0, 0, 0);
    }

    function TableHead($cols) {
        $this->SetFillColor(30, 58, 138);
        $this->SetTextColor(255, 255, 255);
        $this->SetFont('Times', 'B', 7.5);
        foreach ($cols as [$w, $t, $a]) $this->Cell($w, 5.5, $t, 1, 0, $a, true);
        $this->Ln();
        $this->SetTextColor(0, 0, 0);
    }
}

// ── Build PDF ─────────────────────────────────────────────────
$pdf = new OSS_PDF('L', 'mm', 'A4');
$pdf->AliasNbPages();
$pdf->cyc_name   = $selected_cycle['cycle_name'] ?? 'All Cycles';
$pdf->printed_at = $printed_at;
$pdf->admin_name = $admin_name;
$pdf->SetMargins(10, 38, 10);
$pdf->SetAutoPageBreak(true, 12);
$pdf->AddPage();

// ── Summary stats row ─────────────────────────────────────────
$pdf->SectionBar('SUMMARY');
$pdf->Ln(1);
$stats = [
    ['Total',        $total],
    ['Reclassified', $status_counts['reclassified'] ?? 0],
    ['Approved',     $status_counts['approved'] ?? 0],
    ['Pending',      ($status_counts['submitted'] ?? 0) + ($status_counts['under_review'] ?? 0)],
    ['Returned',     $status_counts['rejected'] ?? 0],
];
$sw = 53; $sx = 10; $sy = $pdf->GetY();
foreach ($stats as [$lbl, $val]) {
    $pdf->SetXY($sx, $sy);
    $pdf->SetFillColor(241, 245, 249);
    $pdf->SetFont('Times', 'B', 14);
    $pdf->SetTextColor(30, 58, 138);
    $pdf->Cell($sw, 8, (string)$val, 1, 0, 'C', true);
    $pdf->SetXY($sx, $sy + 8);
    $pdf->SetFont('Times', '', 7);
    $pdf->SetTextColor(100, 100, 100);
    $pdf->Cell($sw, 4, $lbl, 1, 0, 'C', true);
    $pdf->SetTextColor(0, 0, 0);
    $sx += $sw;
}
$pdf->SetY($sy + 14);
$pdf->Ln(3);

// ── KRA averages ──────────────────────────────────────────────
$kra_labels = [
    'Instruction'              => 'KRA I - Instruction',
    'Research'                 => 'KRA II - Research',
    'Extension'                => 'KRA III - Extension',
    'Professional Development' => 'KRA IV - Prof. Dev.',
];
$pdf->SectionBar('AVERAGE KRA SCORES');
$pdf->Ln(1);
$kw = 65; $kx = 10; $ky = $pdf->GetY();
foreach ($kra_avg_vals as $cat => $avg) {
    $pdf->SetXY($kx, $ky);
    $pdf->SetFillColor(241, 245, 249);
    $pdf->SetFont('Times', 'B', 7.5);
    $pdf->SetTextColor(30, 58, 138);
    $pdf->Cell($kw, 5, $kra_labels[$cat], 1, 0, 'L', true);
    $pdf->SetXY($kx, $ky + 5);
    $pdf->SetFont('Times', 'B', 11);
    $pdf->Cell($kw, 7, $avg . ' / 100 pts', 1, 0, 'C', true);
    $pdf->SetTextColor(0, 0, 0);
    $kx += $kw;
}
$pdf->SetY($ky + 14);
$pdf->Ln(3);

// ── Faculty score table grouped by campus ─────────────────────
$pdf->SectionBar('FACULTY SCORE LIST (' . $total . ' records)');

// Column widths — landscape A4 usable width ~277mm
$cols = [
    [7,   '#',            'C'],
    [52,  'Faculty Name', 'L'],
    [22,  'Employee ID',  'C'],
    [25,  'Rank',         'L'],
    [14,  'KRA I',        'C'],
    [14,  'KRA II',       'C'],
    [14,  'KRA III',      'C'],
    [14,  'KRA IV',       'C'],
    [16,  'Total',        'C'],
    [18,  'Weighted',     'C'],
    [14,  'Sub-rank',     'C'],
    [22,  'Status',       'C'],
    [30,  'Reviewed By',  'L'],
];

if (empty($applications)) {
    $pdf->SetFont('Times', 'I', 9);
    $pdf->SetTextColor(148, 163, 184);
    $pdf->Cell(0, 8, 'No applications found for the selected filters.', 1, 1, 'C');
    $pdf->SetTextColor(0, 0, 0);
} else {
    $row_num = 1;
    foreach ($by_campus as $campus_name => $campus_apps) {
        // Campus header bar
        $pdf->CampusBar($campus_name . '  (' . count($campus_apps) . ' faculty)');

        // Table header
        $pdf->TableHead($cols);

        foreach ($campus_apps as $a) {
            $scores = $kra_scores[$a['application_id']] ?? [];
            $ws     = (float)$a['weighted_score'];
            $inc    = (int)$a['sub_rank_increment'];
            $fill   = ($row_num % 2 === 0);
            $pdf->SetFillColor(248, 250, 252);
            $pdf->SetFont('Times', '', 7.5);

            // Row number
            $pdf->SetTextColor(148, 163, 184);
            $pdf->Cell(7, 5.5, $row_num, 1, 0, 'C', $fill);

            // Faculty name
            $pdf->SetTextColor(0, 0, 0);
            $pdf->SetFont('Times', 'B', 7.5);
            $fname = formatDisplayName($a);
            $pdf->Cell(52, 5.5, $fname, 1, 0, 'L', $fill);

            // Employee ID, Rank
            $pdf->SetFont('Times', '', 7.5);
            $pdf->Cell(22, 5.5, $a['employee_id'] ?? 'N/A', 1, 0, 'C', $fill);
            $pdf->Cell(25, 5.5, $a['rank'] ?? 'N/A', 1, 0, 'L', $fill);

            // KRA scores
            $pdf->Cell(14, 5.5, number_format($scores['Instruction'] ?? 0, 2), 1, 0, 'C', $fill);
            $pdf->Cell(14, 5.5, number_format($scores['Research'] ?? 0, 2), 1, 0, 'C', $fill);
            $pdf->Cell(14, 5.5, number_format($scores['Extension'] ?? 0, 2), 1, 0, 'C', $fill);
            $pdf->Cell(14, 5.5, number_format($scores['Professional Development'] ?? 0, 2), 1, 0, 'C', $fill);

            // Total & weighted score with color
            if ($ws >= 71)     { $pdf->SetTextColor(22, 163, 74); }
            elseif ($ws >= 41) { $pdf->SetTextColor(217, 119, 6); }
            else               { $pdf->SetTextColor(220, 38, 38); }
            $pdf->SetFont('Times', 'B', 7.5);
            $pdf->Cell(16, 5.5, number_format((float)$a['total_score'], 2), 1, 0, 'C', $fill);
            $pdf->Cell(18, 5.5, number_format($ws, 2), 1, 0, 'C', $fill);

            // Sub-rank
            $pdf->SetTextColor($inc > 0 ? 22 : 148, $inc > 0 ? 163 : 163, $inc > 0 ? 74 : 184);
            $pdf->Cell(14, 5.5, $inc > 0 ? '+' . $inc : '--', 1, 0, 'C', $fill);

            // Status
            $pdf->SetTextColor(0, 0, 0);
            $pdf->SetFont('Times', '', 7);
            $pdf->Cell(22, 5.5, ucwords(str_replace('_', ' ', $a['status'])), 1, 0, 'C', $fill);

            // Reviewed by
            $chk_name = '';
            if (!empty($a['checker_uid'])) {
                $chk_name = checkerDisplayLabel([
                    'checker_label' => $a['checker_label'] ?? null,
                    'user_id'       => $a['checker_uid'],
                ]);
            }
            $pdf->Cell(30, 5.5, $chk_name ?: 'N/A', 1, 1, 'L', $fill);

            $row_num++;
        }

        // Campus subtotal row
        $ws_vals    = array_filter(array_map(fn($a) => (float)$a['weighted_score'], $campus_apps));
        $avg_ws     = count($ws_vals) > 0 ? round(array_sum($ws_vals) / count($ws_vals), 2) : 0;
        $reclass_ct = count(array_filter($campus_apps, fn($a) => $a['status'] === 'reclassified'));
        $pdf->SetFillColor(239, 246, 255);
        $pdf->SetFont('Times', 'B', 7.5);
        $pdf->SetTextColor(30, 58, 138);
        $pdf->Cell(120, 5, 'Campus Average Weighted Score: ' . $avg_ws, 1, 0, 'R', true);
        $pdf->Cell(42,  5, 'Avg', 1, 0, 'C', true);
        $pdf->Cell(95,  5, 'Reclassified: ' . $reclass_ct . ' / ' . count($campus_apps), 1, 1, 'L', true);
        $pdf->SetTextColor(0, 0, 0);
        $pdf->Ln(2);
    }
}

// ── Signature block ───────────────────────────────────────────
$pdf->Ln(4);
$pdf->SetFont('Times', 'B', 8.5);
$pdf->SetTextColor(0, 0, 0);
$pdf->Cell(0, 5, 'CERTIFICATION & SIGNATURES', 0, 1, 'L');
$pdf->SetDrawColor(0, 0, 0);
$pdf->Line($pdf->GetX(), $pdf->GetY(), $pdf->GetX() + 277, $pdf->GetY());
$pdf->Ln(3);

$sw3 = 92;
$sigs = [
    ['Prepared by — ' . $admin_name, 'Administrator'],
    ['Reviewed by — Campus Director / Dean', ''],
    ['Approved by — University President', ''],
];
$sig_x = 10; $sig_y = $pdf->GetY();
foreach ($sigs as [$role, $name]) {
    $pdf->SetXY($sig_x, $sig_y + 10);
    $pdf->SetFont('Times', '', 7.5);
    $pdf->SetDrawColor(0, 0, 0);
    $pdf->Cell($sw3 - 4, 0.3, '', 'T');
    $pdf->SetXY($sig_x, $sig_y + 11);
    $pdf->Cell($sw3 - 4, 4, $role, 0, 0, 'L');
    $pdf->SetXY($sig_x, $sig_y + 15);
    $pdf->Cell($sw3 - 4, 4, 'Date: ___________________', 0, 0, 'L');
    $sig_x += $sw3;
}

ob_end_clean();
$cycle_slug = preg_replace('/[^A-Za-z0-9_]/', '_', $selected_cycle['cycle_name'] ?? 'all');
$pdf->Output('I', 'OSS_' . $cycle_slug . '_' . date('Ymd') . '.pdf');
