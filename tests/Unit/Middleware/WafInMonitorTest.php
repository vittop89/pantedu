<?php

declare(strict_types=1);

namespace Tests\Unit\Middleware;

use App\Controllers\WafApiController;
use App\Core\Config;
use App\Core\Kernel;
use App\Core\Request;
use App\Core\Response;
use App\Core\Router;
use App\Middleware\WafMiddleware;
use App\Repositories\Waf\WafConfigRepository;
use App\Services\Waf\GeoIpService;
use App\Services\Waf\WafLogService;
use App\Services\Waf\WafRulesService;
use App\Services\Waf\WafSessionService;
use App\Support\TosEnforcement;
use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

/**
 * In modalità `monitor` il WAF osserva e non blocca (23/9/2026, revisione
 * architetturale 2026-09, rilievo A-71).
 *
 * Prima di oggi nessuna prova guardava il ramo monitor, e lì il blocco
 * rispondeva `new Response('', 200)`: una pagina bianca alla navigazione, un
 * corpo vuoto alle fetch (che poi fallivano su `.json()`), e il controller
 * non girava. In più l'API della sfida assegnava `block` anche in monitor,
 * quindi lo script mostrava «Accesso bloccato» e il cookie teneva fuori per
 * tutta la sua durata. La modalità pensata per la taratura bloccava in modo
 * muto chi aveva un falso positivo.
 *
 * Qui il WAF vero, dentro il Kernel e il Router veri, come in
 * KernelEccezioniTest: configurazione, regole e registro su SQLite in memoria,
 * geolocalizzazione spenta. Per ogni motivo di blocco (IP bloccato a mano,
 * regola, punteggio del cookie) e per ogni forma di richiesta (pagina, API
 * JSON):
 *
 *   - in `monitor` la richiesta passa, il controller gira una volta, e il
 *     registro dice `monitor_*`;
 *   - in `enforce` (il verso opposto) il blocco resta: 403 in HTML o in JSON,
 *     il controller non gira, e il registro dice `blocked_*`.
 *
 * E l'API della sfida: con un punteggio sopra la soglia di blocco, in monitor
 * risponde e firma `soft`, in enforce `block`.
 *
 * Controprova: con `enforceBlock` di prima (200 con il corpo vuoto in
 * monitor) e le righe `blocked_*` di prima, le prove in monitor falliscono
 * tutte (stato 200 ma corpo vuoto, controller mai eseguito, esito
 * `blocked_*`); senza la riga che in monitor trasforma `block` in `soft`,
 * fallisce la prova dell'API. Misurato il 23/9/2026.
 */
final class WafInMonitorTest extends TestCase
{
    private const IP = '127.0.0.1';
    private const UA = 'Mozilla/5.0 (prova WafInMonitorTest)';
    private const SEGRETO = 'segreto-di-prova-lungo-almeno-trentadue-byte';

    private string $cartella = '';

    /** @var array<string, mixed> */
    private array $configPrima = [];

    private string|false $errorLogPrima = false;

    private int $esecuzioni = 0;

    private PDO $registro;

    protected function setUp(): void
    {
        $this->cartella = sys_get_temp_dir() . '/pantedu-waf-monitor-' . bin2hex(random_bytes(6));
        mkdir($this->cartella, 0700, true);

        $items = new ReflectionProperty(Config::class, 'items');
        /** @var array<string, mixed> $prima */
        $prima = $items->getValue();
        $this->configPrima = $prima;

        Config::set('database.enabled', false);
        Config::set('app.debug', false);
        Config::set('app.paths.logs', $this->cartella);
        Config::set('app.paths.data_base', $this->cartella);
        Config::set('app.paths.storage', $this->cartella);
        Config::set('multitenancy.tos_enforce', false);
        Config::set('waf.hmac_secret', self::SEGRETO);
        Config::set('waf.crowdsec_lapi_key', '');
        Config::set('waf.geoip_db', null);
        TosEnforcement::resetCache();
        $this->errorLogPrima = ini_set('error_log', $this->cartella . '/php_errors.log');

        $_SESSION = [];
        foreach (array_keys($_SERVER) as $k) {
            if (str_starts_with((string)$k, 'HTTP_')) {
                unset($_SERVER[$k]);
            }
        }
        $_SERVER['REMOTE_ADDR'] = self::IP;
        $_SERVER['HTTP_USER_AGENT'] = self::UA;
        $_GET = [];
        $_POST = [];

        $this->registro = new PDO('sqlite::memory:');
        $this->registro->exec(
            'CREATE TABLE waf_logs (id INTEGER PRIMARY KEY, ip TEXT, country TEXT, asn TEXT, user_agent TEXT,
                request_uri TEXT, method TEXT, referer TEXT, score INTEGER, challenge TEXT, outcome TEXT,
                rule_id INTEGER, session_token TEXT, fp_hash TEXT, request_id TEXT)'
        );
    }

    protected function tearDown(): void
    {
        ini_set('error_log', $this->errorLogPrima === false ? '' : $this->errorLogPrima);
        (new ReflectionProperty(Config::class, 'items'))->setValue(null, $this->configPrima);
        TosEnforcement::resetCache();
        unset($_SERVER['HTTP_USER_AGENT'], $_SERVER['HTTP_ACCEPT'], $_SERVER['HTTP_COOKIE']);
        foreach (glob($this->cartella . '/{,.}*', GLOB_BRACE) ?: [] as $f) {
            if (!in_array(basename($f), ['.', '..'], true) && is_file($f)) {
                @unlink($f);
            }
        }
        @rmdir($this->cartella);
    }

    // ── Il WAF vero ───────────────────────────────────────────────────────

    /**
     * @param array<string, string> $valori
     */
    private function configurazione(array $valori): WafConfigRepository
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->exec('CREATE TABLE waf_config (config_key TEXT PRIMARY KEY, config_value TEXT)');
        $scrivi = $pdo->prepare('INSERT INTO waf_config VALUES (?, ?)');
        foreach ($valori + ['enabled' => '1', 'honeypot_enabled' => '0', 'threat_intel_enabled' => '0'] as $k => $v) {
            $scrivi->execute([$k, $v]);
        }
        return new WafConfigRepository($pdo);
    }

    /**
     * Le tabelle di regole e liste come nelle migrazioni 048 e seguenti, con
     * `NOW()` come in MariaDB.
     *
     * @param 'ip'|'regola'|'punteggio' $motivo
     */
    private function regole(string $motivo): WafRulesService
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->sqliteCreateFunction('NOW', static fn(): string => date('Y-m-d H:i:s'), 0);
        $pdo->exec('CREATE TABLE waf_blocked_ips (id INTEGER PRIMARY KEY, ip_or_cidr TEXT, expires_at TEXT)');
        $pdo->exec('CREATE TABLE waf_whitelisted_ips (id INTEGER PRIMARY KEY, ip_or_cidr TEXT, expires_at TEXT)');
        $pdo->exec(
            'CREATE TABLE waf_rules (id INTEGER PRIMARY KEY, name TEXT, description TEXT, enabled INTEGER,
                priority INTEGER, conditions TEXT, action TEXT, match_count INTEGER DEFAULT 0)'
        );
        if ($motivo === 'ip') {
            $pdo->prepare('INSERT INTO waf_blocked_ips (ip_or_cidr, expires_at) VALUES (?, NULL)')->execute([self::IP]);
        }
        if ($motivo === 'regola') {
            $pdo->prepare(
                'INSERT INTO waf_rules (name, description, enabled, priority, conditions, action)
                 VALUES (?, "", 1, 10, ?, "block")'
            )->execute([
                'sonda',
                json_encode(['logic' => 'AND', 'conditions' => [
                    ['field' => 'user_agent', 'operator' => 'contains', 'value' => 'WafInMonitorTest'],
                ]]),
            ]);
        }
        return new WafRulesService($pdo);
    }

    /**
     * @param 'monitor'|'enforce' $modo
     * @param 'ip'|'regola'|'punteggio' $motivo
     */
    private function kernel(string $modo, string $motivo): Kernel
    {
        $config = $this->configurazione(['mode' => $modo]);
        $regole = $this->regole($motivo);
        $registro = new WafLogService($this->registro);
        $sessione = new WafSessionService(self::SEGRETO);
        if ($motivo === 'punteggio') {
            // Il cookie di chi ha superato la soglia di blocco.
            $gettone = $sessione->createToken(95, self::IP, 'block', WafSessionService::uaHash(self::UA));
            $_SERVER['HTTP_COOKIE'] = 'waf_session=' . rawurlencode($gettone);
        }
        $waf = static fn(Request $r, callable $next): Response => (new WafMiddleware(
            configRepo: $config,
            session: $sessione,
            geoip: new GeoIpService(null, null),
            rules: $regole,
            log: $registro,
        ))->handle($r, $next);
        $tos = static fn(Request $r, callable $next): Response => $next($r);

        $router = new Router();
        $router->get('/sano', function (): Response {
            $this->esecuzioni++;
            return Response::html('<p>sano n.' . $this->esecuzioni . '</p>');
        });
        $router->get('/api/sano', function (): Response {
            $this->esecuzioni++;
            return Response::json(['ok' => true, 'n' => $this->esecuzioni]);
        });
        return new Kernel($router, $waf, $tos);
    }

    private function chiedi(Kernel $kernel, string $percorso, bool $json): Response
    {
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['REQUEST_URI'] = $percorso;
        if ($json) {
            $_SERVER['HTTP_ACCEPT'] = 'application/json';
        } else {
            $_SERVER['HTTP_ACCEPT'] = 'text/html';
        }
        return $kernel->handle(new Request());
    }

    /** @return list<string> gli esiti scritti nel registro del WAF */
    private function esiti(): array
    {
        return array_map('strval', $this->registro->query('SELECT outcome FROM waf_logs ORDER BY id')->fetchAll(PDO::FETCH_COLUMN));
    }

    /** @return array<string, array{0: 'ip'|'regola'|'punteggio', 1: string, 2: bool, 3: string}> */
    public static function blocchi(): array
    {
        $casi = [];
        foreach (['ip' => 'manual', 'regola' => 'rule', 'punteggio' => 'score'] as $motivo => $esito) {
            $casi["$motivo, pagina"] = [$motivo, '/sano', false, $esito];
            $casi["$motivo, API"] = [$motivo, '/api/sano', true, $esito];
        }
        return $casi;
    }

    // ── Le prove ──────────────────────────────────────────────────────────

    /** @param 'ip'|'regola'|'punteggio' $motivo */
    #[Test]
    #[DataProvider('blocchi')]
    public function in_monitor_un_blocco_si_registra_e_la_richiesta_passa(
        string $motivo,
        string $percorso,
        bool $json,
        string $esito
    ): void {
        $risposta = $this->chiedi($this->kernel('monitor', $motivo), $percorso, $json);

        $this->assertSame(1, $this->esecuzioni, 'in monitor il controller gira');
        $this->assertSame(200, $risposta->status);
        if ($json) {
            $this->assertSame(['ok' => true, 'n' => 1], json_decode((string)$risposta->body, true), 'la risposta è quella del controller');
        } else {
            $this->assertStringContainsString('sano n.1', (string)$risposta->body, 'la pagina è quella del controller, non bianca');
        }
        $this->assertSame(['monitor_' . $esito], $this->esiti(), 'il registro dice che avrebbe bloccato');
    }

    /** @param 'ip'|'regola'|'punteggio' $motivo */
    #[Test]
    #[DataProvider('blocchi')]
    public function in_enforce_lo_stesso_blocco_resta_un_403(
        string $motivo,
        string $percorso,
        bool $json,
        string $esito
    ): void {
        $risposta = $this->chiedi($this->kernel('enforce', $motivo), $percorso, $json);

        $this->assertSame(0, $this->esecuzioni, 'in enforce il controller non gira');
        $this->assertSame(403, $risposta->status);
        if ($json) {
            $this->assertSame('request_blocked', json_decode((string)$risposta->body, true)['error'] ?? null);
        } else {
            $this->assertStringContainsString('403 Accesso negato', (string)$risposta->body);
        }
        $this->assertSame(['blocked_' . $esito], $this->esiti());
    }

    /** @return array<string, array{0: 'monitor'|'enforce', 1: string}> */
    public static function modi(): array
    {
        return [
            'monitor' => ['monitor', 'soft'],
            'enforce' => ['enforce', 'block'],
        ];
    }

    /**
     * L'API della sfida con un punteggio oltre la soglia di blocco (soglie a
     * -1: ogni punteggio è «block»). In monitor risponde e firma `soft`, in
     * enforce `block`; il cookie dice lo stesso della risposta.
     *
     * @param 'monitor'|'enforce' $modo
     */
    #[Test]
    #[DataProvider('modi')]
    public function l_api_della_sfida_non_assegna_block_in_monitor(string $modo, string $atteso): void
    {
        $config = $this->configurazione([
            'mode' => $modo, 'pow_enabled' => '0', 'threshold_pass' => '-1', 'threshold_block' => '-1',
        ]);
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_SERVER['REQUEST_URI'] = '/waf/fingerprint';
        $impronta = (string)json_encode(['userAgent' => self::UA, 'webdriver' => true, 'plugins' => 0]);

        $risposta = (new WafApiController($config, new WafLogService($this->registro)))->collect(new Request($impronta));

        $this->assertSame(200, $risposta->status);
        $corpo = json_decode((string)$risposta->body, true);
        $this->assertSame($atteso, $corpo['challenge'] ?? null);
        $this->assertMatchesRegularExpression('/^waf_session=([^;]+);/', $risposta->headers['Set-Cookie'] ?? '');
        preg_match('/^waf_session=([^;]+);/', $risposta->headers['Set-Cookie'], $m);
        $firmato = (new WafSessionService(self::SEGRETO))->verifyToken($m[1], self::IP, WafSessionService::uaHash(self::UA));
        $this->assertSame($atteso, $firmato['challenge'] ?? null, 'il cookie porta la stessa sfida');
        $this->assertSame(
            [$atteso],
            array_map('strval', $this->registro->query("SELECT challenge FROM waf_logs WHERE outcome = 'fingerprint_collected'")->fetchAll(PDO::FETCH_COLUMN)),
        );
    }
}
