<?php

declare(strict_types=1);

namespace Tests\Unit\Core;

use App\Core\Response;
use App\Core\Router;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Tests\Support\RottaVera;

/**
 * Una rotta jolly non cattura una rotta vera registrata dopo di lei
 * (23/9/2026, revisione architetturale A-54).
 *
 * Router::match prende la prima rotta registrata che combacia con metodo e
 * percorso. Una jolly come `/verifiche/{path*}` combacia con ogni percorso
 * sotto `/verifiche/`: se sta prima di una rotta vera con lo stesso prefisso,
 * quella non si raggiunge più. È già successo (G19.4, commento in
 * routes/web.php): il gruppo dell'amministratore registrava prima la jolly
 * `legacy_gone` e `/verifiche/print-info` e `/verifiche/scelte` rispondevano
 * 410, o 403 a un docente, perché passavano dalle porte della jolly. Oggi
 * l'ordine è giusto per scelta, e nulla lo sorveglia.
 *
 * La regola, sulla tabella vera: per ogni rotta con un segnaposto `{…*}` e
 * ogni rotta registrata dopo con un metodo in comune, un percorso d'esempio
 * della seconda non deve combaciare con la prima. Le rotte `legacy_gone`
 * restano fuori come catturate: sono segnaposti per i percorsi dismessi, e se
 * una rotta vera le copre (GET `/risdoc/{path*}` del docente copre la jolly
 * dell'amministratore) vince la rotta vera, che è ciò che si vuole.
 */
final class OrdineDelleRotteJollyTest extends TestCase
{
    /** Un percorso che combacia con il pattern: ogni segnaposto diventa un segmento. */
    private static function esempio(string $pattern): string
    {
        return (string)preg_replace(
            ['#\{[a-zA-Z_][a-zA-Z0-9_]*\*\}#', '#\{[a-zA-Z_][a-zA-Z0-9_]*\??\}#'],
            ['x/y', 'x'],
            $pattern
        );
    }

    /**
     * Le rotte catturate, come `METODI percorso ← jolly percorso`, e quante
     * jolly si sono guardate.
     *
     * @return array{0: list<string>, 1: int}
     */
    public static function catturate(Router $router): array
    {
        $rotte = $router->routes();
        $fuori = [];
        $jolly = 0;
        foreach ($rotte as $i => $j) {
            if (preg_match('#\{[a-zA-Z_][a-zA-Z0-9_]*\*\}#', $j->pattern) !== 1) {
                continue;
            }
            $jolly++;
            foreach (\array_slice($rotte, $i + 1) as $vera) {
                if (\in_array('legacy_gone', $vera->middleware, true)) {
                    continue;
                }
                $metodi = array_values(array_intersect($j->methods, $vera->methods));
                if ($metodi === [] || !$j->matchesPath(self::esempio($vera->pattern))) {
                    continue;
                }
                $fuori[] = implode(',', $metodi) . ' ' . $vera->pattern . ' ← ' . $j->pattern;
            }
        }
        return [$fuori, $jolly];
    }

    #[Test]
    public function nessuna_jolly_cattura_una_rotta_vera_registrata_dopo(): void
    {
        [$fuori, $jolly] = self::catturate(RottaVera::rotte());

        // Una regola che non guarda niente dice sempre di sì: le jolly erano
        // nove il 23/9/2026 (otto `legacy_gone` e GET /risdoc/{path*}).
        $this->assertGreaterThanOrEqual(9, $jolly, 'le jolly guardate sono quelle di routes/web.php, non zero');
        $this->assertSame([], $fuori, sprintf(
            "%d rotte vere non si raggiungono: una jolly registrata prima le cattura.\n\n"
            . "Router::match prende la prima rotta che combacia. Registra la rotta vera "
            . "prima della jolly (o la jolly in fondo al file).\n\nCatturate:\n  %s",
            \count($fuori),
            implode("\n  ", $fuori)
        ));
    }

    #[Test]
    public function la_prova_scatta_con_una_jolly_spostata_prima_delle_rotte_vere(): void
    {
        // La controprova sulla tabella vera: la jolly dell'amministratore
        // registrata in testa, come prima della G19.4.
        $router = new Router();
        $router->any('/verifiche/{path*}', static fn () => Response::json(['error' => 'gone'], 410))
            ->middleware('legacy_gone');
        require \dirname(__DIR__, 3) . '/routes/web.php';

        [$fuori] = self::catturate($router);

        $this->assertContains('POST /verifiche/print-info ← /verifiche/{path*}', $fuori);
        $this->assertContains('POST /verifiche/scelte ← /verifiche/{path*}', $fuori);
    }

    #[Test]
    public function conta_l_ordine_e_il_metodo(): void
    {
        $gone = static fn () => Response::json(['error' => 'gone'], 410);
        $vera = static fn () => Response::json(['ok' => true]);

        $dopo = new Router();
        $dopo->post('/risdoc/view/{id}', $vera);
        $dopo->any('/risdoc/{path*}', $gone)->middleware('legacy_gone');
        $this->assertSame([], self::catturate($dopo)[0], 'la rotta vera prima della jolly si raggiunge');

        $prima = new Router();
        $prima->any('/risdoc/{path*}', $gone)->middleware('legacy_gone');
        $prima->post('/risdoc/view/{id}', $vera);
        $this->assertSame(['POST /risdoc/view/{id} ← /risdoc/{path*}'], self::catturate($prima)[0]);

        $altroMetodo = new Router();
        $altroMetodo->post('/risdoc/{path*}', $gone)->middleware('legacy_gone');
        $altroMetodo->get('/risdoc/view/{id}', $vera);
        $this->assertSame([], self::catturate($altroMetodo)[0], 'un metodo diverso non si cattura');

        $altroPrefisso = new Router();
        $altroPrefisso->any('/risdoc/{path*}', $gone)->middleware('legacy_gone');
        $altroPrefisso->get('/risdocumenti/{id}', $vera);
        $this->assertSame([], self::catturate($altroPrefisso)[0], 'un prefisso che comincia uguale non si cattura');
    }
}
