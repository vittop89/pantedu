<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Core\Config;
use App\Core\Request;
use App\Core\Response;
use App\Middleware\TosAcceptanceMiddleware;
use App\Support\TosEnforcement;
use App\Services\Gdpr\TosAcceptanceService;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

/**
 * Il middleware è applicato globalmente da Kernel::handle(): un errore qui
 * blocca l'intero sito o, al contrario, non blocca niente. Entrambe le
 * direzioni vanno coperte.
 */
final class TosAcceptanceMiddlewareTest extends TestCase
{
    /** @var mixed valore originale del flag, ripristinato in tearDown */
    private mixed $originalFlag = null;

    protected function setUp(): void
    {
        $_SESSION = [];
        $this->originalFlag = Config::get('multitenancy.tos_enforce');
        TosEnforcement::resetCache();
    }

    protected function tearDown(): void
    {
        $this->setEnforce((bool)$this->originalFlag);
        TosEnforcement::resetCache();
        $_SESSION = [];
    }

    /**
     * Il gate legge TosEnforcement, che a sua volta ricade su questa config
     * quando non c'è override runtime. Config non ha setter: il valore è
     * valutato al load da app/Config, quindi si scrive nell'indice.
     */
    private function setEnforce(bool|string $on): void
    {
        $prop = new ReflectionProperty(Config::class, 'items');
        $items = $prop->getValue();
        $items['multitenancy']['tos_enforce'] = $on;
        $prop->setValue(null, $items);
        TosEnforcement::resetCache();
    }

    private function mkReq(string $path, bool $wantsJson = false): Request
    {
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['REQUEST_URI']    = $path;
        foreach (array_keys($_SERVER) as $k) {
            if (str_starts_with($k, 'HTTP_')) {
                unset($_SERVER[$k]);
            }
        }
        if ($wantsJson) {
            $_SERVER['HTTP_ACCEPT'] = 'application/json';
        }
        $_GET = [];
        $_POST = [];
        return new Request();
    }

    private function login(int $userId = 7, bool $superAdmin = false): void
    {
        $_SESSION['autenticato']    = true;
        $_SESSION['username']       = 'docente';
        $_SESSION['user_id']        = $userId;
        $_SESSION['user_role']      = 'teacher';
        $_SESSION['is_super_admin'] = $superAdmin;
    }

    private function service(bool $accepted): TosAcceptanceService
    {
        $mock = $this->createMock(TosAcceptanceService::class);
        $mock->method('hasAccepted')->willReturn($accepted);
        $mock->method('getCurrentTosVersion')->willReturn('1.0');
        $mock->method('getCurrentAupVersion')->willReturn('1.1');
        return $mock;
    }

    private function passthrough(): callable
    {
        return static fn($r) => Response::html('CONTENUTO');
    }

    // -----------------------------------------------------------------

    #[Test]
    public function flag_off_lets_everything_through(): void
    {
        $this->setEnforce(false);
        $this->login();

        $mw = new TosAcceptanceMiddleware($this->service(false));
        $res = $mw->handle($this->mkReq('/area-docente'), $this->passthrough());

        $this->assertSame(200, $res->status);
        $this->assertStringContainsString('CONTENUTO', (string)$res->body);
    }

    #[Test]
    public function string_false_from_env_does_not_enable_enforcement(): void
    {
        // Il bug originale: (bool)'false' === true, cioè il flag si accendeva
        // proprio scrivendo TOS_ENFORCE=false.
        $this->setEnforce('false');

        $this->login();
        $mw = new TosAcceptanceMiddleware($this->service(false));
        $res = $mw->handle($this->mkReq('/area-docente'), $this->passthrough());

        $this->assertSame(200, $res->status);
    }

    #[Test]
    public function unauthenticated_request_is_left_to_auth_middleware(): void
    {
        $this->setEnforce(true);

        $mw = new TosAcceptanceMiddleware($this->service(false));
        $res = $mw->handle($this->mkReq('/area-docente'), $this->passthrough());

        $this->assertSame(200, $res->status);
    }

    #[Test]
    public function pending_user_is_redirected_to_the_form(): void
    {
        $this->setEnforce(true);
        $this->login();

        $mw = new TosAcceptanceMiddleware($this->service(false));
        $res = $mw->handle($this->mkReq('/area-docente'), $this->passthrough());

        $this->assertSame(302, $res->status);
        $this->assertStringContainsString('/tos-acceptance', $res->headers['Location'] ?? '');
        $this->assertStringNotContainsString('CONTENUTO', (string)$res->body);
    }

    #[Test]
    public function query_string_is_stripped_before_matching_exempt_paths(): void
    {
        $this->setEnforce(true);
        $this->login();

        $mw = new TosAcceptanceMiddleware($this->service(false));
        $res = $mw->handle($this->mkReq('/logout?from=%2Fx'), $this->passthrough());

        $this->assertSame(200, $res->status);
    }

    #[Test]
    public function accepted_user_passes(): void
    {
        $this->setEnforce(true);
        $this->login();

        $mw = new TosAcceptanceMiddleware($this->service(true));
        $res = $mw->handle($this->mkReq('/area-docente'), $this->passthrough());

        $this->assertSame(200, $res->status);
    }

    #[Test]
    public function super_admin_is_never_locked_out(): void
    {
        $this->setEnforce(true);
        $this->login(superAdmin: true);

        $mw = new TosAcceptanceMiddleware($this->service(false));
        $res = $mw->handle($this->mkReq('/admin/tos-log'), $this->passthrough());

        $this->assertSame(200, $res->status);
    }

    /**
     * Il caso che rendeva il consenso invalido: l'utente fermo al muro non
     * poteva aprire i documenti che gli si chiedeva di accettare.
     */
    #[Test]
    public function legal_documents_stay_reachable_from_behind_the_gate(): void
    {
        $this->setEnforce(true);
        $this->login();

        foreach (['/legal/aup', '/legal/tos', '/privacy/informativa', '/tos-acceptance'] as $path) {
            $mw = new TosAcceptanceMiddleware($this->service(false));
            $res = $mw->handle($this->mkReq($path), $this->passthrough());
            $this->assertSame(200, $res->status, "$path deve restare raggiungibile");
        }
    }

    #[Test]
    public function json_client_gets_403_instead_of_a_redirect(): void
    {
        $this->setEnforce(true);
        $this->login();

        $mw = new TosAcceptanceMiddleware($this->service(false));
        $res = $mw->handle($this->mkReq('/api/contents', true), $this->passthrough());

        $this->assertSame(403, $res->status);
        $this->assertStringContainsString('tos_acceptance_required', (string)$res->body);
    }
}
