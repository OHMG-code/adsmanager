<?php
declare(strict_types=1);

require_once __DIR__ . '/crypto.php';
require_once __DIR__ . '/db_schema.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/mailbox_service.php';
require_once __DIR__ . '/crm_activity.php';

function imapHeaderDecode(?string $value): string {
    $value = (string)($value ?? '');
    if ($value === '') {
        return '';
    }
    if (!function_exists('imap_mime_header_decode')) {
        return $value;
    }
    $decoded = imap_mime_header_decode($value);
    if (!$decoded) {
        return $value;
    }
    $out = '';
    foreach ($decoded as $part) {
        $charset = strtoupper((string)($part->charset ?? 'UTF-8'));
        $text = (string)($part->text ?? '');
        if ($charset !== 'DEFAULT' && $charset !== 'UTF-8') {
            $converted = @iconv($charset, 'UTF-8//TRANSLIT//IGNORE', $text);
            $out .= $converted !== false ? $converted : $text;
        } else {
            $out .= $text;
        }
    }
    return $out;
}

function imapAddressToEmail(?string $mailbox, ?string $host): string {
    $mailbox = trim((string)$mailbox);
    $host = trim((string)$host);
    if ($mailbox === '' || $host === '') {
        return '';
    }
    return $mailbox . '@' . $host;
}

function imapAddressListToEmails($addresses): array {
    if (!is_array($addresses)) {
        return [];
    }
    $out = [];
    foreach ($addresses as $addr) {
        $email = imapAddressToEmail($addr->mailbox ?? '', $addr->host ?? '');
        if ($email !== '') {
            $out[strtolower($email)] = $email;
        }
    }
    return array_values($out);
}

function imapAddressListToString($addresses): string {
    if (!is_array($addresses)) {
        return '';
    }
    $out = [];
    foreach ($addresses as $addr) {
        $email = imapAddressToEmail($addr->mailbox ?? '', $addr->host ?? '');
        $name = imapHeaderDecode($addr->personal ?? '');
        if ($email === '') {
            continue;
        }
        $out[] = $name !== '' ? $name . ' <' . $email . '>' : $email;
    }
    return implode(', ', $out);
}

function buildImapMailboxString(array $config): string {
    $host = trim((string)($config['host'] ?? ''));
    $port = (int)($config['port'] ?? 0);
    $secure = (string)($config['secure'] ?? '');
    $mailbox = trim((string)($config['mailbox'] ?? 'INBOX'));
    if ($port <= 0) {
        $port = $secure === 'ssl' ? 993 : 143;
    }
    $flags = '/imap';
    if ($secure === 'ssl') {
        $flags .= '/ssl';
    } elseif ($secure === 'tls') {
        $flags .= '/tls';
    }
    $flags .= '/novalidate-cert';

    return sprintf('{%s:%d%s}%s', $host, $port, $flags, $mailbox !== '' ? $mailbox : 'INBOX');
}

function resolveImapConfig(array $user): array {
    $password = '';
    try {
        $password = decryptSecret((string)($user['imap_pass_enc'] ?? ''));
    } catch (Throwable $e) {
        $password = '';
    }
    return [
        'enabled' => !empty($user['imap_enabled']),
        'host' => trim((string)($user['imap_host'] ?? '')),
        'port' => (int)($user['imap_port'] ?? 0),
        'secure' => in_array($user['imap_secure'] ?? '', ['', 'tls', 'ssl'], true) ? (string)($user['imap_secure'] ?? '') : '',
        'username' => trim((string)($user['imap_user'] ?? '')),
        'password' => $password,
        'mailbox' => trim((string)($user['imap_mailbox'] ?? 'INBOX')),
    ];
}

function decodeImapPart(string $data, int $encoding): string {
    if ($data === '') {
        return '';
    }
    switch ($encoding) {
        case 3: // BASE64
            return base64_decode($data, true) ?: '';
        case 4: // QUOTED-PRINTABLE
            return quoted_printable_decode($data);
        default:
            return $data;
    }
}

function collectImapParts(object $structure, string $prefix = ''): array {
    $parts = [];
    if (!empty($structure->parts) && is_array($structure->parts)) {
        foreach ($structure->parts as $idx => $part) {
            $number = $prefix === '' ? (string)($idx + 1) : $prefix . '.' . (string)($idx + 1);
            $parts = array_merge($parts, collectImapParts($part, $number));
        }
        return $parts;
    }
    $parts[] = [
        'number' => $prefix === '' ? '1' : $prefix,
        'part' => $structure,
    ];
    return $parts;
}

function getImapPartFilename(object $part): string {
    $candidates = [];
    if (!empty($part->dparameters)) {
        foreach ($part->dparameters as $param) {
            if (!empty($param->attribute) && strtolower((string)$param->attribute) === 'filename') {
                $candidates[] = (string)$param->value;
            }
        }
    }
    if (!empty($part->parameters)) {
        foreach ($part->parameters as $param) {
            if (!empty($param->attribute) && strtolower((string)$param->attribute) === 'name') {
                $candidates[] = (string)$param->value;
            }
        }
    }
    foreach ($candidates as $candidate) {
        $candidate = imapHeaderDecode($candidate);
        if ($candidate !== '') {
            return $candidate;
        }
    }
    return '';
}

function getImapPartCharset(object $part): string {
    if (!empty($part->parameters)) {
        foreach ($part->parameters as $param) {
            if (!empty($param->attribute) && strtolower((string)$param->attribute) === 'charset') {
                return strtoupper(trim((string)$param->value));
            }
        }
    }
    return 'UTF-8';
}

function extractImapMessageParts($imap, int $uid, int $maxAttachmentBytes): array {
    $bodyText = '';
    $bodyHtml = '';
    $attachments = [];

    $structure = imap_fetchstructure($imap, $uid, FT_UID);
    if (!$structure) {
        $raw = imap_body($imap, $uid, FT_UID);
        return ['text' => $raw ? trim($raw) : '', 'html' => '', 'attachments' => []];
    }

    $parts = collectImapParts($structure);
    foreach ($parts as $item) {
        $part = $item['part'];
        $number = $item['number'];
        $type = (int)($part->type ?? 0);
        $subtype = strtoupper((string)($part->subtype ?? ''));
        $disposition = strtoupper((string)($part->disposition ?? ''));
        $filename = getImapPartFilename($part);

        $data = imap_fetchbody($imap, $uid, $number, FT_UID);
        $decoded = decodeImapPart($data !== false ? $data : '', (int)($part->encoding ?? 0));
        if ($type === 0) {
            $charset = getImapPartCharset($part);
            if ($charset !== '' && $charset !== 'UTF-8' && $charset !== 'DEFAULT') {
                $converted = @iconv($charset, 'UTF-8//TRANSLIT//IGNORE', $decoded);
                if ($converted !== false) {
                    $decoded = $converted;
                }
            }
        }

        if ($type === 0 && $disposition !== 'ATTACHMENT' && $filename === '') {
            if ($subtype === 'HTML' && $bodyHtml === '') {
                $bodyHtml = trim($decoded);
                continue;
            }
            if ($subtype === 'PLAIN' && $bodyText === '') {
                $bodyText = trim($decoded);
                continue;
            }
        }

        if ($filename !== '' || $disposition === 'ATTACHMENT' || $disposition === 'INLINE') {
            if ($maxAttachmentBytes > 0 && strlen($decoded) > $maxAttachmentBytes) {
                continue;
            }
            $attachments[] = [
                'filename' => $filename !== '' ? $filename : ('attachment-' . $number),
                'mime_type' => ($type === 0 ? 'text/' . strtolower($subtype) : 'application/octet-stream'),
                'data' => $decoded,
            ];
        }
    }

    return [
        'text' => $bodyText,
        'html' => $bodyHtml,
        'attachments' => $attachments,
    ];
}

function resolveEmailAssignment(PDO $pdo, array $currentUser, string $email): array {
    $email = strtolower(trim($email));
    if ($email === '') {
        return ['client_id' => null, 'lead_id' => null];
    }
    $clientsCols = getTableColumns($pdo, 'klienci');
    $leadsCols = getTableColumns($pdo, 'leady');

    $clientId = null;
    $leadId = null;

    try {
        if (hasColumn($clientsCols, 'email')) {
            $sql = "SELECT id FROM klienci WHERE LOWER(email) = :email";
            $params = [':email' => $email];
            if (normalizeRole($currentUser) === 'Handlowiec' && hasColumn($clientsCols, 'owner_user_id')) {
                $sql .= " AND owner_user_id = :owner_user_id";
                $params[':owner_user_id'] = (int)$currentUser['id'];
            }
            $stmt = $pdo->prepare($sql . " LIMIT 1");
            $stmt->execute($params);
            $clientId = $stmt->fetchColumn() ?: null;
        }
    } catch (Throwable $e) {
        error_log('imap_service: cannot match client by email: ' . $e->getMessage());
    }

    try {
        if (!$clientId && hasColumn($leadsCols, 'email')) {
            $sql = "SELECT id, client_id FROM leady WHERE LOWER(email) = :email";
            $params = [':email' => $email];
            if (normalizeRole($currentUser) === 'Handlowiec') {
                if (hasColumn($leadsCols, 'owner_user_id')) {
                    $sql .= " AND owner_user_id = :owner_user_id";
                    $params[':owner_user_id'] = (int)$currentUser['id'];
                } elseif (hasColumn($leadsCols, 'assigned_user_id')) {
                    $sql .= " AND assigned_user_id = :assigned_user_id";
                    $params[':assigned_user_id'] = (int)$currentUser['id'];
                }
            }
            $stmt = $pdo->prepare($sql . " LIMIT 1");
            $stmt->execute($params);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($row) {
                $leadId = (int)$row['id'];
                if (!empty($row['client_id'])) {
                    $clientId = (int)$row['client_id'];
                }
            }
        }
    } catch (Throwable $e) {
        error_log('imap_service: cannot match lead by email: ' . $e->getMessage());
    }

    return ['client_id' => $clientId, 'lead_id' => $leadId];
}

function imapSyncMailbox(PDO $pdo, array $user, array $options = []): array {
    if (!function_exists('imap_open')) {
        return ['ok' => false, 'error' => 'IMAP extension is not available.', 'synced' => 0];
    }

    $config = resolveImapConfig($user);
    if (empty($config['enabled'])) {
        return ['ok' => false, 'error' => 'IMAP is disabled for this user.', 'synced' => 0];
    }
    if ($config['host'] === '' || $config['username'] === '' || $config['password'] === '') {
        return ['ok' => false, 'error' => 'Missing IMAP credentials.', 'synced' => 0];
    }

    $mailbox = buildImapMailboxString($config);
    $imap = @imap_open($mailbox, $config['username'], $config['password']);
    if (!$imap) {
        $error = imap_last_error();
        return ['ok' => false, 'error' => $error ?: 'Cannot connect to IMAP.', 'synced' => 0];
    }

    $limit = (int)($options['limit'] ?? 50);
    $maxAttachmentBytes = (int)($options['max_attachment_bytes'] ?? (10 * 1024 * 1024));
    $synced = 0;
    $maxUid = (int)($user['imap_last_uid'] ?? 0);

    try {
        if ($maxUid > 0) {
            $uids = imap_search($imap, 'UID ' . ($maxUid + 1) . ':*', SE_UID);
        } else {
            $uids = imap_search($imap, 'ALL', SE_UID);
            if (is_array($uids)) {
                $uids = array_slice($uids, max(0, count($uids) - $limit));
            }
        }
        if (!is_array($uids)) {
            $uids = [];
        }
        sort($uids);

        foreach ($uids as $uid) {
            $uid = (int)$uid;
            if ($uid <= 0) {
                continue;
            }

            $existsStmt = $pdo->prepare('SELECT id FROM mail_messages WHERE owner_user_id = :owner AND imap_uid = :uid AND imap_mailbox = :mbox LIMIT 1');
            $existsStmt->execute([
                ':owner' => (int)$user['id'],
                ':uid' => $uid,
                ':mbox' => $config['mailbox'],
            ]);
            if ($existsStmt->fetchColumn()) {
                $maxUid = max($maxUid, $uid);
                continue;
            }

            $headerText = imap_fetchheader($imap, $uid, FT_UID) ?: '';
            $header = imap_rfc822_parse_headers($headerText);

            $fromEmailList = imapAddressListToEmails($header->from ?? null);
            $fromEmail = $fromEmailList[0] ?? '';
            $fromName = '';
            if (!empty($header->from[0]->personal)) {
                $fromName = imapHeaderDecode($header->from[0]->personal);
            }

            $toList = imapAddressListToString($header->to ?? null);
            $ccList = imapAddressListToString($header->cc ?? null);
            $bccList = imapAddressListToString($header->bcc ?? null);

            $subject = imapHeaderDecode($header->subject ?? '');
            $messageId = trim((string)($header->message_id ?? ''));
            $inReplyTo = trim((string)($header->in_reply_to ?? ''));
            $references = trim((string)($header->references ?? ''));

            $receivedAt = null;
            if (!empty($header->date)) {
                try {
                    $dt = new DateTime($header->date);
                    $receivedAt = $dt->format('Y-m-d H:i:s');
                } catch (Throwable $e) {
                    $receivedAt = null;
                }
            }

            $parts = extractImapMessageParts($imap, $uid, $maxAttachmentBytes);

            $assignment = resolveEmailAssignment($pdo, $user, $fromEmail);
            $clientId = $assignment['client_id'];
            $leadId = $assignment['lead_id'];

            if ($messageId === '') {
                $messageId = mailboxGenerateMessageId('imap:' . (int)$user['id'] . ':' . $uid . ':' . $config['mailbox'], (string)($user['imap_user'] ?? ''));
            }

            $stmt = $pdo->prepare(
                'INSERT INTO mail_messages (client_id, lead_id, campaign_id, owner_user_id, direction, from_email, from_name, to_email, cc_email, bcc_email, subject, body_html, body_text, status, error_message, message_id, in_reply_to, references_header, imap_uid, imap_mailbox, received_at)
                 VALUES (:client_id, :lead_id, :campaign_id, :owner_user_id, :direction, :from_email, :from_name, :to_email, :cc_email, :bcc_email, :subject, :body_html, :body_text, :status, :error_message, :message_id, :in_reply_to, :references_header, :imap_uid, :imap_mailbox, :received_at)'
            );
            $stmt->execute([
                ':client_id' => $clientId,
                ':lead_id' => $leadId,
                ':campaign_id' => null,
                ':owner_user_id' => (int)$user['id'],
                ':direction' => 'IN',
                ':from_email' => $fromEmail,
                ':from_name' => $fromName,
                ':to_email' => $toList,
                ':cc_email' => $ccList,
                ':bcc_email' => $bccList,
                ':subject' => $subject,
                ':body_html' => $parts['html'],
                ':body_text' => $parts['text'],
                ':status' => 'RECEIVED',
                ':error_message' => null,
                ':message_id' => $messageId,
                ':in_reply_to' => $inReplyTo !== '' ? $inReplyTo : null,
                ':references_header' => $references !== '' ? $references : null,
                ':imap_uid' => $uid,
                ':imap_mailbox' => $config['mailbox'],
                ':received_at' => $receivedAt,
            ]);
            $mailMessageId = (int)$pdo->lastInsertId();

            if ($mailMessageId > 0 && !empty($parts['attachments'])) {
                $clientFolder = $clientId ? (string)$clientId : '0';
                $baseDir = dirname(__DIR__) . '/storage/mail_attachments/' . $clientFolder . '/' . $mailMessageId;
                if (!is_dir($baseDir)) {
                    @mkdir($baseDir, 0775, true);
                }

                foreach ($parts['attachments'] as $attachment) {
                    $filename = basename((string)$attachment['filename']);
                    if ($filename === '' || preg_match('/\\.php\\d*$/i', $filename)) {
                        continue;
                    }
                    $storedName = uniqid('att_', true) . '_' . $filename;
                    $storedPath = $baseDir . '/' . $storedName;
                    if (@file_put_contents($storedPath, $attachment['data']) === false) {
                        continue;
                    }
                    $relativePath = 'storage/mail_attachments/' . $clientFolder . '/' . $mailMessageId . '/' . $storedName;
                    $stmtAtt = $pdo->prepare(
                        'INSERT INTO mail_attachments (mail_message_id, filename, stored_path, storage_path, mime_type, size_bytes)
                         VALUES (:mail_message_id, :filename, :stored_path, :storage_path, :mime_type, :size_bytes)'
                    );
                    $stmtAtt->execute([
                        ':mail_message_id' => $mailMessageId,
                        ':filename' => $filename,
                        ':stored_path' => $relativePath,
                        ':storage_path' => $relativePath,
                        ':mime_type' => (string)($attachment['mime_type'] ?? null),
                        ':size_bytes' => strlen((string)$attachment['data']),
                    ]);
                }
            }

            $synced++;
            if ($uid > $maxUid) {
                $maxUid = $uid;
            }
        }
    } catch (Throwable $e) {
        imap_close($imap);
        return ['ok' => false, 'error' => $e->getMessage(), 'synced' => $synced];
    }

    imap_close($imap);

    try {
        $stmt = $pdo->prepare('UPDATE uzytkownicy SET imap_last_uid = :uid, imap_last_sync_at = NOW() WHERE id = :id');
        $stmt->execute([':uid' => $maxUid > 0 ? $maxUid : null, ':id' => (int)$user['id']]);
    } catch (Throwable $e) {
        error_log('imap_service: cannot update imap sync state: ' . $e->getMessage());
    }

    return ['ok' => true, 'error' => '', 'synced' => $synced];
}

function imapSyncMailAccount(PDO $pdo, array $mailAccount, array $options = []): array {
    if (!function_exists('imap_open')) {
        return ['ok' => false, 'error' => 'IMAP extension is not available.', 'synced' => 0];
    }
    if (empty($mailAccount['is_active'])) {
        return ['ok' => false, 'error' => 'Konto poczty jest nieaktywne.', 'synced' => 0];
    }
    try {
        mailSecretKey();
    } catch (Throwable $e) {
        return ['ok' => false, 'error' => $e->getMessage(), 'synced' => 0];
    }

    $host = trim((string)($mailAccount['imap_host'] ?? ''));
    $username = trim((string)($mailAccount['username'] ?? ''));
    $password = mailboxDecryptPassword((string)($mailAccount['password_enc'] ?? ''));
    if ($host === '' || $username === '' || $password === '') {
        return ['ok' => false, 'error' => 'Brak konfiguracji IMAP dla konta pocztowego.', 'synced' => 0];
    }

    $secure = (string)($mailAccount['imap_encryption'] ?? 'ssl');
    $secure = $secure === 'none' ? '' : $secure;
    $config = [
        'host' => $host,
        'port' => (int)($mailAccount['imap_port'] ?? 993),
        'secure' => $secure,
        'mailbox' => trim((string)($mailAccount['imap_mailbox'] ?? 'INBOX')) ?: 'INBOX',
    ];
    $mailbox = buildImapMailboxString($config);
    $imap = @imap_open($mailbox, $username, $password);
    if (!$imap) {
        $error = imap_last_error();
        return ['ok' => false, 'error' => $error ?: 'Nie mozna polaczyc z IMAP.', 'synced' => 0];
    }

    $limit = (int)($options['limit'] ?? 50);
    $limit = max(1, min(200, $limit));
    $maxAttachmentBytes = (int)($options['max_attachment_bytes'] ?? (10 * 1024 * 1024));
    $synced = 0;

    try {
        $uids = imap_sort($imap, SORTDATE, 1, SE_UID);
        if (!is_array($uids)) {
            $uids = [];
        }
        if (count($uids) > $limit) {
            $uids = array_slice($uids, -$limit);
        }

        foreach ($uids as $uid) {
            $uid = (int)$uid;
            if ($uid <= 0) {
                continue;
            }

            $headerText = imap_fetchheader($imap, $uid, FT_UID) ?: '';
            $header = imap_rfc822_parse_headers($headerText);

            $fromEmailList = imapAddressListToEmails($header->from ?? null);
            $fromEmail = $fromEmailList[0] ?? '';
            $fromName = '';
            if (!empty($header->from[0]->personal)) {
                $fromName = imapHeaderDecode($header->from[0]->personal);
            }

            $toList = imapAddressListToEmails($header->to ?? null);
            $ccList = imapAddressListToEmails($header->cc ?? null);

            $toEmails = $toList ? implode(', ', $toList) : '';
            $ccEmails = $ccList ? implode(', ', $ccList) : '';

            $toLabel = imapAddressListToString($header->to ?? null);
            $ccLabel = imapAddressListToString($header->cc ?? null);
            $bccLabel = imapAddressListToString($header->bcc ?? null);

            $subject = imapHeaderDecode($header->subject ?? '');
            $messageId = trim((string)($header->message_id ?? ''));
            $inReplyTo = trim((string)($header->in_reply_to ?? ''));
            $references = trim((string)($header->references ?? ''));

            $receivedAt = null;
            if (!empty($header->date)) {
                try {
                    $dt = new DateTime($header->date);
                    $receivedAt = $dt->format('Y-m-d H:i:s');
                } catch (Throwable $e) {
                    $receivedAt = null;
                }
            }
            $messageAt = $receivedAt ?: date('Y-m-d H:i:s');

            if ($messageId === '') {
                $messageId = mailboxGenerateMessageId('imap:' . (int)$mailAccount['id'] . ':' . $uid . ':' . $config['mailbox'], (string)($mailAccount['email_address'] ?? ''));
            }

            $existsStmt = $pdo->prepare('SELECT id FROM mail_messages WHERE mail_account_id = :account AND message_id = :message_id LIMIT 1');
            $existsStmt->execute([
                ':account' => (int)$mailAccount['id'],
                ':message_id' => $messageId,
            ]);
            if ($existsStmt->fetchColumn()) {
                continue;
            }

            $direction = 'in';
            $accountEmail = strtolower(trim((string)($mailAccount['email_address'] ?? '')));
            if ($accountEmail !== '' && strtolower($fromEmail) === $accountEmail) {
                $direction = 'out';
            }

            $matchEmail = $direction === 'in' ? $fromEmail : ($toList[0] ?? '');
            $assignment = $matchEmail !== '' ? resolveEntityByEmail($pdo, $matchEmail) : null;
            $entityType = $assignment['entity_type'] ?? null;
            $entityId = $assignment['entity_id'] ?? null;

            $threadId = mailboxFindOrCreateThread($pdo, $entityType, $entityId, $subject, $messageAt);

            $parts = extractImapMessageParts($imap, $uid, $maxAttachmentBytes);

            $clientId = null;
            $leadId = null;
            if ($entityType === 'client') {
                $clientId = $entityId;
            } elseif ($entityType === 'lead') {
                $leadId = $entityId;
            }

            $mailMessageId = mailboxInsertMessage($pdo, [
                'client_id' => $clientId,
                'lead_id' => $leadId,
                'campaign_id' => null,
                'owner_user_id' => (int)($mailAccount['user_id'] ?? 0),
                'mail_account_id' => (int)$mailAccount['id'],
                'thread_id' => $threadId,
                'direction' => $direction,
                'from_email' => $fromEmail,
                'from_name' => $fromName,
                'to_email' => $toLabel,
                'to_emails' => $toEmails,
                'cc_email' => $ccLabel,
                'cc_emails' => $ccEmails,
                'bcc_email' => $bccLabel,
                'subject' => $subject,
                'body_html' => $parts['html'],
                'body_text' => $parts['text'],
                'status' => $direction === 'in' ? 'RECEIVED' : 'SENT',
                'error_message' => null,
                'message_id' => $messageId,
                'in_reply_to' => $inReplyTo !== '' ? $inReplyTo : null,
                'references_header' => $references !== '' ? $references : null,
                'imap_uid' => $uid,
                'imap_mailbox' => $config['mailbox'],
                'received_at' => $receivedAt,
                'sent_at' => $direction === 'out' ? $receivedAt : null,
                'has_attachments' => !empty($parts['attachments']) ? 1 : 0,
                'entity_type' => $entityType,
                'entity_id' => $entityId,
                'created_by_user_id' => $direction === 'out' ? (int)($mailAccount['user_id'] ?? 0) : null,
            ]);

            $attachmentSaved = false;
            if ($mailMessageId > 0 && !empty($parts['attachments'])) {
                foreach ($parts['attachments'] as $attachment) {
                    $ok = mailboxStoreRawAttachment(
                        $pdo,
                        $mailMessageId,
                        $entityType,
                        $entityId,
                        (string)($attachment['filename'] ?? 'attachment'),
                        (string)($attachment['mime_type'] ?? ''),
                        (string)($attachment['data'] ?? ''),
                        $maxAttachmentBytes
                    );
                    if ($ok) {
                        $attachmentSaved = true;
                    }
                }
            }

            if ($attachmentSaved) {
                $stmtUpdate = $pdo->prepare('UPDATE mail_messages SET has_attachments = 1 WHERE id = :id');
                $stmtUpdate->execute([':id' => $mailMessageId]);
            }

            if ($entityType && $entityId && $mailMessageId > 0) {
                $activityType = $direction === 'in' ? 'email_in' : 'email_out';
                $snippet = trim((string)($parts['text'] ?? ''));
                if ($snippet !== '' && strlen($snippet) > 180) {
                    $snippet = substr($snippet, 0, 180) . '...';
                }
                addActivity(mailboxCrmType($entityType), (int)$entityId, $activityType, (int)($mailAccount['user_id'] ?? 0), $snippet !== '' ? $snippet : null, null, $subject !== '' ? $subject : null);
            }

            $synced++;
        }
    } catch (Throwable $e) {
        imap_close($imap);
        return ['ok' => false, 'error' => $e->getMessage(), 'synced' => $synced];
    }

    imap_close($imap);

    try {
        $stmt = $pdo->prepare('UPDATE mail_accounts SET last_sync_at = NOW() WHERE id = :id');
        $stmt->execute([':id' => (int)$mailAccount['id']]);
    } catch (Throwable $e) {
        error_log('imap_service: cannot update mail account sync state: ' . $e->getMessage());
    }

    return ['ok' => true, 'error' => '', 'synced' => $synced];
}
