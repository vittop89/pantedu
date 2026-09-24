<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Config;
use App\Core\Database;
use App\Core\Request;
use App\Core\Response;
use App\Services\Gdpr\ConsentService;
use App\Services\Gdpr\DeletionRequestService;
use App\Services\Gdpr\RichiestaDiCancellazione;
use App\Support\PagineDellaCancellazione as Pagine;

/**
 * Phase 25.C — Self-service GDPR endpoints per data subjects (Art. 7, 16, 17, 20).
 *
 * Endpoint:
 *   GET  /me/consents                    listActive
 *   POST /me/consents/grant              grant(type)
 *   POST /me/consents/revoke             revoke(type)
 *
 *   POST /me/request-deletion            Art. 17 → genera token + email
 *   GET  /me/confirm-deletion?token=...  Art. 17 → pagina del collegamento (non conferma)
 *   POST /me/confirm-deletion            Art. 17 → confirm + cooling_off (il pulsante)
 *   POST /me/cancel-deletion             Art. 17 → annulla durante cooling-off
 *   GET  /me/deletion-status             Art. 17 → status corrente
 *
 *   GET  /me/export-data                 Art. 20 → ZIP JSON portabilità
 *   PATCH /me/profile                    Art. 16 → rettifica first_name/last_name/email
 *
 * Tutti richiedono auth (no role:guest), tranne le due conferme, che apre il
 * gettone del collegamento. CSRF su POST/PATCH.
 */
final class SelfServiceController
{
    public function __construct(
        private readonly ConsentService $consents = new ConsentService(),
        private readonly DeletionRequestService $deletions = new DeletionRequestService(),
        private readonly RichiestaDiCancellazione $richieste = new RichiestaDiCancellazione(),
    ) {
    }

    // ─────── Custodia delle chiavi: cosa e' stato fatto sui MIEI contenuti ───────

    /**
     * GET /me/custody-events — gli accessi amministrativi ai contenuti cifrati
     * dell'utente (ToS §3(c), Informativa §11-bis), letti dal registro
     * append-only `crypto_custody_events`. Solo i tipi che l'interessato ha
     * diritto di conoscere subito: le richieste dell'autorita' restano fuori
     * dall'automatismo (vedi CustodyNotifier). Niente descrizioni operative:
     * tipo, data e base giuridica, che sono cio' che serve per chiedere conto.
     */
    public function custodyEvents(Request $req): Response
    {
        $userId = $this->currentUserId();
        if ($userId === 0) {
            return Response::json(['error' => 'unauthorized'], 401);
        }
        $types = \App\Services\Crypto\CustodyNotifier::SUBJECT_VISIBLE_TYPES;
        $in    = implode(',', array_fill(0, count($types), '?'));
        $events = [];
        try {
            $st = Database::connection()->prepare(
                "SELECT id, event_type, occurred_at, legal_basis
                   FROM crypto_custody_events
                  WHERE teacher_id = ? AND event_type IN ($in)
                  ORDER BY occurred_at DESC, id DESC LIMIT 200"
            );
            $st->execute([$userId, ...$types]);
            $events = $st->fetchAll(\PDO::FETCH_ASSOC) ?: [];
        } catch (\Throwable $e) {
            error_log('[me/custody-events] ' . $e->getMessage());
            return Response::json(['error' => 'unavailable'], 503);
        }
        return Response::json([
            'ok'     => true,
            'count'  => count($events),
            'events' => $events,
            'note'   => 'Ogni riga è un accesso amministrativo ai tuoi contenuti cifrati, registrato in modo immutabile e notificato via email al momento della registrazione.',
        ]);
    }

    // ─────── Consents (Art. 7, 9) ───────

    public function consentsList(Request $req): Response
    {
        $userId = $this->currentUserId();
        if ($userId === 0) {
            return Response::json(['error' => 'unauthorized'], 401);
        }

        return Response::json([
            'ok'              => true,
            'active'          => $this->consents->listActive($userId),
            'needs_reconfirm' => $this->consents->needsReconfirm($userId),
            'current_version' => $this->consents->currentTextVersion(),
            'available_types' => ConsentService::TYPES,
        ]);
    }

    public function consentGrant(Request $req): Response
    {
        $userId = $this->currentUserId();
        if ($userId === 0) {
            return Response::json(['error' => 'unauthorized'], 401);
        }

        $type = (string)($req->post['type'] ?? '');
        if (!in_array($type, ConsentService::TYPES, true)) {
            return Response::json(['error' => 'invalid_type', 'allowed' => ConsentService::TYPES], 400);
        }
        $textVersion = $this->consents->currentTextVersion();

        try {
            $id = $this->consents->grant($userId, $type, $textVersion, [
                'ip' => $this->clientIp($req),
                'ua' => $req->server['HTTP_USER_AGENT'] ?? null,
            ]);
            return Response::json(['ok' => true, 'consent_id' => $id, 'type' => $type, 'text_version' => $textVersion]);
        } catch (\InvalidArgumentException $e) {
            return Response::json(['error' => $e->getMessage()], 400);
        }
    }

    public function consentRevoke(Request $req): Response
    {
        $userId = $this->currentUserId();
        if ($userId === 0) {
            return Response::json(['error' => 'unauthorized'], 401);
        }

        $type = (string)($req->post['type'] ?? '');
        if (!in_array($type, ConsentService::TYPES, true)) {
            return Response::json(['error' => 'invalid_type'], 400);
        }
        $ok = $this->consents->revoke($userId, $type, ['ip' => $this->clientIp($req)]);
        return Response::json(['ok' => $ok, 'type' => $type, 'revoked' => $ok]);
    }

    // ─────── Deletion (Art. 17) ───────
    //
    // 24/9/2026 — tre difetti, corretti insieme:
    //   - la richiesta rispondeva «Email di conferma inviata» e nessuna email
    //     partiva: adesso la manda RichiestaDiCancellazione, e se non parte la
    //     risposta lo dice e la richiesta non resta valida;
    //   - aprire il collegamento confermava subito (GET): vedi confirmDeletion;
    //   - chi arrivava da un modulo o da un collegamento vedeva JSON grezzo:
    //     adesso, se il client chiede HTML (PagineDellaCancellazione::
    //     vuolePagina), la risposta è una pagina con il messaggio e il
    //     ritorno a «I tuoi dati». Chi chiama in JSON riceve la forma di prima.

    public function requestDeletion(Request $req): Response
    {
        $userId = $this->currentUserId();
        if ($userId === 0) {
            return $this->nonAutenticato($req);
        }

        $reason = trim((string)($req->post['reason'] ?? ''));
        // Fuori dalla produzione, o con EXPOSE_DELETION_DEBUG_TOKEN, il
        // gettone torna nella risposta: è il canale delle prove end-to-end,
        // che una casella di posta non ce l'hanno. Il gettone in chiaro non va
        // in nessun registro: nel database c'è il suo hash (A-67).
        $gettoneAlChiamante = (string)Config::get('app.env', 'production') !== 'production'
            || (bool)Config::get('security.expose_deletion_debug_token', false);

        $esito = $this->richieste->richiedi($userId, $reason ?: null, $this->clientIp($req), $gettoneAlChiamante);
        $inviata = $esito['esito'] === RichiestaDiCancellazione::INVIATA;
        $righe = Pagine::righeDellaRichiesta($esito);

        // Una cancellazione già confermata resta com'è: non se ne crea
        // un'altra, e la risposta dice quando sarà eseguita e come annullarla.
        if ($esito['esito'] === RichiestaDiCancellazione::GIA_CONFERMATA) {
            return Pagine::vuolePagina($req)
                ? Pagine::pagina('Cancellazione già confermata', 'info', $righe, 409)
                : Response::json([
                    'ok'            => false,
                    'error'         => 'cancellazione_gia_confermata',
                    'execute_after' => $esito['eseguita_il'],
                    'message'       => implode(' ', $righe),
                    'cancel'        => '/me/cancel-deletion',
                ], 409);
        }

        if (Pagine::vuolePagina($req)) {
            if (!$esito['valida']) {
                $stato = Pagine::statoDelRifiuto($esito['esito']);
                return Pagine::pagina('Cancellazione non richiesta', 'errore', $righe, $stato, ['contatto' => true]);
            }
            return Pagine::pagina(
                $inviata ? 'Controlla la posta' : 'Email non inviata',
                $inviata ? 'ok' : 'info',
                $righe,
                200,
                ['collegamentoDiProva' => $esito['gettone'] !== null
                    ? '/me/confirm-deletion?token=' . rawurlencode($esito['gettone'])
                    : null],
            );
        }

        if (!$esito['valida']) {
            return Response::json([
                'ok'      => false,
                'error'   => 'email_non_inviata',
                'reason'  => $esito['esito'],
                'message' => implode(' ', $righe) . ' Puoi riprovare più tardi, oppure chiedere la cancellazione '
                    . 'al titolare: ' . Pagine::comeScrivereAlDpo() . '.',
                'contact' => ['dpo_email' => Pagine::casellaDpo() ?: null, 'form' => '/dpo-contact'],
            ], Pagine::statoDelRifiuto($esito['esito']));
        }
        return Response::json([
            'ok'                  => true,
            'email_sent'          => $inviata,
            'message'             => implode(' ', $righe),
            'cooling_off_days'    => DeletionRequestService::COOLING_OFF_DAYS,
            'token_expiry_days'   => DeletionRequestService::TOKEN_EXPIRY_DAYS,
            // SOLO fuori dalla produzione o con EXPOSE_DELETION_DEBUG_TOKEN
            'debug_token'         => $esito['gettone'],
        ]);
    }

    /**
     * GET /me/confirm-deletion?token=… — la pagina del collegamento dell'email.
     *
     * 24/9/2026 — aprire il collegamento non conferma più niente. Alcuni
     * programmi di posta e antivirus aprono da soli i collegamenti che
     * ricevono, anche con HEAD, che il router passa a questa rotta: con
     * l'email che adesso parte davvero, un GET che conferma avrebbe
     * confermato cancellazioni che nessuno ha deciso, e la frase dell'email
     * «se non sei stato tu, ignora il messaggio» sarebbe stata falsa. È la
     * regola già scritta per il cambio dell'email (AccountController) e per il
     * consenso del genitore: il GET mostra la pagina, il pulsante fa POST
     * (confirmDeletionSubmit).
     *
     * Un browser riceve la pagina, con il pulsante se il gettone vale; chi
     * chiama in JSON riceve 400 come prima se il gettone non vale, e
     * `status: pending_confirm` se vale.
     */
    public function confirmDeletion(Request $req): Response
    {
        $token = trim((string)($req->query['token'] ?? ''));
        $vale = $token !== '' && $this->deletions->inAttesa($token);

        // L'indirizzo di questa pagina ha il gettone: non lo manda come
        // Referer (PaginaConGettone), né al pulsante né a fogli e script.
        if (Pagine::vuolePagina($req)) {
            if (!$vale) {
                return Pagine::collegamentoNonValido(gettoneNellIndirizzo: true);
            }
            return Pagine::pagina('Conferma la cancellazione', 'info', [
                'Stai per confermare la cancellazione del tuo account Pantedu.',
                sprintf(
                    'Dopo la conferma la cancellazione viene eseguita fra %d giorni. Fino ad allora puoi '
                    . 'annullarla dalla pagina «I tuoi dati», dopo aver fatto l\'accesso.',
                    DeletionRequestService::COOLING_OFF_DAYS
                ),
                'Se non vuoi cancellare l\'account, chiudi questa pagina: senza la conferma la richiesta '
                . 'scade da sola.',
            ], 200, ['gettone' => $token, 'gettoneNellIndirizzo' => true]);
        }

        if ($token === '') {
            return Response::json(['error' => 'token_required'], 400);
        }
        if (!$vale) {
            return Response::json(Pagine::gettoneNonValido(), 400);
        }
        return Response::json([
            'ok'      => true,
            'status'  => 'pending_confirm',
            'message' => 'Il collegamento è valido: la cancellazione si conferma con il pulsante della pagina '
                . '(POST /me/confirm-deletion con il gettone).',
        ]);
    }

    /** POST /me/confirm-deletion — il pulsante della pagina del collegamento: conferma. */
    public function confirmDeletionSubmit(Request $req): Response
    {
        $token = trim((string)($req->post['token'] ?? ''));
        if ($token === '') {
            return Pagine::vuolePagina($req)
                ? Pagine::collegamentoNonValido()
                : Response::json(['error' => 'token_required'], 400);
        }

        $ok = $this->deletions->confirm($token, $this->clientIp($req));
        if (!$ok) {
            return Pagine::vuolePagina($req)
                ? Pagine::collegamentoNonValido()
                : Response::json(Pagine::gettoneNonValido(), 400);
        }

        if (Pagine::vuolePagina($req)) {
            return Pagine::pagina('Cancellazione confermata', 'ok', [
                sprintf(
                    'La cancellazione del tuo account è confermata: sarà eseguita il %s, fra %d giorni.',
                    date('d/m/Y', time() + DeletionRequestService::COOLING_OFF_DAYS * 86400),
                    DeletionRequestService::COOLING_OFF_DAYS
                ),
                'Fino ad allora puoi annullarla dalla pagina «I tuoi dati», dopo aver fatto l\'accesso.',
            ]);
        }
        return Response::json([
            'ok'      => true,
            'status'  => 'cooling_off',
            'message' => sprintf(
                'Cancellazione confermata. Sarà eseguita tra %d giorni. Puoi annullarla in qualsiasi momento.',
                DeletionRequestService::COOLING_OFF_DAYS
            ),
        ]);
    }

    public function cancelDeletion(Request $req): Response
    {
        $userId = $this->currentUserId();
        if ($userId === 0) {
            return $this->nonAutenticato($req);
        }

        $ok = $this->deletions->cancel($userId);
        $messaggio = $ok ? 'Cancellazione annullata.' : 'Nessuna cancellazione attiva da annullare.';
        if (Pagine::vuolePagina($req)) {
            return Pagine::pagina(
                $ok ? 'Cancellazione annullata' : 'Niente da annullare',
                $ok ? 'ok' : 'info',
                [$ok
                    ? 'Hai annullato la richiesta di cancellazione: il tuo account resta com\'è.'
                    : 'Non c\'era nessuna richiesta di cancellazione in corso da annullare.'],
            );
        }
        return Response::json([
            'ok' => $ok,
            'message' => $messaggio,
        ]);
    }

    public function deletionStatus(Request $req): Response
    {
        $userId = $this->currentUserId();
        if ($userId === 0) {
            return Response::json(['error' => 'unauthorized'], 401);
        }

        $req = $this->deletions->activeRequest($userId);
        return Response::json([
            'ok'      => true,
            'pending' => $req !== null,
            'request' => $req,
        ]);
    }

    // ─────── Export Art. 20 ───────

    /**
     * Phase 25.R.23 — Refactor: usa UserDataExportService centralizzato.
     *
     * Restituisce un ZIP con cartelle organizzate (profile/, content/, ...)
     * + manifest.json con sha256 per ogni file. Coerente con bundle authority
     * export ma SENZA audit_log (riservato admin) e SENZA HMAC firma
     * (non serve in self-service — l'utente già si fida del proprio download).
     */
    public function exportData(Request $req): Response
    {
        $userId = $this->currentUserId();
        if ($userId === 0) {
            return Response::json(['error' => 'unauthorized'], 401);
        }

        $svc = \App\Services\Gdpr\Export\UserDataExportService::default();
        $ctx = new \App\Services\Gdpr\Export\ExportContext(
            userId: $userId,
            scope: \App\Services\Gdpr\Export\ExportContext::SCOPE_SELF_SERVICE,
            requestorId: $userId,
            reason: 'self-service Art. 15/20 GDPR',
        );
        $sections = $svc->buildExport($ctx);

        // Build ZIP
        $ts = date('Ymd-His');
        $zipPath = tempnam(sys_get_temp_dir(), 'fm-self-export-');
        if ($zipPath === false) {
            return Response::json(['error' => 'tempfile_failed'], 500);
        }
        $zip = new \ZipArchive();
        if ($zip->open($zipPath, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
            @unlink($zipPath);
            return Response::json(['error' => 'zip_open_failed'], 500);
        }

        $manifest = [
            'manifest_version'       => '2.0',
            'product'                => 'pantedu',
            'export_purpose'         => 'self-service-gdpr',
            'legal_basis'            => 'Art. 15 + Art. 20 GDPR (right of access + data portability)',
            'data_subject_user_id'   => $userId,
            'exported_at'            => date(DATE_ATOM),
            'sections'               => $svc->aggregateSummary($sections),
        ];

        foreach ($sections as $section) {
            foreach ($section->files as $f) {
                $zip->addFromString($f->relativePath, $f->content);
            }
        }
        $zip->addFromString(
            'manifest.json',
            (string)json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
        );
        $zip->close();

        $zipBody = (string)@file_get_contents($zipPath);
        @unlink($zipPath);

        $filename = "pantedu-data-export-{$userId}-{$ts}.zip";
        return new Response($zipBody, 200, [
            'Content-Type'        => 'application/zip',
            'Content-Disposition' => 'attachment; filename="' . $filename . '"',
            'Content-Length'      => (string)strlen($zipBody),
            'Cache-Control'       => 'no-store',
        ]);
    }

    // ─────── Rettifica Art. 16 ───────

    public function profilePatch(Request $req): Response
    {
        $userId = $this->currentUserId();
        if ($userId === 0) {
            return Response::json(['error' => 'unauthorized'], 401);
        }

        // L'email non si cambia più da qui (15/9/2026): è il canale del recupero
        // password e del secondo fattore, e qui bastava il gettone CSRF. Si
        // cambia da /me/account, con la password e un link al nuovo indirizzo.
        if (isset($req->post['email']) && is_string($req->post['email']) && trim($req->post['email']) !== '') {
            if (!filter_var(trim($req->post['email']), FILTER_VALIDATE_EMAIL)) {
                return Response::json(['error' => 'invalid_email'], 400);
            }
            return Response::json([
                'error'   => 'email_da_confermare',
                'message' => "L'email si cambia da /me/account, con la password e un link di conferma al nuovo indirizzo.",
            ], 409);
        }
        $allowed = ['first_name', 'last_name'];
        $cols = [];
        $args = [];
        foreach ($allowed as $f) {
            if (isset($req->post[$f]) && is_string($req->post[$f])) {
                $val = trim($req->post[$f]);
                if ($val === '') {
                    continue;
                }
                $cols[] = "$f = ?";
                $args[] = $val;
            }
        }
        if (!$cols) {
            return Response::json(['error' => 'no_fields_provided'], 400);
        }

        $args[] = $userId;
        $sql = 'UPDATE users SET ' . implode(', ', $cols) . ' WHERE id = ?';
        $stmt = Database::connection()->prepare($sql);
        $stmt->execute($args);

        return Response::json([
            'ok' => true,
            'updated_fields' => count($cols),
            'message' => 'Profilo aggiornato.',
        ]);
    }

    // ─────── Helpers ───────

    private function nonAutenticato(Request $req): Response
    {
        return Pagine::vuolePagina($req)
            ? Response::redirect('/login')
            : Response::json(['error' => 'unauthorized'], 401);
    }


    private function currentUserId(): int
    {
        $u = Auth::user();
        if (!$u) {
            return 0;
        }
        return \App\Support\TeacherContextResolver::userIdFromUsername((string)($u['username'] ?? ''));
    }

    /**
     * IP per gli hash dei consensi e delle cancellazioni.
     *
     * 23/9/2026 — quello di EdgeContext, come in tutti gli altri registri.
     * Prima si prendeva `X-Forwarded-For` per intero: un header che sceglie il
     * client, e per giunta l'elenco completo. L'hash era quindi falsificabile,
     * e dietro un proxy non si poteva confrontare con quelli di
     * `audit_activity_log`, che dell'elenco tenevano il primo elemento
     * (revisione architetturale 2026-09, A-63).
     */
    private function clientIp(Request $req): ?string
    {
        $ip = \App\Services\Waf\EdgeContext::clientIp($req->server ?? []);
        return $ip === '0.0.0.0' ? null : $ip;
    }
}
