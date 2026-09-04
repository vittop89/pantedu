<?php

namespace App\Services;

use App\Core\Database;
use App\Core\Session;

/**
 * Phase 19 — Storage-agnostic rate limit state.
 *
 * Backend selezionato via env RATE_LIMIT_BACKEND=session|db.
 * Default: auto — DB se Database::isAvailable, altrimenti session.
 * API: hits(bucket, window) ritorna timestamps nel window; append(bucket)
 * aggiunge hit corrente. Il middleware applica la policy (count vs limit).
 *
 * Cleanup DB: cron daily `tools/rate_limit_cleanup.php` elimina ts < -1h.
 */
final class RateLimitStore
{
    public function __construct(
        private readonly ?string $backend = null,
    ) {
    }

    /**
     * Ritorna timestamps del bucket entro `windowSeconds` (filtro automatico).
     * @return list<int>
     */
    public function hits(string $bucket, int $windowSeconds): array
    {
        $now    = \time();
        $cutoff = $now - $windowSeconds;
        return $this->backend() === 'db'
            ? $this->hitsDb($bucket, $cutoff)
            : $this->hitsSession($bucket, $cutoff);
    }

    /** Aggiunge un hit al bucket con timestamp corrente. */
    public function append(string $bucket, ?string $ip = null): void
    {
        if ($this->backend() === 'db') {
            $this->appendDb($bucket, $ip);
        } else {
            $this->appendSession($bucket);
        }
    }

    /** Cleanup global: elimina hit più vecchi di `olderThanSeconds`. Solo DB. */
    public static function purgeDb(int $olderThanSeconds = 3600): int
    {
        if (!Database::isAvailable()) {
            return 0;
        }
        $stmt = Database::connection()->prepare('DELETE FROM rate_limits WHERE ts < ?');
        $stmt->execute([\time() - $olderThanSeconds]);
        return $stmt->rowCount();
    }

    // ── Session backend ──

    private function hitsSession(string $bucket, int $cutoff): array
    {
        $raw = Session::get("rate:$bucket", []);
        if (!\is_array($raw)) {
            $raw = [];
        }
        return \array_values(\array_filter(
            \array_map('intval', $raw),
            fn($t) => $t > $cutoff
        ));
    }

    private function appendSession(string $bucket): void
    {
        $key = "rate:$bucket";
        $hits = Session::get($key, []);
        if (!\is_array($hits)) {
            $hits = [];
        }
        $hits[] = \time();
        // cap 200 entries per evitare bloat sessione
        if (\count($hits) > 200) {
            $hits = \array_slice($hits, -200);
        }
        Session::put($key, $hits);
    }

    // ── DB backend ──

    private function hitsDb(string $bucket, int $cutoff): array
    {
        try {
            $stmt = Database::connection()->prepare(
                'SELECT ts FROM rate_limits WHERE bucket = ? AND ts > ? ORDER BY ts ASC'
            );
            $stmt->execute([$bucket, $cutoff]);
            return \array_map('intval', $stmt->fetchAll(\PDO::FETCH_COLUMN));
        } catch (\Throwable) {
            return $this->hitsSession($bucket, $cutoff); // fallback on error
        }
    }

    private function appendDb(string $bucket, ?string $ip): void
    {
        try {
            $stmt = Database::connection()->prepare(
                'INSERT INTO rate_limits (bucket, ts, ip_address) VALUES (?, ?, ?)'
            );
            $stmt->execute([$bucket, \time(), $ip]);
        } catch (\Throwable) {
            $this->appendSession($bucket); // fallback
        }
    }

    private function backend(): string
    {
        if ($this->backend !== null) {
            return $this->backend;
        }
        $env = \strtolower((string)(\getenv('RATE_LIMIT_BACKEND') ?: 'auto'));
        if ($env === 'session' || $env === 'db') {
            return $env;
        }
        return Database::isAvailable() ? 'db' : 'session';
    }
}
