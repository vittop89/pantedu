<?php

namespace App\Controllers;

use App\Core\Config;
use App\Core\Database;
use App\Core\Request;
use App\Core\Response;
use App\Repositories\TeacherContentRepository;
use App\Services\Study\PublicContentPolicy;
use App\Services\Study\StudyPageRenderer;

/**
 * Studio pubblico (WS4): la superficie esposta ai guest, senza sessione.
 *
 * Estratto da ContentStudyController il 2026-09-04 (revisione architetturale,
 * intervento P5; ADR-029): le tre rotte pubbliche stanno in un file solo, e
 * chi le legge non deve distinguerle dalle rotte autenticate. Servono
 * ESCLUSIVAMENTE i contenuti `published` del super-admin docente nelle
 * sezioni publish_public (regola in PublicContentPolicy), ignorando del tutto
 * la sessione: un docente autenticato che le chiama ottiene comunque solo i
 * contenuti pubblici, mai i propri draft o quelli di colleghi.
 *
 * Route:
 *   GET /public/studio/{id}              → vista read-only di UN contenuto
 *   GET /api/public/study/topics.json    → JSON topic pubblici
 *   GET /api/public/study/content.json   → JSON contenuti pubblici
 *
 * Helper copiati dal controller autenticato (ADR-029 accetta la copia dei
 * piccoli helper): groupByTopic, validType, dbReady, userCanEdit (che qui e'
 * sempre false per i guest, ma resta la stessa decisione). Il calcolo di
 * has_body_pt e doc_roles non è più una copia: sta in App\Support\RigheDellaBarra
 * (19/9/2026), perché le copie davano risposte diverse per la stessa riga.
 */
final class PublicStudyController
{
    private TeacherContentRepository $repo;

    private ?StudyPageRenderer $renderer = null;

    public function __construct(?TeacherContentRepository $repo = null)
    {
        $this->repo = $repo ?? new TeacherContentRepository();
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
        $filters = PublicContentPolicy::scopedFilters([
            'ind'  => $req->query['ind']     ?? $req->query['indirizzo'] ?? null,
            'cls'  => $req->query['cls']     ?? $req->query['classe']    ?? null,
            'subj' => $req->query['subject'] ?? null,
        ], $type) + ['limit' => 500];
        if (PublicContentPolicy::isDeny($filters)) {
            return Response::json(['ok' => true, 'topics' => []]);
        }
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
        $params = [
            'ind'   => $req->query['ind']     ?? $req->query['indirizzo'] ?? null,
            'cls'   => $req->query['cls']     ?? $req->query['classe']    ?? null,
            'subj'  => $req->query['subject'] ?? null,
            'topic' => $req->query['topic']   ?? null,
        ];
        // La sezione vince sul tipo, come per gli studenti (ContentStudyController):
        // la sidepage dei visitatori chiede per sezione (16/9/2026).
        $scope = $sectionKey !== ''
            ? PublicContentPolicy::scopedFiltersPerSezione($params, $sectionKey)
            : PublicContentPolicy::scopedFilters($params, (string)$type);
        if (PublicContentPolicy::isDeny($scope)) {
            return Response::json(['ok' => true, 'count' => 0, 'rows' => []]);
        }
        $filters = $scope + [
            'limit'         => min(500, max(1, (int)($req->query['limit'] ?? 100))),
            'offset'        => max(0, (int)($req->query['offset'] ?? 0)),
            'with_metadata' => 1,
        ];
        $rows = \App\Support\RigheDellaBarra::arricchisci($this->repo->search($filters));
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
        if (!PublicContentPolicy::isPublic($row)) {
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
        $body = $this->renderer()->renderTopicHtml($type, $rparams, $topic, [$row]);
        return $this->renderer()->wrapInShell($req, $body, (string)$row['title'], $type);
    }

    // ---- helper condivisi (copia da ContentStudyController, ADR-029) ----

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

    /** Rendering HTML (P5, 2026-09-04): dati dal controller, stringhe dal servizio. */
    private function renderer(): StudyPageRenderer
    {
        return $this->renderer ??= new StudyPageRenderer($this->repo, $this->userCanEdit());
    }

    private function validType(?string $t): ?string
    {
        if (in_array($t, TeacherContentRepository::TYPES, true)) {
            return $t;
        }
        if (in_array($t, PublicContentPolicy::SECTION_DOC_TYPES, true)) {
            return $t;
        }
        return null;
    }

    private function dbReady(): bool
    {
        return (bool)Config::get('database.enabled') && Database::isAvailable();
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
