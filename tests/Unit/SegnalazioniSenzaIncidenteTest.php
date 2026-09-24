<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Ops\SegnalazioniDiViolazione;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Una segnalazione di violazione che nessuno valuta deve fare rumore.
 *
 * ── Il buco che questo chiude ─────────────────────────────────────────────
 *
 * Il piano per le violazioni dichiarava questo come il proprio punto più
 * debole, e lo dichiarava perché era vero: una segnalazione arrivata dai
 * moduli pubblici non apre da sola un incidente nel registro, quindi è un
 * passaggio che si può dimenticare. Le due strade pubbliche — `/dpo-contact`
 * con oggetto `breach_report`, `/segnalazione-contenuti` con categoria
 * `gdpr_art9` — sono le sole che possano rilevare il rischio R20, che nessun
 * controllo automatico vede. Perderne una vuol dire non accorgersene più,
 * mentre le settantadue ore dell'art. 33 scorrono lo stesso.
 *
 * ── Perché non si apre l'incidente da soli ────────────────────────────────
 *
 * Quei moduli sono pubblici e senza autenticazione, e dalla migrazione 136
 * una riga del registro **non si cancella mai**. Farli scrivere direttamente
 * vorrebbe dire che chiunque, a tre invii l'ora, può riempire per sempre un
 * registro di accountability: la conservazione permanente, che è una
 * garanzia, diventerebbe l'arma. L'incidente lo apre una persona; il sistema
 * si accorge se non lo fa.
 */
final class SegnalazioniSenzaIncidenteTest extends TestCase
{
    /** @param list<array{fonte:string,id:int,ore:int}> $v */
    private static function ids(array $v): array
    {
        return array_map(static fn(array $s): int => $s['id'], $v);
    }

    #[Test]
    public function sotto_la_soglia_non_suona(): void
    {
        $aperte = [
            ['fonte' => 'dpo', 'id' => 1, 'ore' => 0],
            ['fonte' => 'dpo', 'id' => 2, 'ore' => 5],
            ['fonte' => 'takedown', 'id' => 3, 'ore' => 1],
        ];

        self::assertSame(
            [],
            SegnalazioniDiViolazione::inRitardo($aperte),
            'una segnalazione arrivata da poco non è un guasto: è lavoro da fare'
        );
    }

    #[Test]
    public function alla_soglia_e_oltre_suona(): void
    {
        $aperte = [
            ['fonte' => 'dpo', 'id' => 1, 'ore' => 5],
            ['fonte' => 'dpo', 'id' => 2, 'ore' => 6],
            ['fonte' => 'takedown', 'id' => 3, 'ore' => 40],
        ];

        $tardi = SegnalazioniDiViolazione::inRitardo($aperte);

        self::assertSame([3, 2], self::ids($tardi), 'in ritardo, dalla più vecchia');
    }

    /**
     * La soglia è sei ore perché la diagnostica gira due volte al giorno:
     * l'avviso parte entro dodici ore, e ne restano sessanta delle
     * settantadue. Se qualcuno la cambia, questa prova lo dice.
     */
    #[Test]
    public function la_soglia_lascia_margine_sulle_72_ore(): void
    {
        self::assertSame(6, SegnalazioniDiViolazione::ORE);
        self::assertLessThan(
            72 - 12,
            SegnalazioniDiViolazione::ORE + 12,
            'soglia più dodici ore di attesa del timer: deve restare margine sull\'art. 33'
        );
    }

    /**
     * Una data che il database non ha saputo calcolare non deve **nascondere**
     * una segnalazione. Sbaglia dalla parte del rumore, che è l'unica
     * accettabile qui: il caso opposto è una segnalazione invisibile.
     */
    #[Test]
    public function una_data_illeggibile_conta_come_in_ritardo(): void
    {
        $tardi = SegnalazioniDiViolazione::inRitardo([
            ['fonte' => 'dpo', 'id' => 9, 'ore' => -1],
        ]);

        self::assertSame([9], self::ids($tardi));
    }

    #[Test]
    public function i_due_moduli_pubblici_sono_quelli_giusti(): void
    {
        self::assertSame('breach_report', SegnalazioniDiViolazione::OGGETTO_DPO);
        self::assertSame('gdpr_art9', SegnalazioniDiViolazione::CATEGORIA_TAKEDOWN);

        // E devono essere valori che i moduli offrono davvero, non stringhe
        // inventate: un filtro su un valore inesistente non trova mai niente,
        // e il controllo direbbe sempre «tutto a posto».
        self::assertContains(
            SegnalazioniDiViolazione::OGGETTO_DPO,
            \App\Controllers\DpoContactController::SUBJECTS,
            'l\'oggetto deve essere uno di quelli del modulo'
        );
        $migrazione = (string)file_get_contents(
            \dirname(__DIR__, 2) . '/database/migrations/057_takedown_requests.sql'
        );
        self::assertStringContainsString(
            SegnalazioniDiViolazione::CATEGORIA_TAKEDOWN,
            $migrazione,
            'la categoria deve essere una di quelle della tabella'
        );
    }

    #[Test]
    public function dice_dove_si_va_a_guardarla(): void
    {
        self::assertSame('/admin/data-requests/7', SegnalazioniDiViolazione::dove('dpo', 7));
        self::assertSame('/admin/takedown/7', SegnalazioniDiViolazione::dove('takedown', 7));
    }

    /**
     * L'invariante dev'essere agganciato alla diagnostica, altrimenti misura
     * nel vuoto; e i due pannelli devono offrire il modo di spegnerlo
     * correttamente, altrimenti resta rosso per sempre — che è la stessa
     * malattia con un altro nome.
     */
    #[Test]
    public function l_invariante_e_agganciato_e_si_puo_spegnere(): void
    {
        $radice = \dirname(__DIR__, 2);

        $diag = (string)file_get_contents($radice . '/tools/ops/diagnostica.php');
        self::assertStringContainsString("daFare('segnalazioni')", $diag, 'il controllo non gira');
        self::assertStringContainsString('SegnalazioniDiViolazione', $diag);

        foreach ([
            'views/admin/data_requests_show.php',
            'views/admin/takedown_show.php',
        ] as $vista) {
            self::assertStringContainsString(
                'value="open_incident"',
                (string)file_get_contents($radice . '/' . $vista),
                "senza il bottone in {$vista} l'allarme non si può spegnere correttamente"
            );
        }

        foreach ([
            'app/Controllers/Admin/AdminGdprController.php',
            'app/Controllers/Admin/AdminTakedownController.php',
        ] as $controller) {
            self::assertStringContainsString(
                "'open_incident'",
                (string)file_get_contents($radice . '/' . $controller),
                "il bottone in {$controller} non è collegato a niente"
            );
        }
    }
}
