-- Add education fields used by the faculty profile page.
-- Safe to run more than once.

ALTER TABLE users
    ADD COLUMN IF NOT EXISTS applied_first_cycle TINYINT(1) DEFAULT 0 AFTER faculty_status;

CREATE TABLE IF NOT EXISTS faculty_education (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    level ENUM('Bachelor','Master','Doctorate','PostDoctorate') NOT NULL,
    degree_program VARCHAR(255) NOT NULL,
    major_specialization VARCHAR(255) NOT NULL,
    school_university VARCHAR(255) NOT NULL,
    year_graduated VARCHAR(20) NOT NULL,
    honors_units_notes VARCHAR(255) DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_faculty_education_user (user_id),
    FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE CASCADE
);

ALTER TABLE faculty_education
    MODIFY COLUMN level ENUM('Bachelor','Master','Doctorate','PostDoctorate') NOT NULL;
