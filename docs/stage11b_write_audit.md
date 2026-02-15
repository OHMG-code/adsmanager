# Stage 11B write audit
Date: 2026-01-27

## Schema check (production)
Nie mam bezpośredniego dostępu do produkcyjnej bazy, więc nie mogę wykonać `SHOW CREATE TABLE klienci` z tego środowiska.
Na potrzeby wdrożenia przyjąłem schemat z repo (public/shema.sql + db_schema.php) i dodałem migrację, która liberalizuje NULL na legacy kolumnach firmowych.

## Zmiany
1) Migracja SQL
- `sql/migrations/2026_01_27_11B_klienci_legacy_nullable.sql`
  - ustawia `klienci.nip`, `klienci.nazwa_firmy`, `klienci.regon`, `klienci.adres` jako NULL

2) Fallback kontrolowany (tylko INSERT)
- `public/handlers/client_save.php`
  - jeśli kolumna firmowa w `klienci` jest NOT NULL bez defaultu, na INSERT ustawiane są placeholdery:
    - `nip = '0000000000'`
    - `nazwa_firmy = '(legacy)'`
    - `regon = ''`
    - `adres = ''`
  - jeśli `CompanyWriter` zwróci `ok=false`, a placeholder był użyty, nowo utworzony rekord klienta jest usuwany (brak trwałego inserta).

## Uwagi
- Docelowo migracja powinna usunąć potrzebę placeholderów.
- Jeśli w produkcji są dodatkowe NOT NULL w `klienci` (np. miejscowosc/wojewodztwo), należy je dopisać do migracji lub placeholderów.
