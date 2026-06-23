<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Database;
use App\Core\Request;
use App\Core\Response;
use App\Services\Gdpr\ParentConsentService;

/**
 * Phase 25.C7 — Endpoint pubblici per parent consent Art. 8 GDPR.
 *
 * Endpoint:
 *   GET  /parent-consent/{token}      → preview HTML "Conferma o rifiuta"
 *   POST /parent-consent/{token}      → confirm via form submit
 *   POST /parent-consent/revoke       → revoca da genitore con email verify
 *
 * NB: NO auth required — il token in URL è la prova di identità del
 * genitore (link inviato a parent_email). Token è single-use + TTL 30g.
 */
final class ParentConsentController
{
    public function __construct(
        private readonly ParentConsentService $service = new ParentConsentService()
    ) {
    }

    /**
     * GET /parent-consent/{token} — preview pagina.
     * Mostra: "Tuo figlio/a [NOME] ha richiesto di registrarsi a Pantedu.
     * Confermi il consenso al trattamento dati? [Confermo] [Rifiuto]"
     */
    public function preview(Request $req, array $params): Response
    {
        $token = (string)($params['token'] ?? '');
        if ($token === '') {
            return Response::json(['error' => 'token_missing'], 400);
        }

        // Look up parent_consent row + student name (user_id → first_name)
        $stmt = Database::connection()->prepare(
            'SELECT pc.id, pc.student_user_id, pc.parent_email, pc.parent_name,
                    pc.status, pc.expires_at,
                    u.first_name AS student_first_name, u.last_name AS student_last_name
             FROM parent_consents pc
             LEFT JOIN users u ON u.id = pc.student_user_id
             WHERE pc.confirm_token = ?'
        );
        $stmt->execute([$token]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        if (!$row) {
            return Response::html($this->renderPage(
                'Token non valido',
                'Il link di conferma non è valido o è già stato usato.'
            ), 404);
        }

        if ($row['status'] === 'confirmed') {
            return Response::html($this->renderPage(
                'Già confermato',
                'Hai già confermato il consenso per ' . htmlspecialchars($row['student_first_name'] ?? '') . '. '
                . 'L\'account dello studente è attivo.'
            ));
        }
        if ($row['status'] === 'revoked') {
            return Response::html($this->renderPage(
                'Consenso revocato',
                'Il consenso è stato revocato. L\'account dello studente è disattivato.'
            ));
        }
        if (
            $row['status'] === 'expired'
            || ($row['expires_at'] && strtotime($row['expires_at']) < time())
        ) {
            return Response::html($this->renderPage(
                'Token scaduto',
                'Il link di conferma è scaduto (TTL 30 giorni). '
                . 'Lo studente deve richiedere un nuovo link via supporto.'
            ), 410);
        }

        $studentFullName = trim(($row['student_first_name'] ?? '') . ' ' . ($row['student_last_name'] ?? ''));
        $body = '<h2>Consenso parentale richiesto</h2>'
              . '<p>Tuo figlio/a <strong>' . htmlspecialchars($studentFullName) . '</strong> '
              . 'ha richiesto di registrarsi alla piattaforma educativa Pantedu.</p>'
              . '<p>Per attivare l\'account è necessario il tuo consenso (Art. 8 GDPR — '
              . 'minori sotto i 14 anni in Italia).</p>'
              . '<p>Leggi l\'<a href="/privacy/informativa" target="_blank">informativa privacy</a> '
              . 'prima di confermare.</p>'
              . '<form method="POST" action="/parent-consent/' . htmlspecialchars($token) . '">'
              . '<button type="submit" name="action" value="confirm">✓ Confermo il consenso</button>'
              . '<button type="submit" name="action" value="reject" style="margin-left:1em">✗ Rifiuto</button>'
              . '</form>';
        return Response::html($this->renderPage('Conferma consenso parentale', $body));
    }

    /**
     * POST /parent-consent/{token} — confirm o reject.
     * action=confirm → ParentConsentService::confirm + activate user
     * action=reject  → mark expired, cascade delete student account
     */
    public function confirm(Request $req, array $params): Response
    {
        $token = (string)($params['token'] ?? '');
        $action = (string)($req->post['action'] ?? '');

        if ($action === 'reject') {
            // Phase 25.C7.fix (GDPR-001) — delega a ParentConsentService::reject:
            //   - audit log su consent_audit (Art. 30) con IP/UA hash
            //   - soft-delete (anonymize) invece di hard DELETE → preserva
            //     audit trail e referenze FK
            //   - status='revoked' invece di 'expired' (semantica esatta)
            $ip = $req->server['REMOTE_ADDR'] ?? null;
            $ua = $req->server['HTTP_USER_AGENT'] ?? null;
            $result = $this->service->reject($token, $ip, $ua);

            if (!$result['ok']) {
                $errMessages = [
                    'token_invalid_or_used' => 'Il link è non valido o è già stato usato.',
                    'reject_failed' => 'Errore tecnico. Riprova o contatta il supporto.',
                ];
                $errKey = explode(':', (string)($result['error'] ?? ''), 2)[0];
                $msg = $errMessages[$errKey] ?? 'Errore non specificato.';
                return Response::html($this->renderPage('Errore rifiuto', $msg), 400);
            }

            return Response::html($this->renderPage(
                'Consenso rifiutato',
                'Hai rifiutato il consenso. L\'account dello studente è stato disattivato. '
                . 'Se è un errore, lo studente può rifare la registrazione.'
            ));
        }

        $ip = $req->server['REMOTE_ADDR'] ?? null;
        $ua = $req->server['HTTP_USER_AGENT'] ?? null;
        $result = $this->service->confirm($token, $ip, $ua);

        if (!$result['ok']) {
            $errMessages = [
                'token_invalid_or_used' => 'Il link è non valido o è già stato usato.',
                'token_expired' => 'Il link di conferma è scaduto. Lo studente deve richiedere un nuovo link.',
                'activation_failed' => 'Errore tecnico durante l\'attivazione. Riprova o contatta il supporto.',
            ];
            $msg = $errMessages[$result['error']] ?? 'Errore non specificato.';
            return Response::html($this->renderPage('Errore conferma', $msg), 400);
        }

        return Response::html($this->renderPage(
            'Consenso confermato',
            '<p>✅ Hai confermato il consenso. L\'account dello studente è ora attivo.</p>'
            . '<p>Lo studente può ora accedere a <a href="/login">Pantedu</a> con username e password.</p>'
            . '<p>Per revocare in futuro questo consenso (Art. 8 §3 GDPR), invia richiesta a '
            . '<a href="mailto:operatore@example.net?subject=Revoca consenso parentale">operatore@example.net</a>.</p>'
        ));
    }

    /**
     * Renderizza una pagina HTML minimale (no template engine — semplice
     * per parent endpoint che gira pre-auth).
     */
    private function renderPage(string $title, string $body): string
    {
        $titleEsc = htmlspecialchars($title);
        return <<<HTML
            <!DOCTYPE html>
            <html lang="it">
            <head>
                <meta charset="utf-8">
                <meta name="viewport" content="width=device-width, initial-scale=1">
                <title>{$titleEsc} — Pantedu</title>
                <style>
                    body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
                           max-width: 600px; margin: 2em auto; padding: 1em; line-height: 1.5; }
                    h2 { color: #1e40af; }
                    button { padding: 0.6em 1.2em; font-size: 1em; cursor: pointer;
                             border: 1px solid #ccc; border-radius: 4px; background: #fff; }
                    button[value="confirm"] { background: #16a34a; color: white; border-color: #16a34a; }
                    button[value="reject"] { background: #dc2626; color: white; border-color: #dc2626; }
                    a { color: #1e40af; }
                </style>
            </head>
            <body>
                <h2>{$titleEsc}</h2>
                {$body}
            </body>
            </html>
            HTML;
    }
}
