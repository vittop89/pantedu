<?php

namespace Tests\Integration;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Integration-ish check: the app layout has three modes
 *   full     (default)   — sidebar + modals + iframe
 *   embed    (?embed=1)  — minimal head + main only
 *   partial  (X-Partial) — only the content string
 *
 * We exercise them by including app.php with superglobals set,
 * capturing its output, and asserting on the resulting HTML.
 */
final class LayoutModesTest extends TestCase
{
    /**
     * La sidebar e' il landmark `<nav class="sidebar fm-sidebar" ...>`: la
     * migrazione BEM (docs/plans/css-refactor-residue-todo.md, 2026-06-18) ha
     * trasformato il vecchio `<div class="sidebar">` in un <nav> e AFFIANCATO
     * `fm-sidebar` alla classe legacy, che resta per CSS/JS non ancora
     * migrati. Il match e' sul primo token, non sull'attributo intero:
     * `class="sidebar"` letterale non compare piu' nel markup.
     */
    private const SIDEBAR_NAV = '~<nav\s+class="sidebar(?:\s|")~';

    private string $base;

    protected function setUp(): void
    {
        $this->base = realpath(__DIR__ . '/../..');
        $_GET = [];
        $_SERVER['HTTP_X_PARTIAL'] = '';
        $_SESSION = [];
    }

    private function render(array $vars = []): string
    {
        extract($vars + [
            'pageTitle'   => 'Test',
            'pageContent' => '<div id="testmarker">BODY</div>',
            'pageScripts' => '',
        ]);
        ob_start();
        include $this->base . '/views/layout/app.php';
        return (string)ob_get_clean();
    }

    #[Test]
    public function full_mode_includes_sidebar_and_iframe(): void
    {
        $html = $this->render();
        $this->assertStringContainsString('<!doctype html>', strtolower($html));
        $this->assertMatchesRegularExpression(self::SIDEBAR_NAV, $html);
        $this->assertStringContainsString('id="myframe"',    $html);
        $this->assertStringContainsString('id="testmarker"', $html);
    }

    #[Test]
    public function embed_mode_skips_sidebar_and_iframe(): void
    {
        $_GET['embed'] = '1';
        $html = $this->render();
        $this->assertStringContainsString('<!doctype html>', strtolower($html));
        $this->assertDoesNotMatchRegularExpression(self::SIDEBAR_NAV, $html);
        $this->assertStringNotContainsString('id="myframe"',    $html);
        $this->assertStringContainsString('id="testmarker"',    $html);
    }

    #[Test]
    public function partial_mode_emits_content_only(): void
    {
        $_SERVER['HTTP_X_PARTIAL'] = '1';
        $html = $this->render();
        $this->assertStringNotContainsString('<!doctype', strtolower($html));
        $this->assertStringNotContainsString('<head',     strtolower($html));
        $this->assertSame('<div id="testmarker">BODY</div>', trim($html));
    }
}
