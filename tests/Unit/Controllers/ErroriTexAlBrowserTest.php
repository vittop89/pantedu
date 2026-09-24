<?php

declare(strict_types=1);

namespace Tests\Unit\Controllers;

use App\Controllers\TexAdhocCompileController;
use App\Controllers\TikzRenderController;
use App\Core\Config;
use App\Core\Request;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Tests\Support\ServizioFinto;

/**
 * Gli errori di pdflatex arrivano al browser (24/9/2026).
 *
 * Il servizio TeX compila in nonstopmode e un PDF esce quasi sempre, anche con
 * errori: il modal «Editor TikZ avanzato» mostrava la figura a metà e diceva
 * «ok», e il log compariva solo quando il PDF non usciva affatto. Qui, con un
 * servizio finto che risponde come quello vero e i log veri di pdflatex
 * (`tests/fixtures/log-pdflatex/`):
 *
 *   - il modal (`/api/tex/compile-adhoc-pdf`): PDF con errori → 422
 *     `tex_errors` con gli errori, l'estratto e il PDF parziale; PDF pulito →
 *     il PDF, come prima; niente PDF → 422 con gli errori;
 *   - l'anteprima SVG (`/tikz/render`): gli errori del servizio passano al
 *     browser, e con un servizio di prima (senza `errors`) si ricavano dal log.
 */
final class ErroriTexAlBrowserTest extends TestCase
{
    private ?ServizioFinto $finto = null;
    private string $dati = '';
    /** @var array<string, mixed> */
    private array $configPrima = [];

    private static function log(string $nome): string
    {
        return (string) file_get_contents(__DIR__ . '/../../fixtures/log-pdflatex/' . $nome . '.log');
    }

    protected function setUp(): void
    {
        foreach (['tex_compile.endpoint', 'tex_compile.secret', 'app.paths.data_base'] as $k) {
            $this->configPrima[$k] = Config::get($k);
        }
        $this->dati = sys_get_temp_dir() . '/pantedu-errori-tex-' . bin2hex(random_bytes(6));
        mkdir($this->dati, 0700, true);
        Config::set('app.paths.data_base', $this->dati);
        Config::set('tex_compile.secret', 'segreto-di-prova');
        $_SESSION = ['autenticato' => true, 'user_id' => 7, 'username' => 'docente.di.prova', 'user_role' => 'teacher'];
    }

    protected function tearDown(): void
    {
        $this->finto?->ferma();
        foreach ($this->configPrima as $k => $v) {
            Config::set($k, $v);
        }
        $_SESSION = [];
        $_POST = [];
        unset($_SERVER['CONTENT_TYPE']);
        exec('rm -rf ' . escapeshellarg($this->dati));
    }

    /** @param array<string, array{0: int, 1: string}> $risposte */
    private function servizio(array $risposte): void
    {
        $this->finto = ServizioFinto::avvia($risposte);
        Config::set('tex_compile.endpoint', 'http://127.0.0.1:' . $this->finto->porta);
    }

    /** @return array{0: int, 1: array<string, mixed>, 2: string} codice, JSON, Content-Type */
    private function modal(): array
    {
        $_SERVER['CONTENT_TYPE'] = 'application/json';
        $sorgente = "\\begin{document}\n\\begin{tikzpicture}\\draw (0,0) -- (1,1);\\end{tikzpicture}\n\\end{document}";
        $res = (new TexAdhocCompileController())->compileTikzPdf(new Request((string) json_encode(['tex' => $sorgente])));
        return [$res->status, (array) json_decode((string) $res->body, true), (string) ($res->headers['Content-Type'] ?? '')];
    }

    private static function compilato(bool $ok, string $log, string $pdf = '%PDF-1.5 finto'): string
    {
        return (string) json_encode([
            'ok' => $ok, 'engine' => 'pdflatex', 'passes' => 1, 'duration_ms' => 5,
            'pdf_b64' => $ok ? base64_encode($pdf) : '', 'log' => $log,
            'synctex_gz_b64' => '', 'aux' => '', 'fls' => '', 'warnings' => [], 'errors' => [],
        ]);
    }

    #[Test]
    public function il_modal_con_un_pdf_pieno_di_errori_li_mostra_con_il_pdf_parziale(): void
    {
        $this->servizio(['/compile' => [200, self::compilato(true, self::log('due-errori'))]]);

        [$codice, $corpo] = $this->modal();

        self::assertSame(422, $codice, 'prima del 24/9/2026 qui c\'era 200 e il PDF, senza una parola');
        self::assertSame('tex_errors', $corpo['error']);
        self::assertCount(2, $corpo['errors']);
        self::assertSame(4, $corpo['errors'][0]['line']);
        self::assertStringStartsWith('pdflatex ha trovato 2 errori:', (string) $corpo['log']);
        self::assertSame('%PDF-1.5 finto', base64_decode((string) $corpo['pdf_b64']), 'e il PDF parziale, da mostrare sotto');
    }

    #[Test]
    public function il_modal_con_un_pdf_pulito_riceve_il_pdf_come_prima(): void
    {
        $this->servizio(['/compile' => [200, self::compilato(true, self::log('pulito'))]]);

        [$codice, , $tipo] = $this->modal();

        self::assertSame(200, $codice);
        self::assertSame('application/pdf', $tipo);
    }

    #[Test]
    public function il_modal_senza_pdf_riceve_gli_errori_e_l_estratto(): void
    {
        $this->servizio(['/compile' => [422, self::compilato(false, self::log('fatale'))]]);

        [$codice, $corpo] = $this->modal();

        self::assertSame(422, $codice);
        self::assertSame('compile_failed', $corpo['error']);
        self::assertSame([136], array_column($corpo['errors'], 'line'));
        self::assertStringStartsWith('pdflatex ha trovato 1 errore:', (string) $corpo['log']);
        self::assertArrayNotHasKey('pdf_b64', $corpo);
    }

    /** @return array{0: int, 1: array<string, mixed>} */
    private function anteprima(): array
    {
        $_SERVER['CONTENT_TYPE'] = 'application/x-www-form-urlencoded';
        $_POST = ['tikz' => '\draw (0,0) -- (1,' . bin2hex(random_bytes(3)) . ');', 'scope' => 'public'];
        $res = (new TikzRenderController())->render(new Request());
        return [$res->status, (array) json_decode((string) $res->body, true)];
    }

    #[Test]
    public function l_anteprima_svg_passa_al_browser_gli_errori_del_servizio(): void
    {
        $errori = [['line' => 4, 'message' => "Package pgfkeys Error: I do not know the key '/tikz/kin inesistente'", 'context' => 'l.4 \draw[kin inesistente]']];
        $this->servizio(['/render-tikz' => [422, (string) json_encode(['ok' => false, 'log' => 'pdflatex ha trovato 1 errore: …', 'errors' => $errori, 'duration_ms' => 3])]]);

        [$codice, $corpo] = $this->anteprima();

        self::assertSame(422, $codice);
        self::assertSame($errori, $corpo['errors']);
    }

    #[Test]
    public function con_un_servizio_di_prima_gli_errori_si_ricavano_dal_log(): void
    {
        // Il servizio TeX si aggiorna al rilascio, dopo il container: per un
        // momento l'applicazione nuova parla con il servizio vecchio, che non
        // manda `errors`. Si ricavano dal log che manda.
        $this->servizio(['/render-tikz' => [422, (string) json_encode(['ok' => false, 'log' => self::log('fatale'), 'duration_ms' => 3])]]);

        [$codice, $corpo] = $this->anteprima();

        self::assertSame(422, $codice);
        self::assertSame([136], array_column($corpo['errors'], 'line'));
    }

    #[Test]
    public function un_fallimento_che_non_e_del_disegno_non_porta_errori(): void
    {
        // Senza righe «!» il client può ritentare (tempo scaduto, servizio
        // saturo): se qui comparissero errori inventati, non ritenterebbe più.
        $this->servizio(['/render-tikz' => [422, (string) json_encode(['ok' => false, 'log' => 'pdflatex TIMEOUT dopo 20s', 'errors' => [], 'duration_ms' => 20000])]]);

        [$codice, $corpo] = $this->anteprima();

        self::assertSame(422, $codice);
        self::assertSame([], $corpo['errors']);
    }
}
