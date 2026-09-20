<?php
/**
 * CHED KRA PDF Renderer — Official "Faculty Summary of Scores" template
 * Reusable render engine, decoupled from DB access so it can be required
 * by pages/kra_pdf.php (live data) or a test harness (mock data).
 */

require_once __DIR__ . '/../fpdf/fpdf.php';

// ═══════════════════════════════════════════════════════════════════════════════
// KRA DEFINITIONS — one row per official indicator (fixed-form summary, not a
// per-submission ledger). Each item's "Points" value is the SUM of computed_points
// from matching kra_submissions entries, capped where the official form caps it.
// ═══════════════════════════════════════════════════════════════════════════════

function kraDefinitions(): array {
    return [
        'I' => [
            'number' => 'I', 'label' => 'TEACHING EFFECTIVENESS',
            'category' => 'Instruction', 'max_points' => 100,
            'criteria' => [
                'A' => [
                    'title' => 'CRITERION A - TEACHING EFFECTIVENESS (SET + SEF) (MAX = 60 POINTS)',
                    'max' => 60,
                    'items' => [
                        ['label' => '1. STUDENT EVALUATION OF TEACHING (SET) AND SELF-EVALUATION FORM (SEF) RATING', 'types' => ['A-set-sef']],
                    ],
                ],
                'B' => [
                    'title' => 'CRITERION B - INSTRUCTIONAL MATERIALS / CURRICULUM DEVELOPMENT (MAX = 30 POINTS)',
                    'max' => 30,
                    'items' => [
                        ['label' => '1. DEVELOPMENT OF INSTRUCTIONAL MATERIALS / CURRICULUM PROGRAMS', 'types' => ['B-material']],
                    ],
                ],
                'C' => [
                    'title' => 'CRITERION C - THESIS / DISSERTATION / MENTORSHIP (MAX = 10 POINTS)',
                    'max' => 10,
                    'items' => [
                        ['label' => '1. THESIS/DISSERTATION ADVISING, PANEL MEMBERSHIP, AND RESEARCH-RELATED MENTORSHIP', 'types' => ['C-thesis', 'C-mentor']],
                    ],
                ],
            ],
        ],
        'II' => [
            'number' => 'II', 'label' => 'RESEARCH, INNOVATION & CREATIVE WORK',
            'category' => 'Research', 'max_points' => 100,
            'criteria' => [
                'A' => [
                    'title' => 'CRITERION A - RESEARCH, INNOVATION & CREATIVE WORK (MAX = 100 POINTS)',
                    'max' => 100,
                    'items' => [
                        ['label' => '1. RESEARCH OUTPUTS, INVENTIONS, AND CREATIVE WORKS', 'types' => null],
                    ],
                ],
            ],
        ],
        'III' => [
            'number' => 'III', 'label' => 'EXTENSION SERVICES',
            'category' => 'Extension', 'max_points' => 100,
            'criteria' => [
                'A' => [
                    'title' => 'CRITERION A - EXTENSION SERVICES (MAX = 100 POINTS)',
                    'max' => 100,
                    'items' => [
                        ['label' => '1. EXTENSION SERVICES AND COMMUNITY ENGAGEMENT ACTIVITIES', 'types' => null],
                    ],
                ],
            ],
        ],
        'IV' => [
            'number' => 'IV', 'label' => 'PROFESSIONAL DEVELOPMENT',
            'category' => 'Professional Development', 'max_points' => 100,
            'criteria' => [
                'A' => [
                    'title' => 'CRITERION A - INVOLVEMENT IN PROFESSIONAL ORGANIZATIONS (MAX = 20 POINTS)',
                    'max' => 20,
                    'items' => [
                        ['label' => '1. FOR CURRENT INDIVIDUAL MEMBERSHIP AND ACTIVE ROLE/CONTRIBUTION IN RELEVANT, RECOGNIZED/ REGISTERED PROFESSIONAL ORGANIZATION, LEARNED/HONOR/SCIENTIFIC SOCIETY', 'types' => ['A-org']],
                    ],
                ],
                'B' => [
                    'title' => 'CRITERION B - CONTINUING DEVELOPMENT (MAX = 60 POINTS)',
                    'max' => 60,
                    'items' => [
                        [
                            'label' => '1. EDUCATIONAL QUALIFICATIONS (MAX - 40 POINTS)',
                            'types' => ['B-degree'],
                            'subgroups' => [
                                ['label' => '1.1 FOR DOCTORATE DEGREE', 'test' => function ($v) { return $v >= 40; }],
                                ['label' => '1.2 FOR ADDITIONAL DEGREES', 'test' => function ($v) { return $v < 40; }],
                            ],
                        ],
                        ['label' => '2. FOR EVERY PARTICIPATION IN CONFERENCES, SEMINARS, WORKSHOPS, INDUSTRY IMMERSION (MAX = 10 POINTS)', 'types' => ['B-training']],
                        ['label' => '3. FOR EVERY PAPER PRESENTATION IN CONFERENCES (MAX = 10 POINTS)', 'types' => ['B-paper']],
                    ],
                ],
                'C' => [
                    'title' => 'CRITERION C - AWARDS AND RECOGNITION (MAX = 20 POINTS)',
                    'max' => 20,
                    'items' => [
                        ['label' => '1. FOR EVERY AWARD OF DISTINCTION RECEIVED IN RECOGNITION OF ACHIEVEMENT IN RELEVANT AREAS OF SPECIALIZATION/PROFESSION AND/OR ASSIGNMENT OF THE FACULTY CONCERNED.', 'types' => ['C-award']],
                    ],
                ],
                'D' => [
                    'title' => 'CRITERION D - BONUS INDICATOR FOR NEWLY HIRED FACULTY (MAX = 20 POINTS)',
                    'max' => 20,
                    'items' => [
                        ['label' => '1. FOR EVERY YEAR OF FULL-TIME ACADEMIC SERVICE IN AN INSTITUTION OF HIGHER LEARNING AS:', 'types' => ['D-prior-academic']],
                        ['label' => '2. FOR EVERY YEAR OF INDUSTRY EXPERIENCE (NON-ACADEMIC ORGANIZATION) IN:', 'types' => ['D-prior-industry']],
                    ],
                ],
            ],
        ],
    ];
}

// ═══════════════════════════════════════════════════════════════════════════════
// ENTRY HELPERS
// ═══════════════════════════════════════════════════════════════════════════════

function kraEntryType(array $entry): string {
    $parts = explode('|||', $entry['remarks'] ?? '');
    return trim($parts[0] ?? '');
}

function kraEntrySubVal(array $entry): float {
    $parts = array_map('trim', explode('|||', $entry['remarks'] ?? ''));
    return (float)($parts[2] ?? 0);
}

/**
 * Bucket every entry (for one KRA's category) into [criterion_letter][item_index] => [entries].
 * A type prefix (e.g. 'B-training') is matched against every item's declared types across
 * the whole KRA definition; a catch-all item (types === null) absorbs anything unmatched.
 */
function kraBucketEntries(array $def, array $entries): array {
    $buckets = [];
    $catch_all = null; // [letter, idx] of a catch-all item, if any
    $type_map = [];     // lowercased type => [letter, idx]

    foreach ($def['criteria'] as $letter => $criterion) {
        foreach ($criterion['items'] as $idx => $item) {
            $buckets[$letter][$idx] = [];
            if ($item['types'] === null) {
                $catch_all = [$letter, $idx];
                continue;
            }
            foreach ($item['types'] as $t) {
                $type_map[strtolower($t)] = [$letter, $idx];
            }
        }
    }

    $first_letter = array_key_first($def['criteria']);

    foreach ($entries as $entry) {
        $type = strtolower(kraEntryType($entry));
        if (isset($type_map[$type])) {
            [$letter, $idx] = $type_map[$type];
        } elseif ($catch_all !== null) {
            [$letter, $idx] = $catch_all;
        } else {
            $letter = $first_letter;
            $idx = 0;
        }
        $buckets[$letter][$idx][] = $entry;
    }

    return $buckets;
}

// ═══════════════════════════════════════════════════════════════════════════════
// PDF CLASS — plain black/white/gray, Times font, matches the CHED printed form
// ═══════════════════════════════════════════════════════════════════════════════

class KRA_PDF extends FPDF {
    public $kra_number = '';
    public $kra_label = '';
    public $faculty_name = '';
    public $faculty_rank = '';
    public $faculty_department = '';
    public $suc_name = '';
    public $faculty_campus = '';
    public $is_iss_page = false;

    const COL_CRITERIA  = 116;
    const COL_POINTS    = 37;
    const COL_ALLOWABLE = 37;
    const ROW_H         = 5.2;
    const LABEL_LEAD    = 3.2; // padding-left inside criteria cell

    const GRAY_BAND       = [214, 214, 214]; // criterion band
    const GRAY_ITEM       = [234, 234, 234]; // item/column header row
    const GRAY_SUBTOTAL   = [244, 244, 244]; // item "TOTAL POINTS"
    const GRAY_CRIT_TOTAL = [214, 214, 214]; // "TOTAL POINTS FOR CRITERION X"
    const GRAY_GRAND      = [199, 199, 199]; // grand total
    const GRAY_LABELCOL   = [240, 240, 240]; // faculty info label column

    function Header() {
        $logo_path = __DIR__ . '/../assets/images/ched_logo.png';
        if (!file_exists($logo_path)) $logo_path = __DIR__ . '/../assets/images/logo.jpg';
        if (file_exists($logo_path)) {
            $this->Image($logo_path, 14, 8, 18, 18);
        }

        $this->SetTextColor(0, 0, 0);
        $this->SetXY(34, 9);
        $this->SetFont('Times', 'B', 14);
        $this->Cell(156, 6, 'COMMISSION ON HIGHER EDUCATION', 0, 1, 'C');
        $this->SetX(34);
        $this->SetFont('Times', 'B', 10);
        $this->Cell(156, 5, 'FACULTY POSITION RECLASSIFICATION FOR SUCs', 0, 1, 'C');

        $this->SetLineWidth(0.5);
        $this->Line(10, 29, 200, 29);
        $this->SetLineWidth(0.2);

        $this->SetY(33);
        $this->SetFont('Times', 'B', 12);
        if ($this->is_iss_page) {
            $this->Cell(0, 6, 'INDIVIDUAL SUMMARY SHEET', 0, 1, 'C');
            $this->SetFont('Times', 'B', 11);
            $this->Cell(0, 6, 'RANK-WEIGHTED SCORING & RECLASSIFICATION', 0, 1, 'C');
        } else {
            $this->Cell(0, 6, 'SE KRA ' . $this->kra_number . ' - ' . $this->kra_label, 0, 1, 'C');
            $this->SetFont('Times', 'B', 11);
            $this->Cell(0, 6, 'SUMMARY OF POINTS', 0, 1, 'C');
        }
        $this->Ln(1);

        // Faculty information table
        $label_w = 55;
        $data_w  = 135;
        $this->SetFont('Times', 'B', 9.5);
        $this->SetFillColor(...self::GRAY_LABELCOL);
        $this->SetDrawColor(0, 0, 0);
        $this->SetLineWidth(0.2);

        $rows = [
            ['Name of Faculty:', $this->faculty_name],
            ['Current Faculty Rank:', $this->faculty_rank],
            ['College/Department:', $this->faculty_department],
            ['Name of SUC:', $this->suc_name],
            ['Campus:', $this->faculty_campus],
        ];
        foreach ($rows as [$label, $value]) {
            $this->SetFont('Times', 'B', 9.5);
            $this->Cell($label_w, 6, $label, 1, 0, 'L', true);
            $this->SetFont('Times', '', 9.5);
            $this->Cell($data_w, 6, $value, 1, 1, 'L');
        }

        $this->Ln(3);
    }

    function Footer() {
        $this->SetY(-12);
        $this->SetFont('Times', 'I', 7);
        $this->SetTextColor(130, 130, 130);
        $this->Cell(0, 4, 'Page ' . $this->PageNo() . '/{nb}', 0, 0, 'R');
        $this->SetTextColor(0, 0, 0);
    }

    // ── Criterion band (full-width, gray) ──────────────────────────────
    function CriterionBand($text) {
        $this->CheckPageBreak(self::ROW_H);
        $this->SetFillColor(...self::GRAY_BAND);
        $this->SetTextColor(0, 0, 0);
        $this->SetFont('Times', 'B', 9.5);
        $this->SetDrawColor(0, 0, 0);
        $this->SetLineWidth(0.2);
        $total_w = self::COL_CRITERIA + self::COL_POINTS + self::COL_ALLOWABLE;
        $this->Cell($total_w, self::ROW_H, '  ' . $text, 1, 1, 'L', true);
    }

    // ── Item header row: item label | "Points" | "Allowable Points" ────
    function ItemHeaderRow($label) {
        $this->WrappedRow($label, 'Points', 'Allowable Points', self::GRAY_ITEM, true, true, 'L', 'C');
    }

    // ── A plain indicator/data row ──────────────────────────────────────
    function DataRow($label, $points, $allowable = '') {
        $pts_txt = ($points === '' || $points === null) ? '' : number_format((float)$points, 2);
        $this->WrappedRow($label, $pts_txt, $allowable, [255, 255, 255], false, false, 'L', 'R');
    }

    // ── Item subtotal row: "TOTAL POINTS" ───────────────────────────────
    function ItemTotalRow($points) {
        $this->WrappedRow('TOTAL POINTS', number_format((float)$points, 2), '', self::GRAY_SUBTOTAL, true, true, 'L', 'R');
    }

    // ── Criterion subtotal row ──────────────────────────────────────────
    function CriterionTotalRow($letter, $points, $allowable) {
        $this->WrappedRow(
            'TOTAL POINTS FOR CRITERION ' . $letter,
            number_format((float)$points, 2),
            number_format((float)$allowable, 2),
            self::GRAY_CRIT_TOTAL, true, true, 'L', 'R'
        );
    }

    // ── Grand total row ──────────────────────────────────────────────────
    function GrandTotalRow($kra_number, $grand_total, $max_points) {
        $this->WrappedRow(
            'GRAND TOTAL POINTS FOR KRA ' . $kra_number . ' (MAX - ' . $max_points . ' points)',
            number_format((float)$grand_total, 2),
            (string)$max_points,
            self::GRAY_GRAND, true, true, 'L', 'R'
        );
    }

    /**
     * Draws a 3-column bordered row whose height grows to fit wrapped text in
     * column 1, keeping all three cells the same height (classic FPDF technique).
     */
    function WrappedRow($col1, $col2, $col3, $fillColor, $bold = false, $fill = false, $col1Align = 'L', $numAlign = 'R') {
        $w1 = self::COL_CRITERIA;
        $w2 = self::COL_POINTS;
        $w3 = self::COL_ALLOWABLE;

        $font_size = $bold ? 9 : 8.5;
        $this->SetFont('Times', $bold ? 'B' : '', $font_size);

        $nb = $this->NbLines($w1 - self::LABEL_LEAD, $col1);
        $line_h = 4.0;
        $h = max(self::ROW_H, $nb * $line_h + 1.6);

        $this->CheckPageBreak($h);

        $x = $this->GetX();
        $y = $this->GetY();

        $this->SetFillColor(...$fillColor);
        $this->SetDrawColor(0, 0, 0);
        $this->SetLineWidth(0.15);

        // Column 1: wrapped text
        $this->Rect($x, $y, $w1, $h, $fill ? 'DF' : 'D');
        if (trim((string)$col1) !== '') {
            $this->SetXY($x + self::LABEL_LEAD, $y + ($h - $nb * $line_h) / 2);
            $this->MultiCell($w1 - self::LABEL_LEAD - 1, $line_h, $col1, 0, $col1Align);
        }

        // Column 2: Points
        $this->SetXY($x + $w1, $y);
        $this->SetFont('Times', $bold ? 'B' : '', $font_size);
        $this->Cell($w2, $h, $col2, 1, 0, $numAlign, $fill);

        // Column 3: Allowable Points
        $this->Cell($w3, $h, $col3, 1, 0, $numAlign, $fill);

        $this->SetXY($x, $y + $h);
    }

    /** Estimate number of wrapped lines MultiCell would need for a given width. */
    function NbLines($w, $txt) {
        $cw = &$this->CurrentFont['cw'];
        if ($w == 0) $w = $this->w - $this->rMargin - $this->x;
        $wmax = ($w - 2 * $this->cMargin) * 1000 / $this->FontSize;
        $s = str_replace("\r", '', (string)$txt);
        $nb = strlen($s);
        if ($nb > 0 && $s[$nb - 1] == "\n") $nb--;
        $sep = -1; $i = 0; $j = 0; $l = 0; $nl = 1;
        while ($i < $nb) {
            $c = $s[$i];
            if ($c == "\n") { $i++; $sep = -1; $j = $i; $l = 0; $nl++; continue; }
            if ($c == ' ') $sep = $i;
            $l += $cw[$c] ?? 600;
            if ($l > $wmax) {
                if ($sep == -1) { if ($i == $j) $i++; }
                else $i = $sep + 1;
                $sep = -1; $j = $i; $l = 0; $nl++;
            } else $i++;
        }
        return $nl;
    }

    function CheckPageBreak($h) {
        if ($this->GetY() + $h > $this->PageBreakTrigger) {
            $this->AddPage($this->CurOrientation);
        }
    }

    // ── EVALUATED BY / Conforme / Acknowledgement footer block ─────────
    function SignatureBlock($faculty_name) {
        $this->Ln(4);
        $this->CheckPageBreak(60);

        $col_w  = 60;
        $gap    = 3;
        $right_x = 138;
        $right_w = 62;
        $start_y = $this->GetY();

        $this->SetFont('Times', 'B', 9.5);
        $this->SetX(10);
        $this->Cell(0, 5, 'EVALUATED BY:', 0, 1, 'L');
        $this->Ln(1);

        $iec_y0 = $this->GetY();
        $members = 5;
        $per_row = 2;
        $block_h = 15;
        for ($i = 0; $i < $members; $i++) {
            $row = intdiv($i, $per_row);
            $col = $i % $per_row;
            $x = 10 + $col * ($col_w + $gap);
            $y = $iec_y0 + $row * ($block_h + 4);
            $this->SetXY($x, $y);
            $this->SetLineWidth(0.3);
            $this->Line($x + 5, $y + 5, $x + $col_w - 5, $y + 5);
            $this->SetXY($x, $y + 6);
            $this->SetFont('Times', 'B', 7.5);
            $this->Cell($col_w, 4, 'Name & Signature of IEC Member', 0, 1, 'C');
            $this->SetX($x);
            $this->SetFont('Times', '', 7.5);
            $this->Cell($col_w, 4, 'Date: __________________', 0, 1, 'C');
        }
        $iec_bottom = $iec_y0 + (intdiv($members - 1, $per_row) + 1) * ($block_h + 4);

        // Right column: Conforme / Acknowledgement
        $ry = $start_y;
        $this->SetXY($right_x, $ry);
        $this->SetFont('Times', 'B', 9.5);
        $this->Cell($right_w, 5, 'Conforme:', 0, 1, 'L');
        $this->SetX($right_x);
        $this->Ln(4);
        $ln_y = $this->GetY();
        $this->SetLineWidth(0.3);
        $this->Line($right_x + 4, $ln_y, $right_x + $right_w - 4, $ln_y);
        $this->SetXY($right_x, $ln_y + 1);
        $this->SetFont('Times', 'B', 9);
        $this->Cell($right_w, 4, $faculty_name, 0, 1, 'C');
        $this->SetX($right_x);
        $this->SetFont('Times', '', 8);
        $this->Cell($right_w, 4, 'Name and Signature of Faculty', 0, 1, 'C');
        $this->SetX($right_x);
        $this->Cell($right_w, 4, 'Date: __________________', 0, 1, 'C');

        $this->SetXY($right_x, $this->GetY() + 5);
        $this->SetFont('Times', 'B', 9.5);
        $this->Cell($right_w, 5, 'Acknowledgement:', 0, 1, 'L');
        $this->SetX($right_x);
        $this->Ln(4);
        $ln_y2 = $this->GetY();
        $this->Line($right_x + 4, $ln_y2, $right_x + $right_w - 4, $ln_y2);
        $this->SetXY($right_x, $ln_y2 + 1);
        $this->SetFont('Times', 'B', 9);
        $this->Cell($right_w, 4, $faculty_name, 0, 1, 'C');
        $this->SetX($right_x);
        $this->SetFont('Times', '', 8);
        $this->Cell($right_w, 4, 'Name and Signature of Faculty', 0, 1, 'C');
        $this->SetX($right_x);
        $this->Cell($right_w, 4, 'Date: __________________', 0, 1, 'C');

        $right_bottom = $this->GetY();
        $this->SetY(max($iec_bottom, $right_bottom));

        $this->Ln(4);
        $this->SetLineWidth(0.3);
        $this->Line(10, $this->GetY(), 200, $this->GetY());
        $this->Ln(2);
        $this->SetFont('Times', 'I', 7.5);
        $this->SetTextColor(90, 90, 90);
        $this->Cell(0, 4, 'CHED FACULTY POSITION RECLASSIFICATION FOR SUCs', 0, 1, 'C');
        $this->SetTextColor(0, 0, 0);
    }

    // ── Individual Summary Sheet: rank-weighted scoring & reclassification ──
    // Renders as a final, standalone page: Table 1 (weight table with the
    // applicant's current-rank tier highlighted), Table 2 (the static
    // sub-rank bracket reference table), and the nine-line breakdown block.
    // $iss is the array returned by Scoring\Orchestrator::run()['iss'] —
    // this method draws only from that array and never recomputes anything.
    function IssSummaryPage(array $iss) {
        $this->is_iss_page = true;
        $this->AddPage();
        $this->SetFont('Arial', '', 9);
        $this->Cell(0, 5, 'Generated for the current cycle - not cached from any prior computation', 0, 1, 'C');
        $this->Ln(3);

        // ── Table 1: Faculty Rank | KRA1 Pts | Weight | ... | Total Points ──
        $tiers = [
            'Instructor'                  => 'Instructor I',
            'Asst. Professor'              => 'Assistant Professor I',
            'Assoc. Professor'             => 'Associate Professor I',
            'Professor'                    => 'Professor I',
            'College/Univ. Professor'      => 'University Professor',
        ];
        $raw = $iss['kra_raw_points'];

        // Which tier row is the applicant's own base rank, for highlighting.
        $base_rank   = $iss['base_rank'];
        $active_tier = null;
        foreach ($tiers as $label => $sampleRank) {
            $w = \Scoring\Orchestrator::getKraWeights($sampleRank);
            $w_base = \Scoring\Orchestrator::getKraWeights($base_rank);
            if ($w === $w_base) { $active_tier = $label; break; }
        }

        $this->SetFont('Arial', 'B', 8);
        $this->SetFillColor(...self::GRAY_ITEM);
        $headers = ['Faculty Rank', 'KRA 1 Pts', 'Weight', 'KRA 2 Pts', 'Weight', 'KRA 3 Pts', 'Weight', 'KRA 4 Pts', 'Weight', 'Total Points'];
        $widths  = [40, 15, 13, 15, 13, 15, 13, 15, 13, 24];
        foreach ($headers as $i => $h) {
            $this->Cell($widths[$i], 8, $h, 1, 0, 'C', true);
        }
        $this->Ln();

        $this->SetFont('Arial', '', 8);
        foreach ($tiers as $label => $sampleRank) {
            $w = \Scoring\Orchestrator::getKraWeights($sampleRank);
            $total = round(
                $raw['kra1'] * $w['Instruction'] +
                $raw['kra2'] * $w['Research'] +
                $raw['kra3'] * $w['Extension'] +
                $raw['kra4'] * $w['Professional Development'],
                2
            );
            $isActive = ($label === $active_tier);
            if ($isActive) { $this->SetFillColor(...self::GRAY_BAND); $this->SetFont('Arial', 'B', 8); }
            else           { $this->SetFillColor(255, 255, 255); }

            $this->Cell($widths[0], 6, $label, 1, 0, 'L', $isActive);
            $this->Cell($widths[1], 6, number_format($raw['kra1'], 2), 1, 0, 'C', $isActive);
            $this->Cell($widths[2], 6, round($w['Instruction'] * 100) . '%', 1, 0, 'C', $isActive);
            $this->Cell($widths[3], 6, number_format($raw['kra2'], 2), 1, 0, 'C', $isActive);
            $this->Cell($widths[4], 6, round($w['Research'] * 100) . '%', 1, 0, 'C', $isActive);
            $this->Cell($widths[5], 6, number_format($raw['kra3'], 2), 1, 0, 'C', $isActive);
            $this->Cell($widths[6], 6, round($w['Extension'] * 100) . '%', 1, 0, 'C', $isActive);
            $this->Cell($widths[7], 6, number_format($raw['kra4'], 2), 1, 0, 'C', $isActive);
            $this->Cell($widths[8], 6, round($w['Professional Development'] * 100) . '%', 1, 0, 'C', $isActive);
            $this->Cell($widths[9], 6, number_format($total, 2), 1, 1, 'C', $isActive);

            if ($isActive) $this->SetFont('Arial', '', 8);
        }
        $this->Ln(6);

        // ── Table 2: static Score Bracket reference table ──────────────────
        $this->SetFont('Arial', 'B', 8);
        $this->SetFillColor(...self::GRAY_ITEM);
        $this->Cell(60, 7, 'Score Bracket', 1, 0, 'C', true);
        $this->Cell(60, 7, 'No. of Sub-rank Increment', 1, 1, 'C', true);
        $this->SetFont('Arial', '', 8);
        $brackets = [
            '41-50' => '1 sub-rank', '51-60' => '2 sub-ranks', '61-70' => '3 sub-ranks',
            '71-80' => '4 sub-ranks', '81-90' => '5 sub-ranks', '91-100' => '6 sub-ranks',
        ];
        foreach ($brackets as $range => $label) {
            $this->Cell(60, 6, $range, 1, 0, 'C');
            $this->Cell(60, 6, $label, 1, 1, 'C');
        }
        $this->Ln(6);

        // ── Breakdown block ──────────────────────────────────────────────
        $rows = [
            ['Current Faculty Rank', $iss['base_rank']],
            ['Qualified for Auto. 1-Sub Rank (for PhD)?', $iss['qualified_auto_subrank_phd'] ? 'YES' : 'NO'],
            ['Base Rank', $iss['base_rank']],
            ['No. of Sub-Rank Increment based on Score', (string)$iss['sub_rank_increment_pass1']],
            ['Initial Reclassified Rank', $iss['initial_reclassified_rank']],
            ['No. of Sub-Rank Increment based on Recomputed Score', (string)$iss['sub_rank_increment_pass2']],
            ['Reclassified Rank', $iss['reclassified_rank']],
            ['Qualified for Auto. 1-Sub Rank (for Awards)?', $iss['qualified_auto_subrank_award'] ? 'YES' : 'NO'],
            ['Final Rank (Score-Based Increment + Auto Sub Rank combined)', $iss['final_rank']],
        ];
        $this->SetFont('Arial', '', 8);
        foreach ($rows as [$label, $value]) {
            $this->CheckPageBreak(7);
            $this->SetFillColor(...self::GRAY_LABELCOL);
            $this->Cell(120, 6, $label, 1, 0, 'R', true);
            $this->SetFont('Arial', 'B', 8);
            $this->Cell(64, 6, $value, 1, 1, 'C');
            $this->SetFont('Arial', '', 8);
        }
    }
}

// ═══════════════════════════════════════════════════════════════════════════════
// MAIN RENDER ENTRY POINT
// ═══════════════════════════════════════════════════════════════════════════════

/**
 * @param array    $faculty        ['full_name','rank','department'|'college','campus_name']
 * @param array    $subs_by_cat    kra_category => [ {remarks, computed_points}, ... ]
 * @param string[] $kras_to_print  e.g. ['I','II','III','IV'] or a single one
 * @param string   $suc_name       University name for the "Name of SUC" row
 * @return KRA_PDF  (caller decides Output mode/filename)
 */
function renderKraPdf(array $faculty, array $subs_by_cat, array $kras_to_print, string $suc_name = 'Carlos Hilado Memorial State University', ?array $iss = null): KRA_PDF {
    $definitions = kraDefinitions();

    $pdf = new KRA_PDF('P', 'mm', 'A4');
    $pdf->AliasNbPages();
    $pdf->faculty_name       = $faculty['full_name'] ?? 'N/A';
    $pdf->faculty_rank       = $faculty['rank'] ?? 'N/A';
    $pdf->faculty_department = $faculty['department'] ?? ($faculty['college'] ?? '');
    $pdf->suc_name           = $suc_name;
    $pdf->faculty_campus     = $faculty['campus_name'] ?? 'N/A';

    $pdf->SetMargins(10, 78, 10);
    $pdf->SetAutoPageBreak(true, 16);
    $pdf->SetDrawColor(0, 0, 0);
    $pdf->SetLineWidth(0.2);

    foreach ($kras_to_print as $num) {
        if (!isset($definitions[$num])) continue;
        $def = $definitions[$num];
        $entries = $subs_by_cat[$def['category']] ?? [];

        $pdf->kra_number = $def['number'];
        $pdf->kra_label  = $def['label'];
        $pdf->AddPage();

        $grand_total = 0.0;
        $buckets = kraBucketEntries($def, $entries);

        foreach ($def['criteria'] as $letter => $criterion) {
            $pdf->CriterionBand($criterion['title']);

            $item_entries = $buckets[$letter];
            $multi_item = count($criterion['items']) > 1;
            $criterion_raw = 0.0;

            foreach ($criterion['items'] as $idx => $item) {
                $pdf->ItemHeaderRow($item['label']);
                $entries_here = $item_entries[$idx];

                if (!empty($item['subgroups'])) {
                    $item_total = 0.0;
                    foreach ($item['subgroups'] as $sg) {
                        $sub_sum = 0.0;
                        foreach ($entries_here as $e) {
                            if ($sg['test'](kraEntrySubVal($e))) $sub_sum += (float)$e['computed_points'];
                        }
                        $pdf->DataRow($sg['label'], $sub_sum);
                        $item_total += $sub_sum;
                    }
                } else {
                    $item_total = array_sum(array_column($entries_here, 'computed_points'));
                    $pdf->DataRow('', $item_total);
                }

                if ($multi_item) $pdf->ItemTotalRow($item_total);
                $criterion_raw += $item_total;
            }

            $criterion_capped = min($criterion['max'], $criterion_raw);
            $pdf->CriterionTotalRow($letter, $criterion_raw, $criterion_capped);
            $grand_total += $criterion_capped;
        }

        $grand_total_capped = min($def['max_points'], $grand_total);
        $pdf->Ln(2);
        $pdf->GrandTotalRow($def['number'], $grand_total_capped, $def['max_points']);

        $pdf->SignatureBlock($pdf->faculty_name);
    }

    // Rank-weighted scoring / reclassification summary — only when the caller
    // supplied fresh ISS data (the full multi-KRA report), never for a
    // single-KRA-only print.
    if ($iss !== null) {
        $pdf->IssSummaryPage($iss);
    }

    return $pdf;
}
