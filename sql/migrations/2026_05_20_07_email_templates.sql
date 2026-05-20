CREATE TABLE IF NOT EXISTS email_templates (
  id INT AUTO_INCREMENT PRIMARY KEY,
  template_key VARCHAR(80) NOT NULL,
  name VARCHAR(160) NOT NULL,
  subject_template VARCHAR(255) NOT NULL,
  body_template TEXT NOT NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_by INT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_email_templates_key (template_key),
  KEY idx_email_templates_active (is_active),
  KEY idx_email_templates_created_by (created_by)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO email_templates (template_key, name, subject_template, body_template, is_active, created_at, updated_at)
SELECT 'document_order_send', 'Wysyłka zlecenia do klienta', 'Zlecenie emisji reklamy {DOCUMENT_NUMBER}',
'Dzień dobry,

w załączeniu przesyłamy dokument {DOCUMENT_NUMBER} dotyczący emisji reklamy.
Prosimy o zapoznanie się z dokumentem i potwierdzenie akceptacji.

Link do pobrania i akceptacji dokumentu:
{ACCEPTANCE_LINK}

Pozdrawiamy,
{COMPANY_NAME}', 1, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP
WHERE NOT EXISTS (SELECT 1 FROM email_templates WHERE template_key = 'document_order_send');

INSERT INTO email_templates (template_key, name, subject_template, body_template, is_active, created_at, updated_at)
SELECT 'document_annex_send', 'Wysyłka aneksu do klienta', 'Aneks do zlecenia {DOCUMENT_NUMBER}',
'Dzień dobry,

w załączeniu przesyłamy aneks {DOCUMENT_NUMBER} dotyczący emisji reklamy.
Prosimy o zapoznanie się z dokumentem i potwierdzenie akceptacji.

Link do pobrania i akceptacji dokumentu:
{ACCEPTANCE_LINK}

Pozdrawiamy,
{COMPANY_NAME}', 1, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP
WHERE NOT EXISTS (SELECT 1 FROM email_templates WHERE template_key = 'document_annex_send');
