<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Config;
use App\Core\Database;
use App\Core\Request;
use App\Core\Response;
use App\Services\Mailer;
use App\Support\StandalonePageRenderer;
use Throwable;

/**
 * Phase 25.C13 — DPO contact form pubblico (no auth richiesto).
 *
 * Permette ai data subject di esercitare diritti GDPR via form web (Art. 12
 * §2: facilitare l'esercizio dei diritti). Risposta DPO entro 1 mese
 * (Art. 12 §3, prorogabile a 60g con comunicazione motivata).
 *
 * Subject mappato a 6 diritti principali + breach report + altro:
 *   access, rectification, erasure, restriction, portability, objection,
 *   consent_revoke, breach_report, other.
 *
 * Endpoint:
 *   GET  /dpo-contact          → form HTML
 *   POST /dpo-contact          → submit (rate-limit + CSRF)
 *
 * NB: form pubblico → CSRF token via cookie (NO server session richiesta).
 * Antispam: rate-limit + honeypot field + IP hash.
 */
final class DpoContactController
{
    public const SUBJECTS = [
        'access', 'rectification', 'erasure', 'restriction', 'portability',
        'objection', 'consent_revoke', 'breach_report', 'other',
    ];

    public const SUBJECT_LABELS = [
        'access'          => 'Accesso ai miei dati (Art. 15)',
        'rectification'   => 'Rettifica dati (Art. 16)',
        'erasure'         => 'Cancellazione / oblio (Art. 17)',
        'restriction'     => 'Limitazione del trattamento (Art. 18)',
        'portability'     => 'Portabilità dei dati (Art. 20)',
        'objection'       => 'Opposizione al trattamento (Art. 21)',
        'consent_revoke'  => 'Revoca consenso (Art. 7 §3)',
        'breach_report'   => 'Segnalazione data breach',
        'other'           => 'Altra richiesta al DPO',
    ];

    /** GET /dpo-contact — form HTML standalone. */
    public function show(Request $req): Response
    {
        return Response::html($this->renderForm());
    }

    /**
     * POST /dpo-contact — submit.
     * Validation: name+email+subject+message obbligatori. message > 20 char.
     * Honeypot field "url_field" (anti-bot).
     * Rate-limit gestito da middleware route.
     */
    public function submit(Request $req): Response
    {
        // Honeypot: se questo field è popolato, è bot
        if (!empty($req->post['url_field'] ?? '')) {
            return Response::html($this->renderResult(
                'Richiesta ricevuta',
                'Grazie per la tua richiesta. Riceverai una risposta entro 30 giorni.'
            ));
        }

        $name    = trim((string)($req->post['name'] ?? ''));
        $email   = trim((string)($req->post['email'] ?? ''));
        $subject = (string)($req->post['subject'] ?? '');
        $message = trim((string)($req->post['message'] ?? ''));
        $isMinor = !empty($req->post['is_minor_related'] ?? '');

        // Validation
        if ($name === '' || strlen($name) > 120) {
            return Response::html($this->renderForm('Il nome è obbligatorio (max 120 caratteri).'), 400);
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return Response::html($this->renderForm('Email non valida.'), 400);
        }
        if (!in_array($subject, self::SUBJECTS, true)) {
            return Response::html($this->renderForm('Oggetto richiesta non valido.'), 400);
        }
        if (strlen($message) < 20 || strlen($message) > 8192) {
            return Response::html($this->renderForm('Il messaggio deve essere tra 20 e 8192 caratteri.'), 400);
        }

        // INSERT row
        $ip = $req->server['REMOTE_ADDR'] ?? null;
        $ua = $req->server['HTTP_USER_AGENT'] ?? null;
        $stmt = Database::connection()->prepare(
            'INSERT INTO dpo_requests
                (name, email, subject, is_minor_related, message, ip_hash, user_agent_hash, status)
             VALUES (?, ?, ?, ?, ?, ?, ?, "open")'
        );
        $stmt->execute([
            $name, strtolower($email), $subject, $isMinor ? 1 : 0, $message,
            $ip ? hash('sha256', $ip, true) : null,
            $ua ? hash('sha256', $ua, true) : null,
        ]);
        $requestId = (int)Database::connection()->lastInsertId();

        // Phase 25.R.4.3 — invio email (best-effort, errori loggati ma non
        // bloccano il submit). Due email:
        //   1. Acknowledgment al richiedente (Art. 12 §3 facilitazione)
        //   2. Notifica al DPO (indirizzo da $_ENV['DPO_EMAIL']) per intervento manuale
        $emailsSent = $this->sendAcknowledgmentEmails(
            $requestId,
            $name,
            strtolower($email),
            $subject,
            $message,
            $isMinor
        );
        if ($emailsSent) {
            // Aggiorna acknowledged_at = NOW() (SLA 72h rispettato immediatamente)
            try {
                $upd = Database::connection()->prepare(
                    'UPDATE dpo_requests SET status = "acknowledged", acknowledged_at = NOW() WHERE id = ?'
                );
                $upd->execute([$requestId]);
            } catch (Throwable $e) {
                error_log('[DpoContact] ack update failed: ' . $e->getMessage());
            }
        }

        return Response::html($this->renderResult(
            'Richiesta ricevuta — N° ' . $requestId,
            '<p>Grazie ' . htmlspecialchars($name) . '. La tua richiesta è stata registrata.</p>'
            . '<p><strong>Cosa succede ora?</strong></p>'
            . '<ul>'
            . '<li>Riceverai un acknowledgment via email entro <strong>72 ore</strong>.</li>'
            . '<li>Il DPO risponderà alla tua richiesta entro <strong>30 giorni</strong> (prorogabili a 60 con comunicazione motivata, Art. 12 §3 GDPR).</li>'
            . '<li>In caso di insoddisfazione, puoi presentare reclamo al <a href="https://www.garanteprivacy.it" target="_blank">Garante Privacy</a>.</li>'
            . '</ul>'
            . '<p><strong>Riferimento richiesta:</strong> #' . $requestId . '</p>'
        ));
    }

    /**
     * Phase 25.R.4.3 — invia 2 email (ack al richiedente + notify al DPO).
     * Best-effort: ritorna true se almeno l'ack al richiedente è andato.
     * Errori sono loggati ma non bloccano il submit (resilienza pubblica).
     */
    private function sendAcknowledgmentEmails(
        int $requestId,
        string $name,
        string $emailLower,
        string $subjectCode,
        string $message,
        bool $isMinor
    ): bool {
        $from   = (string)($_ENV['MAIL_FROM'] ?? 'noreply@pantedu.eu');
        $dpoTo  = (string)($_ENV['DPO_EMAIL'] ?? 'dpo@pantedu.eu');
        $siteUrl = (string)(Config::get('app.url') ?: 'https://pantedu.eu');
        $subjectLabel = self::SUBJECT_LABELS[$subjectCode] ?? $subjectCode;

        $mailer = new Mailer($from, 'Pantedu DPO');
        $logFile = (string)Config::get('app.paths.storage') . '/logs/mail.log';

        // 1) Acknowledgment al richiedente
        $ackBody = "Ciao {$name},\n\n"
            . "abbiamo ricevuto la tua richiesta GDPR #{$requestId} ({$subjectLabel}).\n\n"
            . "Cosa succede ora:\n"
            . "- Il DPO la prenderà in carico entro 30 giorni (Art. 12 §3 GDPR).\n"
            . "- In caso di complessità il termine può essere prorogato a 60g con comunicazione motivata.\n"
            . "- Conserva questo messaggio come ricevuta (riferimento: #{$requestId}).\n\n"
            . "Reclami: " . ($siteUrl !== '' ? $siteUrl . '/privacy/informativa' : 'https://www.garanteprivacy.it') . "\n\n"
            . "— Pantedu DPO\n";
        $ackSubject = "Richiesta GDPR #{$requestId} ricevuta — {$subjectLabel}";
        $ackSent = false;
        try {
            $mailer->logSend($emailLower, $ackSubject, $ackBody, $logFile);
            $ackSent = $mailer->send($emailLower, $ackSubject, $ackBody);
        } catch (Throwable $e) {
            error_log('[DpoContact] ack send failed: ' . $e->getMessage());
        }

        // 2) Notifica al DPO (best-effort, non blocca la return value)
        $dpoBody = "Nuova richiesta GDPR #{$requestId}\n\n"
            . "Subject: {$subjectLabel} ({$subjectCode})\n"
            . "Minore coinvolto: " . ($isMinor ? 'SÌ' : 'no') . "\n"
            . "Richiedente: {$name} <{$emailLower}>\n\n"
            . "Messaggio:\n{$message}\n\n"
            . "Gestisci via /admin/data-requests (#{$requestId}).\n"
            . "SLA: rispondi entro 30 giorni dalla ricezione.\n";
        $dpoSubject = "[DPO] Richiesta GDPR #{$requestId} — {$subjectLabel}";
        try {
            $mailer->logSend($dpoTo, $dpoSubject, $dpoBody, $logFile);
            $mailer->send($dpoTo, $dpoSubject, $dpoBody);
        } catch (Throwable $e) {
            error_log('[DpoContact] dpo notify failed: ' . $e->getMessage());
        }

        return $ackSent;
    }

    private function renderForm(string $error = ''): string
    {
        $errorBlock = $error !== ''
            ? '<div class="fm-error-banner">' . htmlspecialchars($error) . '</div>'
            : '';

        $subjectOptions = '';
        foreach (self::SUBJECT_LABELS as $value => $label) {
            $subjectOptions .= '<option value="' . htmlspecialchars($value) . '">' . htmlspecialchars($label) . '</option>';
        }

        $body = $errorBlock
            . '<h2>Contatta il DPO (Data Protection Officer)</h2>'
            . '<p>Usa questo form per esercitare i tuoi diritti GDPR (Art. 15-22) o segnalare problemi privacy.</p>'
            . '<p><strong>SLA</strong>: ti risponderemo entro 30 giorni (Art. 12 §3 GDPR).</p>'
            . '<form method="POST" action="/dpo-contact" autocomplete="off">'
            . '<input type="hidden" name="_csrf" value="' . htmlspecialchars(\App\Core\Csrf::token(), ENT_QUOTES, 'UTF-8') . '">'
            . '<label>Nome e cognome *<input type="text" name="name" required maxlength="120" autofocus></label>'
            . '<label>Email *<input type="email" name="email" required maxlength="255"></label>'
            . '<label>Tipo richiesta *<select name="subject" required>'
            . '<option value="">— seleziona —</option>'
            . $subjectOptions
            . '</select></label>'
            . '<label class="checkbox-label"><input type="checkbox" name="is_minor_related" value="1"> '
            . 'La richiesta riguarda un minore (sono il genitore / tutore legale)</label>'
            . '<label>Messaggio dettagliato *<textarea name="message" required minlength="20" maxlength="8192" rows="8" placeholder="Descrivi la tua richiesta. Per esercizio diritti su dati personali, indica username/email dell\'account interessato (se diverso da questa email)."></textarea></label>'
            . '<input type="text" name="url_field" tabindex="-1" autocomplete="off" class="fm-honeypot" aria-hidden="true">'
            . '<button type="submit">Invia richiesta</button>'
            . '</form>'
            . '<p class="fm-trust-meta"><a href="/privacy/informativa">Informativa privacy</a> · '
            . '<a href="https://www.garanteprivacy.it" target="_blank">Garante Privacy</a> per reclami</p>';

        return $this->renderPage('Contatta il DPO', $body);
    }

    private function renderResult(string $title, string $body): string
    {
        return $this->renderPage(
            $title,
            '<h2>' . htmlspecialchars($title) . '</h2>' . $body
            . '<p class="fm-back-home"><a href="/">← Torna alla home</a></p>'
        );
    }

    private function renderPage(string $title, string $body): string
    {
        return StandalonePageRenderer::render($title . ' — Pantedu', $body, [
            'extraStyles' => '
                .fm-honeypot { position: absolute; left: -9999px; }
                .fm-trust-meta { margin-top: 2em; font-size: 0.9em; color: var(--fm-fg-muted); }
                .fm-back-home { margin-top: 1.5em; }
            ',
            // Phase 25.R.2.4 — preserva sidebar+bottombar su direct hit.
            'useAppLayout' => true,
        ]);
    }
}
