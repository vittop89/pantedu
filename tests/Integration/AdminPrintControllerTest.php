<?php

namespace Tests\Integration;

use App\Controllers\AdminPrintController;
use App\Core\Request;
use App\Services\FileService;
use App\Services\OwnershipService;
use App\Services\TexBuilder;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class AdminPrintControllerTest extends TestCase
{
    private string $sandbox;
    private FileService $files;
    private OwnershipService $owners;

    protected function setUp(): void
    {
        $_SESSION = [];
        $this->sandbox = sys_get_temp_dir() . '/pantedu_adprint_' . uniqid();
        mkdir($this->sandbox . '/storage_temp', 0755, true);

        $this->files = new FileService([
            'roots' => ['storage_temp' => $this->sandbox . '/storage_temp'],
            'allowed_extensions' => ['tex' => ['tex'], 'any' => ['tex']],
            'max_sizes' => ['tex' => 5 * 1024 * 1024],
        ]);
        $this->owners = new OwnershipService($this->sandbox . '/ownership.json');
    }

    protected function tearDown(): void
    {
        $this->rm($this->sandbox);
    }

    private function rm(string $p): void
    {
        if (!file_exists($p)) return;
        if (is_dir($p)) {
            foreach (scandir($p) ?: [] as $e) {
                if ($e === '.' || $e === '..') continue;
                $this->rm($p . '/' . $e);
            }
            @rmdir($p);
        } else { @unlink($p); }
    }

    private function controller(): AdminPrintController
    {
        return new AdminPrintController(new TexBuilder(), $this->files, $this->owners);
    }

    private function asAdmin(): void
    {
        $_SESSION['autenticato'] = true;
        $_SESSION['username']    = 'admin';
        $_SESSION['user_role']   = 'administrator';
    }

    private function asTeacher(): void
    {
        $_SESSION['autenticato'] = true;
        $_SESSION['username']    = 'mario';
        $_SESSION['user_role']   = 'teacher';
    }

    private function request(string $jsonBody): Request
    {
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_SERVER['REQUEST_URI']    = '/admin/print';
        $_POST = ['selection' => $jsonBody];
        return new Request();
    }

    private function samplePayload(array $overrides = []): string
    {
        return json_encode(array_merge([
            'version'       => 'A',
            'verTitle'      => 'AdminTest',
            'selectedIIS'   => 'ar', 'selectedCLS' => '2s', 'selectedMATER' => 'MAT',
            'anno'          => '2026', 'sezione' => 'NOR',
            'problems'      => [[
                'filePath'  => '/x.php', 'problemId' => 'p1', 'position' => 1,
                'text'      => 'T', 'items' => [['html' => 'q', 'points' => 1.0]],
            ]],
        ], $overrides));
    }

    #[Test]
    public function teacher_cannot_use_admin_print(): void
    {
        $this->asTeacher();
        $res = $this->controller()->generate($this->request($this->samplePayload()));
        $this->assertSame(400, $res->status);
        $this->assertStringContainsString('forbidden', $res->body);
    }

    #[Test]
    public function admin_generates_single_variant(): void
    {
        $this->asAdmin();
        $res = $this->controller()->generate($this->request($this->samplePayload()));
        $this->assertSame(200, $res->status);
        $this->assertStringContainsString('application/x-tex', $res->headers['Content-Type']);
        $this->assertStringContainsString('AdminTest',         $res->body);
        $this->assertStringContainsString('\documentclass',    $res->body);
    }

    #[Test]
    public function admin_batch_returns_zip_with_3_variants(): void
    {
        if (!\class_exists(\ZipArchive::class)) {
            $this->markTestSkipped('ZipArchive non disponibile');
        }
        $this->asAdmin();
        $res = $this->controller()->batch($this->request($this->samplePayload()));
        $this->assertSame(200, $res->status);
        $this->assertStringContainsString('application/zip', $res->headers['Content-Type']);

        $tmp = tempnam(sys_get_temp_dir(), 'zchk_') . '.zip';
        file_put_contents($tmp, $res->body);
        $zip = new \ZipArchive();
        $zip->open($tmp);
        $this->assertSame(3, $zip->count());
        $names = [];
        for ($i = 0; $i < $zip->count(); $i++) $names[] = $zip->getNameIndex($i);
        $joined = implode(' ', $names);
        $this->assertStringContainsString('_normal.tex',   $joined);
        $this->assertStringContainsString('_dsa.tex',      $joined);
        $this->assertStringContainsString('_dyslexic.tex', $joined);
        $zip->close();
        @unlink($tmp);
    }

    #[Test]
    public function admin_ownership_recorded(): void
    {
        $this->asAdmin();
        $this->controller()->generate($this->request($this->samplePayload()));
        $owned = $this->owners->listFor('admin');
        $this->assertCount(1, $owned['verifiche']);
        $this->assertStringContainsString('/admin/tex/', $owned['verifiche'][0]);
    }

    #[Test]
    public function invalid_json_returns_400(): void
    {
        $this->asAdmin();
        $res = $this->controller()->generate($this->request('notjson'));
        $this->assertSame(400, $res->status);
    }

    #[Test]
    public function rate_limit_at_20(): void
    {
        $this->asAdmin();
        for ($i = 0; $i < 20; $i++) {
            $r = $this->controller()->generate($this->request($this->samplePayload()));
            $this->assertSame(200, $r->status, "call $i ok");
        }
        $blocked = $this->controller()->generate($this->request($this->samplePayload()));
        $this->assertSame(429, $blocked->status);
    }
}
