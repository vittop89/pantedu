<?php

namespace App\Services;

use App\Core\Auth;
use App\Core\Database;
use App\Core\Session;

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
    /**
     * Il caller ha il flag is_super_admin?
     *
     * 23/9/2026 — l'unica implementazione: Auth::isSuperAdmin() delega qui
     * (revisione 2026-09, rilievo A-47). Prima ce n'erano due con due cache:
     * questa rileggeva ogni cinque minuti (in memoria e in
     * `fm_super_admin_cache`), quella di Auth teneva il valore in sessione
     * fino al logout, e `role:admin` guardava quella. Un flag revocato nel
     * database apriva la zona admin per tutta la sessione.
     *
     * Ora il valore dell'utente corrente sta con gli altri claims della
     * sessione (ruolo, account attivo), riletti insieme da
     * Auth::chiudiSeRevocata() e validi Auth::CLAIMS_TTL_SECONDS: la stessa
     * lettura con cui AuthMiddleware chiude le sessioni revocate. Una query al
     * minuto per sessione, e un TTL solo.
     *
     * Il flag si concede solo a una sessione che, riletta, è ancora quella del
     * login: account presente e attivo, ruolo e flag invariati. Altrimenti la
     * sessione si chiude lì, anche sulle rotte senza `auth` — il cancello di
     * Grafana, /metrics —, e la risposta è no. Se i claims sono scaduti e il
     * database non risponde, la risposta è no: un privilegio che non si
     * riesce a verificare non si concede.
     */
    public static function isSuperAdmin(?string $username = null): bool
    {
        $corrente = Auth::check() ? (string)Session::get('username', '') : '';
        $username ??= $corrente;
        if ($username === '') {
            return false;
        }
        if ($username === $corrente) {
            return self::superAdminDellaSessione();
        }
        // Un altro utente: nessuna cache, lo dice il database.
        try {
            if (!Database::isAvailable()) {
                return false;
            }
            $stmt = Database::connection()->prepare(
                'SELECT is_super_admin FROM users WHERE username = ? LIMIT 1'
            );
            $stmt->execute([$username]);
            return (bool)$stmt->fetchColumn();
        } catch (\Throwable $e) {
            \error_log('[AclPolicy::isSuperAdmin] ' . $e->getMessage());
            return false;
        }
    }

    private static function superAdminDellaSessione(): bool
    {
        // Rilegge se i claims sono scaduti. Un account sparito, disattivato
        // (anche con i claims freschi) o cambiato: la sessione è chiusa lì, e
        // qui non c'è più nessuno.
        if (Auth::chiudiSeRevocata()) {
            return false;
        }
        $letti = Session::get('claims_at');
        if (!\is_int($letti) || time() - $letti >= Auth::CLAIMS_TTL_SECONDS) {
            // Non riletti: il database non ha risposto, o la domanda arriva
            // mentre la verifica è in corso (Auth::$verificaInCorso).
            return false;
        }
        return Session::get('is_super_admin') === true;
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
