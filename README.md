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
- If `companies` is missing, the page warns and the bootstrap migration `2026_01_27_00_create_companies.sql` is prioritized by the migrator. If duplicate `nip` values exist it will skip `11C_unique_companies_nip.sql` automatically while noting the reason. Files that contain `DELIMITER` are blocked and must be run manually via the MySQL CLI.

## Browser update flow (no reinstall)

- Use `public/admin/updates.php` for post-deploy upgrade (administrator/manager only).
- Set remote manifest URL in config: `UPDATE_MANIFEST_URL` (env) or `config/db.local.php` key `update_manifest_url` (fallback remains `release.json.manifest_url`).
- Minimal supported `manifest.json` payload:
  - `version` (remote app version),
  - `download_url` (HTTPS ZIP for release package),
  - `changelog` (array of short lines or newline string).
- The screen compares `APP_VERSION` (code) and `DB_VERSION` (schema in `app_meta`), shows pending migrations, requires explicit backup confirmation, and runs migrations in safe web batches.
- Detailed operational steps are in `docs/update_guide.md`.

## Database migrations

- Run `php tools/migrate_all.php` from the project root to apply all SQL migration files in deterministic order. Logs are appended to `tools/migrate_all.log` and `storage/logs/migrate_all.log`.
- Add `--dry-run=1` to preview the plan (which migrations are pending vs. already recorded in `schema_migrations`) without touching the database; the planned ordering reflects the bootstrap, priority overrides, and alphabetical fallbacks.
- If the `companies` table is missing, the migrator prioritizes `sql/migrations/2026_01_27_00_create_companies.sql` in the migration plan.
- Release packages rely on `sql/install/baseline.sql` + `sql/migrations/*.sql`; they do not require historical full-dump imports.
- The `11C_unique_companies_nip.sql` migration is skipped automatically whenever duplicate NIP values are detected, and an informational warning is logged.

## Optional DB bootstrap on empty Docker volume (dev-only)

- Set `DB_OPTIONAL_INIT_BOOTSTRAP=1` before `docker compose up` to let the MariaDB container import `sql/production_bootstrap.sql` during first initialization of an empty volume.
- The import runs only through `/docker-entrypoint-initdb.d`, so it does not rerun on later restarts of the same volume.
- This path is for local Docker bootstrap only (not for shared-hosting release ZIP installation).
- The bootstrap SQL is idempotent and non-destructive, but it is only a schema bootstrap. Keep using the regular migrator/install flow for the full application setup.

## GitHub release update channel

- Default manifest URL is configured in `release.json` and points to `release-manifest/stable/manifest.json` in this repo.
- Current stable manifest uses GitHub archive ZIP (`main.zip`), so updates work without attaching release assets.
- Generate/update manifest (auto-builds archive URL from origin repo):
  - `php tools/generate_update_manifest.php --ref=main --changelog="Line 1\nLine 2"`
- Optional: if you publish a dedicated release asset, override URL explicitly:
  - `php tools/generate_update_manifest.php --download-url="https://github.com/OHMG-code/adsmanager/releases/download/vYYYY.MM.DD.N/crm-update.zip" --changelog="Line 1\nLine 2"`
- Commit updated `release-manifest/stable/manifest.json` so deployed instances can see the new version.
