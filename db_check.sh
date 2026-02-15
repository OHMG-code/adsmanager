#!/usr/bin/env bash
set -euo pipefail
DOCKER="./scripts/docker.sh"

CFG=$("$DOCKER" exec crm_app bash -lc 'php -r '\''$c=include "/var/www/html/config/db.local.php"; echo $c["name"]."|".$c["user"]."|".$c["pass"];'\''')
DB_NAME="${CFG%%|*}"; REST="${CFG#*|}"
DB_USER="${REST%%|*}"; DB_PASS="${REST#*|}"

TABLES=$("$DOCKER" exec crm_db bash -lc "mariadb -N -u\"$DB_USER\" -p\"$DB_PASS\" \"$DB_NAME\" -e 'SHOW TABLES;'")

if [ -z "$TABLES" ]; then
  echo "DB check failed: no tables found in '$DB_NAME' (or DB not accessible)." >&2
  exit 1
fi

printf "%s\n" "$TABLES" | head -n 30
