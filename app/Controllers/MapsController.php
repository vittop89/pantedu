<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Config;
use App\Core\Database;
use App\Core\Request;
use App\Core\Response;
use App\Repositories\TeacherContentRepository;
use App\Services\Drive\MapSyncService;
use App\Services\Maps\MapBlobStore;
use App\Services\Maps\MapPermissionService;
use App\Services\Maps\MapSignedUrlService;
use App\Support\Ulid;
use PDO;
use Throwable;

/**
 * Phase G3.b — REST per mappe con storage locale cifrato.
 *
 *   POST /api/maps           → crea mappa con blob (upload | drawio_native)
 *
 * Forme accettate (multipart/form-data o application/x-www-form-urlencoded):
 *
 *   1) UPLOAD file (drag-drop o file picker):
 *      - Content-Type: multipart/form-data
 *      - Field "mode" = "upload"
 *      - Field "file" = il file (PDF, drawio, PNG, XML, HTML; max 50MB)
 *      - Fields metadata: title, topic, subject, indirizzo, classe, visibility
 *
 *   2) DRAWIO_NATIVE (mappa creata in-app via embed.diagrams.net):
 *      - Content-Type: application/x-www-form-urlencoded
 *      - Field "mode" = "drawio_native"
 *      - Field "xml"  = il drawio XML salvato dall'embed
 *      - Fields metadata: title, topic, subject, indirizzo, classe, visibility
 *
 * Per il caso "link drawio classico" (URL paste) l'endpoint legacy
 * POST /api/teacher/content e' tuttora usato (non duplichiamo qui).
 *
 * Sicurezza:
 *   - middleware csrf + rate (route-level, vedi routes/web.php)
 *   - teacher_id derivato da session (no spoofing)
 *   - mime whitelist contro upload arbitrari (no executable)
 *   - max file size hard cap (anti-DoS)
 *   - blob salvato cifrato envelope via MapBlobStore (TKEK ADR-006)
 */
final class MapsController
{
    /** Allowed MIME upload (best-effort: server-side derivato dal client). */
    private const ALLOWED_MIME = [
        'application/xml'        => 'application/xml',
        'text/xml'               => 'application/xml',
        'application/octet-stream' => 'application/xml', // .drawio raw
        'application/pdf'        => 'application/pdf',
        'image/png'              => 'image/png',
        'image/jpeg'             => 'image/jpeg',
        'text/html'              => 'text/html',
    ];
    /** Allowed extension fallback (se MIME ambiguo). */
    private const ALLOWED_EXT = ['drawio', 'xml', 'pdf', 'png', 'jpg', 'jpeg', 'html'];

    private const MAX_BYTES = 50 * 1024 * 1024; // 50 MB

    private TeacherContentRepository $repo;
    private MapBlobStore $blobStore;
    private MapPermissionService $perms;
    private MapSignedUrlService $signer;
    private MapSyncService $syncService;

    public function __construct(
        ?TeacherContentRepository $repo = null,
        ?MapBlobStore $blobStore = null,
        ?MapPermissionService $perms = null,
        ?MapSignedUrlService $signer = null,
        ?MapSyncService $syncService = null
    ) {
        $this->repo        = $repo        ?? new TeacherContentRepository();
        $this->blobStore   = $blobStore   ?? new MapBlobStore();
        $this->perms       = $perms       ?? new MapPermissionService();
        $this->signer      = $signer      ?? new MapSignedUrlService();
        $this->syncService = $syncService ?? new MapSyncService();
    }

    /**
     * POST /api/maps/{id}/sync — push singola mappa su Drive del docente.
     * Owner only. Richiede oauth Drive collegato (verificato da
     * MapSyncService::syncOne, ritorna 'drive_not_connected' altrimenti).
     */
    public function sync(Request $req, array $params): Response
    {
        $userId = $this->teacherId();
        if ($userId === null) {
            return Response::json(['error' => 'unauthorized'], 401);
        }
        $id = (int)($params['id'] ?? 0);
        if (!$this->perms->canEdit($id, $userId)) {
            return Response::json(['error' => 'forbidden'], 403);
        }
        $report = $this->syncService->syncOne($id, $userId);
        $status = ($report['ok'] ?? false) ? 200 : 422;
        if (($report['error'] ?? '') === 'drive_not_connected') {
            $status = 412; // Precondition Failed: serve OAuth connect
        }
        return Response::json($report, $status);
    }

    /**
     * POST /api/maps/sync-all — push BATCH di tutte le mappe del docente
     * su Drive (best-effort, no fail-fast). Limit configurabile.
     */
    public function syncAll(Request $req): Response
    {
        $userId = $this->teacherId();
        if ($userId === null) {
            return Response::json(['error' => 'unauthorized'], 401);
        }
        // G22.S15.bis Fase 5 — release session lock asap così l'utente può
        // navigare durante il sync (ogni richiesta PHP-FPM su stessa session
        // si blocca finché chi possiede il lock non rilascia → bloccava UI).
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }
        // Phase G7 — sync batch incrementale: il client chiama in loop
        // con limit=20 + onlyUnsynced=1 fino a count=0. Lift PHP timeout
        // per ogni batch (Drive API quota agisce come cap esterno).
        @set_time_limit(0);
        // G19.48 — alza memory_limit per coprire docenti con molti blob
        // (default Apache 128 MB satura caricando il TEX di 100+ verifiche).
        @ini_set('memory_limit', '512M');

        $limit = isset($req->post['limit']) ? max(1, (int)$req->post['limit']) : null;
        // onlyChanged=true (UI manual): solo mappe mai syncate o
        // modificate dopo l'ultimo sync globale. Cron usa default false.
        $onlyChanged = !empty($req->post['onlyChanged']) || !empty($req->post['onlyUnsynced']);

        $report = $this->syncService->syncAllForTeacher($userId, $limit, $onlyChanged);
        return Response::json(['ok' => true, 'report' => $report]);
    }

    public function create(Request $req): Response
    {
        if (!Config::get('database.enabled') || !Database::isAvailable()) {
            return Response::json(['error' => 'db_unavailable'], 503);
        }
        $teacherId = $this->teacherId();
        if ($teacherId === null) {
            return Response::json(['error' => 'unauthorized'], 401);
        }

        $mode = (string)($req->post['mode'] ?? '');
        if ($mode !== 'upload' && $mode !== 'drawio_native') {
            return Response::json(['error' => 'invalid_mode'], 400);
        }

        $title      = trim((string)($req->post['title']      ?? ''));
        $topic      = trim((string)($req->post['topic']      ?? ''));
        $subject    = trim((string)($req->post['subject']    ?? ''));
        $indirizzo  = $this->blankToNull($req->post['indirizzo'] ?? null);
        $classe     = $this->blankToNull($req->post['classe']    ?? null);
        $visibility = (string)($req->post['visibility'] ?? 'draft');

        if ($title === '' || $subject === '') {
            return Response::json(['error' => 'missing_fields'], 422);
        }

        try {
            [$plaintext, $mime] = $this->extractPayload($mode, $req);
        } catch (Throwable $e) {
            return Response::json(['error' => $e->getMessage()], 422);
        }

        $size = strlen($plaintext);
        if ($size === 0) {
            return Response::json(['error' => 'empty_payload'], 422);
        }
        if ($size > self::MAX_BYTES) {
            return Response::json(['error' => 'payload_too_large'], 413);
        }

        // ADR-027 — ancora la mappa alla sezione sidebar di creazione (section_id),
        // così compare nel pannello (loader per sezione).
        $sectionId = null;
        $sectionKey = trim((string)($req->post['section_key'] ?? ''));
        if ($sectionKey !== '') {
            $iid = (int)\App\Support\TeacherContextResolver::firstInstituteId($teacherId);
            foreach ((new \App\Repositories\SidebarSectionRepository())->resolveFor($iid, $teacherId) as $s) {
                if ($s['section_key'] === $sectionKey) {
                    $sectionId = (int)$s['id'];
                    break;
                }
            }
        }

        $pdo = Database::connection();
        try {
            $pdo->beginTransaction();

            $contentId = $this->repo->create([
                'teacher_id'   => $teacherId,
                'content_type' => 'mappa',
                'section_id'   => $sectionId,
                'subject_code' => $subject,
                'indirizzo'    => $indirizzo,
                'classe'       => $classe,
                'topic'        => $topic,
                'title'        => $title,
                'metadata'     => ['mappa' => ['display' => 'show']],
                'visibility'   => $visibility,
            ]);

            $ulid     = Ulid::generate();
            $blobPath = $this->blobStore->put($teacherId, $plaintext, $ulid);
            $origin   = $mode === 'drawio_native' ? 'drawio_native' : 'upload';

            $stmt = $pdo->prepare(
                'UPDATE teacher_content_data
                 SET map_blob_path = ?, map_mime = ?, map_size = ?,
                     map_origin = ?, map_version = 1
                 WHERE id = ? AND teacher_id = ?'
            );
            $stmt->execute([$blobPath, $mime, $size, $origin, $contentId, $teacherId]);

            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            // Best-effort cleanup blob orfano (se put era riuscito).
            if (isset($blobPath) && $blobPath !== '') {
                try {
                    $this->blobStore->delete($blobPath);
                } catch (Throwable) {
                }
            }
            error_log('MapsController.create: ' . $e->getMessage());
            return Response::json(['error' => 'create_failed'], 500);
        }

        return Response::json([
            'ok'         => true,
            'id'         => $contentId,
            'mode'       => $mode,
            'mime'       => $mime,
            'size'       => $size,
            'origin'     => $origin,
            'blob_path'  => $blobPath,
        ]);
    }

    /**
     * GET /api/maps/{id}/signed-url?mode=view|copy
     *
     * Mint signed URL per il viewer corrente DOPO permission check. Il
     * client (modal edit drawio o viewer overlay) usa l'URL per fetchare
     * il blob decifrato. TTL 600s default.
     */
    public function signedUrl(Request $req, array $params): Response
    {
        $userId = $this->teacherId();
        if ($userId === null) {
            return Response::json(['error' => 'unauthorized'], 401);
        }
        $id   = (int)($params['id'] ?? 0);
        $mode = (string)($req->query['mode'] ?? MapSignedUrlService::MODE_VIEW);

        $allowed = $mode === MapSignedUrlService::MODE_COPY
            ? $this->perms->canCopy($id, $userId)
            : $this->perms->canView($id, $userId);

        if (!$allowed) {
            return Response::json(['error' => 'forbidden'], 403);
        }

        try {
            $url = $this->signer->mint($id, $mode);
        } catch (Throwable $e) {
            error_log('MapsController.signedUrl: ' . $e->getMessage());
            return Response::json(['error' => 'sign_failed'], 500);
        }

        return Response::json([
            'ok'  => true,
            'id'  => $id,
            'mode' => $mode,
            'url' => $url,
            'exp' => time() + 600,
        ]);
    }

    /**
     * GET /api/maps/dl?t=<token>&s=<sig>
     *
     * Pubblico (no auth Apache-side): la firma e' l'auth. Verifica HMAC,
     * carica row, decifra blob via MapBlobStore (usando teacher_id del
     * OWNER, non del viewer — il caller potrebbe essere un altro docente
     * o studente con permission grant), stream con Content-Type corretto
     * e Cache-Control privato.
     */
    public function download(Request $req): Response
    {
        $t = (string)($req->query['t'] ?? '');
        $s = (string)($req->query['s'] ?? '');
        if ($t === '' || $s === '') {
            return Response::json(['error' => 'missing_token'], 400);
        }
        try {
            $payload = $this->signer->verify($t, $s);
        } catch (Throwable $e) {
            return Response::json(['error' => $e->getMessage()], 403);
        }

        $contentId = $payload['content_id'];

        $stmt = Database::connection()->prepare(
            'SELECT teacher_id, map_blob_path, map_mime, map_size, content_type
             FROM teacher_content WHERE id = ? LIMIT 1'
        );
        $stmt->execute([$contentId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row || $row['content_type'] !== 'mappa' || empty($row['map_blob_path'])) {
            return Response::json(['error' => 'not_found'], 404);
        }

        try {
            $plaintext = $this->blobStore->get((int)$row['teacher_id'], (string)$row['map_blob_path']);
        } catch (Throwable $e) {
            error_log('MapsController.download: ' . $e->getMessage());
            return Response::json(['error' => 'blob_unreadable'], 500);
        }

        $mime = (string)($row['map_mime'] ?? 'application/octet-stream');

        // Risposta stream-friendly: Response::html() accetta string body con
        // status+headers. Per Content-Type custom usiamo costruttore.
        // Phase G7 — CORS: viewer.diagrams.net (e drawio embed) fanno fetch
        // cross-origin di questo URL. Il signed URL (HMAC+TTL) e' gia'
        // l'auth, no cookie leak con Access-Control-Allow-Origin: *.
        $resp = new Response($plaintext, 200, [
            'Content-Type'  => $mime,
            'Cache-Control' => 'private, max-age=0, no-store',
            'X-Content-Type-Options' => 'nosniff',
            'Access-Control-Allow-Origin'  => '*',
            'Access-Control-Allow-Methods' => 'GET',
        ]);
        return $resp;
    }

    /**
     * POST /api/maps/{id}/update
     *
     * Save da editor drawio (modifica originale, owner only). Body:
     *   xml          : nuovo XML drawio
     *   map_version  : versione client per optimistic concurrency
     *
     * Mismatch versione → 409 (UI prompt reload). Match → re-encrypt
     * blob, UPDATE map_size + bump map_version. NON crea nuova row;
     * per "modifica copia" usa POST /api/maps con mode=drawio_native
     * (gia' implementato in G3.b).
     */
    public function update(Request $req, array $params): Response
    {
        $userId = $this->teacherId();
        if ($userId === null) {
            return Response::json(['error' => 'unauthorized'], 401);
        }
        $id = (int)($params['id'] ?? 0);

        if (!$this->perms->canEdit($id, $userId)) {
            return Response::json(['error' => 'forbidden'], 403);
        }

        $xml = trim((string)($req->post['xml'] ?? ''));
        if ($xml === '') {
            return Response::json(['error' => 'xml_missing'], 422);
        }
        if (!str_contains($xml, '<mxfile') && !str_contains($xml, '<mxGraphModel')) {
            return Response::json(['error' => 'xml_invalid'], 422);
        }
        $clientVersion = (int)($req->post['map_version'] ?? -1);
        if ($clientVersion < 0) {
            return Response::json(['error' => 'version_missing'], 422);
        }

        $pdo = Database::connection();
        $stmt = $pdo->prepare(
            'SELECT map_blob_path, map_version FROM teacher_content
             WHERE id = ? AND teacher_id = ? LIMIT 1'
        );
        $stmt->execute([$id, $userId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row || empty($row['map_blob_path'])) {
            return Response::json(['error' => 'not_found'], 404);
        }
        $serverVersion = (int)$row['map_version'];
        if ($clientVersion !== $serverVersion) {
            return Response::json([
                'error'           => 'version_conflict',
                'server_version'  => $serverVersion,
            ], 409);
        }

        try {
            // Re-encrypt sovrascrive il blob esistente (stesso ulid via
            // MapBlobStore::put con ulid esplicito derivato dal path).
            $relPath = (string)$row['map_blob_path'];
            // Estrai ulid dal path "{teacher_id}/{ulid}.bin"
            $ulid = '';
            if (preg_match('#/([0-9A-Z]{26})\.bin$#', $relPath, $m)) {
                $ulid = $m[1];
            }
            if ($ulid === '') {
                return Response::json(['error' => 'blob_path_invalid'], 500);
            }
            $newPath = $this->blobStore->put($userId, $xml, $ulid);
            $newSize = strlen($xml);

            // Optimistic update: bump version atomically (anti-race).
            $upd = $pdo->prepare(
                'UPDATE teacher_content_data
                 SET map_size = ?, map_version = map_version + 1, updated_at = NOW()
                 WHERE id = ? AND teacher_id = ? AND map_version = ?'
            );
            $upd->execute([$newSize, $id, $userId, $clientVersion]);
            if ($upd->rowCount() === 0) {
                // Race lost: qualcun altro ha bumpato nel frattempo.
                return Response::json(['error' => 'version_conflict_race'], 409);
            }
        } catch (Throwable $e) {
            error_log('MapsController.update: ' . $e->getMessage());
            return Response::json(['error' => 'update_failed'], 500);
        }

        return Response::json([
            'ok'           => true,
            'id'           => $id,
            'size'         => $newSize,
            'map_version'  => $serverVersion + 1,
        ]);
    }

    /**
     * @return array{0:string,1:string}  [plaintext, mime]
     */
    private function extractPayload(string $mode, Request $req): array
    {
        if ($mode === 'drawio_native') {
            $xml = (string)($req->post['xml'] ?? '');
            $xml = trim($xml);
            if ($xml === '') {
                throw new \RuntimeException('xml_missing');
            }
            // Sanity: drawio mxfile root.
            if (!str_contains($xml, '<mxfile') && !str_contains($xml, '<mxGraphModel')) {
                throw new \RuntimeException('xml_invalid');
            }
            return [$xml, 'application/xml'];
        }

        // mode === 'upload'
        $files = $_FILES['file'] ?? null;
        if (!is_array($files) || ($files['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            throw new \RuntimeException('file_missing');
        }
        $tmp  = (string)$files['tmp_name'];
        $name = (string)($files['name'] ?? '');
        $size = (int)($files['size'] ?? 0);
        if ($size <= 0 || !is_uploaded_file($tmp)) {
            throw new \RuntimeException('upload_invalid');
        }
        if ($size > self::MAX_BYTES) {
            throw new \RuntimeException('payload_too_large');
        }

        $mime = $this->resolveMime($tmp, $name, (string)($files['type'] ?? ''));
        $bytes = file_get_contents($tmp);
        if ($bytes === false) {
            throw new \RuntimeException('upload_read_failed');
        }
        return [$bytes, $mime];
    }

    private function resolveMime(string $tmp, string $name, string $clientMime): string
    {
        // Trust finfo > extension > client header.
        $finfo = @finfo_open(FILEINFO_MIME_TYPE);
        $detected = $finfo ? @finfo_file($finfo, $tmp) : '';
        if ($finfo) {
            finfo_close($finfo);
        }
        $detected = (string)$detected;

        if (isset(self::ALLOWED_MIME[$detected])) {
            return self::ALLOWED_MIME[$detected];
        }

        // PROBLEM-8 fix — fallback su estensione SOLO se i magic bytes del file
        // confermano il MIME atteso. Senza questa guard un file `.drawio`
        // contenente codice PHP/EXE bypasserebbe la validazione finfo silente.
        $ext = strtolower((string)pathinfo($name, PATHINFO_EXTENSION));
        if (\in_array($ext, self::ALLOWED_EXT, true)) {
            $resolved = match ($ext) {
                'drawio', 'xml' => 'application/xml',
                'pdf'           => 'application/pdf',
                'png'           => 'image/png',
                'jpg', 'jpeg'   => 'image/jpeg',
                'html'          => 'text/html',
                default         => 'application/octet-stream',
            };
            if (self::verifyMagicBytes($tmp, $resolved)) {
                return $resolved;
            }
        }
        if (
            isset(self::ALLOWED_MIME[$clientMime])
            && self::verifyMagicBytes($tmp, self::ALLOWED_MIME[$clientMime])
        ) {
            return self::ALLOWED_MIME[$clientMime];
        }
        throw new \RuntimeException('mime_not_allowed');
    }

    /**
     * PROBLEM-8 — Verifica magic bytes per i tipi accettati. Read 16 byte
     * iniziali del file e confronta con signature attese.
     *
     * Returns true se OK O se il MIME non ha signature standard verificabile
     * (es. text/html — Apache MIME magic non basta da solo, accettiamo).
     */
    private static function verifyMagicBytes(string $tmpPath, string $mime): bool
    {
        $fh = @fopen($tmpPath, 'rb');
        if (!$fh) {
            return false;
        }
        $head = (string)fread($fh, 256);
        fclose($fh);
        return match ($mime) {
            'application/pdf' => str_starts_with($head, '%PDF-'),
            'application/xml' => str_contains($head, '<?xml')
                              || str_contains($head, '<mxfile')
                              || str_contains($head, '<mxGraphModel'),
            'image/png'       => str_starts_with($head, "\x89PNG\r\n\x1a\n"),
            'image/jpeg'      => str_starts_with($head, "\xFF\xD8\xFF"),
            'text/html'       => true,  // troppo eterogeneo per signature unica
            default           => false,
        };
    }

    private function teacherId(): ?int
    {
        $username = (string)(Auth::user()['username'] ?? '');
        if ($username === '') {
            return null;
        }
        $id = \App\Support\TeacherContextResolver::userIdFromUsername($username);
        return $id > 0 ? $id : null;
    }

    private function blankToNull(?string $value): ?string
    {
        $v = trim((string)$value);
        return $v === '' ? null : $v;
    }
}
