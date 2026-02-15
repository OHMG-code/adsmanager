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
$clientSectionPages = ['klienci.php', 'dodaj_klienta.php', 'lista_klientow.php', 'edytuj_klienta.php', 'klient_szczegoly.php', 'klient_edytuj.php'];
$clientSectionOpen = in_array($current, $clientSectionPages, true);
$leadSectionPages = ['leady.php', 'lead.php', 'dodaj_lead.php', 'pipeline.php', 'followup.php', 'lead_szczegoly.php', 'lead_edytuj.php'];
$leadSectionOpen = in_array($current, $leadSectionPages, true);

$notificationCount = 0;
if ($pdoReady && !empty($_SESSION['user_id'])) {
    try {
        ensureLeadColumns($pdo);
        ensureNotificationsTable($pdo);
        $leadCols = getTableColumns($pdo, 'leady');
        $userId = (int)$_SESSION['user_id'];
        if ($userId > 0 && hasColumn($leadCols, 'next_action_at')) {
            $excludeStatuses = [
                'Wygrana',
                'Przegrana',
                'skonwertowany',
                'odrzucony',
                'zakonczony',
            ];
            $placeholders = implode(',', array_fill(0, count($excludeStatuses), '?'));
            $sql = "SELECT id, nazwa_firmy, next_action, next_action_at
                FROM leady
                WHERE owner_user_id = ?
                  AND next_action_at IS NOT NULL
                  AND next_action_at <= CONCAT(CURDATE(), ' 23:59:59')
                  AND status NOT IN ($placeholders)";
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

function get_action() {
    return $_GET['action'] ?? '';
}

$action = get_action();

$currentUser = $pdoReady ? getCurrentUser($pdo) : null;
$roleNormalized = $currentUser ? normalizeRole($currentUser) : '';
$isManagerView = in_array($roleNormalized, ['Manager', 'Administrator'], true) || isAdminOverride($currentUser);
$canManageSystem = can('manage_system');
$taskOverdueCount = 0;
if ($pdoReady && $currentUser) {
    $taskOverdueCount = countOverdueTasks((int)$currentUser['id'], $roleNormalized);
}
?>
<!DOCTYPE html>
<html lang="pl">
<head>
    <meta charset="UTF-8">
    <title><?= htmlspecialchars($pageTitle) ?> | Project Manager</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <!-- Bootstrap -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="<?= htmlspecialchars(BASE_URL) ?>/assets/css/style.css" rel="stylesheet">
    <link href="<?= htmlspecialchars(BASE_URL) ?>/assets/css/ui.css" rel="stylesheet">
</head>

<body>
<div class="css-loaded-test">UI CSS loaded</div>
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
            <a href="zadania.php?filter=overdue" class="btn btn-sm btn-topbar-action position-relative" aria-label="Zadania">
                &#128276;
                <?php if ($taskOverdueCount > 0): ?>
                    <span class="position-absolute top-0 start-100 translate-middle badge rounded-pill bg-danger">
                        <?= $taskOverdueCount ?>
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
                <div class="mt-2">
                    <a href="followup.php" class="btn btn-sm btn-ghost position-relative">
                        🔔
                        <?php if ($notificationCount > 0): ?>
                            <span class="position-absolute top-0 start-100 translate-middle badge rounded-pill bg-danger">
                                <?= $notificationCount ?>
                            </span>
                        <?php endif; ?>
                    </a>
                </div>
            </div>

            <div class="nav-section">
                <a href="dashboard.php" class="nav-link <?= is_active('dashboard.php') ? 'active' : '' ?>">🏠 Dashboard</a>
            </div>
            <div class="nav-section">
                <a href="skrzynka.php" class="nav-link <?= is_active('skrzynka.php') ? 'active' : '' ?>">Skrzynka</a>
            </div>

            <div class="nav-section <?= $clientSectionOpen ? 'is-open' : '' ?>" data-section="klienci">
                <button class="nav-section-header" type="button">
                    <span>👥 Klienci</span>
                    <span class="chevron">▾</span>
                </button>
                <div class="nav-section-items">
                    <a href="klienci.php" class="nav-link <?= $current === 'klienci.php' ? 'active' : '' ?>">📊 Przegląd</a>
                    <a href="dodaj_klienta.php" class="nav-link <?= $current === 'dodaj_klienta.php' ? 'active' : '' ?>">➕ Dodaj klienta</a>
                    <a href="lista_klientow.php" class="nav-link <?= $current === 'lista_klientow.php' ? 'active' : '' ?>">📋 Lista</a>
                </div>
            </div>

            <div class="nav-section <?= $leadSectionOpen ? 'is-open' : '' ?>" data-section="leady">
                <button class="nav-section-header" type="button">
                    <span>🎯 Leady</span>
                    <span class="chevron">▾</span>
                </button>
                <div class="nav-section-items">
                    <a href="leady.php" class="nav-link <?= $current === 'leady.php' ? 'active' : '' ?>">📊 Przegląd</a>
                    <a href="lead.php" class="nav-link <?= $current === 'lead.php' ? 'active' : '' ?>">📋 Lista</a>
                    <a href="dodaj_lead.php" class="nav-link <?= $current === 'dodaj_lead.php' ? 'active' : '' ?>">➕ Dodaj lead</a>
                    <a href="pipeline.php" class="nav-link <?= $current === 'pipeline.php' ? 'active' : '' ?>">🧩 Pipeline</a>
                    <a href="followup.php" class="nav-link <?= $current === 'followup.php' ? 'active' : '' ?>">📅 Do kontaktu</a>
                    <a href="generator_leadow.php" class="nav-link <?= is_active('generator_leadow.php') ? 'active' : '' ?>">🌍 Generator leadów</a>
                </div>
            </div>

            <?php
            $kampanieFiles = ['kalkulator_tygodniowy.php','podglad_pasma.php','spoty.php','raport_emisji.php'];
            $kampanieOpen  = in_array($current, $kampanieFiles, true);
            ?>
            <div class="nav-section <?= $kampanieOpen ? 'is-open' : '' ?>" data-section="kampanie">
                <button class="nav-section-header" type="button">
                    <span>📢 Kampanie</span>
                    <span class="chevron">▾</span>
                </button>
                <div class="nav-section-items">
                    <a href="kalkulator_tygodniowy.php" class="nav-link <?= is_active('kalkulator_tygodniowy.php') ? 'active' : '' ?>">📆 Kalkulator</a>
                    <a href="podglad_pasma.php" class="nav-link <?= is_active('podglad_pasma.php') ? 'active' : '' ?>">📊 Podgląd pasma</a>
                    <a href="spoty.php" class="nav-link <?= is_active('spoty.php') ? 'active' : '' ?>">🎧 Spoty</a>
                    <a href="raport_emisji.php" class="nav-link <?= is_active('raport_emisji.php') ? 'active' : '' ?>">📄 Raport emisji</a>
                </div>
            </div>

            <?php
            $systemFiles = ['ustawienia.php','cenniki.php','uzytkownicy.php','logout.php'];
            $systemOpen  = in_array($current, $systemFiles, true);
            ?>
            <div class="nav-section <?= $systemOpen ? 'is-open' : '' ?>" data-section="system">
                <button class="nav-section-header" type="button">
                    <span>⚙️ System</span>
                    <span class="chevron">▾</span>
                </button>
                <div class="nav-section-items">
                    <a href="cenniki.php" class="nav-link <?= is_active('cenniki.php') ? 'active' : '' ?>">💰 Cenniki</a>
                    <?php if ($canManageSystem): ?>
                        <a href="uzytkownicy.php" class="nav-link <?= is_active('uzytkownicy.php') ? 'active' : '' ?>">👤 Użytkownicy</a>
                        <a href="ustawienia.php" class="nav-link <?= is_active('ustawienia.php') ? 'active' : '' ?>">🔧 Ustawienia</a>
                    <?php endif; ?>
                </div>
            </div>

            <?php if ($isManagerView): ?>
                <?php
                $managerFiles = ['dashboard_manager.php','cele.php','raport_cele.php','prowizje.php'];
                $managerOpen  = in_array($current, $managerFiles, true);
                ?>
                <div class="nav-section <?= $managerOpen ? 'is-open' : '' ?>" data-section="manager">
                    <button class="nav-section-header" type="button">
                        <span>📈 Manager</span>
                        <span class="chevron">▾</span>
                    </button>
                    <div class="nav-section-items">
                        <a href="dashboard_manager.php" class="nav-link <?= is_active('dashboard_manager.php') ? 'active' : '' ?>">📊 Dashboard Managera</a>
                        <a href="cele.php" class="nav-link <?= is_active('cele.php') ? 'active' : '' ?>">🎯 Cele sprzedażowe</a>
                        <a href="raport_cele.php" class="nav-link <?= is_active('raport_cele.php') ? 'active' : '' ?>">📄 Raport celów</a>
                        <a href="prowizje.php" class="nav-link <?= is_active('prowizje.php') ? 'active' : '' ?>">💸 Prowizje</a>
                    </div>
                </div>
            <?php endif; ?>
        </aside>

        <!-- START: app-content -->
        <main class="app-content">
            <div class="content-inner">
