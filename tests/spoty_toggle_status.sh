#!/usr/bin/env bash
set -euo pipefail

source ./tests/_lib.sh

spot_name="S3_TOGGLE_$(date +%s)_$RANDOM"
spot_id=""

cleanup() {
  if [[ -n "$spot_id" ]]; then
    db_exec "DELETE FROM spoty WHERE id = ${spot_id};" >/dev/null 2>&1 || true
  fi
}
trap cleanup EXIT

db_exec "INSERT INTO spoty (nazwa_spotu, dlugosc, dlugosc_s, data_start, data_koniec, status, aktywny) VALUES ('${spot_name}', '30', 30, CURDATE(), DATE_ADD(CURDATE(), INTERVAL 10 DAY), 'Aktywny', 1);"

spot_id="$(db_query_one "SELECT id FROM spoty WHERE nazwa_spotu = '${spot_name}' ORDER BY id DESC LIMIT 1;")"
if [[ -z "$spot_id" ]]; then
  fail_with_logs "cannot find inserted test spot for toggle"
fi

app_php "require_once '/var/www/html/config/config.php'; require_once '/var/www/html/services/SpotStatusService.php'; SpotStatusService::setSpotActive(\$pdo, ${spot_id}, false);" >/dev/null

status_after_off="$(db_query_one "SELECT COALESCE(status, '') FROM spoty WHERE id = ${spot_id} LIMIT 1;")"
active_after_off="$(db_query_one "SELECT COALESCE(aktywny, -1) FROM spoty WHERE id = ${spot_id} LIMIT 1;")"
if [[ "$status_after_off" != "Nieaktywny" || "$active_after_off" != "0" ]]; then
  fail_with_logs "toggle to inactive did not update DB state"
fi

app_php "require_once '/var/www/html/config/config.php'; require_once '/var/www/html/services/SpotStatusService.php'; SpotStatusService::setSpotActive(\$pdo, ${spot_id}, true);" >/dev/null

status_after_on="$(db_query_one "SELECT COALESCE(status, '') FROM spoty WHERE id = ${spot_id} LIMIT 1;")"
active_after_on="$(db_query_one "SELECT COALESCE(aktywny, -1) FROM spoty WHERE id = ${spot_id} LIMIT 1;")"

echo "[spoty-toggle] id=${spot_id} off=${status_after_off}/${active_after_off} on=${status_after_on}/${active_after_on}"

if [[ "$status_after_on" != "Aktywny" || "$active_after_on" != "1" ]]; then
  fail_with_logs "toggle to active did not update DB state"
fi

echo "[ok] spoty toggle status"
