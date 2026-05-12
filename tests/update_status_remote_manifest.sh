#!/usr/bin/env bash
set -euo pipefail

if [[ -f /var/www/html/test/update_status_remote_manifest_test.php ]]; then
  php /var/www/html/test/update_status_remote_manifest_test.php
else
  ./scripts/docker.sh exec crm_app php /var/www/html/test/update_status_remote_manifest_test.php
fi
