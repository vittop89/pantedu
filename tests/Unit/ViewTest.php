<?php

namespace Tests\Unit;

use App\Core\View;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class ViewTest extends TestCase
{
    private string $dir;
    private View $view;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/pantedu_view_' . uniqid();
        mkdir($this->dir . '/pages', 0755, true);
        file_put_contents($this->dir . '/pages/hello.php',
            '<p>Ciao <?= e($name) ?>!</p>'
        );
        file_put_contents($this->dir . '/pages/layout.php',
            '<main><?= $body ?></main>'
        );
        $this->view = new View($this->dir);
    }

    protected function tearDown(): void
    {
        @unlink($this->dir . '/pages/hello.php');
        @unlink($this->dir . '/pages/layout.php');
        @rmdir($this->dir . '/pages');
        @rmdir($this->dir);
    }

    #[Test]
    public function renders_template_with_data(): void
    {
        $out = $this->view->render('pages/hello', ['name' => 'Mario']);
        $this->assertSame('<p>Ciao Mario!</p>', $out);
    }

    #[Test]
    public function escapes_via_e_helper(): void
    {
        $out = $this->view->render('pages/hello', ['name' => '<script>x</script>']);
        $this->assertStringContainsString('&lt;script&gt;', $out);
        $this->assertStringNotContainsString('<script>x</script>', $out);
    }

    #[Test]
    public function nests_templates_via_body(): void
    {
        $inner = $this->view->render('pages/hello', ['name' => 'X']);
        $out   = $this->view->render('pages/layout', ['body' => $inner]);
        $this->assertSame('<main><p>Ciao X!</p></main>', $out);
    }

    #[Test]
    public function throws_on_missing_template(): void
    {
        $this->expectException(RuntimeException::class);
        $this->view->render('pages/ghost');
    }
}
