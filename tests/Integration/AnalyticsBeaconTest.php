<?php

namespace Tests\Integration;

use App\Controllers\AnalyticsController;
use App\Core\Config;
use App\Core\Request;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class AnalyticsBeaconTest extends TestCase
{
    private string $logsDir;
    private string $prevLogsPath;

    protected function setUp(): void
    {
        $_SESSION = [];
        $this->logsDir = sys_get_temp_dir() . '/pantedu_analytics_' . uniqid();
        mkdir($this->logsDir, 0755, true);
        // redirect AccessLogger through config for this test
        $ref = new \ReflectionClass(Config::class);
        $p   = $ref->getProperty('items'); $p->setAccessible(true);
        $items = $p->getValue() ?: [];
        $this->prevLogsPath = $items['app']['paths']['logs'] ?? '';
        $items['app']['paths']['logs'] = $this->logsDir;
        $p->setValue(null, $items);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->logsDir . '/*') ?: [] as $f) @unlink($f);
        @rmdir($this->logsDir);
        // restore
        $ref = new \ReflectionClass(Config::class);
        $p   = $ref->getProperty('items'); $p->setAccessible(true);
        $items = $p->getValue() ?: [];
        $items['app']['paths']['logs'] = $this->prevLogsPath;
        $p->setValue(null, $items);
    }

    private function request(array $post): Request
    {
        $_POST            = $post;
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_SERVER['REQUEST_URI']    = '/analytics/nav';
        return new Request();
    }

    #[Test]
    public function skips_beacon_for_unauthenticated(): void
    {
        $res = (new AnalyticsController())->navBeacon($this->request(['url' => '/eser/x']));
        $body = json_decode($res->body, true);
        $this->assertSame(['ok' => true, 'skipped' => 'anon'], $body);
        $this->assertSame(0, count(glob($this->logsDir . '/access_log.json') ?: []));
    }

    #[Test]
    public function rejects_missing_url(): void
    {
        $_SESSION['autenticato'] = true;
        $_SESSION['username']    = 'u';
        $_SESSION['user_role']   = 'student';

        $res = (new AnalyticsController())->navBeacon($this->request(['url' => '']));
        $this->assertSame(400, $res->status);
    }

    #[Test]
    public function logs_authenticated_navigation(): void
    {
        $_SESSION['autenticato'] = true;
        $_SESSION['username']    = 'anna';
        $_SESSION['user_role']   = 'teacher';

        $res = (new AnalyticsController())->navBeacon(
            $this->request(['url' => 'http://example.com/eser/ar/eser_ar5s/MAT/1.0_MAT-Limiti.php'])
        );
        $this->assertSame(200, $res->status);

        $logFile = $this->logsDir . '/access_log.json';
        $this->assertFileExists($logFile);
        $entries = json_decode((string)file_get_contents($logFile), true);
        $this->assertIsArray($entries);
        $this->assertCount(1, $entries);
        $this->assertSame('anna',    $entries[0]['username']);
        $this->assertSame('spa_nav', $entries[0]['action']);
        $this->assertStringContainsString('/eser/ar/eser_ar5s/MAT/', $entries[0]['linkref']);
    }
}
