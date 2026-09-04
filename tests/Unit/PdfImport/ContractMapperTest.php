<?php

declare(strict_types=1);

namespace Tests\Unit\PdfImport;

use App\Services\PdfImport\ContractMapper;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ContractMapperTest extends TestCase
{
    private function mapper(): ContractMapper
    {
        return new ContractMapper();
    }

    #[Test]
    public function multiple_choice_uppercase_maps_to_RM(): void
    {
        $item = [
            'number' => '5',
            'text' => 'Per quale valore di \\(x\\) l\'espressione perde significato?',
            'sub_items' => [
                ['letter' => 'A', 'text' => '\\(0\\)'],
                ['letter' => 'B', 'text' => '\\(1\\)'],
                ['letter' => 'C', 'text' => '\\(2\\)'],
            ],
            'badge_color' => 'green',
            'difficulty' => 3,
        ];
        $row = $this->mapper()->mapItem($item, 506);

        $this->assertSame('RM', $row['type']);
        $this->assertCount(3, $row['payload']['options']);
        $this->assertFalse($row['payload']['options'][0]['correct']);
        $this->assertSame('A', $row['payload']['options'][0]['letter']);
        $this->assertStringContainsString('\\(x\\)', $row['payload']['question']);
        $this->assertSame('green', $row['badge_color']);
        $this->assertSame(3, $row['difficulty']);
        $this->assertSame(506, $row['source_page']);
    }

    #[Test]
    public function vero_falso_maps_to_VF_with_default_answer(): void
    {
        $item = [
            'number' => '64',
            'text' => 'Stabilisci se le seguenti affermazioni sono vere o false.',
            'sub_items' => [
                ['letter' => 'a', 'text' => '\\(\\frac{2}{x}\\) è definita ovunque.'],
                ['letter' => 'b', 'text' => 'Le C.E. coincidono.'],
            ],
        ];
        $row = $this->mapper()->mapItem($item, 509);

        $this->assertSame('VF', $row['type']);
        $this->assertCount(2, $row['payload']['statements']);
        $this->assertSame('V', $row['payload']['statements'][0]['answer']);
        $this->assertContains('vf_answers_unknown', $row['flags']);
    }

    #[Test]
    public function plain_problem_maps_to_Collect(): void
    {
        $item = [
            'number' => '12',
            'text' => 'Calcola il dominio di \\(f(x)=\\sqrt{x-1}\\).',
            'sub_items' => [],
        ];
        $row = $this->mapper()->mapItem($item, 1);

        $this->assertSame('Collect', $row['type']);
        $this->assertArrayHasKey('points', $row['payload']);
        $this->assertStringContainsString('\\sqrt{x-1}', $row['payload']['question']);
    }

    #[Test]
    public function difficulty_is_clamped(): void
    {
        $row = $this->mapper()->mapItem(['text' => 'x', 'difficulty' => 9], 1);
        $this->assertSame(4, $row['difficulty']);
        $row2 = $this->mapper()->mapItem(['text' => 'x', 'difficulty' => -3], 1);
        $this->assertSame(0, $row2['difficulty']);
    }

    #[Test]
    public function invalid_color_is_dropped(): void
    {
        $row = $this->mapper()->mapItem(['text' => 'x', 'badge_color' => 'fuchsia'], 1);
        $this->assertSame('', $row['badge_color']);
    }

    #[Test]
    public function every_row_has_uuid_id(): void
    {
        $a = $this->mapper()->mapItem(['text' => 'x'], 1);
        $b = $this->mapper()->mapItem(['text' => 'x'], 1);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{8}-[0-9a-f]{4}-4/', $a['id']);
        $this->assertNotSame($a['id'], $b['id']);
    }
}
