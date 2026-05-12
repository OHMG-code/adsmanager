CREATE TABLE IF NOT EXISTS `lead_form_sources` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(255) NOT NULL,
  `public_key` varchar(64) NOT NULL,
  `allowed_domains` text DEFAULT NULL,
  `default_lead_source` varchar(40) NOT NULL DEFAULT 'formularz_www',
  `marketing_consent_required` tinyint(1) NOT NULL DEFAULT 0,
  `gus_lookup_enabled` tinyint(1) NOT NULL DEFAULT 0,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_lead_form_sources_public_key` (`public_key`),
  KEY `idx_lead_form_sources_active` (`is_active`),
  KEY `idx_lead_form_sources_created_at` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `lead_form_submissions` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `lead_form_source_id` int(11) NOT NULL,
  `lead_id` int(11) DEFAULT NULL,
  `public_key` varchar(64) NOT NULL,
  `origin` varchar(255) DEFAULT NULL,
  `referer` varchar(500) DEFAULT NULL,
  `remote_addr` varchar(64) DEFAULT NULL,
  `status` varchar(30) NOT NULL DEFAULT 'received',
  `duplicate_reason` varchar(255) DEFAULT NULL,
  `raw_payload` longtext NOT NULL,
  `normalized_payload` longtext DEFAULT NULL,
  `error_message` text DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_lead_form_submissions_source_created` (`lead_form_source_id`, `created_at`),
  KEY `idx_lead_form_submissions_public_key` (`public_key`),
  KEY `idx_lead_form_submissions_lead_id` (`lead_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `lead_form_field_mappings` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `lead_form_source_id` int(11) NOT NULL,
  `external_field` varchar(120) NOT NULL,
  `crm_field` varchar(120) NOT NULL,
  `is_required` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_lead_form_field_mappings_source_external` (`lead_form_source_id`, `external_field`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
