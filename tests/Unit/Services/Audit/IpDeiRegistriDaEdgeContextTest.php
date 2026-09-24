<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Audit;

use App\Core\AccessLogger;
use App\Core\Config;
use App\Services\Audit\RequestFingerprint;
use App\Support\ImprontaIp;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * L'IP dei registri è quello che decide EdgeContext (23/9/2026).
 *
 * Fino a oggi `RequestFingerprint` (da cui passano `audit_activity_log`,
 * `content_action_log`, `privileged_access_log`) e `AccessLogger`
 * (`access_log.json`) prendevano `Client-IP`, poi `X-Forwarded-For`, e solo
 * per ultimo REMOTE_ADDR. I primi due li sceglie il client, e nginx li lascia
 * passare: chiunque poteva mettere nei registri l'hash di un indirizzo a
 * piacere (revisione architetturale 2026-09, A-63). Limitatore, blocchi per
 * brute force e WAF usavano già EdgeContext, che crede agli header di
 * forwarding solo se la connessione arriva da un proxy fidato.
 *
 * Provato nei due versi: da un indirizzo qualunque gli header inventati non
 * cambiano niente; da un proxy fidato `CF-Connecting-IP` sì, come EdgeContext
 * dichiara. Senza il secondo verso, «ignorare sempre gli header» passerebbe
 * la prova — e dietro il CDN registrerebbe l'indirizzo del CDN per tutti.
 *
 * Indirizzi dei blocchi riservati alla documentazione (RFC 5737): il proxy
 * fidato è 198.51.100.0/24, dichiarato per la prova in `waf.trusted_proxies`.
 *
 * L'impronta dell'indirizzo, dal 24/9/2026, è quella con chiave di
 * `ImprontaIp` (la sua prova è `tests/Unit/Support/ImprontaIpTest.php`): qui
 * si guarda di quale indirizzo è, con un segreto fissato dalla prova perché
 * due null non passino per due impronte uguali.
 */
final class IpDeiRegistriDaEdgeContextTest extends TestCase
{
    private const CLIENT = '192.0.2.10';
    private const INVENTATO = '203.0.113.66';
    private const ALTRO_INVENTATO = '203.0.113.77';
    private const PROXY_FIDATO = '198.51.100.7';
    private const DIETRO_IL_PROXY = '192.0.2.44';

    private mixed $proxyFidatiPrima = null;
    private mixed $segretoPrima = null;
    private string $cartella = '';

    protected function setUp(): void
    {
        $this->proxyFidatiPrima = Config::get('waf.trusted_proxies');
        Config::set('waf.trusted_proxies', '198.51.100.0/24');
        $this->segretoPrima = Config::get('waf.hmac_secret');
        Config::set('waf.hmac_secret', 'segreto-di-prova-per-gli-ip-dei-registri-0123456789');
        foreach (['HTTP_CLIENT_IP', 'HTTP_X_FORWARDED_FOR', 'HTTP_CF_CONNECTING_IP', 'WAF_EDGE_TRUSTED'] as $k) {
            unset($_SERVER[$k]);
        }
    }

    protected function tearDown(): void
    {
        Config::set('waf.trusted_proxies', $this->proxyFidatiPrima);
        Config::set('waf.hmac_secret', $this->segretoPrima);
        if ($this->cartella !== '') {
            foreach (glob($this->cartella . '/*') ?: [] as $f) {
                @unlink($f);
            }
            @rmdir($this->cartella);
        }
    }

    #[Test]
    public function gli_header_inventati_non_cambiano_l_ip_ne_il_suo_hash(): void
    {
        $_SERVER['REMOTE_ADDR'] = self::CLIENT;
        $_SERVER['HTTP_CLIENT_IP'] = self::INVENTATO;
        $_SERVER['HTTP_X_FORWARDED_FOR'] = self::ALTRO_INVENTATO . ', ' . self::CLIENT;

        self::assertSame(self::CLIENT, RequestFingerprint::clientIp());
        self::assertNotNull(RequestFingerprint::ipHash());
        self::assertSame(ImprontaIp::di(self::CLIENT), RequestFingerprint::ipHash(),
            "l'impronta nei registri è quella dell'indirizzo di connessione");
    }

    #[Test]
    public function da_un_proxy_fidato_vale_cf_connecting_ip(): void
    {
        $_SERVER['REMOTE_ADDR'] = self::PROXY_FIDATO;
        $_SERVER['HTTP_CF_CONNECTING_IP'] = self::DIETRO_IL_PROXY;
        $_SERVER['HTTP_CLIENT_IP'] = self::INVENTATO;

        self::assertSame(self::DIETRO_IL_PROXY, RequestFingerprint::clientIp());
        self::assertNotNull(RequestFingerprint::ipHash());
        self::assertSame(ImprontaIp::di(self::DIETRO_IL_PROXY), RequestFingerprint::ipHash());
    }

    #[Test]
    public function senza_connessione_non_c_e_ip_anche_con_gli_header(): void
    {
        // Da riga di comando REMOTE_ADDR non c'è: un header rimasto in
        // $_SERVER non deve diventare l'indirizzo di nessuno.
        unset($_SERVER['REMOTE_ADDR']);
        $_SERVER['HTTP_CLIENT_IP'] = self::INVENTATO;

        self::assertNull(RequestFingerprint::clientIp());
        self::assertNull(RequestFingerprint::ipHash());
    }

    #[Test]
    public function un_ip_passato_dal_chiamante_resta_quello(): void
    {
        // TeacherRecoveryService passa l'indirizzo da sé: la regola nuova
        // riguarda solo quello della richiesta corrente.
        $_SERVER['REMOTE_ADDR'] = self::CLIENT;
        self::assertNotNull(RequestFingerprint::ipHash(self::DIETRO_IL_PROXY));
        self::assertSame(ImprontaIp::di(self::DIETRO_IL_PROXY),
            RequestFingerprint::ipHash(self::DIETRO_IL_PROXY));
        self::assertNull(RequestFingerprint::ipHash(null));
    }

    #[Test]
    public function il_registro_degli_accessi_scrive_lo_stesso_ip(): void
    {
        // Da `ip_address` di access_log.json l'analisi delle anomalie decide
        // chi bloccare: con un indirizzo inventato si faceva bloccare un altro.
        $_SERVER['REMOTE_ADDR'] = self::CLIENT;
        $_SERVER['HTTP_CLIENT_IP'] = self::INVENTATO;
        $_SERVER['HTTP_X_FORWARDED_FOR'] = self::ALTRO_INVENTATO;
        $registro = $this->registro();
        $registro->logAccess('zz_ip_prova', 'teacher', '/studio', 'access');

        $_SERVER['REMOTE_ADDR'] = self::PROXY_FIDATO;
        $_SERVER['HTTP_CF_CONNECTING_IP'] = self::DIETRO_IL_PROXY;
        $registro->logAccess('zz_ip_prova', 'teacher', '/studio', 'access');

        $voci = $this->voci();
        self::assertSame(self::CLIENT, $voci[0]['ip_address'], 'gli header inventati non contano');
        self::assertSame(self::DIETRO_IL_PROXY, $voci[1]['ip_address'], 'da un proxy fidato sì');
    }

    private function registro(): AccessLogger
    {
        $this->cartella = sys_get_temp_dir() . '/pantedu_ip_registri_' . bin2hex(random_bytes(4));
        return new AccessLogger($this->cartella);
    }

    /** @return list<array<string, mixed>> */
    private function voci(): array
    {
        $voci = json_decode((string)file_get_contents($this->cartella . '/access_log.json'), true);
        self::assertIsArray($voci);
        return array_values($voci);
    }
}
