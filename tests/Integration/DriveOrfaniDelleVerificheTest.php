<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Core\Config;
use App\Core\Database;
use App\Repositories\DriveOAuthRepository;
use App\Services\Crypto\TeacherCryptoService;
use App\Services\Drive\DriveClient;
use App\Services\Drive\VerificaSyncService;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Psr7\Response;
use PDO;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;

/**
 * La pulizia degli orfani su Drive tocca solo le cartelle `verifiche/` del
 * docente (23/9/2026, revisione architetturale A-83).
 *
 * Il docblock di `deleteOrphansFromDrive` prometteva «i file presenti nelle
 * cartelle `verifiche/` del docente»; la query chiedeva invece a Drive ogni
 * `.tex` creato dall'app per quell'account Google, e cancellava tutti quelli
 * che la tabella del docente non conosceva. Con lo scope `drive.file` di
 * solito coincide, ma un account Google collegato a due docenti perdeva le
 * verifiche dell'altro, e un `.tex` dell'app fuori da `verifiche/` spariva
 * comunque. Sul codice di prima questa prova falliva: sei file cancellati su
 * sette, cioè tutti tranne quello che il database conosce, compreso quello
 * del collega (ora i file sono nove: si aggiungono due `.tex` in cartelle
 * che hanno «verifiche» nel percorso ma non come sezione, e che un filtro
 * «c'è verifiche da qualche parte» cancellerebbe).
 *
 * Il database è quello vero, dentro una transazione annullata alla fine;
 * Google è simulato e risponde per percorso, e tiene le cancellazioni.
 */
final class DriveOrfaniDelleVerificheTest extends TestCase
{
    private const RADICE = 'ZZORF/SCI/1/MAT';

    private PDO $pdo;
    private int $docente = 0;
    private int $collega = 0;
    private DriveOAuthRepository $repo;
    /** @var array<string, mixed> */
    private array $drivePrima = [];
    /** @var list<string> */
    private array $cancellati = [];
    /** @var list<array<string, mixed>> quello che l'elenco dei `.tex` restituisce */
    private array $suDrive = [];

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
            $this->pdo->query('SELECT drive_file_id FROM verifica_documents_data LIMIT 1');
        } catch (\Throwable $e) {
            $this->markTestSkipped('DB non disponibile: ' . $e->getMessage());
        }
        $this->pdo->beginTransaction();

        $marca = date('YmdHis') . bin2hex(random_bytes(2));
        $this->docente = $this->utente('zzorfani_' . $marca);
        $this->collega = $this->utente('zzorfani_collega_' . $marca);

        $drive = Config::get('drive', []);
        $this->drivePrima = is_array($drive) ? $drive : [];
        Config::set('drive', array_replace($this->drivePrima, [
            'enabled' => true,
            'oauth'   => [
                'client_id'     => 'id-di-prova',
                'client_secret' => 'segreto-di-prova',
                'redirect_uri'  => 'https://example.invalid/teacher/drive/callback',
            ],
        ]));
        $this->repo = new DriveOAuthRepository(new TeacherCryptoService(bin2hex(random_bytes(32))));
        $this->repo->upsert(
            $this->docente,
            'token-di-prova',
            'openid email https://www.googleapis.com/auth/drive.file',
            'zzorfani@example.invalid'
        );

        // Le cartelle come le lascia FolderTreeBuilder: un percorso per riga.
        $this->cartella($this->docente, self::RADICE, 'cart-materia');
        $this->cartella($this->docente, self::RADICE . '/verifiche', 'cart-verifiche');
        $this->cartella($this->docente, self::RADICE . '/verifiche/Titolo', 'cart-titolo');
        $this->cartella($this->docente, self::RADICE . '/verifiche/Titolo/v0-01_09_2026-NOR', 'cart-versione');
        $this->cartella($this->docente, self::RADICE . '/mappe', 'cart-mappe');
        // «verifiche» in un altro punto del percorso: una cartella delle mappe
        // che si chiama così, e una materia che si chiama così. Non sono la
        // sezione `verifiche/` (il quinto segmento), e i loro file restano.
        $this->cartella($this->docente, self::RADICE . '/mappe/verifiche', 'cart-mappe-verifiche');
        $this->cartella($this->docente, 'ZZORF/SCI/1/verifiche', 'cart-materia-verifiche');
        $this->cartella($this->docente, 'ZZORF/SCI/1/verifiche/mappe', 'cart-materia-verifiche-mappe');
        $this->cartella($this->collega, self::RADICE . '/verifiche/Altro/v1-02_09_2026-NOR', 'cart-altrui');

        // L'unica verifica del docente che vive su Drive (senza file locale:
        // la sincronizzazione non la tocca, conta solo per la pulizia).
        $this->pdo->prepare(
            'INSERT INTO verifica_documents_data (teacher_id, title, variant, drive_file_id)
             VALUES (?, ?, "A_NOR", "file-vivo")'
        )->execute([$this->docente, 'Viva ' . $marca]);
    }

    protected function tearDown(): void
    {
        Config::set('drive', $this->drivePrima);
        if (isset($this->pdo) && $this->pdo->inTransaction()) {
            $this->pdo->rollBack();
        }
    }

    private function utente(string $nome): int
    {
        $this->pdo->prepare(
            'INSERT INTO users (username, role, first_name, last_name, email, password_hash, status, active)
             VALUES (?, "teacher", "Zz", "Orfani", ?, "x", "approved", 1)'
        )->execute([$nome, $nome . '@example.invalid']);
        return (int)$this->pdo->lastInsertId();
    }

    private function cartella(int $docente, string $percorso, string $id): void
    {
        $this->pdo->prepare(
            'INSERT INTO teacher_drive_folder_cache (teacher_id, folder_path, drive_folder_id) VALUES (?, ?, ?)'
        )->execute([$docente, $percorso, $id]);
    }

    /** Un Google che risponde per percorso: token, elenco dei `.tex`, cancellazioni. */
    private function servizio(): VerificaSyncService
    {
        $google = function (RequestInterface $richiesta) {
            $percorso = $richiesta->getUri()->getPath();
            $json = static fn(int $codice, array $corpo) => Create::promiseFor(
                new Response(
                    $codice,
                    ['Content-Type' => 'application/json; charset=utf-8'],
                    (string)json_encode($corpo)
                )
            );
            if (str_ends_with($percorso, '/token')) {
                return $json(200, ['access_token' => 'ya29.prova', 'expires_in' => 3599, 'token_type' => 'Bearer',
                    'scope' => 'openid email https://www.googleapis.com/auth/drive.file']);
            }
            if ($richiesta->getMethod() === 'GET' && $percorso === '/drive/v3/files') {
                parse_str($richiesta->getUri()->getQuery(), $parametri);
                $q = (string)($parametri['q'] ?? '');
                return $json(200, ['files' => str_contains($q, 'application/x-tex') ? $this->suDrive : []]);
            }
            if ($richiesta->getMethod() === 'DELETE' && str_starts_with($percorso, '/drive/v3/files/')) {
                $this->cancellati[] = substr($percorso, strlen('/drive/v3/files/'));
                return Create::promiseFor(new Response(204));
            }
            return $json(500, ['error' => ['code' => 500, 'message' => "richiesta non prevista: $percorso"]]);
        };
        return new VerificaSyncService(new DriveClient($this->repo, HandlerStack::create($google)), $this->repo);
    }

    /** @param list<string> $genitori */
    private function suDrive(string $id, array $genitori): void
    {
        $file = ['id' => $id, 'name' => $id . '.tex', 'mimeType' => 'application/x-tex'];
        if ($genitori !== []) {
            $file['parents'] = $genitori;
        }
        $this->suDrive[] = $file;
    }

    #[Test]
    public function si_cancellano_solo_gli_orfani_nelle_cartelle_verifiche_del_docente(): void
    {
        $this->suDrive('file-vivo', ['cart-versione']);          // nel database: resta
        $this->suDrive('file-orfano', ['cart-versione']);        // orfano nella sua cartella: va
        $this->suDrive('file-orfano-titolo', ['cart-titolo']);   // orfano sotto verifiche/: va
        $this->suDrive('file-del-collega', ['cart-altrui']);     // verifiche/ di un altro docente
        $this->suDrive('file-nelle-mappe', ['cart-mappe']);      // .tex dell'app fuori da verifiche/
        $this->suDrive('file-in-mappe-verifiche', ['cart-mappe-verifiche']);            // «verifiche» al sesto segmento
        $this->suDrive('file-in-materia-verifiche', ['cart-materia-verifiche-mappe']);  // «verifiche» al quarto
        $this->suDrive('file-in-cartella-ignota', ['cart-che-non-conosco']);
        $this->suDrive('file-senza-cartella', []);

        $esito = $this->servizio()->syncAllForTeacher($this->docente);

        sort($this->cancellati);
        self::assertSame(['file-orfano', 'file-orfano-titolo'], $this->cancellati, json_encode($esito) ?: '');
        self::assertSame(2, $esito['deleted']);
        self::assertSame(
            ['file-orfano', 'file-orfano-titolo'],
            array_values(array_map(static fn(array $d) => $d['drive_file_id'], $esito['deleted_items']))
        );
    }

    #[Test]
    public function senza_orfani_non_si_cancella_niente(): void
    {
        $this->suDrive('file-vivo', ['cart-versione']);

        $esito = $this->servizio()->syncAllForTeacher($this->docente);

        self::assertSame([], $this->cancellati);
        self::assertSame(0, $esito['deleted']);
    }
}
