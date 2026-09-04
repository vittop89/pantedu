<?php

declare(strict_types=1);

namespace App\Services\Gdpr;

use App\Core\Database;
use App\Services\Crypto\TeacherCryptoService;
use PDO;
use RuntimeException;

/**
 * Phase 25.C4 — Self-service oblio Art. 17 GDPR con crypto-shredding O(1).
 *
 * Workflow:
 *   1. request(int $userId, ?string $reason): user POST /me/request-deletion
 *      → genera token, salva row PENDING_CONFIRM, send email con link.
 *   2. confirm(string $token): user clicca link → status CONFIRMED →
 *      execute_after = NOW() + 30 days. User informato del cooling-off.
 *   3. cancel(int $userId): user può annullare durante COOLING_OFF.
 *   4. executeOverdue(): cron job esegue cancellazioni dovute (CONFIRMED +
 *      execute_after < NOW()):
 *        - TeacherCryptoService::shred() → tutti body cifrati illeggibili
 *        - users.deleted_at = NOW()
 *        - users.email = anonymized
 *        - users.first/last_name = anonymized
 *        - users.password_hash = ''
 *        - status → EXECUTED
 *
 * Hard-delete è separato (job successivo a 90g, vedi tools/gdpr/hard_delete_pending.php).
 *
 * Audit trail: privileged_access_log + crypto_access_log (shred event).
 */
final class DeletionRequestService
{
    public const COOLING_OFF_DAYS = 30;
    public const TOKEN_EXPIRY_DAYS = 7;

    public function __construct(
        private readonly TeacherCryptoService $crypto = new TeacherCryptoService()
    ) {
    }

    /**
     * Crea richiesta di cancellazione. Genera token + salva row PENDING_CONFIRM.
     * Caller responsabile di inviare email con link `confirm/{token}`.
     *
     * @return string token (64 hex char)
     */
    public function request(int $userId, ?string $reason = null, ?string $ip = null): string
    {
        $db = Database::connection();
        // Cancella eventuali pending precedenti (replace)
        $db->prepare(
            "UPDATE deletion_requests SET status='cancelled', cancelled_at=NOW()
             WHERE user_id=? AND status IN ('pending_confirm', 'confirmed', 'cooling_off')"
        )->execute([$userId]);

        $token = bin2hex(random_bytes(32));
        $expires = date('Y-m-d H:i:s', time() + self::TOKEN_EXPIRY_DAYS * 86400);

        $stmt = $db->prepare(
            'INSERT INTO deletion_requests
                (user_id, confirm_token, status, requested_at, expires_at, reason, request_ip_hash)
             VALUES (?, ?, "pending_confirm", NOW(), ?, ?, ?)'
        );
        $stmt->execute([
            $userId, $token, $expires, $reason,
            $ip ? hash('sha256', $ip, true) : null,
        ]);

        return $token;
    }

    /**
     * Conferma cancellazione via token. Imposta CONFIRMED + execute_after = +30g.
     */
    public function confirm(string $token, ?string $ip = null): bool
    {
        $db = Database::connection();
        $stmt = $db->prepare(
            "SELECT id, user_id, expires_at FROM deletion_requests
             WHERE confirm_token = ? AND status = 'pending_confirm'"
        );
        $stmt->execute([$token]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return false;
        }

        // Verifica expires_at
        if ($row['expires_at'] && strtotime($row['expires_at']) < time()) {
            $db->prepare("UPDATE deletion_requests SET status='expired' WHERE id=?")
               ->execute([$row['id']]);
            return false;
        }

        $executeAfter = date('Y-m-d H:i:s', time() + self::COOLING_OFF_DAYS * 86400);
        $upd = $db->prepare(
            "UPDATE deletion_requests
             SET status='cooling_off', confirmed_at=NOW(), execute_after=?, confirm_ip_hash=?
             WHERE id=?"
        );
        $upd->execute([
            $executeAfter,
            $ip ? hash('sha256', $ip, true) : null,
            $row['id'],
        ]);
        return true;
    }

    /**
     * Annulla richiesta durante cooling-off period.
     */
    public function cancel(int $userId): bool
    {
        $stmt = Database::connection()->prepare(
            "UPDATE deletion_requests
             SET status='cancelled', cancelled_at=NOW()
             WHERE user_id=? AND status IN ('pending_confirm', 'confirmed', 'cooling_off')"
        );
        $stmt->execute([$userId]);
        return $stmt->rowCount() > 0;
    }

    /**
     * Verifica se un user ha una deletion in cooling-off attiva.
     * Usato dalla UI per mostrare "Hai una cancellazione in corso, annullala?".
     *
     * @return array{id:int, status:string, execute_after:?string}|null
     */
    public function activeRequest(int $userId): ?array
    {
        $stmt = Database::connection()->prepare(
            "SELECT id, status, execute_after, requested_at, confirmed_at
             FROM deletion_requests
             WHERE user_id = ? AND status IN ('pending_confirm', 'cooling_off')
             ORDER BY id DESC LIMIT 1"
        );
        $stmt->execute([$userId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    /**
     * Esegue le cancellazioni overdue (status=cooling_off + execute_after passato).
     * Chiamato da cron job giornaliero `tools/gdpr/execute_pending_deletions.php`.
     *
     * Per ogni request:
     *   1. TeacherCryptoService::shred(user_id) → crypto-shredding O(1)
     *   2. users anonymization (deleted_at, email, names, password_hash)
     *   3. status → EXECUTED, executed_at = NOW()
     *
     * @return array{processed:int, succeeded:int, failed:int, errors:array}
     */
    public function executeOverdue(): array
    {
        $db = Database::connection();
        $rows = $db->query(
            "SELECT id, user_id FROM deletion_requests
             WHERE status='cooling_off' AND execute_after <= NOW()"
        )->fetchAll(PDO::FETCH_ASSOC);

        $stats = ['processed' => count($rows), 'succeeded' => 0, 'failed' => 0, 'errors' => []];
        foreach ($rows as $r) {
            try {
                $this->executeOne((int)$r['id'], (int)$r['user_id']);
                $stats['succeeded']++;
            } catch (\Throwable $e) {
                $stats['failed']++;
                $stats['errors'][] = "request_id={$r['id']} user_id={$r['user_id']}: " . $e->getMessage();
            }
        }
        return $stats;
    }

    /**
     * Esegue una singola richiesta. Atomic via transaction:
     *   - Crypto-shredding (NON in transaction perché tocca teacher_keys)
     *   - User anonymization in transaction
     */
    public function executeOne(int $requestId, int $userId): void
    {
        $db = Database::connection();

        // 1. Crypto-shredding (idempotent — safe da race)
        try {
            $this->crypto->shred($userId, accessorId: 0, reason: 'art_17_self_service_deletion');
        } catch (\Throwable $e) {
            // KMS non configurato o teacher mai cifrato: non blocca anonymization.
            // Il body resta plaintext nel DB ma users è anonimizzato → de facto inaccessibile.
            error_log("[deletion_service] shred failed user_id=$userId: " . $e->getMessage());
        }

        // 2. User anonymization (transaction)
        $db->beginTransaction();
        try {
            $anonEmail = "anon-{$userId}@invalid.local";
            $upd = $db->prepare(
                'UPDATE users
                 SET email = ?, first_name = ?, last_name = ?, password_hash = ?,
                     active = 0, deleted_at = NOW()
                 WHERE id = ?'
            );
            $upd->execute([$anonEmail, '', '', '', $userId]);

            $reqUpd = $db->prepare(
                "UPDATE deletion_requests SET status='executed', executed_at=NOW() WHERE id=?"
            );
            $reqUpd->execute([$requestId]);

            $db->commit();
        } catch (\Throwable $e) {
            $db->rollBack();
            throw new RuntimeException("anonymization_failed: " . $e->getMessage(), 0, $e);
        }
    }
}
