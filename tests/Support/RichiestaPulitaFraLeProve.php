<?php

declare(strict_types=1);

namespace Tests\Support;

use PHPUnit\Event\Test\Finished;
use PHPUnit\Event\Test\FinishedSubscriber;
use PHPUnit\Runner\Extension\Extension;
use PHPUnit\Runner\Extension\Facade;
use PHPUnit\Runner\Extension\ParameterCollection;
use PHPUnit\TextUI\Configuration\Configuration;

/**
 * La richiesta di una prova non passa alla successiva (14/9/2026).
 *
 * Una dozzina di prove scrive $_SESSION (un docente, un amministratore, un
 * super-amministratore) e non la ripulisce; altre lasciano in $_SERVER e $_GET
 * una richiesta parziale o incorporata. Con executionOrder="random" la prova
 * successiva le eredita, e fallisce solo con certi semi:
 *   - RisdocResolverTest, seme 1789389912: un super-amministratore rimasto in
 *     sessione vede anche i modelli «denied». Ordine rosso misurato:
 *     RisdocSeedTest (carica app/bootstrap.php; tolta il 14/9/2026), poi
 *     SuperAdminRequiredMiddlewareTest::super_admin_passes, poi
 *     RisdocResolverTest;
 *   - DriveStatiTest, seme 1789398653: LayoutModesTest lascia
 *     HTTP_X_PARTIAL=1 ed embed=1, e il cruscotto esce senza la cornice.
 *
 * Una regola sola, qui, invece di un tearDown in ogni prova: finita una
 * prova, la sessione torna vuota e le superglobali della richiesta tornano
 * com'erano all'avvio della suite. Nessuna prova le prepara in
 * setUpBeforeClass (controllato quel giorno), quindi nessuna ne ha bisogno fra
 * un metodo e l'altro. $_ENV resta fuori: la configurazione lo legge solo
 * quando si carica, e chi lo cambia lo rimette da sé.
 */
final class RichiestaPulitaFraLeProve implements Extension
{
    public function bootstrap(Configuration $configuration, Facade $facade, ParameterCollection $parameters): void
    {
        $facade->registerSubscriber(new class ([
            'server'  => $_SERVER,
            'get'     => $_GET,
            'post'    => $_POST,
            'cookie'  => $_COOKIE,
            'request' => $_REQUEST,
            'files'   => $_FILES,
        ]) implements FinishedSubscriber {
            /** @param array{server: array<mixed>, get: array<mixed>, post: array<mixed>, cookie: array<mixed>, request: array<mixed>, files: array<mixed>} $avvio */
            public function __construct(private readonly array $avvio)
            {
            }

            public function notify(Finished $event): void
            {
                $_SESSION = [];
                $_SERVER  = $this->avvio['server'];
                $_GET     = $this->avvio['get'];
                $_POST    = $this->avvio['post'];
                $_COOKIE  = $this->avvio['cookie'];
                $_REQUEST = $this->avvio['request'];
                $_FILES   = $this->avvio['files'];
            }
        });
    }
}
