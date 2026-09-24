<?php

declare(strict_types=1);

namespace Tests\Unit\Core;

use App\Core\Config;
use App\Core\Kernel;
use App\Core\Request;
use App\Core\Response;
use App\Core\Router;
use App\Middleware\WafMiddleware;
use App\Repositories\Waf\WafConfigRepository;
use App\Support\TosEnforcement;
use PDO;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

/**
 * Un alias di middleware che non esiste si vede (23/9/2026, revisione
 * architetturale 2026-09, rilievo A-40).
 *
 * Il Kernel costruiva la catena di una rotta saltando in silenzio gli alias
 * che non conosceva: un refuso come `'atuh'` al posto di `'auth'` in
 * routes/web.php avrebbe servito un gruppo di rotte senza la porta, e nessuna
 * prova se ne sarebbe accorta (CoperturaRuoliTest e CoperturaCsrfTest leggono
 * i nomi scritti nella tabella, non quelli che il Kernel riconosce). Adesso:
 *
 *   - in CI, questa prova risolve ogni voce di ogni rotta di routes/web.php
 *     con la regola del Kernel (`Kernel::middlewareDellaVoce`) e fallisce sul
 *     primo alias che non esiste;
 *   - a runtime, se un refuso arrivasse lo stesso, la rotta risponde 500 con
 *     la riga `[KERNEL]` e il controller non gira: niente rotta servita senza
 *     la protezione che chiedeva;
 *   - ogni alias registrato serve ad almeno una rotta: l'alias `waf`, mai
 *     usato perché il WAF lo applica il Kernel a ogni richiesta, è stato
 *     tolto, e un alias morto non torna senza che la prova lo dica.
 *
 * Controprove (mutazioni del Kernel, misurate il 23/9/2026): rimesso il
 * `continue` sull'alias sconosciuto in `risolvi`, la prova a runtime vede il
 * controller girare con 200; con `risolvi` che restituisce un middleware
 * qualunque invece di lanciare, `un_refuso_in_una_rotta_si_vede` fallisce;
 * rimesso `'waf'` fra i MIDDLEWARE, fallisce
 * `ogni_alias_registrato_serve_a_qualche_rotta`.
 */
final class AliasDeiMiddlewareTest extends TestCase
{
    private string $cartella = '';

    /** @var array<string, mixed> */
    private array $configPrima = [];

    private string|false $errorLogPrima = false;

    private int $esecuzioni = 0;

    protected function setUp(): void
    {
        $this->cartella = sys_get_temp_dir() . '/pantedu-alias-middleware-' . bin2hex(random_bytes(6));
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
        $_SESSION = [];
        $_SERVER['REMOTE_ADDR'] = '127.0.0.1';
        $_GET = [];
        $_POST = [];
    }

    protected function tearDown(): void
    {
        ini_set('error_log', $this->errorLogPrima === false ? '' : $this->errorLogPrima);
        (new ReflectionProperty(Config::class, 'items'))->setValue(null, $this->configPrima);
        TosEnforcement::resetCache();
        foreach (glob($this->cartella . '/{,.}*', GLOB_BRACE) ?: [] as $f) {
            if (!in_array(basename($f), ['.', '..'], true) && is_file($f)) {
                @unlink($f);
            }
        }
        @rmdir($this->cartella);
    }

    /**
     * Le voci di middleware che il Kernel non sa risolvere, come
     * `METODO percorso: voce`.
     *
     * @return array{0: list<string>, 1: int} sconosciute e voci controllate
     */
    public static function sconosciute(Router $router): array
    {
        $fuori = [];
        $voci = 0;
        foreach ($router->routes() as $rotta) {
            foreach ($rotta->middleware as $voce) {
                $voci++;
                try {
                    Kernel::middlewareDellaVoce($voce);
                } catch (\LogicException) {
                    $fuori[] = $rotta->methods[0] . ' ' . $rotta->pattern . ': ' . $voce;
                }
            }
        }
        return [$fuori, $voci];
    }

    private function rotteDelSito(): Router
    {
        $router = new Router();
        require \dirname(__DIR__, 3) . '/routes/web.php';
        return $router;
    }

    #[Test]
    public function ogni_alias_delle_rotte_del_sito_esiste(): void
    {
        [$sconosciute, $voci] = self::sconosciute($this->rotteDelSito());

        // Una regola che non guarda niente dice sempre di sì: le voci di
        // middleware erano più di duemila il 23/9/2026.
        $this->assertGreaterThan(1000, $voci, 'le voci controllate sono quelle di routes/web.php, non zero');
        $this->assertSame([], $sconosciute, sprintf(
            "%d voci di middleware in routes/web.php non esistono nel Kernel: la rotta risponderebbe 500.\n\n"
            . "Correggi il nome o aggiungi l'alias a Kernel::MIDDLEWARE.\n\n  %s",
            \count($sconosciute),
            implode("\n  ", $sconosciute)
        ));
    }

    /** Il verso opposto della prova qui sopra: con un refuso, la stessa regola lo trova. */
    #[Test]
    public function un_refuso_in_una_rotta_si_vede(): void
    {
        $router = new Router();
        $router->group(['middleware' => ['auth', 'role:teacher', 'csfr']], function (Router $r): void {
            $r->post('/api/teacher/sonda', static fn(): Response => Response::json(['ok' => true]))
                ->middleware('rate:content,60', 'atuh');
        });
        $router->get('/sana', static fn(): Response => Response::html('ok'))->middleware('auth', 'log');

        [$sconosciute, $voci] = self::sconosciute($router);

        $this->assertSame(7, $voci);
        $this->assertSame([
            'POST /api/teacher/sonda: csfr',
            'POST /api/teacher/sonda: atuh',
        ], $sconosciute);
    }

    #[Test]
    public function ogni_alias_registrato_serve_a_qualche_rotta(): void
    {
        $usati = [];
        foreach ($this->rotteDelSito()->routes() as $rotta) {
            foreach ($rotta->middleware as $voce) {
                $usati[explode(':', $voce, 2)[0]] = true;
            }
        }

        $morti = array_values(array_diff(array_keys(Kernel::MIDDLEWARE), array_keys($usati)));

        $this->assertSame([], $morti, sprintf(
            "Alias registrati nel Kernel che nessuna rotta usa: %s.\n\n"
            . "Un middleware globale lo applica handle(), non un alias; uno morto va tolto.",
            implode(', ', $morti)
        ));
    }

    /**
     * A runtime: una rotta con un alias sconosciuto non gira senza la sua
     * porta. Il Kernel risponde 500, il controller non parte, e il registro
     * dice quale nome manca.
     */
    #[Test]
    public function una_rotta_con_un_alias_sconosciuto_risponde_500_e_non_gira(): void
    {
        $router = new Router();
        $router->group(['middleware' => ['atuh']], function (Router $r): void {
            $r->get('/area-docente/sonda', function (): Response {
                $this->esecuzioni++;
                return Response::html('servita senza porta');
            });
        });
        $config = new WafConfigRepository(new PDO('sqlite::memory:'));
        $waf = static fn(Request $r, callable $next): Response
            => (new WafMiddleware(configRepo: $config))->handle($r, $next);
        $tos = static fn(Request $r, callable $next): Response => $next($r);
        $kernel = new Kernel($router, $waf, $tos);

        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['REQUEST_URI'] = '/area-docente/sonda';
        $risposta = $kernel->handle(new Request());

        $this->assertSame(0, $this->esecuzioni, 'il controller non gira senza la porta che la rotta chiede');
        $this->assertSame(500, $risposta->status);
        $this->assertStringNotContainsString('servita senza porta', (string)$risposta->body);
        $registro = (string)@file_get_contents($this->cartella . '/php_errors.log');
        $this->assertStringContainsString('[KERNEL] LogicException: Middleware di rotta sconosciuto «atuh»', $registro);
    }

    /**
     * Il verso opposto: la stessa rotta con un alias vero passa dalla sua
     * porta. `log` chiama `$next` e, senza sessione, non scrive niente.
     */
    #[Test]
    public function la_stessa_rotta_con_un_alias_vero_passa_dalla_porta(): void
    {
        $router = new Router();
        $router->group(['middleware' => ['log']], function (Router $r): void {
            $r->get('/area-docente/sonda', function (): Response {
                $this->esecuzioni++;
                return Response::html('servita');
            });
        });
        $config = new WafConfigRepository(new PDO('sqlite::memory:'));
        $waf = static fn(Request $r, callable $next): Response
            => (new WafMiddleware(configRepo: $config))->handle($r, $next);
        $tos = static fn(Request $r, callable $next): Response => $next($r);
        $kernel = new Kernel($router, $waf, $tos);

        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['REQUEST_URI'] = '/area-docente/sonda';
        $risposta = $kernel->handle(new Request());

        $this->assertSame(1, $this->esecuzioni);
        $this->assertSame(200, $risposta->status);
        $this->assertStringContainsString('servita', (string)$risposta->body);
    }
}
