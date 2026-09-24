<?php

declare(strict_types=1);

namespace Tests\Unit\Services\TexCompile;

use App\Services\TexCompile\TexCompileClient;
use App\Services\TexCompile\TexIrraggiungibile;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Tests\Support\ServizioFinto;

/**
 * La sonda del servizio TeX dice se risponde e, se no, perché (19/9/2026).
 *
 * Dall'8 settembre 2026 il container dell'applicazione cercava il TeX sul
 * proprio 127.0.0.1, dove non ascolta nessuno: ogni compilazione avviata dal
 * sito falliva con «Errore di rete: [7] …» e nessun controllo se n'è accorto
 * per undici giorni. `health()` c'era, ma senza un tetto al tempo di
 * connessione e senza dire che cosa non andava: nessuno lo chiamava.
 *
 * La sonda è quella che usano /health/tex, la diagnostica e il rilascio: qui
 * si prova su una porta chiusa (il guasto di produzione) e su un servizio
 * finto che risponde come quello vero.
 */
final class SondaTexTest extends TestCase
{
    private ?ServizioFinto $finto = null;

    protected function tearDown(): void
    {
        $this->finto?->ferma();
    }

    #[Test]
    public function su_una_porta_chiusa_dice_connessione_rifiutata(): void
    {
        $porta = ServizioFinto::portaChiusa();
        $sonda = (new TexCompileClient("http://127.0.0.1:{$porta}", 'segreto-di-prova'))->sonda();

        self::assertFalse($sonda['ok']);
        self::assertSame(7, $sonda['errno'], 'cURL: CURLE_COULDNT_CONNECT');
        self::assertSame(0, $sonda['http']);
        self::assertSame('connessione rifiutata', $sonda['classe']);
    }

    #[Test]
    public function il_servizio_che_risponde_come_il_tex_regge(): void
    {
        // Il corpo è quello di GET /health di tools/tex-compile-vps/app/main.py.
        $this->finto = ServizioFinto::avvia([
            '/health' => [200, '{"status":"ok","service":"tex-compile-vps","version":"1.4.1"}'],
        ]);
        $client = new TexCompileClient($this->finto->url(), 'segreto-di-prova');
        $sonda = $client->sonda();

        self::assertTrue($sonda['ok']);
        self::assertSame(0, $sonda['errno']);
        self::assertSame(200, $sonda['http']);
        self::assertSame('', $sonda['classe']);
        self::assertGreaterThanOrEqual(0, $sonda['ms']);
        self::assertTrue($client->health(), 'health() è la sonda ridotta a un sì o un no');
    }

    #[Test]
    public function un_altro_programma_su_quella_porta_non_e_il_tex(): void
    {
        // Risponde, ma non è il servizio: una pagina qualunque, o un errore.
        $this->finto = ServizioFinto::avvia([
            '/health' => [200, '<html>benvenuto</html>'],
        ]);
        $sonda = (new TexCompileClient($this->finto->url(), 'segreto-di-prova'))->sonda();
        self::assertFalse($sonda['ok']);
        self::assertSame(0, $sonda['errno']);
        self::assertSame('risposta inattesa', $sonda['classe']);

        $this->finto->ferma();
        $this->finto = ServizioFinto::avvia([
            '/health' => [503, '{"status":"ok"}'],
        ]);
        $sonda = (new TexCompileClient($this->finto->url(), 'segreto-di-prova'))->sonda();
        self::assertFalse($sonda['ok'], 'un 503 non è un servizio che regge, qualunque cosa dica il corpo');
        self::assertSame(503, $sonda['http']);
        self::assertSame('risposta inattesa', $sonda['classe']);
    }

    #[Test]
    public function gli_errori_di_rete_hanno_un_nome(): void
    {
        self::assertSame('connessione rifiutata', TexIrraggiungibile::classe(7));
        self::assertSame('tempo scaduto', TexIrraggiungibile::classe(28));
        self::assertSame('nome non risolto', TexIrraggiungibile::classe(6));
        self::assertSame('errore di rete', TexIrraggiungibile::classe(35));
    }
}
