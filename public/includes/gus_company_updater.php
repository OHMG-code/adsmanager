<?php
declare(strict_types=1);

require_once __DIR__ . '/db_schema.php';
require_once __DIR__ . '/gus_validation.php';

class GusCompanyUpdater
{
    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
        ensureCompaniesTable($this->pdo);
        ensureCompanyChangeLogTable($this->pdo);
    }

    public function updateFromDto(int $companyId, array $dto, ?int $userId = null): array
    {
        $company = $this->fetchCompany($companyId);
        if (!$company) {
            return [
                'ok' => false,
                'error' => 'Company not found.',
                'updated_fields' => [],
                'skipped_locked_fields' => [],
                'diff' => [],
            ];
        }

        $incoming = $this->dtoToFields($dto);
        $diff = $this->computeDiff($company, $incoming);

        $updates = [];
        $params = [':id' => $companyId];
        $updatedFields = [];
        $skippedLocked = [];
        $skippedInvalid = [];

        $lockName = !empty($company['lock_name']);
        $lockAddress = !empty($company['lock_address']);
        $lockIdentifiers = !empty($company['lock_identifiers']);

        foreach ($incoming as $field => $newValue) {
            $oldValue = $company[$field] ?? null;
            if (!$this->hasValueChanged($field, $oldValue, $newValue)) {
                continue;
            }

            if (in_array($field, ['name_full', 'name_short'], true) && $lockName) {
                $skippedLocked[] = $field;
                continue;
            }
            if ($this->isAddressField($field) && $lockAddress) {
                $skippedLocked[] = $field;
                continue;
            }
            if (in_array($field, ['nip', 'regon', 'krs'], true) && $lockIdentifiers) {
                $skippedLocked[] = $field;
                continue;
            }

            if ($field === 'nip' && $newValue !== null && !isValidNip($newValue)) {
                $skippedInvalid[] = $field;
                continue;
            }
            if ($field === 'regon' && $newValue !== null && !isValidRegon($newValue)) {
                $skippedInvalid[] = $field;
                continue;
            }
            if ($field === 'krs' && $newValue !== null && !isValidKrs($newValue)) {
                $skippedInvalid[] = $field;
                continue;
            }

            $updates[] = $field . ' = :' . $field;
            $params[':' . $field] = $newValue;
            $updatedFields[] = $field;
        }

        if ($updates) {
            $sql = 'UPDATE companies SET ' . implode(', ', $updates) . ' WHERE id = :id';
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($params);

            $this->logChange($companyId, $userId, $updatedFields, $diff);
        }

        return [
            'ok' => true,
            'updated_fields' => $updatedFields,
            'skipped_locked_fields' => $skippedLocked,
            'skipped_invalid_fields' => $skippedInvalid,
            'diff' => $diff,
        ];
    }

    private function fetchCompany(int $companyId): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM companies WHERE id = :id');
        $stmt->execute([':id' => $companyId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    private function dtoToFields(array $dto): array
    {
        $address = isset($dto['address']) && is_array($dto['address']) ? $dto['address'] : [];
        return [
            'name_full' => $this->normalizeText($dto['name_full'] ?? null),
            'name_short' => $this->normalizeText($dto['name_short'] ?? null),
            'nip' => $this->normalizeDigits($dto['nip'] ?? null),
            'regon' => $this->normalizeDigits($dto['regon'] ?? null),
            'krs' => $this->normalizeDigits($dto['krs'] ?? null),
            'status' => $this->normalizeText($dto['status'] ?? null),
            'is_active' => $this->normalizeBool($dto['is_active'] ?? null),
            'street' => $this->normalizeText($address['street'] ?? null),
            'building_no' => $this->normalizeText($address['building_no'] ?? null),
            'apartment_no' => $this->normalizeText($address['apartment_no'] ?? null),
            'postal_code' => $this->normalizePostalCode($address['postal_code'] ?? null),
            'city' => $this->normalizeText($address['city'] ?? null),
            'gmina' => $this->normalizeText($address['gmina'] ?? null),
            'powiat' => $this->normalizeText($address['powiat'] ?? null),
            'wojewodztwo' => $this->normalizeText($address['wojewodztwo'] ?? null),
            'country' => $this->normalizeText($address['country'] ?? null),
        ];
    }

    private function computeDiff(array $current, array $incoming): array
    {
        $diff = [];
        foreach ($incoming as $field => $newValue) {
            $oldValue = $current[$field] ?? null;
            if ($this->hasValueChanged($field, $oldValue, $newValue)) {
                $diff[] = [
                    'field' => $field,
                    'old' => $oldValue,
                    'new' => $newValue,
                ];
            }
        }
        return $diff;
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
        if (is_bool($value)) {
            return $value ? '1' : '0';
        }
        $text = trim((string)$value);
        if ($text === '') {
            return null;
        }
        if (in_array($field, ['nip', 'regon', 'krs'], true)) {
            $digits = preg_replace('/\D+/', '', $text) ?? '';
            return $digits === '' ? null : $digits;
        }
        if ($field === 'postal_code') {
            $digits = preg_replace('/\D+/', '', $text) ?? '';
            if (strlen($digits) === 5) {
                return substr($digits, 0, 2) . '-' . substr($digits, 2, 3);
            }
        }
        return $text;
    }

    private function normalizeText($value): ?string
    {
        $value = trim((string)$value);
        return $value === '' ? null : $value;
    }

    private function normalizeDigits($value): ?string
    {
        $digits = preg_replace('/\D+/', '', (string)$value) ?? '';
        return $digits === '' ? null : $digits;
    }

    private function normalizePostalCode($value): ?string
    {
        $value = trim((string)$value);
        if ($value === '') {
            return null;
        }
        $digits = preg_replace('/\D+/', '', $value) ?? '';
        if (strlen($digits) === 5) {
            return substr($digits, 0, 2) . '-' . substr($digits, 2, 3);
        }
        if (preg_match('/^\d{2}-\d{3}$/', $value)) {
            return $value;
        }
        return $value;
    }

    private function normalizeBool($value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (is_bool($value)) {
            return $value ? 1 : 0;
        }
        return (int)(bool)$value;
    }

    private function isAddressField(string $field): bool
    {
        return in_array($field, [
            'street', 'building_no', 'apartment_no', 'postal_code', 'city', 'gmina', 'powiat', 'wojewodztwo', 'country'
        ], true);
    }

    private function logChange(int $companyId, ?int $userId, array $fields, array $diff): void
    {
        if (!$fields) {
            return;
        }
        $stmt = $this->pdo->prepare('INSERT INTO company_change_log (company_id, user_id, source, changed_fields, diff) VALUES (:company_id, :user_id, :source, :fields, :diff)');
        $stmt->execute([
            ':company_id' => $companyId,
            ':user_id' => $userId,
            ':source' => 'gus',
            ':fields' => json_encode(array_values($fields), JSON_UNESCAPED_UNICODE),
            ':diff' => json_encode($diff, JSON_UNESCAPED_UNICODE),
        ]);
    }
}
