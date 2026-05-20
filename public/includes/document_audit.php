<?php
declare(strict_types=1);

require_once __DIR__ . '/db_schema.php';

function documentAuditSanitizeMetadata($value)
{
    if (is_array($value)) {
        $clean = [];
        foreach ($value as $key => $item) {
            $keyString = strtolower((string)$key);
            if (preg_match('/token|password|smtp|secret|api_key|apikey|authorization|cookie/', $keyString)) {
                $clean[$key] = '[redacted]';
                continue;
            }
            $clean[$key] = documentAuditSanitizeMetadata($item);
        }
        return $clean;
    }
    if (is_object($value)) {
        return documentAuditSanitizeMetadata((array)$value);
    }
    if (is_string($value) && strlen($value) > 1000) {
        return substr($value, 0, 1000) . '...';
    }
    return $value;
}

function logDocumentAudit(PDO $pdo, int $documentId, string $eventType, string $eventLabel, array $context = []): void
{
    if ($documentId < 0 || trim($eventType) === '') {
        return;
    }

    try {
        if (!$pdo->inTransaction()) {
            ensureDocumentAuditLogTable($pdo);
        }

        $metadata = documentAuditSanitizeMetadata($context['metadata'] ?? []);
        $metadataJson = null;
        if ($metadata !== [] && $metadata !== null) {
            $encoded = json_encode($metadata, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $metadataJson = $encoded !== false ? $encoded : null;
        }

        $stmt = $pdo->prepare('INSERT INTO document_audit_log
            (document_id, user_id, event_type, event_label, old_value, new_value, metadata_json, ip_address, user_agent, created_at)
            VALUES (:document_id, :user_id, :event_type, :event_label, :old_value, :new_value, :metadata_json, :ip_address, :user_agent, CURRENT_TIMESTAMP)');
        $stmt->execute([
            ':document_id' => $documentId,
            ':user_id' => !empty($context['user_id']) ? (int)$context['user_id'] : null,
            ':event_type' => substr(trim($eventType), 0, 80),
            ':event_label' => substr(trim($eventLabel), 0, 255),
            ':old_value' => isset($context['old_value']) ? (string)$context['old_value'] : null,
            ':new_value' => isset($context['new_value']) ? (string)$context['new_value'] : null,
            ':metadata_json' => $metadataJson,
            ':ip_address' => substr(trim((string)($context['ip_address'] ?? ($_SERVER['REMOTE_ADDR'] ?? ''))), 0, 45) ?: null,
            ':user_agent' => substr(trim((string)($context['user_agent'] ?? ($_SERVER['HTTP_USER_AGENT'] ?? ''))), 0, 255) ?: null,
        ]);
    } catch (Throwable $e) {
        error_log('document_audit: cannot write audit event: ' . $e->getMessage());
    }
}
