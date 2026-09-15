<?php
/**
 * Audit Log PDF &mdash; FPDF
 */
ob_start();
if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__ . '/../includes/functions.php';
requireLogin();
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../fpdf/fpdf.php';

$uid  = $_SESSION['user_id'];
$role = $_SESSION['role'];

$search        = trim($_GET['search']        ?? '');
$filter_role   = trim($_GET['filter_role']   ?? '');
$filter_action = trim($_GET['filter_action'] ?? '');

$where  = [];
$params = [];
if ($role !== 'admin') { $where[] = 'al.user_id = ?'; $params[] = $uid; }
if ($search) {
    $where[]  = '(al.action_performed LIKE ? OR al.details LIKE ? OR u.full_name LIKE ?)';
    $params   = array_merge($params, ["%$search%", "%$search%", "%$search%"]);
}
if ($filter_role && $role === 'admin') { $where[] = 'al.role_at_time = ?'; $params[] = $filter_role; }
if ($filter_action) { $where[] = 'al.action_performed LIKE ?'; $params[] = "%$filter_action%"; }

$where_sql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

$stmt = $pdo->prepare("
    SELECT al.*, u.full_name
    FROM audit_logs al
    LEFT JOIN users u ON al.user_id = u.user_id
    $where_sql
    ORDER BY al.timestamp DESC
");
$stmt->execute($params);
$logs = $stmt->fetchAll();

$printed_at = date('M d, Y h:i A');
$admin_name = $_SESSION['full_name'] ?? 'Administrator';
$title      = $role === 'admin' ? 'System Audit Log' : 'My Activity Log';

// â”€â”€ FPDF â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
class AuditPDF extends FPDF {
    public $doc_title  = '';
    public $printed_at = '';
    public $printed_by = '';
    public $total_rows = 0;
    public $cols       = [];

    function Header() {
        $this->SetFont('Times','B',13);
        $this->SetTextColor(0,0,0);
        $this->SetXY(12, 6);
        $this->Cell(0, 6, 'State Universities and Colleges', 0, 1, 'C');

        $this->SetFont('Times','',8.5);
        $this->SetTextColor(60,60,60);
        $this->SetX(12);
        $this->Cell(0, 4.5, 'SUC Faculty Reclassification Management System', 0, 1, 'C');

        $this->SetLineWidth(0.4);
        $this->SetDrawColor(0,0,0);
        $this->Line(12, $this->GetY(), 285, $this->GetY());
        $this->Ln(1.5);

        $this->SetFont('Times','B',11);
        $this->SetTextColor(0,0,0);
        $this->SetX(12);
        $this->Cell(0, 6, strtoupper($this->doc_title), 0, 1, 'C');

        $this->SetFont('Times','',7.5);
        $this->SetTextColor(60,60,60);
        $this->SetX(12);
        $this->Cell(0, 4,
            'Printed by: '.$this->printed_by.
            '   |   Date: '.$this->printed_at.
            '   |   Total Records: '.$this->total_rows,
            0, 1, 'C');

        $this->SetLineWidth(0.4);
        $this->Line(12, $this->GetY(), 285, $this->GetY());
        $this->SetTextColor(0,0,0);
        $this->Ln(2);
    }

    function Footer() {
        $this->SetY(-10);
        $this->SetFont('Times','I',7);
        $this->SetTextColor(100,100,100);
        $this->Cell(0, 4, 'SUCFRMS - '.$this->doc_title.'   |   Page '.$this->PageNo().'/{nb}', 0, 0, 'C');
    }

    function TableHead(array $cols): void {
        $this->SetFillColor(210,210,210);
        $this->SetTextColor(0,0,0);
        $this->SetFont('Times','B',8);
        foreach ($cols as [$w,$t,$a]) $this->Cell($w, 6, $t, 1, 0, $a, true);
        $this->Ln();
    }

    /**
     * Draw a full row where one column may wrap.
     * All cells are drawn with SetXY to guarantee alignment.
     */
    function MultiRow(array $cells, int $wrap_idx, float $line_h, bool $fill): void {
        $this->SetFont('Times','',7.5);

        // 1. Pre-split the wrapping cell text into lines
        $wrap_w    = $cells[$wrap_idx][0];
        $wrap_text = $cells[$wrap_idx][1];
        $words     = explode(' ', $wrap_text);
        $lines_arr = [];
        $cur_line  = '';
        foreach ($words as $word) {
            $test = $cur_line === '' ? $word : $cur_line.' '.$word;
            if ($this->GetStringWidth($test) <= $wrap_w - 3) {
                $cur_line = $test;
            } else {
                if ($cur_line !== '') $lines_arr[] = $cur_line;
                $cur_line = $word;
            }
        }
        if ($cur_line !== '') $lines_arr[] = $cur_line;
        if (empty($lines_arr)) $lines_arr = [''];

        $row_h = max(5.5, count($lines_arr) * $line_h);

        // 2. Manual page break
        if ($this->GetY() + $row_h > $this->PageBreakTrigger) {
            $this->AddPage();
            $this->TableHead($this->cols); // repeat header on new page
        }

        $start_x = 12; // left margin
        $start_y = $this->GetY();
        $bg_r = $fill ? 245 : 255;
        $this->SetFillColor($bg_r, $bg_r, $bg_r);
        $this->SetTextColor(0,0,0);
        $this->SetDrawColor(0,0,0);
        $this->SetLineWidth(0.2);

        $cur_x = $start_x;
        foreach ($cells as $idx => [$w, $text, $align]) {
            if ($idx === $wrap_idx) {
                // Draw the cell border + fill as one rect
                if ($fill) {
                    $this->Rect($cur_x, $start_y, $w, $row_h, 'FD');
                } else {
                    $this->Rect($cur_x, $start_y, $w, $row_h, 'D');
                }
                // Write each line of text
                $this->SetFont('Times','',7.5);
                foreach ($lines_arr as $li => $line) {
                    $this->SetXY($cur_x + 1, $start_y + ($li * $line_h) + 1);
                    $this->Cell($w - 2, $line_h - 0.5, $line, 0, 0, $align);
                }
            } else {
                $this->SetXY($cur_x, $start_y);
                $this->SetFont('Times','',7.5);
                $this->Cell($w, $row_h, $text, 1, 0, $align, $fill);
            }
            $cur_x += $w;
        }

        // Advance cursor past this row
        $this->SetXY($start_x, $start_y + $row_h);
    }
}

// â”€â”€ Build PDF â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
$pdf = new AuditPDF('L','mm','A4');
$pdf->AliasNbPages();
$pdf->doc_title  = $title;
$pdf->printed_at = $printed_at;
$pdf->printed_by = $admin_name;
$pdf->total_rows = count($logs);
$pdf->SetMargins(12, 40, 12);
$pdf->SetAutoPageBreak(false, 14); // manual page break in MultiRow
$pdf->SetDrawColor(0,0,0);
$pdf->SetLineWidth(0.2);
$pdf->AddPage();

// Page width = 297 - 12 - 12 = 273mm
// Admin:     # | User | Role | Action | Details | Date
//            8 + 44  + 18   + 36     + 124     + 43  = 273
// Non-admin: # | Action | Details | Date
//            8 + 48   + 172     + 45  = 273

if ($role === 'admin') {
    $cols = [
        [8,   '#',           'C'],
        [44,  'User',        'L'],
        [18,  'Role',        'C'],
        [36,  'Action',      'L'],
        [124, 'Details',     'L'],
        [43,  'Date & Time', 'C'],
    ];
    $wrap_idx = 4; // Details column
} else {
    $cols = [
        [8,   '#',           'C'],
        [48,  'Action',      'L'],
        [172, 'Details',     'L'],
        [45,  'Date & Time', 'C'],
    ];
    $wrap_idx = 2; // Details column
}

$pdf->cols = $cols;
$pdf->TableHead($cols);

if (empty($logs)) {
    $pdf->SetFont('Times','I',9);
    $pdf->SetTextColor(80,80,80);
    $pdf->Cell(0, 8, 'No records found.', 1, 1, 'C');
} else {
    foreach ($logs as $i => $log) {
        $details = strip_tags($log['details'] ?? '');
        $details = preg_replace('/\s+/', ' ', trim($details));
        if (mb_strlen($details) > 200) $details = mb_substr($details, 0, 197).'...';

        $dt = date('M d, Y h:i A', strtotime($log['timestamp']));

        if ($role === 'admin') {
            $row = [
                [8,   (string)($i + 1),                              'C'],
                [44,  $log['full_name'] ?? 'System',                 'L'],
                [18,  ucfirst($log['role_at_time'] ?? '-'),           'C'],
                [36,  $log['action_performed'],                       'L'],
                [124, $details,                                       'L'],
                [43,  $dt,                                            'C'],
            ];
        } else {
            $row = [
                [8,   (string)($i + 1),          'C'],
                [48,  $log['action_performed'],   'L'],
                [172, $details,                   'L'],
                [45,  $dt,                        'C'],
            ];
        }

        $pdf->MultiRow($row, $wrap_idx, 4.5, ($i % 2 === 0));
    }
}

ob_end_clean();
$pdf->Output('I', 'Audit_Log_'.date('Ymd').'.pdf');