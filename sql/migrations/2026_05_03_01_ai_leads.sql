CREATE TABLE IF NOT EXISTS `ai_leads_import` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `company_name` varchar(255) NOT NULL,
  `city` varchar(120) NOT NULL,
  `phone` varchar(60) DEFAULT NULL,
  `email` varchar(255) DEFAULT NULL,
  `website` varchar(255) DEFAULT NULL,
  `industry` varchar(255) NOT NULL,
  `score` int(11) NOT NULL DEFAULT 0,
  `source` varchar(80) NOT NULL DEFAULT 'ai_generated',
  `status` enum('new','duplicate','reviewed','accepted','rejected') NOT NULL DEFAULT 'new',
  `assigned_user_id` int(11) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_ai_leads_import_status` (`status`),
  KEY `idx_ai_leads_import_assigned_user` (`assigned_user_id`),
  KEY `idx_ai_leads_import_created_at` (`created_at`),
  KEY `idx_ai_leads_import_company_city` (`company_name`, `city`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `ai_leads_duplicates` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `ai_lead_id` int(11) NOT NULL,
  `matched_type` enum('lead','client') NOT NULL,
  `matched_id` int(11) NOT NULL,
  `match_score` int(11) NOT NULL,
  `reason` varchar(255) NOT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_ai_leads_duplicates_ai_lead` (`ai_lead_id`),
  KEY `idx_ai_leads_duplicates_match` (`matched_type`, `matched_id`),
  CONSTRAINT `fk_ai_leads_duplicates_import`
    FOREIGN KEY (`ai_lead_id`) REFERENCES `ai_leads_import` (`id`)
    ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
