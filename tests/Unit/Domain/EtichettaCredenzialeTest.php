<?php

declare(strict_types=1);

namespace Tests\Unit\Domain;

use App\Domain\EtichettaCredenziale;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * L'etichetta di una credenziale di classe composta dal server (ADR-044):
 * {CLASSE}_{INDIRIZZO}_{MATERIE}[_{AGGIUNTA}], materie in ordine alfabetico.
 * Pura, senza database.
 */
final class EtichettaCredenzialeTest extends TestCase
{
    /** @return iterable<string,array{0:?string,1:?string,2:list<string>,3:?string,4:string}> */
    public static function casi(): iterable
    {
        yield 'anno, indirizzo, tre materie in ordine alfabetico' => ['3', 'SCI', ['MAT', 'FIS', 'GEO'], null, '3_SCI_FIS-GEO-MAT'];
        yield 'sezione in minuscolo, aggiunta in maiuscolo'       => ['3b', 'sci', ['MAT'], 'pomeriggio', '3B_SCI_MAT_POMERIGGIO'];
        yield 'aggiunta con il trattino'                          => ['3', 'SCI', ['MAT', 'FIS', 'GEO'], 'gruppo-b', '3_SCI_FIS-GEO-MAT_GRUPPO-B'];
        yield 'tutte le classi'                                   => [null, null, ['MAT'], null, 'TUTTE_MAT'];
        yield 'tutte le classi, senza materie'                    => [null, null, [], null, 'TUTTE'];
        yield 'tutte le classi, con aggiunta'                     => [null, null, [], 'x1', 'TUTTE_X1'];
        yield 'solo indirizzo'                                    => [null, 'SCI', ['MAT', 'FIS'], null, 'SCI_FIS-MAT'];
        yield 'senza materie'                                     => ['3', 'SCI', [], null, '3_SCI'];
        yield 'materie ripetute, in forme diverse'                => ['3', 'SCI', ['mat', 'MAT', ' Mat ', 'FIS', ''], null, '3_SCI_FIS-MAT'];
        yield 'aggiunta vuota come nessuna'                       => ['3', 'SCI', ['MAT'], '  ', '3_SCI_MAT'];
    }

    /** @param list<string> $materie */
    #[Test]
    #[DataProvider('casi')]
    public function compone_l_etichetta(?string $classe, ?string $indirizzo, array $materie, ?string $aggiunta, string $attesa): void
    {
        self::assertSame($attesa, EtichettaCredenziale::componi($classe, $indirizzo, $materie, $aggiunta));
    }

    #[Test]
    public function l_ordine_delle_materie_non_dipende_da_come_arrivano(): void
    {
        $a = EtichettaCredenziale::componi('3', 'SCI', ['MAT', 'FIS', 'GEO'], null);
        $b = EtichettaCredenziale::componi('3', 'SCI', ['GEO', 'MAT', 'FIS'], null);
        self::assertSame($a, $b, 'stesse materie, stessa etichetta: il controllo dei doppioni la vede');
        self::assertSame(['FIS', 'GEO', 'MAT'], EtichettaCredenziale::materie(['mat', 'geo', 'fis', 'MAT']));
    }

    #[Test]
    public function nessun_pezzo_contiene_il_separatore_delle_parti(): void
    {
        // L'aggiunta non ammette «_»: le parti si separano senza ambiguità.
        self::assertFalse(EtichettaCredenziale::aggiuntaValida('a_b'));
        self::assertTrue(EtichettaCredenziale::aggiuntaValida('a-b'));
        self::assertSame(12, EtichettaCredenziale::AGGIUNTA_MAX);
    }
}
