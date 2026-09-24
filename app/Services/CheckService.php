<?php

namespace App\Services;

use App\Repositories\UserRepository;

/**
 * Service per l'endpoint "check" della password admin.
 */
final class CheckService
{
    /** Ritorna true se la password matcha un admin attivo (DB-only). */
    public function verifyAdminPassword(string $password): bool
    {
        if ($password === '') {
            return false;
        }
        foreach ((new UserRepository())->all() as $user) {
            if ($user->isAdmin() && $user->active && $user->verifyPassword($password)) {
                return true;
            }
        }
        return false;
    }
}
