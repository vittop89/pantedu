<?php

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Config;
use App\Core\Database;
use App\Core\Request;
use App\Core\Response;
use App\Repositories\TeacherContentRepository;
use App\Services\Study\ChiStudia;
use App\Services\Study\MaterieConMateriali;
use App\Services\Study\PublicContentPolicy;
use App\Services\Study\StudyPageRenderer;

/**
 * Studio multi-materia/multi-tipo (Phase 13).
 *
 * Servono studenti + docenti: rendering DB-backed di mappe/esercizi/lab/
 * verifiche da `teacher_content` (visibility=published per studenti).
 *
 * Route:
 *   GET /studio/{type}/{ind}/{cls}/{subj}                → lista topics
 *   GET /studio/{type}/{ind}/{cls}/{subj}/{topic}        → render content
 *   GET /api/study/topics.json?type=&subject=&ind=&cls=  → JSON topics
 *   GET /api/study/content.json?...                       → JSON list
 *   GET /api/study/content/{id}.json                      → JSON single
 *   GET /api/study/materie.json?classe=&indirizzo=        → materie con materiali (chi studia)
 *
 * type ∈ mappa | esercizio | lab | verifica
 *
 * Studenti: ExerciseAccessPolicy confina a propria sezione (ind+cls);
 * docenti/admin: full access. Solo content visibility=published per
 * studenti; docente vede anche draft propri.
 */
final class ContentStudyController
{
    private TeacherContentRepository $repo;
    /** Chi guarda e dentro quale perimetro: lo stesso per elenchi, pagine e selettore delle materie. */
    private ChiStudia $chiStudia;

    public function __construct(?TeacherContentRepository $repo = null, ?ChiStudia $chiStudia = null)
    {
        $this->repo = $repo ?? new TeacherContentRepository();
        $this->chiStudia = $chiStudia ?? new ChiStudia();
    }

    public function topicsPage(Request $req, array $params): Response
    {
        if (!$this->dbReady()) {
            return Response::html('<h1>DB non disponibile</h1>', 503);
        }
        // Forward retro-compat: la rotta 4-param /studio/{type}/{ind}/{cls}/{subj}
        // è ambigua col vecchio /studio/{indirizzo}/{classe}/{materia}/{topic}
        // M11. Se il 1° segmento non è un type valido → è un URL legacy M11.
        if (!$this->validType($params['type'] ?? null)) {
            // 14/9/2026 — l'ospite con la credenziale di classe arriva qui dal
            // gruppo dello studio, ma la tabella legacy `exercises` non conosce
            // la credenziale: per lui la pagina legacy non esiste.
            if (!Auth::check()) {
                return Response::html('<h1>404 Not Found</h1>', 404);
            }
            return (new ExerciseStudyController())->topicPage($req, [
                'indirizzo' => (string)($params['type'] ?? ''),
                'classe'    => (string)($params['ind']  ?? ''),
                'materia'   => (string)($params['cls']  ?? ''),
                'topic'     => (string)($params['subj'] ?? ''),
            ]);
        }
        $type = $this->validType($params['type'] ?? null);

        $filters = $this->scopedFilters($params, $type);
        $rows = $this->repo->search($filters + ['limit' => 500]);
        $rows = $this->applyAclFilter($rows);
        $topics = $this->groupByTopic($rows);

        $body = $this->renderer()->renderTopicsHtml($type, $params, $topics);
        return $this->renderer()->wrapInShell($req, $body, ucfirst($type) . ' — ' . ($params['subj'] ?? ''));
    }

    public function topicPage(Request $req, array $params): Response
    {
        if (!$this->dbReady()) {
            return Response::html('<h1>DB non disponibile</h1>', 503);
        }
        $type = $this->validType($params['type'] ?? null);
        if (!$type) {
            return Response::html('<h1>404 Not Found</h1>', 404);
        }
        // Nota: la rotta 5-param /studio/{type}/{ind}/{cls}/{subj}/{topic}
        // non collide con nulla (6 segmenti totali). No forward richiesto.

        $topic = $this->normalizeTopic((string)($params['topic'] ?? ''));

        // Phase 20 — ?ids=1,2,3 opzionale: se presente IGNORA il filtro topic
        // della URL path (supporta ctrl+click cross-topic che deve aprire
        // content da topic diversi nella stessa pagina). Senza ?ids restituisce
        // tutti i rows del topic URL (legacy behavior).
        $idsRaw = (string)($req->query['ids'] ?? '');
        $idFilterActive = ($idsRaw !== '');
        $wanted = [];
        if ($idFilterActive) {
            $wanted = array_flip(array_filter(array_map('intval', explode(',', $idsRaw))));
            if (!$wanted) {
                $idFilterActive = false;
            }
        }

        // ADR-030 — con ?ids espliciti, NON vincolare per la terna dell'URL: un
        // documento terna_scoped si apre alla stessa riga (?ids=N) a una terna
        // "lente" diversa dalla terna della riga. La visibilità studente (fragment
        // ContentVisibilityPolicy) e la ACL per-riga (applyAclFilter) restano
        // applicate, quindi nessun bypass di permessi: solo il vincolo terna
        // dell'URL path viene rilassato quando l'id è esplicito.
        $scopeParams = $params;
        if ($idFilterActive) {
            unset($scopeParams['ind'], $scopeParams['cls'], $scopeParams['subj']);
        }
        // with_metadata: include metadata_json (plaintext) nelle righe così
        // extractBodyPt/extractRenderMode/ADR-030 NON ri-fanno find() (decrypt) a
        // vuoto — pagina-documento a riga singola, impatto payload nullo.
        $filters = $this->scopedFilters($scopeParams, $type) + ['limit' => 500, 'with_metadata' => 1];
        if (!$idFilterActive) {
            $filters['topic'] = $topic;
        }
        $rows = $this->repo->search($filters);
        $rows = $this->applyAclFilter($rows);

        if ($idFilterActive) {
            $rows = array_values(array_filter($rows, fn($r) => isset($wanted[(int)$r['id']])));
        }

        // Phase 15: passa teacher_id + institute_id al renderer per source registry lookup
        $tid = $filters['teacher_id'] ?? $this->currentTeacherId();
        $params['teacher_id']   = $tid;
        $params['institute_id'] = $this->privateFilesInstituteId($tid);

        $body = $this->renderer()->renderTopicHtml($type, $params, $topic, $rows);
        return $this->renderer()->wrapInShell($req, $body, $topic, $type);
    }

    public function topicsJson(Request $req): Response
    {
        if (!$this->dbReady()) {
            return Response::json(['error' => 'db_unavailable'], 503);
        }
        $type = $this->validType($req->query['type'] ?? null);
        if (!$type) {
            return Response::json(['error' => 'invalid_type'], 400);
        }

        $filters = $this->scopedFilters([
            'ind'  => $req->query['ind']     ?? $req->query['indirizzo'] ?? null,
            'cls'  => $req->query['cls']     ?? $req->query['classe']    ?? null,
            'subj' => $req->query['subject'] ?? null,
        ], $type) + ['limit' => 500];
        $rows = $this->repo->search($filters);
        $rows = $this->applyAclFilter($rows);
        $topics = $this->groupByTopic($rows);
        // Phase 19 — ETag: topic list stabile se la signature non cambia.
        //
        // 2026-09-10 — il `maxAge` qui sotto resta, al contrario di quelli di
        // `/api/teacher/content` (elenco e dettaglio), che sono stati azzerati
        // lo stesso giorno. La differenza non è il tipo di risposta, è chi la
        // legge e che cosa ci fa:
        //
        //   - lì la legge chi *scrive*, e il documento personalizzabile la
        //     rilegge prima di ogni salvataggio parziale: una copia vecchia
        //     non è lentezza, è il corpo appena salvato che torna indietro;
        //   - qui la legge chi *studia*, e nessuna scrittura parte da questi
        //     dati. Il costo di una finestra è che un documento pubblicato
        //     adesso compaia fra un minuto; il guadagno è che una pagina con
        //     più pannelli non ripeta la stessa domanda per ognuno.
        //
        // Se un giorno da questi elenchi partisse una modifica — un'azione in
        // riga che riscrive quel che ha letto — questa scelta va rifatta.
        //
        // Il segnalibro invece cambia: `MAX(updated_at) + COUNT(*)` non vedeva
        // il filtro per permessi, che si applica in PHP dopo la ricerca. Una
        // condivisione tolta lasciava conteggio e date identici, quindi un 304
        // su un elenco che era cambiato — e lì il minuto non c'entrava: restava
        // finché non si muoveva qualcos'altro.
        return Response::json(['ok' => true, 'topics' => array_values($topics)])
            ->withETagFromBody(maxAge: 60);
    }

    public function contentJson(Request $req): Response
    {
        if (!$this->dbReady()) {
            return Response::json(['error' => 'db_unavailable'], 503);
        }
        // ADR-027 — loader unico: ?section=<key> carica TUTTI i tipi della
        // sezione (section_id) mantenendo i vincoli di scope studente
        // (visibility=published + sezione propria). Senza section → per type.
        $sectionKey = trim((string)($req->query['section'] ?? ''));
        $sectionId  = $sectionKey !== '' ? $this->resolveSectionIdForUser($sectionKey) : null;
        $type = $this->validType($req->query['type'] ?? null);
        if (!$sectionId && !$type) {
            return Response::json(['error' => 'invalid_type'], 400);
        }

        $filters = $this->scopedFilters([
            'ind'   => $req->query['ind']     ?? $req->query['indirizzo'] ?? null,
            'cls'   => $req->query['cls']     ?? $req->query['classe']    ?? null,
            'subj'  => $req->query['subject'] ?? null,
            'topic' => $req->query['topic']   ?? null,
        ], $type ?: 'mappa') + [
            'limit'  => min(500, max(1, (int)($req->query['limit'] ?? 100))),
            'offset' => max(0, (int)($req->query['offset'] ?? 0)),
            // 2026-05-28 — opt-in projection: serve metadata_json per popolare
            // has_body_pt + doc_roles esposti nei row del response. Senza
            // questa flag, search() ritorna proiezione lean → entrambi i
            // campi sarebbero sempre vuoti (regressione silente).
            'with_metadata' => 1,
        ];
        // Section mode: sostituisce il filtro content_type con section_id
        // (i vincoli di scope studente impostati da scopedFilters restano).
        if ($sectionId) {
            unset($filters['content_type']);
            $filters['section_id'] = $sectionId;
        }
        $rows = $this->repo->search($filters);
        // Phase 18 — ACL enforcement: filtro cross-teacher. Docente vede
        // solo le proprie righe o pool condiviso (institute + pool_enabled).
        $rows = $this->applyAclFilter($rows);
        $rows = \App\Support\RigheDellaBarra::arricchisci($rows);

        // Phase 19 — ETag conditional: 304 se la risposta è identica.
        // La finestra di validità resta (vedi `topicsJson` per il perché);
        // il segnalibro no, per lo stesso motivo spiegato lì.
        return Response::json(['ok' => true, 'count' => count($rows), 'rows' => $rows])
            ->withETagFromBody(maxAge: 30);
    }

    /**
     * GET /api/study/materie.json?classe=&indirizzo= — le materie del selettore
     * di chi studia per la classe chiesta (19/9/2026).
     *
     * La barra le disegna al primo caricamento per la classe di chi guarda
     * (views/layout/app.php); quando lo studente passa a un anno già fatto
     * (classi frequentate) js/modules/features/materie-di-chi-studia.js le
     * chiede qui. La classe chiesta passa dallo stesso gate dell'elenco: se non
     * è una classe frequentata vale quella propria.
     *
     * A chi non studia (docente, amministratore: il gruppo dello studio lascia
     * passare anche loro) risponde `filtrate: false` e nessuna materia: il loro
     * selettore non si tocca.
     */
    public function materieJson(Request $req): Response
    {
        if (!$this->dbReady()) {
            return Response::json(['error' => 'db_unavailable'], 503);
        }
        $voci = MaterieConMateriali::vocabolarioDiChiStudia();
        if ($voci === null) {
            return Response::json(['ok' => true, 'filtrate' => false, 'materie' => []]);
        }
        $testo = static function (mixed $v): ?string {
            $s = trim(\is_string($v) ? $v : '');
            return $s === '' ? null : $s;
        };
        $classe    = $testo($req->query['classe'] ?? null);
        $indirizzo = $testo($req->query['indirizzo'] ?? null);
        $materie = (new MaterieConMateriali($this->chiStudia, $this->repo))->filtra($voci, $classe, $indirizzo);
        $out = [];
        foreach ($materie as $m) {
            $out[] = ['code' => (string)$m['code'], 'label' => (string)($m['label'] ?? $m['code'])];
        }
        // Stessa finestra di topics.json (vedi lì il perché): chi legge qui
        // studia, e nessuna scrittura parte da questi dati.
        return Response::json(['ok' => true, 'filtrate' => true, 'materie' => $out])
            ->withETagFromBody(maxAge: MaterieConMateriali::FINESTRA);
    }


    /**
     * Phase 18 / G22.S22 — Filtra rows tramite SharedContentPolicy::canReadContent.
     * Docente (anche super-admin che è anche teacher): solo proprie o pool
     * condiviso. Super-admin tecnico (no role teacher): tutto. Student: tutto
     * (gate a monte scopeConstraints).
     *
     * G22.S22 — rimossa l'eccezione super-admin teacher: un super-admin che è
     * anche docente vede solo i propri contenuti nella sidepage didattica,
     * coerentemente con l'ownership per-teacher (i contenuti recuperati da
     * colleghi appartengono ai recuperatori, non più all'autore).
     */
    private function applyAclFilter(array $rows): array
    {
        if (!$rows) {
            return $rows;
        }
        $actorId = $this->currentTeacherId();
        // Pilota #1 — gate unico: ContentVisibilityPolicy::filterByAcl()
        // incapsula i rami di pass-through:
        //   - guest (teacherId=0)        → rows invariate (filtro in scopeConstraints)
        //   - ruolo non-teacher          → rows invariate (super-admin puro: tutto)
        //   - teacher                    → SharedContentPolicy::canReadContent (G22.S25:
        //     grants espliciti istituto/teacher/group oltre a shared_with_pool).
        // ViewerContext costruito con teacherId=currentTeacherId() e role=Auth::role()
        // per preservare ESATTAMENTE i predicati actorId===0 / AclPolicy::isTeacher().
        $ctx = new \App\Domain\ViewerContext(
            role: \App\Domain\Role::tryFromString((string)\App\Core\Auth::role()),
            teacherId: $actorId,
        );
        $policy = new \App\Services\Sharing\SharedContentPolicy();
        $aclReader = static fn(int $ownerId, int $contentId, bool $pool): bool
            => $policy->canReadContent($actorId, 'teacher_content', $contentId, $ownerId, $pool);
        return (new \App\Domain\ContentVisibilityPolicy())->filterByAcl($rows, $ctx, $aclReader);
    }

    /** Phase 15 — Ritorna HTML renderizzato delle verifiche collegate al topic.
     *  Match: stesso subject + title dell'esercizio (topic=numArg "2.0" è
     *  specifico dell'esercizio; la verifica usa title come topic).
     *
     *  Query params: subject=MAT, title="Sistemi lineari" (o related esercizio id via ?esercizio_id=58).
     *  Usato dall'auto-attivazione di verifica-mode (Phase 21) su /studio/esercizio/...
     *  per caricare verifica correlata in #type_verAll.
     */
    public function relatedVerificaHtml(Request $req): Response
    {
        if (!$this->dbReady()) {
            return Response::html('<!-- db_unavailable -->', 503);
        }

        $subject = trim((string)($req->query['subject'] ?? ''));
        $title   = trim((string)($req->query['title']   ?? ''));

        // Alt: esercizio_id → derive subject+title
        if ($title === '' && !empty($req->query['esercizio_id'])) {
            $erow = $this->repo->find((int)$req->query['esercizio_id']);
            if ($erow) {
                $subject = $subject ?: (string)($erow['subject_code'] ?? '');
                $title   = (string)($erow['title'] ?? '');
            }
        }
        if ($subject === '' || $title === '') {
            return Response::html('<!-- missing subject or title -->');
        }

        // G22.S25 — Owner del content vede anche le verifiche in draft (proprie).
        // Altri docenti vedono solo verifiche published (UX studente-like).
        $actorId = $this->currentTeacherId();

        // G22.S25 — Normalizza title: rimuovi suffix " (importata da ...)" che
        // PoolController::recover aggiunge ai clone. Il match topic↔title deve
        // funzionare anche per contenuti recuperati dal pool, dove l'esercizio
        // ha title="X (importata da Y)" ma la verifica abbinata ha topic="X".
        $titleNorm = preg_replace('/\s*\(importata da [^)]+\)\s*$/u', '', $title) ?? $title;

        // Cerca verifiche con subject+topic match (topic = title per verifiche
        // dopo migrazione hash → title). Fallback match su title se topic mismatch.
        //
        // ADR-037, fase 1 (2026-09-13) — la ricerca passa dallo stesso perimetro
        // degli elenchi (scopedFilters): classe e scuola per lo studente, la
        // credenziale per l'ospite, la scuola attiva per il docente. Prima
        // cercava per materia e titolo in tutte le scuole, e a studenti e
        // ospiti bastava conoscere un titolo per avere le verifiche pubblicate
        // di qualunque docente.
        $ambito = $this->scopedFilters(['subj' => $subject], 'verifica');
        $filters = $ambito + [
            'topic'        => $titleNorm,
            'limit'        => 10,
        ];
        $rows = $this->repo->search($filters);
        if (!$rows) {
            // Fallback: cerca per title esatto (case-insensitive) usando search generica.
            // Match anche su titleNorm (senza suffix "(importata da X)") per gestire
            // verifiche recuperate dal pool.
            $all = $this->repo->search($ambito + ['limit' => 200]);
            $needles = [mb_strtolower($title)];
            if ($titleNorm !== $title) {
                $needles[] = mb_strtolower($titleNorm);
            }
            $rows = array_values(array_filter($all, function ($r) use ($needles) {
                $t = mb_strtolower((string)($r['title'] ?? ''));
                $tp = mb_strtolower((string)($r['topic'] ?? ''));
                foreach ($needles as $n) {
                    if ($t === $n || $tp === $n) {
                        return true;
                    }
                }
                return false;
            }));
        }
        // Filter visibility lato app: owner vede tutto (tranne archiviate),
        // altri solo published. Pilota #1 — gate unico via
        // ContentVisibilityPolicy::canReadRelatedVerifica() (asimmetrico
        // rispetto a canReadSingle: qui l'owner NON vede le proprie archiviate).
        $verCtx = new \App\Domain\ViewerContext(
            role: \App\Domain\Role::tryFromString((string)\App\Core\Auth::role()),
            teacherId: $actorId,
        );
        $verPolicy = new \App\Domain\ContentVisibilityPolicy();
        $rows = array_values(array_filter(
            $rows,
            static fn($r): bool => $verPolicy->canReadRelatedVerifica($r, $verCtx)
        ));
        // FIX sicurezza (audit pilota #1, DIV1): escludi le verifiche correlate
        // in SEZIONI NASCOSTE agli studenti — coerente con contentSingleJson
        // (955-975) e scopedFilters. Senza, una verifica published in sezione
        // hidden (visible_roles esclude 'student') era raggiungibile via
        // "correlate" → leak di contenuto di sezione nascosta a uno studente.
        // Owner e all-scopes (admin/teacher) bypassano, come altrove.
        if (!$verCtx->canSeeAllScopes()) {
            $hiddenSecIds = $this->chiStudia->sezioniNascoste();
            if ($hiddenSecIds) {
                $rows = array_values(array_filter($rows, static function ($r) use ($verCtx, $hiddenSecIds): bool {
                    $isOwner = $verCtx->teacherId > 0 && (int)($r['teacher_id'] ?? 0) === $verCtx->teacherId;
                    $sec = (int)($r['section_id'] ?? 0);
                    return $isOwner || $sec === 0 || !in_array($sec, $hiddenSecIds, true);
                }));
            }
        }
        // G22.S22 — ACL: docente vede solo suoi + pool. Niente cross-teacher
        // leak di verifiche correlate.
        $rows = $this->applyAclFilter($rows);
        if (!$rows) {
            return Response::html('<!-- nessuna verifica correlata -->');
        }

        // Render via ContractRenderer + ContractRepository (Phase 16).
        $tid = $this->currentTeacherId();
        $iid = $this->privateFilesInstituteId($tid);
        // Phase 25.Q.8 — guard scope: solo teacher/admin vedono edit controls.
        // Studente/guest ricevono HTML pulito (no checkIN edit, no checkmod,
        // no moveBtn, no DSA toggles, no selection). HTML mai emesso a non-edit
        // = NO defense via CSS hide (server-side enforcement).
        $canEdit = $this->userCanEdit();
        $renderer = \App\Services\ContractRenderer::loadSourcesFor($iid, $tid, $canEdit);
        $contractRepo = \App\Repositories\Contract\ContractRepository::default();

        $html = '<section id="type_verAll" class="fm-related-verifiche"><div class="fm-titolo fm-related-header"></div>';
        foreach ($rows as $r) {
            $agg = $contractRepo->load((int)$r['id']);
            if (!$agg) {
                continue;
            }
            $html .= '<div class="fm-contract-wrap" data-id="' . (int)$r['id']
                  . '" data-kind="verifica" data-version="' . $agg->version() . '">';
            $html .= $renderer->renderContract($agg->data());
            $html .= '</div>';
        }
        $html .= '</section>';
        return Response::html($html);
    }

    public function contentSingleJson(Request $req, array $params): Response
    {
        if (!$this->dbReady()) {
            return Response::json(['error' => 'db_unavailable'], 503);
        }
        $row = $this->repo->find((int)($params['id'] ?? 0));
        if (!$row) {
            return Response::json(['error' => 'not_found'], 404);
        }
        // Visibility: studenti vedono solo published; teacher/admin tutto;
        // owner-teacher vede sempre i propri draft.
        // Pilota #1 — gate unico: ContentVisibilityPolicy::canReadSingle()
        //   = published || owner || canSeeAll. ViewerContext costruito con
        //   teacherId=resolveUserId(username) e role=Auth::user()['role'] per
        //   preservare ESATTAMENTE i predicati isOwner / canSeeAll.
        $u = Auth::user();
        $tid = $this->resolveUserId((string)($u['username'] ?? ''));
        $ctx = new \App\Domain\ViewerContext(
            role: $u ? \App\Domain\Role::tryFromString((string)($u['role'] ?? '')) : null,
            teacherId: (int)$tid,
        );
        $policy = new \App\Domain\ContentVisibilityPolicy();
        if (!$policy->canReadSingle($row, $ctx)) {
            return Response::json(['error' => 'forbidden'], 403);
        }
        // 2026-09-13 (ADR-037, S4: «nemmeno per id») — le regole dell'elenco
        // valgono anche qui. Fino a quel giorno «un ruolo che vede tutto»
        // bastava: un docente qualsiasi leggeva per id il contenuto di un
        // collega, bozze e corpo decifrato compresi, e uno studente con account
        // un pubblicato di un'altra scuola. Prova: DettaglioPerIdTest.
        //
        // Docente che non è il proprietario: la stessa ACL di applyAclFilter()
        // (condiviso, grant, stessa scuola); gli altri ruoli passano come lì.
        $acl = new \App\Services\Sharing\SharedContentPolicy();
        if (!$policy->passesAcl($row, $ctx, $acl->aclReaderFor((int)$tid))) {
            return Response::json(['error' => 'forbidden'], 403);
        }
        // Studente con account: solo contenuti di un docente della sua scuola,
        // come il filtro `institute_id` dell'elenco (pivot teacher_institutes).
        if ($ctx->isStudent()) {
            $scuola = (int)($this->chiStudia->contesto()->instituteId ?? 0);
            if (
                $scuola > 0
                && !\App\Support\TeacherContextResolver::isLinkedToInstitute((int)($row['teacher_id'] ?? 0), $scuola)
            ) {
                return Response::json(['error' => 'forbidden'], 403);
            }
            // ADR-037, fase 1 — e il contenuto dev'essere pubblicato nella sua
            // scuola, non solo scritto da un docente che ci lavora.
            if (!\App\Support\Pubblicazioni::haPubblicazione((int)$row['id'], $scuola > 0 ? $scuola : null, 'published')) {
                return Response::json(['error' => 'forbidden'], 403);
            }
        }
        // ADR-032 (2026-09-04) — ospite: "published" non basta piu' da solo.
        // Con la credenziale di classe legge solo i contenuti del docente
        // della credenziale; senza, solo cio' che e' davvero pubblico
        // (sezione publish_public del super-admin). Prima bastava l'id per
        // leggere qualunque contenuto pubblicato di qualunque docente.
        if (!$u) {
            $grantTeachers = \App\Support\ClassAccessGrant::teacherIds();
            if ($grantTeachers === []) {
                if (!PublicContentPolicy::isPublic($row)) {
                    return Response::json(['error' => 'forbidden'], 403);
                }
            } elseif (!in_array((int)($row['teacher_id'] ?? 0), $grantTeachers, true)) {
                return Response::json(['error' => 'forbidden'], 403);
            } elseif (!$this->pubblicatoPerUnaCredenziale($row)) {
                // ADR-037, fase 1 — pubblicato nella scuola di una credenziale
                // di quel docente (o in una qualsiasi, per le credenziali
                // create senza scuola).
                return Response::json(['error' => 'forbidden'], 403);
            }
        }
        // ADR-027 Step 8 — sezione nascosta agli studenti: 403 anche se published.
        // Bypass per owner / all-scopes (identico al ramo canReadSingle non-published).
        $canBypassSection = $ctx->canSeeAllScopes()
            || ((int)$tid > 0 && (int)$row['teacher_id'] === (int)$tid);
        if (
            !$canBypassSection && !empty($row['section_id'])
            && in_array((int)$row['section_id'], $this->chiStudia->sezioniNascoste(), true)
        ) {
            return Response::json(['error' => 'forbidden'], 403);
        }
        return Response::json(['ok' => true, 'content' => $row]);
    }

    /**
     * ADR-037, fase 1 — l'ospite con il portachiavi legge un contenuto per id
     * solo se è pubblicato nella scuola di una delle sue credenziali per quel
     * docente. Una credenziale senza scuola (create prima del 13/9/2026) vale
     * per qualunque scuola del docente.
     *
     * @param array<string,mixed> $row
     */
    private function pubblicatoPerUnaCredenziale(array $row): bool
    {
        $proprietario = (int)($row['teacher_id'] ?? 0);
        foreach (\App\Support\ClassAccessGrant::all() as $g) {
            if ((int)($g['teacher_id'] ?? 0) !== $proprietario) {
                continue;
            }
            $scuola = (int)($g['institute_id'] ?? 0);
            if (\App\Support\Pubblicazioni::haPubblicazione((int)$row['id'], $scuola > 0 ? $scuola : null, 'published')) {
                return true;
            }
        }
        return false;
    }

    // ─────── Filtri scope policy-aware ───────

    /**
     * Forza lo scope per ruolo (studente → propria sezione + pubblicati).
     *
     * 19/9/2026 — chi guarda e il perimetro comune a ogni tipo li calcola
     * ChiStudia (estratti da qui senza cambiare comportamento): li usa anche il
     * selettore delle materie di chi studia, che deve fare la stessa domanda
     * dell'elenco. Qui resta solo cio' che dipende dal tipo.
     */
    private function scopedFilters(array $params, string $type): array
    {
        // Migr 078 — 'risdoc'/'bes' nell'URL sono SEZIONI: il content_type reale
        // è 'document', distinto per section_id. Senza questa mappa la search
        // filtrava content_type='risdoc' (inesistente) → 0 rows → "not found".
        $contentType = $type;
        $sectionId = null;
        if (in_array($type, self::SECTION_DOC_TYPES, true)) {
            $contentType = 'document';
            $sectionId = $this->resolveSectionIdForUser($type);
        }

        $out = ['content_type' => $contentType] + $this->chiStudia->filtri($params);
        // risdoc/bes: vincola alla sezione esatta (distingue risdoc da bes,
        // entrambi content_type='document'). Se non risolvibile, resta il solo
        // content_type='document' (comunque corretto, al più meno specifico).
        if ($sectionId) {
            $out['section_id'] = $sectionId;
        }
        return $out;
    }

    private function groupByTopic(array $rows): array
    {
        $topics = [];
        foreach ($rows as $r) {
            $key = $r['topic'] !== '' ? $r['topic'] : '(senza topic)';
            $topics[$key] ??= ['topic' => $key, 'count' => 0];
            $topics[$key]['count']++;
        }
        ksort($topics);
        return $topics;
    }

    // ─────── Helpers ───────

    private ?StudyPageRenderer $renderer = null;

    /** Rendering HTML (P5, 2026-09-04): dati dal controller, stringhe dal servizio. */
    private function renderer(): StudyPageRenderer
    {
        return $this->renderer ??= new StudyPageRenderer($this->repo, $this->userCanEdit());
    }
    /** Type-segment URL validi per le sezioni document collassate (migr 078):
     *  'risdoc'/'bes' NON sono content_type (TYPES = mappa/esercizio/verifica/
     *  document) ma SEZIONI il cui content_type reale è 'document'. Restano
     *  segmenti URL legittimi (link del sidepage) → vanno accettati e mappati. */
    private const SECTION_DOC_TYPES = ['risdoc', 'bes'];

    private function validType(?string $t): ?string
    {
        if (in_array($t, TeacherContentRepository::TYPES, true)) {
            return $t;
        }
        if (in_array($t, self::SECTION_DOC_TYPES, true)) {
            return $t;
        }
        return null;
    }

    /**
     * ADR-027 — risolve section_key → section_id per l'utente corrente
     * (studente: istituto da users; docente: istituto attivo), rispettando la
     * visibilità per ruolo (resolveFor). Null se non trovata/visibile.
     */
    private function resolveSectionIdForUser(string $sectionKey): ?int
    {
        try {
            $u = Auth::user();
            $uid = (int)($u['id'] ?? 0);
            if ($uid <= 0) {
                // ADR-032 — ospite con credenziale di classe: sezioni
                // dell'istituto del docente della credenziale.
                $iid = \App\Support\ClassAccessGrant::instituteId();
                if ($iid <= 0) {
                    return null;
                }
                foreach ((new \App\Repositories\SidebarSectionRepository())->resolveFor($iid, null) as $s) {
                    if ($s['section_key'] === $sectionKey) {
                        return (int)$s['id'];
                    }
                }
                return null;
            }
            $role = (string)Auth::role();
            if ($role === 'student') {
                $stmt = Database::connection()->prepare('SELECT institute_id FROM users WHERE id=? LIMIT 1');
                $stmt->execute([$uid]);
                $iid = (int)$stmt->fetchColumn();
                $tid = null;
            } else {
                $iid = (int)(Auth::currentInstitute() ?? 0);
                $tid = $uid;
            }
            foreach ((new \App\Repositories\SidebarSectionRepository())->resolveFor($iid, $tid) as $s) {
                if ($s['section_key'] === $sectionKey) {
                    return (int)$s['id'];
                }
            }
        } catch (\Throwable $e) {
/* ignore */
        }
        return null;
    }

    private function normalizeTopic(string $raw): string
    {
        $raw = rawurldecode($raw);
        return trim(str_replace('_', ' ', $raw));
    }

    private function dbReady(): bool
    {
        return (bool)Config::get('database.enabled') && Database::isAvailable();
    }

    private function resolveUserId(string $username): int
    {
        return \App\Support\TeacherContextResolver::userIdFromUsername($username);
    }

    private function currentTeacherId(): int
    {
        return \App\Support\TeacherContextResolver::currentTeacherId();
    }

    private function privateFilesInstituteId(int $teacherId): int
    {
        return \App\Support\TeacherContextResolver::privateFilesInstituteId($teacherId);
    }

    /**
     * Phase 25.Q.8 — autorizzazione di edit per il rendering di esercizi/
     * verifiche. SOLO docenti/admin possono vedere controls di edit
     * (modificare, riordinare, taggare DSA, comporre verifiche).
     *
     * Sicurezza:
     *  - NON è solo CSS hide: l'HTML degli edit controls non viene MAI
     *    emesso a studente/guest (server-side enforcement).
     *  - Defense-in-depth: gli endpoint API di mutazione (PUT/POST
     *    su /api/teacher/content/*, /api/verifica/*) sono protetti da
     *    middleware 'role:teacher' / 'role:admin' nel router.
     */
    private function userCanEdit(): bool
    {
        $role = \App\Core\Auth::role();
        return $role === 'teacher' || \App\Core\Auth::hasAccess('admin');
    }
}
