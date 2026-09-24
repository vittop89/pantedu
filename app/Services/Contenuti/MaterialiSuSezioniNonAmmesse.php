<?php

declare(strict_types=1);

namespace App\Services\Contenuti;

use App\Core\Database;
use App\Domain\ClassCode;
use App\Services\SezioniDeiDocenti;
use InvalidArgumentException;
use PDO;

/**
 * I materiali di un docente rimasti su sezioni che non può più usare
 * (ADR-041, 14/9/2026).
 *
 * PERCHÉ
 *   Togliere un incarico, o stringere la modalità delle sezioni, sospende la
 *   spunta del docente su quella sezione ma non tocca i suoi materiali: restano
 *   lì, e dai menù non li raggiunge più. Misurato il 14/9/2026: la sezione
 *   spariva anche da «Sposta di classe», che rifiutava lo spostamento
 *   (`classe_non_spuntata`), e l'unica strada era ridare l'incarico.
 *
 * CHE COSA SONO «I MATERIALI SU UNA SEZIONE»
 *   Le pubblicazioni del docente su quella classe: la principale di un
 *   contenuto o di una verifica (`origine = 'riga'`), e i posti in più scelti
 *   da «Dove vale» (`origine = 'docente'`). In produzione, il 14/9/2026, le 251
 *   pubblicazioni su sezione erano tutte posti in più, con la principale
 *   sull'anno. I bersagli della pubblicazione «per più classi»
 *   (`origine = 'bersaglio'`) non si spostano da qui, perché vengono dalle
 *   classi scelte nel contenuto: si contano, e si tolgono dal contenuto.
 *
 * TRE STRADE, NESSUNA AUTOMATICA (scelta dell'utente, 14/9/2026)
 *   - il docente li sposta da «Sposta di classe», dove la sezione compare come
 *     classe di partenza (SpostamentoDiClasse::classiDiPartenza);
 *   - l'amministratore li porta sull'anno da «Sezioni e incarichi», docente per
 *     docente e sezione per sezione (portaSullAnno), con il motivo a registro;
 *   - l'area docente avvisa quanti sono, con la data se l'amministratore ne ha
 *     messa una (`institutes.sezioni_scadenza`, migrazione 127).
 *   Uno spostamento automatico renderebbe definitivo anche un incarico tolto per
 *   sbaglio: ridandolo, i materiali non tornerebbero da soli.
 */
final class MaterialiSuSezioniNonAmmesse
{
    public function __construct(private ?PDO $pdo = null, private ?SezioniDeiDocenti $sezioni = null)
    {
    }

    private function db(): PDO
    {
        return $this->pdo ?? Database::connection();
    }

    private function sezioni(): SezioniDeiDocenti
    {
        return $this->sezioni ??= new SezioniDeiDocenti($this->pdo);
    }

    /**
     * Per docente e classe: quanti contenuti e verifiche ha lì, e quanti
     * posti. Solo le classi che quel docente non può usare: sezioni e, da
     * ADR-043, anni di un corso senza incarico.
     *
     * @return list<array{docente:int,id:int,code:string,label:string,indirizzo:?string,
     *                    institute_id:int,istituto:string,contenuti:int,verifiche:int,posti:int,bersagli:int}>
     */
    public function righe(?int $docente = null, ?int $istituto = null): array
    {
        $filtri = '';
        $args = [];
        if ($docente !== null) {
            $filtri .= ' AND COALESCE(d.teacher_id, v.teacher_id) = ?';
            $args[] = $docente;
        }
        if ($istituto !== null) {
            $filtri .= ' AND ce.institute_id = ?';
            $args[] = $istituto;
        }
        $st = $this->db()->prepare(
            "SELECT COALESCE(d.teacher_id, v.teacher_id) AS docente,
                    ce.id, ce.code, ce.label, ce.indirizzo, ce.institute_id,
                    COALESCE(i.name, i.code) AS istituto,
                    COUNT(DISTINCT CASE WHEN p.origine <> 'bersaglio' THEN p.teacher_content_id END)   AS contenuti,
                    COUNT(DISTINCT CASE WHEN p.origine <> 'bersaglio' THEN p.verifica_document_id END) AS verifiche,
                    SUM(p.origine <> 'bersaglio') AS posti,
                    SUM(p.origine = 'bersaglio')  AS bersagli
               FROM content_publications p
               JOIN curriculum_entries ce ON ce.id = p.classe_id
               JOIN institutes i          ON i.id = ce.institute_id
               LEFT JOIN teacher_content_data d    ON d.id = p.teacher_content_id
               LEFT JOIN verifica_documents_data v ON v.id = p.verifica_document_id
              WHERE ce.kind = 'classi' AND ce.code REGEXP '^[1-9][A-Za-z0-9]*$'
                AND COALESCE(d.teacher_id, v.teacher_id) IS NOT NULL{$filtri}
              GROUP BY docente, ce.id, ce.code, ce.label, ce.indirizzo, ce.institute_id, istituto
              ORDER BY istituto, ce.code, docente"
        );
        $st->execute($args);
        $out = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $chi = (int)$r['docente'];
            $indirizzo = $r['indirizzo'] !== null ? (string)$r['indirizzo'] : null;
            if ($this->sezioni()->ammessa($chi, (int)$r['institute_id'], (string)$r['code'], $indirizzo)) {
                continue;
            }
            $out[] = [
                'docente'      => $chi,
                'id'           => (int)$r['id'],
                'code'         => (string)$r['code'],
                'label'        => (string)$r['label'],
                'indirizzo'    => $indirizzo,
                'institute_id' => (int)$r['institute_id'],
                'istituto'     => (string)$r['istituto'],
                'contenuti'    => (int)$r['contenuti'],
                'verifiche'    => (int)$r['verifiche'],
                'posti'        => (int)$r['posti'],
                'bersagli'     => (int)$r['bersagli'],
            ];
        }
        return $out;
    }

    /**
     * Che cosa ha un docente su alcune sezioni, prima di togliergliene
     * l'incarico: l'avviso all'amministratore e l'email al docente (15/9/2026).
     *
     * A differenza di righe() non guarda se la sezione è ammessa: serve proprio
     * mentre lo è ancora. Una sezione è il codice nell'indirizzo del modulo; con
     * l'indirizzo, 1A di un altro corso non conta.
     *
     * - `tipi`: contenuti distinti per tipo (`content_subtype`), fra principale
     *   e posti in più;
     * - `verifiche`: verifiche distinte;
     * - `posti`: di quelle pubblicazioni, quante sono posti in più;
     * - `bersagli`: pubblicazioni «per più classi», contate a parte;
     * - `credenziali`: credenziali di classe attive del docente su quella sezione,
     *   che continuano a vedere i materiali;
     * - `studenti`: studenti con account in quella sezione (solo nello scenario
     *   Istituto), che senza l'incarico non vedono più i contenuti del docente.
     *
     * @param list<string> $codici
     * @return list<array{code:string,tipi:array<string,int>,verifiche:int,posti:int,bersagli:int,credenziali:int,studenti:int}>
     */
    public function sulleSezioni(int $docente, int $istituto, ?string $indirizzo, array $codici): array
    {
        $codici = array_values(array_unique(array_filter(array_map(
            static fn($c): string => strtoupper(trim((string)$c)),
            $codici
        ), static fn(string $c): bool => $c !== '')));
        if ($codici === []) {
            return [];
        }
        $indirizzo = $indirizzo !== null && trim($indirizzo) !== '' ? strtoupper(trim($indirizzo)) : null;
        $segnaposti = implode(',', array_fill(0, \count($codici), '?'));
        $voce = "ce.institute_id = ? AND ce.kind = 'classi' AND UPPER(ce.code) IN ($segnaposti)"
              . ($indirizzo !== null ? ' AND (ce.indirizzo IS NULL OR UPPER(ce.indirizzo) = ?)' : '');
        $argVoce = array_merge([$istituto], $codici, $indirizzo !== null ? [$indirizzo] : []);

        $out = [];
        foreach ($codici as $c) {
            $out[$c] = ['code' => $c, 'tipi' => [], 'verifiche' => 0, 'posti' => 0, 'bersagli' => 0, 'credenziali' => 0, 'studenti' => 0];
        }
        $db = $this->db();

        $st = $db->prepare(
            "SELECT UPPER(ce.code) AS code, d.content_subtype AS tipo, COUNT(DISTINCT p.teacher_content_id) AS n
               FROM content_publications p
               JOIN curriculum_entries ce       ON ce.id = p.classe_id
               JOIN teacher_content_data d      ON d.id = p.teacher_content_id
              WHERE d.teacher_id = ? AND p.origine <> 'bersaglio' AND $voce
              GROUP BY UPPER(ce.code), d.content_subtype
              ORDER BY d.content_subtype"
        );
        $st->execute(array_merge([$docente], $argVoce));
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $out[(string)$r['code']]['tipi'][(string)$r['tipo']] = (int)$r['n'];
        }

        $st = $db->prepare(
            "SELECT UPPER(ce.code) AS code,
                    COUNT(DISTINCT CASE WHEN p.origine <> 'bersaglio' THEN p.verifica_document_id END) AS verifiche,
                    SUM(p.origine = 'docente')   AS posti,
                    SUM(p.origine = 'bersaglio') AS bersagli
               FROM content_publications p
               JOIN curriculum_entries ce          ON ce.id = p.classe_id
               LEFT JOIN teacher_content_data d    ON d.id = p.teacher_content_id
               LEFT JOIN verifica_documents_data v ON v.id = p.verifica_document_id
              WHERE COALESCE(d.teacher_id, v.teacher_id) = ? AND $voce
              GROUP BY UPPER(ce.code)"
        );
        $st->execute(array_merge([$docente], $argVoce));
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $c = (string)$r['code'];
            $out[$c]['verifiche'] = (int)$r['verifiche'];
            $out[$c]['posti']     = (int)$r['posti'];
            $out[$c]['bersagli']  = (int)$r['bersagli'];
        }

        $filtroInd = $indirizzo !== null ? ' AND (indirizzo IS NULL OR UPPER(indirizzo) = ?)' : '';
        $credenziali = $db->prepare(
            "SELECT COUNT(*) FROM teacher_access_credentials
              WHERE teacher_id = ? AND institute_id = ? AND UPPER(classe) = ? AND active = 1$filtroInd"
        );
        $studenti = $db->prepare(
            "SELECT COUNT(*) FROM users
              WHERE role = 'student' AND deleted_at IS NULL AND institute_id = ? AND UPPER(classe) = ?$filtroInd"
        );
        foreach ($codici as $c) {
            $credenziali->execute(array_merge([$docente, $istituto, $c], $indirizzo !== null ? [$indirizzo] : []));
            $out[$c]['credenziali'] = (int)$credenziali->fetchColumn();
            $studenti->execute(array_merge([$istituto, $c], $indirizzo !== null ? [$indirizzo] : []));
            $out[$c]['studenti'] = (int)$studenti->fetchColumn();
        }
        return array_values($out);
    }

    /**
     * Per il pannello dell'amministratore: le righe dell'istituto con il nome
     * del docente, le sue credenziali ancora su quella sezione e l'anno dove
     * andrebbero i materiali (null se il catalogo non ce l'ha).
     *
     * @return list<array<string,mixed>>
     */
    public function perIstituto(int $istituto): array
    {
        $righe = $this->righe(null, $istituto);
        if ($righe === []) {
            return [];
        }
        $db = $this->db();
        $nome = $db->prepare(
            'SELECT username, COALESCE(NULLIF(TRIM(CONCAT_WS(" ", first_name, last_name)), ""), username) AS nome
               FROM users WHERE id = ?'
        );
        $credenziali = $db->prepare(
            'SELECT COUNT(*) FROM teacher_access_credentials
              WHERE teacher_id = ? AND institute_id = ? AND UPPER(classe) = UPPER(?) AND active = 1'
        );
        foreach ($righe as &$r) {
            $nome->execute([$r['docente']]);
            $u = $nome->fetch(PDO::FETCH_ASSOC) ?: ['username' => '', 'nome' => '#' . $r['docente']];
            $credenziali->execute([$r['docente'], $istituto, $r['code']]);
            // Un anno non si porta sull'anno: lo sposta il docente, o gli si ridà l'incarico.
            $eAnno = ClassCode::isAnno($r['code']);
            $anno = $eAnno ? null : $this->anno($istituto, $r['code'], $r['indirizzo']);
            $r += [
                'nome'        => (string)$u['nome'],
                'username'    => (string)$u['username'],
                'credenziali' => (int)$credenziali->fetchColumn(),
                'e_anno'      => $eAnno,
                'anno_id'     => $anno['id'] ?? null,
                'anno_ammesso' => !$eAnno && $anno !== null
                    && $this->sezioni()->ammessa((int)$r['docente'], $istituto, (string)ClassCode::anno($r['code']), $r['indirizzo']),
                'anno'        => ClassCode::anno($r['code']),
            ];
        }
        unset($r);
        return $righe;
    }

    /**
     * Quello che l'area docente dice al docente: per istituto, le sezioni con i
     * suoi materiali da spostare e la data, se c'è.
     *
     * @return list<array{institute_id:int,istituto:string,scadenza:?string,
     *                    sezioni:list<array{id:int,code:string,materiali:int}>}>
     */
    public function avviso(int $docente): array
    {
        $per = [];
        foreach ($this->righe($docente) as $r) {
            $k = $r['institute_id'];
            $per[$k] ??= ['institute_id' => $k, 'istituto' => $r['istituto'], 'scadenza' => $this->scadenza($k), 'sezioni' => []];
            $per[$k]['sezioni'][] = ['id' => $r['id'], 'code' => $r['code'], 'materiali' => $r['contenuti'] + $r['verifiche']];
        }
        return array_values($per);
    }

    /**
     * L'amministratore porta sull'anno tutti i materiali di un docente su una
     * sezione che non può usare: «2A» → «2». L'anno diventa una spunta attiva
     * del docente, se non lo era, perché i materiali restino nei suoi menù.
     *
     * ADR-042 — l'anno è quello del corso della sezione, e un anno si vede
     * sotto il suo indirizzo: se il docente non l'ha acceso, si accende anche
     * quello (`corso_spuntato`), altrimenti i materiali sparirebbero dai menù.
     *
     * @return array<string,mixed> l'esito di SpostamentoDiClasse::sposta, con `anno_spuntato` e `corso_spuntato`
     * ADR-043 — l'anno deve essere una classe che il docente può usare: portare i
     * materiali su un anno senza incarico li lascerebbe di nuovo fuori dai suoi
     * menù (`anno_non_ammesso`). L'incarico lo dà l'amministratore, prima.
     *
     * @throws InvalidArgumentException sezione_non_valida · sezione_ammessa · anno_mancante:<anno> · anno_non_ammesso:<anno> · niente_da_spostare
     */
    public function portaSullAnno(int $istituto, int $docente, int $sezione): array
    {
        $db = $this->db();
        $st = $db->prepare(
            "SELECT id, code, indirizzo FROM curriculum_entries
              WHERE id = ? AND institute_id = ? AND kind = 'classi' LIMIT 1"
        );
        $st->execute([$sezione, $istituto]);
        $voce = $st->fetch(PDO::FETCH_ASSOC);
        if ($voce === false || !ClassCode::isSezione((string)$voce['code'])) {
            throw new InvalidArgumentException('sezione_non_valida');
        }
        $indirizzo = $voce['indirizzo'] !== null ? (string)$voce['indirizzo'] : null;
        if ($this->sezioni()->ammessa($docente, $istituto, (string)$voce['code'], $indirizzo)) {
            throw new InvalidArgumentException('sezione_ammessa');
        }
        $anno = $this->anno($istituto, (string)$voce['code'], $indirizzo);
        if ($anno === null) {
            throw new InvalidArgumentException('anno_mancante:' . (string)ClassCode::anno((string)$voce['code']));
        }
        if (!$this->sezioni()->ammessa($docente, $istituto, (string)ClassCode::anno((string)$voce['code']), $indirizzo)) {
            throw new InvalidArgumentException('anno_non_ammesso:' . (string)ClassCode::anno((string)$voce['code']));
        }

        $contenuti = $this->documenti('teacher_content_id', 'teacher_content_data', $docente, $sezione);
        $verifiche = $this->documenti('verifica_document_id', 'verifica_documents_data', $docente, $sezione);
        if ($contenuti === [] && $verifiche === []) {
            throw new InvalidArgumentException('niente_da_spostare');
        }

        $inTx = !$db->inTransaction();
        if ($inTx) {
            $db->beginTransaction();
        }
        try {
            $spuntato = $this->accendi($db, $anno['id'], $docente);
            $corso = $db->prepare(
                "SELECT id FROM curriculum_entries
                  WHERE institute_id = ? AND kind = 'indirizzi' AND code = ? AND active = 1
                  ORDER BY id LIMIT 1"
            );
            $corso->execute([$istituto, (string)$indirizzo]);
            $corsoId = $corso->fetchColumn();
            $corsoSpuntato = $corsoId !== false && $this->accendi($db, (int)$corsoId, $docente);
            $esito = (new SpostamentoDiClasse($db, $this))->sposta($docente, $contenuti, $verifiche, $sezione, $anno['id']);
            if ($inTx) {
                $db->commit();
            }
        } catch (\Throwable $e) {
            if ($inTx && $db->inTransaction()) {
                $db->rollBack();
            }
            throw $e;
        }
        return $esito + ['anno_spuntato' => $spuntato, 'corso_spuntato' => $corsoSpuntato];
    }

    /** Accende la spunta del docente su una voce; true se prima non era accesa. */
    private function accendi(PDO $db, int $voce, int $docente): bool
    {
        $st = $db->prepare('SELECT active FROM curriculum_teacher WHERE curriculum_id = ? AND user_id = ? LIMIT 1');
        $st->execute([$voce, $docente]);
        $prima = $st->fetchColumn();
        if ($prima !== false && (int)$prima === 1) {
            return false;
        }
        $db->prepare(
            'INSERT INTO curriculum_teacher (curriculum_id, user_id, active) VALUES (?, ?, 1)
             ON DUPLICATE KEY UPDATE active = 1, sospesa_dalla_scuola = 0'
        )->execute([$voce, $docente]);
        return true;
    }

    /** La data dell'avviso, `AAAA-MM-GG`, o null. */
    public function scadenza(int $istituto): ?string
    {
        try {
            $st = $this->db()->prepare('SELECT sezioni_scadenza FROM institutes WHERE id = ? LIMIT 1');
            $st->execute([$istituto]);
            $d = $st->fetchColumn();
            return \is_string($d) && $d !== '' ? substr($d, 0, 10) : null;
        } catch (\PDOException) {
            // Migrazione 127 non ancora applicata: nessuna data.
            return null;
        }
    }

    /**
     * @param string|null $data `AAAA-MM-GG`, o null/vuota per toglierla
     * @throws InvalidArgumentException data_non_valida
     */
    public function impostaScadenza(int $istituto, ?string $data): void
    {
        $data = $data !== null ? trim($data) : '';
        if ($data !== '') {
            $d = \DateTimeImmutable::createFromFormat('!Y-m-d', $data);
            if ($d === false || $d->format('Y-m-d') !== $data) {
                throw new InvalidArgumentException('data_non_valida');
            }
        }
        $this->db()->prepare('UPDATE institutes SET sezioni_scadenza = ? WHERE id = ?')
            ->execute([$data !== '' ? $data : null, $istituto]);
    }

    /**
     * La voce dell'anno di quella sezione, attiva, nell'istituto: l'anno del
     * corso della sezione (ADR-042). Una sezione senza corso non ha un anno a
     * cui tornare.
     *
     * @return array{id:int}|null
     */
    private function anno(int $istituto, string $sezione, ?string $indirizzo): ?array
    {
        $anno = ClassCode::anno($sezione);
        if ($anno === null || $indirizzo === null || $indirizzo === '') {
            return null;
        }
        $st = $this->db()->prepare(
            "SELECT id FROM curriculum_entries
              WHERE institute_id = ? AND kind = 'classi' AND code = ? AND indirizzo = ? AND active = 1
              ORDER BY id LIMIT 1"
        );
        $st->execute([$istituto, $anno, $indirizzo]);
        $id = $st->fetchColumn();
        return $id !== false ? ['id' => (int)$id] : null;
    }

    /**
     * I documenti del docente con una pubblicazione (principale o posto in più)
     * su quella classe.
     *
     * @param 'teacher_content_id'|'verifica_document_id' $colonna
     * @param 'teacher_content_data'|'verifica_documents_data' $tabella
     * @return list<int>
     */
    private function documenti(string $colonna, string $tabella, int $docente, int $classe): array
    {
        $st = $this->db()->prepare(
            "SELECT DISTINCT p.$colonna FROM content_publications p
               JOIN `$tabella` t ON t.id = p.$colonna
              WHERE t.teacher_id = ? AND p.classe_id = ? AND p.origine IN ('riga', 'docente')
              ORDER BY p.$colonna"
        );
        $st->execute([$docente, $classe]);
        return array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
    }
}
