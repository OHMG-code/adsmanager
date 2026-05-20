# Document sales flow checklist

Use this checklist on a staging database with a test client, campaign, spot/audio and SMTP configured.

1. Create an `order` document from `dokument_nowy_zlecenie.php`.
2. Generate PDF v1 from `dokument_podglad.php`.
3. Confirm a row exists in `document_pdf_versions` and the current version can be downloaded.
4. Change status `draft -> issued`.
5. Send the document to the client from `dokument_wyslij.php`.
6. Confirm status changed to `sent`, `document_email_log` has `sent`, and `document_acceptance_tokens` has a hashed active token.
7. Open the public acceptance link from the sent e-mail body.
8. Download PDF through the public link.
9. Accept online.
10. Confirm status changed to `accepted`, `accepted_at` and acceptance metadata are set.
11. Confirm campaign sync log exists and campaign/emission status changed according to document status.
12. Confirm audit events exist for creation, PDF, status, e-mail, online acceptance and sync.
13. Confirm attempts to regenerate PDF, send e-mail or change status on `accepted` are blocked.
14. Create another sent document, reject it online, and confirm the rejection panel appears on preview.
15. Use "Utwórz nową wersję po odrzuceniu" and confirm a new draft is created with a new number and without copied PDF/tokens.
16. Verify `dokumenty.php` filters, CSV export, rejected badge, dashboard cards.
17. Verify `dokumenty_alerty.php` and `dokumenty_raport.php`.
18. Verify no public endpoint exposes a document without a valid token.
