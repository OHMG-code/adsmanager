#!/usr/bin/env bash
set -euo pipefail

echo "[up] docker compose"
docker compose up -d --build

echo "[smoke] http code:"
code="$(./smoke.sh)"
echo "$code"
if [[ "$code" != "200" && "$code" != "302" ]]; then
  echo "[fail] smoke"
  ./doctor.sh || true
  exit 1
fi

echo "[db] quick schema check"
if ! ./db_check.sh >/dev/null 2>&1; then
  echo "[warn] db_check failed; dumping diagnostics"
  ./db_errors.sh || true
  exit 1
fi

echo "[app] dashboard check (best effort)"
# dashboard bywa za auth; sprawdzimy czy nie ma 500 (200/302 OK)
dash="$(curl -s -o /dev/null -w "%{http_code}" http://localhost:8080/dashboard.php || true)"
echo "dashboard.php => $dash"
if [[ "$dash" != "200" && "$dash" != "302" ]]; then
  echo "[warn] dashboard not 200/302; showing app logs"
  docker logs --tail 160 crm_app || true
  exit 1
fi

echo "[ok] GREEN"
