<?php

declare(strict_types=1);

namespace Tests\Unit\Core;

use App\Core\Auth;
use App\Core\Config;
use App\Core\Database;
use App\Core\Kernel;
use App\Core\Request;
use App\Core\Response;
use App\Domain\User;
use App\Middleware\AuthMiddleware;
use App\Services\AclPolicy;
use PDO;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use Tests\Support\RottaVera;

/**
 * Una sessione aperta vale finché l'account è quello del login, al più con un
 * minuto di ritardo (23/9/2026).
 *
 * Revisione architetturale 2026-09, rilievi A-66 e A-47, e la verifica del
 * ramo che li correggeva:
 *   - un account disattivato restava dentro fino a dodici ore, perché ruolo e
 *     stato si leggevano solo al login;
 *   - un super-admin disattivato passava ancora il cancello di Grafana, che
 *     non ha `auth`: la rilettura vedeva `active` e non lo guardava;
 *   - un docente promosso ad amministratore prendeva il ruolo nuovo con la
 *     sessione aperta, senza ripassare dal login e quindi senza la 2FA che il
 *     ruolo nuovo rende obbligatoria.
 *
 * Il cuore di A-66 sta qui, nella suite `unit` che il cancello su `main` fa
 * girare senza MariaDB: il database è uno SQLite in memoria messo al posto
 * della connessione condivisa, come in SecondoFattoreDalProfiloTest. Le
 * stesse regole su MariaDB, con la catena vera dei middleware e il costo
 * misurato: tests/Integration/SessioneRilettaDalDatabaseTest.php.
 *
 * Il tempo si simula spostando indietro `claims_at`, l'ora dell'ultima
 * lettura. I secondi sono scritti qui come numeri, e non ricavati da
 * Auth::CLAIMS_TTL_SECONDS: la prova fissa il limite, non lo ripete.
 */
final class AccountRevocatoFuoriDallaSessioneTest extends TestCase
{
    private const DOCENTE = 'zz_revoca_docente';
    private const TECNICO = 'zz_revoca_tecnico';

    private ?PDO $pdo = null;
    private ?PDO $pdoPrima = null;
    private mixed $dbPrima = null;
    /** @var array<string, mixed> */
    private array $serverPrima = [];

    protected function setUp(): void
    {
        if (!\in_array('sqlite', PDO::getAvailableDrivers(), true)) {
            $this->markTestSkipped('pdo_sqlite non disponibile in questo runtime');
        }
        $conn = new ReflectionProperty(Database::class, 'pdo');
        $prima = $conn->getValue();
        $this->pdoPrima = $prima instanceof PDO ? $prima : null;
        $this->usa(new PDO('sqlite::memory:'));
        $this->dbPrima = Config::get('database.enabled');
        Config::set('database.enabled', true);

        $this->serverPrima = $_SERVER;
        $_SESSION = [];
    }

    /**
     * Mette $pdo al posto della connessione condivisa, con le tabelle e i due
     * account della prova.
     */
    private function usa(PDO $pdo): void
    {
        $this->pdo = $pdo;
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $this->pdo->sqliteCreateFunction('NOW', static fn() => date('Y-m-d H:i:s'), 0);
        // Le colonne di `users` che la rilettura e il login toccano, e quelle
        // di `audit_activity_log` che ActivityLogger scrive (migrazione 069).
        $this->pdo->exec(
            'CREATE TABLE users (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                username TEXT NOT NULL UNIQUE,
                role TEXT NOT NULL,
                is_super_admin INTEGER NOT NULL DEFAULT 0,
                active INTEGER NOT NULL DEFAULT 1,
                last_access_at TEXT DEFAULT NULL
            );
            CREATE TABLE audit_activity_log (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                actor_user_id INTEGER, actor_name TEXT, actor_role TEXT,
                action TEXT NOT NULL, method TEXT, path TEXT, status INTEGER,
                outcome TEXT, subject_type TEXT, subject_id TEXT,
                details_json TEXT, ip_hash TEXT, ua_hash TEXT, request_id TEXT
            );'
        );
        $this->pdo->prepare('INSERT INTO users (username, role, is_super_admin) VALUES (?, ?, ?)')
            ->execute([self::DOCENTE, 'teacher', 0]);
        $this->pdo->prepare('INSERT INTO users (username, role, is_super_admin) VALUES (?, ?, ?)')
            ->execute([self::TECNICO, 'teacher', 1]);
        (new ReflectionProperty(Database::class, 'pdo'))->setValue(null, $this->pdo);
    }

    protected function tearDown(): void
    {
        (new ReflectionProperty(Database::class, 'pdo'))->setValue(null, $this->pdoPrima);
        Config::set('database.enabled', $this->dbPrima);
        $_SERVER = $this->serverPrima;
        $_SESSION = [];
        $this->pdo = null;
    }

    /** Una sessione come la lascia il login, con i claims letti $secondi fa. */
    private function sessione(string $utente, int $secondi = 0): void
    {
        $riga = $this->pdo?->prepare('SELECT id, role, is_super_admin FROM users WHERE username = ?');
        $riga?->execute([$utente]);
        $u = (array)$riga?->fetch();
        $_SESSION = [
            'autenticato'    => true,
            'username'       => $utente,
            'user_id'        => (int)$u['id'],
            'user_role'      => (string)$u['role'],
            'is_super_admin' => (bool)$u['is_super_admin'],
            'active'         => true,
            'claims_at'      => time() - $secondi,
        ];
    }

    private function cambia(string $utente, string $colonna, int|string $valore): void
    {
        $this->pdo?->prepare("UPDATE users SET $colonna = ? WHERE username = ?")->execute([$valore, $utente]);
    }

    /** AuthMiddleware davanti a un'azione finta: 200 se la lascia passare. */
    private function bussa(string $percorso = '/api/teacher/prova', bool $json = true): Response
    {
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['REQUEST_URI'] = $percorso;
        $_SERVER['HTTP_ACCEPT'] = $json ? 'application/json' : 'text/html';
        return (new AuthMiddleware())->handle(
            new Request(''),
            static fn(Request $r): Response => Response::json(['passato' => true])
        );
    }

    /**
     * Le porte di una rotta vera di routes/web.php (`auth` e `role:*`), davanti
     * a un'azione finta: dice chi arriva all'azione senza far girare il
     * controller, che qui non ha le sue tabelle.
     */
    private function porte(string $percorso): Response
    {
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['REQUEST_URI'] = $percorso;
        $_SERVER['HTTP_ACCEPT'] = 'application/json';
        $req = new Request('');
        $rotta = RottaVera::rotte()->match($req);
        self::assertNotNull($rotta, "GET $percorso esiste");
        $mappa = (array)(new ReflectionProperty(Kernel::class, 'middlewareMap'))->getValue(new Kernel(RottaVera::rotte()));
        $porte = array_values(array_filter(
            $rotta->middleware,
            static fn(string $m): bool => $m === 'auth' || str_starts_with($m, 'role:')
        ));
        self::assertSame('auth', $porte[0] ?? null, "GET $percorso passa da auth");
        $avanti = static fn(Request $r): Response => Response::json(['arrivato' => true]);
        foreach (array_reverse($porte) as $nome) {
            [$classe, $argomenti] = str_contains($nome, ':')
                ? [$mappa[explode(':', $nome, 2)[0]], explode(',', explode(':', $nome, 2)[1])]
                : [$mappa[$nome], []];
            $dopo = $avanti;
            $avanti = static fn(Request $r): Response => (new $classe())->handle($r, $dopo, ...$argomenti);
        }
        return $avanti($req);
    }

    /** @return list<array{subject_id: string, outcome: string, details_json: string, actor_name: string}> */
    private function revoche(): array
    {
        $righe = $this->pdo?->query(
            "SELECT subject_id, outcome, details_json, actor_name FROM audit_activity_log WHERE action = 'session_revoked' ORDER BY id"
        )->fetchAll();
        return \is_array($righe) ? array_values($righe) : [];
    }

    #[Test]
    public function il_ritardo_dichiarato_e_al_piu_un_minuto(): void
    {
        self::assertLessThanOrEqual(60, Auth::CLAIMS_TTL_SECONDS, 'la promessa è «entro un minuto»: un TTL più lungo la rompe');
        self::assertGreaterThan(0, Auth::CLAIMS_TTL_SECONDS);
    }

    #[Test]
    public function l_account_disattivato_esce_dopo_sessantuno_secondi(): void
    {
        $this->cambia(self::DOCENTE, 'active', 0);

        // Cinquanta secondi fa: la sessione non lo sa ancora, è il ritardo dichiarato.
        $this->sessione(self::DOCENTE, 50);
        self::assertSame(200, $this->bussa()->status);
        self::assertTrue(Auth::check());

        // Sessantuno: fuori, con la risposta di chi non ha sessione.
        $this->sessione(self::DOCENTE, 61);
        $risposta = $this->bussa();
        self::assertSame(401, $risposta->status);
        self::assertSame(['error' => 'unauthenticated'], json_decode((string)$risposta->body, true));
        self::assertFalse(Auth::check(), 'la sessione è chiusa');
        self::assertSame([], $_SESSION);
        self::assertSame([[
            'subject_id'   => self::DOCENTE,
            'outcome'      => 'denied',
            'details_json' => '{"motivo":"account_disattivato"}',
            'actor_name'   => self::DOCENTE,
        ]], $this->revoche(), 'una riga session_revoked, con il perché');

        // Una pagina, invece di un'API: al login.
        $this->sessione(self::DOCENTE, 61);
        $pagina = $this->bussa('/area-docente/dashboard', json: false);
        self::assertSame(302, $pagina->status);
        self::assertSame('/login?redirect=%2Farea-docente%2Fdashboard', $pagina->headers['Location'] ?? null);
    }

    #[Test]
    public function l_account_cancellato_esce(): void
    {
        $this->sessione(self::DOCENTE, 61);
        $this->pdo?->prepare('DELETE FROM users WHERE username = ?')->execute([self::DOCENTE]);
        self::assertSame(401, $this->bussa()->status);
        self::assertFalse(Auth::check());
        self::assertSame('{"motivo":"account_assente"}', $this->revoche()[0]['details_json'] ?? null);
    }

    /** L'altro verso: l'account invariato resta dentro, e la lettura si rinnova. */
    #[Test]
    public function l_account_invariato_resta_dentro(): void
    {
        $this->sessione(self::DOCENTE, 61);
        self::assertSame(200, $this->bussa()->status);
        self::assertTrue(Auth::check());
        self::assertSame('teacher', Auth::role());
        self::assertGreaterThanOrEqual(time() - 1, $_SESSION['claims_at'], 'la lettura è segnata adesso');
        self::assertSame([], $this->revoche());
    }

    /**
     * Una promozione non si prende in corsa: al login dopo il ruolo nuovo
     * ripassa dai controlli del login, 2FA obbligatoria compresa.
     */
    #[Test]
    public function il_docente_promosso_esce_e_non_amministra(): void
    {
        $this->sessione(self::DOCENTE);
        $this->cambia(self::DOCENTE, 'role', 'administrator');
        self::assertSame(403, $this->porte('/api/admin/users')->status, 'entro il minuto resta un docente');

        $_SESSION['claims_at'] = time() - 61;
        $risposta = $this->porte('/api/admin/users');
        self::assertSame(401, $risposta->status, 'passato il minuto: fuori, non amministratore');
        self::assertFalse(Auth::check());
        self::assertFalse(Auth::hasAccess('admin'));
        self::assertSame('guest', Auth::role());
        self::assertSame('{"motivo":"ruolo_cambiato"}', $this->revoche()[0]['details_json'] ?? null);

        // Il docente che resta docente, sulla stessa strada, arriva all'azione della sua zona.
        $this->cambia(self::DOCENTE, 'role', 'teacher');
        $this->sessione(self::DOCENTE, 61);
        self::assertSame(200, $this->porte('/api/teacher/content')->status);
        self::assertTrue(Auth::check());
    }

    /** Anche il declassamento chiude: nessun ruolo cambia a sessione aperta. */
    #[Test]
    public function il_ruolo_tolto_chiude_la_sessione(): void
    {
        $this->sessione(self::DOCENTE, 61);
        $this->cambia(self::DOCENTE, 'role', 'student');
        self::assertSame(401, $this->bussa()->status);
        self::assertFalse(Auth::check());
    }

    /**
     * Il cancello di Grafana non ha `auth`: il super-admin disattivato si
     * ferma lì, e la sessione si chiude lì.
     */
    #[Test]
    public function il_super_admin_disattivato_non_passa_il_cancello_di_grafana(): void
    {
        $this->sessione(self::TECNICO);
        self::assertSame(200, RottaVera::chiama('GET', '/auth/grafana-gate')->status, 'attivo: passa');

        $this->cambia(self::TECNICO, 'active', 0);
        $this->sessione(self::TECNICO, 61);
        self::assertSame(401, RottaVera::chiama('GET', '/auth/grafana-gate')->status, 'disattivato, passato il minuto: fuori');
        self::assertFalse(Auth::check());
        self::assertSame('{"motivo":"account_disattivato"}', $this->revoche()[0]['details_json'] ?? null);

        // I claims freschi con l'account non attivo li scrive solo
        // refreshCurrentUserClaims(), dopo un cambio fatto su di sé: il flag
        // non si concede nemmeno lì.
        $this->sessione(self::TECNICO);
        $_SESSION['active'] = false;
        self::assertFalse(AclPolicy::isSuperAdmin());
        self::assertFalse(Auth::check());
    }

    /** Il flag cambiato nel database chiude la sessione, nei due versi. */
    #[Test]
    public function il_flag_cambiato_chiude_la_sessione(): void
    {
        $this->sessione(self::TECNICO, 61);
        self::assertTrue(Auth::isSuperAdmin(), 'invariato: vale');
        self::assertTrue(Auth::hasAccess('admin'));

        $this->cambia(self::TECNICO, 'is_super_admin', 0);
        self::assertTrue(Auth::isSuperAdmin(), 'entro il minuto vale quello letto');
        $this->sessione(self::TECNICO, 61);
        $_SESSION['is_super_admin'] = true;
        self::assertFalse(Auth::isSuperAdmin(), 'revocato: passato il minuto no');
        self::assertFalse(Auth::check(), 'e la sessione è chiusa');

        $this->sessione(self::DOCENTE, 61);
        $this->cambia(self::DOCENTE, 'is_super_admin', 1);
        self::assertFalse(Auth::isSuperAdmin(), 'concesso a sessione aperta: non si prende in corsa');
        self::assertFalse(Auth::check());
        self::assertSame(
            ['{"motivo":"super_admin_cambiato"}', '{"motivo":"super_admin_cambiato"}'],
            array_column($this->revoche(), 'details_json')
        );
    }

    /**
     * Claims scaduti e database spento: il flag è no, ma la sessione resta
     * (e AuthMiddleware la lascia passare). Con i claims freschi il flag vale.
     */
    #[Test]
    public function con_il_database_spento_il_flag_non_verificato_non_si_concede(): void
    {
        $this->sessione(self::TECNICO);
        Config::set('database.enabled', false);
        self::assertTrue(AclPolicy::isSuperAdmin(), 'claims freschi: vale quello letto');

        $this->sessione(self::TECNICO, 61);
        $letti = $_SESSION['claims_at'];
        self::assertFalse(AclPolicy::isSuperAdmin(), 'scaduti e non riletti: no');
        self::assertFalse(Auth::hasAccess('admin'));
        self::assertTrue(Auth::check(), 'la sessione non si chiude per un database che non risponde');
        self::assertSame(200, $this->bussa()->status);
        self::assertSame($letti, $_SESSION['claims_at'], 'e la lettura non si segna');
    }

    /**
     * Il login dimentica i claims del login precedente nella stessa sessione:
     * l'ora dell'ultima lettura e lo stato dell'account. Senza, il secondo
     * utente ereditava un «non attivo» (e usciva) o un «letto adesso» (e non
     * veniva riletto per un minuto).
     */
    #[Test]
    public function il_login_dimentica_i_claims_del_login_precedente(): void
    {
        $this->sessione(self::DOCENTE);
        $_SESSION['active'] = false;

        Auth::establishSession(new User(self::TECNICO, 'x', 'teacher', true));

        self::assertArrayNotHasKey('claims_at', $_SESSION);
        self::assertArrayNotHasKey('active', $_SESSION);
        self::assertArrayNotHasKey('is_super_admin', $_SESSION);

        self::assertSame(200, $this->bussa()->status, 'il nuovo utente non eredita il «non attivo»');
        self::assertTrue(Auth::isSuperAdmin(), 'la prima richiesta legge i suoi claims');
        self::assertGreaterThanOrEqual(time() - 1, $_SESSION['claims_at']);
    }

    /**
     * /auth/user-info non ha `auth`: una sessione revocata non vi riceve più
     * «autenticato», nemmeno una volta. Fino al 23/9 rispondeva ancora sì, e
     * la sessione si chiudeva dopo, alla domanda sul flag.
     */
    #[Test]
    public function user_info_non_dice_autenticato_a_una_sessione_revocata(): void
    {
        $this->sessione(self::DOCENTE, 61);
        $dentro = (array)json_decode((string)RottaVera::chiama('GET', '/auth/user-info')->body, true);
        self::assertTrue($dentro['authenticated'] ?? null, 'invariato: autenticato');
        self::assertSame(self::DOCENTE, $dentro['username'] ?? null);
        self::assertSame([], $this->revoche());

        $this->cambia(self::DOCENTE, 'active', 0);
        $this->sessione(self::DOCENTE, 61);
        $fuori = (array)json_decode((string)RottaVera::chiama('GET', '/auth/user-info')->body, true);
        self::assertFalse($fuori['authenticated'] ?? null, 'disattivato, passato il minuto: non autenticato già a questa risposta');
        self::assertArrayNotHasKey('username', $fuori);
        self::assertFalse(Auth::check(), 'e la sessione è chiusa');
        self::assertSame('{"motivo":"account_disattivato"}', $this->revoche()[0]['details_json'] ?? null);
    }

    /**
     * Una domanda sul flag che arriva mentre la sessione si sta verificando
     * non ricomincia la verifica (23/9/2026). Qui la fa la lettura dei claims
     * stessa: il database finto, a ogni SELECT su `users`, chiede il flag.
     * Senza il segno contro il rientro ogni domanda rileggeva i claims, e
     * ogni lettura chiedeva di nuovo: un giro senza fine, che nella verifica
     * del ramo ha passato 1 GB. Il database finto smette di chiedere alla
     * quinta domanda annidata, perché la prova rossa finisca senza consumare
     * memoria.
     */
    #[Test]
    public function la_domanda_che_rientra_non_ripete_la_verifica(): void
    {
        $rientrante = new class ('sqlite::memory:') extends PDO {
            public bool $armato = false;
            public int $letture = 0;
            /** @var list<bool> */
            public array $risposteDentro = [];
            private int $profondita = 0;

            /** @param array<int|string, mixed> $options */
            public function prepare(string $query, array $options = []): \PDOStatement|false
            {
                if ($this->armato && str_contains($query, 'FROM users')) {
                    $this->letture++;
                    if ($this->profondita < 5) {
                        $this->profondita++;
                        try {
                            $this->risposteDentro[] = AclPolicy::isSuperAdmin();
                        } finally {
                            $this->profondita--;
                        }
                    }
                }
                return parent::prepare($query, $options);
            }
        };
        $this->usa($rientrante);
        $this->sessione(self::TECNICO, 61);
        $rientrante->armato = true;

        self::assertTrue(AclPolicy::isSuperAdmin(), 'fuori dalla verifica il flag vale');
        self::assertSame(1, $rientrante->letture, 'una lettura sola dei claims, non una per domanda');
        self::assertSame([false], $rientrante->risposteDentro, 'dentro la verifica il flag non ancora riletto non si concede');
        self::assertTrue(Auth::check());
        self::assertGreaterThanOrEqual(time() - 1, $_SESSION['claims_at']);
        self::assertSame([], $this->revoche());

        // Finita la verifica, la domanda dopo non trova il segno rimasto acceso.
        $_SESSION['claims_at'] = time() - 61;
        $this->cambia(self::TECNICO, 'active', 0);
        self::assertFalse(AclPolicy::isSuperAdmin());
        self::assertFalse(Auth::check(), 'la verifica dopo chiude la sessione revocata');
    }
}
