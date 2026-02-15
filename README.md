# CRM

## VS Code SFTP sync (Natizyskunk.sftp)

- Konfiguracja uzywana przez wtyczke to `.vscode/sftp.json`.
- `.vscode/ftp-sync.json.example` jest tylko przykladem i nie jest uzywany.

## Test uploadu (ignore zostaje)

- `.vscode` jest nadal ignorowane w `sftp.json`.
- Aby przetestowac upload na pliku PHP spoza `.vscode`:
  - W Explorerze wybierz dowolny plik PHP nieobjety `ignore` (np. `config/config.php`).
  - Uruchom z Command Palette: `SFTP: Upload File`.
  - Sprawdz log w `View > Output` i wybierz kanal `sftp` (wpisy typu `Uploading ...` / `Upload done`).

## Reczne wysylanie plikow

- `SFTP: Upload File` wysyla pojedynczy plik.
- `SFTP: Sync Local -> Remote` synchronizuje caly workspace.

## GUS BIR SOAP

- Integracja wymaga SOAP 1.2 i MTOM/XOP (multipart/related; type="application/xop+xml").
- WSDL (prod): https://wyszukiwarkaregon.stat.gov.pl/wsBIR/wsdl/UslugaBIRzewnPubl-ver11-prod.wsdl.
- Klucze: osobno `gus_api_key_prod` i `gus_api_key_test` (selftest blokuje mieszanie srodowisk).
- Operacje i SOAP Action (binding e3, SOAP 1.2):
  - Action/namespace sa odczytywane z WSDL (fallback tylko dla operacji PUBL).
  - GetValue: action `http://CIS/BIR/2014/07/IUslugaBIR/GetValue`, namespace `http://CIS/BIR/2014/07`, param `pNazwaParametru` (zgodnie z WSDL, kontrakt IUslugaBIR).
  - Zaloguj: action `http://CIS/BIR/PUBL/2014/07/IUslugaBIRzewnPubl/Zaloguj`, namespace `http://CIS/BIR/PUBL/2014/07`, param `pKluczUzytkownika`.
  - Wyloguj: action `http://CIS/BIR/PUBL/2014/07/IUslugaBIRzewnPubl/Wyloguj`, namespace `http://CIS/BIR/PUBL/2014/07`, param `pIdentyfikatorSesji`.
  - DaneSzukajPodmioty: action `http://CIS/BIR/PUBL/2014/07/IUslugaBIRzewnPubl/DaneSzukajPodmioty`, namespace `http://CIS/BIR/PUBL/2014/07`, param `pParametryWyszukiwania` (DataContract `http://CIS/BIR/PUBL/2014/07/DataContract`).
- Pusty SID oznacza:
  - `SERVER_EMPTY_SID` gdy w SOAP jest `<ZalogujResult/>` (szukaj KomunikatKod/KomunikatTresc).
  - `CLIENT_PARSE_ERROR` gdy brak poprawnego SOAP envelope lub klient nie odczytal SID.
- WS-Addressing: uzywamy `http://www.w3.org/2005/08/addressing` (zgodnie z WSDL/wsaw:Action).
- SID: wysylany jako HTTP header `sid: <SID>` (plus SOAP Header `sid`).
- Kluczowe logi: `storage/logs/gus_soap.log` (label: `login_empty_sid`, `not_found`, `success`).
- Selftest: `public/gus_lookup.php?selftest=1&debug=1`.
- Test requestow: `php test/gus_request_test.php`.

## Web migration console

- Define `MIGRATOR_TOKEN` in `config/config.php` (or via the `MIGRATOR_TOKEN` environment variable) so the panel can verify requests.
- Visit `public/admin/migrations.php?token=YOUR_TOKEN` as an administrator to see the database name, companies table status, diagnostics (PHP SAPI, storage/logs, `sql/migrations` readability) and the last 200 lines from `storage/logs/migrations_web.log` (falls back to `/tmp/migrations_web.log` when storage isn't writable).
- The page lists `sql/migrations/*.sql` files in deterministic order (priority overrides plus alphabetical) and highlights applied/skipped migrations. A per-file "Run" button posts to `public/handlers/run_migration.php`, while the "Run next" and "Run all (safe)" buttons post to `public/handlers/run_migrations_batch.php` (the batch runner executes at most 5 files or 15 seconds per request, then prompts to continue if more remain). All POST actions require the `token` and a CSRF token, so re-include `?token=...` in the links to keep working.
- If `companies` is missing the page warns (the bootstrap `2026_01_27_00_create_companies.sql` migration runs first or the CLI dump can be imported), and if duplicate `nip` values exist it will skip `11C_unique_companies_nip.sql` automatically while noting the reason. Files that contain `DELIMITER` are blocked and must be run manually via the MySQL CLI.

## Database migrations

- Run `php tools/migrate_all.php` from the project root to apply all SQL migration files in deterministic order. Logs are appended to `tools/migrate_all.log` and `storage/logs/migrate_all.log`.
- Add `--dry-run=1` to preview the plan (which migrations are pending vs. already recorded in `schema_migrations`) without touching the database; the planned ordering reflects the bootstrap, priority overrides, and alphabetical fallbacks.
- If the `companies` table is missing, the CLI will look for `CREATE TABLE companies` inside `sql/01214144_crm.sql`. Without `--force=1` it will print a `mysql -h … -u … -p … < sql/01214144_crm.sql` command and exit so you can import manually; rerun with `--force=1` to let the tool import the dump automatically when it is smaller than 20 MB.
- When the dump does not contain `companies`, the bootstrap migration `sql/migrations/2026_01_27_00_create_companies.sql` is executed first.
- The `11C_unique_companies_nip.sql` migration is skipped automatically whenever duplicate NIP values are detected, and an informational warning is logged.
