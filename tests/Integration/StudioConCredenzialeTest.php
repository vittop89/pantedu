<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Controllers\ContentStudyController;
use App\Controllers\SidebarConfigController;
use App\Core\Database;
use App\Core\Kernel;
use App\Core\Request;
use App\Core\Response;
use App\Core\Router;
use App\Middleware\StudioMiddleware;
use App\Support\ClassAccessGrant;
use PDO;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Lo studio con la credenziale di classe (14/9/2026).
 *
 * Le pagine e le API di studio stavano dietro `auth` + `role:student`, che
 * l'ospite entrato con la credenziale non soddisfa: riceveva 401 da tutte, e la
 * modalità dichiarata non mostrava niente. Il gruppo dello studio usa
 * StudioMiddleware. Qui si prova chi entra, nei due versi: senza niente no; con
 * una credenziale valida sì; con la stessa credenziale spenta, scaduta o
 * cancellata no; con un account le porte di prima. E che il gruppo è di sola
 * lettura, con la sua porta registrata (il Kernel salta in silenzio un
 * middleware che non conosce).
 *
 * Fixture isolata in transazione (rollback in tearDown).
 */
final class StudioConCredenzialeTest extends TestCase
{
    private PDO $pdo;
    private bool $inTx = false;
    private int $scuola = 0;
    private int $docente = 0;
    private int $credenziale = 0;

    /** Le rotte dello studio: nessuna di più, nessuna di meno. */
    private const ROTTE_DELLO_STUDIO = [
        'GET /studio/{type}/{ind}/{cls}/{subj}',
        'GET /studio/{type}/{ind}/{cls}/{subj}/{topic}',
        'GET /api/sidebar/config',
        'GET /api/study/topics.json',
        'GET /api/study/content.json',
        'GET /api/study/content/{id}.json',
        'GET /api/study/materie.json',
        'GET /api/study/verifica/list',
        'GET /api/study/header-page.json',
        'GET /api/study/related-verifiche.html',
        'GET /tikz/render',
        // 21/9/2026 — l'unica che non è lettura, e il perché sta in
        // routes/web.php: compilare una figura non scrive i dati di nessuno.
        // La cache è indirizzata dal contenuto, e senza questa rotta chi
        // studia con la credenziale vedeva «[TikZ render error]
        // unauthenticated» su ogni figura non ancora in cache.
        'POST /tikz/render',
    ];

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
            $this->pdo->query('SELECT 1 FROM teacher_access_credentials_data LIMIT 1');
        } catch (\Throwable $e) {
            $this->markTestSkipped('DB non disponibile: ' . $e->getMessage());
        }
        $this->pdo->beginTransaction();
        $this->inTx = true;
        $_SESSION = [];
        $_SERVER['HTTP_ACCEPT'] = 'application/json';
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['REQUEST_URI'] = '/api/study/content.json';
        ClassAccessGrant::resetCache();

        $this->pdo->prepare('INSERT INTO institutes (code, name, city, active) VALUES (?, ?, ?, 1)')
            ->execute(['ZZSCC001', 'SCUOLA STUDIO CREDENZIALE', 'Comune Esempio']);
        $this->scuola = (int)$this->pdo->lastInsertId();
        $this->pdo->prepare(
            'INSERT INTO users (username, role, first_name, last_name, email, password_hash, status, active, created_at)
             VALUES ("zzscc_doc", "teacher", "Zz", "Credenziale", "zzscc_doc@example.invalid", "x", "approved", 1, NOW())'
        )->execute();
        $this->docente = (int)$this->pdo->lastInsertId();
        $this->pdo->prepare('INSERT INTO teacher_institutes (user_id, institute_id) VALUES (?, ?)')->execute([$this->docente, $this->scuola]);
        $this->pdo->prepare(
            'INSERT INTO teacher_access_credentials_data (teacher_id, label, access_username, password_hash, institute_id, active)
             VALUES (?, "Seconda A", ?, "x", ?, 1)'
        )->execute([$this->docente, 'zzscc_' . uniqid(), $this->scuola]);
        $this->credenziale = (int)$this->pdo->lastInsertId();
    }

    protected function tearDown(): void
    {
        if ($this->inTx && $this->pdo->inTransaction()) {
            $this->pdo->rollBack();
        }
        $_SESSION = [];
        unset($_SERVER['HTTP_ACCEPT']);
        ClassAccessGrant::resetCache();
    }

    private function entra(): Response
    {
        ClassAccessGrant::resetCache();
        return (new StudioMiddleware())->handle(new Request(), static fn(Request $r): Response => Response::json(['ok' => true, 'passato' => true]));
    }

    private function conCredenziale(): void
    {
        $_SESSION = [];
        ClassAccessGrant::resetCache();
        ClassAccessGrant::add([
            'teacher_id' => $this->docente, 'institute_id' => $this->scuola, 'indirizzo' => 'ZSS', 'classe' => '2A',
            'label' => 'Seconda A', 'credential_id' => $this->credenziale, 'granted_at' => time(),
        ]);
        ClassAccessGrant::resetCache();
    }

    #[Test]
    public function senza_account_e_senza_credenziale_non_si_entra(): void
    {
        $this->assertSame(401, $this->entra()->status, 'chi chiede JSON riceve 401');
        unset($_SERVER['HTTP_ACCEPT']);
        $pagina = $this->entra();
        $this->assertSame(302, $pagina->status, 'una pagina manda al login');
        $this->assertStringStartsWith('/login', (string)($pagina->headers['Location'] ?? ''));
    }

    #[Test]
    public function con_una_credenziale_valida_si_entra_e_spenta_scaduta_o_cancellata_no(): void
    {
        $this->conCredenziale();
        $this->assertSame(200, $this->entra()->status, 'credenziale valida: si entra');

        $this->pdo->prepare('UPDATE teacher_access_credentials_data SET active = 0 WHERE id = ?')->execute([$this->credenziale]);
        $this->assertSame(401, $this->entra()->status, 'spenta dal docente: non più');

        $this->pdo->prepare('UPDATE teacher_access_credentials_data SET active = 1, expires_at = ? WHERE id = ?')
            ->execute([date('Y-m-d', strtotime('-1 day')), $this->credenziale]);
        $this->conCredenziale();
        $this->assertSame(401, $this->entra()->status, 'scaduta: non più');

        $this->pdo->prepare('UPDATE teacher_access_credentials_data SET expires_at = NULL WHERE id = ?')->execute([$this->credenziale]);
        $this->conCredenziale();
        $this->assertSame(200, $this->entra()->status);
        $this->pdo->prepare('DELETE FROM teacher_access_credentials_data WHERE id = ?')->execute([$this->credenziale]);
        $this->assertSame(401, $this->entra()->status, 'cancellata: non più');
    }

    #[Test]
    public function con_un_account_valgono_le_porte_di_prima(): void
    {
        $_SESSION = ['autenticato' => true, 'username' => 'zzscc_doc', 'user_id' => $this->docente, 'user_role' => 'teacher'];
        $this->assertSame(200, $this->entra()->status, 'un docente studia');

        $_SESSION['must_change_password'] = true;
        $this->assertSame(403, $this->entra()->status, 'con la password da cambiare, come prima: la porta di auth resta');
    }

    #[Test]
    public function da_ospite_la_pagina_legacy_degli_esercizi_non_esiste_e_la_barra_prende_la_scuola_della_credenziale(): void
    {
        $this->conCredenziale();
        $_SERVER['REQUEST_URI'] = '/studio/SCI/2/MAT/Frazioni';
        $res = (new ContentStudyController())->topicsPage(new Request(), ['type' => 'SCI', 'ind' => '2', 'cls' => 'MAT', 'subj' => 'Frazioni']);
        $this->assertSame(404, $res->status, 'la tabella legacy non conosce la credenziale');

        $metodo = new \ReflectionMethod(SidebarConfigController::class, 'instituteId');
        $this->assertSame($this->scuola, $metodo->invoke(new SidebarConfigController(), null, 'student'));
        $_SESSION = [];
        ClassAccessGrant::resetCache();
        $this->assertSame(0, $metodo->invoke(new SidebarConfigController(), null, 'student'), 'senza credenziale, nessuna scuola');
    }

    #[Test]
    public function il_gruppo_dello_studio_e_di_sola_lettura_e_la_sua_porta_e_registrata(): void
    {
        $mappa = (new \ReflectionProperty(Kernel::class, 'middlewareMap'))->getValue(new Kernel(new Router()));
        $this->assertSame(StudioMiddleware::class, $mappa['studio'] ?? null, 'senza alias il Kernel salterebbe la porta in silenzio');

        $router = new Router();
        require dirname(__DIR__, 2) . '/routes/web.php';
        $studio = [];
        foreach ($router->routes() as $rotta) {
            if (!\in_array('studio', $rotta->middleware, true)) {
                continue;
            }
            foreach ($rotta->methods as $metodo) {
                if ($metodo === 'HEAD') {
                    continue;
                }
                $studio[] = $metodo . ' ' . $rotta->pattern;
            }
        }
        sort($studio);
        $attese = self::ROTTE_DELLO_STUDIO;
        sort($attese);
        $this->assertSame($attese, $studio, 'solo queste rotte, e nessun altra');
        // La regola resta scritta: di tutto il gruppo, una sola non è lettura.
        $scritture = array_values(array_filter($studio, static fn(string $r): bool => !str_starts_with($r, 'GET ')));
        $this->assertSame(['POST /tikz/render'], $scritture,
            "l'unica rotta che non legge e' la compilazione di una figura");
        foreach ($router->routes() as $rotta) {
            if (\in_array('studio', $rotta->middleware, true)) {
                $this->assertNotContains('auth', $rotta->middleware, $rotta->pattern);
            }
        }
    }
}
