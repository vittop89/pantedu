<?php

declare(strict_types=1);

namespace App\Support;

use App\Core\Auth;
use App\Core\Database;

/**
 * G22.S15.bis Fase 5+ — Helper centralizzato per resolve teacher context.
 *
 * Sostituisce 3 pattern duplicati in 4-5 classi:
 *   - resolveUserId(username) : ContentStudyController, VerificaBuilderController,
 *                               PrintInfoService
 *   - primo istituto (tid)    : ContentStudyController, TeacherContentController
 *   - isLinkedToInstitute     : CurriculumController, TeacherCurriculumPivotController
 *
 * Tutti i metodi static, no state. Safe a chiamare anche se DB non
 * disponibile (ritornano 0/false invece di throw).
 *
 * Due scuole diverse per lo stesso docente (23/9/2026, revisione
 * architetturale A-7): la «casa dei file privati» (privateFilesInstituteId,
 * fino a oggi firstInstituteId) e l'«istituto attivo» (activeInstituteId).
 * Quale si usa per che cosa lo dice wiki/glossary.md.
 */
final class TeacherContextResolver
{
    /**
     * Id dell'utente autenticato, 0 se nessuno.
     *
     * Revisione 2026-09, P4: sostituisce il `teacherId()` privato copiato in
     * sedici controller. Preferisce l'id gia' in sessione; se la sessione
     * (creata da una versione precedente) non lo porta, lo risolve dallo
     * username come facevano quelle copie. Nessun controllo di ruolo: chi
     * lo vuole usa `AuthHelpers::teacherUsernameOrThrow()` o
     * `Auth::hasRole()`, come prima.
     */
    public static function currentTeacherId(): int
    {
        $u = Auth::user();
        if (!$u) {
            return 0;
        }
        $id = (int)($u['id'] ?? 0);
        if ($id > 0) {
            return $id;
        }
        return self::userIdFromUsername((string)($u['username'] ?? ''));
    }

    /**
     * Risolve users.id da username. Ritorna 0 se non trovato o DB down.
     */
    public static function userIdFromUsername(string $username): int
    {
        if ($username === '' || !Database::isAvailable()) {
            return 0;
        }
        $stmt = Database::connection()->prepare('SELECT id FROM users WHERE username = ? LIMIT 1');
        $stmt->execute([$username]);
        return (int)($stmt->fetchColumn() ?: 0);
    }

    /**
     * La casa dei file privati del docente: il primo istituto collegato per
     * id, preferendo quelli senza codice `MIUR-%`. 0 se non ne ha nessuno.
     *
     * Non guarda il selettore delle scuole, ed è voluto: le sue preferenze
     * (registro delle fonti, stile dei badge, modelli, fonti spuntate) stanno
     * sotto `institutes/{casa}/private/{docente}/` e si leggono da lì in
     * qualunque scuola lavori. Per la scuola in cui il docente sta lavorando
     * (sezioni della barra, curriculum, contratti dei contenuti nuovi,
     * intestazione di studio) c'è activeInstituteId().
     *
     * Fino al 23/9/2026 si chiamava firstInstituteId, un nome che non diceva
     * a che cosa serve: in tre punti era usato come istituto attivo.
     */
    public static function privateFilesInstituteId(int $teacherId): int
    {
        if ($teacherId <= 0 || !Database::isAvailable()) {
            return 0;
        }
        $stmt = Database::connection()->prepare(
            "SELECT i.id FROM institutes i
             INNER JOIN teacher_institutes ti ON ti.institute_id = i.id
             WHERE ti.user_id = ? AND i.code NOT LIKE 'MIUR-%'
             ORDER BY i.id LIMIT 1"
        );
        $stmt->execute([$teacherId]);
        $id = (int)($stmt->fetchColumn() ?: 0);
        if ($id > 0) {
            return $id;
        }
        $stmt = Database::connection()->prepare(
            'SELECT institute_id FROM teacher_institutes WHERE user_id = ? LIMIT 1'
        );
        $stmt->execute([$teacherId]);
        return (int)($stmt->fetchColumn() ?: 0);
    }

    /**
     * L'istituto attivo: la scuola in cui il docente sta lavorando, quella
     * del selettore, o la prima collegata per chi ne ha una sola. 0 se non ne
     * ha nessuna. È la stessa in cui TeacherContentRepository risolve
     * indirizzo, classe e materia (CurriculumLookup::instituteForTeacher).
     *
     * Il confine di ADR-037: la scuola in sessione conta solo se il docente
     * le appartiene; altrimenti si ripiega sulla sua prima.
     */
    public static function activeInstituteId(int $teacherId): int
    {
        return (int)(CurriculumLookup::instituteForTeacher($teacherId) ?? 0);
    }

    /**
     * Risolve il code dell'istituto principale del docente (primo collegato
     * via teacher_institutes ORDER BY created_at). Fallback 'default' se
     * nessun istituto collegato — coerente con VerificaSyncService /
     * MapSyncService legacy.
     */
    public static function instituteCodeForTeacher(int $teacherId): string
    {
        if ($teacherId <= 0 || !Database::isAvailable()) {
            return 'default';
        }
        $stmt = Database::connection()->prepare(
            'SELECT i.code FROM teacher_institutes ti
             JOIN institutes i ON i.id = ti.institute_id
             WHERE ti.user_id = ? ORDER BY ti.created_at LIMIT 1'
        );
        $stmt->execute([$teacherId]);
        $code = $stmt->fetchColumn();
        return \is_string($code) && $code !== '' ? $code : 'default';
    }

    /**
     * True se il docente è collegato all'istituto via teacher_institutes.
     */
    public static function isLinkedToInstitute(int $teacherId, int $instituteId): bool
    {
        if ($teacherId <= 0 || $instituteId <= 0 || !Database::isAvailable()) {
            return false;
        }
        $stmt = Database::connection()->prepare(
            'SELECT 1 FROM teacher_institutes WHERE user_id = ? AND institute_id = ? LIMIT 1'
        );
        $stmt->execute([$teacherId, $instituteId]);
        return (bool)$stmt->fetchColumn();
    }
}
