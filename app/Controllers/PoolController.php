<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Config;
use App\Core\Database;
use App\Core\Request;
use App\Core\Response;
use App\Repositories\Sharing\PoolRepository;
use App\Repositories\TeacherContentRepository;
use App\Services\Crypto\TeacherCryptoService;
use App\Services\Maps\MapBlobStore;
use App\Services\Sharing\SharedContentPolicy;
use App\Support\TeacherContextResolver;
use Throwable;

/**
 * G22.S21 Fase C — Pool browse + recover (catalog ownership refactor).
 *
 * Endpoint:
 *   GET  /api/teacher/pool/materials       — browse materiali condivisi di colleghi
 *   POST /api/teacher/pool/recover/{id}    — clona contenuto nel proprio account
 *
 * Eligibilita': content e' visibile se
 *   - teacher_content.shared_with_pool = 1, OPPURE
 *   - la spunta dell'owner sulla materia (curriculum_teacher.shared_with_pool = 1,
 *     ADR-035: «condividi tutta la materia» e' del docente, non della voce)
 * AND owner_teacher e actor sono nello stesso istituto.
 *
 * Dal 2026-09-04 (revisione P6) il SQL sta in PoolRepository: qui restano
 * la validazione dei filtri, la decisione (SharedContentPolicy) e la forma
 * delle risposte.
 */
final class PoolController
{
    private PoolRepository $pool;

    public function __construct(?PoolRepository $pool = null)
    {
        $this->pool = $pool ?? new PoolRepository();
    }

    /**
     * GET /api/teacher/pool/materials
     * Query params:
     *   - content_type: mappa|esercizio|verifica|...  (opzionale, filtro)
     *   - subject_code: MAT|FIS|... (opzionale)
     *   - owner_id: int (opzionale)
     *
     * Risposta: { ok: true, items: [...] }
     */
    public function materials(Request $req): Response
    {
        if (!$this->dbReady()) {
            return Response::json(['error' => 'db_unavailable'], 503);
        }
        $actor = (int)(Auth::user()['id'] ?? 0);
        if ($actor <= 0) {
            return Response::json(['error' => 'unauthorized'], 401);
        }

        // G22.S22 — Pool su TUTTI gli istituti dell'attore (non solo il primo).
        // L'institute_id usato come scope nel response e' indicativo (primo).
        $actorInstitutes = $this->pool->institutesOf($actor);
        if (!$actorInstitutes) {
            return Response::json(['ok' => true, 'items' => []]);
        }

        $contentType = (string)($req->query['content_type'] ?? '');
        $subjectCode = (string)($req->query['subject_code'] ?? '');
        $ownerId     = (int)($req->query['owner_id'] ?? 0);

        // Filtri validati qui; il repository applica solo quelli non null.
        $typeFilter  = ($contentType !== '' && \in_array($contentType, TeacherContentRepository::TYPES, true))
            ? $contentType : null;
        $subjFilter  = ($subjectCode !== '' && preg_match('/^[A-Z]{2,8}$/', $subjectCode)) ? $subjectCode : null;
        $ownerFilter = $ownerId > 0 ? $ownerId : null;

        // G22.S25 — gruppi dei quali sono membro (per eligibility grant target_type=group).
        $actorGroups = $this->pool->groupsOfMember($actor);

        // Eleggibilita' SET-BASED (parita' con SharedContentPolicy::canReadContent(),
        // vedi PoolRepository::eligibleTeacherContent).
        $rows = $this->pool->eligibleTeacherContent(
            $actor,
            $actorInstitutes,
            $actorGroups,
            $typeFilter,
            $subjFilter,
            $ownerFilter
        );
        $instituteId = $actorInstitutes[0]; // first for response indication
        $items = [];
        foreach ($rows as $r) {
            $name = trim((string)($r['owner_first'] ?? '') . ' ' . (string)($r['owner_last'] ?? ''));
            $items[] = [
                'source'        => 'teacher_content',
                'id'            => (int)$r['id'],
                'content_type'  => (string)$r['content_type'],
                'title'         => (string)$r['title'],
                'topic'         => (string)($r['topic'] ?? ''),
                'subject_code'  => (string)$r['subject_code'],
                'subject_label' => (string)$r['subject_label'],
                'owner_id'      => (int)$r['owner_id'],
                'owner_name'    => $name !== '' ? $name : ('user#' . $r['owner_id']),
                'updated_at'    => (string)($r['updated_at'] ?? ''),
                'via_materia'   => (int)($r['materia_shared'] ?? 0) === 1,
                'already_recovered' => $r['my_recovered_id'] !== null && (int)$r['my_recovered_id'] > 0,
                'my_recovered_id'   => $r['my_recovered_id'] !== null ? (int)$r['my_recovered_id'] : null,
            ];
        }

        // G22.S23 — Aggiunge verifica_documents shared_with_pool=1 (vere
        // verifiche TEX/PDF). Distinte da teacher_content: sono blob TEX/PDF
        // generati. content_type virtuale 'verifica_doc' per distinguerle
        // lato UI dal placeholder teacher_content content_type='verifica'.
        if ($contentType === '' || $contentType === 'verifica' || $contentType === 'verifica_doc') {
            // G22.S25 — Eligibility analoga ai teacher_content per verifica_documents.
            $vdRows = $this->pool->eligibleVerificaDocuments(
                $actor,
                $actorInstitutes,
                $actorGroups,
                $subjFilter,
                $ownerFilter
            );
            // 14/9/2026 — una voce per verifica, non per variante: si condivide
            // e si recupera la verifica intera. La voce porta la variante più
            // recente (le righe arrivano per updated_at decrescente).
            foreach (self::unaPerVerifica($vdRows, 'owner_id') as [$r, $varianti, $ids]) {
                $name = trim((string)($r['owner_first'] ?? '') . ' ' . (string)($r['owner_last'] ?? ''));
                $items[] = [
                    'source'        => 'verifica_documents',
                    'id'            => (int)$r['id'],
                    'content_type'  => 'verifica_doc',
                    'title'         => \App\Repositories\VerificaDocumentRepository::titoloBase((string)$r['title']),
                    'varianti'      => $varianti,
                    'ids'           => $ids,
                    'topic'         => '',
                    'subject_code'  => (string)$r['subject_code'],
                    'subject_label' => (string)$r['subject_label'],
                    'owner_id'      => (int)$r['owner_id'],
                    'owner_name'    => $name !== '' ? $name : ('user#' . $r['owner_id']),
                    'updated_at'    => (string)($r['updated_at'] ?? ''),
                    'via_materia'   => false,
                ];
            }
        }

        return Response::json(['ok' => true, 'institute_id' => $instituteId, 'items' => $items]);
    }

    /**
     * G22.S24 — GET /api/teacher/pool/my-shares
     * Lista dei MIEI contenuti condivisi (teacher_content shared_with_pool=1
     * + verifica_documents shared_with_pool=1). Permette al docente di
     * controllare cosa ha esposto e di rimuoverlo dalla condivisione.
     */
    public function myShares(Request $req): Response
    {
        if (!$this->dbReady()) {
            return Response::json(['error' => 'db_unavailable'], 503);
        }
        $actor = (int)(Auth::user()['id'] ?? 0);
        if ($actor <= 0) {
            return Response::json(['error' => 'unauthorized'], 401);
        }
        $items = [];

        // teacher_content shared (esercizi, mappe, verifiche-container)
        foreach ($this->pool->sharedTeacherContentOf($actor) as $r) {
            $items[] = [
                'source'         => 'teacher_content',
                'id'             => (int)$r['id'],
                'content_type'   => (string)$r['content_type'],
                'title'          => (string)$r['title'],
                'topic'          => (string)($r['topic'] ?? ''),
                'subject_code'   => (string)($r['subject_code'] ?? ''),
                'subject_label'  => (string)($r['subject_label'] ?? ''),
                'institute_id'   => $r['materia_institute_id'] ? (int)$r['materia_institute_id'] : null,
                'institute_name' => (string)($r['institute_name'] ?? ''),
                'updated_at'     => (string)($r['updated_at'] ?? ''),
                'shared_with_pool' => (int)($r['shared_with_pool'] ?? 0) === 1,
                'grants_count'   => (int)($r['grants_count'] ?? 0),
            ];
        }

        // verifica_documents shared (file TEX/PDF): una voce per verifica.
        foreach (self::unaPerVerifica($this->pool->sharedVerificaDocumentsOf($actor), null) as [$r, $varianti, $ids]) {
            $items[] = [
                'source'         => 'verifica_documents',
                'id'             => (int)$r['id'],
                'content_type'   => 'verifica_doc',
                'title'          => \App\Repositories\VerificaDocumentRepository::titoloBase((string)$r['title']),
                'varianti'       => $varianti,
                'ids'            => $ids,
                'topic'          => '',
                'subject_code'   => (string)($r['subject_code'] ?? ''),
                'subject_label'  => (string)($r['subject_label'] ?? ''),
                'institute_id'   => $r['institute_id'] ? (int)$r['institute_id'] : null,
                'institute_name' => (string)($r['institute_name'] ?? ''),
                'updated_at'     => (string)($r['updated_at'] ?? ''),
                'shared_with_pool' => (int)($r['shared_with_pool'] ?? 0) === 1,
                'grants_count'   => (int)($r['grants_count'] ?? 0),
            ];
        }

        return Response::json(['ok' => true, 'items' => $items, 'count' => count($items)]);
    }

    /**
     * G22.S24 — POST /api/teacher/pool/unshare
     * Body JSON: { items: [{ source: 'teacher_content'|'verifica_documents', id: int }, ...] }
     * Rimuove la condivisione per gli items dati (owner check). Bulk-safe.
     */
    public function unshare(Request $req): Response
    {
        if (!$this->dbReady()) {
            return Response::json(['error' => 'db_unavailable'], 503);
        }
        $actor = (int)(Auth::user()['id'] ?? 0);
        if ($actor <= 0) {
            return Response::json(['error' => 'unauthorized'], 401);
        }
        $jsonBody = $req->isJson() ? $req->json() : [];
        $items = is_array($jsonBody['items'] ?? null) ? $jsonBody['items'] : [];
        if (!$items) {
            return Response::json(['error' => 'no_items'], 400);
        }

        $tcIds = [];
        $vdIds = [];
        foreach ($items as $it) {
            $src = (string)($it['source'] ?? '');
            $id  = (int)($it['id'] ?? 0);
            if ($id <= 0) {
                continue;
            }
            if ($src === 'teacher_content') {
                $tcIds[] = $id;
            } elseif ($src === 'verifica_documents') {
                $vdIds[] = $id;
            }
        }
        $unshared = ['teacher_content' => 0, 'verifica_documents' => 0];
        if ($tcIds) {
            $unshared['teacher_content'] = $this->pool->unshareTeacherContent($actor, $tcIds);
        }
        if ($vdIds) {
            $unshared['verifica_documents'] = $this->pool->unshareVerificaDocuments($actor, $vdIds);
        }
        return Response::json([
            'ok' => true,
            'unshared' => $unshared,
            'total' => $unshared['teacher_content'] + $unshared['verifica_documents'],
        ]);
    }

    /**
     * POST /api/teacher/pool/recover/{id}
     * Body:
     *   target_subject_id=INT   (mia materia)        — obbligatorio
     *   target_indirizzo_id=INT (catalog istituto)   — opzionale
     *   target_classe_id=INT    (catalog istituto)   — opzionale
     *
     * Effetto: clona il contenuto sotto il mio account, re-cifrando i blob
     * con la mia KEK. Imposta source_content_id per audit trail. Se sono
     * forniti target_indirizzo_id / target_classe_id, popola anche quelli
     * (utile per la visibilità nei select indirizzo/classe scope-aware).
     */
    public function recover(Request $req, array $params): Response
    {
        if (!$this->dbReady()) {
            return Response::json(['error' => 'db_unavailable'], 503);
        }
        $actor = (int)(Auth::user()['id'] ?? 0);
        if ($actor <= 0) {
            return Response::json(['error' => 'unauthorized'], 401);
        }
        // G22.S22 — accetta sia form-encoded ($_POST) sia JSON body
        // (Content-Type: application/json).
        $jsonBody = $req->isJson() ? $req->json() : [];
        $sourceId       = (int)($params['id'] ?? 0);
        $targetSubject  = (int)($req->post['target_subject_id']  ?? $jsonBody['target_subject_id']  ?? 0);
        $targetIndirizzo = (int)($req->post['target_indirizzo_id'] ?? $jsonBody['target_indirizzo_id'] ?? 0) ?: null;
        $targetClasse   = (int)($req->post['target_classe_id']    ?? $jsonBody['target_classe_id']    ?? 0) ?: null;
        if ($sourceId <= 0 || $targetSubject <= 0) {
            return Response::json(['error' => 'invalid_params'], 400);
        }

        // 1) Validate source: visible to me via pool eligibility (institute + shared flag).
        $src = $this->pool->sourceWithSubject($sourceId);
        if (!$src) {
            return Response::json(['error' => 'source_not_found'], 404);
        }
        $ownerId = (int)$src['teacher_id'];
        if ($ownerId === $actor) {
            return Response::json(['error' => 'cannot_recover_own_content'], 400);
        }
        $rowShared     = (int)($src['shared_with_pool'] ?? 0) === 1;
        $materiaShared = (int)($src['materia_shared'] ?? 0) === 1;
        // G22.S25 — single eligibility check (cross-institute gate + shared/grants)
        // via policy unificata. Sostituisce 3 controlli duplicati separati.
        // Versione ROW-WISE della stessa decisione del WHERE bulk in materials()
        // (vedi blocco "PARITÀ con SharedContentPolicy::canReadContent()"):
        // qualsiasi modifica all'eligibility va replicata in entrambi i siti.
        $policy = new SharedContentPolicy();
        if (!$policy->canReadContent($actor, 'teacher_content', $sourceId, $ownerId, $rowShared, $materiaShared)) {
            return Response::json(['error' => 'forbidden'], 403);
        }

        // 2) Validate target_subject_id: dev'essere una materia che ho spuntato (curriculum_teacher)
        $target = $this->pool->ownMateria($actor, $targetSubject);
        if (!$target) {
            return Response::json(['error' => 'target_subject_invalid'], 400);
        }
        $targetInstId = (int)$target['institute_id'];

        // 2b) Validate target_indirizzo_id / target_classe_id: devono appartenere
        // allo stesso istituto della materia target.
        $indirizzoCode = null;
        $classeCode = null;
        if ($targetIndirizzo !== null) {
            $indirizzoCode = $this->pool->catalogCode($targetIndirizzo, 'indirizzi', $targetInstId);
            if ($indirizzoCode === null) {
                return Response::json(['error' => 'target_indirizzo_invalid'], 400);
            }
        }
        if ($targetClasse !== null) {
            $classeCode = $this->pool->catalogCode($targetClasse, 'classi', $targetInstId);
            if ($classeCode === null) {
                return Response::json(['error' => 'target_classe_invalid'], 400);
            }
        }

        // 3) Decifra body fields nel context dell'owner via repo->find
        $repo = new TeacherContentRepository();
        $original = $repo->find($sourceId);
        if (!$original) {
            return Response::json(['error' => 'source_unreadable'], 500);
        }
        if (!empty($original['_crypto_error'])) {
            return Response::json([
                'error' => 'source_crypto_error',
                'detail' => $original['_crypto_error'],
            ], 500);
        }

        // 4) Clone via repo->create (re-cifra con KEK actor).
        $contentType = (string)$original['content_type'];
        $title       = (string)($original['title'] ?? '');
        $topic       = (string)($original['topic'] ?? '');
        $bodyHtml    = isset($original['body_html']) ? (string)$original['body_html'] : null;
        $metadata    = is_array($original['metadata'] ?? null) ? $original['metadata'] : null;

        try {
            $newId = $repo->create([
                'teacher_id'   => $actor,
                'content_type' => $contentType,
                'subject_code' => (string)$target['code'],
                // G22.S22 — eredita indirizzo/classe scelti nel popup recover.
                // Permette al contenuto clonato di apparire nei select sidebar
                // scope-aware (indirizzo/classe selezionati nel dropdown topbar).
                'indirizzo'    => $indirizzoCode ?: null,
                'classe'       => $classeCode ?: null,
                'topic'        => $topic,
                'title'        => $title . ' (importata da ' . $this->ownerNameFor($ownerId) . ')',
                'body_html'    => $bodyHtml,
                'metadata'     => $metadata,
                'visibility'   => 'draft',
            ]);
        } catch (Throwable $e) {
            return Response::json(['error' => 'clone_failed', 'detail' => $e->getMessage()], 500);
        }

        // 5) source_content_id audit + per-content shared off (private by default).
        $this->pool->markRecovered($newId, $sourceId);

        // 5-bis) Riga di audit del clone. La `create()` qui sopra ha gia'
        // scritto un content_created, ma con `source_id` a NULL: la
        // provenienza viene impostata solo adesso, un'istruzione piu' tardi.
        // Senza questa riga, `content_cloned_from` era una costante che il
        // codice non usava mai, e prendere il materiale di un altro docente
        // dal pool risultava indistinguibile dal crearlo da zero.
        \App\Services\Audit\ContentActionLogger::log(
            \App\Services\Audit\ContentActionLogger::ACTION_CLONED_FROM,
            $actor,
            $newId,
            $contentType,
            [
                'source_content_id' => $sourceId,
                'source_teacher_id' => $ownerId,
                'via'               => 'pool',
            ]
        );

        // 6) Per mappa: clona blob map_blob_path (decrypt owner KEK + encrypt actor KEK).
        if ($contentType === 'mappa' && !empty($original['map_blob_path'])) {
            try {
                $store = new MapBlobStore(new TeacherCryptoService());
                $plain = $store->get($ownerId, (string)$original['map_blob_path']);
                $newPath = $store->put($actor, $plain);
                $this->pool->setMapBlob($newId, $newPath, (string)($original['map_mime'] ?? 'application/xml'));
            } catch (Throwable $e) {
                // Rollback: cancella il row creato, segnala
                $this->pool->deleteContentRow($newId);
                return Response::json([
                    'error' => 'blob_clone_failed',
                    'detail' => $e->getMessage(),
                ], 500);
            }
        }

        // 6b) G22.S22 — Per esercizio: clona il file contract.json sotto il
        // path di Docente2 (institutes/{actorInst}/private/{actor}/eser/{basename})
        // e aggiorna scope dentro al JSON + metadata.contract_key sulla nuova
        // riga. Senza questo il content e' vuoto al render (legacy storage).
        // G22.S22/S25 — Clone contract.json sotto path destinatario.
        // Per esercizio: path institutes/{inst}/private/{tid}/eser/{basename}.
        // Per verifica: path institutes/{inst}/private/{tid}/verifiche/{basename}.
        // Naming convention sotto cartelle diverse per tipo (legacy).
        if ($contentType === 'esercizio' || $contentType === 'verifica') {
            // Il contratto dichiarato dall'originale. Se c'è, la riga nuova
            // ne ha ereditato la chiave da `$metadata`: senza la copia
            // punterebbe al file di un ALTRO docente.
            $chiaveOriginale = \is_array($original['metadata'] ?? null)
                ? trim((string)($original['metadata']['contract_key'] ?? ''))
                : '';
            try {
                $this->cloneContentContract($ownerId, $original, $actor, $newId, $target, $contentType);
            } catch (Throwable $e) {
                error_log('[pool.recover] contract clone failed for #' . $newId
                    . ': ' . $e->getMessage());
                if ($chiaveOriginale !== '') {
                    // L'originale DICHIARA un contratto e non si è riusciti a
                    // copiarlo. Prima si scriveva solo in error_log e si
                    // rispondeva ok:true: l'utente vedeva «copia riuscita» e
                    // si portava a casa una riga che punta al contratto del
                    // proprietario. Si disfa e si dice che è andata male,
                    // come già fa il ramo del blob delle mappe.
                    $this->pool->deleteContentRow($newId);
                    return Response::json([
                        'error'  => 'contract_clone_failed',
                        'detail' => $e->getMessage(),
                    ], 500);
                }
                // Nessun contratto dichiarato: non c'era niente da copiare, e
                // la riga nuova non eredita nessuna chiave. Si prosegue come
                // prima — qui un errore sarebbe un falso allarme.
            }
        }

        return Response::json([
            'ok' => true,
            'new_id' => $newId,
            'content_type' => $contentType,
            // verifica: TEX/PDF blob non clonati (verifica_documents row separata).
            // Il contract.json (gruppi + items) è invece clonato come per gli esercizi.
            'verifica_tex_pdf_skipped' => $contentType === 'verifica',
        ]);
    }

    /**
     * POST /api/teacher/pool/recover-verifica/{id}
     * Body (form o JSON): target_subject_id, target_indirizzo_id, target_classe_id.
     *
     * 14/9/2026 — recupera una verifica condivisa da un collega: una copia del
     * suo pacchetto, con tutte le varianti, nel mio account e nel posto scelto
     * (CopiaVerifica::recupera). Prima il pulsante «Recupera» mandava l'id della
     * verifica a recover(), che lo cercava fra i contenuti. Per una verifica
     * servono indirizzo e classe: è il posto della sua principale.
     */
    public function recoverVerifica(Request $req, array $params): Response
    {
        if (!$this->dbReady()) {
            return Response::json(['error' => 'db_unavailable'], 503);
        }
        $actor = (int)(Auth::user()['id'] ?? 0);
        if ($actor <= 0) {
            return Response::json(['error' => 'unauthorized'], 401);
        }
        $p = $req->isJson() ? $req->json() : $req->post;
        $verifica = (int)($params['id'] ?? 0);
        $materia = (int)($p['target_subject_id'] ?? 0);
        $indirizzo = (int)($p['target_indirizzo_id'] ?? 0);
        $classe = (int)($p['target_classe_id'] ?? 0);
        if ($verifica <= 0 || $materia <= 0) {
            return Response::json(['error' => 'invalid_params'], 400);
        }
        if ($indirizzo <= 0 || $classe <= 0) {
            return Response::json(['error' => 'indirizzo_e_classe_richiesti'], 400);
        }
        $target = $this->pool->ownMateria($actor, $materia);
        if (!$target) {
            return Response::json(['error' => 'target_subject_invalid'], 400);
        }
        try {
            $ids = (new \App\Services\Contenuti\CopiaVerifica())->recupera(
                $verifica,
                $actor,
                (int)$target['institute_id'],
                $indirizzo,
                $classe,
                $materia
            );
        } catch (\InvalidArgumentException $e) {
            $codice = $e->getMessage();
            $stato = match ($codice) {
                'non_trovato' => 404,
                'copia_non_riuscita' => 500,
                'copyright_block' => 409,
                default => 400,
            };
            return Response::json(['error' => $codice], $stato);
        }
        return Response::json(['ok' => true, 'new_id' => $ids[0], 'varianti' => count($ids), 'content_type' => 'verifica_doc']);
    }

    /**
     * Una voce per verifica: raggruppa le righe (varianti e versioni) per
     * proprietario, materia e titolo base, tenendo la prima incontrata — le
     * righe arrivano per data di modifica decrescente, quindi la più recente —
     * e contando le varianti, di cui dà anche gli id.
     *
     * @param list<array<string,mixed>> $righe
     * @param ?string                   $colonnaProprietario null = tutte dello stesso proprietario
     * @return list<array{0:array<string,mixed>,1:int,2:list<int>}>
     */
    private static function unaPerVerifica(array $righe, ?string $colonnaProprietario): array
    {
        $gruppi = [];
        foreach ($righe as $r) {
            $chiave = ($colonnaProprietario !== null ? (int)($r[$colonnaProprietario] ?? 0) : 0)
                . '|' . (int)($r['materia_id'] ?? 0)
                . '|' . mb_strtolower(\App\Repositories\VerificaDocumentRepository::titoloBase((string)($r['title'] ?? '')));
            if (!isset($gruppi[$chiave])) {
                $gruppi[$chiave] = [$r, 0, []];
            }
            $gruppi[$chiave][1]++;
            $gruppi[$chiave][2][] = (int)($r['id'] ?? 0);
        }
        return array_values($gruppi);
    }

    private function ownerNameFor(int $ownerId): string
    {
        if ($ownerId <= 0) {
            return 'collega';
        }
        return $this->pool->ownerName($ownerId) ?? ('user#' . $ownerId);
    }

    private function dbReady(): bool
    {
        return Config::get('database.enabled') && Database::isAvailable();
    }

    /**
     * G22.S22 — Clona file contract.json di un esercizio sotto il path
     * del docente destinatario, aggiornando lo scope JSON interno e la
     * metadata.contract_key della nuova riga DB.
     *
     * Convenzione path legacy:
     *   institutes/{instId}/private/{teacherId}/eser/{basename}
     * dove basename = {topic}_{subj}-{titleSlug}-{ind}{cls}.contract.json
     *
     * @param int   $ownerId    teacher_id dell'esercizio sorgente
     * @param array $original   row originale (subject_code, indirizzo, classe, topic, title)
     * @param int   $actor      teacher_id destinatario
     * @param int   $newId      id della nuova teacher_content
     * @param array $target     row materia di destinazione (code, institute_id)
     */
    private function cloneContentContract(
        int $ownerId,
        array $original,
        int $actor,
        int $newId,
        array $target,
        string $contentType,
    ): void {
        // I contratti sono dati d'istanza: stanno sotto PANTEDU_DATA_PATH
        // (app/Config/storage.php, LocalStorageProvider), non nella radice del
        // repository. Nel container quella radice è l'immagine: leggendo di lì
        // non si trovava il contratto d'origine e la copia scriveva in uno
        // strato che il rilascio successivo butta via.
        $root = \App\Support\PercorsiDati::base(dirname(__DIR__, 2)) . '/storage/objects';
        $slug = static fn(string $s): string => preg_replace('/[^A-Za-z0-9._-]+/', '_', $s) ?? '_';

        $ownerInstId = $this->resolveOwnerInstitute($ownerId, $original);
        if ($ownerInstId === 0) {
            throw new \RuntimeException('owner_institute_unknown');
        }
        $subjCode = (string)($original['subject_code'] ?? '');
        $indCode  = (string)($original['indirizzo']    ?? '');
        $clsCode  = (string)($original['classe']       ?? '');
        $topic    = (string)($original['topic']        ?? '');
        $title    = (string)($original['title']        ?? '');

        // G22.S25 — Convenzione naming/path diversa per type:
        //  - esercizio: {topic}_{subj}-{slug(title)}-{ind}{cls}.contract.json
        //               sotto institutes/{inst}/private/{tid}/eser/
        //  - verifica:  {subj}-{slug(title)}-ver.contract.json
        //               sotto institutes/{inst}/private/{tid}/verifiche/
        // (legacy: gli esercizi includono topic+sezione, le verifiche solo subject+title)
        $dirSlug = $contentType === 'verifica' ? 'verifiche' : 'eser';
        if ($contentType === 'verifica') {
            $basename = $subjCode . '-' . $slug($title) . '-ver.contract.json';
        } else {
            $basename = $topic . '_' . $subjCode . '-' . $slug($title) . '-' . $indCode . $clsCode . '.contract.json';
        }

        // La chiave DICHIARATA dall'originale vince sulla convenzione qui
        // sopra. Misurato il 20/9/2026 sul database di sviluppo: dei 105
        // esercizi con un `contract_key`, 88 stanno sotto `eser/` — ma 9
        // sotto `esercizi/`, 6 sotto `bes/` e 2 sotto `lab/`, cartelle che la
        // convenzione non produce. Per quei 17 la ricostruzione non trovava
        // niente, e la copia usciva agganciata al contratto del proprietario.
        $chiave = \is_array($original['metadata'] ?? null)
            ? trim((string)($original['metadata']['contract_key'] ?? ''))
            : '';
        $srcRel = $chiave !== '' && !str_contains($chiave, '..')
            ? ltrim(str_replace('\\', '/', $chiave), '/')
            : sprintf('institutes/%d/private/%d/%s/%s', $ownerInstId, $ownerId, $dirSlug, $basename);
        $srcAbs = $root . '/' . $srcRel;
        if (!is_file($srcAbs)) {
            throw new \RuntimeException('source_contract_not_found: ' . $srcRel);
        }
        $bytes = @file_get_contents($srcAbs);
        if ($bytes === false) {
            throw new \RuntimeException('source_contract_read_failed');
        }
        $data = json_decode($bytes, true);
        if (!is_array($data)) {
            throw new \RuntimeException('source_contract_json_invalid');
        }

        $actorInstId = (int)$target['institute_id'];
        $data['scope'] = array_merge($data['scope'] ?? [], [
            'teacher_id'   => $actor,
            'institute_id' => $actorInstId,
        ]);
        $data['_recovered_from'] = [
            'owner_teacher_id'   => $ownerId,
            'owner_institute_id' => $ownerInstId,
            'recovered_at'       => date(DATE_ATOM),
        ];

        $dstRel = sprintf('institutes/%d/private/%d/%s/%s', $actorInstId, $actor, $dirSlug, $basename);
        $dstAbs = $root . '/' . $dstRel;
        $dstDir = dirname($dstAbs);
        if (!is_dir($dstDir) && !@mkdir($dstDir, 0o775, true) && !is_dir($dstDir)) {
            throw new \RuntimeException('dst_mkdir_failed');
        }
        $payload = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        // G22.S25 — atomic write: tmp file + rename. Evita stato corrotto
        // se il processo fallisce a metà write (es. disco pieno, kill -9).
        $tmp = $dstAbs . '.' . bin2hex(random_bytes(4)) . '.tmp';
        if (file_put_contents($tmp, $payload) === false) {
            throw new \RuntimeException('dst_write_failed');
        }
        if (!@rename($tmp, $dstAbs)) {
            @unlink($tmp);
            throw new \RuntimeException('dst_rename_failed');
        }

        $meta = json_decode($this->pool->metadataJson($newId), true);
        if (!is_array($meta)) {
            $meta = [];
        }
        $meta['contract_key'] = $dstRel;
        $this->pool->setMetadataJson(
            $newId,
            (string)json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
        );
    }

    /**
     * G22.S22 — Risolve institute_id dell'esercizio sorgente: prima dai
     * FK del row (subject_id → curriculum_entries.institute_id) poi
     * fallback al primo istituto del docente proprietario.
     */
    private function resolveOwnerInstitute(int $ownerId, array $original): int
    {
        $subjId = (int)($original['subject_id'] ?? 0);
        if ($subjId > 0) {
            $iid = $this->pool->instituteOfCatalogEntry($subjId);
            if ($iid > 0) {
                return $iid;
            }
        }
        return $this->pool->firstInstituteOf($ownerId);
    }
}
