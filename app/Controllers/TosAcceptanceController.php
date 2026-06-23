<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Request;
use App\Core\Response;
use App\Services\Gdpr\TosAcceptanceService;

/**
 * Phase 25.P — Controller per click-acceptance ToS+AUP.
 *
 * Routes (TODO da aggiungere in routes/web.php quando si attiva Scenario B/C):
 *   GET  /tos-acceptance         → form
 *   POST /tos-acceptance         → submit (richiede CSRF)
 *
 * Si attiva insieme a TosAcceptanceMiddleware con flag env TOS_ENFORCE=true.
 */
final class TosAcceptanceController
{
    private TosAcceptanceService $service;

    public function __construct(?TosAcceptanceService $service = null)
    {
        $this->service = $service ?? new TosAcceptanceService();
    }

    /** GET /tos-acceptance — mostra form. */
    public function show(): Response
    {
        if (! Auth::check()) {
            return Response::redirect('/login');
        }
        $userId = (int) Auth::userId();
        if ($this->service->hasAccepted($userId)) {
            return Response::redirect('/');
        }

        $tosV = $this->service->getCurrentTosVersion();
        $aupV = $this->service->getCurrentAupVersion();
        $redirect = $_GET['redirect'] ?? '/';
        $redirectSafe = htmlspecialchars((string) $redirect, ENT_QUOTES, 'UTF-8');
        $csrf = $_SESSION['_csrf'] ?? bin2hex(random_bytes(16));
        $_SESSION['_csrf'] = $csrf;

        return Response::html(<<<HTML
<!DOCTYPE html>
<html lang="it">
<head>
<meta charset="UTF-8">
<title>Accettazione Termini di Servizio — pantedu</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<style>
  body { font-family: system-ui, sans-serif; max-width: 800px; margin: 2em auto; padding: 0 1em; line-height: 1.5; }
  h1 { color: #2c3e50; }
  .docs { background: #f8f9fa; padding: 1.5em; border-radius: 8px; margin: 1.5em 0; }
  .docs a { display: block; padding: 0.5em 0; }
  .info { background: #cff4fc; color: #055160; padding: 1em; border-radius: 4px; margin-bottom: 1em; }
  form { background: #fff; padding: 1.5em; border: 1px solid #ddd; border-radius: 8px; }
  label { display: block; margin: 0.7em 0; }
  button { background: #2c3e50; color: white; padding: 0.8em 1.5em; border: none; border-radius: 4px; cursor: pointer; font-size: 1em; }
  button:hover { background: #34495e; }
  button:disabled { background: #ccc; cursor: not-allowed; }
</style>
</head>
<body>
<h1>Accettazione Termini di Servizio</h1>

<div class="info">
Per continuare a utilizzare pantedu come docente devi accettare i
seguenti documenti contrattuali (click-accept obbligatorio).
</div>

<div class="docs">
<strong>Versioni correnti:</strong>
<ul>
<li>Terms of Service (ToS) — versione <strong>{$tosV}</strong></li>
<li>Acceptable Use Policy (AUP) — versione <strong>{$aupV}</strong></li>
</ul>
<a href="/legal/tos_docente" target="_blank">📄 Leggi i Terms of Service (PDF)</a>
<a href="/legal/aup" target="_blank">📄 Leggi l'Acceptable Use Policy (PDF)</a>
</div>

<form method="POST" action="/tos-acceptance" id="tos-form">
<input type="hidden" name="_csrf" value="{$csrf}">
<input type="hidden" name="redirect" value="{$redirectSafe}">
<input type="hidden" name="tos_version" value="{$tosV}">
<input type="hidden" name="aup_version" value="{$aupV}">

<label>
<input type="checkbox" name="read_tos" required>
Ho letto e compreso i <strong>Terms of Service</strong> versione {$tosV}
</label>

<label>
<input type="checkbox" name="read_aup" required>
Ho letto e compreso l'<strong>Acceptable Use Policy</strong> versione {$aupV}
</label>

<label>
<input type="checkbox" name="accept_responsibility" required>
Mi assumo la <strong>piena responsabilità civile, penale e disciplinare</strong>
per i contenuti che caricherò
</label>

<label>
<input type="checkbox" name="accept_safe_harbor" required>
Sollevo l'operatore tecnico da responsabilità per i contenuti caricati,
riconoscendo che l'envelope encryption per-docente gli impedisce di
accedervi
</label>

<label>
<input type="checkbox" name="accept_takedown" required>
Mi impegno a cooperare in buona fede su procedure di Notice &amp;
Takedown e sull'obbligo di segnalazione ex DPR 62/2013 art. 13
</label>

<p><button type="submit">Accetto e continuo</button></p>

</form>

</body>
</html>
HTML, 200);
    }

    /** POST /tos-acceptance — submit accettazione. */
    public function submit(Request $req): Response
    {
        if (! Auth::check()) {
            return Response::redirect('/login');
        }
        $userId = (int) Auth::userId();
        $ip = $req->server['REMOTE_ADDR'] ?? '';
        $ua = $req->server['HTTP_USER_AGENT'] ?? null;

        $this->service->recordAcceptance($userId, $ip, $ua);

        $redirect = $req->post('redirect', '/');
        if (! is_string($redirect) || ! str_starts_with($redirect, '/')) {
            $redirect = '/';
        }

        return Response::redirect($redirect);
    }
}
