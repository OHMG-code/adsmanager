#!/usr/bin/env bash
set -euo pipefail
DOCKER="./scripts/docker.sh"

echo "[1/6] Up + build"
"$DOCKER" compose up -d --build

echo "[2/6] Ensure Apache DocumentRoot -> /public (mounted vhost required)"
# jeśli mount już jest, OK; jeśli nie ma, tylko informacja
if ! grep -q "000-default.conf:/etc/apache2/sites-enabled/000-default.conf" docker-compose.yml 2>/dev/null; then
  echo "WARNING: vhost mount not found in docker-compose.yml (Apache may serve /var/www/html)."
fi

echo "[3/6] Ensure app DB host = db"
if [ -f config/db.local.php ]; then
  sed -i "s/'host'\\s*=>\\s*'[^']*'/'host' => 'db'/" config/db.local.php || true
fi

echo "[4/6] Create DB + user in MariaDB as required by config/db.local.php"
# wyciągamy parametry z db.local.php w kontenerze, żeby nie zgadywać
CFG=$("$DOCKER" exec crm_app bash -lc 'php -r '\''$c=include "/var/www/html/config/db.local.php"; echo $c["name"]."|".$c["user"]."|".$c["pass"];'\''')
DB_NAME="${CFG%%|*}"; REST="${CFG#*|}"
DB_USER="${REST%%|*}"; DB_PASS="${REST#*|}"

"$DOCKER" exec -i crm_db bash -lc "mariadb -uroot -p\"\$MYSQL_ROOT_PASSWORD\" <<SQL
CREATE DATABASE IF NOT EXISTS \\\`$DB_NAME\\\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER IF NOT EXISTS '$DB_USER'@'%' IDENTIFIED BY '$DB_PASS';
ALTER USER '$DB_USER'@'%' IDENTIFIED BY '$DB_PASS';
GRANT ALL PRIVILEGES ON \\\`$DB_NAME\\\`.* TO '$DB_USER'@'%';
FLUSH PRIVILEGES;
SQL"

echo "[5/6] Restart app"
"$DOCKER" compose restart app

echo "[6/6] Smoke test"
code=$(curl -s -o /dev/null -w "%{http_code}" http://localhost:8080 || true)
echo "HTTP: $code"
if [ "$code" != "200" ] && [ "$code" != "302" ]; then
  echo "Smoke test failed. Showing last Apache error log lines:"
  "$DOCKER" exec crm_app bash -lc 'tail -n 80 /var/log/apache2/error.log || true'
  exit 1
fi

echo "OK -> http://localhost:8080"
