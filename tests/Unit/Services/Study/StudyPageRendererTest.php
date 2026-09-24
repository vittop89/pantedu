<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Study;

use App\Core\Request;
use App\Repositories\TeacherContentRepository;
use App\Services\Study\StudyPageRenderer;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Contratto fisso della lista dei topic (P5, 2026-09-04): classi CSS e URL
 * che il JavaScript dello studio (`.fm-study-topics a`) e le spec E2E
 * cercano. Se cambia qui, cambia anche chi lo legge.
 */
final class StudyPageRendererTest extends TestCase
{
    private function renderer(bool $canEdit = false): StudyPageRenderer
    {
        return new StudyPageRenderer(new TeacherContentRepository(), $canEdit);
    }

    #[Test]
    public function topics_list_links_each_topic_with_its_count(): void
    {
        $html = $this->renderer()->renderTopicsHtml(
            'mappa',
            ['ind' => 'sc', 'cls' => '2s', 'subj' => 'MAT'],
            [['topic' => 'Cinematica', 'count' => 3], ['topic' => 'Moto & forze', 'count' => 1]]
        );

        self::assertStringStartsWith('<main class="fm-study">', $html);
        self::assertStringContainsString('<h1>Mappa — MAT', $html);
        self::assertStringContainsString('(sc · 2s)', $html);
        self::assertStringContainsString('<ul class="fm-study-topics">', $html);
        self::assertStringContainsString('<a href="/studio/mappa/sc/2s/MAT/Cinematica">Cinematica</a>', $html);
        self::assertStringContainsString('<span class="fm-study-count">×3</span>', $html);
        // lo slug e' URL-encoded, il testo e' HTML-escaped
        self::assertStringContainsString('/studio/mappa/sc/2s/MAT/Moto%20%26%20forze">Moto &amp; forze</a>', $html);
        self::assertStringEndsWith('</main>', $html);
    }

    #[Test]
    public function empty_topics_list_says_so_and_escapes_parameters(): void
    {
        $html = $this->renderer()->renderTopicsHtml(
            'esercizio',
            ['ind' => '<b>', 'cls' => '2s', 'subj' => '"MAT"'],
            []
        );

        self::assertStringContainsString('Nessun esercizio pubblicato per questa sezione.', $html);
        self::assertStringNotContainsString('<ul', $html);
        self::assertStringContainsString('&lt;b&gt;', $html);
        self::assertStringContainsString('&quot;MAT&quot;', $html);
        self::assertStringNotContainsString('<b>', $html);
    }

    #[Test]
    public function wrap_in_shell_puts_the_body_inside_the_site_layout(): void
    {
        // Regressione 2026-09-05: il renderer, estratto dal controller (P5),
        // cercava views/layout/app.php due livelli sopra di se' (cioe' in app/)
        // e ogni pagina /studio/{type}/... usciva vuota con status 200.
        $_GET = [];
        $_SESSION = [];
        $_SERVER['HTTP_X_PARTIAL'] = '';
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['REQUEST_URI'] = '/studio/esercizio/sc/2s/MAT/Cinematica';

        $res = $this->renderer()->wrapInShell(
            new Request(),
            '<main class="fm-study" id="testmarker">corpo</main>',
            'Cinematica',
            'esercizio'
        );

        self::assertSame(200, $res->status);
        self::assertStringContainsString('<!doctype html>', strtolower($res->body));
        self::assertStringContainsString('id="testmarker"', $res->body);
        self::assertStringContainsString('PANTEDU — Cinematica', $res->body);
        self::assertMatchesRegularExpression('~<body class="exercise-context [^"]*"~', $res->body);
    }

    #[Test]
    public function wrap_in_shell_accepts_the_listing_pages_that_have_no_type(): void
    {
        // Le pagine elenco chiamano wrapInShell senza tipo: prima del 2026-09-05
        // formatOf(null) lanciava un TypeError e il WAF rispondeva «Verifica…».
        $_GET = [];
        $_SESSION = [];
        $_SERVER['HTTP_X_PARTIAL'] = '';
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['REQUEST_URI'] = '/studio/esercizio/sc/2s/MAT';

        $res = $this->renderer()->wrapInShell(
            new Request(),
            '<main class="fm-study" id="testmarker">elenco</main>',
            'Esercizio — MAT'
        );

        self::assertSame(200, $res->status);
        self::assertStringContainsString('id="testmarker"', $res->body);
        self::assertStringContainsString('PANTEDU — Esercizio — MAT', $res->body);
    }
}
