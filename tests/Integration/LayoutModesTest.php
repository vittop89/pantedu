<?php

namespace Tests\Integration;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * 23/9/2026 (revisione architetturale A-33): istituto, vocabolario e servizi
 * della barra si calcolano solo nella cornice intera. Prima il layout li
 * calcolava in cima anche in partial (navigazione SPA) ed embed, che poi
 * scartavano il risultato. Il calcolo passa da `$fmDatiDellaBarra`, che la
 * prova sostituisce con una funzione che conta: zero volte in partial ed
 * embed, una nella cornice intera. E il ramo docente, che non aveva una prova
 * PHP: il layout reso per un docente, con i suoi elementi.
 *
 * Controprova (23/9/2026): rimesso il calcolo in cima, prima dei due rami,
 * le prove di partial ed embed contano una chiamata e falliscono.
 *
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

    /** Quante volte il layout ha chiesto i dati della barra. */
    private int $calcoliDellaBarra = 0;

    protected function setUp(): void
    {
        $this->base = realpath(__DIR__ . '/../..');
        $_GET = [];
        $_SERVER['HTTP_X_PARTIAL'] = '';
        $_SESSION = [];
        $this->calcoliDellaBarra = 0;
    }

    protected function tearDown(): void
    {
        $_SESSION = [];
        $_GET = [];
        $_SERVER['HTTP_X_PARTIAL'] = '';
    }

    /**
     * I dati della barra, finti e contati: un indirizzo, una classe, una
     * materia, riconoscibili nell'HTML.
     *
     * @return \Closure(): array<string, mixed>
     */
    private function datiContati(): \Closure
    {
        return function (): array {
            $this->calcoliDellaBarra++;
            return [
                'istituto'           => null,
                'utente'             => 0,
                'curriculum'         => [
                    'indirizzi' => [['code' => 'zz_ind', 'label' => 'Indirizzo di prova A33', 'group' => 'Prove', 'active' => true]],
                    'classi'    => [['code' => 'zz_cls', 'label' => 'Classe di prova A33', 'group' => 'Prove', 'active' => true]],
                    'materie'   => [['code' => 'zz_mat', 'label' => 'Materia di prova A33', 'active' => true]],
                ],
                'materieDiChiStudia' => false,
            ];
        };
    }

    private function comeDocente(): void
    {
        $_SESSION = [
            'autenticato' => true,
            'username'    => 'zz_docente_layout_' . bin2hex(random_bytes(4)),
            'user_id'     => 0,
            'user_role'   => 'teacher',
            // Claims appena letti: niente rilettura dal database.
            'claims_at'   => time(),
        ];
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
        // L'altro verso della prova del ramo docente, qui sotto: senza
        // sessione la barra è quella dell'ospite.
        $this->assertStringContainsString('data-fm-role="guest"', $html);
        $this->assertStringNotContainsString('fm-session-banner--teacher', $html);
        $this->assertStringContainsString('data-fm-can-edit="0"', $html);
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

    #[Test]
    public function partial_non_calcola_i_dati_della_barra(): void
    {
        $this->comeDocente();
        $_SERVER['HTTP_X_PARTIAL'] = '1';
        $html = $this->render(['fmDatiDellaBarra' => $this->datiContati()]);

        $this->assertSame('<div id="testmarker">BODY</div>', trim($html));
        $this->assertSame(0, $this->calcoliDellaBarra, 'la navigazione SPA non chiede istituto né vocabolario');
    }

    #[Test]
    public function embed_non_calcola_i_dati_della_barra(): void
    {
        $this->comeDocente();
        $_GET['embed'] = '1';
        $html = $this->render(['fmDatiDellaBarra' => $this->datiContati()]);

        $this->assertStringContainsString('id="testmarker"', $html);
        $this->assertSame(0, $this->calcoliDellaBarra, 'l\'embed non chiede istituto né vocabolario');
    }

    #[Test]
    public function la_cornice_intera_calcola_i_dati_una_volta_e_la_barra_li_usa(): void
    {
        $this->comeDocente();
        $html = $this->render(['fmDatiDellaBarra' => $this->datiContati()]);

        $this->assertSame(1, $this->calcoliDellaBarra);
        $this->assertStringContainsString('Indirizzo di prova A33', $html);
        $this->assertStringContainsString('Classe di prova A33', $html);
        $this->assertStringContainsString('Materia di prova A33', $html);
    }

    /**
     * Il ramo docente, con il calcolo vero della barra (nessuna sostituzione):
     * gli attributi del <body> che il client legge, la barra con i selettori,
     * il riquadro del docente con l'area docente, i comandi del docente.
     */
    #[Test]
    public function il_layout_del_docente_ha_i_suoi_elementi(): void
    {
        $this->comeDocente();
        $html = $this->render();

        $this->assertMatchesRegularExpression(self::SIDEBAR_NAV, $html);
        $this->assertMatchesRegularExpression('~<body class="[^"]*\bfm-can-edit\b~', $html);
        $this->assertStringContainsString('data-fm-can-edit="1"', $html);
        $this->assertStringContainsString('data-fm-role="teacher"', $html);
        $this->assertMatchesRegularExpression('~data-fm-zones="[^"]*\bteacher\b~', $html);
        $this->assertStringContainsString('id="sel-iis"', $html);
        $this->assertStringContainsString('id="sel-cls"', $html);
        $this->assertStringContainsString('id="sel-mater"', $html);
        $this->assertStringContainsString('fm-session-banner--teacher', $html);
        $this->assertStringContainsString('href="/area-docente/dashboard"', $html);
        $this->assertStringContainsString('id="fm-shortcuts-btn"', $html);
        $this->assertStringContainsString('href="/logout"', $html);
        $this->assertStringContainsString('id="testmarker"', $html);
        $this->assertStringNotContainsString('data-fm-guest="1"', $html, 'non è la barra dell\'ospite');
    }
}
