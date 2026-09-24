<?php
declare(strict_types=1);

namespace Tests\Unit\Domain;

use App\Domain\ClassCode;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * La regola «l'anno copre le sue sezioni», nel posto in cui vive.
 * Tutto puro: nessun DB, nessuna sessione.
 */
final class ClassCodeTest extends TestCase
{
    /** @return iterable<string, array{0:?string,1:?string}> */
    public static function anni(): iterable
    {
        yield 'anno secco'          => ['3', '3'];
        yield 'sezione'             => ['3A', '3'];
        yield 'sezione minuscola'   => ['1b', '1'];
        yield 'sezione lunga'       => ['3BS', '3'];
        yield 'spazi intorno'       => [' 2A ', '2'];
        yield 'legacy con suffisso' => ['2s', '2'];
        yield 'vuoto'               => ['', null];
        yield 'null'                => [null, null];
        yield 'non una classe'      => ['ABC', null];
        yield 'zero non e\' un anno' => ['0A', null];
    }

    #[Test]
    #[DataProvider('anni')]
    public function anno_di_corso(?string $classe, ?string $atteso): void
    {
        $this->assertSame($atteso, ClassCode::anno($classe));
    }

    #[Test]
    public function la_sezione_si_distingue_dall_anno(): void
    {
        $this->assertTrue(ClassCode::isSezione('3A'));
        $this->assertTrue(ClassCode::isSezione('1b'));
        $this->assertFalse(ClassCode::isSezione('3'));
        $this->assertFalse(ClassCode::isSezione('2s'), 'il suffisso legacy non e\' una sezione');
        $this->assertFalse(ClassCode::isSezione(''));
    }

    /** @return iterable<string, array{0:string,1:string,2:bool}> */
    public static function coperture(): iterable
    {
        yield 'anno copre la sezione'            => ['3', '3A', true];
        yield 'anno copre se stesso'             => ['3', '3', true];
        yield 'sezione copre se stessa'          => ['3A', '3A', true];
        yield 'sezione, maiuscole diverse'       => ['3a', '3A', true];
        yield 'sezione NON copre l\'anno'        => ['3A', '3', false];
        yield 'sezione NON copre altra sezione'  => ['3A', '3B', false];
        yield 'anno NON copre altro anno'        => ['3', '4A', false];
        yield 'etichetta vuota'                  => ['', '3A', false];
        yield 'classe vuota'                     => ['3', '', false];
    }

    #[Test]
    #[DataProvider('coperture')]
    public function l_etichetta_raggiunge_chi_guarda(string $etichetta, string $classe, bool $atteso): void
    {
        $this->assertSame($atteso, ClassCode::covers($etichetta, $classe));
    }

    #[Test]
    public function da_una_sezione_si_vedono_sezione_e_anno(): void
    {
        $this->assertSame(['3A', '3'], ClassCode::covering('3A'));
        $this->assertSame(['1b', '1'], ClassCode::covering('1b'), 'il caso della sezione resta quello dato: decide la collation');
    }

    #[Test]
    public function da_un_anno_si_vede_solo_l_anno(): void
    {
        $this->assertSame(['3'], ClassCode::covering('3'));
        $this->assertSame(['2'], ClassCode::covering('2s'), 'un segnalibro legacy non diventa la sezione S');
    }

    #[Test]
    public function senza_classe_nessun_insieme(): void
    {
        $this->assertSame([], ClassCode::covering(''));
        $this->assertSame([], ClassCode::covering(null));
        $this->assertSame(['XYZ'], ClassCode::covering('XYZ'), 'un codice non riconosciuto resta se stesso: il confronto esatto continua a valere');
    }
}
