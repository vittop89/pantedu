<?php

declare(strict_types=1);

namespace App\Services\Gdpr;

use App\Core\Database;
use App\Services\Audit\ActivityLogger;
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

        $this->audit($studentUserId, 'requested', null);
        ActivityLogger::event(
            'parent_consent_requested',
            subjectType: 'user',
            subjectId:   (string)$studentUserId,
            details:     ['expires_at' => $expires],
        );
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
            // consent_id resta NULL: quella colonna punta a `consents`, non a
            // `parent_consents`. L'id della richiesta sta nel dettaglio sotto.
            $this->audit((int)$row['student_user_id'], 'expired', $ip);
            ActivityLogger::event(
                'parent_consent_expired',
                subjectType: 'user',
                subjectId:   (string)(int)$row['student_user_id'],
                outcome:     'denied',
            );
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

            // 3. Registro immutabile (Art. 30 §1 lett. c+f). Mancava proprio
            //    qui, sul percorso che conta: `reject()` scriveva la sua riga,
            //    il consenso *concesso* no. Il risultato era che l'attivazione
            //    di un account di minore — cioe' la base giuridica del
            //    trattamento, art. 8 — non compariva in nessun registro.
            $db->prepare(
                'INSERT INTO consent_audit
                    (consent_id, user_id, consent_type, event, accessed_at, ip_hash)
                 VALUES (NULL, ?, "parent_consent", "granted", NOW(), ?)'
            )->execute([
                (int)$row['student_user_id'],
                $ip ? hash('sha256', $ip, true) : null,
            ]);

            $db->commit();

            ActivityLogger::event(
                'parent_consent_granted',
                subjectType: 'user',
                subjectId:   (string)(int)$row['student_user_id'],
                details:     ['consent_id' => (int)$row['id']],
            );
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

            ActivityLogger::event(
                'parent_consent_rejected',
                subjectType: 'user',
                subjectId:   (string)$studentId,
                details:     ['consent_id' => (int)$row['id']],
            );
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

            // 3. Registro immutabile: la revoca cancella l'account di un
            //    minore, ed e' l'evento che il DPO deve poter ritrovare.
            $db->prepare(
                'INSERT INTO consent_audit
                    (consent_id, user_id, consent_type, event, accessed_at, ip_hash)
                 VALUES (NULL, ?, "parent_consent", "revoked", NOW(), NULL)'
            )->execute([$studentUserId]);

            $db->commit();

            ActivityLogger::event(
                'parent_consent_revoked',
                subjectType: 'user',
                subjectId:   (string)$studentUserId,
                details:     ['consent_id' => (int)$row['id'], 'via' => 'parent_request'],
            );
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

        // 0. Chi sta per scadere. Serve *prima*: al passo 2 questi account
        //    vengono cancellati davvero, e senza gli id raccolti adesso il
        //    registro non saprebbe piu' dire di chi si trattava.
        $ids = $db->query(
            'SELECT student_user_id FROM parent_consents
              WHERE status = "pending" AND expires_at < NOW()'
        )->fetchAll(PDO::FETCH_COLUMN) ?: [];

        // 1. Mark expired
        $exp = $db->prepare(
            'UPDATE parent_consents SET status="expired"
             WHERE status="pending" AND expires_at < NOW()'
        );
        $exp->execute();
        $expiredCount = $exp->rowCount();

        foreach ($ids as $sid) {
            $this->audit((int)$sid, 'expired', null);
        }

        // 2. Hard-delete student accounts associated with expired consent
        // (mai attivati → safe da cancellare completamente)
        $del = $db->prepare(
            'DELETE u FROM users u
             JOIN parent_consents pc ON pc.student_user_id = u.id
             WHERE pc.status = "expired" AND u.active = 0'
        );
        $del->execute();
        $deletedCount = $del->rowCount();

        if ($expiredCount > 0 || $deletedCount > 0) {
            ActivityLogger::event(
                'parent_consent_cleanup',
                details: [
                    'expired'      => $expiredCount,
                    'deleted'      => $deletedCount,
                    'student_ids'  => array_map('intval', $ids),
                ],
                actorName: 'cron',
                actorRole: 'system',
            );
        }

        return ['expired' => $expiredCount, 'deleted' => $deletedCount];
    }

    /**
     * Riga su `consent_audit`, la tabella append-only che il DPO consulta.
     *
     * Best-effort come ogni scrittura di audit: non deve far fallire il
     * consenso. Ma l'errore va in error_log — un registro che smette di
     * scrivere e' esso stesso un incidente, e finire in un catch vuoto era
     * il difetto di partenza.
     */
    private function audit(int $studentUserId, string $event, ?string $ip, ?int $consentId = null): void
    {
        try {
            Database::connection()->prepare(
                'INSERT INTO consent_audit
                    (consent_id, user_id, consent_type, event, accessed_at, ip_hash)
                 VALUES (?, ?, "parent_consent", ?, NOW(), ?)'
            )->execute([
                $consentId,
                $studentUserId,
                $event,
                $ip ? hash('sha256', $ip, true) : null,
            ]);
        } catch (\Throwable $e) {
            \error_log('[ParentConsentService] consent_audit ' . $event . ' fallita: ' . $e->getMessage());
        }
    }
}
