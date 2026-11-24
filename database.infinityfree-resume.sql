-- Resume an import that stopped in the scoring-column migration.
-- Select the target database in phpMyAdmin before importing this file.

-- ============================================================
-- 14. RUNTIME MIGRATIONS for JC01 s.2026 scoring columns
--     Check each column so a partial import can resume on MySQL or MariaDB.
-- ============================================================
SELECT GROUP_CONCAT(CONCAT('ADD COLUMN ', required_columns.column_definition)
                    ORDER BY required_columns.column_order SEPARATOR ', ')
    INTO @missing_application_columns
FROM (
    SELECT 1 AS column_order, 'committee_route' AS column_name,
           'committee_route VARCHAR(10) DEFAULT NULL' AS column_definition
    UNION ALL SELECT 2, 'evaluation_period_ok', 'evaluation_period_ok TINYINT(1) DEFAULT 1'
    UNION ALL SELECT 3, 'double_counting_flags', 'double_counting_flags TEXT DEFAULT NULL'
    UNION ALL SELECT 4, 'pending_documentation', 'pending_documentation TEXT DEFAULT NULL'
    UNION ALL SELECT 5, 'config_incomplete', 'config_incomplete TEXT DEFAULT NULL'
    UNION ALL SELECT 6, 'orchestrator_flags', 'orchestrator_flags TEXT DEFAULT NULL'
) AS required_columns
LEFT JOIN information_schema.COLUMNS AS existing
    ON existing.TABLE_SCHEMA = DATABASE()
    AND existing.TABLE_NAME = 'applications'
    AND existing.COLUMN_NAME = required_columns.column_name
WHERE existing.COLUMN_NAME IS NULL;
SET @migration_sql = COALESCE(CONCAT('ALTER TABLE applications ', @missing_application_columns), 'DO 0');
PREPARE schema_migration FROM @migration_sql;
EXECUTE schema_migration;
DEALLOCATE PREPARE schema_migration;

-- Correct the panel member point values per JC01 s.2026
UPDATE scoring_criteria SET max_points = 4.00
    WHERE criterion_key = 'kra1_c_panel_masters'
      AND max_points = 2.00
      AND cycle_id IS NULL;

UPDATE scoring_criteria SET max_points = 6.00
    WHERE criterion_key = 'kra1_c_panel_doctoral'
      AND max_points = 2.00
      AND cycle_id IS NULL;

-- ============================================================
-- 16. RUNTIME MIGRATION for anonymous evaluator identity
--     evaluator_label holds the auto-incremented anonymous name
--     ("Evaluator #1", "Evaluator #2", ...) shown wherever an
--     evaluator's identity appears on faculty-facing pages.
--     Real name stays visible only in Manage Users (admin view).
-- ============================================================
SET @migration_sql = IF(
    EXISTS (
        SELECT 1 FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'checker_label'
    ),
    'DO 0',
    'ALTER TABLE users ADD COLUMN checker_label VARCHAR(50) DEFAULT NULL AFTER role'
);
PREPARE schema_migration FROM @migration_sql;
EXECUTE schema_migration;
DEALLOCATE PREPARE schema_migration;

-- Backfill labels for any pre-existing evaluator accounts
-- created before this column existed.
SET @cl_rownum = (SELECT COALESCE(MAX(CAST(SUBSTRING(checker_label, 12) AS UNSIGNED)), 0)
                   FROM users WHERE checker_label REGEXP '^Evaluator #[0-9]+$');
UPDATE users
    SET checker_label = CONCAT('Evaluator #', (@cl_rownum := @cl_rownum + 1))
    WHERE role = 'evaluator' AND checker_label IS NULL
    ORDER BY user_id ASC;

-- Expand existing evaluation labels, including cycle and rank copies.
-- Evaluation label migration begin
UPDATE scoring_criteria
    SET criterion_label = REPLACE(criterion_label, 'Student Evaluation of Teaching (SET)', 'Student Evaluation of Teaching')
    WHERE criterion_key = 'kra1_a_set';
UPDATE scoring_criteria
    SET criterion_label = REPLACE(criterion_label, 'Supervisor Evaluation Form (SEF)', 'Supervisor Evaluation Form')
    WHERE criterion_key = 'kra1_a_sef';
-- Evaluation label migration end

