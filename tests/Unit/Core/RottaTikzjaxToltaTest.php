<?php

declare(strict_types=1);

namespace Tests\Unit\Core;

use App\Core\Request;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Tests\Support\RottaVera;

/**
 * `/tikzjax.js` non ha più una rotta (23/9/2026, revisione architetturale
 * A-54 e D-17).
 *
 * La rotta rimandava con un 301 a `/js/vendor/tikzjax.js`, tolto il 1/6/2026:
 * chi la seguiva arrivava a un 404 passando da un redirect permanente, che i
 * browser ricordano. Con lei sono usciti il motore WebAssembly del vecchio
 * TikZJax (`wasm/`), che nessuna pagina caricava, e la sua `location` di
 * nginx; il TikZ si rende sul server (ADR-013). Adesso la richiesta non trova
 * rotte e risponde il 404 dell'applicazione.
 */
final class RottaTikzjaxToltaTest extends TestCase
{
    #[Test]
    public function tikzjax_js_non_ha_una_rotta(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['REQUEST_URI'] = '/tikzjax.js';

        $this->assertNull(RottaVera::rotte()->match(new Request('')));
    }

    #[Test]
    public function il_file_a_cui_rimandava_e_il_motore_wasm_non_ci_sono(): void
    {
        $radice = \dirname(__DIR__, 3);

        $this->assertFileDoesNotExist($radice . '/js/vendor/tikzjax.js');
        $this->assertDirectoryDoesNotExist($radice . '/wasm');
    }
}
