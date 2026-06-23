<?php

namespace Tests\Unit;

use App\Services\Mailer;
use App\Services\ParentConsentMailer;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Phase 25.C8 — ParentConsentMailer tests.
 */
final class ParentConsentMailerTest extends TestCase
{
    private array $sent = [];
    private string $logFile;

    protected function setUp(): void
    {
        $this->logFile = sys_get_temp_dir() . '/parent_mail_' . uniqid() . '.log';
    }

    protected function tearDown(): void
    {
        @unlink($this->logFile);
    }

    private function pcm(): ParentConsentMailer
    {
        $this->sent = [];
        $mailer = new Mailer(
            'noreply@pantedu.eu',
            'Pantedu',
            function (string $to, string $subj, string $body, string $hdrs): bool {
                $this->sent[] = compact('to', 'subj', 'body');
                return true;
            },
        );
        return new ParentConsentMailer($mailer, 'https://pantedu.eu', $this->logFile);
    }

    #[Test]
    public function request_consent_sends_email_with_token_link(): void
    {
        $token = str_repeat('a', 64);
        $this->pcm()->requestConsent('genitore@example.it', $token, 'Marco', 'Sig.ra Rossi');
        $this->assertCount(1, $this->sent);
        $this->assertStringContainsString('Sig.ra Rossi',                                $this->sent[0]['body']);
        $this->assertStringContainsString('Marco',                                       $this->sent[0]['body']);
        $this->assertStringContainsString("https://pantedu.eu/parent-consent/$token", $this->sent[0]['body']);
        $this->assertStringContainsString('Art. 8 GDPR',                                 $this->sent[0]['body']);
        $this->assertFileExists($this->logFile);
    }

    #[Test]
    public function request_consent_uses_generic_greet_without_parent_name(): void
    {
        $this->pcm()->requestConsent('genitore@example.it', str_repeat('b', 64), 'Marco', null);
        $this->assertStringContainsString('Gentile genitore', $this->sent[0]['body']);
    }
}
