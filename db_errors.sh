#!/usr/bin/env bash
set -euo pipefail
DOCKER="./scripts/docker.sh"
if [[ -f /.dockerenv ]] && ! command -v docker >/dev/null 2>&1; then
  echo "[info] db_errors.sh running inside crm_app; DB container logs require host docker access."
  exit 0
fi

started_at=$("$DOCKER" inspect crm_db --format '{{.State.StartedAt}}' 2>/dev/null || true)
log_args=(logs --tail 400)
if [ -n "${started_at}" ] && [ "${started_at}" != "0001-01-01T00:00:00Z" ]; then
  log_args+=(--since "${started_at}")
fi
log_args+=(crm_db)

if command -v rg >/dev/null 2>&1; then
  "$DOCKER" "${log_args[@]}" 2>&1 | rg -n "Access denied|Unknown database|Can't connect|ERROR" || true
else
  "$DOCKER" "${log_args[@]}" 2>&1 | grep -En "Access denied|Unknown database|Can't connect|ERROR" || true
fi
