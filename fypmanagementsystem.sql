-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1
-- Generation Time: Aug 26, 2025 at 09:56 PM
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
-- Database: `fypmanagementsystem`
--

-- --------------------------------------------------------

--
-- Table structure for table `admins`
--

CREATE TABLE `admins` (
  `id` int(11) NOT NULL,
  `username` varchar(50) NOT NULL,
  `email` varchar(100) NOT NULL,
  `password` varchar(255) NOT NULL,
  `full_name` varchar(100) NOT NULL,
  `profile_picture` varchar(255) DEFAULT NULL,
  `no_tel` varchar(15) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `admins`
--

INSERT INTO `admins` (`id`, `username`, `email`, `password`, `full_name`, `profile_picture`, `no_tel`) VALUES
(1, 'norazman', 'norazman@unikl.edu.my', '$2y$10$SfkL.hMKsAgye3fTMBZjMeu7NfsYr3UxhfliYQ0ziMziOxHJkskzq', 'Nor Azman bin Ismail', NULL, 'N/A'),
(1, 'norazman', 'norazman@unikl.edu.my', '$2y$10$SfkL.hMKsAgye3fTMBZjMeu7NfsYr3UxhfliYQ0ziMziOxHJkskzq', 'Nor Azman bin Ismail', NULL, 'N/A');

-- --------------------------------------------------------

--
-- Table structure for table `announcements`
--

CREATE TABLE `announcements` (
  `id` int(11) NOT NULL,
  `coordinator_id` int(11) NOT NULL,
  `title` varchar(255) NOT NULL,
  `details` text NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `announcements`
--

INSERT INTO `announcements` (`id`, `coordinator_id`, `title`, `details`, `created_at`, `updated_at`) VALUES
(5, 4, 'FYP Submission Deadline', 'All groups must submit their final reports by June 30, 2025. Follow the formatting guidelines in the FYP handbook.', '2025-06-01 18:36:31', '2025-06-14 14:35:55');

-- --------------------------------------------------------

--
-- Table structure for table `coordinators`
--

CREATE TABLE `coordinators` (
  `id` int(11) NOT NULL,
  `username` varchar(50) NOT NULL,
  `email` varchar(100) NOT NULL,
  `password` varchar(255) NOT NULL,
  `full_name` varchar(100) NOT NULL,
  `profile_picture` varchar(255) DEFAULT NULL,
  `no_tel` varchar(15) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `coordinators`
--

INSERT INTO `coordinators` (`id`, `username`, `email`, `password`, `full_name`, `profile_picture`, `no_tel`) VALUES
(4, 'sitirahmah', 'siti.rahmah@university.edu.my', '$2y$10$DWst1WYYsreS7f33bDOt..x4PxCH/41r/YzO4uJ6gFEm882tsxCga', 'Dr. Siti Rahmah binti Ahmad', NULL, '0191234567');

-- --------------------------------------------------------

--
-- Table structure for table `deliverables`
--

CREATE TABLE `deliverables` (
  `id` int(11) NOT NULL,
  `name` varchar(255) NOT NULL,
  `semester` varchar(50) NOT NULL,
  `feedback` text DEFAULT NULL,
  `submission_type` enum('individual','group') NOT NULL DEFAULT 'individual',
  `weightage` decimal(5,2) NOT NULL DEFAULT 0.00
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `deliverables`
--

INSERT INTO `deliverables` (`id`, `name`, `semester`, `feedback`, `submission_type`, `weightage`) VALUES
(19, 'FYP Poster', 'August 2025', 'Every group need to submit their poster according based on the rubric given', 'group', 10.00),
(20, 'Research Paper', 'August 2025', NULL, 'individual', 10.00),
(21, 'Logbook', 'August 2025', NULL, 'group', 20.00);

-- --------------------------------------------------------

--
-- Table structure for table `deliverable_submissions`
--

CREATE TABLE `deliverable_submissions` (
  `id` int(11) NOT NULL,
  `student_id` int(11) NOT NULL,
  `deliverable_id` int(11) DEFAULT NULL,
  `deliverable_name` varchar(255) NOT NULL,
  `file_path` varchar(255) NOT NULL,
  `submitted_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `group_id` int(11) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `deliverable_submissions`
--

INSERT INTO `deliverable_submissions` (`id`, `student_id`, `deliverable_id`, `deliverable_name`, `file_path`, `submitted_at`, `group_id`) VALUES
(25, 90, 19, 'FYP Poster', 'Uploads/FYP Poster_80_1755682539.pdf', '2025-08-20 09:35:39', 80),
(26, 90, 20, 'Research Paper', 'Uploads/Research Paper_90_1755748376.pdf', '2025-08-21 03:52:56', 80),
(27, 93, 19, 'FYP Poster', 'Uploads/FYP Poster_82_1755749813.pdf', '2025-08-21 04:16:53', 82),
(28, 93, 20, 'Research Paper', 'Uploads/Research Paper_93_1755749828.pdf', '2025-08-21 04:17:08', 82);

-- --------------------------------------------------------

--
-- Table structure for table `diary`
--

CREATE TABLE `diary` (
  `id` int(11) NOT NULL,
  `student_id` int(11) NOT NULL,
  `lecturer_id` int(11) DEFAULT NULL,
  `entry_date` date NOT NULL,
  `title` varchar(255) NOT NULL,
  `diary_content` text NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `status` enum('Pending','Reviewed') DEFAULT 'Pending'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `diary`
--

INSERT INTO `diary` (`id`, `student_id`, `lecturer_id`, `entry_date`, `title`, `diary_content`, `created_at`, `updated_at`, `status`) VALUES
(24, 90, 39, '2025-08-21', 'jumpa madam', 'jumpa madam 10 pagi', '2025-08-21 02:59:32', '2025-08-21 02:59:32', 'Pending');

-- --------------------------------------------------------

--
-- Table structure for table `evaluation`
--

CREATE TABLE `evaluation` (
  `id` int(11) NOT NULL,
  `student_id` int(11) NOT NULL,
  `assessor_id` int(11) DEFAULT NULL,
  `supervisor_id` int(11) DEFAULT NULL,
  `deliverable_id` int(10) UNSIGNED NOT NULL,
  `evaluation_grade` decimal(5,2) NOT NULL DEFAULT 0.00,
  `feedback` text DEFAULT NULL,
  `type` varchar(50) NOT NULL,
  `date` date NOT NULL,
  `coordinator_id` int(11) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `evaluation`
--

INSERT INTO `evaluation` (`id`, `student_id`, `assessor_id`, `supervisor_id`, `deliverable_id`, `evaluation_grade`, `feedback`, `type`, `date`, `coordinator_id`) VALUES
(50, 90, 40, NULL, 20, 3.40, 'teruknya', 'ass', '2025-08-21', NULL),
(51, 90, NULL, 39, 20, 8.20, 'bestnye', 'sv', '2025-08-21', NULL),
(52, 93, NULL, 41, 20, 2.00, 'supervisor thesis', 'sv', '2025-08-21', NULL),
(53, 93, 40, NULL, 20, 10.00, 'assessor', 'ass', '2025-08-21', NULL);

-- --------------------------------------------------------

--
-- Table structure for table `evaluation_rubric_scores`
--

CREATE TABLE `evaluation_rubric_scores` (
  `id` int(11) NOT NULL,
  `evaluation_id` int(11) NOT NULL,
  `rubric_id` int(11) NOT NULL,
  `score` int(11) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `evaluation_rubric_scores`
--

INSERT INTO `evaluation_rubric_scores` (`id`, `evaluation_id`, `rubric_id`, `score`) VALUES
(217, 50, 41, 3),
(218, 50, 42, 2),
(219, 50, 43, 1),
(220, 50, 44, 1),
(221, 50, 45, 10),
(222, 51, 41, 8),
(223, 51, 42, 8),
(224, 51, 43, 7),
(225, 51, 44, 8),
(226, 51, 45, 10),
(227, 52, 41, 2),
(228, 52, 42, 2),
(229, 52, 43, 2),
(230, 52, 44, 2),
(231, 52, 45, 2),
(232, 53, 41, 10),
(233, 53, 42, 10),
(234, 53, 43, 10),
(235, 53, 44, 10),
(236, 53, 45, 10);

-- --------------------------------------------------------

--
-- Table structure for table `groups`
--

CREATE TABLE `groups` (
  `id` int(11) NOT NULL,
  `name` varchar(100) NOT NULL,
  `coordinator_id` int(11) DEFAULT NULL,
  `lecturer_id` int(11) DEFAULT NULL,
  `status` enum('Pending','Approved','Rejected') DEFAULT 'Pending',
  `leader_id` int(11) DEFAULT NULL,
  `assessor_id` int(11) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `groups`
--

INSERT INTO `groups` (`id`, `name`, `coordinator_id`, `lecturer_id`, `status`, `leader_id`, `assessor_id`) VALUES
(80, '2025August001', 4, 39, 'Approved', 90, 40),
(82, '2025August002', 4, 41, 'Approved', 93, 40),
(83, '2025August003', 4, 43, 'Approved', 95, NULL);

-- --------------------------------------------------------

--
-- Table structure for table `group_evaluations`
--

CREATE TABLE `group_evaluations` (
  `id` int(11) NOT NULL,
  `group_id` int(11) NOT NULL,
  `assessor_id` int(11) DEFAULT NULL,
  `supervisor_id` int(11) DEFAULT NULL,
  `deliverable_id` int(11) NOT NULL,
  `evaluation_grade` decimal(5,2) NOT NULL,
  `feedback` text DEFAULT NULL,
  `type` varchar(50) NOT NULL,
  `date` date NOT NULL,
  `coordinator_id` int(11) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `group_evaluations`
--

INSERT INTO `group_evaluations` (`id`, `group_id`, `assessor_id`, `supervisor_id`, `deliverable_id`, `evaluation_grade`, `feedback`, `type`, `date`, `coordinator_id`) VALUES
(12, 80, NULL, 39, 19, 9.00, 'tahniah', 'Group', '2025-08-21', NULL),
(13, 82, NULL, 41, 19, 2.00, 'supervisor', 'Group', '2025-08-21', NULL),
(14, 82, 40, NULL, 19, 9.00, 'assessor', 'Group', '2025-08-21', NULL);

-- --------------------------------------------------------

--
-- Table structure for table `group_evaluation_rubric_scores`
--

CREATE TABLE `group_evaluation_rubric_scores` (
  `id` int(11) NOT NULL,
  `group_evaluation_id` int(11) NOT NULL,
  `rubric_id` int(11) NOT NULL,
  `score` int(11) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `group_evaluation_rubric_scores`
--

INSERT INTO `group_evaluation_rubric_scores` (`id`, `group_evaluation_id`, `rubric_id`, `score`) VALUES
(31, 12, 39, 9),
(32, 12, 40, 9),
(33, 13, 39, 2),
(34, 13, 40, 2),
(35, 14, 39, 9),
(36, 14, 40, 9);

-- --------------------------------------------------------

--
-- Table structure for table `group_members`
--

CREATE TABLE `group_members` (
  `id` int(11) NOT NULL,
  `group_id` int(11) NOT NULL,
  `student_id` int(11) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `group_members`
--

INSERT INTO `group_members` (`id`, `group_id`, `student_id`) VALUES
(111, 80, 91),
(112, 80, 96),
(113, 80, 90),
(116, 82, 93),
(117, 83, 95),
(118, 82, 94);

-- --------------------------------------------------------

--
-- Table structure for table `lecturers`
--

CREATE TABLE `lecturers` (
  `id` int(11) NOT NULL,
  `username` varchar(50) NOT NULL,
  `email` varchar(100) NOT NULL,
  `password` varchar(255) NOT NULL,
  `full_name` varchar(100) NOT NULL,
  `no_tel` varchar(15) DEFAULT NULL,
  `role_id` int(11) NOT NULL DEFAULT 1,
  `profile_picture` varchar(255) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `lecturers`
--

INSERT INTO `lecturers` (`id`, `username`, `email`, `password`, `full_name`, `no_tel`, `role_id`, `profile_picture`) VALUES
(39, 'norhaidah', 'norhaidah@unikl.edu.my', '$2y$10$7b57lD7kosIGghhl2gaGX.2dPYyGcnRxV9R5NgjE54w2jMow2TO6y', 'Nor Haidah Abu Haris', NULL, 4, NULL),
(40, 'azlanrahim', 'azlanrahim@unikl.edu.my', '$2y$10$81U0lqOxU9JDXEIG7ywq6OOCOiIIQS6edkW1nr8f3w.2GjrCYEIfO', 'Azlan bin Rahim', NULL, 2, NULL),
(41, 'farahsalleh', 'farahsalleh@unikl.edu.my', '$2y$10$Tj639S6bZ9azeoBzHHSBWewz2Im3B5.LV/98rY2Tks13dkcwQvLOy', 'Farah binti Salleh', NULL, 4, NULL),
(42, 'hafizabdullah', 'hafizabdullah@unikl.edu.my', '$2y$10$Ypv2.q.wdf1pdwx6k7rkcObOWsMX3NDCf23g2WOmPba0K.4rUtLby', 'Hafiz bin Abdullah', NULL, 2, NULL),
(43, 'sitimariam', 'sitimariam@unikl.edu.my', '$2y$10$kTdgZEb2HIVHR/An31vOEu1iyS/UmamGBiBv6Kfpvx9zdeljejYga', 'Siti Mariam bin Omar', NULL, 4, NULL),
(44, 'nadiakamal', 'nadiakamal@unikl.edu.my', '$2y$10$QokNmSHzXWX1pHE7k7V5WuhS0SHRchgLp6LPMJwg.nylqiPfi6Vrq', 'Nadia binti Kamal', NULL, 2, NULL);

-- --------------------------------------------------------

--
-- Table structure for table `meetings`
--

CREATE TABLE `meetings` (
  `id` int(11) NOT NULL,
  `student_id` int(11) NOT NULL,
  `lecturer_id` int(11) DEFAULT NULL,
  `meeting_date` date NOT NULL,
  `meeting_time` time NOT NULL,
  `title` varchar(255) NOT NULL,
  `topic` text NOT NULL,
  `status` enum('Pending','Confirmed','Cancelled') DEFAULT 'Pending',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `group_id` int(11) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `meetings`
--

INSERT INTO `meetings` (`id`, `student_id`, `lecturer_id`, `meeting_date`, `meeting_time`, `title`, `topic`, `status`, `created_at`, `updated_at`, `group_id`) VALUES
(36, 90, 39, '2025-08-21', '10:00:00', 'Supervisor Meeting', 'jumpa madam', 'Confirmed', '2025-08-21 02:59:57', '2025-08-21 03:24:52', 80);

-- --------------------------------------------------------

--
-- Table structure for table `projects`
--

CREATE TABLE `projects` (
  `project_id` int(11) NOT NULL,
  `group_id` int(11) NOT NULL,
  `title` varchar(200) NOT NULL,
  `description` text NOT NULL,
  `lecturer_id` int(11) DEFAULT NULL,
  `pending_title` varchar(255) DEFAULT NULL,
  `pending_description` text DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `projects`
--

INSERT INTO `projects` (`project_id`, `group_id`, `title`, `description`, `lecturer_id`, `pending_title`, `pending_description`) VALUES
(56, 80, 'Online Student Attendance Tracking System', 'A digital attendance system using QR codes for students to check in during lectures, providing lecturers with real-time attendance reports and reducing manual paperwork', NULL, NULL, NULL),
(58, 82, '', '', NULL, 'TestA', 'TestA'),
(59, 83, '', '', NULL, NULL, NULL);

-- --------------------------------------------------------

--
-- Table structure for table `roles`
--

CREATE TABLE `roles` (
  `id` int(11) NOT NULL,
  `role_name` varchar(50) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `roles`
--

INSERT INTO `roles` (`id`, `role_name`) VALUES
(2, 'Assessor'),
(3, 'Both'),
(5, 'Coordinator'),
(1, 'Lecturer'),
(4, 'Supervisor');

-- --------------------------------------------------------

--
-- Table structure for table `rubrics`
--

CREATE TABLE `rubrics` (
  `id` int(11) NOT NULL,
  `coordinator_id` int(11) DEFAULT NULL,
  `evaluation_id` int(11) DEFAULT NULL,
  `component` text DEFAULT NULL,
  `criteria` text NOT NULL,
  `max_score` int(11) NOT NULL DEFAULT 10,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `group_evaluation_id` int(11) DEFAULT NULL,
  `deliverable_id` int(11) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `rubrics`
--

INSERT INTO `rubrics` (`id`, `coordinator_id`, `evaluation_id`, `component`, `criteria`, `max_score`, `created_at`, `group_evaluation_id`, `deliverable_id`) VALUES
(39, 4, NULL, 'Information of the project', 'Content Knowledge', 10, '2025-08-20 10:03:57', NULL, 19),
(40, 4, NULL, 'Poster is clean, neat and creative', 'Poster presentation', 10, '2025-08-20 10:05:03', NULL, 19),
(41, 4, NULL, 'Summary of objective,  scope of study and methodology and expected findings', 'Abstract', 10, '2025-08-21 03:44:21', NULL, 20),
(42, 4, NULL, 'Summary of literature related to the project', 'Literature Review', 10, '2025-08-21 03:44:53', NULL, 20),
(43, 4, NULL, 'Project background and overview of the project', 'Introduction', 10, '2025-08-21 03:48:00', NULL, 20),
(44, 4, NULL, 'The process flow of the study/project being conducted', 'Methodology', 10, '2025-08-21 03:48:21', NULL, 20),
(45, 4, NULL, 'The expected output/feature of the proposed project/product are clearly presented', 'Results', 10, '2025-08-21 03:48:41', NULL, 20),
(46, 4, NULL, 'Meeting frequency and progress consultation', 'Consultation with  Supervisor', 10, '2025-08-21 04:23:16', NULL, 21),
(47, 4, NULL, 'Record of progress with respect to the timelines', 'Timelines', 10, '2025-08-21 04:23:41', NULL, 21);

-- --------------------------------------------------------

--
-- Table structure for table `rubric_score_ranges`
--

CREATE TABLE `rubric_score_ranges` (
  `id` int(11) NOT NULL,
  `rubric_id` int(11) NOT NULL,
  `score_range` varchar(10) NOT NULL,
  `description` text NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `rubric_score_ranges`
--

INSERT INTO `rubric_score_ranges` (`id`, `rubric_id`, `score_range`, `description`) VALUES
(166, 39, '0-2', 'There is little information about the project. Presented not in logical order and difficult to follow.'),
(167, 39, '3-4', 'There is little information about the project. Presented not in logical order and difficult to follow.'),
(168, 39, '5-6', 'There is some information about the project. Presented not in logical order and difficult to follow.'),
(169, 39, '7-8', 'The information includes a description of the project and activities. Some information is missing. Presented in a logical order and difficult to follow.'),
(170, 39, '9-10', 'The information is detailed about the project and the flow of the activities. Presented in logical order.'),
(171, 40, '0-2', 'The presentation is unorganized. Insufficient information and lack some information.'),
(172, 40, '3-4', 'The presentation is unorganized. Insufficient information and lack some information.'),
(173, 40, '5-6', 'The presentation flows well. Shows acceptable understanding'),
(174, 40, '7-8', 'The presentation is mostly neat and clean. Information is organized logically and shows some degree of creativity.  The overall presentation is interesting.'),
(175, 40, '9-10', 'The presentation is neat, clean, well organized, and presented creatively.  Information is interesting and accurate'),
(176, 41, '0-2', 'The thesis statement is unclear or missing'),
(177, 41, '3-4', 'The thesis statement is unclear or missing'),
(178, 41, '5-6', 'The thesis statement is somewhat clear, but could be more focused'),
(179, 41, '7-8', 'The thesis statement is clear and presents the main argument'),
(180, 41, '9-10', 'The thesis statement is clear, focused, and effectively presents the main argument'),
(181, 42, '0-2', 'The literature review is insufficient or not conducted'),
(182, 42, '3-4', 'The literature review is insufficient or not conducted'),
(183, 42, '5-6', 'Literature review is somewhat limited or relies on less credible sources'),
(184, 42, '7-8', 'Literature review is thorough and includes credible sources to support the argument'),
(185, 42, '9-10', 'Comprehensive literature studies with a wide range of credible sources used to support the argument'),
(186, 43, '0-2', 'Weak or no introduction of topic'),
(187, 43, '3-4', 'Weak or no introduction of topic'),
(188, 43, '5-6', 'A basic introduction that states the topic but lacks interest'),
(189, 43, '7-8', 'Proficient introduction that is interesting and clear on the topics discussed'),
(190, 43, '9-10', 'Exceptional introduction that grabs reader interest, clearly relates to the topic and well developed'),
(191, 44, '0-2', 'Little explanation of the methodology choice and supports the overall of the project process flow'),
(192, 44, '3-4', 'Little explanation of the methodology choice and supports the overall of the project process flow'),
(193, 44, '5-6', 'Some explanation of the methodology choice and supports the overall of the project process flow'),
(194, 44, '7-8', 'Good explanation of the methodology choice and supports the overall of the project process flow'),
(195, 44, '9-10', 'Excellent explanation of the methodology choice and supports the overall project process flow that links to the problem and project outcome'),
(196, 45, '0-2', '\"lacks development\r\nof ideas with weak or\r\nno transitions\r\nbetween and within\r\nparagraphs.\"'),
(197, 45, '3-4', '\"lacks development\r\nof ideas with weak or\r\nno transitions\r\nbetween and within\r\nparagraphs.\"'),
(198, 45, '5-6', '\"somewhat clear and\r\nlogical development\r\nwith basic transitions\r\nbetween and within\r\nparagraphs.\"'),
(199, 45, '7-8', '\"clear and logical order\r\nthat supports project\r\nwith good transitions\r\nbetween and within\r\nparagraphs\"'),
(200, 45, '9-10', '\"exceptionally clear,\r\nlogical, mature, and\r\nthorough development of\r\nproject with excellent\r\ntransitions between and\r\nwithin paragraphs\"'),
(201, 46, '0-2', 'Meet less than 2 times  and shows minimum project progress'),
(202, 46, '3-4', 'Meet less than 2 times  and shows minimum project progress'),
(203, 46, '5-6', '\"Meet at least 5 times and shows satisfactorily project\r\nprogress.\"'),
(204, 46, '7-8', 'Meet at least 6 times and shows good project progress'),
(205, 46, '9-10', 'Meet at least  7 times and show excellent project progress'),
(206, 47, '0-2', '\"Unable to follow timelines.\r\nLogbook is very poorly maintained. \"'),
(207, 47, '3-4', '\"Unable to follow timelines.\r\nLogbook is very poorly maintained. \"'),
(208, 47, '5-6', 'Most of the time follows timelines. Logbook satisfactorily  maintained'),
(209, 47, '7-8', '\"Meets and follows timelines.\r\n Logbook is usually maintained \"'),
(210, 47, '9-10', 'Highly meets and follows the  timelines.Logbook is well maintained');

-- --------------------------------------------------------

--
-- Table structure for table `semesters`
--

CREATE TABLE `semesters` (
  `id` int(11) NOT NULL,
  `semester_name` varchar(50) NOT NULL,
  `start_date` date NOT NULL,
  `is_current` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `semesters`
--

INSERT INTO `semesters` (`id`, `semester_name`, `start_date`, `is_current`, `created_at`, `updated_at`) VALUES
(20, 'August 2025', '2025-08-12', 1, '2025-08-19 14:54:23', '2025-08-19 14:54:23'),
(21, 'March 2025', '2025-03-10', 0, '2025-02-19 14:54:23', '2025-08-26 18:49:22');

-- --------------------------------------------------------

--
-- Table structure for table `students`
--

CREATE TABLE `students` (
  `id` int(11) NOT NULL,
  `email` varchar(100) NOT NULL,
  `password` varchar(255) NOT NULL,
  `username` varchar(50) NOT NULL,
  `full_name` varchar(100) NOT NULL,
  `no_tel` varchar(15) DEFAULT NULL,
  `intake_year` int(11) DEFAULT NULL,
  `intake_month` varchar(20) DEFAULT NULL,
  `profile_picture` varchar(255) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `students`
--

INSERT INTO `students` (`id`, `email`, `password`, `username`, `full_name`, `no_tel`, `intake_year`, `intake_month`, `profile_picture`) VALUES
(90, 'mimran.jiwa@s.unikl.edu.my', '$2y$10$8AbiZnYeuuDKw6sSbi2YMOP8EMCmCqmxBQmfY4.SHeZFCE.VUJ6zi', 'imranjiwa', 'Mohamad Imran bin Mohamad Jiwa', NULL, 2025, 'August', 'img/undraw_profile.svg'),
(91, 'zahirrosman@unikl.edu.my', '$2y$10$otOt.mg0OU3IF8IDCgvh4.VmoRR1yzIPoqucqiMVIYZ.hLXRuImIe', 'zahirrosman', 'Zahir bin Rosman', NULL, 2025, 'August', 'img/undraw_profile.svg'),
(92, 'nuramirah@unikl.edu.my', '$2y$10$snzZhf.o30zMuAGVl3FRH.HYrbhhFIuPZCN2vfuZ3/8lVYXpmPVt6', 'nuramirah', 'Nur Amirah binti Norazli', NULL, 2025, 'August', 'img/undraw_profile.svg'),
(93, 'adamidris@unikl.edu.my', '$2y$10$0doQ1k9JBd9WptI3zS9VYegCbcAs753AJnjWoO4J9z8qTuIASW6la', 'adamidris', 'Adam Idris bin Sulaiman', NULL, 2025, 'August', 'img/undraw_profile.svg'),
(94, 'mikhailshafiq@unikl.edu.my', '$2y$10$xWHm2AHLzj///.XCvQtgYeOGfCgBsUkbsySfAa2ypZGaaK6tskRoi', 'mikhailshafiq', 'Mikhail bin Shafiq', NULL, 2025, 'August', 'img/undraw_profile.svg'),
(95, 'aisyahzahid@unikl.edu.my', '$2y$10$Syu8U5mkPHxWC033wXgbEOfb5cZKnsbjSqmfb6PKjURohixrtiYy2', 'aisyahzahid', 'Aisyah binti Zahid', NULL, 2025, 'August', 'img/undraw_profile.svg'),
(96, 'ameerrosli@unikl.edu.my', '$2y$10$0TqCDL6kblcSc2uHrot8Ru9lyBRdgnHts3uLQvVymVFU8GbiMZqKG', 'ameerrosli', 'Ameer bin Rosli', NULL, 2025, 'August', 'img/undraw_profile.svg');

-- --------------------------------------------------------

--
-- Table structure for table `teaching_materials`
--

CREATE TABLE `teaching_materials` (
  `id` int(11) NOT NULL,
  `coordinator_id` int(11) NOT NULL,
  `title` varchar(255) NOT NULL,
  `description` text DEFAULT NULL,
  `file_path` varchar(255) NOT NULL,
  `uploaded_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `teaching_materials`
--

INSERT INTO `teaching_materials` (`id`, `coordinator_id`, `title`, `description`, `file_path`, `uploaded_at`, `updated_at`) VALUES
(6, 4, 'FYP Poster Guidelines', 'Guidelines for creating project posters for the FYP exhibition.', 'Uploads/teaching_materials/1748984215_Blue White Illustrative Kimbap Promotion A3 Portrait (2).pdf', '2025-06-03 20:56:55', '2025-06-14 14:35:56');

--
-- Indexes for dumped tables
--

--
-- Indexes for table `announcements`
--
ALTER TABLE `announcements`
  ADD PRIMARY KEY (`id`),
  ADD KEY `coordinator_id` (`coordinator_id`);

--
-- Indexes for table `coordinators`
--
ALTER TABLE `coordinators`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `email` (`email`);

--
-- Indexes for table `deliverables`
--
ALTER TABLE `deliverables`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `deliverable_submissions`
--
ALTER TABLE `deliverable_submissions`
  ADD PRIMARY KEY (`id`),
  ADD KEY `fk_group` (`group_id`),
  ADD KEY `fk_deliverable` (`deliverable_id`);

--
-- Indexes for table `diary`
--
ALTER TABLE `diary`
  ADD PRIMARY KEY (`id`),
  ADD KEY `student_id` (`student_id`),
  ADD KEY `lecturer_id` (`lecturer_id`);

--
-- Indexes for table `evaluation`
--
ALTER TABLE `evaluation`
  ADD PRIMARY KEY (`id`),
  ADD KEY `student_id` (`student_id`),
  ADD KEY `assessor_id` (`assessor_id`),
  ADD KEY `supervisor_id` (`supervisor_id`),
  ADD KEY `evaluation_ibfk_4` (`coordinator_id`);

--
-- Indexes for table `evaluation_rubric_scores`
--
ALTER TABLE `evaluation_rubric_scores`
  ADD PRIMARY KEY (`id`),
  ADD KEY `evaluation_id` (`evaluation_id`),
  ADD KEY `rubric_id` (`rubric_id`);

--
-- Indexes for table `groups`
--
ALTER TABLE `groups`
  ADD PRIMARY KEY (`id`),
  ADD KEY `fk_lecturer_group` (`lecturer_id`),
  ADD KEY `fk_coordinator_group` (`coordinator_id`),
  ADD KEY `fk_leader_id` (`leader_id`),
  ADD KEY `fk_assessor_id` (`assessor_id`);

--
-- Indexes for table `group_evaluations`
--
ALTER TABLE `group_evaluations`
  ADD PRIMARY KEY (`id`),
  ADD KEY `group_id` (`group_id`),
  ADD KEY `assessor_id` (`assessor_id`),
  ADD KEY `supervisor_id` (`supervisor_id`),
  ADD KEY `group_evaluations_ibfk_4` (`deliverable_id`),
  ADD KEY `group_evaluations_ibfk_5` (`coordinator_id`);

--
-- Indexes for table `group_evaluation_rubric_scores`
--
ALTER TABLE `group_evaluation_rubric_scores`
  ADD PRIMARY KEY (`id`),
  ADD KEY `group_evaluation_id` (`group_evaluation_id`),
  ADD KEY `rubric_id` (`rubric_id`);

--
-- Indexes for table `group_members`
--
ALTER TABLE `group_members`
  ADD PRIMARY KEY (`id`),
  ADD KEY `group_id` (`group_id`) USING BTREE,
  ADD KEY `student_id` (`student_id`) USING BTREE;

--
-- Indexes for table `lecturers`
--
ALTER TABLE `lecturers`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `username` (`username`),
  ADD UNIQUE KEY `email` (`email`),
  ADD KEY `role_id` (`role_id`);

--
-- Indexes for table `meetings`
--
ALTER TABLE `meetings`
  ADD PRIMARY KEY (`id`),
  ADD KEY `student_id` (`student_id`),
  ADD KEY `fk_meetings_lecturer` (`lecturer_id`),
  ADD KEY `group_id` (`group_id`);

--
-- Indexes for table `projects`
--
ALTER TABLE `projects`
  ADD PRIMARY KEY (`project_id`),
  ADD KEY `GroupID` (`group_id`),
  ADD KEY `fk_projects_lecturer` (`lecturer_id`);

--
-- Indexes for table `roles`
--
ALTER TABLE `roles`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `role_name` (`role_name`);

--
-- Indexes for table `rubrics`
--
ALTER TABLE `rubrics`
  ADD PRIMARY KEY (`id`),
  ADD KEY `coordinator_id` (`coordinator_id`),
  ADD KEY `evaluation_id` (`evaluation_id`),
  ADD KEY `fk_group_evaluation` (`group_evaluation_id`),
  ADD KEY `fk_rubrics_deliverable` (`deliverable_id`);

--
-- Indexes for table `rubric_score_ranges`
--
ALTER TABLE `rubric_score_ranges`
  ADD PRIMARY KEY (`id`),
  ADD KEY `rubric_id` (`rubric_id`);

--
-- Indexes for table `semesters`
--
ALTER TABLE `semesters`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `students`
--
ALTER TABLE `students`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `email` (`email`),
  ADD UNIQUE KEY `username` (`username`);

--
-- Indexes for table `teaching_materials`
--
ALTER TABLE `teaching_materials`
  ADD PRIMARY KEY (`id`),
  ADD KEY `coordinator_id` (`coordinator_id`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `announcements`
--
ALTER TABLE `announcements`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=6;

--
-- AUTO_INCREMENT for table `coordinators`
--
ALTER TABLE `coordinators`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=5;

--
-- AUTO_INCREMENT for table `deliverables`
--
ALTER TABLE `deliverables`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=22;

--
-- AUTO_INCREMENT for table `deliverable_submissions`
--
ALTER TABLE `deliverable_submissions`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=29;

--
-- AUTO_INCREMENT for table `diary`
--
ALTER TABLE `diary`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=25;

--
-- AUTO_INCREMENT for table `evaluation`
--
ALTER TABLE `evaluation`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=54;

--
-- AUTO_INCREMENT for table `evaluation_rubric_scores`
--
ALTER TABLE `evaluation_rubric_scores`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=237;

--
-- AUTO_INCREMENT for table `groups`
--
ALTER TABLE `groups`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=84;

--
-- AUTO_INCREMENT for table `group_evaluations`
--
ALTER TABLE `group_evaluations`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=15;

--
-- AUTO_INCREMENT for table `group_evaluation_rubric_scores`
--
ALTER TABLE `group_evaluation_rubric_scores`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=37;

--
-- AUTO_INCREMENT for table `group_members`
--
ALTER TABLE `group_members`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=119;

--
-- AUTO_INCREMENT for table `lecturers`
--
ALTER TABLE `lecturers`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=45;

--
-- AUTO_INCREMENT for table `meetings`
--
ALTER TABLE `meetings`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=37;

--
-- AUTO_INCREMENT for table `projects`
--
ALTER TABLE `projects`
  MODIFY `project_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=60;

--
-- AUTO_INCREMENT for table `roles`
--
ALTER TABLE `roles`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=12;

--
-- AUTO_INCREMENT for table `rubrics`
--
ALTER TABLE `rubrics`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=48;

--
-- AUTO_INCREMENT for table `rubric_score_ranges`
--
ALTER TABLE `rubric_score_ranges`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=211;

--
-- AUTO_INCREMENT for table `semesters`
--
ALTER TABLE `semesters`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=22;

--
-- AUTO_INCREMENT for table `students`
--
ALTER TABLE `students`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=97;

--
-- AUTO_INCREMENT for table `teaching_materials`
--
ALTER TABLE `teaching_materials`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=7;

--
-- Constraints for dumped tables
--

--
-- Constraints for table `deliverable_submissions`
--
ALTER TABLE `deliverable_submissions`
  ADD CONSTRAINT `fk_deliverable` FOREIGN KEY (`deliverable_id`) REFERENCES `deliverables` (`id`),
  ADD CONSTRAINT `fk_group` FOREIGN KEY (`group_id`) REFERENCES `groups` (`id`);

--
-- Constraints for table `diary`
--
ALTER TABLE `diary`
  ADD CONSTRAINT `diary_ibfk_1` FOREIGN KEY (`student_id`) REFERENCES `students` (`id`),
  ADD CONSTRAINT `diary_ibfk_2` FOREIGN KEY (`lecturer_id`) REFERENCES `lecturers` (`id`);

--
-- Constraints for table `evaluation`
--
ALTER TABLE `evaluation`
  ADD CONSTRAINT `evaluation_ibfk_1` FOREIGN KEY (`student_id`) REFERENCES `students` (`id`),
  ADD CONSTRAINT `evaluation_ibfk_2` FOREIGN KEY (`assessor_id`) REFERENCES `lecturers` (`id`),
  ADD CONSTRAINT `evaluation_ibfk_3` FOREIGN KEY (`supervisor_id`) REFERENCES `lecturers` (`id`),
  ADD CONSTRAINT `evaluation_ibfk_4` FOREIGN KEY (`coordinator_id`) REFERENCES `coordinators` (`id`);

--
-- Constraints for table `evaluation_rubric_scores`
--
ALTER TABLE `evaluation_rubric_scores`
  ADD CONSTRAINT `evaluation_rubric_scores_ibfk_1` FOREIGN KEY (`evaluation_id`) REFERENCES `evaluation` (`id`),
  ADD CONSTRAINT `evaluation_rubric_scores_ibfk_2` FOREIGN KEY (`rubric_id`) REFERENCES `rubrics` (`id`);

--
-- Constraints for table `groups`
--
ALTER TABLE `groups`
  ADD CONSTRAINT `fk_assessor_id` FOREIGN KEY (`assessor_id`) REFERENCES `lecturers` (`id`),
  ADD CONSTRAINT `fk_coordinator_group` FOREIGN KEY (`coordinator_id`) REFERENCES `coordinators` (`id`),
  ADD CONSTRAINT `fk_leader_id` FOREIGN KEY (`leader_id`) REFERENCES `students` (`id`),
  ADD CONSTRAINT `fk_lecturer_group` FOREIGN KEY (`lecturer_id`) REFERENCES `lecturers` (`id`);

--
-- Constraints for table `group_evaluations`
--
ALTER TABLE `group_evaluations`
  ADD CONSTRAINT `group_evaluations_ibfk_1` FOREIGN KEY (`group_id`) REFERENCES `groups` (`id`),
  ADD CONSTRAINT `group_evaluations_ibfk_2` FOREIGN KEY (`assessor_id`) REFERENCES `lecturers` (`id`),
  ADD CONSTRAINT `group_evaluations_ibfk_3` FOREIGN KEY (`supervisor_id`) REFERENCES `lecturers` (`id`),
  ADD CONSTRAINT `group_evaluations_ibfk_4` FOREIGN KEY (`deliverable_id`) REFERENCES `deliverables` (`id`),
  ADD CONSTRAINT `group_evaluations_ibfk_5` FOREIGN KEY (`coordinator_id`) REFERENCES `coordinators` (`id`);

--
-- Constraints for table `group_evaluation_rubric_scores`
--
ALTER TABLE `group_evaluation_rubric_scores`
  ADD CONSTRAINT `group_evaluation_rubric_scores_ibfk_1` FOREIGN KEY (`group_evaluation_id`) REFERENCES `group_evaluations` (`id`),
  ADD CONSTRAINT `group_evaluation_rubric_scores_ibfk_2` FOREIGN KEY (`rubric_id`) REFERENCES `rubrics` (`id`);

--
-- Constraints for table `group_members`
--
ALTER TABLE `group_members`
  ADD CONSTRAINT `group_members_ibfk_1` FOREIGN KEY (`group_id`) REFERENCES `groups` (`id`),
  ADD CONSTRAINT `group_members_ibfk_2` FOREIGN KEY (`student_id`) REFERENCES `students` (`id`);

--
-- Constraints for table `lecturers`
--
ALTER TABLE `lecturers`
  ADD CONSTRAINT `lecturers_ibfk_1` FOREIGN KEY (`role_id`) REFERENCES `roles` (`id`);

--
-- Constraints for table `meetings`
--
ALTER TABLE `meetings`
  ADD CONSTRAINT `fk_meetings_lecturer` FOREIGN KEY (`lecturer_id`) REFERENCES `lecturers` (`id`),
  ADD CONSTRAINT `meetings_ibfk_1` FOREIGN KEY (`student_id`) REFERENCES `students` (`id`),
  ADD CONSTRAINT `meetings_ibfk_2` FOREIGN KEY (`group_id`) REFERENCES `groups` (`id`);

--
-- Constraints for table `projects`
--
ALTER TABLE `projects`
  ADD CONSTRAINT `fk_projects_lecturer` FOREIGN KEY (`lecturer_id`) REFERENCES `lecturers` (`id`),
  ADD CONSTRAINT `projects_ibfk_1` FOREIGN KEY (`group_id`) REFERENCES `groups` (`id`);

--
-- Constraints for table `rubrics`
--
ALTER TABLE `rubrics`
  ADD CONSTRAINT `fk_group_evaluation` FOREIGN KEY (`group_evaluation_id`) REFERENCES `group_evaluations` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_rubrics_deliverable` FOREIGN KEY (`deliverable_id`) REFERENCES `deliverables` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `rubrics_ibfk_1` FOREIGN KEY (`coordinator_id`) REFERENCES `coordinators` (`id`),
  ADD CONSTRAINT `rubrics_ibfk_2` FOREIGN KEY (`evaluation_id`) REFERENCES `evaluation` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `rubric_score_ranges`
--
ALTER TABLE `rubric_score_ranges`
  ADD CONSTRAINT `rubric_score_ranges_ibfk_1` FOREIGN KEY (`rubric_id`) REFERENCES `rubrics` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `teaching_materials`
--
ALTER TABLE `teaching_materials`
  ADD CONSTRAINT `teaching_materials_ibfk_1` FOREIGN KEY (`coordinator_id`) REFERENCES `coordinators` (`id`);
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
