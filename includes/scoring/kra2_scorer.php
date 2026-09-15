<?php
/**
 * KRA II Scorer — Research, Invention & Creative Work (100 points)
 * DBM-CHED Joint Circular No. 01, s. 2026
 *
 * Structure:
 *   Criterion A – Research Outputs:     internally scoreable up to 100
 *   Criterion B – Inventions:           internally scoreable up to 100
 *   Criterion C – Creative Works:       internally scoreable up to 100
 *   KRA II total = A + B + C summed FIRST, then capped at 100.
 *   (Do NOT cap A, B, C individually — sum then cap.)
 */

namespace Scoring;

class KRA2Scorer
{
    const CAP = 100;

    // ACI indexing: accepted only through last issue of 2024; no ACI from Jan 1, 2025
    const ACI_CUTOFF = '2024-12-31';

    // Research output base points (sole-author; co-author applies declared % against this)
    const RESEARCH_POINTS = [
        'book_sole'             => 100,
        'book_co'               => 100,
        'monograph_sole'        => 100,
        'monograph_co'          => 100,
        'journal_indexed_sole'  => 50,
        'journal_indexed_co'    => 50,
        'book_chapter_sole'     => 35,
        'book_chapter_co'       => 35,
        'policy_lead'           => 35,   // Research → Project/Policy/Product (max 2 instances = 70)
        'policy_contrib'        => 35,
        'citation_local'        => 10,   // per cited article, max 6 = 60
        'citation_intl'         => 10,
    ];

    // Invention point values (by highest stage reached — not cumulative)
    const INVENTION_POINTS = [
        'patent_acceptance'     => 10,
        'patent_publication'    => 20,
        'patent_grant'          => 80,
        'utility_model'         => 10,
        'industrial_design'     => 5,
        'commercialized_local'  => 5,   // max 20 (4 instances)
        'commercialized_intl'   => 10,  // max 30 (3 instances)
        'software_new'          => 10,
        'software_updated'      => 4,
        'plant_variety'         => 10,
    ];

    // Creative works point values
    const CREATIVE_POINTS = [
        'music_composition'     => 20,
        'music_arrangement'     => 20,
        'music_performance'     => 10,
        'music_conducting'      => 10,
        'music_production'      => 20,
        'dance_choreography'    => 20,
        'dance_performance'     => 10,
        'theatre_playwright'    => 20,
        'theatre_directing'     => 20,
        'theatre_acting'        => 20,
        'theatre_production'    => 20,
        'visual_arts'           => 20,  // one shared rate across painting/drawing/sculpture/photography/digital/arch/eng/interior
        'film'                  => 20,  // short/feature/documentary/experimental/animated
        'novel'                 => 20,
        'short_story'           => 10,
        'poetry'                => 2,
        'literary_essay'        => 2,
    ];

    public static function score(array $submissions): array
    {
        $crit_a_raw = 0.0;
        $crit_b_raw = 0.0;
        $crit_c_raw = 0.0;
        $pending    = [];
        $config_i   = [];

        // Track caps within Criterion A sub-items
        $policy_count   = 0;  // max 2 instances for policy output
        $citation_count = 0;  // max 6 citations

        foreach ($submissions as $s) {
            $parts    = array_map('trim', explode('|||', $s['remarks'] ?? ''));
            $label    = $parts[0] ?? '';
            $title    = $parts[1] ?? '';
            $contrib  = min(100, max(1, (float)($parts[2] ?? 100)));
            $pts      = 0.0;

            // ── Criterion A: Research Outputs ────────────────────────
            if (self::isResearchOutput($label)) {
                $base = self::resolveResearchBase($label, $config_i);
                if ($base !== null) {
                    $isCo = self::isCoAuthor($label);
                    $pts  = round($isCo ? $base * ($contrib / 100) : (float)$base, 2);

                    // Policy/product output cap: max 2 instances
                    if (self::isPolicyOutput($label)) {
                        $policy_count++;
                        if ($policy_count > 2) {
                            $config_i[] = "KRA II Crit A: Policy/product output cap reached (max 2 instances = 70 pts). '{$title}' excluded.";
                            $pts = 0.0;
                        }
                    }
                    // Citation cap: max 6 citations (60 pts) — self-citations excluded
                    if (self::isCitation($label)) {
                        $citation_count++;
                        if ($citation_count > 6) {
                            $config_i[] = "KRA II Crit A: Citation cap reached (max 6 citations). '{$title}' excluded.";
                            $pts = 0.0;
                        }
                    }

                    // Indexing validation
                    if (self::requiresIndexing($label)) {
                        if (empty($s['evidence_names'])) {
                            $pending[] = "KRA II Crit A: '{$title}' — missing research director certification of indexing status at time of publication (Scopus/WoS/ACI required).";
                        }
                    }

                    if ($isCo && empty($parts[2])) {
                        $pending[] = "KRA II Crit A: '{$title}' — co-author entry missing Annex D (Certificate of Contribution Form_Research Output).";
                    }
                }
                $crit_a_raw += $pts;
            }
            // ── Criterion B: Inventions ──────────────────────────────
            elseif (self::isInvention($label)) {
                $base = self::resolveInventionBase($label, $config_i);
                if ($base !== null) {
                    $isCo = self::isCoInventor($label);
                    $pts  = round($isCo ? $base * ($contrib / 100) : (float)$base, 2);
                    if ($isCo && empty($parts[2])) {
                        $pending[] = "KRA II Crit B: '{$title}' — co-inventor missing Annex F/G/H (Certificate of Contribution Form_IP).";
                    }
                    if (empty($s['evidence_names'])) {
                        $pending[] = "KRA II Crit B: '{$title}' — missing IPOPHL certification / patent certificate.";
                    }
                }
                $crit_b_raw += $pts;
            }
            // ── Criterion C: Creative Works ──────────────────────────
            elseif (self::isCreativeWork($label)) {
                $base = self::resolveCreativeBase($label, $config_i);
                if ($base !== null) {
                    $isCo = self::isCoAuthor($label) || self::isCoCreator($label);
                    $pts  = round($isCo ? $base * ($contrib / 100) : (float)$base, 2);
                    if ($isCo && empty($parts[2])) {
                        $pending[] = "KRA II Crit C: '{$title}' — co-creator missing Annex I (Certificate of Contribution Form_Creative Works).";
                    }
                    if (empty($s['evidence_names'])) {
                        $pending[] = "KRA II Crit C: '{$title}' — missing copyright certificate / invitation letter / evidence of performance.";
                    }
                }
                $crit_c_raw += $pts;
            }
            // Legacy / unrecognised entry — try to extract pts from label pattern
            else {
                if (preg_match('/\((\d+(?:\.\d+)?)\s*pts?\)/i', $label, $m)) {
                    $base = (float)$m[1];
                    $pts  = round($base * ($contrib / 100), 2);
                    $crit_a_raw += $pts;
                } else {
                    $config_i[] = "KRA II: Unrecognised entry '{$label}' — CONFIG_INCOMPLETE.";
                }
            }
        }

        // Sum THEN cap (not individually)
        $sum_before_cap = $crit_a_raw + $crit_b_raw + $crit_c_raw;
        $subtotal       = min(self::CAP, $sum_before_cap);

        return [
            'criterion_a_raw'       => round($crit_a_raw, 2),
            'criterion_b_raw'       => round($crit_b_raw, 2),
            'criterion_c_raw'       => round($crit_c_raw, 2),
            'sum_before_cap'        => round($sum_before_cap, 2),
            'subtotal'              => round($subtotal, 2),
            'cap'                   => self::CAP,
            'pending_documentation' => $pending,
            'config_incomplete'     => $config_i,
        ];
    }

    // ── Category detectors ───────────────────────────────────────────
    private static function isResearchOutput(string $label): bool
    {
        $l = strtolower($label);
        return str_contains($l, 'book') || str_contains($l, 'monograph')
            || str_contains($l, 'journal') || str_contains($l, 'article')
            || str_contains($l, 'chapter') || str_contains($l, 'policy')
            || str_contains($l, 'project') || str_contains($l, 'product')
            || str_contains($l, 'citation') || str_contains($l, 'scholar')
            || str_contains($l, 'crit a') || str_contains($l, 'research output')
            || preg_match('/kra2_a_/', $l);
    }

    private static function isInvention(string $label): bool
    {
        $l = strtolower($label);
        return str_contains($l, 'patent') || str_contains($l, 'invention')
            || str_contains($l, 'utility model') || str_contains($l, 'industrial design')
            || str_contains($l, 'software') || str_contains($l, 'plant variety')
            || str_contains($l, 'animal breed') || str_contains($l, 'microbial')
            || str_contains($l, 'commerciali') || str_contains($l, 'crit b')
            || preg_match('/kra2_b_/', $l);
    }

    private static function isCreativeWork(string $label): bool
    {
        $l = strtolower($label);
        return str_contains($l, 'music') || str_contains($l, 'composition')
            || str_contains($l, 'arrangement') || str_contains($l, 'performance')
            || str_contains($l, 'conducting') || str_contains($l, 'dance')
            || str_contains($l, 'choreograph') || str_contains($l, 'theatre')
            || str_contains($l, 'theater') || str_contains($l, 'playwright')
            || str_contains($l, 'visual art') || str_contains($l, 'film')
            || str_contains($l, 'novel') || str_contains($l, 'short story')
            || str_contains($l, 'poetry') || str_contains($l, 'literary')
            || str_contains($l, 'exhibition') || str_contains($l, 'creative')
            || str_contains($l, 'crit c') || preg_match('/kra2_c_/', $l);
    }

    private static function isPolicyOutput(string $label): bool
    {
        $l = strtolower($label);
        return str_contains($l, 'policy') || str_contains($l, 'product') || str_contains($l, 'project output');
    }

    private static function isCitation(string $label): bool
    {
        return stripos($label, 'citation') !== false;
    }

    private static function requiresIndexing(string $label): bool
    {
        $l = strtolower($label);
        return str_contains($l, 'journal') || str_contains($l, 'article') || str_contains($l, 'conference');
    }

    private static function isCoAuthor(string $label): bool
    {
        $l = strtolower($label);
        return str_contains($l, 'co-author') || str_contains($l, 'co author')
            || str_contains($l, 'coauthor') || str_contains($l, ', co');
    }

    private static function isCoInventor(string $label): bool
    {
        $l = strtolower($label);
        return str_contains($l, 'co-inventor') || str_contains($l, 'co inventor')
            || str_contains($l, 'co-developer') || str_contains($l, 'co developer');
    }

    private static function isCoCreator(string $label): bool
    {
        $l = strtolower($label);
        return str_contains($l, 'co-arranger') || str_contains($l, 'co-choreographer')
            || str_contains($l, 'co-producer') || str_contains($l, 'co-composer');
    }

    // ── Base point resolvers ─────────────────────────────────────────
    private static function resolveResearchBase(string $label, array &$config_i): ?float
    {
        $l = strtolower($label);
        if (str_contains($l, 'book') && !str_contains($l, 'chapter'))          return 100.0;
        if (str_contains($l, 'monograph'))                                       return 100.0;
        if (str_contains($l, 'journal') || str_contains($l, 'article'))         return 50.0;
        if (str_contains($l, 'chapter') && str_contains($l, 'book'))            return 35.0;
        if (str_contains($l, 'policy') || str_contains($l, 'project output') || str_contains($l, 'product')) return 35.0;
        if (str_contains($l, 'citation'))                                        return 10.0;
        if (str_contains($l, 'scholar'))                                         return 10.0;
        // Extract from label pattern
        if (preg_match('/\((\d+(?:\.\d+)?)\s*pts?\)/i', $label, $m)) return (float)$m[1];
        $config_i[] = "KRA II Crit A: Cannot resolve base points for '{$label}' — CONFIG_INCOMPLETE.";
        return null;
    }

    private static function resolveInventionBase(string $label, array &$config_i): ?float
    {
        $l = strtolower($label);
        if (str_contains($l, 'grant') && (str_contains($l, 'patent') || str_contains($l, 'invention'))) return 80.0;
        if (str_contains($l, 'publication') && str_contains($l, 'patent')) return 20.0;
        if (str_contains($l, 'acceptance') && str_contains($l, 'patent'))  return 10.0;
        if (str_contains($l, 'utility model'))    return 10.0;
        if (str_contains($l, 'industrial design')) return 5.0;
        if (str_contains($l, 'commerciali') && str_contains($l, 'intl'))   return 10.0;
        if (str_contains($l, 'commerciali') && str_contains($l, 'local'))  return 5.0;
        if (str_contains($l, 'software') && str_contains($l, 'update'))    return 4.0;
        if (str_contains($l, 'software'))          return 10.0;
        if (str_contains($l, 'plant variety') || str_contains($l, 'animal breed') || str_contains($l, 'microbial')) return 10.0;
        if (preg_match('/\((\d+(?:\.\d+)?)\s*pts?\)/i', $label, $m)) return (float)$m[1];
        $config_i[] = "KRA II Crit B: Cannot resolve invention base for '{$label}' — CONFIG_INCOMPLETE.";
        return null;
    }

    private static function resolveCreativeBase(string $label, array &$config_i): ?float
    {
        $l = strtolower($label);
        // Performing arts — music
        if (str_contains($l, 'composition') || str_contains($l, 'arrangement') || str_contains($l, 'production')) return 20.0;
        if (str_contains($l, 'performance') || str_contains($l, 'conducting'))                                     return 10.0;
        // Dance
        if (str_contains($l, 'choreograph'))                                     return 20.0;
        // Theatre
        if (str_contains($l, 'playwright') || str_contains($l, 'directing') || str_contains($l, 'acting') || str_contains($l, 'production design')) return 20.0;
        // Visual arts (one shared rate) — architecture/engineering/interior nested here
        if (str_contains($l, 'visual') || str_contains($l, 'painting') || str_contains($l, 'sculpture')
            || str_contains($l, 'photograph') || str_contains($l, 'digital art')
            || str_contains($l, 'architect') || str_contains($l, 'engineer') || str_contains($l, 'interior')) return 20.0;
        // Film (one shared rate)
        if (str_contains($l, 'film') || str_contains($l, 'documentary') || str_contains($l, 'animated')) return 20.0;
        // Literary
        if (str_contains($l, 'novel'))       return 20.0;
        if (str_contains($l, 'short story')) return 10.0;
        if (str_contains($l, 'poetry'))      return 2.0;
        if (str_contains($l, 'essay'))       return 2.0;
        if (str_contains($l, 'exhibition') || str_contains($l, 'juried') || str_contains($l, 'peer-reviewed design')) return 20.0;
        if (preg_match('/\((\d+(?:\.\d+)?)\s*pts?\)/i', $label, $m)) return (float)$m[1];
        $config_i[] = "KRA II Crit C: Cannot resolve creative work base for '{$label}' — CONFIG_INCOMPLETE.";
        return null;
    }

    /**
     * Per-entry scorer for kra_ajax.php compatibility.
     * Returns computed_points for a single Research submission row.
     */
    public static function computeFromRemarks(string $remarks): float
    {
        $parts   = array_map('trim', explode('|||', $remarks));
        $label   = $parts[0] ?? '';
        $contrib = min(100, max(1, (float)($parts[2] ?? 100)));
        $dummy   = [];

        if (self::isInvention($label)) {
            $base = self::resolveInventionBase($label, $dummy);
        } elseif (self::isCreativeWork($label)) {
            $base = self::resolveCreativeBase($label, $dummy);
        } else {
            $base = self::resolveResearchBase($label, $dummy);
        }

        if ($base === null) {
            // Try generic pts-in-label extraction
            if (preg_match('/\((\d+(?:\.\d+)?)\s*pts?\)/i', $label, $m)) $base = (float)$m[1];
            else return 0.0;
        }
        $isCo = self::isCoAuthor($label) || self::isCoInventor($label) || self::isCoCreator($label);
        return round($isCo ? $base * ($contrib / 100) : $base, 2);
    }
}
