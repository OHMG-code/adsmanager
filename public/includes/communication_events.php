<?php
declare(strict_types=1);

require_once __DIR__ . '/db_schema.php';

function communicationNormalizeDirection(string $direction): string
{
    $direction = trim($direction);
    $allowed = ['outbound_client', 'internal', 'system'];
    return in_array($direction, $allowed, true) ? $direction : 'system';
}

function communicationNormalizeStatus(string $status): string
{
    $status = trim($status);
    $allowed = ['logged', 'sent', 'error', 'skipped_duplicate'];
    return in_array($status, $allowed, true) ? $status : 'logged';
}

function communicationBuildIdempotencyKey(string $eventType, array $parts): string
{
    $eventType = trim($eventType);
    $safeParts = [];
    foreach ($parts as $part) {
        $value = trim((string)$part);
        if ($value === '') {
            continue;
        }
        $value = str_replace([' ', "\n", "\r", "\t"], '', $value);
        $value = str_replace(':', '-', $value);
        $safeParts[] = $value;
    }
    $key = $eventType . ':' . implode(':', $safeParts);
    return substr($key, 0, 190);
}

function communicationFindEventByKey(PDO $pdo, string $idempotencyKey): ?array
{
    ensureCommunicationEventsTable($pdo);
    $idempotencyKey = trim($idempotencyKey);
    if ($idempotencyKey === '') {
        return null;
    }
    $stmt = $pdo->prepare('SELECT * FROM communication_events WHERE idempotency_key = :k LIMIT 1');
    $stmt->execute([':k' => $idempotencyKey]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

function communicationLogEvent(PDO $pdo, array $payload): array
{
    ensureCommunicationEventsTable($pdo);

    $eventType = trim((string)($payload['event_type'] ?? ''));
    $idempotencyKey = trim((string)($payload['idempotency_key'] ?? ''));
    if ($eventType === '' || $idempotencyKey === '') {
        return ['ok' => false, 'error' => 'Brak event_type lub idempotency_key.'];
    }

    $existing = communicationFindEventByKey($pdo, $idempotencyKey);
    if ($existing) {
        return [
            'ok' => true,
            'inserted' => false,
            'duplicate' => true,
            'id' => (int)($existing['id'] ?? 0),
            'status' => 'skipped_duplicate',
            'row' => $existing,
        ];
    }

    $direction = communicationNormalizeDirection((string)($payload['direction'] ?? 'system'));
    $status = communicationNormalizeStatus((string)($payload['status'] ?? 'logged'));
    $metaJson = null;
    $meta = $payload['meta_json'] ?? null;
    if (is_array($meta) && $meta !== []) {
        $metaJson = json_encode($meta, JSON_UNESCAPED_UNICODE);
        if ($metaJson === false) {
            $metaJson = null;
        }
    }

    try {
        $stmt = $pdo->prepare("INSERT INTO communication_events
            (event_type, idempotency_key, direction, status, recipient, subject, body, meta_json,
             lead_id, client_id, campaign_id, brief_id, dispatch_id, spot_audio_file_id, created_by_user_id)
            VALUES
            (:event_type, :idempotency_key, :direction, :status, :recipient, :subject, :body, :meta_json,
             :lead_id, :client_id, :campaign_id, :brief_id, :dispatch_id, :spot_audio_file_id, :created_by_user_id)");
        $stmt->execute([
            ':event_type' => $eventType,
            ':idempotency_key' => $idempotencyKey,
            ':direction' => $direction,
            ':status' => $status,
            ':recipient' => trim((string)($payload['recipient'] ?? '')) !== '' ? trim((string)$payload['recipient']) : null,
            ':subject' => trim((string)($payload['subject'] ?? '')) !== '' ? trim((string)$payload['subject']) : null,
            ':body' => trim((string)($payload['body'] ?? '')) !== '' ? (string)$payload['body'] : null,
            ':meta_json' => $metaJson,
            ':lead_id' => !empty($payload['lead_id']) ? (int)$payload['lead_id'] : null,
            ':client_id' => !empty($payload['client_id']) ? (int)$payload['client_id'] : null,
            ':campaign_id' => !empty($payload['campaign_id']) ? (int)$payload['campaign_id'] : null,
            ':brief_id' => !empty($payload['brief_id']) ? (int)$payload['brief_id'] : null,
            ':dispatch_id' => !empty($payload['dispatch_id']) ? (int)$payload['dispatch_id'] : null,
            ':spot_audio_file_id' => !empty($payload['spot_audio_file_id']) ? (int)$payload['spot_audio_file_id'] : null,
            ':created_by_user_id' => !empty($payload['created_by_user_id']) ? (int)$payload['created_by_user_id'] : null,
        ]);
    } catch (Throwable $e) {
        error_log('communication_events: insert failed: ' . $e->getMessage());
        return ['ok' => false, 'error' => $e->getMessage()];
    }

    return [
        'ok' => true,
        'inserted' => true,
        'duplicate' => false,
        'id' => (int)$pdo->lastInsertId(),
        'status' => $status,
    ];
}

function communicationUpdateEventStatus(PDO $pdo, int $eventId, string $status, ?array $metaPatch = null): bool
{
    ensureCommunicationEventsTable($pdo);
    if ($eventId <= 0) {
        return false;
    }
    $status = communicationNormalizeStatus($status);

    try {
        if ($metaPatch !== null) {
            $stmtCurrent = $pdo->prepare('SELECT meta_json FROM communication_events WHERE id = :id LIMIT 1');
            $stmtCurrent->execute([':id' => $eventId]);
            $currentRaw = $stmtCurrent->fetchColumn();
            $currentMeta = [];
            if (is_string($currentRaw) && trim($currentRaw) !== '') {
                $decoded = json_decode($currentRaw, true);
                if (is_array($decoded)) {
                    $currentMeta = $decoded;
                }
            }
            $merged = array_merge($currentMeta, $metaPatch);
            $metaJson = json_encode($merged, JSON_UNESCAPED_UNICODE);
            if ($metaJson === false) {
                $metaJson = null;
            }
            $stmt = $pdo->prepare('UPDATE communication_events SET status = :status, meta_json = :meta_json WHERE id = :id');
            $stmt->execute([
                ':status' => $status,
                ':meta_json' => $metaJson,
                ':id' => $eventId,
            ]);
        } else {
            $stmt = $pdo->prepare('UPDATE communication_events SET status = :status WHERE id = :id');
            $stmt->execute([
                ':status' => $status,
                ':id' => $eventId,
            ]);
        }
        return true;
    } catch (Throwable $e) {
        error_log('communication_events: update status failed: ' . $e->getMessage());
        return false;
    }
}

function communicationCreateInternalNotification(PDO $pdo, int $userId, string $type, string $text, ?string $link = null): bool
{
    ensureNotificationsTable($pdo);
    if ($userId <= 0 || trim($type) === '' || trim($text) === '') {
        return false;
    }

    try {
        $stmtCheck = $pdo->prepare(
            'SELECT id FROM powiadomienia
             WHERE user_id = :user_id
               AND typ = :typ
               AND COALESCE(link, \'\') = COALESCE(:link, \'\')
               AND COALESCE(tresc, \'\') = COALESCE(:tresc, \'\')
               AND DATE(created_at) = CURDATE()
             LIMIT 1'
        );
        $stmtCheck->execute([
            ':user_id' => $userId,
            ':typ' => $type,
            ':link' => $link,
            ':tresc' => $text,
        ]);
        if ($stmtCheck->fetchColumn()) {
            return true;
        }

        $stmtInsert = $pdo->prepare(
            'INSERT INTO powiadomienia (user_id, typ, tresc, link) VALUES (:user_id, :typ, :tresc, :link)'
        );
        $stmtInsert->execute([
            ':user_id' => $userId,
            ':typ' => $type,
            ':tresc' => $text,
            ':link' => $link,
        ]);
        return true;
    } catch (Throwable $e) {
        error_log('communication_events: internal notification failed: ' . $e->getMessage());
        return false;
    }
}

function communicationResolveCampaignUsers(PDO $pdo, int $campaignId): array
{
    $out = [
        'campaign_owner_user_id' => 0,
        'production_owner_user_id' => 0,
        'lead_owner_user_id' => 0,
    ];
    if ($campaignId <= 0 || !tableExists($pdo, 'kampanie')) {
        return $out;
    }

    ensureKampanieOwnershipColumns($pdo);
    ensureLeadBriefsTable($pdo);
    ensureLeadColumns($pdo);

    try {
        $stmt = $pdo->prepare('SELECT owner_user_id, source_lead_id FROM kampanie WHERE id = :id LIMIT 1');
        $stmt->execute([':id' => $campaignId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
        $out['campaign_owner_user_id'] = (int)($row['owner_user_id'] ?? 0);
        $leadId = (int)($row['source_lead_id'] ?? 0);

        if ($leadId > 0 && tableExists($pdo, 'leady')) {
            $stmtLead = $pdo->prepare('SELECT owner_user_id FROM leady WHERE id = :id LIMIT 1');
            $stmtLead->execute([':id' => $leadId]);
            $out['lead_owner_user_id'] = (int)($stmtLead->fetchColumn() ?? 0);
        }

        if (tableExists($pdo, 'lead_briefs')) {
            $stmtBrief = $pdo->prepare('SELECT production_owner_user_id FROM lead_briefs WHERE campaign_id = :campaign_id LIMIT 1');
            $stmtBrief->execute([':campaign_id' => $campaignId]);
            $out['production_owner_user_id'] = (int)($stmtBrief->fetchColumn() ?? 0);
        }
    } catch (Throwable $e) {
        error_log('communication_events: resolve campaign users failed: ' . $e->getMessage());
    }

    return $out;
}
