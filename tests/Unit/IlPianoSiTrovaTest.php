<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Il piano per le violazioni si trova da dove serve.
 *
 * ── Il buco, misurato il 22/9/2026 ───────────────────────────────────────
 *
 * `docs/privacy/data_breach_runbook.md` esisteva, era stato riscritto, e non
 * era raggiungibile **da nessuna pagina**: nessun collegamento nel pannello,
 * nessuna rotta che lo rendesse. Stava nel repository, e basta.
 *
 * Chi ne ha bisogno lo cerca in un momento pessimo, con le settantadue ore
 * dell'art. 33 già partite, e dovrebbe sapere che esiste e dove. Un piano che
 * si trova solo se si sa già dove guardare non è un piano: è un file.
 *
 * ── Due cose diverse, e servono entrambe ─────────────────────────────────
 *
 * I **primi passi** stanno in chiaro sulla pagina del registro incidenti,
 * perché in emergenza non si scarica niente: si legge. Il **piano completo**
 * si scarica, perché è lungo e lo si legge dopo, con calma.
 *
 * ── Che cosa difende questa prova ────────────────────────────────────────
 *
 * Che il collegamento ci sia, che la rotta stia **prima** di quella con
 * l'identificativo (altrimenti «piano» verrebbe letto come un numero), e che
 * il riquadro d'emergenza non dica cose che il piano non dice più: è la copia
 * che diverge, e qui ce n'è una per forza.
 */
final class IlPianoSiTrovaTest extends TestCase
{
    private static function radice(): string
    {
        return \dirname(__DIR__, 2);
    }

    private static function legge(string $relativo): string
    {
        return (string)file_get_contents(self::radice() . '/' . $relativo);
    }

    #[Test]
    public function il_piano_esiste_e_ha_la_fase_zero(): void
    {
        $piano = self::legge('docs/privacy/data_breach_runbook.md');

        self::assertStringContainsString('Fase 0', $piano, 'controllo positivo: è il piano giusto');
        self::assertStringContainsString('viene a conoscenza', $piano);
    }

    #[Test]
    public function si_scarica_da_una_rotta_dell_amministratore(): void
    {
        $rotte = self::legge('routes/web.php');

        self::assertStringContainsString(
            "'/admin/data-breach/piano'",
            $rotte,
            'senza rotta il piano resta un file nel repository'
        );

        // L'ordine conta: `{id}` cattura qualunque segmento, quindi una rotta
        // letterale che gli finisse dopo non verrebbe mai raggiunta.
        $piano = strpos($rotte, "'/admin/data-breach/piano'");
        $conId = strpos($rotte, "'/admin/data-breach/{id}'");
        self::assertIsInt($piano);
        self::assertIsInt($conId);
        self::assertLessThan(
            $conId,
            $piano,
            '«piano» verrebbe letto come un identificativo: la rotta letterale va prima'
        );

        $controller = self::legge('app/Controllers/Admin/AdminGdprController.php');
        self::assertStringContainsString('function dataBreachPiano', $controller);
        self::assertStringContainsString(
            'data_breach_runbook.md',
            $controller,
            'e deve servire proprio quel file'
        );
        self::assertStringContainsString(
            'Content-Disposition',
            $controller,
            'si scarica, non si rende: il convertitore markdown sta altrove'
        );
    }

    #[Test]
    public function la_pagina_del_registro_ci_porta(): void
    {
        $vista = self::legge('views/admin/data_breach_index.php');

        self::assertStringContainsString(
            '/admin/data-breach/piano',
            $vista,
            'il collegamento va dove si apre l\'incidente, non in un menu lontano'
        );
        self::assertStringContainsString(
            'Se è appena successo',
            $vista,
            'i primi passi si leggono in pagina: in emergenza non si scarica niente'
        );
    }

    /**
     * E deve entrare nell'immagine.
     *
     * Misurato il 22/9/2026, dopo aver rilasciato: il file NON c'era nel
     * container. `.dockerignore` esclude `docs/` per intero e riammette un
     * elenco scelto — le tre informative, i documenti legali, il curriculum —
     * e il piano non era fra quelli. La rotta rispondeva 404 proprio nel
     * momento in cui serve, e nessuna prova se ne sarebbe accorta: in locale
     * il file c'è sempre.
     *
     * È lo stesso difetto che il commento di quel file racconta di sé: «trovato
     * dalla prova delle rotte sul container, non da un ragionamento».
     */
    #[Test]
    public function il_piano_entra_nell_immagine(): void
    {
        $ignora = self::legge('.dockerignore');

        self::assertStringContainsString(
            'docs',
            $ignora,
            'controllo positivo: è il file giusto e esclude la cartella'
        );
        self::assertStringContainsString(
            '!docs/privacy/data_breach_runbook.md',
            $ignora,
            "senza questa riga /admin/data-breach/piano risponde 404 nel container"
        );
    }

    /**
     * La copia che diverge. Il riquadro ripete in breve la Fase 0 e la Fase 1
     * del piano: se il piano cambia e il riquadro no, la pagina dice una cosa
     * e il documento un'altra — proprio nel momento in cui contano.
     */
    #[Test]
    public function il_riquadro_non_promette_cose_che_il_piano_non_dice(): void
    {
        $vista = self::legge('views/admin/data_breach_index.php');
        $piano = self::legge('docs/privacy/data_breach_runbook.md');

        $affermazioni = [
            'le 72 ore decorrono dalla conoscenza' => ['settantadue ore', 'viene a conoscenza'],
            'detected_at non si corregge'          => ['detected_at', 'detected_at'],
            'il titolare può essere l\'Istituto'   => ['Istituto', 'Istituto'],
            'si avvisa senza ritardo'              => ['senza ingiustificato ritardo', 'senza ingiustificato ritardo'],
        ];

        foreach ($affermazioni as $cosa => [$nelRiquadro, $nelPiano]) {
            self::assertStringContainsString($nelRiquadro, $vista, "il riquadro deve dirlo: {$cosa}");
            self::assertStringContainsString($nelPiano, $piano, "e il piano deve confermarlo: {$cosa}");
        }
    }
}
