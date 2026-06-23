<?php

declare(strict_types=1);

namespace Tests\Unit\PdfImport;

use App\Services\PdfImport\SolutionGenerator;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class SolutionCategoryTest extends TestCase
{
    private function row(string $type, string $q): array
    {
        return ['type' => $type, 'payload' => ['question' => $q]];
    }

    #[Test]
    public function vf_dal_tipo(): void
    {
        $this->assertSame('vf', SolutionGenerator::classifyCategory($this->row('VF', 'qualsiasi cosa')));
    }

    #[Test]
    public function fisica_da_unita_o_termini(): void
    {
        $this->assertSame('physics', SolutionGenerator::classifyCategory($this->row('Collect', 'Un corpo si muove a \\(20\\) m/s, calcola...')));
        $this->assertSame('physics', SolutionGenerator::classifyCategory($this->row('Collect', 'Calcola la velocità finale del corpo')));
    }

    #[Test]
    public function teoria_da_verbi(): void
    {
        $this->assertSame('theory', SolutionGenerator::classifyCategory($this->row('Collect', 'Dimostra il teorema di Pitagora')));
        $this->assertSame('theory', SolutionGenerator::classifyCategory($this->row('Collect', 'Spiega perché la funzione è continua')));
    }

    #[Test]
    public function algebra_di_default(): void
    {
        $this->assertSame('algebra', SolutionGenerator::classifyCategory($this->row('Collect', 'Risolvi l\'equazione \\(\\sqrt{x+2}=3\\)')));
        $this->assertSame('algebra', SolutionGenerator::classifyCategory($this->row('RM', 'Quale dei seguenti radicali esiste?')));
    }
}
