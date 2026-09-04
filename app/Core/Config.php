<?php

namespace App\Core;

final class Config
{
    private static array $items = [];
    private static bool $loaded = false;

    public static function load(string $configDir): void
    {
        foreach (glob($configDir . '/*.php') as $file) {
            $name = basename($file, '.php');
            self::$items[$name] = require $file;
        }
        self::$loaded = true;
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        $segments = explode('.', $key);
        $value = self::$items;
        foreach ($segments as $seg) {
            if (!is_array($value) || !array_key_exists($seg, $value)) {
                return $default;
            }
            $value = $value[$seg];
        }
        return $value;
    }

    /**
     * Sovrascrive una voce a runtime.
     *
     * Esiste per i test — stesso ruolo di Database::reset() — cosi' che una
     * politica governata da configurazione (per esempio
     * `security.totp_required_roles`) sia verificabile senza dover riscrivere
     * i file di config o passare da variabili d'ambiente. Il codice
     * applicativo legge la configurazione, non la cambia: usarlo fuori dai
     * test significa nascondere in un punto qualsiasi una decisione che
     * dovrebbe stare in app/Config.
     */
    public static function set(string $key, mixed $value): void
    {
        $segments = explode('.', $key);
        $ref = &self::$items;
        foreach ($segments as $seg) {
            if (!isset($ref[$seg]) || !is_array($ref[$seg])) {
                $ref[$seg] = [];
            }
            $ref = &$ref[$seg];
        }
        $ref = $value;
    }

    public static function loaded(): bool
    {
        return self::$loaded;
    }
}
