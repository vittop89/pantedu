<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Config;
use App\Core\Database;
use App\Core\Request;
use App\Core\Response;
use App\Support\TeacherContextResolver;
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
 *               deleted_verifiche, deleted_mappe, sospetto? }
 *
 * Sicurezza: solo righe del teacher autenticato. Niente cross-tenant.
 */
final class TeacherSyncCleanupController
{
    /**
     * Sotto questo numero di documenti «tutti orfani» può essere vero; sopra,
     * è quasi certamente la cartella dei dati che non si trova.
     */
    private const SOGLIA_TUTTI_ORFANI = 3;

    public function cleanupOrphans(Request $req): Response
    {
        $tid = TeacherContextResolver::currentTeacherId();
        if (!$tid) {
            return Response::fail('unauthorized', 401);
        }
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }

        $payload = $req->json();
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
            // 2026-09-10 — qui c'era `dirname(__DIR__, 2) . '/storage/…'`, cioè
            // la cartella del REPOSITORY. Ma i blob li scrive EncryptedBlobStore
            // sotto `app.paths.storage`, che in produzione è PANTEDU_DATA_PATH,
            // fuori dal repository: nel contenitore `/var/www/pantedu/storage/maps_enc`
            // non esiste nemmeno.
            //
            // Conseguenza: ogni documento cifrato risultava «orfano», e con
            // `confirm` si cancellavano le righe di TUTTI — il 10 settembre 2026
            // sarebbero state 25 verifiche e 249 mappe, dopo una finestra di
            // conferma che assicurava «i blob sono già mancanti». Mai lanciata in
            // produzione (il registro delle attività non ha nessuna richiesta),
            // ma a un clic dal farlo. Gli orfani veri, guardando nel posto
            // giusto, erano undici mappe.
            $storage       = (string)Config::get('app.paths.storage', dirname(__DIR__, 2) . '/storage');
            $verifBlobRoot = $storage . '/verifiche_enc';
            $mapBlobRoot   = $storage . '/maps_enc';

            // Quanti documenti hanno davvero un blob da controllare: serve alla
            // rete più in basso, che confronta gli orfani con questo numero.
            $conBlob = 0;

            // Scan verifica_documents
            $stmt = Database::connection()->prepare(
                'SELECT id, title, variant, tex_blob_path, tex_files, pdf_blob_path
                 FROM verifica_documents WHERE teacher_id = ?'
            );
            $stmt->execute([$tid]);
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $stats['scanned']++;
                if (!empty($row['tex_blob_path']) || !empty($row['tex_files'])) {
                    $conBlob++;
                }
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
            // Apici singoli: una stringa SQL. Con le doppie, sotto `ANSI_QUOTES`
            // (e in SQLite senza la vecchia tolleranza) "mappa" diventa il nome
            // di una colonna che non esiste.
            $stmt = Database::connection()->prepare(
                "SELECT id, title, map_blob_path FROM teacher_content
                 WHERE teacher_id = ? AND content_type = 'mappa'
                   AND map_blob_path IS NOT NULL"
            );
            $stmt->execute([$tid]);
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $stats['scanned']++;
                $conBlob++;
                if (!is_file($mapBlobRoot . '/' . $row['map_blob_path'])) {
                    $stats['orphan_mappe'][] = [
                        'id' => (int)$row['id'],
                        'title' => (string)($row['title'] ?? ''),
                    ];
                }
            }

            // Rete, prima di qualunque DELETE.
            //
            // Una pulizia che trova orfano TUTTO non ha trovato orfani: ha
            // trovato un errore di configurazione — la cartella sbagliata, un
            // montaggio mancante, un disco vuoto. È esattamente il guasto che
            // questa classe ha avuto per mesi senza che nessuno lo vedesse.
            // Il resoconto si restituisce lo stesso, con il sospetto scritto;
            // la cancellazione no.
            $orfani = count($stats['orphan_verifiche']) + count($stats['orphan_mappe']);
            $cartellaAssente = ($stats['orphan_verifiche'] && !is_dir($verifBlobRoot))
                            || ($stats['orphan_mappe'] && !is_dir($mapBlobRoot));
            $tuttiOrfani = $conBlob >= self::SOGLIA_TUTTI_ORFANI && $orfani === $conBlob;

            if ($cartellaAssente || $tuttiOrfani) {
                $stats['sospetto'] = $cartellaAssente ? 'cartella_blob_assente' : 'tutti_orfani';
                $stats['messaggio'] = 'Tutti i documenti risultano senza file: è quasi certamente la '
                    . 'cartella dei dati che non si trova, non una pulizia da fare. '
                    . 'Non viene cancellato niente.';
                if ($confirm) {
                    $stats['ok'] = false;
                    $stats['dry_run'] = true;
                    $stats['error'] = $stats['sospetto'];

                    return Response::json($stats, 409);
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
}
