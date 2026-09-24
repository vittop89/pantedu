<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Il nonce della Content-Security-Policy della richiesta in corso.
 *
 * 23/9/2026 (revisione architetturale A-16, R-3 passo 4). Con la CSP rigorosa
 * (`script-src 'nonce-…' 'strict-dynamic'`, in produzione dal pannello del
 * WAF) il browser esegue uno `<script>` scritto nella pagina solo se porta il
 * nonce dell'intestazione. Fino a oggi il nonce lo generava
 * SecurityHeadersMiddleware DOPO aver reso la risposta, e con una regex lo
 * timbrava su ogni `<script>` del corpo: anche su quelli arrivati dal
 * contenuto (un docente, un file di una mappa, un corpo reso dal
 * sanificatore), che così diventavano eseguibili. La CSP non difendeva
 * proprio nel caso per cui c'è.
 *
 * Adesso il nonce si genera prima di rendere la pagina (Kernel::handle, e
 * comunque al primo uso), e le viste e le classi che scrivono uno script
 * dell'applicazione lo scrivono loro, subito dopo `<script`:
 *
 *     <script<?= \App\Support\Csp::attributo() ?> src="/js/fm-router.js" defer></script>
 *     '<script' . \App\Support\Csp::attributo() . ' type="module" src="…"></script>'
 *     $csp = \App\Support\Csp::attributo();  // e nel heredoc: <script{$csp}>
 *
 * Uno `<script>` che arriva dal contenuto non ha il nonce e il browser non lo
 * esegue. Le isole di dati (`type="application/json"`, `application/ld+json`,
 * `text/tikz`) non lo portano: il browser non le esegue comunque. La guardia
 * che ogni script scritto in views/ e app/ lo porti è
 * tests/Unit/Middleware/OgniScriptDellAppHaIlNonceTest.php.
 *
 * Il nonce si scrive anche con la CSP rilassata, che non lo chiede: la pagina
 * è la stessa in tutte e tre le modalità, e un cambio di modalità dal pannello
 * non rompe niente. Una risposta con il nonce non va messa in una cache
 * condivisa: il valore vale solo con l'intestazione della stessa risposta.
 */
final class Csp
{
    private static ?string $nonce = null;

    /** Il nonce della richiesta (base64 di 16 byte casuali): lo stesso per tutta la richiesta. */
    public static function nonce(): string
    {
        return self::$nonce ??= base64_encode(random_bytes(16));
    }

    /**
     * ` nonce="…"`, con lo spazio davanti: si scrive subito dopo `<script` (o
     * `<link rel="modulepreload"`). Il base64 ha solo lettere, cifre, `+`, `/`
     * e `=`: niente da scappare dentro le virgolette doppie.
     */
    public static function attributo(): string
    {
        return ' nonce="' . self::nonce() . '"';
    }

    /**
     * Un nonce nuovo: lo chiama il Kernel all'inizio di ogni richiesta, prima
     * della pipeline, e le prove fra una richiesta e l'altra. Con PHP-FPM
     * ogni richiesta parte già da zero; serve dove un processo ne serve più
     * d'una (le prove, un giorno un server che resta in memoria).
     */
    public static function nuovaRichiesta(): string
    {
        self::$nonce = null;
        return self::nonce();
    }
}
