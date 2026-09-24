<?php

declare(strict_types=1);

namespace App\Support;

use App\Core\Config;

/**
 * Quanto spesso si può avvisare della stessa unità guasta.
 *
 * Perché esiste: `OnFailure=` va messo su ogni lavoro automatico, altrimenti
 * un guasto non lo sa nessuno. Ma `pantedu-waf-export-blocked` gira **ogni
 * minuto**: se si rompe alle due di notte, senza una briglia manda
 * quattrocentoventi email prima di colazione. E la richiesta era esplicita —
 * gli errori si correggono, non si silenziano — quindi né mandare tutto né
 * spegnere l'avviso.
 *
 * La via di mezzo non è silenzio: è **contare**. Dentro la finestra gli
 * avvisi si sommano invece di partire; il primo che parte dopo dice quanti
 * ne sono stati soppressi. Un guasto che si ripete resta rumoroso quanto
 * basta a non essere ignorato, senza riempire la casella.
 *
 * Lo stato è un file per unità sotto `storage/logs/avvisi/`. Se non si riesce
 * a scriverlo, si manda comunque: meglio una email di troppo che un allarme
 * perso. È la stessa scelta di `pantedu-avviso@.service`, che se non riesce ad
 * avvisare resta in `failed` invece di uscire zero.
 */
final class BrigliaAvvisi
{
    /** Un avviso per unità ogni ora. */
    public const FINESTRA_SECONDI = 3600;

    public static function cartella(): string
    {
        $logs = (string)Config::get('app.paths.logs', \dirname(__DIR__, 2) . '/storage/logs');

        return $logs . '/avvisi';
    }

    /** Il file di stato di un'unità. Il nome viene ripulito: arriva da systemd. */
    public static function percorso(string $unita): string
    {
        $nome = preg_replace('/[^A-Za-z0-9._@-]/', '_', $unita) ?? 'ignota';

        return self::cartella() . '/' . $nome . '.json';
    }

    /**
     * Si può mandare l'avviso adesso?
     *
     * Chiamarla **consuma** la decisione: se risponde di sì segna l'invio, se
     * risponde di no incrementa il contatore dei soppressi. Va chiamata una
     * volta sola per avviso.
     *
     * @return array{manda: bool, soppressi: int}
     *         `soppressi` è quanti se ne sono accumulati da quando è partito
     *         l'ultimo (significativo solo quando `manda` è vero).
     */
    public static function consulta(string $unita, ?int $adesso = null): array
    {
        $adesso = $adesso ?? time();
        $file   = self::percorso($unita);

        $stato = ['ultimo' => 0, 'soppressi' => 0];
        if (is_file($file)) {
            $letto = json_decode((string)@file_get_contents($file), true);
            if (is_array($letto)) {
                $stato['ultimo']    = (int)($letto['ultimo'] ?? 0);
                $stato['soppressi'] = (int)($letto['soppressi'] ?? 0);
            }
            // Un file illeggibile non deve zittire l'avviso: si riparte da zero,
            // cioè si manda.
        }

        $dentroLaFinestra = ($adesso - $stato['ultimo']) < self::FINESTRA_SECONDI;

        if ($dentroLaFinestra) {
            $stato['soppressi']++;
            self::scrivi($file, $stato);

            return ['manda' => false, 'soppressi' => $stato['soppressi']];
        }

        $soppressi = $stato['soppressi'];
        self::scrivi($file, ['ultimo' => $adesso, 'soppressi' => 0]);

        return ['manda' => true, 'soppressi' => $soppressi];
    }

    /**
     * @param array{ultimo: int, soppressi: int} $stato
     */
    private static function scrivi(string $file, array $stato): void
    {
        $cartella = \dirname($file);
        if (!is_dir($cartella)) {
            @mkdir($cartella, 0o770, true);
        }
        // Se non si riesce a scrivere non si fa niente: la prossima chiamata
        // troverà lo stato vuoto e manderà. Fallire aperti, non chiusi.
        @file_put_contents($file, json_encode($stato, JSON_UNESCAPED_SLASHES), LOCK_EX);
    }
}
