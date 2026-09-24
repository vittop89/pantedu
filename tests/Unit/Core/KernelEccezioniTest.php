<?php

declare(strict_types=1);

namespace Tests\Unit\Core;

use App\Core\Config;
use App\Core\Kernel;
use App\Core\Request;
use App\Core\Response;
use App\Core\Router;
use App\Middleware\TosAcceptanceMiddleware;
use App\Middleware\WafMiddleware;
use App\Repositories\Waf\WafConfigRepository;
use App\Services\Gdpr\TosAcceptanceService;
use App\Services\Waf\GeoIpService;
use App\Services\Waf\WafLogService;
use App\Services\Waf\WafRulesService;
use App\Support\TosEnforcement;
use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use RuntimeException;

/**
 * Un'eccezione, un'esecuzione (23/9/2026, revisione architetturale 2026-09,
 * rilievo A-1).
 *
 * Il Kernel avvolgeva il WAF in un `catch (Throwable)` per il fail-open: se il
 * WAF si guasta, la richiesta passa senza filtro. Ma il WAF chiama la pipeline
 * (gate ToS, rotta, middleware, controller), e quel catch prendeva anche le
 * sue eccezioni: le scartava senza registrarle e rieseguiva tutto. Misurato con
 * una sonda sul Kernel e il Router veri, un controller che lancia: stato 500,
 * controller eseguito due volte, e nel registro solo «guasto n.2» — il primo
 * errore perso. Con il gate ToS attivo, che aveva lo stesso schema, quattro
 * volte; con il WAF guasto in modo monitor, che lo aveva nel suo ripiego, tre.
 * Ogni esecuzione in più ripete gli effetti già prodotti: scritture fuori
 * transazione, posta, righe di audit, colpi del limitatore.
 *
 * Qui: Kernel e Router veri, una rotta che conta quante volte gira. I
 * cancelli sono quelli veri (WafMiddleware, TosAcceptanceMiddleware), con le
 * dipendenze che toccherebbero il database sostituite: un SQLite in memoria
 * per la configurazione del WAF, un servizio finto per le accettazioni. Il
 * database vero è spento per tutta la prova (`database.enabled`), così il
 * registro delle operazioni non scrive righe; registri, rotazione e override
 * del gate ToS stanno in una cartella temporanea.
 *
 * Il fail-open del WAF è un invariante e resta: un WAF che si guasta prima di
 * decidere lascia passare la richiesta, una volta sola. L'altro verso resta
 * anche lui: in `enforce` il WAF che si guasta dentro evaluate() risponde con
 * la challenge e il controller non gira.
 *
 * Il registro: una riga `[KERNEL]` per eccezione, con classe, messaggio,
 * file:riga e la traccia senza argomenti. La prova fa raccogliere a PHP gli
 * argomenti come nel container (`zend.exception_ignore_args` Off, stringhe
 * troncate a 15 caratteri: docker/php.ini non imposta né l'una né l'altra) e
 * controlla che la riga non contenga quello passato alla funzione che lancia.
 *
 * Controprova. Per rifarla con il codice di prima si rimette il vecchio
 * `catch (Throwable)` del Kernel e i middleware di main, ma si TIENE il
 * costruttore nuovo con `$waf` e `$tos`. Con `git checkout main --
 * app/Core/Kernel.php` il Kernel ignora le closure che la prova gli passa:
 * le prove del fail-open diventano rosse perché il WAF finto non viene
 * chiamato, non perché il controller gira due volte, e il conto dei rossi
 * cambia per un motivo che non è il difetto.
 */
final class KernelEccezioniTest extends TestCase
{
    /** Più corta di 15 caratteri: `getTraceAsString()` la scriverebbe intera. */
    private const PASSWORD = 'Segreto42';

    private string $cartella = '';

    /** @var array<string, mixed> */
    private array $configPrima = [];

    private string|false $errorLogPrima = false;

    /** @var array<string, string|false> le impostazioni di PHP cambiate da setUp, com'erano */
    private array $iniPrima = [];

    /** Quante volte è girato il controller della rotta. */
    private int $esecuzioni = 0;

    /** Quante volte il gate ToS ha letto l'accettazione (servizio finto). */
    private int $lettureToS = 0;

    private bool $tosAttivo = false;

    protected function setUp(): void
    {
        $this->cartella = sys_get_temp_dir() . '/pantedu-kernel-eccezioni-' . bin2hex(random_bytes(6));
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
        TosEnforcement::resetCache();
        $this->errorLogPrima = ini_set('error_log', $this->cartella . '/php_errors.log');
        // Come nel container: PHP raccoglie gli argomenti nella traccia e li
        // mostra troncati a 15 caratteri. La CLI di Ubuntu in WSL parte da
        // php.ini-production (On e 0, misurato il 23/9/2026): senza queste
        // due righe la prova sugli argomenti non vedrebbe niente anche con
        // una traccia che li scrive.
        foreach (['zend.exception_ignore_args' => '0', 'zend.exception_string_param_max_len' => '15'] as $k => $v) {
            $this->iniPrima[$k] = ini_set($k, $v);
        }

        $_SESSION = [];
        foreach (array_keys($_SERVER) as $k) {
            if (str_starts_with((string)$k, 'HTTP_')) {
                unset($_SERVER[$k]);
            }
        }
        $_SERVER['REMOTE_ADDR'] = '127.0.0.1';
        $_GET = [];
        $_POST = [];
    }

    protected function tearDown(): void
    {
        ini_set('error_log', $this->errorLogPrima === false ? '' : $this->errorLogPrima);
        foreach ($this->iniPrima as $k => $v) {
            if ($v !== false) {
                ini_set($k, $v);
            }
        }
        (new ReflectionProperty(Config::class, 'items'))->setValue(null, $this->configPrima);
        TosEnforcement::resetCache();
        $this->svuota($this->cartella);
    }

    private function svuota(string $cartella): void
    {
        foreach (glob($cartella . '/{,.}*', GLOB_BRACE) ?: [] as $f) {
            if (in_array(basename($f), ['.', '..'], true)) {
                continue;
            }
            is_dir($f) ? $this->svuota($f) : @unlink($f);
        }
        @rmdir($cartella);
    }

    // ── La richiesta e la rotta ────────────────────────────────────────────

    private function richiesta(string $percorso): Request
    {
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['REQUEST_URI'] = $percorso;
        return new Request();
    }

    /**
     * `/guasto` lancia a ogni esecuzione, `/sano` e `/api/sano` rispondono,
     * `/segreto` lancia da una funzione che riceve una password. Tutte contano.
     */
    private function rotte(): Router
    {
        $router = new Router();
        $router->get('/guasto', function (): Response {
            $this->esecuzioni++;
            throw new RuntimeException('guasto n.' . $this->esecuzioni);
        });
        $sano = function (): Response {
            $this->esecuzioni++;
            return Response::html('sano n.' . $this->esecuzioni);
        };
        $router->get('/sano', $sano);
        $router->get('/api/sano', $sano);
        $router->get('/segreto', function (): Response {
            $this->esecuzioni++;
            $this->accedi(self::PASSWORD);
        });
        $router->get('/profondo', function (): Response {
            $this->esecuzioni++;
            $this->scendi(60);
        });
        $router->get('/anonima', function (): Response {
            $this->esecuzioni++;
            // Il nome di una classe anonima contiene un byte NUL.
            throw new class ('guasto anonimo') extends RuntimeException {
            };
        });
        return $router;
    }

    /** Scende di `$livelli` chiamate e lancia un messaggio su due righe. */
    private function scendi(int $livelli): never
    {
        if ($livelli > 0) {
            $this->scendi($livelli - 1);
        }
        throw new RuntimeException("prima riga\nseconda riga");
    }

    /** Lancia, come un controllo di credenziali che rifiuta. */
    private function accedi(string $password): never
    {
        throw new RuntimeException('accesso rifiutato per ' . \strlen($password) . ' caratteri');
    }

    // ── I cancelli ─────────────────────────────────────────────────────────

    /**
     * Il WAF vero, spento: la configurazione è un SQLite vuoto (nessuna
     * tabella → WafConfigRepository ricade su `enabled` = 0).
     *
     * @return \Closure(Request, callable): Response
     */
    private function wafSpento(): \Closure
    {
        $config = new WafConfigRepository(new PDO('sqlite::memory:'));
        return static fn(Request $r, callable $next): Response
            => (new WafMiddleware(configRepo: $config))->handle($r, $next);
    }

    /**
     * Un WAF che si guasta prima di decidere, cioè prima di chiamare `$next`.
     * Con la classe vera non si costruisce: il suo guasto interno lo gestisce
     * da sé (vedi {@see self::wafAccesoCheSiGuasta()}).
     *
     * @return \Closure(Request, callable): Response
     */
    private function wafCheLancia(): \Closure
    {
        return static fn(Request $r, callable $next): Response
            => throw new RuntimeException('WAF rotto');
    }

    /**
     * Il WAF vero, acceso nel modo dato, che si guasta dentro evaluate(): la
     * chiave HMAC è troppo corta e WafSessionService la rifiuta. Il WAF allora
     * ripiega da sé: in `monitor` lascia passare, in `enforce` risponde con la
     * challenge invisibile. Tutto il resto è spento o su un SQLite senza
     * tabelle, perché il guasto arrivi lì e non altrove (lo prova la riga
     * «[WAF] errore middleware» nel registro).
     *
     * @param 'monitor'|'enforce' $modo
     * @return \Closure(Request, callable): Response
     */
    private function wafAccesoCheSiGuasta(string $modo): \Closure
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->exec('CREATE TABLE waf_config (config_key TEXT PRIMARY KEY, config_value TEXT)');
        $scrivi = $pdo->prepare('INSERT INTO waf_config VALUES (?, ?)');
        $valori = ['enabled' => '1', 'mode' => $modo, 'honeypot_enabled' => '0', 'threat_intel_enabled' => '0'];
        foreach ($valori as $k => $v) {
            $scrivi->execute([$k, $v]);
        }
        Config::set('waf.hmac_secret', 'corta');
        Config::set('waf.crowdsec_lapi_key', '');
        $vuoto = new PDO('sqlite::memory:');
        $config = new WafConfigRepository($pdo);
        $regole = new WafRulesService($vuoto);
        $registro = new WafLogService($vuoto);
        return static fn(Request $r, callable $next): Response => (new WafMiddleware(
            configRepo: $config,
            geoip: new GeoIpService(null, null),
            rules: $regole,
            log: $registro,
        ))->handle($r, $next);
    }

    /**
     * Il gate ToS vero. Attivo: un docente autenticato, non super-admin, che
     * ha accettato — il ramo che chiama `$next` dal controllo di accettazione.
     *
     * Il servizio finto conta le letture in `$this->lettureToS`: la prova
     * controlla che il gate attivo abbia guardato davvero. Non con
     * `expects($this->once())`: alla seconda chiamata il finto lancerebbe, e
     * nel codice di prima quell'eccezione cambierebbe il percorso da provare.
     *
     * @return \Closure(Request, callable): Response
     */
    private function tos(bool $attivo): \Closure
    {
        Config::set('multitenancy.tos_enforce', $attivo);
        TosEnforcement::resetCache();
        $this->tosAttivo = $attivo;
        if ($attivo) {
            $_SESSION['autenticato'] = true;
            $_SESSION['username'] = 'docente-prova';
            $_SESSION['user_id'] = 7;
            $_SESSION['user_role'] = 'teacher';
            $_SESSION['is_super_admin'] = false;
        }
        $servizio = $this->createStub(TosAcceptanceService::class);
        $servizio->method('hasAccepted')->willReturnCallback(function (): bool {
            $this->lettureToS++;
            return true;
        });
        return static fn(Request $r, callable $next): Response
            => (new TosAcceptanceMiddleware($servizio))->handle($r, $next);
    }

    /** Il gate attivo ha letto l'accettazione una volta; quello spento mai. */
    private function assertGateToSHaGuardato(): void
    {
        $this->assertSame(
            $this->tosAttivo ? 1 : 0,
            $this->lettureToS,
            $this->tosAttivo
                ? "il gate ToS attivo deve controllare l'accettazione, una volta"
                : 'il gate spento non legge'
        );
    }

    // ── Il registro ───────────────────────────────────────────────────────

    /** @return list<string> le righe di error_log che contengono `$segno` */
    private function righe(string $segno): array
    {
        $testo = (string)@file_get_contents($this->cartella . '/php_errors.log');
        return array_values(array_filter(
            explode("\n", $testo),
            static fn(string $r): bool => str_contains($r, $segno)
        ));
    }

    /**
     * L'errore è registrato una volta, ed è quello della prima esecuzione.
     *
     * @param string $messaggio il messaggio senza il numero dell'esecuzione
     */
    private function assertErroreRegistratoUnaVolta(string $messaggio = 'guasto n.'): void
    {
        $righe = $this->righe('[KERNEL] RuntimeException: ' . $messaggio);
        $this->assertCount(1, $righe, "l'errore va registrato una volta:\n" . implode("\n", $righe));
        $this->assertStringContainsString(
            '[KERNEL] RuntimeException: ' . $messaggio . '1 @ ',
            $righe[0],
            'è la prima esecuzione a lanciare'
        );
    }

    /** @return array<string, array{0: bool}> */
    public static function gateToS(): array
    {
        return [
            'gate ToS spento' => [false],
            'gate ToS attivo' => [true],
        ];
    }

    // ── Le prove ──────────────────────────────────────────────────────────

    #[Test]
    #[DataProvider('gateToS')]
    public function un_controller_che_lancia_gira_una_volta_e_risponde_500(bool $tosAttivo): void
    {
        $kernel = new Kernel($this->rotte(), $this->wafSpento(), $this->tos($tosAttivo));

        $risposta = $kernel->handle($this->richiesta('/guasto'));

        $this->assertSame(1, $this->esecuzioni, 'il controller che lancia non si riesegue');
        $this->assertSame(500, $risposta->status);
        $this->assertStringNotContainsString(
            'guasto n.',
            (string)$risposta->body,
            'in produzione il messaggio non va in pagina'
        );
        $this->assertErroreRegistratoUnaVolta();
        $this->assertGateToSHaGuardato();
    }

    #[Test]
    #[DataProvider('gateToS')]
    public function un_waf_che_si_guasta_prima_di_decidere_lascia_passare_una_volta(bool $tosAttivo): void
    {
        $kernel = new Kernel($this->rotte(), $this->wafCheLancia(), $this->tos($tosAttivo));

        $risposta = $kernel->handle($this->richiesta('/sano'));

        $this->assertSame(1, $this->esecuzioni, 'fail-open: la pipeline gira, una volta');
        $this->assertSame(200, $risposta->status);
        $this->assertStringContainsString('sano n.1', (string)$risposta->body);
        $this->assertCount(1, $this->righe('[KERNEL] WAF guasto prima della decisione'), 'il fail-open si registra');
        $this->assertCount(1, $this->righe('RuntimeException: WAF rotto'), 'con il guasto del WAF');
        $this->assertGateToSHaGuardato();
    }

    /** Il fail-open del Kernel porta al gestore degli errori anche l'eccezione della pipeline che lascia passare. */
    #[Test]
    #[DataProvider('gateToS')]
    public function con_il_waf_guasto_un_controller_che_lancia_gira_una_volta(bool $tosAttivo): void
    {
        $kernel = new Kernel($this->rotte(), $this->wafCheLancia(), $this->tos($tosAttivo));

        $risposta = $kernel->handle($this->richiesta('/guasto'));

        $this->assertSame(1, $this->esecuzioni);
        $this->assertSame(500, $risposta->status);
        $this->assertCount(1, $this->righe('[KERNEL] WAF guasto prima della decisione'));
        $this->assertErroreRegistratoUnaVolta();
        $this->assertGateToSHaGuardato();
    }

    /** Il ripiego interno del WAF (guasto in monitor → lascia passare) non riesegue il controller. */
    #[Test]
    #[DataProvider('gateToS')]
    public function il_waf_guasto_in_monitor_lascia_passare_una_volta(bool $tosAttivo): void
    {
        $kernel = new Kernel($this->rotte(), $this->wafAccesoCheSiGuasta('monitor'), $this->tos($tosAttivo));

        $risposta = $kernel->handle($this->richiesta('/guasto'));

        $this->assertCount(
            1,
            $this->righe('[WAF] errore middleware'),
            'il WAF deve guastarsi dentro evaluate(), una volta'
        );
        $this->assertSame(1, $this->esecuzioni);
        $this->assertSame(500, $risposta->status);
        $this->assertErroreRegistratoUnaVolta();
        $this->assertGateToSHaGuardato();
    }

    /**
     * Il cancello delle materie del docente (alias `teacher_subjects`, su
     * tutto il gruppo del docente in routes/web.php) aveva lo stesso schema:
     * `$next` dentro il try delle sue letture, e il catch lo richiamava. La
     * revisione non lo elencava; trovato cercando lo schema negli altri
     * middleware. Qui il ramo senza scuola attiva (ADR-047), che non legge il
     * database: la rotta passa per la catena vera, alias compreso.
     */
    #[Test]
    public function dietro_il_cancello_delle_materie_un_controller_che_lancia_gira_una_volta(): void
    {
        $router = new Router();
        $router->group(['middleware' => ['teacher_subjects']], function (Router $r): void {
            $r->get('/area-docente/guasto', function (): Response {
                $this->esecuzioni++;
                throw new RuntimeException('guasto n.' . $this->esecuzioni);
            });
        });
        $_SESSION['autenticato'] = true;
        $_SESSION['username'] = 'docente-prova';
        $_SESSION['user_id'] = 7;
        $_SESSION['user_role'] = 'teacher';
        $_SESSION['is_super_admin'] = false;
        $_SESSION['current_institute_id'] = 0;
        $kernel = new Kernel($router, $this->wafSpento(), $this->tos(false));

        $risposta = $kernel->handle($this->richiesta('/area-docente/guasto'));

        $this->assertSame(1, $this->esecuzioni);
        $this->assertSame(500, $risposta->status);
        $this->assertErroreRegistratoUnaVolta();
    }

    /** Lo stesso WAF guasto con un controller sano: passa, una volta, con 200. */
    #[Test]
    public function il_waf_guasto_in_monitor_lascia_passare_una_rotta_sana(): void
    {
        $kernel = new Kernel($this->rotte(), $this->wafAccesoCheSiGuasta('monitor'), $this->tos(false));

        $risposta = $kernel->handle($this->richiesta('/sano'));

        $this->assertCount(1, $this->righe('[WAF] errore middleware'));
        $this->assertSame(1, $this->esecuzioni);
        $this->assertSame(200, $risposta->status);
        $this->assertStringContainsString('sano n.1', (string)$risposta->body);
    }

    /** @return array<string, array{0: string, 1: int, 2: string}> percorso, stato, segno della challenge */
    public static function sfide(): array
    {
        return [
            'pagina' => ['/sano', 200, 'data-waf-mode="invisible"'],
            'API'    => ['/api/sano', 403, '"code":"waf_challenge"'],
        ];
    }

    /**
     * L'altro verso del ripiego interno del WAF: in `enforce` il guasto non
     * lascia passare, risponde con la challenge invisibile (una pagina che
     * esegue il fingerprinter, o un 403 JSON per le API) e il controller non
     * gira. Il 23/9/2026 il ripiego è stato riscritto per portare `$next` fuori
     * dal try: se la decisione diventasse `null`, il WAF guasto in enforce
     * aprirebbe la porta, e senza questa prova la suite resterebbe verde.
     */
    #[Test]
    #[DataProvider('sfide')]
    public function il_waf_guasto_in_enforce_sfida_e_non_esegue_il_controller(
        string $percorso,
        int $stato,
        string $segno
    ): void {
        $kernel = new Kernel($this->rotte(), $this->wafAccesoCheSiGuasta('enforce'), $this->tos(false));

        $risposta = $kernel->handle($this->richiesta($percorso));

        $this->assertCount(
            1,
            $this->righe('[WAF] errore middleware'),
            'il WAF deve guastarsi dentro evaluate(), una volta'
        );
        $this->assertSame(0, $this->esecuzioni, 'in enforce il WAF guasto non lascia passare');
        $this->assertSame($stato, $risposta->status);
        $this->assertStringContainsString($segno, (string)$risposta->body, 'è la challenge');
        $this->assertStringNotContainsString('sano n.', (string)$risposta->body);
        $this->assertSame([], $this->righe('[KERNEL]'), 'né fail-open del Kernel né errore');
    }

    /**
     * Il WAF lascia passare e si guasta dopo (23/9/2026): la pipeline è
     * partita, quindi non è un fail-open. L'eccezione va al gestore degli
     * errori, la risposta già prodotta dal controller va persa e l'utente
     * riceve un 500; il controller non si riesegue. Oggi il WAF non fa niente
     * dopo `$next`: la prova tiene fermo che cosa succederebbe se un giorno lo
     * facesse.
     */
    #[Test]
    public function un_waf_che_si_guasta_dopo_aver_lasciato_passare_risponde_500_senza_rieseguire(): void
    {
        $waf = static function (Request $r, callable $next): Response {
            $next($r);
            throw new RuntimeException('WAF guasto dopo n.1');
        };
        $kernel = new Kernel($this->rotte(), $waf, $this->tos(false));

        $risposta = $kernel->handle($this->richiesta('/sano'));

        $this->assertSame(1, $this->esecuzioni, 'la pipeline è partita: non si riesegue');
        $this->assertSame(500, $risposta->status);
        $this->assertErroreRegistratoUnaVolta('WAF guasto dopo n.');
        $this->assertSame([], $this->righe('WAF guasto prima della decisione'), 'non è un fail-open');
    }

    /** @return array<string, array{0: bool}> */
    public static function quandoLanciaIlMiddleware(): array
    {
        return [
            'prima di $next' => [false],
            'dopo $next'     => [true],
        ];
    }

    /**
     * Un middleware di rotta che lancia, prima o dopo aver chiamato `$next`
     * (23/9/2026). Con il catch di prima il Kernel rieseguiva tutta la catena:
     * il middleware girava due volte, e con lui il controller se ci era già
     * arrivato. L'alias si aggiunge alla mappa del Kernel per riflessione: i
     * middleware si costruiscono senza argomenti, quindi il conto sta in una
     * proprietà statica della classe anonima.
     */
    #[Test]
    #[DataProvider('quandoLanciaIlMiddleware')]
    public function un_middleware_di_rotta_che_lancia_gira_una_volta(bool $dopoNext): void
    {
        $guasto = new class {
            public static int $volte = 0;

            public static bool $dopoNext = false;

            public function handle(Request $r, callable $next): Response
            {
                self::$volte++;
                if (self::$dopoNext) {
                    $next($r);
                }
                throw new RuntimeException('middleware guasto n.' . self::$volte);
            }
        };
        $guasto::$volte = 0;
        $guasto::$dopoNext = $dopoNext;
        $router = new Router();
        $router->group(['middleware' => ['guasto']], function (Router $r): void {
            $r->get('/dietro-il-middleware', function (): Response {
                $this->esecuzioni++;
                return Response::html('sano n.' . $this->esecuzioni);
            });
        });
        $kernel = new Kernel($router, $this->wafSpento(), $this->tos(false));
        $mappa = new ReflectionProperty(Kernel::class, 'middlewareMap');
        /** @var array<string, class-string> $alias */
        $alias = $mappa->getValue($kernel);
        $mappa->setValue($kernel, $alias + ['guasto' => $guasto::class]);

        $risposta = $kernel->handle($this->richiesta('/dietro-il-middleware'));

        $this->assertSame(1, $guasto::$volte, 'il middleware che lancia non si riesegue');
        $this->assertSame($dopoNext ? 1 : 0, $this->esecuzioni, 'il controller gira al più una volta');
        $this->assertSame(500, $risposta->status);
        $this->assertErroreRegistratoUnaVolta('middleware guasto n.');
    }

    /**
     * La riga del registro (23/9/2026): classe, messaggio, file:riga e la
     * traccia con file, riga e funzione, ma senza gli argomenti. Qui il
     * controller passa una password alla funzione che lancia.
     *
     * La precondizione fa vedere che la prova può fallire: con le impostazioni
     * del container `getTraceAsString()` scrive la password, quindi una
     * traccia costruita così la porterebbe nel registro.
     */
    #[Test]
    public function il_registro_ha_classe_riga_e_traccia_senza_gli_argomenti(): void
    {
        try {
            $this->accedi(self::PASSWORD);
        } catch (RuntimeException $e) {
            $this->assertStringContainsString(
                "accedi('" . self::PASSWORD . "')",
                $e->getTraceAsString(),
                'precondizione: come nel container, la traccia di PHP porta gli argomenti'
            );
        }
        $kernel = new Kernel($this->rotte(), $this->wafSpento(), $this->tos(false));

        $risposta = $kernel->handle($this->richiesta('/segreto'));

        $this->assertSame(500, $risposta->status);
        $righe = $this->righe('[KERNEL]');
        $this->assertCount(1, $righe, "una riga sola per l'eccezione, traccia compresa");
        $riga = $righe[0];
        $this->assertStringNotContainsString(self::PASSWORD, $riga, 'gli argomenti non vanno nel registro');
        $this->assertMatchesRegularExpression(
            '/\[KERNEL\] RuntimeException: accesso rifiutato per 9 caratteri @ '
                . preg_quote(__FILE__, '/') . ':\d+ \| traccia: #0 /',
            $riga,
            'classe, messaggio, file:riga, poi la traccia'
        );
        $this->assertStringContainsString(
            '#0 ' . __FILE__ . '(',
            $riga,
            'ogni passo ha file e riga'
        );
        $this->assertStringContainsString(self::class . '->accedi()', $riga, 'la funzione che ha lanciato');
        $this->assertStringContainsString(Kernel::class . '->invoke()', $riga, 'la traccia arriva al Kernel');
    }

    /**
     * La riga resta una anche con una traccia lunga e un messaggio su più
     * righe: la traccia si ferma a quaranta passi e dice quanti ne lascia,
     * e gli a capo del messaggio diventano spazi.
     */
    #[Test]
    public function il_registro_resta_una_riga_con_una_traccia_lunga_e_un_messaggio_a_capo(): void
    {
        $kernel = new Kernel($this->rotte(), $this->wafSpento(), $this->tos(false));

        $kernel->handle($this->richiesta('/profondo'));

        $righe = $this->righe('[KERNEL]');
        $this->assertCount(1, $righe);
        $riga = $righe[0];
        $this->assertStringContainsString('RuntimeException: prima riga seconda riga @ ', $riga);
        $this->assertStringContainsString(' ; #39 ', $riga, 'i primi quaranta passi ci sono');
        $this->assertStringNotContainsString('#40 ', $riga, 'dal quarantunesimo no');
        $this->assertMatchesRegularExpression('/ ; … altri \d+ passi$/', $riga, 'e si dice quanti ne mancano');
    }

    /**
     * Una classe anonima non tronca la riga (23/9/2026). Il suo nome contiene
     * un byte NUL, ed error_log() scrive fino al primo NUL: senza la
     * sostituzione la riga si fermerebbe al nome della classe, e messaggio e
     * traccia andrebbero persi.
     */
    #[Test]
    public function una_classe_anonima_non_tronca_la_riga_del_registro(): void
    {
        $kernel = new Kernel($this->rotte(), $this->wafSpento(), $this->tos(false));

        $kernel->handle($this->richiesta('/anonima'));

        $righe = $this->righe('[KERNEL]');
        $this->assertCount(1, $righe);
        $this->assertStringContainsString(': guasto anonimo @ ', $righe[0], 'il messaggio dopo il nome della classe');
        $this->assertStringContainsString(' | traccia: #0 ', $righe[0], 'e la traccia');
    }
}
