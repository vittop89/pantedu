<?php

declare(strict_types=1);

namespace App\Repositories\Gdpr;

use App\Core\Database;
use PDO;

/**
 * Phase 25.R.4.1 — Repository per `data_breach_incidents` (Art. 33-34 GDPR).
 *
 * Append-friendly (no DELETE). Aggiornamenti via updateStatus / setNotified*.
 * Conservazione permanente per accountability Art. 5 §2.
 */
final class DataBreachRepository
{
    public const STATUSES = ['detected', 'assessing', 'notified_garante', 'notified_users', 'closed'];
    public const SEVERITIES = ['low', 'medium', 'high', 'critical'];

    /** @return list<array<string,mixed>> */
    public function listAll(?string $statusFilter = null): array
    {
        $sql = 'SELECT * FROM data_breach_incidents';
        $args = [];
        if ($statusFilter !== null && in_array($statusFilter, self::STATUSES, true)) {
            $sql .= ' WHERE status = ?';
            $args[] = $statusFilter;
        }
        $sql .= ' ORDER BY detected_at DESC, id DESC';
        $stmt = Database::connection()->prepare($sql);
        $stmt->execute($args);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /** @return array<string,mixed>|null */
    public function find(int $id): ?array
    {
        $stmt = Database::connection()->prepare(
            'SELECT * FROM data_breach_incidents WHERE id = ? LIMIT 1'
        );
        $stmt->execute([$id]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    /**
     * Crea nuovo incident. Status iniziale "detected".
     * Throw InvalidArgumentException se severity non valida.
     */
    public function create(array $data, int $reporterId): int
    {
        // Audit 25.R.31 — guard ?? + validazione minima (prima accesso a chiavi
        // potenzialmente assenti → warning/insert sporco).
        if (!in_array($data['severity'] ?? '', self::SEVERITIES, true)) {
            throw new \InvalidArgumentException('severity invalida');
        }
        $occurredAt  = trim((string)($data['occurred_at'] ?? ''));
        $detectedAt  = trim((string)($data['detected_at'] ?? ''));
        $description = trim((string)($data['description'] ?? ''));
        if ($occurredAt === '' || $detectedAt === '') {
            throw new \InvalidArgumentException('date breach obbligatorie');
        }
        if (mb_strlen($description) < 10) {
            throw new \InvalidArgumentException('descrizione troppo breve (min 10)');
        }
        $affected = trim((string)($data['affected_users_count'] ?? ''));
        $stmt = Database::connection()->prepare(
            'INSERT INTO data_breach_incidents
                (occurred_at, detected_at, severity, affected_users_count, data_categories,
                 description, root_cause, remedial_actions, status, reported_by_user_id)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, "detected", ?)'
        );
        $stmt->execute([
            $occurredAt,
            $detectedAt,
            $data['severity'],
            $affected !== '' ? (int)$affected : null,
            ($data['data_categories'] ?? '') ?: null,
            $description,
            ($data['root_cause'] ?? '')       ?: null,
            ($data['remedial_actions'] ?? '') ?: null,
            $reporterId,
        ]);
        return (int)Database::connection()->lastInsertId();
    }

    public function updateStatus(int $id, string $status): bool
    {
        if (!in_array($status, self::STATUSES, true)) {
            throw new \InvalidArgumentException('status invalido');
        }
        $extra = '';
        if ($status === 'closed') {
            $extra = ', closed_at = NOW()';
        }
        $stmt = Database::connection()->prepare(
            "UPDATE data_breach_incidents SET status = ?{$extra} WHERE id = ?"
        );
        return $stmt->execute([$status, $id]);
    }

    public function setGaranteNotified(int $id, ?string $ref): bool
    {
        $stmt = Database::connection()->prepare(
            'UPDATE data_breach_incidents
             SET notified_garante_at = NOW(), garante_ref = ?, status = "notified_garante"
             WHERE id = ?'
        );
        return $stmt->execute([$ref, $id]);
    }

    public function setUsersNotified(int $id, string $method): bool
    {
        $stmt = Database::connection()->prepare(
            'UPDATE data_breach_incidents
             SET notified_users_at = NOW(), users_notification_method = ?, status = "notified_users"
             WHERE id = ?'
        );
        return $stmt->execute([$method, $id]);
    }

    /** @return array<string,int> count by status */
    public function statusCounts(): array
    {
        $rows = Database::connection()->query(
            'SELECT status, COUNT(*) c FROM data_breach_incidents GROUP BY status'
        )->fetchAll(PDO::FETCH_ASSOC);
        $out = array_fill_keys(self::STATUSES, 0);
        foreach ($rows as $r) {
            $out[(string)$r['status']] = (int)$r['c'];
        }
        return $out;
    }
}
