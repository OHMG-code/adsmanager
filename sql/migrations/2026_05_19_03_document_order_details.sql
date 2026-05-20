CREATE TABLE IF NOT EXISTS `document_order_details` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `document_id` int(11) NOT NULL,
  `spot_source` varchar(30) NOT NULL,
  `material_deadline` date DEFAULT NULL,
  `spot_length_seconds` int(11) NOT NULL DEFAULT 0,
  `emission_count` int(11) NOT NULL DEFAULT 0,
  `technical_notes` text DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_document_order_details_document` (`document_id`),
  KEY `idx_document_order_details_spot_source` (`spot_source`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
