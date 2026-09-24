<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Span attivo. Stato (ok/error) si imposta prima di end().
 */
class Span
{
    public string $spanId;
    public string $traceId;
    public ?string $parentId;
    public string $name;
    /** @var array<string, scalar> */
    public array $attrs;
    public float $startMs;
    public string $outcome = 'unknown';
    public ?string $errorMsg = null;
    public bool $ended = false;

    /** @param array<string, scalar> $attrs */
    public function __construct(string $name, string $traceId, ?string $parentId, array $attrs = [])
    {
        $this->name = $name;
        $this->traceId = $traceId;
        $this->parentId = $parentId;
        $this->attrs = $attrs;
        $this->spanId = bin2hex(random_bytes(8));
        $this->startMs = microtime(true) * 1000;
    }

    public function ok(): void
    {
        $this->outcome = 'ok';
    }

    public function error(\Throwable $e): void
    {
        $this->outcome = 'error';
        $this->errorMsg = $e->getMessage();
    }

    /**
     * Aggiungi attribuiti runtime allo span.
     * @param array<string, scalar> $attrs
     */
    public function setAttrs(array $attrs): void
    {
        $this->attrs = array_merge($this->attrs, $attrs);
    }

    public function end(): void
    {
        if ($this->ended) {
            return;
        }
        $this->ended = true;
        $duration = microtime(true) * 1000 - $this->startMs;

        $line = [
            'ts'          => date('c'),
            'kind'        => 'span',
            'trace_id'    => $this->traceId,
            'span_id'     => $this->spanId,
            'parent_id'   => $this->parentId,
            'name'        => $this->name,
            'duration_ms' => round($duration, 2),
            'outcome'     => $this->outcome,
        ];
        if ($this->errorMsg) {
            $line['error'] = $this->errorMsg;
        }
        if ($this->attrs) {
            $line['attrs'] = $this->attrs;
        }

        $encoded = (string)json_encode($line, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        error_log($encoded);

        Telemetry::popStack($this);
    }
}
