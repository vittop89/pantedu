<?php

namespace App\Core;

final class Csrf
{
    public static function token(): string
    {
        $ttl       = (int)($_ENV['CSRF_TOKEN_LIFETIME'] ?? 7200);
        $issuedAt  = (int)($_SESSION['_csrf_at'] ?? 0);
        $expired   = $issuedAt > 0 && (time() - $issuedAt) > $ttl;
        if (empty($_SESSION['_csrf']) || $expired) {
            $_SESSION['_csrf']    = bin2hex(random_bytes(32));
            $_SESSION['_csrf_at'] = time();
        }
        return $_SESSION['_csrf'];
    }

    public static function verify(?string $token): bool
    {
        $stored   = $_SESSION['_csrf']    ?? null;
        $issuedAt = $_SESSION['_csrf_at'] ?? 0;
        $ttl      = (int)($_ENV['CSRF_TOKEN_LIFETIME'] ?? 7200);
        if (!$stored || !$token || (time() - $issuedAt) > $ttl) {
            return false;
        }
        return hash_equals($stored, $token);
    }

    public static function rotate(): void
    {
        unset($_SESSION['_csrf'], $_SESSION['_csrf_at']);
    }
}
