<?php

namespace App\Core;

use PDO;
use SessionHandlerInterface;
use SessionUpdateTimestampHandlerInterface;

/**
 * Phase 17 — DB-backed session handler.
 *
 * Sostituisce il default filesystem handler:
 *   - abilita scaling orizzontale (più istanze web server condividono sessioni)
 *   - niente corruzione da crash filesystem hosting legacy
 *   - GC via DELETE WHERE last_access < NOW()-lifetime (idempotente)
 *
 * Tabella `sessions`:
 *   id VARCHAR(128) PK  — session_id PHP
 *   data LONGBLOB       — serialized session data
 *   last_access INT     — unix ts, indicizzato per GC
 *   ip VARCHAR(45)      — audit, optional
 *   ua VARCHAR(255)     — audit, optional
 *
 * Implementa sia SessionHandlerInterface (PHP base) sia
 * SessionUpdateTimestampHandlerInterface (lazy_write: evita UPDATE se i dati
 * non sono cambiati, solo bump last_access). Riduce write load.
 */
final class DbSessionHandler implements SessionHandlerInterface, SessionUpdateTimestampHandlerInterface
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly int $lifetime = 1800,
    ) {
    }

    public function open(string $path, string $name): bool
    {
        return true;
    }
    public function close(): bool
    {
        return true;
    }

    public function read(string $id): string
    {
        $st = $this->pdo->prepare('SELECT data FROM sessions WHERE id = ? LIMIT 1');
        $st->execute([$id]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        return $row ? (string)$row['data'] : '';
    }

    public function write(string $id, string $data): bool
    {
        $sql = 'INSERT INTO sessions (id, data, last_access, ip, ua)
                VALUES (?, ?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE data = VALUES(data),
                                        last_access = VALUES(last_access)';
        $st = $this->pdo->prepare($sql);
        $ip = $_SERVER['REMOTE_ADDR'] ?? null;
        $ua = substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255);
        $st->execute([$id, $data, time(), $ip, $ua]);
        return true;
    }

    public function destroy(string $id): bool
    {
        $st = $this->pdo->prepare('DELETE FROM sessions WHERE id = ?');
        $st->execute([$id]);
        return true;
    }

    /** GC chiamato da PHP con session.gc_probability. Ritorna #righe cancellate. */
    public function gc(int $max_lifetime): int|false
    {
        $cutoff = time() - max($max_lifetime, $this->lifetime);
        $st = $this->pdo->prepare('DELETE FROM sessions WHERE last_access < ?');
        $st->execute([$cutoff]);
        return $st->rowCount();
    }

    /** lazy_write: se la sessione esiste e non è expired, valida per PHP. */
    public function validateId(string $id): bool
    {
        $st = $this->pdo->prepare(
            'SELECT 1 FROM sessions WHERE id = ? AND last_access >= ? LIMIT 1'
        );
        $st->execute([$id, time() - $this->lifetime]);
        return (bool)$st->fetchColumn();
    }

    /** lazy_write: bump last_access senza UPDATE data. */
    public function updateTimestamp(string $id, string $data): bool
    {
        $st = $this->pdo->prepare('UPDATE sessions SET last_access = ? WHERE id = ?');
        $st->execute([time(), $id]);
        return true;
    }
}
