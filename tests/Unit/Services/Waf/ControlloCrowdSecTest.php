<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Waf;

use App\Services\Waf\ControlloCrowdSec;
use App\Services\Waf\WafCrowdSecBouncerService as Bouncer;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Le risposte del controllo `crowdsec` della diagnostica, a partire dallo stato
 * del bouncer (23/9/2026). La sonda vera, con servizi finti, sta in
 * CrowdSecLapiTest e in DiagnosticaCrowdSecTest; qui la regola dell'host.
 *
 * Sull'host di un'installazione a container 127.0.0.1:8080 è la LAPI, e dal
 * container è il nginx dell'applicazione. Una sonda fatta dall'host su quella
 * loopback risponderebbe bene mentre il bouncer parla con il sito: è lo stesso
 * inganno del TeX dall'8 al 19 settembre 2026. Quindi lì la loopback è un
 * guasto, qualunque cosa risponda — e solo lì.
 */
final class ControlloCrowdSecTest extends TestCase
{
    /** @return array{configured: bool, mancano: list<string>, risposta: ?string, http: int, tipo: string, servizio: string, error: ?string} */
    private static function stato(string $risposta, int $http = 200): array
    {
        return [
            'configured' => true,
            'mancano'    => [],
            'risposta'   => $risposta,
            'http'       => $http,
            'tipo'       => 'application/json; charset=utf-8',
            'servizio'   => '127.0.0.1:8080',
            'error'      => null,
        ];
    }

    #[Test]
    public function sull_host_di_un_container_la_loopback_e_un_guasto_anche_se_risponde_la_lapi(): void
    {
        $r = ControlloCrowdSec::esito(self::stato(Bouncer::RISPOSTA_LAPI), 'http://127.0.0.1:8080', 'dall’host', true);

        self::assertSame('guasto', $r['esito']);
        self::assertStringContainsString('loopback', $r['prova']);
        self::assertStringContainsString('docker/nginx.conf', $r['prova']);
    }

    #[Test]
    public function altrove_la_loopback_che_risponde_come_la_lapi_regge(): void
    {
        // Nell'assetto di ripiego (PHP sull'host, niente upstream del container)
        // la loopback è giusta; su una macchina di sviluppo anche.
        $r = ControlloCrowdSec::esito(self::stato(Bouncer::RISPOSTA_LAPI), 'http://127.0.0.1:8080', 'dall’host', false);

        self::assertSame('regge', $r['esito']);
    }

    #[Test]
    public function sull_host_di_un_container_un_indirizzo_del_bridge_conta_la_risposta(): void
    {
        $stato = self::stato(Bouncer::RISPOSTA_LAPI);
        $stato['servizio'] = '172.17.0.1:8080';

        $r = ControlloCrowdSec::esito($stato, 'http://172.17.0.1:8080', 'dall’host', true);

        self::assertSame('regge', $r['esito']);
        self::assertStringContainsString('8-bis', $r['prova'], 'dice dove si vede il firewall');
    }

    #[Test]
    public function il_nginx_dell_applicazione_dal_container_e_un_guasto(): void
    {
        $stato = self::stato(Bouncer::RISPOSTA_NON_LAPI, 404);
        $stato['tipo'] = 'text/html; charset=UTF-8';

        $r = ControlloCrowdSec::esito($stato, 'http://127.0.0.1:8080', 'dal container', false);

        self::assertSame('guasto', $r['esito']);
        self::assertStringContainsString('non è la LAPI (HTTP 404, text/html', $r['prova']);
    }

    #[Test]
    public function chiave_rifiutata_e_nessuna_risposta_sono_guasti(): void
    {
        $stato = self::stato(Bouncer::RISPOSTA_CHIAVE_RIFIUTATA, 403);
        $rifiutata = ControlloCrowdSec::esito($stato, 'http://10.0.0.1:8080', 'da qui', false);
        $muta = self::stato(Bouncer::RISPOSTA_IRRAGGIUNGIBILE, 0);
        $muta['error'] = 'cURL 7: Connection refused';
        $ferma = ControlloCrowdSec::esito($muta, 'http://10.0.0.1:8080', 'da qui', false);

        self::assertSame('guasto', $rifiutata['esito']);
        self::assertStringContainsString('rifiuta la chiave', $rifiutata['prova']);
        self::assertSame('guasto', $ferma['esito']);
        self::assertStringContainsString('cURL 7', $ferma['prova']);
    }

    #[Test]
    public function spento_non_si_applica_configurato_a_meta_e_un_guasto(): void
    {
        $spento = ['configured' => false, 'mancano' => ['CROWDSEC_LAPI_URL', 'CROWDSEC_LAPI_KEY'], 'risposta' => null,
            'http' => 0, 'tipo' => '', 'servizio' => '', 'error' => null];
        $meta = ['mancano' => ['CROWDSEC_LAPI_URL']] + $spento;

        self::assertSame('non_applicabile', ControlloCrowdSec::esito($spento, '', 'da qui', true)['esito']);
        $r = ControlloCrowdSec::esito($meta, '', 'da qui', true);
        self::assertSame('guasto', $r['esito']);
        self::assertStringContainsString('manca CROWDSEC_LAPI_URL', $r['prova']);
    }

    #[Test]
    public function riconosce_la_loopback(): void
    {
        foreach (['http://127.0.0.1:8080', 'http://localhost:8080', 'http://[::1]:8080', 'http://127.1.2.3'] as $u) {
            self::assertTrue(ControlloCrowdSec::loopback($u), $u);
        }
        foreach (['http://172.17.0.1:8080', 'http://host.docker.internal:8080', 'http://10.0.0.5', ''] as $u) {
            self::assertFalse(ControlloCrowdSec::loopback($u), $u);
        }
    }
}
