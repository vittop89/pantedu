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

    private function pcm(?string $replyTo = null): ParentConsentMailer
    {
        $this->sent = [];
        $mailer = new Mailer(
            'operatore@example.net',
            'Pantedu',
            function (string $to, string $subj, string $body, string $hdrs): bool {
                $this->sent[] = compact('to', 'subj', 'body', 'hdrs');
                return true;
            },
        );
        return $replyTo === null
            ? new ParentConsentMailer($mailer, 'https://pantedu.eu', $this->logFile)
            : new ParentConsentMailer($mailer, 'https://pantedu.eu', $this->logFile, $replyTo);
    }

    #[Test]
    public function reply_goes_to_a_read_mailbox_not_to_the_send_only_sender(): void
    {
        // Il genitore che chiede "che cos'e' questa email sui dati di mio
        // figlio?" preme Rispondi: quella risposta deve arrivare a qualcuno.
        $this->pcm()->requestConsent('genitore@example.it', str_repeat('a', 64), 'Anna');

        $this->assertStringContainsString("From: Pantedu <operatore@example.net>\r\n", $this->sent[0]['hdrs']);
        $this->assertStringContainsString("Reply-To: operatore@example.net\r\n", $this->sent[0]['hdrs']);
    }

    #[Test]
    public function reply_to_is_configurable(): void
    {
        // In Scenario B/C il titolare e' l'Istituto: la risposta del genitore
        // deve poter andare al suo DPO, non al nostro.
        $this->pcm('privacy@scuola.example')
            ->requestConsent('genitore@example.it', str_repeat('b', 64), 'Anna');

        $this->assertStringContainsString("Reply-To: privacy@scuola.example\r\n", $this->sent[0]['hdrs']);
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
