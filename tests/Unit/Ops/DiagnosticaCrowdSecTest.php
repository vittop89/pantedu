<?php

declare(strict_types=1);

namespace Tests\Unit\Ops;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Tests\Support\ServizioFinto;

/**
 * Il controllo `crowdsec` della diagnostica, con lo script vero in un processo
 * a parte e servizi finti al posto della LAPI (23/9/2026).
 *
 * Nei due versi: la LAPI che risponde → `regge` ed esito 0; il nginx
 * dell'applicazione (404, una pagina HTML: è quello che c'è sulla loopback del
 * container, A-15) o la sola chiave senza URL → `guasto` ed esito 1. Bouncer
 * spento → `non_applicabile`, che non è un guasto: è lo stato di oggi in
 * produzione (misurato il 23/9/2026).
 *
 * La configurazione si imposta dopo il bootstrap, come in
 * DiagnosticaTexERegistroTest: `.env.local` scavalcherebbe l'ambiente.
 */
final class DiagnosticaCrowdSecTest extends TestCase
{
    private string $cartella = '';
    private ?ServizioFinto $finto = null;

    protected function setUp(): void
    {
        $this->cartella = sys_get_temp_dir() . '/pantedu-diagnostica-crowdsec-' . bin2hex(random_bytes(6));
        mkdir($this->cartella . '/logs', 0700, true);
        file_put_contents($this->cartella . '/lancia.php', <<<'PHP'
<?php
$repo = (string)getenv('PROVA_REPO');
require_once $repo . '/app/bootstrap.php';
\App\Core\Config::set('waf.crowdsec_lapi_url', (string)getenv('PROVA_URL'));
\App\Core\Config::set('waf.crowdsec_lapi_key', (string)getenv('PROVA_CHIAVE'));
\App\Core\Config::set('app.paths.logs', (string)getenv('PROVA_LOGS'));
\App\Core\Config::set('app.paths.data_base', \App\Core\Config::get('app.paths.base') . '/storage');
ini_set('display_errors', 'stderr');
ini_set('error_log', (string)getenv('PROVA_LOGS') . '/php_errors.log');
$argv = ['diagnostica.php', '--json', '--solo=crowdsec'];
require $repo . '/tools/ops/diagnostica.php';
PHP);
    }

    protected function tearDown(): void
    {
        $this->finto?->ferma();
        foreach (glob($this->cartella . '/logs/{,.}*', GLOB_BRACE) ?: [] as $f) {
            if (is_file($f)) {
                @unlink($f);
            }
        }
        @rmdir($this->cartella . '/logs');
        @unlink($this->cartella . '/lancia.php');
        @rmdir($this->cartella);
    }

    /** @return array{esito: int, controllo: array{esito: string, prova: string}, uscita: string} */
    private function diagnostica(string $url, string $chiave): array
    {
        $proc = proc_open(
            [PHP_BINARY, $this->cartella . '/lancia.php'],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $tubi,
            $this->cartella,
            array_merge(getenv(), [
                'PROVA_REPO'   => \dirname(__DIR__, 3),
                'PROVA_URL'    => $url,
                'PROVA_CHIAVE' => $chiave,
                'PROVA_LOGS'   => $this->cartella . '/logs',
            ]),
        );
        self::assertIsResource($proc);
        $uscita = (string)stream_get_contents($tubi[1]);
        $errori = (string)stream_get_contents($tubi[2]);
        $esito = proc_close($proc);

        $json = json_decode($uscita, true);
        self::assertIsArray($json, "la diagnostica stampa JSON: {$uscita}{$errori}");
        $controllo = null;
        foreach ($json['controlli'] ?? [] as $c) {
            if (($c['nome'] ?? '') === 'crowdsec') {
                $controllo = ['esito' => (string)$c['esito'], 'prova' => (string)$c['prova']];
            }
        }
        self::assertNotNull($controllo, "manca il controllo `crowdsec`: {$uscita}{$errori}");
        return ['esito' => $esito, 'controllo' => $controllo, 'uscita' => $uscita . $errori];
    }

    #[Test]
    public function la_lapi_che_risponde_regge(): void
    {
        $this->finto = ServizioFinto::avvia(['/v1/decisions' => [200, 'null']]);

        $r = $this->diagnostica($this->finto->url(), 'chiave-di-prova');

        self::assertSame(0, $r['esito'], $r['uscita']);
        self::assertSame('regge', $r['controllo']['esito'], $r['uscita']);
        self::assertStringContainsString('risponde la LAPI', $r['controllo']['prova']);
        self::assertStringNotContainsString('chiave-di-prova', $r['uscita'], 'la chiave non si stampa');
    }

    #[Test]
    public function il_nginx_dell_applicazione_e_un_guasto(): void
    {
        $this->finto = ServizioFinto::avvia([
            '/v1/decisions' => [404, "<!doctype html><title>404</title>\n", 0, 'text/html; charset=UTF-8'],
        ]);

        $r = $this->diagnostica($this->finto->url(), 'chiave-di-prova');

        self::assertSame(1, $r['esito'], $r['uscita']);
        self::assertSame('guasto', $r['controllo']['esito'], $r['uscita']);
        self::assertStringContainsString('non è la LAPI (HTTP 404, text/html', $r['controllo']['prova']);
    }

    #[Test]
    public function la_sola_chiave_e_un_guasto(): void
    {
        // Il caso di A-15 dopo il 23/9: senza URL il bouncer è spento, e chi
        // ha messo la chiave deve saperlo.
        $r = $this->diagnostica('', 'chiave-di-prova');

        self::assertSame(1, $r['esito'], $r['uscita']);
        self::assertSame('guasto', $r['controllo']['esito'], $r['uscita']);
        self::assertStringContainsString('configurato a metà: manca CROWDSEC_LAPI_URL', $r['controllo']['prova']);
    }

    #[Test]
    public function il_bouncer_spento_non_si_applica(): void
    {
        $r = $this->diagnostica('', '');

        self::assertSame(0, $r['esito'], $r['uscita']);
        self::assertSame('non_applicabile', $r['controllo']['esito'], $r['uscita']);
    }
}
