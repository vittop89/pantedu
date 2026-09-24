<?php

declare(strict_types=1);

namespace Tests\Unit\Dev;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * L'ordine delle chiavi di docs/PHASES.md è un ordine vero (24/9/2026).
 *
 * Il confronto di prima dava il ciclo 24.9 < 24.10 < 24.11b < 24.9, e con un
 * ciclo il risultato dipende dall'algoritmo di ordinamento: PHP 8.3 e 8.4
 * generavano il file in modo diverso, e il controllo dei documenti generati
 * andava in rosso in CI. Qui: l'ordine atteso, e la transitività su tutte le
 * terne di un insieme che contiene i casi misti (numero, numero con lettere,
 * solo lettere).
 */
final class OrdineDellePhaseTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once __DIR__ . '/../../../tools/dev/phases_ordine.php';
    }

    private const CHIAVI = ['24.9', '24.10', '24.11', '24.11b', '24.12', '25.C', '25.C2', '25.C12', '25.D', '3', '10', 'R', '24.b'];

    #[Test]
    public function l_ordine_mette_i_numeri_come_numeri_e_poi_il_resto(): void
    {
        $chiavi = ['24.11b', '24.12', '24.9', '24.10', '24.11'];
        usort($chiavi, 'confrontaPhase');

        self::assertSame(['24.9', '24.10', '24.11', '24.11b', '24.12'], $chiavi);
    }

    #[Test]
    public function il_confronto_e_transitivo_e_antisimmetrico(): void
    {
        $k = self::CHIAVI;
        foreach ($k as $a) {
            foreach ($k as $b) {
                self::assertSame(-(confrontaPhase($b, $a) <=> 0), confrontaPhase($a, $b) <=> 0, "$a e $b");
                foreach ($k as $c) {
                    if (confrontaPhase($a, $b) < 0 && confrontaPhase($b, $c) < 0) {
                        self::assertLessThan(0, confrontaPhase($a, $c), "$a < $b < $c ma non $a < $c");
                    }
                }
            }
        }
    }

    #[Test]
    public function il_risultato_non_dipende_dall_ordine_di_partenza(): void
    {
        $atteso = self::CHIAVI;
        usort($atteso, 'confrontaPhase');
        for ($giro = 0; $giro < 20; $giro++) {
            $mescolate = self::CHIAVI;
            mt_srand($giro);
            shuffle($mescolate);
            usort($mescolate, 'confrontaPhase');
            self::assertSame($atteso, $mescolate);
        }
    }
}
