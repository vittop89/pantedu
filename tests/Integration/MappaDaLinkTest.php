<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Controllers\MapsController;
use App\Core\Config;
use App\Core\Database;
use App\Core\Request;
use App\Repositories\TeacherContentRepository;
use App\Services\Crypto\TeacherCryptoService;
use App\Services\Maps\MapBlobStore;
use App\Services\Maps\MappaDaLinkDrive;
use App\Services\Study\StudyPageRenderer;
use PDO;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Una mappa dal «🔗 Link esterno» (15/9/2026, segnalato dall'utente).
 *
 * Il docente aveva caricato un drawio su Drive, preso il link pubblico da
 * diagrams.net e creato la mappa con «Link esterno». Il contenuto nasceva con il
 * solo link, e la pagina di studio diceva «Mappa non disponibile localmente
 * (orphan)».
 *
 * Nei due versi, sul database vero (chiave del docente, righe) con il download
 * finto: un link di Drive si importa e la pagina mostra la mappa; un link che non
 * è di Drive non crea niente per quella via. Una mappa che ha il solo link si
 * mostra come collegamento; senza link resta l'avviso di prima. Blob in una
 * cartella temporanea, tutto in transazione → rollback.
 */
final class MappaDaLinkTest extends TestCase
{
    private const ID = '1AbCdEfGhIjKlMnOpQrStUvWxYz_-012';

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
        $nome = 'zzmappalink' . bin2hex(random_bytes(3));
        $this->pdo->prepare(
            'INSERT INTO users (username, role, first_name, last_name, email, password_hash, status, active, created_at)
             VALUES (?, "teacher", "Zz", "Link", ?, "x", "approved", 1, NOW())'
        )->execute([$nome, "$nome@example.invalid"]);
        $this->docente = (int)$this->pdo->lastInsertId();
        $_SESSION = ['autenticato' => true, 'username' => $nome, 'user_id' => $this->docente, 'user_role' => 'teacher', 'is_super_admin' => false];

        $crypto = new TeacherCryptoService(bin2hex(random_bytes(32)));
        $crypto->encrypt($this->docente, 'chiave pronta');
        $this->cartella = sys_get_temp_dir() . '/pantedu-mappalink-' . bin2hex(random_bytes(6));
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

    /** @return array{0:int, 1:array<string,mixed>} lo stato HTTP e il JSON */
    private function crea(string $href, MappaDaLinkDrive $daLink): array
    {
        $_POST = [
            'mode' => 'link', 'href' => $href, 'title' => 'Calcolatrice grafica', 'topic' => '0.0',
            'subject' => 'MAT', 'visibility' => 'draft',
        ];
        $risposta = (new MapsController(null, $this->blob, null, null, null, $daLink))->create(new Request());
        return [$risposta->status, json_decode((string)$risposta->body, true) ?: []];
    }

    /** La pagina di studio di una riga, come la vede chi la apre. */
    private function pagina(array $riga): string
    {
        $repo = new class ($riga) extends TeacherContentRepository {
            /** @param array<string, mixed> $riga */
            public function __construct(private array $riga)
            {
            }

            public function find(int $id): ?array
            {
                return $id === (int)$this->riga['id'] ? $this->riga : null;
            }
        };
        return (new StudyPageRenderer($repo, false, $this->blob))
            ->renderTopicHtml('mappa', ['ind' => 'SCI', 'cls' => '3', 'subj' => 'MAT'], '0.0', [$riga]);
    }

    #[Test]
    public function un_link_pubblico_di_drive_si_importa_e_la_pagina_mostra_la_mappa(): void
    {
        $drive = 'https://drive.google.com/uc?id=' . self::ID . '&export=download';
        $link = 'https://viewer.diagrams.net/?lightbox=1&nav=1&title=fx.drawio#U' . rawurlencode($drive);
        $xml = '<mxfile host="drive"><diagram id="d" name="P">FX-CG50</diagram></mxfile>';
        $daLink = new MappaDaLinkDrive(static fn(string $url): array => ['status' => 200, 'body' => $xml, 'host' => 'drive.usercontent.google.com']);

        [$stato, $json] = $this->crea($link, $daLink);

        self::assertSame(200, $stato, json_encode($json) ?: '');
        self::assertTrue($json['ok'] ?? false);
        $st = $this->pdo->prepare('SELECT teacher_id, map_blob_path, metadata_json, title FROM teacher_content_data WHERE id = ?');
        $st->execute([(int)$json['id']]);
        $riga = $st->fetch(PDO::FETCH_ASSOC);
        self::assertNotSame('', (string)$riga['map_blob_path'], 'il file è sul server');
        self::assertSame($xml, $this->blob->get($this->docente, (string)$riga['map_blob_path']));
        self::assertSame($link, json_decode((string)$riga['metadata_json'], true)['mappa']['href'] ?? null, 'il link resta come provenienza');

        $html = $this->pagina(['id' => (int)$json['id'], 'topic' => '0.0'] + $riga);
        self::assertStringNotContainsString('orphan', $html);
        self::assertStringContainsString('data-fm-mode="viewer-fragment"', $html, 'la mappa si vede nella pagina');
    }

    #[Test]
    public function un_link_che_non_e_di_drive_non_passa_da_qui_e_non_crea_niente(): void
    {
        $nessuna = new MappaDaLinkDrive(static function (string $url): array {
            throw new \LogicException('nessun download per un link che non è di Drive');
        });
        $prima = (int)$this->pdo->query('SELECT COUNT(*) FROM teacher_content_data WHERE teacher_id = ' . $this->docente)->fetchColumn();

        [$stato, $json] = $this->crea('https://example.org/mappa.drawio', $nessuna);

        self::assertSame(422, $stato);
        self::assertSame('link_non_drive', $json['error'] ?? null);
        self::assertSame($prima, (int)$this->pdo->query('SELECT COUNT(*) FROM teacher_content_data WHERE teacher_id = ' . $this->docente)->fetchColumn());
    }

    /**
     * Il file scaricato da Drive passa da FileDrawio intero (23/9/2026,
     * verifica avversaria di A-3): prima si guardavano i primi 4 KB, e un XML
     * con un DTD dopo `<mxfile` entrava.
     */
    #[Test]
    public function un_file_di_drive_che_non_e_un_drawio_non_crea_niente(): void
    {
        $drive = 'https://drive.google.com/uc?id=' . self::ID . '&export=download';
        $link = 'https://viewer.diagrams.net/?lightbox=1&nav=1&title=fx.drawio#U' . rawurlencode($drive);
        $conDtd = '<mxfile host="drive"><diagram id="d" name="P"/></mxfile><!DOCTYPE x [<!ENTITY y "z">]>';
        $daLink = new MappaDaLinkDrive(static fn(string $url): array => ['status' => 200, 'body' => $conDtd, 'host' => 'drive.usercontent.google.com']);
        $prima = (int)$this->pdo->query('SELECT COUNT(*) FROM teacher_content_data WHERE teacher_id = ' . $this->docente)->fetchColumn();

        [$stato, $json] = $this->crea($link, $daLink);

        self::assertSame(422, $stato, json_encode($json) ?: '');
        self::assertSame('drawio_non_valido', $json['error'] ?? null);
        self::assertSame($prima, (int)$this->pdo->query('SELECT COUNT(*) FROM teacher_content_data WHERE teacher_id = ' . $this->docente)->fetchColumn());
    }

    /** La mappa dell'utente, creata prima: si importa dal suo link, una volta sola. */
    #[Test]
    public function una_mappa_esistente_con_il_solo_link_si_importa_una_volta(): void
    {
        $drive = 'https://drive.google.com/uc?id=' . self::ID . '&export=download';
        $meta = json_encode(['mappa' => ['href' => 'https://viewer.diagrams.net/?lightbox=1#U' . rawurlencode($drive), 'display' => 'show']]);
        $this->pdo->prepare('INSERT INTO teacher_content_data (teacher_id, content_subtype, title, metadata_json) VALUES (?, "mappa", "Esistente", ?)')
            ->execute([$this->docente, $meta]);
        $id = (int)$this->pdo->lastInsertId();
        $xml = '<mxfile host="drive"><diagram id="d" name="P">esistente</diagram></mxfile>';
        $servizio = new MappaDaLinkDrive(static fn(string $url): array => ['status' => 200, 'body' => $xml, 'host' => 'drive.google.com']);

        self::assertSame(['esito' => 'da_importare', 'byte' => \strlen($xml)], $servizio->importaNellaMappa($this->pdo, $this->blob, $id, false));
        $st = $this->pdo->prepare('SELECT map_blob_path FROM teacher_content_data WHERE id = ?');
        $st->execute([$id]);
        self::assertSame('', (string)$st->fetchColumn(), 'a secco niente è scritto');

        self::assertSame('importata', $servizio->importaNellaMappa($this->pdo, $this->blob, $id, true)['esito']);
        $st->execute([$id]);
        self::assertSame($xml, $this->blob->get($this->docente, (string)$st->fetchColumn()));
        self::assertSame('gia_col_file', $servizio->importaNellaMappa($this->pdo, $this->blob, $id, true)['esito'], 'la seconda volta non tocca niente');

        $this->pdo->prepare('INSERT INTO teacher_content_data (teacher_id, content_subtype, title, metadata_json) VALUES (?, "mappa", "Sito", ?)')
            ->execute([$this->docente, json_encode(['mappa' => ['href' => 'https://example.org/x']])]);
        self::assertSame('non_drive', $servizio->importaNellaMappa($this->pdo, $this->blob, (int)$this->pdo->lastInsertId(), true)['esito']);
    }

    #[Test]
    public function una_mappa_con_il_solo_link_si_mostra_come_collegamento_e_senza_link_resta_l_avviso(): void
    {
        $drive = 'https://drive.google.com/uc?id=' . self::ID . '&export=download';
        $link = 'https://viewer.diagrams.net/?lightbox=1#U' . rawurlencode($drive);
        $base = ['id' => 910001, 'teacher_id' => $this->docente, 'map_blob_path' => '', 'title' => 'Calcolatrice grafica (FX-CG50)', 'topic' => '0.0'];

        $html = $this->pagina($base + ['metadata' => ['mappa' => ['href' => $link, 'display' => 'show']]]);
        self::assertStringNotContainsString('orphan', $html, 'il caso dell\'utente');
        self::assertStringContainsString('fm-mappa-link', $html);
        self::assertStringContainsString('href="' . htmlspecialchars($link, ENT_QUOTES) . '" target="_blank" rel="noopener noreferrer"', $html);
        self::assertStringContainsString('su Google Drive che non è stato importato', $html);

        $sito = $this->pagina(['id' => 910002] + $base + ['metadata' => ['mappa' => ['href' => 'https://example.org/mappa', 'display' => 'show']]]);
        self::assertStringContainsString('example.org', $sito);
        self::assertStringNotContainsString('Google Drive', $sito, 'per un sito niente istruzioni su Drive');

        $script = $this->pagina(['id' => 910003] + $base + ['metadata' => ['mappa' => ['href' => 'javascript:alert(1)', 'display' => 'show']]]);
        self::assertStringNotContainsString('javascript:', $script, 'solo http e https diventano un collegamento');
        self::assertStringContainsString('orphan', $script);

        self::assertStringContainsString('orphan', $this->pagina(['id' => 910004] + $base + ['metadata' => []]), 'senza link né file: l\'avviso di prima');
    }
}
