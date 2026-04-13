<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/db_schema.php';

const DEFAULT_HANDLOWIEC_FUNCTION = 'Specjalista ds. Reklamy';

function ensureSession(): void {
    if (session_status() !== PHP_SESSION_ACTIVE) {
        return;
    }
}

function requireLogin(): void {
    ensureSession();
    if (!isset($_SESSION['user_id'])) {
        header('Location: ' . BASE_URL . '/index.php');
        exit;
    }
}

function normalizeRoleValue(?string $rawRole): string {
    $rawRole = trim((string)$rawRole);
    $map = [
        'admin'         => 'Administrator',
        'administrator' => 'Administrator',
        'superadmin'    => 'Administrator',
        'handlowiec'    => 'Handlowiec',
        'uzytkownik'    => 'Handlowiec',
        'user'          => 'Handlowiec',
        'manager'       => 'Manager',
        'sales_manager' => 'Manager',
        'sales manager' => 'Manager',
        'salesmanager'  => 'Manager',
    ];
    $normalized = $map[strtolower($rawRole)] ?? ($rawRole !== '' ? $rawRole : 'Handlowiec');
    return in_array($normalized, ['Administrator', 'Manager', 'Handlowiec'], true) ? $normalized : 'Handlowiec';
}

function normalizeRole(?array $user = null): string {
    ensureSession();
    $rawRole = trim((string)($user['rola'] ?? ($_SESSION['rola'] ?? $_SESSION['user_role'] ?? '')));
    return normalizeRoleValue($rawRole);
}

function currentUser(): ?array {
    ensureSession();
    $userId = (int)($_SESSION['user_id'] ?? 0);
    $login = trim((string)($_SESSION['login'] ?? $_SESSION['user_login'] ?? ''));
    $role = trim((string)($_SESSION['rola'] ?? $_SESSION['user_role'] ?? ''));

    if ($userId <= 0 && $login === '') {
        return null;
    }

    $dbUser = null;
    $shouldFetchDb = ($userId > 0 || $login !== '');
    if ($shouldFetchDb) {
        global $pdo;
        if (isset($pdo) && $pdo instanceof PDO) {
            try {
                ensureUserColumns($pdo);
                $columns = getTableColumns($pdo, 'uzytkownicy');
                if (hasColumn($columns, 'rola')) {
                    if ($userId > 0) {
                        $stmt = $pdo->prepare('SELECT id, login, rola FROM uzytkownicy WHERE id = :id');
                        $stmt->execute([':id' => $userId]);
                    } elseif ($login !== '') {
                        $stmt = $pdo->prepare('SELECT id, login, rola FROM uzytkownicy WHERE login = :login');
                        $stmt->execute([':login' => $login]);
                    }
                    if (isset($stmt)) {
                        $dbUser = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
                    }
                }
            } catch (Throwable $e) {
                error_log('auth: currentUser lookup failed: ' . $e->getMessage());
            }
        }
    }

    if ($dbUser) {
        if ($userId <= 0) {
            $userId = (int)($dbUser['id'] ?? 0);
        }
        if ($login === '') {
            $login = trim((string)($dbUser['login'] ?? ''));
        }
        if ($role === '') {
            $role = trim((string)($dbUser['rola'] ?? ''));
        }
    } elseif ($role === '' && $userId > 0) {
        $role = fetchUserRoleFromDb();
    }

    $normalizedRole = normalizeRoleValue($role);
    $adminOverride = ($login !== '' && strtolower($login) === 'admin') || $userId === 1;
    if ($adminOverride) {
        $normalizedRole = 'Administrator';
    }

    if ($userId > 0) {
        $_SESSION['user_id'] = $userId;
    }
    if ($login !== '') {
        $_SESSION['login'] = $login;
        $_SESSION['user_login'] = $login;
    }
    $_SESSION['rola'] = $normalizedRole;
    $_SESSION['user_role'] = $normalizedRole;
    if ($adminOverride) {
        $_SESSION['is_superadmin'] = true;
    }

    return [
        'id' => $userId,
        'user_id' => $userId,
        'login' => $login,
        'rola' => $normalizedRole,
        'admin_override' => $adminOverride,
        'is_admin_override' => $adminOverride,
    ];
}

function isSuperAdmin(): bool {
    ensureSession();
    if (!empty($_SESSION['is_superadmin'])) {
        return true;
    }
    $login = strtolower(trim((string)($_SESSION['login'] ?? $_SESSION['user_login'] ?? '')));
    $userId = (int)($_SESSION['user_id'] ?? 0);
    return $login === 'admin' || $userId === 1;
}

function fetchUserRoleFromDb(): string {
    ensureSession();
    if (empty($_SESSION['user_id'])) {
        return '';
    }
    global $pdo;
    if (!isset($pdo) || !($pdo instanceof PDO)) {
        return '';
    }
    try {
        ensureUserColumns($pdo);
        $columns = getTableColumns($pdo, 'uzytkownicy');
        if (!hasColumn($columns, 'rola')) {
            return '';
        }
        $stmt = $pdo->prepare('SELECT rola FROM uzytkownicy WHERE id = :id');
        $stmt->execute([':id' => (int)$_SESSION['user_id']]);
        return trim((string)$stmt->fetchColumn());
    } catch (Throwable $e) {
        error_log('auth: cannot fetch role from db: ' . $e->getMessage());
        return '';
    }
}

function currentUserRole(): string {
    $user = currentUser();
    return $user ? $user['rola'] : normalizeRoleValue('');
}

function getCurrentUser(PDO $pdo): ?array {
    ensureSession();
    $userId = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : 0;
    $login = trim((string)($_SESSION['login'] ?? $_SESSION['user_login'] ?? ''));
    if ($userId <= 0 && $login === '') {
        return null;
    }

    ensureUserColumns($pdo);
    $columns = getTableColumns($pdo, 'uzytkownicy');
    $select = [
        'u.`id`',
        'u.`login`',
        selectOrEmpty('u', 'rola', $columns),
        selectOrEmpty('u', 'email', $columns),
    ];

    if ($userId > 0) {
        $stmt = $pdo->prepare('SELECT ' . implode(', ', $select) . ' FROM uzytkownicy AS u WHERE u.`id` = :id');
        $stmt->execute([':id' => $userId]);
    } else {
        $stmt = $pdo->prepare('SELECT ' . implode(', ', $select) . ' FROM uzytkownicy AS u WHERE u.`login` = :login');
        $stmt->execute([':login' => $login]);
    }
    $user = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    if (!$user) {
        return null;
    }

    $_SESSION['user_id'] = (int)$user['id'];
    $_SESSION['user_login'] = $user['login'] ?? $login;
    $_SESSION['login'] = $_SESSION['user_login'];
    if (!empty($user['email'])) {
        $_SESSION['user_email'] = $user['email'];
    }
    $role = normalizeRole($user);
    if (isSuperAdmin()) {
        $role = 'Administrator';
        $_SESSION['is_superadmin'] = true;
    }
    $_SESSION['user_role'] = $role;
    $_SESSION['rola'] = $role;

    return $user;
}

function isAdminOverride(?array $user): bool {
    if (!$user) {
        return isSuperAdmin();
    }
    $login = strtolower(trim((string)($user['login'] ?? '')));
    $userId = (int)($user['user_id'] ?? $user['id'] ?? 0);
    return $login === 'admin' || $userId === 1;
}

function normalizeUserSnapshot(?array $user): ?array {
    if (!$user) {
        return null;
    }
    $login = trim((string)($user['login'] ?? ''));
    $userId = (int)($user['user_id'] ?? $user['id'] ?? 0);
    $role = normalizeRoleValue((string)($user['rola'] ?? ''));
    $adminOverride = !empty($user['admin_override'])
        || !empty($user['is_admin_override'])
        || ($login !== '' && strtolower($login) === 'admin')
        || $userId === 1;
    if ($adminOverride) {
        $role = 'Administrator';
    }
    return [
        'id' => $userId,
        'user_id' => $userId,
        'login' => $login,
        'rola' => $role,
        'admin_override' => $adminOverride,
        'is_admin_override' => $adminOverride,
    ];
}

function canManageSystem(?array $user): bool {
    if ($user === null) {
        return can('manage_system');
    }
    return canWithUser('manage_system', $user);
}

function canManageUpdates(?array $user): bool {
    if ($user === null) {
        return can('manage_updates');
    }
    return canWithUser('manage_updates', $user);
}

function getCurrentRole(PDO $pdo, ?array $user = null): string {
    ensureSession();
    if ($user) {
        return normalizeRole($user);
    }
    return currentUserRole();
}

function canManageUsers(PDO $pdo, ?array $user = null): bool {
    $current = $user ?? getCurrentUser($pdo);
    return canManageSystem($current);
}

function hasRole(string $role, ?array $user = null): bool {
    $current = $user ? normalizeRole($user) : currentUserRole();
    return $current === $role;
}

function isRole(string $role, ?array $user = null): bool {
    $current = $user ? normalizeRole($user) : currentUserRole();
    return $current === $role;
}

function isHandlowiec(array $user): bool {
    return normalizeRole($user) === 'Handlowiec';
}

function canWithUser(string $capability, ?array $user): bool {
    $snapshot = normalizeUserSnapshot($user);
    if (!$snapshot) {
        return false;
    }
    $role = $snapshot['rola'] ?? '';
    $adminOverride = !empty($snapshot['admin_override']);
    if ($adminOverride && in_array($capability, ['manage_system', 'manage_users', 'view_settings', 'manage_updates'], true)) {
        return true;
    }
    if ($capability === 'view_pricing') {
        return true;
    }
    if (in_array($capability, ['manage_system', 'manage_users', 'view_settings', 'manage_updates'], true)) {
        return in_array($role, ['Administrator', 'Manager'], true);
    }
    return false;
}

function can(string $capability): bool {
    return canWithUser($capability, currentUser());
}

function requireCapability(string $capability): void {
    requireLogin();
    $user = currentUser();
    if (canWithUser($capability, $user)) {
        return;
    }
    $snapshot = normalizeUserSnapshot($user);
    $requestUri = $_SERVER['REQUEST_URI'] ?? '';
    $login = $snapshot['login'] ?? '';
    $userId = (int)($snapshot['user_id'] ?? 0);
    $role = $snapshot['rola'] ?? '';
    $adminOverride = !empty($snapshot['admin_override']) ? 'true' : 'false';
    error_log(sprintf(
        '[AUTH_DENY] uri=%s login=%s uid=%d rola=%s cap=%s admin_override=%s',
        $requestUri !== '' ? $requestUri : '-',
        $login !== '' ? $login : '-',
        $userId,
        $role !== '' ? $role : '-',
        $capability,
        $adminOverride
    ));
    header('Location: ' . BASE_URL . '/dashboard.php?err=forbidden');
    exit;
}

function requireRole(array $roles): void {
    requireLogin();
    $user = currentUser();
    $role = $user['rola'] ?? '';
    if (!empty($user['is_admin_override']) || $role === 'Administrator') {
        return;
    }
    $allowed = [];
    foreach ($roles as $allowedRole) {
        $allowed[] = normalizeRoleValue((string)$allowedRole);
    }
    if (in_array($role, $allowed, true)) {
        return;
    }
    header('Location: ' . BASE_URL . '/dashboard.php?err=forbidden');
    exit;
}

function getCsrfToken(): string {
    ensureSession();
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(16));
    }
    return $_SESSION['csrf_token'];
}

function isCsrfTokenValid(?string $token): bool {
    ensureSession();
    $expected = $_SESSION['csrf_token'] ?? '';
    return $expected !== '' && is_string($token) && hash_equals($expected, $token);
}

function normalizeUserFunction(array $user): string {
    $functionVal = trim((string)($user['funkcja'] ?? ''));
    if ($functionVal === '' && normalizeRole($user) === 'Handlowiec') {
        return DEFAULT_HANDLOWIEC_FUNCTION;
    }
    return $functionVal;
}

function fetchCurrentUser(PDO $pdo): ?array {
    ensureSession();
    if (empty($_SESSION['user_id'])) {
        return null;
    }

    ensureUserColumns($pdo);
    $columns = getTableColumns($pdo, 'uzytkownicy');
    $select = [
        'u.`id`',
        'u.`login`',
        selectOrEmpty('u', 'email', $columns),
        selectOrEmpty('u', 'rola', $columns),
        selectOrEmpty('u', 'imie', $columns),
        selectOrEmpty('u', 'nazwisko', $columns),
        selectOrEmpty('u', 'telefon', $columns),
        selectOrEmpty('u', 'funkcja', $columns),
        selectOrEmpty('u', 'aktywny', $columns),
        selectOrEmpty('u', 'smtp_user', $columns),
        selectOrEmpty('u', 'smtp_pass', $columns),
        selectOrEmpty('u', 'smtp_pass_enc', $columns),
        selectOrEmpty('u', 'smtp_host', $columns),
        selectOrEmpty('u', 'smtp_port', $columns),
        selectOrEmpty('u', 'smtp_secure', $columns),
        selectOrEmpty('u', 'smtp_enabled', $columns),
        selectOrEmpty('u', 'smtp_from_email', $columns),
        selectOrEmpty('u', 'smtp_from_name', $columns),
        selectOrEmpty('u', 'email_signature', $columns),
        selectOrEmpty('u', 'use_system_smtp', $columns),
    ];

    $stmt = $pdo->prepare('SELECT ' . implode(', ', $select) . ' FROM uzytkownicy AS u WHERE u.`id` = :id');
    $stmt->execute([':id' => $_SESSION['user_id']]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    if (!$user) {
        return null;
    }

    $user['rola'] = normalizeRole($user);
    $user['funkcja'] = normalizeUserFunction($user);
    if (hasColumn($columns, 'aktywny') && (int)($user['aktywny'] ?? 1) === 0) {
        return null;
    }

    $_SESSION['user_role'] = $user['rola'];
    $_SESSION['user_login'] = $user['login'] ?? '';
    $_SESSION['login'] = $_SESSION['user_login'];
    $_SESSION['user_email'] = $user['email'] ?? '';
    $_SESSION['rola'] = $user['rola'];
    if (isSuperAdmin()) {
        $_SESSION['rola'] = 'Administrator';
        $_SESSION['user_role'] = 'Administrator';
        $_SESSION['is_superadmin'] = true;
    }

    return $user;
}

function ownerConstraint(array $columns, array $currentUser, string $alias = '', bool $includeUnassigned = false): array {
    if (normalizeRole($currentUser) !== 'Handlowiec' || !hasColumn($columns, 'owner_user_id')) {
        return ['', []];
    }
    $prefix = $alias !== '' ? rtrim($alias, '.') . '.' : '';
    $ownerExpr = sprintf('%s`owner_user_id`', $prefix);
    if ($includeUnassigned) {
        return [sprintf('(%1$s = :owner_user_id OR %1$s IS NULL OR %1$s = 0)', $ownerExpr), [':owner_user_id' => (int)$currentUser['id']]];
    }
    return [sprintf('%s = :owner_user_id', $ownerExpr), [':owner_user_id' => (int)$currentUser['id']]];
}

function defaultOwnerId(array $columns, array $currentUser): ?int {
    if (normalizeRole($currentUser) !== 'Handlowiec' || !hasColumn($columns, 'owner_user_id')) {
        return null;
    }
    return (int)$currentUser['id'];
}
