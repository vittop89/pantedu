<?php

declare(strict_types=1);

namespace Tests\Unit\Services\TexCompile;

use App\Core\Config;
use App\Services\TexCompile\SvgToPdfClient;
use App\Services\TexCompile\TexCompileClient;
use App\Services\TexCompile\TexFormatClient;
use App\Services\TexCompile\TexIrraggiungibile;
use App\Services\TexCompile\TikzRenderClient;
use App\Support\Anomalia;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Tests\Support\ServizioFinto;

/**
 * Una compilazione più lunga del tetto del client non è un servizio
 * irraggiungibile (20/9/2026).
 *
 * cURL dà errno 28 in due casi che non si somigliano per niente:
 *
 *   - i pacchetti si perdono e la connessione non si stabilisce mai (un
 *     firewall che scarta): il servizio **non si raggiunge**;
 *   - la connessione c'è, il servizio sta lavorando, e scade il tetto del
 *     client: il documento è **troppo pesante**.
 *
 * Fino al 20 settembre 2026 il secondo caso scriveva un'anomalia
 * `tex_irraggiungibile`, che la diagnostica trasforma in una mail e che manda a
 * guardare rete e firewall. I tetti del servizio stanno sopra quelli dei client
 * (compile: 30 s per passata contro i 35 s di `tryDefault()`; TikZ: 20+20 s
 * contro i 25 s del client), quindi il falso allarme non era raro: era la
 * regola per ogni documento pesante. E un rapporto che non è mai pulito, dopo
 * un mese, non lo apre più nessuno.
 *
 * Li separa `CURLINFO_CONNECT_TIME`, letto prima di `curl_close()`.
 */
final class TexCompilazioneLentaTest extends TestCase
{
    private string $cartella = '';
    private mixed $logsPrima = null;
    private string|false $errorLogPrima = false;
    private ?ServizioFinto $finto = null;

    protected function setUp(): void
    {
        $this->cartella = sys_get_temp_dir() . '/pantedu-tex-lento-' . bin2hex(random_bytes(6));
        mkdir($this->cartella, 0700, true);
        $this->logsPrima = Config::get('app.paths.logs');
        Config::set('app.paths.logs', $this->cartella);
        $this->errorLogPrima = ini_set('error_log', $this->cartella . '/php_errors.log');
    }

    protected function tearDown(): void
    {
        $this->finto?->ferma();
        Config::set('app.paths.logs', $this->logsPrima);
        ini_set('error_log', $this->errorLogPrima === false ? '' : $this->errorLogPrima);
        foreach (glob($this->cartella . '/{,.}*', GLOB_BRACE) ?: [] as $f) {
            if (is_file($f)) {
                @unlink($f);
            }
        }
        @rmdir($this->cartella);
    }

    /** Il registro degli errori PHP di questa prova. */
    private function errori(): string
    {
        return (string)@file_get_contents($this->cartella . '/php_errors.log');
    }

    /**
     * Ogni modo in cui l'applicazione parla con il servizio TeX, con il tetto
     * del client abbassato a due secondi: il servizio finto ne dorme quattro.
     *
     * @return array<string, array{0: \Closure(string): array<string, mixed>, 1: string}>
     */
    public static function chiamate(): array
    {
        return [
            'compilazione' => [
                static fn(string $e): array => (new TexCompileClient($e, 's', 2))->compile('x', 'prova'),
                '/compile',
            ],
            'pacchetto di file' => [
                static fn(string $e): array => (new TexCompileClient($e, 's', 2))
                    ->compileBundle([['path' => 'main.tex', 'content' => 'x']], 'main.tex', 'prova'),
                '/compile-bundle',
            ],
            'tikz' => [
                static fn(string $e): array => (new TikzRenderClient($e, 's', 2))->render('\\draw (0,0) -- (1,1);'),
                '/render-tikz',
            ],
            'svg in pdf' => [
                static fn(string $e): array => (new SvgToPdfClient($e, 's', 2))->convert('<svg/>'),
                '/svg-to-pdf',
            ],
            'formattazione' => [
                static fn(string $e): array => (new TexFormatClient($e, 's', 2))->format('x'),
                '/format-tex',
            ],
        ];
    }

    /**
     * @param \Closure(string): array<string, mixed> $chiama
     */
    #[Test]
    #[DataProvider('chiamate')]
    public function il_servizio_che_impiega_troppo_non_e_un_guasto_di_rete(\Closure $chiama, string $rotta): void
    {
        // Il servizio accetta la connessione e poi lavora: è una compilazione
        // lunga, non un servizio fermo.
        $this->finto = ServizioFinto::avvia([$rotta => [200, '{"ok":true}', 4]]);

        $esito = $chiama($this->finto->url());

        self::assertFalse((bool)$esito['ok']);
        self::assertTrue((bool)($esito['tempo_scaduto'] ?? false), 'il client deve dire che è scaduto il suo tetto');
        self::assertSame(
            TexIrraggiungibile::MESSAGGIO_LENTO,
            (string)$esito['log'],
            'al docente si dice che il documento è troppo pesante, non che il servizio è giù',
        );
        self::assertSame([], Anomalia::recenti(), 'nessuna anomalia: il servizio risponde, è il documento a essere lungo');
        self::assertStringContainsString('[tex] compilazione troppo lunga', $this->errori());
        self::assertStringNotContainsString('servizio irraggiungibile', $this->errori());
    }

    /**
     * Controprova: senza connessione, l'errno 28 resta un servizio
     * irraggiungibile, con la sua anomalia. È il caso del firewall che scarta.
     *
     * Si misura sulla classificazione, non sulla rete: un indirizzo che fa
     * scadere la connessione non si può inventare in una prova unitaria senza
     * dipendere da com'è fatta la rete di chi la esegue.
     */
    #[Test]
    public function senza_connessione_il_tempo_scaduto_resta_un_servizio_irraggiungibile(): void
    {
        self::assertFalse(
            TexIrraggiungibile::compilazioneTroppoLunga(28, false),
            'errno 28 senza connessione: il servizio non si raggiunge',
        );
        self::assertTrue(TexIrraggiungibile::compilazioneTroppoLunga(28, true));
        // Gli altri errori restano guasti anche a connessione stabilita: un 52
        // o un 56 sono un worker di uvicorn morto a metà richiesta.
        self::assertFalse(TexIrraggiungibile::compilazioneTroppoLunga(52, true));
        self::assertFalse(TexIrraggiungibile::compilazioneTroppoLunga(7, true));

        $messaggio = TexIrraggiungibile::segnala(28, 'http://127.0.0.1:8001', 'compile', false);
        self::assertSame(TexIrraggiungibile::MESSAGGIO, $messaggio);
        $anomalie = array_values(array_filter(
            Anomalia::recenti(),
            static fn(array $v): bool => ($v['codice'] ?? '') === 'tex_irraggiungibile',
        ));
        self::assertCount(1, $anomalie);
        self::assertSame('tempo scaduto', $anomalie[0]['dettagli']['classe'] ?? null);
    }

    /**
     * Una compilazione troppo lunga non si ripete: il servizio sta ancora
     * lavorando su quel documento, e rimandarglielo gli raddoppia il carico.
     *
     * Si misura contando le richieste arrivate al servizio finto.
     */
    #[Test]
    public function una_compilazione_troppo_lunga_non_si_ritenta(): void
    {
        $this->finto = ServizioFinto::avvia(['/compile' => [200, '{"ok":true}', 3]]);
        $client = new TexCompileClient($this->finto->url(), 's', 1);

        $esito = $client->compile('x', 'prova');
        self::assertTrue((bool)($esito['tempo_scaduto'] ?? false));

        // La condizione del ciclo di tentativi di VerificaCompileController,
        // copiata qui: `tempo_scaduto` la spegne.
        $ritentabile = static fn(array $r): bool => empty($r['tempo_scaduto'])
            && (($r['http_status'] ?? 0) === 0 || ($r['http_status'] ?? 0) >= 500);
        self::assertFalse($ritentabile($esito));

        // Controprova: un servizio che non c'è resta ritentabile.
        $porta = ServizioFinto::portaChiusa();
        $spento = (new TexCompileClient("http://127.0.0.1:{$porta}", 's', 1))->compile('x', 'prova');
        self::assertTrue($ritentabile($spento));
    }
}
