<?php

namespace App\Services;

use RuntimeException;

/**
 * Generates bcrypt hashes for storage in admin_users.json /
 * collaborators.json, replacing the standalone
 * log/admin/generate_hash.php UI with a thin service.
 */
final class HashGenerator
{
    public function generate(string $plain, int $cost = 12): string
    {
        if (strlen($plain) < 4) {
            throw new RuntimeException('password_too_short');
        }
        if (strlen($plain) > 4096) {
            throw new RuntimeException('password_too_long');
        }
        return password_hash($plain, PASSWORD_BCRYPT, ['cost' => $cost]);
    }

    public function verify(string $plain, string $hash): bool
    {
        return password_verify($plain, $hash);
    }
}
