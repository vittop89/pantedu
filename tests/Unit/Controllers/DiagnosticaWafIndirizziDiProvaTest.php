<?php

declare(strict_types=1);

namespace Tests\Unit\Controllers;

use App\Controllers\Admin\WafAdminController;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Il «Test lookup» della diagnostica del WAF prova chi guarda la pagina, non
 * un indirizzo scritto nel codice (23/9/2026, A-44).
 *
 * Accanto ai due risolutori pubblici c'era un indirizzo personale del
 * manutentore, non più attuale: la tabella diceva come il GeoIP vedeva un
 * indirizzo di ieri, e il codice lo pubblicava in ogni copia del repository.
 * Adesso il terzo è l'indirizzo della richiesta, ricostruito da EdgeContext
 * come per il blocco geografico.
 *
 * Le prove chiedono l'elenco **esatto**: un indirizzo fisso rimesso accanto
 * agli altri le fa fallire, e anche un elenco che perdesse quello di chi
 * guarda. Gli indirizzi qui sono dei blocchi riservati alla documentazione
 * (RFC 5737), più un indirizzo di Cloudflare come proxy fidato.
 *
 * Dall'aiutante alla vista: `righeDelTestLookup()` costruisce le righe solo
 * dagli indirizzi dell'aiutante, e la vista resa con quelle righe mostra
 * quelli e nessun altro. Il limite: `diagData()`, che le passa alla vista,
 * non gira qui, perché interroga il database, l'API di CrowdSec e il DNS
 * inverso. Di lei si guarda il testo: nessun indirizzo scritto, e la tabella
 * presa dall'aiutante. Un indirizzo che ci arrivasse per un'altra via (dalla
 * configurazione, o composto a pezzi) quella prova non lo vede.
 */
final class DiagnosticaWafIndirizziDiProvaTest extends TestCase
{
    private const RISOLUTORI = [
        '8.8.8.8' => 'risolutore pubblico',
        '1.1.1.1' => 'risolutore pubblico',
    ];

    #[Test]
    public function il_terzo_indirizzo_e_quello_di_chi_guarda(): void
    {
        $this->assertSame(
            self::RISOLUTORI + ['203.0.113.7' => 'il tuo indirizzo'],
            WafAdminController::indirizziDiProva(['REMOTE_ADDR' => '203.0.113.7'])
        );
    }

    #[Test]
    public function dietro_cloudflare_vale_l_indirizzo_ricostruito_e_non_quello_del_proxy(): void
    {
        $this->assertSame(
            self::RISOLUTORI + ['198.51.100.23' => 'il tuo indirizzo'],
            WafAdminController::indirizziDiProva([
                'REMOTE_ADDR'           => '173.245.48.1',
                'HTTP_CF_CONNECTING_IP' => '198.51.100.23',
            ])
        );
    }

    #[Test]
    public function un_intestazione_inventata_da_chi_non_e_un_proxy_non_conta(): void
    {
        // Lo stesso criterio del blocco geografico: da un indirizzo non fidato
        // CF-Connecting-IP si ignora, e la tabella mostra chi si collega davvero.
        $this->assertSame(
            self::RISOLUTORI + ['203.0.113.7' => 'il tuo indirizzo'],
            WafAdminController::indirizziDiProva([
                'REMOTE_ADDR'           => '203.0.113.7',
                'HTTP_CF_CONNECTING_IP' => '198.51.100.23',
            ])
        );
    }

    #[Test]
    public function senza_un_indirizzo_valido_restano_i_due_risolutori(): void
    {
        // EdgeContext risponde 0.0.0.0 in tutti e due i casi: in tabella
        // sembrerebbe un indirizzo vero.
        $this->assertSame(self::RISOLUTORI, WafAdminController::indirizziDiProva([]));
        $this->assertSame(
            self::RISOLUTORI,
            WafAdminController::indirizziDiProva(['REMOTE_ADDR' => 'non-un-indirizzo'])
        );
    }

    /**
     * Le righe della tabella con una ricerca finta, e gli indirizzi per cui
     * la ricerca è stata chiesta.
     *
     * @param array<string,mixed> $server
     * @return array{
     *     0: list<string>,
     *     1: array<string, array{nota: string, country: ?string, enrich: array<string,mixed>}>
     * }
     */
    private static function interroga(array $server): array
    {
        $chiesti = [];
        $righe = WafAdminController::righeDelTestLookup(
            $server,
            static function (string $ip) use (&$chiesti): array {
                $chiesti[] = $ip;
                return ['country' => 'ZZ', 'enrich' => ['rdns' => "rdns-di-$ip", 'asn' => 64500, 'org' => 'prova']];
            }
        );
        return [$chiesti, $righe];
    }

    #[Test]
    public function la_tabella_ha_una_riga_per_ogni_indirizzo_dell_aiutante_e_nessun_altra(): void
    {
        $server = ['REMOTE_ADDR' => '203.0.113.7'];
        $attesi = WafAdminController::indirizziDiProva($server);

        [$chiesti, $righe] = self::interroga($server);

        $this->assertSame(
            array_keys($attesi),
            $chiesti,
            'la ricerca si fa per gli indirizzi dell\'aiutante, e solo per quelli'
        );
        $this->assertSame(array_keys($attesi), array_keys($righe));
        foreach ($attesi as $ip => $nota) {
            $this->assertSame($nota, $righe[$ip]['nota']);
            $this->assertSame('ZZ', $righe[$ip]['country']);
            $this->assertSame("rdns-di-$ip", $righe[$ip]['enrich']['rdns']);
        }
    }

    #[Test]
    public function la_vista_mostra_solo_gli_indirizzi_dell_aiutante(): void
    {
        $server = ['REMOTE_ADDR' => '198.51.100.23'];
        [, $righe] = self::interroga($server);

        $html = self::rendiLaDiagnostica($righe);
        $inizio = strpos($html, 'Test lookup</h3>');
        $this->assertNotFalse($inizio, 'la vista ha la tabella «Test lookup»');
        $tabella = substr($html, $inizio, (int)strpos($html, '</table>', $inizio) - $inizio);
        preg_match_all('#<td><code>([^<]*)</code>#', $tabella, $m);

        $this->assertSame(array_keys(WafAdminController::indirizziDiProva($server)), $m[1]);
        $this->assertStringContainsString('il tuo indirizzo', $tabella);
    }

    #[Test]
    public function diag_data_prende_la_tabella_dall_aiutante_e_non_scrive_indirizzi(): void
    {
        $metodo = new \ReflectionMethod(WafAdminController::class, 'diagData');
        $righe = file((string)$metodo->getFileName());
        $this->assertIsArray($righe);
        $corpo = implode('', \array_slice(
            $righe,
            $metodo->getStartLine() - 1,
            $metodo->getEndLine() - $metodo->getStartLine() + 1
        ));

        $ipv4 = '/\b\d{1,3}(?:\.\d{1,3}){3}\b/';
        $ipv6 = '/[\'"][0-9a-fA-F:]*::[0-9a-fA-F:]*[\'"]/';
        $this->assertDoesNotMatchRegularExpression($ipv4, $corpo, 'un indirizzo IPv4 scritto in diagData()');
        $this->assertDoesNotMatchRegularExpression($ipv6, $corpo, 'un indirizzo IPv6 scritto in diagData()');
        $this->assertSame(
            1,
            preg_match_all('/\$results\s*=\s*self::righeDelTestLookup\(/', $corpo),
            'la tabella viene dall\'aiutante'
        );
        $this->assertSame(
            2,
            preg_match_all('/\$results\b/', $corpo),
            'e $results compare solo lì e nella consegna alla vista'
        );
        $this->assertMatchesRegularExpression('/\'diag_results\'\s*=>\s*\$results\b/', $corpo);
    }

    /**
     * La vista del pannello con le righe date e tutto il resto vuoto.
     *
     * @param array<string, array<string,mixed>> $righe
     */
    private static function rendiLaDiagnostica(array $righe): string
    {
        $vista = \dirname(__DIR__, 3) . '/views/admin/waf/_diag_fragment.php';
        $rendi = static function (string $vista, array $variabili): string {
            extract($variabili);
            ob_start();
            try {
                require $vista;
            } finally {
                $html = (string)ob_get_clean();
            }
            return $html;
        };
        return $rendi($vista, [
            'diag_countryPath' => '',
            'diag_asnPath'     => '',
            'diag_envInfo'     => [],
            'diag_sdkAvail'    => false,
            'diag_results'     => $righe,
            'diag_wafCfg'      => [],
            'diag_csStatus'    => ['configured' => false, 'reachable' => false, 'error' => null],
            'diag_hpStats'     => [],
            'diag_hpTop'       => [],
            'diag_tiStats'     => [],
            'diag_logTail'     => null,
            'diag_logPath'     => '/non/esiste',
        ]);
    }
}
