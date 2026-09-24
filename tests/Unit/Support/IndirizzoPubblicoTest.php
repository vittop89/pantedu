<?php
declare(strict_types=1);

namespace Tests\Unit\Support;

use App\Core\Config;
use App\Support\Anomalia;
use App\Support\IndirizzoPubblico;
use App\Support\IndirizzoPubblicoMancante;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * La radice degli indirizzi assoluti: `app.url`, e basta.
 *
 * Fino al 14/9/2026 si prendeva dalla richiesta; da allora prima `APP_URL`,
 * poi la richiesta; dal 23/9/2026 (rilievo A-13) solo `APP_URL`: senza, né il
 * dominio di produzione né l'intestazione `Host`, ma un'anomalia registrata e
 * un'eccezione (o null, per i collegamenti di cortesia).
 */
final class IndirizzoPubblicoTest extends TestCase
{
    private string $cartella;
    private mixed $logsPrima;
    private mixed $appUrlPrima;
    private string|false $errorLogPrima;

    protected function setUp(): void
    {
        // Il registro delle anomalie e error_log in una cartella della prova:
        // quelli veri dell'istanza non si sporcano.
        $this->cartella = sys_get_temp_dir() . '/pantedu-indirizzo-pubblico-' . bin2hex(random_bytes(6));
        mkdir($this->cartella, 0700, true);
        $this->logsPrima = Config::get('app.paths.logs');
        $this->appUrlPrima = Config::get('app.url');
        Config::set('app.paths.logs', $this->cartella);
        $this->errorLogPrima = ini_set('error_log', $this->cartella . '/php_errors.log');
    }

    protected function tearDown(): void
    {
        Config::set('app.paths.logs', $this->logsPrima);
        Config::set('app.url', $this->appUrlPrima);
        ini_set('error_log', $this->errorLogPrima === false ? '' : $this->errorLogPrima);
        unset($_SERVER['HTTP_HOST']);
        foreach (glob($this->cartella . '/{,.}*', GLOB_BRACE) ?: [] as $f) {
            if (is_file($f)) {
                @unlink($f);
            }
        }
        @rmdir($this->cartella);
    }

    /** @return list<array<string, mixed>> */
    private function anomalie(): array
    {
        return array_values(array_filter(
            Anomalia::recenti(),
            static fn(array $v): bool => ($v['codice'] ?? '') === IndirizzoPubblico::ANOMALIA,
        ));
    }

    #[Test]
    public function con_app_url_la_porta_non_si_perde_e_la_barra_finale_si_toglie(): void
    {
        // Il caso della CI del 14/9: nginx passa HTTP_HOST senza porta.
        $_SERVER['HTTP_HOST'] = '127.0.0.1';
        self::assertSame('http://127.0.0.1:44487', IndirizzoPubblico::radice('prova', 'http://127.0.0.1:44487/'));
        self::assertSame('https://scuola.example/pantedu', IndirizzoPubblico::radice('prova', ' https://scuola.example/pantedu/ '));
        self::assertSame([], $this->anomalie(), 'con la radice configurata nessuna anomalia');
    }

    #[Test]
    public function si_legge_app_url_della_configurazione(): void
    {
        Config::set('app.url', 'https://altra-istanza.example');
        self::assertSame('https://altra-istanza.example', IndirizzoPubblico::radice('prova'));
        self::assertSame('https://altra-istanza.example', IndirizzoPubblico::radiceSeConfigurata('prova'));
    }

    #[Test]
    public function l_intestazione_host_non_conta_ne_con_app_url_ne_senza(): void
    {
        // È la via dell'avvelenamento dei collegamenti di reset: l'Host lo
        // scrive chi fa la richiesta.
        $_SERVER['HTTP_HOST'] = 'sito-di-chi-attacca.example';
        self::assertSame('https://istanza.example', IndirizzoPubblico::radice('prova', 'https://istanza.example'));

        $this->expectException(IndirizzoPubblicoMancante::class);
        IndirizzoPubblico::radice('prova', '');
    }

    #[Test]
    public function senza_app_url_niente_ripiego_ma_un_anomalia_e_un_eccezione(): void
    {
        try {
            IndirizzoPubblico::radice('recupero_password', '   ');
            self::fail('senza app.url la radice non deve esistere');
        } catch (IndirizzoPubblicoMancante $e) {
            self::assertStringContainsString('recupero_password', $e->getMessage());
            self::assertStringNotContainsStringIgnoringCase('pantedu.eu', $e->getMessage());
        }

        $anomalie = $this->anomalie();
        self::assertCount(1, $anomalie, 'una riga indirizzo_pubblico_mancante nel registro');
        self::assertSame('recupero_password', $anomalie[0]['dettagli']['flusso'] ?? null);
        self::assertSame('vuota', $anomalie[0]['dettagli']['motivo'] ?? null);

        $errori = (string) @file_get_contents($this->cartella . '/php_errors.log');
        self::assertStringContainsString('[indirizzo_pubblico] app.url vuota', $errori);
    }

    /** @return array<string, array{0: string}> */
    public static function valoriNonValidi(): array
    {
        return [
            'senza schema'          => ['www.scuola.example'],
            'schema sbagliato'      => ['ftp://scuola.example'],
            'javascript'            => ['javascript:alert(1)'],
            'con credenziali'       => ['https://utente:segreto@scuola.example'],
            'con parametri'         => ['https://scuola.example/?a=1'],
            'con frammento'         => ['https://scuola.example/#x'],
            'con spazi in mezzo'    => ['https://scuola .example'],
            'solo schema'           => ['https://'],
        ];
    }

    #[Test]
    #[DataProvider('valoriNonValidi')]
    public function un_valore_che_non_e_un_indirizzo_vale_come_assente(string $valore): void
    {
        try {
            IndirizzoPubblico::radice('prova', $valore);
            self::fail("«{$valore}» non è una radice");
        } catch (IndirizzoPubblicoMancante $e) {
            // Il valore non si ripete: né nel messaggio, né nel registro.
            self::assertStringNotContainsString($valore, $e->getMessage());
        }
        $anomalie = $this->anomalie();
        self::assertCount(1, $anomalie);
        self::assertSame('non valida', $anomalie[0]['dettagli']['motivo'] ?? null);
        self::assertStringNotContainsString($valore, (string) json_encode($anomalie[0], JSON_UNESCAPED_SLASHES));
    }

    #[Test]
    public function per_i_collegamenti_di_cortesia_null_e_l_anomalia(): void
    {
        self::assertNull(IndirizzoPubblico::radiceSeConfigurata('secondo_fattore', ''));
        $anomalie = $this->anomalie();
        self::assertCount(1, $anomalie);
        self::assertSame('secondo_fattore', $anomalie[0]['dettagli']['flusso'] ?? null);
    }

    #[Test]
    public function l_intestazione_delle_chiamate_esterne_dice_l_istanza_o_niente(): void
    {
        self::assertSame(
            'pantedu-waf/25.J (+https://istanza.example)',
            IndirizzoPubblico::agente('pantedu-waf/25.J', 'https://istanza.example/'),
        );
        self::assertSame('pantedu-waf/25.J', IndirizzoPubblico::agente('pantedu-waf/25.J', ''));
        self::assertSame([], $this->anomalie(), 'un User-Agent senza indirizzo non è un\'anomalia');
    }
}
