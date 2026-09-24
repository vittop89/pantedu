<?php

declare(strict_types=1);

namespace Tests\Unit\Middleware;

use App\Core\Request;
use App\Core\Response;
use App\Middleware\SecurityHeadersMiddleware;
use App\Support\Csp;
use App\Support\ViteManifest;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Dove sta il `nonce` della CSP in uno `<script>`.
 *
 * Il 18 settembre 2026 il riconoscitore dei blocchi TikZ pretendeva `type`
 * come primo attributo, e in produzione non lo trovava mai: con la CSP
 * rigorosa SecurityHeadersMiddleware timbrava `nonce="…"` subito dopo
 * `<script` su ogni script del corpo, figure TikZ del contenuto comprese. In
 * locale, senza CSP rigorosa, funzionava benissimo. Questa prova fissava
 * quella forma, perché chi la cambiava lo sapesse.
 *
 * 23/9/2026 (revisione architetturale A-16, R-3 passo 4) — il middleware non
 * timbra più il corpo. Il nonce lo scrivono gli script dell'applicazione, con
 * `Csp::attributo()` subito dopo `<script`: lì resta davanti a ogni altro
 * attributo. Le figure TikZ del contenuto non lo ricevono più: sono dati, il
 * browser non le esegue. Il riconoscitore (js/modules/editor/inline-blocks-markers.js)
 * regge ancora il nonce davanti a `type`, per l'HTML salvato prima di oggi, e
 * `tests/js-unit/tikz-nel-salvataggio.test.js` prova le due forme.
 *
 * NonceSoloAgliScriptDellAppTest guarda il resto (il contenuto senza nonce,
 * lo stesso nonce nella pagina e nell'intestazione).
 */
final class NonceDavantiAlTipoTest extends TestCase
{
    protected function setUp(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['REQUEST_URI'] = '/studio';
        Csp::nuovaRichiesta();
    }

    private static function attraversaIlMiddleware(string $html): string
    {
        return (new SecurityHeadersMiddleware('strict'))->handle(
            new Request(),
            static fn(): Response => Response::html($html)
        )->body;
    }

    #[Test]
    public function negliScriptDellAppIlNonceVaDavantiATutto(): void
    {
        $nonce = preg_quote(Csp::nonce(), '/');
        self::assertMatchesRegularExpression(
            '/<script nonce="' . $nonce . '" type="module" src="[^"]+"[^>]*><\/script>$/',
            ViteManifest::script('js/modules/bootstrap.js')
        );
        self::assertSame(' nonce="' . Csp::nonce() . '"', Csp::attributo());
    }

    /**
     * Una figura TikZ del contenuto attraversa il middleware com'era: senza
     * nonce, e con `type` primo attributo. Sul codice di prima riceveva
     * `nonce="…"` davanti a `type`: rossa.
     */
    #[Test]
    public function unaFiguraTikzDelContenutoNonRiceveIlNonce(): void
    {
        $tikz = '<script type="text/tikz" data-show-console="true">\\begin{tikzpicture}\\end{tikzpicture}</script>';

        $reso = self::attraversaIlMiddleware($tikz);

        self::assertSame($tikz, $reso);
        self::assertStringNotContainsString('nonce', $reso);
    }

    /** L'altro verso: senza `<script>` il corpo non si tocca, come prima. */
    #[Test]
    public function senzaScriptIlCorpoRestaQuelloCheEra(): void
    {
        $html = '<p>Un sasso è lanciato orizzontalmente.</p>';
        self::assertSame($html, self::attraversaIlMiddleware($html));
    }
}
