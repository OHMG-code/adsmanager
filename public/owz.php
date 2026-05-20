<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
requireCapability('manage_system');

require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/includes/db_schema.php';

ensureDocumentTermsTables($pdo);

$pageTitle = 'Ogólne warunki zamówienia';
$csrfToken = getCsrfToken();
$alerts = [];
$documentTypes = [
    'order' => 'Zlecenie',
    'annex' => 'Aneks',
    'all' => 'Wspólne',
];

function owzH($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function owzSanitizeHtml(string $html): string
{
    $html = preg_replace('#<(script|iframe|object|embed)\b[^>]*>.*?</\1>#is', '', $html) ?? '';
    $html = preg_replace('#<(script|iframe|object|embed)\b[^>]*/?>#is', '', $html) ?? '';
    $html = preg_replace('/\son[a-z]+\s*=\s*(".*?"|\'.*?\'|[^\s>]+)/is', '', $html) ?? '';
    $html = preg_replace('/(href|src)\s*=\s*("|\')\s*javascript:.*?\2/is', '$1="#"', $html) ?? '';
    return strip_tags($html, '<p><br><strong><b><em><i><u><ol><ul><li><h2><h3><h4><table><thead><tbody><tr><th><td>');
}

function owzClean(string $key, int $maxLength = 255): string
{
    $value = trim((string)($_POST[$key] ?? ''));
    return function_exists('mb_substr') ? mb_substr($value, 0, $maxLength) : substr($value, 0, $maxLength);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isCsrfTokenValid($_POST['csrf_token'] ?? '')) {
        $alerts[] = ['type' => 'danger', 'msg' => 'Niepoprawny token CSRF.'];
    } else {
        $action = (string)($_POST['action'] ?? '');
        try {
            if ($action === 'create') {
                $documentType = owzClean('document_type', 30);
                $version = owzClean('version', 30);
                $title = owzClean('title', 255);
                $contentHtml = owzSanitizeHtml((string)($_POST['content_html'] ?? ''));
                $validFrom = owzClean('valid_from', 10) ?: null;
                $validTo = owzClean('valid_to', 10) ?: null;
                $makeActive = !empty($_POST['is_active']);

                if (!isset($documentTypes[$documentType])) {
                    throw new RuntimeException('Niepoprawny typ dokumentu.');
                }
                if ($version === '' || $title === '' || trim(strip_tags($contentHtml)) === '') {
                    throw new RuntimeException('Wersja, tytuł i treść są wymagane.');
                }

                $pdo->beginTransaction();
                if ($makeActive) {
                    $deactivate = $pdo->prepare('UPDATE document_terms_templates SET is_active = 0 WHERE document_type = :document_type');
                    $deactivate->execute([':document_type' => $documentType]);
                }
                $stmt = $pdo->prepare("INSERT INTO document_terms_templates
                    (document_type, version, title, content_html, is_active, valid_from, valid_to, created_by)
                    VALUES (:document_type, :version, :title, :content_html, :is_active, :valid_from, :valid_to, :created_by)");
                $stmt->execute([
                    ':document_type' => $documentType,
                    ':version' => $version,
                    ':title' => $title,
                    ':content_html' => $contentHtml,
                    ':is_active' => $makeActive ? 1 : 0,
                    ':valid_from' => $validFrom,
                    ':valid_to' => $validTo,
                    ':created_by' => (int)($_SESSION['user_id'] ?? 0) ?: null,
                ]);
                $pdo->commit();
                $alerts[] = ['type' => 'success', 'msg' => 'Dodano nową wersję OWZ.'];
            } elseif ($action === 'activate') {
                $id = (int)($_POST['id'] ?? 0);
                $stmt = $pdo->prepare('SELECT document_type FROM document_terms_templates WHERE id = :id LIMIT 1');
                $stmt->execute([':id' => $id]);
                $documentType = (string)($stmt->fetchColumn() ?: '');
                if ($documentType === '') {
                    throw new RuntimeException('Nie znaleziono wersji OWZ.');
                }
                $pdo->beginTransaction();
                $pdo->prepare('UPDATE document_terms_templates SET is_active = 0 WHERE document_type = :document_type')->execute([':document_type' => $documentType]);
                $pdo->prepare('UPDATE document_terms_templates SET is_active = 1 WHERE id = :id')->execute([':id' => $id]);
                $pdo->commit();
                $alerts[] = ['type' => 'success', 'msg' => 'Ustawiono aktywną wersję OWZ.'];
            }
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $alerts[] = ['type' => 'danger', 'msg' => $e->getMessage()];
        }
    }
}

$templates = $pdo->query('SELECT * FROM document_terms_templates ORDER BY document_type ASC, created_at DESC, id DESC')->fetchAll(PDO::FETCH_ASSOC);

include __DIR__ . '/includes/header.php';
?>

<main class="container py-4" role="main" aria-labelledby="owz-heading">
    <div class="d-flex flex-wrap justify-content-between align-items-start gap-3 mb-4">
        <div>
            <p class="text-uppercase text-muted fw-semibold small mb-1">Ustawienia</p>
            <h1 id="owz-heading" class="h3 mb-2">Ogólne warunki zamówienia</h1>
            <p class="text-muted mb-0">Wersjonowane szablony OWZ używane przy generowaniu dokumentów sprzedażowych.</p>
        </div>
    </div>

    <?php foreach ($alerts as $alert): ?>
        <div class="alert alert-<?= owzH($alert['type']) ?>"><?= owzH($alert['msg']) ?></div>
    <?php endforeach; ?>

    <div class="row g-4">
        <div class="col-xl-5">
            <form method="post" class="card shadow-sm">
                <input type="hidden" name="csrf_token" value="<?= owzH($csrfToken) ?>">
                <input type="hidden" name="action" value="create">
                <div class="card-body">
                    <h2 class="h5 mb-3">Nowa wersja</h2>
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label" for="document_type">Typ dokumentu</label>
                            <select class="form-select" id="document_type" name="document_type">
                                <?php foreach ($documentTypes as $value => $label): ?>
                                    <option value="<?= owzH($value) ?>"><?= owzH($label) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label" for="version">Wersja</label>
                            <input class="form-control" type="text" id="version" name="version" value="1.1" required>
                        </div>
                        <div class="col-12">
                            <label class="form-label" for="title">Tytuł</label>
                            <input class="form-control" type="text" id="title" name="title" value="Ogólne warunki zamówienia" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label" for="valid_from">Ważne od</label>
                            <input class="form-control" type="date" id="valid_from" name="valid_from" value="<?= owzH(date('Y-m-d')) ?>">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label" for="valid_to">Ważne do</label>
                            <input class="form-control" type="date" id="valid_to" name="valid_to">
                        </div>
                        <div class="col-12">
                            <label class="form-label" for="content_html">Treść HTML</label>
                            <textarea class="form-control" id="content_html" name="content_html" rows="12" required><?= owzH(defaultOrderTermsHtml()) ?></textarea>
                            <div class="form-text">Dozwolone są podstawowe tagi HTML. Skrypty i osadzenia są usuwane przy zapisie.</div>
                        </div>
                        <div class="col-12">
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" id="is_active" name="is_active" value="1" checked>
                                <label class="form-check-label" for="is_active">Ustaw jako aktywną wersję dla wybranego typu</label>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="card-footer d-flex justify-content-end">
                    <button class="btn btn-primary" type="submit">Dodaj wersję</button>
                </div>
            </form>
        </div>
        <div class="col-xl-7">
            <section class="card shadow-sm">
                <div class="card-body">
                    <h2 class="h5 mb-3">Lista wersji</h2>
                    <?php if (!$templates): ?>
                        <p class="text-muted mb-0">Brak szablonów OWZ.</p>
                    <?php endif; ?>
                    <?php foreach ($templates as $template): ?>
                        <article class="border rounded p-3 mb-3">
                            <div class="d-flex flex-wrap justify-content-between gap-2 mb-2">
                                <div>
                                    <strong><?= owzH($template['title']) ?></strong>
                                    <span class="text-muted">v<?= owzH($template['version']) ?></span>
                                    <div class="small text-muted"><?= owzH($documentTypes[$template['document_type']] ?? $template['document_type']) ?> | <?= !empty($template['is_active']) ? 'aktywna' : 'nieaktywna' ?></div>
                                </div>
                                <?php if (empty($template['is_active'])): ?>
                                    <form method="post">
                                        <input type="hidden" name="csrf_token" value="<?= owzH($csrfToken) ?>">
                                        <input type="hidden" name="action" value="activate">
                                        <input type="hidden" name="id" value="<?= (int)$template['id'] ?>">
                                        <button class="btn btn-outline-primary btn-sm" type="submit">Ustaw aktywną</button>
                                    </form>
                                <?php endif; ?>
                            </div>
                            <div class="small text-muted mb-2">Ważność: <?= owzH($template['valid_from'] ?: '-') ?> - <?= owzH($template['valid_to'] ?: '-') ?></div>
                            <div class="border bg-light rounded p-2 small"><?= owzSanitizeHtml((string)$template['content_html']) ?></div>
                        </article>
                    <?php endforeach; ?>
                </div>
            </section>
        </div>
    </div>
</main>

<?php include __DIR__ . '/includes/footer.php'; ?>
