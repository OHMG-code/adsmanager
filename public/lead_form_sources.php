<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
requireCapability('manage_system');
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/db_schema.php';
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../services/InstallationUrl.php';
require_once __DIR__ . '/../services/LeadFormService.php';

$pageTitle = 'Formularze zewnetrzne';
ensureLeadColumns($pdo);
ensureLeadActivityTable($pdo);
ensureLeadFormTables($pdo);

$csrfToken = getCsrfToken();
$alerts = [];
$endpointInfo = InstallationUrl::endpointUrl($pdo, $_SERVER);
$endpointUrl = (string)$endpointInfo['endpoint'];

function leadFormAllowedDomainsText(?string $value): string
{
    return implode("\n", LeadFormService::parseAllowedDomains((string)$value));
}

function leadFormDefaultMappings(bool $consentRequired): string
{
    $lines = [
        'name=name|required',
        'email=email|required',
        'phone=phone|required',
        'nip=nip',
        'company_name=company_name',
        'message=message',
        'source_url=source_url',
    ];
    $lines[] = $consentRequired ? 'consent=consent|required' : 'consent=consent';
    return implode("\n", $lines);
}

function leadFormMappingLines(PDO $pdo, int $sourceId, bool $consentRequired): string
{
    if ($sourceId <= 0) {
        return leadFormDefaultMappings($consentRequired);
    }
    $stmt = $pdo->prepare('SELECT external_field, crm_field, is_required FROM lead_form_field_mappings WHERE source_id = :id ORDER BY id ASC');
    $stmt->execute([':id' => $sourceId]);
    $lines = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $line = (string)$row['external_field'] . '=' . (string)$row['crm_field'];
        if (!empty($row['is_required'])) {
            $line .= '|required';
        }
        $lines[] = $line;
    }
    return $lines ? implode("\n", $lines) : leadFormDefaultMappings($consentRequired);
}

function leadFormSaveMappings(PDO $pdo, int $sourceId, string $input): void
{
    $pdo->prepare('DELETE FROM lead_form_field_mappings WHERE source_id = :id')->execute([':id' => $sourceId]);
    $stmt = $pdo->prepare(
        'INSERT INTO lead_form_field_mappings (source_id, external_field, crm_field, is_required)
         VALUES (:source_id, :external_field, :crm_field, :is_required)'
    );
    $lines = preg_split('/\r\n|\r|\n/', $input) ?: [];
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || strpos($line, '=') === false) {
            continue;
        }
        [$external, $rest] = array_map('trim', explode('=', $line, 2));
        $parts = array_map('trim', explode('|', $rest));
        $crm = $parts[0] ?? '';
        if ($external === '' || $crm === '') {
            continue;
        }
        $stmt->execute([
            ':source_id' => $sourceId,
            ':external_field' => $external,
            ':crm_field' => $crm,
            ':is_required' => in_array('required', $parts, true) ? 1 : 0,
        ]);
    }
}

function leadFormSource(PDO $pdo, int $sourceId): ?array
{
    if ($sourceId <= 0) {
        return null;
    }
    $stmt = $pdo->prepare('SELECT * FROM lead_form_sources WHERE id = :id LIMIT 1');
    $stmt->execute([':id' => $sourceId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

function leadFormJsonField(array $row, string $field): array
{
    $decoded = json_decode((string)($row[$field] ?? ''), true);
    return is_array($decoded) ? $decoded : [];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isCsrfTokenValid($_POST['csrf_token'] ?? '')) {
        $alerts[] = ['type' => 'danger', 'msg' => 'Niepoprawny token CSRF.'];
    } else {
        $action = (string)($_POST['action'] ?? '');
        $sourceId = (int)($_POST['source_id'] ?? 0);

        if ($action === 'toggle_source') {
            $source = leadFormSource($pdo, $sourceId);
            if ($source) {
                $newState = empty($source['is_active']) ? 1 : 0;
                $stmt = $pdo->prepare('UPDATE lead_form_sources SET is_active = :is_active WHERE id = :id');
                $stmt->execute([':is_active' => $newState, ':id' => $sourceId]);
                header('Location: ' . BASE_URL . '/lead_form_sources.php?id=' . $sourceId . '&saved=1');
                exit;
            }
        }

        if ($action === 'regenerate_key') {
            $source = leadFormSource($pdo, $sourceId);
            if ($source) {
                $newKey = LeadFormService::createUniquePublicKey($pdo);
                $stmt = $pdo->prepare('UPDATE lead_form_sources SET public_key = :public_key WHERE id = :id');
                $stmt->execute([':public_key' => $newKey, ':id' => $sourceId]);
                header('Location: ' . BASE_URL . '/lead_form_sources.php?id=' . $sourceId . '&regenerated=1');
                exit;
            }
        }

        if ($action === 'save_source') {
            $name = trim((string)($_POST['name'] ?? ''));
            $defaultSource = trim((string)($_POST['default_source'] ?? ''));
            $domains = LeadFormService::parseAllowedDomains((string)($_POST['allowed_domains'] ?? ''));
            $consentRequired = !empty($_POST['consent_required']) ? 1 : 0;
            $gusLookupEnabled = !empty($_POST['gus_lookup_enabled']) ? 1 : 0;
            $isActive = !empty($_POST['is_active']) ? 1 : 0;

            if ($name === '' || $defaultSource === '' || !$domains) {
                $alerts[] = ['type' => 'warning', 'msg' => 'Podaj nazwe, zrodlo domyslne i co najmniej jedna domene.'];
            } else {
                if ($sourceId > 0) {
                    $stmt = $pdo->prepare(
                        'UPDATE lead_form_sources
                         SET name = :name, allowed_domains = :allowed_domains, default_source = :default_source,
                             consent_required = :consent_required, gus_lookup_enabled = :gus_lookup_enabled, is_active = :is_active
                         WHERE id = :id'
                    );
                    $stmt->execute([
                        ':name' => $name,
                        ':allowed_domains' => json_encode($domains, JSON_UNESCAPED_UNICODE),
                        ':default_source' => $defaultSource,
                        ':consent_required' => $consentRequired,
                        ':gus_lookup_enabled' => $gusLookupEnabled,
                        ':is_active' => $isActive,
                        ':id' => $sourceId,
                    ]);
                } else {
                    $publicKey = LeadFormService::createUniquePublicKey($pdo);
                    $stmt = $pdo->prepare(
                        'INSERT INTO lead_form_sources
                         (name, public_key, allowed_domains, default_source, consent_required, gus_lookup_enabled, is_active)
                         VALUES (:name, :public_key, :allowed_domains, :default_source, :consent_required, :gus_lookup_enabled, :is_active)'
                    );
                    $stmt->execute([
                        ':name' => $name,
                        ':public_key' => $publicKey,
                        ':allowed_domains' => json_encode($domains, JSON_UNESCAPED_UNICODE),
                        ':default_source' => $defaultSource,
                        ':consent_required' => $consentRequired,
                        ':gus_lookup_enabled' => $gusLookupEnabled,
                        ':is_active' => $isActive,
                    ]);
                    $sourceId = (int)$pdo->lastInsertId();
                }

                $mappingInput = trim((string)($_POST['mappings'] ?? ''));
                if ($mappingInput === '') {
                    $mappingInput = leadFormDefaultMappings((bool)$consentRequired);
                }
                leadFormSaveMappings($pdo, $sourceId, $mappingInput);
                header('Location: ' . BASE_URL . '/lead_form_sources.php?id=' . $sourceId . '&saved=1');
                exit;
            }
        }
    }
}

if (!empty($_GET['saved'])) {
    $alerts[] = ['type' => 'success', 'msg' => 'Formularz zostal zapisany.'];
}
if (!empty($_GET['regenerated'])) {
    $alerts[] = ['type' => 'warning', 'msg' => 'Klucz zostal zregenerowany. Stary kod formularza na stronie przestal dzialac i wymaga aktualizacji.'];
}

$sources = $pdo->query('SELECT * FROM lead_form_sources ORDER BY created_at DESC, id DESC')->fetchAll(PDO::FETCH_ASSOC);
$selectedId = (int)($_GET['id'] ?? ($sources[0]['id'] ?? 0));
$selected = leadFormSource($pdo, $selectedId);
if (!$selected) {
    $selected = [
        'id' => 0,
        'name' => '',
        'public_key' => '',
        'allowed_domains' => '',
        'default_source' => 'www_radiozulawy_reklama',
        'consent_required' => 1,
        'gus_lookup_enabled' => 1,
        'is_active' => 1,
        'created_at' => '',
    ];
}

$submissions = [];
if ((int)$selected['id'] > 0) {
    $stmt = $pdo->prepare('SELECT * FROM lead_form_submissions WHERE source_id = :id ORDER BY created_at DESC LIMIT 20');
    $stmt->execute([':id' => (int)$selected['id']]);
    $submissions = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

$htmlCode = !empty($selected['public_key']) ? LeadFormService::generateEmbedCode($selected, $endpointUrl) : '';

include __DIR__ . '/includes/header.php';
?>

<main class="container py-4">
    <div class="d-flex flex-wrap align-items-center justify-content-between mb-4">
        <div>
            <h1 class="h4 mb-1">Formularze zewnetrzne</h1>
            <p class="text-muted mb-0">Publiczne formularze leadowe z kluczem API, domenami i gotowym kodem HTML/JS.</p>
        </div>
        <a href="<?= htmlspecialchars(BASE_URL . '/lead_form_sources.php?id=0') ?>" class="btn btn-primary btn-sm">Nowy formularz</a>
    </div>

<?php foreach ($alerts as $alert): ?>
        <div class="alert alert-<?= htmlspecialchars($alert['type']) ?>"><?= htmlspecialchars($alert['msg']) ?></div>
    <?php endforeach; ?>
    <?php foreach (($endpointInfo['warnings'] ?? []) as $warning): ?>
        <div class="alert alert-warning"><?= htmlspecialchars((string)$warning) ?></div>
    <?php endforeach; ?>
    <?php foreach (($endpointInfo['errors'] ?? []) as $error): ?>
        <div class="alert alert-danger"><?= htmlspecialchars((string)$error) ?></div>
    <?php endforeach; ?>

    <section class="card shadow-sm mb-4">
        <div class="card-header">Lista formularzy</div>
        <div class="table-responsive">
            <table class="table table-sm align-middle mb-0">
                <thead>
                    <tr>
                        <th>Nazwa</th>
                        <th>Publiczny klucz API</th>
                        <th>Dozwolone domeny</th>
                        <th>Zrodlo leada</th>
                        <th>Status</th>
                        <th>Utworzono</th>
                        <th class="text-end">Akcje</th>
                    </tr>
                </thead>
                <tbody>
                <?php if (!$sources): ?>
                    <tr><td colspan="7" class="text-center text-muted">Brak formularzy.</td></tr>
                <?php endif; ?>
                <?php foreach ($sources as $source): ?>
                    <?php $domains = implode(', ', LeadFormService::parseAllowedDomains((string)$source['allowed_domains'])); ?>
                    <tr>
                        <td><?= htmlspecialchars((string)$source['name']) ?></td>
                        <td><code><?= htmlspecialchars((string)$source['public_key']) ?></code></td>
                        <td><?= htmlspecialchars($domains) ?></td>
                        <td><?= htmlspecialchars((string)$source['default_source']) ?></td>
                        <td>
                            <span class="badge bg-<?= !empty($source['is_active']) ? 'success' : 'secondary' ?>">
                                <?= !empty($source['is_active']) ? 'aktywny' : 'nieaktywny' ?>
                            </span>
                        </td>
                        <td><?= htmlspecialchars((string)$source['created_at']) ?></td>
                        <td class="text-end">
                            <a class="btn btn-outline-secondary btn-sm" href="<?= htmlspecialchars(BASE_URL . '/lead_form_sources.php?id=' . (int)$source['id'] . '#preview') ?>">Podglad</a>
                            <a class="btn btn-outline-primary btn-sm" href="<?= htmlspecialchars(BASE_URL . '/lead_form_sources.php?id=' . (int)$source['id'] . '#edit') ?>">Edytuj</a>
                            <a class="btn btn-outline-info btn-sm" href="<?= htmlspecialchars(BASE_URL . '/lead_form_sources.php?id=' . (int)$source['id'] . '#embed-code') ?>">Kopiuj kod HTML</a>
                            <form method="post" class="d-inline">
                                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                                <input type="hidden" name="action" value="toggle_source">
                                <input type="hidden" name="source_id" value="<?= (int)$source['id'] ?>">
                                <button class="btn btn-outline-warning btn-sm" type="submit">
                                    <?= !empty($source['is_active']) ? 'Dezaktywuj' : 'Aktywuj' ?>
                                </button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </section>

    <div class="row g-4">
        <div class="col-lg-6">
            <form method="post" class="card shadow-sm" id="edit">
                <div class="card-header"><?= (int)$selected['id'] > 0 ? 'Edycja formularza' : 'Nowy formularz' ?></div>
                <div class="card-body">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                    <input type="hidden" name="action" value="save_source">
                    <input type="hidden" name="source_id" value="<?= (int)$selected['id'] ?>">
                    <div class="mb-3">
                        <label class="form-label">Nazwa formularza</label>
                        <input class="form-control" name="name" value="<?= htmlspecialchars((string)$selected['name']) ?>" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Dozwolone domeny</label>
                        <textarea class="form-control" name="allowed_domains" rows="3" required><?= htmlspecialchars(leadFormAllowedDomainsText((string)$selected['allowed_domains'])) ?></textarea>
                        <div class="form-text">Jedna domena w wierszu albo lista po przecinku, np. radiozulawy.pl, www.radiozulawy.pl.</div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Domyslne zrodlo leada</label>
                        <input class="form-control" name="default_source" value="<?= htmlspecialchars((string)$selected['default_source']) ?>" required>
                    </div>
                    <div class="row g-3 mb-3">
                        <div class="col-md-4">
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" name="consent_required" value="1" id="consent_required" <?= !empty($selected['consent_required']) ? 'checked' : '' ?>>
                                <label class="form-check-label" for="consent_required">Zgoda wymagana</label>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" name="gus_lookup_enabled" value="1" id="gus_lookup_enabled" <?= !empty($selected['gus_lookup_enabled']) ? 'checked' : '' ?>>
                                <label class="form-check-label" for="gus_lookup_enabled">GUS po NIP</label>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" name="is_active" value="1" id="is_active" <?= !empty($selected['is_active']) ? 'checked' : '' ?>>
                                <label class="form-check-label" for="is_active">Aktywny</label>
                            </div>
                        </div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Mapowanie pol</label>
                        <textarea class="form-control font-monospace" name="mappings" rows="8"><?= htmlspecialchars(leadFormMappingLines($pdo, (int)$selected['id'], !empty($selected['consent_required']))) ?></textarea>
                    </div>
                    <?php if (!empty($selected['public_key'])): ?>
                        <div class="alert alert-secondary mb-0">
                            Publiczny klucz API: <code><?= htmlspecialchars((string)$selected['public_key']) ?></code>
                        </div>
                    <?php endif; ?>
                </div>
                <div class="card-footer d-flex justify-content-between">
                    <button class="btn btn-primary" type="submit">Zapisz formularz</button>
                    <?php if ((int)$selected['id'] > 0): ?>
                        <button class="btn btn-outline-danger" type="submit" name="action" value="regenerate_key"
                                onclick="return confirm('Regeneracja klucza uniewazni aktualny kod formularza na stronie. Kontynuowac?')">
                            Regeneruj klucz
                        </button>
                    <?php endif; ?>
                </div>
            </form>
        </div>

        <div class="col-lg-6">
            <section class="card shadow-sm mb-4" id="preview">
                <div class="card-header">Podglad konfiguracji</div>
                <div class="card-body">
                    <dl class="row mb-0">
                        <dt class="col-sm-4">Endpoint</dt>
                        <dd class="col-sm-8"><code><?= htmlspecialchars($endpointUrl) ?></code></dd>
                        <dt class="col-sm-4">Zrodlo adresu</dt>
                        <dd class="col-sm-8"><?= htmlspecialchars((string)($endpointInfo['source'] ?? '')) ?></dd>
                        <dt class="col-sm-4">Public key</dt>
                        <dd class="col-sm-8"><code><?= htmlspecialchars((string)$selected['public_key']) ?></code></dd>
                        <dt class="col-sm-4">Domeny</dt>
                        <dd class="col-sm-8"><?= htmlspecialchars(leadFormAllowedDomainsText((string)$selected['allowed_domains'])) ?></dd>
                        <dt class="col-sm-4">Zgoda</dt>
                        <dd class="col-sm-8"><?= !empty($selected['consent_required']) ? 'wymagana' : 'niewymagana' ?></dd>
                        <dt class="col-sm-4">GUS</dt>
                        <dd class="col-sm-8"><?= !empty($selected['gus_lookup_enabled']) ? 'wlaczony' : 'wylaczony' ?></dd>
                    </dl>
                </div>
            </section>

            <section class="card shadow-sm" id="embed-code">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <span>Kod HTML/JS do wklejenia</span>
                    <button class="btn btn-outline-secondary btn-sm" type="button" data-copy-target="lead-form-code">Kopiuj</button>
                </div>
                <div class="card-body">
                    <textarea id="lead-form-code" class="form-control font-monospace" rows="18" readonly><?= htmlspecialchars($htmlCode) ?></textarea>
                    <div class="form-text">Klucz publiczny sluzy tylko do wysylania zgloszen i nie daje dostepu do panelu CRM.</div>
                </div>
            </section>
        </div>
    </div>

    <section class="card shadow-sm mt-4">
        <div class="card-header">Ostatnie zgloszenia formularza</div>
        <div class="table-responsive">
            <table class="table table-sm align-middle mb-0">
                <thead>
                    <tr>
                        <th>Data</th>
                        <th>Imie i nazwisko</th>
                        <th>Email</th>
                        <th>Telefon</th>
                        <th>NIP</th>
                        <th>Status</th>
                        <th>Lead</th>
                    </tr>
                </thead>
                <tbody>
                <?php if (!$submissions): ?>
                    <tr><td colspan="7" class="text-center text-muted">Brak zgloszen.</td></tr>
                <?php endif; ?>
                <?php foreach ($submissions as $submission): ?>
                    <?php $normalized = leadFormJsonField($submission, 'normalized_payload'); ?>
                    <tr>
                        <td><?= htmlspecialchars((string)$submission['created_at']) ?></td>
                        <td><?= htmlspecialchars((string)($normalized['name'] ?? '')) ?></td>
                        <td><?= htmlspecialchars((string)($normalized['email'] ?? '')) ?></td>
                        <td><?= htmlspecialchars((string)($normalized['phone'] ?? '')) ?></td>
                        <td><?= htmlspecialchars((string)($normalized['nip'] ?? '')) ?></td>
                        <td><span class="badge bg-secondary"><?= htmlspecialchars((string)$submission['status']) ?></span></td>
                        <td>
                            <?php if (!empty($submission['created_lead_id'])): ?>
                                <a href="<?= htmlspecialchars(BASE_URL . '/lead_szczegoly.php?id=' . (int)$submission['created_lead_id']) ?>">#<?= (int)$submission['created_lead_id'] ?></a>
                            <?php else: ?>
                                -
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </section>
</main>

<script>
(function () {
    var buttons = document.querySelectorAll('[data-copy-target]');
    for (var i = 0; i < buttons.length; i++) {
        buttons[i].addEventListener('click', function () {
            var target = document.getElementById(this.getAttribute('data-copy-target'));
            if (!target) return;
            target.select();
            document.execCommand('copy');
            this.textContent = 'Skopiowano';
        });
    }
})();
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>
