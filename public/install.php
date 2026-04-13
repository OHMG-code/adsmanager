<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/config.php';

$rootDir = realpath(__DIR__ . '/..') ?: dirname(__DIR__);

require_once $rootDir . '/services/InstallGuard.php';
require_once $rootDir . '/services/InstallBaseline.php';
require_once $rootDir . '/services/InstallWizardState.php';
require_once $rootDir . '/services/InstallerService.php';

InstallWizardState::boot();
ensureInstallerCsrfToken();

$service = new InstallerService($rootDir);
$configFile = $rootDir . '/config/db.local.php';
$lockFile = $rootDir . '/storage/installed.lock';
$legacyLockFile = $rootDir . '/storage/installer.lock';
$existingConfig = readPhpArrayFile($configFile);
$guardInfo = InstallGuard::inspect([
    'host' => trim((string)($existingConfig['host'] ?? '')),
    'port' => (int)($existingConfig['port'] ?? 3306),
    'name' => trim((string)($existingConfig['name'] ?? '')),
    'user' => trim((string)($existingConfig['user'] ?? '')),
    'pass' => (string)($existingConfig['pass'] ?? ''),
    'charset' => trim((string)($existingConfig['charset'] ?? 'utf8mb4')),
], $_SERVER);

$steps = ['requirements', 'organization', 'database', 'administrator', 'review'];
$stepTitles = [
    'requirements' => 'Wymagania systemowe',
    'organization' => 'Dane organizacji',
    'database' => 'Baza danych',
    'administrator' => 'Konto administratora',
    'review' => 'Podsumowanie i instalacja',
    'success' => 'Instalacja zakończona',
];

$requirements = $service->requirements();
$wizardState = InstallWizardState::all();
$reason = trim((string)($_GET['reason'] ?? ''));

if (isset($_GET['reset']) && $_GET['reset'] === '1') {
    InstallWizardState::clear();
    header('Location: ' . BASE_URL . '/install.php', true, 303);
    exit;
}

$flashSuccess = $_SESSION['installer_success'] ?? null;
unset($_SESSION['installer_success']);

$installBlockMessages = buildInstallBlockMessages($guardInfo, is_file($lockFile) || is_file($legacyLockFile));
$installBlocked = $installBlockMessages !== [];
$errors = [];
$databaseAnalysis = null;
$successData = null;

$organizationData = $service->normalizeOrganizationData((array)($wizardState['organization'] ?? []));
$databaseData = $service->normalizeDatabaseConfig(array_merge([
    'host' => trim((string)($existingConfig['host'] ?? '127.0.0.1')),
    'port' => (int)($existingConfig['port'] ?? 3306),
    'name' => trim((string)($existingConfig['name'] ?? 'crm')),
    'user' => trim((string)($existingConfig['user'] ?? 'crm')),
    'pass' => (string)($existingConfig['pass'] ?? ''),
    'charset' => trim((string)($existingConfig['charset'] ?? 'utf8mb4')) ?: 'utf8mb4',
    'table_prefix' => trim((string)($existingConfig['table_prefix'] ?? '')),
    'create_if_missing' => false,
], (array)($wizardState['database'] ?? [])));
$administratorData = $service->normalizeAdministratorData(array_merge([
    'first_name' => '',
    'last_name' => '',
    'login' => 'admin',
    'email' => '',
    'password' => '',
    'password_confirm' => '',
], (array)($wizardState['administrator'] ?? [])));

$currentStep = resolveStep((string)($_GET['step'] ?? ''), $steps, $wizardState, $flashSuccess !== null);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $postedStep = (string)($_POST['step'] ?? 'requirements');
    $action = (string)($_POST['action'] ?? 'next');

    if ($installBlocked) {
        $errors = $installBlockMessages;
        $currentStep = $postedStep;
    } elseif (!verifyInstallerCsrfToken((string)($_POST['installer_csrf'] ?? ''))) {
        $errors[] = 'Sesja instalatora wygasła albo token bezpieczeństwa jest nieprawidłowy. Odśwież stronę i spróbuj ponownie.';
        $currentStep = $postedStep;
    } elseif ($action === 'back') {
        header('Location: ' . BASE_URL . '/install.php?step=' . rawurlencode(previousStep($postedStep, $steps)));
        exit;
    } else {
        switch ($postedStep) {
            case 'requirements':
                if (hasBlockingRequirements($requirements)) {
                    $errors[] = 'Przed przejściem dalej popraw wszystkie wymagane problemy środowiskowe.';
                    $currentStep = 'requirements';
                    break;
                }
                header('Location: ' . BASE_URL . '/install.php?step=organization', true, 303);
                exit;

            case 'organization':
                $organizationData = $service->normalizeOrganizationData($_POST);
                $errors = validateOrganizationStep($organizationData);
                $currentStep = 'organization';
                if ($errors === []) {
                    InstallWizardState::put('organization', $organizationData);
                    header('Location: ' . BASE_URL . '/install.php?step=database', true, 303);
                    exit;
                }
                break;

            case 'database':
                $databaseData = $service->normalizeDatabaseConfig([
                    'host' => $_POST['host'] ?? '',
                    'port' => $_POST['port'] ?? 3306,
                    'name' => $_POST['name'] ?? '',
                    'user' => $_POST['user'] ?? '',
                    'pass' => $_POST['pass'] ?? '',
                    'table_prefix' => $_POST['table_prefix'] ?? '',
                    'create_if_missing' => !empty($_POST['create_if_missing']),
                ]);
                $errors = validateDatabaseStep($databaseData);
                $currentStep = 'database';
                if ($errors === []) {
                    $databaseAnalysis = $service->analyzeDatabase($databaseData);
                    if (empty($databaseAnalysis['ok'])) {
                        $errors[] = (string)($databaseAnalysis['message'] ?? 'Nie udało się przygotować danych bazy.');
                    } else {
                        InstallWizardState::put('database', $databaseData);
                        header('Location: ' . BASE_URL . '/install.php?step=administrator', true, 303);
                        exit;
                    }
                }
                break;

            case 'administrator':
                $administratorData = $service->normalizeAdministratorData([
                    'first_name' => $_POST['first_name'] ?? '',
                    'last_name' => $_POST['last_name'] ?? '',
                    'login' => $_POST['login'] ?? '',
                    'email' => $_POST['email'] ?? '',
                    'password' => $_POST['password'] ?? '',
                    'password_confirm' => $_POST['password_confirm'] ?? '',
                ]);
                $errors = validateAdministratorStep($administratorData);
                $currentStep = 'administrator';
                if ($errors === []) {
                    InstallWizardState::put('administrator', $administratorData);
                    header('Location: ' . BASE_URL . '/install.php?step=review', true, 303);
                    exit;
                }
                break;

            case 'review':
                $wizardState = InstallWizardState::all();
                $organizationData = $service->normalizeOrganizationData((array)($wizardState['organization'] ?? []));
                $databaseData = $service->normalizeDatabaseConfig((array)($wizardState['database'] ?? []));
                $administratorData = $service->normalizeAdministratorData((array)($wizardState['administrator'] ?? []));
                $currentStep = 'review';

                if ($organizationData['company_name'] === '' || $databaseData['name'] === '' || $administratorData['login'] === '') {
                    $errors[] = 'Brakuje kompletnych danych instalacyjnych. Przejdź przez poprzednie kroki jeszcze raz.';
                    break;
                }

                try {
                    $successData = $service->install([
                        'organization' => $organizationData,
                        'database' => $databaseData,
                        'administrator' => $administratorData,
                    ], $existingConfig);

                    InstallWizardState::clear();
                    $_SESSION['installer_success'] = [
                        'organization' => $organizationData,
                        'database' => $databaseData,
                        'administrator' => [
                            'first_name' => $administratorData['first_name'],
                            'last_name' => $administratorData['last_name'],
                            'login' => $administratorData['login'],
                            'email' => $administratorData['email'],
                        ],
                        'result' => $successData,
                    ];

                    header('Location: ' . BASE_URL . '/install.php?step=success', true, 303);
                    exit;
                } catch (Throwable $e) {
                    error_log('install.php: installation failed: ' . $e->getMessage());
                    $errors[] = 'Instalacja nie powiodła się. Sprawdź połączenie z bazą i uprawnienia katalogów, a następnie spróbuj ponownie.';
                }
                break;
        }
    }
}

if ($currentStep === 'success') {
    if ($flashSuccess === null) {
        header('Location: ' . BASE_URL . '/install.php');
        exit;
    }
    $successData = is_array($flashSuccess) ? $flashSuccess : null;
}

if ($installBlocked && $currentStep !== 'success' && !headers_sent()) {
    http_response_code(403);
}

$wizardState = InstallWizardState::all();
if (
    $currentStep === 'database'
    && $errors === []
    && !$installBlocked
    && $databaseData['name'] !== ''
    && (!empty($wizardState['database']) || $_SERVER['REQUEST_METHOD'] === 'POST')
) {
    $databaseAnalysis = $service->analyzeDatabase($databaseData);
}

$completedSteps = [
    'requirements' => !hasBlockingRequirements($requirements),
    'organization' => !empty($wizardState['organization']),
    'database' => !empty($wizardState['database']),
    'administrator' => !empty($wizardState['administrator']),
];

?>
<!DOCTYPE html>
<html lang="pl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Instalator CRM</title>
    <style>
        :root {
            --bg: #eef3f9;
            --panel: #ffffff;
            --text: #102033;
            --muted: #65768a;
            --line: #d8e1ec;
            --accent: #1460e1;
            --accent-soft: #eaf2ff;
            --ok: #1f8f4e;
            --warn: #b86b00;
            --error: #c73b31;
            --radius: 22px;
            --shadow: 0 24px 64px rgba(16, 32, 51, 0.10);
        }

        * { box-sizing: border-box; }
        body {
            margin: 0;
            min-height: 100vh;
            font-family: "Segoe UI", Tahoma, sans-serif;
            background:
                radial-gradient(circle at top left, rgba(20, 96, 225, 0.12), transparent 28%),
                radial-gradient(circle at right bottom, rgba(20, 96, 225, 0.08), transparent 22%),
                var(--bg);
            color: var(--text);
        }

        .page {
            width: min(1040px, calc(100% - 32px));
            margin: 36px auto 48px;
        }

        .hero, .panel {
            background: var(--panel);
            border: 1px solid rgba(216, 225, 236, 0.9);
            border-radius: var(--radius);
            box-shadow: var(--shadow);
        }

        .hero {
            padding: 32px;
            margin-bottom: 24px;
        }

        .eyebrow {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 8px 12px;
            border-radius: 999px;
            background: var(--accent-soft);
            color: var(--accent);
            font-size: 13px;
            font-weight: 700;
            letter-spacing: 0.02em;
            text-transform: uppercase;
        }

        h1 {
            margin: 18px 0 10px;
            font-size: clamp(32px, 5vw, 48px);
            line-height: 1.05;
        }

        .lead {
            margin: 0;
            max-width: 760px;
            color: var(--muted);
            font-size: 18px;
            line-height: 1.65;
        }

        .layout {
            display: grid;
            grid-template-columns: 280px minmax(0, 1fr);
            gap: 24px;
        }

        .sidebar, .content {
            min-width: 0;
        }

        .panel {
            padding: 24px;
        }

        .progress {
            display: grid;
            gap: 12px;
        }

        .progress-item {
            border: 1px solid var(--line);
            border-radius: 18px;
            padding: 14px 16px;
            background: #f8fbff;
        }

        .progress-item.is-current {
            border-color: rgba(20, 96, 225, 0.32);
            background: var(--accent-soft);
        }

        .progress-item.is-done {
            border-color: rgba(31, 143, 78, 0.24);
            background: rgba(31, 143, 78, 0.08);
        }

        .progress-index {
            font-size: 12px;
            font-weight: 700;
            color: var(--muted);
            text-transform: uppercase;
            letter-spacing: 0.04em;
        }

        .progress-title {
            margin-top: 6px;
            font-size: 16px;
            font-weight: 700;
        }

        .progress-copy {
            margin-top: 6px;
            font-size: 14px;
            color: var(--muted);
            line-height: 1.55;
        }

        .content-header {
            margin-bottom: 20px;
        }

        .content-header h2 {
            margin: 0 0 8px;
            font-size: 28px;
        }

        .content-header p {
            margin: 0;
            color: var(--muted);
            line-height: 1.6;
        }

        .alert {
            border-radius: 18px;
            padding: 16px 18px;
            margin-bottom: 18px;
            border: 1px solid transparent;
            line-height: 1.6;
        }

        .alert strong { display: block; margin-bottom: 4px; }
        .alert-error { background: rgba(199, 59, 49, 0.08); border-color: rgba(199, 59, 49, 0.18); color: #872820; }
        .alert-warn { background: rgba(184, 107, 0, 0.10); border-color: rgba(184, 107, 0, 0.20); color: #7f4c00; }
        .alert-ok { background: rgba(31, 143, 78, 0.10); border-color: rgba(31, 143, 78, 0.22); color: #176a3a; }
        .alert-info { background: rgba(20, 96, 225, 0.08); border-color: rgba(20, 96, 225, 0.18); color: #134ea8; }

        .grid {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 16px 18px;
        }

        .field {
            display: grid;
            gap: 8px;
        }

        .field.is-full {
            grid-column: 1 / -1;
        }

        label {
            font-weight: 700;
            font-size: 14px;
        }

        .hint {
            font-size: 13px;
            color: var(--muted);
            line-height: 1.5;
        }

        input[type="text"],
        input[type="email"],
        input[type="password"],
        input[type="number"],
        textarea {
            width: 100%;
            border: 1px solid var(--line);
            border-radius: 14px;
            padding: 14px 16px;
            font: inherit;
            color: var(--text);
            background: #fff;
        }

        textarea {
            min-height: 112px;
            resize: vertical;
        }

        input:focus,
        textarea:focus {
            outline: none;
            border-color: rgba(20, 96, 225, 0.45);
            box-shadow: 0 0 0 4px rgba(20, 96, 225, 0.12);
        }

        .checkbox-row {
            display: flex;
            align-items: flex-start;
            gap: 12px;
            padding: 14px 16px;
            border-radius: 14px;
            border: 1px solid var(--line);
            background: #f9fbfd;
        }

        .checkbox-row input {
            margin-top: 3px;
        }

        .requirements-list,
        .summary-list,
        .detail-list {
            display: grid;
            gap: 12px;
        }

        .requirement {
            display: flex;
            justify-content: space-between;
            gap: 16px;
            border: 1px solid var(--line);
            border-radius: 16px;
            padding: 14px 16px;
            background: #f8fbff;
        }

        .requirement strong {
            display: block;
            margin-bottom: 4px;
        }

        .badge {
            flex: 0 0 auto;
            align-self: start;
            padding: 7px 10px;
            border-radius: 999px;
            font-size: 12px;
            font-weight: 700;
            letter-spacing: 0.03em;
            text-transform: uppercase;
        }

        .badge-ok { background: rgba(31, 143, 78, 0.12); color: var(--ok); }
        .badge-warn { background: rgba(184, 107, 0, 0.12); color: var(--warn); }
        .badge-error { background: rgba(199, 59, 49, 0.12); color: var(--error); }

        .actions {
            display: flex;
            justify-content: space-between;
            gap: 12px;
            margin-top: 24px;
            flex-wrap: wrap;
        }

        .btn {
            border: none;
            border-radius: 999px;
            padding: 14px 22px;
            font: inherit;
            font-weight: 700;
            cursor: pointer;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
        }

        .btn-primary {
            background: linear-gradient(135deg, #1460e1, #0d7ee8);
            color: #fff;
            box-shadow: 0 16px 30px rgba(20, 96, 225, 0.22);
        }

        .btn-secondary {
            background: #eff4fa;
            color: var(--text);
            border: 1px solid var(--line);
        }

        .summary-card {
            border: 1px solid var(--line);
            border-radius: 18px;
            padding: 18px;
            background: #f9fbfd;
        }

        .summary-card h3 {
            margin: 0 0 12px;
            font-size: 18px;
        }

        .summary-row {
            display: flex;
            justify-content: space-between;
            gap: 16px;
            padding: 8px 0;
            border-bottom: 1px solid rgba(216, 225, 236, 0.7);
        }

        .summary-row:last-child {
            border-bottom: none;
            padding-bottom: 0;
        }

        .summary-row span:first-child {
            color: var(--muted);
        }

        .footnote {
            margin-top: 18px;
            color: var(--muted);
            font-size: 13px;
            line-height: 1.6;
        }

        @media (max-width: 920px) {
            .layout {
                grid-template-columns: 1fr;
            }
        }

        @media (max-width: 720px) {
            .page {
                width: min(100% - 20px, 1040px);
                margin-top: 18px;
            }

            .hero, .panel {
                padding: 20px;
                border-radius: 18px;
            }

            .grid {
                grid-template-columns: 1fr;
            }

            .actions {
                flex-direction: column-reverse;
            }

            .btn {
                width: 100%;
            }

            .summary-row,
            .requirement {
                flex-direction: column;
            }
        }
    </style>
</head>
<body>
<div class="page">
    <section class="hero">
        <div class="eyebrow">Instalacja produkcyjna CRM</div>
        <h1>Skonfiguruj środowisko krok po kroku</h1>
        <p class="lead">
            Ten wizard przygotuje połączenie z bazą, zapisze dane organizacji i utworzy pierwsze konto administratora.
            Logo zostało świadomie odroczone do ustawień po instalacji, żeby nie komplikować podstawowego flow wdrożenia.
        </p>
    </section>

    <?php if ($reason !== '' && !$installBlocked && $currentStep !== 'success'): ?>
        <div class="alert alert-info">
            <strong>Instalator został otwarty automatycznie.</strong>
            System rozpoznał stan niezainstalowanego środowiska (`<?= h($reason) ?>`) i przekierował do bezpiecznego flow first-run.
        </div>
    <?php endif; ?>

    <div class="layout">
        <aside class="sidebar">
            <div class="panel">
                <div class="progress">
                    <?php foreach ($steps as $index => $step): ?>
                        <?php
                        $classes = [];
                        if ($currentStep === $step) {
                            $classes[] = 'is-current';
                        } elseif (!empty($completedSteps[$step])) {
                            $classes[] = 'is-done';
                        }
                        ?>
                        <div class="progress-item <?= implode(' ', $classes) ?>">
                            <div class="progress-index">Krok <?= $index + 1 ?></div>
                            <div class="progress-title"><?= h($stepTitles[$step]) ?></div>
                            <div class="progress-copy">
                                <?= h(progressCopy($step)) ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
                <p class="footnote">
                    Baseline: <strong><?= h(InstallBaseline::BASELINE_ID) ?></strong><br>
                    `prog_procentowy`: <strong><?= h(InstallBaseline::PROG_PROCENTOWY_POLICY) ?></strong><br>
                    Migracje wchłonięte: <strong><?= count(InstallBaseline::absorbedMigrations()) ?></strong>
                </p>
            </div>
        </aside>

        <main class="content">
            <div class="panel">
                <?php if ($currentStep === 'success' && $successData !== null): ?>
                    <div class="content-header">
                        <h2>Instalacja zakończona</h2>
                        <p>Środowisko zostało przygotowane, konfiguracja zapisana i instalator zablokowany przed ponownym użyciem.</p>
                    </div>

                    <div class="alert alert-ok">
                        <strong>CRM jest gotowy do pierwszego logowania.</strong>
                        Możesz teraz przejść do ekranu logowania i dokończyć ustawienia operacyjne, takie jak logo, SMTP i integracje.
                    </div>

                    <div class="summary-list">
                        <div class="summary-card">
                            <h3>Organizacja</h3>
                            <div class="summary-row"><span>Nazwa</span><span><?= h((string)$successData['organization']['company_name']) ?></span></div>
                            <div class="summary-row"><span>NIP</span><span><?= h((string)($successData['organization']['company_nip'] ?: '—')) ?></span></div>
                            <div class="summary-row"><span>Email</span><span><?= h((string)($successData['organization']['company_email'] ?: '—')) ?></span></div>
                            <div class="summary-row"><span>Telefon</span><span><?= h((string)($successData['organization']['company_phone'] ?: '—')) ?></span></div>
                        </div>

                        <div class="summary-card">
                            <h3>Baza danych i administrator</h3>
                            <div class="summary-row"><span>Host</span><span><?= h((string)$successData['database']['host']) ?>:<?= (int)$successData['database']['port'] ?></span></div>
                            <div class="summary-row"><span>Baza</span><span><?= h((string)$successData['database']['name']) ?></span></div>
                            <div class="summary-row"><span>Prefiks tabel</span><span><?= h((string)($successData['database']['table_prefix'] ?? '') !== '' ? (string)$successData['database']['table_prefix'] : 'Brak') ?></span></div>
                            <div class="summary-row"><span>Login administratora</span><span><?= h((string)$successData['administrator']['login']) ?></span></div>
                            <div class="summary-row"><span>Email administratora</span><span><?= h((string)$successData['administrator']['email']) ?></span></div>
                        </div>

                        <div class="summary-card">
                            <h3>Co stało się dalej</h3>
                            <?php foreach ((array)($successData['result']['details'] ?? []) as $detail): ?>
                                <div class="summary-row"><span>Wykonano</span><span><?= h((string)$detail) ?></span></div>
                            <?php endforeach; ?>
                        </div>
                    </div>

                    <div class="actions">
                        <a class="btn btn-secondary" href="<?= h(BASE_URL) ?>/install.php?reset=1">Wyczyść stan wizarda</a>
                        <a class="btn btn-primary" href="<?= h(BASE_URL) ?>/login.php">Przejdź do logowania</a>
                    </div>
                <?php elseif ($installBlocked): ?>
                    <div class="content-header">
                        <h2>Instalator jest zablokowany</h2>
                        <p>To środowisko nie kwalifikuje się do publicznej instalacji z przeglądarki.</p>
                    </div>
                    <div class="detail-list">
                        <?php foreach ($installBlockMessages as $message): ?>
                            <div class="alert alert-warn"><?= h($message) ?></div>
                        <?php endforeach; ?>
                    </div>
                <?php else: ?>
                    <div class="content-header">
                        <h2><?= h($stepTitles[$currentStep]) ?></h2>
                        <p><?= h(stepDescription($currentStep)) ?></p>
                    </div>

                    <?php if ($errors !== []): ?>
                        <div class="alert alert-error">
                            <strong>Trzeba poprawić kilka rzeczy.</strong>
                            <?php foreach ($errors as $error): ?>
                                <div><?= h((string)$error) ?></div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>

                    <?php if ($currentStep === 'requirements'): ?>
                        <form method="post">
                            <input type="hidden" name="step" value="requirements">
                            <?= installerCsrfField() ?>
                            <div class="requirements-list">
                                <?php foreach ($requirements as $check): ?>
                                    <?php
                                    $badgeClass = !empty($check['ok']) ? 'badge-ok' : (!empty($check['required']) ? 'badge-error' : 'badge-warn');
                                    $badgeText = !empty($check['ok']) ? 'OK' : (!empty($check['required']) ? 'Wymagane' : 'Ostrzeżenie');
                                    ?>
                                    <div class="requirement">
                                        <div>
                                            <strong><?= h((string)$check['label']) ?></strong>
                                            <div class="hint"><?= h((string)$check['message']) ?></div>
                                        </div>
                                        <span class="badge <?= $badgeClass ?>"><?= h($badgeText) ?></span>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                            <div class="actions">
                                <span></span>
                                <button class="btn btn-primary" type="submit" name="action" value="next">Przejdź do danych organizacji</button>
                            </div>
                        </form>
                    <?php elseif ($currentStep === 'organization'): ?>
                        <form method="post">
                            <input type="hidden" name="step" value="organization">
                            <?= installerCsrfField() ?>
                            <div class="grid">
                                <div class="field is-full">
                                    <label for="company_name">Nazwa organizacji *</label>
                                    <input id="company_name" name="company_name" type="text" value="<?= h($organizationData['company_name']) ?>" placeholder="Na przykład: Radio Żuławy Sp. z o.o." required>
                                    <div class="hint">To ta nazwa będzie używana w dokumentach, widokach systemowych i komunikacji z klientami.</div>
                                </div>
                                <div class="field">
                                    <label for="company_nip">NIP</label>
                                    <input id="company_nip" name="company_nip" type="text" value="<?= h($organizationData['company_nip']) ?>" placeholder="1234567890">
                                </div>
                                <div class="field">
                                    <label for="company_email">Email organizacji</label>
                                    <input id="company_email" name="company_email" type="email" value="<?= h($organizationData['company_email']) ?>" placeholder="biuro@twojafirma.pl">
                                </div>
                                <div class="field is-full">
                                    <label for="company_address">Adres</label>
                                    <textarea id="company_address" name="company_address" placeholder="Ulica, kod pocztowy, miejscowość"><?= h($organizationData['company_address']) ?></textarea>
                                </div>
                                <div class="field">
                                    <label for="company_phone">Telefon</label>
                                    <input id="company_phone" name="company_phone" type="text" value="<?= h($organizationData['company_phone']) ?>" placeholder="+48 55 000 00 00">
                                </div>
                                <div class="field">
                                    <label>Logo</label>
                                    <div class="checkbox-row">
                                        <div>
                                            <strong>Dodasz po instalacji</strong>
                                            <div class="hint">Logo zostało odroczone do ustawień systemowych po pierwszym logowaniu, żeby nie komplikować podstawowego wdrożenia.</div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <div class="actions">
                                <button class="btn btn-secondary" type="submit" name="action" value="back">Wróć</button>
                                <button class="btn btn-primary" type="submit" name="action" value="next">Zapisz i przejdź do bazy</button>
                            </div>
                        </form>
                    <?php elseif ($currentStep === 'database'): ?>
                        <form method="post">
                            <input type="hidden" name="step" value="database">
                            <?= installerCsrfField() ?>
                            <div class="grid">
                                <div class="field">
                                    <label for="host">Host DB *</label>
                                    <input id="host" name="host" type="text" value="<?= h($databaseData['host']) ?>" placeholder="127.0.0.1 albo db" required>
                                </div>
                                <div class="field">
                                    <label for="port">Port DB (opcjonalny)</label>
                                    <input id="port" name="port" type="number" min="1" max="65535" value="<?= (int)$databaseData['port'] ?>" placeholder="3306">
                                </div>
                                <div class="field">
                                    <label for="name">Nazwa bazy *</label>
                                    <input id="name" name="name" type="text" value="<?= h($databaseData['name']) ?>" placeholder="crm_production" required>
                                </div>
                                <div class="field">
                                    <label for="user">Użytkownik DB *</label>
                                    <input id="user" name="user" type="text" value="<?= h($databaseData['user']) ?>" placeholder="crm_user" required>
                                </div>
                                <div class="field is-full">
                                    <label for="pass">Hasło DB</label>
                                    <input id="pass" name="pass" type="password" value="<?= h($databaseData['pass']) ?>" placeholder="Pozostaw puste tylko jeśli baza tego wymaga">
                                </div>
                                <div class="field is-full">
                                    <label for="table_prefix">Prefiks tabel (opcjonalny)</label>
                                    <input id="table_prefix" name="table_prefix" type="text" value="<?= h((string)$databaseData['table_prefix']) ?>" placeholder="np. crm_ (w CRM 1.0 zalecane: puste)">
                                    <div class="hint">CRM 1.0 używa kanonicznych nazw tabel bez prefiksu. Pozostaw to pole puste, aby uniknąć niespójności.</div>
                                </div>
                                <div class="field is-full">
                                    <label class="checkbox-row">
                                        <input type="checkbox" name="create_if_missing" value="1" <?= $databaseData['create_if_missing'] ? 'checked' : '' ?>>
                                        <div>
                                            <strong>Spróbuj utworzyć bazę, jeśli jeszcze nie istnieje</strong>
                                            <div class="hint">To jest tylko opcjonalna próba w finalnym kroku. Jeśli konto DB nie ma takich uprawnień, instalator zatrzyma się z czytelnym komunikatem.</div>
                                        </div>
                                    </label>
                                </div>
                            </div>

                            <?php if (is_array($databaseAnalysis)): ?>
                                <?php
                                $analysisClass = !empty($databaseAnalysis['ok']) ? 'alert-ok' : 'alert-warn';
                                ?>
                                <div class="alert <?= $analysisClass ?>">
                                    <strong>Stan docelowej bazy: <?= h((string)$databaseAnalysis['state']) ?></strong>
                                    <?= h((string)$databaseAnalysis['message']) ?>
                                    <?php if (!empty($databaseAnalysis['details'])): ?>
                                        <div class="footnote">Przykładowe wykryte tabele: <?= h(implode(', ', (array)$databaseAnalysis['details'])) ?></div>
                                    <?php endif; ?>
                                </div>
                            <?php endif; ?>

                            <div class="actions">
                                <button class="btn btn-secondary" type="submit" name="action" value="back">Wróć</button>
                                <button class="btn btn-primary" type="submit" name="action" value="next">Sprawdź bazę i przejdź dalej</button>
                            </div>
                        </form>
                    <?php elseif ($currentStep === 'administrator'): ?>
                        <form method="post">
                            <input type="hidden" name="step" value="administrator">
                            <?= installerCsrfField() ?>
                            <div class="grid">
                                <div class="field">
                                    <label for="first_name">Imię *</label>
                                    <input id="first_name" name="first_name" type="text" value="<?= h($administratorData['first_name']) ?>" placeholder="Jan" required>
                                </div>
                                <div class="field">
                                    <label for="last_name">Nazwisko *</label>
                                    <input id="last_name" name="last_name" type="text" value="<?= h($administratorData['last_name']) ?>" placeholder="Kowalski" required>
                                </div>
                                <div class="field">
                                    <label for="login">Login *</label>
                                    <input id="login" name="login" type="text" value="<?= h($administratorData['login']) ?>" placeholder="admin" required>
                                    <div class="hint">Ten login będzie używany przy pierwszym logowaniu do CRM.</div>
                                </div>
                                <div class="field">
                                    <label for="email">Email *</label>
                                    <input id="email" name="email" type="email" value="<?= h($administratorData['email']) ?>" placeholder="admin@twojafirma.pl" required>
                                </div>
                                <div class="field">
                                    <label for="password">Hasło *</label>
                                    <input id="password" name="password" type="password" value="<?= h($administratorData['password']) ?>" placeholder="Minimum 8 znaków" required>
                                </div>
                                <div class="field">
                                    <label for="password_confirm">Powtórz hasło *</label>
                                    <input id="password_confirm" name="password_confirm" type="password" value="<?= h($administratorData['password_confirm']) ?>" placeholder="Powtórz hasło" required>
                                </div>
                            </div>
                            <div class="actions">
                                <button class="btn btn-secondary" type="submit" name="action" value="back">Wróć</button>
                                <button class="btn btn-primary" type="submit" name="action" value="next">Zapisz konto i przejdź do podsumowania</button>
                            </div>
                        </form>
                    <?php elseif ($currentStep === 'review'): ?>
                        <?php
                        $reviewOrganization = $service->normalizeOrganizationData((array)($wizardState['organization'] ?? []));
                        $reviewDatabase = $service->normalizeDatabaseConfig((array)($wizardState['database'] ?? []));
                        $reviewAdministrator = $service->normalizeAdministratorData((array)($wizardState['administrator'] ?? []));
                        ?>
                        <div class="summary-list">
                            <div class="summary-card">
                                <h3>Organizacja</h3>
                                <div class="summary-row"><span>Nazwa</span><span><?= h($reviewOrganization['company_name']) ?></span></div>
                                <div class="summary-row"><span>NIP</span><span><?= h($reviewOrganization['company_nip'] ?: '—') ?></span></div>
                                <div class="summary-row"><span>Email</span><span><?= h($reviewOrganization['company_email'] ?: '—') ?></span></div>
                                <div class="summary-row"><span>Telefon</span><span><?= h($reviewOrganization['company_phone'] ?: '—') ?></span></div>
                                <div class="summary-row"><span>Adres</span><span><?= h($reviewOrganization['company_address'] ?: '—') ?></span></div>
                            </div>

                            <div class="summary-card">
                                <h3>Baza danych</h3>
                                <div class="summary-row"><span>Host</span><span><?= h($reviewDatabase['host']) ?>:<?= (int)$reviewDatabase['port'] ?></span></div>
                                <div class="summary-row"><span>Nazwa</span><span><?= h($reviewDatabase['name']) ?></span></div>
                                <div class="summary-row"><span>Użytkownik</span><span><?= h($reviewDatabase['user']) ?></span></div>
                                <div class="summary-row"><span>Prefiks tabel</span><span><?= h($reviewDatabase['table_prefix'] !== '' ? $reviewDatabase['table_prefix'] : 'Brak') ?></span></div>
                                <div class="summary-row"><span>Tworzenie bazy</span><span><?= $reviewDatabase['create_if_missing'] ? 'Tak, jeśli będzie potrzebna' : 'Nie, używamy istniejącej bazy' ?></span></div>
                            </div>

                            <div class="summary-card">
                                <h3>Pierwszy administrator</h3>
                                <div class="summary-row"><span>Imię i nazwisko</span><span><?= h(trim($reviewAdministrator['first_name'] . ' ' . $reviewAdministrator['last_name'])) ?></span></div>
                                <div class="summary-row"><span>Login</span><span><?= h($reviewAdministrator['login']) ?></span></div>
                                <div class="summary-row"><span>Email</span><span><?= h($reviewAdministrator['email']) ?></span></div>
                                <div class="summary-row"><span>Hasło</span><span><?= h(maskSecret($reviewAdministrator['password'])) ?></span></div>
                            </div>
                        </div>

                        <div class="alert alert-info">
                            <strong>Co wydarzy się po kliknięciu „Instaluj CRM”.</strong>
                            Instalator zaimportuje kanoniczny baseline, oznaczy migracje wchłonięte do baseline’u, uruchomi tylko nowsze migracje,
                            zapisze konfigurację organizacji, utworzy pierwszego administratora, zapisze `config/db.local.php`, ustawi `app_meta`
                            i na końcu zablokuje ponowną instalację.
                        </div>

                        <form method="post">
                            <input type="hidden" name="step" value="review">
                            <?= installerCsrfField() ?>
                            <div class="actions">
                                <button class="btn btn-secondary" type="submit" name="action" value="back">Wróć</button>
                                <button class="btn btn-primary" type="submit" name="action" value="install">Instaluj CRM</button>
                            </div>
                        </form>
                    <?php endif; ?>
                <?php endif; ?>
            </div>
        </main>
    </div>
</div>
</body>
</html>
<?php

function hasBlockingRequirements(array $requirements): bool
{
    foreach ($requirements as $requirement) {
        if (!empty($requirement['required']) && empty($requirement['ok'])) {
            return true;
        }
    }
    return false;
}

function buildInstallBlockMessages(array $guardInfo, bool $isLocked): array
{
    $messages = [];
    $status = (string)($guardInfo['status'] ?? '');
    $reason = (string)($guardInfo['reason'] ?? '');

    if ($status === InstallGuard::STATUS_INSTALLED) {
        $messages[] = $reason === 'legacy_install'
            ? 'Wykryto działającą instalację legacy CRM. Publiczny wizard nie nadpisuje istniejących środowisk.'
            : 'To środowisko jest już oznaczone jako zainstalowane. Publiczna reinstalacja została zablokowana.';
    } elseif ($status === InstallGuard::STATUS_DB_ERROR) {
        $messages[] = 'System ma już konfigurację bazy danych, ale połączenie z nią nie działa. Najpierw napraw połączenie lub użyj kontrolowanego procesu serwisowego.';
    }

    if ($isLocked) {
        $messages[] = 'Dodatkowo wykryto plik blokady instalatora (`storage/installed.lock` lub legacy `storage/installer.lock`).';
    }

    return $messages;
}

function resolveStep(string $requestedStep, array $steps, array $wizardState, bool $hasSuccessFlash): string
{
    if ($requestedStep === 'success' && $hasSuccessFlash) {
        return 'success';
    }

    if ($requestedStep === '' || !in_array($requestedStep, $steps, true)) {
        return firstIncompleteStep($wizardState);
    }

    $allowed = availableSteps($wizardState);
    if (in_array($requestedStep, $allowed, true)) {
        return $requestedStep;
    }

    return firstIncompleteStep($wizardState);
}

function firstIncompleteStep(array $wizardState): string
{
    if (empty($wizardState['organization'])) {
        return 'requirements';
    }
    if (empty($wizardState['database'])) {
        return 'database';
    }
    if (empty($wizardState['administrator'])) {
        return 'administrator';
    }
    return 'review';
}

function availableSteps(array $wizardState): array
{
    $allowed = ['requirements', 'organization'];
    if (!empty($wizardState['organization'])) {
        $allowed[] = 'database';
    }
    if (!empty($wizardState['database'])) {
        $allowed[] = 'administrator';
    }
    if (!empty($wizardState['administrator'])) {
        $allowed[] = 'review';
    }
    return $allowed;
}

function previousStep(string $currentStep, array $steps): string
{
    $index = array_search($currentStep, $steps, true);
    if ($index === false || $index <= 0) {
        return 'requirements';
    }
    return $steps[$index - 1];
}

function progressCopy(string $step): string
{
    return match ($step) {
        'requirements' => 'Sprawdzenie środowiska, katalogów i baseline’u.',
        'organization' => 'Podstawowe dane podmiotu operującego systemem.',
        'database' => 'Połączenie z DB i ocena stanu docelowej bazy.',
        'administrator' => 'Pierwsze konto z pełnym dostępem administracyjnym.',
        'review' => 'Ostateczna weryfikacja i uruchomienie instalacji.',
        default => '',
    };
}

function stepDescription(string $step): string
{
    return match ($step) {
        'requirements' => 'Najpierw upewnijmy się, że środowisko jest gotowe do bezpiecznej instalacji produkcyjnej.',
        'organization' => 'Zapisz dane organizacji, która będzie operować systemem. Logo dodasz później w ustawieniach.',
        'database' => 'Podaj dane bazy i sprawdź, czy docelowe środowisko jest puste, częściowe czy już zajęte przez istniejącą aplikację.',
        'administrator' => 'To konto będzie miało dostęp do konfiguracji systemu zaraz po pierwszym logowaniu.',
        'review' => 'Sprawdź podsumowanie. Dopiero ten krok zapisuje konfigurację, importuje baseline i uruchamia instalację.',
        default => '',
    };
}

function validateOrganizationStep(array $organizationData): array
{
    $errors = [];
    if ($organizationData['company_name'] === '') {
        $errors[] = 'Podaj nazwę organizacji.';
    }
    if ($organizationData['company_email'] !== '' && !filter_var($organizationData['company_email'], FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Podaj poprawny email organizacji.';
    }
    return $errors;
}

function validateDatabaseStep(array $databaseData): array
{
    $errors = [];
    if ($databaseData['host'] === '' || $databaseData['name'] === '' || $databaseData['user'] === '') {
        $errors[] = 'Wypełnij wszystkie wymagane pola połączenia do bazy.';
    }
    if ($databaseData['port'] < 1 || $databaseData['port'] > 65535) {
        $errors[] = 'Port bazy danych musi być liczbą z zakresu 1-65535.';
    }
    if (!preg_match('/^[A-Za-z0-9_]+$/', $databaseData['name'])) {
        $errors[] = 'Nazwa bazy może zawierać tylko litery, cyfry i znak podkreślenia.';
    }
    $tablePrefix = trim((string)($databaseData['table_prefix'] ?? ''));
    if ($tablePrefix !== '') {
        if (!preg_match('/^[A-Za-z0-9_]{1,32}$/', $tablePrefix)) {
            $errors[] = 'Prefiks tabel może zawierać tylko litery, cyfry i znak podkreślenia (1-32 znaki).';
        } else {
            $errors[] = 'Prefiks tabel nie jest jeszcze wspierany przez CRM 1.0. Pozostaw to pole puste.';
        }
    }
    return $errors;
}

function ensureInstallerCsrfToken(): string
{
    if (empty($_SESSION['installer_csrf']) || !is_string($_SESSION['installer_csrf'])) {
        $_SESSION['installer_csrf'] = bin2hex(random_bytes(32));
    }
    return (string)$_SESSION['installer_csrf'];
}

function verifyInstallerCsrfToken(string $token): bool
{
    $expected = (string)($_SESSION['installer_csrf'] ?? '');
    return $expected !== '' && $token !== '' && hash_equals($expected, $token);
}

function installerCsrfField(): string
{
    $token = ensureInstallerCsrfToken();
    return '<input type="hidden" name="installer_csrf" value="' . h($token) . '">';
}

function validateAdministratorStep(array $administratorData): array
{
    $errors = [];
    if ($administratorData['first_name'] === '' || $administratorData['last_name'] === '') {
        $errors[] = 'Podaj imię i nazwisko administratora.';
    }
    if ($administratorData['login'] === '' || !preg_match('/^[A-Za-z0-9._-]{3,64}$/', $administratorData['login'])) {
        $errors[] = 'Login administratora musi mieć 3-64 znaki i zawierać tylko litery, cyfry, kropkę, podkreślenie lub myślnik.';
    }
    if (!filter_var($administratorData['email'], FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Podaj poprawny email administratora.';
    }
    if (strlen($administratorData['password']) < 8) {
        $errors[] = 'Hasło administratora musi mieć co najmniej 8 znaków.';
    }
    if ($administratorData['password'] !== $administratorData['password_confirm']) {
        $errors[] = 'Hasło administratora i jego potwierdzenie nie są zgodne.';
    }
    return $errors;
}

function readPhpArrayFile(string $path): array
{
    if (!is_file($path)) {
        return [];
    }
    $loaded = include $path;
    return is_array($loaded) ? $loaded : [];
}

function maskSecret(string $value): string
{
    if ($value === '') {
        return '—';
    }
    return str_repeat('•', max(8, min(16, strlen($value))));
}

function h(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}
