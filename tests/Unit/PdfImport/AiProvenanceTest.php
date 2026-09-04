<?php

declare(strict_types=1);

namespace Tests\Unit\PdfImport;

use App\Services\PdfImport\AiProvenance;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * AI Act art. 50(2) — la distinzione fra operazione assistiva e generativa
 * decide se un item viene marcato verso lo studente. Un falso positivo è un
 * difetto di trasparenza esattamente come un falso negativo: entrambi i versi
 * sono coperti dai test.
 */
final class AiProvenanceTest extends TestCase
{
    #[Test]
    public function generative_ops_are_classified_as_such(): void
    {
        $this->assertTrue(AiProvenance::isGenerative(AiProvenance::OP_SOLUTIONS));
        $this->assertTrue(AiProvenance::isGenerative(AiProvenance::OP_TOPICS));
        $this->assertTrue(AiProvenance::isGenerative(AiProvenance::OP_TRANSLATION));
    }

    #[Test]
    public function assistive_ops_are_not_generative(): void
    {
        // Trascrivono ciò che è già stampato sulla pagina → eccezione art. 50(2).
        $this->assertFalse(AiProvenance::isGenerative(AiProvenance::OP_EXTRACTION));
        $this->assertFalse(AiProvenance::isGenerative(AiProvenance::OP_DIFFICULTY));
    }

    #[Test]
    public function stamp_records_op_model_and_timestamp(): void
    {
        $row = ['number' => '12'];
        AiProvenance::stamp($row, 'solution', AiProvenance::OP_SOLUTIONS, 'claude-opus-4-8');

        $entry = $row[AiProvenance::META_KEY]['fields']['solution'];
        $this->assertSame(AiProvenance::OP_SOLUTIONS, $entry['op']);
        $this->assertSame('claude-opus-4-8', $entry['model']);
        $this->assertNotSame('', $entry['at']);
        // La riga originale non viene toccata altrove.
        $this->assertSame('12', $row['number']);
    }

    #[Test]
    public function row_field_names_map_onto_contract_field_names(): void
    {
        $row = [];
        AiProvenance::stamp($row, 'topic', AiProvenance::OP_TOPICS, 'm');
        AiProvenance::stamp($row, 'payload', AiProvenance::OP_TRANSLATION, 'm');

        // topic → category_label, payload → question (cfr. ExerciseInserter::baseItem)
        $this->assertSame(['category_label', 'question'], AiProvenance::generativeFields($row));
    }

    #[Test]
    public function assistive_stamp_alone_produces_no_marking(): void
    {
        $row = [];
        AiProvenance::stamp($row, 'difficulty', AiProvenance::OP_DIFFICULTY, 'm');

        $this->assertSame([], AiProvenance::generativeFields($row));
        $this->assertFalse(AiProvenance::hasGenerative($row));
        $this->assertNull(AiProvenance::itemBlock($row));
    }

    #[Test]
    public function mixed_row_marks_only_the_generative_field(): void
    {
        // Caso reale: difficoltà letta dai pallini stampati + soluzione generata.
        $row = [];
        AiProvenance::stamp($row, 'difficulty', AiProvenance::OP_DIFFICULTY, 'm');
        AiProvenance::stamp($row, 'solution', AiProvenance::OP_SOLUTIONS, 'm');

        $this->assertSame(['solution'], AiProvenance::generativeFields($row));
    }

    #[Test]
    public function item_block_carries_fields_ops_and_human_review(): void
    {
        $row = [];
        AiProvenance::stamp($row, 'solution', AiProvenance::OP_SOLUTIONS, 'gpt-4o');

        $block = AiProvenance::itemBlock($row);
        $this->assertNotNull($block);
        $this->assertTrue($block['generated']);
        $this->assertSame(['solution'], $block['fields']);
        $this->assertTrue($block['human_reviewed']);
        $this->assertSame('solutions', $block['ops'][0]['op']);
        $this->assertSame('gpt-4o', $block['ops'][0]['model']);
    }

    #[Test]
    public function ops_are_deduplicated_per_op_and_model(): void
    {
        $row = [];
        AiProvenance::stamp($row, 'solution', AiProvenance::OP_SOLUTIONS, 'm1');
        AiProvenance::stamp($row, 'topic', AiProvenance::OP_TOPICS, 'm1');

        $ops = AiProvenance::generativeOps($row);
        $this->assertCount(2, $ops);

        // Stesso op+model su un secondo campo non duplica la voce.
        $row2 = [];
        AiProvenance::stamp($row2, 'solution', AiProvenance::OP_SOLUTIONS, 'm1');
        AiProvenance::stamp($row2, 'payload', AiProvenance::OP_SOLUTIONS, 'm1');
        $this->assertCount(1, AiProvenance::generativeOps($row2));
    }

    #[Test]
    public function untouched_row_yields_nothing(): void
    {
        $this->assertNull(AiProvenance::itemBlock(['number' => '1', 'topic' => 'Limiti']));
        $this->assertSame([], AiProvenance::generativeFields([]));
        // Meta malformato non deve far esplodere nulla.
        $this->assertSame([], AiProvenance::generativeFields(['ai_meta' => 'garbage']));
        $this->assertSame([], AiProvenance::generativeFields(['ai_meta' => ['fields' => 'garbage']]));
    }

    #[Test]
    public function restamping_a_field_replaces_the_previous_entry(): void
    {
        $row = [];
        AiProvenance::stamp($row, 'solution', AiProvenance::OP_SOLUTIONS, 'vecchio');
        AiProvenance::stamp($row, 'solution', AiProvenance::OP_SOLUTIONS, 'nuovo');

        $ops = AiProvenance::generativeOps($row);
        $this->assertCount(1, $ops);
        $this->assertSame('nuovo', $ops[0]['model']);
    }

    #[Test]
    public function empty_field_or_op_is_ignored(): void
    {
        $row = [];
        AiProvenance::stamp($row, '', AiProvenance::OP_SOLUTIONS, 'm');
        AiProvenance::stamp($row, 'solution', '', 'm');
        $this->assertSame([], AiProvenance::generativeFields($row));
    }
}
