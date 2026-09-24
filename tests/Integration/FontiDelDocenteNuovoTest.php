<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Controllers\StudySourcesController;
use App\Core\Database;
use App\Core\Request;
use App\Support\Sources\SourcesRegistryStore;
use App\Support\Storage\LocalStorageProvider;
use App\Support\Storage\StorageFactory;
use PDO;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

/**
 * Le fonti di un docente nuovo (23/9/2026, revisione architetturale 2026-09,
 * rilievo A-43).
 *
 * `StudySourcesController::sourcesCommonJson` cercava un modello di fonti,
 * `.github/workflows/deploy/config/SOURCES_COMMON.json`, cancellato il 24/6
 * ed escluso dall'immagine. Senza il file ripiegava in silenzio: scriveva un
 * registro vuoto al primo GET (da lì un docente che non ha mai scelto le
 * fonti non si distingueva da uno che le ha tolte tutte) e rispondeva
 * `{"sources":[]}`, una lista al posto dell'oggetto per codice. La pagina
 * delle fonti, se ci arrivava per prima, riceveva 404 dal registro e mostrava
 * «Errore».
 *
 * L'elenco giusto per un docente nuovo è vuoto — il modello era l'elenco dei
 * libri di un docente, e i libri da cui partire oggi sono quelli del catalogo
 * delle adozioni (ADR-036), che si aggiungono dalla pagina — ma detto:
 * `registro: "assente"`, un oggetto `{}`, nessun file scritto; e il registro
 * vuoto con 200, che la pagina mostra come «Nessuna fonte registrata».
 *
 * Qui il controller vero, con il docente sul database locale (in una
 * transazione annullata alla fine) e il deposito in una cartella temporanea.
 *
 * Controprova (23/9/2026): con lo StudySourcesController di origin/main
 * falliscono le prove del docente nuovo (risposta `{"sources":[]}`, registro
 * scritto, 404 dal registro) e quella del registro vuoto (`[]`).
 *
 * Più sotto il docente che ha solo il vecchio `sources.json`: le sue fonti
 * non si perdono quando aggiunge un libro dal catalogo.
 */
final class FontiDelDocenteNuovoTest extends TestCase
{
    private PDO $pdo;
    private bool $inTx = false;
    private string $deposito = '';
    private int $docente = 0;
    private int $scuola = 0;

    protected function setUp(): void
    {
        $base = \dirname(__DIR__, 2);
        foreach (['.env', '.env.local'] as $f) {
            if (is_file("$base/$f")) {
                \Dotenv\Dotenv::createMutable($base, $f)->safeLoad();
            }
        }
        \App\Core\Config::load($base . '/app/Config');
        try {
            $this->pdo = Database::connection();
            $this->pdo->query('SELECT 1 FROM teacher_institutes LIMIT 1');
        } catch (\Throwable $e) {
            $this->markTestSkipped('DB non disponibile: ' . $e->getMessage());
        }
        $this->pdo->beginTransaction();
        $this->inTx = true;

        $marca = substr((string)hrtime(true), -9);
        $this->pdo->prepare('INSERT INTO institutes (code, name, city, active) VALUES (?, ?, "Comune Esempio", 1)')
            ->execute(['ZZFONTI' . $marca, 'Scuola delle fonti ' . $marca]);
        $this->scuola = (int)$this->pdo->lastInsertId();
        $utente = 'zzfonti' . $marca;
        $this->pdo->prepare(
            'INSERT INTO users (username, role, first_name, last_name, email, password_hash, status, active, created_at)
             VALUES (?, "teacher", "Zz", "Fonti", ?, "x", "approved", 1, NOW())'
        )->execute([$utente, $utente . '@example.invalid']);
        $this->docente = (int)$this->pdo->lastInsertId();
        $this->pdo->prepare('INSERT INTO teacher_institutes (user_id, institute_id) VALUES (?, ?)')
            ->execute([$this->docente, $this->scuola]);

        $this->deposito = sys_get_temp_dir() . '/pantedu-fonti-' . bin2hex(random_bytes(6));
        mkdir($this->deposito, 0700, true);
        (new ReflectionProperty(StorageFactory::class, 'memo'))
            ->setValue(null, new LocalStorageProvider(rootDir: $this->deposito, signingSecret: 'prova'));

        $_SESSION = [
            'autenticato' => true,
            'username'    => $utente,
            'user_id'     => $this->docente,
            'user_role'   => 'teacher',
        ];
    }

    protected function tearDown(): void
    {
        if ($this->inTx && $this->pdo->inTransaction()) {
            $this->pdo->rollBack();
        }
        StorageFactory::reset();
        $_SESSION = [];
        $this->svuota($this->deposito);
    }

    private function svuota(string $cartella): void
    {
        if ($cartella === '' || !is_dir($cartella)) {
            return;
        }
        foreach (scandir($cartella) ?: [] as $voce) {
            if ($voce === '.' || $voce === '..') {
                continue;
            }
            $p = $cartella . '/' . $voce;
            is_dir($p) ? $this->svuota($p) : @unlink($p);
        }
        @rmdir($cartella);
    }

    private function chiave(): string
    {
        return SourcesRegistryStore::chiave($this->docente, $this->scuola);
    }

    /** @param list<array<string,string>> $fonti */
    private function registro(array $fonti): void
    {
        StorageFactory::default()->put($this->chiave(), (string)json_encode([
            '$schema' => 'pantedu.sources.v1', 'count' => \count($fonti), 'sources' => $fonti,
        ]));
    }

    private function richiesta(string $percorso): Request
    {
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['REQUEST_URI'] = $percorso;
        return new Request('');
    }

    #[Test]
    public function un_docente_nuovo_riceve_un_elenco_vuoto_dichiarato_e_niente_file(): void
    {
        $risposta = (new StudySourcesController())->sourcesCommonJson($this->richiesta('/api/teacher/sources.json'));

        $this->assertSame(200, $risposta->status);
        $this->assertFalse(StorageFactory::default()->exists($this->chiave()), 'il GET non scrive un registro vuoto');
        $this->assertSame('{"sources":{},"registro":"assente"}', (string)$risposta->body, 'un oggetto vuoto, e detto');
    }

    #[Test]
    public function il_registro_di_un_docente_nuovo_e_vuoto_non_un_404(): void
    {
        $risposta = (new StudySourcesController())->sourcesRegistryJson($this->richiesta('/api/teacher/sources.registry.json'));

        $this->assertSame(200, $risposta->status);
        $corpo = json_decode((string)$risposta->body, true);
        $this->assertSame([], $corpo['sources'] ?? null);
        $this->assertSame('assente', $corpo['registro'] ?? null);
    }

    /** Il verso opposto: chi ha un registro riceve le sue fonti, e nessun segnale di «assente». */
    #[Test]
    public function chi_ha_un_registro_riceve_le_sue_fonti_per_codice(): void
    {
        $this->registro([['key' => 'zz_libro', 'book' => 'Libro di prova', 'volume' => 'Vol.1 - EDITORE', 'authors' => 'A. Autore']]);

        $comune = (new StudySourcesController())->sourcesCommonJson($this->richiesta('/api/teacher/sources.json'));
        $registro = (new StudySourcesController())->sourcesRegistryJson($this->richiesta('/api/teacher/sources.registry.json'));

        $this->assertSame([
            'sources' => ['zz_libro' => [
                'code' => 'zz_libro', 'title' => 'Libro di prova', 'volume' => 'Vol.1',
                'publisher' => 'EDITORE', 'authors' => 'A. Autore',
            ]],
        ], json_decode((string)$comune->body, true));
        $this->assertSame(200, $registro->status);
        $this->assertArrayNotHasKey('registro', json_decode((string)$registro->body, true));
    }

    /** Un registro che c'è ma è vuoto (fonti tolte tutte): oggetto vuoto, non una lista. */
    #[Test]
    public function un_registro_vuoto_da_un_oggetto_vuoto(): void
    {
        $this->registro([]);

        $risposta = (new StudySourcesController())->sourcesCommonJson($this->richiesta('/api/teacher/sources.json'));

        $this->assertSame('{"sources":{}}', (string)$risposta->body);
    }

    /**
     * Un codice che PHP legge come indice di lista (`"0"`) resta una chiave
     * dell'oggetto: `[{…}]` perdeva il codice, e il client che ci aggiunge una
     * chiave e rimanda l'elenco la perdeva al salvataggio.
     */
    #[Test]
    public function un_codice_numerico_resta_una_chiave_dell_oggetto(): void
    {
        $this->registro([['key' => '0', 'book' => 'Libro zero', 'volume' => '', 'authors' => '']]);

        $risposta = (new StudySourcesController())->sourcesCommonJson($this->richiesta('/api/teacher/sources.json'));

        $this->assertStringStartsWith('{"sources":{"0":{', (string)$risposta->body);
    }

    // ── Il docente che ha solo il vecchio sources.json ────────────────────
    //
    // 23/9/2026, dalla verifica del ramo: il registro assente rispondeva 200
    // «assente» con zero fonti anche a chi ha il vecchio file. «Aggiungi» dal
    // catalogo delle adozioni legge il registro, ci mette il libro e lo
    // riscrive: nasceva un registro con il solo libro nuovo, e il registro ha
    // la precedenza sul vecchio file, quindi le fonti di prima sparivano dal
    // selettore dell'origine. Adesso il registro assente porta le fonti del
    // vecchio file, convertite, e il registro nasce al primo salvataggio con
    // quello che la pagina ha mostrato.
    //
    // Controprove (23/9/2026): con lo StudySourcesController di cc8e633a (la
    // punta del ramo prima di questa correzione, con il solo corpo letto
    // dalla Request) falliscono tutte e tre le prove qui sotto — resta il
    // solo libro nuovo, il registro risponde zero fonti — e quella del codice
    // "0" qui sopra; con un salvataggio che unisce d'ufficio il vecchio file
    // al primo registro (la mutazione provata) fallisce la terza.

    /** Il vecchio file, nella forma per codice che scriveva l'editor. */
    private function vecchioFile(): void
    {
        StorageFactory::default()->put(
            "institutes/{$this->scuola}/private/{$this->docente}/sources.json",
            (string)json_encode(['sources' => [
                'zz_vecchio1' => ['code' => 'zz_vecchio1', 'title' => 'Primo libro', 'volume' => 'Vol.1', 'publisher' => 'EDITORE', 'authors' => 'A. Primo'],
                'zz_vecchio2' => ['code' => 'zz_vecchio2', 'title' => 'Secondo libro', 'volume' => 'Vol.2', 'publisher' => 'ALTRO', 'authors' => 'B. Secondo'],
            ]])
        );
    }

    /**
     * Come fa «Aggiungi» della pagina delle fonti (js/entries/area-docente-fonti.js,
     * aggiungiAlleFonti): legge il registro, ci mette la proposta, lo riscrive.
     *
     * @param array<string,string> $proposta
     */
    private function aggiungiDalCatalogo(array $proposta): void
    {
        $letto = (new StudySourcesController())->sourcesRegistryJson($this->richiesta('/api/teacher/sources.registry.json'));
        $this->assertSame(200, $letto->status);
        $fonti = json_decode((string)$letto->body, true)['sources'] ?? [];
        $fonti[] = $proposta;

        $_SERVER['REQUEST_METHOD'] = 'PUT';
        $scritto = (new StudySourcesController())->sourcesRegistrySave(new Request((string)json_encode(['sources' => $fonti])));
        $this->assertSame(200, $scritto->status, (string)$scritto->body);
    }

    #[Test]
    public function chi_ha_solo_il_vecchio_file_aggiunge_un_libro_dal_catalogo_e_non_perde_le_sue_fonti(): void
    {
        $this->vecchioFile();

        $this->aggiungiDalCatalogo([
            'key' => 'zz_catalogo', 'book' => 'Libro del catalogo', 'volume' => 'Vol.3 - EDITORE',
            'authors' => 'C. Terzo', 'isbn' => '9788800000000', 'editore' => 'EDITORE',
        ]);

        $comune = json_decode((string)(new StudySourcesController())->sourcesCommonJson($this->richiesta('/api/teacher/sources.json'))->body, true);
        $this->assertSame(['zz_vecchio1', 'zz_vecchio2', 'zz_catalogo'], array_keys($comune['sources'] ?? []), 'le fonti di prima accanto a quella nuova');
        $this->assertSame([
            'code' => 'zz_vecchio2', 'title' => 'Secondo libro', 'volume' => 'Vol.2', 'publisher' => 'ALTRO', 'authors' => 'B. Secondo',
        ], $comune['sources']['zz_vecchio2']);
        $origini = json_decode((string)(new StudySourcesController())->originsJson($this->richiesta('/api/teacher/origins.json'))->body, true);
        $this->assertSame(['zz_catalogo', 'zz_vecchio1', 'zz_vecchio2'], $origini);
    }

    /** La pagina delle fonti mostra quelle del vecchio file, e dice da dove vengono; nessun file scritto. */
    #[Test]
    public function il_registro_di_chi_ha_solo_il_vecchio_file_porta_le_sue_fonti(): void
    {
        $this->vecchioFile();

        $risposta = (new StudySourcesController())->sourcesRegistryJson($this->richiesta('/api/teacher/sources.registry.json'));

        $this->assertSame(200, $risposta->status);
        $corpo = json_decode((string)$risposta->body, true);
        $this->assertSame([
            ['key' => 'zz_vecchio1', 'book' => 'Primo libro', 'volume' => 'Vol.1 - EDITORE', 'authors' => 'A. Primo'],
            ['key' => 'zz_vecchio2', 'book' => 'Secondo libro', 'volume' => 'Vol.2 - ALTRO', 'authors' => 'B. Secondo'],
        ], $corpo['sources'] ?? null);
        $this->assertSame(2, $corpo['count'] ?? null);
        $this->assertSame('da-sources-json', $corpo['registro'] ?? null);
        $this->assertFalse(StorageFactory::default()->exists($this->chiave()), 'il GET non scrive il registro');
    }

    /**
     * Il verso opposto: il vecchio file non si rimette nel registro a ogni
     * costo. Chi toglie una fonte vecchia al primo salvataggio la vede
     * sparire: il registro scritto è quello che la pagina ha mandato. (Un
     * salvataggio che unisse d'ufficio il vecchio file la farebbe tornare.)
     */
    #[Test]
    public function chi_toglie_una_fonte_vecchia_al_primo_salvataggio_la_toglie_davvero(): void
    {
        $this->vecchioFile();
        $letto = json_decode((string)(new StudySourcesController())->sourcesRegistryJson($this->richiesta('/api/teacher/sources.registry.json'))->body, true);
        $restano = array_values(array_filter($letto['sources'], static fn(array $f): bool => $f['key'] !== 'zz_vecchio1'));

        $_SERVER['REQUEST_METHOD'] = 'PUT';
        $scritto = (new StudySourcesController())->sourcesRegistrySave(new Request((string)json_encode(['sources' => $restano])));
        $this->assertSame(200, $scritto->status);

        $comune = json_decode((string)(new StudySourcesController())->sourcesCommonJson($this->richiesta('/api/teacher/sources.json'))->body, true);
        $this->assertSame(['zz_vecchio2'], array_keys($comune['sources'] ?? []));
    }
}
