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
 * Quando il servizio TeX non risponde, lo si scrive dove qualcuno lo legge, e al
 * docente non si mostra l'indirizzo interno (19/9/2026).
 *
 * Il guasto dell'8 settembre 2026 è durato undici giorni anche perché non
 * lasciava traccia: i client restituivano «Errore di rete: [7] Failed to
 * connect to 127.0.0.1 port 8001 …» al docente, e a nessun altro. Nessuna riga
 * nei registri, nessuna anomalia. E quel messaggio portava sulla pagina
 * l'indirizzo e la porta di un servizio interno.
 *
 * Adesso ogni client, quando cURL non arriva al servizio (errno diverso da
 * zero), fa tre cose: una riga in `error_log` (nel container finisce in
 * `docker logs`), un'anomalia `tex_irraggiungibile` nel registro che la
 * diagnostica legge, e al docente «Il servizio di compilazione non risponde».
 */
final class TexIrraggiungibileTest extends TestCase
{
    private string $cartella = '';
    private mixed $logsPrima = null;
    private string|false $errorLogPrima = false;

    protected function setUp(): void
    {
        $this->cartella = sys_get_temp_dir() . '/pantedu-tex-irraggiungibile-' . bin2hex(random_bytes(6));
        mkdir($this->cartella, 0700, true);
        $this->logsPrima = Config::get('app.paths.logs');
        Config::set('app.paths.logs', $this->cartella);
        $this->errorLogPrima = ini_set('error_log', $this->cartella . '/php_errors.log');
    }

    protected function tearDown(): void
    {
        Config::set('app.paths.logs', $this->logsPrima);
        ini_set('error_log', $this->errorLogPrima === false ? '' : $this->errorLogPrima);
        foreach (glob($this->cartella . '/{,.}*', GLOB_BRACE) ?: [] as $f) {
            if (is_file($f)) {
                @unlink($f);
            }
        }
        @rmdir($this->cartella);
    }

    /**
     * Ogni modo in cui l'applicazione parla con il servizio TeX.
     *
     * @return array<string, array{0: \Closure(string): string}>
     */
    public static function chiamate(): array
    {
        return [
            'compilazione' => [static fn(string $e): string => (string)(new TexCompileClient($e, 's'))->compile('x', 'prova')['log']],
            'compilazione con artefatti' => [static fn(string $e): string => (string)(new TexCompileClient($e, 's'))->compile('x', 'prova', withArtifacts: true)['log']],
            'pacchetto di file' => [static fn(string $e): string => (string)(new TexCompileClient($e, 's'))->compileBundle([['path' => 'main.tex', 'content' => 'x']], 'main.tex', 'prova')['log']],
            'synctex' => [static fn(string $e): string => (string)((new TexCompileClient($e, 's'))->synctexEdit('x', 1, 1.0, 1.0)['error'] ?? '')],
            'tikz' => [static fn(string $e): string => (new TikzRenderClient($e, 's'))->render('\\draw (0,0) -- (1,1);')['log']],
            'svg in pdf' => [static fn(string $e): string => (new SvgToPdfClient($e, 's'))->convert('<svg/>')['log']],
            'formattazione' => [static fn(string $e): string => (new TexFormatClient($e, 's'))->format('x')['log']],
        ];
    }

    /** @param \Closure(string): string $chiama */
    #[Test]
    #[DataProvider('chiamate')]
    public function il_docente_legge_un_messaggio_senza_indirizzi(\Closure $chiama): void
    {
        $porta = ServizioFinto::portaChiusa();
        $messaggio = $chiama("http://127.0.0.1:{$porta}");

        self::assertSame(TexIrraggiungibile::MESSAGGIO, $messaggio);
        self::assertStringNotContainsString('127.0.0.1', $messaggio);
        self::assertStringNotContainsString((string)$porta, $messaggio);
    }

    /** @param \Closure(string): string $chiama */
    #[Test]
    #[DataProvider('chiamate')]
    public function il_guasto_finisce_nel_registro_degli_errori_e_fra_le_anomalie(\Closure $chiama): void
    {
        $porta = ServizioFinto::portaChiusa();
        $chiama("http://127.0.0.1:{$porta}");

        $errori = (string)@file_get_contents($this->cartella . '/php_errors.log');
        self::assertStringContainsString('[tex] servizio irraggiungibile errno=7', $errori);

        $anomalie = array_values(array_filter(
            Anomalia::recenti(),
            static fn(array $v): bool => ($v['codice'] ?? '') === 'tex_irraggiungibile',
        ));
        self::assertCount(1, $anomalie, 'una riga tex_irraggiungibile nel registro delle anomalie');
        self::assertSame(7, $anomalie[0]['dettagli']['errno'] ?? null);
        self::assertSame("127.0.0.1:{$porta}", $anomalie[0]['dettagli']['servizio'] ?? null);
        self::assertSame('connessione rifiutata', $anomalie[0]['dettagli']['classe'] ?? null);
    }

    #[Test]
    public function un_errore_del_latex_non_e_un_servizio_irraggiungibile(): void
    {
        // Controprova: il servizio risponde, con un errore suo. Non è un guasto
        // di rete, e il log del servizio arriva al docente com'è.
        $finto = ServizioFinto::avvia([
            '/compile' => [422, '{"ok":false,"log":"! Undefined control sequence."}'],
        ]);
        try {
            $esito = (new TexCompileClient($finto->url(), 's'))->compile('x', 'prova');
        } finally {
            $finto->ferma();
        }

        self::assertFalse($esito['ok']);
        self::assertSame(422, $esito['http_status']);
        self::assertSame('! Undefined control sequence.', $esito['log']);
        self::assertSame([], Anomalia::recenti(), 'nessuna anomalia: il servizio ha risposto');
        self::assertStringNotContainsString('[tex]', (string)@file_get_contents($this->cartella . '/php_errors.log'));
    }
}
