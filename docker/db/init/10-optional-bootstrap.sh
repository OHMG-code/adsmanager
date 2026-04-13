#!/bin/sh
set -eu

if [ "${DB_OPTIONAL_INIT_BOOTSTRAP:-0}" != "1" ]; then
    echo "[db-init] optional bootstrap disabled"
    exit 0
fi

BOOTSTRAP_SQL="/docker-entrypoint-initdb-extra/sql/production_bootstrap.sql"

if [ ! -f "$BOOTSTRAP_SQL" ]; then
    echo "[db-init] missing bootstrap SQL: $BOOTSTRAP_SQL" >&2
    exit 1
fi

echo "[db-init] running optional bootstrap SQL: $BOOTSTRAP_SQL"
mariadb -uroot -p"${MYSQL_ROOT_PASSWORD}" "${MYSQL_DATABASE}" < "$BOOTSTRAP_SQL"
echo "[db-init] optional bootstrap completed"
