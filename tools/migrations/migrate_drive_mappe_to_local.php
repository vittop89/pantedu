<?php
/**
 * Phase G6 — Migrazione mappe legacy Drive → blob locale cifrato.
 *
 * Sorgente: teacher_content content_type='mappa' WHERE
 *   - map_blob_path IS NULL (non gia' migrate)
 *   - metadata_json.mappa.drawio_id presente (legacy import Phase 18)
 *
 * Per ogni mappa:
 *   1. Risolve teacher_drive_oauth (deve essere connected con scope
 *      drive.readonly — vedi /teacher/drive/connect-migration).
 *   2. Drive API files.get(drawio_id, alt=media) → bytes XML.
 *   3. MapBlobStore::put(teacher_id, bytes) → blob_path locale cifrato.
 *   4. UPDATE teacher_content SET map_blob_path, map_mime, map_size,
 *      map_drive_id, map_origin='drive_legacy', map_version=1.
 *
 * Failure mode:
 *   - 404/403/permission denied → flag map_origin='drive_orphan' con
 *     map_drive_id preserved (link viewer.diagrams.net continua
 *     funzionante in modalita' degraded).
 *
 * Resume-safe:
 *   skip se map_blob_path gia' set. Idempotent: re-run no-op su mappe
 *   gia' migrate.
 *
 * Uso:
 *   # dry-run (default)
 *   php tools/migrations/migrate_drive_mappe_to_local.php
 *   # solo per teacher_id specifico
 *   php tools/migrations/migrate_drive_mappe_to_local.php --teacher=77
 *   # commit
 *   php tools/migrations/migrate_drive_mappe_to_local.php --apply
 *   # commit per teacher specifico
 *   php tools/migrations/migrate_drive_mappe_to_local.php --teacher=77 --apply
 *
 * Pre-requisito: ogni teacher target deve aver fatto re-consent OAuth con
 * scope drive.readonly tramite /teacher/drive/connect-migration. Il script
 * controlla e segnala teacher senza scope appropriato.
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    echo "cli_only";
    exit(2);
}

require_once __DIR__ . '/../../app/bootstrap.php';

use App\Core\Config;
use App\Core\Database;
use App\Repositories\DriveOAuthRepository;
use App\Services\Drive\DriveClient;
use App\Services\Maps\MapBlobStore;

if (!Config::get('database.enabled')) {
    fwrite(STDERR, "DB_ENABLED=false — abilita nel .env.\n");
    exit(1);
}

set_time_limit(0);

$apply        = \in_array('--apply', $argv, true);
$teacherFilter = null;
foreach ($argv as $a) {
    if (preg_match('/^--teacher=(\d+)$/', $a, $m)) {
        $teacherFilter = (int)$m[1];
    }
}

$pdo       = Database::connection();
$oauthRepo = new DriveOAuthRepository();
$client    = new DriveClient();
$blobStore = new MapBlobStore();

$mode = $apply ? 'APPLY' : 'DRY-RUN';
echo "[migrate_drive_mappe] $mode mode" . ($teacherFilter ? " (teacher=$teacherFilter)" : '') . PHP_EOL;

// Carica mappe candidate.
$where = "content_type='mappa' AND map_blob_path IS NULL "
       . "AND JSON_UNQUOTE(JSON_EXTRACT(metadata_json, '\$.mappa.drawio_id')) IS NOT NULL";
$bind = [];
if ($teacherFilter !== null) {
    $where .= ' AND teacher_id = ?';
    $bind[] = $teacherFilter;
}
$stmt = $pdo->prepare(
    "SELECT id, teacher_id, title,
            JSON_UNQUOTE(JSON_EXTRACT(metadata_json, '\$.mappa.drawio_id')) AS drawio_id,
            JSON_UNQUOTE(JSON_EXTRACT(metadata_json, '\$.mappa.href'))     AS href
     FROM teacher_content
     WHERE $where
     ORDER BY teacher_id, id"
);
$stmt->execute($bind);
$rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);

echo "[migrate_drive_mappe] candidati: " . count($rows) . PHP_EOL;
if (!$rows) exit(0);

// Group by teacher per minimizzare i Drive client init (1 per teacher).
$byTeacher = [];
foreach ($rows as $r) {
    $byTeacher[(int)$r['teacher_id']][] = $r;
}

$totalOk      = 0;
$totalOrphan  = 0;
$totalSkipped = 0;
$totalFailed  = 0;

foreach ($byTeacher as $tid => $maps) {
    $tcount = count($maps);
    echo PHP_EOL . "[teacher_id=$tid] $tcount mappe da migrare" . PHP_EOL;

    if (!$oauthRepo->isConnected($tid)) {
        echo "  ⚠ Drive non collegato → skip teacher" . PHP_EOL;
        $totalSkipped += $tcount;
        continue;
    }
    $meta = $oauthRepo->getMetadata($tid);
    if (!str_contains((string)($meta['scope'] ?? ''), 'drive.readonly')) {
        echo "  ⚠ Scope drive.readonly mancante (scope attuale: {$meta['scope']})." . PHP_EOL;
        echo "    Il docente deve fare re-consent: /teacher/drive/connect-migration" . PHP_EOL;
        $totalSkipped += $tcount;
        continue;
    }

    try {
        $drive = $client->getDriveFor($tid);
    } catch (\Throwable $e) {
        echo "  ⚠ DriveClient init failed: " . $e->getMessage() . PHP_EOL;
        $totalFailed += $tcount;
        continue;
    }

    $consecutive404 = 0;
    foreach ($maps as $row) {
        $id        = (int)$row['id'];
        $drawioId  = (string)$row['drawio_id'];
        $title     = (string)$row['title'];

        if ($drawioId === '') {
            echo "  - id=$id no drawio_id → skip" . PHP_EOL;
            $totalSkipped++;
            continue;
        }

        // ID legacy del scriptGoogle_sync (formato "id-YYYYMMDDHHMMSSXXX")
        // NON sono veri Drive ID — sono ULID interni del JSON sync system.
        // Marchiamoli orphan immediato senza tentare Drive API (404 garantito).
        if (str_starts_with($drawioId, 'id-')) {
            echo "  - id=$id drawio_id=$drawioId → ORPHAN (legacy fake id, mai su Drive)" . PHP_EOL;
            if ($apply) {
                $upd = $pdo->prepare(
                    "UPDATE teacher_content
                     SET map_origin='drive_orphan'
                     WHERE id=? AND teacher_id=?"
                );
                $upd->execute([$id, $tid]);
            }
            $totalOrphan++;
            // NON incrementiamo consecutive404 — questi sono orphan strutturali,
            // non indicano scope error.
            continue;
        }

        // Circuit breaker: se 5 Drive ID veri restituiscono 404 in fila,
        // sospetto problema di scope (refresh_token con drive.file, file
        // pre-esistenti invisibili). Abort + warning chiaro.
        if ($consecutive404 >= 5) {
            echo "  ⚠ ABORT: 5+ Drive ID veri consecutivi 404. Probabile scope error." . PHP_EOL;
            echo "    Soluzione: revocare l'app da https://myaccount.google.com/permissions" . PHP_EOL;
            echo "    poi /teacher/drive/disconnect, poi /teacher/drive/connect-migration" . PHP_EOL;
            echo "    per ottenere refresh_token con scope drive.readonly effettivo." . PHP_EOL;
            $totalSkipped += count($maps) - ($totalOk + $totalOrphan + $totalFailed + $totalSkipped);
            break 2; // exit foreach $maps + foreach $byTeacher
        }

        try {
            $resp = $drive->files->get($drawioId, [
                'alt'    => 'media',
                'fields' => null,
            ]);
            $bytes = (string)$resp->getBody();
            $consecutive404 = 0;
        } catch (\Throwable $e) {
            $msg = $e->getMessage();
            $is404 = str_contains($msg, 'notFound') || str_contains($msg, '404');
            $is403 = str_contains($msg, 'forbidden') || str_contains($msg, '403');
            if ($is404 || $is403) {
                echo "  - id=$id drawio_id=$drawioId → ORPHAN ($msg)" . PHP_EOL;
                $consecutive404++;
                if ($apply && $consecutive404 < 5) {
                    // Mark orphan SOLO se non sospettiamo scope error globale.
                    $upd = $pdo->prepare(
                        "UPDATE teacher_content
                         SET map_origin='drive_orphan', map_drive_id=?
                         WHERE id=? AND teacher_id=?"
                    );
                    $upd->execute([$drawioId, $id, $tid]);
                }
                $totalOrphan++;
            } else {
                echo "  - id=$id ERROR: $msg" . PHP_EOL;
                $totalFailed++;
            }
            continue;
        }

        if ($bytes === '') {
            echo "  - id=$id empty download → skip" . PHP_EOL;
            $totalSkipped++;
            continue;
        }

        // Heuristic MIME detection: drawio XML inizia con '<' o gzip magic.
        $mime = (str_starts_with($bytes, '<') || str_starts_with($bytes, "\xEF\xBB\xBF<"))
            ? 'application/xml'
            : 'application/octet-stream';

        if (!$apply) {
            echo "  - id=$id [DRY-RUN] $title → " . strlen($bytes) . "B ($mime)" . PHP_EOL;
            $totalOk++;
            continue;
        }

        try {
            $blobPath = $blobStore->put($tid, $bytes);
            $upd = $pdo->prepare(
                "UPDATE teacher_content
                 SET map_blob_path=?, map_mime=?, map_size=?, map_drive_id=?,
                     map_origin='drive_legacy', map_version=1, updated_at=NOW()
                 WHERE id=? AND teacher_id=?"
            );
            $upd->execute([$blobPath, $mime, strlen($bytes), $drawioId, $id, $tid]);
            echo "  ✓ id=$id $title → $blobPath (" . strlen($bytes) . "B)" . PHP_EOL;
            $totalOk++;
        } catch (\Throwable $e) {
            echo "  - id=$id BLOB write failed: " . $e->getMessage() . PHP_EOL;
            $totalFailed++;
        }
    }
}

echo PHP_EOL . str_repeat('─', 60) . PHP_EOL;
echo "[migrate_drive_mappe] $mode summary:" . PHP_EOL;
echo "  ok      : $totalOk" . PHP_EOL;
echo "  orphan  : $totalOrphan (drive_id 404/403, viewer link preservato)" . PHP_EOL;
echo "  skipped : $totalSkipped" . PHP_EOL;
echo "  failed  : $totalFailed" . PHP_EOL;

if (!$apply && $totalOk > 0) {
    echo PHP_EOL . "Rerun with --apply to commit." . PHP_EOL;
}

exit($totalFailed > 0 ? 1 : 0);
