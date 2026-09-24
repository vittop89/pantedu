<?php

declare(strict_types=1);

namespace Tests\Unit\Rendering;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * La configurazione di MathJax è scritta una volta sola (23/9/2026, revisione
 * architetturale A-19, R-3 passo 3).
 *
 * Stava in due partial, `_mathjax_loader.php` e `_exercise_assets.php`, con un
 * «Allineata a …» come unica garanzia che dicessero la stessa cosa: una
 * modifica a una sola delle due (un pacchetto TeX, il font, l'opzione di
 * accessibilità) avrebbe fatto rendere le formule in due modi secondo la
 * pagina. Adesso la configurazione sta in `_mathjax_loader.php`, e
 * `_exercise_assets.php` la include e aggiunge solo l'aggancio
 * `startup.ready` e il caricamento condizionato.
 *
 * Due prove: nei sorgenti di views/ e app/ l'assegnazione `MathJax = {` è in
 * un file solo (sul codice di prima erano due: rossa); e la pagina degli
 * esercizi, resa davvero, ha la configurazione una volta, l'aggancio dopo, e
 * il loader che prende l'indirizzo dalla configurazione invece di ricalcolarlo.
 */
final class MathJaxConfiguratoUnaVoltaTest extends TestCase
{
    private const CONFIGURAZIONE = '/\bMathJax\s*=\s*\{/';

    private static function radice(): string
    {
        return dirname(__DIR__, 3);
    }

    /** @return list<string> i file di views/ e app/ che assegnano la configurazione */
    private static function fileConLaConfigurazione(): array
    {
        $trovati = [];
        foreach (['views', 'app'] as $cartella) {
            $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(self::radice() . '/' . $cartella));
            foreach ($it as $file) {
                if (!$file->isFile() || !preg_match('/\.(php|html?)$/', $file->getFilename())) {
                    continue;
                }
                if (preg_match(self::CONFIGURAZIONE, (string)file_get_contents($file->getPathname())) === 1) {
                    $trovati[] = substr($file->getPathname(), strlen(self::radice()) + 1);
                }
            }
        }
        sort($trovati);
        return $trovati;
    }

    private static function rendi(string $partial): string
    {
        ob_start();
        try {
            include self::radice() . '/views/partials/' . $partial;
        } finally {
            $html = (string)ob_get_clean();
        }
        return $html;
    }

    #[Test]
    public function laConfigurazioneStaInUnFileSolo(): void
    {
        self::assertSame(['views/partials/_mathjax_loader.php'], self::fileConLaConfigurazione());
    }

    #[Test]
    public function laPaginaDegliEserciziLaIncludeUnaVoltaEPoiAggiungeLAggancio(): void
    {
        $html = self::rendi('_exercise_assets.php');

        self::assertSame(1, preg_match_all(self::CONFIGURAZIONE, $html), 'una configurazione sola');
        $configurazione = (int)strpos($html, 'window.FM_MATHJAX_SRC =');
        $aggancio = strpos($html, 'MathJax.startup = {');
        $loader = strpos($html, 's.src = window.FM_MATHJAX_SRC;');
        self::assertNotFalse($aggancio, "l'aggancio startup.ready c'è ancora");
        self::assertNotFalse($loader, "il loader prende l'indirizzo dalla configurazione");
        self::assertGreaterThan(0, $configurazione);
        self::assertGreaterThan($configurazione, $aggancio, "l'aggancio viene dopo la configurazione");
        self::assertGreaterThan($aggancio, $loader, 'il loader parte dopo');
        // La configurazione dice le cose di prima (font, accessibilità, pacchetti).
        self::assertStringContainsString("font: 'mathjax-stix2'", $html);
        self::assertStringContainsString('assistiveMml: true', $html);
        self::assertStringContainsString("'[tex]/physics'", $html);
        self::assertMatchesRegularExpression('#window\.FM_MATHJAX_SRC = "/vendor/mathjax/tex-mml-chtml\.js(\?v=[^"]+)?";#', $html);
    }

    #[Test]
    public function laConfigurazioneDaSolaNonHaLAggancioDegliEsercizi(): void
    {
        $html = self::rendi('_mathjax_loader.php');

        self::assertSame(1, preg_match_all(self::CONFIGURAZIONE, $html));
        self::assertStringNotContainsString('startup', $html);
    }
}
