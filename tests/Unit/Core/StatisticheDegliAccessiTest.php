<?php

declare(strict_types=1);

namespace Tests\Unit\Core;

use App\Core\AccessLogger;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Le statistiche del registro degli accessi non tengono i nomi utente
 * (24/9/2026).
 *
 * `access_stats.json` conservava per sempre, giorno per giorno, i nomi utente
 * di chi era entrato, e per ogni utente il primo e l'ultimo accesso e il
 * totale. Nessun documento lo dichiarava, nessun lavoro lo puliva, e la
 * cancellazione dell'account non lo toccava: il termine del registro degli
 * accessi (le ultime mille voci) si aggirava per intero. Il cruscotto ne usava
 * solo il totale degli accessi e il numero di utenti del giorno.
 *
 * Nei due versi: i totali continuano a contare (una prova su un file vuoto
 * passerebbe anche se le statistiche smettessero di funzionare), e nessun nome
 * utente finisce nel file, né nuovo né rimasto da prima. Il numero di utenti
 * del giorno si conta dal registro, e gli anonimi non contano.
 */
final class StatisticheDegliAccessiTest extends TestCase
{
    private string $cartella = '';

    protected function setUp(): void
    {
        $this->cartella = sys_get_temp_dir() . '/pantedu-statistiche-accessi-' . bin2hex(random_bytes(6));
        mkdir($this->cartella, 0700, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->cartella . '/*') ?: [] as $f) {
            @unlink($f);
        }
        @rmdir($this->cartella);
    }

    #[Test]
    public function i_totali_contano_e_nessun_nome_utente_finisce_nel_file(): void
    {
        $registro = new AccessLogger($this->cartella);
        $registro->logAccess('docente-di-prova', 'teacher', '/teacher/dashboard', 'login');
        $registro->logAccess('docente-di-prova', 'teacher', '/teacher/dashboard', 'access');
        $registro->logAccess('altro-docente', 'teacher', '/teacher/dashboard', 'access');

        $oggi = date('Y-m-d');
        self::assertSame(3, $registro->stats('daily_stats')[$oggi]['total_accesses'] ?? null);

        $file = (string) file_get_contents($this->cartella . '/access_stats.json');
        self::assertStringNotContainsString('docente-di-prova', $file);
        self::assertStringNotContainsString('altro-docente', $file);
        self::assertSame([], $registro->stats('user_stats'));
    }

    #[Test]
    public function il_contenuto_rimasto_da_prima_perde_i_nomi_e_tiene_i_totali(): void
    {
        $file = $this->cartella . '/access_stats.json';
        file_put_contents($file, json_encode([
            'daily_stats' => ['2026-09-01' => ['total_accesses' => 3, 'unique_users' => ['docente-di-prova']]],
            'user_stats'  => ['docente-di-prova' => ['first_access' => '2026-04-19 08:00:00', 'total_accesses' => 3]],
            'institute_stats' => [],
            'class_stats' => [],
        ]));

        $stats = (new AccessLogger($this->cartella))->stats();

        self::assertSame(['daily_stats' => ['2026-09-01' => ['total_accesses' => 3]]], $stats);
        self::assertStringNotContainsString('docente-di-prova', (string) file_get_contents($file));
    }

    #[Test]
    public function gli_utenti_di_oggi_si_contano_dal_registro(): void
    {
        $registro = new AccessLogger($this->cartella);
        $registro->logAccess('docente-di-prova', 'teacher', '/teacher/dashboard', 'login');
        $registro->logAccess('docente-di-prova', 'teacher', '/teacher/dashboard', 'access');
        $registro->logAccess('altro-docente', 'teacher', '/teacher/dashboard', 'access');
        $registro->logAccess('anonymous', 'guest', '/password/forgot', 'password_reset_requested');

        self::assertSame(2, $registro->utentiDiOggi());
    }
}
