-- phpMyAdmin SQL Dump
-- version 5.2.2
-- https://www.phpmyadmin.net/
--
-- Host: localhost:3306
-- Generation Time: Mar 03, 2026 at 01:34 PM
-- Server version: 11.4.9-MariaDB-cll-lve-log
-- PHP Version: 8.3.30

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Database: `kursqiyd_online_courses`
--

-- --------------------------------------------------------

--
-- Table structure for table `appointments`
--

CREATE TABLE `appointments` (
  `id` int(11) NOT NULL,
  `course_id` int(11) NOT NULL,
  `title` varchar(255) NOT NULL,
  `description` text DEFAULT NULL,
  `appointment_date` datetime NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `link` varchar(255) DEFAULT NULL
) ENGINE=MyISAM DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

-- --------------------------------------------------------

--
-- Table structure for table `assignments`
--

CREATE TABLE `assignments` (
  `id` int(11) NOT NULL,
  `course_id` int(11) NOT NULL,
  `section_id` int(11) DEFAULT NULL,
  `title` varchar(255) NOT NULL,
  `description` mediumtext DEFAULT NULL,
  `resource_path` varchar(255) DEFAULT NULL,
  `solution_path` varchar(255) DEFAULT NULL,
  `due_date` date DEFAULT NULL,
  `status` enum('SUBMITTED','PENDING','GRADED','EXPIRED') DEFAULT 'PENDING',
  `hidden` tinyint(1) NOT NULL DEFAULT 0,
  `uploaded_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Triggers `assignments`
--
DELIMITER $$
CREATE TRIGGER `trg_assignments_after_insert_student` AFTER INSERT ON `assignments` FOR EACH ROW BEGIN
  DECLARE v_course_title VARCHAR(255);
  DECLARE v_due VARCHAR(32);
  DECLARE v_nid BIGINT;

  SELECT c.title INTO v_course_title FROM courses c WHERE c.id = NEW.course_id LIMIT 1;
  SET v_due = IFNULL(DATE_FORMAT(NEW.due_date,'%d.%m.%Y'),'pa afat');

  INSERT INTO notifications(type, title, body, target_url, actor_user_id, course_id, created_at)
  VALUES(
    'assignment_published',
    'Detyrë e re',
    CONCAT('Detyra "', NEW.title, '" u publikua në "', IFNULL(v_course_title,''), '". Afati: ', v_due, '.'),
    CONCAT('assignment_details.php?assignment_id=', NEW.id),
    NULL,
    NEW.course_id,
    NOW()
  );
  SET v_nid = LAST_INSERT_ID();
  CALL sp_fanout_course_students(v_nid, NEW.course_id);
END
$$
DELIMITER ;

-- --------------------------------------------------------

--
-- Table structure for table `assignments_files`
--

CREATE TABLE `assignments_files` (
  `id` int(11) NOT NULL,
  `assignment_id` int(11) NOT NULL,
  `file_path` varchar(255) NOT NULL,
  `uploaded_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=MyISAM DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

-- --------------------------------------------------------

--
-- Table structure for table `assignments_submitted`
--

CREATE TABLE `assignments_submitted` (
  `id` int(11) NOT NULL,
  `assignment_id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `file_path` varchar(255) NOT NULL,
  `submitted_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `grade` int(11) DEFAULT NULL CHECK (`grade` between 1 and 10),
  `feedback` text DEFAULT NULL
) ENGINE=MyISAM DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

--
-- Triggers `assignments_submitted`
--
DELIMITER $$
CREATE TRIGGER `trg_asub_after_insert` AFTER INSERT ON `assignments_submitted` FOR EACH ROW BEGIN
  DECLARE v_course_id INT;
  DECLARE v_title     VARCHAR(255);
  DECLARE v_actor     VARCHAR(255);
  DECLARE v_nid       BIGINT;

  SELECT a.course_id, a.title
    INTO v_course_id, v_title
    FROM assignments a
   WHERE a.id = NEW.assignment_id
   LIMIT 1;

  SELECT u.full_name INTO v_actor FROM users u WHERE u.id = NEW.user_id LIMIT 1;

  INSERT INTO notifications(type, title, body, target_url, actor_user_id, course_id, created_at)
  VALUES (
    'assignment_submitted',
    'Detyrë e dorëzuar',
    CONCAT(v_actor, ' dorëzoi "', v_title, '".'),
    CONCAT('assignment_details.php?assignment_id=', NEW.assignment_id),
    NEW.user_id,
    v_course_id,
    NOW()
  );
  SET v_nid = LAST_INSERT_ID();
  CALL sp_fanout_admins(v_nid);
END
$$
DELIMITER ;
DELIMITER $$
CREATE TRIGGER `trg_asub_after_update_graded` AFTER UPDATE ON `assignments_submitted` FOR EACH ROW BEGIN
  DECLARE v_course_id INT;
  DECLARE v_title     VARCHAR(255);
  DECLARE v_student   VARCHAR(255);
  DECLARE v_nid       BIGINT;

  IF (OLD.grade IS NULL AND NEW.grade IS NOT NULL) THEN
    SELECT a.course_id, a.title
      INTO v_course_id, v_title
      FROM assignments a
     WHERE a.id = NEW.assignment_id
     LIMIT 1;

    SELECT u.full_name INTO v_student FROM users u WHERE u.id = NEW.user_id LIMIT 1;

    INSERT INTO notifications(type, title, body, target_url, actor_user_id, course_id, created_at)
    VALUES(
      'assignment_graded',
      'Detyrë e vlerësuar',
      CONCAT(v_student,' mori notën ', NEW.grade, ' për "', v_title, '".'),
      CONCAT('assignment_details.php?assignment_id=', NEW.assignment_id),
      NULL,
      v_course_id,
      NOW()
    );
    SET v_nid = LAST_INSERT_ID();
    CALL sp_fanout_admins(v_nid);
  END IF;
END
$$
DELIMITER ;
DELIMITER $$
CREATE TRIGGER `trg_asub_after_update_graded_student` AFTER UPDATE ON `assignments_submitted` FOR EACH ROW BEGIN
  DECLARE v_course_id INT;
  DECLARE v_title VARCHAR(255);
  DECLARE v_nid BIGINT;

  IF (OLD.grade IS NULL AND NEW.grade IS NOT NULL) THEN
    SELECT a.course_id, a.title
      INTO v_course_id, v_title
      FROM assignments a
     WHERE a.id = NEW.assignment_id
     LIMIT 1;

    INSERT INTO notifications(type, title, body, target_url, actor_user_id, course_id, created_at)
    VALUES(
      'assignment_graded',
      'Detyra u vlerësua',
      CONCAT('Detyra "', v_title, '" u vlerësua me notën ', NEW.grade, '.'),
      CONCAT('assignment_details.php?assignment_id=', NEW.assignment_id),
      NULL,           -- nuk kemi graded_by në këtë tabelë
      v_course_id,
      NOW()
    );
    SET v_nid = LAST_INSERT_ID();
    CALL sp_notify_user(v_nid, NEW.user_id); -- vetëm studenti në fjalë
  END IF;
END
$$
DELIMITER ;

-- --------------------------------------------------------

--
-- Table structure for table `attempt_answers`
--

CREATE TABLE `attempt_answers` (
  `id` int(11) NOT NULL,
  `attempt_id` int(11) NOT NULL,
  `question_id` int(11) NOT NULL,
  `option_id` int(11) DEFAULT NULL,
  `answer_text` mediumtext DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `attempt_question_scores`
--

CREATE TABLE `attempt_question_scores` (
  `id` int(11) NOT NULL,
  `attempt_id` int(11) NOT NULL,
  `question_id` int(11) NOT NULL,
  `is_correct` tinyint(1) DEFAULT NULL,
  `points_awarded` decimal(10,2) DEFAULT NULL,
  `needs_manual` tinyint(1) NOT NULL DEFAULT 0,
  `feedback` mediumtext DEFAULT NULL,
  `graded_by` int(11) DEFAULT NULL,
  `graded_at` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `courses`
--

CREATE TABLE `courses` (
  `id` int(11) NOT NULL,
  `title` varchar(255) NOT NULL,
  `description` mediumtext DEFAULT NULL,
  `id_lesson` int(11) DEFAULT NULL,
  `id_creator` int(11) NOT NULL,
  `access_code` char(5) DEFAULT NULL,
  `status` enum('ACTIVE','INACTIVE','ARCHIVED') DEFAULT 'ACTIVE',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `category` enum('PROGRAMIM','GRAFIKA','WEB','GJUHE TE HUAJA','IT','TJETRA') NOT NULL,
  `photo` varchar(255) DEFAULT NULL,
  `AulaVirtuale` varchar(255) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `course_tests`
--

CREATE TABLE `course_tests` (
  `id` int(11) NOT NULL,
  `course_id` int(11) NOT NULL,
  `title` varchar(255) NOT NULL,
  `description` text DEFAULT NULL,
  `instructions` text DEFAULT NULL,
  `status` enum('DRAFT','PUBLISHED','ARCHIVED') NOT NULL DEFAULT 'DRAFT',
  `availability_start` datetime DEFAULT NULL,
  `availability_end` datetime DEFAULT NULL,
  `time_limit_minutes` int(11) DEFAULT NULL,
  `attempts_allowed` int(11) NOT NULL DEFAULT 1,
  `randomize_questions` tinyint(1) NOT NULL DEFAULT 0,
  `randomize_answers` tinyint(1) NOT NULL DEFAULT 0,
  `total_points` decimal(10,2) NOT NULL DEFAULT 0.00,
  `created_by` int(11) DEFAULT NULL,
  `updated_by` int(11) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `published_at` datetime DEFAULT NULL
) ENGINE=MyISAM DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

--
-- Triggers `course_tests`
--
DELIMITER $$
CREATE TRIGGER `trg_ctests_after_insert_student` AFTER INSERT ON `course_tests` FOR EACH ROW BEGIN
  DECLARE v_course_title VARCHAR(255);
  DECLARE v_nid BIGINT;

  IF (NEW.status='PUBLISHED') THEN
    SELECT c.title INTO v_course_title FROM courses c WHERE c.id = NEW.course_id LIMIT 1;

    INSERT INTO notifications(type, title, body, target_url, actor_user_id, course_id, created_at)
    VALUES(
      'test_published',
      'Test i ri',
      CONCAT('Testi "', NEW.title, '" u publikua në "', IFNULL(v_course_title,''), '".'),
      CONCAT('test_manage.php?test_id=', NEW.id),
      NULL,
      NEW.course_id,
      NOW()
    );
    SET v_nid = LAST_INSERT_ID();
    CALL sp_fanout_course_students(v_nid, NEW.course_id);
  END IF;
END
$$
DELIMITER ;
DELIMITER $$
CREATE TRIGGER `trg_ctests_after_update_student` AFTER UPDATE ON `course_tests` FOR EACH ROW BEGIN
  DECLARE v_course_title VARCHAR(255);
  DECLARE v_nid BIGINT;

  IF (OLD.status <> 'PUBLISHED' AND NEW.status='PUBLISHED') THEN
    SELECT c.title INTO v_course_title FROM courses c WHERE c.id = NEW.course_id LIMIT 1;

    INSERT INTO notifications(type, title, body, target_url, actor_user_id, course_id, created_at)
    VALUES(
      'test_published',
      'Test i ri',
      CONCAT('Testi "', NEW.title, '" u publikua në "', IFNULL(v_course_title,''), '".'),
      CONCAT('test_manage.php?test_id=', NEW.id),
      NULL,
      NEW.course_id,
      NOW()
    );
    SET v_nid = LAST_INSERT_ID();
    CALL sp_fanout_course_students(v_nid, NEW.course_id);
  END IF;
END
$$
DELIMITER ;

-- --------------------------------------------------------

--
-- Table structure for table `course_test_attempts`
--

CREATE TABLE `course_test_attempts` (
  `id` int(11) NOT NULL,
  `test_id` int(11) NOT NULL,
  `student_id` int(11) NOT NULL,
  `started_at` datetime NOT NULL DEFAULT current_timestamp(),
  `submitted_at` datetime DEFAULT NULL,
  `status` enum('IN_PROGRESS','SUBMITTED','GRADED') NOT NULL DEFAULT 'IN_PROGRESS',
  `score` decimal(10,2) DEFAULT NULL,
  `max_score` decimal(10,2) DEFAULT NULL,
  `grading_notes` text DEFAULT NULL,
  `graded_by` int(11) DEFAULT NULL,
  `graded_at` datetime DEFAULT NULL
) ENGINE=MyISAM DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

--
-- Triggers `course_test_attempts`
--
DELIMITER $$
CREATE TRIGGER `trg_tatt_after_insert` AFTER INSERT ON `course_test_attempts` FOR EACH ROW BEGIN
  DECLARE v_course_id INT;
  DECLARE v_title     VARCHAR(255);
  DECLARE v_actor     VARCHAR(255);
  DECLARE v_nid       BIGINT;

  IF NEW.submitted_at IS NOT NULL THEN
    SELECT t.course_id, t.title
      INTO v_course_id, v_title
      FROM course_tests t
     WHERE t.id = NEW.test_id
     LIMIT 1;

    SELECT u.full_name INTO v_actor FROM users u WHERE u.id = NEW.student_id LIMIT 1;

    INSERT INTO notifications(type, title, body, target_url, actor_user_id, course_id, created_at)
    VALUES(
      'test_submitted',
      'Test i përfunduar',
      CONCAT(v_actor,' dorëzoi testin "', v_title, '".'),
      CONCAT('test_manage.php?test_id=', NEW.test_id),
      NEW.student_id,
      v_course_id,
      NOW()
    );
    SET v_nid = LAST_INSERT_ID();
    CALL sp_fanout_admins(v_nid);
  END IF;
END
$$
DELIMITER ;
DELIMITER $$
CREATE TRIGGER `trg_tatt_after_update` AFTER UPDATE ON `course_test_attempts` FOR EACH ROW BEGIN
  DECLARE v_course_id INT;
  DECLARE v_title     VARCHAR(255);
  DECLARE v_actor     VARCHAR(255);
  DECLARE v_nid       BIGINT;

  /* u dorëzua tani */
  IF (OLD.submitted_at IS NULL AND NEW.submitted_at IS NOT NULL) THEN
    SELECT t.course_id, t.title
      INTO v_course_id, v_title
      FROM course_tests t
     WHERE t.id = NEW.test_id
     LIMIT 1;

    SELECT u.full_name INTO v_actor FROM users u WHERE u.id = NEW.student_id LIMIT 1;

    INSERT INTO notifications(type, title, body, target_url, actor_user_id, course_id, created_at)
    VALUES(
      'test_submitted',
      'Test i përfunduar',
      CONCAT(v_actor,' dorëzoi testin "', v_title, '".'),
      CONCAT('test_manage.php?test_id=', NEW.test_id),
      NEW.student_id,
      v_course_id,
      NOW()
    );
    SET v_nid = LAST_INSERT_ID();
    CALL sp_fanout_admins(v_nid);
  END IF;

  /* u vlerësua */
  IF ((OLD.status <> 'GRADED' AND NEW.status='GRADED') OR (OLD.score IS NULL AND NEW.score IS NOT NULL)) THEN
    SELECT t.course_id, t.title
      INTO v_course_id, v_title
      FROM course_tests t
     WHERE t.id = NEW.test_id
     LIMIT 1;

    SELECT u.full_name INTO v_actor FROM users u WHERE u.id = NEW.student_id LIMIT 1;

    INSERT INTO notifications(type, title, body, target_url, actor_user_id, course_id, created_at)
    VALUES(
      'test_graded',
      'Test i vlerësuar',
      CONCAT(v_actor,' mori ', COALESCE(NEW.score,0), '/', COALESCE(NEW.max_score,0), ' pikë në "', v_title, '".'),
      CONCAT('test_manage.php?test_id=', NEW.test_id),
      NULL,
      v_course_id,
      NOW()
    );
    SET v_nid = LAST_INSERT_ID();
    CALL sp_fanout_admins(v_nid);
  END IF;
END
$$
DELIMITER ;
DELIMITER $$
CREATE TRIGGER `trg_tatt_after_update_graded_student` AFTER UPDATE ON `course_test_attempts` FOR EACH ROW BEGIN
  DECLARE v_course_id INT;
  DECLARE v_title VARCHAR(255);
  DECLARE v_nid BIGINT;

  IF ((OLD.status <> 'GRADED' AND NEW.status='GRADED') OR (OLD.score IS NULL AND NEW.score IS NOT NULL)) THEN
    SELECT t.course_id, t.title
      INTO v_course_id, v_title
      FROM course_tests t
     WHERE t.id = NEW.test_id
     LIMIT 1;

    INSERT INTO notifications(type, title, body, target_url, actor_user_id, course_id, created_at)
    VALUES(
      'test_graded',
      'Test u vlerësua',
      CONCAT('Rezultati: ', COALESCE(NEW.score,0), '/', COALESCE(NEW.max_score,0), ' në "', v_title, '".'),
      CONCAT('test_manage.php?test_id=', NEW.test_id),
      NULL,
      v_course_id,
      NOW()
    );
    SET v_nid = LAST_INSERT_ID();
    CALL sp_notify_user(v_nid, NEW.student_id);
  END IF;
END
$$
DELIMITER ;

-- --------------------------------------------------------

--
-- Table structure for table `course_test_attempt_answers`
--

CREATE TABLE `course_test_attempt_answers` (
  `id` int(11) NOT NULL,
  `attempt_id` int(11) NOT NULL,
  `question_id` int(11) NOT NULL,
  `answer_text` longtext DEFAULT NULL,
  `answer_json` longtext DEFAULT NULL,
  `file_path` varchar(512) DEFAULT NULL,
  `is_correct` tinyint(1) DEFAULT NULL,
  `auto_points` decimal(10,2) DEFAULT NULL,
  `manual_points` decimal(10,2) DEFAULT NULL,
  `feedback` text DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=MyISAM DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

-- --------------------------------------------------------

--
-- Table structure for table `course_test_questions`
--

CREATE TABLE `course_test_questions` (
  `id` int(11) NOT NULL,
  `test_id` int(11) NOT NULL,
  `question_text` longtext NOT NULL,
  `question_type` enum('single_choice','multiple_choice','fill_blanks','dropdown','file_upload','essay') NOT NULL,
  `points` decimal(10,2) NOT NULL DEFAULT 1.00,
  `position` int(11) NOT NULL DEFAULT 0,
  `metadata_json` longtext DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=MyISAM DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

-- --------------------------------------------------------

--
-- Table structure for table `course_test_question_options`
--

CREATE TABLE `course_test_question_options` (
  `id` int(11) NOT NULL,
  `question_id` int(11) NOT NULL,
  `option_label` varchar(255) NOT NULL,
  `option_value` varchar(255) DEFAULT NULL,
  `is_correct` tinyint(1) NOT NULL DEFAULT 0,
  `position` int(11) NOT NULL DEFAULT 0,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=MyISAM DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

-- --------------------------------------------------------

--
-- Table structure for table `enroll`
--

CREATE TABLE `enroll` (
  `id` int(11) NOT NULL,
  `course_id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `enrolled_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=MyISAM DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

--
-- Triggers `enroll`
--
DELIMITER $$
CREATE TRIGGER `trg_enroll_after_insert_student` AFTER INSERT ON `enroll` FOR EACH ROW BEGIN
  DECLARE v_course_title VARCHAR(255);
  DECLARE v_nid BIGINT;

  SELECT c.title
    INTO v_course_title
    FROM courses c
   WHERE c.id = NEW.course_id
   LIMIT 1;

  INSERT INTO notifications(type, title, body, target_url, actor_user_id, course_id, created_at)
  VALUES(
    'course_enrolled',
    'U regjistruat në kurs',
    CONCAT('U regjistruat në "', IFNULL(v_course_title,''), '".'),
    CONCAT('course_details_student.php?course_id=', NEW.course_id),
    NEW.user_id,
    NEW.course_id,
    NOW()
  );

  SET v_nid = LAST_INSERT_ID();
  CALL sp_notify_user(v_nid, NEW.user_id);
END
$$
DELIMITER ;

-- --------------------------------------------------------

--
-- Table structure for table `enroll_events`
--

CREATE TABLE `enroll_events` (
  `id` int(11) NOT NULL,
  `event_id` int(11) NOT NULL,
  `first_name` varchar(100) NOT NULL,
  `last_name` varchar(100) NOT NULL,
  `email` varchar(255) NOT NULL,
  `phone` varchar(20) DEFAULT NULL,
  `enrolled_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=MyISAM DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

--
-- Triggers `enroll_events`
--
DELIMITER $$
CREATE TRIGGER `trg_evenroll_after_insert` AFTER INSERT ON `enroll_events` FOR EACH ROW BEGIN
  DECLARE v_event_title VARCHAR(255);
  DECLARE v_nid         BIGINT;

  SELECT e.title INTO v_event_title FROM events e WHERE e.id = NEW.event_id LIMIT 1;

  INSERT INTO notifications(type, title, body, target_url, actor_user_id, course_id, created_at)
  VALUES(
    'event_enrollment',
    'Regjistrim në event',
    CONCAT(NEW.first_name,' ',NEW.last_name,' u regjistrua në "', v_event_title, '".'),
    CONCAT('event.php?id=', NEW.event_id),
    NULL,
    NULL,
    NOW()
  );
  SET v_nid = LAST_INSERT_ID();
  CALL sp_fanout_admins(v_nid);
END
$$
DELIMITER ;

-- --------------------------------------------------------

--
-- Table structure for table `events`
--

CREATE TABLE `events` (
  `id` int(11) NOT NULL,
  `title` varchar(255) NOT NULL,
  `description` text DEFAULT NULL,
  `id_creator` int(11) NOT NULL,
  `status` enum('ACTIVE','INACTIVE','ARCHIVED') DEFAULT 'ACTIVE',
  `category` varchar(50) NOT NULL,
  `photo` varchar(255) NOT NULL,
  `location` varchar(255) DEFAULT NULL,
  `event_datetime` datetime NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=MyISAM DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

-- --------------------------------------------------------

--
-- Table structure for table `lessons`
--

CREATE TABLE `lessons` (
  `id` int(11) NOT NULL,
  `course_id` int(11) NOT NULL,
  `section_id` int(11) DEFAULT NULL,
  `title` varchar(255) NOT NULL,
  `description` mediumtext DEFAULT NULL,
  `URL` varchar(255) DEFAULT NULL,
  `category` enum('LEKSION','VIDEO','LINK','FILE','REFERENCA','LAB','TJETER') NOT NULL DEFAULT 'LEKSION',
  `uploaded_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `notebook_path` varchar(255) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Triggers `lessons`
--
DELIMITER $$
CREATE TRIGGER `trg_lessons_after_insert_student` AFTER INSERT ON `lessons` FOR EACH ROW BEGIN
  DECLARE v_course_title VARCHAR(255);
  DECLARE v_nid BIGINT;

  SELECT c.title INTO v_course_title
    FROM courses c
   WHERE c.id = NEW.course_id
   LIMIT 1;

  INSERT INTO notifications(type, title, body, target_url, actor_user_id, course_id, created_at)
  VALUES(
    'lesson_published',
    'Leksion i ri',
    CONCAT('Leksioni "', NEW.title, '" u publikua në kursin "', IFNULL(v_course_title,''), '".'),
    CONCAT('lesson_details.php?lesson_id=', NEW.id),
    NULL,
    NEW.course_id,
    NOW()
  );
  SET v_nid = LAST_INSERT_ID();
  CALL sp_fanout_course_students(v_nid, NEW.course_id);
END
$$
DELIMITER ;

-- --------------------------------------------------------

--
-- Table structure for table `lesson_files`
--

CREATE TABLE `lesson_files` (
  `id` int(11) NOT NULL,
  `lesson_id` int(11) NOT NULL,
  `file_path` varchar(255) NOT NULL,
  `file_type` enum('PDF','VIDEO','SLIDES','DOC') DEFAULT NULL,
  `uploaded_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=MyISAM DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

-- --------------------------------------------------------

--
-- Table structure for table `lesson_images`
--

CREATE TABLE `lesson_images` (
  `id` int(10) UNSIGNED NOT NULL,
  `lesson_id` int(11) NOT NULL,
  `file_path` varchar(255) NOT NULL,
  `alt_text` varchar(255) DEFAULT NULL,
  `position` int(11) NOT NULL DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `messages`
--

CREATE TABLE `messages` (
  `id` int(11) NOT NULL,
  `name` varchar(100) NOT NULL,
  `email` varchar(100) NOT NULL,
  `subject` varchar(255) NOT NULL,
  `message` text NOT NULL,
  `read_status` tinyint(1) DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=MyISAM DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

--
-- Triggers `messages`
--
DELIMITER $$
CREATE TRIGGER `trg_msg_after_insert` AFTER INSERT ON `messages` FOR EACH ROW BEGIN
  DECLARE v_nid BIGINT;

  INSERT INTO notifications(type, title, body, target_url, actor_user_id, course_id, created_at)
  VALUES(
    'message_received',
    'Mesazh i ri',
    CONCAT('Nga ', NEW.name, ' — "', NEW.subject, '"'),
    'messages.php',
    NULL,
    NULL,
    NOW()
  );
  SET v_nid = LAST_INSERT_ID();
  CALL sp_fanout_admins(v_nid);
END
$$
DELIMITER ;

-- --------------------------------------------------------

--
-- Table structure for table `notes`
--

CREATE TABLE `notes` (
  `id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `lesson_id` int(11) NOT NULL,
  `title` varchar(255) NOT NULL,
  `content` text NOT NULL,
  `file_path` varchar(255) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=MyISAM DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

-- --------------------------------------------------------

--
-- Table structure for table `notifications`
--

CREATE TABLE `notifications` (
  `id` bigint(20) NOT NULL,
  `type` varchar(50) NOT NULL,
  `title` varchar(255) NOT NULL,
  `body` text DEFAULT NULL,
  `target_url` varchar(255) DEFAULT NULL,
  `actor_user_id` int(11) DEFAULT NULL,
  `course_id` int(11) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `notification_users`
--

CREATE TABLE `notification_users` (
  `id` bigint(20) NOT NULL,
  `notification_id` bigint(20) NOT NULL,
  `user_id` int(11) NOT NULL,
  `is_read` tinyint(1) NOT NULL DEFAULT 0,
  `read_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `payments`
--

CREATE TABLE `payments` (
  `id` int(11) NOT NULL,
  `course_id` int(11) NOT NULL,
  `lesson_id` int(11) DEFAULT NULL,
  `user_id` int(11) NOT NULL,
  `amount` decimal(10,2) NOT NULL DEFAULT 0.00,
  `payment_status` enum('FAILED','COMPLETED') DEFAULT 'FAILED',
  `payment_date` timestamp NOT NULL DEFAULT current_timestamp(),
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=MyISAM DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

--
-- Triggers `payments`
--
DELIMITER $$
CREATE TRIGGER `trg_pay_after_insert` AFTER INSERT ON `payments` FOR EACH ROW BEGIN
  DECLARE v_course_title VARCHAR(255);
  DECLARE v_actor        VARCHAR(255);
  DECLARE v_nid          BIGINT;

  IF NEW.payment_status = 'COMPLETED' THEN
    SELECT c.title INTO v_course_title FROM courses c WHERE c.id = NEW.course_id LIMIT 1;
    SELECT u.full_name INTO v_actor FROM users u WHERE u.id = NEW.user_id LIMIT 1;

    INSERT INTO notifications(type, title, body, target_url, actor_user_id, course_id, created_at)
    VALUES(
      'payment_completed',
      'Pagesë e re',
      CONCAT(v_actor,' pagoi ', FORMAT(NEW.amount,2), IFNULL(CONCAT(' — "', v_course_title, '"'),''), '.'),
      'payments.php',
      NEW.user_id,
      NEW.course_id,
      NOW()
    );
    SET v_nid = LAST_INSERT_ID();
    CALL sp_fanout_admins(v_nid);
  END IF;
END
$$
DELIMITER ;
DELIMITER $$
CREATE TRIGGER `trg_pay_after_insert_student` AFTER INSERT ON `payments` FOR EACH ROW BEGIN
  DECLARE v_course_title VARCHAR(255);
  DECLARE v_nid BIGINT;

  IF NEW.payment_status = 'COMPLETED' THEN
    SELECT c.title INTO v_course_title FROM courses c WHERE c.id = NEW.course_id LIMIT 1;

    INSERT INTO notifications(type, title, body, target_url, actor_user_id, course_id, created_at)
    VALUES(
      'payment_completed',
      'Pagesa u krye',
      CONCAT('Pagesa prej ', FORMAT(NEW.amount,2), ' për "', IFNULL(v_course_title,''), '" u krye me sukses.'),
      'payments_student.php',
      NEW.user_id,
      NEW.course_id,
      NOW()
    );
    SET v_nid = LAST_INSERT_ID();
    CALL sp_notify_user(v_nid, NEW.user_id);
  END IF;
END
$$
DELIMITER ;
DELIMITER $$
CREATE TRIGGER `trg_pay_after_update` AFTER UPDATE ON `payments` FOR EACH ROW BEGIN
  DECLARE v_course_title VARCHAR(255);
  DECLARE v_actor        VARCHAR(255);
  DECLARE v_nid          BIGINT;

  IF (OLD.payment_status <> 'COMPLETED' AND NEW.payment_status = 'COMPLETED') THEN
    SELECT c.title INTO v_course_title FROM courses c WHERE c.id = NEW.course_id LIMIT 1;
    SELECT u.full_name INTO v_actor FROM users u WHERE u.id = NEW.user_id LIMIT 1;

    INSERT INTO notifications(type, title, body, target_url, actor_user_id, course_id, created_at)
    VALUES(
      'payment_completed',
      'Pagesë e re',
      CONCAT(v_actor,' pagoi ', FORMAT(NEW.amount,2), IFNULL(CONCAT(' — "', v_course_title, '"'),''), '.'),
      'payments.php',
      NEW.user_id,
      NEW.course_id,
      NOW()
    );
    SET v_nid = LAST_INSERT_ID();
    CALL sp_fanout_admins(v_nid);
  END IF;
END
$$
DELIMITER ;
DELIMITER $$
CREATE TRIGGER `trg_pay_after_update_student` AFTER UPDATE ON `payments` FOR EACH ROW BEGIN
  DECLARE v_course_title VARCHAR(255);
  DECLARE v_nid BIGINT;

  IF (OLD.payment_status <> 'COMPLETED' AND NEW.payment_status='COMPLETED') THEN
    SELECT c.title INTO v_course_title FROM courses c WHERE c.id = NEW.course_id LIMIT 1;

    INSERT INTO notifications(type, title, body, target_url, actor_user_id, course_id, created_at)
    VALUES(
      'payment_completed',
      'Pagesa u krye',
      CONCAT('Pagesa prej ', FORMAT(NEW.amount,2), ' për "', IFNULL(v_course_title,''), '" u krye me sukses.'),
      'payments_student.php',
      NEW.user_id,
      NEW.course_id,
      NOW()
    );
    SET v_nid = LAST_INSERT_ID();
    CALL sp_notify_user(v_nid, NEW.user_id);
  END IF;
END
$$
DELIMITER ;

-- --------------------------------------------------------

--
-- Table structure for table `promoted_courses`
--

CREATE TABLE `promoted_courses` (
  `id` int(10) UNSIGNED NOT NULL,
  `name` varchar(255) NOT NULL,
  `short_desc` varchar(255) DEFAULT NULL,
  `description` text DEFAULT NULL,
  `hours_total` int(10) UNSIGNED NOT NULL DEFAULT 0,
  `price` decimal(10,2) UNSIGNED DEFAULT NULL,
  `old_price` decimal(10,2) UNSIGNED DEFAULT NULL,
  `level` enum('BEGINNER','INTERMEDIATE','ADVANCED','ALL') NOT NULL DEFAULT 'ALL',
  `label` varchar(40) DEFAULT NULL,
  `badge_color` varchar(7) DEFAULT NULL,
  `photo` varchar(255) DEFAULT NULL,
  `video_url` varchar(255) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `promoted_course_enrollments`
--

CREATE TABLE `promoted_course_enrollments` (
  `id` int(10) UNSIGNED NOT NULL,
  `promotion_id` int(10) UNSIGNED NOT NULL,
  `user_id` int(11) DEFAULT NULL,
  `first_name` varchar(100) NOT NULL,
  `last_name` varchar(100) NOT NULL,
  `email` varchar(255) NOT NULL,
  `phone` varchar(20) DEFAULT NULL,
  `note` text DEFAULT NULL,
  `consent` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Triggers `promoted_course_enrollments`
--
DELIMITER $$
CREATE TRIGGER `trg_pce_after_insert` AFTER INSERT ON `promoted_course_enrollments` FOR EACH ROW BEGIN
  DECLARE v_promo_name VARCHAR(255);
  DECLARE v_nid        BIGINT;

  SELECT name INTO v_promo_name FROM promoted_courses WHERE id = NEW.promotion_id LIMIT 1;

  INSERT INTO notifications(type, title, body, target_url, actor_user_id, course_id, created_at)
  VALUES(
    'promotion_application',
    'Aplikim i ri në reklamë',
    CONCAT(NEW.first_name,' ',NEW.last_name,' (',NEW.email,') u regjistrua në "', IFNULL(v_promo_name,''), '".'),
    CONCAT('promotion_details.php?id=', NEW.promotion_id),
    NEW.user_id,   -- mund të jetë NULL; ruhet si referencë nëse ekziston
    NULL,
    NOW()
  );
  SET v_nid = LAST_INSERT_ID();
  CALL sp_fanout_admins(v_nid);
END
$$
DELIMITER ;

-- --------------------------------------------------------

--
-- Table structure for table `question_bank`
--

CREATE TABLE `question_bank` (
  `id` int(11) NOT NULL,
  `course_id` int(11) NOT NULL,
  `created_by` int(11) NOT NULL,
  `type` enum('MC_SINGLE','MC_MULTI','TRUE_FALSE','SHORT') NOT NULL,
  `text` mediumtext NOT NULL,
  `points` decimal(8,2) NOT NULL DEFAULT 1.00,
  `explanation` mediumtext DEFAULT NULL,
  `difficulty` varchar(32) DEFAULT NULL,
  `tags` varchar(255) DEFAULT NULL,
  `image_path` varchar(255) DEFAULT NULL,
  `short_answer_exact` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `question_options`
--

CREATE TABLE `question_options` (
  `id` int(11) NOT NULL,
  `question_id` int(11) NOT NULL,
  `option_text` mediumtext NOT NULL,
  `is_correct` tinyint(1) NOT NULL DEFAULT 0,
  `position` int(11) NOT NULL DEFAULT 1,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `quizzes`
--

CREATE TABLE `quizzes` (
  `id` int(11) NOT NULL,
  `course_id` int(11) NOT NULL,
  `section_id` int(11) DEFAULT NULL,
  `title` varchar(255) NOT NULL,
  `description` text DEFAULT NULL,
  `open_at` datetime DEFAULT NULL,
  `close_at` datetime DEFAULT NULL,
  `time_limit_sec` int(11) DEFAULT NULL,
  `attempts_allowed` int(11) NOT NULL DEFAULT 1,
  `shuffle_questions` tinyint(1) NOT NULL DEFAULT 0,
  `shuffle_answers` tinyint(1) NOT NULL DEFAULT 0,
  `hidden` tinyint(1) NOT NULL DEFAULT 0,
  `status` enum('DRAFT','PUBLISHED','ARCHIVED') NOT NULL DEFAULT 'DRAFT',
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Triggers `quizzes`
--
DELIMITER $$
CREATE TRIGGER `trg_quizzes_after_insert_student` AFTER INSERT ON `quizzes` FOR EACH ROW BEGIN
  DECLARE v_course_title VARCHAR(255);
  DECLARE v_nid BIGINT;
  IF (NEW.status='PUBLISHED' AND NEW.hidden=0) THEN
    SELECT c.title INTO v_course_title FROM courses c WHERE c.id = NEW.course_id LIMIT 1;

    INSERT INTO notifications(type, title, body, target_url, actor_user_id, course_id, created_at)
    VALUES(
      'quiz_published',
      'Quiz i ri',
      CONCAT('Quiz "', NEW.title, '" u publikua në "', IFNULL(v_course_title,''), '".'),
      CONCAT('quiz_details.php?quiz_id=', NEW.id),
      NULL,
      NEW.course_id,
      NOW()
    );
    SET v_nid = LAST_INSERT_ID();
    CALL sp_fanout_course_students(v_nid, NEW.course_id);
  END IF;
END
$$
DELIMITER ;
DELIMITER $$
CREATE TRIGGER `trg_quizzes_after_update_student` AFTER UPDATE ON `quizzes` FOR EACH ROW BEGIN
  DECLARE v_course_title VARCHAR(255);
  DECLARE v_nid BIGINT;
  IF (OLD.status <> 'PUBLISHED' AND NEW.status='PUBLISHED' AND NEW.hidden=0) THEN
    SELECT c.title INTO v_course_title FROM courses c WHERE c.id = NEW.course_id LIMIT 1;

    INSERT INTO notifications(type, title, body, target_url, actor_user_id, course_id, created_at)
    VALUES(
      'quiz_published',
      'Quiz i ri',
      CONCAT('Quiz "', NEW.title, '" u publikua në "', IFNULL(v_course_title,''), '".'),
      CONCAT('quiz_details.php?quiz_id=', NEW.id),
      NULL,
      NEW.course_id,
      NOW()
    );
    SET v_nid = LAST_INSERT_ID();
    CALL sp_fanout_course_students(v_nid, NEW.course_id);
  END IF;
END
$$
DELIMITER ;

-- --------------------------------------------------------

--
-- Table structure for table `quiz_answers`
--

CREATE TABLE `quiz_answers` (
  `id` int(11) NOT NULL,
  `question_id` int(11) NOT NULL,
  `answer_text` text NOT NULL,
  `is_correct` tinyint(1) NOT NULL DEFAULT 0,
  `position` int(11) NOT NULL DEFAULT 1,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `correct_key` int(11) GENERATED ALWAYS AS (case when `is_correct` = 1 then `question_id` else NULL end) VIRTUAL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `quiz_attempts`
--

CREATE TABLE `quiz_attempts` (
  `id` int(11) NOT NULL,
  `quiz_id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `started_at` datetime NOT NULL DEFAULT current_timestamp(),
  `submitted_at` datetime DEFAULT NULL,
  `score` int(11) DEFAULT NULL,
  `total_points` int(11) DEFAULT NULL,
  `answers_json` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`answers_json`)),
  `open_quiz_key` int(11) GENERATED ALWAYS AS (case when `submitted_at` is null then `quiz_id` else NULL end) VIRTUAL,
  `open_user_key` int(11) GENERATED ALWAYS AS (case when `submitted_at` is null then `user_id` else NULL end) VIRTUAL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Triggers `quiz_attempts`
--
DELIMITER $$
CREATE TRIGGER `trg_qatt_after_insert` AFTER INSERT ON `quiz_attempts` FOR EACH ROW BEGIN
  DECLARE v_course_id INT;
  DECLARE v_title     VARCHAR(255);
  DECLARE v_actor     VARCHAR(255);
  DECLARE v_nid       BIGINT;

  IF NEW.submitted_at IS NOT NULL THEN
    SELECT q.course_id, q.title
      INTO v_course_id, v_title
      FROM quizzes q
     WHERE q.id = NEW.quiz_id
     LIMIT 1;

    SELECT u.full_name INTO v_actor FROM users u WHERE u.id = NEW.user_id LIMIT 1;

    INSERT INTO notifications(type, title, body, target_url, actor_user_id, course_id, created_at)
    VALUES(
      'quiz_submitted',
      'Quiz i përfunduar',
      CONCAT(v_actor, ' përfundoi "', v_title, '".'),
      CONCAT('quiz_details.php?quiz_id=', NEW.quiz_id),
      NEW.user_id,
      v_course_id,
      NOW()
    );
    SET v_nid = LAST_INSERT_ID();
    CALL sp_fanout_admins(v_nid);
  END IF;
END
$$
DELIMITER ;
DELIMITER $$
CREATE TRIGGER `trg_qatt_after_update` AFTER UPDATE ON `quiz_attempts` FOR EACH ROW BEGIN
  DECLARE v_course_id INT;
  DECLARE v_title     VARCHAR(255);
  DECLARE v_actor     VARCHAR(255);
  DECLARE v_nid       BIGINT;

  /* u dorëzua tani */
  IF (OLD.submitted_at IS NULL AND NEW.submitted_at IS NOT NULL) THEN
    SELECT q.course_id, q.title
      INTO v_course_id, v_title
      FROM quizzes q
     WHERE q.id = NEW.quiz_id
     LIMIT 1;

    SELECT u.full_name INTO v_actor FROM users u WHERE u.id = NEW.user_id LIMIT 1;

    INSERT INTO notifications(type, title, body, target_url, actor_user_id, course_id, created_at)
    VALUES(
      'quiz_submitted',
      'Quiz i përfunduar',
      CONCAT(v_actor,' përfundoi "', v_title, '".'),
      CONCAT('quiz_details.php?quiz_id=', NEW.quiz_id),
      NEW.user_id,
      v_course_id,
      NOW()
    );
    SET v_nid = LAST_INSERT_ID();
    CALL sp_fanout_admins(v_nid);
  END IF;

  /* u vlerësua (po vendoset rezultati) */
  IF (OLD.score IS NULL AND NEW.score IS NOT NULL) THEN
    SELECT q.course_id, q.title
      INTO v_course_id, v_title
      FROM quizzes q
     WHERE q.id = NEW.quiz_id
     LIMIT 1;

    SELECT u.full_name INTO v_actor FROM users u WHERE u.id = NEW.user_id LIMIT 1;

    INSERT INTO notifications(type, title, body, target_url, actor_user_id, course_id, created_at)
    VALUES(
      'quiz_graded',
      'Quiz i vlerësuar',
      CONCAT(v_actor,' mori ', NEW.score, '/', COALESCE(NEW.total_points,0), ' pikë në "', v_title, '".'),
      CONCAT('quiz_details.php?quiz_id=', NEW.quiz_id),
      NULL,
      v_course_id,
      NOW()
    );
    SET v_nid = LAST_INSERT_ID();
    CALL sp_fanout_admins(v_nid);
  END IF;
END
$$
DELIMITER ;
DELIMITER $$
CREATE TRIGGER `trg_qatt_after_update_graded_student` AFTER UPDATE ON `quiz_attempts` FOR EACH ROW BEGIN
  DECLARE v_course_id INT;
  DECLARE v_title VARCHAR(255);
  DECLARE v_nid BIGINT;

  IF (OLD.score IS NULL AND NEW.score IS NOT NULL) THEN
    SELECT q.course_id, q.title
      INTO v_course_id, v_title
      FROM quizzes q
     WHERE q.id = NEW.quiz_id
     LIMIT 1;

    INSERT INTO notifications(type, title, body, target_url, actor_user_id, course_id, created_at)
    VALUES(
      'quiz_graded',
      'Quiz u vlerësua',
      CONCAT('Rezultati: ', NEW.score, '/', COALESCE(NEW.total_points,0), ' në "', v_title, '".'),
      CONCAT('quiz_details.php?quiz_id=', NEW.quiz_id),
      NULL,
      v_course_id,
      NOW()
    );
    SET v_nid = LAST_INSERT_ID();
    CALL sp_notify_user(v_nid, NEW.user_id);
  END IF;
END
$$
DELIMITER ;

-- --------------------------------------------------------

--
-- Table structure for table `quiz_questions`
--

CREATE TABLE `quiz_questions` (
  `id` int(11) NOT NULL,
  `quiz_id` int(11) NOT NULL,
  `question` text NOT NULL,
  `explanation` text DEFAULT NULL,
  `points` int(11) NOT NULL DEFAULT 1,
  `position` int(11) NOT NULL DEFAULT 1,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `sections`
--

CREATE TABLE `sections` (
  `id` int(11) NOT NULL,
  `course_id` int(11) NOT NULL,
  `area` enum('MATERIALS','LABS') NOT NULL DEFAULT 'MATERIALS',
  `title` varchar(255) NOT NULL,
  `description` text DEFAULT NULL,
  `position` int(11) NOT NULL DEFAULT 1,
  `hidden` tinyint(1) NOT NULL DEFAULT 0,
  `highlighted` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

-- --------------------------------------------------------

--
-- Table structure for table `section_items`
--

CREATE TABLE `section_items` (
  `id` int(11) NOT NULL,
  `course_id` int(11) NOT NULL,
  `area` enum('MATERIALS','LABS') NOT NULL DEFAULT 'MATERIALS',
  `section_id` int(11) NOT NULL,
  `item_type` enum('LESSON','ASSIGNMENT','QUIZ','TEXT') NOT NULL,
  `item_ref_id` int(11) DEFAULT NULL,
  `content_md` mediumtext DEFAULT NULL,
  `hidden` tinyint(1) NOT NULL DEFAULT 0,
  `position` int(11) NOT NULL DEFAULT 0,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `tests`
--

CREATE TABLE `tests` (
  `id` int(11) NOT NULL,
  `course_id` int(11) NOT NULL,
  `section_id` int(11) DEFAULT NULL,
  `lesson_id` int(11) DEFAULT NULL,
  `title` varchar(255) NOT NULL,
  `description` mediumtext DEFAULT NULL,
  `time_limit_minutes` int(11) NOT NULL DEFAULT 0,
  `pass_score` decimal(5,2) NOT NULL DEFAULT 0.00,
  `max_attempts` int(11) NOT NULL DEFAULT 1,
  `shuffle_questions` tinyint(1) NOT NULL DEFAULT 0,
  `shuffle_choices` tinyint(1) NOT NULL DEFAULT 0,
  `show_results_mode` enum('IMMEDIATE','AFTER_DUE','MANUAL') NOT NULL DEFAULT 'IMMEDIATE',
  `show_correct_answers_mode` enum('IMMEDIATE','AFTER_DUE','NEVER') NOT NULL DEFAULT 'NEVER',
  `start_at` datetime DEFAULT NULL,
  `due_at` datetime DEFAULT NULL,
  `status` enum('DRAFT','PUBLISHED','ARCHIVED') NOT NULL DEFAULT 'DRAFT',
  `created_by` int(11) NOT NULL,
  `updated_by` int(11) DEFAULT NULL,
  `published_at` datetime DEFAULT NULL,
  `results_published_at` datetime DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `test_attempts`
--

CREATE TABLE `test_attempts` (
  `id` int(11) NOT NULL,
  `test_id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `attempt_no` int(11) NOT NULL DEFAULT 1,
  `status` enum('IN_PROGRESS','SUBMITTED','AUTO_SUBMITTED','NEEDS_GRADING','GRADED') NOT NULL DEFAULT 'IN_PROGRESS',
  `started_at` datetime NOT NULL DEFAULT current_timestamp(),
  `submitted_at` datetime DEFAULT NULL,
  `duration_seconds` int(11) DEFAULT NULL,
  `score_points` decimal(10,2) DEFAULT NULL,
  `total_points` decimal(10,2) DEFAULT NULL,
  `percentage` decimal(6,2) DEFAULT NULL,
  `passed` tinyint(1) DEFAULT NULL,
  `time_limit_minutes` int(11) NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `test_audit_log`
--

CREATE TABLE `test_audit_log` (
  `id` bigint(20) NOT NULL,
  `test_id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `action` varchar(64) NOT NULL,
  `details` varchar(255) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `test_questions`
--

CREATE TABLE `test_questions` (
  `id` int(11) NOT NULL,
  `test_id` int(11) NOT NULL,
  `question_id` int(11) NOT NULL,
  `position` int(11) NOT NULL DEFAULT 1,
  `points_override` decimal(8,2) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `threads`
--

CREATE TABLE `threads` (
  `id` int(11) NOT NULL,
  `course_id` int(11) NOT NULL,
  `lesson_id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `title` varchar(255) NOT NULL,
  `content` text NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=MyISAM DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

--
-- Triggers `threads`
--
DELIMITER $$
CREATE TRIGGER `trg_thr_after_insert` AFTER INSERT ON `threads` FOR EACH ROW BEGIN
  DECLARE v_course_title VARCHAR(255);
  DECLARE v_actor        VARCHAR(255);
  DECLARE v_nid          BIGINT;

  SELECT c.title INTO v_course_title FROM courses c WHERE c.id = NEW.course_id LIMIT 1;
  SELECT u.full_name INTO v_actor FROM users u WHERE u.id = NEW.user_id LIMIT 1;

  INSERT INTO notifications(type, title, body, target_url, actor_user_id, course_id, created_at)
  VALUES(
    'thread_created',
    'Diskutim i ri',
    CONCAT(v_actor,' hapi temën "', NEW.title, '" në "', IFNULL(v_course_title,''), '".'),
    CONCAT('thread_view.php?thread_id=', NEW.id),
    NEW.user_id,
    NEW.course_id,
    NOW()
  );
  SET v_nid = LAST_INSERT_ID();
  CALL sp_fanout_admins(v_nid);
END
$$
DELIMITER ;

-- --------------------------------------------------------

--
-- Table structure for table `thread_replies`
--

CREATE TABLE `thread_replies` (
  `id` int(11) NOT NULL,
  `thread_id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `content` text NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=MyISAM DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

--
-- Triggers `thread_replies`
--
DELIMITER $$
CREATE TRIGGER `trg_tr_after_insert` AFTER INSERT ON `thread_replies` FOR EACH ROW BEGIN
  DECLARE v_course_id    INT;
  DECLARE v_course_title VARCHAR(255);
  DECLARE v_actor        VARCHAR(255);
  DECLARE v_nid          BIGINT;

  SELECT t.course_id INTO v_course_id FROM threads t WHERE t.id = NEW.thread_id LIMIT 1;
  SELECT c.title       INTO v_course_title FROM courses c WHERE c.id = v_course_id LIMIT 1;
  SELECT u.full_name   INTO v_actor FROM users u WHERE u.id = NEW.user_id LIMIT 1;

  INSERT INTO notifications(type, title, body, target_url, actor_user_id, course_id, created_at)
  VALUES(
    'reply_posted',
    'Përgjigje e re',
    CONCAT(v_actor,' shtoi një përgjigje në "', IFNULL(v_course_title,''), '".'),
    CONCAT('thread_view.php?thread_id=', NEW.thread_id),
    NEW.user_id,
    v_course_id,
    NOW()
  );
  SET v_nid = LAST_INSERT_ID();
  CALL sp_fanout_admins(v_nid);
END
$$
DELIMITER ;

-- --------------------------------------------------------

--
-- Table structure for table `users`
--

CREATE TABLE `users` (
  `id` int(11) NOT NULL,
  `full_name` varchar(100) NOT NULL,
  `birth_date` date NOT NULL,
  `role` enum('Administrator','Instruktor','Student') NOT NULL,
  `phone_number` varchar(15) NOT NULL,
  `email` varchar(100) NOT NULL,
  `password` varchar(255) NOT NULL,
  `status` enum('APROVUAR','NE SHQYRTIM','REFUZUAR') DEFAULT 'NE SHQYRTIM',
  `remember_token` varchar(64) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Triggers `users`
--
DELIMITER $$
CREATE TRIGGER `trg_user_after_insert_registered` AFTER INSERT ON `users` FOR EACH ROW BEGIN
  DECLARE v_nid BIGINT;

  INSERT INTO notifications(type, title, body, target_url, actor_user_id, course_id, created_at)
  VALUES(
    'user_registered',
    'Përdorues i ri',
    CONCAT(NEW.full_name,' (',NEW.email,') u regjistrua si ', NEW.role,'.'),
    'users.php',
    NEW.id,
    NULL,
    NOW()
  );
  SET v_nid = LAST_INSERT_ID();
  CALL sp_fanout_admins(v_nid);
END
$$
DELIMITER ;
DELIMITER $$
CREATE TRIGGER `trg_user_after_update_status` AFTER UPDATE ON `users` FOR EACH ROW BEGIN
  DECLARE v_nid BIGINT;
  IF (OLD.status <> NEW.status) THEN
    INSERT INTO notifications(type, title, body, target_url, actor_user_id, course_id, created_at)
    VALUES(
      'user_status_changed',
      'Status përdoruesi u përditësua',
      CONCAT(NEW.full_name,': ', OLD.status, ' → ', NEW.status),
      'users.php',
      NEW.id,
      NULL,
      NOW()
    );
    SET v_nid = LAST_INSERT_ID();
    CALL sp_fanout_admins(v_nid);
  END IF;
END
$$
DELIMITER ;

-- --------------------------------------------------------

--
-- Table structure for table `user_reads`
--

CREATE TABLE `user_reads` (
  `id` bigint(20) NOT NULL,
  `user_id` int(11) NOT NULL,
  `item_type` enum('LESSON','ASSIGNMENT','QUIZ') NOT NULL,
  `item_id` int(11) NOT NULL,
  `read_at` timestamp NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Indexes for dumped tables
--

--
-- Indexes for table `appointments`
--
ALTER TABLE `appointments`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_appt_course_date` (`course_id`,`appointment_date`);

--
-- Indexes for table `assignments`
--
ALTER TABLE `assignments`
  ADD PRIMARY KEY (`id`),
  ADD KEY `course_id` (`course_id`),
  ADD KEY `idx_assignments_section` (`section_id`);

--
-- Indexes for table `assignments_files`
--
ALTER TABLE `assignments_files`
  ADD PRIMARY KEY (`id`),
  ADD KEY `assignment_id` (`assignment_id`);

--
-- Indexes for table `assignments_submitted`
--
ALTER TABLE `assignments_submitted`
  ADD PRIMARY KEY (`id`),
  ADD KEY `assignment_id` (`assignment_id`),
  ADD KEY `user_id` (`user_id`);

--
-- Indexes for table `attempt_answers`
--
ALTER TABLE `attempt_answers`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_aa_attempt` (`attempt_id`),
  ADD KEY `idx_aa_question` (`question_id`),
  ADD KEY `idx_aa_attempt_question` (`attempt_id`,`question_id`),
  ADD KEY `idx_aa_option` (`option_id`);

--
-- Indexes for table `attempt_question_scores`
--
ALTER TABLE `attempt_question_scores`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_aqs_attempt_question` (`attempt_id`,`question_id`),
  ADD KEY `idx_aqs_attempt` (`attempt_id`),
  ADD KEY `idx_aqs_question` (`question_id`);

--
-- Indexes for table `courses`
--
ALTER TABLE `courses`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_courses_access_code` (`access_code`),
  ADD KEY `idx_courses_creator` (`id_creator`);

--
-- Indexes for table `course_tests`
--
ALTER TABLE `course_tests`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_course_tests_course` (`course_id`),
  ADD KEY `fk_course_tests_creator` (`created_by`),
  ADD KEY `fk_course_tests_updater` (`updated_by`);

--
-- Indexes for table `course_test_attempts`
--
ALTER TABLE `course_test_attempts`
  ADD PRIMARY KEY (`id`),
  ADD KEY `fk_course_test_attempts_test` (`test_id`),
  ADD KEY `fk_course_test_attempts_student` (`student_id`),
  ADD KEY `fk_course_test_attempts_grader` (`graded_by`);

--
-- Indexes for table `course_test_attempt_answers`
--
ALTER TABLE `course_test_attempt_answers`
  ADD PRIMARY KEY (`id`),
  ADD KEY `fk_course_test_attempt_answers_attempt` (`attempt_id`),
  ADD KEY `fk_course_test_attempt_answers_question` (`question_id`);

--
-- Indexes for table `course_test_questions`
--
ALTER TABLE `course_test_questions`
  ADD PRIMARY KEY (`id`),
  ADD KEY `fk_course_test_questions_test` (`test_id`);

--
-- Indexes for table `course_test_question_options`
--
ALTER TABLE `course_test_question_options`
  ADD PRIMARY KEY (`id`),
  ADD KEY `fk_course_test_question_options_question` (`question_id`);

--
-- Indexes for table `enroll`
--
ALTER TABLE `enroll`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `course_user` (`course_id`,`user_id`),
  ADD KEY `user_id` (`user_id`),
  ADD KEY `idx_enroll_course_user` (`course_id`,`user_id`);

--
-- Indexes for table `enroll_events`
--
ALTER TABLE `enroll_events`
  ADD PRIMARY KEY (`id`),
  ADD KEY `event_id` (`event_id`);

--
-- Indexes for table `events`
--
ALTER TABLE `events`
  ADD PRIMARY KEY (`id`),
  ADD KEY `id_creator` (`id_creator`);

--
-- Indexes for table `lessons`
--
ALTER TABLE `lessons`
  ADD PRIMARY KEY (`id`),
  ADD KEY `fk_lessons_section` (`section_id`),
  ADD KEY `idx_lessons_course_section` (`course_id`,`section_id`);

--
-- Indexes for table `lesson_files`
--
ALTER TABLE `lesson_files`
  ADD PRIMARY KEY (`id`),
  ADD KEY `lesson_id` (`lesson_id`);

--
-- Indexes for table `lesson_images`
--
ALTER TABLE `lesson_images`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_limg_lesson` (`lesson_id`),
  ADD KEY `idx_limg_lesson_pos` (`lesson_id`,`position`);

--
-- Indexes for table `messages`
--
ALTER TABLE `messages`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_msg_unread_created` (`read_status`,`created_at`);

--
-- Indexes for table `notes`
--
ALTER TABLE `notes`
  ADD PRIMARY KEY (`id`),
  ADD KEY `user_id` (`user_id`),
  ADD KEY `lesson_id` (`lesson_id`);

--
-- Indexes for table `notifications`
--
ALTER TABLE `notifications`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_notif_created` (`created_at`),
  ADD KEY `idx_notif_type` (`type`),
  ADD KEY `idx_notif_course` (`course_id`),
  ADD KEY `fk_notif_actor` (`actor_user_id`);

--
-- Indexes for table `notification_users`
--
ALTER TABLE `notification_users`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_notif_user` (`notification_id`,`user_id`),
  ADD KEY `idx_notif_user_unread` (`user_id`,`is_read`,`created_at`);

--
-- Indexes for table `payments`
--
ALTER TABLE `payments`
  ADD PRIMARY KEY (`id`),
  ADD KEY `course_id` (`course_id`),
  ADD KEY `lesson_id` (`lesson_id`),
  ADD KEY `user_id` (`user_id`),
  ADD KEY `idx_pay_status_date` (`payment_status`,`payment_date`);

--
-- Indexes for table `promoted_courses`
--
ALTER TABLE `promoted_courses`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_promoted_level` (`level`),
  ADD KEY `idx_promoted_label` (`label`);

--
-- Indexes for table `promoted_course_enrollments`
--
ALTER TABLE `promoted_course_enrollments`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_promo_email` (`promotion_id`,`email`),
  ADD UNIQUE KEY `uq_promo_user` (`promotion_id`,`user_id`),
  ADD KEY `idx_pce_promo_created` (`promotion_id`,`created_at`),
  ADD KEY `fk_pce_user` (`user_id`);

--
-- Indexes for table `question_bank`
--
ALTER TABLE `question_bank`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_qb_course` (`course_id`),
  ADD KEY `idx_qb_type` (`type`);

--
-- Indexes for table `question_options`
--
ALTER TABLE `question_options`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_qo_question` (`question_id`),
  ADD KEY `idx_qo_pos` (`position`);

--
-- Indexes for table `quizzes`
--
ALTER TABLE `quizzes`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_quizzes_course` (`course_id`),
  ADD KEY `idx_quizzes_section` (`section_id`),
  ADD KEY `idx_quizzes_open` (`open_at`),
  ADD KEY `idx_quizzes_close` (`close_at`);

--
-- Indexes for table `quiz_answers`
--
ALTER TABLE `quiz_answers`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_one_correct` (`correct_key`),
  ADD KEY `idx_qa_question` (`question_id`),
  ADD KEY `idx_qa_pos` (`position`),
  ADD KEY `idx_qa_q_pos` (`question_id`,`position`);

--
-- Indexes for table `quiz_attempts`
--
ALTER TABLE `quiz_attempts`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_one_open_attempt` (`open_quiz_key`,`open_user_key`),
  ADD KEY `idx_qatt_quiz` (`quiz_id`),
  ADD KEY `idx_qatt_user` (`user_id`),
  ADD KEY `idx_qatt_quiz_user_submitted` (`quiz_id`,`user_id`,`submitted_at`);

--
-- Indexes for table `quiz_questions`
--
ALTER TABLE `quiz_questions`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_qq_quiz` (`quiz_id`),
  ADD KEY `idx_qq_pos` (`position`);

--
-- Indexes for table `sections`
--
ALTER TABLE `sections`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_sections_course_area_position` (`course_id`,`area`,`position`),
  ADD KEY `idx_sections_course` (`course_id`);

--
-- Indexes for table `section_items`
--
ALTER TABLE `section_items`
  ADD PRIMARY KEY (`id`),
  ADD KEY `course_id` (`course_id`,`section_id`),
  ADD KEY `item_type` (`item_type`,`item_ref_id`),
  ADD KEY `position` (`position`),
  ADD KEY `idx_si_course_area_section` (`course_id`,`area`,`section_id`),
  ADD KEY `idx_si_area_type_ref` (`area`,`item_type`,`item_ref_id`);

--
-- Indexes for table `tests`
--
ALTER TABLE `tests`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_tests_course` (`course_id`),
  ADD KEY `idx_tests_section` (`section_id`),
  ADD KEY `idx_tests_lesson` (`lesson_id`),
  ADD KEY `idx_tests_status` (`status`),
  ADD KEY `idx_tests_start` (`start_at`),
  ADD KEY `idx_tests_due` (`due_at`);

--
-- Indexes for table `test_attempts`
--
ALTER TABLE `test_attempts`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_ta_test` (`test_id`),
  ADD KEY `idx_ta_user` (`user_id`),
  ADD KEY `idx_ta_submitted` (`submitted_at`),
  ADD KEY `idx_ta_test_user` (`test_id`,`user_id`);

--
-- Indexes for table `test_audit_log`
--
ALTER TABLE `test_audit_log`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_audit_test` (`test_id`),
  ADD KEY `idx_audit_user` (`user_id`);

--
-- Indexes for table `test_questions`
--
ALTER TABLE `test_questions`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_test_question` (`test_id`,`question_id`),
  ADD KEY `idx_tq_test` (`test_id`),
  ADD KEY `idx_tq_pos` (`position`),
  ADD KEY `fk_tq_question` (`question_id`);

--
-- Indexes for table `threads`
--
ALTER TABLE `threads`
  ADD PRIMARY KEY (`id`),
  ADD KEY `lesson_id` (`lesson_id`),
  ADD KEY `user_id` (`user_id`),
  ADD KEY `idx_thr_course` (`course_id`);

--
-- Indexes for table `thread_replies`
--
ALTER TABLE `thread_replies`
  ADD PRIMARY KEY (`id`),
  ADD KEY `thread_id` (`thread_id`),
  ADD KEY `user_id` (`user_id`);

--
-- Indexes for table `users`
--
ALTER TABLE `users`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `email` (`email`);

--
-- Indexes for table `user_reads`
--
ALTER TABLE `user_reads`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_user_item` (`user_id`,`item_type`,`item_id`),
  ADD KEY `idx_user_type` (`user_id`,`item_type`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `appointments`
--
ALTER TABLE `appointments`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `assignments`
--
ALTER TABLE `assignments`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `assignments_files`
--
ALTER TABLE `assignments_files`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `assignments_submitted`
--
ALTER TABLE `assignments_submitted`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `attempt_answers`
--
ALTER TABLE `attempt_answers`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `attempt_question_scores`
--
ALTER TABLE `attempt_question_scores`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `courses`
--
ALTER TABLE `courses`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `course_tests`
--
ALTER TABLE `course_tests`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `course_test_attempts`
--
ALTER TABLE `course_test_attempts`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `course_test_attempt_answers`
--
ALTER TABLE `course_test_attempt_answers`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `course_test_questions`
--
ALTER TABLE `course_test_questions`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `course_test_question_options`
--
ALTER TABLE `course_test_question_options`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `enroll`
--
ALTER TABLE `enroll`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `enroll_events`
--
ALTER TABLE `enroll_events`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `events`
--
ALTER TABLE `events`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `lessons`
--
ALTER TABLE `lessons`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `lesson_files`
--
ALTER TABLE `lesson_files`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `lesson_images`
--
ALTER TABLE `lesson_images`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `messages`
--
ALTER TABLE `messages`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `notes`
--
ALTER TABLE `notes`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `notifications`
--
ALTER TABLE `notifications`
  MODIFY `id` bigint(20) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `notification_users`
--
ALTER TABLE `notification_users`
  MODIFY `id` bigint(20) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `payments`
--
ALTER TABLE `payments`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `promoted_courses`
--
ALTER TABLE `promoted_courses`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `promoted_course_enrollments`
--
ALTER TABLE `promoted_course_enrollments`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `question_bank`
--
ALTER TABLE `question_bank`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `question_options`
--
ALTER TABLE `question_options`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `quizzes`
--
ALTER TABLE `quizzes`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `quiz_answers`
--
ALTER TABLE `quiz_answers`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `quiz_attempts`
--
ALTER TABLE `quiz_attempts`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `quiz_questions`
--
ALTER TABLE `quiz_questions`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `sections`
--
ALTER TABLE `sections`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `section_items`
--
ALTER TABLE `section_items`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `tests`
--
ALTER TABLE `tests`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `test_attempts`
--
ALTER TABLE `test_attempts`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `test_audit_log`
--
ALTER TABLE `test_audit_log`
  MODIFY `id` bigint(20) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `test_questions`
--
ALTER TABLE `test_questions`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `threads`
--
ALTER TABLE `threads`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `thread_replies`
--
ALTER TABLE `thread_replies`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `users`
--
ALTER TABLE `users`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `user_reads`
--
ALTER TABLE `user_reads`
  MODIFY `id` bigint(20) NOT NULL AUTO_INCREMENT;

--
-- Constraints for dumped tables
--

--
-- Constraints for table `assignments`
--
ALTER TABLE `assignments`
  ADD CONSTRAINT `fk_assignments_section` FOREIGN KEY (`section_id`) REFERENCES `sections` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `attempt_answers`
--
ALTER TABLE `attempt_answers`
  ADD CONSTRAINT `fk_aa_attempt` FOREIGN KEY (`attempt_id`) REFERENCES `test_attempts` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `attempt_question_scores`
--
ALTER TABLE `attempt_question_scores`
  ADD CONSTRAINT `fk_aqs_attempt` FOREIGN KEY (`attempt_id`) REFERENCES `test_attempts` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `lessons`
--
ALTER TABLE `lessons`
  ADD CONSTRAINT `fk_lessons_section` FOREIGN KEY (`section_id`) REFERENCES `sections` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `notifications`
--
ALTER TABLE `notifications`
  ADD CONSTRAINT `fk_notif_actor` FOREIGN KEY (`actor_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_notif_course` FOREIGN KEY (`course_id`) REFERENCES `courses` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `notification_users`
--
ALTER TABLE `notification_users`
  ADD CONSTRAINT `fk_notif_users_notification` FOREIGN KEY (`notification_id`) REFERENCES `notifications` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_notif_users_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `promoted_course_enrollments`
--
ALTER TABLE `promoted_course_enrollments`
  ADD CONSTRAINT `fk_pce_promo` FOREIGN KEY (`promotion_id`) REFERENCES `promoted_courses` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_pce_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE;

--
-- Constraints for table `question_bank`
--
ALTER TABLE `question_bank`
  ADD CONSTRAINT `fk_qb_course` FOREIGN KEY (`course_id`) REFERENCES `courses` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `question_options`
--
ALTER TABLE `question_options`
  ADD CONSTRAINT `fk_qo_question` FOREIGN KEY (`question_id`) REFERENCES `question_bank` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `quizzes`
--
ALTER TABLE `quizzes`
  ADD CONSTRAINT `fk_quizzes_course` FOREIGN KEY (`course_id`) REFERENCES `courses` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_quizzes_section` FOREIGN KEY (`section_id`) REFERENCES `sections` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `quiz_answers`
--
ALTER TABLE `quiz_answers`
  ADD CONSTRAINT `fk_qa_question` FOREIGN KEY (`question_id`) REFERENCES `quiz_questions` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `quiz_attempts`
--
ALTER TABLE `quiz_attempts`
  ADD CONSTRAINT `fk_qatt_quiz` FOREIGN KEY (`quiz_id`) REFERENCES `quizzes` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_qatt_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `quiz_questions`
--
ALTER TABLE `quiz_questions`
  ADD CONSTRAINT `fk_qq_quiz` FOREIGN KEY (`quiz_id`) REFERENCES `quizzes` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `sections`
--
ALTER TABLE `sections`
  ADD CONSTRAINT `fk_sections_course` FOREIGN KEY (`course_id`) REFERENCES `courses` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `tests`
--
ALTER TABLE `tests`
  ADD CONSTRAINT `fk_tests_course` FOREIGN KEY (`course_id`) REFERENCES `courses` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_tests_lesson` FOREIGN KEY (`lesson_id`) REFERENCES `lessons` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_tests_section` FOREIGN KEY (`section_id`) REFERENCES `sections` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `test_attempts`
--
ALTER TABLE `test_attempts`
  ADD CONSTRAINT `fk_ta_test` FOREIGN KEY (`test_id`) REFERENCES `tests` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `test_questions`
--
ALTER TABLE `test_questions`
  ADD CONSTRAINT `fk_tq_question` FOREIGN KEY (`question_id`) REFERENCES `question_bank` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_tq_test` FOREIGN KEY (`test_id`) REFERENCES `tests` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `user_reads`
--
ALTER TABLE `user_reads`
  ADD CONSTRAINT `fk_user_reads_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
