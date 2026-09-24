<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Controllers\MapsController;
use App\Core\Config;
use App\Core\Database;
use App\Core\Request;
use App\Services\Crypto\TeacherCryptoService;
use App\Services\Maps\MapBlobStore;
use App\Services\Maps\MapSignedUrlService;
use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Il link firmato di una mappa (GET /api/maps/dl) dà un file da scaricare, mai
 * un documento del sito (23/9/2026, revisione architetturale A-3).
 *
 * Fino a oggi la risposta aveva il tipo registrato nella riga (map_mime): una
 * mappa caricata come text/html, e fino a oggi «Carica file» e l'import lo
 * permettevano, si apriva come pagina del sito, dalla stessa origine, a chi
 * apriva il link. Ora: application/octet-stream, come allegato, con nosniff,
 * qualunque sia map_mime; e i byte sono quelli del file (l'editor li legge
 * con fetch, drawio-editor.js).
 *
 * Nei due versi: le righe con map_mime text/html (le vecchie), application/xml
 * e application/pdf escono tutte così; con la risposta di prima, il caso HTML
 * esce text/html e senza allegato. Database vero, docente con nome unico, tutto
 * in transazione → rollback; file cifrati in una cartella temporanea.
 */
final class MappaScaricataComeAllegatoTest extends TestCase
{
    private const DRAWIO = '<mxfile host="e2e"><diagram id="d" name="Pagina"><mxGraphModel><root>'
        . '<mxCell id="0"/><mxCell id="1" parent="0"/></root></mxGraphModel></diagram></mxfile>';

    private PDO $pdo;
    private int $docente = 0;
    private MapBlobStore $blob;
    private string $cartella = '';
    private mixed $segretoPrima = null;

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
        $nome = 'zzscaricamappa' . date('His') . bin2hex(random_bytes(3));
        $this->pdo->prepare(
            'INSERT INTO users (username, role, first_name, last_name, email, password_hash, status, active, created_at)
             VALUES (?, "teacher", "Zz", "Scarica", ?, "x", "approved", 1, NOW())'
        )->execute([$nome, "$nome@example.invalid"]);
        $this->docente = (int)$this->pdo->lastInsertId();

        $crypto = new TeacherCryptoService(bin2hex(random_bytes(32)));
        $crypto->encrypt($this->docente, 'chiave pronta');
        $this->cartella = sys_get_temp_dir() . '/pantedu-scaricamappa-' . bin2hex(random_bytes(6));
        $this->blob = new MapBlobStore($crypto, $this->cartella);

        // Un segreto di firma della prova: quello dell'ambiente non si legge.
        $this->segretoPrima = Config::get('storage.signing_secret');
        Config::set('storage.signing_secret', bin2hex(random_bytes(32)));
    }

    protected function tearDown(): void
    {
        Config::set('storage.signing_secret', $this->segretoPrima);
        $_GET = [];
        if (isset($this->pdo) && $this->pdo->inTransaction()) {
            $this->pdo->rollBack();
        }
        if ($this->cartella !== '' && is_dir($this->cartella)) {
            array_map('unlink', glob($this->cartella . '/*/*') ?: []);
            array_map('rmdir', glob($this->cartella . '/*', GLOB_ONLYDIR) ?: []);
            rmdir($this->cartella);
        }
    }

    /** Una mappa del docente con quel file e quel tipo registrato. */
    private function mappa(string $contenuto, string $tipo): int
    {
        $this->pdo->prepare(
            'INSERT INTO teacher_content_data (teacher_id, content_subtype, topic, title, metadata_json, visibility)
             VALUES (?, "mappa", "1.1", "Mappa da scaricare", "{}", "draft")'
        )->execute([$this->docente]);
        $id = (int)$this->pdo->lastInsertId();
        $this->pdo->prepare(
            'UPDATE teacher_content_data SET map_blob_path = ?, map_mime = ?, map_size = ? WHERE id = ?'
        )->execute([$this->blob->put($this->docente, $contenuto), $tipo, \strlen($contenuto), $id]);
        return $id;
    }

    /** Il link firmato, aperto come lo apre il browser. */
    private function scarica(int $id): \App\Core\Response
    {
        $url = (new MapSignedUrlService())->mint($id);
        parse_str((string)parse_url($url, PHP_URL_QUERY), $_GET);
        return (new MapsController(null, $this->blob))->download(new Request());
    }

    /** @return iterable<string, array{string, string, string}> */
    public static function mappe(): iterable
    {
        yield 'una riga vecchia text/html' => [
            '<html><body><script>alert(document.cookie)</script></body></html>', 'text/html', 'drawio',
        ];
        yield 'un drawio, application/xml' => [
            self::DRAWIO . '<!-- <x:script xmlns:x="http://www.w3.org/1999/xhtml">alert(1)</x:script> -->',
            'application/xml', 'drawio',
        ];
        yield 'un PDF vecchio' => ["%PDF-1.4\n%%EOF\n", 'application/pdf', 'pdf'];
    }

    #[Test]
    #[DataProvider('mappe')]
    public function il_file_si_scarica_e_non_si_apre(string $contenuto, string $tipo, string $estensione): void
    {
        $id = $this->mappa($contenuto, $tipo);

        $risposta = $this->scarica($id);

        self::assertSame(200, $risposta->status, $risposta->body);
        self::assertSame('application/octet-stream', $risposta->headers['Content-Type'] ?? null);
        self::assertSame(
            'attachment; filename="mappa-' . $id . '.' . $estensione . '"',
            $risposta->headers['Content-Disposition'] ?? null
        );
        self::assertSame('nosniff', $risposta->headers['X-Content-Type-Options'] ?? null);
        self::assertSame($contenuto, $risposta->body, 'i byte sono quelli del file');
    }
}
