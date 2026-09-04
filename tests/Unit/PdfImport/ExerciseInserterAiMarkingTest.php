<?php

declare(strict_types=1);

namespace Tests\Unit\PdfImport;

use App\Repositories\TeacherContentRepository;
use App\Services\PdfImport\AiProvenance;
use App\Services\PdfImport\ExerciseInserter;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * AI Act art. 50(2) — il blocco `ai` deve comparire nell'item del contract SOLO
 * quando la riga porta campi generati, e non deve comparire per il contenuto
 * trascritto dal PDF. La soluzione STAMPATA sul libro non è output sintetico:
 * marcarla sarebbe una disclosure falsa.
 */
final class ExerciseInserterAiMarkingTest extends TestCase
{
    private function inserter(): ExerciseInserter
    {
        return new ExerciseInserter($this->createMock(TeacherContentRepository::class));
    }

    /** @return array<string,mixed> primo item del gruppo costruito dalla row */
    private function firstItem(array $row): array
    {
        return $this->inserter()->buildGroupPublic($row)['items'][0];
    }

    private function row(string $solution = ''): array
    {
        return [
            'number'  => '42',
            'type'    => 'Collect',
            'topic'   => 'Limiti',
            'origin'  => '',
            'payload' => ['question' => 'Calcola il limite.', 'solution' => $solution],
        ];
    }

    #[Test]
    public function printed_solution_is_not_marked_as_ai(): void
    {
        // Soluzione estratta dalla pagina: nessuno stamp generativo sulla row.
        $item = $this->firstItem($this->row('Risultato stampato sul libro.'));
        $this->assertArrayNotHasKey('ai', $item);
    }

    #[Test]
    public function generated_solution_is_marked(): void
    {
        $row = $this->row();
        AiProvenance::stamp($row, 'solution', AiProvenance::OP_SOLUTIONS, 'claude-opus-4-8');

        $item = $this->firstItem($row);
        $this->assertArrayHasKey('ai', $item);
        $this->assertTrue($item['ai']['generated']);
        $this->assertSame(['solution'], $item['ai']['fields']);
        $this->assertTrue($item['ai']['human_reviewed']);
    }

    #[Test]
    public function assistive_difficulty_alone_does_not_mark_the_item(): void
    {
        $row = $this->row('Risultato stampato.');
        AiProvenance::stamp($row, 'difficulty', AiProvenance::OP_DIFFICULTY, 'm');

        $this->assertArrayNotHasKey('ai', $this->firstItem($row));
    }

    #[Test]
    public function generated_topic_maps_to_category_label(): void
    {
        $row = $this->row('Stampata.');
        AiProvenance::stamp($row, 'topic', AiProvenance::OP_TOPICS, 'm');

        $item = $this->firstItem($row);
        $this->assertSame(['category_label'], $item['ai']['fields']);
    }

    #[Test]
    public function marking_survives_on_every_statement_of_a_vf_group(): void
    {
        // Un V/F genera N item (uno per affermazione): tutti appartengono
        // allo stesso esercizio, quindi tutti portano la marcatura.
        $row = [
            'number'  => '7',
            'type'    => 'VF',
            'origin'  => '',
            'payload' => ['statements' => [
                ['text' => 'Prima affermazione', 'answer' => 'V'],
                ['text' => 'Seconda affermazione', 'answer' => 'F'],
            ]],
        ];
        AiProvenance::stamp($row, 'solution', AiProvenance::OP_SOLUTIONS, 'm');

        $items = $this->inserter()->buildGroupPublic($row)['items'];
        $this->assertCount(2, $items);
        foreach ($items as $it) {
            $this->assertTrue($it['ai']['generated']);
        }
    }

    #[Test]
    public function existing_item_fields_are_preserved_alongside_the_marking(): void
    {
        $row = $this->row();
        $row['difficulty'] = 3;
        AiProvenance::stamp($row, 'solution', AiProvenance::OP_SOLUTIONS, 'm');

        $item = $this->firstItem($row);
        $this->assertSame(3, $item['difficulty']);
        $this->assertSame('Limiti', $item['category_label']);
        $this->assertSame('personal', $item['origin']);
        $this->assertArrayHasKey('question', $item);
    }
}
