#!/usr/bin/env bash
set -euo pipefail

CFG=$(docker exec crm_app bash -lc 'php -r '\''$c=include "/var/www/html/config/db.local.php"; echo $c["name"]."|".$c["user"]."|".$c["pass"];'\''')
DB_NAME="${CFG%%|*}"; REST="${CFG#*|}"
DB_USER="${REST%%|*}"; DB_PASS="${REST#*|}"

docker exec crm_db bash -lc "mariadb -u\"$DB_USER\" -p\"$DB_PASS\" -e 'SHOW TABLES;' \"$DB_NAME\" | head -n 30"
