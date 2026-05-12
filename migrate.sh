#!/usr/bin/env bash
set -euo pipefail

DOCKER="./scripts/docker.sh"
MODE="${1:-run}"
IN_APP_CONTAINER=0
if [[ -f /.dockerenv ]] && ! command -v docker >/dev/null 2>&1; then
  IN_APP_CONTAINER=1
fi

case "$MODE" in
  run|dry|check)
    ;;
  *)
    echo "Usage: $0 [run|dry|check]"
    exit 1
    ;;
esac

resolve_token() {
  if [[ "$IN_APP_CONTAINER" == "1" ]]; then
    php -r '$c=@include "/var/www/html/config/db.local.php"; if (is_array($c) && isset($c["migrator_token"])) { echo (string)$c["migrator_token"]; }' 2>/dev/null || true
  else
    "$DOCKER" exec crm_app php -r '$c=@include "/var/www/html/config/db.local.php"; if (is_array($c) && isset($c["migrator_token"])) { echo (string)$c["migrator_token"]; }' 2>/dev/null || true
  fi
}

MIGRATOR_TOKEN="$(resolve_token)"
BASE_URL="${BASE_URL:-http://localhost:8080}"
PATH_WITH_QUERY="/admin/migrate_all.php"

if [[ "$MODE" == "run" ]]; then
  if [[ -z "$MIGRATOR_TOKEN" ]]; then
    echo "[fail] Missing migrator token in config/db.local.php"
    exit 1
  fi
  PATH_WITH_QUERY="${PATH_WITH_QUERY}?token=${MIGRATOR_TOKEN}&confirm=YES"
else
  PATH_WITH_QUERY="${PATH_WITH_QUERY}?dry=1"
  if [[ -n "$MIGRATOR_TOKEN" ]]; then
    PATH_WITH_QUERY="${PATH_WITH_QUERY}&token=${MIGRATOR_TOKEN}"
  fi
fi

HTTP_CODE="$(./smoke.sh "${BASE_URL}${PATH_WITH_QUERY}" || true)"
echo "[migrate] mode=${MODE} http=${HTTP_CODE}"

if [[ "$MODE" == "check" ]]; then
  case "$HTTP_CODE" in
    200|302|401|403)
      exit 0
      ;;
    500)
      echo "[fail] migration endpoint returned 500"
      if [[ "$IN_APP_CONTAINER" == "1" ]]; then
        tail -n 200 /var/log/apache2/error.log || true
      else
        "$DOCKER" logs --tail 200 crm_app || true
      fi
      exit 1
      ;;
    *)
      echo "[warn] migration endpoint returned unexpected HTTP code: ${HTTP_CODE}"
      exit 0
      ;;
  esac
fi

if [[ "$HTTP_CODE" != "200" ]]; then
  echo "[fail] migration run failed with HTTP ${HTTP_CODE}"
  if [[ "$IN_APP_CONTAINER" == "1" ]]; then
    tail -n 200 /var/log/apache2/error.log || true
  else
    "$DOCKER" logs --tail 200 crm_app || true
  fi
  exit 1
fi

echo "[ok] migration request completed"
