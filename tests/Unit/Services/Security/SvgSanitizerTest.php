<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Security;

use App\Services\Security\SvgSanitizer;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * G24.phase2 — SVG XSS sanitization test suite per GeoGebra inline.
 */
final class SvgSanitizerTest extends TestCase
{
    // ──────────────────────────────────────────────────────────────────
    // ATTACK VECTORS
    // ──────────────────────────────────────────────────────────────────

    #[Test]
    public function strips_script_tag_inside_svg(): void
    {
        $in  = '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script><rect x="0" y="0" width="10" height="10"/></svg>';
        $out = SvgSanitizer::sanitize($in);
        $this->assertStringNotContainsString('<script', $out);
        $this->assertStringNotContainsString('alert(1)', $out);
        $this->assertStringContainsString('<rect', $out);
    }

    #[Test]
    public function strips_onload_handler(): void
    {
        $in  = '<svg xmlns="http://www.w3.org/2000/svg" onload="alert(1)"><circle cx="50" cy="50" r="40"/></svg>';
        $out = SvgSanitizer::sanitize($in);
        $this->assertStringNotContainsString('onload', $out);
        $this->assertStringContainsString('<circle', $out);
    }

    #[Test]
    public function strips_onclick_handler(): void
    {
        $in  = '<svg xmlns="http://www.w3.org/2000/svg"><rect onclick="alert(1)" x="0" y="0" width="10" height="10"/></svg>';
        $out = SvgSanitizer::sanitize($in);
        $this->assertStringNotContainsString('onclick', $out);
    }

    #[Test]
    public function strips_foreignobject_with_html(): void
    {
        $in  = '<svg xmlns="http://www.w3.org/2000/svg"><foreignObject width="100" height="100"><iframe src="javascript:alert(1)"></iframe></foreignObject></svg>';
        $out = SvgSanitizer::sanitize($in);
        // foreignObject può restare ma il content malicious (iframe javascript) deve essere sanitizzato
        $this->assertStringNotContainsString('javascript:', $out);
        $this->assertStringNotContainsString('<iframe', $out);
    }

    #[Test]
    public function strips_use_javascript_href(): void
    {
        $in  = '<svg xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink"><use xlink:href="javascript:alert(1)"/></svg>';
        $out = SvgSanitizer::sanitize($in);
        $this->assertStringNotContainsString('javascript:', $out);
    }

    #[Test]
    public function strips_anchor_javascript_href_inside_svg(): void
    {
        $in  = '<svg xmlns="http://www.w3.org/2000/svg"><a href="javascript:alert(1)"><text>x</text></a></svg>';
        $out = SvgSanitizer::sanitize($in);
        $this->assertStringNotContainsString('javascript:', $out);
    }

    #[Test]
    public function strips_style_with_expression(): void
    {
        $in  = '<svg xmlns="http://www.w3.org/2000/svg" style="background:url(javascript:alert(1))"><circle r="5"/></svg>';
        $out = SvgSanitizer::sanitize($in);
        $this->assertStringNotContainsString('javascript:', $out);
    }

    #[Test]
    public function strips_set_animation_with_script(): void
    {
        // SMIL animation con JS injection
        $in  = '<svg xmlns="http://www.w3.org/2000/svg"><animate onbegin="alert(1)" attributeName="x"/></svg>';
        $out = SvgSanitizer::sanitize($in);
        $this->assertStringNotContainsString('onbegin', $out);
    }

    // ──────────────────────────────────────────────────────────────────
    // LEGITIMATE SVG — preservato
    // ──────────────────────────────────────────────────────────────────

    #[Test]
    public function preserves_basic_geometry(): void
    {
        $in  = '<svg xmlns="http://www.w3.org/2000/svg" width="100" height="100"><rect x="10" y="10" width="80" height="80" fill="blue"/><circle cx="50" cy="50" r="20" fill="red"/></svg>';
        $out = SvgSanitizer::sanitize($in);
        $this->assertStringContainsString('<svg', $out);
        $this->assertStringContainsString('<rect', $out);
        $this->assertStringContainsString('<circle', $out);
        $this->assertStringContainsString('fill="blue"', $out);
        $this->assertStringContainsString('fill="red"', $out);
    }

    #[Test]
    public function preserves_text_element(): void
    {
        $in  = '<svg xmlns="http://www.w3.org/2000/svg"><text x="10" y="20">Hello</text></svg>';
        $out = SvgSanitizer::sanitize($in);
        $this->assertStringContainsString('<text', $out);
        $this->assertStringContainsString('Hello', $out);
    }

    #[Test]
    public function preserves_path(): void
    {
        $in  = '<svg xmlns="http://www.w3.org/2000/svg"><path d="M 10 10 L 90 90 Z" stroke="black" fill="none"/></svg>';
        $out = SvgSanitizer::sanitize($in);
        $this->assertStringContainsString('<path', $out);
        $this->assertStringContainsString('d="M 10 10 L 90 90 Z"', $out);
    }

    #[Test]
    public function preserves_geogebra_typical_output(): void
    {
        // Typical SVG output from GeoGebra (axes + label + curve)
        $in  = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 200 200"><g><line x1="0" y1="100" x2="200" y2="100" stroke="black"/><line x1="100" y1="0" x2="100" y2="200" stroke="black"/><text x="105" y="20">y</text></g></svg>';
        $out = SvgSanitizer::sanitize($in);
        $this->assertStringContainsString('<svg', $out);
        $this->assertStringContainsString('<line', $out);
        $this->assertStringContainsString('<text', $out);
        $this->assertStringContainsString('y</text>', $out);
    }

    // ──────────────────────────────────────────────────────────────────
    // EDGE CASES
    // ──────────────────────────────────────────────────────────────────

    #[Test]
    public function empty_input_returns_empty(): void
    {
        $this->assertSame('', SvgSanitizer::sanitize(''));
    }

    #[Test]
    public function malformed_svg_safe_fallback(): void
    {
        // Non rompe su input invalid: ritorna stringa o empty (safe)
        $out = SvgSanitizer::sanitize('<svg<<broken');
        $this->assertIsString($out);
        $this->assertStringNotContainsString('<script', $out);
    }

    #[Test]
    public function non_svg_input_strip_all(): void
    {
        // Input non-SVG: sanitize ritorna empty o stringa innocua
        $out = SvgSanitizer::sanitize('<script>alert(1)</script>not svg');
        $this->assertStringNotContainsString('<script', $out);
        $this->assertStringNotContainsString('alert(1)', $out);
    }
}
