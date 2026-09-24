<?php

declare(strict_types=1);

namespace App\Support;

use App\Core\Config;

/**
 * Dove stanno i dati d'istanza, e dove sta il codice.
 *
 * Dall'8 settembre 2026 l'applicazione gira in un container
 * (`docker/Dockerfile`: «L'immagine è immutabile: tutto ciò che si scrive sta
 * sotto PANTEDU_DATA_PATH»). La radice del repository, dentro il container, è
 * l'immagine: quello che ci si scrive finisce nello strato scrivibile e
 * sparisce al rilascio successivo, senza un errore e senza una traccia.
 *
 * Quindi:
 *
 *  - **si scrive** solo sotto {@see self::base()}, cioè `PANTEDU_DATA_PATH`
 *    (in sviluppo, se la variabile è vuota, è di nuovo la radice del
 *    repository: {@see \App\Core\Config::cartellaDati()});
 *  - **si legge** dai dati e, per gli alberi che hanno una base versionata
 *    che l'immagine porta con sé (`storage/templates/**`,
 *    `storage/data/latex_shortcuts_default.json`), anche dal codice, con la
 *    cascata di {@see self::inCascata()}: prima i dati, poi l'immagine.
 *
 * La guardia `tools/ci/no-scritture-nel-repository.mjs` riconosce come forma
 * corretta sia `Config::get('app.paths.data_base', …)` sia le chiamate a
 * questa classe.
 */
final class PercorsiDati
{
    /**
     * La radice dei dati d'istanza, senza la barra finale.
     *
     * @param string      $radiceDelRepository ripiego quando la configurazione
     *                                         non c'è (di solito
     *                                         `dirname(__DIR__, N)` di chi chiama)
     * @param string|null $esplicita           radice passata da chi chiama, che
     *                                         vince sulla configurazione: serve
     *                                         alle prove e agli strumenti da
     *                                         riga di comando
     */
    public static function base(string $radiceDelRepository, ?string $esplicita = null): string
    {
        $base = $esplicita ?? (string) Config::get('app.paths.data_base', $radiceDelRepository);
        if (trim($base) === '') {
            $base = $radiceDelRepository;
        }

        return rtrim(str_replace('\\', '/', $base), '/');
    }

    /**
     * Il percorso da leggere per `$relativo`: quello nei dati d'istanza se
     * esiste, altrimenti quello nel codice (l'immagine) se esiste, altrimenti
     * `null`.
     *
     * Serve agli alberi misti: una base versionata che viaggia con l'immagine
     * più gli scostamenti dell'istanza, che stanno nei dati e vincono.
     *
     * @param string      $relativo            percorso relativo alla radice
     *                                         (es. `storage/templates/verifiche/_default/…`)
     * @param string      $radiceDelRepository radice del codice
     * @param string|null $esplicita           vedi {@see self::base()}
     */
    public static function inCascata(
        string $relativo,
        string $radiceDelRepository,
        ?string $esplicita = null,
    ): ?string {
        foreach (self::radiciInCascata($relativo, $radiceDelRepository, $esplicita) as $percorso) {
            if (file_exists($percorso)) {
                return $percorso;
            }
        }

        return null;
    }

    /**
     * I due percorsi della cascata, nell'ordine in cui si guardano: prima i
     * dati d'istanza, poi il codice. Quando le due radici coincidono (sviluppo
     * senza `PANTEDU_DATA_PATH`) ne torna uno solo, così chi cicla non
     * esamina due volte la stessa cartella.
     *
     * @return list<string>
     */
    public static function radiciInCascata(
        string $relativo,
        string $radiceDelRepository,
        ?string $esplicita = null,
    ): array {
        $rel = ltrim(str_replace('\\', '/', $relativo), '/');
        $dati = self::base($radiceDelRepository, $esplicita);
        $codice = rtrim(str_replace('\\', '/', $radiceDelRepository), '/');

        $percorsi = [$dati . '/' . $rel];
        if ($codice !== $dati) {
            $percorsi[] = $codice . '/' . $rel;
        }

        return $percorsi;
    }
}
