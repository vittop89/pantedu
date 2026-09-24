<?php

declare(strict_types=1);

namespace Tests\Unit\Support;

use App\Support\Csp;
use App\Support\StandalonePageRenderer;
use App\Support\TemaIniziale;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Il tema all'avvio arriva in linea, con il nonce, da un file solo
 * (23/9/2026, revisione architetturale A-50).
 *
 * La politica sta in js/tema-iniziale.js (la prova del suo comportamento è
 * tests/js-unit/tema-all-avvio.test.js). Qui si guarda che il PHP la metta
 * nella pagina così com'è, dentro uno script con il nonce della richiesta —
 * con la CSP rigorosa uno script senza nonce non parte, e la pagina resta
 * del colore sbagliato fino al caricamento del bundle — e che anche il
 * documento a sé delle pagine di fiducia la porti nel suo <head>.
 */
final class TemaInizialeTest extends TestCase
{
    protected function setUp(): void
    {
        Csp::nuovaRichiesta();
    }

    #[Test]
    public function loScriptPortaIlFileDellaPoliticaEIlNonceDellaRichiesta(): void
    {
        $file = (string)file_get_contents(dirname(__DIR__, 3) . '/js/tema-iniziale.js');
        self::assertStringContainsString('window.fmTemaScuro', $file, 'il file è quello della politica');

        $script = TemaIniziale::script();

        self::assertSame('<script nonce="' . Csp::nonce() . '">' . $file . '</script>', $script);
    }

    #[Test]
    public function ilDocumentoASeDellePagineDiFiduciaLaPortaNelSuoHead(): void
    {
        $html = StandalonePageRenderer::render('Prova', '<p>corpo</p>', ['partial' => false]);

        $head = substr($html, 0, (int)strpos($html, '</head>'));
        self::assertStringContainsString(TemaIniziale::script(), $head);
        $corpo = substr($html, (int)strpos($html, '</head>'));
        self::assertStringNotContainsString('matchMedia', $corpo, 'lo script del corpo non ha una politica sua');
    }
}
