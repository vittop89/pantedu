<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Core\Config;
use App\Core\Database;
use PDO;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Un `Duplicate entry` non è «già applicato» (23/9/2026, revisione
 * architetturale A-68).
 *
 * Il Migrator salta le istruzioni che falliscono perché l'oggetto c'è già
 * (colonna, indice, tabella, chiave esterna: 1060, 1061, 1050, 1068, 1826,
 * 121) e registra la migrazione come eseguita. Nell'elenco c'era anche
 * `Duplicate entry` (1062), che non dice che un oggetto esiste ma che dei
 * DATI sono in conflitto: un `ADD UNIQUE` su una colonna con doppioni, o un
 * INSERT su una chiave già presente. La migrazione passava a vuoto, la chiave
 * unica non c'era, `tools/migrate.php` usciva con 0 e il database si
 * dichiarava allineato. Sul codice di prima le due prove dei doppioni
 * fallivano: esito 0 e migrazione registrata.
 *
 * Si lancia lo strumento vero, come il rilascio, su una cartella di migrazioni
 * temporanea e sul database di prova. La controprova: una colonna che c'è già
 * (1060) resta «già applicata», perché quello è il caso per cui il salto
 * esiste. Tabelle e file hanno un nome unico per giro, e si tolgono alla
 * fine, con le righe di tracciamento, anche se la prova fallisce.
 */
final class MigrazioneConDoppioniTest extends TestCase
{
    private PDO $pdo;
    private string $cartella = '';
    private string $tabella = '';
    /** @var list<string> */
    private array $file = [];

    protected function setUp(): void
    {
        $base = dirname(__DIR__, 2);
        foreach (['.env', '.env.local'] as $f) {
            if (is_file("$base/$f")) {
                \Dotenv\Dotenv::createMutable($base, $f)->safeLoad();
            }
        }
        Config::load($base . '/app/Config');
        try {
            $this->pdo = Database::connection();
            $this->pdo->query('SELECT 1');
        } catch (\Throwable $e) {
            $this->markTestSkipped('DB non disponibile: ' . $e->getMessage());
        }
        $marca = date('YmdHis') . '_' . bin2hex(random_bytes(3));
        $this->tabella = 'zz_doppioni_' . $marca;
        $this->cartella = sys_get_temp_dir() . '/pantedu_migrazione_doppioni_' . $marca;
        mkdir($this->cartella);
        // La prima migrazione crea la tabella con due righe che hanno lo
        // stesso valore in `v`: il doppione che le altre incontrano.
        $this->migrazione(
            '001',
            'crea',
            'CREATE TABLE ' . $this->tabella . " (id INT PRIMARY KEY, v INT NOT NULL);\n"
            . 'INSERT INTO ' . $this->tabella . " (id, v) VALUES (1, 5), (2, 5);\n"
        );
    }

    protected function tearDown(): void
    {
        if (isset($this->pdo)) {
            $this->pdo->exec('DROP TABLE IF EXISTS ' . $this->tabella);
            if ($this->file !== []) {
                $segnaposti = implode(',', array_fill(0, count($this->file), '?'));
                try {
                    $this->pdo->prepare("DELETE FROM schema_migrations WHERE filename IN ($segnaposti)")
                        ->execute($this->file);
                } catch (\PDOException) {
                    // la tabella di tracciamento non c'è: niente da togliere
                }
            }
        }
        foreach (glob($this->cartella . '/*') ?: [] as $f) {
            @unlink($f);
        }
        if ($this->cartella !== '') {
            @rmdir($this->cartella);
        }
    }

    private function migrazione(string $numero, string $nome, string $sql): string
    {
        $file = $numero . '_' . $this->tabella . '_' . $nome . '.sql';
        file_put_contents($this->cartella . '/' . $file, $sql);
        $this->file[] = $file;
        return $file;
    }

    /** @return array{0:int, 1:string, 2:string} */
    private function lancia(): array
    {
        $comando = [PHP_BINARY, dirname(__DIR__, 2) . '/tools/migrate.php', '--cartella=' . $this->cartella];
        // Un ambiente minimo, come in MigrazioneCheFallisceTest: lo strumento
        // legge .env e .env.local da sé, come quando lo lancia il rilascio.
        $ambiente = ['APP_ENV' => 'testing', 'PATH' => (string)getenv('PATH'), 'HOME' => (string)getenv('HOME')];
        $proc = proc_open($comando, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, dirname(__DIR__, 2), $ambiente);
        self::assertIsResource($proc);
        $out = (string)stream_get_contents($pipes[1]);
        $err = (string)stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        return [proc_close($proc), $out, $err];
    }

    /** @return list<string> */
    private function registrate(): array
    {
        $segnaposti = implode(',', array_fill(0, count($this->file), '?'));
        $stmt = $this->pdo->prepare(
            "SELECT filename FROM schema_migrations WHERE filename IN ($segnaposti) ORDER BY filename"
        );
        $stmt->execute($this->file);
        return $stmt->fetchAll(PDO::FETCH_COLUMN);
    }

    private function haLaChiave(string $nome): bool
    {
        $stmt = $this->pdo->prepare(
            'SELECT COUNT(*) FROM information_schema.STATISTICS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ?'
        );
        $stmt->execute([$this->tabella, $nome]);
        return (int)$stmt->fetchColumn() > 0;
    }

    #[Test]
    public function una_chiave_unica_su_dati_con_doppioni_fa_fallire_la_migrazione(): void
    {
        $crea = $this->file[0];
        $unica = $this->migrazione('002', 'unica', 'ALTER TABLE ' . $this->tabella . " ADD UNIQUE KEY uq_zz_v (v);\n");

        [$esito, $out, $err] = $this->lancia();

        self::assertSame(1, $esito, "esito; stdout: $out");
        self::assertStringContainsString('MIGRAZIONE NON RIUSCITA', $err);
        self::assertStringContainsString($unica, $err, 'dice quale file');
        self::assertStringContainsString('Duplicate entry', $err, 'e il messaggio del database');
        self::assertSame([$crea], $this->registrate(), 'quella con i doppioni non è registrata');
        self::assertFalse($this->haLaChiave('uq_zz_v'), 'e la chiave unica non c\'è');
    }

    #[Test]
    public function un_insert_su_una_chiave_gia_presente_fa_fallire_la_migrazione(): void
    {
        $crea = $this->file[0];
        $this->migrazione('002', 'insert', 'INSERT INTO ' . $this->tabella . " (id, v) VALUES (1, 9);\n");

        [$esito, $out, $err] = $this->lancia();

        self::assertSame(1, $esito, "esito; stdout: $out");
        self::assertStringContainsString('Duplicate entry', $err);
        self::assertSame([$crea], $this->registrate());
        self::assertSame(5, (int)$this->pdo->query('SELECT v FROM ' . $this->tabella . ' WHERE id = 1')->fetchColumn());
    }

    #[Test]
    public function una_colonna_che_c_e_gia_resta_gia_applicata(): void
    {
        // Il caso per cui il salto esiste: una migrazione rilanciata su un
        // database che ha già la colonna (1060 Duplicate column name).
        $this->migrazione('002', 'colonna', 'ALTER TABLE ' . $this->tabella . " ADD COLUMN w INT NULL;\n");
        $this->migrazione('003', 'colonna_ancora', 'ALTER TABLE ' . $this->tabella . " ADD COLUMN w INT NULL;\n");

        [$esito, $out, $err] = $this->lancia();

        self::assertSame(0, $esito, "esito; stderr: $err");
        // Su stderr può esserci solo la riga con cui il Migrator registra
        // l'istruzione saltata. error_log() la manda nel registro dell'app se
        // la cartella dei log c'è, e su stderr se non c'è, come in una copia
        // appena clonata: chiedere stderr vuoto faceva dipendere la prova
        // dall'ordine (in CI la cartella la crea la suite unit, che gira
        // prima). Un `-d error_log=…` al processo figlio non basta:
        // bootstrap.php reimposta error_log da sé.
        self::assertStringNotContainsString('MIGRAZIONE NON RIUSCITA', $err);
        foreach (preg_split('/\R/', trim($err)) ?: [] as $riga) {
            if ($riga !== '') {
                self::assertMatchesRegularExpression(
                    '/\[migrator\] \S+_colonna_ancora\.sql: statement already applied, skipping/',
                    $riga,
                    "stderr: $err"
                );
            }
        }
        self::assertStringContainsString("Statement saltati come gia' applicati: 1", $out);
        self::assertSame($this->file, $this->registrate(), 'tutte e tre registrate');
    }
}
