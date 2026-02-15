#!/usr/bin/env bash
set -euo pipefail

source ./tests/_lib.sh

ENDPOINTS=(
  "/kalkulator.php"
  "/kalkulator_tygodniowy.php"
)

for endpoint in "${ENDPOINTS[@]}"; do
  code="$(http_code "$endpoint" || true)"
  echo "[kalkulator] $endpoint => $code"
  assert_non_500_and_allowed "$endpoint" "$code"

  if [[ "$code" == "200" ]]; then
    body="$(fetch_body "$endpoint" || true)"
    if ! printf '%s' "$body" | rg -qi "Kalkulator"; then
      fail_with_logs "kalkulator page loaded but expected marker text is missing: $endpoint"
    fi
  fi
done

echo "[ok] kalkulator smoke"
