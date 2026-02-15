# CRM Dev Agent Playbook

## Goal (Definition of Done)
- `curl -s -o /dev/null -w "%{http_code}" http://localhost:8080` returns 200 or 302
- App shows the login page (Ads Manager) and no DB connection errors.

## Allowed actions
- Read/modify files in this repo.
- Run commands to build and operate Docker dev env.

## Primary commands
- docker compose up -d --build
- docker compose ps
- docker logs -n 120 crm_app
- docker logs -n 120 crm_db
- curl -i http://localhost:8080 | head -n 30

## Fix strategy
1) If HTTP 403 -> ensure Apache DocumentRoot points to /var/www/html/public (vhost mount).
2) If DB DNS error -> ensure db.local.php host is 'db' or add network alias.
3) If DB 1045 -> create DB/user according to config/db.local.php.
4) If missing tables -> import latest sql dump or run project migrations.

## Safety
- Do not touch ~/.ssh or host system files outside repo.
- Avoid destructive commands (rm -rf, mkfs, dd, etc.).
