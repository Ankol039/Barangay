-- ============================================================
-- Barangay Health Center Survey Management System
-- Database Schema
-- ============================================================

DROP DATABASE IF EXISTS barangay_survey_system;
CREATE DATABASE barangay_survey_system;
USE barangay_survey_system;

-- ------------------------------------------------------------
-- Residents
-- ------------------------------------------------------------
CREATE TABLE residents (
    resident_id      INT AUTO_INCREMENT PRIMARY KEY,
    resident_number  VARCHAR(20)  NOT NULL UNIQUE,
    first_name       VARCHAR(50)  NOT NULL,
    last_name        VARCHAR(50)  NOT NULL,
    email            VARCHAR(100),
    contact_number   VARCHAR(20),
    address          VARCHAR(255),
    password         VARCHAR(255) NOT NULL,
    is_first_login   TINYINT(1)   NOT NULL DEFAULT 1,
    created_at       TIMESTAMP    DEFAULT CURRENT_TIMESTAMP
);

-- ------------------------------------------------------------
-- Staff / Admin
-- ------------------------------------------------------------
CREATE TABLE staff (
    staff_id     INT AUTO_INCREMENT PRIMARY KEY,
    username     VARCHAR(50)  NOT NULL UNIQUE,
    full_name    VARCHAR(100) NOT NULL,
    password     VARCHAR(255) NOT NULL,
    role         VARCHAR(30)  NOT NULL DEFAULT 'staff',
    created_at   TIMESTAMP    DEFAULT CURRENT_TIMESTAMP
);

-- ------------------------------------------------------------
-- Surveys
-- ------------------------------------------------------------
CREATE TABLE surveys (
    survey_id    INT AUTO_INCREMENT PRIMARY KEY,
    title        VARCHAR(150) NOT NULL,
    description  TEXT,
    start_date   DATE NOT NULL,
    end_date     DATE NOT NULL,
    status       ENUM('active','inactive') NOT NULL DEFAULT 'active',
    created_by   INT,
    created_at   TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (created_by) REFERENCES staff(staff_id) ON DELETE SET NULL
);

-- ------------------------------------------------------------
-- Survey Questions
-- ------------------------------------------------------------
CREATE TABLE survey_questions (
    question_id     INT AUTO_INCREMENT PRIMARY KEY,
    survey_id       INT NOT NULL,
    question_text   VARCHAR(255) NOT NULL,
    question_type   ENUM('multiple_choice','yesno','rating','short_answer') NOT NULL,
    question_order  INT NOT NULL DEFAULT 0,
    FOREIGN KEY (survey_id) REFERENCES surveys(survey_id) ON DELETE CASCADE
);

-- ------------------------------------------------------------
-- Survey Choices (for multiple_choice questions)
-- ------------------------------------------------------------
CREATE TABLE survey_choices (
    choice_id    INT AUTO_INCREMENT PRIMARY KEY,
    question_id  INT NOT NULL,
    choice_text  VARCHAR(150) NOT NULL,
    FOREIGN KEY (question_id) REFERENCES survey_questions(question_id) ON DELETE CASCADE
);

-- ------------------------------------------------------------
-- Responses (one row = one resident's submission of one survey)
-- ------------------------------------------------------------
CREATE TABLE responses (
    response_id   INT AUTO_INCREMENT PRIMARY KEY,
    survey_id     INT NOT NULL,
    resident_id   INT NOT NULL,
    submitted_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY unique_response (survey_id, resident_id),
    FOREIGN KEY (survey_id) REFERENCES surveys(survey_id) ON DELETE CASCADE,
    FOREIGN KEY (resident_id) REFERENCES residents(resident_id) ON DELETE CASCADE
);

-- ------------------------------------------------------------
-- Survey Results / Answers (one row per answered question)
-- ------------------------------------------------------------
CREATE TABLE survey_results (
    answer_id      INT AUTO_INCREMENT PRIMARY KEY,
    response_id    INT NOT NULL,
    question_id    INT NOT NULL,
    choice_id      INT NULL,
    rating_value   INT NULL,
    answer_text    TEXT NULL,
    FOREIGN KEY (response_id) REFERENCES responses(response_id) ON DELETE CASCADE,
    FOREIGN KEY (question_id) REFERENCES survey_questions(question_id) ON DELETE CASCADE,
    FOREIGN KEY (choice_id) REFERENCES survey_choices(choice_id) ON DELETE SET NULL
);

-- ------------------------------------------------------------
-- Login History (optional)
-- ------------------------------------------------------------
CREATE TABLE login_history (
    log_id       INT AUTO_INCREMENT PRIMARY KEY,
    user_type    ENUM('resident','staff') NOT NULL,
    user_id      INT NOT NULL,
    login_time   TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    ip_address   VARCHAR(50)
);

-- ============================================================
-- Seed data
-- ============================================================

-- Default staff account -> username: admin | password: admin123
INSERT INTO staff (username, full_name, password, role) VALUES
('admin', 'Barangay Health Officer', '$2b$10$/NiGXWpb7mkGcnZOJ9rmjOG2Xi338wJ4fVfB/VlXeNB7mnHvfrBV.', 'admin');
-- NOTE: hash above corresponds to the plaintext password: admin123

-- Sample residents -> username = resident_number, default password = resident_number
INSERT INTO residents (resident_number, first_name, last_name, email, contact_number, address, password, is_first_login) VALUES
('2026-0001', 'Juan', 'Dela Cruz', 'juan.delacruz@example.com', '09171234567', 'Purok 1, Barangay Sample', '$2b$10$YmR1b9sGlsRXYT46JMD73eJLA6HCpS4O6P11SbRmhU/3.ZZsV1fVC', 1),
('2026-0002', 'Maria', 'Santos', 'maria.santos@example.com', '09181234567', 'Purok 2, Barangay Sample', '$2b$10$QgM8QJ.PhPkZfEWLJM1lTOvxYl6rSD.mOafdfn/a.VhodLc9jasl6', 1);
-- Password for 2026-0001 is: 2026-0001 | Password for 2026-0002 is: 2026-0002
-- Additional residents can be registered via Staff > Resident Management, which hashes
-- the resident number automatically using PHP's password_hash().
