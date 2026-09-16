INSERT INTO users (first_name, middle_name, last_name, email, password, role, status, employee_id)
VALUES ('System', NULL, 'Administrator', 'admin@chmsuft.edu.ph', '$2y$10$eHQd6ot06VL7ExAEv9.T5OLUTx1nJGitYK1QEicvGCnZCqORK64Ru', 'admin', 'active', 'ADMIN-001')
ON DUPLICATE KEY UPDATE
    first_name = VALUES(first_name),
    middle_name = VALUES(middle_name),
    last_name = VALUES(last_name),
    email = VALUES(email),
    password = VALUES(password),
    role = VALUES(role),
    status = VALUES(status);
