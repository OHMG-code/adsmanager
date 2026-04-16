<?php
declare(strict_types=1);

function imapFallbackSyncMailAccountViaSocket(PDO $pdo, array $mailAccount, array $config, array $usernameCandidates, array $passwordCandidates, array $options = []): array {
    if (!function_exists('stream_socket_client')) {
        return ['ok' => false, 'error' => 'IMAP fallback unavailable: stream sockets are disabled.', 'synced' => 0];
    }

    $limit = (int)($options['limit'] ?? 50);
    $limit = max(1, min(200, $limit));

    $mailOwnerUserId = (int)($mailAccount['user_id'] ?? 0);
    $mailOwnerRole = 'Handlowiec';
    if ($mailOwnerUserId > 0) {
        try {
            ensureUserColumns($pdo);
            $userColumns = getTableColumns($pdo, 'uzytkownicy');
            if (hasColumn($userColumns, 'rola')) {
                $stmtUser = $pdo->prepare('SELECT rola FROM uzytkownicy WHERE id = :id LIMIT 1');
                $stmtUser->execute([':id' => $mailOwnerUserId]);
                $roleRaw = trim((string)$stmtUser->fetchColumn());
                if ($roleRaw !== '') {
                    $mailOwnerRole = normalizeRoleValue($roleRaw);
                }
            }
        } catch (Throwable $e) {
            error_log('imap_fallback: cannot resolve mail account owner role: ' . $e->getMessage());
        }
    }
    $restrictToOwner = ($mailOwnerRole === 'Handlowiec' && $mailOwnerUserId > 0);

    $usedUsername = null;
    $usedPassword = null;
    $lastError = '';
    $conn = imapFallbackConnectWithFallbacks($config, $usernameCandidates, $passwordCandidates, $usedUsername, $usedPassword, $lastError);
    if ($conn === null) {
        if (imapIsAuthenticationError($lastError)) {
            return [
                'ok' => false,
                'error' => 'Blad logowania IMAP: niepoprawny login lub haslo (sprawdz konto poczty / haslo aplikacyjne).',
                'synced' => 0,
            ];
        }
        return ['ok' => false, 'error' => $lastError !== '' ? $lastError : 'Nie mozna polaczyc z IMAP (fallback socket).', 'synced' => 0];
    }

    $synced = 0;
    try {
        $uids = imapFallbackListUids($conn, $limit, null, $lastError);
        foreach ($uids as $uid) {
            $message = imapFallbackFetchMessage($conn, (int)$uid, $lastError);
            if ($message === null) {
                continue;
            }

            $fromEmailList = $message['from_emails'];
            $fromEmail = $fromEmailList[0] ?? '';
            $fromName = $message['from_name'];
            $toList = $message['to_emails'];
            $ccList = $message['cc_emails'];
            $bccList = $message['bcc_emails'];

            $toEmails = $toList ? implode(', ', $toList) : '';
            $ccEmails = $ccList ? implode(', ', $ccList) : '';

            $subject = $message['subject'];
            $messageId = $message['message_id'];
            $inReplyTo = $message['in_reply_to'];
            $references = $message['references'];
            $receivedAt = $message['received_at'];
            $messageAt = $receivedAt ?: date('Y-m-d H:i:s');

            if ($messageId === '') {
                $messageId = mailboxGenerateMessageId('imapfb:' . (int)$mailAccount['id'] . ':' . $uid . ':' . (string)$config['mailbox'], (string)($mailAccount['email_address'] ?? ''));
            }

            $direction = 'in';
            $accountEmail = strtolower(trim((string)($mailAccount['email_address'] ?? '')));
            if ($accountEmail !== '' && strtolower($fromEmail) === $accountEmail) {
                $direction = 'out';
            }

            $candidateEmails = mailboxNormalizeEmailCandidates(array_merge($fromEmailList, $toList, $ccList, $bccList));
            if (!$candidateEmails && $fromEmail !== '') {
                $candidateEmails = [$fromEmail];
            }
            $assignment = resolveEntityByEmails($pdo, $candidateEmails, [
                'owner_user_id' => $mailOwnerUserId,
                'restrict_to_owner' => $restrictToOwner,
                'include_unassigned_leads' => $restrictToOwner,
            ]);
            $entityType = $assignment['entity_type'] ?? null;
            $entityId = $assignment['entity_id'] ?? null;

            $existsStmt = $pdo->prepare('SELECT id, entity_type, entity_id FROM mail_messages WHERE mail_account_id = :account AND message_id = :message_id LIMIT 1');
            $existsStmt->execute([
                ':account' => (int)$mailAccount['id'],
                ':message_id' => $messageId,
            ]);
            $existingMessage = $existsStmt->fetch(PDO::FETCH_ASSOC) ?: null;
            if ($existingMessage) {
                $existingEntityType = trim((string)($existingMessage['entity_type'] ?? ''));
                $existingEntityId = (int)($existingMessage['entity_id'] ?? 0);
                if (($existingEntityType === '' || $existingEntityId <= 0) && $entityType && $entityId) {
                    try {
                        $stmtAssign = $pdo->prepare(
                            'UPDATE mail_messages
                             SET entity_type = :entity_type,
                                 entity_id = :entity_id,
                                 client_id = COALESCE(client_id, :client_id),
                                 lead_id = COALESCE(lead_id, :lead_id)
                             WHERE id = :id'
                        );
                        $stmtAssign->execute([
                            ':entity_type' => $entityType,
                            ':entity_id' => (int)$entityId,
                            ':client_id' => $entityType === 'client' ? (int)$entityId : null,
                            ':lead_id' => $entityType === 'lead' ? (int)$entityId : null,
                            ':id' => (int)$existingMessage['id'],
                        ]);
                    } catch (Throwable $e) {
                        error_log('imap_fallback: cannot update message assignment: ' . $e->getMessage());
                    }
                }
                continue;
            }

            $threadId = mailboxFindOrCreateThread($pdo, $entityType, $entityId, $subject, $messageAt);

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
                'to_email' => $message['to_label'],
                'to_emails' => $toEmails,
                'cc_email' => $message['cc_label'],
                'cc_emails' => $ccEmails,
                'bcc_email' => $message['bcc_label'],
                'subject' => $subject,
                'body_html' => $message['body_html'],
                'body_text' => $message['body_text'],
                'status' => $direction === 'in' ? 'RECEIVED' : 'SENT',
                'error_message' => null,
                'message_id' => $messageId,
                'in_reply_to' => $inReplyTo !== '' ? $inReplyTo : null,
                'references_header' => $references !== '' ? $references : null,
                'imap_uid' => (int)$uid,
                'imap_mailbox' => (string)$config['mailbox'],
                'received_at' => $receivedAt,
                'sent_at' => $direction === 'out' ? $receivedAt : null,
                'has_attachments' => 0,
                'entity_type' => $entityType,
                'entity_id' => $entityId,
                'created_by_user_id' => $direction === 'out' ? (int)($mailAccount['user_id'] ?? 0) : null,
            ]);

            if ($entityType && $entityId && $mailMessageId > 0) {
                $activityType = $direction === 'in' ? 'email_in' : 'email_out';
                $snippet = trim((string)$message['body_text']);
                if ($snippet !== '' && strlen($snippet) > 180) {
                    $snippet = substr($snippet, 0, 180) . '...';
                }
                addActivity(mailboxCrmType($entityType), (int)$entityId, $activityType, (int)($mailAccount['user_id'] ?? 0), $snippet !== '' ? $snippet : null, null, $subject !== '' ? $subject : null);
            }

            $synced++;
        }
    } catch (Throwable $e) {
        imapFallbackDisconnect($conn);
        return ['ok' => false, 'error' => $e->getMessage(), 'synced' => $synced];
    }

    imapFallbackDisconnect($conn);

    try {
        if ($usedUsername !== null && trim($usedUsername) !== '' && trim($usedUsername) !== trim((string)($mailAccount['username'] ?? ''))) {
            $stmtFixUser = $pdo->prepare('UPDATE mail_accounts SET username = :username WHERE id = :id');
            $stmtFixUser->execute([
                ':username' => trim($usedUsername),
                ':id' => (int)$mailAccount['id'],
            ]);
        }
        if ($usedPassword !== null && $usedPassword !== '' && $usedPassword !== mailboxDecryptPassword((string)($mailAccount['password_enc'] ?? ''))) {
            $stmtFixPass = $pdo->prepare('UPDATE mail_accounts SET password_enc = :password_enc WHERE id = :id');
            $stmtFixPass->execute([
                ':password_enc' => encryptMailSecret($usedPassword),
                ':id' => (int)$mailAccount['id'],
            ]);
        }
        $stmt = $pdo->prepare('UPDATE mail_accounts SET last_sync_at = NOW() WHERE id = :id');
        $stmt->execute([':id' => (int)$mailAccount['id']]);
    } catch (Throwable $e) {
        error_log('imap_fallback: cannot update mail account sync state: ' . $e->getMessage());
    }

    return ['ok' => true, 'error' => '', 'synced' => $synced];
}

function imapFallbackConnectWithFallbacks(array $config, array $usernames, array $passwords, ?string &$usedUsername, ?string &$usedPassword, string &$lastError): ?array {
    $usedUsername = null;
    $usedPassword = null;
    $lastError = '';

    foreach ($usernames as $username) {
        foreach ($passwords as $password) {
            $conn = imapFallbackConnect($config, (string)$username, (string)$password, $lastError);
            if ($conn !== null) {
                $usedUsername = (string)$username;
                $usedPassword = (string)$password;
                return $conn;
            }
        }
    }

    return null;
}

function imapFallbackConnect(array $config, string $username, string $password, string &$error): ?array {
    $error = '';
    $host = trim((string)($config['host'] ?? ''));
    if ($host === '' || $username === '' || $password === '') {
        $error = 'Brak konfiguracji IMAP.';
        return null;
    }

    $secure = strtolower(trim((string)($config['secure'] ?? '')));
    $port = (int)($config['port'] ?? 0);
    if ($port <= 0) {
        $port = $secure === 'ssl' ? 993 : 143;
    }

    $target = ($secure === 'ssl' ? 'ssl://' : 'tcp://') . $host . ':' . $port;
    $errno = 0;
    $errstr = '';
    $stream = @stream_socket_client($target, $errno, $errstr, 20, STREAM_CLIENT_CONNECT);
    if (!is_resource($stream)) {
        $error = $errstr !== '' ? $errstr : 'Nie mozna polaczyc z serwerem IMAP.';
        return null;
    }

    stream_set_timeout($stream, 30);
    $greeting = fgets($stream);
    if (!is_string($greeting) || stripos($greeting, 'OK') === false) {
        fclose($stream);
        $error = 'Serwer IMAP odrzucil polaczenie.';
        return null;
    }

    $conn = ['stream' => $stream, 'seq' => 0];

    if ($secure === 'tls') {
        $resp = imapFallbackCommand($conn, 'STARTTLS', $error);
        if (!$resp['ok']) {
            fclose($stream);
            if ($error === '') {
                $error = 'STARTTLS nieudany.';
            }
            return null;
        }
        $cryptoOk = @stream_socket_enable_crypto($stream, true, STREAM_CRYPTO_METHOD_TLS_CLIENT);
        if ($cryptoOk !== true) {
            fclose($stream);
            $error = 'Nie mozna wlaczyc TLS dla IMAP.';
            return null;
        }
    }

    $loginCommand = 'LOGIN ' . imapFallbackQuote($username) . ' ' . imapFallbackQuote($password);
    $resp = imapFallbackCommand($conn, $loginCommand, $error);
    if (!$resp['ok']) {
        fclose($stream);
        if ($error === '') {
            $error = 'Blad logowania IMAP.';
        }
        return null;
    }

    $mailbox = trim((string)($config['mailbox'] ?? 'INBOX'));
    if ($mailbox === '') {
        $mailbox = 'INBOX';
    }
    $select = imapFallbackCommand($conn, 'SELECT ' . imapFallbackQuote($mailbox), $error);
    if (!$select['ok']) {
        imapFallbackDisconnect($conn);
        if ($error === '') {
            $error = 'Nie mozna otworzyc folderu IMAP.';
        }
        return null;
    }

    return $conn;
}

function imapFallbackDisconnect(array &$conn): void {
    $error = '';
    if (isset($conn['stream']) && is_resource($conn['stream'])) {
        @imapFallbackCommand($conn, 'LOGOUT', $error);
        @fclose($conn['stream']);
    }
}

function imapFallbackListUids(array &$conn, int $limit, ?int $fromUid, string &$error): array {
    $command = $fromUid !== null && $fromUid > 0
        ? 'UID SEARCH UID ' . $fromUid . ':*'
        : 'UID SEARCH ALL';
    $resp = imapFallbackCommand($conn, $command, $error);
    if (!$resp['ok']) {
        return [];
    }

    $uids = [];
    foreach ($resp['lines'] as $line) {
        if (preg_match('/^\* SEARCH\s*(.*)$/i', trim($line), $m) === 1) {
            $parts = preg_split('/\s+/', trim((string)$m[1])) ?: [];
            foreach ($parts as $part) {
                if (ctype_digit($part)) {
                    $uids[(int)$part] = (int)$part;
                }
            }
        }
    }

    $uids = array_values($uids);
    sort($uids, SORT_NUMERIC);
    if (count($uids) > $limit) {
        $uids = array_slice($uids, -$limit);
    }

    return $uids;
}

function imapFallbackFetchMessage(array &$conn, int $uid, string &$error): ?array {
    $resp = imapFallbackCommand($conn, 'UID FETCH ' . $uid . ' (INTERNALDATE RFC822.HEADER RFC822.TEXT)', $error);
    if (!$resp['ok']) {
        return null;
    }

    $headerRaw = '';
    $bodyRaw = '';
    if (isset($resp['literals'][0])) {
        $headerRaw = (string)$resp['literals'][0];
    }
    if (isset($resp['literals'][1])) {
        $bodyRaw = (string)$resp['literals'][1];
    }

    if ($headerRaw === '' && $bodyRaw === '') {
        $fallbackResp = imapFallbackCommand($conn, 'UID FETCH ' . $uid . ' (RFC822)', $error);
        if (!$fallbackResp['ok'] || !isset($fallbackResp['literals'][0])) {
            return null;
        }
        [$headerRaw, $bodyRaw] = imapFallbackSplitRawMessage((string)$fallbackResp['literals'][0]);
        $resp = $fallbackResp;
    }

    $headers = imapFallbackParseHeaders($headerRaw);
    $fromParsed = imapFallbackParseAddressHeader((string)($headers['from'] ?? ''));
    $toParsed = imapFallbackParseAddressHeader((string)($headers['to'] ?? ''));
    $ccParsed = imapFallbackParseAddressHeader((string)($headers['cc'] ?? ''));
    $bccParsed = imapFallbackParseAddressHeader((string)($headers['bcc'] ?? ''));

    $subject = imapFallbackDecodeHeader((string)($headers['subject'] ?? ''));
    $messageId = trim((string)($headers['message-id'] ?? ''));
    $inReplyTo = trim((string)($headers['in-reply-to'] ?? ''));
    $references = trim((string)($headers['references'] ?? ''));

    $receivedAt = imapFallbackExtractInternalDate($resp['lines']);
    if ($receivedAt === null) {
        $receivedAt = imapFallbackParseDate((string)($headers['date'] ?? ''));
    }

    [$bodyText, $bodyHtml] = imapFallbackExtractBodies($headers, $bodyRaw);

    return [
        'subject' => $subject,
        'message_id' => $messageId,
        'in_reply_to' => $inReplyTo,
        'references' => $references,
        'received_at' => $receivedAt,
        'from_name' => $fromParsed['names'][0] ?? '',
        'from_emails' => $fromParsed['emails'],
        'to_emails' => $toParsed['emails'],
        'cc_emails' => $ccParsed['emails'],
        'bcc_emails' => $bccParsed['emails'],
        'to_label' => $toParsed['label'],
        'cc_label' => $ccParsed['label'],
        'bcc_label' => $bccParsed['label'],
        'body_text' => $bodyText,
        'body_html' => $bodyHtml,
    ];
}

function imapFallbackCommand(array &$conn, string $command, string &$error): array {
    $error = '';
    $stream = $conn['stream'] ?? null;
    if (!is_resource($stream)) {
        $error = 'Brak aktywnego polaczenia IMAP.';
        return ['ok' => false, 'lines' => [], 'literals' => []];
    }

    $conn['seq'] = (int)($conn['seq'] ?? 0) + 1;
    $tag = 'A' . str_pad((string)$conn['seq'], 4, '0', STR_PAD_LEFT);
    $payload = $tag . ' ' . $command . "\r\n";
    $written = @fwrite($stream, $payload);
    if ($written === false || $written <= 0) {
        $error = 'Nie mozna wyslac komendy IMAP.';
        return ['ok' => false, 'lines' => [], 'literals' => []];
    }

    $lines = [];
    $literals = [];
    $ok = false;

    while (!feof($stream)) {
        $line = fgets($stream);
        if (!is_string($line)) {
            $meta = stream_get_meta_data($stream);
            if (!empty($meta['timed_out'])) {
                $error = 'Timeout odpowiedzi IMAP.';
            } else {
                $error = 'Przerwane polaczenie IMAP.';
            }
            break;
        }

        $lines[] = $line;
        if (preg_match('/\{(\d+)\}\r?\n$/', $line, $m) === 1) {
            $len = (int)$m[1];
            $literal = imapFallbackReadBytes($stream, $len);
            $literals[] = $literal;
            $tail = fgets($stream);
            if (is_string($tail) && trim($tail) !== '') {
                $lines[] = $tail;
            }
        }

        if (strpos($line, $tag . ' ') === 0) {
            if (preg_match('/^' . preg_quote($tag, '/') . '\s+(OK|NO|BAD)\b/i', trim($line), $m) === 1) {
                $status = strtoupper((string)$m[1]);
                $ok = ($status === 'OK');
                if (!$ok && $error === '') {
                    $error = trim($line);
                }
            } else {
                $error = trim($line);
            }
            break;
        }
    }

    return ['ok' => $ok, 'lines' => $lines, 'literals' => $literals];
}

function imapFallbackReadBytes($stream, int $len): string {
    $data = '';
    while (strlen($data) < $len && !feof($stream)) {
        $chunk = fread($stream, $len - strlen($data));
        if ($chunk === false || $chunk === '') {
            break;
        }
        $data .= $chunk;
    }
    return $data;
}

function imapFallbackQuote(string $value): string {
    $value = str_replace(['\\', '"'], ['\\\\', '\\"'], $value);
    return '"' . $value . '"';
}

function imapFallbackSplitRawMessage(string $raw): array {
    $parts = preg_split("/\r?\n\r?\n/", $raw, 2);
    $headerRaw = (string)($parts[0] ?? '');
    $bodyRaw = (string)($parts[1] ?? '');
    return [$headerRaw, $bodyRaw];
}

function imapFallbackParseHeaders(string $headerRaw): array {
    $headerRaw = str_replace(["\r\n", "\r"], "\n", $headerRaw);
    $lines = explode("\n", $headerRaw);
    $headers = [];
    $currentKey = null;

    foreach ($lines as $line) {
        if ($line === '') {
            continue;
        }
        if (($line[0] === ' ' || $line[0] === "\t") && $currentKey !== null) {
            $headers[$currentKey] .= ' ' . trim($line);
            continue;
        }
        $pos = strpos($line, ':');
        if ($pos === false) {
            continue;
        }
        $key = strtolower(trim(substr($line, 0, $pos)));
        $value = trim(substr($line, $pos + 1));
        $headers[$key] = $value;
        $currentKey = $key;
    }

    return $headers;
}

function imapFallbackParseAddressHeader(string $value): array {
    $value = trim($value);
    if ($value === '') {
        return ['emails' => [], 'names' => [], 'label' => ''];
    }

    $emails = [];
    if (preg_match_all('/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}/i', $value, $matches) >= 1) {
        foreach ($matches[0] as $email) {
            $email = strtolower(trim((string)$email));
            if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $emails[$email] = $email;
            }
        }
    }

    $decoded = imapFallbackDecodeHeader($value);
    $names = [];
    $chunks = preg_split('/,\s*/', $decoded) ?: [];
    foreach ($chunks as $chunk) {
        if (preg_match('/^(.+?)\s*<[^>]+>$/', trim($chunk), $m) === 1) {
            $name = trim((string)$m[1], " \t\n\r\0\x0B\"");
            if ($name !== '') {
                $names[] = $name;
            }
        }
    }

    return [
        'emails' => array_values($emails),
        'names' => $names,
        'label' => $decoded,
    ];
}

function imapFallbackDecodeHeader(string $value): string {
    $value = trim($value);
    if ($value === '') {
        return '';
    }
    if (function_exists('iconv_mime_decode')) {
        $decoded = @iconv_mime_decode($value, ICONV_MIME_DECODE_CONTINUE_ON_ERROR, 'UTF-8');
        if (is_string($decoded) && $decoded !== '') {
            return $decoded;
        }
    }
    return $value;
}

function imapFallbackExtractInternalDate(array $lines): ?string {
    foreach ($lines as $line) {
        if (preg_match('/INTERNALDATE\s+"([^"]+)"/i', $line, $m) === 1) {
            return imapFallbackParseDate((string)$m[1]);
        }
    }
    return null;
}

function imapFallbackParseDate(string $raw): ?string {
    $raw = trim($raw);
    if ($raw === '') {
        return null;
    }
    try {
        $dt = new DateTimeImmutable($raw);
        return $dt->format('Y-m-d H:i:s');
    } catch (Throwable $e) {
        return null;
    }
}

function imapFallbackExtractBodies(array $headers, string $bodyRaw): array {
    $contentType = strtolower((string)($headers['content-type'] ?? 'text/plain'));
    if (strpos($contentType, 'multipart/') !== false) {
        [$text, $html] = imapFallbackExtractMultipartBodies($bodyRaw, $contentType);
        return [$text, $html];
    }

    $encoding = strtolower((string)($headers['content-transfer-encoding'] ?? ''));
    $decoded = imapFallbackDecodeTransferEncoding($bodyRaw, $encoding);
    $decoded = imapFallbackConvertCharset($decoded, (string)($headers['content-type'] ?? ''));

    if (strpos($contentType, 'text/html') !== false) {
        return ['', trim($decoded)];
    }

    return [trim($decoded), ''];
}

function imapFallbackExtractMultipartBodies(string $bodyRaw, string $contentType): array {
    $boundary = '';
    if (preg_match('/boundary\s*=\s*"?([^";]+)"?/i', $contentType, $m) === 1) {
        $boundary = trim((string)$m[1]);
    }
    if ($boundary === '') {
        return [trim($bodyRaw), ''];
    }

    $text = '';
    $html = '';
    $segments = preg_split('/--' . preg_quote($boundary, '/') . '(?:--)?\r?\n/', $bodyRaw) ?: [];
    foreach ($segments as $segment) {
        $segment = trim($segment);
        if ($segment === '' || $segment === '--') {
            continue;
        }
        [$partHeaderRaw, $partBodyRaw] = imapFallbackSplitRawMessage($segment);
        $partHeaders = imapFallbackParseHeaders($partHeaderRaw);
        $partType = strtolower((string)($partHeaders['content-type'] ?? ''));
        $partEnc = strtolower((string)($partHeaders['content-transfer-encoding'] ?? ''));
        $decoded = imapFallbackDecodeTransferEncoding($partBodyRaw, $partEnc);
        $decoded = imapFallbackConvertCharset($decoded, (string)($partHeaders['content-type'] ?? ''));

        if ($text === '' && strpos($partType, 'text/plain') !== false) {
            $text = trim($decoded);
            continue;
        }
        if ($html === '' && strpos($partType, 'text/html') !== false) {
            $html = trim($decoded);
            continue;
        }
    }

    return [$text, $html];
}

function imapFallbackDecodeTransferEncoding(string $value, string $encoding): string {
    $encoding = strtolower(trim($encoding));
    if ($encoding === 'base64') {
        $decoded = base64_decode($value, true);
        return $decoded !== false ? $decoded : $value;
    }
    if ($encoding === 'quoted-printable') {
        return quoted_printable_decode($value);
    }
    return $value;
}

function imapFallbackConvertCharset(string $value, string $contentType): string {
    if (preg_match('/charset\s*=\s*"?([^";]+)"?/i', $contentType, $m) !== 1) {
        return $value;
    }
    $charset = strtoupper(trim((string)$m[1]));
    if ($charset === '' || $charset === 'UTF-8' || $charset === 'US-ASCII') {
        return $value;
    }
    if (function_exists('iconv')) {
        $converted = @iconv($charset, 'UTF-8//TRANSLIT//IGNORE', $value);
        if (is_string($converted) && $converted !== '') {
            return $converted;
        }
    }
    return $value;
}
