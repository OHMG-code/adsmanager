<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/db_schema.php';
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/handlers/client_save.php';

requireLogin();

if (!isset($_POST['id']) || !is_numeric($_POST['id'])) {
    header('Location: ' . BASE_URL . '/lista_klientow.php?msg=error_invalid_id');
    exit;
}

$id = (int) $_POST['id'];

if (!isCsrfTokenValid($_POST['csrf_token'] ?? '')) {
    header('Location: ' . BASE_URL . '/lista_klientow.php?msg=error_csrf');
    exit;
}

try {
    $currentUser = fetchCurrentUser($pdo) ?? [];
    $currentRole = normalizeRole($currentUser);
    if ($currentRole === 'Handlowiec') {
        ensureClientLeadColumns($pdo);
        $clientColumns = getTableColumns($pdo, 'klienci');
        $ownerSelect = [];
        if (hasColumn($clientColumns, 'assigned_user_id')) {
            $ownerSelect[] = 'assigned_user_id';
        }
        if (hasColumn($clientColumns, 'owner_user_id')) {
            $ownerSelect[] = 'owner_user_id';
        }

        if ($ownerSelect) {
            $stmtOwner = $pdo->prepare("SELECT " . implode(', ', $ownerSelect) . " FROM klienci WHERE id = :id LIMIT 1");
            $stmtOwner->execute([':id' => $id]);
            $ownerRow = $stmtOwner->fetch(PDO::FETCH_ASSOC) ?: [];
            $ownerId = (int)($ownerRow['assigned_user_id'] ?? 0);
            if ($ownerId <= 0) {
                $ownerId = (int)($ownerRow['owner_user_id'] ?? 0);
            }
            if ($ownerId > 0 && $ownerId !== (int)($currentUser['id'] ?? 0)) {
                header('Location: ' . BASE_URL . '/lista_klientow.php?msg=error_forbidden');
                exit;
            }
        }

        unset($_POST['assigned_user_id'], $_POST['owner_user_id']);
    }

    $result = saveClientFromPost($pdo, $_POST, (int)($currentUser['id'] ?? 0), ['mode' => 'update']);
    if (!$result['ok']) {
        $errors = $result['errors'] ? implode(', ', $result['errors']) : 'Nie udało się zapisać danych klienta.';
        echo "<pre>Błąd aktualizacji: " . htmlspecialchars($errors) . "</pre>";
        exit;
    }

    header('Location: ' . BASE_URL . '/lista_klientow.php?msg=updated');
    exit;

} catch (PDOException $e) {
    error_log('zapisz_klienta.php: save failed: ' . $e->getMessage());
    echo "<pre>Błąd aktualizacji.</pre>";
    exit;
}
