<?php

namespace Tests\Unit;

use App\Services\Mailer;
use App\Services\RegistrationMailer;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

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

    private function rm(?string $replyTo = null): RegistrationMailer
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
            ? new RegistrationMailer($mailer, 'https://pantedu.eu', $this->logFile)
            : new RegistrationMailer($mailer, 'https://pantedu.eu', $this->logFile, $replyTo);
    }

    #[Test]
    public function every_registration_email_can_be_replied_to(): void
    {
        // Vale per tutti e tre: chi riceve "in attesa", "approvato" o
        // "rifiutato" e chiede spiegazioni non deve finire in noreply@.
        $this->rm()->pending('anna@example.it', 'Anna');
        $this->rm()->approved('anna@example.it', 'Anna', 'anna.rossi');
        $this->rm()->rejected('anna@example.it', 'Anna');

        foreach ($this->sent as $msg) {
            $this->assertStringContainsString("Reply-To: operatore@example.net\r\n", $msg['hdrs']);
        }
    }

    #[Test]
    public function rejection_tells_the_user_how_to_ask(): void
    {
        $this->rm()->rejected('anna@example.it', 'Anna');
        $this->assertStringContainsString('rispondere a questa email', $this->sent[0]['body']);
    }

    #[Test]
    public function pending_sends_waiting_email(): void
    {
        $this->rm()->pending('anna@example.it', 'Anna');
        $this->assertCount(1, $this->sent);
        $this->assertStringContainsString('Anna', $this->sent[0]['body']);
        // "in attesa" vive nell'oggetto, non nel corpo: l'assert precedente
        // cercava li' una stringa che il testo non ha mai avuto, e nessuno se
        // n'e' accorto perche' questa classe non veniva raccolta da PHPUnit.
        // Cio' che conta e' che il corpo dica all'utente che deve aspettare.
        $this->assertStringContainsString('esaminer', $this->sent[0]['body']);
        $this->assertStringContainsString('seconda email', $this->sent[0]['body']);
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
