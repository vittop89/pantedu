<?php

declare(strict_types=1);

namespace App\Support;

use App\Core\Database;

/**
 * G22.S15.bis Fase 5+ — Helper centralizzato per resolve teacher context.
 *
 * Sostituisce 3 pattern duplicati in 4-5 classi:
 *   - resolveUserId(username) : ContentStudyController, TeacherSubjectController,
 *                               VerificaBuilderController, PrintInfoService
 *   - firstInstituteId(tid)   : ContentStudyController, TeacherContentController
 *   - isLinkedToInstitute     : CurriculumController, TeacherCurriculumPivotController
 *
 * Tutti i metodi static, no state. Safe a chiamare anche se DB non
 * disponibile (ritornano 0/false invece di throw).
 */
final class TeacherContextResolver
{
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
     * Primo istituto del docente (preferendo non-MIUR-fallback).
     * Ritorna 0 se nessun istituto collegato.
     */
    public static function firstInstituteId(int $teacherId): int
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
