<?php

declare(strict_types=1);

namespace Tests\Unit\Risdoc\Pt;

use App\Services\Risdoc\Pt\TernaBinding;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Parità fra `TernaBinding` (PHP, per il render server e la migrazione) e il
 * suo specchio JavaScript (`js/modules/risdoc/pt/terna-binding.js`, per
 * l'editor). ADR-030 li descrive come lo stesso comportamento in due
 * linguaggi, ma — come il motore di formule di ADR-031 (A-20, revisione
 * architetturale del 23/9/2026) — non esisteva nessuna prova che lo
 * verificasse davvero.
 *
 * Questa prova e la sua gemella JavaScript (`tests/js-unit/terna-binding-parita.test.js`)
 * leggono gli stessi file in `tests/fixtures/terna-binding/*.json` e ciascuna
 * interroga il proprio motore, senza parlarsi fra loro (come per le formule).
 *
 * Le due API non sono identiche a specchio — il PHP ha `applyAndStrip`
 * (applica + toglie il blocco ternaStore in un colpo), il JS separa
 * `applyTernaValues`/`stripTernaStore`/`splitTernaStore` — quindi qui si
 * chiama l'API PHP nativa (`applyAndStrip`, `extract`) e nella prova gemella
 * si compone la sequenza equivalente in JS. Quello che deve coincidere è il
 * RISULTATO, non la forma delle funzioni.
 *
 * Nota su «estrai» — cella-tabella-con-formula-esclusa e
 * cella-testo-non-marcata-non-estratta: quando nessun campo è stato estratto
 * per una chiave-terna, il delta è una mappa vuota. In PHP un array vuoto
 * `[]` e un oggetto vuoto sono la STESSA cosa (PHP non li distingue); quando
 * `json_encode` lo scrive, esce `[]`. La prova gemella JavaScript normalizza
 * un array vuoto a `{}` prima del confronto per questo — non nasconde una
 * differenza di VALORE (il delta è vuoto in entrambi), solo un'ambiguità di
 * forma JSON che PHP introduce quando non c'è nessun campo da salvare.
 * `TernaBinding::extract()` in produzione gira solo dallo strumento di
 * migrazione (`tools/migrate_terna_consolidate.php`), non dal salvataggio
 * interattivo (quello passa dal JS): l'ambiguità non tocca il percorso che
 * un docente usa ogni giorno.
 */
final class TernaBindingParitaTest extends TestCase
{
    private const CARTELLA_FIXTURE = __DIR__ . '/../../../fixtures/terna-binding';

    /** @return array<string, array{0: array}> */
    public static function casiApplica(): array
    {
        $decoded = \json_decode(
            (string)\file_get_contents(self::CARTELLA_FIXTURE . '/applica.json'),
            true,
            512,
            \JSON_THROW_ON_ERROR
        );
        $out = [];
        foreach ($decoded['casi'] as $caso) {
            $out[$caso['nome']] = [$caso];
        }
        return $out;
    }

    /** @return array<string, array{0: array}> */
    public static function casiEstrai(): array
    {
        $decoded = \json_decode(
            (string)\file_get_contents(self::CARTELLA_FIXTURE . '/estrai.json'),
            true,
            512,
            \JSON_THROW_ON_ERROR
        );
        $out = [];
        foreach ($decoded['casi'] as $caso) {
            $out[$caso['nome']] = [$caso];
        }
        return $out;
    }

    #[Test]
    #[DataProvider('casiApplica')]
    public function applicaEStrippaDaIlRisultatoAtteso(array $caso): void
    {
        $risultato = TernaBinding::applyAndStrip($caso['blocchi'], $caso['ternaKey']);
        self::assertEquals($caso['atteso'], $risultato, $caso['nome']);
    }

    #[Test]
    #[DataProvider('casiEstrai')]
    public function estraeIlDeltaAtteso(array $caso): void
    {
        [$clean, $store] = TernaBinding::extract($caso['blocchi'], $caso['ternaKey']);
        self::assertEquals($caso['atteso']['blocchi'], $clean, $caso['nome'] . ' — blocchi');
        self::assertEquals($caso['atteso']['store'], $store, $caso['nome'] . ' — store');
    }
}
