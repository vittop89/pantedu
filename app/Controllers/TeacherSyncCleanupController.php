<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Database;
use App\Core\Request;
use App\Core\Response;
use PDO;
use Throwable;

/**
 * G22.S15.bis Fase 5 — Cleanup di righe DB orfane (blob mancante su disco).
 *
 *   POST /api/teacher/sync/cleanup-orphans
 *      → scans verifica_documents + teacher_content (kind=mappa) per il
 *        docente, verifica esistenza blob su disco. Se mancante, marca la
 *        riga come da rimuovere (default: dry-run).
 *      Body opzionale: { confirm: true } per applicare DELETE effettivamente.
 *
 *   Risposta: { ok, dry_run, scanned, orphan_verifiche, orphan_mappe,
 *               deleted_verifiche, deleted_mappe }
 *
 * Sicurezza: solo righe del teacher autenticato. Niente cross-tenant.
 */
final class TeacherSyncCleanupController
{
    public function cleanupOrphans(Request $req): Response
    {
        $tid = $this->teacherId();
        if (!$tid) {
            return Response::json(['ok' => false, 'error' => 'unauthorized'], 401);
        }
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }

        $payload = json_decode((string)file_get_contents('php://input'), true) ?: [];
        $confirm = !empty($payload['confirm']);

        $stats = [
            'ok' => true,
            'dry_run' => !$confirm,
            'scanned' => 0,
            'orphan_verifiche' => [],
            'orphan_mappe' => [],
            'deleted_verifiche' => 0,
            'deleted_mappe' => 0,
        ];

        try {
            $verifBlobRoot = dirname(__DIR__, 2) . '/storage/verifiche_enc';
            $mapBlobRoot   = dirname(__DIR__, 2) . '/storage/maps_enc';

            // Scan verifica_documents
            $stmt = Database::connection()->prepare(
                'SELECT id, title, variant, tex_blob_path, tex_files, pdf_blob_path
                 FROM verifica_documents WHERE teacher_id = ?'
            );
            $stmt->execute([$tid]);
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $stats['scanned']++;
                $isOrphan = false;
                // Check single-blob legacy
                if (!empty($row['tex_blob_path'])) {
                    if (!is_file($verifBlobRoot . '/' . $row['tex_blob_path'])) {
                        $isOrphan = true;
                    }
                }
                // Check multi-file manifest
                if (!$isOrphan && !empty($row['tex_files'])) {
                    $manifest = is_array($row['tex_files'])
                        ? $row['tex_files']
                        : (json_decode((string)$row['tex_files'], true) ?: []);
                    foreach ($manifest as $f) {
                        if (
                            is_array($f) && !empty($f['blob_path'])
                            && !is_file($verifBlobRoot . '/' . $f['blob_path'])
                        ) {
                            $isOrphan = true;
                            break;
                        }
                    }
                }
                if ($isOrphan) {
                    $stats['orphan_verifiche'][] = [
                        'id' => (int)$row['id'],
                        'title' => (string)($row['title'] ?? ''),
                        'variant' => (string)($row['variant'] ?? ''),
                    ];
                }
            }

            // Scan teacher_content kind=mappa
            $stmt = Database::connection()->prepare(
                'SELECT id, title, map_blob_path FROM teacher_content
                 WHERE teacher_id = ? AND content_type = "mappa"
                   AND map_blob_path IS NOT NULL'
            );
            $stmt->execute([$tid]);
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $stats['scanned']++;
                if (!is_file($mapBlobRoot . '/' . $row['map_blob_path'])) {
                    $stats['orphan_mappe'][] = [
                        'id' => (int)$row['id'],
                        'title' => (string)($row['title'] ?? ''),
                    ];
                }
            }

            // Apply delete se confirm=true
            if ($confirm) {
                $db = Database::connection();
                if ($stats['orphan_verifiche']) {
                    $ids = array_column($stats['orphan_verifiche'], 'id');
                    $placeholders = implode(',', array_fill(0, count($ids), '?'));
                    $bind = array_merge([$tid], $ids);
                    $stmt = $db->prepare(
                        "DELETE FROM verifica_documents_data
                         WHERE teacher_id = ? AND id IN ({$placeholders})"
                    );
                    $stmt->execute($bind);
                    $stats['deleted_verifiche'] = $stmt->rowCount();
                }
                if ($stats['orphan_mappe']) {
                    $ids = array_column($stats['orphan_mappe'], 'id');
                    $placeholders = implode(',', array_fill(0, count($ids), '?'));
                    $bind = array_merge([$tid], $ids);
                    $stmt = $db->prepare(
                        "DELETE FROM teacher_content_data
                         WHERE teacher_id = ? AND content_type = 'mappa' AND id IN ({$placeholders})"
                    );
                    $stmt->execute($bind);
                    $stats['deleted_mappe'] = $stmt->rowCount();
                }
            }

            return Response::json($stats);
        } catch (Throwable $e) {
            return Response::json([
                'ok' => false, 'error' => $e->getMessage(),
            ], 500);
        }
    }

    private function teacherId(): int
    {
        if (!Auth::check()) {
            return 0;
        }
        $u = Auth::user();
        return (int)($u['id'] ?? 0);
    }
}
