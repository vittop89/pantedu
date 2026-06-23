<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database;
use PDO;

/**
 * Phase PDF-Import — CRUD per `pdf_import_sessions`.
 *
 * FSM (mirror di VerificaCompileJobRepository, unità = sessione):
 *   uploaded → rasterized → extracting → extracted → reviewing
 *            → inserting → inserted | failed | retry
 *
 * Ownership sempre scoped per teacher_id. La chiave API non è mai persistita.
 *
 * @phpstan-type Session array{
 *   id:int, teacher_id:int, institute_id:int, status:string,
 *   payload_sha256:string, original_filename:string,
 *   page_count:int, pages_done:int, provider:string, model:string,
 *   tokens_in:int, tokens_out:int, storage_prefix:string,
 *   indirizzo_id:?int, classe_id:?int, subject_id:?int, section_id:?int,
 *   attempts:int, next_attempt_at:?string, last_error:?string,
 *   created_at:string, updated_at:string, completed_at:?string,
 * }
 */
class PdfImportSessionRepository
{
    public const STATUS_UPLOADED   = 'uploaded';
    public const STATUS_RASTERIZED = 'rasterized';
    public const STATUS_EXTRACTING = 'extracting';
    public const STATUS_EXTRACTED  = 'extracted';
    public const STATUS_REVIEWING  = 'reviewing';
    public const STATUS_INSERTING  = 'inserting';
    public const STATUS_INSERTED   = 'inserted';
    public const STATUS_FAILED     = 'failed';
    public const STATUS_RETRY      = 'retry';
    public const STATUS_CANCELLED  = 'cancelled';

    public const MAX_ATTEMPTS = 4;

    /**
     * Crea una sessione (o ritrova quella attiva con stesso PDF — dedup).
     * Ritorna l'id sessione.
     *
     * @param array{teacher_id:int, institute_id:int, payload_sha256:string,
     *   original_filename?:string, provider?:string, model?:string,
     *   storage_prefix?:string} $data
     */
    public function create(array $data): int
    {
        $existing = $this->findActiveByHash(
            (int)$data['teacher_id'],
            (string)$data['payload_sha256']
        );
        if ($existing !== null) {
            return (int)$existing['id'];
        }

        $stmt = Database::connection()->prepare(
            'INSERT INTO pdf_import_sessions
             (teacher_id, institute_id, status, payload_sha256, original_filename,
              provider, model, storage_prefix)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            (int)$data['teacher_id'],
            (int)$data['institute_id'],
            self::STATUS_UPLOADED,
            (string)$data['payload_sha256'],
            (string)($data['original_filename'] ?? 'document.pdf'),
            (string)($data['provider'] ?? 'anthropic'),
            (string)($data['model'] ?? ''),
            (string)($data['storage_prefix'] ?? ''),
        ]);
        return (int)Database::connection()->lastInsertId();
    }

    /**
     * Esegue $fn tenendo un LOCK per-sessione (MySQL GET_LOCK, per-connessione →
     * serializza tra worker e richieste HTTP). Serve a prevenire il LOST UPDATE su
     * contracts.json (read-modify-write concorrente: docente che edita mentre il
     * worker arricchisce, o più passate). Best-effort: se il lock non si ottiene
     * entro il timeout, procede comunque (≡ comportamento odierno, mai peggio).
     */
    public function withLock(int $sessionId, callable $fn, int $timeoutSec = 30): mixed
    {
        $pdo = Database::connection();
        $name = 'pdfimport_contracts_' . $sessionId;
        $g = $pdo->prepare('SELECT GET_LOCK(?, ?)');
        $g->execute([$name, $timeoutSec]);
        $got = (int)$g->fetchColumn() === 1;
        try {
            return $fn();
        } finally {
            if ($got) {
                $r = $pdo->prepare('SELECT RELEASE_LOCK(?)');
                $r->execute([$name]);
            }
        }
    }

    /**
     * Dedup: SOLO una sessione ancora IN CORSO e RECENTE con stesso (teacher,
     * sha). Serve a evitare doppioni da doppio-submit dello stesso upload.
     * Le sessioni già completate (extracted/reviewing/inserted) o vecchie NON
     * vengono riusate: ri-caricare lo stesso PDF avvia una sessione pulita
     * (il riuso di sessioni completate causava "0 righe" dopo la pulizia storage).
     */
    public function findActiveByHash(int $teacherId, string $sha): ?array
    {
        $stmt = Database::connection()->prepare(
            'SELECT * FROM pdf_import_sessions
             WHERE teacher_id = ? AND payload_sha256 = ?
               AND status IN (?, ?, ?, ?)
               AND created_at > (NOW() - INTERVAL 30 MINUTE)
             ORDER BY id DESC LIMIT 1'
        );
        $stmt->execute([
            $teacherId, $sha,
            self::STATUS_UPLOADED, self::STATUS_RASTERIZED,
            self::STATUS_EXTRACTING, self::STATUS_RETRY,
        ]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ? $this->hydrate($row) : null;
    }

    public function find(int $id): ?array
    {
        $stmt = Database::connection()->prepare(
            'SELECT * FROM pdf_import_sessions WHERE id = ? LIMIT 1'
        );
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ? $this->hydrate($row) : null;
    }

    /** Find con owner-check: ritorna null se la sessione non è del docente. */
    public function findForTeacher(int $id, int $teacherId): ?array
    {
        $s = $this->find($id);
        if ($s === null || (int)$s['teacher_id'] !== $teacherId) {
            return null;
        }
        return $s;
    }

    /** Aggiorna lo stato (+ completed_at sui terminali). */
    public function setStatus(int $id, string $status, ?string $error = null): void
    {
        $terminal = in_array($status, [self::STATUS_INSERTED, self::STATUS_FAILED], true);
        $stmt = Database::connection()->prepare(
            'UPDATE pdf_import_sessions
             SET status = ?, last_error = COALESCE(?, last_error),
                 completed_at = ' . ($terminal ? 'NOW()' : 'completed_at') . '
             WHERE id = ?'
        );
        $stmt->execute([$status, $error !== null ? self::truncate($error) : null, $id]);
    }

    /** Richiesta di STOP: marca 'cancelled' se la sessione è ancora in lavorazione. */
    public function cancel(int $id): bool
    {
        $stmt = Database::connection()->prepare(
            "UPDATE pdf_import_sessions
             SET status = '" . self::STATUS_CANCELLED . "', last_error = 'Interrotto dall''utente',
                 completed_at = NOW()
             WHERE id = ? AND status IN ('uploaded','rasterized','extracting','retry')"
        );
        $stmt->execute([$id]);
        return $stmt->rowCount() > 0;
    }

    public function setRasterized(int $id, int $pageCount, string $storagePrefix): void
    {
        $stmt = Database::connection()->prepare(
            'UPDATE pdf_import_sessions
             SET status = ?, page_count = ?, storage_prefix = ?
             WHERE id = ?'
        );
        $stmt->execute([self::STATUS_RASTERIZED, $pageCount, $storagePrefix, $id]);
    }

    /** Avanza il contatore pagine estratte + accumula token usati. */
    public function recordPageDone(int $id, int $tokensIn, int $tokensOut): void
    {
        $stmt = Database::connection()->prepare(
            'UPDATE pdf_import_sessions
             SET pages_done = pages_done + 1,
                 tokens_in  = tokens_in + ?,
                 tokens_out = tokens_out + ?
             WHERE id = ?'
        );
        $stmt->execute([max(0, $tokensIn), max(0, $tokensOut), $id]);
    }

    /** Accumula token senza toccare pages_done (uso: soluzioni/figure AI). */
    public function addTokens(int $id, int $tokensIn, int $tokensOut): void
    {
        $stmt = Database::connection()->prepare(
            'UPDATE pdf_import_sessions
             SET tokens_in = tokens_in + ?, tokens_out = tokens_out + ?
             WHERE id = ?'
        );
        $stmt->execute([max(0, $tokensIn), max(0, $tokensOut), $id]);
    }

    public function setTargetContext(int $id, array $ctx): void
    {
        $stmt = Database::connection()->prepare(
            'UPDATE pdf_import_sessions
             SET indirizzo_id = ?, classe_id = ?, subject_id = ?, section_id = ?
             WHERE id = ?'
        );
        $stmt->execute([
            $ctx['indirizzo_id'] ?? null,
            $ctx['classe_id'] ?? null,
            $ctx['subject_id'] ?? null,
            $ctx['section_id'] ?? null,
            $id,
        ]);
    }

    /**
     * Pesca FIFO la prossima sessione processabile (rasterized o extracting
     * stagnante o retry fuori-backoff) e la marca extracting (lock atomico).
     */
    public function pickNext(): ?array
    {
        $pdo = Database::connection();
        $sel = $pdo->prepare(
            "SELECT id FROM pdf_import_sessions
             WHERE status = 'rasterized'
                OR (status = 'retry' AND (next_attempt_at IS NULL OR next_attempt_at <= NOW()))
             ORDER BY id ASC LIMIT 1"
        );
        $sel->execute();
        $id = $sel->fetchColumn();
        if (!$id) {
            return null;
        }

        $upd = $pdo->prepare(
            "UPDATE pdf_import_sessions
             SET status = 'extracting', attempts = attempts + 1
             WHERE id = ? AND status IN ('rasterized', 'retry')"
        );
        $upd->execute([$id]);
        if ($upd->rowCount() !== 1) {
            return null;
        }
        return $this->find((int)$id);
    }

    /** Pick di una sessione SPECIFICA (inline trigger-on-request). */
    public function pickSpecific(int $id): ?array
    {
        $upd = Database::connection()->prepare(
            "UPDATE pdf_import_sessions
             SET status = 'extracting', attempts = attempts + 1
             WHERE id = ? AND status IN ('rasterized', 'retry')
               AND (next_attempt_at IS NULL OR next_attempt_at <= NOW())"
        );
        $upd->execute([$id]);
        if ($upd->rowCount() !== 1) {
            return null;
        }
        return $this->find($id);
    }

    /** Reset delle sessioni 'extracting' stagnanti (FPM kill durante inline). */
    public function resetStuckExtracting(int $staleSec = 600): int
    {
        $stmt = Database::connection()->prepare(
            "UPDATE pdf_import_sessions
             SET status = 'retry', next_attempt_at = NOW(),
                 last_error = CONCAT('stuck_extracting_reset_after_', ?, 's')
             WHERE status = 'extracting'
               AND updated_at < DATE_SUB(NOW(), INTERVAL ? SECOND)"
        );
        $stmt->execute([$staleSec, $staleSec]);
        return $stmt->rowCount();
    }

    /** failed (definitivo) o retry (backoff esponenziale). */
    public function markFailedOrRetry(int $id, string $error): string
    {
        $s = $this->find($id);
        if (!$s) {
            return 'missing';
        }

        if ((int)$s['attempts'] >= self::MAX_ATTEMPTS) {
            $stmt = Database::connection()->prepare(
                "UPDATE pdf_import_sessions
                 SET status = 'failed', completed_at = NOW(), last_error = ?
                 WHERE id = ?"
            );
            $stmt->execute([self::truncate($error), $id]);
            return self::STATUS_FAILED;
        }
        // Backoff BREVE: l'estrazione è guidata dal poll interattivo (l'utente
        // aspetta). Delay lunghi (min) facevano sembrare il tool bloccato.
        $delaySec = match ((int)$s['attempts']) {
            1 => 3,
            2 => 8,
            default => 20,
        };
        $stmt = Database::connection()->prepare(
            "UPDATE pdf_import_sessions
             SET status = 'retry', next_attempt_at = DATE_ADD(NOW(), INTERVAL ? SECOND),
                 last_error = ?
             WHERE id = ?"
        );
        $stmt->execute([$delaySec, self::truncate($error), $id]);
        return self::STATUS_RETRY;
    }

    /** Somma token (in+out) consumati dal docente nelle ultime 24h (budget). */
    public function tokensUsedToday(int $teacherId): int
    {
        $stmt = Database::connection()->prepare(
            'SELECT COALESCE(SUM(tokens_in + tokens_out), 0)
             FROM pdf_import_sessions
             WHERE teacher_id = ? AND created_at >= DATE_SUB(NOW(), INTERVAL 1 DAY)'
        );
        $stmt->execute([$teacherId]);
        return (int)$stmt->fetchColumn();
    }

    public function listForTeacher(int $teacherId, int $limit = 50): array
    {
        $limit = max(1, min(200, $limit));
        $stmt = Database::connection()->prepare(
            'SELECT * FROM pdf_import_sessions
             WHERE teacher_id = ?
             ORDER BY id DESC LIMIT ' . $limit
        );
        $stmt->execute([$teacherId]);
        return array_map([$this, 'hydrate'], $stmt->fetchAll(PDO::FETCH_ASSOC) ?: []);
    }

    /**
     * Sessioni eleggibili al purge (qualsiasi stato) più vecchie di N giorni —
     * usato per cancellare PRIMA i file storage, poi le righe. Include le
     * sessioni abbandonate (reviewing/extracted mai inserite).
     *
     * @return list<array{id:int, teacher_id:int, storage_prefix:string, page_count:int}>
     */
    public function listPurgeable(int $days): array
    {
        $stmt = Database::connection()->prepare(
            'SELECT id, teacher_id, storage_prefix, page_count
             FROM pdf_import_sessions
             WHERE updated_at < DATE_SUB(NOW(), INTERVAL ? DAY)
             LIMIT 500'
        );
        $stmt->execute([$days]);
        return array_map(static fn($r) => [
            'id'             => (int)$r['id'],
            'teacher_id'     => (int)$r['teacher_id'],
            'storage_prefix' => (string)$r['storage_prefix'],
            'page_count'     => (int)$r['page_count'],
        ], $stmt->fetchAll(PDO::FETCH_ASSOC) ?: []);
    }

    /**
     * Cleanup: cancella le RIGHE di sessioni più vecchie di N giorni (qualsiasi
     * stato). I FILE vanno cancellati prima dal service (deleteSession).
     */
    public function purgeOlderThan(int $days = 7): int
    {
        $stmt = Database::connection()->prepare(
            'DELETE FROM pdf_import_sessions
             WHERE updated_at < DATE_SUB(NOW(), INTERVAL ? DAY)'
        );
        $stmt->execute([$days]);
        return $stmt->rowCount();
    }

    private function hydrate(array $row): array
    {
        foreach (
            ['id','teacher_id','institute_id','page_count','pages_done',
                  'tokens_in','tokens_out','attempts'] as $k
        ) {
            $row[$k] = (int)$row[$k];
        }
        foreach (['indirizzo_id','classe_id','subject_id','section_id'] as $k) {
            $row[$k] = isset($row[$k]) && $row[$k] !== null ? (int)$row[$k] : null;
        }
        return $row;
    }

    private static function truncate(string $s, int $max = 1024): string
    {
        return strlen($s) > $max ? substr($s, 0, $max - 3) . '...' : $s;
    }
}
