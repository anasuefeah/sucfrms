-- JC2026 Scoring Fixes Migration
-- Applies all scoring_criteria corrections from the JC2026 reference audit.
-- Safe to run on any existing SUCFRMS installation. All statements are idempotent.

-- ============================================================
-- 1. KRA III Criterion D — Coordinator roles (split into two tiers)
--    Reference: Institutional coordinator = 2 pts/yr, College/Dept = 1 pt/yr
-- ============================================================
INSERT INTO scoring_criteria (kra_category, criterion_key, criterion_label, max_points, weight_pct, description)
VALUES
    ('Extension', 'kra3_d_coordinator_inst', 'Criterion D (BONUS) - Coordinator (Institutional Level)', 2.00, 100.00,
     'Copy of appointment or designation with effectivity period. Copy of accomplishment report duly submitted to authorized official/supervisor.'),
    ('Extension', 'kra3_d_coordinator_dept', 'Criterion D (BONUS) - Coordinator (College/Department Level)', 1.00, 100.00,
     'Copy of appointment or designation with effectivity period. Copy of accomplishment report duly submitted to authorized official/supervisor.')
ON DUPLICATE KEY UPDATE
    criterion_label = VALUES(criterion_label),
    max_points      = VALUES(max_points),
    description     = VALUES(description);

-- ============================================================
-- 2. KRA IV Criterion C — Award taxonomy corrections
--    OLD mapping (wrong):
--      kra4_c_award_regional    subVal=4  4 pts  "Regional"        ← WRONG: JC01 says subVal=4 = National (4 pts)
--      kra4_c_award_national    subVal=0  0 pts  "National"        ← WRONG: was conflated with sub-rank trigger
--      kra4_c_award_international subVal=0 0 pts "International"   ← incomplete: GAP not handled
--
--    NEW mapping (correct per JC01 s.2026):
--      kra4_c_award_local        subVal=3  3 pts  "Local / Regional (City/Prov/Region)"  CONFIRMED
--      kra4_c_award_national     subVal=4  4 pts  "National"                              CONFIRMED
--      kra4_c_award_international subVal=5 0 pts  "International"                         GAP — set after CHED-RO confirmation
--      kra4_c_award_prestigious  subVal=0  0 pts  "Prestigious Award — sub-rank trigger"  CONFIRMED
--
-- Step A: Update existing kra4_c_award_national to correct point value (4 pts, not 0)
-- ============================================================
UPDATE scoring_criteria
SET criterion_label = 'Criterion C - National Award',
    max_points      = 4.00,
    description     = 'Copy of certificate of recognition or award. Copy of picture of plaque, trophy, medal, or similar items.'
WHERE criterion_key = 'kra4_c_award_national';

-- Step B: Update kra4_c_award_local label to reflect correct tier name
UPDATE scoring_criteria
SET criterion_label = 'Criterion C - Local / Regional Award (City/Municipality/Province/Region)',
    max_points      = 3.00
WHERE criterion_key = 'kra4_c_award_local';

-- Step C: Rename kra4_c_award_regional → kra4_c_award_national already handled above.
--         Remove the old kra4_c_award_regional row (it was the wrong mapping for National).
--         Use DELETE only if the row exists, to avoid error on clean installs.
DELETE FROM scoring_criteria WHERE criterion_key = 'kra4_c_award_regional';

-- Step D: Update kra4_c_award_international to reflect the GAP (max_points=0 until CHED-RO confirms)
UPDATE scoring_criteria
SET criterion_label = 'Criterion C - International Award (CONFIG GAP — set after confirming with CHED-RO)',
    max_points      = 0.00,
    description     = 'CONFIG_INTERNATIONAL_AWARD_PTS: the Points value for the international tier is blank in JC01 s.2026 (confirmed source gap). Set max_points to the confirmed value once known. Evidence: copy of certificate, picture of award.'
WHERE criterion_key = 'kra4_c_award_international';

-- Step E: Add kra4_c_award_prestigious for Auto Sub-Rank Trigger 2 entries (0 KRA IV pts, sub-rank trigger only)
INSERT INTO scoring_criteria (kra_category, criterion_key, criterion_label, max_points, weight_pct, description)
VALUES (
    'Professional Development',
    'kra4_c_award_prestigious',
    'Criterion C - Prestigious Award (Auto Sub-Rank Trigger only, 0 KRA IV pts)',
    0.00,
    100.00,
    'Copy of certificate, picture of award. Award-giving body mandate/history. Prior winners list. Requires checker confirmation for sub-rank trigger — never auto-approved.'
)
ON DUPLICATE KEY UPDATE
    criterion_label = VALUES(criterion_label),
    max_points      = VALUES(max_points),
    description     = VALUES(description);

-- ============================================================
-- 3. Verify scoring_criteria state after migration (informational — not executed)
--    Expected post-migration KRA IV Crit C rows:
--      kra4_c_award_institutional  2.00
--      kra4_c_award_local          3.00
--      kra4_c_award_national       4.00
--      kra4_c_award_international  0.00  ← GAP, pending CHED-RO
--      kra4_c_award_prestigious    0.00  ← sub-rank trigger, no KRA IV pts
-- ============================================================
