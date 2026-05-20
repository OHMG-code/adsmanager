<?php
declare(strict_types=1);

require_once __DIR__ . '/db_schema.php';

function documentPdfTemplatePlaceholders(): array
{
    return [
        '{DOCUMENT_NUMBER}',
        '{DOCUMENT_TYPE_LABEL}',
        '{DOCUMENT_TITLE}',
        '{ISSUE_DATE}',
        '{VALID_FROM}',
        '{VALID_TO}',
        '{CLIENT_NAME}',
        '{CLIENT_NIP}',
        '{CLIENT_ADDRESS}',
        '{CLIENT_EMAIL}',
        '{COMPANY_NAME}',
        '{COMPANY_NIP}',
        '{COMPANY_ADDRESS}',
        '{COMPANY_EMAIL}',
        '{COMPANY_PHONE}',
        '{NET_VALUE}',
        '{VAT_RATE}',
        '{VAT_VALUE}',
        '{GROSS_VALUE}',
        '{CURRENCY}',
        '{ORDER_DETAILS}',
        '{ANNEX_DETAILS}',
        '{TERMS_HTML}',
        '{DYNAMIC_TERMS_HTML}',
        '{SIGNATURES_HTML}',
    ];
}

function getActiveDocumentPdfTemplate(PDO $pdo, string $documentType): ?array
{
    if (!$pdo->inTransaction()) {
        ensureDocumentPdfTemplatesTable($pdo);
    }
    $documentType = trim($documentType);
    if ($documentType === '') {
        return null;
    }

    $stmt = $pdo->prepare('SELECT * FROM document_pdf_templates WHERE document_type = :document_type AND is_active = 1 ORDER BY id DESC LIMIT 1');
    $stmt->execute([':document_type' => $documentType]);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

function renderDocumentPdfTemplate(string $html, array $vars): string
{
    $replacements = [];
    foreach ($vars as $key => $value) {
        $placeholder = strtoupper((string)$key);
        if ($placeholder[0] !== '{') {
            $placeholder = '{' . $placeholder . '}';
        }
        $replacements[$placeholder] = (string)$value;
    }
    return strtr($html, $replacements);
}

function sanitizeDocumentPdfTemplateHtml(string $html): string
{
    $html = preg_replace('#<\s*(script|iframe|object|embed)\b[^>]*>.*?<\s*/\s*\1\s*>#is', '', $html) ?? '';
    $html = preg_replace('#</?\s*(script|iframe|object|embed)\b[^>]*>#i', '', $html) ?? '';
    $html = preg_replace('/\son[a-z]+\s*=\s*(".*?"|\'.*?\'|[^\s>]+)/is', '', $html) ?? '';
    $html = preg_replace('/(href|src)\s*=\s*("|\')\s*(javascript:|file:|php:|data:).*?\2/is', '$1="#"', $html) ?? '';
    $html = preg_replace('#<\s*(link|meta|base)\b[^>]*>#i', '', $html) ?? '';

    return strip_tags(
        $html,
        '<html><head><body><style><div><span><p><br><strong><b><em><i><u><ol><ul><li><h1><h2><h3><h4><table><thead><tbody><tfoot><tr><th><td><small><section><article><hr>'
    );
}

function sanitizeDocumentPdfTemplateCss(string $css): string
{
    $css = preg_replace('#/\*.*?\*/#s', '', $css) ?? '';
    $css = preg_replace('/@import\b[^;]*;?/i', '', $css) ?? '';
    $css = preg_replace('/expression\s*\([^)]*\)/i', '', $css) ?? '';
    $css = preg_replace('/url\s*\(\s*([\'"]?)\s*(javascript:|file:|php:|data:)[^)]+\)/i', 'none', $css) ?? '';
    $css = preg_replace('/behavior\s*:\s*[^;]+;?/i', '', $css) ?? '';
    return trim((string)$css);
}

function buildDocumentPdfTemplateDocument(string $htmlTemplate, string $cssTemplate, array $vars): string
{
    $htmlTemplate = sanitizeDocumentPdfTemplateHtml($htmlTemplate);
    $html = renderDocumentPdfTemplate($htmlTemplate, $vars);
    $css = sanitizeDocumentPdfTemplateCss($cssTemplate);

    if (stripos($html, '<html') !== false) {
        if ($css !== '') {
            if (stripos($html, '</head>') !== false) {
                $html = preg_replace('/<\/head>/i', '<style>' . $css . '</style></head>', $html, 1) ?? $html;
            } else {
                $html = '<style>' . $css . '</style>' . $html;
            }
        }
        return $html;
    }

    return '<!doctype html><html lang="pl"><head><meta charset="utf-8"><style>' . $css . '</style></head><body>' . $html . '</body></html>';
}
