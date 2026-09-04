<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Security;

use App\Services\Security\TotpService;
use App\Services\Security\TwoFactorPolicy;
use PDO;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Copre la politica del secondo fattore.
 *
 * Il caso che conta piu' di tutti e' `backup_code_is_consumed`: un codice di
 * riserva che resta valido dopo l'uso non e' un secondo fattore, e' una
 * seconda password statica scritta su un foglio. Se questo test diventa
 * verde per il motivo sbagliato — per esempio perche' consumeBackupCode
 * ritorna true senza scrivere — la 2FA continua ad apparire attiva mentre
 * non lo e' piu': esattamente il difetto che l'ha resa necessaria.
 */
final class TwoFactorPolicyTest extends TestCase
{
    private const USER = 'docente_prova';

    private function pdo(): PDO
    {
        if (!\in_array('sqlite', PDO::getAvailableDrivers(), true)) {
            $this->markTestSkipped('pdo_sqlite non disponibile in questo runtime');
        }
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $pdo->exec(
            'CREATE TABLE users (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                username TEXT NOT NULL,
                totp_enabled INTEGER NOT NULL DEFAULT 0,
                totp_secret TEXT,
                totp_backup_codes TEXT,
                two_factor_method TEXT DEFAULT NULL
            )'
        );
        return $pdo;
    }

    /** @param list<string> $backups */
    private function seed(PDO $pdo, string $secret, array $backups, int $enabled = 1): void
    {
        $hashes = (new TotpService())->hashBackupCodes($backups);
        $st = $pdo->prepare(
            'INSERT INTO users (username, totp_enabled, totp_secret, totp_backup_codes)
             VALUES (?, ?, ?, ?)'
        );
        $st->execute([self::USER, $enabled, $secret, json_encode($hashes)]);
    }

    #[Test]
    public function enabled_reflects_the_column(): void
    {
        $pdo = $this->pdo();
        $this->seed($pdo, 'ABCDEFGHIJKLMNOP', ['aaa111'], enabled: 0);
        self::assertFalse((new TwoFactorPolicy($pdo))->enabledFor(self::USER));

        $pdo->exec('UPDATE users SET totp_enabled = 1');
        self::assertTrue((new TwoFactorPolicy($pdo))->enabledFor(self::USER));
    }

    #[Test]
    public function unknown_user_has_no_second_factor(): void
    {
        self::assertFalse((new TwoFactorPolicy($this->pdo()))->enabledFor('nessuno'));
    }

    #[Test]
    public function totp_code_verifies_against_the_stored_secret(): void
    {
        $svc    = new TotpService();
        $secret = $svc->generateSecret();
        $pdo    = $this->pdo();
        $this->seed($pdo, $secret, ['aaa111']);

        $policy = new TwoFactorPolicy($pdo);
        self::assertTrue($policy->verifyTotp(self::USER, $svc->generateCode($secret)));
        self::assertFalse($policy->verifyTotp(self::USER, '000000'));
    }

    #[Test]
    public function a_disabled_account_never_verifies(): void
    {
        $svc    = new TotpService();
        $secret = $svc->generateSecret();
        $pdo    = $this->pdo();
        $this->seed($pdo, $secret, ['aaa111'], enabled: 0);

        // Il codice e' giusto, ma la 2FA non e' attiva: verificarlo come valido
        // significherebbe far passare per secondo fattore un segreto che
        // l'utente ha smesso di usare.
        self::assertFalse((new TwoFactorPolicy($pdo))->verifyTotp(self::USER, $svc->generateCode($secret)));
    }

    #[Test]
    public function backup_code_is_consumed(): void
    {
        $pdo = $this->pdo();
        $this->seed($pdo, 'ABCDEFGHIJKLMNOP', ['aaa111', 'bbb222', 'ccc333']);
        $policy = new TwoFactorPolicy($pdo);

        self::assertSame(3, $policy->backupCodesLeft(self::USER));
        self::assertTrue($policy->consumeBackupCode(self::USER, 'bbb222'), 'primo uso valido');
        self::assertSame(2, $policy->backupCodesLeft(self::USER), 'il codice usato sparisce dalla lista');

        self::assertFalse(
            $policy->consumeBackupCode(self::USER, 'bbb222'),
            'lo stesso codice non deve valere una seconda volta'
        );
        self::assertTrue($policy->consumeBackupCode(self::USER, 'ccc333'), 'gli altri restano validi');
        self::assertSame(1, $policy->backupCodesLeft(self::USER));
    }

    #[Test]
    public function wrong_backup_code_consumes_nothing(): void
    {
        $pdo = $this->pdo();
        $this->seed($pdo, 'ABCDEFGHIJKLMNOP', ['aaa111', 'bbb222']);
        $policy = new TwoFactorPolicy($pdo);

        self::assertFalse($policy->consumeBackupCode(self::USER, 'zzz999'));
        self::assertSame(2, $policy->backupCodesLeft(self::USER));
    }

    #[Test]
    public function the_method_decides_which_code_is_accepted(): void
    {
        // Il caso che rende utile questo test: chi passa da un metodo all'altro.
        // Se l'iscrizione via app non riscrive `two_factor_method`, resta il
        // valore 'email' di prima e al login viene spedito un codice per posta
        // mentre l'utente digita quello dell'app. Nessun errore, nessun log:
        // semplicemente non entra piu'.
        $svc    = new TotpService();
        $secret = $svc->generateSecret();
        $pdo    = $this->pdo();
        $this->seed($pdo, $secret, ['aaa111']);

        // Metodo assente: si assume l'app, per le iscrizioni fatte prima della
        // migration 096, quando era l'unica strada.
        self::assertSame('app', (new TwoFactorPolicy($pdo))->methodFor(self::USER));
        self::assertTrue((new TwoFactorPolicy($pdo))->verifyCode(self::USER, $svc->generateCode($secret)));

        // Metodo email: il codice dell'app NON deve essere accettato, altrimenti
        // il secondo fattore varrebbe per due strade invece di una.
        $pdo->exec('UPDATE users SET two_factor_method = "email"');
        $policy = new TwoFactorPolicy($pdo);
        self::assertSame('email', $policy->methodFor(self::USER));
        self::assertFalse(
            $policy->verifyCode(self::USER, $svc->generateCode($secret)),
            'col metodo email un codice TOTP non deve aprire l accesso'
        );

        // Senza secondo fattore attivo non c'e' metodo.
        $pdo->exec('UPDATE users SET totp_enabled = 0');
        self::assertNull((new TwoFactorPolicy($pdo))->methodFor(self::USER));
    }

    #[Test]
    public function role_requirement_reads_the_configuration(): void
    {
        \App\Support\TwoFactorEnforcement::resetCache();
        $policy = new TwoFactorPolicy($this->pdo());

        // Il master switch spento non impone nulla, a nessun ruolo.
        \App\Core\Config::set('security.totp_enabled', false);
        \App\Core\Config::set('security.totp_required_roles', ['administrator']);
        self::assertFalse($policy->requiredForRole('administrator'));

        \App\Core\Config::set('security.totp_enabled', true);
        self::assertTrue($policy->requiredForRole('administrator'));
        self::assertFalse($policy->requiredForRole('teacher'), 'solo i ruoli elencati');
    }

    #[Test]
    public function must_enrol_only_when_required_and_missing(): void
    {
        $pdo = $this->pdo();
        $this->seed($pdo, 'ABCDEFGHIJKLMNOP', ['aaa111'], enabled: 0);
        $policy = new TwoFactorPolicy($pdo);

        \App\Core\Config::set('security.totp_enabled', true);
        \App\Core\Config::set('security.totp_required_roles', ['teacher']);
        \App\Support\TwoFactorEnforcement::resetCache();
        self::assertTrue($policy->mustEnrol(self::USER, 'teacher'));

        $pdo->exec('UPDATE users SET totp_enabled = 1');
        self::assertFalse(
            (new TwoFactorPolicy($pdo))->mustEnrol(self::USER, 'teacher'),
            'chi l ha gia attivata non va accompagnato all iscrizione'
        );
    }

    protected function tearDown(): void
    {
        \App\Core\Config::set('security.totp_enabled', false);
        \App\Core\Config::set('security.totp_required_roles', []);
        \App\Support\TwoFactorEnforcement::resetCache();
    }
}
