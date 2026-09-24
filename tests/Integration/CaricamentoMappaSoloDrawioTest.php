<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Controllers\MapsController;
use App\Core\Config;
use App\Core\Database;
use App\Core\Request;
use App\Services\Crypto\TeacherCryptoService;
use App\Services\Maps\MapBlobStore;
use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * «Carica file» accetta solo un drawio (23/9/2026, revisione architetturale
 * A-3, R-3 passo 2).
 *
 * Fino a oggi POST /api/maps con mode=upload guardava il nome e i primi 256
 * byte: una pagina HTML entrava come text/html, una con `<mxfile` in un
 * commento anche con il nome .drawio, e PDF e PNG entravano senza che nessuna
 * pagina li sapesse mostrare. La pagina di studio mette il file della mappa in
 * uno `<script>`. Adesso conta il contenuto (FileDrawio), e il file si salva
 * come application/xml.
 *
 * Nei due versi, attraverso il controller: un drawio si salva, con il nome
 * .drawio o .xml e qualunque tipo dichiari il browser; ciò che non è un drawio
 * risponde 422 `drawio_non_valido` e non lascia né righe né file. Il file
 * «caricato» lo dà la prova (da riga di comando is_uploaded_file è sempre
 * falso). Database vero, docente con nome unico, tutto in transazione →
 * rollback; file cifrati in una cartella temporanea.
 */
final class CaricamentoMappaSoloDrawioTest extends TestCase
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
        $nome = 'zzcaricamappa' . date('His') . bin2hex(random_bytes(3));
        $this->pdo->prepare(
            'INSERT INTO users (username, role, first_name, last_name, email, password_hash, status, active, created_at)
             VALUES (?, "teacher", "Zz", "Carica", ?, "x", "approved", 1, NOW())'
        )->execute([$nome, "$nome@example.invalid"]);
        $this->docente = (int)$this->pdo->lastInsertId();
        $_SESSION = [
            'autenticato' => true, 'username' => $nome, 'user_id' => $this->docente,
            'user_role' => 'teacher', 'is_super_admin' => false,
        ];

        $crypto = new TeacherCryptoService(bin2hex(random_bytes(32)));
        $crypto->encrypt($this->docente, 'chiave pronta');
        $this->cartella = sys_get_temp_dir() . '/pantedu-caricamappa-' . bin2hex(random_bytes(6));
        $this->blob = new MapBlobStore($crypto, $this->cartella);
    }

    protected function tearDown(): void
    {
        $_SESSION = [];
        $_POST = [];
        $_FILES = [];
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
     * Carica un file come fa il modale «Carica file».
     *
     * @return array{0:int, 1:array<string,mixed>} lo stato HTTP e il JSON
     */
    private function carica(string $nome, string $tipo, string $contenuto, bool $sostituisciIlControllo = true): array
    {
        $tmp = (string)tempnam(sys_get_temp_dir(), 'pantedu-caricamento-');
        try {
            file_put_contents($tmp, $contenuto);
            $_FILES = ['file' => [
                'name' => $nome, 'type' => $tipo, 'tmp_name' => $tmp,
                'error' => UPLOAD_ERR_OK, 'size' => \strlen($contenuto),
            ]];
            $_POST = [
                'mode' => 'upload', 'title' => 'Mappa caricata', 'topic' => '0.0',
                'subject' => 'FIS', 'visibility' => 'draft',
            ];
            $caricato = $sostituisciIlControllo ? static fn(string $p): bool => $p === $tmp : null;
            $controller = new MapsController(null, $this->blob, null, null, null, null, $caricato);
            $risposta = $controller->create(new Request());
            return [$risposta->status, json_decode((string)$risposta->body, true) ?: []];
        } finally {
            @unlink($tmp);
        }
    }

    private function mappeDelDocente(): int
    {
        $st = $this->pdo->prepare('SELECT COUNT(*) FROM teacher_content_data WHERE teacher_id = ?');
        $st->execute([$this->docente]);
        return (int)$st->fetchColumn();
    }

    /** @return iterable<string, array{string, string, string}> */
    public static function drawio(): iterable
    {
        yield '.drawio senza tipo del browser' => ['mappa.drawio', 'application/octet-stream', self::DRAWIO];
        yield '.xml con la dichiarazione' => [
            'mappa.xml', 'text/xml', '<?xml version="1.0" encoding="UTF-8"?>' . "\n" . self::DRAWIO,
        ];
    }

    #[Test]
    #[DataProvider('drawio')]
    public function un_drawio_si_salva_come_xml(string $nome, string $tipo, string $contenuto): void
    {
        [$stato, $json] = $this->carica($nome, $tipo, $contenuto);

        self::assertSame(200, $stato, json_encode($json) ?: '');
        self::assertSame('application/xml', $json['mime'] ?? null);
        $st = $this->pdo->prepare(
            'SELECT map_blob_path, map_mime FROM teacher_content_data WHERE id = ? AND teacher_id = ?'
        );
        $st->execute([(int)$json['id'], $this->docente]);
        $riga = $st->fetch(PDO::FETCH_ASSOC);
        self::assertIsArray($riga);
        self::assertSame('application/xml', $riga['map_mime']);
        $salvato = $this->blob->get($this->docente, (string)$riga['map_blob_path']);
        self::assertSame($contenuto, $salvato, 'il file è quello caricato');
    }

    /** @return iterable<string, array{string, string, string}> */
    public static function nonDrawio(): iterable
    {
        yield 'una pagina HTML' => ['pagina.html', 'text/html', '<html><body><script>alert(1)</script></body></html>'];
        yield 'HTML con <mxfile in un commento, chiamato .drawio' => [
            'mappa.drawio', 'application/octet-stream',
            '<!-- <mxfile --><html><body><script>alert(1)</script></body></html>',
        ];
        yield 'un PDF' => [
            'dispensa.pdf', 'application/pdf', "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n1 0 obj\n<<>>\nendobj\n%%EOF\n",
        ];
        yield 'un PNG' => [
            'schema.png', 'image/png', "\x89PNG\r\n\x1a\n\x00\x00\x00\x0dIHDR\x00\x00\x00\x01\x00\x00\x00\x01",
        ];
    }

    #[Test]
    #[DataProvider('nonDrawio')]
    public function cio_che_non_e_un_drawio_non_si_salva(string $nome, string $tipo, string $contenuto): void
    {
        $prima = $this->mappeDelDocente();

        [$stato, $json] = $this->carica($nome, $tipo, $contenuto);

        self::assertSame(422, $stato, json_encode($json) ?: '');
        self::assertSame('drawio_non_valido', $json['error'] ?? null);
        self::assertSame($prima, $this->mappeDelDocente(), 'nessuna riga');
        self::assertSame([], glob($this->cartella . '/*/*') ?: [], 'nessun file cifrato');
    }

    /**
     * Le altre due strade dell'editor (23/9/2026, verifica avversaria di A-3):
     * la creazione (drawio_native) e il salvataggio (update) cercavano solo la
     * sottostringa `<mxfile`, e un XML con un DTD si salvava. Ora passano da
     * FileDrawio come «Carica file».
     *
     * @return array{0:int, 1:array<string,mixed>}
     */
    private function creaDallEditor(string $xml): array
    {
        $_FILES = [];
        $_POST = [
            'mode' => 'drawio_native', 'xml' => $xml, 'title' => 'Mappa dall editor', 'topic' => '0.0',
            'subject' => 'FIS', 'visibility' => 'draft',
        ];
        $risposta = (new MapsController(null, $this->blob))->create(new Request());
        return [$risposta->status, json_decode((string)$risposta->body, true) ?: []];
    }

    /** @return iterable<string, array{string}> */
    public static function xmlCheNonSonoDrawio(): iterable
    {
        yield 'con un DTD e <mxfile' => [
            '<!DOCTYPE mxfile [<!ENTITY x "ha">]><mxfile>&x;</mxfile>',
        ];
        yield 'HTML con <mxfile in un commento' => ['<!-- <mxfile --><html><body/></html>'];
        yield 'annidato oltre il tetto' => [
            '<mxfile>' . str_repeat('<a>', 80) . str_repeat('</a>', 80) . '</mxfile>',
        ];
    }

    #[Test]
    #[DataProvider('xmlCheNonSonoDrawio')]
    public function dall_editor_si_crea_solo_un_drawio(string $xml): void
    {
        $prima = $this->mappeDelDocente();

        [$stato, $json] = $this->creaDallEditor($xml);

        self::assertSame(422, $stato, json_encode($json) ?: '');
        self::assertSame('xml_invalid', $json['error'] ?? null);
        self::assertSame($prima, $this->mappeDelDocente(), 'nessuna riga');
    }

    #[Test]
    #[DataProvider('xmlCheNonSonoDrawio')]
    public function dall_editor_si_salva_solo_un_drawio(string $xml): void
    {
        [$stato, $json] = $this->creaDallEditor(self::DRAWIO);
        self::assertSame(200, $stato, 'precondizione: la mappa si crea: ' . (json_encode($json) ?: ''));
        $id = (int)$json['id'];

        $_POST = ['xml' => $xml, 'map_version' => '1'];
        $risposta = (new MapsController(null, $this->blob))->update(new Request(), ['id' => $id]);
        $esito = json_decode((string)$risposta->body, true) ?: [];

        self::assertSame(422, $risposta->status, json_encode($esito) ?: '');
        self::assertSame('xml_invalid', $esito['error'] ?? null);
        $st = $this->pdo->prepare('SELECT map_blob_path FROM teacher_content_data WHERE id = ?');
        $st->execute([$id]);
        self::assertSame(
            self::DRAWIO,
            $this->blob->get($this->docente, (string)$st->fetchColumn()),
            'il file salvato resta quello di prima'
        );
    }

    #[Test]
    public function senza_sostituto_conta_is_uploaded_file(): void
    {
        // Il controllo sostituibile c'è solo per le prove: senza, vale
        // is_uploaded_file, e un file che non è arrivato con la richiesta HTTP
        // (qui, uno scritto da riga di comando) non si salva, nemmeno se è un
        // drawio valido.
        $prima = $this->mappeDelDocente();

        [$stato, $json] = $this->carica('mappa.drawio', 'application/octet-stream', self::DRAWIO, false);

        self::assertSame(422, $stato, json_encode($json) ?: '');
        self::assertSame('upload_invalid', $json['error'] ?? null);
        self::assertSame($prima, $this->mappeDelDocente(), 'nessuna riga');
        self::assertSame([], glob($this->cartella . '/*/*') ?: [], 'nessun file cifrato');
    }
}
