<?php
/**
 * Database Schema - Combined SQL for all tables
 * This file is included by setup_database.php
 */

$sql = <<<SQL
-- Main schema
-- phpMyAdmin SQL Dump
-- Database: gradeview_system

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";

/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Table structure for table \`audit_log\`
--

CREATE TABLE \`audit_log\` (
  \`id\` int(11) NOT NULL,
  \`actor_id\` int(11) DEFAULT NULL,
  \`action\` varchar(50) NOT NULL,
  \`target_user_id\` int(11) DEFAULT NULL,
  \`details\` text DEFAULT NULL,
  \`timestamp\` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Table structure for table \`broadcast_templates\`
--

CREATE TABLE \`broadcast_templates\` (
  \`id\` int(11) NOT NULL,
  \`title\` varchar(255) NOT NULL,
  \`message\` text NOT NULL,
  \`role_access\` varchar(50) NOT NULL COMMENT 'e.g., registrar, teacher, all',
  \`created_at\` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Table structure for table \`grades\`
--

CREATE TABLE \`grades\` (
  \`id\` int(11) NOT NULL,
  \`student_id\` int(11) DEFAULT NULL,
  \`subject_id\` int(11) DEFAULT NULL,
  \`teacher_id\` int(11) DEFAULT NULL,
  \`prelim_quiz\` decimal(5,2) DEFAULT 0.00,
  \`prelim_cs\` decimal(5,2) DEFAULT 0.00,
  \`prelim_attendance\` decimal(5,2) DEFAULT 0.00,
  \`prelim_laboratory\` decimal(5,2) DEFAULT 0.00,
  \`prelim_online\` decimal(5,2) DEFAULT 0.00,
  \`prelim_exam\` decimal(5,2) DEFAULT 0.00,
  \`prelim\` decimal(5,2) DEFAULT 0.00,
  \`midterm_quiz\` decimal(5,2) DEFAULT 0.00,
  \`midterm_cs\` decimal(5,2) DEFAULT 0.00,
  \`midterm_attendance\` decimal(5,2) DEFAULT 0.00,
  \`midterm_laboratory\` decimal(5,2) DEFAULT 0.00,
  \`midterm_online\` decimal(5,2) DEFAULT 0.00,
  \`midterm_exam\` decimal(5,2) DEFAULT 0.00,
  \`midterm\` decimal(5,2) DEFAULT 0.00,
  \`prefinal_quiz\` decimal(5,2) DEFAULT 0.00,
  \`prefinal_cs\` decimal(5,2) DEFAULT 0.00,
  \`prefinal_attendance\` decimal(5,2) DEFAULT 0.00,
  \`prefinal_laboratory\` decimal(5,2) DEFAULT 0.00,
  \`prefinal_online\` decimal(5,2) DEFAULT 0.00,
  \`prefinal_exam\` decimal(5,2) DEFAULT 0.00,
  \`prefinal\` decimal(5,2) DEFAULT 0.00,
  \`final_quiz\` decimal(5,2) DEFAULT 0.00,
  \`final_cs\` decimal(5,2) DEFAULT 0.00,
  \`final_attendance\` decimal(5,2) DEFAULT 0.00,
  \`final_laboratory\` decimal(5,2) DEFAULT 0.00,
  \`final_online\` decimal(5,2) DEFAULT 0.00,
  \`final_exam\` decimal(5,2) DEFAULT 0.00,
  \`final\` decimal(5,2) DEFAULT 0.00,
  \`grade\` decimal(5,2) NOT NULL,
  \`remarks\` text DEFAULT NULL,
  \`graded_at\` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Table structure for table \`login_logs\`
--

CREATE TABLE \`login_logs\` (
  \`id\` int(11) NOT NULL,
  \`user_id\` int(11) NOT NULL,
  \`role\` varchar(20) NOT NULL,
  \`action\` varchar(50) NOT NULL,
  \`description\` text DEFAULT NULL,
  \`timestamp\` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Table structure for table \`notifications\`
--

CREATE TABLE \`notifications\` (
  \`id\` int(11) NOT NULL,
  \`sender_id\` int(11) NOT NULL,
  \`recipient_id\` int(11) NOT NULL,
  \`message\` text NOT NULL,
  \`is_read\` tinyint(1) NOT NULL DEFAULT 0,
  \`created_at\` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Table structure for table \`settings\`
--

CREATE TABLE \`settings\` (
  \`id\` int(11) NOT NULL,
  \`setting_key\` varchar(50) NOT NULL,
  \`setting_value\` text DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Table structure for table \`subjects\`
--

CREATE TABLE \`subjects\` (
  \`id\` int(11) NOT NULL,
  \`name\` varchar(100) NOT NULL,
  \`code\` varchar(20) NOT NULL,
  \`description\` text DEFAULT NULL,
  \`teacher_id\` int(11) DEFAULT NULL,
  \`target_course\` varchar(255) DEFAULT NULL,
  \`target_year_level\` varchar(255) DEFAULT NULL,
  \`status\` enum('open','finalized') NOT NULL DEFAULT 'open'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Table structure for table \`teacher_settings\`
--

CREATE TABLE \`teacher_settings\` (
  \`id\` int(11) NOT NULL,
  \`teacher_id\` int(11) NOT NULL,
  \`setting_key\` varchar(50) NOT NULL,
  \`setting_value\` varchar(255) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Table structure for table \`users\`
--

CREATE TABLE \`users\` (
  \`id\` int(11) NOT NULL,
  \`username\` varchar(50) NOT NULL,
  \`password\` varchar(255) NOT NULL,
  \`email\` varchar(100) DEFAULT NULL,
  \`role\` enum('admin','teacher','student') NOT NULL,
  \`first_name\` varchar(50) DEFAULT NULL,
  \`last_name\` varchar(50) DEFAULT NULL,
  \`suffix\` varchar(10) DEFAULT NULL,
  \`age\` int(11) DEFAULT NULL,
  \`birth_date\` date DEFAULT NULL,
  \`sex\` enum('Male','Female') DEFAULT NULL,
  \`phone_number\` varchar(15) DEFAULT NULL,
  \`course\` varchar(50) DEFAULT NULL,
  \`year_level\` varchar(20) DEFAULT NULL,
  \`home_address\` text DEFAULT NULL,
  \`city\` varchar(50) DEFAULT NULL,
  \`province\` varchar(50) DEFAULT NULL,
  \`guardian_name\` varchar(100) DEFAULT NULL,
  \`guardian_occupation\` varchar(100) DEFAULT NULL,
  \`guardian_status\` varchar(50) DEFAULT NULL,
  \`guardian_phone\` varchar(15) DEFAULT NULL,
  \`guardian_address\` text DEFAULT NULL,
  \`profile_picture\` varchar(255) DEFAULT 'image/default_avatar.png',
  \`status\` enum('active','archived') NOT NULL DEFAULT 'active',
  \`created_at\` timestamp NOT NULL DEFAULT current_timestamp(),
  \`email_otp\` VARCHAR(10) DEFAULT NULL,
  \`email_otp_expires\` DATETIME DEFAULT NULL,
  \`reset_otp\` VARCHAR(10) DEFAULT NULL,
  \`reset_otp_expires\` DATETIME DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Indexes
--

ALTER TABLE \`audit_log\`
  ADD PRIMARY KEY (\`id\`),
  ADD KEY \`actor_id\` (\`actor_id\`),
  ADD KEY \`target_user_id\` (\`target_user_id\`),
  ADD KEY \`idx_filters_and_sort\` (\`actor_id\`, \`action\`, \`timestamp\`),
  ADD KEY \`timestamp\` (\`timestamp\`),
  ADD KEY \`target_user_id_2\` (\`target_user_id\`),
  ADD KEY \`action\` (\`action\`);
ALTER TABLE \`audit_log\` ADD FULLTEXT KEY \`details\` (\`details\`);

ALTER TABLE \`broadcast_templates\`
  ADD PRIMARY KEY (\`id\`);

ALTER TABLE \`grades\`
  ADD PRIMARY KEY (\`id\`),
  ADD UNIQUE KEY \`enrollment_unique\` (\`student_id\`, \`subject_id\`),
  ADD KEY \`student_id\` (\`student_id\`),
  ADD KEY \`subject_id\` (\`subject_id\`),
  ADD KEY \`teacher_id\` (\`teacher_id\`);

ALTER TABLE \`login_logs\`
  ADD PRIMARY KEY (\`id\`),
  ADD KEY \`user_id\` (\`user_id\`);

ALTER TABLE \`notifications\`
  ADD PRIMARY KEY (\`id\`),
  ADD KEY \`recipient_id\` (\`recipient_id\`),
  ADD KEY \`sender_id\` (\`sender_id\`);

ALTER TABLE \`settings\`
  ADD PRIMARY KEY (\`id\`),
  ADD UNIQUE KEY \`setting_key\` (\`setting_key\`);

ALTER TABLE \`subjects\`
  ADD PRIMARY KEY (\`id\`),
  ADD UNIQUE KEY \`code\` (\`code\`),
  ADD UNIQUE KEY \`name\` (\`name\`),
  ADD KEY \`teacher_id\` (\`teacher_id\`);

ALTER TABLE \`teacher_settings\`
  ADD PRIMARY KEY (\`id\`),
  ADD UNIQUE KEY \`teacher_key\` (\`teacher_id\`, \`setting_key\`);

ALTER TABLE \`users\`
  ADD PRIMARY KEY (\`id\`),
  ADD UNIQUE KEY \`username\` (\`username\`),
  ADD UNIQUE KEY \`email\` (\`email\`),
  ADD KEY \`username_2\` (\`username\`),
  ADD KEY \`idx_email_otp\` (\`email_otp\`),
  ADD KEY \`idx_reset_otp\` (\`reset_otp\`);

--
-- AUTO_INCREMENT
--

ALTER TABLE \`audit_log\`
  MODIFY \`id\` int(11) NOT NULL AUTO_INCREMENT;

ALTER TABLE \`broadcast_templates\`
  MODIFY \`id\` int(11) NOT NULL AUTO_INCREMENT;

ALTER TABLE \`grades\`
  MODIFY \`id\` int(11) NOT NULL AUTO_INCREMENT;

ALTER TABLE \`login_logs\`
  MODIFY \`id\` int(11) NOT NULL AUTO_INCREMENT;

ALTER TABLE \`notifications\`
  MODIFY \`id\` int(11) NOT NULL AUTO_INCREMENT;

ALTER TABLE \`settings\`
  MODIFY \`id\` int(11) NOT NULL AUTO_INCREMENT;

ALTER TABLE \`subjects\`
  MODIFY \`id\` int(11) NOT NULL AUTO_INCREMENT;

ALTER TABLE \`teacher_settings\`
  MODIFY \`id\` int(11) NOT NULL AUTO_INCREMENT;

ALTER TABLE \`users\`
  MODIFY \`id\` int(11) NOT NULL AUTO_INCREMENT;

--
-- Constraints
--

ALTER TABLE \`audit_log\`
  ADD CONSTRAINT \`audit_log_actor_fk\` FOREIGN KEY (\`actor_id\`) REFERENCES \`users\` (\`id\`) ON DELETE SET NULL,
  ADD CONSTRAINT \`audit_log_target_fk\` FOREIGN KEY (\`target_user_id\`) REFERENCES \`users\` (\`id\`) ON DELETE SET NULL;

ALTER TABLE \`grades\`
  ADD CONSTRAINT \`grades_student_fk\` FOREIGN KEY (\`student_id\`) REFERENCES \`users\` (\`id\`) ON DELETE CASCADE,
  ADD CONSTRAINT \`grades_subject_fk\` FOREIGN KEY (\`subject_id\`) REFERENCES \`subjects\` (\`id\`) ON DELETE CASCADE,
  ADD CONSTRAINT \`grades_teacher_fk\` FOREIGN KEY (\`teacher_id\`) REFERENCES \`users\` (\`id\`) ON DELETE SET NULL;

ALTER TABLE \`login_logs\`
  ADD CONSTRAINT \`login_logs_user_fk\` FOREIGN KEY (\`user_id\`) REFERENCES \`users\` (\`id\`) ON DELETE CASCADE;

ALTER TABLE \`notifications\`
  ADD CONSTRAINT \`notifications_recipient_fk\` FOREIGN KEY (\`recipient_id\`) REFERENCES \`users\` (\`id\`) ON DELETE CASCADE,
  ADD CONSTRAINT \`notifications_sender_fk\` FOREIGN KEY (\`sender_id\`) REFERENCES \`users\` (\`id\`) ON DELETE CASCADE;

ALTER TABLE \`subjects\`
  ADD CONSTRAINT \`subjects_teacher_fk\` FOREIGN KEY (\`teacher_id\`) REFERENCES \`users\` (\`id\`) ON DELETE SET NULL;

ALTER TABLE \`teacher_settings\`
  ADD CONSTRAINT \`ts_teacher_fk\` FOREIGN KEY (\`teacher_id\`) REFERENCES \`users\` (\`id\`) ON DELETE CASCADE;

COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;

-- SMTP Settings
INSERT IGNORE INTO settings (setting_key, setting_value) VALUES
('smtp_from_email', ''),
('smtp_from_name', 'GradeView'),
('smtp_host', 'smtp.gmail.com'),
('smtp_port', '587'),
('smtp_username', ''),
('smtp_password', ''),
('smtp_encryption', 'tls');

-- Slideshow & Background Settings (defaults)
INSERT IGNORE INTO settings (setting_key, setting_value) VALUES
('login_slideshow_enabled', '1'),
('login_slideshow_interval', '5000'),
('login_slideshow_opacity', '0.5'),
('login_form_glass', '1'),
('login_slideshow_image_1', ''),
('login_slideshow_image_2', ''),
('login_slideshow_image_3', ''),
('register_bg_type', 'color'),
('register_bg_color', '#1a2a4a'),
('register_bg_image', ''),
('register_bg_opacity', '1');



-- ============================================
-- Grade Sheet Module (additive)
-- ============================================

-- Grade Sheet Module Migration (additive)
-- Safe to run multiple times where possible (IF NOT EXISTS / checks handled in PHP migrator)

CREATE TABLE IF NOT EXISTS `academic_years` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(50) NOT NULL,
  `start_date` date DEFAULT NULL,
  `end_date` date DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_academic_year_name` (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `semesters` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `academic_year_id` int(11) DEFAULT NULL,
  `name` varchar(50) NOT NULL,
  `start_date` date DEFAULT NULL,
  `end_date` date DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_semesters_academic_year_id` (`academic_year_id`),
  CONSTRAINT `fk_semesters_academic_year` FOREIGN KEY (`academic_year_id`) REFERENCES `academic_years` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `sections` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(100) NOT NULL,
  `course` varchar(100) DEFAULT NULL,
  `year_level` varchar(50) DEFAULT NULL,
  `academic_year_id` int(11) DEFAULT NULL,
  `semester_id` int(11) DEFAULT NULL,
  `adviser_id` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_sections_academic_year_id` (`academic_year_id`),
  KEY `idx_sections_semester_id` (`semester_id`),
  KEY `idx_sections_adviser_id` (`adviser_id`),
  CONSTRAINT `fk_sections_academic_year` FOREIGN KEY (`academic_year_id`) REFERENCES `academic_years` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_sections_semester` FOREIGN KEY (`semester_id`) REFERENCES `semesters` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_sections_adviser` FOREIGN KEY (`adviser_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `subject_offerings` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `subject_id` int(11) NOT NULL,
  `teacher_id` int(11) DEFAULT NULL,
  `section_id` int(11) DEFAULT NULL,
  `academic_year_id` int(11) DEFAULT NULL,
  `semester_id` int(11) DEFAULT NULL,
  `schedule` varchar(255) DEFAULT NULL,
  `room` varchar(100) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_offerings_subject_id` (`subject_id`),
  KEY `idx_offerings_teacher_id` (`teacher_id`),
  KEY `idx_offerings_section_id` (`section_id`),
  KEY `idx_offerings_academic_year_id` (`academic_year_id`),
  KEY `idx_offerings_semester_id` (`semester_id`),
  CONSTRAINT `fk_offerings_subject` FOREIGN KEY (`subject_id`) REFERENCES `subjects` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_offerings_teacher` FOREIGN KEY (`teacher_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_offerings_section` FOREIGN KEY (`section_id`) REFERENCES `sections` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_offerings_academic_year` FOREIGN KEY (`academic_year_id`) REFERENCES `academic_years` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_offerings_semester` FOREIGN KEY (`semester_id`) REFERENCES `semesters` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `grade_sheets` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `subject_id` int(11) NOT NULL,
  `teacher_id` int(11) NOT NULL,
  `subject_offering_id` int(11) DEFAULT NULL,
  `academic_year_id` int(11) DEFAULT NULL,
  `semester_id` int(11) DEFAULT NULL,
  `section_id` int(11) DEFAULT NULL,
  `title` varchar(150) NOT NULL,
  `status` enum('draft','submitted','finalized','returned') NOT NULL DEFAULT 'draft',
  `grading_scale` text DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `submitted_at` datetime DEFAULT NULL,
  `finalized_at` datetime DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_grade_sheets_subject_id` (`subject_id`),
  KEY `idx_grade_sheets_teacher_id` (`teacher_id`),
  KEY `idx_grade_sheets_offering_id` (`subject_offering_id`),
  KEY `idx_grade_sheets_section_id` (`section_id`),
  KEY `idx_grade_sheets_status` (`status`),
  CONSTRAINT `fk_grade_sheets_subject` FOREIGN KEY (`subject_id`) REFERENCES `subjects` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_grade_sheets_teacher` FOREIGN KEY (`teacher_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_grade_sheets_offering` FOREIGN KEY (`subject_offering_id`) REFERENCES `subject_offerings` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_grade_sheets_academic_year` FOREIGN KEY (`academic_year_id`) REFERENCES `academic_years` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_grade_sheets_semester` FOREIGN KEY (`semester_id`) REFERENCES `semesters` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_grade_sheets_section` FOREIGN KEY (`section_id`) REFERENCES `sections` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `grade_components` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `grade_sheet_id` int(11) NOT NULL,
  `name` varchar(100) NOT NULL,
  `component_type` varchar(50) NOT NULL DEFAULT 'score',
  `max_score` decimal(7,2) NOT NULL DEFAULT 100.00,
  `weight` decimal(7,2) NOT NULL DEFAULT 0.00,
  `sort_order` int(11) NOT NULL DEFAULT 0,
  `is_computed` tinyint(1) NOT NULL DEFAULT 0,
  `formula` varchar(255) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_components_grade_sheet_id` (`grade_sheet_id`),
  CONSTRAINT `fk_components_grade_sheet` FOREIGN KEY (`grade_sheet_id`) REFERENCES `grade_sheets` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `grade_sheet_students` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `grade_sheet_id` int(11) NOT NULL,
  `student_id` int(11) NOT NULL,
  `row_order` int(11) NOT NULL DEFAULT 0,
  `remarks` varchar(255) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_grade_sheet_student` (`grade_sheet_id`,`student_id`),
  KEY `idx_gss_student_id` (`student_id`),
  CONSTRAINT `fk_gss_grade_sheet` FOREIGN KEY (`grade_sheet_id`) REFERENCES `grade_sheets` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_gss_student` FOREIGN KEY (`student_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `student_grades` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `grade_sheet_id` int(11) NOT NULL,
  `student_id` int(11) NOT NULL,
  `component_id` int(11) NOT NULL,
  `raw_score` decimal(7,2) DEFAULT NULL,
  `weighted_score` decimal(7,2) DEFAULT NULL,
  `percentage_score` decimal(7,2) DEFAULT NULL,
  `remarks` varchar(255) DEFAULT NULL,
  `updated_by` int(11) DEFAULT NULL,
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_student_grade_cell` (`grade_sheet_id`,`student_id`,`component_id`),
  KEY `idx_student_grades_student_id` (`student_id`),
  KEY `idx_student_grades_component_id` (`component_id`),
  CONSTRAINT `fk_student_grades_sheet` FOREIGN KEY (`grade_sheet_id`) REFERENCES `grade_sheets` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_student_grades_student` FOREIGN KEY (`student_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_student_grades_component` FOREIGN KEY (`component_id`) REFERENCES `grade_components` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_student_grades_updated_by` FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `finalized_grades` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `grade_sheet_id` int(11) NOT NULL,
  `student_id` int(11) NOT NULL,
  `final_numeric` decimal(7,2) DEFAULT NULL,
  `final_transmuted` decimal(7,2) DEFAULT NULL,
  `grade_point` varchar(10) DEFAULT NULL,
  `remarks` varchar(100) DEFAULT NULL,
  `is_released` tinyint(1) NOT NULL DEFAULT 0,
  `finalized_by` int(11) DEFAULT NULL,
  `finalized_at` datetime DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_finalized_grade` (`grade_sheet_id`,`student_id`),
  KEY `idx_finalized_student_id` (`student_id`),
  CONSTRAINT `fk_finalized_grade_sheet` FOREIGN KEY (`grade_sheet_id`) REFERENCES `grade_sheets` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_finalized_student` FOREIGN KEY (`student_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_finalized_by` FOREIGN KEY (`finalized_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `grade_change_logs` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `grade_sheet_id` int(11) NOT NULL,
  `student_id` int(11) DEFAULT NULL,
  `component_id` int(11) DEFAULT NULL,
  `actor_id` int(11) DEFAULT NULL,
  `action` varchar(50) NOT NULL,
  `old_value` text DEFAULT NULL,
  `new_value` text DEFAULT NULL,
  `details` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_gcl_grade_sheet_id` (`grade_sheet_id`),
  KEY `idx_gcl_student_id` (`student_id`),
  KEY `idx_gcl_component_id` (`component_id`),
  KEY `idx_gcl_actor_id` (`actor_id`),
  CONSTRAINT `fk_gcl_grade_sheet` FOREIGN KEY (`grade_sheet_id`) REFERENCES `grade_sheets` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_gcl_student` FOREIGN KEY (`student_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_gcl_component` FOREIGN KEY (`component_id`) REFERENCES `grade_components` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_gcl_actor` FOREIGN KEY (`actor_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

SQL;

return $sql;
