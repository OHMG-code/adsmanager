#!/usr/bin/env bash
set -euo pipefail
DOCKER="./scripts/docker.sh"

started_at=$("$DOCKER" inspect crm_db --format '{{.State.StartedAt}}' 2>/dev/null || true)
log_args=(logs --tail 400)
if [ -n "${started_at}" ] && [ "${started_at}" != "0001-01-01T00:00:00Z" ]; then
  log_args+=(--since "${started_at}")
fi
log_args+=(crm_db)

"$DOCKER" "${log_args[@]}" 2>&1 | rg -n "Access denied|Unknown database|Can't connect|ERROR" || true
