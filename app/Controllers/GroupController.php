<?php

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Config;
use App\Core\Database;
use App\Core\Request;
use App\Core\Response;
use App\Repositories\TeacherContentRepository;
use Throwable;

/**
 * GroupController — estratto da TeacherContentController (ADR-029).
 * Metodi: groupMove, groupAdd, groupDelete, groupPatch.
 * Helper condivisi duplicati: teacherId, dbReady, firstInstituteId.
 */
final class GroupController
{
    private TeacherContentRepository $repo;

    public function __construct(?TeacherContentRepository $repo = null)
    {
        $this->repo = $repo ?? new TeacherContentRepository();
    }

    /**
     * Phase 17 — `POST /api/teacher/content/{id}/quesito/{itemRef}/clone-to-eser`.
     * Cross-file clone: verifica → esercizio corrispondente (match subject+topic).
     * Se l'eser ha un gruppo con stesso titolo → append item; altrimenti →
     * append nuovo gruppo clonato. Ritorna {eserContentId, groupId, newItemId, createdGroup}.
     */
    /**
     * Phase 17 — `POST /api/teacher/content/{id}/group/{groupRef}/move?to=N`.
     * Riordina un gruppo nel contract. Usato dal drag-drop su `.moveBtn`.
     * Optimistic lock via `If-Match: "v<N>"` o body `_version`.
     */
    public function groupMove(Request $req, array $params): Response
    {
        if (!$this->dbReady()) {
            return Response::json(['error' => 'db_unavailable'], 503);
        }
        $tid = $this->teacherId();
        if (!$tid) {
            return Response::json(['error' => 'unauthorized'], 401);
        }
        $contentId = (int)($params['id'] ?? 0);
        $groupRef = (string)($params['groupRef'] ?? '');
        if ($contentId <= 0 || $groupRef === '') {
            return Response::json(['error' => 'invalid_params'], 400);
        }
        $to = (int)($req->query['to'] ?? $req->post['to'] ?? -1);
        if ($to < 0) {
            return Response::json(['error' => 'missing_to'], 400);
        }
        $expected = $this->readExpectedVersion($req);
        $repo = \App\Services\Contract\ContractRepository::default();
        try {
            $agg = $repo->moveGroup($contentId, $tid, $groupRef, $to, $expected);
        } catch (\App\Services\Contract\ContractNotFoundException) {
            return Response::json(['error' => 'not_found'], 404);
        } catch (\App\Services\Contract\ContractItemNotFoundException) {
            return Response::json(['error' => 'group_not_found', 'groupRef' => $groupRef], 404);
        } catch (\App\Services\Contract\ContractVersionMismatchException $e) {
            return Response::json([
                'error' => 'version_conflict', 'expected' => $e->expected, 'actual' => $e->actual,
            ], 409);
        } catch (\Throwable $e) {
            return Response::json(['error' => 'internal'], 500);
        }
        $r = Response::json(['ok' => true, 'version' => $agg->version()]);
        $r->headers['ETag'] = '"v' . $agg->version() . '"';
        return $r;
    }

    /**
     * Phase 20 — POST /api/teacher/content/{id}/group/add
     * Aggiunge un nuovo group al contract (usato da tipoEsercizio post-
     * insert DOM). Body: type, title?, intro?, clientId?.
     * Optimistic locking via If-Match: "v<N>". Ritorna groupId server-
     * assigned per rimpiazzare l'id locale.
     */
    public function groupAdd(Request $req, array $params): Response
    {
        if (!$this->dbReady()) {
            return Response::json(['error' => 'db_unavailable'], 503);
        }
        $tid = $this->teacherId();
        if (!$tid) {
            return Response::json(['error' => 'unauthorized'], 401);
        }
        $contentId = (int)($params['id'] ?? 0);
        if ($contentId <= 0) {
            return Response::json(['error' => 'invalid_id'], 400);
        }

        $type     = (string)($req->post['type']     ?? 'Collect');
        $title    = (string)($req->post['title']    ?? '');
        $intro    = (string)($req->post['intro']    ?? '');
        $clientId = (string)($req->post['clientId'] ?? '');
        $expected = $this->readExpectedVersion($req);
        // Phase 20 — fallback title/intro dal template personale del docente
        // (se assente cade sul default hard-coded per type).
        if ($title === '') {
            $title = TemplateDefaults::titleForType($type, $tid);
        }
        if ($intro === '') {
            $intro = TemplateDefaults::introForType($type, $tid);
        }

        $repo = \App\Services\Contract\ContractRepository::default();
        try {
            $agg = $repo->loadForTeacher($contentId, $tid);
            if (!$agg) {
                return Response::json(['error' => 'not_found'], 404);
            }
            if ($expected !== null && $agg->version() !== $expected) {
                return Response::json([
                    'error' => 'version_conflict',
                    'expected' => $expected, 'actual' => $agg->version(),
                ], 409);
            }
            $groupId = $agg->appendGroup([
                'kind'  => 'problem-group',
                'type'  => $type,
                'title' => $title,
                'intro' => $intro,
                'items' => TemplateDefaults::itemsForType($type, $tid),
            ]);
            $repo->save($agg);
        } catch (\Throwable $e) {
            return Response::json(['error' => 'persist_failed'], 500);
        }

        // Phase 20 — risponde con l'HTML del gruppo appena inserito: il
        // client sostituisce il clone legacy del template con il render
        // server, ottenendo .checkIN + tutti i controlli coerenti.
        $groupHtml = '';
        foreach ($agg->groups() as $g) {
            if (($g['id'] ?? '') === $groupId) {
                $renderer = \App\Services\ContractRenderer::loadSourcesFor(
                    $this->firstInstituteId($tid) ?? 0,
                    $tid,
                );
                $groupHtml = $renderer->renderGroupPublic($g);
                break;
            }
        }

        $r = Response::json([
            'ok' => true,
            'groupId' => $groupId,
            'version' => $agg->version(),
            'clientId' => $clientId,
            'html' => $groupHtml,
        ]);
        $r->headers['ETag'] = '"v' . $agg->version() . '"';
        return $r;
    }

    /** Phase 20 — POST /api/teacher/content/{id}/group/{groupRef}/delete.
     *  Rimuove l'intero gruppo (con tutti gli items) dal contract JSON. */
    public function groupDelete(Request $req, array $params): Response
    {
        if (!$this->dbReady()) {
            return Response::json(['error' => 'db_unavailable'], 503);
        }
        $tid = $this->teacherId();
        if (!$tid) {
            return Response::json(['error' => 'unauthorized'], 401);
        }
        $contentId = (int)($params['id'] ?? 0);
        $groupRef = (string)($params['groupRef'] ?? '');
        if ($contentId <= 0 || $groupRef === '') {
            return Response::json(['error' => 'invalid_params'], 400);
        }
        $expected = $this->readExpectedVersion($req);
        try {
            $agg = \App\Services\Contract\ContractRepository::default()
                ->deleteGroup($contentId, $tid, $groupRef, $expected);
        } catch (\App\Services\Contract\ContractNotFoundException) {
            return Response::json(['error' => 'not_found'], 404);
        } catch (\App\Services\Contract\ContractItemNotFoundException) {
            return Response::json(['error' => 'group_not_found', 'groupRef' => $groupRef], 404);
        } catch (\App\Services\Contract\ContractVersionMismatchException $e) {
            return Response::json([
                'error' => 'version_conflict',
                'expected' => $e->expected,
                'actual'   => $e->actual,
            ], 409);
        } catch (\Throwable $e) {
            return Response::json(['error' => 'internal'], 500);
        }
        $r = Response::json(['ok' => true, 'version' => $agg->version()]);
        $r->headers['ETag'] = '"v' . $agg->version() . '"';
        return $r;
    }

    /** Phase 20 — POST /api/teacher/content/{id}/group/{groupRef}/patch.
     *  Merge-patch dei campi top-level di un gruppo (title, intro).
     *  groupRef accetta id UUID del gruppo o locator `g<N>`. */
    public function groupPatch(Request $req, array $params): Response
    {
        if (!$this->dbReady()) {
            return Response::json(['error' => 'db_unavailable'], 503);
        }
        $tid = $this->teacherId();
        if (!$tid) {
            return Response::json(['error' => 'unauthorized'], 401);
        }
        $contentId = (int)($params['id'] ?? 0);
        $groupRef = (string)($params['groupRef'] ?? '');
        if ($contentId <= 0 || $groupRef === '') {
            return Response::json(['error' => 'invalid_params'], 400);
        }

        $allowed = ['title', 'intro', 'type', 'giustifica'];
        $raw = (string)@file_get_contents('php://input');
        $decoded = $raw !== '' ? json_decode($raw, true) : null;
        $src = is_array($decoded) ? $decoded : $req->post;
        $patch = [];
        foreach ($allowed as $k) {
            if (!array_key_exists($k, $src)) {
                continue;
            }
            $v = $src[$k];
            // G23.fix15 — Form-urlencoded path: client (apiPost) invia
            // valori object come JSON-string. Decodifica come fa
            // readQuesitoPatchBody per intro array di blocks (uniforme con
            // item patch flow). Senza, intro veniva salvato come stringa
            // raw `"[...]"` → server render mostrava JSON text plain.
            if (is_string($v) && $v !== '' && ($v[0] === '[' || $v[0] === '{')) {
                $dec = json_decode($v, true);
                if ($dec !== null) {
                    $v = $dec;
                }
            }
            $patch[$k] = $v;
        }
        // Retro-compat: l'editor groupo invia `_group_title` / `_group_intro`.
        if (array_key_exists('_group_title', $src)) {
            $patch['title'] = (string)$src['_group_title'];
        }
        if (array_key_exists('_group_intro', $src)) {
            $patch['intro'] = (string)$src['_group_intro'];
        }
        if (!$patch) {
            return Response::json(['error' => 'empty_patch'], 400);
        }

        $expected = $this->readExpectedVersion($req);
        try {
            $agg = \App\Services\Contract\ContractRepository::default()
                ->patchGroup($contentId, $tid, $groupRef, $patch, $expected);
        } catch (\App\Services\Contract\ContractNotFoundException) {
            return Response::json(['error' => 'not_found'], 404);
        } catch (\App\Services\Contract\ContractItemNotFoundException) {
            return Response::json(['error' => 'group_not_found', 'groupRef' => $groupRef], 404);
        } catch (\App\Services\Contract\ContractVersionMismatchException $e) {
            return Response::json([
                'error' => 'version_conflict',
                'expected' => $e->expected,
                'actual'   => $e->actual,
            ], 409);
        } catch (\Throwable $e) {
            return Response::json(['error' => 'internal'], 500);
        }
        $r = Response::json(['ok' => true, 'version' => $agg->version()]);
        $r->headers['ETag'] = '"v' . $agg->version() . '"';
        return $r;
    }

    /**
     * Legge `If-Match: "v<N>"` (standard HTTP) oppure `_version` nel body
     * come fallback per client che non possono settare header custom
     * (es. form post). Null → no optimistic check.
     */
    private function readExpectedVersion(Request $req): ?int
    {
        $ifMatch = (string)($req->headers['if-match'] ?? '');
        if (preg_match('/"v(\d+)"/', $ifMatch, $m)) {
            return (int)$m[1];
        }
        if (isset($req->post['_version'])) {
            return (int)$req->post['_version'];
        }
        return null;
    }

    // ---- helper condivisi (copia da TeacherContentController, ADR-029) ----

    private function teacherId(): int
    {
        $u = Auth::user();
        if (!$u) {
            return 0;
        }
        return \App\Support\TeacherContextResolver::userIdFromUsername((string)($u['username'] ?? ''));
    }

    private function dbReady(): bool
    {
        return (bool)Config::get('database.enabled') && Database::isAvailable();
    }

    private function firstInstituteId(int $teacherId): int
    {
        return \App\Support\TeacherContextResolver::firstInstituteId($teacherId);
    }
}
