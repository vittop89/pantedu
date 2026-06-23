<?php

namespace App\Core\Gateway;

use App\Core\Auth;
use App\Core\Contracts\AuthInterface;

final class AuthGateway implements AuthInterface
{
    public function user(): ?array
    {
        return Auth::user();
    }
    public function check(): bool
    {
        return Auth::user() !== null;
    }
    public function id(): ?int
    {
        $u = Auth::user();
        return $u ? (int)($u['id'] ?? 0) : null;
    }
}
