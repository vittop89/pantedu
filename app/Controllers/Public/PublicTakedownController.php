<?php

declare(strict_types=1);

namespace App\Controllers\Public;

use App\Core\Response;
use App\Services\Gdpr\TakedownRequestService;
use App\Support\StandalonePageRenderer;
use InvalidArgumentException;

/**
 * Phase 25.P — Controller pubblico per Notice & Takedown submissions.
 *
 * NON ancora integrato nel router. Pronto per integrazione quando si attiva
 * concretamente Scenario B/C multi-tenant.
 *
 * Route attese (da aggiungere in app/Kernel.php):
 *   GET  /segnalazione-contenuti       → show form
 *   POST /segnalazione-contenuti       → submit
 *
 * Vedi:
 *   - docs/legal/takedown_procedure.md
 *   - app/Services/Gdpr/TakedownRequestService.php
 *   - database/migrations/057_takedown_requests.sql
 *
 * Misure di sicurezza necessarie pre-attivazione:
 *   - Rate limit (es. 5 submission/h per IP via fail2ban filter)
 *   - reCAPTCHA o hCaptcha per anti-bot
 *   - Mail notification a abuse@pantedu.eu con il content nuovo
 *   - Notifica Grafana alert su submission ad alto volume
 */
class PublicTakedownController
{
    private TakedownRequestService $service;

    public function __construct(?TakedownRequestService $service = null)
    {
        $this->service = $service ?? new TakedownRequestService();
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
    public function submit(): Response
    {
        try {
            $data = [
                'submitter_name' => $this->cleanString($_POST['submitter_name'] ?? null),
                'submitter_email' => $this->cleanString($_POST['submitter_email'] ?? null),
                'submitter_role' => $this->cleanString($_POST['submitter_role'] ?? 'private'),
                'submitter_ip' => $_SERVER['REMOTE_ADDR'] ?? null,
                'content_ref' => $this->cleanString($_POST['content_ref'] ?? ''),
                'violation_type' => $this->cleanString($_POST['violation_type'] ?? ''),
                'description' => trim((string)($_POST['description'] ?? '')),
            ];

            $requestId = $this->service->submit($data);

            // Phase 25.Q — notifica abuse@ via mail (best-effort, no block).
            $this->notifyAbuseEmail($requestId, $data);

            return Response::html($this->renderPage($this->renderSuccessBody($requestId)));
        } catch (InvalidArgumentException $e) {
            return Response::html($this->renderPage($this->renderFormBody($e->getMessage())), 400);
        } catch (\Throwable $e) {
            error_log('[PublicTakedownController] ' . $e->getMessage());
            return Response::html(
                $this->renderPage($this->renderFormBody('Errore interno. Riprova più tardi o contatta abuse@pantedu.eu')),
                500
            );
        }
    }

    /**
     * Phase 25.Q — invia mail di notifica ad abuse@pantedu.eu.
     * Best-effort: errori vengono solo loggati e non bloccano il submit.
     */
    private function notifyAbuseEmail(int $requestId, array $data): void
    {
        try {
            $to = 'abuse@pantedu.eu';
            $subject = "[pantedu abuse-{$requestId}] Nuova segnalazione " . ($data['violation_type'] ?? '');
            $body = "Nuova segnalazione ricevuta su /segnalazione-contenuti.\n\n"
                  . "ID: {$requestId}\n"
                  . "Tipo: " . ($data['violation_type'] ?? '—') . "\n"
                  . "Ruolo segnalante: " . ($data['submitter_role'] ?? '—') . "\n"
                  . "Nome: " . ($data['submitter_name'] ?? '(anonimo)') . "\n"
                  . "Email: " . ($data['submitter_email'] ?? '—') . "\n"
                  . "IP: " . ($data['submitter_ip'] ?? '—') . "\n"
                  . "Content ref: " . ($data['content_ref'] ?? '—') . "\n\n"
                  . "Descrizione:\n" . ($data['description'] ?? '—') . "\n\n"
                  . "Dettaglio + azione: https://beta.pantedu.eu/admin/takedown/{$requestId}\n";
            $headers = "From: no-reply@pantedu.eu\r\n"
                     . "Reply-To: " . ($data['submitter_email'] ?? 'no-reply@pantedu.eu') . "\r\n"
                     . "Content-Type: text/plain; charset=UTF-8\r\n";
            @mail($to, $subject, $body, $headers);
        } catch (\Throwable $e) {
            error_log('[PublicTakedownController::notifyAbuseEmail] ' . $e->getMessage());
        }
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

        return <<<HTML
<h1>Segnalazione contenuti</h1>

<div class="fm-tk-info">
  <p>Questo form permette di segnalare contenuti presenti sull'applicativo
  <strong>pantedu.eu</strong> che ritieni violino i tuoi diritti
  (copyright, privacy GDPR, contenuti illegali, ecc.).</p>
  <p>Procedura conforme a <strong>D.Lgs. 70/2003 art. 16</strong> (Direttiva
  2000/31/CE — Notice &amp; Takedown). Vedi dettagli in
  <a href="/legal/takedown-procedure">procedura completa</a>.</p>
  <p>In alternativa puoi scrivere a <strong>abuse@pantedu.eu</strong>.</p>
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
        return <<<HTML
<div class="fm-tk-success">
<h1>Segnalazione ricevuta</h1>
<p>La tua segnalazione è stata ricevuta con identificativo <strong>#{$id}</strong>.</p>
<p>Procederemo a valutarla nei tempi SLA stabiliti
(vedi <a href="/legal/takedown-procedure">procedura</a>).</p>
<p>Se hai indicato un'email valida, ti contatteremo non appena disponibile
una risposta motivata.</p>
<p>Per urgenze contattare direttamente <strong>abuse@pantedu.eu</strong>.</p>
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
