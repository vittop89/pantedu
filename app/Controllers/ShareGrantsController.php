<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Database;
use App\Core\Request;
use App\Core\Response;
use App\Services\Sharing\SharedContentPolicy;
use PDO;
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
 */
final class ShareGrantsController
{
    private SharedContentPolicy $policy;

    public function __construct()
    {
        $this->policy = new SharedContentPolicy();
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
        $pdo = Database::connection();
        $stmt = $pdo->prepare(
            'SELECT id, target_type, target_id, created_at
               FROM content_shares
              WHERE owner_user_id = ? AND content_source = ? AND content_id = ?
              ORDER BY target_type, target_id'
        );
        $stmt->execute([$actor, $source, $id]);
        $grants = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Decora con label umane per UI
        foreach ($grants as &$g) {
            $g['target_label'] = $this->labelFor((string)$g['target_type'], (int)$g['target_id']);
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
        $jsonBody = $this->readJsonBody();
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

        $pdo = Database::connection();
        $pdo->beginTransaction();
        try {
            $pdo->prepare(
                'DELETE FROM content_shares
                  WHERE owner_user_id = ? AND content_source = ? AND content_id = ?'
            )->execute([$actor, $source, $id]);
            if ($clean) {
                $ins = $pdo->prepare(
                    'INSERT INTO content_shares (owner_user_id, content_source, content_id, target_type, target_id)
                     VALUES (?, ?, ?, ?, ?)'
                );
                foreach ($clean as $g) {
                    $ins->execute([$actor, $source, $id, $g['target_type'], $g['target_id']]);
                }
            }
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            return Response::json(['error' => 'set_grants_failed', 'detail' => $e->getMessage()], 500);
        }
        return Response::json(['ok' => true, 'count' => count($clean)]);
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
        $stmt = Database::connection()->prepare(
            'SELECT sg.id, sg.name, sg.description, sg.created_at,
                    (SELECT COUNT(*) FROM share_group_members m WHERE m.group_id = sg.id) AS members_count
               FROM share_groups sg
              WHERE sg.owner_user_id = ?
              ORDER BY sg.name'
        );
        $stmt->execute([$actor]);
        return Response::json(['ok' => true, 'groups' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
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
        $jb = $this->readJsonBody();
        $name = trim((string)($jb['name'] ?? $req->post['name'] ?? ''));
        $desc = trim((string)($jb['description'] ?? $req->post['description'] ?? ''));
        if ($name === '' || strlen($name) > 120) {
            return Response::json(['error' => 'invalid_name'], 400);
        }
        try {
            $pdo = Database::connection();
            $pdo->prepare(
                'INSERT INTO share_groups (owner_user_id, name, description) VALUES (?, ?, ?)'
            )->execute([$actor, $name, $desc !== '' ? $desc : null]);
            return Response::json(['ok' => true, 'id' => (int)$pdo->lastInsertId()]);
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
        $stmt = Database::connection()->prepare(
            "SELECT u.id,
                    COALESCE(NULLIF(TRIM(CONCAT_WS(' ', u.first_name, u.last_name)), ''), u.username) AS display_name,
                    u.username
               FROM share_group_members m
               JOIN users u ON u.id = m.member_user_id
              WHERE m.group_id = ?
              ORDER BY display_name"
        );
        $stmt->execute([$gid]);
        return Response::json(['ok' => true, 'members' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
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
        $jb = $this->readJsonBody();
        $ids = array_map('intval', $jb['member_ids'] ?? []);
        $ids = array_values(array_unique(array_filter($ids, fn($i) => $i > 0 && $i !== $actor)));

        $pdo = Database::connection();
        $pdo->beginTransaction();
        try {
            $pdo->prepare('DELETE FROM share_group_members WHERE group_id = ?')->execute([$gid]);
            if ($ids) {
                $ins = $pdo->prepare('INSERT INTO share_group_members (group_id, member_user_id) VALUES (?, ?)');
                foreach ($ids as $uid) {
                    $ins->execute([$gid, $uid]);
                }
            }
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
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
        $pdo = Database::connection();
        $pdo->prepare('DELETE FROM content_shares WHERE target_type=? AND target_id=?')->execute(['group', $gid]);
        $pdo->prepare('DELETE FROM share_groups WHERE id = ? AND owner_user_id = ?')->execute([$gid, $actor]);
        return Response::json(['ok' => true]);
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
        $pdo = Database::connection();
        $stmt = $pdo->prepare(
            "SELECT DISTINCT u.id, u.username,
                    COALESCE(NULLIF(TRIM(CONCAT_WS(' ', u.first_name, u.last_name)), ''), u.username) AS display_name
               FROM teacher_institutes ti_self
               JOIN teacher_institutes ti_other ON ti_other.institute_id = ti_self.institute_id
               JOIN users u ON u.id = ti_other.user_id
              WHERE ti_self.user_id = ? AND ti_other.user_id <> ?
                AND u.role = 'teacher'
                AND u.deleted_at IS NULL
              ORDER BY display_name"
        );
        $stmt->execute([$actor, $actor]);
        return Response::json(['ok' => true, 'colleagues' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
    }

    // ─── helpers ───

    private function actor(): int
    {
        return (int)(Auth::user()['id'] ?? 0);
    }

    private function readJsonBody(): array
    {
        $ctype = strtolower((string)($_SERVER['CONTENT_TYPE'] ?? ''));
        if (!str_contains($ctype, 'application/json')) {
            return [];
        }
        $raw = (string)file_get_contents('php://input');
        return json_decode($raw, true) ?: [];
    }

    private function ownsContent(int $actor, string $source, int $id): bool
    {
        return $this->policy->ownsContent($actor, $source, $id);
    }

    private function ownsGroup(int $actor, int $gid): bool
    {
        if ($gid <= 0) {
            return false;
        }
        $stmt = Database::connection()->prepare('SELECT owner_user_id FROM share_groups WHERE id = ?');
        $stmt->execute([$gid]);
        return (int)$stmt->fetchColumn() === $actor;
    }

    private function labelFor(string $type, int $id): string
    {
        $pdo = Database::connection();
        try {
            switch ($type) {
                case 'institute':
                    $stmt = $pdo->prepare('SELECT name FROM institutes WHERE id = ?');
                    $stmt->execute([$id]);
                    return (string)($stmt->fetchColumn() ?: ('institute#' . $id));
                case 'teacher':
                    $stmt = $pdo->prepare(
                        "SELECT COALESCE(NULLIF(TRIM(CONCAT_WS(' ', first_name, last_name)), ''), username) FROM users WHERE id = ?"
                    );
                    $stmt->execute([$id]);
                    return (string)($stmt->fetchColumn() ?: ('user#' . $id));
                case 'group':
                    $stmt = $pdo->prepare('SELECT name FROM share_groups WHERE id = ?');
                    $stmt->execute([$id]);
                    return (string)($stmt->fetchColumn() ?: ('group#' . $id));
            }
        } catch (Throwable) {
        }
        return $type . '#' . $id;
    }
}
