#!/usr/bin/env bash
set -euo pipefail
DOCKER="./scripts/docker.sh"
IN_APP_CONTAINER=0
if [[ -f /.dockerenv ]] && ! command -v docker >/dev/null 2>&1; then
  IN_APP_CONTAINER=1
fi

if [[ "$IN_APP_CONTAINER" == "1" ]]; then
  echo "[up] skipped inside crm_app"
else
  echo "[up] docker compose"
  "$DOCKER" compose up -d --build
fi

echo "[smoke] http code:"
code="$(./smoke.sh)"
echo "$code"
if [[ "$code" != "200" && "$code" != "302" ]]; then
  echo "[fail] smoke"
  ./doctor.sh || true
  exit 1
fi

echo "[db] quick schema check"
if ! ./db_check.sh >/dev/null 2>&1; then
  echo "[warn] db_check failed; dumping diagnostics"
  ./db_errors.sh || true
  exit 1
fi

echo "[app] endpoint checks"
ENDPOINTS=(
  "/"
  "/dashboard.php"
  "/cenniki.php"
  "/spoty.php"
  "/eksport_pdf.php"
)
for endpoint in "${ENDPOINTS[@]}"; do
  endpoint_code="$(./smoke.sh "$endpoint" || true)"
  echo "$endpoint => $endpoint_code"
  case "$endpoint_code" in
    200|302|401|403)
      ;;
    500)
      echo "[fail] endpoint returned 500: $endpoint"
      if [[ "$IN_APP_CONTAINER" == "1" ]]; then
        tail -n 200 /var/log/apache2/error.log || true
      else
        "$DOCKER" logs --tail 200 crm_app || true
      fi
      exit 1
      ;;
    *)
      echo "[fail] endpoint unexpected HTTP code: $endpoint => $endpoint_code"
      if [[ "$IN_APP_CONTAINER" == "1" ]]; then
        tail -n 200 /var/log/apache2/error.log || true
      else
        "$DOCKER" logs --tail 200 crm_app || true
      fi
      exit 1
      ;;
  esac
done

echo "[migrations] endpoint check (best effort)"
if ! ./migrate.sh check; then
  echo "[fail] migration endpoint check failed"
  if [[ "$IN_APP_CONTAINER" == "1" ]]; then
    tail -n 200 /var/log/apache2/error.log || true
  else
    "$DOCKER" logs --tail 200 crm_app || true
  fi
  exit 1
fi

echo "[tests] module checks"
if compgen -G "tests/*.sh" > /dev/null; then
  for test_script in tests/*.sh; do
    test_name="$(basename "$test_script")"
    if [[ "$test_name" == "_lib.sh" ]]; then
      continue
    fi
    if [[ ! -x "$test_script" ]]; then
      echo "[warn] skipping non-executable test: $test_script"
      continue
    fi
    echo "running $test_script"
    if ! "$test_script"; then
      echo "[fail] test failed: $test_script"
      if [[ "$IN_APP_CONTAINER" == "1" ]]; then
        tail -n 200 /var/log/apache2/error.log || true
      else
        "$DOCKER" logs --tail 200 crm_app || true
      fi
      exit 1
    fi
  done
else
  echo "[warn] no tests/*.sh scripts found"
fi

echo "[ok] GREEN"
