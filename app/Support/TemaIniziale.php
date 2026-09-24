<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Lo script del tema all'avvio, in linea, per il <head> delle pagine.
 *
 * 23/9/2026 (revisione architetturale, A-50) — la politica del tema (scelta
 * salvata, poi preferenza del sistema, poi scuro) sta in js/tema-iniziale.js,
 * un posto solo. Qui la si mette in linea, con il nonce della CSP, perché giri
 * prima della prima pittura: un file esterno la farebbe aspettare. Prima di
 * questa classe ogni impaginazione ne aveva una copia, e non erano d'accordo.
 */
final class TemaIniziale
{
    private const FILE = '/js/tema-iniziale.js';

    private static ?string $codice = null;

    /** `<script nonce="…">…</script>`, o niente se il file non si legge (e lo si dice nel registro). */
    public static function script(): string
    {
        $codice = self::codice();
        if ($codice === '') {
            return '';
        }
        return '<script' . Csp::attributo() . '>' . $codice . '</script>';
    }

    private static function codice(): string
    {
        if (self::$codice !== null) {
            return self::$codice;
        }
        $percorso = dirname(__DIR__, 2) . self::FILE;
        $letto = is_file($percorso) ? file_get_contents($percorso) : false;
        if ($letto === false) {
            // Il tema è un di più: la pagina parte lo stesso, chiara o scura
            // secondo il CSS, ma chi guarda il registro deve saperlo.
            error_log('[tema] ' . self::FILE . ' non leggibile: le pagine partono senza il tema iniziale');
            return self::$codice = '';
        }
        return self::$codice = $letto;
    }
}
