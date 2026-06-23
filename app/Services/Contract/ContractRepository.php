<?php

namespace App\Services\Contract;

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
            } catch (\Throwable) {
/* archive non deve bloccare la save */
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
    private const SOURCE_PLACEHOLDER = 'Traccia e soluzioni reperibili nel testo in adozione';

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
            '$schema' => 'pantedu.content.v1',
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
            '$schema' => 'pantedu.content.v1',
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
