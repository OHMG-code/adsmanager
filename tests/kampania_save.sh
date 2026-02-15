#!/usr/bin/env bash
set -euo pipefail

source ./tests/_lib.sh

client_name="S3_KAMPANIA_$(date +%s)_$RANDOM"
campaign_name="${client_name}_A"
weekly_id=""

cleanup() {
  db_exec "DELETE FROM kampanie_tygodniowe WHERE klient_nazwa = '${client_name}';" >/dev/null 2>&1 || true
  db_exec "DELETE FROM kampanie WHERE klient_nazwa = '${client_name}';" >/dev/null 2>&1 || true
}
trap cleanup EXIT

create_code="$("$DOCKER" run --rm --network host crm-app curl -sS -o /dev/null -w "%{http_code}" -X POST \
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

echo "[kampania-save] create => ${create_code}"
if [[ "$create_code" != "200" && "$create_code" != "302" ]]; then
  fail_with_logs "kampania create returned unexpected HTTP code: ${create_code}"
fi

weekly_id="$(db_query_one "SELECT id FROM kampanie_tygodniowe WHERE klient_nazwa = '${client_name}' ORDER BY id DESC LIMIT 1;")"
if [[ -z "$weekly_id" ]]; then
  fail_with_logs "weekly campaign row was not created"
fi

update_code="$("$DOCKER" run --rm --network host crm-app curl -sS -o /dev/null -w "%{http_code}" -X POST \
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

echo "[kampania-save] update => ${update_code}"
if [[ "$update_code" != "200" && "$update_code" != "302" ]]; then
  fail_with_logs "kampania update returned unexpected HTTP code: ${update_code}"
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
