-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: localhost:3307
-- Generation Time: Mar 01, 2026 at 04:16 PM
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
-- Database: `ordinance`
--

-- --------------------------------------------------------

--
-- Table structure for table `ai_amendment_analytics`
--

CREATE TABLE `ai_amendment_analytics` (
  `id` int(11) NOT NULL,
  `amendment_id` int(11) NOT NULL,
  `analytics_type` varchar(50) NOT NULL,
  `data_key` varchar(100) NOT NULL,
  `data_value` text NOT NULL,
  `confidence_score` decimal(5,2) DEFAULT 0.00,
  `generated_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `generated_by` int(11) DEFAULT NULL,
  `is_active` tinyint(1) DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `amendment_approval_signatures`
--

CREATE TABLE `amendment_approval_signatures` (
  `id` int(11) NOT NULL,
  `amendment_id` int(11) NOT NULL,
  `signatory_id` int(11) NOT NULL,
  `signature_type` enum('proposer','reviewer','chairperson','vice_mayor','mayor') NOT NULL,
  `signature_status` enum('pending','signed','rejected') DEFAULT 'pending',
  `signed_at` timestamp NULL DEFAULT NULL,
  `signature_notes` text DEFAULT NULL,
  `digital_signature` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `amendment_approval_workflow`
--

CREATE TABLE `amendment_approval_workflow` (
  `id` int(11) NOT NULL,
  `amendment_id` int(11) NOT NULL,
  `workflow_step` varchar(50) NOT NULL,
  `assigned_to` int(11) DEFAULT NULL,
  `assigned_at` timestamp NULL DEFAULT NULL,
  `completed_at` timestamp NULL DEFAULT NULL,
  `status` enum('pending','in_progress','approved','rejected','returned') DEFAULT 'pending',
  `comments` text DEFAULT NULL,
  `action_by` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `amendment_attachments`
--

CREATE TABLE `amendment_attachments` (
  `id` int(11) NOT NULL,
  `amendment_id` int(11) NOT NULL,
  `file_name` varchar(255) NOT NULL,
  `file_path` varchar(500) NOT NULL,
  `file_type` varchar(100) DEFAULT NULL,
  `file_size` int(11) DEFAULT NULL,
  `description` text DEFAULT NULL,
  `attachment_type` enum('supporting_doc','legal_opinion','fiscal_note','public_input','comparison_chart') DEFAULT 'supporting_doc',
  `uploaded_by` int(11) DEFAULT NULL,
  `uploaded_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `amendment_authors`
--

CREATE TABLE `amendment_authors` (
  `id` int(11) NOT NULL,
  `amendment_id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `role` enum('author','sponsor','co_sponsor','proponent') DEFAULT 'author',
  `assigned_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `assigned_by` int(11) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `amendment_comparisons`
--

CREATE TABLE `amendment_comparisons` (
  `id` int(11) NOT NULL,
  `amendment_id` int(11) NOT NULL,
  `old_section` varchar(100) DEFAULT NULL,
  `new_section` varchar(100) DEFAULT NULL,
  `old_text` longtext DEFAULT NULL,
  `new_text` longtext DEFAULT NULL,
  `change_type` enum('addition','deletion','modification','replacement','reorganization') NOT NULL,
  `change_description` text DEFAULT NULL,
  `line_numbers` varchar(100) DEFAULT NULL,
  `affected_clauses` text DEFAULT NULL,
  `legal_impact` text DEFAULT NULL,
  `fiscal_impact` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `amendment_history`
--

CREATE TABLE `amendment_history` (
  `id` int(11) NOT NULL,
  `amendment_id` int(11) NOT NULL,
  `action` varchar(100) NOT NULL,
  `description` text DEFAULT NULL,
  `old_value` text DEFAULT NULL,
  `new_value` text DEFAULT NULL,
  `performed_by` int(11) DEFAULT NULL,
  `performed_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `amendment_reviews`
--

CREATE TABLE `amendment_reviews` (
  `id` int(11) NOT NULL,
  `amendment_id` int(11) NOT NULL,
  `reviewed_by` int(11) DEFAULT NULL,
  `review_type` enum('legal','fiscal','technical','committee','public') NOT NULL,
  `status` enum('pending','reviewing','approved','needs_revision','rejected') DEFAULT 'pending',
  `comments` text DEFAULT NULL,
  `recommendations` text DEFAULT NULL,
  `review_date` timestamp NULL DEFAULT NULL,
  `deadline` date DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `amendment_submissions`
--

CREATE TABLE `amendment_submissions` (
  `id` int(11) NOT NULL,
  `document_id` int(11) NOT NULL,
  `document_type` enum('ordinance','resolution') NOT NULL,
  `amendment_number` varchar(50) DEFAULT NULL,
  `title` varchar(255) NOT NULL,
  `description` text DEFAULT NULL,
  `proposed_changes` longtext NOT NULL,
  `justification` text DEFAULT NULL,
  `current_version_id` int(11) NOT NULL,
  `proposed_version_id` int(11) DEFAULT NULL,
  `status` enum('draft','pending','under_review','approved','rejected','withdrawn') DEFAULT 'draft',
  `priority` enum('low','medium','high','urgent','emergency') DEFAULT 'medium',
  `submitted_by` int(11) DEFAULT NULL,
  `submitted_at` timestamp NULL DEFAULT NULL,
  `reviewed_by` int(11) DEFAULT NULL,
  `reviewed_at` timestamp NULL DEFAULT NULL,
  `approved_by` int(11) DEFAULT NULL,
  `approved_at` timestamp NULL DEFAULT NULL,
  `rejection_reason` text DEFAULT NULL,
  `requires_committee_review` tinyint(1) DEFAULT 0,
  `committee_id` int(11) DEFAULT NULL,
  `committee_status` enum('pending','reviewing','recommended','not_recommended') DEFAULT 'pending',
  `committee_notes` text DEFAULT NULL,
  `public_hearing_required` tinyint(1) DEFAULT 0,
  `public_hearing_date` date DEFAULT NULL,
  `effectivity_date` date DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `amendment_voting`
--

CREATE TABLE `amendment_voting` (
  `id` int(11) NOT NULL,
  `amendment_id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `vote_type` enum('yes','no','abstain','absent') NOT NULL,
  `vote_notes` text DEFAULT NULL,
  `voted_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `audit_logs`
--

CREATE TABLE `audit_logs` (
  `id` int(11) NOT NULL,
  `user_id` int(11) DEFAULT NULL,
  `action` varchar(100) NOT NULL,
  `description` text DEFAULT NULL,
  `ip_address` varchar(45) DEFAULT NULL,
  `user_agent` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `audit_logs`
--

INSERT INTO `audit_logs` (`id`, `user_id`, `action`, `description`, `ip_address`, `user_agent`, `created_at`) VALUES
(1, 1, 'LOGIN', 'User logged in successfully', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/144.0.0.0 Safari/537.36', '2026-02-07 14:40:10'),
(2, 1, 'LOGIN', 'User logged in successfully', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/144.0.0.0 Safari/537.36', '2026-02-07 15:07:01'),
(3, 1, 'LOGIN', 'User logged in successfully', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/144.0.0.0 Safari/537.36', '2026-02-07 15:49:20'),
(4, 1, 'LOGIN', 'User logged in successfully', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/144.0.0.0 Safari/537.36', '2026-02-07 16:09:25'),
(5, 1, 'LOGIN', 'User logged in successfully', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/144.0.0.0 Safari/537.36', '2026-02-09 13:10:54'),
(6, 1, 'LOGIN', 'User logged in successfully', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/144.0.0.0 Safari/537.36', '2026-02-09 14:07:03'),
(7, 1, 'DRAFT_CREATE', 'Created new draft: QC-ORD-2026-02-001', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/144.0.0.0 Safari/537.36', '2026-02-09 16:32:31'),
(8, 1, 'DRAFT_CREATE', 'Created new draft: QC-ORD-2026-02-002', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/144.0.0.0 Safari/537.36', '2026-02-09 16:32:40'),
(9, 1, 'DRAFT_CREATE', 'Created new draft: QC-ORD-2026-02-003', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/144.0.0.0 Safari/537.36', '2026-02-09 16:33:07'),
(10, 1, 'DRAFT_CREATE', 'Created new draft: QC-ORD-2026-02-004', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/144.0.0.0 Safari/537.36', '2026-02-09 16:45:04'),
(11, 1, 'DRAFT_CREATE', 'Created new draft: QC-ORD-2026-02-005', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/144.0.0.0 Safari/537.36', '2026-02-09 17:54:13'),
(12, 1, 'TEMPLATE_USE', 'Used template ID: 5', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/144.0.0.0 Safari/537.36', '2026-02-09 18:29:47'),
(13, 1, 'TEMPLATE_CREATE', 'Created new template: asdasd', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/144.0.0.0 Safari/537.36', '2026-02-09 18:30:40'),
(14, 1, 'TEMPLATE_FAVORITE', 'Added template to favorites: 2', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/144.0.0.0 Safari/537.36', '2026-02-09 18:34:28'),
(15, NULL, 'FAILED_LOGIN', 'Failed login attempt for email: superadmin@qc.gov.ph', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/144.0.0.0 Safari/537.36', '2026-02-09 18:48:48'),
(16, 1, 'LOGIN', 'User logged in successfully', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/144.0.0.0 Safari/537.36', '2026-02-09 18:48:52'),
(17, 1, 'TEMPLATE_CREATE', 'Created new template: 1111111111', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/144.0.0.0 Safari/537.36', '2026-02-09 18:49:28'),
(18, 1, 'LOGIN', 'User logged in successfully', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/144.0.0.0 Safari/537.36', '2026-02-09 19:12:59'),
(19, 1, 'TEMPLATE_UNFAVORITE', 'Removed template from favorites: 2', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/144.0.0.0 Safari/537.36', '2026-02-09 19:13:18'),
(20, 1, 'TEMPLATE_CREATE', 'Created new template: asddddddddd', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/144.0.0.0 Safari/537.36', '2026-02-09 19:13:31'),
(21, 1, 'LOGIN', 'User logged in successfully', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/144.0.0.0 Safari/537.36', '2026-02-09 22:37:31'),
(22, 1, 'TEMPLATE_USE', 'Used template ID: 5', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/144.0.0.0 Safari/537.36', '2026-02-10 01:08:34'),
(23, 1, 'DOCUMENT_UPLOAD', 'Uploaded supporting document for ordinance: ivan', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/144.0.0.0 Safari/537.36', '2026-02-10 01:11:35'),
(24, 1, 'DRAFT_REGISTER', 'Registered draft: QC-ORD-2026-02-005 with registration: QC-REG-ORD-2026-02-0001', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/144.0.0.0 Safari/537.36', '2026-02-10 01:12:07'),
(25, 1, 'LOGIN', 'User logged in successfully', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/144.0.0.0 Safari/537.36', '2026-02-10 03:33:58'),
(26, 1, 'TEMPLATE_USE', 'Used template ID: 8', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/144.0.0.0 Safari/537.36', '2026-02-10 03:34:22'),
(27, 1, 'TEMPLATE_FAVORITE', 'Added template to favorites: 8', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/144.0.0.0 Safari/537.36', '2026-02-10 03:34:27'),
(28, 1, 'TEMPLATE_UNFAVORITE', 'Removed template from favorites: 8', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/144.0.0.0 Safari/537.36', '2026-02-10 03:34:34'),
(29, 1, 'PRIORITY_SET', 'Updated priority for QC-ORD-2026-02-005 to emergency', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/144.0.0.0 Safari/537.36', '2026-02-10 03:53:52'),
(30, 1, 'PRIORITY_SET', 'Updated priority for QC-ORD-2026-02-005 to emergency', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/144.0.0.0 Safari/537.36', '2026-02-10 03:54:00'),
(31, 1, 'PRIORITY_SET', 'Updated priority for QC-ORD-2026-02-005 to emergency', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/144.0.0.0 Safari/537.36', '2026-02-10 03:54:26'),
(32, 1, 'LOGIN', 'User logged in successfully', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/144.0.0.0 Safari/537.36', '2026-02-10 06:01:56'),
(33, 1, 'COMPARISON_CREATE', 'Created comparison session: COMP-ORD-20260210084028-88f107', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/144.0.0.0 Safari/537.36', '2026-02-10 07:40:28'),
(34, 1, 'COMPARISON_CREATE', 'Created comparison session: COMP-ORD-20260210084046-213de5', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/144.0.0.0 Safari/537.36', '2026-02-10 07:40:46'),
(35, NULL, 'FAILED_LOGIN', 'Failed login attempt for email: superadmin@qc.gov.ph', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/145.0.0.0 Safari/537.36', '2026-03-01 15:16:17'),
(36, NULL, 'FAILED_LOGIN', 'Failed login attempt for email: superadmin@qc.gov.ph', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/145.0.0.0 Safari/537.36', '2026-03-01 15:16:21'),
(37, 1, 'LOGIN', 'User logged in successfully', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/145.0.0.0 Safari/537.36', '2026-03-01 15:16:26');

-- --------------------------------------------------------

--
-- Table structure for table `change_comments`
--

CREATE TABLE `change_comments` (
  `id` int(11) NOT NULL,
  `highlight_id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `comment` text NOT NULL,
  `is_resolved` tinyint(1) DEFAULT 0,
  `resolved_by` int(11) DEFAULT NULL,
  `resolved_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `change_highlights`
--

CREATE TABLE `change_highlights` (
  `id` int(11) NOT NULL,
  `session_id` int(11) NOT NULL,
  `old_text` longtext DEFAULT NULL,
  `new_text` longtext DEFAULT NULL,
  `change_type` enum('addition','deletion','modification','reorganization','formatting') NOT NULL,
  `section` varchar(100) DEFAULT NULL,
  `line_start` int(11) DEFAULT NULL,
  `line_end` int(11) DEFAULT NULL,
  `importance` enum('low','medium','high','critical') DEFAULT 'medium',
  `notes` text DEFAULT NULL,
  `created_by` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `change_highlights`
--

INSERT INTO `change_highlights` (`id`, `session_id`, `old_text`, `new_text`, `change_type`, `section`, `line_start`, `line_end`, `importance`, `notes`, `created_by`, `created_at`) VALUES
(1, 1, 'Original text for Preamble line 23', 'Modified text for Preamble with updated provisions', 'reorganization', 'Preamble', NULL, NULL, 'low', 'Updates outdated provisions', 1, '2026-02-10 07:40:28'),
(2, 1, NULL, 'Modified text for Penalties with updated provisions', 'addition', 'Penalties', NULL, NULL, 'high', 'Clarifies ambiguous language', 1, '2026-02-10 07:40:28'),
(3, 1, 'Original text for Definitions line 16', 'Modified text for Definitions with updated provisions', 'modification', 'Definitions', NULL, NULL, 'medium', 'Review this modification carefully', 1, '2026-02-10 07:40:28'),
(4, 1, NULL, 'Modified text for Section 2 with updated provisions', 'addition', 'Section 2', NULL, NULL, 'high', 'Important change in Section 2', 1, '2026-02-10 07:40:28'),
(5, 1, NULL, 'Modified text for Preamble with updated provisions', 'addition', 'Preamble', NULL, NULL, 'medium', 'Clarifies ambiguous language', 1, '2026-02-10 07:40:28'),
(6, 1, 'Original text for Section 2 line 25', NULL, 'deletion', 'Section 2', NULL, NULL, 'critical', 'Review this modification carefully', 1, '2026-02-10 07:40:28'),
(7, 2, 'Original text for Section 2 line 31', NULL, 'deletion', 'Section 2', NULL, NULL, 'low', 'Adds enforcement mechanism', 1, '2026-02-10 07:40:46'),
(8, 2, 'Original text for Penalties line 17', 'Modified text for Penalties with updated provisions', 'modification', 'Penalties', NULL, NULL, 'critical', 'Significant impact on implementation', 1, '2026-02-10 07:40:46'),
(9, 2, 'Original text for Section 1 line 24', 'Modified text for Section 1 with updated provisions', 'reorganization', 'Section 1', NULL, NULL, 'low', 'Adds enforcement mechanism', 1, '2026-02-10 07:40:46'),
(10, 2, 'Original text for Penalties line 9', NULL, 'deletion', 'Penalties', NULL, NULL, 'critical', 'Updates outdated provisions', 1, '2026-02-10 07:40:46'),
(11, 2, 'Original text for Section 5 line 21', NULL, 'deletion', 'Section 5', NULL, NULL, 'high', 'Important change in Section 5', 1, '2026-02-10 07:40:46'),
(12, 2, 'Original text for Section 4 line 37', 'Modified text for Section 4 with updated provisions', 'modification', 'Section 4', NULL, NULL, 'high', 'Clarifies ambiguous language', 1, '2026-02-10 07:40:46'),
(13, 2, 'Original text for Section 5 line 23', 'Modified text for Section 5 with updated provisions', 'modification', 'Section 5', NULL, NULL, 'critical', 'Significant impact on implementation', 1, '2026-02-10 07:40:46'),
(14, 2, NULL, 'Modified text for Penalties with updated provisions', 'addition', 'Penalties', NULL, NULL, 'medium', 'Adds enforcement mechanism', 1, '2026-02-10 07:40:46');

-- --------------------------------------------------------

--
-- Table structure for table `committees`
--

CREATE TABLE `committees` (
  `id` int(11) NOT NULL,
  `committee_name` varchar(100) NOT NULL,
  `committee_code` varchar(20) NOT NULL,
  `description` text DEFAULT NULL,
  `chairperson_id` int(11) DEFAULT NULL,
  `vice_chairperson_id` int(11) DEFAULT NULL,
  `is_active` tinyint(1) DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `committees`
--

INSERT INTO `committees` (`id`, `committee_name`, `committee_code`, `description`, `chairperson_id`, `vice_chairperson_id`, `is_active`, `created_at`, `updated_at`) VALUES
(1, 'Committee on Appropriations', 'APPROP', 'Handles budget and financial matters', 1, 2, 1, '2026-02-09 23:10:32', '2026-02-09 23:10:32'),
(2, 'Committee on Laws, Rules and Internal Government', 'LAWS', 'Reviews legal aspects and internal governance', 2, 3, 1, '2026-02-09 23:10:32', '2026-02-09 23:10:32'),
(3, 'Committee on Public Order and Safety', 'SAFETY', 'Oversees public safety and order', 3, 4, 1, '2026-02-09 23:10:32', '2026-02-09 23:10:32'),
(4, 'Committee on Health', 'HEALTH', 'Handles health-related matters', 4, 1, 1, '2026-02-09 23:10:32', '2026-02-09 23:10:32'),
(5, 'Committee on Education', 'EDUC', 'Oversees educational matters', 1, 3, 1, '2026-02-09 23:10:32', '2026-02-09 23:10:32'),
(6, 'Committee on Environment', 'ENVI', 'Handles environmental protection', 2, 4, 1, '2026-02-09 23:10:32', '2026-02-09 23:10:32'),
(7, 'Committee on Public Works', 'WORKS', 'Oversees infrastructure projects', 3, 1, 1, '2026-02-09 23:10:32', '2026-02-09 23:10:32'),
(8, 'Committee on Transportation', 'TRANS', 'Handles transportation matters', 4, 2, 1, '2026-02-09 23:10:32', '2026-02-09 23:10:32');

-- --------------------------------------------------------

--
-- Table structure for table `committee_members`
--

CREATE TABLE `committee_members` (
  `id` int(11) NOT NULL,
  `committee_id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `role` enum('member','secretary','treasurer') DEFAULT 'member',
  `joined_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `assigned_by` int(11) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `committee_members`
--

INSERT INTO `committee_members` (`id`, `committee_id`, `user_id`, `role`, `joined_at`, `assigned_by`) VALUES
(1, 1, 1, 'member', '2026-02-09 23:10:32', 1),
(2, 1, 2, 'secretary', '2026-02-09 23:10:32', 1),
(3, 2, 2, 'member', '2026-02-09 23:10:32', 1),
(4, 2, 3, 'member', '2026-02-09 23:10:32', 1),
(5, 3, 3, 'member', '2026-02-09 23:10:32', 1),
(6, 3, 4, 'member', '2026-02-09 23:10:32', 1),
(7, 4, 1, 'member', '2026-02-09 23:10:32', 1),
(8, 4, 4, 'member', '2026-02-09 23:10:32', 1),
(9, 5, 1, 'member', '2026-02-09 23:10:32', 1),
(10, 5, 3, 'member', '2026-02-09 23:10:32', 1),
(11, 6, 2, 'member', '2026-02-09 23:10:32', 1),
(12, 6, 4, 'member', '2026-02-09 23:10:32', 1),
(13, 7, 3, 'member', '2026-02-09 23:10:32', 1),
(14, 7, 1, 'member', '2026-02-09 23:10:32', 1),
(15, 8, 4, 'member', '2026-02-09 23:10:32', 1),
(16, 8, 2, 'member', '2026-02-09 23:10:32', 1);

-- --------------------------------------------------------

--
-- Table structure for table `comparison_analytics`
--

CREATE TABLE `comparison_analytics` (
  `id` int(11) NOT NULL,
  `session_id` int(11) NOT NULL,
  `analytics_type` enum('complexity','impact','similarity','legal_risk','fiscal_impact') NOT NULL,
  `score` decimal(5,2) DEFAULT 0.00,
  `confidence` decimal(5,2) DEFAULT 0.00,
  `details` text DEFAULT NULL,
  `recommendations` text DEFAULT NULL,
  `generated_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `comparison_analytics`
--

INSERT INTO `comparison_analytics` (`id`, `session_id`, `analytics_type`, `score`, `confidence`, `details`, `recommendations`, `generated_at`, `updated_at`) VALUES
(6, 1, 'complexity', 36.70, 79.58, 'Analysis complete. 15 significant changes detected across 4 sections.', 'Consider breaking into multiple amendments if too complex.', '2026-02-10 07:40:28', '2026-02-10 07:40:28'),
(7, 1, 'impact', 34.14, 84.50, 'Medium impact assessment. Affects 3 departments and 3 existing ordinances.', 'Coordinate with affected departments before committee review.', '2026-02-10 07:40:28', '2026-02-10 07:40:28'),
(8, 1, 'similarity', 72.79, 79.35, '0.0% similarity score. High similarity to existing documents.', 'Check for conflicts with existing legislation.', '2026-02-10 07:40:28', '2026-02-10 07:40:28'),
(9, 1, 'legal_risk', 83.88, 60.09, 'Legal review recommended for 1 sections. Low risk overall.', 'Consult with City Legal Office before final approval.', '2026-02-10 07:40:28', '2026-02-10 07:40:28'),
(10, 1, 'fiscal_impact', 85.97, 66.77, 'Estimated PHP 2550K annual impact.', 'Requires detailed fiscal note and budget allocation.', '2026-02-10 07:40:28', '2026-02-10 07:40:28'),
(11, 2, 'complexity', 65.19, 94.55, 'Analysis complete. 11 significant changes detected across 7 sections.', 'Consider breaking into multiple amendments if too complex.', '2026-02-10 07:40:46', '2026-02-10 07:40:46'),
(12, 2, 'impact', 41.74, 94.96, 'Medium impact assessment. Affects 4 departments and 1 existing ordinances.', 'Coordinate with affected departments before committee review.', '2026-02-10 07:40:46', '2026-02-10 07:40:46'),
(13, 2, 'similarity', 62.64, 95.62, '0.0% similarity score. High similarity to existing documents.', 'Check for conflicts with existing legislation.', '2026-02-10 07:40:46', '2026-02-10 07:40:46'),
(14, 2, 'legal_risk', 38.60, 89.75, 'Legal review recommended for 1 sections. Moderate risk identified.', 'Consult with City Legal Office before final approval.', '2026-02-10 07:40:46', '2026-02-10 07:40:46'),
(15, 2, 'fiscal_impact', 45.76, 81.55, 'Estimated PHP 1009K annual impact.', 'Requires detailed fiscal note and budget allocation.', '2026-02-10 07:40:46', '2026-02-10 07:40:46');

-- --------------------------------------------------------

--
-- Table structure for table `comparison_sessions`
--

CREATE TABLE `comparison_sessions` (
  `id` int(11) NOT NULL,
  `session_code` varchar(50) NOT NULL,
  `document_id` int(11) NOT NULL,
  `document_type` enum('ordinance','resolution') NOT NULL,
  `old_version_id` int(11) NOT NULL,
  `new_version_id` int(11) NOT NULL,
  `created_by` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `last_viewed` timestamp NULL DEFAULT NULL,
  `view_count` int(11) DEFAULT 0,
  `is_active` tinyint(1) DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `comparison_sessions`
--

INSERT INTO `comparison_sessions` (`id`, `session_code`, `document_id`, `document_type`, `old_version_id`, `new_version_id`, `created_by`, `created_at`, `last_viewed`, `view_count`, `is_active`) VALUES
(1, 'COMP-ORD-20260210084028-88f107', 5, 'ordinance', 5, 2, 1, '2026-02-10 07:40:28', '2026-02-10 07:40:36', 2, 1),
(2, 'COMP-ORD-20260210084046-213de5', 5, 'ordinance', 5, 2, 1, '2026-02-10 07:40:46', '2026-02-10 07:40:46', 1, 1);

-- --------------------------------------------------------

--
-- Table structure for table `comparison_shares`
--

CREATE TABLE `comparison_shares` (
  `id` int(11) NOT NULL,
  `session_id` int(11) NOT NULL,
  `shared_with` int(11) NOT NULL,
  `shared_by` int(11) DEFAULT NULL,
  `permissions` enum('view','comment','edit') DEFAULT 'view',
  `shared_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `expires_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `document_authors`
--

CREATE TABLE `document_authors` (
  `id` int(11) NOT NULL,
  `document_id` int(11) NOT NULL,
  `document_type` enum('ordinance','resolution') NOT NULL,
  `user_id` int(11) NOT NULL,
  `role` enum('author','sponsor','co_sponsor') DEFAULT 'author',
  `assigned_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `assigned_by` int(11) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `document_authors`
--

INSERT INTO `document_authors` (`id`, `document_id`, `document_type`, `user_id`, `role`, `assigned_at`, `assigned_by`) VALUES
(2, 2, 'ordinance', 1, 'author', '2026-02-09 16:32:40', 1),
(3, 3, 'ordinance', 1, 'author', '2026-02-09 16:33:07', 1),
(4, 4, 'ordinance', 1, 'author', '2026-02-09 16:45:04', 1),
(6, 5, 'ordinance', 3, 'author', '2026-02-10 00:05:17', 1),
(7, 1, 'ordinance', 3, 'author', '2026-02-10 00:05:17', 1);

-- --------------------------------------------------------

--
-- Table structure for table `document_categories`
--

CREATE TABLE `document_categories` (
  `id` int(11) NOT NULL,
  `category_name` varchar(100) NOT NULL,
  `category_code` varchar(20) NOT NULL,
  `description` text DEFAULT NULL,
  `parent_id` int(11) DEFAULT NULL,
  `is_active` tinyint(1) DEFAULT 1,
  `created_by` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `document_categories`
--

INSERT INTO `document_categories` (`id`, `category_name`, `category_code`, `description`, `parent_id`, `is_active`, `created_by`, `created_at`, `updated_at`) VALUES
(1, 'Administrative', 'ADMIN', 'Administrative matters, procedures, and policies', NULL, 1, 1, '2026-02-10 03:18:45', '2026-02-10 03:18:45'),
(2, 'Revenue & Taxation', 'TAX', 'Taxation, fees, revenue generation', NULL, 1, 1, '2026-02-10 03:18:45', '2026-02-10 03:18:45'),
(3, 'Public Safety', 'SAFETY', 'Peace and order, fire prevention, disaster management', NULL, 1, 1, '2026-02-10 03:18:45', '2026-02-10 03:18:45'),
(4, 'Health & Sanitation', 'HEALTH', 'Public health, sanitation, hospital services', NULL, 1, 1, '2026-02-10 03:18:45', '2026-02-10 03:18:45'),
(5, 'Education', 'EDUC', 'Education, scholarship, school facilities', NULL, 1, 1, '2026-02-10 03:18:45', '2026-02-10 03:18:45'),
(6, 'Environment', 'ENVI', 'Environment protection, waste management', NULL, 1, 1, '2026-02-10 03:18:45', '2026-02-10 03:18:45'),
(7, 'Infrastructure', 'INFRA', 'Public works, roads, bridges, buildings', NULL, 1, 1, '2026-02-10 03:18:45', '2026-02-10 03:18:45'),
(8, 'Transportation', 'TRANS', 'Traffic, transportation, public utility vehicles', NULL, 1, 1, '2026-02-10 03:18:45', '2026-02-10 03:18:45'),
(9, 'Business & Trade', 'BUSINESS', 'Business permits, trade, commerce', NULL, 1, 1, '2026-02-10 03:18:45', '2026-02-10 03:18:45'),
(10, 'Social Welfare', 'WELFARE', 'Social services, welfare programs', NULL, 1, 1, '2026-02-10 03:18:45', '2026-02-10 03:18:45'),
(11, 'Culture & Arts', 'CULTURE', 'Cultural affairs, arts, tourism', NULL, 1, 1, '2026-02-10 03:18:45', '2026-02-10 03:18:45'),
(12, 'Sports & Recreation', 'SPORTS', 'Sports, recreation, parks', NULL, 1, 1, '2026-02-10 03:18:45', '2026-02-10 03:18:45');

-- --------------------------------------------------------

--
-- Table structure for table `document_classification`
--

CREATE TABLE `document_classification` (
  `id` int(11) NOT NULL,
  `document_id` int(11) NOT NULL,
  `document_type` enum('ordinance','resolution') NOT NULL,
  `classification_type` enum('ordinance','resolution','amendment','memorandum','order') NOT NULL,
  `reference_number` varchar(50) DEFAULT NULL,
  `category_id` int(11) DEFAULT NULL,
  `priority_level` enum('low','medium','high','urgent','emergency') DEFAULT 'medium',
  `status` enum('pending','classified','reviewed','approved','rejected') DEFAULT 'pending',
  `classification_notes` text DEFAULT NULL,
  `classified_by` int(11) DEFAULT NULL,
  `classified_at` timestamp NULL DEFAULT NULL,
  `reviewed_by` int(11) DEFAULT NULL,
  `reviewed_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `document_classification`
--

INSERT INTO `document_classification` (`id`, `document_id`, `document_type`, `classification_type`, `reference_number`, `category_id`, `priority_level`, `status`, `classification_notes`, `classified_by`, `classified_at`, `reviewed_by`, `reviewed_at`, `created_at`, `updated_at`) VALUES
(1, 5, 'ordinance', 'ordinance', NULL, NULL, 'emergency', 'classified', NULL, 1, '2026-02-10 03:54:26', NULL, NULL, '2026-02-10 03:53:52', '2026-02-10 03:54:26');

-- --------------------------------------------------------

--
-- Table structure for table `document_committees`
--

CREATE TABLE `document_committees` (
  `id` int(11) NOT NULL,
  `document_id` int(11) NOT NULL,
  `document_type` enum('ordinance','resolution') NOT NULL,
  `committee_id` int(11) NOT NULL,
  `assignment_type` enum('primary','secondary','review','recommending') DEFAULT 'primary',
  `assigned_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `assigned_by` int(11) DEFAULT NULL,
  `status` enum('pending','reviewing','approved','rejected') DEFAULT 'pending',
  `comments` text DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `document_committees`
--

INSERT INTO `document_committees` (`id`, `document_id`, `document_type`, `committee_id`, `assignment_type`, `assigned_at`, `assigned_by`, `status`, `comments`) VALUES
(1, 5, 'ordinance', 1, 'primary', '2026-02-10 01:12:07', 1, 'pending', NULL);

-- --------------------------------------------------------

--
-- Table structure for table `document_keywords`
--

CREATE TABLE `document_keywords` (
  `id` int(11) NOT NULL,
  `document_id` int(11) NOT NULL,
  `document_type` enum('ordinance','resolution') NOT NULL,
  `keyword` varchar(100) NOT NULL,
  `weight` int(11) DEFAULT 1,
  `added_by` int(11) DEFAULT NULL,
  `added_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `document_numbering_logs`
--

CREATE TABLE `document_numbering_logs` (
  `id` int(11) NOT NULL,
  `document_id` int(11) NOT NULL,
  `document_type` enum('ordinance','resolution') NOT NULL,
  `old_number` varchar(50) DEFAULT NULL,
  `new_number` varchar(50) NOT NULL,
  `reason` text DEFAULT NULL,
  `changed_by` int(11) DEFAULT NULL,
  `changed_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `document_priority_history`
--

CREATE TABLE `document_priority_history` (
  `id` int(11) NOT NULL,
  `document_id` int(11) NOT NULL,
  `document_type` enum('ordinance','resolution') NOT NULL,
  `previous_priority` enum('low','medium','high','urgent','emergency') DEFAULT NULL,
  `new_priority` enum('low','medium','high','urgent','emergency') NOT NULL,
  `reason` text DEFAULT NULL,
  `changed_by` int(11) DEFAULT NULL,
  `changed_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `document_priority_history`
--

INSERT INTO `document_priority_history` (`id`, `document_id`, `document_type`, `previous_priority`, `new_priority`, `reason`, `changed_by`, `changed_at`) VALUES
(1, 5, 'ordinance', 'medium', 'emergency', '', 1, '2026-02-10 03:53:52'),
(2, 5, 'ordinance', 'emergency', 'emergency', '', 1, '2026-02-10 03:54:00'),
(3, 5, 'ordinance', 'emergency', 'emergency', '', 1, '2026-02-10 03:54:26');

-- --------------------------------------------------------

--
-- Table structure for table `document_templates`
--

CREATE TABLE `document_templates` (
  `id` int(11) NOT NULL,
  `template_name` varchar(100) NOT NULL,
  `template_type` enum('ordinance','resolution','amendment') NOT NULL,
  `category` varchar(50) DEFAULT 'general',
  `description` text DEFAULT NULL,
  `thumbnail` varchar(500) DEFAULT NULL,
  `content` longtext NOT NULL,
  `variables` text DEFAULT NULL,
  `is_active` tinyint(1) DEFAULT 1,
  `use_count` int(11) DEFAULT 0,
  `last_used` timestamp NULL DEFAULT NULL,
  `created_by` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `document_templates`
--

INSERT INTO `document_templates` (`id`, `template_name`, `template_type`, `category`, `description`, `thumbnail`, `content`, `variables`, `is_active`, `use_count`, `last_used`, `created_by`, `created_at`, `updated_at`) VALUES
(1, 'Standard Ordinance Template', 'ordinance', 'Administrative', 'Standard template for creating ordinances', NULL, '<div class=\"ordinance-template\">\n    <div class=\"ordinance-header\">\n        <h1>CITY ORDINANCE NO. ______</h1>\n        <h2>[TITLE OF ORDINANCE]</h2>\n        <p>Sponsored by: [SPONSOR NAMES]</p>\n    </div>\n    \n    <div class=\"ordinance-body\">\n        <h3>SECTION 1. TITLE</h3>\n        <p>This Ordinance shall be known as the \"[FULL TITLE]\" Ordinance.</p>\n        \n        <h3>SECTION 2. DECLARATION OF POLICY</h3>\n        <p>It is hereby declared the policy of the City Government of Quezon...</p>\n        \n        <h3>SECTION 3. DEFINITION OF TERMS</h3>\n        <p>As used in this Ordinance:</p>\n        <ul>\n            <li>(a) Term 1 - Definition</li>\n            <li>(b) Term 2 - Definition</li>\n        </ul>\n        \n        <h3>SECTION 4. PROVISIONS</h3>\n        <p>[Main provisions of the ordinance]</p>\n        \n        <h3>SECTION 5. IMPLEMENTING RULES AND REGULATIONS</h3>\n        <p>The City Mayor shall issue the necessary rules and regulations...</p>\n        \n        <h3>SECTION 6. SEPARABILITY CLAUSE</h3>\n        <p>If any provision of this Ordinance is declared invalid...</p>\n        \n        <h3>SECTION 7. REPEALING CLAUSE</h3>\n        <p>All ordinances, resolutions, or parts thereof inconsistent herewith are hereby repealed or modified accordingly.</p>\n        \n        <h3>SECTION 8. EFFECTIVITY</h3>\n        <p>This Ordinance shall take effect fifteen (15) days after its publication in a newspaper of general circulation.</p>\n    </div>\n    \n    <div class=\"ordinance-footer\">\n        <p>ENACTED by the Sangguniang Panlungsod of Quezon City in a regular session assembled.</p>\n        <p>[DATE OF APPROVAL]</p>\n        <p>_________________________________</p>\n        <p>City Vice Mayor & Presiding Officer</p>\n    </div>\n</div>', NULL, 1, 0, NULL, 1, '2026-02-09 16:09:20', '2026-02-09 18:15:56'),
(2, 'Standard Resolution Template', 'resolution', 'General Resolutions', 'Standard template for creating resolutions', NULL, '<div class=\"resolution-template\">\n    <div class=\"resolution-header\">\n        <h1>RESOLUTION NO. ______</h1>\n        <h2>[TITLE OF RESOLUTION]</h2>\n        <p>Sponsored by: [SPONSOR NAMES]</p>\n    </div>\n    \n    <div class=\"resolution-body\">\n        <h3>WHEREAS,</h3>\n        <p>[First whereas clause]</p>\n        \n        <h3>WHEREAS,</h3>\n        <p>[Second whereas clause]</p>\n        \n        <h3>WHEREAS,</h3>\n        <p>[Third whereas clause]</p>\n        \n        <h3>NOW, THEREFORE,</h3>\n        <p>Be it RESOLVED, as it is hereby RESOLVED by the Sangguniang Panlungsod of Quezon City in session assembled:</p>\n        \n        <h3>SECTION 1.</h3>\n        <p>[First resolving clause]</p>\n        \n        <h3>SECTION 2.</h3>\n        <p>[Second resolving clause]</p>\n        \n        <h3>SECTION 3.</h3>\n        <p>[Third resolving clause]</p>\n    </div>\n    \n    <div class=\"resolution-footer\">\n        <p>RESOLVED this ______ day of ____________, 20___.</p>\n        <p>_________________________________</p>\n        <p>City Vice Mayor & Presiding Officer</p>\n        <p>ATTESTED:</p>\n        <p>_________________________________</p>\n        <p>Secretary to the Sanggunian</p>\n    </div>\n</div>', NULL, 1, 0, NULL, 1, '2026-02-09 16:09:20', '2026-02-09 18:15:56'),
(3, 'Environmental Ordinance Template', 'ordinance', 'Environmental', 'Template for environmental protection ordinances', NULL, '<div class=\"ordinance-template\">\n    <div class=\"ordinance-header\">\n        <h1>CITY ORDINANCE NO. ______</h1>\n        <h2>AN ORDINANCE PROTECTING THE ENVIRONMENT OF QUEZON CITY</h2>\n        <p>Sponsored by: [SPONSOR NAMES]</p>\n    </div>\n    \n    <div class=\"ordinance-body\">\n        <h3>SECTION 1. TITLE</h3>\n        <p>This Ordinance shall be known as the \"Quezon City Environmental Protection Ordinance.\"</p>\n        \n        <h3>SECTION 2. DECLARATION OF POLICY</h3>\n        <p>It is hereby declared the policy of the City Government of Quezon City to protect, preserve, and enhance the quality of the environment...</p>\n        \n        <h3>SECTION 3. DEFINITION OF TERMS</h3>\n        <p>As used in this Ordinance:</p>\n        <ul>\n            <li>(a) Environment - Definition</li>\n            <li>(b) Pollution - Definition</li>\n            <li>(c) Conservation - Definition</li>\n        </ul>\n        \n        <h3>SECTION 4. PROHIBITED ACTS</h3>\n        <p>The following acts are hereby prohibited within the territorial jurisdiction of Quezon City:</p>\n        \n        <h3>SECTION 5. PENALTIES</h3>\n        <p>Any person found violating any provision of this Ordinance shall be penalized...</p>\n        \n        <h3>SECTION 6. EFFECTIVITY</h3>\n        <p>This Ordinance shall take effect fifteen (15) days after its publication in a newspaper of general circulation.</p>\n    </div>\n    \n    <div class=\"ordinance-footer\">\n        <p>ENACTED by the Sangguniang Panlungsod of Quezon City in a regular session assembled.</p>\n        <p>[DATE OF APPROVAL]</p>\n        <p>_________________________________</p>\n        <p>City Vice Mayor & Presiding Officer</p>\n    </div>\n</div>', '{\"sponsor_names\":\"\",\"date_of_approval\":\"\"}', 1, 0, NULL, 1, '2026-02-09 18:19:11', '2026-02-09 18:19:11'),
(4, 'Appropriation Resolution Template', 'resolution', 'Finance', 'Template for budget appropriation resolutions', NULL, '<div class=\"resolution-template\">\n    <div class=\"resolution-header\">\n        <h1>RESOLUTION NO. ______</h1>\n        <h2>A RESOLUTION APPROPRIATING FUNDS FOR [PURPOSE]</h2>\n        <p>Sponsored by: [SPONSOR NAMES]</p>\n    </div>\n    \n    <div class=\"resolution-body\">\n        <h3>WHEREAS,</h3>\n        <p>The City Government of Quezon City recognizes the need for [PURPOSE];</p>\n        \n        <h3>WHEREAS,</h3>\n        <p>There is an available fund in the amount of [AMOUNT] under [FUND SOURCE];</p>\n        \n        <h3>WHEREAS,</h3>\n        <p>It is imperative to appropriate said amount for the aforementioned purpose;</p>\n        \n        <h3>NOW, THEREFORE,</h3>\n        <p>Be it RESOLVED, as it is hereby RESOLVED by the Sangguniang Panlungsod of Quezon City in session assembled:</p>\n        \n        <h3>SECTION 1.</h3>\n        <p>The amount of [AMOUNT] is hereby appropriated from [FUND SOURCE] for [PURPOSE].</p>\n        \n        <h3>SECTION 2.</h3>\n        <p>The City Treasurer is hereby authorized to release the said amount.</p>\n        \n        <h3>SECTION 3.</h3>\n        <p>This Resolution shall take effect immediately upon approval.</p>\n    </div>\n    \n    <div class=\"resolution-footer\">\n        <p>RESOLVED this ______ day of ____________, 20___.</p>\n        <p>_________________________________</p>\n        <p>City Vice Mayor & Presiding Officer</p>\n        <p>ATTESTED:</p>\n        <p>_________________________________</p>\n        <p>Secretary to the Sanggunian</p>\n    </div>\n</div>', '{\"sponsor_names\":\"\",\"purpose\":\"\",\"amount\":\"\",\"fund_source\":\"\"}', 1, 0, NULL, 1, '2026-02-09 18:19:11', '2026-02-09 18:19:11'),
(5, 'Traffic Ordinance Template', 'ordinance', 'Public Safety', 'Template for traffic management ordinances', NULL, '<div class=\"ordinance-template\">\n    <div class=\"ordinance-header\">\n        <h1>CITY ORDINANCE NO. ______</h1>\n        <h2>AN ORDINANCE REGULATING TRAFFIC IN QUEZON CITY</h2>\n        <p>Sponsored by: [SPONSOR NAMES]</p>\n    </div>\n    \n    <div class=\"ordinance-body\">\n        <h3>SECTION 1. TITLE</h3>\n        <p>This Ordinance shall be known as the \"Quezon City Traffic Regulation Ordinance.\"</p>\n        \n        <h3>SECTION 2. SCOPE AND COVERAGE</h3>\n        <p>This Ordinance shall apply to all public roads, streets, highways, and thoroughfares within Quezon City.</p>\n        \n        <h3>SECTION 3. TRAFFIC RULES AND REGULATIONS</h3>\n        <p>The following traffic rules and regulations shall be strictly observed:</p>\n        \n        <h3>SECTION 4. TRAFFIC SIGNS AND SIGNALS</h3>\n        <p>All traffic signs and signals installed by the City Government shall be obeyed by all motorists.</p>\n        \n        <h3>SECTION 5. PENALTIES</h3>\n        <p>Violations of this Ordinance shall be penalized as follows:</p>\n        \n        <h3>SECTION 6. IMPLEMENTATION</h3>\n        <p>The Quezon City Police District - Traffic Enforcement Unit shall implement this Ordinance.</p>\n        \n        <h3>SECTION 7. EFFECTIVITY</h3>\n        <p>This Ordinance shall take effect thirty (30) days after its publication.</p>\n    </div>\n    \n    <div class=\"ordinance-footer\">\n        <p>ENACTED by the Sangguniang Panlungsod of Quezon City in a regular session assembled.</p>\n        <p>[DATE OF APPROVAL]</p>\n        <p>_________________________________</p>\n        <p>City Vice Mayor & Presiding Officer</p>\n    </div>\n</div>', '{\"sponsor_names\":\"\",\"date_of_approval\":\"\"}', 1, 2, '2026-02-10 01:08:34', 1, '2026-02-09 18:19:11', '2026-02-10 01:08:34'),
(6, 'asdasd', 'ordinance', 'asds', 'asdasddas', NULL, '<p>asdasdasd</p>', '', 1, 0, NULL, 1, '2026-02-09 18:30:40', '2026-02-09 18:30:40'),
(7, '1111111111', 'ordinance', '11111111', '11111111111', NULL, '<p>1111111111</p>', NULL, 1, 0, NULL, 1, '2026-02-09 18:49:28', '2026-02-09 18:49:28'),
(8, 'asddddddddd', 'ordinance', 'asdasdasda', 'sdasdas', '../uploads/template_pdfs/template_8.pdf', '<p>dasdasdasdasd</p>', NULL, 1, 1, '2026-02-10 03:34:22', 1, '2026-02-09 19:13:31', '2026-02-10 03:34:22');

-- --------------------------------------------------------

--
-- Table structure for table `document_versions`
--

CREATE TABLE `document_versions` (
  `id` int(11) NOT NULL,
  `document_id` int(11) NOT NULL,
  `document_type` enum('ordinance','resolution') NOT NULL,
  `version_number` int(11) DEFAULT 1,
  `title` varchar(255) NOT NULL,
  `content` longtext DEFAULT NULL,
  `changes` text DEFAULT NULL,
  `created_by` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `is_current` tinyint(1) DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `document_versions`
--

INSERT INTO `document_versions` (`id`, `document_id`, `document_type`, `version_number`, `title`, `content`, `changes`, `created_by`, `created_at`, `is_current`) VALUES
(1, 1, 'ordinance', 1, 'ivan', '<p>ssssssssssssssssssadads</p>', NULL, 1, '2026-02-09 16:32:31', 1),
(2, 2, 'ordinance', 1, 'ivan', '<p>ssssssssssssssssssadads</p>', NULL, 1, '2026-02-09 16:32:40', 1),
(3, 3, 'ordinance', 1, 'ivan', '<p>ssssssssssssssssssadads</p>', NULL, 1, '2026-02-09 16:33:07', 1),
(4, 4, 'ordinance', 1, 'ivan', '<p>ssssssssssssssssssadads</p>', NULL, 1, '2026-02-09 16:45:04', 1),
(5, 5, 'ordinance', 1, 'tests', '<p>waesadwads</p>', NULL, 1, '2026-02-09 17:54:13', 1);

-- --------------------------------------------------------

--
-- Table structure for table `document_views`
--

CREATE TABLE `document_views` (
  `id` int(11) NOT NULL,
  `document_id` int(11) NOT NULL,
  `document_type` enum('ordinance','resolution') NOT NULL,
  `viewed_by` int(11) DEFAULT NULL,
  `viewed_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `search_terms` text DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `document_views`
--

INSERT INTO `document_views` (`id`, `document_id`, `document_type`, `viewed_by`, `viewed_at`, `search_terms`) VALUES
(1, 0, '', 1, '2026-02-10 04:20:50', 'tagging_module'),
(2, 0, '', 1, '2026-02-10 04:21:19', 'tagging_module'),
(3, 0, '', 1, '2026-02-10 04:51:43', 'tagging_module');

-- --------------------------------------------------------

--
-- Table structure for table `draft_registrations`
--

CREATE TABLE `draft_registrations` (
  `id` int(11) NOT NULL,
  `document_id` int(11) NOT NULL,
  `document_type` enum('ordinance','resolution') NOT NULL,
  `registration_number` varchar(50) NOT NULL,
  `registration_date` date NOT NULL,
  `registration_notes` text DEFAULT NULL,
  `registered_by` int(11) NOT NULL,
  `status` enum('pending','registered','archived','cancelled') DEFAULT 'pending',
  `committee_review_required` tinyint(1) DEFAULT 0,
  `committee_id` int(11) DEFAULT NULL,
  `requires_signature` tinyint(1) DEFAULT 0,
  `signature_date` date DEFAULT NULL,
  `signature_by` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `draft_registrations`
--

INSERT INTO `draft_registrations` (`id`, `document_id`, `document_type`, `registration_number`, `registration_date`, `registration_notes`, `registered_by`, `status`, `committee_review_required`, `committee_id`, `requires_signature`, `signature_date`, `signature_by`, `created_at`, `updated_at`) VALUES
(1, 5, 'ordinance', 'QC-REG-ORD-2026-02-0001', '2026-02-10', 'ASDASD', 1, 'registered', 1, 1, 1, NULL, NULL, '2026-02-10 01:12:07', '2026-02-10 01:12:07');

-- --------------------------------------------------------

--
-- Table structure for table `keyword_categories`
--

CREATE TABLE `keyword_categories` (
  `id` int(11) NOT NULL,
  `category_name` varchar(100) NOT NULL,
  `description` text DEFAULT NULL,
  `color_code` varchar(20) DEFAULT '#007bff',
  `created_by` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `keyword_suggestions`
--

CREATE TABLE `keyword_suggestions` (
  `id` int(11) NOT NULL,
  `keyword` varchar(100) NOT NULL,
  `category_id` int(11) DEFAULT NULL,
  `document_type` enum('ordinance','resolution','all') DEFAULT 'all',
  `usage_count` int(11) DEFAULT 0,
  `is_system_suggested` tinyint(1) DEFAULT 0,
  `suggested_by` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `notifications`
--

CREATE TABLE `notifications` (
  `id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `title` varchar(255) NOT NULL,
  `message` text NOT NULL,
  `notification_type` enum('system','document','committee','deadline','alert','update') DEFAULT 'system',
  `priority` enum('low','medium','high','urgent') DEFAULT 'medium',
  `related_document_id` int(11) DEFAULT NULL,
  `related_document_type` enum('ordinance','resolution') DEFAULT NULL,
  `related_committee_id` int(11) DEFAULT NULL,
  `action_url` varchar(500) DEFAULT NULL,
  `is_read` tinyint(1) DEFAULT 0,
  `read_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `expires_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `notification_logs`
--

CREATE TABLE `notification_logs` (
  `id` int(11) NOT NULL,
  `notification_id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `action` enum('sent','delivered','read','clicked','dismissed') DEFAULT 'sent',
  `details` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `notification_preferences`
--

CREATE TABLE `notification_preferences` (
  `id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `notification_type` varchar(50) NOT NULL,
  `enabled` tinyint(1) DEFAULT 1,
  `email_notifications` tinyint(1) DEFAULT 1,
  `in_app_notifications` tinyint(1) DEFAULT 1,
  `desktop_notifications` tinyint(1) DEFAULT 0,
  `sms_notifications` tinyint(1) DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `ordinances`
--

CREATE TABLE `ordinances` (
  `id` int(11) NOT NULL,
  `ordinance_number` varchar(50) NOT NULL,
  `title` varchar(255) NOT NULL,
  `description` text DEFAULT NULL,
  `status` enum('draft','pending','approved','rejected','implemented') DEFAULT 'draft',
  `created_by` int(11) DEFAULT NULL,
  `approved_by` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `ordinances`
--

INSERT INTO `ordinances` (`id`, `ordinance_number`, `title`, `description`, `status`, `created_by`, `approved_by`, `created_at`, `updated_at`) VALUES
(1, 'QC-ORD-2026-02-001', 'ivan', 'ivan', 'draft', 1, NULL, '2026-02-09 16:32:31', '2026-02-09 16:32:31'),
(2, 'QC-ORD-2026-02-002', 'ivan', 'ivan', 'draft', 1, NULL, '2026-02-09 16:32:40', '2026-02-09 16:32:40'),
(3, 'QC-ORD-2026-02-003', 'ivan', 'ivan', 'draft', 1, NULL, '2026-02-09 16:33:07', '2026-02-09 16:33:07'),
(4, 'QC-ORD-2026-02-004', 'ivan', 'ivan', 'draft', 1, NULL, '2026-02-09 16:45:04', '2026-02-09 16:45:04'),
(5, 'QC-ORD-2026-02-005', 'tests', 'teasd', 'pending', 1, NULL, '2026-02-09 17:54:13', '2026-02-10 01:12:07');

-- --------------------------------------------------------

--
-- Table structure for table `progress_analytics`
--

CREATE TABLE `progress_analytics` (
  `id` int(11) NOT NULL,
  `document_id` int(11) NOT NULL,
  `document_type` enum('ordinance','resolution') NOT NULL,
  `analytics_type` varchar(50) NOT NULL,
  `data_key` varchar(100) NOT NULL,
  `data_value` text NOT NULL,
  `confidence_score` decimal(5,2) DEFAULT 0.00,
  `generated_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `generated_by` int(11) DEFAULT NULL,
  `is_active` tinyint(1) DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `progress_forecasts`
--

CREATE TABLE `progress_forecasts` (
  `id` int(11) NOT NULL,
  `document_id` int(11) DEFAULT NULL,
  `document_type` enum('ordinance','resolution') DEFAULT NULL,
  `forecast_type` enum('completion_time','approval_likelihood','risk_assessment') NOT NULL,
  `predicted_date` date DEFAULT NULL,
  `confidence_level` decimal(5,2) DEFAULT 0.00,
  `factors_considered` text DEFAULT NULL,
  `recommendations` text DEFAULT NULL,
  `generated_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `valid_until` date DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `progress_kpis`
--

CREATE TABLE `progress_kpis` (
  `id` int(11) NOT NULL,
  `kpi_name` varchar(100) NOT NULL,
  `kpi_description` text DEFAULT NULL,
  `kpi_type` enum('efficiency','timeliness','quality','compliance') NOT NULL,
  `target_value` decimal(10,2) DEFAULT NULL,
  `current_value` decimal(10,2) DEFAULT NULL,
  `unit` varchar(20) DEFAULT NULL,
  `document_type` enum('ordinance','resolution','both') DEFAULT 'both',
  `is_active` tinyint(1) DEFAULT 1,
  `created_by` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `progress_kpis`
--

INSERT INTO `progress_kpis` (`id`, `kpi_name`, `kpi_description`, `kpi_type`, `target_value`, `current_value`, `unit`, `document_type`, `is_active`, `created_by`, `created_at`, `updated_at`) VALUES
(1, 'Average Processing Time', 'Average time from draft creation to final approval', 'timeliness', 45.00, 52.50, 'days', 'both', 1, NULL, '2026-02-10 06:38:21', '2026-02-10 06:38:21'),
(2, 'Approval Rate', 'Percentage of documents that receive final approval', 'efficiency', 85.00, 78.50, '%', 'both', 1, NULL, '2026-02-10 06:38:21', '2026-02-10 06:38:21'),
(3, 'Amendment Frequency', 'Average number of amendments per document', 'quality', 2.00, 3.20, 'times', 'both', 1, NULL, '2026-02-10 06:38:21', '2026-02-10 06:38:21'),
(4, 'Committee Review Time', 'Average time spent in committee review', 'timeliness', 15.00, 18.75, 'days', 'both', 1, NULL, '2026-02-10 06:38:21', '2026-02-10 06:38:21'),
(5, 'Public Hearing Compliance', 'Percentage of documents with required public hearings', 'compliance', 100.00, 92.50, '%', 'ordinance', 1, NULL, '2026-02-10 06:38:21', '2026-02-10 06:38:21'),
(6, 'Resolution Implementation', 'Percentage of resolutions implemented within deadline', 'efficiency', 90.00, 85.30, '%', 'resolution', 1, NULL, '2026-02-10 06:38:21', '2026-02-10 06:38:21');

-- --------------------------------------------------------

--
-- Table structure for table `progress_milestones`
--

CREATE TABLE `progress_milestones` (
  `id` int(11) NOT NULL,
  `document_id` int(11) NOT NULL,
  `document_type` enum('ordinance','resolution') NOT NULL,
  `milestone_name` varchar(100) NOT NULL,
  `milestone_description` text DEFAULT NULL,
  `expected_date` date DEFAULT NULL,
  `actual_date` date DEFAULT NULL,
  `status` enum('pending','in_progress','completed','delayed','cancelled') DEFAULT 'pending',
  `completion_percentage` int(11) DEFAULT 0,
  `assigned_to` int(11) DEFAULT NULL,
  `dependencies` varchar(255) DEFAULT NULL,
  `created_by` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `reference_numbering`
--

CREATE TABLE `reference_numbering` (
  `id` int(11) NOT NULL,
  `prefix` varchar(20) NOT NULL,
  `document_type` enum('ordinance','resolution') NOT NULL,
  `year` int(4) NOT NULL,
  `sequence` int(11) DEFAULT 1,
  `last_used_date` date DEFAULT NULL,
  `format_pattern` varchar(100) DEFAULT NULL,
  `description` text DEFAULT NULL,
  `is_active` tinyint(1) DEFAULT 1,
  `created_by` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `reference_numbering`
--

INSERT INTO `reference_numbering` (`id`, `prefix`, `document_type`, `year`, `sequence`, `last_used_date`, `format_pattern`, `description`, `is_active`, `created_by`, `created_at`, `updated_at`) VALUES
(1, 'QC-ORD', 'ordinance', 2026, 6, NULL, 'QC-ORD-YYYY-SSSS', 'Quezon City Ordinance - YYYY year, SSSS 4-digit sequence', 1, NULL, '2026-02-10 04:08:33', '2026-02-10 04:08:33'),
(2, 'QC-RES', 'resolution', 2026, 1, NULL, 'QC-RES-YYYY-SSSS', 'Quezon City Resolution - YYYY year, SSSS 4-digit sequence', 1, NULL, '2026-02-10 04:08:33', '2026-02-10 04:08:33'),
(3, 'QC-REG-ORD', 'ordinance', 2026, 2, NULL, 'QC-REG-ORD-YYYY-MM-SSSS', 'Registered Ordinance - YYYY year, MM month, SSSS 4-digit sequence', 1, NULL, '2026-02-10 04:08:33', '2026-02-10 04:08:33'),
(4, 'QC-REG-RES', 'resolution', 2026, 1, NULL, 'QC-REG-RES-YYYY-MM-SSSS', 'Registered Resolution - YYYY year, MM month, SSSS 4-digit sequence', 1, NULL, '2026-02-10 04:08:33', '2026-02-10 04:08:33');

-- --------------------------------------------------------

--
-- Table structure for table `resolutions`
--

CREATE TABLE `resolutions` (
  `id` int(11) NOT NULL,
  `resolution_number` varchar(50) NOT NULL,
  `title` varchar(255) NOT NULL,
  `description` text DEFAULT NULL,
  `status` enum('draft','pending','approved','rejected') DEFAULT 'draft',
  `created_by` int(11) DEFAULT NULL,
  `approved_by` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `sessions`
--

CREATE TABLE `sessions` (
  `id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `session_id` varchar(255) NOT NULL,
  `login_time` timestamp NOT NULL DEFAULT current_timestamp(),
  `ip_address` varchar(45) DEFAULT NULL,
  `user_agent` text DEFAULT NULL,
  `logout_time` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `sessions`
--

INSERT INTO `sessions` (`id`, `user_id`, `session_id`, `login_time`, `ip_address`, `user_agent`, `logout_time`) VALUES
(1, 1, 'nsv32vl4rtlnilc2ieq3opmfaa', '2026-02-07 14:40:10', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/144.0.0.0 Safari/537.36', NULL),
(2, 1, 'nsv32vl4rtlnilc2ieq3opmfaa', '2026-02-07 15:07:01', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/144.0.0.0 Safari/537.36', NULL),
(3, 1, 'nsv32vl4rtlnilc2ieq3opmfaa', '2026-02-07 15:49:20', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/144.0.0.0 Safari/537.36', NULL),
(4, 1, 'nsv32vl4rtlnilc2ieq3opmfaa', '2026-02-07 16:09:25', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/144.0.0.0 Safari/537.36', NULL),
(5, 1, '1di1ukbbg0hu7ksf4iio9s6him', '2026-02-09 13:10:54', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/144.0.0.0 Safari/537.36', NULL),
(6, 1, '1di1ukbbg0hu7ksf4iio9s6him', '2026-02-09 14:07:03', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/144.0.0.0 Safari/537.36', NULL),
(7, 1, '1di1ukbbg0hu7ksf4iio9s6him', '2026-02-09 18:48:52', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/144.0.0.0 Safari/537.36', NULL),
(8, 1, '1di1ukbbg0hu7ksf4iio9s6him', '2026-02-09 19:12:59', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/144.0.0.0 Safari/537.36', NULL),
(9, 1, '1di1ukbbg0hu7ksf4iio9s6him', '2026-02-09 22:37:31', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/144.0.0.0 Safari/537.36', NULL),
(10, 1, '1di1ukbbg0hu7ksf4iio9s6him', '2026-02-10 03:33:58', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/144.0.0.0 Safari/537.36', NULL),
(11, 1, '1di1ukbbg0hu7ksf4iio9s6him', '2026-02-10 06:01:56', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/144.0.0.0 Safari/537.36', NULL),
(12, 1, '8tld09bok6oso12pef4b2jjpio', '2026-03-01 15:16:25', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/145.0.0.0 Safari/537.36', NULL);

-- --------------------------------------------------------

--
-- Table structure for table `status_history`
--

CREATE TABLE `status_history` (
  `id` int(11) NOT NULL,
  `document_id` int(11) NOT NULL,
  `document_type` enum('ordinance','resolution') NOT NULL,
  `old_status` varchar(50) NOT NULL,
  `new_status` varchar(50) NOT NULL,
  `notes` text DEFAULT NULL,
  `changed_by` int(11) NOT NULL,
  `next_step` varchar(100) DEFAULT NULL,
  `target_date` date DEFAULT NULL,
  `changed_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `supporting_documents`
--

CREATE TABLE `supporting_documents` (
  `id` int(11) NOT NULL,
  `document_id` int(11) NOT NULL,
  `document_type` enum('ordinance','resolution') NOT NULL,
  `file_name` varchar(255) NOT NULL,
  `file_path` varchar(500) NOT NULL,
  `file_type` varchar(100) DEFAULT NULL,
  `file_size` int(11) DEFAULT NULL,
  `description` text DEFAULT NULL,
  `uploaded_by` int(11) DEFAULT NULL,
  `uploaded_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `supporting_documents`
--

INSERT INTO `supporting_documents` (`id`, `document_id`, `document_type`, `file_name`, `file_path`, `file_type`, `file_size`, `description`, `uploaded_by`, `uploaded_at`) VALUES
(1, 1, 'ordinance', 'Pastil_Report.pdf', '../uploads/supporting_docs/1770654751_698a0c1f6b6af_Pastil_Report.pdf', 'application/pdf', 135616, NULL, 1, '2026-02-09 16:32:31'),
(2, 2, 'ordinance', 'Pastil_Report.pdf', '../uploads/supporting_docs/1770654760_698a0c28e252e_Pastil_Report.pdf', 'application/pdf', 135616, NULL, 1, '2026-02-09 16:32:40'),
(3, 3, 'ordinance', 'Pastil_Report.pdf', '../uploads/supporting_docs/1770654787_698a0c43c2f5e_Pastil_Report.pdf', 'application/pdf', 135616, NULL, 1, '2026-02-09 16:33:07'),
(4, 4, 'ordinance', 'Pastil_Report.pdf', '../uploads/supporting_docs/1770655504_698a0f1053b12_Pastil_Report.pdf', 'application/pdf', 135616, NULL, 1, '2026-02-09 16:45:04'),
(5, 5, 'ordinance', 'yUKKI.docx', '../uploads/supporting_docs/1770659653_698a1f453be76_yUKKI.docx', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document', 103420, NULL, 1, '2026-02-09 17:54:13'),
(6, 4, 'ordinance', '1770654787_698a0c43c2f5e_Pastil_Report.pdf', '../uploads/supporting_docs/1770685895_698a85c7a21db_1770654787_698a0c43c2f5e_Pastil_Report.pdf', 'application/pdf', 135616, 'ASDASD', 1, '2026-02-10 01:11:35');

-- --------------------------------------------------------

--
-- Table structure for table `tagging_history`
--

CREATE TABLE `tagging_history` (
  `id` int(11) NOT NULL,
  `document_id` int(11) NOT NULL,
  `document_type` enum('ordinance','resolution') NOT NULL,
  `action_type` enum('added','removed','modified') NOT NULL,
  `keyword_id` int(11) DEFAULT NULL,
  `keyword_text` varchar(100) NOT NULL,
  `performed_by` int(11) DEFAULT NULL,
  `performed_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `notes` text DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `template_categories`
--

CREATE TABLE `template_categories` (
  `id` int(11) NOT NULL,
  `category_name` varchar(100) NOT NULL,
  `category_type` enum('ordinance','resolution','all') DEFAULT 'all',
  `description` text DEFAULT NULL,
  `created_by` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `template_categories`
--

INSERT INTO `template_categories` (`id`, `category_name`, `category_type`, `description`, `created_by`, `created_at`, `updated_at`) VALUES
(1, 'Administrative', 'ordinance', 'Templates for administrative ordinances', 1, '2026-02-09 18:15:56', '2026-02-09 18:15:56'),
(2, 'Environmental', 'ordinance', 'Templates for environmental ordinances', 1, '2026-02-09 18:15:56', '2026-02-09 18:15:56'),
(3, 'Public Safety', 'ordinance', 'Templates for public safety ordinances', 1, '2026-02-09 18:15:56', '2026-02-09 18:15:56'),
(4, 'Finance', 'ordinance', 'Templates for finance-related ordinances', 1, '2026-02-09 18:15:56', '2026-02-09 18:15:56'),
(5, 'Infrastructure', 'ordinance', 'Templates for infrastructure ordinances', 1, '2026-02-09 18:15:56', '2026-02-09 18:15:56'),
(6, 'General Resolutions', 'resolution', 'General resolution templates', 1, '2026-02-09 18:15:56', '2026-02-09 18:15:56'),
(7, 'Special Resolutions', 'resolution', 'Special resolution templates', 1, '2026-02-09 18:15:56', '2026-02-09 18:15:56'),
(8, 'Amendments', '', 'Amendment templates', 1, '2026-02-09 18:15:56', '2026-02-09 18:15:56');

-- --------------------------------------------------------

--
-- Table structure for table `template_favorites`
--

CREATE TABLE `template_favorites` (
  `id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `template_id` int(11) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `template_usage`
--

CREATE TABLE `template_usage` (
  `id` int(11) NOT NULL,
  `template_id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `document_id` int(11) DEFAULT NULL,
  `document_type` enum('ordinance','resolution') DEFAULT NULL,
  `used_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `template_usage`
--

INSERT INTO `template_usage` (`id`, `template_id`, `user_id`, `document_id`, `document_type`, `used_at`) VALUES
(1, 5, 1, NULL, 'ordinance', '2026-02-09 18:29:47'),
(2, 5, 1, NULL, 'ordinance', '2026-02-10 01:08:34'),
(3, 8, 1, NULL, 'ordinance', '2026-02-10 03:34:22');

-- --------------------------------------------------------

--
-- Table structure for table `timeline_comments`
--

CREATE TABLE `timeline_comments` (
  `id` int(11) NOT NULL,
  `timeline_id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `comment` text NOT NULL,
  `attachment` varchar(500) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `timeline_notifications`
--

CREATE TABLE `timeline_notifications` (
  `id` int(11) NOT NULL,
  `timeline_id` int(11) DEFAULT NULL,
  `user_id` int(11) NOT NULL,
  `notification_type` varchar(50) NOT NULL,
  `message` text NOT NULL,
  `is_read` tinyint(1) DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `read_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `timeline_tracking`
--

CREATE TABLE `timeline_tracking` (
  `id` int(11) NOT NULL,
  `document_id` int(11) NOT NULL,
  `document_type` enum('ordinance','resolution') NOT NULL,
  `milestone` varchar(100) NOT NULL,
  `description` text DEFAULT NULL,
  `status` enum('pending','in_progress','completed','delayed','cancelled') DEFAULT 'pending',
  `assigned_to` int(11) DEFAULT NULL,
  `start_date` date DEFAULT NULL,
  `due_date` date DEFAULT NULL,
  `completed_date` date DEFAULT NULL,
  `estimated_duration` int(11) DEFAULT NULL COMMENT 'Duration in days',
  `actual_duration` int(11) DEFAULT NULL COMMENT 'Duration in days',
  `dependency_id` int(11) DEFAULT NULL COMMENT 'Dependent milestone',
  `priority` enum('low','medium','high','urgent','emergency') DEFAULT 'medium',
  `notes` text DEFAULT NULL,
  `created_by` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `users`
--

CREATE TABLE `users` (
  `id` int(11) NOT NULL,
  `email` varchar(100) NOT NULL,
  `password` varchar(255) NOT NULL,
  `first_name` varchar(50) NOT NULL,
  `last_name` varchar(50) NOT NULL,
  `role` enum('super_admin','admin','councilor') NOT NULL,
  `department` varchar(100) DEFAULT NULL,
  `is_active` tinyint(1) DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `last_login` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `users`
--

INSERT INTO `users` (`id`, `email`, `password`, `first_name`, `last_name`, `role`, `department`, `is_active`, `created_at`, `last_login`) VALUES
(1, 'superadmin@qc.gov.ph', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'Quezon', 'City', 'super_admin', 'City Administrator Office', 1, '2026-02-07 14:36:42', '2026-03-01 15:16:25'),
(2, 'admin@qc.gov.ph', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'Maria', 'Santos', 'admin', 'City Council Secretariat', 1, '2026-02-07 14:36:42', NULL),
(3, 'councilor1@qc.gov.ph', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'Juan', 'Dela Cruz', 'councilor', 'District 1', 1, '2026-02-07 14:36:42', NULL),
(4, 'councilor2@qc.gov.ph', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'Ana', 'Reyes', 'councilor', 'District 2', 1, '2026-02-07 14:36:42', NULL);

-- --------------------------------------------------------

--
-- Table structure for table `version_metadata`
--

CREATE TABLE `version_metadata` (
  `id` int(11) NOT NULL,
  `version_id` int(11) NOT NULL,
  `document_id` int(11) NOT NULL,
  `document_type` enum('ordinance','resolution') NOT NULL,
  `checksum` varchar(64) NOT NULL,
  `word_count` int(11) DEFAULT 0,
  `section_count` int(11) DEFAULT 0,
  `created_by_name` varchar(100) DEFAULT NULL,
  `created_by_role` varchar(50) DEFAULT NULL,
  `storage_location` varchar(500) DEFAULT NULL,
  `compression_ratio` decimal(5,2) DEFAULT NULL,
  `indexed_at` timestamp NULL DEFAULT NULL,
  `backup_location` varchar(500) DEFAULT NULL,
  `backup_status` enum('pending','completed','failed') DEFAULT 'pending',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `version_relationships`
--

CREATE TABLE `version_relationships` (
  `id` int(11) NOT NULL,
  `parent_version_id` int(11) NOT NULL,
  `child_version_id` int(11) NOT NULL,
  `relationship_type` enum('amendment','revision','correction','revert') NOT NULL,
  `relationship_notes` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `version_search_index`
--

CREATE TABLE `version_search_index` (
  `id` int(11) NOT NULL,
  `version_id` int(11) NOT NULL,
  `document_id` int(11) NOT NULL,
  `document_type` enum('ordinance','resolution') NOT NULL,
  `search_text` longtext NOT NULL,
  `keywords` text DEFAULT NULL,
  `indexed_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Indexes for dumped tables
--

--
-- Indexes for table `ai_amendment_analytics`
--
ALTER TABLE `ai_amendment_analytics`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `unique_analytics` (`amendment_id`,`analytics_type`,`data_key`),
  ADD KEY `generated_by` (`generated_by`);

--
-- Indexes for table `amendment_approval_signatures`
--
ALTER TABLE `amendment_approval_signatures`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `amendment_signatory` (`amendment_id`,`signatory_id`,`signature_type`),
  ADD KEY `signatory_id` (`signatory_id`);

--
-- Indexes for table `amendment_approval_workflow`
--
ALTER TABLE `amendment_approval_workflow`
  ADD PRIMARY KEY (`id`),
  ADD KEY `amendment_id` (`amendment_id`),
  ADD KEY `assigned_to` (`assigned_to`);

--
-- Indexes for table `amendment_attachments`
--
ALTER TABLE `amendment_attachments`
  ADD PRIMARY KEY (`id`),
  ADD KEY `amendment_id` (`amendment_id`),
  ADD KEY `uploaded_by` (`uploaded_by`);

--
-- Indexes for table `amendment_authors`
--
ALTER TABLE `amendment_authors`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `amendment_author` (`amendment_id`,`user_id`),
  ADD KEY `user_id` (`user_id`),
  ADD KEY `assigned_by` (`assigned_by`);

--
-- Indexes for table `amendment_comparisons`
--
ALTER TABLE `amendment_comparisons`
  ADD PRIMARY KEY (`id`),
  ADD KEY `amendment_id` (`amendment_id`);

--
-- Indexes for table `amendment_history`
--
ALTER TABLE `amendment_history`
  ADD PRIMARY KEY (`id`),
  ADD KEY `amendment_id` (`amendment_id`),
  ADD KEY `performed_by` (`performed_by`);

--
-- Indexes for table `amendment_reviews`
--
ALTER TABLE `amendment_reviews`
  ADD PRIMARY KEY (`id`),
  ADD KEY `amendment_id` (`amendment_id`),
  ADD KEY `reviewed_by` (`reviewed_by`);

--
-- Indexes for table `amendment_submissions`
--
ALTER TABLE `amendment_submissions`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `amendment_number` (`amendment_number`),
  ADD KEY `document_id` (`document_id`,`document_type`),
  ADD KEY `submitted_by` (`submitted_by`),
  ADD KEY `reviewed_by` (`reviewed_by`),
  ADD KEY `approved_by` (`approved_by`),
  ADD KEY `committee_id` (`committee_id`),
  ADD KEY `current_version_id` (`current_version_id`),
  ADD KEY `proposed_version_id` (`proposed_version_id`);

--
-- Indexes for table `amendment_voting`
--
ALTER TABLE `amendment_voting`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `amendment_voter` (`amendment_id`,`user_id`),
  ADD KEY `user_id` (`user_id`);

--
-- Indexes for table `audit_logs`
--
ALTER TABLE `audit_logs`
  ADD PRIMARY KEY (`id`),
  ADD KEY `user_id` (`user_id`);

--
-- Indexes for table `change_comments`
--
ALTER TABLE `change_comments`
  ADD PRIMARY KEY (`id`),
  ADD KEY `highlight_id` (`highlight_id`),
  ADD KEY `user_id` (`user_id`),
  ADD KEY `is_resolved` (`is_resolved`),
  ADD KEY `change_comments_ibfk_3` (`resolved_by`);

--
-- Indexes for table `change_highlights`
--
ALTER TABLE `change_highlights`
  ADD PRIMARY KEY (`id`),
  ADD KEY `session_id` (`session_id`),
  ADD KEY `change_type` (`change_type`),
  ADD KEY `importance` (`importance`),
  ADD KEY `change_highlights_ibfk_2` (`created_by`);

--
-- Indexes for table `committees`
--
ALTER TABLE `committees`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `committee_code` (`committee_code`),
  ADD KEY `chairperson_id` (`chairperson_id`),
  ADD KEY `vice_chairperson_id` (`vice_chairperson_id`);

--
-- Indexes for table `committee_members`
--
ALTER TABLE `committee_members`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `committee_user` (`committee_id`,`user_id`),
  ADD KEY `user_id` (`user_id`),
  ADD KEY `assigned_by` (`assigned_by`);

--
-- Indexes for table `comparison_analytics`
--
ALTER TABLE `comparison_analytics`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `session_analytics` (`session_id`,`analytics_type`),
  ADD KEY `session_id` (`session_id`);

--
-- Indexes for table `comparison_sessions`
--
ALTER TABLE `comparison_sessions`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `session_code` (`session_code`),
  ADD KEY `document_id` (`document_id`,`document_type`),
  ADD KEY `created_by` (`created_by`),
  ADD KEY `old_version_id` (`old_version_id`),
  ADD KEY `new_version_id` (`new_version_id`);

--
-- Indexes for table `comparison_shares`
--
ALTER TABLE `comparison_shares`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `session_share` (`session_id`,`shared_with`),
  ADD KEY `shared_with` (`shared_with`),
  ADD KEY `shared_by` (`shared_by`);

--
-- Indexes for table `document_authors`
--
ALTER TABLE `document_authors`
  ADD PRIMARY KEY (`id`),
  ADD KEY `document_id` (`document_id`),
  ADD KEY `user_id` (`user_id`),
  ADD KEY `assigned_by` (`assigned_by`);

--
-- Indexes for table `document_categories`
--
ALTER TABLE `document_categories`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `category_code` (`category_code`),
  ADD KEY `parent_id` (`parent_id`),
  ADD KEY `created_by` (`created_by`);

--
-- Indexes for table `document_classification`
--
ALTER TABLE `document_classification`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `document_classification` (`document_id`,`document_type`),
  ADD UNIQUE KEY `reference_number` (`reference_number`),
  ADD KEY `category_id` (`category_id`),
  ADD KEY `classified_by` (`classified_by`),
  ADD KEY `reviewed_by` (`reviewed_by`);

--
-- Indexes for table `document_committees`
--
ALTER TABLE `document_committees`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `document_committee` (`document_id`,`document_type`,`committee_id`),
  ADD KEY `committee_id` (`committee_id`),
  ADD KEY `assigned_by` (`assigned_by`);

--
-- Indexes for table `document_keywords`
--
ALTER TABLE `document_keywords`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `document_keyword` (`document_id`,`document_type`,`keyword`),
  ADD KEY `keyword` (`keyword`),
  ADD KEY `added_by` (`added_by`);

--
-- Indexes for table `document_numbering_logs`
--
ALTER TABLE `document_numbering_logs`
  ADD PRIMARY KEY (`id`),
  ADD KEY `document_id` (`document_id`,`document_type`),
  ADD KEY `changed_by` (`changed_by`);

--
-- Indexes for table `document_priority_history`
--
ALTER TABLE `document_priority_history`
  ADD PRIMARY KEY (`id`),
  ADD KEY `document_id` (`document_id`),
  ADD KEY `changed_by` (`changed_by`);

--
-- Indexes for table `document_templates`
--
ALTER TABLE `document_templates`
  ADD PRIMARY KEY (`id`),
  ADD KEY `created_by` (`created_by`);

--
-- Indexes for table `document_versions`
--
ALTER TABLE `document_versions`
  ADD PRIMARY KEY (`id`),
  ADD KEY `document_id` (`document_id`),
  ADD KEY `created_by` (`created_by`);

--
-- Indexes for table `document_views`
--
ALTER TABLE `document_views`
  ADD PRIMARY KEY (`id`),
  ADD KEY `document_id` (`document_id`,`document_type`),
  ADD KEY `viewed_by` (`viewed_by`);

--
-- Indexes for table `draft_registrations`
--
ALTER TABLE `draft_registrations`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `registration_number` (`registration_number`),
  ADD UNIQUE KEY `document_registration` (`document_id`,`document_type`),
  ADD KEY `registered_by` (`registered_by`),
  ADD KEY `committee_id` (`committee_id`),
  ADD KEY `signature_by` (`signature_by`);

--
-- Indexes for table `keyword_categories`
--
ALTER TABLE `keyword_categories`
  ADD PRIMARY KEY (`id`),
  ADD KEY `created_by` (`created_by`);

--
-- Indexes for table `keyword_suggestions`
--
ALTER TABLE `keyword_suggestions`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `unique_keyword` (`keyword`,`category_id`,`document_type`),
  ADD KEY `category_id` (`category_id`),
  ADD KEY `suggested_by` (`suggested_by`);

--
-- Indexes for table `notifications`
--
ALTER TABLE `notifications`
  ADD PRIMARY KEY (`id`),
  ADD KEY `user_id` (`user_id`),
  ADD KEY `notification_type` (`notification_type`),
  ADD KEY `is_read` (`is_read`),
  ADD KEY `expires_at` (`expires_at`),
  ADD KEY `related_document` (`related_document_id`,`related_document_type`);

--
-- Indexes for table `notification_logs`
--
ALTER TABLE `notification_logs`
  ADD PRIMARY KEY (`id`),
  ADD KEY `notification_id` (`notification_id`),
  ADD KEY `user_id` (`user_id`);

--
-- Indexes for table `notification_preferences`
--
ALTER TABLE `notification_preferences`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `user_notification_type` (`user_id`,`notification_type`);

--
-- Indexes for table `ordinances`
--
ALTER TABLE `ordinances`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `ordinance_number` (`ordinance_number`),
  ADD KEY `created_by` (`created_by`),
  ADD KEY `approved_by` (`approved_by`);

--
-- Indexes for table `progress_analytics`
--
ALTER TABLE `progress_analytics`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `unique_analytics` (`document_id`,`document_type`,`analytics_type`,`data_key`),
  ADD KEY `generated_by` (`generated_by`);

--
-- Indexes for table `progress_forecasts`
--
ALTER TABLE `progress_forecasts`
  ADD PRIMARY KEY (`id`),
  ADD KEY `document_id` (`document_id`,`document_type`),
  ADD KEY `forecast_type` (`forecast_type`);

--
-- Indexes for table `progress_kpis`
--
ALTER TABLE `progress_kpis`
  ADD PRIMARY KEY (`id`),
  ADD KEY `kpi_type` (`kpi_type`),
  ADD KEY `is_active` (`is_active`);

--
-- Indexes for table `progress_milestones`
--
ALTER TABLE `progress_milestones`
  ADD PRIMARY KEY (`id`),
  ADD KEY `document_id` (`document_id`,`document_type`),
  ADD KEY `assigned_to` (`assigned_to`),
  ADD KEY `status` (`status`);

--
-- Indexes for table `reference_numbering`
--
ALTER TABLE `reference_numbering`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `unique_numbering` (`prefix`,`document_type`,`year`),
  ADD KEY `created_by` (`created_by`);

--
-- Indexes for table `resolutions`
--
ALTER TABLE `resolutions`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `resolution_number` (`resolution_number`),
  ADD KEY `created_by` (`created_by`),
  ADD KEY `approved_by` (`approved_by`);

--
-- Indexes for table `sessions`
--
ALTER TABLE `sessions`
  ADD PRIMARY KEY (`id`),
  ADD KEY `user_id` (`user_id`),
  ADD KEY `idx_session_id` (`session_id`);

--
-- Indexes for table `status_history`
--
ALTER TABLE `status_history`
  ADD PRIMARY KEY (`id`),
  ADD KEY `document_id` (`document_id`,`document_type`),
  ADD KEY `changed_by` (`changed_by`),
  ADD KEY `changed_at` (`changed_at`),
  ADD KEY `new_status` (`new_status`);

--
-- Indexes for table `supporting_documents`
--
ALTER TABLE `supporting_documents`
  ADD PRIMARY KEY (`id`),
  ADD KEY `document_id` (`document_id`),
  ADD KEY `uploaded_by` (`uploaded_by`);

--
-- Indexes for table `tagging_history`
--
ALTER TABLE `tagging_history`
  ADD PRIMARY KEY (`id`),
  ADD KEY `document_id` (`document_id`,`document_type`),
  ADD KEY `keyword_id` (`keyword_id`),
  ADD KEY `performed_by` (`performed_by`);

--
-- Indexes for table `template_categories`
--
ALTER TABLE `template_categories`
  ADD PRIMARY KEY (`id`),
  ADD KEY `created_by` (`created_by`);

--
-- Indexes for table `template_favorites`
--
ALTER TABLE `template_favorites`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `user_template` (`user_id`,`template_id`),
  ADD KEY `template_id` (`template_id`);

--
-- Indexes for table `template_usage`
--
ALTER TABLE `template_usage`
  ADD PRIMARY KEY (`id`),
  ADD KEY `template_id` (`template_id`),
  ADD KEY `user_id` (`user_id`);

--
-- Indexes for table `timeline_comments`
--
ALTER TABLE `timeline_comments`
  ADD PRIMARY KEY (`id`),
  ADD KEY `timeline_id` (`timeline_id`),
  ADD KEY `user_id` (`user_id`);

--
-- Indexes for table `timeline_notifications`
--
ALTER TABLE `timeline_notifications`
  ADD PRIMARY KEY (`id`),
  ADD KEY `timeline_id` (`timeline_id`),
  ADD KEY `user_id` (`user_id`),
  ADD KEY `is_read` (`is_read`);

--
-- Indexes for table `timeline_tracking`
--
ALTER TABLE `timeline_tracking`
  ADD PRIMARY KEY (`id`),
  ADD KEY `document_id` (`document_id`,`document_type`),
  ADD KEY `assigned_to` (`assigned_to`),
  ADD KEY `dependency_id` (`dependency_id`),
  ADD KEY `created_by` (`created_by`);

--
-- Indexes for table `users`
--
ALTER TABLE `users`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `email` (`email`),
  ADD KEY `idx_email` (`email`),
  ADD KEY `idx_role` (`role`);

--
-- Indexes for table `version_metadata`
--
ALTER TABLE `version_metadata`
  ADD PRIMARY KEY (`id`),
  ADD KEY `version_id` (`version_id`),
  ADD KEY `document_id` (`document_id`,`document_type`),
  ADD KEY `checksum` (`checksum`);

--
-- Indexes for table `version_relationships`
--
ALTER TABLE `version_relationships`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `unique_relationship` (`parent_version_id`,`child_version_id`),
  ADD KEY `child_version_id` (`child_version_id`);

--
-- Indexes for table `version_search_index`
--
ALTER TABLE `version_search_index`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `version_id` (`version_id`),
  ADD KEY `document_id` (`document_id`,`document_type`);
ALTER TABLE `version_search_index` ADD FULLTEXT KEY `search_text` (`search_text`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `ai_amendment_analytics`
--
ALTER TABLE `ai_amendment_analytics`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `amendment_approval_signatures`
--
ALTER TABLE `amendment_approval_signatures`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `amendment_approval_workflow`
--
ALTER TABLE `amendment_approval_workflow`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `amendment_attachments`
--
ALTER TABLE `amendment_attachments`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `amendment_authors`
--
ALTER TABLE `amendment_authors`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `amendment_comparisons`
--
ALTER TABLE `amendment_comparisons`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `amendment_history`
--
ALTER TABLE `amendment_history`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `amendment_reviews`
--
ALTER TABLE `amendment_reviews`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `amendment_submissions`
--
ALTER TABLE `amendment_submissions`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `amendment_voting`
--
ALTER TABLE `amendment_voting`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `audit_logs`
--
ALTER TABLE `audit_logs`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=38;

--
-- AUTO_INCREMENT for table `change_comments`
--
ALTER TABLE `change_comments`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `change_highlights`
--
ALTER TABLE `change_highlights`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=15;

--
-- AUTO_INCREMENT for table `committees`
--
ALTER TABLE `committees`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=9;

--
-- AUTO_INCREMENT for table `committee_members`
--
ALTER TABLE `committee_members`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=17;

--
-- AUTO_INCREMENT for table `comparison_analytics`
--
ALTER TABLE `comparison_analytics`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=16;

--
-- AUTO_INCREMENT for table `comparison_sessions`
--
ALTER TABLE `comparison_sessions`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT for table `comparison_shares`
--
ALTER TABLE `comparison_shares`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `document_authors`
--
ALTER TABLE `document_authors`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=8;

--
-- AUTO_INCREMENT for table `document_categories`
--
ALTER TABLE `document_categories`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=13;

--
-- AUTO_INCREMENT for table `document_classification`
--
ALTER TABLE `document_classification`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `document_committees`
--
ALTER TABLE `document_committees`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `document_keywords`
--
ALTER TABLE `document_keywords`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `document_numbering_logs`
--
ALTER TABLE `document_numbering_logs`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `document_priority_history`
--
ALTER TABLE `document_priority_history`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- AUTO_INCREMENT for table `document_templates`
--
ALTER TABLE `document_templates`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=9;

--
-- AUTO_INCREMENT for table `document_versions`
--
ALTER TABLE `document_versions`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=6;

--
-- AUTO_INCREMENT for table `document_views`
--
ALTER TABLE `document_views`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- AUTO_INCREMENT for table `draft_registrations`
--
ALTER TABLE `draft_registrations`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `keyword_categories`
--
ALTER TABLE `keyword_categories`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `keyword_suggestions`
--
ALTER TABLE `keyword_suggestions`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `notifications`
--
ALTER TABLE `notifications`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `notification_logs`
--
ALTER TABLE `notification_logs`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `notification_preferences`
--
ALTER TABLE `notification_preferences`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `ordinances`
--
ALTER TABLE `ordinances`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=6;

--
-- AUTO_INCREMENT for table `progress_analytics`
--
ALTER TABLE `progress_analytics`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `progress_forecasts`
--
ALTER TABLE `progress_forecasts`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `progress_kpis`
--
ALTER TABLE `progress_kpis`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=7;

--
-- AUTO_INCREMENT for table `progress_milestones`
--
ALTER TABLE `progress_milestones`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `reference_numbering`
--
ALTER TABLE `reference_numbering`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=5;

--
-- AUTO_INCREMENT for table `resolutions`
--
ALTER TABLE `resolutions`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `sessions`
--
ALTER TABLE `sessions`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=13;

--
-- AUTO_INCREMENT for table `status_history`
--
ALTER TABLE `status_history`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `supporting_documents`
--
ALTER TABLE `supporting_documents`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=7;

--
-- AUTO_INCREMENT for table `tagging_history`
--
ALTER TABLE `tagging_history`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `template_categories`
--
ALTER TABLE `template_categories`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=9;

--
-- AUTO_INCREMENT for table `template_favorites`
--
ALTER TABLE `template_favorites`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT for table `template_usage`
--
ALTER TABLE `template_usage`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- AUTO_INCREMENT for table `timeline_comments`
--
ALTER TABLE `timeline_comments`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `timeline_notifications`
--
ALTER TABLE `timeline_notifications`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `timeline_tracking`
--
ALTER TABLE `timeline_tracking`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `users`
--
ALTER TABLE `users`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=5;

--
-- AUTO_INCREMENT for table `version_metadata`
--
ALTER TABLE `version_metadata`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `version_relationships`
--
ALTER TABLE `version_relationships`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `version_search_index`
--
ALTER TABLE `version_search_index`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- Constraints for dumped tables
--

--
-- Constraints for table `ai_amendment_analytics`
--
ALTER TABLE `ai_amendment_analytics`
  ADD CONSTRAINT `ai_amendment_analytics_ibfk_1` FOREIGN KEY (`amendment_id`) REFERENCES `amendment_submissions` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `ai_amendment_analytics_ibfk_2` FOREIGN KEY (`generated_by`) REFERENCES `users` (`id`);

--
-- Constraints for table `amendment_approval_signatures`
--
ALTER TABLE `amendment_approval_signatures`
  ADD CONSTRAINT `amendment_approval_signatures_ibfk_1` FOREIGN KEY (`amendment_id`) REFERENCES `amendment_submissions` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `amendment_approval_signatures_ibfk_2` FOREIGN KEY (`signatory_id`) REFERENCES `users` (`id`);

--
-- Constraints for table `amendment_approval_workflow`
--
ALTER TABLE `amendment_approval_workflow`
  ADD CONSTRAINT `amendment_approval_workflow_ibfk_1` FOREIGN KEY (`amendment_id`) REFERENCES `amendment_submissions` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `amendment_approval_workflow_ibfk_2` FOREIGN KEY (`assigned_to`) REFERENCES `users` (`id`);

--
-- Constraints for table `amendment_attachments`
--
ALTER TABLE `amendment_attachments`
  ADD CONSTRAINT `amendment_attachments_ibfk_1` FOREIGN KEY (`amendment_id`) REFERENCES `amendment_submissions` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `amendment_attachments_ibfk_2` FOREIGN KEY (`uploaded_by`) REFERENCES `users` (`id`);

--
-- Constraints for table `amendment_authors`
--
ALTER TABLE `amendment_authors`
  ADD CONSTRAINT `amendment_authors_ibfk_1` FOREIGN KEY (`amendment_id`) REFERENCES `amendment_submissions` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `amendment_authors_ibfk_2` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`),
  ADD CONSTRAINT `amendment_authors_ibfk_3` FOREIGN KEY (`assigned_by`) REFERENCES `users` (`id`);

--
-- Constraints for table `amendment_comparisons`
--
ALTER TABLE `amendment_comparisons`
  ADD CONSTRAINT `amendment_comparisons_ibfk_1` FOREIGN KEY (`amendment_id`) REFERENCES `amendment_submissions` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `amendment_history`
--
ALTER TABLE `amendment_history`
  ADD CONSTRAINT `amendment_history_ibfk_1` FOREIGN KEY (`amendment_id`) REFERENCES `amendment_submissions` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `amendment_history_ibfk_2` FOREIGN KEY (`performed_by`) REFERENCES `users` (`id`);

--
-- Constraints for table `amendment_reviews`
--
ALTER TABLE `amendment_reviews`
  ADD CONSTRAINT `amendment_reviews_ibfk_1` FOREIGN KEY (`amendment_id`) REFERENCES `amendment_submissions` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `amendment_reviews_ibfk_2` FOREIGN KEY (`reviewed_by`) REFERENCES `users` (`id`);

--
-- Constraints for table `amendment_submissions`
--
ALTER TABLE `amendment_submissions`
  ADD CONSTRAINT `amendment_submissions_ibfk_1` FOREIGN KEY (`submitted_by`) REFERENCES `users` (`id`),
  ADD CONSTRAINT `amendment_submissions_ibfk_2` FOREIGN KEY (`reviewed_by`) REFERENCES `users` (`id`),
  ADD CONSTRAINT `amendment_submissions_ibfk_3` FOREIGN KEY (`approved_by`) REFERENCES `users` (`id`),
  ADD CONSTRAINT `amendment_submissions_ibfk_4` FOREIGN KEY (`committee_id`) REFERENCES `committees` (`id`);

--
-- Constraints for table `amendment_voting`
--
ALTER TABLE `amendment_voting`
  ADD CONSTRAINT `amendment_voting_ibfk_1` FOREIGN KEY (`amendment_id`) REFERENCES `amendment_submissions` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `amendment_voting_ibfk_2` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`);

--
-- Constraints for table `audit_logs`
--
ALTER TABLE `audit_logs`
  ADD CONSTRAINT `audit_logs_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`);

--
-- Constraints for table `change_comments`
--
ALTER TABLE `change_comments`
  ADD CONSTRAINT `change_comments_ibfk_1` FOREIGN KEY (`highlight_id`) REFERENCES `change_highlights` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `change_comments_ibfk_2` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`),
  ADD CONSTRAINT `change_comments_ibfk_3` FOREIGN KEY (`resolved_by`) REFERENCES `users` (`id`);

--
-- Constraints for table `change_highlights`
--
ALTER TABLE `change_highlights`
  ADD CONSTRAINT `change_highlights_ibfk_1` FOREIGN KEY (`session_id`) REFERENCES `comparison_sessions` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `change_highlights_ibfk_2` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`);

--
-- Constraints for table `committees`
--
ALTER TABLE `committees`
  ADD CONSTRAINT `committees_ibfk_1` FOREIGN KEY (`chairperson_id`) REFERENCES `users` (`id`),
  ADD CONSTRAINT `committees_ibfk_2` FOREIGN KEY (`vice_chairperson_id`) REFERENCES `users` (`id`);

--
-- Constraints for table `committee_members`
--
ALTER TABLE `committee_members`
  ADD CONSTRAINT `committee_members_ibfk_1` FOREIGN KEY (`committee_id`) REFERENCES `committees` (`id`),
  ADD CONSTRAINT `committee_members_ibfk_2` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`),
  ADD CONSTRAINT `committee_members_ibfk_3` FOREIGN KEY (`assigned_by`) REFERENCES `users` (`id`);

--
-- Constraints for table `comparison_analytics`
--
ALTER TABLE `comparison_analytics`
  ADD CONSTRAINT `comparison_analytics_ibfk_1` FOREIGN KEY (`session_id`) REFERENCES `comparison_sessions` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `comparison_sessions`
--
ALTER TABLE `comparison_sessions`
  ADD CONSTRAINT `comparison_sessions_ibfk_1` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`),
  ADD CONSTRAINT `comparison_sessions_ibfk_2` FOREIGN KEY (`old_version_id`) REFERENCES `document_versions` (`id`),
  ADD CONSTRAINT `comparison_sessions_ibfk_3` FOREIGN KEY (`new_version_id`) REFERENCES `document_versions` (`id`);

--
-- Constraints for table `comparison_shares`
--
ALTER TABLE `comparison_shares`
  ADD CONSTRAINT `comparison_shares_ibfk_1` FOREIGN KEY (`session_id`) REFERENCES `comparison_sessions` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `comparison_shares_ibfk_2` FOREIGN KEY (`shared_with`) REFERENCES `users` (`id`),
  ADD CONSTRAINT `comparison_shares_ibfk_3` FOREIGN KEY (`shared_by`) REFERENCES `users` (`id`);

--
-- Constraints for table `document_authors`
--
ALTER TABLE `document_authors`
  ADD CONSTRAINT `document_authors_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`),
  ADD CONSTRAINT `document_authors_ibfk_2` FOREIGN KEY (`assigned_by`) REFERENCES `users` (`id`);

--
-- Constraints for table `document_categories`
--
ALTER TABLE `document_categories`
  ADD CONSTRAINT `document_categories_ibfk_1` FOREIGN KEY (`parent_id`) REFERENCES `document_categories` (`id`),
  ADD CONSTRAINT `document_categories_ibfk_2` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`);

--
-- Constraints for table `document_classification`
--
ALTER TABLE `document_classification`
  ADD CONSTRAINT `document_classification_ibfk_1` FOREIGN KEY (`category_id`) REFERENCES `document_categories` (`id`),
  ADD CONSTRAINT `document_classification_ibfk_2` FOREIGN KEY (`classified_by`) REFERENCES `users` (`id`),
  ADD CONSTRAINT `document_classification_ibfk_3` FOREIGN KEY (`reviewed_by`) REFERENCES `users` (`id`);

--
-- Constraints for table `document_committees`
--
ALTER TABLE `document_committees`
  ADD CONSTRAINT `document_committees_ibfk_1` FOREIGN KEY (`committee_id`) REFERENCES `committees` (`id`),
  ADD CONSTRAINT `document_committees_ibfk_2` FOREIGN KEY (`assigned_by`) REFERENCES `users` (`id`);

--
-- Constraints for table `document_keywords`
--
ALTER TABLE `document_keywords`
  ADD CONSTRAINT `document_keywords_ibfk_1` FOREIGN KEY (`added_by`) REFERENCES `users` (`id`);

--
-- Constraints for table `document_numbering_logs`
--
ALTER TABLE `document_numbering_logs`
  ADD CONSTRAINT `document_numbering_logs_ibfk_1` FOREIGN KEY (`changed_by`) REFERENCES `users` (`id`);

--
-- Constraints for table `document_priority_history`
--
ALTER TABLE `document_priority_history`
  ADD CONSTRAINT `document_priority_history_ibfk_1` FOREIGN KEY (`changed_by`) REFERENCES `users` (`id`);

--
-- Constraints for table `document_templates`
--
ALTER TABLE `document_templates`
  ADD CONSTRAINT `document_templates_ibfk_1` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`);

--
-- Constraints for table `document_versions`
--
ALTER TABLE `document_versions`
  ADD CONSTRAINT `document_versions_ibfk_1` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`);

--
-- Constraints for table `document_views`
--
ALTER TABLE `document_views`
  ADD CONSTRAINT `document_views_ibfk_1` FOREIGN KEY (`viewed_by`) REFERENCES `users` (`id`);

--
-- Constraints for table `draft_registrations`
--
ALTER TABLE `draft_registrations`
  ADD CONSTRAINT `draft_registrations_ibfk_1` FOREIGN KEY (`registered_by`) REFERENCES `users` (`id`),
  ADD CONSTRAINT `draft_registrations_ibfk_2` FOREIGN KEY (`committee_id`) REFERENCES `committees` (`id`),
  ADD CONSTRAINT `draft_registrations_ibfk_3` FOREIGN KEY (`signature_by`) REFERENCES `users` (`id`);

--
-- Constraints for table `keyword_categories`
--
ALTER TABLE `keyword_categories`
  ADD CONSTRAINT `keyword_categories_ibfk_1` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`);

--
-- Constraints for table `keyword_suggestions`
--
ALTER TABLE `keyword_suggestions`
  ADD CONSTRAINT `keyword_suggestions_ibfk_1` FOREIGN KEY (`category_id`) REFERENCES `keyword_categories` (`id`),
  ADD CONSTRAINT `keyword_suggestions_ibfk_2` FOREIGN KEY (`suggested_by`) REFERENCES `users` (`id`);

--
-- Constraints for table `notifications`
--
ALTER TABLE `notifications`
  ADD CONSTRAINT `notifications_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`);

--
-- Constraints for table `notification_logs`
--
ALTER TABLE `notification_logs`
  ADD CONSTRAINT `notification_logs_ibfk_1` FOREIGN KEY (`notification_id`) REFERENCES `notifications` (`id`),
  ADD CONSTRAINT `notification_logs_ibfk_2` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`);

--
-- Constraints for table `notification_preferences`
--
ALTER TABLE `notification_preferences`
  ADD CONSTRAINT `notification_preferences_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`);

--
-- Constraints for table `ordinances`
--
ALTER TABLE `ordinances`
  ADD CONSTRAINT `ordinances_ibfk_1` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`),
  ADD CONSTRAINT `ordinances_ibfk_2` FOREIGN KEY (`approved_by`) REFERENCES `users` (`id`);

--
-- Constraints for table `reference_numbering`
--
ALTER TABLE `reference_numbering`
  ADD CONSTRAINT `reference_numbering_ibfk_1` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`);

--
-- Constraints for table `resolutions`
--
ALTER TABLE `resolutions`
  ADD CONSTRAINT `resolutions_ibfk_1` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`),
  ADD CONSTRAINT `resolutions_ibfk_2` FOREIGN KEY (`approved_by`) REFERENCES `users` (`id`);

--
-- Constraints for table `sessions`
--
ALTER TABLE `sessions`
  ADD CONSTRAINT `sessions_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`);

--
-- Constraints for table `status_history`
--
ALTER TABLE `status_history`
  ADD CONSTRAINT `status_history_ibfk_1` FOREIGN KEY (`changed_by`) REFERENCES `users` (`id`);

--
-- Constraints for table `supporting_documents`
--
ALTER TABLE `supporting_documents`
  ADD CONSTRAINT `supporting_documents_ibfk_1` FOREIGN KEY (`uploaded_by`) REFERENCES `users` (`id`);

--
-- Constraints for table `tagging_history`
--
ALTER TABLE `tagging_history`
  ADD CONSTRAINT `tagging_history_ibfk_1` FOREIGN KEY (`keyword_id`) REFERENCES `document_keywords` (`id`),
  ADD CONSTRAINT `tagging_history_ibfk_2` FOREIGN KEY (`performed_by`) REFERENCES `users` (`id`);

--
-- Constraints for table `template_categories`
--
ALTER TABLE `template_categories`
  ADD CONSTRAINT `template_categories_ibfk_1` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`);

--
-- Constraints for table `template_favorites`
--
ALTER TABLE `template_favorites`
  ADD CONSTRAINT `template_favorites_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`),
  ADD CONSTRAINT `template_favorites_ibfk_2` FOREIGN KEY (`template_id`) REFERENCES `document_templates` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `template_usage`
--
ALTER TABLE `template_usage`
  ADD CONSTRAINT `template_usage_ibfk_1` FOREIGN KEY (`template_id`) REFERENCES `document_templates` (`id`),
  ADD CONSTRAINT `template_usage_ibfk_2` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`);
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
