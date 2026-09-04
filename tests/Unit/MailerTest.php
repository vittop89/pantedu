<?php

namespace Tests\Unit;

use App\Services\Mailer;
use App\Services\RegistrationMailer;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class MailerTest extends TestCase
{
    private array $sent = [];

    private function fakeMailer(): Mailer
    {
        $this->sent = [];
        return new Mailer(
            'operatore@example.net',
            'Pantedu',
            function (string $to, string $subj, string $body, string $hdrs): bool {
                $this->sent[] = compact('to', 'subj', 'body', 'hdrs');
                return true;
            },
        );
    }

    #[Test]
    public function send_with_utf8_subject(): void
    {
        $m = $this->fakeMailer();
        $this->assertTrue($m->send('anna@example.it', 'Conferma iscrizione', 'Ciao'));
        $this->assertCount(1, $this->sent);
        $this->assertStringContainsString('=?UTF-8?B?', $this->sent[0]['subj']);
    }

    #[Test]
    public function send_rejects_invalid_email(): void
    {
        $m = $this->fakeMailer();
        $this->expectException(RuntimeException::class);
        $m->send('not-an-email', 'Test', 'body');
    }

    #[Test]
    public function send_rejects_empty_subject(): void
    {
        $m = $this->fakeMailer();
        $this->expectException(RuntimeException::class);
        $m->send('a@b.it', '', 'body');
    }

    #[Test]
    public function send_rejects_oversized_body(): void
    {
        $m = $this->fakeMailer();
        $this->expectException(RuntimeException::class);
        $m->send('a@b.it', 'Subject', str_repeat('x', 200_000));
    }

    #[Test]
    public function reply_to_defaults_to_from(): void
    {
        $m = $this->fakeMailer();
        $m->send('anna@example.it', 'Subject', 'body');
        $this->assertStringContainsString("Reply-To: operatore@example.net\r\n", $this->sent[0]['hdrs']);
    }

    #[Test]
    public function reply_to_can_diverge_from_send_only_sender(): void
    {
        $m = $this->fakeMailer();
        $m->send('anna@example.it', 'Subject', 'body', 'operatore@example.net');
        $this->assertStringContainsString("From: Pantedu <operatore@example.net>\r\n", $this->sent[0]['hdrs']);
        $this->assertStringContainsString("Reply-To: operatore@example.net\r\n", $this->sent[0]['hdrs']);
    }

    #[Test]
    public function send_rejects_invalid_reply_to(): void
    {
        $m = $this->fakeMailer();
        $this->expectException(RuntimeException::class);
        $m->send('anna@example.it', 'Subject', 'body', 'not-an-email');
    }

    #[Test]
    public function log_write_creates_file(): void
    {
        $m = $this->fakeMailer();
        $log = sys_get_temp_dir() . '/pantedu_mail_' . uniqid() . '.log';
        $m->logSend('a@b.it', 'Subj', 'Body', $log);
        $this->assertFileExists($log);
        $this->assertStringContainsString('TO=a@b.it', file_get_contents($log));
        @unlink($log);
    }
}
