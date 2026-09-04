<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Gdpr;

use App\Controllers\Admin\AdminTakedownController;
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

    public function get(int $requestId): ?array
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

    protected function tearDown(): void
    {
        $_POST = [];
        $_SERVER['REQUEST_METHOD'] = 'GET';
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
            'operatore@example.net',
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
    public function the_action_is_still_recorded_when_the_mail_cannot_go_out(): void
    {
        $service = $this->fakeService(null);
        $ctrl    = new AdminTakedownController($service, $this->fakeMailer());

        $ctrl->action($this->post('suspended_user'), ['id' => '1']);

        $this->assertSame('actioned', $service->status);
    }
}
