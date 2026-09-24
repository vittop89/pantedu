<?php

declare(strict_types=1);

namespace Tests\Unit\Core;

use App\Core\Session;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Le impostazioni delle sessioni vengono dalla configurazione, uguali in ogni
 * ambiente (ADR-039, 14/9/2026). Ogni decisione nei due versi.
 */
final class SessionImpostazioniTest extends TestCase
{
    private string $radice = '';

    protected function setUp(): void
    {
        $this->radice = sys_get_temp_dir() . '/pantedu-sessioni-cartelle-' . bin2hex(random_bytes(6));
        mkdir($this->radice, 0700, true);
    }

    protected function tearDown(): void
    {
        foreach (['/nuova/sotto', '/nuova', '/sola-lettura/dentro', '/sola-lettura', '/mai'] as $dir) {
            if (is_dir($this->radice . $dir)) {
                @chmod($this->radice . $dir, 0700);
                @rmdir($this->radice . $dir);
            }
        }
        @rmdir($this->radice);
    }

    #[Test]
    public function il_modo_si_sceglie_in_configurazione_e_uno_sconosciuto_e_un_errore(): void
    {
        $this->assertSame('file', Session::driver([]), 'predefinito');
        $this->assertSame('file', Session::driver(['driver' => '']));
        $this->assertSame('database', Session::driver(['driver' => 'database']));
        $this->assertSame('database', Session::driver(['driver' => ' Database ']));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('SESSION_DRIVER «redis» sconosciuto');
        Session::driver(['driver' => 'redis']);
    }

    #[Test]
    public function la_modalita_stretta_c_e_sempre_e_la_pulizia_aspetta_tutta_l_inattivita(): void
    {
        $ini = Session::impostazioni(['lifetime' => 1800], '/dati/storage');

        $this->assertSame('1', $ini['session.use_strict_mode'], 'un id sconosciuto non si accetta, in ogni ambiente');
        $this->assertArrayNotHasKey('session.use_only_cookies', $ini, 'deprecato da PHP 8.4, e già sicuro di suo');
        $this->assertSame('1800', $ini['session.gc_maxlifetime'], 'non i 1440 s di PHP, meno dei 30 minuti concessi');
        $this->assertSame('1', $ini['session.gc_probability'], 'e la pulizia gira davvero');
    }

    #[Test]
    public function a_file_la_cartella_e_quella_configurata_o_storage_sessions(): void
    {
        $this->assertSame(
            '/dati/storage/sessions',
            Session::impostazioni([], '/dati/storage/')['session.save_path'],
            'sul volume dei dati, che sopravvive allo scambio dei container',
        );
        $this->assertSame(
            '/altrove/sessioni',
            Session::impostazioni(['save_path' => '/altrove/sessioni'], '/dati/storage')['session.save_path'],
        );
    }

    #[Test]
    public function su_database_la_cartella_non_si_tocca(): void
    {
        $this->assertArrayNotHasKey('session.save_path', Session::impostazioni(['driver' => 'database'], '/dati/storage'));
    }

    #[Test]
    public function la_cartella_si_crea_solo_se_si_puo_e_leggibile_solo_da_chi_serve_le_pagine(): void
    {
        $nuova = $this->radice . '/nuova/sotto';

        $this->assertFalse(Session::cartellaPronta($nuova, false), 'da riga di comando non si crea');
        $this->assertDirectoryDoesNotExist($nuova);

        $this->assertTrue(Session::cartellaPronta($nuova, true), 'servendo pagine sì');
        $this->assertDirectoryExists($nuova);
        $this->assertSame(0700, fileperms($nuova) & 0777, 'i nomi dei file sono gli id di sessione');
        $this->assertTrue(Session::cartellaPronta($nuova, false), 'e una volta creata va bene per tutti');
    }

    #[Test]
    public function una_cartella_che_non_si_puo_scrivere_non_e_pronta(): void
    {
        if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
            $this->markTestSkipped('da root ogni cartella è scrivibile');
        }
        $bloccata = $this->radice . '/sola-lettura';
        mkdir($bloccata, 0500);

        $this->assertFalse(Session::cartellaPronta($bloccata, true));
        $this->assertFalse(Session::cartellaPronta($bloccata . '/dentro', true), 'e dentro non si crea');
        $this->assertFalse(Session::cartellaPronta('', true), 'e nemmeno un percorso vuoto');
    }
}
