<?php

declare(strict_types=1);

namespace Tests\Unit\Ops;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Tests\Support\ServizioFinto;

/**
 * I due invarianti nuovi della diagnostica, `tex` e `registro`, provati nei due
 * versi con lo script vero in un processo a parte (19/9/2026).
 *
 * `tex`: dall'8 settembre 2026 nessuna compilazione avviata dal sito
 * funzionava, perché il container cercava il servizio TeX sul proprio
 * 127.0.0.1. La diagnostica girava due volte al giorno e dopo ogni rilascio, e
 * non lo guardava. Qui: porta chiusa → `guasto` ed esito 1; servizio che
 * risponde → `regge` ed esito 0.
 *
 * `registro`: il registro delle anomalie non era scrivibile dal container, e le
 * sue righe si perdevano in silenzio. Qui: file 0440 → `guasto`; 0660 → `regge`.
 *
 * La configurazione si imposta dopo il bootstrap, in uno script che poi include
 * la diagnostica: `.env.local` (caricato con `createMutable`) scavalcherebbe
 * qualunque variabile d'ambiente passata al processo. I dati si fingono dentro
 * il repository, cioè una macchina di sviluppo: il passaggio da nginx c'è solo
 * su un'installazione vera, e lo prova `ControlloTexTest`.
 */
final class DiagnosticaTexERegistroTest extends TestCase
{
    private string $cartella = '';
    private ?ServizioFinto $finto = null;

    protected function setUp(): void
    {
        $this->cartella = sys_get_temp_dir() . '/pantedu-diagnostica-' . bin2hex(random_bytes(6));
        mkdir($this->cartella . '/logs', 0700, true);
        file_put_contents($this->cartella . '/lancia.php', <<<'PHP'
<?php
$repo = (string)getenv('PROVA_REPO');
require_once $repo . '/app/bootstrap.php';
\App\Core\Config::set('tex_compile.endpoint', (string)getenv('PROVA_TEX'));
\App\Core\Config::set('tex_compile.secret', (string)getenv('PROVA_TEX') === '' ? '' : 'segreto-di-prova');
\App\Core\Config::set('app.paths.logs', (string)getenv('PROVA_LOGS'));
\App\Core\Config::set('app.paths.data_base', \App\Core\Config::get('app.paths.base') . '/storage');
ini_set('display_errors', 'stderr');
ini_set('error_log', (string)getenv('PROVA_LOGS') . '/php_errors.log');
$argv = ['diagnostica.php', '--json', '--solo=' . getenv('PROVA_SOLO')];
require $repo . '/tools/ops/diagnostica.php';
PHP);
    }

    protected function tearDown(): void
    {
        $this->finto?->ferma();
        foreach ([$this->cartella . '/logs', $this->cartella] as $d) {
            foreach (glob($d . '/{,.}*', GLOB_BRACE) ?: [] as $f) {
                if (is_file($f)) {
                    @chmod($f, 0600);
                    @unlink($f);
                }
            }
        }
        @rmdir($this->cartella . '/logs');
        @rmdir($this->cartella);
    }

    /**
     * @return array{esito: int, controlli: array<string, array{esito: string, prova: string}>, uscita: string}
     */
    private function diagnostica(string $solo, string $tex = ''): array
    {
        $env = array_merge(getenv(), [
            'PROVA_REPO' => \dirname(__DIR__, 3),
            'PROVA_TEX'  => $tex,
            'PROVA_LOGS' => $this->cartella . '/logs',
            'PROVA_SOLO' => $solo,
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
        $controlli = [];
        foreach ($json['controlli'] ?? [] as $c) {
            $controlli[(string)$c['nome']] = ['esito' => (string)$c['esito'], 'prova' => (string)$c['prova']];
        }
        return ['esito' => $esito, 'controlli' => $controlli, 'uscita' => $uscita . $errori];
    }

    #[Test]
    public function tex_con_la_porta_chiusa_e_un_guasto(): void
    {
        $porta = ServizioFinto::portaChiusa();
        $r = $this->diagnostica('tex', "http://127.0.0.1:{$porta}");

        self::assertSame(1, $r['esito'], $r['uscita']);
        self::assertSame('guasto', $r['controlli']['tex']['esito'] ?? null, $r['uscita']);
        self::assertStringContainsString('connessione rifiutata', $r['controlli']['tex']['prova']);
    }

    #[Test]
    public function tex_con_il_servizio_che_risponde_regge(): void
    {
        $this->finto = ServizioFinto::avvia([
            '/health' => [200, '{"status":"ok","service":"tex-compile-vps","version":"1.4.1"}'],
        ]);
        $r = $this->diagnostica('tex', $this->finto->url());

        self::assertSame(0, $r['esito'], $r['uscita']);
        self::assertSame('regge', $r['controlli']['tex']['esito'] ?? null, $r['uscita']);
    }

    #[Test]
    public function tex_non_configurato_non_e_un_guasto(): void
    {
        // Come geoip: non configurato è una scelta (lo sviluppo senza TeX, la
        // CI), configurato e irraggiungibile è il guasto.
        $r = $this->diagnostica('tex', '');

        self::assertSame(0, $r['esito'], $r['uscita']);
        self::assertSame('non_applicabile', $r['controlli']['tex']['esito'] ?? null, $r['uscita']);
    }

    #[Test]
    public function registro_non_scrivibile_e_un_guasto(): void
    {
        if (\function_exists('posix_geteuid') && posix_geteuid() === 0) {
            self::markTestSkipped('da root ogni file è scrivibile: la prova non direbbe niente');
        }
        $registro = $this->cartella . '/logs/anomalie.jsonl';
        file_put_contents($registro, '');
        chmod($registro, 0440);

        $r = $this->diagnostica('registro');

        self::assertSame(1, $r['esito'], $r['uscita']);
        self::assertSame('guasto', $r['controlli']['registro']['esito'] ?? null, $r['uscita']);
        self::assertStringContainsString('anomalie.jsonl', $r['controlli']['registro']['prova']);
    }

    #[Test]
    public function registro_scrivibile_regge(): void
    {
        $registro = $this->cartella . '/logs/anomalie.jsonl';
        file_put_contents($registro, '');
        chmod($registro, 0660);
        file_put_contents($registro . '.stato', '{}');
        chmod($registro . '.stato', 0660);

        $r = $this->diagnostica('registro');

        self::assertSame(0, $r['esito'], $r['uscita']);
        self::assertSame('regge', $r['controlli']['registro']['esito'] ?? null, $r['uscita']);
    }
}
