#!/usr/bin/env bash
set -euo pipefail
DOCKER="./scripts/docker.sh"
IN_APP_CONTAINER=0
if [[ -f /.dockerenv ]] && ! command -v docker >/dev/null 2>&1; then
  IN_APP_CONTAINER=1
fi

if [[ "$IN_APP_CONTAINER" == "1" ]]; then
  php -r '
    require "/var/www/html/config/config.php";
    $tables = $pdo->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);
    if (!$tables) {
      fwrite(STDERR, "DB check failed: no tables found.\n");
      exit(1);
    }
    echo implode("\n", array_slice($tables, 0, 30)) . "\n";
  '
  exit 0
fi

CFG=$("$DOCKER" exec crm_app bash -lc 'php -r '\''$c=include "/var/www/html/config/db.local.php"; echo $c["name"]."|".$c["user"]."|".$c["pass"];'\''')
DB_NAME="${CFG%%|*}"; REST="${CFG#*|}"
DB_USER="${REST%%|*}"; DB_PASS="${REST#*|}"

TABLES=$("$DOCKER" exec crm_db bash -lc "mariadb -N -u\"$DB_USER\" -p\"$DB_PASS\" \"$DB_NAME\" -e 'SHOW TABLES;'")

if [ -z "$TABLES" ]; then
  echo "DB check failed: no tables found in '$DB_NAME' (or DB not accessible)." >&2
  exit 1
fi

printf "%s\n" "$TABLES" | head -n 30
