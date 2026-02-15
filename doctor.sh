#!/usr/bin/env bash
set -euo pipefail

echo "== compose ps =="
docker compose ps || true
echo

echo "== smoke =="
./smoke.sh || true
echo

echo "== app logs =="
docker logs --tail 120 crm_app || true
echo

echo "== db errors (tail) =="
./db_errors.sh || true
echo

echo "== apache error log (inside container) =="
docker exec crm_app bash -lc 'tail -n 80 /var/log/apache2/error.log || true' || true
