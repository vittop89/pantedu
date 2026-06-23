<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Core\Telemetry;
use App\Core\Span;
use App\Core\NoopSpan;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Phase 25.E4.3 — Test Telemetry / Span / NoopSpan.
 *
 * Verifica:
 *   1. Disabled (default): span() ritorna NoopSpan (zero overhead)
 *   2. Enabled: span() ritorna Span attivo con trace_id + span_id
 *   3. Stack annidato: child span ha parent_id = parent.span_id
 *   4. ok() / error() impostano outcome
 *   5. end() pop stack (defensive su out-of-order)
 *   6. setAttrs aggiunge metadata runtime
 *   7. end() idempotente
 */
final class TelemetryTest extends TestCase
{
    protected function setUp(): void
    {
        unset($_ENV['TELEMETRY_ENABLED'], $_SERVER['X_REQUEST_ID']);
    }

    public function testDisabledReturnsNoop(): void
    {
        $span = Telemetry::span('test');
        $this->assertInstanceOf(NoopSpan::class, $span);
    }

    public function testEnabledReturnsActiveSpan(): void
    {
        $_ENV['TELEMETRY_ENABLED'] = '1';
        $_SERVER['X_REQUEST_ID'] = 'test-rid-123';

        $span = Telemetry::span('test.op', ['key' => 'value']);
        $this->assertNotInstanceOf(NoopSpan::class, $span);
        $this->assertSame('test.op', $span->name);
        $this->assertSame('test-rid-123', $span->traceId);
        $this->assertNull($span->parentId, 'root span no parent');
        $this->assertSame(['key' => 'value'], $span->attrs);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{16}$/', $span->spanId);
        $span->end();
    }

    public function testNestedSpansHaveParentId(): void
    {
        $_ENV['TELEMETRY_ENABLED'] = '1';
        $_SERVER['X_REQUEST_ID'] = 'nested-trace';

        $parent = Telemetry::span('parent');
        $child = Telemetry::span('child');
        $this->assertSame($parent->spanId, $child->parentId);

        $child->end();
        $parent->end();
    }

    public function testOkAndErrorOutcome(): void
    {
        $_ENV['TELEMETRY_ENABLED'] = '1';

        $okSpan = Telemetry::span('ok-test');
        $okSpan->ok();
        $this->assertSame('ok', $okSpan->outcome);
        $okSpan->end();

        $errSpan = Telemetry::span('err-test');
        $errSpan->error(new RuntimeException('boom'));
        $this->assertSame('error', $errSpan->outcome);
        $this->assertSame('boom', $errSpan->errorMsg);
        $errSpan->end();
    }

    public function testSetAttrsAddsRuntime(): void
    {
        $_ENV['TELEMETRY_ENABLED'] = '1';

        $span = Telemetry::span('attrs-test', ['init' => 1]);
        $span->setAttrs(['runtime' => 'value', 'count' => 42]);
        $this->assertSame(['init' => 1, 'runtime' => 'value', 'count' => 42], $span->attrs);
        $span->end();
    }

    public function testEndIsIdempotent(): void
    {
        $_ENV['TELEMETRY_ENABLED'] = '1';

        $span = Telemetry::span('idempotent');
        $span->end();
        $this->assertTrue($span->ended);
        $span->end();  // No-op, no exception
        $this->assertTrue($span->ended);
    }

    public function testNoopSpanZeroOverhead(): void
    {
        unset($_ENV['TELEMETRY_ENABLED']);
        $span = Telemetry::span('noop');
        $span->ok();
        $span->error(new RuntimeException('ignored'));
        $span->setAttrs(['ignored' => 1]);
        $span->end();
        $this->assertInstanceOf(NoopSpan::class, $span);
        // Non vengono settati outcome/errorMsg (no init)
        $this->assertFalse(isset($span->traceId), 'NoopSpan no traceId init');
    }

    public function testTraceIdFallbackWhenNoRequestId(): void
    {
        $_ENV['TELEMETRY_ENABLED'] = '1';
        unset($_SERVER['X_REQUEST_ID']);

        $span = Telemetry::span('no-rid');
        $this->assertNotEmpty($span->traceId);
        $this->assertNotSame('', $span->traceId);
        $span->end();
    }
}
