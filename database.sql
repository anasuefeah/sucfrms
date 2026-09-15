-- ============================================================
-- SUCFRMS — Faculty Reclassification Management Information System
-- Full Schema + Seed Data
-- Compatible with MySQL 5.7+ / MariaDB 10.3+
-- ============================================================

-- Prevent errors from strict mode and encoding mismatches
SET NAMES utf8mb4;
SET CHARACTER SET utf8mb4;
SET character_set_client = utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;
SET SQL_MODE = 'NO_AUTO_VALUE_ON_ZERO';
SET time_zone = '+00:00';

CREATE DATABASE IF NOT EXISTS SUCFRMS CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE SUCFRMS;

-- ============================================================
-- 1. CAMPUSES
--    Must be created before users due to FK reference
-- ============================================================
CREATE TABLE IF NOT EXISTS campuses (
    campus_id   INT AUTO_INCREMENT PRIMARY KEY,
    campus_name VARCHAR(100) NOT NULL UNIQUE,
    is_active   TINYINT(1) DEFAULT 1,
    created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- ============================================================
-- 2. USERS
-- ============================================================
CREATE TABLE IF NOT EXISTS users (
    user_id     INT AUTO_INCREMENT PRIMARY KEY,
    -- Name stored in parts to match the registration form
    first_name  VARCHAR(100) NOT NULL,
    middle_name VARCHAR(100) DEFAULT NULL,
    last_name   VARCHAR(100) NOT NULL,
    -- full_name is a generated column for convenience (search, display, PDF)
    -- Format: "Last, First M." for regular users; just "last_name" when first_name is empty (e.g. system accounts)
    full_name   VARCHAR(310) GENERATED ALWAYS AS (
                    IF(first_name = '' OR first_name IS NULL,
                        last_name,
                        TRIM(CONCAT(last_name, ', ', first_name,
                            IF(middle_name IS NOT NULL AND middle_name <> '',
                                CONCAT(' ', LEFT(middle_name, 1), '.'), ''))))
                ) STORED,
    email       VARCHAR(150) NOT NULL UNIQUE,
    password    VARCHAR(255) NOT NULL,
    role        ENUM('faculty','checker','admin','checker_faculty','talisay_checker') DEFAULT 'faculty',
    status      ENUM('active','inactive','rejected') DEFAULT 'active',
    campus_id   INT DEFAULT NULL,
    -- rank stores the current faculty rank (e.g. "Assistant Professor II")
    -- used as the starting point for reclassification scoring
    rank        VARCHAR(100),
    employee_id VARCHAR(50) UNIQUE,
    profile_pic VARCHAR(255) DEFAULT NULL,
    created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (campus_id) REFERENCES campuses(campus_id) ON DELETE SET NULL
);

-- ============================================================
-- 4. RECLASSIFICATION CYCLES
-- ============================================================
CREATE TABLE IF NOT EXISTS cycles (
    cycle_id            INT AUTO_INCREMENT PRIMARY KEY,
    cycle_name          VARCHAR(100) NOT NULL,
    start_date          DATE NOT NULL,
    end_date            DATE NOT NULL,
    submission_deadline DATE DEFAULT NULL,
    status              ENUM('open','closed','archived') DEFAULT 'open',
    created_by          INT DEFAULT NULL,
    created_at          TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (created_by) REFERENCES users(user_id) ON DELETE SET NULL
);

-- ============================================================
-- 5. RECLASSIFICATION APPLICATIONS
-- ============================================================
CREATE TABLE IF NOT EXISTS applications (
    application_id     INT AUTO_INCREMENT PRIMARY KEY,
    tracking_number    VARCHAR(30) DEFAULT NULL UNIQUE,
    user_id            INT NOT NULL,
    cycle_id           INT DEFAULT NULL,
    status             ENUM('draft','submitted','under_review','talisay_review','approved','rejected','reclassified','admin_rejected','edit_requested','needs_revision') DEFAULT 'draft',
    total_score        DECIMAL(7,2) DEFAULT 0,
    weighted_score     DECIMAL(7,2) DEFAULT 0,
    sub_rank_increment TINYINT DEFAULT 0,
    potential_rank     VARCHAR(100) DEFAULT NULL,
    checker_id         INT DEFAULT NULL,
    checker_remarks    TEXT,
    edit_reason        TEXT,
    reviewed_at        TIMESTAMP NULL,
    submitted_at       TIMESTAMP NULL,
    position_title     VARCHAR(100) DEFAULT NULL,
    salary_grade       VARCHAR(20)  DEFAULT NULL,
    study_leave        ENUM('yes','no') DEFAULT 'no',
    created_at         TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at         TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id)    REFERENCES users(user_id) ON DELETE CASCADE,
    FOREIGN KEY (cycle_id)   REFERENCES cycles(cycle_id) ON DELETE SET NULL,
    FOREIGN KEY (checker_id) REFERENCES users(user_id) ON DELETE SET NULL
);

-- ============================================================
-- 6. KRA SUBMISSIONS
-- ============================================================
CREATE TABLE IF NOT EXISTS kra_submissions (
    submission_id   INT AUTO_INCREMENT PRIMARY KEY,
    application_id  INT NOT NULL,
    user_id         INT NOT NULL,
    kra_category    ENUM('Instruction','Research','Extension','Professional Development') NOT NULL,
    computed_points DECIMAL(6,2) DEFAULT 0,
    document_path   VARCHAR(255),
    remarks         TEXT,
    verified        TINYINT(1) DEFAULT 0,
    verified_by     INT DEFAULT NULL,
    verified_at     TIMESTAMP NULL,
    revision_status ENUM('ok','needs_revision') DEFAULT 'ok',
    revision_note   TEXT DEFAULT NULL,
    revision_by     INT DEFAULT NULL,
    revision_at     TIMESTAMP NULL,
    submitted_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (application_id) REFERENCES applications(application_id) ON DELETE CASCADE,
    FOREIGN KEY (user_id)        REFERENCES users(user_id) ON DELETE CASCADE,
    FOREIGN KEY (verified_by)    REFERENCES users(user_id) ON DELETE SET NULL
);

-- ============================================================
-- 7. KRA EVIDENCE FILES  (multiple files per submission)
-- ============================================================
CREATE TABLE IF NOT EXISTS kra_evidence_files (
    evidence_id       INT AUTO_INCREMENT PRIMARY KEY,
    submission_id     INT NOT NULL,
    file_path         VARCHAR(255) NOT NULL,
    original_filename VARCHAR(255) NOT NULL,
    file_size_bytes   INT DEFAULT 0,
    uploaded_at       TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    uploaded_by       INT DEFAULT NULL,
    FOREIGN KEY (submission_id) REFERENCES kra_submissions(submission_id) ON DELETE CASCADE,
    FOREIGN KEY (uploaded_by)   REFERENCES users(user_id) ON DELETE SET NULL
);

-- ============================================================
-- 8. SCORING CRITERIA
-- ============================================================
CREATE TABLE IF NOT EXISTS scoring_criteria (
    criteria_id     INT AUTO_INCREMENT PRIMARY KEY,
    cycle_id        INT DEFAULT NULL,
    position_rank   VARCHAR(100) DEFAULT NULL,
    kra_category    ENUM('Instruction','Research','Extension','Professional Development') NOT NULL,
    criterion_key   VARCHAR(100) NOT NULL,
    criterion_label VARCHAR(255) NOT NULL,
    max_points      DECIMAL(6,2) NOT NULL,
    weight_pct      DECIMAL(5,2) DEFAULT 100.00,
    description     TEXT,
    is_active       TINYINT(1) DEFAULT 1,
    updated_by      INT DEFAULT NULL,
    updated_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    -- NULL cycle_id + NULL position_rank = global default for all cycles and positions
    -- cycle_id set  + NULL position_rank = cycle-wide (all positions in that cycle)
    -- cycle_id set  + position_rank set  = most specific (cycle + position)
    UNIQUE KEY uq_cycle_pos_criterion (cycle_id, position_rank, criterion_key),
    FOREIGN KEY (cycle_id)   REFERENCES cycles(cycle_id) ON DELETE CASCADE,
    FOREIGN KEY (updated_by) REFERENCES users(user_id) ON DELETE SET NULL
);

-- ============================================================
-- 9. PASSWORD RESETS
-- ============================================================
CREATE TABLE IF NOT EXISTS password_resets (
    reset_id      INT AUTO_INCREMENT PRIMARY KEY,
    user_id       INT NOT NULL UNIQUE,
    temp_password VARCHAR(20) DEFAULT NULL,
    otp_code      VARCHAR(6)  DEFAULT NULL,
    otp_expires_at DATETIME   DEFAULT NULL,
    status        ENUM('pending','released','verified') DEFAULT 'pending',
    requested_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    released_at   TIMESTAMP NULL,
    FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE CASCADE
);

-- ============================================================
-- 10. APPLICATION CHECKER REVIEWS
--     Multi-checker approval tracking per application.
--     Each checker gets one row per application they review.
-- ============================================================
CREATE TABLE IF NOT EXISTS application_checker_reviews (
    review_id      INT AUTO_INCREMENT PRIMARY KEY,
    application_id INT NOT NULL,
    checker_id     INT NOT NULL,
    decision       ENUM('approved','rejected','pending') DEFAULT 'pending',
    remarks        TEXT,
    decided_at     TIMESTAMP NULL,
    created_at     TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_app_checker (application_id, checker_id),
    FOREIGN KEY (application_id) REFERENCES applications(application_id) ON DELETE CASCADE,
    FOREIGN KEY (checker_id)     REFERENCES users(user_id) ON DELETE CASCADE
);

-- ============================================================
-- 11. AUDIT LOGS
-- ============================================================
CREATE TABLE IF NOT EXISTS audit_logs (
    log_id           INT AUTO_INCREMENT PRIMARY KEY,
    user_id          INT DEFAULT NULL,
    role_at_time     VARCHAR(20),
    action_performed VARCHAR(255) NOT NULL,
    details          TEXT,
    timestamp        TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE SET NULL
);

-- ============================================================
-- SEED: DEFAULT CAMPUSES
-- ============================================================
INSERT INTO campuses (campus_name) VALUES
    ('CHMSU-Fortune Towne'),
    ('CHMSU-Binalbagan'),
    ('CHMSU-Alijis'),
    ('CHMSU-Talisay')
ON DUPLICATE KEY UPDATE campus_id = campus_id;

-- ============================================================
-- SEED: DEFAULT ADMIN ACCOUNT
--   Password is a placeholder — run pages/setup_admin.php
--   to set a real bcrypt password before going live.
-- ============================================================
INSERT INTO users (first_name, middle_name, last_name, email, password, role, status, employee_id)
VALUES ('System', NULL, 'Administrator', 'admin@sucfrms.edu.ph', 'PLACEHOLDER', 'admin', 'active', 'ADMIN-001')
ON DUPLICATE KEY UPDATE user_id = user_id;

-- ============================================================
-- SEED: SCORING CRITERIA  (DBM-CHED Joint Circular No. 3, s. 2022)
-- Global defaults — copied per-position when a cycle is created.
-- ============================================================
INSERT INTO scoring_criteria (kra_category, criterion_key, criterion_label, max_points, weight_pct, description) VALUES

-- ── KRA I: INSTRUCTION ───────────────────────────────────────
('Instruction','kra1_a_set','Criterion A – Student Evaluation of Teaching (SET)',36.00,100.00,'Student evaluation rating using prescribed template.'),
('Instruction','kra1_a_sef','Criterion A – Supervisor Evaluation Form (SEF)',24.00,100.00,'Supervisor\'s evaluation rating using prescribed template.'),
('Instruction','kra1_b_textbook_sole','Criterion B – Textbook, Sole Author',30.00,100.00,'Copy of instructional material developed. Copy of evidence that the material underwent peer-review or evaluation process. Copy of approval for use in the department/institution.'),
('Instruction','kra1_b_textbook_co','Criterion B – Textbook, Co-Author',30.00,100.00,'Copy of instructional material developed. Copy of evidence that the material underwent peer-review or evaluation process. Copy of approval for use in the department/institution. For multiple authors — certification signed by all authors indicating percentage contribution using prescribed template.'),
('Instruction','kra1_b_chapter_sole','Criterion B – Textbook Chapter, Sole Author',10.00,100.00,'Copy of instructional material developed. Copy of evidence that the material underwent peer-review or evaluation process. Copy of approval for use in the department/institution.'),
('Instruction','kra1_b_chapter_co','Criterion B – Textbook Chapter, Co-Author',10.00,100.00,'Copy of instructional material developed. Copy of evidence that the material underwent peer-review or evaluation process. Copy of approval for use in the department/institution. For multiple authors — certification signed by all authors indicating percentage contribution using prescribed template.'),
('Instruction','kra1_b_manual_sole','Criterion B – Manual / Module, Sole Author',16.00,100.00,'Copy of instructional material developed. Copy of evidence that the material underwent peer-review or evaluation process. Copy of approval for use in the department/institution.'),
('Instruction','kra1_b_manual_co','Criterion B – Manual / Module, Co-Author',16.00,100.00,'Copy of instructional material developed. Copy of evidence that the material underwent peer-review or evaluation process. Copy of approval for use in the department/institution. For multiple authors — certification signed by all authors indicating percentage contribution using prescribed template.'),
('Instruction','kra1_b_multimedia','Criterion B – Multimedia Teaching Materials',16.00,100.00,'Copy of testing material with evidence of validation, reliability testing, and verification by authorized body.'),
('Instruction','kra1_b_testing','Criterion B – Validated Testing Materials',10.00,100.00,'Copy of testing material with evidence of validation, reliability testing, and verification by authorized body.'),
('Instruction','kra1_b_program_lead','Criterion B – Academic Program Dev/Revision, Lead',10.00,100.00,'Copy of certification signed by academic unit head indicating faculty role in program development/revision. Copy of governing board resolution approving the academic program.'),
('Instruction','kra1_b_program_contrib','Criterion B – Academic Program Dev/Revision, Contributor',5.00,100.00,'Copy of certification signed by academic unit head indicating faculty role in program development/revision. Copy of governing board resolution approving the academic program.'),
('Instruction','kra1_c_adviser_special','Criterion C – Adviser: Special Project / Capstone',3.00,100.00,'Copy of appointment/invitation as adviser. Copy of evidence that advisee passed the capstone, thesis, or dissertation.'),
('Instruction','kra1_c_adviser_undergrad','Criterion C – Adviser: Undergraduate Thesis',5.00,100.00,'Copy of appointment/invitation as adviser. Copy of evidence that advisee passed the capstone, thesis, or dissertation.'),
('Instruction','kra1_c_adviser_masters','Criterion C – Adviser: Master\'s Thesis',8.00,100.00,'Copy of appointment/invitation as adviser. Copy of evidence that advisee passed the capstone, thesis, or dissertation.'),
('Instruction','kra1_c_adviser_doctoral','Criterion C – Adviser: Doctoral Dissertation',10.00,100.00,'Copy of appointment/invitation as adviser. Copy of evidence that advisee passed the capstone, thesis, or dissertation.'),
('Instruction','kra1_c_panel_special','Criterion C – Panel Member: Special Project / Capstone',1.00,100.00,'Copy of appointment/invitation as panel member. Copy of proof of participation.'),
('Instruction','kra1_c_panel_undergrad','Criterion C – Panel Member: Undergraduate Thesis',1.00,100.00,'Copy of appointment/invitation as panel member. Copy of proof of participation.'),
('Instruction','kra1_c_panel_masters','Criterion C – Panel Member: Master\'s Thesis',4.00,100.00,'Copy of appointment/invitation as panel member. Copy of proof of participation.'),
('Instruction','kra1_c_panel_doctoral','Criterion C – Panel Member: Doctoral Dissertation',6.00,100.00,'Copy of appointment/invitation as panel member. Copy of proof of participation.'),
('Instruction','kra1_c_mentor_competition','Criterion C – Mentor: Student/Team Competition Winner',0.00,100.00,'CONFIRMED SOURCE GAP: The Points column for Mentorship Services (JC01 s.2026, Section 15 item 2, p.78) is blank in the official circular. This is not an extraction error. Set max_points to a non-zero value only after confirming with CHED-RO or your adviser. Until set, all mentorship submissions raise PENDING_DOCUMENTATION and are not scored. Hard rules regardless of point value: (1) regional/national/international competitions only — local-only excluded; (2) Champion through 3rd place only — consolation prizes excluded. Required evidence: award certificate or photo of trophy/plaque/medal, competition mechanics document, award-giving organization profile with mandate/history/prior winners list. Suggested starting point for discussion: 1 pt (matching Panel Member Special/Capstone — lowest confirmed rate in Crit C). This is a disclosed design recommendation, not a sourced value.'),

-- ── KRA II: RESEARCH, INVENTION & CREATIVE WORK ──────────────
('Research','kra2_a_book_sole','Criterion A – Book, Sole Author',100.00,100.00,'Copy of published research output showing author name, date, and publication title.'),
('Research','kra2_a_book_co','Criterion A – Book, Co-Author',100.00,100.00,'Copy of published research output showing author name, date, and publication title. For multiple authors — certification from all authors showing percentage contribution using prescribed template.'),
('Research','kra2_a_monograph_sole','Criterion A – Monograph, Sole Author',100.00,100.00,'Copy of published research output showing author name, date, and publication title.'),
('Research','kra2_a_monograph_co','Criterion A – Monograph, Co-Author',100.00,100.00,'Copy of published research output showing author name, date, and publication title. For multiple authors — certification from all authors showing percentage contribution using prescribed template.'),
('Research','kra2_a_journal_indexed_sole','Criterion A – Indexed Journal Article, Sole Author',50.00,100.00,'Copy of published research output showing author name, date, and publication title.'),
('Research','kra2_a_journal_indexed_co','Criterion A – Indexed Journal Article, Co-Author',50.00,100.00,'Copy of published research output showing author name, date, and publication title. For multiple authors — certification from all authors showing percentage contribution using prescribed template.'),
('Research','kra2_a_book_chapter_sole','Criterion A – Book Chapter, Sole Author',35.00,100.00,'Copy of published research output showing author name, date, and publication title.'),
('Research','kra2_a_book_chapter_co','Criterion A – Book Chapter, Co-Author',35.00,100.00,'Copy of published research output showing author name, date, and publication title. For multiple authors — certification from all authors showing percentage contribution using prescribed template.'),
('Research','kra2_a_policy_lead','Criterion A – Research Output → Project/Policy/Product, Lead',35.00,100.00,'Copy of executive summary or evidence that research was translated into project, policy, or product.'),
('Research','kra2_a_policy_contrib','Criterion A – Research Output → Project/Policy/Product, Contributor',35.00,100.00,'Copy of executive summary or evidence that research was translated into project, policy, or product. Certification from all authors showing percentage contribution using prescribed template.'),
('Research','kra2_a_scholarly_other','Criterion A – Other Peer-Reviewed Scholarly Output',10.00,100.00,'Copy of published research output showing author name, date, and publication title.'),
('Research','kra2_a_citation_local','Criterion A – Local Citation (per citation, max 40 pts)',5.00,100.00,'Copy of proof that publication has been cited by other authors via citation index database.'),
('Research','kra2_a_citation_intl','Criterion A – International Citation (per citation, max 60 pts)',10.00,100.00,'Copy of proof that publication has been cited by other authors via citation index database.'),
('Research','kra2_b_patent_acceptance','Criterion B – Invention Patent: Acceptance',10.00,100.00,'Copy of certification from IPOPHL for acceptance.'),
('Research','kra2_b_patent_publication','Criterion B – Invention Patent: Publication',20.00,100.00,'Copy of notice of publication from IPOPHL.'),
('Research','kra2_b_patent_grant','Criterion B – Invention Patent: Grant',80.00,100.00,'Copy of patent/utility model/industrial design certificate issued by IPOPHL for grant. For multiple inventors — certification from all inventors indicating percentage contribution using prescribed template.'),
('Research','kra2_b_utility_model','Criterion B – Utility Model Grant',10.00,100.00,'Copy of patent/utility model/industrial design certificate issued by IPOPHL for grant. For multiple inventors — certification from all inventors indicating percentage contribution using prescribed template.'),
('Research','kra2_b_industrial_design','Criterion B – Industrial Design Grant',5.00,100.00,'Copy of patent/utility model/industrial design certificate issued by IPOPHL for grant. For multiple inventors — certification from all inventors indicating percentage contribution using prescribed template.'),
('Research','kra2_b_commercialized_local','Criterion B – Commercialized Patented Product, Local',5.00,100.00,'Copy of licensing agreement, LTO, certificate of product registration from FDA or similar permits for commercialized patents.'),
('Research','kra2_b_commercialized_intl','Criterion B – Commercialized Patented Product, International',10.00,100.00,'Copy of licensing agreement, LTO, certificate of product registration from FDA or similar permits for commercialized patents.'),
('Research','kra2_b_software_new_sole','Criterion B – New Software Product, Sole Developer',10.00,100.00,'Copy of copyright registration certificate from IPOPHL for software products. Copy of certificate of utilization from end-users for software products.'),
('Research','kra2_b_software_new_co','Criterion B – New Software Product, Co-Developer',10.00,100.00,'Copy of copyright registration certificate from IPOPHL for software products. Copy of certificate of utilization from end-users for software products. Certification from all developers indicating percentage contribution.'),
('Research','kra2_b_software_updated_sole','Criterion B – Updated Software (New Functionality), Sole Developer',4.00,100.00,'Copy of copyright registration certificate from IPOPHL for software products. Copy of certificate of utilization from end-users for software products.'),
('Research','kra2_b_software_updated_co','Criterion B – Updated Software (New Functionality), Co-Developer',2.00,100.00,'Copy of copyright registration certificate from IPOPHL for software products. Copy of certificate of utilization from end-users for software products. Certification from all developers indicating percentage contribution.'),
('Research','kra2_b_plant_variety_sole','Criterion B – New Plant Variety/Animal Breed/Microbial Strain, Sole',10.00,100.00,'Copy of registration of new variety, breed, or strain from authorized agency. Copy of certification from farm owners/breeders that new variety or breed has been propagated.'),
('Research','kra2_b_plant_variety_co','Criterion B – New Plant Variety/Animal Breed/Microbial Strain, Co-Developer',10.00,100.00,'Copy of registration of new variety, breed, or strain from authorized agency. Copy of certification from farm owners/breeders that new variety or breed has been propagated. Certification from all developers indicating percentage contribution.'),
('Research','kra2_c_performance_own','Criterion C – Performance of Own Creative Work',20.00,100.00,'Copy of copyright certificate of the creative performing art work. Copy of invitation letter from reputable organizer, program, and pictures of performance.'),
('Research','kra2_c_performance_others','Criterion C – Performance of Another\'s Creative Work',10.00,100.00,'Copy of invitation letter from reputable organizer, program, and pictures of performance.'),
('Research','kra2_c_exhibition','Criterion C – Exhibition (Visual Arts/Architecture/Film/Multimedia)',10.00,100.00,'Copy of letter of acceptance or invitation for exhibition.'),
('Research','kra2_c_juried_design','Criterion C – Juried or Peer-Reviewed Design',20.00,100.00,'Copy of evidence of being juried or peer-reviewed for designs.'),
('Research','kra2_c_novel','Criterion C – Literary Publication: Novel',20.00,100.00,'Copy of published literary work for literary publications.'),
('Research','kra2_c_short_story','Criterion C – Literary Publication: Short Story',10.00,100.00,'Copy of published literary work for literary publications.'),
('Research','kra2_c_essay','Criterion C – Literary Publication: Essay',10.00,100.00,'Copy of published literary work for literary publications.'),
('Research','kra2_c_poetry','Criterion C – Literary Publication: Poetry',10.00,100.00,'Copy of published literary work for literary publications.'),

-- ── KRA III: EXTENSION SERVICES ──────────────────────────────
('Extension','kra3_a_moa','Criterion A – MOA/Linkage Partnership',5.00,100.00,'Copy of MOA. Certification from the President that partnership was successfully initiated or implemented by the faculty.'),
('Extension','kra3_a_income_below6m','Criterion A – Income Generation: Below PHP 6 Million',6.00,100.00,'Copy of implementation report or terminal activity report. Copy of financial reports showing income generated and certification from the President acknowledging faculty\'s contribution.'),
('Extension','kra3_a_income_6to12m','Criterion A – Income Generation: PHP 6M to PHP 12M',12.00,100.00,'Copy of implementation report or terminal activity report. Copy of financial reports showing income generated and certification from the President acknowledging faculty\'s contribution.'),
('Extension','kra3_a_income_above12m','Criterion A – Income Generation: Above PHP 12 Million',18.00,100.00,'Copy of implementation report or terminal activity report. Copy of financial reports showing income generated and certification from the President acknowledging faculty\'s contribution.'),
('Extension','kra3_b_accreditation_local','Criterion B – Local Accreditation/QA Service',8.00,100.00,'Copy of appointment from the organization/agency. Copy of proof of engagement such as certificate of participation.'),
('Extension','kra3_b_accreditation_intl','Criterion B – International Accreditation/QA Service',10.00,100.00,'Copy of appointment from the organization/agency. Copy of proof of engagement such as certificate of participation.'),
('Extension','kra3_b_judge_research','Criterion B – Judging Research Awards',2.00,100.00,'Copy of appointment from the organization/agency. Copy of proof of engagement such as certificate of participation.'),
('Extension','kra3_b_judge_competition','Criterion B – Judging Academic Competitions',1.00,100.00,'Copy of appointment from the organization/agency. Copy of proof of engagement such as certificate of participation.'),
('Extension','kra3_b_consultant_local','Criterion B – Local Consultant/Expert Service',8.00,100.00,'Copy of contract of service or equivalent for consultancy.'),
('Extension','kra3_b_consultant_intl','Criterion B – International Consultant/Expert Service',10.00,100.00,'Copy of contract of service or equivalent for consultancy.'),
('Extension','kra3_b_column_occasional','Criterion B – Occasional Newspaper/Online Column',2.00,100.00,'Copy of newspaper articles or compiled articles for media writing.'),
('Extension','kra3_b_column_regular','Criterion B – Regular Column',10.00,100.00,'Copy of newspaper articles or compiled articles for media writing.'),
('Extension','kra3_b_tv_radio_host','Criterion B – Hosting TV/Radio/Online Program',10.00,100.00,'Copy of contract or invitation letter for TV/radio hosting.'),
('Extension','kra3_b_technical_guest','Criterion B – Guesting as Technical Expert',1.00,100.00,'Copy of invitation letter for guesting as technical expert.'),
('Extension','kra3_b_resource_local','Criterion B – Resource Person/Speaker, Local',2.00,100.00,'Copy of invitation letter, program, and certificate of appreciation for training/seminar conducted.'),
('Extension','kra3_b_resource_intl','Criterion B – Resource Person/Speaker, International',3.00,100.00,'Copy of invitation letter, program, and certificate of appreciation for training/seminar conducted.'),
('Extension','kra3_b_outreach_head','Criterion B – Head of Outreach/Extension Activity',5.00,100.00,'Copy of appointment from the organization/agency. Copy of proof of engagement such as certificate of participation.'),
('Extension','kra3_b_outreach_participant','Criterion B – Participant in Outreach/Extension Activity',2.00,100.00,'Copy of appointment from the organization/agency. Copy of proof of engagement such as certificate of participation.'),
('Extension','kra3_c_satisfaction','Criterion C – Client Satisfaction Rating',20.00,100.00,'Summary of satisfaction/evaluation ratings per evaluation period using prescribed template.'),
('Extension','kra3_d_president','Criterion D (BONUS) – President / OIC President',20.00,100.00,'Copy of appointment or designation with effectivity period. Copy of accomplishment report duly submitted to authorized official/supervisor.'),
('Extension','kra3_d_vp','Criterion D (BONUS) – Vice-President',15.00,100.00,'Copy of appointment or designation with effectivity period. Copy of accomplishment report duly submitted to authorized official/supervisor.'),
('Extension','kra3_d_chancellor','Criterion D (BONUS) – Chancellor',10.00,100.00,'Copy of appointment or designation with effectivity period. Copy of accomplishment report duly submitted to authorized official/supervisor.'),
('Extension','kra3_d_vice_chancellor','Criterion D (BONUS) – Vice-Chancellor',8.00,100.00,'Copy of appointment or designation with effectivity period. Copy of accomplishment report duly submitted to authorized official/supervisor.'),
('Extension','kra3_d_campus_director','Criterion D (BONUS) – Campus Director / Administrator',8.00,100.00,'Copy of appointment or designation with effectivity period. Copy of accomplishment report duly submitted to authorized official/supervisor.'),
('Extension','kra3_d_faculty_regent','Criterion D (BONUS) – Faculty Regent',8.00,100.00,'Copy of appointment or designation with effectivity period. Copy of accomplishment report duly submitted to authorized official/supervisor.'),
('Extension','kra3_d_office_director','Criterion D (BONUS) – Office Director',6.00,100.00,'Copy of appointment or designation with effectivity period. Copy of accomplishment report duly submitted to authorized official/supervisor.'),
('Extension','kra3_d_univ_secretary','Criterion D (BONUS) – University / College Secretary',6.00,100.00,'Copy of appointment or designation with effectivity period. Copy of accomplishment report duly submitted to authorized official/supervisor.'),
('Extension','kra3_d_project_head_inst','Criterion D (BONUS) – Project Head (Institutional)',4.00,100.00,'Copy of appointment or designation with effectivity period. Copy of accomplishment report duly submitted to authorized official/supervisor.'),
('Extension','kra3_d_committee_chair_inst','Criterion D (BONUS) – Committee Chair (Institutional)',3.00,100.00,'Copy of appointment or designation with effectivity period. Copy of accomplishment report duly submitted to authorized official/supervisor.'),
('Extension','kra3_d_committee_member_inst','Criterion D (BONUS) – Committee Member (Institutional)',2.00,100.00,'Copy of appointment or designation with effectivity period. Copy of accomplishment report duly submitted to authorized official/supervisor.'),
('Extension','kra3_d_dean','Criterion D (BONUS) – Dean',6.00,100.00,'Copy of appointment or designation with effectivity period. Copy of accomplishment report duly submitted to authorized official/supervisor.'),
('Extension','kra3_d_assoc_dean','Criterion D (BONUS) – Associate Dean',5.00,100.00,'Copy of appointment or designation with effectivity period. Copy of accomplishment report duly submitted to authorized official/supervisor.'),
('Extension','kra3_d_college_secretary','Criterion D (BONUS) – College Secretary',3.00,100.00,'Copy of appointment or designation with effectivity period. Copy of accomplishment report duly submitted to authorized official/supervisor.'),
('Extension','kra3_d_dept_head','Criterion D (BONUS) – Department Head',4.00,100.00,'Copy of appointment or designation with effectivity period. Copy of accomplishment report duly submitted to authorized official/supervisor.'),
('Extension','kra3_d_program_chair','Criterion D (BONUS) – Program Chair / Project Head (College/Dept)',3.00,100.00,'Copy of appointment or designation with effectivity period. Copy of accomplishment report duly submitted to authorized official/supervisor.'),
('Extension','kra3_d_committee_chair_dept','Criterion D (BONUS) – Committee Chair (College/Dept)',2.00,100.00,'Copy of appointment or designation with effectivity period. Copy of accomplishment report duly submitted to authorized official/supervisor.'),
('Extension','kra3_d_committee_member_dept','Criterion D (BONUS) – Committee Member (College/Dept)',1.00,100.00,'Copy of appointment or designation with effectivity period. Copy of accomplishment report duly submitted to authorized official/supervisor.'),

-- ── KRA IV: PROFESSIONAL DEVELOPMENT ─────────────────────────
('Professional Development','kra4_a_org_membership','Criterion A – Active Professional Org Membership',5.00,100.00,'Copy of proof of membership such as certificate of membership or ID. Copy of certification of engagement, role, or assignment from the head of the organization.'),
('Professional Development','kra4_b_postmaster_cert','Criterion B – Post-Master\'s Diploma / Certificate',10.00,100.00,'Copy of transcript of records, diploma, or certificate for educational qualifications.'),
('Professional Development','kra4_b_postdoc_cert','Criterion B – Post-Doctoral Diploma / Certificate',10.00,100.00,'Copy of transcript of records, diploma, or certificate for educational qualifications.'),
('Professional Development','kra4_b_additional_masters','Criterion B – Additional Master\'s Degree',20.00,100.00,'Copy of transcript of records, diploma, or certificate for educational qualifications.'),
('Professional Development','kra4_b_doctorate','Criterion B – Doctorate / Additional Doctorate',40.00,100.00,'Copy of transcript of records, diploma, or certificate for educational qualifications.'),
('Professional Development','kra4_b_training_local','Criterion B – Training/Conference, Local',1.00,100.00,'Copy of certificate of participation for seminars, conferences, and workshops.'),
('Professional Development','kra4_b_training_intl','Criterion B – Training/Conference, International',2.00,100.00,'Copy of certificate of participation for seminars, conferences, and workshops.'),
('Professional Development','kra4_b_paper_local','Criterion B – Paper Presentation, Local',3.00,100.00,'Copy of letter/certificate of acceptance for paper presentations.'),
('Professional Development','kra4_b_paper_intl','Criterion B – Paper Presentation, International',5.00,100.00,'Copy of letter/certificate of acceptance for paper presentations.'),
('Professional Development','kra4_c_award_institutional','Criterion C – Institutional Award',2.00,100.00,'Copy of certificate of recognition or award. Copy of picture of plaque, trophy, medal, or similar items.'),
('Professional Development','kra4_c_award_local','Criterion C – Local Award (City/Municipality/Province)',3.00,100.00,'Copy of certificate of recognition or award. Copy of picture of plaque, trophy, medal, or similar items.'),
('Professional Development','kra4_c_award_regional','Criterion C – Regional In-Country Award',4.00,100.00,'Copy of certificate of recognition or award. Copy of picture of plaque, trophy, medal, or similar items.'),
('Professional Development','kra4_c_award_national','Criterion C – National Award (Triggers +1 Sub-rank)',0.00,100.00,'Copy of certificate of recognition or award. Copy of picture of plaque, trophy, medal, or similar items.'),
('Professional Development','kra4_c_award_international','Criterion C – International Award (Triggers +1 Sub-rank)',0.00,100.00,'Copy of certificate of recognition or award. Copy of picture of plaque, trophy, medal, or similar items.'),
('Professional Development','kra4_d_academic_president','Criterion D (BONUS, New Faculty) – Academic Service as President',5.00,100.00,'Copy of service record, certificate of employment, notice of appointment or designation for academic experience.'),
('Professional Development','kra4_d_academic_vp_dean','Criterion D (BONUS, New Faculty) – Academic Service as VP/Dean/Director',4.00,100.00,'Copy of service record, certificate of employment, notice of appointment or designation for academic experience.'),
('Professional Development','kra4_d_academic_dept_head','Criterion D (BONUS, New Faculty) – Academic Service as Dept/Program Head',3.00,100.00,'Copy of service record, certificate of employment, notice of appointment or designation for academic experience.'),
('Professional Development','kra4_d_academic_faculty','Criterion D (BONUS, New Faculty) – Academic Service as Faculty Member',2.00,100.00,'Copy of service record, certificate of employment, notice of appointment or designation for academic experience.'),
('Professional Development','kra4_d_industry_managerial','Criterion D (BONUS, New Faculty) – Industry: Managerial/Supervisory',4.00,100.00,'Copy of service record, certificate of employment, or notice of appointment for industry experience.'),
('Professional Development','kra4_d_industry_technical','Criterion D (BONUS, New Faculty) – Industry: Technical/Skilled',3.00,100.00,'Copy of service record, certificate of employment, or notice of appointment for industry experience.'),
('Professional Development','kra4_d_industry_support','Criterion D (BONUS, New Faculty) – Industry: Support/Administrative Staff',2.00,100.00,'Copy of service record, certificate of employment, or notice of appointment for industry experience.')

ON DUPLICATE KEY UPDATE
    criterion_label = VALUES(criterion_label),
    max_points      = VALUES(max_points),
    weight_pct      = VALUES(weight_pct),
    description     = VALUES(description);

-- ============================================================
-- 13. PRE-EVALUATION
--     Faculty self-assessment before a cycle opens.
--     Stores KRA entries and evidence independently of any cycle.
-- ============================================================
CREATE TABLE IF NOT EXISTS pre_eval_entries (
    entry_id        INT AUTO_INCREMENT PRIMARY KEY,
    user_id         INT NOT NULL,
    kra_category    ENUM('Instruction','Research','Extension','Professional Development') NOT NULL,
    remarks         TEXT NOT NULL,
    computed_points DECIMAL(6,2) DEFAULT 0,
    notes           TEXT DEFAULT NULL,
    created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS pre_eval_files (
    file_id           INT AUTO_INCREMENT PRIMARY KEY,
    user_id           INT NOT NULL,
    entry_id          INT DEFAULT NULL,
    kra_category      ENUM('Instruction','Research','Extension','Professional Development') NOT NULL,
    file_path         VARCHAR(255) NOT NULL,
    original_filename VARCHAR(255) NOT NULL,
    file_size_bytes   INT DEFAULT 0,
    description       VARCHAR(255) DEFAULT NULL,
    uploaded_at       TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id)  REFERENCES users(user_id) ON DELETE CASCADE,
    FOREIGN KEY (entry_id) REFERENCES pre_eval_entries(entry_id) ON DELETE SET NULL
);


--     Powers the "Help" and "What's New" menu items.
--     category = 'help'     → general help articles
--     category = 'whats_new' → release notes / changelog entries
-- ============================================================
CREATE TABLE IF NOT EXISTS help_articles (
    article_id   INT AUTO_INCREMENT PRIMARY KEY,
    title        VARCHAR(255) NOT NULL,
    content      TEXT NOT NULL,
    category     ENUM('help','whats_new') NOT NULL DEFAULT 'help',
    is_published TINYINT(1) DEFAULT 1,
    created_by   INT DEFAULT NULL,
    created_at   TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at   TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (created_by) REFERENCES users(user_id) ON DELETE SET NULL
);

-- ============================================================
-- 13. FEEDBACK SUBMISSIONS
--     Powers the "Send Feedback" menu item.
--     Authenticated users (user_id set) or anonymous guests
--     (user_id NULL, contact_email provided) can submit.
-- ============================================================
CREATE TABLE IF NOT EXISTS feedback_submissions (
    feedback_id   INT AUTO_INCREMENT PRIMARY KEY,
    user_id       INT DEFAULT NULL,
    contact_email VARCHAR(150) DEFAULT NULL,
    subject       VARCHAR(255) DEFAULT NULL,
    message       TEXT NOT NULL,
    rating        TINYINT UNSIGNED DEFAULT NULL COMMENT '1–5 star rating, optional',
    status        ENUM('new','read','resolved') DEFAULT 'new',
    submitted_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    resolved_by   INT DEFAULT NULL,
    resolved_at   TIMESTAMP NULL,
    FOREIGN KEY (user_id)     REFERENCES users(user_id) ON DELETE SET NULL,
    FOREIGN KEY (resolved_by) REFERENCES users(user_id) ON DELETE SET NULL
);

-- ============================================================
-- SEED: SAMPLE HELP ARTICLES
-- ============================================================
INSERT INTO help_articles (title, content, category, created_by) VALUES
(
    'Getting Started with SUCFRMS',
    'Welcome to the SUCFRMS Faculty Reclassification Management Information System.\n\n1. Log in with your institutional email and password.\n2. Navigate to "My Application" to start or continue a reclassification application.\n3. Fill in all four KRA sections (Instruction, Research, Extension, Professional Development).\n4. Upload supporting documents for each criterion.\n5. Submit your application before the cycle deadline.\n\nFor further assistance contact your campus checker or the system administrator.',
    'help',
    NULL
),
(
    'How to Submit a Reclassification Application',
    'To submit your application:\n\n1. Open your draft application from the dashboard.\n2. Complete all required KRA entries and upload evidence files.\n3. Click "Submit Application".\n4. Your application will move to "Under Review" status.\n5. You will be notified once a checker reviews your submission.\n\nNote: You cannot edit a submitted application unless the checker requests a revision.',
    'help',
    NULL
),
(
    'Understanding Your Application Status',
    'Application statuses explained:\n\n• Draft – Not yet submitted; you can still edit.\n• Submitted – Awaiting checker review.\n• Under Review – Checker is currently reviewing.\n• Needs Revision – Checker has requested changes; you may re-edit.\n• Approved – Application endorsed by checker.\n• Reclassified – Final approval granted by admin.\n• Rejected – Application was not approved.',
    'help',
    NULL
),
(
    'What\'s New – System Launch (v1.0)',
    '• Initial release of SUCFRMS.\n• Faculty can submit reclassification applications online.\n• Checkers can review, approve, or request revisions.\n• Admin can manage cycles, users, and campus settings.\n• Automated tracking number generation for each application.\n• PDF export of completed applications.',
    'whats_new',
    NULL
)
ON DUPLICATE KEY UPDATE article_id = article_id;

-- ============================================================
-- 14. RUNTIME MIGRATIONS for JC01 s.2026 scoring columns
--     These ALTER TABLE statements are safe to re-run (IF NOT EXISTS logic
--     is handled by the orchestrator at runtime; these are here for fresh installs).
-- ============================================================
-- Add orchestrator result columns to applications
ALTER TABLE applications
    ADD COLUMN IF NOT EXISTS committee_route       VARCHAR(10)  DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS evaluation_period_ok  TINYINT(1)   DEFAULT 1,
    ADD COLUMN IF NOT EXISTS double_counting_flags TEXT         DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS pending_documentation TEXT         DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS config_incomplete     TEXT         DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS orchestrator_flags    TEXT         DEFAULT NULL;

-- Correct the panel member point values per JC01 s.2026
UPDATE scoring_criteria SET max_points = 4.00
    WHERE criterion_key = 'kra1_c_panel_masters'
      AND max_points = 2.00
      AND cycle_id IS NULL;

UPDATE scoring_criteria SET max_points = 6.00
    WHERE criterion_key = 'kra1_c_panel_doctoral'
      AND max_points = 2.00
      AND cycle_id IS NULL;

-- Reset mentorship to 0.00 (CONFIG_MENTORSHIP_POINTS not yet confirmed from JC01 p.78)
-- Admin must explicitly set this to a non-zero value after confirming with CHED-RO.
UPDATE scoring_criteria
    SET max_points  = 0.00,
        description = 'CONFIRMED SOURCE GAP: The Points column for Mentorship Services (JC01 s.2026, Section 15 item 2, p.78) is blank in the official circular — verified directly against the scanned page. Set max_points to a non-zero value only after confirming with CHED-RO or your adviser. Until set (max_points = 0), all mentorship submissions raise PENDING_DOCUMENTATION and are not scored. Hard rules: (1) regional/national/international only — local-only excluded; (2) Champion through 3rd place only — consolation prizes excluded. Required evidence: award certificate/photo, competition mechanics, org profile with prior winners list. Suggested discussion starting point: 1 pt (matching lowest confirmed Crit C rate — design recommendation, NOT sourced).'
    WHERE criterion_key = 'kra1_c_mentor_competition'
      AND cycle_id IS NULL;

-- Re-enable foreign key checks after all inserts
SET FOREIGN_KEY_CHECKS = 1;
