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
 * QuesitoController — estratto da TeacherContentController (ADR-029).
 * Metodi: quesitoPatch, quesitoDelete, quesitoMove, quesitoDuplicate, quesitoCloneToEser.
 * Helper condivisi duplicati: readExpectedVersion, teacherId, dbReady.
 */
final class QuesitoController
{
    private TeacherContentRepository $repo;

    public function __construct(?TeacherContentRepository $repo = null)
    {
        $this->repo = $repo ?? new TeacherContentRepository();
    }

    /**
     * Phase 16 — Endpoints contract-scoped per patch/delete/move a livello
     * item. Sostituiscono le rotte `/api/teacher/content/{syntheticId}/*`
     * che 404avano perché gli item non hanno DB rows proprie.
     *
     *   POST /api/teacher/content/{id}/quesito/{itemRef}/patch
     *   POST /api/teacher/content/{id}/quesito/{itemRef}/delete
     *   POST /api/teacher/content/{id}/quesito/{itemRef}/move?to={idx}
     *
     * Dove:
     *   - {id}     = teacher_content row numeric id (contract-level)
     *   - {itemRef}= locator opaco (id numerico interno O "<groupId>_q<idx>"
     *                O "g<groupIdx>_q<itemIdx>"). Risolto da ContractAggregate.
     *
     * Optimistic locking via header `If-Match: "v<N>"` oppure campo body
     * `_version`. Mismatch → 409 Conflict con la version corrente nel body.
     *
     * Body accepted per patch: { origin?: string, source?: string,
     * color?: string, badge?: object, category_label?: string, category_color?: string,
     * body_html?: string, answer?: string, points?: number }.
     * I campi non nell'allowlist vengono scartati.
     */
    public function quesitoPatch(Request $req, array $params): Response
    {
        return $this->quesitoOp($req, $params, 'patch');
    }

    public function quesitoDelete(Request $req, array $params): Response
    {
        return $this->quesitoOp($req, $params, 'delete');
    }

    public function quesitoMove(Request $req, array $params): Response
    {
        return $this->quesitoOp($req, $params, 'move');
    }

    /** Phase 17 — `POST /api/teacher/content/{id}/quesito/{itemRef}/duplicate`.
     *  Crea una copia dell'item (nuovo UUID) subito dopo quello indicato. */
    public function quesitoDuplicate(Request $req, array $params): Response
    {
        return $this->quesitoOp($req, $params, 'duplicate');
    }

    public function quesitoCloneToEser(Request $req, array $params): Response
    {
        if (!$this->dbReady()) {
            return Response::json(['error' => 'db_unavailable'], 503);
        }
        $tid = $this->teacherId();
        if (!$tid) {
            return Response::json(['error' => 'unauthorized'], 401);
        }
        $verId = (int)($params['id'] ?? 0);
        $itemRef = (string)($params['itemRef'] ?? '');
        if ($verId <= 0 || $itemRef === '') {
            return Response::json(['error' => 'invalid_params'], 400);
        }
        $mode = (string)$req->input('mode', 'source');
        if (!in_array($mode, ['source', 'full'], true)) {
            $mode = 'source';
        }
        $repo = \App\Services\Contract\ContractRepository::default();
        try {
            $result = $repo->cloneToEser($verId, $tid, $itemRef, $mode);
        } catch (\App\Services\Contract\ContractNotFoundException) {
            return Response::json(['error' => 'not_found'], 404);
        } catch (\App\Services\Contract\ContractItemNotFoundException) {
            return Response::json(['error' => 'item_not_found'], 404);
        } catch (\Throwable $e) {
            return Response::json(['error' => 'clone_failed'], 400);
        }
        return Response::json(['ok' => true] + $result);
    }

    private function quesitoOp(Request $req, array $params, string $op): Response
    {
        if (!$this->dbReady()) {
            return Response::json(['error' => 'db_unavailable'], 503);
        }
        $tid = $this->teacherId();
        if (!$tid) {
            return Response::json(['error' => 'unauthorized'], 401);
        }
        $contentId = (int)($params['id'] ?? 0);
        $itemRef = (string)($params['itemRef'] ?? '');
        if ($contentId <= 0 || $itemRef === '') {
            return Response::json(['error' => 'invalid_params'], 400);
        }

        $repo = \App\Services\Contract\ContractRepository::default();
        $expectedVersion = $this->readExpectedVersion($req);

        $newId = null;
        try {
            if ($op === 'patch') {
                $patch = $this->readQuesitoPatchBody($req);
                if (!$patch) {
                    return Response::json(['error' => 'empty_patch'], 400);
                }
                $agg = $repo->patchItem($contentId, $tid, $itemRef, $patch, $expectedVersion);
            } elseif ($op === 'delete') {
                $agg = $repo->deleteItem($contentId, $tid, $itemRef, $expectedVersion);
            } elseif ($op === 'duplicate') {
                $res = $repo->duplicateItem($contentId, $tid, $itemRef, $expectedVersion);
                $agg = $res['agg'];
                $newId = $res['newId'];
            } else {
                $to = (int)($req->query['to'] ?? $req->post['to'] ?? -1);
                if ($to < 0) {
                    return Response::json(['error' => 'missing_to'], 400);
                }
                $agg = $repo->moveItem($contentId, $tid, $itemRef, $to, $expectedVersion);
            }
        } catch (\App\Services\Contract\ContractNotFoundException) {
            return Response::json(['error' => 'not_found'], 404);
        } catch (\App\Services\Contract\ContractItemNotFoundException $e) {
            return Response::json(['error' => 'item_not_found', 'itemRef' => $itemRef], 404);
        } catch (\App\Services\Contract\ContractVersionMismatchException $e) {
            return Response::json([
                'error' => 'version_conflict',
                'expected' => $e->expected,
                'actual'   => $e->actual,
                'hint'     => 'Ricarica il contract e riprova.',
            ], 409);
        } catch (\Throwable $e) {
            return Response::json(['error' => 'internal'], 500);
        }

        $body = ['ok' => true, 'version' => $agg->version()];
        if ($newId !== null) {
            $body['newId'] = $newId;
        }
        $r = Response::json($body);
        $r->headers['ETag'] = '"v' . $agg->version() . '"';
        return $r;
    }

    /** Allowlist dei campi patchabili su un item del contract.
     *  Accetta sia nomi EN (storage-facing) sia alias IT del nuovo editor
     *  (`quesito/soluzione/giustificazione` → `question/answer/justification`).
     *  Flatten `metadata.{category_label,difficulty}` a top-level.
     *  Valori stringa che iniziano per `[` o `{` vengono decodificati (form
     *  POST client non supporta nested objects senza JSON-stringify). */
    private function readQuesitoPatchBody(Request $req): array
    {
        // Alias IT→EN del contract schema:
        //   - quesito/giustificazione: 1:1 mapping
        //   - soluzione (Collect): mappa a 'solution' (campo letto dal renderer
        //     per il box .fm-sol Collect). NB: 'answer' è separato (radio V/F dei VF).
        $aliases = [
            'quesito'         => 'question',
            'soluzione'       => 'solution',
            'giustificazione' => 'justification',
        ];
        $allowed = ['origin', 'source', 'color', 'badge', 'body_html',
                    'answer', 'solution', 'points', 'category_label', 'category_color',
                    'justification', 'question', 'difficulty', 'topic',
                    'options', 'metadata', 'rmLayout',
                    // G27.dsa.persist — marker DSA item-level (mark) + path-keyed
                    // map per i sub-li (dsa_marks). Persistiti per content_id +
                    // teacher (cosi' restano dopo reload pagina, no piu' solo
                    // sessionStorage). Il renderer riemette il DOM con
                    // data-fm-dsa-state pre-applicato.
                    'mark', 'dsa_marks',
                    '_group_title', '_group_intro'];
        $raw = (string)@file_get_contents('php://input');
        $decoded = $raw !== '' ? json_decode($raw, true) : null;
        $src = is_array($decoded) ? $decoded : $req->post;
        $patch = [];
        foreach ($src as $k => $v) {
            $key = $aliases[$k] ?? $k;
            if (!in_array($key, $allowed, true)) {
                continue;
            }
            if (is_string($v) && $v !== '' && ($v[0] === '[' || $v[0] === '{')) {
                $dec = json_decode($v, true);
                if ($dec !== null) {
                    $v = $dec;
                }
            }
            // Wrap HTML string in block array per campi che il contract
            // rappresenta come `[{type,content}]`.
            if (in_array($key, ['question', 'answer', 'justification'], true) && is_string($v)) {
                $v = [['type' => 'text', 'content' => $v]];
            }
            if ($key === 'metadata' && is_array($v)) {
                if (array_key_exists('category_label', $v)) {
                    $patch['category_label'] = (string)$v['category_label'];
                }
                if (array_key_exists('difficulty', $v)) {
                    $patch['difficulty'] = (int)$v['difficulty'];
                }
                continue;
            }
            $patch[$key] = $v;
        }
        return $patch;
    }

    // ---- helper condivisi (copia da TeacherContentController, ADR-029) ----

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
}
