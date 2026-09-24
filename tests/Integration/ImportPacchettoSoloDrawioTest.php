<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Controllers\ImportBundleController;
use App\Core\Config;
use App\Core\Database;
use App\Core\Request;
use App\Services\Crypto\TeacherCryptoService;
use App\Services\Crypto\TeacherRecoveryService;
use App\Services\Maps\MapBlobStore;
use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * L'import dei pacchetti crea una mappa solo da un drawio (23/9/2026,
 * revisione architetturale A-3).
 *
 * È la seconda strada da cui entra una mappa, dopo «Carica file». Fino a oggi
 * POST /api/teacher/import-bundle/apply non guardava il contenuto: il tipo lo
 * dava l'estensione del percorso, e un .html diventava una mappa text/html, un
 * .pdf una application/pdf. Adesso lo stesso controllo di «Carica file»
 * (FileDrawio): una mappa che non è un drawio non si crea, il riepilogo la
 * mette fra gli errori (`drawio_non_valido`), e quelle che si creano si
 * salvano come application/xml.
 *
 * Nei due versi, attraverso il controller, con un pacchetto firmato come lo
 * firma l'esportazione (HMAC con una chiave di recupero della prova). Database
 * vero, docente con nome unico, tutto in transazione → rollback; file cifrati
 * in una cartella temporanea.
 */
final class ImportPacchettoSoloDrawioTest extends TestCase
{
    private const DRAWIO = '<mxfile host="e2e"><diagram id="d" name="Pagina"><mxGraphModel><root>'
        . '<mxCell id="0"/><mxCell id="1" parent="0"/></root></mxGraphModel></diagram></mxfile>';

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
        $nome = 'zzimportmappa' . date('His') . bin2hex(random_bytes(3));
        $this->pdo->prepare(
            'INSERT INTO users (username, role, first_name, last_name, email, password_hash, status, active, created_at)
             VALUES (?, "teacher", "Zz", "Import", ?, "x", "approved", 1, NOW())'
        )->execute([$nome, "$nome@example.invalid"]);
        $this->docente = (int)$this->pdo->lastInsertId();
        $_SESSION = [
            'autenticato' => true, 'username' => $nome, 'user_id' => $this->docente,
            'user_role' => 'teacher', 'is_super_admin' => false,
        ];

        $crypto = new TeacherCryptoService(bin2hex(random_bytes(32)));
        $crypto->encrypt($this->docente, 'chiave pronta');
        $this->cartella = sys_get_temp_dir() . '/pantedu-importmappa-' . bin2hex(random_bytes(6));
        $this->blob = new MapBlobStore($crypto, $this->cartella);
    }

    protected function tearDown(): void
    {
        $_SESSION = [];
        if (isset($this->pdo) && $this->pdo->inTransaction()) {
            $this->pdo->rollBack();
        }
        if ($this->cartella !== '' && is_dir($this->cartella)) {
            array_map('unlink', glob($this->cartella . '/*/*') ?: []);
            array_map('rmdir', glob($this->cartella . '/*', GLOB_ONLYDIR) ?: []);
            rmdir($this->cartella);
        }
    }

    /**
     * Un pacchetto con un solo file mappa, firmato come lo firma
     * l'esportazione, mandato ad apply o a preview.
     *
     * @return array{0:int, 1:array<string,mixed>} lo stato HTTP e il JSON
     */
    private function importa(string $percorso, string $contenuto, bool $applica = true): array
    {
        $chiave = random_bytes(32);
        $recupero = new TeacherRecoveryService();
        $manifesto = [
            'version' => 1,
            'exported_at' => gmdate('c'),
            'files' => [[
                'type' => 'mappa', 'path' => $percorso,
                'sha256' => hash('sha256', $contenuto), 'size' => \strlen($contenuto),
            ]],
        ];
        // La firma dell'esportazione: TeacherRecoveryService::computeHmac, la
        // stessa che verifyManifestHmac ricalcola.
        $manifesto['hmac'] = (new \ReflectionMethod(TeacherRecoveryService::class, 'computeHmac'))
            ->invoke($recupero, $chiave, $manifesto);
        $corpo = json_encode([
            'recovery_code' => strtoupper(bin2hex($chiave)),
            'manifest' => $manifesto,
            'files' => [['path' => $percorso, 'content_b64' => base64_encode($contenuto)]],
            'conflict_strategy' => 'rename',
        ], JSON_THROW_ON_ERROR);

        $controller = new ImportBundleController($recupero, null, $this->blob);
        $risposta = $applica
            ? $controller->apply(new Request($corpo))
            : $controller->preview(new Request($corpo));
        return [$risposta->status, json_decode($risposta->body, true) ?: []];
    }

    /** @return list<array<string, mixed>> */
    private function mappeDelDocente(): array
    {
        $st = $this->pdo->prepare(
            'SELECT id, map_blob_path, map_mime FROM teacher_content_data
              WHERE teacher_id = ? AND content_subtype = "mappa"'
        );
        $st->execute([$this->docente]);
        return $st->fetchAll(PDO::FETCH_ASSOC);
    }

    /** @return iterable<string, array{string, string}> */
    public static function drawio(): iterable
    {
        yield '.drawio' => ['ist/SCI/2A/FIS/mappe/Cinematica.drawio', self::DRAWIO];
        // Le mappe application/octet-stream si esportano senza estensione
        // (BundlePathBuilder::mapPath): prima rientravano con quel tipo.
        yield 'senza estensione' => [
            'ist/SCI/2A/FIS/mappe/Dinamica', '<?xml version="1.0" encoding="UTF-8"?>' . "\n" . self::DRAWIO,
        ];
    }

    #[Test]
    #[DataProvider('drawio')]
    public function un_drawio_si_importa_come_xml(string $percorso, string $contenuto): void
    {
        [$stato, $json] = $this->importa($percorso, $contenuto);

        self::assertSame(200, $stato, json_encode($json) ?: '');
        self::assertSame(1, $json['report']['applied'] ?? null, json_encode($json) ?: '');
        self::assertSame([], $json['report']['errors'] ?? null);
        $mappe = $this->mappeDelDocente();
        self::assertCount(1, $mappe);
        self::assertSame('application/xml', $mappe[0]['map_mime']);
        self::assertSame($contenuto, $this->blob->get($this->docente, (string)$mappe[0]['map_blob_path']));
    }

    /** @return iterable<string, array{string, string}> */
    public static function nonDrawio(): iterable
    {
        yield 'una pagina HTML' => [
            'ist/SCI/2A/FIS/mappe/Pagina.html', '<html><body><script>alert(1)</script></body></html>',
        ];
        yield 'HTML con <mxfile in un commento, chiamato .drawio' => [
            'ist/SCI/2A/FIS/mappe/Finta.drawio', '<!-- <mxfile --><html><body><script>alert(1)</script></body></html>',
        ];
        yield 'un PDF' => [
            'ist/SCI/2A/FIS/mappe/Dispensa.pdf', "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n1 0 obj\n<<>>\nendobj\n%%EOF\n",
        ];
        yield 'un PNG' => [
            'ist/SCI/2A/FIS/mappe/Schema.png', "\x89PNG\r\n\x1a\n\x00\x00\x00\x0dIHDR\x00\x00\x00\x01\x00\x00\x00\x01",
        ];
    }

    #[Test]
    #[DataProvider('nonDrawio')]
    public function cio_che_non_e_un_drawio_non_diventa_una_mappa(string $percorso, string $contenuto): void
    {
        [$stato, $json] = $this->importa($percorso, $contenuto);

        self::assertSame(200, $stato, json_encode($json) ?: '');
        self::assertSame(0, $json['report']['applied'] ?? null, json_encode($json) ?: '');
        self::assertSame([], $json['report']['created'] ?? null);
        self::assertCount(1, $json['report']['errors'] ?? []);
        self::assertSame($percorso, $json['report']['errors'][0]['path'] ?? null);
        self::assertSame('drawio_non_valido', $json['report']['errors'][0]['reason'] ?? null);
        self::assertSame([], $this->mappeDelDocente(), 'nessuna riga');
        self::assertSame([], glob($this->cartella . '/*/*') ?: [], 'nessun file cifrato');
    }

    #[Test]
    public function anche_l_anteprima_con_i_file_lo_dice(): void
    {
        [$stato, $json] = $this->importa('ist/SCI/2A/FIS/mappe/Pagina.html', '<html><body/></html>', false);

        self::assertSame(200, $stato, json_encode($json) ?: '');
        self::assertTrue($json['preview'] ?? null);
        self::assertSame([], $json['report']['created'] ?? null, 'non la propone come da creare');
        self::assertSame('drawio_non_valido', $json['report']['errors'][0]['reason'] ?? null);
    }
}
