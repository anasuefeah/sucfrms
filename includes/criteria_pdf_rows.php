<?php

function kraPdfText(string $text): string
{
    return iconv('UTF-8', 'windows-1252//TRANSLIT//IGNORE', $text) ?: '';
}

function criteriaPdfLineCount(FPDF $pdf, float $width, string $text): int
{
    $limit = $width - 2.01;
    $lines = 0;
    foreach (explode("\n", rtrim(str_replace("\r", '', $text), "\n")) as $paragraph) {
        $lines++;
        $line = '';
        foreach (explode(' ', $paragraph) as $word) {
            $candidate = $line === '' ? $word : "$line $word";
            if ($pdf->GetStringWidth($candidate) <= $limit) {
                $line = $candidate;
                continue;
            }
            if ($line !== '') $lines++;
            $line = '';
            foreach (str_split($word) as $character) {
                if ($line !== '' && $pdf->GetStringWidth($line . $character) > $limit) {
                    $lines++;
                    $line = '';
                }
                $line .= $character;
            }
        }
    }
    return $lines;
}

/** Leave room above the existing footer before starting each criterion row. */
function criteriaPdfRow(FPDF $pdf, string $text, float $height, float $footerMargin, int $border = 0, ?string $points = null): void
{
    $label = kraPdfText($text);
    $rowHeight = $height * criteriaPdfLineCount($pdf, $points === null ? 186 : 140, $label);
    if ($pdf->GetY() + $rowHeight > $pdf->GetPageHeight() - $footerMargin) $pdf->AddPage();
    if ($points === null) {
        $pdf->MultiCell(0, $height, $label, $border, 'L');
        return;
    }
    $x = $pdf->GetX();
    $y = $pdf->GetY();
    $pdf->SetX($x + 140);
    $pdf->Cell(50, $height, kraPdfText($points), $border, 0, 'R');
    $pdf->SetXY($x, $y);
    $pdf->MultiCell(140, $height, $label, $border, 'L');
}
