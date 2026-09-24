<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Gdpr;

use App\Controllers\Admin\AdminTakedownController;
use App\Core\Config;
use App\Core\Request;
use App\Services\Gdpr\TakedownRequestService;
use App\Services\Mailer;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/** Doppio senza DB: registra cosa il controller ha chiesto di scrivere. */
final class FakeTakedownRequestService extends TakedownRequestService
{
    public bool $marked = false;
    public ?string $status = null;

    /** @param array{email: string, name: string}|null $contact */
    public function __construct(private ?array $contact)
    {
        parent::__construct();
    }

    public function updateStatus(
        int $requestId,
        string $newStatus,
        string $action,
        string $notes,
        int $actionedByUserId
    ): void {
        $this->status = $newStatus;
    }

    public function uploaderContact(int $requestId): ?array
    {
        return $this->contact;
    }

    // Restituisce sempre una richiesta: il tipo lo dice, invece di
    // dichiarare un `null` che questo finto non produce mai. Restringere il
    // tipo di ritorno in una sottoclasse è lecito.
    public function get(int $requestId): array
    {
        return [
            'submitted_at'   => '2026-08-30 10:00:00',
            'violation_type' => 'copyright',
            'content_ref'    => '/eser/mat/scheda-12.pdf',
        ];
    }

    public function markUploaderNotified(int $requestId): void
    {
        $this->marked = true;
    }
}

/**
 * Fase 4 della Notice & Takedown procedure: l'azione admin deve notificare
 * l'uploader e marcare `notified_uploader` solo se la mail parte davvero.
 *
 * Vedi docs/legal/takedown_procedure.md §3 (Fase 4) e §5.2 (template).
 */
final class TakedownUploaderNotificationTest extends TestCase
{
    /** @var list<array{to: string, subj: string, body: string, hdrs: string}> */
    private array $sent = [];

    /** @var array<string, mixed> */
    private array $configPrima = [];
    private string $cartella;
    private string|false $errorLogPrima;

    protected function setUp(): void
    {
        // 23/9/2026 — la casella e la radice vengono dalla configurazione
        // (rilievo A-13): le prove le scrivono, invece di dipendere da quello
        // che c'è nel `.env` di chi le fa girare. Registro delle anomalie ed
        // error_log in una cartella della prova.
        foreach (['mail.abuse_email', 'app.url', 'app.paths.logs'] as $k) {
            $this->configPrima[$k] = Config::get($k);
        }
        $this->cartella = sys_get_temp_dir() . '/pantedu-takedown-' . bin2hex(random_bytes(6));
        mkdir($this->cartella, 0700, true);
        Config::set('app.paths.logs', $this->cartella);
        $this->errorLogPrima = ini_set('error_log', $this->cartella . '/php_errors.log');
        Config::set('mail.abuse_email', 'operatore@example.net');
        Config::set('app.url', 'https://pantedu.eu');
    }

    protected function tearDown(): void
    {
        $_POST = [];
        $_SERVER['REQUEST_METHOD'] = 'GET';
        foreach ($this->configPrima as $k => $v) {
            Config::set($k, $v);
        }
        ini_set('error_log', $this->errorLogPrima === false ? '' : $this->errorLogPrima);
        foreach (glob($this->cartella . '/{,.}*', GLOB_BRACE) ?: [] as $f) {
            if (is_file($f)) {
                @unlink($f);
            }
        }
        @rmdir($this->cartella);
    }

    /** Service senza DB: risponde con una segnalazione fissa. */
    private function fakeService(?array $contact): FakeTakedownRequestService
    {
        return new FakeTakedownRequestService($contact);
    }

    private function fakeMailer(bool $succeeds = true): Mailer
    {
        $this->sent = [];
        return new Mailer(
            'noreply@example.test',
            'Pantedu',
            function (string $to, string $subj, string $body, string $hdrs) use ($succeeds): bool {
                $this->sent[] = compact('to', 'subj', 'body', 'hdrs');
                return $succeeds;
            },
        );
    }

    private function post(string $action, string $notes = 'Violazione art. 70 L.633/1941 accertata.'): Request
    {
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_POST = ['action' => $action, 'notes' => $notes];
        return new Request();
    }

    #[Test]
    public function removal_notifies_uploader_and_marks_the_flag(): void
    {
        $service = $this->fakeService(['email' => 'docente@example.it', 'name' => 'Anna Rossi']);
        $ctrl    = new AdminTakedownController($service, $this->fakeMailer());

        $res = $ctrl->action($this->post('removed'), ['id' => '42']);

        $this->assertCount(1, $this->sent);
        $this->assertSame('docente@example.it', $this->sent[0]['to']);
        $this->assertStringContainsString('Anna Rossi', $this->sent[0]['body']);
        $this->assertStringContainsString('/eser/mat/scheda-12.pdf', $this->sent[0]['body']);
        $this->assertStringContainsString('14 giorni', $this->sent[0]['body']);
        $this->assertTrue($service->marked);
        $this->assertSame('/admin/takedown/42?ok=1', $res->headers['Location']);
    }

    #[Test]
    public function reply_goes_to_the_abuse_mailbox_not_to_noreply(): void
    {
        $ctrl = new AdminTakedownController(
            $this->fakeService(['email' => 'docente@example.it', 'name' => 'Anna']),
            $this->fakeMailer()
        );
        $ctrl->action($this->post('removed'), ['id' => '42']);

        $this->assertStringContainsString("Reply-To: operatore@example.net\r\n", $this->sent[0]['hdrs']);
    }

    #[Test]
    public function dismissal_notifies_without_asking_for_an_appeal(): void
    {
        $ctrl = new AdminTakedownController(
            $this->fakeService(['email' => 'docente@example.it', 'name' => 'Anna']),
            $this->fakeMailer()
        );
        $ctrl->action($this->post('dismissed'), ['id' => '7']);

        $this->assertCount(1, $this->sent);
        $this->assertStringContainsString('infondata', $this->sent[0]['body']);
        $this->assertStringNotContainsString('14 giorni', $this->sent[0]['body']);
    }

    #[Test]
    public function forwarding_to_authority_sends_nothing(): void
    {
        // Avvisare l'uploader mentre il caso è in mano all'autorità può
        // compromettere l'indagine: la comunicazione resta una scelta manuale.
        $service = $this->fakeService(['email' => 'docente@example.it', 'name' => 'Anna']);
        $ctrl    = new AdminTakedownController($service, $this->fakeMailer());

        $res = $ctrl->action($this->post('forwarded_authority'), ['id' => '9']);

        $this->assertCount(0, $this->sent);
        $this->assertFalse($service->marked);
        $this->assertStringContainsString('notice=uploader_not_notified', $res->headers['Location']);
    }

    #[Test]
    public function unknown_uploader_leaves_the_flag_down_and_warns_the_admin(): void
    {
        $service = $this->fakeService(null);
        $ctrl    = new AdminTakedownController($service, $this->fakeMailer());

        $res = $ctrl->action($this->post('removed'), ['id' => '13']);

        $this->assertCount(0, $this->sent);
        $this->assertFalse($service->marked);
        $this->assertStringContainsString('notice=uploader_not_notified', $res->headers['Location']);
    }

    #[Test]
    public function failed_delivery_does_not_mark_the_uploader_as_notified(): void
    {
        $service = $this->fakeService(['email' => 'docente@example.it', 'name' => 'Anna']);
        $ctrl    = new AdminTakedownController($service, $this->fakeMailer(succeeds: false));

        $res = $ctrl->action($this->post('removed'), ['id' => '42']);

        $this->assertCount(1, $this->sent);
        $this->assertFalse($service->marked);
        $this->assertStringContainsString('notice=uploader_not_notified', $res->headers['Location']);
    }

    #[Test]
    public function su_un_altra_istanza_casella_e_collegamenti_sono_i_suoi(): void
    {
        // Fino al 23/9/2026 il Reply-To era abuse@ di produzione, scritto nel
        // controller, e i collegamenti ripiegavano sul dominio di produzione.
        Config::set('mail.abuse_email', 'segnalazioni@scuola.example');
        Config::set('app.url', 'https://scuola.example/');
        $ctrl = new AdminTakedownController(
            $this->fakeService(['email' => 'docente@example.it', 'name' => 'Anna']),
            $this->fakeMailer()
        );
        $ctrl->action($this->post('removed'), ['id' => '42']);

        $this->assertCount(1, $this->sent);
        $this->assertStringContainsString("Reply-To: segnalazioni@scuola.example\r\n", $this->sent[0]['hdrs']);
        $this->assertStringContainsString('(segnalazioni@scuola.example)', $this->sent[0]['body']);
        $this->assertStringContainsString('https://scuola.example/legal/takedown-procedure', $this->sent[0]['body']);
        $this->assertStringNotContainsStringIgnoringCase('pantedu.eu', $this->sent[0]['hdrs'] . $this->sent[0]['body']);
    }

    #[Test]
    public function senza_casella_delle_segnalazioni_la_comunicazione_non_parte(): void
    {
        // Prometterebbe una contestazione «rispondendo a questa email» che non
        // arriverebbe a nessuno: l'amministratore legge che va fatta a mano.
        Config::set('mail.abuse_email', '');
        $service = $this->fakeService(['email' => 'docente@example.it', 'name' => 'Anna']);
        $res = (new AdminTakedownController($service, $this->fakeMailer()))
            ->action($this->post('removed'), ['id' => '42']);

        $this->assertCount(0, $this->sent);
        $this->assertFalse($service->marked);
        $this->assertStringContainsString('notice=uploader_not_notified', $res->headers['Location']);
    }

    #[Test]
    public function senza_app_url_parte_senza_collegamenti_e_l_anomalia_resta(): void
    {
        // La comunicazione è dovuta (Fase 4): i collegamenti alle pagine
        // legali sono di cortesia, e senza radice non c'è ripiego.
        Config::set('app.url', '');
        $service = $this->fakeService(['email' => 'docente@example.it', 'name' => 'Anna']);
        (new AdminTakedownController($service, $this->fakeMailer()))->action($this->post('removed'), ['id' => '42']);

        $this->assertCount(1, $this->sent);
        $this->assertStringNotContainsString('://', $this->sent[0]['body']);
        $this->assertStringContainsString('nelle pagine legali del sito', $this->sent[0]['body']);
        $this->assertTrue($service->marked);
        $anomalie = array_filter(
            \App\Support\Anomalia::recenti(),
            static fn(array $v): bool => ($v['codice'] ?? '') === \App\Support\IndirizzoPubblico::ANOMALIA,
        );
        $this->assertCount(1, $anomalie);
    }

    #[Test]
    public function the_action_is_still_recorded_when_the_mail_cannot_go_out(): void
    {
        $service = $this->fakeService(null);
        $ctrl    = new AdminTakedownController($service, $this->fakeMailer());

        $ctrl->action($this->post('suspended_user'), ['id' => '1']);

        $this->assertSame('actioned', $service->status);
    }
}
