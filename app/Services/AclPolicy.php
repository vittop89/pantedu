<?php

namespace App\Services;

use App\Core\Auth;
use App\Core\Database;
use PDO;

/**
 * Phase 14 — policy centralizzata per ACL teacher / super-admin / pool.
 *
 * Regole (vedi todo_prompt_ia.md §1.4):
 *   - Teacher: admin della propria sezione (materiali + studenti propri).
 *     Non vede studenti di altri docenti né materiali altrui (solo quelli
 *     nel pool del proprio istituto, se pool_enabled).
 *   - Super-Admin (tecnico): READ-ONLY tracciato su materiali. ZERO
 *     accesso a liste studenti altrui (GDPR minimizzazione).
 *   - Studente: non tocca ACL server-side (route students+ gating).
 *
 * Ogni decisione che richiede accesso privilegiato DEVE essere accompagnata
 * da una chiamata a PrivilegedAccessLogger::log(...).
 */
final class AclPolicy
{
    /** PROBLEM-5 — cache TTL 5min per is_super_admin. La cache vive in memoria
     *  per request (request-scoped) per evitare N query identiche durante una
     *  request, e in $_SESSION con timestamp per persistere fra request senza
     *  costringere logout/login a ogni toggle. TTL breve (300s) garantisce che
     *  il claim si aggiorni entro 5 min dal toggle (admin assegna/revoca super
     *  status via UsersAdminController). */
    private const SUPER_ADMIN_TTL_SECONDS = 300;
    /** Cache request-scoped: [username => ['v' => bool, 't' => unix_ts]] */
    private static array $superAdminMem = [];

    /** Il caller ha flag is_super_admin? (DB-backed se disponibile, con TTL.) */
    public static function isSuperAdmin(?string $username = null): bool
    {
        $username ??= (string)(Auth::user()['username'] ?? '');
        if ($username === '') {
            return false;
        }
        $now = time();
        // Layer 1: memoria di request
        if (
            isset(self::$superAdminMem[$username])
            && ($now - self::$superAdminMem[$username]['t']) < self::SUPER_ADMIN_TTL_SECONDS
        ) {
            return self::$superAdminMem[$username]['v'];
        }
        // Layer 2: session cache (vive fra request)
        $sessKey = 'fm_super_admin_cache';
        $sess = $_SESSION[$sessKey] ?? null;
        if (
            is_array($sess)
            && ($sess['username'] ?? '') === $username
            && ($now - (int)($sess['t'] ?? 0)) < self::SUPER_ADMIN_TTL_SECONDS
        ) {
            self::$superAdminMem[$username] = ['v' => (bool)$sess['v'], 't' => $now];
            return (bool)$sess['v'];
        }
        // Layer 3: DB lookup + cache write-through
        if (!Database::isAvailable()) {
            return false;
        }
        $stmt = Database::connection()->prepare(
            'SELECT is_super_admin FROM users WHERE username = ? LIMIT 1'
        );
        $stmt->execute([$username]);
        $v = (bool)$stmt->fetchColumn();
        self::$superAdminMem[$username] = ['v' => $v, 't' => $now];
        if (session_status() === PHP_SESSION_ACTIVE) {
            $_SESSION[$sessKey] = ['username' => $username, 'v' => $v, 't' => $now];
        }
        return $v;
    }

    /** PROBLEM-5 — invalidate super_admin cache per username (post-toggle).
     *  Chiamare da UsersAdminController dopo set/revoke is_super_admin. */
    public static function invalidateSuperAdminCache(string $username): void
    {
        unset(self::$superAdminMem[$username]);
        if (
            session_status() === PHP_SESSION_ACTIVE
            && (($_SESSION['fm_super_admin_cache']['username'] ?? '') === $username)
        ) {
            unset($_SESSION['fm_super_admin_cache']);
        }
    }

    /** Il caller è teacher regolare (role=teacher)? */
    public static function isTeacher(): bool
    {
        return Auth::role() === \App\Domain\Role::TEACHER->value;
    }

    /**
     * Vietato in ogni caso: un docente non vede studenti di un altro
     * docente; il super-admin non vede studenti punto (GDPR strict).
     */
    public static function canReadStudentsOfTeacher(int $actorTeacherId, int $ownerTeacherId): bool
    {
        if ($actorTeacherId === 0 || $ownerTeacherId === 0) {
            return false;
        }
        return $actorTeacherId === $ownerTeacherId;
    }

    /** Super-admin può leggere metriche tecniche (dashboard, quote, backup). */
    public static function canReadInfraMetrics(): bool
    {
        return self::isSuperAdmin();
    }
}
