<?php
declare(strict_types=1);

require_once __DIR__ . '/db_schema.php';

$GLOBALS['MAIL_ACCOUNT_INACTIVE_FLAGS'] = $GLOBALS['MAIL_ACCOUNT_INACTIVE_FLAGS'] ?? [];
$GLOBALS['MAIL_ACCOUNT_DEBUG_ROWS'] = $GLOBALS['MAIL_ACCOUNT_DEBUG_ROWS'] ?? [];

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
