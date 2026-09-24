<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Services\SezioniDeiDocenti;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * ADR-041 — la regola delle sezioni dei docenti, senza database.
 */
final class SezioniDeiDocentiTest extends TestCase
{
    /** ADR-043 (15/9/2026): fino a qui gli anni erano sempre ammessi. */
    #[Test]
    public function un_anno_segue_la_modalita_e_l_incarico(): void
    {
        foreach (['1', '2', '5'] as $anno) {
            self::assertTrue(SezioniDeiDocenti::ammessaPer(SezioniDeiDocenti::TUTTI, $anno, false), "tutti, anno $anno");
            self::assertTrue(SezioniDeiDocenti::ammessaPer(SezioniDeiDocenti::SOLO_INCARICATI, $anno, true), "solo incaricati con incarico, anno $anno");
            self::assertFalse(SezioniDeiDocenti::ammessaPer(SezioniDeiDocenti::SOLO_INCARICATI, $anno, false), "solo incaricati senza incarico, anno $anno");
            self::assertTrue(SezioniDeiDocenti::ammessaPer(SezioniDeiDocenti::NESSUNO, $anno, false), "nessuno: gli anni per tutti, anno $anno");
            self::assertFalse(SezioniDeiDocenti::ammessaPer('inventata', $anno, true), "modalità sconosciuta, anno $anno");
        }
        self::assertTrue(SezioniDeiDocenti::ammessaPer(SezioniDeiDocenti::SOLO_INCARICATI, 'X', false), 'una sigla che non è una classe non si governa');
    }

    #[Test]
    public function una_sezione_segue_la_modalita_e_l_incarico(): void
    {
        self::assertTrue(SezioniDeiDocenti::ammessaPer(SezioniDeiDocenti::TUTTI, '2A', false));
        self::assertTrue(SezioniDeiDocenti::ammessaPer(SezioniDeiDocenti::SOLO_INCARICATI, '2A', true));
        self::assertFalse(SezioniDeiDocenti::ammessaPer(SezioniDeiDocenti::SOLO_INCARICATI, '2A', false));
        self::assertFalse(SezioniDeiDocenti::ammessaPer(SezioniDeiDocenti::NESSUNO, '2A', true), 'nemmeno con l\'incarico');
        self::assertFalse(SezioniDeiDocenti::ammessaPer('inventata', '3BS', true), 'una modalità sconosciuta non apre');
    }

    #[Test]
    public function la_modalita_di_partenza_e_solo_incaricati(): void
    {
        self::assertSame(SezioniDeiDocenti::SOLO_INCARICATI, SezioniDeiDocenti::PREDEFINITA);
    }
}
