<?php
declare(strict_types=1);

require_once __DIR__ . '/db_schema.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/crypto.php';
require_once __DIR__ . '/mail.php';
require_once __DIR__ . '/mail_service.php';

function mailboxNormalizeSubject(string $subject): string {
    $subject = trim($subject);
    while (preg_match('/^(re|fwd|fw)\\s*:/i', $subject)) {
        $subject = preg_replace('/^(re|fwd|fw)\\s*:\\s*/i', '', $subject);
        $subject = trim((string)$subject);
    }
    return $subject;
}

function mailboxSubjectHash(string $subject): string {
    return hash('sha256', mailboxNormalizeSubject($subject));
}

function mailboxNormalizeEmailCandidates(array $emails): array {
    $normalized = [];
    foreach ($emails as $email) {
        $email = strtolower(trim((string)$email));
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            continue;
        }
        $normalized[$email] = $email;
    }
    return array_values($normalized);
}

function mailboxBuildEmailPlaceholders(array $emails, string $paramPrefix): array {
    $placeholders = [];
    $params = [];
    foreach ($emails as $idx => $email) {
        $ph = ':' . $paramPrefix . '_email_' . $idx;
        $placeholders[] = $ph;
        $params[$ph] = $email;
    }
    return [$placeholders, $params];
}

function mailboxBuildEmailMatch(array $columns, array $emails, string $paramPrefix): array {
    if (!$emails) {
        return ['', []];
    }

    $params = [];
    $clauses = [];
    if (hasColumn($columns, 'email')) {
        [$placeholders, $bind] = mailboxBuildEmailPlaceholders($emails, $paramPrefix . '_main');
        $clauses[] = 'LOWER(email) IN (' . implode(', ', $placeholders) . ')';
        $params = array_merge($params, $bind);
    }
    if (hasColumn($columns, 'kontakt_email')) {
        [$placeholders, $bind] = mailboxBuildEmailPlaceholders($emails, $paramPrefix . '_contact');
        $clauses[] = 'LOWER(kontakt_email) IN (' . implode(', ', $placeholders) . ')';
        $params = array_merge($params, $bind);
    }

    if (!$clauses) {
        return ['', []];
    }
    return ['(' . implode(' OR ', $clauses) . ')', $params];
}

function mailboxBuildMessageEmailMatchSql(array $mailColumns, array $emails, string $alias = 'm', string $paramPrefix = 'mail_msg'): array {
    $emails = mailboxNormalizeEmailCandidates($emails);
    if (!$emails) {
        return ['', []];
    }

    $clauses = [];
    $params = [];

    if (hasColumn($mailColumns, 'from_email')) {
        [$placeholders, $bind] = mailboxBuildEmailPlaceholders($emails, $paramPrefix . '_from');
        $clauses[] = 'LOWER(' . $alias . '.from_email) IN (' . implode(', ', $placeholders) . ')';
        $params = array_merge($params, $bind);
    }

    $containsColumns = ['to_email', 'to_emails', 'cc_email', 'cc_emails', 'bcc_email'];
    foreach ($containsColumns as $column) {
        if (!hasColumn($mailColumns, $column)) {
            continue;
        }
        foreach ($emails as $idx => $email) {
            $placeholder = ':' . $paramPrefix . '_' . $column . '_' . $idx;
            $clauses[] = 'LOWER(' . $alias . '.' . $column . ') LIKE ' . $placeholder;
            $params[$placeholder] = '%' . $email . '%';
        }
    }

    if (!$clauses) {
        return ['', []];
    }
    return ['(' . implode(' OR ', $clauses) . ')', $params];
}

function mailboxResolveEntityFromTable(PDO $pdo, string $table, string $entityType, array $columns, array $emails, array $options = []): ?array {
    [$emailMatchSql, $params] = mailboxBuildEmailMatch($columns, $emails, $table);
    if ($emailMatchSql === '') {
        return null;
    }

    $restrictToOwner = !empty($options['restrict_to_owner']);
    $ownerUserId = (int)($options['owner_user_id'] ?? 0);
    $includeUnassignedLeads = !empty($options['include_unassigned_leads']);

    $sql = 'SELECT id FROM ' . $table . ' WHERE ' . $emailMatchSql;
    if ($restrictToOwner && $ownerUserId > 0 && hasColumn($columns, 'owner_user_id')) {
        if ($table === 'leady' && $includeUnassignedLeads) {
            $sql .= ' AND (owner_user_id = :owner_user_id OR owner_user_id IS NULL OR owner_user_id = 0)';
            $sql .= ' ORDER BY CASE WHEN owner_user_id = :owner_user_id_sort THEN 0 ELSE 1 END, id DESC';
            $params[':owner_user_id_sort'] = $ownerUserId;
        } else {
            $sql .= ' AND owner_user_id = :owner_user_id';
            $sql .= ' ORDER BY id DESC';
        }
        $params[':owner_user_id'] = $ownerUserId;
    } else {
        $sql .= ' ORDER BY id DESC';
    }
    $sql .= ' LIMIT 1';

    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $entityId = $stmt->fetchColumn() ?: null;
        $stmt->closeCursor();
    } catch (Throwable $e) {
        error_log('mailbox: cannot match ' . $entityType . ' by email: ' . $e->getMessage());
        return null;
    }

    if (!$entityId) {
        return null;
    }
    return ['entity_type' => $entityType, 'entity_id' => (int)$entityId];
}

function resolveEntityByEmails(PDO $pdo, array $emails, array $options = []): ?array {
    $emails = mailboxNormalizeEmailCandidates($emails);
    if (!$emails) {
        return null;
    }

    if (tableExists($pdo, 'klienci')) {
        ensureClientLeadColumns($pdo);
        $clientCols = getTableColumns($pdo, 'klienci');
        $client = mailboxResolveEntityFromTable($pdo, 'klienci', 'client', $clientCols, $emails, $options);
        if ($client) {
            return $client;
        }
    }

    if (tableExists($pdo, 'leady')) {
        ensureLeadColumns($pdo);
        $leadCols = getTableColumns($pdo, 'leady');
        $lead = mailboxResolveEntityFromTable($pdo, 'leady', 'lead', $leadCols, $emails, $options);
        if ($lead) {
            return $lead;
        }
    }

    return null;
}

function resolveEntityByEmail(PDO $pdo, string $email, array $options = []): ?array {
    return resolveEntityByEmails($pdo, [$email], $options);
}

function mailboxCrmType(string $entityType): string {
    return $entityType === 'client' ? 'klient' : $entityType;
}

function mailboxEnsureMailAccount(PDO $pdo, int $userId): ?array {
    return getMailAccountForUser($pdo, $userId);
}

function mailboxDecryptPassword(?string $cipherText): string {
    try {
        return decryptMailSecret($cipherText);
    } catch (Throwable $e) {
        return '';
    }
}

function mailboxGenerateMessageId(string $seed, string $emailAddress): string {
    $domain = 'local';
    $emailAddress = trim($emailAddress);
    if ($emailAddress !== '' && strpos($emailAddress, '@') !== false) {
        $domain = substr($emailAddress, strpos($emailAddress, '@') + 1);
    }
    $hash = hash('sha256', $seed);
    return '<crm-' . substr($hash, 0, 20) . '@' . $domain . '>';
}

function mailboxFindOrCreateThread(PDO $pdo, ?string $entityType, ?int $entityId, string $subject, string $messageAt): ?int {
    if (!$entityType || !$entityId || $entityId <= 0) {
        return null;
    }
    ensureCrmMailTables($pdo);
    $subjectHash = mailboxSubjectHash($subject);

    try {
        $stmt = $pdo->prepare('SELECT id, last_message_at FROM mail_threads WHERE entity_type = :type AND entity_id = :id AND subject_hash = :hash LIMIT 1');
        $stmt->execute([
            ':type' => $entityType,
            ':id' => $entityId,
            ':hash' => $subjectHash,
        ]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        $stmt->closeCursor();
        if ($row) {
            $threadId = (int)$row['id'];
            $existingAt = trim((string)($row['last_message_at'] ?? ''));
            if ($existingAt !== '') {
                $existingTs = strtotime($existingAt) ?: 0;
                $messageTs = strtotime($messageAt) ?: 0;
                if ($existingTs > $messageTs) {
                    $messageAt = $existingAt;
                }
            }
            $stmtUp = $pdo->prepare('UPDATE mail_threads SET last_message_at = :last_message_at WHERE id = :id');
            $stmtUp->execute([
                ':last_message_at' => $messageAt,
                ':id' => $threadId,
            ]);
            return $threadId;
        }
    } catch (Throwable $e) {
        error_log('mailbox: cannot find thread: ' . $e->getMessage());
    }

    try {
        $stmt = $pdo->prepare(
            'INSERT INTO mail_threads (entity_type, entity_id, subject, subject_hash, last_message_at)
             VALUES (:type, :id, :subject, :hash, :last_message_at)'
        );
        $stmt->execute([
            ':type' => $entityType,
            ':id' => $entityId,
            ':subject' => $subject !== '' ? $subject : null,
            ':hash' => $subjectHash,
            ':last_message_at' => $messageAt,
        ]);
        return (int)$pdo->lastInsertId();
    } catch (Throwable $e) {
        error_log('mailbox: cannot create thread: ' . $e->getMessage());
        return null;
    }
}

function mailboxNormalizeUploadedFiles($files): array {
    if (!is_array($files) || empty($files['name'])) {
        return [];
    }
    $result = [];
    if (is_array($files['name'])) {
        $count = count($files['name']);
        for ($i = 0; $i < $count; $i++) {
            $result[] = [
                'name' => (string)($files['name'][$i] ?? ''),
                'type' => (string)($files['type'][$i] ?? ''),
                'tmp_name' => (string)($files['tmp_name'][$i] ?? ''),
                'error' => (int)($files['error'][$i] ?? UPLOAD_ERR_NO_FILE),
                'size' => (int)($files['size'][$i] ?? 0),
            ];
        }
    } else {
        $result[] = [
            'name' => (string)($files['name'] ?? ''),
            'type' => (string)($files['type'] ?? ''),
            'tmp_name' => (string)($files['tmp_name'] ?? ''),
            'error' => (int)($files['error'] ?? UPLOAD_ERR_NO_FILE),
            'size' => (int)($files['size'] ?? 0),
        ];
    }
    return $result;
}

function mailboxSanitizeAttachmentName(string $name): string {
    $name = basename($name);
    $name = preg_replace('/[^A-Za-z0-9._-]/', '_', $name);
    if ($name === '' || $name === '.' || $name === '..') {
        $name = 'attachment';
    }
    return $name;
}

function mailboxEnsureAttachmentDir(?string $entityType, ?int $entityId, int $mailMessageId): ?string {
    $base = dirname(__DIR__, 2) . '/storage/mail_attachments';
    if (!is_dir($base) && !@mkdir($base, 0775, true) && !is_dir($base)) {
        return null;
    }
    $typePart = in_array($entityType, ['lead', 'client'], true) ? $entityType : 'unassigned';
    $idPart = $entityId && $entityId > 0 ? (string)$entityId : '0';
    $dir = $base . '/' . $typePart . '/' . $idPart . '/' . $mailMessageId;
    if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
        return null;
    }
    if (!is_writable($dir)) {
        return null;
    }
    return $dir;
}

function mailboxStoreUploadedAttachments(PDO $pdo, int $mailMessageId, ?string $entityType, ?int $entityId, array $files, int $maxBytes, array &$errors): array {
    $attachments = mailboxNormalizeUploadedFiles($files);
    if (!$attachments) {
        return [];
    }

    $stored = [];
    foreach ($attachments as $attachment) {
        $error = (int)($attachment['error'] ?? UPLOAD_ERR_NO_FILE);
        if ($error === UPLOAD_ERR_NO_FILE) {
            continue;
        }
        if ($error !== UPLOAD_ERR_OK) {
            $errors[] = 'Blad zalacznika: ' . ($attachment['name'] ?? 'plik');
            continue;
        }
        $size = (int)($attachment['size'] ?? 0);
        if ($maxBytes > 0 && $size > $maxBytes) {
            $errors[] = 'Zalacznik jest za duzy: ' . ($attachment['name'] ?? 'plik');
            continue;
        }
        $filename = mailboxSanitizeAttachmentName((string)($attachment['name'] ?? 'attachment'));
        if (preg_match('/\\.php\\d*$/i', $filename)) {
            continue;
        }
        $dir = mailboxEnsureAttachmentDir($entityType, $entityId, $mailMessageId);
        if ($dir === null) {
            $errors[] = 'Nie udalo sie przygotowac katalogu zalacznikow.';
            continue;
        }
        $storedName = uniqid('att_', true) . '_' . $filename;
        $targetPath = $dir . '/' . $storedName;
        $tmpName = (string)($attachment['tmp_name'] ?? '');
        if ($tmpName === '' || !is_uploaded_file($tmpName)) {
            $errors[] = 'Nieprawidlowy plik tymczasowy: ' . $filename;
            continue;
        }
        if (!@move_uploaded_file($tmpName, $targetPath)) {
            $errors[] = 'Nie udalo sie zapisac zalacznika: ' . $filename;
            continue;
        }
        $relativePath = 'storage/mail_attachments/' . (in_array($entityType, ['lead', 'client'], true) ? $entityType : 'unassigned')
            . '/' . ($entityId && $entityId > 0 ? (string)$entityId : '0') . '/' . $mailMessageId . '/' . $storedName;

        $stmt = $pdo->prepare(
            'INSERT INTO mail_attachments (mail_message_id, filename, stored_path, storage_path, mime_type, size_bytes)
             VALUES (:mail_message_id, :filename, :stored_path, :storage_path, :mime_type, :size_bytes)'
        );
        $stmt->execute([
            ':mail_message_id' => $mailMessageId,
            ':filename' => $filename,
            ':stored_path' => $relativePath,
            ':storage_path' => $relativePath,
            ':mime_type' => (string)($attachment['type'] ?? null),
            ':size_bytes' => $size > 0 ? $size : null,
        ]);
        $stored[] = [
            'path' => $targetPath,
            'filename' => $filename,
            'mime_type' => (string)($attachment['type'] ?? ''),
        ];
    }

    return $stored;
}

function mailboxStoreRawAttachment(PDO $pdo, int $mailMessageId, ?string $entityType, ?int $entityId, string $filename, string $mimeType, string $data, int $maxBytes): bool {
    if ($maxBytes > 0 && strlen($data) > $maxBytes) {
        return false;
    }
    $filename = mailboxSanitizeAttachmentName($filename);
    if (preg_match('/\\.php\\d*$/i', $filename)) {
        return false;
    }
    $dir = mailboxEnsureAttachmentDir($entityType, $entityId, $mailMessageId);
    if ($dir === null) {
        return false;
    }
    $storedName = uniqid('att_', true) . '_' . $filename;
    $targetPath = $dir . '/' . $storedName;
    if (@file_put_contents($targetPath, $data) === false) {
        return false;
    }
    $relativePath = 'storage/mail_attachments/' . (in_array($entityType, ['lead', 'client'], true) ? $entityType : 'unassigned')
        . '/' . ($entityId && $entityId > 0 ? (string)$entityId : '0') . '/' . $mailMessageId . '/' . $storedName;

    $stmt = $pdo->prepare(
        'INSERT INTO mail_attachments (mail_message_id, filename, stored_path, storage_path, mime_type, size_bytes)
         VALUES (:mail_message_id, :filename, :stored_path, :storage_path, :mime_type, :size_bytes)'
    );
    $stmt->execute([
        ':mail_message_id' => $mailMessageId,
        ':filename' => $filename,
        ':stored_path' => $relativePath,
        ':storage_path' => $relativePath,
        ':mime_type' => $mimeType !== '' ? $mimeType : null,
        ':size_bytes' => strlen($data),
    ]);
    return true;
}

function mailboxInsertMessage(PDO $pdo, array $data): int {
    $stmt = $pdo->prepare(
        'INSERT INTO mail_messages (client_id, lead_id, campaign_id, owner_user_id, mail_account_id, thread_id, direction, from_email, from_name, to_email, to_emails, cc_email, cc_emails, bcc_email, subject, body_html, body_text, status, error_message, message_id, in_reply_to, references_header, imap_uid, imap_mailbox, received_at, sent_at, has_attachments, entity_type, entity_id, created_by_user_id)
         VALUES (:client_id, :lead_id, :campaign_id, :owner_user_id, :mail_account_id, :thread_id, :direction, :from_email, :from_name, :to_email, :to_emails, :cc_email, :cc_emails, :bcc_email, :subject, :body_html, :body_text, :status, :error_message, :message_id, :in_reply_to, :references_header, :imap_uid, :imap_mailbox, :received_at, :sent_at, :has_attachments, :entity_type, :entity_id, :created_by_user_id)'
    );
    $stmt->execute([
        ':client_id' => $data['client_id'] ?? null,
        ':lead_id' => $data['lead_id'] ?? null,
        ':campaign_id' => $data['campaign_id'] ?? null,
        ':owner_user_id' => $data['owner_user_id'] ?? null,
        ':mail_account_id' => $data['mail_account_id'],
        ':thread_id' => $data['thread_id'] ?? null,
        ':direction' => $data['direction'],
        ':from_email' => $data['from_email'] ?? null,
        ':from_name' => $data['from_name'] ?? null,
        ':to_email' => $data['to_email'] ?? null,
        ':to_emails' => $data['to_emails'] ?? null,
        ':cc_email' => $data['cc_email'] ?? null,
        ':cc_emails' => $data['cc_emails'] ?? null,
        ':bcc_email' => $data['bcc_email'] ?? null,
        ':subject' => $data['subject'] ?? null,
        ':body_html' => $data['body_html'] ?? null,
        ':body_text' => $data['body_text'] ?? null,
        ':status' => $data['status'] ?? 'SENT',
        ':error_message' => $data['error_message'] ?? null,
        ':message_id' => $data['message_id'],
        ':in_reply_to' => $data['in_reply_to'] ?? null,
        ':references_header' => $data['references_header'] ?? null,
        ':imap_uid' => $data['imap_uid'] ?? null,
        ':imap_mailbox' => $data['imap_mailbox'] ?? null,
        ':received_at' => $data['received_at'] ?? null,
        ':sent_at' => $data['sent_at'] ?? null,
        ':has_attachments' => $data['has_attachments'] ?? 0,
        ':entity_type' => $data['entity_type'] ?? null,
        ':entity_id' => $data['entity_id'] ?? null,
        ':created_by_user_id' => $data['created_by_user_id'] ?? null,
    ]);
    return (int)$pdo->lastInsertId();
}

function mailboxSanitizeMailError(?string $message): string {
    $message = trim((string)$message);
    if ($message === '') {
        return 'Nieznany blad wysylki.';
    }
    $message = preg_replace('/(pass(word)?\\s*[:=]\\s*)\\S+/i', '$1***', $message);
    $message = preg_replace('/(haslo\\s*[:=]\\s*)\\S+/i', '$1***', $message);
    if (strlen($message) > 400) {
        $message = substr($message, 0, 400) . '...';
    }
    return $message;
}
