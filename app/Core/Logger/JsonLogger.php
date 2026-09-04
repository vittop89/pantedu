<?php

namespace App\Core\Logger;

use Psr\Log\AbstractLogger;
use Psr\Log\LogLevel;

/**
 * Phase 17 — PSR-3 Logger con output JSON strutturato.
 *
 * Scrive una riga JSON per evento su `$fh` (default stderr). Formato:
 *   {"ts":"2026-04-18T12:00:00+02:00","level":"info","msg":"...","ctx":{...},
 *    "req":{"ip":"1.2.3.4","method":"GET","path":"/x"}}
 *
 * Compatibile con log aggregator (Loki/ELK/Datadog) che ingeriscono JSON.
 * Niente filesystem file locking: una sola scrittura atomica per riga.
 *
 * Uso:
 *   $log = new JsonLogger();
 *   $log->info('user login', ['user_id' => 42]);
 *   $log->error('db query failed', ['query' => $sql, 'err' => $e->getMessage()]);
 *
 * Placeholders PSR-3:
 *   $log->warning('rate limit {user}', ['user' => $username]);  // interpola
 */
final class JsonLogger extends AbstractLogger
{
    /** @var resource */
    private $fh;
    private string $minLevel;

    private const LEVELS = [
        LogLevel::DEBUG => 0, LogLevel::INFO => 1, LogLevel::NOTICE => 2,
        LogLevel::WARNING => 3, LogLevel::ERROR => 4, LogLevel::CRITICAL => 5,
        LogLevel::ALERT => 6, LogLevel::EMERGENCY => 7,
    ];

    /** @param resource|null $fh */
    public function __construct($fh = null, string $minLevel = LogLevel::INFO)
    {
        $this->fh = $fh ?? STDERR;
        $this->minLevel = $minLevel;
    }

    public function log($level, \Stringable|string $message, array $context = []): void
    {
        if (!isset(self::LEVELS[$level]) || self::LEVELS[$level] < self::LEVELS[$this->minLevel]) {
            return;
        }
        $line = [
            'ts'    => date('c'),
            'level' => $level,
            'msg'   => $this->interpolate((string)$message, $context),
            'ctx'   => $this->sanitizeCtx($context),
        ];
        // Phase 25.E4 — request_id correlation (set da RequestIdMiddleware).
        // Permette tracing end-to-end: log entries della stessa request hanno
        // lo stesso rid. CLI: skip se non set.
        $rid = $_SERVER['X_REQUEST_ID'] ?? null;
        if (is_string($rid) && $rid !== '') {
            $line['rid'] = $rid;
        }
        // Request metadata (se CLI, SERVER vuoto → skippa).
        if (!empty($_SERVER['REQUEST_METHOD'] ?? '')) {
            $line['req'] = [
                'ip'     => $_SERVER['REMOTE_ADDR'] ?? null,
                'method' => $_SERVER['REQUEST_METHOD'] ?? null,
                'path'   => $_SERVER['REQUEST_URI'] ?? null,
            ];
        }
        $encoded = (string)json_encode($line, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        fwrite($this->fh, $encoded . "\n");
    }

    /** Sostituisce `{placeholder}` nel message con context[placeholder]. */
    private function interpolate(string $message, array $context): string
    {
        if (!str_contains($message, '{')) {
            return $message;
        }
        $replace = [];
        foreach ($context as $k => $v) {
            if (is_scalar($v) || $v === null) {
                $replace['{' . $k . '}'] = (string)($v ?? 'null');
            }
        }
        return strtr($message, $replace);
    }

    /** Evita oggetti non-serializzabili (Closure, resource, etc). */
    private function sanitizeCtx(array $ctx): array
    {
        $out = [];
        foreach ($ctx as $k => $v) {
            if ($v instanceof \Throwable) {
                $out[$k] = [
                    'class' => $v::class,
                    'msg'   => $v->getMessage(),
                    'file'  => $v->getFile() . ':' . $v->getLine(),
                ];
            } elseif (is_scalar($v) || is_array($v) || $v === null) {
                $out[$k] = $v;
            } else {
                $out[$k] = '[' . (is_object($v) ? $v::class : gettype($v)) . ']';
            }
        }
        return $out;
    }
}
