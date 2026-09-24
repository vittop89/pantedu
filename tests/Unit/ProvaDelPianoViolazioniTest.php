<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * La prova periodica del piano gira davvero, e misura quello che dice.
 *
 * ── Tre difetti, misurati il 22/9/2026 ────────────────────────────────────
 *
 * `tools/gdpr/breach_drill.php` esiste dal 10 settembre e misura sul serio.
 * Ma:
 *
 * 1. **Nessuno l'aveva mai eseguito.** La cartella dei rapporti non esisteva,
 *    la DPIA segnava «drill semestrale PENDING», e l'unica pianificazione
 *    proposta era una riga di crontab — in un sistema dove i lavori periodici
 *    sono unità systemd, quindi senza `OnFailure`: un fallimento muto.
 *
 * 2. **Un passo diceva una cosa e ne contava un'altra.** Si chiamava
 *    `login_attempts_24h` e faceva `SELECT COUNT(*) FROM users WHERE
 *    created_at > ...`, cioè contava gli utenti creati. Dentro lo strumento
 *    che serve a dimostrare di saper ricostruire un incidente.
 *
 * 3. **Controllava la cosa sbagliata sui salvataggi**: che due cartelle di un
 *    disegno abbandonato fossero scrivibili, non che esistesse una copia
 *    recente. Davanti a una violazione serve la seconda — e quelle due
 *    cartelle, non esistendo più, comparivano nel rapporto a ogni giro.
 *
 * ── Che cosa difende questa prova ─────────────────────────────────────────
 *
 * Non può eseguire il drill (serve il database, e in produzione il contesto).
 * Difende le tre cose che si sono rotte in silenzio: che le misure portino il
 * nome di ciò che contano, che il rapporto non finisca in una cartella che
 * sparisce, e che qualcuno lo lanci raccogliendone l'esito.
 */
final class ProvaDelPianoViolazioniTest extends TestCase
{
    private static function radice(): string
    {
        return \dirname(__DIR__, 2);
    }

    private static function drill(): string
    {
        return (string)file_get_contents(self::radice() . '/tools/gdpr/breach_drill.php');
    }

    /** Controllo positivo: se il file cambiasse nome, tutto il resto passerebbe a vuoto. */
    #[Test]
    public function lo_strumento_esiste_ed_e_quello_giusto(): void
    {
        $drill = self::drill();
        self::assertStringContainsString('breach', $drill);
        self::assertStringContainsString('$findings', $drill, 'il drill raccoglie rilievi');
        self::assertMatchesRegularExpression(
            '/exit\(\s*count\(\$findings\)\s*>\s*0\s*\?\s*1\s*:\s*0\s*\)/',
            $drill,
            'e deve uscire 1 quando ne trova: è ciò che rende raccoglibile il suo esito'
        );
    }

    #[Test]
    public function nessuna_misura_porta_piu_il_nome_sbagliato(): void
    {
        $drill = self::drill();

        self::assertStringNotContainsString(
            "'login_attempts_24h' => \"SELECT COUNT(*) FROM users",
            $drill,
            'contava gli utenti creati e si chiamava «tentativi di accesso»'
        );
        self::assertStringContainsString(
            'waf_login_failures',
            $drill,
            'i tentativi falliti stanno lì, ed è la fonte vera'
        );
        self::assertStringContainsString(
            "'utenti_creati_24h'",
            $drill,
            'e il conteggio degli utenti resta, con il suo nome'
        );
    }

    #[Test]
    public function il_rapporto_non_finisce_nel_repository(): void
    {
        $drill = self::drill();

        self::assertStringNotContainsString(
            "dirname(__DIR__, 2) . '/storage/gdpr/drills'",
            $drill,
            'in produzione il repository sta dentro il container: un rapporto scritto lì sparisce al rilascio'
        );
        self::assertStringContainsString(
            "app.paths.storage",
            $drill,
            'il rapporto va nei dati d\'istanza'
        );

        $ignorati = (string)file_get_contents(self::radice() . '/.gitignore');
        self::assertStringContainsString('storage/gdpr/', $ignorati, 'e in sviluppo non si versiona');
    }

    #[Test]
    public function i_salvataggi_si_controllano_per_esistenza_non_per_permessi(): void
    {
        $drill = self::drill();

        self::assertStringNotContainsString(
            "'storage/backups/db'",
            $drill,
            'cartelle di un disegno abbandonato: comparivano nel rapporto a ogni giro'
        );
        self::assertStringContainsString('BACKUP_DIR', $drill, 'la variabile che usa il salvataggio vero');
        self::assertStringContainsString(
            'pantedu-backup-*.tar.gpg',
            $drill,
            'e si guarda che una copia ci SIA, non che si possa scriverla'
        );
    }

    /**
     * La parte che mancava del tutto: qualcuno che lo lanci, e che raccolga
     * l'esito. Senza `OnFailure` un drill che trova qualcosa fallisce in
     * silenzio, che è peggio di non farlo.
     */
    #[Test]
    public function qualcuno_lo_lancia_e_ne_raccoglie_l_esito(): void
    {
        $servizio = self::radice() . '/tools/systemd/pantedu-breach-drill.service';
        $timer    = self::radice() . '/tools/systemd/pantedu-breach-drill.timer';

        self::assertFileExists($servizio, 'senza unità, il drill resta uno strumento che nessuno lancia');
        self::assertFileExists($timer);

        $s = (string)file_get_contents($servizio);
        self::assertStringContainsString('tools/gdpr/breach_drill.php', $s, "e deve lanciare proprio quello");
        self::assertStringContainsString(
            'OnFailure=pantedu-avviso@%n.service',
            $s,
            "senza questo, un drill che trova qualcosa fallisce in silenzio"
        );

        $t = (string)file_get_contents($timer);
        self::assertStringContainsString('Unit=pantedu-breach-drill.service', $t);
        self::assertStringContainsString('Persistent=true', $t, 'una macchina spenta non salta un semestre');
        self::assertSame(
            2,
            substr_count($t, 'OnCalendar='),
            'la cadenza dichiarata è semestrale: due appuntamenti'
        );
    }
}
