<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Core\Config;
use App\Core\Database;
use App\Core\Request;
use App\Core\Response;
use App\Middleware\LegacyGoneMiddleware;
use App\Support\PostoPrincipale;
use PDO;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Le rotte legacy non dicono se un contenuto esiste (23/9/2026, revisione
 * architetturale A-54).
 *
 * LegacyGoneMiddleware mandava a /studio con un 302 solo se trovava nel
 * database un contenuto con le sigle e l'argomento dell'URL, cercato senza
 * filtro per docente, istituto o visibilità; altrimenti 410. /mappe/{path*}
 * è fuori da ogni login: bastava chiedere un URL per sapere se un docente
 * qualunque aveva, anche in bozza, una mappa con quell'argomento.
 *
 * Qui lo stesso URL si chiede prima e dopo aver creato, in bozza, il contenuto
 * che gli corrisponde, di un docente che non è chi chiede: la risposta deve
 * essere identica (stato, destinazione, corpo). Sul codice di prima era 410 e
 * poi 302. Database vero, istituto, sigle e docente con nomi unici, tutto in
 * una transazione annullata alla fine.
 */
final class LegacyGoneSenzaOracoloTest extends TestCase
{
    private PDO $pdo;
    private string $indirizzo = '';
    private string $classe = '';
    private string $materia = '';
    private string $argomento = '';
    /** @var array{indirizzi:int, classi:int, materie:int} */
    private array $voci = ['indirizzi' => 0, 'classi' => 0, 'materie' => 0];

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
            $this->pdo->query('SELECT 1');
        } catch (\Throwable $e) {
            $this->markTestSkipped('DB non disponibile: ' . $e->getMessage());
        }
        $this->pdo->beginTransaction();
        $_SESSION = [];

        $caso = substr(bin2hex(random_bytes(3)), 0, 5);
        $this->pdo->prepare('INSERT INTO institutes (code, name, city, active) VALUES (?, ?, ?, 1)')
            ->execute(['ZZLG' . strtoupper($caso), 'SCUOLA LEGACY ' . $caso, 'Comune Esempio']);
        $scuola = (int)$this->pdo->lastInsertId();

        // Sigle nella forma dei vecchi URL: indirizzo di lettere, classe
        // anno+sezione, materia maiuscola.
        $this->indirizzo = 'zlg' . preg_replace('/[^a-z]/', 'q', $caso);
        $this->classe = '9z';
        $this->materia = 'ZLGM';
        $this->argomento = 'Argomento riservato ' . $caso;
        $voce = $this->pdo->prepare(
            'INSERT INTO curriculum_entries (kind, institute_id, code, label, indirizzo, active, shared_with_pool, origine)
             VALUES (?, ?, ?, ?, ?, 1, 0, "istituto")'
        );
        foreach ([['indirizzi', $this->indirizzo, null], ['classi', $this->classe, $this->indirizzo], ['materie', $this->materia, null]] as [$kind, $code, $corso]) {
            $voce->execute([$kind, $scuola, $code, $code, $corso]);
            $this->voci[$kind] = (int)$this->pdo->lastInsertId();
        }
    }

    protected function tearDown(): void
    {
        $_SESSION = [];
        if (isset($this->pdo) && $this->pdo->inTransaction()) {
            $this->pdo->rollBack();
        }
    }

    /** Una mappa in bozza di un altro docente, con le sigle e l'argomento dell'URL. */
    private function creaLaMappaDiUnAltro(): void
    {
        $nome = 'zzlegacy' . date('His') . bin2hex(random_bytes(3));
        $this->pdo->prepare(
            'INSERT INTO users (username, role, first_name, last_name, email, password_hash, status, active, created_at)
             VALUES (?, "teacher", "Zz", "Legacy", ?, "x", "approved", 1, NOW())'
        )->execute([$nome, "$nome@example.invalid"]);
        $docente = (int)$this->pdo->lastInsertId();
        $this->pdo->prepare(
            'INSERT INTO teacher_content_data (teacher_id, content_subtype, topic, title, metadata_json, visibility)
             VALUES (?, "mappa", ?, ?, "{}", "draft")'
        )->execute([$docente, $this->argomento, $this->argomento]);
        $id = (int)$this->pdo->lastInsertId();
        PostoPrincipale::contenuto($this->pdo, $id, $this->voci['indirizzi'], $this->voci['classi'], $this->voci['materie']);

        // Il contenuto si vede dalla stessa vista che la vecchia ricerca leggeva.
        $st = $this->pdo->prepare(
            'SELECT COUNT(*) FROM teacher_content
              WHERE content_type = "mappa" AND subject_code = ? AND indirizzo = ? AND classe = ? AND topic = ?'
        );
        $st->execute([$this->materia, $this->indirizzo, $this->classe, $this->argomento]);
        self::assertSame(1, (int)$st->fetchColumn(), 'la mappa c\'è, con le sigle dell\'URL');
    }

    private function url(): string
    {
        $sezione = $this->indirizzo . $this->classe;
        return '/mappe/' . $this->indirizzo . '/mappe_' . $sezione . '/' . $this->materia
            . '/1_' . $this->materia . '-' . str_replace(' ', '_', $this->argomento) . '-' . $sezione . '.php';
    }

    /** @return array{0:int, 1:?string, 2:string} */
    private function chiedi(string $percorso, bool $json = false): array
    {
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['REQUEST_URI'] = $percorso;
        unset($_SERVER['HTTP_ACCEPT']);
        if ($json) {
            $_SERVER['HTTP_ACCEPT'] = 'application/json';
        }
        $r = (new LegacyGoneMiddleware())->handle(new Request(''), static fn () => Response::html('mai qui'));
        return [$r->status, $r->headers['Location'] ?? null, $r->body];
    }

    #[Test]
    public function la_risposta_e_la_stessa_che_il_contenuto_esista_o_no(): void
    {
        $prima = $this->chiedi($this->url());
        $primaJson = $this->chiedi($this->url(), true);
        $this->creaLaMappaDiUnAltro();
        $dopo = $this->chiedi($this->url());
        $dopoJson = $this->chiedi($this->url(), true);

        self::assertSame($prima, $dopo, 'la pagina non dice se la mappa esiste');
        self::assertSame($primaJson, $dopoJson, 'nemmeno la risposta JSON');
    }

    #[Test]
    public function un_url_di_forma_nota_va_a_studio_anche_senza_contenuto(): void
    {
        [$stato, $dove] = $this->chiedi($this->url());

        // Se il contenuto c'è e chi chiede lo può vedere lo decide /studio.
        self::assertSame(302, $stato);
        self::assertSame(
            '/studio/mappa/' . $this->indirizzo . '/' . $this->classe . '/' . $this->materia . '/' . rawurlencode($this->argomento),
            $dove
        );
    }
}
