<?php

declare(strict_types=1);

namespace Tests\Unit\PdfImport;

use App\Services\PdfImport\TranslationGenerator;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class TranslationTest extends TestCase
{
    #[Test]
    public function rileva_inglese(): void
    {
        $this->assertTrue(TranslationGenerator::looksEnglish(
            'Find the value of x which solves the following equation. If true then answer.'
        ));
    }

    #[Test]
    public function non_scambia_italiano_per_inglese(): void
    {
        $this->assertFalse(TranslationGenerator::looksEnglish(
            'Trova il valore di x che risolve la seguente equazione. Se vero allora rispondi.'
        ));
    }

    #[Test]
    public function ignora_il_latex(): void
    {
        // testo italiano con LaTeX che contiene parole inglesi-like non deve ingannare
        $this->assertFalse(TranslationGenerator::looksEnglish(
            'Risolvi \\(x = \\sin(t) + \\for\\) e calcola il valore della funzione.'
        ));
    }

    #[Test]
    public function set_by_path_campo_semplice_e_annidato(): void
    {
        $p = ['question' => 'q', 'options' => [['letter' => 'A', 'text' => 'old']]];
        TranslationGenerator::setByPath($p, 'question', 'nuova');
        TranslationGenerator::setByPath($p, 'options.0.text', 'tradotto');
        $this->assertSame('nuova', $p['question']);
        $this->assertSame('tradotto', $p['options'][0]['text']);
    }
}
