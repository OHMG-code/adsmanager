#!/usr/bin/env bash
set -euo pipefail

source ./tests/_lib.sh

client_name="S3_KAMPANIA_$(date +%s)_$RANDOM"
campaign_name="${client_name}_A"
weekly_id=""
session_id="$(create_privileged_session)"
csrf_token=""

http_code_from_headers() {
  awk '/^HTTP\// { code=$2 } END { print code }'
}

header_location() {
  awk 'BEGIN { IGNORECASE=1 } /^Location:/ { print $2 }' | tr -d '\r'
}

request_headers() {
  "$DOCKER" run --rm --network host crm-app curl -sS -D - -o /dev/null -H "Cookie: PHPSESSID=${session_id}" "$@"
}

cleanup() {
  db_exec "DELETE FROM kampanie_tygodniowe WHERE klient_nazwa = '${client_name}';" >/dev/null 2>&1 || true
  db_exec "DELETE FROM kampanie WHERE klient_nazwa = '${client_name}';" >/dev/null 2>&1 || true
}
trap cleanup EXIT

csrf_token="$("$DOCKER" run --rm --network host crm-app curl -sS -H "Cookie: PHPSESSID=${session_id}" "http://localhost:8080/kalkulator_tygodniowy.php" | grep -o 'name=\"csrf_token\" value=\"[^\"]*\"' | sed -E 's/.*value=\"([^\"]*)\"/\1/' | head -n1)"
if [[ -z "$csrf_token" ]]; then
  fail_with_logs "cannot resolve CSRF token for kampania save"
fi

create_headers="$(request_headers -X POST \
  --data-urlencode "csrf_token=${csrf_token}" \
  --data-urlencode "klient_nazwa=${client_name}" \
  --data-urlencode "nazwa_kampanii=${campaign_name}" \
  --data-urlencode "kampania_tygodniowa_id=0" \
  --data-urlencode "dlugosc=30" \
  --data-urlencode "data_start=2026-02-01" \
  --data-urlencode "data_koniec=2026-02-07" \
  --data-urlencode "rabat=0" \
  --data-urlencode "emisja_json={\"mon\":{\"06:00\":1}}" \
  --data-urlencode "sumy[prime]=1" \
  --data-urlencode "sumy[standard]=0" \
  --data-urlencode "sumy[night]=0" \
  --data-urlencode "netto_spoty=100.00" \
  --data-urlencode "netto_dodatki=10.00" \
  --data-urlencode "razem_po_rabacie=110.00" \
  --data-urlencode "razem_brutto=135.30" \
  "http://localhost:8080/zapisz_kampanie.php")"
create_code="$(printf '%s\n' "$create_headers" | http_code_from_headers)"
create_location="$(printf '%s\n' "$create_headers" | header_location)"

echo "[kampania-save] create => ${create_code}"
if [[ "$create_code" != "200" && "$create_code" != "302" ]]; then
  fail_with_logs "kampania create returned unexpected HTTP code: ${create_code}"
fi
if [[ "$create_code" == "302" && "$create_location" != *"/kalkulator_tygodniowy.php?zapis=ok&id="* ]]; then
  fail_with_logs "kampania create redirected to unexpected location: ${create_location}"
fi

weekly_id="$(db_query_one "SELECT id FROM kampanie_tygodniowe WHERE klient_nazwa = '${client_name}' ORDER BY id DESC LIMIT 1;")"
if [[ -z "$weekly_id" ]]; then
  fail_with_logs "weekly campaign row was not created"
fi

update_headers="$(request_headers -X POST \
  --data-urlencode "csrf_token=${csrf_token}" \
  --data-urlencode "klient_nazwa=${client_name}" \
  --data-urlencode "nazwa_kampanii=${campaign_name}_UPD" \
  --data-urlencode "kampania_tygodniowa_id=${weekly_id}" \
  --data-urlencode "dlugosc=30" \
  --data-urlencode "data_start=2026-02-01" \
  --data-urlencode "data_koniec=2026-02-14" \
  --data-urlencode "rabat=5" \
  --data-urlencode "emisja_json={\"mon\":{\"06:00\":2},\"tue\":{\"08:00\":1}}" \
  --data-urlencode "sumy[prime]=3" \
  --data-urlencode "sumy[standard]=0" \
  --data-urlencode "sumy[night]=0" \
  --data-urlencode "netto_spoty=200.00" \
  --data-urlencode "netto_dodatki=20.00" \
  --data-urlencode "razem_po_rabacie=209.00" \
  --data-urlencode "razem_brutto=257.07" \
  "http://localhost:8080/zapisz_kampanie.php")"
update_code="$(printf '%s\n' "$update_headers" | http_code_from_headers)"
update_location="$(printf '%s\n' "$update_headers" | header_location)"

echo "[kampania-save] update => ${update_code}"
if [[ "$update_code" != "200" && "$update_code" != "302" ]]; then
  fail_with_logs "kampania update returned unexpected HTTP code: ${update_code}"
fi
if [[ "$update_code" == "302" && "$update_location" != *"/kalkulator_tygodniowy.php?zapis=ok&id="* ]]; then
  fail_with_logs "kampania update redirected to unexpected location: ${update_location}"
fi

weekly_count="$(db_query_one "SELECT COUNT(*) FROM kampanie_tygodniowe WHERE klient_nazwa = '${client_name}';")"
if [[ "${weekly_count:-0}" != "1" ]]; then
  fail_with_logs "weekly campaign save is not idempotent (expected single row)"
fi

weekly_row="$(db_query_one "SELECT CONCAT(COALESCE(nazwa_kampanii,''),'|',DATE_FORMAT(data_koniec,'%Y-%m-%d'),'|',ROUND(razem_brutto,2)) FROM kampanie_tygodniowe WHERE id = ${weekly_id} LIMIT 1;")"
echo "[kampania-save] row => ${weekly_row}"
if [[ "$weekly_row" != "${campaign_name}_UPD|2026-02-14|257.07" ]]; then
  fail_with_logs "weekly campaign row does not contain expected updated values"
fi

echo "[ok] kampania save"
