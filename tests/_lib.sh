#!/usr/bin/env bash
set -euo pipefail

DOCKER="./scripts/docker.sh"
BASE_URL="${BASE_URL:-http://localhost:8080}"

http_code() {
  local target="${1:-/}"
  ./smoke.sh "$target"
}

fetch_body() {
  local target="${1:-/}"
  local url="$target"
  if [[ "$url" != http://* && "$url" != https://* ]]; then
    url="${BASE_URL}${url}"
  fi
  "$DOCKER" run --rm --network host crm-app curl -sS "$url"
}

fail_with_logs() {
  local msg="$1"
  echo "[fail] $msg"
  "$DOCKER" logs --tail 200 crm_app || true
  exit 1
}

assert_non_500_and_allowed() {
  local endpoint="$1"
  local code="$2"

  if [[ -z "$code" ]]; then
    fail_with_logs "$endpoint returned empty HTTP code"
  fi

  if [[ "$code" == "500" ]]; then
    fail_with_logs "$endpoint returned 500"
  fi

  case "$code" in
    200|302|401|403)
      ;;
    *)
      fail_with_logs "$endpoint returned unexpected HTTP code: $code"
      ;;
  esac
}
