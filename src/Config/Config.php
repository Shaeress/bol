<?php
declare(strict_types=1);

namespace App\Config;

final class Config
{
    public static function get(string $key, ?string $default = null): string
    {
        $val = $_ENV[$key] ?? $_SERVER[$key] ?? getenv($key) ?: null;
        if ($val === null) {
            if ($default !== null) {
                return $default;
            }
            throw new \RuntimeException("Missing env var: {$key}");
        }
        return $val;
    }

    public static function bool(string $key, bool $default = false): bool
    {
        $v = strtolower((string)($_ENV[$key] ?? $_SERVER[$key] ?? getenv($key) ?? ($default ? "1" : "0")));
        return in_array($v, ["1", "true", "yes", "on"], true);
    }
}

