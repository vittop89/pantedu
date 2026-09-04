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

    protected function setUp(): void
    {
        // Questo test copre la modalità FILE di OwnershipService. In suite, un
        // test precedente carica .env (DB_ENABLED=true) → OwnershipService::useDb()
        // passerebbe in DB mode e ignorerebbe il file → flaky. Forzo file mode
        // deterministico azzerando DB_ENABLED per la durata del test.
        $_ENV['DB_ENABLED'] = 'false';
        $_SERVER['DB_ENABLED'] = 'false';
        putenv('DB_ENABLED=false');
        // Config::load NON ha guard: ricarico così database.enabled rilegge il
        // DB_ENABLED=false appena impostato (un test precedente l'aveva messo a
        // true nella cache statica self::$items).
        \App\Core\Config::load(dirname(__DIR__, 2) . '/app/Config');

        $this->file = sys_get_temp_dir() . '/pantedu_own_' . uniqid() . '.json';
        $this->svc  = new OwnershipService($this->file);
    }

    protected function tearDown(): void
    {
        unset($_ENV['DB_ENABLED'], $_SERVER['DB_ENABLED']);
        putenv('DB_ENABLED');
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
