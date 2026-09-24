<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Services\TikzElementsService;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Il CRUD dei modelli TikZ lavora sul JSON d'istanza
 * storage/data/modelli_tikz_elements.json: qui una sandbox in temp con un
 * indice di due gruppi.
 */
final class TikzElementsServiceTest extends TestCase
{
    private string $sandbox;
    private string $file;
    private TikzElementsService $svc;

    protected function setUp(): void
    {
        $this->sandbox = sys_get_temp_dir() . '/pantedu_tikz_elements_' . uniqid();
        mkdir($this->sandbox . '/storage/data', 0755, true);
        $this->file = $this->sandbox . '/storage/data/modelli_tikz_elements.json';
        $this->seed([
            'gruppo-FISICA' => [
                ['label' => 'cinematica 1D', 'content' => '\begin{tikzpicture}\draw (0,0) -- (2,0);\end{tikzpicture}', 'type' => 'tikz'],
                ['label' => 'Dati problema', 'content' => '\begin{array}{l} v_0 = 3\,m/s \end{array}', 'type' => 'latex'],
            ],
            'gruppo-geometria' => [
                ['label' => 'Triangolo', 'content' => '\draw (0,0) -- (1,0) -- (0,1) -- cycle;', 'type' => 'tikz'],
            ],
        ]);
        $this->svc = new TikzElementsService($this->sandbox);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->sandbox . '/storage/data/*') ?: [] as $f) {
            @unlink($f);
        }
        @rmdir($this->sandbox . '/storage/data');
        @rmdir($this->sandbox . '/storage');
        @rmdir($this->sandbox);
    }

    // ───────────────────────── create ─────────────────────────

    #[Test]
    public function create_appends_to_an_existing_group_and_keeps_labels_sorted(): void
    {
        $out = $this->svc->createElement('', 'gruppo-FISICA', 'latex', '  Bilancio energetico ', 'E = \frac{1}{2} m v^2');

        self::assertSame(['group' => 'gruppo-FISICA', 'label' => 'Bilancio energetico', 'created_group' => false], $out);
        $fisica = $this->load()['gruppo-FISICA'];
        self::assertSame(['Bilancio energetico', 'cinematica 1D', 'Dati problema'], array_column($fisica, 'label'));
        self::assertSame(['label' => 'Bilancio energetico', 'content' => 'E = \frac{1}{2} m v^2', 'type' => 'latex'], $fisica[0]);
    }

    #[Test]
    public function create_makes_a_new_group_keeping_the_name_as_typed(): void
    {
        $out = $this->svc->createElement('Studio  Funzioni', 'gruppo-FISICA', 'tikz', 'Parabola', '\draw plot (\x,\x*\x);');

        self::assertTrue($out['created_group']);
        self::assertSame('gruppo-Studio Funzioni', $out['group']);
        $index = $this->load();
        self::assertSame(['gruppo-FISICA', 'gruppo-Studio Funzioni', 'gruppo-geometria'], array_keys($index));
        self::assertCount(1, $index['gruppo-Studio Funzioni']);
    }

    #[Test]
    public function create_accepts_a_group_name_that_already_carries_the_prefix(): void
    {
        $out = $this->svc->createElement('gruppo-geometria', '', 'tikz', 'Quadrato', '\draw (0,0) rectangle (1,1);');

        self::assertFalse($out['created_group']);
        self::assertSame(['Quadrato', 'Triangolo'], array_column($this->load()['gruppo-geometria'], 'label'));
    }

    #[Test]
    public function create_rejects_a_duplicate_label_ignoring_case(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('duplicate_label_in_group');

        $this->svc->createElement('', 'gruppo-FISICA', 'tikz', 'CINEMATICA 1d', '\draw (0,0);');
    }

    #[Test]
    public function create_validates_its_input_before_touching_the_file(): void
    {
        $before = file_get_contents($this->file);
        $cases = [
            'label_missing'        => ['', 'gruppo-FISICA', 'tikz', '   ', 'x'],
            'code_missing'         => ['', 'gruppo-FISICA', 'tikz', 'Nuovo', "  \n"],
            'invalid_element_type' => ['', 'gruppo-FISICA', 'svg', 'Nuovo', 'x'],
            'group_missing'        => ['', '', 'tikz', 'Nuovo', 'x'],
            'invalid_group_name'   => ["a|b", '', 'tikz', 'Nuovo', 'x'],
            'invalid_label'        => ['', 'gruppo-FISICA', 'tikz', "a|b", 'x'],
        ];
        foreach ($cases as $expected => $args) {
            try {
                $this->svc->createElement(...$args);
                self::fail("attesa eccezione $expected");
            } catch (RuntimeException $e) {
                self::assertSame($expected, $e->getMessage());
            }
        }
        self::assertSame($before, file_get_contents($this->file), 'un input rifiutato non riscrive il file');
    }

    #[Test]
    public function create_starts_from_an_empty_index_when_the_file_is_missing(): void
    {
        unlink($this->file);

        $out = $this->svc->createElement('Primo gruppo', '', 'tikz', 'Retta', '\draw (0,0) -- (1,1);');

        self::assertTrue($out['created_group']);
        self::assertSame(['gruppo-Primo gruppo' => [['label' => 'Retta', 'content' => '\draw (0,0) -- (1,1);', 'type' => 'tikz']]], $this->load());
    }

    #[Test]
    public function an_unreadable_index_is_never_overwritten(): void
    {
        file_put_contents($this->file, '{"gruppo-FISICA": [ …troncato');

        try {
            $this->svc->createElement('', 'gruppo-FISICA', 'tikz', 'Nuovo', 'x');
            self::fail('attesa eccezione index_invalid');
        } catch (RuntimeException $e) {
            self::assertSame('index_invalid', $e->getMessage());
        }
        self::assertSame('{"gruppo-FISICA": [ …troncato', file_get_contents($this->file));
    }

    // ───────────────────────── delete ─────────────────────────

    #[Test]
    public function delete_removes_one_element_and_drops_the_group_once_empty(): void
    {
        $out = $this->svc->deleteElement('gruppo-FISICA', 'dati problema', false);
        self::assertSame(['group' => 'gruppo-FISICA', 'deletedLabel' => 'dati problema', 'groupRemoved' => false], $out);
        self::assertSame(['cinematica 1D'], array_column($this->load()['gruppo-FISICA'], 'label'));

        $out = $this->svc->deleteElement('gruppo-geometria', 'Triangolo', false);
        self::assertTrue($out['groupRemoved']);
        self::assertSame(['gruppo-FISICA'], array_keys($this->load()));
    }

    #[Test]
    public function delete_removes_a_whole_group(): void
    {
        $out = $this->svc->deleteElement('gruppo-FISICA', '', true);

        self::assertTrue($out['groupRemoved']);
        self::assertSame(['gruppo-geometria'], array_keys($this->load()));
    }

    #[Test]
    public function delete_reports_a_missing_group_element_or_label(): void
    {
        $cases = [
            'group_missing'                => ['', 'x', false],
            'element_label_missing'        => ['gruppo-FISICA', '', false],
            'group_not_found'              => ['gruppo-chimica', 'x', false],
            'element_not_found_in_group'   => ['gruppo-FISICA', 'Triangolo', false],
        ];
        foreach ($cases as $expected => $args) {
            try {
                $this->svc->deleteElement(...$args);
                self::fail("attesa eccezione $expected");
            } catch (RuntimeException $e) {
                self::assertSame($expected, $e->getMessage());
            }
        }
    }

    // ───────────────────────── edit ─────────────────────────

    #[Test]
    public function edit_replaces_the_element_found_by_label(): void
    {
        $out = $this->svc->editElement('gruppo-FISICA', -1, '', '', 'latex', 'Cinematica 1D (nuova)', 'v = v_0 + a t', 'cinematica 1D');

        self::assertSame(['group' => 'gruppo-FISICA', 'originalGroup' => 'gruppo-FISICA', 'renamed' => false, 'moved' => false, 'label' => 'Cinematica 1D (nuova)'], $out);
        $index = $this->load();
        self::assertSame(
            [['label' => 'Cinematica 1D (nuova)', 'content' => 'v = v_0 + a t', 'type' => 'latex'], ['label' => 'Dati problema', 'content' => '\begin{array}{l} v_0 = 3\,m/s \end{array}', 'type' => 'latex']],
            $index['gruppo-FISICA'],
        );
        self::assertSame('Triangolo', $index['gruppo-geometria'][0]['label'], 'gli altri gruppi restano intatti');
    }

    #[Test]
    public function edit_falls_back_to_the_json_index_when_no_label_is_given(): void
    {
        $out = $this->svc->editElement('gruppo-FISICA', 1, '', '', 'latex', 'Dati problema', 'nuovo contenuto');

        self::assertSame('Dati problema', $out['label']);
        self::assertSame('nuovo contenuto', $this->load()['gruppo-FISICA'][1]['content']);
    }

    #[Test]
    public function edit_renames_the_whole_group(): void
    {
        $out = $this->svc->editElement('gruppo-FISICA', -1, 'Fisica classica', '', 'tikz', 'cinematica 1D', '\draw (0,0) -- (2,0);', 'cinematica 1D');

        self::assertSame('gruppo-Fisica classica', $out['group']);
        self::assertSame('gruppo-FISICA', $out['originalGroup']);
        self::assertTrue($out['renamed']);
        self::assertFalse($out['moved']);
        $index = $this->load();
        self::assertArrayNotHasKey('gruppo-FISICA', $index);
        self::assertSame(['cinematica 1D', 'Dati problema'], array_column($index['gruppo-Fisica classica'], 'label'));
    }

    #[Test]
    public function edit_moves_the_element_and_drops_the_emptied_source_group(): void
    {
        $out = $this->svc->editElement('gruppo-geometria', -1, '', 'gruppo-FISICA', 'tikz', 'Triangolo', '\draw (0,0) -- (1,0) -- (0,1) -- cycle;', 'Triangolo');

        self::assertTrue($out['moved']);
        self::assertSame('gruppo-FISICA', $out['group']);
        self::assertSame('gruppo-geometria', $out['originalGroup']);
        $index = $this->load();
        self::assertSame(['gruppo-FISICA'], array_keys($index));
        self::assertSame(['cinematica 1D', 'Dati problema', 'Triangolo'], array_column($index['gruppo-FISICA'], 'label'));
    }

    #[Test]
    public function edit_refuses_a_missing_target_group_a_taken_group_name_and_a_duplicate_label(): void
    {
        $cases = [
            'target_group_not_found'   => ['gruppo-FISICA', -1, '', 'chimica', 'tikz', 'cinematica 1D', 'x', 'cinematica 1D'],
            'target_group_exists'      => ['gruppo-FISICA', -1, 'geometria', '', 'tikz', 'cinematica 1D', 'x', 'cinematica 1D'],
            'duplicate_label_in_group' => ['gruppo-FISICA', -1, '', '', 'tikz', 'dati PROBLEMA', 'x', 'cinematica 1D'],
            'element_label_not_found'  => ['gruppo-FISICA', -1, '', '', 'tikz', 'x', 'x', 'Quadrato'],
            'element_index_out_of_range' => ['gruppo-FISICA', 7, '', '', 'tikz', 'x', 'x'],
            'invalid_element_index'    => ['gruppo-FISICA', -1, '', '', 'tikz', 'x', 'x'],
            'group_not_found'          => ['gruppo-chimica', 0, '', '', 'tikz', 'x', 'x'],
        ];
        foreach ($cases as $expected => $args) {
            try {
                $this->svc->editElement(...$args);
                self::fail("attesa eccezione $expected");
            } catch (RuntimeException $e) {
                self::assertSame($expected, $e->getMessage());
            }
        }
    }

    // ───────────────────────── file ─────────────────────────

    #[Test]
    public function the_written_json_keeps_unicode_slashes_and_unknown_keys(): void
    {
        $this->seed([
            'gruppo-equ. di 2° grado' => [
                ['label' => 'Δ > 0', 'content' => '\frac{-b \pm \sqrt{\Delta}}{2a}', 'type' => 'latex', 'data' => ['a' => 1]],
            ],
        ]);

        $this->svc->createElement('', 'gruppo-equ. di 2° grado', 'tikz', 'Parabola', '\draw plot (\x,\x*\x);');

        $raw = (string) file_get_contents($this->file);
        self::assertStringContainsString('"gruppo-equ. di 2° grado"', $raw, 'unicode non escapato');
        self::assertStringContainsString('{2a}', $raw);
        self::assertStringNotContainsString('\/', $raw, 'slash non escapate');
        $index = $this->load();
        self::assertSame(['a' => 1], $index['gruppo-equ. di 2° grado'][1]['data'], 'le chiavi sconosciute sopravvivono');
        self::assertSame(['label', 'content', 'type', 'data'], array_keys($index['gruppo-equ. di 2° grado'][1]));
        self::assertSame([], glob($this->sandbox . '/storage/data/*.tmp.*') ?: [], 'nessun temporaneo lasciato in giro');
    }

    #[Test]
    public function an_empty_index_is_written_as_an_object(): void
    {
        $this->svc->deleteElement('gruppo-FISICA', '', true);
        $this->svc->deleteElement('gruppo-geometria', '', true);

        self::assertSame('{}', trim((string) file_get_contents($this->file)));
        self::assertSame([], $this->svc->readIndex());
    }

    // ───────────────────────── helpers ─────────────────────────

    /** @param array<string, list<array<string, mixed>>> $index */
    private function seed(array $index): void
    {
        file_put_contents($this->file, json_encode($index, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    }

    /** @return array<string, list<array<string, mixed>>> */
    private function load(): array
    {
        $data = json_decode((string) file_get_contents($this->file), true);
        self::assertIsArray($data);
        return $data;
    }
}
