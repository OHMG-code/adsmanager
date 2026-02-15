#!/usr/bin/env bash
set -euo pipefail

source ./tests/_lib.sh

spot_name="S3_AUTO_$(date +%s)_$RANDOM"
spot_id=""

cleanup() {
  if [[ -n "$spot_id" ]]; then
    db_exec "DELETE FROM spoty WHERE id = ${spot_id};" >/dev/null 2>&1 || true
  fi
}
trap cleanup EXIT

# Ensure latest spot columns exist before insert.
app_php 'require_once "/var/www/html/config/config.php"; require_once "/var/www/html/services/SpotStatusService.php"; SpotStatusService::autoDeactivateExpired($pdo);' >/dev/null

db_exec "INSERT INTO spoty (nazwa_spotu, dlugosc, dlugosc_s, data_start, data_koniec, status, aktywny) VALUES ('${spot_name}', '30', 30, DATE_SUB(CURDATE(), INTERVAL 5 DAY), DATE_SUB(CURDATE(), INTERVAL 1 DAY), 'Aktywny', 1);"

spot_id="$(db_query_one "SELECT id FROM spoty WHERE nazwa_spotu = '${spot_name}' ORDER BY id DESC LIMIT 1;")"
if [[ -z "$spot_id" ]]; then
  fail_with_logs "cannot find inserted test spot"
fi

app_php 'require_once "/var/www/html/config/config.php"; require_once "/var/www/html/services/SpotStatusService.php"; SpotStatusService::autoDeactivateExpired($pdo);' >/dev/null

status_value="$(db_query_one "SELECT COALESCE(status, '') FROM spoty WHERE id = ${spot_id} LIMIT 1;")"
active_value="$(db_query_one "SELECT COALESCE(aktywny, -1) FROM spoty WHERE id = ${spot_id} LIMIT 1;")"

echo "[spoty-auto] id=${spot_id} status=${status_value} aktywny=${active_value}"

if [[ "$status_value" != "Nieaktywny" || "$active_value" != "0" ]]; then
  fail_with_logs "spot auto-deactivate did not set expected inactive status"
fi

echo "[ok] spoty auto-deactivate"
