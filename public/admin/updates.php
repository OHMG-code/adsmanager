<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
requireCapability('manage_updates');

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../services/AppUpdateOrchestrator.php';

ensureSession();

$pageTitle = 'Aktualizacje systemu';
$currentUser = currentUser();
$orchestrator = new AppUpdateOrchestrator(dirname(__DIR__, 2));
$flash = $_SESSION['updates_flash'] ?? null;
unset($_SESSION['updates_flash']);

$wantsJson = ($_GET['format'] ?? '') === 'json';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isCsrfTokenValid($_POST['csrf_token'] ?? null)) {
        if ($wantsJson) {
            updatesJson([
                'ok' => false,
                'message' => 'Nie udało się zweryfikować tokenu CSRF.',
            ], 422);
        }

        $_SESSION['updates_flash'] = [
            'type' => 'danger',
            'message' => 'Nie udało się zweryfikować formularza. Odśwież stronę i spróbuj ponownie.',
        ];
        header('Location: ' . BASE_URL . '/admin/updates.php');
        exit;
    }

    $action = trim((string)($_POST['action'] ?? ''));

    if ($wantsJson && $action === 'tick') {
        $result = $orchestrator->tick($pdo, $currentUser);
        $dashboard = (array)($result['dashboard'] ?? []);
        $runtime = (array)($dashboard['runtime'] ?? []);
        updatesJson([
            'ok' => !empty($result['ok']),
            'message' => (string)($result['message'] ?? ''),
            'state' => (string)($runtime['display_state'] ?? 'idle'),
            'label' => (string)($runtime['display_label'] ?? ''),
            'progress_percent' => (int)($runtime['progress_percent'] ?? 0),
            'pending_migrations' => (int)($runtime['pending_migrations'] ?? 0),
            'completed_migrations' => (int)($runtime['completed_migrations'] ?? 0),
            'total_migrations' => (int)($runtime['total_migrations'] ?? 0),
        ]);
    }

    switch ($action) {
        case 'check_now':
            $dashboard = $orchestrator->refreshRemoteStatus($pdo, $currentUser);
            $_SESSION['updates_flash'] = buildCheckFlashMessage($dashboard);
            break;

        case 'start_update':
            $result = $orchestrator->start($pdo, $currentUser ?? [], !empty($_POST['backup_confirmed']));
            $_SESSION['updates_flash'] = buildFlowFlashMessage($result, 'start');
            break;

        case 'resume_update':
            $result = $orchestrator->resume($pdo, $currentUser ?? [], !empty($_POST['backup_confirmed']));
            $_SESSION['updates_flash'] = buildFlowFlashMessage($result, 'resume');
            break;

        default:
            $_SESSION['updates_flash'] = [
                'type' => 'warning',
                'message' => 'Nieznana akcja aktualizacji.',
            ];
            break;
    }

    header('Location: ' . BASE_URL . '/admin/updates.php');
    exit;
}

$dashboard = $orchestrator->getDashboard($pdo, $currentUser, ['allow_auto_refresh' => false]);
$status = (array)($dashboard['status'] ?? []);
$runtime = (array)($dashboard['runtime'] ?? []);
$logs = (array)($dashboard['logs'] ?? []);
$policy = (array)($dashboard['policy'] ?? []);
$overall = (string)($status['overall_status'] ?? 'up_to_date');
$manifest = (array)($status['manifest'] ?? []);
$release = (array)($status['release'] ?? []);
$appMeta = (array)($status['app_meta'] ?? []);
$migrations = (array)($status['migrations'] ?? []);
$versions = (array)($status['versions'] ?? []);
$flags = (array)($status['status_flags'] ?? []);
$latestRelease = (array)($manifest['latest_release'] ?? []);
$runtimeState = (string)($runtime['display_state'] ?? 'idle');
$canStart = !empty($runtime['can_start']);
$canResume = !empty($runtime['can_resume']);
$isRunning = $runtimeState === 'running';
$autoRefreshHours = (int)floor(UpdatesStatusService::autoRefreshAfterSeconds() / 3600);
$hasRemoteCheckButton = !manifestCheckButtonDisabled($release);
$backupAlreadyConfirmed = !empty($runtime['backup_confirmed']);
$progressPercent = (int)($runtime['progress_percent'] ?? 0);
$localAppVersion = trim((string)($versions['app_version'] ?? (defined('APP_VERSION') ? APP_VERSION : ($release['version'] ?? ''))));
$dbVersion = trim((string)($versions['db_version'] ?? ($appMeta['db_version'] ?? '')));
$targetVersion = trim((string)($versions['target_version'] ?? $localAppVersion));
$updateRequired = !empty($versions['requires_update']) || !empty($flags['update_required']);
$remoteUpdateAvailable = !empty($flags['update_available']);
$anyUpdateAvailable = $updateRequired || $remoteUpdateAvailable;
if ($remoteUpdateAvailable && trim((string)($manifest['latest_version'] ?? '')) !== '') {
    $targetVersion = trim((string)$manifest['latest_version']);
}
$migrationsDirOk = !empty($migrations['migrations_dir_ok']);
$localStatus = trim((string)($status['local_status'] ?? $overall));
if ($localStatus === '') {
    $localStatus = $overall;
}
$manifestStatus = trim((string)($status['manifest_status'] ?? ($manifest['status'] ?? 'unknown')));
if ($manifestStatus === '') {
    $manifestStatus = 'unknown';
}
$manifestConfigured = array_key_exists('manifest_configured', $status)
    ? !empty($status['manifest_configured'])
    : !empty($manifest['configured']);
$mainSystemStatus = $anyUpdateAvailable ? 'update_available' : 'up_to_date';
$mainSystemTone = $mainSystemStatus === 'up_to_date' ? 'success' : 'warning';
$mainSystemLabel = $mainSystemStatus === 'up_to_date' ? 'System jest aktualny' : 'Dostępna aktualizacja systemu';
$mainSystemMessage = $mainSystemStatus === 'up_to_date'
    ? 'APP_VERSION i DB_VERSION są zsynchronizowane, brak oczekujących migracji.'
    : ($remoteUpdateAvailable
        ? 'Wykryto nowsze wydanie w zdalnym manifeście. Możesz uruchomić pełną aktualizację z paczki ZIP.'
        : 'Wykryto lokalne zaległości aktualizacji. Uruchom proces aktualizacji systemu.');

include __DIR__ . '/../includes/header.php';
?>

<main class="container py-4" role="main" aria-labelledby="updates-heading">
    <div class="d-flex flex-wrap justify-content-between align-items-start gap-3 mb-4">
        <div>
            <p class="text-uppercase text-muted fw-semibold small mb-1">Administracja techniczna</p>
            <h1 id="updates-heading" class="h3 mb-2">Aktualizacje systemu</h1>
            <p class="text-muted mb-0">
                Ten ekran obsługuje pełny update HTTP z manifestu: pobranie ZIP, backup bazy i plików,
                podmianę plików aplikacji (z wykluczeniami <code>config/</code>, <code>storage/</code>, <code>uploads/</code>),
                uruchomienie migracji i finalne zsynchronizowanie <code>DB_VERSION</code> z wersją aplikacji.
            </p>
        </div>
        <div id="updates-cta-area" class="d-flex flex-wrap gap-2">
            <form method="post">
                <input type="hidden" name="csrf_token" value="<?= updatesH(getCsrfToken()) ?>">
                <input type="hidden" name="action" value="check_now">
                <button type="submit" class="btn btn-outline-primary" <?= !$hasRemoteCheckButton ? 'disabled' : '' ?>>
                    Sprawdź teraz
                </button>
            </form>

            <?php if ($canStart || $canResume): ?>
                <form method="post" class="d-flex flex-column gap-2">
                    <input type="hidden" name="csrf_token" value="<?= updatesH(getCsrfToken()) ?>">
                    <input type="hidden" name="action" value="<?= $canResume ? 'resume_update' : 'start_update' ?>">

                    <?php if (!$backupAlreadyConfirmed || !$canResume): ?>
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" value="1" id="backup_confirmed" name="backup_confirmed" required>
                            <label class="form-check-label small" for="backup_confirmed">
                                Potwierdzam, że backup został wykonany i zweryfikowany przed uruchomieniem update flow.
                            </label>
                        </div>
                    <?php else: ?>
                        <div class="small text-muted">
                            Backup został już wcześniej potwierdzony przez
                            <strong><?= updatesH(displayValue((string)($runtime['backup_confirmed_by'] ?? ''))) ?></strong>
                            o <?= updatesH(displayDate((string)($runtime['backup_confirmed_at'] ?? ''))) ?>.
                        </div>
                    <?php endif; ?>

                    <button type="submit" class="btn btn-primary align-self-start">
                        <?= $canResume ? 'Wznów aktualizację' : 'Aktualizuj' ?>
                    </button>
                </form>
            <?php elseif ($isRunning): ?>
                <div class="alert alert-info mb-0 py-2 px-3 small">
                    Auto-orkiestracja jest aktywna. Ekran będzie sam odświeżał status kolejnych batchy.
                </div>
            <?php endif; ?>
        </div>
    </div>

    <?php if (is_array($flash) && ($flash['message'] ?? '') !== ''): ?>
        <div class="alert alert-<?= updatesH((string)($flash['type'] ?? 'info')) ?> mb-4">
            <?= updatesH((string)$flash['message']) ?>
        </div>
    <?php endif; ?>

    <?php if (empty($runtime['supports_update_flow'])): ?>
        <div class="alert alert-warning mb-4">
            Środowisko nie ma jeszcze pełnego schematu etapu 4. Najpierw uruchom migrację
            <code>2026_04_07_02_app_update_log.sql</code>, a potem wróć do tego ekranu.
        </div>
    <?php endif; ?>

    <?php if (!$migrationsDirOk): ?>
        <div class="alert alert-danger mb-4">
            Katalog migracji jest niedostępny: <code><?= updatesH((string)($migrations['migrations_dir'] ?? 'sql/migrations')) ?></code>.
            Update flow pozostaje zablokowany do czasu przywrócenia dostępu do plików.
        </div>
    <?php endif; ?>

    <section class="card shadow-sm mb-4 border-<?= updatesH($mainSystemTone) ?>" aria-labelledby="system-status-heading">
        <div class="card-body">
            <div class="d-flex flex-wrap justify-content-between align-items-start gap-3 mb-3">
                <div>
                    <p class="text-uppercase text-muted fw-semibold small mb-2">Status systemu</p>
                    <h2 id="system-status-heading" class="h3 mb-1"><?= updatesH($mainSystemLabel) ?></h2>
                    <p class="mb-0"><?= updatesH($mainSystemMessage) ?></p>
                </div>
                <div class="d-flex flex-column align-items-start align-items-md-end gap-2">
                    <span class="badge text-bg-<?= updatesH($mainSystemTone) ?> fs-6"><?= updatesH($mainSystemStatus) ?></span>
                    <?php if ($mainSystemStatus !== 'up_to_date'): ?>
                        <a class="btn btn-<?= updatesH($mainSystemTone) ?>" href="#updates-cta-area">Uruchom aktualizację</a>
                    <?php endif; ?>
                </div>
            </div>

            <div class="row g-3 mb-3">
                <div class="col-md-4">
                    <div class="p-3 rounded border h-100">
                        <p class="text-muted text-uppercase small fw-semibold mb-1">APP_VERSION</p>
                        <p class="h5 mb-0"><code><?= updatesH(displayValue($localAppVersion)) ?></code></p>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="p-3 rounded border h-100">
                        <p class="text-muted text-uppercase small fw-semibold mb-1">DB_VERSION</p>
                        <p class="h5 mb-0"><code><?= updatesH(displayValue($dbVersion)) ?></code></p>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="p-3 rounded border h-100">
                        <p class="text-muted text-uppercase small fw-semibold mb-1">Pending migrations</p>
                        <p class="h5 mb-0"><?= (int)($migrations['pending_count'] ?? 0) ?></p>
                    </div>
                </div>
            </div>

            <div class="border rounded p-3">
                <p class="text-muted text-uppercase small fw-semibold mb-2">Sekcja techniczna</p>
                <dl class="row small mb-0">
                    <dt class="col-sm-4 text-muted">local_status</dt>
                    <dd class="col-sm-8 mb-2"><code><?= updatesH($localStatus) ?></code></dd>
                    <dt class="col-sm-4 text-muted">manifest_status</dt>
                    <dd class="col-sm-8 mb-2"><code><?= updatesH($manifestStatus) ?></code></dd>
                    <dt class="col-sm-4 text-muted">manifest_configured</dt>
                    <dd class="col-sm-8 mb-0"><code><?= $manifestConfigured ? 'true' : 'false' ?></code></dd>
                </dl>
            </div>
        </div>
    </section>

    <?php if (!$anyUpdateAvailable && !$isRunning && !$canResume): ?>
        <div class="alert alert-success mb-4">
            System jest aktualny. <code>APP_VERSION</code> i <code>DB_VERSION</code> są zsynchronizowane, brak oczekujących migracji.
        </div>
    <?php endif; ?>

    <div class="alert alert-<?= updatesH(runtimeAlertClass($runtimeState, $overall)) ?> mb-4">
        <strong><?= updatesH((string)($runtime['display_label'] ?? 'Status nieznany')) ?></strong><br>
        <span><?= updatesH((string)($runtime['display_message'] ?? '')) ?></span>
    </div>

    <div class="row g-3 mb-4">
        <div class="col-lg-5">
            <section class="card shadow-sm h-100" aria-labelledby="update-flow-heading">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-start gap-3 mb-3">
                        <div>
                            <h2 id="update-flow-heading" class="h5 mb-1">Flow aktualizacji</h2>
                            <p class="text-muted small mb-0">Postęp bieżącego lub ostatniego runu finalizacji.</p>
                        </div>
                        <span class="badge text-bg-<?= updatesH(runtimeBadgeClass($runtimeState)) ?>">
                            <?= updatesH((string)($runtime['display_label'] ?? '')) ?>
                        </span>
                    </div>

                    <div class="mb-3">
                        <div class="progress" role="progressbar" aria-label="Postęp migracji" aria-valuenow="<?= $progressPercent ?>" aria-valuemin="0" aria-valuemax="100">
                            <div class="progress-bar <?= $runtimeState === 'failed' ? 'bg-danger' : ($runtimeState === 'completed' ? 'bg-success' : '') ?>" style="width: <?= $progressPercent ?>%">
                                <?= $progressPercent ?>%
                            </div>
                        </div>
                    </div>

                    <dl class="row small mb-0">
                        <dt class="col-5 text-muted">Run ID</dt>
                        <dd class="col-7 mb-2"><?= updatesH(displayValue((string)($runtime['run_id'] ?? ''))) ?></dd>
                        <dt class="col-5 text-muted">Wersja docelowa</dt>
                        <dd class="col-7 mb-2"><?= updatesH(displayValue((string)($runtime['target_version'] ?? $targetVersion))) ?></dd>
                        <dt class="col-5 text-muted">Maintenance</dt>
                        <dd class="col-7 mb-2"><?= !empty($runtime['maintenance_mode']) ? 'włączony' : 'wyłączony' ?></dd>
                        <dt class="col-5 text-muted">Start</dt>
                        <dd class="col-7 mb-2"><?= updatesH(displayDate((string)($runtime['started_at'] ?? ''))) ?></dd>
                        <dt class="col-5 text-muted">Ostatni heartbeat</dt>
                        <dd class="col-7 mb-2"><?= updatesH(displayDate((string)($runtime['last_heartbeat_at'] ?? ''))) ?></dd>
                        <dt class="col-5 text-muted">Lock wygasa</dt>
                        <dd class="col-7 mb-2"><?= updatesH(displayDate((string)($runtime['lock_expires_at'] ?? ''))) ?></dd>
                        <dt class="col-5 text-muted">Migracje</dt>
                        <dd class="col-7 mb-2">
                            <?= (int)($runtime['completed_migrations'] ?? 0) ?> / <?= (int)($runtime['total_migrations'] ?? 0) ?>
                            ukończone, <?= (int)($runtime['pending_migrations'] ?? 0) ?> pending
                        </dd>
                        <dt class="col-5 text-muted">Backup</dt>
                        <dd class="col-7 mb-2">
                            <?php if ($backupAlreadyConfirmed): ?>
                                tak, <?= updatesH(displayDate((string)($runtime['backup_confirmed_at'] ?? ''))) ?>
                            <?php else: ?>
                                brak potwierdzenia
                            <?php endif; ?>
                        </dd>
                        <dt class="col-5 text-muted">Błąd końcowy</dt>
                        <dd class="col-7 mb-0"><?= updatesH(displayValue((string)($runtime['last_error'] ?? ''))) ?></dd>
                    </dl>
                </div>
            </section>
        </div>

        <div class="col-lg-3 col-md-6">
            <section class="card shadow-sm h-100" aria-labelledby="update-lock-heading">
                <div class="card-body">
                    <h2 id="update-lock-heading" class="h5 mb-2">Lock i heartbeat</h2>
                    <p class="text-muted small mb-3">
                        Lock ma jawne TTL i heartbeat, żeby dało się wykryć porzucony run bez ręcznego grzebania w bazie.
                    </p>
                    <dl class="row small mb-0">
                        <dt class="col-6 text-muted">TTL locka</dt>
                        <dd class="col-6 mb-2"><?= (int)($policy['ttl_seconds'] ?? 0) ?> s</dd>
                        <dt class="col-6 text-muted">Heartbeat</dt>
                        <dd class="col-6 mb-2"><?= (int)($policy['heartbeat_interval_seconds'] ?? 0) ?> s</dd>
                        <dt class="col-6 text-muted">Porzucony po</dt>
                        <dd class="col-6 mb-2"><?= (int)($policy['abandoned_after_seconds'] ?? 0) ?> s</dd>
                        <dt class="col-6 text-muted">Batch</dt>
                        <dd class="col-6 mb-2"><?= (int)($policy['batch_file_limit'] ?? 0) ?> pliki</dd>
                        <dt class="col-6 text-muted">Limit batcha</dt>
                        <dd class="col-6 mb-0"><?= (int)($policy['batch_time_limit_seconds'] ?? 0) ?> s</dd>
                    </dl>
                </div>
            </section>
        </div>

        <div class="col-lg-4 col-md-6">
            <section class="card shadow-sm h-100" aria-labelledby="update-whitelist-heading">
                <div class="card-body">
                    <h2 id="update-whitelist-heading" class="h5 mb-2">Whitelist maintenance</h2>
                    <p class="text-muted small mb-3">
                        Podczas aktualizacji aplikacja przepuszcza tylko niezbędne ścieżki potrzebne do wznowienia i monitorowania flow.
                    </p>
                    <ul class="small mb-0">
                        <li><code>/admin/updates.php</code> jako główny ekran orkiestracji i statusu</li>
                        <li><code>/admin/index.php</code> jako pomocniczy punkt wejścia dla administracji</li>
                        <li><code>/index.php</code> i <code>/login.php</code> dla wejścia admina</li>
                        <li>wymagane assety pod <code>/assets/*</code></li>
                    </ul>
                </div>
            </section>
        </div>
    </div>

    <div class="row g-3 mb-4">
        <div class="col-lg-4 col-md-6">
            <section class="card shadow-sm h-100" aria-labelledby="updates-local-heading">
                <div class="card-body">
                    <p class="text-muted text-uppercase small fw-semibold mb-2">Lokalna wersja kodu</p>
                    <h2 id="updates-local-heading" class="h4 mb-2"><?= updatesH(displayValue($localAppVersion)) ?></h2>
                    <dl class="row small mb-0">
                        <dt class="col-5 text-muted">Źródło</dt>
                        <dd class="col-7 mb-2"><code>APP_VERSION</code></dd>
                        <dt class="col-5 text-muted">Kanał</dt>
                        <dd class="col-7 mb-2"><?= updatesH(displayValue((string)($release['channel'] ?? ''))) ?></dd>
                        <dt class="col-5 text-muted">Baseline</dt>
                        <dd class="col-7 mb-2"><?= updatesH(displayValue((string)($release['baseline_id'] ?? ''))) ?></dd>
                        <dt class="col-5 text-muted">Publikacja</dt>
                        <dd class="col-7 mb-0"><?= updatesH(displayDate((string)($release['published_at'] ?? ''))) ?></dd>
                    </dl>
                </div>
            </section>
        </div>

        <div class="col-lg-4 col-md-6">
            <section class="card shadow-sm h-100" aria-labelledby="updates-recorded-heading">
                <div class="card-body">
                    <p class="text-muted text-uppercase small fw-semibold mb-2">Wersja schematu bazy</p>
                    <h2 id="updates-recorded-heading" class="h4 mb-2"><?= updatesH(displayValue($dbVersion)) ?></h2>
                    <dl class="row small mb-0">
                        <dt class="col-5 text-muted">Instalacja</dt>
                        <dd class="col-7 mb-2"><?= updatesH(displayValue((string)($appMeta['install_state'] ?? ''))) ?></dd>
                        <dt class="col-5 text-muted">Installed</dt>
                        <dd class="col-7 mb-2"><?= updatesH(displayValue((string)($appMeta['installed_version'] ?? ''))) ?></dd>
                        <dt class="col-5 text-muted">Zainstalowano</dt>
                        <dd class="col-7 mb-2"><?= updatesH(displayDate((string)($appMeta['installed_at'] ?? ''))) ?></dd>
                        <dt class="col-5 text-muted">Kanał</dt>
                        <dd class="col-7 mb-0"><?= updatesH(displayValue((string)($appMeta['release_channel'] ?? ''))) ?></dd>
                    </dl>
                </div>
            </section>
        </div>

        <div class="col-lg-4 col-md-12">
            <section class="card shadow-sm h-100" aria-labelledby="updates-pending-mini-heading">
                <div class="card-body">
                    <p class="text-muted text-uppercase small fw-semibold mb-2">Wersja docelowa i status upgrade</p>
                    <h2 id="updates-pending-mini-heading" class="h4 mb-2"><?= updatesH(displayValue($targetVersion)) ?></h2>
                    <p class="small text-muted mb-3">
                        Pending migracje: <strong><?= (int)($migrations['pending_count'] ?? 0) ?></strong>.
                        Status: <strong><?= $anyUpdateAvailable ? 'dostępna aktualizacja' : 'system aktualny' ?></strong>.
                    </p>
                    <a class="btn btn-outline-secondary btn-sm" href="<?= BASE_URL ?>/admin/migrations.php">Otwórz panel migracji</a>
                </div>
            </section>
        </div>
    </div>

    <div class="row g-3 mb-4">
        <div class="col-lg-7">
            <section class="card shadow-sm h-100" aria-labelledby="updates-remote-heading">
                <div class="card-body">
                    <div class="d-flex flex-wrap justify-content-between align-items-start gap-3 mb-3">
                        <div>
                            <h2 id="updates-remote-heading" class="h5 mb-2">Zdalny manifest wydań</h2>
                            <p class="text-muted small mb-0">
                                Domyślnie widzisz ostatni znany stan. Automatyczny refresh manifestu odpala się dopiero przy starym cache
                                albo po użyciu przycisku „Sprawdź teraz” (około <?= $autoRefreshHours ?> h).
                            </p>
                        </div>
                        <span class="badge text-bg-<?= updatesH(statusBadgeClass((string)($manifest['status'] ?? ''))) ?>">
                            <?= updatesH(statusBadgeLabel((string)($manifest['status'] ?? ''))) ?>
                        </span>
                    </div>

                    <dl class="row small mb-0">
                        <dt class="col-lg-4 text-muted">Źródło manifestu</dt>
                        <dd class="col-lg-8 mb-2">
                            <?php if (($manifest['url'] ?? '') !== ''): ?>
                                <code><?= updatesH((string)$manifest['url']) ?></code>
                            <?php else: ?>
                                Brak <code>manifest_url</code> w konfiguracji (<code>UPDATE_MANIFEST_URL</code>/<code>config/db.local.php</code>) oraz <code>release.json</code>.
                            <?php endif; ?>
                        </dd>
                        <dt class="col-lg-4 text-muted">Ostatni check</dt>
                        <dd class="col-lg-8 mb-2"><?= updatesH(displayDate((string)($manifest['checked_at'] ?? ''))) ?></dd>
                        <dt class="col-lg-4 text-muted">Ostatnia znana wersja</dt>
                        <dd class="col-lg-8 mb-2"><?= updatesH(displayValue((string)($manifest['latest_version'] ?? ''))) ?></dd>
                        <dt class="col-lg-4 text-muted">Szczegóły</dt>
                        <dd class="col-lg-8 mb-0">
                            <?= updatesH(statusDetailText($manifest)) ?>
                            <?php if (($latestRelease['download_url'] ?? '') !== ''): ?>
                                <br><a href="<?= updatesH((string)$latestRelease['download_url']) ?>" target="_blank" rel="noopener">Pobierz paczkę ZIP</a>
                            <?php endif; ?>
                            <?php if (($manifest['latest_notes_url'] ?? '') !== ''): ?>
                                <br><a href="<?= updatesH((string)$manifest['latest_notes_url']) ?>" target="_blank" rel="noopener">Pełne release notes</a>
                            <?php endif; ?>
                        </dd>
                    </dl>
                </div>
            </section>
        </div>

        <div class="col-lg-5">
            <section class="card shadow-sm h-100" aria-labelledby="updates-changelog-heading">
                <div class="card-body">
                    <h2 id="updates-changelog-heading" class="h5 mb-2">Changelog najnowszego wydania</h2>
                    <p class="text-muted small mb-3">
                        Pokazywany z ostatniego poprawnie zwalidowanego manifestu zapisanego lokalnie.
                    </p>
                    <?php $changelog = isset($latestRelease['changelog']) && is_array($latestRelease['changelog']) ? $latestRelease['changelog'] : []; ?>
                    <?php if ($changelog !== []): ?>
                        <ul class="small mb-0">
                            <?php foreach ($changelog as $line): ?>
                                <li><?= updatesH((string)$line) ?></li>
                            <?php endforeach; ?>
                        </ul>
                    <?php else: ?>
                        <p class="small text-muted mb-0">Brak lokalnie zapisanego changelogu dla ostatniego poprawnego checka.</p>
                    <?php endif; ?>
                </div>
            </section>
        </div>
    </div>

    <section class="card shadow-sm mb-4" aria-labelledby="updates-plan-heading">
        <div class="card-body">
            <h2 id="updates-plan-heading" class="h5 mb-3">Kroki flow</h2>
            <ol class="mb-0">
                <li><strong>Sprawdź teraz</strong>: odśwież ostatni znany manifest i stan wersji.</li>
                <li><strong>Potwierdź backup</strong>: bez tego nie uruchomisz finalizacji.</li>
                <li><strong>Aktualizuj</strong>: aplikacja bierze lock, zapisuje run i włącza maintenance mode.</li>
                <li><strong>Pobierz paczkę</strong>: ZIP jest pobierany po HTTPS i walidowany przed użyciem.</li>
                <li><strong>Backup DB + pliki</strong>: system zapisuje SQL dump i archiwum plików przed podmianą.</li>
                <li><strong>Rozpakuj i podmień pliki</strong>: z pominięciem <code>config/</code>, <code>storage/</code>, <code>uploads/</code>.</li>
                <li><strong>Auto-orkiestracja migracji</strong>: ekran wykonuje kolejne batch-e migracji bez ręcznego klikania.</li>
                <li><strong>Finalize</strong>: po zejściu pending migrations do zera aplikacja aktualizuje <code>app_meta</code>, loguje sukces i wyłącza maintenance.</li>
                <li><strong>Resume</strong>: jeśli lock wygaśnie albo batch padnie, ekran pokaże stan failed/abandoned i pozwoli wznowić run.</li>
            </ol>
        </div>
    </section>

    <section class="card shadow-sm mb-4" aria-labelledby="updates-pending-heading">
        <div class="card-body">
            <div class="d-flex flex-wrap justify-content-between align-items-start gap-3 mb-3">
                <div>
                    <h2 id="updates-pending-heading" class="h5 mb-2">Pending migrations</h2>
                    <p class="text-muted small mb-0">
                        To jest realny, lokalny stan bazy. W update flow właśnie ten stan decyduje o postępie finalizacji.
                    </p>
                </div>
                <span class="badge text-bg-<?= !empty($migrations['has_pending']) ? 'warning' : 'success' ?>">
                    <?= !empty($migrations['has_pending']) ? 'Oczekują' : 'Brak zaległości' ?>
                </span>
            </div>

            <?php if (!empty($migrations['pending'])): ?>
                <ul class="mb-3">
                    <?php foreach ((array)$migrations['pending'] as $filename): ?>
                        <li><code><?= updatesH((string)$filename) ?></code></li>
                    <?php endforeach; ?>
                </ul>
            <?php else: ?>
                <p class="text-muted small mb-3">Nie wykryto lokalnych migracji oczekujących na uruchomienie.</p>
            <?php endif; ?>

            <p class="small text-muted mb-0">
                Połączenie: host <code><?= updatesH((string)(($migrations['connection']['host'] ?? 'unknown'))) ?></code>,
                baza <code><?= updatesH((string)(($migrations['connection']['database'] ?? 'unknown'))) ?></code>.
            </p>
            <p class="small text-muted mb-0">
                Katalog migracji: <code><?= updatesH((string)($migrations['migrations_dir'] ?? 'sql/migrations')) ?></code>,
                status: <strong><?= !empty($migrations['migrations_dir_ok']) ? 'dostępny' : 'niedostępny' ?></strong>.
            </p>
        </div>
    </section>

    <section class="card shadow-sm" aria-labelledby="updates-log-heading">
        <div class="card-body">
            <div class="d-flex flex-wrap justify-content-between align-items-start gap-3 mb-3">
                <div>
                    <h2 id="updates-log-heading" class="h5 mb-2">app_update_log</h2>
                    <p class="text-muted small mb-0">
                        Log przechowuje krótkie, uporządkowane wpisy z małymi JSON-ami w <code>validation_json</code> i <code>result_json</code>.
                    </p>
                </div>
                <span class="badge text-bg-light border">ostatnie <?= count($logs) ?> wpisów</span>
            </div>

            <?php if ($logs !== []): ?>
                <div class="table-responsive">
                    <table class="table table-sm align-middle mb-0">
                        <thead>
                            <tr>
                                <th>Czas</th>
                                <th>Faza</th>
                                <th>Status</th>
                                <th>Komunikat</th>
                                <th>validation_json</th>
                                <th>result_json</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($logs as $entry): ?>
                                <tr>
                                    <td class="small text-nowrap"><?= updatesH(displayDate((string)($entry['created_at'] ?? ''))) ?></td>
                                    <td class="small">
                                        <strong><?= updatesH((string)($entry['phase'] ?? '')) ?></strong><br>
                                        <span class="text-muted"><?= updatesH((string)($entry['event_key'] ?? '')) ?></span>
                                    </td>
                                    <td><span class="badge text-bg-<?= updatesH(logBadgeClass((string)($entry['status'] ?? ''))) ?>"><?= updatesH((string)($entry['status'] ?? '')) ?></span></td>
                                    <td class="small"><?= updatesH((string)($entry['message'] ?? '')) ?></td>
                                    <td class="small"><code><?= updatesH(renderJsonInline($entry['validation'] ?? null)) ?></code></td>
                                    <td class="small"><code><?= updatesH(renderJsonInline($entry['result'] ?? null)) ?></code></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php else: ?>
                <p class="text-muted small mb-0">Brak wpisów logu dla bieżącego lub ostatniego runu.</p>
            <?php endif; ?>
        </div>
    </section>
</main>

<?php include __DIR__ . '/../includes/footer.php'; ?>

<?php if ($isRunning): ?>
<script>
(function () {
    var csrfToken = <?= json_encode(getCsrfToken(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>;
    var tickUrl = <?= json_encode(BASE_URL . '/admin/updates.php?format=json', JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>;
    var intervalMs = <?= (int)(max(3, (int)($policy['heartbeat_interval_seconds'] ?? 30) / 3) * 1000) ?>;
    var busy = false;

    function scheduleNext() {
        window.setTimeout(runTick, intervalMs);
    }

    function runTick() {
        if (busy) {
            return;
        }
        busy = true;

        var body = new URLSearchParams();
        body.set('csrf_token', csrfToken);
        body.set('action', 'tick');

        fetch(tickUrl, {
            method: 'POST',
            headers: {
                'Accept': 'application/json',
                'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8'
            },
            body: body.toString(),
            credentials: 'same-origin'
        }).then(function (response) {
            return response.json();
        }).then(function () {
            window.location.reload();
        }).catch(function () {
            scheduleNext();
        }).finally(function () {
            busy = false;
        });
    }

    scheduleNext();
})();
</script>
<?php endif; ?>

<?php
/**
 * @param array<string,mixed> $dashboard
 * @return array{type:string,message:string}
 */
function buildCheckFlashMessage(array $dashboard): array
{
    $manifest = (array)($dashboard['status']['manifest'] ?? []);
    $message = statusDetailText($manifest);
    $type = ($manifest['status'] ?? '') === 'success' ? 'success' : 'warning';

    if (($manifest['status'] ?? '') === 'success') {
        $message = 'Sprawdzenie zakończone powodzeniem. ' . $message;
    }

    return [
        'type' => $type,
        'message' => $message,
    ];
}

/**
 * @param array<string,mixed> $result
 * @return array{type:string,message:string}
 */
function buildFlowFlashMessage(array $result, string $mode): array
{
    return [
        'type' => !empty($result['ok']) ? 'success' : 'warning',
        'message' => (!empty($result['ok']) ? ($mode === 'resume' ? 'Wznowienie zapisane. ' : 'Start zapisany. ') : '')
            . (string)($result['message'] ?? 'Brak komunikatu.'),
    ];
}

/**
 * @param array<string,mixed> $payload
 */
function updatesJson(array $payload, int $statusCode = 200): void
{
    http_response_code($statusCode);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

function runtimeAlertClass(string $runtimeState, string $overall): string
{
    return match ($runtimeState) {
        'running' => 'info',
        'failed', 'abandoned' => 'warning',
        'completed' => 'success',
        default => statusAlertClass($overall),
    };
}

function runtimeBadgeClass(string $runtimeState): string
{
    return match ($runtimeState) {
        'running' => 'info',
        'failed' => 'warning',
        'abandoned' => 'secondary',
        'completed' => 'success',
        default => 'light',
    };
}

function logBadgeClass(string $status): string
{
    return match ($status) {
        'success' => 'success',
        'failed' => 'danger',
        'warning' => 'warning',
        default => 'secondary',
    };
}

/**
 * @param array<string,mixed>|null $payload
 */
function renderJsonInline(?array $payload): string
{
    if ($payload === null || $payload === []) {
        return 'brak';
    }
    $encoded = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    return $encoded !== false ? $encoded : 'brak';
}

/**
 * @param array<string,mixed> $release
 */
function manifestCheckButtonDisabled(array $release): bool
{
    if (empty($release['ok'])) {
        return true;
    }
    $url = trim((string)($release['manifest_url'] ?? ''));
    return $url === '' || !ReleaseInfo::isValidHttpsUrl($url);
}

function statusAlertClass(string $overall): string
{
    return match ($overall) {
        'up_to_date' => 'success',
        'update_available', 'pending_migrations_detected', 'update_required' => 'warning',
        'manifest_unreachable', 'manifest_not_configured', 'invalid_manifest_url' => 'secondary',
        'migrations_directory_unavailable', 'local_version_unknown' => 'danger',
        default => 'danger',
    };
}

function statusBadgeClass(string $status): string
{
    return match ($status) {
        'success' => 'success',
        'manifest_not_configured', 'invalid_manifest_url', 'invalid_manifest_download_url', 'not_checked' => 'secondary',
        'network_error', 'http_error', 'curl_unavailable', 'curl_init_failed', 'manifest_too_large' => 'warning',
        'local_release_invalid' => 'danger',
        default => 'warning',
    };
}

function statusBadgeLabel(string $status): string
{
    return match ($status) {
        'success' => 'Ostatni check OK',
        'manifest_not_configured' => 'Brak manifestu',
        'invalid_manifest_url' => 'Niepoprawny URL',
        'invalid_manifest_download_url' => 'Brak download URL',
        'not_checked' => 'Jeszcze nie sprawdzono',
        'network_error', 'http_error', 'curl_unavailable', 'curl_init_failed', 'manifest_too_large' => 'Check nieudany',
        'local_release_invalid' => 'Błąd release.json',
        default => 'Wymaga uwagi',
    };
}

/**
 * @param array<string,mixed> $manifest
 */
function statusDetailText(array $manifest): string
{
    return match ((string)($manifest['status'] ?? '')) {
        'success' => 'Zdalny manifest został poprawnie pobrany i zwalidowany.',
        'manifest_not_configured' => 'Nie ustawiono jeszcze manifest_url (config/env albo release.json).',
        'invalid_manifest_url' => 'manifest_url istnieje, ale nie przechodzi walidacji HTTPS.',
        'invalid_manifest_download_url' => 'Manifest nie zawiera poprawnego download_url dla najnowszego wydania.',
        'not_checked' => 'Nie wykonano jeszcze zdalnego checka dla tego środowiska.',
        'network_error', 'http_error', 'curl_unavailable', 'curl_init_failed', 'manifest_too_large' =>
            'Ostatni check nie powiódł się: ' . displayValue((string)($manifest['error'] ?? 'Brak szczegółów.')),
        'local_release_invalid' => 'release.json jest niekompletny albo ma niepoprawny format.',
        default => ($manifest['error'] ?? '') !== '' ? (string)$manifest['error'] : 'Status manifestu wymaga dodatkowej weryfikacji.',
    };
}

function displayValue(string $value): string
{
    return $value !== '' ? $value : 'brak danych';
}

function displayDate(string $value): string
{
    if ($value === '') {
        return 'brak danych';
    }
    try {
        return (new DateTimeImmutable($value))->format('Y-m-d H:i:s');
    } catch (Throwable $e) {
        return $value;
    }
}

function updatesH(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}
