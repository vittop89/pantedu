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
 * Chi sta nell'area del docente: chi insegna (App\Domain\Role::canTeach:
 * docente e amministratore) e il super-admin.
 */
final class AuthHelpers
{
    /**
     * True se l'utente loggato insegna (docente o amministratore) o è super-admin.
     * False se non loggato o role diverso.
     *
     * 2026-09-14 — confrontava `users.role` con 'teacher', 'admin' e
     * 'super_admin'. In database l'amministratore è `administrator` e il
     * super-admin è un flag, non un ruolo: all'amministratore «Sposta di
     * classe», il profilo del docente, le informazioni di stampa e le verifiche
     * rispondevano 403. Il ruolo si legge con l'enum, che conosce anche l'alias
     * storico `admin`; il super-admin con il suo flag.
     */
    public static function isTeacherOrAdmin(): bool
    {
        if (!Auth::check()) {
            return false;
        }
        return (\App\Domain\Role::tryFromString(Auth::role())?->canTeach() ?? false)
            || Auth::isSuperAdmin();
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
