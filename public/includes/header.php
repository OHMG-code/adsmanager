<?php
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/tasks.php';

$configPath = __DIR__ . '/../../config/config.php';
if (!isset($pdo) && file_exists($configPath)) {
    require_once $configPath;
}
require_once __DIR__ . '/emisje_helpers.php';
require_once __DIR__ . '/lead_pipeline_helpers.php';

$pdoReady = isset($pdo) && $pdo instanceof PDO;
if ($pdoReady) {
    ensureSpotColumns($pdo);
    ensureEmisjeSpotowTable($pdo);
    ensureKampanieOwnershipColumns($pdo);
    runMaintenanceIfDue($pdo);
}

$pageTitle = $pageTitle ?? "Panel zarządzania";
$current   = basename($_SERVER['PHP_SELF']);
$currentPath = trim((string)($_SERVER['PHP_SELF'] ?? ''), '/');

$notificationCount = 0;
if ($pdoReady && !empty($_SESSION['user_id'])) {
    try {
        ensureLeadColumns($pdo);
        ensureNotificationsTable($pdo);
        $leadCols = getTableColumns($pdo, 'leady');
        $userId = (int)$_SESSION['user_id'];
        if ($userId > 0 && hasColumn($leadCols, 'next_action_at')) {
            $excludeStatuses = leadFollowupExcludedStatuses();
            $placeholders = implode(',', array_fill(0, count($excludeStatuses), '?'));
            $sql = "SELECT id, nazwa_firmy, next_action, next_action_at
                FROM leady
                WHERE owner_user_id = ?
                  AND next_action_at IS NOT NULL
                  AND next_action_at <= CONCAT(CURDATE(), ' 23:59:59')
                  AND status NOT IN ($placeholders)";
            $activeConditions = buildActiveLeadConditions($leadCols);
            if ($activeConditions) {
                $sql .= ' AND ' . implode(' AND ', $activeConditions);
            }
            $stmt = $pdo->prepare($sql);
            $stmt->execute(array_merge([$userId], $excludeStatuses));
            $dueLeads = $stmt->fetchAll(PDO::FETCH_ASSOC);

            foreach ($dueLeads as $lead) {
                $link = 'lead.php?lead_edit_id=' . (int)$lead['id'] . '#lead-form';
                $check = $pdo->prepare("SELECT id FROM powiadomienia WHERE user_id = ? AND typ = 'lead_followup' AND link = ? AND DATE(created_at) = CURDATE() LIMIT 1");
                $check->execute([$userId, $link]);
                if ($check->fetch()) {
                    continue;
                }
                $text = 'Lead do kontaktu: ' . ($lead['nazwa_firmy'] ?? '');
                if (!empty($lead['next_action'])) {
                    $text .= ' — ' . $lead['next_action'];
                }
                $stmtIns = $pdo->prepare("INSERT INTO powiadomienia (user_id, typ, tresc, link) VALUES (?, 'lead_followup', ?, ?)");
                $stmtIns->execute([$userId, $text, $link]);
            }

            $countStmt = $pdo->prepare("SELECT COUNT(*) FROM powiadomienia WHERE user_id = ? AND is_read = 0 AND typ = 'lead_followup'");
            $countStmt->execute([$userId]);
            $notificationCount = (int)$countStmt->fetchColumn();
        }
    } catch (Throwable $e) {
        error_log('header: notifications failed: ' . $e->getMessage());
    }
}

function is_active($file) {
    global $current;
    return $current === $file;
}

function is_active_any(array $files): bool {
    global $current;
    return in_array($current, $files, true);
}

function is_active_path(string $path): bool {
    global $currentPath;
    $needle = trim($path, '/');
    if ($needle === '' || $currentPath === '') {
        return false;
    }
    if ($currentPath === $needle) {
        return true;
    }
    return substr($currentPath, -strlen($needle)) === $needle;
}

function query_value(string $key): string {
    return trim((string)($_GET[$key] ?? ''));
}

function get_action() {
    return $_GET['action'] ?? '';
}

$action = get_action();

$currentUser = $pdoReady ? getCurrentUser($pdo) : null;
$roleNormalized = $currentUser ? normalizeRole($currentUser) : '';
$canManageSystem = can('manage_system');
$canManageUpdates = can('manage_updates');
$canSeeAdministration = $canManageSystem || $canManageUpdates;
$campaignTypeFilter = query_value('campaign_type');
$teamSectionActive = is_active_any(['uzytkownicy.php', 'uzytkownik_dodaj.php', 'uzytkownik_edytuj.php']);
$offerSectionActive = is_active_any(['cenniki.php', 'cele.php', 'prowizje.php']);
$taskOverdueCount = 0;
if ($pdoReady && $currentUser) {
    $taskOverdueCount = countOverdueTasks((int)$currentUser['id'], $roleNormalized);
}

$pageStyles = array_values(array_unique(array_filter(array_map(static function ($style): string {
    $normalized = preg_replace('/[^a-z0-9\-]/i', '', (string)$style);
    return is_string($normalized) ? trim($normalized) : '';
}, $pageStyles ?? []))));

$assetVersionCandidates = [
    __DIR__ . '/../assets/css/themes/tokens.css',
    __DIR__ . '/../assets/css/themes/theme-light.css',
    __DIR__ . '/../assets/css/themes/theme-dark.css',
    __DIR__ . '/../assets/css/themes/overrides-bootstrap.css',
    __DIR__ . '/../assets/css/style.css',
    __DIR__ . '/../assets/css/ui.css',
    __DIR__ . '/../assets/js/theme.js',
];
foreach ($pageStyles as $pageStyle) {
    $pageStylePath = __DIR__ . '/../assets/css/pages/' . $pageStyle . '.css';
    if (is_file($pageStylePath)) {
        $assetVersionCandidates[] = $pageStylePath;
    }
}
$assetVersion = 0;
foreach ($assetVersionCandidates as $assetPath) {
    $mtime = @filemtime($assetPath);
    if ($mtime !== false && $mtime > $assetVersion) {
        $assetVersion = (int)$mtime;
    }
}
if ($assetVersion <= 0) {
    $assetVersion = time();
}
?>
<!DOCTYPE html>
<html lang="pl">
<head>
    <meta charset="UTF-8">
    <title><?= htmlspecialchars($pageTitle) ?> | Project Manager</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <script>
    (function () {
        var key = 'adsmanager_theme';
        var theme = 'light';
        try {
            var stored = localStorage.getItem(key);
            if (stored === 'dark' || stored === 'light') {
                theme = stored;
            }
        } catch (e) {}
        document.documentElement.setAttribute('data-theme', theme);
        document.documentElement.style.colorScheme = theme === 'dark' ? 'dark' : 'light';
    })();
    </script>

    <!-- Bootstrap -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="<?= htmlspecialchars(BASE_URL) ?>/assets/css/themes/tokens.css?v=<?= $assetVersion ?>" rel="stylesheet">
    <link href="<?= htmlspecialchars(BASE_URL) ?>/assets/css/themes/theme-light.css?v=<?= $assetVersion ?>" rel="stylesheet">
    <link href="<?= htmlspecialchars(BASE_URL) ?>/assets/css/themes/theme-dark.css?v=<?= $assetVersion ?>" rel="stylesheet">
    <link href="<?= htmlspecialchars(BASE_URL) ?>/assets/css/style.css?v=<?= $assetVersion ?>" rel="stylesheet">
    <link href="<?= htmlspecialchars(BASE_URL) ?>/assets/css/ui.css?v=<?= $assetVersion ?>" rel="stylesheet">
    <link href="<?= htmlspecialchars(BASE_URL) ?>/assets/css/themes/overrides-bootstrap.css?v=<?= $assetVersion ?>" rel="stylesheet">
    <?php foreach ($pageStyles as $pageStyle): ?>
        <?php $pageStyleHref = __DIR__ . '/../assets/css/pages/' . $pageStyle . '.css'; ?>
        <?php if (is_file($pageStyleHref)): ?>
            <link href="<?= htmlspecialchars(BASE_URL) ?>/assets/css/pages/<?= htmlspecialchars($pageStyle) ?>.css?v=<?= $assetVersion ?>" rel="stylesheet">
        <?php endif; ?>
    <?php endforeach; ?>
</head>

<body>
<!-- START: app-shell -->
<div class="app-shell">
    <header class="app-topbar">
        <div class="topbar-left">
            <div class="logo-placeholder">AM</div>
            <div class="topbar-title">
                <div class="topbar-name">Adds Manager 1.0</div>
                <div class="topbar-subtitle">Panel zarządzania</div>
            </div>
            <!-- TODO: Wstaw logo jako <img src=\"...\"> w miejscu .logo-placeholder -->
        </div>
        <div class="topbar-right">
            <a href="kalkulator_tygodniowy.php" class="btn btn-sm btn-topbar-action">Kalkulator</a>
            <a href="followup.php" class="btn btn-sm btn-topbar-action position-relative" aria-label="Powiadomienia">
                &#128276; Powiadomienia
                <?php if ($notificationCount > 0): ?>
                    <span class="position-absolute top-0 start-100 translate-middle badge rounded-pill bg-danger">
                        <?= $notificationCount ?>
                    </span>
                <?php endif; ?>
            </a>
            <div class="topbar-user">
                <?= htmlspecialchars($_SESSION['user_login'] ?? 'Użytkownik') ?>
            </div>
            <a href="logout.php" class="btn btn-sm btn-danger">Wyloguj</a>
        </div>
    </header>

    <!-- START: app-body -->
    <div class="app-body">
        <aside class="app-sidebar">
            <div class="sidebar-header">
                Menu
            </div>

            <div class="nav-group">
                <div class="nav-group-title">Start</div>
                <div class="nav-group-items">
                    <a href="dashboard.php" class="nav-link <?= is_active('dashboard.php') ? 'active' : '' ?>">Dashboard</a>
                    <?php if ($canManageSystem): ?>
                        <a href="dashboard_manager.php" class="nav-link <?= is_active('dashboard_manager.php') ? 'active' : '' ?>">Dashboard managera</a>
                    <?php endif; ?>
                </div>
            </div>

            <div class="nav-group">
                <div class="nav-group-title">Sprzedaż</div>
                <div class="nav-group-items">
                    <a href="lead.php" class="nav-link <?= is_active_any(['lead.php', 'leady.php', 'dodaj_lead.php', 'lead_szczegoly.php', 'lead_edytuj.php']) ? 'active' : '' ?>">Leady</a>
                    <a href="followup.php" class="nav-link <?= is_active_any(['followup.php', 'zadania.php']) ? 'active' : '' ?>">Follow-up i zadania</a>
                    <a href="generator_leadow.php" class="nav-link <?= is_active('generator_leadow.php') ? 'active' : '' ?>">Generator leadów</a>
                    <a href="klienci.php" class="nav-link <?= is_active_any(['klienci.php', 'lista_klientow.php', 'dodaj_klienta.php', 'edytuj_klienta.php', 'klient_szczegoly.php', 'klient_edytuj.php', 'clients.php', 'add_client.php', 'wyszukaj_klienta.php']) ? 'active' : '' ?>">Klienci</a>
                    <a href="kampanie_lista.php" class="nav-link <?= is_active_any(['kampanie_lista.php', 'kampania_podglad.php', 'kampania_audio.php', 'kampania_akceptuj.php']) ? 'active' : '' ?>">Kampanie</a>
                    <a href="kalkulator_tygodniowy.php" class="nav-link <?= is_active_any(['kalkulator_tygodniowy.php', 'kalkulator.php', 'kalkulator_tygodniowy_corrected.php', 'kalkulator_tygodniowy_updated.php']) ? 'active' : '' ?>">Mediaplany / kalkulator</a>
                    <a href="pipeline.php" class="nav-link <?= is_active('pipeline.php') ? 'active' : '' ?>">Pipeline</a>
                </div>
            </div>

            <div class="nav-group">
                <div class="nav-group-title">Realizacja</div>
                <div class="nav-group-items">
                    <a href="briefy.php" class="nav-link <?= is_active('briefy.php') ? 'active' : '' ?>">Briefy</a>
                    <a href="spoty.php" class="nav-link <?= is_active_any(['spoty.php', 'add_spot.php', 'edytuj_spot.php', 'kampania_audio.php']) ? 'active' : '' ?>">Produkcja audio / spoty</a>
                    <a href="podglad_pasma.php" class="nav-link <?= is_active_any(['podglad_pasma.php', 'symulacja_emisji.php']) ? 'active' : '' ?>">Emisje</a>
                </div>
            </div>

            <div class="nav-group">
                <div class="nav-group-title">Raporty</div>
                <div class="nav-group-items">
                    <a href="raport_emisji.php" class="nav-link <?= is_active_any(['raport_emisji.php']) ? 'active' : '' ?>">Raport emisji</a>
                    <a href="raport_cele.php" class="nav-link <?= is_active_any(['raport_cele.php']) ? 'active' : '' ?>">Raport celów</a>
                </div>
            </div>

            <div class="nav-group">
                <div class="nav-group-title">Komunikacja</div>
                <div class="nav-group-items">
                    <a href="skrzynka.php" class="nav-link <?= is_active_any(['skrzynka.php']) ? 'active' : '' ?>">Skrzynka</a>
                </div>
            </div>

            <?php if ($canManageSystem): ?>
                <div class="nav-group">
                    <div class="nav-group-title">Ustawienia</div>
                    <div class="nav-group-items">
                        <a href="ustawienia.php" class="nav-link <?= is_active('ustawienia.php') ? 'active' : '' ?>">Ustawienia globalne</a>
                    </div>
                </div>

                <div class="nav-group">
                    <div class="nav-group-title">Zespół i uprawnienia</div>
                    <div class="nav-group-items">
                        <a href="uzytkownicy.php" class="nav-link <?= $teamSectionActive ? 'active' : '' ?>">Użytkownicy i role</a>
                    </div>
                </div>

                <div class="nav-group">
                    <div class="nav-group-title">Oferta i rozliczenia</div>
                    <div class="nav-group-items">
                        <a href="cenniki.php" class="nav-link <?= is_active('cenniki.php') ? 'active' : '' ?>">Cenniki oferty</a>
                        <a href="cele.php" class="nav-link <?= is_active('cele.php') ? 'active' : '' ?>">Cele sprzedażowe</a>
                        <a href="prowizje.php" class="nav-link <?= is_active('prowizje.php') ? 'active' : '' ?>">Prowizje handlowców</a>
                    </div>
                </div>
            <?php endif; ?>

            <?php if ($canSeeAdministration): ?>
                <div class="nav-group">
                    <div class="nav-group-title">Administracja techniczna</div>
                    <div class="nav-group-items">
                        <?php if ($canManageSystem): ?>
                            <a href="admin/index.php" class="nav-link <?= is_active_path('admin/index.php') ? 'active' : '' ?>">Panel narzędzi technicznych</a>
                        <?php endif; ?>
                        <?php if ($canManageUpdates): ?>
                            <a href="admin/updates.php" class="nav-link <?= is_active_path('admin/updates.php') ? 'active' : '' ?>">Aktualizacje systemu</a>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endif; ?>
        </aside>

        <!-- START: app-content -->
        <div class="app-content">
            <div class="content-inner">
