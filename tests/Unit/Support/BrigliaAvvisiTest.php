<?php

declare(strict_types=1);

namespace Tests\Unit\Support;

use App\Core\Config;
use App\Support\BrigliaAvvisi;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * La briglia sugli avvisi ripetuti.
 *
 * Le due direzioni contano tutte e due, e si provano tutte e due: **suona**
 * quando deve (primo guasto, e di nuovo dopo la finestra) e **tace** quando
 * deve (guasto che si ripete subito). Un allarme provato in una direzione sola
 * è mezzo allarme.
 */
final class BrigliaAvvisiTest extends TestCase
{
    private string $cartella = '';
    private mixed $logsPrima = null;

    protected function setUp(): void
    {
        $this->cartella = sys_get_temp_dir() . '/pantedu-briglia-' . bin2hex(random_bytes(6));
        mkdir($this->cartella, 0o777, true);
        $this->logsPrima = Config::get('app.paths.logs');
        Config::set('app.paths.logs', $this->cartella);
    }

    protected function tearDown(): void
    {
        Config::set('app.paths.logs', $this->logsPrima);
        // Ricorsivo a mano: due livelli, non serve di piu'.
        foreach (glob($this->cartella . '/avvisi/*') ?: [] as $f) {
            @unlink($f);
        }
        @rmdir($this->cartella . '/avvisi');
        @rmdir($this->cartella);
    }

    #[Test]
    public function il_primo_guasto_avvisa(): void
    {
        $d = BrigliaAvvisi::consulta('pantedu-prova.service', 1_000_000);
        self::assertTrue($d['manda'], 'il primo avviso deve partire');
        self::assertSame(0, $d['soppressi']);
    }

    #[Test]
    public function un_guasto_subito_dopo_non_avvisa_ma_si_conta(): void
    {
        BrigliaAvvisi::consulta('pantedu-prova.service', 1_000_000);

        $secondo = BrigliaAvvisi::consulta('pantedu-prova.service', 1_000_060);
        self::assertFalse($secondo['manda'], 'dentro la finestra non si manda');
        self::assertSame(1, $secondo['soppressi']);

        $terzo = BrigliaAvvisi::consulta('pantedu-prova.service', 1_000_120);
        self::assertFalse($terzo['manda']);
        self::assertSame(2, $terzo['soppressi'], 'i soppressi si sommano');
    }

    #[Test]
    public function dopo_la_finestra_riparte_e_dice_quanti_ne_ha_ingoiati(): void
    {
        BrigliaAvvisi::consulta('pantedu-prova.service', 1_000_000);
        BrigliaAvvisi::consulta('pantedu-prova.service', 1_000_060);
        BrigliaAvvisi::consulta('pantedu-prova.service', 1_000_120);

        $dopo = BrigliaAvvisi::consulta('pantedu-prova.service', 1_000_000 + BrigliaAvvisi::FINESTRA_SECONDI);
        self::assertTrue($dopo['manda'], 'passata la finestra si torna a mandare');
        self::assertSame(2, $dopo['soppressi'], 'e si dice quanti se ne sono persi');
    }

    #[Test]
    public function ogni_unita_ha_la_sua_briglia(): void
    {
        BrigliaAvvisi::consulta('pantedu-una.service', 1_000_000);

        $altra = BrigliaAvvisi::consulta('pantedu-altra.service', 1_000_060);
        self::assertTrue($altra['manda'], 'il silenzio di un\'unita non deve zittirne un\'altra');
    }

    #[Test]
    public function il_nome_dell_unita_non_esce_dalla_cartella(): void
    {
        // Il nome arriva da systemd (`%n`), cioe' da fuori: non deve poter
        // scrivere altrove. L'invariante non e' «niente punti nel nome» — i
        // nomi delle unita' ne sono pieni — ma «il file resta in quella
        // cartella», cioe' nessun separatore sopravvive.
        $percorso = BrigliaAvvisi::percorso('../../../etc/passwd');

        self::assertSame(BrigliaAvvisi::cartella(), dirname($percorso), 'il file resta sotto avvisi/');
        self::assertStringNotContainsString('/', basename($percorso), 'nessuna barra nel nome del file');
        self::assertStringNotContainsString('\\', basename($percorso), 'nemmeno rovesciata');
    }

    #[Test]
    public function con_lo_stato_illeggibile_si_manda_lo_stesso(): void
    {
        // Fallire aperti: meglio una email di troppo che un allarme perso.
        $file = BrigliaAvvisi::percorso('pantedu-rotta.service');
        @mkdir(dirname($file), 0o777, true);
        file_put_contents($file, 'questo non e\' JSON');

        $d = BrigliaAvvisi::consulta('pantedu-rotta.service', 1_000_000);
        self::assertTrue($d['manda']);
    }
}
