<?php

declare(strict_types=1);

namespace App\Services\Risdoc;

use App\Core\Database;
use PDO;

/**
 * Phase 24.55 — Institutional overrides repository.
 *
 * Layer admin-editabile sopra ai source file su disco; per-teacher
 * override (`risdoc_teacher_overrides`) hanno priorità maggiore.
 *
 * Resolver order:
 *   1. teacher override
 *   2. institutional override (questa tabella)
 *   3. source file su disco
 *
 * Mirrors OverrideRepository ma senza teacher_id (UNIQUE template_id+kind+path).
 */
final class InstitutionalOverrideRepository
{
    private const KINDS = ['html', 'tex', 'css', 'json', 'image', 'texCommon', 'schema'];
    public function find(int $templateId, string $kind, string $path): ?array
    {
        $this->assertKind($kind);
        $stmt = Database::connection()->prepare('SELECT id, body, image_hash, source_version, updated_by, updated_at
             FROM risdoc_institutional_overrides
             WHERE template_id=? AND kind=? AND relative_path=?
             LIMIT 1');
        $stmt->execute([$templateId, $kind, $path]);
        $r = $stmt->fetch(PDO::FETCH_ASSOC);
        return $r ?: null;
    }

    public function listForTemplate(int $templateId): array
    {
        $stmt = Database::connection()->prepare('SELECT id, kind, relative_path, source_version, updated_by, updated_at
             FROM risdoc_institutional_overrides
             WHERE template_id=?
             ORDER BY kind, relative_path');
        $stmt->execute([$templateId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function saveText(int $templateId, string $kind, string $path, string $body, string $sourceVersion, ?int $updatedBy = null): int
    {
        $this->assertKind($kind);
        if ($kind === 'image') {
            throw new \InvalidArgumentException('use saveImage() for kind=image');
        }
        $stmt = Database::connection()->prepare('INSERT INTO risdoc_institutional_overrides
                (template_id, kind, relative_path, body, source_version, updated_by)
             VALUES (?,?,?,?,?,?)
             ON DUPLICATE KEY UPDATE
                body=VALUES(body),
                source_version=VALUES(source_version),
                updated_by=VALUES(updated_by)');
        $stmt->execute([$templateId, $kind, $path, $body, $sourceVersion, $updatedBy]);
        return (int)Database::connection()->lastInsertId();
    }

    public function saveImage(int $templateId, string $path, string $imageHash, string $sourceVersion, ?int $updatedBy = null): int
    {
        $stmt = Database::connection()->prepare('INSERT INTO risdoc_institutional_overrides
                (template_id, kind, relative_path, body, image_hash, source_version, updated_by)
             VALUES (?,?,?,NULL,?,?,?)
             ON DUPLICATE KEY UPDATE
                image_hash=VALUES(image_hash),
                source_version=VALUES(source_version),
                updated_by=VALUES(updated_by)');
        $stmt->execute([$templateId, 'image', $path, $imageHash, $sourceVersion, $updatedBy]);
        return (int)Database::connection()->lastInsertId();
    }

    public function delete(int $templateId, string $kind, string $path): bool
    {
        $this->assertKind($kind);
        $stmt = Database::connection()->prepare('DELETE FROM risdoc_institutional_overrides
             WHERE template_id=? AND kind=? AND relative_path=?');
        $stmt->execute([$templateId, $kind, $path]);
        return $stmt->rowCount() > 0;
    }

    private function assertKind(string $kind): void
    {
        if (!in_array($kind, self::KINDS, true)) {
            throw new \InvalidArgumentException("invalid kind: $kind");
        }
    }
}
