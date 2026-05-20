CREATE TABLE IF NOT EXISTS `document_terms_templates` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `document_type` varchar(30) NOT NULL,
  `version` varchar(30) NOT NULL,
  `title` varchar(255) NOT NULL,
  `content_html` mediumtext NOT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 0,
  `valid_from` date DEFAULT NULL,
  `valid_to` date DEFAULT NULL,
  `created_by` int(11) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_document_terms_type_active` (`document_type`,`is_active`),
  KEY `idx_document_terms_validity` (`valid_from`,`valid_to`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `document_terms_acceptance` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `document_id` int(11) NOT NULL,
  `terms_template_id` int(11) DEFAULT NULL,
  `terms_version` varchar(30) NOT NULL,
  `terms_content_snapshot` mediumtext NOT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_document_terms_document` (`document_id`),
  KEY `idx_document_terms_template` (`terms_template_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO `document_terms_templates`
  (`document_type`, `version`, `title`, `content_html`, `is_active`, `valid_from`)
SELECT
  'order',
  '1.0',
  'Ogólne warunki zamówienia - zlecenie',
  '<ol><li>Zamawiający odpowiada za prawdziwość i zgodność z prawem treści reklamy.</li><li>Zamawiający oświadcza, że posiada prawa do przekazanych materiałów, znaków, muzyki, lektorów i innych elementów.</li><li>W przypadku materiału dostarczonego przez Zamawiającego Wykonawca nie odpowiada za jego jakość techniczną ani treść.</li><li>W przypadku produkcji spotu przez Wykonawcę Zamawiający ma prawo do jednej tury poprawek, o ile strony nie ustaliły inaczej.</li><li>Brak akceptacji lub uwag do materiału w terminie 24 godzin od przesłania oznacza akceptację materiału do emisji.</li><li>Niedostarczenie materiału w terminie nie zwalnia Zamawiającego z obowiązku zapłaty, jeżeli Wykonawca pozostawał gotowy do realizacji emisji.</li><li>Godziny emisji mogą ulec niewielkim przesunięciom wynikającym z układu programu, bloków reklamowych lub przyczyn technicznych.</li><li>W przypadku awarii technicznej Wykonawca może zrealizować emisje zastępcze w innym terminie.</li><li>Reklamacje należy zgłaszać pisemnie lub mailowo w terminie 7 dni od zakończenia kampanii.</li><li>Akceptacja zlecenia może nastąpić podpisem, mailowo albo przez inną udokumentowaną formę potwierdzenia.</li></ol>',
  1,
  CURDATE()
WHERE NOT EXISTS (
  SELECT 1 FROM `document_terms_templates` WHERE `document_type` = 'order'
);
