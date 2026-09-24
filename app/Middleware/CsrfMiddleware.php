<?php

namespace App\Middleware;

use App\Core\Csrf;
use App\Core\Request;
use App\Core\Response;
use App\Core\View;
use App\Services\Audit\PercorsoSenzaGettoni;
use App\Support\Anomalia;

final class CsrfMiddleware
{
    public function handle(Request $req, callable $next): Response
    {
        if (\in_array($req->method, ['POST','PUT','PATCH','DELETE'], true)) {
            $token = $req->post['_csrf'] ?? $req->headers['x-csrf-token'] ?? null;
            if (!Csrf::verify($token)) {
                $this->annota($req, $token);
                // Phase 16 — use 403 Forbidden instead of 419 (Laravel-only).
                // Apache su Windows rewrites non-standard codes (419 → 500),
                // rompendo il client auto-retry. 403 è universale.
                if ($req->wantsJson()) {
                    return Response::json(['error' => 'csrf_invalid'], 403);
                }
                $view = View::default();
                $body = $view->render('errors/generic', [
                    'code' => 403,
                    'title' => 'CSRF token invalid',
                    'message' => 'La sessione è scaduta o la richiesta non è stata autenticata. Ricarica la pagina e riprova.',
                    'icon' => '🔐',
                    'color' => 'var(--fm-c-warning)',
                ]);
                return Response::html($view->render('layout/shell', [
                    'title' => '403 — CSRF invalid',
                    'body'  => $body,
                ]), 403);
            }
        }
        return $next($req);
    }

    /**
     * Dice **perché** il gettone non è passato, e lo scrive dove qualcuno
     * guarda.
     *
     * Prima qui non si registrava niente, e le tre situazioni finivano tutte
     * nello stesso 403 indistinguibile. È così che quattro funzioni hanno
     * potuto rispondere 403 per mesi senza che nessuno lo sapesse: il client
     * leggeva `meta[name="csrf-token"]`, che nessuna vista emette, e mandava
     * una stringa vuota.
     *
     * Le tre situazioni non si somigliano affatto:
     *
     *   - **assente**: nessun `_csrf` nel corpo né intestazione. Le nostre
     *     pagine il gettone lo mandano sempre; se non c'è, o è un client
     *     nostro rotto o è una richiesta che non viene da noi.
     *   - **vuoto**: l'intestazione c'è ma è la stringa vuota. Questo non lo
     *     fa nessun attaccante — manderebbe qualcosa di plausibile. È un
     *     nostro difetto, sempre.
     *   - **sbagliato**: un gettone c'era e non corrisponde. Di solito una
     *     sessione scaduta; a volte qualcos'altro.
     *
     * Nei dettagli finiscono rotta e metodo, mai il gettone: un gettone in
     * chiaro nei registri è una credenziale in chiaro nei registri.
     *
     * Né il gettone CSRF né quelli dei collegamenti (24/9/2026). Il Referer
     * si scriveva intero, e la pagina da cui parte un POST può avere un
     * gettone nell'indirizzo: da `/me/confirm-deletion?token=…` un pulsante
     * premuto a sessione scaduta scriveva nel registro delle anomalie il
     * gettone ancora valido della cancellazione. Adesso il Referer passa da
     * PercorsoSenzaGettoni::perIlRegistro, come nel registro degli accessi:
     * senza query string né frammento, e con i gettoni del percorso mascherati;
     * la rotta con i gettoni del percorso mascherati.
     */
    private function annota(Request $req, ?string $token): void
    {
        [$codice, $cosa] = match (true) {
            $token === null => [
                'csrf_gettone_assente',
                'Richiesta che scrive senza nessun gettone CSRF, né nel corpo né '
                . 'come intestazione.',
            ],
            trim($token) === '' => [
                'csrf_gettone_vuoto',
                'Il client ha mandato un gettone CSRF vuoto: non è un tentativo di '
                . 'attacco, è codice nostro che non trova il gettone. Il canonico '
                . 'lato JS è fetchCsrf() in js/modules/core/dom-utils.js.',
            ],
            default => [
                'csrf_gettone_sbagliato',
                'Gettone CSRF presente ma non valido: di norma una sessione scaduta.',
            ],
        };

        // Anche la rotta: alcune hanno il gettone nel percorso
        // (PercorsoSenzaGettoni::PREFISSI_CON_GETTONE).
        $rotta = PercorsoSenzaGettoni::mascherati($req->path);
        Anomalia::registra($codice, $cosa, [
            'metodo'  => $req->method,
            'rotta'   => $rotta,
            'da'      => PercorsoSenzaGettoni::perIlRegistro((string)($req->headers['referer'] ?? '')),
            'origine' => $codice === 'csrf_gettone_vuoto' ? 'nostro codice' : 'da stabilire',
        ], $codice . ':' . $rotta);
    }
}
