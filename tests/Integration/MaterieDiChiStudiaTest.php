<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Controllers\ContentStudyController;
use App\Core\Database;
use App\Core\Request;
use App\Core\Response;
use App\Middleware\StudioMiddleware;
use App\Repositories\TeacherContentRepository;
use App\Services\Study\MaterieConMateriali;
use App\Services\TeacherSectionService;
use App\Support\ClassAccessGrant;
use PDO;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Le materie che chi studia trova nel selettore della barra (19/9/2026).
 *
 * Prima il selettore mostrava tutto il vocabolario attivo della scuola: in
 * produzione, alla credenziale di una terza, diciassette materie di cui
 * quindici vuote. Adesso solo quelle con almeno un materiale che chi guarda
 * può vedere, calcolate con lo stesso gate dell'elenco: una bozza non conta,
 * un pubblicato di un docente fuori dal portachiavi nemmeno. Senza nessuna
 * materia, al posto del selettore c'è un avviso. Al cambio di classe (anni
 * frequentati) il selettore si ricalcola da GET /api/study/materie.json.
 *
 * Fixture isolata in transazione (rollback in tearDown): una scuola con tre
 * materie nel vocabolario, il docente della credenziale e un docente estraneo.
 */
final class MaterieDiChiStudiaTest extends TestCase
{
    private PDO $pdo;
    private bool $inTx = false;
    private TeacherContentRepository $contenuti;
    private int $scuola = 0;
    private int $docente = 0;
    private int $estraneo = 0;
    private int $credenziale = 0;

    private const IND = 'ZSM';
    private const AVVISO = 'Non ci sono ancora materiali pubblicati per la tua classe.';

    protected function setUp(): void
    {
        $base = dirname(__DIR__, 2);
        foreach (['.env', '.env.local'] as $f) {
            if (is_file("$base/$f")) {
                \Dotenv\Dotenv::createMutable($base, $f)->safeLoad();
            }
        }
        \App\Core\Config::load($base . '/app/Config');
        try {
            $this->pdo = Database::connection();
            $this->pdo->query('SELECT 1 FROM teacher_access_credentials_data LIMIT 1');
        } catch (\Throwable $e) {
            $this->markTestSkipped('DB non disponibile: ' . $e->getMessage());
        }
        $this->pdo->beginTransaction();
        $this->inTx = true;
        $this->pulisciLaRichiesta();

        $this->pdo->prepare('INSERT INTO institutes (code, name, city, active) VALUES (?, ?, ?, 1)')
            ->execute(['ZZMDS001', 'SCUOLA MATERIE DI CHI STUDIA', 'Comune Esempio']);
        $this->scuola = (int)$this->pdo->lastInsertId();
        // ADR-041 — la prova non riguarda chi dei docenti può usare le sezioni.
        $this->pdo->prepare("UPDATE institutes SET sezioni_docenti = 'tutti' WHERE id = ?")->execute([$this->scuola]);
        $voce = $this->pdo->prepare(
            'INSERT INTO curriculum_entries (kind, institute_id, code, label, indirizzo, active, shared_with_pool)
             VALUES (?, ?, ?, ?, ?, 1, 0)'
        );
        foreach ([
            ['indirizzi', self::IND, 'Zeta scientifico', null],
            ['classi', '1', 'Prima', self::IND],
            ['classi', '2', 'Seconda', self::IND],
            ['materie', 'ZMA', 'Zeta matematica', null],
            ['materie', 'ZFI', 'Zeta fisica', null],
            ['materie', 'ZGE', 'Zeta geografia', null],
        ] as [$kind, $code, $label, $ind]) {
            $voce->execute([$kind, $this->scuola, $code, $label, $ind]);
        }
        $this->docente  = $this->nuovoDocente('zzmds_doc');
        $this->estraneo = $this->nuovoDocente('zzmds_altro');
        $this->contenuti = new TeacherContentRepository();

        $this->pdo->prepare(
            'INSERT INTO teacher_access_credentials_data (teacher_id, label, access_username, password_hash, institute_id, active)
             VALUES (?, "Seconda", ?, "x", ?, 1)'
        )->execute([$this->docente, 'zzmds_' . uniqid(), $this->scuola]);
        $this->credenziale = (int)$this->pdo->lastInsertId();
    }

    protected function tearDown(): void
    {
        if ($this->inTx && $this->pdo->inTransaction()) {
            $this->pdo->rollBack();
        }
        $this->pulisciLaRichiesta();
        unset($_SERVER['HTTP_ACCEPT'], $_SERVER['HTTP_X_PARTIAL']);
    }

    private function pulisciLaRichiesta(): void
    {
        $_SESSION = [];
        $_GET = [];
        ClassAccessGrant::resetCache();
        MaterieConMateriali::dimentica();
    }

    private function nuovoDocente(string $username): int
    {
        $this->pdo->prepare(
            'INSERT INTO users (username, role, first_name, last_name, email, password_hash, status, active, created_at)
             VALUES (?, "teacher", "Zz", ?, ?, "x", "approved", 1, NOW())'
        )->execute([$username, $username, $username . '@example.invalid']);
        $id = (int)$this->pdo->lastInsertId();
        $this->pdo->prepare('INSERT INTO teacher_institutes (user_id, institute_id) VALUES (?, ?)')->execute([$id, $this->scuola]);
        return $id;
    }

    private function materiale(int $docente, string $materia, string $classe, string $stato = 'published'): int
    {
        return $this->contenuti->create([
            'teacher_id'   => $docente,
            'content_type' => 'esercizio',
            'subject_code' => $materia,
            'indirizzo'    => self::IND,
            'classe'       => $classe,
            'topic'        => 'MDS_' . uniqid(),
            'title'        => "Esercizio $materia $classe",
            'body_html'    => '<p>x</p>',
            'visibility'   => $stato,
        ]);
    }

    private function conCredenziale(): void
    {
        $_SESSION = [];
        ClassAccessGrant::resetCache();
        ClassAccessGrant::add([
            'teacher_id' => $this->docente, 'institute_id' => $this->scuola, 'indirizzo' => self::IND, 'classe' => '2',
            'label' => 'Seconda', 'credential_id' => $this->credenziale, 'granted_at' => time(),
        ]);
        ClassAccessGrant::resetCache();
    }

    private function comeStudente(): void
    {
        $this->pdo->prepare(
            'INSERT INTO users (username, role, first_name, last_name, email, password_hash, status, active, created_at,
                                institute_id, indirizzo, classe)
             VALUES ("zzmds_stud", "student", "Zz", "Studente", "zzmds_stud@example.invalid", "x", "approved", 1, NOW(), ?, ?, "2")'
        )->execute([$this->scuola, self::IND]);
        $id = (int)$this->pdo->lastInsertId();
        $_SESSION = ['autenticato' => true, 'username' => 'zzmds_stud', 'user_id' => $id, 'user_role' => 'student'];
        ClassAccessGrant::resetCache();
    }

    private function barra(): string
    {
        $_GET = [];
        $_SERVER['HTTP_X_PARTIAL'] = '';
        $pageTitle = 'Prova';
        $pageContent = '<div id="prova">x</div>';
        $pageScripts = '';
        ob_start();
        include dirname(__DIR__, 2) . '/views/layout/app.php';
        return (string)ob_get_clean();
    }

    /** @return list<string> i codici offerti dal selettore, in ordine alfabetico */
    private function materieNelSelettore(string $html): array
    {
        $this->assertSame(1, preg_match('~<select[^>]*id="sel-mater"[^>]*>(.*?)</select>~s', $html, $m), 'il selettore delle materie è nella barra');
        preg_match_all('~<option value="([^"]+)"~', $m[1], $o);
        $codici = $o[1];
        sort($codici);
        return $codici;
    }

    private function tagDelSelettore(string $html): string
    {
        preg_match('~<select[^>]*id="sel-mater"[^>]*>~', $html, $m);
        return $m[0] ?? '';
    }

    /** @return array{0:int,1:array<string,mixed>} */
    private function chiediLeMaterie(?string $classe): array
    {
        $_GET = $classe === null ? [] : ['classe' => $classe, 'indirizzo' => self::IND];
        $_SERVER['HTTP_ACCEPT'] = 'application/json';
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['REQUEST_URI'] = '/api/study/materie.json';
        ClassAccessGrant::resetCache();
        $risposta = (new StudioMiddleware())->handle(
            new Request(),
            static fn(Request $r): Response => (new ContentStudyController())->materieJson($r)
        );
        $corpo = json_decode($risposta->body, true);
        return [$risposta->status, \is_array($corpo) ? $corpo : []];
    }

    /** @param array<string,mixed> $corpo @return list<string> */
    private static function codici(array $corpo): array
    {
        $c = array_map(static fn(array $m): string => (string)$m['code'], $corpo['materie'] ?? []);
        sort($c);
        return $c;
    }

    #[Test]
    public function con_la_credenziale_il_selettore_offre_solo_le_materie_con_materiali(): void
    {
        $this->materiale($this->docente, 'ZMA', '2');
        $this->materiale($this->docente, 'ZFI', '2', 'draft');
        $this->materiale($this->estraneo, 'ZGE', '2');
        $this->conCredenziale();

        $html = $this->barra();
        $this->assertSame(['ZMA'], $this->materieNelSelettore($html),
            'una bozza non conta, e nemmeno il pubblicato di un docente fuori dal portachiavi');
        $this->assertStringContainsString('data-fm-materie-di-chi-studia="1"', $this->tagDelSelettore($html),
            'il segno dice ai pannelli che le voci sono tutte e sole le materie da chiedere');
        $this->assertStringNotContainsString(' hidden', $this->tagDelSelettore($html), 'con una materia il selettore si vede');

        // Nell'altro verso: pubblicata la fisica, la fisica compare.
        $this->materiale($this->docente, 'ZFI', '2');
        MaterieConMateriali::dimentica();
        $this->assertSame(['ZFI', 'ZMA'], $this->materieNelSelettore($this->barra()));
    }

    #[Test]
    public function senza_materiali_al_posto_del_selettore_c_e_un_avviso(): void
    {
        $this->materiale($this->docente, 'ZMA', '2', 'draft');
        $this->conCredenziale();

        $html = $this->barra();
        $this->assertSame([], $this->materieNelSelettore($html), 'nessuna materia da offrire');
        $this->assertStringContainsString(' hidden', $this->tagDelSelettore($html), 'il selettore resta nel DOM, nascosto');
        $this->assertMatchesRegularExpression('~<p[^>]*id="fm-materie-avviso"(?![^>]*hidden)[^>]*>' . preg_quote(self::AVVISO, '~') . '~', $html,
            'e al suo posto l\'avviso, visibile');

        $this->materiale($this->docente, 'ZMA', '2');
        MaterieConMateriali::dimentica();
        $html = $this->barra();
        $this->assertMatchesRegularExpression('~<p[^>]*id="fm-materie-avviso"[^>]*hidden~', $html, 'con un materiale l\'avviso si nasconde');
    }

    #[Test]
    public function lo_studente_con_account_vede_le_materie_con_materiali_della_sua_classe(): void
    {
        // Scenario 3: il docente raggiunge lo studente con un incarico nella sua classe.
        (new TeacherSectionService())->assign($this->docente, $this->scuola, self::IND, '2');
        $this->materiale($this->docente, 'ZMA', '2');
        $this->materiale($this->estraneo, 'ZGE', '2');
        $this->comeStudente();

        $this->assertSame(['ZMA'], $this->materieNelSelettore($this->barra()),
            'il docente senza incarico nella sua classe non gli arriva, quindi la sua materia nemmeno');
    }

    #[Test]
    public function l_api_ricalcola_le_materie_per_la_classe_chiesta(): void
    {
        [$stato] = $this->chiediLeMaterie('2');
        $this->assertSame(401, $stato, 'senza credenziale il gruppo dello studio non lascia passare');

        $this->materiale($this->docente, 'ZMA', '2');
        $this->materiale($this->docente, 'ZFI', '1');
        $this->conCredenziale();

        [$stato, $corpo] = $this->chiediLeMaterie('2');
        $this->assertSame(200, $stato);
        $this->assertTrue($corpo['filtrate'] ?? null);
        $this->assertSame(['ZMA'], self::codici($corpo), 'la seconda, la classe della credenziale');

        [, $corpo] = $this->chiediLeMaterie('1');
        $this->assertSame(['ZFI'], self::codici($corpo), 'la prima, anno già fatto: la risposta non è quella di prima in cache');

        [, $corpo] = $this->chiediLeMaterie('3');
        $this->assertSame(['ZMA'], self::codici($corpo), 'un anno futuro non è una classe frequentata: vale la propria');
    }

    #[Test]
    public function la_risposta_si_tiene_per_un_minuto_e_poi_si_ricalcola(): void
    {
        $this->materiale($this->docente, 'ZMA', '2');
        $this->conCredenziale();
        $this->assertSame(['ZMA'], self::codici($this->chiediLeMaterie('2')[1]));

        $this->materiale($this->docente, 'ZGE', '2');
        $perRichiesta = new \ReflectionProperty(MaterieConMateriali::class, 'perRichiesta');
        $perRichiesta->setValue(null, []);
        $this->assertSame(['ZMA'], self::codici($this->chiediLeMaterie('2')[1]),
            'dentro la finestra risponde la sessione, anche a una richiesta nuova');

        // La finestra passa: si invecchia la voce in sessione.
        foreach ($_SESSION[MaterieConMateriali::CHIAVE_SESSIONE] as $chiave => $voce) {
            $_SESSION[MaterieConMateriali::CHIAVE_SESSIONE][$chiave]['t'] = time() - MaterieConMateriali::FINESTRA;
        }
        $perRichiesta->setValue(null, []);
        $this->assertSame(['ZGE', 'ZMA'], self::codici($this->chiediLeMaterie('2')[1]), 'passato il minuto, la geografia arriva');
    }

    #[Test]
    public function a_chi_non_studia_l_api_non_filtra_niente(): void
    {
        $_SESSION = ['autenticato' => true, 'username' => 'zzmds_doc', 'user_id' => $this->docente, 'user_role' => 'teacher'];
        [$stato, $corpo] = $this->chiediLeMaterie('2');
        $this->assertSame(200, $stato, 'il docente passa dal gruppo dello studio');
        $this->assertFalse($corpo['filtrate'] ?? null, 'ma il suo selettore non si tocca');
        $this->assertSame([], $corpo['materie'] ?? null);
    }
}
