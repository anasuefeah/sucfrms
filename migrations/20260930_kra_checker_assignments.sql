-- Adds per-cycle KRA assignment controls for evaluator accounts.
-- Existing evaluators are granted all KRAs for existing cycles so current review work continues.

CREATE TABLE IF NOT EXISTS checker_kra_assignments (
    assignment_id INT AUTO_INCREMENT PRIMARY KEY,
    cycle_id INT NOT NULL,
    checker_id INT NOT NULL,
    kra_category ENUM('Instruction','Research','Extension','Professional Development') NOT NULL,
    assigned_by INT DEFAULT NULL,
    assigned_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_checker_cycle_kra (cycle_id, checker_id, kra_category),
    INDEX idx_cka_cycle_kra (cycle_id, kra_category),
    INDEX idx_cka_checker_cycle (checker_id, cycle_id),
    FOREIGN KEY (cycle_id) REFERENCES cycles(cycle_id) ON DELETE CASCADE,
    FOREIGN KEY (checker_id) REFERENCES users(user_id) ON DELETE CASCADE,
    FOREIGN KEY (assigned_by) REFERENCES users(user_id) ON DELETE SET NULL
);

INSERT IGNORE INTO checker_kra_assignments (cycle_id, checker_id, kra_category, assigned_by)
SELECT c.cycle_id, u.user_id, k.kra_category, NULL
FROM cycles c
JOIN users u ON u.role = 'checker' AND u.status = 'active'
JOIN (
    SELECT 'Instruction' AS kra_category
    UNION ALL SELECT 'Research'
    UNION ALL SELECT 'Extension'
    UNION ALL SELECT 'Professional Development'
) k;
