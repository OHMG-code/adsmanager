#!/usr/bin/env bash
set -euo pipefail
DOCKER="./scripts/docker.sh"

# Pobierz parametry z config/db.local.php (wewnątrz kontenera app)
CFG=$("$DOCKER" exec crm_app bash -lc 'php -r '\''$c=include "/var/www/html/config/db.local.php"; echo $c["name"]."|".$c["user"]."|".$c["pass"];'\''')
DB_NAME="${CFG%%|*}"; REST="${CFG#*|}"
DB_USER="${REST%%|*}"; DB_PASS="${REST#*|}"

DUMP="${1:-sql/install/baseline.sql}"
if [ ! -f "$DUMP" ]; then
  echo "Brak pliku SQL: $DUMP"
  exit 1
fi

echo "Import SQL: $DUMP -> DB=$DB_NAME USER=$DB_USER"
if rg -q "utf8mb4_0900_ai_ci" "$DUMP"; then
  echo "Info: replacing utf8mb4_0900_ai_ci -> utf8mb4_unicode_ci for MariaDB compatibility"
  sed 's/utf8mb4_0900_ai_ci/utf8mb4_unicode_ci/g' "$DUMP" | \
    "$DOCKER" exec -i crm_db bash -lc "mariadb -u\"$DB_USER\" -p\"$DB_PASS\" \"$DB_NAME\""
else
  "$DOCKER" exec -i crm_db bash -lc "mariadb -u\"$DB_USER\" -p\"$DB_PASS\" \"$DB_NAME\"" < "$DUMP"
fi
echo "OK"
