<?php

declare(strict_types=1);

namespace App\Support;

use App\Core\Response;
use App\Core\View;

/**
 * Una pagina che ha un gettone nel proprio indirizzo, e che quindi non deve
 * mandarlo come Referer a nessuno (24/9/2026).
 *
 * IL DIFETTO
 *   I collegamenti delle email portano il gettone nella query string:
 *   `/me/confirm-deletion?token=…`, `/me/account/email/conferma?token=…`,
 *   `/password/reset?token=…`. Con la politica di sempre
 *   (`strict-origin-when-cross-origin`) il browser manda l'indirizzo intero,
 *   gettone compreso, come Referer a ogni richiesta verso lo stesso sito che
 *   parte dalla pagina: i fogli di stile, gli script, il POST del pulsante.
 *   Se quel POST non passava il CSRF, CsrfMiddleware scriveva il Referer nel
 *   registro delle anomalie, e con lui il gettone ancora valido (misurato).
 *   `rel="noreferrer"` sul modulo non basta: Chromium lo ignora sui moduli
 *   (misurato), e i fogli di stile e gli script non lo guardano comunque.
 *
 * LE DUE METÀ, E PERCHÉ TUTTE E DUE
 *   - l'intestazione `Referrer-Policy: no-referrer`, che
 *     SecurityHeadersMiddleware lascia com'è (sostituisce con quella di sempre
 *     ogni altro valore, non questo);
 *   - `<meta name="referrer" content="no-referrer">` in testa alla pagina,
 *     prima di fogli di stile e script (layout/shell, `$senzaReferer`).
 *   Il meta serve perché in produzione l'intestazione non arriva da sola: il
 *   vhost di nginx sull'host (i due file in infra/nginx/) aggiunge la sua
 *   `Referrer-Policy: strict-origin-when-cross-origin` dopo quella
 *   dell'applicazione, e con due intestazioni il browser applica l'ultima.
 *   Misurato con Chromium il 24/9/2026: `no-referrer` seguita da
 *   `strict-origin-when-cross-origin` manda di nuovo il gettone come Referer;
 *   con il meta in testa, no.
 *
 * Le pagine che la usano: la pagina del collegamento della cancellazione
 * (PagineDellaCancellazione), quella del cambio email (AccountController) e
 * quella del ripristino della password (PasswordResetController). Prove:
 * tests/Unit/Middleware/SecurityHeadersReferrerPolicyTest.php e
 * tests/Integration/Security/ReferrerPolicyDellePagineConGettoneTest.php.
 */
final class PaginaConGettone
{
    public const POLITICA = 'no-referrer';

    /**
     * La pagina nel layout del sito (`layout/shell`), con il meta e
     * l'intestazione.
     *
     * @param array<string, mixed> $layout le variabili di layout/shell
     *                                     (title, body, modal, …)
     */
    public static function html(array $layout, int $stato = 200): Response
    {
        $risposta = Response::html(
            View::default()->render('layout/shell', ['senzaReferer' => true] + $layout),
            $stato,
        );
        $risposta->headers['Referrer-Policy'] = self::POLITICA;
        return $risposta;
    }
}
