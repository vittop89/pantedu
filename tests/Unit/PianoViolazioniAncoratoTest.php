<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Il piano per le violazioni nomina solo cose che esistono.
 *
 * ── Perché serve una prova per un documento ───────────────────────────────
 *
 * Un piano operativo si legge una volta l'anno, e quando si legge c'è un
 * cronometro acceso. È il tipo di documento che invecchia senza che nessuno se
 * ne accorga: la versione precedente chiudeva con tre «comandi rapidi in caso
 * di sospetto breach» che non funzionavano — scrivevano in due cartelle
 * inesistenti e usavano `$DB_NAME` come variabile di shell, che è una chiave
 * del file d'ambiente. Nessuno li aveva mai provati, perché nessuno li aveva
 * mai dovuti usare.
 *
 * Questa prova non giudica il contenuto. Verifica l'unica cosa verificabile a
 * macchina, ed è anche quella che si rompe: che ogni indirizzo, file e unità
 * di sistema citati esistano davvero.
 *
 * ── Provata nelle due direzioni ───────────────────────────────────────────
 *
 * Un controllo che estraesse zero riferimenti passerebbe sempre. Per questo
 * ogni estrazione ha il suo controllo positivo, e una prova dedicata verifica
 * che i riferimenti si trovino davvero nel testo.
 */
final class PianoViolazioniAncoratoTest extends TestCase
{
    /**
     * File che il piano cita e che la copia pubblica non ha: il sanitizer li
     * toglie di proposito (runbook operativi dell'esercente, con fornitori e
     * ubicazioni). Nella copia pubblica la prova li salta; nel repository di
     * sviluppo esistono, e una prova controlla che l'elenco sia esattamente
     * quello che il sanitizer toglie (25/9/2026: la copia pubblica cadeva su
     * kms-recovery.md, citato dalla Fase 3 dal giorno prima).
     */
    private const TOLTI_DALLA_COPIA_PUBBLICA = [
        'docs/security/operations/kms-recovery.md',
    ];

    private const SANITIZER = 'tools/publish/sanitize-for-publication.php';

    private static function radice(): string
    {
        return \dirname(__DIR__, 2);
    }

    private static function piano(): string
    {
        return (string)file_get_contents(self::radice() . '/docs/privacy/data_breach_runbook.md');
    }

    /** @return list<string> gli indirizzi del sito citati nel piano */
    private static function rotteCitate(): array
    {
        preg_match_all('#`(/[a-z0-9][a-z0-9/_.-]*)`#', self::piano(), $m);
        // Solo quelli che sono indirizzi del sito: gli altri sono percorsi di
        // file, e li guarda l'altra estrazione.
        return array_values(array_unique(array_filter(
            $m[1],
            static fn(string $p): bool => !str_contains($p, '.php')
                && !str_contains($p, '.md')
                && !str_starts_with($p, '/var')
                && !str_starts_with($p, '/etc'),
        )));
    }

    /** @return list<string> i file del repository citati nel piano */
    private static function fileCitati(): array
    {
        preg_match_all('#`((?:tools|docs|app|database)/[A-Za-z0-9/_.-]+\.(?:php|md|sql))`#', self::piano(), $m);
        return array_values(array_unique($m[1]));
    }

    /** @return list<string> le unità di sistema citate nel piano */
    private static function unitaCitate(): array
    {
        preg_match_all('/\b(pantedu-[a-z-]+\.(?:service|timer))\b/', self::piano(), $m);
        return array_values(array_unique($m[1]));
    }

    #[Test]
    public function il_piano_dice_da_dove_arriva_la_notizia(): void
    {
        $piano = self::piano();

        self::assertStringContainsString(
            'Fase 0',
            $piano,
            'la fase che dice da dove arriva la notizia: senza, il piano presuppone di saperlo già'
        );
        self::assertStringContainsString(
            'viene a conoscenza',
            $piano,
            'le 72 ore decorrono da lì, non dal guasto'
        );
        self::assertStringContainsString(
            'R20',
            $piano,
            'il caso più probabile qui: un dato di studente in un campo a testo libero'
        );
        self::assertStringContainsString(
            'Istituto',
            $piano,
            'e il titolare di quel dato è la scuola, non noi'
        );
    }

    #[Test]
    public function gli_indirizzi_che_nomina_esistono(): void
    {
        $rotte = self::rotteCitate();

        self::assertGreaterThanOrEqual(4, \count($rotte), 'il piano ne nomina di più: la lettura non ha funzionato');
        self::assertContains('/admin/data-breach', $rotte, 'il registro degli incidenti è citato di sicuro');

        $dichiarate = (string)file_get_contents(self::radice() . '/routes/web.php');
        foreach ($rotte as $r) {
            self::assertStringContainsString(
                "'{$r}'",
                $dichiarate,
                "il piano manda a «{$r}», che non è una rotta dichiarata"
            );
        }
    }

    #[Test]
    public function i_file_che_nomina_esistono(): void
    {
        $file = self::fileCitati();

        self::assertGreaterThanOrEqual(5, \count($file), 'il piano ne nomina di più: la lettura non ha funzionato');
        self::assertContains('tools/gdpr/breach_drill.php', $file);

        $copiaPubblica = !is_file(self::radice() . '/' . self::SANITIZER);
        foreach ($file as $f) {
            if ($copiaPubblica && \in_array($f, self::TOLTI_DALLA_COPIA_PUBBLICA, true)) {
                continue;
            }
            self::assertFileExists(
                self::radice() . '/' . $f,
                "il piano rimanda a «{$f}», che non esiste"
            );
        }
    }

    #[Test]
    public function i_file_citati_che_la_copia_pubblica_toglie_sono_quelli_dichiarati(): void
    {
        $sanitizer = self::radice() . '/' . self::SANITIZER;
        if (!is_file($sanitizer)) {
            // Nella copia pubblica il sanitizer non c'è, e la CI pubblica conta
            // ogni salto come fallimento (--fail-on-skipped): si controlla il
            // verso che lì si può vedere, cioè che i file dichiarati manchino.
            foreach (self::TOLTI_DALLA_COPIA_PUBBLICA as $f) {
                self::assertFileDoesNotExist(
                    self::radice() . '/' . $f,
                    "«{$f}» è dichiarato tolto dalla copia pubblica, e qui c'è"
                );
            }
            return;
        }
        $testo = (string)file_get_contents($sanitizer);
        $inizio = strpos($testo, '$deleteFiles = [');
        self::assertNotFalse($inizio, 'l\'elenco dei file tolti si trova nel sanitizer');
        $fine = strpos($testo, "\n];", $inizio);
        self::assertNotFalse($fine);
        // Solo le righe che sono una voce: i commenti dell'elenco hanno apostrofi.
        preg_match_all("/^\\s*'([^']+)',\\s*$/m", substr($testo, $inizio, $fine - $inizio), $m);
        self::assertContains('docs/ops/vps-info.md', $m[1], 'la lettura dell\'elenco ha funzionato');

        $tolti = array_values(array_intersect(self::fileCitati(), $m[1]));
        sort($tolti);
        $dichiarati = self::TOLTI_DALLA_COPIA_PUBBLICA;
        sort($dichiarati);
        self::assertSame(
            $dichiarati,
            $tolti,
            'il piano cita file che la copia pubblica non ha: vanno dichiarati qui, o il rimando si toglie'
        );
    }

    #[Test]
    public function le_unita_di_sistema_che_nomina_esistono(): void
    {
        $unita = self::unitaCitate();

        self::assertGreaterThanOrEqual(3, \count($unita), 'il piano ne nomina di più: la lettura non ha funzionato');

        foreach ($unita as $u) {
            self::assertFileExists(
                self::radice() . '/tools/systemd/' . $u,
                "il piano dice di lanciare «{$u}», che non è un'unità del progetto"
            );
        }
    }

    /**
     * I comandi che non funzionavano non devono tornare. Sono citati per nome
     * nel documento, come cosa da non rifare: questa prova guarda che non
     * siano tornati come comandi.
     */
    #[Test]
    public function i_comandi_rotti_non_sono_tornati(): void
    {
        $piano = self::piano();

        // Compaiono nel testo che spiega perché erano sbagliati; non devono
        // comparire dentro il blocco dei comandi.
        preg_match_all('/```bash\n(.*?)```/s', $piano, $m);
        $comandi = implode("\n", $m[1]);

        self::assertNotSame('', $comandi, 'il piano deve avere dei comandi: altrimenti non c\'è niente da controllare');
        self::assertStringNotContainsString('storage/backups/db', $comandi);
        self::assertStringNotContainsString('storage/backups/files', $comandi);
        self::assertStringNotContainsString('$DB_NAME', $comandi, 'non è una variabile di shell');
        self::assertStringNotContainsString('mysql -e', $comandi, 'le credenziali non stanno nella shell');
    }

    /** Controllo positivo sulle estrazioni: se non trovano niente, si sa. */
    #[Test]
    public function le_letture_trovano_davvero_qualcosa(): void
    {
        self::assertNotEmpty(self::rotteCitate(), 'nessun indirizzo estratto');
        self::assertNotEmpty(self::fileCitati(), 'nessun file estratto');
        self::assertNotEmpty(self::unitaCitate(), 'nessuna unità estratta');
    }
}
