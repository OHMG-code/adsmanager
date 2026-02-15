#!/usr/bin/env bash
set -euo pipefail

DOCKER="./scripts/docker.sh"
BASE_URL="${BASE_URL:-http://localhost:8080}"

http_code() {
  local target="${1:-/}"
  ./smoke.sh "$target"
}

fetch_body() {
  local target="${1:-/}"
  local url="$target"
  if [[ "$url" != http://* && "$url" != https://* ]]; then
    url="${BASE_URL}${url}"
  fi
  "$DOCKER" run --rm --network host crm-app curl -sS "$url"
}

fail_with_logs() {
  local msg="$1"
  echo "[fail] $msg"
  "$DOCKER" logs --tail 200 crm_app || true
  exit 1
}

assert_non_500_and_allowed() {
  local endpoint="$1"
  local code="$2"

  if [[ -z "$code" ]]; then
    fail_with_logs "$endpoint returned empty HTTP code"
  fi

  if [[ "$code" == "500" ]]; then
    fail_with_logs "$endpoint returned 500"
  fi

  case "$code" in
    200|302|401|403)
      ;;
    *)
      fail_with_logs "$endpoint returned unexpected HTTP code: $code"
      ;;
  esac
}

load_db_cfg() {
  if [[ -n "${TEST_DB_NAME:-}" && -n "${TEST_DB_USER:-}" ]]; then
    return
  fi

  local cfg
  cfg="$("$DOCKER" exec crm_app bash -lc 'php -r '\''$c=include "/var/www/html/config/db.local.php"; echo ($c["name"] ?? "")."|".($c["user"] ?? "")."|".($c["pass"] ?? "");'\''' 2>/dev/null || true)"
  TEST_DB_NAME="${cfg%%|*}"
  local rest="${cfg#*|}"
  TEST_DB_USER="${rest%%|*}"
  TEST_DB_PASS="${rest#*|}"

  if [[ -z "$TEST_DB_NAME" || -z "$TEST_DB_USER" ]]; then
    fail_with_logs "cannot resolve DB credentials from config/db.local.php"
  fi
}

db_exec() {
  local sql="$1"
  load_db_cfg
  printf '%s\n' "$sql" | "$DOCKER" exec -i crm_db bash -lc "mariadb -N -u\"$TEST_DB_USER\" -p\"$TEST_DB_PASS\" \"$TEST_DB_NAME\""
}

db_query_one() {
  local sql="$1"
  db_exec "$sql" | tr -d '\r' | head -n1
}

app_php() {
  local code="$1"
  "$DOCKER" exec -i crm_app php -r "$code"
}
