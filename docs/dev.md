# CRM dev quickstart
- Start and validate stack: `./green.sh` (compose up, smoke, DB check, endpoint checks).
- If `green.sh` fails, run diagnostics: `./doctor.sh`.
- If DB check fails (empty DB), run: `./db_import.sh`, then `./db_check.sh`.
- Run migrations in dev (idempotent): `./migrate.sh` (or `./migrate.sh dry` for dry-run).
- Migration health-only check (no write): `./migrate.sh check`.
- App URL: `http://localhost:8080` (Apache/PHP container `crm_app`).
- DB port: `localhost:3307` (MariaDB container `crm_db`).
- Logs: `./scripts/docker.sh logs --tail 200 crm_app` and `./scripts/docker.sh logs --tail 400 crm_db`.
