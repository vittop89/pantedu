<?php

declare(strict_types=1);

namespace Tests\Unit\Core;

use App\Core\Router;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Le rotte /me/* che lavorano sulla sessione passano da AuthMiddleware
 * (23/9/2026).
 *
 * Revisione architetturale 2026-09, rilievo A-79: nessuna rotta /me/* aveva il
 * middleware `auth`. I controller chiedevano la sessione per conto loro, ma
 * il confinamento di AuthMiddleware non li vedeva: con `must_change_password`
 * o `must_enrol_2fa` in sessione si raggiungevano esportazione dei dati,
 * consensi, cancellazione e profilo, contro quanto dice
 * wiki/security-notes.md. E una sessione di un account disattivato non si
 * chiudeva passando da lì (Auth::chiudiSeRevocata).
 *
 * Come CoperturaRuoliTest, la regola si legge dalla tabella delle rotte, dove
 * il middleware si decide davvero. Le esenzioni sono scritte qui con il
 * perché, e un'esenzione che non corrisponde più a nessuna rotta è un errore:
 * una lista di eccezioni che invecchia in silenzio non esenta niente, copre.
 *
 * Il comportamento sulla rotta vera (con la password da cambiare,
 * /me/export-data si ferma alla porta e /me/change-password no) lo prova
 * tests/Integration/SessioneRilettaDalDatabaseTest.php.
 */
final class ConfinamentoSulleRotteMeTest extends TestCase
{
    /**
     * Le rotte /me/* che restano senza `auth`, con il motivo.
     *
     * @var array<string, string> `METODO percorso` => perché
     */
    private const ESENTI = [
        'GET /me/account/email/conferma'  => 'conferma del nuovo indirizzo: la apre il gettone del collegamento, anche da un altro dispositivo',
        'POST /me/account/email/conferma' => 'conferma del nuovo indirizzo: la apre il gettone del collegamento, anche da un altro dispositivo',
        'GET /me/confirm-deletion'        => 'conferma della cancellazione: la apre il gettone del collegamento spedito per email',
        'POST /me/confirm-deletion'       => 'conferma della cancellazione: il pulsante della pagina del collegamento, anche da un altro dispositivo',
        'GET /me/change-password'         => 'è dove il confinamento di must_change_password manda',
        'POST /me/change-password'        => 'è dove il confinamento di must_change_password manda',
        'GET /me/2fa'                     => 'iscrizione al secondo fattore: dove manda il confinamento di must_enrol_2fa',
        'POST /me/2fa/setup'              => 'iscrizione al secondo fattore',
        'POST /me/2fa/enable'             => 'iscrizione al secondo fattore',
        'POST /me/2fa/setup-email'        => 'iscrizione al secondo fattore',
        'POST /me/2fa/enable-email'       => 'iscrizione al secondo fattore',
    ];

    /**
     * Rotte /me/* senza `auth` e non esenti, ed esenzioni senza rotta.
     *
     * @param array<string, string> $esenti
     * @return array{0: list<string>, 1: list<string>, 2: int} scoperte, esenzioni orfane, rotte controllate
     */
    public static function controlla(Router $router, array $esenti): array
    {
        $scoperte = [];
        $viste = [];
        $controllate = 0;
        foreach ($router->routes() as $rotta) {
            if (!str_starts_with($rotta->pattern, '/me/') && $rotta->pattern !== '/me') {
                continue;
            }
            foreach ($rotta->methods as $metodo) {
                if ($metodo === 'HEAD') {
                    continue; // il Router lo aggiunge a ogni GET, con gli stessi middleware
                }
                $chiave = $metodo . ' ' . $rotta->pattern;
                $controllate++;
                if (isset($esenti[$chiave])) {
                    $viste[$chiave] = true;
                    continue;
                }
                if (!\in_array('auth', $rotta->middleware, true)) {
                    $scoperte[] = $chiave . '   [' . implode(' ', $rotta->middleware) . ']';
                }
            }
        }
        sort($scoperte);
        $orfane = array_values(array_diff(array_keys($esenti), array_keys($viste)));
        sort($orfane);
        return [$scoperte, $orfane, $controllate];
    }

    #[Test]
    public function le_rotte_me_che_lavorano_sulla_sessione_passano_da_auth(): void
    {
        $router = new Router();
        require \dirname(__DIR__, 3) . '/routes/web.php';

        [$scoperte, $orfane, $controllate] = self::controlla($router, self::ESENTI);

        // Una regola che non guarda niente dice sempre di sì: il 23/9/2026 le
        // rotte /me/* erano 22, dieci esenti.
        $this->assertGreaterThan(20, $controllate, 'le rotte /me/* ci sono, e si guardano');
        $this->assertSame([], $scoperte, sprintf(
            "%d rotte /me/* senza il middleware 'auth': il confinamento di must_change_password\n"
            . "e must_enrol_2fa non le vede. Aggiungi ->middleware('auth', ...) in routes/web.php,\n"
            . "oppure, se la rotta la apre un gettone e non la sessione, l'esenzione qui con il perché.\n\n  %s",
            \count($scoperte),
            implode("\n  ", $scoperte)
        ));
        $this->assertSame([], $orfane, 'esenzioni che non corrispondono più a nessuna rotta: toglierle');
    }

    #[Test]
    public function la_regola_scatta_quando_deve_e_tace_quando_non_deve(): void
    {
        $router = new Router();
        $noop = static fn() => null;
        // Scoperte: senza niente, e con altri middleware ma senza auth.
        $router->get('/me/finta-senza-niente', $noop);
        $router->post('/me/finta-solo-csrf', $noop)->middleware('csrf', 'rate:export,3');
        // Coperte: auth sulla rotta, auth dal gruppo.
        $router->post('/me/finta-con-auth', $noop)->middleware('auth', 'csrf');
        $router->group(['middleware' => ['auth']], function (Router $r) use ($noop): void {
            $r->get('/me/finta-dal-gruppo', $noop);
        });
        // Esente, e fuori dal prefisso.
        $router->get('/me/finta-col-gettone', $noop);
        $router->get('/mela/fuori-prefisso', $noop);

        [$scoperte, $orfane, $controllate] = self::controlla($router, [
            'GET /me/finta-col-gettone' => 'la apre un gettone',
            'GET /me/finta-che-non-esiste' => 'esenzione rimasta indietro',
        ]);

        $this->assertSame(5, $controllate, 'le cinque sotto /me/, non quella di /mela');
        $this->assertSame([
            'GET /me/finta-senza-niente   []',
            'POST /me/finta-solo-csrf   [csrf rate:export,3]',
        ], $scoperte);
        $this->assertSame(['GET /me/finta-che-non-esiste'], $orfane, 'l\'esenzione senza rotta si segnala');
    }
}
