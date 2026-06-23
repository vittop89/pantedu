<?php

namespace App\Services\Contract;

use App\Core\Database;
use PDO;

/**
 * Phase 17 — Archivio append-only delle versioni del contract JSON.
 *
 *   - archive($contentId, $version, $snapshot): insert dell'attuale JSON
 *     PRIMA che venga sovrascritto dal save successivo.
 *   - history($contentId, limit): ritorna le ultime N versioni.
 *   - restore($contentId, $version): ritorna il snapshot di una version
 *     specifica (consumer applica via save()).
 *
 * Idempotenza: (content_id, version) non è UNIQUE perché più save
 * sequenziali con stessa version sono possibili (es. bug). Fair game.
 */
class ContentVersionRepository
{
    public function __construct(private ?PDO $pdo = null)
    {
        $this->pdo = $pdo ?? Database::connection();
    }

    public function archive(
        int $contentId,
        int $version,
        array $snapshot,
        ?int $actorUserId = null,
        ?string $actorName = null,
        ?string $summary = null,
    ): int {
        $sql = 'INSERT INTO content_versions
                    (content_id, version, snapshot_json, actor_user_id, actor_name, change_summary)
                VALUES (?, ?, ?, ?, ?, ?)';
        $st = $this->pdo->prepare($sql);
        $st->execute([
            $contentId,
            $version,
            (string)json_encode($snapshot, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            $actorUserId,
            $actorName,
            $summary,
        ]);
        return (int)$this->pdo->lastInsertId();
    }

    /** @return list<array{id:int,version:int,created_at:string,actor_name:?string,change_summary:?string}> */
    public function history(int $contentId, int $limit = 50): array
    {
        $limit = max(1, min(500, $limit));
        $st = $this->pdo->prepare(
            "SELECT id, version, actor_user_id, actor_name, change_summary, created_at
             FROM content_versions WHERE content_id = ?
             ORDER BY id DESC LIMIT $limit"
        );
        $st->execute([$contentId]);
        return $st->fetchAll(PDO::FETCH_ASSOC);
    }

    /** Ritorna lo snapshot JSON decodificato per una specifica version (null se mancante). */
    public function restore(int $contentId, int $version): ?array
    {
        $st = $this->pdo->prepare(
            'SELECT snapshot_json FROM content_versions
             WHERE content_id = ? AND version = ? ORDER BY id DESC LIMIT 1'
        );
        $st->execute([$contentId, $version]);
        $blob = $st->fetchColumn();
        if (!$blob) {
            return null;
        }
        $data = json_decode((string)$blob, true);
        return is_array($data) ? $data : null;
    }

    /** GC: conserva gli ultimi N snapshot per content, elimina il resto. */
    public function prune(int $contentId, int $keep = 20): int
    {
        $keep = max(1, $keep);
        $st = $this->pdo->prepare(
            'DELETE FROM content_versions
             WHERE content_id = ?
             AND id NOT IN (
                SELECT id FROM (
                    SELECT id FROM content_versions
                    WHERE content_id = ? ORDER BY id DESC LIMIT ?
                ) keep
             )'
        );
        $st->execute([$contentId, $contentId, $keep]);
        return $st->rowCount();
    }
}
