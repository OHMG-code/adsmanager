#!/usr/bin/env bash
set -euo pipefail

DOCKER="./scripts/docker.sh"
TARGET="${1:-/}"

if [[ "$TARGET" == http://* || "$TARGET" == https://* ]]; then
  URL="$TARGET"
else
  URL="http://localhost:8080${TARGET}"
fi

"$DOCKER" run --rm --network host crm-app \
  curl -s -o /dev/null -w "%{http_code}" "$URL"
