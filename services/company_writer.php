<?php
declare(strict_types=1);

require_once __DIR__ . '/../public/includes/db_schema.php';
require_once __DIR__ . '/../public/includes/company_upsert_policy.php';

class CompanyWriter
{
    private PDO $pdo;
    private ?array $companyColumns = null;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
        ensureCompaniesTable($this->pdo);
        if (function_exists('ensureClientLeadColumns')) {
            ensureClientLeadColumns($this->pdo);
        }
    }

    public function upsertForClient(int $clientId, array $input, int $actorUserId, string $source = 'manual'): array
    {
        $result = [
            'ok' => false,
            'company_id' => null,
            'created_company' => false,
            'updated_fields' => [],
            'updated' => false,
            'locks_set' => [],
            'skipped_fields_by_lock' => [
                'name' => [],
                'address' => [],
                'identifiers' => [],
            ],
            'skipped_groups_by_lock' => [],
            'warnings' => [],
            'errors' => [],
        ];

        $presence = $this->detectPresence($input);
        $errors = [];
        $normalized = $this->normalizeInput($input, $errors);
        if ($errors) {
            $result['errors'] = $errors;
            return $result;
        }

        $startedTransaction = $this->beginTransaction();
        try {
            $client = $this->fetchClientForUpdate($clientId);
            if (!$client) {
                $this->rollback($startedTransaction);
                $result['errors'][] = 'client_not_found';
                return $result;
            }

            $identifiers = $this->resolveIdentifiers($normalized, $client, $result['warnings']);
            $companyId = isset($client['company_id']) ? (int)$client['company_id'] : 0;
            $company = null;

            if ($companyId > 0) {
                $company = $this->fetchCompanyForUpdate($companyId);
                if (!$company) {
                    $result['warnings'][] = 'company_missing_for_client';
                    $companyId = 0;
                }
            }

            if ($companyId === 0) {
                $companyId = $this->findCompanyIdByIdentifiers($identifiers);
                if ($companyId > 0) {
                    $this->linkClientCompany($clientId, $companyId);
                } else {
                    $create = $this->createCompany($normalized, $client, $identifiers, $presence, $source);
                    if (!$create['ok']) {
                        $this->rollback($startedTransaction);
                        $result['errors'][] = $create['error'] ?? 'Brak danych do utworzenia firmy';
                        return $result;
                    }
                    $companyId = (int)$create['company_id'];
                    $result['created_company'] = true;
                    $result['updated_fields'] = $create['updated_fields'];
                    $result['locks_set'] = $create['locks_set'];
                    $this->linkClientCompany($clientId, $companyId);
                }
            }

            $company = $this->fetchCompanyForUpdate($companyId);
            if (!$company) {
                $this->rollback($startedTransaction);
                $result['errors'][] = 'company_not_found';
                return $result;
            }

            if (!$result['created_company']) {
                $lockResult = self::filterInputWithLocks($company, $normalized);
                $normalizedFiltered = $lockResult['filtered'];
                $result['skipped_fields_by_lock'] = $lockResult['skipped_fields_by_lock'];
                $result['skipped_groups_by_lock'] = $lockResult['skipped_groups_by_lock'];

                if (!$normalizedFiltered) {
                    $this->commit($startedTransaction);
                    $result['ok'] = true;
                    $result['company_id'] = $companyId;
                    $result['updated'] = false;
                    return $result;
                }

                $update = $this->updateCompany($company, $normalizedFiltered, true, $source);
                $result['updated_fields'] = $update['updated_fields'];
                $result['locks_set'] = $update['locks_set'];
                $result['skipped_groups_by_lock'] = $result['skipped_groups_by_lock'] ?: ($update['skipped_groups_by_lock'] ?? []);
            }

            $this->commit($startedTransaction);
            $result['ok'] = true;
            $result['company_id'] = $companyId;
            $result['updated'] = $result['created_company'] || !empty($result['updated_fields']);
            return $result;
        } catch (Throwable $e) {
            $this->rollback($startedTransaction);
            error_log('company_writer: upsert failed for client_id=' . $clientId . ': ' . $e->getMessage());
            $result['errors'][] = 'exception';
            return $result;
        }
    }

    public function upsertByCompanyId(int $companyId, array $input, int $actorUserId, string $source = 'manual'): array
    {
        $result = [
            'ok' => false,
            'company_id' => $companyId,
            'created_company' => false,
            'updated_fields' => [],
            'updated' => false,
            'locks_set' => [],
            'skipped_fields_by_lock' => [
                'name' => [],
                'address' => [],
                'identifiers' => [],
            ],
            'skipped_groups_by_lock' => [],
            'warnings' => [],
            'errors' => [],
        ];

        $errors = [];
        $normalized = $this->normalizeInput($input, $errors);
        if ($errors) {
            $result['errors'] = $errors;
            return $result;
        }

        $startedTransaction = $this->beginTransaction();
        try {
            $company = $this->fetchCompanyForUpdate($companyId);
            if (!$company) {
                $this->rollback($startedTransaction);
                $result['errors'][] = 'company_not_found';
                return $result;
            }

            $lockResult = self::filterInputWithLocks($company, $normalized);
            $normalizedFiltered = $lockResult['filtered'];
            $result['skipped_fields_by_lock'] = $lockResult['skipped_fields_by_lock'];
            $result['skipped_groups_by_lock'] = $lockResult['skipped_groups_by_lock'];

            if (!$normalizedFiltered) {
                $this->commit($startedTransaction);
                $result['ok'] = true;
                $result['updated'] = false;
                return $result;
            }

            $applyLocks = $source !== 'gus';
            $update = $this->updateCompany($company, $normalizedFiltered, $applyLocks, $source);
            $result['updated_fields'] = $update['updated_fields'];
            $result['locks_set'] = $update['locks_set'];
            $result['updated'] = !empty($update['updated_fields']);
            $result['skipped_groups_by_lock'] = $result['skipped_groups_by_lock'] ?: $update['skipped_groups_by_lock'];
            $result['ok'] = true;
            $this->commit($startedTransaction);
            return $result;
        } catch (Throwable $e) {
            $this->rollback($startedTransaction);
            error_log('company_writer: upsertByCompanyId failed company_id=' . $companyId . ': ' . $e->getMessage());
            $result['errors'][] = 'exception';
            return $result;
        }
    }

    public function upsertForCompanyId(int $companyId, array $input, int $actorUserId, string $source = 'gus'): array
    {
        $result = [
            'ok' => false,
            'company_id' => $companyId,
            'created_company' => false,
            'updated_fields' => [],
            'updated' => false,
            'locks_set' => [],
            'skipped_fields_by_lock' => [
                'name' => [],
                'address' => [],
                'identifiers' => [],
            ],
            'skipped_groups_by_lock' => [],
            'warnings' => [],
            'errors' => [],
        ];

        $metaKeys = [
            'gus_last_status',
            'gus_last_error_code',
            'gus_last_error_message',
            'gus_last_refresh_at',
            'last_gus_check_at',
            'last_gus_error_at',
            'last_gus_error_message',
        ];

        $now = date('Y-m-d H:i:s');
        $metaDefaults = [
            'gus_last_status' => 'OK',
            'gus_last_error_code' => null,
            'gus_last_error_message' => null,
            'gus_last_refresh_at' => $now,
            'last_gus_check_at' => $now,
            'last_gus_error_at' => null,
            'last_gus_error_message' => null,
        ];

        $meta = [];
        foreach ($metaKeys as $key) {
            if (array_key_exists($key, $input)) {
                $meta[$key] = $input[$key];
                unset($input[$key]);
            }
        }
        $meta = array_merge($metaDefaults, $meta);

        $errors = [];
        $normalized = $this->normalizeInput($input, $errors);
        if ($errors) {
            $result['errors'] = $errors;
            return $result;
        }

        $startedTransaction = $this->beginTransaction();
        try {
            $company = $this->fetchCompanyForUpdate($companyId);
            if (!$company) {
                $this->rollback($startedTransaction);
                $result['errors'][] = 'company_not_found';
                return $result;
            }

            $lockResult = self::filterInputWithLocks($company, $normalized);
            $normalizedFiltered = $lockResult['filtered'];
            $result['skipped_fields_by_lock'] = $lockResult['skipped_fields_by_lock'];
            $result['skipped_groups_by_lock'] = $lockResult['skipped_groups_by_lock'];

            $updateFields = [];
            $diff = [];

            if ($normalizedFiltered) {
                $update = $this->updateCompany($company, $normalizedFiltered, false, $source);
                $result['updated_fields'] = $update['updated_fields'];
                $result['locks_set'] = $update['locks_set'];
                $updateFields = $update['updated_fields'];
                $result['skipped_groups_by_lock'] = $result['skipped_groups_by_lock'] ?: $update['skipped_groups_by_lock'];
                foreach ($normalizedFiltered as $field => $value) {
                    if ($this->hasValueChanged($field, $company[$field] ?? null, $value)) {
                        $diff[] = [
                            'field' => $field,
                            'old' => $company[$field] ?? null,
                            'new' => $value,
                        ];
                    }
                }
                // Reload company snapshot for meta comparison
                $company = array_merge($company, $normalizedFiltered);
            }

            $metaUpdates = [];
            $metaParams = [':id' => $companyId];
            foreach ($meta as $key => $value) {
                $current = $company[$key] ?? null;
                if ($this->hasValueChanged($key, $current, $value)) {
                    $metaUpdates[] = $key . ' = :' . $key;
                    $metaParams[':' . $key] = $value;
                    $updateFields[] = $key;
                    $diff[] = [
                        'field' => $key,
                        'old' => $current,
                        'new' => $value,
                    ];
                }
            }
            if ($metaUpdates) {
                $columns = $this->getCompanyColumns();
                if ($source === 'manual' && isset($columns['last_manual_update_at']) && !in_array('last_manual_update_at = :last_manual_update_at', $metaUpdates, true)) {
                    $metaUpdates[] = 'last_manual_update_at = :last_manual_update_at';
                    $metaParams[':last_manual_update_at'] = $now;
                    $updateFields[] = 'last_manual_update_at';
                }
                if ($source === 'gus' && isset($columns['last_gus_update_at']) && !in_array('last_gus_update_at = :last_gus_update_at', $metaUpdates, true)) {
                    $metaUpdates[] = 'last_gus_update_at = :last_gus_update_at';
                    $metaParams[':last_gus_update_at'] = $now;
                    $updateFields[] = 'last_gus_update_at';
                }
                $sql = 'UPDATE companies SET ' . implode(', ', $metaUpdates) . ' WHERE id = :id';
                $stmt = $this->pdo->prepare($sql);
                $stmt->execute($metaParams);
                foreach ($metaUpdates as $setExpr) {
                    $fieldName = trim(strtok($setExpr, ' '));
                    if (!in_array($fieldName, $updateFields, true)) {
                        $updateFields[] = $fieldName;
                    }
                }
            }

            $holdMeta = $this->applyGusHoldMeta($company, array_merge($input, $meta));
            if (!empty($holdMeta['set'])) {
                $sql = 'UPDATE companies SET ' . implode(', ', $holdMeta['set']) . ' WHERE id = :id';
                $stmt = $this->pdo->prepare($sql);
                $stmt->execute($holdMeta['params']);
                $updateFields = array_merge($updateFields, array_map(static fn($s) => trim(strtok($s, ' ')), $holdMeta['set']));
            }

            if ($updateFields) {
                $this->logChange($companyId, $actorUserId, $source, $updateFields, $diff);
            }

            $this->commit($startedTransaction);
            $result['ok'] = true;
            $result['updated_fields'] = $updateFields;
            $result['updated'] = !empty($updateFields);
            return $result;
        } catch (Throwable $e) {
            $this->rollback($startedTransaction);
            error_log('company_writer: upsertForCompanyId failed company_id=' . $companyId . ': ' . $e->getMessage());
            $result['errors'][] = 'exception';
            return $result;
        }
    }

    private function detectPresence(array $input): array
    {
        return [
            'name' => $this->hasNonEmpty($input, ['name_full', 'name_short', 'nazwa_firmy', 'nazwa']),
            'identifiers' => $this->hasNonEmpty($input, ['nip', 'regon', 'krs']),
            'address' => $this->hasNonEmpty($input, [
                'address_text', 'street', 'building_no', 'apartment_no', 'postal_code',
                'city', 'gmina', 'powiat', 'wojewodztwo', 'country'
            ]),
        ];
    }

    private function normalizeInput(array $input, array &$errors): array
    {
        $normalized = [];
        $textKeys = [
            'name_full', 'name_short', 'status', 'address_text', 'street', 'building_no', 'apartment_no',
            'postal_code', 'city', 'gmina', 'powiat', 'wojewodztwo', 'country'
        ];
        foreach ($textKeys as $key) {
            if (!array_key_exists($key, $input)) {
                continue;
            }
            $value = trim((string)$input[$key]);
            if ($value === '') {
                continue;
            }
            $normalized[$key] = $value;
        }

        $normalized = array_merge($normalized, $this->normalizeIdentifierKey($input, 'nip', [10], $errors));
        $normalized = array_merge($normalized, $this->normalizeIdentifierKey($input, 'regon', [9, 14], $errors));
        $normalized = array_merge($normalized, $this->normalizeIdentifierKey($input, 'krs', [10], $errors));

        if (isset($normalized['address_text']) && !isset($normalized['street'])) {
            $normalized['street'] = $normalized['address_text'];
        }
        unset($normalized['address_text']);

        return $normalized;
    }

    private function normalizeIdentifierKey(array $input, string $key, array $lengths, array &$errors): array
    {
        if (!array_key_exists($key, $input)) {
            return [];
        }
        $raw = trim((string)$input[$key]);
        if ($raw === '') {
            return [];
        }
        $digits = preg_replace('/\D+/', '', $raw) ?? '';
        if ($digits === '' || !in_array(strlen($digits), $lengths, true)) {
            $errors[] = 'invalid_' . $key;
            return [];
        }
        return [$key => $digits];
    }

    private function resolveIdentifiers(array $normalized, array $client, array &$warnings): array
    {
        $nip = $normalized['nip'] ?? $this->normalizeClientIdentifier($client['nip'] ?? null, [10]);
        $regon = $normalized['regon'] ?? $this->normalizeClientIdentifier($client['regon'] ?? null, [9, 14]);
        $krs = $normalized['krs'] ?? $this->normalizeClientIdentifier($client['krs'] ?? null, [10]);

        if ($nip === null && !empty($client['nip'])) {
            $warnings[] = 'client_nip_invalid';
        }
        if ($regon === null && !empty($client['regon'])) {
            $warnings[] = 'client_regon_invalid';
        }

        return [
            'nip' => $nip,
            'regon' => $regon,
            'krs' => $krs,
        ];
    }

    private function normalizeClientIdentifier($value, array $lengths): ?string
    {
        $value = trim((string)$value);
        if ($value === '') {
            return null;
        }
        $digits = preg_replace('/\D+/', '', $value) ?? '';
        if ($digits === '') {
            return null;
        }
        if (!in_array(strlen($digits), $lengths, true)) {
            return null;
        }
        return $digits;
    }

    private function fetchClientForUpdate(int $clientId): ?array
    {
        $sql = 'SELECT id, company_id, nip, regon, nazwa_firmy, adres, miejscowosc, wojewodztwo FROM klienci WHERE id = :id';
        if ($this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'sqlite') {
            $sql .= ' FOR UPDATE';
        }

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([':id' => $clientId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    private function fetchCompanyForUpdate(int $companyId): ?array
    {
        $sql = 'SELECT * FROM companies WHERE id = :id';
        if ($this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'sqlite') {
            $sql .= ' FOR UPDATE';
        }

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([':id' => $companyId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    private function findCompanyIdByIdentifiers(array $identifiers): int
    {
        if (!empty($identifiers['nip'])) {
            $stmt = $this->pdo->prepare('SELECT id FROM companies WHERE nip = :nip LIMIT 1');
            $stmt->execute([':nip' => $identifiers['nip']]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($row) {
                return (int)$row['id'];
            }
        }
        if (!empty($identifiers['regon'])) {
            $stmt = $this->pdo->prepare('SELECT id FROM companies WHERE regon = :regon LIMIT 1');
            $stmt->execute([':regon' => $identifiers['regon']]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($row) {
                return (int)$row['id'];
            }
        }
        if (!empty($identifiers['krs'])) {
            $stmt = $this->pdo->prepare('SELECT id FROM companies WHERE krs = :krs LIMIT 1');
            $stmt->execute([':krs' => $identifiers['krs']]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($row) {
                return (int)$row['id'];
            }
        }
        return 0;
    }

    private function createCompany(array $normalized, array $client, array $identifiers, array $presence, string $source): array
    {
        $fields = [];
        $nameFull = $normalized['name_full'] ?? $this->normalizeText($client['nazwa_firmy'] ?? null);
        if ($nameFull !== null) {
            $fields['name_full'] = $nameFull;
        }
        if (!empty($normalized['name_short'])) {
            $fields['name_short'] = $normalized['name_short'];
        }
        if (!empty($identifiers['nip'])) {
            $fields['nip'] = $identifiers['nip'];
        }
        if (!empty($identifiers['regon'])) {
            $fields['regon'] = $identifiers['regon'];
        }
        if (!empty($identifiers['krs'])) {
            $fields['krs'] = $identifiers['krs'];
        }

        $addressFields = $this->addressFieldsFromNormalized($normalized);
        $fields = array_merge($fields, $addressFields);

        if (!$fields) {
            return ['ok' => false, 'error' => 'Brak danych do utworzenia firmy'];
        }
        if (!isset($fields['name_full']) && !isset($fields['nip']) && !isset($fields['regon']) && !isset($fields['krs'])) {
            return ['ok' => false, 'error' => 'Brak danych do utworzenia firmy'];
        }

        $fields['is_active'] = 1;

        $locks = [];
        if (!empty($presence['name'])) {
            $locks['lock_name'] = 1;
        }
        if (!empty($presence['address'])) {
            $locks['lock_address'] = 1;
        }
        if (!empty($presence['identifiers'])) {
            $locks['lock_identifiers'] = 1;
        }
        $fields = array_merge($fields, $locks);

        $fields = $this->applyProvenanceForInsert($fields, $presence, $source);

        $columns = array_keys($fields);
        $placeholders = array_map(static fn(string $col): string => ':' . $col, $columns);

        $stmt = $this->pdo->prepare('INSERT INTO companies (' . implode(', ', $columns) . ') VALUES (' . implode(', ', $placeholders) . ')');
        $params = [];
        foreach ($fields as $col => $value) {
            $params[':' . $col] = $value;
        }
        $stmt->execute($params);
        $companyId = (int)$this->pdo->lastInsertId();

        return [
            'ok' => true,
            'company_id' => $companyId,
            'updated_fields' => array_keys($fields),
            'locks_set' => $locks,
        ];
    }

    private function applyProvenanceForInsert(array $fields, array $presence, string $source): array
    {
        $columns = $this->getCompanyColumns();
        $now = date('Y-m-d H:i:s');

        $map = [
            'name' => ['presence_key' => 'name', 'source_col' => 'name_source', 'ts_col' => 'name_updated_at'],
            'address' => ['presence_key' => 'address', 'source_col' => 'address_source', 'ts_col' => 'address_updated_at'],
            'identifiers' => ['presence_key' => 'identifiers', 'source_col' => 'identifiers_source', 'ts_col' => 'identifiers_updated_at'],
        ];

        foreach ($map as $meta) {
            if (empty($presence[$meta['presence_key']])) {
                continue;
            }
            if (isset($columns[strtolower($meta['source_col'])])) {
                $fields[$meta['source_col']] = $source;
            }
            if (isset($columns[strtolower($meta['ts_col'])])) {
                $fields[$meta['ts_col']] = $now;
            }
        }

        if ($source === 'manual' && isset($columns['last_manual_update_at'])) {
            $fields['last_manual_update_at'] = $now;
        }
        if ($source === 'gus' && isset($columns['last_gus_update_at'])) {
            $fields['last_gus_update_at'] = $now;
        }

        return $fields;
    }

    private function addressFieldsFromNormalized(array $normalized): array
    {
        $fields = [];
        $map = [
            'street', 'building_no', 'apartment_no', 'postal_code',
            'city', 'gmina', 'powiat', 'wojewodztwo', 'country'
        ];
        foreach ($map as $field) {
            if (array_key_exists($field, $normalized) && $normalized[$field] !== null) {
                $fields[$field] = $normalized[$field];
            }
        }
        return $fields;
    }

    private function updateCompany(array $company, array $normalized, bool $applyLocks, string $source): array
    {
        $updates = [];
        $params = [':id' => (int)$company['id']];
        $updatedFields = [];
        $locksSet = [];

        $allowed = [
            'name_full', 'name_short', 'status', 'nip', 'regon', 'krs',
            'street', 'building_no', 'apartment_no', 'postal_code',
            'city', 'gmina', 'powiat', 'wojewodztwo', 'country',
        ];

        $updatedGroups = [
            'name' => false,
            'identifiers' => false,
            'address' => false,
        ];

        foreach ($allowed as $field) {
            if (!array_key_exists($field, $normalized)) {
                continue;
            }
            $value = $normalized[$field];
            if ($value === null) {
                continue;
            }
            $oldValue = $company[$field] ?? null;
            if (!$this->hasValueChanged($field, $oldValue, $value)) {
                continue;
            }
            $updates[] = $field . ' = :' . $field;
            $params[':' . $field] = $value;
            $updatedFields[] = $field;

            if (in_array($field, ['name_full', 'name_short'], true)) {
                $updatedGroups['name'] = true;
            } elseif (in_array($field, ['nip', 'regon', 'krs'], true)) {
                $updatedGroups['identifiers'] = true;
            } else {
                $updatedGroups['address'] = true;
            }
        }

        if ($applyLocks) {
            if ($updatedGroups['name'] && empty($company['lock_name'])) {
                $updates[] = 'lock_name = 1';
                $updatedFields[] = 'lock_name';
                $locksSet['lock_name'] = 1;
            }
            if ($updatedGroups['address'] && empty($company['lock_address'])) {
                $updates[] = 'lock_address = 1';
                $updatedFields[] = 'lock_address';
                $locksSet['lock_address'] = 1;
            }
            if ($updatedGroups['identifiers'] && empty($company['lock_identifiers'])) {
                $updates[] = 'lock_identifiers = 1';
                $updatedFields[] = 'lock_identifiers';
                $locksSet['lock_identifiers'] = 1;
            }
        }

        if ($updates) {
            $sql = 'UPDATE companies SET ' . implode(', ', $updates) . ' WHERE id = :id';
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($params);
        }

        $provenanceUpdates = $this->buildProvenanceUpdates($updatedGroups, $source, !empty($updatedFields));
        if ($provenanceUpdates) {
            $sql = 'UPDATE companies SET ' . implode(', ', $provenanceUpdates['set']) . ' WHERE id = :id';
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($provenanceUpdates['params'] + [':id' => (int)$company['id']]);
            $updatedFields = array_merge($updatedFields, $provenanceUpdates['touched_fields']);
        }

        return [
            'updated_fields' => $updatedFields,
            'locks_set' => $locksSet,
            'updated_groups' => $updatedGroups,
            'skipped_groups_by_lock' => $provenanceUpdates['skipped_groups_by_lock'] ?? [],
        ];
    }

    private function linkClientCompany(int $clientId, int $companyId): void
    {
        $stmt = $this->pdo->prepare('UPDATE klienci SET company_id = :company_id WHERE id = :id');
        $stmt->execute([
            ':company_id' => $companyId,
            ':id' => $clientId,
        ]);
    }

    private function hasValueChanged(string $field, $oldValue, $newValue): bool
    {
        $old = $this->normalizeComparable($field, $oldValue);
        $new = $this->normalizeComparable($field, $newValue);
        return $old !== $new;
    }

    private function normalizeComparable(string $field, $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $text = trim((string)$value);
        if ($text === '') {
            return null;
        }
        if (in_array($field, ['nip', 'regon', 'krs'], true)) {
            $digits = preg_replace('/\D+/', '', $text) ?? '';
            return $digits === '' ? null : $digits;
        }
        return $text;
    }

    private function normalizeText($value): ?string
    {
        $text = trim((string)$value);
        return $text === '' ? null : $text;
    }

    private function hasNonEmpty(array $input, array $keys): bool
    {
        foreach ($keys as $key) {
            if (!array_key_exists($key, $input)) {
                continue;
            }
            $value = $input[$key];
            if (is_string($value)) {
                if (trim($value) !== '') {
                    return true;
                }
                continue;
            }
            if ($value !== null && $value !== '') {
                return true;
            }
        }
        return false;
    }

    public static function filterInputWithLocks(array $companyRow, array $input): array
    {
        $filtered = CompanyUpsertPolicy::filterInputByLocks($companyRow, $input);
        $skipped = self::buildSkippedFieldsByLock($input, $filtered);
        $skippedGroups = [];
        foreach ($skipped as $group => $fields) {
            if (!empty($fields)) {
                $skippedGroups[] = $group;
            }
        }

        return [
            'filtered' => $filtered,
            'skipped_fields_by_lock' => $skipped,
            'skipped_groups_by_lock' => $skippedGroups,
        ];
    }

    private static function buildSkippedFieldsByLock(array $original, array $filtered): array
    {
        $groups = [
            'name' => ['name_full', 'name_short'],
            'address' => ['street', 'building_no', 'apartment_no', 'postal_code', 'city', 'gmina', 'powiat', 'wojewodztwo', 'country'],
            'identifiers' => ['nip', 'regon', 'krs'],
        ];

        $skipped = [
            'name' => [],
            'address' => [],
            'identifiers' => [],
        ];

        foreach ($groups as $group => $fields) {
            foreach ($fields as $field) {
                if (array_key_exists($field, $original) && !array_key_exists($field, $filtered)) {
                    $skipped[$group][] = $field;
                }
            }
        }

        return $skipped;
    }

    private function logChange(int $companyId, int $userId, string $source, array $fields, array $diff): void
    {
        if (!$fields) {
            return;
        }
        try {
            if (function_exists('ensureCompanyChangeLogTable')) {
                ensureCompanyChangeLogTable($this->pdo);
            }
            $stmt = $this->pdo->prepare('INSERT INTO company_change_log (company_id, user_id, source, changed_fields, diff) VALUES (:company_id, :user_id, :source, :fields, :diff)');
            $stmt->execute([
                ':company_id' => $companyId,
                ':user_id' => $userId ?: null,
                ':source' => $source,
                ':fields' => json_encode(array_values(array_unique($fields)), JSON_UNESCAPED_UNICODE),
                ':diff' => json_encode($diff, JSON_UNESCAPED_UNICODE),
            ]);
        } catch (Throwable $e) {
            error_log('company_writer: logChange failed company_id=' . $companyId . ': ' . $e->getMessage());
        }
    }

    private function beginTransaction(): bool
    {
        if (!$this->pdo->inTransaction()) {
            $this->pdo->beginTransaction();
            return true;
        }
        return false;
    }

    private function commit(bool $startedTransaction): void
    {
        if ($startedTransaction && $this->pdo->inTransaction()) {
            $this->pdo->commit();
        }
    }

    private function rollback(bool $startedTransaction): void
    {
        if ($startedTransaction && $this->pdo->inTransaction()) {
            $this->pdo->rollBack();
        }
    }

    private function buildProvenanceUpdates(array $updatedGroups, string $source, bool $anyFieldChanged): array
    {
        $columns = $this->getCompanyColumns();
        $now = date('Y-m-d H:i:s');
        $set = [];
        $params = [];
        $touched = [];
        $skippedGroups = [];

        $groupMap = [
            'name' => ['source_col' => 'name_source', 'ts_col' => 'name_updated_at'],
            'address' => ['source_col' => 'address_source', 'ts_col' => 'address_updated_at'],
            'identifiers' => ['source_col' => 'identifiers_source', 'ts_col' => 'identifiers_updated_at'],
        ];

        foreach ($groupMap as $group => $meta) {
            if (!($updatedGroups[$group] ?? false)) {
                continue;
            }
            if (!isset($columns[strtolower($meta['source_col'])]) || !isset($columns[strtolower($meta['ts_col'])])) {
                continue;
            }
            $set[] = $meta['source_col'] . ' = :' . $meta['source_col'];
            $params[':' . $meta['source_col']] = $source;
            $set[] = $meta['ts_col'] . ' = :' . $meta['ts_col'];
            $params[':' . $meta['ts_col']] = $now;
            $touched[] = $meta['source_col'];
            $touched[] = $meta['ts_col'];
        }

        if ($anyFieldChanged) {
            if ($source === 'manual' && isset($columns['last_manual_update_at'])) {
                $set[] = 'last_manual_update_at = :last_manual_update_at';
                $params[':last_manual_update_at'] = $now;
                $touched[] = 'last_manual_update_at';
            }
            if ($source === 'gus' && isset($columns['last_gus_update_at'])) {
                $set[] = 'last_gus_update_at = :last_gus_update_at';
                $params[':last_gus_update_at'] = $now;
                $touched[] = 'last_gus_update_at';
            }
        }

        return [
            'set' => $set,
            'params' => $params,
            'touched_fields' => $touched,
            'skipped_groups_by_lock' => $skippedGroups,
        ];
    }

    private function getCompanyColumns(): array
    {
        if ($this->companyColumns !== null) {
            return $this->companyColumns;
        }
        $cols = getTableColumns($this->pdo, 'companies') ?: [];
        $this->companyColumns = $cols;
        return $cols;
    }

    private function applyGusHoldMeta(array $company, array $input): array
    {
        $columns = $this->getCompanyColumns();
        $set = [];
        $params = [':id' => (int)$company['id']];
        $now = date('Y-m-d H:i:s');

        $status = $input['gus_last_status'] ?? null;
        $errorClass = $input['gus_error_class'] ?? $input['gus_last_error_class'] ?? null;
        $retryable = $input['gus_error_retryable'] ?? null;

        $hasCol = static function (array $cols, string $name): bool {
            return isset($cols[strtolower($name)]);
        };

        $capStreak = static function ($val): int {
            return max(0, min(50, (int)$val));
        };

        if ($status === 'OK') {
            if ($hasCol($columns, 'gus_fail_streak')) {
                $set[] = 'gus_fail_streak = 0';
            }
            if ($hasCol($columns, 'gus_last_error_class')) {
                $set[] = 'gus_last_error_class = NULL';
            }
            if ($hasCol($columns, 'gus_hold_until')) {
                $set[] = 'gus_hold_until = NULL';
            }
            if ($hasCol($columns, 'gus_hold_reason')) {
                $set[] = 'gus_hold_reason = NULL';
            }
            if ($hasCol($columns, 'gus_not_found')) {
                $set[] = 'gus_not_found = 0';
            }
            if ($hasCol($columns, 'gus_not_found_at')) {
                $set[] = 'gus_not_found_at = NULL';
            }
            return $set ? ['set' => $set, 'params' => $params] : [];
        }

        if ($status !== 'ERR' && $errorClass === null) {
            return [];
        }

        if ($hasCol($columns, 'gus_last_error_class')) {
            $set[] = 'gus_last_error_class = :lec';
            $params[':lec'] = $errorClass;
        }

        if ($errorClass === 'auth') {
            if ($hasCol($columns, 'gus_fail_streak')) {
                $set[] = 'gus_fail_streak = ' . $capStreak(($company['gus_fail_streak'] ?? 0) + 1);
            }
            if ($hasCol($columns, 'gus_hold_until')) {
                $set[] = 'gus_hold_until = :hold';
                $params[':hold'] = date('Y-m-d H:i:s', strtotime('+6 hours'));
            }
            if ($hasCol($columns, 'gus_hold_reason')) {
                $set[] = 'gus_hold_reason = \'auth\'';
            }
        } elseif ($errorClass === 'invalid_request') {
            if ($hasCol($columns, 'gus_fail_streak')) {
                $set[] = 'gus_fail_streak = ' . $capStreak(($company['gus_fail_streak'] ?? 0) + 1);
            }
            if ($hasCol($columns, 'gus_hold_until')) {
                $set[] = 'gus_hold_until = :hold';
                $params[':hold'] = date('Y-m-d H:i:s', strtotime('+365 days'));
            }
            if ($hasCol($columns, 'gus_hold_reason')) {
                $set[] = 'gus_hold_reason = \'invalid_identifier\'';
            }
        } elseif ($errorClass === 'not_found') {
            if ($hasCol($columns, 'gus_fail_streak')) {
                $set[] = 'gus_fail_streak = 0';
            }
            if ($hasCol($columns, 'gus_not_found')) {
                $set[] = 'gus_not_found = 1';
            }
            if ($hasCol($columns, 'gus_not_found_at')) {
                $set[] = 'gus_not_found_at = :nf';
                $params[':nf'] = $now;
            }
            if ($hasCol($columns, 'gus_hold_until')) {
                $set[] = 'gus_hold_until = :hold';
                $params[':hold'] = date('Y-m-d H:i:s', strtotime('+30 days'));
            }
            if ($hasCol($columns, 'gus_hold_reason')) {
                $set[] = 'gus_hold_reason = \'not_found\'';
            }
        } else {
            if ($hasCol($columns, 'gus_fail_streak')) {
                $set[] = 'gus_fail_streak = ' . $capStreak(($company['gus_fail_streak'] ?? 0) + 1);
            }
            // rate_limit/transient/unknown: no hold changes
        }

        return $set ? ['set' => $set, 'params' => $params] : [];
    }
}

/*
Manual test checklist:
1) Client has company_id -> update name_full -> company updated and lock_name=1.
2) Client without company_id but with NIP -> should find/create company and link.
3) Invalid NIP (9 digits) -> ok=false, no DB changes (rollback).
*/
