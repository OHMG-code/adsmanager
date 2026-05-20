<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/includes/auth.php';
requireCapability('manage_system');

require_once __DIR__ . '/includes/document_pdf_templates.php';
require_once __DIR__ . '/includes/document_audit.php';

ensureDocumentPdfTemplatesTable($pdo);
ensureDocumentAuditLogTable($pdo);

function pdfTplH($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function nextPdfTemplateVersion(string $version): string
{
    if (preg_match('/^(\d+)(?:\.(\d+))?$/', trim($version), $m)) {
        $major = (int)$m[1];
        $minor = isset($m[2]) ? (int)$m[2] : 0;
        return $major . '.' . ($minor + 1);
    }
    return '1.0-' . date('YmdHis');
}

$alerts = [];
$editId = (int)($_GET['edit'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isCsrfTokenValid($_POST['csrf_token'] ?? '')) {
        $alerts[] = ['type' => 'danger', 'msg' => 'Niepoprawny token CSRF.'];
    } else {
        $action = (string)($_POST['action'] ?? '');
        $templateId = (int)($_POST['template_id'] ?? 0);
        $stmt = $pdo->prepare('SELECT * FROM document_pdf_templates WHERE id = :id LIMIT 1');
        $stmt->execute([':id' => $templateId]);
        $template = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;

        if (!$template) {
            $alerts[] = ['type' => 'danger', 'msg' => 'Nie znaleziono szablonu.'];
        } elseif ($action === 'save') {
            $name = trim((string)($_POST['name'] ?? ''));
            $version = trim((string)($_POST['version'] ?? ''));
            $html = sanitizeDocumentPdfTemplateHtml((string)($_POST['html_template'] ?? ''));
            $css = sanitizeDocumentPdfTemplateCss((string)($_POST['css_template'] ?? ''));
            if ($name === '' || $version === '' || $html === '') {
                $alerts[] = ['type' => 'danger', 'msg' => 'Nazwa, wersja i HTML są wymagane.'];
            } else {
                $update = $pdo->prepare('UPDATE document_pdf_templates
                    SET name = :name, version = :version, html_template = :html_template, css_template = :css_template, updated_at = CURRENT_TIMESTAMP
                    WHERE id = :id');
                $update->execute([
                    ':name' => $name,
                    ':version' => $version,
                    ':html_template' => $html,
                    ':css_template' => $css,
                    ':id' => $templateId,
                ]);
                logDocumentAudit($pdo, 0, 'document_pdf_template_updated', 'Zaktualizowano szablon PDF', [
                    'user_id' => (int)($_SESSION['user_id'] ?? 0),
                    'old_value' => (string)$template['version'],
                    'new_value' => $version,
                    'metadata' => ['template_id' => $templateId, 'document_type' => $template['document_type']],
                ]);
                $alerts[] = ['type' => 'success', 'msg' => 'Szablon został zapisany.'];
                $editId = $templateId;
            }
        } elseif ($action === 'toggle') {
            $newActive = empty($template['is_active']) ? 1 : 0;
            if ($newActive) {
                $pdo->prepare('UPDATE document_pdf_templates SET is_active = 0, updated_at = CURRENT_TIMESTAMP WHERE document_type = :document_type')
                    ->execute([':document_type' => (string)$template['document_type']]);
            }
            $pdo->prepare('UPDATE document_pdf_templates SET is_active = :is_active, updated_at = CURRENT_TIMESTAMP WHERE id = :id')
                ->execute([':is_active' => $newActive, ':id' => $templateId]);
            logDocumentAudit($pdo, 0, $newActive ? 'document_pdf_template_activated' : 'document_pdf_template_deactivated', $newActive ? 'Aktywowano szablon PDF' : 'Dezaktywowano szablon PDF', [
                'user_id' => (int)($_SESSION['user_id'] ?? 0),
                'old_value' => !empty($template['is_active']) ? 'active' : 'inactive',
                'new_value' => $newActive ? 'active' : 'inactive',
                'metadata' => ['template_id' => $templateId, 'document_type' => $template['document_type']],
            ]);
            $alerts[] = ['type' => 'success', 'msg' => $newActive ? 'Szablon aktywowany.' : 'Szablon dezaktywowany.'];
            $editId = $templateId;
        } elseif ($action === 'clone') {
            $newVersion = nextPdfTemplateVersion((string)$template['version']);
            $insert = $pdo->prepare('INSERT INTO document_pdf_templates
                (document_type, name, version, html_template, css_template, is_active, created_by, created_at, updated_at)
                VALUES (:document_type, :name, :version, :html_template, :css_template, 0, :created_by, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)');
            $insert->execute([
                ':document_type' => (string)$template['document_type'],
                ':name' => (string)$template['name'] . ' - wersja ' . $newVersion,
                ':version' => $newVersion,
                ':html_template' => (string)$template['html_template'],
                ':css_template' => (string)($template['css_template'] ?? ''),
                ':created_by' => (int)($_SESSION['user_id'] ?? 0) ?: null,
            ]);
            $newId = (int)$pdo->lastInsertId();
            logDocumentAudit($pdo, 0, 'document_pdf_template_created', 'Utworzono nową wersję szablonu PDF', [
                'user_id' => (int)($_SESSION['user_id'] ?? 0),
                'old_value' => (string)$template['version'],
                'new_value' => $newVersion,
                'metadata' => ['source_template_id' => $templateId, 'template_id' => $newId, 'document_type' => $template['document_type']],
            ]);
            $alerts[] = ['type' => 'success', 'msg' => 'Utworzono nową wersję szablonu.'];
            $editId = $newId;
        }
    }
}

$templates = $pdo->query('SELECT * FROM document_pdf_templates ORDER BY document_type ASC, id DESC')->fetchAll(PDO::FETCH_ASSOC);
$editTemplate = null;
if ($editId > 0) {
    foreach ($templates as $row) {
        if ((int)$row['id'] === $editId) {
            $editTemplate = $row;
            break;
        }
    }
}
if (!$editTemplate && $templates) {
    $editTemplate = $templates[0];
}

$placeholders = documentPdfTemplatePlaceholders();
$previewVars = [
    'DOCUMENT_NUMBER' => 'ZL/2026/05/001',
    'DOCUMENT_TYPE_LABEL' => 'Zlecenie',
    'DOCUMENT_TITLE' => 'Kampania reklamowa',
    'ISSUE_DATE' => '2026-05-20',
    'VALID_FROM' => '2026-06-01',
    'VALID_TO' => '2026-06-30',
    'CLIENT_NAME' => 'Przykładowy Klient',
    'CLIENT_NIP' => '1234567890',
    'CLIENT_ADDRESS' => 'ul. Radiowa 1, 82-200 Malbork',
    'CLIENT_EMAIL' => 'klient@example.test',
    'COMPANY_NAME' => 'Radio CRM',
    'COMPANY_NIP' => '9876543210',
    'COMPANY_ADDRESS' => 'ul. Nadawcza 10, 82-200 Malbork',
    'COMPANY_EMAIL' => 'biuro@example.test',
    'COMPANY_PHONE' => '+48 000 000 000',
    'NET_VALUE' => '1 000,00 PLN',
    'VAT_RATE' => '23%',
    'VAT_VALUE' => '230,00 PLN',
    'GROSS_VALUE' => '1 230,00 PLN',
    'CURRENCY' => 'PLN',
    'ORDER_DETAILS' => 'Liczba emisji: <strong>30</strong><br>Długość spotu: <strong>30 s</strong>',
    'ANNEX_DETAILS' => '<p>Zmiana okresu emisji.</p>',
    'TERMS_HTML' => '<ol><li>Przykładowy warunek OWZ.</li></ol>',
    'DYNAMIC_TERMS_HTML' => 'Przykładowy warunek zależny od źródła spotu.',
    'SIGNATURES_HTML' => '<table class="signatures"><tr><td><div class="line">Podpis Zamawiającego</div></td><td><div class="line">Podpis Wykonawcy</div></td></tr></table>',
];

$pageTitle = 'Szablony PDF';
include __DIR__ . '/includes/header.php';
?>

<main class="container py-4" role="main" aria-labelledby="pdf-templates-heading">
    <div class="d-flex flex-wrap justify-content-between align-items-start gap-3 mb-4">
        <div>
            <p class="text-uppercase text-muted fw-semibold small mb-1">Ustawienia</p>
            <h1 id="pdf-templates-heading" class="h3 mb-2">Szablony PDF</h1>
            <p class="text-muted mb-0">HTML i CSS dokumentów PDF dla zleceń i aneksów.</p>
        </div>
        <a class="btn btn-outline-secondary btn-sm" href="<?= pdfTplH(BASE_URL . '/ustawienia.php') ?>">Wróć do ustawień</a>
    </div>

    <?php foreach ($alerts as $alert): ?>
        <div class="alert alert-<?= pdfTplH($alert['type']) ?>"><?= pdfTplH($alert['msg']) ?></div>
    <?php endforeach; ?>

    <div class="row g-4">
        <div class="col-lg-4">
            <section class="card shadow-sm">
                <div class="card-body">
                    <h2 class="h5 mb-3">Lista szablonów</h2>
                    <div class="list-group">
                        <?php foreach ($templates as $template): ?>
                            <a class="list-group-item list-group-item-action <?= $editTemplate && (int)$editTemplate['id'] === (int)$template['id'] ? 'active' : '' ?>" href="<?= pdfTplH(BASE_URL . '/document_pdf_templates.php?edit=' . (int)$template['id']) ?>">
                                <div class="fw-semibold"><?= pdfTplH($template['name']) ?></div>
                                <div class="small"><?= pdfTplH($template['document_type']) ?> / v<?= pdfTplH($template['version']) ?></div>
                                <span class="badge <?= !empty($template['is_active']) ? 'text-bg-success' : 'text-bg-secondary' ?> mt-2">
                                    <?= !empty($template['is_active']) ? 'aktywny' : 'nieaktywny' ?>
                                </span>
                            </a>
                        <?php endforeach; ?>
                    </div>
                </div>
            </section>
            <section class="card shadow-sm mt-3">
                <div class="card-body">
                    <h2 class="h6 mb-2">Placeholdery</h2>
                    <div class="d-flex flex-wrap gap-2">
                        <?php foreach ($placeholders as $placeholder): ?>
                            <code><?= pdfTplH($placeholder) ?></code>
                        <?php endforeach; ?>
                    </div>
                </div>
            </section>
        </div>
        <div class="col-lg-8">
            <?php if ($editTemplate): ?>
                <section class="card shadow-sm">
                    <div class="card-body">
                        <h2 class="h5 mb-3"><?= pdfTplH($editTemplate['name']) ?></h2>
                        <form method="post">
                            <input type="hidden" name="csrf_token" value="<?= pdfTplH(getCsrfToken()) ?>">
                            <input type="hidden" name="action" value="save">
                            <input type="hidden" name="template_id" value="<?= (int)$editTemplate['id'] ?>">
                            <div class="row g-3">
                                <div class="col-md-8">
                                    <label class="form-label" for="name">Nazwa</label>
                                    <input class="form-control" type="text" id="name" name="name" required value="<?= pdfTplH($editTemplate['name']) ?>">
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label" for="version">Wersja</label>
                                    <input class="form-control" type="text" id="version" name="version" required value="<?= pdfTplH($editTemplate['version']) ?>">
                                </div>
                            </div>
                            <div class="mt-3">
                                <label class="form-label" for="html_template">HTML</label>
                                <textarea class="form-control font-monospace" id="html_template" name="html_template" rows="14" required><?= pdfTplH($editTemplate['html_template']) ?></textarea>
                            </div>
                            <div class="mt-3">
                                <label class="form-label" for="css_template">CSS</label>
                                <textarea class="form-control font-monospace" id="css_template" name="css_template" rows="10"><?= pdfTplH($editTemplate['css_template']) ?></textarea>
                            </div>
                            <div class="d-flex flex-wrap gap-2 mt-3">
                                <button class="btn btn-primary" type="submit">Zapisz szablon</button>
                            </div>
                        </form>
                        <div class="d-flex flex-wrap gap-2 mt-3">
                            <form method="post">
                                <input type="hidden" name="csrf_token" value="<?= pdfTplH(getCsrfToken()) ?>">
                                <input type="hidden" name="action" value="toggle">
                                <input type="hidden" name="template_id" value="<?= (int)$editTemplate['id'] ?>">
                                <button class="btn <?= !empty($editTemplate['is_active']) ? 'btn-outline-warning' : 'btn-outline-success' ?>" type="submit">
                                    <?= !empty($editTemplate['is_active']) ? 'Dezaktywuj' : 'Aktywuj' ?>
                                </button>
                            </form>
                            <form method="post">
                                <input type="hidden" name="csrf_token" value="<?= pdfTplH(getCsrfToken()) ?>">
                                <input type="hidden" name="action" value="clone">
                                <input type="hidden" name="template_id" value="<?= (int)$editTemplate['id'] ?>">
                                <button class="btn btn-outline-primary" type="submit">Utwórz nową wersję</button>
                            </form>
                        </div>
                    </div>
                </section>
                <section class="card shadow-sm mt-3">
                    <div class="card-body">
                        <h2 class="h6 mb-2">Podgląd HTML</h2>
                        <?php $previewHtml = buildDocumentPdfTemplateDocument((string)$editTemplate['html_template'], (string)($editTemplate['css_template'] ?? ''), $previewVars); ?>
                        <iframe class="w-100 border rounded bg-white" style="min-height:520px;" srcdoc="<?= pdfTplH($previewHtml) ?>"></iframe>
                    </div>
                </section>
            <?php endif; ?>
        </div>
    </div>
</main>

<?php include __DIR__ . '/includes/footer.php'; ?>
