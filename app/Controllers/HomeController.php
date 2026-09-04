<?php

namespace App\Controllers;

use App\Core\Config;
use App\Core\Request;
use App\Core\Response;

final class HomeController
{
    public function index(Request $req): Response
    {
        // Phase 16 — GET / va SEMPRE alla home (sidebar + contenuto).
        // Teacher/admin hanno già il banner sidebar con link "📊 Analytics" e
        // pulsanti dashboard dedicati. Non fare redirect auto alla dashboard
        // evita loop e permette ai bookmark utente di atterrare sulla home.
        // Utenti interessati usano /teacher/dashboard o /admin/dashboard.
        //
        // Hardening CSP (2026-06-20): la home era servita via Response::file
        // (legacy /index.php) → eseguita da nginx in un processo FPM separato
        // che NON passa dal Kernel → nessuna Content-Security-Policy applicata
        // (unica pagina del sito senza CSP). Ora renderizziamo lo stesso layout
        // app.php DENTRO la pipeline (ob_start + include → Response HTML), così
        // SecurityHeadersMiddleware applica la CSP (strict: nonce + stamping
        // sugli <script>) come per ogni altra pagina. app.php è auto-sufficiente
        // (si auto-bootstrappa, tutte le var di input opzionali con ??).
        $base   = Config::get('app.paths.legacy');
        $layout = $base . '/views/layout/app.php';
        if (is_file($layout)) {
            $pageTitle   = 'PANTEDU';
            // a11y: la home ha il contenuto nella sidebar (navigazione) e #fm-content
            // vuoto → senza h1 la pagina non ha un titolo programmatico (gerarchia
            // heading). h1 solo-screen-reader, nessun impatto visivo.
            $pageContent = '<h1 class="fm-sr-only">Pantedu — Home</h1>';
            ob_start();
            include $layout;
            return Response::html((string) ob_get_clean());
        }
        return Response::html('<h1>Pantedu</h1>');
    }
}
