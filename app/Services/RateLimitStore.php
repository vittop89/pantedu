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
 * Pulizia DB: `tools/rate_limit_cleanup.php`, una volta al giorno da
 * `pantedu-rate-limit-cleanup.timer`, toglie le righe più vecchie di
 * CONSERVAZIONE_SECONDI. 23/9/2026: fino a oggi qui c'era «cron daily», ma
 * nessun cron, unità o workflow lanciava lo script, e la tabella conservava
 * l'IP in chiaro senza termine, mentre registro dei trattamenti e DPIA
 * dichiarano una pulizia giornaliera (A-84 e DOC-30 della revisione del 23/9).
 */
final class RateLimitStore
{
    /**
     * L'età oltre la quale la pulizia toglie una riga di `rate_limits`: un'ora.
     * Con la pulizia una volta al giorno, una riga resta al più un giorno e
     * un'ora — «fino alla pulizia del giorno dopo», come dice il registro.
     *
     * Non meno della finestra più lunga dichiarata da una rotta (`rate:x,n,w`,
     * oggi 3600 per `dpo` e `takedown`): la pulizia toglierebbe colpi ancora
     * dentro la finestra, e il limite non opererebbe più. Lo impone
     * tests/Unit/PuliziaDelLimitatoreTest.php.
     */
    public const CONSERVAZIONE_SECONDI = 3600;

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

    /**
     * Pulizia globale: elimina i colpi più vecchi di `olderThanSeconds`. Solo DB.
     *
     * 23/9/2026 — senza database non risponde più 0: lancia. «Zero righe tolte»
     * e «non ho potuto guardare» non sono la stessa cosa, e lo script che la
     * chiama da un timer deve uscire con errore perché l'avviso parta.
     *
     * @throws \RuntimeException se il database non risponde
     */
    public static function purgeDb(int $olderThanSeconds = self::CONSERVAZIONE_SECONDI): int
    {
        if (!Database::isAvailable()) {
            throw new \RuntimeException('database non disponibile: rate_limits non è stata pulita');
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
        $env = \strtolower((string)\App\Core\Config::get('security.rate_limit_backend', 'auto'));
        if ($env === 'session' || $env === 'db') {
            return $env;
        }
        return Database::isAvailable() ? 'db' : 'session';
    }
}
