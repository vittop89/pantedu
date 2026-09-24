<?php declare(strict_types=1);

namespace Tests\Unit\Risdoc\Pt;

use App\Services\Risdoc\Pt\SchemaSeeder;
use PHPUnit\Framework\TestCase;

/**
 * Test SchemaSeeder (Phase 22.4b).
 *
 * Valida:
 *   - Seeding di un field con tex_source valido → default popolato con PT AST
 *   - Skip per tex_source invalido (file missing, section missing, PT invalido)
 *   - Walk ricorsivo trova field a profondità arbitraria in sections.items[]
 *   - Schema senza tex_source → no-op (nessun default iniettato)
 */
final class SchemaSeederTest extends TestCase
{
    private string $tmpTexRoot;

    protected function setUp(): void
    {
        $this->tmpTexRoot = sys_get_temp_dir() . '/fm-pt-seeder-' . bin2hex(random_bytes(4));
        mkdir($this->tmpTexRoot, 0777, true);
    }

    protected function tearDown(): void
    {
        $this->rrmdir($this->tmpTexRoot);
    }

    private function rrmdir(string $dir): void
    {
        if (!is_dir($dir)) return;
        foreach (scandir($dir) ?: [] as $f) {
            if ($f === '.' || $f === '..') continue;
            $path = "$dir/$f";
            is_dir($path) ? $this->rrmdir($path) : unlink($path);
        }
        rmdir($dir);
    }

    private function writeTex(string $relPath, string $content): void
    {
        $full = $this->tmpTexRoot . '/' . $relPath;
        $dir = dirname($full);
        if (!is_dir($dir)) mkdir($dir, 0777, true);
        file_put_contents($full, $content);
    }

    public function testSeedsFieldWithValidTexSource(): void
    {
        $this->writeTex('t/sample.tex', <<<'TEX'
\begin{sectionbox}{LAB}
%[BeginTesto]
Testo con [field-classe].
\xcheckbox{ok}
%[EndTesto]
\end{sectionbox}
TEX);
        $schema = [
            'sections' => [[
                'items' => [[
                    'type' => 'nota-textarea',
                    'name' => 'my_field',
                    'tex_source' => ['file' => 't/sample.tex', 'section' => 'LAB'],
                ]],
            ]],
        ];
        $seeder = new SchemaSeeder($this->tmpTexRoot);
        $result = $seeder->seed($schema);

        $field = $result['schema']['sections'][0]['items'][0];
        self::assertArrayHasKey('default', $field);
        self::assertIsArray($field['default']);
        self::assertGreaterThan(0, count($field['default']));

        $report = $result['report'];
        self::assertCount(1, $report);
        self::assertSame('seeded', $report[0]['status']);
        self::assertSame('my_field', $report[0]['name']);
    }

    public function testSkipsFieldWithMissingTexFile(): void
    {
        $schema = ['field' => [
            'name' => 'x',
            'tex_source' => ['file' => 'nonexistent.tex', 'section' => 'NONE'],
        ]];
        $seeder = new SchemaSeeder($this->tmpTexRoot);
        $result = $seeder->seed($schema);
        self::assertSame('skip:file_missing', $result['report'][0]['status']);
        self::assertArrayNotHasKey('default', $result['schema']['field']);
    }

    public function testSkipsSectionNotFound(): void
    {
        $this->writeTex('s.tex', '\\begin{sectionbox}{A}%[BeginTesto]x%[EndTesto]\\end{sectionbox}');
        $schema = ['f' => [
            'name' => 'x',
            'tex_source' => ['file' => 's.tex', 'section' => 'B'],
        ]];
        $seeder = new SchemaSeeder($this->tmpTexRoot);
        $result = $seeder->seed($schema);
        self::assertSame('skip:section_not_found', $result['report'][0]['status']);
    }

    public function testSchemaWithoutTexSourceYieldsEmptyReport(): void
    {
        $schema = ['sections' => [[
            'items' => [
                ['type' => 'dynamic-table', 'name' => 't1'],
                ['type' => 'nota-textarea', 'name' => 'n1', 'placeholder' => 'nothing'],
            ],
        ]]];
        $seeder = new SchemaSeeder($this->tmpTexRoot);
        $result = $seeder->seed($schema);
        self::assertSame([], $result['report']);
        // Nessun default iniettato
        foreach ($result['schema']['sections'][0]['items'] as $it) {
            self::assertArrayNotHasKey('default', $it);
        }
    }

    public function testWalksDeepNestedSchema(): void
    {
        $this->writeTex('a.tex', <<<'TEX'
\begin{sectionbox}{X}
%[BeginTesto]
alpha
%[EndTesto]
\end{sectionbox}
TEX);
        $this->writeTex('b.tex', <<<'TEX'
\begin{sectionbox}{Y}
%[BeginTesto]
beta
%[EndTesto]
\end{sectionbox}
TEX);
        $schema = [
            'sections' => [
                ['items' => [
                    ['name' => 'one', 'tex_source' => ['file' => 'a.tex', 'section' => 'X']],
                    ['sub_items' => [
                        ['name' => 'two_nested', 'tex_source' => ['file' => 'b.tex', 'section' => 'Y']],
                    ]],
                ]],
            ],
        ];
        $seeder = new SchemaSeeder($this->tmpTexRoot);
        $result = $seeder->seed($schema);
        self::assertCount(2, $result['report']);
        $names = array_column($result['report'], 'name');
        self::assertContains('one', $names);
        self::assertContains('two_nested', $names);
    }

    public function testSummarizeCounts(): void
    {
        $report = [
            ['status' => 'seeded'],
            ['status' => 'seeded'],
            ['status' => 'skip:file_missing'],
            ['status' => 'skip:section_not_found'],
        ];
        $stats = SchemaSeeder::summarize($report);
        self::assertSame(['seeded' => 2, 'skipped' => 2, 'total' => 4], $stats);
    }
}
