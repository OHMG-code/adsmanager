<?php
declare(strict_types=1);

function canUpdateName(array $companyRow): bool
{
    return empty($companyRow['lock_name']);
}

function canUpdateAddress(array $companyRow): bool
{
    return empty($companyRow['lock_address']);
}

function canUpdateIdentifiers(array $companyRow): bool
{
    return empty($companyRow['lock_identifiers']);
}

function mergeScalar($current, $incoming, bool $canUpdate)
{
    if ($incoming === null) {
        return $current;
    }
    if (is_string($incoming) && trim($incoming) === '') {
        return $current;
    }
    if (!$canUpdate) {
        return $current;
    }
    return $incoming;
}

function filterInputByLocks(array $companyRow, array $input): array
{
    if (!canUpdateName($companyRow)) {
        unset($input['name_full'], $input['name_short']);
    }
    if (!canUpdateAddress($companyRow)) {
        unset(
            $input['street'],
            $input['building_no'],
            $input['apartment_no'],
            $input['postal_code'],
            $input['city'],
            $input['gmina'],
            $input['powiat'],
            $input['wojewodztwo'],
            $input['country']
        );
    }
    if (!canUpdateIdentifiers($companyRow)) {
        unset($input['nip'], $input['regon'], $input['krs']);
    }

    return $input;
}

class CompanyUpsertPolicy
{
    public static function canUpdateName(array $companyRow): bool
    {
        return canUpdateName($companyRow);
    }

    public static function canUpdateAddress(array $companyRow): bool
    {
        return canUpdateAddress($companyRow);
    }

    public static function canUpdateIdentifiers(array $companyRow): bool
    {
        return canUpdateIdentifiers($companyRow);
    }

    public static function mergeScalar($current, $incoming, bool $canUpdate)
    {
        return mergeScalar($current, $incoming, $canUpdate);
    }

    public static function filterInputByLocks(array $companyRow, array $input): array
    {
        return filterInputByLocks($companyRow, $input);
    }
}
