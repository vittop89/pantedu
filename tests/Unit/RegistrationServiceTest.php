<?php

namespace Tests\Unit;

use App\Services\RegistrationService;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class RegistrationServiceTest extends TestCase
{
    private string $sandbox;
    private string $reg;
    private string $usr;
    private RegistrationService $svc;

    protected function setUp(): void
    {
        $this->sandbox = sys_get_temp_dir() . '/pantedu_reg_' . uniqid();
        mkdir($this->sandbox, 0755, true);
        $this->reg = $this->sandbox . '/registrations.json';
        $this->usr = $this->sandbox . '/users.json';
        file_put_contents($this->reg, json_encode(['pending' => [], 'history' => []]));
        file_put_contents($this->usr, json_encode(['users' => []]));
        $this->svc = new RegistrationService($this->reg, $this->usr);
    }

    protected function tearDown(): void
    {
        @unlink($this->reg);
        @unlink($this->usr);
        @rmdir($this->sandbox);
    }

    private function input(array $overrides = []): array
    {
        return array_merge([
            'role'       => 'teacher',
            // Phase 13: teacher richiede ≥1 istituto (institute_ids); senza,
            // RegistrationService lancia institutes_required.
            'institute_ids' => [1],
            // ToS obbligatorio (accept_tos) → altrimenti tos_required.
            'accept_tos'    => true,
            'first_name' => 'Maria',
            'last_name'  => 'Rossi',
            'email'      => 'maria.rossi@example.it',
            'password'   => 'supersecret8',
        ], $overrides);
    }

    #[Test]
    public function submit_creates_pending_entry(): void
    {
        $out = $this->svc->submit($this->input());
        $this->assertSame('pending', $out['status']);
        $this->assertSame('maria.rossi', $out['username']);

        $data = json_decode(file_get_contents($this->reg), true);
        $this->assertCount(1, $data['pending']);
        $this->assertSame('pending', $data['pending'][0]['status']);
        $this->assertStringStartsWith('$2y$', $data['pending'][0]['password_hash']);
    }

    #[Test]
    public function submit_rejects_invalid_role(): void
    {
        $this->expectException(RuntimeException::class);
        $this->svc->submit($this->input(['role' => 'administrator']));
    }

    #[Test]
    public function submit_rejects_bad_email(): void
    {
        $this->expectException(RuntimeException::class);
        $this->svc->submit($this->input(['email' => 'not-an-email']));
    }

    #[Test]
    public function submit_rejects_short_password(): void
    {
        $this->expectException(RuntimeException::class);
        $this->svc->submit($this->input(['password' => 'short']));
    }

    #[Test]
    public function submit_rejects_duplicate_email(): void
    {
        $this->svc->submit($this->input());
        $this->expectException(RuntimeException::class);
        $this->svc->submit($this->input(['first_name' => 'Mario']));
    }

    #[Test]
    public function submit_disambiguates_username_on_collision(): void
    {
        $this->svc->submit($this->input());
        $out = $this->svc->submit($this->input([
            'email' => 'maria.rossi2@example.it',
        ]));
        $this->assertSame('maria.rossi2', $out['username']);
    }

    #[Test]
    public function approve_moves_to_users_and_activates(): void
    {
        $submit = $this->svc->submit($this->input());
        $pending = $this->svc->pending();
        $this->assertCount(1, $pending);
        $id = $pending[0]['id'];

        $res = $this->svc->approve($id, 'admin');
        $this->assertTrue($res['ok']);
        $this->assertSame($submit['username'], $res['username']);

        $users = json_decode(file_get_contents($this->usr), true)['users'];
        $this->assertCount(1, $users);
        $this->assertSame('approved', $users[0]['status']);
        $this->assertTrue($users[0]['active']);
        $this->assertSame('teacher', $users[0]['role']);

        $history = json_decode(file_get_contents($this->reg), true)['history'];
        $this->assertCount(1, $history);
        $this->assertSame('approved', $history[0]['action']);
        $this->assertSame('admin', $history[0]['actor']);
        $this->assertCount(0, $this->svc->pending());
    }

    #[Test]
    public function reject_records_history_without_activation(): void
    {
        $this->svc->submit($this->input());
        $id = $this->svc->pending()[0]['id'];

        $res = $this->svc->reject($id, 'admin', 'duplicate');
        $this->assertTrue($res['ok']);
        $this->assertCount(0, $this->svc->pending());

        $users = json_decode(file_get_contents($this->usr), true)['users'];
        $this->assertCount(0, $users);

        $history = json_decode(file_get_contents($this->reg), true)['history'];
        $this->assertSame('rejected', $history[0]['action']);
        $this->assertSame('duplicate', $history[0]['reason']);
    }

    #[Test]
    public function approve_unknown_id_throws(): void
    {
        $this->expectException(RuntimeException::class);
        $this->svc->approve('does-not-exist', 'admin');
    }

    #[Test]
    public function pending_hides_password_hash(): void
    {
        $this->svc->submit($this->input());
        $pending = $this->svc->pending();
        $this->assertArrayNotHasKey('password_hash', $pending[0]);
    }
}
