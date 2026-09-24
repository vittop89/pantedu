<?php

declare(strict_types=1);

namespace Tests\Unit\Core;

use App\Core\Request;
use App\Core\Router;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use Tests\Support\RottaVera;

/**
 * Un'azione con un segnaposto nel percorso riceve i parametri della rotta
 * (23/9/2026).
 *
 * Kernel::invoke chiama ogni azione con due argomenti: la Request e i
 * parametri del percorso (`{id}`, `{key}`, …). Cinque azioni del pannello WAF
 * e l'apertura di una voce del catalogo GeoGebra dichiaravano solo il primo e
 * cercavano l'id in `$req->params`, che Request non ha: il `??` taceva
 * l'avviso, l'id valeva 0 (o ''), le eliminazioni rispondevano «ok» senza
 * cancellare niente e «Apri» del catalogo riceveva sempre 400 (revisione
 * architetturale del 23/9/2026, A-5). PHPStan lo vedeva, ma l'errore stava
 * nella baseline.
 *
 * La regola, letta sulla tabella delle rotte come CoperturaCsrfTest: ogni
 * rotta con un segnaposto il cui gestore è [classe, metodo] ha un metodo che
 * accetta almeno due argomenti. Le chiusure (le rotte `legacy_gone`) restano
 * fuori: rispondono 410 e il percorso non lo leggono.
 *
 * Che l'id arrivi davvero, passando dal Router e da Kernel::invoke, lo prova
 * tests/Unit/Controllers/IdDellaRottaArrivaAllAzioneTest.php.
 */
final class ParametriDiRottaTest extends TestCase
{
    /**
     * Le rotte con un segnaposto la cui azione non riceve i parametri, come
     * `METODO percorso -> Classe::metodo`, e quante rotte si sono guardate.
     *
     * @return array{0: list<string>, 1: int}
     */
    public static function senzaParametri(Router $router): array
    {
        $fuori = [];
        $controllate = 0;
        foreach ($router->routes() as $rotta) {
            $h = $rotta->handler;
            if (
                !str_contains($rotta->pattern, '{')
                || !\is_array($h) || !isset($h[0], $h[1])
                || !\is_string($h[0]) || !\is_string($h[1])
            ) {
                continue;
            }
            $controllate++;
            if ((new ReflectionMethod($h[0], $h[1]))->getNumberOfParameters() >= 2) {
                continue;
            }
            $fuori[] = $rotta->methods[0] . ' ' . $rotta->pattern . ' -> ' . $h[0] . '::' . $h[1];
        }
        sort($fuori);
        return [$fuori, $controllate];
    }

    #[Test]
    public function ogni_azione_con_un_segnaposto_nel_percorso_riceve_i_parametri(): void
    {
        [$fuori, $controllate] = self::senzaParametri(RottaVera::rotte());

        // Una regola che non guarda niente dice sempre di sì: le rotte con un
        // segnaposto e un'azione di controller erano 166 il 23/9/2026.
        $this->assertGreaterThan(100, $controllate, 'le rotte guardate sono quelle con un segnaposto, non zero');
        $this->assertSame([], $fuori, sprintf(
            "%d azioni hanno un segnaposto nel percorso ma non ricevono i parametri.\n\n"
            . "Kernel::invoke li passa come secondo argomento: dichiara l'azione "
            . "come (Request \$req, array \$params = []) e leggi \$params['…']. "
            . "Request non ha una proprietà params.\n\nScoperte:\n  %s",
            \count($fuori),
            implode("\n  ", $fuori)
        ));
    }

    #[Test]
    public function la_regola_scatta_quando_deve_e_tace_quando_non_deve(): void
    {
        $unArgomento = new class () {
            public function azione(Request $req): string
            {
                return 'uno';
            }
        };
        $dueArgomenti = new class () {
            /** @param array<string, string> $params */
            public function azione(Request $req, array $params = []): string
            {
                return 'due';
            }
        };
        $router = new Router();
        // Scoperta: segnaposto nel percorso, azione con un argomento solo.
        $router->delete('/finta/{id}', [$unArgomento::class, 'azione']);
        // Coperta: segnaposto e due argomenti.
        $router->get('/finta/{id}/voce', [$dueArgomenti::class, 'azione']);
        // Fuori dalla regola: niente segnaposto; una chiusura.
        $router->post('/finta', [$unArgomento::class, 'azione']);
        $router->get('/finta-chiusa/{path*}', static fn(): string => 'chiusa');

        [$fuori, $controllate] = self::senzaParametri($router);

        $this->assertSame(2, $controllate, 'le due azioni di controller con un segnaposto');
        $this->assertSame(['DELETE /finta/{id} -> ' . $unArgomento::class . '::azione'], $fuori);
    }
}
