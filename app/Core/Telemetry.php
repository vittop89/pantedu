<?php

declare(strict_types=1);

namespace App\Core;

use App\Middleware\RequestIdMiddleware;

/**
 * Phase 25.E4.3 — Lightweight tracing helper (no full OpenTelemetry SDK).
 *
 * Pattern minimo per inserire span timing nei controller/service critical-path:
 *
 *   $span = Telemetry::span('repo.find', ['id' => $id]);
 *   try {
 *       // ... lavoro ...
 *       $span->ok();
 *   } catch (\Throwable $e) {
 *       $span->error($e);
 *       throw $e;
 *   } finally {
 *       $span->end();
 *   }
 *
 * Output: 1 JSON log line per span con `trace_id`, `parent_id`, `name`,
 * `duration_ms`, `outcome` ('ok'/'error'), `attrs`. Compatibile con
 * pipeline JSON log → Loki/Datadog/Splunk per visualizzazione downstream.
 *
 * trace_id = X-Request-ID (Phase 25.E4 RequestIdMiddleware), così tutti
 * gli span della stessa request sono correlati. parent_id da stack di
 * span attivi (in-process, no propagation cross-process — out of scope).
 *
 * Per rollout futuro completo OpenTelemetry SDK:
 *   - Sostituire writeLog() con OTel Exporter
 *   - Mantenere API span() invariata (drop-in replacement)
 *
 * Toggle abilitazione: env `TELEMETRY_ENABLED=1`. Default OFF (no overhead
 * production se non richiesto). Span() ritorna Noop quando disabilitato.
 */
final class Telemetry
{
    /** @var array<int, Span> stack span attivi per parent_id resolution */
    private static array $stack = [];

    /**
     * Avvia un nuovo span. Ritorna oggetto Span su cui chiamare ok/error/end.
     *
     * @param array<string, scalar> $attrs Metadata dello span (usato come tag)
     */
    public static function span(string $name, array $attrs = []): Span
    {
        if (!self::enabled()) {
            return new NoopSpan();
        }

        $traceId = RequestIdMiddleware::currentRequestId() ?? bin2hex(random_bytes(8));
        $parentId = !empty(self::$stack) ? end(self::$stack)->spanId : null;

        $span = new Span($name, $traceId, $parentId, $attrs);
        self::$stack[] = $span;
        return $span;
    }

    /** Pop dallo stack quando lo span termina (chiamato da Span::end). */
    public static function popStack(Span $span): void
    {
        $top = end(self::$stack);
        if ($top && $top->spanId === $span->spanId) {
            array_pop(self::$stack);
        }
    }

    public static function enabled(): bool
    {
        return ($_ENV['TELEMETRY_ENABLED'] ?? '') === '1';
    }
}
