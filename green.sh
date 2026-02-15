#!/usr/bin/env bash
set -euo pipefail

echo "[up] docker compose"
docker compose up -d --build

echo "[smoke] http code:"
code="$(./smoke.sh)"
echo "$code"

if [[ "$code" != "200" && "$code" != "302" ]]; then
  echo "[fail] showing logs"
  docker logs --tail 120 crm_app || true
  docker logs --tail 200 crm_db || true
  exit 1
fi

echo "[ok] GREEN"
