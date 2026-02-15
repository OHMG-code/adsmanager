CREATE TABLE IF NOT EXISTS uzytkownicy (
  id INT AUTO_INCREMENT PRIMARY KEY,
  login VARCHAR(100) NOT NULL UNIQUE,
  haslo_hash VARCHAR(255) NOT NULL,
  rola VARCHAR(50) NOT NULL DEFAULT 'user',
  reset_token VARCHAR(255),
  reset_token_expires DATETIME
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS klienci (
  id INT AUTO_INCREMENT PRIMARY KEY,
  nip VARCHAR(20) NOT NULL UNIQUE,
  nazwa_firmy VARCHAR(255) NOT NULL,
  regon VARCHAR(50),
  telefon VARCHAR(50),
  email VARCHAR(150),
  adres VARCHAR(255),
  miejscowosc VARCHAR(150),
  wojewodztwo VARCHAR(150),
  branza VARCHAR(150),
  status VARCHAR(50) DEFAULT 'Nowy',
  ostatni_kontakt DATE,
  notatka TEXT,
  kontakt_imie_nazwisko VARCHAR(120),
  kontakt_stanowisko VARCHAR(120),
  kontakt_telefon VARCHAR(60),
  kontakt_email VARCHAR(120),
  kontakt_preferencja ENUM('telefon','email','sms','') DEFAULT '',
  potencjal DECIMAL(12,2),
  przypomnienie DATE,
  data_dodania DATETIME DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS cennik_spoty (
  id INT AUTO_INCREMENT PRIMARY KEY,
  dlugosc INT NOT NULL,
  pasmo VARCHAR(50) NOT NULL,
  stawka_netto DECIMAL(12,2) NOT NULL DEFAULT 0,
  stawka_vat DECIMAL(5,2) NOT NULL DEFAULT 23.00
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS cennik_sygnaly (
  id INT AUTO_INCREMENT PRIMARY KEY,
  typ_programu VARCHAR(150) NOT NULL,
  stawka_netto DECIMAL(12,2) NOT NULL DEFAULT 0,
  stawka_vat DECIMAL(5,2) NOT NULL DEFAULT 23.00
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS cennik_wywiady (
  id INT AUTO_INCREMENT PRIMARY KEY,
  nazwa VARCHAR(150) NOT NULL,
  opis TEXT,
  stawka_netto DECIMAL(12,2) NOT NULL DEFAULT 0,
  stawka_vat DECIMAL(5,2) NOT NULL DEFAULT 23.00
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS cennik_display (
  id INT AUTO_INCREMENT PRIMARY KEY,
  format VARCHAR(150) NOT NULL,
  opis TEXT,
  stawka_netto DECIMAL(12,2) NOT NULL DEFAULT 0,
  stawka_vat DECIMAL(5,2) NOT NULL DEFAULT 23.00
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS cennik_social (
  id INT AUTO_INCREMENT PRIMARY KEY,
  platforma VARCHAR(150) NOT NULL,
  rodzaj_postu VARCHAR(150) NOT NULL,
  stawka_netto DECIMAL(12,2) NOT NULL DEFAULT 0,
  stawka_vat DECIMAL(5,2) NOT NULL DEFAULT 23.00
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS kampanie (
  id INT AUTO_INCREMENT PRIMARY KEY,
  klient_id INT NULL,
  klient_nazwa VARCHAR(255) NOT NULL,
  dlugosc_spotu INT NOT NULL,
  data_start DATE NOT NULL,
  data_koniec DATE NOT NULL,
  rabat DECIMAL(5,2) NOT NULL DEFAULT 0,
  netto_spoty DECIMAL(12,2) NOT NULL DEFAULT 0,
  netto_dodatki DECIMAL(12,2) NOT NULL DEFAULT 0,
  razem_netto DECIMAL(12,2) NOT NULL DEFAULT 0,
  razem_brutto DECIMAL(12,2) NOT NULL DEFAULT 0,
  propozycja TINYINT(1) NOT NULL DEFAULT 0,
  audio_file VARCHAR(255) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (klient_id) REFERENCES klienci(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS kampanie_emisje (
  id INT AUTO_INCREMENT PRIMARY KEY,
  kampania_id INT NOT NULL,
  dzien_tygodnia VARCHAR(10) NOT NULL,
  godzina TIME NOT NULL,
  ilosc INT NOT NULL DEFAULT 0,
  FOREIGN KEY (kampania_id) REFERENCES kampanie(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS spoty (
  id INT AUTO_INCREMENT PRIMARY KEY,
  klient_id INT NOT NULL,
  nazwa_spotu VARCHAR(255) NOT NULL,
  dlugosc INT NOT NULL,
  data_start DATE NOT NULL,
  data_koniec DATE NOT NULL,
  rezerwacja TINYINT(1) NOT NULL DEFAULT 0,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (klient_id) REFERENCES klienci(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS spoty_emisje (
  id INT AUTO_INCREMENT PRIMARY KEY,
  spot_id INT NOT NULL,
  data DATE NOT NULL,
  godzina TIME NOT NULL,
  blok CHAR(1) NULL,
  FOREIGN KEY (spot_id) REFERENCES spoty(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Stara tabela (jeśli potrzebna)
CREATE TABLE IF NOT EXISTS kampanie_tygodniowe (
  id INT AUTO_INCREMENT PRIMARY KEY,
  klient_nazwa VARCHAR(255) NOT NULL,
  dlugosc INT NOT NULL,
  data_start DATE NOT NULL,
  data_koniec DATE NOT NULL,
  netto_spoty DECIMAL(12,2) NOT NULL DEFAULT 0,
  netto_dodatki DECIMAL(12,2) NOT NULL DEFAULT 0,
  rabat DECIMAL(5,2) NOT NULL DEFAULT 0,
  razem_po_rabacie DECIMAL(12,2) NOT NULL DEFAULT 0,
  razem_brutto DECIMAL(12,2) NOT NULL DEFAULT 0,
  sumy JSON NULL,
  produkty JSON NULL,
  siatka JSON NULL,
  audio_file VARCHAR(255) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS uzytkownicy (
  id INT AUTO_INCREMENT PRIMARY KEY,
  login VARCHAR(100) NOT NULL UNIQUE,
  haslo_hash VARCHAR(255) NOT NULL,
  rola VARCHAR(50) NOT NULL DEFAULT 'user',
  imie VARCHAR(100),
  nazwisko VARCHAR(100),
  email VARCHAR(150),
  reset_token VARCHAR(255),
  reset_token_expires DATETIME
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

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

CREATE TABLE IF NOT EXISTS crm_zadania (
  id INT AUTO_INCREMENT PRIMARY KEY,
  obiekt_typ ENUM('lead','klient') NOT NULL,
  obiekt_id INT NOT NULL,
  owner_user_id INT NOT NULL,
  typ ENUM('telefon','email','sms','spotkanie','inne') NOT NULL,
  tytul VARCHAR(160) NOT NULL,
  opis TEXT NULL,
  due_at DATETIME NOT NULL,
  status ENUM('OPEN','DONE','CANCELLED') NOT NULL DEFAULT 'OPEN',
  done_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_crm_zadania_owner_status_due (owner_user_id, status, due_at),
  INDEX idx_crm_zadania_obiekt_due (obiekt_typ, obiekt_id, due_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS activity_log (
  id INT AUTO_INCREMENT PRIMARY KEY,
  entity_type ENUM('lead','klient','task') NOT NULL,
  entity_id INT NOT NULL,
  action VARCHAR(50) NOT NULL,
  message TEXT NULL,
  user_id INT NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_activity_log_entity (entity_type, entity_id, created_at),
  INDEX idx_activity_log_user (user_id, created_at)
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

-- reszta schematu: klienci, cennik_*, kampanie, kampanie_emisje, spoty, spoty_emisje, kampanie_tygodniowe (jak w poprzedniej wersji)
