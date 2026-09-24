<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Services\AvvisoIncarichiTolti;
use App\Services\SezioniDeiDocenti;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Il testo dell'email al docente a cui si tolgono incarichi (15/9/2026).
 *
 * Deve dire il vero nei casi che cambiano le conseguenze: la modalità «tutti»
 * non toglie le sezioni dai menù; senza materiali non si parla di spostarli; le
 * credenziali di classe continuano a vedere; gli studenti con account no.
 */
final class AvvisoIncarichiToltiMessaggioTest extends TestCase
{
    /** @return array{code:string,tipi:array<string,int>,verifiche:int,posti:int,bersagli:int,credenziali:int,studenti:int} */
    private static function riga(string $code, array $tipi = [], int $verifiche = 0, int $posti = 0, int $credenziali = 0, int $studenti = 0, int $bersagli = 0): array
    {
        return compact('code', 'tipi', 'verifiche', 'posti', 'bersagli', 'credenziali', 'studenti');
    }

    #[Test]
    public function con_materiali_elenca_per_sezione_e_dice_come_spostarli(): void
    {
        [$oggetto, $testo] = AvvisoIncarichiTolti::messaggio('Maria', 'Liceo Zz', 'SCI', [
            self::riga('2A', ['mappa' => 3, 'esercizio' => 1], 2, 4, 2),
            self::riga('2B'),
        ], SezioniDeiDocenti::SOLO_INCARICATI, 'https://esempio.test');

        self::assertSame('Pantedu — incarichi tolti sulle classi 2A, 2B', $oggetto);
        self::assertStringContainsString("ti ha tolto l'incarico sulle classi 2A, 2B dell'indirizzo SCI (Liceo Zz)", $testo);
        self::assertStringContainsString('Le classi non compaiono più nei tuoi menù', $testo);
        self::assertStringContainsString('- 2A: 3 mappe · 1 esercizio · 2 verifiche generate (4 posti in più)', $testo);
        self::assertStringContainsString('- 2B: nessun materiale', $testo);
        self::assertStringContainsString('https://esempio.test/area-docente/sposta-di-classe', $testo);
        self::assertStringContainsString('Le tue 2 credenziali di classe', $testo);
        self::assertStringNotContainsString('studenti con account', $testo);
    }

    #[Test]
    public function senza_materiali_non_parla_di_spostarli(): void
    {
        [, $testo] = AvvisoIncarichiTolti::messaggio('Maria', '', 'SCI', [self::riga('3A')], SezioniDeiDocenti::SOLO_INCARICATI, 'https://esempio.test');

        self::assertStringContainsString('Sulla classe non hai materiali pubblicati.', $testo);
        self::assertStringNotContainsString('sposta-di-classe', $testo);
        self::assertStringNotContainsString('continua a vedere', $testo, 'nessuna credenziale: non se ne parla');
    }

    #[Test]
    public function con_tutti_le_sezioni_restano_e_gli_studenti_con_account_si_nominano(): void
    {
        [$oggetto, $testo] = AvvisoIncarichiTolti::messaggio('Maria', '', 'SCI', [self::riga('2A', [], 0, 0, 0, 1)], SezioniDeiDocenti::TUTTI, 'https://esempio.test');

        self::assertSame('Pantedu — incarico tolto sulla classe 2A', $oggetto);
        self::assertStringNotContainsString('non compare più', $testo, 'con «tutti» il docente usa ancora la sezione');
        self::assertStringContainsString('Lo studente con account di questa classe non vede più i tuoi contenuti.', $testo);
    }

    /** 15/9/2026, scelta dell'utente: senza materiali né credenziali non c'è niente da fare, e non si scrive. */
    #[Test]
    public function si_scrive_solo_se_c_e_qualcosa_da_fare(): void
    {
        self::assertFalse(AvvisoIncarichiTolti::daFare([self::riga('5'), self::riga('2A', [], 0, 0, 0, 3)]), 'niente, anche con studenti con account');
        self::assertTrue(AvvisoIncarichiTolti::daFare([self::riga('5'), self::riga('2A', ['mappa' => 1])]), 'un materiale');
        self::assertTrue(AvvisoIncarichiTolti::daFare([self::riga('2A', [], 1)]), 'una verifica');
        self::assertTrue(AvvisoIncarichiTolti::daFare([self::riga('2A', [], 0, 0, 1)]), 'una credenziale attiva');
        self::assertTrue(AvvisoIncarichiTolti::daFare([self::riga('2A', [], 0, 0, 0, 0, 1)]), 'una pubblicazione «per più classi»');
    }

    #[Test]
    public function i_bersagli_si_dicono_a_parte(): void
    {
        self::assertSame('nessun materiale; 2 pubblicazioni «per più classi»', AvvisoIncarichiTolti::descrivi(self::riga('2A', [], 0, 0, 0, 0, 2)));
        self::assertSame('1 laboratorio · 1 pubblicazione «per più classi»', AvvisoIncarichiTolti::descrivi(self::riga('2A', ['lab' => 1], 0, 0, 0, 0, 1)));
    }
}
