<?php

declare(strict_types=1);

namespace Tests\Unit\Support;

use App\Support\PdoTransactionRunner;
use PDO;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class PdoTransactionRunnerTest extends TestCase
{
    private function pdo(): PDO
    {
        if (!\in_array('sqlite', PDO::getAvailableDrivers(), true)) {
            $this->markTestSkipped('pdo_sqlite non disponibile in questo runtime');
        }
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->exec('CREATE TABLE t (id INTEGER PRIMARY KEY, v TEXT)');
        return $pdo;
    }

    #[Test]
    public function commits_on_success(): void
    {
        $pdo = $this->pdo();
        $tx  = new PdoTransactionRunner($pdo);

        $result = $tx->run(function () use ($pdo) {
            $pdo->exec("INSERT INTO t (v) VALUES ('a'), ('b')");
            return 'ok';
        });

        $this->assertSame('ok', $result);
        $count = (int)$pdo->query('SELECT COUNT(*) FROM t')->fetchColumn();
        $this->assertSame(2, $count);
        $this->assertFalse($pdo->inTransaction());
    }

    #[Test]
    public function rolls_back_on_exception(): void
    {
        $pdo = $this->pdo();
        $tx  = new PdoTransactionRunner($pdo);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('boom');

        try {
            $tx->run(function () use ($pdo) {
                $pdo->exec("INSERT INTO t (v) VALUES ('a')");
                throw new RuntimeException('boom');
            });
        } finally {
            $count = (int)$pdo->query('SELECT COUNT(*) FROM t')->fetchColumn();
            $this->assertSame(0, $count, 'rollback deve aver scartato la INSERT');
            $this->assertFalse($pdo->inTransaction());
        }
    }

    #[Test]
    public function reentrant_skips_nested_begin_commit(): void
    {
        $pdo = $this->pdo();
        $tx  = new PdoTransactionRunner($pdo);

        $pdo->beginTransaction();
        try {
            $result = $tx->run(function () use ($pdo) {
                $pdo->exec("INSERT INTO t (v) VALUES ('inner')");
                $this->assertTrue($pdo->inTransaction());
                return 1;
            });
            $this->assertSame(1, $result);
            // La tx esterna deve essere ancora aperta: il run() interno
            // non ha committato perche' non era owner.
            $this->assertTrue($pdo->inTransaction());
            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }

        $count = (int)$pdo->query('SELECT COUNT(*) FROM t')->fetchColumn();
        $this->assertSame(1, $count);
    }

    #[Test]
    public function fake_pdo_records_begin_commit_on_success(): void
    {
        $pdo = new FakePdo();
        $tx  = new PdoTransactionRunner($pdo);

        $tx->run(fn() => 'ok');

        $this->assertSame(['begin', 'commit'], $pdo->calls);
        $this->assertFalse($pdo->inTransaction());
    }

    #[Test]
    public function fake_pdo_records_begin_rollback_on_exception(): void
    {
        $pdo = new FakePdo();
        $tx  = new PdoTransactionRunner($pdo);

        try {
            $tx->run(function () {
                throw new RuntimeException('x');
            });
            $this->fail('expected throw');
        } catch (RuntimeException) {
            // ok
        }

        $this->assertSame(['begin', 'rollback'], $pdo->calls);
        $this->assertFalse($pdo->inTransaction());
    }

    #[Test]
    public function reentrant_propagates_exception_without_consuming_outer_tx(): void
    {
        $pdo = $this->pdo();
        $tx  = new PdoTransactionRunner($pdo);

        $pdo->beginTransaction();
        $caught = null;
        try {
            $tx->run(function () {
                throw new RuntimeException('inner_boom');
            });
        } catch (RuntimeException $e) {
            $caught = $e;
        }

        $this->assertNotNull($caught);
        $this->assertSame('inner_boom', $caught->getMessage());
        // Owner esterno deve poter ancora controllare: la tx non e' stata
        // chiusa dal run() interno (non era owner).
        $this->assertTrue($pdo->inTransaction());
        $pdo->rollBack();
    }
}

/**
 * Mock PDO che bypassa il costruttore (no DSN reale): registra le call
 * a begin/commit/rollback per asserzioni di ordering. Non sa eseguire SQL.
 */
final class FakePdo extends PDO
{
    public array $calls = [];
    private bool $inTx = false;

    public function __construct()
    {
        // Bypass parent: niente DSN/driver lookup.
    }

    public function beginTransaction(): bool
    {
        $this->calls[] = 'begin';
        $this->inTx = true;
        return true;
    }

    public function commit(): bool
    {
        $this->calls[] = 'commit';
        $this->inTx = false;
        return true;
    }

    public function rollBack(): bool
    {
        $this->calls[] = 'rollback';
        $this->inTx = false;
        return true;
    }

    public function inTransaction(): bool
    {
        return $this->inTx;
    }
}
