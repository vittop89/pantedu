<?php

declare(strict_types=1);

namespace Tests\Unit\Ops;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Il controllo `unita` della diagnostica, con lo script vero in un processo a
 * parte e una cartella finta al posto di /etc/systemd/system (23/9/2026).
 *
 * Il rilascio a container non installa più le unità di `tools/systemd`, e fino
 * a oggi niente confrontava il server con il repository. Qui la cartella finta
 * è una copia delle unità **vere** del repository, così la prova dice anche
 * che il confronto regge sulle unità che ci sono oggi, non solo su tre file
 * inventati (quelli stanno in UnitaInstallateTest).
 *
 * Nei due versi: copia fedele → `regge` ed esito 0; un'unità cambiata, una
 * non installata, un file che non si legge, un timer spento, un'orfana o una
 * cartella che non c'è → `guasto` ed esito 1, con il nome nella prova. E dove
 * non ha oggetto — una macchina di sviluppo, senza cartella indicata —
 * `non_applicabile`.
 */
final class DiagnosticaUnitaTest extends TestCase
{
    private string $cartella = '';

    protected function setUp(): void
    {
        $this->cartella = sys_get_temp_dir() . '/pantedu-diagnostica-unita-' . bin2hex(random_bytes(6));
        mkdir($this->cartella . '/logs', 0700, true);
        mkdir($this->cartella . '/etc', 0700, true);
        // La configurazione si imposta dopo il bootstrap: `.env.local`, caricato
        // mutabile, scavalcherebbe l'ambiente del processo (come in
        // DiagnosticaTexERegistroTest). I dati restano dentro il repository:
        // una macchina di sviluppo.
        file_put_contents($this->cartella . '/lancia.php', <<<'PHP'
<?php
$repo = (string)getenv('PROVA_REPO');
require_once $repo . '/app/bootstrap.php';
\App\Core\Config::set('app.paths.logs', (string)getenv('PROVA_LOGS'));
\App\Core\Config::set('app.paths.data_base', \App\Core\Config::get('app.paths.base') . '/storage');
ini_set('display_errors', 'stderr');
ini_set('error_log', (string)getenv('PROVA_LOGS') . '/php_errors.log');
$argv = ['diagnostica.php', '--json', '--solo=' . (getenv('PROVA_SOLO') ?: 'unita')];
if ((string)getenv('PROVA_UNITA') !== '') {
    $argv[] = '--unita-installate=' . getenv('PROVA_UNITA');
}
require $repo . '/tools/ops/diagnostica.php';
PHP);
    }

    protected function tearDown(): void
    {
        $this->svuota($this->cartella);
    }

    private function svuota(string $p): void
    {
        if (is_link($p) || is_file($p)) {
            @unlink($p);
            return;
        }
        foreach (scandir($p) ?: [] as $v) {
            if ($v !== '.' && $v !== '..') {
                $this->svuota($p . '/' . $v);
            }
        }
        @rmdir($p);
    }

    /** Copia le unità vere del repository e accende timer e path, come il vecchio rilascio. */
    private function installaLeUnitaVere(): string
    {
        $etc = $this->cartella . '/etc';
        $sorgente = \dirname(__DIR__, 3) . '/tools/systemd';
        foreach (scandir($sorgente) ?: [] as $nome) {
            $p = $sorgente . '/' . $nome;
            if (is_dir($p) && str_ends_with($nome, '.service.d')) {
                mkdir($etc . '/' . $nome, 0700, true);
                foreach (glob($p . '/*.conf') ?: [] as $conf) {
                    copy($conf, $etc . '/' . $nome . '/' . basename($conf));
                }
                continue;
            }
            if (!preg_match('/\.(service|timer|path)$/', $nome, $m)) {
                continue;
            }
            copy($p, $etc . '/' . $nome);
            if ($m[1] === 'service') {
                continue;
            }
            preg_match_all('/^WantedBy\s*=\s*(\S+)/m', (string)file_get_contents($p), $bersagli);
            foreach ($bersagli[1] as $t) {
                @mkdir($etc . '/' . $t . '.wants', 0700, true);
                symlink($etc . '/' . $nome, $etc . '/' . $t . '.wants/' . $nome);
            }
        }
        return $etc;
    }

    /**
     * @return array{esito: int, controllo: array{esito: string, prova: string}, uscita: string}
     */
    private function diagnostica(string $installate, string $solo = 'unita'): array
    {
        $env = array_merge(getenv(), [
            'PROVA_REPO'  => \dirname(__DIR__, 3),
            'PROVA_LOGS'  => $this->cartella . '/logs',
            'PROVA_UNITA' => $installate,
            'PROVA_SOLO'  => $solo,
        ]);
        $proc = proc_open(
            [PHP_BINARY, $this->cartella . '/lancia.php'],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $tubi,
            $this->cartella,
            $env,
        );
        self::assertIsResource($proc);
        $uscita = (string)stream_get_contents($tubi[1]);
        $errori = (string)stream_get_contents($tubi[2]);
        $esito = proc_close($proc);

        $json = json_decode($uscita, true);
        self::assertIsArray($json, "la diagnostica stampa JSON: {$uscita}{$errori}");
        $controllo = null;
        foreach ($json['controlli'] ?? [] as $c) {
            if (($c['nome'] ?? '') === $solo) {
                $controllo = ['esito' => (string)$c['esito'], 'prova' => (string)$c['prova']];
            }
        }
        self::assertNotNull($controllo, "manca il controllo `{$solo}`: {$uscita}{$errori}");
        return ['esito' => $esito, 'controllo' => $controllo, 'uscita' => $uscita . $errori];
    }

    #[Test]
    public function con_le_unita_vere_installate_tali_e_quali_regge(): void
    {
        $r = $this->diagnostica($this->installaLeUnitaVere());

        self::assertSame(0, $r['esito'], $r['uscita']);
        self::assertSame('regge', $r['controllo']['esito'], $r['uscita']);
        self::assertMatchesRegularExpression('/^\d+ file di tools\/systemd uguali/', $r['controllo']['prova']);
    }

    #[Test]
    public function un_timer_cambiato_a_mano_e_un_guasto_con_il_suo_nome(): void
    {
        $etc = $this->installaLeUnitaVere();
        file_put_contents($etc . '/pantedu-diagnostica.timer', "\n# ritocco a mano\n", FILE_APPEND);

        $r = $this->diagnostica($etc);

        self::assertSame(1, $r['esito'], $r['uscita']);
        self::assertSame('guasto', $r['controllo']['esito'], $r['uscita']);
        self::assertStringContainsString('diverse dal repository: pantedu-diagnostica.timer', $r['controllo']['prova']);
        self::assertStringContainsString('Che cosa il rilascio non installa', $r['controllo']['prova']);
    }

    #[Test]
    public function un_timer_nuovo_non_installato_e_un_guasto(): void
    {
        // È il caso della pulizia di `rate_limits`: nel repository dal 23/9/2026,
        // sul server solo quando qualcuno la installa.
        $etc = $this->installaLeUnitaVere();
        $timer = 'pantedu-diagnostica.timer';
        unlink($etc . '/timers.target.wants/' . $timer);
        unlink($etc . '/' . $timer);

        $r = $this->diagnostica($etc);

        self::assertSame(1, $r['esito'], $r['uscita']);
        self::assertStringContainsString("non installate: $timer", $r['controllo']['prova']);
    }

    #[Test]
    public function un_file_illeggibile_un_timer_spento_e_un_orfano_sono_un_guasto_con_i_loro_nomi(): void
    {
        // Le tre risposte che UnitaInstallate dà e che solo la diagnostica
        // trasforma in esito. UnitaInstallateTest le classifica; qui si prova
        // che arrivano nella riga del controllo. Senza, togliere uno dei tre
        // rami in diagnostica.php faceva sparire quell'unità dal rapporto e il
        // controllo diceva «regge» (verifica avversaria del 23/9/2026).
        $etc = $this->installaLeUnitaVere();

        // Un file che chi gira non può leggere: «non ho potuto confrontare».
        $illeggibile = $etc . '/pantedu-diagnostica.service';
        chmod($illeggibile, 0000);
        if (is_readable($illeggibile)) {
            // Da root ogni file si legge. Al posto dei permessi, un
            // collegamento che non porta a niente: nemmeno root lo legge, e
            // la risposta attesa è la stessa.
            unlink($illeggibile);
            symlink($this->cartella . '/non-esiste.service', $illeggibile);
        }
        // Un timer installato senza il collegamento che `systemctl enable` crea.
        unlink($etc . '/timers.target.wants/pantedu-versioni.timer');
        // Un timer nostro che il repository non ha più (la fonte CrowdSec,
        // tolta l'8/9/2026).
        file_put_contents($etc . '/pantedu-waf-threat-intel@crowdsec.timer', "[Timer]\nOnCalendar=daily\n");

        $r = $this->diagnostica($etc);

        self::assertSame(1, $r['esito'], $r['uscita']);
        self::assertSame('guasto', $r['controllo']['esito'], $r['uscita']);
        $prova = $r['controllo']['prova'];
        self::assertMatchesRegularExpression(
            '/non ho potuto confrontare \(non leggibili da [^)]*\): pantedu-diagnostica\.service/',
            $prova,
        );
        self::assertStringContainsString(
            'installate ma spente (nessun collegamento in <bersaglio>.wants): pantedu-versioni.timer',
            $prova,
        );
        self::assertStringContainsString(
            'installate e non più nel repository: pantedu-waf-threat-intel@crowdsec.timer',
            $prova,
        );
        // Nessuna delle tre è finita fra le diverse o le non installate.
        self::assertStringNotContainsString('diverse dal repository', $prova);
        self::assertStringNotContainsString('non installate', $prova);
    }

    #[Test]
    public function una_cartella_che_non_ce_e_un_guasto_non_un_regge(): void
    {
        $r = $this->diagnostica($this->cartella . '/non-esiste');

        self::assertSame(1, $r['esito'], $r['uscita']);
        self::assertSame('guasto', $r['controllo']['esito'], $r['uscita']);
        self::assertStringContainsString('non ho potuto confrontare', $r['controllo']['prova']);
    }

    #[Test]
    public function su_una_macchina_di_sviluppo_senza_cartella_non_si_applica(): void
    {
        $r = $this->diagnostica('');

        self::assertSame(0, $r['esito'], $r['uscita']);
        self::assertSame('non_applicabile', $r['controllo']['esito'], $r['uscita']);
    }

    #[Test]
    public function un_nome_che_nessun_controllo_porta_non_e_un_regge(): void
    {
        // Con la diagnostica di prima `--solo=unita` stampava «0 controlli,
        // tutti reggono» ed esito 0: un nome sbagliato, o una copia dello
        // strumento più vecchia del controllo, sembravano un verde.
        $r = $this->diagnostica('', 'nessuno-si-chiama-cosi');

        self::assertSame(1, $r['esito'], $r['uscita']);
        self::assertSame('guasto', $r['controllo']['esito'], $r['uscita']);
        self::assertStringContainsString('nessun controllo si chiama così', $r['controllo']['prova']);
    }
}
