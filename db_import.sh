#!/usr/bin/env bash
set -euo pipefail

# Pobierz parametry z config/db.local.php (wewnątrz kontenera app)
CFG=$(docker exec crm_app bash -lc 'php -r '\''$c=include "/var/www/html/config/db.local.php"; echo $c["name"]."|".$c["user"]."|".$c["pass"];'\''')
DB_NAME="${CFG%%|*}"; REST="${CFG#*|}"
DB_USER="${REST%%|*}"; DB_PASS="${REST#*|}"

DUMP="${1:-sql/01214144_crm.sql}"
if [ ! -f "$DUMP" ]; then
  echo "Brak dumpa: $DUMP"
  exit 1
fi

echo "Import: $DUMP -> DB=$DB_NAME USER=$DB_USER"
docker exec -i crm_db bash -lc "mariadb -u\"$DB_USER\" -p\"$DB_PASS\" \"$DB_NAME\"" < "$DUMP"
echo "OK"
