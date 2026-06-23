<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Security;

use App\Services\Security\HtmlSanitizer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * G24.phase1 — XSS sanitization test suite con payloads OWASP cheat-sheet.
 *
 * Strategia: data provider con coppie (input, mustContain, mustNotContain)
 * per coprire i vector standard XSS senza falsi positivi su content legitimo.
 */
final class HtmlSanitizerTest extends TestCase
{
    // ──────────────────────────────────────────────────────────────────
    // ATTACK VECTORS — devono essere stripped
    // ──────────────────────────────────────────────────────────────────

    #[Test]
    public function strips_script_tag(): void
    {
        $out = HtmlSanitizer::forBlockContent('<b>ok</b><script>alert(1)</script>');
        $this->assertStringNotContainsString('<script', $out);
        $this->assertStringNotContainsString('alert(1)', $out);
        $this->assertStringContainsString('<b>ok</b>', $out);
    }

    #[Test]
    public function strips_javascript_href(): void
    {
        $out = HtmlSanitizer::forBlockContent('<a href="javascript:alert(1)">click</a>');
        $this->assertStringNotContainsString('javascript:', $out);
        // Link diventa link senza href (o testo plain)
        $this->assertStringContainsString('click', $out);
    }

    #[Test]
    public function strips_onclick_handler(): void
    {
        $out = HtmlSanitizer::forBlockContent('<span onclick="alert(1)">x</span>');
        $this->assertStringNotContainsString('onclick', $out);
        $this->assertStringNotContainsString('alert', $out);
        $this->assertStringContainsString('x', $out);
    }

    #[Test]
    public function strips_onerror_handler(): void
    {
        $out = HtmlSanitizer::forBlockContent('<span onerror="alert(1)">x</span>');
        $this->assertStringNotContainsString('onerror', $out);
    }

    #[Test]
    public function strips_iframe(): void
    {
        $out = HtmlSanitizer::forBlockContent('<iframe src="https://evil.com"></iframe>');
        $this->assertStringNotContainsString('<iframe', $out);
    }

    #[Test]
    public function strips_object_embed(): void
    {
        $out = HtmlSanitizer::forBlockContent('<object data="x.swf"></object><embed src="x.swf"/>');
        $this->assertStringNotContainsString('<object', $out);
        $this->assertStringNotContainsString('<embed', $out);
    }

    #[Test]
    public function strips_css_javascript_url(): void
    {
        $out = HtmlSanitizer::forBlockContent('<span style="background:url(javascript:alert(1))">x</span>');
        // HTMLPurifier rimuove URL javascript: dal CSS (anche se style attribute resta)
        $this->assertStringNotContainsString('javascript:', $out);
        $this->assertStringContainsString('x', $out);
    }

    #[Test]
    public function strips_data_uri_in_href(): void
    {
        $out = HtmlSanitizer::forBlockContent('<a href="data:text/html;base64,PHNjcmlwdD4=">x</a>');
        $this->assertStringNotContainsString('data:', $out);
    }

    #[Test]
    public function strips_svg_tag_with_script(): void
    {
        // <svg> non è in allowlist text block (gestito via SvgSanitizer separato)
        $out = HtmlSanitizer::forBlockContent('<svg onload="alert(1)"><script>alert(2)</script></svg>x');
        $this->assertStringNotContainsString('<svg', $out);
        $this->assertStringNotContainsString('<script', $out);
        $this->assertStringNotContainsString('onload', $out);
        $this->assertStringContainsString('x', $out);
    }

    #[Test]
    public function strips_vbscript_href(): void
    {
        $out = HtmlSanitizer::forBlockContent('<a href="vbscript:msgbox(1)">x</a>');
        $this->assertStringNotContainsString('vbscript:', $out);
    }

    #[Test]
    public function strips_meta_refresh(): void
    {
        $out = HtmlSanitizer::forBlockContent('<meta http-equiv="refresh" content="0;url=https://evil.com">x');
        $this->assertStringNotContainsString('<meta', $out);
    }

    #[Test]
    public function strips_form_input(): void
    {
        $out = HtmlSanitizer::forBlockContent('<form action="evil"><input type="password" name="pwd"></form>x');
        $this->assertStringNotContainsString('<form', $out);
        $this->assertStringNotContainsString('<input', $out);
    }

    // ──────────────────────────────────────────────────────────────────
    // LEGITIMATE CONTENT — deve essere PRESERVATO
    // ──────────────────────────────────────────────────────────────────

    #[Test]
    public function preserves_bold_italic_underline(): void
    {
        $in  = '<b>bold</b> <i>italic</i> <u>underline</u>';
        $out = HtmlSanitizer::forBlockContent($in);
        $this->assertStringContainsString('<b>bold</b>', $out);
        $this->assertStringContainsString('<i>italic</i>', $out);
        $this->assertStringContainsString('<u>underline</u>', $out);
    }

    #[Test]
    public function preserves_strong_em(): void
    {
        $in  = '<strong>important</strong> <em>emphasis</em>';
        $out = HtmlSanitizer::forBlockContent($in);
        $this->assertStringContainsString('<strong>important</strong>', $out);
        $this->assertStringContainsString('<em>emphasis</em>', $out);
    }

    #[Test]
    public function preserves_sub_sup(): void
    {
        $in  = 'H<sub>2</sub>O E=mc<sup>2</sup>';
        $out = HtmlSanitizer::forBlockContent($in);
        $this->assertStringContainsString('H<sub>2</sub>O', $out);
        $this->assertStringContainsString('mc<sup>2</sup>', $out);
    }

    #[Test]
    public function preserves_strikethrough(): void
    {
        $in  = '<s>deleted</s>';
        $out = HtmlSanitizer::forBlockContent($in);
        $this->assertStringContainsString('<s>deleted</s>', $out);
    }

    #[Test]
    public function preserves_safe_http_link(): void
    {
        $in  = '<a href="https://it.wikipedia.org/wiki/Pi_greco">Pi greco</a>';
        $out = HtmlSanitizer::forBlockContent($in);
        $this->assertStringContainsString('href="https://it.wikipedia.org/wiki/Pi_greco"', $out);
        $this->assertStringContainsString('Pi greco', $out);
    }

    #[Test]
    public function preserves_safe_http_link_with_http(): void
    {
        $in  = '<a href="http://example.com">x</a>';
        $out = HtmlSanitizer::forBlockContent($in);
        $this->assertStringContainsString('href="http://example.com"', $out);
    }

    #[Test]
    public function preserves_mailto_link(): void
    {
        $in  = '<a href="mailto:teacher@school.edu">email</a>';
        $out = HtmlSanitizer::forBlockContent($in);
        $this->assertStringContainsString('mailto:teacher@school.edu', $out);
    }

    #[Test]
    public function preserves_safe_css_color(): void
    {
        $in  = '<span style="color:red">x</span>';
        $out = HtmlSanitizer::forBlockContent($in);
        $this->assertStringContainsString('color', $out);
        $this->assertStringContainsString('x', $out);
    }

    #[Test]
    public function preserves_br_newline(): void
    {
        $in  = 'line1<br>line2';
        $out = HtmlSanitizer::forBlockContent($in);
        $this->assertStringContainsString('<br', $out);
    }

    #[Test]
    public function preserves_nested_inline_format(): void
    {
        $in  = '<b><i>bold italic</i></b>';
        $out = HtmlSanitizer::forBlockContent($in);
        $this->assertMatchesRegularExpression('#<b><i>bold italic</i></b>#', $out);
    }

    // ──────────────────────────────────────────────────────────────────
    // STRICT MODE — no HTML at all (badge/category labels)
    // ──────────────────────────────────────────────────────────────────

    #[Test]
    public function strict_strips_all_html(): void
    {
        $out = HtmlSanitizer::forStrictText('<b>bold</b> plain text');
        $this->assertStringNotContainsString('<b>', $out);
        $this->assertStringContainsString('bold', $out);
        $this->assertStringContainsString('plain text', $out);
    }

    #[Test]
    public function strict_strips_script(): void
    {
        $out = HtmlSanitizer::forStrictText('<script>alert(1)</script>x');
        $this->assertStringNotContainsString('<script', $out);
        $this->assertStringNotContainsString('alert', $out);
        $this->assertStringContainsString('x', $out);
    }

    // ──────────────────────────────────────────────────────────────────
    // EDGE CASES
    // ──────────────────────────────────────────────────────────────────

    #[Test]
    public function empty_input_returns_empty(): void
    {
        $this->assertSame('', HtmlSanitizer::forBlockContent(''));
        $this->assertSame('', HtmlSanitizer::forStrictText(''));
    }

    #[Test]
    public function plain_text_unchanged(): void
    {
        $out = HtmlSanitizer::forBlockContent('Calcola il valore di x.');
        $this->assertStringContainsString('Calcola il valore di x.', $out);
    }

    #[Test]
    public function malformed_html_not_break(): void
    {
        // Tag non chiusi / malformed: HTMLPurifier auto-fix senza crash
        $out = HtmlSanitizer::forBlockContent('<b>unclosed');
        $this->assertIsString($out);
        $this->assertStringContainsString('unclosed', $out);
    }

    #[Test]
    public function double_encoded_payload(): void
    {
        // Tentativo encoding bypass: `&lt;script&gt;` resta innocuo (già escapato)
        $in  = '&lt;script&gt;alert(1)&lt;/script&gt;';
        $out = HtmlSanitizer::forBlockContent($in);
        $this->assertStringNotContainsString('<script', $out);
    }
}
