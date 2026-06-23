<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Security;

use App\Services\Security\TikzScriptValidator;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * G24.phase3 — TikZ script body XSS validation test suite.
 */
final class TikzScriptValidatorTest extends TestCase
{
    // ──────────────────────────────────────────────────────────────────
    // sanitize() — escape `</`
    // ──────────────────────────────────────────────────────────────────

    #[Test]
    public function escapes_closing_script_tag(): void
    {
        $in  = '\\begin{tikzpicture}\\node{ok};\\end{tikzpicture}</script><script>alert(1)</script>';
        $out = TikzScriptValidator::sanitize($in);
        // Il `</script>` letterale → `<\/script>` (escape)
        $this->assertStringNotContainsString('</script', $out);
        $this->assertStringContainsString('<\\/script', $out);
    }

    #[Test]
    public function escapes_closing_any_tag(): void
    {
        $in  = 'foo</bar>baz';
        $out = TikzScriptValidator::sanitize($in);
        $this->assertStringContainsString('<\\/bar', $out);
        $this->assertStringNotContainsString('</bar', $out);
    }

    #[Test]
    public function preserves_legitimate_tikz_body(): void
    {
        $in  = '\\begin{tikzpicture}[scale=1.5]\\draw[red] (0,0) -- (1,1);\\node at (0.5,0.5) {x};\\end{tikzpicture}';
        $out = TikzScriptValidator::sanitize($in);
        // Niente `<` o `</` → output unchanged
        $this->assertSame($in, $out);
    }

    #[Test]
    public function idempotent(): void
    {
        $in  = '\\begin{tikz}foo</script>bar\\end{tikz}';
        $once  = TikzScriptValidator::sanitize($in);
        $twice = TikzScriptValidator::sanitize($once);
        $this->assertSame($once, $twice);
    }

    #[Test]
    public function empty_input(): void
    {
        $this->assertSame('', TikzScriptValidator::sanitize(''));
    }

    // ──────────────────────────────────────────────────────────────────
    // validate() — hard reject
    // ──────────────────────────────────────────────────────────────────

    #[Test]
    public function validate_throws_on_unescaped_closing_script(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('XSS injection vector');
        TikzScriptValidator::validate('foo</script>bar');
    }

    #[Test]
    public function validate_throws_case_insensitive(): void
    {
        $this->expectException(\RuntimeException::class);
        TikzScriptValidator::validate('foo</SCRIPT>bar');
    }

    #[Test]
    public function validate_throws_with_whitespace(): void
    {
        $this->expectException(\RuntimeException::class);
        TikzScriptValidator::validate('foo</  script  >bar');
    }

    #[Test]
    public function validate_passes_legitimate_tikz(): void
    {
        // No exception expected
        TikzScriptValidator::validate('\\begin{tikzpicture}\\draw (0,0)--(1,1);\\end{tikzpicture}');
        $this->assertTrue(true); // Reached without throw
    }

    #[Test]
    public function validate_passes_escaped(): void
    {
        TikzScriptValidator::validate('foo<\\/script>bar');  // already escaped
        $this->assertTrue(true);
    }

    #[Test]
    public function validate_empty_input(): void
    {
        TikzScriptValidator::validate('');
        $this->assertTrue(true);
    }
}
