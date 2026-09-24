<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Core\Config;
use App\Core\Database;
use PDO;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * `tools/migrate.php` dice perché una migrazione non è riuscita (14/9/2026).
 *
 * Il difetto: scrivendo la 125, MariaDB ha rifiutato un vincolo (errore 1901)
 * e lo strumento è uscito con 255 senza scrivere niente, né su stdout né su
 * stderr: il messaggio stava solo in `storage/logs/php_errors.log`. Qui lo si
 * lancia davvero, come fa il rilascio, su una cartella con una migrazione
 * buona e una rotta, sul database di prova; nel verso buono, su una cartella
 * con la sola migrazione buona.
 *
 * Nessun resto: la tabella e le righe di tracciamento della prova si tolgono
 * alla fine, anche se la prova fallisce.
 */
final class MigrazioneCheFallisceTest extends TestCase
{
    private const TABELLA = 'zz_prova_migrazione_rotta';
    private const BUONA = '001_zz_prova_buona.sql';
    private const ROTTA = '002_zz_prova_rotta.sql';

    private PDO $pdo;
    private string $cartella = '';

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
        $this->pulisci();
        $this->cartella = sys_get_temp_dir() . '/pantedu_migrazione_rotta_' . uniqid();
        mkdir($this->cartella);
        file_put_contents($this->cartella . '/' . self::BUONA, 'CREATE TABLE ' . self::TABELLA . " (id INT PRIMARY KEY);\n");
    }

    protected function tearDown(): void
    {
        if (isset($this->pdo)) {
            $this->pulisci();
        }
        foreach (glob($this->cartella . '/*') ?: [] as $f) {
            @unlink($f);
        }
        if ($this->cartella !== '') {
            @rmdir($this->cartella);
        }
    }

    #[Test]
    public function una_migrazione_rotta_si_dice_su_stderr_ed_esce_con_1(): void
    {
        file_put_contents(
            $this->cartella . '/' . self::ROTTA,
            "INSERT INTO " . self::TABELLA . " (id) VALUES (1);\nINSERT INTO zz_tabella_che_non_esiste VALUES (1);\n"
        );

        [$esito, $out, $err] = $this->lancia();

        self::assertSame(1, $esito, "esito; stderr: $err");
        self::assertStringContainsString('MIGRAZIONE NON RIUSCITA', $err);
        self::assertStringContainsString(self::ROTTA, $err, 'dice quale file');
        self::assertStringContainsString('zz_tabella_che_non_esiste', $err, 'e il messaggio del database');
        self::assertStringContainsString('Applicate e registrate prima dell\'errore: ' . self::BUONA, $err);
        self::assertStringContainsString(self::ROTTA, $out, 'l\'elenco di quelle da eseguire resta su stdout');

        self::assertSame([self::BUONA], $this->registrate(), 'la rotta non è registrata');
        // La prima istruzione della rotta è stata eseguita: MariaDB non annulla
        // niente, e lo strumento lo dice.
        self::assertSame(1, (int)$this->pdo->query('SELECT COUNT(*) FROM ' . self::TABELLA)->fetchColumn());
    }

    #[Test]
    public function senza_errori_esce_con_0_e_stderr_resta_vuoto(): void
    {
        [$esito, $out, $err] = $this->lancia();

        self::assertSame(0, $esito, "esito; stderr: $err");
        self::assertSame('', trim($err));
        self::assertStringContainsString('✓ ' . self::BUONA, $out);
        self::assertSame([self::BUONA], $this->registrate());
    }

    /** @return array{0:int, 1:string, 2:string} */
    private function lancia(): array
    {
        $comando = [PHP_BINARY, dirname(__DIR__, 2) . '/tools/migrate.php', '--cartella=' . $this->cartella];
        // Un ambiente minimo, non quello del processo delle prove: un'altra prova
        // può aver lasciato DB_ENABLED=false (misurato nella suite intera, in un
        // ordine casuale), e il figlio lo erediterebbe. Lo strumento legge .env
        // e .env.local da sé, come quando lo lancia il rilascio.
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
        $stmt = $this->pdo->prepare('SELECT filename FROM schema_migrations WHERE filename IN (?, ?) ORDER BY filename');
        $stmt->execute([self::BUONA, self::ROTTA]);
        return $stmt->fetchAll(PDO::FETCH_COLUMN);
    }

    private function pulisci(): void
    {
        $this->pdo->exec('DROP TABLE IF EXISTS ' . self::TABELLA);
        try {
            $this->pdo->prepare('DELETE FROM schema_migrations WHERE filename IN (?, ?)')->execute([self::BUONA, self::ROTTA]);
        } catch (\PDOException) {
            // la tabella di tracciamento non c'è ancora: niente da togliere
        }
    }
}
