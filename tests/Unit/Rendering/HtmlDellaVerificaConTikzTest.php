<?php

declare(strict_types=1);

namespace Tests\Unit\Rendering;

use App\Services\ContractRenderer;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * L'HTML su cui lavora la prova JavaScript del salvataggio TikZ è quello che
 * ContractRenderer produce davvero.
 *
 * `tests/js-unit/tikz-nel-salvataggio.test.js` apre l'editor su due voci di una
 * verifica, le richiude e guarda che cosa tornerebbe al server. Vitest non
 * esegue PHP, quindi l'HTML reso sta in un file accanto alla prova; un HTML
 * scritto a mano però proverebbe l'editor su una pagina che non esiste. Questa
 * prova rende il gruppo di `verifica-con-tikz.json` con il renderer vero e
 * pretende che coincida con il file: se il renderer cambia, qui diventa rosso
 * finché il file non si rigenera.
 *
 * Per rigenerarlo:
 *
 *     RIGENERA_FIXTURE=1 php vendor/phpunit/phpunit/phpunit --filter HtmlDellaVerificaConTikzTest
 *
 * `renderGroupPublic` e non `renderContract`: il secondo numera gli id con un
 * contatore statico, e il file cambierebbe secondo l'ordine delle prove.
 */
final class HtmlDellaVerificaConTikzTest extends TestCase
{
    private const CARTELLA = __DIR__ . '/../../js-unit/fixtures';

    #[Test]
    public function lHtmlDellaProvaJavascriptEQuelloDelRenderer(): void
    {
        $json = file_get_contents(self::CARTELLA . '/verifica-con-tikz.json');
        self::assertIsString($json);
        $gruppo = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($gruppo);

        $reso = (new ContractRenderer([], true))->renderGroupPublic($gruppo) . "\n";
        $file = self::CARTELLA . '/verifica-con-tikz.html';
        if (getenv('RIGENERA_FIXTURE') === '1') {
            file_put_contents($file, $reso);
        }

        self::assertFileExists($file);
        self::assertSame(
            $reso,
            file_get_contents($file),
            'ContractRenderer non produce più tests/js-unit/fixtures/verifica-con-tikz.html: '
            . 'rigeneralo (vedi il commento in testa a questa prova) e guarda che la prova '
            . 'JavaScript regga ancora.'
        );

        // Il file porta davvero quello che la prova JavaScript usa: due script
        // TikZ, una lista con il suo stile e il suo inizio, una formula.
        self::assertSame(2, substr_count($reso, '<script type="text/tikz"'));
        self::assertStringContainsString('type="a"', $reso);
        self::assertStringContainsString('start="2"', $reso);
        self::assertStringContainsString('class="fm-latex" data-raw="\(v_y\)"', $reso);
    }
}
