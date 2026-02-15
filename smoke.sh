#!/usr/bin/env bash
set -euo pipefail

DOCKER="docker"
if ! $DOCKER ps >/dev/null 2>&1; then
  DOCKER="sudo docker"
fi

$DOCKER run --rm --network host crm-app \
  curl -s -o /dev/null -w "%{http_code}" http://localhost:8080
