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
