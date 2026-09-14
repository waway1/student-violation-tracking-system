-- ============================================================
--  QR Shield (VTS) — import file for InfinityFree
--
--  WHAT THIS IS
--  A full dump of the local `svts` database: all 19 tables and the
--  seed/reference rows (violation types, colleges, courses, the user
--  accounts). Generated from the working XAMPP database, so what
--  imports is what the app expects.
--
--  WHY IT IS NOT A PLAIN mysqldump
--  Three things in a stock dump are refused by InfinityFree:
--
--    1. THE SEVEN VIEWS ARE GONE. `beed_students`, `bsa_students`,
--       `bsba_students`, `bscrim_students`, `bscs_students`,
--       `bsed_students` and `bsit_students` are NOT in this file.
--       InfinityFree does not grant CREATE VIEW to a free account at
--       all, so importing them fails outright:
--
--           #1142 - CREATE VIEW command denied to user 'if0_42480644'
--
--       Stripping the DEFINER clause is not enough — the privilege
--       itself is withheld, so no form of the statement can succeed.
--       Dropping them is safe: they were added by
--       database/upgrade_2026-07.sql and NOTHING in the application
--       queries them. A grep of every .php file finds zero references,
--       and no code builds the name dynamically either. They were each
--       just "students on one course, with their violation count" —
--       a query the app does inline where it needs it.
--
--       If you ever do want them back, they are still in
--       database/upgrade_2026-07.sql, and they will still be refused
--       on InfinityFree. They work on XAMPP, which is where they came
--       from and where the local database still has them.
--
--    2. DEFINER=`root`@`localhost`. No such user exists here. Removed
--       along with the views that carried it.
--
--    3. CREATE DATABASE / USE. The database already exists and is
--       named for you (if0_42480644_svts); the dump must not try to
--       make its own. Generated without --databases, so there is none.
--
--  RE-RUNNING THIS IS SAFE. Every table is preceded by DROP TABLE IF
--  EXISTS, so importing a second time over a half-finished import
--  replaces cleanly rather than erroring on duplicates.
--
--  HOW TO IMPORT
--    InfinityFree control panel > phpMyAdmin
--    > select  if0_42480644_svts  in the left sidebar   (IMPORTANT:
--      select the database first — importing with no database chosen
--      is the usual cause of "No database selected")
--    > Import > Choose File > this file > Go
--
--  Foreign key checks are off for the duration, so table order does
--  not matter, and restored at the end.
-- ============================================================


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;
/*!40103 SET @OLD_TIME_ZONE=@@TIME_ZONE */;
/*!40103 SET TIME_ZONE='+00:00' */;
/*!40014 SET @OLD_UNIQUE_CHECKS=@@UNIQUE_CHECKS, UNIQUE_CHECKS=0 */;
/*!40014 SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0 */;
/*!40101 SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='NO_AUTO_VALUE_ON_ZERO' */;
/*!40111 SET @OLD_SQL_NOTES=@@SQL_NOTES, SQL_NOTES=0 */;
DROP TABLE IF EXISTS `admins`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `admins` (
  `id` int(10) unsigned NOT NULL,
  `staff_id` varchar(10) DEFAULT NULL,
  `firstname` varchar(60) DEFAULT NULL,
  `middlename` varchar(60) DEFAULT NULL,
  `lastname` varchar(60) DEFAULT NULL,
  `suffix` varchar(15) DEFAULT NULL,
  `fullname` varchar(180) NOT NULL,
  `username` varchar(60) NOT NULL,
  `email` varchar(150) NOT NULL,
  `password` varchar(255) NOT NULL,
  `contact_number` varchar(20) DEFAULT NULL,
  `gender` enum('Male','Female','Other') DEFAULT NULL,
  `birthday` date DEFAULT NULL,
  `status` enum('Active','Inactive') NOT NULL DEFAULT 'Active',
  `email_verified` tinyint(1) NOT NULL DEFAULT 0,
  `verify_code` varchar(6) DEFAULT NULL,
  `verify_expires` datetime DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `username` (`username`),
  UNIQUE KEY `email` (`email`),
  CONSTRAINT `fk_admins_registry` FOREIGN KEY (`id`) REFERENCES `people_registry` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

LOCK TABLES `admins` WRITE;
/*!40000 ALTER TABLE `admins` DISABLE KEYS */;
INSERT INTO `admins` VALUES (2,NULL,'System',NULL,'Admin',NULL,'Mrs. Alma Gerero Viray','admin','goldenweststudentaffairs@gmail.com','$2y$10$klwl.b8TSRdyFz/RcezFhuARZYv5s/s4U0R7IKQoer35VjBEkrLAi',NULL,'Male',NULL,'Active',1,'000007','2026-07-24 11:06:42','2026-07-22 16:21:33');
/*!40000 ALTER TABLE `admins` ENABLE KEYS */;
UNLOCK TABLES;
DROP TABLE IF EXISTS `audit_logs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `audit_logs` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int(10) unsigned DEFAULT NULL,
  `user_name` varchar(180) DEFAULT NULL,
  `role` varchar(20) DEFAULT NULL,
  `action` varchar(60) NOT NULL,
  `target` varchar(60) DEFAULT NULL,
  `target_id` varchar(60) DEFAULT NULL,
  `details` varchar(255) DEFAULT NULL,
  `ip` varchar(45) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_audit_user` (`user_id`,`created_at`),
  KEY `idx_audit_action` (`action`,`created_at`)
) ENGINE=InnoDB AUTO_INCREMENT=1090 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

LOCK TABLES `audit_logs` WRITE;
/*!40000 ALTER TABLE `audit_logs` DISABLE KEYS */;
INSERT INTO `audit_logs` VALUES (1034,100,'Mr.Denzel Valdez','Admin','Clear Audit Log','audit_logs',NULL,'Deleted all 242 audit entries','::1','2026-09-14 07:16:35'),(1035,195,'Rogie Tagura Lagota','Student','Register','users','195','New student self-registered','::1','2026-09-14 07:16:40'),(1036,196,'Pogi Ordonia Jr.','Student','Register','users','196','New student self-registered','::1','2026-09-14 07:17:26'),(1037,100,'Mr.Denzel Valdez','Admin','Delete Violation','violations','173',NULL,'::1','2026-09-14 07:17:42'),(1038,100,'Mr.Denzel Valdez','Admin','Delete Violation','violations','172',NULL,'::1','2026-09-14 07:17:45'),(1039,197,'Roberto Salmorin Llorca Jr.','Student','Register','users','197','New student self-registered','::1','2026-09-14 07:18:37'),(1040,100,'Mr.Denzel Valdez','Admin','Scanner Sign-Off','users','33','Garce Jasper F (1242500299) was signed off the scanner by user #100','::1','2026-09-14 07:19:58'),(1041,100,'Mr.Denzel Valdez','Admin','Scanner Sign-Off','users','103','James Ungria Cabungan (1234567890) was signed off the scanner by user #100','::1','2026-09-14 07:20:01'),(1042,197,'Roberto Salmorin Llorca Jr.','Student','Login','users','197','Student passwordless lookup login (new device confirmed)','::1','2026-09-14 07:20:36'),(1043,3,'Osa Officer','OSA','Login','users','3','Signed in via staff portal (new device confirmed)','::1','2026-09-14 07:20:40'),(1044,198,'Rafael Santaigo Ordonia','Student','Register','users','198','New student self-registered','::1','2026-09-14 07:21:09'),(1045,197,'Roberto Salmorin Llorca Jr.','Student','Login','users','197','Student passwordless lookup login','::1','2026-09-14 07:21:21'),(1046,3,'Mrs.Alma Gerero Viray','OSA','Delete Violation','violations','161',NULL,'::1','2026-09-14 07:23:49'),(1047,3,'Mrs.Alma Gerero Viray','OSA','Delete Violation','violations','169',NULL,'::1','2026-09-14 07:23:53'),(1048,3,'Mrs.Alma Gerero Viray','OSA','Delete Violation','violations','168',NULL,'::1','2026-09-14 07:23:57'),(1049,3,'Mrs.Alma Gerero Viray','OSA','Delete Violation','violations','167',NULL,'::1','2026-09-14 07:23:59'),(1050,3,'Mrs.Alma Gerero Viray','OSA','Delete Violation','violations','166',NULL,'::1','2026-09-14 07:24:02'),(1051,3,'Mrs.Alma Gerero Viray','OSA','Delete Violation','violations','165',NULL,'::1','2026-09-14 07:24:04'),(1052,3,'Mrs.Alma Gerero Viray','OSA','Delete Violation','violations','163',NULL,'::1','2026-09-14 07:24:08'),(1053,3,'Mrs.Alma Gerero Viray','OSA','Delete Violation','violations','164',NULL,'::1','2026-09-14 07:24:11'),(1054,3,'Mrs.Alma Gerero Viray','OSA','Delete Violation','violations','162',NULL,'::1','2026-09-14 07:24:15'),(1055,100,'Mr.Denzel Valdez','Admin','Login','users','100','Signed in via staff portal (new device confirmed)','::1','2026-09-14 07:30:12'),(1056,100,'Mr.Denzel Valdez','Admin','Edit Student','users','196','Updated student account','::1','2026-09-14 07:30:26'),(1057,197,'Roberto Salmorin Llorca Jr.','Student','Login','users','197','Student passwordless lookup login','::1','2026-09-14 07:30:37'),(1058,196,'Rafael Ordonia Jr.','Student','Login','users','196','Student passwordless lookup login','::1','2026-09-14 07:31:05'),(1059,197,'Roberto Salmorin Llorca Jr.','Student','Login','users','197','Student passwordless lookup login','::1','2026-09-14 07:31:41'),(1060,3,'Smoke OSA','OSA','Delete Violation','violations','174',NULL,'127.0.0.1','2026-09-14 07:32:02'),(1061,100,'Smoke Admin','Admin','Delete Violation','violations','176','Admin deleted a Major offense with no conference on record','127.0.0.1','2026-09-14 07:32:02'),(1062,3,'Smoke OSA','OSA','Delete Violation','violations','175',NULL,'127.0.0.1','2026-09-14 07:32:17'),(1063,100,'Mr.Denzel Valdez','Admin','Delete Violation','violations','171','Admin deleted a Major offense with no conference on record','::1','2026-09-14 07:32:22'),(1064,100,'Mr.Denzel Valdez','Admin','Delete Violation','violations','170','Admin deleted a Major offense with no conference on record','::1','2026-09-14 07:32:25'),(1065,100,'Mr.Denzel Valdez','Admin','Login','users','100','Signed in via staff portal (new device confirmed)','::1','2026-09-14 07:34:32'),(1066,18,'Veronica Cabungan Cerdan','Student','Login','users','18','Student passwordless lookup login (new device confirmed)','::1','2026-09-14 07:34:51'),(1067,195,'Rogie Tagura Lagota','Student','Login','users','195','Student passwordless lookup login (new device confirmed)','::1','2026-09-14 07:35:46'),(1068,100,'Mr.Denzel Valdez','Admin','Delete Violation','violations','177',NULL,'::1','2026-09-14 07:35:47'),(1069,100,'Mr.Denzel Valdez','Admin','Delete Violation','violations','178',NULL,'::1','2026-09-14 07:35:51'),(1070,100,'Mr.Denzel Valdez','Admin','Login','users','100','Signed in via staff portal (new device confirmed)','::1','2026-09-14 07:41:23'),(1071,100,'Smoke Admin','Admin','Proof Rejected','violations','179','Proof review: Pending -> Rejected','127.0.0.1','2026-09-14 07:44:18'),(1072,100,'Smoke Admin','Admin','Proof Pending','violations','179','Proof review: Rejected -> Pending','127.0.0.1','2026-09-14 07:44:25'),(1073,100,'Smoke Admin','Admin','Proof Approved','violations','179','Proof review: Pending -> Approved','127.0.0.1','2026-09-14 07:44:26'),(1074,100,'Smoke Admin','Admin','Delete Violation','violations','179',NULL,'127.0.0.1','2026-09-14 07:44:32'),(1075,195,'Rogie Tagura Lagota','Student','Login','users','195','Student passwordless lookup login','::1','2026-09-14 07:51:05'),(1076,195,'Rogie Tagura Lagota','Student','Login','users','195','Student passwordless lookup login','::1','2026-09-14 07:51:33'),(1077,100,'Mr.Denzel Valdez','Admin','Login','users','100','Signed in via staff portal','::1','2026-09-14 07:51:59'),(1078,195,'Rogie Tagura Lagota','Student','Login','users','195','Student passwordless lookup login (new device confirmed)','::1','2026-09-14 07:53:13'),(1079,100,'Mr.Denzel Valdez','Admin','Proof Approved','violations','180','Proof review: Pending -> Approved','::1','2026-09-14 07:54:10'),(1080,195,'Rogie Tagura Lagota','Student','Login','users','195','Student passwordless lookup login','::1','2026-09-14 07:55:18'),(1081,195,'Rogie Tagura Lagota','Student','Login','users','195','Student passwordless lookup login','::1','2026-09-14 07:55:53'),(1082,196,'Rafael Ordonia Jr.','Student','Login','users','196','Student passwordless lookup login','::1','2026-09-14 07:56:41'),(1083,100,'Mr.Denzel Valdez','Admin','Delete Violation','violations','180',NULL,'::1','2026-09-14 07:57:37'),(1084,3,'Mrs.Alma Gerero Viray','OSA','Login','users','3','Signed in via staff portal (new device confirmed)','::1','2026-09-14 07:58:39'),(1085,195,'Rogie Tagura Lagota','Student','Login','users','195','Student passwordless lookup login (new device confirmed)','::1','2026-09-14 07:58:42'),(1086,3,'Mrs.Alma Gerero Viray','OSA','Proof Approved','violations','181','Proof review: Pending -> Approved','::1','2026-09-14 07:59:20'),(1087,3,'Mrs.Alma Gerero Viray','OSA','Proof Rejected','violations','181','Proof review: Approved -> Rejected','::1','2026-09-14 07:59:48'),(1088,3,'Mrs.Alma Gerero Viray','OSA','Delete Violation','violations','181',NULL,'::1','2026-09-14 07:59:55'),(1089,100,'Mr.Denzel Valdez','Admin','Login','users','100','Signed in via staff portal (new device confirmed)','::1','2026-09-14 09:32:41');
/*!40000 ALTER TABLE `audit_logs` ENABLE KEYS */;
UNLOCK TABLES;
DROP TABLE IF EXISTS `colleges`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `colleges` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `college_name` varchar(150) NOT NULL,
  `short_name` varchar(30) NOT NULL,
  `osa_email` varchar(190) DEFAULT NULL,
  `osa_name` varchar(150) DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=5 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

LOCK TABLES `colleges` WRITE;
/*!40000 ALTER TABLE `colleges` DISABLE KEYS */;
INSERT INTO `colleges` VALUES (1,'Department of Information Technology','CITE',NULL,NULL),(2,'Department of Business Administration','BSBA',NULL,NULL),(3,'Department of Education','BSED',NULL,NULL),(4,'Department of Criminology','BSCRIM',NULL,NULL);
/*!40000 ALTER TABLE `colleges` ENABLE KEYS */;
UNLOCK TABLES;
DROP TABLE IF EXISTS `courses`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `courses` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `college_id` int(10) unsigned NOT NULL,
  `course_name` varchar(150) NOT NULL,
  `short_name` varchar(30) NOT NULL,
  PRIMARY KEY (`id`),
  KEY `fk_course_college` (`college_id`),
  CONSTRAINT `fk_course_college` FOREIGN KEY (`college_id`) REFERENCES `colleges` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=8 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

LOCK TABLES `courses` WRITE;
/*!40000 ALTER TABLE `courses` DISABLE KEYS */;
INSERT INTO `courses` VALUES (1,1,'BS Information Technology','BSIT'),(2,1,'BS Computer Science','BSCS'),(3,2,'BS Business Administration','BSBA'),(4,2,'BS Accountancy','BSA'),(5,3,'Bachelor of Elementary Education','BEED'),(6,3,'Bachelor of Secondary Education','BSED'),(7,4,'BS Criminology','BSCRIM');
/*!40000 ALTER TABLE `courses` ENABLE KEYS */;
UNLOCK TABLES;
DROP TABLE IF EXISTS `guards`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `guards` (
  `id` int(10) unsigned NOT NULL,
  `staff_id` varchar(10) DEFAULT NULL,
  `firstname` varchar(60) DEFAULT NULL,
  `middlename` varchar(60) DEFAULT NULL,
  `lastname` varchar(60) DEFAULT NULL,
  `suffix` varchar(15) DEFAULT NULL,
  `fullname` varchar(180) NOT NULL,
  `username` varchar(60) NOT NULL,
  `email` varchar(150) NOT NULL,
  `password` varchar(255) NOT NULL,
  `contact_number` varchar(20) DEFAULT NULL,
  `gender` enum('Male','Female','Other') DEFAULT NULL,
  `birthday` date DEFAULT NULL,
  `status` enum('Active','Inactive') NOT NULL DEFAULT 'Active',
  `email_verified` tinyint(1) NOT NULL DEFAULT 0,
  `verify_code` varchar(6) DEFAULT NULL,
  `verify_expires` datetime DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `username` (`username`),
  UNIQUE KEY `email` (`email`),
  CONSTRAINT `fk_guards_registry` FOREIGN KEY (`id`) REFERENCES `people_registry` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

LOCK TABLES `guards` WRITE;
/*!40000 ALTER TABLE `guards` DISABLE KEYS */;
INSERT INTO `guards` VALUES (5,'GUARD-01','James',NULL,'Marshall',NULL,'James Marshall','james','james@gwc.edu.ph','$2y$10$klwl.b8TSRdyFz/RcezFhuARZYv5s/s4U0R7IKQoer35VjBEkrLAi',NULL,'Male',NULL,'Active',1,NULL,NULL,'2026-07-22 16:21:33'),(11,'SCAN-SYS','Scanner',NULL,'App',NULL,'Scanner App','scanner.app','scanner.app@gwc.edu.ph','$2y$10$klwl.b8TSRdyFz/RcezFhuARZYv5s/s4U0R7IKQoer35VjBEkrLAi',NULL,NULL,NULL,'Active',1,NULL,NULL,'2026-07-22 16:21:33'),(13,NULL,'Head',NULL,'Marshal',NULL,'Head Marshal','headmarshal','headmarshal@gwc.edu.ph','$2y$10$klwl.b8TSRdyFz/RcezFhuARZYv5s/s4U0R7IKQoer35VjBEkrLAi',NULL,'Male',NULL,'Active',1,NULL,NULL,'2026-07-22 16:21:34');
/*!40000 ALTER TABLE `guards` ENABLE KEYS */;
UNLOCK TABLES;
DROP TABLE IF EXISTS `import_logs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `import_logs` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `filename` varchar(255) DEFAULT NULL,
  `imported_by` int(10) unsigned DEFAULT NULL,
  `scanner_name` varchar(150) DEFAULT NULL,
  `source` varchar(10) NOT NULL DEFAULT 'offline',
  `imported_count` int(10) unsigned NOT NULL DEFAULT 0,
  `skipped_count` int(10) unsigned NOT NULL DEFAULT 0,
  `ok` tinyint(1) NOT NULL DEFAULT 1,
  `error_message` varchar(255) DEFAULT NULL,
  `details` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=38 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

LOCK TABLES `import_logs` WRITE;
/*!40000 ALTER TABLE `import_logs` DISABLE KEYS */;
INSERT INTO `import_logs` VALUES (36,NULL,NULL,'Garce Jasper F','online',3,0,1,'','✓ Garce Jasper F (1242500299) — Eating Inside the Computer Laboratory (Violation #1).','2026-09-14 07:35:13','2026-09-14 07:59:11'),(37,NULL,NULL,'James Ungria Cabungan','online',1,0,1,'','✓ James Ungria Cabungan (1234567890) — Eating Inside the Computer Laboratory (Violation #1).','2026-09-14 07:35:42','2026-09-14 07:35:42');
/*!40000 ALTER TABLE `import_logs` ENABLE KEYS */;
UNLOCK TABLES;
DROP TABLE IF EXISTS `login_attempts`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `login_attempts` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `username` varchar(100) DEFAULT NULL,
  `ip` varchar(45) NOT NULL,
  `success` tinyint(1) NOT NULL DEFAULT 0,
  `attempted_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=1079 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

LOCK TABLES `login_attempts` WRITE;
/*!40000 ALTER TABLE `login_attempts` DISABLE KEYS */;
INSERT INTO `login_attempts` VALUES (932,'admain','::1',1,'2026-09-13 22:45:07'),(933,'otp:goldenweststudentaffairs@gmail.com','::1',1,'2026-09-13 22:45:21'),(934,'1242500299','::1',1,'2026-09-13 22:50:25'),(935,'otp:jaspergarce69@gmail.com','::1',1,'2026-09-13 22:50:50'),(936,'headmarshal','::1',1,'2026-09-13 22:51:18'),(937,'admain','::1',1,'2026-09-13 22:53:40'),(940,'otpsend:goldenweststudentaffairs@gmail.com','::1',1,'2026-09-13 22:54:41'),(941,'otp:goldenweststudentaffairs@gmail.com','::1',1,'2026-09-13 22:54:59'),(942,'admain','::1',1,'2026-09-13 22:55:18'),(943,'headmarshal','::1',1,'2026-09-13 22:58:27'),(944,'otp:jasperaccoun02@gmail.com','::1',1,'2026-09-13 22:58:47'),(945,'1242500299','::1',1,'2026-09-13 23:02:09'),(946,'otp:jaspergarce69@gmail.com','::1',1,'2026-09-13 23:02:38'),(947,'1242500299','::1',1,'2026-09-13 23:02:56'),(948,'1242500299','::1',1,'2026-09-13 23:03:04'),(949,'1242500299','::1',1,'2026-09-13 23:03:41'),(950,'1242500299','::1',1,'2026-09-13 23:04:14'),(951,'admain','::1',1,'2026-09-13 23:05:27'),(952,'otp:goldenweststudentaffairs@gmail.com','::1',1,'2026-09-13 23:05:54'),(953,'osa','::1',1,'2026-09-13 23:06:39'),(954,'otp:jasperfgarce@gmail.com','::1',1,'2026-09-13 23:07:03'),(955,'admain','::1',1,'2026-09-13 23:07:34'),(956,'otp:goldenweststudentaffairs@gmail.com','::1',1,'2026-09-13 23:07:54'),(957,'admain','::1',1,'2026-09-13 23:08:08'),(958,'1242500299','::1',1,'2026-09-13 23:15:04'),(959,'otp:jaspergarce69@gmail.com','::1',1,'2026-09-13 23:15:29'),(960,'1242500299','::1',1,'2026-09-13 23:22:10'),(961,'1242500299','::1',1,'2026-09-13 23:22:38'),(962,'1242500299','::1',1,'2026-09-13 23:23:10'),(963,'1242500299','::1',1,'2026-09-13 23:25:17'),(964,'1242500299','::1',1,'2026-09-13 23:30:35'),(965,'1242500299','::1',1,'2026-09-13 23:32:21'),(968,'admain','::1',1,'2026-09-13 23:38:31'),(969,'admain','::1',1,'2026-09-13 23:40:49'),(970,'otp:goldenweststudentaffairs@gmail.com','::1',1,'2026-09-13 23:41:18'),(971,'1242500299','::1',1,'2026-09-13 23:44:19'),(972,'otp:jaspergarce69@gmail.com','::1',1,'2026-09-13 23:44:48'),(975,'1242500299','::1',1,'2026-09-13 23:46:16'),(976,'otp:jaspergarce69@gmail.com','::1',1,'2026-09-13 23:46:36'),(977,'1242500299','::1',1,'2026-09-13 23:48:19'),(978,'admain','::1',1,'2026-09-13 23:49:01'),(979,'otp:goldenweststudentaffairs@gmail.com','::1',1,'2026-09-13 23:49:21'),(980,'1242500299','::1',1,'2026-09-13 23:50:01'),(981,'otp:jaspergarce69@gmail.com','::1',1,'2026-09-13 23:50:22'),(982,'1242500299','::1',1,'2026-09-13 23:52:27'),(983,'otp:jaspergarce69@gmail.com','::1',1,'2026-09-13 23:52:47'),(984,'admain','::1',1,'2026-09-13 23:53:32'),(985,'otp:goldenweststudentaffairs@gmail.com','::1',1,'2026-09-13 23:53:50'),(986,'1242500299','::1',1,'2026-09-13 23:54:52'),(987,'otp:jaspergarce69@gmail.com','::1',1,'2026-09-13 23:55:34'),(989,'nosuchuser','::1',0,'2026-09-13 23:59:51'),(992,'9999999999','::1',0,'2026-09-14 00:00:06'),(994,'1242500299','::1',1,'2026-09-14 04:26:05'),(995,'1234567890','::1',1,'2026-09-14 04:29:41'),(996,'otp:cabunganjames77@gmail.com','::1',1,'2026-09-14 04:30:19'),(997,'admain','::1',1,'2026-09-14 04:30:48'),(998,'1234567890','::1',1,'2026-09-14 04:30:59'),(999,'otp:goldenweststudentaffairs@gmail.com','::1',1,'2026-09-14 04:31:09'),(1000,'otp:cabunganjames77@gmail.com','::1',1,'2026-09-14 04:31:37'),(1002,'admain','::1',1,'2026-09-14 04:35:05'),(1004,'otp:goldenweststudentaffairs@gmail.com','::1',1,'2026-09-14 04:35:38'),(1005,'admain','::1',1,'2026-09-14 04:42:09'),(1006,'otpsend:julyklin382@gmail.com','::1',1,'2026-09-14 04:42:45'),(1007,'1242501400','::1',1,'2026-09-14 04:46:46'),(1008,'otp:julyklin382@gmail.com','::1',1,'2026-09-14 04:47:26'),(1009,'1242501400','::1',1,'2026-09-14 04:48:07'),(1010,'1242501400','::1',1,'2026-09-14 04:48:26'),(1011,'1242500299','::1',1,'2026-09-14 04:48:53'),(1012,'1242501400','::1',1,'2026-09-14 04:49:37'),(1013,'otp:julyklin382@gmail.com','::1',1,'2026-09-14 04:50:18'),(1014,'otpsend:veronicacerdan069@gmail.com','::1',1,'2026-09-14 05:00:50'),(1015,'osa','::1',1,'2026-09-14 05:01:11'),(1016,'osa','::1',1,'2026-09-14 05:01:57'),(1017,'1242500410','::1',1,'2026-09-14 05:06:51'),(1018,'otp:veronicacerdan069@gmail.com','::1',1,'2026-09-14 05:08:25'),(1019,'1242500410','::1',1,'2026-09-14 05:11:17'),(1020,'admain','::1',1,'2026-09-14 05:11:20'),(1021,'admain','::1',1,'2026-09-14 05:22:58'),(1022,'otp:goldenweststudentaffairs@gmail.com','::1',1,'2026-09-14 05:23:22'),(1023,'1242500299','::1',1,'2026-09-14 05:24:35'),(1024,'1242500299','::1',1,'2026-09-14 06:27:42'),(1025,'otp:jaspergarce69@gmail.com','::1',1,'2026-09-14 06:28:05'),(1026,'admain','::1',1,'2026-09-14 06:30:59'),(1027,'otp:goldenweststudentaffairs@gmail.com','::1',1,'2026-09-14 06:31:25'),(1028,'admain','::1',1,'2026-09-14 07:10:06'),(1029,'otp:goldenweststudentaffairs@gmail.com','::1',1,'2026-09-14 07:10:31'),(1030,'admain','::1',1,'2026-09-14 07:15:09'),(1031,'1042500917','::1',1,'2026-09-14 07:19:41'),(1032,'osa','::1',1,'2026-09-14 07:20:14'),(1033,'otp:llorcamalakas@gmail.com','::1',1,'2026-09-14 07:20:36'),(1034,'otp:jasperfgarce@gmail.com','::1',1,'2026-09-14 07:20:40'),(1036,'1042500917','::1',1,'2026-09-14 07:21:21'),(1038,'admain','::1',1,'2026-09-14 07:22:10'),(1040,'admain','::1',1,'2026-09-14 07:24:32'),(1045,'otpsend:llorcamalakas@gmail.com','::1',1,'2026-09-14 07:28:42'),(1046,'admain','::1',1,'2026-09-14 07:29:41'),(1048,'otp:goldenweststudentaffairs@gmail.com','::1',1,'2026-09-14 07:30:12'),(1049,'1242500410','::1',1,'2026-09-14 07:30:35'),(1050,'1042500917','::1',1,'2026-09-14 07:30:37'),(1051,'1242500547','::1',1,'2026-09-14 07:31:05'),(1052,'1042500917','::1',1,'2026-09-14 07:31:41'),(1053,'admain','::1',1,'2026-09-14 07:33:50'),(1055,'admain','::1',1,'2026-09-14 07:34:13'),(1056,'otp:goldenweststudentaffairs@gmail.com','::1',1,'2026-09-14 07:34:32'),(1057,'otp:veronicacerdan069@gmail.com','::1',1,'2026-09-14 07:34:51'),(1058,'1242500544','::1',1,'2026-09-14 07:35:15'),(1059,'otp:lagotarogie@gmail.com','::1',1,'2026-09-14 07:35:46'),(1060,'admain','::1',1,'2026-09-14 07:41:03'),(1061,'otp:goldenweststudentaffairs@gmail.com','::1',1,'2026-09-14 07:41:23'),(1062,'1242500544','::1',1,'2026-09-14 07:51:05'),(1063,'1242500544','::1',1,'2026-09-14 07:51:33'),(1065,'admain','::1',1,'2026-09-14 07:51:59'),(1066,'1242500544','::1',1,'2026-09-14 07:52:43'),(1067,'otp:lagotarogie@gmail.com','::1',1,'2026-09-14 07:53:13'),(1068,'1242500544','::1',1,'2026-09-14 07:55:18'),(1069,'1242500544','::1',1,'2026-09-14 07:55:53'),(1070,'1242500547','::1',1,'2026-09-14 07:56:41'),(1073,'osa','::1',1,'2026-09-14 07:57:59'),(1074,'1242500544','::1',1,'2026-09-14 07:58:10'),(1075,'otp:jasperfgarce@gmail.com','::1',1,'2026-09-14 07:58:39'),(1076,'otp:lagotarogie@gmail.com','::1',1,'2026-09-14 07:58:42'),(1077,'admain','::1',1,'2026-09-14 09:32:19'),(1078,'otp:goldenweststudentaffairs@gmail.com','::1',1,'2026-09-14 09:32:41');
/*!40000 ALTER TABLE `login_attempts` ENABLE KEYS */;
UNLOCK TABLES;
DROP TABLE IF EXISTS `marshal_reports`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `marshal_reports` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `guard_id` int(10) unsigned DEFAULT NULL,
  `guard_name` varchar(150) DEFAULT NULL,
  `report_date` date NOT NULL,
  `violation_count` int(10) unsigned NOT NULL DEFAULT 0,
  `status` enum('Submitted','Sent') NOT NULL DEFAULT 'Submitted',
  `submitted_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `sent_at` datetime DEFAULT NULL,
  `sent_by` int(10) unsigned DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

LOCK TABLES `marshal_reports` WRITE;
/*!40000 ALTER TABLE `marshal_reports` DISABLE KEYS */;
/*!40000 ALTER TABLE `marshal_reports` ENABLE KEYS */;
UNLOCK TABLES;
DROP TABLE IF EXISTS `notifications`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `notifications` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int(10) unsigned NOT NULL,
  `title` varchar(150) NOT NULL DEFAULT 'New Violation',
  `message` varchar(255) NOT NULL,
  `violation_id` int(10) unsigned DEFAULT NULL,
  `is_read` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_notif_user_read` (`user_id`,`is_read`),
  KEY `fk_notif_violation` (`violation_id`),
  CONSTRAINT `fk_notif_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_notif_violation` FOREIGN KEY (`violation_id`) REFERENCES `violations` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=733 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

LOCK TABLES `notifications` WRITE;
/*!40000 ALTER TABLE `notifications` DISABLE KEYS */;
/*!40000 ALTER TABLE `notifications` ENABLE KEYS */;
UNLOCK TABLES;
DROP TABLE IF EXISTS `osa_officers`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `osa_officers` (
  `id` int(10) unsigned NOT NULL,
  `staff_id` varchar(10) DEFAULT NULL,
  `firstname` varchar(60) DEFAULT NULL,
  `middlename` varchar(60) DEFAULT NULL,
  `lastname` varchar(60) DEFAULT NULL,
  `suffix` varchar(15) DEFAULT NULL,
  `fullname` varchar(180) NOT NULL,
  `username` varchar(60) NOT NULL,
  `email` varchar(150) NOT NULL,
  `password` varchar(255) NOT NULL,
  `contact_number` varchar(20) DEFAULT NULL,
  `gender` enum('Male','Female','Other') DEFAULT NULL,
  `birthday` date DEFAULT NULL,
  `status` enum('Active','Inactive') NOT NULL DEFAULT 'Active',
  `email_verified` tinyint(1) NOT NULL DEFAULT 0,
  `verify_code` varchar(6) DEFAULT NULL,
  `verify_expires` datetime DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `username` (`username`),
  UNIQUE KEY `email` (`email`),
  CONSTRAINT `fk_osa_officers_registry` FOREIGN KEY (`id`) REFERENCES `people_registry` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

LOCK TABLES `osa_officers` WRITE;
/*!40000 ALTER TABLE `osa_officers` DISABLE KEYS */;
INSERT INTO `osa_officers` VALUES (3,NULL,'OSA',NULL,'Officer',NULL,'Osa Officer','osa','osa@gwc.edu.ph','$2y$10$klwl.b8TSRdyFz/RcezFhuARZYv5s/s4U0R7IKQoer35VjBEkrLAi',NULL,'Female',NULL,'Active',1,NULL,NULL,'2026-07-22 16:21:33');
/*!40000 ALTER TABLE `osa_officers` ENABLE KEYS */;
UNLOCK TABLES;
DROP TABLE IF EXISTS `osa_staff`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `osa_staff` (
  `id` int(10) unsigned NOT NULL,
  `staff_id` varchar(10) DEFAULT NULL,
  `firstname` varchar(60) DEFAULT NULL,
  `middlename` varchar(60) DEFAULT NULL,
  `lastname` varchar(60) DEFAULT NULL,
  `suffix` varchar(15) DEFAULT NULL,
  `fullname` varchar(180) NOT NULL,
  `username` varchar(60) NOT NULL,
  `email` varchar(150) NOT NULL,
  `password` varchar(255) NOT NULL,
  `contact_number` varchar(20) DEFAULT NULL,
  `gender` enum('Male','Female','Other') DEFAULT NULL,
  `birthday` date DEFAULT NULL,
  `status` enum('Active','Inactive') NOT NULL DEFAULT 'Active',
  `email_verified` tinyint(1) NOT NULL DEFAULT 0,
  `verify_code` varchar(6) DEFAULT NULL,
  `verify_expires` datetime DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `username` (`username`),
  UNIQUE KEY `email` (`email`),
  CONSTRAINT `fk_osa_staff_registry` FOREIGN KEY (`id`) REFERENCES `people_registry` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

LOCK TABLES `osa_staff` WRITE;
/*!40000 ALTER TABLE `osa_staff` DISABLE KEYS */;
/*!40000 ALTER TABLE `osa_staff` ENABLE KEYS */;
UNLOCK TABLES;
DROP TABLE IF EXISTS `people_registry`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `people_registry` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `role` enum('Student','Guard','OSA Staff','OSA','Admin','Dean','Head Marshal') NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=104 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

LOCK TABLES `people_registry` WRITE;
/*!40000 ALTER TABLE `people_registry` DISABLE KEYS */;
INSERT INTO `people_registry` VALUES (2,'Admin','2026-07-22 16:21:33'),(3,'OSA','2026-07-22 16:21:33'),(4,'Dean','2026-07-22 16:21:33'),(5,'Guard','2026-07-22 16:21:33'),(6,'Dean','2026-07-22 16:21:33'),(7,'Dean','2026-07-22 16:21:33'),(8,'Dean','2026-07-22 16:21:33'),(9,'Dean','2026-07-22 16:21:33'),(11,'Guard','2026-07-22 16:21:33'),(13,'Head Marshal','2026-07-22 16:21:34'),(18,'Student','2026-07-23 04:01:32'),(19,'Student','2026-07-23 04:02:51'),(20,'Student','2026-07-23 04:03:50'),(22,'Student','2026-07-23 04:04:59'),(23,'Student','2026-07-23 08:48:22'),(24,'Student','2026-07-23 14:52:35'),(26,'Student','2026-07-24 00:31:39'),(27,'Student','2026-07-24 01:10:24'),(33,'Student','2026-07-29 08:12:31'),(34,'Student','2026-07-29 21:27:58'),(35,'Student','2026-07-29 21:37:17'),(36,'Student','2026-07-29 21:40:01'),(37,'Student','2026-07-29 21:52:05'),(38,'Student','2026-07-29 22:19:48'),(40,'Student','2026-07-30 02:49:26'),(100,'Admin','2026-09-11 12:14:51'),(103,'Student','2026-09-12 17:42:41');
/*!40000 ALTER TABLE `people_registry` ENABLE KEYS */;
UNLOCK TABLES;
DROP TABLE IF EXISTS `scan_logs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `scan_logs` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `student_id` int(10) unsigned NOT NULL,
  `scanned_by` int(10) unsigned NOT NULL,
  `scanner_name` varchar(100) DEFAULT NULL,
  `scan_time` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `fk_scanlogs_student` (`student_id`),
  KEY `fk_scanlogs_guard` (`scanned_by`),
  CONSTRAINT `fk_scanlogs_guard` FOREIGN KEY (`scanned_by`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_scanlogs_student` FOREIGN KEY (`student_id`) REFERENCES `students` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=63 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

LOCK TABLES `scan_logs` WRITE;
/*!40000 ALTER TABLE `scan_logs` DISABLE KEYS */;
INSERT INTO `scan_logs` VALUES (1,22,3,'Shawn Kevyn C Pingad (Marshall)','2026-07-23 23:29:01'),(3,18,3,'Shawn Kevyn C Pingad (Marshall)','2026-07-23 23:29:01'),(43,18,100,'Marshal','2026-09-11 20:14:51'),(45,18,100,'Test Marshal','2026-09-11 20:14:58'),(46,18,100,'Test Marshal','2026-09-11 20:16:51'),(47,18,100,'Test Marshal','2026-09-11 20:27:25'),(48,18,100,'Test Marshal','2026-09-11 20:36:35'),(49,18,100,'Test Marshal','2026-09-12 00:14:10'),(50,18,100,'Test Marshal','2026-09-12 00:22:23');
/*!40000 ALTER TABLE `scan_logs` ENABLE KEYS */;
UNLOCK TABLES;
DROP TABLE IF EXISTS `student_roster`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `student_roster` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `school_id` varchar(10) NOT NULL,
  `lastname` varchar(60) NOT NULL,
  `firstname` varchar(60) DEFAULT NULL,
  `middlename` varchar(60) DEFAULT NULL,
  `course` varchar(150) DEFAULT NULL,
  `year_level` varchar(20) DEFAULT NULL,
  `is_used` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `school_id` (`school_id`)
) ENGINE=InnoDB AUTO_INCREMENT=77 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

LOCK TABLES `student_roster` WRITE;
/*!40000 ALTER TABLE `student_roster` DISABLE KEYS */;
INSERT INTO `student_roster` VALUES (7,'1242500214','ORDONIA','RAFAEL','SANTIAGO',NULL,'3rd Year',1,'2026-07-23 04:00:58'),(8,'1242500410','CERDAN','VERONICA','CABUNGAN',NULL,'3rd Year',1,'2026-07-23 04:01:32'),(12,'1242500267','PALEB','EDCEL MAE','TAUSA',NULL,'3rd Year',1,'2026-07-23 04:04:59'),(14,'1234567890','CABUNGAN','JAMES','UNGRIA','BSIT','3rd Year',1,'2026-07-23 14:52:34'),(23,'1242501400','Luis','Kirby James',NULL,'BSIT','4th Year',1,'2026-07-29 21:27:58'),(29,'1242500299','Garce','Jasper',NULL,'BSIT','3rd Year',1,'2026-07-30 17:54:53'),(30,'9979109157','Testerson','Reg',NULL,'BSIT','1st Year',1,'2026-09-12 12:55:47'),(31,'9999985492','Testerson','Reg',NULL,'BSIT','1st Year',1,'2026-09-12 12:56:06'),(32,'9911223344','Testerson','Reg',NULL,'BSIT','1st Year',1,'2026-09-12 12:56:37'),(33,'9979607588','Testerson','Reg',NULL,'BSIT','1st Year',1,'2026-09-12 13:07:36'),(34,'9935558599','Testerson','Reg',NULL,'BSIT','1st Year',1,'2026-09-12 13:16:26'),(35,'9916531678','Testerson','Reg',NULL,'BSIT','1st Year',1,'2026-09-12 13:18:22'),(36,'9979065706','Testerson','Reg',NULL,'BSIT','1st Year',1,'2026-09-12 13:22:46'),(37,'9946448335','Testerson','Reg',NULL,'BSIT','1st Year',1,'2026-09-12 13:23:46'),(38,'9952359505','Testerson','Reg',NULL,'BSIT','1st Year',1,'2026-09-12 13:27:44'),(39,'9972310577','Testerson','Reg',NULL,'BSIT','1st Year',1,'2026-09-12 13:35:46'),(40,'9987475801','Testerson','Reg',NULL,'BSIT','1st Year',1,'2026-09-12 13:45:41'),(41,'9947233116','Testerson','Reg',NULL,'BSIT','1st Year',1,'2026-09-12 13:52:15'),(42,'9989521792','Testerson','Reg',NULL,'BSIT','1st Year',1,'2026-09-12 13:58:13'),(43,'9926713979','Testerson','Reg',NULL,'BSIT','1st Year',1,'2026-09-12 14:07:58'),(44,'9967868693','Testerson','Reg',NULL,'BSIT','1st Year',1,'2026-09-12 14:08:45'),(45,'9978228281','Testerson','Reg',NULL,'BSIT','1st Year',1,'2026-09-12 14:13:03'),(46,'9926763642','Testerson','Reg',NULL,'BSIT','1st Year',1,'2026-09-12 14:17:32'),(47,'9967185420','Testerson','Reg',NULL,'BSIT','1st Year',1,'2026-09-12 14:24:53'),(48,'9996389032','Testerson','Reg',NULL,'BSIT','1st Year',1,'2026-09-12 14:31:18'),(49,'9931132293','Testerson','Reg',NULL,'BSIT','1st Year',1,'2026-09-12 14:36:29'),(50,'9965983367','Testerson','Reg',NULL,'BSIT','1st Year',1,'2026-09-12 14:41:16'),(51,'9954962763','Testerson','Reg',NULL,'BSIT','1st Year',1,'2026-09-12 14:42:54'),(52,'9936863819','Testerson','Reg',NULL,'BSIT','1st Year',1,'2026-09-12 14:53:00'),(53,'9996711040','Testerson','Reg',NULL,'BSIT','1st Year',1,'2026-09-12 14:55:29'),(54,'9927637739','Testerson','Reg',NULL,'BSIT','1st Year',1,'2026-09-12 15:16:47'),(55,'9953327759','Testerson','Reg',NULL,'BSIT','1st Year',1,'2026-09-12 15:18:13'),(56,'9988625613','Testerson','Reg',NULL,'BSIT','1st Year',1,'2026-09-12 15:26:52'),(57,'9987462703','Testerson','Reg',NULL,'BSIT','1st Year',1,'2026-09-12 15:28:50'),(58,'9939424838','Testerson','Reg',NULL,'BSIT','1st Year',1,'2026-09-12 15:33:31'),(59,'9983325035','Testerson','Reg',NULL,'BSIT','1st Year',1,'2026-09-12 15:39:27'),(60,'9984019110','Testerson','Reg',NULL,'BSIT','1st Year',1,'2026-09-12 15:41:12'),(61,'9974882356','Testerson','Reg',NULL,'BSIT','1st Year',1,'2026-09-12 15:44:40'),(62,'9938044687','Testerson','Reg',NULL,'BSIT','1st Year',1,'2026-09-12 15:55:09'),(63,'9934134278','Testerson','Reg',NULL,'BSIT','1st Year',1,'2026-09-12 16:05:04'),(64,'9994708763','Testerson','Reg',NULL,'BSIT','1st Year',1,'2026-09-12 16:26:54'),(65,'9913160647','Testerson','Reg',NULL,'BSIT','1st Year',1,'2026-09-12 17:22:25'),(66,'9935551687','Testerson','Reg',NULL,'BSIT','1st Year',1,'2026-09-12 17:29:19'),(67,'9999542214','Testerson','Reg',NULL,'BSIT','1st Year',1,'2026-09-12 17:32:15'),(68,'9928765523','Testerson','Reg',NULL,'BSIT','1st Year',1,'2026-09-12 17:37:29'),(69,'9958806269','Testerson','Reg',NULL,'BSIT','1st Year',1,'2026-09-12 17:43:35'),(70,'9924113281','Testerson','Reg',NULL,'BSIT','1st Year',1,'2026-09-12 17:47:52'),(71,'9924494842','Testerson','Reg',NULL,'BSIT','1st Year',1,'2026-09-12 17:52:02'),(72,'9911487453','Testerson','Reg',NULL,'BSIT','1st Year',1,'2026-09-12 17:55:25'),(74,'1242500544','Lagota','Rogie',NULL,'BSIT','3rd Year',1,'2026-09-14 07:16:40'),(75,'1242500547','Ordonia','Pogi',NULL,'BSIT','3rd Year',1,'2026-09-14 07:17:26'),(76,'1042500917','Llorca','Roberto',NULL,'BSIT','3rd Year',1,'2026-09-14 07:18:36');
/*!40000 ALTER TABLE `student_roster` ENABLE KEYS */;
UNLOCK TABLES;
DROP TABLE IF EXISTS `students`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `students` (
  `id` int(10) unsigned NOT NULL,
  `student_id` varchar(10) DEFAULT NULL,
  `firstname` varchar(60) DEFAULT NULL,
  `middlename` varchar(60) DEFAULT NULL,
  `lastname` varchar(60) DEFAULT NULL,
  `suffix` varchar(15) DEFAULT NULL,
  `fullname` varchar(180) NOT NULL,
  `username` varchar(60) NOT NULL,
  `email` varchar(150) NOT NULL,
  `password` varchar(255) NOT NULL,
  `contact_number` varchar(20) DEFAULT NULL,
  `gender` enum('Male','Female','Other') DEFAULT NULL,
  `birthday` date DEFAULT NULL,
  `college_id` int(10) unsigned DEFAULT NULL,
  `course` varchar(150) DEFAULT NULL,
  `year_level` varchar(20) DEFAULT NULL,
  `section` varchar(20) DEFAULT NULL,
  `profile_picture` varchar(255) DEFAULT NULL,
  `qr_code` varchar(255) DEFAULT NULL,
  `status` enum('Active','Inactive') NOT NULL DEFAULT 'Active',
  `email_verified` tinyint(1) NOT NULL DEFAULT 0,
  `verify_code` varchar(6) DEFAULT NULL,
  `verify_expires` datetime DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `username` (`username`),
  UNIQUE KEY `email` (`email`),
  UNIQUE KEY `student_id` (`student_id`),
  KEY `fk_students_college` (`college_id`),
  CONSTRAINT `fk_students_college` FOREIGN KEY (`college_id`) REFERENCES `colleges` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_students_registry` FOREIGN KEY (`id`) REFERENCES `people_registry` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

LOCK TABLES `students` WRITE;
/*!40000 ALTER TABLE `students` DISABLE KEYS */;
INSERT INTO `students` VALUES (18,'1242500410','Veronica','Cabungan','Cerdan',NULL,'Veronica Cabungan Cerdan','stu_1242500410','veronicacerdan069@gmail.com','$2y$10$7Nn.ILjOAaOMQoU1wMa.mOkw.BuUrpKyoBhYxLt.yulBOVxeAxSO6',NULL,NULL,NULL,1,'BSIT','3rd Year','D',NULL,NULL,'Active',1,NULL,NULL,'2026-07-23 04:01:32'),(19,'1242500544','Rogie','Tagura','Lagota',NULL,'Rogie Tagura Lagota','stu_1242500544','lagotarogie@gmail.com','$2y$10$OV/AQFJ9INOtJPvMgcS1oOxf20Yqn9dOhEG9NF/HbkWXkKpH9uR5S',NULL,NULL,NULL,1,'BSIT','3rd Year','D',NULL,NULL,'Active',1,NULL,NULL,'2026-07-23 04:02:51'),(20,'1242500547','Rafael','S','Damasco','Jr.','Rafael S Damasco Jr.','stu_1242500547','rafaeldamasco911@gmail.com','$2y$10$.VvGJbpPetLJzlEDHCipqei9EqwL.yRBPLZJblmIVWCaa/ubQB0za',NULL,NULL,NULL,1,'BSIT','3rd Year','D',NULL,NULL,'Active',1,NULL,NULL,'2026-07-23 04:03:50'),(22,'1242500267','Edcel Mae','Tausa','Paleb',NULL,'Edcel Mae Tausa Paleb','stu_1242500267','edcelmaepaleb46@gmail.com','$2y$10$4mhwU6Oz1S3NmpOmOn/OZO9iVKIuOecWQ0C6e5gjzTEnkVnI80IjO',NULL,NULL,NULL,1,'BSIT','3rd Year','D',NULL,NULL,'Active',1,NULL,NULL,'2026-07-23 04:04:59'),(23,'1242500357','Shwan Kevyn','Canta','Pingad',NULL,'Shawn Kevyn Canta Pingad','stu_1242500357','1242500357@student.gwc.local','$2y$10$4JuJiE1NLetNWVc2PtMOn.WH8C7fI77cVavBH3D8PIVnRwC0/wVg2',NULL,NULL,NULL,4,'BSCRIM','3rd Year','J',NULL,NULL,'Active',1,NULL,NULL,'2026-07-23 08:48:22'),(24,'1234567890','James','Ungria','Cabungan',NULL,'James Ungria Cabungan','stu_1234567890','cabunganjames77@gmail.com','$2y$10$2iVYNwZ1sscP/bBFEE2TBOp7iSVJAa5w4v72h1atHoxbKt5RHCLhu',NULL,NULL,NULL,1,'BSIT','3rd Year',NULL,NULL,NULL,'Active',1,NULL,NULL,'2026-07-23 14:52:35'),(26,'1242500335','Jumarin','Soriano','Ranches',NULL,'Jumarin Soriano Ranches','stu_1242500335','jumarinranches@gmail.com','$2y$10$14jQkmKxKJZ2vmBWglwmsOvHw8c/mWRjJ76goUvSOiWJWi8/VnnKq','09563843723','Male',NULL,1,'BSIT','3rd Year','D',NULL,NULL,'Active',1,NULL,NULL,'2026-07-24 00:31:39'),(27,'1242500816','Ethan','Cenas','Espelita',NULL,'Ethan Cenas Espelita','stu_1242500816','espelitaethan0@gmail.com','$2y$10$FE2NjY6LR6QYY3WbpXQHBOoB99z.HX937Fs7p60ci.kI2BtctqyjW','09912968431','Male',NULL,1,'BSIT','3rd Year',NULL,NULL,'1242500816.png','Active',1,NULL,NULL,'2026-07-24 01:10:24'),(33,'1242500299','Jasper','Faburada','Garce',NULL,'Jasper F Garce','stu_1242500299','jaspergarce69@gmail.com','$2y$10$PKa7aXNrVgpTUvLwsKV4n.p8eqIg1ZdDScMTK58LkNiknY7IczERW','09157458039','Male',NULL,1,'BSIT','3rd Year','D',NULL,'1242500299.png','Active',1,NULL,NULL,'2026-07-29 08:12:31'),(34,'1242501400','Kirby James','Laderas','Luis',NULL,'Kirby James Laderas Luis','stu_1242501400','julyklin382@gmail.com','$2y$10$IAHDr0gXmZ55/4uyy8UVZuZFSBhkDIX03kIrA6arCJ0xnElURKiGW','09674670752','Male',NULL,1,'BSIT','4th Year','D',NULL,'1242501400.png','Active',1,NULL,NULL,'2026-07-29 21:27:58'),(35,'1242500917','Roberto',NULL,'Llorca',NULL,'Roberto Llorca','stu_1242500917','1242500917@student.gwc.local','$2y$10$3DwTWbogtkP5DmzVOeE7gOHvsfkong2SXuwr.hR0VhW6yotFbe0IW',NULL,NULL,NULL,NULL,'bsit','3rd Year','SET D','1785389987_8f41c6e497f2.jpg',NULL,'Active',1,NULL,NULL,'2026-07-29 21:37:17'),(36,'1242501367','Joris Orlando','Montallon','Lavarias',NULL,'Joris Orlando Montallon Lavarias','stu_1242501367','lavjoris@email.com','$2y$10$zUwRc/JsLeO3jdj9OlwCT.OlfVGaQeMWyloomkZdrPdppIeXKRh2W','09668614738','Male',NULL,1,'BSIT','3rd Year',NULL,NULL,'1242501367.png','Active',1,NULL,NULL,'2026-07-29 21:40:01'),(37,'1242500168','Melanie Allen','Tapanan','Pagodpod',NULL,'Melanie Allen Tapanan Pagodpod','stu_1242500168','pagodpodtapananallenmelanie@gmail.com','$2y$10$6b1pwDrEsm2d5oFWbMTj/O.dQKXQPsBg4jFE03JwrIggBG7ZCiyIi','09982645134','Female',NULL,1,'BSIT','3rd Year',NULL,NULL,'1242500168.png','Active',1,NULL,NULL,'2026-07-29 21:52:05'),(38,'1242500699','Lovely Maeian',NULL,'Celino',NULL,'Lovely Marian Celino','stu_1242500699','lovelycelino505@gmail.com','$2y$10$W5LJhqUn..fpbNmRlIrbbukfcH5Lu7r32gJQPMZ9eXTZoEasENMXC','09567298860','Female',NULL,1,'BSIT','3rd Year',NULL,NULL,'1242500699.png','Active',1,NULL,NULL,'2026-07-29 22:19:48'),(40,'1242501345','Johncarlo','Canta','Aquino',NULL,'Johncarlo Canta Aquino','stu_1242501345','johncarloaquino760@gmail.com','$2y$10$UsrVUnt.Tqwm5tVIzcqRYukcaRach/p1UbX5bRoiWi/GJScqHcUUS','09914264422','Male',NULL,1,'BSIT','3rd Year',NULL,NULL,'1242501345.png','Active',1,NULL,NULL,'2026-07-30 02:49:26');
/*!40000 ALTER TABLE `students` ENABLE KEYS */;
UNLOCK TABLES;
DROP TABLE IF EXISTS `system_settings`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `system_settings` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `school_name` varchar(255) NOT NULL DEFAULT 'Golden West Colleges, Inc.',
  `school_address` text DEFAULT NULL,
  `school_email` varchar(255) DEFAULT NULL,
  `contact_number` varchar(100) DEFAULT NULL,
  `logo` varchar(255) DEFAULT NULL,
  `academic_year` varchar(100) DEFAULT NULL,
  `semester` varchar(50) DEFAULT NULL,
  `max_points` int(11) NOT NULL DEFAULT 100,
  `reports_drive_url` varchar(500) DEFAULT NULL,
  `violations_drive_url` varchar(500) DEFAULT NULL,
  `students_drive_url` varchar(500) DEFAULT NULL,
  `marshal_drive_url` varchar(500) DEFAULT NULL,
  `scanning_enabled` tinyint(1) NOT NULL DEFAULT 1,
  `duty_days` varchar(20) DEFAULT NULL,
  `duty_start` varchar(5) DEFAULT NULL,
  `duty_end` varchar(5) DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

LOCK TABLES `system_settings` WRITE;
/*!40000 ALTER TABLE `system_settings` DISABLE KEYS */;
INSERT INTO `system_settings` VALUES (1,'Golden West Colleges, Inc.','Alaminos, Pangasinan','goldenweststudentaffairs@gmail.com','',NULL,'2026-2027','1st Semester',100,'https://drive.google.com/drive/folders/1-0hsBstQAv15YuqAnerYDUO3uAh9Pkdx','https://drive.google.com/drive/folders/1G_wpi4dK-sxtjsUpE2KzGD6EnCBVJfCh','https://drive.google.com/drive/folders/1cN8_fnVxZeGYzmnXzSY3mObf33glHkuf','https://drive.google.com/drive/folders/1NHC3CLmWvunJ0M1dsEjcrOud20djGIHG',1,'1,2,3,4,5,6','07:00','18:00');
/*!40000 ALTER TABLE `system_settings` ENABLE KEYS */;
UNLOCK TABLES;
DROP TABLE IF EXISTS `users`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `users` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `student_id` varchar(10) DEFAULT NULL,
  `firstname` varchar(60) DEFAULT NULL,
  `middlename` varchar(60) DEFAULT NULL,
  `lastname` varchar(60) DEFAULT NULL,
  `suffix` varchar(15) DEFAULT NULL,
  `fullname` varchar(180) NOT NULL,
  `username` varchar(60) NOT NULL,
  `email` varchar(150) NOT NULL,
  `password` varchar(255) NOT NULL,
  `role` enum('Student','Guard','OSA Staff','OSA','Admin') NOT NULL DEFAULT 'Student',
  `contact_number` varchar(20) DEFAULT NULL,
  `gender` enum('Male','Female','Other') DEFAULT NULL,
  `birthday` date DEFAULT NULL,
  `college_id` int(10) unsigned DEFAULT NULL,
  `course` varchar(150) DEFAULT NULL,
  `year_level` varchar(20) DEFAULT NULL,
  `section` varchar(20) DEFAULT NULL,
  `profile_picture` varchar(255) DEFAULT NULL,
  `qr_code` varchar(255) DEFAULT NULL,
  `status` enum('Active','Inactive') NOT NULL DEFAULT 'Active',
  `email_verified` tinyint(1) NOT NULL DEFAULT 1,
  `verify_code` varchar(6) DEFAULT NULL,
  `verify_expires` datetime DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `login_otp_hash` varchar(255) DEFAULT NULL,
  `login_otp_expires` datetime DEFAULT NULL,
  `has_password` tinyint(1) NOT NULL DEFAULT 0,
  `reset_code_hash` varchar(255) DEFAULT NULL,
  `reset_expires` datetime DEFAULT NULL,
  `scanner_access` tinyint(1) NOT NULL DEFAULT 0,
  `scanner_session_token` char(64) DEFAULT NULL,
  `scanner_session_expires` datetime DEFAULT NULL,
  `device_token` varchar(64) DEFAULT NULL,
  `device_otp_hash` varchar(255) DEFAULT NULL,
  `device_otp_expires` datetime DEFAULT NULL,
  `device_otp_pending_token` varchar(64) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `username` (`username`),
  UNIQUE KEY `email` (`email`),
  UNIQUE KEY `student_id` (`student_id`),
  KEY `fk_user_college` (`college_id`),
  CONSTRAINT `fk_user_college` FOREIGN KEY (`college_id`) REFERENCES `colleges` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=199 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

LOCK TABLES `users` WRITE;
/*!40000 ALTER TABLE `users` DISABLE KEYS */;
INSERT INTO `users` VALUES (3,NULL,'OSA',NULL,'Officer',NULL,'Mrs.Alma Gerero Viray','osa','jasperfgarce@gmail.com','$2y$10$5VJlSbnB3xSd02fKRiwSFej3pbWGwHJN95yQxICm1DcQDDThiTqla','OSA',NULL,'Female',NULL,NULL,NULL,NULL,NULL,NULL,NULL,'Active',1,NULL,NULL,'2026-07-22 16:21:33',NULL,NULL,1,'$2y$10$s1j1Xp7tB/ocqq1N244xoezTxGUnQMGa5831HfgYSRONaPIPogFu2','2026-09-13 13:46:44',0,NULL,NULL,'d75c7af18c7c0817b6dcd5e02f1daef2a594036428b9bc79c28e8a61b42dbccb',NULL,NULL,NULL),(13,NULL,'Head',NULL,'Marshal',NULL,'Head Marshal','headmarshal','jasperaccoun02@gmail.com','$2y$10$bvIRyB90c5zpbTdEgG28G.QDgmnBT6qxIVpCm8y4rgWiHNN31sBua','OSA Staff',NULL,'Male',NULL,NULL,NULL,NULL,NULL,NULL,NULL,'Active',1,NULL,NULL,'2026-07-22 16:21:34',NULL,NULL,1,NULL,NULL,0,NULL,NULL,'e70ad9ae227bc96f2bf89a3049b6608b3c3614a72d7636c2c7d86f2fc1e1b48a',NULL,NULL,NULL),(18,'1242500410','Veronica','Cabungan','Cerdan',NULL,'Veronica Cabungan Cerdan','stu_1242500410','veronicacerdan069@gmail.com','$2y$10$ZkMQELR67gcY4Mfvfug8t.qC/myxAaNRqHFzFdYqqKNOrR5cIwvbC','Student',NULL,NULL,NULL,1,'BSIT','3rd Year','D',NULL,NULL,'Active',1,NULL,NULL,'2026-07-23 04:01:32',NULL,NULL,1,NULL,NULL,0,NULL,NULL,'6cb57b3cdc7e17d8599fb9bc37e6191aa741287e1927958c169ed2afe355db33',NULL,NULL,NULL),(22,'1242500267','Edcel Mae','Tausa','Paleb',NULL,'Edcel Mae Tausa Paleb','stu_1242500267','edcelmaepaleb46@gmail.com','$2y$10$mQRbCmWlV4p0K5cIarvAD./a7j34CsrFWTYVNcTaY389lPQ6EnLjG','Student',NULL,NULL,NULL,1,'BSIT','3rd Year','D',NULL,NULL,'Active',1,NULL,NULL,'2026-07-23 04:04:59',NULL,NULL,1,NULL,NULL,0,NULL,NULL,NULL,NULL,NULL,NULL),(33,'1242500299','Jasper','Faburada','Garce',NULL,'Garce Jasper F','stu_1242500299','jaspergarce69@gmail.com','$2y$10$d4TFKAUbBDI0D8BQnV0rnu1yxBQr1GIj965ovc6L9iAa4E2akPufq','Student','09157458039','Male',NULL,1,'BSIT','3rd Year','D',NULL,'1242500299.png','Active',1,NULL,NULL,'2026-07-29 08:12:31',NULL,NULL,1,NULL,NULL,0,'c947be1e065b297c723c9c059ede01a3a7e057f79040aead5c8c8df3db5afe7f','2026-09-14 20:00:00','d0a38d9693accb0107fa4a04ae84e61dc5e744b9d81a61cf95c97f721a036d7d',NULL,NULL,NULL),(34,'1242501400','Kirby James','Laderas','Luis',NULL,'Kirby James Laderas Luis','stu_1242501400','julyklin382@gmail.com','$2y$10$Jm2aAjlU4XtoJ6FasrxJmuemogA3WY46QZM/nUVX7FJs9QE61Cyfi','Student','09674670752','Male',NULL,1,'BSIT','4th Year','D',NULL,'1242501400.png','Active',1,NULL,NULL,'2026-07-29 21:27:58',NULL,NULL,1,NULL,NULL,0,NULL,NULL,'b2279285a9f33b220993c6970170dfbfbfbd0ee5d2f0e3d1e6fec45a055d626f',NULL,NULL,NULL),(100,NULL,NULL,NULL,NULL,NULL,'Mr.Denzel Valdez','admain','goldenweststudentaffairs@gmail.com','$2y$10$LgS7k7Ev31lNLCiYYKOXU.kDgiXzCsXo31BZrrQzk12HeYJm.qm4W','Admin',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,'Active',1,NULL,NULL,'2026-09-09 23:17:17',NULL,NULL,1,NULL,NULL,0,NULL,NULL,'ff7aa9db4fdce55ca4dc314aec2a49d212f121735070ae44c62aa36aedd30d31',NULL,NULL,NULL),(103,'1234567890','James','Ungria','Cabungan',NULL,'James Ungria Cabungan','stu_1234567890','cabunganjames77@gmail.com','$2y$10$CPZF39lliYsotwdQbXF.Y.hD7L.4TUV.cRvSLXWuh.W5uK5oSa1ma','Student',NULL,NULL,NULL,1,'BSIT','3rd Year','D',NULL,NULL,'Active',1,NULL,NULL,'2026-07-23 14:52:35',NULL,NULL,1,NULL,NULL,0,'9cc0cb87b15844efd6272811994ef7719f5fc4269ba443ce714ae036d9001e3b','2026-09-14 20:00:00','90a698288156e8f924e95b321cc972c82c4946234e7f4b8c13e82c073812327d',NULL,NULL,NULL),(195,'1242500544','Rogie','Tagura','Lagota',NULL,'Rogie Tagura Lagota','stu_1242500544','lagotarogie@gmail.com','$2y$10$NYLJJ9O93zFkVffaps.c..uVVi0Ra61.6B/tGRaHXy3Kc8vrpVUw2','Student','09694936038','Male',NULL,1,'BSIT','3rd Year','D',NULL,'1242500544.png','Active',1,NULL,NULL,'2026-09-14 07:16:40',NULL,NULL,1,NULL,NULL,0,NULL,NULL,'9918fbe08e4925d6d0fae5eb7ffb6505e8033445c0349fbb4c2c47b7e907bcd0',NULL,NULL,NULL),(196,'1242500547','Pogi',NULL,'Ordonia','Jr.','Rafael Ordonia Jr.','stu_1242500547','pogiordonia@gmail.com','$2y$10$oMDoqFM90Nb.dpEvadg3y.W2bG7dSpFvH1hlIVbiwxfcsoOmQtHWO','Student','09967806089','Male',NULL,1,'BSIT','3rd Year','D',NULL,'1242500547.png','Active',1,NULL,NULL,'2026-09-14 07:17:26',NULL,NULL,1,NULL,NULL,0,NULL,NULL,'b9b3f94a481004c015240842d559263cd5a6ce8cac27703e08919544674d4dca',NULL,NULL,NULL),(197,'1042500917','Roberto','Salmorin','Llorca','Jr.','Roberto Salmorin Llorca Jr.','stu_1042500917','llorcamalakas@gmail.com','$2y$10$2F7mbgC2CtsM3heHtkHO7.yEdC3uLreoOyzc9S7OrdAMJXp2v86y6','Student','09915542768','Male',NULL,1,'BSIT','3rd Year','D',NULL,'1042500917.png','Active',1,NULL,NULL,'2026-09-14 07:18:36',NULL,NULL,1,NULL,NULL,0,NULL,NULL,'5845203f6fa6ceace32ff3691595b5ecf114e7fa4add1b49b93b45f847abe556',NULL,NULL,NULL),(198,'1242500214','Rafael','Santaigo','Ordonia',NULL,'Rafael Santaigo Ordonia','stu_1242500214','cabunganjames88@gmail.com','$2y$10$UcFWYt37eG8cduCtRRf1/us4uq6knNt1ktVVb8mVU4ukdBNZoUqQa','Student','09629448412','Male',NULL,1,'BSIT','3rd Year','D',NULL,'1242500214.png','Active',1,NULL,NULL,'2026-09-14 07:21:09',NULL,NULL,1,NULL,NULL,0,NULL,NULL,'7476ed8333f419100403fe2c08a02b79cc4f13594038bc673ed56285f51d9bee',NULL,NULL,NULL);
/*!40000 ALTER TABLE `users` ENABLE KEYS */;
UNLOCK TABLES;
DROP TABLE IF EXISTS `violation_types`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `violation_types` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `violation_name` varchar(150) NOT NULL,
  `severity` enum('Minor','Major') NOT NULL DEFAULT 'Minor',
  `critical_alert` tinyint(1) NOT NULL DEFAULT 0,
  `max_points` int(10) unsigned NOT NULL DEFAULT 1,
  `escalate_after` int(10) unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=69 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

LOCK TABLES `violation_types` WRITE;
/*!40000 ALTER TABLE `violation_types` DISABLE KEYS */;
INSERT INTO `violation_types` VALUES (1,'No School ID','Minor',0,1,0),(2,'Improper Uniform','Minor',0,1,0),(3,'Improper Haircut(Male)','Minor',0,1,0),(4,'Improper Footwear (Slippers)','Minor',0,1,0),(5,'No ID Lace / Lanyard','Minor',0,1,0),(7,'Untrimmed Nails(Male)','Minor',0,1,0),(8,'Wearing Cap/Hat Indoors','Minor',0,1,0),(11,'Littering','Minor',0,1,0),(12,'Eating Inside the Computer Laboratory','Minor',0,1,0),(13,'Unauthorized Use of Phone in Class','Minor',0,1,0),(15,'Multiple Earrings','Minor',0,1,0),(16,'Prohibited Accessories / Piercings','Minor',0,1,0),(17,'Disrespect to Authority / Faculty','Major',0,1,0),(19,'Public Display of Affection (PDA)','Major',0,1,0),(20,'Smoking / Vaping on Campus','Major',0,1,0),(21,'Gambling on Campus','Major',0,1,0),(22,'Rowdy / Disruptive Behavior','Major',0,1,0),(23,'Insubordination','Major',0,1,0),(24,'Unauthorized Entry to Restricted Area','Major',0,1,0),(25,'Misuse of Computer Lab Equipment','Major',0,1,0),(26,'Installing Unauthorized Software','Major',0,1,0),(27,'Using Another Student\'s Account','Major',0,1,0),(29,'Vandalism','Major',0,1,0),(30,'Cheating / Academic Dishonesty','Major',0,1,0),(31,'Plagiarism','Major',0,1,0),(32,'Theft / Stealing','Major',0,1,0),(33,'Physical Assault / Fighting','Major',1,1,0),(34,'Bullying / Harassment','Major',0,1,0),(35,'Cyberbullying','Major',0,1,0),(36,'Hacking / Unauthorized System Access','Major',0,1,0),(37,'Data Theft / Privacy Violation','Major',0,1,0),(38,'Forgery / Falsification of Documents','Major',0,1,0),(39,'Possession of Alcohol / Intoxication','Major',0,1,0),(40,'Possession / Use of Prohibited Drugs','Major',1,1,0),(41,'Possession of Deadly Weapon','Major',1,1,0),(42,'Threats / Intimidation','Major',0,1,0),(43,'Gross Misconduct / Immoral Conduct','Major',0,1,0),(44,'Others','Minor',0,1,0),(45,'Hazing / Initiation','Major',0,1,0),(46,'Fraternity / Sorority Recruitment','Major',0,1,0),(47,'Impersonation','Major',0,1,0),(48,'Assisting in Copying / Cheating','Major',0,1,0),(49,'Unauthorized AI Use in Exams (e.g. ChatGPT)','Major',0,1,0),(50,'Class Boycotting','Major',0,1,0),(51,'Carrying Explosives','Major',1,1,0),(52,'Psychological Injury / Emotional Abuse','Major',0,1,0),(53,'Possession of Prohibited / Bad Articles','Major',0,1,0),(54,'Pornographic / Obscene Material','Major',0,1,0),(55,'Cross-dressing / Improper Gender Attire','Major',0,1,0),(56,'Inappropriate Relationship with Staff/Faculty','Major',0,1,0),(57,'Using Cellphone During Examination','Major',0,1,0),(58,'Online Bullying','Major',0,1,0),(59,'Improper Haircut','Minor',0,1,0),(60,'Loitering During Class Hours','Minor',0,1,0);
/*!40000 ALTER TABLE `violation_types` ENABLE KEYS */;
UNLOCK TABLES;
DROP TABLE IF EXISTS `violations`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `violations` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `student_id` int(10) unsigned NOT NULL,
  `violation` varchar(150) NOT NULL,
  `description` text DEFAULT NULL,
  `severity` enum('Minor','Major') NOT NULL DEFAULT 'Minor',
  `offense` varchar(30) NOT NULL DEFAULT 'First Offense',
  `evidence` varchar(255) DEFAULT NULL,
  `status` enum('Recorded') NOT NULL DEFAULT 'Recorded',
  `remarks` text DEFAULT NULL,
  `reported_by` int(10) unsigned DEFAULT NULL,
  `approved_by` int(10) unsigned DEFAULT NULL,
  `scanner_name` varchar(100) DEFAULT NULL,
  `date_reported` datetime NOT NULL DEFAULT current_timestamp(),
  `cleared_at` datetime DEFAULT NULL,
  `cleared_by` int(10) unsigned DEFAULT NULL,
  `points` int(10) unsigned NOT NULL DEFAULT 1,
  `proof_status` enum('Pending','Approved','Rejected') NOT NULL DEFAULT 'Pending',
  `proof_reviewed_by` int(10) unsigned DEFAULT NULL,
  `proof_reviewed_at` datetime DEFAULT NULL,
  `discussed_at` datetime DEFAULT NULL,
  `discussed_by` int(10) unsigned DEFAULT NULL,
  `discussion_note` text DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_violations_student` (`student_id`),
  KEY `fk_violations_reporter` (`reported_by`),
  CONSTRAINT `fk_violations_reporter` FOREIGN KEY (`reported_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_violations_student` FOREIGN KEY (`student_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=182 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

LOCK TABLES `violations` WRITE;
/*!40000 ALTER TABLE `violations` DISABLE KEYS */;
/*!40000 ALTER TABLE `violations` ENABLE KEYS */;
UNLOCK TABLES;
/*!40103 SET TIME_ZONE=@OLD_TIME_ZONE */;

/*!40101 SET SQL_MODE=@OLD_SQL_MODE */;
/*!40014 SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS */;
/*!40014 SET UNIQUE_CHECKS=@OLD_UNIQUE_CHECKS */;
/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
/*!40111 SET SQL_NOTES=@OLD_SQL_NOTES */;

