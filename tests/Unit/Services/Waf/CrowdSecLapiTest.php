<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Waf;

use App\Core\Config;
use App\Services\Waf\WafCrowdSecBouncerService as Bouncer;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Tests\Support\ServizioFinto;

/**
 * Il bouncer CrowdSec riconosce la LAPI, e non scambia per lei il nginx
 * dell'applicazione (23/9/2026).
 *
 * ── Il difetto (A-15 della revisione architetturale del 23/9) ─────────────
 *
 * L'URL della LAPI aveva un predefinito, `http://127.0.0.1:8080`: la porta di
 * serie della LAPI sull'host. Dall'8/9/2026 l'applicazione gira nel container,
 * dove 127.0.0.1:8080 è il suo nginx (docker/nginx.conf). Con la sola chiave
 * impostata, il bouncer interrogava il sito — `GET /v1/decisions` risponde 404
 * con una pagina HTML (misurato sul server di sviluppo il 23/9/2026) — e,
 * fail-open, non bloccava nessuno; il pannello considerava «raggiungibile» ogni
 * codice fra 200 e 499, e lo dava ✓.
 *
 * ── Che cosa difende ──────────────────────────────────────────────────────
 *
 * - la forma di una risposta della LAPI (200 JSON `null` o elenco di
 *   decisioni; 403 JSON «access forbidden» per la chiave rifiutata), e che
 *   tutto il resto non lo sia;
 * - lo stato del pannello, con servizi finti veri (`php -S`): «raggiungibile»
 *   solo se risponde la LAPI;
 * - il fail-open, che resta: chi non è la LAPI non produce decisioni;
 * - nessun URL predefinito: la sola chiave non accende il bouncer.
 */
final class CrowdSecLapiTest extends TestCase
{
    private ?ServizioFinto $finto = null;

    protected function tearDown(): void
    {
        $this->finto?->ferma();
    }

    /** Quello che risponde il nginx dell'applicazione a `GET /v1/decisions`. */
    private const NGINX_404 = [404, "<!doctype html><title>404</title>\n", 0, 'text/html; charset=UTF-8'];

    private static function decisione(string $tipo, string $ip): string
    {
        return (string)json_encode([[
            'duration' => '3h59m', 'id' => 1, 'origin' => 'crowdsec', 'scenario' => 'crowdsecurity/http-probing',
            'scope' => 'Ip', 'type' => $tipo, 'value' => $ip,
        ]]);
    }

    #[Test]
    public function riconosce_le_risposte_della_lapi(): void
    {
        $json = 'application/json; charset=utf-8';
        self::assertSame(Bouncer::RISPOSTA_LAPI, Bouncer::riconosci(200, $json, 'null'), 'nessuna decisione');
        self::assertSame(Bouncer::RISPOSTA_LAPI, Bouncer::riconosci(200, $json, '[]'));
        self::assertSame(Bouncer::RISPOSTA_LAPI, Bouncer::riconosci(200, $json, self::decisione('ban', '203.0.113.9')));
        self::assertSame(
            Bouncer::RISPOSTA_CHIAVE_RIFIUTATA,
            Bouncer::riconosci(403, $json, '{"message":"access forbidden"}'),
        );
    }

    #[Test]
    public function tutto_il_resto_non_e_la_lapi(): void
    {
        $json = 'application/json';
        self::assertSame(Bouncer::RISPOSTA_NON_LAPI, Bouncer::riconosci(404, 'text/html; charset=UTF-8', '<html>'));
        self::assertSame(Bouncer::RISPOSTA_NON_LAPI, Bouncer::riconosci(200, 'text/html', 'null'), 'una pagina');
        self::assertSame(Bouncer::RISPOSTA_NON_LAPI, Bouncer::riconosci(404, $json, '{"detail":"Not Found"}'));
        self::assertSame(Bouncer::RISPOSTA_NON_LAPI, Bouncer::riconosci(403, $json, '{"error":"WAF"}'), 'un altro 403');
        self::assertSame(Bouncer::RISPOSTA_NON_LAPI, Bouncer::riconosci(401, $json, '{"message":"access forbidden"}'));
        self::assertSame(Bouncer::RISPOSTA_NON_LAPI, Bouncer::riconosci(200, $json, '{"ok":true}'), 'un oggetto');
        self::assertSame(Bouncer::RISPOSTA_NON_LAPI, Bouncer::riconosci(200, $json, '[1,2]'), 'un elenco di altro');
        self::assertSame(Bouncer::RISPOSTA_NON_LAPI, Bouncer::riconosci(200, $json, '[{"id":1}]'), 'senza type/value');
        self::assertSame(Bouncer::RISPOSTA_NON_LAPI, Bouncer::riconosci(302, '', ''));
        self::assertSame(Bouncer::RISPOSTA_IRRAGGIUNGIBILE, Bouncer::riconosci(0, '', false));
    }

    #[Test]
    public function il_nginx_dell_applicazione_non_e_raggiungibile_come_lapi(): void
    {
        // Il caso di A-15: con il pannello di prima, «✓ YES».
        $this->finto = ServizioFinto::avvia(['/v1/decisions' => self::NGINX_404]);

        $s = (new Bouncer($this->finto->url(), 'chiave-di-prova'))->status();

        self::assertTrue($s['configured']);
        self::assertFalse($s['reachable'], 'un 404 del sito non è la LAPI');
        self::assertSame(Bouncer::RISPOSTA_NON_LAPI, $s['risposta']);
        self::assertSame(404, $s['http']);
        self::assertStringStartsWith('text/html', $s['tipo']);
    }

    #[Test]
    public function la_lapi_che_risponde_e_raggiungibile(): void
    {
        $this->finto = ServizioFinto::avvia(['/v1/decisions' => [200, 'null']]);

        $s = (new Bouncer($this->finto->url(), 'chiave-di-prova'))->status();

        self::assertTrue($s['reachable']);
        self::assertSame(Bouncer::RISPOSTA_LAPI, $s['risposta']);
        self::assertSame(['/v1/decisions'], array_column($this->finto->richieste(), 'percorso'));
    }

    #[Test]
    public function la_lapi_che_rifiuta_la_chiave_non_e_raggiungibile(): void
    {
        $this->finto = ServizioFinto::avvia(['/v1/decisions' => [403, '{"message":"access forbidden"}']]);

        $s = (new Bouncer($this->finto->url(), 'chiave-sbagliata'))->status();

        self::assertFalse($s['reachable']);
        self::assertSame(Bouncer::RISPOSTA_CHIAVE_RIFIUTATA, $s['risposta']);
    }

    #[Test]
    public function una_porta_chiusa_non_e_raggiungibile(): void
    {
        $s = (new Bouncer('http://127.0.0.1:' . ServizioFinto::portaChiusa(), 'chiave-di-prova'))->status();

        self::assertFalse($s['reachable']);
        self::assertSame(Bouncer::RISPOSTA_IRRAGGIUNGIBILE, $s['risposta']);
    }

    #[Test]
    public function una_decisione_della_lapi_blocca(): void
    {
        $this->finto = ServizioFinto::avvia(['/v1/decisions' => [200, self::decisione('ban', '203.0.113.21')]]);

        $d = (new Bouncer($this->finto->url(), 'chiave-di-prova'))->checkIp('203.0.113.21');

        self::assertSame('block', $d['action'] ?? null);
        self::assertSame('crowdsecurity/http-probing', $d['scenario'] ?? null);
    }

    #[Test]
    public function chi_non_e_la_lapi_non_produce_decisioni_fail_open(): void
    {
        // Un 200 con un elenco che non è di decisioni: prima diventava una
        // «challenge» con scenario vuoto. Adesso nessuna decisione.
        $this->finto = ServizioFinto::avvia(['/v1/decisions' => [200, '[{"id":1}]']]);
        $bouncer = new Bouncer($this->finto->url(), 'chiave-di-prova');
        self::assertNull($bouncer->checkIp('203.0.113.22'));

        // Il nginx dell'applicazione e la LAPI ferma: fail-open, come sempre.
        $this->finto->ferma();
        $this->finto = ServizioFinto::avvia(['/v1/decisions' => self::NGINX_404]);
        self::assertNull((new Bouncer($this->finto->url(), 'chiave-di-prova'))->checkIp('203.0.113.23'));
        $ferma = new Bouncer('http://127.0.0.1:' . ServizioFinto::portaChiusa(), 'chiave-di-prova');
        self::assertNull($ferma->checkIp('203.0.113.24'));
    }

    #[Test]
    public function la_sola_chiave_non_accende_il_bouncer(): void
    {
        $url = Config::get('waf.crowdsec_lapi_url');
        $chiave = Config::get('waf.crowdsec_lapi_key');
        $env = $_ENV;
        try {
            unset($_ENV['CROWDSEC_LAPI_URL']);
            $_ENV['CROWDSEC_LAPI_KEY'] = 'chiave-di-prova';
            // Il file di configurazione valutato con questo ambiente. La chiave
            // HMAC si dà, così il file non tocca la chiave su disco.
            $_ENV['WAF_HMAC_SECRET'] = str_repeat('x', 40);
            $waf = require \dirname(__DIR__, 4) . '/app/Config/waf.php';
            self::assertSame('', $waf['crowdsec_lapi_url'], 'nessun indirizzo predefinito: era la loopback');

            Config::set('waf.crowdsec_lapi_url', $waf['crowdsec_lapi_url']);
            Config::set('waf.crowdsec_lapi_key', $waf['crowdsec_lapi_key']);
            $b = Bouncer::default();
            self::assertFalse($b->isConfigured());
            self::assertSame(['CROWDSEC_LAPI_URL'], $b->mancano());
            self::assertNull($b->checkIp('203.0.113.25'));
            self::assertFalse($b->status()['configured']);
            self::assertStringContainsString('CROWDSEC_LAPI_URL', (string)$b->status()['error']);
        } finally {
            $_ENV = $env;
            Config::set('waf.crowdsec_lapi_url', $url);
            Config::set('waf.crowdsec_lapi_key', $chiave);
        }
    }

    /**
     * Il pannello del WAF dice che cosa ha risposto, non un «✓» qualunque.
     *
     * @param array<string, mixed> $stato
     */
    private static function pannello(array $stato): string
    {
        $diag_countryPath = '';
        $diag_asnPath = '';
        $diag_envInfo = [];
        $diag_sdkAvail = false;
        $diag_results = [];
        $diag_wafCfg = [];
        $diag_csStatus = $stato;
        $diag_hpStats = [];
        $diag_hpTop = [];
        $diag_tiStats = [];
        $diag_logTail = null;
        $diag_logPath = '';
        ob_start();
        include \dirname(__DIR__, 4) . '/views/admin/waf/_diag_fragment.php';
        return (string)ob_get_clean();
    }

    #[Test]
    public function il_pannello_non_da_raggiungibile_il_nginx_dell_applicazione(): void
    {
        $this->finto = ServizioFinto::avvia(['/v1/decisions' => self::NGINX_404]);
        $html = self::pannello((new Bouncer($this->finto->url(), 'chiave-di-prova'))->status());

        self::assertStringContainsString('risponde qualcosa che non è la LAPI', $html);
        self::assertStringContainsString('HTTP 404', $html);
        self::assertStringNotContainsString('✓ risponde la LAPI', $html);
        self::assertStringNotContainsString('✓ YES', $html);
    }

    #[Test]
    public function il_pannello_da_raggiungibile_la_lapi_e_dice_che_cosa_manca(): void
    {
        $this->finto = ServizioFinto::avvia(['/v1/decisions' => [200, 'null']]);
        $html = self::pannello((new Bouncer($this->finto->url(), 'chiave-di-prova'))->status());
        self::assertStringContainsString('✓ risponde la LAPI', $html);

        $spento = self::pannello((new Bouncer('', 'chiave-di-prova'))->status());
        self::assertStringContainsString('manca CROWDSEC_LAPI_URL', $spento);
        self::assertStringContainsString('non interrogata', $spento);
    }
}
