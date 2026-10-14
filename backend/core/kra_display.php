<?php
// Human-readable KRA titles and remarks (used by views and PDF reports).

// -- UI helpers ------------------------------------------------

/**
 * Parse the |||-delimited remarks string into a human-readable HTML snippet.
 * Format per KRA category:
 *   Instruction:              SET%  ||| SEF%  ||| notes
 *   Research:                 Type  ||| Developers ||| Affiliation ||| Area ||| Specific Contribution ||| Contribution%
 *   Extension:                Subtype ||| Title ||| Val1 ||| Val2
 *   Professional Development: Credential ||| Degree value
 */
function kraInstructionDisplayTitle(string $critType, string $storedLabel): string {
    $raw = $storedLabel !== '' ? $storedLabel : implode('|', array_slice(explode('|', $critType), 2));
    $label = kraCleanEntryDisplayTitle(str_replace('\\u2014', ' - ', $raw));
    $norm = strtolower(preg_replace('/[^a-z0-9]+/', ' ', $label));
    $isCo = str_contains($norm, 'co author') || str_contains($norm, 'coauthor');

    if (str_starts_with($critType, 'B|') || $critType === 'B-material') {
        if (str_contains($norm, 'textbook chapter')) return 'Textbook Chapter, ' . ($isCo ? 'Co-Author' : 'Sole Author');
        if (str_contains($norm, 'manual') || str_contains($norm, 'module')) return 'Manual / Module, ' . ($isCo ? 'Co-Author' : 'Sole Author');
        if (str_contains($norm, 'multimedia')) return 'Multimedia Teaching Materials';
        if (str_contains($norm, 'testing') || str_contains($norm, 'validated')) return 'Validated Testing Materials';
        if (str_contains($norm, 'program')) return 'Academic Program Dev/Revision, ' . (str_contains($norm, 'contributor') ? 'Contributor' : 'Lead');
        if (str_contains($norm, 'textbook')) return 'Textbook, ' . ($isCo ? 'Co-Author' : 'Sole Author');
    }

    if (str_starts_with($critType, 'C|') || $critType === 'C-thesis') {
        if (str_contains($norm, 'special') || str_contains($norm, 'capstone')) $level = 'Special Project / Capstone';
        elseif (str_contains($norm, 'undergraduate')) $level = 'Undergraduate Thesis';
        elseif (str_contains($norm, 'master')) $level = "Master's Thesis";
        elseif (str_contains($norm, 'doctor')) $level = 'Doctoral Dissertation';
        else $level = '';

        if (str_contains($norm, 'panel')) return trim('Panel Member: ' . $level);
        if (str_contains($norm, 'mentor')) return 'Mentor: Student/Team Competition Winner';
        if ($level !== '') return 'Adviser: ' . $level;
    }

    return $label;
}

function kraExtensionSubtypeDisplayTitle(string $subtype): string {
    return [
        'moa-linkage' => 'MOA/Linkage Partnership',
        'income' => 'Income Generation',
        'accredit-local' => 'Local Accreditation/QA Service',
        'accredit-intl' => 'International Accreditation/QA Service',
        'judge-research' => 'Judging Research Awards',
        'judge-other' => 'Judging Academic Competitions',
        'consultant-local' => 'Local Consultant/Expert Service',
        'consultant-intl' => 'International Consultant/Expert Service',
        'media-column-occasional' => 'Occasional Newspaper/Online Column',
        'media-column-regular' => 'Regular Column',
        'media-tv-radio-host' => 'Hosting TV/Radio/Online Program',
        'media-guest' => 'Guesting as Technical Expert',
        'resource-speaker-local' => 'Resource Person/Speaker, Local',
        'resource-speaker-intl' => 'Resource Person/Speaker, International',
        'outreach-isr-lead' => 'Head of Outreach/Extension Activity',
        'outreach-isr-member' => 'Participant in Outreach/Extension Activity',
        'csr-satisfaction' => 'Client Satisfaction Rating',
    ][$subtype] ?? ucwords(str_replace('-', ' ', $subtype));
}

function kraProfessionalDevelopmentDisplayTitle(string $critType, string $subVal): string {
    if ($critType === 'A-org') return 'Professional Org Membership';
    if ($critType === 'B-training') return $subVal === '2' ? 'Training/Conference, International' : 'Training/Conference, Local';
    if ($critType === 'B-paper') return $subVal === '5' ? 'Paper Presentation, International' : 'Paper Presentation, Local';
    if ($critType === 'B-degree') {
        return match($subVal) {
            '40' => 'Doctorate / Additional Doctorate',
            '20' => "Additional Master's Degree",
            '10' => "Post-Master's / Post-Doctoral Certificate",
            default => 'Educational Qualification',
        };
    }
    if ($critType === 'C-award') {
        return match($subVal) {
            '2' => 'Award / Recognition, Institutional',
            '3' => 'Award / Recognition, Local / Regional',
            '4' => 'Award / Recognition, National',
            '5' => 'Award / Recognition, International (CONFIG GAP)',
            '0' => 'Award / Recognition, Prestigious (Sub-rank Trigger)',
            default => 'Award / Recognition',
        };
    }
    return kraCleanEntryDisplayTitle($critType);
}

function formatKraRemarks(string $category, string $remarks): string {
    $p = array_map('trim', explode('|||', $remarks));
    $numbering = kraNumberingFindByStoredType($category, $p[0] ?? '', $p);
    $numberedLabel = $numbering ? kraNumberingDisplayLabel($numbering) : '';

    switch ($category) {
        case 'Instruction':
            $critType = $p[0] ?? '';
            if ($critType === 'CUSTOM') {
                $label = $p[3] ?? 'Custom criterion';
                $pts = number_format((float)($p[4] ?? 0), 2);
                $detail = $p[5] ?? '';
                $out = '<div><strong>' . htmlspecialchars($label, ENT_QUOTES) . '</strong> <span class="text-muted" style="font-size:0.75rem;">(' . $pts . ' pts)</span></div>';
                if ($detail !== '') $out .= '<div class="text-muted" style="font-size:0.82rem;">' . htmlspecialchars($detail, ENT_QUOTES) . '</div>';
                return $out;
            }
            $d1       = $p[1] ?? '';
            $d2       = $p[2] ?? '';
            $notes    = $p[3] ?? '';
            if ($critType === 'A-set-sef') {
                $parts = [];
                $parts[] = '<span class="text-muted" style="font-size:0.75rem;">1.1 Student Evaluation (60%)</span> <strong>' . htmlspecialchars($d1, ENT_QUOTES) . '%</strong>';
                $parts[] = '<span class="text-muted" style="font-size:0.75rem;">1.2 Supervisor\'s Evaluation (40%)</span> <strong>' . htmlspecialchars($d2, ENT_QUOTES) . '%</strong>';
                $parts = array_filter($parts, fn($part) => !str_contains($part, '<strong>%</strong>'));
                if ($notes !== '') $parts[] = '<span class="text-muted" style="font-size:0.75rem;">Notes:</span> ' . htmlspecialchars($notes, ENT_QUOTES);
                return implode(' &nbsp;|&nbsp; ', $parts) ?: '-';
            } elseif ($critType === 'A-set-sef-sem') {
                $period = $d1;
                $sem    = $d2 === '2' ? '2nd Semester' : '1st Semester';
                $set    = $p[3] ?? '';
                $sef    = $p[4] ?? '';
                $parts = [];
                if ($period !== '') $parts[] = '<strong>' . htmlspecialchars($period, ENT_QUOTES) . '</strong>';
                $parts[] = '<span class="text-muted" style="font-size:0.75rem;">' . $sem . '</span>';
                if ($set !== '') $parts[] = '<span class="text-muted" style="font-size:0.75rem;">1.1 Student Evaluation (60%)</span> <strong>' . htmlspecialchars($set, ENT_QUOTES) . '%</strong>';
                if ($sef !== '') $parts[] = '<span class="text-muted" style="font-size:0.75rem;">1.2 Supervisor\'s Evaluation (40%)</span> <strong>' . htmlspecialchars($sef, ENT_QUOTES) . '%</strong>';
                return implode(' &nbsp;|&nbsp; ', $parts) ?: '-';
            } elseif ($critType === 'A-set-sem' || $critType === 'A-sef-sem') {
                $period = $d1;
                $sem    = $d2 === '2' ? '2nd Semester' : '1st Semester';
                $label  = $numberedLabel !== '' ? $numberedLabel : ($critType === 'A-sef-sem' ? 'SEF' : 'SET');
                $score  = $p[3] ?? '';
                $parts = [];
                if ($period !== '') $parts[] = '<strong>' . htmlspecialchars($period, ENT_QUOTES) . '</strong>';
                $parts[] = '<span class="text-muted" style="font-size:0.75rem;">' . $sem . '</span>';
                if ($score !== '') $parts[] = '<span class="text-muted" style="font-size:0.75rem;">' . htmlspecialchars($label, ENT_QUOTES) . '</span> <strong>' . htmlspecialchars($score, ENT_QUOTES) . '%</strong>';
                return implode(' &nbsp;|&nbsp; ', $parts) ?: '-';
            } elseif (str_starts_with($critType, 'B|')) {
                $label = kraInstructionDisplayTitle($critType, $d1);
                $out = '<div><strong>' . htmlspecialchars($label, ENT_QUOTES) . '</strong></div>';
                if ($d2 !== '' && $d2 !== '100') $out .= '<div class="text-muted" style="font-size:0.78rem;">Contribution: ' . htmlspecialchars($d2, ENT_QUOTES) . '%</div>';
                return $out;
            } elseif (str_starts_with($critType, 'C|')) {
                $label = kraInstructionDisplayTitle($critType, $d1);
                return '<div><strong>' . htmlspecialchars($label, ENT_QUOTES) . '</strong></div>';
            } elseif ($critType === 'B-material') {
                $out = '<div><strong>' . htmlspecialchars(kraInstructionDisplayTitle($critType, $d1), ENT_QUOTES) . '</strong></div>';
                if ($d2 !== '' && $d2 !== '100') $out .= '<div class="text-muted" style="font-size:0.78rem;">Contribution: ' . htmlspecialchars($d2, ENT_QUOTES) . '%</div>';
                return $out;
            } elseif ($critType === 'C-thesis') {
                return '<div><strong>' . htmlspecialchars(kraInstructionDisplayTitle($critType, $d1), ENT_QUOTES) . '</strong></div>';
            } elseif ($critType === 'C-mentor') {
                $competition = $d1 ?: 'Mentorship';
                $level       = $d2 ? htmlspecialchars($d2, ENT_QUOTES) : '';
                $placement   = $notes ? htmlspecialchars($notes, ENT_QUOTES) : '';
                $out = '<div><strong>Mentor: Student/Team Competition Winner</strong></div>';
                if ($competition !== 'Mentorship') $out .= '<div>' . htmlspecialchars($competition, ENT_QUOTES) . '</div>';
                $meta = array_filter([$level, $placement]);
                if ($meta) $out .= '<div class="text-muted" style="font-size:0.76rem;">' . implode(' &middot; ', $meta) . '</div>';
                $out .= '<div style="font-size:0.72rem;color:#f59e0b;font-weight:600;"><i class="bi bi-exclamation-triangle me-1"></i>PENDING  -  CONFIG_MENTORSHIP_POINTS not confirmed (JC01 p.78 source gap)</div>';
                return $out;
            } else {
                // Legacy format: SET%|||SEF%|||notes
                $set   = $p[0] ?? '';
                $sef   = $p[1] ?? '';
                $notes = $p[2] ?? '';
                $parts = [];
                if ($set  !== '') $parts[] = '<span class="text-muted" style="font-size:0.75rem;">SET</span> <strong>' . htmlspecialchars($set, ENT_QUOTES) . '%</strong>';
                if ($sef  !== '') $parts[] = '<span class="text-muted" style="font-size:0.75rem;">SEF</span> <strong>' . htmlspecialchars($sef, ENT_QUOTES) . '%</strong>';
                if ($notes !== '') $parts[] = '<span class="text-muted" style="font-size:0.75rem;">Notes:</span> ' . htmlspecialchars($notes, ENT_QUOTES);
                return implode(' &nbsp;|&nbsp; ', $parts) ?: '-';
            }

        case 'Research':
            if (($p[0] ?? '') === 'CUSTOM') {
                $label = $p[3] ?? 'Custom criterion';
                $pts = number_format((float)($p[4] ?? 0), 2);
                $detail = $p[5] ?? '';
                $out = '<div><strong>' . htmlspecialchars($label, ENT_QUOTES) . '</strong> <span class="text-muted" style="font-size:0.75rem;">(' . $pts . ' pts)</span></div>';
                if ($detail !== '') $out .= '<div class="text-muted" style="font-size:0.82rem;">' . htmlspecialchars($detail, ENT_QUOTES) . '</div>';
                return $out;
            }
            $type    = $p[0] ?? '';
            $dev     = $p[1] ?? '';
            $aff     = $p[2] ?? '';
            $area    = $p[3] ?? '';
            $spec    = $p[4] ?? '';
            $contrib = $p[5] ?? '';
            $out = '';
            if ($type  !== '') $out .= '<div><strong>' . htmlspecialchars(kraCleanEntryDisplayTitle($type), ENT_QUOTES) . '</strong></div>';
            if ($dev   !== '') $out .= '<div class="text-muted" style="font-size:0.82rem;"><span class="text-muted" style="font-size:0.72rem;">Developers:</span> ' . htmlspecialchars($dev, ENT_QUOTES) . '</div>';
            if ($aff   !== '') $out .= '<div class="text-muted" style="font-size:0.78rem;"><span class="text-muted" style="font-size:0.72rem;">Affiliation:</span> ' . htmlspecialchars($aff, ENT_QUOTES) . '</div>';
            if ($area  !== '') $out .= '<div class="text-muted" style="font-size:0.78rem;"><span class="text-muted" style="font-size:0.72rem;">Area:</span> ' . htmlspecialchars($area, ENT_QUOTES) . '</div>';
            if ($spec  !== '') $out .= '<div class="text-muted" style="font-size:0.78rem;"><span class="text-muted" style="font-size:0.72rem;">Specific Contribution:</span> ' . htmlspecialchars($spec, ENT_QUOTES) . '</div>';
            if ($contrib !== '') $out .= '<div style="font-size:0.78rem;"><span class="text-muted">Contribution:</span> <strong>' . htmlspecialchars($contrib, ENT_QUOTES) . '%</strong></div>';
            return $out ?: '-';

        case 'Extension':
            if (($p[0] ?? '') === 'CUSTOM') {
                $label = $p[3] ?? 'Custom criterion';
                $pts = number_format((float)($p[4] ?? 0), 2);
                $detail = $p[5] ?? '';
                $out = '<div><strong>' . htmlspecialchars($label, ENT_QUOTES) . '</strong> <span class="text-muted" style="font-size:0.75rem;">(' . $pts . ' pts)</span></div>';
                if ($detail !== '') $out .= '<div class="text-muted" style="font-size:0.82rem;">' . htmlspecialchars($detail, ENT_QUOTES) . '</div>';
                return $out;
            }
            $subtype = $p[0] ?? '';
            $title   = $p[1] ?? '';
            $val1    = $p[2] ?? '';
            $val2    = $p[3] ?? '';
            $out = '';
            if ($subtype !== '') $out .= '<div><strong>' . htmlspecialchars(kraExtensionSubtypeDisplayTitle($subtype), ENT_QUOTES) . '</strong></div>';
            if ($title   !== '') $out .= '<div><strong>' . htmlspecialchars($title, ENT_QUOTES) . '</strong></div>';
            $meta = [];
            if ($val1 !== '') $meta[] = '<span class="text-muted" style="font-size:0.78rem;">' . htmlspecialchars($val1, ENT_QUOTES) . '</span>';
            if ($val2 !== '') $meta[] = '<span class="text-muted" style="font-size:0.78rem;">' . htmlspecialchars($val2, ENT_QUOTES) . '</span>';
            if ($meta) $out .= '<div>' . implode(' &nbsp;&middot;&nbsp; ', $meta) . '</div>';
            return $out ?: '-';

        case 'Professional Development':
            if (($p[0] ?? '') === 'CUSTOM') {
                $label = $p[3] ?? 'Custom criterion';
                $pts = number_format((float)($p[4] ?? 0), 2);
                $detail = $p[5] ?? '';
                $out = '<div><strong>' . htmlspecialchars($label, ENT_QUOTES) . '</strong> <span class="text-muted" style="font-size:0.75rem;">(' . $pts . ' pts)</span></div>';
                if ($detail !== '') $out .= '<div class="text-muted" style="font-size:0.82rem;">' . htmlspecialchars($detail, ENT_QUOTES) . '</div>';
                return $out;
            }
            $critType = $p[0] ?? '';
            $desc     = $p[1] ?? '';
            $subVal   = $p[2] ?? '';

            // New format: criterion_type|||description|||sub_value
            $critLabels = [
                'A-org'      => ['Crit. A', 'secondary', 'Professional Org'],
                'B-training' => ['Crit. B', 'primary',   'Training/Conference'],
                'B-paper'    => ['Crit. B', 'primary',   'Paper Presentation'],
                'B-degree'   => ['Crit. B', 'primary',   'Educational Qualification'],
                'C-award'    => ['Crit. C', 'success',   'Award/Recognition'],
            ];
            if (isset($critLabels[$critType])) {
                $out = '<div><strong>' . htmlspecialchars(kraProfessionalDevelopmentDisplayTitle($critType, $subVal), ENT_QUOTES) . '</strong></div>';
                if ($desc !== '') $out .= '<div class="text-muted" style="font-size:0.82rem;">' . htmlspecialchars($desc, ENT_QUOTES) . '</div>';
                return $out;
            }
            // Legacy format: credential|||degree_value
            $cred   = $p[0] ?? '';
            $degree = $p[1] ?? '';
            $out = '';
            if ($cred !== '') $out .= '<div><strong>' . htmlspecialchars($cred, ENT_QUOTES) . '</strong></div>';
            if ($degree !== '' && $degree !== '0') {
                $degLabel = match($degree) {
                    '40' => 'Doctorate',
                    '20' => "Master's Degree",
                    '10' => 'Post-Doctoral',
                    default => ''
                };
                if ($degLabel) $out .= '<div><span class="badge bg-info" style="font-size:0.72rem;">' . $degLabel . '</span></div>';
            }
            return $out ?: '-';

        default:
            // Fallback: just show first segment
            return htmlspecialchars($p[0] ?? $remarks, ENT_QUOTES) ?: '-';
    }
}
