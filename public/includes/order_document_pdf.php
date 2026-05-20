<?php
declare(strict_types=1);

require_once __DIR__ . '/db_schema.php';
require_once __DIR__ . '/document_pdf_versions.php';
require_once __DIR__ . '/document_pdf_templates.php';

function orderDocumentPdfH($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function orderDocumentPdfMoney($value, string $currency): string
{
    return number_format((float)$value, 2, ',', ' ') . ' ' . $currency;
}

function orderDocumentPdfLoad(PDO $pdo, int $documentId): array
{
    if (!$pdo->inTransaction()) {
        ensureDocumentOrderDetailsTable($pdo);
    }

    $stmt = $pdo->prepare("SELECT
            d.*,
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
            cp.logo_path AS owner_logo_path,
            od.spot_source,
            od.material_deadline,
            od.spot_length_seconds,
            od.emission_count,
            od.technical_notes
        FROM documents d
        LEFT JOIN klienci k ON k.id = d.client_id
        LEFT JOIN company_profile cp ON cp.id = d.company_profile_id
        LEFT JOIN document_order_details od ON od.document_id = d.id
        WHERE d.id = :id
        LIMIT 1");
    $stmt->execute([':id' => $documentId]);
    $document = $stmt->fetch(PDO::FETCH_ASSOC);
    $stmt->closeCursor();

    if (!$document) {
        throw new RuntimeException('Nie znaleziono dokumentu.');
    }
    if ((string)$document['document_type'] !== 'order') {
        throw new RuntimeException('PDF moĹĽna wygenerowaÄ‡ tylko dla zlecenia.');
    }

    return $document;
}

function orderDocumentPdfLogoDataUri(?string $relativePath): string
{
    $relativePath = trim((string)$relativePath);
    if ($relativePath === '') {
        return '';
    }

    $publicDir = dirname(__DIR__);
    $fullPath = realpath($publicDir . '/' . ltrim($relativePath, '/'));
    $publicReal = realpath($publicDir);
    if (!$fullPath || !$publicReal || strpos($fullPath, $publicReal) !== 0 || !is_file($fullPath)) {
        return '';
    }

    $mime = mime_content_type($fullPath) ?: '';
    if (!in_array($mime, ['image/jpeg', 'image/png', 'image/webp'], true)) {
        return '';
    }

    $bytes = file_get_contents($fullPath);
    if ($bytes === false) {
        return '';
    }

    return 'data:' . $mime . ';base64,' . base64_encode($bytes);
}

function orderDocumentPdfClientAddress(array $document): string
{
    $structured = trim(implode(' ', array_filter([
        trim((string)($document['client_street'] ?? '') . ' ' . (string)($document['client_building_no'] ?? '') . (!empty($document['client_apartment_no']) ? '/' . $document['client_apartment_no'] : '')),
        trim((string)($document['client_postal_code'] ?? '') . ' ' . (string)($document['client_city'] ?? '')),
    ])));

    return $structured !== '' ? $structured : trim((string)($document['client_address'] ?? ''));
}

function orderDocumentPdfSanitizeTermsHtml(string $html): string
{
    $html = preg_replace('#<(script|iframe|object|embed)\b[^>]*>.*?</\1>#is', '', $html) ?? '';
    $html = preg_replace('#<(script|iframe|object|embed)\b[^>]*/?>#is', '', $html) ?? '';
    $html = preg_replace('/\son[a-z]+\s*=\s*(".*?"|\'.*?\'|[^\s>]+)/is', '', $html) ?? '';
    $html = preg_replace('/(href|src)\s*=\s*("|\')\s*javascript:.*?\2/is', '$1="#"', $html) ?? '';
    return strip_tags($html, '<p><br><strong><b><em><i><u><ol><ul><li><h2><h3><h4><table><thead><tbody><tr><th><td>');
}

function orderDocumentPdfFallbackTermsHtml(): string
{
    return '<p>Do dokumentu stosuje siÄ™ ogĂłlne warunki zamĂłwienia obowiÄ…zujÄ…ce u Wykonawcy w dniu wystawienia dokumentu.</p>';
}

function orderDocumentPdfResolveTermsSnapshot(PDO $pdo, array $document): array
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
            'content_html' => orderDocumentPdfSanitizeTermsHtml((string)$snapshot['terms_content_snapshot']),
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

    $template = $find((string)$document['document_type']) ?: $find('all');
    $templateId = $template ? (int)$template['id'] : null;
    $version = $template ? (string)$template['version'] : 'fallback';
    $contentHtml = $template
        ? orderDocumentPdfSanitizeTermsHtml((string)$template['content_html'])
        : orderDocumentPdfFallbackTermsHtml();

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

function orderDocumentPdfRenderHtml(array $document): string
{
    $currency = (string)($document['currency'] ?? 'PLN');
    $logoDataUri = orderDocumentPdfLogoDataUri($document['owner_logo_path'] ?? null);
    $ownerAddress = trim(implode(' ', array_filter([
        $document['owner_address_street'] ?? '',
        $document['owner_address_postal_code'] ?? '',
        $document['owner_address_city'] ?? '',
    ])));
    $clientAddress = orderDocumentPdfClientAddress($document);
    $spotSource = (string)($document['spot_source'] ?? '');
    $spotSourceText = $spotSource === 'radio_production'
        ? 'MateriaĹ‚ reklamowy przygotowuje Wykonawca'
        : 'MateriaĹ‚ reklamowy dostarcza ZamawiajÄ…cy';
    $dynamicOwz = $spotSource === 'radio_production'
        ? 'Produkcja materiaĹ‚u reklamowego przez WykonawcÄ™ obejmuje przygotowanie spotu zgodnie z ustaleniami stron. Dodatkowe poprawki ponad jednÄ… turÄ™ mogÄ… byÄ‡ wycenione osobno.'
        : 'ZamawiajÄ…cy ponosi peĹ‚nÄ… odpowiedzialnoĹ›Ä‡ za dostarczony materiaĹ‚ reklamowy, w tym za prawa autorskie, prawa pokrewne oraz zgodnoĹ›Ä‡ treĹ›ci z obowiÄ…zujÄ…cymi przepisami.';

    $owzHtml = orderDocumentPdfSanitizeTermsHtml((string)($document['terms_content_snapshot'] ?? orderDocumentPdfFallbackTermsHtml()));
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
.logo { max-width: 150px; max-height: 70px; margin-bottom: 8px; }
.owner-name { font-size: 18px; font-weight: 700; color: #0b2b5c; margin-bottom: 4px; }
.doc-title { font-size: 21px; font-weight: 700; color: #0b2b5c; margin: 0 0 5px; text-align: right; }
.doc-meta { text-align: right; line-height: 1.6; }
.section { margin-top: 16px; }
.section-title { background: #0b2b5c; color: #fff; padding: 7px 10px; font-weight: 700; font-size: 12px; }
.box { border: 1px solid #d7dfec; padding: 10px; }
.grid { width: 100%; border-collapse: collapse; }
.grid td { vertical-align: top; width: 50%; }
.kv { width: 100%; border-collapse: collapse; }
.kv th { width: 35%; text-align: left; background: #f2f6fb; color: #0b2b5c; }
.kv th, .kv td { border: 1px solid #d7dfec; padding: 7px; vertical-align: top; }
.values { width: 100%; border-collapse: collapse; margin-top: 6px; }
.values th { background: #f2f6fb; color: #0b2b5c; text-align: left; }
.values th, .values td { border: 1px solid #d7dfec; padding: 8px; }
.values .num { text-align: right; }
.highlight { border: 2px solid #0b2b5c; background: #eef5ff; padding: 9px; font-weight: 700; margin: 8px 0; }
.owz li { margin-bottom: 5px; }
.signatures { width: 100%; margin-top: 34px; border-collapse: collapse; }
.signatures td { width: 50%; text-align: center; padding-top: 36px; }
.line { border-top: 1px solid #1f2937; padding-top: 7px; width: 80%; margin: 0 auto; }
</style>
</head>
<body>
<div class="footer">Dokument ' . orderDocumentPdfH($document['document_number']) . '</div>
<div class="header">
  <table class="header-table">
    <tr>
      <td style="width: 52%;">
        ' . ($logoDataUri !== '' ? '<img class="logo" src="' . orderDocumentPdfH($logoDataUri) . '" alt="Logo">' : '') . '
        <div class="owner-name">' . orderDocumentPdfH($document['owner_company_name'] ?: '-') . '</div>
        <div>' . orderDocumentPdfH($ownerAddress ?: '-') . '</div>
        <div>NIP: ' . orderDocumentPdfH($document['owner_nip'] ?: '-') . '</div>
        <div>Email: ' . orderDocumentPdfH($document['owner_email'] ?: '-') . '</div>
        <div>Telefon: ' . orderDocumentPdfH($document['owner_phone'] ?: '-') . '</div>
      </td>
      <td style="width: 48%;">
        <div class="doc-title">Zlecenie emisji reklamy</div>
        <div class="doc-meta">
          <strong>Numer:</strong> ' . orderDocumentPdfH($document['document_number']) . '<br>
          <strong>Data wystawienia:</strong> ' . orderDocumentPdfH($document['issue_date'] ?: '-') . '
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
        <strong>' . orderDocumentPdfH($document['client_name'] ?: '-') . '</strong><br>
        ' . orderDocumentPdfH($clientAddress ?: '-') . '<br>
        NIP: ' . orderDocumentPdfH($document['client_nip'] ?: '-') . '<br>
        Email: ' . orderDocumentPdfH($document['client_email'] ?: '-') . '
      </div>
    </td>
    <td style="padding-left: 8px;">
      <div class="section-title">Parametry emisji</div>
      <div class="box">
        Okres emisji: <strong>' . orderDocumentPdfH($document['valid_from'] ?: '-') . ' - ' . orderDocumentPdfH($document['valid_to'] ?: '-') . '</strong><br>
        Liczba emisji: <strong>' . orderDocumentPdfH($document['emission_count'] ?? '0') . '</strong><br>
        DĹ‚ugoĹ›Ä‡ spotu: <strong>' . orderDocumentPdfH($document['spot_length_seconds'] ?? '0') . ' s</strong><br>
        Termin materiaĹ‚u: <strong>' . orderDocumentPdfH($document['material_deadline'] ?: '-') . '</strong>
      </div>
    </td>
  </tr>
</table>

<div class="section">
  <div class="section-title">TreĹ›Ä‡ zlecenia</div>
  <table class="kv">
    <tr><th>TytuĹ‚</th><td>' . orderDocumentPdfH($document['title'] ?: 'Zlecenie emisji reklamy') . '</td></tr>
    <tr><th>ĹąrĂłdĹ‚o spotu</th><td>' . orderDocumentPdfH($spotSourceText) . '</td></tr>
    <tr><th>Uwagi techniczne</th><td>' . nl2br(orderDocumentPdfH($document['technical_notes'] ?: '-')) . '</td></tr>
    <tr><th>Notatki</th><td>' . nl2br(orderDocumentPdfH($document['notes'] ?: '-')) . '</td></tr>
  </table>
</div>

<div class="section">
  <div class="section-title">WartoĹ›ci</div>
  <table class="values">
    <tr><th>Netto</th><th>Stawka VAT</th><th>VAT</th><th>Brutto</th></tr>
    <tr>
      <td class="num">' . orderDocumentPdfH(orderDocumentPdfMoney($document['net_value'], $currency)) . '</td>
      <td class="num">' . orderDocumentPdfH((string)$document['vat_rate']) . '%</td>
      <td class="num">' . orderDocumentPdfH(orderDocumentPdfMoney($document['vat_value'], $currency)) . '</td>
      <td class="num"><strong>' . orderDocumentPdfH(orderDocumentPdfMoney($document['gross_value'], $currency)) . '</strong></td>
    </tr>
  </table>
</div>

<div class="section">
  <div class="section-title">OgĂłlne warunki zamĂłwienia</div>
  <div class="small">Wersja OWZ: ' . orderDocumentPdfH($owzVersion) . '</div>
  <div class="owz">' . $owzHtml . '</div>
</div>

<div class="section">
  <div class="section-title">Warunek zaleĹĽny od ĹşrĂłdĹ‚a spotu</div>
  <div class="highlight">' . orderDocumentPdfH($dynamicOwz) . '</div>
</div>

<table class="signatures">
  <tr>
    <td><div class="line">Podpis ZamawiajÄ…cego</div></td>
    <td><div class="line">Podpis Wykonawcy</div></td>
  </tr>
</table>
</body>
</html>';
}

function orderDocumentPdfTemplateVars(array $document): array
{
    $currency = (string)($document['currency'] ?? 'PLN');
    $ownerAddress = trim(implode(' ', array_filter([
        $document['owner_address_street'] ?? '',
        $document['owner_address_postal_code'] ?? '',
        $document['owner_address_city'] ?? '',
    ])));
    $clientAddress = orderDocumentPdfClientAddress($document);
    $spotSource = (string)($document['spot_source'] ?? '');
    $spotSourceText = $spotSource === 'radio_production'
        ? 'Materiał reklamowy przygotowuje Wykonawca'
        : 'Materiał reklamowy dostarcza Zamawiający';
    $dynamicOwz = $spotSource === 'radio_production'
        ? 'Produkcja materiału reklamowego przez Wykonawcę obejmuje przygotowanie spotu zgodnie z ustaleniami stron. Dodatkowe poprawki ponad jedną turę mogą być wycenione osobno.'
        : 'Zamawiający ponosi pełną odpowiedzialność za dostarczony materiał reklamowy, w tym za prawa autorskie, prawa pokrewne oraz zgodność treści z obowiązującymi przepisami.';
    $owzVersion = (string)($document['terms_version'] ?? 'fallback');
    $termsHtml = orderDocumentPdfSanitizeTermsHtml((string)($document['terms_content_snapshot'] ?? orderDocumentPdfFallbackTermsHtml()));
    $orderDetails = 'Liczba emisji: <strong>' . orderDocumentPdfH($document['emission_count'] ?? '0') . '</strong><br>'
        . 'Długość spotu: <strong>' . orderDocumentPdfH($document['spot_length_seconds'] ?? '0') . ' s</strong><br>'
        . 'Termin materiału: <strong>' . orderDocumentPdfH($document['material_deadline'] ?: '-') . '</strong><br>'
        . 'Źródło spotu: <strong>' . orderDocumentPdfH($spotSourceText) . '</strong><br>'
        . 'Uwagi techniczne: ' . nl2br(orderDocumentPdfH($document['technical_notes'] ?: '-')) . '<br>'
        . 'Notatki: ' . nl2br(orderDocumentPdfH($document['notes'] ?: '-'));

    return [
        'DOCUMENT_NUMBER' => orderDocumentPdfH($document['document_number'] ?? ''),
        'DOCUMENT_TYPE_LABEL' => 'Zlecenie',
        'DOCUMENT_TITLE' => orderDocumentPdfH($document['title'] ?: 'Zlecenie emisji reklamy'),
        'ISSUE_DATE' => orderDocumentPdfH($document['issue_date'] ?: '-'),
        'VALID_FROM' => orderDocumentPdfH($document['valid_from'] ?: '-'),
        'VALID_TO' => orderDocumentPdfH($document['valid_to'] ?: '-'),
        'CLIENT_NAME' => orderDocumentPdfH($document['client_name'] ?: '-'),
        'CLIENT_NIP' => orderDocumentPdfH($document['client_nip'] ?: '-'),
        'CLIENT_ADDRESS' => orderDocumentPdfH($clientAddress ?: '-'),
        'CLIENT_EMAIL' => orderDocumentPdfH($document['client_email'] ?: '-'),
        'COMPANY_NAME' => orderDocumentPdfH($document['owner_company_name'] ?: '-'),
        'COMPANY_NIP' => orderDocumentPdfH($document['owner_nip'] ?: '-'),
        'COMPANY_ADDRESS' => orderDocumentPdfH($ownerAddress ?: '-'),
        'COMPANY_EMAIL' => orderDocumentPdfH($document['owner_email'] ?: '-'),
        'COMPANY_PHONE' => orderDocumentPdfH($document['owner_phone'] ?: '-'),
        'NET_VALUE' => orderDocumentPdfH(orderDocumentPdfMoney($document['net_value'], $currency)),
        'VAT_RATE' => orderDocumentPdfH((string)$document['vat_rate']) . '%',
        'VAT_VALUE' => orderDocumentPdfH(orderDocumentPdfMoney($document['vat_value'], $currency)),
        'GROSS_VALUE' => orderDocumentPdfH(orderDocumentPdfMoney($document['gross_value'], $currency)),
        'CURRENCY' => orderDocumentPdfH($currency),
        'ORDER_DETAILS' => $orderDetails,
        'ANNEX_DETAILS' => '',
        'TERMS_HTML' => '<div class="small">Wersja OWZ: ' . orderDocumentPdfH($owzVersion) . '</div>' . $termsHtml,
        'DYNAMIC_TERMS_HTML' => orderDocumentPdfH($dynamicOwz),
        'SIGNATURES_HTML' => '<table class="signatures"><tr><td><div class="line">Podpis Zamawiającego</div></td><td><div class="line">Podpis Wykonawcy</div></td></tr></table>',
    ];
}

function orderDocumentPdfBinary(array $document): string
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
        : orderDocumentPdfRenderHtml($document);
    $dompdf->loadHtml($html, 'UTF-8');
    $dompdf->render();
    return $dompdf->output();
}

function orderDocumentPdfGenerateAndSave(PDO $pdo, int $documentId): array
{
    $startedTransaction = false;
    if (!$pdo->inTransaction()) {
        ensureDocumentOrderDetailsTable($pdo);
        ensureDocumentTermsTables($pdo);
        ensureDocumentPdfVersionsTable($pdo);
        ensureDocumentPdfTemplatesTable($pdo);
        $pdo->beginTransaction();
        $startedTransaction = true;
    }

    try {
        $document = orderDocumentPdfLoad($pdo, $documentId);
        $terms = orderDocumentPdfResolveTermsSnapshot($pdo, $document);
        $document['terms_template_id'] = $terms['template_id'];
        $document['terms_version'] = $terms['version'];
        $document['terms_content_snapshot'] = $terms['content_html'];
        $template = getActiveDocumentPdfTemplate($pdo, 'order');
        if ($template) {
            $document['_pdf_template_html'] = buildDocumentPdfTemplateDocument(
                sanitizeDocumentPdfTemplateHtml((string)$template['html_template']),
                sanitizeDocumentPdfTemplateCss((string)($template['css_template'] ?? '')),
                orderDocumentPdfTemplateVars($document)
            );
        }
        $pdf = orderDocumentPdfBinary($document);

        $uploadDir = dirname(__DIR__) . '/uploads/documents';
        if (!is_dir($uploadDir) && !mkdir($uploadDir, 0775, true) && !is_dir($uploadDir)) {
            throw new RuntimeException('Nie udaĹ‚o siÄ™ utworzyÄ‡ katalogu uploads/documents.');
        }

        $safeNumber = preg_replace('/[^A-Za-z0-9_-]+/', '_', (string)$document['document_number']);
        $filename = 'zlecenie_' . trim((string)$safeNumber, '_') . '_' . date('Ymd_His') . '.pdf';
        $fullPath = $uploadDir . '/' . $filename;
        if (file_exists($fullPath)) {
            $filename = 'zlecenie_' . trim((string)$safeNumber, '_') . '_' . date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.pdf';
            $fullPath = $uploadDir . '/' . $filename;
        }

        if (file_put_contents($fullPath, $pdf) === false) {
            throw new RuntimeException('Nie udaĹ‚o siÄ™ zapisaÄ‡ pliku PDF.');
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
