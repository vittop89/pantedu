<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Support\CurriculumLookup;
use InvalidArgumentException;
use PDO;

/**
 * Le materie che un docente insegna.
 *
 * Non c'e' una tabella dedicata come per le sezioni, e non serve: il modello
 * del curriculum lo dice gia'. Una riga con owner_user_id NULL e' il
 * VOCABOLARIO dell'istituto ("qui si insegna Matematica"); una riga con
 * owner_user_id = docente e' l'ATTIVAZIONE ("questo docente insegna
 * Matematica"). Assegnare una materia significa creare la seconda a partire
 * dalla prima, ed e' esattamente cio' che fa CurriculumLookup.
 *
 * TOGLIERE UNA MATERIA NON CANCELLA LA RIGA: la disattiva. I contenuti gia'
 * pubblicati puntano a quell'id (teacher_content_data.subject_id), e
 * cancellarla renderebbe orfano il lavoro del docente per correggere un
 * errore di spunta. Disattivata sparisce dalle tendine e dai filtri, e
 * ripristinarla e' un clic.
 *
 * Chi decide: l'admin (come per le sezioni). Il docente propone le proprie
 * al primo accesso — se sbaglia, l'admin corregge.
 */
final class TeacherSubjectService
{
    private const KIND = 'materie';

    private function pdo(): PDO
    {
        return Database::connection();
    }

    /**
     * Materie attive di un docente in un istituto.
     *
     * @return list<array{code:string,label:string}>
     */
    public function forTeacher(int $userId, int $instituteId): array
    {
        $st = $this->pdo()->prepare(
            'SELECT code, label FROM curriculum_entries
              WHERE kind = ? AND institute_id = ? AND owner_user_id = ? AND active = 1
              ORDER BY label'
        );
        $st->execute([self::KIND, $instituteId, $userId]);
        return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * Vocabolario dell'istituto: le materie fra cui si puo' scegliere.
     *
     * @return list<array{code:string,label:string}>
     */
    public function available(int $instituteId): array
    {
        $st = $this->pdo()->prepare(
            'SELECT code, label FROM curriculum_entries
              WHERE kind = ? AND institute_id = ? AND owner_user_id IS NULL AND active = 1
              ORDER BY label'
        );
        $st->execute([self::KIND, $instituteId]);
        return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * Tutti i docenti dell'istituto con le loro materie. Per il pannello.
     *
     * @return array<int, list<array{code:string,label:string}>> user_id → materie
     */
    public function byInstitute(int $instituteId): array
    {
        $st = $this->pdo()->prepare(
            'SELECT c.owner_user_id AS uid, c.code, c.label
               FROM curriculum_entries c
               JOIN teacher_institutes t
                 ON t.user_id = c.owner_user_id AND t.institute_id = c.institute_id
              WHERE c.kind = ? AND c.institute_id = ? AND c.owner_user_id IS NOT NULL AND c.active = 1
              ORDER BY c.label'
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
     * E' un "set", non un "add": quello che non e' nell'elenco viene
     * disattivato. Cosi' il pannello puo' mandare le caselle spuntate cosi'
     * come sono, senza dover calcolare la differenza — e senza che una casella
     * tolta resti attiva perche' nessuno ha pensato a mandarla.
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
        // non avrebbe l'anchor da cui clonare, e sparirebbe in silenzio.
        $noti = [];
        foreach ($this->available($instituteId) as $r) {
            $noti[strtoupper((string)$r['code'])] = true;
        }
        $voluti = [];
        foreach ($codici as $c) {
            $c = strtoupper(trim((string)$c));
            if ($c !== '' && isset($noti[$c])) {
                $voluti[$c] = true;
            }
        }

        $prima = [];
        foreach ($this->forTeacher($userId, $instituteId) as $r) {
            $prima[strtoupper((string)$r['code'])] = true;
        }

        $attivate = $disattivate = [];
        $riattiva = $this->pdo()->prepare(
            'UPDATE curriculum_entries SET active = 1
              WHERE kind = ? AND institute_id = ? AND owner_user_id = ? AND code = ?'
        );
        foreach (array_keys($voluti) as $code) {
            if (isset($prima[$code])) {
                continue;
            }
            // Clona l'anchor se la riga non c'e'; se c'era ed era spenta la
            // riaccende, cosi' un errore di spunta si annulla senza perdere i
            // contenuti che ci puntavano.
            CurriculumLookup::ensureEntryForTeacher(self::KIND, $userId, $code, $instituteId);
            $riattiva->execute([self::KIND, $instituteId, $userId, $code]);
            $attivate[] = $code;
        }

        $spegni = $this->pdo()->prepare(
            'UPDATE curriculum_entries SET active = 0
              WHERE kind = ? AND institute_id = ? AND owner_user_id = ? AND code = ?'
        );
        foreach (array_keys($prima) as $code) {
            if (isset($voluti[$code])) {
                continue;
            }
            $spegni->execute([self::KIND, $instituteId, $userId, $code]);
            $disattivate[] = $code;
        }

        return ['attivate' => $attivate, 'disattivate' => $disattivate];
    }

    /**
     * Docenti dell'istituto senza nessuna materia attiva.
     *
     * Non possono pubblicare niente di categorizzabile, e per il pannello e'
     * la stessa domanda delle classi scoperte: chi non e' ancora a posto.
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
                    SELECT 1 FROM curriculum_entries c
                     WHERE c.kind = ? AND c.institute_id = t.institute_id
                       AND c.owner_user_id = t.user_id AND c.active = 1
                )'
        );
        $st->execute([$instituteId, self::KIND]);
        return array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN) ?: []);
    }
}
