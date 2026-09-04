<?php declare(strict_types=1);

namespace Tests\Unit\Risdoc\Pt;

use App\Services\Risdoc\Pt\PtValidator;
use PHPUnit\Framework\TestCase;

/**
 * Test PtValidator (Phase 22.2).
 *
 * Valida casi happy-path (fixture profilo_classe) + edge case broken:
 * campo mancante, _type sconosciuto, struttura invertita.
 */
final class PtValidatorTest extends TestCase
{
    protected function setUp(): void
    {
        PtValidator::flushCache();
    }

    public function testFixtureProfiloIsValid(): void
    {
        $path = dirname(__DIR__, 3) . '/../schemas/risdoc/_pt/fixture-profilo.pt.json';
        $pt = json_decode((string)file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
        $result = PtValidator::validate($pt);
        self::assertTrue($result['valid'], 'fixture deve validare. Errori: ' . implode('; ', $result['errors']));
    }

    public function testEmptyArrayIsValid(): void
    {
        $result = PtValidator::validate([]);
        self::assertTrue($result['valid']);
    }

    public function testValidMinimalBlock(): void
    {
        $pt = [[
            '_type' => 'block',
            'children' => [['_type' => 'span', 'text' => 'ok']],
        ]];
        self::assertTrue(PtValidator::validate($pt)['valid']);
    }

    public function testInvalidBlockMissingType(): void
    {
        $pt = [['children' => []]];
        $result = PtValidator::validate($pt);
        self::assertFalse($result['valid']);
    }

    public function testInvalidUnknownBlockType(): void
    {
        $pt = [['_type' => 'unknownThing']];
        $result = PtValidator::validate($pt);
        self::assertFalse($result['valid']);
    }

    public function testInvalidSpanWithoutText(): void
    {
        $pt = [[
            '_type' => 'block',
            'children' => [['_type' => 'span']], // text mancante
        ]];
        self::assertFalse(PtValidator::validate($pt)['valid']);
    }

    public function testInvalidMarkNotInEnum(): void
    {
        $pt = [[
            '_type' => 'block',
            'children' => [[
                '_type' => 'span',
                'text' => 'x',
                'marks' => ['nonExistentMark'],
            ]],
        ]];
        self::assertFalse(PtValidator::validate($pt)['valid']);
    }

    public function testInvalidFieldRefNameSpecialChars(): void
    {
        $pt = [[
            '_type' => 'block',
            'children' => [
                ['_type' => 'span', 'text' => 'Classe ', 'marks' => []],
                ['_type' => 'fieldRef', 'name' => 'with-dash'], // pattern vieta dash
            ],
        ]];
        self::assertFalse(PtValidator::validate($pt)['valid']);
    }

    public function testValidFieldRefSnakeCase(): void
    {
        $pt = [[
            '_type' => 'block',
            'children' => [
                ['_type' => 'fieldRef', 'name' => 'anno_scolastico'],
            ],
        ]];
        self::assertTrue(PtValidator::validate($pt)['valid']);
    }

    public function testInvalidCheckboxGroupEmptyItems(): void
    {
        $pt = [[
            '_type' => 'checkboxGroup',
            'items' => [], // minItems=1
        ]];
        self::assertFalse(PtValidator::validate($pt)['valid']);
    }

    public function testInvalidCheckboxStateNotInEnum(): void
    {
        $pt = [[
            '_type' => 'checkboxGroup',
            'items' => [['state' => 'yes', 'label' => 'ok']],
        ]];
        self::assertFalse(PtValidator::validate($pt)['valid']);
    }

    public function testInvalidExtraProperty(): void
    {
        $pt = [[
            '_type' => 'block',
            'children' => [],
            'foo' => 'bar', // additionalProperties:false
        ]];
        self::assertFalse(PtValidator::validate($pt)['valid']);
    }

    public function testAssertValidThrowsOnInvalid(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/validation failed/');
        PtValidator::assertValid([['_type' => 'aliens']]);
    }

    public function testAssertValidSilentOnValid(): void
    {
        PtValidator::assertValid([]);
        self::assertTrue(true);
    }

    public function testValidRawTex(): void
    {
        $pt = [[
            '_type' => 'rawTex',
            'content' => '\\begin{eq}x^2\\end{eq}',
        ]];
        self::assertTrue(PtValidator::validate($pt)['valid']);
    }

    public function testKeyOptionalAllowed(): void
    {
        $pt = [[
            '_type' => 'block',
            '_key' => 'abc123',
            'children' => [
                ['_type' => 'span', '_key' => 'k1', 'text' => 'x'],
            ],
        ]];
        self::assertTrue(PtValidator::validate($pt)['valid']);
    }
}
