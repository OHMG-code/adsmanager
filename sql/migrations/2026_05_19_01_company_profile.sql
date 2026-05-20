CREATE TABLE IF NOT EXISTS `company_profile` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `company_name` varchar(255) NOT NULL,
  `short_name` varchar(120) DEFAULT NULL,
  `nip` varchar(20) DEFAULT NULL,
  `regon` varchar(20) DEFAULT NULL,
  `krs` varchar(20) DEFAULT NULL,
  `address_street` varchar(255) DEFAULT NULL,
  `address_postal_code` varchar(20) DEFAULT NULL,
  `address_city` varchar(120) DEFAULT NULL,
  `email` varchar(255) DEFAULT NULL,
  `phone` varchar(50) DEFAULT NULL,
  `website` varchar(255) DEFAULT NULL,
  `bank_account` varchar(80) DEFAULT NULL,
  `bank_name` varchar(160) DEFAULT NULL,
  `representative_name` varchar(160) DEFAULT NULL,
  `representative_role` varchar(120) DEFAULT NULL,
  `logo_path` varchar(255) DEFAULT NULL,
  `stamp_path` varchar(255) DEFAULT NULL,
  `signature_path` varchar(255) DEFAULT NULL,
  `default_vat_rate` decimal(5,2) NOT NULL DEFAULT 23.00,
  `default_payment_days` int(11) NOT NULL DEFAULT 14,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_company_profile_nip` (`nip`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO `company_profile`
  (`company_name`, `nip`, `address_street`, `email`, `phone`, `logo_path`, `default_vat_rate`, `default_payment_days`)
SELECT
  COALESCE(NULLIF(TRIM(`company_name`), ''), 'Firma') AS `company_name`,
  NULLIF(TRIM(`company_nip`), '') AS `nip`,
  NULLIF(TRIM(`company_address`), '') AS `address_street`,
  NULLIF(TRIM(`company_email`), '') AS `email`,
  NULLIF(TRIM(`company_phone`), '') AS `phone`,
  NULLIF(TRIM(`pdf_logo_path`), '') AS `logo_path`,
  23.00,
  14
FROM `konfiguracja_systemu`
WHERE `id` = 1
  AND NOT EXISTS (SELECT 1 FROM `company_profile` LIMIT 1)
  AND (
    NULLIF(TRIM(`company_name`), '') IS NOT NULL
    OR NULLIF(TRIM(`company_nip`), '') IS NOT NULL
    OR NULLIF(TRIM(`company_email`), '') IS NOT NULL
    OR NULLIF(TRIM(`pdf_logo_path`), '') IS NOT NULL
  );
