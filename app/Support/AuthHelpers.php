<?php

declare(strict_types=1);

namespace App\Support;

use App\Core\Auth;

/**
 * G22.S15.bis Fase 5+ — Helper centralizzato per check ruolo.
 *
 * Sostituisce 3+ pattern duplicati:
 *   - PrintInfoController                  : if ($role !== 'teacher' && ...) throw
 *   - TeacherProfileController::page       : if (!Auth::check() || ...) return 403
 *   - VerificaSharedHelpersTrait::teacherId: identical role check inline
 *
 * Roles considerati "teacher area": teacher, admin, super_admin.
 */
final class AuthHelpers
{
    /**
     * True se l'utente loggato è teacher/admin/super_admin.
     * False se non loggato o role diverso.
     */
    public static function isTeacherOrAdmin(): bool
    {
        if (!Auth::check()) {
            return false;
        }
        $u = Auth::user();
        $role = (string)($u['role'] ?? '');
        return $role === 'teacher' || $role === 'admin' || $role === 'super_admin';
    }

    /**
     * Throw RuntimeException('forbidden') se non teacher/admin.
     * Comodo nei controller che usano try/catch + statusFor.
     */
    public static function assertTeacherOrAdmin(): void
    {
        if (!self::isTeacherOrAdmin()) {
            throw new \RuntimeException('forbidden');
        }
    }

    /**
     * Like assertTeacherOrAdmin ma ritorna anche l'username
     * (comodo nei flow che lo usano subito dopo).
     */
    public static function teacherUsernameOrThrow(): string
    {
        self::assertTeacherOrAdmin();
        $u = Auth::user();
        $username = (string)($u['username'] ?? '');
        if ($username === '') {
            throw new \RuntimeException('unauthenticated');
        }
        return $username;
    }
}
