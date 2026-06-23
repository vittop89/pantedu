<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database;
use PDO;

/**
 * G22.S5 — CRUD per verifica_compile_jobs (queue async PDF compile).
 *
 * Lifecycle row:
 *   pending → running → done | retry → ... → done | failed
 *
 * Ownership: ogni row e' scoped per teacher_id (envelope crypto). Il
 * worker usa la TKEK del teacher per decifrare i blob della manifest
 * tex_files prima di mandarli a /compile-bundle.
 *
 * @phpstan-type Job array{
 *   id: int, doc_id: int, teacher_id: int, status: string,
 *   payload_hash: string, attempts: int, next_attempt_at: ?string,
 *   engine: string, passes: int, last_error: ?string,
 *   created_at: string, started_at: ?string, completed_at: ?string,
 * }
 */
class VerificaCompileJobRepository
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_RUNNING = 'running';
    public const STATUS_DONE    = 'done';
    public const STATUS_FAILED  = 'failed';
    public const STATUS_RETRY   = 'retry';

    public const MAX_ATTEMPTS = 3;

    /**
     * Enqueue idempotente: se esiste gia' un job (pending/running/done)
     * per la stessa (teacher, doc, payload_hash) lo ritorna invece di
     * crearne uno nuovo. Solo i job 'failed' vengono ignorati (utente
     * deve ri-richiedere esplicitamente).
     */
    public function enqueue(array $data): int
    {
        $existing = $this->findActive(
            (int)$data['teacher_id'],
            (int)$data['doc_id'],
            (string)$data['payload_hash']
        );
        if ($existing !== null) {
            return (int)$existing['id'];
        }

        $stmt = Database::connection()->prepare(
            'INSERT INTO verifica_compile_jobs
             (doc_id, teacher_id, status, payload_hash, engine, passes)
             VALUES (?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            (int)$data['doc_id'],
            (int)$data['teacher_id'],
            self::STATUS_PENDING,
            (string)$data['payload_hash'],
            (string)($data['engine'] ?? 'pdflatex'),
            (int)($data['passes'] ?? 2),
        ]);
        return (int)Database::connection()->lastInsertId();
    }

    /**
     * Trova un job attivo (pending/running/done/retry) per la dedup
     * dell'enqueue. 'failed' esclusi: ri-enqueue crea un nuovo job
     * dopo che un attempt definitivo e' fallito.
     */
    public function findActive(int $teacherId, int $docId, string $payloadHash): ?array
    {
        $stmt = Database::connection()->prepare(
            'SELECT * FROM verifica_compile_jobs
             WHERE teacher_id = ? AND doc_id = ? AND payload_hash = ?
               AND status IN (?, ?, ?, ?)
             ORDER BY id DESC LIMIT 1'
        );
        $stmt->execute([
            $teacherId, $docId, $payloadHash,
            self::STATUS_PENDING, self::STATUS_RUNNING,
            self::STATUS_DONE,    self::STATUS_RETRY,
        ]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ? $this->hydrate($row) : null;
    }

    public function find(int $jobId): ?array
    {
        $stmt = Database::connection()->prepare(
            'SELECT * FROM verifica_compile_jobs WHERE id = ? LIMIT 1'
        );
        $stmt->execute([$jobId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ? $this->hydrate($row) : null;
    }

    /**
     * Pesca FIFO il prossimo job processable e lo marca come 'running'
     * (lock leggero via UPDATE atomic). Ritorna null se la queue e'
     * vuota o tutti i pending sono in retry-backoff.
     *
     * Strategia anti-race:
     *   1. SELECT id WHERE status='pending' OR (status='retry' AND
     *      next_attempt_at <= NOW()) ORDER BY id LIMIT 1
     *   2. UPDATE ... SET status='running', started_at=NOW() WHERE id=?
     *      AND status IN ('pending','retry')   ← double-check
     *   3. Se rowCount=1: ritorna la row aggiornata.
     *      Altrimenti: race con altro worker, riprova.
     */
    public function pickNext(): ?array
    {
        $pdo = Database::connection();

        $sel = $pdo->prepare(
            "SELECT id FROM verifica_compile_jobs
             WHERE (status = 'pending')
                OR (status = 'retry' AND (next_attempt_at IS NULL OR next_attempt_at <= NOW()))
             ORDER BY id ASC LIMIT 1"
        );
        $sel->execute();
        $jobId = $sel->fetchColumn();
        if (!$jobId) {
            return null;
        }

        $upd = $pdo->prepare(
            "UPDATE verifica_compile_jobs
             SET status = 'running', started_at = NOW(), attempts = attempts + 1
             WHERE id = ? AND status IN ('pending', 'retry')"
        );
        $upd->execute([$jobId]);
        if ($upd->rowCount() !== 1) {
            // Race: altro worker ha gia' pickato. Ritorna null e ritenta
            // al prossimo tick cron.
            return null;
        }
        return $this->find((int)$jobId);
    }

    /**
     * G22.S8 — Resetta a 'retry' i job 'running' stagnanti da > $staleSec.
     * Defensive contro PHP timeout durante trigger-on-request (S7): se la
     * request che faceva inline-process e' stata kill-ata da FPM, il job
     * resta in 'running' per sempre e il cron non lo ripicka. Questo
     * cleanup li libera ogni tick.
     *
     * Strategia: status='retry', next_attempt_at=NOW (immediate re-pick),
     * attempts INVARIATO (l'incremento e' avvenuto al pick precedente).
     * last_error annotato per audit.
     *
     * @return int row liberate
     */
    public function resetStuckRunning(int $staleSec = 300): int
    {
        $stmt = Database::connection()->prepare(
            "UPDATE verifica_compile_jobs
             SET status = 'retry', next_attempt_at = NOW(),
                 last_error = CONCAT('stuck_running_reset_after_', ?, 's')
             WHERE status = 'running'
               AND started_at IS NOT NULL
               AND started_at < DATE_SUB(NOW(), INTERVAL ? SECOND)"
        );
        $stmt->execute([$staleSec, $staleSec]);
        return $stmt->rowCount();
    }

    /**
     * G22.S7 — Pick di un job SPECIFICO (non FIFO) atomico, per inline-
     * processing trigger-on-request. Setta status='running' solo se la
     * row e' attualmente pending o retry (e fuori backoff), altrimenti
     * ritorna null (gia' presa da un altro worker o ancora in attesa).
     *
     * Differenza da pickNext: questo NON cerca FIFO, agisce sul jobId
     * dato. Usato dal compileAsync endpoint per processare il job appena
     * enqueued nella stessa request.
     */
    public function pickSpecific(int $jobId): ?array
    {
        $upd = Database::connection()->prepare(
            "UPDATE verifica_compile_jobs
             SET status = 'running', started_at = NOW(), attempts = attempts + 1
             WHERE id = ? AND status IN ('pending', 'retry')
                AND (next_attempt_at IS NULL OR next_attempt_at <= NOW())"
        );
        $upd->execute([$jobId]);
        if ($upd->rowCount() !== 1) {
            return null;
        }
        return $this->find($jobId);
    }

    public function markDone(int $jobId, ?string $note = null): void
    {
        $stmt = Database::connection()->prepare(
            "UPDATE verifica_compile_jobs
             SET status = 'done', completed_at = NOW(), last_error = ?
             WHERE id = ?"
        );
        $stmt->execute([$note, $jobId]);
    }

    /**
     * Segna come failed (definitivo) o retry (con backoff esponenziale).
     * Politica: attempts < MAX_ATTEMPTS → retry con next_attempt_at +1m,
     * +5m, +15m. Al MAX_ATTEMPTS-esimo fallimento → status='failed'.
     */
    public function markFailedOrRetry(int $jobId, string $error): string
    {
        $job = $this->find($jobId);
        if (!$job) {
            return 'missing';
        }

        $attempts = (int)$job['attempts'];
        if ($attempts >= self::MAX_ATTEMPTS) {
            $stmt = Database::connection()->prepare(
                "UPDATE verifica_compile_jobs
                 SET status = 'failed', completed_at = NOW(), last_error = ?
                 WHERE id = ?"
            );
            $stmt->execute([self::truncate($error), $jobId]);
            return self::STATUS_FAILED;
        }

        $delaySec = match ($attempts) {
            1 => 60,    // 1 min dopo primo fail
            2 => 300,   // 5 min dopo secondo
            default => 900, // 15 min
        };
        $stmt = Database::connection()->prepare(
            "UPDATE verifica_compile_jobs
             SET status = 'retry', next_attempt_at = DATE_ADD(NOW(), INTERVAL ? SECOND),
                 last_error = ?
             WHERE id = ?"
        );
        $stmt->execute([$delaySec, self::truncate($error), $jobId]);
        return self::STATUS_RETRY;
    }

    /**
     * Lista jobs di un teacher (status endpoint UI). Limit predefinito
     * per evitare scan ampi.
     */
    public function listForTeacher(int $teacherId, int $limit = 50): array
    {
        $limit = max(1, min(200, $limit));
        $stmt = Database::connection()->prepare(
            'SELECT * FROM verifica_compile_jobs
             WHERE teacher_id = ?
             ORDER BY id DESC LIMIT ' . $limit
        );
        $stmt->execute([$teacherId]);
        return array_map([$this, 'hydrate'], $stmt->fetchAll(PDO::FETCH_ASSOC) ?: []);
    }

    /**
     * Cleanup periodico: cancella jobs done/failed piu' vecchi di N giorni.
     * Chiamato dal worker dopo ogni tick per limitare la crescita tabella.
     *
     * @return int row deleted
     */
    public function purgeOlderThan(int $days = 7): int
    {
        $stmt = Database::connection()->prepare(
            "DELETE FROM verifica_compile_jobs
             WHERE status IN ('done', 'failed')
               AND completed_at IS NOT NULL
               AND completed_at < DATE_SUB(NOW(), INTERVAL ? DAY)"
        );
        $stmt->execute([$days]);
        return $stmt->rowCount();
    }

    /** Conta jobs per status (telemetry / dashboard health). */
    public function countByStatus(?int $teacherId = null): array
    {
        $sql = 'SELECT status, COUNT(*) AS n FROM verifica_compile_jobs';
        $args = [];
        if ($teacherId !== null) {
            $sql .= ' WHERE teacher_id = ?';
            $args[] = $teacherId;
        }
        $sql .= ' GROUP BY status';
        $stmt = Database::connection()->prepare($sql);
        $stmt->execute($args);
        $out = ['pending' => 0, 'running' => 0, 'done' => 0, 'failed' => 0, 'retry' => 0];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $out[(string)$r['status']] = (int)$r['n'];
        }
        return $out;
    }

    private function hydrate(array $row): array
    {
        $row['id']           = (int)$row['id'];
        $row['doc_id']       = (int)$row['doc_id'];
        $row['teacher_id']   = (int)$row['teacher_id'];
        $row['attempts']     = (int)$row['attempts'];
        $row['passes']       = (int)$row['passes'];
        return $row;
    }

    private static function truncate(string $s, int $max = 1024): string
    {
        return strlen($s) > $max ? substr($s, 0, $max - 3) . '...' : $s;
    }
}
