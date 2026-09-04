<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Span no-op quando TELEMETRY_ENABLED=0. Zero overhead.
 */
final class NoopSpan extends Span
{
    public function __construct()
    {
    }
    public function ok(): void
    {
    }
    public function error(\Throwable $e): void
    {
    }
    public function setAttrs(array $attrs): void
    {
    }
    public function end(): void
    {
    }
}
