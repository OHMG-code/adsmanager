INSERT INTO email_templates (template_key, name, subject_template, body_template, is_active, created_at, updated_at)
SELECT 'document_acceptance_reminder', 'Przypomnienie o akceptacji dokumentu', 'Przypomnienie o akceptacji dokumentu {DOCUMENT_NUMBER}',
'Dzień dobry,

przypominamy o dokumencie {DOCUMENT_NUMBER} oczekującym na akceptację.

Link do pobrania i akceptacji dokumentu:
{ACCEPTANCE_LINK}

Pozdrawiamy,
{COMPANY_NAME}', 1, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP
WHERE NOT EXISTS (SELECT 1 FROM email_templates WHERE template_key = 'document_acceptance_reminder');
