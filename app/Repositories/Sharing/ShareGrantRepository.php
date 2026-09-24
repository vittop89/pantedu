<?php

declare(strict_types=1);

namespace App\Repositories\Sharing;

use App\Core\Database;
use PDO;

/**
 * Accesso ai dati della condivisione esplicita (G22.S25): grants polimorfici
 * (`content_shares`: istituto, docente, gruppo) e gruppi personali
 * (`share_groups`, `share_group_members`).
 *
 * Estratto da ShareGrantsController il 2026-09-04 (revisione architetturale,
 * intervento P6): il SQL e' quello del controller, copiato tale e quale; il
 * controller decide chi puo' fare cosa (SharedContentPolicy, proprieta' del
 * gruppo) e come rispondere. Nessuna regola di autorizzazione vive qui:
 * `groupOwner()` e `onlyColleagues()` restituiscono fatti, la decisione e'
 * di chi chiama.
 *
 * Le scritture composte (`replaceGrants`, `replaceMembers`) sono
 * transazionali e lasciano propagare l'eccezione dopo il rollback.
 */
final class ShareGrantRepository
{
    private function pdo(): PDO
    {
        return Database::connection();
    }

    /**
     * Grants di un contenuto dell'owner, ordinati per tipo e id di destinazione.
     *
     * @return list<array{id:int|string,target_type:string,target_id:int|string,created_at:string}>
     */
    public function grantsFor(int $owner, string $source, int $contentId): array
    {
        $stmt = $this->pdo()->prepare(
            'SELECT id, target_type, target_id, created_at
               FROM content_shares
              WHERE owner_user_id = ? AND content_source = ? AND content_id = ?
              ORDER BY target_type, target_id'
        );
        $stmt->execute([$owner, $source, $contentId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Sostituisce l'insieme dei grants di un contenuto (cancella e reinserisce,
     * in transazione).
     *
     * @param list<array{target_type:string,target_id:int}> $grants
     */
    public function replaceGrants(int $owner, string $source, int $contentId, array $grants): void
    {
        $this->atomically(function (PDO $pdo) use ($owner, $source, $contentId, $grants): void {
            $pdo->prepare(
                'DELETE FROM content_shares
                  WHERE owner_user_id = ? AND content_source = ? AND content_id = ?'
            )->execute([$owner, $source, $contentId]);
            if ($grants) {
                $ins = $pdo->prepare(
                    'INSERT INTO content_shares (owner_user_id, content_source, content_id, target_type, target_id)
                     VALUES (?, ?, ?, ?, ?)'
                );
                foreach ($grants as $g) {
                    $ins->execute([$owner, $source, $contentId, $g['target_type'], $g['target_id']]);
                }
            }
        });
    }

    /**
     * Esegue $work in una transazione propria; se una transazione e' gia'
     * aperta (test in rollback, chiamante che coordina piu' scritture) si
     * accoda a quella senza aprirne un'altra. Rollback e rilancio in caso di
     * eccezione.
     *
     * @param callable(PDO): void $work
     */
    private function atomically(callable $work): void
    {
        $pdo = $this->pdo();
        $own = !$pdo->inTransaction();
        if ($own) {
            $pdo->beginTransaction();
        }
        try {
            $work($pdo);
            if ($own) {
                $pdo->commit();
            }
        } catch (\Throwable $e) {
            if ($own && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    /**
     * Gruppi personali dell'owner con il conteggio dei membri.
     *
     * @return list<array<string,mixed>>
     */
    public function groupsOf(int $owner): array
    {
        $stmt = $this->pdo()->prepare(
            'SELECT sg.id, sg.name, sg.description, sg.created_at,
                    (SELECT COUNT(*) FROM share_group_members m WHERE m.group_id = sg.id) AS members_count
               FROM share_groups sg
              WHERE sg.owner_user_id = ?
              ORDER BY sg.name'
        );
        $stmt->execute([$owner]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Crea un gruppo e ne restituisce l'id. Un nome duplicato per lo stesso
     * owner fa fallire l'INSERT (PDOException 1062): la traduzione in
     * risposta e' del chiamante.
     */
    public function createGroup(int $owner, string $name, ?string $description): int
    {
        $pdo = $this->pdo();
        $pdo->prepare(
            'INSERT INTO share_groups (owner_user_id, name, description) VALUES (?, ?, ?)'
        )->execute([$owner, $name, $description]);
        return (int)$pdo->lastInsertId();
    }

    /** Owner di un gruppo, 0 se il gruppo non esiste. */
    public function groupOwner(int $groupId): int
    {
        $stmt = $this->pdo()->prepare('SELECT owner_user_id FROM share_groups WHERE id = ?');
        $stmt->execute([$groupId]);
        return (int)$stmt->fetchColumn();
    }

    /**
     * Membri di un gruppo (id, display_name, username), per nome.
     *
     * @return list<array<string,mixed>>
     */
    public function membersOf(int $groupId): array
    {
        $stmt = $this->pdo()->prepare(
            "SELECT u.id,
                    COALESCE(NULLIF(TRIM(CONCAT_WS(' ', u.first_name, u.last_name)), ''), u.username) AS display_name,
                    u.username
               FROM share_group_members m
               JOIN users u ON u.id = m.member_user_id
              WHERE m.group_id = ?
              ORDER BY display_name"
        );
        $stmt->execute([$groupId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Sostituisce i membri di un gruppo (cancella e reinserisce, in transazione).
     *
     * @param list<int> $memberIds
     */
    public function replaceMembers(int $groupId, array $memberIds): void
    {
        $this->atomically(function (PDO $pdo) use ($groupId, $memberIds): void {
            $pdo->prepare('DELETE FROM share_group_members WHERE group_id = ?')->execute([$groupId]);
            if ($memberIds) {
                $ins = $pdo->prepare('INSERT INTO share_group_members (group_id, member_user_id) VALUES (?, ?)');
                foreach ($memberIds as $uid) {
                    $ins->execute([$groupId, $uid]);
                }
            }
        });
    }

    /** Elimina un gruppo dell'owner e i grants che lo avevano come destinazione. */
    public function deleteGroup(int $owner, int $groupId): void
    {
        $pdo = $this->pdo();
        $pdo->prepare('DELETE FROM content_shares WHERE target_type=? AND target_id=?')->execute(['group', $groupId]);
        $pdo->prepare('DELETE FROM share_groups WHERE id = ? AND owner_user_id = ?')->execute([$groupId, $owner]);
    }

    /**
     * Altri docenti attivi che condividono almeno un istituto con l'attore.
     *
     * @return list<array<string,mixed>>
     */
    public function colleaguesOf(int $actor): array
    {
        $stmt = $this->pdo()->prepare(
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
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Filtra una lista di user id ai soli docenti attivi che condividono
     * almeno un istituto con l'attore (stessa regola di validateTarget).
     *
     * @param list<int> $ids
     * @return list<int>
     */
    public function onlyColleagues(int $actor, array $ids): array
    {
        if ($ids === []) {
            return [];
        }
        $place = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $this->pdo()->prepare(
            "SELECT DISTINCT u.id
               FROM users u
               JOIN teacher_institutes ti ON ti.user_id = u.id
               JOIN teacher_institutes mine ON mine.institute_id = ti.institute_id AND mine.user_id = ?
              WHERE u.id IN ($place) AND u.role = 'teacher' AND u.deleted_at IS NULL"
        );
        $stmt->execute([$actor, ...$ids]);
        return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    }

    /**
     * Etichetta leggibile di una destinazione (nome istituto, docente o
     * gruppo); `tipo#id` se non risolvibile.
     */
    public function targetLabel(string $type, int $id): string
    {
        $pdo = $this->pdo();
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
        } catch (\Throwable) {
        }
        return $type . '#' . $id;
    }
}
