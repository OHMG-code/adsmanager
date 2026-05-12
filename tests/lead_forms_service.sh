#!/usr/bin/env bash
set -euo pipefail

if [[ -f /var/www/html/test/lead_forms_service_test.php ]]; then
  php /var/www/html/test/lead_forms_service_test.php
else
  ./scripts/docker.sh exec crm_app php /var/www/html/test/lead_forms_service_test.php
fi
