<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Middleware\RateLimitMiddleware;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Una GET che chiede un limite lo ottiene; una GET qualunque no.
 *
 * Il 22/9/2026: `RateLimitMiddleware` usciva su tutto ciò che non fosse POST,
 * PUT, PATCH o DELETE. Dodici rotte GET dichiaravano un secchio e nessuna era
 * limitata. Per la verifica in due passaggi e il recupero password non c'era
 * danno — la GET è il modulo, la POST corrispondente è limitata — ma
 * `/accesso-classe/qr/{token}` esiste **solo** come GET, tenta credenziali e ne
 * accetta dodici per richiesta, e il commento della rotta prometteva «stesso
 * limite di tentativi della password».
 *
 * Il token è di 43 caratteri, 256 bit: non era un modo per entrare. Era un
 * controllo dichiarato che non opera, che è la forma di guasto che questo
 * progetto insegue.
 *
 * Provato nei due versi, e il secondo conta quanto il primo: limitare **ogni**
 * lettura romperebbe la navigazione.
 */
final class LimitatoreSulleGetTest extends TestCase
{
    #[Test]
    public function cio_che_scrive_si_conta_sempre(): void
    {
        foreach (['POST', 'PUT', 'PATCH', 'DELETE', 'post'] as $metodo) {
            self::assertTrue(RateLimitMiddleware::daContare($metodo, null),
                "$metodo si conta anche senza secchio");
        }
    }

    #[Test]
    public function una_get_con_secchio_esplicito_si_conta(): void
    {
        self::assertTrue(RateLimitMiddleware::daContare('GET', 'qr'),
            "l'ingresso con QR è una GET che tenta credenziali: va contato");
        self::assertTrue(RateLimitMiddleware::daContare('GET', 'export'),
            "l'esportazione dei propri dati è cara: va contata");
    }

    #[Test]
    public function una_get_qualunque_resta_libera(): void
    {
        // Il verso che di solito non si prova. Senza questo caso, una regola
        // che contasse tutto passerebbe le due prove sopra e romperebbe la
        // navigazione di ogni pagina.
        self::assertFalse(RateLimitMiddleware::daContare('GET', null),
            'una lettura senza secchio esplicito non si conta');
        self::assertFalse(RateLimitMiddleware::daContare('GET', ''),
            'e nemmeno con un secchio vuoto');
        self::assertFalse(RateLimitMiddleware::daContare('HEAD', null));
    }

    #[Test]
    public function la_rotta_del_qr_chiede_davvero_un_secchio(): void
    {
        // Senza questo caso la correzione del middleware non servirebbe: la
        // rotta continuerebbe a dichiarare `rate` senza secchio e resterebbe
        // fuori dal limitatore esattamente come prima.
        $rotte = (string)file_get_contents(dirname(__DIR__, 2) . '/routes/web.php');

        self::assertMatchesRegularExpression(
            "#accesso-classe/qr/\{token\}.*\n\s*->middleware\('rate:[a-z_]+,\d+'\)#",
            $rotte,
            'la rotta del QR deve avere un secchio suo, altrimenti non viene contata'
        );
    }
}
