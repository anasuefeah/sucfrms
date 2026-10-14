-- ============================================================
-- Migration: Consolidate roles to admin / faculty / evaluator
-- Replaces: checker, talisay_checker, checker_faculty → evaluator
-- Run this ONCE against the live SUCFRMS database.
-- ============================================================

USE SUCFRMS;

-- Step 1: Rename all old role values to 'evaluator'
UPDATE users
SET role = 'evaluator'
WHERE role IN ('checker', 'talisay_checker', 'checker_faculty');

-- Step 2: Update the ENUM to only allow the three canonical roles
ALTER TABLE users
    MODIFY COLUMN role ENUM('faculty', 'admin', 'evaluator') DEFAULT 'faculty';

-- Step 3: Backfill checker_label for any evaluator without one
SET @cl_max = (
    SELECT COALESCE(MAX(CAST(SUBSTRING(checker_label, 12) AS UNSIGNED)), 0)
    FROM users
    WHERE checker_label REGEXP '^Evaluator #[0-9]+$'
);

UPDATE users
SET checker_label = CONCAT('Evaluator #', (@cl_max := @cl_max + 1))
WHERE role = 'evaluator'
  AND checker_label IS NULL
ORDER BY user_id ASC;
