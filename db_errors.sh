#!/usr/bin/env bash
set -euo pipefail
docker logs --tail 400 crm_db 2>&1 | rg -n "Access denied|Unknown database|Can't connect|ERROR" || true
