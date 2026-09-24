<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Core\Config;
use App\Core\Database;
use App\Repositories\TeacherContentRepository;
use App\Services\Crypto\TeacherCryptoService;
use App\Services\Maps\MapBlobStore;
use App\Services\Study\StudyPageRenderer;
use PDO;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Le mappe grandi nella pagina di studio (15/9/2026).
 *
 * In produzione (HTTPS) una mappa il cui frammento compresso superava ~800 KB
 * andava al visualizzatore di diagrams.net con `#U` e un URL firmato
 * (`/api/maps/dl`). Il visualizzatore non lo scaricava dal browser ma dal proxy di
 * diagrams.net, che il WAF fermava con la challenge: «File non trovato»
 * (waf_logs, dal 1/9). E il contenuto decifrato passava dai server di JGraph.
 *
 * Adesso le mappe grandi si passano al visualizzatore dal browser (embed +
 * postMessage), anche in HTTPS; le piccole restano nel frammento `#R`. Nei due
 * versi, con APP_URL in HTTPS come in produzione. Database vero per la chiave del
 * docente; blob in una cartella temporanea; tutto in transazione → rollback.
 */
final class MappeGrandiNelVisualizzatoreTest extends TestCase
{
    private PDO $pdo;
    private int $docente = 0;
    private MapBlobStore $blob;
    private string $cartella = '';
    private string $appUrlPrima = '';

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
        $this->pdo->prepare(
            'INSERT INTO users (username, role, first_name, last_name, email, password_hash, status, active, created_at)
             VALUES ("zzmappegrandi", "teacher", "Zz", "Grandi", "zzmappegrandi@example.invalid", "x", "approved", 1, NOW())'
        )->execute();
        $this->docente = (int)$this->pdo->lastInsertId();

        $crypto = new TeacherCryptoService(bin2hex(random_bytes(32)));
        $crypto->encrypt($this->docente, 'chiave pronta');
        $this->cartella = sys_get_temp_dir() . '/pantedu-mappegrandi-' . bin2hex(random_bytes(6));
        $this->blob = new MapBlobStore($crypto, $this->cartella);

        $this->appUrlPrima = (string)Config::get('app.url', '');
        Config::set('app.url', 'https://pantedu.example');
    }

    protected function tearDown(): void
    {
        Config::set('app.url', $this->appUrlPrima);
        if (isset($this->pdo) && $this->pdo->inTransaction()) {
            $this->pdo->rollBack();
        }
        if ($this->cartella !== '' && is_dir($this->cartella)) {
            array_map('unlink', glob($this->cartella . '/*/*') ?: []);
            array_map('rmdir', glob($this->cartella . '/*', GLOB_ONLYDIR) ?: []);
            rmdir($this->cartella);
        }
    }

    /** La pagina di studio di una mappa con quel contenuto. */
    private function pagina(string $xml, int $id): string
    {
        $riga = [
            'id' => $id, 'teacher_id' => $this->docente, 'map_blob_path' => $this->blob->put($this->docente, $xml),
            'title' => 'Mappa di prova', 'topic' => '1.1',
        ];
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
            ->renderTopicHtml('mappa', ['ind' => 'SCI', 'cls' => '2A', 'subj' => 'FIS'], '1.1', [$riga]);
    }

    #[Test]
    public function una_mappa_grande_si_passa_dal_browser_e_non_con_un_url_firmato(): void
    {
        // Base64 di byte casuali: si comprime poco, il frammento supera ~800 KB.
        $grande = '<mxfile host="prova"><diagram id="d" name="P">' . base64_encode(random_bytes(700 * 1024)) . '</diagram></mxfile>';

        $html = $this->pagina($grande, 900001);

        $this->assertStringContainsString('data-fm-mode="embed-blob"', $html);
        $this->assertStringContainsString('id="fm-mappa-iframe-900001"', $html);
        // L'XML lo passa la pagina: dall'isola JSON il modulo della pagina di
        // studio (js/modules/features/mappe-della-pagina.js) lo manda alla
        // cornice. Fino al 23/9/2026 era uno script in linea con action:"load"
        // (R-3 passo 3); il messaggio lo prova tests/js-unit/mappe-della-pagina.
        $this->assertMatchesRegularExpression(
            '#<script type="application/json" data-fm-mappe-xml[^>]*>\{[^<]*"900001":#',
            $html,
            "l'XML lo passa la pagina, nell'isola JSON che legge il modulo"
        );
        $this->assertStringNotContainsString('action:"load"', $html, 'nessuno script in linea con il file');
        $this->assertStringNotContainsString('#U', $html);
        $this->assertStringNotContainsString('/api/maps/dl', $html, 'nessun URL firmato per il proxy di diagrams.net');
        $this->assertStringContainsString('fm-mappa-download-btn" data-fm-content-id="900001"', $html, 'e il .drawio si scarica ancora');
    }

    #[Test]
    public function una_mappa_piccola_resta_nel_frammento(): void
    {
        $html = $this->pagina('<mxfile host="prova"><diagram id="d" name="P">piccola</diagram></mxfile>', 900002);

        $this->assertStringContainsString('data-fm-mode="viewer-fragment"', $html);
        $this->assertStringContainsString('#R', $html);
        $this->assertStringNotContainsString('/api/maps/dl', $html);
    }
}
