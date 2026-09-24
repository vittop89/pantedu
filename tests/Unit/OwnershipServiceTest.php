<?php

namespace Tests\Unit;

use App\Services\OwnershipService;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class OwnershipServiceTest extends TestCase
{
    private string $file;
    private OwnershipService $svc;

    /**
     * `DB_ENABLED` com'era prima che questo test lo spegnesse (`false` = non
     * c'era affatto).
     *
     * 2026-09-10 — prima `tearDown()` faceva `unset` e basta, senza ricaricare
     * la configurazione. Ma `database.enabled` vive in una cache statica, e
     * `setUp()` ce l'aveva appena scritto `false`: quel valore restava per
     * tutto il resto del processo. Siccome la suite gira con
     * `executionOrder="random"`, **ogni prova d'integrazione che capitava dopo
     * questa classe si saltava** con «database non disponibile» — senza un
     * rosso, senza un avviso, solo un totale verde più corto.
     *
     * Misurato: col seme 1 questa classe cade alla posizione 81 e
     * `PublicContentPolicyTest` alla 121, e quelle tre prove si saltavano; coi
     * semi da 2 a 8 l'ordine è diverso e giravano tutte.
     *
     * Un `unset` non basta nemmeno da solo: senza `DB_ENABLED` nell'ambiente,
     * `app/Config/database.php` vale `false` per difetto. Va rimesso il valore
     * di prima, e poi ricaricata la configurazione.
     *
     * `processo` è il valore nell'ambiente vero del processo (getenv), che
     * il bootstrap delle prove non tocca: Dotenv scrive solo $_ENV e $_SERVER.
     * Fino al 23/9/2026 tearDown lo ricostruiva da $_ENV, e lasciava
     * `DB_ENABLED=true` nell'ambiente vero. Un sottoprocesso lanciato dopo
     * (PuliziaDiRateLimitsTest) lo ereditava; Dotenv, immutabile, non lo
     * riscriveva in $_ENV, e con variables_order=GPCS la configurazione
     * leggeva «database spento». Con l'ordine casuale di phpunit.xml capitava
     * un giro su tre.
     *
     * @var array{env: string|false, server: string|false, processo: string|false}
     */
    private array $dbEnabledPrima = ['env' => false, 'server' => false, 'processo' => false];

    protected function setUp(): void
    {
        // Questo test copre la modalità FILE di OwnershipService: con
        // `DB_ENABLED=true` (che il bootstrap della suite carica da `.env`)
        // `OwnershipService::useDb()` passerebbe in modalità database e
        // ignorerebbe il file. Lo spengo per la durata della prova.
        $this->dbEnabledPrima = [
            'env'    => isset($_ENV['DB_ENABLED'])    ? (string)$_ENV['DB_ENABLED']    : false,
            'server' => isset($_SERVER['DB_ENABLED']) ? (string)$_SERVER['DB_ENABLED'] : false,
            'processo' => getenv('DB_ENABLED'),
        ];

        $_ENV['DB_ENABLED'] = 'false';
        $_SERVER['DB_ENABLED'] = 'false';
        putenv('DB_ENABLED=false');
        // Config::load NON ha guard: ricarico così database.enabled rilegge il
        // DB_ENABLED=false appena impostato.
        \App\Core\Config::load(dirname(__DIR__, 2) . '/app/Config');

        $this->file = sys_get_temp_dir() . '/pantedu_own_' . uniqid() . '.json';
        $this->svc  = new OwnershipService($this->file);
    }

    protected function tearDown(): void
    {
        $primaEnv    = $this->dbEnabledPrima['env'];
        $primaServer = $this->dbEnabledPrima['server'];

        if ($primaEnv === false) {
            unset($_ENV['DB_ENABLED']);
        } else {
            $_ENV['DB_ENABLED'] = $primaEnv;
        }
        if ($primaServer === false) {
            unset($_SERVER['DB_ENABLED']);
        } else {
            $_SERVER['DB_ENABLED'] = $primaServer;
        }
        // L'ambiente vero torna com'era, non com'è $_ENV (vedi $dbEnabledPrima).
        $primaProcesso = $this->dbEnabledPrima['processo'];
        putenv($primaProcesso !== false ? 'DB_ENABLED=' . $primaProcesso : 'DB_ENABLED');

        // La configurazione sta in una cache statica: senza questo, il `false`
        // scritto da setUp() sopravvive a tutta la suite.
        \App\Core\Config::load(dirname(__DIR__, 2) . '/app/Config');

        @unlink($this->file);
    }

    #[Test]
    public function empty_file_yields_zero_counts(): void
    {
        $c = $this->svc->counts('nobody');
        $this->assertSame(0, $c['mappe']);
        $this->assertSame(0, $c['eser']);
        $this->assertSame(0, $c['lab']);
        $this->assertSame(0, $c['verifiche']);
    }

    #[Test]
    public function assign_and_list(): void
    {
        $this->svc->assign('mario',  'mappe', '/mappe/limiti.html');
        $this->svc->assign('mario',  'eser',  '/eser/sc/eser_sc5s/MAT/1.php');
        $this->svc->assign('lucia',  'eser',  '/eser/ar/eser_ar5s/MAT/1.php');

        $marioList = $this->svc->listFor('mario');
        $this->assertSame(['/mappe/limiti.html'], $marioList['mappe']);
        $this->assertCount(1, $marioList['eser']);
        $this->assertCount(0, $marioList['lab']);

        $this->assertSame(1, $this->svc->counts('mario')['mappe']);
        $this->assertSame(1, $this->svc->counts('lucia')['eser']);
    }

    #[Test]
    public function assign_is_idempotent(): void
    {
        $this->svc->assign('mario', 'mappe', '/x');
        $this->svc->assign('mario', 'mappe', '/x');
        $this->assertCount(1, $this->svc->listFor('mario')['mappe']);
    }

    #[Test]
    public function unassign_removes_item(): void
    {
        $this->svc->assign('mario', 'mappe', '/x');
        $this->assertTrue($this->svc->unassign('mario', 'mappe', '/x'));
        $this->assertFalse($this->svc->unassign('mario', 'mappe', '/x'));
    }

    #[Test]
    public function rejects_invalid_kind(): void
    {
        $this->expectException(RuntimeException::class);
        $this->svc->assign('mario', 'bogus', '/x');
    }
}
