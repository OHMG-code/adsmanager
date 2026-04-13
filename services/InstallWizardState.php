<?php

declare(strict_types=1);

final class InstallWizardState
{
    private const SESSION_KEY = 'installer_wizard';

    public static function boot(): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
    }

    /**
     * @return array<string,mixed>
     */
    public static function all(): array
    {
        self::boot();
        $state = $_SESSION[self::SESSION_KEY] ?? [];
        return is_array($state) ? $state : [];
    }

    /**
     * @param array<string,mixed> $state
     */
    public static function replace(array $state): void
    {
        self::boot();
        $_SESSION[self::SESSION_KEY] = $state;
    }

    /**
     * @param array<string,mixed> $data
     */
    public static function put(string $section, array $data): void
    {
        $state = self::all();
        $state[$section] = $data;
        self::replace($state);
    }

    public static function clear(): void
    {
        self::boot();
        unset($_SESSION[self::SESSION_KEY]);
    }
}
