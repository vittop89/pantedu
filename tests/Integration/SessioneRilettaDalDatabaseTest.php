<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Core\Auth;
use App\Core\Config;
use App\Core\Database;
use App\Core\Kernel;
use App\Core\Request;
use App\Core\Response;
use App\Core\Router;
use App\Middleware\AuthMiddleware;
use PDO;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Una sessione aperta segue l'account nel database, al più con un minuto di
 * ritardo (23/9/2026).
 *
 * Revisione architetturale 2026-09:
 *   - A-66: disattivare un account o cambiargli il ruolo non toccava le
 *     sessioni aperte. `Auth::check()` e `Auth::role()` leggono la sessione, e
 *     `active` si guardava solo al login: un utente disattivato restava dentro
 *     fino a dodici ore, con il ruolo di prima;
 *   - A-47: il flag di super-admin aveva due cache, e quella che `role:admin`
 *     guardava durava quanto la sessione;
 *   - A-79: le rotte /me/* non passavano da AuthMiddleware, quindi il
 *     confinamento di `must_change_password` non valeva lì.
 *
 * Il tempo si simula spostando indietro `claims_at` di un minuto e un secondo:
 * è l'ora dell'ultima lettura che la sessione si segna, nessuna attesa vera.
 * Tutto in transazione, con un utente dal nome unico: rollback in tearDown,
 * anche quando una prova fallisce.
 */
final class SessioneRilettaDalDatabaseTest extends TestCase
{
    private PDO $pdo;
    private bool $inTx = false;
    private string $utente = '';
    private int $id = 0;
    /** @var array<string, mixed> */
    private array $serverPrima = [];
    private mixed $dbPrima = null;

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
        $this->dbPrima = Config::get('database.enabled');
        $this->pdo->beginTransaction();
        $this->inTx = true;

        $this->utente = 'zz_sessione_' . date('YmdHis') . '_' . bin2hex(random_bytes(3));
        $this->pdo->prepare(
            'INSERT INTO users (username, role, first_name, last_name, email, password_hash, status, active, is_super_admin, created_at)
             VALUES (?, "teacher", "Zz", "Sessione", ?, "x", "approved", 1, 0, NOW())'
        )->execute([$this->utente, $this->utente . '@example.invalid']);
        $this->id = (int)$this->pdo->lastInsertId();

        $this->serverPrima = $_SERVER;
        $_SESSION = [];
    }

    protected function tearDown(): void
    {
        if ($this->inTx && $this->pdo->inTransaction()) {
            $this->pdo->rollBack();
        }
        Config::set('database.enabled', $this->dbPrima);
        $_SERVER = $this->serverPrima;
        $_SESSION = [];
    }

    /** Una sessione come la lascia il login, con i claims appena letti. */
    private function entra(): void
    {
        $_SESSION = [
            'autenticato'    => true,
            'username'       => $this->utente,
            'user_id'        => $this->id,
            'user_role'      => 'teacher',
            'is_super_admin' => false,
            'active'         => true,
            'claims_at'      => time(),
        ];
    }

    /** È passato un minuto e un secondo dall'ultima lettura. */
    private function passaUnMinuto(): void
    {
        $_SESSION['claims_at'] = time() - Auth::CLAIMS_TTL_SECONDS - 1;
    }

    private function cambia(string $colonna, int|string $valore): void
    {
        $this->pdo->prepare("UPDATE users SET $colonna = ? WHERE id = ?")->execute([$valore, $this->id]);
    }

    /** Una richiesta che passa da AuthMiddleware: 200 se la lascia passare. */
    private function bussa(string $percorso = '/api/teacher/prova', bool $json = true): Response
    {
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['REQUEST_URI'] = $percorso;
        $_SERVER['HTTP_ACCEPT'] = $json ? 'application/json' : 'text/html';
        return (new AuthMiddleware())->handle(
            new Request(),
            static fn(Request $r): Response => Response::json(['ok' => true])
        );
    }

    /** La catena vera dei middleware di una rotta di routes/web.php. */
    private function rotta(string $metodo, string $percorso): Response
    {
        $router = new Router();
        require dirname(__DIR__, 2) . '/routes/web.php';
        $_SERVER['REQUEST_METHOD'] = $metodo;
        $_SERVER['REQUEST_URI'] = $percorso;
        $_SERVER['HTTP_ACCEPT'] = 'application/json';
        $req = new Request();
        $trovata = $router->match($req);
        self::assertNotNull($trovata, "$metodo $percorso esiste");
        $catena = new \ReflectionMethod(Kernel::class, 'buildPipeline');
        return $catena->invoke(new Kernel($router), $trovata, $req)($req);
    }

    private function selectFatte(): int
    {
        $riga = $this->pdo->query("SHOW SESSION STATUS LIKE 'Com_select'")->fetch(PDO::FETCH_NUM);
        return (int)($riga[1] ?? -1);
    }

    #[Test]
    public function l_account_disattivato_resta_fuori_passato_un_minuto(): void
    {
        $this->entra();
        $this->cambia('active', 0);

        self::assertSame(200, $this->bussa()->status, 'entro il minuto la sessione non lo sa ancora: è il ritardo dichiarato');

        $this->passaUnMinuto();
        $risposta = $this->bussa();
        self::assertSame(401, $risposta->status, 'passato il minuto, fuori');
        self::assertSame(['error' => 'unauthenticated'], json_decode($risposta->body, true), 'la stessa risposta di chi non ha sessione');
        self::assertFalse(Auth::check(), 'e la sessione è chiusa');
        self::assertSame([], $_SESSION);

        // La traccia nel registro delle operazioni (in transazione anche lei).
        $st = $this->pdo->prepare(
            "SELECT outcome, details_json FROM audit_activity_log
              WHERE action = 'session_revoked' AND subject_id = ? ORDER BY id DESC LIMIT 1"
        );
        $st->execute([$this->utente]);
        $riga = $st->fetch(PDO::FETCH_ASSOC);
        self::assertIsArray($riga, 'la chiusura è registrata');
        self::assertSame('denied', $riga['outcome']);
        self::assertSame(['motivo' => 'account_disattivato'], json_decode((string)$riga['details_json'], true));

        // Una pagina, invece di un'API: al login, come chi non ha sessione.
        $this->entra();
        $this->passaUnMinuto();
        $pagina = $this->bussa('/area-docente/dashboard', json: false);
        self::assertSame(302, $pagina->status);
        self::assertSame('/login?redirect=%2Farea-docente%2Fdashboard', $pagina->headers['Location'] ?? null);
    }

    #[Test]
    public function l_account_cancellato_resta_fuori(): void
    {
        $this->entra();
        $this->pdo->prepare('DELETE FROM users WHERE id = ?')->execute([$this->id]);
        $this->passaUnMinuto();
        self::assertSame(401, $this->bussa()->status);
        self::assertFalse(Auth::check());
    }

    /**
     * L'altro verso: l'account attivo e invariato passa, anche dopo il minuto,
     * e la lettura rinnova l'ora. Se il database non risponde non si butta
     * fuori nessuno e non si segna la lettura: si riprova alla richiesta dopo.
     */
    #[Test]
    public function l_account_attivo_resta_dentro_anche_se_il_database_non_risponde(): void
    {
        $this->entra();
        $this->passaUnMinuto();
        self::assertSame(200, $this->bussa()->status);
        self::assertTrue(Auth::check());
        self::assertGreaterThanOrEqual(time() - 1, $_SESSION['claims_at'], 'la lettura è segnata adesso');

        $this->passaUnMinuto();
        $vecchia = $_SESSION['claims_at'];
        Config::set('database.enabled', false);
        self::assertSame(200, $this->bussa()->status, 'database spento: la sessione resta');
        self::assertSame($vecchia, $_SESSION['claims_at'], 'e la lettura non si segna');
    }

    /**
     * Un ruolo cambiato non si prende in corsa: la sessione si chiude, e al
     * login dopo il ruolo nuovo ripassa dai controlli del login. Un docente
     * promosso ad amministratore non amministra con la sessione di prima, che
     * non ha mai visto la 2FA obbligatoria per gli amministratori.
     */
    #[Test]
    public function il_docente_promosso_esce_e_non_amministra(): void
    {
        $this->entra();
        $this->cambia('role', 'administrator');

        $entro = $this->rotta('GET', '/api/admin/users');
        self::assertSame(403, $entro->status, 'entro il minuto resta un docente');

        $this->passaUnMinuto();
        $fuori = $this->rotta('GET', '/api/admin/users');
        self::assertSame(401, $fuori->status, 'passato il minuto: fuori, non amministratore');
        self::assertSame(['error' => 'unauthenticated'], json_decode($fuori->body, true));
        self::assertFalse(Auth::check());
        self::assertFalse(Auth::hasAccess('admin'));

        $st = $this->pdo->prepare(
            "SELECT details_json FROM audit_activity_log
              WHERE action = 'session_revoked' AND subject_id = ? ORDER BY id DESC LIMIT 1"
        );
        $st->execute([$this->utente]);
        self::assertSame(['motivo' => 'ruolo_cambiato'], json_decode((string)$st->fetchColumn(), true));

        // Il docente che resta docente arriva al controller della sua zona.
        $this->cambia('role', 'teacher');
        $this->entra();
        $this->passaUnMinuto();
        $dentro = $this->rotta('GET', '/api/teacher/content');
        self::assertSame(200, $dentro->status);
        self::assertTrue(Auth::check());
    }

    /**
     * A-47: il flag di super-admin scade con gli altri claims. Cambiato nel
     * database, in un verso o nell'altro, chiude la sessione; e un
     * super-admin disattivato non passa il cancello di Grafana, che non ha
     * `auth`.
     */
    #[Test]
    public function il_flag_di_super_admin_segue_il_database_entro_un_minuto(): void
    {
        $this->cambia('is_super_admin', 1);
        $this->entra();
        $_SESSION['is_super_admin'] = true;
        self::assertSame(200, $this->rotta('GET', '/auth/grafana-gate')->status, 'super-admin attivo: passa');

        $this->cambia('is_super_admin', 0);
        self::assertTrue(Auth::isSuperAdmin(), 'entro il minuto vale quello letto');
        $this->passaUnMinuto();
        self::assertFalse(Auth::isSuperAdmin(), 'revocato nel database: passato il minuto non vale più');
        self::assertFalse(Auth::hasAccess('admin'), 'e la zona admin si chiude');
        self::assertFalse(Auth::check(), 'con la sessione');
        self::assertFalse(\App\Services\AclPolicy::isSuperAdmin(), 'una risposta sola, da tutte e due le porte');

        $this->entra();
        $this->cambia('is_super_admin', 1);
        $this->passaUnMinuto();
        self::assertFalse(Auth::isSuperAdmin(), 'concesso a sessione aperta: non si prende in corsa');
        self::assertFalse(Auth::check());

        $this->entra();
        $_SESSION['is_super_admin'] = true;
        $this->cambia('active', 0);
        $this->passaUnMinuto();
        self::assertSame(401, $this->rotta('GET', '/auth/grafana-gate')->status, 'super-admin disattivato: il cancello lo ferma');
        self::assertFalse(Auth::check());
    }

    /**
     * Il costo: una SELECT per sessione al minuto, misurata con il contatore
     * del server (Com_select di questa connessione), non dedotta.
     */
    #[Test]
    public function una_lettura_al_minuto_per_sessione(): void
    {
        $this->entra();
        $this->passaUnMinuto();

        $prima = $this->selectFatte();
        self::assertSame(200, $this->bussa()->status);
        self::assertSame(1, $this->selectFatte() - $prima, 'claims scaduti: una lettura');

        $prima = $this->selectFatte();
        for ($i = 0; $i < 20; $i++) {
            self::assertSame(200, $this->bussa()->status);
            Auth::isSuperAdmin();
            Auth::hasAccess('admin');
        }
        self::assertSame(0, $this->selectFatte() - $prima, 'venti richieste nel minuto: nessuna lettura');

        $this->passaUnMinuto();
        $prima = $this->selectFatte();
        $this->bussa();
        Auth::isSuperAdmin();
        self::assertSame(1, $this->selectFatte() - $prima, 'il minuto dopo, di nuovo una');
    }

    /**
     * A-79: con la password da cambiare, /me/export-data si ferma alla porta
     * e /me/change-password no. Con la 2FA da attivare, lo stesso per i
     * consensi e per la pagina di iscrizione.
     */
    #[Test]
    public function il_confinamento_vale_anche_per_le_rotte_me(): void
    {
        $this->entra();
        $_SESSION['must_change_password'] = true;

        $bloccata = $this->rotta('GET', '/me/export-data');
        self::assertSame(403, $bloccata->status, 'l\'esportazione si ferma alla porta');
        self::assertSame(['error' => 'password_change_required'], json_decode($bloccata->body, true));

        $libera = $this->rotta('GET', '/me/change-password');
        self::assertSame(200, $libera->status, 'la pagina del cambio password si apre');
        self::assertStringContainsString('/me/change-password', $libera->body, 'ed è proprio lei, con il suo modulo');

        $this->entra();
        $_SESSION['must_enrol_2fa'] = true;
        $bloccata = $this->rotta('GET', '/me/consents');
        self::assertSame(403, $bloccata->status);
        self::assertSame(['error' => 'two_factor_enrolment_required'], json_decode($bloccata->body, true));

        // Senza confinamento la stessa rotta arriva al controller.
        $this->entra();
        $aperta = $this->rotta('GET', '/me/deletion-status');
        self::assertSame(200, $aperta->status);
        self::assertTrue((bool)(json_decode($aperta->body, true)['ok'] ?? false));
    }
}
