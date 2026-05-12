#!/usr/bin/env bash
set -euo pipefail

DOCKER="./scripts/docker.sh"
BASE_URL="${BASE_URL:-http://localhost:8080}"
IN_APP_CONTAINER=0
if [[ -f /.dockerenv ]] && ! command -v docker >/dev/null 2>&1; then
  IN_APP_CONTAINER=1
  BASE_URL="${BASE_URL/http:\/\/localhost:8080/http:\/\/127.0.0.1}"
  DOCKER="/tmp/crm-docker-shim"
  cat > "$DOCKER" <<'SHIM'
#!/usr/bin/env bash
set -euo pipefail
cmd="${1:-}"
shift || true
case "$cmd" in
  exec)
    while [[ "$#" -gt 0 ]]; do
      case "${1:-}" in
        -i|-t|-it|-ti)
          shift
          ;;
        -e)
          export "${2:-}"
          shift 2
          ;;
        --env)
          export "${2:-}"
          shift 2
          ;;
        --env=*)
          export "${1#--env=}"
          shift
          ;;
        *)
          break
          ;;
      esac
    done
    container="${1:-}"
    shift || true
    if [[ "$container" != "crm_app" ]]; then
      echo "crm-docker-shim: unsupported container $container" >&2
      exit 1
    fi
    exec "$@"
    ;;
  run)
    while [[ "$#" -gt 0 && "${1:-}" == -* ]]; do
      if [[ "${1:-}" == "--network" ]]; then
        shift 2
      else
        shift
      fi
    done
    image="${1:-}"
    shift || true
    if [[ "$image" != "crm-app" ]]; then
      echo "crm-docker-shim: unsupported image $image" >&2
      exit 1
    fi
    if [[ "${1:-}" == "curl" ]]; then
      args=()
      for arg in "$@"; do
        args+=("${arg/http:\/\/localhost:8080/http:\/\/127.0.0.1}")
      done
      exec "${args[@]}"
    fi
    exec "$@"
    ;;
  logs)
    tail -n 200 /var/log/apache2/error.log || true
    ;;
  compose|inspect)
    exit 0
    ;;
  *)
    echo "crm-docker-shim: unsupported docker command $cmd" >&2
    exit 1
    ;;
esac
SHIM
  chmod +x "$DOCKER"
fi

http_code() {
  local target="${1:-/}"
  ./smoke.sh "$target"
}

fetch_body() {
  local target="${1:-/}"
  local url="$target"
  if [[ "$url" != http://* && "$url" != https://* ]]; then
    url="${BASE_URL}${url}"
  fi
  if [[ "$IN_APP_CONTAINER" == "1" ]]; then
    curl -sS "$url"
  else
    "$DOCKER" run --rm --network host crm-app curl -sS "$url"
  fi
}

fail_with_logs() {
  local msg="$1"
  echo "[fail] $msg"
  if [[ "$IN_APP_CONTAINER" == "1" ]]; then
    tail -n 200 /var/log/apache2/error.log || true
  else
    "$DOCKER" logs --tail 200 crm_app || true
  fi
  exit 1
}

assert_non_500_and_allowed() {
  local endpoint="$1"
  local code="$2"

  if [[ -z "$code" ]]; then
    fail_with_logs "$endpoint returned empty HTTP code"
  fi

  if [[ "$code" == "500" ]]; then
    fail_with_logs "$endpoint returned 500"
  fi

  case "$code" in
    200|302|401|403)
      ;;
    *)
      fail_with_logs "$endpoint returned unexpected HTTP code: $code"
      ;;
  esac
}

load_db_cfg() {
  if [[ -n "${TEST_DB_NAME:-}" && -n "${TEST_DB_USER:-}" ]]; then
    return
  fi

  if [[ "$IN_APP_CONTAINER" == "1" ]]; then
    TEST_DB_NAME="__pdo__"
    TEST_DB_USER="__pdo__"
    TEST_DB_PASS=""
    return
  fi

  local cfg
  cfg="$("$DOCKER" exec crm_app bash -lc 'php -r '\''$c=include "/var/www/html/config/db.local.php"; echo ($c["name"] ?? "")."|".($c["user"] ?? "")."|".($c["pass"] ?? "");'\''' 2>/dev/null || true)"
  TEST_DB_NAME="${cfg%%|*}"
  local rest="${cfg#*|}"
  TEST_DB_USER="${rest%%|*}"
  TEST_DB_PASS="${rest#*|}"

  if [[ -z "$TEST_DB_NAME" || -z "$TEST_DB_USER" ]]; then
    fail_with_logs "cannot resolve DB credentials from config/db.local.php"
  fi
}

db_exec() {
  local sql="$1"
  load_db_cfg
  if [[ "$IN_APP_CONTAINER" == "1" ]]; then
    SQL_INPUT="$sql" php -r '
      require "/var/www/html/config/config.php";
      $sql = getenv("SQL_INPUT");
      $stmt = $pdo->query($sql);
      if ($stmt) {
        while ($row = $stmt->fetch(PDO::FETCH_NUM)) {
          echo implode("\t", array_map(static fn($v) => $v === null ? "NULL" : (string)$v, $row)) . "\n";
        }
      }
    '
    return
  fi
  printf '%s\n' "$sql" | "$DOCKER" exec -i crm_db bash -lc "mariadb -N -u\"$TEST_DB_USER\" -p\"$TEST_DB_PASS\" \"$TEST_DB_NAME\""
}

db_query_one() {
  local sql="$1"
  db_exec "$sql" | tr -d '\r' | head -n1
}

app_php() {
  local code="$1"
  if [[ "$IN_APP_CONTAINER" == "1" ]]; then
    php -r "$code"
  else
    "$DOCKER" exec -i crm_app php -r "$code"
  fi
}

create_privileged_session() {
  local preferred_login="${1:-admin}"
  local sid

  sid="$("$DOCKER" exec -e TEST_SESSION_LOGIN="$preferred_login" crm_app php -r '
    require "/var/www/html/config/config.php";

    $preferredLogin = getenv("TEST_SESSION_LOGIN") ?: "admin";
    $stmt = $pdo->prepare("SELECT id, login, rola FROM uzytkownicy WHERE login = ? LIMIT 1");
    $stmt->execute([$preferredLogin]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$user) {
        $fallback = $pdo->query("SELECT id, login, rola FROM uzytkownicy WHERE rola IN (\"Administrator\", \"Manager\") ORDER BY CASE WHEN rola = \"Administrator\" THEN 0 ELSE 1 END, id ASC LIMIT 1");
        $user = $fallback ? $fallback->fetch(PDO::FETCH_ASSOC) : false;
    }

    if (!$user) {
        fwrite(STDERR, "missing privileged user\n");
        exit(1);
    }

    session_id(bin2hex(random_bytes(16)));
    session_start();
    $_SESSION["user_id"] = (int)$user["id"];
    $_SESSION["login"] = (string)$user["login"];
    $_SESSION["user_login"] = (string)$user["login"];

    $role = (string)($user["rola"] ?? "Administrator");
    if ($role === "") {
        $role = "Administrator";
    }
    $_SESSION["rola"] = $role;
    $_SESSION["user_role"] = $role;

    if (strtolower((string)$user["login"]) === "admin" || (int)$user["id"] === 1 || $role === "Administrator") {
        $_SESSION["rola"] = "Administrator";
        $_SESSION["user_role"] = "Administrator";
        $_SESSION["is_superadmin"] = true;
    }

    session_write_close();

    $savePath = session_save_path() ?: sys_get_temp_dir();
    $savePath = rtrim($savePath, DIRECTORY_SEPARATOR);
    $sessionFile = $savePath . DIRECTORY_SEPARATOR . "sess_" . session_id();
    if (is_file($sessionFile)) {
        @chmod($sessionFile, 0666);
    }

    echo session_id();
  ' 2>/dev/null)"

  if [[ -z "$sid" ]]; then
    fail_with_logs "cannot create privileged session"
  fi

  printf '%s\n' "$sid"
}
