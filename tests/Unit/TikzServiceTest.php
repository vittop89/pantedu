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
}
