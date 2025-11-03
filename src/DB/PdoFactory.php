<?php
declare(strict_types=1);

namespace App\DB;

use PDO;
use App\Config\Config;

final class PdoFactory
{
    private static ?PDO $instance = null;

    public static function make(): PDO
    {
        if (self::$instance === null) {
            self::$instance = new PDO(
                Config::get('DB_DSN'),
                Config::get('DB_USER'),
                Config::get('DB_PASS'),
                [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES => false,
                ]
            );
        }
        return self::$instance;
    }

    public static function reset(): void
    {
        self::$instance = null;
    }
}
