#!/usr/bin/env bash
set -euo pipefail

source ./tests/_lib.sh

ENDPOINT="/spoty.php"
CODE="$(http_code "$ENDPOINT" || true)"
echo "[spoty] $ENDPOINT => $CODE"
assert_non_500_and_allowed "$ENDPOINT" "$CODE"

if [[ "$CODE" == "200" ]]; then
  BODY="$(fetch_body "$ENDPOINT" || true)"
  if ! printf '%s' "$BODY" | rg -qi "Spoty|Spoty reklamowe|Aktywne"; then
    fail_with_logs "spoty page loaded but expected marker text is missing"
  fi
fi

echo "[ok] spoty smoke"
