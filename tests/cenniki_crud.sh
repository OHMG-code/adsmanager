#!/usr/bin/env bash
set -euo pipefail

source ./tests/_lib.sh

item_name="S3_CENNIK_$(date +%s)_$RANDOM"
item_id=""

cleanup() {
  if [[ -n "$item_id" ]]; then
    db_exec "DELETE FROM cennik_wywiady WHERE id = ${item_id};" >/dev/null 2>&1 || true
  else
    db_exec "DELETE FROM cennik_wywiady WHERE nazwa = '${item_name}';" >/dev/null 2>&1 || true
  fi
}
trap cleanup EXIT

create_code="$("$DOCKER" run --rm --network host crm-app curl -sS -o /dev/null -w "%{http_code}" -X POST \
  --data-urlencode "nazwa=${item_name}" \
  --data-urlencode "opis=test create" \
  --data-urlencode "netto=100.00" \
  --data-urlencode "vat=23.00" \
  "http://localhost:8080/dodaj_cennik.php?typ=wywiady")"

echo "[cenniki-crud] create => ${create_code}"
if [[ "$create_code" != "200" && "$create_code" != "302" ]]; then
  fail_with_logs "cenniki create returned unexpected HTTP code: ${create_code}"
fi

item_id="$(db_query_one "SELECT id FROM cennik_wywiady WHERE nazwa = '${item_name}' ORDER BY id DESC LIMIT 1;")"
if [[ -z "$item_id" ]]; then
  fail_with_logs "cenniki create did not insert row"
fi

update_code="$("$DOCKER" run --rm --network host crm-app curl -sS -o /dev/null -w "%{http_code}" -X POST \
  --data-urlencode "nazwa[${item_id}]=${item_name}" \
  --data-urlencode "opis[${item_id}]=test update" \
  --data-urlencode "netto[${item_id}]=150.50" \
  --data-urlencode "vat[${item_id}]=8.00" \
  "http://localhost:8080/zapisz_cennik.php?typ=wywiady")"

echo "[cenniki-crud] update => ${update_code}"
if [[ "$update_code" != "200" && "$update_code" != "302" ]]; then
  fail_with_logs "cenniki update returned unexpected HTTP code: ${update_code}"
fi

row_after_update="$(db_query_one "SELECT CONCAT(ROUND(stawka_netto,2),'|',ROUND(stawka_vat,2),'|',ROUND(stawka_netto*(1+stawka_vat/100),2)) FROM cennik_wywiady WHERE id = ${item_id} LIMIT 1;")"
echo "[cenniki-crud] row => ${row_after_update}"
if [[ "$row_after_update" != "150.50|8.00|162.54" ]]; then
  fail_with_logs "cenniki update stored unexpected values"
fi

delete_code="$("$DOCKER" run --rm --network host crm-app curl -sS -o /dev/null -w "%{http_code}" -X POST \
  --data-urlencode "id=${item_id}" \
  "http://localhost:8080/usun_cennik.php?typ=wywiady")"

echo "[cenniki-crud] delete => ${delete_code}"
if [[ "$delete_code" != "200" && "$delete_code" != "302" ]]; then
  fail_with_logs "cenniki delete returned unexpected HTTP code: ${delete_code}"
fi

remaining="$(db_query_one "SELECT COUNT(*) FROM cennik_wywiady WHERE id = ${item_id};")"
if [[ "${remaining:-1}" != "0" ]]; then
  fail_with_logs "cenniki delete did not remove row"
fi

echo "[ok] cenniki CRUD"
