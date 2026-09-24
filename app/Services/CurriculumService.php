<?php

namespace App\Services;

use App\Core\Config;
use App\Core\Database;
use App\Repositories\Curriculum\CurriculumTeacherRepository;
use PDO;
use RuntimeException;

/**
 * Il catalogo dell'istituto: indirizzi, classi e materie (ADR-035).
 *
 * Il VOCABOLARIO è dell'istituto: una riga per (kind, code, istituto) in
 * `curriculum_entries`, scritta dall'importatore MIUR o dall'amministratore
 * (`origine`). Il docente non possiede voci: le SPUNTA, e la spunta è una riga
 * di `curriculum_teacher` con il suo stato, la condivisione nel pool e
 * l'eventuale etichetta con cui vuole vedere la voce nei propri menù.
 *
 * Fino al 13 settembre 2026 il docente aveva una COPIA della riga
 * (`owner_user_id = docente`): la stessa aula esisteva in tante versioni
 * quanti erano i docenti, e tre letture deduplicavano per codice a ogni
 * richiesta. Le copie restano nel database finché la migrazione di pulizia
 * (rilascio successivo) non le toglie; nessuna lettura di questa classe le
 * guarda più.
 *
 * Il ripiego JSON (`storage/data/curriculum.json`) resta solo per quando il
 * database non c'è: sola lettura, voci «globali».
 */
final class CurriculumService
{
    public const KINDS = ['indirizzi', 'classi', 'materie'];
    /** Chi ha messo la voce nel vocabolario. */
    public const ORIGINI = ['miur', 'istituto', 'docente'];

    /**
     * Convenzione code per kind.
     *  - indirizzi/materie: 3-6 lettere maiuscole (MAT, SCI, AFM, ...)
     *  - classi: anno + sezione (1, 2B, 1ALSS: nel dataset MIUR il suffisso
     *    codifica anche l'indirizzo)
     */
    private const CODE_PATTERNS = [
        'indirizzi' => '#^[A-Z]{3,6}$#',
        'classi'    => '#^[1-9][A-Z0-9]{0,5}$#',
        'materie'   => '#^[A-Z]{3,6}$#',
    ];

    private const SELECT_VOCABOLARIO = "SELECT ce.id, ce.kind, ce.institute_id, ce.code, ce.label,
                        ce.label AS label_istituto, NULL AS label_override,
                        ce.grp, ce.indirizzo, ce.active, ce.shared_with_pool, ce.origine,
                        COALESCE(i.name, '') AS institute_name,
                        COALESCE(i.code, '') AS institute_code
                   FROM curriculum_entries ce
                   LEFT JOIN institutes i ON i.id = ce.institute_id
                  WHERE ce.kind IN ('indirizzi','classi','materie') ";

    public function __construct(
        private readonly string $jsonPath,
        private readonly ?CurriculumTeacherRepository $relazione = null,
    ) {
    }

    private function relazione(): CurriculumTeacherRepository
    {
        return $this->relazione ?? new CurriculumTeacherRepository();
    }

    private function dbPronto(): bool
    {
        return (bool)Config::get('database.enabled') && Database::isAvailable();
    }

    /**
     * Il catalogo.
     *
     *  (a) istituto + docente: le voci dell'istituto che il docente ha spuntato,
     *      con il suo stato e la sua etichetta — è il profilo;
     *  (b) solo istituto: il vocabolario della scuola — registrazione,
     *      amministrazione, esercizi;
     *  (c) solo docente: tutte le sue spunte, in tutti i suoi istituti;
     *  (d) nessuno dei due: le voci senza istituto (legacy della 036).
     *
     * @return array{indirizzi: list<array>, classi: list<array>, materie: list<array>}
     */
    public function all(?int $instituteId = null, ?int $teacherId = null): array
    {
        if ($this->dbPronto()) {
            return $this->loadFromDb($instituteId, $teacherId);
        }
        return $this->loadFromJsonFallback();
    }

    /** @return array{indirizzi: list<array>, classi: list<array>, materie: list<array>} */
    private function loadFromDb(?int $instituteId, ?int $teacherId = null): array
    {
        $out = ['indirizzi' => [], 'classi' => [], 'materie' => []];

        if ($teacherId !== null && $teacherId > 0) {
            foreach ($this->relazione()->perDocente($teacherId, $instituteId) as $row) {
                $kind = (string)$row['kind'];
                if (isset($out[$kind])) {
                    $out[$kind][] = $this->rowToShape($row, $teacherId);
                }
            }
            return $out;
        }

        // 2026-09-22 — senza istituto non c'e' nessun catalogo, e dirlo qui
        // costa meno che farlo scoprire da un elenco vuoto.
        //
        // Fino a oggi questo ramo interrogava `ce.institute_id IS NULL`, e
        // sembrava una rete di sicurezza: un «catalogo globale» a cui ripiegare.
        // Misurato: restituiva **zero voci di ogni tipo**. La colonna e' NOT
        // NULL nello schema e le righe globali le aveva cancellate la
        // migrazione 043; lo stesso controller lo dichiarava codice morto in un
        // commento, e intanto la query continuava a partire a ogni chiamata.
        //
        // Un ramo che sembra proteggere e non protegge e' peggio di un ramo che
        // non c'e': insegna a fidarsi di una rete che non esiste.
        if ($instituteId === null) {
            return $out;
        }

        $pdo = Database::connection();
        $stmt = $pdo->prepare(self::SELECT_VOCABOLARIO . 'AND ce.institute_id = ? ORDER BY ce.kind, ce.label');
        $stmt->execute([$instituteId]);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $kind = (string)$row['kind'];
            if (isset($out[$kind])) {
                $out[$kind][] = $this->rowToShape($row, null);
            }
        }
        return $out;
    }

    /** Ripiego in sola lettura (database assente). */
    private function loadFromJsonFallback(): array
    {
        if (!is_file($this->jsonPath)) {
            return ['indirizzi' => [], 'classi' => [], 'materie' => []];
        }
        $raw = file_get_contents($this->jsonPath);
        $data = json_decode((string)$raw, true);
        if (!is_array($data)) {
            return ['indirizzi' => [], 'classi' => [], 'materie' => []];
        }
        foreach (self::KINDS as $k) {
            $data[$k] = $data[$k] ?? [];
        }
        return $data;
    }

    /** @return list<array> */
    public function listActive(string $kind, ?int $instituteId = null, ?int $teacherId = null): array
    {
        $this->assertKind($kind);
        return array_values(array_filter(
            $this->all($instituteId, $teacherId)[$kind] ?? [],
            fn(array $row) => (bool)($row['active'] ?? false)
        ));
    }

    /**
     * Il vocabolario attivo di un istituto, per chi non ha spunte proprie: lo
     * studente in registrazione, la barra laterale dello studente. Una riga
     * per codice: il vocabolario è unico per costruzione.
     *
     * @return list<array>
     */
    public function listActiveForInstitute(string $kind, int $instituteId): array
    {
        $this->assertKind($kind);
        if (!$this->dbPronto()) {
            return [];
        }
        $stmt = Database::connection()->prepare(
            self::SELECT_VOCABOLARIO . 'AND ce.kind = ? AND ce.institute_id = ? AND ce.active = 1 ORDER BY ce.label'
        );
        $stmt->execute([$kind, $instituteId]);
        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $out[] = $this->rowToShape($row, null);
        }
        return $out;
    }

    /** @return array{indirizzi: list<array>, classi: list<array>, materie: list<array>} */
    public function allActiveForInstitute(int $instituteId): array
    {
        return [
            'indirizzi' => $this->listActiveForInstitute('indirizzi', $instituteId),
            'classi'    => $this->listActiveForInstitute('classi', $instituteId),
            'materie'   => $this->listActiveForInstitute('materie', $instituteId),
        ];
    }

    /**
     * Aggiunge.
     *
     *  - senza docente (`$ownerUserId` null): una voce nuova nel vocabolario
     *    della scuola, con la sua provenienza. È il percorso amministrativo, e
     *    l'unico modo in cui nasce un codice nuovo;
     *  - con docente: la SPUNTA di una voce che la scuola ha già. Una sigla che
     *    la scuola non ha viene rifiutata (`unknown_<kind>_for_institute`): due
     *    docenti che creano «SCI» e «SCIE» entrambi «Scientifico» darebbero allo
     *    studente in registrazione due voci identiche, e chi sceglie quella
     *    sbagliata si aggancia a un codice che i contenuti non usano, in
     *    silenzio. L'etichetta scritta dal docente, se diversa da quella della
     *    scuola, diventa la sua etichetta personale.
     *
     * ADR-042 — un anno («3») esiste una volta per corso: si aggiunge e si
     * spunta con l'indirizzo (`anno_senza_indirizzo`), che per una voce nuova
     * deve essere un corso della scuola (`indirizzo_sconosciuto`).
     */
    public function add(
        string $kind,
        array $item,
        ?int $instituteId = null,
        ?int $ownerUserId = null,
        string $origine = 'istituto',
    ): array {
        $this->assertKind($kind);
        if ($instituteId === null) {
            throw new RuntimeException('institute_id_required');
        }
        $code  = trim((string)($item['code']  ?? ''));
        $label = trim((string)($item['label'] ?? ''));
        $group = trim((string)($item['group'] ?? ''));
        $active = (bool)($item['active'] ?? true);
        // Migration 100 — corso di appartenenza della sezione. Ha senso solo
        // per le classi. Una sezione senza corso resta possibile (righe di
        // prima della 100); un anno no (ADR-042, chk_anno_ha_corso).
        $classeInd = $kind === 'classi'
            ? (strtoupper(trim((string)($item['indirizzo'] ?? ''))) ?: null)
            : null;
        if ($classeInd !== null && !preg_match('/^[A-Z]{3,6}$/', $classeInd)) {
            throw new RuntimeException('invalid_indirizzo_for_classe');
        }
        $anno = $kind === 'classi' && \App\Domain\ClassCode::isAnno($code);
        if ($anno && $classeInd === null) {
            throw new RuntimeException('anno_senza_indirizzo');
        }
        $pattern = self::CODE_PATTERNS[$kind] ?? null;
        if (!$pattern || !preg_match($pattern, $code)) {
            throw new RuntimeException('invalid_code_for_' . $kind);
        }
        if ($label === '' || strlen($label) > 120) {
            throw new RuntimeException('invalid_label');
        }
        if (strlen($group) > 60) {
            throw new RuntimeException('invalid_group');
        }
        if (!\in_array($origine, self::ORIGINI, true)) {
            throw new RuntimeException('invalid_origine');
        }
        if (!$this->dbPronto()) {
            throw new RuntimeException('db_required_for_add');
        }
        $pdo = Database::connection();

        if ($ownerUserId !== null && $ownerUserId > 0) {
            $stmt = $pdo->prepare('SELECT 1 FROM teacher_institutes WHERE user_id=? AND institute_id=? LIMIT 1');
            $stmt->execute([$ownerUserId, $instituteId]);
            if (!$stmt->fetchColumn()) {
                throw new RuntimeException('not_linked_to_institute');
            }
            $voce = $this->vocabolario($kind, $instituteId, $code, $anno ? $classeInd : null);
            if ($voce === null) {
                throw new RuntimeException('unknown_' . $kind . '_for_institute');
            }
            // ADR-041 — una sezione si spunta solo se la modalità dell'istituto
            // la ammette per questo docente.
            if (
                $kind === 'classi' && !(new SezioniDeiDocenti())->ammessa(
                    $ownerUserId,
                    $instituteId,
                    $code,
                    isset($voce['indirizzo']) ? (string)$voce['indirizzo'] : null
                )
            ) {
                throw new RuntimeException('sezione_non_ammessa');
            }
            if ($this->relazione()->esiste((int)$voce['id'], $ownerUserId)) {
                throw new RuntimeException('duplicate_code');
            }
            $this->relazione()->aggiorna((int)$voce['id'], $ownerUserId, [
                'active'         => $active,
                'label_override' => $label !== (string)$voce['label'] ? $label : null,
            ]);
            $mia = $this->getByIdForTeacher((int)$voce['id'], $ownerUserId);
            if ($mia === null) {
                throw new RuntimeException('entry_not_found');
            }
            return $mia;
        }

        if ($anno && $this->vocabolario('indirizzi', $instituteId, (string)$classeInd) === null) {
            throw new RuntimeException('indirizzo_sconosciuto');
        }
        try {
            $stmt = $pdo->prepare(
                'INSERT INTO curriculum_entries
                    (kind, institute_id, code, label, grp, indirizzo, active, shared_with_pool, origine)
                 VALUES (?, ?, ?, ?, ?, ?, ?, 0, ?)'
            );
            $stmt->execute([
                $kind, $instituteId, $code, $label,
                $group !== '' ? $group : null, $classeInd, (int)$active, $origine,
            ]);
            $id = (int)$pdo->lastInsertId();
        } catch (\PDOException $e) {
            if ((int)$e->errorInfo[1] === 1062) {
                throw new RuntimeException('duplicate_code');
            }
            throw $e;
        }
        $rec = $this->getById($id);
        if ($rec === null) {
            throw new RuntimeException('entry_not_found');
        }
        return $rec;
    }

    /**
     * Aggiorna.
     *
     *  - con `$forTeacher`: la relazione del docente con la voce (stato,
     *    condivisione, etichetta personale). L'etichetta uguale a quella della
     *    scuola, o vuota, toglie l'override;
     *  - senza: la voce del vocabolario (etichetta, gruppo, stato, origine).
     *    È il percorso amministrativo.
     */
    public function updateById(int $entryId, array $patch, ?int $forTeacher = null): array
    {
        if ($entryId <= 0) {
            throw new RuntimeException('invalid_id');
        }
        if (!$this->dbPronto()) {
            throw new RuntimeException('db_required_for_update');
        }
        $voce = $this->getById($entryId);
        if ($voce === null) {
            throw new RuntimeException('entry_not_found');
        }

        if ($forTeacher !== null && $forTeacher > 0) {
            $mod = [];
            if (isset($patch['label'])) {
                $lbl = trim((string)$patch['label']);
                if (strlen($lbl) > 120) {
                    throw new RuntimeException('invalid_label');
                }
                $mod['label_override'] = ($lbl === '' || $lbl === (string)$voce['label']) ? null : $lbl;
            }
            if (array_key_exists('active', $patch)) {
                // Il valore arriva come STRINGA "true"/"false" dal FormData:
                // (bool)"false" è true in PHP, filter_var no.
                $mod['active'] = filter_var($patch['active'], FILTER_VALIDATE_BOOLEAN);
                // ADR-041 — riaccendere una sezione che la scuola ha sospeso, o
                // che la modalità non ammette, no.
                if (
                    $mod['active'] && $voce['kind'] === 'classi' && !(new SezioniDeiDocenti())->ammessa(
                        $forTeacher,
                        (int)$voce['institute_id'],
                        (string)$voce['code'],
                        isset($voce['indirizzo']) ? (string)$voce['indirizzo'] : null
                    )
                ) {
                    throw new RuntimeException('sezione_non_ammessa');
                }
            }
            if (array_key_exists('shared_with_pool', $patch)) {
                $mod['shared_with_pool'] = filter_var($patch['shared_with_pool'], FILTER_VALIDATE_BOOLEAN);
            }
            $this->relazione()->aggiorna($entryId, $forTeacher, $mod);
            $mia = $this->getByIdForTeacher($entryId, $forTeacher);
            if ($mia === null) {
                throw new RuntimeException('entry_not_found');
            }
            return $mia;
        }

        $sets = [];
        $vals = [];
        if (isset($patch['label'])) {
            $lbl = trim((string)$patch['label']);
            if ($lbl === '' || strlen($lbl) > 120) {
                throw new RuntimeException('invalid_label');
            }
            $sets[] = 'label = ?';
            $vals[] = $lbl;
        }
        if (isset($patch['group'])) {
            $g = trim((string)$patch['group']);
            if (strlen($g) > 60) {
                throw new RuntimeException('invalid_group');
            }
            $sets[] = 'grp = ?';
            $vals[] = $g !== '' ? $g : null;
        }
        if (array_key_exists('active', $patch)) {
            $sets[] = 'active = ?';
            $vals[] = (int)filter_var($patch['active'], FILTER_VALIDATE_BOOLEAN);
        }
        if (isset($patch['origine'])) {
            $o = (string)$patch['origine'];
            if (!\in_array($o, self::ORIGINI, true)) {
                throw new RuntimeException('invalid_origine');
            }
            $sets[] = 'origine = ?';
            $vals[] = $o;
        }
        if ($sets !== []) {
            $vals[] = $entryId;
            Database::connection()
                ->prepare('UPDATE curriculum_entries SET ' . implode(', ', $sets) . ' WHERE id = ?')
                ->execute($vals);
        }
        $rec = $this->getById($entryId);
        if ($rec === null) {
            throw new RuntimeException('entry_not_found');
        }
        return $rec;
    }

    /**
     * Toglie.
     *
     *  - con `$forTeacher`: la spunta del docente. La voce della scuola resta,
     *    e i contenuti che ci puntano non perdono niente;
     *  - senza: la voce del vocabolario, con le spunte di tutti (cascade). I
     *    contenuti che ci puntavano restano senza categoria (FK SET NULL): chi
     *    chiama lo deve sapere — il pannello del catalogo lo dice prima.
     */
    public function removeById(int $entryId, ?int $forTeacher = null): bool
    {
        if ($entryId <= 0) {
            return false;
        }
        if (!$this->dbPronto()) {
            throw new RuntimeException('db_required_for_remove');
        }
        if ($forTeacher !== null && $forTeacher > 0) {
            return $this->relazione()->rimuovi($entryId, $forTeacher);
        }
        $stmt = Database::connection()->prepare('DELETE FROM curriculum_entries WHERE id = ?');
        $stmt->execute([$entryId]);
        return $stmt->rowCount() > 0;
    }

    /** Una voce del vocabolario, per id. */
    public function getById(int $entryId): ?array
    {
        if ($entryId <= 0 || !$this->dbPronto()) {
            return null;
        }
        $stmt = Database::connection()->prepare(self::SELECT_VOCABOLARIO . 'AND ce.id = ?');
        $stmt->execute([$entryId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ? $this->rowToShape($row, null) : null;
    }

    /** La stessa voce, come la vede un docente: con la sua spunta sopra. Null se non l'ha spuntata. */
    public function getByIdForTeacher(int $entryId, int $teacherId): ?array
    {
        $voce = $this->getById($entryId);
        if ($voce === null) {
            return null;
        }
        $rel = $this->relazione()->trova($entryId, $teacherId);
        if ($rel === null) {
            return null;
        }
        $voce['owner_user_id']    = $teacherId;
        $voce['label']            = $rel['label_override'] ?? $voce['label_istituto'];
        $voce['label_override']   = $rel['label_override'];
        $voce['active']           = $rel['active'];
        $voce['shared_with_pool'] = $rel['shared_with_pool'];
        return $voce;
    }

    /**
     * Le materie che i colleghi dello stesso istituto condividono nel pool.
     *
     * @return list<array{id:int, code:string, label:string, owner_user_id:int, owner_name:string}>
     */
    public function listSharedFromColleagues(int $instituteId, int $teacherId): array
    {
        if (!$this->dbPronto()) {
            return [];
        }
        return $this->relazione()->condiviseDaiColleghi($instituteId, $teacherId, 'materie');
    }

    /**
     * La riga di vocabolario (kind, istituto, codice); per un anno anche il
     * corso (ADR-042).
     *
     * @return array<string,mixed>|null
     */
    private function vocabolario(string $kind, int $instituteId, string $code, ?string $corsoDellAnno = null): ?array
    {
        $sql = 'SELECT id, label, indirizzo FROM curriculum_entries
                 WHERE kind = ? AND institute_id = ? AND code = ?';
        $args = [$kind, $instituteId, $code];
        if ($corsoDellAnno !== null) {
            $sql .= ' AND indirizzo = ?';
            $args[] = $corsoDellAnno;
        }
        $stmt = Database::connection()->prepare($sql . ' LIMIT 1');
        $stmt->execute($args);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    /**
     * La forma che le viste e le API conoscono. `owner_user_id` non è più il
     * proprietario della riga — la riga è della scuola — ma il docente per cui
     * la voce è stata letta, se c'è: i client che lo guardano per capire «è
     * mia?» continuano a funzionare.
     */
    private function rowToShape(array $row, ?int $teacherId): array
    {
        return [
            'id'               => (int)$row['id'],
            'kind'             => $row['kind'],
            'institute_id'     => $row['institute_id'] !== null ? (int)$row['institute_id'] : null,
            'owner_user_id'    => $teacherId,
            'code'             => $row['code'],
            'label'            => $row['label'],
            'label_istituto'   => (string)($row['label_istituto'] ?? $row['label']),
            'label_override'   => isset($row['label_override']) ? (string)$row['label_override'] : null,
            'group'            => $row['grp'],
            'indirizzo'        => isset($row['indirizzo']) ? (string)$row['indirizzo'] : null,
            'active'           => (bool)$row['active'],
            'shared_with_pool' => isset($row['shared_with_pool']) ? (bool)$row['shared_with_pool'] : false,
            'origine'          => (string)($row['origine'] ?? 'istituto'),
            'is_legacy'        => $row['institute_id'] === null,
            'institute_name'   => isset($row['institute_name']) ? (string)$row['institute_name'] : '',
            'institute_code'   => isset($row['institute_code']) ? (string)$row['institute_code'] : '',
        ];
    }

    private function assertKind(string $kind): void
    {
        if (!\in_array($kind, self::KINDS, true)) {
            throw new RuntimeException('invalid_kind');
        }
    }
}
