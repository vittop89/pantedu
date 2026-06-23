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

    public static function loaded(): bool
    {
        return self::$loaded;
    }
}
