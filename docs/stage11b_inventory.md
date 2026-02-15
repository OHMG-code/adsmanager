# Stage 11B inventory: writes to klienci (company data)
Date: 2026-01-27

Scope: find all INSERT/UPDATE paths that write company fields in `klienci` (name, NIP/REGON/KRS, address).
Method: ripgrep + manual inspection of SQL statements in PHP files.

## Writes to klienci (INSERT/UPDATE touching company fields)

1) public/add_client.php
- Lines: 60-83
- Operation: INSERT INTO klienci
- Company fields written: nip, nazwa_firmy, regon, adres, miejscowosc, wojewodztwo
- Notes: also writes telefon/email/status/ostatni_kontakt/notatka/potencjal/przypomnienie/data_dodania (+ optional owner/assigned).

2) public/zapisz_klienta.php
- Lines: 26-35
- Operation: UPDATE klienci
- Company fields written: nip, nazwa_firmy, regon, adres, miejscowosc, wojewodztwo
- Notes: also updates status/potencjal/ostatni_kontakt/przypomnienie/notatka.

3) public/klient_edytuj.php
- Lines: 147-199
- Operation: UPDATE klienci (dynamic setParts)
- Company fields written: nip, nazwa_firmy, regon, adres, miejscowosc, wojewodztwo
- Notes: also updates telefon/email/status/potencjal/ostatni_kontakt/przypomnienie/notatka/branza + kontakt_* fields.

4) public/lead.php
- Lines: 613-661
- Operation: INSERT INTO klienci (lead conversion)
- Company fields written: nip, nazwa_firmy
- Notes: no address fields written here; also sets telefon/email/notatka/status/data_dodania + owner/assigned/source_lead_id.

5) public/cron/stage10b_backfill_companies.php
- Lines: 211-212
- Operation: UPDATE klienci
- Fields: company_id only (not company data, but touches table).

## Missing writes for address breakdown fields
No INSERT/UPDATE paths found for: ulica, nr_nieruchomosci/nr_lokalu, kod_pocztowy, gmina, powiat, kraj, wojewodztwo (beyond the single `wojewodztwo` column). The legacy `klienci` table (public/shema.sql) only contains `adres`, `miejscowosc`, `wojewodztwo` as address fields.

## UI forms / flows based on klienci
- public/dodaj_klienta.php -> posts to public/add_client.php (NIP, nazwa_firmy, regon, adres, miasto, wojewodztwo).
- public/edytuj_klienta.php -> posts to public/zapisz_klienta.php (same fields).
- public/klient_edytuj.php -> self-post; updates fields dynamically (nip/nazwa/regon/adres/miasto/wojewodztwo + contact info).
- public/lead.php -> converts lead into klienci (nip/nazwa only; no address).

## Central handler?
No single central write handler. Writes are distributed across add_client.php, zapisz_klienta.php, klient_edytuj.php, and lead.php.

## Suggested minimal refactor hook
Create one write layer (e.g. public/includes/client_writer.php) with functions:
- insertClientFromForm($data)
- updateClientFromForm($id, $data)
- insertClientFromLead($leadRow)
Then replace calls in add_client.php, zapisz_klienta.php, klient_edytuj.php, lead.php to use this layer. This gives one interception point to map into companies + legacy sync in stage 11B.
