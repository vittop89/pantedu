<?php

declare(strict_types=1);

namespace Tests\Unit\Controllers;

use App\Controllers\Public\PublicTakedownController;
use App\Core\Config;
use App\Core\Request;
use App\Services\Gdpr\TakedownRequestService;
use App\Support\ImprontaIp;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Una segnalazione di rimozione non tiene l'IP in chiaro (24/9/2026,
 * migrazione 141).
 *
 * `takedown_requests.submitter_ip` conservava per cinque anni l'IP di chi
 * segnalava, e lo mandava nell'email all'amministratore. Ora ci va l'impronta
 * con chiave (ImprontaIp), come negli altri registri: lo stesso indirizzo si
 * riconosce, non si legge.
 *
 * Nei due versi: al servizio arriva l'impronta dell'indirizzo, uguale per lo
 * stesso indirizzo e diversa per un altro; mai l'indirizzo.
 */
final class SegnalazioneSenzaIpInChiaroTest extends TestCase
{
    private const SEGRETO = 'segreto-di-prova-per-le-segnalazioni-0123456789abcdef';

    /** @var array<string, mixed> */
    private array $serverPrima = [];
    /** @var array<string, mixed> */
    private array $postPrima = [];
    private mixed $segretoPrima = null;
    private mixed $originePrima = null;

    protected function setUp(): void
    {
        $this->serverPrima = $_SERVER;
        $this->postPrima = $_POST;
        $this->segretoPrima = Config::get('waf.hmac_secret');
        $this->originePrima = Config::get('waf.hmac_secret_dall_ambiente');
        Config::set('waf.hmac_secret', self::SEGRETO);
        Config::set('waf.hmac_secret_dall_ambiente', true);
    }

    protected function tearDown(): void
    {
        $_SERVER = $this->serverPrima;
        $_POST = $this->postPrima;
        Config::set('waf.hmac_secret', $this->segretoPrima);
        Config::set('waf.hmac_secret_dall_ambiente', $this->originePrima);
    }

    /** @return array<string, mixed> i dati che il controller passa al servizio */
    private function segnalaDa(string $ip): array
    {
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_SERVER['REQUEST_URI'] = '/segnalazione-contenuti';
        $_SERVER['REMOTE_ADDR'] = $ip;
        $_POST = [
            'submitter_name' => 'Prova',
            'submitter_email' => 'prova@example.invalid',
            'submitter_role' => 'private',
            'content_ref' => '/studio/prova',
            'violation_type' => 'other',
            'description' => 'Una descrizione abbastanza lunga per la prova.',
        ];
        $servizio = new class () extends TakedownRequestService {
            /** @var array<string, mixed> */
            public array $ricevuto = [];

            public function submit(array $data): int
            {
                $this->ricevuto = $data;
                return 1;
            }
        };
        (new PublicTakedownController($servizio, null))->submit(new Request(''));
        return $servizio->ricevuto;
    }

    #[Test]
    public function al_servizio_arriva_l_impronta_e_non_l_indirizzo(): void
    {
        $dati = $this->segnalaDa('203.0.113.9');

        self::assertNotSame('', (string)($dati['submitter_ip'] ?? ''), 'il controller ha chiamato il servizio');
        self::assertNotSame('203.0.113.9', $dati['submitter_ip']);
        self::assertSame(ImprontaIp::esadecimale('203.0.113.9'), $dati['submitter_ip']);
        self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', (string)$dati['submitter_ip']);
    }

    #[Test]
    public function lo_stesso_indirizzo_si_riconosce_un_altro_no(): void
    {
        $a = $this->segnalaDa('203.0.113.9')['submitter_ip'] ?? null;
        $b = $this->segnalaDa('203.0.113.9')['submitter_ip'] ?? null;
        $c = $this->segnalaDa('198.51.100.7')['submitter_ip'] ?? null;

        self::assertSame($a, $b);
        self::assertNotSame($a, $c);
    }
}
