<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database;
use PDO;

/**
 * Phase G2 — CRUD per map_shares: regole esplicite di condivisione mappe.
 *
 * Ogni row = 1 grant per (content_id, scope_type, scope_value), opzionalmente
 * con permission ∈ {view, copy}. Default = nessun grant → no cross-teacher
 * access (diritto autore).
 *
 * Scope types:
 *   - institute: scope_value = institute_id
 *   - class:     scope_value = "{institute_id}|{indirizzo}|{classe}"
 *   - student:   scope_value = users.id
 *   - teacher:   scope_value = users.id
 *
 * Revoca = DELETE row (atomico, idempotent). No soft-delete.
 *
 * @phpstan-type Share array{
 *   id: int,
 *   content_id: int,
 *   scope_type: string,
 *   scope_value: string,
 *   permission: string,
 *   granted_by: int,
 *   granted_at: string,
 *   reason: ?string
 * }
 */
final class MapShareRepository
{
    public const SCOPE_TYPES = ['institute', 'class', 'student', 'teacher'];
    public const PERMISSIONS = ['view', 'copy'];

    public function grant(
        int $contentId,
        string $scopeType,
        string $scopeValue,
        string $permission,
        int $grantedBy,
        ?string $reason = null
    ): void {
        $this->validate($scopeType, $permission);

        $sql = 'INSERT INTO map_shares
                (content_id, scope_type, scope_value, permission, granted_by, reason)
                VALUES (?, ?, ?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE
                  permission = VALUES(permission),
                  granted_by = VALUES(granted_by),
                  granted_at = CURRENT_TIMESTAMP,
                  reason     = VALUES(reason)';
        $stmt = Database::connection()->prepare($sql);
        $stmt->execute([$contentId, $scopeType, $scopeValue, $permission, $grantedBy, $reason]);
    }

    public function revoke(int $contentId, string $scopeType, string $scopeValue): void
    {
        $stmt = Database::connection()->prepare(
            'DELETE FROM map_shares WHERE content_id = ? AND scope_type = ? AND scope_value = ?'
        );
        $stmt->execute([$contentId, $scopeType, $scopeValue]);
    }

    /** Revoca tutti i share di una mappa. Usato in DELETE map flow. */
    public function revokeAll(int $contentId): void
    {
        $stmt = Database::connection()->prepare(
            'DELETE FROM map_shares WHERE content_id = ?'
        );
        $stmt->execute([$contentId]);
    }

    /**
     * Cerca permission migliore (view < copy) per (content_id, scope tuples).
     * I scope tuples sono i contesti dell'accessor:
     *   [['institute', '12'], ['class', '12|ar|ar1s'], ['teacher', '77']]
     *
     * Restituisce 'copy' se almeno un grant copy match, 'view' se solo view,
     * null se nessun grant applicabile.
     *
     * @param list<array{0:string,1:string}> $scopeTuples
     */
    public function bestPermissionFor(int $contentId, array $scopeTuples): ?string
    {
        if ($scopeTuples === []) {
            return null;
        }
        // Build (scope_type=? AND scope_value=?) OR ... senza placeholder
        // bouncing su PDO bind multi-tipo: usiamo IN (composto via VALUES).
        // Approccio robusto: query 1 row per tuple, prendi la max permission.
        $best = null;
        $stmt = Database::connection()->prepare(
            'SELECT permission FROM map_shares
             WHERE content_id = ? AND scope_type = ? AND scope_value = ?
             LIMIT 1'
        );
        foreach ($scopeTuples as [$type, $value]) {
            $stmt->execute([$contentId, $type, (string)$value]);
            $row = $stmt->fetchColumn();
            if ($row === false || $row === null) {
                continue;
            }
            if ($row === 'copy') {
                return 'copy'; // max possibile, short-circuit
            }
            if ($best === null) {
                $best = (string)$row;
            }
        }
        return $best;
    }

    /**
     * Lista tutti i grant per una mappa. Per UI "Condividi…" del owner.
     *
     * @return list<Share>
     */
    public function listForContent(int $contentId): array
    {
        $stmt = Database::connection()->prepare(
            'SELECT id, content_id, scope_type, scope_value, permission,
                    granted_by, granted_at, reason
             FROM map_shares WHERE content_id = ?
             ORDER BY granted_at DESC'
        );
        $stmt->execute([$contentId]);
        $rows = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $rows[] = [
                'id'          => (int)$r['id'],
                'content_id'  => (int)$r['content_id'],
                'scope_type'  => (string)$r['scope_type'],
                'scope_value' => (string)$r['scope_value'],
                'permission'  => (string)$r['permission'],
                'granted_by'  => (int)$r['granted_by'],
                'granted_at'  => (string)$r['granted_at'],
                'reason'      => $r['reason'] !== null ? (string)$r['reason'] : null,
            ];
        }
        return $rows;
    }

    private function validate(string $scopeType, string $permission): void
    {
        if (!\in_array($scopeType, self::SCOPE_TYPES, true)) {
            throw new \InvalidArgumentException("invalid scope_type: $scopeType");
        }
        if (!\in_array($permission, self::PERMISSIONS, true)) {
            throw new \InvalidArgumentException("invalid permission: $permission");
        }
    }
}
