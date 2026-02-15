#!/usr/bin/env bash
set -euo pipefail
DOCKER="./scripts/docker.sh"
"$DOCKER" exec -it crm_db bash
