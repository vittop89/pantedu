<?php

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Config;
use App\Core\Database;
use App\Core\Request;
use App\Core\Response;
use App\Policies\ExerciseAccessPolicy;
use App\Repositories\TeacherContentRepository;

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

    public function __construct(?TeacherContentRepository $repo = null)
    {
        $this->repo = $repo ?? new TeacherContentRepository();
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

        $body = $this->renderTopicsHtml($type, $params, $topics);
        return $this->wrapInShell($req, $body, ucfirst($type) . ' — ' . ($params['subj'] ?? ''));
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
        $params['institute_id'] = $this->firstInstituteId($tid);

        $body = $this->renderTopicHtml($type, $params, $topic, $rows);
        return $this->wrapInShell($req, $body, $topic, $type);
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
        $sig = $this->repo->listSignature($filters);
        return Response::json(['ok' => true, 'topics' => array_values($topics)])
            ->withETag($sig, maxAge: 60);
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
        $rows = $this->enrichRowsForClient($rows);

        // Phase 19 — ETag conditional. Token = signature della lista
        // (listSignature: MAX(updated_at) + COUNT) → 304 se invariato.
        $sig = $this->repo->listSignature($filters);
        return Response::json(['ok' => true, 'count' => count($rows), 'rows' => $rows])
            ->withETag($sig, maxAge: 30);
    }

    /**
     * Arricchisce le righe per il client: has_body_pt (mostra export) + doc_roles
     * (chip D/C/R). Estratto per riuso tra contentJson e publicContentJson.
     * @param list<array<string,mixed>> $rows
     * @return list<array<string,mixed>>
     */
    private function enrichRowsForClient(array $rows): array
    {
        foreach ($rows as &$rr) {
            $meta = isset($rr['metadata_json'])
                ? (\is_string($rr['metadata_json'])
                    ? \json_decode($rr['metadata_json'], true)
                    : $rr['metadata_json'])
                : null;
            $rr['has_body_pt'] = !empty($meta['body_pt']) && \is_array($meta['body_pt']);
            $rr['doc_roles'] = '';
            if (!empty($meta['doc_roles']) && \is_array($meta['doc_roles'])) {
                $allowed = ['D', 'C', 'R'];
                $picked = \array_values(\array_unique(\array_intersect($meta['doc_roles'], $allowed)));
                \usort($picked, fn($a, $b) => \array_search($a, $allowed) - \array_search($b, $allowed));
                $rr['doc_roles'] = \implode('', $picked);
            }
        }
        unset($rr);
        return $rows;
    }

    // ─────────────────────────────────────────────────────────────────────
    // WS4 — ENDPOINT PUBBLICI (no auth): SOLO contenuti published del super-admin
    // nelle sezioni publish_public. Ignorano del tutto la sessione → nessuna
    // escalation possibile (un docente autenticato che li chiama ottiene comunque
    // solo i contenuti pubblici del super-admin, mai i propri draft o cross-teacher).
    // ─────────────────────────────────────────────────────────────────────

    /**
     * Filtri per gli endpoint pubblici. NON usa Auth/viewerContext: serve solo
     * contenuti `published` del super-admin docente nelle sezioni publish_public,
     * filtrati per i selettori. Tipo non pubblico → sentinel deny (lista vuota).
     */
    private function publicScopedFilters(array $params, string $type): array
    {
        $deny = ['content_type' => $type, 'indirizzo' => '__deny__'];
        $repo = new \App\Repositories\SidebarSectionRepository();
        $saId = $this->publicSuperAdminId();
        if ($saId <= 0) {
            return $deny;
        }
        $base = [
            'subject_code' => $params['subj'] ?? null,
            'indirizzo'    => $params['ind'] ?? null,
            'classe'       => isset($params['cls']) ? \App\Support\ClsNormalizer::shrink((string)$params['cls']) : null,
            'topic'        => $params['topic'] ?? null,
            'visibility'   => 'published',
            'teacher_id'   => $saId,
        ];

        // Sezioni-documento (bes/risdoc): SOLO via la specifica sezione pubblica
        // (section_id). Se la sezione richiesta NON è publish_public → deny: così
        // bes pubblica NON espone i documenti risdoc (riservati) e viceversa.
        if (in_array($type, self::SECTION_DOC_TYPES, true)) {
            $pub = $repo->publicSectionByKey($type);
            if (!$pub) {
                return $deny;
            }
            $base['section_id'] = (int)$pub['id'];
            return array_filter($base, static fn($v) => $v !== null);
        }

        // 'document' senza una sezione pubblica esplicita → MAI per tipo (ambiguo
        // tra bes/risdoc) → deny.
        if ($type === 'document') {
            return $deny;
        }

        // Tipi UNIVOCI (mappa/esercizio/verifica): per content_type, solo se la
        // sezione built-in corrispondente è pubblica. 'document' escluso a monte.
        $pubTypes = [];
        foreach ($repo->publicSections() as $ps) {
            $dct = (string)($ps['default_content_type'] ?? '');
            if ($dct !== '' && $dct !== 'document') {
                $pubTypes[] = $dct;
            }
        }
        if (!in_array($type, $pubTypes, true)) {
            return $deny;
        }
        $base['content_type'] = $type;
        return array_filter($base, static fn($v) => $v !== null);
    }

    /**
     * WS4/security — un contenuto è pubblicamente visibile sse: appartiene al
     * super-admin docente, è published, e la sua SEZIONE è publish_public
     * (gate per section_id, non per content_type → bes pubblica non espone
     * risdoc). Per i contenuti legacy senza section_id si ammette solo un tipo
     * UNIVOCO (mappa/esercizio/verifica) di una sezione pubblica, mai 'document'.
     */
    private function isContentPublic(array $row): bool
    {
        $saId = $this->publicSuperAdminId();
        if (
            $saId <= 0
            || (int)($row['teacher_id'] ?? 0) !== $saId
            || (string)($row['visibility'] ?? '') !== 'published'
        ) {
            return false;
        }
        $repo = new \App\Repositories\SidebarSectionRepository();
        $secId = (int)($row['section_id'] ?? 0);
        if ($secId > 0) {
            return in_array($secId, $repo->publicSectionIds(), true);
        }
        $ct = (string)($row['content_type'] ?? '');
        if ($ct === '' || $ct === 'document') {
            return false;
        }
        foreach ($repo->publicSections() as $ps) {
            if ((string)($ps['default_content_type'] ?? '') === $ct) {
                return true;
            }
        }
        return false;
    }

    public function publicTopicsJson(Request $req): Response
    {
        if (!$this->dbReady()) {
            return Response::json(['error' => 'db_unavailable'], 503);
        }
        $type = $this->validType($req->query['type'] ?? null);
        if (!$type) {
            return Response::json(['error' => 'invalid_type'], 400);
        }
        $filters = $this->publicScopedFilters([
            'ind'  => $req->query['ind']     ?? $req->query['indirizzo'] ?? null,
            'cls'  => $req->query['cls']     ?? $req->query['classe']    ?? null,
            'subj' => $req->query['subject'] ?? null,
        ], $type) + ['limit' => 500];
        $rows = $this->repo->search($filters);
        return Response::json(['ok' => true, 'topics' => array_values($this->groupByTopic($rows))]);
    }

    public function publicContentJson(Request $req): Response
    {
        if (!$this->dbReady()) {
            return Response::json(['error' => 'db_unavailable'], 503);
        }
        $sectionKey = trim((string)($req->query['section'] ?? ''));
        $type = $this->validType($req->query['type'] ?? null);
        if ($sectionKey === '' && !$type) {
            return Response::json(['error' => 'invalid_type'], 400);
        }
        $filters = $this->publicScopedFilters([
            'ind'   => $req->query['ind']     ?? $req->query['indirizzo'] ?? null,
            'cls'   => $req->query['cls']     ?? $req->query['classe']    ?? null,
            'subj'  => $req->query['subject'] ?? null,
            'topic' => $req->query['topic']   ?? null,
        ], $type ?: $sectionKey) + [
            'limit'         => min(500, max(1, (int)($req->query['limit'] ?? 100))),
            'offset'        => max(0, (int)($req->query['offset'] ?? 0)),
            'with_metadata' => 1,
        ];
        $rows = $this->enrichRowsForClient($this->repo->search($filters));
        return Response::json(['ok' => true, 'count' => count($rows), 'rows' => $rows]);
    }

    /**
     * WS4 — vista pubblica read-only di UN contenuto, con STESSA shell+sidebar+stile
     * della pagina /studio (riusa renderTopicHtml + wrapInShell). Rispetta render_mode
     * (HTML statico vs web component fm-pt-document, che in view rende dall'SSR senza
     * fetch auth). Gate stretto: super-admin docente + published + tipo di sezione
     * publish_public. Nessun uso di scopedFilters/sessione → niente escalation.
     * Rotta: GET /public/studio/{id}.
     */
    public function publicView(Request $req, array $params): Response
    {
        if (!$this->dbReady()) {
            return Response::html('<h1>DB non disponibile</h1>', 503);
        }
        $id = (int)($params['id'] ?? 0);
        $row = $id > 0 ? $this->repo->find($id) : null;
        if (!$row) {
            return Response::html('<h1>404 Not Found</h1>', 404);
        }
        // GATE pubblico per-SEZIONE (non per tipo): super-admin docente + published
        // + sezione del contenuto publish_public. Vedi isContentPublic().
        if (!$this->isContentPublic($row)) {
            return Response::html('<h1>404 Not Found</h1>', 404);
        }

        // Vista pubblica = HTML statico pulito: niente chrome interattiva
        // (topbar/selettori del componente fm-pt-document). Forziamo render_mode=html
        // così i documenti rendono come "articolo" (rispettando includeHeaderHtml,
        // che agisce sul blocco header del body_pt). Mappe/esercizi non sono
        // influenzati. Non persiste: modifica solo la copia in memoria.
        $meta = is_array($row['metadata'] ?? null)
            ? $row['metadata']
            : (json_decode((string)($row['metadata_json'] ?? '{}'), true) ?: []);
        if (!is_array($meta)) {
            $meta = [];
        }
        $meta['render_mode'] = 'html';
        $row['metadata'] = $meta;                                   // extractMeta legge 'metadata' per primo
        $row['metadata_json'] = json_encode($meta, JSON_UNESCAPED_UNICODE);

        $type = (string)$row['content_type'];
        $topic = (string)($row['topic'] ?? '');
        $rparams = [
            'ind'          => (string)($row['indirizzo'] ?? ''),
            'cls'          => (string)($row['classe'] ?? ''),
            'subj'         => (string)($row['subject_code'] ?? ''),
            'teacher_id'   => (int)($row['teacher_id'] ?? 0),
            'institute_id' => (int)($row['institute_id'] ?? 0),
        ];
        // renderTopicHtml dispatcha mappa/custom-document/contract; userCanEdit()
        // è false per i guest → render view-only (nessun controllo di edit).
        $body = $this->renderTopicHtml($type, $rparams, $topic, [$row]);
        return $this->wrapInShell($req, $body, (string)$row['title'], $type);
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
        $filters = [
            'content_type' => 'verifica',
            'subject_code' => $subject,
            'topic'        => $titleNorm,
            'limit'        => 10,
        ];
        $rows = $this->repo->search($filters);
        if (!$rows) {
            // Fallback: cerca per title esatto (case-insensitive) usando search generica.
            // Match anche su titleNorm (senza suffix "(importata da X)") per gestire
            // verifiche recuperate dal pool.
            $all = $this->repo->search(['content_type' => 'verifica', 'subject_code' => $subject, 'limit' => 200]);
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
        // Owner e all-scopes (admin/teacher/collaborator) bypassano, come altrove.
        if (!$verCtx->canSeeAllScopes()) {
            $hiddenSecIds = $this->hiddenSectionIdsForStudent();
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
        $iid = $this->firstInstituteId($tid);
        // Phase 25.Q.8 — guard scope: solo teacher/admin vedono edit controls.
        // Studente/guest ricevono HTML pulito (no checkIN edit, no checkmod,
        // no moveBtn, no DSA toggles, no selection). HTML mai emesso a non-edit
        // = NO defense via CSS hide (server-side enforcement).
        $canEdit = $this->userCanEdit();
        $renderer = \App\Services\ContractRenderer::loadSourcesFor($iid, $tid, $canEdit);
        $contractRepo = \App\Services\Contract\ContractRepository::default();

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

    /** G22.S15.bis — converte registry array {key,book,volume,authors} → dict
     *  legacy {code,title,volume,publisher,authors}. `volume` è splittato su
     *  " - " per estrarre publisher (es. "Vol.2 Ed.3 - ZANICHELLI"). */
    private static function registryArrayToLegacyDict(array $list): array
    {
        $out = [];
        foreach ($list as $r) {
            if (!is_array($r)) {
                continue;
            }
            $key = (string)($r['key'] ?? '');
            if ($key === '') {
                continue;
            }
            $vol       = (string)($r['volume'] ?? '');
            $publisher = '';
            $shortVol  = $vol;
            if (preg_match('/^(.*?)\s*-\s*([A-Z][A-Z0-9\/ ]*)\s*$/u', $vol, $m)) {
                $shortVol  = trim($m[1]);
                $publisher = trim($m[2]);
            }
            $out[$key] = [
                'code'      => $key,
                'title'     => (string)($r['book']    ?? ''),
                'volume'    => $shortVol,
                'publisher' => $publisher,
                'authors'   => (string)($r['authors'] ?? ''),
            ];
        }
        return $out;
    }

    /** G22.S15.bis — converte dict legacy {code,title,volume,publisher,authors}
     *  → registry array {key,book,volume,authors}. Recompose volume con
     *  publisher (es. "Vol.2 Ed.3" + "ZANICHELLI" → "Vol.2 Ed.3 - ZANICHELLI"). */
    private static function legacyDictToRegistryArray(array $dict): array
    {
        $out = [];
        foreach ($dict as $code => $src) {
            if (!is_array($src)) {
                continue;
            }
            $key = is_string($code) && $code !== ''
                ? $code
                : (string)($src['code'] ?? '');
            if ($key === '') {
                continue;
            }
            $shortVol  = (string)($src['volume']    ?? '');
            $publisher = (string)($src['publisher'] ?? '');
            $vol = $publisher !== '' && $shortVol !== ''
                ? "$shortVol - $publisher"
                : ($shortVol !== '' ? $shortVol : $publisher);
            $out[] = [
                'key'     => $key,
                'book'    => (string)($src['title']   ?? ''),
                'volume'  => $vol,
                'authors' => (string)($src['authors'] ?? ''),
            ];
        }
        return $out;
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
        // ADR-027 Step 8 — sezione nascosta agli studenti: 403 anche se published.
        // Bypass per owner / all-scopes (identico al ramo canReadSingle non-published).
        $canBypassSection = $ctx->canSeeAllScopes()
            || ((int)$tid > 0 && (int)$row['teacher_id'] === (int)$tid);
        if (
            !$canBypassSection && !empty($row['section_id'])
            && in_array((int)$row['section_id'], $this->hiddenSectionIdsForStudent(), true)
        ) {
            return Response::json(['error' => 'forbidden'], 403);
        }
        return Response::json(['ok' => true, 'content' => $row]);
    }

    // ─────── Filtri scope policy-aware ───────

    /**
     * Pilota #1 — Costruisce il {@see \App\Domain\ViewerContext} del viewer
     * corrente delegando la risoluzione dello scope studente a
     * {@see ExerciseAccessPolicy} (sorgente di verità invariata).
     *
     * BEHAVIOR-PRESERVING: l'indirizzo/classe del context derivano da
     * `scopeConstraints()` esattamente come faceva lo override inline in
     * scopedFilters (incluso il sentinel guest `indirizzo='__deny__'`), così
     * che ContentVisibilityPolicy::studyListFilters() emetta il medesimo
     * frammento {visibility, student_scope, indirizzo, classe}.
     */
    private function viewerContext(): \App\Domain\ViewerContext
    {
        $u = Auth::user();
        $policy = new ExerciseAccessPolicy($u ? $this->domainUser($u) : null);

        if ($policy->canSeeAllScopes()) {
            // admin|collaborator|teacher: nessun vincolo di scope.
            $role = \App\Domain\Role::tryFromString((string)($u['role'] ?? '')) ?? \App\Domain\Role::TEACHER;
            return new \App\Domain\ViewerContext(
                role: $role,
                teacherId: $this->currentTeacherId(),
            );
        }

        // Studente / guest: lo scope (indirizzo/classe, o sentinel guest
        // '__deny__') viene da scopeConstraints(). Un context STUDENT fa
        // emettere a studyListFilters il frammento published+student_scope.
        $constraints = $policy->scopeConstraints();
        $uid = (int)($u['id'] ?? 0);

        // Ancora lo scope all'ACCOUNT registrato (DB autoritativo, migration 091):
        // istituto + indirizzo + classe vengono dal profilo studente, non dall'URL.
        // Fallback ai constraints (course/sentinel guest) per account legacy senza
        // colonne valorizzate.
        $scope = $uid > 0 ? (new \App\Services\Student\StudentProfileService())->scopeForUser($uid) : null;
        $indirizzo = $scope['indirizzo'] ?? ($constraints['indirizzo'] ?? null);
        $classe    = $scope['classe']    ?? ($constraints['classe'] ?? null);
        $institute = $scope['institute_id'] ?? \App\Core\Auth::currentInstitute();

        return \App\Domain\ViewerContext::forStudent($uid, $institute, $indirizzo, $classe);
    }

    /** WS4 — id del super-admin DOCENTE (per i contenuti pubblici guest). 0 se assente. */
    private function publicSuperAdminId(): int
    {
        if (!\App\Core\Database::isAvailable()) {
            return 0;
        }
        try {
            $id = Database::connection()->query(
                "SELECT id FROM users WHERE is_super_admin = 1 AND role = 'teacher' ORDER BY id LIMIT 1"
            )->fetchColumn();
            return (int)($id ?: 0);
        } catch (\Throwable) {
            return 0;
        }
    }

    /** Forza scope per ruolo (student → propria sezione + published). */
    private function scopedFilters(array $params, string $type): array
    {
        $ctx = $this->viewerContext();

        // Migr 078 — 'risdoc'/'bes' nell'URL sono SEZIONI: il content_type reale
        // è 'document', distinto per section_id. Senza questa mappa la search
        // filtrava content_type='risdoc' (inesistente) → 0 rows → "not found".
        $contentType = $type;
        $sectionId = null;
        if (in_array($type, self::SECTION_DOC_TYPES, true)) {
            $contentType = 'document';
            $sectionId = $this->resolveSectionIdForUser($type);
        }

        $base = [
            'content_type' => $contentType,
            'subject_code' => $params['subj']  ?? null,
            'indirizzo'    => $params['ind']   ?? null,
            // G19.49 — DB normalizzato a short codes ("1".."5"), no piu' "2s".
            // shrink() accetta sia "2" sia "2s" (legacy URL bookmark) → "2".
            'classe'       => isset($params['cls']) ? \App\Support\ClsNormalizer::shrink((string)$params['cls']) : null,
            // visibility null per all-scopes; 'published' per studenti — emesso
            // dal frammento policy sotto.
            'visibility'   => null,
        ];
        // Pilota #1 — gate unico: ContentVisibilityPolicy::studyListFilters()
        // produce {visibility:published, student_scope, indirizzo/classe scope,
        // section_id_not_in} per gli studenti, [] per gli all-scopes.
        // ADR-027 Step 8 — section_id_not_in: sezioni NON visibili agli studenti.
        // Migration 069 — student_scope: include 'general'/'classes' inclusivi.
        $hidden = $ctx->canSeeAllScopes() ? [] : $this->hiddenSectionIdsForStudent();
        $fragment = (new \App\Domain\ContentVisibilityPolicy())->studyListFilters($ctx, $hidden);
        foreach ($fragment as $k => $v) {
            $base[$k] = $v;
        }
        $out = array_filter($base, static fn($v) => $v !== null);
        // array_filter rimuove gli array vuoti ma non quelli pieni; section_id_not_in
        // (array) sopravvive. Reinserisce se per caso filtrato.
        if (!empty($fragment['section_id_not_in'])) {
            $out['section_id_not_in'] = $fragment['section_id_not_in'];
        }
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

    // ─────── Rendering HTML ───────

    private function renderTopicsHtml(string $type, array $params, array $topics): string
    {
        $esc = static fn(?string $s): string => htmlspecialchars((string)$s, ENT_QUOTES);
        $h  = '<main class="fm-study">';
        $h .= '<h1>' . $esc(ucfirst($type)) . ' — ' . $esc($params['subj'] ?? '');
        $h .= ' <span class="fm-muted">(' . $esc($params['ind'] ?? '') . ' · ' . $esc($params['cls'] ?? '') . ')</span></h1>';

        if (!$topics) {
            $h .= '<p class="fm-muted">Nessun ' . $esc($type) . ' pubblicato per questa sezione.</p>';
        } else {
            $h .= '<ul class="fm-study-topics">';
            foreach ($topics as $t) {
                $slug = rawurlencode($t['topic']);
                $h .= '<li><a href="/studio/' . $esc($type) . '/' . $esc($params['ind'] ?? '') . '/'
                    . $esc($params['cls'] ?? '') . '/' . $esc($params['subj'] ?? '') . '/' . $slug . '">'
                    . $esc($t['topic']) . '</a> <span class="fm-study-count">×' . (int)$t['count'] . '</span></li>';
            }
            $h .= '</ul>';
        }
        $h .= '</main>';
        return $h;
    }

    /**
     * Phase 18 — render dedicato per content_type=mappa: iframe Google
     * Drawio full-width, senza upbar/header_page/fm-draggable-container
     * (read-only, niente CRUD da fare).
     */
    /**
     * WCAG 1.1.1 (Contenuti non testuali) — alternativa testuale accessibile
     * della mappa concettuale. La mappa è un diagramma drawio reso in un iframe
     * di terze parti (viewer.diagrams.net), non navigabile da tastiera/screen
     * reader. Qui estraiamo dal XML i concetti (nodi con testo) e le relazioni
     * (archi sorgente→destinazione) e li presentiamo come elenco testuale in un
     * <details> collassabile, nativamente accessibile: il contenuto della mappa
     * è così fruibile senza vedere il diagramma né usare il mouse.
     *
     * Ritorna '' se dall'XML non si estrae testo (mappa puramente grafica): in
     * tal caso non si genera una lista fittizia.
     */
    private function mapTextAlternative(string $xml): string
    {
        if (\trim($xml) === '') {
            return '';
        }
        $esc = static fn(?string $s): string => \htmlspecialchars((string)$s, ENT_QUOTES);
        // Normalizza un value drawio (può contenere HTML rich-label): <br> →
        // spazio, via i tag, decodifica entità, collassa gli spazi.
        $clean = static function (?string $v): string {
            if ($v === null || $v === '') {
                return '';
            }
            $v = \preg_replace('#<br\s*/?>#i', ' ', (string)$v);
            $v = \strip_tags((string)$v);
            $v = \html_entity_decode($v, ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $v = \preg_replace('/\s+/u', ' ', (string)$v);
            return \trim((string)$v);
        };

        $nodes = [];   // id => label
        $edges = [];   // [sourceId, label, targetId]
        $prev = \libxml_use_internal_errors(true);
        $doc = new \DOMDocument();
        if (@$doc->loadXML($xml)) {
            foreach ($doc->getElementsByTagName('mxCell') as $cell) {
                /** @var \DOMElement $cell */
                $id    = (string)$cell->getAttribute('id');
                $value = $clean($cell->getAttribute('value'));
                if ($cell->getAttribute('vertex') === '1') {
                    if ($value !== '' && $id !== '') {
                        $nodes[$id] = $value;
                    }
                } elseif ($cell->getAttribute('edge') === '1') {
                    $edges[] = [
                        (string)$cell->getAttribute('source'),
                        $value,
                        (string)$cell->getAttribute('target'),
                    ];
                }
            }
            // Nodi "rich": <object label="..." id="..."><mxCell vertex="1"/></object>
            // — l'id logico (referenziato dagli archi) sta sull'object, il value
            // dell'mxCell interno è vuoto.
            foreach ($doc->getElementsByTagName('object') as $obj) {
                /** @var \DOMElement $obj */
                $id = (string)$obj->getAttribute('id');
                $label = $clean($obj->getAttribute('label'));
                if ($id !== '' && $label !== '') {
                    $nodes[$id] = $label;
                }
            }
        }
        \libxml_clear_errors();
        \libxml_use_internal_errors($prev);

        if ($nodes === [] && $edges === []) {
            return '';
        }

        $h  = '<details class="fm-mappa-alt" style="margin:6px 0 0;border:1px solid #d9dee6;border-radius:6px;background:#fafbfc">';
        $h .= '<summary style="cursor:pointer;padding:8px 12px;font-weight:600;color:#1f2937">'
            . '📄 Versione testuale accessibile della mappa</summary>';
        $h .= '<div class="fm-mappa-alt__body" style="padding:4px 16px 12px">';

        if ($nodes !== []) {
            $h .= '<p style="margin:6px 0 2px;font-weight:600;color:#475569">Concetti (' . \count($nodes) . ')</p><ul style="margin:0 0 8px;padding-left:20px">';
            foreach ($nodes as $label) {
                $h .= '<li>' . $esc($label) . '</li>';
            }
            $h .= '</ul>';
        }

        // Relazioni leggibili: "A → (etichetta) → B" (solo se entrambi i nodi
        // hanno testo, altrimenti l'arco non è descrivibile a parole).
        $rel = [];
        foreach ($edges as [$from, $lbl, $to]) {
            $a = $nodes[$from] ?? '';
            $b = $nodes[$to] ?? '';
            if ($a === '' || $b === '') {
                continue;
            }
            $rel[] = $lbl !== '' ? ($a . ' → ' . $lbl . ' → ' . $b) : ($a . ' → ' . $b);
        }
        if ($rel !== []) {
            $h .= '<p style="margin:6px 0 2px;font-weight:600;color:#475569">Relazioni (' . \count($rel) . ')</p><ul style="margin:0;padding-left:20px">';
            foreach ($rel as $line) {
                $h .= '<li>' . $esc($line) . '</li>';
            }
            $h .= '</ul>';
        }

        $h .= '</div></details>';
        return $h;
    }

    /**
     * Phase 18 — render dedicato per content_type=mappa: iframe Google
     * Drawio full-width, senza upbar/header_page/fm-draggable-container
     * (read-only, niente CRUD da fare).
     */
    private function renderMappaTopicHtml(array $params, string $topic, array $rows): string
    {
        $esc = static fn(?string $s): string => \htmlspecialchars((string)$s, ENT_QUOTES);
        $h  = '<div class="fm-pagestyle fm-mappa-study">';
        // Phase 20 — breadcrumb inline (topic · type · subj · ind/cls)
        // rimosso: l'evidenziazione dell'item aperto avviene via highlight
        // nella sidepage (data-content-id match sul .fm-contract-wrap /
        // .fm-mappa-wrap visibile a pagina).
        if (!$rows) {
            $h .= '<p class="fm-muted" style="padding:10px">Nessuna mappa in questo topic.</p>';
            $h .= '</div>';
            return $h;
        }
        // Phase G7 — render mappe con viewer.diagrams.net?lightbox + XML
        // INLINE compresso (gzdeflate + base64) via fragment #R. Vantaggi:
        //   - lightbox toolbar (page nav, zoom, copia, fit, edit pencil)
        //   - no fetch URL (no mixed-content http/https, no CORS)
        //   - server e' single source of truth (blob locale cifrato)
        // Fallback per file > MAX_INLINE_BYTES (URL browser limit ~2MB):
        // embed mode + postMessage (perde lightbox toolbar ma carica
        // file grandi).
        $blobStore = new \App\Services\Maps\MapBlobStore();
        $inlineBlobs = [];                    // id => xml plaintext (per fallback embed)
        $MAX_FRAGMENT_BYTES = 800 * 1024;     // ~800KB compressed → ~1.1MB URL

        foreach ($rows as $r) {
            $rid  = (int)$r['id'];
            $full = $this->repo->find($rid) ?: $r;
            $blobPath = (string)($full['map_blob_path'] ?? '');
            $ownerTid = (int)($full['teacher_id'] ?? 0);

            if ($blobPath === '' || $ownerTid <= 0) {
                // Orphan: blob mancante (drawio_id 404 o legacy fake id).
                $h .= '<div class="fm-mappa-wrap fm-mappa-orphan" data-id="' . $rid . '"'
                    . ' style="margin:10px;padding:14px;border:1px dashed #c2c2c2;border-radius:6px;background:#fafafa">';
                $h .= '<div class="fm-titolo-quesito" style="font-weight:bold;margin-bottom:4px">'
                    . $esc($r['topic']) . ' — ' . $esc($r['title']) . '</div>';
                $h .= '<div class="fm-muted" style="font-size:13px">⚠ Mappa non disponibile localmente (orphan).</div>';
                $h .= '<div class="fm-muted" style="font-size:11px;margin-top:6px">Il file originale su Drive non e\' stato trovato durante la migrazione. '
                    . 'Per ripristinarla: docente carica il drawio dal proprio archivio via "Crea mappa → Carica file".</div>';
                $h .= '</div>';
                continue;
            }

            try {
                $xml = $blobStore->get($ownerTid, $blobPath);
            } catch (\Throwable $e) {
                error_log("ContentStudyController.mappa blob load fail id=$rid: " . $e->getMessage());
                $h .= '<div class="fm-mappa-wrap fm-error" data-id="' . $rid . '">'
                    . $esc($r['topic']) . ' — ' . $esc($r['title']) . ' '
                    . '<span class="fm-muted">(errore lettura blob)</span></div>';
                continue;
            }

            // Strategia render (in ordine di preferenza per preservare la
            // lightbox toolbar - page nav, zoom, copia, edit pencil):
            //   1. viewer + #R fragment (gzdeflate+base64) — file piccoli
            //   2. viewer + #U signed URL — solo se APP_URL e' HTTPS (no
            //      mixed-content). Su dev http questo branch e' skippato.
            //   3. embed + postMessage — fallback dev http file grandi
            //      (perde la lightbox toolbar).
            $appUrl = rtrim((string)\App\Core\Config::get('app.url', ''), '/');
            $appIsHttps = \str_starts_with($appUrl, 'https://');
            $title = (string)$r['title'];

            // drawio bug: durante load XML via fragment #R, fa
            // decodeURIComponent su alcuni attribute values. Se il testo
            // contiene "%X" dove X non e' hex valido (es. "%;", "% ", "%&")
            // → "URI malformed" exception.
            // Fix: escape preventivo dei "%" literal a "%25" (URI encoded).
            // Drawio decodifica via decodeURIComponent → torna al "%"
            // originale. Le sequenze gia' valide "%XX" sono preservate
            // (lookahead negativo). Save round-trip OK perche' drawio
            // ri-encodifica quando salva.
            $xmlSafe    = (string)\preg_replace('/%(?![0-9A-Fa-f]{2})/', '%25', $xml);
            $compressed = @\gzdeflate($xmlSafe, 9);
            $fragment   = $compressed !== false ? \base64_encode($compressed) : '';

            if ($fragment !== '' && \strlen($fragment) <= $MAX_FRAGMENT_BYTES) {
                // 1. Fragment inline. drawio Editor.parseDiagramNode applica
                // decodeURIComponent sul fragment dopo "#R" prima del
                // base64 decode + inflate. I caratteri base64 +/= a volte
                // causano "URI malformed". Fix: rawurlencode del fragment
                // → decodeURIComponent ripristina base64 standard.
                $src = 'https://viewer.diagrams.net/?lightbox=1&dark=0&nav=1'
                     . '&title=' . \rawurlencode($title)
                     . '#R' . \rawurlencode($fragment);
                $mode = 'viewer-fragment';
            } elseif ($appIsHttps) {
                // 2. Signed URL fetch (HTTPS only).
                $signer = new \App\Services\Maps\MapSignedUrlService();
                $blobUrl = $appUrl . $signer->mint($rid, 'view', 3600);
                $src = 'https://viewer.diagrams.net/?lightbox=1&dark=0&nav=1'
                     . '&title=' . \rawurlencode($title)
                     . '#U' . \rawurlencode($blobUrl);
                $mode = 'viewer-url';
            } else {
                // 3. Fallback embed dev http.
                $inlineBlobs[$rid] = $xml;
                $h .= '<div class="fm-mappa-wrap" data-id="' . $rid . '" data-fm-mode="embed-blob" style="margin:10px">';
                $h .= '<div class="fm-titolo-quesito" style="font-weight:bold;margin-bottom:6px">'
                    . $esc($r['topic']) . ' — ' . $esc($title) . '</div>';
                // viewer.diagrams.net + lightbox + embed proto: combo
                // sperimentale per ottenere la lightbox toolbar (page nav,
                // zoom) ANCHE per file grandi caricati via postMessage XML
                // inline (evitando il limite URL fragment ~800KB).
                $h .= '<iframe class="fm-mappa-iframe" id="fm-mappa-iframe-' . $rid . '"'
                    . ' title="Mappa concettuale: ' . $esc($title) . '"'
                    . ' src="https://viewer.diagrams.net/?lightbox=1&embed=1&proto=json&dark=0&nav=1"'
                    . ' loading="lazy" allow="fullscreen"'
                    . ' style="width:100%;height:620px;border:1px solid #ccc;border-radius:4px"></iframe>';
                $h .= $this->mapTextAlternative($xml);
                $h .= '</div>';
                continue;
            }

            // Phase G7 — link "Modifica copia": apre app.diagrams.net in
            // NUOVA FINESTRA con la mappa pre-caricata via fragment #R
            // (gzdeflate+base64 inline). L'utente edita li' nativamente
            // e usa il save/save-as standard di drawio per scaricare o
            // salvare su Drive personale. NESSUN salvataggio sul nostro
            // server — l'originale del docente proprietario resta
            // intatto a prescindere.
            //
            // $fragment riusato: e' gia' calcolato sopra per il viewer
            // mode 1 (deflate+base64). Disponibile sempre se compressed
            // size OK (<800KB). Per file grandi: fallback download
            // diretto del XML come .drawio (l'utente apre poi in app
            // drawio desktop o caricalo su drawio.com manualmente).
            $h .= '<div class="fm-mappa-wrap" data-id="' . $rid . '" data-fm-mode="' . $mode . '" style="margin:10px">';
            $h .= '<div class="fm-titolo-quesito" style="font-weight:bold;margin-bottom:6px">'
                . $esc($r['topic']) . ' — ' . $esc($title) . '</div>';
            $h .= '<iframe class="fm-mappa-iframe"'
                . ' title="Mappa concettuale: ' . $esc($title) . '"'
                . ' src="' . $esc($src)
                . '" loading="lazy" allow="fullscreen"'
                . ' style="width:100%;height:620px;border:1px solid #ccc;border-radius:4px"></iframe>';
            $h .= $this->mapTextAlternative($xml);
            // Phase G7 — bottoni "Modifica copia" + "Scarica .drawio".
            // Per file piccoli (fragment OK): link diretto ad
            // app.diagrams.net?#R<encoded> in nuova finestra. Drawio
            // Editor.parseDiagramNode decodifica via decodeURIComponent
            // → atob → inflate. rawurlencode garantisce che il base64
            // standard (+/=) non confonda decodeURIComponent.
            // Per file grandi: solo download Blob locale (l'utente apre
            // il .drawio su drawio.com via File → Open o drag-and-drop).
            //
            // inlineBlobs sempre popolato per supportare il download
            // anche quando il viewer mode usa fragment/signed URL.
            $inlineBlobs[$rid] = $xml;
            $h .= '<div class="fm-mappa-actions" style="margin-top:6px;text-align:right;display:flex;gap:6px;justify-content:flex-end">';
            if ($fragment !== '' && \strlen($fragment) <= $MAX_FRAGMENT_BYTES) {
                $forkUrl = 'https://app.diagrams.net/?src=about&title='
                         . \rawurlencode($title) . '#R' . \rawurlencode($fragment);
                $h .= '<a class="fm-btn fm-btn--ghost fm-btn--sm" target="_blank" rel="noopener"'
                    . ' href="' . $esc($forkUrl) . '"'
                    . ' title="Apre app.diagrams.net in nuova finestra con una copia modificabile.'
                    . ' Usa File → Save / Save as nel menu drawio per scaricare il file modificato.">'
                    . '📝 Modifica copia su drawio.com ↗</a>';
            }
            $h .= '<button type="button" class="fm-btn fm-btn--ghost fm-btn--sm fm-mappa-download-btn"'
                . ' data-fm-content-id="' . $rid . '"'
                . ' title="Scarica il file .drawio sul tuo dispositivo. Aprilo su https://app.diagrams.net via File → Open o drag-and-drop.">'
                . '📥 Scarica .drawio</button>';
            $h .= '</div>';
            $h .= '</div>';
        }

        // Listener globale per i bottoni "Scarica .drawio" (file grandi
        // dove URL fragment supera il limit). Window.FM.DrawioEditor
        // espone una helper per il blob download.
        if (!\str_contains($h, '__fmMappaDownloadBound')) {
            $jsBlobsForDownload = $inlineBlobs !== []
                ? \json_encode($inlineBlobs, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
                : '{}';
            $h .= '<script>(function(){'
                . 'if(window.__fmMappaDownloadBound)return;'
                . 'window.__fmMappaDownloadBound=true;'
                . 'var blobsRaw=' . $jsBlobsForDownload . ';'
                . 'document.addEventListener("click",function(e){'
                . 'var btn=e.target.closest&&e.target.closest(".fm-mappa-download-btn");'
                . 'if(!btn)return;'
                . 'e.preventDefault();'
                . 'var id=btn.dataset.fmContentId;'
                . 'var xml=blobsRaw[id];'
                . 'if(!xml){alert("XML non disponibile in pagina (file grande in dev http).");return;}'
                . 'var blob=new Blob([xml],{type:"application/xml"});'
                . 'var url=URL.createObjectURL(blob);'
                . 'var a=document.createElement("a");'
                . 'var name=btn.closest(".fm-mappa-wrap").querySelector(".fm-titolo-quesito").textContent.replace(/[\\\\\\/:*?"<>|]+/g,"_").trim().slice(0,80)||"mappa";'
                . 'a.href=url;a.download=name+".drawio";'
                . 'document.body.appendChild(a);a.click();a.remove();'
                . 'setTimeout(function(){URL.revokeObjectURL(url);},1000);'
                . '});'
                . '})();</script>';
        }

        // Inject script SOLO se ci sono fallback embed con XML inline.
        if ($inlineBlobs !== []) {
            $jsBlobs = \json_encode($inlineBlobs, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $h .= '<script>(function(){'
                . 'var blobs=' . $jsBlobs . ';'
                . 'window.addEventListener("message",function(e){'
                . 'try{var d=JSON.parse(e.data);}catch(_){return;}'
                . 'if(d&&d.event==="init"){'
                . 'for(var id in blobs){'
                . 'var fr=document.getElementById("fm-mappa-iframe-"+id);'
                . 'if(fr&&fr.contentWindow===e.source){'
                . 'fr.contentWindow.postMessage(JSON.stringify({action:"load",xml:blobs[id],autosave:0}),"*");'
                . 'break;}}}});})();</script>';
        }
        $h .= '</div>';
        return $h;
    }

    /**
     * Phase 24.35 — Estrai PT AST da row.metadata.body_pt. La query base
     * `repo->search` NON include metadata_json (perf), quindi se non c'è
     * fa lookup via find($id) per recuperarlo.
     */
    private function extractBodyPt(array $row, bool $allowPlaceholder = false): ?array
    {
        $meta = $row['metadata'] ?? null;
        if (!\is_array($meta)) {
            $raw = $row['metadata_json'] ?? null;
            if (\is_string($raw) && $raw !== '') {
                $meta = \json_decode($raw, true);
            }
        }
        // Se search query non ha metadata, fai full lookup
        if (!\is_array($meta) && isset($row['id'])) {
            $full = $this->repo->find((int)$row['id']);
            if ($full) {
                $raw = $full['metadata_json'] ?? null;
                if (\is_string($raw) && $raw !== '') {
                    $meta = \json_decode($raw, true);
                }
            }
        }
        if (!\is_array($meta)) {
            return null;
        }
        $pt = $meta['body_pt'] ?? null;
        if (!\is_array($pt) || \count($pt) === 0) {
            return null;
        }
        $first = $pt[0] ?? null;
        if (!\is_array($first) || empty($first['_type'])) {
            return null;
        }
        // G22.S22 — Se body_pt è solo placeholder (sectionHeader + block
        // vuoti, niente problem-group ne testo non-vuoto), ritorna null per
        // far fallback al contract.json. Evita "schermata vuota" quando il
        // metadata contiene solo lo scheletro "Esercizi per studenti / Verifiche".
        // ADR-024 — il path CUSTOM passa $allowPlaceholder=true: il body_pt È il
        // documento (anche se è solo "sectionHeader + corpo vuoto" appena creato)
        // → va sempre reso col componente per l'editing, mai fallback contract.
        if (!$allowPlaceholder && $this->isPlaceholderPt($pt)) {
            return null;
        }
        return $pt;
    }

    /** True se il body_pt contiene solo sectionHeader + block con span vuoti. */
    private function isPlaceholderPt(array $pt): bool
    {
        foreach ($pt as $node) {
            if (!\is_array($node)) {
                continue;
            }
            $t = (string)($node['_type'] ?? '');
            if ($t === 'sectionHeader') {
                continue;
            }
            if ($t === 'block') {
                $children = $node['children'] ?? [];
                if (!\is_array($children)) {
                    return false;
                }
                foreach ($children as $c) {
                    if (\is_array($c) && trim((string)($c['text'] ?? '')) !== '') {
                        return false;
                    }
                }
                continue;
            }
            // Qualunque altro tipo (problem-group, risdoc-section, ecc.) = contenuto reale.
            return false;
        }
        return true;
    }

    /** Phase 24.45 — legge metadata.layout (exercises|custom) con full lookup fallback. */
    private function extractLayout(array $row): string
    {
        $meta = $row['metadata'] ?? null;
        if (!\is_array($meta)) {
            $raw = $row['metadata_json'] ?? null;
            if (\is_string($raw) && $raw !== '') {
                $meta = \json_decode($raw, true);
            }
        }
        if (!\is_array($meta) && isset($row['id'])) {
            $full = $this->repo->find((int)$row['id']);
            if ($full) {
                $raw = $full['metadata_json'] ?? null;
                if (\is_string($raw) && $raw !== '') {
                    $meta = \json_decode($raw, true);
                }
            }
        }
        if (!\is_array($meta)) {
            return '';
        }
        $l = $meta['layout'] ?? '';
        return \is_string($l) ? $l : '';
    }

    private function isCustomLayout(array $row): bool
    {
        return $this->extractLayout($row) === 'custom'
            && \is_array($this->extractBodyPt($row, true));
    }

    /** ADR-024 — legge metadata.render_mode (interactive|html), default interactive.
     *  Stesso full-lookup fallback di extractLayout: il $row del path studio non
     *  include sempre metadata_json, quindi se assente si rilegge via repo->find. */
    private function extractRenderMode(array $row): string
    {
        $m = $this->extractMeta($row)['render_mode'] ?? '';
        return $m === 'html' ? 'html' : 'interactive';
    }

    /** Metadata del content come array. Stesso full-lookup fallback di
     *  extractRenderMode/extractLayout: il $row del path studio non include
     *  sempre metadata_json, quindi se assente si rilegge via repo->find. */
    private function extractMeta(array $row): array
    {
        $meta = $row['metadata'] ?? null;
        if (!\is_array($meta)) {
            $raw = $row['metadata_json'] ?? null;
            if (\is_string($raw) && $raw !== '') {
                $meta = \json_decode($raw, true);
            }
        }
        if (!\is_array($meta) && isset($row['id'])) {
            $full = $this->repo->find((int)$row['id']);
            if ($full) {
                $raw = $full['metadata_json'] ?? null;
                if (\is_string($raw) && $raw !== '') {
                    $meta = \json_decode($raw, true);
                }
            }
        }
        return \is_array($meta) ? $meta : [];
    }

    /** Flag metadata.includeHeaderHtml (default true): controlla se l'intestazione
     *  istituto + selettori (primo sectionHeader-con-selectors del body_pt)
     *  compare nell'HTML statico pubblicato reso agli studenti. */
    private function extractIncludeHeaderHtml(array $row): bool
    {
        $meta = $this->extractMeta($row);
        if (!\array_key_exists('includeHeaderHtml', $meta)) {
            return true;
        }
        return \filter_var($meta['includeHeaderHtml'], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) !== false;
    }

    /**
     * Phase 24.45 — render layout=custom: pagina libera senza scaffolding
     * #header_page / .fm-draggable-container. PT body diretto in wrapper minimal
     * .fm-pt-custom-page (matcha la coppia .fm-risdoc-toolbar/.fm-risdoc-sticky-head
     * lato edit ma in render finale serve solo il body PT).
     */
    private function renderCustomTopicHtml(string $type, array $params, array $row): string
    {
        $esc = static fn(?string $s): string => htmlspecialchars((string)$s, ENT_QUOTES);
        $rid = (int)$row['id'];
        $ptBody = $this->extractBodyPt($row, true);
        // ADR-030 — doc "valori per terna": applica i valori 🔗 della terna
        // dell'URL (indirizzo/classe/materia) e RIMUOVE il blocco ternaStore
        // prima del render, così la vista studente/SSR mostra i valori giusti
        // per la combinazione. No-op per i doc non terna_scoped.
        //
        // Il flag `terna_scoped` è in metadata_json (PLAINTEXT, presente grazie a
        // with_metadata) → gating CHEAP: solo per i doc terna_scoped si fa la
        // find() dedicata per il body_pt DECIFRATO (search non lo espone in modo
        // affidabile). Per i doc normali: nessun decrypt extra.
        $metaPlain030 = \is_array($row['metadata'] ?? null) ? $row['metadata']
            : ((\is_string($row['metadata_json'] ?? null) && $row['metadata_json'] !== '')
                ? (\json_decode($row['metadata_json'], true) ?: []) : []);
        if (\App\Services\Risdoc\Pt\TernaBinding::isTernaScoped($metaPlain030)) {
            $full030 = $this->repo->find($rid);
            $bp030 = $full030['metadata']['body_pt'] ?? null;
            if (\is_array($bp030) && $bp030 !== []) {
                $ternaKey = ($params['ind'] ?? '') . '/' . ($params['cls'] ?? '') . '/' . ($params['subj'] ?? '');
                $ptBody = \App\Services\Risdoc\Pt\TernaBinding::applyAndStrip($bp030, $ternaKey);
            }
        }
        $canEdit = $this->userCanEdit();
        $title = (string)($row['title'] ?? 'Documento');
        $renderMode = $this->extractRenderMode($row);

        // 2026-05-30 — checkbox "Includi intestazione e selettori nell'HTML
        // statico pubblicato" (metadata.includeHeaderHtml, default true). Se off,
        // rimuove il primo blocco sectionHeader-con-selettori (l'intestazione
        // istituto + classe/indirizzo/disciplina) dal body reso ai lettori. Il
        // docente continua a vederla in edit: l'editor ricarica body_pt completo
        // via adapter, qui togliamo solo dal markup pubblicato.
        if (\is_array($ptBody) && $ptBody !== [] && !$this->extractIncludeHeaderHtml($row)) {
            $first = $ptBody[0] ?? null;
            if (
                \is_array($first)
                && (($first['_type'] ?? '') === 'sectionHeader')
                && \is_array($first['selectors'] ?? null)
            ) {
                \array_shift($ptBody);
            }
        }

        // G24 (ADR-022) — WebComponent unificato <fm-pt-document>: consolida
        // view/edit/export (JSON/TeX/HTML) in un componente coeso. SSR-first:
        // il body HTML (PtToHtml) è pre-renderizzato DENTRO il tag con
        // data-ptdoc-ssr → no-flash + graceful degradation se JS off.
        // Il componente cattura quell'HTML per la view, carica body_pt lazy
        // solo per edit/export. Sostituisce il vecchio markup .fm-pt-rendered
        // + topbar buttons sparsi + pt-inline-editor.js.
        $bodyHtml = \App\Services\Risdoc\Pt\PtToHtml::render($ptBody ?? [], [
            'fields' => [],
            'state'  => [
                'classe'     => $params['cls']  ?? '',
                'sezione'    => '',
                'indirizzo'  => $params['ind']  ?? '',
                'disciplina' => $params['subj'] ?? '',
            ],
        ]);

        // ADR-024 — render_mode=html per studenti (no edit): vista "articolo"
        // sanitizzato puro, nessun componente. Il markup è identico a quello
        // che il componente mostrerebbe in view (stessi CSS _pt-page-doc.css).
        if ($renderMode === 'html' && !$canEdit) {
            $h  = '<div id="fm-upbar"></div>';
            $h .= '<div class="fm-pagestyle fm-pt-custom-page fm-pt-custom-page--html"'
                . ' data-layout="custom" data-render-mode="html"'
                . ' data-content-type="' . $esc($type) . '">';
            $h .= '<article class="ptdoc__body ptdoc__body--standalone">';
            if ($title !== '') {
                $h .= '<h2 class="ptdoc__title">' . $esc($title) . '</h2>';
            }
            $h .= $bodyHtml;
            $h .= '</article>';
            $h .= '</div>';
            return $h;
        }

        // ADR-024 — render_mode=html per docente: componente in vista HTML con
        // topbar per ri-switchare a interattivo. Altrimenti interattivo pieno.
        // 2026-05-27 — CENTRALIZZAZIONE: il documento custom usa la STESSA shell
        // dei modelli (`.fm-risdoc-view--unified` + script risdoc + body class
        // fm-studio-risdoc via wrapInShell) → stile, impaginazione e funzionalità
        // (navigator, sticky, export hooks) IDENTICI. Unica differenza:
        // source="teacher-content" vs "risdoc-template".
        // 2026-05-27 — niente #fm-upbar legacy sui doc custom: la topbar è la
        // <fm-doc-topbar> dentro <fm-pt-document> (come i modelli, che NON hanno
        // l'upbar). Evita la doppia barra in cima.
        $h  = '<div class="fm-risdoc-view fm-risdoc-view--unified fm-pt-custom-page" data-layout="custom"'
            . ' data-render-mode="' . $esc($renderMode) . '"'
            . ' data-content-type="' . $esc($type) . '">';
        $h .= '<fm-pt-document doc-id="' . $rid . '" source="teacher-content"'
            . ($canEdit ? ' can-edit="1"' : '')
            . ' render-mode="' . $esc($renderMode) . '"'
            . ' title="' . $esc($title) . '">';
        // SSR body (no-flash, graceful degradation). data-ptdoc-ssr =
        // hook per il componente che cattura solo questo HTML.
        $h .= '<div data-ptdoc-ssr>' . $bodyHtml . '</div>';
        $h .= '</fm-pt-document>';
        $h .= '</div>';
        // ADR-026 #3 — fm-risdoc-export.js + fm-risdoc-toolbar-actions.js
        // ELIMINATI (cleanup post engine-delete: toolbar/save/export ora resi
        // da fm-pt-document._topbarButtons internamente). Section-navigator
        // resta come modulo standalone (UI navigazione sezioni).
        $h .= '<script src="/js/components/risdoc/fm-risdoc-section-navigator.js"></script>';
        return $h;
    }

    /**
     * Pagina dedicata "nessun documento" per i tipi documento (risdoc/bes)
     * quando la combinazione indirizzo/classe/materia/topic non ha un documento.
     * Evita la pagina generica esercizi (disclaimer + "Nessun item"), che su una
     * pagina-documento confonde l'utente.
     */
    private function renderDocEmptyHtml(string $type, array $params, string $topic): string
    {
        $esc = static fn($s): string => htmlspecialchars((string)$s, ENT_QUOTES);
        $ind  = $esc($params['ind']  ?? '');
        $cls  = $esc($params['cls']  ?? '');
        $subj = $esc($params['subj'] ?? '');
        $tp   = $esc($topic);
        $kind = $type === 'bes' ? 'documento BES/DSA' : 'documento';
        $muted = 'color:var(--fm-text-muted,#9fb0c8);';
        // NB: niente <div id="fm-upbar"> qui → wrapInShell non inietta la toolbar
        // verifica/documento (la pagina "nessun documento" deve restare pulita).
        $h  = '<div class="fm-pagestyle fm-doc-empty" style="display:flex;justify-content:center;padding:48px 16px;">';
        $h .= '<div style="max-width:560px;text-align:center;background:var(--fm-card-bg,#1b2230);border:1px solid var(--fm-border,#33415a);border-radius:12px;padding:28px 26px;">';
        $h .= '<div style="font-size:40px;line-height:1;margin-bottom:10px;">📄</div>';
        $h .= '<h2 style="margin:0 0 10px;font-size:18px;">Nessun ' . $kind . ' per questa combinazione</h2>';
        $h .= '<p style="margin:0 0 14px;' . $muted . '">Non esiste un ' . $kind . ' (topic <strong>' . $tp . '</strong>) per:<br>'
            . '<strong>indirizzo ' . $ind . ' · classe ' . $cls . ' · materia ' . $subj . '</strong>.</p>';
        $h .= '<p style="margin:0;' . $muted . 'font-size:13px;">Imposta indirizzo/classe/materia nella barra laterale sulla combinazione giusta per aprire un '
            . $kind . ' esistente, oppure crealo dalla sezione <strong>Risorse docente</strong> (pulsante <strong>+</strong>).</p>';
        $h .= '</div></div>';
        return $h;
    }

    private function renderTopicHtml(string $type, array $params, string $topic, array $rows): string
    {
        $esc = static fn(?string $s): string => htmlspecialchars((string)$s, ENT_QUOTES);
        // Phase 18 — formato 'map' (mappe): render iframe pulito senza
        // upbar/header_page. ADR-027 — branch su FORMATO, non sul nome-tipo.
        if (TeacherContentRepository::formatOf($type) === 'map') {
            return $this->renderMappaTopicHtml($params, $topic, $rows);
        }
        // Tipi DOCUMENTO (risdoc/bes): se NON c'è un documento per questa
        // combinazione indirizzo/classe/materia/topic, mostra una pagina DEDICATA
        // "nessun documento" invece della pagina generica esercizi (disclaimer
        // esercizi + "Nessun item" → confonde l'utente su una pagina-documento).
        if (\count($rows) === 0 && \in_array($type, ['risdoc', 'bes'], true)) {
            return $this->renderDocEmptyHtml($type, $params, $topic);
        }
        // Phase 24.45 — layout=custom (PT-libero): scaffolding minimo, no
        // <div id="header_page">, no .fm-draggable-container. La struttura del
        // documento è interamente nel body_pt costruito via PT editor.
        // Solo single-row teacher_content (caso /studio/{type}/.../{topic}).
        if (\count($rows) === 1 && $this->isCustomLayout($rows[0])) {
            return $this->renderCustomTopicHtml($type, $params, $rows[0]);
        }
        $h  = '<div id="fm-upbar"></div>';
        $h .= '<div id="header_page">';
        $h .= '<div class="fm-header-body"></div>';
        $h .= '<div class="fm-source-citation"></div>';
        $h .= '</div>';
        $h .= '<div class="fm-pagestyle fm-db-study">';
        // Phase 20 — breadcrumb inline (topic · type · subj · ind/cls)
        // rimosso: il titolo del contract è già nel `.fm-contract-wrap`
        // (ContractRenderer) e l'evidenziazione dell'item aperto avviene
        // via highlight nella sidepage (vedi sidepage-highlight.js).
        $h .= '<div class="fm-draggable-container" data-db-backed="1" data-content-type="' . $esc($type) . '">';
        if (!$rows) {
            $h .= '<p class="fm-muted">Nessun item in questo topic.</p>';
        } else {
            // Phase 16 — render via ContractRepository (centralizza letture
            // + espone version per optimistic locking lato client).
            $instituteId = (int)($params['institute_id'] ?? 0);
            $teacherId   = (int)($params['teacher_id']   ?? 0);
            // Phase 25.Q.8 — guard scope: solo teacher/admin vedono edit controls.
            $canEdit = $this->userCanEdit();
            $renderer = \App\Services\ContractRenderer::loadSourcesFor($instituteId, $teacherId, $canEdit);
            $contractRepo = \App\Services\Contract\ContractRepository::default();

            foreach ($rows as $r) {
                $rid = (int)$r['id'];

                // Phase 24.35 — render PT AST (metadata.body_pt) se presente.
                // Priorità: contract → PT body → legacy body_html.
                $ptBody = $this->extractBodyPt($r);
                if (\is_array($ptBody) && \count($ptBody) > 0) {
                    $ptKind = (string)($r['content_type'] ?? $type);
                    $ptKindAttr = in_array($ptKind, ['verifica', 'esercizio'], true)
                        ? ' data-kind="' . $ptKind . '"' : '';
                    $h .= '<div class="fm-contract-wrap fm-pt-rendered" data-id="' . $rid . '"' . $ptKindAttr . ' data-source="pt">';
                    $h .= '<h3 class="fm-pt-item-title">' . $esc((string)$r['title']) . '</h3>';
                    $h .= \App\Services\Risdoc\Pt\PtToHtml::render($ptBody, [
                        'fields' => [],
                        'state'  => [
                            'classe'     => $params['cls']  ?? '',
                            'sezione'    => '',
                            'indirizzo'  => $params['ind']  ?? '',
                            'disciplina' => $params['subj'] ?? '',
                        ],
                    ]);
                    $h .= '</div>';
                    continue;
                }

                $agg = $contractRepo->load($rid);
                if ($agg) {
                    // Wrapper:
                    //   - data-kind abilita lo sticky stacking legacy. Phase 24.77 —
                    //     esteso anche a 'esercizio' (prima solo verifica) così i
                    //     .fm-groupcollex aperti restano sticky sotto .fm-titolo.
                    //   - data-version propaga la version per If-Match sul save.
                    // Phase 17 — rimosso il label .collex-pick (funzionalità
                    // superflua sovrapposta al flusso tipoEsercizio_ver).
                    // Fix clone verifica→esercizio: il data-kind abilita il gate
                    // JS del clone (checkin-handlers `inVerifica`). Derivalo dal
                    // content_type REALE della riga, non dall'URL $type (che può
                    // essere una sezione risdoc/bes → in passato bloccava il clone).
                    $rowKind = (string)($r['content_type'] ?? $type);
                    $kind = in_array($rowKind, ['verifica', 'esercizio'], true)
                        ? ' data-kind="' . $rowKind . '"' : '';
                    $h .= '<div class="fm-contract-wrap" data-id="' . $rid . '"'
                       . $kind . ' data-version="' . $agg->version() . '">';
                    $h .= $renderer->renderContract($agg->data());
                    $h .= '</div>';
                    continue;
                }
                // Phase 17 — DEPRECATED body_html fallback.
                // Zero righe live ne dipendono (verificato da
                // tools/audit_legacy_body_html.php). Manteniamo il path solo
                // come safety net: se un admin importa legacy data, non
                // crashiamo, ma logghiamo un warning strutturato.
                $full = $this->repo->find($rid);
                $body = (string)($full['body_html'] ?? '');
                if ($body !== '') {
                    error_log(sprintf(
                        '[deprecated] body_html fallback used for teacher_content.id=%d (no contract_key). '
                        . 'Run tools/audit_legacy_body_html.php to identify + migrate.',
                        $rid
                    ));
                }
                $h .= '<div class="fm-contract-fallback fm-collection__item" data-id="' . $rid . '" data-legacy="1">';
                $h .= '<div class="fm-titolo-quesito">#' . $rid . ' · ' . $esc($r['title']) . '</div>';
                // Hardening (audit 2026-06-14, FND-007): sink XSS latente sul
                // fallback deprecato body_html — sanitizza comunque (HTMLPurifier)
                // anche se "zero righe live" lo raggiungono. Defense-in-depth.
                $h .= '<div class="fm-collection">' . \App\Services\Security\HtmlSanitizer::forBlockContent($body) . '</div></div>';
            }
        }
        $h .= '</div></div>';
        return $h;
    }

    private function wrapInShell(Request $req, string $body, string $title, ?string $type = null): Response
    {
        // Popola #fm-upbar (server-rendered) via _upbar_loader.
        $base = dirname(__DIR__, 2);
        $upbarPath = $base . '/views/partials/_upbar_loader.php';
        if (is_file($upbarPath) && preg_match('#<div\s+id=["\']fm-upbar["\']\s*>\s*</div\s*>#i', $body)) {
            ob_start();
            require $upbarPath;
            $upbarHtml = (string)ob_get_clean();
            $body = preg_replace(
                '#<div\s+id=["\']fm-upbar["\']\s*>\s*</div\s*>#i',
                '<div id="fm-upbar">' . $upbarHtml . '</div>',
                $body,
                1,
            ) ?? $body;
        }

        $isPartial = ($_SERVER['HTTP_X_PARTIAL'] ?? '') === '1';
        if ($isPartial) {
            return new Response($body, 200, ['Content-Type' => 'text/html; charset=UTF-8']);
        }

        $pageTitle    = 'PANTEDU — ' . $title;
        $pageContent  = $body;
        // Phase 15 — body.fm-admin-access abilita le regole CSS legacy (#sel-origin,
        // #toggle-checkboxABin-control, #analytics-btn, [id^=type_verAll]) che
        // sono gated da `body.fm-admin-access` oltre a `.fm-upbar.fm-admin-access`.
        // Phase 18 — formato 'map': NO exercise-context (evita padding-top:95px
        // per upbar fixed). ADR-027 — branch su FORMATO.
        if (TeacherContentRepository::formatOf($type) === 'map') {
            $bodyClass = 'fm-mappa-view fm-studio-light';
        } elseif (str_contains($body, 'fm-doc-empty')) {
            // Pagina dedicata "nessun documento": shell PULITA, niente
            // exercise-context (toolbar verifica + padding upbar fixed).
            $bodyClass = 'fm-studio-light';
        } elseif (str_contains($body, '<fm-pt-document ')) {
            // NB lo SPAZIO finale è voluto: l'elemento reale è
            // `<fm-pt-document doc-id=...` (con attributi), mentre il COMMENTO in
            // views/partials/_topbar_modern.php contiene la stringa
            // `<fm-pt-document>` (senza spazio) → senza lo spazio lo str_contains
            // matchava il commento e dava la shell documento alle pagine ESERCIZIO
            // (topbar "DOCUMENTO" + UI esercizio mancante). Vedi fix regressione.
            // 2026-05-27 — CENTRALIZZAZIONE: la pagina di un documento custom
            // (fm-pt-document) usa lo STESSO body class dei modelli
            // (fm-studio-risdoc) → stesse regole CSS scoped + chrome/impaginazione.
            $bodyClass = 'fm-studio-risdoc fm-studio-light';
        } else {
            // G-fix-css-rename — emette SIA legacy SIA prefisso BEM per match
            // con regole CSS Sprint K-N (admin-access → fm-admin-access etc).
            $bodyClass = 'exercise-context fm-exercise-context fm-studio-light';
        }
        if (class_exists(Auth::class) && Auth::check() && Auth::hasAccess('admin')) {
            $bodyClass .= ' admin-access fm-admin-access';
        }
        // Body class per docenti (teacher e oltre): abilita le UI di
        // produzione verifica come `.dsa-wrapper-container` inline e
        // checkbox DSA nei `<li>` della traccia. Studenti NON la ricevono.
        if (class_exists(Auth::class) && Auth::check() && Auth::hasAccess('teacher')) {
            $bodyClass .= ' fm-teacher-access';
        }
        $currentRoute = $req->path;
        // Phase 16 — TikZJax ora serve anche per /studio/: i contract possono
        // contenere blocchi TikZ (renderizzati da ContractRenderer come
        // <script type="text/tikz">) e la preview in edit mode usa
        // window.process_tikz per renderizzare le modifiche live.
        $fmExerciseAssetsTier1 = false;
        $pageHead     = '';
        ob_start();
        include $base . '/views/layout/app.php';
        return new Response((string)ob_get_clean(), 200, ['Content-Type' => 'text/html; charset=UTF-8']);
    }

    // ─────── Helpers ───────

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

    /**
     * ADR-027 Step 8 — section_id delle sezioni NON visibili agli studenti
     * per l'istituto dello studente corrente. Usato per escludere i loro
     * contenuti da liste e pagine (anche via URL diretto).
     *
     * @return list<int>
     */
    private function hiddenSectionIdsForStudent(): array
    {
        try {
            $u = Auth::user();
            $uid = (int)($u['id'] ?? 0);
            if ($uid <= 0) {
                return [];
            }
            $stmt = Database::connection()->prepare('SELECT institute_id FROM users WHERE id=? LIMIT 1');
            $stmt->execute([$uid]);
            $iid = (int)$stmt->fetchColumn();
            $hidden = [];
            foreach ((new \App\Repositories\SidebarSectionRepository())->resolveFor($iid, null) as $s) {
                if (!in_array('student', $s['visible_roles'], true)) {
                    $hidden[] = (int)$s['id'];
                }
            }
            return $hidden;
        } catch (\Throwable $e) {
            return [];
        }
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
        $u = \App\Core\Auth::user();
        return $u ? $this->resolveUserId((string)($u['username'] ?? '')) : 0;
    }

    private function firstInstituteId(int $teacherId): int
    {
        return \App\Support\TeacherContextResolver::firstInstituteId($teacherId);
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

    private function domainUser(array $a): \App\Domain\User
    {
        return new \App\Domain\User(
            username:     (string)($a['username'] ?? ''),
            passwordHash: '',
            role:         (string)($a['role']     ?? 'guest'),
            active:       true,
            course:       $a['course'] ?? null,
        );
    }
}
