-- Adds cycle submission/evaluation scheduling and submission extension records.
-- Safe to run more than once.

ALTER TABLE cycles
    ADD COLUMN IF NOT EXISTS submission_start_date DATE DEFAULT NULL AFTER end_date,
    ADD COLUMN IF NOT EXISTS evaluation_deadline DATE DEFAULT NULL AFTER submission_deadline;

UPDATE cycles
   SET submission_start_date = start_date
 WHERE submission_start_date IS NULL
   AND start_date IS NOT NULL;

UPDATE cycles
   SET evaluation_deadline = end_date
 WHERE evaluation_deadline IS NULL
   AND end_date IS NOT NULL;

CREATE TABLE IF NOT EXISTS cycle_submission_extensions (
    extension_id INT AUTO_INCREMENT PRIMARY KEY,
    cycle_id INT NOT NULL,
    faculty_user_id INT DEFAULT NULL,
    applies_to_all TINYINT(1) DEFAULT 0,
    previous_deadline DATE NOT NULL,
    new_deadline DATE NOT NULL,
    reason TEXT DEFAULT NULL,
    extended_by INT DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_cycle_extensions_cycle (cycle_id),
    INDEX idx_cycle_extensions_faculty (faculty_user_id),
    FOREIGN KEY (cycle_id) REFERENCES cycles(cycle_id) ON DELETE CASCADE,
    FOREIGN KEY (faculty_user_id) REFERENCES users(user_id) ON DELETE CASCADE,
    FOREIGN KEY (extended_by) REFERENCES users(user_id) ON DELETE SET NULL
);
