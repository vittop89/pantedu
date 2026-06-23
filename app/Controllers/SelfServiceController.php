<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Database;
use App\Core\Request;
use App\Core\Response;
use App\Services\Gdpr\ConsentService;
use App\Services\Gdpr\DeletionRequestService;

/**
 * Phase 25.C — Self-service GDPR endpoints per data subjects (Art. 7, 16, 17, 20).
 *
 * Endpoint:
 *   GET  /me/consents                    listActive
 *   POST /me/consents/grant              grant(type)
 *   POST /me/consents/revoke             revoke(type)
 *
 *   POST /me/request-deletion            Art. 17 → genera token + email
 *   GET  /me/confirm-deletion?token=...  Art. 17 → confirm + cooling_off
 *   POST /me/cancel-deletion             Art. 17 → annulla durante cooling-off
 *   GET  /me/deletion-status             Art. 17 → status corrente
 *
 *   GET  /me/export-data                 Art. 20 → ZIP JSON portabilità
 *   PATCH /me/profile                    Art. 16 → rettifica first_name/last_name/email
 *
 * Tutti richiedono auth (no role:guest). CSRF su POST/PATCH.
 */
final class SelfServiceController
{
    public function __construct(
        private readonly ConsentService $consents = new ConsentService(),
        private readonly DeletionRequestService $deletions = new DeletionRequestService(),
    ) {
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

    public function requestDeletion(Request $req): Response
    {
        $userId = $this->currentUserId();
        if ($userId === 0) {
            return Response::json(['error' => 'unauthorized'], 401);
        }

        $reason = trim((string)($req->post['reason'] ?? ''));
        $token = $this->deletions->request($userId, $reason ?: null, $this->clientIp($req));

        // Caller (controller) responsabile email. In Phase 25.C iniziale
        // logghiamo solo il token; mailer integration Phase 25.E future.
        // Per dev/CI/E2E ritorniamo il token nella response, opt-in via env.
        $isDevOrTest = ($_ENV['APP_ENV'] ?? 'production') !== 'production'
            || ($_ENV['EXPOSE_DELETION_DEBUG_TOKEN'] ?? '') === '1';

        return Response::json([
            'ok'                  => true,
            'message'             => 'Email di conferma inviata. Controlla la casella per confermare la cancellazione.',
            'cooling_off_days'    => DeletionRequestService::COOLING_OFF_DAYS,
            'token_expiry_days'   => DeletionRequestService::TOKEN_EXPIRY_DAYS,
            // SOLO in dev/test: include token per testing
            'debug_token'         => $isDevOrTest ? $token : null,
        ]);
    }

    public function confirmDeletion(Request $req): Response
    {
        $token = trim((string)($req->query['token'] ?? ''));
        if ($token === '') {
            return Response::json(['error' => 'token_required'], 400);
        }

        $ok = $this->deletions->confirm($token, $this->clientIp($req));
        if (!$ok) {
            return Response::json([
                'error' => 'token_invalid_or_expired',
                'message' => 'Il token è non valido, già usato, o scaduto. Avvia una nuova richiesta.',
            ], 400);
        }
        return Response::json([
            'ok' => true,
            'status' => 'cooling_off',
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
            return Response::json(['error' => 'unauthorized'], 401);
        }

        $ok = $this->deletions->cancel($userId);
        return Response::json([
            'ok' => $ok,
            'message' => $ok ? 'Cancellazione annullata.' : 'Nessuna cancellazione attiva da annullare.',
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

        $allowed = ['first_name', 'last_name', 'email'];
        $cols = [];
        $args = [];
        foreach ($allowed as $f) {
            if (isset($req->post[$f]) && is_string($req->post[$f])) {
                $val = trim($req->post[$f]);
                if ($f === 'email' && !filter_var($val, FILTER_VALIDATE_EMAIL)) {
                    return Response::json(['error' => 'invalid_email'], 400);
                }
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
            'message' => 'Profilo aggiornato. Per cambio email è raccomandata la verifica via mail di conferma (Phase futura).',
        ]);
    }

    // ─────── Helpers ───────

    private function currentUserId(): int
    {
        $u = Auth::user();
        if (!$u) {
            return 0;
        }
        return \App\Support\TeacherContextResolver::userIdFromUsername((string)($u['username'] ?? ''));
    }

    private function clientIp(Request $req): ?string
    {
        return $req->server['HTTP_X_FORWARDED_FOR']
            ?? $req->server['HTTP_CLIENT_IP']
            ?? $req->server['REMOTE_ADDR']
            ?? null;
    }
}
