<?php

declare(strict_types=1);

namespace Tests\Unit\Controllers;

use App\Controllers\CurriculumController;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Il catalogo della barra pubblica senza doppioni (15/9/2026).
 *
 * Il docente che pubblica in rete ha le stesse voci in più istituti: nella barra
 * pubblica l'istituto non si sceglie, e i doppioni si tolgono. Ma una classe è
 * doppia solo con lo stesso codice e lo stesso indirizzo: in produzione il
 * docente ha «1A» in Musicale e in Scientifico, e contando il solo codice la
 * seconda spariva.
 */
final class CurriculumPubblicoSenzaDoppioniTest extends TestCase
{
    /** @return array<string, mixed> */
    private static function voce(string $codice, ?string $indirizzo = null, bool $attiva = true, int $istituto = 106): array
    {
        return ['code' => $codice, 'label' => $codice, 'indirizzo' => $indirizzo, 'active' => $attiva, 'institute_id' => $istituto];
    }

    #[Test]
    public function classi_con_lo_stesso_codice_in_indirizzi_diversi_restano_tutte(): void
    {
        $catalogo = CurriculumController::senzaDoppioni(['classi' => [
            self::voce('1A', 'MUS', true, 108),
            self::voce('1A', 'SCI'),
            self::voce('2A', 'SCI'),
        ]]);

        $this->assertSame(['1A|MUS', '1A|SCI', '2A|SCI'], array_map(
            static fn(array $c) => $c['code'] . '|' . $c['indirizzo'],
            $catalogo['classi'],
        ));
    }

    #[Test]
    public function la_stessa_classe_in_due_istituti_resta_una(): void
    {
        $catalogo = CurriculumController::senzaDoppioni([
            'classi'    => [self::voce('2A', 'SCI', true, 106), self::voce('2A', 'SCI', true, 108)],
            'indirizzi' => [self::voce('SCI', null, true, 106), self::voce('SCI', null, true, 108)],
            'materie'   => [self::voce('MAT', null, true, 106), self::voce('MAT', null, true, 108)],
        ]);

        $this->assertCount(1, $catalogo['classi']);
        $this->assertCount(1, $catalogo['indirizzi'], 'indirizzi e materie per codice, come prima');
        $this->assertCount(1, $catalogo['materie']);
    }

    #[Test]
    public function le_voci_non_attive_non_si_mostrano(): void
    {
        $catalogo = CurriculumController::senzaDoppioni(['classi' => [self::voce('3A', 'SCI', false), self::voce('4A', 'SCI')]]);

        $this->assertSame(['4A'], array_column($catalogo['classi'], 'code'));
    }
}
