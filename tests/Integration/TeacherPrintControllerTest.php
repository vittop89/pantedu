<?php

namespace Tests\Integration;

use App\Controllers\TeacherPrintController;
use App\Core\Config as AppConfig;
use App\Core\Request;
use App\Services\FileService;
use App\Services\OwnershipService;
use App\Services\TexBuilder;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class TeacherPrintControllerTest extends TestCase
{
    private string $sandbox;
    private FileService $files;
    private OwnershipService $owners;

    protected function setUp(): void
    {
        $_SESSION = [];
        $this->sandbox = sys_get_temp_dir() . '/pantedu_print_' . uniqid();
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

    private function controller(): TeacherPrintController
    {
        return new TeacherPrintController(new TexBuilder(), $this->files, $this->owners);
    }

    private function request(string $jsonBody = ''): Request
    {
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_SERVER['REQUEST_URI']    = '/teacher/print';
        $_POST = $jsonBody !== '' ? ['selection' => $jsonBody] : [];
        return new Request();
    }

    private function asTeacher(string $username = 'mariarossi'): void
    {
        $_SESSION['autenticato'] = true;
        $_SESSION['username']    = $username;
        $_SESSION['user_role']   = 'teacher';
    }

    private function samplePayload(array $overrides = []): string
    {
        return json_encode(array_merge([
            'version'       => 'A',
            'verTitle'      => 'Verifica Test',
            'selectedIIS'   => 'ar',
            'selectedCLS'   => '2s',
            'selectedMATER' => 'MAT',
            'anno'          => '2026',
            'sezione'       => 'NOR',
            'variant'       => 'normal',
            'problems'      => [
                [
                    'filePath'  => '/eser/ar/eser_ar2s/MAT/foo.php',
                    'problemId' => 'p-1',
                    'position'  => 1,
                    'text'      => 'Esempio',
                    'items'     => [['html' => 'item 1', 'points' => 1.0, 'includeSolution' => false]],
                ],
            ],
        ], $overrides));
    }

    #[Test]
    public function unauthenticated_returns_401(): void
    {
        $res = $this->controller()->generate($this->request($this->samplePayload()));
        $this->assertSame(401, $res->status);
    }

    #[Test]
    public function teacher_receives_tex_file_with_attachment_header(): void
    {
        $this->asTeacher();
        $res = $this->controller()->generate($this->request($this->samplePayload()));
        $this->assertSame(200, $res->status);
        $this->assertStringContainsString('application/x-tex', $res->headers['Content-Type']);
        $this->assertStringContainsString('attachment',        $res->headers['Content-Disposition']);
        $this->assertStringContainsString('.tex',              $res->headers['Content-Disposition']);
        $this->assertStringContainsString('\\documentclass',   $res->body);
        $this->assertStringContainsString('Verifica Test',     $res->body);
    }

    #[Test]
    public function ownership_is_recorded_after_print(): void
    {
        $this->asTeacher('annab');
        $this->controller()->generate($this->request($this->samplePayload()));
        $owned = $this->owners->listFor('annab');
        $this->assertCount(1, $owned['verifiche']);
        $this->assertStringStartsWith('/teachers/annab/tex/', $owned['verifiche'][0]);
        $this->assertStringEndsWith('.tex',                   $owned['verifiche'][0]);
    }

    #[Test]
    public function file_is_actually_written_to_storage(): void
    {
        $this->asTeacher();
        $res = $this->controller()->generate($this->request($this->samplePayload()));
        $relative = $res->headers['X-Saved-Path'] ?? '';
        $this->assertNotEmpty($relative);
        $abs = $this->sandbox . '/storage_temp/' . ltrim(str_replace('/teachers/', 'teachers/', $relative), '/');
        $this->assertFileExists($abs);
    }

    #[Test]
    public function invalid_json_payload_returns_400(): void
    {
        $this->asTeacher();
        $res = $this->controller()->generate($this->request('not-a-json'));
        $this->assertSame(400, $res->status);
    }

    #[Test]
    public function missing_verTitle_returns_400(): void
    {
        $this->asTeacher();
        $res = $this->controller()->generate($this->request($this->samplePayload(['verTitle' => ''])));
        $this->assertSame(400, $res->status);
    }

    #[Test]
    public function dsa_variant_produces_dsa_preamble(): void
    {
        $this->asTeacher();
        $res = $this->controller()->generate($this->request($this->samplePayload(['variant' => 'dsa'])));
        $this->assertSame(200, $res->status);
        $this->assertStringContainsString('baselinestretch', $res->body);
        $this->assertStringContainsString('[DSA]',           $res->body);
    }

    #[Test]
    public function rate_limit_blocks_after_10_calls(): void
    {
        $this->asTeacher();
        for ($i = 0; $i < 10; $i++) {
            $r = $this->controller()->generate($this->request($this->samplePayload()));
            $this->assertSame(200, $r->status, "call $i should succeed");
        }
        // 11th: limiter blocks
        $blocked = $this->controller()->generate($this->request($this->samplePayload()));
        $this->assertSame(429, $blocked->status);
    }
}
