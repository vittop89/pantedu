/**
 * Il tema all'avvio: la politica, in un posto solo.
 *
 * 23/9/2026 (revisione architetturale, A-50) — il tema si decideva in sei
 * punti con quattro politiche: views/partials/head.php e bootstrap-compat.js
 * (scelta salvata, poi preferenza del sistema), views/layout/app.php (senza
 * una scelta: scuro), le due pagine dell'importazione da PDF (scuro solo con
 * la scelta salvata), le pagine di fiducia di StandalonePageRenderer (scelta,
 * poi il body della pagina ospite, poi il sistema). Alla prima visita con il
 * sistema in chiaro, head.php diceva chiaro e app.php metteva `body.fm-dark`:
 * la pagina restava scura finché il JavaScript dell'app non la correggeva.
 *
 * La politica: la scelta salvata vince ("1" scuro, "0" chiaro); senza una
 * scelta, la preferenza del sistema; se il browser non la dice, scuro (il
 * tema di casa dal G21.4). La scelta la scrive il pulsante del tema
 * (setDarkMode in bootstrap-compat.js).
 *
 * Lo stesso file si usa in due modi:
 *   - in linea nel <head>, prima dei fogli di stile, dove lo mette
 *     App\Support\TemaIniziale::script(): la prima pittura ha già il colore
 *     giusto;
 *   - importato dal JavaScript (bootstrap-compat.js, le entry delle pagine a
 *     sé), dove ridà la stessa decisione e la offre come window.fmTemaScuro().
 *
 * Applica html[data-theme] (i token), html.fm-dark-pre (la pittura prima del
 * body) e body.fm-dark appena il body c'è. Script classico, niente import:
 * deve poter girare in linea. Prova: tests/js-unit/tema-all-avvio.test.js.
 */
(function () {
    "use strict";

    function temaScuro() {
        let salvata = null;
        try {
            salvata = window.localStorage.getItem("fm_dark_mode");
        } catch (_) {
            // Storage negato (navigazione privata, cookie bloccati): si decide senza.
        }
        if (salvata === "1") return true;
        if (salvata === "0") return false;
        const preferenza = window.matchMedia ? window.matchMedia("(prefers-color-scheme: dark)") : null;
        return preferenza ? preferenza.matches : true;
    }

    function alBody() {
        document.body.classList.toggle("fm-dark", temaScuro());
    }

    window.fmTemaScuro = temaScuro;

    const scuro = temaScuro();
    const radice = document.documentElement;
    radice.setAttribute("data-theme", scuro ? "dark" : "light");
    radice.classList.toggle("fm-dark-pre", scuro);
    if (document.body) alBody();
    else document.addEventListener("DOMContentLoaded", alBody, { once: true });
})();
