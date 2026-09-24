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
use App\Services\Drive\StatoDiDrive;
use App\Services\Maps\FileDrawio;
use App\Services\Maps\MapBlobStore;
use App\Services\Maps\MappaDaLinkDrive;
use App\Services\Maps\MapPermissionService;
use App\Services\Maps\MapSignedUrlService;
use App\Services\Maps\SalvataggioMappa;
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
 *      - Field "file" = il file drawio (.drawio o .xml; max 50MB). Dal
 *        23/9/2026 conta il contenuto, non il nome: si accetta solo un XML
 *        drawio (App\Services\Maps\FileDrawio). PDF, PNG, JPEG e HTML, che
 *        nessuna pagina mostrava, non si accettano più.
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
 *   - il file caricato deve essere un XML drawio (FileDrawio), salvato come
 *     application/xml
 *   - max file size hard cap (anti-DoS)
 *   - blob salvato cifrato envelope via MapBlobStore (TKEK ADR-006)
 */
final class MapsController
{
    private const MAX_BYTES = 50 * 1024 * 1024; // 50 MB

    private TeacherContentRepository $repo;
    private MapBlobStore $blobStore;
    private MapPermissionService $perms;
    private MapSignedUrlService $signer;
    private MapSyncService $syncService;
    private MappaDaLinkDrive $daLink;

    /**
     * Il file è arrivato con la richiesta HTTP? Di norma is_uploaded_file; le
     * prove del caricamento (tests/Integration/CaricamentoMappaSoloDrawioTest.php)
     * lo sostituiscono, perché da riga di comando nessun file è «caricato».
     *
     * @var \Closure(string): bool
     */
    private \Closure $caricatoDaHttp;

    /**
     * @param (\Closure(string): bool)|null $caricatoDaHttp
     */
    public function __construct(
        ?TeacherContentRepository $repo = null,
        ?MapBlobStore $blobStore = null,
        ?MapPermissionService $perms = null,
        ?MapSignedUrlService $signer = null,
        ?MapSyncService $syncService = null,
        ?MappaDaLinkDrive $daLink = null,
        ?\Closure $caricatoDaHttp = null
    ) {
        $this->repo        = $repo        ?? new TeacherContentRepository();
        $this->blobStore   = $blobStore   ?? new MapBlobStore();
        $this->perms       = $perms       ?? new MapPermissionService();
        $this->signer      = $signer      ?? new MapSignedUrlService();
        $this->syncService = $syncService ?? new MapSyncService();
        $this->daLink      = $daLink      ?? new MappaDaLinkDrive();
        // Una funzione freccia e non `is_uploaded_file(...)`: l'analizzatore
        // PHP di semgrep non legge la sintassi dei callable di prima classe, e
        // il file finirebbe fra quelli letti in parte (23/9/2026).
        $this->caricatoDaHttp = $caricatoDaHttp ?? static fn(string $percorso): bool => is_uploaded_file($percorso);
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
        $status = match ((string)($report['error'] ?? '')) {
            // Precondition Failed: serve un collegamento, o Drive acceso (ADR-038).
            'drive_not_connected', 'drive_da_ricollegare', 'drive_spento' => 412,
            'drive_guasto' => 503,
            default => $status,
        };
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
        $istanza = StatoDiDrive::attuale();
        if ($istanza !== StatoDiDrive::ACCESO) {
            return Response::json(
                ['ok' => false, 'error' => 'drive_' . $istanza],
                $istanza === StatoDiDrive::GUASTO ? 503 : 412,
            );
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
        // «link» (15/9/2026): il drawio di un link pubblico di Drive si scarica una
        // volta e si salva come le altre mappe (MappaDaLinkDrive).
        if ($mode !== 'upload' && $mode !== 'drawio_native' && $mode !== 'link') {
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
        //
        // La sezione si cerca nell'istituto attivo: è la scuola in cui la barra
        // la mostra e in cui il repository risolve indirizzo, classe e materia.
        // Fino al 23/9/2026 la si cercava nella casa dei file privati (A-7):
        // per un docente di due scuole, con la seconda attiva, una sezione
        // della seconda non si trovava e la mappa nasceva senza sezione.
        $sectionId = null;
        $sectionKey = trim((string)($req->post['section_key'] ?? ''));
        if ($sectionKey !== '') {
            $iid = \App\Support\TeacherContextResolver::activeInstituteId($teacherId);
            foreach ((new \App\Repositories\SidebarSectionRepository())->resolveFor($iid, $teacherId) as $s) {
                if ($s['section_key'] === $sectionKey) {
                    $sectionId = (int)$s['id'];
                    break;
                }
            }
        }

        $pdo = Database::connection();
        // La transazione si apre solo se chi chiama non ne ha già una (le prove).
        $propria = !$pdo->inTransaction();
        try {
            if ($propria) {
                $pdo->beginTransaction();
            }

            $contentId = $this->repo->create([
                'teacher_id'   => $teacherId,
                'content_type' => 'mappa',
                'section_id'   => $sectionId,
                'subject_code' => $subject,
                'indirizzo'    => $indirizzo,
                'classe'       => $classe,
                'topic'        => $topic,
                'title'        => $title,
                // Il link da cui è stata importata resta come provenienza.
                'metadata'     => ['mappa' => $mode === 'link'
                    ? ['display' => 'show', 'href' => trim((string)($req->post['href'] ?? ''))]
                    : ['display' => 'show']],
                'visibility'   => $visibility,
            ]);

            $ulid     = Ulid::generate();
            $blobPath = $this->blobStore->put($teacherId, $plaintext, $ulid);
            // Un file importato da un link è un caricamento: la colonna non ha un valore per i link.
            $origin   = $mode === 'drawio_native' ? 'drawio_native' : 'upload';

            $stmt = $pdo->prepare(
                'UPDATE teacher_content_data
                 SET map_blob_path = ?, map_mime = ?, map_size = ?,
                     map_origin = ?, map_version = 1
                 WHERE id = ? AND teacher_id = ?'
            );
            $stmt->execute([$blobPath, $mime, $size, $origin, $contentId, $teacherId]);

            if ($propria) {
                $pdo->commit();
            }
        } catch (Throwable $e) {
            if ($propria && $pdo->inTransaction()) {
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
        $id   = (int)($params['id'] ?? 0);
        $mode = (string)($req->query['mode'] ?? MapSignedUrlService::MODE_VIEW);

        if ($userId === null) {
            // ADR-032 (2026-09-04) — ospite con credenziale di classe: puo'
            // VEDERE (mai copiare) le mappe pubblicate dal docente della
            // credenziale per la sua classe. Senza credenziale, 401 come prima.
            $grant = \App\Support\ClassAccessGrant::current();
            if ($grant === null) {
                return Response::json(['error' => 'unauthorized'], 401);
            }
            if ($mode === MapSignedUrlService::MODE_COPY || !$this->grantCanViewMap($id, $grant)) {
                return Response::json(['error' => 'forbidden'], 403);
            }
            $allowed = true;
        } else {
            $allowed = $mode === MapSignedUrlService::MODE_COPY
                ? $this->perms->canCopy($id, $userId)
                : $this->perms->canView($id, $userId);
        }

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
     * ADR-032 — una mappa e' visibile con la credenziale di classe se e' del
     * docente della credenziale, e' pubblicata, e la credenziale (se
     * delimitata) coincide con indirizzo/classe della mappa.
     *
     * @param array{teacher_id:int,institute_id:?int,indirizzo:?string,classe:?string} $grant
     */
    private function grantCanViewMap(int $mapId, array $grant): bool
    {
        if ($mapId <= 0) {
            return false;
        }
        try {
            $stmt = \App\Core\Database::connection()->prepare(
                "SELECT teacher_id, visibility, indirizzo, classe
                   FROM teacher_content
                  WHERE id = ? AND content_type = 'mappa' LIMIT 1"
            );
            $stmt->execute([$mapId]);
            $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        } catch (Throwable) {
            return false;
        }
        if (!$row || (int)$row['teacher_id'] !== $grant['teacher_id']) {
            return false;
        }
        if ((string)$row['visibility'] !== 'published') {
            return false;
        }
        // ADR-037, fase 1 (2026-09-13) — il confronto non si fa piu' con le
        // sigle della riga ma con le pubblicazioni della mappa: una pubblicata,
        // nella scuola della credenziale (qualunque, per le credenziali create
        // senza), per la classe coperta. Una mappa «per piu' classi» si vede
        // cosi' dai suoi bersagli, e una mappa di un'altra scuola con le stesse
        // sigle non si vede.
        $scuola  = (int)($grant['institute_id'] ?? 0);
        $wantInd = (string)($grant['indirizzo'] ?? '');
        $wantCls = (string)($grant['classe'] ?? '');
        foreach (\App\Support\Pubblicazioni::delContenuto($mapId) as $p) {
            if ($p['visibility'] !== 'published') {
                continue;
            }
            if ($scuola > 0 && $p['institute_id'] !== $scuola) {
                continue;
            }
            $haveInd = (string)($p['indirizzo'] ?? '');
            if ($wantInd !== '' && $haveInd !== '' && strcasecmp($haveInd, $wantInd) !== 0) {
                continue;
            }
            // Piano classi, A e D — stessa regola del filtro dei contenuti: l'anno
            // copre le sezioni (una mappa «3» si vede da «3A»), e gli anni passati
            // si possono guardare (una mappa «2» si vede da «3A»). L'ospite non ha
            // storico, quindi solo al livello di anno.
            $haveCls = (string)($p['classe'] ?? '');
            if ($wantCls !== '' && $haveCls !== '') {
                $dentro  = \App\Domain\ClassCode::covers($haveCls, $wantCls);
                $passata = \App\Domain\ClassiFrequentate::ammette($wantCls, [], $haveCls) !== null;
                if (!$dentro && !$passata) {
                    continue;
                }
            }
            return true;
        }
        return false;
    }

    /**
     * GET /api/maps/dl?t=<token>&s=<sig>
     *
     * Pubblico (no auth Apache-side): la firma e' l'auth. Verifica HMAC,
     * carica row, decifra blob via MapBlobStore (usando teacher_id del
     * OWNER, non del viewer — il caller potrebbe essere un altro docente
     * o studente con permission grant), e lo manda come allegato
     * application/octet-stream con nosniff (mai come documento del sito) e
     * Cache-Control privato.
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

        // 23/9/2026 (revisione architetturale A-3) — il file si scarica, non si
        // apre. Fino a oggi usciva con il tipo registrato (map_mime), dalla
        // stessa origine del sito: una mappa caricata come text/html (fino a
        // oggi «Carica file» e l'import lo permettevano, e le righe restano)
        // si apriva come pagina del sito aprendo il link firmato; e anche un
        // XML drawio valido, servito come application/xml e aperto come
        // documento, esegue uno `<script>` XHTML che contenga (misurato in
        // Chromium il 23/9/2026, senza CSP; con la CSP dipende da lei). Ora
        // sempre application/octet-stream, come allegato e con nosniff: il
        // browser lo scarica e non esegue niente (misurato: nessuno script
        // eseguito, né dall'HTML né dal drawio). Chi lo usa lo legge con fetch
        // (l'editor, drawio-editor.js fetchXml, e le spec end-to-end,
        // maps.api.ts scarica), e a fetch né il tipo né l'allegato cambiano i
        // byte (misurato, stessa prova).
        $resp = new Response($plaintext, 200, [
            'Content-Type'  => 'application/octet-stream',
            'Content-Disposition' => 'attachment; filename="mappa-' . $contentId . '.'
                . self::estensioneDaScaricare((string)($row['map_mime'] ?? '')) . '"',
            'Cache-Control' => 'private, max-age=0, no-store',
            'X-Content-Type-Options' => 'nosniff',
            // Phase G7 — CORS: viewer.diagrams.net (e drawio embed) facevano
            // fetch cross-origin di questo URL. Il signed URL (HMAC+TTL) e'
            // gia' l'auth, no cookie leak con Access-Control-Allow-Origin: *.
            'Access-Control-Allow-Origin'  => '*',
            'Access-Control-Allow-Methods' => 'GET',
        ]);
        return $resp;
    }

    /**
     * L'estensione del file scaricato: .drawio, o quella delle mappe vecchie
     * in PDF o in immagine, perché chi le scarica le apra con il programma
     * giusto. Una mappa text/html resta .drawio: il sito non la propone come
     * pagina nemmeno sul disco di chi la scarica.
     */
    private static function estensioneDaScaricare(string $mime): string
    {
        return match ($mime) {
            'application/pdf' => 'pdf',
            'image/png'       => 'png',
            'image/jpeg'      => 'jpg',
            default           => 'drawio',
        };
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
        // 23/9/2026 (verifica avversaria di A-3): lo stesso controllo di ogni
        // altra strada d'ingresso (FileDrawio). Fino a oggi bastava la
        // sottostringa `<mxfile`: un XML con un DTD si salvava, e lo strumento
        // di ripristino dei caratteri lo avrebbe letto con PARSEHUGE.
        $motivo = FileDrawio::motivoDelRifiuto($xml);
        if ($motivo !== null) {
            return Response::json(['error' => 'xml_invalid', 'motivo' => $motivo], 422);
        }
        $clientVersion = (int)($req->post['map_version'] ?? -1);
        if ($clientVersion < 0) {
            return Response::json(['error' => 'version_missing'], 422);
        }

        // Controllo di versione, blocco della riga e scrittura stanno in
        // SalvataggioMappa, che usa anche lo strumento dei caratteri persi
        // (tools/maps/ripristina_caratteri.php): stesso modo di scrivere.
        try {
            $esito = (new SalvataggioMappa(Database::connection(), $this->blobStore))
                ->sovrascrivi($id, $userId, $xml, $clientVersion);
        } catch (Throwable $e) {
            error_log('MapsController.update: ' . $e->getMessage());
            return Response::json(['error' => 'update_failed'], 500);
        }

        return match ($esito['esito']) {
            SalvataggioMappa::SALVATA => Response::json([
                'ok'           => true,
                'id'           => $id,
                'size'         => $esito['byte'] ?? strlen($xml),
                'map_version'  => $esito['versione'] ?? $clientVersion + 1,
            ]),
            SalvataggioMappa::CONFLITTO => Response::json([
                'error'           => 'version_conflict',
                'server_version'  => $esito['versione_server'] ?? null,
            ], 409),
            SalvataggioMappa::PERCORSO_NON_VALIDO => Response::json(['error' => 'blob_path_invalid'], 500),
            default => Response::json(['error' => 'not_found'], 404),
        };
    }

    /**
     * @return array{0:string,1:string}  [plaintext, mime]
     */
    private function extractPayload(string $mode, Request $req): array
    {
        if ($mode === 'link') {
            $id = MappaDaLinkDrive::idDalLink((string)($req->post['href'] ?? ''));
            if ($id === null) {
                throw new \RuntimeException('link_non_drive');
            }
            return [$this->daLink->xml($id), 'application/xml'];
        }
        if ($mode === 'drawio_native') {
            $xml = (string)($req->post['xml'] ?? '');
            $xml = trim($xml);
            if ($xml === '') {
                throw new \RuntimeException('xml_missing');
            }
            // Il controllo di ogni strada d'ingresso (FileDrawio, 23/9/2026):
            // prima bastava la sottostringa `<mxfile`.
            if (!FileDrawio::valido($xml)) {
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
        $size = (int)($files['size'] ?? 0);
        if ($size <= 0 || !($this->caricatoDaHttp)($tmp)) {
            throw new \RuntimeException('upload_invalid');
        }
        if ($size > self::MAX_BYTES) {
            throw new \RuntimeException('payload_too_large');
        }

        $bytes = file_get_contents($tmp);
        if ($bytes === false) {
            throw new \RuntimeException('upload_read_failed');
        }
        // 23/9/2026 (revisione architetturale A-3, R-3 passo 2) — conta il
        // contenuto, non il nome. Fino a oggi il file passava da resolveMime:
        // finfo, estensione e i primi 256 byte. Un HTML qualsiasi entrava come
        // text/html, e un file qualsiasi con `<mxfile` nei primi 256 byte come
        // drawio; la pagina di studio lo metteva in uno <script> e il
        // `</script>` del file lo chiudeva. PDF, PNG e JPEG entravano e nessuna
        // pagina li mostrava.
        // Ora si accetta solo un XML drawio, e si salva come tale.
        if (!FileDrawio::valido($bytes)) {
            throw new \RuntimeException('drawio_non_valido');
        }
        return [$bytes, 'application/xml'];
    }

    private function teacherId(): ?int
    {
        $id = \App\Support\TeacherContextResolver::currentTeacherId();
        return $id > 0 ? $id : null;
    }

    private function blankToNull(?string $value): ?string
    {
        $v = trim((string)$value);
        return $v === '' ? null : $v;
    }
}
