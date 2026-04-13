CREATE TABLE IF NOT EXISTS crm_statusy (
  id INT AUTO_INCREMENT PRIMARY KEY,
  nazwa VARCHAR(80) NOT NULL,
  aktywny TINYINT(1) NOT NULL DEFAULT 1,
  dotyczy ENUM('lead','klient','oba') NOT NULL DEFAULT 'oba',
  sort INT NOT NULL DEFAULT 100,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS crm_aktywnosci (
  id INT AUTO_INCREMENT PRIMARY KEY,
  obiekt_typ ENUM('lead','klient') NOT NULL,
  obiekt_id INT NOT NULL,
  typ ENUM('status','notatka','mail','system') NOT NULL,
  status_id INT NULL,
  temat VARCHAR(255) NULL,
  tresc MEDIUMTEXT NULL,
  user_id INT NOT NULL,
  meta_json JSON NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_crm_aktywnosci_obiekt (obiekt_typ, obiekt_id, created_at),
  INDEX idx_crm_aktywnosci_user (user_id, created_at),
  INDEX idx_crm_aktywnosci_status (status_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO crm_statusy (nazwa, aktywny, dotyczy, sort)
SELECT seed.nazwa, seed.aktywny, seed.dotyczy, seed.sort
FROM (
  SELECT 'Nowy' AS nazwa, 1 AS aktywny, 'oba' AS dotyczy, 10 AS sort
  UNION ALL SELECT 'Do kontaktu', 1, 'oba', 20
  UNION ALL SELECT 'Wysłano ofertę', 1, 'oba', 30
  UNION ALL SELECT 'Negocjacje', 1, 'oba', 40
  UNION ALL SELECT 'Wygrany', 1, 'oba', 50
  UNION ALL SELECT 'Przegrany', 1, 'oba', 60
) AS seed
WHERE NOT EXISTS (SELECT 1 FROM crm_statusy);
