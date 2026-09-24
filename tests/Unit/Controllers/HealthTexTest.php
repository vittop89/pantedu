<?php

declare(strict_types=1);

namespace Tests\Unit\Controllers;

use App\Controllers\HealthController;
use App\Core\Config;
use App\Core\Request;
use App\Middleware\WafMiddleware;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Tests\Support\ServizioFinto;

/**
 * GET /health/tex: l'applicazione raggiunge il servizio TeX? (19/9/2026)
 *
 * Lo chiedono il rilascio (attraverso nginx, quindi dal container che serve
 * davvero) e la diagnostica dell'host. Dall'host il TeX si raggiunge anche
 * quando il container non ci arriva: è esattamente il guasto dell'8 settembre
 * 2026, invisibile a ogni controllo fatto dall'host. Per questo la domanda la
 * deve fare l'applicazione.
 *
 * È pubblico come /health e /health/backup, quindi risponde solo con un sì o un
 * no e la classe dell'errore: mai l'indirizzo, la porta o il segreto.
 */
final class HealthTexTest extends TestCase
{
    /** @var array<string, mixed> */
    private array $prima = [];
    private ?ServizioFinto $finto = null;
    private string $cartella = '';

    protected function setUp(): void
    {
        foreach (['tex_compile.endpoint', 'tex_compile.secret', 'app.paths.storage'] as $chiave) {
            $this->prima[$chiave] = Config::get($chiave);
        }
        // Dal 20/9/2026 `tex()` tiene l'esito della sonda in
        // `{storage}/cache/health-tex.json`: qui va in una cartella temporanea,
        // così la prova non scrive nel repository e non eredita la risposta di
        // un'altra prova (HealthTexCacheTest).
        $this->cartella = sys_get_temp_dir() . '/pantedu-health-tex-sonda-' . bin2hex(random_bytes(6));
        mkdir($this->cartella, 0700, true);
        Config::set('app.paths.storage', $this->cartella);
    }

    protected function tearDown(): void
    {
        foreach ($this->prima as $chiave => $valore) {
            Config::set($chiave, $valore);
        }
        $this->finto?->ferma();
        foreach (glob($this->cartella . '/cache/*') ?: [] as $f) {
            @unlink($f);
        }
        @rmdir($this->cartella . '/cache');
        @rmdir($this->cartella);
    }

    /** @return array{0: int, 1: array<string, mixed>, 2: string} */
    private function chiedi(string $endpoint, string $segreto = 'segreto-di-prova'): array
    {
        Config::set('tex_compile.endpoint', $endpoint);
        Config::set('tex_compile.secret', $segreto);
        $risposta = (new HealthController())->tex(new Request(''));
        $dati = json_decode($risposta->body, true);
        self::assertIsArray($dati, "risposta JSON: {$risposta->body}");
        self::assertStringContainsString('application/json', $risposta->headers['Content-Type'] ?? '');
        return [$risposta->status, $dati, $risposta->body];
    }

    #[Test]
    public function senza_configurazione_lo_dice_e_non_e_un_guasto(): void
    {
        [$stato, $dati] = $this->chiedi('', '');
        self::assertSame(200, $stato);
        self::assertSame(['tex' => 'non_configurato'], $dati);
    }

    #[Test]
    public function con_il_servizio_irraggiungibile_risponde_503_senza_indirizzi(): void
    {
        $porta = ServizioFinto::portaChiusa();
        [$stato, $dati, $corpo] = $this->chiedi("http://127.0.0.1:{$porta}");

        self::assertSame(503, $stato, 'il codice deve dire la verità: un monitor HTTP lo vede rosso');
        self::assertSame(['tex' => false, 'errore' => 'connessione rifiutata'], $dati);
        self::assertStringNotContainsString('127.0.0.1', $corpo);
        self::assertStringNotContainsString((string)$porta, $corpo);
        self::assertStringNotContainsString('segreto', $corpo);
    }

    #[Test]
    public function con_il_servizio_che_risponde_dice_si(): void
    {
        $this->finto = ServizioFinto::avvia([
            '/health' => [200, '{"status":"ok","service":"tex-compile-vps","version":"1.4.1"}'],
        ]);
        [$stato, $dati, $corpo] = $this->chiedi($this->finto->url());

        self::assertSame(200, $stato);
        self::assertSame(['tex' => true], $dati);
        self::assertStringNotContainsString((string)$this->finto->porta, $corpo);
    }

    #[Test]
    public function il_waf_la_lascia_passare_come_gli_altri_controlli_di_salute(): void
    {
        // Senza, la sfida PoW risponde 200 con la pagina «Verifica…» e il
        // rilascio non legge mai `"tex":` nel corpo: è la trappola in cui era
        // già caduto /health (WafMiddleware, 31/8/2026).
        $waf = new WafMiddleware();
        $salta = new \ReflectionMethod($waf, 'shouldBypass');

        self::assertTrue($salta->invoke($waf, '/health/tex'));
        self::assertTrue($salta->invoke($waf, '/health/backup'));
        // Controprova: solo quel percorso, non tutto ciò che gli somiglia.
        self::assertFalse($salta->invoke($waf, '/health/texx'));
        self::assertFalse($salta->invoke($waf, '/health/tex/altro'));
    }
}
