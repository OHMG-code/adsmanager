<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
requireCapability('manage_system');

require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/includes/db_schema.php';

ensureSystemConfigColumns($pdo);
ensureCompanyProfileTable($pdo);

$pageTitle = 'Dane firmy';
$csrfToken = getCsrfToken();
$alerts = [];

function companyProfileH($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function companyProfileFetch(PDO $pdo): ?array
{
    $stmt = $pdo->query('SELECT * FROM company_profile ORDER BY id ASC LIMIT 1');
    $profile = $stmt ? ($stmt->fetch(PDO::FETCH_ASSOC) ?: null) : null;
    if ($stmt) {
        $stmt->closeCursor();
    }
    return is_array($profile) ? $profile : null;
}

function companyProfileCleanText(string $key, int $maxLength = 255): string
{
    $value = trim((string)($_POST[$key] ?? ''));
    $value = preg_replace('/[[:cntrl:]]+/u', ' ', $value);
    $value = trim((string)$value);
    if (function_exists('mb_substr')) {
        return mb_substr($value, 0, $maxLength);
    }
    return substr($value, 0, $maxLength);
}

function companyProfileValidateNip(string $nip): bool
{
    if ($nip === '') {
        return true;
    }
    return (bool)preg_match('/^[0-9]{10}$/', preg_replace('/\D+/', '', $nip));
}

function companyProfileNormalizeNip(string $nip): string
{
    return preg_replace('/\D+/', '', $nip);
}

function companyProfileStoreUpload(string $fieldName, ?string $currentPath, array &$alerts): ?string
{
    if (empty($_FILES[$fieldName]['name'])) {
        return $currentPath;
    }

    $file = $_FILES[$fieldName];
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        return $currentPath;
    }
    if (($file['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) {
        $alerts[] = ['type' => 'danger', 'msg' => 'Nie udalo sie przeslac pliku: ' . $fieldName . '.'];
        return $currentPath;
    }

    $tmpName = (string)($file['tmp_name'] ?? '');
    if ($tmpName === '' || !is_uploaded_file($tmpName)) {
        $alerts[] = ['type' => 'danger', 'msg' => 'Niepoprawny plik uploadu: ' . $fieldName . '.'];
        return $currentPath;
    }

    $maxSize = 5 * 1024 * 1024;
    if ((int)($file['size'] ?? 0) > $maxSize) {
        $alerts[] = ['type' => 'danger', 'msg' => 'Plik jest za duzy. Maksymalny rozmiar to 5 MB.'];
        return $currentPath;
    }

    $extension = strtolower(pathinfo((string)$file['name'], PATHINFO_EXTENSION));
    $allowedExtensions = ['jpg', 'jpeg', 'png', 'webp'];
    if (!in_array($extension, $allowedExtensions, true)) {
        $alerts[] = ['type' => 'danger', 'msg' => 'Dozwolone formaty plikow graficznych: jpg, jpeg, png, webp.'];
        return $currentPath;
    }

    $imageInfo = @getimagesize($tmpName);
    if (!is_array($imageInfo)) {
        $alerts[] = ['type' => 'danger', 'msg' => 'Przeslany plik nie jest poprawnym obrazem.'];
        return $currentPath;
    }

    $allowedMimes = ['image/jpeg', 'image/png', 'image/webp'];
    $mime = (string)($imageInfo['mime'] ?? '');
    if (!in_array($mime, $allowedMimes, true)) {
        $alerts[] = ['type' => 'danger', 'msg' => 'Nieobslugiwany typ obrazu: ' . $mime . '.'];
        return $currentPath;
    }

    $uploadDir = __DIR__ . '/uploads/company';
    if (!is_dir($uploadDir) && !mkdir($uploadDir, 0775, true) && !is_dir($uploadDir)) {
        $alerts[] = ['type' => 'danger', 'msg' => 'Nie udalo sie utworzyc katalogu uploads/company.'];
        return $currentPath;
    }

    $prefixMap = [
        'logo_file' => 'logo',
        'stamp_file' => 'stamp',
        'signature_file' => 'signature',
    ];
    $prefix = $prefixMap[$fieldName] ?? 'company';
    try {
        $filename = $prefix . '_' . date('Ymd_His') . '_' . bin2hex(random_bytes(8)) . '.' . $extension;
    } catch (Throwable $e) {
        $filename = $prefix . '_' . date('Ymd_His') . '_' . mt_rand(100000, 999999) . '.' . $extension;
    }

    $destination = $uploadDir . '/' . $filename;
    if (file_exists($destination)) {
        $alerts[] = ['type' => 'danger', 'msg' => 'Nie udalo sie dobrac bezpiecznej nazwy pliku.'];
        return $currentPath;
    }

    if (!move_uploaded_file($tmpName, $destination)) {
        $alerts[] = ['type' => 'danger', 'msg' => 'Nie udalo sie zapisac pliku: ' . $fieldName . '.'];
        return $currentPath;
    }

    return 'uploads/company/' . $filename;
}

function companyProfileSyncLegacyConfig(PDO $pdo, array $data): void
{
    try {
        $address = trim(implode(' ', array_filter([
            $data['address_street'] ?? '',
            $data['address_postal_code'] ?? '',
            $data['address_city'] ?? '',
        ])));

        $existsStmt = $pdo->query('SELECT COUNT(*) FROM konfiguracja_systemu WHERE id = 1');
        $exists = (int)($existsStmt ? $existsStmt->fetchColumn() : 0) > 0;
        if ($existsStmt) {
            $existsStmt->closeCursor();
        }

        if ($exists) {
            $stmt = $pdo->prepare('UPDATE konfiguracja_systemu
                SET company_name = :company_name,
                    company_address = :company_address,
                    company_nip = :company_nip,
                    company_email = :company_email,
                    company_phone = :company_phone,
                    pdf_logo_path = :pdf_logo_path
                WHERE id = 1');
        } else {
            $stmt = $pdo->prepare('INSERT INTO konfiguracja_systemu
                (id, company_name, company_address, company_nip, company_email, company_phone, pdf_logo_path)
                VALUES (1, :company_name, :company_address, :company_nip, :company_email, :company_phone, :pdf_logo_path)');
        }
        $stmt->execute([
            ':company_name' => $data['company_name'] !== '' ? $data['company_name'] : null,
            ':company_address' => $address !== '' ? $address : null,
            ':company_nip' => $data['nip'] !== '' ? $data['nip'] : null,
            ':company_email' => $data['email'] !== '' ? $data['email'] : null,
            ':company_phone' => $data['phone'] !== '' ? $data['phone'] : null,
            ':pdf_logo_path' => $data['logo_path'] !== '' ? $data['logo_path'] : null,
        ]);
    } catch (Throwable $e) {
        error_log('company_profile: cannot sync legacy config: ' . $e->getMessage());
    }
}

$profile = companyProfileFetch($pdo);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isCsrfTokenValid($_POST['csrf_token'] ?? '')) {
        $alerts[] = ['type' => 'danger', 'msg' => 'Niepoprawny token CSRF.'];
    } else {
        $data = [
            'company_name' => companyProfileCleanText('company_name'),
            'short_name' => companyProfileCleanText('short_name', 120),
            'nip' => companyProfileNormalizeNip(companyProfileCleanText('nip', 20)),
            'regon' => companyProfileCleanText('regon', 20),
            'krs' => companyProfileCleanText('krs', 20),
            'address_street' => companyProfileCleanText('address_street'),
            'address_postal_code' => companyProfileCleanText('address_postal_code', 20),
            'address_city' => companyProfileCleanText('address_city', 120),
            'email' => companyProfileCleanText('email'),
            'phone' => companyProfileCleanText('phone', 50),
            'website' => companyProfileCleanText('website'),
            'bank_account' => companyProfileCleanText('bank_account', 80),
            'bank_name' => companyProfileCleanText('bank_name', 160),
            'representative_name' => companyProfileCleanText('representative_name', 160),
            'representative_role' => companyProfileCleanText('representative_role', 120),
            'logo_path' => (string)($profile['logo_path'] ?? ''),
            'stamp_path' => (string)($profile['stamp_path'] ?? ''),
            'signature_path' => (string)($profile['signature_path'] ?? ''),
            'default_vat_rate' => str_replace(',', '.', companyProfileCleanText('default_vat_rate', 10)),
            'default_payment_days' => companyProfileCleanText('default_payment_days', 5),
        ];

        if ($data['company_name'] === '') {
            $alerts[] = ['type' => 'danger', 'msg' => 'Nazwa firmy jest wymagana.'];
        }
        if (!companyProfileValidateNip($data['nip'])) {
            $alerts[] = ['type' => 'danger', 'msg' => 'NIP musi miec 10 cyfr.'];
        }
        if ($data['email'] !== '' && !filter_var($data['email'], FILTER_VALIDATE_EMAIL)) {
            $alerts[] = ['type' => 'danger', 'msg' => 'Podaj poprawny adres email.'];
        }

        $data['default_vat_rate'] = is_numeric($data['default_vat_rate'])
            ? (string)max(0, min(99.99, (float)$data['default_vat_rate']))
            : '23.00';
        $data['default_payment_days'] = (string)max(0, min(365, (int)$data['default_payment_days']));

        if ($alerts === []) {
            $data['logo_path'] = companyProfileStoreUpload('logo_file', $data['logo_path'] ?: null, $alerts) ?? '';
            $data['stamp_path'] = companyProfileStoreUpload('stamp_file', $data['stamp_path'] ?: null, $alerts) ?? '';
            $data['signature_path'] = companyProfileStoreUpload('signature_file', $data['signature_path'] ?: null, $alerts) ?? '';
        }

        if ($alerts === []) {
            $fields = [
                'company_name', 'short_name', 'nip', 'regon', 'krs',
                'address_street', 'address_postal_code', 'address_city',
                'email', 'phone', 'website', 'bank_account', 'bank_name',
                'representative_name', 'representative_role',
                'logo_path', 'stamp_path', 'signature_path',
                'default_vat_rate', 'default_payment_days',
            ];
            $params = [];
            foreach ($fields as $field) {
                $params[':' . $field] = $data[$field] !== '' ? $data[$field] : null;
            }

            if ($profile) {
                $setParts = array_map(static fn (string $field): string => $field . ' = :' . $field, $fields);
                $sql = 'UPDATE company_profile SET ' . implode(', ', $setParts) . ', updated_at = CURRENT_TIMESTAMP WHERE id = :id';
                $params[':id'] = (int)$profile['id'];
                $stmt = $pdo->prepare($sql);
                $stmt->execute($params);
            } else {
                $sql = 'INSERT INTO company_profile (' . implode(', ', $fields) . ') VALUES (:' . implode(', :', $fields) . ')';
                $stmt = $pdo->prepare($sql);
                $stmt->execute($params);
            }

            companyProfileSyncLegacyConfig($pdo, $data);
            $alerts[] = ['type' => 'success', 'msg' => 'Dane firmy zostaly zapisane.'];
            $profile = companyProfileFetch($pdo);
        }
    }
}

$profile = $profile ?: [
    'company_name' => '',
    'short_name' => '',
    'nip' => '',
    'regon' => '',
    'krs' => '',
    'address_street' => '',
    'address_postal_code' => '',
    'address_city' => '',
    'email' => '',
    'phone' => '',
    'website' => '',
    'bank_account' => '',
    'bank_name' => '',
    'representative_name' => '',
    'representative_role' => '',
    'logo_path' => '',
    'stamp_path' => '',
    'signature_path' => '',
    'default_vat_rate' => '23.00',
    'default_payment_days' => '14',
    'created_at' => null,
    'updated_at' => null,
];

include __DIR__ . '/includes/header.php';
?>

<main class="container py-4" role="main" aria-labelledby="company-profile-heading">
    <div class="d-flex flex-wrap justify-content-between align-items-start gap-3 mb-4">
        <div>
            <p class="text-uppercase text-muted fw-semibold small mb-1">Ustawienia</p>
            <h1 id="company-profile-heading" class="h3 mb-2">Dane firmy wlasciciela CRM</h1>
            <p class="text-muted mb-0">Centralny profil nadawcy do przyszlych zlecen, aneksow i dokumentow PDF.</p>
        </div>
        <a class="btn btn-outline-secondary btn-sm" href="<?= companyProfileH(BASE_URL . '/ustawienia.php') ?>">Ustawienia globalne</a>
    </div>

    <?php foreach ($alerts as $alert): ?>
        <div class="alert alert-<?= companyProfileH($alert['type']) ?>"><?= companyProfileH($alert['msg']) ?></div>
    <?php endforeach; ?>

    <div class="row g-4">
        <div class="col-xl-4">
            <section class="card shadow-sm mb-4" aria-labelledby="company-profile-preview-heading">
                <div class="card-body">
                    <h2 id="company-profile-preview-heading" class="h5 mb-3">Podglad danych</h2>
                    <dl class="row small mb-0">
                        <dt class="col-5">Nazwa</dt>
                        <dd class="col-7"><?= companyProfileH($profile['company_name'] ?: '-') ?></dd>
                        <dt class="col-5">NIP</dt>
                        <dd class="col-7"><?= companyProfileH($profile['nip'] ?: '-') ?></dd>
                        <dt class="col-5">Adres</dt>
                        <dd class="col-7">
                            <?= companyProfileH(trim(($profile['address_street'] ?? '') . ' ' . ($profile['address_postal_code'] ?? '') . ' ' . ($profile['address_city'] ?? '')) ?: '-') ?>
                        </dd>
                        <dt class="col-5">Email</dt>
                        <dd class="col-7"><?= companyProfileH($profile['email'] ?: '-') ?></dd>
                        <dt class="col-5">Telefon</dt>
                        <dd class="col-7"><?= companyProfileH($profile['phone'] ?: '-') ?></dd>
                        <dt class="col-5">VAT</dt>
                        <dd class="col-7"><?= companyProfileH($profile['default_vat_rate'] ?? '23.00') ?>%</dd>
                        <dt class="col-5">Termin platnosci</dt>
                        <dd class="col-7"><?= companyProfileH($profile['default_payment_days'] ?? '14') ?> dni</dd>
                    </dl>
                </div>
            </section>

            <section class="card shadow-sm" aria-labelledby="company-profile-assets-heading">
                <div class="card-body">
                    <h2 id="company-profile-assets-heading" class="h5 mb-3">Pliki graficzne</h2>
                    <?php foreach (['logo_path' => 'Logo', 'stamp_path' => 'Pieczec', 'signature_path' => 'Podpis'] as $pathKey => $label): ?>
                        <div class="mb-3">
                            <div class="fw-semibold small mb-1"><?= companyProfileH($label) ?></div>
                            <?php if (!empty($profile[$pathKey])): ?>
                                <img src="<?= companyProfileH(BASE_URL . '/' . ltrim((string)$profile[$pathKey], '/')) ?>" alt="<?= companyProfileH($label) ?>" style="max-width: 220px; max-height: 90px;" class="border rounded bg-white p-2">
                                <div class="small text-muted mt-1"><?= companyProfileH($profile[$pathKey]) ?></div>
                            <?php else: ?>
                                <div class="text-muted small">Brak pliku.</div>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                </div>
            </section>
        </div>

        <div class="col-xl-8">
            <form method="post" enctype="multipart/form-data" class="card shadow-sm">
                <input type="hidden" name="csrf_token" value="<?= companyProfileH($csrfToken) ?>">
                <div class="card-body">
                    <h2 class="h5 mb-3">Edycja profilu</h2>

                    <div class="row g-3">
                        <div class="col-md-8">
                            <label class="form-label" for="company_name">Nazwa firmy *</label>
                            <input class="form-control" type="text" id="company_name" name="company_name" required value="<?= companyProfileH($profile['company_name']) ?>">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label" for="short_name">Nazwa skrocona</label>
                            <input class="form-control" type="text" id="short_name" name="short_name" value="<?= companyProfileH($profile['short_name']) ?>">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label" for="nip">NIP</label>
                            <input class="form-control" type="text" id="nip" name="nip" inputmode="numeric" maxlength="10" value="<?= companyProfileH($profile['nip']) ?>">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label" for="regon">REGON</label>
                            <input class="form-control" type="text" id="regon" name="regon" value="<?= companyProfileH($profile['regon']) ?>">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label" for="krs">KRS</label>
                            <input class="form-control" type="text" id="krs" name="krs" value="<?= companyProfileH($profile['krs']) ?>">
                        </div>

                        <div class="col-md-8">
                            <label class="form-label" for="address_street">Ulica i numer</label>
                            <input class="form-control" type="text" id="address_street" name="address_street" value="<?= companyProfileH($profile['address_street']) ?>">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label" for="address_postal_code">Kod pocztowy</label>
                            <input class="form-control" type="text" id="address_postal_code" name="address_postal_code" value="<?= companyProfileH($profile['address_postal_code']) ?>">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label" for="address_city">Miasto</label>
                            <input class="form-control" type="text" id="address_city" name="address_city" value="<?= companyProfileH($profile['address_city']) ?>">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label" for="website">WWW</label>
                            <input class="form-control" type="url" id="website" name="website" value="<?= companyProfileH($profile['website']) ?>">
                        </div>

                        <div class="col-md-6">
                            <label class="form-label" for="email">Email</label>
                            <input class="form-control" type="email" id="email" name="email" value="<?= companyProfileH($profile['email']) ?>">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label" for="phone">Telefon</label>
                            <input class="form-control" type="text" id="phone" name="phone" value="<?= companyProfileH($profile['phone']) ?>">
                        </div>

                        <div class="col-md-8">
                            <label class="form-label" for="bank_account">Numer konta</label>
                            <input class="form-control" type="text" id="bank_account" name="bank_account" value="<?= companyProfileH($profile['bank_account']) ?>">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label" for="bank_name">Bank</label>
                            <input class="form-control" type="text" id="bank_name" name="bank_name" value="<?= companyProfileH($profile['bank_name']) ?>">
                        </div>

                        <div class="col-md-6">
                            <label class="form-label" for="representative_name">Reprezentant</label>
                            <input class="form-control" type="text" id="representative_name" name="representative_name" value="<?= companyProfileH($profile['representative_name']) ?>">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label" for="representative_role">Funkcja reprezentanta</label>
                            <input class="form-control" type="text" id="representative_role" name="representative_role" value="<?= companyProfileH($profile['representative_role']) ?>">
                        </div>

                        <div class="col-md-6">
                            <label class="form-label" for="default_vat_rate">Domyslna stawka VAT (%)</label>
                            <input class="form-control" type="number" id="default_vat_rate" name="default_vat_rate" min="0" max="99.99" step="0.01" value="<?= companyProfileH($profile['default_vat_rate'] ?? '23.00') ?>">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label" for="default_payment_days">Domyslny termin platnosci (dni)</label>
                            <input class="form-control" type="number" id="default_payment_days" name="default_payment_days" min="0" max="365" value="<?= companyProfileH($profile['default_payment_days'] ?? '14') ?>">
                        </div>

                        <div class="col-md-4">
                            <label class="form-label" for="logo_file">Logo</label>
                            <input class="form-control" type="file" id="logo_file" name="logo_file" accept=".jpg,.jpeg,.png,.webp">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label" for="stamp_file">Pieczec</label>
                            <input class="form-control" type="file" id="stamp_file" name="stamp_file" accept=".jpg,.jpeg,.png,.webp">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label" for="signature_file">Podpis</label>
                            <input class="form-control" type="file" id="signature_file" name="signature_file" accept=".jpg,.jpeg,.png,.webp">
                        </div>
                    </div>
                </div>
                <div class="card-footer d-flex justify-content-end gap-2">
                    <button type="submit" class="btn btn-primary">Zapisz dane firmy</button>
                </div>
            </form>
        </div>
    </div>
</main>

<?php include __DIR__ . '/includes/footer.php'; ?>
