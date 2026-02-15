<?php
declare(strict_types=1);

require_once __DIR__ . '/gus_validation.php';

class GusCompanyMapper
{
    public static function fromGusData(array $gus): array
    {
        $data = $gus;
        $output = [];

        $nameFull = self::pickText($data, [
            'nazwa', 'name', 'NazwaPelna', 'Nazwa', 'NazwaPelnaPodmiotu', 'NazwaPodmiotu'
        ]);
        if ($nameFull !== null) {
            $output['name_full'] = $nameFull;
        }

        $nameShort = self::pickText($data, [
            'nazwa_skrocona', 'NazwaSkrocona', 'NazwaSkroconaPodmiotu'
        ]);
        if ($nameShort !== null) {
            $output['name_short'] = $nameShort;
        }

        $nip = self::pickDigits($data, ['nip', 'Nip', 'NIP']);
        if ($nip !== null) {
            $output['nip'] = $nip;
        }
        $regon = self::pickDigits($data, ['regon', 'Regon']);
        if ($regon !== null) {
            $output['regon'] = $regon;
        }
        $krs = self::pickDigits($data, ['krs', 'Krs', 'NumerKrs', 'KrsNumer', 'KRS']);
        if ($krs !== null) {
            $output['krs'] = $krs;
        }

        $status = self::pickText($data, [
            'status_podmiotu', 'StatusPodmiotu', 'StatusNazwa', 'Status', 'StatusPodmiotuNazwa'
        ]);
        if ($status !== null) {
            $output['status'] = $status;
        }

        $addressFields = [
            'street' => self::pickText($data, [
                'ulica', 'Ulica', 'AdresSiedzibyUlica', 'AdresDzialalnosciUlica', 'AdresKorUlica', 'address_street'
            ]),
            'building_no' => self::pickText($data, [
                'nr_nieruchomosci', 'NrNieruchomosci', 'AdresSiedzibyNrNieruchomosci', 'AdresDzialalnosciNrNieruchomosci'
            ]),
            'apartment_no' => self::pickText($data, [
                'nr_lokalu', 'NrLokalu', 'AdresSiedzibyNrLokalu', 'AdresDzialalnosciNrLokalu'
            ]),
            'postal_code' => self::normalizePostalCode(self::pickText($data, [
                'kod_pocztowy', 'KodPocztowy', 'AdresSiedzibyKodPocztowy', 'AdresDzialalnosciKodPocztowy', 'address_postal'
            ])),
            'city' => self::pickText($data, [
                'miejscowosc', 'Miejscowosc', 'AdresSiedzibyMiejscowosc', 'AdresDzialalnosciMiejscowosc', 'address_city'
            ]),
            'gmina' => self::pickText($data, [
                'gmina', 'Gmina', 'AdresSiedzibyGmina', 'AdresDzialalnosciGmina'
            ]),
            'powiat' => self::pickText($data, [
                'powiat', 'Powiat', 'AdresSiedzibyPowiat', 'AdresDzialalnosciPowiat'
            ]),
            'wojewodztwo' => self::pickText($data, [
                'wojewodztwo', 'Wojewodztwo', 'AdresSiedzibyWojewodztwo', 'AdresDzialalnosciWojewodztwo'
            ]),
            'country' => self::pickText($data, [
                'kraj', 'Kraj', 'AdresSiedzibyKraj', 'AdresDzialalnosciKraj', 'Country'
            ]),
        ];

        $hasSplitAddress = false;
        foreach ($addressFields as $value) {
            if ($value !== null) {
                $hasSplitAddress = true;
                break;
            }
        }

        if ($hasSplitAddress) {
            foreach ($addressFields as $key => $value) {
                if ($value !== null) {
                    $output[$key] = $value;
                }
            }
        } else {
            $fullAddress = self::pickText($data, [
                'Adres', 'adres', 'AdresSiedziby', 'AdresDzialalnosci', 'AdresPelny', 'address', 'address_full'
            ]);
            if ($fullAddress !== null) {
                $output['street'] = $fullAddress;
            }
        }

        return $output;
    }

    public function map($gusData): array
    {
        $data = is_array($gusData) ? $gusData : [];

        $nameFull = $this->normalizeText($this->pickValue($data, [
            'nazwa', 'name', 'NazwaPelna', 'Nazwa', 'NazwaPelnaPodmiotu', 'NazwaPodmiotu'
        ]));
        $nameShort = $this->normalizeText($this->pickValue($data, [
            'nazwa_skrocona', 'NazwaSkrocona', 'NazwaSkroconaPodmiotu', 'NazwaSkrocona'
        ]));

        $nip = $this->normalizeDigits($this->pickValue($data, ['nip', 'Nip', 'NIP']));
        $regon = $this->normalizeDigits($this->pickValue($data, ['regon', 'Regon']));
        $krs = $this->normalizeDigits($this->pickValue($data, ['krs', 'Krs', 'NumerKrs', 'KrsNumer', 'KRS']));

        $statusRaw = $this->normalizeText($this->pickValue($data, [
            'status_podmiotu', 'StatusPodmiotu', 'StatusNazwa', 'Status', 'StatusPodmiotuNazwa'
        ]));
        $status = $this->mapStatus($statusRaw);
        $isActive = $status === null ? null : ($status === 'aktywny');

        $street = $this->normalizeText($this->pickValue($data, [
            'ulica', 'Ulica', 'AdresSiedzibyUlica', 'AdresDzialalnosciUlica', 'AdresKorUlica', 'address_street'
        ]));
        $buildingNo = $this->normalizeText($this->pickValue($data, [
            'nr_nieruchomosci', 'NrNieruchomosci', 'AdresSiedzibyNrNieruchomosci', 'AdresDzialalnosciNrNieruchomosci'
        ]));
        $apartmentNo = $this->normalizeText($this->pickValue($data, [
            'nr_lokalu', 'NrLokalu', 'AdresSiedzibyNrLokalu', 'AdresDzialalnosciNrLokalu'
        ]));
        $postalCode = $this->normalizePostalCode($this->pickValue($data, [
            'kod_pocztowy', 'KodPocztowy', 'AdresSiedzibyKodPocztowy', 'AdresDzialalnosciKodPocztowy', 'address_postal'
        ]));
        $city = $this->normalizeText($this->pickValue($data, [
            'miejscowosc', 'Miejscowosc', 'AdresSiedzibyMiejscowosc', 'AdresDzialalnosciMiejscowosc', 'address_city'
        ]));
        $gmina = $this->normalizeText($this->pickValue($data, [
            'gmina', 'Gmina', 'AdresSiedzibyGmina', 'AdresDzialalnosciGmina'
        ]));
        $powiat = $this->normalizeText($this->pickValue($data, [
            'powiat', 'Powiat', 'AdresSiedzibyPowiat', 'AdresDzialalnosciPowiat'
        ]));
        $wojewodztwo = $this->normalizeText($this->pickValue($data, [
            'wojewodztwo', 'Wojewodztwo', 'AdresSiedzibyWojewodztwo', 'AdresDzialalnosciWojewodztwo'
        ]));
        $country = $this->normalizeText($this->pickValue($data, [
            'kraj', 'Kraj', 'AdresSiedzibyKraj', 'AdresDzialalnosciKraj'
        ]));

        $addressFields = [$street, $buildingNo, $apartmentNo, $postalCode, $city, $gmina, $powiat, $wojewodztwo, $country];
        $hasAddress = false;
        foreach ($addressFields as $field) {
            if ($field !== null && $field !== '') {
                $hasAddress = true;
                break;
            }
        }
        if ($country === null && $hasAddress) {
            $country = 'PL';
        }

        return [
            'name_full' => $nameFull,
            'name_short' => $nameShort,
            'nip' => $nip,
            'regon' => $regon,
            'krs' => $krs,
            'status' => $status,
            'is_active' => $isActive,
            'status_raw' => $statusRaw,
            'address' => [
                'street' => $street,
                'building_no' => $buildingNo,
                'apartment_no' => $apartmentNo,
                'postal_code' => $postalCode,
                'city' => $city,
                'gmina' => $gmina,
                'powiat' => $powiat,
                'wojewodztwo' => $wojewodztwo,
                'country' => $country,
            ],
        ];
    }

    public function diff(array $current, array $incoming): array
    {
        $differences = [];
        $flatIncoming = $this->flatten($incoming);
        $flatCurrent = $this->flatten($current);

        foreach ($flatIncoming as $field => $newValue) {
            $oldValue = $flatCurrent[$field] ?? null;
            $normalizedNew = $this->normalizeComparable($field, $newValue);
            $normalizedOld = $this->normalizeComparable($field, $oldValue);

            if ($normalizedNew !== $normalizedOld) {
                $differences[] = [
                    'field' => $field,
                    'old' => $oldValue,
                    'new' => $newValue,
                ];
            }
        }

        return $differences;
    }

    private function pickValue(array $data, array $keys): string
    {
        foreach ($keys as $key) {
            if (array_key_exists($key, $data)) {
                $value = trim((string)$data[$key]);
                if ($value !== '') {
                    return $value;
                }
            }
        }
        return '';
    }

    private static function pickText(array $data, array $keys): ?string
    {
        foreach ($keys as $key) {
            if (array_key_exists($key, $data)) {
                $value = trim((string)$data[$key]);
                if ($value !== '') {
                    return $value;
                }
            }
        }
        return null;
    }

    private static function pickDigits(array $data, array $keys): ?string
    {
        $value = self::pickText($data, $keys);
        if ($value === null) {
            return null;
        }
        $digits = preg_replace('/\D+/', '', $value) ?? '';
        return $digits === '' ? null : $digits;
    }

    private static function normalizePostalCode(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $value = trim($value);
        if ($value === '') {
            return null;
        }
        $digits = preg_replace('/\D+/', '', $value) ?? '';
        if (strlen($digits) === 5) {
            return substr($digits, 0, 2) . '-' . substr($digits, 2, 3);
        }
        return $value;
    }

    private function normalizeText(string $value): ?string
    {
        $value = trim($value);
        return $value === '' ? null : $value;
    }

    private function normalizeDigits(string $value): ?string
    {
        $digits = preg_replace('/\D+/', '', $value) ?? '';
        return $digits === '' ? null : $digits;
    }

    private function normalizePostalCode(string $value): ?string
    {
        $value = trim($value);
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

    private function mapStatus(?string $statusRaw): ?string
    {
        if ($statusRaw === null || $statusRaw === '') {
            return null;
        }
        $lower = mb_strtolower($statusRaw, 'UTF-8');
        if (strpos($lower, 'wykre') !== false) {
            return 'wykreślony';
        }
        if (strpos($lower, 'aktywn') !== false) {
            return 'aktywny';
        }
        return null;
    }

    private function flatten(array $data): array
    {
        $flat = [];
        foreach ($data as $key => $value) {
            if ($key === 'address' && is_array($value)) {
                foreach ($value as $addrKey => $addrValue) {
                    $flat['address.' . $addrKey] = $addrValue;
                }
                continue;
            }
            if (is_array($value)) {
                continue;
            }
            $flat[$key] = $value;
        }
        return $flat;
    }

    private function normalizeComparable(string $field, $value): ?string
    {
        if ($value === null) {
            return null;
        }
        if (is_bool($value)) {
            return $value ? '1' : '0';
        }
        $string = trim((string)$value);
        if ($string === '') {
            return null;
        }
        if (in_array($field, ['nip', 'regon', 'krs'], true)) {
            $digits = preg_replace('/\D+/', '', $string) ?? '';
            return $digits === '' ? null : $digits;
        }
        if ($field === 'address.postal_code') {
            $digits = preg_replace('/\D+/', '', $string) ?? '';
            if (strlen($digits) === 5) {
                return substr($digits, 0, 2) . '-' . substr($digits, 2, 3);
            }
        }
        return $string;
    }
}
