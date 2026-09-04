<?php

namespace Tests\Unit\Core;

use App\Core\Logger\JsonLogger;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\LogLevel;
use Psr\Log\LoggerInterface;

final class JsonLoggerTest extends TestCase
{
    /** @return array{JsonLogger, resource} */
    private function make(string $minLevel = LogLevel::DEBUG): array
    {
        $fh = fopen('php://memory', 'w+');
        return [new JsonLogger($fh, $minLevel), $fh];
    }

    /** @param resource $fh */
    private function readLines($fh): array
    {
        rewind($fh);
        $out = [];
        while (($line = fgets($fh)) !== false) {
            $out[] = json_decode(trim($line), true);
        }
        return $out;
    }

    #[Test]
    public function implements_psr3(): void
    {
        [$log] = $this->make();
        $this->assertInstanceOf(LoggerInterface::class, $log);
    }

    #[Test]
    public function writes_json_with_level_and_message(): void
    {
        [$log, $fh] = $this->make();
        $log->info('hello');
        $lines = $this->readLines($fh);
        $this->assertCount(1, $lines);
        $this->assertSame('info', $lines[0]['level']);
        $this->assertSame('hello', $lines[0]['msg']);
        $this->assertArrayHasKey('ts', $lines[0]);
        $this->assertArrayHasKey('ctx', $lines[0]);
    }

    #[Test]
    public function interpolates_placeholders(): void
    {
        [$log, $fh] = $this->make();
        $log->warning('user {u} over limit', ['u' => 'vitto']);
        $lines = $this->readLines($fh);
        $this->assertSame('user vitto over limit', $lines[0]['msg']);
    }

    #[Test]
    public function exception_context_flattened(): void
    {
        [$log, $fh] = $this->make();
        $e = new \RuntimeException('boom');
        $log->error('failed', ['exc' => $e]);
        $lines = $this->readLines($fh);
        $this->assertSame(\RuntimeException::class, $lines[0]['ctx']['exc']['class']);
        $this->assertSame('boom', $lines[0]['ctx']['exc']['msg']);
    }

    #[Test]
    public function respects_min_level_filtering(): void
    {
        [$log, $fh] = $this->make(minLevel: LogLevel::WARNING);
        $log->debug('noise');
        $log->info('also noise');
        $log->warning('important');
        $log->error('critical');
        $lines = $this->readLines($fh);
        $this->assertCount(2, $lines);
        $this->assertSame('warning', $lines[0]['level']);
        $this->assertSame('error',   $lines[1]['level']);
    }

    #[Test]
    public function sanitizes_non_scalar_context(): void
    {
        [$log, $fh] = $this->make();
        $log->info('x', [
            'closure' => fn() => 'x',
            'resource' => fopen('php://memory', 'r'),
            'scalar' => 42,
        ]);
        $lines = $this->readLines($fh);
        $this->assertStringContainsString('[', $lines[0]['ctx']['closure']);
        $this->assertStringContainsString('[', $lines[0]['ctx']['resource']);
        $this->assertSame(42, $lines[0]['ctx']['scalar']);
    }
}
