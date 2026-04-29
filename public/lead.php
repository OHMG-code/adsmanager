<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/db_schema.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/ui_helpers.php';
require_once __DIR__ . '/includes/lead_pipeline_helpers.php';
require_once __DIR__ . '/includes/crm_activity.php';
require_once __DIR__ . '/includes/nip_guard.php';
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/handlers/client_save.php';

$pageTitle = "Leady";
$pageStyles = ['leads'];

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$leadStatuses = leadStatusOptions();

$leadStatusBadges = [
    'nowy'            => 'bg-secondary',
    'w_kontakcie'     => 'bg-info text-dark',
    'analiza_potrzeb' => 'bg-primary',
    'oferta_przygotowywana' => 'bg-primary',
    'oferta_wyslana'  => 'bg-warning text-dark',
    'oferta_zaakceptowana' => 'bg-success',
    'wygrana'         => 'bg-success',
    'odrzucony'       => 'bg-danger',
    'zakonczony'      => 'bg-dark',
    'skonwertowany'   => 'bg-success',
];

$leadPriorities = [
    'Niski' => 'Niski',
    'Średni' => 'Średni',
    'Wysoki' => 'Wysoki',
    'Pilny' => 'Pilny',
];

$leadSources = [
    'telefon'        => 'Telefon',
    'email'          => 'Email',
    'formularz_www'  => 'Formularz WWW',
    'maps_api'       => 'Google Maps / API',
    'polecenie'      => 'Polecenie',
    'inne'           => 'Inne',
];

$currentUser = fetchCurrentUser($pdo);
if (!$currentUser) {
    header('Location: ' . BASE_URL . '/logout.php');
    exit;
}
$currentRole = normalizeRole($currentUser);

$leadEditId = isset($_GET['lead_edit_id']) ? (int)$_GET['lead_edit_id'] : null;

$flashMessage = $_SESSION['flash'] ?? null;
$flashIsRaw = false;
$flashText = '';
if ($flashMessage) {
    $flashIsRaw = !empty($flashMessage['raw']);
    $flashText = $flashMessage['message'] ?? '';
    unset($_SESSION['flash']);
}

function sanitizeLeadReturnUrl(?string $candidate): string
{
    $default = 'lead.php';
    $allowed = ['lead.php', 'dodaj_lead.php', 'leady.php'];
    if (!$candidate) {
        return $default;
    }

    $candidate = trim($candidate);
    if ($candidate === '' || stripos($candidate, 'http') === 0) {
        return $default;
    }

    $normalized = ltrim($candidate, '/');
    foreach ($allowed as $path) {
        if (strpos($normalized, $path) === 0) {
            return $normalized;
        }
    }

    return $default;
}

function ensureLeadInfrastructure(PDO $pdo): void
{
    static $initialized = false;
    if ($initialized) {
        return;
    }
    ensureLeadColumns($pdo);
    ensureLeadActivityTable($pdo);
    ensureClientLeadColumns($pdo);
    ensureKampanieOwnershipColumns($pdo);
    ensureCompaniesTable($pdo);
    ensureIndexExists($pdo, 'leady', 'idx_leady_status', 'CREATE INDEX idx_leady_status ON leady(status)');
    ensureIndexExists($pdo, 'leady', 'idx_leady_zrodlo', 'CREATE INDEX idx_leady_zrodlo ON leady(zrodlo)');
    ensureIndexExists($pdo, 'leady', 'idx_leady_nip', 'CREATE INDEX idx_leady_nip ON leady(nip)');
    ensureIndexExists($pdo, 'leady', 'idx_leady_created_at', 'CREATE INDEX idx_leady_created_at ON leady(created_at)');

    $initialized = true;
}

function normalizePriority(?string $priority, array $leadPriorities): string
{
    $priority = trim((string)$priority);
    return $leadPriorities[$priority] ?? 'Średni';
}

function normalizeDateTimeInput(?string $input): ?string
{
    $input = trim((string)$input);
    if ($input === '') {
        return null;
    }
    $formats = ['Y-m-d\\TH:i', 'Y-m-d H:i:s', 'Y-m-d H:i'];
    foreach ($formats as $format) {
        $dt = DateTime::createFromFormat($format, $input);
        if ($dt) {
            return $dt->format('Y-m-d H:i:s');
        }
    }
    return null;
}

function formatDateTimeForInput(?string $value): string
{
    if (!$value) {
        return '';
    }
    try {
        $dt = new DateTime($value);
        return $dt->format('Y-m-d\\TH:i');
    } catch (Throwable $e) {
        return '';
    }
}

function collectLeadPayload(array $input, array $leadSources, array $leadStatuses, array $leadPriorities): array
{
    $nazwa = trim($input['nazwa_firmy'] ?? '');
    $nip = trim($input['nip'] ?? '');
    $telefon = trim($input['telefon'] ?? '');
    $email = trim($input['email'] ?? '');
    $handlowiec = trim($input['przypisany_handlowiec'] ?? '');
    $notatki = trim($input['notatki'] ?? '');
    $nextActionDate = trim($input['next_action_date'] ?? '');
    $nextAction = trim($input['next_action'] ?? '');
    $nextActionAt = normalizeDateTimeInput($input['next_action_at'] ?? '');
    $priority = normalizePriority($input['priority'] ?? '', $leadPriorities);
    $zrodlo = $input['zrodlo'] ?? 'inne';
    $status = normalizeLeadStatus((string)($input['status'] ?? 'nowy'));

    if (!array_key_exists($zrodlo, $leadSources)) {
        $zrodlo = 'inne';
    }
    if (!array_key_exists($status, $leadStatuses)) {
        $status = 'nowy';
    }

    $nextActionDateValue = null;
    if ($nextActionAt) {
        $nextActionDateValue = substr($nextActionAt, 0, 10);
    } elseif ($nextActionDate !== '') {
        $nextActionDateValue = $nextActionDate;
    }

    $payload = [
        'nazwa_firmy'            => $nazwa,
        'nip'                    => $nip !== '' ? $nip : null,
        'telefon'                => $telefon !== '' ? $telefon : null,
        'email'                  => $email !== '' ? $email : null,
        'zrodlo'                 => $zrodlo,
        'przypisany_handlowiec'  => $handlowiec !== '' ? $handlowiec : null,
        'status'                 => $status,
        'notatki'                => $notatki !== '' ? $notatki : null,
        'next_action_date'       => $nextActionDateValue,
        'priority'               => $priority,
        'next_action'            => $nextAction !== '' ? $nextAction : null,
        'next_action_at'         => $nextActionAt,
    ];

    foreach (['kod_pocztowy', 'miasto', 'ulica', 'nr_budynku', 'nr_lokalu'] as $field) {
        if (!array_key_exists($field, $input)) {
            continue;
        }
        $value = trim((string)$input[$field]);
        $payload[$field] = $value !== '' ? $value : null;
    }

    return $payload;
}

function buildLeadWriteData(
    array $payload,
    array $columns,
    ?int $ownerId,
    bool $forceOwnerWrite = false,
    ?string $ownerDisplayName = null
): array
{
    $data = [
        'nazwa_firmy' => $payload['nazwa_firmy'],
        'nip' => $payload['nip'],
        'telefon' => $payload['telefon'],
        'email' => $payload['email'],
        'zrodlo' => $payload['zrodlo'],
        'przypisany_handlowiec' => $payload['przypisany_handlowiec'],
        'status' => $payload['status'],
        'notatki' => $payload['notatki'],
    ];

    if (hasColumn($columns, 'next_action_date')) {
        $data['next_action_date'] = $payload['next_action_date'];
    }
    if (hasColumn($columns, 'priority')) {
        $data['priority'] = $payload['priority'];
    }
    if (hasColumn($columns, 'next_action')) {
        $data['next_action'] = $payload['next_action'];
    }
    if (hasColumn($columns, 'next_action_at')) {
        $data['next_action_at'] = $payload['next_action_at'];
    }
    foreach (['kod_pocztowy', 'miasto', 'ulica', 'nr_budynku', 'nr_lokalu'] as $field) {
        if (hasColumn($columns, $field) && array_key_exists($field, $payload)) {
            $data[$field] = $payload[$field];
        }
    }
    if (hasColumn($columns, 'owner_user_id') && ($forceOwnerWrite || $ownerId !== null)) {
        $data['owner_user_id'] = $ownerId;
    }
    if (hasColumn($columns, 'assigned_user_id') && ($forceOwnerWrite || $ownerId !== null)) {
        $data['assigned_user_id'] = $ownerId;
    }
    if (hasColumn($columns, 'przypisany_handlowiec') && hasColumn($columns, 'owner_user_id') && ($forceOwnerWrite || $ownerId !== null)) {
        $data['przypisany_handlowiec'] = $ownerDisplayName;
    }

    return $data;
}

function resolveLeadOwnerId(
    array $input,
    array $columns,
    array $assignableUsers,
    array $currentUser,
    string $currentRole,
    ?array $existingLead = null
): array {
    if (!hasColumn($columns, 'owner_user_id')) {
        return ['ok' => true, 'owner_id' => null, 'error' => ''];
    }

    $ownerRaw = array_key_exists('owner_user_id', $input) ? (int)$input['owner_user_id'] : null;
    if ($ownerRaw === null) {
        if ($existingLead !== null) {
            $ownerRaw = (int)($existingLead['owner_user_id'] ?? 0);
        } else {
            $ownerRaw = (int)($currentUser['id'] ?? 0);
        }
    }

    if ($currentRole === 'Handlowiec') {
        $currentUserId = (int)($currentUser['id'] ?? 0);
        $currentOwnerId = $existingLead !== null ? (int)($existingLead['owner_user_id'] ?? 0) : 0;
        if ($existingLead !== null && $currentOwnerId > 0 && $currentOwnerId !== $currentUserId) {
            return [
                'ok' => false,
                'owner_id' => null,
                'error' => 'Nie można przypisywać sobie cudzych leadów.',
            ];
        }
        if ($ownerRaw > 0 && !isset($assignableUsers[$ownerRaw])) {
            return ['ok' => false, 'owner_id' => null, 'error' => 'Wybrana osoba odpowiedzialna nie jest dostępna.'];
        }
        return ['ok' => true, 'owner_id' => $ownerRaw > 0 ? $ownerRaw : null, 'error' => ''];
    }

    if ($ownerRaw > 0 && !isset($assignableUsers[$ownerRaw])) {
        return ['ok' => false, 'owner_id' => null, 'error' => 'Wybrana osoba odpowiedzialna nie jest dostępna.'];
    }

    return ['ok' => true, 'owner_id' => $ownerRaw > 0 ? $ownerRaw : null, 'error' => ''];
}

function renderLeadStatusBadge(string $status, array $labels, array $badgeMap): string
{
    $normalized = normalizeLeadStatus($status);
    $label = $labels[$normalized] ?? leadStatusLabel($normalized);
    return renderBadge('lead_status', $label);
}

function renderNextActionBadge(?string $date): string
{
    if (!$date) {
        return '<span class="badge badge-neutral">Brak</span>';
    }

    try {
        $actionDate = new DateTime($date);
    } catch (Throwable $e) {
        return '<span class="badge badge-neutral">' . htmlspecialchars($date) . '</span>';
    }

    $today = new DateTimeImmutable('today');
    if ($actionDate < $today) {
        $class = 'badge-danger';
    } elseif ($actionDate->format('Y-m-d') === $today->format('Y-m-d')) {
        $class = 'badge-warning';
    } else {
        $class = 'badge-success';
    }

    return '<span class="badge ' . $class . '">' . $actionDate->format('d.m.Y H:i') . '</span>';
}

function renderPriorityBadge(?string $priority): string
{
    $priority = $priority ?: 'Średni';
    return renderBadge('priority', $priority);
}

function userDisplayName(array $user): string
{
    $full = trim(($user['imie'] ?? '') . ' ' . ($user['nazwisko'] ?? ''));
    if ($full !== '') {
        return $full;
    }
    return $user['login'] ?? ('User #' . ($user['id'] ?? ''));
}

function fetchAssignableUsers(PDO $pdo): array
{
    ensureUserColumns($pdo);
    $stmt = $pdo->query("SELECT id, login, imie, nazwisko, rola, aktywny FROM uzytkownicy");
    $users = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
    $result = [];
    foreach ($users as $user) {
        if (isset($user['aktywny']) && (int)$user['aktywny'] === 0) {
            continue;
        }
        $role = normalizeRole($user);
        if (!in_array($role, ['Handlowiec', 'Manager', 'Administrator'], true)) {
            continue;
        }
        $user['rola'] = $role;
        $result[(int)$user['id']] = $user;
    }
    return $result;
}

function buildOwnerMap(PDO $pdo, array $ownerIds): array
{
    if (!$ownerIds) {
        return [];
    }
    ensureUserColumns($pdo);
    $placeholders = implode(',', array_fill(0, count($ownerIds), '?'));
    $stmt = $pdo->prepare("SELECT id, login, imie, nazwisko, rola FROM uzytkownicy WHERE id IN ($placeholders)");
    $stmt->execute(array_values($ownerIds));
    $map = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $user) {
        $map[(int)$user['id']] = userDisplayName($user);
    }
    return $map;
}

function logLeadActivity(PDO $pdo, int $leadId, int $userId, string $type, ?string $description): void
{
    try {
        $stmt = $pdo->prepare("INSERT INTO leady_aktywnosci (lead_id, user_id, typ, opis) VALUES (:lead_id, :user_id, :typ, :opis)");
        $stmt->execute([
            ':lead_id' => $leadId,
            ':user_id' => $userId,
            ':typ' => $type,
            ':opis' => $description,
        ]);
    } catch (Throwable $e) {
        error_log('Lead activity log error: ' . $e->getMessage());
    }
}

function applyOfferFollowUp(PDO $pdo, int $leadId, int $userId): void
{
    $columns = getTableColumns($pdo, 'leady');
    if (!hasColumn($columns, 'next_action') || !hasColumn($columns, 'next_action_at')) {
        return;
    }

    $stmt = $pdo->prepare("SELECT next_action, next_action_at FROM leady WHERE id = :id");
    $stmt->execute([':id' => $leadId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
    if (!empty($row['next_action_at'])) {
        return;
    }

    $setParts = ['next_action = :next_action', 'next_action_at = DATE_ADD(NOW(), INTERVAL 2 DAY)'];
    if (hasColumn($columns, 'next_action_date')) {
        $setParts[] = 'next_action_date = DATE(DATE_ADD(NOW(), INTERVAL 2 DAY))';
    }
    $pdo->prepare("UPDATE leady SET " . implode(', ', $setParts) . " WHERE id = :id")
        ->execute([':next_action' => 'Follow-up po ofercie', ':id' => $leadId]);

    try {
        $check = $pdo->prepare("SELECT id FROM leady_aktywnosci WHERE lead_id = :lead_id AND typ = 'offer_sent' AND created_at >= DATE_SUB(NOW(), INTERVAL 1 MINUTE) LIMIT 1");
        $check->execute([':lead_id' => $leadId]);
        if (!$check->fetchColumn()) {
            logLeadActivity($pdo, $leadId, $userId, 'offer_sent', 'Oferta wysłana (system)');
        }
    } catch (Throwable $e) {
        error_log('Lead follow-up log error: ' . $e->getMessage());
    }
}

function fetchLeadForAction(PDO $pdo, int $leadId, array $columns, array $currentUser): ?array
{
    $conditions = ['id = :id'];
    $params = [':id' => $leadId];
    [$ownerClause, $ownerParams] = ownerConstraint($columns, $currentUser, '', true);
    if ($ownerClause !== '') {
        $conditions[] = $ownerClause;
        $params = array_merge($params, $ownerParams);
    }

    $stmt = $pdo->prepare('SELECT * FROM leady WHERE ' . implode(' AND ', $conditions) . ' LIMIT 1');
    $stmt->execute($params);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

function buildVisibleLeadConditions(array $columns): array
{
    return buildActiveLeadConditions($columns);
}

$leadAction = $_POST['lead_action'] ?? null;
if ($leadAction) {
    $redirectTarget = sanitizeLeadReturnUrl($_POST['return_url'] ?? '');
    $redirectLocation = BASE_URL . '/' . ltrim($redirectTarget, '/');
    $token = $_POST['csrf_token'] ?? '';
    if (!hash_equals($_SESSION['csrf_token'], $token)) {
        $_SESSION['flash'] = ['type' => 'danger', 'message' => 'Niepoprawny token CSRF.'];
        header('Location: ' . $redirectLocation);
        exit;
    }

    ensureLeadInfrastructure($pdo);
    $leadColumns = getTableColumns($pdo, 'leady');

    try {
        switch ($leadAction) {
            case 'create':
            case 'quick_create':
                $payload = collectLeadPayload($_POST, $leadSources, $leadStatuses, $leadPriorities);
                if ($payload['nazwa_firmy'] === '') {
                    $_SESSION['flash'] = ['type' => 'warning', 'message' => 'Nazwa firmy jest wymagana.'];
                    break;
                }
                $nipRawProvided = isRawNipGuardInputProvided($_POST['nip'] ?? null);
                $nipNormalized = normalizeNipGuard((string)($payload['nip'] ?? ''));
                if ($nipRawProvided && !isNormalizedNipGuardValid($nipNormalized)) {
                    $_SESSION['flash'] = ['type' => 'warning', 'message' => 'Podaj poprawny numer NIP (10 cyfr).'];
                    break;
                }
                $payload['nip'] = $nipNormalized !== '' ? $nipNormalized : null;
                if (!empty($payload['nip'])) {
                    $dupLead = findLeadByNipGuard($pdo, (string)$payload['nip']);
                    if ($dupLead) {
                        $_SESSION['flash'] = [
                            'type' => 'warning',
                            'message' => 'Lead z tym numerem NIP już istnieje (ID: ' . (int)$dupLead['id'] . ').',
                        ];
                        break;
                    }
                    $dupClient = findClientByNipGuard($pdo, (string)$payload['nip']);
                    if ($dupClient) {
                        $_SESSION['flash'] = [
                            'type' => 'warning',
                            'message' => 'Klient z tym numerem NIP już istnieje (ID: ' . (int)$dupClient['id'] . ').',
                        ];
                        break;
                    }
                }
                $assignableUsers = fetchAssignableUsers($pdo);
                $ownerResult = resolveLeadOwnerId($_POST, $leadColumns, $assignableUsers, $currentUser, $currentRole);
                if (!$ownerResult['ok']) {
                    $_SESSION['flash'] = ['type' => 'danger', 'message' => $ownerResult['error']];
                    break;
                }
                $ownerId = $ownerResult['owner_id'];
                $ownerDisplayName = ($ownerId !== null && $ownerId > 0)
                    ? (isset($assignableUsers[$ownerId]) ? userDisplayName($assignableUsers[$ownerId]) : ('User #' . $ownerId))
                    : null;
                $writeData = buildLeadWriteData($payload, $leadColumns, $ownerId, false, $ownerDisplayName);
                $columns = array_keys($writeData);
                $placeholders = [];
                foreach ($columns as $col) {
                    $placeholders[] = ':' . $col;
                }
                $stmt = $pdo->prepare("INSERT INTO leady (" . implode(', ', $columns) . ") VALUES (" . implode(', ', $placeholders) . ")");
                $params = [];
                foreach ($writeData as $col => $value) {
                    $params[':' . $col] = $value;
                }
                $stmt->execute($params);
                if ($payload['status'] === 'oferta_wyslana') {
                    $leadId = (int)$pdo->lastInsertId();
                    if ($leadId > 0) {
                        applyOfferFollowUp($pdo, $leadId, (int)$currentUser['id']);
                    }
                }
                $_SESSION['flash'] = ['type' => 'success', 'message' => 'Lead zapisany pomyślnie.'];
                break;

            case 'update':
                $leadId = isset($_POST['lead_id']) ? (int)$_POST['lead_id'] : 0;
                if ($leadId <= 0) {
                    $_SESSION['flash'] = ['type' => 'danger', 'message' => 'Brak identyfikatora leada.'];
                    break;
                }
                $existingLead = fetchLeadForAction($pdo, $leadId, $leadColumns, $currentUser);
                if (!$existingLead) {
                    $_SESSION['flash'] = ['type' => 'danger', 'message' => 'Brak dostępu do leada.'];
                    break;
                }
                $payload = collectLeadPayload($_POST, $leadSources, $leadStatuses, $leadPriorities);
                if ($payload['nazwa_firmy'] === '') {
                    $_SESSION['flash'] = ['type' => 'warning', 'message' => 'Nazwa firmy jest wymagana.'];
                    break;
                }
                $nipRawProvided = isRawNipGuardInputProvided($_POST['nip'] ?? null);
                $nipNormalized = normalizeNipGuard((string)($payload['nip'] ?? ''));
                if ($nipRawProvided && !isNormalizedNipGuardValid($nipNormalized)) {
                    $_SESSION['flash'] = ['type' => 'warning', 'message' => 'Podaj poprawny numer NIP (10 cyfr).'];
                    break;
                }
                $payload['nip'] = $nipNormalized !== '' ? $nipNormalized : null;
                if (!empty($payload['nip'])) {
                    $dupLead = findLeadByNipGuard($pdo, (string)$payload['nip'], $leadId);
                    if ($dupLead) {
                        $_SESSION['flash'] = [
                            'type' => 'warning',
                            'message' => 'Inny lead z tym numerem NIP już istnieje (ID: ' . (int)$dupLead['id'] . ').',
                        ];
                        break;
                    }
                    $dupClient = findClientByNipGuard($pdo, (string)$payload['nip']);
                    $currentClientId = (int)($existingLead['client_id'] ?? 0);
                    if ($dupClient && $currentClientId !== (int)$dupClient['id']) {
                        $_SESSION['flash'] = [
                            'type' => 'warning',
                            'message' => 'Klient z tym numerem NIP już istnieje (ID: ' . (int)$dupClient['id'] . ').',
                        ];
                        break;
                    }
                }
                $assignableUsers = fetchAssignableUsers($pdo);
                $ownerResult = resolveLeadOwnerId($_POST, $leadColumns, $assignableUsers, $currentUser, $currentRole, $existingLead);
                if (!$ownerResult['ok']) {
                    $_SESSION['flash'] = ['type' => 'danger', 'message' => $ownerResult['error']];
                    break;
                }
                $ownerId = $ownerResult['owner_id'];
                $ownerDisplayName = ($ownerId !== null && $ownerId > 0)
                    ? (isset($assignableUsers[$ownerId]) ? userDisplayName($assignableUsers[$ownerId]) : ('User #' . $ownerId))
                    : null;
                $writeData = buildLeadWriteData($payload, $leadColumns, $ownerId, true, $ownerDisplayName);
                $setParts = [];
                $params = [':id' => $leadId];
                foreach ($writeData as $col => $value) {
                    $setParts[] = $col . ' = :' . $col;
                    $params[':' . $col] = $value;
                }
                $stmt = $pdo->prepare("UPDATE leady SET " . implode(', ', $setParts) . " WHERE id = :id");
                $stmt->execute($params);
                if ($payload['status'] === 'oferta_wyslana' && normalizeLeadStatus((string)($existingLead['status'] ?? '')) !== 'oferta_wyslana') {
                    applyOfferFollowUp($pdo, $leadId, (int)$currentUser['id']);
                }
                $_SESSION['flash'] = ['type' => 'success', 'message' => 'Lead został zaktualizowany.'];
                break;

            case 'crm_status':
                $leadId = isset($_POST['lead_id']) ? (int)$_POST['lead_id'] : 0;
                $statusId = isset($_POST['crm_status_id']) ? (int)$_POST['crm_status_id'] : 0;
                $comment = trim((string)($_POST['crm_status_comment'] ?? ''));
                if ($leadId <= 0 || $statusId <= 0) {
                    $_SESSION['flash'] = ['type' => 'warning', 'message' => 'Wybierz status/czynnosc i wskaz leada.'];
                    break;
                }
                $leadRow = fetchLeadForAction($pdo, $leadId, $leadColumns, $currentUser);
                if (!$leadRow) {
                    $_SESSION['flash'] = ['type' => 'danger', 'message' => 'Brak dostępu do leada.'];
                    break;
                }
                $availableStatuses = getStatusy('lead');
                $isValidStatus = false;
                foreach ($availableStatuses as $status) {
                    if ((int)($status['id'] ?? 0) === $statusId) {
                        $isValidStatus = true;
                        break;
                    }
                }
                if (!$isValidStatus) {
                    $_SESSION['flash'] = ['type' => 'danger', 'message' => 'Wybrany status nie jest dostępny.'];
                    break;
                }
                if (!addActivity('lead', $leadId, 'status', (int)$currentUser['id'], $comment, $statusId)) {
                    $_SESSION['flash'] = ['type' => 'danger', 'message' => 'Nie udało się zapisać statusu.'];
                    break;
                }
                $_SESSION['flash'] = ['type' => 'success', 'message' => 'Status/czynność zostały zapisane w osi aktywności.'];
                break;

            case 'crm_note':
                $leadId = isset($_POST['lead_id']) ? (int)$_POST['lead_id'] : 0;
                $note = trim((string)($_POST['crm_note'] ?? ''));
                if ($leadId <= 0) {
                    $_SESSION['flash'] = ['type' => 'warning', 'message' => 'Brak identyfikatora leada.'];
                    break;
                }
                if ($note === '') {
                    $_SESSION['flash'] = ['type' => 'warning', 'message' => 'Wpisz treść notatki.'];
                    break;
                }
                $leadRow = fetchLeadForAction($pdo, $leadId, $leadColumns, $currentUser);
                if (!$leadRow) {
                    $_SESSION['flash'] = ['type' => 'danger', 'message' => 'Brak dostępu do leada.'];
                    break;
                }
                if (!addActivity('lead', $leadId, 'notatka', (int)$currentUser['id'], $note)) {
                    $_SESSION['flash'] = ['type' => 'danger', 'message' => 'Nie udało się dodać notatki.'];
                    break;
                }
                $_SESSION['flash'] = ['type' => 'success', 'message' => 'Notatka została dodana do osi aktywności.'];
                break;

            case 'transfer':
                $leadId = isset($_POST['lead_id']) ? (int)$_POST['lead_id'] : 0;
                $newOwnerId = isset($_POST['new_owner_id']) ? (int)$_POST['new_owner_id'] : 0;
                $reason = trim($_POST['transfer_reason'] ?? '');
                if ($leadId <= 0 || $newOwnerId <= 0) {
                    $_SESSION['flash'] = ['type' => 'danger', 'message' => 'Niepoprawne dane przekazania.'];
                    break;
                }
                if ($reason === '') {
                    $_SESSION['flash'] = ['type' => 'warning', 'message' => 'Powód przekazania jest wymagany.'];
                    break;
                }
                if (!hasColumn($leadColumns, 'owner_user_id')) {
                    $_SESSION['flash'] = ['type' => 'danger', 'message' => 'Brak kolumny owner_user_id w bazie.'];
                    break;
                }
                $existingLead = fetchLeadForAction($pdo, $leadId, $leadColumns, $currentUser);
                if (!$existingLead) {
                    $_SESSION['flash'] = ['type' => 'danger', 'message' => 'Brak dostępu do leada.'];
                    break;
                }
                $assignableUsers = fetchAssignableUsers($pdo);
                if (!isset($assignableUsers[$newOwnerId])) {
                    $_SESSION['flash'] = ['type' => 'danger', 'message' => 'Wybrany użytkownik nie jest dostępny.'];
                    break;
                }

                $oldOwnerId = (int)($existingLead['owner_user_id'] ?? 0);
                $setParts = ['owner_user_id = :owner_user_id'];
                $params = [':owner_user_id' => $newOwnerId, ':id' => $leadId];
                if (hasColumn($leadColumns, 'assigned_user_id')) {
                    $setParts[] = 'assigned_user_id = :assigned_user_id';
                    $params[':assigned_user_id'] = $newOwnerId;
                }
                if (hasColumn($leadColumns, 'przypisany_handlowiec')) {
                    $setParts[] = 'przypisany_handlowiec = :przypisany_handlowiec';
                    $params[':przypisany_handlowiec'] = userDisplayName($assignableUsers[$newOwnerId]);
                }
                $stmt = $pdo->prepare("UPDATE leady SET " . implode(', ', $setParts) . " WHERE id = :id");
                $stmt->execute($params);

                $ownerMap = buildOwnerMap($pdo, array_filter([$oldOwnerId, $newOwnerId]));
                $oldOwnerName = $oldOwnerId ? ($ownerMap[$oldOwnerId] ?? ('User #' . $oldOwnerId)) : 'Nieprzypisany';
                $newOwnerName = $ownerMap[$newOwnerId] ?? ('User #' . $newOwnerId);
                $description = sprintf('Przekazano: %s -> %s. Powód: %s', $oldOwnerName, $newOwnerName, $reason);
                logLeadActivity($pdo, $leadId, (int)$currentUser['id'], 'transfer', $description);

                $_SESSION['flash'] = ['type' => 'success', 'message' => 'Lead został przekazany.'];
                break;

            case 'delete':
                $leadId = isset($_POST['lead_id']) ? (int)$_POST['lead_id'] : 0;
                if ($leadId <= 0) {
                    $_SESSION['flash'] = ['type' => 'danger', 'message' => 'Niepoprawny lead.'];
                    break;
                }
                $existingLead = fetchLeadForAction($pdo, $leadId, $leadColumns, $currentUser);
                if (!$existingLead) {
                    $_SESSION['flash'] = ['type' => 'danger', 'message' => 'Brak dostępu do leada.'];
                    break;
                }
                $stmt = $pdo->prepare("DELETE FROM leady WHERE id = :id");
                $stmt->execute([':id' => $leadId]);
                $_SESSION['flash'] = ['type' => 'success', 'message' => 'Lead został usunięty.'];
                break;

            case 'convert':
                $leadId = isset($_POST['lead_id']) ? (int)$_POST['lead_id'] : 0;
                if ($leadId <= 0) {
                    $_SESSION['flash'] = ['type' => 'danger', 'message' => 'Nie znaleziono leada.'];
                    break;
                }
                $existingLead = fetchLeadForAction($pdo, $leadId, $leadColumns, $currentUser);
                if (!$existingLead) {
                    $_SESSION['flash'] = ['type' => 'danger', 'message' => 'Brak dostępu do leada.'];
                    break;
                }
                if (!tableExists($pdo, 'klienci')) {
                    $_SESSION['flash'] = ['type' => 'danger', 'message' => 'Brak tabeli klientów.'];
                    break;
                }
                if (!empty($existingLead['client_id'])) {
                    $_SESSION['flash'] = ['type' => 'info', 'message' => 'Ten lead jest już skonwertowany na klienta.'];
                    break;
                }

                $pdo->beginTransaction();
                $stmt = $pdo->prepare("SELECT * FROM leady WHERE id = :id FOR UPDATE");
                $stmt->execute([':id' => $leadId]);
                $leadRow = $stmt->fetch(PDO::FETCH_ASSOC);
                if (!$leadRow) {
                    if ($pdo->inTransaction()) {
                        $pdo->rollBack();
                    }
                    $_SESSION['flash'] = ['type' => 'danger', 'message' => 'Lead nie istnieje.'];
                    break;
                }

                $clientSave = saveClientFromLeadConversion(
                    $pdo,
                    $leadRow,
                    $_POST,
                    (int)($currentUser['id'] ?? 0),
                    ['source_lead_id' => $leadId]
                );
                if (!$clientSave['ok']) {
                    if ($pdo->inTransaction()) {
                        $pdo->rollBack();
                    }
                    $message = $clientSave['errors'] ? implode(', ', $clientSave['errors']) : 'Nie udało się utworzyć klienta.';
                    $_SESSION['flash'] = ['type' => 'danger', 'message' => $message];
                    break;
                }
                $newClientId = (int)$clientSave['client_id'];

                $convertSetParts = [
                    'client_id = :client_id',
                    'converted_at = NOW()',
                    'converted_by_user_id = :user_id',
                ];
                if (hasColumn($leadColumns, 'status')) {
                    $convertSetParts[] = 'status = :status';
                }
                if (hasColumn($leadColumns, 'next_action')) {
                    $convertSetParts[] = 'next_action = NULL';
                }
                if (hasColumn($leadColumns, 'next_action_at')) {
                    $convertSetParts[] = 'next_action_at = NULL';
                }
                if (hasColumn($leadColumns, 'next_action_date')) {
                    $convertSetParts[] = 'next_action_date = NULL';
                }
                $updateLead = $pdo->prepare('UPDATE leady SET ' . implode(', ', $convertSetParts) . ' WHERE id = :id');
                $convertParams = [
                    ':client_id' => $newClientId,
                    ':user_id' => (int)$currentUser['id'],
                    ':id' => $leadId,
                ];
                if (hasColumn($leadColumns, 'status')) {
                    $convertParams[':status'] = 'skonwertowany';
                }
                $updateLead->execute($convertParams);

                if (tableExists($pdo, 'kampanie')) {
                    $kampanieCols = getTableColumns($pdo, 'kampanie');
                    if (hasColumn($kampanieCols, 'source_lead_id') && hasColumn($kampanieCols, 'klient_id')) {
                        $setParts = ['klient_id = :client_id'];
                        $linkParams = [
                            ':client_id' => $newClientId,
                            ':lead_id' => $leadId,
                        ];
                        if (hasColumn($kampanieCols, 'klient_nazwa')) {
                            $stmtClientName = $pdo->prepare('SELECT nazwa_firmy FROM klienci WHERE id = :id LIMIT 1');
                            $stmtClientName->execute([':id' => $newClientId]);
                            $clientName = trim((string)($stmtClientName->fetchColumn() ?: ''));
                            if ($clientName !== '') {
                                $setParts[] = 'klient_nazwa = :klient_nazwa';
                                $linkParams[':klient_nazwa'] = $clientName;
                            }
                        }
                        $stmtLinkCampaigns = $pdo->prepare(
                            'UPDATE kampanie SET ' . implode(', ', $setParts)
                            . ' WHERE source_lead_id = :lead_id AND (klient_id IS NULL OR klient_id = 0)'
                        );
                        $stmtLinkCampaigns->execute($linkParams);
                    }
                }

                logLeadActivity($pdo, $leadId, (int)$currentUser['id'], 'converted', 'Skonwertowano na klienta ID=' . $newClientId);
                if ($pdo->inTransaction()) {
                    $pdo->commit();
                }

                $link = 'edytuj_klienta.php?id=' . $newClientId;
                $linkedExistingClient = in_array('existing_client_linked_by_nip', $clientSave['warnings'] ?? [], true);
                $_SESSION['flash'] = [
                    'type' => 'success',
                    'message' => ($linkedExistingClient
                        ? 'Lead został powiązany z istniejącym klientem po NIP. '
                        : 'Lead skonwertowany na klienta. ')
                        . '<a href="' . htmlspecialchars($link) . '" class="alert-link">Przejdź do klienta</a>.',
                    'raw' => true,
                ];
                break;

            case 'unconvert':
                $leadId = isset($_POST['lead_id']) ? (int)$_POST['lead_id'] : 0;
                if ($leadId <= 0) {
                    $_SESSION['flash'] = ['type' => 'danger', 'message' => 'Nie znaleziono leada.'];
                    break;
                }
                $existingLead = fetchLeadForAction($pdo, $leadId, $leadColumns, $currentUser);
                if (!$existingLead) {
                    $_SESSION['flash'] = ['type' => 'danger', 'message' => 'Brak dostępu do leada.'];
                    break;
                }
                if (empty($existingLead['client_id'])) {
                    $_SESSION['flash'] = ['type' => 'info', 'message' => 'Ten lead nie jest skonwertowany.'];
                    break;
                }
                $unconvertSetParts = [
                    'client_id = NULL',
                    'converted_at = NULL',
                    'converted_by_user_id = NULL',
                ];
                if (hasColumn($leadColumns, 'status')) {
                    $unconvertSetParts[] = 'status = :status';
                }
                $stmt = $pdo->prepare('UPDATE leady SET ' . implode(', ', $unconvertSetParts) . ' WHERE id = :id');
                $unconvertParams = [':id' => $leadId];
                if (hasColumn($leadColumns, 'status')) {
                    $unconvertParams[':status'] = 'oferta_zaakceptowana';
                }
                $stmt->execute($unconvertParams);
                logLeadActivity($pdo, $leadId, (int)$currentUser['id'], 'unconverted', 'Cofnięto konwersję (klient nieusunięty)');
                $_SESSION['flash'] = ['type' => 'success', 'message' => 'Cofnięto konwersję leada.'];
                break;

            default:
                $_SESSION['flash'] = ['type' => 'danger', 'message' => 'Nieznana akcja.'];
        }
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('Lead action error: ' . $e->getMessage());
        $_SESSION['flash'] = ['type' => 'danger', 'message' => 'Wystąpił błąd podczas operacji na leadach.'];
    }

    header('Location: ' . $redirectLocation);
    exit;
}

$leads = [];
$leadCounts = [];
$leadFilters = ['status' => '', 'source' => '', 'search' => '', 'priority' => ''];
$leadPage = max(1, (int)($_GET['lead_page'] ?? 1));
$leadPerPage = 25;
$leadTotalPages = 1;
$leadEditRow = null;
$currentLeadUrl = 'lead.php';
$leadPaginationBase = [];

ensureLeadInfrastructure($pdo);
$leadColumns = getTableColumns($pdo, 'leady');
$assignableUsers = fetchAssignableUsers($pdo);

if (isset($_GET['lead_status']) && array_key_exists($_GET['lead_status'], $leadStatuses)) {
    $leadFilters['status'] = $_GET['lead_status'];
}
if (isset($_GET['lead_source']) && array_key_exists($_GET['lead_source'], $leadSources)) {
    $leadFilters['source'] = $_GET['lead_source'];
}
if (isset($_GET['lead_priority']) && array_key_exists($_GET['lead_priority'], $leadPriorities)) {
    $leadFilters['priority'] = $_GET['lead_priority'];
}
if (!empty($_GET['lead_search'])) {
    $searchTerm = trim($_GET['lead_search']);
    $leadFilters['search'] = function_exists('mb_substr') ? mb_substr($searchTerm, 0, 255) : substr($searchTerm, 0, 255);
}

$conditions = [];
$params = [];
foreach (buildVisibleLeadConditions($leadColumns) as $leadVisibilityCondition) {
    $conditions[] = $leadVisibilityCondition;
}
if ($leadFilters['status'] !== '') {
    $conditions[] = 'status = :status';
    $params[':status'] = $leadFilters['status'];
}
if ($leadFilters['source'] !== '') {
    $conditions[] = 'zrodlo = :zrodlo';
    $params[':zrodlo'] = $leadFilters['source'];
}
if ($leadFilters['priority'] !== '' && hasColumn($leadColumns, 'priority')) {
    $conditions[] = 'priority = :priority';
    $params[':priority'] = $leadFilters['priority'];
}
if ($leadFilters['search'] !== '') {
    $conditions[] = '(nazwa_firmy LIKE :search OR nip LIKE :search)';
    $params[':search'] = '%' . $leadFilters['search'] . '%';
}

[$ownerClause, $ownerParams] = ownerConstraint($leadColumns, $currentUser, '', true);
if ($ownerClause !== '') {
    $conditions[] = $ownerClause;
    $params = array_merge($params, $ownerParams);
}

$whereSql = $conditions ? 'WHERE ' . implode(' AND ', $conditions) : '';

$orderSql = 'ORDER BY created_at DESC';
if (hasColumn($leadColumns, 'priority') && hasColumn($leadColumns, 'next_action_at')) {
    $orderSql = "ORDER BY CASE priority
        WHEN 'Pilny' THEN 1
        WHEN 'Wysoki' THEN 2
        WHEN 'Średni' THEN 3
        WHEN 'Niski' THEN 4
        ELSE 5 END,
        (next_action_at IS NULL),
        next_action_at ASC";
}

$listSql = "SELECT * FROM leady {$whereSql} {$orderSql} LIMIT :limit OFFSET :offset";
$stmt = $pdo->prepare($listSql);
foreach ($params as $key => $value) {
    $stmt->bindValue($key, $value);
}
$stmt->bindValue(':limit', $leadPerPage, PDO::PARAM_INT);
$stmt->bindValue(':offset', ($leadPage - 1) * $leadPerPage, PDO::PARAM_INT);
$stmt->execute();
$leads = $stmt->fetchAll();
$ownerMap = [];
if (hasColumn($leadColumns, 'owner_user_id') && $leads) {
    $ownerIds = [];
    foreach ($leads as $lead) {
        if (!empty($lead['owner_user_id'])) {
            $ownerIds[(int)$lead['owner_user_id']] = (int)$lead['owner_user_id'];
        }
    }
    $ownerMap = buildOwnerMap($pdo, array_values($ownerIds));
}

$countStmt = $pdo->prepare("SELECT COUNT(*) FROM leady {$whereSql}");
foreach ($params as $key => $value) {
    $countStmt->bindValue($key, $value);
}
$countStmt->execute();
$totalLeads = (int)$countStmt->fetchColumn();
$leadTotalPages = max(1, (int)ceil($totalLeads / $leadPerPage));

$leadCounts = array_fill_keys(array_keys($leadStatuses), 0);
$countConditions = [];
$countParams = [];
foreach (buildVisibleLeadConditions($leadColumns) as $leadVisibilityCondition) {
    $countConditions[] = $leadVisibilityCondition;
}
[$ownerClause, $ownerParams] = ownerConstraint($leadColumns, $currentUser, '', true);
if ($ownerClause !== '') {
    $countConditions[] = $ownerClause;
    $countParams = array_merge($countParams, $ownerParams);
}
$countSql = "SELECT status, COUNT(*) AS cnt FROM leady";
if ($countConditions) {
    $countSql .= ' WHERE ' . implode(' AND ', $countConditions);
}
$countSql .= ' GROUP BY status';
$countStmt = $pdo->prepare($countSql);
$countStmt->execute($countParams);
foreach ($countStmt as $row) {
    $status = $row['status'];
    if (isset($leadCounts[$status])) {
        $leadCounts[$status] = (int)$row['cnt'];
    }
}

if ($leadEditId) {
    $editConditions = ['id = :id'];
    foreach (buildVisibleLeadConditions($leadColumns) as $leadVisibilityCondition) {
        $editConditions[] = $leadVisibilityCondition;
    }
    $editParams = [':id' => $leadEditId];
    [$ownerClause, $ownerParams] = ownerConstraint($leadColumns, $currentUser, '', true);
    if ($ownerClause !== '') {
        $editConditions[] = $ownerClause;
        $editParams = array_merge($editParams, $ownerParams);
    }
    $stmt = $pdo->prepare("SELECT * FROM leady WHERE " . implode(' AND ', $editConditions));
    $stmt->execute($editParams);
    $leadEditRow = $stmt->fetch() ?: null;
}

$canTransfer = false;
if ($leadEditRow && hasColumn($leadColumns, 'owner_user_id')) {
    if ($currentRole !== 'Handlowiec') {
        $canTransfer = true;
    } else {
        $leadOwnerId = (int)($leadEditRow['owner_user_id'] ?? 0);
        $canTransfer = $leadOwnerId <= 0 || $leadOwnerId === (int)$currentUser['id'];
    }
}

$canConvert = false;
$clientLink = '';
if ($leadEditRow && hasColumn($leadColumns, 'client_id')) {
    if ($currentRole !== 'Handlowiec') {
        $canConvert = true;
    } else {
        $canConvert = (int)($leadEditRow['owner_user_id'] ?? 0) === (int)$currentUser['id'];
    }
    if (!empty($leadEditRow['client_id'])) {
        $clientLink = 'edytuj_klienta.php?id=' . (int)$leadEditRow['client_id'];
    }
}

$leadDetailUrl = $leadEditRow ? ('lead.php?lead_edit_id=' . (int)$leadEditRow['id']) : 'lead.php';
$leadActivityReturn = $leadDetailUrl . '#lead-activity';
$leadActivityAllowed = false;
$leadCrmStatuses = [];
$leadActivities = [];
$leadOwnerLabel = 'Nieprzypisany';
$leadCampaignCreateUrl = 'kalkulator_tygodniowy.php';
$leadCampaigns = [];
$kampanieColumns = tableExists($pdo, 'kampanie') ? getTableColumns($pdo, 'kampanie') : [];

if ($leadEditRow) {
    if (hasColumn($leadColumns, 'owner_user_id') && !empty($leadEditRow['owner_user_id'])) {
        $leadOwnerMap = buildOwnerMap($pdo, [(int)$leadEditRow['owner_user_id']]);
        $leadOwnerLabel = $leadOwnerMap[(int)$leadEditRow['owner_user_id']] ?? ('User #' . (int)$leadEditRow['owner_user_id']);
    }
    $leadActivityAllowed = true;
    if ($currentRole === 'Handlowiec' && hasColumn($leadColumns, 'owner_user_id')) {
        $leadActivityAllowed = (int)($leadEditRow['owner_user_id'] ?? 0) === (int)$currentUser['id'];
    }
    if ($leadActivityAllowed) {
        $leadCrmStatuses = getStatusy('lead');
        $leadActivities = getActivities('lead', (int)$leadEditRow['id']);
    }

    $leadCampaignCreateUrl .= '?' . http_build_query([
        'lead_id' => (int)$leadEditRow['id'],
        'lead_name' => (string)($leadEditRow['nazwa_firmy'] ?? ''),
        'lead_nip' => (string)($leadEditRow['nip'] ?? ''),
    ]);

    if (hasColumn($kampanieColumns, 'source_lead_id')) {
        $select = [
            'k.id',
            'k.klient_nazwa',
            'k.data_start',
            'k.data_koniec',
            'k.razem_brutto',
            'k.created_at',
        ];
        if (hasColumn($kampanieColumns, 'propozycja')) {
            $select[] = 'k.propozycja';
        } else {
            $select[] = '0 AS propozycja';
        }
        if (hasColumn($kampanieColumns, 'status')) {
            $select[] = 'k.status';
        } else {
            $select[] = "'' AS status";
        }
        if (hasColumn($kampanieColumns, 'owner_user_id')) {
            $select[] = 'k.owner_user_id';
            $select[] = 'u.login AS owner_login';
            $select[] = 'u.imie AS owner_imie';
            $select[] = 'u.nazwisko AS owner_nazwisko';
        } else {
            $select[] = 'NULL AS owner_user_id';
            $select[] = "'' AS owner_login";
            $select[] = "'' AS owner_imie";
            $select[] = "'' AS owner_nazwisko";
        }

        $sql = 'SELECT ' . implode(', ', $select) . ' FROM kampanie k';
        if (hasColumn($kampanieColumns, 'owner_user_id')) {
            $sql .= ' LEFT JOIN uzytkownicy u ON u.id = k.owner_user_id';
        }
        $sql .= ' WHERE k.source_lead_id = :lead_id ORDER BY k.created_at DESC, k.id DESC LIMIT 100';
        $stmtKamp = $pdo->prepare($sql);
        $stmtKamp->execute([':lead_id' => (int)$leadEditRow['id']]);
        $leadCampaigns = $stmtKamp->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }
}

$leadQueryParams = [];
if ($leadFilters['status']) {
    $leadQueryParams['lead_status'] = $leadFilters['status'];
}
if ($leadFilters['source']) {
    $leadQueryParams['lead_source'] = $leadFilters['source'];
}
if ($leadFilters['priority']) {
    $leadQueryParams['lead_priority'] = $leadFilters['priority'];
}
if ($leadFilters['search']) {
    $leadQueryParams['lead_search'] = $leadFilters['search'];
}
if ($leadPage > 1) {
    $leadQueryParams['lead_page'] = $leadPage;
}
$leadPaginationBase = $leadQueryParams;
unset($leadPaginationBase['lead_page']);
$currentLeadUrl = 'lead.php' . ($leadQueryParams ? '?' . http_build_query($leadQueryParams) : '');

require_once __DIR__ . '/includes/header.php';
?>

<div class="container-fluid mt-4 px-3 leads-page">
    <?php if ($flashMessage): ?>
        <div class="alert alert-<?= htmlspecialchars($flashMessage['type'] ?? 'info') ?> alert-dismissible fade show" role="alert">
            <?= $flashIsRaw ? $flashText : htmlspecialchars($flashText) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Zamknij"></button>
        </div>
    <?php endif; ?>

    <div class="d-flex flex-wrap justify-content-between align-items-center mb-3">
        <div>
            <h2 class="h4 mb-0">Leady sprzedażowe</h2>
            <small class="text-muted">Monitoruj progres i konwertuj do klientów</small>
        </div>
        <a href="generator_leadow.php" class="btn btn-primary">Generuj lead</a>
    </div>

    <form method="get" class="row g-2 align-items-end mb-4 lead-filters-form">
                <div class="col-xl-2 col-lg-3 col-md-4">
                    <label class="form-label">Status</label>
                    <select name="lead_status" class="form-select">
                        <option value="">Wszystkie</option>
                        <?php foreach ($leadStatuses as $key => $label): ?>
                            <option value="<?= htmlspecialchars($key) ?>" <?= $leadFilters['status'] === $key ? 'selected' : '' ?>><?= htmlspecialchars($label) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-xl-2 col-lg-3 col-md-4">
                    <label class="form-label">Źródło</label>
                    <select name="lead_source" class="form-select">
                        <option value="">Wszystkie</option>
                        <?php foreach ($leadSources as $key => $label): ?>
                            <option value="<?= htmlspecialchars($key) ?>" <?= $leadFilters['source'] === $key ? 'selected' : '' ?>><?= htmlspecialchars($label) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <?php if (hasColumn($leadColumns, 'priority')): ?>
                    <div class="col-xl-2 col-lg-3 col-md-4">
                        <label class="form-label">Priorytet</label>
                        <select name="lead_priority" class="form-select">
                            <option value="">Wszystkie</option>
                            <?php foreach ($leadPriorities as $key => $label): ?>
                                <option value="<?= htmlspecialchars($key) ?>" <?= $leadFilters['priority'] === $key ? 'selected' : '' ?>><?= htmlspecialchars($label) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                <?php endif; ?>
                <div class="col-xl-4 col-lg-6 col-md-8">
                    <label class="form-label">Szukaj</label>
                    <input type="text" name="lead_search" class="form-control" placeholder="Nazwa firmy lub NIP" value="<?= htmlspecialchars($leadFilters['search']) ?>">
                </div>
                <div class="col-xl-2 col-lg-3 col-md-4">
                    <button type="submit" class="btn btn-outline-primary w-100">Filtruj</button>
                </div>
            </form>

            <?php if ($leadEditRow): ?>
                <div class="card mb-4" id="lead-form">
                    <div class="card-header">Edycja leada</div>
                    <div class="card-body">
                        <form method="post">
                            <input type="hidden" name="lead_action" value="update">
                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
                            <input type="hidden" name="return_url" value="<?= htmlspecialchars($currentLeadUrl) ?>">
                            <input type="hidden" name="lead_id" value="<?= (int)$leadEditRow['id'] ?>">
                            <div class="mb-3">
                                <label class="form-label">Nazwa firmy *</label>
                                <input type="text" name="nazwa_firmy" class="form-control" required value="<?= htmlspecialchars($leadEditRow['nazwa_firmy'] ?? '') ?>">
                            </div>
                            <div class="row g-2 mb-3">
                                <div class="col-md-6">
                                    <label class="form-label">NIP</label>
                                    <input type="text" name="nip" class="form-control" value="<?= htmlspecialchars($leadEditRow['nip'] ?? '') ?>">
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label">Przypisany handlowiec</label>
                                    <input type="text" name="przypisany_handlowiec" class="form-control" value="<?= htmlspecialchars($leadEditRow['przypisany_handlowiec'] ?? '') ?>">
                                </div>
                            </div>
                            <?php if (hasColumn($leadColumns, 'owner_user_id')): ?>
                                <div class="mb-3">
                                    <label class="form-label">Osoba odpowiedzialna</label>
                                    <select name="owner_user_id" class="form-select">
                                        <option value="0" <?= empty($leadEditRow['owner_user_id']) ? 'selected' : '' ?>>Nieprzypisany</option>
                                        <?php foreach ($assignableUsers as $userId => $user): ?>
                                            <?php
                                            $leadOwnerId = (int)($leadEditRow['owner_user_id'] ?? 0);
                                            $canSeeAllOwners = $currentRole !== 'Handlowiec'
                                                || $leadOwnerId <= 0
                                                || $leadOwnerId === (int)$currentUser['id'];
                                            if (!$canSeeAllOwners && $currentRole === 'Handlowiec' && (int)$userId !== (int)$currentUser['id']) { continue; }
                                            ?>
                                            <option value="<?= (int)$userId ?>" <?= (int)($leadEditRow['owner_user_id'] ?? 0) === (int)$userId ? 'selected' : '' ?>>
                                                <?= htmlspecialchars(userDisplayName($user)) ?> (<?= htmlspecialchars($user['rola']) ?>)
                                            </option>
                                        <?php endforeach; ?>
                                        <?php
                                        $currentOwnerId = (int)($leadEditRow['owner_user_id'] ?? 0);
                                        if ($currentOwnerId > 0 && !isset($assignableUsers[$currentOwnerId])): ?>
                                            <option value="<?= $currentOwnerId ?>" selected>User #<?= $currentOwnerId ?> (niedostępny)</option>
                                        <?php endif; ?>
                                    </select>
                                </div>
                            <?php endif; ?>
                            <div class="row g-2 mb-3">
                                <div class="col-md-6">
                                    <label class="form-label">Telefon</label>
                                    <input type="text" name="telefon" class="form-control" value="<?= htmlspecialchars($leadEditRow['telefon'] ?? '') ?>">
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label">Email</label>
                                    <input type="email" name="email" class="form-control" value="<?= htmlspecialchars($leadEditRow['email'] ?? '') ?>">
                                </div>
                            </div>
                            <div class="row g-2 mb-3">
                                <div class="col-md-6">
                                    <label class="form-label">Źródło</label>
                                    <select name="zrodlo" class="form-select">
                                        <?php foreach ($leadSources as $key => $label): ?>
                                            <option value="<?= htmlspecialchars($key) ?>" <?= ($leadEditRow['zrodlo'] ?? '') === $key ? 'selected' : '' ?>><?= htmlspecialchars($label) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-md-3">
                                    <label class="form-label">Status</label>
                                    <select name="status" class="form-select">
                                        <?php foreach ($leadStatuses as $key => $label): ?>
                                            <option value="<?= htmlspecialchars($key) ?>" <?= normalizeLeadStatus((string)($leadEditRow['status'] ?? '')) === $key ? 'selected' : '' ?>><?= htmlspecialchars($label) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-md-3">
                                    <label class="form-label">Priorytet</label>
                                    <select name="priority" class="form-select">
                                        <?php foreach ($leadPriorities as $key => $label): ?>
                                            <option value="<?= htmlspecialchars($key) ?>" <?= ($leadEditRow['priority'] ?? 'Średni') === $key ? 'selected' : '' ?>><?= htmlspecialchars($label) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            </div>
                            <div class="mb-3">
                                <label class="form-label">Następny krok</label>
                                <input type="text" name="next_action" class="form-control" value="<?= htmlspecialchars($leadEditRow['next_action'] ?? '') ?>" placeholder="Opis działania">
                            </div>
                            <div class="mb-3">
                                <label class="form-label">Termin następnego kroku</label>
                                <input type="datetime-local" name="next_action_at" class="form-control" value="<?= htmlspecialchars(formatDateTimeForInput($leadEditRow['next_action_at'] ?? ($leadEditRow['next_action_date'] ?? ''))) ?>">
                            </div>
                            <div class="mb-3">
                                <label class="form-label">Notatki</label>
                                <textarea name="notatki" rows="4" class="form-control"><?= htmlspecialchars($leadEditRow['notatki'] ?? '') ?></textarea>
                            </div>
                            <div class="d-flex gap-2">
                                <button type="submit" class="btn btn-success">Zapisz zmiany</button>
                                <a href="lead.php" class="btn btn-outline-secondary">Anuluj</a>
                            </div>
                        </form>
                    </div>
                </div>
            <?php endif; ?>

            <?php if ($leadEditRow): ?>
                <div class="card mb-4" id="lead-activity">
                    <div class="card-header">Szczegoly leada</div>
                    <div class="card-body">
                        <div class="row g-2 mb-3">
                            <div class="col-md-4">
                                <div class="text-muted small">Nazwa firmy</div>
                                <div class="fw-semibold"><?= htmlspecialchars($leadEditRow['nazwa_firmy'] ?? '') ?></div>
                            </div>
                            <div class="col-md-2">
                                <div class="text-muted small">NIP</div>
                                <div><?= htmlspecialchars($leadEditRow['nip'] ?? '-') ?></div>
                            </div>
                            <div class="col-md-3">
                                <div class="text-muted small">Telefon</div>
                                <div><?= htmlspecialchars($leadEditRow['telefon'] ?? '-') ?></div>
                            </div>
                            <div class="col-md-3">
                                <div class="text-muted small">Email</div>
                                <div><?= htmlspecialchars($leadEditRow['email'] ?? '-') ?></div>
                            </div>
                            <div class="col-md-3">
                                <div class="text-muted small">Status (glowny)</div>
                                <div><?= renderLeadStatusBadge($leadEditRow['status'] ?? '', $leadStatuses, $leadStatusBadges) ?></div>
                            </div>
                            <div class="col-md-3">
                                <div class="text-muted small">Opiekun</div>
                                <div><?= htmlspecialchars($leadOwnerLabel) ?></div>
                            </div>
                            <div class="col-md-3">
                                <div class="text-muted small">Zrodlo</div>
                                <div><?= htmlspecialchars($leadSources[$leadEditRow['zrodlo'] ?? 'inne'] ?? ($leadEditRow['zrodlo'] ?? '-')) ?></div>
                            </div>
                            <div class="col-md-3">
                                <div class="text-muted small">Utworzono</div>
                                <div><?= htmlspecialchars($leadEditRow['created_at'] ?? '-') ?></div>
                            </div>
                        </div>

                        <ul class="nav nav-tabs" id="leadActivityTabs" role="tablist">
                            <li class="nav-item"><a class="nav-link active" data-bs-toggle="tab" href="#lead-activity-pane">Aktywnosc</a></li>
                            <li class="nav-item"><a class="nav-link" data-bs-toggle="tab" href="#lead-campaigns-pane">Kampanie</a></li>
                            <li class="nav-item"><a class="nav-link" data-bs-toggle="tab" href="#lead-mail-pane">Poczta</a></li>
                            <li class="nav-item"><a class="nav-link" data-bs-toggle="tab" href="#lead-sms-pane">SMS</a></li>
                        </ul>
                        <div class="tab-content pt-3">
                            <div class="tab-pane fade show active" id="lead-activity-pane">
                                <?php if (!$leadActivityAllowed): ?>
                                    <div class="alert alert-warning">Brak dostępu do aktywności tego leada.</div>
                                <?php else: ?>
                                    <div class="row g-3 mb-3">
                                        <div class="col-lg-6">
                                            <div class="card h-100">
                                                <div class="card-body">
                                                    <h6 class="mb-2">Status/czynnosc</h6>
                                                    <?php if (empty($leadCrmStatuses)): ?>
                                                        <div class="alert alert-info mb-2">Brak zdefiniowanych statusow CRM. Dodaj je w tabeli crm_statusy.</div>
                                                    <?php endif; ?>
                                                    <form method="post" class="row g-2 align-items-end">
                                                        <input type="hidden" name="lead_action" value="crm_status">
                                                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
                                                        <input type="hidden" name="lead_id" value="<?= (int)$leadEditRow['id'] ?>">
                                                        <input type="hidden" name="return_url" value="<?= htmlspecialchars($leadActivityReturn) ?>">
                                                        <div class="col-12">
                                                            <label class="form-label">Status/czynnosc</label>
                                                            <select name="crm_status_id" class="form-select" <?= empty($leadCrmStatuses) ? 'disabled' : 'required' ?>>
                                                                <option value="">Wybierz z listy</option>
                                                                <?php foreach ($leadCrmStatuses as $status): ?>
                                                                    <option value="<?= (int)($status['id'] ?? 0) ?>">
                                                                        <?= htmlspecialchars($status['nazwa'] ?? '') ?>
                                                                    </option>
                                                                <?php endforeach; ?>
                                                            </select>
                                                        </div>
                                                        <div class="col-12">
                                                            <label class="form-label">Komentarz (opcjonalnie)</label>
                                                            <input type="text" name="crm_status_comment" class="form-control" maxlength="255">
                                                        </div>
                                                        <div class="col-12">
                                                            <button type="submit" class="btn btn-primary" <?= empty($leadCrmStatuses) ? 'disabled' : '' ?>>Zapisz</button>
                                                        </div>
                                                    </form>
                                                </div>
                                            </div>
                                        </div>
                                        <div class="col-lg-6">
                                            <div class="card h-100">
                                                <div class="card-body">
                                                    <h6 class="mb-2">Notatka</h6>
                                                    <form method="post" class="row g-2 align-items-end">
                                                        <input type="hidden" name="lead_action" value="crm_note">
                                                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
                                                        <input type="hidden" name="lead_id" value="<?= (int)$leadEditRow['id'] ?>">
                                                        <input type="hidden" name="return_url" value="<?= htmlspecialchars($leadActivityReturn) ?>">
                                                        <div class="col-12">
                                                            <label class="form-label">Tresc</label>
                                                            <textarea name="crm_note" rows="3" class="form-control" required></textarea>
                                                        </div>
                                                        <div class="col-12">
                                                            <button type="submit" class="btn btn-outline-primary">Dodaj</button>
                                                        </div>
                                                    </form>
                                                </div>
                                            </div>
                                        </div>
                                    </div>

                                    <div class="mb-2 text-muted small">Ostatnie aktywności (najnowsze u góry)</div>
                                    <?php if (empty($leadActivities)): ?>
                                        <div class="text-muted">Brak aktywności do wyświetlenia.</div>
                                    <?php else: ?>
                                        <?php foreach ($leadActivities as $activity): ?>
                                            <?php
                                            $activityType = $activity['typ'] ?? 'system';
                                            $badgeMap = [
                                                'status' => ['label' => 'Status', 'class' => 'bg-primary'],
                                                'notatka' => ['label' => 'Notatka', 'class' => 'bg-info text-dark'],
                                                'mail' => ['label' => 'Mail', 'class' => 'bg-warning text-dark'],
                                                'system' => ['label' => 'System', 'class' => 'bg-secondary'],
                                            ];
                                            $badge = $badgeMap[$activityType] ?? $badgeMap['system'];
                                            $title = $activityType === 'status'
                                                ? (string)($activity['status_nazwa'] ?? 'Status')
                                                : $badge['label'];
                                            if (in_array($activityType, ['mail', 'system'], true) && !empty($activity['temat'])) {
                                                $title = (string)$activity['temat'];
                                            }
                                            $body = '';
                                            if ($activityType === 'status' && !empty($activity['tresc'])) {
                                                $body = (string)$activity['tresc'];
                                            } elseif (in_array($activityType, ['notatka', 'mail', 'system'], true)) {
                                                $body = (string)($activity['tresc'] ?? '');
                                            }
                                            $author = trim((string)($activity['imie'] ?? '') . ' ' . (string)($activity['nazwisko'] ?? ''));
                                            if ($author === '') {
                                                $author = (string)($activity['login'] ?? ('User #' . (int)($activity['user_id'] ?? 0)));
                                            }
                                            $createdAt = !empty($activity['created_at']) ? date('d.m.Y H:i', strtotime($activity['created_at'])) : '';
                                            ?>
                                            <div class="card mb-2">
                                                <div class="card-body py-2">
                                                    <div class="d-flex justify-content-between align-items-start gap-3">
                                                        <div>
                                                            <span class="badge <?= htmlspecialchars($badge['class']) ?>"><?= htmlspecialchars($badge['label']) ?></span>
                                                            <div class="fw-semibold mt-1"><?= htmlspecialchars($title) ?></div>
                                                            <?php if ($body !== ''): ?>
                                                                <div class="text-muted small"><?= htmlspecialchars($body) ?></div>
                                                            <?php endif; ?>
                                                        </div>
                                                        <div class="text-end small text-muted">
                                                            <div><?= htmlspecialchars($author) ?></div>
                                                            <div><?= htmlspecialchars($createdAt) ?></div>
                                                        </div>
                                                    </div>
                                                </div>
                                            </div>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                <?php endif; ?>
                            </div>
                            <div class="tab-pane fade" id="lead-campaigns-pane">
                                <div class="d-flex flex-wrap justify-content-between align-items-center mb-3 gap-2">
                                    <div>
                                        <div class="fw-semibold">Kampanie (mediaplany)</div>
                                        <div class="text-muted small">Lista kampanii zapisanych z kalkulatora dla tego leada.</div>
                                    </div>
                                    <a href="<?= htmlspecialchars($leadCampaignCreateUrl) ?>" class="btn btn-primary btn-sm">Dodaj kampanie</a>
                                </div>

                                <?php if (!hasColumn($kampanieColumns, 'source_lead_id')): ?>
                                    <div class="alert alert-warning mb-0">Brak kolumny powiazania kampanii z leadem (`source_lead_id`) w tabeli `kampanie`.</div>
                                <?php elseif (empty($leadCampaigns)): ?>
                                    <div class="text-muted">Brak kampanii przypisanych do tego leada.</div>
                                <?php else: ?>
                                    <div class="table-responsive">
                                        <table class="table table-sm table-zebra align-middle mb-0">
                                            <thead class="table-light">
                                                <tr>
                                                    <th>ID</th>
                                                    <th>Nazwa</th>
                                                    <th>Okres</th>
                                                    <th>Brutto</th>
                                                    <th>Typ</th>
                                                    <th>Opiekun</th>
                                                    <th>Utworzono</th>
                                                    <th>Akcje</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php foreach ($leadCampaigns as $campaign): ?>
                                                    <?php
                                                    $campaignOwnerLabel = 'Nieprzypisany';
                                                    if (!empty($campaign['owner_user_id'])) {
                                                        $campaignOwnerLabel = trim((string)($campaign['owner_imie'] ?? '') . ' ' . (string)($campaign['owner_nazwisko'] ?? ''));
                                                        if ($campaignOwnerLabel === '') {
                                                            $campaignOwnerLabel = (string)($campaign['owner_login'] ?? ('User #' . (int)$campaign['owner_user_id']));
                                                        }
                                                    }
                                                    ?>
                                                    <tr>
                                                        <td><?= (int)($campaign['id'] ?? 0) ?></td>
                                                        <td><?= htmlspecialchars($campaign['klient_nazwa'] ?? '') ?></td>
                                                        <td><?= htmlspecialchars(($campaign['data_start'] ?? '') . ' - ' . ($campaign['data_koniec'] ?? '')) ?></td>
                                                        <td><?= number_format((float)($campaign['razem_brutto'] ?? 0), 2, ',', ' ') ?> zł</td>
                                                        <td>
                                                            <?php
                                                            $campaignStatusNorm = strtolower(trim((string)($campaign['status'] ?? '')));
                                                            $isProposal = !empty($campaign['propozycja']) || $campaignStatusNorm === 'propozycja';
                                                            ?>
                                                            <?php if ($isProposal): ?>
                                                                <span class="badge bg-warning text-dark">Propozycja</span>
                                                            <?php else: ?>
                                                                <span class="badge bg-success">Aktywna</span>
                                                            <?php endif; ?>
                                                        </td>
                                                        <td><?= htmlspecialchars($campaignOwnerLabel) ?></td>
                                                        <td><?= htmlspecialchars($campaign['created_at'] ?? '') ?></td>
                                                        <td>
                                                            <a class="btn btn-sm btn-outline-primary" href="kampania_podglad.php?id=<?= (int)($campaign['id'] ?? 0) ?>">Podgląd</a>
                                                        </td>
                                                    </tr>
                                                <?php endforeach; ?>
                                            </tbody>
                                        </table>
                                    </div>
                                <?php endif; ?>
                            </div>
                            <div class="tab-pane fade" id="lead-mail-pane">
                                <div class="alert alert-info mb-0">Modul Poczta zostanie dodany w kolejnym etapie.</div>
                            </div>
                            <div class="tab-pane fade" id="lead-sms-pane">
                                <div class="alert alert-info mb-0">Modul SMS zostanie dodany w kolejnym etapie.</div>
                            </div>
                        </div>
                    </div>
                </div>
            <?php endif; ?>

            <?php if ($leadEditRow && $canTransfer): ?>
                <div class="card mb-4">
                    <div class="card-header">Przekaż lead</div>
                    <div class="card-body">
                        <form method="post">
                            <input type="hidden" name="lead_action" value="transfer">
                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
                            <input type="hidden" name="return_url" value="<?= htmlspecialchars($currentLeadUrl) ?>">
                            <input type="hidden" name="lead_id" value="<?= (int)$leadEditRow['id'] ?>">
                            <div class="row g-2 mb-3">
                                <div class="col-md-6">
                                    <label class="form-label">Przekaż do</label>
                                    <select name="new_owner_id" class="form-select" required>
                                        <option value="">Wybierz użytkownika</option>
                                        <?php foreach ($assignableUsers as $userId => $user): ?>
                                            <option value="<?= (int)$userId ?>">
                                                <?= htmlspecialchars(userDisplayName($user)) ?> (<?= htmlspecialchars($user['rola']) ?>)
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label">Powód przekazania</label>
                                    <input type="text" name="transfer_reason" class="form-control" required>
                                </div>
                            </div>
                            <button type="submit" class="btn btn-outline-primary">Przekaż lead</button>
                        </form>
                    </div>
                </div>
            <?php endif; ?>

            <?php if ($leadEditRow && hasColumn($leadColumns, 'client_id')): ?>
                <div class="card mb-4">
                    <div class="card-header">Finalizacja procesu: lead -> klient</div>
                    <div class="card-body d-flex flex-wrap gap-2 align-items-center">
                        <?php if (!empty($leadEditRow['client_id'])): ?>
                            <span class="badge bg-success">Klient</span>
                            <a href="<?= htmlspecialchars($clientLink) ?>" class="btn btn-sm btn-outline-primary">Przejdź do klienta</a>
                            <?php if ($canConvert): ?>
                                <form method="post" class="d-inline">
                                    <input type="hidden" name="lead_action" value="unconvert">
                                    <input type="hidden" name="lead_id" value="<?= (int)$leadEditRow['id'] ?>">
                                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
                                    <input type="hidden" name="return_url" value="<?= htmlspecialchars($currentLeadUrl) ?>">
                                    <button type="submit" class="btn btn-sm btn-outline-danger" onclick="return confirm('Cofnąć konwersję? Klient pozostanie w bazie.');">
                                        Cofnij konwersję
                                    </button>
                                </form>
                            <?php endif; ?>
                        <?php else: ?>
                            <span class="badge bg-secondary">Lead</span>
                            <?php if ($canConvert): ?>
                                <form method="post" class="d-inline">
                                    <input type="hidden" name="lead_action" value="convert">
                                    <input type="hidden" name="lead_id" value="<?= (int)$leadEditRow['id'] ?>">
                                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
                                    <input type="hidden" name="return_url" value="<?= htmlspecialchars($currentLeadUrl) ?>">
                                    <button type="submit" class="btn btn-sm btn-success">
                                        Zatwierdź jako klient
                                    </button>
                                </form>
                            <?php endif; ?>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endif; ?>

            <div class="card">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <span>Lista leadów</span>
                    <a href="dodaj_lead.php" class="btn btn-sm btn-outline-primary">Dodaj lead</a>
                </div>
                        <div class="table-responsive">
                            <table class="table table-zebra table-sm align-middle mb-0 lead-list-table">
                                <thead class="table-light">
                                <tr>
                                    <th>Nazwa firmy</th>
                                    <th>Telefon</th>
                                    <th>Źródło</th>
                                    <th>Owner</th>
                                    <th>Priorytet</th>
                                    <th>Status</th>
                                    <th>Następny krok</th>
                                    <th>Termin</th>
                                    <th>Utworzono</th>
                                    <th>Akcje</th>
                                </tr>
                                </thead>
                                <tbody>
                                <?php if ($leads): ?>
                                    <?php foreach ($leads as $lead): ?>
                                        <?php
                                        $ownerLabel = 'Nieprzypisany';
                                        if (!empty($lead['owner_user_id'])) {
                                            $ownerLabel = $ownerMap[(int)$lead['owner_user_id']] ?? ('User #' . (int)$lead['owner_user_id']);
                                        }
                                        ?>
                                        <tr>
                                            <td>
                                                <a href="lead_szczegoly.php?id=<?= (int)$lead['id'] ?>">
                                                    <?= htmlspecialchars($lead['nazwa_firmy']) ?>
                                                </a>
                                            </td>
                                            <td><?= $lead['telefon'] ? htmlspecialchars($lead['telefon']) : '—' ?></td>
                                            <td><?= htmlspecialchars($leadSources[$lead['zrodlo']] ?? $lead['zrodlo']) ?></td>
                                            <td><?= htmlspecialchars($ownerLabel) ?></td>
                                            <td><?= renderPriorityBadge($lead['priority'] ?? 'Średni') ?></td>
                                            <td><?= renderLeadStatusBadge($lead['status'], $leadStatuses, $leadStatusBadges) ?></td>
                                            <td><?= $lead['next_action'] ? htmlspecialchars($lead['next_action']) : '—' ?></td>
                                            <td><?= renderNextActionBadge($lead['next_action_at'] ?? ($lead['next_action_date'] ?? null)) ?></td>
                                            <td><?= date('d.m.Y H:i', strtotime($lead['created_at'])) ?></td>
                                            <td class="table-actions lead-actions-cell">
                                                <a href="lead_edytuj.php?id=<?= (int)$lead['id'] ?>" class="btn btn-sm btn-ghost">Edytuj</a>
                                                <form method="post" class="d-inline">
                                                    <input type="hidden" name="lead_action" value="convert">
                                                    <input type="hidden" name="lead_id" value="<?= (int)$lead['id'] ?>">
                                                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
                                                    <input type="hidden" name="return_url" value="<?= htmlspecialchars($currentLeadUrl) ?>">
                                                    <button type="submit" class="btn btn-sm btn-primary">Zatwierdź klienta</button>
                                                </form>
                                                <form method="post" class="d-inline" onsubmit="return confirm('Czy na pewno usunąć ten lead?');">
                                                    <input type="hidden" name="lead_action" value="delete">
                                                    <input type="hidden" name="lead_id" value="<?= (int)$lead['id'] ?>">
                                                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
                                                    <input type="hidden" name="return_url" value="<?= htmlspecialchars($currentLeadUrl) ?>">
                                                    <button type="submit" class="btn btn-sm btn-danger">Usuń</button>
                                                </form>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <tr>
                                        <td colspan="10" class="text-center py-4 text-muted">Brak leadów dla wybranych filtrów.</td>
                                    </tr>
                                <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                        <div class="card-footer">
                            <nav aria-label="Nawigacja po leadach">
                                <ul class="pagination mb-0">
                                    <?php
                                    $prevPage = max(1, $leadPage - 1);
                                    $nextPage = min($leadTotalPages, $leadPage + 1);
                                    $pageLink = function (int $page) use ($leadPaginationBase) {
                                        $params = $leadPaginationBase;
                                        if ($page > 1) {
                                            $params['lead_page'] = $page;
                                        } else {
                                            unset($params['lead_page']);
                                        }
                                        $query = http_build_query($params);
                                        return 'lead.php' . ($query ? '?' . $query : '');
                                    };
                                    ?>
                                    <li class="page-item <?= $leadPage <= 1 ? 'disabled' : '' ?>">
                                        <a class="page-link" href="<?= $leadPage <= 1 ? '#' : htmlspecialchars($pageLink($prevPage)) ?>">Poprzednia</a>
                                    </li>
                                    <?php for ($i = 1; $i <= $leadTotalPages; $i++): ?>
                                        <li class="page-item <?= $i === $leadPage ? 'active' : '' ?>">
                                            <a class="page-link" href="<?= htmlspecialchars($pageLink($i)) ?>"><?= $i ?></a>
                                        </li>
                                    <?php endfor; ?>
                                    <li class="page-item <?= $leadPage >= $leadTotalPages ? 'disabled' : '' ?>">
                                        <a class="page-link" href="<?= $leadPage >= $leadTotalPages ? '#' : htmlspecialchars($pageLink($nextPage)) ?>">Następna</a>
                                    </li>
                                </ul>
                            </nav>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
