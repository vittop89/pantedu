<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Core\Kernel;
use App\Core\Request;
use App\Core\Response;
use App\Core\Router;
use ReflectionMethod;
use RuntimeException;

/**
 * Una richiesta attraverso la tabella delle rotte vera (routes/web.php),
 * Router::match e Kernel::invoke (23/9/2026).
 *
 * Serve alle prove che devono vedere che cosa l'azione riceve dal percorso: i
 * parametri come `{id}` li estrae Router::match e li passa Kernel::invoke come
 * secondo argomento. Chiamare l'azione a mano, con un array scritto nella
 * prova, salterebbe proprio il passaggio che era rotto nel pannello WAF e nel
 * catalogo GeoGebra (revisione architetturale del 23/9/2026, A-5).
 *
 * I middleware del gruppo (porte, CSRF, motivazione) restano fuori: hanno le
 * loro prove, e qui la sessione è già quella di chi le passa.
 */
final class RottaVera
{
    private static ?Router $rotte = null;

    /**
     * @param array<string, string> $campi i campi del modulo ($_POST)
     */
    public static function chiama(string $metodo, string $percorso, array $campi = []): Response
    {
        $_SERVER['REQUEST_METHOD'] = $metodo;
        $_SERVER['REQUEST_URI'] = $percorso;
        $_SERVER['HTTP_ACCEPT'] = 'application/json';
        $_POST = $campi;
        $req = new Request('');
        $rotta = self::rotte()->match($req);
        if ($rotta === null) {
            throw new RuntimeException("{$metodo} {$percorso}: nessuna rotta in routes/web.php");
        }
        $esito = (new ReflectionMethod(Kernel::class, 'invoke'))->invoke(new Kernel(self::rotte()), $rotta, $req);
        if (!$esito instanceof Response) {
            throw new RuntimeException("{$metodo} {$percorso}: Kernel::invoke non ha restituito una Response");
        }
        return $esito;
    }

    /** La tabella delle rotte del sito, letta una volta per processo. */
    public static function rotte(): Router
    {
        if (self::$rotte === null) {
            $router = new Router();
            require \dirname(__DIR__, 2) . '/routes/web.php';
            self::$rotte = $router;
        }
        return self::$rotte;
    }
}
