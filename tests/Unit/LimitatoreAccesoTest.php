<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Core\Config;
use App\Core\Request;
use App\Core\Response;
use App\Middleware\RateLimitMiddleware;
use App\Services\RateLimitStore;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Con il limitatore acceso, una richiesta qualunque oltre il limite riceve 429.
 *
 * ── Perché (revisione architetturale del 23/9/2026, A-4) ──────────────────
 *
 * Il rilievo riguardava il `.env` versionato, che spegneva il limitatore
 * anche nel container di produzione. `EnvTracciatoTest` guarda il file; questa
 * prova guarda l'ultimo anello, il middleware: quando
 * `security.rate_limit_disabled` è falsa e la richiesta non porta
 * `X-Pantedu-Rate-Limit`, cioè una richiesta qualunque in produzione, il
 * limite si applica.
 *
 * Fino a quel giorno nessuna prova lo guardava. Quelle del middleware
 * (FinestraDelLimitatoreTest, la spec end-to-end dei limiti) mandano sempre
 * `X-Pantedu-Rate-Limit: enforce`, perché nelle prove il limitatore è spento.
 * Con la condizione del bypass ridotta a `if (!$enforce)` il limitatore
 * sarebbe spento dappertutto, produzione compresa, e la suite restava verde
 * (misurato con quella mutazione il 23/9/2026, nella verifica della
 * correzione). Con questa prova diventa rossa.
 *
 * Nei due versi: acceso e senza intestazione, la richiesta oltre il limite
 * riceve 429; spento e senza intestazione, passano tutte. Il secondo verso
 * tiene onesto il primo: un middleware che respingesse sempre passerebbe
 * l'altro.
 */
final class LimitatoreAccesoTest extends TestCase
{
    private const LIMITE = 3;

    private mixed $configurazionePrima = null;

    protected function setUp(): void
    {
        $this->configurazionePrima = Config::get('security.rate_limit_disabled');
    }

    protected function tearDown(): void
    {
        // La configurazione è statica e resterebbe alle prove che seguono. La
        // sessione e $_SERVER li ripulisce l'estensione RichiestaPulitaFraLeProve.
        Config::set('security.rate_limit_disabled', $this->configurazionePrima);
    }

    /**
     * Gli esiti di `$quante` POST in fila, dallo stesso indirizzo, senza
     * intestazione, con un limite di tre.
     *
     * @return list<int>
     */
    private function esiti(int $quante): array
    {
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_SERVER['REQUEST_URI']    = '/segnalazione-contenuti';
        $_SERVER['REMOTE_ADDR']    = '203.0.113.9';
        unset($_SERVER['HTTP_X_PANTEDU_RATE_LIMIT']);
        $_POST    = [];
        $_SESSION = [];

        $limitatore = new RateLimitMiddleware(new RateLimitStore('session'));
        $esiti = [];
        for ($i = 0; $i < $quante; $i++) {
            $richiesta = new Request('');
            self::assertArrayNotHasKey(
                'x-pantedu-rate-limit',
                $richiesta->headers,
                'la richiesta deve essere una qualunque, senza intestazione: '
                . 'altrimenti la prova non guarda il caso di A-4'
            );
            $esiti[] = $limitatore->handle(
                $richiesta,
                static fn(Request $r): Response => new Response('passata', 200),
                'prova_acceso',
                (string)self::LIMITE,
            )->status;
        }

        return $esiti;
    }

    #[Test]
    public function acceso_e_senza_intestazione_oltre_il_limite_risponde_429(): void
    {
        Config::set('security.rate_limit_disabled', false);

        self::assertSame(
            [200, 200, 200, 429, 429],
            $this->esiti(self::LIMITE + 2),
            'con il limitatore acceso le prime tre passano e le altre no, anche senza X-Pantedu-Rate-Limit: '
            . 'è la richiesta di chiunque in produzione'
        );
    }

    #[Test]
    public function spento_e_senza_intestazione_passano_tutte(): void
    {
        Config::set('security.rate_limit_disabled', true);

        self::assertSame(
            array_fill(0, self::LIMITE + 2, 200),
            $this->esiti(self::LIMITE + 2),
            'con il limitatore spento nessuna richiesta senza intestazione si respinge: '
            . 'è il bypass delle prove e dello sviluppo'
        );
    }
}
