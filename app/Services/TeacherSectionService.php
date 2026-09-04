<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Support\CurriculumLookup;
use InvalidArgumentException;
use PDO;

/**
 * Incarichi docente → sezione, decisi dall'amministratore.
 *
 * Serve a rispondere a una domanda che il filtro contenuti oggi non sa porsi:
 * "quali docenti insegnano nella sezione di questo studente?". Senza, uno
 * studente di 1A vede anche il materiale della 1B, perche' il filtro dice solo
 * "docenti dell'istituto".
 *
 * REGOLA DI MATCHING (il punto che va deciso una volta e scritto qui)
 *
 *   "1"  e' l'anno di corso     — vale per TUTTE le sue sezioni
 *   "1A" e' la singola sezione  — vale solo per quella
 *
 * Da cui:
 *   - docente su "1"  → raggiunge studenti di 1, 1A, 1B, …
 *   - docente su "1A" → raggiunge solo studenti di 1A
 *   - studente su "1A" → vede docenti su "1A" e su "1"
 *   - studente su "1"  → vede solo docenti su "1"
 *
 * L'asimmetria dell'ultimo caso e' voluta: uno studente che non ha dichiarato
 * la sezione non puo' essere assegnato d'ufficio a una qualsiasi. Vede il
 * materiale comune al corso, e per il resto va completato il suo profilo.
 */
final class TeacherSectionService
{
    /** Anno + sezione opzionale: "1", "1A", "3BS". */
    private const CLASSE_PATTERN = '/^([1-9])([A-Z0-9]{0,5})$/';

    public function __construct(private ?PDO $pdo = null)
    {
    }

    private function pdo(): PDO
    {
        return $this->pdo ??= Database::connection();
    }

    /**
     * Un incarico su $classeDocente raggiunge uno studente su $classeStudente?
     *
     * Confronto case-insensitive: le classi in curriculum_entries convivono
     * gia' in maiuscolo e minuscolo ("3B" e "1b").
     */
    public static function classeMatches(?string $classeDocente, ?string $classeStudente): bool
    {
        $d = strtoupper(trim((string)$classeDocente));
        $s = strtoupper(trim((string)$classeStudente));
        if ($d === '' || $s === '') {
            return false;
        }
        if ($d === $s) {
            return true;
        }
        if (!preg_match(self::CLASSE_PATTERN, $d, $md) || !preg_match(self::CLASSE_PATTERN, $s, $ms)) {
            return false;
        }
        // Stesso anno, e il docente non ha specificato la sezione: copre tutte.
        return $md[1] === $ms[1] && $md[2] === '';
    }

    /** Anno di corso di una classe ("1A" → "1"), null se non riconosciuta. */
    public static function anno(?string $classe): ?string
    {
        return preg_match(self::CLASSE_PATTERN, strtoupper(trim((string)$classe)), $m) ? $m[1] : null;
    }

    /**
     * Docenti che raggiungono uno studente, in ordine di id.
     *
     * Lista VUOTA quando nessun incarico e' stato assegnato per quella
     * (istituto, indirizzo, anno): significa "sezioni non ancora in uso", e il
     * chiamante deve ricadere sul comportamento precedente invece di nascondere
     * tutto. Una tabella vuota non e' un divieto.
     *
     * @return list<int>
     */
    public function teachersForStudent(int $instituteId, ?string $indirizzo, ?string $classe): array
    {
        $anno = self::anno($classe);
        if ($instituteId <= 0 || $indirizzo === null || $indirizzo === '' || $anno === null) {
            return [];
        }
        // Si prendono tutti gli incarichi dell'anno e si filtra in PHP con la
        // stessa funzione che i test verificano: la regola vive in un posto solo.
        $stmt = $this->pdo()->prepare(
            'SELECT user_id, classe FROM teacher_sections
              WHERE institute_id = ? AND UPPER(indirizzo) = UPPER(?)
                AND classe LIKE ?
              ORDER BY user_id'
        );
        $stmt->execute([$instituteId, $indirizzo, $anno . '%']);

        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            if (self::classeMatches((string)$row['classe'], $classe)) {
                $out[(int)$row['user_id']] = true;
            }
        }
        return array_map('intval', array_keys($out));
    }

    /**
     * Esistono incarichi per questa (istituto, indirizzo, anno)?
     *
     * Distingue "nessun docente raggiunge lo studente" da "le sezioni non sono
     * in uso qui": senza questa domanda il filtro non saprebbe se una lista
     * vuota vuol dire zero risultati o nessuna configurazione.
     */
    public function sectionsInUse(int $instituteId, ?string $indirizzo, ?string $classe): bool
    {
        $anno = self::anno($classe);
        if ($instituteId <= 0 || $indirizzo === null || $indirizzo === '' || $anno === null) {
            return false;
        }
        $stmt = $this->pdo()->prepare(
            'SELECT 1 FROM teacher_sections
              WHERE institute_id = ? AND UPPER(indirizzo) = UPPER(?) AND classe LIKE ?
              LIMIT 1'
        );
        $stmt->execute([$instituteId, $indirizzo, $anno . '%']);
        return (bool)$stmt->fetchColumn();
    }

    /** @return list<array<string,mixed>> incarichi di un istituto */
    public function listForInstitute(int $instituteId): array
    {
        $stmt = $this->pdo()->prepare(
            'SELECT ts.id, ts.user_id, ts.indirizzo, ts.classe, ts.assigned_at, ts.note,
                    u.username,
                    COALESCE(NULLIF(TRIM(CONCAT_WS(" ", u.first_name, u.last_name)), ""), u.username) AS nome
               FROM teacher_sections ts
               JOIN users u ON u.id = ts.user_id
              WHERE ts.institute_id = ?
              ORDER BY ts.indirizzo, ts.classe, nome'
        );
        $stmt->execute([$instituteId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /** Assegna un incarico. Idempotente: riassegnare non duplica. */
    public function assign(
        int $userId,
        int $instituteId,
        string $indirizzo,
        string $classe,
        ?int $assignedBy = null,
        ?string $note = null
    ): void {
        $indirizzo = strtoupper(trim($indirizzo));
        $classe    = strtoupper(trim($classe));
        if ($userId <= 0 || $instituteId <= 0) {
            throw new InvalidArgumentException('invalid_assignment_target');
        }
        if (!preg_match('/^[A-Z]{3,6}$/', $indirizzo)) {
            throw new InvalidArgumentException('invalid_indirizzo');
        }
        if (!preg_match(self::CLASSE_PATTERN, $classe)) {
            throw new InvalidArgumentException('invalid_classe');
        }
        // Un incarico in un istituto a cui il docente non e' collegato sarebbe
        // orfano, e nessuno se ne accorgerebbe: lo studente vedrebbe zero
        // contenuti senza spiegazione.
        $chk = $this->pdo()->prepare('SELECT 1 FROM teacher_institutes WHERE user_id = ? AND institute_id = ? LIMIT 1');
        $chk->execute([$userId, $instituteId]);
        if (!$chk->fetchColumn()) {
            throw new InvalidArgumentException('teacher_not_linked_to_institute');
        }

        $stmt = $this->pdo()->prepare(
            'INSERT INTO teacher_sections (user_id, institute_id, indirizzo, classe, assigned_by, note)
             VALUES (?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE assigned_by = VALUES(assigned_by), note = VALUES(note)'
        );
        $stmt->execute([$userId, $instituteId, $indirizzo, $classe, $assignedBy, $note]);

        // L'incarico e' scritto in `teacher_sections`, ma i selettori del
        // docente leggono le SUE righe di `curriculum_entries`. Due tabelle
        // diverse: senza questo passaggio l'amministratore assegnava la 1A e il
        // docente continuava a vedere il selettore Classe vuoto, senza che
        // niente segnalasse il perche'.
        //
        // Dire "insegna in 1A" e dire "puo' scegliere 1A" sono la stessa cosa:
        // non esiste un caso in cui l'incarico c'e' e la classe non deve
        // comparire. Quindi l'attivazione segue l'incarico, e non si aspetta
        // che qualcuno se ne ricordi.
        //
        // Copia dal vocabolario dell'istituto e non inventa: se l'ancora non
        // c'e' ritorna null e non succede niente. E' idempotente (INSERT
        // IGNORE), quindi riassegnare non duplica.
        CurriculumLookup::ensureEntryForTeacher('indirizzi', $userId, $indirizzo, $instituteId);
        CurriculumLookup::ensureEntryForTeacher('classi', $userId, $classe, $instituteId);
    }

    /**
     * Toglie un incarico preciso, senza doverne conoscere l'id.
     *
     * Serve al pannello quando l'amministratore toglie la spunta a una
     * sezione: li' si conosce chi/dove/cosa, non il numero di riga.
     */
    public function revokeSection(int $userId, int $instituteId, string $indirizzo, string $classe): bool
    {
        $stmt = $this->pdo()->prepare(
            'DELETE FROM teacher_sections
              WHERE user_id = ? AND institute_id = ? AND indirizzo = ? AND classe = ?'
        );
        $stmt->execute([$userId, $instituteId, strtoupper(trim($indirizzo)), strtoupper(trim($classe))]);
        return $stmt->rowCount() > 0;
    }

    public function revoke(int $assignmentId): bool
    {
        $stmt = $this->pdo()->prepare('DELETE FROM teacher_sections WHERE id = ?');
        $stmt->execute([$assignmentId]);
        return $stmt->rowCount() > 0;
    }
}
