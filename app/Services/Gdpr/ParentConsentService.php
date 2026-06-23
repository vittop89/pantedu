<?php

declare(strict_types=1);

namespace App\Services\Gdpr;

use App\Core\Database;
use PDO;
use RuntimeException;

/**
 * Phase 25.C7 — Consenso parentale Art. 8 GDPR per minori.
 *
 * Età soglia: **14 anni** (D.Lgs. 101/2018, Italia — più stringente del default
 * GDPR di 16 anni).
 *
 * Workflow doppio opt-in:
 *   1. RegistrationService.create rileva birth_date → età < 14:
 *        - User salvato come `active=0`, `status='pending_parent_consent'`
 *        - parent_email obbligatoria nel form signup
 *        - ParentConsentService::request() genera token 64-hex (TTL 30g)
 *        - Email automatica al genitore con link `/parent-consent/{token}`
 *
 *   2. Genitore clicca link → ParentConsentService::confirm():
 *        - Verifica token + expires_at
 *        - status='confirmed', confirmed_at=NOW()
 *        - User attivato (active=1, status='active')
 *
 *   3. Token scaduto (>30g senza click): cron job marca expired + cancella
 *      account studente pending.
 *
 *   4. Revoca genitore: parent_consents.status='revoked' → cascade DELETE
 *      account studente (rispetto Art. 17 minore + Art. 8 §3).
 *
 * NB: età 14-17 anni: consenso autonomo studente (D.Lgs. 101/2018) — non
 * serve parent consent. Solo età < 14 attiva il flow C7.
 */
final class ParentConsentService
{
    public const ITALY_MINOR_THRESHOLD_AGE = 14;
    public const TOKEN_EXPIRY_DAYS = 30;

    /**
     * Calcola età anagrafica da birth_date stringa "YYYY-MM-DD".
     */
    public static function ageFromBirthDate(string $birthDate): int
    {
        try {
            $dob = new \DateTimeImmutable($birthDate);
            $now = new \DateTimeImmutable('today');
            return $now->diff($dob)->y;
        } catch (\Throwable) {
            throw new RuntimeException('invalid_birth_date');
        }
    }

    /**
     * True se l'utente con questa data di nascita è minore (< 14 anni Italia).
     * Usato dal RegistrationService per gating signup.
     */
    public static function requiresParentConsent(string $birthDate): bool
    {
        return self::ageFromBirthDate($birthDate) < self::ITALY_MINOR_THRESHOLD_AGE;
    }

    /**
     * Crea richiesta parent consent. Genera token + INSERT row PENDING.
     * Caller (RegistrationService) responsabile mailer.
     *
     * @return string token 64 hex char
     */
    public function request(int $studentUserId, string $parentEmail, ?string $parentName = null): string
    {
        if (!filter_var($parentEmail, FILTER_VALIDATE_EMAIL)) {
            throw new RuntimeException('invalid_parent_email');
        }

        $token = bin2hex(random_bytes(32));
        $expires = date('Y-m-d H:i:s', time() + self::TOKEN_EXPIRY_DAYS * 86400);

        $stmt = Database::connection()->prepare(
            'INSERT INTO parent_consents
                (student_user_id, parent_email, parent_name, confirm_token,
                 status, requested_at, expires_at)
             VALUES (?, ?, ?, ?, "pending", NOW(), ?)'
        );
        $stmt->execute([
            $studentUserId, strtolower(trim($parentEmail)),
            $parentName ?: null, $token, $expires,
        ]);
        return $token;
    }

    /**
     * Conferma consenso via token. Attiva l'account studente.
     *
     * @return array{ok: bool, student_user_id?: int, error?: string}
     */
    public function confirm(string $token, ?string $ip = null, ?string $ua = null): array
    {
        $db = Database::connection();
        $stmt = $db->prepare(
            'SELECT id, student_user_id, expires_at FROM parent_consents
             WHERE confirm_token = ? AND status = "pending"'
        );
        $stmt->execute([$token]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return ['ok' => false, 'error' => 'token_invalid_or_used'];
        }

        // Verifica TTL
        if ($row['expires_at'] && strtotime($row['expires_at']) < time()) {
            $db->prepare('UPDATE parent_consents SET status="expired" WHERE id=?')
               ->execute([$row['id']]);
            return ['ok' => false, 'error' => 'token_expired'];
        }

        $db->beginTransaction();
        try {
            // 1. Confirm parent_consents
            $upd = $db->prepare(
                'UPDATE parent_consents
                 SET status="confirmed", confirmed_at=NOW(),
                     confirm_ip_hash=?, confirm_ua_hash=?
                 WHERE id=?'
            );
            $upd->execute([
                $ip ? hash('sha256', $ip, true) : null,
                $ua ? hash('sha256', $ua, true) : null,
                $row['id'],
            ]);

            // 2. Activate student account
            $act = $db->prepare(
                'UPDATE users SET active=1, status="active", approved_at=NOW()
                 WHERE id=?'
            );
            $act->execute([(int)$row['student_user_id']]);

            $db->commit();
            return ['ok' => true, 'student_user_id' => (int)$row['student_user_id']];
        } catch (\Throwable $e) {
            $db->rollBack();
            return ['ok' => false, 'error' => 'activation_failed: ' . $e->getMessage()];
        }
    }

    /**
     * Phase 25.C7.fix (GDPR-001) — Rifiuto consenso esplicito dal genitore via
     * link `/parent-consent/{token}` (action=reject), PRIMA della conferma.
     *
     * Diff vs hard-DELETE precedente:
     *   1. Soft-delete user (anonymize + deleted_at), no hard DELETE.
     *   2. Audit log su consent_audit (Art. 30 §1 lett. c+f) con IP/UA hash.
     *   3. status='revoked' (non 'expired' — semantica esatta: parent rifiuta).
     *
     * @return array{ok: bool, student_user_id?: int, error?: string}
     */
    public function reject(string $token, ?string $ip = null, ?string $ua = null): array
    {
        $db = Database::connection();
        $stmt = $db->prepare(
            'SELECT id, student_user_id, expires_at FROM parent_consents
             WHERE confirm_token = ? AND status = "pending"'
        );
        $stmt->execute([$token]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return ['ok' => false, 'error' => 'token_invalid_or_used'];
        }

        $ipHash = $ip ? hash('sha256', $ip, true) : null;
        $uaHash = $ua ? hash('sha256', $ua, true) : null;
        $studentId = (int)$row['student_user_id'];

        $db->beginTransaction();
        try {
            $db->prepare(
                'UPDATE parent_consents
                 SET status="revoked", revoked_at=NOW(),
                     confirm_ip_hash=?, confirm_ua_hash=?
                 WHERE id=?'
            )->execute([$ipHash, $uaHash, $row['id']]);

            // Soft-delete: anonymize PII + active=0 + deleted_at=NOW.
            // No hard DELETE: preserva audit trail e referenze FK.
            $db->prepare(
                'UPDATE users
                 SET email = ?, first_name = "", last_name = "",
                     password_hash = "", active = 0, deleted_at = NOW(),
                     status = "rejected_parent_consent"
                 WHERE id = ? AND active = 0'
            )->execute(["anon-{$studentId}@invalid.local", $studentId]);

            // Audit log immutabile (Art. 30 §1 lett. c+f).
            $db->prepare(
                'INSERT INTO consent_audit
                    (consent_id, user_id, consent_type, event, accessed_at, ip_hash)
                 VALUES (NULL, ?, "parent_consent", "revoked", NOW(), ?)'
            )->execute([$studentId, $ipHash]);

            $db->commit();
            return ['ok' => true, 'student_user_id' => $studentId];
        } catch (\Throwable $e) {
            $db->rollBack();
            return ['ok' => false, 'error' => 'reject_failed: ' . $e->getMessage()];
        }
    }

    /**
     * Revoca consenso parentale (Art. 8 §3 — diritto revoca).
     * Cascade delete account studente (privacy by default per minori).
     */
    public function revoke(int $studentUserId, string $parentEmailVerification): bool
    {
        // Verifica che l'email del genitore corrisponda a quella registrata
        // (mitigazione: solo il genitore che ha confermato può revocare).
        $stmt = Database::connection()->prepare(
            'SELECT id, parent_email FROM parent_consents
             WHERE student_user_id = ? AND status = "confirmed"
             ORDER BY id DESC LIMIT 1'
        );
        $stmt->execute([$studentUserId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return false;
        }

        if (strtolower(trim($parentEmailVerification)) !== strtolower($row['parent_email'])) {
            return false;
        }

        $db = Database::connection();
        $db->beginTransaction();
        try {
            // 1. Mark revoked
            $db->prepare(
                'UPDATE parent_consents SET status="revoked", revoked_at=NOW() WHERE id=?'
            )->execute([$row['id']]);

            // 2. Cascade delete account studente (Art. 8 §3 + Art. 17 minore)
            // Soft-delete pattern: anonymize + active=0 + deleted_at=NOW
            $db->prepare(
                'UPDATE users
                 SET email = ?, first_name = "", last_name = "",
                     password_hash = "", active = 0, deleted_at = NOW()
                 WHERE id = ?'
            )->execute(["anon-{$studentUserId}@invalid.local", $studentUserId]);

            $db->commit();
            return true;
        } catch (\Throwable) {
            $db->rollBack();
            return false;
        }
    }

    /**
     * Lista parent_consent attivi per uno studente (debug / admin view).
     */
    public function findByStudent(int $studentUserId): ?array
    {
        $stmt = Database::connection()->prepare(
            'SELECT id, parent_email, parent_name, status, requested_at,
                    confirmed_at, expires_at, revoked_at
             FROM parent_consents
             WHERE student_user_id = ?
             ORDER BY id DESC LIMIT 1'
        );
        $stmt->execute([$studentUserId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    /**
     * Cron cleanup: marca expired e cancella account studente con
     * parent_consent scaduto > 30g (Art. 5 §1 e — minimizzazione).
     *
     * @return array{expired:int, deleted:int}
     */
    public function cleanupExpired(): array
    {
        $db = Database::connection();

        // 1. Mark expired
        $exp = $db->prepare(
            'UPDATE parent_consents SET status="expired"
             WHERE status="pending" AND expires_at < NOW()'
        );
        $exp->execute();
        $expiredCount = $exp->rowCount();

        // 2. Hard-delete student accounts associated with expired consent
        // (mai attivati → safe da cancellare completamente)
        $del = $db->prepare(
            'DELETE u FROM users u
             JOIN parent_consents pc ON pc.student_user_id = u.id
             WHERE pc.status = "expired" AND u.active = 0'
        );
        $del->execute();
        $deletedCount = $del->rowCount();

        return ['expired' => $expiredCount, 'deleted' => $deletedCount];
    }
}
