<?php

declare(strict_types=1);

namespace App\Services\Drive;

use App\Core\Config;
use App\Core\Database;
use App\Repositories\DriveOAuthRepository;
use App\Services\Crypto\EncryptedBlobStore;
use Google\Service\Drive\DriveFile;
use PDO;
use Throwable;

/**
 * Phase G19.47 — Push DB → Drive per verifiche TEX/PDF.
 *
 * Mirror di `MapSyncService` adattato a `verifica_documents`:
 *   - Per ogni doc decifra TEX blob (envelope crypto, namespace
 *     `verifiche_enc`)
 *   - Risolve cartella Drive:
 *     `Pantedu/{istituto}/{ind}/{cls}{sez}/{materia}/verifiche/{titolo}/`
 *   - CREATE/UPDATE file via Drive API
 *   - Persist `drive_file_id` + `drive_synced_at` su verifica_documents
 *   - Delete-orphan: list Drive folder → cancella file presenti su Drive
 *     ma assenti in DB (ownership best-effort, scope `drive.file`)
 *
 * Idempotente: doc gia' syncato + non modificato dopo `drive_synced_at`
 * → skip.
 *
 * Contratto:
 *   syncOne(int $docId, int $teacherId): array{ok, action, drive_file_id?, error?}
 *   syncAllForTeacher(int $teacherId, ?int $limit, bool $onlyChanged):
 *       array{count, ok, skip, error, deleted, items, deleted_items}
 */
final class VerificaSyncService
{
    public const ACTION_CREATED = 'created';
    public const ACTION_UPDATED = 'updated';
    public const ACTION_SKIPPED = 'skipped';
    public const ACTION_FAILED  = 'failed';
    public const ACTION_DELETED = 'deleted';
    public const ACTION_ORPHAN  = 'orphan'; // G22.S15.bis Fase 5 — blob mancante

    private DriveClient $driveClient;
    private DriveOAuthRepository $oauthRepo;
    private EncryptedBlobStore $blobStore;

    public function __construct(
        ?DriveClient $driveClient = null,
        ?DriveOAuthRepository $oauthRepo = null,
        ?EncryptedBlobStore $blobStore = null
    ) {
        $this->driveClient = $driveClient ?? new DriveClient();
        $this->oauthRepo   = $oauthRepo   ?? new DriveOAuthRepository();
        $this->blobStore   = $blobStore   ?? new EncryptedBlobStore('verifiche_enc');
    }

    public function syncOne(int $docId, int $teacherId): array
    {
        if (!$this->oauthRepo->isConnected($teacherId)) {
            return ['ok' => false, 'action' => self::ACTION_FAILED, 'error' => 'drive_not_connected'];
        }
        $row = $this->loadDocRow($docId, $teacherId);
        if (!$row) {
            return ['ok' => false, 'action' => self::ACTION_FAILED, 'error' => 'not_found'];
        }

        // G22.S4.B.2 — multi-file aware: row puo' avere `tex_files` manifest
        // (multi-blob) oppure `tex_blob_path` legacy. In entrambi i casi
        // facciamo upload del .tex monolitico (flatten per multi-file)
        // perche' Drive aspetta UN file per verifica (vedi
        // VerificaDocumentService::readTex).
        $hasMultiFile = !empty($row['tex_files']);
        $hasLegacyBlob = !empty($row['tex_blob_path']);
        if (!$hasMultiFile && !$hasLegacyBlob) {
            return ['ok' => true, 'action' => self::ACTION_SKIPPED, 'error' => 'no_blob'];
        }

        try {
            $drive = $this->driveClient->getDriveFor($teacherId);
            $folderPath = $this->buildFolderPath($row);
            $tree = new FolderTreeBuilder($drive, $teacherId);
            $folderId = $tree->resolve($folderPath);

            // Multi-file: flatten via Service. Legacy: read single blob.
            if ($hasMultiFile) {
                $svc = new \App\Services\Verifica\VerificaDocumentService(
                    null,
                    null,
                    $this->blobStore
                );
                $tex = $svc->readTex($teacherId, $docId);
            } else {
                $tex = $this->blobStore->get($teacherId, (string)$row['tex_blob_path']);
            }
            $filename = $this->buildFilename($row);
            $mime = 'application/x-tex';

            $existingId = (string)($row['drive_file_id'] ?? '');
            if ($existingId !== '' && !$this->driveFileExists($drive, $existingId)) {
                $existingId = '';
            }

            if ($existingId !== '') {
                $upd = new DriveFile(['name' => $filename]);
                $created = $drive->files->update($existingId, $upd, [
                    'data'       => $tex,
                    'mimeType'   => $mime,
                    'uploadType' => 'multipart',
                    'fields'     => 'id',
                ]);
                $action = self::ACTION_UPDATED;
            } else {
                $newFile = new DriveFile([
                    'name'    => $filename,
                    'parents' => [$folderId],
                ]);
                $created = $drive->files->create($newFile, [
                    'data'       => $tex,
                    'mimeType'   => $mime,
                    'uploadType' => 'multipart',
                    'fields'     => 'id',
                ]);
                $action = self::ACTION_CREATED;
            }
            $driveFileId = (string)$created->getId();

            $stmt = Database::connection()->prepare(
                'UPDATE verifica_documents_data
                 SET drive_file_id = ?, drive_synced_at = NOW()
                 WHERE id = ? AND teacher_id = ?'
            );
            $stmt->execute([$driveFileId, $docId, $teacherId]);

            return [
                'ok'             => true,
                'action'         => $action,
                'drive_file_id'  => $driveFileId,
                'mime'           => $mime,
                'size'           => strlen($tex),
            ];
        } catch (Throwable $e) {
            $msg = $e->getMessage();
            error_log("VerificaSyncService.syncOne id=$docId: " . $msg);
            // G22.S15.bis Fase 5 — blob mancante (DB orfano): marcato come
            // 'orphan' invece di 'failed'. Non genera errore-rumore nel log
            // sync; va gestito con cleanup endpoint dedicato (vedi
            // cleanupOrphans). Caso comune: riga teacher_content/verifica_doc
            // referenzia blob_path che è stato cancellato manualmente o
            // perso in migrazione/restore.
            if (str_contains($msg, 'blob_store_not_found') || str_contains($msg, 'verifica_manifest_empty')) {
                return ['ok' => false, 'action' => self::ACTION_ORPHAN, 'error' => 'blob_orphan'];
            }
            return ['ok' => false, 'action' => self::ACTION_FAILED, 'error' => 'sync_failed: ' . $msg];
        }
    }

    /**
     * Sync di TUTTE le verifiche del docente con tex_blob_path. Best-effort.
     * Se `$deleteOrphans=true` (default) cancella anche da Drive i file
     * scope `drive.file` non più presenti in DB (per quel teacher).
     *
     * @return array{count:int, ok:int, skip:int, error:int, deleted:int,
     *               items:list, deleted_items:list}
     */
    public function syncAllForTeacher(int $teacherId, ?int $limit = null, bool $onlyChanged = false, bool $deleteOrphans = true): array
    {
        $maxFiles = $limit ?? (int)Config::get('drive.limits.sync_per_run_max_files', 200);

        $whereExtra = '';
        $bind = [$teacherId];
        if ($onlyChanged) {
            $whereExtra = ' AND (drive_file_id IS NULL OR updated_at > COALESCE(drive_synced_at, "1970-01-01 00:00:00"))';
        }
        // G22.S4.B.2 — accetta sia row multi-file (tex_files) sia legacy
        // (tex_blob_path). Skip solo se nessuno dei due.
        $stmt = Database::connection()->prepare(
            'SELECT id FROM verifica_documents
             WHERE teacher_id = ?
               AND (tex_blob_path IS NOT NULL OR tex_files IS NOT NULL)' . $whereExtra . '
             ORDER BY updated_at ASC
             LIMIT ' . (int)$maxFiles
        );
        $stmt->execute($bind);
        $ids = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));

        $report = [
            'count' => count($ids), 'ok' => 0, 'skip' => 0, 'error' => 0,
            'orphan' => 0,
            'deleted' => 0, 'items' => [], 'deleted_items' => [],
        ];
        foreach ($ids as $id) {
            $r = $this->syncOne($id, $teacherId);
            $entry = ['id' => $id, 'action' => $r['action']];
            if (isset($r['drive_file_id'])) {
                $entry['drive_file_id'] = $r['drive_file_id'];
            }
            if ($r['action'] === self::ACTION_ORPHAN) {
                $entry['error'] = $r['error'] ?? 'blob_orphan';
                $report['orphan']++;
                // G22.S15.bis Fase 5 — marca drive_synced_at per orphan: la
                // query con onlyChanged WHERE updated_at > drive_synced_at
                // li escluderà dai prossimi batch, evitando loop infinito.
                try {
                    $st = Database::connection()->prepare(
                        'UPDATE verifica_documents_data
                         SET drive_synced_at = NOW()
                         WHERE id = ? AND teacher_id = ?'
                    );
                    $st->execute([$id, $teacherId]);
                } catch (Throwable $_) {
                }
            } elseif (!$r['ok'] || $r['action'] === self::ACTION_FAILED) {
                $entry['error'] = $r['error'] ?? 'unknown';
                $report['error']++;
                // G22.S15.bis Fase 5 — invalid_grant: abort batch.
                if (str_contains((string)$entry['error'], 'invalid_grant')) {
                    $report['items'][] = $entry;
                    $report['drive_token_expired'] = true;
                    error_log("VerificaSyncService: drive token expired for teacher {$teacherId}, abort batch");
                    break;
                }
            } elseif ($r['action'] === self::ACTION_SKIPPED) {
                $report['skip']++;
            } else {
                $report['ok']++;
            }
            $report['items'][] = $entry;
        }

        // G19.47 — Delete-orphan: file con drive_file_id NULL in DB? No,
        // gli orfani sono LATO DRIVE (esistevano in DB, sono stati cancellati).
        // Best detection: track drive_file_id "in vita" in DB e confronta con
        // lista file in cartella Drive `Pantedu/.../verifiche/`.
        if ($deleteOrphans && $this->oauthRepo->isConnected($teacherId)) {
            try {
                $deleted = $this->deleteOrphansFromDrive($teacherId);
                $report['deleted'] = count($deleted);
                $report['deleted_items'] = $deleted;
            } catch (Throwable $e) {
                error_log("VerificaSyncService.deleteOrphans: " . $e->getMessage());
            }
        }

        $this->oauthRepo->touchLastSync($teacherId);
        return $report;
    }

    /**
     * Cancella da Drive i file presenti nelle cartelle `verifiche/` del docente
     * il cui ID NON e' piu' presente in `verifica_documents.drive_file_id`.
     * Scope `drive.file` consente delete solo su file creati dall'app.
     *
     * @return list<array{drive_file_id:string, name:string, error?:string}>
     */
    private function deleteOrphansFromDrive(int $teacherId): array
    {
        // Set di drive_file_id ancora "vivi" in DB
        $stmt = Database::connection()->prepare(
            'SELECT drive_file_id FROM verifica_documents
             WHERE teacher_id = ? AND drive_file_id IS NOT NULL'
        );
        $stmt->execute([$teacherId]);
        $aliveIds = array_flip(array_map('strval', $stmt->fetchAll(PDO::FETCH_COLUMN)));

        // Lista file owned dall'app nella cartella verifiche/* del docente.
        // Scope `drive.file` filtra automaticamente su file creati dall'app.
        $drive = $this->driveClient->getDriveFor($teacherId);
        $deleted = [];
        try {
            // Query Drive: tutti i file .tex creati dall'app non in trash
            $pageToken = null;
            do {
                $resp = $drive->files->listFiles([
                    'q' => "trashed = false and mimeType = 'application/x-tex'",
                    'fields' => 'nextPageToken,files(id,name,parents)',
                    'pageSize' => 100,
                    'pageToken' => $pageToken,
                ]);
                foreach ($resp->getFiles() as $f) {
                    $fid = (string)$f->getId();
                    if (isset($aliveIds[$fid])) {
                        continue;
                    }
                    // Orfano: cancella
                    try {
                        $drive->files->delete($fid);
                        $deleted[] = ['drive_file_id' => $fid, 'name' => (string)$f->getName()];
                    } catch (Throwable $e) {
                        $deleted[] = ['drive_file_id' => $fid, 'name' => (string)$f->getName(), 'error' => $e->getMessage()];
                    }
                }
                $pageToken = $resp->getNextPageToken();
            } while ($pageToken);
        } catch (Throwable $e) {
            error_log("VerificaSyncService.deleteOrphansFromDrive: " . $e->getMessage());
        }
        return $deleted;
    }

    private function loadDocRow(int $docId, int $teacherId): ?array
    {
        // G22.S4.B.2 — include tex_files cosi' syncOne riconosce le row
        // multi-file (oltre alle legacy single-blob).
        $stmt = Database::connection()->prepare(
            'SELECT id, teacher_id, materia, indirizzo, classe, title, batch_id, variant,
                    version_label, tex_blob_path, tex_files, tex_size,
                    drive_file_id, created_at
             FROM verifica_documents
             WHERE id = ? AND teacher_id = ? LIMIT 1'
        );
        $stmt->execute([$docId, $teacherId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row !== false ? $row : null;
    }

    /**
     * G19.48 — Path mirror della struttura `MapSyncService.buildFolderPath`:
     *   `{istituto}/{indirizzo}/{classe}/{materia}/verifiche/{titolo_clean}/{version_folder}`
     *
     * `version_folder` = `{version_label|"v0"}-{DD_MM_YYYY}-{KINDS}` con
     * KINDS ordinati canonicamente (SOL_NOR_DSA_DIS) sui variant esistenti
     * nel batch. Se il doc non e' parte di un batch (legacy single-doc) o
     * non ha version_label, il folder version e' costruito ugualmente con
     * fallback `v0` + kinds derivati dalla variante propria.
     */
    private function buildFolderPath(array $row): string
    {
        // G22.S20 v2.C2 Fase C — centralizza path building via BundlePathBuilder.
        // Same source of truth per Drive + Local + GitHub + Import.
        $instCode = $this->resolveInstituteCode((int)$row['teacher_id']);
        $title   = (string)($row['title'] ?? 'verifica');
        $titleClean = preg_replace('/\s*[—-]\s*[AB]_(SOL|NOR|DSA|DIS)\s*$/u', '', $title) ?: $title;
        $versionFolder = $this->buildVersionFolder($row);

        $folder = \App\Support\BundlePathBuilder::folderPath(
            $instCode,
            $row['indirizzo'] ?? null,
            $row['classe']    ?? null,
            $row['materia']   ?? null,
            'verifiche'
        );
        $folder .= '/' . \App\Support\BundlePathBuilder::sanitizeSegment($titleClean);
        if ($versionFolder !== '') {
            $folder .= '/' . \App\Support\BundlePathBuilder::sanitizeSegment($versionFolder);
        }
        return $folder;
    }

    private function resolveInstituteCode(int $teacherId): string
    {
        return \App\Support\TeacherContextResolver::instituteCodeForTeacher($teacherId);
    }

    /**
     * Compone `{version_label}-{DD_MM_YYYY}-{KINDS}` dove KINDS e' la lista
     * ordinata SOL_NOR_DSA_DIS dei variant presenti nel batch (o solo del
     * doc corrente se non in batch). Stringa vuota se mancano dati per
     * costruirla (compat legacy: nessun version folder).
     */
    private function buildVersionFolder(array $row): string
    {
        $kinds = $this->collectBatchKinds($row);
        if ($kinds === []) {
            // Singola variante derivata dalla colonna `variant`
            $m = [];
            $variant = (string)($row['variant'] ?? '');
            if ($variant !== '' && preg_match('/(SOL|NOR|DSA|DIS)$/', $variant, $m)) {
                $kinds = [$m[1]];
            }
        }
        if ($kinds === []) {
            return '';
        }

        $label = (string)($row['version_label'] ?? '');
        if ($label === '') {
            $label = 'v0';
        }

        $createdAt = (string)($row['created_at'] ?? '');
        $ts = $createdAt !== '' ? strtotime($createdAt) : false;
        if ($ts === false || $ts === 0) {
            $ts = time();
        }
        $date = date('d_m_Y', $ts);

        $kindsStr = implode('_', $kinds);
        return $label . '-' . $date . '-' . $kindsStr;
    }

    /**
     * Ritorna i kind (SOL/NOR/DSA/DIS) dei doc del batch del row corrente,
     * ordinati canonicamente (SOL → NOR → DSA → DIS). Vuoto se row non e'
     * in un batch o se la query fallisce.
     *
     * @return list<string>
     */
    private function collectBatchKinds(array $row): array
    {
        $batchId = (string)($row['batch_id'] ?? '');
        if ($batchId === '') {
            return [];
        }
        $stmt = Database::connection()->prepare(
            'SELECT DISTINCT variant FROM verifica_documents
             WHERE batch_id = ? AND teacher_id = ?'
        );
        $stmt->execute([$batchId, (int)$row['teacher_id']]);
        $variants = $stmt->fetchAll(PDO::FETCH_COLUMN);
        $set = [];
        foreach ($variants as $v) {
            if (preg_match('/(SOL|NOR|DSA|DIS)$/', (string)$v, $m)) {
                $set[$m[1]] = true;
            }
        }
        $order = ['SOL', 'NOR', 'DSA', 'DIS'];
        $out = [];
        foreach ($order as $k) {
            if (isset($set[$k])) {
                $out[] = $k;
            }
        }
        return $out;
    }

    private function buildFilename(array $row): string
    {
        $title = (string)($row['title'] ?? 'verifica');
        $variant = (string)($row['variant'] ?? '');
        $version = (string)($row['version_label'] ?? '');
        $base = preg_replace('/[\\\\\\/]+/', '-', $title) ?? $title;
        $base = trim((string)preg_replace('/\s+/', '_', $base));
        if ($base === '') {
            $base = 'verifica';
        }
        $suffix = '';
        if ($version !== '') {
            $suffix .= '_' . $version;
        }
        if ($variant !== '') {
            $suffix .= '_' . $variant;
        }
        return $base . $suffix . '.tex';
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
