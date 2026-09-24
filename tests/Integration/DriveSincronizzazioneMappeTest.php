<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Core\Config;
use App\Core\Database;
use App\Repositories\DriveOAuthRepository;
use App\Services\Crypto\TeacherCryptoService;
use App\Services\Drive\DriveClient;
use App\Services\Drive\FolderTreeBuilder;
use App\Services\Drive\MapSyncService;
use App\Services\Maps\MapBlobStore;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Psr7\Response;
use PDO;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;

/**
 * La sincronizzazione delle mappe verso Drive, sul database vero, con un Google
 * simulato che risponde per percorso e tiene le richieste (15/9/2026).
 *
 * Tre cose misurate in produzione quel giorno, ognuna nei due versi:
 *   - una mappa senza tipo registrato andava su Drive senza estensione, come
 *     dati binari: il tipo si riconosce dal contenuto, e un tipo registrato
 *     non si tocca;
 *   - le mappe senza file risultavano errori, e il segnaposto che le esclude
 *     non si scriveva mai: adesso sono orfane, il segnaposto va solo dove non
 *     c'è un identificativo Drive, e quello vero resta;
 *   - la cartella radice spostata dal docente non si ritrovava dopo un nuovo
 *     collegamento: si cerca per marcatore, dovunque sia, e per nome solo se
 *     il marcatore non la trova.
 */
final class DriveSincronizzazioneMappeTest extends TestCase
{
    private const DIAGRAMMA = '<mxfile host="prova"><diagram id="d" name="Pagina">abc</diagram></mxfile>';

    private PDO $pdo;
    private int $docente = 0;
    private DriveOAuthRepository $repo;
    private MapBlobStore $blob;
    private string $cartella = '';
    /** @var array<string, mixed> */
    private array $drivePrima = [];
    /** @var list<RequestInterface> */
    private array $richieste = [];
    /** @var list<array{id:string}> le cartelle che la ricerca per marcatore trova */
    private array $radiciMarcate = [];
    /** @var list<array{id:string}> le cartelle che la ricerca per nome in «Il mio Drive» trova */
    private array $radiciPerNome = [];

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
        $this->pdo->beginTransaction();

        $this->pdo->prepare(
            'INSERT INTO users (username, role, first_name, last_name, email, password_hash, status, active, created_at)
             VALUES ("zzsyncmappe", "teacher", "Zz", "Sync", "zzsyncmappe@example.invalid", "x", "approved", 1, NOW())'
        )->execute();
        $this->docente = (int)$this->pdo->lastInsertId();

        $drive = Config::get('drive', []);
        $this->drivePrima = is_array($drive) ? $drive : [];
        Config::set('drive', array_replace($this->drivePrima, [
            'enabled' => true,
            'oauth'   => ['client_id' => 'id-di-prova', 'client_secret' => 'segreto-di-prova', 'redirect_uri' => 'https://example.invalid/teacher/drive/callback'],
        ]));

        $chiave = bin2hex(random_bytes(32));
        $this->repo = new DriveOAuthRepository(new TeacherCryptoService($chiave));
        $this->repo->upsert($this->docente, 'token-di-prova', 'openid email https://www.googleapis.com/auth/drive.file', 'zzsyncmappe@example.invalid');
        $this->cartella = sys_get_temp_dir() . '/pantedu-syncmappe-' . bin2hex(random_bytes(6));
        $this->blob = new MapBlobStore(new TeacherCryptoService($chiave), $this->cartella);
    }

    protected function tearDown(): void
    {
        Config::set('drive', $this->drivePrima);
        if (isset($this->pdo) && $this->pdo->inTransaction()) {
            $this->pdo->rollBack();
        }
        if ($this->cartella !== '' && is_dir($this->cartella)) {
            $voci = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($this->cartella, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::CHILD_FIRST,
            );
            foreach ($voci as $voce) {
                $voce->isDir() ? rmdir($voce->getPathname()) : unlink($voce->getPathname());
            }
            rmdir($this->cartella);
        }
    }

    /** Un Google che risponde per percorso: token, elenchi, cartelle e file creati. */
    private function client(): DriveClient
    {
        $numero = 0;
        $google = function (RequestInterface $richiesta) use (&$numero) {
            $this->richieste[] = $richiesta;
            $percorso = $richiesta->getUri()->getPath();
            $json = static fn(int $codice, array $corpo) => Create::promiseFor(
                new Response($codice, ['Content-Type' => 'application/json; charset=utf-8'], (string)json_encode($corpo))
            );
            if (str_ends_with($percorso, '/token')) {
                return $json(200, ['access_token' => 'ya29.prova', 'expires_in' => 3599, 'token_type' => 'Bearer',
                    'scope' => 'openid email https://www.googleapis.com/auth/drive.file']);
            }
            if ($richiesta->getMethod() === 'GET' && $percorso === '/drive/v3/files') {
                parse_str($richiesta->getUri()->getQuery(), $parametri);
                $q = (string)($parametri['q'] ?? '');
                return $json(200, ['files' => match (true) {
                    str_contains($q, 'appProperties has')     => $this->radiciMarcate,
                    str_contains($q, "'root' in parents")      => $this->radiciPerNome,
                    default                                    => [],
                }]);
            }
            if ($richiesta->getMethod() === 'GET' && str_starts_with($percorso, '/drive/v3/files/')) {
                return $json(404, ['error' => ['code' => 404, 'message' => 'File not found']]);
            }
            if ($richiesta->getMethod() === 'POST') {
                $numero++;
                return $json(200, ['id' => "creato-$numero"]);
            }
            return $json(500, ['error' => ['code' => 500, 'message' => "richiesta non prevista: $percorso"]]);
        };
        return new DriveClient($this->repo, HandlerStack::create($google));
    }

    private function servizio(): MapSyncService
    {
        return new MapSyncService($this->client(), $this->repo, $this->blob);
    }

    private function mappa(string $titolo, ?string $percorso, ?string $tipo = null, ?string $idDrive = null): int
    {
        $this->pdo->prepare(
            'INSERT INTO teacher_content_data (teacher_id, content_subtype, title, map_blob_path, map_mime, map_drive_id, map_origin)
             VALUES (?, "mappa", ?, ?, ?, ?, "upload")'
        )->execute([$this->docente, $titolo, $percorso, $tipo, $idDrive]);
        return (int)$this->pdo->lastInsertId();
    }

    private function radiceGiaNota(): void
    {
        $this->pdo->prepare('UPDATE teacher_drive_oauth SET drive_root_id = "radice-nota" WHERE teacher_id = ?')->execute([$this->docente]);
    }

    /** @return array<string, mixed> */
    private function riga(int $id): array
    {
        $st = $this->pdo->prepare('SELECT map_mime, map_drive_id FROM teacher_content_data WHERE id = ?');
        $st->execute([$id]);
        return $st->fetch(PDO::FETCH_ASSOC) ?: [];
    }

    /** Il corpo del caricamento del file (la richiesta multipart verso /upload). */
    private function caricamento(): string
    {
        foreach ($this->richieste as $r) {
            if (str_starts_with($r->getUri()->getPath(), '/upload/drive/v3/files')) {
                return (string)$r->getBody();
            }
        }
        $this->fail('nessun file caricato');
    }

    #[Test]
    public function il_tipo_si_riconosce_dal_contenuto_e_il_file_arriva_come_drawio(): void
    {
        $this->radiceGiaNota();
        $id = $this->mappa('6.1_Specchi', $this->blob->put($this->docente, self::DIAGRAMMA));

        $esito = $this->servizio()->syncAllForTeacher($this->docente);

        $this->assertSame(1, $esito['ok'], json_encode($esito) ?: '');
        $corpo = $this->caricamento();
        $this->assertStringContainsString('"name":"6.1_Specchi.drawio"', $corpo);
        $this->assertStringContainsString('Content-Type: application/xml', $corpo);
        $this->assertSame('application/xml', $this->riga($id)['map_mime'], 'e il tipo si registra');
    }

    #[Test]
    public function un_tipo_gia_registrato_non_si_tocca(): void
    {
        $this->radiceGiaNota();
        $id = $this->mappa('Schema', $this->blob->put($this->docente, self::DIAGRAMMA), 'image/png');

        $this->servizio()->syncAllForTeacher($this->docente);

        $this->assertStringContainsString('"name":"Schema.png"', $this->caricamento());
        $this->assertSame('image/png', $this->riga($id)['map_mime']);
    }

    #[Test]
    public function una_mappa_senza_file_e_orfana_e_tiene_il_suo_identificativo_drive(): void
    {
        $this->radiceGiaNota();
        $conId = $this->mappa('Funzioni', $this->docente . '/01KQD0QRAGBE09DZ8VV2DN0PF8.bin', 'application/xml', '1pUbPNAVNGRDmTnLG1hKIZRdvkNttTode');
        $senzaId = $this->mappa('Mai caricata', $this->docente . '/01KQD0QS7J6M2RT869E28Z6FB1.bin');

        $esito = $this->servizio()->syncAllForTeacher($this->docente);

        $this->assertSame(2, $esito['orphan'], json_encode($esito) ?: '');
        $this->assertSame(0, $esito['error'], 'non sono errori del giro');
        $this->assertSame('1pUbPNAVNGRDmTnLG1hKIZRdvkNttTode', $this->riga($conId)['map_drive_id'], "l'unico riferimento alla copia resta");
        $this->assertSame('blob_orphan', $this->riga($senzaId)['map_drive_id'], 'il segnaposto va dove non c\'era niente');
    }

    #[Test]
    public function solo_le_cambiate_prende_le_nuove_e_le_modificate_non_quelle_gia_su_drive(): void
    {
        $this->radiceGiaNota();
        $this->pdo->prepare('UPDATE teacher_drive_oauth SET last_sync_at = NOW() - INTERVAL 1 DAY WHERE teacher_id = ?')->execute([$this->docente]);
        $inserisci = function (string $titolo, ?string $idDrive, string $quando): int {
            $this->pdo->prepare(
                "INSERT INTO teacher_content_data (teacher_id, content_subtype, title, map_blob_path, map_mime, map_drive_id, map_origin, updated_at)
                 VALUES (?, 'mappa', ?, ?, 'application/xml', ?, 'upload', NOW() - INTERVAL $quando)"
            )->execute([$this->docente, $titolo, $this->blob->put($this->docente, self::DIAGRAMMA), $idDrive]);
            return (int)$this->pdo->lastInsertId();
        };
        $giaSuDrive = $inserisci('Già su Drive', 'id-su-drive-di-prima', '2 DAY');
        $nuova = $inserisci('Nuova', null, '2 DAY');
        $cambiata = $inserisci('Cambiata', 'id-su-drive-cambiata', '1 HOUR');

        $soloCambiate = $this->servizio()->syncAllForTeacher($this->docente, null, true);
        $ids = array_column($soloCambiate['items'], 'id');
        sort($ids);
        $attesi = [$nuova, $cambiata];
        sort($attesi);
        $this->assertSame($attesi, $ids, 'il giro notturno carica solo queste');
        $this->assertNotContains($giaSuDrive, $ids);

        // Controprova: senza la scelta ci sono tutte.
        $this->pdo->prepare('UPDATE teacher_drive_oauth SET last_sync_at = NOW() - INTERVAL 1 DAY WHERE teacher_id = ?')->execute([$this->docente]);
        $tutte = $this->servizio()->syncAllForTeacher($this->docente);
        $this->assertContains($giaSuDrive, array_column($tutte['items'], 'id'));
    }

    #[Test]
    public function la_radice_spostata_si_ritrova_per_marcatore_senza_cercarla_per_nome(): void
    {
        $this->radiciMarcate = [['id' => 'radice-spostata']];
        $this->radiciPerNome = [['id' => 'radice-per-nome']];
        $drive = $this->client()->getDriveFor($this->docente);

        $radice = (new FolderTreeBuilder($drive, $this->docente))->resolve('');

        $this->assertSame('radice-spostata', $radice);
        $this->assertSame('radice-spostata', $this->repo->getMetadata($this->docente)['drive_root_id'] ?? null);
        $perNome = array_filter($this->richieste, static fn(RequestInterface $r) => str_contains(urldecode($r->getUri()->getQuery()), "'root' in parents"));
        $this->assertSame([], array_values($perNome), 'trovata per marcatore, il nome non serve');
    }

    #[Test]
    public function senza_marcatore_la_radice_si_cerca_per_nome_in_il_mio_drive(): void
    {
        $this->radiciMarcate = [];
        $this->radiciPerNome = [['id' => 'radice-per-nome']];
        $drive = $this->client()->getDriveFor($this->docente);

        $radice = (new FolderTreeBuilder($drive, $this->docente))->resolve('');

        $this->assertSame('radice-per-nome', $radice);
        $creazioni = array_filter($this->richieste, static fn(RequestInterface $r) => $r->getMethod() === 'POST' && !str_ends_with($r->getUri()->getPath(), '/token'));
        $this->assertSame([], array_values($creazioni), 'trovata, non se ne crea un\'altra');
    }
}
