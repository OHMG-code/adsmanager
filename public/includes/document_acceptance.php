<?php
declare(strict_types=1);

require_once __DIR__ . '/db_schema.php';
require_once __DIR__ . '/document_status.php';
require_once __DIR__ . '/document_audit.php';
require_once __DIR__ . '/document_pdf_versions.php';

function documentAcceptanceTokenHash(string $token): string
{
    return hash('sha256', $token);
}

function documentAcceptanceBaseUrl(): string
{
    if (defined('APP_URL') && trim((string)APP_URL) !== '') {
        return rtrim((string)APP_URL, '/');
    }

    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';
    $scheme = $https ? 'https' : 'http';
    $host = trim((string)($_SERVER['HTTP_HOST'] ?? ''));
    if ($host === '') {
        $host = 'localhost';
    }

    return rtrim($scheme . '://' . $host . (defined('BASE_URL') ? (string)BASE_URL : ''), '/');
}

function documentAcceptanceUrl(string $token): string
{
    return documentAcceptanceBaseUrl() . '/akceptacja_dokumentu.php?t=' . rawurlencode($token);
}

function documentAcceptancePdfPath(?string $pdfPath, ?int $documentId = null): ?string
{
    if ($documentId !== null && $documentId > 0) {
        try {
            $currentVersion = getCurrentDocumentPdfVersion($GLOBALS['pdo'], $documentId);
            if ($currentVersion) {
                $versionPath = documentPdfResolvePath($currentVersion['pdf_path'] ?? '');
                if ($versionPath) {
                    return $versionPath;
                }
            }
        } catch (Throwable $e) {
            error_log('document_acceptance: current PDF version lookup failed: ' . $e->getMessage());
        }
    }

    $pdfPath = trim((string)$pdfPath);
    if ($pdfPath === '') {
        return null;
    }
    $publicDir = dirname(__DIR__);
    $fullPath = realpath($publicDir . '/' . ltrim($pdfPath, '/\\'));
    $publicReal = realpath($publicDir);
    if (!$publicReal || !$fullPath || strpos($fullPath, $publicReal) !== 0 || !is_file($fullPath)) {
        return null;
    }
    return $fullPath;
}

function createDocumentAcceptanceToken(PDO $pdo, int $documentId, string $email, int $createdBy): string
{
    if ($documentId <= 0) {
        throw new InvalidArgumentException('Niepoprawny identyfikator dokumentu.');
    }
    $email = trim($email);
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        throw new InvalidArgumentException('Niepoprawny adres e-mail.');
    }

    ensureDocumentAcceptanceTables($pdo);
    $token = bin2hex(random_bytes(32));
    $hash = documentAcceptanceTokenHash($token);

    $started = false;
    if (!$pdo->inTransaction()) {
        $pdo->beginTransaction();
        $started = true;
    }
    try {
        $stmt = $pdo->prepare('SELECT id FROM documents WHERE id = :id LIMIT 1');
        $stmt->execute([':id' => $documentId]);
        if (!$stmt->fetchColumn()) {
            throw new RuntimeException('Dokument nie istnieje.');
        }

        $pdo->prepare('UPDATE document_acceptance_tokens
            SET revoked_at = CURRENT_TIMESTAMP
            WHERE document_id = :document_id
              AND used_at IS NULL
              AND revoked_at IS NULL
              AND expires_at > CURRENT_TIMESTAMP')
            ->execute([':document_id' => $documentId]);

        $insert = $pdo->prepare('INSERT INTO document_acceptance_tokens
            (document_id, token_hash, recipient_email, expires_at, created_by, created_at)
            VALUES (:document_id, :token_hash, :recipient_email, DATE_ADD(CURRENT_TIMESTAMP, INTERVAL 14 DAY), :created_by, CURRENT_TIMESTAMP)');
        $insert->execute([
            ':document_id' => $documentId,
            ':token_hash' => $hash,
            ':recipient_email' => $email,
            ':created_by' => $createdBy > 0 ? $createdBy : null,
        ]);

        if ($started) {
            $pdo->commit();
        }
        return $token;
    } catch (Throwable $e) {
        if ($started && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

function getActiveDocumentAcceptanceToken(PDO $pdo, int $documentId): ?array
{
    ensureDocumentAcceptanceTables($pdo);
    if ($documentId <= 0) {
        return null;
    }
    $stmt = $pdo->prepare("SELECT *
        FROM document_acceptance_tokens
        WHERE document_id = :document_id
          AND used_at IS NULL
          AND revoked_at IS NULL
          AND expires_at > CURRENT_TIMESTAMP
        ORDER BY created_at DESC, id DESC
        LIMIT 1");
    $stmt->execute([':document_id' => $documentId]);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

function findValidDocumentByAcceptanceToken(PDO $pdo, string $token): ?array
{
    $token = trim($token);
    if ($token === '' || strlen($token) < 32) {
        return null;
    }

    ensureDocumentAcceptanceTables($pdo);
    $stmt = $pdo->prepare("SELECT
            t.id AS token_id,
            t.document_id,
            t.recipient_email,
            t.expires_at,
            t.used_at,
            t.revoked_at,
            d.*,
            k.nazwa_firmy AS client_name,
            cp.company_name AS owner_company_name
        FROM document_acceptance_tokens t
        INNER JOIN documents d ON d.id = t.document_id
        LEFT JOIN klienci k ON k.id = d.client_id
        LEFT JOIN company_profile cp ON cp.id = d.company_profile_id
        WHERE t.token_hash = :token_hash
        LIMIT 1");
    $stmt->execute([':token_hash' => documentAcceptanceTokenHash($token)]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    if (!$row) {
        return null;
    }

    if (!empty($row['used_at']) || !empty($row['revoked_at']) || strtotime((string)$row['expires_at']) <= time()) {
        return null;
    }

    return $row;
}

function logDocumentAcceptanceEvent(PDO $pdo, int $documentId, ?int $tokenId, string $action, array $context = []): void
{
    if (!$pdo->inTransaction()) {
        ensureDocumentAcceptanceTables($pdo);
    }
    $allowed = ['viewed', 'downloaded_pdf', 'accepted', 'rejected', 'expired', 'invalid'];
    if (!in_array($action, $allowed, true)) {
        throw new InvalidArgumentException('Niepoprawna akcja akceptacji.');
    }

    $stmt = $pdo->prepare('INSERT INTO document_acceptance_log
        (document_id, token_id, action, recipient_email, ip_address, user_agent, note, created_at)
        VALUES (:document_id, :token_id, :action, :recipient_email, :ip_address, :user_agent, :note, CURRENT_TIMESTAMP)');
    $stmt->execute([
        ':document_id' => max(0, $documentId),
        ':token_id' => $tokenId,
        ':action' => $action,
        ':recipient_email' => trim((string)($context['recipient_email'] ?? '')) ?: null,
        ':ip_address' => substr(trim((string)($context['ip_address'] ?? ($_SERVER['REMOTE_ADDR'] ?? ''))), 0, 45) ?: null,
        ':user_agent' => substr(trim((string)($context['user_agent'] ?? ($_SERVER['HTTP_USER_AGENT'] ?? ''))), 0, 255) ?: null,
        ':note' => trim((string)($context['note'] ?? '')) ?: null,
    ]);

    $auditMap = [
        'viewed' => ['document_online_viewed', 'Klient otworzyl link akceptacji online'],
        'downloaded_pdf' => ['document_online_pdf_downloaded', 'Klient pobral PDF online'],
        'accepted' => ['document_online_accepted', 'Klient zaakceptowal dokument online'],
        'rejected' => ['document_online_rejected', 'Klient odrzucil dokument online'],
    ];
    if ($documentId > 0 && isset($auditMap[$action])) {
        logDocumentAudit($pdo, $documentId, $auditMap[$action][0], $auditMap[$action][1], [
            'metadata' => [
                'token_id' => $tokenId,
                'recipient_email' => trim((string)($context['recipient_email'] ?? '')) ?: null,
                'note' => trim((string)($context['note'] ?? '')) ?: null,
            ],
            'ip_address' => $context['ip_address'] ?? ($_SERVER['REMOTE_ADDR'] ?? ''),
            'user_agent' => $context['user_agent'] ?? ($_SERVER['HTTP_USER_AGENT'] ?? ''),
        ]);
    }
}

function documentAcceptanceLoadTokenRow(PDO $pdo, string $token, bool $lock = false): ?array
{
    $sql = "SELECT t.*, d.status AS document_status, d.pdf_path, d.document_number
        FROM document_acceptance_tokens t
        INNER JOIN documents d ON d.id = t.document_id
        WHERE t.token_hash = :token_hash
        LIMIT 1";
    if ($lock && !isSqliteDriver($pdo)) {
        $sql .= ' FOR UPDATE';
    }
    $stmt = $pdo->prepare($sql);
    $stmt->execute([':token_hash' => documentAcceptanceTokenHash($token)]);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

function acceptDocumentByToken(PDO $pdo, string $token, array $context = []): bool
{
    ensureDocumentAcceptanceTables($pdo);
    ensureDocumentCampaignSyncLogTable($pdo);
    ensureDocumentAuditLogTable($pdo);
    $started = false;
    if (!$pdo->inTransaction()) {
        $pdo->beginTransaction();
        $started = true;
    }

    try {
        $row = documentAcceptanceLoadTokenRow($pdo, $token, true);
        if (!$row || !empty($row['used_at']) || !empty($row['revoked_at'])) {
            if ($started) {
                $pdo->commit();
            }
            return false;
        }
        $documentId = (int)$row['document_id'];
        $tokenId = (int)$row['id'];
        $recipientEmail = (string)$row['recipient_email'];

        if (strtotime((string)$row['expires_at']) <= time()) {
            logDocumentAcceptanceEvent($pdo, $documentId, $tokenId, 'expired', ['recipient_email' => $recipientEmail] + $context);
            if ($started) {
                $pdo->commit();
            }
            return false;
        }
        if ((string)$row['document_status'] !== 'sent') {
            logDocumentAcceptanceEvent($pdo, $documentId, $tokenId, 'invalid', ['recipient_email' => $recipientEmail, 'note' => 'Dokument nie jest w statusie sent.'] + $context);
            if ($started) {
                $pdo->commit();
            }
            return false;
        }
        if (!documentAcceptancePdfPath($row['pdf_path'] ?? null, $documentId)) {
            logDocumentAcceptanceEvent($pdo, $documentId, $tokenId, 'invalid', ['recipient_email' => $recipientEmail, 'note' => 'Brak PDF dokumentu.'] + $context);
            if ($started) {
                $pdo->commit();
            }
            return false;
        }

        transitionDocumentStatus($pdo, $documentId, 'accepted', [
            'accepted_by_name' => trim((string)($context['accepted_by_name'] ?? '')),
            'accepted_by_email' => $recipientEmail,
            'acceptance_ip' => (string)($context['ip_address'] ?? ($_SERVER['REMOTE_ADDR'] ?? '')),
            'acceptance_user_agent' => (string)($context['user_agent'] ?? ($_SERVER['HTTP_USER_AGENT'] ?? '')),
        ]);
        $pdo->prepare('UPDATE document_acceptance_tokens SET used_at = CURRENT_TIMESTAMP WHERE id = :id')
            ->execute([':id' => $tokenId]);
        logDocumentAcceptanceEvent($pdo, $documentId, $tokenId, 'accepted', ['recipient_email' => $recipientEmail] + $context);

        if ($started) {
            $pdo->commit();
        }
        return true;
    } catch (Throwable $e) {
        if ($started && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

function rejectDocumentByToken(PDO $pdo, string $token, array $context = []): bool
{
    ensureDocumentAcceptanceTables($pdo);
    ensureDocumentAuditLogTable($pdo);
    $started = false;
    if (!$pdo->inTransaction()) {
        $pdo->beginTransaction();
        $started = true;
    }

    try {
        $row = documentAcceptanceLoadTokenRow($pdo, $token, true);
        if (!$row || !empty($row['used_at']) || !empty($row['revoked_at'])) {
            if ($started) {
                $pdo->commit();
            }
            return false;
        }
        $documentId = (int)$row['document_id'];
        $tokenId = (int)$row['id'];
        $recipientEmail = (string)$row['recipient_email'];
        if (strtotime((string)$row['expires_at']) <= time()) {
            logDocumentAcceptanceEvent($pdo, $documentId, $tokenId, 'expired', ['recipient_email' => $recipientEmail] + $context);
            if ($started) {
                $pdo->commit();
            }
            return false;
        }

        $pdo->prepare('UPDATE document_acceptance_tokens SET used_at = CURRENT_TIMESTAMP WHERE id = :id')
            ->execute([':id' => $tokenId]);
        logDocumentAcceptanceEvent($pdo, $documentId, $tokenId, 'rejected', ['recipient_email' => $recipientEmail] + $context);

        if ($started) {
            $pdo->commit();
        }
        return true;
    } catch (Throwable $e) {
        if ($started && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}
