/*M!999999\- enable the sandbox mode */ 
-- MariaDB dump 10.19  Distrib 10.11.16-MariaDB, for debian-linux-gnu (x86_64)
--
-- Host: localhost    Database: crm_dev
-- ------------------------------------------------------
-- Server version	10.11.16-MariaDB-ubu2204

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

--
-- Table structure for table `activity_log`
--

DROP TABLE IF EXISTS `activity_log`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `activity_log` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `entity_type` enum('lead','klient','task') NOT NULL,
  `entity_id` int(11) NOT NULL,
  `action` varchar(50) NOT NULL,
  `message` text DEFAULT NULL,
  `user_id` int(11) NOT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_activity_log_entity` (`entity_type`,`entity_id`,`created_at`),
  KEY `idx_activity_log_user` (`user_id`,`created_at`)
) ENGINE=InnoDB AUTO_INCREMENT=4 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `activity_log`
--

LOCK TABLES `activity_log` WRITE;
/*!40000 ALTER TABLE `activity_log` DISABLE KEYS */;
INSERT INTO `activity_log` VALUES
(1,'task',1,'created','Telefon, termin: 18.02.2026 11:00, tytul: kontakt z klientem, opis: Zadzwonić do klienta w sprawie jakiegoś tam zagadnienia',2,'2026-02-18 10:47:28'),
(2,'task',2,'created','Telefon, termin: 19.02.2026 10:00, tytul: Kontakt telefoniczny',1,'2026-02-18 23:12:00'),
(3,'task',3,'created','Email, termin: 18.02.2026 23:15, tytul: email wysłać, opis: wysąłć email z ponagleniem',1,'2026-02-18 23:12:44');
/*!40000 ALTER TABLE `activity_log` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `admin_actions_audit`
--

DROP TABLE IF EXISTS `admin_actions_audit`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `admin_actions_audit` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) DEFAULT NULL,
  `username` varchar(80) DEFAULT NULL,
  `action` varchar(50) NOT NULL,
  `scope` varchar(20) NOT NULL,
  `filters_json` text DEFAULT NULL,
  `ids_json` longtext DEFAULT NULL,
  `affected_count` int(11) NOT NULL DEFAULT 0,
  `ip` varchar(45) DEFAULT NULL,
  `user_agent` varchar(255) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_admin_actions_action` (`action`),
  KEY `idx_admin_actions_created_at` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `admin_actions_audit`
--

LOCK TABLES `admin_actions_audit` WRITE;
/*!40000 ALTER TABLE `admin_actions_audit` DISABLE KEYS */;
/*!40000 ALTER TABLE `admin_actions_audit` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `cele_sprzedazowe`
--

DROP TABLE IF EXISTS `cele_sprzedazowe`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `cele_sprzedazowe` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `year` smallint(6) NOT NULL,
  `month` tinyint(4) NOT NULL,
  `user_id` int(11) NOT NULL,
  `target_netto` decimal(12,2) NOT NULL DEFAULT 0.00,
  `created_by_user_id` int(11) NOT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_cele_sprzedazowe_period_user` (`year`,`month`,`user_id`),
  KEY `idx_cele_sprzedazowe_user` (`user_id`)
) ENGINE=InnoDB AUTO_INCREMENT=13 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `cele_sprzedazowe`
--

LOCK TABLES `cele_sprzedazowe` WRITE;
/*!40000 ALTER TABLE `cele_sprzedazowe` DISABLE KEYS */;
INSERT INTO `cele_sprzedazowe` VALUES
(1,2026,2,3,5000.00,1,'2026-02-18 13:22:16'),
(2,2026,2,2,5000.00,1,'2026-02-18 13:22:16');
/*!40000 ALTER TABLE `cele_sprzedazowe` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `cennik_display`
--

DROP TABLE IF EXISTS `cennik_display`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `cennik_display` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `format` varchar(100) NOT NULL,
  `opis` text DEFAULT NULL,
  `stawka_netto` decimal(10,2) NOT NULL,
  `stawka_vat` decimal(5,2) DEFAULT 23.00,
  `data_modyfikacji` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=4 DEFAULT CHARSET=latin2 COLLATE=latin2_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `cennik_display`
--

LOCK TABLES `cennik_display` WRITE;
/*!40000 ALTER TABLE `cennik_display` DISABLE KEYS */;
INSERT INTO `cennik_display` VALUES
(1,'Góra','Górna część strony www najbardziej widoczna o wymirach 1200 x 250 px',200.00,23.00,'2025-12-14 17:20:01'),
(2,'Top - 1','Górna część strony obok informacji \"Co na antenie\"',200.00,23.00,'2025-05-24 19:15:42'),
(3,'Right - 1','Panel reklamowy z prawej strony o wymiarach 240 x 720 px',120.00,23.00,'2025-05-24 19:17:41');
/*!40000 ALTER TABLE `cennik_display` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `cennik_kalkulator`
--

DROP TABLE IF EXISTS `cennik_kalkulator`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `cennik_kalkulator` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `godzina_start` time NOT NULL,
  `godzina_end` time NOT NULL,
  `stawka_netto` decimal(10,2) NOT NULL,
  `opis` varchar(255) DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=latin2 COLLATE=latin2_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `cennik_kalkulator`
--

LOCK TABLES `cennik_kalkulator` WRITE;
/*!40000 ALTER TABLE `cennik_kalkulator` DISABLE KEYS */;
/*!40000 ALTER TABLE `cennik_kalkulator` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `cennik_patronat`
--

DROP TABLE IF EXISTS `cennik_patronat`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `cennik_patronat` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `pozycja` varchar(255) NOT NULL,
  `stawka_netto` decimal(10,2) NOT NULL DEFAULT 0.00,
  `stawka_vat` decimal(5,2) NOT NULL DEFAULT 23.00,
  `data_modyfikacji` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `cennik_patronat`
--

LOCK TABLES `cennik_patronat` WRITE;
/*!40000 ALTER TABLE `cennik_patronat` DISABLE KEYS */;
INSERT INTO `cennik_patronat` VALUES
(1,'Wariant I (Free)',0.00,23.00,'2026-02-21 17:25:14'),
(2,'Wariant II (premium)',1200.00,23.00,'2026-02-21 17:25:31');
/*!40000 ALTER TABLE `cennik_patronat` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `cennik_social`
--

DROP TABLE IF EXISTS `cennik_social`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `cennik_social` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `platforma` varchar(100) NOT NULL,
  `rodzaj_postu` varchar(100) DEFAULT NULL,
  `stawka_netto` decimal(10,2) NOT NULL,
  `stawka_vat` decimal(5,2) DEFAULT 23.00,
  `data_modyfikacji` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=latin2 COLLATE=latin2_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `cennik_social`
--

LOCK TABLES `cennik_social` WRITE;
/*!40000 ALTER TABLE `cennik_social` DISABLE KEYS */;
INSERT INTO `cennik_social` VALUES
(1,'Artykuł na platformie Facebook','Post sponsorowany z grafiką',250.00,23.00,'2025-05-24 19:18:17');
/*!40000 ALTER TABLE `cennik_social` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `cennik_spoty`
--

DROP TABLE IF EXISTS `cennik_spoty`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `cennik_spoty` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `dlugosc` enum('15','20','30') NOT NULL,
  `pasmo` enum('Prime Time','Standard Time','Night Time') NOT NULL,
  `stawka_netto` decimal(10,2) NOT NULL,
  `stawka_vat` decimal(5,2) DEFAULT 23.00,
  `data_modyfikacji` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=16 DEFAULT CHARSET=latin2 COLLATE=latin2_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `cennik_spoty`
--

LOCK TABLES `cennik_spoty` WRITE;
/*!40000 ALTER TABLE `cennik_spoty` DISABLE KEYS */;
INSERT INTO `cennik_spoty` VALUES
(1,'15','Prime Time',12.00,23.00,'2025-05-24 17:47:25'),
(2,'15','Standard Time',9.00,23.00,'2025-05-24 17:47:36'),
(4,'20','Prime Time',16.00,23.00,'2025-05-24 17:47:52'),
(5,'20','Standard Time',12.00,23.00,'2025-05-24 17:48:02'),
(6,'20','Night Time',8.00,23.00,'2025-05-24 17:48:10'),
(7,'30','Prime Time',20.00,23.00,'2025-12-17 15:32:23'),
(9,'30','Standard Time',18.00,23.00,'2025-12-17 15:32:23'),
(10,'30','Night Time',17.00,23.00,'2025-12-17 15:32:23'),
(11,'15','Night Time',6.00,23.00,'2025-05-24 18:55:11');
/*!40000 ALTER TABLE `cennik_spoty` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `cennik_sygnaly`
--

DROP TABLE IF EXISTS `cennik_sygnaly`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `cennik_sygnaly` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `typ_programu` enum('Prognoza pogody','Serwis drogowy','Sponsor ogólny','Sponsor programu') NOT NULL,
  `stawka_netto` decimal(10,2) NOT NULL,
  `stawka_vat` decimal(5,2) DEFAULT 23.00,
  `data_modyfikacji` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=4 DEFAULT CHARSET=latin2 COLLATE=latin2_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `cennik_sygnaly`
--

LOCK TABLES `cennik_sygnaly` WRITE;
/*!40000 ALTER TABLE `cennik_sygnaly` DISABLE KEYS */;
INSERT INTO `cennik_sygnaly` VALUES
(1,'Prognoza pogody',1100.00,23.00,'2025-05-24 19:12:26'),
(2,'Serwis drogowy',750.00,23.00,'2025-05-24 19:12:51');
/*!40000 ALTER TABLE `cennik_sygnaly` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `cennik_wywiady`
--

DROP TABLE IF EXISTS `cennik_wywiady`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `cennik_wywiady` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `nazwa` varchar(255) NOT NULL,
  `opis` text DEFAULT NULL,
  `stawka_netto` decimal(10,2) NOT NULL,
  `stawka_vat` decimal(5,2) DEFAULT 23.00,
  `data_modyfikacji` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=71 DEFAULT CHARSET=latin2 COLLATE=latin2_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `cennik_wywiady`
--

LOCK TABLES `cennik_wywiady` WRITE;
/*!40000 ALTER TABLE `cennik_wywiady` DISABLE KEYS */;
INSERT INTO `cennik_wywiady` VALUES
(1,'Wywiad do 15 minut','',500.00,23.00,'2025-05-24 19:13:33'),
(2,'Wywiad 15 - 30 minut','',700.00,23.00,'2025-05-24 19:13:54');
/*!40000 ALTER TABLE `cennik_wywiady` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `commission_types`
--

DROP TABLE IF EXISTS `commission_types`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `commission_types` (
  `code` varchar(32) NOT NULL,
  `name` varchar(150) NOT NULL,
  `sort_order` int(11) NOT NULL DEFAULT 0,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  PRIMARY KEY (`code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `commission_types`
--

LOCK TABLES `commission_types` WRITE;
/*!40000 ALTER TABLE `commission_types` DISABLE KEYS */;
INSERT INTO `commission_types` VALUES
('KOLEJNA_FAKTURA','Kolejna faktura',3,1),
('NOWA_UMOWA','Nowa umowa',1,1),
('PRZEDLUZENIE','Przedluzenie',2,1);
/*!40000 ALTER TABLE `commission_types` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `companies`
--

DROP TABLE IF EXISTS `companies`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `companies` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(255) NOT NULL,
  `nip` varchar(20) DEFAULT NULL,
  `regon` varchar(50) DEFAULT NULL,
  `krs` varchar(50) DEFAULT NULL,
  `address` text DEFAULT NULL,
  `city` varchar(150) DEFAULT NULL,
  `postal_code` varchar(20) DEFAULT NULL,
  `province` varchar(150) DEFAULT NULL,
  `phone` varchar(50) DEFAULT NULL,
  `email` varchar(150) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `gus_last_sid` varchar(100) DEFAULT NULL,
  `name_source` varchar(10) DEFAULT NULL,
  `address_source` varchar(10) DEFAULT NULL,
  `identifiers_source` varchar(10) DEFAULT NULL,
  `name_updated_at` datetime DEFAULT NULL,
  `address_updated_at` datetime DEFAULT NULL,
  `identifiers_updated_at` datetime DEFAULT NULL,
  `last_manual_update_at` datetime DEFAULT NULL,
  `last_gus_update_at` datetime DEFAULT NULL,
  `gus_hold_until` datetime DEFAULT NULL,
  `gus_hold_reason` varchar(20) DEFAULT NULL,
  `gus_not_found` tinyint(1) NOT NULL DEFAULT 0,
  `gus_not_found_at` datetime DEFAULT NULL,
  `gus_fail_streak` int(11) NOT NULL DEFAULT 0,
  `gus_last_error_class` varchar(20) DEFAULT NULL,
  `name_full` varchar(255) DEFAULT NULL,
  `name_short` varchar(255) DEFAULT NULL,
  `status` varchar(50) DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `gus_last_refresh_at` datetime DEFAULT NULL,
  `gus_last_status` varchar(10) DEFAULT NULL,
  `gus_last_error_code` varchar(50) DEFAULT NULL,
  `gus_last_error_message` text DEFAULT NULL,
  `street` varchar(150) DEFAULT NULL,
  `building_no` varchar(30) DEFAULT NULL,
  `apartment_no` varchar(30) DEFAULT NULL,
  `gmina` varchar(150) DEFAULT NULL,
  `powiat` varchar(150) DEFAULT NULL,
  `wojewodztwo` varchar(150) DEFAULT NULL,
  `country` varchar(80) DEFAULT NULL,
  `lock_name` tinyint(1) NOT NULL DEFAULT 0,
  `lock_address` tinyint(1) NOT NULL DEFAULT 0,
  `lock_identifiers` tinyint(1) NOT NULL DEFAULT 0,
  `last_gus_check_at` datetime DEFAULT NULL,
  `last_gus_error_at` datetime DEFAULT NULL,
  `last_gus_error_message` text DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_companies_nip` (`nip`),
  KEY `idx_companies_nip` (`nip`),
  KEY `idx_companies_regon` (`regon`),
  KEY `idx_companies_krs` (`krs`),
  KEY `idx_companies_gus_hold_until` (`gus_hold_until`),
  KEY `idx_companies_gus_not_found` (`gus_not_found`),
  CONSTRAINT `chk_companies_name_source` CHECK (`name_source` in ('manual','gus') or `name_source` is null),
  CONSTRAINT `chk_companies_address_source` CHECK (`address_source` in ('manual','gus') or `address_source` is null),
  CONSTRAINT `chk_companies_identifiers_source` CHECK (`identifiers_source` in ('manual','gus') or `identifiers_source` is null),
  CONSTRAINT `chk_companies_nip_len` CHECK (`nip` is null or char_length(`nip`) = 10),
  CONSTRAINT `chk_companies_regon_len` CHECK (`regon` is null or char_length(`regon`) in (9,14)),
  CONSTRAINT `chk_companies_krs_len` CHECK (`krs` is null or char_length(`krs`) = 10)
) ENGINE=InnoDB AUTO_INCREMENT=9 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `companies`
--

LOCK TABLES `companies` WRITE;
/*!40000 ALTER TABLE `companies` DISABLE KEYS */;
INSERT INTO `companies` VALUES
(2,'OHMG SPÓŁKA Z OGRANICZONĄ ODPOWIEDZIALNOŚCIĄ','5792268412',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,'2026-02-16 22:18:35','2026-02-16 22:18:35',NULL,'manual',NULL,'manual','2026-02-16 22:18:35',NULL,'2026-02-16 22:18:35','2026-02-16 22:18:35',NULL,NULL,NULL,0,NULL,0,NULL,'OHMG SPÓŁKA Z OGRANICZONĄ ODPOWIEDZIALNOŚCIĄ',NULL,NULL,1,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,1,0,1,NULL,NULL,NULL),
(3,'Alpintel Łukasz Binkowski','5782968272',NULL,NULL,NULL,'82-300 Elbląg',NULL,NULL,NULL,NULL,'2026-02-18 12:37:57','2026-02-18 12:38:13',NULL,'manual','manual','manual','2026-02-18 12:37:57','2026-02-18 12:38:13','2026-02-18 12:37:57','2026-02-18 12:38:13',NULL,NULL,NULL,0,NULL,0,NULL,'Alpintel Łukasz Binkowski',NULL,NULL,1,NULL,NULL,NULL,NULL,'ul. Natolińska 31',NULL,NULL,NULL,NULL,NULL,NULL,1,1,1,NULL,NULL,NULL),
(4,'Firma Handlowo-Usługowa Beata Wojtkiewicz BEATA SADOWSKA','5783101032',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,'2026-02-18 13:14:23','2026-02-18 13:14:23',NULL,'manual',NULL,'manual','2026-02-18 13:14:23',NULL,'2026-02-18 13:14:23','2026-02-18 13:14:23',NULL,NULL,NULL,0,NULL,0,NULL,'Firma Handlowo-Usługowa Beata Wojtkiewicz BEATA SADOWSKA',NULL,NULL,1,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,1,0,1,NULL,NULL,NULL),
(5,'Damian Szuplewski','5782068232',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,'2026-02-18 14:00:25','2026-02-18 14:00:25',NULL,'manual',NULL,'manual','2026-02-18 14:00:25',NULL,'2026-02-18 14:00:25','2026-02-18 14:00:25',NULL,NULL,NULL,0,NULL,0,NULL,'Damian Szuplewski',NULL,NULL,1,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,1,0,1,NULL,NULL,NULL),
(6,'Mandel - Monika Kalicińska','5223036948','362361766',NULL,'ul. gen. Jarosława Dąbrowskiego 2C','Elbląg',NULL,'WARMIŃSKO-MAZURSKIE',NULL,NULL,'2026-02-21 12:57:14','2026-02-21 12:57:14',NULL,'manual','manual','manual','2026-02-21 12:57:14','2026-02-21 12:57:14','2026-02-21 12:57:14','2026-02-21 12:57:14',NULL,NULL,NULL,0,NULL,0,NULL,'Mandel - Monika Kalicińska',NULL,NULL,1,NULL,NULL,NULL,NULL,'ul. gen. Jarosława Dąbrowskiego 2C',NULL,NULL,NULL,NULL,'WARMIŃSKO-MAZURSKIE',NULL,1,1,1,NULL,NULL,NULL),
(7,'NEW LIFE PL SPÓŁKA Z OGRANICZONĄ ODPOWIEDZIALNOŚCIĄ','6783160207','364308931',NULL,'ul. Do Studzienki 34B/15','Gdańsk',NULL,'POMORSKIE',NULL,NULL,'2026-02-21 12:57:33','2026-02-21 12:57:33',NULL,'manual','manual','manual','2026-02-21 12:57:33','2026-02-21 12:57:33','2026-02-21 12:57:33','2026-02-21 12:57:33',NULL,NULL,NULL,0,NULL,0,NULL,'NEW LIFE PL SPÓŁKA Z OGRANICZONĄ ODPOWIEDZIALNOŚCIĄ',NULL,NULL,1,NULL,NULL,NULL,NULL,'ul. Do Studzienki 34B/15',NULL,NULL,NULL,NULL,'POMORSKIE',NULL,1,1,1,NULL,NULL,NULL),
(8,'LAYMAN SPÓŁKA Z OGRANICZONĄ ODPOWIEDZIALNOŚCIĄ SPÓŁKA KOMANDYTOWA','5783135350','382473917',NULL,'ul. Słonecznikowa 10','Elbląg',NULL,'WARMIŃSKO-MAZURSKIE',NULL,NULL,'2026-02-21 12:57:57','2026-02-21 12:57:57',NULL,'manual','manual','manual','2026-02-21 12:57:57','2026-02-21 12:57:57','2026-02-21 12:57:57','2026-02-21 12:57:57',NULL,NULL,NULL,0,NULL,0,NULL,'LAYMAN SPÓŁKA Z OGRANICZONĄ ODPOWIEDZIALNOŚCIĄ SPÓŁKA KOMANDYTOWA',NULL,NULL,1,NULL,NULL,NULL,NULL,'ul. Słonecznikowa 10',NULL,NULL,NULL,NULL,'WARMIŃSKO-MAZURSKIE',NULL,1,1,1,NULL,NULL,NULL);
/*!40000 ALTER TABLE `companies` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `crm_aktywnosci`
--

DROP TABLE IF EXISTS `crm_aktywnosci`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `crm_aktywnosci` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `obiekt_typ` enum('lead','klient') NOT NULL,
  `obiekt_id` int(11) NOT NULL,
  `typ` enum('status','notatka','mail','system','email_in','email_out','sms_in','sms_out') NOT NULL,
  `status_id` int(11) DEFAULT NULL,
  `temat` varchar(255) DEFAULT NULL,
  `tresc` mediumtext DEFAULT NULL,
  `user_id` int(11) NOT NULL,
  `meta_json` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`meta_json`)),
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_crm_aktywnosci_obiekt` (`obiekt_typ`,`obiekt_id`,`created_at`),
  KEY `idx_crm_aktywnosci_user` (`user_id`,`created_at`),
  KEY `idx_crm_aktywnosci_status` (`status_id`)
) ENGINE=InnoDB AUTO_INCREMENT=19 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `crm_aktywnosci`
--

LOCK TABLES `crm_aktywnosci` WRITE;
/*!40000 ALTER TABLE `crm_aktywnosci` DISABLE KEYS */;
INSERT INTO `crm_aktywnosci` VALUES
(1,'lead',3,'email_out',NULL,'ewdwee','testowa wiadomosć',2,NULL,'2026-02-16 22:11:38'),
(2,'lead',1,'system',NULL,'Zaktualizowano osobe kontaktowa','Tomasz Nowal, stanowisko: Manger marketing, tel: 55 87 958 65 94, email: tomasz@jakasfirma.pl, preferencja: Telefon',2,NULL,'2026-02-17 14:42:33'),
(3,'lead',1,'system',NULL,'Zaktualizowano osobe kontaktowa','Usunieto dane osoby kontaktowej.',2,NULL,'2026-02-17 14:43:09'),
(4,'lead',8,'email_out',NULL,'oferta na 2026','dzień dobry,\r\nprzesyłam ofertę na 2026 rok',2,NULL,'2026-02-17 23:05:58'),
(5,'lead',8,'system',NULL,'Zaplanowano dzialanie','Telefon, termin: 18.02.2026 11:00, tytul: kontakt z klientem, opis: Zadzwonić do klienta w sprawie jakiegoś tam zagadnienia',2,NULL,'2026-02-18 10:47:28'),
(6,'klient',12,'email_in',NULL,'Re: oferta na 2026','dziekuję za ofertę\r\n\r\nW dniu 17.02.2026 o 23:05, Jan Kowalski pisze:\r\n> dzień dobry,\r\n> przesyłam ofertę na 2026 rok \r\n-- \r\nEmail Signature\r\n<https://www.ohmg.pl> 	\r\n*Karol P...',2,NULL,'2026-02-18 11:10:25'),
(7,'lead',9,'email_out',NULL,'oferta nowego leada','to jest oferta nowego leada pozdrawiam',2,NULL,'2026-02-18 13:05:29'),
(8,'klient',14,'email_in',NULL,'Re: oferta nowego leada','oferta wygląda ok biere\r\n\r\nW dniu 18.02.2026 o 13:05, Jan Kowalski pisze:\r\n> to jest oferta nowego leada pozdrawiam \r\n-- \r\nEmail Signature\r\n<https://radiozulawy.pl> 	\r\n*Karol Puf...',2,NULL,'2026-02-18 13:05:59'),
(9,'lead',9,'email_out',NULL,'oferta','to jest oferta 2026',2,NULL,'2026-02-18 13:11:59'),
(10,'lead',9,'email_in',NULL,'Re: oferta','wygląda legitnie dawaj to\r\n\r\nśr., 18 lut 2026 o 13:12 Jan Kowalski <test@adsmanager.com.pl> napisał(a):\r\n\r\n> to jest oferta 2026',2,NULL,'2026-02-18 13:12:49'),
(11,'klient',15,'system',NULL,'Akceptacja kampanii','Transakcja z kampanii #116, wartosc netto 2 440,00 zl.',2,NULL,'2026-02-18 13:14:23'),
(12,'lead',9,'system',NULL,'Konwersja po akceptacji','Zaakceptowano kampanie #116 i przeniesiono podmiot do klientow.',2,NULL,'2026-02-18 13:14:23'),
(13,'lead',10,'email_out',NULL,'testowa','testowa wiadomosć',2,NULL,'2026-02-18 13:58:23'),
(14,'lead',10,'email_in',NULL,'Re: testowa','testowa wiadomość\r\n\r\nśr., 18 lut 2026 o 13:58 Jan Kowalski <test@adsmanager.com.pl> napisał(a):\r\n\r\n> testowa wiadomosć',2,NULL,'2026-02-18 13:59:49'),
(15,'klient',16,'system',NULL,'Akceptacja kampanii','Transakcja z kampanii #119, wartosc netto 2 332,00 zl.',2,NULL,'2026-02-18 14:00:25'),
(16,'lead',10,'system',NULL,'Konwersja po akceptacji','Zaakceptowano kampanie #119 i przeniesiono podmiot do klientow.',2,NULL,'2026-02-18 14:00:25'),
(17,'lead',6,'system',NULL,'Zaplanowano dzialanie','Telefon, termin: 19.02.2026 10:00, tytul: Kontakt telefoniczny',1,NULL,'2026-02-18 23:12:00'),
(18,'lead',6,'system',NULL,'Zaplanowano dzialanie','Email, termin: 18.02.2026 23:15, tytul: email wysłać, opis: wysąłć email z ponagleniem',1,NULL,'2026-02-18 23:12:44');
/*!40000 ALTER TABLE `crm_aktywnosci` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `crm_statusy`
--

DROP TABLE IF EXISTS `crm_statusy`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `crm_statusy` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `nazwa` varchar(80) NOT NULL,
  `aktywny` tinyint(1) NOT NULL DEFAULT 1,
  `dotyczy` enum('lead','klient','oba') NOT NULL DEFAULT 'oba',
  `sort` int(11) NOT NULL DEFAULT 100,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=7 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `crm_statusy`
--

LOCK TABLES `crm_statusy` WRITE;
/*!40000 ALTER TABLE `crm_statusy` DISABLE KEYS */;
INSERT INTO `crm_statusy` VALUES
(1,'Nowy',1,'oba',10,'2026-02-15 17:45:54'),
(2,'Do kontaktu',1,'oba',20,'2026-02-15 17:45:54'),
(3,'Wysłano ofertę',1,'oba',30,'2026-02-15 17:45:54'),
(4,'Negocjacje',1,'oba',40,'2026-02-15 17:45:54'),
(5,'Wygrany',1,'oba',50,'2026-02-15 17:45:54'),
(6,'Przegrany',1,'oba',60,'2026-02-15 17:45:54');
/*!40000 ALTER TABLE `crm_statusy` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `crm_zadania`
--

DROP TABLE IF EXISTS `crm_zadania`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `crm_zadania` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `obiekt_typ` enum('lead','klient') NOT NULL,
  `obiekt_id` int(11) NOT NULL,
  `owner_user_id` int(11) NOT NULL,
  `typ` enum('telefon','email','sms','spotkanie','inne') NOT NULL,
  `tytul` varchar(160) NOT NULL,
  `opis` text DEFAULT NULL,
  `due_at` datetime NOT NULL,
  `status` enum('OPEN','DONE','CANCELLED') NOT NULL DEFAULT 'OPEN',
  `done_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_crm_zadania_owner_status_due` (`owner_user_id`,`status`,`due_at`),
  KEY `idx_crm_zadania_obiekt_due` (`obiekt_typ`,`obiekt_id`,`due_at`)
) ENGINE=InnoDB AUTO_INCREMENT=4 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `crm_zadania`
--

LOCK TABLES `crm_zadania` WRITE;
/*!40000 ALTER TABLE `crm_zadania` DISABLE KEYS */;
INSERT INTO `crm_zadania` VALUES
(1,'lead',8,2,'telefon','kontakt z klientem','Zadzwonić do klienta w sprawie jakiegoś tam zagadnienia','2026-02-18 11:00:00','OPEN',NULL,'2026-02-18 10:47:28','2026-02-18 10:47:28'),
(2,'lead',6,2,'telefon','Kontakt telefoniczny',NULL,'2026-02-19 10:00:00','OPEN',NULL,'2026-02-18 23:12:00','2026-02-18 23:12:00'),
(3,'lead',6,2,'email','email wysłać','wysąłć email z ponagleniem','2026-02-18 23:15:00','OPEN',NULL,'2026-02-18 23:12:44','2026-02-18 23:12:44');
/*!40000 ALTER TABLE `crm_zadania` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `dokumenty`
--

DROP TABLE IF EXISTS `dokumenty`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `dokumenty` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `doc_type` varchar(30) NOT NULL,
  `doc_number` varchar(50) NOT NULL,
  `client_id` int(11) NOT NULL,
  `kampania_id` int(11) DEFAULT NULL,
  `created_by_user_id` int(11) NOT NULL,
  `stored_filename` varchar(255) NOT NULL,
  `original_filename` varchar(255) NOT NULL,
  `sha256` char(64) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `doc_number` (`doc_number`),
  KEY `idx_dokumenty_client` (`client_id`),
  KEY `idx_dokumenty_kampania` (`kampania_id`),
  KEY `idx_dokumenty_user` (`created_by_user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `dokumenty`
--

LOCK TABLES `dokumenty` WRITE;
/*!40000 ALTER TABLE `dokumenty` DISABLE KEYS */;
/*!40000 ALTER TABLE `dokumenty` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `emisje`
--

DROP TABLE IF EXISTS `emisje`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `emisje` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `kampania_id` int(11) NOT NULL,
  `dzien` enum('pon','wt','sr','czw','pt','sob','ndz') NOT NULL,
  `godzina` time NOT NULL,
  `liczba` int(11) NOT NULL DEFAULT 1,
  PRIMARY KEY (`id`),
  KEY `kampania_id` (`kampania_id`),
  CONSTRAINT `emisje_ibfk_1` FOREIGN KEY (`kampania_id`) REFERENCES `kampanie` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=9 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `emisje`
--

LOCK TABLES `emisje` WRITE;
/*!40000 ALTER TABLE `emisje` DISABLE KEYS */;
/*!40000 ALTER TABLE `emisje` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `emisje_spotow`
--

DROP TABLE IF EXISTS `emisje_spotow`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `emisje_spotow` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `spot_id` int(11) NOT NULL,
  `dow` tinyint(4) NOT NULL,
  `godzina` time NOT NULL,
  `liczba` int(11) NOT NULL DEFAULT 1,
  `created_at` datetime DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_emisje_spotow_spot_id` (`spot_id`),
  KEY `idx_emisje_spotow_dow` (`dow`),
  KEY `idx_emisje_spotow_godzina` (`godzina`),
  CONSTRAINT `fk_emisje_spotow_spot` FOREIGN KEY (`spot_id`) REFERENCES `spoty` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=21 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `emisje_spotow`
--

LOCK TABLES `emisje_spotow` WRITE;
/*!40000 ALTER TABLE `emisje_spotow` DISABLE KEYS */;
/*!40000 ALTER TABLE `emisje_spotow` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `gus_cache`
--

DROP TABLE IF EXISTS `gus_cache`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `gus_cache` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `nip` varchar(20) DEFAULT NULL,
  `regon` varchar(20) DEFAULT NULL,
  `data_json` longtext NOT NULL,
  `fetched_at` datetime NOT NULL DEFAULT current_timestamp(),
  `source` varchar(20) NOT NULL DEFAULT 'gus',
  PRIMARY KEY (`id`),
  KEY `idx_gus_cache_nip` (`nip`),
  KEY `idx_gus_cache_regon` (`regon`)
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `gus_cache`
--

LOCK TABLES `gus_cache` WRITE;
/*!40000 ALTER TABLE `gus_cache` DISABLE KEYS */;
INSERT INTO `gus_cache` VALUES
(1,'5790004488','810528352','{\"name\":\"Przedsiębiorstwo Handlowo Usługowe \\\"ELRO\\\" Elżbieta Pufal\",\"nip\":\"5790004488\",\"regon\":\"810528352\",\"krs\":\"\",\"address_street\":\"8\",\"address_postal\":\"82-200\",\"address_city\":\"Grobelno\",\"legal_form\":\"\"}','2026-02-16 15:54:15','gus'),
(2,'5782968272','386593653','{\"name\":\"Alpintel Łukasz Binkowski\",\"nip\":\"5782968272\",\"regon\":\"386593653\",\"krs\":\"\",\"address_street\":\"ul. Natolińska 31\",\"address_postal\":\"82-300\",\"address_city\":\"Elbląg\",\"legal_form\":\"\"}','2026-02-18 12:38:11','gus');
/*!40000 ALTER TABLE `gus_cache` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `gus_refresh_queue`
--

DROP TABLE IF EXISTS `gus_refresh_queue`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `gus_refresh_queue` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `company_id` int(11) NOT NULL,
  `priority` tinyint(4) NOT NULL DEFAULT 5,
  `reason` varchar(50) DEFAULT NULL,
  `status` varchar(20) NOT NULL DEFAULT 'pending',
  `attempts` int(11) NOT NULL DEFAULT 0,
  `max_attempts` int(11) NOT NULL DEFAULT 3,
  `next_run_at` datetime NOT NULL,
  `locked_at` datetime DEFAULT NULL,
  `locked_by` varchar(50) DEFAULT NULL,
  `last_error_code` varchar(50) DEFAULT NULL,
  `last_error_message` text DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `finished_at` datetime DEFAULT NULL,
  `error_class` varchar(20) DEFAULT NULL,
  `error_code` varchar(50) DEFAULT NULL,
  `error_message` text DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_queue_status_next` (`status`,`next_run_at`,`priority`),
  KEY `idx_queue_company` (`company_id`),
  KEY `idx_queue_company_status` (`company_id`,`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `gus_refresh_queue`
--

LOCK TABLES `gus_refresh_queue` WRITE;
/*!40000 ALTER TABLE `gus_refresh_queue` DISABLE KEYS */;
/*!40000 ALTER TABLE `gus_refresh_queue` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `gus_snapshots`
--

DROP TABLE IF EXISTS `gus_snapshots`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `gus_snapshots` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `company_id` int(11) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `env` varchar(10) NOT NULL,
  `request_type` varchar(10) NOT NULL,
  `request_value` varchar(32) NOT NULL,
  `report_type` varchar(80) DEFAULT NULL,
  `http_code` int(11) DEFAULT NULL,
  `ok` tinyint(1) NOT NULL DEFAULT 0,
  `error_code` varchar(50) DEFAULT NULL,
  `error_message` text DEFAULT NULL,
  `fault_code` varchar(100) DEFAULT NULL,
  `fault_string` text DEFAULT NULL,
  `raw_request` longtext DEFAULT NULL,
  `raw_response` longtext DEFAULT NULL,
  `raw_parsed` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`raw_parsed`)),
  `correlation_id` varchar(100) DEFAULT NULL,
  `attempt_no` int(11) DEFAULT NULL,
  `latency_ms` int(11) DEFAULT NULL,
  `error_class` varchar(20) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_gus_snapshots_company` (`company_id`),
  KEY `idx_gus_snapshots_env` (`env`),
  KEY `idx_gus_snapshots_request_value` (`request_value`),
  KEY `idx_gus_snapshots_created_at` (`created_at`),
  KEY `idx_gus_snapshots_ok` (`ok`),
  KEY `idx_gus_snapshots_company_created` (`company_id`,`created_at`)
) ENGINE=InnoDB AUTO_INCREMENT=61 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `gus_snapshots`
--

LOCK TABLES `gus_snapshots` WRITE;
/*!40000 ALTER TABLE `gus_snapshots` DISABLE KEYS */;
INSERT INTO `gus_snapshots` VALUES
(1,NULL,'2026-02-16 14:44:41','prod','nip','5790004488',NULL,NULL,0,NULL,'Nie znaleziono podmiotu w GUS.','HTTP','Cannot process the message because the content type \'text/xml; charset=utf-8\' was not the expected type \'multipart/related; type=\"application/xop+xml\"\'.','<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<env:Envelope xmlns:env=\"http://www.w3.org/2003/05/soap-envelope\" xmlns:ns1=\"http://CIS/BIR/PUBL/2014/07/DataContract\" xmlns:ns2=\"http://CIS/BIR/PUBL/2014/07\" xmlns:ns3=\"http://www.w3.org/2005/08/addressing\"><env:Header><ns3:Action env:mustUnderstand=\"true\">http://CIS/BIR/PUBL/2014/07/IUslugaBIRzewnPubl/DaneSzukajPodmioty</ns3:Action><ns3:To env:mustUnderstand=\"true\">https://wyszukiwarkaregon.stat.gov.pl/wsBIR/UslugaBIRzewnPubl.svc</ns3:To><ns3:MessageID env:mustUnderstand=\"true\">urn:uuid:f1f5acd8-3b23-4a3c-a3f9-4413c409826b</ns3:MessageID><wsa:ReplyTo xmlns:wsa=\"http://www.w3.org/2005/08/addressing\"><wsa:Address>http://www.w3.org/2005/08/addressing/anonymous</wsa:Address></wsa:ReplyTo><ns2:sid>h7638v775rf924m4z9x4</ns2:sid></env:Header><env:Body><ns2:DaneSzukajPodmioty><ns2:pParametryWyszukiwania><ns1:Nip>5790004488</ns1:Nip></ns2:pParametryWyszukiwania></ns2:DaneSzukajPodmioty></env:Body></env:Envelope>\n','\r\n--uuid:18e18dae-c6e6-44ff-bd8d-55fd420f328a+id=6997697\r\nContent-ID: <http://tempuri.org/0>\r\nContent-Transfer-Encoding: 8bit\r\nContent-Type: application/xop+xml;charset=utf-8;type=\"application/soap+xml\"\r\n\r\n<s:Envelope xmlns:s=\"http://www.w3.org/2003/05/soap-envelope\" xmlns:a=\"http://www.w3.org/2005/08/addressing\"><s:Header><a:Action s:mustUnderstand=\"1\">http://CIS/BIR/PUBL/2014/07/IUslugaBIRzewnPubl/DaneSzukajPodmiotyResponse</a:Action><a:RelatesTo>urn:uuid:f1f5acd8-3b23-4a3c-a3f9-4413c409826b</a:RelatesTo></s:Header><s:Body><DaneSzukajPodmiotyResponse xmlns=\"http://CIS/BIR/PUBL/2014/07\"><DaneSzukajPodmiotyResult/></DaneSzukajPodmiotyResponse></s:Body></s:Envelope>\r\n--uuid:18e18dae-c6e6-44ff-bd8d-55fd420f328a+id=6997697--\r\n',NULL,'gus_69931f491ab081.56327941',2,429,'TRANSPORT'),
(2,NULL,'2026-02-16 14:45:25','prod','nip','5790004488',NULL,200,0,NULL,'Nie znaleziono podmiotu w GUS.','HTTP','Cannot process the message because the content type \'text/xml; charset=utf-8\' was not the expected type \'multipart/related; type=\"application/xop+xml\"\'.','<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<env:Envelope xmlns:env=\"http://www.w3.org/2003/05/soap-envelope\" xmlns:ns1=\"http://CIS/BIR/PUBL/2014/07/DataContract\" xmlns:ns2=\"http://CIS/BIR/PUBL/2014/07\" xmlns:ns3=\"http://www.w3.org/2005/08/addressing\"><env:Header><ns3:Action env:mustUnderstand=\"true\">http://CIS/BIR/PUBL/2014/07/IUslugaBIRzewnPubl/DaneSzukajPodmioty</ns3:Action><ns3:To env:mustUnderstand=\"true\">https://wyszukiwarkaregon.stat.gov.pl/wsBIR/UslugaBIRzewnPubl.svc</ns3:To><ns3:MessageID env:mustUnderstand=\"true\">urn:uuid:8dd1af6b-e4d8-449d-8043-005f8e36ed9e</ns3:MessageID><wsa:ReplyTo xmlns:wsa=\"http://www.w3.org/2005/08/addressing\"><wsa:Address>http://www.w3.org/2005/08/addressing/anonymous</wsa:Address></wsa:ReplyTo><ns2:sid>t9kc8xs8gm249fuf39yf</ns2:sid></env:Header><env:Body><ns2:DaneSzukajPodmioty><ns2:pParametryWyszukiwania><ns1:Nip>5790004488</ns1:Nip></ns2:pParametryWyszukiwania></ns2:DaneSzukajPodmioty></env:Body></env:Envelope>\n','\r\n--uuid:18e18dae-c6e6-44ff-bd8d-55fd420f328a+id=7008224\r\nContent-ID: <http://tempuri.org/0>\r\nContent-Transfer-Encoding: 8bit\r\nContent-Type: application/xop+xml;charset=utf-8;type=\"application/soap+xml\"\r\n\r\n<s:Envelope xmlns:s=\"http://www.w3.org/2003/05/soap-envelope\" xmlns:a=\"http://www.w3.org/2005/08/addressing\"><s:Header><a:Action s:mustUnderstand=\"1\">http://CIS/BIR/PUBL/2014/07/IUslugaBIRzewnPubl/DaneSzukajPodmiotyResponse</a:Action><a:RelatesTo>urn:uuid:8dd1af6b-e4d8-449d-8043-005f8e36ed9e</a:RelatesTo></s:Header><s:Body><DaneSzukajPodmiotyResponse xmlns=\"http://CIS/BIR/PUBL/2014/07\"><DaneSzukajPodmiotyResult/></DaneSzukajPodmiotyResponse></s:Body></s:Envelope>\r\n--uuid:18e18dae-c6e6-44ff-bd8d-55fd420f328a+id=7008224--\r\n',NULL,'gus_69931f74e057e1.48295405',2,736,'TRANSPORT'),
(3,NULL,'2026-02-16 14:45:40','prod','nip','5790004488',NULL,NULL,0,NULL,'Nie znaleziono podmiotu w GUS.','HTTP','Cannot process the message because the content type \'text/xml; charset=utf-8\' was not the expected type \'multipart/related; type=\"application/xop+xml\"\'.','<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<env:Envelope xmlns:env=\"http://www.w3.org/2003/05/soap-envelope\" xmlns:ns1=\"http://CIS/BIR/PUBL/2014/07/DataContract\" xmlns:ns2=\"http://CIS/BIR/PUBL/2014/07\" xmlns:ns3=\"http://www.w3.org/2005/08/addressing\"><env:Header><ns3:Action env:mustUnderstand=\"true\">http://CIS/BIR/PUBL/2014/07/IUslugaBIRzewnPubl/DaneSzukajPodmioty</ns3:Action><ns3:To env:mustUnderstand=\"true\">https://wyszukiwarkaregon.stat.gov.pl/wsBIR/UslugaBIRzewnPubl.svc</ns3:To><ns3:MessageID env:mustUnderstand=\"true\">urn:uuid:64d6a68e-c765-422b-9e23-f531bc2d02a2</ns3:MessageID><wsa:ReplyTo xmlns:wsa=\"http://www.w3.org/2005/08/addressing\"><wsa:Address>http://www.w3.org/2005/08/addressing/anonymous</wsa:Address></wsa:ReplyTo><ns2:sid>8f4pf8u4g355s85fxmsp</ns2:sid></env:Header><env:Body><ns2:DaneSzukajPodmioty><ns2:pParametryWyszukiwania><ns1:Nip>5790004488</ns1:Nip></ns2:pParametryWyszukiwania></ns2:DaneSzukajPodmioty></env:Body></env:Envelope>\n','\r\n--uuid:18e18dae-c6e6-44ff-bd8d-55fd420f328a+id=7011032\r\nContent-ID: <http://tempuri.org/0>\r\nContent-Transfer-Encoding: 8bit\r\nContent-Type: application/xop+xml;charset=utf-8;type=\"application/soap+xml\"\r\n\r\n<s:Envelope xmlns:s=\"http://www.w3.org/2003/05/soap-envelope\" xmlns:a=\"http://www.w3.org/2005/08/addressing\"><s:Header><a:Action s:mustUnderstand=\"1\">http://CIS/BIR/PUBL/2014/07/IUslugaBIRzewnPubl/DaneSzukajPodmiotyResponse</a:Action><a:RelatesTo>urn:uuid:64d6a68e-c765-422b-9e23-f531bc2d02a2</a:RelatesTo></s:Header><s:Body><DaneSzukajPodmiotyResponse xmlns=\"http://CIS/BIR/PUBL/2014/07\"><DaneSzukajPodmiotyResult/></DaneSzukajPodmiotyResponse></s:Body></s:Envelope>\r\n--uuid:18e18dae-c6e6-44ff-bd8d-55fd420f328a+id=7011032--\r\n',NULL,'gus_69931f847693e6.64867741',2,462,'TRANSPORT'),
(4,NULL,'2026-02-16 14:46:13','prod','nip','5790004488',NULL,NULL,0,NULL,'Nie znaleziono podmiotu w GUS.','HTTP','Cannot process the message because the content type \'text/xml; charset=utf-8\' was not the expected type \'multipart/related; type=\"application/xop+xml\"\'.','<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<env:Envelope xmlns:env=\"http://www.w3.org/2003/05/soap-envelope\" xmlns:ns1=\"http://CIS/BIR/PUBL/2014/07/DataContract\" xmlns:ns2=\"http://CIS/BIR/PUBL/2014/07\" xmlns:ns3=\"http://www.w3.org/2005/08/addressing\"><env:Header><ns3:Action env:mustUnderstand=\"true\">http://CIS/BIR/PUBL/2014/07/IUslugaBIRzewnPubl/DaneSzukajPodmioty</ns3:Action><ns3:To env:mustUnderstand=\"true\">https://wyszukiwarkaregon.stat.gov.pl/wsBIR/UslugaBIRzewnPubl.svc</ns3:To><ns3:MessageID env:mustUnderstand=\"true\">urn:uuid:08ba80e1-6894-4bf2-b680-6109c343ef0a</ns3:MessageID><wsa:ReplyTo xmlns:wsa=\"http://www.w3.org/2005/08/addressing\"><wsa:Address>http://www.w3.org/2005/08/addressing/anonymous</wsa:Address></wsa:ReplyTo><ns2:sid>3h68972br4cd4n7kn374</ns2:sid></env:Header><env:Body><ns2:DaneSzukajPodmioty><ns2:pParametryWyszukiwania><ns1:Nip>5790004488</ns1:Nip></ns2:pParametryWyszukiwania></ns2:DaneSzukajPodmioty></env:Body></env:Envelope>\n','\r\n--uuid:18e18dae-c6e6-44ff-bd8d-55fd420f328a+id=7017075\r\nContent-ID: <http://tempuri.org/0>\r\nContent-Transfer-Encoding: 8bit\r\nContent-Type: application/xop+xml;charset=utf-8;type=\"application/soap+xml\"\r\n\r\n<s:Envelope xmlns:s=\"http://www.w3.org/2003/05/soap-envelope\" xmlns:a=\"http://www.w3.org/2005/08/addressing\"><s:Header><a:Action s:mustUnderstand=\"1\">http://CIS/BIR/PUBL/2014/07/IUslugaBIRzewnPubl/DaneSzukajPodmiotyResponse</a:Action><a:RelatesTo>urn:uuid:08ba80e1-6894-4bf2-b680-6109c343ef0a</a:RelatesTo></s:Header><s:Body><DaneSzukajPodmiotyResponse xmlns=\"http://CIS/BIR/PUBL/2014/07\"><DaneSzukajPodmiotyResult/></DaneSzukajPodmiotyResponse></s:Body></s:Envelope>\r\n--uuid:18e18dae-c6e6-44ff-bd8d-55fd420f328a+id=7017075--\r\n',NULL,'gus_69931fa49c2e20.45972667',2,414,'TRANSPORT'),
(5,NULL,'2026-02-16 14:46:13','prod','nip','5790004488',NULL,200,0,NULL,'Nie znaleziono podmiotu w GUS.','HTTP','Cannot process the message because the content type \'text/xml; charset=utf-8\' was not the expected type \'multipart/related; type=\"application/xop+xml\"\'.','<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<env:Envelope xmlns:env=\"http://www.w3.org/2003/05/soap-envelope\" xmlns:ns1=\"http://CIS/BIR/PUBL/2014/07/DataContract\" xmlns:ns2=\"http://CIS/BIR/PUBL/2014/07\" xmlns:ns3=\"http://www.w3.org/2005/08/addressing\"><env:Header><ns3:Action env:mustUnderstand=\"true\">http://CIS/BIR/PUBL/2014/07/IUslugaBIRzewnPubl/DaneSzukajPodmioty</ns3:Action><ns3:To env:mustUnderstand=\"true\">https://wyszukiwarkaregon.stat.gov.pl/wsBIR/UslugaBIRzewnPubl.svc</ns3:To><ns3:MessageID env:mustUnderstand=\"true\">urn:uuid:94ed3430-c532-4b37-b505-cd41bb0dcfd8</ns3:MessageID><wsa:ReplyTo xmlns:wsa=\"http://www.w3.org/2005/08/addressing\"><wsa:Address>http://www.w3.org/2005/08/addressing/anonymous</wsa:Address></wsa:ReplyTo><ns2:sid>cmm946x8nh26b3347n2z</ns2:sid></env:Header><env:Body><ns2:DaneSzukajPodmioty><ns2:pParametryWyszukiwania><ns1:Nip>5790004488</ns1:Nip></ns2:pParametryWyszukiwania></ns2:DaneSzukajPodmioty></env:Body></env:Envelope>\n','\r\n--uuid:18e18dae-c6e6-44ff-bd8d-55fd420f328a+id=7017191\r\nContent-ID: <http://tempuri.org/0>\r\nContent-Transfer-Encoding: 8bit\r\nContent-Type: application/xop+xml;charset=utf-8;type=\"application/soap+xml\"\r\n\r\n<s:Envelope xmlns:s=\"http://www.w3.org/2003/05/soap-envelope\" xmlns:a=\"http://www.w3.org/2005/08/addressing\"><s:Header><a:Action s:mustUnderstand=\"1\">http://CIS/BIR/PUBL/2014/07/IUslugaBIRzewnPubl/DaneSzukajPodmiotyResponse</a:Action><a:RelatesTo>urn:uuid:94ed3430-c532-4b37-b505-cd41bb0dcfd8</a:RelatesTo></s:Header><s:Body><DaneSzukajPodmiotyResponse xmlns=\"http://CIS/BIR/PUBL/2014/07\"><DaneSzukajPodmiotyResult/></DaneSzukajPodmiotyResponse></s:Body></s:Envelope>\r\n--uuid:18e18dae-c6e6-44ff-bd8d-55fd420f328a+id=7017191--\r\n',NULL,'gus_69931fa4ca3909.22898503',2,719,'TRANSPORT'),
(6,NULL,'2026-02-16 15:42:37','prod','nip','5790004488',NULL,NULL,0,NULL,'Nie znaleziono podmiotu w GUS.','HTTP','Cannot process the message because the content type \'text/xml; charset=utf-8\' was not the expected type \'multipart/related; type=\"application/xop+xml\"\'.','<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<env:Envelope xmlns:env=\"http://www.w3.org/2003/05/soap-envelope\" xmlns:ns1=\"http://CIS/BIR/PUBL/2014/07/DataContract\" xmlns:ns2=\"http://CIS/BIR/PUBL/2014/07\" xmlns:ns3=\"http://www.w3.org/2005/08/addressing\"><env:Header><ns3:Action env:mustUnderstand=\"true\">http://CIS/BIR/PUBL/2014/07/IUslugaBIRzewnPubl/DaneSzukajPodmioty</ns3:Action><ns3:To env:mustUnderstand=\"true\">https://wyszukiwarkaregon.stat.gov.pl/wsBIR/UslugaBIRzewnPubl.svc</ns3:To><ns3:MessageID env:mustUnderstand=\"true\">urn:uuid:ed1c6326-6656-4102-bdec-cea5f4eaef1a</ns3:MessageID><wsa:ReplyTo xmlns:wsa=\"http://www.w3.org/2005/08/addressing\"><wsa:Address>http://www.w3.org/2005/08/addressing/anonymous</wsa:Address></wsa:ReplyTo><ns2:sid>43ex9y4t5zd73s2h5w7p</ns2:sid></env:Header><env:Body><ns2:DaneSzukajPodmioty><ns2:pParametryWyszukiwania><ns1:Nip>5790004488</ns1:Nip></ns2:pParametryWyszukiwania></ns2:DaneSzukajPodmioty></env:Body></env:Envelope>\n','\r\n--uuid:18e18dae-c6e6-44ff-bd8d-55fd420f328a+id=7648141\r\nContent-ID: <http://tempuri.org/0>\r\nContent-Transfer-Encoding: 8bit\r\nContent-Type: application/xop+xml;charset=utf-8;type=\"application/soap+xml\"\r\n\r\n<s:Envelope xmlns:s=\"http://www.w3.org/2003/05/soap-envelope\" xmlns:a=\"http://www.w3.org/2005/08/addressing\"><s:Header><a:Action s:mustUnderstand=\"1\">http://CIS/BIR/PUBL/2014/07/IUslugaBIRzewnPubl/DaneSzukajPodmiotyResponse</a:Action><a:RelatesTo>urn:uuid:ed1c6326-6656-4102-bdec-cea5f4eaef1a</a:RelatesTo></s:Header><s:Body><DaneSzukajPodmiotyResponse xmlns=\"http://CIS/BIR/PUBL/2014/07\"><DaneSzukajPodmiotyResult/></DaneSzukajPodmiotyResponse></s:Body></s:Envelope>\r\n--uuid:18e18dae-c6e6-44ff-bd8d-55fd420f328a+id=7648141--\r\n',NULL,'gus_69932cdc8e44c9.15258033',2,432,'TRANSPORT'),
(7,NULL,'2026-02-16 15:44:03','prod','nip','5790004488','DaneSzukajPodmioty',500,0,'login','Serwer GUS zwrócił błąd HTTP 500.',NULL,NULL,NULL,NULL,NULL,'gus_69932d315a9287.67064129',3,62,'HTTP_5XX'),
(8,NULL,'2026-02-16 15:45:48','test','nip','5790004488',NULL,NULL,0,NULL,'Brak SID z Zaloguj.','HTTP','Cannot process the message because the content type \'text/xml; charset=utf-8\' was not the expected type \'multipart/related; type=\"application/xop+xml\"\'.','<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<env:Envelope xmlns:env=\"http://www.w3.org/2003/05/soap-envelope\" xmlns:ns1=\"http://CIS/BIR/PUBL/2014/07\" xmlns:ns2=\"http://www.w3.org/2005/08/addressing\"><env:Header><ns2:Action env:mustUnderstand=\"true\">http://CIS/BIR/PUBL/2014/07/IUslugaBIRzewnPubl/Zaloguj</ns2:Action><ns2:To env:mustUnderstand=\"true\">https://wyszukiwarkaregontest.stat.gov.pl/wsBIR/UslugaBIRzewnPubl.svc</ns2:To><ns2:MessageID env:mustUnderstand=\"true\">urn:uuid:9bcf2b3f-bca4-49a2-92e1-4a16dba13b81</ns2:MessageID><wsa:ReplyTo xmlns:wsa=\"http://www.w3.org/2005/08/addressing\"><wsa:Address>http://www.w3.org/2005/08/addressing/anonymous</wsa:Address></wsa:ReplyTo></env:Header><env:Body><ns1:Zaloguj><ns1:pKluczUzytkownika>bfb4e8dec7ea4129ab5a</ns1:pKluczUzytkownika></ns1:Zaloguj></env:Body></env:Envelope>\n','\r\n--uuid:2bffd80f-cc26-4466-8fc2-3a19754a3e18+id=135070\r\nContent-ID: <http://tempuri.org/0>\r\nContent-Transfer-Encoding: 8bit\r\nContent-Type: application/xop+xml;charset=utf-8;type=\"application/soap+xml\"\r\n\r\n<s:Envelope xmlns:s=\"http://www.w3.org/2003/05/soap-envelope\" xmlns:a=\"http://www.w3.org/2005/08/addressing\"><s:Header><a:Action s:mustUnderstand=\"1\">http://CIS/BIR/PUBL/2014/07/IUslugaBIRzewnPubl/ZalogujResponse</a:Action><a:RelatesTo>urn:uuid:9bcf2b3f-bca4-49a2-92e1-4a16dba13b81</a:RelatesTo></s:Header><s:Body><ZalogujResponse xmlns=\"http://CIS/BIR/PUBL/2014/07\"><ZalogujResult/></ZalogujResponse></s:Body></s:Envelope>\r\n--uuid:2bffd80f-cc26-4466-8fc2-3a19754a3e18+id=135070--\r\n',NULL,'gus_69932d9bedec64.44680690',2,371,'TRANSPORT'),
(9,NULL,'2026-02-16 15:46:00','prod','nip','5260251049',NULL,NULL,0,NULL,'Nie znaleziono podmiotu w GUS.','HTTP','Cannot process the message because the content type \'text/xml; charset=utf-8\' was not the expected type \'multipart/related; type=\"application/xop+xml\"\'.','<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<env:Envelope xmlns:env=\"http://www.w3.org/2003/05/soap-envelope\" xmlns:ns1=\"http://CIS/BIR/PUBL/2014/07/DataContract\" xmlns:ns2=\"http://CIS/BIR/PUBL/2014/07\" xmlns:ns3=\"http://www.w3.org/2005/08/addressing\"><env:Header><ns3:Action env:mustUnderstand=\"true\">http://CIS/BIR/PUBL/2014/07/IUslugaBIRzewnPubl/DaneSzukajPodmioty</ns3:Action><ns3:To env:mustUnderstand=\"true\">https://wyszukiwarkaregon.stat.gov.pl/wsBIR/UslugaBIRzewnPubl.svc</ns3:To><ns3:MessageID env:mustUnderstand=\"true\">urn:uuid:f9492a8f-5edd-4dd4-9c3f-dc90263817f7</ns3:MessageID><wsa:ReplyTo xmlns:wsa=\"http://www.w3.org/2005/08/addressing\"><wsa:Address>http://www.w3.org/2005/08/addressing/anonymous</wsa:Address></wsa:ReplyTo><ns2:sid>9eugv2g77sk75t396nff</ns2:sid></env:Header><env:Body><ns2:DaneSzukajPodmioty><ns2:pParametryWyszukiwania><ns1:Nip>5260251049</ns1:Nip></ns2:pParametryWyszukiwania></ns2:DaneSzukajPodmioty></env:Body></env:Envelope>\n','\r\n--uuid:18e18dae-c6e6-44ff-bd8d-55fd420f328a+id=7685336\r\nContent-ID: <http://tempuri.org/0>\r\nContent-Transfer-Encoding: 8bit\r\nContent-Type: application/xop+xml;charset=utf-8;type=\"application/soap+xml\"\r\n\r\n<s:Envelope xmlns:s=\"http://www.w3.org/2003/05/soap-envelope\" xmlns:a=\"http://www.w3.org/2005/08/addressing\"><s:Header><a:Action s:mustUnderstand=\"1\">http://CIS/BIR/PUBL/2014/07/IUslugaBIRzewnPubl/DaneSzukajPodmiotyResponse</a:Action><a:RelatesTo>urn:uuid:f9492a8f-5edd-4dd4-9c3f-dc90263817f7</a:RelatesTo></s:Header><s:Body><DaneSzukajPodmiotyResponse xmlns=\"http://CIS/BIR/PUBL/2014/07\"><DaneSzukajPodmiotyResult/></DaneSzukajPodmiotyResponse></s:Body></s:Envelope>\r\n--uuid:18e18dae-c6e6-44ff-bd8d-55fd420f328a+id=7685336--\r\n',NULL,'gus_69932da7bb1873.01923844',2,440,'TRANSPORT'),
(10,NULL,'2026-02-16 15:46:00','prod','nip','5250001009',NULL,NULL,0,'validation','validation_failed',NULL,NULL,NULL,NULL,NULL,'gus_69932da8365379.45245590',0,0,'VALIDATION'),
(11,NULL,'2026-02-16 15:46:00','prod','nip','5261040828',NULL,NULL,0,NULL,'Nie znaleziono podmiotu w GUS.','HTTP','Cannot process the message because the content type \'text/xml; charset=utf-8\' was not the expected type \'multipart/related; type=\"application/xop+xml\"\'.','<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<env:Envelope xmlns:env=\"http://www.w3.org/2003/05/soap-envelope\" xmlns:ns1=\"http://CIS/BIR/PUBL/2014/07/DataContract\" xmlns:ns2=\"http://CIS/BIR/PUBL/2014/07\" xmlns:ns3=\"http://www.w3.org/2005/08/addressing\"><env:Header><ns3:Action env:mustUnderstand=\"true\">http://CIS/BIR/PUBL/2014/07/IUslugaBIRzewnPubl/DaneSzukajPodmioty</ns3:Action><ns3:To env:mustUnderstand=\"true\">https://wyszukiwarkaregon.stat.gov.pl/wsBIR/UslugaBIRzewnPubl.svc</ns3:To><ns3:MessageID env:mustUnderstand=\"true\">urn:uuid:2beb8193-6616-42cd-aed5-d2174e047be1</ns3:MessageID><wsa:ReplyTo xmlns:wsa=\"http://www.w3.org/2005/08/addressing\"><wsa:Address>http://www.w3.org/2005/08/addressing/anonymous</wsa:Address></wsa:ReplyTo><ns2:sid>kfd9hrpu86sk38sddtsp</ns2:sid></env:Header><env:Body><ns2:DaneSzukajPodmioty><ns2:pParametryWyszukiwania><ns1:Nip>5261040828</ns1:Nip></ns2:pParametryWyszukiwania></ns2:DaneSzukajPodmioty></env:Body></env:Envelope>\n','\r\n--uuid:18e18dae-c6e6-44ff-bd8d-55fd420f328a+id=7685412\r\nContent-ID: <http://tempuri.org/0>\r\nContent-Transfer-Encoding: 8bit\r\nContent-Type: application/xop+xml;charset=utf-8;type=\"application/soap+xml\"\r\n\r\n<s:Envelope xmlns:s=\"http://www.w3.org/2003/05/soap-envelope\" xmlns:a=\"http://www.w3.org/2005/08/addressing\"><s:Header><a:Action s:mustUnderstand=\"1\">http://CIS/BIR/PUBL/2014/07/IUslugaBIRzewnPubl/DaneSzukajPodmiotyResponse</a:Action><a:RelatesTo>urn:uuid:2beb8193-6616-42cd-aed5-d2174e047be1</a:RelatesTo></s:Header><s:Body><DaneSzukajPodmiotyResponse xmlns=\"http://CIS/BIR/PUBL/2014/07\"><DaneSzukajPodmiotyResult/></DaneSzukajPodmiotyResponse></s:Body></s:Envelope>\r\n--uuid:18e18dae-c6e6-44ff-bd8d-55fd420f328a+id=7685412--\r\n',NULL,'gus_69932da8398865.52036132',2,403,'TRANSPORT'),
(12,NULL,'2026-02-16 15:46:01','prod','nip','9540007714',NULL,NULL,0,NULL,'Nie znaleziono podmiotu w GUS.','HTTP','Cannot process the message because the content type \'text/xml; charset=utf-8\' was not the expected type \'multipart/related; type=\"application/xop+xml\"\'.','<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<env:Envelope xmlns:env=\"http://www.w3.org/2003/05/soap-envelope\" xmlns:ns1=\"http://CIS/BIR/PUBL/2014/07/DataContract\" xmlns:ns2=\"http://CIS/BIR/PUBL/2014/07\" xmlns:ns3=\"http://www.w3.org/2005/08/addressing\"><env:Header><ns3:Action env:mustUnderstand=\"true\">http://CIS/BIR/PUBL/2014/07/IUslugaBIRzewnPubl/DaneSzukajPodmioty</ns3:Action><ns3:To env:mustUnderstand=\"true\">https://wyszukiwarkaregon.stat.gov.pl/wsBIR/UslugaBIRzewnPubl.svc</ns3:To><ns3:MessageID env:mustUnderstand=\"true\">urn:uuid:eeb8bd7a-8cb0-400c-9b32-2f5e12efdd44</ns3:MessageID><wsa:ReplyTo xmlns:wsa=\"http://www.w3.org/2005/08/addressing\"><wsa:Address>http://www.w3.org/2005/08/addressing/anonymous</wsa:Address></wsa:ReplyTo><ns2:sid>r73d6eyvm4566rent9xp</ns2:sid></env:Header><env:Body><ns2:DaneSzukajPodmioty><ns2:pParametryWyszukiwania><ns1:Nip>9540007714</ns1:Nip></ns2:pParametryWyszukiwania></ns2:DaneSzukajPodmioty></env:Body></env:Envelope>\n','\r\n--uuid:18e18dae-c6e6-44ff-bd8d-55fd420f328a+id=7685497\r\nContent-ID: <http://tempuri.org/0>\r\nContent-Transfer-Encoding: 8bit\r\nContent-Type: application/xop+xml;charset=utf-8;type=\"application/soap+xml\"\r\n\r\n<s:Envelope xmlns:s=\"http://www.w3.org/2003/05/soap-envelope\" xmlns:a=\"http://www.w3.org/2005/08/addressing\"><s:Header><a:Action s:mustUnderstand=\"1\">http://CIS/BIR/PUBL/2014/07/IUslugaBIRzewnPubl/DaneSzukajPodmiotyResponse</a:Action><a:RelatesTo>urn:uuid:eeb8bd7a-8cb0-400c-9b32-2f5e12efdd44</a:RelatesTo></s:Header><s:Body><DaneSzukajPodmiotyResponse xmlns=\"http://CIS/BIR/PUBL/2014/07\"><DaneSzukajPodmiotyResult/></DaneSzukajPodmiotyResponse></s:Body></s:Envelope>\r\n--uuid:18e18dae-c6e6-44ff-bd8d-55fd420f328a+id=7685497--\r\n',NULL,'gus_69932da89f9c35.28730170',2,392,'TRANSPORT'),
(13,NULL,'2026-02-16 15:46:01','prod','nip','5790004488',NULL,NULL,0,NULL,'Nie znaleziono podmiotu w GUS.','HTTP','Cannot process the message because the content type \'text/xml; charset=utf-8\' was not the expected type \'multipart/related; type=\"application/xop+xml\"\'.','<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<env:Envelope xmlns:env=\"http://www.w3.org/2003/05/soap-envelope\" xmlns:ns1=\"http://CIS/BIR/PUBL/2014/07/DataContract\" xmlns:ns2=\"http://CIS/BIR/PUBL/2014/07\" xmlns:ns3=\"http://www.w3.org/2005/08/addressing\"><env:Header><ns3:Action env:mustUnderstand=\"true\">http://CIS/BIR/PUBL/2014/07/IUslugaBIRzewnPubl/DaneSzukajPodmioty</ns3:Action><ns3:To env:mustUnderstand=\"true\">https://wyszukiwarkaregon.stat.gov.pl/wsBIR/UslugaBIRzewnPubl.svc</ns3:To><ns3:MessageID env:mustUnderstand=\"true\">urn:uuid:c22764d1-212b-4892-a47e-1c7cb9005c75</ns3:MessageID><wsa:ReplyTo xmlns:wsa=\"http://www.w3.org/2005/08/addressing\"><wsa:Address>http://www.w3.org/2005/08/addressing/anonymous</wsa:Address></wsa:ReplyTo><ns2:sid>mwud7c4g3k98v949339p</ns2:sid></env:Header><env:Body><ns2:DaneSzukajPodmioty><ns2:pParametryWyszukiwania><ns1:Nip>5790004488</ns1:Nip></ns2:pParametryWyszukiwania></ns2:DaneSzukajPodmioty></env:Body></env:Envelope>\n','\r\n--uuid:18e18dae-c6e6-44ff-bd8d-55fd420f328a+id=7685596\r\nContent-ID: <http://tempuri.org/0>\r\nContent-Transfer-Encoding: 8bit\r\nContent-Type: application/xop+xml;charset=utf-8;type=\"application/soap+xml\"\r\n\r\n<s:Envelope xmlns:s=\"http://www.w3.org/2003/05/soap-envelope\" xmlns:a=\"http://www.w3.org/2005/08/addressing\"><s:Header><a:Action s:mustUnderstand=\"1\">http://CIS/BIR/PUBL/2014/07/IUslugaBIRzewnPubl/DaneSzukajPodmiotyResponse</a:Action><a:RelatesTo>urn:uuid:c22764d1-212b-4892-a47e-1c7cb9005c75</a:RelatesTo></s:Header><s:Body><DaneSzukajPodmiotyResponse xmlns=\"http://CIS/BIR/PUBL/2014/07\"><DaneSzukajPodmiotyResult/></DaneSzukajPodmiotyResponse></s:Body></s:Envelope>\r\n--uuid:18e18dae-c6e6-44ff-bd8d-55fd420f328a+id=7685596--\r\n',NULL,'gus_69932da90f1e36.19175755',2,395,'TRANSPORT'),
(14,NULL,'2026-02-16 15:51:54','prod','nip','5790004488','BIR11OsPrawna',200,1,NULL,NULL,NULL,NULL,'<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<env:Envelope xmlns:env=\"http://www.w3.org/2003/05/soap-envelope\" xmlns:ns1=\"http://CIS/BIR/PUBL/2014/07\" xmlns:ns2=\"http://www.w3.org/2005/08/addressing\"><env:Header><ns2:Action env:mustUnderstand=\"true\">http://CIS/BIR/PUBL/2014/07/IUslugaBIRzewnPubl/DanePobierzPelnyRaport</ns2:Action><ns2:To env:mustUnderstand=\"true\">https://wyszukiwarkaregon.stat.gov.pl/wsBIR/UslugaBIRzewnPubl.svc</ns2:To><ns2:MessageID env:mustUnderstand=\"true\">urn:uuid:948063d1-c49c-46f4-92e8-590a6fda1706</ns2:MessageID><wsa:ReplyTo xmlns:wsa=\"http://www.w3.org/2005/08/addressing\"><wsa:Address>http://www.w3.org/2005/08/addressing/anonymous</wsa:Address></wsa:ReplyTo><ns1:sid>8fgsk969msxw5dyr5x94</ns1:sid></env:Header><env:Body><ns1:DanePobierzPelnyRaport><ns1:pRegon>810528352</ns1:pRegon><ns1:pNazwaRaportu>BIR11OsPrawna</ns1:pNazwaRaportu></ns1:DanePobierzPelnyRaport></env:Body></env:Envelope>\n','\r\n--uuid:18e18dae-c6e6-44ff-bd8d-55fd420f328a+id=7744312\r\nContent-ID: <http://tempuri.org/0>\r\nContent-Transfer-Encoding: 8bit\r\nContent-Type: application/xop+xml;charset=utf-8;type=\"application/soap+xml\"\r\n\r\n<s:Envelope xmlns:s=\"http://www.w3.org/2003/05/soap-envelope\" xmlns:a=\"http://www.w3.org/2005/08/addressing\"><s:Header><a:Action s:mustUnderstand=\"1\">http://CIS/BIR/PUBL/2014/07/IUslugaBIRzewnPubl/DanePobierzPelnyRaportResponse</a:Action><a:RelatesTo>urn:uuid:948063d1-c49c-46f4-92e8-590a6fda1706</a:RelatesTo></s:Header><s:Body><DanePobierzPelnyRaportResponse xmlns=\"http://CIS/BIR/PUBL/2014/07\"><DanePobierzPelnyRaportResult>&lt;root&gt;&#xD;\n  &lt;dane&gt;&#xD;\n    &lt;ErrorCode&gt;4&lt;/ErrorCode&gt;&#xD;\n    &lt;ErrorMessagePl&gt;Nie znaleziono wpisu dla podanych kryteriów wyszukiwania.&lt;/ErrorMessagePl&gt;&#xD;\n    &lt;ErrorMessageEn&gt;No data found for the specified search criteria.&lt;/ErrorMessageEn&gt;&#xD;\n    &lt;pRegon&gt;810528352&lt;/pRegon&gt;&#xD;\n    &lt;Typ_podmiotu&gt;F&lt;/Typ_podmiotu&gt;&#xD;\n    &lt;Raport&gt;BIR11OsPrawna&lt;/Raport&gt;&#xD;\n  &lt;/dane&gt;&#xD;\n&lt;/root&gt;</DanePobierzPelnyRaportResult></DanePobierzPelnyRaportResponse></s:Body></s:Envelope>\r\n--uuid:18e18dae-c6e6-44ff-bd8d-55fd420f328a+id=7744312--\r\n','{\"nazwa\":\"Przedsiębiorstwo Handlowo Usługowe \\\"ELRO\\\" Elżbieta Pufal\",\"nip\":\"5790004488\",\"regon\":\"810528352\",\"krs\":\"\",\"wojewodztwo\":\"POMORSKIE\",\"powiat\":\"malborski\",\"gmina\":\"Malbork\",\"miejscowosc\":\"Grobelno\",\"ulica\":\"\",\"nr_nieruchomosci\":\"8\",\"nr_lokalu\":\"\",\"kod_pocztowy\":\"82-200\",\"poczta\":\"\",\"pkd_glowne\":\"\"}','gus_69932f09b58b44.34782733',2,742,NULL),
(15,NULL,'2026-02-16 15:51:54','prod','nip','5790004488','BIR11OsPrawna',200,1,NULL,NULL,NULL,NULL,'<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<env:Envelope xmlns:env=\"http://www.w3.org/2003/05/soap-envelope\" xmlns:ns1=\"http://CIS/BIR/PUBL/2014/07\" xmlns:ns2=\"http://www.w3.org/2005/08/addressing\"><env:Header><ns2:Action env:mustUnderstand=\"true\">http://CIS/BIR/PUBL/2014/07/IUslugaBIRzewnPubl/DanePobierzPelnyRaport</ns2:Action><ns2:To env:mustUnderstand=\"true\">https://wyszukiwarkaregon.stat.gov.pl/wsBIR/UslugaBIRzewnPubl.svc</ns2:To><ns2:MessageID env:mustUnderstand=\"true\">urn:uuid:7cc35517-ffc7-49df-8884-b86c148847cf</ns2:MessageID><wsa:ReplyTo xmlns:wsa=\"http://www.w3.org/2005/08/addressing\"><wsa:Address>http://www.w3.org/2005/08/addressing/anonymous</wsa:Address></wsa:ReplyTo><ns1:sid>232u2dz78wcydf7f3c8f</ns1:sid></env:Header><env:Body><ns1:DanePobierzPelnyRaport><ns1:pRegon>810528352</ns1:pRegon><ns1:pNazwaRaportu>BIR11OsPrawna</ns1:pNazwaRaportu></ns1:DanePobierzPelnyRaport></env:Body></env:Envelope>\n','\r\n--uuid:18e18dae-c6e6-44ff-bd8d-55fd420f328a+id=7744361\r\nContent-ID: <http://tempuri.org/0>\r\nContent-Transfer-Encoding: 8bit\r\nContent-Type: application/xop+xml;charset=utf-8;type=\"application/soap+xml\"\r\n\r\n<s:Envelope xmlns:s=\"http://www.w3.org/2003/05/soap-envelope\" xmlns:a=\"http://www.w3.org/2005/08/addressing\"><s:Header><a:Action s:mustUnderstand=\"1\">http://CIS/BIR/PUBL/2014/07/IUslugaBIRzewnPubl/DanePobierzPelnyRaportResponse</a:Action><a:RelatesTo>urn:uuid:7cc35517-ffc7-49df-8884-b86c148847cf</a:RelatesTo></s:Header><s:Body><DanePobierzPelnyRaportResponse xmlns=\"http://CIS/BIR/PUBL/2014/07\"><DanePobierzPelnyRaportResult>&lt;root&gt;&#xD;\n  &lt;dane&gt;&#xD;\n    &lt;ErrorCode&gt;4&lt;/ErrorCode&gt;&#xD;\n    &lt;ErrorMessagePl&gt;Nie znaleziono wpisu dla podanych kryteriów wyszukiwania.&lt;/ErrorMessagePl&gt;&#xD;\n    &lt;ErrorMessageEn&gt;No data found for the specified search criteria.&lt;/ErrorMessageEn&gt;&#xD;\n    &lt;pRegon&gt;810528352&lt;/pRegon&gt;&#xD;\n    &lt;Typ_podmiotu&gt;F&lt;/Typ_podmiotu&gt;&#xD;\n    &lt;Raport&gt;BIR11OsPrawna&lt;/Raport&gt;&#xD;\n  &lt;/dane&gt;&#xD;\n&lt;/root&gt;</DanePobierzPelnyRaportResult></DanePobierzPelnyRaportResponse></s:Body></s:Envelope>\r\n--uuid:18e18dae-c6e6-44ff-bd8d-55fd420f328a+id=7744361--\r\n','{\"nazwa\":\"Przedsiębiorstwo Handlowo Usługowe \\\"ELRO\\\" Elżbieta Pufal\",\"nip\":\"5790004488\",\"regon\":\"810528352\",\"krs\":\"\",\"wojewodztwo\":\"POMORSKIE\",\"powiat\":\"malborski\",\"gmina\":\"Malbork\",\"miejscowosc\":\"Grobelno\",\"ulica\":\"\",\"nr_nieruchomosci\":\"8\",\"nr_lokalu\":\"\",\"kod_pocztowy\":\"82-200\",\"poczta\":\"\",\"pkd_glowne\":\"\"}','gus_69932f09b58b42.35745187',2,1000,NULL),
(16,NULL,'2026-02-16 15:51:55','prod','nip','5790004488','BIR11OsPrawna',200,1,NULL,NULL,NULL,NULL,'<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<env:Envelope xmlns:env=\"http://www.w3.org/2003/05/soap-envelope\" xmlns:ns1=\"http://CIS/BIR/PUBL/2014/07\" xmlns:ns2=\"http://www.w3.org/2005/08/addressing\"><env:Header><ns2:Action env:mustUnderstand=\"true\">http://CIS/BIR/PUBL/2014/07/IUslugaBIRzewnPubl/DanePobierzPelnyRaport</ns2:Action><ns2:To env:mustUnderstand=\"true\">https://wyszukiwarkaregon.stat.gov.pl/wsBIR/UslugaBIRzewnPubl.svc</ns2:To><ns2:MessageID env:mustUnderstand=\"true\">urn:uuid:a20a16cc-d793-43bc-b7da-cf10dacedcec</ns2:MessageID><wsa:ReplyTo xmlns:wsa=\"http://www.w3.org/2005/08/addressing\"><wsa:Address>http://www.w3.org/2005/08/addressing/anonymous</wsa:Address></wsa:ReplyTo><ns1:sid>zcux822yecr9sms66bsz</ns1:sid></env:Header><env:Body><ns1:DanePobierzPelnyRaport><ns1:pRegon>810528352</ns1:pRegon><ns1:pNazwaRaportu>BIR11OsPrawna</ns1:pNazwaRaportu></ns1:DanePobierzPelnyRaport></env:Body></env:Envelope>\n','\r\n--uuid:18e18dae-c6e6-44ff-bd8d-55fd420f328a+id=7744437\r\nContent-ID: <http://tempuri.org/0>\r\nContent-Transfer-Encoding: 8bit\r\nContent-Type: application/xop+xml;charset=utf-8;type=\"application/soap+xml\"\r\n\r\n<s:Envelope xmlns:s=\"http://www.w3.org/2003/05/soap-envelope\" xmlns:a=\"http://www.w3.org/2005/08/addressing\"><s:Header><a:Action s:mustUnderstand=\"1\">http://CIS/BIR/PUBL/2014/07/IUslugaBIRzewnPubl/DanePobierzPelnyRaportResponse</a:Action><a:RelatesTo>urn:uuid:a20a16cc-d793-43bc-b7da-cf10dacedcec</a:RelatesTo></s:Header><s:Body><DanePobierzPelnyRaportResponse xmlns=\"http://CIS/BIR/PUBL/2014/07\"><DanePobierzPelnyRaportResult>&lt;root&gt;&#xD;\n  &lt;dane&gt;&#xD;\n    &lt;ErrorCode&gt;4&lt;/ErrorCode&gt;&#xD;\n    &lt;ErrorMessagePl&gt;Nie znaleziono wpisu dla podanych kryteriów wyszukiwania.&lt;/ErrorMessagePl&gt;&#xD;\n    &lt;ErrorMessageEn&gt;No data found for the specified search criteria.&lt;/ErrorMessageEn&gt;&#xD;\n    &lt;pRegon&gt;810528352&lt;/pRegon&gt;&#xD;\n    &lt;Typ_podmiotu&gt;F&lt;/Typ_podmiotu&gt;&#xD;\n    &lt;Raport&gt;BIR11OsPrawna&lt;/Raport&gt;&#xD;\n  &lt;/dane&gt;&#xD;\n&lt;/root&gt;</DanePobierzPelnyRaportResult></DanePobierzPelnyRaportResponse></s:Body></s:Envelope>\r\n--uuid:18e18dae-c6e6-44ff-bd8d-55fd420f328a+id=7744437--\r\n','{\"nazwa\":\"Przedsiębiorstwo Handlowo Usługowe \\\"ELRO\\\" Elżbieta Pufal\",\"nip\":\"5790004488\",\"regon\":\"810528352\",\"krs\":\"\",\"wojewodztwo\":\"POMORSKIE\",\"powiat\":\"malborski\",\"gmina\":\"Malbork\",\"miejscowosc\":\"Grobelno\",\"ulica\":\"\",\"nr_nieruchomosci\":\"8\",\"nr_lokalu\":\"\",\"kod_pocztowy\":\"82-200\",\"poczta\":\"\",\"pkd_glowne\":\"\"}','gus_69932f09e13609.39946607',2,1151,NULL),
(17,NULL,'2026-02-16 15:52:01','prod','nip','5790004488','DaneSzukajPodmioty',NULL,0,'login_sid','Brak SID w odpowiedzi GUS.',NULL,NULL,NULL,NULL,NULL,'gus_69932f11050146.02732266',1,86,'SOAP_FAULT'),
(18,NULL,'2026-02-16 15:52:25','prod','nip','5790004488','DaneSzukajPodmioty',NULL,0,'login_sid','Brak SID w odpowiedzi GUS.',NULL,NULL,NULL,NULL,NULL,'gus_69932f291073a9.26216085',1,100,'SOAP_FAULT'),
(19,NULL,'2026-02-16 15:52:25','prod','nip','5790004488','BIR11OsPrawna',200,1,NULL,NULL,NULL,NULL,'<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<env:Envelope xmlns:env=\"http://www.w3.org/2003/05/soap-envelope\" xmlns:ns1=\"http://CIS/BIR/PUBL/2014/07\" xmlns:ns2=\"http://www.w3.org/2005/08/addressing\"><env:Header><ns2:Action env:mustUnderstand=\"true\">http://CIS/BIR/PUBL/2014/07/IUslugaBIRzewnPubl/DanePobierzPelnyRaport</ns2:Action><ns2:To env:mustUnderstand=\"true\">https://wyszukiwarkaregon.stat.gov.pl/wsBIR/UslugaBIRzewnPubl.svc</ns2:To><ns2:MessageID env:mustUnderstand=\"true\">urn:uuid:f65b3ff3-889d-43d5-9af6-1fadd8b021d8</ns2:MessageID><wsa:ReplyTo xmlns:wsa=\"http://www.w3.org/2005/08/addressing\"><wsa:Address>http://www.w3.org/2005/08/addressing/anonymous</wsa:Address></wsa:ReplyTo><ns1:sid>78vs7w9z4224bhe5553p</ns1:sid></env:Header><env:Body><ns1:DanePobierzPelnyRaport><ns1:pRegon>810528352</ns1:pRegon><ns1:pNazwaRaportu>BIR11OsPrawna</ns1:pNazwaRaportu></ns1:DanePobierzPelnyRaport></env:Body></env:Envelope>\n','\r\n--uuid:18e18dae-c6e6-44ff-bd8d-55fd420f328a+id=7749754\r\nContent-ID: <http://tempuri.org/0>\r\nContent-Transfer-Encoding: 8bit\r\nContent-Type: application/xop+xml;charset=utf-8;type=\"application/soap+xml\"\r\n\r\n<s:Envelope xmlns:s=\"http://www.w3.org/2003/05/soap-envelope\" xmlns:a=\"http://www.w3.org/2005/08/addressing\"><s:Header><a:Action s:mustUnderstand=\"1\">http://CIS/BIR/PUBL/2014/07/IUslugaBIRzewnPubl/DanePobierzPelnyRaportResponse</a:Action><a:RelatesTo>urn:uuid:f65b3ff3-889d-43d5-9af6-1fadd8b021d8</a:RelatesTo></s:Header><s:Body><DanePobierzPelnyRaportResponse xmlns=\"http://CIS/BIR/PUBL/2014/07\"><DanePobierzPelnyRaportResult>&lt;root&gt;&#xD;\n  &lt;dane&gt;&#xD;\n    &lt;ErrorCode&gt;4&lt;/ErrorCode&gt;&#xD;\n    &lt;ErrorMessagePl&gt;Nie znaleziono wpisu dla podanych kryteriów wyszukiwania.&lt;/ErrorMessagePl&gt;&#xD;\n    &lt;ErrorMessageEn&gt;No data found for the specified search criteria.&lt;/ErrorMessageEn&gt;&#xD;\n    &lt;pRegon&gt;810528352&lt;/pRegon&gt;&#xD;\n    &lt;Typ_podmiotu&gt;F&lt;/Typ_podmiotu&gt;&#xD;\n    &lt;Raport&gt;BIR11OsPrawna&lt;/Raport&gt;&#xD;\n  &lt;/dane&gt;&#xD;\n&lt;/root&gt;</DanePobierzPelnyRaportResult></DanePobierzPelnyRaportResponse></s:Body></s:Envelope>\r\n--uuid:18e18dae-c6e6-44ff-bd8d-55fd420f328a+id=7749754--\r\n','{\"nazwa\":\"Przedsiębiorstwo Handlowo Usługowe \\\"ELRO\\\" Elżbieta Pufal\",\"nip\":\"5790004488\",\"regon\":\"810528352\",\"krs\":\"\",\"wojewodztwo\":\"POMORSKIE\",\"powiat\":\"malborski\",\"gmina\":\"Malbork\",\"miejscowosc\":\"Grobelno\",\"ulica\":\"\",\"nr_nieruchomosci\":\"8\",\"nr_lokalu\":\"\",\"kod_pocztowy\":\"82-200\",\"poczta\":\"\",\"pkd_glowne\":\"\"}','gus_69932f28d71158.61151056',2,737,NULL),
(20,NULL,'2026-02-16 15:54:15','prod','nip','5790004488','DaneSzukajPodmioty',200,1,NULL,NULL,NULL,NULL,'<?xml version=\"1.0\" encoding=\"utf-8\"?><soap:Envelope xmlns:soap=\"http://www.w3.org/2003/05/soap-envelope\" xmlns:wsa=\"http://www.w3.org/2005/08/addressing\" xmlns:pub=\"http://CIS/BIR/PUBL/2014/07\" xmlns:dat=\"http://CIS/BIR/PUBL/2014/07/DataContract\"><soap:Header><wsa:Action soap:mustUnderstand=\"true\">http://CIS/BIR/PUBL/2014/07/IUslugaBIRzewnPubl/DaneSzukajPodmioty</wsa:Action><wsa:To soap:mustUnderstand=\"true\">https://wyszukiwarkaregon.stat.gov.pl/wsBIR/UslugaBIRzewnPubl.svc</wsa:To><wsa:MessageID soap:mustUnderstand=\"true\">urn:uuid:388a40f5-f0cf-4ba1-b515-8398b48aeafa</wsa:MessageID><wsa:ReplyTo><wsa:Address>http://www.w3.org/2005/08/addressing/anonymous</wsa:Address></wsa:ReplyTo><pub:sid>nkm5c82xeng9254speyp</pub:sid></soap:Header><soap:Body><pub:DaneSzukajPodmioty><pub:pParametryWyszukiwania><dat:Nip>5790004488</dat:Nip></pub:pParametryWyszukiwania></pub:DaneSzukajPodmioty></soap:Body></soap:Envelope>','\r\n--uuid:18e18dae-c6e6-44ff-bd8d-55fd420f328a+id=7767999\r\nContent-ID: <http://tempuri.org/0>\r\nContent-Transfer-Encoding: 8bit\r\nContent-Type: application/xop+xml;charset=utf-8;type=\"application/soap+xml\"\r\n\r\n<s:Envelope xmlns:s=\"http://www.w3.org/2003/05/soap-envelope\" xmlns:a=\"http://www.w3.org/2005/08/addressing\"><s:Header><a:Action s:mustUnderstand=\"1\">http://CIS/BIR/PUBL/2014/07/IUslugaBIRzewnPubl/DaneSzukajPodmiotyResponse</a:Action><a:RelatesTo>urn:uuid:388a40f5-f0cf-4ba1-b515-8398b48aeafa</a:RelatesTo></s:Header><s:Body><DaneSzukajPodmiotyResponse xmlns=\"http://CIS/BIR/PUBL/2014/07\"><DaneSzukajPodmiotyResult>&lt;root&gt;&#xD;\n  &lt;dane&gt;&#xD;\n    &lt;Regon&gt;810528352&lt;/Regon&gt;&#xD;\n    &lt;Nip&gt;5790004488&lt;/Nip&gt;&#xD;\n    &lt;StatusNip /&gt;&#xD;\n    &lt;Nazwa&gt;Przedsiębiorstwo Handlowo Usługowe \"ELRO\" Elżbieta Pufal&lt;/Nazwa&gt;&#xD;\n    &lt;Wojewodztwo&gt;POMORSKIE&lt;/Wojewodztwo&gt;&#xD;\n    &lt;Powiat&gt;malborski&lt;/Powiat&gt;&#xD;\n    &lt;Gmina&gt;Malbork&lt;/Gmina&gt;&#xD;\n    &lt;Miejscowosc&gt;Grobelno&lt;/Miejscowosc&gt;&#xD;\n    &lt;KodPocztowy&gt;82-200&lt;/KodPocztowy&gt;&#xD;\n    &lt;Ulica /&gt;&#xD;\n    &lt;NrNieruchomosci&gt;8&lt;/NrNieruchomosci&gt;&#xD;\n    &lt;NrLokalu /&gt;&#xD;\n    &lt;Typ&gt;F&lt;/Typ&gt;&#xD;\n    &lt;SilosID&gt;1&lt;/SilosID&gt;&#xD;\n    &lt;DataZakonczeniaDzialalnosci /&gt;&#xD;\n    &lt;MiejscowoscPoczty /&gt;&#xD;\n  &lt;/dane&gt;&#xD;\n&lt;/root&gt;</DaneSzukajPodmiotyResult></DaneSzukajPodmiotyResponse></s:Body></s:Envelope>\r\n--uuid:18e18dae-c6e6-44ff-bd8d-55fd420f328a+id=7767999--\r\n','{\"name\":\"Przedsiębiorstwo Handlowo Usługowe \\\"ELRO\\\" Elżbieta Pufal\",\"nip\":\"5790004488\",\"regon\":\"810528352\",\"krs\":\"\",\"address_street\":\"8\",\"address_postal\":\"82-200\",\"address_city\":\"Grobelno\",\"legal_form\":\"\"}','gus_69932f97582ad3.84892345',1,146,NULL),
(21,NULL,'2026-02-16 15:54:34','prod','nip','5260251049','BIR11OsPrawna',200,1,NULL,NULL,NULL,NULL,'<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<env:Envelope xmlns:env=\"http://www.w3.org/2003/05/soap-envelope\" xmlns:ns1=\"http://CIS/BIR/PUBL/2014/07\" xmlns:ns2=\"http://www.w3.org/2005/08/addressing\"><env:Header><ns2:Action env:mustUnderstand=\"true\">http://CIS/BIR/PUBL/2014/07/IUslugaBIRzewnPubl/DanePobierzPelnyRaport</ns2:Action><ns2:To env:mustUnderstand=\"true\">https://wyszukiwarkaregon.stat.gov.pl/wsBIR/UslugaBIRzewnPubl.svc</ns2:To><ns2:MessageID env:mustUnderstand=\"true\">urn:uuid:52515de8-77d8-4600-a948-ffdf9edfc3d2</ns2:MessageID><wsa:ReplyTo xmlns:wsa=\"http://www.w3.org/2005/08/addressing\"><wsa:Address>http://www.w3.org/2005/08/addressing/anonymous</wsa:Address></wsa:ReplyTo><ns1:sid>rhw93v75mh65kthppfkf</ns1:sid></env:Header><env:Body><ns1:DanePobierzPelnyRaport><ns1:pRegon>010001345</ns1:pRegon><ns1:pNazwaRaportu>BIR11OsPrawna</ns1:pNazwaRaportu></ns1:DanePobierzPelnyRaport></env:Body></env:Envelope>\n','\r\n--uuid:18e18dae-c6e6-44ff-bd8d-55fd420f328a+id=7771363\r\nContent-ID: <http://tempuri.org/0>\r\nContent-Transfer-Encoding: 8bit\r\nContent-Type: application/xop+xml;charset=utf-8;type=\"application/soap+xml\"\r\n\r\n<s:Envelope xmlns:s=\"http://www.w3.org/2003/05/soap-envelope\" xmlns:a=\"http://www.w3.org/2005/08/addressing\"><s:Header><a:Action s:mustUnderstand=\"1\">http://CIS/BIR/PUBL/2014/07/IUslugaBIRzewnPubl/DanePobierzPelnyRaportResponse</a:Action><a:RelatesTo>urn:uuid:52515de8-77d8-4600-a948-ffdf9edfc3d2</a:RelatesTo></s:Header><s:Body><DanePobierzPelnyRaportResponse xmlns=\"http://CIS/BIR/PUBL/2014/07\"><DanePobierzPelnyRaportResult>&lt;root&gt;&#xD;\n  &lt;dane&gt;&#xD;\n    &lt;praw_regon9&gt;010001345&lt;/praw_regon9&gt;&#xD;\n    &lt;praw_nip&gt;5260251049&lt;/praw_nip&gt;&#xD;\n    &lt;praw_statusNip /&gt;&#xD;\n    &lt;praw_nazwa&gt;POWSZECHNY ZAKŁAD UBEZPIECZEŃ SPÓŁKA AKCYJNA&lt;/praw_nazwa&gt;&#xD;\n    &lt;praw_nazwaSkrocona /&gt;&#xD;\n    &lt;praw_numerWRejestrzeEwidencji&gt;0000009831&lt;/praw_numerWRejestrzeEwidencji&gt;&#xD;\n    &lt;praw_dataWpisuDoRejestruEwidencji&gt;2001-04-30&lt;/praw_dataWpisuDoRejestruEwidencji&gt;&#xD;\n    &lt;praw_dataPowstania&gt;1992-01-15&lt;/praw_dataPowstania&gt;&#xD;\n    &lt;praw_dataRozpoczeciaDzialalnosci&gt;1992-01-15&lt;/praw_dataRozpoczeciaDzialalnosci&gt;&#xD;\n    &lt;praw_dataWpisuDoRegon /&gt;&#xD;\n    &lt;praw_dataZawieszeniaDzialalnosci /&gt;&#xD;\n    &lt;praw_dataWznowieniaDzialalnosci /&gt;&#xD;\n    &lt;praw_dataZaistnieniaZmiany&gt;2026-01-22&lt;/praw_dataZaistnieniaZmiany&gt;&#xD;\n    &lt;praw_dataZakonczeniaDzialalnosci /&gt;&#xD;\n    &lt;praw_dataSkresleniaZRegon /&gt;&#xD;\n    &lt;praw_dataOrzeczeniaOUpadlosci /&gt;&#xD;\n    &lt;praw_dataZakonczeniaPostepowaniaUpadlosciowego /&gt;&#xD;\n    &lt;praw_adSiedzKraj_Symbol&gt;PL&lt;/praw_adSiedzKraj_Symbol&gt;&#xD;\n    &lt;praw_adSiedzWojewodztwo_Symbol&gt;14&lt;/praw_adSiedzWojewodztwo_Symbol&gt;&#xD;\n    &lt;praw_adSiedzPowiat_Symbol&gt;65&lt;/praw_adSiedzPowiat_Symbol&gt;&#xD;\n    &lt;praw_adSiedzGmina_Symbol&gt;188&lt;/praw_adSiedzGmina_Symbol&gt;&#xD;\n    &lt;praw_adSiedzKodPocztowy&gt;00843&lt;/praw_adSiedzKodPocztowy&gt;&#xD;\n    &lt;praw_adSiedzMiejscowoscPoczty_Symbol /&gt;&#xD;\n    &lt;praw_adSiedzMiejscowosc_Symbol&gt;0919884&lt;/praw_adSiedzMiejscowosc_Symbol&gt;&#xD;\n    &lt;praw_adSiedzUlica_Symbol&gt;45433&lt;/praw_adSiedzUlica_Symbol&gt;&#xD;\n    &lt;praw_adSiedzNumerNieruchomosci&gt;4&lt;/praw_adSiedzNumerNieruchomosci&gt;&#xD;\n    &lt;praw_adSiedzNumerLokalu /&gt;&#xD;\n    &lt;praw_adSiedzNietypoweMiejsceLokalizacji /&gt;&#xD;\n    &lt;praw_numerTelefonu /&gt;&#xD;\n    &lt;praw_numerWewnetrznyTelefonu /&gt;&#xD;\n    &lt;praw_numerFaksu /&gt;&#xD;\n    &lt;praw_adresEmail /&gt;&#xD;\n    &lt;praw_adresStronyinternetowej /&gt;&#xD;\n    &lt;praw_adSiedzKraj_Nazwa&gt;POLSKA&lt;/praw_adSiedzKraj_Nazwa&gt;&#xD;\n    &lt;praw_adSiedzWojewodztwo_Nazwa&gt;MAZOWIECKIE&lt;/praw_adSiedzWojewodztwo_Nazwa&gt;&#xD;\n    &lt;praw_adSiedzPowiat_Nazwa&gt;Warszawa&lt;/praw_adSiedzPowiat_Nazwa&gt;&#xD;\n    &lt;praw_adSiedzGmina_Nazwa&gt;Wola&lt;/praw_adSiedzGmina_Nazwa&gt;&#xD;\n    &lt;praw_adSiedzMiejscowosc_Nazwa&gt;Warszawa&lt;/praw_adSiedzMiejscowosc_Nazwa&gt;&#xD;\n    &lt;praw_adSiedzMiejscowoscPoczty_Nazwa /&gt;&#xD;\n    &lt;praw_adSiedzUlica_Nazwa&gt;Rondo Ignacego Daszyńskiego&lt;/praw_adSiedzUlica_Nazwa&gt;&#xD;\n    &lt;praw_podstawowaFormaPrawna_Symbol&gt;1&lt;/praw_podstawowaFormaPrawna_Symbol&gt;&#xD;\n    &lt;praw_szczegolnaFormaPrawna_Symbol&gt;116&lt;/praw_szczegolnaFormaPrawna_Symbol&gt;&#xD;\n    &lt;praw_formaFinansowania_Symbol&gt;1&lt;/praw_formaFinansowania_Symbol&gt;&#xD;\n    &lt;praw_formaWlasnosci_Symbol&gt;131&lt;/praw_formaWlasnosci_Symbol&gt;&#xD;\n    &lt;praw_organZalozycielski_Symbol /&gt;&#xD;\n    &lt;praw_organRejestrowy_Symbol&gt;071010050&lt;/praw_organRejestrowy_Symbol&gt;&#xD;\n    &lt;praw_rodzajRejestruEwidencji_Symbol&gt;138&lt;/praw_rodzajRejestruEwidencji_Symbol&gt;&#xD;\n    &lt;praw_podstawowaFormaPrawna_Nazwa&gt;OSOBA PRAWNA&lt;/praw_podstawowaFormaPrawna_Nazwa&gt;&#xD;\n    &lt;praw_szczegolnaFormaPrawna_Nazwa&gt;SPÓŁKI AKCYJNE&lt;/praw_szczegolnaFormaPrawna_Nazwa&gt;&#xD;\n    &lt;praw_formaFinansowania_Nazwa&gt;JEDNOSTKA SAMOFINANSUJĄCA NIE BĘDĄCA JEDNOSTKĄ BUDŻETOWĄ LUB SAMORZĄDOWYM ZAKŁADEM BUDŻETOWYM&lt;/praw_formaFinansowania_Nazwa&gt;&#xD;\n    &lt;praw_formaWlasnosci_Nazwa&gt;WŁASNOŚĆ MIESZANA MIĘDZY SEKTORAMI Z PRZEWAGĄ WŁASNOŚCI SEKTORA PUBLICZNEGO, W TYM Z PRZEWAGĄ WŁASNOŚCI SKARBU PAŃSTWA&lt;/praw_formaWlasnosci_Nazwa&gt;&#xD;\n    &lt;praw_organZalozycielski_Nazwa /&gt;&#xD;\n    &lt;praw_organRejestrowy_Nazwa&gt;SĄD REJONOWY DLA M.ST.WARSZAWY W WARSZAWIE,XIII WYDZIAŁ GOSPODARCZY KRAJOWEGO REJESTRU SĄDOWEGO&lt;/praw_organRejestrowy_Nazwa&gt;&#xD;\n    &lt;praw_rodzajRejestruEwidencji_Nazwa&gt;REJESTR PRZEDSIĘBIORCÓW&lt;/praw_rodzajRejestruEwidencji_Nazwa&gt;&#xD;\n    &lt;praw_liczbaJednLokalnych&gt;9&lt;/praw_liczbaJednLokalnych&gt;&#xD;\n  &lt;/dane&gt;&#xD;\n&lt;/root&gt;</DanePobierzPelnyRaportResult></DanePobierzPelnyRaportResponse></s:Body></s:Envelope>\r\n--uuid:18e18dae-c6e6-44ff-bd8d-55fd420f328a+id=7771363--\r\n','{\"nazwa\":\"POWSZECHNY ZAKŁAD UBEZPIECZEŃ SPÓŁKA AKCYJNA\",\"nip\":\"5260251049\",\"regon\":\"010001345\",\"krs\":\"\",\"wojewodztwo\":\"MAZOWIECKIE\",\"powiat\":\"Warszawa\",\"gmina\":\"Wola\",\"miejscowosc\":\"Warszawa\",\"ulica\":\"Rondo Ignacego Daszyńskiego\",\"nr_nieruchomosci\":\"4\",\"nr_lokalu\":\"\",\"kod_pocztowy\":\"00-843\",\"poczta\":\"\",\"pkd_glowne\":\"\"}','gus_69932fa95e7df6.68206261',2,893,NULL),
(22,NULL,'2026-02-16 15:54:34','prod','nip','5790004488','BIR11OsPrawna',200,1,NULL,NULL,NULL,NULL,'<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<env:Envelope xmlns:env=\"http://www.w3.org/2003/05/soap-envelope\" xmlns:ns1=\"http://CIS/BIR/PUBL/2014/07\" xmlns:ns2=\"http://www.w3.org/2005/08/addressing\"><env:Header><ns2:Action env:mustUnderstand=\"true\">http://CIS/BIR/PUBL/2014/07/IUslugaBIRzewnPubl/DanePobierzPelnyRaport</ns2:Action><ns2:To env:mustUnderstand=\"true\">https://wyszukiwarkaregon.stat.gov.pl/wsBIR/UslugaBIRzewnPubl.svc</ns2:To><ns2:MessageID env:mustUnderstand=\"true\">urn:uuid:b7cd8722-148e-4236-b66d-a918bd9ae4ea</ns2:MessageID><wsa:ReplyTo xmlns:wsa=\"http://www.w3.org/2005/08/addressing\"><wsa:Address>http://www.w3.org/2005/08/addressing/anonymous</wsa:Address></wsa:ReplyTo><ns1:sid>m5h77sp9y6wf7z89t7z4</ns1:sid></env:Header><env:Body><ns1:DanePobierzPelnyRaport><ns1:pRegon>810528352</ns1:pRegon><ns1:pNazwaRaportu>BIR11OsPrawna</ns1:pNazwaRaportu></ns1:DanePobierzPelnyRaport></env:Body></env:Envelope>\n','\r\n--uuid:18e18dae-c6e6-44ff-bd8d-55fd420f328a+id=7771409\r\nContent-ID: <http://tempuri.org/0>\r\nContent-Transfer-Encoding: 8bit\r\nContent-Type: application/xop+xml;charset=utf-8;type=\"application/soap+xml\"\r\n\r\n<s:Envelope xmlns:s=\"http://www.w3.org/2003/05/soap-envelope\" xmlns:a=\"http://www.w3.org/2005/08/addressing\"><s:Header><a:Action s:mustUnderstand=\"1\">http://CIS/BIR/PUBL/2014/07/IUslugaBIRzewnPubl/DanePobierzPelnyRaportResponse</a:Action><a:RelatesTo>urn:uuid:b7cd8722-148e-4236-b66d-a918bd9ae4ea</a:RelatesTo></s:Header><s:Body><DanePobierzPelnyRaportResponse xmlns=\"http://CIS/BIR/PUBL/2014/07\"><DanePobierzPelnyRaportResult>&lt;root&gt;&#xD;\n  &lt;dane&gt;&#xD;\n    &lt;ErrorCode&gt;4&lt;/ErrorCode&gt;&#xD;\n    &lt;ErrorMessagePl&gt;Nie znaleziono wpisu dla podanych kryteriów wyszukiwania.&lt;/ErrorMessagePl&gt;&#xD;\n    &lt;ErrorMessageEn&gt;No data found for the specified search criteria.&lt;/ErrorMessageEn&gt;&#xD;\n    &lt;pRegon&gt;810528352&lt;/pRegon&gt;&#xD;\n    &lt;Typ_podmiotu&gt;F&lt;/Typ_podmiotu&gt;&#xD;\n    &lt;Raport&gt;BIR11OsPrawna&lt;/Raport&gt;&#xD;\n  &lt;/dane&gt;&#xD;\n&lt;/root&gt;</DanePobierzPelnyRaportResult></DanePobierzPelnyRaportResponse></s:Body></s:Envelope>\r\n--uuid:18e18dae-c6e6-44ff-bd8d-55fd420f328a+id=7771409--\r\n','{\"nazwa\":\"Przedsiębiorstwo Handlowo Usługowe \\\"ELRO\\\" Elżbieta Pufal\",\"nip\":\"5790004488\",\"regon\":\"810528352\",\"krs\":\"\",\"wojewodztwo\":\"POMORSKIE\",\"powiat\":\"malborski\",\"gmina\":\"Malbork\",\"miejscowosc\":\"Grobelno\",\"ulica\":\"\",\"nr_nieruchomosci\":\"8\",\"nr_lokalu\":\"\",\"kod_pocztowy\":\"82-200\",\"poczta\":\"\",\"pkd_glowne\":\"\"}','gus_69932fa9821d50.74148149',2,1089,NULL),
(23,NULL,'2026-02-16 15:54:50','prod','nip','9540007714','BIR11OsPrawna',200,1,NULL,NULL,NULL,NULL,'<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<env:Envelope xmlns:env=\"http://www.w3.org/2003/05/soap-envelope\" xmlns:ns1=\"http://CIS/BIR/PUBL/2014/07\" xmlns:ns2=\"http://www.w3.org/2005/08/addressing\"><env:Header><ns2:Action env:mustUnderstand=\"true\">http://CIS/BIR/PUBL/2014/07/IUslugaBIRzewnPubl/DanePobierzPelnyRaport</ns2:Action><ns2:To env:mustUnderstand=\"true\">https://wyszukiwarkaregon.stat.gov.pl/wsBIR/UslugaBIRzewnPubl.svc</ns2:To><ns2:MessageID env:mustUnderstand=\"true\">urn:uuid:8f787490-1a9a-4e64-902e-6e998fadd378</ns2:MessageID><wsa:ReplyTo xmlns:wsa=\"http://www.w3.org/2005/08/addressing\"><wsa:Address>http://www.w3.org/2005/08/addressing/anonymous</wsa:Address></wsa:ReplyTo><ns1:sid>7xv42h53wmr755ncg6r4</ns1:sid></env:Header><env:Body><ns1:DanePobierzPelnyRaport><ns1:pRegon>272051357</ns1:pRegon><ns1:pNazwaRaportu>BIR11OsPrawna</ns1:pNazwaRaportu></ns1:DanePobierzPelnyRaport></env:Body></env:Envelope>\n','\r\n--uuid:18e18dae-c6e6-44ff-bd8d-55fd420f328a+id=7774465\r\nContent-ID: <http://tempuri.org/0>\r\nContent-Transfer-Encoding: 8bit\r\nContent-Type: application/xop+xml;charset=utf-8;type=\"application/soap+xml\"\r\n\r\n<s:Envelope xmlns:s=\"http://www.w3.org/2003/05/soap-envelope\" xmlns:a=\"http://www.w3.org/2005/08/addressing\"><s:Header><a:Action s:mustUnderstand=\"1\">http://CIS/BIR/PUBL/2014/07/IUslugaBIRzewnPubl/DanePobierzPelnyRaportResponse</a:Action><a:RelatesTo>urn:uuid:8f787490-1a9a-4e64-902e-6e998fadd378</a:RelatesTo></s:Header><s:Body><DanePobierzPelnyRaportResponse xmlns=\"http://CIS/BIR/PUBL/2014/07\"><DanePobierzPelnyRaportResult>&lt;root&gt;&#xD;\n  &lt;dane&gt;&#xD;\n    &lt;praw_regon9&gt;272051357&lt;/praw_regon9&gt;&#xD;\n    &lt;praw_nip&gt;9540007714&lt;/praw_nip&gt;&#xD;\n    &lt;praw_statusNip /&gt;&#xD;\n    &lt;praw_nazwa&gt;\"PRZEDSIĘBIORSTWO-HANDLOWO-USŁUGOWE ENERGOZBYT ŻURADZKI SPÓŁKA JAWNA\"&lt;/praw_nazwa&gt;&#xD;\n    &lt;praw_nazwaSkrocona&gt;P.H.U.ENERGOZBYT S.J.&lt;/praw_nazwaSkrocona&gt;&#xD;\n    &lt;praw_numerWRejestrzeEwidencji&gt;0000205820&lt;/praw_numerWRejestrzeEwidencji&gt;&#xD;\n    &lt;praw_dataWpisuDoRejestruEwidencji&gt;2004-04-30&lt;/praw_dataWpisuDoRejestruEwidencji&gt;&#xD;\n    &lt;praw_dataPowstania&gt;1994-02-01&lt;/praw_dataPowstania&gt;&#xD;\n    &lt;praw_dataRozpoczeciaDzialalnosci&gt;1994-02-01&lt;/praw_dataRozpoczeciaDzialalnosci&gt;&#xD;\n    &lt;praw_dataWpisuDoRegon /&gt;&#xD;\n    &lt;praw_dataZawieszeniaDzialalnosci /&gt;&#xD;\n    &lt;praw_dataWznowieniaDzialalnosci /&gt;&#xD;\n    &lt;praw_dataZaistnieniaZmiany&gt;2004-05-14&lt;/praw_dataZaistnieniaZmiany&gt;&#xD;\n    &lt;praw_dataZakonczeniaDzialalnosci&gt;2016-04-16&lt;/praw_dataZakonczeniaDzialalnosci&gt;&#xD;\n    &lt;praw_dataSkresleniaZRegon&gt;2016-04-22&lt;/praw_dataSkresleniaZRegon&gt;&#xD;\n    &lt;praw_dataOrzeczeniaOUpadlosci /&gt;&#xD;\n    &lt;praw_dataZakonczeniaPostepowaniaUpadlosciowego /&gt;&#xD;\n    &lt;praw_adSiedzKraj_Symbol&gt;PL&lt;/praw_adSiedzKraj_Symbol&gt;&#xD;\n    &lt;praw_adSiedzWojewodztwo_Symbol&gt;24&lt;/praw_adSiedzWojewodztwo_Symbol&gt;&#xD;\n    &lt;praw_adSiedzPowiat_Symbol&gt;69&lt;/praw_adSiedzPowiat_Symbol&gt;&#xD;\n    &lt;praw_adSiedzGmina_Symbol&gt;011&lt;/praw_adSiedzGmina_Symbol&gt;&#xD;\n    &lt;praw_adSiedzKodPocztowy&gt;40021&lt;/praw_adSiedzKodPocztowy&gt;&#xD;\n    &lt;praw_adSiedzMiejscowoscPoczty_Symbol&gt;0937474&lt;/praw_adSiedzMiejscowoscPoczty_Symbol&gt;&#xD;\n    &lt;praw_adSiedzMiejscowosc_Symbol&gt;0937474&lt;/praw_adSiedzMiejscowosc_Symbol&gt;&#xD;\n    &lt;praw_adSiedzUlica_Symbol&gt;07606&lt;/praw_adSiedzUlica_Symbol&gt;&#xD;\n    &lt;praw_adSiedzNumerNieruchomosci&gt;25&lt;/praw_adSiedzNumerNieruchomosci&gt;&#xD;\n    &lt;praw_adSiedzNumerLokalu /&gt;&#xD;\n    &lt;praw_adSiedzNietypoweMiejsceLokalizacji /&gt;&#xD;\n    &lt;praw_numerTelefonu&gt;03275737523&lt;/praw_numerTelefonu&gt;&#xD;\n    &lt;praw_numerWewnetrznyTelefonu /&gt;&#xD;\n    &lt;praw_numerFaksu&gt;0327573822&lt;/praw_numerFaksu&gt;&#xD;\n    &lt;praw_adresEmail /&gt;&#xD;\n    &lt;praw_adresStronyinternetowej /&gt;&#xD;\n    &lt;praw_adSiedzKraj_Nazwa&gt;POLSKA&lt;/praw_adSiedzKraj_Nazwa&gt;&#xD;\n    &lt;praw_adSiedzWojewodztwo_Nazwa&gt;ŚLĄSKIE&lt;/praw_adSiedzWojewodztwo_Nazwa&gt;&#xD;\n    &lt;praw_adSiedzPowiat_Nazwa&gt;Katowice&lt;/praw_adSiedzPowiat_Nazwa&gt;&#xD;\n    &lt;praw_adSiedzGmina_Nazwa&gt;Katowice&lt;/praw_adSiedzGmina_Nazwa&gt;&#xD;\n    &lt;praw_adSiedzMiejscowosc_Nazwa&gt;Katowice&lt;/praw_adSiedzMiejscowosc_Nazwa&gt;&#xD;\n    &lt;praw_adSiedzMiejscowoscPoczty_Nazwa&gt;Katowice&lt;/praw_adSiedzMiejscowoscPoczty_Nazwa&gt;&#xD;\n    &lt;praw_adSiedzUlica_Nazwa&gt;ul. Henryka Jordana&lt;/praw_adSiedzUlica_Nazwa&gt;&#xD;\n    &lt;praw_podstawowaFormaPrawna_Symbol&gt;2&lt;/praw_podstawowaFormaPrawna_Symbol&gt;&#xD;\n    &lt;praw_szczegolnaFormaPrawna_Symbol&gt;118&lt;/praw_szczegolnaFormaPrawna_Symbol&gt;&#xD;\n    &lt;praw_formaFinansowania_Symbol&gt;1&lt;/praw_formaFinansowania_Symbol&gt;&#xD;\n    &lt;praw_formaWlasnosci_Symbol&gt;214&lt;/praw_formaWlasnosci_Symbol&gt;&#xD;\n    &lt;praw_organZalozycielski_Symbol /&gt;&#xD;\n    &lt;praw_organRejestrowy_Symbol&gt;071270010&lt;/praw_organRejestrowy_Symbol&gt;&#xD;\n    &lt;praw_rodzajRejestruEwidencji_Symbol&gt;138&lt;/praw_rodzajRejestruEwidencji_Symbol&gt;&#xD;\n    &lt;praw_podstawowaFormaPrawna_Nazwa&gt;JEDNOSTKA ORGANIZACYJNA NIEMAJĄCA OSOBOWOŚCI PRAWNEJ&lt;/praw_podstawowaFormaPrawna_Nazwa&gt;&#xD;\n    &lt;praw_szczegolnaFormaPrawna_Nazwa&gt;SPÓŁKI JAWNE&lt;/praw_szczegolnaFormaPrawna_Nazwa&gt;&#xD;\n    &lt;praw_formaFinansowania_Nazwa&gt;JEDNOSTKA SAMOFINANSUJĄCA NIE BĘDĄCA JEDNOSTKĄ BUDŻETOWĄ LUB SAMORZĄDOWYM ZAKŁADEM BUDŻETOWYM&lt;/praw_formaFinansowania_Nazwa&gt;&#xD;\n    &lt;praw_formaWlasnosci_Nazwa&gt;WŁASNOŚĆ KRAJOWYCH OSÓB FIZYCZNYCH&lt;/praw_formaWlasnosci_Nazwa&gt;&#xD;\n    &lt;praw_organZalozycielski_Nazwa /&gt;&#xD;\n    &lt;praw_organRejestrowy_Nazwa&gt;SĄD REJONOWY W KATOWICACH, VIII WYDZIAŁ GOSPODARCZY KRAJOWEGO REJESTRU SĄDOWEGO&lt;/praw_organRejestrowy_Nazwa&gt;&#xD;\n    &lt;praw_rodzajRejestruEwidencji_Nazwa&gt;REJESTR PRZEDSIĘBIORCÓW&lt;/praw_rodzajRejestruEwidencji_Nazwa&gt;&#xD;\n    &lt;praw_liczbaJednLokalnych&gt;0&lt;/praw_liczbaJednLokalnych&gt;&#xD;\n  &lt;/dane&gt;&#xD;\n&lt;/root&gt;</DanePobierzPelnyRaportResult></DanePobierzPelnyRaportResponse></s:Body></s:Envelope>\r\n--uuid:18e18dae-c6e6-44ff-bd8d-55fd420f328a+id=7774465--\r\n','{\"nazwa\":\"\\\"PRZEDSIĘBIORSTWO-HANDLOWO-USŁUGOWE ENERGOZBYT ŻURADZKI SPÓŁKA JAWNA\\\"\",\"nip\":\"9540007714\",\"regon\":\"272051357\",\"krs\":\"\",\"wojewodztwo\":\"ŚLĄSKIE\",\"powiat\":\"Katowice\",\"gmina\":\"Katowice\",\"miejscowosc\":\"Katowice\",\"ulica\":\"ul. Henryka Jordana\",\"nr_nieruchomosci\":\"25\",\"nr_lokalu\":\"\",\"kod_pocztowy\":\"40-021\",\"poczta\":\"\",\"pkd_glowne\":\"\"}','gus_69932fb943c6e9.40214204',2,750,NULL),
(24,NULL,'2026-02-16 15:55:11','prod','nip','7183649047',NULL,NULL,0,NULL,'Brak numeru REGON w odpowiedzi GUS.','HTTP','Cannot process the message because the content type \'text/xml; charset=utf-8\' was not the expected type \'multipart/related; type=\"application/xop+xml\"\'.','<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<env:Envelope xmlns:env=\"http://www.w3.org/2003/05/soap-envelope\" xmlns:ns1=\"http://CIS/BIR/PUBL/2014/07/DataContract\" xmlns:ns2=\"http://CIS/BIR/PUBL/2014/07\" xmlns:ns3=\"http://www.w3.org/2005/08/addressing\"><env:Header><ns3:Action env:mustUnderstand=\"true\">http://CIS/BIR/PUBL/2014/07/IUslugaBIRzewnPubl/DaneSzukajPodmioty</ns3:Action><ns3:To env:mustUnderstand=\"true\">https://wyszukiwarkaregon.stat.gov.pl/wsBIR/UslugaBIRzewnPubl.svc</ns3:To><ns3:MessageID env:mustUnderstand=\"true\">urn:uuid:e1176471-80ed-4df3-a3e8-583f0834c8ab</ns3:MessageID><wsa:ReplyTo xmlns:wsa=\"http://www.w3.org/2005/08/addressing\"><wsa:Address>http://www.w3.org/2005/08/addressing/anonymous</wsa:Address></wsa:ReplyTo><ns2:sid>222vx6nb9549vy6tk34p</ns2:sid></env:Header><env:Body><ns2:DaneSzukajPodmioty><ns2:pParametryWyszukiwania><ns1:Nip>7183649047</ns1:Nip></ns2:pParametryWyszukiwania></ns2:DaneSzukajPodmioty></env:Body></env:Envelope>\n','\r\n--uuid:18e18dae-c6e6-44ff-bd8d-55fd420f328a+id=7779262\r\nContent-ID: <http://tempuri.org/0>\r\nContent-Transfer-Encoding: 8bit\r\nContent-Type: application/xop+xml;charset=utf-8;type=\"application/soap+xml\"\r\n\r\n<s:Envelope xmlns:s=\"http://www.w3.org/2003/05/soap-envelope\" xmlns:a=\"http://www.w3.org/2005/08/addressing\"><s:Header><a:Action s:mustUnderstand=\"1\">http://CIS/BIR/PUBL/2014/07/IUslugaBIRzewnPubl/DaneSzukajPodmiotyResponse</a:Action><a:RelatesTo>urn:uuid:e1176471-80ed-4df3-a3e8-583f0834c8ab</a:RelatesTo></s:Header><s:Body><DaneSzukajPodmiotyResponse xmlns=\"http://CIS/BIR/PUBL/2014/07\"><DaneSzukajPodmiotyResult>&lt;root&gt;&#xD;\n  &lt;dane&gt;&#xD;\n    &lt;ErrorCode&gt;4&lt;/ErrorCode&gt;&#xD;\n    &lt;ErrorMessagePl&gt;Nie znaleziono podmiotu dla podanych kryteriów wyszukiwania.&lt;/ErrorMessagePl&gt;&#xD;\n    &lt;ErrorMessageEn&gt;No data found for the specified search criteria.&lt;/ErrorMessageEn&gt;&#xD;\n    &lt;Nip&gt;7183649047&lt;/Nip&gt;&#xD;\n  &lt;/dane&gt;&#xD;\n&lt;/root&gt;</DaneSzukajPodmiotyResult></DaneSzukajPodmiotyResponse></s:Body></s:Envelope>\r\n--uuid:18e18dae-c6e6-44ff-bd8d-55fd420f328a+id=7779262--\r\n',NULL,'gus_69932fce800837.49520638',2,713,'TRANSPORT'),
(25,NULL,'2026-02-16 15:55:12','prod','nip','0003092965',NULL,NULL,0,NULL,'Brak numeru REGON w odpowiedzi GUS.','HTTP','Cannot process the message because the content type \'text/xml; charset=utf-8\' was not the expected type \'multipart/related; type=\"application/xop+xml\"\'.','<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<env:Envelope xmlns:env=\"http://www.w3.org/2003/05/soap-envelope\" xmlns:ns1=\"http://CIS/BIR/PUBL/2014/07/DataContract\" xmlns:ns2=\"http://CIS/BIR/PUBL/2014/07\" xmlns:ns3=\"http://www.w3.org/2005/08/addressing\"><env:Header><ns3:Action env:mustUnderstand=\"true\">http://CIS/BIR/PUBL/2014/07/IUslugaBIRzewnPubl/DaneSzukajPodmioty</ns3:Action><ns3:To env:mustUnderstand=\"true\">https://wyszukiwarkaregon.stat.gov.pl/wsBIR/UslugaBIRzewnPubl.svc</ns3:To><ns3:MessageID env:mustUnderstand=\"true\">urn:uuid:bb978b95-05d5-4e87-9e07-7d7a51dd0a11</ns3:MessageID><wsa:ReplyTo xmlns:wsa=\"http://www.w3.org/2005/08/addressing\"><wsa:Address>http://www.w3.org/2005/08/addressing/anonymous</wsa:Address></wsa:ReplyTo><ns2:sid>4n4652zmctc629v442gp</ns2:sid></env:Header><env:Body><ns2:DaneSzukajPodmioty><ns2:pParametryWyszukiwania><ns1:Nip>0003092965</ns1:Nip></ns2:pParametryWyszukiwania></ns2:DaneSzukajPodmioty></env:Body></env:Envelope>\n','\r\n--uuid:18e18dae-c6e6-44ff-bd8d-55fd420f328a+id=7779409\r\nContent-ID: <http://tempuri.org/0>\r\nContent-Transfer-Encoding: 8bit\r\nContent-Type: application/xop+xml;charset=utf-8;type=\"application/soap+xml\"\r\n\r\n<s:Envelope xmlns:s=\"http://www.w3.org/2003/05/soap-envelope\" xmlns:a=\"http://www.w3.org/2005/08/addressing\"><s:Header><a:Action s:mustUnderstand=\"1\">http://CIS/BIR/PUBL/2014/07/IUslugaBIRzewnPubl/DaneSzukajPodmiotyResponse</a:Action><a:RelatesTo>urn:uuid:bb978b95-05d5-4e87-9e07-7d7a51dd0a11</a:RelatesTo></s:Header><s:Body><DaneSzukajPodmiotyResponse xmlns=\"http://CIS/BIR/PUBL/2014/07\"><DaneSzukajPodmiotyResult>&lt;root&gt;&#xD;\n  &lt;dane&gt;&#xD;\n    &lt;ErrorCode&gt;4&lt;/ErrorCode&gt;&#xD;\n    &lt;ErrorMessagePl&gt;Nie znaleziono podmiotu dla podanych kryteriów wyszukiwania.&lt;/ErrorMessagePl&gt;&#xD;\n    &lt;ErrorMessageEn&gt;No data found for the specified search criteria.&lt;/ErrorMessageEn&gt;&#xD;\n    &lt;Nip&gt;0003092965&lt;/Nip&gt;&#xD;\n  &lt;/dane&gt;&#xD;\n&lt;/root&gt;</DaneSzukajPodmiotyResult></DaneSzukajPodmiotyResponse></s:Body></s:Envelope>\r\n--uuid:18e18dae-c6e6-44ff-bd8d-55fd420f328a+id=7779409--\r\n',NULL,'gus_69932fcf3ee7c6.33603430',2,785,'TRANSPORT'),
(26,NULL,'2026-02-16 15:55:12','prod','nip','8091667492',NULL,NULL,0,NULL,'Brak numeru REGON w odpowiedzi GUS.','HTTP','Cannot process the message because the content type \'text/xml; charset=utf-8\' was not the expected type \'multipart/related; type=\"application/xop+xml\"\'.','<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<env:Envelope xmlns:env=\"http://www.w3.org/2003/05/soap-envelope\" xmlns:ns1=\"http://CIS/BIR/PUBL/2014/07/DataContract\" xmlns:ns2=\"http://CIS/BIR/PUBL/2014/07\" xmlns:ns3=\"http://www.w3.org/2005/08/addressing\"><env:Header><ns3:Action env:mustUnderstand=\"true\">http://CIS/BIR/PUBL/2014/07/IUslugaBIRzewnPubl/DaneSzukajPodmioty</ns3:Action><ns3:To env:mustUnderstand=\"true\">https://wyszukiwarkaregon.stat.gov.pl/wsBIR/UslugaBIRzewnPubl.svc</ns3:To><ns3:MessageID env:mustUnderstand=\"true\">urn:uuid:d3ed7f11-0b6b-44e8-aafd-40e26f3d55b6</ns3:MessageID><wsa:ReplyTo xmlns:wsa=\"http://www.w3.org/2005/08/addressing\"><wsa:Address>http://www.w3.org/2005/08/addressing/anonymous</wsa:Address></wsa:ReplyTo><ns2:sid>g5e8zc667mh7f2n85c7z</ns2:sid></env:Header><env:Body><ns2:DaneSzukajPodmioty><ns2:pParametryWyszukiwania><ns1:Nip>8091667492</ns1:Nip></ns2:pParametryWyszukiwania></ns2:DaneSzukajPodmioty></env:Body></env:Envelope>\n','\r\n--uuid:18e18dae-c6e6-44ff-bd8d-55fd420f328a+id=7779547\r\nContent-ID: <http://tempuri.org/0>\r\nContent-Transfer-Encoding: 8bit\r\nContent-Type: application/xop+xml;charset=utf-8;type=\"application/soap+xml\"\r\n\r\n<s:Envelope xmlns:s=\"http://www.w3.org/2003/05/soap-envelope\" xmlns:a=\"http://www.w3.org/2005/08/addressing\"><s:Header><a:Action s:mustUnderstand=\"1\">http://CIS/BIR/PUBL/2014/07/IUslugaBIRzewnPubl/DaneSzukajPodmiotyResponse</a:Action><a:RelatesTo>urn:uuid:d3ed7f11-0b6b-44e8-aafd-40e26f3d55b6</a:RelatesTo></s:Header><s:Body><DaneSzukajPodmiotyResponse xmlns=\"http://CIS/BIR/PUBL/2014/07\"><DaneSzukajPodmiotyResult>&lt;root&gt;&#xD;\n  &lt;dane&gt;&#xD;\n    &lt;ErrorCode&gt;4&lt;/ErrorCode&gt;&#xD;\n    &lt;ErrorMessagePl&gt;Nie znaleziono podmiotu dla podanych kryteriów wyszukiwania.&lt;/ErrorMessagePl&gt;&#xD;\n    &lt;ErrorMessageEn&gt;No data found for the specified search criteria.&lt;/ErrorMessageEn&gt;&#xD;\n    &lt;Nip&gt;8091667492&lt;/Nip&gt;&#xD;\n  &lt;/dane&gt;&#xD;\n&lt;/root&gt;</DaneSzukajPodmiotyResult></DaneSzukajPodmiotyResponse></s:Body></s:Envelope>\r\n--uuid:18e18dae-c6e6-44ff-bd8d-55fd420f328a+id=7779547--\r\n',NULL,'gus_69932fd00ff7e1.15143745',2,742,'TRANSPORT'),
(27,NULL,'2026-02-16 15:55:13','prod','nip','8694539662',NULL,NULL,0,NULL,'Brak numeru REGON w odpowiedzi GUS.','HTTP','Cannot process the message because the content type \'text/xml; charset=utf-8\' was not the expected type \'multipart/related; type=\"application/xop+xml\"\'.','<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<env:Envelope xmlns:env=\"http://www.w3.org/2003/05/soap-envelope\" xmlns:ns1=\"http://CIS/BIR/PUBL/2014/07/DataContract\" xmlns:ns2=\"http://CIS/BIR/PUBL/2014/07\" xmlns:ns3=\"http://www.w3.org/2005/08/addressing\"><env:Header><ns3:Action env:mustUnderstand=\"true\">http://CIS/BIR/PUBL/2014/07/IUslugaBIRzewnPubl/DaneSzukajPodmioty</ns3:Action><ns3:To env:mustUnderstand=\"true\">https://wyszukiwarkaregon.stat.gov.pl/wsBIR/UslugaBIRzewnPubl.svc</ns3:To><ns3:MessageID env:mustUnderstand=\"true\">urn:uuid:28d1a227-090b-4d77-9858-be9b59a44a17</ns3:MessageID><wsa:ReplyTo xmlns:wsa=\"http://www.w3.org/2005/08/addressing\"><wsa:Address>http://www.w3.org/2005/08/addressing/anonymous</wsa:Address></wsa:ReplyTo><ns2:sid>45y6xs2ynh3y73u28hsp</ns2:sid></env:Header><env:Body><ns2:DaneSzukajPodmioty><ns2:pParametryWyszukiwania><ns1:Nip>8694539662</ns1:Nip></ns2:pParametryWyszukiwania></ns2:DaneSzukajPodmioty></env:Body></env:Envelope>\n','\r\n--uuid:18e18dae-c6e6-44ff-bd8d-55fd420f328a+id=7779716\r\nContent-ID: <http://tempuri.org/0>\r\nContent-Transfer-Encoding: 8bit\r\nContent-Type: application/xop+xml;charset=utf-8;type=\"application/soap+xml\"\r\n\r\n<s:Envelope xmlns:s=\"http://www.w3.org/2003/05/soap-envelope\" xmlns:a=\"http://www.w3.org/2005/08/addressing\"><s:Header><a:Action s:mustUnderstand=\"1\">http://CIS/BIR/PUBL/2014/07/IUslugaBIRzewnPubl/DaneSzukajPodmiotyResponse</a:Action><a:RelatesTo>urn:uuid:28d1a227-090b-4d77-9858-be9b59a44a17</a:RelatesTo></s:Header><s:Body><DaneSzukajPodmiotyResponse xmlns=\"http://CIS/BIR/PUBL/2014/07\"><DaneSzukajPodmiotyResult>&lt;root&gt;&#xD;\n  &lt;dane&gt;&#xD;\n    &lt;ErrorCode&gt;4&lt;/ErrorCode&gt;&#xD;\n    &lt;ErrorMessagePl&gt;Nie znaleziono podmiotu dla podanych kryteriów wyszukiwania.&lt;/ErrorMessagePl&gt;&#xD;\n    &lt;ErrorMessageEn&gt;No data found for the specified search criteria.&lt;/ErrorMessageEn&gt;&#xD;\n    &lt;Nip&gt;8694539662&lt;/Nip&gt;&#xD;\n  &lt;/dane&gt;&#xD;\n&lt;/root&gt;</DaneSzukajPodmiotyResult></DaneSzukajPodmiotyResponse></s:Body></s:Envelope>\r\n--uuid:18e18dae-c6e6-44ff-bd8d-55fd420f328a+id=7779716--\r\n',NULL,'gus_69932fd0cb1017.31848199',2,742,'TRANSPORT'),
(28,NULL,'2026-02-16 15:55:14','prod','nip','0551942258',NULL,NULL,0,NULL,'Brak numeru REGON w odpowiedzi GUS.','HTTP','Cannot process the message because the content type \'text/xml; charset=utf-8\' was not the expected type \'multipart/related; type=\"application/xop+xml\"\'.','<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<env:Envelope xmlns:env=\"http://www.w3.org/2003/05/soap-envelope\" xmlns:ns1=\"http://CIS/BIR/PUBL/2014/07/DataContract\" xmlns:ns2=\"http://CIS/BIR/PUBL/2014/07\" xmlns:ns3=\"http://www.w3.org/2005/08/addressing\"><env:Header><ns3:Action env:mustUnderstand=\"true\">http://CIS/BIR/PUBL/2014/07/IUslugaBIRzewnPubl/DaneSzukajPodmioty</ns3:Action><ns3:To env:mustUnderstand=\"true\">https://wyszukiwarkaregon.stat.gov.pl/wsBIR/UslugaBIRzewnPubl.svc</ns3:To><ns3:MessageID env:mustUnderstand=\"true\">urn:uuid:4e892d62-29ee-41ec-b7f3-e08d16e791d1</ns3:MessageID><wsa:ReplyTo xmlns:wsa=\"http://www.w3.org/2005/08/addressing\"><wsa:Address>http://www.w3.org/2005/08/addressing/anonymous</wsa:Address></wsa:ReplyTo><ns2:sid>48s7k2c45m9h8e489sx4</ns2:sid></env:Header><env:Body><ns2:DaneSzukajPodmioty><ns2:pParametryWyszukiwania><ns1:Nip>0551942258</ns1:Nip></ns2:pParametryWyszukiwania></ns2:DaneSzukajPodmioty></env:Body></env:Envelope>\n','\r\n--uuid:18e18dae-c6e6-44ff-bd8d-55fd420f328a+id=7779856\r\nContent-ID: <http://tempuri.org/0>\r\nContent-Transfer-Encoding: 8bit\r\nContent-Type: application/xop+xml;charset=utf-8;type=\"application/soap+xml\"\r\n\r\n<s:Envelope xmlns:s=\"http://www.w3.org/2003/05/soap-envelope\" xmlns:a=\"http://www.w3.org/2005/08/addressing\"><s:Header><a:Action s:mustUnderstand=\"1\">http://CIS/BIR/PUBL/2014/07/IUslugaBIRzewnPubl/DaneSzukajPodmiotyResponse</a:Action><a:RelatesTo>urn:uuid:4e892d62-29ee-41ec-b7f3-e08d16e791d1</a:RelatesTo></s:Header><s:Body><DaneSzukajPodmiotyResponse xmlns=\"http://CIS/BIR/PUBL/2014/07\"><DaneSzukajPodmiotyResult>&lt;root&gt;&#xD;\n  &lt;dane&gt;&#xD;\n    &lt;ErrorCode&gt;4&lt;/ErrorCode&gt;&#xD;\n    &lt;ErrorMessagePl&gt;Nie znaleziono podmiotu dla podanych kryteriów wyszukiwania.&lt;/ErrorMessagePl&gt;&#xD;\n    &lt;ErrorMessageEn&gt;No data found for the specified search criteria.&lt;/ErrorMessageEn&gt;&#xD;\n    &lt;Nip&gt;0551942258&lt;/Nip&gt;&#xD;\n  &lt;/dane&gt;&#xD;\n&lt;/root&gt;</DaneSzukajPodmiotyResult></DaneSzukajPodmiotyResponse></s:Body></s:Envelope>\r\n--uuid:18e18dae-c6e6-44ff-bd8d-55fd420f328a+id=7779856--\r\n',NULL,'gus_69932fd1921a37.68979275',2,652,'TRANSPORT'),
(29,NULL,'2026-02-16 15:55:14','prod','nip','0105085199',NULL,NULL,0,NULL,'Brak numeru REGON w odpowiedzi GUS.','HTTP','Cannot process the message because the content type \'text/xml; charset=utf-8\' was not the expected type \'multipart/related; type=\"application/xop+xml\"\'.','<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<env:Envelope xmlns:env=\"http://www.w3.org/2003/05/soap-envelope\" xmlns:ns1=\"http://CIS/BIR/PUBL/2014/07/DataContract\" xmlns:ns2=\"http://CIS/BIR/PUBL/2014/07\" xmlns:ns3=\"http://www.w3.org/2005/08/addressing\"><env:Header><ns3:Action env:mustUnderstand=\"true\">http://CIS/BIR/PUBL/2014/07/IUslugaBIRzewnPubl/DaneSzukajPodmioty</ns3:Action><ns3:To env:mustUnderstand=\"true\">https://wyszukiwarkaregon.stat.gov.pl/wsBIR/UslugaBIRzewnPubl.svc</ns3:To><ns3:MessageID env:mustUnderstand=\"true\">urn:uuid:dd6eda5d-fcfe-4f99-9d6f-ebf706864f01</ns3:MessageID><wsa:ReplyTo xmlns:wsa=\"http://www.w3.org/2005/08/addressing\"><wsa:Address>http://www.w3.org/2005/08/addressing/anonymous</wsa:Address></wsa:ReplyTo><ns2:sid>2z3323y7wg3w79g8ymnz</ns2:sid></env:Header><env:Body><ns2:DaneSzukajPodmioty><ns2:pParametryWyszukiwania><ns1:Nip>0105085199</ns1:Nip></ns2:pParametryWyszukiwania></ns2:DaneSzukajPodmioty></env:Body></env:Envelope>\n','\r\n--uuid:18e18dae-c6e6-44ff-bd8d-55fd420f328a+id=7780025\r\nContent-ID: <http://tempuri.org/0>\r\nContent-Transfer-Encoding: 8bit\r\nContent-Type: application/xop+xml;charset=utf-8;type=\"application/soap+xml\"\r\n\r\n<s:Envelope xmlns:s=\"http://www.w3.org/2003/05/soap-envelope\" xmlns:a=\"http://www.w3.org/2005/08/addressing\"><s:Header><a:Action s:mustUnderstand=\"1\">http://CIS/BIR/PUBL/2014/07/IUslugaBIRzewnPubl/DaneSzukajPodmiotyResponse</a:Action><a:RelatesTo>urn:uuid:dd6eda5d-fcfe-4f99-9d6f-ebf706864f01</a:RelatesTo></s:Header><s:Body><DaneSzukajPodmiotyResponse xmlns=\"http://CIS/BIR/PUBL/2014/07\"><DaneSzukajPodmiotyResult>&lt;root&gt;&#xD;\n  &lt;dane&gt;&#xD;\n    &lt;ErrorCode&gt;4&lt;/ErrorCode&gt;&#xD;\n    &lt;ErrorMessagePl&gt;Nie znaleziono podmiotu dla podanych kryteriów wyszukiwania.&lt;/ErrorMessagePl&gt;&#xD;\n    &lt;ErrorMessageEn&gt;No data found for the specified search criteria.&lt;/ErrorMessageEn&gt;&#xD;\n    &lt;Nip&gt;0105085199&lt;/Nip&gt;&#xD;\n  &lt;/dane&gt;&#xD;\n&lt;/root&gt;</DaneSzukajPodmiotyResult></DaneSzukajPodmiotyResponse></s:Body></s:Envelope>\r\n--uuid:18e18dae-c6e6-44ff-bd8d-55fd420f328a+id=7780025--\r\n',NULL,'gus_69932fd2423185.33016179',2,654,'TRANSPORT'),
(30,NULL,'2026-02-16 15:55:15','prod','nip','9850146143',NULL,NULL,0,NULL,'Brak numeru REGON w odpowiedzi GUS.','HTTP','Cannot process the message because the content type \'text/xml; charset=utf-8\' was not the expected type \'multipart/related; type=\"application/xop+xml\"\'.','<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<env:Envelope xmlns:env=\"http://www.w3.org/2003/05/soap-envelope\" xmlns:ns1=\"http://CIS/BIR/PUBL/2014/07/DataContract\" xmlns:ns2=\"http://CIS/BIR/PUBL/2014/07\" xmlns:ns3=\"http://www.w3.org/2005/08/addressing\"><env:Header><ns3:Action env:mustUnderstand=\"true\">http://CIS/BIR/PUBL/2014/07/IUslugaBIRzewnPubl/DaneSzukajPodmioty</ns3:Action><ns3:To env:mustUnderstand=\"true\">https://wyszukiwarkaregon.stat.gov.pl/wsBIR/UslugaBIRzewnPubl.svc</ns3:To><ns3:MessageID env:mustUnderstand=\"true\">urn:uuid:e4e6d149-fab8-4a66-9c35-2bad001e94df</ns3:MessageID><wsa:ReplyTo xmlns:wsa=\"http://www.w3.org/2005/08/addressing\"><wsa:Address>http://www.w3.org/2005/08/addressing/anonymous</wsa:Address></wsa:ReplyTo><ns2:sid>sv36vxw43tb338t3egef</ns2:sid></env:Header><env:Body><ns2:DaneSzukajPodmioty><ns2:pParametryWyszukiwania><ns1:Nip>9850146143</ns1:Nip></ns2:pParametryWyszukiwania></ns2:DaneSzukajPodmioty></env:Body></env:Envelope>\n','\r\n--uuid:18e18dae-c6e6-44ff-bd8d-55fd420f328a+id=7780185\r\nContent-ID: <http://tempuri.org/0>\r\nContent-Transfer-Encoding: 8bit\r\nContent-Type: application/xop+xml;charset=utf-8;type=\"application/soap+xml\"\r\n\r\n<s:Envelope xmlns:s=\"http://www.w3.org/2003/05/soap-envelope\" xmlns:a=\"http://www.w3.org/2005/08/addressing\"><s:Header><a:Action s:mustUnderstand=\"1\">http://CIS/BIR/PUBL/2014/07/IUslugaBIRzewnPubl/DaneSzukajPodmiotyResponse</a:Action><a:RelatesTo>urn:uuid:e4e6d149-fab8-4a66-9c35-2bad001e94df</a:RelatesTo></s:Header><s:Body><DaneSzukajPodmiotyResponse xmlns=\"http://CIS/BIR/PUBL/2014/07\"><DaneSzukajPodmiotyResult>&lt;root&gt;&#xD;\n  &lt;dane&gt;&#xD;\n    &lt;ErrorCode&gt;4&lt;/ErrorCode&gt;&#xD;\n    &lt;ErrorMessagePl&gt;Nie znaleziono podmiotu dla podanych kryteriów wyszukiwania.&lt;/ErrorMessagePl&gt;&#xD;\n    &lt;ErrorMessageEn&gt;No data found for the specified search criteria.&lt;/ErrorMessageEn&gt;&#xD;\n    &lt;Nip&gt;9850146143&lt;/Nip&gt;&#xD;\n  &lt;/dane&gt;&#xD;\n&lt;/root&gt;</DaneSzukajPodmiotyResult></DaneSzukajPodmiotyResponse></s:Body></s:Envelope>\r\n--uuid:18e18dae-c6e6-44ff-bd8d-55fd420f328a+id=7780185--\r\n',NULL,'gus_69932fd2e6e290.90306386',2,697,'TRANSPORT'),
(31,NULL,'2026-02-16 15:55:16','prod','nip','9708655954',NULL,NULL,0,NULL,'Brak numeru REGON w odpowiedzi GUS.','HTTP','Cannot process the message because the content type \'text/xml; charset=utf-8\' was not the expected type \'multipart/related; type=\"application/xop+xml\"\'.','<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<env:Envelope xmlns:env=\"http://www.w3.org/2003/05/soap-envelope\" xmlns:ns1=\"http://CIS/BIR/PUBL/2014/07/DataContract\" xmlns:ns2=\"http://CIS/BIR/PUBL/2014/07\" xmlns:ns3=\"http://www.w3.org/2005/08/addressing\"><env:Header><ns3:Action env:mustUnderstand=\"true\">http://CIS/BIR/PUBL/2014/07/IUslugaBIRzewnPubl/DaneSzukajPodmioty</ns3:Action><ns3:To env:mustUnderstand=\"true\">https://wyszukiwarkaregon.stat.gov.pl/wsBIR/UslugaBIRzewnPubl.svc</ns3:To><ns3:MessageID env:mustUnderstand=\"true\">urn:uuid:0db8f7f2-651d-4a43-9f43-9e31f57740b2</ns3:MessageID><wsa:ReplyTo xmlns:wsa=\"http://www.w3.org/2005/08/addressing\"><wsa:Address>http://www.w3.org/2005/08/addressing/anonymous</wsa:Address></wsa:ReplyTo><ns2:sid>9pkn35sm55hb65c972e4</ns2:sid></env:Header><env:Body><ns2:DaneSzukajPodmioty><ns2:pParametryWyszukiwania><ns1:Nip>9708655954</ns1:Nip></ns2:pParametryWyszukiwania></ns2:DaneSzukajPodmioty></env:Body></env:Envelope>\n','\r\n--uuid:18e18dae-c6e6-44ff-bd8d-55fd420f328a+id=7780319\r\nContent-ID: <http://tempuri.org/0>\r\nContent-Transfer-Encoding: 8bit\r\nContent-Type: application/xop+xml;charset=utf-8;type=\"application/soap+xml\"\r\n\r\n<s:Envelope xmlns:s=\"http://www.w3.org/2003/05/soap-envelope\" xmlns:a=\"http://www.w3.org/2005/08/addressing\"><s:Header><a:Action s:mustUnderstand=\"1\">http://CIS/BIR/PUBL/2014/07/IUslugaBIRzewnPubl/DaneSzukajPodmiotyResponse</a:Action><a:RelatesTo>urn:uuid:0db8f7f2-651d-4a43-9f43-9e31f57740b2</a:RelatesTo></s:Header><s:Body><DaneSzukajPodmiotyResponse xmlns=\"http://CIS/BIR/PUBL/2014/07\"><DaneSzukajPodmiotyResult>&lt;root&gt;&#xD;\n  &lt;dane&gt;&#xD;\n    &lt;ErrorCode&gt;4&lt;/ErrorCode&gt;&#xD;\n    &lt;ErrorMessagePl&gt;Nie znaleziono podmiotu dla podanych kryteriów wyszukiwania.&lt;/ErrorMessagePl&gt;&#xD;\n    &lt;ErrorMessageEn&gt;No data found for the specified search criteria.&lt;/ErrorMessageEn&gt;&#xD;\n    &lt;Nip&gt;9708655954&lt;/Nip&gt;&#xD;\n  &lt;/dane&gt;&#xD;\n&lt;/root&gt;</DaneSzukajPodmiotyResult></DaneSzukajPodmiotyResponse></s:Body></s:Envelope>\r\n--uuid:18e18dae-c6e6-44ff-bd8d-55fd420f328a+id=7780319--\r\n',NULL,'gus_69932fd3a27351.98704836',2,664,'TRANSPORT'),
(32,NULL,'2026-02-16 15:55:21','prod','nip','7183649047',NULL,NULL,0,NULL,'Brak numeru REGON w odpowiedzi GUS.','HTTP','Cannot process the message because the content type \'text/xml; charset=utf-8\' was not the expected type \'multipart/related; type=\"application/xop+xml\"\'.','<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<env:Envelope xmlns:env=\"http://www.w3.org/2003/05/soap-envelope\" xmlns:ns1=\"http://CIS/BIR/PUBL/2014/07/DataContract\" xmlns:ns2=\"http://CIS/BIR/PUBL/2014/07\" xmlns:ns3=\"http://www.w3.org/2005/08/addressing\"><env:Header><ns3:Action env:mustUnderstand=\"true\">http://CIS/BIR/PUBL/2014/07/IUslugaBIRzewnPubl/DaneSzukajPodmioty</ns3:Action><ns3:To env:mustUnderstand=\"true\">https://wyszukiwarkaregon.stat.gov.pl/wsBIR/UslugaBIRzewnPubl.svc</ns3:To><ns3:MessageID env:mustUnderstand=\"true\">urn:uuid:371f226e-ebb2-4e97-8507-f3bb279a532f</ns3:MessageID><wsa:ReplyTo xmlns:wsa=\"http://www.w3.org/2005/08/addressing\"><wsa:Address>http://www.w3.org/2005/08/addressing/anonymous</wsa:Address></wsa:ReplyTo><ns2:sid>77z9gs4heny32wpgk97p</ns2:sid></env:Header><env:Body><ns2:DaneSzukajPodmioty><ns2:pParametryWyszukiwania><ns1:Nip>7183649047</ns1:Nip></ns2:pParametryWyszukiwania></ns2:DaneSzukajPodmioty></env:Body></env:Envelope>\n','\r\n--uuid:18e18dae-c6e6-44ff-bd8d-55fd420f328a+id=7781492\r\nContent-ID: <http://tempuri.org/0>\r\nContent-Transfer-Encoding: 8bit\r\nContent-Type: application/xop+xml;charset=utf-8;type=\"application/soap+xml\"\r\n\r\n<s:Envelope xmlns:s=\"http://www.w3.org/2003/05/soap-envelope\" xmlns:a=\"http://www.w3.org/2005/08/addressing\"><s:Header><a:Action s:mustUnderstand=\"1\">http://CIS/BIR/PUBL/2014/07/IUslugaBIRzewnPubl/DaneSzukajPodmiotyResponse</a:Action><a:RelatesTo>urn:uuid:371f226e-ebb2-4e97-8507-f3bb279a532f</a:RelatesTo></s:Header><s:Body><DaneSzukajPodmiotyResponse xmlns=\"http://CIS/BIR/PUBL/2014/07\"><DaneSzukajPodmiotyResult>&lt;root&gt;&#xD;\n  &lt;dane&gt;&#xD;\n    &lt;ErrorCode&gt;4&lt;/ErrorCode&gt;&#xD;\n    &lt;ErrorMessagePl&gt;Nie znaleziono podmiotu dla podanych kryteriów wyszukiwania.&lt;/ErrorMessagePl&gt;&#xD;\n    &lt;ErrorMessageEn&gt;No data found for the specified search criteria.&lt;/ErrorMessageEn&gt;&#xD;\n    &lt;Nip&gt;7183649047&lt;/Nip&gt;&#xD;\n  &lt;/dane&gt;&#xD;\n&lt;/root&gt;</DaneSzukajPodmiotyResult></DaneSzukajPodmiotyResponse></s:Body></s:Envelope>\r\n--uuid:18e18dae-c6e6-44ff-bd8d-55fd420f328a+id=7781492--\r\n',NULL,'gus_69932fd8d84183.78974569',2,682,'TRANSPORT'),
(33,NULL,'2026-02-16 16:49:24','prod','nip','5790004488','BIR11OsPrawna',200,1,NULL,NULL,NULL,NULL,'<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<env:Envelope xmlns:env=\"http://www.w3.org/2003/05/soap-envelope\" xmlns:ns1=\"http://CIS/BIR/PUBL/2014/07\" xmlns:ns2=\"http://www.w3.org/2005/08/addressing\"><env:Header><ns2:Action env:mustUnderstand=\"true\">http://CIS/BIR/PUBL/2014/07/IUslugaBIRzewnPubl/DanePobierzPelnyRaport</ns2:Action><ns2:To env:mustUnderstand=\"true\">https://wyszukiwarkaregon.stat.gov.pl/wsBIR/UslugaBIRzewnPubl.svc</ns2:To><ns2:MessageID env:mustUnderstand=\"true\">urn:uuid:08b06d0d-b787-4323-9a78-04c8eb07607f</ns2:MessageID><wsa:ReplyTo xmlns:wsa=\"http://www.w3.org/2005/08/addressing\"><wsa:Address>http://www.w3.org/2005/08/addressing/anonymous</wsa:Address></wsa:ReplyTo><ns1:sid>6mk6f56e6c3r88fyr3xf</ns1:sid></env:Header><env:Body><ns1:DanePobierzPelnyRaport><ns1:pRegon>810528352</ns1:pRegon><ns1:pNazwaRaportu>BIR11OsPrawna</ns1:pNazwaRaportu></ns1:DanePobierzPelnyRaport></env:Body></env:Envelope>\n','\r\n--uuid:18e18dae-c6e6-44ff-bd8d-55fd420f328a+id=8289870\r\nContent-ID: <http://tempuri.org/0>\r\nContent-Transfer-Encoding: 8bit\r\nContent-Type: application/xop+xml;charset=utf-8;type=\"application/soap+xml\"\r\n\r\n<s:Envelope xmlns:s=\"http://www.w3.org/2003/05/soap-envelope\" xmlns:a=\"http://www.w3.org/2005/08/addressing\"><s:Header><a:Action s:mustUnderstand=\"1\">http://CIS/BIR/PUBL/2014/07/IUslugaBIRzewnPubl/DanePobierzPelnyRaportResponse</a:Action><a:RelatesTo>urn:uuid:08b06d0d-b787-4323-9a78-04c8eb07607f</a:RelatesTo></s:Header><s:Body><DanePobierzPelnyRaportResponse xmlns=\"http://CIS/BIR/PUBL/2014/07\"><DanePobierzPelnyRaportResult>&lt;root&gt;&#xD;\n  &lt;dane&gt;&#xD;\n    &lt;ErrorCode&gt;4&lt;/ErrorCode&gt;&#xD;\n    &lt;ErrorMessagePl&gt;Nie znaleziono wpisu dla podanych kryteriów wyszukiwania.&lt;/ErrorMessagePl&gt;&#xD;\n    &lt;ErrorMessageEn&gt;No data found for the specified search criteria.&lt;/ErrorMessageEn&gt;&#xD;\n    &lt;pRegon&gt;810528352&lt;/pRegon&gt;&#xD;\n    &lt;Typ_podmiotu&gt;F&lt;/Typ_podmiotu&gt;&#xD;\n    &lt;Raport&gt;BIR11OsPrawna&lt;/Raport&gt;&#xD;\n  &lt;/dane&gt;&#xD;\n&lt;/root&gt;</DanePobierzPelnyRaportResult></DanePobierzPelnyRaportResponse></s:Body></s:Envelope>\r\n--uuid:18e18dae-c6e6-44ff-bd8d-55fd420f328a+id=8289870--\r\n','{\"nazwa\":\"Przedsiębiorstwo Handlowo Usługowe \\\"ELRO\\\" Elżbieta Pufal\",\"nip\":\"5790004488\",\"regon\":\"810528352\",\"krs\":\"\",\"wojewodztwo\":\"POMORSKIE\",\"powiat\":\"malborski\",\"gmina\":\"Malbork\",\"miejscowosc\":\"Grobelno\",\"ulica\":\"\",\"nr_nieruchomosci\":\"8\",\"nr_lokalu\":\"\",\"kod_pocztowy\":\"82-200\",\"poczta\":\"\",\"pkd_glowne\":\"\"}','gus_69933c84187913.42127298',2,823,NULL),
(34,NULL,'2026-02-16 16:49:47','prod','nip','5783183113','BIR11OsPrawna',200,1,NULL,NULL,NULL,NULL,'<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<env:Envelope xmlns:env=\"http://www.w3.org/2003/05/soap-envelope\" xmlns:ns1=\"http://CIS/BIR/PUBL/2014/07\" xmlns:ns2=\"http://www.w3.org/2005/08/addressing\"><env:Header><ns2:Action env:mustUnderstand=\"true\">http://CIS/BIR/PUBL/2014/07/IUslugaBIRzewnPubl/DanePobierzPelnyRaport</ns2:Action><ns2:To env:mustUnderstand=\"true\">https://wyszukiwarkaregon.stat.gov.pl/wsBIR/UslugaBIRzewnPubl.svc</ns2:To><ns2:MessageID env:mustUnderstand=\"true\">urn:uuid:237cf6e1-6190-4252-a3c6-c7a5bd7880bd</ns2:MessageID><wsa:ReplyTo xmlns:wsa=\"http://www.w3.org/2005/08/addressing\"><wsa:Address>http://www.w3.org/2005/08/addressing/anonymous</wsa:Address></wsa:ReplyTo><ns1:sid>spfp425sm7rb8856cpf4</ns1:sid></env:Header><env:Body><ns1:DanePobierzPelnyRaport><ns1:pRegon>543878570</ns1:pRegon><ns1:pNazwaRaportu>BIR11OsPrawna</ns1:pNazwaRaportu></ns1:DanePobierzPelnyRaport></env:Body></env:Envelope>\n','\r\n--uuid:18e18dae-c6e6-44ff-bd8d-55fd420f328a+id=8292636\r\nContent-ID: <http://tempuri.org/0>\r\nContent-Transfer-Encoding: 8bit\r\nContent-Type: application/xop+xml;charset=utf-8;type=\"application/soap+xml\"\r\n\r\n<s:Envelope xmlns:s=\"http://www.w3.org/2003/05/soap-envelope\" xmlns:a=\"http://www.w3.org/2005/08/addressing\"><s:Header><a:Action s:mustUnderstand=\"1\">http://CIS/BIR/PUBL/2014/07/IUslugaBIRzewnPubl/DanePobierzPelnyRaportResponse</a:Action><a:RelatesTo>urn:uuid:237cf6e1-6190-4252-a3c6-c7a5bd7880bd</a:RelatesTo></s:Header><s:Body><DanePobierzPelnyRaportResponse xmlns=\"http://CIS/BIR/PUBL/2014/07\"><DanePobierzPelnyRaportResult>&lt;root&gt;&#xD;\n  &lt;dane&gt;&#xD;\n    &lt;praw_regon9&gt;543878570&lt;/praw_regon9&gt;&#xD;\n    &lt;praw_nip&gt;5783183113&lt;/praw_nip&gt;&#xD;\n    &lt;praw_statusNip /&gt;&#xD;\n    &lt;praw_nazwa&gt;ASPANEL ELBLĄG SPÓŁKA Z OGRANICZONĄ ODPOWIEDZIALNOŚCIĄ&lt;/praw_nazwa&gt;&#xD;\n    &lt;praw_nazwaSkrocona /&gt;&#xD;\n    &lt;praw_numerWRejestrzeEwidencji&gt;0001221166&lt;/praw_numerWRejestrzeEwidencji&gt;&#xD;\n    &lt;praw_dataWpisuDoRejestruEwidencji&gt;2026-02-02&lt;/praw_dataWpisuDoRejestruEwidencji&gt;&#xD;\n    &lt;praw_dataPowstania&gt;2026-02-02&lt;/praw_dataPowstania&gt;&#xD;\n    &lt;praw_dataRozpoczeciaDzialalnosci&gt;2026-02-02&lt;/praw_dataRozpoczeciaDzialalnosci&gt;&#xD;\n    &lt;praw_dataWpisuDoRegon&gt;2026-02-03&lt;/praw_dataWpisuDoRegon&gt;&#xD;\n    &lt;praw_dataZawieszeniaDzialalnosci /&gt;&#xD;\n    &lt;praw_dataWznowieniaDzialalnosci /&gt;&#xD;\n    &lt;praw_dataZaistnieniaZmiany&gt;2026-02-10&lt;/praw_dataZaistnieniaZmiany&gt;&#xD;\n    &lt;praw_dataZakonczeniaDzialalnosci /&gt;&#xD;\n    &lt;praw_dataSkresleniaZRegon /&gt;&#xD;\n    &lt;praw_dataOrzeczeniaOUpadlosci /&gt;&#xD;\n    &lt;praw_dataZakonczeniaPostepowaniaUpadlosciowego /&gt;&#xD;\n    &lt;praw_adSiedzKraj_Symbol&gt;PL&lt;/praw_adSiedzKraj_Symbol&gt;&#xD;\n    &lt;praw_adSiedzWojewodztwo_Symbol&gt;28&lt;/praw_adSiedzWojewodztwo_Symbol&gt;&#xD;\n    &lt;praw_adSiedzPowiat_Symbol&gt;04&lt;/praw_adSiedzPowiat_Symbol&gt;&#xD;\n    &lt;praw_adSiedzGmina_Symbol&gt;012&lt;/praw_adSiedzGmina_Symbol&gt;&#xD;\n    &lt;praw_adSiedzKodPocztowy&gt;82300&lt;/praw_adSiedzKodPocztowy&gt;&#xD;\n    &lt;praw_adSiedzMiejscowoscPoczty_Symbol&gt;0932703&lt;/praw_adSiedzMiejscowoscPoczty_Symbol&gt;&#xD;\n    &lt;praw_adSiedzMiejscowosc_Symbol&gt;0149363&lt;/praw_adSiedzMiejscowosc_Symbol&gt;&#xD;\n    &lt;praw_adSiedzUlica_Symbol /&gt;&#xD;\n    &lt;praw_adSiedzNumerNieruchomosci&gt;35A&lt;/praw_adSiedzNumerNieruchomosci&gt;&#xD;\n    &lt;praw_adSiedzNumerLokalu /&gt;&#xD;\n    &lt;praw_adSiedzNietypoweMiejsceLokalizacji /&gt;&#xD;\n    &lt;praw_numerTelefonu /&gt;&#xD;\n    &lt;praw_numerWewnetrznyTelefonu /&gt;&#xD;\n    &lt;praw_numerFaksu /&gt;&#xD;\n    &lt;praw_adresEmail /&gt;&#xD;\n    &lt;praw_adresStronyinternetowej /&gt;&#xD;\n    &lt;praw_adSiedzKraj_Nazwa&gt;POLSKA&lt;/praw_adSiedzKraj_Nazwa&gt;&#xD;\n    &lt;praw_adSiedzWojewodztwo_Nazwa&gt;WARMIŃSKO-MAZURSKIE&lt;/praw_adSiedzWojewodztwo_Nazwa&gt;&#xD;\n    &lt;praw_adSiedzPowiat_Nazwa&gt;elbląski&lt;/praw_adSiedzPowiat_Nazwa&gt;&#xD;\n    &lt;praw_adSiedzGmina_Nazwa&gt;Elbląg&lt;/praw_adSiedzGmina_Nazwa&gt;&#xD;\n    &lt;praw_adSiedzMiejscowosc_Nazwa&gt;Kazimierzowo&lt;/praw_adSiedzMiejscowosc_Nazwa&gt;&#xD;\n    &lt;praw_adSiedzMiejscowoscPoczty_Nazwa&gt;Elbląg&lt;/praw_adSiedzMiejscowoscPoczty_Nazwa&gt;&#xD;\n    &lt;praw_adSiedzUlica_Nazwa /&gt;&#xD;\n    &lt;praw_podstawowaFormaPrawna_Symbol&gt;1&lt;/praw_podstawowaFormaPrawna_Symbol&gt;&#xD;\n    &lt;praw_szczegolnaFormaPrawna_Symbol&gt;117&lt;/praw_szczegolnaFormaPrawna_Symbol&gt;&#xD;\n    &lt;praw_formaFinansowania_Symbol&gt;1&lt;/praw_formaFinansowania_Symbol&gt;&#xD;\n    &lt;praw_formaWlasnosci_Symbol&gt;214&lt;/praw_formaWlasnosci_Symbol&gt;&#xD;\n    &lt;praw_organZalozycielski_Symbol /&gt;&#xD;\n    &lt;praw_organRejestrowy_Symbol&gt;071510010&lt;/praw_organRejestrowy_Symbol&gt;&#xD;\n    &lt;praw_rodzajRejestruEwidencji_Symbol&gt;138&lt;/praw_rodzajRejestruEwidencji_Symbol&gt;&#xD;\n    &lt;praw_podstawowaFormaPrawna_Nazwa&gt;OSOBA PRAWNA&lt;/praw_podstawowaFormaPrawna_Nazwa&gt;&#xD;\n    &lt;praw_szczegolnaFormaPrawna_Nazwa&gt;SPÓŁKI Z OGRANICZONĄ ODPOWIEDZIALNOŚCIĄ&lt;/praw_szczegolnaFormaPrawna_Nazwa&gt;&#xD;\n    &lt;praw_formaFinansowania_Nazwa&gt;JEDNOSTKA SAMOFINANSUJĄCA NIE BĘDĄCA JEDNOSTKĄ BUDŻETOWĄ LUB SAMORZĄDOWYM ZAKŁADEM BUDŻETOWYM&lt;/praw_formaFinansowania_Nazwa&gt;&#xD;\n    &lt;praw_formaWlasnosci_Nazwa&gt;WŁASNOŚĆ KRAJOWYCH OSÓB FIZYCZNYCH&lt;/praw_formaWlasnosci_Nazwa&gt;&#xD;\n    &lt;praw_organZalozycielski_Nazwa /&gt;&#xD;\n    &lt;praw_organRejestrowy_Nazwa&gt;SĄD REJONOWY W OLSZTYNIE, VIII WYDZIAŁ GOSPODARCZY KRAJOWEGO REJESTRU SĄDOWEGO&lt;/praw_organRejestrowy_Nazwa&gt;&#xD;\n    &lt;praw_rodzajRejestruEwidencji_Nazwa&gt;REJESTR PRZEDSIĘBIORCÓW&lt;/praw_rodzajRejestruEwidencji_Nazwa&gt;&#xD;\n    &lt;praw_liczbaJednLokalnych&gt;0&lt;/praw_liczbaJednLokalnych&gt;&#xD;\n  &lt;/dane&gt;&#xD;\n&lt;/root&gt;</DanePobierzPelnyRaportResult></DanePobierzPelnyRaportResponse></s:Body></s:Envelope>\r\n--uuid:18e18dae-c6e6-44ff-bd8d-55fd420f328a+id=8292636--\r\n','{\"nazwa\":\"ASPANEL ELBLĄG SPÓŁKA Z OGRANICZONĄ ODPOWIEDZIALNOŚCIĄ\",\"nip\":\"5783183113\",\"regon\":\"543878570\",\"krs\":\"\",\"wojewodztwo\":\"WARMIŃSKO-MAZURSKIE\",\"powiat\":\"elbląski\",\"gmina\":\"Elbląg\",\"miejscowosc\":\"Kazimierzowo\",\"ulica\":\"\",\"nr_nieruchomosci\":\"35A\",\"nr_lokalu\":\"\",\"kod_pocztowy\":\"82-300\",\"poczta\":\"\",\"pkd_glowne\":\"\"}','gus_69933c9a944995.41449970',2,680,NULL),
(35,NULL,'2026-02-16 16:53:56','prod','nip','5783183113','BIR11OsPrawna',200,1,NULL,NULL,NULL,NULL,'<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<env:Envelope xmlns:env=\"http://www.w3.org/2003/05/soap-envelope\" xmlns:ns1=\"http://CIS/BIR/PUBL/2014/07\" xmlns:ns2=\"http://www.w3.org/2005/08/addressing\"><env:Header><ns2:Action env:mustUnderstand=\"true\">http://CIS/BIR/PUBL/2014/07/IUslugaBIRzewnPubl/DanePobierzPelnyRaport</ns2:Action><ns2:To env:mustUnderstand=\"true\">https://wyszukiwarkaregon.stat.gov.pl/wsBIR/UslugaBIRzewnPubl.svc</ns2:To><ns2:MessageID env:mustUnderstand=\"true\">urn:uuid:f2b7d10b-9ba6-4cfc-9a2d-32fdb46c2310</ns2:MessageID><wsa:ReplyTo xmlns:wsa=\"http://www.w3.org/2005/08/addressing\"><wsa:Address>http://www.w3.org/2005/08/addressing/anonymous</wsa:Address></wsa:ReplyTo><ns1:sid>mc5b9v3n8u538p64g9vp</ns1:sid></env:Header><env:Body><ns1:DanePobierzPelnyRaport><ns1:pRegon>543878570</ns1:pRegon><ns1:pNazwaRaportu>BIR11OsPrawna</ns1:pNazwaRaportu></ns1:DanePobierzPelnyRaport></env:Body></env:Envelope>\n','\r\n--uuid:18e18dae-c6e6-44ff-bd8d-55fd420f328a+id=8331412\r\nContent-ID: <http://tempuri.org/0>\r\nContent-Transfer-Encoding: 8bit\r\nContent-Type: application/xop+xml;charset=utf-8;type=\"application/soap+xml\"\r\n\r\n<s:Envelope xmlns:s=\"http://www.w3.org/2003/05/soap-envelope\" xmlns:a=\"http://www.w3.org/2005/08/addressing\"><s:Header><a:Action s:mustUnderstand=\"1\">http://CIS/BIR/PUBL/2014/07/IUslugaBIRzewnPubl/DanePobierzPelnyRaportResponse</a:Action><a:RelatesTo>urn:uuid:f2b7d10b-9ba6-4cfc-9a2d-32fdb46c2310</a:RelatesTo></s:Header><s:Body><DanePobierzPelnyRaportResponse xmlns=\"http://CIS/BIR/PUBL/2014/07\"><DanePobierzPelnyRaportResult>&lt;root&gt;&#xD;\n  &lt;dane&gt;&#xD;\n    &lt;praw_regon9&gt;543878570&lt;/praw_regon9&gt;&#xD;\n    &lt;praw_nip&gt;5783183113&lt;/praw_nip&gt;&#xD;\n    &lt;praw_statusNip /&gt;&#xD;\n    &lt;praw_nazwa&gt;ASPANEL ELBLĄG SPÓŁKA Z OGRANICZONĄ ODPOWIEDZIALNOŚCIĄ&lt;/praw_nazwa&gt;&#xD;\n    &lt;praw_nazwaSkrocona /&gt;&#xD;\n    &lt;praw_numerWRejestrzeEwidencji&gt;0001221166&lt;/praw_numerWRejestrzeEwidencji&gt;&#xD;\n    &lt;praw_dataWpisuDoRejestruEwidencji&gt;2026-02-02&lt;/praw_dataWpisuDoRejestruEwidencji&gt;&#xD;\n    &lt;praw_dataPowstania&gt;2026-02-02&lt;/praw_dataPowstania&gt;&#xD;\n    &lt;praw_dataRozpoczeciaDzialalnosci&gt;2026-02-02&lt;/praw_dataRozpoczeciaDzialalnosci&gt;&#xD;\n    &lt;praw_dataWpisuDoRegon&gt;2026-02-03&lt;/praw_dataWpisuDoRegon&gt;&#xD;\n    &lt;praw_dataZawieszeniaDzialalnosci /&gt;&#xD;\n    &lt;praw_dataWznowieniaDzialalnosci /&gt;&#xD;\n    &lt;praw_dataZaistnieniaZmiany&gt;2026-02-10&lt;/praw_dataZaistnieniaZmiany&gt;&#xD;\n    &lt;praw_dataZakonczeniaDzialalnosci /&gt;&#xD;\n    &lt;praw_dataSkresleniaZRegon /&gt;&#xD;\n    &lt;praw_dataOrzeczeniaOUpadlosci /&gt;&#xD;\n    &lt;praw_dataZakonczeniaPostepowaniaUpadlosciowego /&gt;&#xD;\n    &lt;praw_adSiedzKraj_Symbol&gt;PL&lt;/praw_adSiedzKraj_Symbol&gt;&#xD;\n    &lt;praw_adSiedzWojewodztwo_Symbol&gt;28&lt;/praw_adSiedzWojewodztwo_Symbol&gt;&#xD;\n    &lt;praw_adSiedzPowiat_Symbol&gt;04&lt;/praw_adSiedzPowiat_Symbol&gt;&#xD;\n    &lt;praw_adSiedzGmina_Symbol&gt;012&lt;/praw_adSiedzGmina_Symbol&gt;&#xD;\n    &lt;praw_adSiedzKodPocztowy&gt;82300&lt;/praw_adSiedzKodPocztowy&gt;&#xD;\n    &lt;praw_adSiedzMiejscowoscPoczty_Symbol&gt;0932703&lt;/praw_adSiedzMiejscowoscPoczty_Symbol&gt;&#xD;\n    &lt;praw_adSiedzMiejscowosc_Symbol&gt;0149363&lt;/praw_adSiedzMiejscowosc_Symbol&gt;&#xD;\n    &lt;praw_adSiedzUlica_Symbol /&gt;&#xD;\n    &lt;praw_adSiedzNumerNieruchomosci&gt;35A&lt;/praw_adSiedzNumerNieruchomosci&gt;&#xD;\n    &lt;praw_adSiedzNumerLokalu /&gt;&#xD;\n    &lt;praw_adSiedzNietypoweMiejsceLokalizacji /&gt;&#xD;\n    &lt;praw_numerTelefonu /&gt;&#xD;\n    &lt;praw_numerWewnetrznyTelefonu /&gt;&#xD;\n    &lt;praw_numerFaksu /&gt;&#xD;\n    &lt;praw_adresEmail /&gt;&#xD;\n    &lt;praw_adresStronyinternetowej /&gt;&#xD;\n    &lt;praw_adSiedzKraj_Nazwa&gt;POLSKA&lt;/praw_adSiedzKraj_Nazwa&gt;&#xD;\n    &lt;praw_adSiedzWojewodztwo_Nazwa&gt;WARMIŃSKO-MAZURSKIE&lt;/praw_adSiedzWojewodztwo_Nazwa&gt;&#xD;\n    &lt;praw_adSiedzPowiat_Nazwa&gt;elbląski&lt;/praw_adSiedzPowiat_Nazwa&gt;&#xD;\n    &lt;praw_adSiedzGmina_Nazwa&gt;Elbląg&lt;/praw_adSiedzGmina_Nazwa&gt;&#xD;\n    &lt;praw_adSiedzMiejscowosc_Nazwa&gt;Kazimierzowo&lt;/praw_adSiedzMiejscowosc_Nazwa&gt;&#xD;\n    &lt;praw_adSiedzMiejscowoscPoczty_Nazwa&gt;Elbląg&lt;/praw_adSiedzMiejscowoscPoczty_Nazwa&gt;&#xD;\n    &lt;praw_adSiedzUlica_Nazwa /&gt;&#xD;\n    &lt;praw_podstawowaFormaPrawna_Symbol&gt;1&lt;/praw_podstawowaFormaPrawna_Symbol&gt;&#xD;\n    &lt;praw_szczegolnaFormaPrawna_Symbol&gt;117&lt;/praw_szczegolnaFormaPrawna_Symbol&gt;&#xD;\n    &lt;praw_formaFinansowania_Symbol&gt;1&lt;/praw_formaFinansowania_Symbol&gt;&#xD;\n    &lt;praw_formaWlasnosci_Symbol&gt;214&lt;/praw_formaWlasnosci_Symbol&gt;&#xD;\n    &lt;praw_organZalozycielski_Symbol /&gt;&#xD;\n    &lt;praw_organRejestrowy_Symbol&gt;071510010&lt;/praw_organRejestrowy_Symbol&gt;&#xD;\n    &lt;praw_rodzajRejestruEwidencji_Symbol&gt;138&lt;/praw_rodzajRejestruEwidencji_Symbol&gt;&#xD;\n    &lt;praw_podstawowaFormaPrawna_Nazwa&gt;OSOBA PRAWNA&lt;/praw_podstawowaFormaPrawna_Nazwa&gt;&#xD;\n    &lt;praw_szczegolnaFormaPrawna_Nazwa&gt;SPÓŁKI Z OGRANICZONĄ ODPOWIEDZIALNOŚCIĄ&lt;/praw_szczegolnaFormaPrawna_Nazwa&gt;&#xD;\n    &lt;praw_formaFinansowania_Nazwa&gt;JEDNOSTKA SAMOFINANSUJĄCA NIE BĘDĄCA JEDNOSTKĄ BUDŻETOWĄ LUB SAMORZĄDOWYM ZAKŁADEM BUDŻETOWYM&lt;/praw_formaFinansowania_Nazwa&gt;&#xD;\n    &lt;praw_formaWlasnosci_Nazwa&gt;WŁASNOŚĆ KRAJOWYCH OSÓB FIZYCZNYCH&lt;/praw_formaWlasnosci_Nazwa&gt;&#xD;\n    &lt;praw_organZalozycielski_Nazwa /&gt;&#xD;\n    &lt;praw_organRejestrowy_Nazwa&gt;SĄD REJONOWY W OLSZTYNIE, VIII WYDZIAŁ GOSPODARCZY KRAJOWEGO REJESTRU SĄDOWEGO&lt;/praw_organRejestrowy_Nazwa&gt;&#xD;\n    &lt;praw_rodzajRejestruEwidencji_Nazwa&gt;REJESTR PRZEDSIĘBIORCÓW&lt;/praw_rodzajRejestruEwidencji_Nazwa&gt;&#xD;\n    &lt;praw_liczbaJednLokalnych&gt;0&lt;/praw_liczbaJednLokalnych&gt;&#xD;\n  &lt;/dane&gt;&#xD;\n&lt;/root&gt;</DanePobierzPelnyRaportResult></DanePobierzPelnyRaportResponse></s:Body></s:Envelope>\r\n--uuid:18e18dae-c6e6-44ff-bd8d-55fd420f328a+id=8331412--\r\n','{\"nazwa\":\"ASPANEL ELBLĄG SPÓŁKA Z OGRANICZONĄ ODPOWIEDZIALNOŚCIĄ\",\"nip\":\"5783183113\",\"regon\":\"543878570\",\"krs\":\"\",\"wojewodztwo\":\"WARMIŃSKO-MAZURSKIE\",\"powiat\":\"elbląski\",\"gmina\":\"Elbląg\",\"miejscowosc\":\"Kazimierzowo\",\"ulica\":\"\",\"nr_nieruchomosci\":\"35A\",\"nr_lokalu\":\"\",\"kod_pocztowy\":\"82-300\",\"poczta\":\"\",\"pkd_glowne\":\"\"}','gus_69933d93508d55.42915389',2,775,NULL),
(36,NULL,'2026-02-16 21:55:47','prod','nip','5792268412','BIR11OsPrawna',200,1,NULL,NULL,NULL,NULL,'<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<env:Envelope xmlns:env=\"http://www.w3.org/2003/05/soap-envelope\" xmlns:ns1=\"http://CIS/BIR/PUBL/2014/07\" xmlns:ns2=\"http://www.w3.org/2005/08/addressing\"><env:Header><ns2:Action env:mustUnderstand=\"true\">http://CIS/BIR/PUBL/2014/07/IUslugaBIRzewnPubl/DanePobierzPelnyRaport</ns2:Action><ns2:To env:mustUnderstand=\"true\">https://wyszukiwarkaregon.stat.gov.pl/wsBIR/UslugaBIRzewnPubl.svc</ns2:To><ns2:MessageID env:mustUnderstand=\"true\">urn:uuid:85aa2a9a-71e4-4179-99c9-e95fa0f50148</ns2:MessageID><wsa:ReplyTo xmlns:wsa=\"http://www.w3.org/2005/08/addressing\"><wsa:Address>http://www.w3.org/2005/08/addressing/anonymous</wsa:Address></wsa:ReplyTo><ns1:sid>87vy424bu53h5c7tds92</ns1:sid></env:Header><env:Body><ns1:DanePobierzPelnyRaport><ns1:pRegon>382001472</ns1:pRegon><ns1:pNazwaRaportu>BIR11OsPrawna</ns1:pNazwaRaportu></ns1:DanePobierzPelnyRaport></env:Body></env:Envelope>\n','\r\n--uuid:3d32aedf-2e8f-49cd-8ff0-57c1c4c3fa87+id=7238202\r\nContent-ID: <http://tempuri.org/0>\r\nContent-Transfer-Encoding: 8bit\r\nContent-Type: application/xop+xml;charset=utf-8;type=\"application/soap+xml\"\r\n\r\n<s:Envelope xmlns:s=\"http://www.w3.org/2003/05/soap-envelope\" xmlns:a=\"http://www.w3.org/2005/08/addressing\"><s:Header><a:Action s:mustUnderstand=\"1\">http://CIS/BIR/PUBL/2014/07/IUslugaBIRzewnPubl/DanePobierzPelnyRaportResponse</a:Action><a:RelatesTo>urn:uuid:85aa2a9a-71e4-4179-99c9-e95fa0f50148</a:RelatesTo></s:Header><s:Body><DanePobierzPelnyRaportResponse xmlns=\"http://CIS/BIR/PUBL/2014/07\"><DanePobierzPelnyRaportResult>&lt;root&gt;&#xD;\n  &lt;dane&gt;&#xD;\n    &lt;praw_regon9&gt;382001472&lt;/praw_regon9&gt;&#xD;\n    &lt;praw_nip&gt;5792268412&lt;/praw_nip&gt;&#xD;\n    &lt;praw_statusNip /&gt;&#xD;\n    &lt;praw_nazwa&gt;OHMG SPÓŁKA Z OGRANICZONĄ ODPOWIEDZIALNOŚCIĄ&lt;/praw_nazwa&gt;&#xD;\n    &lt;praw_nazwaSkrocona /&gt;&#xD;\n    &lt;praw_numerWRejestrzeEwidencji&gt;0000761846&lt;/praw_numerWRejestrzeEwidencji&gt;&#xD;\n    &lt;praw_dataWpisuDoRejestruEwidencji&gt;2018-12-10&lt;/praw_dataWpisuDoRejestruEwidencji&gt;&#xD;\n    &lt;praw_dataPowstania&gt;2018-12-10&lt;/praw_dataPowstania&gt;&#xD;\n    &lt;praw_dataRozpoczeciaDzialalnosci&gt;2018-12-10&lt;/praw_dataRozpoczeciaDzialalnosci&gt;&#xD;\n    &lt;praw_dataWpisuDoRegon&gt;2018-12-11&lt;/praw_dataWpisuDoRegon&gt;&#xD;\n    &lt;praw_dataZawieszeniaDzialalnosci /&gt;&#xD;\n    &lt;praw_dataWznowieniaDzialalnosci /&gt;&#xD;\n    &lt;praw_dataZaistnieniaZmiany&gt;2019-02-26&lt;/praw_dataZaistnieniaZmiany&gt;&#xD;\n    &lt;praw_dataZakonczeniaDzialalnosci /&gt;&#xD;\n    &lt;praw_dataSkresleniaZRegon /&gt;&#xD;\n    &lt;praw_dataOrzeczeniaOUpadlosci /&gt;&#xD;\n    &lt;praw_dataZakonczeniaPostepowaniaUpadlosciowego /&gt;&#xD;\n    &lt;praw_adSiedzKraj_Symbol&gt;PL&lt;/praw_adSiedzKraj_Symbol&gt;&#xD;\n    &lt;praw_adSiedzWojewodztwo_Symbol&gt;22&lt;/praw_adSiedzWojewodztwo_Symbol&gt;&#xD;\n    &lt;praw_adSiedzPowiat_Symbol&gt;09&lt;/praw_adSiedzPowiat_Symbol&gt;&#xD;\n    &lt;praw_adSiedzGmina_Symbol&gt;042&lt;/praw_adSiedzGmina_Symbol&gt;&#xD;\n    &lt;praw_adSiedzKodPocztowy&gt;82200&lt;/praw_adSiedzKodPocztowy&gt;&#xD;\n    &lt;praw_adSiedzMiejscowoscPoczty_Symbol&gt;0932815&lt;/praw_adSiedzMiejscowoscPoczty_Symbol&gt;&#xD;\n    &lt;praw_adSiedzMiejscowosc_Symbol&gt;0151880&lt;/praw_adSiedzMiejscowosc_Symbol&gt;&#xD;\n    &lt;praw_adSiedzUlica_Symbol /&gt;&#xD;\n    &lt;praw_adSiedzNumerNieruchomosci&gt;8&lt;/praw_adSiedzNumerNieruchomosci&gt;&#xD;\n    &lt;praw_adSiedzNumerLokalu /&gt;&#xD;\n    &lt;praw_adSiedzNietypoweMiejsceLokalizacji /&gt;&#xD;\n    &lt;praw_numerTelefonu&gt;881659971&lt;/praw_numerTelefonu&gt;&#xD;\n    &lt;praw_numerWewnetrznyTelefonu /&gt;&#xD;\n    &lt;praw_numerFaksu /&gt;&#xD;\n    &lt;praw_adresEmail&gt;OFFICE@OHMG.PL&lt;/praw_adresEmail&gt;&#xD;\n    &lt;praw_adresStronyinternetowej /&gt;&#xD;\n    &lt;praw_adSiedzKraj_Nazwa&gt;POLSKA&lt;/praw_adSiedzKraj_Nazwa&gt;&#xD;\n    &lt;praw_adSiedzWojewodztwo_Nazwa&gt;POMORSKIE&lt;/praw_adSiedzWojewodztwo_Nazwa&gt;&#xD;\n    &lt;praw_adSiedzPowiat_Nazwa&gt;malborski&lt;/praw_adSiedzPowiat_Nazwa&gt;&#xD;\n    &lt;praw_adSiedzGmina_Nazwa&gt;Malbork&lt;/praw_adSiedzGmina_Nazwa&gt;&#xD;\n    &lt;praw_adSiedzMiejscowosc_Nazwa&gt;Grobelno&lt;/praw_adSiedzMiejscowosc_Nazwa&gt;&#xD;\n    &lt;praw_adSiedzMiejscowoscPoczty_Nazwa&gt;Malbork&lt;/praw_adSiedzMiejscowoscPoczty_Nazwa&gt;&#xD;\n    &lt;praw_adSiedzUlica_Nazwa /&gt;&#xD;\n    &lt;praw_podstawowaFormaPrawna_Symbol&gt;1&lt;/praw_podstawowaFormaPrawna_Symbol&gt;&#xD;\n    &lt;praw_szczegolnaFormaPrawna_Symbol&gt;117&lt;/praw_szczegolnaFormaPrawna_Symbol&gt;&#xD;\n    &lt;praw_formaFinansowania_Symbol&gt;1&lt;/praw_formaFinansowania_Symbol&gt;&#xD;\n    &lt;praw_formaWlasnosci_Symbol /&gt;&#xD;\n    &lt;praw_organZalozycielski_Symbol /&gt;&#xD;\n    &lt;praw_organRejestrowy_Symbol&gt;071190030&lt;/praw_organRejestrowy_Symbol&gt;&#xD;\n    &lt;praw_rodzajRejestruEwidencji_Symbol&gt;138&lt;/praw_rodzajRejestruEwidencji_Symbol&gt;&#xD;\n    &lt;praw_podstawowaFormaPrawna_Nazwa&gt;OSOBA PRAWNA&lt;/praw_podstawowaFormaPrawna_Nazwa&gt;&#xD;\n    &lt;praw_szczegolnaFormaPrawna_Nazwa&gt;SPÓŁKI Z OGRANICZONĄ ODPOWIEDZIALNOŚCIĄ&lt;/praw_szczegolnaFormaPrawna_Nazwa&gt;&#xD;\n    &lt;praw_formaFinansowania_Nazwa&gt;JEDNOSTKA SAMOFINANSUJĄCA NIE BĘDĄCA JEDNOSTKĄ BUDŻETOWĄ LUB SAMORZĄDOWYM ZAKŁADEM BUDŻETOWYM&lt;/praw_formaFinansowania_Nazwa&gt;&#xD;\n    &lt;praw_formaWlasnosci_Nazwa /&gt;&#xD;\n    &lt;praw_organZalozycielski_Nazwa /&gt;&#xD;\n    &lt;praw_organRejestrowy_Nazwa&gt;SĄD REJONOWY GDAŃSK-PÓŁNOC W GDAŃSKU, VII WYDZIAŁ GOSPODARCZY KRAJOWEGO REJESTRU SĄDOWEGO&lt;/praw_organRejestrowy_Nazwa&gt;&#xD;\n    &lt;praw_rodzajRejestruEwidencji_Nazwa&gt;REJESTR PRZEDSIĘBIORCÓW&lt;/praw_rodzajRejestruEwidencji_Nazwa&gt;&#xD;\n    &lt;praw_liczbaJednLokalnych&gt;0&lt;/praw_liczbaJednLokalnych&gt;&#xD;\n  &lt;/dane&gt;&#xD;\n&lt;/root&gt;</DanePobierzPelnyRaportResult></DanePobierzPelnyRaportResponse></s:Body></s:Envelope>\r\n--uuid:3d32aedf-2e8f-49cd-8ff0-57c1c4c3fa87+id=7238202--\r\n','{\"nazwa\":\"OHMG SPÓŁKA Z OGRANICZONĄ ODPOWIEDZIALNOŚCIĄ\",\"nip\":\"5792268412\",\"regon\":\"382001472\",\"krs\":\"\",\"wojewodztwo\":\"POMORSKIE\",\"powiat\":\"malborski\",\"gmina\":\"Malbork\",\"miejscowosc\":\"Grobelno\",\"ulica\":\"\",\"nr_nieruchomosci\":\"8\",\"nr_lokalu\":\"\",\"kod_pocztowy\":\"82-200\",\"poczta\":\"\",\"pkd_glowne\":\"\"}','gus_6993845251a816.38534220',2,904,NULL),
(37,NULL,'2026-02-17 15:15:52','prod','nip','5783025288','BIR11OsPrawna',200,1,NULL,NULL,NULL,NULL,'<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<env:Envelope xmlns:env=\"http://www.w3.org/2003/05/soap-envelope\" xmlns:ns1=\"http://CIS/BIR/PUBL/2014/07\" xmlns:ns2=\"http://www.w3.org/2005/08/addressing\"><env:Header><ns2:Action env:mustUnderstand=\"true\">http://CIS/BIR/PUBL/2014/07/IUslugaBIRzewnPubl/DanePobierzPelnyRaport</ns2:Action><ns2:To env:mustUnderstand=\"true\">https://wyszukiwarkaregon.stat.gov.pl/wsBIR/UslugaBIRzewnPubl.svc</ns2:To><ns2:MessageID env:mustUnderstand=\"true\">urn:uuid:5926ce75-047b-4465-b403-6ad9bffd38b0</ns2:MessageID><wsa:ReplyTo xmlns:wsa=\"http://www.w3.org/2005/08/addressing\"><wsa:Address>http://www.w3.org/2005/08/addressing/anonymous</wsa:Address></wsa:ReplyTo><ns1:sid>6t6htt92h258y9r6c5m4</ns1:sid></env:Header><env:Body><ns1:DanePobierzPelnyRaport><ns1:pRegon>527085238</ns1:pRegon><ns1:pNazwaRaportu>BIR11OsPrawna</ns1:pNazwaRaportu></ns1:DanePobierzPelnyRaport></env:Body></env:Envelope>\n','\r\n--uuid:2033ed04-ab13-4fd9-b0ba-529b6fbea570+id=3185523\r\nContent-ID: <http://tempuri.org/0>\r\nContent-Transfer-Encoding: 8bit\r\nContent-Type: application/xop+xml;charset=utf-8;type=\"application/soap+xml\"\r\n\r\n<s:Envelope xmlns:s=\"http://www.w3.org/2003/05/soap-envelope\" xmlns:a=\"http://www.w3.org/2005/08/addressing\"><s:Header><a:Action s:mustUnderstand=\"1\">http://CIS/BIR/PUBL/2014/07/IUslugaBIRzewnPubl/DanePobierzPelnyRaportResponse</a:Action><a:RelatesTo>urn:uuid:5926ce75-047b-4465-b403-6ad9bffd38b0</a:RelatesTo></s:Header><s:Body><DanePobierzPelnyRaportResponse xmlns=\"http://CIS/BIR/PUBL/2014/07\"><DanePobierzPelnyRaportResult>&lt;root&gt;&#xD;\n  &lt;dane&gt;&#xD;\n    &lt;ErrorCode&gt;4&lt;/ErrorCode&gt;&#xD;\n    &lt;ErrorMessagePl&gt;Nie znaleziono wpisu dla podanych kryteriów wyszukiwania.&lt;/ErrorMessagePl&gt;&#xD;\n    &lt;ErrorMessageEn&gt;No data found for the specified search criteria.&lt;/ErrorMessageEn&gt;&#xD;\n    &lt;pRegon&gt;527085238&lt;/pRegon&gt;&#xD;\n    &lt;Typ_podmiotu&gt;F&lt;/Typ_podmiotu&gt;&#xD;\n    &lt;Raport&gt;BIR11OsPrawna&lt;/Raport&gt;&#xD;\n  &lt;/dane&gt;&#xD;\n&lt;/root&gt;</DanePobierzPelnyRaportResult></DanePobierzPelnyRaportResponse></s:Body></s:Envelope>\r\n--uuid:2033ed04-ab13-4fd9-b0ba-529b6fbea570+id=3185523--\r\n','{\"nazwa\":\"MebleJutra Jarosław Pelc\",\"nip\":\"5783025288\",\"regon\":\"527085238\",\"krs\":\"\",\"wojewodztwo\":\"WARMIŃSKO-MAZURSKIE\",\"powiat\":\"Elbląg\",\"gmina\":\"Elbląg\",\"miejscowosc\":\"Elbląg\",\"ulica\":\"ul. Piaskowa\",\"nr_nieruchomosci\":\"15\",\"nr_lokalu\":\"\",\"kod_pocztowy\":\"82-300\",\"poczta\":\"\",\"pkd_glowne\":\"\"}','gus_699478170b2778.88992318',2,1063,NULL),
(38,NULL,'2026-02-17 15:47:29','prod','nip','5783025288','BIR11OsPrawna',200,1,NULL,NULL,NULL,NULL,'<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<env:Envelope xmlns:env=\"http://www.w3.org/2003/05/soap-envelope\" xmlns:ns1=\"http://CIS/BIR/PUBL/2014/07\" xmlns:ns2=\"http://www.w3.org/2005/08/addressing\"><env:Header><ns2:Action env:mustUnderstand=\"true\">http://CIS/BIR/PUBL/2014/07/IUslugaBIRzewnPubl/DanePobierzPelnyRaport</ns2:Action><ns2:To env:mustUnderstand=\"true\">https://wyszukiwarkaregon.stat.gov.pl/wsBIR/UslugaBIRzewnPubl.svc</ns2:To><ns2:MessageID env:mustUnderstand=\"true\">urn:uuid:9ba747e1-987a-4009-b07f-e25e430b3392</ns2:MessageID><wsa:ReplyTo xmlns:wsa=\"http://www.w3.org/2005/08/addressing\"><wsa:Address>http://www.w3.org/2005/08/addressing/anonymous</wsa:Address></wsa:ReplyTo><ns1:sid>85dec33ft9vbrr26r6tp</ns1:sid></env:Header><env:Body><ns1:DanePobierzPelnyRaport><ns1:pRegon>527085238</ns1:pRegon><ns1:pNazwaRaportu>BIR11OsPrawna</ns1:pNazwaRaportu></ns1:DanePobierzPelnyRaport></env:Body></env:Envelope>\n','\r\n--uuid:2033ed04-ab13-4fd9-b0ba-529b6fbea570+id=3363621\r\nContent-ID: <http://tempuri.org/0>\r\nContent-Transfer-Encoding: 8bit\r\nContent-Type: application/xop+xml;charset=utf-8;type=\"application/soap+xml\"\r\n\r\n<s:Envelope xmlns:s=\"http://www.w3.org/2003/05/soap-envelope\" xmlns:a=\"http://www.w3.org/2005/08/addressing\"><s:Header><a:Action s:mustUnderstand=\"1\">http://CIS/BIR/PUBL/2014/07/IUslugaBIRzewnPubl/DanePobierzPelnyRaportResponse</a:Action><a:RelatesTo>urn:uuid:9ba747e1-987a-4009-b07f-e25e430b3392</a:RelatesTo></s:Header><s:Body><DanePobierzPelnyRaportResponse xmlns=\"http://CIS/BIR/PUBL/2014/07\"><DanePobierzPelnyRaportResult>&lt;root&gt;&#xD;\n  &lt;dane&gt;&#xD;\n    &lt;ErrorCode&gt;4&lt;/ErrorCode&gt;&#xD;\n    &lt;ErrorMessagePl&gt;Nie znaleziono wpisu dla podanych kryteriów wyszukiwania.&lt;/ErrorMessagePl&gt;&#xD;\n    &lt;ErrorMessageEn&gt;No data found for the specified search criteria.&lt;/ErrorMessageEn&gt;&#xD;\n    &lt;pRegon&gt;527085238&lt;/pRegon&gt;&#xD;\n    &lt;Typ_podmiotu&gt;F&lt;/Typ_podmiotu&gt;&#xD;\n    &lt;Raport&gt;BIR11OsPrawna&lt;/Raport&gt;&#xD;\n  &lt;/dane&gt;&#xD;\n&lt;/root&gt;</DanePobierzPelnyRaportResult></DanePobierzPelnyRaportResponse></s:Body></s:Envelope>\r\n--uuid:2033ed04-ab13-4fd9-b0ba-529b6fbea570+id=3363621--\r\n','{\"nazwa\":\"MebleJutra Jarosław Pelc\",\"nip\":\"5783025288\",\"regon\":\"527085238\",\"krs\":\"\",\"wojewodztwo\":\"WARMIŃSKO-MAZURSKIE\",\"powiat\":\"Elbląg\",\"gmina\":\"Elbląg\",\"miejscowosc\":\"Elbląg\",\"ulica\":\"ul. Piaskowa\",\"nr_nieruchomosci\":\"15\",\"nr_lokalu\":\"\",\"kod_pocztowy\":\"82-300\",\"poczta\":\"\",\"pkd_glowne\":\"\"}','gus_69947f80c10d67.14005282',2,1080,NULL),
(39,NULL,'2026-02-17 15:54:52','prod','nip','5783025288','BIR11OsPrawna',200,1,NULL,NULL,NULL,NULL,'<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<env:Envelope xmlns:env=\"http://www.w3.org/2003/05/soap-envelope\" xmlns:ns1=\"http://CIS/BIR/PUBL/2014/07\" xmlns:ns2=\"http://www.w3.org/2005/08/addressing\"><env:Header><ns2:Action env:mustUnderstand=\"true\">http://CIS/BIR/PUBL/2014/07/IUslugaBIRzewnPubl/DanePobierzPelnyRaport</ns2:Action><ns2:To env:mustUnderstand=\"true\">https://wyszukiwarkaregon.stat.gov.pl/wsBIR/UslugaBIRzewnPubl.svc</ns2:To><ns2:MessageID env:mustUnderstand=\"true\">urn:uuid:156c59d6-bb4b-46ea-951a-fd0667800726</ns2:MessageID><wsa:ReplyTo xmlns:wsa=\"http://www.w3.org/2005/08/addressing\"><wsa:Address>http://www.w3.org/2005/08/addressing/anonymous</wsa:Address></wsa:ReplyTo><ns1:sid>f4y6tc3zs432cmvn5wcz</ns1:sid></env:Header><env:Body><ns1:DanePobierzPelnyRaport><ns1:pRegon>527085238</ns1:pRegon><ns1:pNazwaRaportu>BIR11OsPrawna</ns1:pNazwaRaportu></ns1:DanePobierzPelnyRaport></env:Body></env:Envelope>\n','\r\n--uuid:2033ed04-ab13-4fd9-b0ba-529b6fbea570+id=3401768\r\nContent-ID: <http://tempuri.org/0>\r\nContent-Transfer-Encoding: 8bit\r\nContent-Type: application/xop+xml;charset=utf-8;type=\"application/soap+xml\"\r\n\r\n<s:Envelope xmlns:s=\"http://www.w3.org/2003/05/soap-envelope\" xmlns:a=\"http://www.w3.org/2005/08/addressing\"><s:Header><a:Action s:mustUnderstand=\"1\">http://CIS/BIR/PUBL/2014/07/IUslugaBIRzewnPubl/DanePobierzPelnyRaportResponse</a:Action><a:RelatesTo>urn:uuid:156c59d6-bb4b-46ea-951a-fd0667800726</a:RelatesTo></s:Header><s:Body><DanePobierzPelnyRaportResponse xmlns=\"http://CIS/BIR/PUBL/2014/07\"><DanePobierzPelnyRaportResult>&lt;root&gt;&#xD;\n  &lt;dane&gt;&#xD;\n    &lt;ErrorCode&gt;4&lt;/ErrorCode&gt;&#xD;\n    &lt;ErrorMessagePl&gt;Nie znaleziono wpisu dla podanych kryteriów wyszukiwania.&lt;/ErrorMessagePl&gt;&#xD;\n    &lt;ErrorMessageEn&gt;No data found for the specified search criteria.&lt;/ErrorMessageEn&gt;&#xD;\n    &lt;pRegon&gt;527085238&lt;/pRegon&gt;&#xD;\n    &lt;Typ_podmiotu&gt;F&lt;/Typ_podmiotu&gt;&#xD;\n    &lt;Raport&gt;BIR11OsPrawna&lt;/Raport&gt;&#xD;\n  &lt;/dane&gt;&#xD;\n&lt;/root&gt;</DanePobierzPelnyRaportResult></DanePobierzPelnyRaportResponse></s:Body></s:Envelope>\r\n--uuid:2033ed04-ab13-4fd9-b0ba-529b6fbea570+id=3401768--\r\n','{\"nazwa\":\"MebleJutra Jarosław Pelc\",\"nip\":\"5783025288\",\"regon\":\"527085238\",\"krs\":\"\",\"wojewodztwo\":\"WARMIŃSKO-MAZURSKIE\",\"powiat\":\"Elbląg\",\"gmina\":\"Elbląg\",\"miejscowosc\":\"Elbląg\",\"ulica\":\"ul. Piaskowa\",\"nr_nieruchomosci\":\"15\",\"nr_lokalu\":\"\",\"kod_pocztowy\":\"82-300\",\"poczta\":\"\",\"pkd_glowne\":\"\"}','gus_6994813abf5de2.67176780',2,1261,NULL),
(40,NULL,'2026-02-17 15:56:56','prod','nip','5783025288','BIR11OsPrawna',200,1,NULL,NULL,NULL,NULL,'<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<env:Envelope xmlns:env=\"http://www.w3.org/2003/05/soap-envelope\" xmlns:ns1=\"http://CIS/BIR/PUBL/2014/07\" xmlns:ns2=\"http://www.w3.org/2005/08/addressing\"><env:Header><ns2:Action env:mustUnderstand=\"true\">http://CIS/BIR/PUBL/2014/07/IUslugaBIRzewnPubl/DanePobierzPelnyRaport</ns2:Action><ns2:To env:mustUnderstand=\"true\">https://wyszukiwarkaregon.stat.gov.pl/wsBIR/UslugaBIRzewnPubl.svc</ns2:To><ns2:MessageID env:mustUnderstand=\"true\">urn:uuid:1477822a-96fc-47ec-a398-82da899672c8</ns2:MessageID><wsa:ReplyTo xmlns:wsa=\"http://www.w3.org/2005/08/addressing\"><wsa:Address>http://www.w3.org/2005/08/addressing/anonymous</wsa:Address></wsa:ReplyTo><ns1:sid>256srtb893262s63t39p</ns1:sid></env:Header><env:Body><ns1:DanePobierzPelnyRaport><ns1:pRegon>527085238</ns1:pRegon><ns1:pNazwaRaportu>BIR11OsPrawna</ns1:pNazwaRaportu></ns1:DanePobierzPelnyRaport></env:Body></env:Envelope>\n','\r\n--uuid:2033ed04-ab13-4fd9-b0ba-529b6fbea570+id=3412393\r\nContent-ID: <http://tempuri.org/0>\r\nContent-Transfer-Encoding: 8bit\r\nContent-Type: application/xop+xml;charset=utf-8;type=\"application/soap+xml\"\r\n\r\n<s:Envelope xmlns:s=\"http://www.w3.org/2003/05/soap-envelope\" xmlns:a=\"http://www.w3.org/2005/08/addressing\"><s:Header><a:Action s:mustUnderstand=\"1\">http://CIS/BIR/PUBL/2014/07/IUslugaBIRzewnPubl/DanePobierzPelnyRaportResponse</a:Action><a:RelatesTo>urn:uuid:1477822a-96fc-47ec-a398-82da899672c8</a:RelatesTo></s:Header><s:Body><DanePobierzPelnyRaportResponse xmlns=\"http://CIS/BIR/PUBL/2014/07\"><DanePobierzPelnyRaportResult>&lt;root&gt;&#xD;\n  &lt;dane&gt;&#xD;\n    &lt;ErrorCode&gt;4&lt;/ErrorCode&gt;&#xD;\n    &lt;ErrorMessagePl&gt;Nie znaleziono wpisu dla podanych kryteriów wyszukiwania.&lt;/ErrorMessagePl&gt;&#xD;\n    &lt;ErrorMessageEn&gt;No data found for the specified search criteria.&lt;/ErrorMessageEn&gt;&#xD;\n    &lt;pRegon&gt;527085238&lt;/pRegon&gt;&#xD;\n    &lt;Typ_podmiotu&gt;F&lt;/Typ_podmiotu&gt;&#xD;\n    &lt;Raport&gt;BIR11OsPrawna&lt;/Raport&gt;&#xD;\n  &lt;/dane&gt;&#xD;\n&lt;/root&gt;</DanePobierzPelnyRaportResult></DanePobierzPelnyRaportResponse></s:Body></s:Envelope>\r\n--uuid:2033ed04-ab13-4fd9-b0ba-529b6fbea570+id=3412393--\r\n','{\"nazwa\":\"MebleJutra Jarosław Pelc\",\"nip\":\"5783025288\",\"regon\":\"527085238\",\"krs\":\"\",\"wojewodztwo\":\"WARMIŃSKO-MAZURSKIE\",\"powiat\":\"Elbląg\",\"gmina\":\"Elbląg\",\"miejscowosc\":\"Elbląg\",\"ulica\":\"ul. Piaskowa\",\"nr_nieruchomosci\":\"15\",\"nr_lokalu\":\"\",\"kod_pocztowy\":\"82-300\",\"poczta\":\"\",\"pkd_glowne\":\"\"}','gus_699481b6ee1299.82399200',2,1086,NULL),
(41,NULL,'2026-02-17 15:59:34','prod','nip','5783132297','BIR11OsPrawna',200,1,NULL,NULL,NULL,NULL,'<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<env:Envelope xmlns:env=\"http://www.w3.org/2003/05/soap-envelope\" xmlns:ns1=\"http://CIS/BIR/PUBL/2014/07\" xmlns:ns2=\"http://www.w3.org/2005/08/addressing\"><env:Header><ns2:Action env:mustUnderstand=\"true\">http://CIS/BIR/PUBL/2014/07/IUslugaBIRzewnPubl/DanePobierzPelnyRaport</ns2:Action><ns2:To env:mustUnderstand=\"true\">https://wyszukiwarkaregon.stat.gov.pl/wsBIR/UslugaBIRzewnPubl.svc</ns2:To><ns2:MessageID env:mustUnderstand=\"true\">urn:uuid:ebde91d1-247b-4d66-8856-be1013ee5c47</ns2:MessageID><wsa:ReplyTo xmlns:wsa=\"http://www.w3.org/2005/08/addressing\"><wsa:Address>http://www.w3.org/2005/08/addressing/anonymous</wsa:Address></wsa:ReplyTo><ns1:sid>7bv27wh2882zrsfygk9p</ns1:sid></env:Header><env:Body><ns1:DanePobierzPelnyRaport><ns1:pRegon>380810026</ns1:pRegon><ns1:pNazwaRaportu>BIR11OsPrawna</ns1:pNazwaRaportu></ns1:DanePobierzPelnyRaport></env:Body></env:Envelope>\n','\r\n--uuid:2033ed04-ab13-4fd9-b0ba-529b6fbea570+id=3424251\r\nContent-ID: <http://tempuri.org/0>\r\nContent-Transfer-Encoding: 8bit\r\nContent-Type: application/xop+xml;charset=utf-8;type=\"application/soap+xml\"\r\n\r\n<s:Envelope xmlns:s=\"http://www.w3.org/2003/05/soap-envelope\" xmlns:a=\"http://www.w3.org/2005/08/addressing\"><s:Header><a:Action s:mustUnderstand=\"1\">http://CIS/BIR/PUBL/2014/07/IUslugaBIRzewnPubl/DanePobierzPelnyRaportResponse</a:Action><a:RelatesTo>urn:uuid:ebde91d1-247b-4d66-8856-be1013ee5c47</a:RelatesTo></s:Header><s:Body><DanePobierzPelnyRaportResponse xmlns=\"http://CIS/BIR/PUBL/2014/07\"><DanePobierzPelnyRaportResult>&lt;root&gt;&#xD;\n  &lt;dane&gt;&#xD;\n    &lt;ErrorCode&gt;4&lt;/ErrorCode&gt;&#xD;\n    &lt;ErrorMessagePl&gt;Nie znaleziono wpisu dla podanych kryteriów wyszukiwania.&lt;/ErrorMessagePl&gt;&#xD;\n    &lt;ErrorMessageEn&gt;No data found for the specified search criteria.&lt;/ErrorMessageEn&gt;&#xD;\n    &lt;pRegon&gt;380810026&lt;/pRegon&gt;&#xD;\n    &lt;Typ_podmiotu&gt;F&lt;/Typ_podmiotu&gt;&#xD;\n    &lt;Raport&gt;BIR11OsPrawna&lt;/Raport&gt;&#xD;\n  &lt;/dane&gt;&#xD;\n&lt;/root&gt;</DanePobierzPelnyRaportResult></DanePobierzPelnyRaportResponse></s:Body></s:Envelope>\r\n--uuid:2033ed04-ab13-4fd9-b0ba-529b6fbea570+id=3424251--\r\n','{\"nazwa\":\"KASCOMP PIOTR GUBA\",\"nip\":\"5783132297\",\"regon\":\"380810026\",\"krs\":\"\",\"wojewodztwo\":\"WARMIŃSKO-MAZURSKIE\",\"powiat\":\"Elbląg\",\"gmina\":\"Elbląg\",\"miejscowosc\":\"Elbląg\",\"ulica\":\"ul. Wieżowa\",\"nr_nieruchomosci\":\"13\",\"nr_lokalu\":\"\",\"kod_pocztowy\":\"82-300\",\"poczta\":\"\",\"pkd_glowne\":\"\"}','gus_69948253983be4.60369414',2,3172,NULL),
(42,NULL,'2026-02-17 16:03:15','prod','nip','5783132297','BIR11OsPrawna',200,1,NULL,NULL,NULL,NULL,'<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<env:Envelope xmlns:env=\"http://www.w3.org/2003/05/soap-envelope\" xmlns:ns1=\"http://CIS/BIR/PUBL/2014/07\" xmlns:ns2=\"http://www.w3.org/2005/08/addressing\"><env:Header><ns2:Action env:mustUnderstand=\"true\">http://CIS/BIR/PUBL/2014/07/IUslugaBIRzewnPubl/DanePobierzPelnyRaport</ns2:Action><ns2:To env:mustUnderstand=\"true\">https://wyszukiwarkaregon.stat.gov.pl/wsBIR/UslugaBIRzewnPubl.svc</ns2:To><ns2:MessageID env:mustUnderstand=\"true\">urn:uuid:870904ce-f0bc-41eb-9724-5549298c7211</ns2:MessageID><wsa:ReplyTo xmlns:wsa=\"http://www.w3.org/2005/08/addressing\"><wsa:Address>http://www.w3.org/2005/08/addressing/anonymous</wsa:Address></wsa:ReplyTo><ns1:sid>gvr25yb8th459r3zenuz</ns1:sid></env:Header><env:Body><ns1:DanePobierzPelnyRaport><ns1:pRegon>380810026</ns1:pRegon><ns1:pNazwaRaportu>BIR11OsPrawna</ns1:pNazwaRaportu></ns1:DanePobierzPelnyRaport></env:Body></env:Envelope>\n','\r\n--uuid:2033ed04-ab13-4fd9-b0ba-529b6fbea570+id=3442666\r\nContent-ID: <http://tempuri.org/0>\r\nContent-Transfer-Encoding: 8bit\r\nContent-Type: application/xop+xml;charset=utf-8;type=\"application/soap+xml\"\r\n\r\n<s:Envelope xmlns:s=\"http://www.w3.org/2003/05/soap-envelope\" xmlns:a=\"http://www.w3.org/2005/08/addressing\"><s:Header><a:Action s:mustUnderstand=\"1\">http://CIS/BIR/PUBL/2014/07/IUslugaBIRzewnPubl/DanePobierzPelnyRaportResponse</a:Action><a:RelatesTo>urn:uuid:870904ce-f0bc-41eb-9724-5549298c7211</a:RelatesTo></s:Header><s:Body><DanePobierzPelnyRaportResponse xmlns=\"http://CIS/BIR/PUBL/2014/07\"><DanePobierzPelnyRaportResult>&lt;root&gt;&#xD;\n  &lt;dane&gt;&#xD;\n    &lt;ErrorCode&gt;4&lt;/ErrorCode&gt;&#xD;\n    &lt;ErrorMessagePl&gt;Nie znaleziono wpisu dla podanych kryteriów wyszukiwania.&lt;/ErrorMessagePl&gt;&#xD;\n    &lt;ErrorMessageEn&gt;No data found for the specified search criteria.&lt;/ErrorMessageEn&gt;&#xD;\n    &lt;pRegon&gt;380810026&lt;/pRegon&gt;&#xD;\n    &lt;Typ_podmiotu&gt;F&lt;/Typ_podmiotu&gt;&#xD;\n    &lt;Raport&gt;BIR11OsPrawna&lt;/Raport&gt;&#xD;\n  &lt;/dane&gt;&#xD;\n&lt;/root&gt;</DanePobierzPelnyRaportResult></DanePobierzPelnyRaportResponse></s:Body></s:Envelope>\r\n--uuid:2033ed04-ab13-4fd9-b0ba-529b6fbea570+id=3442666--\r\n','{\"nazwa\":\"KASCOMP PIOTR GUBA\",\"nip\":\"5783132297\",\"regon\":\"380810026\",\"krs\":\"\",\"wojewodztwo\":\"WARMIŃSKO-MAZURSKIE\",\"powiat\":\"Elbląg\",\"gmina\":\"Elbląg\",\"miejscowosc\":\"Elbląg\",\"ulica\":\"ul. Wieżowa\",\"nr_nieruchomosci\":\"13\",\"nr_lokalu\":\"\",\"kod_pocztowy\":\"82-300\",\"poczta\":\"\",\"pkd_glowne\":\"\"}','gus_699483329ab806.35440305',2,1090,NULL),
(43,NULL,'2026-02-17 16:11:08','prod','nip','5783132297','BIR11OsPrawna',200,1,NULL,NULL,NULL,NULL,'<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<env:Envelope xmlns:env=\"http://www.w3.org/2003/05/soap-envelope\" xmlns:ns1=\"http://CIS/BIR/PUBL/2014/07\" xmlns:ns2=\"http://www.w3.org/2005/08/addressing\"><env:Header><ns2:Action env:mustUnderstand=\"true\">http://CIS/BIR/PUBL/2014/07/IUslugaBIRzewnPubl/DanePobierzPelnyRaport</ns2:Action><ns2:To env:mustUnderstand=\"true\">https://wyszukiwarkaregon.stat.gov.pl/wsBIR/UslugaBIRzewnPubl.svc</ns2:To><ns2:MessageID env:mustUnderstand=\"true\">urn:uuid:24de8d4e-78f3-45d1-a183-01444b856da8</ns2:MessageID><wsa:ReplyTo xmlns:wsa=\"http://www.w3.org/2005/08/addressing\"><wsa:Address>http://www.w3.org/2005/08/addressing/anonymous</wsa:Address></wsa:ReplyTo><ns1:sid>9p7ebh8exy77z7534g5z</ns1:sid></env:Header><env:Body><ns1:DanePobierzPelnyRaport><ns1:pRegon>380810026</ns1:pRegon><ns1:pNazwaRaportu>BIR11OsPrawna</ns1:pNazwaRaportu></ns1:DanePobierzPelnyRaport></env:Body></env:Envelope>\n','\r\n--uuid:2033ed04-ab13-4fd9-b0ba-529b6fbea570+id=3478824\r\nContent-ID: <http://tempuri.org/0>\r\nContent-Transfer-Encoding: 8bit\r\nContent-Type: application/xop+xml;charset=utf-8;type=\"application/soap+xml\"\r\n\r\n<s:Envelope xmlns:s=\"http://www.w3.org/2003/05/soap-envelope\" xmlns:a=\"http://www.w3.org/2005/08/addressing\"><s:Header><a:Action s:mustUnderstand=\"1\">http://CIS/BIR/PUBL/2014/07/IUslugaBIRzewnPubl/DanePobierzPelnyRaportResponse</a:Action><a:RelatesTo>urn:uuid:24de8d4e-78f3-45d1-a183-01444b856da8</a:RelatesTo></s:Header><s:Body><DanePobierzPelnyRaportResponse xmlns=\"http://CIS/BIR/PUBL/2014/07\"><DanePobierzPelnyRaportResult>&lt;root&gt;&#xD;\n  &lt;dane&gt;&#xD;\n    &lt;ErrorCode&gt;4&lt;/ErrorCode&gt;&#xD;\n    &lt;ErrorMessagePl&gt;Nie znaleziono wpisu dla podanych kryteriów wyszukiwania.&lt;/ErrorMessagePl&gt;&#xD;\n    &lt;ErrorMessageEn&gt;No data found for the specified search criteria.&lt;/ErrorMessageEn&gt;&#xD;\n    &lt;pRegon&gt;380810026&lt;/pRegon&gt;&#xD;\n    &lt;Typ_podmiotu&gt;F&lt;/Typ_podmiotu&gt;&#xD;\n    &lt;Raport&gt;BIR11OsPrawna&lt;/Raport&gt;&#xD;\n  &lt;/dane&gt;&#xD;\n&lt;/root&gt;</DanePobierzPelnyRaportResult></DanePobierzPelnyRaportResponse></s:Body></s:Envelope>\r\n--uuid:2033ed04-ab13-4fd9-b0ba-529b6fbea570+id=3478824--\r\n','{\"nazwa\":\"KASCOMP PIOTR GUBA\",\"nip\":\"5783132297\",\"regon\":\"380810026\",\"krs\":\"\",\"wojewodztwo\":\"WARMIŃSKO-MAZURSKIE\",\"powiat\":\"Elbląg\",\"gmina\":\"Elbląg\",\"miejscowosc\":\"Elbląg\",\"ulica\":\"ul. Wieżowa\",\"nr_nieruchomosci\":\"13\",\"nr_lokalu\":\"\",\"kod_pocztowy\":\"82-300\",\"poczta\":\"\",\"pkd_glowne\":\"\"}','gus_6994850b76fe61.03022111',2,1157,NULL),
(44,NULL,'2026-02-17 20:50:53','prod','nip','5782751414','BIR11OsPrawna',200,1,NULL,NULL,NULL,NULL,'<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<env:Envelope xmlns:env=\"http://www.w3.org/2003/05/soap-envelope\" xmlns:ns1=\"http://CIS/BIR/PUBL/2014/07\" xmlns:ns2=\"http://www.w3.org/2005/08/addressing\"><env:Header><ns2:Action env:mustUnderstand=\"true\">http://CIS/BIR/PUBL/2014/07/IUslugaBIRzewnPubl/DanePobierzPelnyRaport</ns2:Action><ns2:To env:mustUnderstand=\"true\">https://wyszukiwarkaregon.stat.gov.pl/wsBIR/UslugaBIRzewnPubl.svc</ns2:To><ns2:MessageID env:mustUnderstand=\"true\">urn:uuid:15da7291-82d0-4776-a702-c322064f7b68</ns2:MessageID><wsa:ReplyTo xmlns:wsa=\"http://www.w3.org/2005/08/addressing\"><wsa:Address>http://www.w3.org/2005/08/addressing/anonymous</wsa:Address></wsa:ReplyTo><ns1:sid>7p76dt43ydmf23296sfd</ns1:sid></env:Header><env:Body><ns1:DanePobierzPelnyRaport><ns1:pRegon>220671860</ns1:pRegon><ns1:pNazwaRaportu>BIR11OsPrawna</ns1:pNazwaRaportu></ns1:DanePobierzPelnyRaport></env:Body></env:Envelope>\n','\r\n--uuid:fbc198e0-cf20-4d06-a3dc-957d8ce9c455+id=10633640\r\nContent-ID: <http://tempuri.org/0>\r\nContent-Transfer-Encoding: 8bit\r\nContent-Type: application/xop+xml;charset=utf-8;type=\"application/soap+xml\"\r\n\r\n<s:Envelope xmlns:s=\"http://www.w3.org/2003/05/soap-envelope\" xmlns:a=\"http://www.w3.org/2005/08/addressing\"><s:Header><a:Action s:mustUnderstand=\"1\">http://CIS/BIR/PUBL/2014/07/IUslugaBIRzewnPubl/DanePobierzPelnyRaportResponse</a:Action><a:RelatesTo>urn:uuid:15da7291-82d0-4776-a702-c322064f7b68</a:RelatesTo></s:Header><s:Body><DanePobierzPelnyRaportResponse xmlns=\"http://CIS/BIR/PUBL/2014/07\"><DanePobierzPelnyRaportResult>&lt;root&gt;&#xD;\n  &lt;dane&gt;&#xD;\n    &lt;ErrorCode&gt;4&lt;/ErrorCode&gt;&#xD;\n    &lt;ErrorMessagePl&gt;Nie znaleziono wpisu dla podanych kryteriów wyszukiwania.&lt;/ErrorMessagePl&gt;&#xD;\n    &lt;ErrorMessageEn&gt;No data found for the specified search criteria.&lt;/ErrorMessageEn&gt;&#xD;\n    &lt;pRegon&gt;220671860&lt;/pRegon&gt;&#xD;\n    &lt;Typ_podmiotu&gt;F&lt;/Typ_podmiotu&gt;&#xD;\n    &lt;Raport&gt;BIR11OsPrawna&lt;/Raport&gt;&#xD;\n  &lt;/dane&gt;&#xD;\n&lt;/root&gt;</DanePobierzPelnyRaportResult></DanePobierzPelnyRaportResponse></s:Body></s:Envelope>\r\n--uuid:fbc198e0-cf20-4d06-a3dc-957d8ce9c455+id=10633640--\r\n','{\"nazwa\":\"5N Justyna Zienkiewicz\",\"nip\":\"5782751414\",\"regon\":\"220671860\",\"krs\":\"\",\"wojewodztwo\":\"WARMIŃSKO-MAZURSKIE\",\"powiat\":\"Elbląg\",\"gmina\":\"Elbląg\",\"miejscowosc\":\"Elbląg\",\"ulica\":\"ul. Natolińska\",\"nr_nieruchomosci\":\"46\",\"nr_lokalu\":\"\",\"kod_pocztowy\":\"82-300\",\"poczta\":\"\",\"pkd_glowne\":\"\"}','gus_6994c69be3b661.55134047',2,1065,NULL),
(45,NULL,'2026-02-17 20:52:55','prod','nip','5782848332','BIR11OsPrawna',200,1,NULL,NULL,NULL,NULL,'<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<env:Envelope xmlns:env=\"http://www.w3.org/2003/05/soap-envelope\" xmlns:ns1=\"http://CIS/BIR/PUBL/2014/07\" xmlns:ns2=\"http://www.w3.org/2005/08/addressing\"><env:Header><ns2:Action env:mustUnderstand=\"true\">http://CIS/BIR/PUBL/2014/07/IUslugaBIRzewnPubl/DanePobierzPelnyRaport</ns2:Action><ns2:To env:mustUnderstand=\"true\">https://wyszukiwarkaregon.stat.gov.pl/wsBIR/UslugaBIRzewnPubl.svc</ns2:To><ns2:MessageID env:mustUnderstand=\"true\">urn:uuid:eaf2d7fb-6c90-4ccb-b01c-ed19ad551128</ns2:MessageID><wsa:ReplyTo xmlns:wsa=\"http://www.w3.org/2005/08/addressing\"><wsa:Address>http://www.w3.org/2005/08/addressing/anonymous</wsa:Address></wsa:ReplyTo><ns1:sid>22k738gehh82848x2nsx</ns1:sid></env:Header><env:Body><ns1:DanePobierzPelnyRaport><ns1:pRegon>281474600</ns1:pRegon><ns1:pNazwaRaportu>BIR11OsPrawna</ns1:pNazwaRaportu></ns1:DanePobierzPelnyRaport></env:Body></env:Envelope>\n','\r\n--uuid:fbc198e0-cf20-4d06-a3dc-957d8ce9c455+id=10649135\r\nContent-ID: <http://tempuri.org/0>\r\nContent-Transfer-Encoding: 8bit\r\nContent-Type: application/xop+xml;charset=utf-8;type=\"application/soap+xml\"\r\n\r\n<s:Envelope xmlns:s=\"http://www.w3.org/2003/05/soap-envelope\" xmlns:a=\"http://www.w3.org/2005/08/addressing\"><s:Header><a:Action s:mustUnderstand=\"1\">http://CIS/BIR/PUBL/2014/07/IUslugaBIRzewnPubl/DanePobierzPelnyRaportResponse</a:Action><a:RelatesTo>urn:uuid:eaf2d7fb-6c90-4ccb-b01c-ed19ad551128</a:RelatesTo></s:Header><s:Body><DanePobierzPelnyRaportResponse xmlns=\"http://CIS/BIR/PUBL/2014/07\"><DanePobierzPelnyRaportResult>&lt;root&gt;&#xD;\n  &lt;dane&gt;&#xD;\n    &lt;ErrorCode&gt;4&lt;/ErrorCode&gt;&#xD;\n    &lt;ErrorMessagePl&gt;Nie znaleziono wpisu dla podanych kryteriów wyszukiwania.&lt;/ErrorMessagePl&gt;&#xD;\n    &lt;ErrorMessageEn&gt;No data found for the specified search criteria.&lt;/ErrorMessageEn&gt;&#xD;\n    &lt;pRegon&gt;281474600&lt;/pRegon&gt;&#xD;\n    &lt;Typ_podmiotu&gt;F&lt;/Typ_podmiotu&gt;&#xD;\n    &lt;Raport&gt;BIR11OsPrawna&lt;/Raport&gt;&#xD;\n  &lt;/dane&gt;&#xD;\n&lt;/root&gt;</DanePobierzPelnyRaportResult></DanePobierzPelnyRaportResponse></s:Body></s:Envelope>\r\n--uuid:fbc198e0-cf20-4d06-a3dc-957d8ce9c455+id=10649135--\r\n','{\"nazwa\":\"Adrian Wysocki Wsparcie IT\",\"nip\":\"5782848332\",\"regon\":\"281474600\",\"krs\":\"\",\"wojewodztwo\":\"WARMIŃSKO-MAZURSKIE\",\"powiat\":\"Elbląg\",\"gmina\":\"Elbląg\",\"miejscowosc\":\"Elbląg\",\"ulica\":\"ul. Natolińska\",\"nr_nieruchomosci\":\"32\",\"nr_lokalu\":\"\",\"kod_pocztowy\":\"82-300\",\"poczta\":\"\",\"pkd_glowne\":\"\"}','gus_6994c71664d3d6.51206907',2,880,NULL),
(46,NULL,'2026-02-17 20:53:15','prod','nip','5782968272','BIR11OsPrawna',200,1,NULL,NULL,NULL,NULL,'<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<env:Envelope xmlns:env=\"http://www.w3.org/2003/05/soap-envelope\" xmlns:ns1=\"http://CIS/BIR/PUBL/2014/07\" xmlns:ns2=\"http://www.w3.org/2005/08/addressing\"><env:Header><ns2:Action env:mustUnderstand=\"true\">http://CIS/BIR/PUBL/2014/07/IUslugaBIRzewnPubl/DanePobierzPelnyRaport</ns2:Action><ns2:To env:mustUnderstand=\"true\">https://wyszukiwarkaregon.stat.gov.pl/wsBIR/UslugaBIRzewnPubl.svc</ns2:To><ns2:MessageID env:mustUnderstand=\"true\">urn:uuid:26fcbb51-f6ba-436b-8b1f-ea9ab1f5381d</ns2:MessageID><wsa:ReplyTo xmlns:wsa=\"http://www.w3.org/2005/08/addressing\"><wsa:Address>http://www.w3.org/2005/08/addressing/anonymous</wsa:Address></wsa:ReplyTo><ns1:sid>e6z2dz8nksg9p8repcrd</ns1:sid></env:Header><env:Body><ns1:DanePobierzPelnyRaport><ns1:pRegon>386593653</ns1:pRegon><ns1:pNazwaRaportu>BIR11OsPrawna</ns1:pNazwaRaportu></ns1:DanePobierzPelnyRaport></env:Body></env:Envelope>\n','\r\n--uuid:fbc198e0-cf20-4d06-a3dc-957d8ce9c455+id=10652013\r\nContent-ID: <http://tempuri.org/0>\r\nContent-Transfer-Encoding: 8bit\r\nContent-Type: application/xop+xml;charset=utf-8;type=\"application/soap+xml\"\r\n\r\n<s:Envelope xmlns:s=\"http://www.w3.org/2003/05/soap-envelope\" xmlns:a=\"http://www.w3.org/2005/08/addressing\"><s:Header><a:Action s:mustUnderstand=\"1\">http://CIS/BIR/PUBL/2014/07/IUslugaBIRzewnPubl/DanePobierzPelnyRaportResponse</a:Action><a:RelatesTo>urn:uuid:26fcbb51-f6ba-436b-8b1f-ea9ab1f5381d</a:RelatesTo></s:Header><s:Body><DanePobierzPelnyRaportResponse xmlns=\"http://CIS/BIR/PUBL/2014/07\"><DanePobierzPelnyRaportResult>&lt;root&gt;&#xD;\n  &lt;dane&gt;&#xD;\n    &lt;ErrorCode&gt;4&lt;/ErrorCode&gt;&#xD;\n    &lt;ErrorMessagePl&gt;Nie znaleziono wpisu dla podanych kryteriów wyszukiwania.&lt;/ErrorMessagePl&gt;&#xD;\n    &lt;ErrorMessageEn&gt;No data found for the specified search criteria.&lt;/ErrorMessageEn&gt;&#xD;\n    &lt;pRegon&gt;386593653&lt;/pRegon&gt;&#xD;\n    &lt;Typ_podmiotu&gt;F&lt;/Typ_podmiotu&gt;&#xD;\n    &lt;Raport&gt;BIR11OsPrawna&lt;/Raport&gt;&#xD;\n  &lt;/dane&gt;&#xD;\n&lt;/root&gt;</DanePobierzPelnyRaportResult></DanePobierzPelnyRaportResponse></s:Body></s:Envelope>\r\n--uuid:fbc198e0-cf20-4d06-a3dc-957d8ce9c455+id=10652013--\r\n','{\"nazwa\":\"Alpintel Łukasz Binkowski\",\"nip\":\"5782968272\",\"regon\":\"386593653\",\"krs\":\"\",\"wojewodztwo\":\"WARMIŃSKO-MAZURSKIE\",\"powiat\":\"Elbląg\",\"gmina\":\"Elbląg\",\"miejscowosc\":\"Elbląg\",\"ulica\":\"ul. Natolińska\",\"nr_nieruchomosci\":\"31\",\"nr_lokalu\":\"\",\"kod_pocztowy\":\"82-300\",\"poczta\":\"\",\"pkd_glowne\":\"\"}','gus_6994c72aadd0f2.77144124',2,952,NULL),
(47,NULL,'2026-02-17 23:04:53','prod','nip','5792268412','BIR11OsPrawna',200,1,NULL,NULL,NULL,NULL,'<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<env:Envelope xmlns:env=\"http://www.w3.org/2003/05/soap-envelope\" xmlns:ns1=\"http://CIS/BIR/PUBL/2014/07\" xmlns:ns2=\"http://www.w3.org/2005/08/addressing\"><env:Header><ns2:Action env:mustUnderstand=\"true\">http://CIS/BIR/PUBL/2014/07/IUslugaBIRzewnPubl/DanePobierzPelnyRaport</ns2:Action><ns2:To env:mustUnderstand=\"true\">https://wyszukiwarkaregon.stat.gov.pl/wsBIR/UslugaBIRzewnPubl.svc</ns2:To><ns2:MessageID env:mustUnderstand=\"true\">urn:uuid:6f6ccb44-512d-4ec0-8778-8970ea8c563d</ns2:MessageID><wsa:ReplyTo xmlns:wsa=\"http://www.w3.org/2005/08/addressing\"><wsa:Address>http://www.w3.org/2005/08/addressing/anonymous</wsa:Address></wsa:ReplyTo><ns1:sid>bepu832c98nv2wd4cp73</ns1:sid></env:Header><env:Body><ns1:DanePobierzPelnyRaport><ns1:pRegon>382001472</ns1:pRegon><ns1:pNazwaRaportu>BIR11OsPrawna</ns1:pNazwaRaportu></ns1:DanePobierzPelnyRaport></env:Body></env:Envelope>\n','\r\n--uuid:4544f92f-5305-4069-a4a1-22deb1e311a5+id=7991629\r\nContent-ID: <http://tempuri.org/0>\r\nContent-Transfer-Encoding: 8bit\r\nContent-Type: application/xop+xml;charset=utf-8;type=\"application/soap+xml\"\r\n\r\n<s:Envelope xmlns:s=\"http://www.w3.org/2003/05/soap-envelope\" xmlns:a=\"http://www.w3.org/2005/08/addressing\"><s:Header><a:Action s:mustUnderstand=\"1\">http://CIS/BIR/PUBL/2014/07/IUslugaBIRzewnPubl/DanePobierzPelnyRaportResponse</a:Action><a:RelatesTo>urn:uuid:6f6ccb44-512d-4ec0-8778-8970ea8c563d</a:RelatesTo></s:Header><s:Body><DanePobierzPelnyRaportResponse xmlns=\"http://CIS/BIR/PUBL/2014/07\"><DanePobierzPelnyRaportResult>&lt;root&gt;&#xD;\n  &lt;dane&gt;&#xD;\n    &lt;praw_regon9&gt;382001472&lt;/praw_regon9&gt;&#xD;\n    &lt;praw_nip&gt;5792268412&lt;/praw_nip&gt;&#xD;\n    &lt;praw_statusNip /&gt;&#xD;\n    &lt;praw_nazwa&gt;OHMG SPÓŁKA Z OGRANICZONĄ ODPOWIEDZIALNOŚCIĄ&lt;/praw_nazwa&gt;&#xD;\n    &lt;praw_nazwaSkrocona /&gt;&#xD;\n    &lt;praw_numerWRejestrzeEwidencji&gt;0000761846&lt;/praw_numerWRejestrzeEwidencji&gt;&#xD;\n    &lt;praw_dataWpisuDoRejestruEwidencji&gt;2018-12-10&lt;/praw_dataWpisuDoRejestruEwidencji&gt;&#xD;\n    &lt;praw_dataPowstania&gt;2018-12-10&lt;/praw_dataPowstania&gt;&#xD;\n    &lt;praw_dataRozpoczeciaDzialalnosci&gt;2018-12-10&lt;/praw_dataRozpoczeciaDzialalnosci&gt;&#xD;\n    &lt;praw_dataWpisuDoRegon&gt;2018-12-11&lt;/praw_dataWpisuDoRegon&gt;&#xD;\n    &lt;praw_dataZawieszeniaDzialalnosci /&gt;&#xD;\n    &lt;praw_dataWznowieniaDzialalnosci /&gt;&#xD;\n    &lt;praw_dataZaistnieniaZmiany&gt;2019-02-26&lt;/praw_dataZaistnieniaZmiany&gt;&#xD;\n    &lt;praw_dataZakonczeniaDzialalnosci /&gt;&#xD;\n    &lt;praw_dataSkresleniaZRegon /&gt;&#xD;\n    &lt;praw_dataOrzeczeniaOUpadlosci /&gt;&#xD;\n    &lt;praw_dataZakonczeniaPostepowaniaUpadlosciowego /&gt;&#xD;\n    &lt;praw_adSiedzKraj_Symbol&gt;PL&lt;/praw_adSiedzKraj_Symbol&gt;&#xD;\n    &lt;praw_adSiedzWojewodztwo_Symbol&gt;22&lt;/praw_adSiedzWojewodztwo_Symbol&gt;&#xD;\n    &lt;praw_adSiedzPowiat_Symbol&gt;09&lt;/praw_adSiedzPowiat_Symbol&gt;&#xD;\n    &lt;praw_adSiedzGmina_Symbol&gt;042&lt;/praw_adSiedzGmina_Symbol&gt;&#xD;\n    &lt;praw_adSiedzKodPocztowy&gt;82200&lt;/praw_adSiedzKodPocztowy&gt;&#xD;\n    &lt;praw_adSiedzMiejscowoscPoczty_Symbol&gt;0932815&lt;/praw_adSiedzMiejscowoscPoczty_Symbol&gt;&#xD;\n    &lt;praw_adSiedzMiejscowosc_Symbol&gt;0151880&lt;/praw_adSiedzMiejscowosc_Symbol&gt;&#xD;\n    &lt;praw_adSiedzUlica_Symbol /&gt;&#xD;\n    &lt;praw_adSiedzNumerNieruchomosci&gt;8&lt;/praw_adSiedzNumerNieruchomosci&gt;&#xD;\n    &lt;praw_adSiedzNumerLokalu /&gt;&#xD;\n    &lt;praw_adSiedzNietypoweMiejsceLokalizacji /&gt;&#xD;\n    &lt;praw_numerTelefonu&gt;881659971&lt;/praw_numerTelefonu&gt;&#xD;\n    &lt;praw_numerWewnetrznyTelefonu /&gt;&#xD;\n    &lt;praw_numerFaksu /&gt;&#xD;\n    &lt;praw_adresEmail&gt;OFFICE@OHMG.PL&lt;/praw_adresEmail&gt;&#xD;\n    &lt;praw_adresStronyinternetowej /&gt;&#xD;\n    &lt;praw_adSiedzKraj_Nazwa&gt;POLSKA&lt;/praw_adSiedzKraj_Nazwa&gt;&#xD;\n    &lt;praw_adSiedzWojewodztwo_Nazwa&gt;POMORSKIE&lt;/praw_adSiedzWojewodztwo_Nazwa&gt;&#xD;\n    &lt;praw_adSiedzPowiat_Nazwa&gt;malborski&lt;/praw_adSiedzPowiat_Nazwa&gt;&#xD;\n    &lt;praw_adSiedzGmina_Nazwa&gt;Malbork&lt;/praw_adSiedzGmina_Nazwa&gt;&#xD;\n    &lt;praw_adSiedzMiejscowosc_Nazwa&gt;Grobelno&lt;/praw_adSiedzMiejscowosc_Nazwa&gt;&#xD;\n    &lt;praw_adSiedzMiejscowoscPoczty_Nazwa&gt;Malbork&lt;/praw_adSiedzMiejscowoscPoczty_Nazwa&gt;&#xD;\n    &lt;praw_adSiedzUlica_Nazwa /&gt;&#xD;\n    &lt;praw_podstawowaFormaPrawna_Symbol&gt;1&lt;/praw_podstawowaFormaPrawna_Symbol&gt;&#xD;\n    &lt;praw_szczegolnaFormaPrawna_Symbol&gt;117&lt;/praw_szczegolnaFormaPrawna_Symbol&gt;&#xD;\n    &lt;praw_formaFinansowania_Symbol&gt;1&lt;/praw_formaFinansowania_Symbol&gt;&#xD;\n    &lt;praw_formaWlasnosci_Symbol /&gt;&#xD;\n    &lt;praw_organZalozycielski_Symbol /&gt;&#xD;\n    &lt;praw_organRejestrowy_Symbol&gt;071190030&lt;/praw_organRejestrowy_Symbol&gt;&#xD;\n    &lt;praw_rodzajRejestruEwidencji_Symbol&gt;138&lt;/praw_rodzajRejestruEwidencji_Symbol&gt;&#xD;\n    &lt;praw_podstawowaFormaPrawna_Nazwa&gt;OSOBA PRAWNA&lt;/praw_podstawowaFormaPrawna_Nazwa&gt;&#xD;\n    &lt;praw_szczegolnaFormaPrawna_Nazwa&gt;SPÓŁKI Z OGRANICZONĄ ODPOWIEDZIALNOŚCIĄ&lt;/praw_szczegolnaFormaPrawna_Nazwa&gt;&#xD;\n    &lt;praw_formaFinansowania_Nazwa&gt;JEDNOSTKA SAMOFINANSUJĄCA NIE BĘDĄCA JEDNOSTKĄ BUDŻETOWĄ LUB SAMORZĄDOWYM ZAKŁADEM BUDŻETOWYM&lt;/praw_formaFinansowania_Nazwa&gt;&#xD;\n    &lt;praw_formaWlasnosci_Nazwa /&gt;&#xD;\n    &lt;praw_organZalozycielski_Nazwa /&gt;&#xD;\n    &lt;praw_organRejestrowy_Nazwa&gt;SĄD REJONOWY GDAŃSK-PÓŁNOC W GDAŃSKU, VII WYDZIAŁ GOSPODARCZY KRAJOWEGO REJESTRU SĄDOWEGO&lt;/praw_organRejestrowy_Nazwa&gt;&#xD;\n    &lt;praw_rodzajRejestruEwidencji_Nazwa&gt;REJESTR PRZEDSIĘBIORCÓW&lt;/praw_rodzajRejestruEwidencji_Nazwa&gt;&#xD;\n    &lt;praw_liczbaJednLokalnych&gt;0&lt;/praw_liczbaJednLokalnych&gt;&#xD;\n  &lt;/dane&gt;&#xD;\n&lt;/root&gt;</DanePobierzPelnyRaportResult></DanePobierzPelnyRaportResponse></s:Body></s:Envelope>\r\n--uuid:4544f92f-5305-4069-a4a1-22deb1e311a5+id=7991629--\r\n','{\"nazwa\":\"OHMG SPÓŁKA Z OGRANICZONĄ ODPOWIEDZIALNOŚCIĄ\",\"nip\":\"5792268412\",\"regon\":\"382001472\",\"krs\":\"\",\"wojewodztwo\":\"POMORSKIE\",\"powiat\":\"malborski\",\"gmina\":\"Malbork\",\"miejscowosc\":\"Grobelno\",\"ulica\":\"\",\"nr_nieruchomosci\":\"8\",\"nr_lokalu\":\"\",\"kod_pocztowy\":\"82-200\",\"poczta\":\"\",\"pkd_glowne\":\"\"}','gus_6994e604748034.61795742',2,962,NULL),
(48,NULL,'2026-02-18 11:58:55','prod','nip','5792268412','BIR11OsPrawna',200,1,NULL,NULL,NULL,NULL,'<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<env:Envelope xmlns:env=\"http://www.w3.org/2003/05/soap-envelope\" xmlns:ns1=\"http://CIS/BIR/PUBL/2014/07\" xmlns:ns2=\"http://www.w3.org/2005/08/addressing\"><env:Header><ns2:Action env:mustUnderstand=\"true\">http://CIS/BIR/PUBL/2014/07/IUslugaBIRzewnPubl/DanePobierzPelnyRaport</ns2:Action><ns2:To env:mustUnderstand=\"true\">https://wyszukiwarkaregon.stat.gov.pl/wsBIR/UslugaBIRzewnPubl.svc</ns2:To><ns2:MessageID env:mustUnderstand=\"true\">urn:uuid:76ad9be5-b095-4405-809e-d363b01168f7</ns2:MessageID><wsa:ReplyTo xmlns:wsa=\"http://www.w3.org/2005/08/addressing\"><wsa:Address>http://www.w3.org/2005/08/addressing/anonymous</wsa:Address></wsa:ReplyTo><ns1:sid>78f6d5rncbk7k9tt3t7f</ns1:sid></env:Header><env:Body><ns1:DanePobierzPelnyRaport><ns1:pRegon>382001472</ns1:pRegon><ns1:pNazwaRaportu>BIR11OsPrawna</ns1:pNazwaRaportu></ns1:DanePobierzPelnyRaport></env:Body></env:Envelope>\n','\r\n--uuid:181e0144-638e-4afc-b96b-a7b3e6d96a64+id=2555247\r\nContent-ID: <http://tempuri.org/0>\r\nContent-Transfer-Encoding: 8bit\r\nContent-Type: application/xop+xml;charset=utf-8;type=\"application/soap+xml\"\r\n\r\n<s:Envelope xmlns:s=\"http://www.w3.org/2003/05/soap-envelope\" xmlns:a=\"http://www.w3.org/2005/08/addressing\"><s:Header><a:Action s:mustUnderstand=\"1\">http://CIS/BIR/PUBL/2014/07/IUslugaBIRzewnPubl/DanePobierzPelnyRaportResponse</a:Action><a:RelatesTo>urn:uuid:76ad9be5-b095-4405-809e-d363b01168f7</a:RelatesTo></s:Header><s:Body><DanePobierzPelnyRaportResponse xmlns=\"http://CIS/BIR/PUBL/2014/07\"><DanePobierzPelnyRaportResult>&lt;root&gt;&#xD;\n  &lt;dane&gt;&#xD;\n    &lt;praw_regon9&gt;382001472&lt;/praw_regon9&gt;&#xD;\n    &lt;praw_nip&gt;5792268412&lt;/praw_nip&gt;&#xD;\n    &lt;praw_statusNip /&gt;&#xD;\n    &lt;praw_nazwa&gt;OHMG SPÓŁKA Z OGRANICZONĄ ODPOWIEDZIALNOŚCIĄ&lt;/praw_nazwa&gt;&#xD;\n    &lt;praw_nazwaSkrocona /&gt;&#xD;\n    &lt;praw_numerWRejestrzeEwidencji&gt;0000761846&lt;/praw_numerWRejestrzeEwidencji&gt;&#xD;\n    &lt;praw_dataWpisuDoRejestruEwidencji&gt;2018-12-10&lt;/praw_dataWpisuDoRejestruEwidencji&gt;&#xD;\n    &lt;praw_dataPowstania&gt;2018-12-10&lt;/praw_dataPowstania&gt;&#xD;\n    &lt;praw_dataRozpoczeciaDzialalnosci&gt;2018-12-10&lt;/praw_dataRozpoczeciaDzialalnosci&gt;&#xD;\n    &lt;praw_dataWpisuDoRegon&gt;2018-12-11&lt;/praw_dataWpisuDoRegon&gt;&#xD;\n    &lt;praw_dataZawieszeniaDzialalnosci /&gt;&#xD;\n    &lt;praw_dataWznowieniaDzialalnosci /&gt;&#xD;\n    &lt;praw_dataZaistnieniaZmiany&gt;2019-02-26&lt;/praw_dataZaistnieniaZmiany&gt;&#xD;\n    &lt;praw_dataZakonczeniaDzialalnosci /&gt;&#xD;\n    &lt;praw_dataSkresleniaZRegon /&gt;&#xD;\n    &lt;praw_dataOrzeczeniaOUpadlosci /&gt;&#xD;\n    &lt;praw_dataZakonczeniaPostepowaniaUpadlosciowego /&gt;&#xD;\n    &lt;praw_adSiedzKraj_Symbol&gt;PL&lt;/praw_adSiedzKraj_Symbol&gt;&#xD;\n    &lt;praw_adSiedzWojewodztwo_Symbol&gt;22&lt;/praw_adSiedzWojewodztwo_Symbol&gt;&#xD;\n    &lt;praw_adSiedzPowiat_Symbol&gt;09&lt;/praw_adSiedzPowiat_Symbol&gt;&#xD;\n    &lt;praw_adSiedzGmina_Symbol&gt;042&lt;/praw_adSiedzGmina_Symbol&gt;&#xD;\n    &lt;praw_adSiedzKodPocztowy&gt;82200&lt;/praw_adSiedzKodPocztowy&gt;&#xD;\n    &lt;praw_adSiedzMiejscowoscPoczty_Symbol&gt;0932815&lt;/praw_adSiedzMiejscowoscPoczty_Symbol&gt;&#xD;\n    &lt;praw_adSiedzMiejscowosc_Symbol&gt;0151880&lt;/praw_adSiedzMiejscowosc_Symbol&gt;&#xD;\n    &lt;praw_adSiedzUlica_Symbol /&gt;&#xD;\n    &lt;praw_adSiedzNumerNieruchomosci&gt;8&lt;/praw_adSiedzNumerNieruchomosci&gt;&#xD;\n    &lt;praw_adSiedzNumerLokalu /&gt;&#xD;\n    &lt;praw_adSiedzNietypoweMiejsceLokalizacji /&gt;&#xD;\n    &lt;praw_numerTelefonu&gt;881659971&lt;/praw_numerTelefonu&gt;&#xD;\n    &lt;praw_numerWewnetrznyTelefonu /&gt;&#xD;\n    &lt;praw_numerFaksu /&gt;&#xD;\n    &lt;praw_adresEmail&gt;OFFICE@OHMG.PL&lt;/praw_adresEmail&gt;&#xD;\n    &lt;praw_adresStronyinternetowej /&gt;&#xD;\n    &lt;praw_adSiedzKraj_Nazwa&gt;POLSKA&lt;/praw_adSiedzKraj_Nazwa&gt;&#xD;\n    &lt;praw_adSiedzWojewodztwo_Nazwa&gt;POMORSKIE&lt;/praw_adSiedzWojewodztwo_Nazwa&gt;&#xD;\n    &lt;praw_adSiedzPowiat_Nazwa&gt;malborski&lt;/praw_adSiedzPowiat_Nazwa&gt;&#xD;\n    &lt;praw_adSiedzGmina_Nazwa&gt;Malbork&lt;/praw_adSiedzGmina_Nazwa&gt;&#xD;\n    &lt;praw_adSiedzMiejscowosc_Nazwa&gt;Grobelno&lt;/praw_adSiedzMiejscowosc_Nazwa&gt;&#xD;\n    &lt;praw_adSiedzMiejscowoscPoczty_Nazwa&gt;Malbork&lt;/praw_adSiedzMiejscowoscPoczty_Nazwa&gt;&#xD;\n    &lt;praw_adSiedzUlica_Nazwa /&gt;&#xD;\n    &lt;praw_podstawowaFormaPrawna_Symbol&gt;1&lt;/praw_podstawowaFormaPrawna_Symbol&gt;&#xD;\n    &lt;praw_szczegolnaFormaPrawna_Symbol&gt;117&lt;/praw_szczegolnaFormaPrawna_Symbol&gt;&#xD;\n    &lt;praw_formaFinansowania_Symbol&gt;1&lt;/praw_formaFinansowania_Symbol&gt;&#xD;\n    &lt;praw_formaWlasnosci_Symbol /&gt;&#xD;\n    &lt;praw_organZalozycielski_Symbol /&gt;&#xD;\n    &lt;praw_organRejestrowy_Symbol&gt;071190030&lt;/praw_organRejestrowy_Symbol&gt;&#xD;\n    &lt;praw_rodzajRejestruEwidencji_Symbol&gt;138&lt;/praw_rodzajRejestruEwidencji_Symbol&gt;&#xD;\n    &lt;praw_podstawowaFormaPrawna_Nazwa&gt;OSOBA PRAWNA&lt;/praw_podstawowaFormaPrawna_Nazwa&gt;&#xD;\n    &lt;praw_szczegolnaFormaPrawna_Nazwa&gt;SPÓŁKI Z OGRANICZONĄ ODPOWIEDZIALNOŚCIĄ&lt;/praw_szczegolnaFormaPrawna_Nazwa&gt;&#xD;\n    &lt;praw_formaFinansowania_Nazwa&gt;JEDNOSTKA SAMOFINANSUJĄCA NIE BĘDĄCA JEDNOSTKĄ BUDŻETOWĄ LUB SAMORZĄDOWYM ZAKŁADEM BUDŻETOWYM&lt;/praw_formaFinansowania_Nazwa&gt;&#xD;\n    &lt;praw_formaWlasnosci_Nazwa /&gt;&#xD;\n    &lt;praw_organZalozycielski_Nazwa /&gt;&#xD;\n    &lt;praw_organRejestrowy_Nazwa&gt;SĄD REJONOWY GDAŃSK-PÓŁNOC W GDAŃSKU, VII WYDZIAŁ GOSPODARCZY KRAJOWEGO REJESTRU SĄDOWEGO&lt;/praw_organRejestrowy_Nazwa&gt;&#xD;\n    &lt;praw_rodzajRejestruEwidencji_Nazwa&gt;REJESTR PRZEDSIĘBIORCÓW&lt;/praw_rodzajRejestruEwidencji_Nazwa&gt;&#xD;\n    &lt;praw_liczbaJednLokalnych&gt;0&lt;/praw_liczbaJednLokalnych&gt;&#xD;\n  &lt;/dane&gt;&#xD;\n&lt;/root&gt;</DanePobierzPelnyRaportResult></DanePobierzPelnyRaportResponse></s:Body></s:Envelope>\r\n--uuid:181e0144-638e-4afc-b96b-a7b3e6d96a64+id=2555247--\r\n','{\"nazwa\":\"OHMG SPÓŁKA Z OGRANICZONĄ ODPOWIEDZIALNOŚCIĄ\",\"nip\":\"5792268412\",\"regon\":\"382001472\",\"krs\":\"\",\"wojewodztwo\":\"POMORSKIE\",\"powiat\":\"malborski\",\"gmina\":\"Malbork\",\"miejscowosc\":\"Grobelno\",\"ulica\":\"\",\"nr_nieruchomosci\":\"8\",\"nr_lokalu\":\"\",\"kod_pocztowy\":\"82-200\",\"poczta\":\"\",\"pkd_glowne\":\"\"}','gus_69959b6edde0f4.45843203',2,1006,NULL),
(49,NULL,'2026-02-18 12:38:11','prod','nip','5782968272','DaneSzukajPodmioty',200,1,NULL,NULL,NULL,NULL,'<?xml version=\"1.0\" encoding=\"utf-8\"?><soap:Envelope xmlns:soap=\"http://www.w3.org/2003/05/soap-envelope\" xmlns:wsa=\"http://www.w3.org/2005/08/addressing\" xmlns:pub=\"http://CIS/BIR/PUBL/2014/07\" xmlns:dat=\"http://CIS/BIR/PUBL/2014/07/DataContract\"><soap:Header><wsa:Action soap:mustUnderstand=\"true\">http://CIS/BIR/PUBL/2014/07/IUslugaBIRzewnPubl/DaneSzukajPodmioty</wsa:Action><wsa:To soap:mustUnderstand=\"true\">https://wyszukiwarkaregon.stat.gov.pl/wsBIR/UslugaBIRzewnPubl.svc</wsa:To><wsa:MessageID soap:mustUnderstand=\"true\">urn:uuid:bfc4b4bf-1957-48d2-8c0d-56f5c7410ed7</wsa:MessageID><wsa:ReplyTo><wsa:Address>http://www.w3.org/2005/08/addressing/anonymous</wsa:Address></wsa:ReplyTo><pub:sid>239wzz7598p22926ppbp</pub:sid></soap:Header><soap:Body><pub:DaneSzukajPodmioty><pub:pParametryWyszukiwania><dat:Nip>5782968272</dat:Nip></pub:pParametryWyszukiwania></pub:DaneSzukajPodmioty></soap:Body></soap:Envelope>','\r\n--uuid:181e0144-638e-4afc-b96b-a7b3e6d96a64+id=2951906\r\nContent-ID: <http://tempuri.org/0>\r\nContent-Transfer-Encoding: 8bit\r\nContent-Type: application/xop+xml;charset=utf-8;type=\"application/soap+xml\"\r\n\r\n<s:Envelope xmlns:s=\"http://www.w3.org/2003/05/soap-envelope\" xmlns:a=\"http://www.w3.org/2005/08/addressing\"><s:Header><a:Action s:mustUnderstand=\"1\">http://CIS/BIR/PUBL/2014/07/IUslugaBIRzewnPubl/DaneSzukajPodmiotyResponse</a:Action><a:RelatesTo>urn:uuid:bfc4b4bf-1957-48d2-8c0d-56f5c7410ed7</a:RelatesTo></s:Header><s:Body><DaneSzukajPodmiotyResponse xmlns=\"http://CIS/BIR/PUBL/2014/07\"><DaneSzukajPodmiotyResult>&lt;root&gt;&#xD;\n  &lt;dane&gt;&#xD;\n    &lt;Regon&gt;386593653&lt;/Regon&gt;&#xD;\n    &lt;Nip&gt;5782968272&lt;/Nip&gt;&#xD;\n    &lt;StatusNip /&gt;&#xD;\n    &lt;Nazwa&gt;Alpintel Łukasz Binkowski&lt;/Nazwa&gt;&#xD;\n    &lt;Wojewodztwo&gt;WARMIŃSKO-MAZURSKIE&lt;/Wojewodztwo&gt;&#xD;\n    &lt;Powiat&gt;Elbląg&lt;/Powiat&gt;&#xD;\n    &lt;Gmina&gt;Elbląg&lt;/Gmina&gt;&#xD;\n    &lt;Miejscowosc&gt;Elbląg&lt;/Miejscowosc&gt;&#xD;\n    &lt;KodPocztowy&gt;82-300&lt;/KodPocztowy&gt;&#xD;\n    &lt;Ulica&gt;ul. Natolińska&lt;/Ulica&gt;&#xD;\n    &lt;NrNieruchomosci&gt;31&lt;/NrNieruchomosci&gt;&#xD;\n    &lt;NrLokalu /&gt;&#xD;\n    &lt;Typ&gt;F&lt;/Typ&gt;&#xD;\n    &lt;SilosID&gt;1&lt;/SilosID&gt;&#xD;\n    &lt;DataZakonczeniaDzialalnosci /&gt;&#xD;\n    &lt;MiejscowoscPoczty /&gt;&#xD;\n  &lt;/dane&gt;&#xD;\n&lt;/root&gt;</DaneSzukajPodmiotyResult></DaneSzukajPodmiotyResponse></s:Body></s:Envelope>\r\n--uuid:181e0144-638e-4afc-b96b-a7b3e6d96a64+id=2951906--\r\n','{\"name\":\"Alpintel Łukasz Binkowski\",\"nip\":\"5782968272\",\"regon\":\"386593653\",\"krs\":\"\",\"address_street\":\"ul. Natolińska 31\",\"address_postal\":\"82-300\",\"address_city\":\"Elbląg\",\"legal_form\":\"\"}','gus_6995a4a2d10c22.82991041',1,309,NULL),
(50,NULL,'2026-02-18 13:02:55','prod','nip','5783101032','BIR11OsPrawna',200,1,NULL,NULL,NULL,NULL,'<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<env:Envelope xmlns:env=\"http://www.w3.org/2003/05/soap-envelope\" xmlns:ns1=\"http://CIS/BIR/PUBL/2014/07\" xmlns:ns2=\"http://www.w3.org/2005/08/addressing\"><env:Header><ns2:Action env:mustUnderstand=\"true\">http://CIS/BIR/PUBL/2014/07/IUslugaBIRzewnPubl/DanePobierzPelnyRaport</ns2:Action><ns2:To env:mustUnderstand=\"true\">https://wyszukiwarkaregon.stat.gov.pl/wsBIR/UslugaBIRzewnPubl.svc</ns2:To><ns2:MessageID env:mustUnderstand=\"true\">urn:uuid:6fa7fb42-c5cb-4930-825f-ee193de22c8b</ns2:MessageID><wsa:ReplyTo xmlns:wsa=\"http://www.w3.org/2005/08/addressing\"><wsa:Address>http://www.w3.org/2005/08/addressing/anonymous</wsa:Address></wsa:ReplyTo><ns1:sid>gvmh9g69653zfcxr6syd</ns1:sid></env:Header><env:Body><ns1:DanePobierzPelnyRaport><ns1:pRegon>281586976</ns1:pRegon><ns1:pNazwaRaportu>BIR11OsPrawna</ns1:pNazwaRaportu></ns1:DanePobierzPelnyRaport></env:Body></env:Envelope>\n','\r\n--uuid:8afea369-38e5-44c5-97c9-31d9f576de50+id=5433748\r\nContent-ID: <http://tempuri.org/0>\r\nContent-Transfer-Encoding: 8bit\r\nContent-Type: application/xop+xml;charset=utf-8;type=\"application/soap+xml\"\r\n\r\n<s:Envelope xmlns:s=\"http://www.w3.org/2003/05/soap-envelope\" xmlns:a=\"http://www.w3.org/2005/08/addressing\"><s:Header><a:Action s:mustUnderstand=\"1\">http://CIS/BIR/PUBL/2014/07/IUslugaBIRzewnPubl/DanePobierzPelnyRaportResponse</a:Action><a:RelatesTo>urn:uuid:6fa7fb42-c5cb-4930-825f-ee193de22c8b</a:RelatesTo></s:Header><s:Body><DanePobierzPelnyRaportResponse xmlns=\"http://CIS/BIR/PUBL/2014/07\"><DanePobierzPelnyRaportResult>&lt;root&gt;&#xD;\n  &lt;dane&gt;&#xD;\n    &lt;ErrorCode&gt;4&lt;/ErrorCode&gt;&#xD;\n    &lt;ErrorMessagePl&gt;Nie znaleziono wpisu dla podanych kryteriów wyszukiwania.&lt;/ErrorMessagePl&gt;&#xD;\n    &lt;ErrorMessageEn&gt;No data found for the specified search criteria.&lt;/ErrorMessageEn&gt;&#xD;\n    &lt;pRegon&gt;281586976&lt;/pRegon&gt;&#xD;\n    &lt;Typ_podmiotu&gt;F&lt;/Typ_podmiotu&gt;&#xD;\n    &lt;Raport&gt;BIR11OsPrawna&lt;/Raport&gt;&#xD;\n  &lt;/dane&gt;&#xD;\n&lt;/root&gt;</DanePobierzPelnyRaportResult></DanePobierzPelnyRaportResponse></s:Body></s:Envelope>\r\n--uuid:8afea369-38e5-44c5-97c9-31d9f576de50+id=5433748--\r\n','{\"nazwa\":\"Firma Handlowo-Usługowa Beata Wojtkiewicz BEATA SADOWSKA\",\"nip\":\"5783101032\",\"regon\":\"281586976\",\"krs\":\"\",\"wojewodztwo\":\"WARMIŃSKO-MAZURSKIE\",\"powiat\":\"Elbląg\",\"gmina\":\"Elbląg\",\"miejscowosc\":\"Elbląg\",\"ulica\":\"ul. Ułańska\",\"nr_nieruchomosci\":\"13\",\"nr_lokalu\":\"\",\"kod_pocztowy\":\"82-300\",\"poczta\":\"\",\"pkd_glowne\":\"\"}','gus_6995aa6eb00148.15349228',2,1125,NULL),
(51,NULL,'2026-02-18 13:23:19','prod','nip','5782068232','BIR11OsPrawna',200,1,NULL,NULL,NULL,NULL,'<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<env:Envelope xmlns:env=\"http://www.w3.org/2003/05/soap-envelope\" xmlns:ns1=\"http://CIS/BIR/PUBL/2014/07\" xmlns:ns2=\"http://www.w3.org/2005/08/addressing\"><env:Header><ns2:Action env:mustUnderstand=\"true\">http://CIS/BIR/PUBL/2014/07/IUslugaBIRzewnPubl/DanePobierzPelnyRaport</ns2:Action><ns2:To env:mustUnderstand=\"true\">https://wyszukiwarkaregon.stat.gov.pl/wsBIR/UslugaBIRzewnPubl.svc</ns2:To><ns2:MessageID env:mustUnderstand=\"true\">urn:uuid:fe3d4cfd-242d-425a-b611-f3a446e38eb5</ns2:MessageID><wsa:ReplyTo xmlns:wsa=\"http://www.w3.org/2005/08/addressing\"><wsa:Address>http://www.w3.org/2005/08/addressing/anonymous</wsa:Address></wsa:ReplyTo><ns1:sid>r253g25kf3d925m2244x</ns1:sid></env:Header><env:Body><ns1:DanePobierzPelnyRaport><ns1:pRegon>543991941</ns1:pRegon><ns1:pNazwaRaportu>BIR11OsPrawna</ns1:pNazwaRaportu></ns1:DanePobierzPelnyRaport></env:Body></env:Envelope>\n','\r\n--uuid:8afea369-38e5-44c5-97c9-31d9f576de50+id=5661959\r\nContent-ID: <http://tempuri.org/0>\r\nContent-Transfer-Encoding: 8bit\r\nContent-Type: application/xop+xml;charset=utf-8;type=\"application/soap+xml\"\r\n\r\n<s:Envelope xmlns:s=\"http://www.w3.org/2003/05/soap-envelope\" xmlns:a=\"http://www.w3.org/2005/08/addressing\"><s:Header><a:Action s:mustUnderstand=\"1\">http://CIS/BIR/PUBL/2014/07/IUslugaBIRzewnPubl/DanePobierzPelnyRaportResponse</a:Action><a:RelatesTo>urn:uuid:fe3d4cfd-242d-425a-b611-f3a446e38eb5</a:RelatesTo></s:Header><s:Body><DanePobierzPelnyRaportResponse xmlns=\"http://CIS/BIR/PUBL/2014/07\"><DanePobierzPelnyRaportResult>&lt;root&gt;&#xD;\n  &lt;dane&gt;&#xD;\n    &lt;ErrorCode&gt;4&lt;/ErrorCode&gt;&#xD;\n    &lt;ErrorMessagePl&gt;Nie znaleziono wpisu dla podanych kryteriów wyszukiwania.&lt;/ErrorMessagePl&gt;&#xD;\n    &lt;ErrorMessageEn&gt;No data found for the specified search criteria.&lt;/ErrorMessageEn&gt;&#xD;\n    &lt;pRegon&gt;543991941&lt;/pRegon&gt;&#xD;\n    &lt;Typ_podmiotu&gt;F&lt;/Typ_podmiotu&gt;&#xD;\n    &lt;Raport&gt;BIR11OsPrawna&lt;/Raport&gt;&#xD;\n  &lt;/dane&gt;&#xD;\n&lt;/root&gt;</DanePobierzPelnyRaportResult></DanePobierzPelnyRaportResponse></s:Body></s:Envelope>\r\n--uuid:8afea369-38e5-44c5-97c9-31d9f576de50+id=5661959--\r\n','{\"nazwa\":\"Damian Szuplewski\",\"nip\":\"5782068232\",\"regon\":\"543991941\",\"krs\":\"\",\"wojewodztwo\":\"WARMIŃSKO-MAZURSKIE\",\"powiat\":\"Elbląg\",\"gmina\":\"Elbląg\",\"miejscowosc\":\"Elbląg\",\"ulica\":\"ul. Bohaterów Monte Cassino\",\"nr_nieruchomosci\":\"2a\",\"nr_lokalu\":\"\",\"kod_pocztowy\":\"82-300\",\"poczta\":\"\",\"pkd_glowne\":\"\"}','gus_6995af35e1eec6.34192732',2,1213,NULL),
(52,NULL,'2026-02-19 10:32:52','prod','nip','5790004488','BIR11OsPrawna',200,1,NULL,NULL,NULL,NULL,'<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<env:Envelope xmlns:env=\"http://www.w3.org/2003/05/soap-envelope\" xmlns:ns1=\"http://CIS/BIR/PUBL/2014/07\" xmlns:ns2=\"http://www.w3.org/2005/08/addressing\"><env:Header><ns2:Action env:mustUnderstand=\"true\">http://CIS/BIR/PUBL/2014/07/IUslugaBIRzewnPubl/DanePobierzPelnyRaport</ns2:Action><ns2:To env:mustUnderstand=\"true\">https://wyszukiwarkaregon.stat.gov.pl/wsBIR/UslugaBIRzewnPubl.svc</ns2:To><ns2:MessageID env:mustUnderstand=\"true\">urn:uuid:129c3f75-898a-46c8-a04e-53c71951869b</ns2:MessageID><wsa:ReplyTo xmlns:wsa=\"http://www.w3.org/2005/08/addressing\"><wsa:Address>http://www.w3.org/2005/08/addressing/anonymous</wsa:Address></wsa:ReplyTo><ns1:sid>6f2ptvep2fd7tscuh784</ns1:sid></env:Header><env:Body><ns1:DanePobierzPelnyRaport><ns1:pRegon>810528352</ns1:pRegon><ns1:pNazwaRaportu>BIR11OsPrawna</ns1:pNazwaRaportu></ns1:DanePobierzPelnyRaport></env:Body></env:Envelope>\n','\r\n--uuid:d51f8de5-6bbe-42b3-804a-3e5e218c4c8f+id=1641590\r\nContent-ID: <http://tempuri.org/0>\r\nContent-Transfer-Encoding: 8bit\r\nContent-Type: application/xop+xml;charset=utf-8;type=\"application/soap+xml\"\r\n\r\n<s:Envelope xmlns:s=\"http://www.w3.org/2003/05/soap-envelope\" xmlns:a=\"http://www.w3.org/2005/08/addressing\"><s:Header><a:Action s:mustUnderstand=\"1\">http://CIS/BIR/PUBL/2014/07/IUslugaBIRzewnPubl/DanePobierzPelnyRaportResponse</a:Action><a:RelatesTo>urn:uuid:129c3f75-898a-46c8-a04e-53c71951869b</a:RelatesTo></s:Header><s:Body><DanePobierzPelnyRaportResponse xmlns=\"http://CIS/BIR/PUBL/2014/07\"><DanePobierzPelnyRaportResult>&lt;root&gt;&#xD;\n  &lt;dane&gt;&#xD;\n    &lt;ErrorCode&gt;4&lt;/ErrorCode&gt;&#xD;\n    &lt;ErrorMessagePl&gt;Nie znaleziono wpisu dla podanych kryteriów wyszukiwania.&lt;/ErrorMessagePl&gt;&#xD;\n    &lt;ErrorMessageEn&gt;No data found for the specified search criteria.&lt;/ErrorMessageEn&gt;&#xD;\n    &lt;pRegon&gt;810528352&lt;/pRegon&gt;&#xD;\n    &lt;Typ_podmiotu&gt;F&lt;/Typ_podmiotu&gt;&#xD;\n    &lt;Raport&gt;BIR11OsPrawna&lt;/Raport&gt;&#xD;\n  &lt;/dane&gt;&#xD;\n&lt;/root&gt;</DanePobierzPelnyRaportResult></DanePobierzPelnyRaportResponse></s:Body></s:Envelope>\r\n--uuid:d51f8de5-6bbe-42b3-804a-3e5e218c4c8f+id=1641590--\r\n','{\"nazwa\":\"Przedsiębiorstwo Handlowo Usługowe \\\"ELRO\\\" Elżbieta Pufal\",\"nip\":\"5790004488\",\"regon\":\"810528352\",\"krs\":\"\",\"wojewodztwo\":\"POMORSKIE\",\"powiat\":\"malborski\",\"gmina\":\"Malbork\",\"miejscowosc\":\"Grobelno\",\"ulica\":\"\",\"nr_nieruchomosci\":\"8\",\"nr_lokalu\":\"\",\"kod_pocztowy\":\"82-200\",\"poczta\":\"\",\"pkd_glowne\":\"\"}','gus_6996d8c33ff3f3.46262236',2,982,NULL),
(53,NULL,'2026-02-21 12:48:51','prod','nip','5790004488','BIR11OsPrawna',200,1,NULL,NULL,NULL,NULL,'<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<env:Envelope xmlns:env=\"http://www.w3.org/2003/05/soap-envelope\" xmlns:ns1=\"http://CIS/BIR/PUBL/2014/07\" xmlns:ns2=\"http://www.w3.org/2005/08/addressing\"><env:Header><ns2:Action env:mustUnderstand=\"true\">http://CIS/BIR/PUBL/2014/07/IUslugaBIRzewnPubl/DanePobierzPelnyRaport</ns2:Action><ns2:To env:mustUnderstand=\"true\">https://wyszukiwarkaregon.stat.gov.pl/wsBIR/UslugaBIRzewnPubl.svc</ns2:To><ns2:MessageID env:mustUnderstand=\"true\">urn:uuid:49822db2-757b-4298-9356-07f2cc032e66</ns2:MessageID><wsa:ReplyTo xmlns:wsa=\"http://www.w3.org/2005/08/addressing\"><wsa:Address>http://www.w3.org/2005/08/addressing/anonymous</wsa:Address></wsa:ReplyTo><ns1:sid>p9s9c35nfvm86793um4n</ns1:sid></env:Header><env:Body><ns1:DanePobierzPelnyRaport><ns1:pRegon>810528352</ns1:pRegon><ns1:pNazwaRaportu>BIR11OsPrawna</ns1:pNazwaRaportu></ns1:DanePobierzPelnyRaport></env:Body></env:Envelope>\n','\r\n--uuid:bfb7c0b1-1d13-4245-8660-cc9c6a79ee41+id=3653982\r\nContent-ID: <http://tempuri.org/0>\r\nContent-Transfer-Encoding: 8bit\r\nContent-Type: application/xop+xml;charset=utf-8;type=\"application/soap+xml\"\r\n\r\n<s:Envelope xmlns:s=\"http://www.w3.org/2003/05/soap-envelope\" xmlns:a=\"http://www.w3.org/2005/08/addressing\"><s:Header><a:Action s:mustUnderstand=\"1\">http://CIS/BIR/PUBL/2014/07/IUslugaBIRzewnPubl/DanePobierzPelnyRaportResponse</a:Action><a:RelatesTo>urn:uuid:49822db2-757b-4298-9356-07f2cc032e66</a:RelatesTo></s:Header><s:Body><DanePobierzPelnyRaportResponse xmlns=\"http://CIS/BIR/PUBL/2014/07\"><DanePobierzPelnyRaportResult>&lt;root&gt;&#xD;\n  &lt;dane&gt;&#xD;\n    &lt;ErrorCode&gt;4&lt;/ErrorCode&gt;&#xD;\n    &lt;ErrorMessagePl&gt;Nie znaleziono wpisu dla podanych kryteriów wyszukiwania.&lt;/ErrorMessagePl&gt;&#xD;\n    &lt;ErrorMessageEn&gt;No data found for the specified search criteria.&lt;/ErrorMessageEn&gt;&#xD;\n    &lt;pRegon&gt;810528352&lt;/pRegon&gt;&#xD;\n    &lt;Typ_podmiotu&gt;F&lt;/Typ_podmiotu&gt;&#xD;\n    &lt;Raport&gt;BIR11OsPrawna&lt;/Raport&gt;&#xD;\n  &lt;/dane&gt;&#xD;\n&lt;/root&gt;</DanePobierzPelnyRaportResult></DanePobierzPelnyRaportResponse></s:Body></s:Envelope>\r\n--uuid:bfb7c0b1-1d13-4245-8660-cc9c6a79ee41+id=3653982--\r\n','{\"nazwa\":\"Przedsiębiorstwo Handlowo Usługowe \\\"ELRO\\\" Elżbieta Pufal\",\"nip\":\"5790004488\",\"regon\":\"810528352\",\"krs\":\"\",\"wojewodztwo\":\"POMORSKIE\",\"powiat\":\"malborski\",\"gmina\":\"Malbork\",\"miejscowosc\":\"Grobelno\",\"ulica\":\"\",\"nr_nieruchomosci\":\"8\",\"nr_lokalu\":\"\",\"kod_pocztowy\":\"82-200\",\"poczta\":\"\",\"pkd_glowne\":\"\"}','gus_69999ba246c9f1.04460809',2,876,NULL),
(54,NULL,'2026-02-21 12:50:27','prod','nip','5790004488','BIR11OsPrawna',200,1,NULL,NULL,NULL,NULL,'<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<env:Envelope xmlns:env=\"http://www.w3.org/2003/05/soap-envelope\" xmlns:ns1=\"http://CIS/BIR/PUBL/2014/07\" xmlns:ns2=\"http://www.w3.org/2005/08/addressing\"><env:Header><ns2:Action env:mustUnderstand=\"true\">http://CIS/BIR/PUBL/2014/07/IUslugaBIRzewnPubl/DanePobierzPelnyRaport</ns2:Action><ns2:To env:mustUnderstand=\"true\">https://wyszukiwarkaregon.stat.gov.pl/wsBIR/UslugaBIRzewnPubl.svc</ns2:To><ns2:MessageID env:mustUnderstand=\"true\">urn:uuid:aa5ce394-b6e2-4473-8342-5215ef2a08f5</ns2:MessageID><wsa:ReplyTo xmlns:wsa=\"http://www.w3.org/2005/08/addressing\"><wsa:Address>http://www.w3.org/2005/08/addressing/anonymous</wsa:Address></wsa:ReplyTo><ns1:sid>49vf9ec66622fhs9652x</ns1:sid></env:Header><env:Body><ns1:DanePobierzPelnyRaport><ns1:pRegon>810528352</ns1:pRegon><ns1:pNazwaRaportu>BIR11OsPrawna</ns1:pNazwaRaportu></ns1:DanePobierzPelnyRaport></env:Body></env:Envelope>\n','\r\n--uuid:bfb7c0b1-1d13-4245-8660-cc9c6a79ee41+id=3661662\r\nContent-ID: <http://tempuri.org/0>\r\nContent-Transfer-Encoding: 8bit\r\nContent-Type: application/xop+xml;charset=utf-8;type=\"application/soap+xml\"\r\n\r\n<s:Envelope xmlns:s=\"http://www.w3.org/2003/05/soap-envelope\" xmlns:a=\"http://www.w3.org/2005/08/addressing\"><s:Header><a:Action s:mustUnderstand=\"1\">http://CIS/BIR/PUBL/2014/07/IUslugaBIRzewnPubl/DanePobierzPelnyRaportResponse</a:Action><a:RelatesTo>urn:uuid:aa5ce394-b6e2-4473-8342-5215ef2a08f5</a:RelatesTo></s:Header><s:Body><DanePobierzPelnyRaportResponse xmlns=\"http://CIS/BIR/PUBL/2014/07\"><DanePobierzPelnyRaportResult>&lt;root&gt;&#xD;\n  &lt;dane&gt;&#xD;\n    &lt;ErrorCode&gt;4&lt;/ErrorCode&gt;&#xD;\n    &lt;ErrorMessagePl&gt;Nie znaleziono wpisu dla podanych kryteriów wyszukiwania.&lt;/ErrorMessagePl&gt;&#xD;\n    &lt;ErrorMessageEn&gt;No data found for the specified search criteria.&lt;/ErrorMessageEn&gt;&#xD;\n    &lt;pRegon&gt;810528352&lt;/pRegon&gt;&#xD;\n    &lt;Typ_podmiotu&gt;F&lt;/Typ_podmiotu&gt;&#xD;\n    &lt;Raport&gt;BIR11OsPrawna&lt;/Raport&gt;&#xD;\n  &lt;/dane&gt;&#xD;\n&lt;/root&gt;</DanePobierzPelnyRaportResult></DanePobierzPelnyRaportResponse></s:Body></s:Envelope>\r\n--uuid:bfb7c0b1-1d13-4245-8660-cc9c6a79ee41+id=3661662--\r\n','{\"nazwa\":\"Przedsiębiorstwo Handlowo Usługowe \\\"ELRO\\\" Elżbieta Pufal\",\"nip\":\"5790004488\",\"regon\":\"810528352\",\"krs\":\"\",\"wojewodztwo\":\"POMORSKIE\",\"powiat\":\"malborski\",\"gmina\":\"Malbork\",\"miejscowosc\":\"Grobelno\",\"ulica\":\"\",\"nr_nieruchomosci\":\"8\",\"nr_lokalu\":\"\",\"kod_pocztowy\":\"82-200\",\"poczta\":\"\",\"pkd_glowne\":\"\"}','gus_69999c027e5606.64064970',2,951,NULL),
(55,NULL,'2026-02-21 12:50:53','prod','nip','5790004488','BIR11OsPrawna',200,1,NULL,NULL,NULL,NULL,'<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<env:Envelope xmlns:env=\"http://www.w3.org/2003/05/soap-envelope\" xmlns:ns1=\"http://CIS/BIR/PUBL/2014/07\" xmlns:ns2=\"http://www.w3.org/2005/08/addressing\"><env:Header><ns2:Action env:mustUnderstand=\"true\">http://CIS/BIR/PUBL/2014/07/IUslugaBIRzewnPubl/DanePobierzPelnyRaport</ns2:Action><ns2:To env:mustUnderstand=\"true\">https://wyszukiwarkaregon.stat.gov.pl/wsBIR/UslugaBIRzewnPubl.svc</ns2:To><ns2:MessageID env:mustUnderstand=\"true\">urn:uuid:0c3080a0-e394-4a63-be10-c7cd714cbb9e</ns2:MessageID><wsa:ReplyTo xmlns:wsa=\"http://www.w3.org/2005/08/addressing\"><wsa:Address>http://www.w3.org/2005/08/addressing/anonymous</wsa:Address></wsa:ReplyTo><ns1:sid>8zxk52fb2h2cvc973vfx</ns1:sid></env:Header><env:Body><ns1:DanePobierzPelnyRaport><ns1:pRegon>810528352</ns1:pRegon><ns1:pNazwaRaportu>BIR11OsPrawna</ns1:pNazwaRaportu></ns1:DanePobierzPelnyRaport></env:Body></env:Envelope>\n','\r\n--uuid:bfb7c0b1-1d13-4245-8660-cc9c6a79ee41+id=3664052\r\nContent-ID: <http://tempuri.org/0>\r\nContent-Transfer-Encoding: 8bit\r\nContent-Type: application/xop+xml;charset=utf-8;type=\"application/soap+xml\"\r\n\r\n<s:Envelope xmlns:s=\"http://www.w3.org/2003/05/soap-envelope\" xmlns:a=\"http://www.w3.org/2005/08/addressing\"><s:Header><a:Action s:mustUnderstand=\"1\">http://CIS/BIR/PUBL/2014/07/IUslugaBIRzewnPubl/DanePobierzPelnyRaportResponse</a:Action><a:RelatesTo>urn:uuid:0c3080a0-e394-4a63-be10-c7cd714cbb9e</a:RelatesTo></s:Header><s:Body><DanePobierzPelnyRaportResponse xmlns=\"http://CIS/BIR/PUBL/2014/07\"><DanePobierzPelnyRaportResult>&lt;root&gt;&#xD;\n  &lt;dane&gt;&#xD;\n    &lt;ErrorCode&gt;4&lt;/ErrorCode&gt;&#xD;\n    &lt;ErrorMessagePl&gt;Nie znaleziono wpisu dla podanych kryteriów wyszukiwania.&lt;/ErrorMessagePl&gt;&#xD;\n    &lt;ErrorMessageEn&gt;No data found for the specified search criteria.&lt;/ErrorMessageEn&gt;&#xD;\n    &lt;pRegon&gt;810528352&lt;/pRegon&gt;&#xD;\n    &lt;Typ_podmiotu&gt;F&lt;/Typ_podmiotu&gt;&#xD;\n    &lt;Raport&gt;BIR11OsPrawna&lt;/Raport&gt;&#xD;\n  &lt;/dane&gt;&#xD;\n&lt;/root&gt;</DanePobierzPelnyRaportResult></DanePobierzPelnyRaportResponse></s:Body></s:Envelope>\r\n--uuid:bfb7c0b1-1d13-4245-8660-cc9c6a79ee41+id=3664052--\r\n','{\"nazwa\":\"Przedsiębiorstwo Handlowo Usługowe \\\"ELRO\\\" Elżbieta Pufal\",\"nip\":\"5790004488\",\"regon\":\"810528352\",\"krs\":\"\",\"wojewodztwo\":\"POMORSKIE\",\"powiat\":\"malborski\",\"gmina\":\"Malbork\",\"miejscowosc\":\"Grobelno\",\"ulica\":\"\",\"nr_nieruchomosci\":\"8\",\"nr_lokalu\":\"\",\"kod_pocztowy\":\"82-200\",\"poczta\":\"\",\"pkd_glowne\":\"\"}','gus_69999c1cd7c9b9.70456963',2,814,NULL),
(56,NULL,'2026-02-21 12:52:21','prod','nip','5790004488','BIR11OsPrawna',200,1,NULL,NULL,NULL,NULL,'<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<env:Envelope xmlns:env=\"http://www.w3.org/2003/05/soap-envelope\" xmlns:ns1=\"http://CIS/BIR/PUBL/2014/07\" xmlns:ns2=\"http://www.w3.org/2005/08/addressing\"><env:Header><ns2:Action env:mustUnderstand=\"true\">http://CIS/BIR/PUBL/2014/07/IUslugaBIRzewnPubl/DanePobierzPelnyRaport</ns2:Action><ns2:To env:mustUnderstand=\"true\">https://wyszukiwarkaregon.stat.gov.pl/wsBIR/UslugaBIRzewnPubl.svc</ns2:To><ns2:MessageID env:mustUnderstand=\"true\">urn:uuid:8749cae3-20c0-4adb-80b2-86fa191db51f</ns2:MessageID><wsa:ReplyTo xmlns:wsa=\"http://www.w3.org/2005/08/addressing\"><wsa:Address>http://www.w3.org/2005/08/addressing/anonymous</wsa:Address></wsa:ReplyTo><ns1:sid>h4e478udhbv3nb4zw79x</ns1:sid></env:Header><env:Body><ns1:DanePobierzPelnyRaport><ns1:pRegon>810528352</ns1:pRegon><ns1:pNazwaRaportu>BIR11OsPrawna</ns1:pNazwaRaportu></ns1:DanePobierzPelnyRaport></env:Body></env:Envelope>\n','\r\n--uuid:bfb7c0b1-1d13-4245-8660-cc9c6a79ee41+id=3672675\r\nContent-ID: <http://tempuri.org/0>\r\nContent-Transfer-Encoding: 8bit\r\nContent-Type: application/xop+xml;charset=utf-8;type=\"application/soap+xml\"\r\n\r\n<s:Envelope xmlns:s=\"http://www.w3.org/2003/05/soap-envelope\" xmlns:a=\"http://www.w3.org/2005/08/addressing\"><s:Header><a:Action s:mustUnderstand=\"1\">http://CIS/BIR/PUBL/2014/07/IUslugaBIRzewnPubl/DanePobierzPelnyRaportResponse</a:Action><a:RelatesTo>urn:uuid:8749cae3-20c0-4adb-80b2-86fa191db51f</a:RelatesTo></s:Header><s:Body><DanePobierzPelnyRaportResponse xmlns=\"http://CIS/BIR/PUBL/2014/07\"><DanePobierzPelnyRaportResult>&lt;root&gt;&#xD;\n  &lt;dane&gt;&#xD;\n    &lt;ErrorCode&gt;4&lt;/ErrorCode&gt;&#xD;\n    &lt;ErrorMessagePl&gt;Nie znaleziono wpisu dla podanych kryteriów wyszukiwania.&lt;/ErrorMessagePl&gt;&#xD;\n    &lt;ErrorMessageEn&gt;No data found for the specified search criteria.&lt;/ErrorMessageEn&gt;&#xD;\n    &lt;pRegon&gt;810528352&lt;/pRegon&gt;&#xD;\n    &lt;Typ_podmiotu&gt;F&lt;/Typ_podmiotu&gt;&#xD;\n    &lt;Raport&gt;BIR11OsPrawna&lt;/Raport&gt;&#xD;\n  &lt;/dane&gt;&#xD;\n&lt;/root&gt;</DanePobierzPelnyRaportResult></DanePobierzPelnyRaportResponse></s:Body></s:Envelope>\r\n--uuid:bfb7c0b1-1d13-4245-8660-cc9c6a79ee41+id=3672675--\r\n','{\"nazwa\":\"Przedsiębiorstwo Handlowo Usługowe \\\"ELRO\\\" Elżbieta Pufal\",\"nip\":\"5790004488\",\"regon\":\"810528352\",\"krs\":\"\",\"wojewodztwo\":\"POMORSKIE\",\"powiat\":\"malborski\",\"gmina\":\"Malbork\",\"miejscowosc\":\"Grobelno\",\"ulica\":\"\",\"nr_nieruchomosci\":\"8\",\"nr_lokalu\":\"\",\"kod_pocztowy\":\"82-200\",\"poczta\":\"\",\"pkd_glowne\":\"\"}','gus_69999c743ae741.20129975',2,862,NULL),
(57,NULL,'2026-02-21 12:54:15','prod','nip','5223036948','BIR11OsPrawna',200,1,NULL,NULL,NULL,NULL,'<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<env:Envelope xmlns:env=\"http://www.w3.org/2003/05/soap-envelope\" xmlns:ns1=\"http://CIS/BIR/PUBL/2014/07\" xmlns:ns2=\"http://www.w3.org/2005/08/addressing\"><env:Header><ns2:Action env:mustUnderstand=\"true\">http://CIS/BIR/PUBL/2014/07/IUslugaBIRzewnPubl/DanePobierzPelnyRaport</ns2:Action><ns2:To env:mustUnderstand=\"true\">https://wyszukiwarkaregon.stat.gov.pl/wsBIR/UslugaBIRzewnPubl.svc</ns2:To><ns2:MessageID env:mustUnderstand=\"true\">urn:uuid:174d2c21-57f5-4aa3-b47a-4f31f4855b7f</ns2:MessageID><wsa:ReplyTo xmlns:wsa=\"http://www.w3.org/2005/08/addressing\"><wsa:Address>http://www.w3.org/2005/08/addressing/anonymous</wsa:Address></wsa:ReplyTo><ns1:sid>s2z29w4p7854tn594y3d</ns1:sid></env:Header><env:Body><ns1:DanePobierzPelnyRaport><ns1:pRegon>362361766</ns1:pRegon><ns1:pNazwaRaportu>BIR11OsPrawna</ns1:pNazwaRaportu></ns1:DanePobierzPelnyRaport></env:Body></env:Envelope>\n','\r\n--uuid:bfb7c0b1-1d13-4245-8660-cc9c6a79ee41+id=3684325\r\nContent-ID: <http://tempuri.org/0>\r\nContent-Transfer-Encoding: 8bit\r\nContent-Type: application/xop+xml;charset=utf-8;type=\"application/soap+xml\"\r\n\r\n<s:Envelope xmlns:s=\"http://www.w3.org/2003/05/soap-envelope\" xmlns:a=\"http://www.w3.org/2005/08/addressing\"><s:Header><a:Action s:mustUnderstand=\"1\">http://CIS/BIR/PUBL/2014/07/IUslugaBIRzewnPubl/DanePobierzPelnyRaportResponse</a:Action><a:RelatesTo>urn:uuid:174d2c21-57f5-4aa3-b47a-4f31f4855b7f</a:RelatesTo></s:Header><s:Body><DanePobierzPelnyRaportResponse xmlns=\"http://CIS/BIR/PUBL/2014/07\"><DanePobierzPelnyRaportResult>&lt;root&gt;&#xD;\n  &lt;dane&gt;&#xD;\n    &lt;ErrorCode&gt;4&lt;/ErrorCode&gt;&#xD;\n    &lt;ErrorMessagePl&gt;Nie znaleziono wpisu dla podanych kryteriów wyszukiwania.&lt;/ErrorMessagePl&gt;&#xD;\n    &lt;ErrorMessageEn&gt;No data found for the specified search criteria.&lt;/ErrorMessageEn&gt;&#xD;\n    &lt;pRegon&gt;362361766&lt;/pRegon&gt;&#xD;\n    &lt;Typ_podmiotu&gt;F&lt;/Typ_podmiotu&gt;&#xD;\n    &lt;Raport&gt;BIR11OsPrawna&lt;/Raport&gt;&#xD;\n  &lt;/dane&gt;&#xD;\n&lt;/root&gt;</DanePobierzPelnyRaportResult></DanePobierzPelnyRaportResponse></s:Body></s:Envelope>\r\n--uuid:bfb7c0b1-1d13-4245-8660-cc9c6a79ee41+id=3684325--\r\n','{\"nazwa\":\"Mandel - Monika Kalicińska\",\"nip\":\"5223036948\",\"regon\":\"362361766\",\"krs\":\"\",\"wojewodztwo\":\"WARMIŃSKO-MAZURSKIE\",\"powiat\":\"Elbląg\",\"gmina\":\"Elbląg\",\"miejscowosc\":\"Elbląg\",\"ulica\":\"ul. gen. Jarosława Dąbrowskiego\",\"nr_nieruchomosci\":\"2C\",\"nr_lokalu\":\"\",\"kod_pocztowy\":\"82-300\",\"poczta\":\"\",\"pkd_glowne\":\"\"}','gus_69999ce70a0f45.09443591',2,910,NULL),
(58,NULL,'2026-02-21 12:57:13','prod','nip','5223036948','BIR11OsPrawna',200,1,NULL,NULL,NULL,NULL,'<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<env:Envelope xmlns:env=\"http://www.w3.org/2003/05/soap-envelope\" xmlns:ns1=\"http://CIS/BIR/PUBL/2014/07\" xmlns:ns2=\"http://www.w3.org/2005/08/addressing\"><env:Header><ns2:Action env:mustUnderstand=\"true\">http://CIS/BIR/PUBL/2014/07/IUslugaBIRzewnPubl/DanePobierzPelnyRaport</ns2:Action><ns2:To env:mustUnderstand=\"true\">https://wyszukiwarkaregon.stat.gov.pl/wsBIR/UslugaBIRzewnPubl.svc</ns2:To><ns2:MessageID env:mustUnderstand=\"true\">urn:uuid:bc066e9c-8ef8-4d8b-b4b8-0efa4b3b9d51</ns2:MessageID><wsa:ReplyTo xmlns:wsa=\"http://www.w3.org/2005/08/addressing\"><wsa:Address>http://www.w3.org/2005/08/addressing/anonymous</wsa:Address></wsa:ReplyTo><ns1:sid>85c268cp43kdg39dc2dx</ns1:sid></env:Header><env:Body><ns1:DanePobierzPelnyRaport><ns1:pRegon>362361766</ns1:pRegon><ns1:pNazwaRaportu>BIR11OsPrawna</ns1:pNazwaRaportu></ns1:DanePobierzPelnyRaport></env:Body></env:Envelope>\n','\r\n--uuid:bfb7c0b1-1d13-4245-8660-cc9c6a79ee41+id=3702561\r\nContent-ID: <http://tempuri.org/0>\r\nContent-Transfer-Encoding: 8bit\r\nContent-Type: application/xop+xml;charset=utf-8;type=\"application/soap+xml\"\r\n\r\n<s:Envelope xmlns:s=\"http://www.w3.org/2003/05/soap-envelope\" xmlns:a=\"http://www.w3.org/2005/08/addressing\"><s:Header><a:Action s:mustUnderstand=\"1\">http://CIS/BIR/PUBL/2014/07/IUslugaBIRzewnPubl/DanePobierzPelnyRaportResponse</a:Action><a:RelatesTo>urn:uuid:bc066e9c-8ef8-4d8b-b4b8-0efa4b3b9d51</a:RelatesTo></s:Header><s:Body><DanePobierzPelnyRaportResponse xmlns=\"http://CIS/BIR/PUBL/2014/07\"><DanePobierzPelnyRaportResult>&lt;root&gt;&#xD;\n  &lt;dane&gt;&#xD;\n    &lt;ErrorCode&gt;4&lt;/ErrorCode&gt;&#xD;\n    &lt;ErrorMessagePl&gt;Nie znaleziono wpisu dla podanych kryteriów wyszukiwania.&lt;/ErrorMessagePl&gt;&#xD;\n    &lt;ErrorMessageEn&gt;No data found for the specified search criteria.&lt;/ErrorMessageEn&gt;&#xD;\n    &lt;pRegon&gt;362361766&lt;/pRegon&gt;&#xD;\n    &lt;Typ_podmiotu&gt;F&lt;/Typ_podmiotu&gt;&#xD;\n    &lt;Raport&gt;BIR11OsPrawna&lt;/Raport&gt;&#xD;\n  &lt;/dane&gt;&#xD;\n&lt;/root&gt;</DanePobierzPelnyRaportResult></DanePobierzPelnyRaportResponse></s:Body></s:Envelope>\r\n--uuid:bfb7c0b1-1d13-4245-8660-cc9c6a79ee41+id=3702561--\r\n','{\"nazwa\":\"Mandel - Monika Kalicińska\",\"nip\":\"5223036948\",\"regon\":\"362361766\",\"krs\":\"\",\"wojewodztwo\":\"WARMIŃSKO-MAZURSKIE\",\"powiat\":\"Elbląg\",\"gmina\":\"Elbląg\",\"miejscowosc\":\"Elbląg\",\"ulica\":\"ul. gen. Jarosława Dąbrowskiego\",\"nr_nieruchomosci\":\"2C\",\"nr_lokalu\":\"\",\"kod_pocztowy\":\"82-300\",\"poczta\":\"\",\"pkd_glowne\":\"\"}','gus_69999d981b2300.07770015',2,988,NULL),
(59,NULL,'2026-02-21 12:57:31','prod','nip','6783160207','BIR11OsPrawna',200,1,NULL,NULL,NULL,NULL,'<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<env:Envelope xmlns:env=\"http://www.w3.org/2003/05/soap-envelope\" xmlns:ns1=\"http://CIS/BIR/PUBL/2014/07\" xmlns:ns2=\"http://www.w3.org/2005/08/addressing\"><env:Header><ns2:Action env:mustUnderstand=\"true\">http://CIS/BIR/PUBL/2014/07/IUslugaBIRzewnPubl/DanePobierzPelnyRaport</ns2:Action><ns2:To env:mustUnderstand=\"true\">https://wyszukiwarkaregon.stat.gov.pl/wsBIR/UslugaBIRzewnPubl.svc</ns2:To><ns2:MessageID env:mustUnderstand=\"true\">urn:uuid:6a583a6e-efb6-4758-b0ef-f0d418cfb19e</ns2:MessageID><wsa:ReplyTo xmlns:wsa=\"http://www.w3.org/2005/08/addressing\"><wsa:Address>http://www.w3.org/2005/08/addressing/anonymous</wsa:Address></wsa:ReplyTo><ns1:sid>c3rf4mvg23t67fcbpdyx</ns1:sid></env:Header><env:Body><ns1:DanePobierzPelnyRaport><ns1:pRegon>364308931</ns1:pRegon><ns1:pNazwaRaportu>BIR11OsPrawna</ns1:pNazwaRaportu></ns1:DanePobierzPelnyRaport></env:Body></env:Envelope>\n','\r\n--uuid:bfb7c0b1-1d13-4245-8660-cc9c6a79ee41+id=3704468\r\nContent-ID: <http://tempuri.org/0>\r\nContent-Transfer-Encoding: 8bit\r\nContent-Type: application/xop+xml;charset=utf-8;type=\"application/soap+xml\"\r\n\r\n<s:Envelope xmlns:s=\"http://www.w3.org/2003/05/soap-envelope\" xmlns:a=\"http://www.w3.org/2005/08/addressing\"><s:Header><a:Action s:mustUnderstand=\"1\">http://CIS/BIR/PUBL/2014/07/IUslugaBIRzewnPubl/DanePobierzPelnyRaportResponse</a:Action><a:RelatesTo>urn:uuid:6a583a6e-efb6-4758-b0ef-f0d418cfb19e</a:RelatesTo></s:Header><s:Body><DanePobierzPelnyRaportResponse xmlns=\"http://CIS/BIR/PUBL/2014/07\"><DanePobierzPelnyRaportResult>&lt;root&gt;&#xD;\n  &lt;dane&gt;&#xD;\n    &lt;praw_regon9&gt;364308931&lt;/praw_regon9&gt;&#xD;\n    &lt;praw_nip&gt;6783160207&lt;/praw_nip&gt;&#xD;\n    &lt;praw_statusNip /&gt;&#xD;\n    &lt;praw_nazwa&gt;NEW LIFE PL SPÓŁKA Z OGRANICZONĄ ODPOWIEDZIALNOŚCIĄ&lt;/praw_nazwa&gt;&#xD;\n    &lt;praw_nazwaSkrocona /&gt;&#xD;\n    &lt;praw_numerWRejestrzeEwidencji&gt;0000615061&lt;/praw_numerWRejestrzeEwidencji&gt;&#xD;\n    &lt;praw_dataWpisuDoRejestruEwidencji&gt;2016-04-27&lt;/praw_dataWpisuDoRejestruEwidencji&gt;&#xD;\n    &lt;praw_dataPowstania&gt;2016-04-27&lt;/praw_dataPowstania&gt;&#xD;\n    &lt;praw_dataRozpoczeciaDzialalnosci&gt;2016-04-27&lt;/praw_dataRozpoczeciaDzialalnosci&gt;&#xD;\n    &lt;praw_dataWpisuDoRegon&gt;2016-04-27&lt;/praw_dataWpisuDoRegon&gt;&#xD;\n    &lt;praw_dataZawieszeniaDzialalnosci /&gt;&#xD;\n    &lt;praw_dataWznowieniaDzialalnosci /&gt;&#xD;\n    &lt;praw_dataZaistnieniaZmiany&gt;2026-02-05&lt;/praw_dataZaistnieniaZmiany&gt;&#xD;\n    &lt;praw_dataZakonczeniaDzialalnosci /&gt;&#xD;\n    &lt;praw_dataSkresleniaZRegon /&gt;&#xD;\n    &lt;praw_dataOrzeczeniaOUpadlosci /&gt;&#xD;\n    &lt;praw_dataZakonczeniaPostepowaniaUpadlosciowego /&gt;&#xD;\n    &lt;praw_adSiedzKraj_Symbol&gt;PL&lt;/praw_adSiedzKraj_Symbol&gt;&#xD;\n    &lt;praw_adSiedzWojewodztwo_Symbol&gt;22&lt;/praw_adSiedzWojewodztwo_Symbol&gt;&#xD;\n    &lt;praw_adSiedzPowiat_Symbol&gt;61&lt;/praw_adSiedzPowiat_Symbol&gt;&#xD;\n    &lt;praw_adSiedzGmina_Symbol&gt;011&lt;/praw_adSiedzGmina_Symbol&gt;&#xD;\n    &lt;praw_adSiedzKodPocztowy&gt;80227&lt;/praw_adSiedzKodPocztowy&gt;&#xD;\n    &lt;praw_adSiedzMiejscowoscPoczty_Symbol&gt;0933016&lt;/praw_adSiedzMiejscowoscPoczty_Symbol&gt;&#xD;\n    &lt;praw_adSiedzMiejscowosc_Symbol&gt;0933016&lt;/praw_adSiedzMiejscowosc_Symbol&gt;&#xD;\n    &lt;praw_adSiedzUlica_Symbol&gt;03906&lt;/praw_adSiedzUlica_Symbol&gt;&#xD;\n    &lt;praw_adSiedzNumerNieruchomosci&gt;34B&lt;/praw_adSiedzNumerNieruchomosci&gt;&#xD;\n    &lt;praw_adSiedzNumerLokalu&gt;15&lt;/praw_adSiedzNumerLokalu&gt;&#xD;\n    &lt;praw_adSiedzNietypoweMiejsceLokalizacji /&gt;&#xD;\n    &lt;praw_numerTelefonu&gt;533424103&lt;/praw_numerTelefonu&gt;&#xD;\n    &lt;praw_numerWewnetrznyTelefonu /&gt;&#xD;\n    &lt;praw_numerFaksu /&gt;&#xD;\n    &lt;praw_adresEmail&gt;BIURONEWLIFE@GMAIL.COM&lt;/praw_adresEmail&gt;&#xD;\n    &lt;praw_adresStronyinternetowej /&gt;&#xD;\n    &lt;praw_adSiedzKraj_Nazwa&gt;POLSKA&lt;/praw_adSiedzKraj_Nazwa&gt;&#xD;\n    &lt;praw_adSiedzWojewodztwo_Nazwa&gt;POMORSKIE&lt;/praw_adSiedzWojewodztwo_Nazwa&gt;&#xD;\n    &lt;praw_adSiedzPowiat_Nazwa&gt;Gdańsk&lt;/praw_adSiedzPowiat_Nazwa&gt;&#xD;\n    &lt;praw_adSiedzGmina_Nazwa&gt;Gdańsk&lt;/praw_adSiedzGmina_Nazwa&gt;&#xD;\n    &lt;praw_adSiedzMiejscowosc_Nazwa&gt;Gdańsk&lt;/praw_adSiedzMiejscowosc_Nazwa&gt;&#xD;\n    &lt;praw_adSiedzMiejscowoscPoczty_Nazwa&gt;Gdańsk&lt;/praw_adSiedzMiejscowoscPoczty_Nazwa&gt;&#xD;\n    &lt;praw_adSiedzUlica_Nazwa&gt;ul. Do Studzienki&lt;/praw_adSiedzUlica_Nazwa&gt;&#xD;\n    &lt;praw_podstawowaFormaPrawna_Symbol&gt;1&lt;/praw_podstawowaFormaPrawna_Symbol&gt;&#xD;\n    &lt;praw_szczegolnaFormaPrawna_Symbol&gt;117&lt;/praw_szczegolnaFormaPrawna_Symbol&gt;&#xD;\n    &lt;praw_formaFinansowania_Symbol&gt;1&lt;/praw_formaFinansowania_Symbol&gt;&#xD;\n    &lt;praw_formaWlasnosci_Symbol&gt;216&lt;/praw_formaWlasnosci_Symbol&gt;&#xD;\n    &lt;praw_organZalozycielski_Symbol /&gt;&#xD;\n    &lt;praw_organRejestrowy_Symbol&gt;071190030&lt;/praw_organRejestrowy_Symbol&gt;&#xD;\n    &lt;praw_rodzajRejestruEwidencji_Symbol&gt;138&lt;/praw_rodzajRejestruEwidencji_Symbol&gt;&#xD;\n    &lt;praw_podstawowaFormaPrawna_Nazwa&gt;OSOBA PRAWNA&lt;/praw_podstawowaFormaPrawna_Nazwa&gt;&#xD;\n    &lt;praw_szczegolnaFormaPrawna_Nazwa&gt;SPÓŁKI Z OGRANICZONĄ ODPOWIEDZIALNOŚCIĄ&lt;/praw_szczegolnaFormaPrawna_Nazwa&gt;&#xD;\n    &lt;praw_formaFinansowania_Nazwa&gt;JEDNOSTKA SAMOFINANSUJĄCA NIE BĘDĄCA JEDNOSTKĄ BUDŻETOWĄ LUB SAMORZĄDOWYM ZAKŁADEM BUDŻETOWYM&lt;/praw_formaFinansowania_Nazwa&gt;&#xD;\n    &lt;praw_formaWlasnosci_Nazwa&gt;WŁASNOŚĆ ZAGRANICZNA&lt;/praw_formaWlasnosci_Nazwa&gt;&#xD;\n    &lt;praw_organZalozycielski_Nazwa /&gt;&#xD;\n    &lt;praw_organRejestrowy_Nazwa&gt;SĄD REJONOWY GDAŃSK-PÓŁNOC W GDAŃSKU, VII WYDZIAŁ GOSPODARCZY KRAJOWEGO REJESTRU SĄDOWEGO&lt;/praw_organRejestrowy_Nazwa&gt;&#xD;\n    &lt;praw_rodzajRejestruEwidencji_Nazwa&gt;REJESTR PRZEDSIĘBIORCÓW&lt;/praw_rodzajRejestruEwidencji_Nazwa&gt;&#xD;\n    &lt;praw_liczbaJednLokalnych&gt;3&lt;/praw_liczbaJednLokalnych&gt;&#xD;\n  &lt;/dane&gt;&#xD;\n&lt;/root&gt;</DanePobierzPelnyRaportResult></DanePobierzPelnyRaportResponse></s:Body></s:Envelope>\r\n--uuid:bfb7c0b1-1d13-4245-8660-cc9c6a79ee41+id=3704468--\r\n','{\"nazwa\":\"NEW LIFE PL SPÓŁKA Z OGRANICZONĄ ODPOWIEDZIALNOŚCIĄ\",\"nip\":\"6783160207\",\"regon\":\"364308931\",\"krs\":\"\",\"wojewodztwo\":\"POMORSKIE\",\"powiat\":\"Gdańsk\",\"gmina\":\"Gdańsk\",\"miejscowosc\":\"Gdańsk\",\"ulica\":\"ul. Do Studzienki\",\"nr_nieruchomosci\":\"34B\",\"nr_lokalu\":\"15\",\"kod_pocztowy\":\"80-227\",\"poczta\":\"\",\"pkd_glowne\":\"\"}','gus_69999daa516c84.41459563',2,886,NULL),
(60,NULL,'2026-02-21 12:57:54','prod','nip','5783135350','BIR11OsPrawna',200,1,NULL,NULL,NULL,NULL,'<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<env:Envelope xmlns:env=\"http://www.w3.org/2003/05/soap-envelope\" xmlns:ns1=\"http://CIS/BIR/PUBL/2014/07\" xmlns:ns2=\"http://www.w3.org/2005/08/addressing\"><env:Header><ns2:Action env:mustUnderstand=\"true\">http://CIS/BIR/PUBL/2014/07/IUslugaBIRzewnPubl/DanePobierzPelnyRaport</ns2:Action><ns2:To env:mustUnderstand=\"true\">https://wyszukiwarkaregon.stat.gov.pl/wsBIR/UslugaBIRzewnPubl.svc</ns2:To><ns2:MessageID env:mustUnderstand=\"true\">urn:uuid:82bb7cc2-ac13-44a9-a53c-dadf2522ca22</ns2:MessageID><wsa:ReplyTo xmlns:wsa=\"http://www.w3.org/2005/08/addressing\"><wsa:Address>http://www.w3.org/2005/08/addressing/anonymous</wsa:Address></wsa:ReplyTo><ns1:sid>h6cffhc5e5tg7zyu3gvn</ns1:sid></env:Header><env:Body><ns1:DanePobierzPelnyRaport><ns1:pRegon>382473917</ns1:pRegon><ns1:pNazwaRaportu>BIR11OsPrawna</ns1:pNazwaRaportu></ns1:DanePobierzPelnyRaport></env:Body></env:Envelope>\n','\r\n--uuid:bfb7c0b1-1d13-4245-8660-cc9c6a79ee41+id=3706961\r\nContent-ID: <http://tempuri.org/0>\r\nContent-Transfer-Encoding: 8bit\r\nContent-Type: application/xop+xml;charset=utf-8;type=\"application/soap+xml\"\r\n\r\n<s:Envelope xmlns:s=\"http://www.w3.org/2003/05/soap-envelope\" xmlns:a=\"http://www.w3.org/2005/08/addressing\"><s:Header><a:Action s:mustUnderstand=\"1\">http://CIS/BIR/PUBL/2014/07/IUslugaBIRzewnPubl/DanePobierzPelnyRaportResponse</a:Action><a:RelatesTo>urn:uuid:82bb7cc2-ac13-44a9-a53c-dadf2522ca22</a:RelatesTo></s:Header><s:Body><DanePobierzPelnyRaportResponse xmlns=\"http://CIS/BIR/PUBL/2014/07\"><DanePobierzPelnyRaportResult>&lt;root&gt;&#xD;\n  &lt;dane&gt;&#xD;\n    &lt;praw_regon9&gt;382473917&lt;/praw_regon9&gt;&#xD;\n    &lt;praw_nip&gt;5783135350&lt;/praw_nip&gt;&#xD;\n    &lt;praw_statusNip /&gt;&#xD;\n    &lt;praw_nazwa&gt;LAYMAN SPÓŁKA Z OGRANICZONĄ ODPOWIEDZIALNOŚCIĄ SPÓŁKA KOMANDYTOWA&lt;/praw_nazwa&gt;&#xD;\n    &lt;praw_nazwaSkrocona /&gt;&#xD;\n    &lt;praw_numerWRejestrzeEwidencji&gt;0000823702&lt;/praw_numerWRejestrzeEwidencji&gt;&#xD;\n    &lt;praw_dataWpisuDoRejestruEwidencji&gt;2020-02-03&lt;/praw_dataWpisuDoRejestruEwidencji&gt;&#xD;\n    &lt;praw_dataPowstania&gt;2019-02-01&lt;/praw_dataPowstania&gt;&#xD;\n    &lt;praw_dataRozpoczeciaDzialalnosci&gt;2019-02-01&lt;/praw_dataRozpoczeciaDzialalnosci&gt;&#xD;\n    &lt;praw_dataWpisuDoRegon&gt;2019-02-04&lt;/praw_dataWpisuDoRegon&gt;&#xD;\n    &lt;praw_dataZawieszeniaDzialalnosci /&gt;&#xD;\n    &lt;praw_dataWznowieniaDzialalnosci /&gt;&#xD;\n    &lt;praw_dataZaistnieniaZmiany&gt;2021-07-01&lt;/praw_dataZaistnieniaZmiany&gt;&#xD;\n    &lt;praw_dataZakonczeniaDzialalnosci /&gt;&#xD;\n    &lt;praw_dataSkresleniaZRegon /&gt;&#xD;\n    &lt;praw_dataOrzeczeniaOUpadlosci /&gt;&#xD;\n    &lt;praw_dataZakonczeniaPostepowaniaUpadlosciowego /&gt;&#xD;\n    &lt;praw_adSiedzKraj_Symbol&gt;PL&lt;/praw_adSiedzKraj_Symbol&gt;&#xD;\n    &lt;praw_adSiedzWojewodztwo_Symbol&gt;28&lt;/praw_adSiedzWojewodztwo_Symbol&gt;&#xD;\n    &lt;praw_adSiedzPowiat_Symbol&gt;61&lt;/praw_adSiedzPowiat_Symbol&gt;&#xD;\n    &lt;praw_adSiedzGmina_Symbol&gt;011&lt;/praw_adSiedzGmina_Symbol&gt;&#xD;\n    &lt;praw_adSiedzKodPocztowy&gt;82300&lt;/praw_adSiedzKodPocztowy&gt;&#xD;\n    &lt;praw_adSiedzMiejscowoscPoczty_Symbol&gt;0932703&lt;/praw_adSiedzMiejscowoscPoczty_Symbol&gt;&#xD;\n    &lt;praw_adSiedzMiejscowosc_Symbol&gt;0932703&lt;/praw_adSiedzMiejscowosc_Symbol&gt;&#xD;\n    &lt;praw_adSiedzUlica_Symbol&gt;20258&lt;/praw_adSiedzUlica_Symbol&gt;&#xD;\n    &lt;praw_adSiedzNumerNieruchomosci&gt;10&lt;/praw_adSiedzNumerNieruchomosci&gt;&#xD;\n    &lt;praw_adSiedzNumerLokalu /&gt;&#xD;\n    &lt;praw_adSiedzNietypoweMiejsceLokalizacji /&gt;&#xD;\n    &lt;praw_numerTelefonu /&gt;&#xD;\n    &lt;praw_numerWewnetrznyTelefonu /&gt;&#xD;\n    &lt;praw_numerFaksu /&gt;&#xD;\n    &lt;praw_adresEmail /&gt;&#xD;\n    &lt;praw_adresStronyinternetowej /&gt;&#xD;\n    &lt;praw_adSiedzKraj_Nazwa&gt;POLSKA&lt;/praw_adSiedzKraj_Nazwa&gt;&#xD;\n    &lt;praw_adSiedzWojewodztwo_Nazwa&gt;WARMIŃSKO-MAZURSKIE&lt;/praw_adSiedzWojewodztwo_Nazwa&gt;&#xD;\n    &lt;praw_adSiedzPowiat_Nazwa&gt;Elbląg&lt;/praw_adSiedzPowiat_Nazwa&gt;&#xD;\n    &lt;praw_adSiedzGmina_Nazwa&gt;Elbląg&lt;/praw_adSiedzGmina_Nazwa&gt;&#xD;\n    &lt;praw_adSiedzMiejscowosc_Nazwa&gt;Elbląg&lt;/praw_adSiedzMiejscowosc_Nazwa&gt;&#xD;\n    &lt;praw_adSiedzMiejscowoscPoczty_Nazwa&gt;Elbląg&lt;/praw_adSiedzMiejscowoscPoczty_Nazwa&gt;&#xD;\n    &lt;praw_adSiedzUlica_Nazwa&gt;ul. Słonecznikowa&lt;/praw_adSiedzUlica_Nazwa&gt;&#xD;\n    &lt;praw_podstawowaFormaPrawna_Symbol&gt;2&lt;/praw_podstawowaFormaPrawna_Symbol&gt;&#xD;\n    &lt;praw_szczegolnaFormaPrawna_Symbol&gt;120&lt;/praw_szczegolnaFormaPrawna_Symbol&gt;&#xD;\n    &lt;praw_formaFinansowania_Symbol&gt;1&lt;/praw_formaFinansowania_Symbol&gt;&#xD;\n    &lt;praw_formaWlasnosci_Symbol&gt;214&lt;/praw_formaWlasnosci_Symbol&gt;&#xD;\n    &lt;praw_organZalozycielski_Symbol /&gt;&#xD;\n    &lt;praw_organRejestrowy_Symbol&gt;071510010&lt;/praw_organRejestrowy_Symbol&gt;&#xD;\n    &lt;praw_rodzajRejestruEwidencji_Symbol&gt;138&lt;/praw_rodzajRejestruEwidencji_Symbol&gt;&#xD;\n    &lt;praw_podstawowaFormaPrawna_Nazwa&gt;JEDNOSTKA ORGANIZACYJNA NIEMAJĄCA OSOBOWOŚCI PRAWNEJ&lt;/praw_podstawowaFormaPrawna_Nazwa&gt;&#xD;\n    &lt;praw_szczegolnaFormaPrawna_Nazwa&gt;SPÓŁKI KOMANDYTOWE&lt;/praw_szczegolnaFormaPrawna_Nazwa&gt;&#xD;\n    &lt;praw_formaFinansowania_Nazwa&gt;JEDNOSTKA SAMOFINANSUJĄCA NIE BĘDĄCA JEDNOSTKĄ BUDŻETOWĄ LUB SAMORZĄDOWYM ZAKŁADEM BUDŻETOWYM&lt;/praw_formaFinansowania_Nazwa&gt;&#xD;\n    &lt;praw_formaWlasnosci_Nazwa&gt;WŁASNOŚĆ KRAJOWYCH OSÓB FIZYCZNYCH&lt;/praw_formaWlasnosci_Nazwa&gt;&#xD;\n    &lt;praw_organZalozycielski_Nazwa /&gt;&#xD;\n    &lt;praw_organRejestrowy_Nazwa&gt;SĄD REJONOWY W OLSZTYNIE, VIII WYDZIAŁ GOSPODARCZY KRAJOWEGO REJESTRU SĄDOWEGO&lt;/praw_organRejestrowy_Nazwa&gt;&#xD;\n    &lt;praw_rodzajRejestruEwidencji_Nazwa&gt;REJESTR PRZEDSIĘBIORCÓW&lt;/praw_rodzajRejestruEwidencji_Nazwa&gt;&#xD;\n    &lt;praw_liczbaJednLokalnych&gt;0&lt;/praw_liczbaJednLokalnych&gt;&#xD;\n  &lt;/dane&gt;&#xD;\n&lt;/root&gt;</DanePobierzPelnyRaportResult></DanePobierzPelnyRaportResponse></s:Body></s:Envelope>\r\n--uuid:bfb7c0b1-1d13-4245-8660-cc9c6a79ee41+id=3706961--\r\n','{\"nazwa\":\"LAYMAN SPÓŁKA Z OGRANICZONĄ ODPOWIEDZIALNOŚCIĄ SPÓŁKA KOMANDYTOWA\",\"nip\":\"5783135350\",\"regon\":\"382473917\",\"krs\":\"\",\"wojewodztwo\":\"WARMIŃSKO-MAZURSKIE\",\"powiat\":\"Elbląg\",\"gmina\":\"Elbląg\",\"miejscowosc\":\"Elbląg\",\"ulica\":\"ul. Słonecznikowa\",\"nr_nieruchomosci\":\"10\",\"nr_lokalu\":\"\",\"kod_pocztowy\":\"82-300\",\"poczta\":\"\",\"pkd_glowne\":\"\"}','gus_69999dc1a32995.46495571',2,950,NULL);
/*!40000 ALTER TABLE `gus_snapshots` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `historia_maili_ofert`
--

DROP TABLE IF EXISTS `historia_maili_ofert`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `historia_maili_ofert` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `kampania_id` int(11) NOT NULL,
  `klient_id` int(11) DEFAULT NULL,
  `user_id` int(11) NOT NULL,
  `to_email` varchar(255) NOT NULL,
  `subject` varchar(255) NOT NULL,
  `status` varchar(20) NOT NULL,
  `error_msg` text DEFAULT NULL,
  `sent_at` datetime NOT NULL DEFAULT current_timestamp(),
  `lead_id` int(11) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `kampania_id` (`kampania_id`),
  KEY `user_id` (`user_id`)
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `historia_maili_ofert`
--

LOCK TABLES `historia_maili_ofert` WRITE;
/*!40000 ALTER TABLE `historia_maili_ofert` DISABLE KEYS */;
INSERT INTO `historia_maili_ofert` VALUES
(1,89,14,2,'naczelny@radiozulawy.pl','Oferta / Mediaplan - Alpintel Łukasz Binkowski  - kampania #89','error','Nie udalo sie wgrac zalacznika: ','2026-02-18 12:39:30',NULL);
/*!40000 ALTER TABLE `historia_maili_ofert` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `integration_alerts`
--

DROP TABLE IF EXISTS `integration_alerts`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `integration_alerts` (
  `alert_key` varchar(80) NOT NULL,
  `last_logged_at` datetime NOT NULL,
  `last_payload` text DEFAULT NULL,
  PRIMARY KEY (`alert_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `integration_alerts`
--

LOCK TABLES `integration_alerts` WRITE;
/*!40000 ALTER TABLE `integration_alerts` DISABLE KEYS */;
/*!40000 ALTER TABLE `integration_alerts` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `integration_circuit_breaker`
--

DROP TABLE IF EXISTS `integration_circuit_breaker`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `integration_circuit_breaker` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `integration` varchar(30) NOT NULL,
  `state` varchar(20) NOT NULL,
  `reason` varchar(50) DEFAULT NULL,
  `details` text DEFAULT NULL,
  `degraded_until` datetime DEFAULT NULL,
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `integration` (`integration`)
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `integration_circuit_breaker`
--

LOCK TABLES `integration_circuit_breaker` WRITE;
/*!40000 ALTER TABLE `integration_circuit_breaker` DISABLE KEYS */;
INSERT INTO `integration_circuit_breaker` VALUES
(1,'gus','ok',NULL,NULL,NULL,'2026-02-17 22:45:53');
/*!40000 ALTER TABLE `integration_circuit_breaker` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `integrations_logs`
--

DROP TABLE IF EXISTS `integrations_logs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `integrations_logs` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `log_time` datetime NOT NULL DEFAULT current_timestamp(),
  `user_id` int(11) DEFAULT NULL,
  `type` varchar(30) NOT NULL,
  `request_id` varchar(100) DEFAULT NULL,
  `message` text NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_integrations_logs_type` (`type`),
  KEY `idx_integrations_logs_user` (`user_id`)
) ENGINE=InnoDB AUTO_INCREMENT=6 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `integrations_logs`
--

LOCK TABLES `integrations_logs` WRITE;
/*!40000 ALTER TABLE `integrations_logs` DISABLE KEYS */;
INSERT INTO `integrations_logs` VALUES
(1,'2026-02-16 15:44:03',1,'gus','gus_69932d315a9287.67064129','Serwer GUS zwrócił błąd HTTP 500.'),
(2,'2026-02-16 15:52:01',1,'gus','gus_69932f11050146.02732266','Brak SID w odpowiedzi GUS.'),
(3,'2026-02-16 15:52:25',1,'gus','gus_69932f291073a9.26216085','Brak SID w odpowiedzi GUS.'),
(4,'2026-02-19 11:27:22',2,'report','raport_emisji','Raport emisji CSV 2026-02-19 - 2026-02-19'),
(5,'2026-02-21 17:32:40',2,'report','raport_emisji','Raport emisji CSV 2026-02-21 - 2026-03-31');
/*!40000 ALTER TABLE `integrations_logs` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `kampanie`
--

DROP TABLE IF EXISTS `kampanie`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `kampanie` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `klient_id` int(11) DEFAULT NULL,
  `klient_nazwa` varchar(255) DEFAULT NULL,
  `dlugosc_spotu` int(11) DEFAULT NULL,
  `data_start` date DEFAULT NULL,
  `data_koniec` date DEFAULT NULL,
  `rabat` decimal(5,2) DEFAULT 0.00,
  `netto_spoty` decimal(10,2) DEFAULT NULL,
  `netto_dodatki` decimal(10,2) DEFAULT NULL,
  `razem_netto` decimal(10,2) DEFAULT NULL,
  `razem_brutto` decimal(10,2) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `propozycja` tinyint(1) DEFAULT 0,
  `audio_file` varchar(255) DEFAULT NULL,
  `owner_user_id` int(11) DEFAULT NULL,
  `status` varchar(50) NOT NULL DEFAULT 'W realizacji',
  `wartosc_netto` decimal(12,2) NOT NULL DEFAULT 0.00,
  `source_lead_id` int(11) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_kampanie_owner_user_id` (`owner_user_id`),
  KEY `idx_kampanie_created_at` (`created_at`),
  KEY `idx_kampanie_status` (`status`),
  KEY `idx_kampanie_data_start` (`data_start`),
  KEY `idx_kampanie_source_lead_id` (`source_lead_id`)
) ENGINE=InnoDB AUTO_INCREMENT=169 DEFAULT CHARSET=latin2 COLLATE=latin2_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `kampanie`
--

LOCK TABLES `kampanie` WRITE;
/*!40000 ALTER TABLE `kampanie` DISABLE KEYS */;
INSERT INTO `kampanie` VALUES
(17,NULL,'UM Elbląg',20,'2026-01-01','2026-02-18',0.00,2268.00,0.00,2268.00,2789.64,'2025-12-13 21:49:04',0,NULL,NULL,'Zakończona',0.00,NULL),
(18,NULL,'OMODA',30,'2026-01-01','2026-02-26',0.00,2907.00,0.00,2907.00,3575.61,'2025-12-13 21:51:41',0,NULL,NULL,'Zakończona',0.00,NULL),
(19,NULL,'Salon Daewoo',20,'2025-12-19','2026-02-26',0.00,2920.00,0.00,2920.00,3591.60,'2025-12-13 22:02:10',0,NULL,NULL,'Zakończona',0.00,NULL),
(20,NULL,'Test',15,'2025-12-15','2026-03-17',0.00,2757.00,0.00,2757.00,3391.11,'2025-12-13 22:04:59',0,NULL,NULL,'Zakończona',0.00,NULL),
(21,NULL,'OHMG',20,'2025-12-15','2026-03-19',0.00,3608.00,0.00,3608.00,4437.84,'2025-12-13 22:18:57',0,NULL,NULL,'Zakończona',0.00,NULL),
(22,NULL,'Test',20,'2025-12-16','2026-02-18',0.00,564.00,0.00,564.00,693.72,'2025-12-13 22:28:31',0,NULL,NULL,'Zakończona',0.00,NULL),
(24,NULL,'Salon Daewoo',20,'2025-12-22','2026-01-30',0.00,780.00,0.00,780.00,959.40,'2025-12-13 23:23:38',0,NULL,NULL,'Zakończona',0.00,NULL),
(25,NULL,'tyerhjrh',20,'2025-12-17','2025-12-31',0.00,252.00,0.00,252.00,309.96,'2025-12-16 18:28:10',0,NULL,NULL,'Zakończona',0.00,NULL),
(26,NULL,'Omoda & Jaecoo',30,'2025-12-18','2026-01-06',0.00,4600.00,0.00,4600.00,5658.00,'2025-12-17 15:39:58',0,NULL,NULL,'Zakończona',0.00,NULL),
(27,NULL,'OMODA',30,'2025-12-18','2026-01-06',0.00,3400.00,0.00,3400.00,4182.00,'2025-12-19 20:45:31',0,NULL,NULL,'Zakończona',0.00,NULL),
(28,NULL,'ELRO 2',20,'2026-01-01','2026-01-31',6.00,1232.00,0.00,1158.08,1424.44,'2025-12-28 16:15:34',0,NULL,NULL,'Zakończona',0.00,NULL),
(88,NULL,'5N Justyna Zienkiewicz',20,'2026-02-18','2026-03-18',0.00,1260.00,1500.00,2760.00,3394.80,'2026-02-17 19:51:50',0,NULL,2,'Zakończona',0.00,5),
(89,14,'Alpintel Łukasz Binkowski ',20,'2026-03-01','2026-03-31',0.00,1232.00,0.00,1232.00,1515.36,'2026-02-17 19:54:28',0,NULL,2,'Zakończona',0.00,7),
(90,14,'Alpintel Łukasz Binkowski',20,'2026-03-01','2026-03-31',0.00,1760.00,0.00,1760.00,2164.80,'2026-02-17 19:55:13',0,NULL,2,'Zakończona',0.00,7),
(95,NULL,'5N Justyna Zienkiewicz',20,'2026-03-01','2026-03-31',0.00,880.00,0.00,880.00,1082.40,'2026-02-17 20:18:41',1,NULL,2,'Propozycja',0.00,5),
(116,15,'Firma Handlowo-Usługowa Beata Wojtkiewicz BEATA SADOWSKA',20,'2026-03-01','2026-03-31',0.00,1240.00,1200.00,2440.00,3001.20,'2026-02-18 12:04:08',0,NULL,2,'Zamówiona',2440.00,9),
(119,16,'Damian Szuplewski',20,'2026-03-01','2026-03-31',0.00,1232.00,1100.00,2332.00,2868.36,'2026-02-18 12:24:47',0,NULL,2,'Zamówiona',2332.00,10),
(142,19,'LAYMAN SPÓŁKA Z OGRANICZONĄ ODPOWIEDZIALNOŚCIĄ SPÓŁKA KOMANDYTOWA',20,'2026-02-22','2026-03-31',0.00,2088.00,0.00,2088.00,2568.24,'2026-02-21 14:27:40',0,'storage/uploads/audio/142_1772057940_VT1537.mp3',2,'W realizacji',2088.00,NULL);
/*!40000 ALTER TABLE `kampanie` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `kampanie_emisje`
--

DROP TABLE IF EXISTS `kampanie_emisje`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `kampanie_emisje` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `kampania_id` int(11) NOT NULL,
  `dzien_tygodnia` enum('mon','tue','wed','thu','fri','sat','sun') DEFAULT NULL,
  `godzina` time DEFAULT NULL,
  `ilosc` int(11) DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `kampania_id` (`kampania_id`),
  CONSTRAINT `kampanie_emisje_ibfk_1` FOREIGN KEY (`kampania_id`) REFERENCES `kampanie` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=826 DEFAULT CHARSET=latin2 COLLATE=latin2_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `kampanie_emisje`
--

LOCK TABLES `kampanie_emisje` WRITE;
/*!40000 ALTER TABLE `kampanie_emisje` DISABLE KEYS */;
INSERT INTO `kampanie_emisje` VALUES
(162,17,'mon','09:00:00',1),
(163,17,'mon','13:00:00',1),
(164,17,'mon','16:00:00',1),
(165,17,'mon','17:00:00',1),
(166,17,'tue','09:00:00',1),
(167,17,'tue','13:00:00',1),
(168,17,'tue','16:00:00',1),
(169,17,'wed','09:00:00',1),
(170,17,'wed','13:00:00',1),
(171,17,'wed','16:00:00',1),
(172,17,'thu','09:00:00',1),
(173,17,'thu','13:00:00',1),
(174,17,'thu','16:00:00',1),
(175,17,'fri','09:00:00',1),
(176,17,'fri','13:00:00',1),
(177,17,'fri','16:00:00',1),
(178,17,'sat','09:00:00',1),
(179,17,'sat','13:00:00',1),
(180,17,'sat','16:00:00',1),
(181,17,'sun','09:00:00',1),
(182,17,'sun','13:00:00',1),
(183,17,'sun','16:00:00',1),
(184,18,'mon','09:00:00',1),
(185,18,'mon','14:00:00',1),
(186,18,'mon','17:00:00',1),
(187,18,'tue','09:00:00',1),
(188,18,'tue','14:00:00',1),
(189,18,'tue','17:00:00',1),
(190,18,'wed','09:00:00',1),
(191,18,'wed','14:00:00',1),
(192,18,'wed','17:00:00',1),
(193,18,'thu','09:00:00',1),
(194,18,'thu','14:00:00',1),
(195,18,'thu','17:00:00',1),
(196,18,'fri','09:00:00',1),
(197,18,'fri','14:00:00',1),
(198,18,'fri','17:00:00',1),
(199,18,'sat','09:00:00',1),
(200,18,'sat','14:00:00',1),
(201,18,'sat','17:00:00',1),
(202,18,'sun','09:00:00',1),
(203,18,'sun','14:00:00',1),
(204,18,'sun','17:00:00',1),
(205,19,'mon','08:00:00',1),
(206,19,'mon','13:00:00',1),
(207,19,'mon','17:00:00',1),
(208,19,'tue','08:00:00',1),
(209,19,'tue','13:00:00',1),
(210,19,'tue','17:00:00',1),
(211,19,'wed','08:00:00',1),
(212,19,'wed','13:00:00',1),
(213,19,'wed','17:00:00',1),
(214,19,'thu','08:00:00',1),
(215,19,'thu','13:00:00',1),
(216,19,'thu','17:00:00',1),
(217,19,'fri','08:00:00',1),
(218,19,'fri','13:00:00',1),
(219,19,'fri','17:00:00',1),
(220,19,'sat','08:00:00',1),
(221,19,'sat','13:00:00',1),
(222,19,'sat','17:00:00',1),
(223,19,'sun','13:00:00',1),
(224,19,'sun','17:00:00',1),
(225,20,'mon','09:00:00',1),
(226,20,'mon','15:00:00',1),
(227,20,'mon','20:00:00',1),
(228,20,'tue','09:00:00',1),
(229,20,'tue','15:00:00',1),
(230,20,'tue','20:00:00',1),
(231,20,'wed','09:00:00',1),
(232,20,'wed','15:00:00',1),
(233,20,'wed','20:00:00',1),
(234,20,'thu','09:00:00',1),
(235,20,'thu','15:00:00',1),
(236,20,'thu','20:00:00',1),
(237,20,'fri','09:00:00',1),
(238,20,'fri','15:00:00',1),
(239,20,'fri','20:00:00',1),
(240,20,'sat','09:00:00',1),
(241,20,'sat','15:00:00',1),
(242,20,'sat','20:00:00',1),
(243,20,'sun','20:00:00',1),
(244,21,'mon','09:00:00',1),
(245,21,'mon','13:00:00',1),
(246,21,'mon','16:00:00',1),
(247,21,'tue','09:00:00',1),
(248,21,'tue','13:00:00',1),
(249,21,'tue','16:00:00',1),
(250,21,'wed','09:00:00',1),
(251,21,'wed','13:00:00',1),
(252,21,'wed','16:00:00',1),
(253,21,'thu','09:00:00',1),
(254,21,'thu','13:00:00',1),
(255,21,'thu','16:00:00',1),
(256,21,'fri','09:00:00',1),
(257,21,'fri','13:00:00',1),
(258,21,'fri','16:00:00',1),
(259,21,'sat','09:00:00',1),
(260,21,'sat','13:00:00',1),
(261,21,'sat','16:00:00',1),
(262,22,'mon','10:00:00',1),
(263,22,'tue','10:00:00',1),
(264,22,'wed','10:00:00',1),
(265,22,'thu','10:00:00',1),
(266,22,'fri','10:00:00',1),
(277,24,'mon','11:00:00',1),
(278,24,'mon','13:00:00',1),
(279,24,'tue','11:00:00',1),
(280,24,'tue','13:00:00',1),
(281,24,'wed','11:00:00',1),
(282,24,'wed','13:00:00',1),
(283,24,'thu','11:00:00',1),
(284,24,'thu','13:00:00',1),
(285,24,'fri','11:00:00',1),
(286,24,'fri','13:00:00',1),
(287,24,'sat','13:00:00',1),
(288,25,'mon','10:00:00',1),
(289,25,'mon','12:00:00',1),
(290,25,'mon','14:00:00',1),
(291,25,'tue','10:00:00',1),
(292,25,'tue','12:00:00',1),
(293,25,'wed','10:00:00',1),
(294,25,'wed','12:00:00',1),
(295,25,'wed','14:00:00',1),
(296,25,'thu','12:00:00',1),
(297,26,'mon','07:00:00',1),
(298,26,'mon','08:00:00',1),
(299,26,'mon','09:00:00',1),
(300,26,'mon','10:00:00',1),
(301,26,'mon','11:00:00',1),
(302,26,'mon','12:00:00',1),
(303,26,'mon','13:00:00',1),
(304,26,'mon','14:00:00',1),
(305,26,'mon','15:00:00',1),
(306,26,'mon','16:00:00',1),
(307,26,'mon','17:00:00',1),
(308,26,'mon','18:00:00',1),
(309,26,'tue','07:00:00',1),
(310,26,'tue','08:00:00',1),
(311,26,'tue','09:00:00',1),
(312,26,'tue','10:00:00',1),
(313,26,'tue','11:00:00',1),
(314,26,'tue','12:00:00',1),
(315,26,'tue','13:00:00',1),
(316,26,'tue','14:00:00',1),
(317,26,'tue','15:00:00',1),
(318,26,'tue','16:00:00',1),
(319,26,'tue','17:00:00',1),
(320,26,'tue','18:00:00',1),
(321,26,'wed','07:00:00',1),
(322,26,'wed','08:00:00',1),
(323,26,'wed','09:00:00',1),
(324,26,'wed','10:00:00',1),
(325,26,'wed','11:00:00',1),
(326,26,'wed','12:00:00',1),
(327,26,'wed','13:00:00',1),
(328,26,'wed','14:00:00',1),
(329,26,'wed','15:00:00',1),
(330,26,'wed','16:00:00',1),
(331,26,'wed','17:00:00',1),
(332,26,'wed','18:00:00',1),
(333,26,'thu','07:00:00',1),
(334,26,'thu','08:00:00',1),
(335,26,'thu','09:00:00',1),
(336,26,'thu','10:00:00',1),
(337,26,'thu','11:00:00',1),
(338,26,'thu','12:00:00',1),
(339,26,'thu','13:00:00',1),
(340,26,'thu','14:00:00',1),
(341,26,'thu','15:00:00',1),
(342,26,'thu','16:00:00',1),
(343,26,'thu','17:00:00',1),
(344,26,'thu','18:00:00',1),
(345,26,'fri','07:00:00',1),
(346,26,'fri','08:00:00',1),
(347,26,'fri','09:00:00',1),
(348,26,'fri','10:00:00',1),
(349,26,'fri','11:00:00',1),
(350,26,'fri','12:00:00',1),
(351,26,'fri','13:00:00',1),
(352,26,'fri','14:00:00',1),
(353,26,'fri','15:00:00',1),
(354,26,'fri','16:00:00',1),
(355,26,'fri','17:00:00',1),
(356,26,'fri','18:00:00',1),
(357,26,'sat','07:00:00',1),
(358,26,'sat','08:00:00',1),
(359,26,'sat','09:00:00',1),
(360,26,'sat','10:00:00',1),
(361,26,'sat','11:00:00',1),
(362,26,'sat','12:00:00',1),
(363,26,'sat','13:00:00',1),
(364,26,'sat','14:00:00',1),
(365,26,'sat','15:00:00',1),
(366,26,'sat','16:00:00',1),
(367,26,'sat','17:00:00',1),
(368,26,'sat','18:00:00',1),
(369,26,'sun','07:00:00',1),
(370,26,'sun','08:00:00',1),
(371,26,'sun','09:00:00',1),
(372,26,'sun','10:00:00',1),
(373,26,'sun','11:00:00',1),
(374,26,'sun','12:00:00',1),
(375,26,'sun','13:00:00',1),
(376,26,'sun','14:00:00',1),
(377,26,'sun','15:00:00',1),
(378,26,'sun','16:00:00',1),
(379,26,'sun','17:00:00',1),
(380,26,'sun','18:00:00',1),
(381,27,'mon','07:00:00',1),
(382,27,'mon','08:00:00',1),
(383,27,'mon','09:00:00',1),
(384,27,'mon','10:00:00',1),
(385,27,'mon','11:00:00',1),
(386,27,'mon','12:00:00',1),
(387,27,'mon','13:00:00',1),
(388,27,'mon','14:00:00',1),
(389,27,'mon','15:00:00',1),
(390,27,'tue','07:00:00',1),
(391,27,'tue','08:00:00',1),
(392,27,'tue','09:00:00',1),
(393,27,'tue','10:00:00',1),
(394,27,'tue','11:00:00',1),
(395,27,'tue','12:00:00',1),
(396,27,'tue','13:00:00',1),
(397,27,'tue','14:00:00',1),
(398,27,'tue','15:00:00',1),
(399,27,'wed','07:00:00',1),
(400,27,'wed','08:00:00',1),
(401,27,'wed','09:00:00',1),
(402,27,'wed','10:00:00',1),
(403,27,'wed','11:00:00',1),
(404,27,'wed','12:00:00',1),
(405,27,'wed','13:00:00',1),
(406,27,'wed','14:00:00',1),
(407,27,'wed','15:00:00',1),
(408,27,'thu','07:00:00',1),
(409,27,'thu','08:00:00',1),
(410,27,'thu','09:00:00',1),
(411,27,'thu','10:00:00',1),
(412,27,'thu','11:00:00',1),
(413,27,'thu','12:00:00',1),
(414,27,'thu','13:00:00',1),
(415,27,'thu','14:00:00',1),
(416,27,'thu','15:00:00',1),
(417,27,'fri','07:00:00',1),
(418,27,'fri','08:00:00',1),
(419,27,'fri','09:00:00',1),
(420,27,'fri','10:00:00',1),
(421,27,'fri','11:00:00',1),
(422,27,'fri','12:00:00',1),
(423,27,'fri','13:00:00',1),
(424,27,'fri','14:00:00',1),
(425,27,'fri','15:00:00',1),
(426,27,'sat','07:00:00',1),
(427,27,'sat','08:00:00',1),
(428,27,'sat','09:00:00',1),
(429,27,'sat','10:00:00',1),
(430,27,'sat','11:00:00',1),
(431,27,'sat','12:00:00',1),
(432,27,'sat','13:00:00',1),
(433,27,'sat','14:00:00',1),
(434,27,'sat','15:00:00',1),
(435,27,'sun','07:00:00',1),
(436,27,'sun','08:00:00',1),
(437,27,'sun','09:00:00',1),
(438,27,'sun','10:00:00',1),
(439,27,'sun','11:00:00',1),
(440,27,'sun','12:00:00',1),
(441,27,'sun','13:00:00',1),
(442,27,'sun','14:00:00',1),
(443,27,'sun','15:00:00',1),
(444,28,'mon','09:00:00',1),
(445,28,'mon','11:00:00',1),
(446,28,'mon','14:00:00',1),
(447,28,'mon','16:00:00',1),
(448,28,'tue','09:00:00',1),
(449,28,'tue','11:00:00',1),
(450,28,'tue','14:00:00',1),
(451,28,'tue','16:00:00',1),
(452,28,'wed','09:00:00',1),
(453,28,'wed','11:00:00',1),
(454,28,'wed','14:00:00',1),
(455,28,'wed','16:00:00',1),
(456,28,'thu','09:00:00',1),
(457,28,'thu','11:00:00',1),
(458,28,'thu','14:00:00',1),
(459,28,'thu','16:00:00',1),
(460,28,'fri','09:00:00',1),
(461,28,'fri','11:00:00',1),
(462,28,'fri','14:00:00',1),
(463,28,'fri','16:00:00',1),
(574,88,'mon','09:00:00',1),
(575,88,'mon','12:00:00',1),
(576,88,'mon','15:00:00',1),
(577,88,'mon','18:00:00',1),
(578,88,'tue','09:00:00',1),
(579,88,'tue','12:00:00',1),
(580,88,'tue','15:00:00',1),
(581,88,'tue','18:00:00',1),
(582,88,'wed','09:00:00',1),
(583,88,'wed','12:00:00',1),
(584,88,'wed','15:00:00',1),
(585,88,'wed','18:00:00',1),
(586,88,'thu','09:00:00',1),
(587,88,'thu','12:00:00',1),
(588,88,'thu','15:00:00',1),
(589,88,'thu','18:00:00',1),
(590,88,'fri','09:00:00',1),
(591,88,'fri','12:00:00',1),
(592,88,'fri','15:00:00',1),
(593,88,'fri','18:00:00',1),
(594,89,'mon','09:00:00',1),
(595,89,'mon','11:00:00',1),
(596,89,'mon','13:00:00',1),
(597,89,'mon','15:00:00',1),
(598,89,'tue','09:00:00',1),
(599,89,'tue','11:00:00',1),
(600,89,'tue','13:00:00',1),
(601,89,'tue','15:00:00',1),
(602,89,'wed','09:00:00',1),
(603,89,'wed','11:00:00',1),
(604,89,'wed','13:00:00',1),
(605,89,'wed','15:00:00',1),
(606,89,'thu','09:00:00',1),
(607,89,'thu','11:00:00',1),
(608,89,'thu','13:00:00',1),
(609,89,'thu','15:00:00',1),
(610,89,'fri','09:00:00',1),
(611,89,'fri','11:00:00',1),
(612,89,'fri','13:00:00',1),
(613,89,'fri','15:00:00',1),
(614,90,'mon','10:00:00',1),
(615,90,'mon','12:00:00',1),
(616,90,'mon','13:00:00',1),
(617,90,'mon','14:00:00',1),
(618,90,'mon','15:00:00',1),
(619,90,'mon','16:00:00',1),
(620,90,'tue','10:00:00',1),
(621,90,'tue','12:00:00',1),
(622,90,'tue','13:00:00',1),
(623,90,'tue','14:00:00',1),
(624,90,'tue','15:00:00',1),
(625,90,'tue','16:00:00',1),
(626,90,'wed','10:00:00',1),
(627,90,'wed','12:00:00',1),
(628,90,'wed','13:00:00',1),
(629,90,'wed','14:00:00',1),
(630,90,'wed','15:00:00',1),
(631,90,'wed','16:00:00',1),
(632,90,'thu','10:00:00',1),
(633,90,'thu','12:00:00',1),
(634,90,'thu','13:00:00',1),
(635,90,'thu','14:00:00',1),
(636,90,'thu','15:00:00',1),
(637,90,'thu','16:00:00',1),
(638,90,'fri','10:00:00',1),
(639,90,'fri','12:00:00',1),
(640,90,'fri','13:00:00',1),
(641,90,'fri','14:00:00',1),
(642,90,'fri','15:00:00',1),
(643,90,'fri','16:00:00',1),
(649,95,'mon','07:00:00',1),
(650,95,'mon','11:00:00',1),
(651,95,'mon','13:00:00',1),
(652,95,'tue','07:00:00',1),
(653,95,'tue','11:00:00',1),
(654,95,'tue','13:00:00',1),
(655,95,'wed','07:00:00',1),
(656,95,'wed','11:00:00',1),
(657,95,'wed','13:00:00',1),
(658,95,'thu','07:00:00',1),
(659,95,'thu','11:00:00',1),
(660,95,'thu','13:00:00',1),
(661,95,'fri','07:00:00',1),
(662,95,'fri','11:00:00',1),
(663,95,'fri','13:00:00',1),
(694,116,'mon','10:00:00',1),
(695,116,'mon','16:00:00',1),
(696,116,'mon','19:00:00',1),
(697,116,'mon','20:00:00',1),
(698,116,'tue','10:00:00',1),
(699,116,'tue','16:00:00',1),
(700,116,'tue','19:00:00',1),
(701,116,'tue','20:00:00',1),
(702,116,'wed','10:00:00',1),
(703,116,'wed','16:00:00',1),
(704,116,'wed','19:00:00',1),
(705,116,'wed','20:00:00',1),
(706,116,'thu','10:00:00',1),
(707,116,'thu','16:00:00',1),
(708,116,'thu','19:00:00',1),
(709,116,'thu','20:00:00',1),
(710,116,'fri','10:00:00',1),
(711,116,'fri','16:00:00',1),
(712,116,'fri','19:00:00',1),
(713,116,'fri','20:00:00',1),
(714,116,'sat','19:00:00',1),
(715,116,'sat','20:00:00',1),
(719,119,'mon','11:00:00',1),
(720,119,'mon','13:00:00',1),
(721,119,'mon','15:00:00',1),
(722,119,'mon','17:00:00',1),
(723,119,'tue','11:00:00',1),
(724,119,'tue','13:00:00',1),
(725,119,'tue','15:00:00',1),
(726,119,'tue','17:00:00',1),
(727,119,'wed','11:00:00',1),
(728,119,'wed','13:00:00',1),
(729,119,'wed','15:00:00',1),
(730,119,'wed','17:00:00',1),
(731,119,'thu','11:00:00',1),
(732,119,'thu','13:00:00',1),
(733,119,'thu','15:00:00',1),
(734,119,'thu','17:00:00',1),
(735,119,'fri','11:00:00',1),
(736,119,'fri','13:00:00',1),
(737,119,'fri','15:00:00',1),
(738,119,'fri','17:00:00',1),
(772,142,'mon','10:00:00',1),
(773,142,'mon','13:00:00',2),
(774,142,'mon','15:00:00',2),
(775,142,'tue','10:00:00',2),
(776,142,'tue','13:00:00',2),
(777,142,'tue','15:00:00',2),
(778,142,'wed','10:00:00',2),
(779,142,'wed','13:00:00',2),
(780,142,'wed','15:00:00',2),
(781,142,'thu','10:00:00',2),
(782,142,'thu','13:00:00',2),
(783,142,'thu','15:00:00',2),
(784,142,'fri','10:00:00',2),
(785,142,'fri','13:00:00',2),
(786,142,'fri','15:00:00',2);
/*!40000 ALTER TABLE `kampanie_emisje` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `kampanie_tygodniowe`
--

DROP TABLE IF EXISTS `kampanie_tygodniowe`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `kampanie_tygodniowe` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `klient_nazwa` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `nazwa_kampanii` varchar(255) DEFAULT NULL,
  `dlugosc` tinyint(3) unsigned NOT NULL,
  `data_start` date NOT NULL,
  `data_koniec` date NOT NULL,
  `sumy` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
  `produkty` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
  `netto_spoty` decimal(10,2) NOT NULL DEFAULT 0.00,
  `netto_dodatki` decimal(10,2) NOT NULL DEFAULT 0.00,
  `rabat` decimal(5,2) NOT NULL DEFAULT 0.00,
  `razem_po_rabacie` decimal(10,2) NOT NULL DEFAULT 0.00,
  `razem_brutto` decimal(10,2) NOT NULL DEFAULT 0.00,
  `siatka` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT NULL,
  `audio_file` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `owner_user_id` int(11) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_okres` (`data_start`,`data_koniec`)
) ENGINE=InnoDB AUTO_INCREMENT=76 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `kampanie_tygodniowe`
--

LOCK TABLES `kampanie_tygodniowe` WRITE;
/*!40000 ALTER TABLE `kampanie_tygodniowe` DISABLE KEYS */;
INSERT INTO `kampanie_tygodniowe` VALUES
(30,'KASCOMP PIOTR GUBA','KASCOMP PIOTR GUBA 2026-02-18 - 2026-02-25',20,'2026-02-18','2026-02-25','{\"prime\":18,\"standard\":12,\"night\":0}','[]',432.00,0.00,0.00,432.00,531.36,'{\"mon\":{\"06:00\":\"0\",\"07:00\":\"0\",\"08:00\":\"1\",\"09:00\":\"0\",\"10:00\":\"0\",\"11:00\":\"1\",\"12:00\":\"0\",\"13:00\":\"1\",\"14:00\":\"0\",\"15:00\":\"1\",\"16:00\":\"0\",\"17:00\":\"1\",\"18:00\":\"0\",\"19:00\":\"0\",\"20:00\":\"0\",\"21:00\":\"0\",\"22:00\":\"0\",\"23:00\":\"0\",\">23:00\":\"0\"},\"tue\":{\"06:00\":\"0\",\"07:00\":\"0\",\"08:00\":\"1\",\"09:00\":\"0\",\"10:00\":\"0\",\"11:00\":\"1\",\"12:00\":\"0\",\"13:00\":\"1\",\"14:00\":\"0\",\"15:00\":\"1\",\"16:00\":\"0\",\"17:00\":\"1\",\"18:00\":\"0\",\"19:00\":\"0\",\"20:00\":\"0\",\"21:00\":\"0\",\"22:00\":\"0\",\"23:00\":\"0\",\">23:00\":\"0\"},\"wed\":{\"06:00\":\"0\",\"07:00\":\"0\",\"08:00\":\"1\",\"09:00\":\"0\",\"10:00\":\"0\",\"11:00\":\"1\",\"12:00\":\"0\",\"13:00\":\"1\",\"14:00\":\"0\",\"15:00\":\"1\",\"16:00\":\"0\",\"17:00\":\"1\",\"18:00\":\"0\",\"19:00\":\"0\",\"20:00\":\"0\",\"21:00\":\"0\",\"22:00\":\"0\",\"23:00\":\"0\",\">23:00\":\"0\"},\"thu\":{\"06:00\":\"0\",\"07:00\":\"0\",\"08:00\":\"1\",\"09:00\":\"0\",\"10:00\":\"0\",\"11:00\":\"1\",\"12:00\":\"0\",\"13:00\":\"1\",\"14:00\":\"0\",\"15:00\":\"1\",\"16:00\":\"0\",\"17:00\":\"1\",\"18:00\":\"0\",\"19:00\":\"0\",\"20:00\":\"0\",\"21:00\":\"0\",\"22:00\":\"0\",\"23:00\":\"0\",\">23:00\":\"0\"},\"fri\":{\"06:00\":\"0\",\"07:00\":\"0\",\"08:00\":\"1\",\"09:00\":\"0\",\"10:00\":\"0\",\"11:00\":\"1\",\"12:00\":\"0\",\"13:00\":\"1\",\"14:00\":\"0\",\"15:00\":\"1\",\"16:00\":\"0\",\"17:00\":\"1\",\"18:00\":\"0\",\"19:00\":\"0\",\"20:00\":\"0\",\"21:00\":\"0\",\"22:00\":\"0\",\"23:00\":\"0\",\">23:00\":\"0\"},\"sat\":{\"06:00\":\"0\",\"07:00\":\"0\",\"08:00\":\"0\",\"09:00\":\"0\",\"10:00\":\"0\",\"11:00\":\"0\",\"12:00\":\"0\",\"13:00\":\"0\",\"14:00\":\"0\",\"15:00\":\"0\",\"16:00\":\"0\",\"17:00\":\"0\",\"18:00\":\"0\",\"19:00\":\"0\",\"20:00\":\"0\",\"21:00\":\"0\",\"22:00\":\"0\",\"23:00\":\"0\",\">23:00\":\"0\"},\"sun\":{\"06:00\":\"0\",\"07:00\":\"0\",\"08:00\":\"0\",\"09:00\":\"0\",\"10:00\":\"0\",\"11:00\":\"0\",\"12:00\":\"0\",\"13:00\":\"0\",\"14:00\":\"0\",\"15:00\":\"0\",\"16:00\":\"0\",\"17:00\":\"0\",\"18:00\":\"0\",\"19:00\":\"0\",\"20:00\":\"0\",\"21:00\":\"0\",\"22:00\":\"0\",\"23:00\":\"0\",\">23:00\":\"0\"}}','2026-02-17 15:40:36','2026-02-17 16:40:36',NULL,NULL),
(31,'5N Justyna Zienkiewicz','5N Justyna Zienkiewicz 2026-02-18 - 2026-03-18',20,'2026-02-18','2026-03-18','{\"prime\":63,\"standard\":21,\"night\":0}','[{\"nazwa\":\"Reklama Display\",\"ilosc\":2,\"kwota\":400},{\"nazwa\":\"Sygnał sponsorski\",\"ilosc\":1,\"kwota\":1100}]',1260.00,1500.00,0.00,2760.00,3394.80,'{\"mon\":{\"06:00\":\"0\",\"07:00\":\"0\",\"08:00\":\"0\",\"09:00\":\"1\",\"10:00\":\"0\",\"11:00\":\"0\",\"12:00\":\"1\",\"13:00\":\"0\",\"14:00\":\"0\",\"15:00\":\"1\",\"16:00\":\"0\",\"17:00\":\"0\",\"18:00\":\"1\",\"19:00\":\"0\",\"20:00\":\"0\",\"21:00\":\"0\",\"22:00\":\"0\",\"23:00\":\"0\",\">23:00\":\"0\"},\"tue\":{\"06:00\":\"0\",\"07:00\":\"0\",\"08:00\":\"0\",\"09:00\":\"1\",\"10:00\":\"0\",\"11:00\":\"0\",\"12:00\":\"1\",\"13:00\":\"0\",\"14:00\":\"0\",\"15:00\":\"1\",\"16:00\":\"0\",\"17:00\":\"0\",\"18:00\":\"1\",\"19:00\":\"0\",\"20:00\":\"0\",\"21:00\":\"0\",\"22:00\":\"0\",\"23:00\":\"0\",\">23:00\":\"0\"},\"wed\":{\"06:00\":\"0\",\"07:00\":\"0\",\"08:00\":\"0\",\"09:00\":\"1\",\"10:00\":\"0\",\"11:00\":\"0\",\"12:00\":\"1\",\"13:00\":\"0\",\"14:00\":\"0\",\"15:00\":\"1\",\"16:00\":\"0\",\"17:00\":\"0\",\"18:00\":\"1\",\"19:00\":\"0\",\"20:00\":\"0\",\"21:00\":\"0\",\"22:00\":\"0\",\"23:00\":\"0\",\">23:00\":\"0\"},\"thu\":{\"06:00\":\"0\",\"07:00\":\"0\",\"08:00\":\"0\",\"09:00\":\"1\",\"10:00\":\"0\",\"11:00\":\"0\",\"12:00\":\"1\",\"13:00\":\"0\",\"14:00\":\"0\",\"15:00\":\"1\",\"16:00\":\"0\",\"17:00\":\"0\",\"18:00\":\"1\",\"19:00\":\"0\",\"20:00\":\"0\",\"21:00\":\"0\",\"22:00\":\"0\",\"23:00\":\"0\",\">23:00\":\"0\"},\"fri\":{\"06:00\":\"0\",\"07:00\":\"0\",\"08:00\":\"0\",\"09:00\":\"1\",\"10:00\":\"0\",\"11:00\":\"0\",\"12:00\":\"1\",\"13:00\":\"0\",\"14:00\":\"0\",\"15:00\":\"1\",\"16:00\":\"0\",\"17:00\":\"0\",\"18:00\":\"1\",\"19:00\":\"0\",\"20:00\":\"0\",\"21:00\":\"0\",\"22:00\":\"0\",\"23:00\":\"0\",\">23:00\":\"0\"},\"sat\":{\"06:00\":\"0\",\"07:00\":\"0\",\"08:00\":\"0\",\"09:00\":\"0\",\"10:00\":\"0\",\"11:00\":\"0\",\"12:00\":\"0\",\"13:00\":\"0\",\"14:00\":\"0\",\"15:00\":\"0\",\"16:00\":\"0\",\"17:00\":\"0\",\"18:00\":\"0\",\"19:00\":\"0\",\"20:00\":\"0\",\"21:00\":\"0\",\"22:00\":\"0\",\"23:00\":\"0\",\">23:00\":\"0\"},\"sun\":{\"06:00\":\"0\",\"07:00\":\"0\",\"08:00\":\"0\",\"09:00\":\"0\",\"10:00\":\"0\",\"11:00\":\"0\",\"12:00\":\"0\",\"13:00\":\"0\",\"14:00\":\"0\",\"15:00\":\"0\",\"16:00\":\"0\",\"17:00\":\"0\",\"18:00\":\"0\",\"19:00\":\"0\",\"20:00\":\"0\",\"21:00\":\"0\",\"22:00\":\"0\",\"23:00\":\"0\",\">23:00\":\"0\"}}','2026-02-17 19:51:50','2026-02-17 20:51:50',NULL,NULL),
(32,'Alpintel Łukasz Binkowski ','Alpintel Łukasz Binkowski 2026-03-01 - 2026-03-31',20,'2026-03-01','2026-03-31','{\"prime\":44,\"standard\":44,\"night\":0}','[]',1232.00,0.00,0.00,1232.00,1515.36,'{\"mon\":{\"06:00\":\"0\",\"07:00\":\"0\",\"08:00\":\"0\",\"09:00\":\"1\",\"10:00\":\"0\",\"11:00\":\"1\",\"12:00\":\"0\",\"13:00\":\"1\",\"14:00\":\"0\",\"15:00\":\"1\",\"16:00\":\"0\",\"17:00\":\"0\",\"18:00\":\"0\",\"19:00\":\"0\",\"20:00\":\"0\",\"21:00\":\"0\",\"22:00\":\"0\",\"23:00\":\"0\",\">23:00\":\"0\"},\"tue\":{\"06:00\":\"0\",\"07:00\":\"0\",\"08:00\":\"0\",\"09:00\":\"1\",\"10:00\":\"0\",\"11:00\":\"1\",\"12:00\":\"0\",\"13:00\":\"1\",\"14:00\":\"0\",\"15:00\":\"1\",\"16:00\":\"0\",\"17:00\":\"0\",\"18:00\":\"0\",\"19:00\":\"0\",\"20:00\":\"0\",\"21:00\":\"0\",\"22:00\":\"0\",\"23:00\":\"0\",\">23:00\":\"0\"},\"wed\":{\"06:00\":\"0\",\"07:00\":\"0\",\"08:00\":\"0\",\"09:00\":\"1\",\"10:00\":\"0\",\"11:00\":\"1\",\"12:00\":\"0\",\"13:00\":\"1\",\"14:00\":\"0\",\"15:00\":\"1\",\"16:00\":\"0\",\"17:00\":\"0\",\"18:00\":\"0\",\"19:00\":\"0\",\"20:00\":\"0\",\"21:00\":\"0\",\"22:00\":\"0\",\"23:00\":\"0\",\">23:00\":\"0\"},\"thu\":{\"06:00\":\"0\",\"07:00\":\"0\",\"08:00\":\"0\",\"09:00\":\"1\",\"10:00\":\"0\",\"11:00\":\"1\",\"12:00\":\"0\",\"13:00\":\"1\",\"14:00\":\"0\",\"15:00\":\"1\",\"16:00\":\"0\",\"17:00\":\"0\",\"18:00\":\"0\",\"19:00\":\"0\",\"20:00\":\"0\",\"21:00\":\"0\",\"22:00\":\"0\",\"23:00\":\"0\",\">23:00\":\"0\"},\"fri\":{\"06:00\":\"0\",\"07:00\":\"0\",\"08:00\":\"0\",\"09:00\":\"1\",\"10:00\":\"0\",\"11:00\":\"1\",\"12:00\":\"0\",\"13:00\":\"1\",\"14:00\":\"0\",\"15:00\":\"1\",\"16:00\":\"0\",\"17:00\":\"0\",\"18:00\":\"0\",\"19:00\":\"0\",\"20:00\":\"0\",\"21:00\":\"0\",\"22:00\":\"0\",\"23:00\":\"0\",\">23:00\":\"0\"},\"sat\":{\"06:00\":\"0\",\"07:00\":\"0\",\"08:00\":\"0\",\"09:00\":\"0\",\"10:00\":\"0\",\"11:00\":\"0\",\"12:00\":\"0\",\"13:00\":\"0\",\"14:00\":\"0\",\"15:00\":\"0\",\"16:00\":\"0\",\"17:00\":\"0\",\"18:00\":\"0\",\"19:00\":\"0\",\"20:00\":\"0\",\"21:00\":\"0\",\"22:00\":\"0\",\"23:00\":\"0\",\">23:00\":\"0\"},\"sun\":{\"06:00\":\"0\",\"07:00\":\"0\",\"08:00\":\"0\",\"09:00\":\"0\",\"10:00\":\"0\",\"11:00\":\"0\",\"12:00\":\"0\",\"13:00\":\"0\",\"14:00\":\"0\",\"15:00\":\"0\",\"16:00\":\"0\",\"17:00\":\"0\",\"18:00\":\"0\",\"19:00\":\"0\",\"20:00\":\"0\",\"21:00\":\"0\",\"22:00\":\"0\",\"23:00\":\"0\",\">23:00\":\"0\"}}','2026-02-17 19:54:28','2026-02-17 20:54:28',NULL,NULL),
(33,'Alpintel Łukasz Binkowski','Alpintel Łukasz Binkowski 2026-03-01 - 2026-03-31',20,'2026-03-01','2026-03-31','{\"prime\":44,\"standard\":88,\"night\":0}','[]',1760.00,0.00,0.00,1760.00,2164.80,'{\"mon\":{\"06:00\":\"0\",\"07:00\":\"0\",\"08:00\":\"0\",\"09:00\":\"0\",\"10:00\":\"1\",\"11:00\":\"0\",\"12:00\":\"1\",\"13:00\":\"1\",\"14:00\":\"1\",\"15:00\":\"1\",\"16:00\":\"1\",\"17:00\":\"0\",\"18:00\":\"0\",\"19:00\":\"0\",\"20:00\":\"0\",\"21:00\":\"0\",\"22:00\":\"0\",\"23:00\":\"0\",\">23:00\":\"0\"},\"tue\":{\"06:00\":\"0\",\"07:00\":\"0\",\"08:00\":\"0\",\"09:00\":\"0\",\"10:00\":\"1\",\"11:00\":\"0\",\"12:00\":\"1\",\"13:00\":\"1\",\"14:00\":\"1\",\"15:00\":\"1\",\"16:00\":\"1\",\"17:00\":\"0\",\"18:00\":\"0\",\"19:00\":\"0\",\"20:00\":\"0\",\"21:00\":\"0\",\"22:00\":\"0\",\"23:00\":\"0\",\">23:00\":\"0\"},\"wed\":{\"06:00\":\"0\",\"07:00\":\"0\",\"08:00\":\"0\",\"09:00\":\"0\",\"10:00\":\"1\",\"11:00\":\"0\",\"12:00\":\"1\",\"13:00\":\"1\",\"14:00\":\"1\",\"15:00\":\"1\",\"16:00\":\"1\",\"17:00\":\"0\",\"18:00\":\"0\",\"19:00\":\"0\",\"20:00\":\"0\",\"21:00\":\"0\",\"22:00\":\"0\",\"23:00\":\"0\",\">23:00\":\"0\"},\"thu\":{\"06:00\":\"0\",\"07:00\":\"0\",\"08:00\":\"0\",\"09:00\":\"0\",\"10:00\":\"1\",\"11:00\":\"0\",\"12:00\":\"1\",\"13:00\":\"1\",\"14:00\":\"1\",\"15:00\":\"1\",\"16:00\":\"1\",\"17:00\":\"0\",\"18:00\":\"0\",\"19:00\":\"0\",\"20:00\":\"0\",\"21:00\":\"0\",\"22:00\":\"0\",\"23:00\":\"0\",\">23:00\":\"0\"},\"fri\":{\"06:00\":\"0\",\"07:00\":\"0\",\"08:00\":\"0\",\"09:00\":\"0\",\"10:00\":\"1\",\"11:00\":\"0\",\"12:00\":\"1\",\"13:00\":\"1\",\"14:00\":\"1\",\"15:00\":\"1\",\"16:00\":\"1\",\"17:00\":\"0\",\"18:00\":\"0\",\"19:00\":\"0\",\"20:00\":\"0\",\"21:00\":\"0\",\"22:00\":\"0\",\"23:00\":\"0\",\">23:00\":\"0\"},\"sat\":{\"06:00\":\"0\",\"07:00\":\"0\",\"08:00\":\"0\",\"09:00\":\"0\",\"10:00\":\"0\",\"11:00\":\"0\",\"12:00\":\"0\",\"13:00\":\"0\",\"14:00\":\"0\",\"15:00\":\"0\",\"16:00\":\"0\",\"17:00\":\"0\",\"18:00\":\"0\",\"19:00\":\"0\",\"20:00\":\"0\",\"21:00\":\"0\",\"22:00\":\"0\",\"23:00\":\"0\",\">23:00\":\"0\"},\"sun\":{\"06:00\":\"0\",\"07:00\":\"0\",\"08:00\":\"0\",\"09:00\":\"0\",\"10:00\":\"0\",\"11:00\":\"0\",\"12:00\":\"0\",\"13:00\":\"0\",\"14:00\":\"0\",\"15:00\":\"0\",\"16:00\":\"0\",\"17:00\":\"0\",\"18:00\":\"0\",\"19:00\":\"0\",\"20:00\":\"0\",\"21:00\":\"0\",\"22:00\":\"0\",\"23:00\":\"0\",\">23:00\":\"0\"}}','2026-02-17 19:55:13','2026-02-17 20:55:13',NULL,NULL),
(35,'TEST_PROP_1771358760','TEST_PROP_1771358760',20,'2026-02-17','2026-02-24','{\"prime\":1,\"standard\":0,\"night\":0}','[]',100.00,0.00,0.00,100.00,123.00,'{\"mon\":{\"06:00\":\"1\"}}','2026-02-17 20:06:00','2026-02-17 21:06:00','storage/uploads/audio/35_1771692203_VT1553.mp3',NULL),
(36,'TEST_AKT_1771358772','TEST_AKT_1771358772',20,'2026-02-17','2026-02-24','{\"prime\":1,\"standard\":0,\"night\":0}','[]',100.00,0.00,0.00,100.00,123.00,'{\"mon\":{\"06:00\":\"1\"}}','2026-02-17 20:06:12','2026-02-17 21:06:12',NULL,NULL),
(37,'5N Justyna Zienkiewicz','5N Justyna Zienkiewicz 2026-03-01 - 2026-03-31',20,'2026-03-01','2026-03-31','{\"prime\":22,\"standard\":44,\"night\":0}','[]',880.00,0.00,0.00,880.00,1082.40,'{\"mon\":{\"06:00\":\"0\",\"07:00\":\"1\",\"08:00\":\"0\",\"09:00\":\"0\",\"10:00\":\"0\",\"11:00\":\"1\",\"12:00\":\"0\",\"13:00\":\"1\",\"14:00\":\"0\",\"15:00\":\"0\",\"16:00\":\"0\",\"17:00\":\"0\",\"18:00\":\"0\",\"19:00\":\"0\",\"20:00\":\"0\",\"21:00\":\"0\",\"22:00\":\"0\",\"23:00\":\"0\",\">23:00\":\"0\"},\"tue\":{\"06:00\":\"0\",\"07:00\":\"1\",\"08:00\":\"0\",\"09:00\":\"0\",\"10:00\":\"0\",\"11:00\":\"1\",\"12:00\":\"0\",\"13:00\":\"1\",\"14:00\":\"0\",\"15:00\":\"0\",\"16:00\":\"0\",\"17:00\":\"0\",\"18:00\":\"0\",\"19:00\":\"0\",\"20:00\":\"0\",\"21:00\":\"0\",\"22:00\":\"0\",\"23:00\":\"0\",\">23:00\":\"0\"},\"wed\":{\"06:00\":\"0\",\"07:00\":\"1\",\"08:00\":\"0\",\"09:00\":\"0\",\"10:00\":\"0\",\"11:00\":\"1\",\"12:00\":\"0\",\"13:00\":\"1\",\"14:00\":\"0\",\"15:00\":\"0\",\"16:00\":\"0\",\"17:00\":\"0\",\"18:00\":\"0\",\"19:00\":\"0\",\"20:00\":\"0\",\"21:00\":\"0\",\"22:00\":\"0\",\"23:00\":\"0\",\">23:00\":\"0\"},\"thu\":{\"06:00\":\"0\",\"07:00\":\"1\",\"08:00\":\"0\",\"09:00\":\"0\",\"10:00\":\"0\",\"11:00\":\"1\",\"12:00\":\"0\",\"13:00\":\"1\",\"14:00\":\"0\",\"15:00\":\"0\",\"16:00\":\"0\",\"17:00\":\"0\",\"18:00\":\"0\",\"19:00\":\"0\",\"20:00\":\"0\",\"21:00\":\"0\",\"22:00\":\"0\",\"23:00\":\"0\",\">23:00\":\"0\"},\"fri\":{\"06:00\":\"0\",\"07:00\":\"1\",\"08:00\":\"0\",\"09:00\":\"0\",\"10:00\":\"0\",\"11:00\":\"1\",\"12:00\":\"0\",\"13:00\":\"1\",\"14:00\":\"0\",\"15:00\":\"0\",\"16:00\":\"0\",\"17:00\":\"0\",\"18:00\":\"0\",\"19:00\":\"0\",\"20:00\":\"0\",\"21:00\":\"0\",\"22:00\":\"0\",\"23:00\":\"0\",\">23:00\":\"0\"},\"sat\":{\"06:00\":\"0\",\"07:00\":\"0\",\"08:00\":\"0\",\"09:00\":\"0\",\"10:00\":\"0\",\"11:00\":\"0\",\"12:00\":\"0\",\"13:00\":\"0\",\"14:00\":\"0\",\"15:00\":\"0\",\"16:00\":\"0\",\"17:00\":\"0\",\"18:00\":\"0\",\"19:00\":\"0\",\"20:00\":\"0\",\"21:00\":\"0\",\"22:00\":\"0\",\"23:00\":\"0\",\">23:00\":\"0\"},\"sun\":{\"06:00\":\"0\",\"07:00\":\"0\",\"08:00\":\"0\",\"09:00\":\"0\",\"10:00\":\"0\",\"11:00\":\"0\",\"12:00\":\"0\",\"13:00\":\"0\",\"14:00\":\"0\",\"15:00\":\"0\",\"16:00\":\"0\",\"17:00\":\"0\",\"18:00\":\"0\",\"19:00\":\"0\",\"20:00\":\"0\",\"21:00\":\"0\",\"22:00\":\"0\",\"23:00\":\"0\",\">23:00\":\"0\"}}','2026-02-17 20:18:41','2026-02-17 21:18:41',NULL,NULL),
(48,'Firma Handlowo-Usługowa Beata Wojtkiewicz BEATA SADOWSKA','Firma Handlowo-Usługowa Beata Wojtkiewicz BEATA SADOWSKA 2026-03-01 - 2026-03-31',20,'2026-03-01','2026-03-31','{\"prime\":22,\"standard\":74,\"night\":0}','[{\"nazwa\":\"Reklama Display\",\"ilosc\":1,\"kwota\":200},{\"nazwa\":\"Wywiad\",\"ilosc\":1,\"kwota\":500},{\"nazwa\":\"Social Media\",\"ilosc\":2,\"kwota\":500}]',1240.00,1200.00,0.00,2440.00,3001.20,'{\"mon\":{\"06:00\":\"0\",\"07:00\":\"0\",\"08:00\":\"0\",\"09:00\":\"0\",\"10:00\":\"1\",\"11:00\":\"0\",\"12:00\":\"0\",\"13:00\":\"0\",\"14:00\":\"0\",\"15:00\":\"0\",\"16:00\":\"1\",\"17:00\":\"0\",\"18:00\":\"0\",\"19:00\":\"1\",\"20:00\":\"1\",\"21:00\":\"0\",\"22:00\":\"0\",\"23:00\":\"0\",\">23:00\":\"0\"},\"tue\":{\"06:00\":\"0\",\"07:00\":\"0\",\"08:00\":\"0\",\"09:00\":\"0\",\"10:00\":\"1\",\"11:00\":\"0\",\"12:00\":\"0\",\"13:00\":\"0\",\"14:00\":\"0\",\"15:00\":\"0\",\"16:00\":\"1\",\"17:00\":\"0\",\"18:00\":\"0\",\"19:00\":\"1\",\"20:00\":\"1\",\"21:00\":\"0\",\"22:00\":\"0\",\"23:00\":\"0\",\">23:00\":\"0\"},\"wed\":{\"06:00\":\"0\",\"07:00\":\"0\",\"08:00\":\"0\",\"09:00\":\"0\",\"10:00\":\"1\",\"11:00\":\"0\",\"12:00\":\"0\",\"13:00\":\"0\",\"14:00\":\"0\",\"15:00\":\"0\",\"16:00\":\"1\",\"17:00\":\"0\",\"18:00\":\"0\",\"19:00\":\"1\",\"20:00\":\"1\",\"21:00\":\"0\",\"22:00\":\"0\",\"23:00\":\"0\",\">23:00\":\"0\"},\"thu\":{\"06:00\":\"0\",\"07:00\":\"0\",\"08:00\":\"0\",\"09:00\":\"0\",\"10:00\":\"1\",\"11:00\":\"0\",\"12:00\":\"0\",\"13:00\":\"0\",\"14:00\":\"0\",\"15:00\":\"0\",\"16:00\":\"1\",\"17:00\":\"0\",\"18:00\":\"0\",\"19:00\":\"1\",\"20:00\":\"1\",\"21:00\":\"0\",\"22:00\":\"0\",\"23:00\":\"0\",\">23:00\":\"0\"},\"fri\":{\"06:00\":\"0\",\"07:00\":\"0\",\"08:00\":\"0\",\"09:00\":\"0\",\"10:00\":\"1\",\"11:00\":\"0\",\"12:00\":\"0\",\"13:00\":\"0\",\"14:00\":\"0\",\"15:00\":\"0\",\"16:00\":\"1\",\"17:00\":\"0\",\"18:00\":\"0\",\"19:00\":\"1\",\"20:00\":\"1\",\"21:00\":\"0\",\"22:00\":\"0\",\"23:00\":\"0\",\">23:00\":\"0\"},\"sat\":{\"06:00\":\"0\",\"07:00\":\"0\",\"08:00\":\"0\",\"09:00\":\"0\",\"10:00\":\"0\",\"11:00\":\"0\",\"12:00\":\"0\",\"13:00\":\"0\",\"14:00\":\"0\",\"15:00\":\"0\",\"16:00\":\"0\",\"17:00\":\"0\",\"18:00\":\"0\",\"19:00\":\"1\",\"20:00\":\"1\",\"21:00\":\"0\",\"22:00\":\"0\",\"23:00\":\"0\",\">23:00\":\"0\"},\"sun\":{\"06:00\":\"0\",\"07:00\":\"0\",\"08:00\":\"0\",\"09:00\":\"0\",\"10:00\":\"0\",\"11:00\":\"0\",\"12:00\":\"0\",\"13:00\":\"0\",\"14:00\":\"0\",\"15:00\":\"0\",\"16:00\":\"0\",\"17:00\":\"0\",\"18:00\":\"0\",\"19:00\":\"0\",\"20:00\":\"0\",\"21:00\":\"0\",\"22:00\":\"0\",\"23:00\":\"0\",\">23:00\":\"0\"}}','2026-02-18 12:04:08','2026-02-18 13:04:08',NULL,NULL),
(62,'LAYMAN SPÓŁKA Z OGRANICZONĄ ODPOWIEDZIALNOŚCIĄ SPÓŁKA KOMANDYTOWA','LAYMAN SPÓŁKA Z OGRANICZONĄ ODPOWIEDZIALNOŚCIĄ SPÓŁKA KOMANDYTOWA 2026-02-22 - 2026-03-31',20,'2026-02-22','2026-03-31','{\"prime\":54,\"standard\":102,\"night\":0}','[]',2088.00,0.00,0.00,2088.00,2568.24,'{\"mon\":{\"06:00\":\"0\",\"07:00\":\"0\",\"08:00\":\"0\",\"09:00\":\"0\",\"10:00\":\"1\",\"11:00\":\"0\",\"12:00\":\"0\",\"13:00\":\"2\",\"14:00\":\"0\",\"15:00\":\"2\",\"16:00\":\"0\",\"17:00\":\"0\",\"18:00\":\"0\",\"19:00\":\"0\",\"20:00\":\"0\",\"21:00\":\"0\",\"22:00\":\"0\",\"23:00\":\"0\",\">23:00\":\"0\"},\"tue\":{\"06:00\":\"0\",\"07:00\":\"0\",\"08:00\":\"0\",\"09:00\":\"0\",\"10:00\":\"2\",\"11:00\":\"0\",\"12:00\":\"0\",\"13:00\":\"2\",\"14:00\":\"0\",\"15:00\":\"2\",\"16:00\":\"0\",\"17:00\":\"0\",\"18:00\":\"0\",\"19:00\":\"0\",\"20:00\":\"0\",\"21:00\":\"0\",\"22:00\":\"0\",\"23:00\":\"0\",\">23:00\":\"0\"},\"wed\":{\"06:00\":\"0\",\"07:00\":\"0\",\"08:00\":\"0\",\"09:00\":\"0\",\"10:00\":\"2\",\"11:00\":\"0\",\"12:00\":\"0\",\"13:00\":\"2\",\"14:00\":\"0\",\"15:00\":\"2\",\"16:00\":\"0\",\"17:00\":\"0\",\"18:00\":\"0\",\"19:00\":\"0\",\"20:00\":\"0\",\"21:00\":\"0\",\"22:00\":\"0\",\"23:00\":\"0\",\">23:00\":\"0\"},\"thu\":{\"06:00\":\"0\",\"07:00\":\"0\",\"08:00\":\"0\",\"09:00\":\"0\",\"10:00\":\"2\",\"11:00\":\"0\",\"12:00\":\"0\",\"13:00\":\"2\",\"14:00\":\"0\",\"15:00\":\"2\",\"16:00\":\"0\",\"17:00\":\"0\",\"18:00\":\"0\",\"19:00\":\"0\",\"20:00\":\"0\",\"21:00\":\"0\",\"22:00\":\"0\",\"23:00\":\"0\",\">23:00\":\"0\"},\"fri\":{\"06:00\":\"0\",\"07:00\":\"0\",\"08:00\":\"0\",\"09:00\":\"0\",\"10:00\":\"2\",\"11:00\":\"0\",\"12:00\":\"0\",\"13:00\":\"2\",\"14:00\":\"0\",\"15:00\":\"2\",\"16:00\":\"0\",\"17:00\":\"0\",\"18:00\":\"0\",\"19:00\":\"0\",\"20:00\":\"0\",\"21:00\":\"0\",\"22:00\":\"0\",\"23:00\":\"0\",\">23:00\":\"0\"},\"sat\":{\"06:00\":\"0\",\"07:00\":\"0\",\"08:00\":\"0\",\"09:00\":\"0\",\"10:00\":\"0\",\"11:00\":\"0\",\"12:00\":\"0\",\"13:00\":\"0\",\"14:00\":\"0\",\"15:00\":\"0\",\"16:00\":\"0\",\"17:00\":\"0\",\"18:00\":\"0\",\"19:00\":\"0\",\"20:00\":\"0\",\"21:00\":\"0\",\"22:00\":\"0\",\"23:00\":\"0\",\">23:00\":\"0\"},\"sun\":{\"06:00\":\"0\",\"07:00\":\"0\",\"08:00\":\"0\",\"09:00\":\"0\",\"10:00\":\"0\",\"11:00\":\"0\",\"12:00\":\"0\",\"13:00\":\"0\",\"14:00\":\"0\",\"15:00\":\"0\",\"16:00\":\"0\",\"17:00\":\"0\",\"18:00\":\"0\",\"19:00\":\"0\",\"20:00\":\"0\",\"21:00\":\"0\",\"22:00\":\"0\",\"23:00\":\"0\",\">23:00\":\"0\"}}','2026-02-21 14:27:40','2026-02-21 15:27:40',NULL,NULL);
/*!40000 ALTER TABLE `kampanie_tygodniowe` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `klienci`
--

DROP TABLE IF EXISTS `klienci`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `klienci` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `nazwa_firmy` varchar(255) DEFAULT NULL,
  `nip` varchar(20) DEFAULT NULL,
  `regon` varchar(50) DEFAULT NULL,
  `adres` varchar(255) DEFAULT NULL,
  `data_dodania` timestamp NULL DEFAULT current_timestamp(),
  `ulica` varchar(255) DEFAULT NULL,
  `nr_nieruchomosci` varchar(20) DEFAULT NULL,
  `nr_lokalu` varchar(20) DEFAULT NULL,
  `kod_pocztowy` varchar(20) DEFAULT NULL,
  `miejscowosc` varchar(100) DEFAULT NULL,
  `wojewodztwo` varchar(100) DEFAULT NULL,
  `powiat` varchar(100) DEFAULT NULL,
  `gmina` varchar(100) DEFAULT NULL,
  `kraj` varchar(100) DEFAULT NULL,
  `telefon` varchar(50) DEFAULT NULL,
  `email` varchar(100) DEFAULT NULL,
  `strona_www` varchar(255) DEFAULT NULL,
  `status` varchar(50) DEFAULT 'Nowy',
  `ostatni_kontakt` date DEFAULT NULL,
  `notatka` text DEFAULT NULL,
  `potencjal` decimal(10,2) DEFAULT NULL,
  `przypomnienie` date DEFAULT NULL,
  `branza` varchar(255) DEFAULT NULL,
  `owner_user_id` int(11) DEFAULT NULL,
  `source_lead_id` int(11) DEFAULT NULL,
  `assigned_user_id` int(11) DEFAULT NULL,
  `kontakt_imie_nazwisko` varchar(120) DEFAULT NULL,
  `kontakt_stanowisko` varchar(120) DEFAULT NULL,
  `kontakt_telefon` varchar(60) DEFAULT NULL,
  `kontakt_email` varchar(120) DEFAULT NULL,
  `kontakt_preferencja` enum('telefon','email','sms','') NOT NULL DEFAULT '',
  `company_id` int(11) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `nip` (`nip`),
  KEY `idx_klienci_owner_user_id` (`owner_user_id`),
  KEY `idx_klienci_source_lead_id` (`source_lead_id`),
  KEY `idx_klienci_assigned_user_id` (`assigned_user_id`),
  KEY `idx_klienci_company_id` (`company_id`),
  CONSTRAINT `fk_klienci_company` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=20 DEFAULT CHARSET=latin2 COLLATE=latin2_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `klienci`
--

LOCK TABLES `klienci` WRITE;
/*!40000 ALTER TABLE `klienci` DISABLE KEYS */;
INSERT INTO `klienci` VALUES
(3,'OHMG Sp. z o.o.','5792268412','',NULL,'2025-04-23 18:53:18','Grobelno','8','','82-200','Grobelno','pomorskie',NULL,NULL,'Polska','55 621 30 20','ceo@ohmg.pl','www.ohmg.pl','Nowy',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,'',NULL),
(4,'Nasza Europa','5811822572','',NULL,'2025-05-26 11:49:31','','','','','','',NULL,NULL,'Polska','691271822','phutarget@gmail.com','','Nowy',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,'',NULL),
(5,'PHU ELRO','5790004488','','Grobelno','2025-11-10 16:37:24',NULL,NULL,NULL,NULL,'Malbork','',NULL,NULL,NULL,'55 272 22 67','biuro@groblanka.pl',NULL,'Nowy','2025-11-07','kontakt z klientem',2500.00,'2025-11-12','Hurtownia opakowań szklanych',NULL,NULL,NULL,NULL,NULL,NULL,NULL,'',NULL),
(6,'Zabawka24','5798885455','','Powstańców 13','2025-11-23 18:45:57',NULL,NULL,NULL,NULL,'Elbląg','warmińsko-mazurskie',NULL,NULL,NULL,'','zabawka@wp.pl',NULL,'Nowy','2025-11-21','Klient dosyć zdecydowany ',5000.00,'2025-12-01','Hurtownia zabawek',NULL,NULL,NULL,NULL,NULL,NULL,NULL,'',NULL),
(7,'Aparthotel Dworzec','5798885487',NULL,NULL,'2025-12-12 22:53:37',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,'795 700 075',NULL,NULL,'Nowy',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,'',NULL),
(8,'Kasy i drukarki fiskalne KAScomp','5783132297',NULL,NULL,'2025-12-13 23:32:15',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,'55 642 22 00','ceo@ohmg.pl',NULL,'Nowy',NULL,'Zadzwonić',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,'',NULL),
(9,'HADM AG SPÓŁKA Z OGRANICZONĄ ODPOWIEDZIALNOŚCIĄ','5783129378','369231736','WARSZAWSKA 87','2025-12-28 16:41:54',NULL,NULL,NULL,NULL,'82-300 ELBLĄG','',NULL,NULL,NULL,'','',NULL,'Nowy','0000-00-00','',0.00,'0000-00-00','',NULL,NULL,NULL,NULL,NULL,NULL,NULL,'',NULL),
(13,NULL,NULL,NULL,NULL,'2026-02-18 10:37:11',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,'ceo@ohmg.pl',NULL,'Nowy',NULL,NULL,NULL,NULL,NULL,2,8,2,NULL,NULL,NULL,NULL,'',2),
(14,NULL,NULL,NULL,NULL,'2026-02-18 11:37:57',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,'naczelny@radiozulawy.pl',NULL,'Nowy',NULL,NULL,NULL,NULL,NULL,2,7,2,NULL,NULL,NULL,NULL,'',3),
(15,NULL,NULL,NULL,NULL,'2026-02-18 12:14:23',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,'karolpufal@gmail.com',NULL,'Nowy',NULL,NULL,NULL,NULL,NULL,2,9,2,NULL,NULL,NULL,NULL,'',4),
(16,NULL,NULL,NULL,NULL,'2026-02-18 13:00:25',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,'marianwolski2323@gmail.com',NULL,'Nowy',NULL,'Zadzwonić do klienta',NULL,NULL,NULL,2,10,2,NULL,NULL,NULL,NULL,'',5),
(17,NULL,NULL,NULL,NULL,'2026-02-21 11:57:14',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,'Nowy',NULL,NULL,NULL,NULL,NULL,2,NULL,2,NULL,NULL,NULL,NULL,'',6),
(18,NULL,NULL,NULL,NULL,'2026-02-21 11:57:33',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,'Nowy',NULL,NULL,NULL,NULL,NULL,2,NULL,2,NULL,NULL,NULL,NULL,'',7),
(19,NULL,NULL,NULL,NULL,'2026-02-21 11:57:57',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,'Nowy',NULL,NULL,NULL,NULL,NULL,2,NULL,2,NULL,NULL,NULL,NULL,'',8);
/*!40000 ALTER TABLE `klienci` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `konfiguracja_systemu`
--

DROP TABLE IF EXISTS `konfiguracja_systemu`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `konfiguracja_systemu` (
  `id` int(11) NOT NULL DEFAULT 1,
  `liczba_blokow` int(11) NOT NULL DEFAULT 2,
  `godzina_start` time NOT NULL DEFAULT '07:00:00',
  `godzina_koniec` time NOT NULL DEFAULT '21:00:00',
  `prog_procentowy` enum('0','2','7','12','20') NOT NULL DEFAULT '2',
  `prime_hours` varchar(255) DEFAULT NULL,
  `standard_hours` varchar(255) DEFAULT NULL,
  `night_hours` varchar(255) DEFAULT NULL,
  `pdf_logo_path` varchar(255) DEFAULT NULL,
  `smtp_host` varchar(255) DEFAULT NULL,
  `smtp_port` int(11) DEFAULT NULL,
  `smtp_secure` varchar(10) DEFAULT NULL,
  `smtp_auth` tinyint(1) DEFAULT NULL,
  `smtp_default_from_email` varchar(255) DEFAULT NULL,
  `smtp_default_from_name` varchar(255) DEFAULT NULL,
  `smtp_username` varchar(255) DEFAULT NULL,
  `smtp_password` varchar(255) DEFAULT NULL,
  `email_signature_template_html` longtext DEFAULT NULL,
  `limit_prime_seconds_per_day` int(11) NOT NULL DEFAULT 3600,
  `limit_standard_seconds_per_day` int(11) NOT NULL DEFAULT 3600,
  `limit_night_seconds_per_day` int(11) NOT NULL DEFAULT 3600,
  `maintenance_last_run_at` datetime DEFAULT NULL,
  `maintenance_interval_minutes` int(11) NOT NULL DEFAULT 10,
  `audio_upload_max_mb` int(11) NOT NULL DEFAULT 50,
  `audio_allowed_ext` varchar(100) NOT NULL DEFAULT 'wav,mp3',
  `gus_enabled` tinyint(1) NOT NULL DEFAULT 0,
  `gus_api_key` varchar(255) DEFAULT NULL,
  `gus_environment` varchar(20) NOT NULL DEFAULT 'prod',
  `gus_cache_ttl_days` int(11) NOT NULL DEFAULT 30,
  `company_name` varchar(255) DEFAULT NULL,
  `company_address` varchar(255) DEFAULT NULL,
  `company_nip` varchar(50) DEFAULT NULL,
  `company_email` varchar(255) DEFAULT NULL,
  `company_phone` varchar(50) DEFAULT NULL,
  `documents_storage_path` varchar(255) DEFAULT NULL,
  `documents_number_prefix` varchar(50) NOT NULL DEFAULT 'AM/',
  `gus_auto_refresh_enabled` tinyint(1) NOT NULL DEFAULT 0,
  `gus_auto_refresh_batch` int(11) NOT NULL DEFAULT 20,
  `gus_auto_refresh_interval_days` int(11) NOT NULL DEFAULT 30,
  `gus_auto_refresh_backoff_minutes` int(11) NOT NULL DEFAULT 60,
  `crm_archive_bcc_email` varchar(255) DEFAULT NULL,
  `crm_archive_enabled` tinyint(1) NOT NULL DEFAULT 0,
  `block_duration_seconds` int(11) NOT NULL DEFAULT 45,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=latin2 COLLATE=latin2_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `konfiguracja_systemu`
--

LOCK TABLES `konfiguracja_systemu` WRITE;
/*!40000 ALTER TABLE `konfiguracja_systemu` DISABLE KEYS */;
INSERT INTO `konfiguracja_systemu` VALUES
(1,2,'07:00:00','23:00:00','2','06:00-09:59,15:00-18:59','10:00-14:59,19:00-22:59','00:00-05:59,23:00-23:59','uploads/settings/mediaplan_logo_1765662090.png','server480824.nazwa.pl',465,'ssl',1,'reklama@radiozulawy.pl','Radio Żuławy','reklama@radiozulawy.pl','Radio1234',NULL,3600,3600,3600,'2026-02-25 23:11:25',10,50,'wav,mp3',1,'bfb4e8dec7ea4129ab5a','prod',30,'OHMG Sp. z o.o.','Grobelno 8','5792268412','office@ohmg.pl',NULL,'storage/docs/','AM/',0,20,30,60,NULL,0,180);
/*!40000 ALTER TABLE `konfiguracja_systemu` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `koszty_dodatkowe`
--

DROP TABLE IF EXISTS `koszty_dodatkowe`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `koszty_dodatkowe` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `nazwa_kosztu` varchar(255) NOT NULL,
  `kwota_netto` decimal(10,2) NOT NULL,
  `opis` varchar(255) DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=latin2 COLLATE=latin2_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `koszty_dodatkowe`
--

LOCK TABLES `koszty_dodatkowe` WRITE;
/*!40000 ALTER TABLE `koszty_dodatkowe` DISABLE KEYS */;
/*!40000 ALTER TABLE `koszty_dodatkowe` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `leady`
--

DROP TABLE IF EXISTS `leady`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `leady` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `nazwa_firmy` varchar(255) NOT NULL,
  `nip` varchar(20) DEFAULT NULL,
  `telefon` varchar(50) DEFAULT NULL,
  `email` varchar(255) DEFAULT NULL,
  `zrodlo` enum('telefon','email','formularz_www','maps_api','polecenie','inne') NOT NULL DEFAULT 'inne',
  `przypisany_handlowiec` varchar(255) DEFAULT NULL,
  `status` varchar(50) NOT NULL DEFAULT 'nowy',
  `notatki` text DEFAULT NULL,
  `next_action_date` date DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `owner_user_id` int(11) DEFAULT NULL,
  `priority` varchar(20) NOT NULL DEFAULT 'Średni',
  `next_action` varchar(255) DEFAULT NULL,
  `next_action_at` datetime DEFAULT NULL,
  `client_id` int(11) DEFAULT NULL,
  `converted_at` datetime DEFAULT NULL,
  `converted_by_user_id` int(11) DEFAULT NULL,
  `assigned_user_id` int(11) DEFAULT NULL,
  `kontakt_imie_nazwisko` varchar(120) DEFAULT NULL,
  `kontakt_stanowisko` varchar(120) DEFAULT NULL,
  `kontakt_telefon` varchar(60) DEFAULT NULL,
  `kontakt_email` varchar(120) DEFAULT NULL,
  `kontakt_preferencja` enum('telefon','email','sms','') NOT NULL DEFAULT '',
  `kod_pocztowy` varchar(12) DEFAULT NULL,
  `miasto` varchar(120) DEFAULT NULL,
  `ulica` varchar(180) DEFAULT NULL,
  `nr_budynku` varchar(30) DEFAULT NULL,
  `nr_lokalu` varchar(30) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_leady_status` (`status`),
  KEY `idx_leady_zrodlo` (`zrodlo`),
  KEY `idx_leady_nip` (`nip`),
  KEY `idx_leady_created_at` (`created_at`),
  KEY `idx_leady_owner_user_id` (`owner_user_id`),
  KEY `idx_leady_client_id` (`client_id`),
  KEY `idx_leady_next_action_at` (`next_action_at`),
  KEY `idx_leady_converted_at` (`converted_at`),
  KEY `idx_leady_assigned_user_id` (`assigned_user_id`)
) ENGINE=InnoDB AUTO_INCREMENT=11 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `leady`
--

LOCK TABLES `leady` WRITE;
/*!40000 ALTER TABLE `leady` DISABLE KEYS */;
INSERT INTO `leady` VALUES
(3,'OHMG SPÓŁKA Z OGRANICZONĄ ODPOWIEDZIALNOŚCIĄ','5792268412',NULL,'ceo@ohmg.pl','telefon',NULL,'skonwertowany',NULL,'2026-02-19','2026-02-16 20:55:59','2026-02-25 19:55:27',2,'Niski','Follow-up po ofercie','2026-02-19 21:57:37',12,'2026-02-16 22:18:35',2,2,NULL,NULL,NULL,NULL,'',NULL,NULL,NULL,NULL,NULL),
(5,'5N Justyna Zienkiewicz','5782751414',NULL,NULL,'telefon','Jan Kowalski','w_kontakcie',NULL,'2026-02-20','2026-02-17 19:50:57','2026-02-25 20:37:28',2,'Niski','Follow-up po ofercie','2026-02-20 23:11:28',NULL,NULL,NULL,2,NULL,NULL,NULL,NULL,'','82-300','Elbląg','ul. Natolińska','46',NULL),
(6,'Adrian Wysocki Wsparcie IT','5782848332',NULL,NULL,'telefon','Jan Kowalski','wygrana',NULL,NULL,'2026-02-17 19:53:00','2026-02-25 19:55:27',2,'Niski',NULL,NULL,NULL,NULL,NULL,2,NULL,NULL,NULL,NULL,'','82-300','Elbląg','ul. Natolińska','32',NULL),
(7,'Alpintel Łukasz Binkowski','5782968272',NULL,NULL,'telefon','Jan Kowalski','skonwertowany',NULL,NULL,'2026-02-17 19:53:18','2026-02-25 19:55:27',2,'Niski',NULL,NULL,14,'2026-02-18 12:37:57',2,2,NULL,NULL,NULL,NULL,'','82-300','Elbląg','ul. Natolińska','31',NULL),
(8,'OHMG SPÓŁKA Z OGRANICZONĄ ODPOWIEDZIALNOŚCIĄ','5792268412',NULL,'ceo@ohmg.pl','telefon','Jan Kowalski','skonwertowany',NULL,NULL,'2026-02-17 22:05:01','2026-02-25 19:55:27',2,'Niski',NULL,NULL,13,'2026-02-18 11:37:11',2,2,NULL,NULL,NULL,NULL,'','82-200','Grobelno',NULL,'8',NULL),
(9,'Firma Handlowo-Usługowa Beata Wojtkiewicz BEATA SADOWSKA','5783101032',NULL,'karolpufal@gmail.com','telefon','Jan Kowalski','skonwertowany',NULL,NULL,'2026-02-18 12:03:07','2026-02-25 19:55:27',2,'Niski',NULL,NULL,15,'2026-02-18 13:14:23',2,2,NULL,NULL,NULL,NULL,'','82-300','Elbląg','ul. Ułańska','13',NULL),
(10,'Damian Szuplewski','5782068232',NULL,'marianwolski2323@gmail.com','telefon','Jan Kowalski','skonwertowany','Zadzwonić do klienta','2026-02-19','2026-02-18 12:23:55','2026-02-25 19:55:27',2,'Średni','Kontakt z klientem','2026-02-19 12:10:00',16,'2026-02-18 14:00:25',2,2,NULL,NULL,NULL,NULL,'','82-300','Elbląg','ul. Bohaterów Monte Cassino','2a',NULL);
/*!40000 ALTER TABLE `leady` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `leady_aktywnosci`
--

DROP TABLE IF EXISTS `leady_aktywnosci`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `leady_aktywnosci` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `lead_id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `typ` varchar(30) NOT NULL,
  `opis` text DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `lead_id` (`lead_id`),
  KEY `user_id` (`user_id`)
) ENGINE=InnoDB AUTO_INCREMENT=27 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `leady_aktywnosci`
--

LOCK TABLES `leady_aktywnosci` WRITE;
/*!40000 ALTER TABLE `leady_aktywnosci` DISABLE KEYS */;
INSERT INTO `leady_aktywnosci` VALUES
(1,1,1,'status_change','Status: Wygrana → Negocjacje','2025-12-28 20:07:53'),
(2,1,1,'status_change','Status: Negocjacje → Zamrożony','2025-12-28 20:07:56'),
(3,3,2,'converted','Skonwertowano na klienta ID=12','2026-02-16 22:18:35'),
(4,3,2,'status_change','Status: Nowy → Kontakt podjęty','2026-02-16 23:13:07'),
(5,3,2,'status_change','Status: Kontakt podjęty → Potrzeba potwierdzona','2026-02-16 23:13:10'),
(6,3,2,'status_change','Status: Potrzeba potwierdzona → Oferta wysłana','2026-02-16 23:13:12'),
(7,3,2,'offer_sent','Wysłano ofertę (zmiana statusu).','2026-02-16 23:13:12'),
(8,3,2,'status_change','Status: Oferta wysłana → Negocjacje','2026-02-16 23:13:16'),
(9,3,2,'status_change','Status: Negocjacje → Oferta wysłana','2026-02-17 14:13:58'),
(10,3,2,'offer_sent','Wysłano ofertę (zmiana statusu).','2026-02-17 14:13:58'),
(11,3,2,'status_change','Status: Oferta wysłana → Potrzeba potwierdzona','2026-02-17 14:14:00'),
(12,3,2,'status_change','Status: Potrzeba potwierdzona → Kontakt podjęty','2026-02-17 14:14:02'),
(13,3,2,'status_change','Status: Kontakt podjęty → Nowy','2026-02-17 14:14:04'),
(14,1,2,'transfer','Zmiana opiekuna: Nieprzypisany -> Jan Kowalski','2026-02-17 15:10:31'),
(15,5,2,'status_change','Status: Nowy → Kontakt podjęty','2026-02-17 21:54:21'),
(16,6,2,'status_change','Status: Nowy → Potrzeba potwierdzona','2026-02-17 21:57:34'),
(17,3,2,'status_change','Status: Nowy → Oferta wysłana','2026-02-17 21:57:37'),
(18,3,2,'offer_sent','Wysłano ofertę (zmiana statusu).','2026-02-17 21:57:37'),
(19,7,2,'status_change','Status: Nowy → Przegrana','2026-02-17 21:57:41'),
(20,5,2,'status_change','Status: Kontakt podjęty → Potrzeba potwierdzona','2026-02-18 10:24:03'),
(21,8,2,'converted','Skonwertowano na klienta ID=13','2026-02-18 11:37:11'),
(22,7,2,'converted','Skonwertowano na klienta ID=14','2026-02-18 12:37:57'),
(23,5,1,'status_change','Status: Potrzeba potwierdzona → Oferta wysłana','2026-02-18 23:11:28'),
(24,5,1,'offer_sent','Wysłano ofertę (zmiana statusu).','2026-02-18 23:11:28'),
(25,6,1,'status_change','Status: Potrzeba potwierdzona → Wygrana','2026-02-18 23:11:32'),
(26,5,2,'status_change','Status: Oferta wysłana → Kontakt','2026-02-25 21:37:28');
/*!40000 ALTER TABLE `leady_aktywnosci` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `mail_accounts`
--

DROP TABLE IF EXISTS `mail_accounts`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `mail_accounts` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `imap_host` varchar(255) NOT NULL,
  `imap_port` int(11) NOT NULL DEFAULT 993,
  `imap_encryption` enum('ssl','tls','none') NOT NULL DEFAULT 'ssl',
  `smtp_host` varchar(255) NOT NULL,
  `smtp_port` int(11) NOT NULL DEFAULT 587,
  `smtp_encryption` enum('ssl','tls','none') NOT NULL DEFAULT 'tls',
  `email_address` varchar(255) NOT NULL,
  `username` varchar(255) NOT NULL,
  `password_enc` text DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `last_sync_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `imap_mailbox` varchar(255) NOT NULL DEFAULT 'INBOX',
  `smtp_from_name` varchar(255) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_mail_accounts_user` (`user_id`),
  UNIQUE KEY `uniq_mail_user` (`user_id`)
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `mail_accounts`
--

LOCK TABLES `mail_accounts` WRITE;
/*!40000 ALTER TABLE `mail_accounts` DISABLE KEYS */;
INSERT INTO `mail_accounts` VALUES
(1,2,'hosting2588063.online.pro',993,'ssl','hosting2588063.online.pro',465,'ssl','test@adsmanager.com.pl','test@adsmanager.com.pl','9bwk1nMf0eP0kd0F3G+iAOojd40sgbMkaWclZvxI96M=',1,'2026-02-19 10:33:46','2026-02-16 17:10:12','INBOX','Jan Kowalski');
/*!40000 ALTER TABLE `mail_accounts` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `mail_attachments`
--

DROP TABLE IF EXISTS `mail_attachments`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `mail_attachments` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `mail_message_id` int(11) NOT NULL,
  `filename` varchar(255) NOT NULL,
  `stored_path` varchar(512) NOT NULL,
  `mime_type` varchar(100) DEFAULT NULL,
  `size_bytes` int(11) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `storage_path` varchar(500) NOT NULL DEFAULT '',
  PRIMARY KEY (`id`),
  KEY `idx_mail_attachments_message` (`mail_message_id`)
) ENGINE=InnoDB AUTO_INCREMENT=16 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `mail_attachments`
--

LOCK TABLES `mail_attachments` WRITE;
/*!40000 ALTER TABLE `mail_attachments` DISABLE KEYS */;
INSERT INTO `mail_attachments` VALUES
(1,2,'2026_01_31_Karta_Zg__oszeniowa_FUR-3.pdf','storage/mail_attachments/lead/3/2/att_6993862d173827.55305346_2026_01_31_Karta_Zg__oszeniowa_FUR-3.pdf','application/pdf',1209954,'2026-02-16 22:03:41','storage/mail_attachments/lead/3/2/att_6993862d173827.55305346_2026_01_31_Karta_Zg__oszeniowa_FUR-3.pdf'),
(2,3,'2026_01_31_Klauzula_informacyjna_do_FUR-3.pdf','storage/mail_attachments/lead/3/3/att_6993880878ecf2.93393512_2026_01_31_Klauzula_informacyjna_do_FUR-3.pdf','application/pdf',1214050,'2026-02-16 22:11:36','storage/mail_attachments/lead/3/3/att_6993880878ecf2.93393512_2026_01_31_Klauzula_informacyjna_do_FUR-3.pdf'),
(3,4,'2026_01_31_Karta_Zg__oszeniowa_FUR-3.pdf','storage/mail_attachments/lead/8/4/att_6994e64454a054.51272826_2026_01_31_Karta_Zg__oszeniowa_FUR-3.pdf','application/pdf',1209954,'2026-02-17 23:05:56','storage/mail_attachments/lead/8/4/att_6994e64454a054.51272826_2026_01_31_Karta_Zg__oszeniowa_FUR-3.pdf'),
(4,6,'1.png','storage/mail_attachments/unassigned/0/6/att_6995901296a787.72068930_1.png','application/octet-stream',3377,'2026-02-18 11:10:26','storage/mail_attachments/unassigned/0/6/att_6995901296a787.72068930_1.png'),
(5,6,'2.png','storage/mail_attachments/unassigned/0/6/att_69959012972d22.05020197_2.png','application/octet-stream',5168,'2026-02-18 11:10:26','storage/mail_attachments/unassigned/0/6/att_69959012972d22.05020197_2.png'),
(6,6,'3.png','storage/mail_attachments/unassigned/0/6/att_69959012976991.83667477_3.png','application/octet-stream',5350,'2026-02-18 11:10:26','storage/mail_attachments/unassigned/0/6/att_69959012976991.83667477_3.png'),
(7,6,'4.png','storage/mail_attachments/unassigned/0/6/att_6995901298aa83.48916279_4.png','application/octet-stream',4703,'2026-02-18 11:10:26','storage/mail_attachments/unassigned/0/6/att_6995901298aa83.48916279_4.png'),
(8,6,'5.png','storage/mail_attachments/unassigned/0/6/att_6995901298eab4.35149108_5.png','application/octet-stream',5471,'2026-02-18 11:10:26','storage/mail_attachments/unassigned/0/6/att_6995901298eab4.35149108_5.png'),
(9,6,'6.png','storage/mail_attachments/unassigned/0/6/att_69959012991d79.56070880_6.png','application/octet-stream',6923,'2026-02-18 11:10:26','storage/mail_attachments/unassigned/0/6/att_69959012991d79.56070880_6.png'),
(10,6,'7.png','storage/mail_attachments/unassigned/0/6/att_69959012996da0.73315052_7.png','application/octet-stream',6213,'2026-02-18 11:10:26','storage/mail_attachments/unassigned/0/6/att_69959012996da0.73315052_7.png'),
(11,6,'8.png','storage/mail_attachments/unassigned/0/6/att_6995901299b109.33979819_8.png','application/octet-stream',5005,'2026-02-18 11:10:26','storage/mail_attachments/unassigned/0/6/att_6995901299b109.33979819_8.png'),
(12,6,'9.png','storage/mail_attachments/unassigned/0/6/att_6995901299e185.85317549_9.png','application/octet-stream',3375,'2026-02-18 11:10:26','storage/mail_attachments/unassigned/0/6/att_6995901299e185.85317549_9.png'),
(13,7,'mediaplan_kampania_116.pdf','storage/mail_attachments/lead/9/7/att_6995ab07691e77.01624380_mediaplan_kampania_116.pdf','application/pdf',104736,'2026-02-18 13:05:27','storage/mail_attachments/lead/9/7/att_6995ab07691e77.01624380_mediaplan_kampania_116.pdf'),
(14,9,'mediaplan_kampania_116.pdf','storage/mail_attachments/lead/9/9/att_6995ac8de78309.89341996_mediaplan_kampania_116.pdf','application/pdf',104736,'2026-02-18 13:11:57','storage/mail_attachments/lead/9/9/att_6995ac8de78309.89341996_mediaplan_kampania_116.pdf'),
(15,11,'mediaplan_kampania_119.pdf','storage/mail_attachments/lead/10/11/att_6995b76d7ddce0.13338920_mediaplan_kampania_119.pdf','application/pdf',103457,'2026-02-18 13:58:21','storage/mail_attachments/lead/10/11/att_6995b76d7ddce0.13338920_mediaplan_kampania_119.pdf');
/*!40000 ALTER TABLE `mail_attachments` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `mail_messages`
--

DROP TABLE IF EXISTS `mail_messages`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `mail_messages` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `client_id` int(11) DEFAULT NULL,
  `lead_id` int(11) DEFAULT NULL,
  `campaign_id` int(11) DEFAULT NULL,
  `owner_user_id` int(11) NOT NULL,
  `direction` enum('in','out') NOT NULL,
  `from_email` varchar(255) DEFAULT NULL,
  `from_name` varchar(255) DEFAULT NULL,
  `to_email` text DEFAULT NULL,
  `cc_email` text DEFAULT NULL,
  `bcc_email` text DEFAULT NULL,
  `subject` varchar(255) DEFAULT NULL,
  `body_html` mediumtext DEFAULT NULL,
  `body_text` mediumtext DEFAULT NULL,
  `status` enum('SENT','ERROR','RECEIVED') NOT NULL DEFAULT 'SENT',
  `error_message` text DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `message_id` varchar(255) NOT NULL,
  `in_reply_to` varchar(255) DEFAULT NULL,
  `references_header` text DEFAULT NULL,
  `imap_uid` int(11) DEFAULT NULL,
  `imap_mailbox` varchar(255) DEFAULT NULL,
  `received_at` datetime DEFAULT NULL,
  `mail_account_id` int(11) NOT NULL DEFAULT 0,
  `thread_id` int(11) DEFAULT NULL,
  `to_emails` text DEFAULT NULL,
  `cc_emails` text DEFAULT NULL,
  `sent_at` datetime DEFAULT NULL,
  `has_attachments` tinyint(1) NOT NULL DEFAULT 0,
  `entity_type` enum('lead','client') DEFAULT NULL,
  `entity_id` int(11) DEFAULT NULL,
  `created_by_user_id` int(11) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_mail_messages_account_message` (`mail_account_id`,`message_id`),
  KEY `idx_mail_messages_owner_created` (`owner_user_id`,`created_at`),
  KEY `idx_mail_messages_client_created` (`client_id`,`created_at`),
  KEY `idx_mail_messages_campaign_created` (`campaign_id`,`created_at`),
  KEY `idx_mail_messages_owner_imap_uid` (`owner_user_id`,`imap_uid`,`imap_mailbox`),
  KEY `idx_mail_messages_message_id` (`message_id`),
  KEY `idx_mail_messages_entity_created` (`entity_type`,`entity_id`,`created_at`),
  KEY `idx_mail_messages_thread_created` (`thread_id`,`created_at`)
) ENGINE=InnoDB AUTO_INCREMENT=13 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `mail_messages`
--

LOCK TABLES `mail_messages` WRITE;
/*!40000 ALTER TABLE `mail_messages` DISABLE KEYS */;
INSERT INTO `mail_messages` VALUES
(1,NULL,3,NULL,2,'out','test@adsmanager.com.pl','Jan Kowalski','ceo@ohmg.pl',NULL,NULL,'oferta na kaktusy','Dzień dobry, <br />\r\ntestowa wiadomość','Dzień dobry, \r\ntestowa wiadomość','ERROR','PHPMailer is not available.','2026-02-16 21:56:39','<crm-6c6987f659f7e49f29bf@adsmanager.com.pl>',NULL,NULL,NULL,NULL,NULL,1,1,'ceo@ohmg.pl',NULL,'2026-02-16 21:56:39',0,'lead',3,2),
(2,NULL,3,NULL,2,'out','test@adsmanager.com.pl','Jan Kowalski','ceo@ohmg.pl',NULL,NULL,'oferta na spot','dzień dobry, przesyłam naszą ofertę','dzień dobry, przesyłam naszą ofertę','ERROR','PHPMailer is not available.','2026-02-16 22:03:41','<crm-db44e319585f09f34ff4@adsmanager.com.pl>',NULL,NULL,NULL,NULL,NULL,1,2,'ceo@ohmg.pl',NULL,'2026-02-16 22:03:41',1,'lead',3,2),
(3,NULL,3,NULL,2,'out','test@adsmanager.com.pl','Jan Kowalski','ceo@ohmg.pl',NULL,NULL,'ewdwee','testowa wiadomosć','testowa wiadomosć','SENT',NULL,'2026-02-16 22:11:36','<crm-a9a14240da6fc127851e@adsmanager.com.pl>',NULL,NULL,NULL,NULL,NULL,1,3,'ceo@ohmg.pl',NULL,'2026-02-16 22:11:36',1,'lead',3,2),
(4,NULL,8,NULL,2,'out','test@adsmanager.com.pl','Jan Kowalski','ceo@ohmg.pl',NULL,NULL,'oferta na 2026','dzień dobry,<br />\r\nprzesyłam ofertę na 2026 rok','dzień dobry,\r\nprzesyłam ofertę na 2026 rok','SENT',NULL,'2026-02-17 23:05:56','<crm-254d186d2d3cecd09e62@adsmanager.com.pl>',NULL,NULL,NULL,NULL,NULL,1,4,'ceo@ohmg.pl',NULL,'2026-02-17 23:05:56',1,'lead',8,2),
(5,12,NULL,NULL,2,'in','ceo@ohmg.pl','Karol Pufal CEO','Jan Kowalski <test@adsmanager.com.pl>','','','Re: oferta na 2026','<!DOCTYPE html>\r\n<html>\r\n  <head>\r\n    <meta http-equiv=\"Content-Type\" content=\"text/html; charset=UTF-8\">\r\n  </head>\r\n  <body>\r\n    <p>dziekuję za ofertę </p>\r\n    <div class=\"moz-cite-prefix\">W dniu 17.02.2026 o 23:05, Jan Kowalski\r\n      pisze:<br>\r\n    </div>\r\n    <blockquote type=\"cite\"\r\n      cite=\"mid:crm-254d186d2d3cecd09e62@adsmanager.com.pl\">\r\n      <meta http-equiv=\"content-type\" content=\"text/html; charset=UTF-8\">\r\n      dzień dobry,<br>\r\n      przesyłam ofertę na 2026 rok\r\n    </blockquote>\r\n    <div class=\"moz-signature\">-- <br>\r\n      <title>Email Signature</title>\r\n      <meta content=\"text/html; charset=UTF-8\" http-equiv=\"Content-Type\">\r\n      <table\r\nstyle=\"width: 525px; font-size: 11pt; font-family: Arial, sans-serif; background: transparent !important;\"\r\n        cellpadding=\"0\" cellspacing=\"0\">\r\n        <tbody>\r\n          <tr>\r\n            <td width=\"125\"\r\nstyle=\"font-size: 10pt; font-family: Arial, sans-serif; border-right: 1px solid; border-right-color: #1c127e; width: 125px; padding-right: 10px; vertical-align: top;\"\r\n              valign=\"top\" rowspan=\"6\"> <a href=\"https://www.ohmg.pl\"\r\n                target=\"_blank\"><img border=\"0\" width=\"105\"\r\n                  style=\"width:105px; height:auto; border:0;\"\r\nsrc=\"https://server480824.nazwa.pl/ohmg/images/logo/OHMG_logo_glob.jpg\"></a>\r\n            </td>\r\n            <td style=\"padding-left:10px\">\r\n              <table cellpadding=\"0\" cellspacing=\"0\"\r\n                style=\"background: transparent !important;\">\r\n                <tbody>\r\n                  <tr>\r\n                    <td\r\nstyle=\"font-size: 10pt; color:#0079ac; font-family: Arial, sans-serif; width: 400px; padding-bottom: 5px; padding-left: 10px; vertical-align: top;\"\r\n                      valign=\"top\"><strong><span\r\nstyle=\"font-size: 11pt; font-family: Arial, sans-serif; color:#1c127e;\">Karol\r\n                          Pufal</span></strong><strong\r\nstyle=\"font-family: Arial, sans-serif; font-size:11pt; color:#000000;\"><span>\r\n                          | </span>CEO</strong></td>\r\n                  </tr>\r\n                  <tr>\r\n                    <td\r\nstyle=\"font-size: 10pt; color:#444444; font-family: Arial, sans-serif; padding-bottom: 5px; padding-top: 5px; padding-left: 10px; vertical-align: top; line-height:17px;\"\r\n                      valign=\"top\"> <span> <span\r\n                          style=\"color: #1c127e;\"><strong>a: </strong></span>\r\n                      </span> <span> <span\r\nstyle=\"font-family: Arial, sans-serif; font-size:10pt; color:#000000;\">OHMG\r\n                          Sp. z o.o.</span> </span> <span> <span\r\nstyle=\"font-size: 10pt; font-family: Arial, sans-serif; color: #000000;\"><span>\r\n                            | </span>Grobelno 8<span></span></span> <span\r\nstyle=\"font-size: 10pt; font-family: Arial, sans-serif; color: #000000;\"><span>\r\n                            | </span>82200 Malbork</span> </span> <span><br>\r\n                      </span> <span><span style=\"color: #1c127e;\"><strong>e:</strong></span><span\r\nstyle=\"font-size: 10pt; font-family: Arial, sans-serif; color:#000000;\">\r\n                          <a class=\"moz-txt-link-abbreviated\" href=\"mailto:ceo@ohmg.pl\">ceo@ohmg.pl</a></span></span> <span><span> | </span><span\r\n                          style=\"color: #1c127e;\"><strong>w:</strong></span><a\r\n                          href=\"http://ohmg.pl\" target=\"_blank\"\r\n                          rel=\"noopener\" style=\"text-decoration:none;\"><span\r\nstyle=\"font-size: 10pt; font-family: Arial, sans-serif; color:#000000;\">\r\n                            ohmg.pl</span></a></span> <span><br>\r\n                      </span> <span><span style=\"color: #1c127e;\"><strong>m:</strong></span><span\r\nstyle=\"font-size: 10pt; font-family: Arial, sans-serif; color:#000000;\">\r\n                          +48 881 659 971</span></span><span><span> | </span><span\r\n                          style=\"color: #1c127e;\"><strong>p:</strong></span><span\r\nstyle=\"font-size: 10pt; font-family: Arial, sans-serif; color:#000000;\">\r\n                          22 652 52 52</span></span></td>\r\n                  </tr>\r\n                </tbody>\r\n              </table>\r\n            </td>\r\n          </tr>\r\n        </tbody>\r\n      </table>\r\n    </div>\r\n  </body>\r\n</html>','dziekuję za ofertę\r\n\r\nW dniu 17.02.2026 o 23:05, Jan Kowalski pisze:\r\n> dzień dobry,\r\n> przesyłam ofertę na 2026 rok \r\n-- \r\nEmail Signature\r\n<https://www.ohmg.pl> 	\r\n*Karol Pufal**| CEO*\r\n*a: * OHMG Sp. z o.o. | Grobelno 8| 82200 Malbork\r\n*e:*ceo@ohmg.pl | *w:*ohmg.pl <http://ohmg.pl>\r\n*m:*+48 881 659 971| *p:*22 652 52 52','RECEIVED',NULL,'2026-02-18 11:10:25','<4425674d-4886-4190-9c2f-3a6e8170ec10@ohmg.pl>','<crm-254d186d2d3cecd09e62@adsmanager.com.pl>','<crm-254d186d2d3cecd09e62@adsmanager.com.pl>',2,'INBOX','2026-02-17 23:06:16',1,5,'test@adsmanager.com.pl','',NULL,0,'client',12,NULL),
(6,NULL,NULL,NULL,2,'in','info@az.pl','','','','','Pierwsze kroki z pocztą w AZ','<HTML xmlns=\"http://www.w3.org/1999/xhtml\"><HEAD><TITLE>Pierwsze kroki z pocztą w AZ</TITLE>\r\n<META content=\"text/html; charset=utf-8\" http-equiv=Content-Type>\r\n<META name=viewport content=\"width=device-width, initial-scale=1\">\r\n<META name=format-detection content=telephone=no><!----><LINK rel=stylesheet type=text/css href=\"https://fonts.googleapis.com/css?family=Open+Sans:300,400,600,700&subset=latin,latin-ext\"><LINK rel=stylesheet type=text/css href=\"https://fonts.googleapis.com/css?family=Overpass:600&display=swap&subset=latin-ext\">\r\n<STYLE type=text/css>\r\n    @media only screen and (max-width:480px) {\r\n        @-ms-viewport { width:320px; }\r\n        @viewport { width:320px; }\r\n    }\r\n	</STYLE>\r\n\r\n<STYLE type=text/css>\r\n    html,  body {\r\n		margin: 0 !important;\r\n		padding: 0 !important;\r\n		height: 100% !important;\r\n		width: 100% !important;\r\n		font-family: Open Sans, Helvetica, Arial, sans-serif;\r\n		-ms-text-size-adjust: 100%;\r\n		-webkit-text-size-adjust: 100%;\r\n	}\r\n	body,td,th {\r\n		font-family: Open Sans, Helvetica, Arial, sans-serif;\r\n	}\r\n    table,  td {\r\n		mso-table-lspace: 0pt !important;\r\n		mso-table-rspace: 0pt !important;\r\n	}\r\n	p {\r\n		padding:0;\r\n		margin:0 0 20px;\r\n		font-size: 16px;\r\n		line-height: 1.4em; \r\n		font-weight:400;\r\n	}\r\n	a {\r\n		color:#0D9DCC;\r\n	}\r\n	\r\n	.footFeature p {\r\n		margin:0;\r\n	}\r\n\r\n    table {\r\n		border-spacing: 0 !important;\r\n		border-collapse: collapse !important;\r\n		table-layout: fixed !important;\r\n		margin: 0 auto !important;\r\n	}\r\n    table table table {\r\n		table-layout: auto;\r\n	}\r\n    img {\r\n		-ms-interpolation-mode:bicubic;\r\n	}\r\n	/* What it does: Overrides styles added when Yahoo\'s auto-senses a link. */\r\n    .yshortcuts a {\r\n		border-bottom: none !important;\r\n	}\r\n	/* What it does: A work-around for iOS meddling in triggered links. */\r\n    .mobile-link--footer a,  a[x-apple-data-detectors] {\r\n		color:inherit !important;\r\n		text-decoration: underline !important;\r\n	}\r\n    \r\n	/* Media Queries */\r\n@media screen and (max-width: 660px) {\r\n	.theButton {\r\n	width:100%;\r\n	}\r\n}\r\n@media screen and (max-width: 480px) {\r\n	\r\n	.footFeature {\r\n	width:100%;\r\n	}\r\n    .mobile-hidden {\r\n		display: none;\r\n	}\r\n    p.company-info {\r\n		padding-left: 15px !important;\r\n	}\r\n	\r\n}</STYLE>\r\n</HEAD>\r\n<BODY style=\"MARGIN: 0px\" bgColor=#fbfbfb width=\"100%\">\r\n<TABLE style=\"BORDER-COLLAPSE: collapse; PADDING-BOTTOM: 10px; PADDING-TOP: 10px; PADDING-LEFT: 0px; MARGIN: 0px; PADDING-RIGHT: 0px\" height=\"100%\" cellSpacing=0 cellPadding=0 width=\"100%\" bgColor=#fbfbfb border=0>\r\n<TBODY>\r\n<TR>\r\n<TD style=\"PADDING-BOTTOM: 20px; PADDING-TOP: 10px; PADDING-LEFT: 0px; PADDING-RIGHT: 0px\" vAlign=top>\r\n<CENTER style=\"WIDTH: 100%\"><!-- Visually Hidden Preheader Text : BEGIN -->\r\n<DIV style=\"FONT-SIZE: 1px; OVERFLOW: hidden; MAX-WIDTH: 0px; FONT-FAMILY: sans-serif; DISPLAY: none; LINE-HEIGHT: 1px; MAX-HEIGHT: 0px; opacity: 0; mso-hide: all\">&nbsp;</DIV><!-- Visually Hidden Preheader Text : END -->\r\n<DIV style=\"MAX-WIDTH: 600px\">\r\n<TABLE cellSpacing=0 cellPadding=0 width=600 align=center border=0>\r\n<TBODY>\r\n<TR>\r\n<TD><!-- space -->\r\n<TABLE class=spacetab style=\"MARGIN: 0px auto\" cellSpacing=0 cellPadding=0 width=\"100%\" border=0>\r\n<TBODY>\r\n<TR>\r\n<TD style=\"HEIGHT: 20px; LINE-HEIGHT: 20px\" height=20 width=\"100%\">&nbsp;</TD></TR></TBODY></TABLE><!-- space end --><!-- Email color strap : BEGIN -->\r\n<TABLE style=\"MAX-WIDTH: 600px; HEIGHT: 60px; BACKGROUND: #003d8f; MIN-WIDTH: 300px\" height=60 cellSpacing=0 cellPadding=0 width=\"100%\" align=center border=0>\r\n<TBODY>\r\n<TR>\r\n<TD style=\"FONT-SIZE: 12px; HEIGHT: 60px; BACKGROUND: #003d8f; COLOR: #ffffff; TEXT-ALIGN: left; PADDING-LEFT: 20px; LINE-HEIGHT: 16px\" align=center><A style=\"BORDER-TOP: medium none; BORDER-RIGHT: medium none; BORDER-BOTTOM: medium none; BORDER-LEFT: medium none\" href=\"https://az.pl/\" target=_blank><IMG style=\"MAX-WIDTH: 69px; HEIGHT: 32px !important; WIDTH: 69px !important; MIN-WIDTH: 69px; MIN-HEIGHT: 32px; MAX-HEIGHT: 32px\" alt=AZ src=\"cid:4072151473-1\" width=69 height=32></A></TD>\r\n<TD style=\"FONT-SIZE: 12px; HEIGHT: 60px; BACKGROUND: #003d8f; COLOR: #ffffff; TEXT-ALIGN: left; PADDING-LEFT: 45px; LINE-HEIGHT: 16px; PADDING-RIGHT: 45px\">&nbsp;</TD>\r\n<TD style=\"FONT-SIZE: 12px; HEIGHT: 60px; BACKGROUND: #003d8f; COLOR: #ffffff; TEXT-ALIGN: right; LINE-HEIGHT: 16px; PADDING-RIGHT: 20px\"><A style=\"TEXT-DECORATION: none; COLOR: #ffffff\" href=\"https://cp.az.pl/\" target=_blank>Logowanie</A></TD></TR></TBODY></TABLE><!-- Email color strap: END --><!-- Email Body : BEGIN -->\r\n<TABLE style=\"MAX-WIDTH: 600px\" cellSpacing=0 cellPadding=0 width=\"100%\" align=center bgColor=#ffffff border=0><!-- 1 Column Welcome Text : BEGIN -->\r\n<TBODY>\r\n<TR>\r\n<TD>\r\n<TABLE cellSpacing=0 cellPadding=0 width=\"100%\" align=center border=0>\r\n<TBODY>\r\n<TR>\r\n<TD style=\"COLOR: #001b41; PADDING-BOTTOM: 40px; TEXT-ALIGN: left; PADDING-TOP: 40px; PADDING-LEFT: 60px; PADDING-RIGHT: 60px; mso-height-rule: exactly\" align=center>\r\n<P style=\"MARGIN: 0px\"><SPAN style=\"FONT-SIZE: 28px; FONT-FAMILY: Overpass,Open Sans, Helvetica, Arial, Sans-serif; FONT-WEIGHT: 600; COLOR: #001b41; LINE-HEIGHT: 1.3em\">Witamy w poczcie AZ!</SPAN></P></TD></TR></TBODY></TABLE></TD></TR><!-- 1 Column Welcome Text : END --><!-- 1 Column Main Text : BEGIN -->\r\n<TR>\r\n<TD>\r\n<TABLE cellSpacing=0 cellPadding=0 width=\"100%\" align=center border=0>\r\n<TBODY>\r\n<TR>\r\n<TD style=\"FONT-SIZE: 16px; COLOR: #465a75; PADDING-BOTTOM: 0px; TEXT-ALIGN: left; PADDING-TOP: 0px; PADDING-LEFT: 60px; LINE-HEIGHT: 1.4em; PADDING-RIGHT: 60px; mso-height-rule: exactly\" align=center>\r\n<P>Cześć</P>\r\n<P>Twoja nowa skrzynka ułatwi Ci kontakt z kontrahentami i wysyłanie maili z&nbsp;dowolnego urządzenia – gdziekolwiek będziesz.</P>\r\n<P style=\"MARGIN: 0px\">Przed stworzeniem pierwszej wiadomości poznaj wskazówki, które ułatwią Ci&nbsp;korzystanie ze skrzynki.</P><!-- space -->\r\n<TABLE class=spacetab style=\"MARGIN: 0px auto\" cellSpacing=0 cellPadding=0 width=\"100%\" border=0>\r\n<TBODY>\r\n<TR>\r\n<TD style=\"HEIGHT: 40px; LINE-HEIGHT: 40px\" height=40 width=\"100%\">&nbsp;</TD></TR></TBODY></TABLE><!-- space end --><!-- LIST-table ICON : BEGIN -->\r\n<TABLE style=\"MARGIN: 0px\" cellSpacing=0 cellPadding=0 width=\"100%\" border=0>\r\n<TBODY>\r\n<TR>\r\n<TD style=\"PADDING-BOTTOM: 0px; PADDING-TOP: 0px; PADDING-LEFT: 0px; PADDING-RIGHT: 0px\">\r\n<TABLE style=\"MARGIN: 0px\" cellSpacing=0 cellPadding=0 align=left border=0>\r\n<TBODY>\r\n<TR>\r\n<TD class=mobile-hidden style=\"FONT-SIZE: 16px; WIDTH: 70px; PADDING-BOTTOM: 0px; TEXT-ALIGN: left; PADDING-TOP: 0px; PADDING-LEFT: 0px; LINE-HEIGHT: 1.4em; PADDING-RIGHT: 0px\" vAlign=top width=70><SPAN><IMG style=\"BORDER-TOP: medium none; HEIGHT: 50px; BORDER-RIGHT: medium none; WIDTH: 70px; BORDER-BOTTOM: medium none; BORDER-LEFT: medium none; MARGIN: 0px\" alt=\"\" src=\"cid:1240939361-2\" width=70 height=50></SPAN> </TD>\r\n<TD style=\"FONT-SIZE: 16px; FONT-WEIGHT: 400; COLOR: #465a75; PADDING-BOTTOM: 20px; TEXT-ALIGN: left; PADDING-TOP: 0px; PADDING-LEFT: 0px; LINE-HEIGHT: 1.4em; PADDING-RIGHT: 0px; mso-height-rule: exactly\" vAlign=top>\r\n<P style=\"FONT-WEIGHT: 600; MARGIN: 0px\">Ustaw nazwę, dane kontaktowe i adres e-mail</P>\r\n<P>Będzie świetnie, jeśli odbiorca Twoich wiadomości od razu dowie się, od kogo otrzymał e-mail. A najlepiej, jeśli będzie mógł szybko zapisać nadawcę w swojej książce adresowej. Żeby tak było, ustaw swoje imię, nazwisko oraz dodatkowe informacje w sekcji \"Moje dane kontaktowe\".</P><!-- LIST-table-link : BEGIN -->\r\n<TABLE style=\"MARGIN: 0px\" cellSpacing=0 cellPadding=0 width=\"100%\" border=0>\r\n<TBODY>\r\n<TR>\r\n<TD style=\"PADDING-BOTTOM: 0px; PADDING-TOP: 0px; PADDING-LEFT: 0px; PADDING-RIGHT: 0px\">\r\n<TABLE style=\"MARGIN: 0px\" cellSpacing=0 cellPadding=0 align=left border=0>\r\n<TBODY>\r\n<TR>\r\n<TD style=\"FONT-SIZE: 16px; WIDTH: 25px; PADDING-BOTTOM: 0px; TEXT-ALIGN: left; PADDING-TOP: 0px; PADDING-LEFT: 0px; LINE-HEIGHT: 1.5em; PADDING-RIGHT: 0px\" vAlign=top width=25><A style=\"TEXT-DECORATION: none; COLOR: #0d9dcc\" href=\"https://pomoc.az.pl/kategorie/jak-skonfigurowac-i-do-czego-sluzy-profil-poczty-e-mail/\" target=_blank>→</A> </TD>\r\n<TD style=\"FONT-SIZE: 16px; FONT-WEIGHT: 400; COLOR: #0d9dcc; PADDING-BOTTOM: 0px; TEXT-ALIGN: left; PADDING-TOP: 0px; PADDING-LEFT: 0px; LINE-HEIGHT: 1.4em; PADDING-RIGHT: 0px; mso-height-rule: exactly\" vAlign=top><A style=\"TEXT-DECORATION: none; COLOR: #0d9dcc\" href=\"https://pomoc.az.pl/kategorie/jak-skonfigurowac-i-do-czego-sluzy-profil-poczty-e-mail/\" target=_blank>Przejdź&nbsp;do pomocy</A> </TD></TR></TBODY></TABLE></TD></TR></TBODY></TABLE><!-- LIST-table-link : END --></TD></TR>\r\n<TR>\r\n<TD class=mobile-hidden style=\"FONT-SIZE: 16px; WIDTH: 70px; PADDING-BOTTOM: 0px; TEXT-ALIGN: left; PADDING-TOP: 0px; PADDING-LEFT: 0px; LINE-HEIGHT: 1.4em; PADDING-RIGHT: 0px\" vAlign=top width=70><SPAN><IMG style=\"BORDER-TOP: medium none; HEIGHT: 50px; BORDER-RIGHT: medium none; WIDTH: 70px; BORDER-BOTTOM: medium none; BORDER-LEFT: medium none; MARGIN: 0px\" alt=\"\" src=\"cid:1569659968-3\" width=70 height=50></SPAN> </TD>\r\n<TD style=\"FONT-SIZE: 16px; FONT-WEIGHT: 400; COLOR: #465a75; PADDING-BOTTOM: 20px; TEXT-ALIGN: left; PADDING-TOP: 0px; PADDING-LEFT: 0px; LINE-HEIGHT: 1.4em; PADDING-RIGHT: 0px; mso-height-rule: exactly\" vAlign=top>\r\n<P style=\"FONT-WEIGHT: 600; MARGIN: 0px\">Skonfiguruj podpis</P>\r\n<P>Pod każdą wiadomością możesz automatycznie podpisać się jako nadawca. Dodaj grafiki i stwórz atrakcyjną sygnaturę, która będzie Twoją wizytówką. Przejdź do naszej pomocy i sprawdź, jak to zrobić.</P><!-- LIST-table-link : BEGIN -->\r\n<TABLE style=\"MARGIN: 0px\" cellSpacing=0 cellPadding=0 width=\"100%\" border=0>\r\n<TBODY>\r\n<TR>\r\n<TD style=\"PADDING-BOTTOM: 0px; PADDING-TOP: 0px; PADDING-LEFT: 0px; PADDING-RIGHT: 0px\">\r\n<TABLE style=\"MARGIN: 0px\" cellSpacing=0 cellPadding=0 align=left border=0>\r\n<TBODY>\r\n<TR>\r\n<TD style=\"FONT-SIZE: 16px; WIDTH: 25px; PADDING-BOTTOM: 0px; TEXT-ALIGN: left; PADDING-TOP: 0px; PADDING-LEFT: 0px; LINE-HEIGHT: 1.5em; PADDING-RIGHT: 0px\" vAlign=top width=25><A style=\"TEXT-DECORATION: none; COLOR: #0d9dcc\" href=\"https://pomoc.az.pl/kategorie/jak-skonfigurowac-automatyczny-podpis-do-wiadomosci-e-mail/\" target=_blank>→</A> </TD>\r\n<TD style=\"FONT-SIZE: 16px; FONT-WEIGHT: 400; COLOR: #0d9dcc; PADDING-BOTTOM: 0px; TEXT-ALIGN: left; PADDING-TOP: 0px; PADDING-LEFT: 0px; LINE-HEIGHT: 1.4em; PADDING-RIGHT: 0px; mso-height-rule: exactly\" vAlign=top><A style=\"TEXT-DECORATION: none; COLOR: #0d9dcc\" href=\"https://pomoc.az.pl/kategorie/jak-skonfigurowac-automatyczny-podpis-do-wiadomosci-e-mail/\" target=_blank>Przejdź&nbsp;do pomocy</A> </TD></TR></TBODY></TABLE></TD></TR></TBODY></TABLE><!-- LIST-table-link : END --></TD></TR>\r\n<TR>\r\n<TD class=mobile-hidden style=\"FONT-SIZE: 16px; WIDTH: 70px; PADDING-BOTTOM: 0px; TEXT-ALIGN: left; PADDING-TOP: 0px; PADDING-LEFT: 0px; LINE-HEIGHT: 1.4em; PADDING-RIGHT: 0px\" vAlign=top width=70><SPAN><IMG style=\"BORDER-TOP: medium none; HEIGHT: 50px; BORDER-RIGHT: medium none; WIDTH: 70px; BORDER-BOTTOM: medium none; BORDER-LEFT: medium none; MARGIN: 0px\" alt=\"\" src=\"cid:2768021570-4\" width=70 height=50></SPAN> </TD>\r\n<TD style=\"FONT-SIZE: 16px; FONT-WEIGHT: 400; COLOR: #465a75; PADDING-BOTTOM: 20px; TEXT-ALIGN: left; PADDING-TOP: 0px; PADDING-LEFT: 0px; LINE-HEIGHT: 1.4em; PADDING-RIGHT: 0px; mso-height-rule: exactly\" vAlign=top>\r\n<P style=\"FONT-WEIGHT: 600; MARGIN: 0px\">Wszystkie konta pocztowe w jednym miejscu</P>\r\n<P>Dzięki poczcie az.pl możesz także wygodnie pobierać wiadomości z kont zewnętrznych. Nie musisz logować się do kilku skrzynek i przełączać między oknami. W jednym miejscu masz pocztę z wielu kont utworzonych w az.pl lub u innych dostawców.</P><!-- LIST-table-link : BEGIN -->\r\n<TABLE style=\"MARGIN: 0px\" cellSpacing=0 cellPadding=0 width=\"100%\" border=0>\r\n<TBODY>\r\n<TR>\r\n<TD style=\"PADDING-BOTTOM: 0px; PADDING-TOP: 0px; PADDING-LEFT: 0px; PADDING-RIGHT: 0px\">\r\n<TABLE style=\"MARGIN: 0px\" cellSpacing=0 cellPadding=0 align=left border=0>\r\n<TBODY>\r\n<TR>\r\n<TD style=\"FONT-SIZE: 16px; WIDTH: 25px; PADDING-BOTTOM: 0px; TEXT-ALIGN: left; PADDING-TOP: 0px; PADDING-LEFT: 0px; LINE-HEIGHT: 1.5em; PADDING-RIGHT: 0px\" vAlign=top width=25><A style=\"TEXT-DECORATION: none; COLOR: #0d9dcc\" href=\"https://pomoc.az.pl/kategorie/jak-wlaczyc-obsluge-zewnetrznego-konta-e-mail-np-gmail-na-koncie-pocztowym-w-az-pl/\" target=_blank>→</A> </TD>\r\n<TD style=\"FONT-SIZE: 16px; FONT-WEIGHT: 400; COLOR: #0d9dcc; PADDING-BOTTOM: 0px; TEXT-ALIGN: left; PADDING-TOP: 0px; PADDING-LEFT: 0px; LINE-HEIGHT: 1.4em; PADDING-RIGHT: 0px; mso-height-rule: exactly\" vAlign=top><A style=\"TEXT-DECORATION: none; COLOR: #0d9dcc\" href=\"https://pomoc.az.pl/kategorie/jak-wlaczyc-obsluge-zewnetrznego-konta-e-mail-np-gmail-na-koncie-pocztowym-w-az-pl/\" target=_blank>Przejdź&nbsp;do pomocy</A> </TD></TR></TBODY></TABLE></TD></TR></TBODY></TABLE><!-- LIST-table-link : END --></TD></TR>\r\n<TR>\r\n<TD class=mobile-hidden style=\"FONT-SIZE: 16px; WIDTH: 70px; PADDING-BOTTOM: 0px; TEXT-ALIGN: left; PADDING-TOP: 0px; PADDING-LEFT: 0px; LINE-HEIGHT: 1.4em; PADDING-RIGHT: 0px\" vAlign=top width=70><SPAN><IMG style=\"BORDER-TOP: medium none; HEIGHT: 50px; BORDER-RIGHT: medium none; WIDTH: 70px; BORDER-BOTTOM: medium none; BORDER-LEFT: medium none; MARGIN: 0px\" alt=\"\" src=\"cid:5824777474-5\" width=70 height=50></SPAN> </TD>\r\n<TD style=\"FONT-SIZE: 16px; FONT-WEIGHT: 400; COLOR: #465a75; PADDING-BOTTOM: 20px; TEXT-ALIGN: left; PADDING-TOP: 0px; PADDING-LEFT: 0px; LINE-HEIGHT: 1.4em; PADDING-RIGHT: 0px; mso-height-rule: exactly\" vAlign=top>\r\n<P style=\"FONT-WEIGHT: 600; MARGIN: 0px\">Autoresponder - poinformuj o nieobecności</P>\r\n<P>Pokaż, że zawsze można liczyć na Twoją odpowiedź! Przed wyjazdem na urlop albo w delegację ustaw automatyczną wiadomość, dzięki której poinformujesz odbiorców o swojej nieobecności. W poczcie az.pl ustawisz nawet konkretne godziny, w których informacja o nieobecności będzie wysyłana.</P><!-- LIST-table-link : BEGIN -->\r\n<TABLE style=\"MARGIN: 0px\" cellSpacing=0 cellPadding=0 width=\"100%\" border=0>\r\n<TBODY>\r\n<TR>\r\n<TD style=\"PADDING-BOTTOM: 0px; PADDING-TOP: 0px; PADDING-LEFT: 0px; PADDING-RIGHT: 0px\">\r\n<TABLE style=\"MARGIN: 0px\" cellSpacing=0 cellPadding=0 align=left border=0>\r\n<TBODY>\r\n<TR>\r\n<TD style=\"FONT-SIZE: 16px; WIDTH: 25px; PADDING-BOTTOM: 0px; TEXT-ALIGN: left; PADDING-TOP: 0px; PADDING-LEFT: 0px; LINE-HEIGHT: 1.5em; PADDING-RIGHT: 0px\" vAlign=top width=25><A style=\"TEXT-DECORATION: none; COLOR: #0d9dcc\" href=\"https://pomoc.az.pl/kategorie/jak-wlaczyc-autoresponder-na-skrzynce-e-mail/\" target=_blank>→</A> </TD>\r\n<TD style=\"FONT-SIZE: 16px; FONT-WEIGHT: 400; COLOR: #0d9dcc; PADDING-BOTTOM: 0px; TEXT-ALIGN: left; PADDING-TOP: 0px; PADDING-LEFT: 0px; LINE-HEIGHT: 1.4em; PADDING-RIGHT: 0px; mso-height-rule: exactly\" vAlign=top><A style=\"TEXT-DECORATION: none; COLOR: #0d9dcc\" href=\"https://pomoc.az.pl/kategorie/jak-wlaczyc-autoresponder-na-skrzynce-e-mail/\" target=_blank>Przejdź&nbsp;do pomocy</A> </TD></TR></TBODY></TABLE></TD></TR></TBODY></TABLE><!-- LIST-table-link : END --></TD></TR>\r\n<TR>\r\n<TD class=mobile-hidden style=\"FONT-SIZE: 16px; WIDTH: 70px; PADDING-BOTTOM: 0px; TEXT-ALIGN: left; PADDING-TOP: 0px; PADDING-LEFT: 0px; LINE-HEIGHT: 1.4em; PADDING-RIGHT: 0px\" vAlign=top width=70><SPAN><IMG style=\"BORDER-TOP: medium none; HEIGHT: 50px; BORDER-RIGHT: medium none; WIDTH: 70px; BORDER-BOTTOM: medium none; BORDER-LEFT: medium none; MARGIN: 0px\" alt=\"\" src=\"cid:1934469978-6\" width=70 height=50></SPAN> </TD>\r\n<TD style=\"FONT-SIZE: 16px; FONT-WEIGHT: 400; COLOR: #465a75; PADDING-BOTTOM: 20px; TEXT-ALIGN: left; PADDING-TOP: 0px; PADDING-LEFT: 0px; LINE-HEIGHT: 1.4em; PADDING-RIGHT: 0px; mso-height-rule: exactly\" vAlign=top>\r\n<P style=\"FONT-WEIGHT: 600; MARGIN: 0px\">Bardzo dużo maili? Sprawdź reguły wiadomości i foldery</P>\r\n<P>Żeby zarządzać dużą liczbą wiadomości, możesz tworzyć foldery, do których według określonych reguł będziesz kierować wiadomości od wybranych odbiorców, np. zawierające określone frazy w temacie.</P><!-- LIST-table-link : BEGIN -->\r\n<TABLE style=\"MARGIN: 0px\" cellSpacing=0 cellPadding=0 width=\"100%\" border=0>\r\n<TBODY>\r\n<TR>\r\n<TD style=\"PADDING-BOTTOM: 0px; PADDING-TOP: 0px; PADDING-LEFT: 0px; PADDING-RIGHT: 0px\">\r\n<TABLE style=\"MARGIN: 0px\" cellSpacing=0 cellPadding=0 align=left border=0>\r\n<TBODY>\r\n<TR>\r\n<TD style=\"FONT-SIZE: 16px; WIDTH: 25px; PADDING-BOTTOM: 0px; TEXT-ALIGN: left; PADDING-TOP: 0px; PADDING-LEFT: 0px; LINE-HEIGHT: 1.5em; PADDING-RIGHT: 0px\" vAlign=top width=25><A style=\"TEXT-DECORATION: none; COLOR: #0d9dcc\" href=\"https://pomoc.az.pl/kategorie/jak-tworzyc-reguly-wiadomosci-w-poczcie-az-pl/\" target=_blank>→</A> </TD>\r\n<TD style=\"FONT-SIZE: 16px; FONT-WEIGHT: 400; COLOR: #0d9dcc; PADDING-BOTTOM: 0px; TEXT-ALIGN: left; PADDING-TOP: 0px; PADDING-LEFT: 0px; LINE-HEIGHT: 1.4em; PADDING-RIGHT: 0px; mso-height-rule: exactly\" vAlign=top><A style=\"TEXT-DECORATION: none; COLOR: #0d9dcc\" href=\"https://pomoc.az.pl/kategorie/jak-tworzyc-reguly-wiadomosci-w-poczcie-az-pl/\" target=_blank>Przejdź&nbsp;do pomocy</A> </TD></TR></TBODY></TABLE></TD></TR></TBODY></TABLE><!-- LIST-table-link : END --></TD></TR>\r\n<TR>\r\n<TD class=mobile-hidden style=\"FONT-SIZE: 16px; WIDTH: 70px; PADDING-BOTTOM: 0px; TEXT-ALIGN: left; PADDING-TOP: 0px; PADDING-LEFT: 0px; LINE-HEIGHT: 1.4em; PADDING-RIGHT: 0px\" vAlign=top width=70><SPAN><IMG style=\"BORDER-TOP: medium none; HEIGHT: 50px; BORDER-RIGHT: medium none; WIDTH: 70px; BORDER-BOTTOM: medium none; BORDER-LEFT: medium none; MARGIN: 0px\" alt=\"\" src=\"cid:8371631778-7\" width=70 height=50></SPAN> </TD>\r\n<TD style=\"FONT-SIZE: 16px; FONT-WEIGHT: 400; COLOR: #465a75; PADDING-BOTTOM: 20px; TEXT-ALIGN: left; PADDING-TOP: 0px; PADDING-LEFT: 0px; LINE-HEIGHT: 1.4em; PADDING-RIGHT: 0px; mso-height-rule: exactly\" vAlign=top>\r\n<P style=\"FONT-WEIGHT: 600; MARGIN: 0px\">Książka adresowa</P>\r\n<P>Kontakty są niezwykle ważne. W poczcie az.pl wszystkie masz w jednym miejscu. Możesz zaimportować listę posiadanych adresów, a także automatycznie zapisywać odbiorców, do których wysyłasz wiadomości.</P><!-- LIST-table-link : BEGIN -->\r\n<TABLE style=\"MARGIN: 0px\" cellSpacing=0 cellPadding=0 width=\"100%\" border=0>\r\n<TBODY>\r\n<TR>\r\n<TD style=\"PADDING-BOTTOM: 0px; PADDING-TOP: 0px; PADDING-LEFT: 0px; PADDING-RIGHT: 0px\">\r\n<TABLE style=\"MARGIN: 0px\" cellSpacing=0 cellPadding=0 align=left border=0>\r\n<TBODY>\r\n<TR>\r\n<TD style=\"FONT-SIZE: 16px; WIDTH: 25px; PADDING-BOTTOM: 0px; TEXT-ALIGN: left; PADDING-TOP: 0px; PADDING-LEFT: 0px; LINE-HEIGHT: 1.5em; PADDING-RIGHT: 0px\" vAlign=top width=25><A style=\"TEXT-DECORATION: none; COLOR: #0d9dcc\" href=\"https://pomoc.az.pl/kategorie/jak-zarzadzac-lista-kontaktow-e-mail-przez-webmail/\" target=_blank>→</A> </TD>\r\n<TD style=\"FONT-SIZE: 16px; FONT-WEIGHT: 400; COLOR: #0d9dcc; PADDING-BOTTOM: 0px; TEXT-ALIGN: left; PADDING-TOP: 0px; PADDING-LEFT: 0px; LINE-HEIGHT: 1.4em; PADDING-RIGHT: 0px; mso-height-rule: exactly\" vAlign=top><A style=\"TEXT-DECORATION: none; COLOR: #0d9dcc\" href=\"https://pomoc.az.pl/kategorie/jak-zarzadzac-lista-kontaktow-e-mail-przez-webmail/\" target=_blank>Przejdź&nbsp;do pomocy</A> </TD></TR></TBODY></TABLE></TD></TR></TBODY></TABLE><!-- LIST-table-link : END --></TD></TR>\r\n<TR>\r\n<TD class=mobile-hidden style=\"FONT-SIZE: 16px; WIDTH: 70px; PADDING-BOTTOM: 0px; TEXT-ALIGN: left; PADDING-TOP: 0px; PADDING-LEFT: 0px; LINE-HEIGHT: 1.4em; PADDING-RIGHT: 0px\" vAlign=top width=70><SPAN><IMG style=\"BORDER-TOP: medium none; HEIGHT: 50px; BORDER-RIGHT: medium none; WIDTH: 70px; BORDER-BOTTOM: medium none; BORDER-LEFT: medium none; MARGIN: 0px\" alt=\"\" src=\"cid:9971463894-8\" width=70 height=50></SPAN> </TD>\r\n<TD style=\"FONT-SIZE: 16px; FONT-WEIGHT: 400; COLOR: #465a75; PADDING-BOTTOM: 20px; TEXT-ALIGN: left; PADDING-TOP: 0px; PADDING-LEFT: 0px; LINE-HEIGHT: 1.4em; PADDING-RIGHT: 0px; mso-height-rule: exactly\" vAlign=top>\r\n<P style=\"FONT-WEIGHT: 600; MARGIN: 0px\">Kalendarz - zaplanuj swój dzień</P>\r\n<P>Planuj swój dzień, organizuj spotkania z klientami i… pamiętaj o urodzinach najbliższych! Kalendarz w poczcie az.pl pozwoli Ci efektywnie organizować swój czas i pamiętać o wszystkich najważniejszych terminach.</P><!-- LIST-table-link : BEGIN -->\r\n<TABLE style=\"MARGIN: 0px\" cellSpacing=0 cellPadding=0 width=\"100%\" border=0>\r\n<TBODY>\r\n<TR>\r\n<TD style=\"PADDING-BOTTOM: 0px; PADDING-TOP: 0px; PADDING-LEFT: 0px; PADDING-RIGHT: 0px\">\r\n<TABLE style=\"MARGIN: 0px\" cellSpacing=0 cellPadding=0 align=left border=0>\r\n<TBODY>\r\n<TR>\r\n<TD style=\"FONT-SIZE: 16px; WIDTH: 25px; PADDING-BOTTOM: 0px; TEXT-ALIGN: left; PADDING-TOP: 0px; PADDING-LEFT: 0px; LINE-HEIGHT: 1.5em; PADDING-RIGHT: 0px\" vAlign=top width=25><A style=\"TEXT-DECORATION: none; COLOR: #0d9dcc\" href=\"https://pomoc.az.pl/kategorie/obsluga-i-konfiguracja-kalendarza-w-poczcie-az-pl/\" target=_blank>→</A> </TD>\r\n<TD style=\"FONT-SIZE: 16px; FONT-WEIGHT: 400; COLOR: #0d9dcc; PADDING-BOTTOM: 0px; TEXT-ALIGN: left; PADDING-TOP: 0px; PADDING-LEFT: 0px; LINE-HEIGHT: 1.4em; PADDING-RIGHT: 0px; mso-height-rule: exactly\" vAlign=top><A style=\"TEXT-DECORATION: none; COLOR: #0d9dcc\" href=\"https://pomoc.az.pl/kategorie/obsluga-i-konfiguracja-kalendarza-w-poczcie-az-pl/\" target=_blank>Przejdź&nbsp;do pomocy</A> </TD></TR></TBODY></TABLE></TD></TR></TBODY></TABLE><!-- LIST-table-link : END --></TD></TR></TBODY></TABLE></TD></TR></TBODY></TABLE><!-- LIST-table ICON : END --><!-- space -->\r\n<TABLE class=spacetab style=\"MARGIN: 0px auto\" cellSpacing=0 cellPadding=0 width=\"100%\" border=0>\r\n<TBODY>\r\n<TR>\r\n<TD style=\"HEIGHT: 60px; LINE-HEIGHT: 60px\" height=60 width=\"100%\">&nbsp;</TD></TR></TBODY></TABLE><!-- space end --></TD></TR></TBODY></TABLE></TD></TR><!-- 1 Column Main Text : END --></TBODY></TABLE><!-- Email Body : END --><!-- Email foot color strap : BEGIN -->\r\n<TABLE style=\"MAX-WIDTH: 600px; BACKGROUND: #0b2a63; MIN-WIDTH: 300px\" cellSpacing=0 cellPadding=0 width=\"100%\" align=center border=0>\r\n<TBODY>\r\n<TR>\r\n<TD style=\"FONT-SIZE: 12px; BACKGROUND: #0b2a63; COLOR: #ffffff; PADDING-BOTTOM: 40px; TEXT-ALIGN: left; PADDING-TOP: 0px; PADDING-LEFT: 40px; LINE-HEIGHT: 1.4em; PADDING-RIGHT: 40px\" align=center>\r\n<TABLE class=footFeature style=\"MIN-WIDTH: 160px\" cellSpacing=0 cellPadding=0 width=\"33%\" align=left border=0>\r\n<TBODY>\r\n<TR>\r\n<TD style=\"FONT-SIZE: 16px; COLOR: #ffffff; PADDING-BOTTOM: 0px; TEXT-ALIGN: left; PADDING-TOP: 40px; PADDING-LEFT: 0px; LINE-HEIGHT: 1.4em; PADDING-RIGHT: 0px\">\r\n<P><STRONG>e-mail:</STRONG><BR><A style=\"TEXT-DECORATION: none; COLOR: #ffffff\" href=\"mailto:info@az.pl\">info@az.pl</A></P></TD></TR></TBODY></TABLE>\r\n<TABLE class=footFeature style=\"MIN-WIDTH: 160px\" cellSpacing=0 cellPadding=0 width=\"33%\" align=left border=0>\r\n<TBODY>\r\n<TR>\r\n<TD style=\"FONT-SIZE: 16px; COLOR: #ffffff; PADDING-BOTTOM: 0px; TEXT-ALIGN: left; PADDING-TOP: 40px; PADDING-LEFT: 0px; LINE-HEIGHT: 1.4em; PADDING-RIGHT: 0px\">\r\n<P><STRONG>telefon:</STRONG><BR>570&nbsp;510&nbsp;570</P></TD></TR></TBODY></TABLE>\r\n<TABLE class=footFeature style=\"MIN-WIDTH: 69px\" cellSpacing=0 cellPadding=0 width=69 align=right border=0>\r\n<TBODY>\r\n<TR>\r\n<TD style=\"PADDING-BOTTOM: 0px; TEXT-ALIGN: left; PADDING-TOP: 40px; PADDING-LEFT: 0px; PADDING-RIGHT: 0px\" vAlign=top><A style=\"BORDER-TOP: medium none; BORDER-RIGHT: medium none; BORDER-BOTTOM: medium none; BORDER-LEFT: medium none\" href=\"https://az.pl/\" target=_blank><IMG style=\"MAX-WIDTH: 69px; HEIGHT: 32px !important; WIDTH: 69px !important; MIN-WIDTH: 69px; MIN-HEIGHT: 32px; MAX-HEIGHT: 32px\" alt=AZ src=\"cid:5572582140-9\" width=69 height=32></A> </TD></TR></TBODY></TABLE></TD></TR></TBODY></TABLE><!-- Email foot color strap: END --><!-- Email foot: BEGIN -->\r\n<TABLE class=footerInfo style=\"MAX-WIDTH: 600px; BACKGROUND: #f6f7f8; MIN-WIDTH: 300px\" cellSpacing=0 cellPadding=0 width=\"100%\" align=center border=0>\r\n<TBODY>\r\n<TR>\r\n<TD style=\"FONT-SIZE: 12px; BACKGROUND: #f6f7f8; COLOR: #465a75; PADDING-BOTTOM: 40px; TEXT-ALIGN: left; PADDING-TOP: 40px; PADDING-LEFT: 40px; LINE-HEIGHT: 1.4em; PADDING-RIGHT: 40px\" align=center>\r\n<P style=\"FONT-SIZE: 12px; COLOR: #465a75; MARGIN: 0px 0px 10px; LINE-HEIGHT: 1.4em\"><A style=\"TEXT-DECORATION: none; COLOR: #465a75\" href=\"https://az.pl/\" target=_blank>AZ.pl</A> Sp. z o.o., adres: Zbożowa 4, 70-653 Szczecin, Polska <BR>NIP:&nbsp;8561164306, REGON:&nbsp;810903927, KRS:&nbsp;0000360147 </P></TD></TR></TBODY></TABLE><!-- Email foot: END --></TD></TR></TBODY></TABLE></DIV></CENTER></TD></TR></TBODY></TABLE></BODY></HTML>','','RECEIVED',NULL,'2026-02-18 11:10:26','<crm-d4089ca2cb49f10b942f@adsmanager.com.pl>',NULL,NULL,1,'INBOX',NULL,1,NULL,'','',NULL,1,NULL,NULL,NULL),
(7,NULL,9,NULL,2,'out','test@adsmanager.com.pl','Jan Kowalski','naczelny@radiozulawy.pl',NULL,NULL,'oferta nowego leada','to jest oferta nowego leada pozdrawiam','to jest oferta nowego leada pozdrawiam','SENT',NULL,'2026-02-18 13:05:27','<crm-6e839242f43452494cfd@adsmanager.com.pl>',NULL,NULL,NULL,NULL,NULL,1,6,'naczelny@radiozulawy.pl',NULL,'2026-02-18 13:05:27',1,'lead',9,2),
(8,14,NULL,NULL,2,'in','naczelny@radiozulawy.pl','Karol Pufal Radio Żuławy 106.4FM','Jan Kowalski <test@adsmanager.com.pl>','','','Re: oferta nowego leada','<!DOCTYPE html>\r\n<html>\r\n  <head>\r\n    <meta http-equiv=\"Content-Type\" content=\"text/html; charset=UTF-8\">\r\n  </head>\r\n  <body>\r\n    <p>oferta wygląda ok biere</p>\r\n    <div class=\"moz-cite-prefix\">W dniu 18.02.2026 o 13:05, Jan Kowalski\r\n      pisze:<br>\r\n    </div>\r\n    <blockquote type=\"cite\"\r\n      cite=\"mid:crm-6e839242f43452494cfd@adsmanager.com.pl\">\r\n      <meta http-equiv=\"content-type\" content=\"text/html; charset=UTF-8\">\r\n      to jest oferta nowego leada pozdrawiam\r\n    </blockquote>\r\n    <div class=\"moz-signature\">-- <br>\r\n      <title>Email Signature</title>\r\n      <meta content=\"text/html; charset=UTF-8\" http-equiv=\"Content-Type\">\r\n      <table\r\nstyle=\"width: 525px; font-size: 11pt; font-family: Arial, sans-serif; background: transparent !important;\"\r\n        cellpadding=\"0\" cellspacing=\"0\">\r\n        <tbody>\r\n          <tr>\r\n            <td width=\"125\"\r\nstyle=\"font-size: 10pt; font-family: Arial, sans-serif; border-right: 1px solid; border-right-color: #04af09; width: 125px; padding-right: 10px; vertical-align: top;\"\r\n              valign=\"top\" rowspan=\"6\"> <a\r\n                href=\"https://radiozulawy.pl\" target=\"_blank\"><img\r\n                  border=\"0\" width=\"105\"\r\n                  style=\"width:105px; height:auto; border:0;\"\r\nsrc=\"https://hosting2588063.online.pro/zulawy/logo/logo.png\"></a> </td>\r\n            <td style=\"padding-left:10px\">\r\n              <table cellpadding=\"0\" cellspacing=\"0\"\r\n                style=\"background: transparent !important;\">\r\n                <tbody>\r\n                  <tr>\r\n                    <td\r\nstyle=\"font-size: 10pt; color:#0079ac; font-family: Arial, sans-serif; width: 400px; padding-bottom: 5px; padding-left: 10px; vertical-align: top;\"\r\n                      valign=\"top\"><strong><span\r\nstyle=\"font-size: 11pt; font-family: Arial, sans-serif; color:#04af09;\">Karol\r\n                          Pufal</span></strong><strong\r\nstyle=\"font-family: Arial, sans-serif; font-size:11pt; color:#000000;\"><span>\r\n                          | </span>Redaktor Naczelny</strong></td>\r\n                  </tr>\r\n                  <tr>\r\n                    <td\r\nstyle=\"font-size: 10pt; color:#444444; font-family: Arial, sans-serif; padding-bottom: 5px; padding-top: 5px; padding-left: 10px; vertical-align: top; line-height:17px;\"\r\n                      valign=\"top\"> <span> <span\r\n                          style=\"color: #04af09;\"><strong>a: </strong></span>\r\n                      </span> <span> <span\r\nstyle=\"font-family: Arial, sans-serif; font-size:10pt; color:#000000;\">Radio\r\n                          Żuławy 106,4FM</span> </span> <span> <span\r\nstyle=\"font-size: 10pt; font-family: Arial, sans-serif; color: #000000;\"><span>\r\n                            | </span>ul. Fromborska 2A<span></span></span>\r\n                        <span\r\nstyle=\"font-size: 10pt; font-family: Arial, sans-serif; color: #000000;\"><span>\r\n                            | </span>82-300 Elbląg</span> </span> <span><br>\r\n                      </span> <span><span style=\"color: #04af09;\"><strong>e:</strong></span><span\r\nstyle=\"font-size: 10pt; font-family: Arial, sans-serif; color:#000000;\">\r\n                          <a class=\"moz-txt-link-abbreviated\" href=\"mailto:naczelny@radiozulawy.pl\">naczelny@radiozulawy.pl</a></span></span> <span><span>\r\n                          | </span><span style=\"color: #04af09;\"><strong>w:</strong></span><a\r\n                          href=\"http://radiozulawy.pl\" target=\"_blank\"\r\n                          rel=\"noopener\" style=\"text-decoration:none;\"><span\r\nstyle=\"font-size: 10pt; font-family: Arial, sans-serif; color:#000000;\">\r\n                            radiozulawy.pl</span></a></span> <span><br>\r\n                      </span> <span><span style=\"color: #04af09;\"><strong>m:</strong></span><span\r\nstyle=\"font-size: 10pt; font-family: Arial, sans-serif; color:#000000;\">\r\n                          881 659 971</span></span><span><span> | </span><span\r\n                          style=\"color: #04af09;\"><strong>p:</strong></span><span\r\nstyle=\"font-size: 10pt; font-family: Arial, sans-serif; color:#000000;\">\r\n                          55 621 30 20</span></span></td>\r\n                  </tr>\r\n                </tbody>\r\n              </table>\r\n            </td>\r\n          </tr>\r\n        </tbody>\r\n      </table>\r\n    </div>\r\n  </body>\r\n</html>','oferta wygląda ok biere\r\n\r\nW dniu 18.02.2026 o 13:05, Jan Kowalski pisze:\r\n> to jest oferta nowego leada pozdrawiam \r\n-- \r\nEmail Signature\r\n<https://radiozulawy.pl> 	\r\n*Karol Pufal**| Redaktor Naczelny*\r\n*a: * Radio Żuławy 106,4FM | ul. Fromborska 2A| 82-300 Elbląg\r\n*e:*naczelny@radiozulawy.pl | *w:*radiozulawy.pl <http://radiozulawy.pl>\r\n*m:*881 659 971| *p:*55 621 30 20','RECEIVED',NULL,'2026-02-18 13:05:59','<055f9e3a-f32d-47b4-89ad-1b965a4a8c33@radiozulawy.pl>','<crm-6e839242f43452494cfd@adsmanager.com.pl>','<crm-6e839242f43452494cfd@adsmanager.com.pl>',3,'INBOX','2026-02-18 13:05:45',1,7,'test@adsmanager.com.pl','',NULL,0,'client',14,NULL),
(9,NULL,9,NULL,2,'out','test@adsmanager.com.pl','Jan Kowalski','karolpufal@gmail.com',NULL,NULL,'oferta','to jest oferta 2026','to jest oferta 2026','SENT',NULL,'2026-02-18 13:11:57','<crm-41feb25e2cb6d38b81bb@adsmanager.com.pl>',NULL,NULL,NULL,NULL,NULL,1,8,'karolpufal@gmail.com',NULL,'2026-02-18 13:11:57',1,'lead',9,2),
(10,NULL,9,NULL,2,'in','karolpufal@gmail.com','Karol Pufal','Jan Kowalski <test@adsmanager.com.pl>','','','Re: oferta','<div dir=\"ltr\">wygląda legitnie dawaj to</div><br><div class=\"gmail_quote gmail_quote_container\"><div dir=\"ltr\" class=\"gmail_attr\">śr., 18 lut 2026 o 13:12 Jan Kowalski &lt;<a href=\"mailto:test@adsmanager.com.pl\">test@adsmanager.com.pl</a>&gt; napisał(a):<br></div><blockquote class=\"gmail_quote\" style=\"margin:0px 0px 0px 0.8ex;border-left:1px solid rgb(204,204,204);padding-left:1ex\">to jest oferta 2026\r\n\r\n</blockquote></div>','wygląda legitnie dawaj to\r\n\r\nśr., 18 lut 2026 o 13:12 Jan Kowalski <test@adsmanager.com.pl> napisał(a):\r\n\r\n> to jest oferta 2026','RECEIVED',NULL,'2026-02-18 13:12:49','<CALfQGLq-r2nbR2Xm_CKFTfM6i1yg80xM9ysMpv+XSzVn163QnA@mail.gmail.com>','<crm-41feb25e2cb6d38b81bb@adsmanager.com.pl>','<crm-41feb25e2cb6d38b81bb@adsmanager.com.pl>',4,'INBOX','2026-02-18 13:12:35',1,8,'test@adsmanager.com.pl','',NULL,0,'lead',9,NULL),
(11,NULL,10,NULL,2,'out','test@adsmanager.com.pl','Jan Kowalski','marianwolski2323@gmail.com',NULL,NULL,'testowa','testowa wiadomosć','testowa wiadomosć','SENT',NULL,'2026-02-18 13:58:21','<crm-228b2665fb5cb5850deb@adsmanager.com.pl>',NULL,NULL,NULL,NULL,NULL,1,9,'marianwolski2323@gmail.com',NULL,'2026-02-18 13:58:21',1,'lead',10,2),
(12,NULL,10,NULL,2,'in','marianwolski2323@gmail.com','Marian Wolski','Jan Kowalski <test@adsmanager.com.pl>','','','Re: testowa','<div dir=\"ltr\">testowa wiadomość</div><br><div class=\"gmail_quote gmail_quote_container\"><div dir=\"ltr\" class=\"gmail_attr\">śr., 18 lut 2026 o 13:58 Jan Kowalski &lt;<a href=\"mailto:test@adsmanager.com.pl\">test@adsmanager.com.pl</a>&gt; napisał(a):<br></div><blockquote class=\"gmail_quote\" style=\"margin:0px 0px 0px 0.8ex;border-left:1px solid rgb(204,204,204);padding-left:1ex\">testowa wiadomosć\r\n\r\n</blockquote></div>','testowa wiadomość\r\n\r\nśr., 18 lut 2026 o 13:58 Jan Kowalski <test@adsmanager.com.pl> napisał(a):\r\n\r\n> testowa wiadomosć','RECEIVED',NULL,'2026-02-18 13:59:49','<CAPjEquhZ6oiAdHtwjLbwcrBO+xqJAmQ00ywc1jaa=geaUG0zcw@mail.gmail.com>','<crm-228b2665fb5cb5850deb@adsmanager.com.pl>','<crm-228b2665fb5cb5850deb@adsmanager.com.pl>',5,'INBOX','2026-02-18 13:59:02',1,9,'test@adsmanager.com.pl','',NULL,0,'lead',10,NULL);
/*!40000 ALTER TABLE `mail_messages` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `mail_threads`
--

DROP TABLE IF EXISTS `mail_threads`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `mail_threads` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `entity_type` enum('lead','client') NOT NULL,
  `entity_id` int(11) NOT NULL,
  `subject` varchar(255) DEFAULT NULL,
  `subject_hash` char(64) NOT NULL,
  `last_message_at` datetime NOT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_mail_threads_entity_last` (`entity_type`,`entity_id`,`last_message_at`)
) ENGINE=InnoDB AUTO_INCREMENT=10 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `mail_threads`
--

LOCK TABLES `mail_threads` WRITE;
/*!40000 ALTER TABLE `mail_threads` DISABLE KEYS */;
INSERT INTO `mail_threads` VALUES
(1,'lead',3,'oferta na kaktusy','855c4d70f9611aaceaea4337a5df47691cda69f46383e88dfaf72e763b80a79d','2026-02-16 21:56:39','2026-02-16 21:56:39'),
(2,'lead',3,'oferta na spot','0524c778a613ef070b34e0181a7bd282333a64e6ef249d651f532bdbc2f666af','2026-02-16 22:03:41','2026-02-16 22:03:41'),
(3,'lead',3,'ewdwee','453e06c7cb42a1a5fe46abeed5c2f9fc794d41d52363f1f419f6cc793f27b1ec','2026-02-16 22:11:36','2026-02-16 22:11:36'),
(4,'lead',8,'oferta na 2026','70fc7d84280dc9740d7d889d8cc8282fc45365eb89f7ddff6cbbd37f11bba9e9','2026-02-17 23:05:56','2026-02-17 23:05:56'),
(5,'client',12,'Re: oferta na 2026','70fc7d84280dc9740d7d889d8cc8282fc45365eb89f7ddff6cbbd37f11bba9e9','2026-02-17 23:06:16','2026-02-18 11:10:25'),
(6,'lead',9,'oferta nowego leada','e365b338f0cbb04c3be38a1cec65c096c24b94d905912829201c218bfbdebe2e','2026-02-18 13:05:27','2026-02-18 13:05:27'),
(7,'client',14,'Re: oferta nowego leada','e365b338f0cbb04c3be38a1cec65c096c24b94d905912829201c218bfbdebe2e','2026-02-18 13:05:45','2026-02-18 13:05:59'),
(8,'lead',9,'oferta','a13fee0279d4aaafd0ba122496b04740130b5b30e8c10b890b3dc328ec3e4005','2026-02-18 13:12:35','2026-02-18 13:11:57'),
(9,'lead',10,'testowa','314fa057448a2849b4614c1adc5f09348fe1047c3203940e6051e435c8d8109d','2026-02-18 13:59:02','2026-02-18 13:58:21');
/*!40000 ALTER TABLE `mail_threads` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `numeracja_dokumentow`
--

DROP TABLE IF EXISTS `numeracja_dokumentow`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `numeracja_dokumentow` (
  `year` int(11) NOT NULL,
  `last_number` int(11) NOT NULL DEFAULT 0,
  PRIMARY KEY (`year`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `numeracja_dokumentow`
--

LOCK TABLES `numeracja_dokumentow` WRITE;
/*!40000 ALTER TABLE `numeracja_dokumentow` DISABLE KEYS */;
/*!40000 ALTER TABLE `numeracja_dokumentow` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `plany_emisji`
--

DROP TABLE IF EXISTS `plany_emisji`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `plany_emisji` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `klient_id` int(11) NOT NULL,
  `rok` int(11) NOT NULL,
  `miesiac` int(11) NOT NULL,
  `nazwa_planu` varchar(255) NOT NULL,
  `dlugosc_spotu` time DEFAULT NULL,
  `rabat` decimal(10,2) DEFAULT 0.00,
  `data_utworzenia` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `klient_id` (`klient_id`),
  CONSTRAINT `plany_emisji_ibfk_1` FOREIGN KEY (`klient_id`) REFERENCES `klienci` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=latin2 COLLATE=latin2_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `plany_emisji`
--

LOCK TABLES `plany_emisji` WRITE;
/*!40000 ALTER TABLE `plany_emisji` DISABLE KEYS */;
/*!40000 ALTER TABLE `plany_emisji` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `plany_emisji_szczegoly`
--

DROP TABLE IF EXISTS `plany_emisji_szczegoly`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `plany_emisji_szczegoly` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `plan_id` int(11) NOT NULL,
  `dzien_miesiaca` int(11) NOT NULL,
  `godzina` time NOT NULL,
  `ilosc_spotow` int(11) NOT NULL,
  PRIMARY KEY (`id`),
  KEY `plan_id` (`plan_id`),
  CONSTRAINT `plany_emisji_szczegoly_ibfk_1` FOREIGN KEY (`plan_id`) REFERENCES `plany_emisji` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=latin2 COLLATE=latin2_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `plany_emisji_szczegoly`
--

LOCK TABLES `plany_emisji_szczegoly` WRITE;
/*!40000 ALTER TABLE `plany_emisji_szczegoly` DISABLE KEYS */;
/*!40000 ALTER TABLE `plany_emisji_szczegoly` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `powiadomienia`
--

DROP TABLE IF EXISTS `powiadomienia`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `powiadomienia` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `typ` varchar(30) NOT NULL,
  `tresc` text NOT NULL,
  `link` varchar(255) DEFAULT NULL,
  `is_read` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_powiadomienia_user` (`user_id`),
  KEY `idx_powiadomienia_type` (`typ`)
) ENGINE=InnoDB AUTO_INCREMENT=4 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `powiadomienia`
--

LOCK TABLES `powiadomienia` WRITE;
/*!40000 ALTER TABLE `powiadomienia` DISABLE KEYS */;
INSERT INTO `powiadomienia` VALUES
(1,2,'lead_followup','Lead do kontaktu: Aparthotel Dworzec','lead.php?lead_edit_id=1#lead-form',0,'2026-02-17 14:42:50'),
(2,2,'lead_followup','Lead do kontaktu: 5N Justyna Zienkiewicz — Follow-up po ofercie','lead.php?lead_edit_id=5#lead-form',0,'2026-02-21 12:36:39'),
(3,2,'lead_followup','Lead do kontaktu: 5N Justyna Zienkiewicz — Follow-up po ofercie','lead.php?lead_edit_id=5#lead-form',0,'2026-02-25 20:22:58');
/*!40000 ALTER TABLE `powiadomienia` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `prowizje_rozliczenia`
--

DROP TABLE IF EXISTS `prowizje_rozliczenia`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `prowizje_rozliczenia` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `year` smallint(6) NOT NULL,
  `month` tinyint(4) NOT NULL,
  `user_id` int(11) NOT NULL,
  `rate_percent` decimal(5,2) NOT NULL,
  `base_netto` decimal(12,2) NOT NULL DEFAULT 0.00,
  `commission_netto` decimal(12,2) NOT NULL DEFAULT 0.00,
  `status` varchar(20) NOT NULL DEFAULT 'Należne',
  `calculated_at` datetime NOT NULL DEFAULT current_timestamp(),
  `paid_at` datetime DEFAULT NULL,
  `note` varchar(255) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_prowizje_rozliczenia_period_user` (`year`,`month`,`user_id`),
  KEY `idx_prowizje_rozliczenia_user` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `prowizje_rozliczenia`
--

LOCK TABLES `prowizje_rozliczenia` WRITE;
/*!40000 ALTER TABLE `prowizje_rozliczenia` DISABLE KEYS */;
/*!40000 ALTER TABLE `prowizje_rozliczenia` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `schema_migrations`
--

DROP TABLE IF EXISTS `schema_migrations`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `schema_migrations` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `filename` varchar(255) NOT NULL,
  `applied_at` datetime NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_schema_migrations_filename` (`filename`)
) ENGINE=InnoDB AUTO_INCREMENT=27 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `schema_migrations`
--

LOCK TABLES `schema_migrations` WRITE;
/*!40000 ALTER TABLE `schema_migrations` DISABLE KEYS */;
INSERT INTO `schema_migrations` VALUES
(1,'2026_01_27_00_create_companies.sql','2026-02-15 16:45:54'),
(2,'2025_12_28_12A_commission_rates.sql','2026-02-15 16:45:54'),
(3,'2026_01_02_01_mail_archive.sql','2026-02-15 16:45:54'),
(4,'2026_01_02_02_imap_inbox.sql','2026-02-15 16:45:54'),
(5,'2026_01_02_03_crm_activity.sql','2026-02-15 16:45:54'),
(6,'2026_01_03_01_contact_person.sql','2026-02-15 16:45:54'),
(7,'2026_01_03_01_stage3_mail_sms.sql','2026-02-15 16:46:21'),
(8,'2026_01_03_02_crm_tasks.sql','2026-02-15 16:46:21'),
(9,'2026_01_03_03_activity_log.sql','2026-02-15 16:46:21'),
(10,'2026_01_04_01_mail_accounts_adjust.sql','2026-02-15 16:46:21'),
(11,'2026_01_27_10B_canonical_schema.sql','2026-02-15 16:46:21'),
(12,'2026_01_27_10B_foreign_keys_optional.sql','2026-02-15 16:46:21'),
(13,'2026_01_27_10B_unique_nip_optional.sql','2026-02-15 16:46:21'),
(14,'2026_01_27_11B_klienci_legacy_nullable.sql','2026-02-15 16:46:21'),
(15,'2026_01_27_11C_unique_companies_nip.sql','2026-02-15 16:46:21'),
(16,'2026_01_27_11D_companies_provenance.sql','2026-02-15 16:46:21'),
(17,'2026_01_27_12A_gus_refresh_queue.sql','2026-02-15 16:46:21'),
(18,'2026_01_27_12D_integration_alerts.sql','2026-02-15 16:46:21'),
(19,'2026_01_27_12F_queue_error_fields.sql','2026-02-15 16:46:21'),
(20,'2026_01_27_12H_company_gus_hold.sql','2026-02-15 16:46:21'),
(21,'2026_01_27_12I_integration_circuit_breaker.sql','2026-02-15 16:46:21'),
(22,'2026_01_27_12J_admin_actions_audit.sql','2026-02-15 16:46:21'),
(23,'2026_01_27_12L_worker_locks.sql','2026-02-15 16:46:21'),
(24,'2026_02_08_01_companies_name_fields_hotfix.sql','2026-02-15 16:46:21'),
(25,'PROD_BOOTSTRAP_companies.sql','2026-02-15 16:46:22'),
(26,'2026_02_15_01_kampanie_tygodniowe_upsert.sql','2026-02-15 18:19:53');
/*!40000 ALTER TABLE `schema_migrations` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `sms_messages`
--

DROP TABLE IF EXISTS `sms_messages`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `sms_messages` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `entity_type` enum('lead','client') DEFAULT NULL,
  `entity_id` int(11) DEFAULT NULL,
  `user_id` int(11) DEFAULT NULL,
  `direction` enum('in','out') NOT NULL,
  `phone` varchar(40) NOT NULL,
  `content` text NOT NULL,
  `provider` varchar(50) DEFAULT NULL,
  `provider_message_id` varchar(120) DEFAULT NULL,
  `status` varchar(40) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_sms_messages_entity_created` (`entity_type`,`entity_id`,`created_at`),
  KEY `idx_sms_messages_phone_created` (`phone`,`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `sms_messages`
--

LOCK TABLES `sms_messages` WRITE;
/*!40000 ALTER TABLE `sms_messages` DISABLE KEYS */;
/*!40000 ALTER TABLE `sms_messages` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `spot_audio_files`
--

DROP TABLE IF EXISTS `spot_audio_files`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `spot_audio_files` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `spot_id` int(11) NOT NULL,
  `version_no` int(11) NOT NULL DEFAULT 1,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `original_filename` varchar(255) NOT NULL,
  `stored_filename` varchar(255) NOT NULL,
  `mime_type` varchar(100) DEFAULT NULL,
  `file_size` int(11) DEFAULT NULL,
  `sha256` char(64) DEFAULT NULL,
  `production_status` varchar(30) NOT NULL DEFAULT 'Do akceptacji',
  `approved_by_user_id` int(11) DEFAULT NULL,
  `approved_at` datetime DEFAULT NULL,
  `rejection_reason` varchar(255) DEFAULT NULL,
  `uploaded_by_user_id` int(11) NOT NULL,
  `upload_note` varchar(255) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_spot_audio_files_stored` (`stored_filename`),
  KEY `idx_spot_audio_files_spot_id` (`spot_id`),
  KEY `idx_spot_audio_files_uploaded_by` (`uploaded_by_user_id`)
) ENGINE=InnoDB AUTO_INCREMENT=5 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `spot_audio_files`
--

LOCK TABLES `spot_audio_files` WRITE;
/*!40000 ALTER TABLE `spot_audio_files` DISABLE KEYS */;
/*!40000 ALTER TABLE `spot_audio_files` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `spoty`
--

DROP TABLE IF EXISTS `spoty`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `spoty` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `klient_id` int(11) DEFAULT NULL,
  `nazwa_spotu` varchar(255) NOT NULL,
  `dlugosc` enum('15','20','30') NOT NULL,
  `data_start` date DEFAULT NULL,
  `data_koniec` date DEFAULT NULL,
  `data_dodania` timestamp NULL DEFAULT current_timestamp(),
  `aktywny` tinyint(1) NOT NULL DEFAULT 1,
  `rezerwacja` tinyint(1) NOT NULL DEFAULT 0,
  `kampania_id` int(11) DEFAULT NULL,
  `dlugosc_s` int(11) NOT NULL DEFAULT 30,
  `status` varchar(20) NOT NULL DEFAULT 'Aktywny',
  `rotation_group` varchar(1) DEFAULT NULL,
  `rotation_mode` varchar(30) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `klient_id` (`klient_id`),
  KEY `idx_spoty_klient_id` (`klient_id`),
  KEY `idx_spoty_kampania_id` (`kampania_id`),
  CONSTRAINT `spoty_ibfk_1` FOREIGN KEY (`klient_id`) REFERENCES `klienci` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=159 DEFAULT CHARSET=latin2 COLLATE=latin2_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `spoty`
--

LOCK TABLES `spoty` WRITE;
/*!40000 ALTER TABLE `spoty` DISABLE KEYS */;
INSERT INTO `spoty` VALUES
(7,4,'Oferta świąteczna','30','2025-11-23','2026-03-31','2025-11-23 18:19:46',1,0,NULL,30,'Aktywny',NULL,NULL),
(8,6,'Spot audio','20','2025-11-24','2025-11-30','2025-11-23 18:46:23',0,1,NULL,30,'Nieaktywny',NULL,NULL),
(9,4,'gramy świątecznie','15','2025-11-28','2025-12-05','2025-11-24 10:09:22',0,1,NULL,30,'Nieaktywny',NULL,NULL),
(121,15,'Reklama chlebaków','20','2026-03-01','2026-03-31','2026-02-19 12:01:21',1,0,116,20,'Aktywny',NULL,NULL),
(122,16,'reklama ogórków','20','2026-03-01','2026-03-31','2026-02-19 12:10:50',1,0,119,20,'Aktywny',NULL,NULL),
(127,19,'Reklama ziemniaków polskich','20','2026-02-22','2026-03-31','2026-02-21 14:28:04',1,0,142,20,'Aktywny',NULL,NULL),
(146,19,'reklama owocków','20','2026-02-22','2026-03-31','2026-02-25 21:28:00',1,0,142,20,'Aktywny',NULL,NULL),
(147,19,'Reklama chleba','20','2026-02-22','2026-03-31','2026-02-25 21:28:44',1,0,142,20,'Aktywny','A','PREFER_A'),
(148,19,'Darmowa promocja zapisów','20','2026-02-22','2026-03-31','2026-02-25 21:46:57',1,0,142,20,'Aktywny',NULL,NULL),
(149,19,'Reklama pomidorów','20','2026-02-22','2026-03-31','2026-02-25 21:49:38',1,0,142,20,'Aktywny',NULL,NULL),
(156,19,'Reklama chleba','20','2026-02-22','2026-03-31','2026-02-25 22:11:41',1,0,142,20,'Aktywny',NULL,NULL);
/*!40000 ALTER TABLE `spoty` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `spoty_emisje`
--

DROP TABLE IF EXISTS `spoty_emisje`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `spoty_emisje` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `spot_id` int(11) NOT NULL,
  `data` date NOT NULL,
  `godzina` time NOT NULL,
  `blok` varchar(1) NOT NULL,
  PRIMARY KEY (`id`),
  KEY `spot_id` (`spot_id`),
  CONSTRAINT `spoty_emisje_ibfk_1` FOREIGN KEY (`spot_id`) REFERENCES `spoty` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=8052 DEFAULT CHARSET=latin2 COLLATE=latin2_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `spoty_emisje`
--

LOCK TABLES `spoty_emisje` WRITE;
/*!40000 ALTER TABLE `spoty_emisje` DISABLE KEYS */;
INSERT INTO `spoty_emisje` VALUES
(6856,7,'2025-11-24','10:00:00','B'),
(6857,7,'2025-11-24','11:00:00','B'),
(6858,7,'2025-11-24','12:00:00','B'),
(6859,7,'2025-11-24','13:00:00','B'),
(6860,7,'2025-11-25','10:00:00','B'),
(6861,7,'2025-11-25','11:00:00','B'),
(6862,7,'2025-11-25','12:00:00','B'),
(6863,7,'2025-11-25','13:00:00','B'),
(6864,7,'2025-11-26','10:00:00','B'),
(6865,7,'2025-11-26','11:00:00','B'),
(6866,7,'2025-11-26','12:00:00','B'),
(6867,7,'2025-11-26','13:00:00','B'),
(6868,7,'2025-11-27','10:00:00','B'),
(6869,7,'2025-11-27','11:00:00','B'),
(6870,7,'2025-11-27','12:00:00','B'),
(6871,7,'2025-11-27','13:00:00','B'),
(6872,7,'2025-11-28','10:00:00','B'),
(6873,7,'2025-11-28','11:00:00','B'),
(6874,7,'2025-11-28','12:00:00','B'),
(6875,7,'2025-11-28','13:00:00','B'),
(6876,7,'2025-12-01','10:00:00','B'),
(6877,7,'2025-12-01','11:00:00','B'),
(6878,7,'2025-12-01','12:00:00','B'),
(6879,7,'2025-12-01','13:00:00','B'),
(6880,7,'2025-12-02','10:00:00','B'),
(6881,7,'2025-12-02','11:00:00','B'),
(6882,7,'2025-12-02','12:00:00','B'),
(6883,7,'2025-12-02','13:00:00','B'),
(6884,7,'2025-12-03','10:00:00','B'),
(6885,7,'2025-12-03','11:00:00','B'),
(6886,7,'2025-12-03','12:00:00','B'),
(6887,7,'2025-12-03','13:00:00','B'),
(6888,7,'2025-12-04','10:00:00','B'),
(6889,7,'2025-12-04','11:00:00','B'),
(6890,7,'2025-12-04','12:00:00','B'),
(6891,7,'2025-12-04','13:00:00','B'),
(6892,7,'2025-12-05','10:00:00','B'),
(6893,7,'2025-12-05','11:00:00','B'),
(6894,7,'2025-12-05','12:00:00','B'),
(6895,7,'2025-12-05','13:00:00','B'),
(6896,7,'2025-12-08','10:00:00','B'),
(6897,7,'2025-12-08','11:00:00','B'),
(6898,7,'2025-12-08','12:00:00','B'),
(6899,7,'2025-12-08','13:00:00','B'),
(6900,7,'2025-12-09','10:00:00','B'),
(6901,7,'2025-12-09','11:00:00','B'),
(6902,7,'2025-12-09','12:00:00','B'),
(6903,7,'2025-12-09','13:00:00','B'),
(6904,7,'2025-12-10','10:00:00','B'),
(6905,7,'2025-12-10','11:00:00','B'),
(6906,7,'2025-12-10','12:00:00','B'),
(6907,7,'2025-12-10','13:00:00','B'),
(6908,7,'2025-12-11','10:00:00','B'),
(6909,7,'2025-12-11','11:00:00','B'),
(6910,7,'2025-12-11','12:00:00','B'),
(6911,7,'2025-12-11','13:00:00','B'),
(6912,7,'2025-12-12','10:00:00','B'),
(6913,7,'2025-12-12','11:00:00','B'),
(6914,7,'2025-12-12','12:00:00','B'),
(6915,7,'2025-12-12','13:00:00','B'),
(6916,7,'2025-12-15','10:00:00','B'),
(6917,7,'2025-12-15','11:00:00','B'),
(6918,7,'2025-12-15','12:00:00','B'),
(6919,7,'2025-12-15','13:00:00','B'),
(6920,7,'2025-12-16','10:00:00','B'),
(6921,7,'2025-12-16','11:00:00','B'),
(6922,7,'2025-12-16','12:00:00','B'),
(6923,7,'2025-12-16','13:00:00','B'),
(6924,7,'2025-12-17','10:00:00','B'),
(6925,7,'2025-12-17','11:00:00','B'),
(6926,7,'2025-12-17','12:00:00','B'),
(6927,7,'2025-12-17','13:00:00','B'),
(6928,7,'2025-12-18','10:00:00','B'),
(6929,7,'2025-12-18','11:00:00','B'),
(6930,7,'2025-12-18','12:00:00','B'),
(6931,7,'2025-12-18','13:00:00','B'),
(6932,7,'2025-12-19','10:00:00','B'),
(6933,7,'2025-12-19','11:00:00','B'),
(6934,7,'2025-12-19','12:00:00','B'),
(6935,7,'2025-12-19','13:00:00','B'),
(6936,7,'2025-12-22','10:00:00','B'),
(6937,7,'2025-12-22','11:00:00','B'),
(6938,7,'2025-12-22','12:00:00','B'),
(6939,7,'2025-12-22','13:00:00','B'),
(6940,7,'2025-12-23','10:00:00','B'),
(6941,7,'2025-12-23','11:00:00','B'),
(6942,7,'2025-12-23','12:00:00','B'),
(6943,7,'2025-12-23','13:00:00','B'),
(6944,7,'2025-12-24','10:00:00','B'),
(6945,7,'2025-12-24','11:00:00','B'),
(6946,7,'2025-12-24','12:00:00','B'),
(6947,7,'2025-12-24','13:00:00','B'),
(6948,7,'2025-12-25','10:00:00','B'),
(6949,7,'2025-12-25','11:00:00','B'),
(6950,7,'2025-12-25','12:00:00','B'),
(6951,7,'2025-12-25','13:00:00','B'),
(6952,7,'2025-12-26','10:00:00','B'),
(6953,7,'2025-12-26','11:00:00','B'),
(6954,7,'2025-12-26','12:00:00','B'),
(6955,7,'2025-12-26','13:00:00','B'),
(6956,7,'2025-12-29','10:00:00','B'),
(6957,7,'2025-12-29','11:00:00','B'),
(6958,7,'2025-12-29','12:00:00','B'),
(6959,7,'2025-12-29','13:00:00','B'),
(6960,7,'2025-12-30','10:00:00','B'),
(6961,7,'2025-12-30','11:00:00','B'),
(6962,7,'2025-12-30','12:00:00','B'),
(6963,7,'2025-12-30','13:00:00','B'),
(6964,7,'2025-12-31','10:00:00','B'),
(6965,7,'2025-12-31','11:00:00','B'),
(6966,7,'2025-12-31','12:00:00','B'),
(6967,7,'2025-12-31','13:00:00','B'),
(6968,7,'2026-01-01','10:00:00','B'),
(6969,7,'2026-01-01','11:00:00','B'),
(6970,7,'2026-01-01','12:00:00','B'),
(6971,7,'2026-01-01','13:00:00','B'),
(6972,7,'2026-01-02','10:00:00','B'),
(6973,7,'2026-01-02','11:00:00','B'),
(6974,7,'2026-01-02','12:00:00','B'),
(6975,7,'2026-01-02','13:00:00','B'),
(6976,7,'2026-01-05','10:00:00','B'),
(6977,7,'2026-01-05','11:00:00','B'),
(6978,7,'2026-01-05','12:00:00','B'),
(6979,7,'2026-01-05','13:00:00','B'),
(6980,7,'2026-01-06','10:00:00','B'),
(6981,7,'2026-01-06','11:00:00','B'),
(6982,7,'2026-01-06','12:00:00','B'),
(6983,7,'2026-01-06','13:00:00','B'),
(6984,7,'2026-01-07','10:00:00','B'),
(6985,7,'2026-01-07','11:00:00','B'),
(6986,7,'2026-01-07','12:00:00','B'),
(6987,7,'2026-01-07','13:00:00','B'),
(6988,7,'2026-01-08','10:00:00','B'),
(6989,7,'2026-01-08','11:00:00','B'),
(6990,7,'2026-01-08','12:00:00','B'),
(6991,7,'2026-01-08','13:00:00','B'),
(6992,7,'2026-01-09','10:00:00','B'),
(6993,7,'2026-01-09','11:00:00','B'),
(6994,7,'2026-01-09','12:00:00','B'),
(6995,7,'2026-01-09','13:00:00','B'),
(6996,7,'2026-01-12','10:00:00','B'),
(6997,7,'2026-01-12','11:00:00','B'),
(6998,7,'2026-01-12','12:00:00','B'),
(6999,7,'2026-01-12','13:00:00','B'),
(7000,7,'2026-01-13','10:00:00','B'),
(7001,7,'2026-01-13','11:00:00','B'),
(7002,7,'2026-01-13','12:00:00','B'),
(7003,7,'2026-01-13','13:00:00','B'),
(7004,7,'2026-01-14','10:00:00','B'),
(7005,7,'2026-01-14','11:00:00','B'),
(7006,7,'2026-01-14','12:00:00','B'),
(7007,7,'2026-01-14','13:00:00','B'),
(7008,7,'2026-01-15','10:00:00','B'),
(7009,7,'2026-01-15','11:00:00','B'),
(7010,7,'2026-01-15','12:00:00','B'),
(7011,7,'2026-01-15','13:00:00','B'),
(7012,7,'2026-01-16','10:00:00','B'),
(7013,7,'2026-01-16','11:00:00','B'),
(7014,7,'2026-01-16','12:00:00','B'),
(7015,7,'2026-01-16','13:00:00','B'),
(7016,7,'2026-01-19','10:00:00','B'),
(7017,7,'2026-01-19','11:00:00','B'),
(7018,7,'2026-01-19','12:00:00','B'),
(7019,7,'2026-01-19','13:00:00','B'),
(7020,7,'2026-01-20','10:00:00','B'),
(7021,7,'2026-01-20','11:00:00','B'),
(7022,7,'2026-01-20','12:00:00','B'),
(7023,7,'2026-01-20','13:00:00','B'),
(7024,7,'2026-01-21','10:00:00','B'),
(7025,7,'2026-01-21','11:00:00','B'),
(7026,7,'2026-01-21','12:00:00','B'),
(7027,7,'2026-01-21','13:00:00','B'),
(7028,7,'2026-01-22','10:00:00','B'),
(7029,7,'2026-01-22','11:00:00','B'),
(7030,7,'2026-01-22','12:00:00','B'),
(7031,7,'2026-01-22','13:00:00','B'),
(7032,7,'2026-01-23','10:00:00','B'),
(7033,7,'2026-01-23','11:00:00','B'),
(7034,7,'2026-01-23','12:00:00','B'),
(7035,7,'2026-01-23','13:00:00','B'),
(7036,7,'2026-01-26','10:00:00','B'),
(7037,7,'2026-01-26','11:00:00','B'),
(7038,7,'2026-01-26','12:00:00','B'),
(7039,7,'2026-01-26','13:00:00','B'),
(7040,7,'2026-01-27','10:00:00','B'),
(7041,7,'2026-01-27','11:00:00','B'),
(7042,7,'2026-01-27','12:00:00','B'),
(7043,7,'2026-01-27','13:00:00','B'),
(7044,7,'2026-01-28','10:00:00','B'),
(7045,7,'2026-01-28','11:00:00','B'),
(7046,7,'2026-01-28','12:00:00','B'),
(7047,7,'2026-01-28','13:00:00','B'),
(7048,7,'2026-01-29','10:00:00','B'),
(7049,7,'2026-01-29','11:00:00','B'),
(7050,7,'2026-01-29','12:00:00','B'),
(7051,7,'2026-01-29','13:00:00','B'),
(7052,7,'2026-01-30','10:00:00','B'),
(7053,7,'2026-01-30','11:00:00','B'),
(7054,7,'2026-01-30','12:00:00','B'),
(7055,7,'2026-01-30','13:00:00','B'),
(7056,7,'2026-02-02','10:00:00','B'),
(7057,7,'2026-02-02','11:00:00','B'),
(7058,7,'2026-02-02','12:00:00','B'),
(7059,7,'2026-02-02','13:00:00','B'),
(7060,7,'2026-02-03','10:00:00','B'),
(7061,7,'2026-02-03','11:00:00','B'),
(7062,7,'2026-02-03','12:00:00','B'),
(7063,7,'2026-02-03','13:00:00','B'),
(7064,7,'2026-02-04','10:00:00','B'),
(7065,7,'2026-02-04','11:00:00','B'),
(7066,7,'2026-02-04','12:00:00','B'),
(7067,7,'2026-02-04','13:00:00','B'),
(7068,7,'2026-02-05','10:00:00','B'),
(7069,7,'2026-02-05','11:00:00','B'),
(7070,7,'2026-02-05','12:00:00','B'),
(7071,7,'2026-02-05','13:00:00','B'),
(7072,7,'2026-02-06','10:00:00','B'),
(7073,7,'2026-02-06','11:00:00','B'),
(7074,7,'2026-02-06','12:00:00','B'),
(7075,7,'2026-02-06','13:00:00','B'),
(7076,7,'2026-02-09','10:00:00','B'),
(7077,7,'2026-02-09','11:00:00','B'),
(7078,7,'2026-02-09','12:00:00','B'),
(7079,7,'2026-02-09','13:00:00','B'),
(7080,7,'2026-02-10','10:00:00','B'),
(7081,7,'2026-02-10','11:00:00','B'),
(7082,7,'2026-02-10','12:00:00','B'),
(7083,7,'2026-02-10','13:00:00','B'),
(7084,7,'2026-02-11','10:00:00','B'),
(7085,7,'2026-02-11','11:00:00','B'),
(7086,7,'2026-02-11','12:00:00','B'),
(7087,7,'2026-02-11','13:00:00','B'),
(7088,7,'2026-02-12','10:00:00','B'),
(7089,7,'2026-02-12','11:00:00','B'),
(7090,7,'2026-02-12','12:00:00','B'),
(7091,7,'2026-02-12','13:00:00','B'),
(7092,7,'2026-02-13','10:00:00','B'),
(7093,7,'2026-02-13','11:00:00','B'),
(7094,7,'2026-02-13','12:00:00','B'),
(7095,7,'2026-02-13','13:00:00','B'),
(7096,7,'2026-02-16','10:00:00','B'),
(7097,7,'2026-02-16','11:00:00','B'),
(7098,7,'2026-02-16','12:00:00','B'),
(7099,7,'2026-02-16','13:00:00','B'),
(7100,7,'2026-02-17','10:00:00','B'),
(7101,7,'2026-02-17','11:00:00','B'),
(7102,7,'2026-02-17','12:00:00','B'),
(7103,7,'2026-02-17','13:00:00','B'),
(7104,7,'2026-02-18','10:00:00','B'),
(7105,7,'2026-02-18','11:00:00','B'),
(7106,7,'2026-02-18','12:00:00','B'),
(7107,7,'2026-02-18','13:00:00','B'),
(7108,7,'2026-02-19','10:00:00','B'),
(7109,7,'2026-02-19','11:00:00','B'),
(7110,7,'2026-02-19','12:00:00','B'),
(7111,7,'2026-02-19','13:00:00','B'),
(7112,7,'2026-02-20','10:00:00','B'),
(7113,7,'2026-02-20','11:00:00','B'),
(7114,7,'2026-02-20','12:00:00','B'),
(7115,7,'2026-02-20','13:00:00','B'),
(7116,7,'2026-02-23','10:00:00','B'),
(7117,7,'2026-02-23','11:00:00','B'),
(7118,7,'2026-02-23','12:00:00','B'),
(7119,7,'2026-02-23','13:00:00','B'),
(7120,7,'2026-02-24','10:00:00','B'),
(7121,7,'2026-02-24','11:00:00','B'),
(7122,7,'2026-02-24','12:00:00','B'),
(7123,7,'2026-02-24','13:00:00','B'),
(7124,7,'2026-02-25','10:00:00','B'),
(7125,7,'2026-02-25','11:00:00','B'),
(7126,7,'2026-02-25','12:00:00','B'),
(7127,7,'2026-02-25','13:00:00','B'),
(7128,7,'2026-02-26','10:00:00','B'),
(7129,7,'2026-02-26','11:00:00','B'),
(7130,7,'2026-02-26','12:00:00','B'),
(7131,7,'2026-02-26','13:00:00','B'),
(7132,7,'2026-02-27','10:00:00','B'),
(7133,7,'2026-02-27','11:00:00','B'),
(7134,7,'2026-02-27','12:00:00','B'),
(7135,7,'2026-02-27','13:00:00','B'),
(7136,7,'2026-03-02','10:00:00','B'),
(7137,7,'2026-03-02','11:00:00','B'),
(7138,7,'2026-03-02','12:00:00','B'),
(7139,7,'2026-03-02','13:00:00','B'),
(7140,7,'2026-03-03','10:00:00','B'),
(7141,7,'2026-03-03','11:00:00','B'),
(7142,7,'2026-03-03','12:00:00','B'),
(7143,7,'2026-03-03','13:00:00','B'),
(7144,7,'2026-03-04','10:00:00','B'),
(7145,7,'2026-03-04','11:00:00','B'),
(7146,7,'2026-03-04','12:00:00','B'),
(7147,7,'2026-03-04','13:00:00','B'),
(7148,7,'2026-03-05','10:00:00','B'),
(7149,7,'2026-03-05','11:00:00','B'),
(7150,7,'2026-03-05','12:00:00','B'),
(7151,7,'2026-03-05','13:00:00','B'),
(7152,7,'2026-03-06','10:00:00','B'),
(7153,7,'2026-03-06','11:00:00','B'),
(7154,7,'2026-03-06','12:00:00','B'),
(7155,7,'2026-03-06','13:00:00','B'),
(7156,7,'2026-03-09','10:00:00','B'),
(7157,7,'2026-03-09','11:00:00','B'),
(7158,7,'2026-03-09','12:00:00','B'),
(7159,7,'2026-03-09','13:00:00','B'),
(7160,7,'2026-03-10','10:00:00','B'),
(7161,7,'2026-03-10','11:00:00','B'),
(7162,7,'2026-03-10','12:00:00','B'),
(7163,7,'2026-03-10','13:00:00','B'),
(7164,7,'2026-03-11','10:00:00','B'),
(7165,7,'2026-03-11','11:00:00','B'),
(7166,7,'2026-03-11','12:00:00','B'),
(7167,7,'2026-03-11','13:00:00','B'),
(7168,7,'2026-03-12','10:00:00','B'),
(7169,7,'2026-03-12','11:00:00','B'),
(7170,7,'2026-03-12','12:00:00','B'),
(7171,7,'2026-03-12','13:00:00','B'),
(7172,7,'2026-03-13','10:00:00','B'),
(7173,7,'2026-03-13','11:00:00','B'),
(7174,7,'2026-03-13','12:00:00','B'),
(7175,7,'2026-03-13','13:00:00','B'),
(7176,7,'2026-03-16','10:00:00','B'),
(7177,7,'2026-03-16','11:00:00','B'),
(7178,7,'2026-03-16','12:00:00','B'),
(7179,7,'2026-03-16','13:00:00','B'),
(7180,7,'2026-03-17','10:00:00','B'),
(7181,7,'2026-03-17','11:00:00','B'),
(7182,7,'2026-03-17','12:00:00','B'),
(7183,7,'2026-03-17','13:00:00','B'),
(7184,7,'2026-03-18','10:00:00','B'),
(7185,7,'2026-03-18','11:00:00','B'),
(7186,7,'2026-03-18','12:00:00','B'),
(7187,7,'2026-03-18','13:00:00','B'),
(7188,7,'2026-03-19','10:00:00','B'),
(7189,7,'2026-03-19','11:00:00','B'),
(7190,7,'2026-03-19','12:00:00','B'),
(7191,7,'2026-03-19','13:00:00','B'),
(7192,7,'2026-03-20','10:00:00','B'),
(7193,7,'2026-03-20','11:00:00','B'),
(7194,7,'2026-03-20','12:00:00','B'),
(7195,7,'2026-03-20','13:00:00','B'),
(7196,7,'2026-03-23','10:00:00','B'),
(7197,7,'2026-03-23','11:00:00','B'),
(7198,7,'2026-03-23','12:00:00','B'),
(7199,7,'2026-03-23','13:00:00','B'),
(7200,7,'2026-03-24','10:00:00','B'),
(7201,7,'2026-03-24','11:00:00','B'),
(7202,7,'2026-03-24','12:00:00','B'),
(7203,7,'2026-03-24','13:00:00','B'),
(7204,7,'2026-03-25','10:00:00','B'),
(7205,7,'2026-03-25','11:00:00','B'),
(7206,7,'2026-03-25','12:00:00','B'),
(7207,7,'2026-03-25','13:00:00','B'),
(7208,7,'2026-03-26','10:00:00','B'),
(7209,7,'2026-03-26','11:00:00','B'),
(7210,7,'2026-03-26','12:00:00','B'),
(7211,7,'2026-03-26','13:00:00','B'),
(7212,7,'2026-03-27','10:00:00','B'),
(7213,7,'2026-03-27','11:00:00','B'),
(7214,7,'2026-03-27','12:00:00','B'),
(7215,7,'2026-03-27','13:00:00','B'),
(7216,7,'2026-03-30','10:00:00','B'),
(7217,7,'2026-03-30','11:00:00','B'),
(7218,7,'2026-03-30','12:00:00','B'),
(7219,7,'2026-03-30','13:00:00','B'),
(7220,7,'2026-03-31','10:00:00','B'),
(7221,7,'2026-03-31','11:00:00','B'),
(7222,7,'2026-03-31','12:00:00','B'),
(7223,7,'2026-03-31','13:00:00','B'),
(8018,8,'2025-11-24','08:00:00','B'),
(8019,8,'2025-11-25','08:00:00','B'),
(8020,8,'2025-11-26','08:00:00','B'),
(8021,8,'2025-11-27','08:00:00','B'),
(8022,8,'2025-11-28','08:00:00','B'),
(8023,9,'2025-11-28','11:00:00','B'),
(8024,9,'2025-11-29','11:00:00','B'),
(8025,9,'2025-11-30','11:00:00','B'),
(8026,9,'2025-12-01','11:00:00','B'),
(8027,9,'2025-12-02','11:00:00','B'),
(8028,9,'2025-12-03','11:00:00','B'),
(8029,9,'2025-12-04','11:00:00','B'),
(8030,9,'2025-12-05','11:00:00','B');
/*!40000 ALTER TABLE `spoty_emisje` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `system_logs`
--

DROP TABLE IF EXISTS `system_logs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `system_logs` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `user_id` int(11) NOT NULL,
  `action` varchar(50) NOT NULL,
  `message` text NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_system_logs_user` (`user_id`),
  KEY `idx_system_logs_action` (`action`)
) ENGINE=InnoDB AUTO_INCREMENT=5 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `system_logs`
--

LOCK TABLES `system_logs` WRITE;
/*!40000 ALTER TABLE `system_logs` DISABLE KEYS */;
INSERT INTO `system_logs` VALUES
(1,'2026-02-18 13:22:16',1,'sales_target_update','Cel sprzedażowy: user_id=3, 2026-02, kwota=0.00'),
(2,'2026-02-18 13:22:16',1,'sales_target_update','Cel sprzedażowy: user_id=2, 2026-02, kwota=0.00'),
(3,'2026-02-18 13:22:31',1,'sales_target_update','Cel sprzedażowy: user_id=3, 2026-02, kwota=5000.00'),
(4,'2026-02-18 13:22:31',1,'sales_target_update','Cel sprzedażowy: user_id=2, 2026-02, kwota=5000.00');
/*!40000 ALTER TABLE `system_logs` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `uzytkownicy`
--

DROP TABLE IF EXISTS `uzytkownicy`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `uzytkownicy` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `login` varchar(50) NOT NULL,
  `haslo_hash` varchar(255) NOT NULL,
  `rola` varchar(32) NOT NULL DEFAULT 'Handlowiec',
  `data_utworzenia` timestamp NULL DEFAULT current_timestamp(),
  `reset_token` varchar(255) DEFAULT NULL,
  `reset_token_expires` datetime DEFAULT NULL,
  `smtp_user` varchar(255) DEFAULT NULL,
  `smtp_pass` varchar(255) DEFAULT NULL,
  `smtp_from_email` varchar(255) DEFAULT NULL,
  `smtp_from_name` varchar(255) DEFAULT NULL,
  `email_signature` text DEFAULT NULL,
  `use_system_smtp` tinyint(1) NOT NULL DEFAULT 0,
  `email` varchar(255) DEFAULT NULL,
  `aktywny` tinyint(1) NOT NULL DEFAULT 1,
  `imie` varchar(100) DEFAULT NULL,
  `nazwisko` varchar(100) DEFAULT NULL,
  `telefon` varchar(50) DEFAULT NULL,
  `funkcja` varchar(150) DEFAULT NULL,
  `commission_enabled` tinyint(1) NOT NULL DEFAULT 0,
  `commission_rate_percent` decimal(5,2) NOT NULL DEFAULT 0.00,
  `prov_new_contract_pct` decimal(5,2) NOT NULL DEFAULT 0.00,
  `prov_renewal_pct` decimal(5,2) NOT NULL DEFAULT 0.00,
  `prov_next_invoice_pct` decimal(5,2) NOT NULL DEFAULT 0.00,
  `smtp_pass_enc` text DEFAULT NULL,
  `smtp_host` varchar(255) DEFAULT NULL,
  `smtp_port` int(11) DEFAULT NULL,
  `smtp_secure` varchar(10) NOT NULL DEFAULT 'tls',
  `smtp_enabled` tinyint(1) NOT NULL DEFAULT 0,
  `imap_host` varchar(255) DEFAULT NULL,
  `imap_port` int(11) DEFAULT NULL,
  `imap_user` varchar(255) DEFAULT NULL,
  `imap_pass_enc` text DEFAULT NULL,
  `imap_secure` varchar(10) NOT NULL DEFAULT 'tls',
  `imap_enabled` tinyint(1) NOT NULL DEFAULT 0,
  `imap_mailbox` varchar(255) NOT NULL DEFAULT 'INBOX',
  `imap_last_uid` int(11) DEFAULT NULL,
  `imap_last_sync_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `login` (`login`)
) ENGINE=InnoDB AUTO_INCREMENT=5 DEFAULT CHARSET=latin2 COLLATE=latin2_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `uzytkownicy`
--

LOCK TABLES `uzytkownicy` WRITE;
/*!40000 ALTER TABLE `uzytkownicy` DISABLE KEYS */;
INSERT INTO `uzytkownicy` VALUES
(1,'admin','$2y$12$ly1EYCCCExHCiou2D/A7LOvz1MJd5lmimSYbIuer3n2d551neRvNe','Administrator','2025-03-24 20:58:41',NULL,NULL,NULL,NULL,NULL,NULL,NULL,0,'ceo@ohmg.pl',1,'Karol','Pufal','881659971',NULL,0,0.00,0.00,0.00,0.00,NULL,NULL,NULL,'tls',0,NULL,NULL,NULL,NULL,'tls',0,'INBOX',NULL,NULL),
(2,'testuser','$2y$10$i4L6rGvR40liVUlP7mdG8ecV5P8vjhyIlmlWPuvRLrp2sfenCeB/C','Handlowiec','2025-03-24 20:58:41',NULL,NULL,NULL,NULL,NULL,NULL,NULL,0,'jan@testowe.pl',1,'Jan','Kowalski','884958958',NULL,1,20.00,0.00,0.00,0.00,NULL,NULL,NULL,'tls',0,NULL,NULL,NULL,NULL,'tls',0,'INBOX',NULL,NULL),
(3,'Testowy','$2y$10$RVHjJExuGH1BH9EwXbIwEeVdOuKls8x3q8SEMKOYtr.REGmcr0kru','Handlowiec','2025-12-19 20:43:41',NULL,NULL,NULL,NULL,NULL,NULL,NULL,0,'reklama@radiozulawy.pl',1,'Adam','Nowak','55 621 30 20',NULL,0,0.00,0.00,0.00,0.00,NULL,NULL,NULL,'tls',0,NULL,NULL,NULL,NULL,'tls',0,'INBOX',NULL,NULL),
(4,'KarolP','$2y$10$sqbaQZ0NWCkUsPUEFJaPNO5.7JG854P41FOTMS7NMw18uYRoaJ/R2','Manager','2026-02-18 19:52:01',NULL,NULL,NULL,NULL,NULL,NULL,NULL,0,'ceo@ohmg.pl',1,'Karol','Pufal','881659971',NULL,0,0.00,0.00,0.00,0.00,NULL,NULL,NULL,'tls',0,NULL,NULL,NULL,NULL,'tls',0,'INBOX',NULL,NULL);
/*!40000 ALTER TABLE `uzytkownicy` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `worker_locks`
--

DROP TABLE IF EXISTS `worker_locks`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `worker_locks` (
  `name` varchar(50) NOT NULL,
  `locked_by` varchar(120) DEFAULT NULL,
  `locked_at` datetime DEFAULT NULL,
  `heartbeat_at` datetime DEFAULT NULL,
  `expires_at` datetime DEFAULT NULL,
  `meta_json` text DEFAULT NULL,
  PRIMARY KEY (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `worker_locks`
--

LOCK TABLES `worker_locks` WRITE;
/*!40000 ALTER TABLE `worker_locks` DISABLE KEYS */;
/*!40000 ALTER TABLE `worker_locks` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Dumping routines for database 'crm_dev'
--
/*!40103 SET TIME_ZONE=@OLD_TIME_ZONE */;

/*!40101 SET SQL_MODE=@OLD_SQL_MODE */;
/*!40014 SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS */;
/*!40014 SET UNIQUE_CHECKS=@OLD_UNIQUE_CHECKS */;
/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
/*!40111 SET SQL_NOTES=@OLD_SQL_NOTES */;

-- Dump completed on 2026-02-25 23:23:24
