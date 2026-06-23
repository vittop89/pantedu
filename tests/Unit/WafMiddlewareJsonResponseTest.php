<?php

namespace Tests\Unit;

use App\Core\Response;
use App\Middleware\WafMiddleware;
use App\Services\Waf\WafConfigRepository;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * WAF — formato risposta per richieste XHR/JSON (Phase 25.J.2).
 *
 * Bug originario: il WAF serviva la pagina di challenge HTML (fingerprint/PoW)
 * anche alle fetch JSON → il client faceva `.json()` su "<!doctype …>" e
 * crashava con "Unexpected token '<'". La challenge HTML è risolvibile solo
 * da una navigazione full-page, mai da una fetch.
 *
 * Garanzia di sicurezza: la DECISIONE del WAF non cambia (challenge resta
 * challenge, block resta block, sempre 403); cambia solo il FORMATO della
 * risposta per le richieste JSON. Questi test bloccano la regressione.
 *
 * Testiamo il branching format-only via reflection (security-critical, no DB):
 *  - respondChallenge() con expectsJson=true ritorna early in JSON.
 *  - enforceBlock() non usa config → entrambe le forme testabili senza DB.
 */
final class WafMiddlewareJsonResponseTest extends TestCase
{
    private function mw(): WafMiddleware
    {
        return new WafMiddleware();
    }

    private function setExpectsJson(WafMiddleware $mw, bool $v): void
    {
        $ref = new ReflectionClass($mw);
        $prop = $ref->getProperty('expectsJson');
        $prop->setAccessible(true);
        $prop->setValue($mw, $v);
    }

    private function call(WafMiddleware $mw, string $method, array $args): Response
    {
        $ref = new ReflectionClass($mw);
        $m = $ref->getMethod($method);
        $m->setAccessible(true);
        return $m->invokeArgs($mw, $args);
    }

    #[Test]
    public function challenge_for_json_request_is_json_not_html(): void
    {
        $mw = $this->mw();
        $this->setExpectsJson($mw, true);
        // expectsJson=true → ritorna PRIMA di toccare la config (nessun DB).
        $res = $this->call($mw, 'respondChallenge', ['invisible', new WafConfigRepository()]);

        $this->assertSame(403, $res->status);
        $this->assertStringContainsString('application/json', $res->headers['Content-Type'] ?? '');
        $this->assertStringNotContainsString('<!doctype', strtolower($res->body));
        $data = json_decode($res->body, true);
        $this->assertSame('waf_challenge', $data['code'] ?? null);
        $this->assertTrue($data['reload'] ?? false);
    }

    #[Test]
    public function block_for_json_request_is_json_not_html(): void
    {
        $mw = $this->mw();
        $this->setExpectsJson($mw, true);
        $res = $this->call($mw, 'enforceBlock', ['enforce', 'Manual IP block']);

        $this->assertSame(403, $res->status);
        $this->assertStringContainsString('application/json', $res->headers['Content-Type'] ?? '');
        $this->assertStringNotContainsString('<!doctype', strtolower($res->body));
        $data = json_decode($res->body, true);
        $this->assertSame('request_blocked', $data['error'] ?? null);
    }

    #[Test]
    public function block_for_page_request_stays_html(): void
    {
        $mw = $this->mw();
        $this->setExpectsJson($mw, false);
        $res = $this->call($mw, 'enforceBlock', ['enforce', 'Manual IP block']);

        $this->assertSame(403, $res->status);
        $this->assertStringContainsString('text/html', $res->headers['Content-Type'] ?? '');
        $this->assertStringContainsString('<!doctype', strtolower($res->body));
    }
}
