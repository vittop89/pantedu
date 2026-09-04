<?php

namespace App\Jobs;

use App\Core\Database;
use PDO;

/**
 * Phase 17 — Queue leggera basata su `jobs` table. Niente dipendenze
 * esterne (no Redis/RabbitMQ). Adatta per traffico modesto single-worker.
 *
 * Flow:
 *   dispatch()   → INSERT pending
 *   reserve()    → SELECT + UPDATE status=running (atomic via FOR UPDATE)
 *   complete()   → status=done + completed_at
 *   fail()       → attempts++, re-queue con backoff OR failed se max_attempts
 *
 * Worker CLI: `php bin/worker.php [--queue=default] [--once]`.
 */
class JobRepository
{
    public function __construct(private ?PDO $pdo = null)
    {
        $this->pdo = $pdo ?? Database::connection();
    }

    /**
     * Inserisce un job in coda. `payload` JSON-encodato.
     * `$delay` in secondi: se >0, il job non sarà reserve()-abile fino a T+delay.
     */
    public function dispatch(
        string $handler,
        array $payload,
        string $queue = 'default',
        int $delay = 0,
        int $maxAttempts = 3,
    ): int {
        $sql = 'INSERT INTO jobs (queue, handler, payload, status, max_attempts, available_at)
                VALUES (?, ?, ?, "pending", ?, ?)';
        $st = $this->pdo->prepare($sql);
        $st->execute([
            $queue,
            $handler,
            (string)json_encode($payload, JSON_UNESCAPED_UNICODE),
            $maxAttempts,
            time() + max(0, $delay),
        ]);
        return (int)$this->pdo->lastInsertId();
    }

    /**
     * Atomic reserve: prende il primo pending su `queue` disponibile, lo
     * marca running, ritorna la row o null se niente disponibile.
     * FOR UPDATE SKIP LOCKED evita contesa se più worker.
     */
    public function reserve(string $queue, string $workerId): ?array
    {
        $this->pdo->beginTransaction();
        try {
            $sel = $this->pdo->prepare(
                'SELECT * FROM jobs
                 WHERE queue = ? AND status = "pending" AND available_at <= ?
                 ORDER BY id ASC LIMIT 1
                 FOR UPDATE SKIP LOCKED'
            );
            $sel->execute([$queue, time()]);
            $row = $sel->fetch(PDO::FETCH_ASSOC);
            if (!$row) {
                $this->pdo->rollBack();
                return null;
            }
            $upd = $this->pdo->prepare(
                'UPDATE jobs SET status = "running", reserved_at = ?, reserved_by = ?, attempts = attempts + 1
                 WHERE id = ?'
            );
            $upd->execute([time(), $workerId, $row['id']]);
            $this->pdo->commit();
            $row['status'] = 'running';
            $row['attempts'] = (int)$row['attempts'] + 1;
            return $row;
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    public function complete(int $id): void
    {
        $st = $this->pdo->prepare(
            'UPDATE jobs SET status = "done", completed_at = ?, last_error = NULL
             WHERE id = ?'
        );
        $st->execute([time(), $id]);
    }

    /**
     * Segna un job come failed. Se attempts < max_attempts → re-queue con
     * backoff esponenziale (min 10s, max 1h). Altrimenti → status=failed.
     */
    public function fail(int $id, string $error): void
    {
        $row = $this->pdo->query("SELECT attempts, max_attempts FROM jobs WHERE id = $id")
            ->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return;
        }
        $attempts = (int)$row['attempts'];
        $maxAttempts = (int)$row['max_attempts'];
        if ($attempts >= $maxAttempts) {
            $st = $this->pdo->prepare(
                'UPDATE jobs SET status = "failed", last_error = ? WHERE id = ?'
            );
            $st->execute([$error, $id]);
            return;
        }
        $backoff = min(3600, 10 * (2 ** ($attempts - 1)));
        $st = $this->pdo->prepare(
            'UPDATE jobs SET status = "pending", available_at = ?, last_error = ?,
                            reserved_at = NULL, reserved_by = NULL
             WHERE id = ?'
        );
        $st->execute([time() + $backoff, $error, $id]);
    }

    /** Conta job per stato (utile per monitoring). */
    public function stats(): array
    {
        $rows = $this->pdo->query(
            'SELECT status, COUNT(*) AS n FROM jobs GROUP BY status'
        )->fetchAll(PDO::FETCH_ASSOC);
        $out = ['pending' => 0, 'running' => 0, 'done' => 0, 'failed' => 0, 'cancelled' => 0];
        foreach ($rows as $r) {
            $out[$r['status']] = (int)$r['n'];
        }
        return $out;
    }
}
