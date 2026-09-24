<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Controllers\CspReportController;
use App\Core\Config;
use App\Core\Request;
use App\Support\ImprontaIp;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * POST /api/csp-report (2026-09-05): il report del browser finisce in un
 * NDJSON sotto <app.paths.logs>/csp-reports, tutto il resto è un 400.
 */
final class CspReportTest extends TestCase
{
    private string $logsDir;
    private string $prevLogsPath;

    protected function setUp(): void
    {
        $_SESSION = [];
        $this->logsDir = sys_get_temp_dir() . '/pantedu_csp_' . uniqid();
        mkdir($this->logsDir, 0755, true);
        $this->prevLogsPath = (string)$this->configItems()['app']['paths']['logs'];
        $this->setLogsPath($this->logsDir);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->logsDir . '/csp-reports/*') ?: [] as $f) {
            @unlink($f);
        }
        @rmdir($this->logsDir . '/csp-reports');
        @rmdir($this->logsDir);
        $this->setLogsPath($this->prevLogsPath);
    }

    private function configItems(): array
    {
        $ref = new \ReflectionClass(Config::class);
        $p   = $ref->getProperty('items');
        $p->setAccessible(true);
        return $p->getValue() ?: [];
    }

    private function setLogsPath(string $path): void
    {
        $items = $this->configItems();
        $items['app']['paths']['logs'] = $path;
        $ref = new \ReflectionClass(Config::class);
        $p   = $ref->getProperty('items');
        $p->setAccessible(true);
        $p->setValue(null, $items);
    }

    private function post(string $raw): Request
    {
        $_SERVER['REQUEST_METHOD']  = 'POST';
        $_SERVER['REQUEST_URI']     = '/api/csp-report';
        $_SERVER['REMOTE_ADDR']     = '203.0.113.7';
        $_SERVER['HTTP_USER_AGENT'] = 'PHPUnit';
        return new Request($raw);
    }

    private function todayFile(): string
    {
        return $this->logsDir . '/csp-reports/' . date('Y-m-d') . '.ndjson';
    }

    #[Test]
    public function report_uri_format_is_appended_as_one_ndjson_line(): void
    {
        $raw = json_encode(['csp-report' => [
            'document-uri'       => 'https://example.test/studio/esercizio/SCI/1/MAT/4.0',
            'violated-directive' => 'script-src',
            'effective-directive' => 'script-src-elem',
            'blocked-uri'        => 'inline',
            'line-number'        => 42,
            'original-policy'    => str_repeat('x', 4000),
        ]]);

        $res = (new CspReportController())->collect($this->post($raw));

        self::assertSame(204, $res->status);
        self::assertSame('', $res->body);
        self::assertFileExists($this->todayFile());
        $lines = file($this->todayFile(), FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        self::assertCount(1, $lines);
        $entry = json_decode($lines[0], true);
        self::assertSame('script-src', $entry['violated-directive']);
        self::assertSame('inline', $entry['blocked-uri']);
        self::assertSame('42', $entry['line-number']);
        self::assertArrayNotHasKey('original-policy', $entry, 'la policy intera non si salva');
        self::assertSame('PHPUnit', $entry['ua']);
        self::assertSame(16, strlen($entry['iph']));
        self::assertStringNotContainsString('203.0.113.7', $lines[0]);
        // L'impronta del giorno ha la chiave (dal 24/9/2026): la formula di
        // prima, SHA-256 di «IP|data», si ricalcolava provando gli indirizzi.
        self::assertSame(ImprontaIp::delGiorno('203.0.113.7', date('Y-m-d')), $entry['iph']);
        self::assertNotSame(substr(hash('sha256', '203.0.113.7|' . date('Y-m-d')), 0, 16), $entry['iph']);
    }

    #[Test]
    public function report_to_format_is_accepted_too(): void
    {
        $raw = json_encode([[
            'type' => 'csp-violation',
            'body' => ['documentURL' => 'https://example.test/', 'effectiveDirective' => 'style-src-elem', 'blockedURL' => 'inline'],
        ]]);

        $res = (new CspReportController())->collect($this->post($raw));

        self::assertSame(204, $res->status);
        $entry = json_decode((string)file_get_contents($this->todayFile()), true);
        self::assertSame('style-src-elem', $entry['effectiveDirective']);
    }

    #[Test]
    public function garbage_is_refused_and_nothing_is_written(): void
    {
        $controller = new CspReportController();

        self::assertSame(400, $controller->collect($this->post(''))->status);
        self::assertSame(400, $controller->collect($this->post('non json'))->status);
        self::assertSame(400, $controller->collect($this->post('{"altro":1}'))->status);
        self::assertSame(400, $controller->collect($this->post('{"csp-report":{"nessun-campo-noto":1}}'))->status);
        self::assertSame(400, $controller->collect($this->post(str_repeat('a', 20000)))->status);
        self::assertFileDoesNotExist($this->todayFile());
    }
}
