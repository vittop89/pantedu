<?php declare(strict_types=1);

namespace Tests\Unit\Risdoc\Pt;

use App\Services\Risdoc\Pt\PtToTex;
use App\Services\Risdoc\Pt\PtValidator;
use App\Services\Risdoc\Pt\TexBlockExtractor;
use App\Services\Risdoc\Pt\SchemaSeeder;
use App\Services\Risdoc\TexBuilder;
use PHPUnit\Framework\TestCase;

/**
 * Test full cycle (Phase 22.8) — end-to-end pipeline PT senza DB:
 *
 *   [.tex legacy]
 *      → TexBlockExtractor → PT AST
 *      → PtValidator (valida)
 *      → SchemaSeeder inject in schema.field.default
 *      → JSON serialize (come client save)
 *      → JSON parse (come server retrieve)
 *      → TexBuilder build con PT field
 *      → output TeX contiene check rendering + fieldRef
 *
 * Coverage gaps del POC: DB layer (CompilationRepository read/write) NON
 * testato qui — richiederebbe PDO + harness SQLite. Tutto il resto è
 * coperto da questo full-cycle in-memory.
 */
final class FullCycleTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/fm-pt-fullcycle-' . bin2hex(random_bytes(4));
        mkdir($this->tmpDir . '/MODELLI/tex', 0777, true);
        PtValidator::flushCache();
    }

    protected function tearDown(): void
    {
        if (!is_dir($this->tmpDir)) return;
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->tmpDir, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($it as $f) {
            $f->isDir() ? rmdir($f->getPathname()) : unlink($f->getPathname());
        }
        rmdir($this->tmpDir);
    }

    public function testEndToEndTexToPtToSchemaToCompilationToTex(): void
    {
        // ── 1. Scrittura TeX legacy fittizio con blocco BeginTesto ──
        $texContent = <<<'TEX'
\documentclass{article}
\begin{document}

\subsection{Profilo della classe}
\begin{sectionbox}{OSSERVAZIONI}
    %[BeginTesto]
    Gli alunni della classe [field-classe] [field-sezione] sono
    \xcheckbox{attenti}
    \checkbox{distratti}
    durante le lezioni.
    %[EndTesto]
\end{sectionbox}

\end{document}
TEX;
        $texPath = $this->tmpDir . '/MODELLI/tex/test.tex';
        file_put_contents($texPath, $texContent);

        // ── 2. Schema con hint tex_source ──
        $schema = [
            '$id' => 'test',
            'title' => 'Test',
            'category' => 'MODELLI',
            'sections' => [[
                'items' => [[
                    'type' => 'nota-textarea',
                    'name' => 'profilo_classe',
                    'label' => 'Profilo della classe',
                    'tex_source' => [
                        'file' => 'MODELLI/tex/test.tex',
                        'section' => 'OSSERVAZIONI',
                    ],
                ]],
            ]],
        ];

        // ── 3. Seed default PT ──
        $seeder = new SchemaSeeder($this->tmpDir);
        $seedResult = $seeder->seed($schema);
        $schemaMigrated = $seedResult['schema'];
        self::assertSame('seeded', $seedResult['report'][0]['status']);

        // ── 4. Validazione PT AST ──
        $field = $schemaMigrated['sections'][0]['items'][0];
        self::assertArrayHasKey('default', $field);
        self::assertTrue(PtValidator::validate($field['default'])['valid']);

        // ── 5. Save schema to disk (per TexBuilder constructor richiede path) ──
        $schemaPath = $this->tmpDir . '/test.schema.json';
        file_put_contents($schemaPath, json_encode($schemaMigrated));

        // ── 6. Simula compilation: user modifica field (aggiunge checkbox "x" altro) ──
        $userEditedPt = $field['default']; // base dal default
        // User aggiunge un nuovo blocco checkboxGroup
        $userEditedPt[] = [
            '_type' => 'checkboxGroup',
            'items' => [
                ['state' => 'x', 'label' => 'aggiunta utente'],
            ],
        ];
        $compilation = [
            'fields' => [
                'profilo_classe' => $userEditedPt,
                'classe_name' => 'Plain string field',
            ],
            'state' => [
                'classe' => '3A',
                'sezione' => 'B',
            ],
        ];

        // ── 7. Serialize JSON (come client save POST) ──
        $serialized = json_encode($compilation, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        self::assertIsString($serialized);
        self::assertGreaterThan(0, strlen($serialized));

        // ── 8. Parse JSON (come server retrieve) ──
        $retrieved = json_decode($serialized, true, 512, JSON_THROW_ON_ERROR);
        self::assertSame($compilation, $retrieved);

        // ── 9. TexBuilder build (usa fallback path senza wrapper) ──
        $builder = new TexBuilder($schemaPath, $this->tmpDir);
        $texOut = $builder->build($retrieved);

        // ── 10. Assertions su TeX output ──
        // Default preservato (bloccato nel template originale)
        self::assertStringContainsString('[field-classe]', $texOut);
        self::assertStringContainsString('[field-sezione]', $texOut);
        self::assertStringContainsString('\\xcheckbox{attenti}', $texOut);
        self::assertStringContainsString('\\checkbox{distratti}', $texOut);
        // User edit preservato
        self::assertStringContainsString('\\xcheckbox{aggiunta utente}', $texOut);
        // Plain string field
        self::assertStringContainsString('Plain string field', $texOut);
        // Non deve contenere "Array" literal (old string-cast bug)
        self::assertStringNotContainsString('profilo_classe:} Array', $texOut);
    }

    public function testJsonSerializeRoundTripPreservesPtShape(): void
    {
        // Verifica che il serialize/deserialize JSON non alteri la struttura
        // (include Unicode chars, escape chars, nested structures).
        $pt = [
            [
                '_type' => 'block', 'style' => 'normal',
                'children' => [
                    ['_type' => 'span', 'text' => 'àéìòù — “curly quotes”', 'marks' => []],
                    ['_type' => 'fieldRef', 'name' => 'anno_scolastico'],
                    ['_type' => 'span', 'text' => '\\backslash & ampersand', 'marks' => ['strong']],
                ],
            ],
            [
                '_type' => 'rawTex',
                'content' => "\\begin{equation}\n    x^2 + y^2 = z^2\n\\end{equation}",
            ],
        ];
        $encoded = json_encode($pt, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        $decoded = json_decode($encoded, true, 512, JSON_THROW_ON_ERROR);
        self::assertSame($pt, $decoded);
        self::assertTrue(PtValidator::validate($decoded)['valid']);
    }

    public function testTexOutputSemanticsAfterUserEdit(): void
    {
        // Simula il pattern: partenza da default, user rimuove un checkbox
        // e cambia state di un altro. Verify final TeX riflette lo stato user.
        $originalPt = [
            [
                '_type' => 'checkboxGroup',
                'items' => [
                    ['state' => 'x', 'label' => 'opzione1'],
                    ['state' => '_', 'label' => 'opzione2'],
                    ['state' => '_', 'label' => 'opzione3'],
                ],
            ],
        ];
        // User modifica via editor: toggle opzione2, rimuove opzione3
        $editedPt = [
            [
                '_type' => 'checkboxGroup',
                'items' => [
                    ['state' => 'x', 'label' => 'opzione1'],
                    ['state' => 'x', 'label' => 'opzione2'], // toggled
                    // opzione3 rimossa
                ],
            ],
        ];
        $tex = PtToTex::render($editedPt);
        self::assertStringContainsString('\\xcheckbox{opzione1}', $tex);
        self::assertStringContainsString('\\xcheckbox{opzione2}', $tex);
        self::assertStringNotContainsString('opzione3', $tex);
    }

    public function testAllMigratedSchemasInRepoProduceValidTexOutput(): void
    {
        // Scansiona schemas/risdoc/*.json per field con default PT AST
        // (quelli migrati dal CLI --auto-annotate --apply) e verifica che
        // PtToTex::render su ognuno produca output non-vuoto senza errori.
        $schemaDir = dirname(__DIR__, 3) . '/../schemas/risdoc';
        if (!is_dir($schemaDir)) {
            self::markTestSkipped('schemas/risdoc/ non presente in questo env');
        }
        $migratedCount = 0;
        foreach (glob($schemaDir . '/*.json') ?: [] as $f) {
            if (str_ends_with($f, 'template.schema.json')) continue;
            $schema = json_decode((string)file_get_contents($f), true);
            if (!is_array($schema)) continue;
            $this->walkFields($schema, function (array $field) use (&$migratedCount) {
                $def = $field['default'] ?? null;
                if (!is_array($def) || count($def) === 0) return;
                if (!isset($def[0]['_type'])) return;
                // È un PT AST → valida + render
                self::assertTrue(PtValidator::validate($def)['valid'],
                    'PT default invalido in field ' . ($field['name'] ?? '?'));
                $tex = PtToTex::render($def);
                self::assertNotSame('', $tex, 'TeX render vuoto per field ' . ($field['name'] ?? '?'));
                $migratedCount++;
            });
        }
        self::assertGreaterThan(0, $migratedCount, 'atteso ≥1 schema migrato nel repo');
    }

    private function walkFields(array $tree, callable $visitor): void
    {
        if (isset($tree['type'], $tree['name'])) $visitor($tree);
        foreach ($tree as $v) {
            if (is_array($v)) $this->walkFields($v, $visitor);
        }
    }
}
