<?php

declare(strict_types=1);

namespace Tests\Unit\Middleware;

use App\Core\Config;
use App\Core\Database;
use App\Core\Kernel;
use App\Core\Request;
use App\Core\Response;
use App\Core\Router;
use App\Middleware\SecurityHeadersMiddleware;
use App\Services\ContractRenderer;
use App\Support\Csp;
use App\Support\TosEnforcement;
use PDO;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

/**
 * Con la CSP rigorosa il nonce lo portano solo gli script dell'applicazione
 * (23/9/2026, revisione architetturale A-16, R-3 passo 4).
 *
 * SecurityHeadersMiddleware generava il nonce dopo aver reso la risposta e lo
 * timbrava con una regex su ogni `<script>` del corpo. Con 'strict-dynamic' uno
 * script arrivato dal contenuto — un corpo che il sanificatore non ha fermato,
 * il file di una mappa che chiudeva lo script (A-3, fino alla #246) — riceveva
 * il nonce ed era eseguito: la CSP non difendeva proprio dove serve.
 *
 * Adesso il nonce nasce prima della pipeline (Kernel::handle →
 * Csp::nuovaRichiesta()), le viste lo scrivono negli script dell'applicazione,
 * e il middleware lo mette solo nell'intestazione.
 *
 * Qui: Kernel e Router veri, una rotta che rende una pagina con due script
 * dell'applicazione (scritti con Csp::attributo()), il corpo di una verifica
 * con due figure TikZ reso da ContractRenderer (contenuto del docente, con i
 * suoi `<script type="text/tikz">`) e uno `<script>` ostile nel contenuto. La
 * modalità rigorosa la dà `waf_config`, come in produzione: la connessione al
 * database è un SQLite in memoria con quella riga (quella vera si rimette a
 * fine prova).
 *
 * Controprova: rimettendo nel middleware la timbratura di prima
 * (`preg_replace('/<script\b(?![^>]*\bnonce=)/i', …)` sul corpo HTML) la
 * prima prova diventa rossa: lo script ostile e le due figure ricevono il nonce.
 */
final class NonceSoloAgliScriptDellAppTest extends TestCase
{
    /** Uno script arrivato dal contenuto, come lo lasciava passare il file di una mappa prima della #246. */
    private const OSTILE = '<script>document.title="eseguito"</script>';

    private string $cartella = '';

    /** @var array<string, mixed> */
    private array $configPrima = [];

    private ?PDO $pdoPrima = null;

    protected function setUp(): void
    {
        $this->cartella = sys_get_temp_dir() . '/pantedu-nonce-' . bin2hex(random_bytes(6));
        mkdir($this->cartella, 0700, true);

        $items = new ReflectionProperty(Config::class, 'items');
        /** @var array<string, mixed> $prima */
        $prima = $items->getValue();
        $this->configPrima = $prima;
        Config::set('app.debug', false);
        Config::set('app.paths.logs', $this->cartella);
        Config::set('app.paths.data_base', $this->cartella);
        Config::set('app.paths.storage', $this->cartella);
        Config::set('multitenancy.tos_enforce', false);
        Config::set('security.csp_mode', 'relaxed');
        TosEnforcement::resetCache();

        // La modalità la decide waf_config, come in produzione: un SQLite in
        // memoria con la sola riga csp_mode al posto della connessione vera.
        $pdo = new ReflectionProperty(Database::class, 'pdo');
        $vecchia = $pdo->getValue();
        $this->pdoPrima = $vecchia instanceof PDO ? $vecchia : null;
        $sqlite = new PDO('sqlite::memory:');
        $sqlite->exec('CREATE TABLE waf_config (config_key TEXT PRIMARY KEY, config_value TEXT)');
        $sqlite->exec("INSERT INTO waf_config VALUES ('csp_mode', 'strict')");
        $pdo->setValue(null, $sqlite);

        foreach (array_keys($_SERVER) as $k) {
            if (str_starts_with((string)$k, 'HTTP_')) {
                unset($_SERVER[$k]);
            }
        }
        $_SERVER['REMOTE_ADDR'] = '127.0.0.1';
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_GET = [];
        $_POST = [];
    }

    protected function tearDown(): void
    {
        (new ReflectionProperty(Database::class, 'pdo'))->setValue(null, $this->pdoPrima);
        (new ReflectionProperty(Config::class, 'items'))->setValue(null, $this->configPrima);
        TosEnforcement::resetCache();
        foreach (glob($this->cartella . '/{,.}*', GLOB_BRACE) ?: [] as $f) {
            if (is_file($f)) {
                @unlink($f);
            }
        }
        @rmdir($this->cartella);
    }

    /** Il corpo di una verifica con due figure TikZ, reso dal renderer vero (contenuto del docente). */
    private static function verificaConTikz(): string
    {
        $json = (string)file_get_contents(dirname(__DIR__, 2) . '/js-unit/fixtures/verifica-con-tikz.json');
        $gruppo = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($gruppo);
        return (new ContractRenderer([], true))->renderGroupPublic($gruppo);
    }

    private function kernel(): Kernel
    {
        $router = new Router();
        $router->get('/pagina', static fn(): Response => Response::html(
            '<!doctype html><html><head>'
            . '<script' . Csp::attributo() . ' type="module" src="/js/app.js"></script>'
            . '</head><body><main>'
            . self::verificaConTikz()
            . self::OSTILE
            . '</main><script' . Csp::attributo() . '>window.app = 1;</script></body></html>'
        ));
        $passa = static fn(Request $r, callable $next): Response => $next($r);
        return new Kernel($router, $passa, $passa);
    }

    private function richiesta(): Request
    {
        $_SERVER['REQUEST_URI'] = '/pagina';
        return new Request();
    }

    /** Il nonce dell'intestazione CSP applicata. */
    private static function nonceDellIntestazione(Response $r): string
    {
        $csp = (string)($r->headers['Content-Security-Policy'] ?? '');
        self::assertSame(1, preg_match("/'nonce-([A-Za-z0-9+\\/=]+)'/", $csp, $m), "CSP rigorosa con il nonce: {$csp}");
        self::assertStringContainsString("'strict-dynamic'", $csp);
        return $m[1];
    }

    #[Test]
    public function unoScriptDelContenutoNonRiceveIlNonce(): void
    {
        $r = $this->kernel()->handle($this->richiesta());

        self::assertSame(200, $r->status);
        $nonce = self::nonceDellIntestazione($r);
        // Lo script ostile arriva com'era: senza nonce, e il browser non lo esegue.
        self::assertStringContainsString('<main>', $r->body);
        self::assertStringContainsString(self::OSTILE, $r->body);
        // Le due figure TikZ del contenuto: nessun nonce (sono dati, non codice).
        self::assertSame(2, substr_count($r->body, '<script type="text/tikz"'));
        // Il nonce c'è solo sui due script dell'applicazione, ed è quello dell'intestazione.
        preg_match_all('/<script\b[^>]*\bnonce="([^"]*)"/i', $r->body, $m);
        self::assertSame([$nonce, $nonce], $m[1], 'solo i due script scritti con Csp::attributo()');
        self::assertSame(5, substr_count(strtolower($r->body), '<script'), 'due dell\'app, due figure TikZ, quello ostile');
    }

    #[Test]
    public function ogniRichiestaHaIlSuoNonceNellIntestazioneENellaPagina(): void
    {
        $kernel = $this->kernel();
        $prima = $kernel->handle($this->richiesta());
        $seconda = $kernel->handle($this->richiesta());

        $n1 = self::nonceDellIntestazione($prima);
        $n2 = self::nonceDellIntestazione($seconda);
        self::assertNotSame($n1, $n2, 'un nonce nuovo a ogni richiesta');
        self::assertSame(2, substr_count($prima->body, 'nonce="' . $n1 . '"'));
        self::assertSame(2, substr_count($seconda->body, 'nonce="' . $n2 . '"'));
        self::assertStringNotContainsString($n1, $seconda->body);
    }

    /**
     * Il nonce nasce prima di `$next()`: quello che la vista scrive mentre la
     * risposta si rende è lo stesso che finisce nell'intestazione. Controprova:
     * generandolo dopo `$next()` con un `random_bytes` suo, come faceva il
     * middleware, l'intestazione e la pagina non coincidono.
     */
    #[Test]
    public function ilNonceDellaVistaEQuelloDellIntestazione(): void
    {
        Csp::nuovaRichiesta();
        $scritto = '';
        $r = (new SecurityHeadersMiddleware('strict'))->handle(
            $this->richiesta(),
            static function () use (&$scritto): Response {
                $scritto = Csp::nonce();
                return Response::html('<script' . Csp::attributo() . '>1</script>');
            }
        );

        self::assertSame($scritto, self::nonceDellIntestazione($r));
        self::assertSame('<script nonce="' . $scritto . '">1</script>', $r->body);
    }

    /** In nessuna modalità il middleware tocca il corpo. */
    #[Test]
    public function inNessunaModalitaIlCorpoCambia(): void
    {
        $corpo = self::verificaConTikz() . self::OSTILE . '<script src="/x.js"></script>';
        foreach (['strict', 'report-only', 'relaxed'] as $modo) {
            Csp::nuovaRichiesta();
            $r = (new SecurityHeadersMiddleware($modo))->handle(
                $this->richiesta(),
                static fn(): Response => Response::html($corpo)
            );
            self::assertSame($corpo, $r->body, "modalità {$modo}: il corpo è quello che la rotta ha reso");

            $intestazione = $modo === 'report-only' ? 'Content-Security-Policy-Report-Only' : 'Content-Security-Policy';
            self::assertArrayHasKey($intestazione, $r->headers);
            self::assertSame(
                $modo !== 'relaxed',
                str_contains($r->headers[$intestazione], "'nonce-" . Csp::nonce() . "'"),
                "modalità {$modo}: il nonce della richiesta nell'intestazione solo se la policy è quella rigorosa"
            );
        }
    }
}
