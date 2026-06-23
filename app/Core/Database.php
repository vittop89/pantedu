<?php

namespace App\Core;

use PDO;
use PDOException;
use RuntimeException;

/**
 * PDO connection singleton.
 *
 * Honors `database.enabled`: if false, `connection()` throws and
 * callers are expected to fall back to JSON. Use `isAvailable()` for
 * a non-throwing probe.
 */
final class Database
{
    private static ?PDO $pdo = null;

    public static function connection(): PDO
    {
        if (self::$pdo instanceof PDO) {
            return self::$pdo;
        }
        if (!Config::get('database.enabled')) {
            throw new RuntimeException('Database disabled via config');
        }

        $driver  = Config::get('database.driver');
        $host    = Config::get('database.host');
        $port    = Config::get('database.port');
        $name    = Config::get('database.name');
        $user    = Config::get('database.user');
        $pass    = Config::get('database.pass');
        $charset = Config::get('database.charset');

        $dsn = "{$driver}:host={$host};port={$port};dbname={$name};charset={$charset}";

        try {
            self::$pdo = new PDO($dsn, $user, $pass, [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
                // G22.S9 — fix MariaDB 11.x + PHP 8.4 native driver: senza
                // buffered queries il Migrator (con fetchColumn lazy) lascia
                // cursor aperto fra statement → "Cannot execute queries while
                // other unbuffered queries are active". Buffered = fetch tutto
                // in memoria, costo trascurabile per query del nostro size.
                PDO::MYSQL_ATTR_USE_BUFFERED_QUERY => true,
            ]);
        } catch (PDOException $e) {
            throw new RuntimeException('DB connection failed: ' . $e->getMessage(), 0, $e);
        }
        return self::$pdo;
    }

    public static function isAvailable(): bool
    {
        if (!Config::get('database.enabled')) {
            return false;
        }
        try {
            self::connection();
            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    /** Test helper — reset singleton between tests. */
    public static function reset(): void
    {
        self::$pdo = null;
    }
}
