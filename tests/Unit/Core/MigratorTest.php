<?php

namespace Tests\Unit\Core;

use App\Core\Migrator;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Phase 20 — Migrator unit test sulle funzioni file-based.
 * I test di esecuzione SQL effettiva (run, tracking, skip) richiedono
 * MySQL live → coperti da integration test manuale tramite
 * `php tools/migrate.php`.
 */
final class MigratorTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        $this->tmpDir = \sys_get_temp_dir() . '/fm_mig_' . \uniqid();
        \mkdir($this->tmpDir, 0755, true);
    }

    protected function tearDown(): void
    {
        if (\is_dir($this->tmpDir)) {
            foreach (\glob($this->tmpDir . '/*') ?: [] as $f) @\unlink($f);
            @\rmdir($this->tmpDir);
        }
    }

    /**
     * Costruisce Migrator via reflection senza richiedere PDO reale.
     * I test su run() richiedono MySQL live → coperti da integration
     * manuali tramite `php tools/migrate.php --status`.
     */
    private function mig(?string $dir = null): Migrator
    {
        $ref = new \ReflectionClass(Migrator::class);
        $m = $ref->newInstanceWithoutConstructor();
        $dirProp = $ref->getProperty('migrationsDir');
        $dirProp->setValue($m, $dir ?? $this->tmpDir);
        return $m;
    }

    private function writeMig(string $name, string $sql = '-- noop'): void
    {
        \file_put_contents($this->tmpDir . '/' . $name, $sql);
    }

    #[Test]
    public function discoverAll_returns_empty_when_dir_empty(): void
    {
        // nessun file nel dir
        $this->assertSame([], $this->mig()->discoverAll());
    }

    #[Test]
    public function discoverAll_orders_naturally(): void
    {
        $this->writeMig('010_b.sql');
        $this->writeMig('002_a.sql');
        $this->writeMig('001_c.sql');
        $files = $this->mig()->discoverAll();
        $this->assertSame(['001_c.sql', '002_a.sql', '010_b.sql'], $files);
    }

    #[Test]
    public function discoverAll_filters_non_sql_files(): void
    {
        $this->writeMig('001_real.sql');
        \file_put_contents($this->tmpDir . '/README.md', '# doc');
        \file_put_contents($this->tmpDir . '/notes.txt', 'x');
        $this->assertSame(['001_real.sql'], $this->mig()->discoverAll());
    }

    #[Test]
    public function discoverAll_missing_dir_returns_empty(): void
    {
        $m = $this->mig('/nonexistent/path/123');
        $this->assertSame([], $m->discoverAll());
    }

    /**
     * Phase 25.E3 — verifica costanti advisory lock per multi-server safety.
     * Il behavior runtime di GET_LOCK richiede MySQL → integration test.
     */
    #[Test]
    public function advisory_lock_constants_are_defined(): void
    {
        $this->assertSame('pantedu.migrator', Migrator::LOCK_NAME);
        $this->assertSame(60, Migrator::LOCK_TIMEOUT_SEC);
    }
}
