<?php

declare(strict_types=1);

namespace Tests\Unit\PdfImport;

use App\Services\PdfImport\ExtractionPipeline;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Parsing robusto dell'output LLM (dato non fidato): code-fence, oggetto
 * {exercises:[...]}, singolo oggetto, spazzatura.
 */
final class ExtractionParseTest extends TestCase
{
    #[Test]
    public function parses_code_fenced_json_array(): void
    {
        $out = ExtractionPipeline::parseJsonArray("```json\n[{\"number\":\"1\"}]\n```");
        $this->assertIsArray($out);
        $this->assertCount(1, $out);
        $this->assertSame('1', $out[0]['number']);
    }

    #[Test]
    public function unwraps_exercises_key(): void
    {
        $out = ExtractionPipeline::parseJsonArray('{"exercises":[{"number":"2"},{"number":"3"}]}');
        $this->assertCount(2, $out);
        $this->assertSame('3', $out[1]['number']);
    }

    #[Test]
    public function wraps_single_object(): void
    {
        $out = ExtractionPipeline::parseJsonArray('{"number":"7","text":"x"}');
        $this->assertCount(1, $out);
        $this->assertSame('7', $out[0]['number']);
    }

    #[Test]
    public function returns_null_on_garbage(): void
    {
        $this->assertNull(ExtractionPipeline::parseJsonArray('non è json'));
        $this->assertNull(ExtractionPipeline::parseJsonArray(''));
    }

    #[Test]
    public function isolates_array_inside_noise(): void
    {
        $out = ExtractionPipeline::parseJsonArray('Ecco il risultato: [{"number":"9"}] fine.');
        $this->assertIsArray($out);
        $this->assertSame('9', $out[0]['number']);
    }
}
