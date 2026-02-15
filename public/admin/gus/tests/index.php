<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../includes/auth.php';
require_once __DIR__ . '/../../../../config/config.php';
require_once __DIR__ . '/../../../includes/gus_e2e_tests.php';

requireLogin();
$currentUser = fetchCurrentUser($pdo);
if (!$currentUser) {
    header('Location: ' . BASE_URL . '/index.php');
    exit;
}

$role = normalizeRole($currentUser);
if (!in_array($role, ['Administrator', 'Manager'], true) && !isSuperAdmin()) {
    header('Location: ' . BASE_URL . '/dashboard.php');
    exit;
}

$pageTitle = 'Testy GUS BIR';
$results = null;
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_POST['csrf_token'] ?? '';
    if (!isCsrfTokenValid($token)) {
        $error = 'Niepoprawny token CSRF.';
    } else {
        $action = $_POST['action'] ?? '';
        if ($action === 'test_env_full') {
            $testNip = getenv('GUS_TEST_NIP') ?: '';
            $results = gusE2eRunSelfTest($pdo, 'test', true, $testNip);
        } elseif ($action === 'prod_smoke') {
            $results = gusE2eRunSelfTest($pdo, 'prod', false, null);
        } else {
            $error = 'Nieznana akcja.';
        }
    }
}

require_once __DIR__ . '/../../../includes/header.php';
?>

<main class="content-wide py-4">
    <div class="d-flex flex-wrap justify-content-between align-items-center mb-3">
        <div>
            <h1 class="h4 mb-1">Testy GUS BIR (E2E)</h1>
            <p class="text-muted mb-0">Uruchamiane ręcznie przez admina</p>
        </div>
    </div>

    <?php if ($error): ?>
        <div class="alert alert-danger"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <?php if ($results): ?>
        <div class="alert alert-<?= $results['ok'] ? 'success' : 'danger' ?>">
            <?= $results['ok'] ? 'Test zakończony sukcesem.' : 'Test zakończony błędem.' ?>
            <?php if (!empty($results['error'])): ?>
                <div class="small text-muted mt-1"><?= htmlspecialchars((string)$results['error']) ?></div>
            <?php endif; ?>
        </div>
        <div class="card mb-3">
            <div class="card-body">
                <div class="text-muted small">Trace ID: <?= htmlspecialchars($results['trace_id'] ?? '') ?></div>
                <ul class="mb-0">
                    <?php foreach ($results['steps'] ?? [] as $step): ?>
                        <li>
                            <?= htmlspecialchars($step['name'] ?? '') ?>:
                            <strong><?= !empty($step['ok']) ? 'OK' : 'BŁĄD' ?></strong>
                            <?php if (!empty($step['value'])): ?>
                                <span class="text-muted small ms-2">value: <?= htmlspecialchars((string)$step['value']) ?></span>
                            <?php endif; ?>
                            <?php if (!empty($step['error'])): ?>
                                <span class="text-muted small ms-2">error: <?= htmlspecialchars((string)$step['error']) ?></span>
                            <?php endif; ?>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </div>
        </div>
    <?php endif; ?>

    <div class="row g-3">
        <div class="col-lg-6">
            <div class="card">
                <div class="card-body">
                    <h6 class="mb-2">TEST env (pełny)</h6>
                    <p class="text-muted small mb-3">
                        Login → GetValue(StatusSesji) → Wyloguj + wyszukiwanie NIP testowego i mapowanie.
                        Ustaw NIP testowy w zmiennej środowiskowej <code>GUS_TEST_NIP</code>.
                    </p>
                    <form method="post">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(getCsrfToken()) ?>">
                        <input type="hidden" name="action" value="test_env_full">
                        <button type="submit" class="btn btn-primary">Uruchom test TEST</button>
                    </form>
                </div>
            </div>
        </div>
        <div class="col-lg-6">
            <div class="card">
                <div class="card-body">
                    <h6 class="mb-2">PROD env (smoke)</h6>
                    <p class="text-muted small mb-3">
                        Tylko login → GetValue(StatusSesji) → Wyloguj. Uruchamiaj ręcznie.
                    </p>
                    <form method="post">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(getCsrfToken()) ?>">
                        <input type="hidden" name="action" value="prod_smoke">
                        <button type="submit" class="btn btn-outline-danger">Uruchom smoke PROD</button>
                    </form>
                </div>
            </div>
        </div>
    </div>
</main>

<?php require_once __DIR__ . '/../../../includes/footer.php'; ?>
