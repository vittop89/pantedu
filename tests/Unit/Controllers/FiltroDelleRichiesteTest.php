<?php

declare(strict_types=1);

namespace Tests\Unit\Controllers;

use App\Controllers\Admin\WafAdminController;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Il filtro del registro del WAF.
 *
 * Richiesta dell'utente (21/9/2026): «come admin devo poter isolare una fascia
 * oraria per vedere i log di quella fascia e capire cos'è successo». Prima la
 * pagina mostrava le ultime cinquanta richieste e basta — durante una lezione
 * cinquanta richieste sono pochi secondi, e alla domanda «perché quel ragazzo
 * non è entrato alle 10:18» non si poteva rispondere.
 *
 * Qui si misura la parte che decide: che cosa entra nella query e che cosa no.
 * Un esito che non conosciamo e una data che non si capisce non diventano un
 * filtro storto: diventano nessun filtro, perché un filtro che finge è peggio
 * di nessun filtro.
 */
final class FiltroDelleRichiesteTest extends TestCase
{
    #[Test]
    public function senza_parametri_nessuna_finestra_e_cinquanta_righe(): void
    {
        $f = WafAdminController::filtroDelleRichieste([]);

        self::assertNull($f['outcome']);
        self::assertNull($f['dalle']);
        self::assertNull($f['alle']);
        self::assertSame(50, $f['limit']);
    }

    #[Test]
    public function la_fascia_oraria_del_campo_del_browser_diventa_quella_del_database(): void
    {
        // Il campo `datetime-local` manda «2026-09-21T10:00».
        $f = WafAdminController::filtroDelleRichieste([
            'dalle' => '2026-09-21T10:00',
            'alle'  => '2026-09-21T11:00',
        ]);

        self::assertSame('2026-09-21 10:00:00', $f['dalle']);
        self::assertSame('2026-09-21 11:00:00', $f['alle']);
    }

    #[Test]
    public function si_accettano_anche_lo_spazio_i_secondi_e_la_data_sola(): void
    {
        self::assertSame('2026-09-21 10:18:00',
            WafAdminController::filtroDelleRichieste(['dalle' => '2026-09-21 10:18'])['dalle']);
        self::assertSame('2026-09-21 10:18:40',
            WafAdminController::filtroDelleRichieste(['dalle' => '2026-09-21 10:18:40'])['dalle']);
        self::assertSame('2026-09-21 00:00:00',
            WafAdminController::filtroDelleRichieste(['dalle' => '2026-09-21'])['dalle']);
    }

    #[Test]
    public function una_data_che_non_si_capisce_non_diventa_un_filtro(): void
    {
        foreach (['ieri', '21/09/2026', '2026-13-45 99:99', "2026-09-21' OR 1=1 --", '', '   ', null, 42] as $scritto) {
            $f = WafAdminController::filtroDelleRichieste(['dalle' => $scritto]);
            self::assertNull($f['dalle'], 'rifiutata: ' . var_export($scritto, true));
        }
    }

    #[Test]
    public function un_esito_sconosciuto_si_ignora_e_uno_vero_si_tiene(): void
    {
        self::assertSame('blocked_geo',
            WafAdminController::filtroDelleRichieste(['outcome' => 'blocked_geo'])['outcome']);
        self::assertNull(WafAdminController::filtroDelleRichieste(['outcome' => 'boh'])['outcome']);
        self::assertNull(WafAdminController::filtroDelleRichieste(['outcome' => "pass' OR 1=1"])['outcome']);
    }

    #[Test]
    public function la_quantita_sta_fra_dieci_e_mille(): void
    {
        self::assertSame(10, WafAdminController::filtroDelleRichieste(['limit' => 1])['limit']);
        self::assertSame(10, WafAdminController::filtroDelleRichieste(['limit' => -7])['limit']);
        self::assertSame(1000, WafAdminController::filtroDelleRichieste(['limit' => 99999])['limit']);
        self::assertSame(300, WafAdminController::filtroDelleRichieste(['limit' => '300'])['limit']);
        // L'API aveva cento di suo, e resta così.
        self::assertSame(100, WafAdminController::filtroDelleRichieste([], 100)['limit']);
    }

    #[Test]
    public function gli_esiti_offerti_sono_quelli_che_il_waf_scrive_davvero(): void
    {
        // Se il middleware ne aggiunge uno e nessuno lo mette nell'elenco, il
        // filtro non lo offre: questa prova lo dice prima che lo dica una
        // persona che non trova la riga che cerca.
        $sorgente = (string)file_get_contents(\dirname(__DIR__, 3) . '/app/Middleware/WafMiddleware.php');
        preg_match_all("/'outcome' => '([a-z_]+)'/", $sorgente, $m);
        $scritti = array_values(array_unique($m[1]));
        sort($scritti);
        $offerti = WafAdminController::ESITI;
        sort($offerti);

        self::assertSame([], array_values(array_diff($scritti, $offerti)),
            'esiti che il WAF scrive e che il filtro non offre');
    }
}
