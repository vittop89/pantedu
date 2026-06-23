<?php

declare(strict_types=1);

namespace App\Services\Drive;

use App\Core\Config;
use App\Core\Database;
use Google\Service\Drive as GoogleDriveService;
use Google\Service\Drive\DriveFile;
use PDO;
use RuntimeException;

/**
 * Phase G5 — Costruisce/risolve l'albero cartelle Pantedu su Drive del
 * docente. Cache locale via teacher_drive_folder_cache (folder_path →
 * drive_folder_id) per evitare folder.list ripetuti (Drive API quota:
 * 1k req/100s/user).
 *
 * Schema albero:
 *   Pantedu/                     ← root, drive_root_id in teacher_drive_oauth
 *     {istituto}/                  ← code istituto (es. "ITIS-MAGGI-LECCO")
 *       {indirizzo}/               ← scientifico, artistico, ...
 *         {classe}/                ← sc1s, ar2s, ...
 *           {materia}/             ← MAT, FIS, GEO, ...
 *             mappe/               ← drawio + pdf
 *             verifiche/           ← PDF verifiche compilate
 *             risdoc/              ← PDF documenti istituzionali
 *
 * NB: usa scope drive.file → vediamo SOLO file/cartelle creati dall'app.
 * La root viene materializzata in Drive del docente alla prima sync con
 * `name="Pantedu" appProperties.fm_root="1" mimeType=folder`.
 */
final class FolderTreeBuilder
{
    private const FOLDER_MIME = 'application/vnd.google-apps.folder';

    public function __construct(
        private readonly GoogleDriveService $drive,
        private readonly int $teacherId,
        private readonly ?string $rootFolderName = null,
    ) {
    }

    /**
     * Risolve (o crea) l'ID cartella Drive corrispondente al path completo.
     *
     * @param string $path es. "ITIS-MAGGI-LECCO/scientifico/sc1s/MAT/mappe"
     * @return string drive_folder_id pronto per l'upload
     */
    public function resolve(string $path): string
    {
        $path = trim($path, '/');
        if ($path === '') {
            return $this->resolveRoot();
        }

        $cached = $this->cacheGet($path);
        if ($cached !== null) {
            return $cached;
        }

        // Risolve incrementalmente: per ogni segmento, cache hit o create.
        // Il root e' resolved a parte (cached in teacher_drive_oauth.drive_root_id).
        $parentId = $this->resolveRoot();
        $segments = explode('/', $path);
        $accumPath = '';

        foreach ($segments as $seg) {
            if ($seg === '') {
                continue;
            }
            $accumPath = $accumPath === '' ? $seg : $accumPath . '/' . $seg;

            $cachedSeg = $this->cacheGet($accumPath);
            if ($cachedSeg !== null) {
                $parentId = $cachedSeg;
                continue;
            }

            $folderId = $this->findOrCreateChild($parentId, $seg);
            $this->cachePut($accumPath, $folderId);
            $parentId = $folderId;
        }

        return $parentId;
    }

    /**
     * Risolve la root "Pantedu/". Cache in teacher_drive_oauth.drive_root_id;
     * se NULL, crea + popola.
     */
    private function resolveRoot(): string
    {
        $rootName = $this->rootFolderName
            ?? (string)Config::get('drive.root_folder_name', 'Pantedu');

        $pdo = Database::connection();
        $stmt = $pdo->prepare(
            'SELECT drive_root_id FROM teacher_drive_oauth WHERE teacher_id = ? LIMIT 1'
        );
        $stmt->execute([$this->teacherId]);
        $rootId = $stmt->fetchColumn();
        if (is_string($rootId) && $rootId !== '' && $rootId !== false) {
            return $rootId;
        }

        // Cerca esistente (drive.file scope vede solo le folder create da noi).
        $existing = $this->findFolderInParent('root', $rootName);
        if ($existing === null) {
            $existing = $this->createFolder('root', $rootName, ['fm_root' => '1']);
        }

        $upd = $pdo->prepare(
            'UPDATE teacher_drive_oauth SET drive_root_id = ? WHERE teacher_id = ?'
        );
        $upd->execute([$existing, $this->teacherId]);
        return $existing;
    }

    private function findOrCreateChild(string $parentId, string $name): string
    {
        $found = $this->findFolderInParent($parentId, $name);
        return $found ?? $this->createFolder($parentId, $name);
    }

    private function findFolderInParent(string $parentId, string $name): ?string
    {
        $q = sprintf(
            "mimeType='%s' and trashed=false and name='%s' and '%s' in parents",
            self::FOLDER_MIME,
            $this->escapeQ($name),
            $this->escapeQ($parentId)
        );
        $resp = $this->drive->files->listFiles([
            'q'             => $q,
            'fields'        => 'files(id,name)',
            'pageSize'      => 1,
            'supportsAllDrives' => false,
        ]);
        $files = $resp->getFiles();
        if (\count($files) === 0) {
            return null;
        }
        return $files[0]->getId();
    }

    /**
     * @param array<string,string> $appProperties
     */
    private function createFolder(string $parentId, string $name, array $appProperties = []): string
    {
        $folder = new DriveFile([
            'name'     => $name,
            'mimeType' => self::FOLDER_MIME,
            'parents'  => [$parentId],
        ]);
        if ($appProperties !== []) {
            $folder->setAppProperties($appProperties);
        }
        $created = $this->drive->files->create($folder, [
            'fields' => 'id',
        ]);
        return (string)$created->getId();
    }

    private function cacheGet(string $path): ?string
    {
        $stmt = Database::connection()->prepare(
            'SELECT drive_folder_id FROM teacher_drive_folder_cache
             WHERE teacher_id = ? AND folder_path = ? LIMIT 1'
        );
        $stmt->execute([$this->teacherId, $path]);
        $val = $stmt->fetchColumn();
        return ($val !== false && \is_string($val)) ? $val : null;
    }

    private function cachePut(string $path, string $folderId): void
    {
        $stmt = Database::connection()->prepare(
            'INSERT INTO teacher_drive_folder_cache
                (teacher_id, folder_path, drive_folder_id)
             VALUES (?, ?, ?)
             ON DUPLICATE KEY UPDATE
                drive_folder_id = VALUES(drive_folder_id),
                cached_at = CURRENT_TIMESTAMP'
        );
        $stmt->execute([$this->teacherId, $path, $folderId]);
    }

    /**
     * Escape per Drive query DSL (single quote = wrapper, backslash escape).
     */
    private function escapeQ(string $value): string
    {
        return str_replace(['\\', "'"], ['\\\\', "\\'"], $value);
    }
}
