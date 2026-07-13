-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1
-- Generation Time: May 24, 2025 at 09:13 PM
-- Server version: 10.4.32-MariaDB
-- PHP Version: 8.0.30

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
-- Table structure for table `students`
--

CREATE TABLE `students` (
  `id` int(11) NOT NULL,
  `email` varchar(100) NOT NULL,
  `password` varchar(255) NOT NULL,
  `username` varchar(50) NOT NULL,
  `full_name` varchar(100) NOT NULL,
  `no_ic` varchar(20) DEFAULT NULL,
  `no_tel` varchar(15) DEFAULT NULL,
  `intake_year` int(11) DEFAULT NULL,
  `intake_month` varchar(20) DEFAULT NULL,
  `profile_picture` varchar(255) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `students`
--

INSERT INTO `students` (`id`, `email`, `password`, `username`, `full_name`, `no_ic`, `no_tel`, `intake_year`, `intake_month`, `profile_picture`) VALUES
(54, 'studenta@gmail.com', '$2y$10$CKhQ0WI/xGgqi3dgTqeYHuKYKO8U3bK518swAobUQgkExzKUZPjH6', 'studenta', 'Student A', NULL, NULL, 2025, 'May', 'img/undraw_profile.svg'),
(55, 'studentb@gmail.com', '$2y$10$JgTbLUuTgsLwxp0itJ7sl.sDtQtBbOauk/ccz3h88bZ8.l9qFdQA6', 'studentb', 'Student B', NULL, NULL, 2025, 'May', 'img/undraw_profile.svg'),
(56, 'studentc@gmail.com', '$2y$10$o2/jOMFmeTNf5pT7Fl9iAefLJCs4VIRF4r/fWgzFHNFp6dwLaavjG', 'studentc', 'Student C', NULL, NULL, 2025, 'May', 'img/undraw_profile.svg'),
(57, 'studentd@gmail.com', '$2y$10$wmU1grzdap5RUcGhoS1kLuOLBjTemUpW1QWK1g.K17KHAi1wKMhRW', 'studentd', 'Student D', NULL, NULL, 2025, 'May', 'img/undraw_profile.svg'),
(71, 'imran@gmail.com', '$2y$10$JjHKHk6H6s/Hn0BbtAhjT.WIrukxMFLxil3lMTIt4EcKVOqvyqwfS', 'imran', 'IMRAN', NULL, NULL, 2025, 'May', 'img/undraw_profile.svg'),
(72, 'mohamadimran2003@gmail.com', '$2y$10$S4AXeGz5H1knqAnzb8gO3.IwnluryiT.O43W/g6ClBS.b7EFT7hve', 'imranhensem', 'Mohamad Imran bin Mohamad Jiwa', NULL, NULL, 2025, 'May', NULL);

--
-- Indexes for dumped tables
--

--
-- Indexes for table `students`
--
ALTER TABLE `students`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `email` (`email`),
  ADD UNIQUE KEY `username` (`username`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `students`
--
ALTER TABLE `students`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=73;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
