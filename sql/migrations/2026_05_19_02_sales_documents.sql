CREATE TABLE IF NOT EXISTS `document_numbering_settings` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `document_type` varchar(30) NOT NULL,
  `prefix` varchar(20) NOT NULL,
  `numbering_pattern` varchar(120) NOT NULL DEFAULT '{PREFIX}/{YEAR}/{MONTH}/{NUMBER}',
  `current_year` int(11) DEFAULT NULL,
  `current_month` int(11) DEFAULT NULL,
  `last_number` int(11) NOT NULL DEFAULT 0,
  `reset_period` varchar(20) NOT NULL DEFAULT 'yearly',
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_document_numbering_type` (`document_type`),
  KEY `idx_document_numbering_active` (`is_active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO `document_numbering_settings`
  (`document_type`, `prefix`, `numbering_pattern`, `reset_period`, `is_active`)
SELECT 'order', 'ZL', 'ZL/{YEAR}/{MONTH}/{NUMBER}', 'monthly', 1
WHERE NOT EXISTS (
  SELECT 1 FROM `document_numbering_settings` WHERE `document_type` = 'order'
);

INSERT INTO `document_numbering_settings`
  (`document_type`, `prefix`, `numbering_pattern`, `reset_period`, `is_active`)
SELECT 'annex', 'AN', 'AN/{YEAR}/{MONTH}/{NUMBER}', 'monthly', 1
WHERE NOT EXISTS (
  SELECT 1 FROM `document_numbering_settings` WHERE `document_type` = 'annex'
);

CREATE TABLE IF NOT EXISTS `documents` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `document_type` varchar(30) NOT NULL,
  `document_number` varchar(80) NOT NULL,
  `related_document_id` int(11) DEFAULT NULL,
  `client_id` int(11) DEFAULT NULL,
  `campaign_id` int(11) DEFAULT NULL,
  `company_profile_id` int(11) DEFAULT NULL,
  `issue_date` date DEFAULT NULL,
  `valid_from` date DEFAULT NULL,
  `valid_to` date DEFAULT NULL,
  `status` varchar(30) NOT NULL DEFAULT 'draft',
  `title` varchar(255) DEFAULT NULL,
  `net_value` decimal(12,2) NOT NULL DEFAULT 0.00,
  `vat_rate` decimal(5,2) NOT NULL DEFAULT 23.00,
  `vat_value` decimal(12,2) NOT NULL DEFAULT 0.00,
  `gross_value` decimal(12,2) NOT NULL DEFAULT 0.00,
  `currency` char(3) NOT NULL DEFAULT 'PLN',
  `pdf_path` varchar(255) DEFAULT NULL,
  `created_by` int(11) DEFAULT NULL,
  `accepted_at` datetime DEFAULT NULL,
  `accepted_by_name` varchar(160) DEFAULT NULL,
  `accepted_by_email` varchar(255) DEFAULT NULL,
  `acceptance_ip` varchar(45) DEFAULT NULL,
  `acceptance_user_agent` varchar(255) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_documents_number` (`document_number`),
  KEY `idx_documents_type_status` (`document_type`,`status`),
  KEY `idx_documents_client` (`client_id`),
  KEY `idx_documents_campaign` (`campaign_id`),
  KEY `idx_documents_company_profile` (`company_profile_id`),
  KEY `idx_documents_related` (`related_document_id`),
  KEY `idx_documents_created_by` (`created_by`),
  KEY `idx_documents_issue_date` (`issue_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
