<?php

declare(strict_types=1);

namespace Tests\Unit\Config;

use App\Core\Config;
use App\Support\ViteManifest;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Un modo solo di leggere gli interruttori dall'ambiente (23/9/2026).
 *
 * Il difetto (revisione architetturale del 23/9/2026, A-35): sei idiomi
 * diversi. `(bool)` faceva valere vera la stringa 'false' e falsa la riga
 * vuota (`SECURITY_HIBP_ENABLED=` spegneva il controllo delle password
 * compromesse); `=== 'true'` ignorava `1`, e `APP_VITE_DEV=1`, come scrive la
 * guida, non accendeva Vite; `=== '1'` ignorava `true`; `BACKUP_DIR=` vuota,
 * letta con `??`, faceva cercare i salvataggi alla radice del disco.
 *
 * Qui la regola di App\Core\Config::booleanoDallAmbiente e
 * testoDallAmbiente, e le letture vere dei file di configurazione, caricati
 * come li carica l'applicazione, con le variabili impostate caso per caso.
 *
 * Controprove per mutazione (23/9/2026): rimessi in security.php i vecchi
 * `(bool)` e `=== '1'`, falliscono i casi di hibp, totp e del limitatore;
 * rimesso `=== 'true'` in ViteManifest, fallisce il caso di APP_VITE_DEV=1;
 * togliendo dal helper il ramo del valore vuoto, falliscono i casi «vuoto».
 */
final class BooleaniDallAmbienteTest extends TestCase
{
    private const CHIAVI = [
        'PROVA_INTERRUTTORE_A35', 'SECURITY_HIBP_ENABLED', 'SECURITY_TOTP_ENABLED', 'XSS_SANITIZE_ENABLED',
        'EXPOSE_DELETION_DEBUG_TOKEN', 'RATE_LIMIT_DISABLED', 'APP_DEBUG', 'APP_VITE_DEV',
        'TELEMETRY_ENABLED', 'FM_CRITICAL_CSS', 'BACKUP_DIR', 'SESSION_COOKIE_SECURE',
        'ALLOW_CRYPTO_REGENERATE', 'CRYPTO_DUAL_WRITE', 'INSTANCE_ACN_QUALIFIED',
    ];

    /** @var array<string, string|null> */
    private array $envPrima = [];

    /** @var array<string, string|false> */
    private array $processoPrima = [];

    private mixed $viteDevPrima = null;
    private string $registroErrori = '';
    private string $errorLogPrima = '';

    protected function setUp(): void
    {
        foreach (self::CHIAVI as $k) {
            $this->envPrima[$k] = isset($_ENV[$k]) ? (string)$_ENV[$k] : null;
            $this->processoPrima[$k] = getenv($k);
            unset($_ENV[$k]);
            putenv($k);
        }
        $this->viteDevPrima = Config::get('app.vite_dev');
        $this->registroErrori = sys_get_temp_dir() . '/booleani-' . bin2hex(random_bytes(6)) . '.log';
        $this->errorLogPrima = (string)ini_get('error_log');
        ini_set('error_log', $this->registroErrori);
    }

    protected function tearDown(): void
    {
        ini_set('error_log', $this->errorLogPrima);
        @unlink($this->registroErrori);
        foreach (self::CHIAVI as $k) {
            if ($this->envPrima[$k] === null) {
                unset($_ENV[$k]);
            } else {
                $_ENV[$k] = $this->envPrima[$k];
            }
            $p = $this->processoPrima[$k];
            putenv($p === false ? $k : "{$k}={$p}");
        }
        Config::set('app.vite_dev', $this->viteDevPrima);
    }

    /** @return array<string, mixed> */
    private function carica(string $nome): array
    {
        $cfg = require \dirname(__DIR__, 3) . "/app/Config/{$nome}.php";
        self::assertIsArray($cfg);
        return $cfg;
    }

    /** @return iterable<string, array{0: string, 1: bool}> */
    public static function valoriRiconosciuti(): iterable
    {
        foreach (['1', 'true', 'yes', 'on', 'TRUE', 'On', ' yes '] as $v) {
            yield "vero «{$v}»" => [$v, true];
        }
        foreach (['0', 'false', 'no', 'off', 'FALSE', 'Off', ' no '] as $v) {
            yield "falso «{$v}»" => [$v, false];
        }
    }

    #[Test]
    #[DataProvider('valoriRiconosciuti')]
    public function un_valore_riconosciuto_vince_su_tutti_e_due_i_predefiniti(string $valore, bool $atteso): void
    {
        $_ENV['PROVA_INTERRUTTORE_A35'] = $valore;
        self::assertSame($atteso, Config::booleanoDallAmbiente('PROVA_INTERRUTTORE_A35', true));
        self::assertSame($atteso, Config::booleanoDallAmbiente('PROVA_INTERRUTTORE_A35', false));
        self::assertSame($atteso, Config::interpretaBooleano($valore));
    }

    #[Test]
    public function assente_o_vuota_vale_il_predefinito(): void
    {
        self::assertTrue(Config::booleanoDallAmbiente('PROVA_INTERRUTTORE_A35', true));
        self::assertFalse(Config::booleanoDallAmbiente('PROVA_INTERRUTTORE_A35', false));

        foreach (['', '   '] as $vuota) {
            $_ENV['PROVA_INTERRUTTORE_A35'] = $vuota;
            self::assertTrue(Config::booleanoDallAmbiente('PROVA_INTERRUTTORE_A35', true), 'riga vuota → predefinito');
            self::assertFalse(Config::booleanoDallAmbiente('PROVA_INTERRUTTORE_A35', false), 'riga vuota → predefinito');
        }
        self::assertSame('', (string)@file_get_contents($this->registroErrori), 'una riga vuota non è un errore');
    }

    #[Test]
    public function un_valore_sconosciuto_vale_il_predefinito_e_lo_dice(): void
    {
        $_ENV['PROVA_INTERRUTTORE_A35'] = 'forse-' . bin2hex(random_bytes(3));
        self::assertTrue(Config::booleanoDallAmbiente('PROVA_INTERRUTTORE_A35', true));
        self::assertFalse(Config::booleanoDallAmbiente('PROVA_INTERRUTTORE_A35', false));
        self::assertNull(Config::interpretaBooleano((string)$_ENV['PROVA_INTERRUTTORE_A35']));

        $registro = (string)@file_get_contents($this->registroErrori);
        self::assertStringContainsString('PROVA_INTERRUTTORE_A35', $registro);
        self::assertStringNotContainsString(
            (string)$_ENV['PROVA_INTERRUTTORE_A35'],
            $registro,
            'si nomina la variabile, non il valore'
        );
    }

    #[Test]
    public function l_ambiente_del_processo_conta_solo_dove_contava_gia(): void
    {
        putenv('PROVA_INTERRUTTORE_A35=on');
        // Di norma no: la sorgente di un interruttore non si allarga.
        self::assertFalse(Config::booleanoDallAmbiente('PROVA_INTERRUTTORE_A35', false));
        // Con $ancheDalProcesso sì, se i file non hanno la variabile.
        self::assertTrue(Config::booleanoDallAmbiente('PROVA_INTERRUTTORE_A35', false, true));
        // Il file .env (cioè $_ENV) ha la precedenza sul processo.
        $_ENV['PROVA_INTERRUTTORE_A35'] = 'off';
        self::assertFalse(Config::booleanoDallAmbiente('PROVA_INTERRUTTORE_A35', true, true));
    }

    #[Test]
    public function i_sanitizer_si_spengono_ancora_dall_ambiente_del_processo(): void
    {
        // Lo facevano prima del 23/9/2026 (getenv), e lo fanno ancora.
        putenv('XSS_SANITIZE_ENABLED=0');
        self::assertFalse($this->carica('security')['xss_sanitize_enabled']);
    }

    #[Test]
    public function il_limitatore_non_si_spegne_dall_ambiente_del_processo(): void
    {
        // Prima del 23/9/2026 si leggeva solo $_ENV, e così resta.
        putenv('RATE_LIMIT_DISABLED=1');
        self::assertFalse($this->carica('security')['rate_limit_disabled']);
    }

    #[Test]
    public function un_testo_vuoto_vale_il_predefinito(): void
    {
        // Tre variabili distinte, non la stessa espressione ripetuta: con
        // PHPStan >= 2.2.14 due assertSame('/predefinito', ...) sulla stessa
        // chiamata fanno credere che la seconda sia sempre vera
        // (staticMethod.alreadyNarrowedType), perché non vede che la
        // scrittura su $_ENV fra le due può cambiare l'esito.
        $senzaVariabile = Config::testoDallAmbiente('BACKUP_DIR', '/predefinito');
        self::assertSame('/predefinito', $senzaVariabile);
        $_ENV['BACKUP_DIR'] = '';
        $conVariabileVuota = Config::testoDallAmbiente('BACKUP_DIR', '/predefinito');
        self::assertSame('/predefinito', $conVariabileVuota);
        $_ENV['BACKUP_DIR'] = '/srv/salvataggi';
        $conVariabileValorizzata = Config::testoDallAmbiente('BACKUP_DIR', '/predefinito');
        self::assertSame('/srv/salvataggi', $conVariabileValorizzata);
    }

    #[Test]
    public function backup_dir_vuota_non_manda_alla_radice_del_disco(): void
    {
        $_ENV['BACKUP_DIR'] = '';
        self::assertSame('/var/backups/pantedu', $this->carica('backup')['dir']);
        $_ENV['BACKUP_DIR'] = '/srv/salvataggi';
        self::assertSame('/srv/salvataggi', $this->carica('backup')['dir']);
    }

    #[Test]
    public function il_controllo_delle_password_compromesse_si_spegne_solo_se_lo_si_dice(): void
    {
        self::assertTrue($this->carica('security')['hibp_enabled'], 'assente → acceso');
        $_ENV['SECURITY_HIBP_ENABLED'] = '';
        self::assertTrue($this->carica('security')['hibp_enabled'], 'vuota → acceso (con (bool) era spento)');
        $_ENV['SECURITY_HIBP_ENABLED'] = 'boh';
        self::assertTrue($this->carica('security')['hibp_enabled'], 'sconosciuto → acceso');
        $_ENV['SECURITY_HIBP_ENABLED'] = 'false';
        self::assertFalse($this->carica('security')['hibp_enabled'], "'false' → spento (con (bool) era acceso)");
        $_ENV['SECURITY_HIBP_ENABLED'] = '0';
        self::assertFalse($this->carica('security')['hibp_enabled']);
    }

    #[Test]
    public function l_interruttore_del_secondo_fattore_si_legge_per_quel_che_dice(): void
    {
        $_ENV['SECURITY_TOTP_ENABLED'] = 'false';
        self::assertFalse($this->carica('security')['totp_enabled'], "con (bool) 'false' valeva vero");
        $_ENV['SECURITY_TOTP_ENABLED'] = 'true';
        self::assertTrue($this->carica('security')['totp_enabled']);
        unset($_ENV['SECURITY_TOTP_ENABLED']);
        self::assertFalse($this->carica('security')['totp_enabled']);
    }

    #[Test]
    public function i_sanitizer_restano_accesi_salvo_un_no_esplicito(): void
    {
        self::assertTrue($this->carica('security')['xss_sanitize_enabled']);
        foreach (['boh', '', 'si'] as $v) {
            $_ENV['XSS_SANITIZE_ENABLED'] = $v;
            self::assertTrue($this->carica('security')['xss_sanitize_enabled'], "«{$v}» → acceso");
        }
        foreach (['0', 'false', 'OFF', 'no'] as $v) {
            $_ENV['XSS_SANITIZE_ENABLED'] = $v;
            self::assertFalse($this->carica('security')['xss_sanitize_enabled'], "«{$v}» → spento");
        }
    }

    /**
     * Le chiavi che indeboliscono una difesa quando valgono vero: il
     * predefinito, la riga vuota e un valore sconosciuto le lasciano spente.
     *
     * @return iterable<string, array{0: string, 1: string, 2: string}>
     */
    public static function bypass(): iterable
    {
        yield 'limitatore' => ['RATE_LIMIT_DISABLED', 'security', 'rate_limit_disabled'];
        yield 'gettone di cancellazione' => ['EXPOSE_DELETION_DEBUG_TOKEN', 'security', 'expose_deletion_debug_token'];
        yield 'rigenerazione della KEK' => ['ALLOW_CRYPTO_REGENERATE', 'crypto', 'allow_regenerate'];
        yield 'debug' => ['APP_DEBUG', 'app', 'debug'];
        yield 'dichiarazione ACN' => ['INSTANCE_ACN_QUALIFIED', 'app', 'instance_acn_qualified'];
    }

    #[Test]
    #[DataProvider('bypass')]
    public function un_bypass_resta_spento_se_non_lo_si_accende(string $variabile, string $file, string $chiave): void
    {
        self::assertFalse($this->carica($file)[$chiave], 'assente → spento');
        foreach (['', 'boh', 'false', '0'] as $v) {
            $_ENV[$variabile] = $v;
            self::assertFalse($this->carica($file)[$chiave], "«{$v}» → spento");
        }
        foreach (['1', 'true'] as $v) {
            $_ENV[$variabile] = $v;
            self::assertTrue($this->carica($file)[$chiave], "«{$v}» → acceso");
        }
    }

    #[Test]
    public function il_cookie_di_sessione_vuoto_o_sconosciuto_segue_il_protocollo(): void
    {
        $httpsPrima = $_SERVER['HTTPS'] ?? null;
        try {
            $_SERVER['HTTPS'] = 'on';
            foreach (['', 'boh'] as $v) {
                $_ENV['SESSION_COOKIE_SECURE'] = $v;
                self::assertTrue($this->carica('session')['secure'], "«{$v}» su HTTPS → secure (prima: falso)");
            }
            $_ENV['SESSION_COOKIE_SECURE'] = 'false';
            self::assertFalse($this->carica('session')['secure'], 'un no esplicito resta un no');
        } finally {
            if ($httpsPrima === null) {
                unset($_SERVER['HTTPS']);
            } else {
                $_SERVER['HTTPS'] = $httpsPrima;
            }
        }
    }

    #[Test]
    public function app_vite_dev_uno_accende_il_dev_server_come_dice_la_guida(): void
    {
        $_ENV['APP_VITE_DEV'] = '1';
        Config::set('app.vite_dev', $this->carica('app')['vite_dev']);
        self::assertTrue(ViteManifest::devMode(), 'APP_VITE_DEV=1 (wiki/dev-workflow.md) accende Vite');

        $_ENV['APP_VITE_DEV'] = '0';
        Config::set('app.vite_dev', $this->carica('app')['vite_dev']);
        self::assertFalse(ViteManifest::devMode());

        unset($_ENV['APP_VITE_DEV']);
        Config::set('app.vite_dev', $this->carica('app')['vite_dev']);
        self::assertFalse(ViteManifest::devMode(), 'assente → manifest');
    }

    #[Test]
    public function telemetria_e_css_critico_accettano_anche_true(): void
    {
        $_ENV['TELEMETRY_ENABLED'] = 'true';
        $_ENV['FM_CRITICAL_CSS'] = 'yes';
        $app = $this->carica('app');
        self::assertTrue($app['telemetry_enabled']);
        self::assertTrue($app['critical_css']);

        unset($_ENV['TELEMETRY_ENABLED'], $_ENV['FM_CRITICAL_CSS']);
        $app = $this->carica('app');
        self::assertFalse($app['telemetry_enabled']);
        self::assertFalse($app['critical_css']);
    }
}
