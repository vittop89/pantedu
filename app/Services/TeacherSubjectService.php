<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Repositories\Curriculum\CurriculumTeacherRepository;
use InvalidArgumentException;
use PDO;

/**
 * Le materie che un docente insegna.
 *
 * Il vocabolario delle materie è dell'istituto («qui si insegna Matematica»:
 * riga di `curriculum_entries` senza proprietario). Che un docente insegni
 * Matematica è una SPUNTA su quella voce, cioè una riga di
 * `curriculum_teacher` (ADR-035). Fino al 13 settembre 2026 la spunta era una
 * copia della riga: la stessa materia esisteva in tante versioni quanti erano
 * i docenti.
 *
 * TOGLIERE UNA MATERIA NON CANCELLA NIENTE: spegne la spunta. I contenuti
 * puntano alla voce della scuola, non alla spunta, e non perdono la categoria
 * per correggere un errore di clic. Riaccenderla è un clic.
 *
 * Chi decide: l'admin (come per le sezioni). Il docente propone le proprie al
 * primo accesso — se sbaglia, l'admin corregge.
 */
final class TeacherSubjectService
{
    private const KIND = 'materie';

    public function __construct(private readonly ?CurriculumTeacherRepository $relazione = null)
    {
    }

    private function pdo(): PDO
    {
        return Database::connection();
    }

    private function relazione(): CurriculumTeacherRepository
    {
        return $this->relazione ?? new CurriculumTeacherRepository();
    }

    /**
     * Materie attive di un docente in un istituto.
     *
     * @return list<array{code:string,label:string}>
     */
    public function forTeacher(int $userId, int $instituteId): array
    {
        $out = [];
        foreach ($this->relazione()->perDocente($userId, $instituteId, self::KIND, true) as $r) {
            $out[] = ['code' => (string)$r['code'], 'label' => (string)$r['label']];
        }
        return $out;
    }

    /**
     * Vocabolario dell'istituto: le materie fra cui si può scegliere.
     *
     * @return list<array{code:string,label:string}>
     */
    public function available(int $instituteId): array
    {
        $st = $this->pdo()->prepare(
            'SELECT code, label FROM curriculum_entries
              WHERE kind = ? AND institute_id = ? AND active = 1
              ORDER BY label'
        );
        $st->execute([self::KIND, $instituteId]);
        /** @var list<array{code:string,label:string}> $rows */
        $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
        return $rows;
    }

    /**
     * Tutti i docenti dell'istituto con le loro materie. Per il pannello.
     *
     * @return array<int, list<array{code:string,label:string}>> user_id → materie
     */
    public function byInstitute(int $instituteId): array
    {
        $st = $this->pdo()->prepare(
            'SELECT ct.user_id AS uid, c.code, COALESCE(ct.label_override, c.label) AS label
               FROM curriculum_teacher ct
               JOIN curriculum_entries c ON c.id = ct.curriculum_id
               JOIN teacher_institutes t ON t.user_id = ct.user_id AND t.institute_id = c.institute_id
              WHERE c.kind = ? AND c.institute_id = ? AND ct.active = 1 AND c.active = 1
              ORDER BY label'
        );
        $st->execute([self::KIND, $instituteId]);
        $out = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $out[(int)$r['uid']][] = ['code' => (string)$r['code'], 'label' => (string)$r['label']];
        }
        return $out;
    }

    /**
     * Fissa l'elenco completo delle materie di un docente.
     *
     * È un "set", non un "add": quello che non è nell'elenco viene spento.
     * Così il pannello manda le caselle spuntate così come sono, senza dover
     * calcolare la differenza — e senza che una casella tolta resti attiva
     * perché nessuno ha pensato a mandarla.
     *
     * @param  list<string> $codici
     * @return array{attivate:list<string>,disattivate:list<string>}
     */
    public function set(int $userId, int $instituteId, array $codici, ?int $actorId = null): array
    {
        if ($userId <= 0 || $instituteId <= 0) {
            throw new InvalidArgumentException('invalid_target');
        }
        $chk = $this->pdo()->prepare(
            'SELECT 1 FROM teacher_institutes WHERE user_id = ? AND institute_id = ? LIMIT 1'
        );
        $chk->execute([$userId, $instituteId]);
        if (!$chk->fetchColumn()) {
            throw new InvalidArgumentException('teacher_not_linked_to_institute');
        }

        // Solo codici che l'istituto conosce davvero: una materia inventata
        // non avrebbe una voce da spuntare, e sparirebbe in silenzio.
        $idPerCodice = [];
        $sel = $this->pdo()->prepare(
            'SELECT id, code FROM curriculum_entries
              WHERE kind = ? AND institute_id = ? AND active = 1'
        );
        $sel->execute([self::KIND, $instituteId]);
        foreach ($sel->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $idPerCodice[strtoupper((string)$r['code'])] = (int)$r['id'];
        }
        $voluti = [];
        foreach ($codici as $c) {
            $c = strtoupper(trim((string)$c));
            if ($c !== '' && isset($idPerCodice[$c])) {
                $voluti[$c] = true;
            }
        }

        $prima = [];
        foreach ($this->forTeacher($userId, $instituteId) as $r) {
            $prima[strtoupper((string)$r['code'])] = true;
        }

        $attivate = $disattivate = [];
        foreach (array_keys($voluti) as $code) {
            if (isset($prima[$code])) {
                continue;
            }
            $this->relazione()->attiva($idPerCodice[$code], $userId);
            $attivate[] = $code;
        }
        foreach (array_keys($prima) as $code) {
            if (isset($voluti[$code]) || !isset($idPerCodice[$code])) {
                continue;
            }
            $this->relazione()->disattiva($idPerCodice[$code], $userId);
            $disattivate[] = $code;
        }

        return ['attivate' => $attivate, 'disattivate' => $disattivate];
    }

    /**
     * Docenti dell'istituto senza nessuna materia attiva.
     *
     * Non possono pubblicare niente di categorizzabile, e per il pannello è
     * la stessa domanda delle classi scoperte: chi non è ancora a posto.
     *
     * @return list<int>
     */
    public function senzaMaterie(int $instituteId): array
    {
        $st = $this->pdo()->prepare(
            'SELECT t.user_id
               FROM teacher_institutes t
              WHERE t.institute_id = ?
                AND NOT EXISTS (
                    SELECT 1
                      FROM curriculum_teacher ct
                      JOIN curriculum_entries c ON c.id = ct.curriculum_id
                     WHERE ct.user_id = t.user_id AND ct.active = 1
                       AND c.kind = ? AND c.institute_id = t.institute_id AND c.active = 1
                )'
        );
        $st->execute([$instituteId, self::KIND]);
        return array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN) ?: []);
    }
}
