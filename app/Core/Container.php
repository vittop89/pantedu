<?php

namespace App\Core;

use App\Core\Contracts\AuthInterface;
use App\Core\Contracts\ConfigInterface;
use App\Core\Contracts\DatabaseInterface;
use App\Core\Gateway\AuthGateway;
use App\Core\Gateway\ConfigGateway;
use App\Core\Gateway\DatabaseGateway;

/**
 * Phase 17 — Container minimale (no full DI, no autowiring).
 *
 * Obiettivi:
 *   - Registrare binding interface → implementation
 *   - Lazy-instantiation (singleton per default)
 *   - Permettere overriding nei test (set() ad hoc)
 *
 * Uso:
 *   $db = Container::get(DatabaseInterface::class);
 *
 * Test:
 *   Container::set(DatabaseInterface::class, $mockDb);
 *   Container::reset();  // dopo ogni test
 */
final class Container
{
    /** @var array<class-string, object|callable> */
    private static array $bindings = [];
    /** @var array<class-string, object> */
    private static array $instances = [];

    private static function seed(): void
    {
        if (!empty(self::$bindings)) {
            return;
        }
        self::$bindings = [
            DatabaseInterface::class => DatabaseGateway::class,
            ConfigInterface::class   => ConfigGateway::class,
            AuthInterface::class     => AuthGateway::class,
        ];
    }

    /** Ritorna l'istanza (singleton) per una interface. */
    public static function get(string $id): object
    {
        self::seed();
        if (isset(self::$instances[$id])) {
            return self::$instances[$id];
        }
        $binding = self::$bindings[$id] ?? $id;
        // Closures sono oggetti MA vanno invocate: check callable (Closure) prima.
        if ($binding instanceof \Closure) {
            return self::$instances[$id] = $binding();
        }
        if (is_object($binding)) {
            return self::$instances[$id] = $binding;
        }
        if (is_string($binding) && class_exists($binding)) {
            return self::$instances[$id] = new $binding();
        }
        throw new \RuntimeException("Container: no binding for $id");
    }

    /** Override manuale (principalmente per test). */
    public static function set(string $id, object|string|callable $impl): void
    {
        self::seed();
        self::$bindings[$id] = $impl;
        unset(self::$instances[$id]); // re-instantiate on next get
    }

    /** Reset completo (per test teardown). */
    public static function reset(): void
    {
        self::$bindings = [];
        self::$instances = [];
    }
}
