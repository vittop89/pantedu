<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Core\Config;
use App\Core\Database;
use App\Repositories\TeacherContentRepository;
use App\Services\Crypto\TeacherCryptoService;
use App\Services\Maps\FileDrawio;
use App\Services\Maps\MapBlobStore;
use App\Services\Study\StudyPageRenderer;
use PDO;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * L'XML delle mappe nell'isola JSON della pagina di studio (23/9/2026,
 * revisione architetturale A-3, R-3 passo 1; A-19, R-3 passo 3).
 *
 * La pagina metteva il file drawio di ogni mappa, com'è, in due `<script>` in
 * linea: quello del pulsante «Scarica .drawio» e quello che passa le mappe
 * grandi al visualizzatore. Il JSON si scriveva con JSON_UNESCAPED_SLASHES e
 * senza JSON_HEX_TAG, e un `</script>` nel file chiudeva lo script: il resto
 * diventava HTML della pagina, per studenti e ospiti. Un `<!--` seguito da
 * `<script` cambia il modo in cui il browser cerca la fine dello script.
 *
 * Passo 1 (#246): la codifica. Passo 3: i due script in linea non ci sono più;
 * il comportamento sta nel bundle (js/modules/features/mappe-della-pagina.js,
 * prova tests/js-unit/mappe-della-pagina.test.js) e la pagina scrive solo i
 * dati, in un'isola `<script type="application/json" data-fm-mappe-xml>`, che
 * il browser non esegue. La codifica resta la stessa: anche un'isola di dati
 * finisce al primo `</script`.
 *
 * Nei due casi, una mappa piccola (frammento #R) e una grande (embed e
 * postMessage), con un file che contiene tutti e due, e che è un drawio
 * valido anche per il controllo del caricamento (FileDrawio): la difesa qui è
 * la codifica, non l'ingresso. Si guarda che nell'HTML ci sia un solo
 * `<script`, l'isola, che finisca dove l'ha chiusa la pagina, e che il JSON
 * decodificato ridia il file byte per byte. Sul codice di prima del passo 3
 * gli `<script` erano due, eseguibili: la prova è rossa.
 *
 * Database vero per la chiave del docente (nome unico); file in una cartella
 * temporanea; tutto in transazione → rollback.
 */
final class MappeNegliScriptDellaPaginaTest extends TestCase
{
    /** Chiude lo script, ne apre un altro, apre un commento HTML. */
    private const OSTILE = '</script><script>alert(1)</script><!--<script>';

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
        $nome = 'zzmappescript' . date('His') . bin2hex(random_bytes(3));
        $this->pdo->prepare(
            'INSERT INTO users (username, role, first_name, last_name, email, password_hash, status, active, created_at)
             VALUES (?, "teacher", "Zz", "Script", ?, "x", "approved", 1, NOW())'
        )->execute([$nome, "$nome@example.invalid"]);
        $this->docente = (int)$this->pdo->lastInsertId();

        $crypto = new TeacherCryptoService(bin2hex(random_bytes(32)));
        $crypto->encrypt($this->docente, 'chiave pronta');
        $this->cartella = sys_get_temp_dir() . '/pantedu-mappescript-' . bin2hex(random_bytes(6));
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

    /**
     * Un file drawio con il contenuto ostile in una sezione CDATA, e i caratteri
     * che la codifica nuova trasforma (& ' " / \, accenti, U+2028 e U+2029):
     * il confronto byte per byte guarda che tornino tutti uguali dopo la
     * decodifica, e controllaLIsola che U+2028 e U+2029 non compaiano crudi
     * nell'HTML.
     */
    private static function drawio(string $pagina): string
    {
        return '<mxfile host="prova"><diagram id="d" name="Velocità">' . $pagina . '</diagram>'
            . '<nota><![CDATA[' . self::OSTILE . " & ' \" / \\ é " . mb_chr(0x2028) . mb_chr(0x2029)
            . ']]></nota></mxfile>';
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

    /**
     * L'isola della pagina come la divide il browser: finisce al primo
     * `</script` che segue. È l'unico `<script` della pagina delle mappe, è di
     * dati (`type="application/json"`), non contiene `<script` né `<!--`, che
     * cambierebbero il punto in cui il browser la chiude, e finisce con la
     * graffa che la pagina ha scritto.
     *
     * @return string il contenuto dell'isola
     */
    private function isolaDellaPagina(string $html): string
    {
        self::assertSame(1, substr_count(strtolower($html), '<script'), 'solo l\'isola dei dati, nessuno script in linea');
        preg_match_all('~<script\b([^>]*)>(.*?)</script~is', $html, $m);
        self::assertCount(1, $m[2]);
        self::assertSame(' type="application/json" data-fm-mappe-xml', $m[1][0], 'un\'isola di dati, che il browser non esegue');
        $corpo = $m[2][0];
        self::assertStringNotContainsStringIgnoringCase('<script', $corpo);
        self::assertStringNotContainsString('<!--', $corpo);
        self::assertStringEndsWith('"}', $corpo, "l'isola finisce dove l'ha chiusa la pagina");
        return $corpo;
    }

    private function controllaLIsola(string $html, string $xml, int $id): void
    {
        $corpo = $this->isolaDellaPagina($html);
        self::assertStringNotContainsString(self::OSTILE, $html, 'il contenuto ostile non compare com\'è');
        // Nel file ci sono, nell'HTML no: json_encode li scrive \u2028 e
        // \u2029 (senza JSON_UNESCAPED_LINE_TERMINATORS).
        self::assertStringContainsString(mb_chr(0x2028), $xml);
        self::assertStringNotContainsString(mb_chr(0x2028), $html, 'U+2028 non compare crudo nella pagina');
        self::assertStringNotContainsString(mb_chr(0x2029), $html, 'U+2029 non compare crudo nella pagina');

        $mappe = json_decode($corpo, true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($mappe);
        self::assertSame($xml, $mappe[$id] ?? null, 'l\'isola ridà il file byte per byte');
    }

    #[Test]
    public function una_mappa_piccola_con_un_fine_script_nel_file_non_esce_dall_isola(): void
    {
        $xml = self::drawio('piccola');
        self::assertTrue(FileDrawio::valido($xml), 'è un drawio anche per il controllo del caricamento');

        $html = $this->pagina($xml, 900101);

        self::assertStringContainsString('data-fm-mode="viewer-fragment"', $html);
        $this->controllaLIsola($html, $xml, 900101);
    }

    #[Test]
    public function una_mappa_grande_con_un_fine_script_nel_file_non_esce_dall_isola(): void
    {
        // Base64 di byte casuali: si comprime poco, il frammento supera ~800 KB.
        $xml = self::drawio(base64_encode(random_bytes(700 * 1024)));
        self::assertTrue(FileDrawio::valido($xml), 'è un drawio anche per il controllo del caricamento');

        $html = $this->pagina($xml, 900102);

        self::assertStringContainsString('data-fm-mode="embed-blob"', $html);
        $this->controllaLIsola($html, $xml, 900102);
    }
}
