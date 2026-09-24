<?php

declare(strict_types=1);

namespace App\Controllers\Public;

use App\Core\Config;
use App\Core\Request;
use App\Core\Response;
use App\Services\Gdpr\TakedownRequestService;
use App\Services\Mailer;
use App\Support\Anomalia;
use App\Support\IndirizzoPubblico;
use App\Support\StandalonePageRenderer;
use InvalidArgumentException;

/**
 * Phase 25.P — Controller pubblico per Notice & Takedown submissions.
 *
 * Route (routes/web.php):
 *   GET  /segnalazione-contenuti       → show form
 *   POST /segnalazione-contenuti       → submit (rate:takedown,3,3600 → 3/h per IP)
 *
 * Vedi:
 *   - docs/legal/takedown_procedure.md
 *   - app/Services/Gdpr/TakedownRequestService.php
 *   - database/migrations/057_takedown_requests.sql
 *
 * Da valutare se il volume cresce:
 *   - anti-bot (hCaptcha) oltre al rate limit
 *   - alert Grafana su submission ad alto volume
 */
class PublicTakedownController
{
    private TakedownRequestService $service;
    private ?Mailer $mailer;

    public function __construct(?TakedownRequestService $service = null, ?Mailer $mailer = null)
    {
        $this->service = $service ?? new TakedownRequestService();
        $this->mailer  = $mailer ?? self::defaultMailer();
    }

    private static function defaultMailer(): ?Mailer
    {
        return Mailer::fromConfig();
    }

    /**
     * Casella presidiata che riceve le segnalazioni: `mail.abuse_email`
     * (ABUSE_EMAIL, o CONTACT_EMAIL). Fino al 23/9/2026 era scritta qui con il
     * dominio di produzione, e le segnalazioni di un'altra istanza — con nome,
     * email e IP di chi segnala — arrivavano a noi anche con la posta
     * configurata (revisione del 23/9, rilievo A-13).
     */
    private static function casella(): string
    {
        return trim((string)Config::get('mail.abuse_email', ''));
    }

    /** La frase con la casella, per le pagine; niente se la casella non c'è. */
    private static function oScriviA(string $prima): string
    {
        $casella = self::casella();
        return $casella === '' ? '' : $prima . '<strong>' . htmlspecialchars($casella) . '</strong>';
    }

    /**
     * GET /segnalazione-contenuti
     * Mostra form HTML pubblico (StandalonePageRenderer scoped — no sidebar leak).
     */
    public function showForm(): Response
    {
        return Response::html($this->renderPage($this->renderFormBody()));
    }

    /**
     * POST /segnalazione-contenuti
     * Riceve submission, valida, salva in DB.
     */
    public function submit(Request $req): Response
    {
        try {
            $data = [
                'submitter_name' => $this->cleanString($req->post['submitter_name'] ?? null),
                'submitter_email' => $this->cleanString($req->post['submitter_email'] ?? null),
                'submitter_role' => $this->cleanString($req->post['submitter_role'] ?? 'private'),
                // L'impronta con chiave, non l'IP (24/9/2026, migrazione 141).
                'submitter_ip' => \App\Support\ImprontaIp::esadecimale($req->server['REMOTE_ADDR'] ?? null),
                'content_ref' => $this->cleanString($req->post['content_ref'] ?? ''),
                'violation_type' => $this->cleanString($req->post['violation_type'] ?? ''),
                'description' => trim((string)($req->post['description'] ?? '')),
            ];

            $requestId = $this->service->submit($data);

            // Phase 25.Q — notifica abuse@ via mail (best-effort, no block).
            $this->notifyAbuseEmail($requestId, $data);

            return Response::html($this->renderPage($this->renderSuccessBody($requestId)));
        } catch (InvalidArgumentException $e) {
            return Response::html($this->renderPage($this->renderFormBody($e->getMessage())), 400);
        } catch (\Throwable $e) {
            error_log('[PublicTakedownController] ' . $e->getMessage());
            $casella = self::casella();
            return Response::html(
                $this->renderPage($this->renderFormBody(
                    'Errore interno. Riprova più tardi' . ($casella !== '' ? " o scrivi a {$casella}" : '') . '.'
                )),
                500
            );
        }
    }

    /**
     * Phase 25.Q — invia mail di notifica alla casella delle segnalazioni.
     * Best-effort: errori vengono solo loggati e non bloccano il submit.
     *
     * Passa da Mailer (Resend) e non da mail(): il dominio è autenticato solo
     * sul percorso Resend (SPF sul sottodominio di invio + DKIM
     * resend._domainkey), quindi una mail uscita dal PHP del VPS non è
     * allineata DMARC.
     */
    private function notifyAbuseEmail(int $requestId, array $data): void
    {
        if ($this->mailer === null) {
            error_log('[PublicTakedownController] APP_MAIL_FROM non configurata: notifica abuse@ saltata');
            return;
        }
        $casella = self::casella();
        if ($casella === '') {
            // La segnalazione è salvata e sta nella coda di /admin/takedown,
            // ma nessuno la riceve per posta, e la procedura ha dei tempi: lo
            // si dice alla diagnostica, senza i dati di chi segnala.
            error_log('[PublicTakedownController] ABUSE_EMAIL (e CONTACT_EMAIL) non configurate: notifica saltata');
            Anomalia::registra(
                'segnalazione_senza_casella',
                'Una segnalazione di contenuti è stata salvata ma non notificata: manca ABUSE_EMAIL (e CONTACT_EMAIL).',
                ['richiesta' => $requestId],
            );
            return;
        }

        try {
            $subject = "[pantedu abuse-{$requestId}] Nuova segnalazione " . ($data['violation_type'] ?? '');
            $body = "Nuova segnalazione ricevuta su /segnalazione-contenuti.\n\n"
                  . "ID: {$requestId}\n"
                  . "Tipo: " . ($data['violation_type'] ?? '—') . "\n"
                  . "Ruolo segnalante: " . ($data['submitter_role'] ?? '—') . "\n"
                  . "Nome: " . ($data['submitter_name'] ?? '(anonimo)') . "\n"
                  . "Email: " . ($data['submitter_email'] ?? '—') . "\n"
                  . "Content ref: " . ($data['content_ref'] ?? '—') . "\n\n"
                  . "Descrizione:\n" . ($data['description'] ?? '—') . "\n\n"
                  . "Dettaglio + azione: " . self::dettaglio($requestId) . "\n";

            // Reply-To sul segnalante: rispondere dalla casella abuse@ deve
            // bastare per chiedere chiarimenti (Fase 1 della procedura).
            $replyTo = $data['submitter_email'] ?? null;
            if ($replyTo !== null && !filter_var($replyTo, FILTER_VALIDATE_EMAIL)) {
                $replyTo = null;
            }

            $this->mailer->send($casella, $subject, $body, $replyTo);
        } catch (\Throwable $e) {
            error_log('[PublicTakedownController::notifyAbuseEmail] ' . $e->getMessage());
        }
    }

    /**
     * Il collegamento alla segnalazione nel pannello: era scritto con il
     * dominio di produzione. Di cortesia — la notifica parte anche senza
     * `app.url`, con il percorso da aprire nel pannello (23/9/2026).
     */
    private static function dettaglio(int $requestId): string
    {
        $percorso = "/admin/takedown/{$requestId}";
        $sito = IndirizzoPubblico::radiceSeConfigurata('notifica_segnalazione');
        return $sito !== null ? $sito . $percorso : "{$percorso} (nel pannello di amministrazione)";
    }

    private function cleanString(?string $s): ?string
    {
        if ($s === null) {
            return null;
        }
        $s = trim($s);
        if ($s === '') {
            return null;
        }
        return mb_substr($s, 0, 512, 'UTF-8');
    }

    /**
     * Renderizza solo il body (no DOCTYPE/html/head): viene wrappato da
     * StandalonePageRenderer che applica dark-theme + scope CSS.
     */
    private function renderFormBody(string $error = ''): string
    {
        $errorHtml = $error !== '' ? '<div class="fm-tk-error">' . htmlspecialchars($error) . '</div>' : '';
        $alternativa = self::oScriviA('In alternativa puoi scrivere a ');
        $alternativa = $alternativa !== '' ? "<p>{$alternativa}.</p>" : '';

        return <<<HTML
<h1>Segnalazione contenuti</h1>

<div class="fm-tk-info">
  <p>Questo form permette di segnalare contenuti presenti su questo sito
  che ritieni violino i tuoi diritti
  (copyright, privacy GDPR, contenuti illegali, ecc.).</p>
  <p>Procedura conforme a <strong>D.Lgs. 70/2003 art. 16</strong> (Direttiva
  2000/31/CE — Notice &amp; Takedown). Vedi dettagli in
  <a href="/legal/takedown-procedure">procedura completa</a>.</p>
  {$alternativa}
</div>

{$errorHtml}

<form method="POST" action="/segnalazione-contenuti" class="fm-tk-form">

<label for="submitter_role">Il tuo ruolo:</label>
<select id="submitter_role" name="submitter_role" required>
  <option value="private">Privato cittadino interessato</option>
  <option value="editor">Editore / titolare diritto d'autore</option>
  <option value="dpo_other">DPO di altra organizzazione</option>
  <option value="authority">Autorità competente (Garante / forze ordine)</option>
  <option value="parent">Genitore studente minore</option>
  <option value="self">Studente / docente segnala proprio contenuto</option>
  <option value="anonymous">Segnalazione anonima (valutazione non vincolante)</option>
</select>

<label for="submitter_name">Nome e cognome (consigliato):</label>
<input type="text" id="submitter_name" name="submitter_name" maxlength="255">
<small>Se anonimo, lascia vuoto. Valutazione potrebbe richiedere identificazione.</small>

<label for="submitter_email">Email per risposta (consigliato):</label>
<input type="email" id="submitter_email" name="submitter_email" maxlength="255">

<label for="violation_type">Tipo di violazione:</label>
<select id="violation_type" name="violation_type" required>
  <option value="">— seleziona —</option>
  <option value="copyright">Violazione diritto d'autore (L. 633/1941)</option>
  <option value="gdpr_art9">Violazione GDPR art. 9 (categorie particolari)</option>
  <option value="illegal">Contenuto illegale penalmente</option>
  <option value="inappropriate">Contenuto inappropriato per contesto scolastico</option>
  <option value="spam">Spam / contenuto promozionale</option>
  <option value="other">Altro</option>
</select>

<label for="content_ref">Riferimento contenuto contestato:</label>
<input type="text" id="content_ref" name="content_ref" maxlength="1024" required>
<small>URL, ID risorsa, descrizione precisa per identificarlo.</small>

<label for="description">Descrizione dettagliata della violazione:</label>
<textarea id="description" name="description" required maxlength="65535"
  placeholder="Spiega in dettaglio quale violazione ritieni sia presente, perché, e fornisci eventuali prove a sostegno (es. titolo libro coperto da copyright, fattura editore, identità del soggetto interessato, ecc.)"></textarea>

<p><small>Inviando questa segnalazione confermi che le informazioni fornite
sono veritiere e che la tua segnalazione è in buona fede. Segnalazioni
abusive o false saranno trattate come violazione e potranno comportare
segnalazioni alle autorità competenti.</small></p>

<button type="submit" class="fm-tk-btn">Invia segnalazione</button>

</form>
HTML;
    }

    private function renderSuccessBody(int $requestId): string
    {
        $id = htmlspecialchars((string)$requestId);
        $urgenze = self::oScriviA('Per urgenze contattare direttamente ');
        $urgenze = $urgenze !== '' ? "<p>{$urgenze}.</p>" : '';
        return <<<HTML
<div class="fm-tk-success">
<h1>Segnalazione ricevuta</h1>
<p>La tua segnalazione è stata ricevuta con identificativo <strong>#{$id}</strong>.</p>
<p>Procederemo a valutarla nei tempi SLA stabiliti
(vedi <a href="/legal/takedown-procedure">procedura</a>).</p>
<p>Se hai indicato un'email valida, ti contatteremo non appena disponibile
una risposta motivata.</p>
{$urgenze}
<p><a href="/">← torna alla home</a></p>
</div>
HTML;
    }

    /**
     * Wrap body con StandalonePageRenderer: scope CSS + dark-mode toggle
     * + niente leak di stili sulla sidebar globale.
     */
    private function renderPage(string $body): string
    {
        // Phase 25.R follow-up — Stili scoped a .fm-trust-page wrapper così
        // ereditano le CSS vars dello scope (definite in StandalonePageRenderer::scopedStyles
        // sia light che dark, vedi `--fm-quote-bg`, `--fm-error-bg`, ecc.).
        // Fix dark theme: prima si usavano var non definite (--fm-bg-elev,
        // --fm-info-bg, ...) con fallback CHIARI → form restava bianco anche
        // in dark mode. Ora map a var dello scope che hanno dark counterpart.
        $css = <<<CSS
.fm-trust-page .fm-tk-info {
    background: var(--fm-quote-bg);
    color: var(--fm-fg);
    border-left: 3px solid var(--fm-accent);
    padding: 1em;
    border-radius: 6px;
    margin-bottom: 1em;
}
.fm-trust-page .fm-tk-error {
    background: var(--fm-error-bg);
    color: var(--fm-error-fg);
    padding: 1em;
    border-radius: 6px;
    margin-bottom: 1em;
}
.fm-trust-page .fm-tk-success {
    background: var(--fm-quote-bg);
    color: var(--fm-fg);
    border-left: 3px solid #16a34a;
    padding: 1.5em;
    border-radius: 8px;
}
.fm-trust-page .fm-tk-form {
    background: var(--fm-quote-bg);
    padding: 1.5em;
    border-radius: 8px;
    border: 1px solid var(--fm-border);
}
.fm-trust-page .fm-tk-form label { display: block; margin-top: 1em; font-weight: 600; color: var(--fm-fg); }
.fm-trust-page .fm-tk-form input,
.fm-trust-page .fm-tk-form select,
.fm-trust-page .fm-tk-form textarea {
    width: 100%;
    padding: 0.5em;
    margin-top: 0.3em;
    border: 1px solid var(--fm-input-border);
    border-radius: 4px;
    box-sizing: border-box;
    background: var(--fm-input-bg);
    color: var(--fm-fg);
    font-family: inherit;
    font-size: 1em;
}
.fm-trust-page .fm-tk-form textarea { min-height: 120px; resize: vertical; }
.fm-trust-page .fm-tk-btn {
    background: var(--fm-accent);
    color: #fff;
    padding: 0.8em 1.5em;
    border: 0;
    border-radius: 6px;
    cursor: pointer;
    margin-top: 1.5em;
    font-size: 1em;
}
.fm-trust-page .fm-tk-btn:hover { filter: brightness(1.1); }
.fm-trust-page .fm-tk-form small { color: var(--fm-fg-muted); }
CSS;
        return StandalonePageRenderer::render('Segnalazione contenuti — pantedu', $body, [
            'extraStyles' => $css,
            // Phase 25.R.2.3+2.4 — wrap in layout/app.php (sidebar + bottombar)
            // su direct hit. SPA partial mode invariato. Dark theme ereditato
            // dal layout host via .fm-dark sul wrapper (vedi scopedStyles).
            'useAppLayout' => true,
        ]);
    }
}
