#!/usr/bin/env bash
set -euo pipefail

source ./tests/_lib.sh

ENDPOINT="/eksport_pdf.php"
GET_CODE="$(http_code "$ENDPOINT" || true)"
echo "[pdf] GET $ENDPOINT => $GET_CODE"
assert_non_500_and_allowed "$ENDPOINT" "$GET_CODE"

POST_HEADERS="$("$DOCKER" run --rm --network host crm-app curl -sS -D - -o /dev/null -X POST \
  --data-urlencode "klient_nazwa=Smoke Test" \
  --data-urlencode "dlugosc=30" \
  --data-urlencode "data_start=2026-01-01" \
  --data-urlencode "data_koniec=2026-01-07" \
  --data-urlencode "rabat=0" \
  --data-urlencode "emisja_json={\"mon\":{\"06:00\":1}}" \
  --data-urlencode "sumy[prime]=1" \
  --data-urlencode "sumy[standard]=0" \
  --data-urlencode "sumy[night]=0" \
  --data-urlencode "netto_spoty=100.00" \
  --data-urlencode "netto_dodatki=0.00" \
  --data-urlencode "razem_po_rabacie=100.00" \
  --data-urlencode "razem_brutto=123.00" \
  "http://localhost:8080/eksport_pdf.php")"

POST_CODE="$(printf '%s\n' "$POST_HEADERS" | awk '/^HTTP\// {code=$2} END {print code}')"
CONTENT_TYPE="$(printf '%s\n' "$POST_HEADERS" | awk 'BEGIN{IGNORECASE=1} /^Content-Type:/ {print $2}' | tr -d '\r' | tail -n1)"

echo "[pdf] POST $ENDPOINT => $POST_CODE, content-type=${CONTENT_TYPE:-n/a}"

if [[ -z "$POST_CODE" ]]; then
  fail_with_logs "pdf POST returned empty HTTP code"
fi

if [[ "$POST_CODE" == "500" ]]; then
  fail_with_logs "pdf POST returned 500"
fi

if [[ "$POST_CODE" == "200" ]]; then
  if [[ "${CONTENT_TYPE,,}" != application/pdf* ]]; then
    fail_with_logs "pdf POST returned 200 but response is not PDF"
  fi
fi

echo "[ok] pdf smoke"
