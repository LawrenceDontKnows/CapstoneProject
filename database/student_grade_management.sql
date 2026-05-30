-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1
-- Generation Time: Sep 30, 2025 at 07:13 AM
-- Server version: 10.4.32-MariaDB
-- PHP Version: 8.2.12

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Database: `student_grade_management`
--

-- --------------------------------------------------------

--
-- Table structure for table `login_logs`
--

CREATE TABLE `login_logs` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `role` varchar(20) NOT NULL,
  `action` varchar(50) NOT NULL,
  `description` text DEFAULT NULL,
  `timestamp` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `grades` (
  `id` int(11) NOT NULL,
  `student_id` int(11) DEFAULT NULL,
  `subject_id` int(11) DEFAULT NULL,
  `teacher_id` int(11) DEFAULT NULL,
  `prelim_quiz` decimal(5,2) DEFAULT 0.00,
  `prelim_class_standing` decimal(5,2) DEFAULT 0.00,
  `prelim_attendance` decimal(5,2) DEFAULT 0.00,
  `prelim_laboratory` decimal(5,2) DEFAULT 0.00,
  `prelim_online` decimal(5,2) DEFAULT 0.00,
  `prelim_exam` decimal(5,2) DEFAULT 0.00,
  `prelim` decimal(5,2) DEFAULT 0.00,
  `midterm_quiz` decimal(5,2) DEFAULT 0.00,
  `midterm_class_standing` decimal(5,2) DEFAULT 0.00,
  `midterm_attendance` decimal(5,2) DEFAULT 0.00,
  `midterm_laboratory` decimal(5,2) DEFAULT 0.00,
  `midterm_online` decimal(5,2) DEFAULT 0.00,
  `midterm_exam` decimal(5,2) DEFAULT 0.00,
  `midterm` decimal(5,2) DEFAULT 0.00,
  `prefinal_quiz` decimal(5,2) DEFAULT 0.00,
  `prefinal_class_standing` decimal(5,2) DEFAULT 0.00,
  `prefinal_attendance` decimal(5,2) DEFAULT 0.00,
  `prefinal_laboratory` decimal(5,2) DEFAULT 0.00,
  `prefinal_online` decimal(5,2) DEFAULT 0.00,
  `prefinal_exam` decimal(5,2) DEFAULT 0.00,
  `prefinal` decimal(5,2) DEFAULT 0.00,
  `final_quiz` decimal(5,2) DEFAULT 0.00,
  `final_class_standing` decimal(5,2) DEFAULT 0.00,
  `final_attendance` decimal(5,2) DEFAULT 0.00,
  `final_laboratory` decimal(5,2) DEFAULT 0.00,
  `final_online` decimal(5,2) DEFAULT 0.00,
  `final_exam` decimal(5,2) DEFAULT 0.00,
  `final` decimal(5,2) DEFAULT 0.00,
  `grade` decimal(5,2) NOT NULL,
  `remarks` text DEFAULT NULL,
  `graded_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `grades`
INSERT INTO `grades` (`id`, `student_id`, `subject_id`, `teacher_id`, `prelim`, `midterm`, `prefinal`, `final`, `grade`, `remarks`, `graded_at`) VALUES
(1, 3, 1, 2, 90.00, 90.00, 90.00, 90.00, 90.00, '', '2025-09-30 04:02:49');

-- --------------------------------------------------------

--
-- Table structure for table `subjects`
--

CREATE TABLE `subjects` (
  `id` int(11) NOT NULL,
  `name` varchar(100) NOT NULL,
  `code` varchar(20) NOT NULL,
  `description` text DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `subjects`
--

INSERT INTO `subjects` (`id`, `name`, `code`, `description`) VALUES
(1, 'Integrative Programming and Technologies', 'UG101', 'A course covering integrative programming and technologies'),
(2, 'Capstone 1', 'UG201', 'Capstone Project Phase 1');

-- --------------------------------------------------------

CREATE TABLE `settings` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `setting_key` varchar(50) NOT NULL,
  `setting_value` text DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `setting_key` (`setting_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

INSERT INTO `settings` (`setting_key`, `setting_value`) VALUES ('system_logo', 'image/aclc.jpg') ON DUPLICATE KEY UPDATE `setting_value` = VALUES(`setting_value`);
INSERT INTO `settings` (`setting_key`, `setting_value`) VALUES ('weight_lec', '40') ON DUPLICATE KEY UPDATE `setting_value` = VALUES(`setting_value`);
INSERT INTO `settings` (`setting_key`, `setting_value`) VALUES ('weight_lab', '60') ON DUPLICATE KEY UPDATE `setting_value` = VALUES(`setting_value`);
INSERT INTO `settings` (`setting_key`, `setting_value`) VALUES ('weight_lec_quiz', '40') ON DUPLICATE KEY UPDATE `setting_value` = VALUES(`setting_value`);
INSERT INTO `settings` (`setting_key`, `setting_value`) VALUES ('weight_lec_online', '10') ON DUPLICATE KEY UPDATE `setting_value` = VALUES(`setting_value`);
INSERT INTO `settings` (`setting_key`, `setting_value`) VALUES ('weight_lec_exam', '50') ON DUPLICATE KEY UPDATE `setting_value` = VALUES(`setting_value`);
INSERT INTO `settings` (`setting_key`, `setting_value`) VALUES ('weight_lab_att', '40') ON DUPLICATE KEY UPDATE `setting_value` = VALUES(`setting_value`);
INSERT INTO `settings` (`setting_key`, `setting_value`) VALUES ('weight_lab_cs', '10') ON DUPLICATE KEY UPDATE `setting_value` = VALUES(`setting_value`);
INSERT INTO `settings` (`setting_key`, `setting_value`) VALUES ('weight_lab_exam', '50') ON DUPLICATE KEY UPDATE `setting_value` = VALUES(`setting_value`);

CREATE TABLE `teacher_settings` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `teacher_id` int(11) NOT NULL,
  `setting_key` varchar(50) NOT NULL,
  `setting_value` varchar(255) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `teacher_key` (`teacher_id`, `setting_key`),
  CONSTRAINT `ts_teacher_fk` FOREIGN KEY (`teacher_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- --------------------------------------------------------

--
-- Table structure for table `users`
--

CREATE TABLE `users` (
  `id` int(11) NOT NULL,
  `username` varchar(50) NOT NULL,
  `password` varchar(255) NOT NULL,
  `email` varchar(100) DEFAULT NULL,
  `role` enum('admin','teacher','student') NOT NULL,
  `first_name` varchar(50) DEFAULT NULL,
  `last_name` varchar(50) DEFAULT NULL,
  `suffix` varchar(10) DEFAULT NULL,
  `age` int(11) DEFAULT NULL,
  `birth_date` date DEFAULT NULL,
  `sex` enum('Male','Female') DEFAULT NULL,
  `phone_number` varchar(15) DEFAULT NULL,
  `course` varchar(50) DEFAULT NULL,
  `year_level` varchar(20) DEFAULT NULL,
  `home_address` text DEFAULT NULL,
  `city` varchar(50) DEFAULT NULL,
  `province` varchar(50) DEFAULT NULL,
  `guardian_name` varchar(100) DEFAULT NULL,
  `guardian_occupation` varchar(100) DEFAULT NULL,
  `guardian_status` varchar(50) DEFAULT NULL,
  `guardian_phone` varchar(15) DEFAULT NULL,
  `guardian_address` text DEFAULT NULL,
  `status` enum('active','archived') NOT NULL DEFAULT 'active',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `users`
--

INSERT INTO `users` (`id`, `username`, `password`, `email`, `role`, `first_name`, `last_name`, `created_at`) VALUES
(1, 'admin', '$2y$10$M6zgd5fQozY9PjXSyQ6BDuzmcP5ksY6iOJMbXIEnP3u/1dr.29gly', 'admin@school.edu', 'admin', 'System', 'Administrator', '2025-09-30 03:54:39'),
(2, 'renzy', '$2y$10$p1p6r5e8n9z1y1A2v3C4D5E6F7G8H9I0J1K2L3M4N5O6P7Q8R9S0T', 'renzy@school.edu', 'teacher', 'Renzy', 'Instructor', '2025-09-30 07:13:00'),
(3, 'lawrence', '$2y$10$L1a2w3r4e5n6c7e8L9a8w1r2e3n4c5e6H7a8s9h0G1r2a3d4e5S6', 'lawrence@school.edu', 'student', 'Lawrence', 'Student', '2025-09-30 07:13:00');

--
-- Indexes for dumped tables
--

ALTER TABLE `grades`
  ADD PRIMARY KEY (`id`),
  ADD KEY `student_id` (`student_id`),
  ADD KEY `subject_id` (`subject_id`),
  ADD KEY `teacher_id` (`teacher_id`);

--
-- Indexes for table `subjects`
--
ALTER TABLE `subjects`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `code` (`code`);

--
-- Indexes for table `users`
--
ALTER TABLE `users`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `username` (`username`);

--
-- AUTO_INCREMENT for dumped tables
--

-- AUTO_INCREMENT for table `grades`
--
ALTER TABLE `grades`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `subjects`
--
ALTER TABLE `subjects`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT for table `users`
--
ALTER TABLE `users`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=6;

--
-- Constraints for dumped tables
--

--
-- Constraints for table `grades`
--
ALTER TABLE `grades`
  ADD CONSTRAINT `grades_ibfk_1` FOREIGN KEY (`student_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `grades_ibfk_2` FOREIGN KEY (`subject_id`) REFERENCES `subjects` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `grades_ibfk_4` FOREIGN KEY (`teacher_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
