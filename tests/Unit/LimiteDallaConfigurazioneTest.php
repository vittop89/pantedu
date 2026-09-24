<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Core\Config;
use App\Core\Request;
use App\Core\Response;
use App\Core\Router;
use App\Middleware\RateLimitMiddleware;
use App\Services\RateLimitStore;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Il limite di una rotta può venire dalla configurazione (23/9/2026).
 *
 * ── Il difetto (registro del debito, voce 195) ────────────────────────────
 *
 * `PDF_IMPORT_RATE_GENERIC` e `PDF_IMPORT_RATE_LLM` finivano in
 * `pdf_import.rate.*`, ma le rotte dell'importazione da PDF avevano il
 * numero scritto nella stringa del middleware (`rate:pdf_import,30`): la
 * configurazione non arrivava mai al limitatore, e chi cambiava la variabile
 * non cambiava niente.
 *
 * ── Che cosa guarda ───────────────────────────────────────────────────────
 *
 * Il limitatore con `config:<chiave>` al posto del numero: il limite è quello
 * della configurazione (due richieste passano, la terza no, e con la chiave a
 * cinque ne passano cinque). Una chiave che manca o non è un intero positivo
 * non spegne il limite: vale il limite del ruolo. E le rotte dell'importazione
 * da PDF, lette dal Router vero, prendono il limite dalla configurazione,
 * salvo `/translate`, che ha di proposito un numero suo.
 */
final class LimiteDallaConfigurazioneTest extends TestCase
{
    /** @var array<string, mixed> */
    private array $prima = [];

    private const CHIAVI = ['security.rate_limit_disabled', 'pdf_import.rate.pdf_import'];

    protected function setUp(): void
    {
        foreach (self::CHIAVI as $chiave) {
            $this->prima[$chiave] = Config::get($chiave);
        }
        Config::set('security.rate_limit_disabled', false);
    }

    protected function tearDown(): void
    {
        foreach ($this->prima as $chiave => $valore) {
            Config::set($chiave, $valore);
        }
        $_SESSION = [];
    }

    #[Test]
    public function il_limite_viene_dalla_chiave_di_configurazione(): void
    {
        Config::set('pdf_import.rate.pdf_import', 2);
        self::assertSame([200, 200, 429], $this->esitiNelloStessoSecchio(3, 'config:pdf_import.rate.pdf_import'));

        Config::set('pdf_import.rate.pdf_import', 5);
        self::assertSame(
            [200, 200, 200, 200, 200, 429],
            $this->esitiNelloStessoSecchio(6, 'config:pdf_import.rate.pdf_import'),
            'cambiando la configurazione cambia il limite: è la variabile che prima non faceva niente'
        );
    }

    #[Test]
    public function una_chiave_che_manca_non_spegne_il_limite(): void
    {
        $esiti = $this->esitiNelloStessoSecchio(20, 'config:pdf_import.rate.chiave_che_non_esiste');
        self::assertContains(429, $esiti, 'senza la chiave vale il limite del ruolo, non «nessun limite»');
        self::assertSame(15, count(array_filter($esiti, static fn(int $s): bool => $s === 200)), 'ospite: 15');
    }

    #[Test]
    public function le_rotte_dell_importazione_da_pdf_prendono_il_limite_dalla_configurazione(): void
    {
        $router = new Router();
        require \dirname(__DIR__, 2) . '/routes/web.php';

        $numeri = [];
        $dallaConfigurazione = 0;
        foreach ($router->routes() as $rotta) {
            if (!str_starts_with($rotta->pattern, '/api/teacher/pdf-import/')) {
                continue;
            }
            foreach ($rotta->middleware as $m) {
                if (!preg_match('/^rate:(pdf_import(?:_llm)?),(.+)$/', $m, $x)) {
                    continue;
                }
                if ($x[2] === 'config:pdf_import.rate.' . $x[1]) {
                    $dallaConfigurazione++;
                } else {
                    $numeri[] = $rotta->pattern . ' → ' . $m;
                }
            }
        }

        self::assertGreaterThan(10, $dallaConfigurazione, 'le rotte dell\'importazione da PDF leggono il limite dalla configurazione');
        self::assertSame(
            ['/api/teacher/pdf-import/session/{id}/translate → rate:pdf_import_llm,30'],
            $numeri,
            'solo /translate ha un numero suo (il client la chiama a ripetizione, un pezzo alla volta)'
        );
    }

    /** @return list<int> */
    private function esitiNelloStessoSecchio(int $quante, string $limite): array
    {
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_SERVER['REQUEST_URI']    = '/api/teacher/pdf-import/session/1/cell';
        $_SERVER['REMOTE_ADDR']    = '203.0.113.' . random_int(1, 250);
        unset($_SERVER['HTTP_X_PANTEDU_RATE_LIMIT']);
        $_POST    = [];
        $_SESSION = [];

        $secchio = 'prova_config_' . bin2hex(random_bytes(3));
        $limitatore = new RateLimitMiddleware(new RateLimitStore('session'));
        $esiti = [];
        for ($i = 0; $i < $quante; $i++) {
            $esiti[] = $limitatore->handle(
                new Request(''),
                static fn(Request $r): Response => new Response('passata', 200),
                $secchio,
                $limite,
            )->status;
        }

        return $esiti;
    }
}
