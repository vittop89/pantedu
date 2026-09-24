<?php

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Config;
use App\Core\Database;
use App\Core\Request;
use App\Core\Response;
use App\Repositories\TeacherContentRepository;
use App\Support\TeacherContextResolver;
use Throwable;

/**
 * REST CRUD su `teacher_content` (Phase 13).
 *
 *   GET    /api/teacher/content               → lista (filtri: type, subject, etc)
 *   POST   /api/teacher/content               → crea
 *   GET    /api/teacher/content/{id}          → detail
 *   POST   /api/teacher/content/{id}/update   → update parziale (PATCH-like);
 *                                               `metadata` sostituisce i metadati,
 *                                               `metadata_patch` ne cambia solo alcune chiavi
 *   POST   /api/teacher/content/{id}/delete   → cancella
 *   POST   /api/teacher/content/{id}/publish  → visibility=published
 *   POST   /api/teacher/content/{id}/unpublish→ visibility=draft
 *
 * Autorizzazione: teacher+ (route middleware). Ogni operazione su
 * un id verifica che `teacher_id == current user id` (no cross-teacher).
 */
final class TeacherContentController
{
    private TeacherContentRepository $repo;

    public function __construct(?TeacherContentRepository $repo = null)
    {
        $this->repo = $repo ?? new TeacherContentRepository();
    }

    public function index(Request $req): Response
    {
        if (!$this->dbReady()) {
            return Response::json(['error' => 'db_unavailable'], 503);
        }
        $tid = TeacherContextResolver::currentTeacherId();
        if (!$tid) {
            return Response::json(['error' => 'unauthorized'], 401);
        }

        // ADR-027 — loader unico: ?section=<key> carica TUTTI i tipi ancorati
        // alla sezione (section_id) invece di filtrare per content_type.
        $sectionId = null;
        $sectionKey = trim((string)($req->query['section'] ?? ''));
        if ($sectionKey !== '') {
            $iid = $this->activeInstituteId($tid);
            foreach ((new \App\Repositories\SidebarSectionRepository())->resolveFor($iid, $tid) as $s) {
                if ($s['section_key'] === $sectionKey) {
                    $sectionId = $s['id'];
                    break;
                }
            }
        }

        // La scuola della ricerca (2026-09-14). Senza parametro è la scuola
        // attiva, come per la barra. La ricerca del cruscotto può chiederne
        // un'altra fra quelle del docente, o «tutte»: senza scuola la ricerca
        // confronta indirizzo e classe con la pubblicazione principale (vedi
        // TeacherContentRepository::searchLean). Una scuola che non è del
        // docente è un 403, non la scuola attiva al suo posto.
        $scuola = $this->activeInstituteId($tid) ?: null;
        $scuolaChiesta = trim((string)($req->query['institute_id'] ?? ''));
        if ($scuolaChiesta === 'tutte') {
            $scuola = null;
        } elseif ($scuolaChiesta !== '') {
            $iid = ctype_digit($scuolaChiesta) ? (int)$scuolaChiesta : 0;
            if (!\App\Support\TeacherContextResolver::isLinkedToInstitute($tid, $iid)) {
                return Response::json(['ok' => false, 'error' => 'scuola_non_tua'], 403);
            }
            $scuola = $iid;
        }

        $filters = [
            'teacher_id'   => $tid,
            'content_type' => $sectionId ? null : $this->cleanType($req->query['type'] ?? null),
            'section_id'   => $sectionId,
            'subject_code' => $req->query['subject']  ?? null,
            'indirizzo'    => $req->query['indirizzo'] ?? null,
            'classe'       => $req->query['classe']    ?? null,
            'visibility'   => $req->query['visibility'] ?? null,
            'q'            => $req->query['q']         ?? null,
            'limit'        => (int)($req->query['limit']  ?? 100),
            'offset'       => (int)($req->query['offset'] ?? 0),
            // Phase 24.49 — opt-in metadata_json per consumer che hanno
            // bisogno di leggere category/layout/scope (es. risdoc-sidepage merge).
            'with_metadata' => !empty($req->query['with_metadata']),
            // ADR-037, fase 1 — la barra elenca i contenuti della scuola in cui
            // il docente sta lavorando: prima bastavano le sigle, e un
            // contenuto nato in un'altra scuola con le stesse compariva qui.
            'pub_institute_id' => $scuola,
            'pub_actor_id'     => $tid,
        ];
        // Phase 17 — ETag conditional: il client che ha già la lista corrente
        // riceve 304 senza scaricare nulla.
        //
        // 2026-09-10 — `maxAge` era 30. Non è la stessa cosa dell'ETag: l'ETag
        // fa risparmiare la discesa dei dati, `max-age` fa risparmiare la
        // *domanda*. Per trenta secondi il browser rispondeva da sé, quindi il
        // docente che creava, rinominava o cancellava un documento non lo
        // vedeva comparire (o sparire) dall'elenco della barra laterale finché
        // non passava mezzo minuto. A zero ogni richiesta chiede, e quando non
        // è cambiato niente riceve un 304 di duecento byte: il risparmio
        // resta, la finestra cieca no.
        //
        // Il segnalibro non è più `MAX(updated_at) + COUNT(*)`: contava i
        // secondi, e ignorava il filtro per permessi che si applica dopo la
        // ricerca. Adesso l'ETag esce dalla risposta stessa — vedi
        // `Response::withETagFromBody()`.
        $rows = $this->repo->searchLean($filters);
        // Con i metadati la barra disegna anche il 📥 e i ruoli D/C/R: gli
        // stessi due campi, con la stessa regola, di /api/study/content.json
        // (il pannello Verifiche per categoria e le sidepage Risorse docente e
        // BES/DSA leggono da qui).
        if ($filters['with_metadata']) {
            $rows = \App\Support\RigheDellaBarra::arricchisci($rows);
        }
        return Response::json(['ok' => true, 'count' => count($rows), 'rows' => $rows])
            ->withETagFromBody();
    }

    /**
     * GET /api/teacher/capabilities — capability effettive del docente.
     * Usato dall'UI per mostrare solo le opzioni consentite (es. il dropdown
     * "Chi può vederlo" limitato a max_visibility). Full-permissive in SINGLE.
     */
    public function capabilities(Request $req): Response
    {
        $tid = TeacherContextResolver::currentTeacherId();
        if (!$tid) {
            return Response::json(['error' => 'unauthorized'], 401);
        }
        $caps = (new \App\Services\TeacherCapabilityPolicy())->effectiveFor($tid);
        return Response::json(['ok' => true, 'capabilities' => $caps]);
    }

    public function store(Request $req): Response
    {
        if (!$this->dbReady()) {
            return Response::json(['error' => 'db_unavailable'], 503);
        }
        $tid = TeacherContextResolver::currentTeacherId();
        if (!$tid) {
            return Response::json(['error' => 'unauthorized'], 401);
        }

        try {
            $type = (string)($req->post['type'] ?? '');

            // ADR-028 Fase 3 — capability per-docente: tipo di documento creabile
            // + visibilità massima. Full-permissive in SINGLE; ristretto in
            // INSTITUTE secondo il profilo del docente.
            $cap = new \App\Services\TeacherCapabilityPolicy();
            if ($type !== '' && !$cap->canCreateDocType($tid, $type)) {
                return Response::json(['error' => 'doc_type_not_allowed', 'type' => $type], 403);
            }
            $reqScope = (string)($req->post['publish_scope'] ?? 'class');
            if (!$cap->visibilityAllowed($tid, $reqScope)) {
                return Response::json(['error' => 'visibility_not_allowed', 'scope' => $reqScope], 403);
            }
            // ADR-037, fase 2 — «per più classi» non si crea più: più
            // pubblicazioni fanno la stessa cosa, con una scuola e uno stato
            // ciascuna («Dove vale» nel modale).
            if ($reqScope === 'classes') {
                return Response::json(['error' => 'usa_dove_vale'], 400);
            }

            // ADR-027 Step 5-6 — risolve la sezione di creazione (se inviata) e
            // valida il tipo contro allowed_content_types. Retrocompat: senza
            // section_key → $sectionId null → flusso invariato.
            $sectionId = null;
            $sectionKey = trim((string)($req->post['section_key'] ?? ''));
            if ($sectionKey !== '') {
                $iid = $this->activeInstituteId($tid);
                $sections = (new \App\Repositories\SidebarSectionRepository())->resolveFor($iid, $tid);
                foreach ($sections as $s) {
                    if ($s['section_key'] === $sectionKey) {
                        if (!\in_array($type, $s['allowed_content_types'], true)) {
                            return Response::json(['error' => 'type_not_allowed_in_section'], 400);
                        }
                        $sectionId = $s['id'];
                        break;
                    }
                }
            }

            $id = $this->repo->create([
                'teacher_id'   => $tid,
                'content_type' => $type,
                'section_id'   => $sectionId,
                'subject_code' => (string)($req->post['subject']  ?? ''),
                'indirizzo'    => $this->blankToNull($req->post['indirizzo'] ?? null),
                'classe'       => $this->blankToNull($req->post['classe']    ?? null),
                'topic'        => (string)($req->post['topic']    ?? ''),
                'title'        => (string)($req->post['title']    ?? ''),
                'body_html'    => (string)($req->post['body_html'] ?? ''),
                'metadata'     => $this->parseJson($req->post['metadata'] ?? null),
                'visibility'   => (string)($req->post['visibility'] ?? 'draft'),
                // Migration 069 — scope di pubblicazione multi-classe.
                'publish_scope'  => $reqScope,
                // Piano classi, D — «visibile anche dopo l'anno» (verifiche nell'archivio).
                'archive_visible' => !empty($req->post['archive_visible']),
            ]);

            // Phase 18 — auto-create contract shell per il FORMATO 'exercise'
            // (esercizio/verifica/lab): il renderer emette fm-draggable-container
            // vuoto. ADR-027 — branch su FORMATO, non sui nomi-tipo.
            if (TeacherContentRepository::formatOf($type) === 'exercise') {
                try {
                    $iid = $this->activeInstituteId($tid);
                    \App\Repositories\Contract\ContractRepository::default()
                        ->createEmptyShellForNewContent($id, $iid);
                } catch (\Throwable) {
                    // best-effort: la row esiste, il contract puo essere
                    // creato successivamente al primo save.
                }
            }

            // La voce nuova si disegna nella barra senza ricaricarla: il 📥 e i
            // ruoli li decide la stessa regola del caricamento (RigheDellaBarra).
            return Response::json(['ok' => true, 'id' => $id] + $this->perLaBarra($id));
        } catch (\InvalidArgumentException $e) {
            return Response::json(['error' => 'invalid_request'], 400);
        } catch (Throwable $e) {
            // Phase 24.74 — titolo duplicato (uq_teach_content_title = teacher_id
            // + content_subtype + title): messaggio chiaro invece di persist_failed.
            $m = $e->getMessage();
            if (\str_contains($m, 'uq_teach_content_title') || \str_contains($m, '1062')) {
                return Response::json([
                    'error' => 'Hai già un documento con questo titolo: scegline un altro.',
                ], 409);
            }
            return Response::json(['error' => 'persist_failed', 'detail' => $m], 500);
        }
    }

    public function show(Request $req, array $params): Response
    {
        if (!$this->dbReady()) {
            return Response::json(['error' => 'db_unavailable'], 503);
        }
        $tid = TeacherContextResolver::currentTeacherId();
        if (!$tid) {
            return Response::json(['error' => 'unauthorized'], 401);
        }
        $row = $this->repo->find((int)($params['id'] ?? 0));
        if (!$row) {
            return Response::json(['error' => 'not_found'], 404);
        }
        if (!$this->contentVisibilityPolicy()->canReadOwnDetail((int)$row['teacher_id'], $this->viewerContext($tid))) {
            return Response::json(['error' => 'forbidden'], 403);
        }
        // Phase 19 — ETag: invalida su updated_at.
        //
        // 2026-09-10 — qui c'era `maxAge: 10`, e non era un dettaglio di
        // banda: era una perdita di dati.
        //
        // Questo è l'indirizzo da cui il documento personalizzabile carica il
        // proprio corpo, ed è anche quello che rilegge *prima di ogni
        // salvataggio parziale* per non perdere il resto dei metadati
        // (`TeacherContentAdapter._patchMeta`). Con dieci secondi di validità
        // dichiarata il browser rispondeva senza chiedere niente al server:
        //
        //   1. il docente salva; il documento nuovo arriva sul server;
        //   2. entro dieci secondi ricarica, o cambia una qualsiasi opzione
        //      del documento (il titolo, «includi intestazione», il modo di
        //      resa): il browser serve la copia di prima;
        //   3. l'opzione viene riscritta *su quella copia*, e il corpo appena
        //      salvato torna com'era.
        //
        // Misurato il 10 settembre 2026: 266 ms dopo una scrittura andata a
        // buon fine, il browser rileggeva ancora il titolo vecchio. È lo
        // stesso difetto che faceva traballare la prova end-to-end del giro
        // completo — e la prova aveva ragione.
        //
        // A zero il browser chiede sempre; l'ETag gli fa avere un 304 quando
        // non è cambiato niente, che è il risparmio per cui l'ETag esiste.
        // Il segnalibro non è più `id:updated_at`: le date del database contano
        // i secondi, e due salvataggi nello stesso secondo lasciavano un ETag
        // identico su un documento diverso — un 304 sbagliato, cioè di nuovo
        // il corpo vecchio. Adesso l'ETag esce dalla risposta stessa.
        return Response::json(['ok' => true, 'content' => $row])->withETagFromBody();
    }

    public function update(Request $req, array $params): Response
    {
        if (!$this->dbReady()) {
            return Response::json(['error' => 'db_unavailable'], 503);
        }
        $tid = TeacherContextResolver::currentTeacherId();
        if (!$tid) {
            return Response::json(['error' => 'unauthorized'], 401);
        }
        $id = (int)($params['id'] ?? 0);
        if (!$id) {
            return Response::json(['error' => 'invalid_id'], 400);
        }

        $patch = [];
        foreach (['type','subject','indirizzo','classe','topic','title','body_html','visibility'] as $k) {
            if (array_key_exists($k, $req->post)) {
                $col = $k === 'type' ? 'content_type' : ($k === 'subject' ? 'subject_code' : $k);
                $val = $req->post[$k];
                if (in_array($k, ['indirizzo','classe'], true)) {
                    $val = $this->blankToNull($val);
                }
                $patch[$col] = $val;
            }
        }
        if (array_key_exists('metadata', $req->post)) {
            $patch['metadata'] = $this->parseJson($req->post['metadata']);
        }
        // 19/9/2026 — le sole chiavi cambiate, fuse dal repository con quelle
        // salvate (App\Domain\MetadatiDelContenuto). Il modale ✎ manda solo
        // questa: prima mandava i metadati interi ricostruiti da sé, e ogni
        // «Salva» cancellava quello che non conosceva.
        if (array_key_exists('metadata_patch', $req->post)) {
            if (array_key_exists('metadata', $req->post)) {
                return Response::json(['error' => 'metadata_e_patch_insieme'], 400);
            }
            try {
                $patch['metadata_patch'] = \App\Domain\MetadatiDelContenuto::patchDa((string)$req->post['metadata_patch']);
            } catch (\InvalidArgumentException) {
                return Response::json(['error' => 'metadata_patch_non_valida'], 400);
            }
        }
        // Migration 069 — scope di pubblicazione.
        if (array_key_exists('publish_scope', $req->post)) {
            $patch['publish_scope'] = (string)$req->post['publish_scope'];
            // ADR-037, fase 2 — «per più classi» resta solo sui contenuti che
            // lo avevano (la loro principale è in bozza, i posti stanno in
            // «Dove vale»); un contenuto non ci entra più. I bersagli non
            // esistono più: dalla migrazione 118 sono pubblicazioni, e dalla
            // fase 4a il repository non li legge né li scrive.
            if ($patch['publish_scope'] === 'classes' && $this->scopeAttuale($id, $tid) !== 'classes') {
                return Response::json(['error' => 'usa_dove_vale'], 400);
            }
        }
        // Piano classi, D — «visibile anche dopo l'anno»: presente solo quando il
        // form lo invia (verifiche), cosi' un aggiornamento parziale non lo azzera.
        if (array_key_exists('archive_visible', $req->post)) {
            $patch['archive_visible'] = (string)$req->post['archive_visible'] === '1';
        }
        try {
            $ok = $this->repo->update($id, $tid, $patch);
            if (!$ok) {
                return Response::json(['error' => 'not_found_or_forbidden'], 404);
            }
            // La voce della barra si aggiorna sul posto con i valori della riga
            // salvata, non con quelli che il browser crede di aver mandato.
            return Response::json(['ok' => true] + $this->perLaBarra($id));
        } catch (Throwable $e) {
            return Response::json(['error' => 'update_failed'], 500);
        }
    }

    public function destroy(Request $req, array $params): Response
    {
        if (!$this->dbReady()) {
            return Response::json(['error' => 'db_unavailable'], 503);
        }
        $tid = TeacherContextResolver::currentTeacherId();
        if (!$tid) {
            return Response::json(['error' => 'unauthorized'], 401);
        }
        $ok = $this->repo->delete((int)($params['id'] ?? 0), $tid);
        if (!$ok) {
            return Response::json(['error' => 'not_found_or_forbidden'], 404);
        }
        return Response::ok();
    }

    /**
     * Phase 25 — POST /api/teacher/content/{id}/recategorize
     * Sposta un documento in un'altra categoria (e opzionalmente in un'altra
     * sezione). Aggiorna SOLO `metadata_json.$.category` via JSON_SET +
     * eventuale `section_id`: NON tocca il body_pt (storage separato dual-write),
     * quindi è sicuro contro la perdita di contenuto.
     * Body: { category: string, section_key?: string }
     */
    public function recategorize(Request $req, array $params): Response
    {
        if (!$this->dbReady()) {
            return Response::json(['error' => 'db_unavailable'], 503);
        }
        $tid = TeacherContextResolver::currentTeacherId();
        if (!$tid) {
            return Response::json(['error' => 'unauthorized'], 401);
        }
        $id = (int)($params['id'] ?? 0);
        $category = trim((string)($req->post['category'] ?? ''));
        // Audit 25.R.31 — cap + whitelist charset sulla categoria: prima
        // arbitraria → auto-pollution della tassonomia 'residue' con valori
        // sporchi/lunghi. Stesso vincolo dei default_categories admin.
        if (
            $id <= 0 || $category === '' || mb_strlen($category) > 32
            || !preg_match('#^[A-Za-z0-9_ -]{1,32}$#', $category)
        ) {
            return Response::json(['error' => 'invalid_params'], 400);
        }
        // Ownership — Audit 25.R.31 (L11): riusa l'helper centralizzato esistente
        // findOwnedRow (gate unico owner-or-superadmin via ContentVisibilityPolicy)
        // invece del check teacher_id inline duplicato.
        [$row, $ownErr] = $this->findOwnedRow($id, $tid);
        if ($ownErr) {
            return $ownErr;
        }

        // Sezione opzionale (migrazione cross-sezione): risolvi section_id.
        $sectionId = null;
        $changeSection = false;
        $sectionKey = trim((string)($req->post['section_key'] ?? ''));
        if ($sectionKey !== '') {
            $changeSection = true;
            $iid = $this->activeInstituteId($tid);
            foreach ((new \App\Repositories\SidebarSectionRepository())->resolveFor($iid, $tid) as $s) {
                if ($s['section_key'] === $sectionKey) {
                    if (!\in_array((string)$row['content_type'], $s['allowed_content_types'], true)) {
                        return Response::json(['error' => 'type_not_allowed_in_section'], 400);
                    }
                    $sectionId = (int)$s['id'];
                    break;
                }
            }
            if ($sectionId === null) {
                return Response::json(['error' => 'section_not_found'], 404);
            }
        }

        try {
            $pdo = \App\Core\Database::connection();
            if ($changeSection) {
                $sql = "UPDATE teacher_content
                        SET metadata_json = JSON_SET(COALESCE(metadata_json, '{}'), '$.category', :cat),
                            section_id = :sid
                        WHERE id = :id AND teacher_id = :tid";
                $st = $pdo->prepare($sql);
                $st->execute([':cat' => $category, ':sid' => $sectionId, ':id' => $id, ':tid' => $tid]);
            } else {
                $sql = "UPDATE teacher_content
                        SET metadata_json = JSON_SET(COALESCE(metadata_json, '{}'), '$.category', :cat)
                        WHERE id = :id AND teacher_id = :tid";
                $st = $pdo->prepare($sql);
                $st->execute([':cat' => $category, ':id' => $id, ':tid' => $tid]);
            }
            return Response::ok();
        } catch (\Throwable $e) {
            return Response::json(['error' => 'update_failed'], 500);
        }
    }

    /** Trova la row + ACL (owner o super-admin). @return array{0:?array,1:?Response} */
    private function findOwnedRow(int $id, int $tid): array
    {
        $row = $this->repo->find($id);
        if (!$row) {
            return [null, Response::json(['error' => 'not_found'], 404)];
        }
        // Pilota #1 — gate unico: stesso owner-OR-superadmin di canExportOwn
        // (siti 322/662). Prima era una 4a copia inline dello stesso predicato.
        $ctx = new \App\Domain\ViewerContext(
            role: \App\Domain\Role::tryFromString((string)\App\Core\Auth::role()),
            teacherId: $tid,
        );
        if (
            !(new \App\Domain\ContentVisibilityPolicy())->canExportOwn(
                (int)$row['teacher_id'],
                $ctx,
                \App\Services\AclPolicy::isSuperAdmin()
            )
        ) {
            return [null, Response::json(['error' => 'forbidden'], 403)];
        }
        return [$row, null];
    }

    // ─────── Helpers ───────

    /**
     * `has_body_pt` e `doc_roles` della riga com'è salvata, per la voce della
     * barra (App\Support\RigheDellaBarra). Vuoto se la riga non si rilegge.
     *
     * @return array{has_body_pt?: bool, doc_roles?: string}
     */
    private function perLaBarra(int $id): array
    {
        try {
            $riga = $this->repo->find($id);
        } catch (Throwable) {
            return [];
        }
        return $riga ? \App\Support\RigheDellaBarra::perLaRiga($riga) : [];
    }

    private function dbReady(): bool
    {
        return (bool)Config::get('database.enabled') && Database::isAvailable();
    }

    /**
     * La scuola in cui il docente sta lavorando: quella scelta dal selettore
     * (sessione), o la prima collegata per chi ne ha una sola. È la stessa
     * in cui il repository risolve indirizzo, classe e materia
     * (`CurriculumLookup::instituteForTeacher`).
     *
     * Fino al 13 settembre 2026 qui c'era `firstInstituteId` (dal 23/9/2026
     * `privateFilesInstituteId`, la casa dei file privati): per un docente di
     * due scuole la sezione della barra e il contratto finivano nella prima,
     * le etichette nella seconda (rilettura degli scenari, §1.2).
     */
    private function activeInstituteId(int $teacherId): int
    {
        return \App\Support\TeacherContextResolver::activeInstituteId($teacherId);
    }

    /** Lo scope di pubblicazione attuale di un contenuto del docente ('' se non è suo). */
    private function scopeAttuale(int $id, int $teacherId): string
    {
        $st = Database::connection()->prepare(
            'SELECT publish_scope FROM teacher_content_data WHERE id = ? AND teacher_id = ? LIMIT 1'
        );
        $st->execute([$id, $teacherId]);
        return (string)($st->fetchColumn() ?: '');
    }

    /**
     * Pilota #1 — gate UNICO ownership/export.
     * Le rotte di questo controller sono teacher+ (middleware): il viewer è
     * sempre un docente con user_id risolto. Costruisce il ViewerContext
     * esplicito che la policy consuma (nessun SESSION/DB nel core).
     */
    private function contentVisibilityPolicy(): \App\Domain\ContentVisibilityPolicy
    {
        return new \App\Domain\ContentVisibilityPolicy();
    }

    private function viewerContext(int $teacherId): \App\Domain\ViewerContext
    {
        return \App\Domain\ViewerContext::forTeacher($teacherId, $this->activeInstituteId($teacherId));
    }

    private function cleanType(mixed $v): mixed
    {
        if ($v === null || $v === '') {
            return null;
        }
        return (string)$v;
    }

    private function blankToNull(mixed $v): ?string
    {
        $s = is_string($v) ? trim($v) : null;
        return ($s === null || $s === '') ? null : $s;
    }

    private function parseJson(mixed $v): array
    {
        if (is_array($v)) {
            return $v;
        }
        if (!is_string($v) || $v === '') {
            return [];
        }
        $d = json_decode($v, true);
        return is_array($d) ? $d : [];
    }
}
