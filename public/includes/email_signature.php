<?php
declare(strict_types=1);

require_once __DIR__ . '/db_schema.php';
require_once __DIR__ . '/auth.php';

function loadDefaultEmailSignatureTemplate(): string {
    $path = __DIR__ . '/../assets/email_footer_template.html';
    if (is_file($path)) {
        $contents = file_get_contents($path);
        if (is_string($contents) && $contents !== '') {
            return $contents;
        }
    }
    return "<p><strong>{{IMIE_NAZWISKO}}</strong><br>{{FUNKCJA}}<br>Telefon: {{TELEFON}}<br>Email: {{EMAIL}}</p>";
}

function ensureEmailSignatureTemplate(PDO $pdo): string {
    ensureSystemConfigColumns($pdo);
    $tpl = '';
    try {
        $stmt = $pdo->query("SELECT email_signature_template_html FROM konfiguracja_systemu WHERE id = 1 LIMIT 1");
        $tpl = $stmt ? (string)($stmt->fetchColumn() ?? '') : '';
    } catch (Throwable $e) {
        error_log('email_signature: fetch template failed: ' . $e->getMessage());
    }

    if (trim($tpl) !== '') {
        return $tpl;
    }

    $default = loadDefaultEmailSignatureTemplate();
    try {
        $pdo->prepare("INSERT INTO konfiguracja_systemu (id, email_signature_template_html) VALUES (1, :tpl)
            ON DUPLICATE KEY UPDATE email_signature_template_html = VALUES(email_signature_template_html)")
            ->execute([':tpl' => $default]);
    } catch (Throwable $e) {
        error_log('email_signature: cannot seed template: ' . $e->getMessage());
    }

    return $default;
}

function renderEmailSignature(string $templateHtml, array $userData): string {
    $firstName = trim((string)($userData['imie'] ?? ''));
    $lastName  = trim((string)($userData['nazwisko'] ?? ''));
    $fullName  = trim($firstName . ' ' . $lastName);
    if ($fullName === '') {
        $fullName = trim((string)($userData['login'] ?? '')) ?: 'Uzytkownik';
    }
    $function  = normalizeUserFunction($userData);
    $email     = trim((string)($userData['email'] ?? ''));
    $phone     = trim((string)($userData['telefon'] ?? ''));
    if ($phone === '') {
        $phone = 'brak numeru';
    }
    if ($function === '') {
        $function = 'Specjalista ds. Reklamy';
    }

    $replacements = [
        '{{IMIE}}'          => htmlspecialchars($firstName, ENT_QUOTES, 'UTF-8'),
        '{{NAZWISKO}}'      => htmlspecialchars($lastName, ENT_QUOTES, 'UTF-8'),
        '{{IMIE_NAZWISKO}}' => htmlspecialchars($fullName, ENT_QUOTES, 'UTF-8'),
        '{{FUNKCJA}}'       => htmlspecialchars($function, ENT_QUOTES, 'UTF-8'),
        '{{EMAIL}}'         => htmlspecialchars($email, ENT_QUOTES, 'UTF-8'),
        '{{TELEFON}}'       => htmlspecialchars($phone, ENT_QUOTES, 'UTF-8'),
    ];

    return strtr($templateHtml, $replacements);
}

function resolveUserSignature(PDO $pdo, array $user, array $systemCfg = []): string {
    $custom = trim((string)($user['email_signature'] ?? ''));
    if ($custom !== '') {
        return $custom;
    }

    $tpl = trim((string)($systemCfg['email_signature_template_html'] ?? ''));
    if ($tpl === '') {
        $tpl = ensureEmailSignatureTemplate($pdo);
    }

    return renderEmailSignature($tpl, $user);
}

function appendSignatureToBody(string $body, string $signatureHtml): string {
    $cleanBody = trim($body);
    $signature = trim($signatureHtml);
    if ($signature === '') {
        return $cleanBody;
    }

    $bodyHtml = nl2br(htmlspecialchars($cleanBody, ENT_QUOTES, 'UTF-8'));
    return $bodyHtml . '<br><br>' . $signature;
}
