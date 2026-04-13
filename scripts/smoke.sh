#!/usr/bin/env bash
set -Eeuo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT_DIR"

print_diag() {
  echo
  echo "[diag] docker compose ps -a"
  docker compose ps -a || true

  echo
  echo "[diag] docker compose logs --tail=200 app"
  docker compose logs --tail=200 app || true

  echo
  echo "[diag] docker compose logs --tail=200 db"
  docker compose logs --tail=200 db || true
}

fail() {
  local reason="$1"
  echo "[smoke] FAIL: ${reason}" >&2
  print_diag
  exit 1
}

echo "[smoke] build + start"
docker compose up -d --build || fail "docker compose up -d --build failed"

echo "[smoke] check services"
running_services="$(docker compose ps --status running --services || true)"
echo "$running_services" | grep -qx "app" || fail "service app is not running"
echo "$running_services" | grep -qx "db" || fail "service db is not running"

echo "[smoke] healthcheck HTTP"
http_ok=0
for _ in $(seq 1 30); do
  if curl -sSI --max-time 5 http://localhost:8080/ >/dev/null; then
    http_ok=1
    break
  fi
  sleep 2
done
[[ "$http_ok" -eq 1 ]] || fail "curl -I http://localhost:8080/ failed"

echo "[smoke] log tail"
docker compose logs --tail=50 app db || true

echo "[smoke] OK"
