<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Repositories\InstituteRepository;
use PDO;
use RuntimeException;

/**
 * Deduplicazione/merge di istituti (boundary tenant).
 *
 * La stessa scuola fisica può esistere come più righe `institutes` con `code`
 * diversi (sintetico vs MIUR reale): docente e studenti finiscono su tenant
 * diversi e non condividono curriculum/contenuti. Questo servizio fonde i
 * duplicati ri-puntando OGNI foreign key institute_id verso la riga canonica.
 *
 * Robustezza schema-drift: lo schema di produzione diverge dal repo (alcune
 * tabelle/colonne possono mancare). Ogni operazione è guardata da un check
 * information_schema → salta silenziosamente ciò che non esiste.
 *
 * Tutto in UNA transazione: rollback su qualunque errore.
 */
final class InstituteMergeService
{
    /**
     * Riferimenti "soft" a institutes.id SENZA foreign key dichiarata (non
     * compaiono in information_schema.KEY_COLUMN_USAGE). [tabella, colonna].
     *
     * @var list<array{0:string,1:string}>
     */
    private const SOFT_FKS = [
        ['risdoc_templates', 'scope_institute_id'],
    ];

    public function __construct(private readonly ?PDO $pdo = null)
    {
    }

    private function db(): PDO
    {
        return $this->pdo ?? Database::connection();
    }

    /**
     * Fonde $duplicateId dentro $canonicalId. Opzionalmente imposta il `code`
     * canonico (es. upgrade a MIUR reale del gruppo).
     *
     * @return array{moved: array<string,int>, canonical: int, duplicate: int, code_set: ?string}
     */
    public function merge(int $canonicalId, int $duplicateId, ?string $adoptCode = null): array
    {
        if ($canonicalId <= 0 || $duplicateId <= 0 || $canonicalId === $duplicateId) {
            throw new RuntimeException('merge_invalid_ids');
        }
        $pdo = $this->db();
        $repo = new InstituteRepository();
        if (!$repo->findById($canonicalId)) {
            throw new RuntimeException('merge_canonical_not_found');
        }
        if (!$repo->findById($duplicateId)) {
            throw new RuntimeException('merge_duplicate_not_found');
        }

        $moved = [];
        $ownTx = !$pdo->inTransaction();
        if ($ownTx) {
            $pdo->beginTransaction();
        }
        try {
            // ── Tabelle con UNIQUE che include institute_id: prima elimino le
            //    righe del duplicato che collidono con il canonico, poi UPDATE. ──

            // curriculum_entries — UNIQUE(kind, code, institute_id, owner_key=COALESCE(owner_user_id,0)).
            // LOSSLESS: le righe duplicate che collidono con l'equivalente canonico
            // NON vanno eliminate alla cieca (le FK verso curriculum_entries.id sono
            // SET NULL → scollegherebbero i contenuti del docente). Per ogni riga
            // collidente ri-mappo i referrer dup.id→canonico.id, poi elimino. Le
            // righe non collidenti vengono semplicemente ri-puntate all'istituto.
            if ($this->hasColumn('curriculum_entries', 'institute_id')) {
                $moved['curriculum_entries'] = $this->mergeCurriculum($canonicalId, $duplicateId);
            }

            // risdoc_curriculum_data — UNIQUE(institute_id, dataset, indirizzo, classe, materia)
            if ($this->hasColumn('risdoc_curriculum_data', 'institute_id')) {
                $this->run(
                    'DELETE d FROM risdoc_curriculum_data d
                       JOIN risdoc_curriculum_data c
                         ON c.dataset = d.dataset AND c.indirizzo = d.indirizzo
                        AND c.classe = d.classe AND c.materia = d.materia
                        AND c.institute_id = ?
                      WHERE d.institute_id = ?',
                    [$canonicalId, $duplicateId]
                );
                $moved['risdoc_curriculum_data'] = $this->run(
                    'UPDATE risdoc_curriculum_data SET institute_id = ? WHERE institute_id = ?',
                    [$canonicalId, $duplicateId]
                );
            }

            // teacher_institutes — PK(user_id, institute_id)
            if ($this->hasColumn('teacher_institutes', 'institute_id')) {
                $this->run(
                    'DELETE d FROM teacher_institutes d
                       JOIN teacher_institutes c
                         ON c.user_id = d.user_id AND c.institute_id = ?
                      WHERE d.institute_id = ?',
                    [$canonicalId, $duplicateId]
                );
                $moved['teacher_institutes'] = $this->run(
                    'UPDATE teacher_institutes SET institute_id = ? WHERE institute_id = ?',
                    [$canonicalId, $duplicateId]
                );
            }

            // institute_pool_policy — PK institute_id (1:1)
            if ($this->hasColumn('institute_pool_policy', 'institute_id')) {
                // sposta la policy del duplicato solo se il canonico non ne ha già una
                $this->run(
                    'UPDATE IGNORE institute_pool_policy SET institute_id = ? WHERE institute_id = ?',
                    [$canonicalId, $duplicateId]
                );
                $this->run('DELETE FROM institute_pool_policy WHERE institute_id = ?', [$duplicateId]);
            }

            // ── FK semplici verso institutes (UPDATE diretto) ──
            // Scoperte dinamicamente: tutte le FK → institutes.id MENO le tabelle
            // gestite sopra (UNIQUE/PK con dedup). Cattura anche i nomi *_data e
            // regge lo schema-drift prod.
            $special = ['curriculum_entries', 'risdoc_curriculum_data', 'teacher_institutes', 'institute_pool_policy'];
            foreach ($this->referrersTo('institutes') as [$table, $col]) {
                if (in_array($table, $special, true)) {
                    continue;
                }
                $moved[$table . '.' . $col] = $this->run(
                    "UPDATE {$table} SET {$col} = ? WHERE {$col} = ?",
                    [$canonicalId, $duplicateId]
                );
            }
            // Soft-reference senza FK (non compaiono in referrersTo): guardate.
            foreach (self::SOFT_FKS as [$table, $col]) {
                if (!$this->hasColumn($table, $col)) {
                    continue;
                }
                $moved[$table . '.' . $col] = $this->run(
                    "UPDATE {$table} SET {$col} = ? WHERE {$col} = ?",
                    [$canonicalId, $duplicateId]
                );
            }

            // ── Adotta il code canonico (es. upgrade a MIUR reale) ──
            $codeSet = null;
            if ($adoptCode !== null && $adoptCode !== '') {
                $cur = $repo->findById($canonicalId);
                if ($cur && (string)$cur['code'] !== $adoptCode) {
                    // libera il code dal duplicato (sta per essere eliminato) e
                    // assegnalo al canonico (UNIQUE su institutes.code).
                    $this->run(
                        'UPDATE institutes SET code = CONCAT(code, ?) WHERE id = ?',
                        ['_merged_' . $duplicateId, $duplicateId]
                    );
                    $this->run('UPDATE institutes SET code = ? WHERE id = ?', [$adoptCode, $canonicalId]);
                    $codeSet = $adoptCode;
                }
            }

            // ── Elimina la riga duplicata ──
            $this->run('DELETE FROM institutes WHERE id = ?', [$duplicateId]);

            if ($ownTx) {
                $pdo->commit();
            }
        } catch (\Throwable $e) {
            if ($ownTx && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw new RuntimeException('merge_failed: ' . $e->getMessage(), 0, $e);
        }

        return [
            'moved'     => $moved,
            'canonical' => $canonicalId,
            'duplicate' => $duplicateId,
            'code_set'  => $codeSet,
        ];
    }

    /**
     * Pianifica i gruppi di duplicati (stessa dedupKey). Per ogni gruppo con
     * >1 riga sceglie il canonico (più "peso" = curriculum + teacher_institutes
     * + users) e il code da adottare (l'unico MIUR reale del gruppo, se c'è).
     *
     * @return list<array{key:string, canonical:array, duplicates:list<array>, adopt_code:?string, real_codes:list<string>, safe:bool}>
     */
    public function planGroups(): array
    {
        $pdo = $this->db();
        $rows = $pdo->query('SELECT id, code, name, city FROM institutes')->fetchAll(PDO::FETCH_ASSOC);

        $groups = [];
        foreach ($rows as $r) {
            $k = InstituteRepository::dedupKey((string)$r['name'], $r['city'] ?? null);
            $r['weight'] = $this->weight((int)$r['id']);
            $groups[$k][] = $r;
        }

        $plan = [];
        foreach ($groups as $k => $members) {
            if (count($members) < 2) {
                continue;
            }
            // canonico = peso massimo, tiebreak id minore
            usort($members, fn($a, $b) => ($b['weight'] <=> $a['weight']) ?: ($a['id'] <=> $b['id']));
            $canonical = $members[0];
            $duplicates = array_slice($members, 1);

            $realCodes = [];
            foreach ($members as $m) {
                if (InstituteRepository::isRealMiurCode((string)$m['code'])) {
                    $realCodes[] = (string)$m['code'];
                }
            }
            $realCodes = array_values(array_unique($realCodes));
            // adotta il code MIUR reale solo se ce n'è ESATTAMENTE uno nel gruppo
            $adoptCode = count($realCodes) === 1 ? $realCodes[0] : null;
            // safe = al più un code reale (più reali distinti = possibili plessi → non-safe)
            $safe = count($realCodes) <= 1;

            $plan[] = [
                'key'        => $k,
                'canonical'  => $canonical,
                'duplicates' => $duplicates,
                'adopt_code' => $adoptCode,
                'real_codes' => $realCodes,
                'safe'       => $safe,
            ];
        }
        return $plan;
    }

    /**
     * Merge LOSSLESS di curriculum_entries da $dup a $canonical.
     * Ritorna il numero di righe ri-puntate (escluse quelle deduplicate via
     * re-mapping dei referrer). Vedi merge() per il razionale.
     */
    private function mergeCurriculum(int $canonical, int $dup): int
    {
        $pdo = $this->db();
        // Mappa delle righe canoniche per chiave logica (kind|code|owner_key).
        $canon = [];
        $stmt = $pdo->prepare(
            'SELECT id, kind, code, COALESCE(owner_user_id,0) ok FROM curriculum_entries WHERE institute_id = ?'
        );
        $stmt->execute([$canonical]);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $canon[$r['kind'] . '|' . $r['code'] . '|' . $r['ok']] = (int)$r['id'];
        }

        $referrers = $this->referrersTo('curriculum_entries');

        $stmt = $pdo->prepare(
            'SELECT id, kind, code, COALESCE(owner_user_id,0) ok FROM curriculum_entries WHERE institute_id = ?'
        );
        $stmt->execute([$dup]);
        $dupRows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $moved = 0;
        foreach ($dupRows as $r) {
            $key = $r['kind'] . '|' . $r['code'] . '|' . $r['ok'];
            $dupRowId = (int)$r['id'];
            if (isset($canon[$key])) {
                // collisione: ri-mappa i referrer al gemello canonico, poi elimina.
                $canonRowId = $canon[$key];
                foreach ($referrers as [$t, $c]) {
                    $this->run("UPDATE {$t} SET {$c} = ? WHERE {$c} = ?", [$canonRowId, $dupRowId]);
                }
                $this->run('DELETE FROM curriculum_entries WHERE id = ?', [$dupRowId]);
            } else {
                // nessuna collisione: ri-punta all'istituto canonico e registra
                // la chiave (così eventuali duplicati interni al dup deduplicano).
                $this->run('UPDATE curriculum_entries SET institute_id = ? WHERE id = ?', [$canonical, $dupRowId]);
                $canon[$key] = $dupRowId;
                $moved++;
            }
        }
        return $moved;
    }

    /**
     * Coppie (tabella, colonna) che hanno una FK verso $table.id, scoperte
     * dinamicamente da information_schema (robusto allo schema-drift).
     *
     * @return list<array{0:string,1:string}>
     */
    private function referrersTo(string $table): array
    {
        $stmt = $this->db()->prepare(
            'SELECT TABLE_NAME, COLUMN_NAME FROM information_schema.KEY_COLUMN_USAGE
              WHERE TABLE_SCHEMA = DATABASE() AND REFERENCED_TABLE_NAME = ?'
        );
        $stmt->execute([$table]);
        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_NUM) as $row) {
            $out[] = [(string)$row[0], (string)$row[1]];
        }
        return $out;
    }

    /** Peso di un istituto = righe figlie chiave (per scegliere il canonico). */
    private function weight(int $instituteId): int
    {
        $total = 0;
        foreach (
            [
            ['curriculum_entries', 'institute_id'],
            ['teacher_institutes', 'institute_id'],
            ['users',             'institute_id'],
            ] as [$t, $c]
        ) {
            if (!$this->hasColumn($t, $c)) {
                continue;
            }
            $stmt = $this->db()->prepare("SELECT COUNT(1) FROM {$t} WHERE {$c} = ?");
            $stmt->execute([$instituteId]);
            $total += (int)$stmt->fetchColumn();
        }
        return $total;
    }

    /** Esegue uno statement e ritorna le righe affette. */
    private function run(string $sql, array $args): int
    {
        $stmt = $this->db()->prepare($sql);
        $stmt->execute($args);
        return $stmt->rowCount();
    }

    /** @var array<string,bool> cache per-process */
    private array $colCache = [];

    /** True se la tabella esiste e ha la colonna (schema-drift safe). */
    private function hasColumn(string $table, string $column): bool
    {
        $key = $table . '.' . $column;
        if (isset($this->colCache[$key])) {
            return $this->colCache[$key];
        }
        $stmt = $this->db()->prepare(
            'SELECT COUNT(1) FROM information_schema.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?'
        );
        $stmt->execute([$table, $column]);
        return $this->colCache[$key] = ((int)$stmt->fetchColumn() > 0);
    }
}
