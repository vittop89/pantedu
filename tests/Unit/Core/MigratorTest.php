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

    /**
     * Due migration con lo stesso prefisso numerico vanno rifiutate prima
     * dell'esecuzione: il prefisso non identifica piu' nulla e l'ordine fra
     * le due dipende dal resto del nome (rilievo A16, revisione 2026-09).
     */
    #[Test]
    public function duplicatePrefixes_reports_files_sharing_a_number(): void
    {
        $dup = Migrator::duplicatePrefixes(['001_a.sql', '002_b.sql', '002_c.sql', '003_d.sql']);
        $this->assertSame(['002' => ['002_b.sql', '002_c.sql']], $dup);
    }

    #[Test]
    public function duplicatePrefixes_is_empty_when_numbers_are_unique(): void
    {
        $this->assertSame([], Migrator::duplicatePrefixes(['001_a.sql', '002_b.sql', 'README.md']));
    }

    /** La coppia storica 100_* e' tollerata, ma solo esattamente quella. */
    #[Test]
    public function duplicatePrefixes_tolerates_only_the_known_historical_pair(): void
    {
        $known = ['100_audit_ip_ua_hash.sql', '100_classi_indirizzo.sql'];
        $this->assertSame([], Migrator::duplicatePrefixes($known));

        $withThird = [...$known, '100_altro.sql'];
        $dup = Migrator::duplicatePrefixes($withThird);
        $this->assertSame(['100'], \array_map('strval', \array_keys($dup)));
        $this->assertCount(3, $dup['100'] ?? []);
    }

    /** Il repository reale non deve contenere doppioni oltre quelli noti. */
    #[Test]
    public function repository_migrations_have_unique_prefixes(): void
    {
        $m = $this->mig(\dirname(__DIR__, 3) . '/database/migrations');
        $this->assertSame([], Migrator::duplicatePrefixes($m->discoverAll()));
    }
}
