<?php declare(strict_types=1);

namespace Tests\Unit\Risdoc\Pt;

use App\Services\Risdoc\Pt\TexSourceAutoDetector;
use PHPUnit\Framework\TestCase;

/**
 * Test TexSourceAutoDetector (Phase 22.6).
 *
 * Valida euristica fuzzy-match schema.field.label ↔ tex subsection title,
 * resolution del .tex file da schema $id/title, skip se tex_source presente.
 */
final class TexSourceAutoDetectorTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/fm-pt-autodetect-' . bin2hex(random_bytes(4));
        mkdir($this->tmpDir . '/MODELLI/tex', 0777, true);
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

    public function testNormalizeStripsNumbersAndSymbols(): void
    {
        self::assertSame('profilodellaclasse', TexSourceAutoDetector::normalize('1.2 Profilo della classe'));
        self::assertSame('pianoannualedeldocente', TexSourceAutoDetector::normalize('Piano annuale del Docente'));
        self::assertSame('docpianoannualedocente', TexSourceAutoDetector::normalize('DOC-Piano_annuale_(docente)'));
    }

    public function testResolveTexFileByTitle(): void
    {
        $texPath = $this->tmpDir . '/MODELLI/tex/0.0_DOC-Piano_annuale_(docente)-MODELLI.tex';
        file_put_contents($texPath, <<<'TEX'
\subsection{Profilo della classe}
\begin{sectionbox}{OSS}
%[BeginTesto]
x
%[EndTesto]
\end{sectionbox}
TEX);
        $detector = new TexSourceAutoDetector($this->tmpDir);
        $schema = [
            '$id' => 'piano-annuale-docente',
            'title' => 'Piano annuale del Docente',
            'category' => 'MODELLI',
        ];
        $result = $detector->annotate($schema);
        // Nessun field → report no_blocks or no-op per field walking
        self::assertSame($schema, $result['schema']);
    }

    public function testAnnotatesFieldWithMatchingSubsection(): void
    {
        $texPath = $this->tmpDir . '/MODELLI/tex/test.tex';
        file_put_contents($texPath, <<<'TEX'
\subsection{Profilo della classe}
\begin{sectionbox}{OSSERVAZIONI}
%[BeginTesto]
contenuto
%[EndTesto]
\end{sectionbox}

\subsection{Note finali}
\begin{sectionbox}{NOTE}
%[BeginTesto]
altro
%[EndTesto]
\end{sectionbox}
TEX);
        $detector = new TexSourceAutoDetector($this->tmpDir);
        $schema = [
            '$id' => 'test',
            'title' => 'test',
            'category' => 'MODELLI',
            'sections' => [[
                'items' => [
                    ['type' => 'nota-textarea', 'name' => 'pc', 'label' => '1.2 Profilo della classe'],
                    ['type' => 'nota-textarea', 'name' => 'nf', 'label' => 'Note finali'],
                    ['type' => 'nota-textarea', 'name' => 'nomatch', 'label' => 'Qualcosa senza match'],
                ],
            ]],
        ];
        $result = $detector->annotate($schema);

        $items = $result['schema']['sections'][0]['items'];
        self::assertArrayHasKey('tex_source', $items[0]);
        self::assertSame('MODELLI/tex/test.tex', $items[0]['tex_source']['file']);
        self::assertSame('OSSERVAZIONI', $items[0]['tex_source']['section']);

        self::assertArrayHasKey('tex_source', $items[1]);
        self::assertSame('NOTE', $items[1]['tex_source']['section']);

        self::assertArrayNotHasKey('tex_source', $items[2]);

        $statuses = array_column($result['report'], 'status');
        self::assertSame(['annotated', 'annotated', 'no_match'], $statuses);
    }

    public function testDoesNotOverwriteExistingTexSource(): void
    {
        $texPath = $this->tmpDir . '/MODELLI/tex/test.tex';
        file_put_contents($texPath, <<<'TEX'
\subsection{X}
\begin{sectionbox}{OVERWRITE_ME}
%[BeginTesto]
x
%[EndTesto]
\end{sectionbox}
TEX);
        $detector = new TexSourceAutoDetector($this->tmpDir);
        $schema = [
            '$id' => 'test',
            'title' => 'test',
            'category' => 'MODELLI',
            'f' => [
                'type' => 'nota-textarea',
                'name' => 'x',
                'label' => 'X',
                'tex_source' => ['file' => 'already/set.tex', 'section' => 'KEEP'],
            ],
        ];
        $result = $detector->annotate($schema);
        self::assertSame('already/set.tex', $result['schema']['f']['tex_source']['file']);
        self::assertSame('KEEP', $result['schema']['f']['tex_source']['section']);
    }

    public function testReportsNoTexFileWhenNoMatch(): void
    {
        $detector = new TexSourceAutoDetector($this->tmpDir);
        $schema = ['$id' => 'nowhere', 'title' => 'Nowhere', 'category' => 'MODELLI'];
        $result = $detector->annotate($schema);
        self::assertSame('no_tex_file', $result['report'][0]['status']);
    }
}
