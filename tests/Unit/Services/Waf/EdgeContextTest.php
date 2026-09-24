<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Waf;

use App\Core\Config;
use App\Services\Waf\EdgeContext;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * L'IP del client lo decide la connessione, non gli header (23/9/2026).
 *
 * `EdgeContext` è il punto da cui passano lista nera, lista bianca, geo-blocco,
 * limitatore e legame del cookie del WAF all'IP. Fino all'audit di giugno 2026
 * il vecchio `clientIp()` prendeva sempre il primo elemento di
 * `X-Forwarded-For` o `CF-Connecting-IP`, cioè un valore scritto dal client: con
 * un header inventato si sceglieva l'IP con cui presentarsi. Nessuna prova lo
 * sorvegliava (revisione architetturale del 23/9/2026, A-24).
 *
 * La regola che il codice dichiara, e che qui si prova nei due versi:
 *   - da una connessione che NON viene da un proxy fidato gli header di
 *     inoltro non contano, nessuno: vale `REMOTE_ADDR`, e il paese è ignoto;
 *   - da un proxy fidato (i range del CDN, `TRUSTED_PROXIES`, o il marcatore
 *     `WAF_EDGE_TRUSTED=1` che mette nginx e non il client) contano, con
 *     quest'ordine: `CF-Connecting-IP`, poi il PRIMO elemento di
 *     `X-Forwarded-For`, poi `REMOTE_ADDR`.
 *
 * `Client-IP` il codice non lo legge proprio: la prova lo manda lo stesso,
 * perché un domani nessuno lo aggiunga fra le fonti senza accorgersene.
 *
 * Indirizzi: quelli dei client sono dei blocchi riservati alla documentazione
 * (192.0.2.0/24, 198.51.100.0/24, 203.0.113.0/24, 2001:db8::/32); quelli del
 * CDN sono dentro i range che il codice elenca da sé.
 *
 * La configurazione dei proxy fidati in più si azzera a ogni prova e si rimette
 * com'era alla fine: `.env.local` di chi lancia la suite non deve cambiare
 * l'esito, e il suo valore non si legge né si stampa.
 */
final class EdgeContextTest extends TestCase
{
    /** Un indirizzo dentro 173.245.48.0/20, il primo range del CDN nell'elenco. */
    private const CDN_V4 = '173.245.48.10';
    /** Un indirizzo dentro 2606:4700::/32. */
    private const CDN_V6 = '2606:4700::6810:1';

    private mixed $configPrima = null;
    private bool $envAveva = false;
    private mixed $envPrima = null;

    protected function setUp(): void
    {
        $this->configPrima = Config::get('waf.trusted_proxies');
        $this->envAveva    = array_key_exists('TRUSTED_PROXIES', $_ENV);
        $this->envPrima    = $_ENV['TRUSTED_PROXIES'] ?? null;
        Config::set('waf.trusted_proxies', '');
        unset($_ENV['TRUSTED_PROXIES']);
    }

    protected function tearDown(): void
    {
        Config::set('waf.trusted_proxies', $this->configPrima);
        if ($this->envAveva) {
            $_ENV['TRUSTED_PROXIES'] = $this->envPrima;
        } else {
            unset($_ENV['TRUSTED_PROXIES']);
        }
    }

    /**
     * Ogni header di inoltro, da solo e tutti insieme, da una connessione
     * diretta: nessuno deve spostare l'IP.
     *
     * @return array<string, array{array<string,string>}>
     */
    public static function headerInventati(): array
    {
        return [
            'X-Forwarded-For'           => [['HTTP_X_FORWARDED_FOR' => '192.0.2.1']],
            'X-Forwarded-For a catena'  => [['HTTP_X_FORWARDED_FOR' => '192.0.2.1, 198.51.100.2, ' . self::CDN_V4]],
            'CF-Connecting-IP'          => [['HTTP_CF_CONNECTING_IP' => '192.0.2.2']],
            'Client-IP'                 => [['HTTP_CLIENT_IP' => '192.0.2.3']],
            'marcatore come header'     => [['HTTP_WAF_EDGE_TRUSTED' => '1', 'HTTP_CF_CONNECTING_IP' => '192.0.2.4']],
            'tutti insieme, col paese'  => [[
                'HTTP_X_FORWARDED_FOR'  => '192.0.2.1',
                'HTTP_CF_CONNECTING_IP' => '192.0.2.2',
                'HTTP_CLIENT_IP'        => '192.0.2.3',
                'HTTP_CF_IPCOUNTRY'     => 'IT',
            ]],
        ];
    }

    /** @param array<string,string> $header */
    #[Test]
    #[DataProvider('headerInventati')]
    public function da_una_connessione_diretta_gli_header_non_contano(array $header): void
    {
        $ctx = EdgeContext::resolve(['REMOTE_ADDR' => '203.0.113.50'] + $header);

        self::assertSame('203.0.113.50', $ctx->ip);
        self::assertFalse($ctx->trustedEdge);
        self::assertNull($ctx->country, 'il paese scritto dal client non vale');
        self::assertSame('203.0.113.50', EdgeContext::clientIp(['REMOTE_ADDR' => '203.0.113.50'] + $header));
    }

    /** @param array<string,string> $header */
    #[Test]
    #[DataProvider('headerInventati')]
    public function lo_stesso_vale_in_ipv6(array $header): void
    {
        $ctx = EdgeContext::resolve(['REMOTE_ADDR' => '2001:db8::50'] + $header);

        self::assertSame('2001:db8::50', $ctx->ip);
        self::assertFalse($ctx->trustedEdge);
    }

    /**
     * Neanche un proxy sulla stessa macchina è fidato, se nessuno lo dichiara:
     * un nginx locale che non è nell'elenco non basta a far valere gli header.
     */
    #[Test]
    public function la_macchina_stessa_non_e_un_proxy_fidato_se_nessuno_lo_dichiara(): void
    {
        foreach (['127.0.0.1', '::1'] as $locale) {
            $ctx = EdgeContext::resolve([
                'REMOTE_ADDR'           => $locale,
                'HTTP_X_FORWARDED_FOR'  => '192.0.2.1',
                'HTTP_CF_CONNECTING_IP' => '192.0.2.2',
            ]);
            self::assertSame($locale, $ctx->ip, $locale);
            self::assertFalse($ctx->trustedEdge, $locale);
        }
    }

    /**
     * I bordi dei range: dentro il /20 e il /32 del CDN sì, un indirizzo più in
     * là no. Un IPv4 scritto in forma IPv6 (`::ffff:…`) non combacia con un range
     * IPv4 e resta non fidato: il verso sicuro.
     */
    #[Test]
    public function solo_gli_indirizzi_dentro_i_range_del_cdn_sono_fidati(): void
    {
        $fidato = static fn(string $remoto): bool => EdgeContext::resolve([
            'REMOTE_ADDR'           => $remoto,
            'HTTP_CF_CONNECTING_IP' => '192.0.2.9',
        ])->trustedEdge;

        self::assertTrue($fidato('173.245.48.0'));
        self::assertTrue($fidato('173.245.63.255'));
        self::assertFalse($fidato('173.245.64.0'));
        self::assertFalse($fidato('173.245.47.255'));
        self::assertTrue($fidato('2606:4700:ffff::1'));
        self::assertFalse($fidato('2606:4701::1'));
        self::assertFalse($fidato('::ffff:173.245.48.10'));
        self::assertFalse($fidato(''));
        self::assertFalse($fidato('non-un-indirizzo'));
    }

    #[Test]
    public function dal_cdn_vale_cf_connecting_ip_prima_di_x_forwarded_for(): void
    {
        $ctx = EdgeContext::resolve([
            'REMOTE_ADDR'           => self::CDN_V4,
            'HTTP_CF_CONNECTING_IP' => '192.0.2.44',
            'HTTP_X_FORWARDED_FOR'  => '198.51.100.7',
            'HTTP_CF_IPCOUNTRY'     => 'it',
        ]);

        self::assertSame('192.0.2.44', $ctx->ip);
        self::assertTrue($ctx->trustedEdge);
        self::assertSame('IT', $ctx->country);
    }

    /**
     * Senza `CF-Connecting-IP`, o con un valore che non è un indirizzo, vale il
     * PRIMO elemento di `X-Forwarded-For`, come dice il codice. Se il primo non
     * è un indirizzo non si passa al secondo: si torna a `REMOTE_ADDR`.
     */
    #[Test]
    public function dal_cdn_di_una_catena_vale_il_primo_elemento(): void
    {
        $dalCdn = static fn(array $h): EdgeContext => EdgeContext::resolve(['REMOTE_ADDR' => self::CDN_V4] + $h);

        self::assertSame('192.0.2.10', $dalCdn([
            'HTTP_X_FORWARDED_FOR' => '192.0.2.10, 198.51.100.20, ' . self::CDN_V4,
        ])->ip);
        self::assertSame('192.0.2.10', $dalCdn([
            'HTTP_CF_CONNECTING_IP' => 'non-un-indirizzo',
            'HTTP_X_FORWARDED_FOR'  => ' 192.0.2.10 ,198.51.100.20',
        ])->ip, 'CF-Connecting-IP non valido: si passa a X-Forwarded-For');
        self::assertSame(self::CDN_V4, $dalCdn([
            'HTTP_X_FORWARDED_FOR' => 'unknown, 198.51.100.20',
        ])->ip, 'primo elemento non valido: REMOTE_ADDR, non il secondo');
        self::assertSame(self::CDN_V4, $dalCdn([])->ip, 'nessun header: REMOTE_ADDR');
        self::assertSame(self::CDN_V4, $dalCdn(['HTTP_CLIENT_IP' => '192.0.2.3'])->ip, 'Client-IP non si legge neanche dal CDN');
    }

    #[Test]
    public function dal_cdn_in_ipv6_e_catene_miste(): void
    {
        $ctx = EdgeContext::resolve([
            'REMOTE_ADDR'           => self::CDN_V6,
            'HTTP_CF_CONNECTING_IP' => '2001:db8::1234',
        ]);
        self::assertSame('2001:db8::1234', $ctx->ip);
        self::assertTrue($ctx->trustedEdge);

        self::assertSame('2001:db8::5', EdgeContext::resolve([
            'REMOTE_ADDR'          => self::CDN_V6,
            'HTTP_X_FORWARDED_FOR' => '2001:db8::5, 192.0.2.1',
        ])->ip);
        self::assertSame('192.0.2.1', EdgeContext::resolve([
            'REMOTE_ADDR'          => self::CDN_V4,
            'HTTP_X_FORWARDED_FOR' => '192.0.2.1, 2001:db8::5',
        ])->ip);
    }

    /** «XX» e «T1» sono il paese ignoto e Tor del CDN: si trattano come ignoti. */
    #[Test]
    public function i_paesi_ignoti_del_cdn_restano_ignoti(): void
    {
        foreach (['XX', 'T1', 'xx', '', '  '] as $codice) {
            $ctx = EdgeContext::resolve([
                'REMOTE_ADDR'       => self::CDN_V4,
                'HTTP_CF_IPCOUNTRY' => $codice,
            ]);
            self::assertNull($ctx->country, "«{$codice}»");
        }
    }

    /**
     * Il marcatore di nginx è una variabile del server, non un header: vale solo
     * se è esattamente «1».
     */
    #[Test]
    public function il_marcatore_del_server_rende_fidato_il_bordo(): void
    {
        $base = [
            'REMOTE_ADDR'           => '203.0.113.50',
            'HTTP_CF_CONNECTING_IP' => '192.0.2.44',
            'HTTP_CF_IPCOUNTRY'     => 'IT',
        ];

        $ctx = EdgeContext::resolve($base + ['WAF_EDGE_TRUSTED' => '1']);
        self::assertTrue($ctx->trustedEdge);
        self::assertSame('192.0.2.44', $ctx->ip);
        self::assertSame('IT', $ctx->country);

        foreach (['0', 'true', 'yes', ''] as $valore) {
            $ctx = EdgeContext::resolve($base + ['WAF_EDGE_TRUSTED' => $valore]);
            self::assertFalse($ctx->trustedEdge, "WAF_EDGE_TRUSTED={$valore}");
            self::assertSame('203.0.113.50', $ctx->ip, "WAF_EDGE_TRUSTED={$valore}");
        }
    }

    /**
     * `TRUSTED_PROXIES` aggiunge proxy fidati, in CIDR o come indirizzo esatto;
     * vale sia dalla configurazione sia, se quella è vuota, dall'ambiente.
     */
    #[Test]
    public function i_proxy_dichiarati_in_trusted_proxies_sono_fidati_e_gli_altri_no(): void
    {
        $da = static fn(string $remoto): EdgeContext => EdgeContext::resolve([
            'REMOTE_ADDR'          => $remoto,
            'HTTP_X_FORWARDED_FOR' => '192.0.2.77',
        ]);

        self::assertSame('198.51.100.7', $da('198.51.100.7')->ip, 'prima della dichiarazione');

        Config::set('waf.trusted_proxies', ' 198.51.100.0/24 , 10.0.0.1 ');
        self::assertSame('192.0.2.77', $da('198.51.100.7')->ip);
        self::assertSame('192.0.2.77', $da('10.0.0.1')->ip);
        self::assertSame('10.0.0.2', $da('10.0.0.2')->ip, 'indirizzo esatto: il vicino no');
        self::assertSame('198.51.101.1', $da('198.51.101.1')->ip, 'fuori dal /24');

        Config::set('waf.trusted_proxies', '');
        $_ENV['TRUSTED_PROXIES'] = '198.51.100.0/24';
        self::assertSame('192.0.2.77', $da('198.51.100.7')->ip, 'dall\'ambiente');
    }

    #[Test]
    public function senza_un_remote_addr_valido_l_ip_e_quello_nullo(): void
    {
        foreach ([[], ['REMOTE_ADDR' => ''], ['REMOTE_ADDR' => 'boh']] as $server) {
            $ctx = EdgeContext::resolve($server + ['HTTP_X_FORWARDED_FOR' => '192.0.2.1']);
            self::assertSame('0.0.0.0', $ctx->ip);
            self::assertFalse($ctx->trustedEdge);
        }
    }
}
