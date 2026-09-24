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
        // La casella la passa RegistrationService (`mail.dpo_email`).
        $this->pcm('operatore@example.net')->requestConsent('genitore@example.it', str_repeat('a', 64), 'Anna');

        $this->assertStringContainsString("From: Pantedu <operatore@example.net>\r\n", $this->sent[0]['hdrs']);
        $this->assertStringContainsString("Reply-To: operatore@example.net\r\n", $this->sent[0]['hdrs']);
    }

    #[Test]
    public function senza_casella_nessun_reply_to_verso_la_casella_di_produzione(): void
    {
        // 23/9/2026 (A-13): il predefinito era dpo@ di produzione, anche per
        // un'altra istanza. Senza casella, il Reply-To è il mittente stesso.
        $this->pcm()->requestConsent('genitore@example.it', str_repeat('c', 64), 'Anna');

        $this->assertStringContainsString("Reply-To: operatore@example.net\r\n", $this->sent[0]['hdrs']);
        $this->assertStringNotContainsString('dpo@', $this->sent[0]['hdrs']);
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
        $this->pcm()->requestConsent('genitore@example.it', $token, 'Docente2', 'Sig.ra Rossi');
        $this->assertCount(1, $this->sent);
        $this->assertStringContainsString('Sig.ra Rossi',                                $this->sent[0]['body']);
        $this->assertStringContainsString('Docente2',                                       $this->sent[0]['body']);
        $this->assertStringContainsString("https://pantedu.eu/parent-consent/$token", $this->sent[0]['body']);
        $this->assertStringContainsString('Art. 8 GDPR',                                 $this->sent[0]['body']);
        $this->assertFileExists($this->logFile);
    }

    #[Test]
    public function request_consent_uses_generic_greet_without_parent_name(): void
    {
        $this->pcm()->requestConsent('genitore@example.it', str_repeat('b', 64), 'Docente2', null);
        $this->assertStringContainsString('Gentile genitore', $this->sent[0]['body']);
    }

    /**
     * 23/9/2026, A-67. Il registro della posta copiava il collegamento intero:
     * chi leggeva il registro, o un suo backup, aveva il gettone con cui dare
     * o rifiutare il consenso al posto del genitore. Il gettone sta nell'email,
     * e solo li'. La prima asserzione impedisce che la seconda passi perche'
     * il gettone non c'e' da nessuna parte.
     */
    #[Test]
    public function il_registro_della_posta_non_contiene_il_gettone(): void
    {
        $token = bin2hex(random_bytes(32));
        $this->pcm()->requestConsent('genitore@example.it', $token, 'Docente2');

        $email = $this->sent[0]['body'];
        $this->assertStringContainsString("/parent-consent/$token", $email, 'l\'email porta il gettone');
        $registro = (string)file_get_contents($this->logFile);
        $this->assertStringContainsString('TO=genitore@example.it', $registro, 'il registro dice a chi e\' partita');
        $this->assertStringNotContainsString($token, $registro, 'il registro non contiene il gettone');
        $this->assertStringContainsString('/parent-consent/[gettone omesso]', $registro, 'al suo posto il segnaposto');
    }

    #[Test]
    public function un_invio_fallito_non_scrive_l_indirizzo_nel_registro_degli_errori(): void
    {
        $errori = sys_get_temp_dir() . '/parent_mail_err_' . uniqid() . '.log';
        $prima = (string)ini_get('error_log');
        ini_set('error_log', $errori);
        try {
            $mailer = new Mailer(
                'operatore@example.net',
                'Pantedu',
                static function (): bool {
                    throw new \RuntimeException('trasporto giu\'');
                },
            );
            $ok = (new ParentConsentMailer($mailer, 'https://pantedu.eu', $this->logFile))
                ->requestConsent('genitore.errori@example.it', str_repeat('c', 64), 'Docente2');
            $scritto = (string)@file_get_contents($errori);
        } finally {
            ini_set('error_log', $prima);
            @unlink($errori);
        }

        $this->assertFalse($ok);
        $this->assertStringContainsString('trasporto giu', $scritto, 'l\'errore si registra');
        $this->assertStringNotContainsString(
            'genitore.errori@example.it',
            $scritto,
            'ma senza l\'indirizzo del genitore'
        );
    }
}
