-- Adds faculty appeal threads for evaluator flags and score changes.

ALTER TABLE cycles
    ADD COLUMN IF NOT EXISTS appeal_deadline DATE DEFAULT NULL AFTER evaluation_deadline;

CREATE TABLE IF NOT EXISTS appeals (
    appeal_id INT AUTO_INCREMENT PRIMARY KEY,
    application_id INT NOT NULL,
    submission_id INT NOT NULL,
    faculty_id INT NOT NULL,
    checker_id INT NOT NULL,
    cycle_id INT NOT NULL,
    appeal_type ENUM('flag','score_change') NOT NULL,
    original_score DECIMAL(6,2) DEFAULT NULL,
    changed_score DECIMAL(6,2) DEFAULT NULL,
    checker_remark TEXT DEFAULT NULL,
    reason TEXT NOT NULL,
    status ENUM('open','under_review','resolved') DEFAULT 'open',
    outcome ENUM('upheld','revised','dismissed') DEFAULT NULL,
    is_read_by_faculty TINYINT(1) DEFAULT 1,
    is_read_by_checker TINYINT(1) DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    resolved_at TIMESTAMP NULL,
    resolved_by INT DEFAULT NULL,
    INDEX idx_appeals_submission_status (submission_id, status),
    INDEX idx_appeals_faculty (faculty_id, status),
    INDEX idx_appeals_checker (checker_id, status),
    FOREIGN KEY (application_id) REFERENCES applications(application_id) ON DELETE CASCADE,
    FOREIGN KEY (submission_id) REFERENCES kra_submissions(submission_id) ON DELETE CASCADE,
    FOREIGN KEY (faculty_id) REFERENCES users(user_id) ON DELETE CASCADE,
    FOREIGN KEY (checker_id) REFERENCES users(user_id) ON DELETE CASCADE,
    FOREIGN KEY (cycle_id) REFERENCES cycles(cycle_id) ON DELETE CASCADE,
    FOREIGN KEY (resolved_by) REFERENCES users(user_id) ON DELETE SET NULL
);

CREATE TABLE IF NOT EXISTS appeal_messages (
    message_id INT AUTO_INCREMENT PRIMARY KEY,
    appeal_id INT NOT NULL,
    sender_id INT NOT NULL,
    message TEXT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (appeal_id) REFERENCES appeals(appeal_id) ON DELETE CASCADE,
    FOREIGN KEY (sender_id) REFERENCES users(user_id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS appeal_attachments (
    attachment_id INT AUTO_INCREMENT PRIMARY KEY,
    message_id INT NOT NULL,
    file_path VARCHAR(255) NOT NULL,
    original_filename VARCHAR(255) NOT NULL,
    file_size_bytes INT DEFAULT 0,
    uploaded_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (message_id) REFERENCES appeal_messages(message_id) ON DELETE CASCADE
);
