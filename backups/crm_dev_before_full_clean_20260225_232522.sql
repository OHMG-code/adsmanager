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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `activity_log`
--

LOCK TABLES `activity_log` WRITE;
/*!40000 ALTER TABLE `activity_log` DISABLE KEYS */;
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `cele_sprzedazowe`
--

LOCK TABLES `cele_sprzedazowe` WRITE;
/*!40000 ALTER TABLE `cele_sprzedazowe` DISABLE KEYS */;
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
) ENGINE=InnoDB DEFAULT CHARSET=latin2 COLLATE=latin2_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `cennik_display`
--

LOCK TABLES `cennik_display` WRITE;
/*!40000 ALTER TABLE `cennik_display` DISABLE KEYS */;
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `cennik_patronat`
--

LOCK TABLES `cennik_patronat` WRITE;
/*!40000 ALTER TABLE `cennik_patronat` DISABLE KEYS */;
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
) ENGINE=InnoDB DEFAULT CHARSET=latin2 COLLATE=latin2_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `cennik_social`
--

LOCK TABLES `cennik_social` WRITE;
/*!40000 ALTER TABLE `cennik_social` DISABLE KEYS */;
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
) ENGINE=InnoDB DEFAULT CHARSET=latin2 COLLATE=latin2_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `cennik_spoty`
--

LOCK TABLES `cennik_spoty` WRITE;
/*!40000 ALTER TABLE `cennik_spoty` DISABLE KEYS */;
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
) ENGINE=InnoDB DEFAULT CHARSET=latin2 COLLATE=latin2_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `cennik_sygnaly`
--

LOCK TABLES `cennik_sygnaly` WRITE;
/*!40000 ALTER TABLE `cennik_sygnaly` DISABLE KEYS */;
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
) ENGINE=InnoDB DEFAULT CHARSET=latin2 COLLATE=latin2_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `cennik_wywiady`
--

LOCK TABLES `cennik_wywiady` WRITE;
/*!40000 ALTER TABLE `cennik_wywiady` DISABLE KEYS */;
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `companies`
--

LOCK TABLES `companies` WRITE;
/*!40000 ALTER TABLE `companies` DISABLE KEYS */;
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `crm_aktywnosci`
--

LOCK TABLES `crm_aktywnosci` WRITE;
/*!40000 ALTER TABLE `crm_aktywnosci` DISABLE KEYS */;
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `crm_statusy`
--

LOCK TABLES `crm_statusy` WRITE;
/*!40000 ALTER TABLE `crm_statusy` DISABLE KEYS */;
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `crm_zadania`
--

LOCK TABLES `crm_zadania` WRITE;
/*!40000 ALTER TABLE `crm_zadania` DISABLE KEYS */;
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `gus_cache`
--

LOCK TABLES `gus_cache` WRITE;
/*!40000 ALTER TABLE `gus_cache` DISABLE KEYS */;
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `gus_snapshots`
--

LOCK TABLES `gus_snapshots` WRITE;
/*!40000 ALTER TABLE `gus_snapshots` DISABLE KEYS */;
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `historia_maili_ofert`
--

LOCK TABLES `historia_maili_ofert` WRITE;
/*!40000 ALTER TABLE `historia_maili_ofert` DISABLE KEYS */;
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `integration_circuit_breaker`
--

LOCK TABLES `integration_circuit_breaker` WRITE;
/*!40000 ALTER TABLE `integration_circuit_breaker` DISABLE KEYS */;
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `integrations_logs`
--

LOCK TABLES `integrations_logs` WRITE;
/*!40000 ALTER TABLE `integrations_logs` DISABLE KEYS */;
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
) ENGINE=InnoDB DEFAULT CHARSET=latin2 COLLATE=latin2_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `kampanie`
--

LOCK TABLES `kampanie` WRITE;
/*!40000 ALTER TABLE `kampanie` DISABLE KEYS */;
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
) ENGINE=InnoDB DEFAULT CHARSET=latin2 COLLATE=latin2_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `kampanie_emisje`
--

LOCK TABLES `kampanie_emisje` WRITE;
/*!40000 ALTER TABLE `kampanie_emisje` DISABLE KEYS */;
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `kampanie_tygodniowe`
--

LOCK TABLES `kampanie_tygodniowe` WRITE;
/*!40000 ALTER TABLE `kampanie_tygodniowe` DISABLE KEYS */;
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
) ENGINE=InnoDB DEFAULT CHARSET=latin2 COLLATE=latin2_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `klienci`
--

LOCK TABLES `klienci` WRITE;
/*!40000 ALTER TABLE `klienci` DISABLE KEYS */;
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
(1,2,'07:00:00','23:00:00','2','06:00-09:59,15:00-18:59','10:00-14:59,19:00-22:59','00:00-05:59,23:00-23:59','uploads/settings/mediaplan_logo_1765662090.png','server480824.nazwa.pl',465,'ssl',1,'reklama@radiozulawy.pl','Radio Żuławy','reklama@radiozulawy.pl','Radio1234',NULL,3600,3600,3600,'2026-02-25 23:24:07',10,50,'wav,mp3',1,'bfb4e8dec7ea4129ab5a','prod',30,'OHMG Sp. z o.o.','Grobelno 8','5792268412','office@ohmg.pl',NULL,'storage/docs/','AM/',0,20,30,60,NULL,0,180);
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `leady`
--

LOCK TABLES `leady` WRITE;
/*!40000 ALTER TABLE `leady` DISABLE KEYS */;
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `leady_aktywnosci`
--

LOCK TABLES `leady_aktywnosci` WRITE;
/*!40000 ALTER TABLE `leady_aktywnosci` DISABLE KEYS */;
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `mail_accounts`
--

LOCK TABLES `mail_accounts` WRITE;
/*!40000 ALTER TABLE `mail_accounts` DISABLE KEYS */;
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `mail_attachments`
--

LOCK TABLES `mail_attachments` WRITE;
/*!40000 ALTER TABLE `mail_attachments` DISABLE KEYS */;
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `mail_messages`
--

LOCK TABLES `mail_messages` WRITE;
/*!40000 ALTER TABLE `mail_messages` DISABLE KEYS */;
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `mail_threads`
--

LOCK TABLES `mail_threads` WRITE;
/*!40000 ALTER TABLE `mail_threads` DISABLE KEYS */;
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `powiadomienia`
--

LOCK TABLES `powiadomienia` WRITE;
/*!40000 ALTER TABLE `powiadomienia` DISABLE KEYS */;
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
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
) ENGINE=InnoDB DEFAULT CHARSET=latin2 COLLATE=latin2_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `spoty`
--

LOCK TABLES `spoty` WRITE;
/*!40000 ALTER TABLE `spoty` DISABLE KEYS */;
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
) ENGINE=InnoDB DEFAULT CHARSET=latin2 COLLATE=latin2_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `spoty_emisje`
--

LOCK TABLES `spoty_emisje` WRITE;
/*!40000 ALTER TABLE `spoty_emisje` DISABLE KEYS */;
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `system_logs`
--

LOCK TABLES `system_logs` WRITE;
/*!40000 ALTER TABLE `system_logs` DISABLE KEYS */;
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

-- Dump completed on 2026-02-25 23:25:23
