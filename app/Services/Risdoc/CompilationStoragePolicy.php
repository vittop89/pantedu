<?php

declare(strict_types=1);

namespace App\Services\Risdoc;

use App\Core\Database;
use Throwable;

/**
 * Le compilazioni di un modello si possono salvare sul server per questo
 * docente?
 *
 * PERCHE' (2026-09-04)
 *   I modelli della categoria `modelli` producono atti dell'Istituto e
 *   parlano di studenti per natura (relazione finale, schede di recupero).
 *   Il DPO di un Istituto puo' chiedere che per i suoi docenti la
 *   compilazione non resti sul server. L'amministratore lo imposta per
 *   Istituto (`institutes.compilation_storage`, migration 103); qui si
 *   decide, e CompilationController::save rifiuta il salvataggio quando la
 *   risposta e' no. Il client tiene allora la bozza nel browser ed esporta
 *   il PDF: sul server resta il solo modello.
 *
 * REGOLA
 *   Vale per i soli modelli istituzionali (categoria `modelli`). Un docente
 *   puo' appartenere a piu' Istituti: basta che uno abbia disattivato il
 *   salvataggio perche' il salvataggio sia negato — e' la lettura prudente,
 *   e un docente con due Istituti e' un caso raro.
 */
final class CompilationStoragePolicy
{
    public const INSTITUTIONAL_CATEGORY = 'modelli';

    /** @param array<string,mixed> $template riga di risdoc_templates */
    public static function isInstitutional(array $template): bool
    {
        return (string)($template['category'] ?? '') === self::INSTITUTIONAL_CATEGORY;
    }

    /** @param array<string,mixed> $template */
    public static function allowedFor(int $teacherId, array $template): bool
    {
        if (!self::isInstitutional($template)) {
            return true;
        }
        return !self::storageDisabledForTeacher($teacherId);
    }

    /** Uno degli Istituti attivi del docente ha il salvataggio disattivato? */
    public static function storageDisabledForTeacher(int $teacherId): bool
    {
        if ($teacherId <= 0) {
            return false;
        }
        try {
            $st = Database::connection()->prepare(
                'SELECT COUNT(*) FROM teacher_institutes ti
                   JOIN institutes i ON i.id = ti.institute_id
                  WHERE ti.user_id = ? AND i.active = 1 AND i.compilation_storage = 0'
            );
            $st->execute([$teacherId]);
            return (int)$st->fetchColumn() > 0;
        } catch (Throwable) {
            // Colonna assente (migration 103 non applicata): comportamento di
            // sempre, si salva. Meglio un default noto che un rifiuto a sorpresa.
            return false;
        }
    }
}
