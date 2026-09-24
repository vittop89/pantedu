<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Middleware\RateLimitMiddleware;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Il limite che i documenti promettono è quello che opera.
 *
 * ── Il difetto, misurato il 22/9/2026 ─────────────────────────────────────
 *
 * `/segnalazione-contenuti` era dichiarato **3 all'ora per IP** in tre posti:
 * la pagina pubblica `/legal/takedown-procedure`, l'indice dei documenti
 * legali, e il commento del controller. Operava a **tre al minuto**, cioè
 * centottanta all'ora, perché il limitatore aveva una finestra sola —
 * sessanta secondi — per tutte le rotte.
 *
 * Un controllo dichiarato che non opera, e per giunta scritto in un documento
 * pubblicato in rete. Dei due modi di riallineare si è scelto quello che tiene
 * fede al documento, perché il limite promesso è anche quello giusto: quei
 * moduli mandano posta a ogni invio.
 *
 * ── Che cosa difende questa prova ─────────────────────────────────────────
 *
 * Non basta che `finestraSecondi()` restituisca 3600: bisogna che quel numero
 * **arrivi fino al conteggio**. Una funzione giusta chiamata da nessuno è il
 * difetto che questo progetto insegue. Quindi si legge la dichiarazione delle
 * rotte vere e la si confronta con quella dei documenti.
 */
final class FinestraDelLimitatoreTest extends TestCase
{
    private static function radice(): string
    {
        return \dirname(__DIR__, 2);
    }

    #[Test]
    public function senza_terzo_parametro_vale_la_finestra_di_sempre(): void
    {
        self::assertSame(60, RateLimitMiddleware::finestraSecondi(null));
        self::assertSame(60, RateLimitMiddleware::finestraSecondi(''));
        self::assertSame(60, RateLimitMiddleware::finestraSecondi('non-un-numero'));
        self::assertSame(60, RateLimitMiddleware::finestraSecondi('0'), 'zero non è una finestra');
        self::assertSame(60, RateLimitMiddleware::finestraSecondi('-30'), 'né un numero negativo');
    }

    #[Test]
    public function con_il_terzo_parametro_vale_quello(): void
    {
        self::assertSame(3600, RateLimitMiddleware::finestraSecondi('3600'));
        self::assertSame(86400, RateLimitMiddleware::finestraSecondi('86400'));
    }

    /**
     * La parte che conta: le due rotte pubbliche che i documenti dichiarano
     * «all'ora» devono dichiararlo anche al limitatore.
     */
    #[Test]
    public function le_rotte_dichiarate_allora_hanno_la_finestra_di_unora(): void
    {
        $rotte = (string)file_get_contents(self::radice() . '/routes/web.php');

        foreach (['dpo', 'takedown'] as $secchio) {
            preg_match("/'rate:{$secchio},(\d+)(?:,(\d+))?'/", $rotte, $m);

            self::assertNotEmpty($m, "la rotta del secchio «{$secchio}» non si trova più");
            self::assertSame('3', $m[1], "il numero di invii per «{$secchio}»");
            self::assertSame(
                3600,
                RateLimitMiddleware::finestraSecondi($m[2] ?? null),
                "«{$secchio}» è dichiarato 3 all'ora nei documenti: qui deve dire 3600"
            );
        }
    }

    /**
     * La direzione opposta: le rotte che NON dichiarano una finestra devono
     * restare a sessanta secondi. Una modifica che allungasse la finestra a
     * tutti renderebbe il sito inusabile senza che nessuno l'abbia chiesto.
     */
    #[Test]
    public function le_altre_rotte_restano_al_minuto(): void
    {
        $rotte = (string)file_get_contents(self::radice() . '/routes/web.php');
        preg_match_all("/'rate:([a-z_]+),(\d+)(?:,(\d+))?'/", $rotte, $tutte, PREG_SET_ORDER);

        self::assertGreaterThanOrEqual(
            8,
            \count($tutte),
            'le rotte con un limite sono molte di più: la lettura non ha funzionato'
        );

        // `preg_match_all` non popola il terzo gruppo quando manca: la rotta
        // senza finestra ha semplicemente tre elementi invece di quattro.
        $conFinestra = [];
        foreach ($tutte as $m) {
            if (\count($m) > 3) {
                $conFinestra[] = $m[1];
            }
        }

        self::assertSame(
            ['dpo', 'takedown'],
            array_values(array_unique($conFinestra)),
            'solo i due moduli pubblici che mandano posta hanno una finestra diversa'
        );
    }

    /**
     * E i documenti: se qualcuno domani cambia la rotta senza toccarli, o
     * viceversa, questa prova lo dice. È la coppia che si era rotta.
     */
    #[Test]
    public function i_documenti_e_la_rotta_dicono_la_stessa_cosa(): void
    {
        $pagina = (string)file_get_contents(self::radice() . '/docs/legal/takedown_procedure.md');
        self::assertStringContainsString(
            '3/h/IP',
            $pagina,
            'la pagina pubblica dichiara il limite: se cambia, cambia anche la rotta'
        );

        $rotte = (string)file_get_contents(self::radice() . '/routes/web.php');
        self::assertStringContainsString(
            "'rate:takedown,3,3600'",
            $rotte,
            'e la rotta deve dire la stessa cosa in secondi'
        );
    }

    // ── La finestra arriva davvero al conteggio ──────────────────────────
    //
    // Le prove qui sopra guardano la dichiarazione. Queste guardano il
    // comportamento: una funzione giusta che nessuno chiama è il difetto che
    // questo progetto insegue, e la dichiarazione da sola non lo esclude.
    //
    // Si semina il magazzino con tre colpi di due minuti fa. Con la finestra
    // di sempre sono fuori dal conto e la richiesta passa; con quella di
    // un'ora contano, e la quarta richiesta viene respinta. Lo stesso stato,
    // due esiti opposti: è la finestra a deciderli.

    private function richiestaFinta(): \App\Core\Request
    {
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_SERVER['REQUEST_URI']    = '/segnalazione-contenuti';
        $_SERVER['REMOTE_ADDR']    = '203.0.113.7';
        // Sotto il bootstrap delle prove `security.rate_limit_disabled` è vera
        // e il limitatore lascia passare tutto. Senza questa intestazione una
        // prova che si aspetta «passa» sarebbe verde senza aver misurato
        // niente — misurato il 22/9/2026, ed era proprio così finché non l'ho
        // guardato. L'intestazione opera solo in senso restrittivo: nessuna
        // richiesta può allentare un limite.
        $_SERVER['HTTP_X_PANTEDU_RATE_LIMIT'] = 'enforce';
        $_POST = [];
        return new \App\Core\Request();
    }

    /** @param list<int> $colpi */
    private function semina(array $colpi): void
    {
        $_SESSION = ["rate:takedown:ip:203.0.113.7" => $colpi];
    }

    private function esitoCon(?string $finestra): int
    {
        $mw = new RateLimitMiddleware(new \App\Services\RateLimitStore("session"));
        $risposta = $mw->handle(
            $this->richiestaFinta(),
            static fn($r): \App\Core\Response => new \App\Core\Response("passata", 200),
            "takedown",
            "3",
            $finestra,
        );
        return $risposta->status;
    }

    /**
     * Il controllo che tiene oneste le due prove qui sotto: se il limitatore
     * non sta operando, «passa» non dimostra niente. Tre colpi di adesso, con
     * un limite di tre, devono far respingere la quarta richiesta.
     */
    #[Test]
    public function il_limitatore_sta_davvero_operando_in_questa_prova(): void
    {
        $adesso = time();
        $this->semina([$adesso, $adesso, $adesso]);

        self::assertSame(
            429,
            $this->esitoCon(null),
            'il limitatore non sta contando: ogni altra prova di questo file sarebbe vacua'
        );
    }

    #[Test]
    public function tre_colpi_di_due_minuti_fa_non_contano_nella_finestra_di_un_minuto(): void
    {
        $dueMinutiFa = time() - 120;
        $this->semina([$dueMinutiFa, $dueMinutiFa + 1, $dueMinutiFa + 2]);

        self::assertSame(
            200,
            $this->esitoCon(null),
            "con la finestra di sessanta secondi quei colpi sono scaduti: la richiesta passa"
        );
    }

    #[Test]
    public function gli_stessi_tre_colpi_contano_nella_finestra_di_unora(): void
    {
        $dueMinutiFa = time() - 120;
        $this->semina([$dueMinutiFa, $dueMinutiFa + 1, $dueMinutiFa + 2]);

        self::assertSame(
            429,
            $this->esitoCon("3600"),
            "con la finestra di un'ora quei tre colpi sono il limite: la quarta viene respinta"
        );
    }
}
