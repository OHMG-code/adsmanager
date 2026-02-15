#!/usr/bin/env bash
set -euo pipefail
DOCKER="./scripts/docker.sh"

echo "== compose ps =="
"$DOCKER" compose ps || true
echo

echo "== smoke =="
./smoke.sh || true
echo

echo "== app logs =="
"$DOCKER" logs --tail 120 crm_app || true
echo

echo "== db errors (tail) =="
./db_errors.sh || true
echo

echo "== apache error log (inside container) =="
"$DOCKER" exec crm_app bash -lc 'tail -n 80 /var/log/apache2/error.log || true' || true
