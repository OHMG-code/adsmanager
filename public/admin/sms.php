<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
requireCapability('manage_system');

require_once __DIR__ . '/../includes/db_schema.php';
require_once __DIR__ . '/../../services/ZadarmaSmsService.php';
require_once __DIR__ . '/../../config/config.php';

$pageTitle = 'Diagnostyka SMS';
ensureSystemConfigColumns($pdo);
ensureCrmSmsTables($pdo);
$smsApiConfig = ZadarmaSmsService::loadDatabaseConfig($pdo);

function smsAdminH($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

$currentUser = fetchCurrentUser($pdo);
$flash = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isCsrfTokenValid($_POST['csrf_token'] ?? null)) {
        $flash = ['type' => 'danger', 'msg' => 'Niepoprawny token CSRF.'];
    } else {
        $phone = trim((string)($_POST['test_phone'] ?? ''));
        $message = trim((string)($_POST['test_message'] ?? ''));
        $service = ZadarmaSmsService::fromDatabase($pdo);
        $result = $service->sendSms($phone, $message);
        $providerResponse = $result['provider_response'] ?? null;
        $providerResponseJson = is_array($providerResponse)
            ? json_encode($providerResponse, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
            : null;

        $stmt = $pdo->prepare(
            'INSERT INTO sms_messages
             (related_type, related_id, entity_type, entity_id, user_id, direction, phone, content, sender, provider, provider_message_id, provider_response, cost, currency, error_message, status, sent_at)
             VALUES
             (:related_type, :related_id, :entity_type, :entity_id, :user_id, :direction, :phone, :content, :sender, :provider, :provider_message_id, :provider_response, :cost, :currency, :error_message, :status, :sent_at)'
        );
        $stmt->execute([
            ':related_type' => null,
            ':related_id' => null,
            ':entity_type' => null,
            ':entity_id' => null,
            ':user_id' => (int)($currentUser['id'] ?? 0) ?: null,
            ':direction' => 'out',
            ':phone' => (string)($result['phone'] ?? $phone),
            ':content' => $message,
            ':sender' => (string)($result['sender'] ?? ''),
            ':provider' => 'zadarma',
            ':provider_message_id' => null,
            ':provider_response' => $providerResponseJson,
            ':cost' => $result['cost'] ?? null,
            ':currency' => $result['currency'] ?? null,
            ':error_message' => $result['error'] ?? null,
            ':status' => (string)($result['status'] ?? 'failed'),
            ':sent_at' => in_array((string)($result['status'] ?? ''), ['sent', 'partial'], true) ? date('Y-m-d H:i:s') : null,
        ]);

        $status = (string)($result['status'] ?? 'failed');
        $flashType = in_array($status, ['sent', 'dry_run'], true) ? 'success' : ($status === 'partial' ? 'warning' : 'danger');
        $flash = ['type' => $flashType, 'msg' => (string)($result['message'] ?? 'Nie udało się wysłać SMS.')];
    }
}

$stmtRecent = $pdo->query('SELECT * FROM sms_messages ORDER BY created_at DESC LIMIT 20');
$recentSms = $stmtRecent ? ($stmtRecent->fetchAll(PDO::FETCH_ASSOC) ?: []) : [];

include __DIR__ . '/../includes/header.php';
?>

<main class="container py-4" role="main">
    <div class="d-flex flex-wrap justify-content-between align-items-start gap-3 mb-4">
        <div>
            <p class="text-uppercase text-muted fw-semibold small mb-1">Administracja techniczna</p>
            <h1 class="h3 mb-2">Diagnostyka SMS Zadarma</h1>
            <p class="text-muted mb-0">Status konfiguracji, test wysyłki i ostatnie wiadomości SMS.</p>
        </div>
    </div>

    <?php if ($flash): ?>
        <div class="alert alert-<?= smsAdminH($flash['type'] ?? 'info') ?>"><?= smsAdminH($flash['msg'] ?? '') ?></div>
    <?php endif; ?>

    <div class="row g-3 mb-4">
        <div class="col-lg-5">
            <div class="card h-100 shadow-sm">
                <div class="card-body">
                    <h2 class="h5 mb-3">Konfiguracja</h2>
                    <dl class="row mb-0">
                        <dt class="col-sm-5">API key</dt>
                        <dd class="col-sm-7"><?= trim((string)($smsApiConfig['zadarma_api_key'] ?? '')) !== '' ? 'ustawiony' : 'brak' ?></dd>
                        <dt class="col-sm-5">API secret</dt>
                        <dd class="col-sm-7"><?= trim((string)($smsApiConfig['zadarma_api_secret'] ?? '')) !== '' ? 'ustawiony' : 'brak' ?></dd>
                        <dt class="col-sm-5">Sender</dt>
                        <dd class="col-sm-7"><?= smsAdminH(trim((string)($smsApiConfig['zadarma_sms_sender'] ?? '')) !== '' ? (string)$smsApiConfig['zadarma_sms_sender'] : '-') ?></dd>
                        <dt class="col-sm-5">Base URL</dt>
                        <dd class="col-sm-7"><?= smsAdminH(trim((string)($smsApiConfig['zadarma_api_base_url'] ?? '')) !== '' ? (string)$smsApiConfig['zadarma_api_base_url'] : 'https://api.zadarma.com') ?></dd>
                        <dt class="col-sm-5">Dry-run</dt>
                        <dd class="col-sm-7"><?= !empty($smsApiConfig['sms_dry_run']) ? 'aktywny' : 'nieaktywny' ?></dd>
                    </dl>
                </div>
            </div>
        </div>
        <div class="col-lg-7">
            <div class="card h-100 shadow-sm">
                <div class="card-body">
                    <h2 class="h5 mb-3">Test wysyłki</h2>
                    <form method="post" class="row g-3">
                        <input type="hidden" name="csrf_token" value="<?= smsAdminH(getCsrfToken()) ?>">
                        <div class="col-md-5">
                            <label class="form-label">Numer telefonu</label>
                            <input type="text" name="test_phone" class="form-control" placeholder="501234567" required>
                        </div>
                        <div class="col-md-7">
                            <label class="form-label">Treść</label>
                            <input type="text" name="test_message" class="form-control" maxlength="600" value="Test SMS z CRM" required>
                        </div>
                        <div class="col-12">
                            <button type="submit" class="btn btn-primary">Wyślij test</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <section class="card shadow-sm">
        <div class="card-body">
            <h2 class="h5 mb-3">Ostatnie 20 SMS</h2>
            <?php if (!$recentSms): ?>
                <div class="text-muted">Brak wiadomości SMS.</div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-sm align-middle">
                        <thead>
                            <tr>
                                <th>Data</th>
                                <th>Powiązanie</th>
                                <th>Telefon</th>
                                <th>Status</th>
                                <th>Koszt</th>
                                <th>Błąd</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($recentSms as $sms): ?>
                                <tr>
                                    <td><?= smsAdminH($sms['created_at'] ?? '') ?></td>
                                    <td><?= smsAdminH(($sms['related_type'] ?? $sms['entity_type'] ?? '-') . ' #' . ($sms['related_id'] ?? $sms['entity_id'] ?? '-')) ?></td>
                                    <td><?= smsAdminH($sms['phone'] ?? '') ?></td>
                                    <td><?= smsAdminH($sms['status'] ?? '') ?></td>
                                    <td><?= smsAdminH(($sms['cost'] ?? '') !== '' ? (string)$sms['cost'] . ' ' . (string)($sms['currency'] ?? '') : '-') ?></td>
                                    <td><?= smsAdminH(!empty($sms['error_message']) ? 'tak' : '-') ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </section>
</main>

<?php include __DIR__ . '/../includes/footer.php'; ?>
