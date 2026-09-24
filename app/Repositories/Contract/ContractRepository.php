<?php

namespace App\Repositories\Contract;

use App\Services\Contract\ContractAggregate;
use App\Services\Contract\ContractItemNotFoundException;
use App\Services\Contract\ContractNotFoundException;
use App\Services\Contract\ContractVersionMismatchException;
use App\Repositories\TeacherContentRepository;
use App\Support\Storage\StorageFactory;
use App\Support\Storage\StorageProvider;

/**
 * Phase 16 — Repository centralizzato per il contract JSON associato a una
 * riga di `teacher_content` (via `metadata_json.contract_key`).
 *
 * Prima di questo servizio le letture del contract erano sparse tra
 * `TeacherContentController::contract`, `ContentStudyController::relatedVerificaHtml`
 * e `ContentStudyController::renderTopicHtml`, ciascuna con il proprio
 * try/catch + fallback. Ora tutti passano da qui.
 *
 * Scope (Phase 16 — Step 1):
 *   - load(int $contentId): carica aggregate (null se non esiste)
 *   - loadForTeacher(int $contentId, int $teacherId): + ACL ownership
 *   - save(ContractAggregate): riscrive JSON in storage (bump version se
 *     richiesto da caller — versioning/optimistic locking è Step 2)
 *   - patchItem / deleteItem / moveItem: wrapper che fanno load+mutate+save
 *     in una singola chiamata (atomica a livello di storage put, NON
 *     transazionale a livello DB — sufficiente finché gli item non hanno
 *     righe DB dedicate).
 *
 * Versioning (Step 2):
 *   - save() accetta `expectedVersion` opzionale: se non null, legge il
 *     contract corrente da storage e confronta `version`. Mismatch →
 *     ContractVersionMismatchException (HTTP 409).
 *   - bumpVersion() è responsabilità del caller (save non la chiama
 *     automaticamente: alcuni patch minori potrebbero non meritarla).
 */
final class ContractRepository
{
    public const SCHEMA_ID        = 'pantedu.content.v1';
    public const LEGACY_SCHEMA_ID = 'progetto-precedente.content.v1';

    public function __construct(
        private TeacherContentRepository $contentRepo,
        private StorageProvider $storage,
        private ?ContentVersionRepository $versions = null,
    ) {
    }

    /** Factory default: usa il repository standard + storage provider globale. */
    public static function default(): self
    {
        // ContentVersionRepository è opzionale: se DB assente, viene null e
        // l'archivio saltato (non blocca le write). In prod è sempre attivo.
        $versions = null;
        try {
            $versions = new ContentVersionRepository();
        } catch (\Throwable) {
        }
        return new self(new TeacherContentRepository(), StorageFactory::default(), $versions);
    }

    /**
     * Carica l'aggregate per un `teacher_content.id`. Ritorna null se:
     *   - la riga non esiste
     *   - non ha `contract_key` in metadata_json
     *   - il file storage non esiste / è JSON invalido
     */
    public function load(int $contentId): ?ContractAggregate
    {
        $row = $this->contentRepo->find($contentId);
        if (!$row) {
            return null;
        }
        $meta = json_decode((string)($row['metadata_json'] ?? '{}'), true) ?: [];
        $ckey = (string)($meta['contract_key'] ?? '');
        if ($ckey === '') {
            return null;
        }
        try {
            $bytes = $this->storage->get($ckey);
        } catch (\Throwable) {
            return null;
        }
        $data = json_decode($bytes, true);
        if (!is_array($data)) {
            return null;
        }
        // 2026-09-06 — i contratti importati prima della rinomina portano ancora
        // `$schema: progetto-precedente.content.v1` (in locale 206 file su 382): lo schema JSON
        // vuole `pantedu.content.v1` e il validatore loggava un errore a ogni
        // caricamento. Alias al confine: il salvataggio riscrive il nome nuovo.
        if (($data['$schema'] ?? null) === self::LEGACY_SCHEMA_ID) {
            $data['$schema'] = self::SCHEMA_ID;
        }
        return new ContractAggregate($contentId, $ckey, $data, $row);
    }

    /**
     * Come `load()` ma rifiuta se la riga DB non è posseduta dal teacher.
     * Ritorna null per NOT FOUND e per OWNERSHIP FAIL (il controller può
     * distinguere controllando separatamente se la riga esiste).
     */
    public function loadForTeacher(int $contentId, int $teacherId): ?ContractAggregate
    {
        $agg = $this->load($contentId);
        if (!$agg) {
            return null;
        }
        $rowTeacher = (int)($agg->contentRow['teacher_id'] ?? 0);
        if ($rowTeacher !== $teacherId) {
            return null;
        }
        return $agg;
    }

    /**
     * Riscrive il JSON in storage. Se `$expectedVersion` è fornito, fa un
     * read-then-write check per rilevare scritture concorrenti
     * (ContractVersionMismatchException al conflict).
     *
     * Chi chiama decide se bumpare la version PRIMA di save (per scritture
     * sostanziali) oppure ometterlo (update cosmetici).
     */
    public function save(ContractAggregate $agg, ?int $expectedVersion = null): ContractAggregate
    {
        // 1) Optimistic lock check (legge storage corrente per confronto version)
        $previousSnapshot = null;
        if ($expectedVersion !== null) {
            try {
                $currentBytes = $this->storage->get($agg->storageKey);
                $currentData = json_decode($currentBytes, true) ?: [];
                $previousSnapshot = $currentData;
                $currentVer = (int)($currentData['version'] ?? 0);
                if ($currentVer !== $expectedVersion) {
                    throw new ContractVersionMismatchException($expectedVersion, $currentVer);
                }
            } catch (ContractVersionMismatchException $e) {
                throw $e;
            } catch (\Throwable) {
                /* storage miss → no concurrent write, procedi */
            }
        } else {
            // Anche senza expectedVersion, prova a leggere lo snapshot precedente
            // per l'archivio (best-effort, per audit trail).
            try {
                $prevBytes = $this->storage->get($agg->storageKey);
                $prev = json_decode($prevBytes, true);
                if (is_array($prev)) {
                    $previousSnapshot = $prev;
                }
            } catch (\Throwable) {
/* new contract, no snapshot */
            }
        }

        // 1-bis) 18 settembre 2026 — niente pagina resa nel contratto.
        //
        // Il contratto porta la sorgente; l'SVG compilato, il riquadro rosso
        // del render fallito e lo `<script type="text/tikz">` sono quello che
        // il browser ne fa. Quando tornano indietro la sorgente è persa per
        // sempre: un SVG non ridiventa il TikZ che l'ha disegnato. Il difetto
        // che li faceva tornare era nel serializzatore dell'editor ed è
        // corretto; questa è la rete sotto, per la prossima volta e per le
        // scritture che non passano da lì.
        //
        // Si guarda solo quello che COMPARE ora: un contratto già rovinato
        // resta modificabile, altrimenti la rete impedirebbe di ripararlo.
        $resa = \App\Services\Contract\TestoDiPaginaResa::comparsi($previousSnapshot, $agg->data());
        if ($resa !== []) {
            $e = new \App\Services\Contract\ContractRenderedTextException($resa);
            \error_log('[ContractRepository] scrittura rifiutata sul contratto #' . $agg->contentId
                . ': testo di pagina resa — ' . $e->perIlRegistro());
            throw $e;
        }

        // 1-ter) 23/9/2026 — lo schema del contratto (ADR-005: «validare i
        // dati contro lo schema prima del salvataggio»). Fino a oggi lo
        // controllava solo ContractAggregate al caricamento, e soltanto per
        // scriverlo nel registro: save() scriveva qualunque cosa (revisione
        // architetturale 2026-09, A-76). Adesso un errore di schema che il
        // contratto di prima non aveva ferma la scrittura, PRIMA di
        // archiviare la versione precedente e di toccare le statistiche.
        // Come per la pagina resa, un contratto già fuori schema resta
        // modificabile finché non peggiora: bloccarlo impedirebbe di
        // ripararlo.
        $errori = self::erroriDiSchemaNuovi($previousSnapshot, $agg->data());
        if ($errori !== []) {
            $e = new \App\Services\Contract\ContractSchemaException($errori);
            \error_log('[ContractRepository] scrittura rifiutata sul contratto #' . $agg->contentId
                . ': ' . $e->getMessage());
            throw $e;
        }

        // 2) Phase 16 Step 3 — soft-migration UUID sui quesiti senza id
        $agg->ensureItemIds();

        // 3) Phase 17 — ORDINE DB-FIRST (outbox pattern light):
        //    a) Archivia la version precedente in content_versions (append-only)
        //    b) Sync stats nel teacher_content.metadata_json
        //    c) Scrivi il nuovo JSON in storage (se questo fallisce, la DB è
        //       consistente con la version precedente, client può retry)
        if ($previousSnapshot !== null && $this->versions !== null) {
            try {
                $prevVer = (int)($previousSnapshot['version'] ?? 0);
                $this->versions->archive($agg->contentId, $prevVer, $previousSnapshot);
            } catch (\Throwable $e) {
                // L'archiviazione non deve bloccare la save, ma nemmeno
                // sparire: qui dentro, fino al 2026-09-02, finiva OGNI
                // versione di OGNI contenuto, perche' in produzione la
                // tabella `content_versions` non esisteva — era definita solo
                // in schema.sql, mai in una migration. Nessuno se n'e' accorto
                // per mesi perche' il catch era vuoto. Ora la migration 098 la
                // crea, e se ricapita si vede.
                \error_log('[ContractRepository] archive version #' . $agg->contentId
                    . ' fallita: ' . $e->getMessage());
            }
        }
        $this->syncStats($agg);

        $json = json_encode(
            $agg->data(),
            JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        );
        if ($json === false) {
            throw new \RuntimeException("json_encode failed for contract #{$agg->contentId}");
        }
        // 3c) Storage put: ultimo step, con retry interno su errore transiente.
        $this->putWithRetry($agg->storageKey, (string)$json);
        return $agg;
    }

    /**
     * Gli errori di schema che `$dopo` ha e `$prima` no, contati per forma:
     * l'indice del gruppo non conta (`groups[3].type` e `groups[4].type` sono
     * lo stesso errore), il numero sì. Spostare o togliere un gruppo già
     * fuori schema non è un errore nuovo; aggiungerne un altro sì.
     *
     * Senza lo schema (`schema_unavailable`) non si blocca niente: lo si
     * scrive nel registro, come prima.
     *
     * @param array<mixed>|null $prima il contratto in deposito, null se nuovo
     * @param array<mixed>      $dopo
     * @return list<string>
     */
    private static function erroriDiSchemaNuovi(?array $prima, array $dopo): array
    {
        $validatore = new \App\Services\Contract\ContractSchemaValidator();
        $erroriDopo = $validatore->validate($dopo);
        if ($erroriDopo === [] || $erroriDopo === ['schema_unavailable']) {
            if ($erroriDopo !== []) {
                \error_log('[ContractRepository] schema dei contratti non disponibile: scrittura non validata');
            }
            return [];
        }
        if ($prima !== null && ($prima['$schema'] ?? null) === self::LEGACY_SCHEMA_ID) {
            $prima['$schema'] = self::SCHEMA_ID;
        }
        $forma = static fn(string $errore): string => (string)preg_replace('/\[\d+\]/', '[]', $errore);
        $gia = [];
        foreach ($prima !== null ? $validatore->validate($prima) : [] as $errore) {
            $gia[$forma($errore)] = ($gia[$forma($errore)] ?? 0) + 1;
        }
        $nuovi = [];
        foreach ($erroriDopo as $errore) {
            $f = $forma($errore);
            if (($gia[$f] ?? 0) > 0) {
                $gia[$f]--;
                continue;
            }
            $nuovi[] = $errore;
        }
        return $nuovi;
    }

    /**
     * Phase 17 — storage put con retry esponenziale (3 tentativi: 0ms, 50ms, 200ms).
     * Copre errori transienti filesystem (lock, disk-full flash, NFS timeout).
     * Dopo il 3° fallimento rilancia l'eccezione originale.
     */
    private function putWithRetry(string $key, string $contents): void
    {
        $attempts = 0;
        $maxAttempts = 3;
        $delays = [0, 50_000, 200_000]; // microsecondi
        while (true) {
            try {
                $this->storage->put($key, $contents);
                return;
            } catch (\Throwable $e) {
                $attempts++;
                if ($attempts >= $maxAttempts) {
                    throw $e;
                }
                usleep($delays[$attempts] ?? 200_000);
            }
        }
    }

    /**
     * Scrive `stats` + `contract_key` in `teacher_content.metadata_json`.
     * Merge preserva gli altri campi metadata esistenti. Skippa silenzioso
     * se non abbiamo la content row (es. test isolato senza DB).
     *
     * Phase 25.P.3 — sincronizza anche `teacher_content_data.source_type` (cache
     * derivata dalla classificazione del contract per share-block decisions).
     */
    private function syncStats(ContractAggregate $agg): void
    {
        if (!$agg->contentRow) {
            return;
        }
        $tid = (int)($agg->contentRow['teacher_id'] ?? 0);
        if ($tid <= 0) {
            return;
        }
        $meta = json_decode((string)($agg->contentRow['metadata_json'] ?? '{}'), true);
        if (!is_array($meta)) {
            $meta = [];
        }
        $meta['stats'] = $agg->computeStats();
        try {
            $this->contentRepo->update($agg->contentId, $tid, ['metadata' => $meta]);
        } catch (\Throwable) {
            /* DB missing/locked → stats resteranno stale, non blocca la put storage */
        }

        // Phase 25.P.3 — sync source_type cache (NOT-NULLABLE field policy: solo
        // se il contract ha almeno 1 item; altrimenti lascia null/legacy).
        try {
            $cls = $agg->classifyShareability();
            if ($cls['source_type'] !== null) {
                \App\Core\Database::connection()
                    ->prepare('UPDATE teacher_content_data SET source_type = ? WHERE id = ? AND teacher_id = ?')
                    ->execute([$cls['source_type'], $agg->contentId, $tid]);
            }
        } catch (\Throwable) {
            /* best-effort: cache stale è recuperabile via cron backfill */
        }
    }

    /**
     * Carica → applica patch su un item → salva. Combinazione atomica rispetto
     * alla put finale (lo storage è il lock-point). Lancia:
     *   - ContractNotFoundException se il contract non esiste
     *   - ContractItemNotFoundException se itemRef non matcha alcun item
     *   - ContractVersionMismatchException se `$expectedVersion` non combacia
     */
    public function patchItem(
        int $contentId,
        int $teacherId,
        string $itemRef,
        array $patch,
        ?int $expectedVersion = null,
    ): ContractAggregate {
        $agg = $this->loadForTeacher($contentId, $teacherId);
        if (!$agg) {
            throw new ContractNotFoundException("Contract #$contentId not accessible");
        }
        $agg->patchItem($itemRef, $patch)->bumpVersion();
        return $this->save($agg, $expectedVersion);
    }

    public function deleteItem(
        int $contentId,
        int $teacherId,
        string $itemRef,
        ?int $expectedVersion = null,
    ): ContractAggregate {
        $agg = $this->loadForTeacher($contentId, $teacherId);
        if (!$agg) {
            throw new ContractNotFoundException("Contract #$contentId not accessible");
        }
        $agg->deleteItem($itemRef)->bumpVersion();
        return $this->save($agg, $expectedVersion);
    }

    public function moveItem(
        int $contentId,
        int $teacherId,
        string $itemRef,
        int $newIdx,
        ?int $expectedVersion = null,
    ): ContractAggregate {
        $agg = $this->loadForTeacher($contentId, $teacherId);
        if (!$agg) {
            throw new ContractNotFoundException("Contract #$contentId not accessible");
        }
        $agg->moveItem($itemRef, $newIdx)->bumpVersion();
        return $this->save($agg, $expectedVersion);
    }

    /** Phase 20 — rimuove un gruppo dal contract (tutti i suoi items
     *  inclusi). Lancia `ContractItemNotFoundException` se groupRef non
     *  matcha. */
    public function deleteGroup(
        int $contentId,
        int $teacherId,
        string $groupRef,
        ?int $expectedVersion = null,
    ): ContractAggregate {
        $agg = $this->loadForTeacher($contentId, $teacherId);
        if (!$agg) {
            throw new ContractNotFoundException("Contract #$contentId not accessible");
        }
        $agg->deleteGroup($groupRef)->bumpVersion();
        return $this->save($agg, $expectedVersion);
    }

    /** Phase 20 — merge-patch sui campi top-level di un gruppo (title,
     *  intro, ...). Lancia `ContractItemNotFoundException` se groupRef non
     *  matcha. */
    public function patchGroup(
        int $contentId,
        int $teacherId,
        string $groupRef,
        array $patch,
        ?int $expectedVersion = null,
    ): ContractAggregate {
        $agg = $this->loadForTeacher($contentId, $teacherId);
        if (!$agg) {
            throw new ContractNotFoundException("Contract #$contentId not accessible");
        }
        $agg->patchGroup($groupRef, $patch)->bumpVersion();
        return $this->save($agg, $expectedVersion);
    }

    /** Phase 17 — riordina un gruppo (usato dal drag-drop su `.moveBtn`). */
    public function moveGroup(
        int $contentId,
        int $teacherId,
        string $groupRef,
        int $newIdx,
        ?int $expectedVersion = null,
    ): ContractAggregate {
        $agg = $this->loadForTeacher($contentId, $teacherId);
        if (!$agg) {
            throw new ContractNotFoundException("Contract #$contentId not accessible");
        }
        $agg->moveGroup($groupRef, $newIdx)->bumpVersion();
        return $this->save($agg, $expectedVersion);
    }

    /**
     * Phase 17 — cross-file clone (verifica → esercizi corrispondente).
     *
     * Logica:
     *   1. Carica contract VERIFICA + risolve il gruppo che contiene `itemRef`.
     *   2. Cerca il content ESERCIZIO corrispondente via
     *      `content_type='esercizio' AND subject_code=verifica.subject_code
     *      AND topic=verifica.topic` (primo match).
     *   3. Se trovato: carica contract eser → cerca gruppo con stesso titolo →
     *       - MATCH: append item al gruppo (nuovo UUID).
     *       - NO match: append intero gruppo (con solo l'item clonato).
     *   4. Salva contract eser.
     *
     *  Ritorna `{eserContentId, groupId, newItemId}` o lancia se non c'è
     *  un esercizio corrispondente.
     */
    public function cloneToEser(
        int $verificaContentId,
        int $teacherId,
        string $itemRef,
        string $mode = 'source',
    ): array {
        $verAgg = $this->loadForTeacher($verificaContentId, $teacherId);
        if (!$verAgg) {
            throw new ContractNotFoundException("Verifica #$verificaContentId not accessible");
        }
        $itemIdx = $verAgg->findItemIndex($itemRef);
        if (!$itemIdx) {
            throw new ContractItemNotFoundException("Item '$itemRef' non trovato");
        }
        [$gi, $ii] = $itemIdx;
        $srcGroup = $verAgg->groups()[$gi] ?? null;
        $srcItem  = $srcGroup['items'][$ii] ?? null;
        if (!$srcGroup || !$srcItem) {
            throw new ContractItemNotFoundException("Gruppo/item mancanti");
        }

        // Risolve la row esercizio corrispondente.
        // JOIN CANONICA (come ContentStudyController::relatedVerificaHtml, invertita):
        // l'esercizio corrispondente è quello con `title` == `topic` della verifica
        // (+ subject_code). NB: esercizio.topic è uno slot numerico ("3.0"), NON
        // l'argomento → NON va usato per il match (bug storico: prendeva l'esercizio
        // di un'altra classe → "aggiunto" ma invisibile nel file aperto).
        $verRow = $verAgg->contentRow ?? [];
        $subject = (string)($verRow['subject_code'] ?? '');
        $verTopic = (string)($verRow['topic'] ?? '');
        if ($subject === '' || $verTopic === '') {
            throw new \RuntimeException("Verifica row manca subject/topic");
        }
        $needle = mb_strtolower(trim(
            preg_replace('/\s*\(importata da [^)]+\)\s*$/u', '', $verTopic) ?? $verTopic
        ));
        $candidates = $this->contentRepo->search([
            'teacher_id'   => $teacherId,
            'content_type' => 'esercizio',
            'subject_code' => $subject,
            'limit'        => 500,
        ]);
        $matchRow = null;
        foreach ($candidates as $c) {
            if (mb_strtolower(trim((string)($c['title'] ?? ''))) === $needle) {
                $matchRow = $c;
                break;
            }
        }
        $createdEser = false;
        if (!$matchRow) {
            // NO match: crea un nuovo `esercizio` (title = topic della verifica,
            // così i match futuri funzionano). Il gruppo clonato sarà il primo.
            $eserId = $this->createEmptyEserForVerifica($verRow, $teacherId, $srcGroup);
            $eserAgg = $this->loadForTeacher($eserId, $teacherId);
            if (!$eserAgg) {
                throw new \RuntimeException("Impossibile creare esercizio per subject=$subject topic=$verTopic");
            }
            $createdEser = true;
        } else {
            $eserId = (int)$matchRow['id'];
            $eserAgg = $this->loadForTeacher($eserId, $teacherId);
            if (!$eserAgg) {
                throw new ContractNotFoundException("Esercizio #$eserId not accessible");
            }
        }

        // Modalità "solo fonte" (default): conserva badge + riferimento, rimuove
        // traccia e soluzioni → copyright-safe per la zona studenti. "full" copia tutto.
        if ($mode === 'source') {
            $srcItem = $this->stripItemToSource($srcItem);
        }

        // Match gruppo per titolo
        $srcTitle = (string)($srcGroup['title'] ?? '');
        $targetGroupIdx = $eserAgg->findGroupByTitle($srcTitle);
        if ($targetGroupIdx !== null) {
            // Append item al gruppo esistente
            $newItemId = $eserAgg->appendItemToGroup($targetGroupIdx, $srcItem);
            $targetGroupId = (string)($eserAgg->groups()[$targetGroupIdx]['id'] ?? '');
        } else {
            // Append intero gruppo (solo con l'item clonato)
            $newGroup = $srcGroup;
            $newGroup['items'] = [$srcItem];
            unset($newGroup['id']); // nuovo UUID generato da appendGroup
            // Assegna nuovo id all'item pure (ri-uso JSON encode deep-copy)
            if (!empty($newGroup['items'][0]['id'])) {
                unset($newGroup['items'][0]['id']);
            }
            $targetGroupId = $eserAgg->appendGroup($newGroup);
            // Il nuovo item id è il primo item del gruppo appena creato
            $lastGroupIdx = count($eserAgg->groups()) - 1;
            $newItemId = (string)($eserAgg->groups()[$lastGroupIdx]['items'][0]['id'] ?? '');
        }
        $eserAgg->bumpVersion();
        $this->save($eserAgg);
        return [
            'eserContentId' => $eserId,
            'eserVersion'   => $eserAgg->version(),
            'groupId'       => $targetGroupId,
            'groupTitle'    => (string)($srcGroup['title'] ?? ''),
            'newItemId'     => $newItemId,
            'createdGroup'  => $targetGroupIdx === null,
            'createdEser'   => $createdEser,
            'mode'          => $mode,
        ];
    }

    /**
     * Modalità "solo fonte": conserva badge + riferimento bibliografico (source),
     * categoria e difficoltà; azzera traccia (`question`) e soluzioni
     * (`solution`/`justification`/`answer`/`options`) → il materiale protetto da
     * copyright NON viene copiato nella zona studenti. I campi non elencati
     * (justification/answer/options/rmLayout/dsa_marks) vengono semplicemente
     * non ricopiati.
     */
    /** Al posto della traccia di un esercizio preso da un libro; lo usa anche ContractRenderer. */
    public const SOURCE_PLACEHOLDER = 'Traccia e soluzioni reperibili nel testo in adozione';

    /**
     * Modalità "solo fonte" (copyright-safe):
     *  - conserva badge + riferimento (source), categoria, difficoltà;
     *  - TRACCIA (`question`, testo del libro) → testo placeholder;
     *  - SVOLGIMENTO del docente (`solution`/`justification`) → CONSERVATO, ma il
     *    contenuto dentro `<span class="dots">…</span>` (risultati/risposte finali
     *    che il docente ha scelto di nascondere) → "...".
     */
    private function stripItemToSource(array $it): array
    {
        $keep = [];
        foreach (['id', 'difficulty', 'source', 'category_label', 'category_color', 'badge', 'mark'] as $k) {
            if (array_key_exists($k, $it)) {
                $keep[$k] = $it[$k];
            }
        }
        $keep['question'] = [['type' => 'text', 'content' => self::SOURCE_PLACEHOLDER]];
        if (!empty($it['solution'])) {
            $keep['solution'] = $this->redactDotsInBlocks((array)$it['solution']);
        }
        if (!empty($it['justification'])) {
            $keep['justification'] = $this->redactDotsInBlocks((array)$it['justification']);
        }
        return $keep;
    }

    /** Ricorsivo: in ogni campo `content` stringa, sostituisce il contenuto
     *  interno di `<span class="dots">…</span>` con "..." (conserva lo span). */
    private function redactDotsInBlocks(array $blocks): array
    {
        $walk = function ($node) use (&$walk) {
            if (!is_array($node)) {
                return $node;
            }
            $out = [];
            foreach ($node as $k => $v) {
                if ($k === 'content' && is_string($v)) {
                    $out[$k] = preg_replace(
                        '#(<span\b[^>]*\bclass=(["\'])(?:[^"\']*\s)?dots(?:\s[^"\']*)?\2[^>]*>).*?(</span>)#is',
                        '$1...$3',
                        $v
                    ) ?? $v;
                } else {
                    $out[$k] = $walk($v);
                }
            }
            return $out;
        };
        return array_map($walk, $blocks);
    }

    /**
     * Phase 17 — crea un nuovo `teacher_content` tipo `esercizio` quando il
     * clone cross-file non trova una row eser corrispondente. Il contract
     * JSON iniziale contiene solo la shell (title + meta, NO groups) — il
     * gruppo clonato verrà aggiunto subito dopo dalla caller.
     *
     * Storage key pattern coerente con legacy:
     *   institutes/{iid}/private/{tid}/esercizi/{subj}/{topic}.contract.json
     * Se `institute_id` non è derivabile, fallback `0/private/{tid}/…` (il
     * filesystem storage lo gestisce come directory normale).
     */
    /**
     * Phase 18 — crea un contract shell vuoto (groups=[]) per un content
     * già esistente in `teacher_content` ma senza contract. Usato da
     * TeacherContentController::store quando il client crea nuovo
     * esercizio/verifica/lab via POST /api/teacher/content.
     *
     * Post-condition: la row ha metadata_json.contract_key settato;
     * il renderer emette un fm-draggable-container vuoto dove tipoesercizio
     * può aggiungere il primo .fm-groupcollex.
     */
    /**
     * ADR-037, fase 2 — il contratto di un contenuto copiato («Duplica in…»).
     *
     * Il JSON del contenuto sorgente si riscrive in un file nuovo, con la
     * scuola di arrivo e il docente nello scope, il titolo della copia e la
     * versione a zero; la riga nuova prende la sua `contract_key`. Il nome del
     * file porta l'id della copia: la chiave «per argomento» di
     * createEmptyShellForNewContent() farebbe scrivere la copia sopra
     * l'originale quando argomento e materia coincidono.
     *
     * Torna la chiave scritta, o null se il sorgente non ha contratto (niente
     * da copiare: la copia nasce con il suo corpo e basta).
     *
     * @throws \App\Services\Contract\ContractRenderedTextException se la copia
     *         aggiungesse al sorgente testo di pagina resa (vedi sotto: quello
     *         che eredita dal sorgente si registra e passa).
     */
    public function copiaPerNuovoContenuto(int $sorgente, int $copia, int $instituteId): ?string
    {
        $agg = $this->load($sorgente);
        if ($agg === null) {
            return null;
        }
        $riga = $this->contentRepo->find($copia);
        if (!$riga) {
            throw new \RuntimeException('copia_non_trovata');
        }
        $teacherId = (int)$riga['teacher_id'];
        $type = (string)($riga['content_type'] ?? 'esercizio');
        $dir = match ($type) {
            'verifica' => 'verifiche',
            'lab'      => 'lab',
            default    => 'esercizi',
        };
        $subject = preg_replace('/[^A-Za-z0-9_\-]/', '_', (string)($riga['subject_code'] ?? 'MAT')) ?: 'MAT';
        $chiave = sprintf('institutes/%d/private/%d/%s/%s/copia-%d.contract.json', $instituteId, $teacherId, $dir, $subject, $copia);

        $data = $agg->data();
        $data['$schema'] = self::SCHEMA_ID;
        $data['version'] = 0;
        $data['title'] = (string)($riga['title'] ?? ($data['title'] ?? ''));
        $data['scope'] = array_merge(is_array($data['scope'] ?? null) ? $data['scope'] : [], [
            'teacher_id'   => $teacherId,
            'institute_id' => $instituteId,
        ]);
        $data['_copiato_da'] = ['contenuto' => $sorgente, 'il' => date(DATE_ATOM)];

        // La guardia di save() vale anche qui, che è una scrittura che da save()
        // non passa (versione a zero, niente archivio, niente syncStats: non
        // c'è una versione precedente da conservare).
        //
        // Il confronto è con il SORGENTE, non con il nulla. La copia non
        // introduce niente: ripete quello che è già sul disco. Rifiutarla
        // perché il sorgente è già rovinato non salverebbe nessuna sorgente —
        // quella è persa da prima — e toglierebbe al docente «Duplica in…» su
        // quel contenuto, con un messaggio che gli dice di ricaricare la
        // pagina, cosa che non cambierebbe niente. Quello che la copia AGGIUNGE
        // (il titolo della riga nuova, lo scope, `_copiato_da`) invece si
        // guarda, ed è l'unica strada per cui qui può comparire qualcosa di
        // nuovo.
        $resa = \App\Services\Contract\TestoDiPaginaResa::comparsi($agg->data(), $data);
        if ($resa !== []) {
            $e = new \App\Services\Contract\ContractRenderedTextException($resa);
            \error_log('[ContractRepository] copia rifiutata da #' . $sorgente . ' a #' . $copia
                . ': testo di pagina resa — ' . $e->perIlRegistro());
            throw $e;
        }
        // La corruzione che la copia eredita dal sorgente non si rifiuta, ma
        // non sparisce nemmeno: così il giorno che la diagnostica conta due
        // contratti rovinati invece di uno si sa da dove viene il secondo.
        $ereditati = \App\Services\Contract\TestoDiPaginaResa::trova($agg->data());
        if ($ereditati !== []) {
            $n = count($ereditati);
            \error_log('[ContractRepository] la copia #' . $copia . ' eredita da #' . $sorgente
                . ' ' . ($n === 1 ? '1 punto' : "$n punti") . ' di testo di pagina resa — '
                . (new \App\Services\Contract\ContractRenderedTextException($ereditati))->perIlRegistro());
        }

        $this->storage->put($chiave, (string)json_encode(
            $data,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        ));

        $meta = is_array($riga['metadata'] ?? null) ? $riga['metadata'] : [];
        $meta['contract_key'] = $chiave;
        $this->contentRepo->update($copia, $teacherId, ['metadata' => $meta]);
        return $chiave;
    }

    public function createEmptyShellForNewContent(int $contentId, int $instituteId): void
    {
        $row = $this->contentRepo->find($contentId);
        if (!$row) {
            return;
        }
        $meta = $row['metadata'] ?? [];
        if (!empty($meta['contract_key'])) {
            return; // già creato
        }

        $type    = (string)($row['content_type'] ?? 'esercizio');
        $dir     = match ($type) {
            'verifica' => 'verifiche',
            'lab'      => 'lab',
            default    => 'esercizi',
        };
        $subject = (string)($row['subject_code'] ?? 'MAT');
        $topic   = (string)($row['topic'] ?? '');
        $title   = (string)($row['title'] ?? $topic);
        $teacherId = (int)$row['teacher_id'];

        $safeTopic = \preg_replace('/[^A-Za-z0-9_\-]/', '_', $topic !== '' ? $topic : 'item' . $contentId);
        $contractKey = \sprintf(
            'institutes/%d/private/%d/%s/%s/%s.contract.json',
            $instituteId,
            $teacherId,
            $dir,
            $subject,
            $safeTopic
        );
        $shell = [
            '$schema' => self::SCHEMA_ID,
            'title'   => $title,
            'version' => 0,
            'meta'    => [],
            'groups'  => [],
        ];
        $this->storage->put($contractKey, (string)\json_encode(
            $shell,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        ));

        // Merge metadata con quello esistente (preserva eventuali altre chiavi)
        $newMeta = $meta;
        $newMeta['contract_key'] = $contractKey;
        $this->contentRepo->update($contentId, $teacherId, ['metadata' => $newMeta]);
    }

    private function createEmptyEserForVerifica(
        array $verRow,
        int $teacherId,
        array $srcGroup,
    ): int {
        $subject = (string)($verRow['subject_code'] ?? '');
        $topic   = (string)($verRow['topic'] ?? '');
        $title   = (string)($verRow['title'] ?? $topic);
        $indirizzo = $verRow['indirizzo'] ?? null;
        $classe    = $verRow['classe']    ?? null;

        // Inferisce institute_id dalla path del contract_key della verifica
        // (pattern `institutes/{iid}/...`).
        $verMeta = json_decode((string)($verRow['metadata_json'] ?? '{}'), true) ?: [];
        $verCkey = (string)($verMeta['contract_key'] ?? '');
        $iid = 0;
        if (preg_match('#^institutes/(\d+)/#', $verCkey, $mm)) {
            $iid = (int)$mm[1];
        }

        // Storage key deterministico per il nuovo contract
        $safeTopic = preg_replace('/[^A-Za-z0-9_\-]/', '_', $topic);
        $contractKey = sprintf(
            'institutes/%d/private/%d/esercizi/%s/%s.contract.json',
            $iid,
            $teacherId,
            $subject,
            $safeTopic
        );

        // Contract shell minimale (schema `pantedu.content.v1`)
        $shell = [
            '$schema' => self::SCHEMA_ID,
            'title'   => $title,
            'version' => 0,
            'meta'    => [
                'source_citation' => $verRow['metadata']['source_citation'] ?? '',
            ],
            'groups'  => [],
        ];
        $this->storage->put($contractKey, (string)json_encode(
            $shell,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        ));

        $newId = $this->contentRepo->create([
            'teacher_id'   => $teacherId,
            'content_type' => 'esercizio',
            'subject_code' => $subject,
            'indirizzo'    => $indirizzo,
            'classe'       => $classe,
            'topic'        => $topic,
            'title'        => $title,
            'metadata'     => ['contract_key' => $contractKey],
            'visibility'   => 'draft',
        ]);

        // Phase 18 — audit trail: popola source_content_id con l'id della
        // verifica da cui il nuovo esercizio è stato derivato.
        if (!empty($verRow['id']) && \App\Core\Database::isAvailable()) {
            try {
                $stmt = \App\Core\Database::connection()->prepare(
                    'UPDATE teacher_content_data SET source_content_id = ? WHERE id = ?'
                );
                $stmt->execute([(int)$verRow['id'], $newId]);
            } catch (\Throwable) {
                // best-effort: lo schema potrebbe non avere la colonna (pre-Phase 18)
            }
        }
        return $newId;
    }

    /**
     * Phase 17 — duplica un item (server-side). Ritorna array `{agg, newId}`.
     * Usato da `POST /api/teacher/content/{id}/quesito/{itemRef}/duplicate`
     * (legati ai bottoni `.editQ.addBtn` e `.editQ.clone`).
     */
    public function duplicateItem(
        int $contentId,
        int $teacherId,
        string $itemRef,
        ?int $expectedVersion = null,
    ): array {
        $agg = $this->loadForTeacher($contentId, $teacherId);
        if (!$agg) {
            throw new ContractNotFoundException("Contract #$contentId not accessible");
        }
        $newId = $agg->duplicateItem($itemRef);
        $agg->bumpVersion();
        $saved = $this->save($agg, $expectedVersion);
        return ['agg' => $saved, 'newId' => $newId];
    }
}
