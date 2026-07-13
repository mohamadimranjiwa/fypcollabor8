-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1
-- Generation Time: Jun 02, 2025 at 10:12 PM
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
(21, 56, 10, 'Deliverable 3', 'Uploads/Deliverable 3_56_1746554004.pdf', '2025-05-06 17:53:24', 53),
(22, 56, 11, 'Deliverable 1', 'Uploads/Deliverable 1_53_1746554012.pdf', '2025-05-06 17:53:32', 53),
(23, 55, 10, 'Deliverable 3', 'Uploads/Deliverable 3_55_1747318481.pdf', '2025-05-15 14:14:41', 58);

--
-- Indexes for dumped tables
--

--
-- Indexes for table `deliverable_submissions`
--
ALTER TABLE `deliverable_submissions`
  ADD PRIMARY KEY (`id`),
  ADD KEY `fk_group` (`group_id`),
  ADD KEY `fk_deliverable` (`deliverable_id`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `deliverable_submissions`
--
ALTER TABLE `deliverable_submissions`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=24;

--
-- Constraints for dumped tables
--

--
-- Constraints for table `deliverable_submissions`
--
ALTER TABLE `deliverable_submissions`
  ADD CONSTRAINT `fk_deliverable` FOREIGN KEY (`deliverable_id`) REFERENCES `deliverables` (`id`),
  ADD CONSTRAINT `fk_group` FOREIGN KEY (`group_id`) REFERENCES `groups` (`id`);
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
