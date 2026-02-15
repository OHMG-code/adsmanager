<?php
declare(strict_types=1);

const CRM_SECRET_CIPHER = 'AES-256-CBC';

function crmSecretKey(): string {
    $raw = defined('APP_SECRET_KEY') ? (string)APP_SECRET_KEY : '';
    if ($raw === '') {
        $configPath = __DIR__ . '/../../config/config.php';
        if (is_file($configPath)) {
            require_once $configPath;
        }
        $raw = defined('APP_SECRET_KEY') ? (string)APP_SECRET_KEY : '';
    }
    if ($raw === '') {
        $env = getenv('APP_SECRET_KEY');
        if (is_string($env) && $env !== '') {
            $raw = $env;
        }
    }
    if ($raw === '') {
        $raw = hash('sha256', __DIR__ . '|' . php_uname('n'), false);
    }
    return hash('sha256', $raw, true);
}

function encryptSecret(string $plain): string {
    if ($plain === '') {
        return '';
    }
    if (!function_exists('openssl_encrypt')) {
        throw new RuntimeException('OpenSSL is required for encryption.');
    }
    $ivLen = openssl_cipher_iv_length(CRM_SECRET_CIPHER);
    $iv = random_bytes($ivLen);
    $cipher = openssl_encrypt($plain, CRM_SECRET_CIPHER, crmSecretKey(), OPENSSL_RAW_DATA, $iv);
    if ($cipher === false) {
        throw new RuntimeException('Encryption failed.');
    }
    return base64_encode($iv . $cipher);
}

function decryptSecret(?string $encoded): string {
    $encoded = (string)($encoded ?? '');
    if ($encoded === '') {
        return '';
    }
    if (!function_exists('openssl_decrypt')) {
        throw new RuntimeException('OpenSSL is required for decryption.');
    }
    $raw = base64_decode($encoded, true);
    if ($raw === false) {
        return '';
    }
    $ivLen = openssl_cipher_iv_length(CRM_SECRET_CIPHER);
    if (strlen($raw) <= $ivLen) {
        return '';
    }
    $iv = substr($raw, 0, $ivLen);
    $cipher = substr($raw, $ivLen);
    $plain = openssl_decrypt($cipher, CRM_SECRET_CIPHER, crmSecretKey(), OPENSSL_RAW_DATA, $iv);
    return $plain === false ? '' : $plain;
}

function mailSecretKey(): string {
    $raw = defined('MAIL_SECRET_KEY') ? (string)MAIL_SECRET_KEY : '';
    if ($raw === '') {
        $configPath = __DIR__ . '/../../config/config.php';
        if (is_file($configPath)) {
            require_once $configPath;
        }
        $raw = defined('MAIL_SECRET_KEY') ? (string)MAIL_SECRET_KEY : '';
    }
    if ($raw === '') {
        $env = getenv('MAIL_SECRET_KEY');
        if (is_string($env) && $env !== '') {
            $raw = $env;
        }
    }
    if ($raw === '') {
        throw new RuntimeException('Brak MAIL_SECRET_KEY. Uzupelnij konfiguracje systemu.');
    }
    return hash('sha256', $raw, true);
}

function encryptMailSecret(string $plain): string {
    if ($plain === '') {
        return '';
    }
    if (!function_exists('openssl_encrypt')) {
        throw new RuntimeException('OpenSSL is required for encryption.');
    }
    $ivLen = openssl_cipher_iv_length(CRM_SECRET_CIPHER);
    $iv = random_bytes($ivLen);
    $cipher = openssl_encrypt($plain, CRM_SECRET_CIPHER, mailSecretKey(), OPENSSL_RAW_DATA, $iv);
    if ($cipher === false) {
        throw new RuntimeException('Encryption failed.');
    }
    return base64_encode($iv . $cipher);
}

function decryptMailSecret(?string $encoded): string {
    $encoded = (string)($encoded ?? '');
    if ($encoded === '') {
        return '';
    }
    if (!function_exists('openssl_decrypt')) {
        throw new RuntimeException('OpenSSL is required for decryption.');
    }
    $raw = base64_decode($encoded, true);
    if ($raw === false) {
        return '';
    }
    $ivLen = openssl_cipher_iv_length(CRM_SECRET_CIPHER);
    if (strlen($raw) <= $ivLen) {
        return '';
    }
    $iv = substr($raw, 0, $ivLen);
    $cipher = substr($raw, $ivLen);
    $plain = openssl_decrypt($cipher, CRM_SECRET_CIPHER, mailSecretKey(), OPENSSL_RAW_DATA, $iv);
    return $plain === false ? '' : $plain;
}
