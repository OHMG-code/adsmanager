<?php
declare(strict_types=1);

function normalizeNip(?string $value): string
{
    $value = preg_replace('/\D+/', '', (string)$value);
    return $value ?? '';
}

function normalizeRegon(?string $value): string
{
    $value = preg_replace('/\D+/', '', (string)$value);
    return $value ?? '';
}

function normalizeKrs(?string $value): string
{
    $value = preg_replace('/\D+/', '', (string)$value);
    return $value ?? '';
}

function isValidNip(string $nip): bool
{
    $nip = normalizeNip($nip);
    if (strlen($nip) !== 10) {
        return false;
    }
    $digits = array_map('intval', str_split($nip));
    $weights = [6, 5, 7, 2, 3, 4, 5, 6, 7];
    $sum = 0;
    for ($i = 0; $i < 9; $i++) {
        $sum += $digits[$i] * $weights[$i];
    }
    $check = $sum % 11;
    if ($check === 10) {
        return false;
    }
    return $check === $digits[9];
}

function isValidRegon(string $regon): bool
{
    $regon = normalizeRegon($regon);
    $len = strlen($regon);
    if ($len !== 9 && $len !== 14) {
        return false;
    }
    $digits = array_map('intval', str_split($regon));
    if ($len === 9) {
        $weights = [8, 9, 2, 3, 4, 5, 6, 7];
        $sum = 0;
        for ($i = 0; $i < 8; $i++) {
            $sum += $digits[$i] * $weights[$i];
        }
        $check = $sum % 11;
        if ($check === 10) {
            $check = 0;
        }
        return $check === $digits[8];
    }

    $weights = [2, 4, 8, 5, 0, 9, 7, 3, 6, 1, 2, 4, 8];
    $sum = 0;
    for ($i = 0; $i < 13; $i++) {
        $sum += $digits[$i] * $weights[$i];
    }
    $check = $sum % 11;
    if ($check === 10) {
        $check = 0;
    }
    return $check === $digits[13];
}

function isValidKrs(string $krs): bool
{
    $krs = normalizeKrs($krs);
    return strlen($krs) === 10;
}
