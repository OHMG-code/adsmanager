<?php
declare(strict_types=1);

require_once __DIR__ . '/db_schema.php';

$GLOBALS['MAIL_ACCOUNT_INACTIVE_FLAGS'] = $GLOBALS['MAIL_ACCOUNT_INACTIVE_FLAGS'] ?? [];
$GLOBALS['MAIL_ACCOUNT_DEBUG_ROWS'] = $GLOBALS['MAIL_ACCOUNT_DEBUG_ROWS'] ?? [];

function mailResolveUserRoleForMailbox(PDO $pdo, int $userId): string {
    if ($userId <= 0) {
        return '';
    }

    try {
        ensureUserColumns($pdo);
        $userColumns = getTableColumns($pdo, 'uzytkownicy');
        if (!$userColumns) {
            return '';
        }

        $select = ['id', 'login'];
        if (hasColumn($userColumns, 'rola')) {
            $select[] = 'rola';
        }

        $stmt = $pdo->prepare('SELECT ' . implode(', ', $select) . ' FROM uzytkownicy WHERE id = :id LIMIT 1');
        $stmt->execute([':id' => $userId]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
        if (!$user) {
            return '';
        }

        $login = strtolower(trim((string)($user['login'] ?? '')));
        if ((int)($user['id'] ?? 0) === 1 || $login === 'admin') {
            return 'Administrator';
        }

        $rawRole = strtolower(trim((string)($user['rola'] ?? '')));
        if (in_array($rawRole, ['admin', 'administrator', 'superadmin'], true)) {
            return 'Administrator';
        }
        if (in_array($rawRole, ['manager', 'sales_manager', 'sales manager', 'salesmanager'], true)) {
            return 'Manager';
        }
    } catch (Throwable $e) {
        error_log('mail: cannot resolve user role for mailbox: ' . $e->getMessage());
    }

    return '';
}

function mailCanUseSharedMailbox(PDO $pdo, int $userId): bool {
    $role = mailResolveUserRoleForMailbox($pdo, $userId);
    return in_array($role, ['Administrator', 'Manager'], true);
}

function mailFetchActiveSharedMailbox(PDO $pdo, int $excludeUserId = 0): ?array {
    try {
        $sql = 'SELECT * FROM mail_accounts WHERE is_active = 1';
        $params = [];
        if ($excludeUserId > 0) {
            $sql .= ' AND user_id <> :exclude_user_id';
            $params[':exclude_user_id'] = $excludeUserId;
        }
        $sql .= ' ORDER BY id DESC LIMIT 1';
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
        return $row ?: null;
    } catch (Throwable $e) {
        error_log('mail: cannot fetch shared mailbox: ' . $e->getMessage());
        return null;
    }
}

function getMailAccountForUser(PDO $pdo, int $userId): ?array {
    $userId = (int)$userId;
    $GLOBALS['MAIL_ACCOUNT_INACTIVE_FLAGS'][$userId] = false;
    if ($userId <= 0) {
        error_log(sprintf('MAIL_GET user_id=%d found=0 (id=- active=0)', $userId));
        return null;
    }

    ensureCrmMailTables($pdo);
    $stmtDebug = $pdo->prepare(
        'SELECT id, user_id, email_address, is_active FROM mail_accounts WHERE user_id = :user_id LIMIT 5'
    );
    $stmtDebug->execute([':user_id' => $userId]);
    $rows = $stmtDebug->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $GLOBALS['MAIL_ACCOUNT_DEBUG_ROWS'][$userId] = $rows;
    $rowsCount = count($rows);
    $firstId = $rowsCount > 0 ? (int)$rows[0]['id'] : 0;
    $firstActive = $rowsCount > 0 ? (int)$rows[0]['is_active'] : 0;
    error_log(sprintf('[MAIL_DEBUG] uid=%d rows=%d first_id=%d active=%d', $userId, $rowsCount, $firstId, $firstActive));

    $activeId = 0;
    foreach ($rows as $row) {
        if (!empty($row['is_active'])) {
            $activeId = (int)$row['id'];
            break;
        }
    }
    if ($activeId > 0) {
        $stmt = $pdo->prepare('SELECT * FROM mail_accounts WHERE id = :id LIMIT 1');
        $stmt->execute([':id' => $activeId]);
        $account = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
        if ($account) {
            error_log(sprintf('MAIL_GET user_id=%d found=1 (id=%d active=1)', $userId, (int)$account['id']));
            return $account;
        }
    }

    if (mailCanUseSharedMailbox($pdo, $userId)) {
        $sharedAccount = mailFetchActiveSharedMailbox($pdo, $userId);
        if ($sharedAccount) {
            $rows[] = [
                'id' => (int)($sharedAccount['id'] ?? 0),
                'user_id' => (int)($sharedAccount['user_id'] ?? 0),
                'email_address' => (string)($sharedAccount['email_address'] ?? ''),
                'is_active' => 1,
                '_shared_fallback' => 1,
            ];
            $GLOBALS['MAIL_ACCOUNT_DEBUG_ROWS'][$userId] = $rows;
            error_log(sprintf(
                'MAIL_GET user_id=%d found=1 (shared_fallback=1 id=%d owner=%d)',
                $userId,
                (int)($sharedAccount['id'] ?? 0),
                (int)($sharedAccount['user_id'] ?? 0)
            ));
            return $sharedAccount;
        }
    }

    foreach ($rows as $row) {
        if (empty($row['is_active'])) {
            $GLOBALS['MAIL_ACCOUNT_INACTIVE_FLAGS'][$userId] = true;
            error_log(sprintf('MAIL_GET user_id=%d found=0 (id=%d active=0)', $userId, (int)$row['id']));
            return null;
        }
    }

    error_log(sprintf('MAIL_GET user_id=%d found=0 (id=- active=0)', $userId));
    return null;
}

function mailAccountInactiveFound(int $userId): bool {
    $userId = (int)$userId;
    return !empty($GLOBALS['MAIL_ACCOUNT_INACTIVE_FLAGS'][$userId]);
}

function mailAccountDebugRows(int $userId): array {
    $userId = (int)$userId;
    return $GLOBALS['MAIL_ACCOUNT_DEBUG_ROWS'][$userId] ?? [];
}
