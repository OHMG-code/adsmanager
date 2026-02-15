-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: mysql8
-- Generation Time: Dec 29, 2025 at 01:55 AM
-- Wersja serwera: 8.0.33-25
-- Wersja PHP: 8.2.29

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Database: `01214144_crm`
--

-- --------------------------------------------------------

--
-- Struktura tabeli dla tabeli `cele_sprzedazowe`
--

CREATE TABLE `cele_sprzedazowe` (
  `id` int NOT NULL,
  `year` smallint NOT NULL,
  `month` tinyint NOT NULL,
  `user_id` int NOT NULL,
  `target_netto` decimal(12,2) NOT NULL DEFAULT '0.00',
  `created_by_user_id` int NOT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Struktura tabeli dla tabeli `cennik_display`
--

CREATE TABLE `cennik_display` (
  `id` int NOT NULL,
  `format` varchar(100) NOT NULL,
  `opis` text,
  `stawka_netto` decimal(10,2) NOT NULL,
  `stawka_vat` decimal(5,2) DEFAULT '23.00',
  `data_modyfikacji` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=latin2;

--
-- Dumping data for table `cennik_display`
--

INSERT INTO `cennik_display` (`id`, `format`, `opis`, `stawka_netto`, `stawka_vat`, `data_modyfikacji`) VALUES
(1, 'Góra', 'Górna część strony www najbardziej widoczna o wymirach 1200 x 250 px', 200.00, 23.00, '2025-12-14 17:20:01'),
(2, 'Top - 1', 'Górna część strony obok informacji \"Co na antenie\"', 200.00, 23.00, '2025-05-24 19:15:42'),
(3, 'Right - 1', 'Panel reklamowy z prawej strony o wymiarach 240 x 720 px', 120.00, 23.00, '2025-05-24 19:17:41');

-- --------------------------------------------------------

--
-- Struktura tabeli dla tabeli `cennik_kalkulator`
--

CREATE TABLE `cennik_kalkulator` (
  `id` int NOT NULL,
  `godzina_start` time NOT NULL,
  `godzina_end` time NOT NULL,
  `stawka_netto` decimal(10,2) NOT NULL,
  `opis` varchar(255) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=latin2;

-- --------------------------------------------------------

--
-- Struktura tabeli dla tabeli `cennik_social`
--

CREATE TABLE `cennik_social` (
  `id` int NOT NULL,
  `platforma` varchar(100) NOT NULL,
  `rodzaj_postu` varchar(100) DEFAULT NULL,
  `stawka_netto` decimal(10,2) NOT NULL,
  `stawka_vat` decimal(5,2) DEFAULT '23.00',
  `data_modyfikacji` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=latin2;

--
-- Dumping data for table `cennik_social`
--

INSERT INTO `cennik_social` (`id`, `platforma`, `rodzaj_postu`, `stawka_netto`, `stawka_vat`, `data_modyfikacji`) VALUES
(1, 'Artykuł na platformie Facebook', 'Post sponsorowany z grafiką', 250.00, 23.00, '2025-05-24 19:18:17');

-- --------------------------------------------------------

--
-- Struktura tabeli dla tabeli `cennik_spoty`
--

CREATE TABLE `cennik_spoty` (
  `id` int NOT NULL,
  `dlugosc` enum('15','20','30') NOT NULL,
  `pasmo` enum('Prime Time','Standard Time','Night Time') NOT NULL,
  `stawka_netto` decimal(10,2) NOT NULL,
  `stawka_vat` decimal(5,2) DEFAULT '23.00',
  `data_modyfikacji` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=latin2;

--
-- Dumping data for table `cennik_spoty`
--

INSERT INTO `cennik_spoty` (`id`, `dlugosc`, `pasmo`, `stawka_netto`, `stawka_vat`, `data_modyfikacji`) VALUES
(1, '15', 'Prime Time', 12.00, 23.00, '2025-05-24 17:47:25'),
(2, '15', 'Standard Time', 9.00, 23.00, '2025-05-24 17:47:36'),
(4, '20', 'Prime Time', 16.00, 23.00, '2025-05-24 17:47:52'),
(5, '20', 'Standard Time', 12.00, 23.00, '2025-05-24 17:48:02'),
(6, '20', 'Night Time', 8.00, 23.00, '2025-05-24 17:48:10'),
(7, '30', 'Prime Time', 20.00, 23.00, '2025-12-17 15:32:23'),
(9, '30', 'Standard Time', 18.00, 23.00, '2025-12-17 15:32:23'),
(10, '30', 'Night Time', 17.00, 23.00, '2025-12-17 15:32:23'),
(11, '15', 'Night Time', 6.00, 23.00, '2025-05-24 18:55:11');

-- --------------------------------------------------------

--
-- Struktura tabeli dla tabeli `cennik_sygnaly`
--

CREATE TABLE `cennik_sygnaly` (
  `id` int NOT NULL,
  `typ_programu` enum('Prognoza pogody','Serwis drogowy','Sponsor ogólny','Sponsor programu') NOT NULL,
  `stawka_netto` decimal(10,2) NOT NULL,
  `stawka_vat` decimal(5,2) DEFAULT '23.00',
  `data_modyfikacji` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=latin2;

--
-- Dumping data for table `cennik_sygnaly`
--

INSERT INTO `cennik_sygnaly` (`id`, `typ_programu`, `stawka_netto`, `stawka_vat`, `data_modyfikacji`) VALUES
(1, 'Prognoza pogody', 1100.00, 23.00, '2025-05-24 19:12:26'),
(2, 'Serwis drogowy', 750.00, 23.00, '2025-05-24 19:12:51');

-- --------------------------------------------------------

--
-- Struktura tabeli dla tabeli `cennik_wywiady`
--

CREATE TABLE `cennik_wywiady` (
  `id` int NOT NULL,
  `nazwa` varchar(255) NOT NULL,
  `opis` text,
  `stawka_netto` decimal(10,2) NOT NULL,
  `stawka_vat` decimal(5,2) DEFAULT '23.00',
  `data_modyfikacji` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=latin2;

--
-- Dumping data for table `cennik_wywiady`
--

INSERT INTO `cennik_wywiady` (`id`, `nazwa`, `opis`, `stawka_netto`, `stawka_vat`, `data_modyfikacji`) VALUES
(1, 'Wywiad do 15 minut', '', 500.00, 23.00, '2025-05-24 19:13:33'),
(2, 'Wywiad 15 - 30 minut', '', 700.00, 23.00, '2025-05-24 19:13:54');

-- --------------------------------------------------------

--
-- Struktura tabeli dla tabeli `dokumenty`
--

CREATE TABLE `dokumenty` (
  `id` int NOT NULL,
  `doc_type` varchar(30) NOT NULL,
  `doc_number` varchar(50) NOT NULL,
  `client_id` int NOT NULL,
  `kampania_id` int DEFAULT NULL,
  `created_by_user_id` int NOT NULL,
  `stored_filename` varchar(255) NOT NULL,
  `original_filename` varchar(255) NOT NULL,
  `sha256` char(64) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Struktura tabeli dla tabeli `emisje`
--

CREATE TABLE `emisje` (
  `id` int NOT NULL,
  `kampania_id` int NOT NULL,
  `dzien` enum('pon','wt','sr','czw','pt','sob','ndz') COLLATE utf8mb4_general_ci NOT NULL,
  `godzina` time NOT NULL,
  `liczba` int NOT NULL DEFAULT '1'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Struktura tabeli dla tabeli `emisje_spotow`
--

CREATE TABLE `emisje_spotow` (
  `id` int NOT NULL,
  `spot_id` int NOT NULL,
  `dow` tinyint NOT NULL,
  `godzina` time NOT NULL,
  `liczba` int NOT NULL DEFAULT '1',
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Struktura tabeli dla tabeli `gus_cache`
--

CREATE TABLE `gus_cache` (
  `id` int NOT NULL,
  `nip` varchar(20) DEFAULT NULL,
  `regon` varchar(20) DEFAULT NULL,
  `data_json` longtext NOT NULL,
  `fetched_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `source` varchar(20) NOT NULL DEFAULT 'gus'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Struktura tabeli dla tabeli `historia_maili_ofert`
--

CREATE TABLE `historia_maili_ofert` (
  `id` int NOT NULL,
  `kampania_id` int NOT NULL,
  `klient_id` int DEFAULT NULL,
  `user_id` int NOT NULL,
  `to_email` varchar(255) COLLATE utf8mb4_general_ci NOT NULL,
  `subject` varchar(255) COLLATE utf8mb4_general_ci NOT NULL,
  `status` varchar(20) COLLATE utf8mb4_general_ci NOT NULL,
  `error_msg` text COLLATE utf8mb4_general_ci,
  `sent_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `lead_id` int DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Struktura tabeli dla tabeli `integrations_logs`
--

CREATE TABLE `integrations_logs` (
  `id` int NOT NULL,
  `log_time` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `user_id` int DEFAULT NULL,
  `type` varchar(30) NOT NULL,
  `request_id` varchar(100) DEFAULT NULL,
  `message` text NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Struktura tabeli dla tabeli `kampanie`
--

CREATE TABLE `kampanie` (
  `id` int NOT NULL,
  `klient_id` int DEFAULT NULL,
  `klient_nazwa` varchar(255) DEFAULT NULL,
  `dlugosc_spotu` int DEFAULT NULL,
  `data_start` date DEFAULT NULL,
  `data_koniec` date DEFAULT NULL,
  `rabat` decimal(5,2) DEFAULT '0.00',
  `netto_spoty` decimal(10,2) DEFAULT NULL,
  `netto_dodatki` decimal(10,2) DEFAULT NULL,
  `razem_netto` decimal(10,2) DEFAULT NULL,
  `razem_brutto` decimal(10,2) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `propozycja` tinyint(1) DEFAULT '0',
  `audio_file` varchar(255) DEFAULT NULL,
  `owner_user_id` int DEFAULT NULL,
  `status` varchar(50) NOT NULL DEFAULT 'W realizacji',
  `wartosc_netto` decimal(12,2) NOT NULL DEFAULT '0.00'
) ENGINE=InnoDB DEFAULT CHARSET=latin2;

--
-- Dumping data for table `kampanie`
--

INSERT INTO `kampanie` (`id`, `klient_id`, `klient_nazwa`, `dlugosc_spotu`, `data_start`, `data_koniec`, `rabat`, `netto_spoty`, `netto_dodatki`, `razem_netto`, `razem_brutto`, `created_at`, `propozycja`, `audio_file`, `owner_user_id`, `status`, `wartosc_netto`) VALUES
(15, NULL, 'SALESYSTEMS', 20, '2025-12-29', '2026-01-30', 0.00, 1100.00, 0.00, 1100.00, 1353.00, '2025-12-13 21:25:17', 0, NULL, NULL, 'Zakończona', 0.00),
(17, NULL, 'UM Elbląg', 20, '2026-01-01', '2026-02-18', 0.00, 2268.00, 0.00, 2268.00, 2789.64, '2025-12-13 21:49:04', 0, NULL, NULL, 'Zakończona', 0.00),
(18, NULL, 'OMODA', 30, '2026-01-01', '2026-02-26', 0.00, 2907.00, 0.00, 2907.00, 3575.61, '2025-12-13 21:51:41', 0, NULL, NULL, 'Zakończona', 0.00),
(19, NULL, 'Salon Daewoo', 20, '2025-12-19', '2026-02-26', 0.00, 2920.00, 0.00, 2920.00, 3591.60, '2025-12-13 22:02:10', 0, NULL, NULL, 'Zakończona', 0.00),
(20, NULL, 'Test', 15, '2025-12-15', '2026-03-17', 0.00, 2757.00, 0.00, 2757.00, 3391.11, '2025-12-13 22:04:59', 0, NULL, NULL, 'Zakończona', 0.00),
(21, NULL, 'OHMG', 20, '2025-12-15', '2026-03-19', 0.00, 3608.00, 0.00, 3608.00, 4437.84, '2025-12-13 22:18:57', 0, NULL, NULL, 'Zakończona', 0.00),
(22, NULL, 'Test', 20, '2025-12-16', '2026-02-18', 0.00, 564.00, 0.00, 564.00, 693.72, '2025-12-13 22:28:31', 0, NULL, NULL, 'Zakończona', 0.00),
(23, NULL, 'Test', 20, '2025-12-15', '2026-03-11', 0.00, 1764.00, 0.00, 1764.00, 2169.72, '2025-12-13 22:30:40', 0, NULL, NULL, 'Zakończona', 0.00),
(24, NULL, 'Salon Daewoo', 20, '2025-12-22', '2026-01-30', 0.00, 780.00, 0.00, 780.00, 959.40, '2025-12-13 23:23:38', 0, NULL, NULL, 'Zakończona', 0.00),
(25, NULL, 'tyerhjrh', 20, '2025-12-17', '2025-12-31', 0.00, 252.00, 0.00, 252.00, 309.96, '2025-12-16 18:28:10', 0, NULL, NULL, 'Zakończona', 0.00),
(26, NULL, 'Omoda & Jaecoo', 30, '2025-12-18', '2026-01-06', 0.00, 4600.00, 0.00, 4600.00, 5658.00, '2025-12-17 15:39:58', 0, NULL, NULL, 'Zakończona', 0.00),
(27, NULL, 'OMODA', 30, '2025-12-18', '2026-01-06', 0.00, 3400.00, 0.00, 3400.00, 4182.00, '2025-12-19 20:45:31', 0, NULL, NULL, 'Zakończona', 0.00),
(28, NULL, 'ELRO 2', 20, '2026-01-01', '2026-01-31', 6.00, 1232.00, 0.00, 1158.08, 1424.44, '2025-12-28 16:15:34', 0, NULL, NULL, 'Zakończona', 0.00);

-- --------------------------------------------------------

--
-- Struktura tabeli dla tabeli `kampanie_emisje`
--

CREATE TABLE `kampanie_emisje` (
  `id` int NOT NULL,
  `kampania_id` int NOT NULL,
  `dzien_tygodnia` enum('mon','tue','wed','thu','fri','sat','sun') DEFAULT NULL,
  `godzina` time DEFAULT NULL,
  `ilosc` int DEFAULT '0'
) ENGINE=InnoDB DEFAULT CHARSET=latin2;

--
-- Dumping data for table `kampanie_emisje`
--

INSERT INTO `kampanie_emisje` (`id`, `kampania_id`, `dzien_tygodnia`, `godzina`, `ilosc`) VALUES
(127, 15, 'mon', '09:00:00', 1),
(128, 15, 'mon', '12:00:00', 1),
(129, 15, 'mon', '15:00:00', 1),
(130, 15, 'tue', '09:00:00', 1),
(131, 15, 'tue', '12:00:00', 1),
(132, 15, 'tue', '15:00:00', 1),
(133, 15, 'wed', '09:00:00', 1),
(134, 15, 'wed', '12:00:00', 1),
(135, 15, 'wed', '15:00:00', 1),
(136, 15, 'thu', '09:00:00', 1),
(137, 15, 'thu', '12:00:00', 1),
(138, 15, 'thu', '15:00:00', 1),
(139, 15, 'fri', '09:00:00', 1),
(140, 15, 'fri', '12:00:00', 1),
(141, 15, 'fri', '15:00:00', 1),
(162, 17, 'mon', '09:00:00', 1),
(163, 17, 'mon', '13:00:00', 1),
(164, 17, 'mon', '16:00:00', 1),
(165, 17, 'mon', '17:00:00', 1),
(166, 17, 'tue', '09:00:00', 1),
(167, 17, 'tue', '13:00:00', 1),
(168, 17, 'tue', '16:00:00', 1),
(169, 17, 'wed', '09:00:00', 1),
(170, 17, 'wed', '13:00:00', 1),
(171, 17, 'wed', '16:00:00', 1),
(172, 17, 'thu', '09:00:00', 1),
(173, 17, 'thu', '13:00:00', 1),
(174, 17, 'thu', '16:00:00', 1),
(175, 17, 'fri', '09:00:00', 1),
(176, 17, 'fri', '13:00:00', 1),
(177, 17, 'fri', '16:00:00', 1),
(178, 17, 'sat', '09:00:00', 1),
(179, 17, 'sat', '13:00:00', 1),
(180, 17, 'sat', '16:00:00', 1),
(181, 17, 'sun', '09:00:00', 1),
(182, 17, 'sun', '13:00:00', 1),
(183, 17, 'sun', '16:00:00', 1),
(184, 18, 'mon', '09:00:00', 1),
(185, 18, 'mon', '14:00:00', 1),
(186, 18, 'mon', '17:00:00', 1),
(187, 18, 'tue', '09:00:00', 1),
(188, 18, 'tue', '14:00:00', 1),
(189, 18, 'tue', '17:00:00', 1),
(190, 18, 'wed', '09:00:00', 1),
(191, 18, 'wed', '14:00:00', 1),
(192, 18, 'wed', '17:00:00', 1),
(193, 18, 'thu', '09:00:00', 1),
(194, 18, 'thu', '14:00:00', 1),
(195, 18, 'thu', '17:00:00', 1),
(196, 18, 'fri', '09:00:00', 1),
(197, 18, 'fri', '14:00:00', 1),
(198, 18, 'fri', '17:00:00', 1),
(199, 18, 'sat', '09:00:00', 1),
(200, 18, 'sat', '14:00:00', 1),
(201, 18, 'sat', '17:00:00', 1),
(202, 18, 'sun', '09:00:00', 1),
(203, 18, 'sun', '14:00:00', 1),
(204, 18, 'sun', '17:00:00', 1),
(205, 19, 'mon', '08:00:00', 1),
(206, 19, 'mon', '13:00:00', 1),
(207, 19, 'mon', '17:00:00', 1),
(208, 19, 'tue', '08:00:00', 1),
(209, 19, 'tue', '13:00:00', 1),
(210, 19, 'tue', '17:00:00', 1),
(211, 19, 'wed', '08:00:00', 1),
(212, 19, 'wed', '13:00:00', 1),
(213, 19, 'wed', '17:00:00', 1),
(214, 19, 'thu', '08:00:00', 1),
(215, 19, 'thu', '13:00:00', 1),
(216, 19, 'thu', '17:00:00', 1),
(217, 19, 'fri', '08:00:00', 1),
(218, 19, 'fri', '13:00:00', 1),
(219, 19, 'fri', '17:00:00', 1),
(220, 19, 'sat', '08:00:00', 1),
(221, 19, 'sat', '13:00:00', 1),
(222, 19, 'sat', '17:00:00', 1),
(223, 19, 'sun', '13:00:00', 1),
(224, 19, 'sun', '17:00:00', 1),
(225, 20, 'mon', '09:00:00', 1),
(226, 20, 'mon', '15:00:00', 1),
(227, 20, 'mon', '20:00:00', 1),
(228, 20, 'tue', '09:00:00', 1),
(229, 20, 'tue', '15:00:00', 1),
(230, 20, 'tue', '20:00:00', 1),
(231, 20, 'wed', '09:00:00', 1),
(232, 20, 'wed', '15:00:00', 1),
(233, 20, 'wed', '20:00:00', 1),
(234, 20, 'thu', '09:00:00', 1),
(235, 20, 'thu', '15:00:00', 1),
(236, 20, 'thu', '20:00:00', 1),
(237, 20, 'fri', '09:00:00', 1),
(238, 20, 'fri', '15:00:00', 1),
(239, 20, 'fri', '20:00:00', 1),
(240, 20, 'sat', '09:00:00', 1),
(241, 20, 'sat', '15:00:00', 1),
(242, 20, 'sat', '20:00:00', 1),
(243, 20, 'sun', '20:00:00', 1),
(244, 21, 'mon', '09:00:00', 1),
(245, 21, 'mon', '13:00:00', 1),
(246, 21, 'mon', '16:00:00', 1),
(247, 21, 'tue', '09:00:00', 1),
(248, 21, 'tue', '13:00:00', 1),
(249, 21, 'tue', '16:00:00', 1),
(250, 21, 'wed', '09:00:00', 1),
(251, 21, 'wed', '13:00:00', 1),
(252, 21, 'wed', '16:00:00', 1),
(253, 21, 'thu', '09:00:00', 1),
(254, 21, 'thu', '13:00:00', 1),
(255, 21, 'thu', '16:00:00', 1),
(256, 21, 'fri', '09:00:00', 1),
(257, 21, 'fri', '13:00:00', 1),
(258, 21, 'fri', '16:00:00', 1),
(259, 21, 'sat', '09:00:00', 1),
(260, 21, 'sat', '13:00:00', 1),
(261, 21, 'sat', '16:00:00', 1),
(262, 22, 'mon', '10:00:00', 1),
(263, 22, 'tue', '10:00:00', 1),
(264, 22, 'wed', '10:00:00', 1),
(265, 22, 'thu', '10:00:00', 1),
(266, 22, 'fri', '10:00:00', 1),
(267, 23, 'mon', '08:00:00', 1),
(268, 23, 'mon', '12:00:00', 1),
(269, 23, 'tue', '08:00:00', 1),
(270, 23, 'tue', '12:00:00', 1),
(271, 23, 'wed', '08:00:00', 1),
(272, 23, 'wed', '12:00:00', 1),
(273, 23, 'thu', '08:00:00', 1),
(274, 23, 'thu', '12:00:00', 1),
(275, 23, 'fri', '08:00:00', 1),
(276, 23, 'fri', '12:00:00', 1),
(277, 24, 'mon', '11:00:00', 1),
(278, 24, 'mon', '13:00:00', 1),
(279, 24, 'tue', '11:00:00', 1),
(280, 24, 'tue', '13:00:00', 1),
(281, 24, 'wed', '11:00:00', 1),
(282, 24, 'wed', '13:00:00', 1),
(283, 24, 'thu', '11:00:00', 1),
(284, 24, 'thu', '13:00:00', 1),
(285, 24, 'fri', '11:00:00', 1),
(286, 24, 'fri', '13:00:00', 1),
(287, 24, 'sat', '13:00:00', 1),
(288, 25, 'mon', '10:00:00', 1),
(289, 25, 'mon', '12:00:00', 1),
(290, 25, 'mon', '14:00:00', 1),
(291, 25, 'tue', '10:00:00', 1),
(292, 25, 'tue', '12:00:00', 1),
(293, 25, 'wed', '10:00:00', 1),
(294, 25, 'wed', '12:00:00', 1),
(295, 25, 'wed', '14:00:00', 1),
(296, 25, 'thu', '12:00:00', 1),
(297, 26, 'mon', '07:00:00', 1),
(298, 26, 'mon', '08:00:00', 1),
(299, 26, 'mon', '09:00:00', 1),
(300, 26, 'mon', '10:00:00', 1),
(301, 26, 'mon', '11:00:00', 1),
(302, 26, 'mon', '12:00:00', 1),
(303, 26, 'mon', '13:00:00', 1),
(304, 26, 'mon', '14:00:00', 1),
(305, 26, 'mon', '15:00:00', 1),
(306, 26, 'mon', '16:00:00', 1),
(307, 26, 'mon', '17:00:00', 1),
(308, 26, 'mon', '18:00:00', 1),
(309, 26, 'tue', '07:00:00', 1),
(310, 26, 'tue', '08:00:00', 1),
(311, 26, 'tue', '09:00:00', 1),
(312, 26, 'tue', '10:00:00', 1),
(313, 26, 'tue', '11:00:00', 1),
(314, 26, 'tue', '12:00:00', 1),
(315, 26, 'tue', '13:00:00', 1),
(316, 26, 'tue', '14:00:00', 1),
(317, 26, 'tue', '15:00:00', 1),
(318, 26, 'tue', '16:00:00', 1),
(319, 26, 'tue', '17:00:00', 1),
(320, 26, 'tue', '18:00:00', 1),
(321, 26, 'wed', '07:00:00', 1),
(322, 26, 'wed', '08:00:00', 1),
(323, 26, 'wed', '09:00:00', 1),
(324, 26, 'wed', '10:00:00', 1),
(325, 26, 'wed', '11:00:00', 1),
(326, 26, 'wed', '12:00:00', 1),
(327, 26, 'wed', '13:00:00', 1),
(328, 26, 'wed', '14:00:00', 1),
(329, 26, 'wed', '15:00:00', 1),
(330, 26, 'wed', '16:00:00', 1),
(331, 26, 'wed', '17:00:00', 1),
(332, 26, 'wed', '18:00:00', 1),
(333, 26, 'thu', '07:00:00', 1),
(334, 26, 'thu', '08:00:00', 1),
(335, 26, 'thu', '09:00:00', 1),
(336, 26, 'thu', '10:00:00', 1),
(337, 26, 'thu', '11:00:00', 1),
(338, 26, 'thu', '12:00:00', 1),
(339, 26, 'thu', '13:00:00', 1),
(340, 26, 'thu', '14:00:00', 1),
(341, 26, 'thu', '15:00:00', 1),
(342, 26, 'thu', '16:00:00', 1),
(343, 26, 'thu', '17:00:00', 1),
(344, 26, 'thu', '18:00:00', 1),
(345, 26, 'fri', '07:00:00', 1),
(346, 26, 'fri', '08:00:00', 1),
(347, 26, 'fri', '09:00:00', 1),
(348, 26, 'fri', '10:00:00', 1),
(349, 26, 'fri', '11:00:00', 1),
(350, 26, 'fri', '12:00:00', 1),
(351, 26, 'fri', '13:00:00', 1),
(352, 26, 'fri', '14:00:00', 1),
(353, 26, 'fri', '15:00:00', 1),
(354, 26, 'fri', '16:00:00', 1),
(355, 26, 'fri', '17:00:00', 1),
(356, 26, 'fri', '18:00:00', 1),
(357, 26, 'sat', '07:00:00', 1),
(358, 26, 'sat', '08:00:00', 1),
(359, 26, 'sat', '09:00:00', 1),
(360, 26, 'sat', '10:00:00', 1),
(361, 26, 'sat', '11:00:00', 1),
(362, 26, 'sat', '12:00:00', 1),
(363, 26, 'sat', '13:00:00', 1),
(364, 26, 'sat', '14:00:00', 1),
(365, 26, 'sat', '15:00:00', 1),
(366, 26, 'sat', '16:00:00', 1),
(367, 26, 'sat', '17:00:00', 1),
(368, 26, 'sat', '18:00:00', 1),
(369, 26, 'sun', '07:00:00', 1),
(370, 26, 'sun', '08:00:00', 1),
(371, 26, 'sun', '09:00:00', 1),
(372, 26, 'sun', '10:00:00', 1),
(373, 26, 'sun', '11:00:00', 1),
(374, 26, 'sun', '12:00:00', 1),
(375, 26, 'sun', '13:00:00', 1),
(376, 26, 'sun', '14:00:00', 1),
(377, 26, 'sun', '15:00:00', 1),
(378, 26, 'sun', '16:00:00', 1),
(379, 26, 'sun', '17:00:00', 1),
(380, 26, 'sun', '18:00:00', 1),
(381, 27, 'mon', '07:00:00', 1),
(382, 27, 'mon', '08:00:00', 1),
(383, 27, 'mon', '09:00:00', 1),
(384, 27, 'mon', '10:00:00', 1),
(385, 27, 'mon', '11:00:00', 1),
(386, 27, 'mon', '12:00:00', 1),
(387, 27, 'mon', '13:00:00', 1),
(388, 27, 'mon', '14:00:00', 1),
(389, 27, 'mon', '15:00:00', 1),
(390, 27, 'tue', '07:00:00', 1),
(391, 27, 'tue', '08:00:00', 1),
(392, 27, 'tue', '09:00:00', 1),
(393, 27, 'tue', '10:00:00', 1),
(394, 27, 'tue', '11:00:00', 1),
(395, 27, 'tue', '12:00:00', 1),
(396, 27, 'tue', '13:00:00', 1),
(397, 27, 'tue', '14:00:00', 1),
(398, 27, 'tue', '15:00:00', 1),
(399, 27, 'wed', '07:00:00', 1),
(400, 27, 'wed', '08:00:00', 1),
(401, 27, 'wed', '09:00:00', 1),
(402, 27, 'wed', '10:00:00', 1),
(403, 27, 'wed', '11:00:00', 1),
(404, 27, 'wed', '12:00:00', 1),
(405, 27, 'wed', '13:00:00', 1),
(406, 27, 'wed', '14:00:00', 1),
(407, 27, 'wed', '15:00:00', 1),
(408, 27, 'thu', '07:00:00', 1),
(409, 27, 'thu', '08:00:00', 1),
(410, 27, 'thu', '09:00:00', 1),
(411, 27, 'thu', '10:00:00', 1),
(412, 27, 'thu', '11:00:00', 1),
(413, 27, 'thu', '12:00:00', 1),
(414, 27, 'thu', '13:00:00', 1),
(415, 27, 'thu', '14:00:00', 1),
(416, 27, 'thu', '15:00:00', 1),
(417, 27, 'fri', '07:00:00', 1),
(418, 27, 'fri', '08:00:00', 1),
(419, 27, 'fri', '09:00:00', 1),
(420, 27, 'fri', '10:00:00', 1),
(421, 27, 'fri', '11:00:00', 1),
(422, 27, 'fri', '12:00:00', 1),
(423, 27, 'fri', '13:00:00', 1),
(424, 27, 'fri', '14:00:00', 1),
(425, 27, 'fri', '15:00:00', 1),
(426, 27, 'sat', '07:00:00', 1),
(427, 27, 'sat', '08:00:00', 1),
(428, 27, 'sat', '09:00:00', 1),
(429, 27, 'sat', '10:00:00', 1),
(430, 27, 'sat', '11:00:00', 1),
(431, 27, 'sat', '12:00:00', 1),
(432, 27, 'sat', '13:00:00', 1),
(433, 27, 'sat', '14:00:00', 1),
(434, 27, 'sat', '15:00:00', 1),
(435, 27, 'sun', '07:00:00', 1),
(436, 27, 'sun', '08:00:00', 1),
(437, 27, 'sun', '09:00:00', 1),
(438, 27, 'sun', '10:00:00', 1),
(439, 27, 'sun', '11:00:00', 1),
(440, 27, 'sun', '12:00:00', 1),
(441, 27, 'sun', '13:00:00', 1),
(442, 27, 'sun', '14:00:00', 1),
(443, 27, 'sun', '15:00:00', 1),
(444, 28, 'mon', '09:00:00', 1),
(445, 28, 'mon', '11:00:00', 1),
(446, 28, 'mon', '14:00:00', 1),
(447, 28, 'mon', '16:00:00', 1),
(448, 28, 'tue', '09:00:00', 1),
(449, 28, 'tue', '11:00:00', 1),
(450, 28, 'tue', '14:00:00', 1),
(451, 28, 'tue', '16:00:00', 1),
(452, 28, 'wed', '09:00:00', 1),
(453, 28, 'wed', '11:00:00', 1),
(454, 28, 'wed', '14:00:00', 1),
(455, 28, 'wed', '16:00:00', 1),
(456, 28, 'thu', '09:00:00', 1),
(457, 28, 'thu', '11:00:00', 1),
(458, 28, 'thu', '14:00:00', 1),
(459, 28, 'thu', '16:00:00', 1),
(460, 28, 'fri', '09:00:00', 1),
(461, 28, 'fri', '11:00:00', 1),
(462, 28, 'fri', '14:00:00', 1),
(463, 28, 'fri', '16:00:00', 1);

-- --------------------------------------------------------

--
-- Struktura tabeli dla tabeli `kampanie_tygodniowe`
--

CREATE TABLE `kampanie_tygodniowe` (
  `id` int UNSIGNED NOT NULL,
  `klient_nazwa` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `dlugosc` tinyint UNSIGNED NOT NULL,
  `data_start` date NOT NULL,
  `data_koniec` date NOT NULL,
  `sumy` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
  `produkty` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
  `netto_spoty` decimal(10,2) NOT NULL DEFAULT '0.00',
  `netto_dodatki` decimal(10,2) NOT NULL DEFAULT '0.00',
  `rabat` decimal(5,2) NOT NULL DEFAULT '0.00',
  `razem_po_rabacie` decimal(10,2) NOT NULL DEFAULT '0.00',
  `razem_brutto` decimal(10,2) NOT NULL DEFAULT '0.00',
  `siatka` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `audio_file` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `owner_user_id` int DEFAULT NULL
) ;

-- --------------------------------------------------------

--
-- Struktura tabeli dla tabeli `klienci`
--

CREATE TABLE `klienci` (
  `id` int NOT NULL,
  `nazwa_firmy` varchar(255) NOT NULL,
  `nip` varchar(10) NOT NULL,
  `regon` varchar(14) DEFAULT NULL,
  `adres` text,
  `data_dodania` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
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
  `notatka` text,
  `potencjal` decimal(10,2) DEFAULT NULL,
  `przypomnienie` date DEFAULT NULL,
  `branza` varchar(255) DEFAULT NULL,
  `owner_user_id` int DEFAULT NULL,
  `source_lead_id` int DEFAULT NULL,
  `assigned_user_id` int DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=latin2;

--
-- Dumping data for table `klienci`
--

INSERT INTO `klienci` (`id`, `nazwa_firmy`, `nip`, `regon`, `adres`, `data_dodania`, `ulica`, `nr_nieruchomosci`, `nr_lokalu`, `kod_pocztowy`, `miejscowosc`, `wojewodztwo`, `powiat`, `gmina`, `kraj`, `telefon`, `email`, `strona_www`, `status`, `ostatni_kontakt`, `notatka`, `potencjal`, `przypomnienie`, `branza`, `owner_user_id`, `source_lead_id`, `assigned_user_id`) VALUES
(3, 'OHMG Sp. z o.o.', '5792268412', '', NULL, '2025-04-23 18:53:18', 'Grobelno', '8', '', '82-200', 'Grobelno', 'pomorskie', NULL, NULL, 'Polska', '55 621 30 20', 'ceo@ohmg.pl', 'www.ohmg.pl', 'Nowy', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL),
(4, 'Nasza Europa', '5811822572', '', NULL, '2025-05-26 11:49:31', '', '', '', '', '', '', NULL, NULL, 'Polska', '691271822', 'phutarget@gmail.com', '', 'Nowy', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL),
(5, 'PHU ELRO', '5790004488', '', 'Grobelno', '2025-11-10 16:37:24', NULL, NULL, NULL, NULL, 'Malbork', '', NULL, NULL, NULL, '55 272 22 67', 'biuro@groblanka.pl', NULL, 'Nowy', '2025-11-07', 'kontakt z klientem', 2500.00, '2025-11-12', 'Hurtownia opakowań szklanych', NULL, NULL, NULL),
(6, 'Zabawka24', '5798885455', '', 'Powstańców 13', '2025-11-23 18:45:57', NULL, NULL, NULL, NULL, 'Elbląg', 'warmińsko-mazurskie', NULL, NULL, NULL, '', 'zabawka@wp.pl', NULL, 'Nowy', '2025-11-21', 'Klient dosyć zdecydowany ', 5000.00, '2025-12-01', 'Hurtownia zabawek', NULL, NULL, NULL),
(7, 'Aparthotel Dworzec', '5798885487', NULL, NULL, '2025-12-12 22:53:37', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, '795 700 075', NULL, NULL, 'Nowy', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL),
(8, 'Kasy i drukarki fiskalne KAScomp', '5783132297', NULL, NULL, '2025-12-13 23:32:15', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, '55 642 22 00', 'ceo@ohmg.pl', NULL, 'Nowy', NULL, 'Zadzwonić', NULL, NULL, NULL, NULL, NULL, NULL),
(9, 'HADM AG SPÓŁKA Z OGRANICZONĄ ODPOWIEDZIALNOŚCIĄ', '5783129378', '369231736', 'WARSZAWSKA 87', '2025-12-28 16:41:54', NULL, NULL, NULL, NULL, '82-300 ELBLĄG', '', NULL, NULL, NULL, '', '', NULL, 'Nowy', '0000-00-00', '', 0.00, '0000-00-00', '', NULL, NULL, NULL);

-- --------------------------------------------------------

--
-- Struktura tabeli dla tabeli `konfiguracja_systemu`
--

CREATE TABLE `konfiguracja_systemu` (
  `id` int NOT NULL DEFAULT '1',
  `liczba_blokow` int NOT NULL DEFAULT '2',
  `godzina_start` time NOT NULL DEFAULT '07:00:00',
  `godzina_koniec` time NOT NULL DEFAULT '21:00:00',
  `prog_procentowy` enum('0','2','7','12','20') NOT NULL DEFAULT '2',
  `prime_hours` varchar(255) DEFAULT NULL,
  `standard_hours` varchar(255) DEFAULT NULL,
  `night_hours` varchar(255) DEFAULT NULL,
  `pdf_logo_path` varchar(255) DEFAULT NULL,
  `smtp_host` varchar(255) DEFAULT NULL,
  `smtp_port` int DEFAULT NULL,
  `smtp_secure` varchar(10) DEFAULT NULL,
  `smtp_auth` tinyint(1) DEFAULT NULL,
  `smtp_default_from_email` varchar(255) DEFAULT NULL,
  `smtp_default_from_name` varchar(255) DEFAULT NULL,
  `smtp_username` varchar(255) DEFAULT NULL,
  `smtp_password` varchar(255) DEFAULT NULL,
  `email_signature_template_html` longtext,
  `limit_prime_seconds_per_day` int NOT NULL DEFAULT '3600',
  `limit_standard_seconds_per_day` int NOT NULL DEFAULT '3600',
  `limit_night_seconds_per_day` int NOT NULL DEFAULT '3600',
  `maintenance_last_run_at` datetime DEFAULT NULL,
  `maintenance_interval_minutes` int NOT NULL DEFAULT '10',
  `audio_upload_max_mb` int NOT NULL DEFAULT '50',
  `audio_allowed_ext` varchar(100) NOT NULL DEFAULT 'wav,mp3',
  `gus_enabled` tinyint(1) NOT NULL DEFAULT '0',
  `gus_api_key` varchar(255) DEFAULT NULL,
  `gus_environment` varchar(20) NOT NULL DEFAULT 'prod',
  `gus_cache_ttl_days` int NOT NULL DEFAULT '30',
  `company_name` varchar(255) DEFAULT NULL,
  `company_address` varchar(255) DEFAULT NULL,
  `company_nip` varchar(50) DEFAULT NULL,
  `company_email` varchar(255) DEFAULT NULL,
  `company_phone` varchar(50) DEFAULT NULL,
  `documents_storage_path` varchar(255) DEFAULT NULL,
  `documents_number_prefix` varchar(50) NOT NULL DEFAULT 'AM/'
) ENGINE=InnoDB DEFAULT CHARSET=latin2;

--
-- Dumping data for table `konfiguracja_systemu`
--

INSERT INTO `konfiguracja_systemu` (`id`, `liczba_blokow`, `godzina_start`, `godzina_koniec`, `prog_procentowy`, `prime_hours`, `standard_hours`, `night_hours`, `pdf_logo_path`, `smtp_host`, `smtp_port`, `smtp_secure`, `smtp_auth`, `smtp_default_from_email`, `smtp_default_from_name`, `smtp_username`, `smtp_password`, `email_signature_template_html`, `limit_prime_seconds_per_day`, `limit_standard_seconds_per_day`, `limit_night_seconds_per_day`, `maintenance_last_run_at`, `maintenance_interval_minutes`, `audio_upload_max_mb`, `audio_allowed_ext`, `gus_enabled`, `gus_api_key`, `gus_environment`, `gus_cache_ttl_days`, `company_name`, `company_address`, `company_nip`, `company_email`, `company_phone`, `documents_storage_path`, `documents_number_prefix`) VALUES
(1, 2, '07:00:00', '23:00:00', '0', '06:00-09:59,15:00-18:59', '10:00-14:59,19:00-22:59', '00:00-05:59,23:00-23:59', 'uploads/settings/mediaplan_logo_1765662090.png', 'server480824.nazwa.pl', 465, 'ssl', 1, 'reklama@radiozulawy.pl', 'Radio Żuławy', 'reklama@radiozulawy.pl', 'Radio1234', NULL, 3600, 3600, 3600, '2025-12-29 01:46:50', 10, 50, 'wav,mp3', 0, NULL, 'prod', 30, NULL, NULL, NULL, NULL, NULL, NULL, 'AM/');

-- --------------------------------------------------------

--
-- Struktura tabeli dla tabeli `koszty_dodatkowe`
--

CREATE TABLE `koszty_dodatkowe` (
  `id` int NOT NULL,
  `nazwa_kosztu` varchar(255) NOT NULL,
  `kwota_netto` decimal(10,2) NOT NULL,
  `opis` varchar(255) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=latin2;

-- --------------------------------------------------------

--
-- Struktura tabeli dla tabeli `leady`
--

CREATE TABLE `leady` (
  `id` int NOT NULL,
  `nazwa_firmy` varchar(255) COLLATE utf8mb4_general_ci NOT NULL,
  `nip` varchar(20) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `telefon` varchar(50) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `email` varchar(255) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `zrodlo` enum('telefon','email','formularz_www','maps_api','polecenie','inne') COLLATE utf8mb4_general_ci NOT NULL DEFAULT 'inne',
  `przypisany_handlowiec` varchar(255) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `status` varchar(50) COLLATE utf8mb4_general_ci NOT NULL DEFAULT 'Nowy',
  `notatki` text COLLATE utf8mb4_general_ci,
  `next_action_date` date DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `owner_user_id` int DEFAULT NULL,
  `priority` varchar(20) COLLATE utf8mb4_general_ci NOT NULL DEFAULT 'Średni',
  `next_action` varchar(255) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `next_action_at` datetime DEFAULT NULL,
  `client_id` int DEFAULT NULL,
  `converted_at` datetime DEFAULT NULL,
  `converted_by_user_id` int DEFAULT NULL,
  `assigned_user_id` int DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `leady`
--

INSERT INTO `leady` (`id`, `nazwa_firmy`, `nip`, `telefon`, `email`, `zrodlo`, `przypisany_handlowiec`, `status`, `notatki`, `next_action_date`, `created_at`, `updated_at`, `owner_user_id`, `priority`, `next_action`, `next_action_at`, `client_id`, `converted_at`, `converted_by_user_id`, `assigned_user_id`) VALUES
(1, 'Aparthotel Dworzec', '5798885487', '795 700 075', NULL, 'maps_api', NULL, 'Zamrożony', NULL, '2025-12-15', '2025-12-12 21:48:17', '2025-12-28 19:07:56', NULL, 'Średni', NULL, NULL, NULL, NULL, NULL, NULL);

-- --------------------------------------------------------

--
-- Struktura tabeli dla tabeli `leady_aktywnosci`
--

CREATE TABLE `leady_aktywnosci` (
  `id` int NOT NULL,
  `lead_id` int NOT NULL,
  `user_id` int NOT NULL,
  `typ` varchar(30) NOT NULL,
  `opis` text,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Dumping data for table `leady_aktywnosci`
--

INSERT INTO `leady_aktywnosci` (`id`, `lead_id`, `user_id`, `typ`, `opis`, `created_at`) VALUES
(1, 1, 1, 'status_change', 'Status: Wygrana → Negocjacje', '2025-12-28 20:07:53'),
(2, 1, 1, 'status_change', 'Status: Negocjacje → Zamrożony', '2025-12-28 20:07:56');

-- --------------------------------------------------------

--
-- Struktura tabeli dla tabeli `numeracja_dokumentow`
--

CREATE TABLE `numeracja_dokumentow` (
  `year` int NOT NULL,
  `last_number` int NOT NULL DEFAULT '0'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Struktura tabeli dla tabeli `plany_emisji`
--

CREATE TABLE `plany_emisji` (
  `id` int NOT NULL,
  `klient_id` int NOT NULL,
  `rok` int NOT NULL,
  `miesiac` int NOT NULL,
  `nazwa_planu` varchar(255) NOT NULL,
  `dlugosc_spotu` time DEFAULT NULL,
  `rabat` decimal(10,2) DEFAULT '0.00',
  `data_utworzenia` timestamp NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=latin2;

-- --------------------------------------------------------

--
-- Struktura tabeli dla tabeli `plany_emisji_szczegoly`
--

CREATE TABLE `plany_emisji_szczegoly` (
  `id` int NOT NULL,
  `plan_id` int NOT NULL,
  `dzien_miesiaca` int NOT NULL,
  `godzina` time NOT NULL,
  `ilosc_spotow` int NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=latin2;

-- --------------------------------------------------------

--
-- Struktura tabeli dla tabeli `powiadomienia`
--

CREATE TABLE `powiadomienia` (
  `id` int NOT NULL,
  `user_id` int NOT NULL,
  `typ` varchar(30) NOT NULL,
  `tresc` text NOT NULL,
  `link` varchar(255) DEFAULT NULL,
  `is_read` tinyint(1) NOT NULL DEFAULT '0',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Struktura tabeli dla tabeli `prowizje_rozliczenia`
--

CREATE TABLE `prowizje_rozliczenia` (
  `id` int NOT NULL,
  `year` smallint NOT NULL,
  `month` tinyint NOT NULL,
  `user_id` int NOT NULL,
  `rate_percent` decimal(5,2) NOT NULL,
  `base_netto` decimal(12,2) NOT NULL DEFAULT '0.00',
  `commission_netto` decimal(12,2) NOT NULL DEFAULT '0.00',
  `status` varchar(20) NOT NULL DEFAULT 'Należne',
  `calculated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `paid_at` datetime DEFAULT NULL,
  `note` varchar(255) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Struktura tabeli dla tabeli `spoty`
--

CREATE TABLE `spoty` (
  `id` int NOT NULL,
  `klient_id` int DEFAULT NULL,
  `nazwa_spotu` varchar(255) NOT NULL,
  `dlugosc` enum('15','20','30') NOT NULL,
  `data_start` date DEFAULT NULL,
  `data_koniec` date DEFAULT NULL,
  `data_dodania` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `aktywny` tinyint(1) NOT NULL DEFAULT '1',
  `rezerwacja` tinyint(1) NOT NULL DEFAULT '0',
  `kampania_id` int DEFAULT NULL,
  `dlugosc_s` int NOT NULL DEFAULT '30',
  `status` varchar(20) NOT NULL DEFAULT 'Aktywny',
  `rotation_group` varchar(1) DEFAULT NULL,
  `rotation_mode` varchar(30) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=latin2;

--
-- Dumping data for table `spoty`
--

INSERT INTO `spoty` (`id`, `klient_id`, `nazwa_spotu`, `dlugosc`, `data_start`, `data_koniec`, `data_dodania`, `aktywny`, `rezerwacja`, `kampania_id`, `dlugosc_s`, `status`, `rotation_group`, `rotation_mode`) VALUES
(7, 4, 'Oferta świąteczna', '30', '2025-11-23', '2026-03-31', '2025-11-23 18:19:46', 0, 0, NULL, 30, 'Nieaktywny', NULL, NULL),
(8, 6, 'Spot audio', '20', '2025-11-24', '2025-11-30', '2025-11-23 18:46:23', 0, 1, NULL, 30, 'Nieaktywny', NULL, NULL),
(9, 4, 'gramy świątecznie', '15', '2025-11-28', '2025-12-05', '2025-11-24 10:09:22', 0, 1, NULL, 30, 'Nieaktywny', NULL, NULL);

-- --------------------------------------------------------

--
-- Struktura tabeli dla tabeli `spoty_emisje`
--

CREATE TABLE `spoty_emisje` (
  `id` int NOT NULL,
  `spot_id` int NOT NULL,
  `data` date NOT NULL,
  `godzina` time NOT NULL,
  `blok` varchar(1) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=latin2;

--
-- Dumping data for table `spoty_emisje`
--

INSERT INTO `spoty_emisje` (`id`, `spot_id`, `data`, `godzina`, `blok`) VALUES
(6856, 7, '2025-11-24', '10:00:00', 'B'),
(6857, 7, '2025-11-24', '11:00:00', 'B'),
(6858, 7, '2025-11-24', '12:00:00', 'B'),
(6859, 7, '2025-11-24', '13:00:00', 'B'),
(6860, 7, '2025-11-25', '10:00:00', 'B'),
(6861, 7, '2025-11-25', '11:00:00', 'B'),
(6862, 7, '2025-11-25', '12:00:00', 'B'),
(6863, 7, '2025-11-25', '13:00:00', 'B'),
(6864, 7, '2025-11-26', '10:00:00', 'B'),
(6865, 7, '2025-11-26', '11:00:00', 'B'),
(6866, 7, '2025-11-26', '12:00:00', 'B'),
(6867, 7, '2025-11-26', '13:00:00', 'B'),
(6868, 7, '2025-11-27', '10:00:00', 'B'),
(6869, 7, '2025-11-27', '11:00:00', 'B'),
(6870, 7, '2025-11-27', '12:00:00', 'B'),
(6871, 7, '2025-11-27', '13:00:00', 'B'),
(6872, 7, '2025-11-28', '10:00:00', 'B'),
(6873, 7, '2025-11-28', '11:00:00', 'B'),
(6874, 7, '2025-11-28', '12:00:00', 'B'),
(6875, 7, '2025-11-28', '13:00:00', 'B'),
(6876, 7, '2025-12-01', '10:00:00', 'B'),
(6877, 7, '2025-12-01', '11:00:00', 'B'),
(6878, 7, '2025-12-01', '12:00:00', 'B'),
(6879, 7, '2025-12-01', '13:00:00', 'B'),
(6880, 7, '2025-12-02', '10:00:00', 'B'),
(6881, 7, '2025-12-02', '11:00:00', 'B'),
(6882, 7, '2025-12-02', '12:00:00', 'B'),
(6883, 7, '2025-12-02', '13:00:00', 'B'),
(6884, 7, '2025-12-03', '10:00:00', 'B'),
(6885, 7, '2025-12-03', '11:00:00', 'B'),
(6886, 7, '2025-12-03', '12:00:00', 'B'),
(6887, 7, '2025-12-03', '13:00:00', 'B'),
(6888, 7, '2025-12-04', '10:00:00', 'B'),
(6889, 7, '2025-12-04', '11:00:00', 'B'),
(6890, 7, '2025-12-04', '12:00:00', 'B'),
(6891, 7, '2025-12-04', '13:00:00', 'B'),
(6892, 7, '2025-12-05', '10:00:00', 'B'),
(6893, 7, '2025-12-05', '11:00:00', 'B'),
(6894, 7, '2025-12-05', '12:00:00', 'B'),
(6895, 7, '2025-12-05', '13:00:00', 'B'),
(6896, 7, '2025-12-08', '10:00:00', 'B'),
(6897, 7, '2025-12-08', '11:00:00', 'B'),
(6898, 7, '2025-12-08', '12:00:00', 'B'),
(6899, 7, '2025-12-08', '13:00:00', 'B'),
(6900, 7, '2025-12-09', '10:00:00', 'B'),
(6901, 7, '2025-12-09', '11:00:00', 'B'),
(6902, 7, '2025-12-09', '12:00:00', 'B'),
(6903, 7, '2025-12-09', '13:00:00', 'B'),
(6904, 7, '2025-12-10', '10:00:00', 'B'),
(6905, 7, '2025-12-10', '11:00:00', 'B'),
(6906, 7, '2025-12-10', '12:00:00', 'B'),
(6907, 7, '2025-12-10', '13:00:00', 'B'),
(6908, 7, '2025-12-11', '10:00:00', 'B'),
(6909, 7, '2025-12-11', '11:00:00', 'B'),
(6910, 7, '2025-12-11', '12:00:00', 'B'),
(6911, 7, '2025-12-11', '13:00:00', 'B'),
(6912, 7, '2025-12-12', '10:00:00', 'B'),
(6913, 7, '2025-12-12', '11:00:00', 'B'),
(6914, 7, '2025-12-12', '12:00:00', 'B'),
(6915, 7, '2025-12-12', '13:00:00', 'B'),
(6916, 7, '2025-12-15', '10:00:00', 'B'),
(6917, 7, '2025-12-15', '11:00:00', 'B'),
(6918, 7, '2025-12-15', '12:00:00', 'B'),
(6919, 7, '2025-12-15', '13:00:00', 'B'),
(6920, 7, '2025-12-16', '10:00:00', 'B'),
(6921, 7, '2025-12-16', '11:00:00', 'B'),
(6922, 7, '2025-12-16', '12:00:00', 'B'),
(6923, 7, '2025-12-16', '13:00:00', 'B'),
(6924, 7, '2025-12-17', '10:00:00', 'B'),
(6925, 7, '2025-12-17', '11:00:00', 'B'),
(6926, 7, '2025-12-17', '12:00:00', 'B'),
(6927, 7, '2025-12-17', '13:00:00', 'B'),
(6928, 7, '2025-12-18', '10:00:00', 'B'),
(6929, 7, '2025-12-18', '11:00:00', 'B'),
(6930, 7, '2025-12-18', '12:00:00', 'B'),
(6931, 7, '2025-12-18', '13:00:00', 'B'),
(6932, 7, '2025-12-19', '10:00:00', 'B'),
(6933, 7, '2025-12-19', '11:00:00', 'B'),
(6934, 7, '2025-12-19', '12:00:00', 'B'),
(6935, 7, '2025-12-19', '13:00:00', 'B'),
(6936, 7, '2025-12-22', '10:00:00', 'B'),
(6937, 7, '2025-12-22', '11:00:00', 'B'),
(6938, 7, '2025-12-22', '12:00:00', 'B'),
(6939, 7, '2025-12-22', '13:00:00', 'B'),
(6940, 7, '2025-12-23', '10:00:00', 'B'),
(6941, 7, '2025-12-23', '11:00:00', 'B'),
(6942, 7, '2025-12-23', '12:00:00', 'B'),
(6943, 7, '2025-12-23', '13:00:00', 'B'),
(6944, 7, '2025-12-24', '10:00:00', 'B'),
(6945, 7, '2025-12-24', '11:00:00', 'B'),
(6946, 7, '2025-12-24', '12:00:00', 'B'),
(6947, 7, '2025-12-24', '13:00:00', 'B'),
(6948, 7, '2025-12-25', '10:00:00', 'B'),
(6949, 7, '2025-12-25', '11:00:00', 'B'),
(6950, 7, '2025-12-25', '12:00:00', 'B'),
(6951, 7, '2025-12-25', '13:00:00', 'B'),
(6952, 7, '2025-12-26', '10:00:00', 'B'),
(6953, 7, '2025-12-26', '11:00:00', 'B'),
(6954, 7, '2025-12-26', '12:00:00', 'B'),
(6955, 7, '2025-12-26', '13:00:00', 'B'),
(6956, 7, '2025-12-29', '10:00:00', 'B'),
(6957, 7, '2025-12-29', '11:00:00', 'B'),
(6958, 7, '2025-12-29', '12:00:00', 'B'),
(6959, 7, '2025-12-29', '13:00:00', 'B'),
(6960, 7, '2025-12-30', '10:00:00', 'B'),
(6961, 7, '2025-12-30', '11:00:00', 'B'),
(6962, 7, '2025-12-30', '12:00:00', 'B'),
(6963, 7, '2025-12-30', '13:00:00', 'B'),
(6964, 7, '2025-12-31', '10:00:00', 'B'),
(6965, 7, '2025-12-31', '11:00:00', 'B'),
(6966, 7, '2025-12-31', '12:00:00', 'B'),
(6967, 7, '2025-12-31', '13:00:00', 'B'),
(6968, 7, '2026-01-01', '10:00:00', 'B'),
(6969, 7, '2026-01-01', '11:00:00', 'B'),
(6970, 7, '2026-01-01', '12:00:00', 'B'),
(6971, 7, '2026-01-01', '13:00:00', 'B'),
(6972, 7, '2026-01-02', '10:00:00', 'B'),
(6973, 7, '2026-01-02', '11:00:00', 'B'),
(6974, 7, '2026-01-02', '12:00:00', 'B'),
(6975, 7, '2026-01-02', '13:00:00', 'B'),
(6976, 7, '2026-01-05', '10:00:00', 'B'),
(6977, 7, '2026-01-05', '11:00:00', 'B'),
(6978, 7, '2026-01-05', '12:00:00', 'B'),
(6979, 7, '2026-01-05', '13:00:00', 'B'),
(6980, 7, '2026-01-06', '10:00:00', 'B'),
(6981, 7, '2026-01-06', '11:00:00', 'B'),
(6982, 7, '2026-01-06', '12:00:00', 'B'),
(6983, 7, '2026-01-06', '13:00:00', 'B'),
(6984, 7, '2026-01-07', '10:00:00', 'B'),
(6985, 7, '2026-01-07', '11:00:00', 'B'),
(6986, 7, '2026-01-07', '12:00:00', 'B'),
(6987, 7, '2026-01-07', '13:00:00', 'B'),
(6988, 7, '2026-01-08', '10:00:00', 'B'),
(6989, 7, '2026-01-08', '11:00:00', 'B'),
(6990, 7, '2026-01-08', '12:00:00', 'B'),
(6991, 7, '2026-01-08', '13:00:00', 'B'),
(6992, 7, '2026-01-09', '10:00:00', 'B'),
(6993, 7, '2026-01-09', '11:00:00', 'B'),
(6994, 7, '2026-01-09', '12:00:00', 'B'),
(6995, 7, '2026-01-09', '13:00:00', 'B'),
(6996, 7, '2026-01-12', '10:00:00', 'B'),
(6997, 7, '2026-01-12', '11:00:00', 'B'),
(6998, 7, '2026-01-12', '12:00:00', 'B'),
(6999, 7, '2026-01-12', '13:00:00', 'B'),
(7000, 7, '2026-01-13', '10:00:00', 'B'),
(7001, 7, '2026-01-13', '11:00:00', 'B'),
(7002, 7, '2026-01-13', '12:00:00', 'B'),
(7003, 7, '2026-01-13', '13:00:00', 'B'),
(7004, 7, '2026-01-14', '10:00:00', 'B'),
(7005, 7, '2026-01-14', '11:00:00', 'B'),
(7006, 7, '2026-01-14', '12:00:00', 'B'),
(7007, 7, '2026-01-14', '13:00:00', 'B'),
(7008, 7, '2026-01-15', '10:00:00', 'B'),
(7009, 7, '2026-01-15', '11:00:00', 'B'),
(7010, 7, '2026-01-15', '12:00:00', 'B'),
(7011, 7, '2026-01-15', '13:00:00', 'B'),
(7012, 7, '2026-01-16', '10:00:00', 'B'),
(7013, 7, '2026-01-16', '11:00:00', 'B'),
(7014, 7, '2026-01-16', '12:00:00', 'B'),
(7015, 7, '2026-01-16', '13:00:00', 'B'),
(7016, 7, '2026-01-19', '10:00:00', 'B'),
(7017, 7, '2026-01-19', '11:00:00', 'B'),
(7018, 7, '2026-01-19', '12:00:00', 'B'),
(7019, 7, '2026-01-19', '13:00:00', 'B'),
(7020, 7, '2026-01-20', '10:00:00', 'B'),
(7021, 7, '2026-01-20', '11:00:00', 'B'),
(7022, 7, '2026-01-20', '12:00:00', 'B'),
(7023, 7, '2026-01-20', '13:00:00', 'B'),
(7024, 7, '2026-01-21', '10:00:00', 'B'),
(7025, 7, '2026-01-21', '11:00:00', 'B'),
(7026, 7, '2026-01-21', '12:00:00', 'B'),
(7027, 7, '2026-01-21', '13:00:00', 'B'),
(7028, 7, '2026-01-22', '10:00:00', 'B'),
(7029, 7, '2026-01-22', '11:00:00', 'B'),
(7030, 7, '2026-01-22', '12:00:00', 'B'),
(7031, 7, '2026-01-22', '13:00:00', 'B'),
(7032, 7, '2026-01-23', '10:00:00', 'B'),
(7033, 7, '2026-01-23', '11:00:00', 'B'),
(7034, 7, '2026-01-23', '12:00:00', 'B'),
(7035, 7, '2026-01-23', '13:00:00', 'B'),
(7036, 7, '2026-01-26', '10:00:00', 'B'),
(7037, 7, '2026-01-26', '11:00:00', 'B'),
(7038, 7, '2026-01-26', '12:00:00', 'B'),
(7039, 7, '2026-01-26', '13:00:00', 'B'),
(7040, 7, '2026-01-27', '10:00:00', 'B'),
(7041, 7, '2026-01-27', '11:00:00', 'B'),
(7042, 7, '2026-01-27', '12:00:00', 'B'),
(7043, 7, '2026-01-27', '13:00:00', 'B'),
(7044, 7, '2026-01-28', '10:00:00', 'B'),
(7045, 7, '2026-01-28', '11:00:00', 'B'),
(7046, 7, '2026-01-28', '12:00:00', 'B'),
(7047, 7, '2026-01-28', '13:00:00', 'B'),
(7048, 7, '2026-01-29', '10:00:00', 'B'),
(7049, 7, '2026-01-29', '11:00:00', 'B'),
(7050, 7, '2026-01-29', '12:00:00', 'B'),
(7051, 7, '2026-01-29', '13:00:00', 'B'),
(7052, 7, '2026-01-30', '10:00:00', 'B'),
(7053, 7, '2026-01-30', '11:00:00', 'B'),
(7054, 7, '2026-01-30', '12:00:00', 'B'),
(7055, 7, '2026-01-30', '13:00:00', 'B'),
(7056, 7, '2026-02-02', '10:00:00', 'B'),
(7057, 7, '2026-02-02', '11:00:00', 'B'),
(7058, 7, '2026-02-02', '12:00:00', 'B'),
(7059, 7, '2026-02-02', '13:00:00', 'B'),
(7060, 7, '2026-02-03', '10:00:00', 'B'),
(7061, 7, '2026-02-03', '11:00:00', 'B'),
(7062, 7, '2026-02-03', '12:00:00', 'B'),
(7063, 7, '2026-02-03', '13:00:00', 'B'),
(7064, 7, '2026-02-04', '10:00:00', 'B'),
(7065, 7, '2026-02-04', '11:00:00', 'B'),
(7066, 7, '2026-02-04', '12:00:00', 'B'),
(7067, 7, '2026-02-04', '13:00:00', 'B'),
(7068, 7, '2026-02-05', '10:00:00', 'B'),
(7069, 7, '2026-02-05', '11:00:00', 'B'),
(7070, 7, '2026-02-05', '12:00:00', 'B'),
(7071, 7, '2026-02-05', '13:00:00', 'B'),
(7072, 7, '2026-02-06', '10:00:00', 'B'),
(7073, 7, '2026-02-06', '11:00:00', 'B'),
(7074, 7, '2026-02-06', '12:00:00', 'B'),
(7075, 7, '2026-02-06', '13:00:00', 'B'),
(7076, 7, '2026-02-09', '10:00:00', 'B'),
(7077, 7, '2026-02-09', '11:00:00', 'B'),
(7078, 7, '2026-02-09', '12:00:00', 'B'),
(7079, 7, '2026-02-09', '13:00:00', 'B'),
(7080, 7, '2026-02-10', '10:00:00', 'B'),
(7081, 7, '2026-02-10', '11:00:00', 'B'),
(7082, 7, '2026-02-10', '12:00:00', 'B'),
(7083, 7, '2026-02-10', '13:00:00', 'B'),
(7084, 7, '2026-02-11', '10:00:00', 'B'),
(7085, 7, '2026-02-11', '11:00:00', 'B'),
(7086, 7, '2026-02-11', '12:00:00', 'B'),
(7087, 7, '2026-02-11', '13:00:00', 'B'),
(7088, 7, '2026-02-12', '10:00:00', 'B'),
(7089, 7, '2026-02-12', '11:00:00', 'B'),
(7090, 7, '2026-02-12', '12:00:00', 'B'),
(7091, 7, '2026-02-12', '13:00:00', 'B'),
(7092, 7, '2026-02-13', '10:00:00', 'B'),
(7093, 7, '2026-02-13', '11:00:00', 'B'),
(7094, 7, '2026-02-13', '12:00:00', 'B'),
(7095, 7, '2026-02-13', '13:00:00', 'B'),
(7096, 7, '2026-02-16', '10:00:00', 'B'),
(7097, 7, '2026-02-16', '11:00:00', 'B'),
(7098, 7, '2026-02-16', '12:00:00', 'B'),
(7099, 7, '2026-02-16', '13:00:00', 'B'),
(7100, 7, '2026-02-17', '10:00:00', 'B'),
(7101, 7, '2026-02-17', '11:00:00', 'B'),
(7102, 7, '2026-02-17', '12:00:00', 'B'),
(7103, 7, '2026-02-17', '13:00:00', 'B'),
(7104, 7, '2026-02-18', '10:00:00', 'B'),
(7105, 7, '2026-02-18', '11:00:00', 'B'),
(7106, 7, '2026-02-18', '12:00:00', 'B'),
(7107, 7, '2026-02-18', '13:00:00', 'B'),
(7108, 7, '2026-02-19', '10:00:00', 'B'),
(7109, 7, '2026-02-19', '11:00:00', 'B'),
(7110, 7, '2026-02-19', '12:00:00', 'B'),
(7111, 7, '2026-02-19', '13:00:00', 'B'),
(7112, 7, '2026-02-20', '10:00:00', 'B'),
(7113, 7, '2026-02-20', '11:00:00', 'B'),
(7114, 7, '2026-02-20', '12:00:00', 'B'),
(7115, 7, '2026-02-20', '13:00:00', 'B'),
(7116, 7, '2026-02-23', '10:00:00', 'B'),
(7117, 7, '2026-02-23', '11:00:00', 'B'),
(7118, 7, '2026-02-23', '12:00:00', 'B'),
(7119, 7, '2026-02-23', '13:00:00', 'B'),
(7120, 7, '2026-02-24', '10:00:00', 'B'),
(7121, 7, '2026-02-24', '11:00:00', 'B'),
(7122, 7, '2026-02-24', '12:00:00', 'B'),
(7123, 7, '2026-02-24', '13:00:00', 'B'),
(7124, 7, '2026-02-25', '10:00:00', 'B'),
(7125, 7, '2026-02-25', '11:00:00', 'B'),
(7126, 7, '2026-02-25', '12:00:00', 'B'),
(7127, 7, '2026-02-25', '13:00:00', 'B'),
(7128, 7, '2026-02-26', '10:00:00', 'B'),
(7129, 7, '2026-02-26', '11:00:00', 'B'),
(7130, 7, '2026-02-26', '12:00:00', 'B'),
(7131, 7, '2026-02-26', '13:00:00', 'B'),
(7132, 7, '2026-02-27', '10:00:00', 'B'),
(7133, 7, '2026-02-27', '11:00:00', 'B'),
(7134, 7, '2026-02-27', '12:00:00', 'B'),
(7135, 7, '2026-02-27', '13:00:00', 'B'),
(7136, 7, '2026-03-02', '10:00:00', 'B'),
(7137, 7, '2026-03-02', '11:00:00', 'B'),
(7138, 7, '2026-03-02', '12:00:00', 'B'),
(7139, 7, '2026-03-02', '13:00:00', 'B'),
(7140, 7, '2026-03-03', '10:00:00', 'B'),
(7141, 7, '2026-03-03', '11:00:00', 'B'),
(7142, 7, '2026-03-03', '12:00:00', 'B'),
(7143, 7, '2026-03-03', '13:00:00', 'B'),
(7144, 7, '2026-03-04', '10:00:00', 'B'),
(7145, 7, '2026-03-04', '11:00:00', 'B'),
(7146, 7, '2026-03-04', '12:00:00', 'B'),
(7147, 7, '2026-03-04', '13:00:00', 'B'),
(7148, 7, '2026-03-05', '10:00:00', 'B'),
(7149, 7, '2026-03-05', '11:00:00', 'B'),
(7150, 7, '2026-03-05', '12:00:00', 'B'),
(7151, 7, '2026-03-05', '13:00:00', 'B'),
(7152, 7, '2026-03-06', '10:00:00', 'B'),
(7153, 7, '2026-03-06', '11:00:00', 'B'),
(7154, 7, '2026-03-06', '12:00:00', 'B'),
(7155, 7, '2026-03-06', '13:00:00', 'B'),
(7156, 7, '2026-03-09', '10:00:00', 'B'),
(7157, 7, '2026-03-09', '11:00:00', 'B'),
(7158, 7, '2026-03-09', '12:00:00', 'B'),
(7159, 7, '2026-03-09', '13:00:00', 'B'),
(7160, 7, '2026-03-10', '10:00:00', 'B'),
(7161, 7, '2026-03-10', '11:00:00', 'B'),
(7162, 7, '2026-03-10', '12:00:00', 'B'),
(7163, 7, '2026-03-10', '13:00:00', 'B'),
(7164, 7, '2026-03-11', '10:00:00', 'B'),
(7165, 7, '2026-03-11', '11:00:00', 'B'),
(7166, 7, '2026-03-11', '12:00:00', 'B'),
(7167, 7, '2026-03-11', '13:00:00', 'B'),
(7168, 7, '2026-03-12', '10:00:00', 'B'),
(7169, 7, '2026-03-12', '11:00:00', 'B'),
(7170, 7, '2026-03-12', '12:00:00', 'B'),
(7171, 7, '2026-03-12', '13:00:00', 'B'),
(7172, 7, '2026-03-13', '10:00:00', 'B'),
(7173, 7, '2026-03-13', '11:00:00', 'B'),
(7174, 7, '2026-03-13', '12:00:00', 'B'),
(7175, 7, '2026-03-13', '13:00:00', 'B'),
(7176, 7, '2026-03-16', '10:00:00', 'B'),
(7177, 7, '2026-03-16', '11:00:00', 'B'),
(7178, 7, '2026-03-16', '12:00:00', 'B'),
(7179, 7, '2026-03-16', '13:00:00', 'B'),
(7180, 7, '2026-03-17', '10:00:00', 'B'),
(7181, 7, '2026-03-17', '11:00:00', 'B'),
(7182, 7, '2026-03-17', '12:00:00', 'B'),
(7183, 7, '2026-03-17', '13:00:00', 'B'),
(7184, 7, '2026-03-18', '10:00:00', 'B'),
(7185, 7, '2026-03-18', '11:00:00', 'B'),
(7186, 7, '2026-03-18', '12:00:00', 'B'),
(7187, 7, '2026-03-18', '13:00:00', 'B'),
(7188, 7, '2026-03-19', '10:00:00', 'B'),
(7189, 7, '2026-03-19', '11:00:00', 'B'),
(7190, 7, '2026-03-19', '12:00:00', 'B'),
(7191, 7, '2026-03-19', '13:00:00', 'B'),
(7192, 7, '2026-03-20', '10:00:00', 'B'),
(7193, 7, '2026-03-20', '11:00:00', 'B'),
(7194, 7, '2026-03-20', '12:00:00', 'B'),
(7195, 7, '2026-03-20', '13:00:00', 'B'),
(7196, 7, '2026-03-23', '10:00:00', 'B'),
(7197, 7, '2026-03-23', '11:00:00', 'B'),
(7198, 7, '2026-03-23', '12:00:00', 'B'),
(7199, 7, '2026-03-23', '13:00:00', 'B'),
(7200, 7, '2026-03-24', '10:00:00', 'B'),
(7201, 7, '2026-03-24', '11:00:00', 'B'),
(7202, 7, '2026-03-24', '12:00:00', 'B'),
(7203, 7, '2026-03-24', '13:00:00', 'B'),
(7204, 7, '2026-03-25', '10:00:00', 'B'),
(7205, 7, '2026-03-25', '11:00:00', 'B'),
(7206, 7, '2026-03-25', '12:00:00', 'B'),
(7207, 7, '2026-03-25', '13:00:00', 'B'),
(7208, 7, '2026-03-26', '10:00:00', 'B'),
(7209, 7, '2026-03-26', '11:00:00', 'B'),
(7210, 7, '2026-03-26', '12:00:00', 'B'),
(7211, 7, '2026-03-26', '13:00:00', 'B'),
(7212, 7, '2026-03-27', '10:00:00', 'B'),
(7213, 7, '2026-03-27', '11:00:00', 'B'),
(7214, 7, '2026-03-27', '12:00:00', 'B'),
(7215, 7, '2026-03-27', '13:00:00', 'B'),
(7216, 7, '2026-03-30', '10:00:00', 'B'),
(7217, 7, '2026-03-30', '11:00:00', 'B'),
(7218, 7, '2026-03-30', '12:00:00', 'B'),
(7219, 7, '2026-03-30', '13:00:00', 'B'),
(7220, 7, '2026-03-31', '10:00:00', 'B'),
(7221, 7, '2026-03-31', '11:00:00', 'B'),
(7222, 7, '2026-03-31', '12:00:00', 'B'),
(7223, 7, '2026-03-31', '13:00:00', 'B'),
(8018, 8, '2025-11-24', '08:00:00', 'B'),
(8019, 8, '2025-11-25', '08:00:00', 'B'),
(8020, 8, '2025-11-26', '08:00:00', 'B'),
(8021, 8, '2025-11-27', '08:00:00', 'B'),
(8022, 8, '2025-11-28', '08:00:00', 'B'),
(8023, 9, '2025-11-28', '11:00:00', 'B'),
(8024, 9, '2025-11-29', '11:00:00', 'B'),
(8025, 9, '2025-11-30', '11:00:00', 'B'),
(8026, 9, '2025-12-01', '11:00:00', 'B'),
(8027, 9, '2025-12-02', '11:00:00', 'B'),
(8028, 9, '2025-12-03', '11:00:00', 'B'),
(8029, 9, '2025-12-04', '11:00:00', 'B'),
(8030, 9, '2025-12-05', '11:00:00', 'B');

-- --------------------------------------------------------

--
-- Struktura tabeli dla tabeli `spot_audio_files`
--

CREATE TABLE `spot_audio_files` (
  `id` int NOT NULL,
  `spot_id` int NOT NULL,
  `version_no` int NOT NULL DEFAULT '1',
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `original_filename` varchar(255) NOT NULL,
  `stored_filename` varchar(255) NOT NULL,
  `mime_type` varchar(100) DEFAULT NULL,
  `file_size` int DEFAULT NULL,
  `sha256` char(64) DEFAULT NULL,
  `production_status` varchar(30) NOT NULL DEFAULT 'Do akceptacji',
  `approved_by_user_id` int DEFAULT NULL,
  `approved_at` datetime DEFAULT NULL,
  `rejection_reason` varchar(255) DEFAULT NULL,
  `uploaded_by_user_id` int NOT NULL,
  `upload_note` varchar(255) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Struktura tabeli dla tabeli `system_logs`
--

CREATE TABLE `system_logs` (
  `id` int NOT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `user_id` int NOT NULL,
  `action` varchar(50) NOT NULL,
  `message` text NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Struktura tabeli dla tabeli `uzytkownicy`
--

CREATE TABLE `uzytkownicy` (
  `id` int NOT NULL,
  `login` varchar(50) NOT NULL,
  `haslo_hash` varchar(255) NOT NULL,
  `rola` enum('admin','uzytkownik') DEFAULT 'uzytkownik',
  `data_utworzenia` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `reset_token` varchar(255) DEFAULT NULL,
  `reset_token_expires` datetime DEFAULT NULL,
  `smtp_user` varchar(255) DEFAULT NULL,
  `smtp_pass` varchar(255) DEFAULT NULL,
  `smtp_from_email` varchar(255) DEFAULT NULL,
  `smtp_from_name` varchar(255) DEFAULT NULL,
  `email_signature` text,
  `use_system_smtp` tinyint(1) NOT NULL DEFAULT '0',
  `email` varchar(255) DEFAULT NULL,
  `aktywny` tinyint(1) NOT NULL DEFAULT '1',
  `imie` varchar(100) DEFAULT NULL,
  `nazwisko` varchar(100) DEFAULT NULL,
  `telefon` varchar(50) DEFAULT NULL,
  `funkcja` varchar(150) DEFAULT NULL,
  `commission_enabled` tinyint(1) NOT NULL DEFAULT '0',
  `commission_rate_percent` decimal(5,2) NOT NULL DEFAULT '0.00'
) ENGINE=InnoDB DEFAULT CHARSET=latin2;

--
-- Dumping data for table `uzytkownicy`
--

INSERT INTO `uzytkownicy` (`id`, `login`, `haslo_hash`, `rola`, `data_utworzenia`, `reset_token`, `reset_token_expires`, `smtp_user`, `smtp_pass`, `smtp_from_email`, `smtp_from_name`, `email_signature`, `use_system_smtp`, `email`, `aktywny`, `imie`, `nazwisko`, `telefon`, `funkcja`, `commission_enabled`, `commission_rate_percent`) VALUES
(1, 'admin', '$2y$12$ly1EYCCCExHCiou2D/A7LOvz1MJd5lmimSYbIuer3n2d551neRvNe', '', '2025-03-24 20:58:41', NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0, 'ceo@ohmg.pl', 1, 'Karol', 'Pufal', '881659971', NULL, 0, 0.00),
(2, 'testuser', '$2y$10$fP4iB0S2v3GpcdL3Ok3rFegdf45jqW2Yrj1Jz8l.n/7oIbC2m1YjW', '', '2025-03-24 20:58:41', NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0, 'jan@testowe.pl', 1, 'Jan', 'Kowalski', '884958958', NULL, 1, 20.00),
(3, 'Testowy', '$2y$10$RVHjJExuGH1BH9EwXbIwEeVdOuKls8x3q8SEMKOYtr.REGmcr0kru', '', '2025-12-19 20:43:41', NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0, 'reklama@radiozulawy.pl', 1, 'Adam', 'Nowak', '55 621 30 20', NULL, 0, 0.00);

--
-- Indeksy dla zrzutów tabel
--

--
-- Indeksy dla tabeli `cele_sprzedazowe`
--
ALTER TABLE `cele_sprzedazowe`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_cele_sprzedazowe_period_user` (`year`,`month`,`user_id`),
  ADD KEY `idx_cele_sprzedazowe_user` (`user_id`);

--
-- Indeksy dla tabeli `cennik_display`
--
ALTER TABLE `cennik_display`
  ADD PRIMARY KEY (`id`);

--
-- Indeksy dla tabeli `cennik_kalkulator`
--
ALTER TABLE `cennik_kalkulator`
  ADD PRIMARY KEY (`id`);

--
-- Indeksy dla tabeli `cennik_social`
--
ALTER TABLE `cennik_social`
  ADD PRIMARY KEY (`id`);

--
-- Indeksy dla tabeli `cennik_spoty`
--
ALTER TABLE `cennik_spoty`
  ADD PRIMARY KEY (`id`);

--
-- Indeksy dla tabeli `cennik_sygnaly`
--
ALTER TABLE `cennik_sygnaly`
  ADD PRIMARY KEY (`id`);

--
-- Indeksy dla tabeli `cennik_wywiady`
--
ALTER TABLE `cennik_wywiady`
  ADD PRIMARY KEY (`id`);

--
-- Indeksy dla tabeli `dokumenty`
--
ALTER TABLE `dokumenty`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `doc_number` (`doc_number`),
  ADD KEY `idx_dokumenty_client` (`client_id`),
  ADD KEY `idx_dokumenty_kampania` (`kampania_id`),
  ADD KEY `idx_dokumenty_user` (`created_by_user_id`);

--
-- Indeksy dla tabeli `emisje`
--
ALTER TABLE `emisje`
  ADD PRIMARY KEY (`id`),
  ADD KEY `kampania_id` (`kampania_id`);

--
-- Indeksy dla tabeli `emisje_spotow`
--
ALTER TABLE `emisje_spotow`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_emisje_spotow_spot_id` (`spot_id`),
  ADD KEY `idx_emisje_spotow_dow` (`dow`),
  ADD KEY `idx_emisje_spotow_godzina` (`godzina`);

--
-- Indeksy dla tabeli `gus_cache`
--
ALTER TABLE `gus_cache`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_gus_cache_nip` (`nip`),
  ADD KEY `idx_gus_cache_regon` (`regon`);

--
-- Indeksy dla tabeli `historia_maili_ofert`
--
ALTER TABLE `historia_maili_ofert`
  ADD PRIMARY KEY (`id`),
  ADD KEY `kampania_id` (`kampania_id`),
  ADD KEY `user_id` (`user_id`);

--
-- Indeksy dla tabeli `integrations_logs`
--
ALTER TABLE `integrations_logs`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_integrations_logs_type` (`type`),
  ADD KEY `idx_integrations_logs_user` (`user_id`);

--
-- Indeksy dla tabeli `kampanie`
--
ALTER TABLE `kampanie`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_kampanie_owner_user_id` (`owner_user_id`),
  ADD KEY `idx_kampanie_created_at` (`created_at`),
  ADD KEY `idx_kampanie_status` (`status`),
  ADD KEY `idx_kampanie_data_start` (`data_start`);

--
-- Indeksy dla tabeli `kampanie_emisje`
--
ALTER TABLE `kampanie_emisje`
  ADD PRIMARY KEY (`id`),
  ADD KEY `kampania_id` (`kampania_id`);

--
-- Indeksy dla tabeli `kampanie_tygodniowe`
--
ALTER TABLE `kampanie_tygodniowe`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_okres` (`data_start`,`data_koniec`);

--
-- Indeksy dla tabeli `klienci`
--
ALTER TABLE `klienci`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `nip` (`nip`),
  ADD KEY `idx_klienci_owner_user_id` (`owner_user_id`),
  ADD KEY `idx_klienci_source_lead_id` (`source_lead_id`),
  ADD KEY `idx_klienci_assigned_user_id` (`assigned_user_id`);

--
-- Indeksy dla tabeli `konfiguracja_systemu`
--
ALTER TABLE `konfiguracja_systemu`
  ADD PRIMARY KEY (`id`);

--
-- Indeksy dla tabeli `koszty_dodatkowe`
--
ALTER TABLE `koszty_dodatkowe`
  ADD PRIMARY KEY (`id`);

--
-- Indeksy dla tabeli `leady`
--
ALTER TABLE `leady`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_leady_status` (`status`),
  ADD KEY `idx_leady_zrodlo` (`zrodlo`),
  ADD KEY `idx_leady_nip` (`nip`),
  ADD KEY `idx_leady_created_at` (`created_at`),
  ADD KEY `idx_leady_owner_user_id` (`owner_user_id`),
  ADD KEY `idx_leady_client_id` (`client_id`),
  ADD KEY `idx_leady_next_action_at` (`next_action_at`),
  ADD KEY `idx_leady_converted_at` (`converted_at`),
  ADD KEY `idx_leady_assigned_user_id` (`assigned_user_id`);

--
-- Indeksy dla tabeli `leady_aktywnosci`
--
ALTER TABLE `leady_aktywnosci`
  ADD PRIMARY KEY (`id`),
  ADD KEY `lead_id` (`lead_id`),
  ADD KEY `user_id` (`user_id`);

--
-- Indeksy dla tabeli `numeracja_dokumentow`
--
ALTER TABLE `numeracja_dokumentow`
  ADD PRIMARY KEY (`year`);

--
-- Indeksy dla tabeli `plany_emisji`
--
ALTER TABLE `plany_emisji`
  ADD PRIMARY KEY (`id`),
  ADD KEY `klient_id` (`klient_id`);

--
-- Indeksy dla tabeli `plany_emisji_szczegoly`
--
ALTER TABLE `plany_emisji_szczegoly`
  ADD PRIMARY KEY (`id`),
  ADD KEY `plan_id` (`plan_id`);

--
-- Indeksy dla tabeli `powiadomienia`
--
ALTER TABLE `powiadomienia`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_powiadomienia_user` (`user_id`),
  ADD KEY `idx_powiadomienia_type` (`typ`);

--
-- Indeksy dla tabeli `prowizje_rozliczenia`
--
ALTER TABLE `prowizje_rozliczenia`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_prowizje_rozliczenia_period_user` (`year`,`month`,`user_id`),
  ADD KEY `idx_prowizje_rozliczenia_user` (`user_id`);

--
-- Indeksy dla tabeli `spoty`
--
ALTER TABLE `spoty`
  ADD PRIMARY KEY (`id`),
  ADD KEY `klient_id` (`klient_id`),
  ADD KEY `idx_spoty_klient_id` (`klient_id`),
  ADD KEY `idx_spoty_kampania_id` (`kampania_id`);

--
-- Indeksy dla tabeli `spoty_emisje`
--
ALTER TABLE `spoty_emisje`
  ADD PRIMARY KEY (`id`),
  ADD KEY `spot_id` (`spot_id`);

--
-- Indeksy dla tabeli `spot_audio_files`
--
ALTER TABLE `spot_audio_files`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_spot_audio_files_stored` (`stored_filename`),
  ADD KEY `idx_spot_audio_files_spot_id` (`spot_id`),
  ADD KEY `idx_spot_audio_files_uploaded_by` (`uploaded_by_user_id`);

--
-- Indeksy dla tabeli `system_logs`
--
ALTER TABLE `system_logs`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_system_logs_user` (`user_id`),
  ADD KEY `idx_system_logs_action` (`action`);

--
-- Indeksy dla tabeli `uzytkownicy`
--
ALTER TABLE `uzytkownicy`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `login` (`login`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `cele_sprzedazowe`
--
ALTER TABLE `cele_sprzedazowe`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `cennik_display`
--
ALTER TABLE `cennik_display`
  MODIFY `id` int NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- AUTO_INCREMENT for table `cennik_kalkulator`
--
ALTER TABLE `cennik_kalkulator`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `cennik_social`
--
ALTER TABLE `cennik_social`
  MODIFY `id` int NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `cennik_spoty`
--
ALTER TABLE `cennik_spoty`
  MODIFY `id` int NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=16;

--
-- AUTO_INCREMENT for table `cennik_sygnaly`
--
ALTER TABLE `cennik_sygnaly`
  MODIFY `id` int NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- AUTO_INCREMENT for table `cennik_wywiady`
--
ALTER TABLE `cennik_wywiady`
  MODIFY `id` int NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT for table `dokumenty`
--
ALTER TABLE `dokumenty`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `emisje`
--
ALTER TABLE `emisje`
  MODIFY `id` int NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=9;

--
-- AUTO_INCREMENT for table `emisje_spotow`
--
ALTER TABLE `emisje_spotow`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `gus_cache`
--
ALTER TABLE `gus_cache`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `historia_maili_ofert`
--
ALTER TABLE `historia_maili_ofert`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `integrations_logs`
--
ALTER TABLE `integrations_logs`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `kampanie`
--
ALTER TABLE `kampanie`
  MODIFY `id` int NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=29;

--
-- AUTO_INCREMENT for table `kampanie_emisje`
--
ALTER TABLE `kampanie_emisje`
  MODIFY `id` int NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=464;

--
-- AUTO_INCREMENT for table `kampanie_tygodniowe`
--
ALTER TABLE `kampanie_tygodniowe`
  MODIFY `id` int UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `klienci`
--
ALTER TABLE `klienci`
  MODIFY `id` int NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=10;

--
-- AUTO_INCREMENT for table `koszty_dodatkowe`
--
ALTER TABLE `koszty_dodatkowe`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `leady`
--
ALTER TABLE `leady`
  MODIFY `id` int NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT for table `leady_aktywnosci`
--
ALTER TABLE `leady_aktywnosci`
  MODIFY `id` int NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT for table `plany_emisji`
--
ALTER TABLE `plany_emisji`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `plany_emisji_szczegoly`
--
ALTER TABLE `plany_emisji_szczegoly`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `powiadomienia`
--
ALTER TABLE `powiadomienia`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `prowizje_rozliczenia`
--
ALTER TABLE `prowizje_rozliczenia`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `spoty`
--
ALTER TABLE `spoty`
  MODIFY `id` int NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=10;

--
-- AUTO_INCREMENT for table `spoty_emisje`
--
ALTER TABLE `spoty_emisje`
  MODIFY `id` int NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=8031;

--
-- AUTO_INCREMENT for table `spot_audio_files`
--
ALTER TABLE `spot_audio_files`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `system_logs`
--
ALTER TABLE `system_logs`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `uzytkownicy`
--
ALTER TABLE `uzytkownicy`
  MODIFY `id` int NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- Constraints for dumped tables
--

--
-- Constraints for table `emisje`
--
ALTER TABLE `emisje`
  ADD CONSTRAINT `emisje_ibfk_1` FOREIGN KEY (`kampania_id`) REFERENCES `kampanie` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `emisje_spotow`
--
ALTER TABLE `emisje_spotow`
  ADD CONSTRAINT `fk_emisje_spotow_spot` FOREIGN KEY (`spot_id`) REFERENCES `spoty` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `kampanie_emisje`
--
ALTER TABLE `kampanie_emisje`
  ADD CONSTRAINT `kampanie_emisje_ibfk_1` FOREIGN KEY (`kampania_id`) REFERENCES `kampanie` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `plany_emisji`
--
ALTER TABLE `plany_emisji`
  ADD CONSTRAINT `plany_emisji_ibfk_1` FOREIGN KEY (`klient_id`) REFERENCES `klienci` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `plany_emisji_szczegoly`
--
ALTER TABLE `plany_emisji_szczegoly`
  ADD CONSTRAINT `plany_emisji_szczegoly_ibfk_1` FOREIGN KEY (`plan_id`) REFERENCES `plany_emisji` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `spoty`
--
ALTER TABLE `spoty`
  ADD CONSTRAINT `spoty_ibfk_1` FOREIGN KEY (`klient_id`) REFERENCES `klienci` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `spoty_emisje`
--
ALTER TABLE `spoty_emisje`
  ADD CONSTRAINT `spoty_emisje_ibfk_1` FOREIGN KEY (`spot_id`) REFERENCES `spoty` (`id`) ON DELETE CASCADE;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
