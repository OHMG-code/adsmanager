<?php
declare(strict_types=1);

require_once __DIR__ . '/db_schema.php';
require_once __DIR__ . '/auth.php';

function getStatusy(string $dotyczy): array
{
    global $pdo;
    if (!($pdo instanceof PDO)) {
        return [];
    }
    ensureCrmStatusTables($pdo);

    $dotyczy = trim($dotyczy);
    if (!in_array($dotyczy, ['lead', 'klient', 'oba'], true)) {
        $dotyczy = 'oba';
    }

    try {
        $stmt = $pdo->prepare(
            "SELECT id, nazwa, dotyczy
             FROM crm_statusy
             WHERE aktywny = 1 AND (dotyczy = :dotyczy OR dotyczy = 'oba')
             ORDER BY sort ASC, nazwa ASC"
        );
        $stmt->execute([':dotyczy' => $dotyczy]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        error_log('crm_activity: getStatusy failed: ' . $e->getMessage());
        return [];
    }
}

function getStatusList(string $dotyczy): array
{
    return getStatusy($dotyczy);
}

function canAccessCrmObject(string $obiektTyp, int $obiektId, ?array $user = null): bool
{
    global $pdo;
    if (!($pdo instanceof PDO)) {
        return false;
    }
    $obiektTyp = trim($obiektTyp);
    if (!in_array($obiektTyp, ['lead', 'klient'], true) || $obiektId <= 0) {
        return false;
    }

    $currentUser = $user ?? fetchCurrentUser($pdo);
    if (!$currentUser) {
        return false;
    }
    $role = normalizeRole($currentUser);
    if ($role !== 'Handlowiec') {
        return true;
    }

    if ($obiektTyp === 'lead') {
        ensureLeadColumns($pdo);
        $cols = getTableColumns($pdo, 'leady');
        if (!hasColumn($cols, 'owner_user_id')) {
            return false;
        }
        $stmt = $pdo->prepare('SELECT owner_user_id FROM leady WHERE id = :id');
    } else {
        ensureClientLeadColumns($pdo);
        $cols = getTableColumns($pdo, 'klienci');
        if (!hasColumn($cols, 'owner_user_id')) {
            return false;
        }
        $stmt = $pdo->prepare('SELECT owner_user_id FROM klienci WHERE id = :id');
    }

    $stmt->execute([':id' => $obiektId]);
    $ownerId = (int)($stmt->fetchColumn() ?? 0);
    return $ownerId > 0 && $ownerId === (int)$currentUser['id'];
}

function addActivity(
    string $obiektTyp,
    int $obiektId,
    string $typ,
    int $userId,
    ?string $tresc = null,
    ?int $statusId = null,
    ?string $temat = null,
    ?array $metaArr = null
): bool {
    global $pdo;
    if (!($pdo instanceof PDO)) {
        return false;
    }
    ensureCrmStatusTables($pdo);

    $obiektTyp = trim($obiektTyp);
    if (!in_array($obiektTyp, ['lead', 'klient'], true)) {
        return false;
    }
    $typ = trim($typ);
    if (!in_array($typ, ['status', 'notatka', 'mail', 'system', 'email_in', 'email_out', 'sms_in', 'sms_out'], true)) {
        return false;
    }
    if ($obiektId <= 0 || $userId <= 0) {
        return false;
    }

    $currentUser = fetchCurrentUser($pdo);
    if (!$currentUser) {
        return false;
    }
    $role = normalizeRole($currentUser);
    if ($role === 'Handlowiec' && (int)$currentUser['id'] !== $userId) {
        return false;
    }
    if (!canAccessCrmObject($obiektTyp, $obiektId, $currentUser)) {
        http_response_code(403);
        return false;
    }

    $tresc = trim((string)$tresc);
    $tresc = $tresc !== '' ? $tresc : null;
    $temat = trim((string)$temat);
    $temat = $temat !== '' ? $temat : null;
    $statusId = ($statusId && $statusId > 0) ? $statusId : null;
    $metaJson = null;
    if (is_array($metaArr) && $metaArr !== []) {
        $metaJson = json_encode($metaArr, JSON_UNESCAPED_UNICODE);
        if ($metaJson === false) {
            $metaJson = null;
        }
    }

    try {
        $stmt = $pdo->prepare(
            'INSERT INTO crm_aktywnosci (obiekt_typ, obiekt_id, typ, status_id, temat, tresc, user_id, meta_json)
             VALUES (:obiekt_typ, :obiekt_id, :typ, :status_id, :temat, :tresc, :user_id, :meta_json)'
        );
        $stmt->execute([
            ':obiekt_typ' => $obiektTyp,
            ':obiekt_id' => $obiektId,
            ':typ' => $typ,
            ':status_id' => $statusId,
            ':temat' => $temat,
            ':tresc' => $tresc,
            ':user_id' => $userId,
            ':meta_json' => $metaJson,
        ]);
        return true;
    } catch (Throwable $e) {
        error_log('crm_activity: addActivity failed: ' . $e->getMessage());
        return false;
    }
}

function getActivities(string $obiektTyp, int $obiektId, int $limit = 200): array
{
    global $pdo;
    if (!($pdo instanceof PDO)) {
        return [];
    }
    ensureCrmStatusTables($pdo);

    $obiektTyp = trim($obiektTyp);
    if (!in_array($obiektTyp, ['lead', 'klient'], true) || $obiektId <= 0) {
        return [];
    }

    $currentUser = fetchCurrentUser($pdo);
    if (!$currentUser) {
        return [];
    }
    if (!canAccessCrmObject($obiektTyp, $obiektId, $currentUser)) {
        http_response_code(403);
        return [];
    }

    $limit = max(1, min(500, $limit));

    try {
        $stmt = $pdo->prepare(
            "SELECT a.id, a.typ, a.status_id, a.temat, a.tresc, a.meta_json, a.user_id, a.created_at,
                    s.nazwa AS status_nazwa,
                    u.login, u.imie, u.nazwisko
             FROM crm_aktywnosci a
             LEFT JOIN crm_statusy s ON s.id = a.status_id
             LEFT JOIN uzytkownicy u ON u.id = a.user_id
             WHERE a.obiekt_typ = :typ AND a.obiekt_id = :id
             ORDER BY a.created_at DESC, a.id DESC
             LIMIT :limit"
        );
        $stmt->bindValue(':typ', $obiektTyp);
        $stmt->bindValue(':id', $obiektId, PDO::PARAM_INT);
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        error_log('crm_activity: getActivities failed: ' . $e->getMessage());
        return [];
    }
}
