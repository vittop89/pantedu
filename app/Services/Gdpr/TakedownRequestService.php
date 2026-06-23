<?php

declare(strict_types=1);

namespace App\Services\Gdpr;

use App\Core\Database;
use InvalidArgumentException;
use PDO;

/**
 * Phase 25.P — Service per gestione Notice & Takedown requests.
 *
 * Implementa la procedura di safe harbor giuridico ex D.Lgs. 70/2003 art. 16
 * (Direttiva 2000/31/CE) per l'operatore tecnico dell'Applicativo.
 *
 * Vedi:
 *   - database/migrations/057_takedown_requests.sql
 *   - docs/legal/takedown_procedure.md
 *   - docs/todo/multitenancy_responsibility_framework.md §3.3
 *
 * Flusso:
 *   1. submit()       → segnalazione pubblica via form
 *   2. listPending()  → admin vede coda
 *   3. updateStatus() → admin valuta + decide
 *   4. notifyUploader() → uploader avvisato
 *
 * NOTA: integrazione con email/notification system non inclusa qui (resta
 * task TODO da fare quando Scenario B/C diventa concreto). Service espone
 * solo CRUD su DB.
 */
class TakedownRequestService
{
    public const VIOLATION_TYPES = ['copyright', 'gdpr_art9', 'illegal', 'inappropriate', 'spam', 'other'];
    public const STATUSES = ['new', 'under_review', 'actioned', 'rejected', 'closed'];
    public const ACTIONS = ['pending', 'removed', 'suspended_user', 'dismissed', 'forwarded_authority'];
    public const SUBMITTER_ROLES = ['editor', 'dpo_other', 'authority', 'private', 'parent', 'self', 'anonymous'];

    private ?PDO $pdo;

    public function __construct(?PDO $pdo = null)
    {
        // Connessione LAZY: NON connettere in costruzione. showForm() (GET del
        // form pubblico /segnalazione-contenuti) istanzia il controller ma non
        // usa il DB → con connessione eager andava in 500 se il DB era giù.
        // Il form pubblico deve restare raggiungibile a prescindere; il DB
        // serve solo al submit().
        $this->pdo = $pdo;
    }

    private function pdo(): PDO
    {
        return $this->pdo ??= Database::connection();
    }

    /**
     * Submission di una nuova segnalazione (chiamata da form pubblico).
     *
     * @param array{
     *   submitter_name?: ?string,
     *   submitter_email?: ?string,
     *   submitter_role?: string,
     *   submitter_ip?: ?string,
     *   content_ref: string,
     *   uploader_user_id?: ?int,
     *   violation_type: string,
     *   description: string,
     *   attachments?: ?array
     * } $data
     * @return int ID della segnalazione creata
     * @throws InvalidArgumentException se dati invalidi
     */
    public function submit(array $data): int
    {
        if (empty($data['content_ref']) || strlen($data['content_ref']) > 1024) {
            throw new InvalidArgumentException('content_ref required, max 1024 chars');
        }
        if (empty($data['violation_type']) || ! in_array($data['violation_type'], self::VIOLATION_TYPES, true)) {
            throw new InvalidArgumentException('Invalid violation_type, must be one of: ' . implode(', ', self::VIOLATION_TYPES));
        }
        if (empty($data['description']) || strlen($data['description']) > 65535) {
            throw new InvalidArgumentException('description required (max 64KB)');
        }
        $role = $data['submitter_role'] ?? 'private';
        if (! in_array($role, self::SUBMITTER_ROLES, true)) {
            throw new InvalidArgumentException('Invalid submitter_role');
        }

        $stmt = $this->pdo()->prepare(
            'INSERT INTO takedown_requests '
            . '(submitter_name, submitter_email, submitter_role, submitter_ip, '
            . ' content_ref, uploader_user_id, violation_type, description, attachments) '
            . 'VALUES (:name, :email, :role, :ip, :ref, :uid, :type, :desc, :att)'
        );
        $stmt->execute([
            ':name' => $data['submitter_name'] ?? null,
            ':email' => $data['submitter_email'] ?? null,
            ':role' => $role,
            ':ip' => $data['submitter_ip'] ?? null,
            ':ref' => $data['content_ref'],
            ':uid' => $data['uploader_user_id'] ?? null,
            ':type' => $data['violation_type'],
            ':desc' => $data['description'],
            ':att' => isset($data['attachments']) ? json_encode($data['attachments']) : null,
        ]);
        return (int)$this->pdo()->lastInsertId();
    }

    /**
     * Lista segnalazioni in coda (admin).
     *
     * @param string|null $statusFilter filter optional (es. 'new')
     * @return list<array<string,mixed>>
     */
    public function listPending(?string $statusFilter = null): array
    {
        $sql = 'SELECT * FROM takedown_requests';
        $params = [];
        if ($statusFilter !== null && in_array($statusFilter, self::STATUSES, true)) {
            $sql .= ' WHERE status = :s';
            $params[':s'] = $statusFilter;
        } else {
            $sql .= " WHERE status IN ('new', 'under_review')";
        }
        $sql .= ' ORDER BY submitted_at DESC';

        $stmt = $this->pdo()->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        return is_array($rows) ? $rows : [];
    }

    /**
     * Aggiorna stato + azione di una segnalazione (admin).
     */
    public function updateStatus(
        int $requestId,
        string $newStatus,
        string $action,
        string $notes,
        int $actionedByUserId
    ): void {
        if (! in_array($newStatus, self::STATUSES, true)) {
            throw new InvalidArgumentException('Invalid status');
        }
        if (! in_array($action, self::ACTIONS, true)) {
            throw new InvalidArgumentException('Invalid action');
        }

        $stmt = $this->pdo()->prepare(
            'UPDATE takedown_requests SET '
            . '  status = :s, '
            . '  action_taken = :a, '
            . '  action_notes = :n, '
            . '  actioned_at = NOW(), '
            . '  actioned_by = :uid '
            . 'WHERE id = :id'
        );
        $stmt->execute([
            ':s' => $newStatus,
            ':a' => $action,
            ':n' => $notes,
            ':uid' => $actionedByUserId,
            ':id' => $requestId,
        ]);
    }

    /**
     * Marca segnalazione come "uploader notificato".
     */
    public function markUploaderNotified(int $requestId): void
    {
        $stmt = $this->pdo()->prepare(
            'UPDATE takedown_requests SET notified_uploader = 1, notified_at = NOW() WHERE id = :id'
        );
        $stmt->execute([':id' => $requestId]);
    }

    /**
     * Get dettaglio segnalazione.
     *
     * @return array<string,mixed>|null
     */
    public function get(int $requestId): ?array
    {
        $stmt = $this->pdo()->prepare('SELECT * FROM takedown_requests WHERE id = :id');
        $stmt->execute([':id' => $requestId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row !== false ? $row : null;
    }

    /**
     * Report annuale aggregato (privacy-friendly, no contenuti).
     *
     * @param int $year anno solare
     * @return array{
     *   total: int,
     *   by_type: array<string,int>,
     *   by_status: array<string,int>,
     *   by_action: array<string,int>,
     *   avg_response_hours: ?float
     * }
     */
    public function annualReport(int $year): array
    {
        $stmt = $this->pdo()->prepare(
            "SELECT
                COUNT(*) AS total,
                violation_type,
                status,
                action_taken,
                AVG(TIMESTAMPDIFF(HOUR, submitted_at, actioned_at)) AS avg_response_h
             FROM takedown_requests
             WHERE YEAR(submitted_at) = :y
             GROUP BY violation_type, status, action_taken"
        );
        $stmt->execute([':y' => $year]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $total = 0;
        $byType = $byStatus = $byAction = [];
        $respSum = $respCount = 0;
        foreach ($rows as $r) {
            $n = (int)$r['total'];
            $total += $n;
            $byType[(string)$r['violation_type']] = ($byType[(string)$r['violation_type']] ?? 0) + $n;
            $byStatus[(string)$r['status']] = ($byStatus[(string)$r['status']] ?? 0) + $n;
            $byAction[(string)$r['action_taken']] = ($byAction[(string)$r['action_taken']] ?? 0) + $n;
            if ($r['avg_response_h'] !== null) {
                $respSum += (float)$r['avg_response_h'] * $n;
                $respCount += $n;
            }
        }

        return [
            'total' => $total,
            'by_type' => $byType,
            'by_status' => $byStatus,
            'by_action' => $byAction,
            'avg_response_hours' => $respCount > 0 ? $respSum / $respCount : null,
        ];
    }
}
