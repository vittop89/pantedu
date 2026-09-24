<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Controllers\MapsController;
use App\Core\Config;
use App\Core\Database;
use App\Core\Request;
use App\Services\Crypto\TeacherCryptoService;
use App\Services\Maps\MapBlobStore;
use App\Services\Maps\SalvataggioMappa;
use PDO;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Il salvataggio di una mappa con il controllo di versione (19/9/2026, tolto
 * da MapsController::update perché lo usa anche lo strumento dei caratteri
 * persi).
 *
 * Database vero, cifratura vera con una chiave maestra di prova, blob in una
 * cartella temporanea, tutto in transazione → rollback. Nei due versi: con la
 * versione giusta si scrive e la versione sale di uno; con quella sbagliata,
 * con la riga di un altro o con la riga occupata da un altro salvataggio il
 * file resta com'era.
 */
final class SalvataggioMappaTest extends TestCase
{
    private const PRIMA = '<mxfile host="prova"><diagram id="d" name="P">prima</diagram></mxfile>';
    private const DOPO  = '<mxfile host="prova"><diagram id="d" name="P">città, più, perché</diagram></mxfile>';

    private PDO $pdo;
    private int $docente = 0;
    private MapBlobStore $blob;
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
        Config::set('crypto.allow_regenerate', false);
        try {
            $this->pdo = Database::connection();
            $this->pdo->query('SELECT 1');
        } catch (\Throwable $e) {
            $this->markTestSkipped('DB non disponibile: ' . $e->getMessage());
        }
        $this->pdo->beginTransaction();
        $nome = 'zzsalvamappa' . bin2hex(random_bytes(3));
        $this->pdo->prepare(
            'INSERT INTO users (username, role, first_name, last_name, email, password_hash, status, active, created_at)
             VALUES (?, "teacher", "Zz", "Salva", ?, "x", "approved", 1, NOW())'
        )->execute([$nome, "$nome@example.invalid"]);
        $this->docente = (int)$this->pdo->lastInsertId();
        $_SESSION = ['autenticato' => true, 'username' => $nome, 'user_id' => $this->docente, 'user_role' => 'teacher', 'is_super_admin' => false];

        $crypto = new TeacherCryptoService(bin2hex(random_bytes(32)));
        $crypto->encrypt($this->docente, 'chiave pronta');
        $this->cartella = sys_get_temp_dir() . '/pantedu-salvamappa-' . bin2hex(random_bytes(6));
        $this->blob = new MapBlobStore($crypto, $this->cartella);
    }

    protected function tearDown(): void
    {
        $_SESSION = [];
        $_POST = [];
        if (isset($this->pdo) && $this->pdo->inTransaction()) {
            $this->pdo->rollBack();
        }
        if ($this->cartella !== '' && is_dir($this->cartella)) {
            array_map('unlink', glob($this->cartella . '/*/*') ?: []);
            array_map('rmdir', glob($this->cartella . '/*', GLOB_ONLYDIR) ?: []);
            rmdir($this->cartella);
        }
    }

    private function mappa(int $versione = 2, ?string $percorso = null): int
    {
        $percorso ??= $this->blob->put($this->docente, self::PRIMA);
        $this->pdo->prepare(
            'INSERT INTO teacher_content_data (teacher_id, content_subtype, title, map_blob_path, map_mime, map_size, map_origin, map_version, updated_at)
             VALUES (?, "mappa", "Mappa di prova", ?, "application/xml", ?, "upload", ?, NOW() - INTERVAL 1 HOUR)'
        )->execute([$this->docente, $percorso, strlen(self::PRIMA), $versione]);
        return (int)$this->pdo->lastInsertId();
    }

    /** @return array{map_blob_path:string, map_version:int, map_size:int, recente:int} */
    private function riga(int $id): array
    {
        $st = $this->pdo->prepare(
            'SELECT map_blob_path, map_version, map_size, (updated_at > NOW() - INTERVAL 5 MINUTE) AS recente
             FROM teacher_content_data WHERE id = ?'
        );
        $st->execute([$id]);
        $r = $st->fetch(PDO::FETCH_ASSOC);
        return [
            'map_blob_path' => (string)$r['map_blob_path'], 'map_version' => (int)$r['map_version'],
            'map_size' => (int)$r['map_size'], 'recente' => (int)$r['recente'],
        ];
    }

    #[Test]
    public function con_la_versione_giusta_scrive_lo_stesso_file_e_alza_la_versione(): void
    {
        $id = $this->mappa(2);
        $prima = $this->riga($id);

        $esito = (new SalvataggioMappa($this->pdo, $this->blob))->sovrascrivi($id, $this->docente, self::DOPO, 2);

        self::assertSame(['esito' => SalvataggioMappa::SALVATA, 'versione' => 3, 'byte' => strlen(self::DOPO)], $esito);
        $dopo = $this->riga($id);
        self::assertSame($prima['map_blob_path'], $dopo['map_blob_path'], 'lo stesso file, non uno nuovo');
        self::assertSame(3, $dopo['map_version']);
        self::assertSame(strlen(self::DOPO), $dopo['map_size']);
        self::assertSame(1, $dopo['recente'], 'updated_at è adesso');
        self::assertSame(self::DOPO, $this->blob->get($this->docente, $dopo['map_blob_path']));
    }

    #[Test]
    public function con_la_versione_cambiata_non_scrive_niente(): void
    {
        $id = $this->mappa(2);
        $prima = $this->riga($id);

        $esito = (new SalvataggioMappa($this->pdo, $this->blob))->sovrascrivi($id, $this->docente, self::DOPO, 1);

        self::assertSame(['esito' => SalvataggioMappa::CONFLITTO, 'versione_server' => 2], $esito);
        self::assertSame($prima, $this->riga($id));
        self::assertSame(self::PRIMA, $this->blob->get($this->docente, $prima['map_blob_path']), 'il file è quello di prima');
    }

    #[Test]
    public function la_mappa_di_un_altro_docente_non_si_trova(): void
    {
        $id = $this->mappa(2);
        $prima = $this->riga($id);

        $esito = (new SalvataggioMappa($this->pdo, $this->blob))->sovrascrivi($id, $this->docente + 100000, self::DOPO, 2);

        self::assertSame(['esito' => SalvataggioMappa::NON_TROVATA], $esito);
        self::assertSame($prima, $this->riga($id));
    }

    #[Test]
    public function un_percorso_nella_cartella_di_un_altro_docente_non_si_scrive(): void
    {
        $id = $this->mappa(2, ($this->docente + 1) . '/01KQD0QRAGBE09DZ8VV2DN0PF8.bin');

        $esito = (new SalvataggioMappa($this->pdo, $this->blob))->sovrascrivi($id, $this->docente, self::DOPO, 2);

        self::assertSame(['esito' => SalvataggioMappa::PERCORSO_NON_VALIDO], $esito);
        self::assertSame(2, $this->riga($id)['map_version']);
        self::assertFileDoesNotExist($this->cartella . '/' . $this->docente . '/01KQD0QRAGBE09DZ8VV2DN0PF8.bin', 'nessun file scritto dove la riga non guarda');
    }

    /**
     * La gara vera: un altro salvataggio tiene la riga (qui la transazione del
     * test, che l'ha appena scritta) e questo arriva da una seconda connessione.
     * Deve aspettare la riga **prima** di toccare il file: scaduta l'attesa
     * esce con un errore, e il file è ancora quello di prima.
     *
     * Fino al 19/9 il file si scriveva prima del controllo, e chi perdeva la
     * gara sovrascriveva il file di chi l'aveva vinta.
     */
    #[Test]
    public function con_la_riga_occupata_da_un_altro_salvataggio_il_file_non_si_tocca(): void
    {
        $id = $this->mappa(2);
        $percorso = $this->riga($id)['map_blob_path'];
        $seconda = $this->secondaConnessione();
        $seconda->exec('SET SESSION innodb_lock_wait_timeout = 1');

        $errore = null;
        try {
            (new SalvataggioMappa($seconda, $this->blob))->sovrascrivi($id, $this->docente, self::DOPO, 2);
        } catch (\PDOException $e) {
            $errore = $e;
        }

        self::assertNotNull($errore, 'la seconda connessione ha dovuto aspettare la riga');
        self::assertStringContainsString('1205', (string)$errore->getMessage(), 'attesa del blocco scaduta');
        self::assertFalse($seconda->inTransaction(), 'la sua transazione è tornata indietro');
        self::assertSame(self::PRIMA, $this->blob->get($this->docente, $percorso), 'il file è quello di prima');
    }

    #[Test]
    public function l_editor_riceve_200_con_la_versione_nuova_e_409_con_una_vecchia(): void
    {
        $id = $this->mappa(4);
        $controller = new MapsController(null, $this->blob);

        $_POST = ['xml' => self::DOPO, 'map_version' => '4'];
        $risposta = $controller->update(new Request(), ['id' => (string)$id]);
        self::assertSame(200, $risposta->status);
        self::assertSame(['ok' => true, 'id' => $id, 'size' => strlen(self::DOPO), 'map_version' => 5], json_decode((string)$risposta->body, true));

        // Lo stesso editor salva di nuovo con la versione che aveva: 409.
        $risposta = $controller->update(new Request(), ['id' => (string)$id]);
        self::assertSame(409, $risposta->status);
        self::assertSame(['error' => 'version_conflict', 'server_version' => 5], json_decode((string)$risposta->body, true));
        self::assertSame(5, $this->riga($id)['map_version']);
    }

    private function secondaConnessione(): PDO
    {
        $socket = (string)(Config::get('database.socket') ?? '');
        $dsn = $socket !== ''
            ? sprintf('%s:unix_socket=%s;dbname=%s;charset=%s', Config::get('database.driver'), $socket, Config::get('database.name'), Config::get('database.charset'))
            : sprintf(
                '%s:host=%s;port=%s;dbname=%s;charset=%s',
                Config::get('database.driver'),
                Config::get('database.host'),
                Config::get('database.port'),
                Config::get('database.name'),
                Config::get('database.charset'),
            );
        return new PDO($dsn, (string)Config::get('database.user'), (string)Config::get('database.pass'), [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        ]);
    }
}
