<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/includes/auth.php';
requireCapability('manage_system');

require_once __DIR__ . '/includes/email_templates.php';
require_once __DIR__ . '/includes/document_audit.php';

ensureEmailTemplatesTable($pdo);
ensureDocumentAuditLogTable($pdo);

function emailTplH($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

$alerts = [];
$editId = (int)($_GET['edit'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isCsrfTokenValid($_POST['csrf_token'] ?? '')) {
        $alerts[] = ['type' => 'danger', 'msg' => 'Niepoprawny token CSRF.'];
    } else {
        $action = (string)($_POST['action'] ?? '');
        $templateId = (int)($_POST['template_id'] ?? 0);
        $stmt = $pdo->prepare('SELECT * FROM email_templates WHERE id = :id LIMIT 1');
        $stmt->execute([':id' => $templateId]);
        $template = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;

        if (!$template) {
            $alerts[] = ['type' => 'danger', 'msg' => 'Nie znaleziono szablonu.'];
        } elseif ($action === 'save') {
            $subject = sanitizeEmailTemplateInput((string)($_POST['subject_template'] ?? ''));
            $body = sanitizeEmailTemplateInput((string)($_POST['body_template'] ?? ''));
            if ($subject === '' || $body === '') {
                $alerts[] = ['type' => 'danger', 'msg' => 'Temat i treść są wymagane.'];
            } else {
                $update = $pdo->prepare('UPDATE email_templates
                    SET subject_template = :subject_template, body_template = :body_template, updated_at = CURRENT_TIMESTAMP
                    WHERE id = :id');
                $update->execute([
                    ':subject_template' => $subject,
                    ':body_template' => $body,
                    ':id' => $templateId,
                ]);
                logDocumentAudit($pdo, 0, 'email_template_updated', 'Zaktualizowano szablon e-mail', [
                    'user_id' => (int)($_SESSION['user_id'] ?? 0),
                    'old_value' => (string)$template['template_key'],
                    'new_value' => (string)$template['template_key'],
                    'metadata' => ['template_id' => $templateId, 'template_key' => $template['template_key']],
                ]);
                $alerts[] = ['type' => 'success', 'msg' => 'Szablon został zapisany.'];
                $editId = $templateId;
            }
        } elseif ($action === 'toggle') {
            $newActive = empty($template['is_active']) ? 1 : 0;
            $pdo->prepare('UPDATE email_templates SET is_active = :is_active, updated_at = CURRENT_TIMESTAMP WHERE id = :id')
                ->execute([':is_active' => $newActive, ':id' => $templateId]);
            logDocumentAudit($pdo, 0, $newActive ? 'email_template_activated' : 'email_template_deactivated', $newActive ? 'Aktywowano szablon e-mail' : 'Dezaktywowano szablon e-mail', [
                'user_id' => (int)($_SESSION['user_id'] ?? 0),
                'old_value' => !empty($template['is_active']) ? 'active' : 'inactive',
                'new_value' => $newActive ? 'active' : 'inactive',
                'metadata' => ['template_id' => $templateId, 'template_key' => $template['template_key']],
            ]);
            $alerts[] = ['type' => 'success', 'msg' => $newActive ? 'Szablon aktywowany.' : 'Szablon dezaktywowany.'];
        }
    }
}

$templates = $pdo->query('SELECT * FROM email_templates ORDER BY template_key ASC')->fetchAll(PDO::FETCH_ASSOC);
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

$placeholders = emailTemplatePlaceholders();
$pageTitle = 'Szablony e-mail';
include __DIR__ . '/includes/header.php';
?>

<main class="container py-4" role="main" aria-labelledby="email-templates-heading">
    <div class="d-flex flex-wrap justify-content-between align-items-start gap-3 mb-4">
        <div>
            <p class="text-uppercase text-muted fw-semibold small mb-1">Ustawienia</p>
            <h1 id="email-templates-heading" class="h3 mb-2">Szablony e-mail</h1>
            <p class="text-muted mb-0">Treści wiadomości wysyłanych z dokumentami.</p>
        </div>
        <a class="btn btn-outline-secondary btn-sm" href="<?= emailTplH(BASE_URL . '/ustawienia.php') ?>">Wróć do ustawień</a>
    </div>

    <?php foreach ($alerts as $alert): ?>
        <div class="alert alert-<?= emailTplH($alert['type']) ?>"><?= emailTplH($alert['msg']) ?></div>
    <?php endforeach; ?>

    <div class="row g-4">
        <div class="col-lg-4">
            <section class="card shadow-sm">
                <div class="card-body">
                    <h2 class="h5 mb-3">Lista szablonów</h2>
                    <div class="list-group">
                        <?php foreach ($templates as $template): ?>
                            <a class="list-group-item list-group-item-action <?= $editTemplate && (int)$editTemplate['id'] === (int)$template['id'] ? 'active' : '' ?>" href="<?= emailTplH(BASE_URL . '/email_templates.php?edit=' . (int)$template['id']) ?>">
                                <div class="fw-semibold"><?= emailTplH($template['name']) ?></div>
                                <div class="small"><?= emailTplH($template['template_key']) ?></div>
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
                            <code><?= emailTplH($placeholder) ?></code>
                        <?php endforeach; ?>
                    </div>
                </div>
            </section>
        </div>
        <div class="col-lg-8">
            <?php if ($editTemplate): ?>
                <section class="card shadow-sm">
                    <div class="card-body">
                        <h2 class="h5 mb-3"><?= emailTplH($editTemplate['name']) ?></h2>
                        <form method="post">
                            <input type="hidden" name="csrf_token" value="<?= emailTplH(getCsrfToken()) ?>">
                            <input type="hidden" name="action" value="save">
                            <input type="hidden" name="template_id" value="<?= (int)$editTemplate['id'] ?>">
                            <div class="mb-3">
                                <label class="form-label" for="subject_template">Temat</label>
                                <input class="form-control" type="text" id="subject_template" name="subject_template" required value="<?= emailTplH($editTemplate['subject_template']) ?>">
                            </div>
                            <div class="mb-3">
                                <label class="form-label" for="body_template">Treść</label>
                                <textarea class="form-control" id="body_template" name="body_template" rows="12" required><?= emailTplH($editTemplate['body_template']) ?></textarea>
                            </div>
                            <div class="d-flex flex-wrap justify-content-between gap-2">
                                <button class="btn btn-primary" type="submit">Zapisz szablon</button>
                            </div>
                        </form>
                        <form method="post" class="mt-3">
                            <input type="hidden" name="csrf_token" value="<?= emailTplH(getCsrfToken()) ?>">
                            <input type="hidden" name="action" value="toggle">
                            <input type="hidden" name="template_id" value="<?= (int)$editTemplate['id'] ?>">
                            <button class="btn <?= !empty($editTemplate['is_active']) ? 'btn-outline-warning' : 'btn-outline-success' ?>" type="submit">
                                <?= !empty($editTemplate['is_active']) ? 'Dezaktywuj' : 'Aktywuj' ?>
                            </button>
                        </form>
                    </div>
                </section>
                <section class="card shadow-sm mt-3">
                    <div class="card-body">
                        <h2 class="h6 mb-2">Podgląd</h2>
                        <?php
                            $previewVars = [
                                'DOCUMENT_NUMBER' => 'ZL/2026/05/001',
                                'DOCUMENT_TYPE' => 'Zlecenie',
                                'CLIENT_NAME' => 'Przykładowy Klient',
                                'COMPANY_NAME' => 'Radio CRM',
                                'GROSS_VALUE' => '1 230,00',
                                'CURRENCY' => 'PLN',
                                'ACCEPTANCE_LINK' => 'https://example.test/akceptacja_dokumentu.php?t=...',
                                'PDF_FILE_NAME' => 'dokument.pdf',
                            ];
                        ?>
                        <p class="fw-semibold"><?= emailTplH(renderEmailTemplate((string)$editTemplate['subject_template'], $previewVars)) ?></p>
                        <pre class="border rounded p-3 bg-light" style="white-space:pre-wrap;"><?= emailTplH(renderEmailTemplate((string)$editTemplate['body_template'], $previewVars)) ?></pre>
                    </div>
                </section>
            <?php endif; ?>
        </div>
    </div>
</main>

<?php include __DIR__ . '/includes/footer.php'; ?>
