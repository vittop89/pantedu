<?php

declare(strict_types=1);

namespace App\Services\Risdoc;

use App\Core\Database;
use PDO;

/**
 * Repository per risdoc_teacher_overrides.
 *
 * Phase 24.58 — multi-instance: ogni override è privato al docente
 * MA può esistere in più istanze distinte per la coppia (teacher,
 * template) tramite `instance_key` (slug stabile, default '' = istanza
 * "base/default").
 *
 * UNIQUE: teacher_id+template_id+instance_key+kind+relative_path.
 *
 * Kind 'image': body null, image_hash punta a storage/overrides/teacher_<id>/<hash>.
 * Kind 'html|tex|css|json|texCommon': body testuale.
 */
final class OverrideRepository
{
    private const KINDS = ['html', 'tex', 'css', 'json', 'image', 'texCommon'];
    public function find(int $teacherId, int $templateId, string $kind, string $path, string $instanceKey = ''): ?array
    {
        $this->assertKind($kind);
        $stmt = Database::connection()->prepare('SELECT id, body, image_hash, source_version, updated_at, instance_key, instance_label
             FROM risdoc_teacher_overrides
             WHERE teacher_id=? AND template_id=? AND instance_key=? AND kind=? AND relative_path=?
             LIMIT 1');
        $stmt->execute([$teacherId, $templateId, $instanceKey, $kind, $path]);
        $r = $stmt->fetch(PDO::FETCH_ASSOC);
        return $r ?: null;
    }

    public function listByTeacher(int $teacherId, int $templateId, string $instanceKey = ''): array
    {
        $stmt = Database::connection()->prepare('SELECT id, kind, relative_path, source_version, updated_at, instance_key, instance_label
             FROM risdoc_teacher_overrides
             WHERE teacher_id=? AND template_id=? AND instance_key=?
             ORDER BY kind, relative_path');
        $stmt->execute([$teacherId, $templateId, $instanceKey]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Phase 24.58 — Lista istanze distinte di un template per un docente.
     * Ritorna [{instance_key, instance_label, override_count, last_updated}].
     */
    public function listInstances(int $teacherId, int $templateId): array
    {
        $stmt = Database::connection()->prepare('SELECT instance_key,
                    MAX(instance_label) AS instance_label,
                    COUNT(*)            AS override_count,
                    MAX(updated_at)     AS last_updated
             FROM risdoc_teacher_overrides
             WHERE teacher_id=? AND template_id=?
             GROUP BY instance_key
             ORDER BY MAX(updated_at) DESC');
        $stmt->execute([$teacherId, $templateId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Phase 24.58 — Lista TUTTE le istanze del docente cross-template
     * (utile per render sidepage: per ogni template+instance una riga).
     */
    public function listAllInstancesByTeacher(int $teacherId): array
    {
        $stmt = Database::connection()->prepare('SELECT template_id, instance_key,
                    MAX(instance_label) AS instance_label,
                    COUNT(*)            AS override_count,
                    MAX(updated_at)     AS last_updated
             FROM risdoc_teacher_overrides
             WHERE teacher_id=?
             GROUP BY template_id, instance_key
             ORDER BY MAX(updated_at) DESC');
        $stmt->execute([$teacherId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function saveText(int $teacherId, int $templateId, string $kind, string $path, string $body, string $sourceVersion, string $instanceKey = '', ?string $instanceLabel = null): int
    {
        $this->assertKind($kind);
        if ($kind === 'image') {
            throw new \InvalidArgumentException('use saveImage() for kind=image');
        }
        $stmt = Database::connection()->prepare('INSERT INTO risdoc_teacher_overrides
                (teacher_id, template_id, instance_key, instance_label,
                 kind, relative_path, body, source_version)
             VALUES (?,?,?,?,?,?,?,?)
             ON DUPLICATE KEY UPDATE
                body=VALUES(body),
                source_version=VALUES(source_version),
                instance_label=COALESCE(VALUES(instance_label), instance_label)');
        $stmt->execute([
            $teacherId, $templateId, $instanceKey, $instanceLabel,
            $kind, $path, $body, $sourceVersion,
        ]);
        return (int)Database::connection()->lastInsertId();
    }

    public function saveImage(int $teacherId, int $templateId, string $path, string $imageHash, string $sourceVersion, string $instanceKey = '', ?string $instanceLabel = null): int
    {
        $stmt = Database::connection()->prepare('INSERT INTO risdoc_teacher_overrides
                (teacher_id, template_id, instance_key, instance_label,
                 kind, relative_path, body, image_hash, source_version)
             VALUES (?,?,?,?,?,?,NULL,?,?)
             ON DUPLICATE KEY UPDATE
                image_hash=VALUES(image_hash),
                source_version=VALUES(source_version),
                instance_label=COALESCE(VALUES(instance_label), instance_label)');
        $stmt->execute([
            $teacherId, $templateId, $instanceKey, $instanceLabel,
            'image', $path, $imageHash, $sourceVersion,
        ]);
        return (int)Database::connection()->lastInsertId();
    }

    public function delete(int $teacherId, int $templateId, string $kind, string $path, string $instanceKey = ''): bool
    {
        $this->assertKind($kind);
        $stmt = Database::connection()->prepare('DELETE FROM risdoc_teacher_overrides
             WHERE teacher_id=? AND template_id=? AND instance_key=? AND kind=? AND relative_path=?');
        $stmt->execute([$teacherId, $templateId, $instanceKey, $kind, $path]);
        $ok = $stmt->rowCount() > 0;
        // Phase 25.R.25 — Audit log
        if ($ok) {
            \App\Services\Audit\ContentActionLogger::log(
                \App\Services\Audit\ContentActionLogger::ACTION_DELETED,
                $teacherId,
                $templateId,
                'risdoc',
                ['kind' => $kind, 'relative_path' => $path, 'instance_key' => $instanceKey]
            );
        }
        return $ok;
    }

    /**
     * Phase 24.58 — Cancella un'intera istanza (tutti gli override + image
     * file su disco). Usato per "elimina istanza personale".
     */
    public function deleteInstance(int $teacherId, int $templateId, string $instanceKey): int
    {
        $stmt = Database::connection()->prepare('DELETE FROM risdoc_teacher_overrides
             WHERE teacher_id=? AND template_id=? AND instance_key=?');
        $stmt->execute([$teacherId, $templateId, $instanceKey]);
        $count = $stmt->rowCount();
        // Phase 25.R.25 — Audit log delete istanza intera
        if ($count > 0) {
            \App\Services\Audit\ContentActionLogger::log(
                \App\Services\Audit\ContentActionLogger::ACTION_DELETED,
                $teacherId,
                $templateId,
                'risdoc',
                ['instance_key' => $instanceKey, 'files_removed' => $count, 'delete_type' => 'instance']
            );
        }
        return $count;
    }

    /**
     * Phase 24.58 — Crea un'istanza vuota (placeholder) per registrare il
     * fork. Salva un override "instance-marker" con kind=html path='' body=''
     * solo per tracciare l'instance_key + label.
     *
     * In alternativa lasciamo che la prima save vera crei la riga, ma serve
     * un modo per "creare istanza prima di edit". Marker approach.
     */
    /**
     * Phase 25.B2 — race-safe: usa INSERT ... ON DUPLICATE KEY UPDATE per
     * gestire atomicamente collision concorrenti sull'UNIQUE constraint
     * (teacher_id, template_id, instance_key, kind, relative_path).
     *
     * Ritorna:
     *   - true  se la riga è stata realmente inserita (chiamante è il creatore).
     *   - false se il marker già esisteva (collision: chiamante deve retry
     *           con un suffisso `-N` per ottenere una key univoca).
     *
     * Eliminato il vecchio INSERT IGNORE che mascherava silenziosamente
     * la collision senza segnalarla al caller (race window: due request
     * concorrenti con stesso label generavano lo stesso slug, una vinceva
     * l'INSERT, l'altra otteneva IGNORE, entrambe ritornavano "ok"
     * dall'API mantenendo il caller all'oscuro del conflitto).
     */
    public function createInstanceMarker(int $teacherId, int $templateId, string $instanceKey, string $instanceLabel, string $sourceVersion): bool
    {
        $stmt = Database::connection()->prepare('INSERT INTO risdoc_teacher_overrides
                (teacher_id, template_id, instance_key, instance_label,
                 kind, relative_path, body, source_version)
             VALUES (?,?,?,?,?,?,?,?)
             ON DUPLICATE KEY UPDATE id = id');
        $stmt->execute([
            $teacherId, $templateId, $instanceKey, $instanceLabel,
            'json', '__instance_marker__', '', $sourceVersion,
        ]);
// MySQL rowCount(): 1 = INSERT eseguito, 0 = ON DUPLICATE no-op.
        return $stmt->rowCount() === 1;
    }

    /**
     * Phase 24.58 — Update label di un'istanza (rinomina).
     */
    public function renameInstance(int $teacherId, int $templateId, string $instanceKey, string $newLabel): int
    {
        $stmt = Database::connection()->prepare('UPDATE risdoc_teacher_overrides
             SET instance_label=?
             WHERE teacher_id=? AND template_id=? AND instance_key=?');
        $stmt->execute([$newLabel, $teacherId, $templateId, $instanceKey]);
        return $stmt->rowCount();
    }

    private function assertKind(string $kind): void
    {
        if (!in_array($kind, self::KINDS, true)) {
            throw new \InvalidArgumentException("invalid kind: {$kind}");
        }
    }
}
