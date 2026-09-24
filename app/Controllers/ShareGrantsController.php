<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Request;
use App\Core\Response;
use App\Repositories\Sharing\ShareGrantRepository;
use App\Services\Sharing\SharedContentPolicy;
use Throwable;

/**
 * G22.S25 — Granularità share: grants espliciti polimorfici verso
 *   - istituto
 *   - docente specifico
 *   - gruppo personale (share_groups)
 *
 * Coesiste con il flag legacy teacher_content.shared_with_pool /
 * verifica_documents.shared_with_pool (= share con istituto attivo
 * dell'owner). Le grants estendono la visibilità a target specifici.
 *
 * Dal 2026-09-04 (revisione P6) il SQL sta in ShareGrantRepository: qui
 * restano le decisioni (chi e' l'attore, cosa possiede, cosa e' un target
 * valido, come rispondere).
 */
final class ShareGrantsController
{
    private SharedContentPolicy $policy;
    private ShareGrantRepository $grants;

    public function __construct(?SharedContentPolicy $policy = null, ?ShareGrantRepository $grants = null)
    {
        $this->policy = $policy ?? new SharedContentPolicy();
        $this->grants = $grants ?? new ShareGrantRepository();
    }

    /**
     * GET /api/teacher/share/grants/{source}/{id}
     * Ritorna { grants: [{id, target_type, target_id, target_label?}] }
     */
    public function listGrants(Request $req, array $params): Response
    {
        $actor = $this->actor();
        if (!$actor) {
            return Response::json(['error' => 'unauthorized'], 401);
        }
        $source = (string)($params['source'] ?? '');
        $id = (int)($params['id'] ?? 0);
        if (!\in_array($source, ['teacher_content', 'verifica_documents'], true) || $id <= 0) {
            return Response::json(['error' => 'invalid_params'], 400);
        }
        if (!$this->ownsContent($actor, $source, $id)) {
            return Response::json(['error' => 'forbidden'], 403);
        }
        $grants = $this->grants->grantsFor($actor, $source, $id);

        // Decora con label umane per UI
        foreach ($grants as &$g) {
            $g['target_label'] = $this->grants->targetLabel((string)$g['target_type'], (int)$g['target_id']);
        }
        unset($g);
        return Response::json(['ok' => true, 'grants' => $grants]);
    }

    /**
     * POST /api/teacher/share/grants/{source}/{id}
     * Body JSON: { grants: [{ target_type, target_id }] }
     * Sostituisce l'insieme di grants (full upsert: aggiunge mancanti, cancella esistenti non in lista).
     */
    public function setGrants(Request $req, array $params): Response
    {
        $actor = $this->actor();
        if (!$actor) {
            return Response::json(['error' => 'unauthorized'], 401);
        }
        $source = (string)($params['source'] ?? '');
        $id = (int)($params['id'] ?? 0);
        if (!\in_array($source, ['teacher_content', 'verifica_documents'], true) || $id <= 0) {
            return Response::json(['error' => 'invalid_params'], 400);
        }
        if (!$this->ownsContent($actor, $source, $id)) {
            return Response::json(['error' => 'forbidden'], 403);
        }
        $jsonBody = ($req->isJson() ? $req->json() : []);
        $newGrants = is_array($jsonBody['grants'] ?? null) ? $jsonBody['grants'] : [];

        // Validazione + de-dup. G22.S25 — validateTarget ora cross-institute aware:
        // refusa institute target se il contenuto NON è in quell'istituto, e
        // teacher target se non è collega dell'attore.
        $clean = [];
        foreach ($newGrants as $g) {
            $t = (string)($g['target_type'] ?? '');
            $tid = (int)($g['target_id'] ?? 0);
            if (!\in_array($t, ['institute', 'teacher', 'group'], true) || $tid <= 0) {
                continue;
            }
            if (!$this->policy->validateTarget($actor, $source, $id, $t, $tid)) {
                continue;
            }
            $clean["$t|$tid"] = ['target_type' => $t, 'target_id' => $tid];
        }

        // 14/9/2026 — i grant di una verifica valgono per tutte le sue varianti
        // e versioni, come il pulsante del pool (SharedContentPolicy::righeCondivise).
        $righe = $this->policy->righeCondivise($source, $id);

        // ToS §2.1.1 / art. 70-bis (2026-09-04) — stesso blocco copyright del
        // pool: un contenuto derivato dal libro di testo non si condivide
        // nemmeno con un collega scelto a mano o con un gruppo. Prima il
        // blocco valeva solo per shared_with_pool e i grants lo aggiravano.
        // Togliere tutti i grants ($clean vuoto) resta sempre possibile.
        if ($clean !== []) {
            foreach ($righe as $riga) {
                $reason = $this->policy->shareBlockReason($source, $riga);
                if ($reason !== null) {
                    return Response::json([
                        'error'   => 'copyright_block',
                        'reason'  => $reason,
                        'message' => $this->policy->humanReadableBlockReason($reason),
                    ], 409);
                }
            }
        }

        try {
            // Tutte le righe o nessuna: una verifica condivisa a metà è il
            // difetto che questa correzione chiude.
            (new \App\Support\PdoTransactionRunner())->run(function () use ($actor, $source, $righe, $clean): void {
                foreach ($righe as $riga) {
                    $this->grants->replaceGrants($actor, $source, $riga, array_values($clean));
                }
            });
        } catch (Throwable $e) {
            return Response::json(['error' => 'set_grants_failed', 'detail' => $e->getMessage()], 500);
        }
        return Response::json(['ok' => true, 'count' => count($clean), 'righe' => count($righe)]);
    }

    /**
     * GET /api/teacher/share/groups
     * Lista gruppi dell'attore con conteggio membri.
     */
    public function listGroups(Request $req): Response
    {
        $actor = $this->actor();
        if (!$actor) {
            return Response::json(['error' => 'unauthorized'], 401);
        }
        return Response::json(['ok' => true, 'groups' => $this->grants->groupsOf($actor)]);
    }

    /**
     * POST /api/teacher/share/groups
     * Body JSON: { name, description? } → crea gruppo.
     */
    public function createGroup(Request $req): Response
    {
        $actor = $this->actor();
        if (!$actor) {
            return Response::json(['error' => 'unauthorized'], 401);
        }
        $jb = ($req->isJson() ? $req->json() : []);
        $name = trim((string)($jb['name'] ?? $req->post['name'] ?? ''));
        $desc = trim((string)($jb['description'] ?? $req->post['description'] ?? ''));
        if ($name === '' || strlen($name) > 120) {
            return Response::json(['error' => 'invalid_name'], 400);
        }
        try {
            $gid = $this->grants->createGroup($actor, $name, $desc !== '' ? $desc : null);
            return Response::json(['ok' => true, 'id' => $gid]);
        } catch (\PDOException $e) {
            if ((int)$e->errorInfo[1] === 1062) {
                return Response::json(['error' => 'duplicate_name'], 409);
            }
            return Response::json(['error' => 'create_failed', 'detail' => $e->getMessage()], 500);
        }
    }

    /**
     * GET /api/teacher/share/groups/{id}/members
     * Ritorna membri attuali del gruppo (id + display_name).
     */
    public function listMembers(Request $req, array $params): Response
    {
        $actor = $this->actor();
        if (!$actor) {
            return Response::json(['error' => 'unauthorized'], 401);
        }
        $gid = (int)($params['id'] ?? 0);
        if (!$this->ownsGroup($actor, $gid)) {
            return Response::json(['error' => 'forbidden'], 403);
        }
        return Response::json(['ok' => true, 'members' => $this->grants->membersOf($gid)]);
    }

    /**
     * POST /api/teacher/share/groups/{id}/members
     * Body JSON: { member_ids: [int, ...] } → upsert membri.
     */
    public function setMembers(Request $req, array $params): Response
    {
        $actor = $this->actor();
        if (!$actor) {
            return Response::json(['error' => 'unauthorized'], 401);
        }
        $gid = (int)($params['id'] ?? 0);
        if (!$this->ownsGroup($actor, $gid)) {
            return Response::json(['error' => 'forbidden'], 403);
        }
        $jb = ($req->isJson() ? $req->json() : []);
        $ids = array_map('intval', $jb['member_ids'] ?? []);
        $ids = array_values(array_unique(array_filter($ids, fn($i) => $i > 0 && $i !== $actor)));
        // 2026-09-04 — i membri devono essere colleghi (stesso istituto), come
        // per i grant diretti: la lettura era comunque bloccata dal gate
        // cross-istituto di SharedContentPolicy, ma un gruppo non deve
        // nemmeno poter elencare docenti di altre scuole.
        $ids = $this->grants->onlyColleagues($actor, $ids);

        try {
            $this->grants->replaceMembers($gid, $ids);
        } catch (Throwable $e) {
            return Response::json(['error' => 'set_members_failed', 'detail' => $e->getMessage()], 500);
        }
        return Response::json(['ok' => true, 'count' => count($ids)]);
    }

    /**
     * POST /api/teacher/share/groups/{id}/delete
     * Elimina gruppo (cascade: members + content_shares con target=group).
     */
    public function deleteGroup(Request $req, array $params): Response
    {
        $actor = $this->actor();
        if (!$actor) {
            return Response::json(['error' => 'unauthorized'], 401);
        }
        $gid = (int)($params['id'] ?? 0);
        if (!$this->ownsGroup($actor, $gid)) {
            return Response::json(['error' => 'forbidden'], 403);
        }
        $this->grants->deleteGroup($actor, $gid);
        return Response::ok();
    }

    /**
     * GET /api/teacher/share/colleagues
     * Lista altri docenti dei tuoi istituti (per share-popup target=teacher).
     */
    public function listColleagues(Request $req): Response
    {
        $actor = $this->actor();
        if (!$actor) {
            return Response::json(['error' => 'unauthorized'], 401);
        }
        return Response::json(['ok' => true, 'colleagues' => $this->grants->colleaguesOf($actor)]);
    }

    // ─── helpers ───

    private function actor(): int
    {
        return (int)(Auth::user()['id'] ?? 0);
    }

    private function ownsContent(int $actor, string $source, int $id): bool
    {
        return $this->policy->ownsContent($actor, $source, $id);
    }

    private function ownsGroup(int $actor, int $gid): bool
    {
        return $gid > 0 && $this->grants->groupOwner($gid) === $actor;
    }
}
