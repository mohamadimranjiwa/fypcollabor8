-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1
-- Generation Time: Aug 19, 2025 at 04:28 PM
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
(11, 4, NULL, 'Supervisor Engagement', 'Quality and frequency of supervisor consultations', 10, '2025-04-28 09:34:48', NULL, 10),
(12, 4, NULL, 'Innovation', 'Originality and creativity of project idea', 10, '2025-04-28 14:49:10', NULL, 10),
(14, 4, NULL, 'Research Progress', 'Progress in research activities', 10, '2025-04-28 16:09:50', NULL, 10),
(15, 4, NULL, 'Independence', 'Self-driven approach and independence', 10, '2025-04-28 16:10:25', NULL, 10),
(16, 4, NULL, 'Timeliness', 'Adherence to project timelines', 10, '2025-04-28 16:10:36', NULL, 10),
(17, 4, NULL, 'Prototype Functionality', 'Functionality and usability of prototype', 10, '2025-04-28 18:38:41', NULL, 12),
(18, 4, NULL, 'Prototype Design', 'Quality of prototype design and interface', 10, '2025-04-28 18:38:51', NULL, 12),
(19, 4, NULL, 'Report Clarity', 'Clarity and structure of progress report', 10, '2025-05-05 04:40:41', NULL, 11),
(20, 4, NULL, 'Methodology Detail', 'Detail and appropriateness of methodology', 10, '2025-05-06 19:19:19', NULL, 11),
(21, 4, NULL, 'Result Presentation', 'Quality of preliminary results presentation', 10, '2025-05-06 19:53:03', NULL, 11),
(30, 4, NULL, 'Abstract', 'Clear and concise project overview', 10, '2025-06-03 18:52:41', NULL, 17),
(31, 4, NULL, 'Introduction', 'Clear problem statement, objectives, and scope', 10, '2025-06-03 18:53:49', NULL, 17),
(32, 4, NULL, 'Literature Review', 'Depth and relevance of literature review', 10, '2025-06-03 18:54:02', NULL, 17),
(33, 4, NULL, 'Methodology', 'Clarity and feasibility of methodology', 10, '2025-06-03 18:54:15', NULL, 17),
(34, 4, NULL, 'Design & Implementation', 'System design and component integration', 10, '2025-06-03 18:54:28', NULL, 17),
(35, 4, NULL, 'Testing & Results', 'Comprehensive testing and result reporting', 10, '2025-06-03 18:54:57', NULL, 17),
(36, 4, NULL, 'Conclusion', 'Summary of findings and future work', 10, '2025-06-03 18:55:07', NULL, 17),
(37, 4, NULL, 'References', 'Relevance and completeness of references', 10, '2025-06-03 18:55:15', NULL, 17);

--
-- Indexes for dumped tables
--

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
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `rubrics`
--
ALTER TABLE `rubrics`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=38;

--
-- Constraints for dumped tables
--

--
-- Constraints for table `rubrics`
--
ALTER TABLE `rubrics`
  ADD CONSTRAINT `fk_group_evaluation` FOREIGN KEY (`group_evaluation_id`) REFERENCES `group_evaluations` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_rubrics_deliverable` FOREIGN KEY (`deliverable_id`) REFERENCES `deliverables` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `rubrics_ibfk_1` FOREIGN KEY (`coordinator_id`) REFERENCES `coordinators` (`id`),
  ADD CONSTRAINT `rubrics_ibfk_2` FOREIGN KEY (`evaluation_id`) REFERENCES `evaluation` (`id`) ON DELETE SET NULL;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
