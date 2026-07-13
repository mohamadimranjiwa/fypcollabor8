-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1
-- Generation Time: Aug 19, 2025 at 04:30 PM
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
(26, 11, '0-2', 'Minimal or no supervisor consultation.'),
(27, 11, '3-4', 'Occasional consultation with limited engagement.'),
(28, 11, '5-6', 'Regular consultation with moderate engagement.'),
(29, 11, '7-8', 'Frequent consultation with good engagement.'),
(30, 11, '9-10', 'Consistent and high-quality consultation.'),
(31, 12, '0-2', 'Idea lacks originality or feasibility.'),
(32, 12, '3-4', 'Limited originality, needs refinement.'),
(33, 12, '5-6', 'Moderately original with some creativity.'),
(34, 12, '7-8', 'Highly original with clear innovation.'),
(35, 12, '9-10', 'Exceptionally creative and feasible idea.'),
(41, 14, '0-2', 'No significant research progress.'),
(42, 14, '3-4', 'Limited progress with unclear direction.'),
(43, 14, '5-6', 'Moderate progress with some outcomes.'),
(44, 14, '7-8', 'Good progress with clear outcomes.'),
(45, 14, '9-10', 'Excellent progress with documented outcomes.'),
(46, 15, '0-2', 'Fully reliant on supervisor guidance.'),
(47, 15, '3-4', 'Minimal independence in work.'),
(48, 15, '5-6', 'Moderate independence with some initiative.'),
(49, 15, '7-8', 'High independence with consistent initiative.'),
(50, 15, '9-10', 'Exceptional independence and self-driven.'),
(51, 16, '0-2', 'Missed most deadlines, poor time management.'),
(52, 16, '3-4', 'Missed several deadlines, inconsistent progress.'),
(53, 16, '5-6', 'Met most deadlines with adequate planning.'),
(54, 16, '7-8', 'Consistently met deadlines with good planning.'),
(55, 16, '9-10', 'Exemplary adherence to timelines.'),
(56, 17, '0-2', 'Prototype is non-functional or incomplete.'),
(57, 17, '3-4', 'Prototype has limited functionality, major issues.'),
(58, 17, '5-6', 'Prototype is functional with some issues.'),
(59, 17, '7-8', 'Mostly functional prototype, minor issues.'),
(60, 17, '9-10', 'Fully functional prototype, excellent usability.'),
(61, 18, '0-2', 'Poorly designed prototype, usability issues.'),
(62, 18, '3-4', 'Basic design with significant flaws.'),
(63, 18, '5-6', 'Adequate design with moderate usability.'),
(64, 18, '7-8', 'Well-designed prototype, good usability.'),
(65, 18, '9-10', 'Exceptionally designed prototype.'),
(66, 19, '0-2', 'Report is unclear and poorly structured.'),
(67, 19, '3-4', 'Report lacks clarity, structure issues.'),
(68, 19, '5-6', 'Moderately clear report, some structure issues.'),
(69, 19, '7-8', 'Clear and well-structured report, minor issues.'),
(70, 19, '9-10', 'Exceptionally clear and organized report.'),
(71, 20, '0-2', 'Vague or inappropriate methodology.'),
(72, 20, '3-4', 'Methodology lacks detail or feasibility.'),
(73, 20, '5-6', 'Adequate methodology, needs refinement.'),
(74, 20, '7-8', 'Detailed and mostly appropriate methodology.'),
(75, 20, '9-10', 'Comprehensive and highly feasible methodology.'),
(76, 21, '0-2', 'Poorly presented or incomplete results.'),
(77, 21, '3-4', 'Results lack clarity, limited detail.'),
(78, 21, '5-6', 'Adequately presented results, some gaps.'),
(79, 21, '7-8', 'Clearly presented results with good detail.'),
(80, 21, '9-10', 'Exceptionally well-presented results.'),
(121, 30, '0-2', 'Abstract lacks essential research elements.'),
(122, 30, '3-4', 'Abstract misses key research components.'),
(123, 30, '5-6', 'Includes some elements, key info missing.'),
(124, 30, '7-8', 'Most elements included, minor details missing.'),
(125, 30, '9-10', 'Complete abstract with objectives, methods, findings.'),
(126, 31, '0-2', 'Unclear or inadequately stated introduction.'),
(127, 31, '3-4', 'Introduction lacks clarity or detail.'),
(128, 31, '5-6', 'Most info stated, some lack clarity.'),
(129, 31, '7-8', 'Clear introduction, minor clarity issues.'),
(130, 31, '9-10', 'Concise and focused introduction.'),
(131, 32, '0-2', 'Limited, irrelevant, or outdated literature.'),
(132, 32, '3-4', 'Literature review lacks relevance or depth.'),
(133, 32, '5-6', 'Some relevant sources, lacks depth.'),
(134, 32, '7-8', 'Wide range of relevant literature, minor gaps.'),
(135, 32, '9-10', 'Comprehensive and current literature review.'),
(136, 33, '0-2', 'Unclear methodology, incomplete documentation.'),
(137, 33, '3-4', 'Methodology lacks clarity, significant gaps.'),
(138, 33, '5-6', 'Some clarity issues, incomplete documentation.'),
(139, 33, '7-8', 'Clear methodology, minor gaps.'),
(140, 33, '9-10', 'Exceptionally clear and logical methodology.'),
(141, 34, '0-2', 'Unclear or incomplete system design.'),
(142, 34, '3-4', 'Design has significant clarity issues.'),
(143, 34, '5-6', 'Some design aspects unclear or incomplete.'),
(144, 34, '7-8', 'Generally clear design, minor gaps.'),
(145, 34, '9-10', 'Clear and complete system design.'),
(146, 35, '0-2', 'Inadequate test case design and coverage.'),
(147, 35, '3-4', 'Poor test execution, insufficient reporting.'),
(148, 35, '5-6', 'Basic test cases, significant coverage gaps.'),
(149, 35, '7-8', 'Good test cases, minor coverage issues.'),
(150, 35, '9-10', 'Effective test cases, comprehensive coverage.'),
(151, 36, '0-2', 'Incomplete or unclear summary of findings.'),
(152, 36, '3-4', 'Summary lacks clarity or completeness.'),
(153, 36, '5-6', 'General summary, some aspects missing.'),
(154, 36, '7-8', 'Clear summary, minor clarity issues.'),
(155, 36, '9-10', 'Concise and clear summary of findings.'),
(156, 37, '0-2', 'Many irrelevant references.'),
(157, 37, '3-4', 'References lack relevance to project.'),
(158, 37, '5-6', 'Some references not directly relevant.'),
(159, 37, '7-8', 'Most references relevant to project.'),
(160, 37, '9-10', 'All references highly relevant.');

--
-- Indexes for dumped tables
--

--
-- Indexes for table `rubric_score_ranges`
--
ALTER TABLE `rubric_score_ranges`
  ADD PRIMARY KEY (`id`),
  ADD KEY `rubric_id` (`rubric_id`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `rubric_score_ranges`
--
ALTER TABLE `rubric_score_ranges`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=161;

--
-- Constraints for dumped tables
--

--
-- Constraints for table `rubric_score_ranges`
--
ALTER TABLE `rubric_score_ranges`
  ADD CONSTRAINT `rubric_score_ranges_ibfk_1` FOREIGN KEY (`rubric_id`) REFERENCES `rubrics` (`id`) ON DELETE CASCADE;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
