-- ============================================================
-- Migration: Drop confirmed unused columns
-- Run ONCE against the live SUCFRMS database.
-- All columns below have zero PHP read/write references
-- (verified by full codebase audit, October 2026).
-- ============================================================

USE SUCFRMS;

-- ── applications table ───────────────────────────────────────
-- tracking_number: never SET anywhere, never rendered in UI
ALTER TABLE applications DROP COLUMN IF EXISTS tracking_number;

-- salary_grade: zero PHP references
ALTER TABLE applications DROP COLUMN IF EXISTS salary_grade;

-- study_leave: zero PHP references
ALTER TABLE applications DROP COLUMN IF EXISTS study_leave;

-- edit_reason: zero PHP references
ALTER TABLE applications DROP COLUMN IF EXISTS edit_reason;

-- ── auto_sub_rank table ──────────────────────────────────────
-- Legacy status ENUMs superseded by doctorate_mode / award_mode.
-- Only appeared in CREATE TABLE DDL, never read or written.
ALTER TABLE auto_sub_rank DROP COLUMN IF EXISTS doctorate_status;
ALTER TABLE auto_sub_rank DROP COLUMN IF EXISTS award_status;

-- ── pre_eval_auto_sub_rank table ────────────────────────────
-- Same legacy stubs as above, for the pre-evaluation variant.
ALTER TABLE pre_eval_auto_sub_rank DROP COLUMN IF EXISTS doctorate_status;
ALTER TABLE pre_eval_auto_sub_rank DROP COLUMN IF EXISTS award_status;

-- ── applications: also drop the UNIQUE index on tracking_number
-- (MySQL will have already dropped it with the column above,
--  but include this guard in case the column was never indexed)
-- ALTER TABLE applications DROP INDEX IF EXISTS tracking_number;
-- ^ MySQL auto-drops single-column unique indexes when the column is dropped.

SELECT 'Migration complete. 8 unused columns dropped.' AS result;
