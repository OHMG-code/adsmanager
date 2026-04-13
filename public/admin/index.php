<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
requireCapability('manage_system');

require_once __DIR__ . '/../includes/config.php';
$pageTitle = 'Administracja techniczna';
require_once __DIR__ . '/../../config/config.php';

include __DIR__ . '/../includes/header.php';
?>

<main class="container py-4" role="main" aria-labelledby="admin-tools-heading">
    <div class="d-flex flex-wrap justify-content-between align-items-start gap-3 mb-4">
        <div>
            <p class="text-uppercase text-muted fw-semibold small mb-1">Administracja techniczna</p>
            <h1 id="admin-tools-heading" class="h3 mb-2">Panel narzędzi technicznych</h1>
            <p class="text-muted mb-0">
                Kanoniczne wejście do migracji, diagnostyki i narzędzi integracyjnych.
                Legacy URL-e pozostają aktywne, ale codzienna nawigacja prowadzi przez ten panel.
            </p>
        </div>
    </div>

    <div class="row g-3 mb-4">
        <div class="col-lg-4 col-md-6">
            <div class="card h-100 shadow-sm">
                <div class="card-body">
                    <h2 class="h5 mb-2">Migracje</h2>
                    <p class="text-muted small mb-3">Stan migracji, diagnostyka bazy i uruchamianie pojedynczych kroków.</p>
                    <a class="btn btn-outline-primary btn-sm" href="<?= BASE_URL ?>/admin/migrations.php">Otwórz migracje</a>
                </div>
            </div>
        </div>
        <div class="col-lg-4 col-md-6">
            <div class="card h-100 shadow-sm">
                <div class="card-body">
                    <h2 class="h5 mb-2">Migracje wsadowe</h2>
                    <p class="text-muted small mb-3">Browser-friendly runner do batch runów, dry-runów i sanity checków.</p>
                    <a class="btn btn-outline-primary btn-sm" href="<?= BASE_URL ?>/admin/migrate_all.php">Otwórz runner</a>
                </div>
            </div>
        </div>
        <div class="col-lg-4 col-md-6">
            <div class="card h-100 shadow-sm">
                <div class="card-body">
                    <h2 class="h5 mb-2">Aktualizacje</h2>
                    <p class="text-muted small mb-3">Post-deploy finalize: check manifestu, potwierdzenie backupu, maintenance mode, auto-batch migracji i resume.</p>
                    <a class="btn btn-outline-primary btn-sm" href="<?= BASE_URL ?>/admin/updates.php">Otwórz flow</a>
                </div>
            </div>
        </div>
        <div class="col-lg-4 col-md-6">
            <div class="card h-100 shadow-sm">
                <div class="card-body">
                    <h2 class="h5 mb-2">Kolejka GUS</h2>
                    <p class="text-muted small mb-3">Stan workerów, retry, anulowanie zadań i monitoring kolejki odświeżeń.</p>
                    <a class="btn btn-outline-primary btn-sm" href="<?= BASE_URL ?>/admin/gus-queue.php">Otwórz kolejkę</a>
                </div>
            </div>
        </div>
        <div class="col-lg-4 col-md-6">
            <div class="card h-100 shadow-sm">
                <div class="card-body">
                    <h2 class="h5 mb-2">Snapshoty GUS</h2>
                    <p class="text-muted small mb-3">Surowe rekordy wywołań integracji GUS do analizy błędów i historii odpowiedzi.</p>
                    <a class="btn btn-outline-primary btn-sm" href="<?= BASE_URL ?>/admin/gus/snapshots/index.php">Otwórz snapshoty</a>
                </div>
            </div>
        </div>
        <div class="col-lg-4 col-md-6">
            <div class="card h-100 shadow-sm">
                <div class="card-body">
                    <h2 class="h5 mb-2">Testy GUS</h2>
                    <p class="text-muted small mb-3">Ręczne testy E2E dla środowisk TEST i PROD, uruchamiane przez admina.</p>
                    <a class="btn btn-outline-primary btn-sm" href="<?= BASE_URL ?>/admin/gus/tests/index.php">Otwórz testy</a>
                </div>
            </div>
        </div>
        <div class="col-lg-4 col-md-6">
            <div class="card h-100 shadow-sm">
                <div class="card-body">
                    <h2 class="h5 mb-2">Health GUS</h2>
                    <p class="text-muted small mb-3">Techniczny endpoint JSON do self-testu integracji i weryfikacji konfiguracji runtime.</p>
                    <a class="btn btn-outline-primary btn-sm" href="<?= BASE_URL ?>/admin/gus/health/index.php">Otwórz endpoint</a>
                </div>
            </div>
        </div>
    </div>

    <section class="card shadow-sm mb-4" aria-labelledby="admin-diagnostics-heading">
        <div class="card-body">
            <div class="d-flex flex-wrap justify-content-between align-items-start gap-3">
                <div>
                    <h2 id="admin-diagnostics-heading" class="h5 mb-2">Diagnostyka pomocnicza</h2>
                    <p class="text-muted small mb-0">
                        Narzędzia pomocnicze do analizy konfiguracji poczty i stanu rekordów `mail_accounts`.
                    </p>
                </div>
                <div class="d-flex flex-wrap gap-2">
                    <a class="btn btn-outline-secondary btn-sm" href="<?= BASE_URL ?>/debug_mail.php">Debug mail</a>
                    <a class="btn btn-outline-secondary btn-sm" href="<?= BASE_URL ?>/debug_mail_account.php">Debug mail account</a>
                </div>
            </div>
        </div>
    </section>

    <div class="alert alert-warning small mb-0">
        Instalator <code>install.php</code> pozostaje wejściem serwisowym i nie jest eksponowany jako zwykła pozycja nawigacji.
    </div>
</main>

<?php include __DIR__ . '/../includes/footer.php'; ?>
