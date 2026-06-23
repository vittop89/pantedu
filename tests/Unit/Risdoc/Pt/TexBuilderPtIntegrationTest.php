<?php declare(strict_types=1);

namespace Tests\Unit\Risdoc\Pt;

use App\Services\Risdoc\TexBuilder;
use PHPUnit\Framework\TestCase;

/**
 * Test integrazione TexBuilder ↔ Portable Text (Phase 22.5).
 *
 * Verifica che compilation.fields con PT AST vengano renderizzati via
 * PtToTex (non string-cast a "Array") sia nel path wrapper che fallback.
 */
final class TexBuilderPtIntegrationTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/fm-pt-texbuilder-' . bin2hex(random_bytes(4));
        mkdir($this->tmpDir, 0777, true);
    }

    protected function tearDown(): void
    {
        if (!is_dir($this->tmpDir)) return;
        foreach (scandir($this->tmpDir) ?: [] as $f) {
            if ($f === '.' || $f === '..') continue;
            unlink("{$this->tmpDir}/$f");
        }
        rmdir($this->tmpDir);
    }

    private function writeSchema(string $name, array $schema): string
    {
        $path = "{$this->tmpDir}/{$name}";
        file_put_contents($path, json_encode($schema, JSON_UNESCAPED_SLASHES));
        return $path;
    }

    public function testWrapperPathWithPtFieldRendersViaPtToTex(): void
    {
        $wrapperPath = "{$this->tmpDir}/wrapper.tex";
        file_put_contents($wrapperPath,
            "% wrapper\n\\section{Profilo}\n{{profilo_classe}}\n\\section{Note}\n{{note}}\n"
        );
        $schemaPath = $this->writeSchema('s.json', [
            'title' => 'Test',
            'tex'   => ['wrapper' => 'wrapper.tex'],
        ]);
        $builder = new TexBuilder($schemaPath, $this->tmpDir);

        $compilation = [
            'fields' => [
                'profilo_classe' => [
                    ['_type' => 'block', 'style' => 'normal', 'children' => [
                        ['_type' => 'span', 'text' => 'Gli alunni della classe ', 'marks' => []],
                        ['_type' => 'fieldRef', 'name' => 'classe'],
                    ]],
                    ['_type' => 'checkboxGroup', 'items' => [
                        ['state' => 'x', 'label' => 'ok'],
                    ]],
                ],
                'note' => 'Plain string value',
            ],
        ];
        $out = $builder->build($compilation);

        self::assertStringContainsString('Gli alunni della classe', $out);
        self::assertStringContainsString('[field-classe]', $out);
        self::assertStringContainsString('\\item[\\xcheckbox] ok', $out);
        self::assertStringContainsString('Plain string value', $out);
        // CRITICAL: NON deve contenere il literal "Array" (sintomo string-cast rotto)
        self::assertStringNotContainsString('profilo_classe: Array', $out);
    }

    public function testFallbackPathWithPtFieldRendersViaPtToTex(): void
    {
        // Schema senza tex.wrapper → buildFallbackTex
        $schemaPath = $this->writeSchema('s.json', [
            'title' => 'Test',
            '$id'   => 'test',
        ]);
        $builder = new TexBuilder($schemaPath, $this->tmpDir);

        $compilation = [
            'fields' => [
                'rich_field' => [
                    ['_type' => 'block', 'children' => [
                        ['_type' => 'span', 'text' => 'ciao ', 'marks' => []],
                        ['_type' => 'span', 'text' => 'mondo', 'marks' => ['strong']],
                    ]],
                ],
                'plain_field' => 'ordinary text',
            ],
        ];
        $out = $builder->build($compilation);

        self::assertStringContainsString('ciao \\textbf{mondo}', $out);
        self::assertStringContainsString('ordinary text', $out);
        self::assertStringNotContainsString('rich_field:} Array', $out);
    }

    public function testStringValueUnaffected(): void
    {
        $wrapperPath = "{$this->tmpDir}/wrapper.tex";
        file_put_contents($wrapperPath, "{{name}} {{state.class}}");
        $schemaPath = $this->writeSchema('s.json', [
            'tex' => ['wrapper' => 'wrapper.tex'],
        ]);
        $builder = new TexBuilder($schemaPath, $this->tmpDir);
        $out = $builder->build([
            'fields' => ['name' => 'Mario'],
            'state'  => ['class' => '3A'],
        ]);
        self::assertSame('Mario 3A', $out);
    }

    public function testArrayNotPtShapeUnaffectedInWrapper(): void
    {
        // Array non-PT (es. lista di checkbox-group values) → fallback esc((string)$v)
        $wrapperPath = "{$this->tmpDir}/wrapper.tex";
        file_put_contents($wrapperPath, "{{choices}}");
        $schemaPath = $this->writeSchema('s.json', [
            'tex' => ['wrapper' => 'wrapper.tex'],
        ]);
        $builder = new TexBuilder($schemaPath, $this->tmpDir);
        $out = $builder->build([
            'fields' => ['choices' => ['a', 'b', 'c']], // plain array of strings
        ]);
        // Non rendering PT — cast string dà "Array" (pre-esistente),
        // ma non crashia. In futuro può avere handler dedicato.
        self::assertNotSame('', $out);
    }

    public function testLooksLikePortableTextDetection(): void
    {
        // Via reflection — test unit del pattern detection
        $builder = new TexBuilder(
            $this->writeSchema('s.json', ['title' => 't']),
            $this->tmpDir,
        );
        $rc = new \ReflectionMethod(TexBuilder::class, 'looksLikePortableText');
        $rc->setAccessible(true);

        self::assertTrue($rc->invoke(null, [['_type' => 'block', 'children' => []]]));
        self::assertTrue($rc->invoke(null, [['_type' => 'checkboxGroup', 'items' => []]]));
        self::assertTrue($rc->invoke(null, [['_type' => 'rawTex', 'content' => 'x']]));
        self::assertFalse($rc->invoke(null, []));
        self::assertFalse($rc->invoke(null, 'string'));
        self::assertFalse($rc->invoke(null, ['a', 'b']));
        self::assertFalse($rc->invoke(null, [['name' => 'no_type']]));
        self::assertFalse($rc->invoke(null, [['_type' => 'unknown']]));
    }
}
