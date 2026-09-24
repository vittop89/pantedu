<?php

declare(strict_types=1);

namespace Tests\Unit\Controllers;

use App\Controllers\TeacherSyncCleanupController;
use App\Core\Config;
use App\Core\Database;
use App\Core\Request;
use PDO;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

/**
 * La pulizia delle righe «orfane» (blob mancante su disco).
 *
 * Il 10 settembre 2026 questo controllore cercava i blob nella cartella del
 * repository invece che in `app.paths.storage`. In produzione i dati stanno
 * fuori dal repository, quindi OGNI documento cifrato risultava orfano: con la
 * conferma si sarebbero cancellate le righe di 25 verifiche e 249 mappe. Queste
 * prove fissano le due cose che servono perché non ricapiti:
 *
 *   - i blob si cercano dove li scrive EncryptedBlobStore;
 *   - una pulizia che trova orfano TUTTO non cancella niente, perché quello
 *     non è un orfano: è una cartella che non si trova.
 *
 * Database SQLite in memoria con le due viste che il controllore interroga,
 * sopra le tabelle da cui cancella — come in MariaDB.
 */
final class TeacherSyncCleanupControllerTest extends TestCase
{
    private const DOCENTE = 7;

    private ?PDO $pdo = null;
    private string $cartella = '';
    private mixed $dbPrima = null;
    private mixed $storagePrima = null;

    protected function setUp(): void
    {
        if (!\in_array('sqlite', PDO::getAvailableDrivers(), true)) {
            $this->markTestSkipped('pdo_sqlite non disponibile in questo runtime');
        }

        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $this->pdo->exec(
            'CREATE TABLE verifica_documents_data (
                id INTEGER PRIMARY KEY, teacher_id INTEGER, title TEXT, variant TEXT,
                tex_blob_path TEXT, tex_files TEXT, pdf_blob_path TEXT
            );
            CREATE VIEW verifica_documents AS SELECT * FROM verifica_documents_data;
            CREATE TABLE teacher_content_data (
                id INTEGER PRIMARY KEY, teacher_id INTEGER, title TEXT,
                content_type TEXT, map_blob_path TEXT
            );
            CREATE VIEW teacher_content AS SELECT * FROM teacher_content_data;'
        );
        (new ReflectionProperty(Database::class, 'pdo'))->setValue(null, $this->pdo);

        $this->dbPrima = Config::get('database.enabled');
        Config::set('database.enabled', true);

        $this->cartella = sys_get_temp_dir() . '/pantedu-pulizia-' . bin2hex(random_bytes(6));
        mkdir($this->cartella . '/maps_enc/' . self::DOCENTE, 0o777, true);
        mkdir($this->cartella . '/verifiche_enc/' . self::DOCENTE, 0o777, true);
        $this->storagePrima = Config::get('app.paths.storage');
        Config::set('app.paths.storage', $this->cartella);

        $_SESSION = [
            'autenticato' => true,
            'user_id'     => self::DOCENTE,
            'username'    => 'docente.di.prova',
            'user_role'   => 'teacher',
        ];
    }

    protected function tearDown(): void
    {
        Database::reset();
        $this->pdo = null;
        Config::set('database.enabled', $this->dbPrima);
        Config::set('app.paths.storage', $this->storagePrima);
        $_SESSION = [];
        $this->cancella($this->cartella);
    }

    #[Test]
    public function un_blob_nella_cartella_dei_dati_non_e_orfano(): void
    {
        // Il caso che il 10/9/2026 falliva: il file c'è, ma nella cartella dei
        // dati e non in quella del repository.
        $this->mappa(1, 'Frazioni', '7/a.bin', conFile: true);

        $esito = $this->pulisci(conferma: false);

        self::assertSame(200, $esito['stato']);
        self::assertSame([], $esito['corpo']['orphan_mappe'], 'il blob c\'è: non è orfano');
        self::assertArrayNotHasKey('sospetto', $esito['corpo']);
    }

    #[Test]
    public function se_manca_tutto_non_cancella_niente(): void
    {
        foreach ([1, 2, 3] as $id) {
            $this->mappa($id, "Mappa {$id}", "7/{$id}.bin", conFile: false);
        }

        $esito = $this->pulisci(conferma: true);

        self::assertSame(409, $esito['stato'], 'tutto orfano = configurazione, non pulizia');
        self::assertSame('tutti_orfani', $esito['corpo']['error']);
        self::assertSame(0, $esito['corpo']['deleted_mappe']);
        self::assertSame(3, $this->righe('teacher_content_data'), 'le righe sono ancora tutte lì');
    }

    #[Test]
    public function un_orfano_vero_fra_documenti_sani_si_cancella(): void
    {
        $this->mappa(1, 'Sana', '7/1.bin', conFile: true);
        $this->mappa(2, 'Sana anche lei', '7/2.bin', conFile: true);
        $this->mappa(3, 'Orfana davvero', '7/3.bin', conFile: false);

        $esito = $this->pulisci(conferma: true);

        self::assertSame(200, $esito['stato']);
        self::assertSame(1, $esito['corpo']['deleted_mappe'], 'solo quella senza file');
        self::assertSame(2, $this->righe('teacher_content_data'));
    }

    #[Test]
    public function con_la_cartella_assente_non_cancella_neanche_un_documento_solo(): void
    {
        // Sotto la soglia del «tutti orfani», ma la cartella non esiste proprio:
        // anche un documento solo non si tocca.
        $this->cancella($this->cartella . '/maps_enc');
        $this->mappa(1, 'Unica', '7/1.bin', conFile: false);

        $esito = $this->pulisci(conferma: true);

        self::assertSame(409, $esito['stato']);
        self::assertSame('cartella_blob_assente', $esito['corpo']['error']);
        self::assertSame(1, $this->righe('teacher_content_data'));
    }

    /** @return array{stato: int, corpo: array<string, mixed>} */
    private function pulisci(bool $conferma): array
    {
        $risposta = (new TeacherSyncCleanupController())
            ->cleanupOrphans(new Request((string)json_encode(['confirm' => $conferma])));

        /** @var array<string, mixed> $corpo */
        $corpo = json_decode($risposta->body, true);

        return ['stato' => $risposta->status, 'corpo' => $corpo];
    }

    private function mappa(int $id, string $titolo, string $blob, bool $conFile): void
    {
        $this->pdo?->prepare(
            'INSERT INTO teacher_content_data (id, teacher_id, title, content_type, map_blob_path)
             VALUES (?, ?, ?, ?, ?)'
        )->execute([$id, self::DOCENTE, $titolo, 'mappa', $blob]);

        if ($conFile) {
            file_put_contents($this->cartella . '/maps_enc/' . $blob, 'blob cifrato di prova');
        }
    }

    private function righe(string $tabella): int
    {
        return (int)$this->pdo?->query("SELECT COUNT(*) FROM {$tabella}")->fetchColumn();
    }

    private function cancella(string $percorso): void
    {
        if (!is_dir($percorso)) {
            @unlink($percorso);
            return;
        }
        foreach (scandir($percorso) ?: [] as $voce) {
            if ($voce !== '.' && $voce !== '..') {
                $this->cancella($percorso . '/' . $voce);
            }
        }
        @rmdir($percorso);
    }
}
