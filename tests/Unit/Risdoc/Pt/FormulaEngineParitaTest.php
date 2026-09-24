<?php

declare(strict_types=1);

namespace Tests\Unit\Risdoc\Pt;

use App\Services\Risdoc\Pt\FormulaEngine;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Parità fra il motore di formule PHP (`FormulaEngine`, per il render server e
 * il PDF) e il suo specchio JavaScript (`js/modules/risdoc/pt/formula-engine.js`,
 * per l'editor). ADR-031 li descrive come mirror con «parità verificata su 15+
 * casi» e «unit test PHP (15) verdi»: nessuno dei due è mai esistito come file
 * in questo repository — si può verificare con `git log -- '**FormulaEngine*'`.
 * Un motore poteva divergere dall'altro senza che nessun controllo se ne
 * accorgesse: editor e PDF potevano mostrare risultati diversi sulla stessa
 * tabella. Revisione architetturale del 23/9/2026, rilievo A-20 (voce 125 del
 * registro del debito).
 *
 * Questa prova e la sua gemella JavaScript (`tests/js-unit/formula-engine-parita.test.js`)
 * NON si parlano: leggono gli stessi file in `tests/fixtures/formule/*.json` e
 * ciascuna interroga il proprio motore. La parità è nel fatto che entrambe
 * concordano con la STESSA lista di casi attesi, non in un confronto diretto
 * fra i due linguaggi in un solo processo.
 *
 * Per aggiungere un caso: una voce nuova in uno dei file di
 * `tests/fixtures/formule/`, con la griglia di input e il risultato atteso per
 * ogni cella che si vuole controllare (chiave "riga,colonna", 0-based). Vedi
 * ADR-031 § «Prove di parità».
 */
final class FormulaEngineParitaTest extends TestCase
{
    private const CARTELLA_FIXTURE = __DIR__ . '/../../../fixtures/formule';

    /** @return array<string, array{0: string, 1: array}> */
    public static function casi(): array
    {
        $out = [];
        $file = \glob(self::CARTELLA_FIXTURE . '/*.json');
        \sort($file);
        self::assertNotEmpty($file, 'nessun file di casi in ' . self::CARTELLA_FIXTURE);
        foreach ($file as $percorso) {
            $decoded = \json_decode((string)\file_get_contents($percorso), true, 512, \JSON_THROW_ON_ERROR);
            $baseNome = \basename($percorso, '.json');
            foreach ($decoded['casi'] as $caso) {
                $chiave = $baseNome . '::' . $caso['nome'];
                $out[$chiave] = [$baseNome, $caso];
            }
        }
        return $out;
    }

    #[Test]
    #[DataProvider('casi')]
    public function ilMotorePhpDaIlRisultatoAtteso(string $file, array $caso): void
    {
        $griglia = $caso['griglia'];
        $opzioni = $caso['opzioni'] ?? [];
        $atteso = $caso['atteso'];

        $risultato = FormulaEngine::computeTableValues($griglia, $opzioni);

        foreach ($atteso as $coordinate => $celleAttese) {
            [$r, $c] = \array_map('intval', \explode(',', $coordinate));
            $ottenuta = $risultato[$r][$c] ?? null;
            self::assertNotNull(
                $ottenuta,
                "{$file}::{$caso['nome']} — manca la cella ({$r},{$c}) nel risultato"
            );
            foreach (['display', 'value', 'error'] as $campo) {
                if (!\array_key_exists($campo, $celleAttese)) {
                    continue;
                }
                $msg = "{$file}::{$caso['nome']} — campo «{$campo}» della cella ({$r},{$c})";
                // "value" è sempre float nel motore PHP (dichiarato float|string
                // ovunque); nella fixture JSON un numero intero come "1024" decodifica
                // a int PHP. assertSame fallirebbe su 1024 (int) contro 1024.0 (float)
                // per un valore che sui due lati è lo STESSO numero: qui conta
                // l'uguaglianza numerica, non il tipo PHP interno.
                if ($campo === 'value' && \is_int($celleAttese[$campo])) {
                    $celleAttese[$campo] = (float)$celleAttese[$campo];
                }
                if ($campo === 'value' && \is_float($celleAttese[$campo])) {
                    self::assertIsFloat($ottenuta[$campo], $msg);
                    self::assertEqualsWithDelta($celleAttese[$campo], $ottenuta[$campo], 1e-9, $msg);
                } else {
                    self::assertSame($celleAttese[$campo], $ottenuta[$campo], $msg);
                }
            }
        }
    }
}
