<?php
declare(strict_types=1);

require_once __DIR__ . '/db_schema.php';

function emailTemplatePlaceholders(): array
{
    return [
        '{DOCUMENT_NUMBER}',
        '{DOCUMENT_TYPE}',
        '{CLIENT_NAME}',
        '{COMPANY_NAME}',
        '{GROSS_VALUE}',
        '{CURRENCY}',
        '{ACCEPTANCE_LINK}',
        '{PDF_FILE_NAME}',
    ];
}

function getActiveEmailTemplate(PDO $pdo, string $templateKey): ?array
{
    ensureEmailTemplatesTable($pdo);
    $templateKey = trim($templateKey);
    if ($templateKey === '') {
        return null;
    }
    $stmt = $pdo->prepare('SELECT * FROM email_templates WHERE template_key = :template_key AND is_active = 1 LIMIT 1');
    $stmt->execute([':template_key' => $templateKey]);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

function renderEmailTemplate(string $template, array $vars): string
{
    $replacements = [];
    foreach ($vars as $key => $value) {
        $placeholder = strtoupper((string)$key);
        if ($placeholder[0] !== '{') {
            $placeholder = '{' . $placeholder . '}';
        }
        $replacements[$placeholder] = (string)$value;
    }
    return strtr($template, $replacements);
}

function sanitizeEmailTemplateInput(string $value): string
{
    $value = str_replace(["\r\n", "\r"], "\n", $value);
    $value = preg_replace('#<\s*(script|iframe|object|embed)\b[^>]*>.*?<\s*/\s*\1\s*>#is', '', $value) ?? '';
    $value = preg_replace('#</?\s*(script|iframe|object|embed)\b[^>]*>#i', '', (string)$value) ?? '';
    return trim((string)$value);
}
