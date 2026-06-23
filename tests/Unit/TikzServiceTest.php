<?php

namespace Tests\Unit;

use App\Services\FileService;
use App\Services\TikzService;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class TikzServiceTest extends TestCase
{
    private string $sandbox;
    private TikzService $svc;

    protected function setUp(): void
    {
        $this->sandbox = sys_get_temp_dir() . '/pantedu_tikz_' . uniqid();
        mkdir($this->sandbox . '/eser/ar/eser_ar5s/MAT', 0755, true);

        $modelli = <<<'HTML'
<!DOCTYPE html>
<html><body>
<div class="tex-group" data-group="base">
    <div class="element-tex">
        <div class="label_tikz">Retta</div>
        <div><script type="text/tikz" id="e-1">\draw (0,0) -- (1,1);</script></div>
    </div>
    <div class="element-tex">
        <div class="label_latex">Formula</div>
        <div class="latex">x^2 + y^2 = r^2</div>
    </div>
</div>
<div class="tex-group" data-group="avanzate">
    <div class="element-tex">
        <div class="label_tikz">Parabola</div>
        <div><script type="text/tikz" id="e-2">\draw plot (\x,\x*\x);</script></div>
    </div>
</div>
</body></html>
HTML;
        file_put_contents($this->sandbox . '/modelli_tikz.php', $modelli);

        $files = new FileService([
            'roots' => ['eser' => $this->sandbox . '/eser'],
            'allowed_extensions' => [],
            'max_sizes' => [],
        ]);
        $this->svc = new TikzService($files, basePath: $this->sandbox);
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

    #[Test]
    public function save_svg_into_nested_folder(): void
    {
        $out = $this->svc->saveSvg(
            '/eser/ar/eser_ar5s/MAT/2.0_MAT-Limiti.php',
            'svg/MAT-limiti-svg',
            'tikz-123.svg',
            '<svg/>'
        );
        $this->assertStringContainsString('tikz-123.svg', $out['path']);
        $this->assertSame(6, $out['size']);
        $this->assertFileExists($this->sandbox . '/eser/ar/eser_ar5s/MAT/svg/MAT-limiti-svg/tikz-123.svg');
    }

    #[Test]
    public function save_svg_rejects_outside_allowed_roots(): void
    {
        $this->expectException(RuntimeException::class);
        $this->svc->saveSvg('/etc/passwd', 'svg', 'x.svg', '<svg/>');
    }

    #[Test]
    public function save_svg_rejects_bad_extension(): void
    {
        $this->expectException(RuntimeException::class);
        $this->svc->saveSvg(
            '/eser/ar/eser_ar5s/MAT/2.0_MAT-Limiti.php',
            'svg', 'shell.php', '<?php'
        );
    }

    #[Test]
    public function save_svg_rejects_traversal_in_folder_name(): void
    {
        $this->expectException(RuntimeException::class);
        $this->svc->saveSvg(
            '/eser/ar/eser_ar5s/MAT/2.0_MAT-Limiti.php',
            '../../../etc', 'x.svg', '<svg/>'
        );
    }

    #[Test]
    public function get_content_returns_tikz_script(): void
    {
        $code = $this->svc->getContent('modelli_tikz.php', 'base', 0);
        $this->assertStringContainsString('\draw (0,0)', $code);
    }

    #[Test]
    public function get_content_unknown_group(): void
    {
        $this->expectException(RuntimeException::class);
        $this->svc->getContent('modelli_tikz.php', 'ghost', 0);
    }

    #[Test]
    public function get_content_rejects_non_modelli_file(): void
    {
        $this->expectException(RuntimeException::class);
        $this->svc->getContent('etc/passwd', 'base', 0);
    }

    #[Test]
    public function ensure_json_regenerates_when_missing(): void
    {
        $result = $this->svc->ensureJson('modelli_tikz.php', 'modelli_tikz.json', force: false);
        $this->assertTrue($result['regenerated']);
        $this->assertFileExists($this->sandbox . '/modelli_tikz.json');

        $json = json_decode(file_get_contents($this->sandbox . '/modelli_tikz.json'), true);
        $this->assertArrayHasKey('base', $json);
        $this->assertArrayHasKey('avanzate', $json);
        $this->assertSame('Retta', $json['base'][0]['label']);
    }

    #[Test]
    public function ensure_json_skips_when_up_to_date(): void
    {
        // First run creates json
        $this->svc->ensureJson('modelli_tikz.php', 'modelli_tikz.json', false);
        // Touch json to be newer
        touch($this->sandbox . '/modelli_tikz.json', time() + 10);
        $result = $this->svc->ensureJson('modelli_tikz.php', 'modelli_tikz.json', false);
        $this->assertFalse($result['regenerated']);
    }

    #[Test]
    public function ensure_json_force_regenerates(): void
    {
        $this->svc->ensureJson('modelli_tikz.php', 'modelli_tikz.json', false);
        $result = $this->svc->ensureJson('modelli_tikz.php', 'modelli_tikz.json', force: true);
        $this->assertTrue($result['regenerated']);
        $this->assertSame('forced', $result['reason']);
    }
}
