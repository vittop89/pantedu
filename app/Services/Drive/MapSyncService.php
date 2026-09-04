<?php

declare(strict_types=1);

namespace App\Services\Drive;

use App\Core\Config;
use App\Core\Database;
use App\Repositories\DriveOAuthRepository;
use App\Services\Maps\MapBlobStore;
use Google\Service\Drive\DriveFile;
use PDO;
use Throwable;

/**
 * Phase G5 — Push DB → Drive: per ogni mappa con map_blob_path, cifrato
 * server-side, decrypta e uploada (o aggiorna) il file su Drive del docente
 * nella cartella semantica `Pantedu/{istituto}/{ind}/{cls}/{materia}/mappe/`.
 *
 * Triggered da:
 *   - Pulsante UI ☁ singolo (POST /api/maps/{id}/sync)
 *   - Pulsante UI ☁ globale teacher (POST /api/drive/sync-all)
 *   - Cron notturno (tools/cron/drive_sync_nightly.php)
 *
 * Idempotente: file gia' presente su Drive con stesso checksum → skip.
 * Se diverso → update via files.update preservando il drive_file_id.
 *
 * Trade-off documentato in ADR-009: server e' single source of truth.
 * Modifiche fatte direttamente su Drive (bypass app) verranno
 * sovrascritte dal sync. UI prossimamente mostrera' "ultima sync" e
 * warning se diff temporal grosso (G7).
 *
 * Contratto:
 *   syncOne(int $contentId, int $teacherId): array{ok:bool, drive_file_id?:string, action:string, error?:string}
 *   syncAllForTeacher(int $teacherId, ?int $limit=null): array{count:int, ok:int, skip:int, error:int, items:array}
 */
final class MapSyncService
{
    public const ACTION_CREATED = 'created';
    public const ACTION_UPDATED = 'updated';
    public const ACTION_SKIPPED = 'skipped';
    public const ACTION_FAILED  = 'failed';
    public const ACTION_ORPHAN  = 'orphan'; // G22.S15.bis Fase 5

    private DriveClient $driveClient;
    private DriveOAuthRepository $oauthRepo;
    private MapBlobStore $blobStore;

    public function __construct(
        ?DriveClient $driveClient = null,
        ?DriveOAuthRepository $oauthRepo = null,
        ?MapBlobStore $blobStore = null
    ) {
        $this->driveClient = $driveClient ?? new DriveClient();
        $this->oauthRepo   = $oauthRepo   ?? new DriveOAuthRepository();
        $this->blobStore   = $blobStore   ?? new MapBlobStore();
    }

    /**
     * Sync di UNA mappa specifica per il docente. Carica il blob locale,
     * risolve la cartella Drive, upload o update.
     *
     * @return array{ok:bool, action:string, drive_file_id?:string, error?:string, mime?:string, size?:int}
     */
    public function syncOne(int $contentId, int $teacherId): array
    {
        if (!$this->oauthRepo->isConnected($teacherId)) {
            return ['ok' => false, 'action' => self::ACTION_FAILED, 'error' => 'drive_not_connected'];
        }

        $row = $this->loadMapRow($contentId, $teacherId);
        if (!$row) {
            return ['ok' => false, 'action' => self::ACTION_FAILED, 'error' => 'not_found'];
        }
        if (empty($row['map_blob_path'])) {
            // Mappa link-only legacy: niente blob da pushare. Skip.
            return ['ok' => true, 'action' => self::ACTION_SKIPPED, 'error' => 'no_blob'];
        }

        try {
            $drive = $this->driveClient->getDriveFor($teacherId);
            $folderPath = $this->buildFolderPath($row);
            $tree = new FolderTreeBuilder($drive, $teacherId);
            $folderId = $tree->resolve($folderPath);

            $plaintext = $this->blobStore->get($teacherId, (string)$row['map_blob_path']);
            $filename  = $this->buildFilename($row);
            $mime      = (string)($row['map_mime'] ?? 'application/octet-stream');

            // Phase G5/G7 — Per mappe `drive_legacy` il map_drive_id punta
            // al file ORIGINALE non gestito dall'app (scope drive.file non
            // puo' modificarlo: appNotAuthorizedToFile 403). Trattiamo come
            // first-sync: CREATE nuovo file in Pantedu/.../mappe/, poi
            // map_drive_id viene riassegnato all'ID app-managed. Sync
            // successivi useranno files.update normalmente.
            $isLegacy = ((string)($row['map_origin'] ?? '')) === 'drive_legacy';
            $existingId = $isLegacy ? '' : (string)($row['map_drive_id'] ?? '');
            if ($existingId !== '') {
                // Verify il file esiste ancora (potrebbe essere stato cancellato
                // dall'utente in Drive). Se 404 → ricrea.
                if (!$this->driveFileExists($drive, $existingId)) {
                    $existingId = '';
                }
            }

            if ($existingId !== '') {
                // UPDATE: aggiorna media + name (folder move via parents non
                // necessario qui — i path non cambiano post-create salvo
                // rinomine classe, gestite con re-create in G7).
                $upd = new DriveFile(['name' => $filename]);
                $created = $drive->files->update($existingId, $upd, [
                    'data'       => $plaintext,
                    'mimeType'   => $mime,
                    'uploadType' => 'multipart',
                    'fields'     => 'id',
                ]);
                $driveFileId = (string)$created->getId();
                $action = self::ACTION_UPDATED;
            } else {
                // CREATE: nuovo file in folderId.
                $newFile = new DriveFile([
                    'name'    => $filename,
                    'parents' => [$folderId],
                ]);
                $created = $drive->files->create($newFile, [
                    'data'       => $plaintext,
                    'mimeType'   => $mime,
                    'uploadType' => 'multipart',
                    'fields'     => 'id',
                ]);
                $driveFileId = (string)$created->getId();
                $action = self::ACTION_CREATED;
            }

            // Persist drive_file_id su teacher_content (idempotency next time).
            // Per i drive_legacy: dopo CREATE, il map_origin diventa upload
            // (file gestito dall'app, scope drive.file ora consente update).
            // map_drive_id riassegnato dal NUOVO file ID app-managed.
            $newOrigin = $isLegacy ? 'upload' : (string)($row['map_origin'] ?? 'upload');
            $stmt = Database::connection()->prepare(
                'UPDATE teacher_content_data SET map_drive_id = ?, map_origin = ?, updated_at = NOW()
                 WHERE id = ? AND teacher_id = ?'
            );
            $stmt->execute([$driveFileId, $newOrigin, $contentId, $teacherId]);

            return [
                'ok'             => true,
                'action'         => $action,
                'drive_file_id'  => $driveFileId,
                'mime'           => $mime,
                'size'           => strlen($plaintext),
            ];
        } catch (Throwable $e) {
            $msg = $e->getMessage();
            error_log("MapSyncService.syncOne id=$contentId: " . $msg);
            // G22.S15.bis Fase 5 — blob mancante = riga DB orfana, marker
            // dedicato così il frontend non lo conta come errore generico.
            if (str_contains($msg, 'blob_store_not_found')) {
                return ['ok' => false, 'action' => self::ACTION_ORPHAN, 'error' => 'blob_orphan'];
            }
            return ['ok' => false, 'action' => self::ACTION_FAILED, 'error' => 'sync_failed: ' . $msg];
        }
    }

    /**
     * Sync di TUTTE le mappe del docente con map_blob_path. Best-effort:
     * un fallimento su 1 non blocca il batch. Limit configurabile (default
     * Config drive.limits.sync_per_run_max_files).
     *
     * @return array{
     *   count:int, ok:int, skip:int, error:int,
     *   items:list<array{id:int, action:string, error?:string, drive_file_id?:string}>
     * }
     */
    public function syncAllForTeacher(int $teacherId, ?int $limit = null, bool $onlyChanged = false): array
    {
        $maxFiles = $limit ?? (int)Config::get('drive.limits.sync_per_run_max_files', 200);

        // Phase G7 — modalita' selezione file da pushare:
        //   - default (cron): TUTTE le mappe del docente (re-sync periodico).
        //   - onlyChanged=true (UI manual sync): solo le mappe mai syncate
        //     (map_drive_id NULL) OPPURE modificate dopo l'ultimo sync
        //     globale (updated_at > teacher_drive_oauth.last_sync_at).
        //     Permette al loop frontend di convergere e di rinfrescare
        //     solo cio' che e' cambiato.
        $whereExtra = '';
        $bind = [$teacherId];
        if ($onlyChanged) {
            $whereExtra = ' AND (map_drive_id IS NULL OR updated_at > COALESCE(
                                  (SELECT last_sync_at FROM teacher_drive_oauth
                                   WHERE teacher_id = ?),
                                  "1970-01-01 00:00:00"
                                ))';
            $bind[] = $teacherId;
        }
        $stmt = Database::connection()->prepare(
            'SELECT id FROM teacher_content
             WHERE teacher_id = ? AND content_type = "mappa"
               AND map_blob_path IS NOT NULL' . $whereExtra . '
             ORDER BY updated_at ASC
             LIMIT ' . (int)$maxFiles
        );
        $stmt->execute($bind);
        $ids = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));

        $report = ['count' => count($ids), 'ok' => 0, 'skip' => 0, 'error' => 0, 'orphan' => 0, 'items' => []];

        foreach ($ids as $id) {
            $r = $this->syncOne($id, $teacherId);
            $entry = ['id' => $id, 'action' => $r['action']];
            if (isset($r['drive_file_id'])) {
                $entry['drive_file_id'] = $r['drive_file_id'];
            }
            if ($r['action'] === self::ACTION_ORPHAN) {
                $entry['error'] = $r['error'] ?? 'blob_orphan';
                $report['orphan']++;
                // G22.S15.bis Fase 5 — marca map_drive_id sentinel per
                // escludere orphan da prossimi batch (filtro WHERE
                // map_drive_id IS NULL non matcherà più → loop break).
                try {
                    $st = Database::connection()->prepare(
                        'UPDATE teacher_content_data
                         SET map_drive_id = "blob_orphan"
                         WHERE id = ? AND teacher_id = ? AND content_type = "mappa"'
                    );
                    $st->execute([$id, $teacherId]);
                } catch (Throwable $_) {
                }
            } elseif (!$r['ok'] || $r['action'] === self::ACTION_FAILED) {
                $entry['error'] = $r['error'] ?? 'unknown';
                $report['error']++;
                if (str_contains((string)$entry['error'], 'invalid_grant')) {
                    $report['items'][] = $entry;
                    $report['drive_token_expired'] = true;
                    error_log("MapSyncService: drive token expired for teacher {$teacherId}, abort batch");
                    break;
                }
            } elseif ($r['action'] === self::ACTION_SKIPPED) {
                $report['skip']++;
            } else {
                $report['ok']++;
            }
            $report['items'][] = $entry;
        }

        $this->oauthRepo->touchLastSync($teacherId);
        return $report;
    }

    /**
     * @return array<string,mixed>|null
     */
    private function loadMapRow(int $contentId, int $teacherId): ?array
    {
        $stmt = Database::connection()->prepare(
            'SELECT id, teacher_id, content_type, subject_code, indirizzo, classe,
                    title, topic, map_blob_path, map_mime, map_drive_id, map_size,
                    map_origin, map_version
             FROM teacher_content
             WHERE id = ? AND teacher_id = ? AND content_type = "mappa"
             LIMIT 1'
        );
        $stmt->execute([$contentId, $teacherId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row !== false ? $row : null;
    }

    private function buildFolderPath(array $row): string
    {
        // G22.S20 v2.C2 Fase C — centralizza path building via BundlePathBuilder.
        // Same source of truth per Drive + Local + GitHub + Import.
        $stmt = Database::connection()->prepare(
            'SELECT i.code FROM teacher_institutes ti
             JOIN institutes i ON i.id = ti.institute_id
             WHERE ti.user_id = ?
             ORDER BY ti.created_at LIMIT 1'
        );
        $stmt->execute([(int)$row['teacher_id']]);
        $instCode = $stmt->fetchColumn();
        $instCode = is_string($instCode) && $instCode !== '' ? $instCode : 'default';

        return \App\Support\BundlePathBuilder::folderPath(
            $instCode,
            $row['indirizzo']    ?? null,
            $row['classe']       ?? null,
            $row['subject_code'] ?? null,
            'mappe'
        );
    }

    private function buildFilename(array $row): string
    {
        $title = (string)($row['title'] ?? 'mappa');
        $topic = (string)($row['topic'] ?? '');

        // Estensione da MIME.
        $ext = match ((string)($row['map_mime'] ?? '')) {
            'application/xml'  => '.drawio',
            'application/pdf'  => '.pdf',
            'image/png'        => '.png',
            'image/jpeg'       => '.jpg',
            'text/html'        => '.html',
            default            => '',
        };

        $base = $topic !== '' ? "{$topic}_{$title}" : $title;
        // Sanitize (Drive accetta '/', noi normalizziamo a '-').
        $base = preg_replace('/[\\\\\\/]+/', '-', $base) ?? $base;
        $base = trim($base);
        if ($base === '') {
            $base = 'mappa';
        }
        return $base . $ext;
    }

    private function driveFileExists(\Google\Service\Drive $drive, string $fileId): bool
    {
        try {
            $drive->files->get($fileId, ['fields' => 'id,trashed']);
            return true;
        } catch (Throwable) {
            return false;
        }
    }
}
