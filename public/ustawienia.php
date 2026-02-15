<?php
require_once __DIR__ . '/includes/auth.php';
requireCapability('manage_system');

require_once __DIR__ . '/includes/config.php';
$pageTitle = "Ustawienia systemowe";
require_once '../config/config.php';
require_once __DIR__ . '/includes/db_schema.php';

const DEFAULT_PRIME_HOURS    = '06:00-09:59,15:00-18:59';
const DEFAULT_STANDARD_HOURS = '10:00-14:59,19:00-22:59';
const DEFAULT_NIGHT_HOURS    = '00:00-05:59,23:00-23:59';

function normalizeRangeInput(?string $value, string $fallback): string {
    $value = trim((string)$value);
    if ($value === '') {
        return $fallback;
    }
    $value = str_replace([';', '|'], ',', $value);
    $parts = array_filter(array_map('trim', explode(',', $value)));
    $normalized = [];
    foreach ($parts as $part) {
        if (!preg_match('/^(\d{1,2}):(\d{2})\s*-\s*(\d{1,2}):(\d{2})$/', $part, $m)) {
            continue;
        }
        $startH = max(0, min(23, (int)$m[1]));
        $startM = max(0, min(59, (int)$m[2]));
        $endH   = max(0, min(23, (int)$m[3]));
        $endM   = max(0, min(59, (int)$m[4]));
        $normalized[] = sprintf('%02d:%02d-%02d:%02d', $startH, $startM, $endH, $endM);
    }
    return $normalized ? implode(',', $normalized) : $fallback;
}

function removeOldLogo(?string $path): void {
    if (!$path) {
        return;
    }
    $full = __DIR__ . '/' . ltrim($path, '/');
    if (is_file($full)) {
        @unlink($full);
    }
}

ensureSystemConfigColumns($pdo);
ensureUserColumns($pdo);
ensureMailHistoryTable($pdo);

include 'includes/header.php';

$alerts = [];
$ustawienia = $pdo->query("SELECT * FROM konfiguracja_systemu WHERE id = 1 LIMIT 1")->fetch() ?: [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $liczba_blokow = (int)($_POST['liczba_blokow'] ?? 2);
    $godzina_start = $_POST['godzina_start'] ?? '07:00:00';
    $godzina_koniec = $_POST['godzina_koniec'] ?? '21:00:00';
    $prog_procentowy = $_POST['prog_procentowy'] ?? '2';
    $prime_hours = normalizeRangeInput($_POST['prime_hours'] ?? '', DEFAULT_PRIME_HOURS);
    $standard_hours = normalizeRangeInput($_POST['standard_hours'] ?? '', DEFAULT_STANDARD_HOURS);
    $night_hours = normalizeRangeInput($_POST['night_hours'] ?? '', DEFAULT_NIGHT_HOURS);
    $limit_prime_minutes = max(0, (int)($_POST['limit_prime_minutes'] ?? 60));
    $limit_standard_minutes = max(0, (int)($_POST['limit_standard_minutes'] ?? 60));
    $limit_night_minutes = max(0, (int)($_POST['limit_night_minutes'] ?? 60));
    $limit_prime_seconds_per_day = $limit_prime_minutes * 60;
    $limit_standard_seconds_per_day = $limit_standard_minutes * 60;
    $limit_night_seconds_per_day = $limit_night_minutes * 60;
    $maintenance_interval_minutes = max(1, (int)($_POST['maintenance_interval_minutes'] ?? 10));
    $audio_upload_max_mb = max(1, (int)($_POST['audio_upload_max_mb'] ?? 50));
    $audio_allowed_ext = trim((string)($_POST['audio_allowed_ext'] ?? 'wav,mp3'));
    if ($audio_allowed_ext === '') {
        $audio_allowed_ext = 'wav,mp3';
    }
    $gus_enabled = !empty($_POST['gus_enabled']) ? 1 : 0;
    $gus_api_key = trim((string)($_POST['gus_api_key'] ?? ''));
    $gus_environment = $_POST['gus_environment'] ?? 'prod';
    if (!in_array($gus_environment, ['prod', 'test'], true)) {
        $gus_environment = 'prod';
    }
    $gus_cache_ttl_days = max(1, (int)($_POST['gus_cache_ttl_days'] ?? 30));
    $gus_auto_refresh_enabled = !empty($_POST['gus_auto_refresh_enabled']) ? 1 : 0;
    $gus_auto_refresh_batch = max(1, (int)($_POST['gus_auto_refresh_batch'] ?? 20));
    $gus_auto_refresh_interval_days = max(1, (int)($_POST['gus_auto_refresh_interval_days'] ?? 30));
    $gus_auto_refresh_backoff_minutes = max(5, (int)($_POST['gus_auto_refresh_backoff_minutes'] ?? 60));
    $logoPath = $ustawienia['pdf_logo_path'] ?? null;
    $smtp_host = trim($_POST['smtp_host'] ?? '');
    $smtp_port = (int)($_POST['smtp_port'] ?? 0);
    $smtp_secure = in_array($_POST['smtp_secure'] ?? '', ['', 'tls', 'ssl'], true) ? $_POST['smtp_secure'] : '';
    $smtp_auth = !empty($_POST['smtp_auth']) ? 1 : 0;
    $smtp_default_from_email = trim($_POST['smtp_default_from_email'] ?? '');
    $smtp_default_from_name = trim($_POST['smtp_default_from_name'] ?? '');
    $smtp_username = trim($_POST['smtp_username'] ?? '');
    $smtp_password_input = $_POST['smtp_password'] ?? '';
    $smtp_password_clear = !empty($_POST['smtp_password_clear']);
    if ($smtp_password_clear) {
        $smtp_password = null;
    } elseif ($smtp_password_input === '') {
        $smtp_password = $ustawienia['smtp_password'] ?? null;
    } else {
        $smtp_password = trim($smtp_password_input);
    }
    $crm_archive_bcc_email = trim((string)($_POST['crm_archive_bcc_email'] ?? ''));
    $crm_archive_enabled = !empty($_POST['crm_archive_enabled']) ? 1 : 0;
    $company_name = trim((string)($_POST['company_name'] ?? ''));
    $company_address = trim((string)($_POST['company_address'] ?? ''));
    $company_nip = trim((string)($_POST['company_nip'] ?? ''));
    $company_email = trim((string)($_POST['company_email'] ?? ''));
    $company_phone = trim((string)($_POST['company_phone'] ?? ''));
    $documents_storage_path = trim((string)($_POST['documents_storage_path'] ?? ''));
    $documents_number_prefix = trim((string)($_POST['documents_number_prefix'] ?? ''));
    if ($documents_number_prefix === '') {
        $documents_number_prefix = 'AM/';
    }

    if (!empty($_POST['remove_logo'])) {
        removeOldLogo($logoPath);
        $logoPath = null;
    }

    if (!empty($_FILES['pdf_logo']['name']) && $_FILES['pdf_logo']['error'] === UPLOAD_ERR_OK) {
        $allowed = ['png', 'jpg', 'jpeg', 'svg'];
        $ext = strtolower(pathinfo($_FILES['pdf_logo']['name'], PATHINFO_EXTENSION));
        if (!in_array($ext, $allowed, true)) {
            $alerts[] = ['type' => 'danger', 'msg' => 'Dozwolone formaty logo to: png, jpg, jpeg, svg.'];
        } else {
            $uploadDir = __DIR__ . '/uploads/settings';
            if (!is_dir($uploadDir)) {
                @mkdir($uploadDir, 0775, true);
            }
            $filename = 'mediaplan_logo_' . time() . '.' . $ext;
            $dest = $uploadDir . '/' . $filename;
            if (move_uploaded_file($_FILES['pdf_logo']['tmp_name'], $dest)) {
                removeOldLogo($logoPath);
                $logoPath = 'uploads/settings/' . $filename;
                $alerts[] = ['type' => 'success', 'msg' => 'Logo zostało zapisane.'];
            } else {
                $alerts[] = ['type' => 'danger', 'msg' => 'Nie udało się zapisać przesłanego logo.'];
            }
        }
    } elseif (!empty($_FILES['pdf_logo']['name']) && $_FILES['pdf_logo']['error'] !== UPLOAD_ERR_NO_FILE) {
        $alerts[] = ['type' => 'danger', 'msg' => 'Nie udało się przesłać logo. Spróbuj ponownie.'];
    }

    $stmt = $pdo->prepare("UPDATE konfiguracja_systemu 
        SET liczba_blokow = ?, godzina_start = ?, godzina_koniec = ?, prog_procentowy = ?,
            prime_hours = ?, standard_hours = ?, night_hours = ?,
            limit_prime_seconds_per_day = ?, limit_standard_seconds_per_day = ?, limit_night_seconds_per_day = ?,
            maintenance_interval_minutes = ?, audio_upload_max_mb = ?, audio_allowed_ext = ?,
            gus_enabled = ?, gus_api_key = ?, gus_environment = ?, gus_cache_ttl_days = ?,
            gus_auto_refresh_enabled = ?, gus_auto_refresh_batch = ?, gus_auto_refresh_interval_days = ?, gus_auto_refresh_backoff_minutes = ?, pdf_logo_path = ?,
            smtp_host = ?, smtp_port = ?, smtp_secure = ?, smtp_auth = ?, smtp_default_from_email = ?, smtp_default_from_name = ?, smtp_username = ?, smtp_password = ?,
            crm_archive_bcc_email = ?, crm_archive_enabled = ?,
            company_name = ?, company_address = ?, company_nip = ?, company_email = ?, company_phone = ?,
            documents_storage_path = ?, documents_number_prefix = ?
        WHERE id = 1");

    if ($stmt->execute([
        $liczba_blokow, $godzina_start, $godzina_koniec, $prog_procentowy,
        $prime_hours, $standard_hours, $night_hours,
        $limit_prime_seconds_per_day, $limit_standard_seconds_per_day, $limit_night_seconds_per_day,
        $maintenance_interval_minutes, $audio_upload_max_mb, $audio_allowed_ext,
        $gus_enabled, ($gus_api_key !== '' ? $gus_api_key : null), $gus_environment, $gus_cache_ttl_days,
        $gus_auto_refresh_enabled, $gus_auto_refresh_batch, $gus_auto_refresh_interval_days, $gus_auto_refresh_backoff_minutes, $logoPath,
        $smtp_host !== '' ? $smtp_host : null,
        $smtp_port > 0 ? $smtp_port : null,
        $smtp_secure !== '' ? $smtp_secure : null,
        $smtp_auth,
        $smtp_default_from_email !== '' ? $smtp_default_from_email : null,
        $smtp_default_from_name !== '' ? $smtp_default_from_name : null,
        $smtp_username !== '' ? $smtp_username : null,
        $smtp_password !== '' ? $smtp_password : null,
        $crm_archive_bcc_email !== '' ? $crm_archive_bcc_email : null,
        $crm_archive_enabled,
        $company_name !== '' ? $company_name : null,
        $company_address !== '' ? $company_address : null,
        $company_nip !== '' ? $company_nip : null,
        $company_email !== '' ? $company_email : null,
        $company_phone !== '' ? $company_phone : null,
        $documents_storage_path !== '' ? $documents_storage_path : null,
        $documents_number_prefix,
    ])) {
        $alerts[] = ['type' => 'success', 'msg' => 'Ustawienia zostały zapisane.'];
        $ustawienia = array_merge($ustawienia, [
            'liczba_blokow'  => $liczba_blokow,
            'godzina_start'  => $godzina_start,
            'godzina_koniec' => $godzina_koniec,
            'prog_procentowy'=> $prog_procentowy,
            'prime_hours'    => $prime_hours,
            'standard_hours' => $standard_hours,
            'night_hours'    => $night_hours,
            'limit_prime_seconds_per_day' => $limit_prime_seconds_per_day,
            'limit_standard_seconds_per_day' => $limit_standard_seconds_per_day,
            'limit_night_seconds_per_day' => $limit_night_seconds_per_day,
            'maintenance_interval_minutes' => $maintenance_interval_minutes,
            'audio_upload_max_mb' => $audio_upload_max_mb,
            'audio_allowed_ext' => $audio_allowed_ext,
            'gus_enabled' => $gus_enabled,
            'gus_api_key' => $gus_api_key,
            'gus_environment' => $gus_environment,
            'gus_cache_ttl_days' => $gus_cache_ttl_days,
            'gus_auto_refresh_enabled' => $gus_auto_refresh_enabled,
            'gus_auto_refresh_batch' => $gus_auto_refresh_batch,
            'gus_auto_refresh_interval_days' => $gus_auto_refresh_interval_days,
            'gus_auto_refresh_backoff_minutes' => $gus_auto_refresh_backoff_minutes,
            'pdf_logo_path'  => $logoPath,
            'smtp_host'                => $smtp_host,
            'smtp_port'                => $smtp_port,
            'smtp_secure'              => $smtp_secure,
            'smtp_auth'                => $smtp_auth,
            'smtp_default_from_email'  => $smtp_default_from_email,
            'smtp_default_from_name'   => $smtp_default_from_name,
            'smtp_username'            => $smtp_username,
            'smtp_password'            => $smtp_password,
            'crm_archive_bcc_email'    => $crm_archive_bcc_email,
            'crm_archive_enabled'      => $crm_archive_enabled,
            'company_name'             => $company_name,
            'company_address'          => $company_address,
            'company_nip'              => $company_nip,
            'company_email'            => $company_email,
            'company_phone'            => $company_phone,
            'documents_storage_path'   => $documents_storage_path,
            'documents_number_prefix'  => $documents_number_prefix,
        ]);
    } else {
        $alerts[] = ['type' => 'danger', 'msg' => 'Wystąpił błąd podczas zapisu ustawień.'];
    }
}

if (!$ustawienia) {
    $ustawienia = [
        'liczba_blokow'  => 2,
        'godzina_start'  => '07:00',
        'godzina_koniec' => '21:00',
        'prog_procentowy'=> '2',
        'prime_hours'    => DEFAULT_PRIME_HOURS,
        'standard_hours' => DEFAULT_STANDARD_HOURS,
        'night_hours'    => DEFAULT_NIGHT_HOURS,
        'limit_prime_seconds_per_day' => 3600,
        'limit_standard_seconds_per_day' => 3600,
        'limit_night_seconds_per_day' => 3600,
        'maintenance_interval_minutes' => 10,
        'audio_upload_max_mb' => 50,
        'audio_allowed_ext' => 'wav,mp3',
        'gus_enabled' => 0,
        'gus_api_key' => null,
        'gus_environment' => 'prod',
        'gus_cache_ttl_days' => 30,
        'gus_auto_refresh_enabled' => 0,
        'gus_auto_refresh_batch' => 20,
        'gus_auto_refresh_interval_days' => 30,
        'gus_auto_refresh_backoff_minutes' => 60,
        'pdf_logo_path'  => null,
        'smtp_host'                => null,
        'smtp_port'                => null,
        'smtp_secure'              => null,
        'smtp_auth'                => 0,
        'smtp_default_from_email'  => null,
        'smtp_default_from_name'   => null,
        'smtp_username'            => null,
        'smtp_password'            => null,
        'crm_archive_bcc_email'    => null,
        'crm_archive_enabled'      => 0,
        'company_name'             => null,
        'company_address'          => null,
        'company_nip'              => null,
        'company_email'            => null,
        'company_phone'            => null,
        'documents_storage_path'   => 'storage/docs/',
        'documents_number_prefix'  => 'AM/',
    ];
} else {
    $ustawienia['prime_hours']    = $ustawienia['prime_hours']    ?: DEFAULT_PRIME_HOURS;
    $ustawienia['standard_hours'] = $ustawienia['standard_hours'] ?: DEFAULT_STANDARD_HOURS;
    $ustawienia['night_hours']    = $ustawienia['night_hours']    ?: DEFAULT_NIGHT_HOURS;
    $ustawienia['smtp_username']  = $ustawienia['smtp_username']  ?? null;
    $ustawienia['smtp_password']  = $ustawienia['smtp_password']  ?? null;
    $ustawienia['crm_archive_bcc_email'] = $ustawienia['crm_archive_bcc_email'] ?? null;
    $ustawienia['crm_archive_enabled'] = (int)($ustawienia['crm_archive_enabled'] ?? 0);
    $ustawienia['documents_number_prefix'] = $ustawienia['documents_number_prefix'] ?: 'AM/';
    $ustawienia['documents_storage_path'] = $ustawienia['documents_storage_path'] ?: 'storage/docs/';
}

$limitPrimeMinutes = (int)round((int)($ustawienia['limit_prime_seconds_per_day'] ?? 3600) / 60);
$limitStandardMinutes = (int)round((int)($ustawienia['limit_standard_seconds_per_day'] ?? 3600) / 60);
$limitNightMinutes = (int)round((int)($ustawienia['limit_night_seconds_per_day'] ?? 3600) / 60);
$maintenanceIntervalMinutes = (int)($ustawienia['maintenance_interval_minutes'] ?? 10);
$audioUploadMaxMb = (int)($ustawienia['audio_upload_max_mb'] ?? 50);
$audioAllowedExt = (string)($ustawienia['audio_allowed_ext'] ?? 'wav,mp3');
$gusEnabled = !empty($ustawienia['gus_enabled']);
$gusApiKey = (string)($ustawienia['gus_api_key'] ?? '');
$gusEnvironment = (string)($ustawienia['gus_environment'] ?? 'prod');
$gusCacheTtlDays = (int)($ustawienia['gus_cache_ttl_days'] ?? 30);
$gusAutoRefreshEnabled = !empty($ustawienia['gus_auto_refresh_enabled']);
$gusAutoRefreshBatch = (int)($ustawienia['gus_auto_refresh_batch'] ?? 20);
$gusAutoRefreshIntervalDays = (int)($ustawienia['gus_auto_refresh_interval_days'] ?? 30);
$gusAutoRefreshBackoffMinutes = (int)($ustawienia['gus_auto_refresh_backoff_minutes'] ?? 60);
?>

<div class="container">
    <h1 class="mb-4">Ustawienia systemu</h1>

    <?php foreach ($alerts as $alert): ?>
        <div class="alert alert-<?php echo htmlspecialchars($alert['type']); ?>">
            <?php echo htmlspecialchars($alert['msg']); ?>
        </div>
    <?php endforeach; ?>

    <form method="post" enctype="multipart/form-data">
        <div class="row mb-3">
            <div class="col-md-4">
                <label for="liczba_blokow" class="form-label">Liczba bloków reklamowych na godzinę:</label>
                <input type="number" name="liczba_blokow" id="liczba_blokow" class="form-control" min="1" max="10"
                       value="<?php echo htmlspecialchars($ustawienia['liczba_blokow']); ?>" required>
            </div>
            <div class="col-md-4">
                <label for="godzina_start" class="form-label">Godzina rozpoczęcia emisji:</label>
                <input type="time" name="godzina_start" id="godzina_start" class="form-control"
                       value="<?php echo htmlspecialchars($ustawienia['godzina_start']); ?>" required>
            </div>
            <div class="col-md-4">
                <label for="godzina_koniec" class="form-label">Godzina zakończenia emisji:</label>
                <input type="time" name="godzina_koniec" id="godzina_koniec" class="form-control"
                       value="<?php echo htmlspecialchars($ustawienia['godzina_koniec']); ?>" required>
            </div>
        </div>

        <div class="row mb-4">
            <div class="col-md-4">
                <label for="prog_procentowy" class="form-label">Limit zajętości pasma reklamowego (%):</label>
                <select name="prog_procentowy" id="prog_procentowy" class="form-select" required>
                    <?php
                    $progi = [
                        '0' => '0% (0 sek.)',
                        '2' => '2% (1:30 min)',
                        '7' => '7% (4:12 min)',
                        '12' => '12% (7:12 min)',
                        '20' => '20% (12:00 min)'
                    ];
                    foreach ($progi as $wartosc => $opis) {
                        $selected = ($ustawienia['prog_procentowy'] === $wartosc) ? 'selected' : '';
                        echo "<option value=\"$wartosc\" $selected>$opis</option>";
                    }
                    ?>
                </select>
            </div>
        </div>

        <div class="card mb-4">
            <div class="card-body">
                <h5 class="card-title">Pasma czasowe kampanii</h5>
                <p class="text-muted small mb-3">Podaj przedziały godzinowe dla Prime Time, Standard Time i Night Time w formacie <code>HH:MM-HH:MM</code>, oddzielone przecinkami (np. <em>06:00-09:59,15:00-18:59</em>). Te ustawienia zostaną wykorzystane przy wyliczeniach i w mediaplanie PDF.</p>
                <div class="row g-3">
                    <div class="col-md-4">
                        <label for="prime_hours" class="form-label">Prime Time</label>
                        <input type="text" class="form-control" name="prime_hours" id="prime_hours"
                               value="<?php echo htmlspecialchars($ustawienia['prime_hours']); ?>">
                    </div>
                    <div class="col-md-4">
                        <label for="standard_hours" class="form-label">Standard Time</label>
                        <input type="text" class="form-control" name="standard_hours" id="standard_hours"
                               value="<?php echo htmlspecialchars($ustawienia['standard_hours']); ?>">
                    </div>
                    <div class="col-md-4">
                        <label for="night_hours" class="form-label">Night Time</label>
                        <input type="text" class="form-control" name="night_hours" id="night_hours"
                               value="<?php echo htmlspecialchars($ustawienia['night_hours']); ?>">
                    </div>
                </div>
            </div>
        </div>

        <div class="card mb-4">
            <div class="card-body">
                <h5 class="card-title">Limity pasm (minuty/dzień)</h5>
                <div class="row g-3">
                    <div class="col-md-4">
                        <label for="limit_prime_minutes" class="form-label">Prime</label>
                        <input type="number" name="limit_prime_minutes" id="limit_prime_minutes" class="form-control" min="0"
                               value="<?php echo htmlspecialchars((string)$limitPrimeMinutes); ?>">
                    </div>
                    <div class="col-md-4">
                        <label for="limit_standard_minutes" class="form-label">Standard</label>
                        <input type="number" name="limit_standard_minutes" id="limit_standard_minutes" class="form-control" min="0"
                               value="<?php echo htmlspecialchars((string)$limitStandardMinutes); ?>">
                    </div>
                    <div class="col-md-4">
                        <label for="limit_night_minutes" class="form-label">Night</label>
                        <input type="number" name="limit_night_minutes" id="limit_night_minutes" class="form-control" min="0"
                               value="<?php echo htmlspecialchars((string)$limitNightMinutes); ?>">
                    </div>
                </div>
                <p class="text-muted small mt-2 mb-0">Limity są zapisywane jako sekundy w konfiguracji systemu.</p>
            </div>
        </div>

        <div class="card mb-4">
            <div class="card-body">
                <h5 class="card-title">Interwał maintenance (minuty)</h5>
                <div class="row g-3">
                    <div class="col-md-4">
                        <label for="maintenance_interval_minutes" class="form-label">Interwał</label>
                        <input type="number" name="maintenance_interval_minutes" id="maintenance_interval_minutes" class="form-control" min="1"
                               value="<?php echo htmlspecialchars((string)$maintenanceIntervalMinutes); ?>">
                    </div>
                </div>
            </div>
        </div>

        <div class="card mb-4">
            <div class="card-body">
                <h5 class="card-title">Pliki audio spotów</h5>
                <div class="row g-3">
                    <div class="col-md-4">
                        <label for="audio_upload_max_mb" class="form-label">Limit rozmiaru uploadu (MB)</label>
                        <input type="number" name="audio_upload_max_mb" id="audio_upload_max_mb" class="form-control" min="1"
                               value="<?php echo htmlspecialchars((string)$audioUploadMaxMb); ?>">
                    </div>
                    <div class="col-md-8">
                        <label for="audio_allowed_ext" class="form-label">Dozwolone rozszerzenia (CSV)</label>
                        <input type="text" name="audio_allowed_ext" id="audio_allowed_ext" class="form-control"
                               value="<?php echo htmlspecialchars($audioAllowedExt); ?>">
                        <div class="form-text">Np. wav,mp3</div>
                    </div>
                </div>
            </div>
        </div>

        <div class="card mb-4">
            <div class="card-body">
                <h5 class="card-title">Integracja GUS (BIR)</h5>
                <div class="row g-3">
                    <div class="col-md-4 d-flex align-items-center">
                        <div class="form-check mt-2">
                            <input class="form-check-input" type="checkbox" id="gus_enabled" name="gus_enabled" value="1"
                                   <?= $gusEnabled ? 'checked' : '' ?>>
                            <label class="form-check-label" for="gus_enabled">Włącz integrację</label>
                        </div>
                    </div>
                    <div class="col-md-8">
                        <label for="gus_api_key" class="form-label">Klucz API (BIR)</label>
                        <input type="text" name="gus_api_key" id="gus_api_key" class="form-control"
                               value="<?php echo htmlspecialchars($gusApiKey); ?>">
                    </div>
                    <div class="col-md-4">
                        <label for="gus_environment" class="form-label">Środowisko</label>
                        <select name="gus_environment" id="gus_environment" class="form-select">
                            <option value="prod" <?= $gusEnvironment === 'prod' ? 'selected' : '' ?>>Produkcja</option>
                            <option value="test" <?= $gusEnvironment === 'test' ? 'selected' : '' ?>>Test</option>
                        </select>
                    </div>
                    <div class="col-md-4">
                        <label for="gus_cache_ttl_days" class="form-label">TTL cache (dni)</label>
                        <input type="number" name="gus_cache_ttl_days" id="gus_cache_ttl_days" class="form-control" min="1"
                               value="<?php echo htmlspecialchars((string)$gusCacheTtlDays); ?>">
                    </div>
                    <div class="col-12">
                        <div class="form-check mt-2">
                            <input class="form-check-input" type="checkbox" id="gus_auto_refresh_enabled" name="gus_auto_refresh_enabled" value="1"
                                   <?= $gusAutoRefreshEnabled ? 'checked' : '' ?>>
                            <label class="form-check-label" for="gus_auto_refresh_enabled">Automatyczne odświeżanie GUS</label>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <label for="gus_auto_refresh_batch" class="form-label">Batch (firm na uruchomienie)</label>
                        <input type="number" name="gus_auto_refresh_batch" id="gus_auto_refresh_batch" class="form-control" min="1"
                               value="<?php echo htmlspecialchars((string)$gusAutoRefreshBatch); ?>">
                    </div>
                    <div class="col-md-4">
                        <label for="gus_auto_refresh_interval_days" class="form-label">Interwał (dni)</label>
                        <input type="number" name="gus_auto_refresh_interval_days" id="gus_auto_refresh_interval_days" class="form-control" min="1"
                               value="<?php echo htmlspecialchars((string)$gusAutoRefreshIntervalDays); ?>">
                    </div>
                    <div class="col-md-4">
                        <label for="gus_auto_refresh_backoff_minutes" class="form-label">Backoff po błędzie (min)</label>
                        <input type="number" name="gus_auto_refresh_backoff_minutes" id="gus_auto_refresh_backoff_minutes" class="form-control" min="5"
                               value="<?php echo htmlspecialchars((string)$gusAutoRefreshBackoffMinutes); ?>">
                    </div>
                </div>
                <p class="text-muted small mt-2 mb-0">Integracja korzysta z BIR GUS. W trybie testowym użyj klucza testowego.</p>
            </div>
        </div>

        <div class="card mb-4">
            <div class="card-body">
                <h5 class="card-title">Dane firmy / Dokumenty</h5>
                <div class="row g-3">
                    <div class="col-md-6">
                        <label for="company_name" class="form-label">Nazwa firmy</label>
                        <input type="text" name="company_name" id="company_name" class="form-control"
                               value="<?= htmlspecialchars($ustawienia['company_name'] ?? '') ?>">
                    </div>
                    <div class="col-md-6">
                        <label for="company_nip" class="form-label">NIP firmy</label>
                        <input type="text" name="company_nip" id="company_nip" class="form-control"
                               value="<?= htmlspecialchars($ustawienia['company_nip'] ?? '') ?>">
                    </div>
                    <div class="col-md-12">
                        <label for="company_address" class="form-label">Adres firmy</label>
                        <input type="text" name="company_address" id="company_address" class="form-control"
                               value="<?= htmlspecialchars($ustawienia['company_address'] ?? '') ?>">
                    </div>
                    <div class="col-md-6">
                        <label for="company_email" class="form-label">Email firmy</label>
                        <input type="email" name="company_email" id="company_email" class="form-control"
                               value="<?= htmlspecialchars($ustawienia['company_email'] ?? '') ?>">
                    </div>
                    <div class="col-md-6">
                        <label for="company_phone" class="form-label">Telefon firmy</label>
                        <input type="text" name="company_phone" id="company_phone" class="form-control"
                               value="<?= htmlspecialchars($ustawienia['company_phone'] ?? '') ?>">
                    </div>
                    <div class="col-md-8">
                        <label for="documents_storage_path" class="form-label">Ścieżka do archiwum dokumentów</label>
                        <input type="text" name="documents_storage_path" id="documents_storage_path" class="form-control"
                               value="<?= htmlspecialchars($ustawienia['documents_storage_path'] ?? 'storage/docs/') ?>">
                        <div class="form-text">Może być ścieżką względną lub absolutną (np. /crm/storage/docs/), poza katalogiem public.</div>
                    </div>
                    <div class="col-md-4">
                        <label for="documents_number_prefix" class="form-label">Prefix numeracji</label>
                        <input type="text" name="documents_number_prefix" id="documents_number_prefix" class="form-control"
                               value="<?= htmlspecialchars($ustawienia['documents_number_prefix'] ?? 'AM/') ?>">
                    </div>
                </div>
            </div>
        </div>

        <div class="card mb-4">
            <div class="card-body">
                <h5 class="card-title">Logo mediaplanu</h5>
                <p class="text-muted small mb-3">Logo zostanie umieszczone w wygenerowanym dokumencie PDF. Obsługiwane formaty: PNG, JPG, SVG.</p>
                <div class="mb-3">
                    <label for="pdf_logo" class="form-label">Prześlij nowe logo</label>
                    <input type="file" class="form-control" name="pdf_logo" id="pdf_logo" accept=".png,.jpg,.jpeg,.svg">
                </div>
                <?php if (!empty($ustawienia['pdf_logo_path'])): ?>
                    <div class="mb-3">
                        <p class="mb-1">Aktualne logo:</p>
                        <img src="<?php echo htmlspecialchars($ustawienia['pdf_logo_path']); ?>" alt="Logo mediaplanu" style="max-height:80px;">
                    </div>
                    <div class="form-check">
                        <input class="form-check-input" type="checkbox" value="1" id="remove_logo" name="remove_logo">
                        <label class="form-check-label" for="remove_logo">Usuń bieżące logo</label>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <div class="card mb-4">
            <div class="card-body">
                <h5 class="card-title">Wysyłka ofert (SMTP)</h5>
                <p class="text-muted small mb-3">Ustaw parametry serwera SMTP wykorzystywane do wysyłki mediaplanów. Handlowcy mogą nadpisać dane logowania w swoim profilu.</p>
                <div class="row g-3">
                    <div class="col-md-4">
                        <label class="form-label">Host SMTP</label>
                        <input type="text" name="smtp_host" class="form-control"
                               value="<?= htmlspecialchars($ustawienia['smtp_host'] ?? '') ?>">
                    </div>
                    <div class="col-md-2">
                        <label class="form-label">Port</label>
                        <input type="number" name="smtp_port" class="form-control"
                               value="<?= htmlspecialchars((string)($ustawienia['smtp_port'] ?? '')) ?>">
                    </div>
                    <div class="col-md-2">
                        <label class="form-label">Szyfrowanie</label>
                        <select name="smtp_secure" class="form-select">
                            <?php $secure = $ustawienia['smtp_secure'] ?? ''; ?>
                            <option value="" <?= $secure === '' ? 'selected' : '' ?>>Brak</option>
                            <option value="tls" <?= $secure === 'tls' ? 'selected' : '' ?>>TLS</option>
                            <option value="ssl" <?= $secure === 'ssl' ? 'selected' : '' ?>>SSL</option>
                        </select>
                    </div>
                    <div class="col-md-2 d-flex align-items-center">
                        <div class="form-check mt-4">
                            <input class="form-check-input" type="checkbox" id="smtp_auth" name="smtp_auth" value="1"
                                   <?= !empty($ustawienia['smtp_auth']) ? 'checked' : '' ?>>
                            <label class="form-check-label" for="smtp_auth">Wymaga autoryzacji</label>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Domyślny adres nadawcy</label>
                        <input type="email" name="smtp_default_from_email" class="form-control"
                               value="<?= htmlspecialchars($ustawienia['smtp_default_from_email'] ?? '') ?>">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Domyślna nazwa nadawcy</label>
                        <input type="text" name="smtp_default_from_name" class="form-control"
                               value="<?= htmlspecialchars($ustawienia['smtp_default_from_name'] ?? '') ?>">
                    </div>
                </div>
                <div class="row g-3 mt-2">
                    <div class="col-md-6">
                        <label class="form-label">Systemowy login SMTP</label>
                        <input type="text" name="smtp_username" class="form-control"
                               value="<?= htmlspecialchars($ustawienia['smtp_username'] ?? '') ?>">
                        <div class="form-text">Używany, gdy handlowiec korzysta z ustawień globalnych.</div>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Systemowe hasło SMTP</label>
                        <input type="password" name="smtp_password" class="form-control"
                               placeholder="<?= !empty($ustawienia['smtp_password']) ? 'Pozostaw puste, aby nie zmieniać' : '' ?>">
                        <?php if (!empty($ustawienia['smtp_password'])): ?>
                            <div class="form-check mt-2">
                                <input class="form-check-input" type="checkbox" id="smtp_password_clear" name="smtp_password_clear" value="1">
                                <label class="form-check-label" for="smtp_password_clear">Usuń zapisane hasło</label>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>

        <div class="card mb-4">
            <div class="card-body">
                <h5 class="card-title">Archiwum CRM (BCC)</h5>
                <p class="text-muted small mb-3">Automatyczne BCC do skrzynki archiwum dla korespondencji wychodzacej.</p>
                <div class="row g-3">
                    <div class="col-md-6">
                        <label class="form-label">Adres skrzynki archiwum</label>
                        <input type="email" name="crm_archive_bcc_email" class="form-control"
                               value="<?= htmlspecialchars($ustawienia['crm_archive_bcc_email'] ?? '') ?>">
                    </div>
                    <div class="col-md-3 d-flex align-items-center">
                        <div class="form-check mt-4">
                            <input class="form-check-input" type="checkbox" id="crm_archive_enabled" name="crm_archive_enabled" value="1"
                                   <?= !empty($ustawienia['crm_archive_enabled']) ? 'checked' : '' ?>>
                            <label class="form-check-label" for="crm_archive_enabled">Wlacz archiwum</label>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <button type="submit" class="btn btn-primary">💾 Zapisz ustawienia</button>
    </form>
</div>

<?php include 'includes/footer.php'; ?>
