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

final class RegistrationMailerTest extends TestCase
{
    private array $sent = [];
    private string $logFile;

    protected function setUp(): void
    {
        $this->logFile = sys_get_temp_dir() . '/reg_mail_' . uniqid() . '.log';
    }

    protected function tearDown(): void
    {
        @unlink($this->logFile);
    }

    private function rm(): RegistrationMailer
    {
        $this->sent = [];
        $mailer = new Mailer(
            'operatore@example.net',
            'Pantedu',
            function (string $to, string $subj, string $body, string $hdrs): bool {
                $this->sent[] = compact('to', 'subj', 'body');
                return true;
            },
        );
        return new RegistrationMailer($mailer, 'https://pantedu.eu', $this->logFile);
    }

    #[Test]
    public function pending_sends_waiting_email(): void
    {
        $this->rm()->pending('anna@example.it', 'Anna');
        $this->assertCount(1, $this->sent);
        $this->assertStringContainsString('Anna',             $this->sent[0]['body']);
        $this->assertStringContainsString('in attesa',        $this->sent[0]['body']);
        $this->assertFileExists($this->logFile);
    }

    #[Test]
    public function approved_sends_welcome_with_username(): void
    {
        $this->rm()->approved('anna@example.it', 'Anna', 'anna.rossi');
        $this->assertCount(1, $this->sent);
        $this->assertStringContainsString('anna.rossi',               $this->sent[0]['body']);
        $this->assertStringContainsString('https://pantedu.eu/login', $this->sent[0]['body']);
    }

    #[Test]
    public function rejected_includes_reason_when_given(): void
    {
        $this->rm()->rejected('anna@example.it', 'Anna', 'email già presente');
        $this->assertStringContainsString('email già presente', $this->sent[0]['body']);
    }

    #[Test]
    public function rejected_without_reason(): void
    {
        $this->rm()->rejected('anna@example.it', 'Anna');
        $this->assertStringContainsString('non è stata approvata', $this->sent[0]['body']);
        $this->assertStringNotContainsString('Motivazione:',       $this->sent[0]['body']);
    }
}
