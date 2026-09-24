<?php

declare(strict_types=1);

namespace Tests\Unit\Core;

use App\Core\Config;
use App\Core\Kernel;
use App\Core\Request;
use App\Core\Response;
use App\Core\Router;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

/**
 * Il corpo della richiesta visto dal client, con il Kernel e il Router veri
 * (23/9/2026, revisione architetturale A-52).
 *
 * Il difetto: quattro copie di `readJsonBody()` e una ventina di letture di
 * `php://input` fatte a mano, e un corpo troppo grande che rispondeva 413 o
 * 400 a seconda del controller (TeacherPrintController e le API delle
 * verifiche: 400; PrintInfoController: 413). Adesso il corpo lo legge solo
 * `Request` (ADR-034, ADR-049), con un limite; quello che non si può usare
 * lancia CorpoNonValido, e se nessun controller la prende la risposta la dà
 * il Kernel: 413 per un corpo troppo grande, 400 per uno vuoto o non JSON,
 * sempre in JSON e mai la pagina di errore 500.
 *
 * Qui una rotta che legge il corpo con `jsonObbligatorio(64)` e lo restituisce:
 * oltre il limite 413, JSON rotto 400, corpo valido 200 con i dati. Il WAF e
 * il gate ToS passano la richiesta (non sono loro l'oggetto), il database è
 * spento, i registri stanno in una cartella temporanea.
 *
 * Controprova (23/9/2026): senza il ramo di CorpoNonValido nel Kernel, i tre
 * casi di errore rispondono 500 con la pagina HTML.
 */
final class CorpoDellaRichiestaNelKernelTest extends TestCase
{
    private string $cartella = '';

    /** @var array<string, mixed> */
    private array $configPrima = [];

    private string|false $errorLogPrima = false;

    /** @var array<string, mixed> */
    private array $serverPrima = [];

    protected function setUp(): void
    {
        $this->cartella = sys_get_temp_dir() . '/pantedu-corpo-kernel-' . bin2hex(random_bytes(6));
        mkdir($this->cartella, 0700, true);
        /** @var array<string, mixed> $prima */
        $prima = (new ReflectionProperty(Config::class, 'items'))->getValue();
        $this->configPrima = $prima;
        Config::set('database.enabled', false);
        Config::set('app.debug', false);
        Config::set('app.paths.logs', $this->cartella);
        Config::set('app.paths.data_base', $this->cartella);
        Config::set('app.paths.storage', $this->cartella);
        $this->errorLogPrima = ini_set('error_log', $this->cartella . '/php_errors.log');
        $this->serverPrima = $_SERVER;
        $_SESSION = [];
        $_GET = [];
        $_POST = [];
        $_SERVER['REMOTE_ADDR'] = '127.0.0.1';
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_SERVER['REQUEST_URI'] = '/api/eco';
        $_SERVER['CONTENT_TYPE'] = 'application/json';
        unset($_SERVER['CONTENT_LENGTH']);
    }

    protected function tearDown(): void
    {
        ini_set('error_log', $this->errorLogPrima === false ? '' : $this->errorLogPrima);
        (new ReflectionProperty(Config::class, 'items'))->setValue(null, $this->configPrima);
        $_SERVER = $this->serverPrima;
        $_SESSION = [];
        foreach (glob($this->cartella . '/{,.}*', GLOB_BRACE) ?: [] as $f) {
            if (is_file($f)) {
                @unlink($f);
            }
        }
        @rmdir($this->cartella);
    }

    private function kernel(): Kernel
    {
        $router = new Router();
        $router->post('/api/eco', static fn(Request $req): Response => Response::ok(['dati' => $req->jsonObbligatorio(64)]));
        $passa = static fn(Request $r, callable $next): Response => $next($r);
        return new Kernel($router, $passa, $passa);
    }

    /** @return array{0: int, 1: array<mixed>|null} */
    private function invia(string $corpo): array
    {
        $risposta = $this->kernel()->handle(new Request($corpo));
        $json = json_decode((string)$risposta->body, true);
        return [$risposta->status, is_array($json) ? $json : null];
    }

    #[Test]
    public function un_corpo_oltre_il_limite_e_413(): void
    {
        [$stato, $json] = $this->invia('{"testo":"' . str_repeat('x', 200) . '"}');

        self::assertSame(413, $stato);
        self::assertSame(['ok' => false, 'error' => 'payload_too_large'], $json);
    }

    #[Test]
    public function un_json_rotto_e_400(): void
    {
        [$stato, $json] = $this->invia('{"testo":');

        self::assertSame(400, $stato);
        self::assertSame(['ok' => false, 'error' => 'invalid_json'], $json);
    }

    #[Test]
    public function un_corpo_vuoto_e_400(): void
    {
        [$stato, $json] = $this->invia('');

        self::assertSame(400, $stato);
        self::assertSame(['ok' => false, 'error' => 'empty_payload'], $json);
    }

    #[Test]
    public function un_corpo_valido_arriva_al_controller(): void
    {
        [$stato, $json] = $this->invia('{"a":1}');

        self::assertSame(200, $stato);
        self::assertSame(['ok' => true, 'dati' => ['a' => 1]], $json);
    }

    #[Test]
    public function un_errore_del_controller_resta_un_500(): void
    {
        // L'altro verso: il ramo nuovo del Kernel non prende tutto.
        $router = new Router();
        $router->post('/api/eco', static function (): Response {
            throw new \RuntimeException('guasto');
        });
        $passa = static fn(Request $r, callable $next): Response => $next($r);
        $risposta = (new Kernel($router, $passa, $passa))->handle(new Request('{}'));

        self::assertSame(500, $risposta->status);
    }
}
