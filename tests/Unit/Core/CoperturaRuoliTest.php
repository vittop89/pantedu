<?php

namespace Tests\Unit\Core;

use App\Core\Router;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Chi scrive con le API del docente ha un account e la zona del docente
 * (19/9/2026).
 *
 * Misurato in locale da ospite con la credenziale di classe: il pannello BES
 * gli offriva un «+» che apriva la finestra di creazione. Il server rifiutava
 * già tutto (401 a chi non ha un account, 403 allo studente con account), ma
 * lo diceva il codice di oggi, non una regola. Questa è la regola, letta dove
 * è davvero decisa: la tabella delle rotte (vedi CoperturaCsrfTest per il
 * perché non dai controller).
 *
 * Ogni rotta che scrive (POST, PUT, PATCH, DELETE) sotto /api/teacher/,
 * /api/maps e /api/risdoc/ deve portare `auth` e un middleware di ruolo le cui
 * zone siano solo quella del docente o dell'amministratore
 * (config/roles.php: `teacher` = docente e amministratore, `admin` =
 * amministratore). `role:student` non basta: la zona degli studenti.
 *
 * Il primo giro ha trovato cinque PUT delle preferenze del docente
 * (/api/teacher/sources.json e simili) nel gruppo degli studenti: le fermava
 * solo il controller, per caso. Spostate nel gruppo del docente.
 *
 * Nessuna eccezione: se un giorno una rotta sotto questi prefissi dovesse
 * scrivere per altri, il prefisso è sbagliato.
 *
 * 23/9/2026 (A-78) — la stessa regola, più stretta, per /api/admin/: ogni
 * rotta, **anche in lettura**, porta `auth` e la sola zona `admin`. Sei rotte
 * (i preset dello stile dei badge e il riferimento delle scorciatoie LaTeX)
 * stavano nel gruppo `role:student`, e le fermava solo il controllo dentro
 * ogni metodo: lo stesso schema delle cinque PUT del 19/9. Spostate nel
 * gruppo dell'amministratore; i controlli nei controller restano.
 */
final class CoperturaRuoliTest extends TestCase
{
    /** I metodi che cambiano stato sul server. */
    private const SCRIVONO = ['POST', 'PUT', 'PATCH', 'DELETE'];

    /** Le API con cui il docente crea e modifica i suoi materiali. */
    private const PREFISSI = ['/api/teacher/', '/api/maps', '/api/risdoc/'];

    /** Le zone ammesse per scrivere lì (config/roles.php, access_zones). */
    private const ZONE = ['teacher', 'admin'];

    /** Le API dell'amministratore: qui la regola vale per ogni metodo. */
    private const PREFISSO_ADMIN = '/api/admin/';

    /** L'unica zona ammessa sotto /api/admin/. */
    private const ZONE_ADMIN = ['admin'];

    /** Le sei rotte spostate dal gruppo degli studenti il 23/9/2026, per metodo. */
    private const SPOSTATE = [
        ['GET', '/api/admin/latex-shortcuts'],
        ['POST', '/api/admin/latex-shortcuts'],
        ['GET', '/api/admin/badge-style-presets'],
        ['GET', '/api/admin/badge-style-presets/zz_prova'],
        ['PUT', '/api/admin/badge-style-presets/zz_prova'],
        ['DELETE', '/api/admin/badge-style-presets/zz_prova'],
    ];

    /**
     * Le rotte che scrivono sotto i prefissi senza la porta giusta, come
     * `METODO percorso [middleware]`.
     *
     * @return array{0: list<string>, 1: int} scoperte e rotte controllate
     */
    public static function scoperte(Router $router): array
    {
        $fuori = [];
        $controllate = 0;
        foreach ($router->routes() as $rotta) {
            $scrive = array_values(array_intersect($rotta->methods, self::SCRIVONO));
            if ($scrive === [] || !self::sottoIPrefissi($rotta->pattern)) {
                continue;
            }
            $controllate++;
            if (\in_array('auth', $rotta->middleware, true) && self::soloZone($rotta->middleware, self::ZONE)) {
                continue;
            }
            $fuori[] = $scrive[0] . ' ' . $rotta->pattern . '   [' . implode(' ', $rotta->middleware) . ']';
        }
        sort($fuori);
        return [$fuori, $controllate];
    }

    /**
     * Le rotte sotto /api/admin/, di qualunque metodo, senza `auth` o con una
     * zona che non è solo quella dell'amministratore.
     *
     * @return array{0: list<string>, 1: int} scoperte e rotte controllate
     */
    public static function scoperteAdmin(Router $router): array
    {
        $fuori = [];
        $controllate = 0;
        foreach ($router->routes() as $rotta) {
            if (!str_starts_with($rotta->pattern, self::PREFISSO_ADMIN)) {
                continue;
            }
            $controllate++;
            if (\in_array('auth', $rotta->middleware, true) && self::soloZone($rotta->middleware, self::ZONE_ADMIN)) {
                continue;
            }
            $fuori[] = $rotta->methods[0] . ' ' . $rotta->pattern . '   [' . implode(' ', $rotta->middleware) . ']';
        }
        sort($fuori);
        return [$fuori, $controllate];
    }

    private static function sottoIPrefissi(string $percorso): bool
    {
        foreach (self::PREFISSI as $p) {
            if (str_starts_with($percorso, $p)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Almeno un middleware di ruolo, e tutti con zone ammesse: un
     * `role:teacher` più avanti non rimedia a un `role:student` prima, che
     * lascia passare lo studente fino a lì.
     *
     * @param list<string> $middleware
     * @param list<string> $ammesse le zone di config/roles.php che bastano
     */
    private static function soloZone(array $middleware, array $ammesse): bool
    {
        $ruoli = 0;
        foreach ($middleware as $m) {
            if (!str_starts_with($m, 'role:')) {
                continue;
            }
            $ruoli++;
            $zone = array_filter(explode(',', substr($m, 5)), static fn(string $z): bool => $z !== '');
            if ($zone === [] || array_diff($zone, $ammesse) !== []) {
                return false;
            }
        }
        return $ruoli > 0;
    }

    private function rotteDelSito(): Router
    {
        $router = new Router();
        require \dirname(__DIR__, 3) . '/routes/web.php';
        return $router;
    }

    #[Test]
    public function ogni_scrittura_delle_api_del_docente_vuole_un_account_e_la_zona_del_docente(): void
    {
        [$scoperte, $controllate] = self::scoperte($this->rotteDelSito());

        // Una regola che non guarda niente dice sempre di sì: le rotte che
        // scrivono sotto questi prefissi erano 106 il 19/9/2026.
        $this->assertGreaterThan(50, $controllate, 'le rotte controllate sono quelle del docente, non zero');
        $this->assertSame([], $scoperte, sprintf(
            "%d rotte scrivono con le API del docente senza la sua porta.\n\n"
            . "Mettile in un gruppo con 'auth' e 'role:teacher' (o 'role:admin') in "
            . "routes/web.php: 'role:student' è la zona degli studenti.\n\nScoperte:\n  %s",
            \count($scoperte),
            implode("\n  ", $scoperte)
        ));
    }

    /**
     * La stessa regola vista da chi bussa: la catena dei middleware della
     * rotta vera, fino alla porta. Chi non ha un account riceve 401 (non ha
     * un ruolo da confrontare: l'ospite con la credenziale di classe è qui),
     * lo studente con account 403 dalla porta del ruolo. Il corpo conta: se
     * la porta del ruolo lasciasse passare, più avanti risponderebbe 403 il
     * controllo CSRF, con un altro corpo.
     */
    #[Test]
    public function alla_porta_l_ospite_riceve_401_e_lo_studente_403(): void
    {
        \App\Core\Config::load(\dirname(__DIR__, 3) . '/app/Config');
        $router = $this->rotteDelSito();
        $kernel = new \App\Core\Kernel($router);
        $catena = new \ReflectionMethod(\App\Core\Kernel::class, 'buildPipeline');
        $serverPrima = $_SERVER;
        $sessionePrima = $_SESSION ?? [];
        try {
            foreach ([
                ['POST', '/api/teacher/content'],
                ['POST', '/api/maps'],
                ['POST', '/api/risdoc/templates/1/instances'],
                ['PUT', '/api/teacher/sources.json'],
            ] as [$metodo, $percorso]) {
                $_SERVER['REQUEST_METHOD'] = $metodo;
                $_SERVER['REQUEST_URI'] = $percorso;
                $_SERVER['HTTP_ACCEPT'] = 'application/json';

                $_SESSION = [];
                $req = new \App\Core\Request();
                $rotta = $router->match($req);
                $this->assertNotNull($rotta, "$metodo $percorso esiste");
                $esito = $catena->invoke($kernel, $rotta, $req)($req);
                $this->assertSame(401, $esito->status, "$metodo $percorso senza account");
                $this->assertSame(['error' => 'unauthenticated'], json_decode($esito->body, true));

                // Claims appena letti: senza, AuthMiddleware li rilegge dal
                // database, non trova 'zz_studente' e chiude la sessione con 401
                // prima della porta del ruolo (23/9/2026). Qui si prova la porta.
                $_SESSION = ['autenticato' => true, 'username' => 'zz_studente', 'user_id' => 999999, 'user_role' => 'student', 'active' => true, 'claims_at' => time()];
                $req = new \App\Core\Request();
                $esito = $catena->invoke($kernel, $rotta, $req)($req);
                $this->assertSame(403, $esito->status, "$metodo $percorso da studente");
                $this->assertSame(['error' => 'forbidden', 'role' => 'student'], json_decode($esito->body, true),
                    "$metodo $percorso: a fermarlo è la porta del ruolo");
            }
        } finally {
            $_SERVER = $serverPrima;
            $_SESSION = $sessionePrima;
        }
    }

    #[Test]
    public function la_regola_scatta_quando_deve_e_tace_quando_non_deve(): void
    {
        $router = new Router();
        $noop = static fn() => null;
        // Scoperte: senza niente, senza account, con la zona degli studenti,
        // con una zona degli studenti accanto a quella del docente.
        $router->post('/api/teacher/finta-senza-niente', $noop);
        $router->post('/api/maps/finta-senza-account', $noop)->middleware('role:teacher');
        $router->group(['middleware' => ['auth', 'role:student']], function (Router $r) use ($noop): void {
            $r->put('/api/risdoc/finta-da-studente', $noop)->middleware('csrf');
        });
        $router->delete('/api/teacher/finta-mista', $noop)->middleware('auth', 'role:student', 'role:teacher');
        // Coperte: docente, amministratore, e le due zone insieme.
        $router->group(['middleware' => ['auth', 'role:teacher']], function (Router $r) use ($noop): void {
            $r->post('/api/teacher/finta-del-docente', $noop)->middleware('csrf');
        });
        $router->post('/api/risdoc/finta-dell-admin', $noop)->middleware('auth', 'role:admin');
        $router->put('/api/maps/finta-due-zone', $noop)->middleware('auth', 'role:teacher,admin');
        // Fuori dalla regola: una lettura, e una scrittura sotto un altro prefisso.
        $router->get('/api/teacher/finta-lettura', $noop);
        $router->post('/api/study/finta-altrove', $noop);

        [$scoperte, $controllate] = self::scoperte($router);
        $this->assertSame(7, $controllate, 'le sette scritture sotto i prefissi, non la lettura né l\'altro prefisso');
        $this->assertSame([
            'DELETE /api/teacher/finta-mista   [auth role:student role:teacher]',
            'POST /api/maps/finta-senza-account   [role:teacher]',
            'POST /api/teacher/finta-senza-niente   []',
            'PUT /api/risdoc/finta-da-studente   [auth role:student csrf]',
        ], $scoperte);
    }

    #[Test]
    public function ogni_api_dell_amministratore_vuole_un_account_e_la_sua_zona(): void
    {
        [$scoperte, $controllate] = self::scoperteAdmin($this->rotteDelSito());

        // Le rotte sotto /api/admin/ erano 56 il 23/9/2026: una lettura che
        // non ne trovasse nessuna direbbe «tutto a posto» senza guardare.
        $this->assertGreaterThan(40, $controllate, 'le rotte controllate sono quelle dell\'amministratore, non zero');
        $this->assertSame([], $scoperte, sprintf(
            "%d rotte sotto /api/admin/ non stanno dietro la porta dell'amministratore.\n\n"
            . "Mettile in un gruppo con 'auth' e 'role:admin' in routes/web.php. Un "
            . "controllo dentro il controller non basta: è una riga da non "
            . "dimenticare in ogni metodo, e intanto la richiesta ha già passato "
            . "registro, gettone e limiti come se fosse di chi ha la zona.\n\nScoperte:\n  %s",
            \count($scoperte),
            implode("\n  ", $scoperte)
        ));
    }

    /**
     * Le sei rotte spostate il 23/9/2026, viste da chi bussa. A fermare lo
     * studente e il docente dev'essere la porta del ruolo — corpo con `role` —
     * e non il controllo nel controller (corpo senza `role`) né il gettone
     * CSRF (`csrf_invalid`), che è quello che succedeva quando stavano nel
     * gruppo degli studenti. `/api/admin/users`, mai spostata, fa da
     * confronto.
     */
    #[Test]
    public function alla_porta_dell_amministratore_l_ospite_riceve_401_studente_e_docente_403(): void
    {
        \App\Core\Config::load(\dirname(__DIR__, 3) . '/app/Config');
        $router = $this->rotteDelSito();
        $kernel = new \App\Core\Kernel($router);
        $catena = new \ReflectionMethod(\App\Core\Kernel::class, 'buildPipeline');
        $serverPrima = $_SERVER;
        $sessionePrima = $_SESSION ?? [];
        $bussate = [...self::SPOSTATE, ['GET', '/api/admin/users']];
        try {
            foreach ($bussate as [$metodo, $percorso]) {
                $_SERVER['REQUEST_METHOD'] = $metodo;
                $_SERVER['REQUEST_URI'] = $percorso;
                $_SERVER['HTTP_ACCEPT'] = 'application/json';

                $_SESSION = [];
                $req = new \App\Core\Request();
                $rotta = $router->match($req);
                $this->assertNotNull($rotta, "$metodo $percorso esiste");
                $esito = $catena->invoke($kernel, $rotta, $req)($req);
                $this->assertSame(401, $esito->status, "$metodo $percorso senza account");
                $this->assertSame(['error' => 'unauthenticated'], json_decode($esito->body, true));

                foreach (['student', 'teacher'] as $ruolo) {
                    // Claims appena letti: senza, AuthMiddleware li rilegge dal
                    // database (in CI c'è), non trova 'zz_…' e chiude la
                    // sessione con 401 prima della porta del ruolo (23/9/2026).
                    $_SESSION = [
                        'autenticato' => true, 'username' => 'zz_' . $ruolo, 'user_id' => 999999,
                        'user_role' => $ruolo, 'is_super_admin' => false,
                        'active' => true, 'claims_at' => time(),
                    ];
                    $req = new \App\Core\Request();
                    $esito = $catena->invoke($kernel, $rotta, $req)($req);
                    $this->assertSame(403, $esito->status, "$metodo $percorso da $ruolo");
                    $this->assertSame(
                        ['error' => 'forbidden', 'role' => $ruolo],
                        json_decode($esito->body, true),
                        "$metodo $percorso da $ruolo: a fermarlo è la porta del ruolo"
                    );
                }
            }
        } finally {
            $_SERVER = $serverPrima;
            $_SESSION = $sessionePrima;
        }
    }

    /**
     * L'altro verso dello spostamento: la porta nuova non chiude fuori chi i
     * controller ammettevano. I preset vogliono Auth::hasAccess('admin'); il
     * riferimento delle scorciatoie Auth::isSuperAdmin(), e il super-admin può
     * avere il ruolo di docente o di studente. Si prova la porta del ruolo con
     * le zone che la rotta dichiara, non la catena intera: dopo la porta
     * verrebbero il registro degli accessi, che scrive su file, e il gestore.
     */
    #[Test]
    public function la_porta_dell_amministratore_non_chiude_fuori_chi_i_controller_ammettono(): void
    {
        \App\Core\Config::load(\dirname(__DIR__, 3) . '/app/Config');
        $router = $this->rotteDelSito();
        $passa = static fn(\App\Core\Request $r): \App\Core\Response => \App\Core\Response::json(['passata' => true]);
        $serverPrima = $_SERVER;
        $sessionePrima = $_SESSION ?? [];
        // ruolo, super-admin, esito alla porta
        $chiBussa = [
            ['administrator', false, 200],
            ['teacher', true, 200],
            ['student', true, 200],
            ['teacher', false, 403],
            ['student', false, 403],
        ];
        try {
            foreach (self::SPOSTATE as [$metodo, $percorso]) {
                $_SERVER['REQUEST_METHOD'] = $metodo;
                $_SERVER['REQUEST_URI'] = $percorso;
                $_SERVER['HTTP_ACCEPT'] = 'application/json';
                $rotta = $router->match(new \App\Core\Request());
                $this->assertNotNull($rotta, "$metodo $percorso esiste");

                $zone = [];
                foreach ($rotta->middleware as $m) {
                    if (str_starts_with($m, 'role:')) {
                        $zone = [...$zone, ...explode(',', substr($m, 5))];
                    }
                }
                $this->assertNotSame([], $zone, "$metodo $percorso ha una porta del ruolo");

                foreach ($chiBussa as [$ruolo, $super, $atteso]) {
                    // Claims appena letti: AclPolicy crede al flag solo finché
                    // sono freschi (23/9/2026), senza chiedere al database.
                    $_SESSION = [
                        'autenticato' => true, 'username' => 'zz_' . $ruolo, 'user_id' => 999999,
                        'user_role' => $ruolo, 'is_super_admin' => $super,
                        'active' => true, 'claims_at' => time(),
                    ];
                    $esito = (new \App\Middleware\RoleMiddleware())->handle(new \App\Core\Request(), $passa, ...$zone);
                    $this->assertSame($atteso, $esito->status, sprintf(
                        '%s %s: %s%s alla porta %s',
                        $metodo,
                        $percorso,
                        $ruolo,
                        $super ? ' super-admin' : '',
                        implode(',', $zone)
                    ));
                }
            }
        } finally {
            $_SERVER = $serverPrima;
            $_SESSION = $sessionePrima;
        }
    }

    #[Test]
    public function la_regola_dell_amministratore_scatta_quando_deve_e_tace_quando_non_deve(): void
    {
        $router = new Router();
        $noop = static fn() => null;
        // Scoperte: la zona degli studenti (anche in sola lettura), quella del
        // docente, niente account, due zone di cui una di troppo, niente di niente.
        $router->group(['middleware' => ['auth', 'role:student']], function (Router $r) use ($noop): void {
            $r->get('/api/admin/finta-da-studente', $noop);
        });
        $router->post('/api/admin/finta-del-docente', $noop)->middleware('auth', 'role:teacher', 'csrf');
        $router->get('/api/admin/finta-senza-account', $noop)->middleware('role:admin');
        $router->delete('/api/admin/finta-mista', $noop)->middleware('auth', 'role:admin,teacher');
        $router->get('/api/admin/finta-senza-niente', $noop);
        // Coperte: lettura e scrittura dell'amministratore, e col super-admin.
        $router->group(['middleware' => ['auth', 'role:admin', 'log']], function (Router $r) use ($noop): void {
            $r->get('/api/admin/finta-lettura', $noop);
            $r->put('/api/admin/finta-scrittura', $noop)->middleware('csrf');
            $r->post('/api/admin/finta-super', $noop)->middleware('super_admin_required', 'csrf');
        });
        // Fuori dalla regola: un altro prefisso che comincia allo stesso modo,
        // e le API del docente (hanno la loro).
        $router->get('/api/administrator-finto', $noop);
        $router->post('/api/teacher/finta-altrove', $noop)->middleware('auth', 'role:student');

        [$scoperte, $controllate] = self::scoperteAdmin($router);
        $this->assertSame(8, $controllate, 'le otto rotte sotto /api/admin/, non le altre due');
        $this->assertSame([
            'DELETE /api/admin/finta-mista   [auth role:admin,teacher]',
            'GET /api/admin/finta-da-studente   [auth role:student]',
            'GET /api/admin/finta-senza-account   [role:admin]',
            'GET /api/admin/finta-senza-niente   []',
            'POST /api/admin/finta-del-docente   [auth role:teacher csrf]',
        ], $scoperte);
    }
}
