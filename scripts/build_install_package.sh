#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
DIST_DIR="${ROOT_DIR}/dist"
STAMP="$(date +%Y%m%d_%H%M%S)"
PACKAGE_NAME="crm_install_${STAMP}"
STAGE_DIR="${DIST_DIR}/${PACKAGE_NAME}"
TAR_PATH="${DIST_DIR}/${PACKAGE_NAME}.tar.gz"
ZIP_PATH="${DIST_DIR}/${PACKAGE_NAME}.zip"

mkdir -p "${DIST_DIR}"
rm -rf "${STAGE_DIR}"

rsync -a \
  --exclude '.git/' \
  --exclude '.github/' \
  --exclude '.idea/' \
  --exclude '.vscode/' \
  --exclude '.codex/' \
  --exclude '.validation/' \
  --exclude '.tmp/' \
  --exclude 'dist/' \
  --exclude 'tmp/' \
  --exclude '.cache/' \
  --exclude '.env' \
  --exclude '.env.local' \
  --exclude 'node_modules/' \
  --exclude 'tests/' \
  --exclude 'test/' \
  --exclude 'test-results/' \
  --exclude 'playwright-report/' \
  --exclude 'docker/' \
  --exclude 'php/' \
  --exclude 'docs/' \
  --exclude 'backups/' \
  --exclude 'deploy/' \
  --exclude '.fr-*' \
  --exclude '.ftp_test.txt' \
  --exclude '.ftp_test_download.txt' \
  --exclude '.tmp_remote_uzytkownicy.php' \
  --exclude 'backup_phase6.sql' \
  --exclude 'dev' \
  --exclude 'doctor.sh' \
  --exclude 'smoke.sh' \
  --exclude 'db_check.sh' \
  --exclude 'db_shell.sh' \
  --exclude 'db_import.sh' \
  --exclude 'db_errors.sh' \
  --exclude 'bootstrap_dev.sh' \
  --exclude 'migrate.sh' \
  --exclude 'app_shell.sh' \
  --exclude 'Dockerfile' \
  --exclude 'docker-compose.yml' \
  --exclude 'docker-compose.yml.bak.*' \
  --exclude 'scripts/' \
  --exclude 'tools/' \
  --exclude 'images/' \
  --exclude 'G-Ad-lista-funkcjonalnosci-2022-05.pdf' \
  --exclude 'ftp.log*' \
  --exclude 'apache_error.log' \
  --exclude 'green.sh' \
  --exclude 'package.json' \
  --exclude 'package-lock.json' \
  --exclude 'composer.json' \
  --exclude 'composer.lock' \
  --exclude '/includes' \
  --exclude 'AGENTS.md' \
  --exclude '# AGENTS.md' \
  --exclude 'DESIGN.md' \
  --exclude 'ROADMAP.md' \
  --exclude 'php_errors.log' \
  --exclude 'public/log.txt' \
  --exclude 'public/debug_mail.php' \
  --exclude 'public/debug_mail_account.php' \
  --exclude 'public/generate_hash.php' \
  --exclude 'public/shema.sql' \
  --exclude 'public/uploads/*' \
  --exclude 'sql/*_crm.sql' \
  --exclude 'sql/production_bootstrap.sql' \
  --exclude 'sql/migrations/PROD_BOOTSTRAP_*.sql' \
  --exclude 'config/db.local.php' \
  --exclude 'storage/*' \
  "${ROOT_DIR}/" "${STAGE_DIR}/"

mkdir -p \
  "${STAGE_DIR}/storage" \
  "${STAGE_DIR}/storage/cache" \
  "${STAGE_DIR}/storage/logs" \
  "${STAGE_DIR}/storage/docs" \
  "${STAGE_DIR}/public/uploads" \
  "${STAGE_DIR}/public/uploads/audio" \
  "${STAGE_DIR}/public/uploads/settings"
touch \
  "${STAGE_DIR}/storage/.gitkeep" \
  "${STAGE_DIR}/storage/cache/.gitkeep" \
  "${STAGE_DIR}/storage/logs/.gitkeep" \
  "${STAGE_DIR}/storage/docs/.gitkeep" \
  "${STAGE_DIR}/public/uploads/.gitkeep" \
  "${STAGE_DIR}/public/uploads/audio/.gitkeep" \
  "${STAGE_DIR}/public/uploads/settings/.gitkeep"

if [[ ! -f "${STAGE_DIR}/storage/.htaccess" ]]; then
  cat >"${STAGE_DIR}/storage/.htaccess" <<'HTACCESS'
<IfModule mod_authz_core.c>
    Require all denied
</IfModule>
<IfModule !mod_authz_core.c>
    Deny from all
</IfModule>
HTACCESS
fi

cat >"${STAGE_DIR}/config/db.local.php" <<'PHP'
<?php
return [
    'host' => '',
    'port' => 3306,
    'name' => '',
    'user' => '',
    'pass' => '',
    'charset' => 'utf8mb4',
    'table_prefix' => '',
    'app_env' => 'production',
    'app_debug' => false,
    'mail_secret' => '',
    'migrator_token' => '',
];
PHP

rm -f "${TAR_PATH}" "${ZIP_PATH}"
tar -czf "${TAR_PATH}" -C "${DIST_DIR}" "${PACKAGE_NAME}"

if command -v zip >/dev/null 2>&1; then
  (
    cd "${DIST_DIR}"
    zip -qr "${PACKAGE_NAME}.zip" "${PACKAGE_NAME}"
  )
  echo "[ok] ZIP: ${ZIP_PATH}"
else
  echo "[info] Narzędzie zip niedostępne, pomijam tworzenie ZIP."
fi

echo "[ok] TAR.GZ: ${TAR_PATH}"
echo "[ok] Katalog staging: ${STAGE_DIR}"
