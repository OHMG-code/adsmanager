#!/usr/bin/env bash
set -euo pipefail

source ./tests/_lib.sh

ENDPOINT="/cenniki.php"
CODE="$(http_code "$ENDPOINT" || true)"
echo "[cenniki] $ENDPOINT => $CODE"
assert_non_500_and_allowed "$ENDPOINT" "$CODE"

if [[ "$CODE" == "200" ]]; then
  BODY="$(fetch_body "$ENDPOINT" || true)"
  if ! printf '%s' "$BODY" | rg -qi "Cenniki|Cenniki produkt|Spoty audio"; then
    fail_with_logs "cenniki page loaded but expected marker text is missing"
  fi
fi

echo "[ok] cenniki smoke"
