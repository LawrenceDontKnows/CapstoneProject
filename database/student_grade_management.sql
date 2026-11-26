-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1
-- Generation Time: Jul 17, 2026 at 01:42 AM
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
-- Table structure for table `audit_log`
--

CREATE TABLE `audit_log` (
  `id` int(11) NOT NULL,
  `actor_id` int(11) DEFAULT NULL,
  `action` varchar(50) NOT NULL,
  `target_user_id` int(11) DEFAULT NULL,
  `details` text DEFAULT NULL,
  `timestamp` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `audit_log`
--

INSERT INTO `audit_log` (`id`, `actor_id`, `action`, `target_user_id`, `details`, `timestamp`) VALUES
(14, 1, 'user_archive', 5, 'Archived user \'lawrence\'.', '2026-07-16 00:03:03'),
(15, 1, 'user_restore', 5, 'Restored user \'lawrence\' from archive.', '2026-07-16 00:03:10'),
(16, 1, 'user_create', 6, 'Created new user \'antho\' with role \'teacher\'.', '2026-07-16 08:51:59');

-- --------------------------------------------------------

--
-- Table structure for table `broadcast_templates`
--

CREATE TABLE `broadcast_templates` (
  `id` int(11) NOT NULL,
  `title` varchar(255) NOT NULL,
  `message` text NOT NULL,
  `role_access` varchar(50) NOT NULL COMMENT 'e.g., registrar, teacher, all',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `broadcast_templates`
--

INSERT INTO `broadcast_templates` (`id`, `title`, `message`, `role_access`, `created_at`) VALUES
(1, 'Grade Release', 'All grades for the current period have been released. Please check your portal.', 'admin', '2026-07-15 14:18:58'),
(2, 'Enrollment Open', 'The enrollment period for the upcoming semester is now open. Please complete your enrollment by [Date].', 'admin', '2026-07-15 14:18:58'),
(3, 'Holiday', 'Please be advised that there will be no classes on [Date] in observance of [Holiday].', 'admin', '2026-07-15 14:18:58'),
(4, 'Maintenance', 'The portal will be down for scheduled maintenance on [Date] at [Time]. We apologize for any inconvenience.', 'all', '2026-07-15 14:18:58'),
(5, 'Class Canceled', 'Please be advised that our class for today, [Date], has been canceled. An asynchronous activity will be provided.', 'teacher', '2026-07-15 14:18:58'),
(6, 'Assignment Reminder', 'This is a reminder that your assignment on [Topic] is due on [Date]. Please submit it on time.', 'teacher', '2026-07-15 14:18:58'),
(7, 'Exam Schedule', 'Heads up! Our upcoming [Period] exam is scheduled for [Date] at [Time].', 'teacher', '2026-07-15 14:18:58'),
(8, 'Consultation Hours', 'My consultation hours for this week are on [Day] from [Time Start] to [Time End]. Feel free to approach me with any concerns.', 'teacher', '2026-07-15 14:18:58'),
(9, 'General Announcement', 'Important: Please check the school website for recent announcements regarding [Topic].', 'all', '2026-07-15 14:28:57'),
(10, 'System Update', 'The system will undergo a minor update on [Date] at [Time]. Expect brief interruptions.', 'all', '2026-07-15 14:28:57'),
(11, ' Scheduled Maintenance: System Upgrade', 'We are performing scheduled maintenance on [System Name] on [Date] from [Time] to improve overall system performance. Sorry for the inconvenience.', 'all', '2026-07-15 15:27:10');

-- --------------------------------------------------------

--
-- Table structure for table `grades`
--

CREATE TABLE `grades` (
  `id` int(11) NOT NULL,
  `student_id` int(11) DEFAULT NULL,
  `subject_id` int(11) DEFAULT NULL,
  `teacher_id` int(11) DEFAULT NULL,
  `prelim_quiz` decimal(5,2) DEFAULT 0.00,
  `prelim_cs` decimal(5,2) DEFAULT 0.00,
  `prelim_attendance` decimal(5,2) DEFAULT 0.00,
  `prelim_laboratory` decimal(5,2) DEFAULT 0.00,
  `prelim_online` decimal(5,2) DEFAULT 0.00,
  `prelim_exam` decimal(5,2) DEFAULT 0.00,
  `prelim` decimal(5,2) DEFAULT 0.00,
  `midterm_quiz` decimal(5,2) DEFAULT 0.00,
  `midterm_cs` decimal(5,2) DEFAULT 0.00,
  `midterm_attendance` decimal(5,2) DEFAULT 0.00,
  `midterm_laboratory` decimal(5,2) DEFAULT 0.00,
  `midterm_online` decimal(5,2) DEFAULT 0.00,
  `midterm_exam` decimal(5,2) DEFAULT 0.00,
  `midterm` decimal(5,2) DEFAULT 0.00,
  `prefinal_quiz` decimal(5,2) DEFAULT 0.00,
  `prefinal_cs` decimal(5,2) DEFAULT 0.00,
  `prefinal_attendance` decimal(5,2) DEFAULT 0.00,
  `prefinal_laboratory` decimal(5,2) DEFAULT 0.00,
  `prefinal_online` decimal(5,2) DEFAULT 0.00,
  `prefinal_exam` decimal(5,2) DEFAULT 0.00,
  `prefinal` decimal(5,2) DEFAULT 0.00,
  `final_quiz` decimal(5,2) DEFAULT 0.00,
  `final_cs` decimal(5,2) DEFAULT 0.00,
  `final_attendance` decimal(5,2) DEFAULT 0.00,
  `final_laboratory` decimal(5,2) DEFAULT 0.00,
  `final_online` decimal(5,2) DEFAULT 0.00,
  `final_exam` decimal(5,2) DEFAULT 0.00,
  `final` decimal(5,2) DEFAULT 0.00,
  `grade` decimal(5,2) NOT NULL,
  `remarks` text DEFAULT NULL,
  `graded_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `grades`
--

INSERT INTO `grades` (`id`, `student_id`, `subject_id`, `teacher_id`, `prelim_quiz`, `prelim_cs`, `prelim_attendance`, `prelim_laboratory`, `prelim_online`, `prelim_exam`, `prelim`, `midterm_quiz`, `midterm_cs`, `midterm_attendance`, `midterm_laboratory`, `midterm_online`, `midterm_exam`, `midterm`, `prefinal_quiz`, `prefinal_cs`, `prefinal_attendance`, `prefinal_laboratory`, `prefinal_online`, `prefinal_exam`, `prefinal`, `final_quiz`, `final_cs`, `final_attendance`, `final_laboratory`, `final_online`, `final_exam`, `final`, `grade`, `remarks`, `graded_at`) VALUES
(1, 5, 1, 4, 100.00, 0.00, 0.00, 0.00, 0.00, 100.00, 66.00, 0.00, 0.00, 0.00, 0.00, 0.00, 0.00, 0.00, 0.00, 0.00, 0.00, 0.00, 0.00, 0.00, 0.00, 0.00, 0.00, 0.00, 0.00, 0.00, 0.00, 0.00, 13.20, NULL, '2026-07-15 08:59:51'),
(2, 5, 2, 6, 70.00, 10.00, 0.00, 0.00, 10.00, 100.00, 61.60, 0.00, 0.00, 0.00, 0.00, 0.00, 0.00, 0.00, 0.00, 0.00, 0.00, 0.00, 0.00, 0.00, 0.00, 0.00, 0.00, 0.00, 0.00, 0.00, 0.00, 0.00, 12.32, NULL, '2026-07-16 08:53:56');

-- --------------------------------------------------------

--
-- Table structure for table `login_logs`
--

CREATE TABLE `login_logs` (
  `id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `role` varchar(20) NOT NULL,
  `action` varchar(50) NOT NULL,
  `description` text DEFAULT NULL,
  `timestamp` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `login_logs`
--

INSERT INTO `login_logs` (`id`, `user_id`, `role`, `action`, `description`, `timestamp`) VALUES
(1, 1, 'admin', 'login', 'Successfully signed in', '2026-07-15 08:07:59'),
(2, 1, 'admin', 'login', 'Successfully signed in', '2026-07-15 08:11:16'),
(3, 1, 'admin', 'login', 'Successfully signed in', '2026-07-15 08:13:56'),
(4, 4, 'teacher', 'login', 'Successfully signed in', '2026-07-15 08:50:06'),
(5, 1, 'admin', 'login', 'Successfully signed in', '2026-07-15 08:50:40'),
(6, 5, 'student', 'login', 'Successfully signed in', '2026-07-15 08:52:25'),
(7, 4, 'teacher', 'login', 'Successfully signed in', '2026-07-15 08:56:39'),
(8, 5, 'student', 'login', 'Successfully signed in', '2026-07-15 11:53:29'),
(9, 1, 'admin', 'login', 'Successfully signed in', '2026-07-15 13:45:41'),
(10, 4, 'teacher', 'login', 'Successfully signed in', '2026-07-15 13:53:04'),
(11, 5, 'student', 'login', 'Successfully signed in', '2026-07-15 14:31:02'),
(12, 4, 'teacher', 'login', 'Successfully signed in', '2026-07-15 14:37:11'),
(13, 1, 'admin', 'login', 'Successfully signed in', '2026-07-16 00:02:55'),
(14, 1, 'admin', 'login', 'Successfully signed in', '2026-07-16 03:39:31'),
(15, 4, 'teacher', 'login', 'Successfully signed in', '2026-07-16 03:40:35'),
(16, 5, 'student', 'login', 'Successfully signed in', '2026-07-16 03:43:03'),
(17, 4, 'teacher', 'login', 'Successfully signed in', '2026-07-16 03:44:53'),
(18, 1, 'admin', 'login', 'Successfully signed in', '2026-07-16 04:57:24'),
(19, 4, 'teacher', 'login', 'Successfully signed in', '2026-07-16 05:22:37'),
(20, 5, 'student', 'login', 'Successfully signed in', '2026-07-16 06:07:42'),
(21, 4, 'teacher', 'login', 'Successfully signed in', '2026-07-16 08:24:59'),
(22, 1, 'admin', 'login', 'Successfully signed in', '2026-07-16 08:25:31'),
(23, 6, 'teacher', 'login', 'Successfully signed in', '2026-07-16 08:52:17'),
(24, 5, 'student', 'login', 'Successfully signed in', '2026-07-16 08:54:47'),
(25, 6, 'teacher', 'login', 'Successfully signed in', '2026-07-16 08:55:47');

-- --------------------------------------------------------

--
-- Table structure for table `notifications`
--

CREATE TABLE `notifications` (
  `id` int(11) NOT NULL,
  `sender_id` int(11) NOT NULL,
  `recipient_id` int(11) NOT NULL,
  `message` text NOT NULL,
  `is_read` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `notifications`
--

INSERT INTO `notifications` (`id`, `sender_id`, `recipient_id`, `message`, `is_read`, `created_at`) VALUES
(3, 4, 5, 'Please be advised that our class for today, [Date], has been canceled. An asynchronous activity will be provided.', 1, '2026-07-15 14:48:56'),
(4, 4, 5, 'Please be advised that our class for today, July 23, 2026, has been canceled. An asynchronous activity will be provided.', 1, '2026-07-15 14:49:15'),
(5, 4, 5, 'The system will undergo a minor update on [Date] at [Time]. Expect brief interruptions.', 1, '2026-07-15 14:55:00'),
(6, 1, 4, 'The system will undergo a minor update on July 15, 2026 at 12:00 PM. Expect brief interruptions.', 1, '2026-07-15 14:56:12'),
(7, 1, 4, 'The system will undergo a minor update on July 15, 2026 at 12:00 PM. Expect brief interruptions.', 1, '2026-07-15 15:23:17'),
(8, 1, 4, 'The system will undergo a minor update on July 22, 2026 at 10:00 PM. Expect brief interruptions.', 1, '2026-07-15 15:27:41'),
(9, 1, 4, 'The portal will be down for scheduled maintenance on July 15, 2026 at 1:00 PM. We apologize for any inconvenience.', 1, '2026-07-15 15:28:19'),
(10, 1, 1, 'We are performing scheduled maintenance on [System Name] on July 16, 2026 from 12:00 PM to improve overall system performance. Sorry for the inconvenience.', 0, '2026-07-16 00:27:49'),
(11, 1, 4, 'We are performing scheduled maintenance on [System Name] on July 16, 2026 from 12:00 PM to improve overall system performance. Sorry for the inconvenience.', 1, '2026-07-16 00:27:49'),
(12, 1, 5, 'We are performing scheduled maintenance on [System Name] on July 16, 2026 from 12:00 PM to improve overall system performance. Sorry for the inconvenience.', 1, '2026-07-16 00:27:49'),
(13, 1, 1, 'Please be advised that there will be no classes on July 31, 2026 in observance of [Holiday].', 0, '2026-07-16 03:40:18'),
(14, 1, 4, 'Please be advised that there will be no classes on July 31, 2026 in observance of [Holiday].', 1, '2026-07-16 03:40:18'),
(15, 1, 5, 'Please be advised that there will be no classes on July 31, 2026 in observance of [Holiday].', 1, '2026-07-16 03:40:18'),
(16, 1, 1, 'We are performing scheduled maintenance on [System Name] on July 16, 2026 from 12:00 PM to improve overall system performance. Sorry for the inconvenience.', 0, '2026-07-16 03:44:18'),
(17, 1, 4, 'We are performing scheduled maintenance on [System Name] on July 16, 2026 from 12:00 PM to improve overall system performance. Sorry for the inconvenience.', 1, '2026-07-16 03:44:18'),
(18, 1, 5, 'We are performing scheduled maintenance on [System Name] on July 16, 2026 from 12:00 PM to improve overall system performance. Sorry for the inconvenience.', 1, '2026-07-16 03:44:18'),
(19, 6, 5, 'My consultation hours for this week are on July 16, 2026 from 12:00 PM. Feel free to approach me with any concerns.', 0, '2026-07-16 09:03:56');

-- --------------------------------------------------------

--
-- Table structure for table `settings`
--

CREATE TABLE `settings` (
  `id` int(11) NOT NULL,
  `setting_key` varchar(50) NOT NULL,
  `setting_value` text DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `settings`
--

INSERT INTO `settings` (`id`, `setting_key`, `setting_value`) VALUES
(1, 'system_logo', 'image/system_logo_1784105627.jpg'),
(2, 'weight_lec', '40'),
(3, 'weight_lab', '60'),
(4, 'weight_lec_quiz', '40'),
(5, 'weight_lec_online', '10'),
(6, 'weight_lec_exam', '50'),
(7, 'weight_lab_att', '10'),
(8, 'weight_lab_cs', '40'),
(9, 'weight_lab_exam', '50'),
(12, 'session_date_1_prelim_1', ''),
(13, 'session_date_1_prelim_2', ''),
(14, 'session_date_1_prelim_3', ''),
(15, 'session_date_1_prelim_4', ''),
(16, 'session_date_1_prelim_5', ''),
(21, 'session_date_2_prelim_1', ''),
(22, 'session_date_2_prelim_2', ''),
(25, 'session_date_2_prelim_5', '');

-- --------------------------------------------------------

--
-- Table structure for table `subjects`
--

CREATE TABLE `subjects` (
  `id` int(11) NOT NULL,
  `name` varchar(100) NOT NULL,
  `code` varchar(20) NOT NULL,
  `description` text DEFAULT NULL,
  `teacher_id` int(11) DEFAULT NULL,
  `target_course` varchar(255) DEFAULT NULL,
  `target_year_level` varchar(255) DEFAULT NULL,
  `status` enum('open','finalized') NOT NULL DEFAULT 'open'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `subjects`
--

INSERT INTO `subjects` (`id`, `name`, `code`, `description`, `teacher_id`, `target_course`, `target_year_level`, `status`) VALUES
(1, 'Professional Ethics in IT/ Social & Professional I', 'UGRD-8681799', 'PAG PUMASOK KA MAY 1.5K KA!', 4, NULL, NULL, 'open'),
(2, 'Application Lifecycle Management', 'UGRD-6851858', '', 6, NULL, NULL, 'open');

-- --------------------------------------------------------

--
-- Table structure for table `teacher_settings`
--

CREATE TABLE `teacher_settings` (
  `id` int(11) NOT NULL,
  `teacher_id` int(11) NOT NULL,
  `setting_key` varchar(50) NOT NULL,
  `setting_value` varchar(255) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

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
  `profile_picture` varchar(255) DEFAULT 'image/default_avatar.png',
  `status` enum('active','archived') NOT NULL DEFAULT 'active',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `users`
--

INSERT INTO `users` (`id`, `username`, `password`, `email`, `role`, `first_name`, `last_name`, `suffix`, `age`, `birth_date`, `sex`, `phone_number`, `course`, `year_level`, `home_address`, `city`, `province`, `guardian_name`, `guardian_occupation`, `guardian_status`, `guardian_phone`, `guardian_address`, `profile_picture`, `status`, `created_at`) VALUES
(1, 'admin', '$2y$10$M6zgd5fQozY9PjXSyQ6BDuzmcP5ksY6iOJMbXIEnP3u/1dr.29gly', 'admin@school.edu', 'admin', 'System', 'Admin', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 'uploads/avatars/user_1_1784102983.jpeg', 'active', '2025-09-30 03:54:39'),
(4, 'renz', '$2y$10$ubc6NTRZ1pYLCbW0Qbde3.DfKGjZ7B/hDuZfKQgh6G26Ho6TCgjcy', 'renz@gmail.com', 'teacher', 'Renz Cydric', 'Garcia', '', NULL, NULL, '', '09165201312', '', '', '', '', '', '', '', '', '', '', 'uploads/avatars/user_4_1784105424.jpeg', 'active', '2026-07-15 08:49:57'),
(5, 'lawrence', '$2y$10$Ts0PSXn//x8bh39DVFmd.eTPA/kyUIQApuJwXThZ6GgXcXV6XVpxi', 'lawrence@gmail.com', 'student', 'Lawrence', 'Villacorte', '', 21, '2005-04-13', 'Male', '09165201312', 'BSIT', '4th Year', 'Brygy. Street', 'Palo', 'Leyte', 'Mama', 'Teacher', 'Single', '09100463321', 'brgy. Street', 'uploads/avatars/user_5_1784105562.jpeg', 'active', '2026-07-15 08:52:05'),
(6, 'antho', '$2y$10$CQGnQUEPki/1MAx9.gQ2R.dKtD3PKL8rauk6GhpqnehVWpbPTUggm', 'antho@gmail.com', 'teacher', 'Anthoniete', 'Tumanda', '', NULL, NULL, '', '09606678584', '', '', '', '', '', '', '', '', '', '', 'uploads/avatars/user_6_1784192175.jpeg', 'active', '2026-07-16 08:51:59');

--
-- Indexes for dumped tables
--

--
-- Indexes for table `audit_log`
--
ALTER TABLE `audit_log`
  ADD PRIMARY KEY (`id`),
  ADD KEY `actor_id` (`actor_id`),
  ADD KEY `target_user_id` (`target_user_id`),
  ADD KEY `idx_filters_and_sort` (`actor_id`,`action`,`timestamp`),
  ADD KEY `timestamp` (`timestamp`),
  ADD KEY `target_user_id_2` (`target_user_id`),
  ADD KEY `action` (`action`);
ALTER TABLE `audit_log` ADD FULLTEXT KEY `details` (`details`);

--
-- Indexes for table `broadcast_templates`
--
ALTER TABLE `broadcast_templates`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `grades`
--
ALTER TABLE `grades`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `enrollment_unique` (`student_id`,`subject_id`),
  ADD KEY `student_id` (`student_id`),
  ADD KEY `subject_id` (`subject_id`),
  ADD KEY `teacher_id` (`teacher_id`);

--
-- Indexes for table `login_logs`
--
ALTER TABLE `login_logs`
  ADD PRIMARY KEY (`id`),
  ADD KEY `user_id` (`user_id`);

--
-- Indexes for table `notifications`
--
ALTER TABLE `notifications`
  ADD PRIMARY KEY (`id`),
  ADD KEY `recipient_id` (`recipient_id`),
  ADD KEY `sender_id` (`sender_id`);

--
-- Indexes for table `settings`
--
ALTER TABLE `settings`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `setting_key` (`setting_key`);

--
-- Indexes for table `subjects`
--
ALTER TABLE `subjects`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `code` (`code`),
  ADD UNIQUE KEY `name` (`name`),
  ADD KEY `teacher_id` (`teacher_id`);

--
-- Indexes for table `teacher_settings`
--
ALTER TABLE `teacher_settings`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `teacher_key` (`teacher_id`,`setting_key`);

--
-- Indexes for table `users`
--
ALTER TABLE `users`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `username` (`username`),
  ADD UNIQUE KEY `email` (`email`),
  ADD KEY `username_2` (`username`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `audit_log`
--
ALTER TABLE `audit_log`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=17;

--
-- AUTO_INCREMENT for table `broadcast_templates`
--
ALTER TABLE `broadcast_templates`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=12;

--
-- AUTO_INCREMENT for table `grades`
--
ALTER TABLE `grades`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT for table `login_logs`
--
ALTER TABLE `login_logs`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=26;

--
-- AUTO_INCREMENT for table `notifications`
--
ALTER TABLE `notifications`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=20;

--
-- AUTO_INCREMENT for table `settings`
--
ALTER TABLE `settings`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=27;

--
-- AUTO_INCREMENT for table `subjects`
--
ALTER TABLE `subjects`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT for table `teacher_settings`
--
ALTER TABLE `teacher_settings`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `users`
--
ALTER TABLE `users`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=7;

--
-- Constraints for dumped tables
--

--
-- Constraints for table `audit_log`
--
ALTER TABLE `audit_log`
  ADD CONSTRAINT `audit_log_actor_fk` FOREIGN KEY (`actor_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `audit_log_target_fk` FOREIGN KEY (`target_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `grades`
--
ALTER TABLE `grades`
  ADD CONSTRAINT `grades_student_fk` FOREIGN KEY (`student_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `grades_subject_fk` FOREIGN KEY (`subject_id`) REFERENCES `subjects` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `grades_teacher_fk` FOREIGN KEY (`teacher_id`) REFERENCES `users` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `login_logs`
--
ALTER TABLE `login_logs`
  ADD CONSTRAINT `login_logs_user_fk` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `notifications`
--
ALTER TABLE `notifications`
  ADD CONSTRAINT `notifications_recipient_fk` FOREIGN KEY (`recipient_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `notifications_sender_fk` FOREIGN KEY (`sender_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `subjects`
--
ALTER TABLE `subjects`
  ADD CONSTRAINT `subjects_teacher_fk` FOREIGN KEY (`teacher_id`) REFERENCES `users` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `teacher_settings`
--
ALTER TABLE `teacher_settings`
  ADD CONSTRAINT `ts_teacher_fk` FOREIGN KEY (`teacher_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
