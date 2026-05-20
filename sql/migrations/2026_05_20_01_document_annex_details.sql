CREATE TABLE IF NOT EXISTS `document_annex_details` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `document_id` int(11) NOT NULL,
  `base_document_id` int(11) NOT NULL,
  `change_description` text NOT NULL,
  `old_valid_from` date DEFAULT NULL,
  `old_valid_to` date DEFAULT NULL,
  `new_valid_from` date DEFAULT NULL,
  `new_valid_to` date DEFAULT NULL,
  `old_net_value` decimal(12,2) NOT NULL DEFAULT 0.00,
  `old_gross_value` decimal(12,2) NOT NULL DEFAULT 0.00,
  `new_net_value` decimal(12,2) NOT NULL DEFAULT 0.00,
  `new_gross_value` decimal(12,2) NOT NULL DEFAULT 0.00,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_document_annex_details_document` (`document_id`),
  KEY `idx_document_annex_details_base` (`base_document_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO `document_terms_templates`
  (`document_type`, `version`, `title`, `content_html`, `is_active`, `valid_from`)
SELECT
  'annex',
  '1.0',
  'Ogólne warunki zamówienia - aneks',
  '<ol><li>Aneks zmienia wyłącznie zakres wskazany w jego treści.</li><li>Pozostałe postanowienia zlecenia bazowego pozostają bez zmian.</li><li>W przypadku sprzeczności między zleceniem bazowym a aneksem pierwszeństwo ma treść aneksu.</li><li>Akceptacja aneksu może nastąpić podpisem, mailowo lub przez inną udokumentowaną formę potwierdzenia.</li></ol>',
  1,
  CURDATE()
WHERE NOT EXISTS (
  SELECT 1 FROM `document_terms_templates` WHERE `document_type` = 'annex'
);
