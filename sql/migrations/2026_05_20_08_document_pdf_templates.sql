CREATE TABLE IF NOT EXISTS document_pdf_templates (
  id INT AUTO_INCREMENT PRIMARY KEY,
  document_type VARCHAR(30) NOT NULL,
  name VARCHAR(160) NOT NULL,
  version VARCHAR(30) NOT NULL,
  html_template MEDIUMTEXT NOT NULL,
  css_template MEDIUMTEXT NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_by INT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_document_pdf_templates_type_active (document_type, is_active),
  KEY idx_document_pdf_templates_created_by (created_by)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO document_pdf_templates (document_type, name, version, html_template, css_template, is_active, created_at, updated_at)
SELECT 'order', 'Zlecenie emisji reklamy', '1.0',
'<div class="footer">Dokument {DOCUMENT_NUMBER}</div><div class="header"><table class="header-table"><tr><td style="width:52%;"><div class="owner-name">{COMPANY_NAME}</div><div>{COMPANY_ADDRESS}</div><div>NIP: {COMPANY_NIP}</div><div>Email: {COMPANY_EMAIL}</div><div>Telefon: {COMPANY_PHONE}</div></td><td style="width:48%;"><div class="doc-title">Zlecenie emisji reklamy</div><div class="doc-meta"><strong>Numer:</strong> {DOCUMENT_NUMBER}<br><strong>Data wystawienia:</strong> {ISSUE_DATE}</div></td></tr></table></div><table class="grid"><tr><td style="padding-right:8px;"><div class="section-title">Dane klienta</div><div class="box"><strong>{CLIENT_NAME}</strong><br>{CLIENT_ADDRESS}<br>NIP: {CLIENT_NIP}<br>Email: {CLIENT_EMAIL}</div></td><td style="padding-left:8px;"><div class="section-title">Parametry emisji</div><div class="box">Okres emisji: <strong>{VALID_FROM} - {VALID_TO}</strong><br>{ORDER_DETAILS}</div></td></tr></table><div class="section"><div class="section-title">Treść zlecenia</div><table class="kv"><tr><th>Tytuł</th><td>{DOCUMENT_TITLE}</td></tr></table></div><div class="section"><div class="section-title">Wartości</div><table class="values"><tr><th>Netto</th><th>Stawka VAT</th><th>VAT</th><th>Brutto</th></tr><tr><td class="num">{NET_VALUE}</td><td class="num">{VAT_RATE}</td><td class="num">{VAT_VALUE}</td><td class="num"><strong>{GROSS_VALUE}</strong></td></tr></table></div><div class="section"><div class="section-title">Ogólne warunki zamówienia</div><div class="owz">{TERMS_HTML}</div></div><div class="section"><div class="section-title">Warunek zależny od źródła spotu</div><div class="highlight">{DYNAMIC_TERMS_HTML}</div></div>{SIGNATURES_HTML}',
'@page { margin: 28px 32px 44px; }
body { font-family: DejaVu Sans, sans-serif; font-size: 11px; color: #1f2937; }
.footer { position: fixed; left: 0; right: 0; bottom: -28px; font-size: 9px; color: #64748b; border-top: 1px solid #dbe3ef; padding-top: 7px; }
.header { border-bottom: 3px solid #0b2b5c; padding-bottom: 16px; margin-bottom: 18px; }
.header-table, .grid, .kv, .values, .signatures { width: 100%; border-collapse: collapse; }
.owner-name { font-size: 18px; font-weight: 700; color: #0b2b5c; margin-bottom: 4px; }
.doc-title { font-size: 20px; font-weight: 700; color: #0b2b5c; margin: 0 0 5px; text-align: right; }
.doc-meta { text-align: right; line-height: 1.6; }
.section { margin-top: 16px; }
.section-title { background: #0b2b5c; color: #fff; padding: 7px 10px; font-weight: 700; font-size: 12px; }
.box { border: 1px solid #d7dfec; padding: 10px; }
.grid td { vertical-align: top; width: 50%; }
.kv th { width: 35%; text-align: left; background: #f2f6fb; color: #0b2b5c; }
.kv th, .kv td, .values th, .values td { border: 1px solid #d7dfec; padding: 8px; vertical-align: top; }
.values th { background: #f2f6fb; color: #0b2b5c; text-align: left; }
.num { text-align: right; }
.highlight, .note { border: 2px solid #0b2b5c; background: #eef5ff; padding: 9px; font-weight: 700; margin: 8px 0; }
.owz li { margin-bottom: 5px; }
.signatures { margin-top: 34px; }
.signatures td { width: 50%; text-align: center; padding-top: 36px; }
.line { border-top: 1px solid #1f2937; padding-top: 7px; width: 80%; margin: 0 auto; }',
1, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP
WHERE NOT EXISTS (SELECT 1 FROM document_pdf_templates WHERE document_type = 'order');

INSERT INTO document_pdf_templates (document_type, name, version, html_template, css_template, is_active, created_at, updated_at)
SELECT 'annex', 'Aneks do zlecenia', '1.0',
'<div class="footer">Aneks {DOCUMENT_NUMBER}</div><div class="header"><table class="header-table"><tr><td style="width:52%;"><div class="owner-name">{COMPANY_NAME}</div><div>{COMPANY_ADDRESS}</div><div>NIP: {COMPANY_NIP}</div><div>Email: {COMPANY_EMAIL}</div><div>Telefon: {COMPANY_PHONE}</div></td><td style="width:48%;"><div class="doc-title">Aneks do zlecenia emisji reklamy</div><div class="doc-meta"><strong>Numer aneksu:</strong> {DOCUMENT_NUMBER}<br><strong>Data wystawienia:</strong> {ISSUE_DATE}</div></td></tr></table></div><table class="grid"><tr><td style="padding-right:8px;"><div class="section-title">Dane klienta</div><div class="box"><strong>{CLIENT_NAME}</strong><br>{CLIENT_ADDRESS}<br>NIP: {CLIENT_NIP}<br>Email: {CLIENT_EMAIL}</div></td><td style="padding-left:8px;"><div class="section-title">Aneks</div><div class="box">Tytuł: <strong>{DOCUMENT_TITLE}</strong><br>Waluta: <strong>{CURRENCY}</strong></div></td></tr></table><div class="section"><div class="section-title">Opis zmiany</div><div class="box">{ANNEX_DETAILS}</div></div><div class="section"><div class="note">Pozostałe warunki zlecenia bazowego pozostają bez zmian. W zakresie sprzeczności pierwszeństwo ma treść niniejszego aneksu.</div></div><div class="section"><div class="section-title">Ogólne warunki zamówienia</div><div class="owz">{TERMS_HTML}</div></div>{SIGNATURES_HTML}',
'@page { margin: 28px 32px 44px; }
body { font-family: DejaVu Sans, sans-serif; font-size: 11px; color: #1f2937; }
.footer { position: fixed; left: 0; right: 0; bottom: -28px; font-size: 9px; color: #64748b; border-top: 1px solid #dbe3ef; padding-top: 7px; }
.header { border-bottom: 3px solid #0b2b5c; padding-bottom: 16px; margin-bottom: 18px; }
.header-table, .grid, .kv, .values, .signatures { width: 100%; border-collapse: collapse; }
.owner-name { font-size: 18px; font-weight: 700; color: #0b2b5c; margin-bottom: 4px; }
.doc-title { font-size: 20px; font-weight: 700; color: #0b2b5c; margin: 0 0 5px; text-align: right; }
.doc-meta { text-align: right; line-height: 1.6; }
.section { margin-top: 16px; }
.section-title { background: #0b2b5c; color: #fff; padding: 7px 10px; font-weight: 700; font-size: 12px; }
.box { border: 1px solid #d7dfec; padding: 10px; }
.grid td { vertical-align: top; width: 50%; }
.kv th { width: 35%; text-align: left; background: #f2f6fb; color: #0b2b5c; }
.kv th, .kv td, .values th, .values td { border: 1px solid #d7dfec; padding: 8px; vertical-align: top; }
.values th { background: #f2f6fb; color: #0b2b5c; text-align: left; }
.num { text-align: right; }
.highlight, .note { border: 2px solid #0b2b5c; background: #eef5ff; padding: 9px; font-weight: 700; margin: 8px 0; }
.owz li { margin-bottom: 5px; }
.signatures { margin-top: 34px; }
.signatures td { width: 50%; text-align: center; padding-top: 36px; }
.line { border-top: 1px solid #1f2937; padding-top: 7px; width: 80%; margin: 0 auto; }',
1, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP
WHERE NOT EXISTS (SELECT 1 FROM document_pdf_templates WHERE document_type = 'annex');
