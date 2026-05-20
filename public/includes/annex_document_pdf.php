<?php
declare(strict_types=1);

require_once __DIR__ . '/db_schema.php';
require_once __DIR__ . '/document_pdf_versions.php';
require_once __DIR__ . '/document_pdf_templates.php';

function annexDocumentPdfH($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function annexDocumentPdfMoney($value, string $currency): string
{
    return number_format((float)$value, 2, ',', ' ') . ' ' . $currency;
}

function annexDocumentPdfSanitizeTermsHtml(string $html): string
{
    $html = preg_replace('#<(script|iframe|object|embed)\b[^>]*>.*?</\1>#is', '', $html) ?? '';
    $html = preg_replace('#<(script|iframe|object|embed)\b[^>]*/?>#is', '', $html) ?? '';
    $html = preg_replace('/\son[a-z]+\s*=\s*(".*?"|\'.*?\'|[^\s>]+)/is', '', $html) ?? '';
    $html = preg_replace('/(href|src)\s*=\s*("|\')\s*javascript:.*?\2/is', '$1="#"', $html) ?? '';
    return strip_tags($html, '<p><br><strong><b><em><i><u><ol><ul><li><h2><h3><h4><table><thead><tbody><tr><th><td>');
}

function annexDocumentPdfFallbackTermsHtml(): string
{
    return defaultAnnexTermsHtml();
}

function annexDocumentPdfLoad(PDO $pdo, int $documentId): array
{
    if (!$pdo->inTransaction()) {
        ensureDocumentAnnexDetailsTable($pdo);
    }

    $stmt = $pdo->prepare("SELECT
            d.*,
            base.document_number AS base_document_number,
            k.nazwa_firmy AS client_name,
            k.nip AS client_nip,
            k.adres AS client_address,
            k.ulica AS client_street,
            k.nr_nieruchomosci AS client_building_no,
            k.nr_lokalu AS client_apartment_no,
            k.kod_pocztowy AS client_postal_code,
            k.miejscowosc AS client_city,
            k.email AS client_email,
            k.telefon AS client_phone,
            cp.company_name AS owner_company_name,
            cp.short_name AS owner_short_name,
            cp.nip AS owner_nip,
            cp.address_street AS owner_address_street,
            cp.address_postal_code AS owner_address_postal_code,
            cp.address_city AS owner_address_city,
            cp.email AS owner_email,
            cp.phone AS owner_phone,
            ad.id AS annex_detail_id,
            ad.base_document_id,
            ad.change_description,
            ad.old_valid_from,
            ad.old_valid_to,
            ad.new_valid_from,
            ad.new_valid_to,
            ad.old_net_value,
            ad.old_gross_value,
            ad.new_net_value,
            ad.new_gross_value
        FROM documents d
        LEFT JOIN documents base ON base.id = d.related_document_id
        LEFT JOIN klienci k ON k.id = d.client_id
        LEFT JOIN company_profile cp ON cp.id = d.company_profile_id
        LEFT JOIN document_annex_details ad ON ad.document_id = d.id
        WHERE d.id = :id
        LIMIT 1");
    $stmt->execute([':id' => $documentId]);
    $document = $stmt->fetch(PDO::FETCH_ASSOC);
    $stmt->closeCursor();

    if (!$document) {
        throw new RuntimeException('Nie znaleziono dokumentu.');
    }
    if ((string)$document['document_type'] !== 'annex') {
        throw new RuntimeException('PDF aneksu mozna wygenerowac tylko dla aneksu.');
    }
    if (empty($document['annex_detail_id'])) {
        throw new RuntimeException('Nie mozna wygenerowac PDF aneksu bez szczegolow aneksu.');
    }
    if (empty($document['base_document_number'])) {
        throw new RuntimeException('Nie znaleziono zlecenia bazowego dla aneksu.');
    }

    return $document;
}

function annexDocumentPdfClientAddress(array $document): string
{
    $structured = trim(implode(' ', array_filter([
        trim((string)($document['client_street'] ?? '') . ' ' . (string)($document['client_building_no'] ?? '') . (!empty($document['client_apartment_no']) ? '/' . $document['client_apartment_no'] : '')),
        trim((string)($document['client_postal_code'] ?? '') . ' ' . (string)($document['client_city'] ?? '')),
    ])));

    return $structured !== '' ? $structured : trim((string)($document['client_address'] ?? ''));
}

function annexDocumentPdfResolveTermsSnapshot(PDO $pdo, array $document): array
{
    if (!$pdo->inTransaction()) {
        ensureDocumentTermsTables($pdo);
    }

    $stmt = $pdo->prepare('SELECT * FROM document_terms_acceptance WHERE document_id = :document_id LIMIT 1');
    $stmt->execute([':document_id' => (int)$document['id']]);
    $snapshot = $stmt->fetch(PDO::FETCH_ASSOC);
    $stmt->closeCursor();
    if ($snapshot) {
        return [
            'template_id' => $snapshot['terms_template_id'] !== null ? (int)$snapshot['terms_template_id'] : null,
            'version' => (string)$snapshot['terms_version'],
            'content_html' => annexDocumentPdfSanitizeTermsHtml((string)$snapshot['terms_content_snapshot']),
        ];
    }

    $issueDate = (string)($document['issue_date'] ?? date('Y-m-d'));
    $find = static function (string $type) use ($pdo, $issueDate): ?array {
        $stmt = $pdo->prepare("SELECT * FROM document_terms_templates
            WHERE document_type = :document_type
              AND is_active = 1
              AND (valid_from IS NULL OR valid_from <= :issue_date_from)
              AND (valid_to IS NULL OR valid_to >= :issue_date_to)
            ORDER BY valid_from DESC, id DESC
            LIMIT 1");
        $stmt->execute([
            ':document_type' => $type,
            ':issue_date_from' => $issueDate,
            ':issue_date_to' => $issueDate,
        ]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
        $stmt->closeCursor();
        return $row;
    };

    $template = $find('annex') ?: $find('all');
    $templateId = $template ? (int)$template['id'] : null;
    $version = $template ? (string)$template['version'] : 'fallback';
    $contentHtml = $template
        ? annexDocumentPdfSanitizeTermsHtml((string)$template['content_html'])
        : annexDocumentPdfFallbackTermsHtml();

    $insert = $pdo->prepare("INSERT INTO document_terms_acceptance
        (document_id, terms_template_id, terms_version, terms_content_snapshot)
        VALUES (:document_id, :terms_template_id, :terms_version, :terms_content_snapshot)");
    $insert->execute([
        ':document_id' => (int)$document['id'],
        ':terms_template_id' => $templateId,
        ':terms_version' => $version,
        ':terms_content_snapshot' => $contentHtml,
    ]);

    return [
        'template_id' => $templateId,
        'version' => $version,
        'content_html' => $contentHtml,
    ];
}

function annexDocumentPdfRenderHtml(array $document): string
{
    $currency = (string)($document['currency'] ?? 'PLN');
    $ownerAddress = trim(implode(' ', array_filter([
        $document['owner_address_street'] ?? '',
        $document['owner_address_postal_code'] ?? '',
        $document['owner_address_city'] ?? '',
    ])));
    $clientAddress = annexDocumentPdfClientAddress($document);
    $oldVat = max(0.0, (float)$document['old_gross_value'] - (float)$document['old_net_value']);
    $newVat = max(0.0, (float)$document['new_gross_value'] - (float)$document['new_net_value']);
    $owzHtml = annexDocumentPdfSanitizeTermsHtml((string)($document['terms_content_snapshot'] ?? annexDocumentPdfFallbackTermsHtml()));
    $owzVersion = (string)($document['terms_version'] ?? 'fallback');

    return '<!doctype html>
<html lang="pl">
<head>
<meta charset="utf-8">
<style>
@page { margin: 28px 32px 44px; }
body { font-family: DejaVu Sans, sans-serif; font-size: 11px; color: #1f2937; }
.footer { position: fixed; left: 0; right: 0; bottom: -28px; font-size: 9px; color: #64748b; border-top: 1px solid #dbe3ef; padding-top: 7px; }
.header { border-bottom: 3px solid #0b2b5c; padding-bottom: 16px; margin-bottom: 18px; }
.header-table { width: 100%; border-collapse: collapse; }
.owner-name { font-size: 18px; font-weight: 700; color: #0b2b5c; margin-bottom: 4px; }
.doc-title { font-size: 20px; font-weight: 700; color: #0b2b5c; margin: 0 0 5px; text-align: right; }
.doc-meta { text-align: right; line-height: 1.6; }
.section { margin-top: 16px; }
.section-title { background: #0b2b5c; color: #fff; padding: 7px 10px; font-weight: 700; font-size: 12px; }
.box { border: 1px solid #d7dfec; padding: 10px; }
.grid { width: 100%; border-collapse: collapse; }
.grid td { vertical-align: top; width: 50%; }
.kv, .values { width: 100%; border-collapse: collapse; }
.kv th { width: 32%; text-align: left; background: #f2f6fb; color: #0b2b5c; }
.kv th, .kv td, .values th, .values td { border: 1px solid #d7dfec; padding: 8px; vertical-align: top; }
.values th { background: #f2f6fb; color: #0b2b5c; text-align: left; }
.values .num { text-align: right; }
.note { border: 2px solid #0b2b5c; background: #eef5ff; padding: 9px; font-weight: 700; margin: 8px 0; }
.signatures { width: 100%; margin-top: 36px; border-collapse: collapse; }
.signatures td { width: 50%; text-align: center; padding-top: 36px; }
.line { border-top: 1px solid #1f2937; padding-top: 7px; width: 80%; margin: 0 auto; }
</style>
</head>
<body>
<div class="footer">Aneks ' . annexDocumentPdfH($document['document_number']) . ' do zlecenia ' . annexDocumentPdfH($document['base_document_number']) . '</div>
<div class="header">
  <table class="header-table">
    <tr>
      <td style="width: 52%;">
        <div class="owner-name">' . annexDocumentPdfH($document['owner_company_name'] ?: '-') . '</div>
        <div>' . annexDocumentPdfH($ownerAddress ?: '-') . '</div>
        <div>NIP: ' . annexDocumentPdfH($document['owner_nip'] ?: '-') . '</div>
        <div>Email: ' . annexDocumentPdfH($document['owner_email'] ?: '-') . '</div>
        <div>Telefon: ' . annexDocumentPdfH($document['owner_phone'] ?: '-') . '</div>
      </td>
      <td style="width: 48%;">
        <div class="doc-title">Aneks do zlecenia emisji reklamy</div>
        <div class="doc-meta">
          <strong>Numer aneksu:</strong> ' . annexDocumentPdfH($document['document_number']) . '<br>
          <strong>Numer zlecenia bazowego:</strong> ' . annexDocumentPdfH($document['base_document_number']) . '<br>
          <strong>Data wystawienia:</strong> ' . annexDocumentPdfH($document['issue_date'] ?: '-') . '
        </div>
      </td>
    </tr>
  </table>
</div>

<table class="grid">
  <tr>
    <td style="padding-right: 8px;">
      <div class="section-title">Dane klienta</div>
      <div class="box">
        <strong>' . annexDocumentPdfH($document['client_name'] ?: '-') . '</strong><br>
        ' . annexDocumentPdfH($clientAddress ?: '-') . '<br>
        NIP: ' . annexDocumentPdfH($document['client_nip'] ?: '-') . '<br>
        Email: ' . annexDocumentPdfH($document['client_email'] ?: '-') . '
      </div>
    </td>
    <td style="padding-left: 8px;">
      <div class="section-title">Aneks</div>
      <div class="box">
        Tytul: <strong>' . annexDocumentPdfH($document['title'] ?: 'Aneks do zlecenia emisji reklamy') . '</strong><br>
        Status: <strong>' . annexDocumentPdfH($document['status'] ?: '-') . '</strong><br>
        Waluta: <strong>' . annexDocumentPdfH($currency) . '</strong>
      </div>
    </td>
  </tr>
</table>

<div class="section">
  <div class="section-title">Opis zmiany</div>
  <div class="box">' . nl2br(annexDocumentPdfH($document['change_description'] ?: '-')) . '</div>
</div>

<div class="section">
  <div class="section-title">Bylo / Jest</div>
  <table class="values">
    <tr><th>Zakres</th><th>Bylo</th><th>Jest</th></tr>
    <tr>
      <td>Okres emisji</td>
      <td>' . annexDocumentPdfH(($document['old_valid_from'] ?: '-') . ' - ' . ($document['old_valid_to'] ?: '-')) . '</td>
      <td>' . annexDocumentPdfH(($document['new_valid_from'] ?: '-') . ' - ' . ($document['new_valid_to'] ?: '-')) . '</td>
    </tr>
    <tr>
      <td>Netto</td>
      <td class="num">' . annexDocumentPdfH(annexDocumentPdfMoney($document['old_net_value'], $currency)) . '</td>
      <td class="num">' . annexDocumentPdfH(annexDocumentPdfMoney($document['new_net_value'], $currency)) . '</td>
    </tr>
    <tr>
      <td>VAT</td>
      <td class="num">' . annexDocumentPdfH(annexDocumentPdfMoney($oldVat, $currency)) . '</td>
      <td class="num">' . annexDocumentPdfH((string)$document['vat_rate']) . '% / ' . annexDocumentPdfH(annexDocumentPdfMoney($newVat, $currency)) . '</td>
    </tr>
    <tr>
      <td>Brutto</td>
      <td class="num"><strong>' . annexDocumentPdfH(annexDocumentPdfMoney($document['old_gross_value'], $currency)) . '</strong></td>
      <td class="num"><strong>' . annexDocumentPdfH(annexDocumentPdfMoney($document['new_gross_value'], $currency)) . '</strong></td>
    </tr>
  </table>
</div>

<div class="section">
  <div class="note">Pozostale warunki zlecenia bazowego pozostaja bez zmian. W zakresie sprzecznosci pierwszenstwo ma tresc niniejszego aneksu.</div>
</div>

<div class="section">
  <div class="section-title">Ogolne warunki zamowienia</div>
  <div>Wersja OWZ: ' . annexDocumentPdfH($owzVersion) . '</div>
  <div>' . $owzHtml . '</div>
</div>

<table class="signatures">
  <tr>
    <td><div class="line">Podpis Zamawiajacego</div></td>
    <td><div class="line">Podpis Wykonawcy</div></td>
  </tr>
</table>
</body>
</html>';
}

function annexDocumentPdfTemplateVars(array $document): array
{
    $currency = (string)($document['currency'] ?? 'PLN');
    $ownerAddress = trim(implode(' ', array_filter([
        $document['owner_address_street'] ?? '',
        $document['owner_address_postal_code'] ?? '',
        $document['owner_address_city'] ?? '',
    ])));
    $clientAddress = annexDocumentPdfClientAddress($document);
    $oldVat = max(0.0, (float)$document['old_gross_value'] - (float)$document['old_net_value']);
    $newVat = max(0.0, (float)$document['new_gross_value'] - (float)$document['new_net_value']);
    $owzVersion = (string)($document['terms_version'] ?? 'fallback');
    $termsHtml = annexDocumentPdfSanitizeTermsHtml((string)($document['terms_content_snapshot'] ?? annexDocumentPdfFallbackTermsHtml()));
    $annexDetails = '<p>' . nl2br(annexDocumentPdfH($document['change_description'] ?: '-')) . '</p>'
        . '<table class="values"><tr><th>Zakres</th><th>Było</th><th>Jest</th></tr>'
        . '<tr><td>Okres emisji</td><td>' . annexDocumentPdfH(($document['old_valid_from'] ?: '-') . ' - ' . ($document['old_valid_to'] ?: '-')) . '</td><td>' . annexDocumentPdfH(($document['new_valid_from'] ?: '-') . ' - ' . ($document['new_valid_to'] ?: '-')) . '</td></tr>'
        . '<tr><td>Netto</td><td class="num">' . annexDocumentPdfH(annexDocumentPdfMoney($document['old_net_value'], $currency)) . '</td><td class="num">' . annexDocumentPdfH(annexDocumentPdfMoney($document['new_net_value'], $currency)) . '</td></tr>'
        . '<tr><td>VAT</td><td class="num">' . annexDocumentPdfH(annexDocumentPdfMoney($oldVat, $currency)) . '</td><td class="num">' . annexDocumentPdfH((string)$document['vat_rate']) . '% / ' . annexDocumentPdfH(annexDocumentPdfMoney($newVat, $currency)) . '</td></tr>'
        . '<tr><td>Brutto</td><td class="num"><strong>' . annexDocumentPdfH(annexDocumentPdfMoney($document['old_gross_value'], $currency)) . '</strong></td><td class="num"><strong>' . annexDocumentPdfH(annexDocumentPdfMoney($document['new_gross_value'], $currency)) . '</strong></td></tr>'
        . '</table>';

    return [
        'DOCUMENT_NUMBER' => annexDocumentPdfH($document['document_number'] ?? ''),
        'DOCUMENT_TYPE_LABEL' => 'Aneks',
        'DOCUMENT_TITLE' => annexDocumentPdfH($document['title'] ?: 'Aneks do zlecenia emisji reklamy'),
        'ISSUE_DATE' => annexDocumentPdfH($document['issue_date'] ?: '-'),
        'VALID_FROM' => annexDocumentPdfH($document['new_valid_from'] ?: ($document['valid_from'] ?: '-')),
        'VALID_TO' => annexDocumentPdfH($document['new_valid_to'] ?: ($document['valid_to'] ?: '-')),
        'CLIENT_NAME' => annexDocumentPdfH($document['client_name'] ?: '-'),
        'CLIENT_NIP' => annexDocumentPdfH($document['client_nip'] ?: '-'),
        'CLIENT_ADDRESS' => annexDocumentPdfH($clientAddress ?: '-'),
        'CLIENT_EMAIL' => annexDocumentPdfH($document['client_email'] ?: '-'),
        'COMPANY_NAME' => annexDocumentPdfH($document['owner_company_name'] ?: '-'),
        'COMPANY_NIP' => annexDocumentPdfH($document['owner_nip'] ?: '-'),
        'COMPANY_ADDRESS' => annexDocumentPdfH($ownerAddress ?: '-'),
        'COMPANY_EMAIL' => annexDocumentPdfH($document['owner_email'] ?: '-'),
        'COMPANY_PHONE' => annexDocumentPdfH($document['owner_phone'] ?: '-'),
        'NET_VALUE' => annexDocumentPdfH(annexDocumentPdfMoney($document['new_net_value'], $currency)),
        'VAT_RATE' => annexDocumentPdfH((string)$document['vat_rate']) . '%',
        'VAT_VALUE' => annexDocumentPdfH(annexDocumentPdfMoney($newVat, $currency)),
        'GROSS_VALUE' => annexDocumentPdfH(annexDocumentPdfMoney($document['new_gross_value'], $currency)),
        'CURRENCY' => annexDocumentPdfH($currency),
        'ORDER_DETAILS' => '',
        'ANNEX_DETAILS' => $annexDetails,
        'TERMS_HTML' => '<div class="small">Wersja OWZ: ' . annexDocumentPdfH($owzVersion) . '</div>' . $termsHtml,
        'DYNAMIC_TERMS_HTML' => '',
        'SIGNATURES_HTML' => '<table class="signatures"><tr><td><div class="line">Podpis Zamawiającego</div></td><td><div class="line">Podpis Wykonawcy</div></td></tr></table>',
    ];
}

function annexDocumentPdfBinary(array $document): string
{
    $autoload = dirname(__DIR__, 2) . '/vendor/autoload.php';
    if (is_file($autoload)) {
        require_once $autoload;
    }
    if (!class_exists('\\Dompdf\\Dompdf')) {
        throw new RuntimeException('Brak biblioteki Dompdf.');
    }

    $options = new \Dompdf\Options();
    $options->set('isRemoteEnabled', false);
    $options->set('isHtml5ParserEnabled', true);
    $options->set('defaultFont', 'DejaVu Sans');

    $dompdf = new \Dompdf\Dompdf($options);
    $dompdf->setPaper('A4', 'portrait');
    $html = isset($document['_pdf_template_html']) && is_string($document['_pdf_template_html'])
        ? $document['_pdf_template_html']
        : annexDocumentPdfRenderHtml($document);
    $dompdf->loadHtml($html, 'UTF-8');
    $dompdf->render();
    return $dompdf->output();
}

function annexDocumentPdfGenerateAndSave(PDO $pdo, int $documentId): array
{
    $startedTransaction = false;
    if (!$pdo->inTransaction()) {
        ensureDocumentAnnexDetailsTable($pdo);
        ensureDocumentTermsTables($pdo);
        ensureDocumentPdfVersionsTable($pdo);
        ensureDocumentPdfTemplatesTable($pdo);
        $pdo->beginTransaction();
        $startedTransaction = true;
    }

    try {
        $document = annexDocumentPdfLoad($pdo, $documentId);
        $terms = annexDocumentPdfResolveTermsSnapshot($pdo, $document);
        $document['terms_template_id'] = $terms['template_id'];
        $document['terms_version'] = $terms['version'];
        $document['terms_content_snapshot'] = $terms['content_html'];
        $template = getActiveDocumentPdfTemplate($pdo, 'annex');
        if ($template) {
            $document['_pdf_template_html'] = buildDocumentPdfTemplateDocument(
                sanitizeDocumentPdfTemplateHtml((string)$template['html_template']),
                sanitizeDocumentPdfTemplateCss((string)($template['css_template'] ?? '')),
                annexDocumentPdfTemplateVars($document)
            );
        }
        $pdf = annexDocumentPdfBinary($document);

        $uploadDir = dirname(__DIR__) . '/uploads/documents';
        if (!is_dir($uploadDir) && !mkdir($uploadDir, 0775, true) && !is_dir($uploadDir)) {
            throw new RuntimeException('Nie udalo sie utworzyc katalogu uploads/documents.');
        }

        $safeNumber = preg_replace('/[^A-Za-z0-9_-]+/', '_', (string)$document['document_number']);
        $filename = 'aneks_' . trim((string)$safeNumber, '_') . '_' . date('Ymd_His') . '.pdf';
        $fullPath = $uploadDir . '/' . $filename;
        if (file_exists($fullPath)) {
            $filename = 'aneks_' . trim((string)$safeNumber, '_') . '_' . date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.pdf';
            $fullPath = $uploadDir . '/' . $filename;
        }

        if (file_put_contents($fullPath, $pdf) === false) {
            throw new RuntimeException('Nie udalo sie zapisac pliku PDF.');
        }

        $relativePath = 'uploads/documents/' . $filename;
        $version = createDocumentPdfVersion($pdo, $documentId, $relativePath, $filename, $fullPath, (int)($_SESSION['user_id'] ?? 0) ?: null);

        if ($startedTransaction) {
            $pdo->commit();
        }

        return [
            'path' => $relativePath,
            'filename' => $filename,
            'bytes' => strlen($pdf),
            'terms_version' => $terms['version'],
            'version_id' => $version['id'] ?? null,
            'version_number' => $version['version_number'] ?? null,
            'checksum_sha256' => $version['checksum_sha256'] ?? null,
        ];
    } catch (Throwable $e) {
        if ($startedTransaction && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}
