<?php
declare(strict_types=1);

/**
 * Detect attempts to write legacy firm fields directly into klienci.
 * Returns array of offending field names (as provided in POST keys).
 */
function assertNoFirmFieldWrites(array $post): array
{
    $legacyKeys = [
        'nip', 'regon', 'nazwa_firmy', 'adres', 'ulica', 'nr_nieruchomosci', 'nr_lokalu', 'kod_pocztowy',
        'miejscowosc', 'miasto', 'wojewodztwo', 'powiat', 'gmina', 'kraj',
    ];
    $found = [];
    foreach ($legacyKeys as $key) {
        if (array_key_exists($key, $post) && (string)$post[$key] !== '') {
            $found[] = $key;
        }
    }
    return $found;
}
