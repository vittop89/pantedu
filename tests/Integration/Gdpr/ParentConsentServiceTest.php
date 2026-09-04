<?php

declare(strict_types=1);

namespace Tests\Integration\Gdpr;

use App\Core\Database;
use App\Services\Gdpr\ParentConsentService;
use PHPUnit\Framework\TestCase;

/**
 * Phase 25.C7 — Test ParentConsentService.
 *
 * Coverage:
 *   1. ageFromBirthDate calculation (incl. boundary 14 anni)
 *   2. requiresParentConsent true se < 14, false ≥ 14
 *   3. request() crea row pending + token 64-hex
 *   4. confirm() attiva user + status confirmed
 *   5. confirm() expired token = false
 *   6. confirm() token invalido = false
 *   7. revoke() cascade delete student account
 *   8. cleanupExpired marca expired + cancella user pending
 */
final class ParentConsentServiceTest extends TestCase
{
    private ParentConsentService $svc;
    private array $createdStudents = [];

    protected function setUp(): void
    {
        $basePath = dirname(__DIR__, 3);
        if (is_file($basePath . '/.env')) {
            \Dotenv\Dotenv::createMutable($basePath)->safeLoad();
        }
        if (is_file($basePath . '/.env.local')) {
            \Dotenv\Dotenv::createMutable($basePath, '.env.local')->safeLoad();
        }
        \App\Core\Config::load(dirname(__DIR__, 3) . '/app/Config');

        try {
            Database::connection()->query('SELECT 1');
        } catch (\Throwable $e) {
            $this->markTestSkipped('DB not available');
        }

        $this->svc = new ParentConsentService();
    }

    protected function tearDown(): void
    {
        if ($this->createdStudents) {
            $in = implode(',', array_map('intval', $this->createdStudents));
            Database::connection()->exec("DELETE FROM parent_consents WHERE student_user_id IN ($in)");
            Database::connection()->exec("DELETE FROM users WHERE id IN ($in)");
        }
    }

    public function testAgeFromBirthDateCalculatesYears(): void
    {
        $thirteenYearsAgo = date('Y-m-d', strtotime('-13 years -1 month'));
        $this->assertSame(13, ParentConsentService::ageFromBirthDate($thirteenYearsAgo));

        $fifteenYearsAgo = date('Y-m-d', strtotime('-15 years'));
        $this->assertSame(15, ParentConsentService::ageFromBirthDate($fifteenYearsAgo));
    }

    public function testRequiresParentConsentBoundary14(): void
    {
        // < 14 → richiede consenso
        $this->assertTrue(
            ParentConsentService::requiresParentConsent(date('Y-m-d', strtotime('-13 years -1 month'))),
            '13 anni richiede consenso'
        );

        // ≥ 14 → autonomo
        $this->assertFalse(
            ParentConsentService::requiresParentConsent(date('Y-m-d', strtotime('-14 years -1 day'))),
            '14 anni autonomo'
        );
        $this->assertFalse(
            ParentConsentService::requiresParentConsent(date('Y-m-d', strtotime('-15 years'))),
            '15 anni autonomo'
        );
    }

    public function testRequestCreatesTokenAndPendingRow(): void
    {
        $studentId = $this->createTestMinor();
        $token = $this->svc->request($studentId, 'parent@test.local', 'Genitore Test');

        $this->assertSame(64, strlen($token), 'token 64 hex char');
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $token);

        $row = $this->svc->findByStudent($studentId);
        $this->assertSame('pending', $row['status']);
        $this->assertSame('parent@test.local', $row['parent_email']);
    }

    public function testRequestRejectsInvalidEmail(): void
    {
        $studentId = $this->createTestMinor();
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/invalid_parent_email/');
        $this->svc->request($studentId, 'not-an-email', null);
    }

    public function testConfirmActivatesStudentAccount(): void
    {
        $studentId = $this->createTestMinor();
        $token = $this->svc->request($studentId, 'parent@test.local');

        $result = $this->svc->confirm($token, '127.0.0.1', 'Mozilla/5.0');
        $this->assertTrue($result['ok']);
        $this->assertSame($studentId, $result['student_user_id']);

        // Verify status confirmed + user active
        $row = $this->svc->findByStudent($studentId);
        $this->assertSame('confirmed', $row['status']);
        $this->assertNotNull($row['confirmed_at']);

        $userActive = (int)Database::connection()
            ->query("SELECT active FROM users WHERE id=$studentId")
            ->fetchColumn();
        $this->assertSame(1, $userActive);
    }

    public function testConfirmTokenInvalidReturnsFalse(): void
    {
        $result = $this->svc->confirm('not_a_real_token', null, null);
        $this->assertFalse($result['ok']);
        $this->assertSame('token_invalid_or_used', $result['error']);
    }

    public function testConfirmExpiredTokenReturnsFalse(): void
    {
        $studentId = $this->createTestMinor();
        $token = $this->svc->request($studentId, 'parent@test.local');

        // Forza expires_at nel passato
        Database::connection()->prepare(
            'UPDATE parent_consents SET expires_at = DATE_SUB(NOW(), INTERVAL 1 DAY) WHERE confirm_token=?'
        )->execute([$token]);

        $result = $this->svc->confirm($token, null, null);
        $this->assertFalse($result['ok']);
        $this->assertSame('token_expired', $result['error']);

        // Verify row marcata expired
        $row = $this->svc->findByStudent($studentId);
        $this->assertSame('expired', $row['status']);
    }

    public function testRevokeCascadeDeletesStudent(): void
    {
        $studentId = $this->createTestMinor();
        $token = $this->svc->request($studentId, 'parent@test.local');
        $this->svc->confirm($token);

        // Revoke con email genitore corretta
        $ok = $this->svc->revoke($studentId, 'parent@test.local');
        $this->assertTrue($ok);

        // Verify user anonymized + deleted_at set
        $row = Database::connection()
            ->query("SELECT email, active, deleted_at FROM users WHERE id=$studentId")
            ->fetch(\PDO::FETCH_ASSOC);
        $this->assertSame("anon-{$studentId}@invalid.local", $row['email']);
        $this->assertSame(0, (int)$row['active']);
        $this->assertNotNull($row['deleted_at']);

        // Verify parent_consent revoked
        $consent = $this->svc->findByStudent($studentId);
        $this->assertSame('revoked', $consent['status']);
    }

    public function testRevokeRejectsWrongParentEmail(): void
    {
        $studentId = $this->createTestMinor();
        $token = $this->svc->request($studentId, 'real@parent.local');
        $this->svc->confirm($token);

        $ok = $this->svc->revoke($studentId, 'fake@attacker.local');
        $this->assertFalse($ok, 'Email mismatch → revoke deny');
    }

    /**
     * Phase 25.C7.fix (GDPR-001) — reject path con audit + soft-delete.
     */
    public function testRejectSoftDeletesAndLogsAudit(): void
    {
        $studentId = $this->createTestMinor();
        $token = $this->svc->request($studentId, 'parent@test.local', 'Genitore Test');

        $result = $this->svc->reject($token, '203.0.113.7', 'Mozilla/5.0 RejectTest');
        $this->assertTrue($result['ok']);
        $this->assertSame($studentId, $result['student_user_id']);

        // 1. parent_consents: status=revoked + ip/ua hash valorizzati
        $row = $this->svc->findByStudent($studentId);
        $this->assertSame('revoked', $row['status']);
        $this->assertNotNull($row['revoked_at']);

        // 2. user: anonymized + soft-delete, NON hard-deleted
        $user = Database::connection()
            ->query("SELECT email, first_name, last_name, active, deleted_at, status FROM users WHERE id=$studentId")
            ->fetch(\PDO::FETCH_ASSOC);
        $this->assertNotFalse($user, 'user ancora presente (soft-delete, no hard DELETE)');
        $this->assertSame("anon-{$studentId}@invalid.local", $user['email']);
        $this->assertSame('', $user['first_name']);
        $this->assertSame(0, (int)$user['active']);
        $this->assertNotNull($user['deleted_at']);
        $this->assertSame('rejected_parent_consent', $user['status']);

        // 3. consent_audit: 1 row con event=revoked
        $auditCount = (int)Database::connection()->prepare(
            'SELECT COUNT(*) FROM consent_audit
             WHERE user_id=? AND consent_type="parent_consent" AND event="revoked"'
        )->execute([$studentId]) ? (int)Database::connection()->query(
            "SELECT COUNT(*) FROM consent_audit WHERE user_id=$studentId AND consent_type='parent_consent' AND event='revoked'"
        )->fetchColumn() : 0;
        $this->assertSame(1, $auditCount, 'audit log Art. 30 row present');

        // Cleanup consent_audit (no FK cascade)
        Database::connection()->exec("DELETE FROM consent_audit WHERE user_id=$studentId");
    }

    public function testRejectInvalidTokenReturnsError(): void
    {
        $result = $this->svc->reject('not_a_real_token', null, null);
        $this->assertFalse($result['ok']);
        $this->assertSame('token_invalid_or_used', $result['error']);
    }

    public function testRejectIdempotentOnAlreadyRejected(): void
    {
        $studentId = $this->createTestMinor();
        $token = $this->svc->request($studentId, 'parent@test.local');

        $first = $this->svc->reject($token);
        $this->assertTrue($first['ok']);

        // Secondo tentativo: token già usato (status != pending)
        $second = $this->svc->reject($token);
        $this->assertFalse($second['ok']);
        $this->assertSame('token_invalid_or_used', $second['error']);

        Database::connection()->exec("DELETE FROM consent_audit WHERE user_id=$studentId");
    }

    public function testCleanupExpiredCascadeDeletesPendingStudents(): void
    {
        $studentId = $this->createTestMinor();
        $token = $this->svc->request($studentId, 'parent@test.local');

        // Forza scadenza
        Database::connection()->prepare(
            'UPDATE parent_consents SET expires_at = DATE_SUB(NOW(), INTERVAL 1 DAY) WHERE confirm_token=?'
        )->execute([$token]);

        $stats = $this->svc->cleanupExpired();
        $this->assertSame(1, $stats['expired']);
        $this->assertSame(1, $stats['deleted']);

        // Student deleted hard
        $exists = Database::connection()
            ->query("SELECT COUNT(*) FROM users WHERE id=$studentId")
            ->fetchColumn();
        $this->assertSame(0, (int)$exists);

        // Rimuovi da $createdStudents (già cancellato hard)
        $this->createdStudents = array_diff($this->createdStudents, [$studentId]);
    }

    /**
     * Helper: crea uno student "minore" pendente per i test.
     */
    private function createTestMinor(): int
    {
        $birthDate = date('Y-m-d', strtotime('-12 years'));
        $email = '_test_minor_' . uniqid() . '@test.local';
        $stmt = Database::connection()->prepare(
            'INSERT INTO users (username, role, first_name, last_name, email,
                                password_hash, status, active, birth_date)
             VALUES (?, "student", "Mario", "MinoreTest", ?, "hash",
                     "pending_parent_consent", 0, ?)'
        );
        $username = '_test_minor_' . uniqid();
        $stmt->execute([$username, $email, $birthDate]);
        $id = (int)Database::connection()->lastInsertId();
        $this->createdStudents[] = $id;
        return $id;
    }
}
