<?php

declare(strict_types=1);

namespace Tests\Unit\Support;

use App\Support\RuoliNelPannelloUtenti;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/** I ruoli del pannello «Utenti», per scenario e per chi guarda (15/9/2026). */
final class RuoliNelPannelloUtentiTest extends TestCase
{
    #[Test]
    public function negli_scenari_senza_account_studente_lo_studente_non_si_cerca_e_non_si_assegna(): void
    {
        self::assertSame(['teacher', 'administrator'], RuoliNelPannelloUtenti::assegnabili(false));
        foreach ([true, false] as $super) {
            self::assertArrayNotHasKey('student', RuoliNelPannelloUtenti::filtro(false, $super, false));
        }
        self::assertArrayNotHasKey('institute_admin', RuoliNelPannelloUtenti::filtro(false, false, false), 'fuori dallo scenario 3 non esiste');
    }

    #[Test]
    public function nello_scenario_3_lo_studente_c_e_ma_non_per_il_super_amministratore(): void
    {
        self::assertContains('student', RuoliNelPannelloUtenti::assegnabili(true));
        self::assertArrayHasKey('student', RuoliNelPannelloUtenti::filtro(true, false, true));
        self::assertArrayNotHasKey('student', RuoliNelPannelloUtenti::filtro(true, true, true), 'il super-amministratore non vede gli studenti');
        self::assertTrue(RuoliNelPannelloUtenti::studentiNascosti(true, true), 'e la pagina lo dice');
        self::assertFalse(RuoliNelPannelloUtenti::studentiNascosti(true, false));
        self::assertFalse(RuoliNelPannelloUtenti::studentiNascosti(false, true), 'senza account studente non c\'è niente da dire');
    }

    #[Test]
    public function l_amministratore_di_istituto_si_cerca_nello_scenario_3_ma_non_si_assegna_mai(): void
    {
        self::assertArrayHasKey('institute_admin', RuoliNelPannelloUtenti::filtro(true, true, true));
        foreach ([true, false] as $studenti) {
            self::assertNotContains('institute_admin', RuoliNelPannelloUtenti::assegnabili($studenti));
        }
    }
}
