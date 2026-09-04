<?php

namespace App\Repositories;

use App\Core\Database;
use PDO;

/**
 * Phase 14 — repository per storage_objects (metadati provider-agnostici).
 */
final class StorageObjectRepository
{
    /**
     * Upsert idempotente per (provider, storage_key). Ritorna id riga.
     */
    public function upsert(array $row): int
    {
        $pdo = Database::connection();
        $provider = (string)$row['provider'];
        $key      = (string)$row['storage_key'];
        $stmt = $pdo->prepare(
            'SELECT id FROM storage_objects WHERE provider = ? AND storage_key = ? LIMIT 1'
        );
        $stmt->execute([$provider, $key]);
        $id = (int)($stmt->fetchColumn() ?: 0);
        if ($id > 0) {
            $upd = $pdo->prepare(
                'UPDATE storage_objects
                 SET checksum=?, size_bytes=?, mime=?, visibility=?,
                     owner_user_id=?, institute_id=?, version=?
                 WHERE id = ?'
            );
            $upd->execute([
                (string)$row['checksum'],
                (int)$row['size_bytes'],
                $row['mime'] ?? null,
                (string)($row['visibility'] ?? 'private'),
                $row['owner_user_id'] ?? null,
                $row['institute_id']  ?? null,
                (int)($row['version'] ?? 1),
                $id,
            ]);
            return $id;
        }
        $ins = $pdo->prepare(
            'INSERT INTO storage_objects
               (provider, storage_key, checksum, size_bytes, mime, visibility,
                owner_user_id, institute_id, version)
             VALUES (?,?,?,?,?,?,?,?,?)'
        );
        $ins->execute([
            $provider, $key,
            (string)$row['checksum'],
            (int)$row['size_bytes'],
            $row['mime'] ?? null,
            (string)($row['visibility'] ?? 'private'),
            $row['owner_user_id'] ?? null,
            $row['institute_id']  ?? null,
            (int)($row['version'] ?? 1),
        ]);
        return (int)$pdo->lastInsertId();
    }

    public function exists(string $provider, string $key): bool
    {
        $stmt = Database::connection()->prepare(
            'SELECT 1 FROM storage_objects WHERE provider = ? AND storage_key = ? LIMIT 1'
        );
        $stmt->execute([$provider, $key]);
        return (bool)$stmt->fetchColumn();
    }

    /** @return array|null */
    public function findByKey(string $provider, string $key): ?array
    {
        $stmt = Database::connection()->prepare(
            'SELECT * FROM storage_objects WHERE provider = ? AND storage_key = ? LIMIT 1'
        );
        $stmt->execute([$provider, $key]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }
}
