<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Gdpr\SegnalazioneDiViolazione;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Chi segnala una violazione non si sente promettere trenta giorni.
 *
 * Il modulo `/dpo-contact` offre nove oggetti e uno è «Segnalazione data
 * breach», ma fino al 22/9/2026 la ricevuta era la stessa per tutti e nove:
 * «risposta entro 30 giorni, prorogabili a 60 (art. 12 §3)». Per una richiesta
 * di accesso è giusto. Per una violazione l'orologio è l'art. 33, e dà 72 ore
 * da quando il titolare ne viene a conoscenza — cioè da quel modulo.
 *
 * Le due direzioni, che qui sono letteralmente due: sulle richieste di diritti
 * i trenta giorni devono **restare** (toglierli sarebbe l'errore opposto, e
 * grosso), e sulle violazioni non devono comparire affatto.
 */
final class SegnalazioneDiViolazioneTest extends TestCase
{
    private const VIOLAZIONE = 'breach_report';

    /** @return list<string> gli altri otto oggetti del modulo */
    private static function richiesteDiDiritti(): array
    {
        return ['access', 'rectification', 'erasure', 'restriction',
                'portability', 'objection', 'consent_revoke', 'other'];
    }

    #[Test]
    public function riconosce_solo_la_segnalazione_di_violazione(): void
    {
        self::assertTrue(SegnalazioneDiViolazione::riconosce(self::VIOLAZIONE));
        foreach (self::richiesteDiDiritti() as $o) {
            self::assertFalse(SegnalazioneDiViolazione::riconosce($o), "non è una violazione: {$o}");
        }
    }

    #[Test]
    public function alla_violazione_si_dicono_le_72_ore_e_non_i_30_giorni(): void
    {
        $ricevuta = implode(' ', SegnalazioneDiViolazione::passiDellaRicevuta(self::VIOLAZIONE));
        $email    = implode(' ', SegnalazioneDiViolazione::passiDellEmail(self::VIOLAZIONE));

        foreach (['ricevuta' => $ricevuta, 'email' => $email] as $dove => $testo) {
            self::assertStringContainsString('72 ore', $testo, "{$dove}: manca il termine vero");
            self::assertStringContainsString('33', $testo, "{$dove}: manca l'articolo");
            self::assertStringNotContainsString(
                '30 giorni',
                $testo,
                "{$dove}: a chi segnala una violazione non si promette un mese"
            );
        }
    }

    /**
     * La direzione opposta, e non è una formalità: togliere i trenta giorni
     * alle richieste di diritti sarebbe una violazione dell'art. 12 §3.
     */
    #[Test]
    public function alle_richieste_di_diritti_i_30_giorni_restano(): void
    {
        foreach (self::richiesteDiDiritti() as $oggetto) {
            $ricevuta = implode(' ', SegnalazioneDiViolazione::passiDellaRicevuta($oggetto));
            $email    = implode(' ', SegnalazioneDiViolazione::passiDellEmail($oggetto));

            self::assertStringContainsString('30 giorni', $ricevuta, "ricevuta di {$oggetto}");
            self::assertStringContainsString('30 giorni', $email, "email di {$oggetto}");
            self::assertStringContainsString('12 §3', $ricevuta, "base normativa in {$oggetto}");
        }
    }

    #[Test]
    public function la_violazione_non_si_segna_da_sola_come_presa_in_carico(): void
    {
        self::assertSame(
            'open',
            SegnalazioneDiViolazione::statoDopoLaRicevuta(self::VIOLAZIONE),
            'una riga `acknowledged` nella coda si legge come già sistemata'
        );
        foreach (self::richiesteDiDiritti() as $oggetto) {
            self::assertSame(
                'acknowledged',
                SegnalazioneDiViolazione::statoDopoLaRicevuta($oggetto),
                "per {$oggetto} la ricevuta È l'atto dovuto, e va registrata"
            );
        }
    }

    #[Test]
    public function l_operatore_legge_l_orologio_giusto(): void
    {
        $violazione = SegnalazioneDiViolazione::intestazionePerOperatore(self::VIOLAZIONE);
        self::assertStringContainsString('72 ore', $violazione);
        self::assertStringContainsString('data_breach_runbook', $violazione, 'e dove sta la procedura');
        self::assertStringContainsString('Istituto', $violazione, 'e il caso del dato di un altro titolare');
        self::assertStringNotContainsString('30 giorni', $violazione);

        $diritti = SegnalazioneDiViolazione::intestazionePerOperatore('access');
        self::assertStringContainsString('30 giorni', $diritti);
    }

    /**
     * Gli oggetti che il controller dichiara e quelli che questa prova copre
     * devono essere gli stessi: se qualcuno ne aggiunge uno al modulo senza
     * passare di qui, questa prova lo dice invece di lasciarlo scoperto.
     */
    #[Test]
    public function la_prova_copre_tutti_gli_oggetti_del_modulo(): void
    {
        $dichiarati = \App\Controllers\DpoContactController::SUBJECTS;
        $coperti    = array_merge(self::richiesteDiDiritti(), [self::VIOLAZIONE]);

        sort($dichiarati);
        sort($coperti);
        self::assertSame(
            $dichiarati,
            $coperti,
            'il modulo offre oggetti che questa prova non guarda'
        );
    }
}
