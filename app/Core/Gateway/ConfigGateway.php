<?php

namespace App\Core\Gateway;

use App\Core\Config;
use App\Core\Contracts\ConfigInterface;

final class ConfigGateway implements ConfigInterface
{
    public function get(string $key, mixed $default = null): mixed
    {
        return Config::get($key, $default);
    }
    public function has(string $key): bool
    {
        return Config::get($key) !== null;
    }
}
