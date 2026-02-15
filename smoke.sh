#!/usr/bin/env bash
set -euo pipefail

DOCKER="./scripts/docker.sh"

"$DOCKER" run --rm --network host crm-app \
  curl -s -o /dev/null -w "%{http_code}" http://localhost:8080
