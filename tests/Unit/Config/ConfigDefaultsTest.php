<?php

declare(strict_types=1);

namespace Tests\Unit\Config;

use App\Core\Config;
use App\Services\Mailer;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * I file di configurazione introdotti dalla revisione 2026-09 (P7) devono
 * dare, a variabili d'ambiente assenti, gli stessi default che il codice
 * aveva quando leggeva $_ENV per conto proprio.
 */
final class ConfigDefaultsTest extends TestCase
{
    /** @var array<string, string|null> */
    private array $savedEnv = [];

    /** @var array<string, mixed> */
    private array $savedConfig = [];

    private const ENV_KEYS = [
        'APP_MAIL_FROM', 'APP_MAIL_FROM_NAME', 'APP_MAIL_REPLY_TO', 'DPO_EMAIL', 'RESEND_API_KEY',
        'CONTACT_EMAIL', 'ABUSE_EMAIL', 'SECURITY_EMAIL',
        'AUDIT_REASON_MODE', 'LOG_MAX_ENTRIES',
        'CRYPTO_DUAL_WRITE', 'CRYPTO_READ_FROM', 'ALLOW_CRYPTO_REGENERATE',
    ];

    protected function setUp(): void
    {
        foreach (self::ENV_KEYS as $k) {
            $this->savedEnv[$k] = $_ENV[$k] ?? null;
            unset($_ENV[$k]);
        }
        foreach (['mail.from', 'mail.from_name'] as $k) {
            $this->savedConfig[$k] = Config::get($k);
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->savedEnv as $k => $v) {
            if ($v === null) {
                unset($_ENV[$k]);
            } else {
                $_ENV[$k] = $v;
            }
        }
        foreach ($this->savedConfig as $k => $v) {
            Config::set($k, $v);
        }
    }

    /** @return array<string, mixed> */
    private function load(string $name): array
    {
        $cfg = require dirname(__DIR__, 3) . "/app/Config/$name.php";
        self::assertIsArray($cfg);
        return $cfg;
    }

    #[Test]
    public function mail_defaults_match_previous_inline_fallbacks(): void
    {
        $mail = $this->load('mail');
        self::assertSame('', $mail['from']);
        self::assertSame('Pantedu', $mail['from_name']);
        self::assertSame('', $mail['reply_to']);
        self::assertSame('', $mail['resend_api_key']);

        $_ENV['APP_MAIL_FROM_NAME'] = '';
        self::assertSame('Pantedu', $this->load('mail')['from_name'], 'nome vuoto → default');
    }

    /**
     * 23/9/2026 (rilievo A-13) — l'eccezione alla regola della classe: il
     * vecchio default di `dpo_email` era la casella del DPO di produzione, e
     * non lo si conserva. Nessuna casella ha un valore scritto nel codice: il
     * ripiego è CONTACT_EMAIL, e senza nemmeno quella la casella è vuota.
     */
    #[Test]
    public function le_caselle_non_ripiegano_sulle_caselle_di_produzione(): void
    {
        $mail = $this->load('mail');
        foreach (['contact_email', 'dpo_email', 'abuse_email', 'security_email'] as $casella) {
            self::assertSame('', $mail[$casella], "$casella senza configurazione è vuota");
        }

        $_ENV['CONTACT_EMAIL'] = ' info@scuola.example ';
        $mail = $this->load('mail');
        foreach (['contact_email', 'dpo_email', 'abuse_email', 'security_email'] as $casella) {
            self::assertSame('info@scuola.example', $mail[$casella], "$casella ripiega sulla casella generale");
        }

        $_ENV['DPO_EMAIL'] = 'dpo@scuola.example';
        $_ENV['ABUSE_EMAIL'] = 'segnalazioni@scuola.example';
        $_ENV['SECURITY_EMAIL'] = 'sicurezza@scuola.example';
        $mail = $this->load('mail');
        self::assertSame('dpo@scuola.example', $mail['dpo_email']);
        self::assertSame('segnalazioni@scuola.example', $mail['abuse_email']);
        self::assertSame('sicurezza@scuola.example', $mail['security_email']);
        self::assertSame('info@scuola.example', $mail['contact_email']);
    }

    #[Test]
    public function audit_reason_mode_defaults_to_enforce_and_rejects_unknown_values(): void
    {
        // 2026-09-23 — il predefinito era `warn`, il modo del rollout: ora è
        // `enforce`, anche per un valore sconosciuto (A-37 della revisione
        // del 23/9; prova del middleware in
        // tests/Unit/Middleware/MotivazioneObbligatoriaPredefinitaTest.php).
        if (getenv('AUDIT_REASON_MODE') !== false) {
            self::markTestSkipped('AUDIT_REASON_MODE impostata nell\'ambiente del processo');
        }
        self::assertSame('enforce', $this->load('audit')['reason_mode']);
        self::assertSame(1000, $this->load('audit')['access_log_max_entries']);

        $_ENV['AUDIT_REASON_MODE'] = 'WARN';
        self::assertSame('warn', $this->load('audit')['reason_mode'], 'un modo scritto per nome vale ancora');

        $_ENV['AUDIT_REASON_MODE'] = 'boh';
        self::assertSame('enforce', $this->load('audit')['reason_mode']);
    }

    #[Test]
    public function crypto_flags_default_off_and_accept_both_spellings(): void
    {
        $c = $this->load('crypto');
        self::assertFalse($c['dual_write']);
        self::assertSame('plaintext', $c['read_from']);
        self::assertFalse($c['allow_regenerate']);

        $_ENV['CRYPTO_DUAL_WRITE'] = 'true';
        $_ENV['CRYPTO_READ_FROM'] = 'ciphertext';
        $_ENV['ALLOW_CRYPTO_REGENERATE'] = '1';
        $c = $this->load('crypto');
        self::assertTrue($c['dual_write']);
        self::assertSame('ciphertext', $c['read_from']);
        self::assertTrue($c['allow_regenerate']);

        $_ENV['CRYPTO_DUAL_WRITE'] = '1';
        $_ENV['CRYPTO_READ_FROM'] = 'altro';
        $c = $this->load('crypto');
        self::assertTrue($c['dual_write']);
        self::assertSame('plaintext', $c['read_from']);
    }

    #[Test]
    public function mailer_from_config_is_null_without_sender(): void
    {
        Config::set('mail.from', '');
        self::assertNull(Mailer::fromConfig());

        Config::set('mail.from', 'noreply@example.org');
        Config::set('mail.from_name', 'Istanza');
        self::assertInstanceOf(Mailer::class, Mailer::fromConfig());
    }
}
