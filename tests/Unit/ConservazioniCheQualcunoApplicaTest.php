<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Ogni soglia di conservazione dichiarata è letta da qualcuno.
 *
 * ── Il difetto, misurato il 22/9/2026 ─────────────────────────────────────
 *
 * `app/Config/retention.php` conteneva sei soglie. Tre — `access_log_days`
 * (365), `backup_db_days` (90), `backup_files_days` (30) — **non erano lette
 * da nessun file** del progetto.
 *
 * Non erano inerti. L'informativa §5 dichiarava «365 giorni» per il registro
 * di navigazione e indicava quel file come prova di ciò che applica i termini,
 * mentre il contenimento vero è un troncamento alle ultime mille voci — in
 * produzione circa una settimana.
 *
 * Quindi: un numero scritto in un documento che si consegna a un'autorità, che
 * rimanda a una configurazione, che nessun codice legge. È la forma di guasto
 * che questo progetto insegue, capitata dentro il documento il cui mestiere è
 * dimostrare.
 *
 * ── Che cosa difende questa prova ─────────────────────────────────────────
 *
 * Non che i termini siano giusti: che siano **applicati da qualcuno**. Una
 * soglia in questo file è una promessa, e una promessa senza un lettore è una
 * dichiarazione falsa a scoppio ritardato — si scopre quando qualcuno chiede
 * conto, cioè tardi.
 */
final class ConservazioniCheQualcunoApplicaTest extends TestCase
{
    private static function radice(): string
    {
        return \dirname(__DIR__, 2);
    }

    /**
     * Le soglie dichiarate, lette dal file stesso: se se ne aggiunge una, entra
     * da sola in questo controllo.
     *
     * @return list<string>
     */
    private static function soglieDichiarate(): array
    {
        $sorgente = (string)file_get_contents(self::radice() . '/app/Config/retention.php');

        // Solo le chiavi che sono termini, non gli interruttori.
        preg_match_all("/^\s*'([a-z_]+_days)'\s*=>/m", $sorgente, $trovate);

        return array_values(array_unique($trovate[1]));
    }

    /** Chi legge una chiave, in tutto il progetto, fuori dal file che la dichiara. */
    private static function chiLaLegge(string $chiave): array
    {
        $lettori = [];
        $cartelle = ['app', 'tools', 'routes'];

        foreach ($cartelle as $cartella) {
            $iteratore = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator(self::radice() . '/' . $cartella)
            );
            foreach ($iteratore as $file) {
                if (!$file->isFile() || $file->getExtension() !== 'php') {
                    continue;
                }
                $percorso = str_replace(self::radice() . '/', '', $file->getPathname());
                if ($percorso === 'app/Config/retention.php') {
                    continue;
                }
                if (str_contains((string)file_get_contents($file->getPathname()), $chiave)) {
                    $lettori[] = $percorso;
                }
            }
        }

        return $lettori;
    }

    // ─────────────────────────────────────────────────────────────────────

    /** Controllo positivo: se non si leggesse nessuna soglia, tutto passerebbe. */
    #[Test]
    public function le_soglie_si_leggono_dal_file(): void
    {
        $soglie = self::soglieDichiarate();

        self::assertGreaterThanOrEqual(3, \count($soglie), 'le soglie dichiarate sono almeno tre');
        self::assertContains('inactive_account_days', $soglie, 'questa c\'è di sicuro');
    }

    /**
     * Controllo positivo sul metodo: una chiave che di certo nessuno legge non
     * deve risultare letta. Senza, una funzione che trova sempre un lettore
     * farebbe passare tutto.
     */
    #[Test]
    public function il_controllo_riconosce_una_chiave_che_nessuno_legge(): void
    {
        self::assertSame(
            [],
            self::chiLaLegge('soglia_inventata_che_nessuno_usa_mai'),
            'il controllo trova lettori dove non ce ne sono: non misurerebbe niente'
        );
    }

    /** La direzione che conta. */
    #[Test]
    public function ogni_soglia_dichiarata_e_letta_da_qualcuno(): void
    {
        $orfane = [];
        foreach (self::soglieDichiarate() as $soglia) {
            if (self::chiLaLegge($soglia) === []) {
                $orfane[] = $soglia;
            }
        }

        self::assertSame(
            [],
            $orfane,
            "Queste soglie sono dichiarate in app/Config/retention.php e non le legge nessuno.\n"
            . "Una conservazione dichiarata e non applicata è peggio di una non dichiarata:\n"
            . "l'informativa la cita come termine, e il termine non esiste.\n"
            . "O la si fa leggere da un lavoro, o si toglie e si dichiara il termine vero.\n  "
            . implode("\n  ", $orfane) . "\n"
        );
    }
}
