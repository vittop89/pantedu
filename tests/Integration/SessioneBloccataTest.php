<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Core\Database;
use App\Core\DbSessionHandler;
use PDO;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Due richieste della stessa sessione non si cancellano le scritture (14/9/2026).
 *
 * DbSessionHandler non bloccava la sessione: una richiesta partita prima di
 * un'altra e finita dopo ne riscriveva i dati. Nella suite end-to-end il
 * gettone CSRF preso da /auth/csrf spariva mentre la pagina caricava le sue
 * richieste («403 — CSRF invalid», due corse su quattro la notte del 14/9).
 *
 * La prova usa due processi veri, perché il blocco è della connessione: un
 * processo figlio legge la sessione, aspetta, scrive e chiude; il test, con la
 * sua connessione, legge mentre il figlio la tiene. Deve aspettare, e vedere
 * la scrittura del figlio. Con il gestore di prima leggeva subito i dati
 * vecchi. Poi il verso dell'attesa finita (si prosegue senza blocco) e il
 * blocco che si rilascia alla chiusura.
 *
 * Solo MariaDB/MySQL. La prova lavora su una tabella sua, con la definizione
 * di `sessions` in database/schema.sql, creata qui e tolta a fine prova: la
 * tabella `sessions` c'è solo dove il database nasce da schema.sql (la CI),
 * non in sviluppo né in produzione, e una prova che si saltasse dove manca
 * sarebbe un verde che non guarda.
 */
final class SessioneBloccataTest extends TestCase
{
    private PDO $pdo;
    private string $id = '';
    private string $figlio = '';
    private string $tabella = '';

    protected function setUp(): void
    {
        $base = dirname(__DIR__, 2);
        foreach (['.env', '.env.local'] as $f) {
            if (is_file("$base/$f")) {
                \Dotenv\Dotenv::createMutable($base, $f)->safeLoad();
            }
        }
        \App\Core\Config::load($base . '/app/Config');
        try {
            $this->pdo = Database::connection();
            if ($this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'mysql') {
                $this->markTestSkipped('il blocco con nome è di MariaDB/MySQL');
            }
        } catch (\Throwable $e) {
            $this->markTestSkipped('DB non disponibile: ' . $e->getMessage());
        }
        $this->tabella = 'sessioni_prova_' . bin2hex(random_bytes(6));
        $this->pdo->exec(
            "CREATE TABLE `{$this->tabella}` (
                id          VARCHAR(128) PRIMARY KEY,
                data        LONGBLOB     NOT NULL,
                last_access INT UNSIGNED NOT NULL,
                ip          VARCHAR(45)  NULL,
                ua          VARCHAR(255) NULL,
                INDEX idx_last_access (last_access)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
        $this->id = 'prova-blocco-' . bin2hex(random_bytes(10));
        $this->figlio = sys_get_temp_dir() . '/pantedu-sessione-figlio-' . bin2hex(random_bytes(6)) . '.php';
        file_put_contents($this->figlio, <<<'PHP'
<?php
// Il figlio: legge la sessione, la tiene per il tempo dato, scrive e chiude.
[$script, $base, $id, $tieni, $dati, $tabella] = $argv;
require $base . '/vendor/autoload.php';
foreach (['.env', '.env.local'] as $f) {
    if (is_file("$base/$f")) {
        \Dotenv\Dotenv::createMutable($base, $f)->safeLoad();
    }
}
\App\Core\Config::load($base . '/app/Config');
$h = new \App\Core\DbSessionHandler(\App\Core\Database::connection(), 1800, 10, $tabella);
$h->read($id);
fwrite(STDOUT, "letto\n");
// Senza, verso una pipe la riga arriva al genitore solo alla fine del processo.
fflush(STDOUT);
usleep((int)$tieni);
$h->write($id, $dati);
$h->close();
fwrite(STDOUT, "chiuso\n");
PHP);
        (new DbSessionHandler($this->pdo, 1800, 15, $this->tabella))->write($this->id, 'stato|s:5:"prima";');
    }

    protected function tearDown(): void
    {
        if ($this->tabella !== '') {
            $this->pdo->exec("DROP TABLE IF EXISTS `{$this->tabella}`");
        }
        if ($this->figlio !== '' && is_file($this->figlio)) {
            unlink($this->figlio);
        }
    }

    /**
     * Avvia il figlio e aspetta che abbia letto la sessione (non che tenga il
     * blocco: così, se il blocco non ci fosse, la prova cadrebbe sulla
     * scrittura persa e non sull'attesa di un blocco che non arriva).
     *
     * @return array{0:resource,1:array<int,resource>}
     */
    private function figlioCheTiene(int $microsecondi, string $dati): array
    {
        $base = dirname(__DIR__, 2);
        $cmd = [PHP_BINARY, $this->figlio, $base, $this->id, (string)$microsecondi, $dati, $this->tabella];
        $env = array_merge(getenv(), ['APP_ENV' => 'testing']);
        $proc = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $base, $env);
        $this->assertIsResource($proc, 'il processo figlio parte');
        $riga = fgets($pipes[1]);
        // L'errore del figlio si legge solo se serve: leggerlo aspetta che il
        // figlio finisca, e metterlo nel messaggio dell'asserzione lo leggerebbe
        // sempre, prima del confronto (la prima versione della prova misurava
        // così un'attesa che non c'era).
        if ($riga !== "letto\n") {
            $this->fail('il figlio non ha letto la sessione: ' . stream_get_contents($pipes[2]));
        }
        return [$proc, $pipes];
    }

    /** @param array{0:resource,1:array<int,resource>} $figlio */
    private function aspettaIlFiglio(array $figlio): string
    {
        [$proc, $pipes] = $figlio;
        $out = (string)stream_get_contents($pipes[1]) . (string)stream_get_contents($pipes[2]);
        proc_close($proc);
        return $out;
    }

    #[Test]
    public function chi_legge_mentre_un_altra_richiesta_tiene_la_sessione_aspetta_e_ne_vede_la_scrittura(): void
    {
        $figlio = $this->figlioCheTiene(1_500_000, 'stato|s:4:"dopo";');

        $inizio = microtime(true);
        $h = new DbSessionHandler($this->pdo, 1800, 10, $this->tabella);
        $dati = $h->read($this->id);
        $atteso = microtime(true) - $inizio;
        $h->close();

        $uscita = $this->aspettaIlFiglio($figlio);
        $this->assertStringContainsString('chiuso', $uscita, 'il figlio ha scritto e chiuso');
        $this->assertGreaterThan(0.8, $atteso, 'ha aspettato che la sessione fosse chiusa');
        $this->assertSame('stato|s:4:"dopo";', $dati, 'e ha letto la scrittura del figlio, non i dati di prima');
    }

    #[Test]
    public function finita_l_attesa_si_legge_senza_blocco(): void
    {
        $figlio = $this->figlioCheTiene(3_000_000, 'stato|s:4:"dopo";');

        $inizio = microtime(true);
        $h = new DbSessionHandler($this->pdo, 1800, 1, $this->tabella);
        $dati = $h->read($this->id);
        $atteso = microtime(true) - $inizio;
        $h->close();

        $this->assertGreaterThan(0.8, $atteso, "ha aspettato l'attesa data");
        $this->assertLessThan(2.5, $atteso, 'e poi è andata avanti, senza restare ferma');
        $this->assertSame('stato|s:5:"prima";', $dati, 'con i dati che trovava');
        $this->aspettaIlFiglio($figlio);
    }

    #[Test]
    public function il_blocco_si_prende_leggendo_e_si_rilascia_chiudendo(): void
    {
        $nome = DbSessionHandler::nomeDelBlocco($this->id);
        $this->assertLessThanOrEqual(64, strlen($nome), 'MariaDB accetta nomi di blocco di al più 64 caratteri');
        $libero = $this->pdo->prepare('SELECT IS_FREE_LOCK(?)');

        $h = new DbSessionHandler($this->pdo, 1800, 15, $this->tabella);
        $h->read($this->id);
        $libero->execute([$nome]);
        $this->assertSame(0, (int)$libero->fetchColumn(), 'leggendo, la sessione è bloccata');
        $h->close();
        $libero->execute([$nome]);
        $this->assertSame(1, (int)$libero->fetchColumn(), 'chiudendo, il blocco si rilascia');
    }
}
