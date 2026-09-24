<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Controllers\MapsController;
use App\Controllers\StudyHeaderController;
use App\Controllers\TeacherAdozioniController;
use App\Core\Config;
use App\Core\Database;
use App\Core\Request;
use App\Repositories\Curriculum\AdozioniRepository;
use App\Repositories\PdfImportSessionRepository;
use App\Services\Crypto\TeacherCryptoService;
use App\Services\Maps\MapBlobStore;
use App\Services\PdfImport\ExerciseInserter;
use App\Services\PdfImport\Session\SessionStorage;
use App\Support\Sources\SourcesRegistryStore;
use App\Support\Storage\StorageFactory;
use App\Support\TeacherContextResolver;
use PDO;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Tests\Unit\Contract\InMemoryStorageProvider;

/**
 * Istituto attivo e casa dei file privati sono due cose (revisione
 * architetturale del 23/9/2026, A-7, R-9).
 *
 * Un docente di due scuole lavora in quella scelta nel selettore (istituto
 * attivo), ma le sue preferenze stanno sempre nella prima (casa dei file
 * privati). Fino a oggi l'unico nome era `firstInstituteId`, e in tre punti
 * lo si usava come se fosse l'istituto attivo.
 *
 * Fixture in transazione, annullata a fine prova anche se fallisce: tre
 * istituti con codici unici, il docente collegato alla prima e alla seconda,
 * non alla terza. La terza serve al confine di ADR-037: una scuola in
 * sessione a cui il docente non appartiene non deve contare. La materia
 * esiste in tutte e tre; ogni scuola ha una sezione della barra che le altre
 * non hanno. I file delle mappe vanno in una cartella temporanea, cancellata
 * a fine prova; i contratti, i file dell'import e le intestazioni in un
 * deposito in memoria.
 */
final class IstitutoAttivoECasaDeiFileTest extends TestCase
{
    private PDO $pdo;
    private bool $inTx = false;
    private string $sigla = '';
    private string $utente = '';
    private int $docente = 0;
    private int $prima = 0;
    private int $seconda = 0;
    private int $estranea = 0;
    /** @var array<int,string> chiave della sezione che esiste solo in quella scuola */
    private array $chiave = [];
    /** @var array<int,int> id della sezione che esiste solo in quella scuola */
    private array $sezione = [];
    private string $cartellaMappe = '';
    private ?MapBlobStore $mappe = null;
    private ?TeacherCryptoService $crypto = null;
    private ?InMemoryStorageProvider $deposito = null;

    private const MATERIA = 'ZIA';
    private const DRAWIO = '<mxfile host="prova"><diagram id="d" name="Pagina"><mxGraphModel><root>'
        . '<mxCell id="0"/><mxCell id="1" parent="0"/></root></mxGraphModel></diagram></mxfile>';

    protected function setUp(): void
    {
        $basePath = dirname(__DIR__, 2);
        foreach (['.env', '.env.local'] as $f) {
            if (is_file("$basePath/$f")) {
                \Dotenv\Dotenv::createMutable($basePath, $f)->safeLoad();
            }
        }
        \App\Core\Config::load($basePath . '/app/Config');
        try {
            $this->pdo = Database::connection();
            $this->pdo->query('SELECT 1 FROM teacher_institutes LIMIT 1');
        } catch (\Throwable $e) {
            $this->markTestSkipped('DB non disponibile: ' . $e->getMessage());
        }
        $this->pdo->beginTransaction();
        $this->inTx = true;
        \App\Support\CurriculumLookup::resetCache();

        // Codici e nomi unici: il database è condiviso con altre prove.
        $this->sigla = strtoupper(bin2hex(random_bytes(4)));
        $this->prima = $this->istituto('PRIMA');
        $this->seconda = $this->istituto('SECONDA');
        $this->estranea = $this->istituto('ESTRANEA');

        $this->utente = 'zziac' . strtolower($this->sigla);
        $this->docente = $this->utenteNuovo($this->utente, 'teacher', null);
        $ti = $this->pdo->prepare('INSERT INTO teacher_institutes (user_id, institute_id) VALUES (?, ?)');
        $ti->execute([$this->docente, $this->prima]);
        $ti->execute([$this->docente, $this->seconda]);

        $voce = $this->pdo->prepare(
            'INSERT INTO curriculum_entries (kind, institute_id, code, label, active, shared_with_pool, origine)
             VALUES ("materie", ?, ?, "Materia", 1, 0, "istituto")'
        );
        foreach ([$this->prima, $this->seconda, $this->estranea] as $i => $scuola) {
            $voce->execute([$scuola, self::MATERIA]);
            $this->chiave[$scuola] = 'zzia' . strtolower($this->sigla) . $i;
            $this->sezione[$scuola] = $this->sezioneDellaBarra($scuola, $this->chiave[$scuola]);
        }
    }

    protected function tearDown(): void
    {
        if ($this->inTx && $this->pdo->inTransaction()) {
            $this->pdo->rollBack();
        }
        $_SESSION = [];
        $_POST = [];
        $_GET = [];
        \App\Support\CurriculumLookup::resetCache();
        StorageFactory::reset();
        if ($this->cartellaMappe !== '' && is_dir($this->cartellaMappe)) {
            array_map('unlink', glob($this->cartellaMappe . '/*/*') ?: []);
            array_map('rmdir', glob($this->cartellaMappe . '/*', GLOB_ONLYDIR) ?: []);
            rmdir($this->cartellaMappe);
        }
    }

    private function sezioneDellaBarra(int $istituto, string $chiave): int
    {
        $this->pdo->prepare(
            'INSERT INTO sidebar_sections
                (institute_id, section_key, label, position, loader_kind, group_mode,
                 allowed_content_types, default_content_type, visible_roles, active, is_default)
             VALUES (?, ?, ?, 99, "db", "subject", ?, "mappa", ?, 1, 0)'
        )->execute([$istituto, $chiave, 'Solo qui ' . $chiave, '["mappa","document","esercizio"]', '["teacher","student"]']);
        return (int)$this->pdo->lastInsertId();
    }

    private function istituto(string $nome, string $prefisso = 'ZZIA'): int
    {
        $this->pdo->prepare('INSERT INTO institutes (code, name, city, active) VALUES (?, ?, ?, 1)')
            ->execute([$prefisso . $this->sigla . substr($nome, 0, 3), $nome . ' ' . $this->sigla, 'Comune Esempio']);
        return (int)$this->pdo->lastInsertId();
    }

    private function utenteNuovo(string $nome, string $ruolo, ?int $istituto): int
    {
        $this->pdo->prepare(
            'INSERT INTO users (username, role, first_name, last_name, email, password_hash,
                                institute_id, status, active, created_at)
             VALUES (?, ?, "Zz", "Casa", ?, "x", ?, "approved", 1, NOW())'
        )->execute([$nome, $ruolo, $nome . '@example.invalid', $istituto]);
        return (int)$this->pdo->lastInsertId();
    }

    /** Il docente autenticato, con la scuola `$istituto` in sessione. */
    private function docenteNellaScuola(int $istituto): void
    {
        $_SESSION = [
            'autenticato'          => true,
            'username'             => $this->utente,
            'user_id'              => $this->docente,
            'user_role'            => 'teacher',
            'is_super_admin'       => false,
            'current_institute_id' => $istituto,
        ];
    }

    #[Test]
    public function la_casa_dei_file_non_segue_il_selettore(): void
    {
        $this->docenteNellaScuola($this->seconda);
        $this->assertSame($this->prima, TeacherContextResolver::privateFilesInstituteId($this->docente));
        $this->docenteNellaScuola($this->prima);
        $this->assertSame($this->prima, TeacherContextResolver::privateFilesInstituteId($this->docente));
    }

    #[Test]
    public function la_casa_dei_file_salta_gli_istituti_miur(): void
    {
        // Un istituto `MIUR-%` con l'id più basso, e uno vero dopo: la casa è
        // quello vero, anche se non è il primo per id.
        $altro = $this->utenteNuovo($this->utente . 'm', 'teacher', null);
        $miur = $this->istituto('MIUR', 'MIUR-');
        $vero = $this->istituto('VERO');
        $ti = $this->pdo->prepare('INSERT INTO teacher_institutes (user_id, institute_id) VALUES (?, ?)');
        $ti->execute([$altro, $miur]);
        $ti->execute([$altro, $vero]);
        $this->assertLessThan($vero, $miur);
        $this->assertSame($vero, TeacherContextResolver::privateFilesInstituteId($altro));
    }

    #[Test]
    public function l_istituto_attivo_segue_il_selettore(): void
    {
        $this->docenteNellaScuola($this->seconda);
        $this->assertSame($this->seconda, TeacherContextResolver::activeInstituteId($this->docente));
        $this->docenteNellaScuola($this->prima);
        $this->assertSame($this->prima, TeacherContextResolver::activeInstituteId($this->docente));
    }

    #[Test]
    public function una_scuola_in_sessione_non_sua_non_diventa_attiva(): void
    {
        // Confine di ADR-037: la sessione dice «estranea», ma il docente non ci
        // lavora. L'istituto attivo ripiega sulla sua prima scuola.
        $this->docenteNellaScuola($this->estranea);
        $this->assertSame($this->prima, TeacherContextResolver::activeInstituteId($this->docente));
    }

    #[Test]
    public function senza_scuole_entrambi_valgono_zero(): void
    {
        $solo = $this->utenteNuovo($this->utente . 's', 'teacher', null);
        $this->assertSame(0, TeacherContextResolver::privateFilesInstituteId($solo));
        $this->assertSame(0, TeacherContextResolver::activeInstituteId($solo));
    }

    /**
     * Una chiave master per prova: la chiave del docente nasce al primo uso
     * e le operazioni successive la devono poter riaprire.
     */
    private function crypto(): TeacherCryptoService
    {
        if ($this->crypto === null) {
            Config::set('crypto.allow_regenerate', false);
            $this->crypto = new TeacherCryptoService(bin2hex(random_bytes(32)));
            $this->crypto->encrypt($this->docente, 'chiave pronta');
        }
        return $this->crypto;
    }

    /** Il deposito in memoria al posto di quello su disco (StorageFactory::default()). */
    private function deposito(): InMemoryStorageProvider
    {
        if ($this->deposito === null) {
            $this->deposito = new InMemoryStorageProvider();
            $memo = (new ReflectionClass(StorageFactory::class))->getProperty('memo');
            $memo->setAccessible(true);
            $memo->setValue(null, $this->deposito);
        }
        return $this->deposito;
    }

    // ── Mappe: la sezione di creazione (MapsController::create) ──────────

    /** Crea una mappa disegnata nell'app con la sezione `$chiave`; torna la sua section_id. */
    private function creaMappa(string $chiave): ?int
    {
        if ($this->mappe === null) {
            $this->cartellaMappe = sys_get_temp_dir() . '/pantedu-casa-mappe-' . bin2hex(random_bytes(6));
            $this->mappe = new MapBlobStore($this->crypto(), $this->cartellaMappe);
        }
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_SERVER['REQUEST_URI'] = '/api/maps';
        $_POST = [
            'mode' => 'drawio_native', 'xml' => self::DRAWIO, 'title' => 'Mappa ' . uniqid(),
            'topic' => 'Casa', 'subject' => self::MATERIA, 'visibility' => 'draft',
            'section_key' => $chiave,
        ];
        $risposta = (new MapsController(null, $this->mappe))->create(new Request());
        $corpo = json_decode((string)$risposta->body, true);
        $this->assertSame(200, $risposta->status, 'creazione: ' . json_encode($corpo));
        $st = $this->pdo->prepare('SELECT section_id FROM teacher_content_data WHERE id = ? AND teacher_id = ?');
        $st->execute([(int)$corpo['id'], $this->docente]);
        $sezione = $st->fetchColumn();
        $this->assertNotFalse($sezione, 'la riga della mappa esiste');
        return $sezione === null ? null : (int)$sezione;
    }

    #[Test]
    public function con_la_seconda_attiva_la_mappa_nasce_nella_sezione_della_seconda(): void
    {
        $this->docenteNellaScuola($this->seconda);
        $this->assertSame(
            $this->sezione[$this->seconda],
            $this->creaMappa($this->chiave[$this->seconda]),
            'fino al 23/9/2026: NULL, la sezione si cercava nella prima scuola'
        );
    }

    #[Test]
    public function con_la_prima_attiva_la_mappa_non_trova_la_sezione_della_seconda(): void
    {
        $this->docenteNellaScuola($this->prima);
        $this->assertNull($this->creaMappa($this->chiave[$this->seconda]));
        $this->assertSame($this->sezione[$this->prima], $this->creaMappa($this->chiave[$this->prima]));
    }

    #[Test]
    public function una_scuola_non_sua_in_sessione_non_presta_le_sue_sezioni_alla_mappa(): void
    {
        // Confine di ADR-037: con «estranea» in sessione la sua sezione non si
        // trova; vale la prima scuola del docente, a cui la mappa si aggancia.
        $this->docenteNellaScuola($this->estranea);
        $this->assertNull($this->creaMappa($this->chiave[$this->estranea]));
        $this->assertSame($this->sezione[$this->prima], $this->creaMappa($this->chiave[$this->prima]));
    }

    // ── Import da PDF: il contratto del documento nuovo (ExerciseInserter) ─

    /**
     * Inserisce un esercizio da una sessione di import già revisionata, come
     * fa «Inserisci» nella revisione dell'import; torna la chiave del
     * contratto del documento nuovo. Il deposito dei contratti è in memoria.
     */
    private function importaUnEsercizio(): string
    {
        $file = new SessionStorage($this->deposito(), $this->crypto());
        $sessioni = new PdfImportSessionRepository();
        // I file di lavoro della sessione stanno nella casa, come in createSession.
        $casa = TeacherContextResolver::privateFilesInstituteId($this->docente);
        $prefisso = SessionStorage::prefixFor($casa, $this->docente, random_int(1, PHP_INT_MAX));
        $sessione = $sessioni->create([
            'teacher_id'     => $this->docente,
            'institute_id'   => $casa,
            'payload_sha256' => hash('sha256', uniqid('casa', true)),
            'storage_prefix' => $prefisso,
        ]);
        $file->putJson($prefisso, 'contracts.json', [[
            'id' => 'r1', 'number' => '1', 'type' => 'Collect', 'topic' => 'Limiti', 'origin' => '',
            'payload' => ['question' => 'Calcola il limite.', 'solution' => ''],
        ]], $this->docente);

        $creati = (new ExerciseInserter())->run(
            $sessione,
            $this->docente,
            ['subject' => self::MATERIA],
            [],
            $sessioni,
            $file
        );
        $this->assertCount(1, $creati);
        $st = $this->pdo->prepare('SELECT metadata_json FROM teacher_content_data WHERE id = ? AND teacher_id = ?');
        $st->execute([$creati[0], $this->docente]);
        $meta = json_decode((string)$st->fetchColumn(), true);
        $chiave = (string)($meta['contract_key'] ?? '');
        $this->assertNotSame('', $chiave, 'il documento nuovo ha un contratto');
        $this->assertTrue($this->deposito->exists($chiave), 'il contratto è scritto dove la riga dice');
        return $chiave;
    }

    private function cartellaDelDocente(int $istituto): string
    {
        return 'institutes/' . $istituto . '/private/' . $this->docente . '/';
    }

    #[Test]
    public function con_la_seconda_attiva_il_contratto_dell_import_va_nella_seconda(): void
    {
        $this->docenteNellaScuola($this->seconda);
        $chiave = $this->importaUnEsercizio();
        $this->assertStringStartsWith(
            $this->cartellaDelDocente($this->seconda),
            $chiave,
            'fino al 23/9/2026: sotto la prima scuola, mentre la riga nasceva nella seconda'
        );
    }

    #[Test]
    public function con_la_prima_attiva_il_contratto_dell_import_va_nella_prima(): void
    {
        $this->docenteNellaScuola($this->prima);
        $this->assertStringStartsWith($this->cartellaDelDocente($this->prima), $this->importaUnEsercizio());
    }

    #[Test]
    public function una_scuola_non_sua_in_sessione_non_riceve_il_contratto_dell_import(): void
    {
        // Confine di ADR-037: niente sotto la scuola estranea, il contratto
        // va nella prima scuola del docente.
        $this->docenteNellaScuola($this->estranea);
        $chiave = $this->importaUnEsercizio();
        $this->assertStringStartsNotWith('institutes/' . $this->estranea . '/', $chiave);
        $this->assertStringStartsWith($this->cartellaDelDocente($this->prima), $chiave);
    }

    // ── Intestazione di studio (StudyHeaderController) ───────────────────

    /** Il docente salva la sua intestazione, come dal bottone «Modifica». */
    private function salvaIntestazione(string $html): void
    {
        $this->deposito();
        $_SERVER['REQUEST_METHOD'] = 'PUT';
        $_SERVER['REQUEST_URI'] = '/api/teacher/header-page.json';
        $corpo = (string)json_encode(['html' => $html, 'auto_citations' => true]);
        $risposta = (new StudyHeaderController())->headerPageSave(new Request($corpo));
        $this->assertSame(200, $risposta->status, 'salvataggio: ' . $risposta->body);
    }

    /** L'intestazione che il docente vede nella scuola attiva. */
    private function intestazioneDelDocente(): string
    {
        $this->deposito();
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['REQUEST_URI'] = '/api/teacher/header-page.json';
        $risposta = (new StudyHeaderController())->headerPageJson(new Request(''));
        $this->assertSame(200, $risposta->status, 'lettura del docente: ' . $risposta->body);
        return (string)(json_decode((string)$risposta->body, true)['html'] ?? '');
    }

    /**
     * L'intestazione del docente vista da uno studente con account della
     * scuola `$scuola`, come la chiede la pagina di studio.
     */
    private function intestazioneDelloStudente(int $scuola): string
    {
        $this->deposito();
        $nome = 'zziast' . strtolower($this->sigla) . $scuola;
        $st = $this->pdo->prepare('SELECT id FROM users WHERE username = ?');
        $st->execute([$nome]);
        $studente = (int)$st->fetchColumn() ?: $this->utenteNuovo($nome, 'student', $scuola);
        $_SESSION = [
            'autenticato' => true, 'username' => $nome, 'user_id' => $studente,
            'user_role' => 'student', 'is_super_admin' => false,
        ];
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['REQUEST_URI'] = '/api/study/header-page.json';
        $_GET = ['teacher_id' => (string)$this->docente];
        $risposta = (new StudyHeaderController())->headerPageStudentJson(new Request(''));
        $_GET = [];
        $this->assertSame(200, $risposta->status, 'lettura dello studente: ' . $risposta->body);
        return (string)(json_decode((string)$risposta->body, true)['html'] ?? '');
    }

    private function fileDellIntestazione(int $scuola): string
    {
        return $this->cartellaDelDocente($scuola) . 'header_page.json';
    }

    #[Test]
    public function con_la_seconda_attiva_l_intestazione_si_salva_nella_seconda(): void
    {
        $this->docenteNellaScuola($this->seconda);
        $this->salvaIntestazione('<p>Per la seconda</p>');
        $this->assertTrue(
            $this->deposito()->exists($this->fileDellIntestazione($this->seconda)),
            'fino al 23/9/2026: si salvava sempre nella prima scuola'
        );
        $this->assertFalse($this->deposito()->exists($this->fileDellIntestazione($this->prima)));
        $this->assertSame('<p>Per la seconda</p>', $this->intestazioneDelDocente());
    }

    #[Test]
    public function gli_studenti_della_seconda_vedono_l_intestazione_scritta_per_la_seconda(): void
    {
        $this->docenteNellaScuola($this->seconda);
        $this->salvaIntestazione('<p>Per la seconda</p>');
        $this->assertSame(
            '<p>Per la seconda</p>',
            $this->intestazioneDelloStudente($this->seconda),
            'fino al 23/9/2026: l intestazione predefinita'
        );
    }

    #[Test]
    public function ogni_scuola_ha_la_sua_intestazione(): void
    {
        $this->docenteNellaScuola($this->prima);
        $this->salvaIntestazione('<p>Per la prima</p>');
        $this->docenteNellaScuola($this->seconda);
        $this->salvaIntestazione('<p>Per la seconda</p>');

        $this->docenteNellaScuola($this->prima);
        $this->assertSame('<p>Per la prima</p>', $this->intestazioneDelDocente());
        $this->docenteNellaScuola($this->seconda);
        $this->assertSame('<p>Per la seconda</p>', $this->intestazioneDelDocente());
        $this->assertSame('<p>Per la prima</p>', $this->intestazioneDelloStudente($this->prima));
        $this->assertSame('<p>Per la seconda</p>', $this->intestazioneDelloStudente($this->seconda));
    }

    #[Test]
    public function una_scuola_non_sua_in_sessione_non_riceve_l_intestazione(): void
    {
        // Confine di ADR-037: con «estranea» in sessione il docente scrive e
        // legge nella sua prima scuola; lo studente dell'estranea non vede
        // l'intestazione di un docente che lì non insegna.
        $this->docenteNellaScuola($this->estranea);
        $this->salvaIntestazione('<p>Scritta con la scuola estranea in sessione</p>');
        $this->assertFalse($this->deposito()->exists($this->fileDellIntestazione($this->estranea)));
        $this->assertTrue($this->deposito()->exists($this->fileDellIntestazione($this->prima)));
        $this->assertSame('<p>Scritta con la scuola estranea in sessione</p>', $this->intestazioneDelDocente());
        $this->assertStringNotContainsString('Scritta con', $this->intestazioneDelloStudente($this->estranea));
    }

    // ── Catalogo delle adozioni: il libro già fra le fonti ───────────────

    private const ISBN = '9788800000017';

    /** Il registro delle fonti del docente, con un libro, sotto la scuola `$scuola`. */
    private function registroSotto(int $scuola): void
    {
        $this->deposito()->put(
            SourcesRegistryStore::chiave($this->docente, $scuola),
            (string)json_encode(['sources' => [['key' => 'libro_di_prova', 'isbn' => self::ISBN]]])
        );
    }

    /** Il catalogo delle adozioni della scuola attiva: il libro risulta già preso? */
    private function ilLibroRisultaPreso(): bool
    {
        (new AdozioniRepository($this->pdo))->inserisci($this->seconda, [
            'anno_scolastico' => '2026/2027', 'classe' => '2A', 'disciplina' => 'MATERIA DI PROVA',
            'isbn' => self::ISBN, 'titolo' => 'Libro di prova ' . $this->sigla,
        ]);
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['REQUEST_URI'] = '/api/teacher/adozioni';
        $_GET = ['tutte' => '1'];
        $risposta = (new TeacherAdozioniController())->index(new Request(''));
        $_GET = [];
        $corpo = json_decode((string)$risposta->body, true);
        $this->assertSame(200, $risposta->status, 'catalogo: ' . $risposta->body);
        $this->assertSame($this->seconda, (int)$corpo['institute_id'], 'il catalogo è quello della scuola attiva');
        $this->assertCount(1, $corpo['libri']);
        return (bool)$corpo['libri'][0]['in_registro'];
    }

    #[Test]
    public function con_la_seconda_attiva_il_catalogo_riconosce_i_libri_del_registro_della_casa(): void
    {
        $this->docenteNellaScuola($this->seconda);
        $this->registroSotto($this->prima);
        $this->assertTrue($this->ilLibroRisultaPreso(), 'fino al 23/9/2026: no, il registro si cercava nella seconda');
    }

    #[Test]
    public function il_catalogo_non_legge_un_registro_fuori_dalla_casa(): void
    {
        // Un registro sotto la scuola attiva non è quello che /area-docente/fonti
        // scrive: non conta.
        $this->docenteNellaScuola($this->seconda);
        $this->registroSotto($this->seconda);
        $this->assertFalse($this->ilLibroRisultaPreso());
    }
}
