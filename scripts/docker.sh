#!/usr/bin/env bash
set -euo pipefail

if [ "$#" -eq 0 ]; then
  echo "Usage: $0 <docker-args...>" >&2
  exit 1
fi

if docker ps >/dev/null 2>&1; then
  exec docker "$@"
fi

if command -v sg >/dev/null 2>&1 && sg docker -c "docker ps >/dev/null 2>&1"; then
  cmd="docker"
  for arg in "$@"; do
    printf -v quoted "%q" "$arg"
    cmd+=" $quoted"
  done
  exec sg docker -c "$cmd"
fi

if sudo -n docker ps >/dev/null 2>&1; then
  exec sudo -n docker "$@"
fi

echo "ERROR: Cannot access Docker daemon. Use docker group, sg docker, or passwordless sudo." >&2
exit 1
