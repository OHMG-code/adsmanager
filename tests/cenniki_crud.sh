#!/usr/bin/env bash
set -euo pipefail

source ./tests/_lib.sh

item_name="S3_CENNIK_$(date +%s)_$RANDOM"
item_id=""
session_id="$(create_privileged_session)"

http_code_from_headers() {
  awk '/^HTTP\// { code=$2 } END { print code }'
}

http_location_from_headers() {
  awk 'BEGIN { IGNORECASE=1 } /^Location:/ { print $2 }' | tr -d '\r' | tail -n1
}

request_headers() {
  "$DOCKER" exec crm_app curl -sS -D - -o /dev/null -H "Cookie: PHPSESSID=${session_id}" "$@"
}

request_body() {
  "$DOCKER" exec crm_app curl -sS -H "Cookie: PHPSESSID=${session_id}" "$@"
}

cleanup() {
  if [[ -n "$item_id" ]]; then
    db_exec "DELETE FROM cennik_wywiady WHERE id = ${item_id};" >/dev/null 2>&1 || true
  else
    db_exec "DELETE FROM cennik_wywiady WHERE nazwa = '${item_name}';" >/dev/null 2>&1 || true
  fi
}
trap cleanup EXIT

csrf_token="$(request_body "http://localhost/cenniki.php" | sed -n 's/.*name="csrf_token" value="\([^"]*\)".*/\1/p' | head -n1)"
if [[ -z "$csrf_token" ]]; then
  fail_with_logs "cannot resolve csrf token for cenniki"
fi

create_headers="$(request_headers -X POST \
  --data-urlencode "csrf_token=${csrf_token}" \
  --data-urlencode "nazwa=${item_name}" \
  --data-urlencode "opis=test create" \
  --data-urlencode "netto=100.00" \
  --data-urlencode "vat=23.00" \
  "http://localhost/dodaj_cennik.php?typ=wywiady")"
create_code="$(printf '%s\n' "$create_headers" | http_code_from_headers)"
create_location="$(printf '%s\n' "$create_headers" | http_location_from_headers)"

echo "[cenniki-crud] create => ${create_code}"
if [[ "$create_code" != "200" && "$create_code" != "302" ]]; then
  fail_with_logs "cenniki create returned unexpected HTTP code: ${create_code}"
fi
if [[ "$create_code" == "302" && "$create_location" != "/cenniki.php?msg=added#wywiady" ]]; then
  fail_with_logs "cenniki create redirected to unexpected location: ${create_location:-<empty>}"
fi

item_id="$(db_query_one "SELECT id FROM cennik_wywiady WHERE nazwa = '${item_name}' ORDER BY id DESC LIMIT 1;")"
if [[ -z "$item_id" ]]; then
  fail_with_logs "cenniki create did not insert row"
fi

update_headers="$(request_headers -X POST \
  --data-urlencode "csrf_token=${csrf_token}" \
  --data-urlencode "nazwa[${item_id}]=${item_name}" \
  --data-urlencode "opis[${item_id}]=test update" \
  --data-urlencode "netto[${item_id}]=150.50" \
  --data-urlencode "vat[${item_id}]=8.00" \
  "http://localhost/zapisz_cennik.php?typ=wywiady")"
update_code="$(printf '%s\n' "$update_headers" | http_code_from_headers)"
update_location="$(printf '%s\n' "$update_headers" | http_location_from_headers)"

echo "[cenniki-crud] update => ${update_code}"
if [[ "$update_code" != "200" && "$update_code" != "302" ]]; then
  fail_with_logs "cenniki update returned unexpected HTTP code: ${update_code}"
fi
if [[ "$update_code" == "302" && "$update_location" != "/cenniki.php?msg=saved#wywiady" ]]; then
  fail_with_logs "cenniki update redirected to unexpected location: ${update_location:-<empty>}"
fi

row_after_update="$(db_query_one "SELECT CONCAT(ROUND(stawka_netto,2),'|',ROUND(stawka_vat,2),'|',ROUND(stawka_netto*(1+stawka_vat/100),2)) FROM cennik_wywiady WHERE id = ${item_id} LIMIT 1;")"
echo "[cenniki-crud] row => ${row_after_update}"
if [[ "$row_after_update" != "150.50|8.00|162.54" ]]; then
  fail_with_logs "cenniki update stored unexpected values"
fi

delete_headers="$(request_headers -X POST \
  --data-urlencode "csrf_token=${csrf_token}" \
  --data-urlencode "id=${item_id}" \
  "http://localhost/usun_cennik.php?typ=wywiady")"
delete_code="$(printf '%s\n' "$delete_headers" | http_code_from_headers)"
delete_location="$(printf '%s\n' "$delete_headers" | http_location_from_headers)"

echo "[cenniki-crud] delete => ${delete_code}"
if [[ "$delete_code" != "200" && "$delete_code" != "302" ]]; then
  fail_with_logs "cenniki delete returned unexpected HTTP code: ${delete_code}"
fi
if [[ "$delete_code" == "302" && "$delete_location" != "/cenniki.php?msg=deleted#wywiady" ]]; then
  fail_with_logs "cenniki delete redirected to unexpected location: ${delete_location:-<empty>}"
fi

remaining="$(db_query_one "SELECT COUNT(*) FROM cennik_wywiady WHERE id = ${item_id};")"
if [[ "${remaining:-1}" != "0" ]]; then
  fail_with_logs "cenniki delete did not remove row"
fi

echo "[ok] cenniki CRUD"
