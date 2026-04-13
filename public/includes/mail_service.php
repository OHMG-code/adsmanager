<?php
declare(strict_types=1);

require_once __DIR__ . '/crypto.php';

function ensureMailerLoaded(): bool {
    if (class_exists('\\PHPMailer\\PHPMailer\\PHPMailer')) {
        return true;
    }
    $paths = [
        __DIR__ . '/../../vendor/autoload.php',
        __DIR__ . '/../vendor/autoload.php',
    ];
    foreach ($paths as $path) {
        if (is_file($path)) {
            require_once $path;
            if (class_exists('\\PHPMailer\\PHPMailer\\PHPMailer')) {
                return true;
            }
        }
    }

    // Fallback for environments where Composer autoload was not regenerated.
    $manualSets = [
        [
            __DIR__ . '/../../vendor/phpmailer/phpmailer/src/Exception.php',
            __DIR__ . '/../../vendor/phpmailer/phpmailer/src/SMTP.php',
            __DIR__ . '/../../vendor/phpmailer/phpmailer/src/PHPMailer.php',
        ],
        [
            __DIR__ . '/../vendor/phpmailer/phpmailer/src/Exception.php',
            __DIR__ . '/../vendor/phpmailer/phpmailer/src/SMTP.php',
            __DIR__ . '/../vendor/phpmailer/phpmailer/src/PHPMailer.php',
        ],
    ];
    foreach ($manualSets as $files) {
        if (is_file($files[0]) && is_file($files[1]) && is_file($files[2])) {
            require_once $files[0];
            require_once $files[1];
            require_once $files[2];
            if (class_exists('\\PHPMailer\\PHPMailer\\PHPMailer')) {
                return true;
            }
        }
    }

    return class_exists('\\PHPMailer\\PHPMailer\\PHPMailer');
}

function parseEmailList(string $raw): array {
    $raw = trim($raw);
    if ($raw === '') {
        return [];
    }
    $parts = preg_split('/[;,]+/', $raw) ?: [];
    $out = [];
    foreach ($parts as $part) {
        $email = trim($part);
        if ($email === '') {
            continue;
        }
        if (filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $out[strtolower($email)] = $email;
        }
    }
    return array_values($out);
}

function normalizeEmailInput($input): array {
    if (is_array($input)) {
        $out = [];
        foreach ($input as $item) {
            $email = trim((string)$item);
            if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $out[strtolower($email)] = $email;
            }
        }
        return array_values($out);
    }
    return parseEmailList((string)$input);
}

function resolveSmtpConfig(array $systemCfg, array $user): array {
    $userEnabled = !empty($user['smtp_enabled']) && trim((string)($user['smtp_host'] ?? '')) !== '';
    if ($userEnabled) {
        $password = '';
        try {
            $password = decryptSecret((string)($user['smtp_pass_enc'] ?? ''));
        } catch (Throwable $e) {
            $password = '';
        }
        if ($password === '' && !empty($user['smtp_pass'])) {
            $password = (string)$user['smtp_pass'];
        }
        $smtpAuth = trim((string)($user['smtp_user'] ?? '')) !== '' || $password !== '';
        $port = (int)($user['smtp_port'] ?? 587);
        if ($port <= 0) {
            $port = 587;
        }
        return [
            'host' => trim((string)($user['smtp_host'] ?? '')),
            'port' => $port,
            'secure' => in_array($user['smtp_secure'] ?? '', ['', 'tls', 'ssl'], true) ? (string)($user['smtp_secure'] ?? '') : '',
            'auth' => $smtpAuth,
            'username' => trim((string)($user['smtp_user'] ?? '')),
            'password' => $password,
            'from_email' => trim((string)($user['smtp_from_email'] ?? '')),
            'from_name' => trim((string)($user['smtp_from_name'] ?? '')),
        ];
    }

    return [
        'host' => trim((string)($systemCfg['smtp_host'] ?? '')),
        'port' => (int)($systemCfg['smtp_port'] ?? 587),
        'secure' => in_array($systemCfg['smtp_secure'] ?? '', ['', 'tls', 'ssl'], true) ? (string)($systemCfg['smtp_secure'] ?? '') : '',
        'auth' => !empty($systemCfg['smtp_auth']),
        'username' => trim((string)($systemCfg['smtp_username'] ?? '')),
        'password' => (string)($systemCfg['smtp_password'] ?? ''),
        'from_email' => trim((string)($systemCfg['smtp_default_from_email'] ?? '')),
        'from_name' => trim((string)($systemCfg['smtp_default_from_name'] ?? '')),
    ];
}

function sendCrmEmail(array $context, array $smtpConfig, array $message, array $attachments): array {
    if (!ensureMailerLoaded()) {
        return ['ok' => false, 'error' => 'PHPMailer is not available.', 'to' => [], 'cc' => [], 'bcc' => []];
    }

    $toList = normalizeEmailInput($message['to'] ?? []);
    $ccList = normalizeEmailInput($message['cc'] ?? []);
    $bccList = normalizeEmailInput($message['bcc'] ?? []);

    $archiveEnabled = !empty($context['archive_enabled']);
    $archiveBcc = trim((string)($context['archive_bcc_email'] ?? ''));
    if ($archiveEnabled && $archiveBcc !== '' && filter_var($archiveBcc, FILTER_VALIDATE_EMAIL)) {
        $lower = strtolower($archiveBcc);
        if (!in_array($lower, array_map('strtolower', $bccList), true)) {
            $bccList[] = $archiveBcc;
        }
    }

    $subject = trim((string)($message['subject'] ?? ''));
    $bodyHtml = (string)($message['body_html'] ?? '');
    $bodyText = (string)($message['body_text'] ?? '');
    $customMessageId = trim((string)($message['message_id'] ?? ''));

    try {
        $mailer = new PHPMailer\PHPMailer\PHPMailer(true);
        $mailer->isSMTP();
        $mailer->Host = (string)($smtpConfig['host'] ?? '');
        $mailer->Port = (int)($smtpConfig['port'] ?? 587);
        $mailer->SMTPAuth = !empty($smtpConfig['auth']);
        if ($mailer->SMTPAuth) {
            $mailer->Username = (string)($smtpConfig['username'] ?? '');
            $mailer->Password = (string)($smtpConfig['password'] ?? '');
        }
        $secure = (string)($smtpConfig['secure'] ?? '');
        if ($secure === 'ssl' || $secure === 'tls') {
            $mailer->SMTPSecure = $secure;
        }
        $mailer->CharSet = 'UTF-8';

        $fromEmail = trim((string)($smtpConfig['from_email'] ?? ''));
        $fromName = trim((string)($smtpConfig['from_name'] ?? ''));
        if ($fromEmail === '') {
            throw new RuntimeException('Missing from email.');
        }

        $mailer->setFrom($fromEmail, $fromName);
        foreach ($toList as $addr) {
            $mailer->addAddress($addr);
        }
        foreach ($ccList as $addr) {
            $mailer->addCC($addr);
        }
        foreach ($bccList as $addr) {
            $mailer->addBCC($addr);
        }
        if ($fromEmail !== '') {
            $mailer->addReplyTo($fromEmail, $fromName !== '' ? $fromName : $fromEmail);
        }

        $mailer->Subject = $subject;
        if ($customMessageId !== '') {
            if ($customMessageId[0] !== '<') {
                $customMessageId = '<' . $customMessageId . '>';
            }
            $mailer->MessageID = $customMessageId;
        }
        if ($bodyHtml !== '') {
            $mailer->isHTML(true);
            $mailer->Body = $bodyHtml;
            $mailer->AltBody = $bodyText !== '' ? $bodyText : strip_tags($bodyHtml);
        } else {
            $mailer->Body = $bodyText;
            $mailer->AltBody = $bodyText;
        }

        foreach ($attachments as $attachment) {
            $path = $attachment['path'] ?? '';
            if ($path && is_file($path)) {
                $name = (string)($attachment['filename'] ?? basename($path));
                $type = (string)($attachment['mime_type'] ?? '');
                if ($type !== '') {
                    $mailer->addAttachment($path, $name, 'base64', $type);
                } else {
                    $mailer->addAttachment($path, $name);
                }
            }
        }

        $mailer->send();
        $lastMessageId = '';
        if (method_exists($mailer, 'getLastMessageID')) {
            $lastMessageId = (string)$mailer->getLastMessageID();
        } elseif (property_exists($mailer, 'MessageID')) {
            $lastMessageId = (string)$mailer->MessageID;
        }
        return ['ok' => true, 'error' => '', 'to' => $toList, 'cc' => $ccList, 'bcc' => $bccList, 'message_id' => $lastMessageId];
    } catch (Throwable $e) {
        return ['ok' => false, 'error' => $e->getMessage(), 'to' => $toList, 'cc' => $ccList, 'bcc' => $bccList, 'message_id' => ''];
    }
}
