#!/usr/bin/env bash
set -euo pipefail

DOCKER="./scripts/docker.sh"
TARGET="${1:-/}"
IN_APP_CONTAINER=0
if [[ -f /.dockerenv ]] && ! command -v docker >/dev/null 2>&1; then
  IN_APP_CONTAINER=1
fi

if [[ "$TARGET" == http://* || "$TARGET" == https://* ]]; then
  URL="$TARGET"
else
  URL="http://localhost:8080${TARGET}"
fi

if [[ "$IN_APP_CONTAINER" == "1" ]]; then
  URL="${URL/http:\/\/localhost:8080/http:\/\/127.0.0.1}"
  curl -s -o /dev/null -w "%{http_code}" "$URL"
else
  "$DOCKER" run --rm --network host app-app \
    curl -s -o /dev/null -w "%{http_code}" "$URL"
fi
